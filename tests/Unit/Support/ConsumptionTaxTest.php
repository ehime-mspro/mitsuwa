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
     * 往復で 1 円ずれることを**仕様として**固定する（設計書 §10-1）。
     *
     * 税抜を正として保存する以上避けられない。契約側は tax_amount の手入力で実額に合わせられる。
     */
    public function test_round_trip_may_lose_one_yen(): void
    {
        $excl = ConsumptionTax::toExclusive(33000001, '10.00');
        $this->assertSame(30000000, $excl);
        $this->assertSame(33000000, ConsumptionTax::toInclusive($excl, '10.00'));
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
        $this->assertSame(1073795513, ConsumptionTax::toExclusive($max, '99.99'));
        $this->assertSame($max, ConsumptionTax::toInclusive($max, '0.00'));
    }
}
