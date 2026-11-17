<?php

namespace Tests\Feature\Tenant;

use App\Http\Controllers\Tenant\AreaBuildingImportController as Importer;
use App\Support\FloorNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Unit\Support\FloorNumberTest;

/**
 * 周辺ビル調査の取込で、プレビュー（JS）とサーバ（PHP）が件数と階を同じに読むこと（Bug #41・#47）。
 *
 * 取込の画面が描いた `<script>` を node の vm で読み込み、同じ入力をプレビューの読み方（areaImportParseCount・
 * areaImportParseFloor）とサーバの読み方（AreaBuildingImportController::parseInt() / parseCount()・FloorNumber::parse()）に
 * 通して突き合わせる。割れると「プレビューが取り込めると言った行をサーバが黙って捨てる」か、その逆になる。
 *
 * ⚠ 語彙や定数の一致を見るテスト（AreaBuildingImportTest::test_floor_vocabulary_matches_between_php_and_js など）だけでは、
 *   語彙を触らずに読み方だけを変えた片側の漂流を捕まえられない（Bug #47 の追記）。2026-08-17 に一度 node で手で
 *   突き合わせた作業（FloorNumberTest の docblock）を、ここで毎回行う。
 * ⚠ node が無い環境では skip する（RunsBuyerFormScript と同じ）。
 */
class AreaBuildingImportParserParityTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    /** 件数の入力（Excel のセルの文字。areaImportCellText() が文字にしたあとの形） */
    private const COUNT_INPUTS = [
        // 空欄
        '', ' ', "\u{3000}",
        // 素の数
        '0', '5', ' 5 ', '9999', '10000', '-1', '１２３', '99999999999999999999',
        // 正しい 3 桁ごとの区切り（全角のカンマは半角とみなす）
        '1,234', '１，２３４', '1,234円', '¥1,234', '￥5', '12円', '12 円', '9,999', '10,000', '-1,000',
        // 区切りの崩れ（2026-11-17 までは全部落として読んでいた）
        '1,5', '5,,0', ',15', '15,', '1，5', '1,2,3', '1,234.5', ',', '1,23,456', '5555,000',
        // 数字のあいだの空白・「円」
        '1 5', "1\u{3000}5", "1\u{00A0}5", '1, 234', '1 ,234', '1円5', '1¥5',
        // 数でない
        '1.5', 'abc', '数棟', '円', '-',
    ];

    /** 階の入力（FloorNumberTest の表の文字に、カンマ・空白の形を足す） */
    private const EXTRA_FLOOR_INPUTS = [
        '10階建', '10階建て', '地上 5階', '201', '-11', '99999999999',
        '2,5', '2，5', '1,0階', 'B1,0', ',', '1,000',
        '2 5', "1\u{3000}0階", '地下 1階', 'B 1', '1 F', ' 4 F ', 'B 1 0',
    ];

    public function test_the_preview_reads_counts_like_the_server(): void
    {
        $js = $this->runPreviewParsers();

        $parseInt   = new \ReflectionMethod(Importer::class, 'parseInt');
        $parseCount = new \ReflectionMethod(Importer::class, 'parseCount');
        $importer   = app(Importer::class);

        $this->assertGreaterThanOrEqual(40, count(self::COUNT_INPUTS), '件数の入力が減っている（突き合わせが空振りしていないか）');
        $this->assertCount(count(self::COUNT_INPUTS), $js['counts']);

        foreach (self::COUNT_INPUTS as $i => $raw) {
            $int   = $parseInt->invoke($importer, $raw);
            $count = $parseCount->invoke($importer, $raw);
            $php   = match (true) {
                $int === null  => ['state' => 'blank', 'value' => null],
                $int === false => ['state' => 'bad', 'value' => null],
                $count === null => ['state' => 'range', 'value' => null],
                default        => ['state' => 'ok', 'value' => $count],
            };

            $this->assertSame($php, $js['counts'][$i], '件数 ' . json_encode($raw, JSON_UNESCAPED_UNICODE) . ' の読み方がプレビューとサーバで割れている');
        }
    }

    public function test_the_preview_reads_floors_like_the_server(): void
    {
        $js = $this->runPreviewParsers();

        $inputs = $this->floorInputs();
        $this->assertGreaterThanOrEqual(40, count($inputs), '階の入力が減っている（突き合わせが空振りしていないか）');
        $this->assertCount(count($inputs), $js['floors']);

        foreach ($inputs as $i => $raw) {
            foreach (['tenant' => true, 'total' => false] as $kind => $allowBasement) {
                $parsed = FloorNumber::parse($raw, $allowBasement);
                $php    = match (true) {
                    $parsed === null  => ['state' => 'blank', 'value' => null],
                    $parsed === false => ['state' => 'bad', 'value' => null],
                    default           => ['state' => 'ok', 'value' => $parsed],
                };

                $this->assertSame(
                    $php,
                    $js['floors'][$i][$kind],
                    ($kind === 'tenant' ? 'テナントの階 ' : '総階数 ') . json_encode($raw, JSON_UNESCAPED_UNICODE) . ' の読み方がプレビューとサーバで割れている'
                );
            }
        }
    }

    /** @return list<string> FloorNumberTest の表の文字の入力（配列・数は画面から届かないので除く）＋ EXTRA_FLOOR_INPUTS */
    private function floorInputs(): array
    {
        $fromTable = array_values(array_filter(
            array_map(fn (array $case) => $case[0], FloorNumberTest::tenantFloors()),
            'is_string'
        ));

        return array_values(array_unique(array_merge($fromTable, self::EXTRA_FLOOR_INPUTS)));
    }

    /**
     * 取込の画面が描いた script を node で読み込み、件数と階の入力をプレビューの読み方に通した結果を返す。
     *
     * @return array{counts: list<array{state: string, value: int|null}>, floors: list<array{tenant: array, total: array}>}
     */
    private function runPreviewParsers(): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので取込の画面の JavaScript を動かせない');
        }

        $html = $this->actingAs($this->manager())->get(self::IMPORT_URL)->assertOk()->getContent();
        preg_match_all('/<script>(.*?)<\/script>/su', $html, $scripts);
        $script = collect($scripts[1])->first(fn (string $s) => str_contains($s, 'function areaImportParseFloor('));
        $this->assertNotNull($script, '取込の画面に、階を読む script（areaImportParseFloor）が描かれていない');

        $harness = <<<'JS'
            const fs = require('fs');
            const vm = require('vm');
            const input = JSON.parse(fs.readFileSync(process.argv[1], 'utf8'));
            const context = vm.createContext({});
            vm.runInContext(input.script, context);
            const plain = (r) => ({ state: r.state, value: r.value });
            context.__counts = input.counts;
            context.__floors = input.floors;
            const counts = vm.runInContext('__counts.map(function (raw) { return areaImportParseCount(raw); })', context).map(plain);
            const floors = vm.runInContext(
                '__floors.map(function (raw) { return { tenant: areaImportParseFloor(raw, true), total: areaImportParseFloor(raw, false) }; })',
                context
            ).map((f) => ({ tenant: plain(f.tenant), total: plain(f.total) }));
            process.stdout.write(JSON.stringify({ counts, floors }));
            JS;

        $file = tempnam(sys_get_temp_dir(), 'area-import-parity-');
        try {
            file_put_contents($file, json_encode([
                'script' => $script,
                'counts' => self::COUNT_INPUTS,
                'floors' => $this->floorInputs(),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $output = shell_exec(sprintf('%s -e %s %s 2>&1', escapeshellarg($node), escapeshellarg($harness), escapeshellarg($file)));
        } finally {
            unlink($file);
        }

        $result = json_decode((string) $output, true);
        $this->assertIsArray($result, "node で取込の画面の JavaScript を動かせなかった:\n" . $output);

        return $result;
    }
}
