<?php

namespace App\Support;

/**
 * 区画の土地原価の按分（販売価格の比率）。分譲地の区画（ReProject::allocatedLandCost()）と
 * 土地の仕入れ案件の区画（ReProcurement::landCostForLot()）が**この 1 か所**を使う（Bug #41。
 * 式を 2 か所に置くと、片方だけ直したときに契約の原価欄と住宅事業の土地原価が食い違う）。
 *
 * 式: その区画の販売価格 ÷ 全区画の販売価格の合計 × 原価の合計（四捨五入）。
 * 全区画に販売価格が入っていない・合計が 0・この区画の販売価格が空なら計算できない（null）。
 */
final class LandCostAllocation
{
    public static function share(int $costTotal, ?int $lotPrice, int $priceTotal, bool $allPriced): ?int
    {
        if (! $allPriced || $priceTotal <= 0 || $lotPrice === null) {
            return null;
        }

        return (int) round($costTotal * ($lotPrice / $priceTotal));
    }
}
