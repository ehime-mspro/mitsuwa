<?php

namespace App\Support;

/**
 * 消費税ヘルパー
 *
 * 土地の譲渡は非課税、建物の譲渡は課税。よって**課税対象は建物価格のみ**。
 * DB に保存する金額は常に税抜で、税額は都度算出する（派生カラムを持たない）。
 *
 * **税額の丸めは切り捨て**（プロジェクトの丸め規約。坪数と同じ）。
 * **税込 → 税抜の逆算だけは切り上げ**で、方向が違う。往復（税込を入れて税抜を保存し、
 * 表示でまた税込に戻す）が元の額に一致するようにするため。詳細は `toExclusive()` の注記。
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
     * 税込 → 税抜（逆算）。1 円未満を **切り上げる**。
     *
     * ⚠ 切り捨てに戻さないこと。税込 12,500,000 は税抜 11,363,636.36… で、
     *    切り捨てた 11,363,636 を税込に戻すと 12,499,999 と 1 円足りなくなる。
     *    切り上げた 11,363,637 なら 12,500,000 に戻る。
     *    実測: 10% で往復一致率が 9.09%（切り捨て）→ 90.91%（切り上げ）。
     *
     * ⚠ 「常に +1」ではなく切り上げ。割り切れるときに +1 すると逆に壊れる
     *    （税込 11,000,000 は税抜ちょうど 10,000,000）。
     *
     * ⚠ 切り上げても戻らない税込が残る（10% で 11 分の 1）。税額を切り捨てる以上、
     *    incl(E) = E + floor(E × 税率) は E が 1 増えるごとに 1 か 2 増えるため、
     *    **どの税抜からも作れない税込が構造的に存在する**（20,010,000 は
     *    18,190,909 → 20,009,999 と 18,190,910 → 20,010,001 の間で作れない）。
     *    丸め方向を変えても位置が動くだけで消せない。仕様として許容する。
     */
    public static function toExclusive(?int $inclusive, float|int|string|null $rate): ?int
    {
        if ($inclusive === null) {
            return null;
        }

        // 非負前提の整数切り上げ（TsuboPrice と同じ書き方）
        $divisor = self::RATE_BASE + self::rateBp($rate);

        return intdiv($inclusive * self::RATE_BASE + $divisor - 1, $divisor);
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
