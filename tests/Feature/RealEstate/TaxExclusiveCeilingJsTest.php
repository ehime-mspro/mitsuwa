<?php

namespace Tests\Feature\RealEstate;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 税込 → 税抜の逆算が Blade の JS でも **切り上げ**であることを固定する。
 *
 * 切り捨てると税込に戻したとき 1 円足りなくなる
 * （税込 12,500,000 → 税抜 11,363,636 → 税込 12,499,999）。
 *
 * ⚠ **PHP のテストだけでは原理的に守れない。**
 *    税込入力欄は name 属性を持たず送信されないため、サーバは逆算を一度も実行しない
 *    （`grep -rn "toExclusive" app/` は 0 件）。実際に画面で効いているのは Alpine の JS だけで、
 *    そこが Math.floor に戻っても ConsumptionTaxTest は全部緑のまま通る。
 *    呼び出し側（Blade）と仕様（PHP）を対で検証する
 *    （docs/RULES.md Bug #28 / #35 と同じ構図）。
 */
class TaxExclusiveCeilingJsTest extends TestCase
{
    /**
     * 走査で拾えるはずの逆算箇所の下限。走査が空振りして緑になる事故を防ぐ。
     *
     * 実数は 3（仕入れ案件フォーム / 契約 create / 契約 edit）。
     * 走査ロジックが壊れれば 0 になるのでこの値で検知できる。
     */
    private const MIN_CALL_SITES = 3;

    /** 税込 → 税抜の逆算行にだけ一致する（除数が `10000 + 税率bp` になっている行）。 */
    private const REVERSE_CALC_PATTERN = '/(Math\.\w+)\(\s*\w+\s*\*\s*10000\s*\/\s*\(\s*10000\s*\+[^)]*\)\s*\)/';

    /** @return array<string, string[]> ファイルパス => 逆算に使われている Math メソッド名 */
    private function reverseCalcSites(): array
    {
        $sites = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            if (preg_match_all(self::REVERSE_CALC_PATTERN, $file->getContents(), $matches)) {
                $sites[$file->getRelativePathname()] = $matches[1];
            }
        }

        return $sites;
    }

    public function test_scan_finds_the_reverse_calculation_sites(): void
    {
        $count = array_sum(array_map('count', $this->reverseCalcSites()));

        $this->assertGreaterThanOrEqual(
            self::MIN_CALL_SITES,
            $count,
            '税込→税抜の逆算箇所を走査できていない。正規表現が実装とずれた可能性がある'
        );
    }

    public function test_all_reverse_calculations_round_up(): void
    {
        foreach ($this->reverseCalcSites() as $path => $methods) {
            foreach ($methods as $method) {
                $this->assertSame(
                    'Math.ceil',
                    $method,
                    "{$path}: 税込→税抜の逆算は Math.ceil でなければならない（{$method} になっている）。"
                        . '切り捨てると税込に戻したとき 1 円足りなくなる'
                );
            }
        }
    }

    /**
     * 税額そのものは**切り捨てのまま**であること。
     *
     * 逆算を切り上げに直したついでに税額まで切り上げると、消費税額が 1 円多く出る
     * （Bug #33 / #34 で確立した「税額は切り捨て」の規約に反する）。
     */
    public function test_tax_amount_itself_still_rounds_down(): void
    {
        $found = 0;

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // 税額の算出: `<金額> * <税率bp> / 10000`（除数が 10000 ちょうど）
            if (preg_match_all('/(Math\.\w+)\(\s*\w+\s*\*\s*this\.taxBp\(\)\s*\/\s*10000\s*\)/', $file->getContents(), $matches)) {
                foreach ($matches[1] as $method) {
                    $found++;
                    $this->assertSame(
                        'Math.floor',
                        $method,
                        $file->getRelativePathname() . ": 税額の丸めは Math.floor（切り捨て）のままにすること（{$method} になっている）"
                    );
                }
            }
        }

        $this->assertGreaterThanOrEqual(1, $found, '税額算出の走査が空振りしている');
    }
}
