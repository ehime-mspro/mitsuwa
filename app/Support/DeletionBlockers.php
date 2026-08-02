<?php

namespace App\Support;

use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReContract;
use App\Models\ReProject;
use Illuminate\Support\Collection;

/**
 * 削除ブロッカー — 仕入れ案件 / 分譲地PJ / 区画を参照していて、
 * 消すと他モジュールのレコードが壊れるデータを集める。
 *
 * ブロック対象は 契約(re_contracts) / 建売物件(hs_properties) / 注文住宅(hs_custom_orders) の 3 種のみ。
 * 判定基準は「**SET NULL で他モジュールのレコードが壊れるか**」:
 * 本番の FK は ON DELETE SET NULL だが、参照が NULL になっても hs_* の land_source_type は
 * 'project_lot' のまま残るため、「土地元が分譲地区画」と名乗りながら参照先が無い矛盾状態になる。
 * その状態では HsProperty::getReferenceLandSellingPrice() / getLandSourceDisplay() が
 * どちらも null を返し、土地価格・土地原価の参照が黙って消える。
 *
 * 対象外:
 *   - buyer_surveys        … project_id は任意の紐づけ。NULL でも「分譲地未指定」で成立し矛盾しない
 *   - re_*_costs / lots / drawings … CASCADE の自前の子データ。既存の confirm() で予告済み
 *   - attachments          … ポリモーフィックで FK 無し（孤児行は別件）
 *
 * ⚠ 画面のパネルとサーバのガードが別々に判定すると、片方だけ直したときに
 *    「パネルは削除可と言うのにサーバが拒否する」食い違いが生まれる（Bug #41）。
 *    3 経路（ProcurementController::destroy / ProjectController::destroy / destroyLot）と
 *    詳細画面・区画一覧が**すべてこのクラスを通る**こと。
 *
 * 戻り値は「空配列 = 削除可能」。件数は count($items) で数え、件数と名称を別々に持たない。
 */
class DeletionBlockers
{
    /**
     * 指定した区画群を参照しているデータ。
     * 区画 1 件でも PJ 配下の全区画でもここを通す（whereIn のバルククエリで N+1 を避ける）。
     *
     * @param  array<int>  $lotIds
     * @return array<int, array{label: string, items: array<int, array{name: string, url: string}>}>
     */
    public static function forLotIds(array $lotIds): array
    {
        if ($lotIds === []) {
            return [];
        }

        return self::assemble(
            ReContract::with('buyer')->whereIn('lot_id', $lotIds)->get(),
            HsProperty::whereIn('re_project_lot_id', $lotIds)->get(),
            HsCustomOrder::whereIn('re_project_lot_id', $lotIds)->get(),
        );
    }

    /**
     * 種別ごとにまとめる。items が空の種別はエントリごと含めない。
     *
     * @return array<int, array{label: string, items: array<int, array{name: string, url: string}>}>
     */
    private static function assemble(Collection $contracts, Collection $properties, Collection $orders): array
    {
        $blockers = [];

        if ($contracts->isNotEmpty()) {
            $blockers[] = [
                'label' => '契約',
                'items' => $contracts->map(fn (ReContract $c) => [
                    'name' => $c->buyer_display_name
                        ? $c->property_name . '（' . $c->buyer_display_name . ' 様）'
                        : $c->property_name,
                    'url'  => route('realestate.contracts.show', $c),
                ])->values()->all(),
            ];
        }

        if ($properties->isNotEmpty()) {
            $blockers[] = [
                'label' => '建売物件',
                'items' => $properties->map(fn (HsProperty $p) => [
                    'name' => $p->property_code . ' ' . $p->property_name,
                    'url'  => route('housing.properties.show', $p),
                ])->values()->all(),
            ];
        }

        if ($orders->isNotEmpty()) {
            $blockers[] = [
                'label' => '注文住宅',
                'items' => $orders->map(fn (HsCustomOrder $o) => [
                    'name' => $o->order_code . ' ' . $o->order_name,
                    'url'  => route('housing.custom-orders.show', $o),
                ])->values()->all(),
            ];
        }

        return $blockers;
    }
}
