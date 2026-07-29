<?php

namespace Tests\Unit\Support;

use App\Models\ReProjectLot;
use App\Support\TsuboPrice;
use PHPUnit\Framework\TestCase;

/**
 * 坪単価の仕様を固定する（DB 非依存）。
 *
 * 仕様: 丸めは常に**切り上げ**。
 *   - 分譲地の販売坪単価: 万円単位・小数第1位（小数第2位を切り上げ）
 *   - テナントの賃料坪単価: 円単位・整数（1円未満を切り上げ）
 *
 * このテストは「正しい答えが出ること」より、**過去に踏んだ 2 つの誤実装に戻ったら落ちること**
 * を主目的にしている。値を消すと再発を検出できなくなる。
 */
class TsuboPriceTest extends TestCase
{
    /**
     * ユーザー提示の実例。
     * 9,880,000円 / 33.33坪 = 296,429.64…円/坪 = 29.642964…万円 → 小数第2位切り上げで 29.7
     */
    public function test_reported_case(): void
    {
        $this->assertSame('@29.7', TsuboPrice::perTsuboManLabel(9880000, '33.33'));
        $this->assertSame(297, TsuboPrice::perTsuboManTenths(9880000, '33.33'));
    }

    /**
     * 罠①: 「円/坪を四捨五入して保存 → そこから万円へ切り上げ」の二段階丸めに戻すと落ちる。
     *
     * 真値が 1,000 円の倍数を 0.5 円未満だけ超えるとき、前段の四捨五入が引き下げてしまい
     * 後段の切り上げが効かなくなる。実測 800 万通り中 1,529 件が該当し、その代表を固定する。
     */
    public function test_single_rounding_not_two_stage(): void
    {
        // 999,000.4998…円/坪。二段階だと 999,000 に落ちて @99.9 になる
        $this->assertSame('@100.0', TsuboPriceTest::label(19990000, '20.01'));
        // 333,000.4993…円/坪。二段階だと @33.3 になる
        $this->assertSame('@33.4', TsuboPriceTest::label(6670000, '20.03'));
        // 857,000.4983…円/坪。二段階だと @85.7 になる
        $this->assertSame('@85.8', TsuboPriceTest::label(17200000, '20.07'));
    }

    /**
     * 罠②: float の ceil に戻すと落ちる。
     *
     * ceil($amount / (float) $tsubo) は二進誤差で**割り切れる場合に 1 円上振れ**する。
     * 実測 877,851 通り中 115 件が該当し、その代表を固定する。
     */
    public function test_exact_integer_arithmetic_not_float_ceil(): void
    {
        // 153,000 / 5.10 はちょうど 30,000。float ceil だと 30,001 になる
        $this->assertSame(30000, TsuboPrice::perTsuboYen(153000, '5.10'));
        $this->assertSame(50000, TsuboPrice::perTsuboYen(251000, '5.02'));
        $this->assertSame(12500, TsuboPrice::perTsuboYen(69000, '5.52'));
    }

    /**
     * 円/坪は 1 円未満を切り上げる（四捨五入でも切り捨てでもない）。
     */
    public function test_yen_per_tsubo_rounds_up(): void
    {
        // 100,000 / 3.00 = 33,333.33… → 33,334
        $this->assertSame(33334, TsuboPrice::perTsuboYen(100000, '3.00'));
        // 割り切れる場合は切り上げない
        $this->assertSame(50000, TsuboPrice::perTsuboYen(100000, '2.00'));
    }

    /**
     * 万円表示は 3 桁区切りを保ち、小数第1位を必ず 1 桁出す。
     */
    public function test_man_label_formatting(): void
    {
        $this->assertSame('@111.2', TsuboPrice::perTsuboManLabel(22320000, '20.09'));
        // ちょうど割り切れて小数が 0 でも "1" 桁出す（"@30" ではなく "@30.0"）
        $this->assertSame('@30.0', TsuboPrice::perTsuboManLabel(3000000, '10.00'));
        // 4 桁以上は 3 桁区切りが入る
        $this->assertSame('@1,000.0', TsuboPrice::perTsuboManLabel(100000000, '10.00'));
    }

    /**
     * 坪数が 0 / null なら坪単価を出さない（0 除算や TypeError で落ちない）。
     *
     * ⚠ null は実在する。テナント区画は坪を手入力するので未設定の行があり、
     *    ここを非 null 型に狭めると区画詳細・契約詳細・フロアマップが 500 になる
     *    （`tests/Feature/Tenant/UnitRentRevisionTest` が実際に検出した）。
     */
    public function test_zero_or_null_tsubo_returns_null(): void
    {
        foreach (['0.00', null] as $tsubo) {
            $this->assertNull(TsuboPrice::perTsuboYen(100000, $tsubo));
            $this->assertNull(TsuboPrice::perTsuboManTenths(100000, $tsubo));
            $this->assertNull(TsuboPrice::perTsuboManLabel(100000, $tsubo));
        }
    }

    /**
     * 分譲地区画の表示は**保存済みの円/坪カラムを見ない**こと。
     *
     * あのカラムは丸め済みなので、経由すると二段階丸めに戻る。
     * ここではカラムにわざと嘘の値を入れ、それが表示に影響しないことで経路を固定する。
     */
    public function test_lot_label_ignores_stored_intermediate_column(): void
    {
        $lot = new ReProjectLot([
            'selling_price'           => 19990000,
            'area_tsubo'              => '20.01',
            'selling_price_per_tsubo' => 1,   // ← 明らかに嘘。これを見ていたら @0.1 等になる
        ]);

        $this->assertSame('@100.0', $lot->getSellingPricePerTsuboFormatted());
    }

    /**
     * 販売価格が未入力の区画は坪単価を出さない。
     */
    public function test_lot_without_price_has_no_label(): void
    {
        $this->assertNull((new ReProjectLot(['area_tsubo' => '20.00']))->getSellingPricePerTsuboFormatted());
        $this->assertNull((new ReProjectLot(['area_tsubo' => '20.00', 'selling_price' => 0]))->getSellingPricePerTsuboFormatted());
    }

    /**
     * 坪単価の計算が app/ に直書きで残っていないこと（ヘルパー経由に一本化されていること）を走査で固定する。
     *
     * `TsuboPrice` 自身の docblock には警告として float ceil の例が書いてあるので走査から除く。
     */
    public function test_no_view_reimplements_per_tsubo_ceil(): void
    {
        $root = dirname(__DIR__, 3);
        $files = [];
        foreach (['/resources/views', '/app'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . $dir));
            foreach ($it as $f) {
                if ($f->isFile() && in_array($f->getExtension(), ['php'], true)) {
                    $files[] = $f->getPathname();
                }
            }
        }

        $this->assertGreaterThan(300, count($files), '走査が空振りしている');

        foreach ($files as $file) {
            if (str_ends_with($file, 'app/Support/TsuboPrice.php')) {
                continue;
            }
            $body = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                '/ceil\([^)]*(?:areaTsubo|area_tsubo|PerTsubo)/i',
                $body,
                str_replace($root . '/', '', $file) . ' が坪単価の切り上げを直書きしている'
            );
        }
    }

    /** 二段階丸めに戻したら落ちることを明示するための薄いラッパー */
    private static function label(int $price, string $tsubo): string
    {
        return (string) TsuboPrice::perTsuboManLabel($price, $tsubo);
    }
}
