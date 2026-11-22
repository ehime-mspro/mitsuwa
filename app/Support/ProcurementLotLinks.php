<?php

namespace App\Support;

use App\Enums\LotStatus;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProcurementLot;
use Illuminate\Database\Eloquent\Collection;

/**
 * 区画を足すとき、区画を選んでいない契約などをその区画につなぐ（設計書 docs/superpowers/specs/2026-11-22-lot-link-on-add-design.md）。
 *
 * - 決まり 1: 区画を選んでいないもの（ProcurementLotClaims::unlinkedTakers()）があれば、足す区画にそのうち 1 つをつなぐ
 * - 決まり 2: つないだ区画のステータスはつないだものに合わせる（不動産の契約・契約のある建売・契約以降の注文住宅は成約、
 *   契約の無い建売は販売中）
 * - 決まり 6: つなぐときに書くのは区画の列だけ（土地原価・契約の原価・更新者・更新日時・ステータスは変えない）
 *
 * ⚠ 候補のキーは「種類:id」（contract:12・property:3・order:5）。追加のフォームは開いたときの候補のキーを fingerprint() の形で
 *   hidden に持ち、登録のときに今の候補と比べる（画面を開いたあとで顔ぶれが変わったら断る。決まり 5）。
 */
final class ProcurementLotLinks
{
    /**
     * その土地の区画を足すときに紐づけられるもの（ProcurementLotClaims::unlinkedTakers() の並びのまま）。
     *
     * @return array<int, array{key: string, label: string, status: LotStatus, owner: ReContract|HsProperty|HsCustomOrder}>
     */
    public static function candidates(ReProcurement $procurement): array
    {
        return array_map(fn (ReContract|HsProperty|HsCustomOrder $owner) => [
            'key'    => self::keyOf($owner),
            'label'  => self::labelOf($owner),
            'status' => self::statusFor($owner),
            'owner'  => $owner,
        ], ProcurementLotClaims::unlinkedTakers($procurement));
    }

    /** 候補のキーを並びのまま「,」でつないだ文字（候補が無ければ ''） */
    public static function fingerprint(array $candidates): string
    {
        return implode(',', array_column($candidates, 'key'));
    }

    /**
     * $owner の区画の列だけを $lot にする（契約は procurement_lot_id、建売・注文住宅は re_procurement_lot_id）。
     * ⚠ モデルを通さない問い合わせで書く（保存のフック・更新日時・更新者を動かさない。決まり 6）
     */
    public static function link(ReProcurementLot $lot, ReContract|HsProperty|HsCustomOrder $owner): void
    {
        $column = $owner instanceof ReContract ? 'procurement_lot_id' : 're_procurement_lot_id';

        $owner->newQuery()->whereKey($owner->getKey())->toBase()->update([$column => $lot->id]);
    }

    private static function keyOf(ReContract|HsProperty|HsCustomOrder $owner): string
    {
        $kind = match (true) {
            $owner instanceof ReContract => 'contract',
            $owner instanceof HsProperty => 'property',
            $owner instanceof HsCustomOrder => 'order',
        };

        return $kind . ':' . $owner->getKey();
    }

    /** 名前の形は削除の歯止めと同じ（DeletionBlockers::assemble()。例 契約「勝山の土地（山田 太郎 様）」） */
    private static function labelOf(ReContract|HsProperty|HsCustomOrder $owner): string
    {
        $one = new Collection([$owner]);
        $none = new Collection();

        $blockers = match (true) {
            $owner instanceof ReContract => DeletionBlockers::assemble($one, $none, $none),
            $owner instanceof HsProperty => DeletionBlockers::assemble($none, $one, $none),
            $owner instanceof HsCustomOrder => DeletionBlockers::assemble($none, $none, $one),
        };

        return $blockers[0]['label'] . '「' . $blockers[0]['items'][0]['name'] . '」';
    }

    /** つないだ区画のステータス（決まり 2。候補の注文住宅はいつも契約以降） */
    private static function statusFor(ReContract|HsProperty|HsCustomOrder $owner): LotStatus
    {
        if ($owner instanceof HsProperty && ! $owner->contract()->exists()) {
            return LotStatus::OnSale;
        }

        return LotStatus::Sold;
    }
}
