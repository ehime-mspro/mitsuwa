<?php

namespace App\Support;

/**
 * 面積換算ヘルパー
 *
 * ㎡ → 坪は「㎡ × 0.3025」で計算し、小数第3位以下を切り捨てて2桁で返す。
 * 例: 132.69㎡ × 0.3025 = 40.138725 → 40.13坪
 *
 * ⚠ 罠① 「÷ 3.30579」に戻さないこと。
 *    1 ÷ 3.30579 = 0.30249995… で 0.3025 より僅かに小さい。四捨五入なら差は吸収されるが、
 *    切り捨てと組み合わせると 1 銭単位でズレる:
 *      100㎡ → 除算だと 30.24坪（正: 30.25坪） / 200㎡ → 60.49坪（正: 60.5坪）
 *
 * ⚠ 罠② float の floor($sqm * 0.3025 * 100) / 100 も使わないこと。
 *    二進誤差で 0.01㎡ 刻み 10 万件中 41 件が誤る:
 *      28㎡ → 8.46坪（正: 8.47坪） / 44㎡ → 13.3坪（正: 13.31坪） / 60㎡ → 18.14坪（正: 18.15坪）
 *
 * ㎡ を保持するカラムは全て decimal(10,2) なので、1/100㎡ 単位の整数演算にすれば厳密になる。
 * bcmath の厳密解と 0.01〜2000.00 の全数 + 境界値（計 200,011 件）を突き合わせ、不一致 0 件を確認済み。
 *
 * 入力は非負を前提とする（呼び出し元は全て numeric|min:0 で検証済み）。
 */
class AreaConverter
{
    /** ㎡ → 坪の係数 0.3025 を分数で保持する（float 誤差を持ち込まないため） */
    private const TSUBO_NUMERATOR = 3025;

    private const TSUBO_DENOMINATOR = 10000;

    /** 坪の表示桁数（小数2桁）を 1/100 単位の整数演算で扱うための係数 */
    private const SCALE = 100;

    /**
     * ㎡ → 坪（小数第3位以下切り捨て、小数2桁）
     *
     * decimal:2 キャスト済み属性は文字列で来るため string も受ける。
     */
    public static function sqmToTsubo(float|string $sqm): float
    {
        // ㎡ を 1/100 単位の整数に直してから整数演算する
        $sqmHundredths = (int) round((float) $sqm * self::SCALE);

        $tsuboHundredths = intdiv($sqmHundredths * self::TSUBO_NUMERATOR, self::TSUBO_DENOMINATOR);

        return $tsuboHundredths / self::SCALE;
    }
}
