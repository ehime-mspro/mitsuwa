<?php

namespace Tests\Unit\Support;

use App\Support\ConsumptionTax;
use PHPUnit\Framework\TestCase;

/**
 * 消費税の仕様を固定する（DB 非依存）。
 *
 * 仕様: 課税対象は建物価格のみ。丸めは **切り捨て**。除算は整数演算のみ。
 *
 * このテストは「正しい答えが出ること」より、**誤実装に戻したら落ちること**を主目的にしている。
 * 値を消すと再発を検出できなくなる（AreaConverterTest / TsuboPriceTest と同じ流儀）。
 */
class ConsumptionTaxTest extends TestCase
{
    /** 基本値（設計書 §8.1） */
    public function test_basic_values_at_ten_percent(): void
    {
        $this->assertSame(3000000, ConsumptionTax::tax(30000000, '10.00'));
        $this->assertSame(33000000, ConsumptionTax::toInclusive(30000000, '10.00'));
        $this->assertSame(30000000, ConsumptionTax::toExclusive(33000000, '10.00'));
    }

    /**
     * 罠①: 切り捨てを四捨五入(round)に戻すと落ちる。
     *
     * 12,345,675 × 10% = 1,234,567.5 → 切り捨て 1,234,567 / round だと 1,234,568
     */
    public function test_rounding_is_floor_not_round(): void
    {
        $this->assertSame(1234567, ConsumptionTax::tax(12345675, '10.00'));
        $this->assertSame(800000, ConsumptionTax::tax(8000005, '10.00'));
        $this->assertSame(0, ConsumptionTax::tax(5, '10.00'));
    }

    /**
     * 罠②: toExclusive を float 除算 `(int) ($incl / (1 + $rate / 100))` に戻すと落ちる。
     *
     * 1.1 は二進で正確に表せず商が真値より僅かに小さくなるため、
     * 真値がちょうど整数のときに 1 円下振れする。
     */
    public function test_to_exclusive_uses_integer_division(): void
    {
        $this->assertSame(30000000, ConsumptionTax::toExclusive(33000000, '10.00'));  // float だと 29,999,999
        $this->assertSame(15000000, ConsumptionTax::toExclusive(16500000, '10.00'));  // float だと 14,999,999
        $this->assertSame(30000000, ConsumptionTax::toExclusive(32400000, '8.00'));   // float だと 29,999,999
    }

    /**
     * 罠③: toExclusive を切り捨てに戻すと落ちる。
     *
     * ユーザー報告の実例（2026-07-31）。税込 12,500,000 の税抜は 11,363,636.36… で、
     * 切り捨てた 11,363,636 は税込に戻すと 12,499,999 と 1 円足りない。
     * 切り上げた 11,363,637 なら 12,500,000 に戻る。
     */
    public function test_to_exclusive_rounds_up(): void
    {
        $this->assertSame(11363637, ConsumptionTax::toExclusive(12500000, '10.00'));  // 切り捨てだと 11,363,636
        $this->assertSame(30000001, ConsumptionTax::toExclusive(33000001, '10.00'));  // 切り捨てだと 30,000,000
    }

    /**
     * 罠④: 「常に +1」の誤実装に変えると落ちる。
     *
     * 割り切れるときは足してはいけない。税込 11,000,000 の税抜はちょうど 10,000,000 で、
     * 10,000,001 にすると税込が 11,000,001 になってしまう。
     */
    public function test_to_exclusive_does_not_add_one_when_divisible(): void
    {
        $this->assertSame(10000000, ConsumptionTax::toExclusive(11000000, '10.00'));
        $this->assertSame(11000000, ConsumptionTax::toInclusive(10000000, '10.00'));
    }

    /**
     * 到達可能な税込なら往復が**完全に一致**する。
     *
     * 掃引実測（1,000,000〜100,000,000）: 10% で 90.91% / 8% で 92.59% が一致し、
     * 一致しなかったものは全件が下記の「到達不能な税込」だった。
     */
    public function test_round_trip_is_exact_when_reachable(): void
    {
        foreach ([12500000, 33000001, 11000000, 20009999, 32400000] as $inclusive) {
            $excl = ConsumptionTax::toExclusive($inclusive, '10.00');
            $this->assertSame(
                $inclusive,
                ConsumptionTax::toInclusive($excl, '10.00'),
                "税込 {$inclusive} の往復が一致しない"
            );
        }
    }

    /**
     * **どの税抜からも作れない税込が構造的に存在する**ことを仕様として固定する。
     *
     * 税額を切り捨てる以上 incl(E) = E + floor(E × 税率) は E が 1 増えるごとに 1 か 2 増えるため、
     * 飛ばされる値が出る（10% で 11 分の 1）。丸め方向を変えても位置が動くだけで消せない。
     * ⚠ 本番の建売物件 JG余戸南2号地がこれに該当する（2026-07-31 実測）。
     */
    public function test_some_inclusive_amounts_are_unreachable(): void
    {
        $this->assertSame(20009999, ConsumptionTax::toInclusive(18190909, '10.00'));
        $this->assertSame(20010001, ConsumptionTax::toInclusive(18190910, '10.00'));

        // 間の 20,010,000 を作れる税抜は無い。切り上げは「超えない最小」ではなく直上を返す
        $this->assertSame(18190910, ConsumptionTax::toExclusive(20010000, '10.00'));
        $this->assertSame(20010001, ConsumptionTax::toInclusive(18190910, '10.00'));
    }

    /** 税率 8% / 0% でも整数演算が破れない */
    public function test_other_rates(): void
    {
        $this->assertSame(2400000, ConsumptionTax::tax(30000000, '8.00'));
        $this->assertSame(32400000, ConsumptionTax::toInclusive(30000000, '8.00'));

        $this->assertSame(0, ConsumptionTax::tax(30000000, '0.00'));
        $this->assertSame(30000000, ConsumptionTax::toInclusive(30000000, '0.00'));
        $this->assertSame(30000000, ConsumptionTax::toExclusive(30000000, '0.00'));
    }

    /**
     * decimal:2 キャスト済み属性は**文字列**で来る。float でも同じ結果になること。
     * （引数を float に狭めると Eloquent 経由で TypeError になる）
     */
    public function test_rate_accepts_both_string_and_float(): void
    {
        $this->assertSame(3000000, ConsumptionTax::tax(30000000, '10.00'));
        $this->assertSame(3000000, ConsumptionTax::tax(30000000, 10.0));
        $this->assertSame(3000000, ConsumptionTax::tax(30000000, 10));
    }

    /**
     * null は null で返す。
     * ⚠ 引数を非 null に狭めると未入力の案件で 500 になる（Bug #34 で実際に踏んだ）。
     */
    public function test_null_in_null_out(): void
    {
        $this->assertNull(ConsumptionTax::tax(null, '10.00'));
        $this->assertNull(ConsumptionTax::toInclusive(null, '10.00'));
        $this->assertNull(ConsumptionTax::toExclusive(null, '10.00'));
    }

    /** 税率 null は 0% として扱う（レコードに税率が入っていない過去データ保険） */
    public function test_null_rate_is_treated_as_zero(): void
    {
        $this->assertSame(0, ConsumptionTax::tax(30000000, null));
        $this->assertSame(30000000, ConsumptionTax::toInclusive(30000000, null));
        $this->assertSame(30000000, ConsumptionTax::toExclusive(30000000, null));
    }

    /** 0 円は 0 円（null と区別する） */
    public function test_zero_is_not_null(): void
    {
        $this->assertSame(0, ConsumptionTax::tax(0, '10.00'));
        $this->assertSame(0, ConsumptionTax::toInclusive(0, '10.00'));
    }

    /**
     * 負の税率は 0% として扱う。
     *
     * ⚠ クランプを外すと -100.00% で toExclusive() の除数が 0 になり
     *    DivisionByZeroError で落ちる（500 になる）。
     */
    public function test_negative_rate_is_clamped_to_zero(): void
    {
        $this->assertSame(0, ConsumptionTax::tax(30000000, '-10.00'));
        $this->assertSame(30000000, ConsumptionTax::toInclusive(30000000, '-10.00'));
        $this->assertSame(30000000, ConsumptionTax::toExclusive(30000000, '-100.00'));
    }

    /**
     * INT カラム上限（2,147,483,647）× 税率上限（99.99%）でも桁溢れしないこと。
     *
     * クラス docblock が「被除数は最大 2.1e13 ＝ PHP_INT_MAX の 1/400,000」と
     * 設計根拠にしているので、その主張をテストで固定する
     * （AreaConverterTest::test_boundaries() と同じ流儀）。
     */
    public function test_boundaries(): void
    {
        $max = 2147483647;

        $this->assertSame(2147268898, ConsumptionTax::tax($max, '99.99'));
        $this->assertSame(1073795514, ConsumptionTax::toExclusive($max, '99.99')); // 切り捨てだと 1,073,795,513
        $this->assertSame($max, ConsumptionTax::toInclusive($max, '0.00'));
    }
}
