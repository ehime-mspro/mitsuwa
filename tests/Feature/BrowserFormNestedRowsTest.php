<?php

namespace Tests\Feature;

use PHPUnit\Framework\AssertionFailedError;
use Tests\Concerns\DrivesAlpineFetch;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * DrivesAlpineFetch::browserForm() が「行の中の行」（入れ子の `<template x-for>`）の入力を送れること。
 *
 * アンケート設問の管理の「選択肢の行の中の付随の欄」のように、行ごとに内側の行を持つフォームのため。
 * HTML は文字列で組む（Laravel のルートは要らない）。内側の x-for の式（`row.subs`）は、外の行の変数で評価される。
 *
 * ⚠ `x-for` でない `<template>`（`x-if` など）の中の入力は、今までどおり「扱えない」で止める
 *   （ブラウザでは出し入れが条件しだいで、行の型として数えられない）。
 */
class BrowserFormNestedRowsTest extends TestCase
{
    use DrivesAlpineFetch;
    use ParsesForms;

    private const NEEDLE = 'action="/nested"';

    private function nestedHtml(): string
    {
        return <<<'HTML'
            <div x-data="nestedForm()">
            <form method="POST" action="/nested">
                <template x-for="(row, i) in rows">
                    <div>
                        <input :name="'rows[' + i + '][name]'" x-model="row.name">
                        <template x-for="(sub, j) in row.subs">
                            <input :name="'rows[' + i + '][subs][' + j + '][label]'" x-model="sub.label" :disabled="sub.label === 'off'">
                        </template>
                    </div>
                </template>
            </form>
            </div>
            <script>function nestedForm() { return { rows: [{ name: 'A', subs: [{ label: 'a1' }, { label: 'a2' }] }, { name: 'B', subs: [] }] }; }</script>
            HTML;
    }

    public function test_inputs_in_rows_inside_rows_are_sent(): void
    {
        $form = $this->browserForm($this->nestedHtml(), self::NEEDLE, 'nestedForm');

        $this->assertSame(
            [['name' => 'A', 'subs' => [['label' => 'a1'], ['label' => 'a2']]], ['name' => 'B']],
            $form['fields']['rows'],
            '行の中の行の入力が、外の行の添字つきで送られていない'
        );
    }

    public function test_inner_rows_follow_the_state_after_the_steps(): void
    {
        $form = $this->browserForm(
            $this->nestedHtml(),
            self::NEEDLE,
            'nestedForm',
            "data.rows[1].subs.push({ label: 'b1' }); data.rows[0].subs.splice(0, 1);"
        );

        // 内側の行は、外の行ごとに $steps のあとの状態で数え直される（行を足した行・減らした行の両方）
        $this->assertSame([['label' => 'a2']], $form['fields']['rows'][0]['subs']);
        $this->assertSame([['label' => 'b1']], $form['fields']['rows'][1]['subs']);
    }

    public function test_disabled_inner_inputs_are_not_sent(): void
    {
        $form = $this->browserForm($this->nestedHtml(), self::NEEDLE, 'nestedForm', "data.rows[0].subs[1].label = 'off';");

        $this->assertSame([['label' => 'a1']], $form['fields']['rows'][0]['subs'], ':disabled が真の内側の入力が送られている');
    }

    public function test_inputs_in_a_template_without_x_for_still_stop(): void
    {
        $html = '<form method="POST" action="/nested"><template x-if="true"><input name="x"></template></form>';

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('x-for でない <template> の中の入力は扱えない');

        $this->browserForm($html, self::NEEDLE, null, '', [], '{}');
    }
}
