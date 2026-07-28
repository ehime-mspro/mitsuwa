<?php

namespace Tests\Unit\Support;

use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use App\Support\AreaConverter;
use PHPUnit\Framework\TestCase;

/**
 * ㎡ → 坪 換算の仕様を固定する（DB 非依存）。
 *
 * 仕様: ㎡ × 0.3025 → 小数第3位以下を切り捨て → 小数2桁。
 *
 * このテストは「正しい答えが出ること」より、**過去に踏んだ 2 つの誤実装に戻ったら落ちること**
 * を主目的にしている。どちらも一見それらしく動くので、値を消すと再発を検出できなくなる。
 */
class AreaConverterTest extends TestCase
{
    /**
     * ユーザー報告の実例。
     * 132.69 × 0.3025 = 40.138725 なので 40.13。四捨五入だと 40.14 になり落ちる。
     */
    public function test_reported_case_is_truncated_not_rounded(): void
    {
        $this->assertSame(40.13, AreaConverter::sqmToTsubo(132.69));
    }

    /**
     * 罠①: 「÷ 3.30579」に戻すと落ちる。
     *
     * 1 ÷ 3.30579 = 0.30249995… で 0.3025 より僅かに小さいため、切り捨てると 1 銭下にズレる。
     * 四捨五入時代は差が吸収されていたので、この 2 件は除算方式では絶対に通らない。
     */
    public function test_multiplication_not_division(): void
    {
        $this->assertSame(30.25, AreaConverter::sqmToTsubo(100.00), '除算方式だと 30.24 になる');
        $this->assertSame(60.5, AreaConverter::sqmToTsubo(200.00), '除算方式だと 60.49 になる');
    }

    /**
     * 罠②: float の floor($sqm * 0.3025 * 100) / 100 に戻すと落ちる。
     *
     * 二進誤差で積が僅かに下振れし、切り捨てが 1 銭下に落ちる。
     * 0.01㎡ 刻み 10 万件のうち 41 件が該当し、その代表 3 件を固定する。
     */
    public function test_exact_integer_arithmetic_not_naive_float(): void
    {
        $this->assertSame(8.47, AreaConverter::sqmToTsubo(28.00), '素朴な float だと 8.46 になる');
        $this->assertSame(13.31, AreaConverter::sqmToTsubo(44.00), '素朴な float だと 13.3 になる');
        $this->assertSame(18.15, AreaConverter::sqmToTsubo(60.00), '素朴な float だと 18.14 になる');
    }

    /**
     * 境界値。0 と、割り切れる値と、カラム上限 decimal(10,2) の最大。
     */
    public function test_boundaries(): void
    {
        $this->assertSame(0.0, AreaConverter::sqmToTsubo(0.00));
        $this->assertSame(50.0, AreaConverter::sqmToTsubo(165.29), 'ほぼ 50 坪');
        $this->assertSame(30249999.99, AreaConverter::sqmToTsubo(99999999.99), 'decimal(10,2) の上限');
    }

    /**
     * ㎡ カラムは全て decimal:2 キャストなので、属性は float ではなく**文字列**で来る。
     * 文字列を渡しても float と同じ結果になること。
     */
    public function test_accepts_decimal_cast_string(): void
    {
        $this->assertSame(
            AreaConverter::sqmToTsubo(132.69),
            AreaConverter::sqmToTsubo('132.69')
        );
    }

    /**
     * 換算を持つ 6 メソッドが全てヘルパーと同じ値を返すこと。
     *
     * ⚠ 1 箇所だけ直して他を見落とす事故を防ぐのが目的なので、モデルを増やしたらここにも足す。
     * DB は要らない（属性を渡してインスタンス化するだけ）。
     */
    public function test_all_model_accessors_match_the_helper(): void
    {
        $expected = AreaConverter::sqmToTsubo(132.69);
        $this->assertSame(40.13, $expected, '前提が崩れていないこと');

        $this->assertSame($expected, (new ReProcurement(['land_area_sqm' => 132.69]))->getLandAreaTsubo());
        $this->assertSame($expected, (new ReProject(['land_area_sqm' => 132.69]))->getLandAreaTsubo());
        $this->assertSame($expected, ReProjectLot::sqmToTsubo(132.69));

        $this->assertSame($expected, (new HsProperty(['land_area_sqm' => 132.69]))->getLandAreaTsubo());
        $this->assertSame($expected, (new HsProperty(['building_area_sqm' => 132.69]))->getBuildingAreaTsubo());

        $this->assertSame($expected, (new HsCustomOrder(['land_area_sqm' => 132.69]))->getLandAreaTsubo());
        $this->assertSame($expected, (new HsCustomOrder(['building_area_sqm' => 132.69]))->getBuildingAreaTsubo());
    }

    /**
     * 面積未入力は null のまま返す（0 坪と表示してはいけない）。
     */
    public function test_null_area_stays_null(): void
    {
        $this->assertNull((new ReProcurement())->getLandAreaTsubo());
        $this->assertNull((new ReProject())->getLandAreaTsubo());
        $this->assertNull((new HsProperty())->getLandAreaTsubo());
        $this->assertNull((new HsProperty())->getBuildingAreaTsubo());
        $this->assertNull((new HsCustomOrder())->getLandAreaTsubo());
        $this->assertNull((new HsCustomOrder())->getBuildingAreaTsubo());
    }

    /**
     * モデルが自前で換算式を持っていないこと（＝ヘルパー経由に一本化されていること）を走査で固定する。
     *
     * `AreaConverter` の docblock には警告として 3.30579 が書いてあるので、走査対象は app/Models/ に限る。
     * 走査が空振りして緑になる事故を防ぐため、モデルを十分拾えていることも併せて確認する。
     */
    public function test_no_model_reimplements_the_conversion(): void
    {
        $modelDir = dirname(__DIR__, 3) . '/app/Models';
        $files = glob($modelDir . '/*.php');

        $this->assertGreaterThan(30, count($files), 'モデルの走査が空振りしている');

        foreach ($files as $file) {
            $this->assertStringNotContainsString(
                '3.30579',
                (string) file_get_contents($file),
                basename($file) . ' が旧換算式（÷3.30579）を持っている'
            );
        }
    }
}
