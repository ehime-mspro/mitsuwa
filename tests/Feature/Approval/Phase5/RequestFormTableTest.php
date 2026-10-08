<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Support\Approval\AmountTable;
use App\Support\Approval\BodyTemplate;
use App\Support\Approval\FormInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ComparesJsonColumns;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;
use Tests\Unit\Approval\AmountTableTest;

/**
 * ② の画面の明細表・件名の組み立て・追加の欄（要件 5.5.3〜5.5.6・段階5 設計書 §5.5・D16）。
 *
 * ⚠ 行は Alpine が描く（<template x-for>）ので、HTML の往復では送られない。画面に渡す値（typeConfigs・tableRows）と、
 *   JS の計算・並べ直し・件名の組み立てを node で動かして固定する（顧客の画面の RunsBuyerFormScript と同じ考え。node が無ければ飛ばす）。
 */
class RequestFormTableTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ComparesJsonColumns;
    use ParsesForms;

    private function row(?string $name, bool $fixed, ?int $sale, ?int $cost): array
    {
        return ['name' => $name, 'fixed' => $fixed, 'sale' => $sale, 'cost' => $cost];
    }

    /** script の中の Js::from の値（JSON.parse('…') の中身）を、名前のすぐ後ろから読む */
    private function jsValue(string $html, string $prefix): mixed
    {
        $at = strpos($html, $prefix . 'JSON.parse(\'');
        $this->assertNotFalse($at, "{$prefix} の値が画面に無い");
        $start = $at + strlen($prefix . 'JSON.parse(\'');
        $end   = strpos($html, '\')', $start);

        return json_decode(json_decode('"' . substr($html, $start, $end - $start) . '"'), true);
    }

    /**
     * 描いた画面の approvalRequestForm() を node で動かす（node が無ければ飛ばす）。$steps は、`form()`（開いたばかりの画面の data）・
     * `rowsOf(data)`（明細表の行。x-for の鍵 key も含む）・`input` を使い、結果を `out` に入れる JS
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function runForm(string $html, string $steps, array $input): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので申請書の JavaScript を動かせない');
        }
        $this->assertSame(1, preg_match('/<script>\s*(function approvalRequestForm\(\) \{.*?)<\/script>/su', $html, $m), 'function approvalRequestForm() の script が描かれていない');

        $harness = <<<'JS'
            const fs = require('fs');
            const vm = require('vm');
            const input = JSON.parse(fs.readFileSync(process.argv[1], 'utf8'));
            const context = vm.createContext({});
            vm.runInContext(input.script, context);
            const form = () => {
                const data = vm.runInContext('approvalRequestForm()', context);
                data.$refs = { body: { value: '' }, typeSelect: { value: '' }, extra_tsubo: { value: '' }, extra_tsubo_price: { value: '' }, extra_staff: { value: '' }, extra_contract_date: { value: '' } };
                return data;
            };
            const plain = (row) => ({ key: row.key, fixed: row.fixed, name: row.name, sale: row.sale, cost: row.cost });
            const rowsOf = (data) => ({ upper: data.rows.upper.map(plain), lower: data.rows.lower.map(plain) });
            const out = {};
            JS;
        $file = tempnam(sys_get_temp_dir(), 'approval-form-');
        try {
            file_put_contents($file, json_encode(['script' => $m[1]] + $input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $script = $harness . "\n" . $steps . "\nprocess.stdout.write(JSON.stringify(out));";
            $output = shell_exec(sprintf('%s -e %s %s 2>&1', escapeshellarg($node), escapeshellarg($script), escapeshellarg($file)));
        } finally {
            unlink($file);
        }
        $js = json_decode((string) $output, true);
        $this->assertIsArray($js, "node で申請書の JavaScript を動かせなかった:\n" . $output);

        return $js;
    }

    /** node が返した明細表の行から、x-for の鍵（key）を除く */
    private function withoutKeys(array $rows): array
    {
        return array_map(fn (array $section) => array_map(fn (array $row) => array_diff_key($row, ['key' => true]), $section), $rows);
    }

    public function test_the_page_passes_the_type_settings_and_the_rows(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type = $this->housingContractType($w);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.create'))->assertOk()->getContent();

        $this->assertSameIgnoringKeyOrder([
            'form' => 'table', 'layout' => ['subtotal' => true, 'upper' => ['工事請負金額', null, '紹介料'], 'lower' => ['土地契約金額', null]],
            'suffix' => '様請負新築工事契約の件', 'uses' => ['tsubo', 'tsubo_price', 'staff', 'contract_date'], 'fixedText' => '上記の内容に基づき、販売をおこないます。',
        ], $this->jsValue($html, 'types: ')[$type->id]);
        $this->assertSame(['form' => 'points', 'layout' => ['subtotal' => false, 'upper' => [], 'lower' => []], 'suffix' => '', 'uses' => [], 'fixedText' => ''], $this->jsValue($html, 'types: ')[$w['type']->id]);
        $this->assertSame(['upper' => [], 'lower' => []], $this->jsValue($html, 'var tableRows = '), '種類を選ぶ前は行が無い（選んだときに JS が種類の行で作る）');

        // 明細表・追加の欄・定型文の部品と、明細表の種類では金額を押せなくする（送らない）こと
        foreach ([
            '<div x-show="isTable()" x-cloak class="space-y-2">',
            '<template x-for="(row, index) in rows.upper" :key="row.key">',
            '<template x-for="(row, index) in rows.lower" :key="row.key">',
            ":name=\"'amount_table[upper][' + index + '][fixed]'\" :value=\"row.fixed\"",
            ":name=\"'amount_table[lower][' + index + '][sale]'\" x-model=\"row.sale\"",
            'x-show="!isTable()" :disabled="isTable()"',
            'name="tsubo" inputmode="decimal" placeholder="例: 38.5" :disabled="!uses(\'tsubo\')"',
            'name="contract_date" :disabled="!uses(\'contract_date\')"',
            'name="staff" maxlength="50" :disabled="!uses(\'staff\')"',
            'x-text="fixedText()"',
            'id="subject" name="subject" value="" x-model="subjectText" maxlength="100"',
            'x-model="subjectPrefix" @input="composeSubject()"',
            // 5W2H の種類に選び直したら、明細表の欄は押せなくして送らない（点検の M-4）・選び直しで消えるものを知らせて戻せる（I-3）
            ':value="row.fixed" :disabled="!isTable()"',
            '@blur="formatAmount(row, \'sale\')" :disabled="!isTable()"',
            'x-ref="typeSelect"',
            'x-ref="extra_staff"',
            '@click="restoreType()"',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        // スマホでは 1 行 1 枚のカード（表の要素を block に。横スクロールにしない。要件 5.5.6）
        $this->assertStringContainsString('<table class="block md:table w-full border-collapse text-[13px]">', $html);
        $this->assertStringContainsString('<thead class="hidden md:table-header-group">', $html);
    }

    public function test_the_edit_page_shows_the_saved_rows_in_the_layout_of_today(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type    = $this->housingContractType($w);
        $request = $this->draftFor($w, ['type_id' => $type->id, 'subject' => '山田様請負新築工事契約の件', 'staff' => '佐藤', 'tsubo' => '38.5', 'tsubo_price' => 1083000, 'contract_date' => '2026-10-20', 'amount_table' => [
            'subtotal' => true,
            'upper'    => [$this->row('工事請負金額', true, 28500000, 22000000), $this->row('外構', false, -300000, null), $this->row('紹介料', true, null, 300000)],
            'lower'    => [$this->row('土地契約金額', true, 12000000, null)],
        ]]);
        // 管理者が「紹介料」を「紹介手数料」に変えた（金額の入った古い行は自由行として残す。D16）
        $type->update(['table_layout' => ['subtotal' => true, 'upper' => ['工事請負金額', null, '紹介手数料'], 'lower' => ['土地契約金額', null]]]);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $request))->assertOk()->getContent();

        $this->assertSame([
            'upper' => [
                ['fixed' => '工事請負金額', 'name' => '', 'sale' => '28,500,000', 'cost' => '22,000,000'],
                ['fixed' => null, 'name' => '外構', 'sale' => '-300,000', 'cost' => ''],
                ['fixed' => '紹介手数料', 'name' => '', 'sale' => '', 'cost' => ''],
                ['fixed' => null, 'name' => '紹介料', 'sale' => '', 'cost' => '300,000'],
            ],
            'lower' => [
                ['fixed' => '土地契約金額', 'name' => '', 'sale' => '12,000,000', 'cost' => ''],
                ['fixed' => null, 'name' => '', 'sale' => '', 'cost' => ''],
            ],
        ], $this->jsValue($html, 'var tableRows = '));

        // 追加の欄は保存した値で開く・件名は組み立てたまま送る
        $form = $this->parseForm($html, 'action="' . route('approvals.requests.update', $request) . '"');
        $this->assertSame(['38.50', '1,083,000', '佐藤', '2026-10-20', '山田様請負新築工事契約の件'], [
            $form['fields']['tsubo'], $form['fields']['tsubo_price'], $form['fields']['staff'], $form['fields']['contract_date'], $form['fields']['subject'],
        ]);
        $this->assertStringContainsString('subjectText: ' . Js::from('山田様請負新築工事契約の件')->toHtml() . ',', $html);
    }

    /** 断られて戻ったときは、打った値のまま出す（読めない値も消さない） */
    public function test_a_refused_save_shows_what_was_typed(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type    = $this->housingContractType($w);
        $request = $this->draftFor($w, ['type_id' => $type->id]);

        $this->actingAs($w['applicant'])->from(route('approvals.requests.edit', $request))->put(route('approvals.requests.update', $request), [
            'type_id' => (string) $type->id, 'department_id' => (string) $w['dept']->id, 'subject' => 'x', 'lock_version' => '0', 'intent' => 'save',
            'amount_table' => ['upper' => [['fixed' => '工事請負金額', 'sale' => '２千万', 'cost' => '1,000']], 'lower' => []],
        ])->assertRedirect(route('approvals.requests.edit', $request));

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $request))->assertOk()->getContent();
        $this->assertSame(['upper' => [['fixed' => '工事請負金額', 'name' => '', 'sale' => '2千万', 'cost' => '1000']], 'lower' => []], $this->jsValue($html, 'var tableRows = '));
        $this->assertStringContainsString(e('明細表の販売金額「2千万」は数で入力してください（マイナスも入れられます）。'), $html);
    }

    /** 画面の JS の計算・並べ直し・件名の組み立てが、サーバーの AmountTable と同じ答えを出す */
    public function test_the_script_calculates_like_the_server(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので申請書の JavaScript を動かせない');
        }

        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type  = $this->housingContractType($w);
        $plain = $this->approvalType($w['reviewDept'], ['name' => '購入']);
        $html  = $this->actingAs($w['applicant'])->get(route('approvals.requests.create'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<script>\s*(function approvalRequestForm\(\) \{.*?)<\/script>/su', $html, $m), 'function approvalRequestForm() の script が描かれていない');

        $rows = [
            'upper' => [
                ['fixed' => '工事請負金額', 'name' => '', 'sale' => '28,500,000', 'cost' => '２２，０００，０００'],
                ['fixed' => null, 'name' => '値引き', 'sale' => '−300,000', 'cost' => ''],
                ['fixed' => '紹介料', 'name' => '', 'sale' => '0', 'cost' => '300,000円'],
            ],
            'lower' => [['fixed' => '土地契約金額', 'name' => '', 'sale' => '12,000,000', 'cost' => '10,500,000'], ['fixed' => null, 'name' => '', 'sale' => '', 'cost' => '']],
        ];
        $harness = <<<'JS'
            const fs = require('fs');
            const vm = require('vm');
            const input = JSON.parse(fs.readFileSync(process.argv[1], 'utf8'));
            const context = vm.createContext({});
            vm.runInContext(input.script, context);
            const data = vm.runInContext('approvalRequestForm()', context);
            const key = (rows) => rows.map((row, i) => Object.assign({ key: 1000 + i }, row));
            data.$refs = { body: { value: input.body }, typeSelect: { value: '' }, extra_staff: { value: '佐藤' }, extra_tsubo: { value: '' } };
            data.typeChanged(String(input.tableType));
            data.rows = { upper: key(input.rows.upper), lower: key(input.rows.lower) };
            const out = {
                isTable: data.isTable(),
                total: data.total(),
                subtotal: data.subtotal(),
                rowLines: data.rows.upper.map((row) => data.rowLine(row)),
                labels: [data.rateLabel(data.total().rate), data.rateLabel(null), data.yen(-300000)],
                parse: input.parses.map((v) => data.parseAmount(v)),
                rateLabels: input.rates.map((pair) => data.rateLabel(data.rate(pair[0], pair[1]))),
                merged: data.mergeRows(input.layout, { upper: key(input.merge.upper), lower: key(input.merge.lower) }),
                mergedCase: data.mergeRows(input.caseLayout, { upper: key(input.caseRows.upper), lower: key(input.caseRows.lower) }),
                mergedAbsorb: data.mergeRows(input.absorbLayout, { upper: key(input.absorbRows.upper), lower: key(input.absorbRows.lower) }),
                bodyAfterTable: data.$refs.body.value,
            };
            // 件名: 決まり文句のある種類で前半を入れる → 決まり文句の無い種類に選び直すと前半だけ残る
            data.subjectPrefix = '山田';
            data.composeSubject();
            out.composed = data.subjectText;
            data.subjectPrefix = '　';
            data.composeSubject();
            out.composedEmpty = data.subjectText;
            data.subjectPrefix = '山田';
            data.composeSubject();
            data.typeChanged(String(input.plainType));
            out.subjectAfterPlain = [data.subjectMode, data.subjectText];
            out.bodyAfterPlain = data.$refs.body.value;
            out.leaving = data.leaving;
            data.restoreType();
            out.afterRestore = { typeId: data.typeId, select: data.$refs.typeSelect.value, leaving: data.leaving, sale: data.total().sale, isTable: data.isTable(), subject: [data.subjectMode, data.subjectText] };
            process.stdout.write(JSON.stringify(out));
            JS;
        $merge = [
            'upper' => [['fixed' => '工事請負金額', 'name' => '', 'sale' => '1', 'cost' => ''], ['fixed' => '旧い名前', 'name' => '', 'sale' => '', 'cost' => '5'], ['fixed' => null, 'name' => '足した行', 'sale' => '', 'cost' => '']],
            'lower' => [['fixed' => '消えた名前', 'name' => '', 'sale' => '', 'cost' => '']],
        ];
        $layout = ['subtotal' => false, 'upper' => ['工事請負金額', '新しい名前', null], 'lower' => []];

        // 並べ直しの境目（AmountTable::forForm と同じ入力を JS にも渡して比べる。点検の R-5）
        $caseLayout = ['subtotal' => false, 'upper' => ['工事請負金額', null, '紹介料'], 'lower' => [null]];
        $caseTable  = ['subtotal' => false, 'upper' => [
            $this->row('工事請負金額', true, 1, null),
            $this->row('工事請負金額', true, 2, null),    // 同じ名前の 2 つ目（金額があるので自由行で残る）
            $this->row('消えた名前', true, null, null),   // 設定から消えた名前で金額が無い（残さない）
            $this->row('紹介料', true, 0, null),          // 0 円も金額
            $this->row(null, false, null, null),          // 空の自由行
            $this->row('足した行', false, null, 5),
            $this->row(null, false, null, null),          // 設定の自由行より多い空の自由行
        ], 'lower' => [$this->row('土地', false, 3, null), $this->row(null, false, null, null)]];
        // 自由行の名前が種類の名前と同じ（名前を設定した行が無ければ、最初の 1 つを名前を設定した行にする。Task 6 の点検の I-1）
        $absorbTable = ['subtotal' => false, 'upper' => [
            $this->row('外構', false, 1, null),
            $this->row('工事請負金額', false, 5, null),   // 同じ名前の名前を設定した行が無い（取り込む）
            $this->row('工事請負金額', false, 6, null),   // 2 つ目は自由行のまま
            $this->row('紹介料', true, 7, null),
            $this->row('紹介料', false, 8, null),         // 同じ名前の名前を設定した行がある（自由行のまま）
        ], 'lower' => [$this->row('　土地 ', false, 3, 4)]];   // 前後の空白を落として比べる
        $absorbLayout = ['subtotal' => false, 'upper' => ['工事請負金額', null, '紹介料'], 'lower' => ['土地', null]];
        $toJs = fn (array $rows): array => array_map(fn (array $r): array => [
            'fixed' => $r['fixed'] ? $r['name'] : null, 'name' => $r['fixed'] ? '' : (string) $r['name'],
            'sale'  => $r['sale'] === null ? '' : (string) $r['sale'], 'cost' => $r['cost'] === null ? '' : (string) $r['cost'],
        ], $rows);

        // 金額の読み方（FormInput::digits と同じ数。点検の M-2）
        $parses = ['1,234', '－５', 'abc', '', '12.5', '２２，０００，０００', '300,000円', '¥1,000', '￥1,000', '−300,000', '+1000', '＋1000', '0100', '000', '-0', "1\t000 ", '　 ', '1e3', '--5', '1234567890123456'];

        // 粗利率（境目と、販売金額 1〜200 円 × 粗利益 −50〜200 円のすべての組。点検の I-1）
        $rates = array_map(fn (array $edge) => [$edge[0], $edge[1]], array_values(AmountTableTest::rateEdges()));
        for ($sale = 1; $sale <= 200; $sale++) {
            for ($profit = -50; $profit <= 200; $profit++) {
                $rates[] = [$sale, $profit];
            }
        }

        $file = tempnam(sys_get_temp_dir(), 'approval-form-');
        try {
            file_put_contents($file, json_encode([
                'script' => $m[1], 'tableType' => $type->id, 'plainType' => $plain->id, 'rows' => $rows, 'merge' => $merge, 'layout' => $layout,
                'caseLayout' => $caseLayout, 'caseRows' => ['upper' => $toJs($caseTable['upper']), 'lower' => $toJs($caseTable['lower'])],
                'absorbLayout' => $absorbLayout, 'absorbRows' => ['upper' => $toJs($absorbTable['upper']), 'lower' => $toJs($absorbTable['lower'])],
                'parses' => $parses, 'rates' => $rates,
                'body' => BodyTemplate::DEFAULT,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $output = shell_exec(sprintf('%s -e %s %s 2>&1', escapeshellarg($node), escapeshellarg($harness), escapeshellarg($file)));
        } finally {
            unlink($file);
        }
        $js = json_decode((string) $output, true);
        $this->assertIsArray($js, "node で申請書の JavaScript を動かせなかった:\n" . $output);

        // サーバーの計算（同じ行）と同じ
        $server = AmountTable::totals(AmountTable::fromInput($type->table_layout, AmountTable::cleanInput($rows)));
        $this->assertTrue($js['isTable']);
        $this->assertEquals($server['total'], $js['total']);
        $this->assertEquals($server['subtotal'], $js['subtotal']);
        $this->assertEquals([['sale' => 28500000, 'cost' => 22000000, 'profit' => 6500000, 'rate' => 22.8], ['sale' => -300000, 'cost' => 0, 'profit' => -300000, 'rate' => 100], ['sale' => 0, 'cost' => 300000, 'profit' => -300000, 'rate' => null]], $js['rowLines']);
        $this->assertSame([AmountTable::rateLabel($server['total']['rate']), '—', '-300,000円'], $js['labels']);
        $this->assertSame(array_map(function (string $value): ?int {
            $digits = FormInput::digits($value, FormInput::YEN);

            return is_string($digits) && preg_match('/^-?\d{1,15}$/', $digits) === 1 ? (int) $digits : null;
        }, $parses), $js['parse']);

        $mismatch = [];
        foreach ($rates as $i => [$sale, $profit]) {
            $label = AmountTable::rateLabel(AmountTable::rate($sale, $profit));
            if ($label !== $js['rateLabels'][$i]) {
                $mismatch[] = "{$sale} 円・粗利益 {$profit} 円: サーバー {$label}・画面 {$js['rateLabels'][$i]}";
            }
        }
        $this->assertSame([], $mismatch, '粗利率が画面とサーバーで違う');

        $expected = AmountTable::forForm($caseLayout, $caseTable);
        $this->assertSame(
            ['upper' => $toJs($expected['upper']), 'lower' => $toJs($expected['lower'])],
            array_map(fn (array $rows) => array_map(fn (array $row) => array_diff_key($row, ['key' => true]), $rows), $js['mergedCase'])
        );
        $absorbed = array_map(fn (array $rows) => array_map(fn (array $row) => array_diff_key($row, ['key' => true]), $rows), $js['mergedAbsorb']);
        $this->assertSame([
            'upper' => [
                ['fixed' => '工事請負金額', 'name' => '', 'sale' => '5', 'cost' => ''],
                ['fixed' => null, 'name' => '外構', 'sale' => '1', 'cost' => ''],
                ['fixed' => '紹介料', 'name' => '', 'sale' => '7', 'cost' => ''],
                ['fixed' => null, 'name' => '工事請負金額', 'sale' => '6', 'cost' => ''],
                ['fixed' => null, 'name' => '紹介料', 'sale' => '8', 'cost' => ''],
            ],
            'lower' => [['fixed' => '土地', 'name' => '', 'sale' => '3', 'cost' => '4'], ['fixed' => null, 'name' => '', 'sale' => '', 'cost' => '']],
        ], $absorbed);
        $expected = AmountTable::forForm($absorbLayout, $absorbTable);
        $this->assertSame(['upper' => $toJs($expected['upper']), 'lower' => $toJs($expected['lower'])], $absorbed, '自由行の名前が種類の名前と同じとき、画面とサーバーの並べ直しが同じ');

        // 並べ直し（AmountTable::forForm と同じ規則。D16）
        $this->assertSame([
            ['fixed' => '工事請負金額', 'name' => '', 'sale' => '1', 'cost' => ''],
            ['fixed' => '新しい名前', 'name' => '', 'sale' => '', 'cost' => ''],
            ['fixed' => null, 'name' => '旧い名前', 'sale' => '', 'cost' => '5'],   // 自由行の位置に、申請の並びの順で当てる
            ['fixed' => null, 'name' => '足した行', 'sale' => '', 'cost' => ''],
        ], array_map(fn (array $row) => array_diff_key($row, ['key' => true]), $js['merged']['upper']));
        $this->assertSame([], $js['merged']['lower'], '名前が設定から消えた行は、金額が無ければ残さない');

        // 明細表の種類を選ぶと見出しのままの本文は空になり、5W2H の種類に戻すと見出しが入る・件名は打った前半だけが残る
        $this->assertSame('', $js['bodyAfterTable']);
        $this->assertSame('山田様請負新築工事契約の件', $js['composed']);
        $this->assertSame('', $js['composedEmpty'], '前半が空なら決まり文句だけの件名を作らない（点検の I-4）');
        $this->assertSame(['direct', '山田'], $js['subjectAfterPlain']);
        $this->assertSame(BodyTemplate::DEFAULT, $js['bodyAfterPlain']);

        // 明細表の種類から 5W2H の種類に選び直すと、保存で消えるもの（明細表・新しい種類が使わない担当者）を知らせ、元の種類に戻せる
        // （戻すと明細表は入れたまま。段階5 D16。点検の I-3）
        $this->assertSame(['from' => (string) $type->id, 'labels' => ['明細表', '担当者'], 'subjectMode' => 'split'], $js['leaving']);
        $this->assertSame([
            'typeId' => (string) $type->id, 'select' => (string) $type->id, 'leaving' => null, 'sale' => $server['total']['sale'], 'isTable' => true,
            'subject' => ['split', '山田様請負新築工事契約の件'],   // 件名も前半と決まり文句の組み立てに戻る
        ], $js['afterRestore']);
    }

    /**
     * 明細表の種類どうしで選び直しても、名前を設定した行が「空の名前の行＋金額の入った同じ名前の自由行」に分かれない（D16・計画の
     * Review Focus 3「書いたまま戻れる」）。「元の種類に戻す」は選び直す前の行をそのまま戻し、手で元の種類を選び直したときは
     * 同じ名前の自由行を名前を設定した行に取り込む（Task 6 の点検の I-1）
     */
    public function test_choosing_a_table_type_again_does_not_double_the_named_rows(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type  = $this->housingContractType($w);
        // 追加・少額工事契約（前半: 工事請負金額・自由行 5 つ／後半なし／坪数・坪単価を使わない。要件 5.5.7 の 2 つ目）
        $small = $this->housingContractType($w, [
            'name'           => '住宅の契約用（追加・少額工事契約）',
            'table_layout'   => ['subtotal' => false, 'upper' => ['工事請負金額', null, null, null, null, null], 'lower' => []],
            'subject_suffix' => '様追加・少額工事契約の件', 'uses_tsubo' => false, 'uses_tsubo_price' => false,
        ]);
        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.create'))->assertOk()->getContent();

        $js = $this->runForm($html, <<<'JS'
            // 9/17 に利用者が見た見本の数を入れる
            const fill = (data) => {
                data.typeChanged(String(input.type));
                const upper = data.rows.upper;
                upper[0].sale = '28,500,000'; upper[0].cost = '22,000,000';
                upper[1].name = 'オプション工事'; upper[1].sale = '1,200,000'; upper[1].cost = '850,000';
                upper[2].sale = '0'; upper[2].cost = '300,000';
                data.rows.lower[0].sale = '12,000,000'; data.rows.lower[0].cost = '10,500,000';
            };

            // 坪数を入れてから、坪数を使わない明細表の種類に選び直す（知らせが出る）→「元の種類に戻す」
            let data = form();
            fill(data);
            data.$refs.extra_tsubo.value = '38.5';
            out.before = rowsOf(data);
            out.total = data.total();
            data.typeChanged(String(input.small));
            out.leaving = data.leaving === null ? null : data.leaving.labels;
            data.rows.upper[0].sale = '1';   // 選び直した種類で直した数は、戻すと選び直す前の数になる
            data.restoreType();
            out.restored = rowsOf(data);
            out.restoredType = [data.typeId, data.leaving];

            // 知らせの出ない選び直し（追加の欄に値が無い）で、手で行き来する
            data = form();
            fill(data);
            data.typeChanged(String(input.small));
            out.leavingSmall = data.leaving;
            out.small = rowsOf(data);
            data.typeChanged(String(input.type));
            out.back = rowsOf(data);
            out.backTotal = data.total();
            JS, ['type' => $type->id, 'small' => $small->id]);

        $empty = ['fixed' => null, 'name' => '', 'sale' => '', 'cost' => ''];

        // 「元の種類に戻す」: 選び直す前の行がそのまま戻る（並べ直して作り直さない）
        $this->assertSame(['坪数'], $js['leaving']);
        $this->assertSame($js['before'], $js['restored']);
        $this->assertSame([(string) $type->id, null], $js['restoredType']);

        // 手で行き来: 選び直した種類に無い名前の行は自由行になり、元の種類を選ぶと名前を設定した行に戻る（二重にならない・合計は同じ）
        $this->assertNull($js['leavingSmall']);
        $this->assertSame([
            'upper' => [
                ['fixed' => '工事請負金額', 'name' => '', 'sale' => '28,500,000', 'cost' => '22,000,000'],
                ['fixed' => null, 'name' => 'オプション工事', 'sale' => '1,200,000', 'cost' => '850,000'],
                ['fixed' => null, 'name' => '紹介料', 'sale' => '0', 'cost' => '300,000'],
                $empty, $empty, $empty,
            ],
            'lower' => [['fixed' => null, 'name' => '土地契約金額', 'sale' => '12,000,000', 'cost' => '10,500,000'], $empty],
        ], $this->withoutKeys($js['small']));
        $this->assertSame([
            'upper' => [
                ['fixed' => '工事請負金額', 'name' => '', 'sale' => '28,500,000', 'cost' => '22,000,000'],
                ['fixed' => null, 'name' => 'オプション工事', 'sale' => '1,200,000', 'cost' => '850,000'],
                ['fixed' => '紹介料', 'name' => '', 'sale' => '0', 'cost' => '300,000'],
                $empty, $empty, $empty,   // 選び直した種類の自由行の位置の空の行は残る（空の自由行は消さない規則のまま）
            ],
            'lower' => [['fixed' => '土地契約金額', 'name' => '', 'sale' => '12,000,000', 'cost' => '10,500,000'], $empty],
        ], $this->withoutKeys($js['back']));
        $this->assertSame($js['total'], $js['backTotal']);
    }

    /**
     * コピーして作成で種類が空になった（元の種類が自分の部門で使えない）申請は、開いたときに行がすべて自由行になっている。そこで
     * 同じ名前の行を持つ種類を選ぶと、名前の合う行は名前を設定した行に並ぶ（設計書 §5.6・D16。Task 6 の点検の I-1）
     */
    public function test_a_copy_without_its_type_lines_up_the_rows_when_a_type_is_chosen(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type   = $this->housingContractType($w);
        $source = $this->draftFor($w, ['type_id' => $type->id, 'amount_table' => [
            'subtotal' => true,
            'upper'    => [$this->row('工事請負金額', true, 28500000, 22000000), $this->row('外構', false, -300000, null), $this->row('紹介料', true, null, 300000)],
            'lower'    => [$this->row('土地契約金額', true, 12000000, null)],
        ]]);
        // 元の種類が自分の部門で使えなくなり、同じ行の種類を作り直した
        $type->departments()->attach($this->approvalDepartment($w['company'], ['name' => 'ミツワ不動産'])->id);
        $remade = $this->housingContractType($w, ['name' => '住宅の契約用（作り直した種類）']);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.create', ['copy' => $source->id]))->assertOk()->getContent();
        $this->assertSame([null, null, null], array_column($this->jsValue($html, 'var tableRows = ')['upper'], 'fixed'), '種類が空なので、開いたときは行がすべて自由行');

        $js = $this->runForm($html, <<<'JS'
            const data = form();
            data.typeChanged(String(input.type));
            out.rows = rowsOf(data);
            JS, ['type' => $remade->id]);

        $this->assertSame([
            'upper' => [
                ['fixed' => '工事請負金額', 'name' => '', 'sale' => '28,500,000', 'cost' => '22,000,000'],
                ['fixed' => null, 'name' => '外構', 'sale' => '-300,000', 'cost' => ''],
                ['fixed' => '紹介料', 'name' => '', 'sale' => '', 'cost' => '300,000'],
            ],
            'lower' => [['fixed' => '土地契約金額', 'name' => '', 'sale' => '12,000,000', 'cost' => ''], ['fixed' => null, 'name' => '', 'sale' => '', 'cost' => '']],
        ], $this->withoutKeys($js['rows']));
    }
}
