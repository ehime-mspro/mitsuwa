<?php

namespace App\Support;

use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReProcurement;
use App\Models\ReProcurementLot;
use Illuminate\Support\Collection;

/**
 * 住宅事業（建売・注文住宅）の画面の、土地の仕入れ案件と区画の選択肢（設計書 §5.3・§5.6、決めたこと 4・8）。
 * 建売・注文住宅の登録と編集・注文住宅の契約の編集・仕入れ案件の情報の API（procurement-info）が同じものを使う（Bug #41）。
 *
 * - 区画の選択肢: 取られていない区画 ＋ $current が今持っている区画（今の区画は、あとでほかに取られていても残す。
 *   見積りの注文住宅の区画は、ほかの契約などに取られることがある。決めたこと 4・Bug #130）
 * - 仕入れ案件の選択肢: 区画のある土地で、区画の選択肢が 1 つも残らないものは出さない（選んでも区画を選べず、保存で
 *   「区画を選んでください。」と断られるだけなので）。$current の今の仕入れ案件は残す
 * - 初めの選択（入力エラーで戻った画面・編集画面）: 選んでいた区画が区画の選択肢に無ければ選ばない（取られた区画で断られて
 *   戻った画面に、その区画を選んだまま描かない＝ x-model の値が選択肢に無い形にしない。Top trap #3）
 *
 * ⚠ 取られているかの判定は ProcurementLotClaims（紐付けで決める。区画のステータスでは決めない）。
 * ⚠ 持ち主の「今」は保存済みの値（getRawOriginal()）で読む（ProcurementLotClaims と同じ）。
 */
final class HousingProcurementLots
{
    /**
     * 区画の選択肢から外す区画の id: 取られている区画のうち、$current が今持っていないもの。
     *
     * @param  array<int, mixed>  $procurementIds
     * @return array<int, int>
     */
    public static function excludedLotIds(array $procurementIds, HsProperty|HsCustomOrder|null $current): array
    {
        $held = self::digits($current?->getRawOriginal('re_procurement_lot_id'));

        return array_values(array_filter(ProcurementLotClaims::takenLotIds($procurementIds, $current), fn (int $id) => $id !== $held));
    }

    /**
     * その土地の区画の選択肢（ReProcurement::housingLotOptions() の形）。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function lotOptions(ReProcurement $procurement, HsProperty|HsCustomOrder|null $current): array
    {
        return $procurement->housingLotOptions(self::excludedLotIds([$procurement->id], $current));
    }

    /**
     * 仕入れ案件の選択肢: $procurements（並べたもの）から、区画の選択肢が 1 つも残らない土地を外す（$current の今の仕入れ案件は残す）。
     *
     * @param  Collection<int, ReProcurement>  $procurements
     * @return Collection<int, ReProcurement>
     */
    public static function offered(Collection $procurements, HsProperty|HsCustomOrder|null $current): Collection
    {
        $lotIdsByProcurement = ReProcurementLot::whereIn('procurement_id', $procurements->pluck('id'))
            ->get(['id', 'procurement_id'])
            ->groupBy('procurement_id');
        $excluded = self::excludedLotIds($lotIdsByProcurement->keys()->all(), $current);
        $currentId = self::digits($current?->getRawOriginal('re_procurement_id'));

        return $procurements->filter(function (ReProcurement $procurement) use ($lotIdsByProcurement, $excluded, $currentId) {
            $lots = $lotIdsByProcurement->get($procurement->id);
            $full = $lots !== null && $lots->every(fn (ReProcurementLot $lot) => in_array((int) $lot->id, $excluded, true));

            return ! $full || (int) $procurement->id === $currentId;
        })->values();
    }

    /**
     * 建売・注文住宅の登録と編集の画面（_form）の仕入れ案件の選択肢（決済完了・販売中。JS に渡す形）。
     *
     * @return array<int, array{id: int, code: string, name: string, address: ?string}>
     */
    public static function formOptions(HsProperty|HsCustomOrder|null $current): array
    {
        $procurements = ReProcurement::whereIn('status', ['settled', 'selling'])
            ->orderByDesc('id')
            ->get(['id', 'procurement_code', 'property_name', 'address']);

        return self::offered($procurements, $current)
            ->map(fn (ReProcurement $procurement) => [
                'id'      => $procurement->id,
                'code'    => $procurement->procurement_code,
                'name'    => $procurement->property_name,
                'address' => $procurement->address,
            ])
            ->all();
    }

    /**
     * 区画の選択の初めの値: 選んでいる仕入れ案件・その区画の選択肢・選んでいる区画（区画の選択肢に無ければ null）。
     * ⚠ 区画の `<option>` は `<template x-for>` なので、一覧を空から始めると今の区画が選べず、空のまま送られる（Top trap #3）。
     *
     * @return array{procurementId: ?int, lots: array<int, array<string, mixed>>, lotId: ?int}
     */
    public static function initialSelection(mixed $procurementId, mixed $lotId, HsProperty|HsCustomOrder|null $current): array
    {
        $id = self::digits($procurementId);
        $procurement = $id === null ? null : ReProcurement::find($id);
        if ($procurement === null) {
            return ['procurementId' => null, 'lots' => [], 'lotId' => null];
        }

        $lots = self::lotOptions($procurement, $current);
        $chosen = self::digits($lotId);
        $listed = $chosen !== null && in_array($chosen, array_map(fn (array $lot) => (int) $lot['id'], $lots), true);

        return ['procurementId' => $procurement->id, 'lots' => $lots, 'lotId' => $listed ? $chosen : null];
    }

    /** int と数字だけの文字を id に（それ以外・空は null） */
    private static function digits(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && $value !== '' && ctype_digit($value) ? (int) $value : null;
    }
}
