<?php

namespace App\Support;

/**
 * 坪単価ヘルパー
 *
 * 坪単価は用途によって 2 つの表示形式があるが、**丸めはどちらも切り上げ**で共通:
 *   - 分譲地の販売坪単価: 万円単位・小数第1位（小数第2位を切り上げ）  例 "@29.7"
 *   - テナントの賃料坪単価: 円単位・整数（1円未満を切り上げ）          例 "@296,430"
 *
 * ⚠ 罠① 丸めは必ず 1 回だけにすること。
 *    「円/坪 を四捨五入して保存 → 表示時に万円へ切り上げ」の二段階にすると、真値が 1,000 円の
 *    倍数を 0.5 円未満だけ超えたときに前段の四捨五入が引き下げ、後段の切り上げが効かなくなる:
 *      19,990,000円 / 20.01坪 = 999,000.4998円/坪 → 二段階だと @99.9（正: @100.0）
 *    実測で 800 万通り中 1,529 件（0.019%）が該当した。
 *
 * ⚠ 罠② float の ceil を使わないこと。
 *    ceil($amount / (float) $tsubo) は二進誤差で**割り切れる場合に 1 円上振れ**する:
 *      153,000円 / 5.10坪 = ちょうど 30,000円 なのに 30,001円
 *    実測で 877,851 通り中 115 件が該当した。
 *
 * 坪数カラムは全て decimal(10,2) / decimal(8,2) なので、坪を 1/100 単位の整数に直せば
 * 除算を intdiv に置き換えられ、上記どちらの誤差も原理的に発生しない。
 *
 * 金額・坪数とも非負を前提とする（呼び出し元は全て integer|min:0 / numeric|min:0 で検証済み）。
 */
class TsuboPrice
{
    /** 坪数を 1/100 単位の整数として扱うための係数 */
    private const TSUBO_SCALE = 100;

    /** 1 万円 = 10,000 円。万円の 1/10 単位（＝表示の小数第1位）は 1,000 円 */
    private const YEN_PER_MAN_TENTH = 1000;

    /**
     * 円/坪（1 円未満を切り上げ)
     *
     * 例: 家賃 153,000円 / 5.10坪 → 30,000円（float の ceil だと 30,001 になる）
     */
    public static function perTsuboYen(int $amount, float|string|null $tsubo): ?int
    {
        $tsuboHundredths = self::tsuboHundredths($tsubo);
        if ($tsuboHundredths <= 0) {
            return null;
        }

        // 円/坪 = amount / (tsuboHundredths / 100) = amount * 100 / tsuboHundredths
        return intdiv($amount * self::TSUBO_SCALE + $tsuboHundredths - 1, $tsuboHundredths);
    }

    /**
     * 万円/坪 を「小数第1位まで」表す整数（＝万円の 1/10 単位）。小数第2位を切り上げる。
     *
     * 例: 9,880,000円 / 33.33坪 = 296,429.64…円/坪 = 29.642964…万円 → 297（＝29.7万円）
     */
    public static function perTsuboManTenths(int $price, float|string|null $tsubo): ?int
    {
        $tsuboHundredths = self::tsuboHundredths($tsubo);
        if ($tsuboHundredths <= 0) {
            return null;
        }

        // 円/坪 = price * 100 / tsuboHundredths なので、それを 1,000 円で割ると
        // 万円の1/10単位 = price / (tsuboHundredths * 1000 / 100) = price / (tsuboHundredths * 10)
        $denominator = $tsuboHundredths * intdiv(self::YEN_PER_MAN_TENTH, self::TSUBO_SCALE);

        return intdiv($price + $denominator - 1, $denominator);
    }

    /**
     * 分譲地の販売坪単価の表示用ラベル（"@29.7" 形式。万円単位・小数第1位）
     */
    public static function perTsuboManLabel(int $price, float|string|null $tsubo): ?string
    {
        $manTenths = self::perTsuboManTenths($price, $tsubo);
        if ($manTenths === null) {
            return null;
        }

        // 整数のまま整数部と小数第1位に割り、float を経由せずに組み立てる
        return '@' . number_format(intdiv($manTenths, 10)) . '.' . ($manTenths % 10);
    }

    /**
     * 坪数を 1/100 単位の整数に正規化する。decimal:2 キャスト済み属性は文字列で来る。
     *
     * 坪数は未設定（null）がありうる（テナント区画は坪を手入力するため空のことがある）。
     * その場合は 0 を返し、呼び出し側が null を返して「坪単価は出さない」に倒す。
     */
    private static function tsuboHundredths(float|string|null $tsubo): int
    {
        if ($tsubo === null) {
            return 0;
        }

        return (int) round((float) $tsubo * self::TSUBO_SCALE);
    }
}
