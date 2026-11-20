<?php

namespace App\Services\RealEstate;

use App\Enums\RealEstateTransactionType;
use App\Models\Attachment;
use App\Models\ReProcurement;
use App\Models\ReProcurementLot;
use App\Models\ReProject;
use App\Models\ReProjectCost;
use App\Models\ReProjectLot;
use App\Models\ScheduleStep;
use App\Models\User;
use App\Support\AreaConverter;
use App\Support\DeletionBlockers;
use App\Support\TsuboPrice;
use Illuminate\Support\Facades\DB;

/**
 * 土地（自社買取）の仕入れ案件を、原価・区画・工程・添付ごと分譲地に変える（設計書 §5.5）。
 * 新しい分譲地を作り、中身を写すか付け替えてから、元の仕入れ案件を消す。全部を 1 つのトランザクションで行う。
 *
 * ⚠ 画面の入口（ボタンを出すか）と、ここでの確かめ直しは同じ refusal() を通す（片方だけ直して割れないように。Bug #41）。
 */
class ProcurementToProjectConverter
{
    public const REFUSE_NOT_LAND = '物件種別が土地の仕入れ案件だけを分譲地に変えられます。';

    public const REFUSE_BROKERAGE = '取引種別が仲介の土地は分譲地に変えられません（分譲地に変えられるのは自社買取の土地だけです）。';

    /** 紐付けがあるときの文末。件数の部分は DeletionBlockers::countsLabel()（削除の歯止めと同じ形） */
    public const LINKED_SUFFIX = 'が紐づいているため、分譲地に変えられません。';

    /** 仕入れ案件がもう無い（二度押しの 2 回目など）。ルートの missing() と共用 */
    public const GONE = 'この仕入れ案件はもうありません（分譲地に変えたか、削除された可能性があります）。';

    /**
     * 分譲地に変えられない理由（変えられるなら null）。
     * 土地でない → 仲介 → 契約・建売・注文住宅の紐付け、の順に見る。
     */
    public static function refusal(ReProcurement $procurement): ?string
    {
        if (! $procurement->property_type?->isLandOnly()) {
            return self::REFUSE_NOT_LAND;
        }

        // 分譲地には取引種別の項目が無く、仲介の土地は自社の土地ではない（自社買取だけを通す）
        if ($procurement->transaction_type !== RealEstateTransactionType::Purchase) {
            return self::REFUSE_BROKERAGE;
        }

        if ($blockers = $procurement->deletionBlockers()) {
            return DeletionBlockers::countsLabel($blockers) . self::LINKED_SUFFIX;
        }

        return null;
    }

    /**
     * 仕入れ案件を分譲地に変える。変えた分譲地を返す。
     *
     * @throws ProcurementConversionRefused 仕入れ案件がもう無い・変えられない（何も変えない）
     */
    public function convert(ReProcurement $procurement, User $by): ReProject
    {
        return DB::transaction(function () use ($procurement, $by): ReProject {
            // 仕入れ案件の行を先にロックしてから読み直し、条件を確かめ直す。
            // ⚠ 原価の表の保存・試算表の取込も「親の行 → 原価の行」の順にロックする（Bug #135）。順をそろえる。
            // ⚠ 不動産の契約・住宅事業の保存は仕入れ案件の行をロックしないので、ほぼ同時に届いた紐付けまでは止まらない
            //   （設計書 §7・§8。順に届けば断れる）。
            $locked = ReProcurement::query()->whereKey($procurement->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                throw new ProcurementConversionRefused(self::GONE);
            }
            if (($reason = self::refusal($locked)) !== null) {
                throw new ProcurementConversionRefused($reason);
            }

            $project = ReProject::create([
                'project_code'            => ReProject::nextProjectCode(),
                'source_procurement_code' => $locked->procurement_code,
                'project_name'            => $locked->property_name,
                'status'                  => $locked->status->toProjectStatus(),
                'postal_code'             => $locked->postal_code,
                'address'                 => $locked->address,
                'latitude'                => $locked->latitude,
                'longitude'               => $locked->longitude,
                'land_area_sqm'           => $locked->land_area_sqm,
                'zoning'                  => $locked->zoning,
                // ⚠ モデルの整数のキャストを通すと 59.50 が 59 になるので、生の値を写す
                'building_coverage'       => $locked->getRawOriginal('building_coverage'),
                'floor_area_ratio'        => $locked->getRawOriginal('floor_area_ratio'),
                'supplier_id'             => $locked->supplier_id,
                'info_obtained_date'      => $locked->info_obtained_date,
                'assessment_price'        => $locked->assessment_price_land,
                'purchase_price'          => $locked->purchase_price_land,
                'target_selling_price'    => $locked->target_selling_price_land,
                'contract_date'           => $locked->contract_date,
                'settlement_date'         => $locked->settlement_date,
                'notes'                   => $locked->notes,
                'created_by'              => $locked->created_by,
                'updated_by'              => $by->id,
            ]);

            // 作成のフック（ReProject::syncPropertyPurchaseCost()）が物件購入費の行を作ることがあるので、消してから写す。
            // ⚠ 自動の同期に任せると、査定価格と購入価格がどちらも空の土地では物件購入費の行が移らず、行の備考も移らない。
            ReProjectCost::where('project_id', $project->id)->delete();
            foreach ($locked->costs()->orderBy('id')->get() as $cost) {
                ReProjectCost::create([
                    'project_id'       => $project->id,
                    'cost_item_id'     => $cost->cost_item_id,
                    'estimated_amount' => $cost->estimated_amount,
                    'actual_amount'    => $cost->actual_amount,
                    'notes'            => $cost->notes,
                ]);
            }

            $lot = $locked->lot;
            if ($lot !== null) {
                // 坪数・坪単価は分譲地の区画の登録（ProjectController::storeLot）と同じ計算（丸めは 1 回だけ。Bug #33・#34）
                $tsubo = AreaConverter::sqmToTsubo((float) $lot->area_sqm);
                ReProjectLot::create([
                    'project_id'              => $project->id,
                    'lot_number'              => $lot->lot_number,
                    'area_sqm'                => $lot->area_sqm,
                    'area_tsubo'              => $tsubo,
                    'selling_price'           => $lot->selling_price,
                    'selling_price_per_tsubo' => ($lot->selling_price !== null && $lot->selling_price > 0)
                        ? TsuboPrice::perTsuboYen((int) $lot->selling_price, $tsubo)
                        : null,
                    'is_price_manual'         => true,
                    'status'                  => $lot->status,
                    'notes'                   => $lot->notes,
                ]);
            }
            // 区画の状態で分譲地のステータスをまとめる（区画が成約なら販売済。分譲地の区画の登録と同じ決まり）
            $project->syncStatusFromLots();

            // 工程と添付は持ち主を付け替える（ファイルの置き場所は変えない）。論理削除した添付も移す。
            // ⚠ 型と id の両方で絞る（id だけだと、同じ id の分譲地などの行まで動く）
            ScheduleStep::where('schedulable_type', $locked->getMorphClass())
                ->where('schedulable_id', $locked->id)
                ->update(['schedulable_type' => $project->getMorphClass(), 'schedulable_id' => $project->id]);
            Attachment::withTrashed()
                ->where('attachable_type', $locked->getMorphClass())
                ->where('attachable_id', $locked->id)
                ->update(['attachable_type' => $project->getMorphClass(), 'attachable_id' => $project->id]);

            // 元の仕入れ案件を消す（本番は外部キーの CASCADE だが、テスト用スキーマは外部キーを張らないのでコードでも消す。Bug #81）
            $locked->costs()->delete();
            ReProcurementLot::where('procurement_id', $locked->id)->delete();
            $locked->delete();

            return $project->refresh();
        });
    }
}
