<?php

namespace Tests\Feature\Housing\Screens;

use App\Models\HsProperty;
use App\Support\HousingLandCost;

/**
 * 土地原価の手動 OFF の保存で、画面（JS の housingLandCostView()）とサーバ（HousingLandCost::decide()）が同じ値を決めること
 * （判断待ち ⑤。Bug #41: 同じ決まりを 2 か所に書くと片側だけ変わって、画面に出した値と保存した値が割れる）。
 *
 * 描いた建売の編集画面の script を node の vm で読み、場面の全部（4 つの条件 × 計算値 × 保存値）を両方に通して突き合わせる。
 * ⚠ node が無い環境では skip する（AreaBuildingImportParserParityTest と同じ）。
 */
class LandCostViewParityTest extends HousingScreenTestCase
{
    public function test_the_screen_decides_like_the_server(): void
    {
        $cases = [];
        foreach ([false, true] as $creating) {
            foreach ([false, true] as $savedManual) {
                foreach ([false, true] as $linkChanged) {
                    foreach ([false, true] as $refresh) {
                        foreach ([9600000, null] as $computed) {
                            foreach ([5000000, null, 9600000] as $saved) {
                                $cases[] = compact('creating', 'savedManual', 'linkChanged', 'refresh', 'computed', 'saved');
                            }
                        }
                    }
                }
            }
        }
        $this->assertCount(96, $cases);

        $js = $this->runScreenRule($cases);
        $this->assertCount(count($cases), $js);

        foreach ($cases as $i => $c) {
            $this->assertSame(
                HousingLandCost::decide($c['creating'], $c['savedManual'], $c['linkChanged'], $c['refresh'], $c['computed'], $c['saved']),
                $js[$i],
                '場面 ' . json_encode($c) . ' で画面とサーバの値が割れている'
            );
        }
    }

    public function test_the_link_comparison_matches_between_php_and_js(): void
    {
        $p = ['land_source_type' => 'procurement'];
        // [保存済み, 送った値]（キーは land_source_type・re_project_lot_id・re_procurement_id・re_procurement_lot_id）
        $cases = [
            [$p + ['re_procurement_id' => 1, 're_procurement_lot_id' => 5], $p + ['re_procurement_id' => '1', 're_procurement_lot_id' => '5']],
            [$p + ['re_procurement_id' => 1, 're_procurement_lot_id' => 5], $p + ['re_procurement_id' => 1, 're_procurement_lot_id' => 6]],
            [$p + ['re_procurement_id' => 1, 're_procurement_lot_id' => 5], $p + ['re_procurement_id' => 1, 're_procurement_lot_id' => null]],
            [$p + ['re_procurement_id' => 1, 're_procurement_lot_id' => null], $p + ['re_procurement_id' => 1, 're_procurement_lot_id' => '']],
            [$p + ['re_procurement_id' => 1, 're_procurement_lot_id' => 5], $p + ['re_procurement_id' => 1, 're_procurement_lot_id' => '05']],
            [$p + ['re_procurement_id' => 1, 're_procurement_lot_id' => null], $p + ['re_procurement_id' => 1, 're_procurement_lot_id' => 5]],
            [$p + ['re_procurement_id' => 1, 're_procurement_lot_id' => 5], $p + ['re_procurement_id' => 2, 're_procurement_lot_id' => 5]],
            [$p + ['re_procurement_id' => 7, 're_procurement_lot_id' => 5], $p + ['re_procurement_id' => '07', 're_procurement_lot_id' => '5']],
            [$p + ['re_procurement_id' => 1, 're_procurement_lot_id' => 5], ['land_source_type' => '', 're_procurement_id' => 1, 're_procurement_lot_id' => 5]],
            [$p + ['re_procurement_id' => 1], $p + ['re_procurement_id' => 1]],
            [['land_source_type' => 'project_lot', 're_project_lot_id' => 3, 're_procurement_lot_id' => 5], ['land_source_type' => 'project_lot', 're_project_lot_id' => '3', 're_procurement_lot_id' => 9]],
            [['land_source_type' => 'project_lot', 're_project_lot_id' => 3], ['land_source_type' => 'project_lot', 're_project_lot_id' => 4]],
            [['land_source_type' => 'customer_land', 're_procurement_lot_id' => 5], ['land_source_type' => 'customer_land', 're_procurement_lot_id' => 9]],
            [$p + ['re_procurement_id' => 1, 're_procurement_lot_id' => 5], ['land_source_type' => 'project_lot', 're_project_lot_id' => 5, 're_procurement_lot_id' => 5]],
            [['land_source_type' => null, 're_procurement_lot_id' => 5], ['land_source_type' => null, 're_procurement_lot_id' => 6]],
        ];
        $this->assertGreaterThanOrEqual(12, count($cases));

        $js = $this->runInScreen(
            '__cases.map(function (c) { return housingLandLinkChanged(c.saved, c.now); })',
            array_map(fn (array $c) => ['saved' => $this->jsLink($c[0]), 'now' => $this->jsLink($c[1])], $cases)
        );

        foreach ($cases as $i => [$saved, $now]) {
            $model = (new HsProperty())->forceFill($saved);
            $this->assertSame(
                $model->landLinkDiffers($now),
                $js[$i],
                '場面 ' . json_encode([$saved, $now]) . ' で、紐付け先を変えたかの判定が画面とサーバで割れている'
            );
        }
        // 区画だけを変えるのは「変えた」（取り違えて全部 false を返す実装を許さない）
        $this->assertTrue($js[1]);
        $this->assertFalse($js[3]);
        $this->assertFalse($js[4]);
    }

    /** サーバの入力の形（列名）を、画面の JS が比べる形（type・lot・procurement・procurementLot）へ */
    private function jsLink(array $link): array
    {
        return [
            'type' => $link['land_source_type'] ?? null,
            'lot' => $link['re_project_lot_id'] ?? null,
            'procurement' => $link['re_procurement_id'] ?? null,
            'procurementLot' => $link['re_procurement_lot_id'] ?? null,
        ];
    }

    /** @return list<int|null> 各場面で housingLandCostView({manual: false, …}).value */
    private function runScreenRule(array $cases): array
    {
        return $this->runInScreen(
            '__cases.map(function (c) { return housingLandCostView({ manual: false, creating: c.creating, savedManual: c.savedManual, linkChanged: c.linkChanged, refresh: c.refresh, computed: c.computed, saved: c.saved, hasLink: true }).value; })',
            $cases
        );
    }

    /**
     * 建売の編集画面が描いた土地原価の決まりの script を node の vm で読み、$expression（__cases を使える JS の式）を評価した結果を返す。
     *
     * @return list<mixed>
     */
    private function runInScreen(string $expression, array $cases): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので画面の JavaScript を動かせない');
        }

        $property = $this->property(['land_source_type' => 'project_lot', 're_project_lot_id' => $this->pricedProject()[1]->id]);
        $html = $this->htmlOf(route('housing.properties.edit', $property));
        preg_match_all('/<script>(.*?)<\/script>/su', $html, $scripts);
        $script = collect($scripts[1])->first(fn (string $s) => str_contains($s, 'function housingLandCostView('));
        $this->assertNotNull($script, '建売の編集画面に、土地原価の決まりの script（housingLandCostView）が描かれていない');

        $harness = <<<'JS'
            const fs = require('fs');
            const vm = require('vm');
            const input = JSON.parse(fs.readFileSync(process.argv[1], 'utf8'));
            const context = vm.createContext({});
            vm.runInContext(input.script, context);
            context.__cases = input.cases;
            const values = vm.runInContext(input.expression, context);
            process.stdout.write(JSON.stringify(values));
            JS;

        $file = tempnam(sys_get_temp_dir(), 'land-cost-parity-');
        try {
            file_put_contents($file, json_encode(['script' => $script, 'cases' => $cases, 'expression' => $expression], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $output = shell_exec(sprintf('%s -e %s %s 2>&1', escapeshellarg($node), escapeshellarg($harness), escapeshellarg($file)));
        } finally {
            unlink($file);
        }

        $result = json_decode((string) $output, true);
        $this->assertIsArray($result, "node で画面の JavaScript を動かせなかった:\n" . $output);

        return $result;
    }
}
