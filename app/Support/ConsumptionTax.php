<?php

namespace App\Support;

/**
 * 消費税ヘルパー
 *
 * 土地の譲渡は非課税、建物の譲渡は課税。よって**課税対象は建物価格のみ**。
 * DB に保存する金額は常に税抜で、税額は都度算出する（派生カラムを持たない）。
 *
 * 丸めは **切り捨て**（プロジェクトの丸め規約。坪数・坪単価と同じ）。
 *
 * ⚠ 罠① round に戻さないこと。
 *    12,345,675 × 10% = 1,234,567.5 → 切り捨て 1,234,567 だが round だと 1,234,568。
 *
 * ⚠ 罠② float 除算に戻さないこと。
 *    (int) ($incl / (1 + $rate / 100)) は 1.1 が二進で表せないため商が真値より僅かに小さくなり、
 *    真値がちょうど整数のときに 1 円下振れする:
 *      33,000,000 → 29,999,999（正: 30,000,000） / 16,500,000 → 14,999,999（正: 15,000,000）
 *
 * 税率カラムは decimal(5,2) なので 100 倍した整数（basis point）に直せば厳密になる。
 * 金額カラムは INT（最大 2,147,483,647）で、被除数は最大 2.1e13 ＝ PHP_INT_MAX の 1/400,000。
 *
 * 金額は非負を前提とする（呼び出し元は全て integer|min:0 で検証済み）。
 */
class ConsumptionTax
{
    /** 税率（%）を basis point 整数にするための係数。10.00% → 1000 */
    private const RATE_SCALE = 100;

    /** 100% を basis point で表した値 */
    private const RATE_BASE = 10000;

    /**
     * 建物価格（税抜）に対する消費税額。1 円未満を切り捨てる。
     *
     * 金額 null は null で返す（未入力を「0 円」にしないため）。
     */
    public static function tax(?int $excl, float|int|string|null $rate): ?int
    {
        if ($excl === null) {
            return null;
        }

        return intdiv($excl * self::rateBp($rate), self::RATE_BASE);
    }

    /**
     * 税抜 → 税込
     */
    public static function toInclusive(?int $excl, float|int|string|null $rate): ?int
    {
        if ($excl === null) {
            return null;
        }

        return $excl + (int) self::tax($excl, $rate);
    }

    /**
     * 税込 → 税抜（逆算）。1 円未満を切り捨てる。
     *
     * ⚠ 往復すると 1 円落ちることがある（33,000,001 → 30,000,000 → 33,000,000）。
     *    税抜を正として保存する以上原理的に避けられない仕様。
     */
    public static function toExclusive(?int $inclusive, float|int|string|null $rate): ?int
    {
        if ($inclusive === null) {
            return null;
        }

        return intdiv($inclusive * self::RATE_BASE, self::RATE_BASE + self::rateBp($rate));
    }

    /**
     * 税率を basis point 整数に正規化する。
     *
     * decimal:2 キャスト済み属性は**文字列**で来るため string も受ける。
     * null は 0%（税額 0）として扱う。
     *
     * ⚠ 負の税率は 0% に丸める。負値をそのまま通すと toExclusive() の除数
     *    (RATE_BASE + rateBp) が -100.00% ちょうどで 0 になり DivisionByZeroError で落ちる。
     *    呼び出し元は numeric|min:0 で検証済みだが、Support クラスは独立して再利用されるため
     *    ここでも防ぐ（TsuboPrice が除数を自前でガードしているのと同じ流儀）。
     */
    private static function rateBp(float|int|string|null $rate): int
    {
        if ($rate === null) {
            return 0;
        }

        return max(0, (int) round((float) $rate * self::RATE_SCALE));
    }
}
