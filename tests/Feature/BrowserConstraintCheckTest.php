<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ChecksBrowserConstraints;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * ブラウザの入力欄の制約を確かめる道具 ChecksBrowserConstraints の分かれ目ごとの見本。
 * 見本の無い枝は、消しても緑のまま通る（Bug #61 ③）ので、1 つずつ置く。
 */
class BrowserConstraintCheckTest extends TestCase
{
    use ChecksBrowserConstraints, ParsesForms;

    /** @return array<string, array{string, array<string, mixed>, list<string>}> */
    public static function samples(): array
    {
        $rows = [
            ['<input type="number" name="rent" min="0" step="1000">', ['rent' => '65300'], ['rent: 「65300」は step=1000 に乗らない（送信が止まる）']],
            ['<input type="number" name="rent" min="0">', ['rent' => '65300'], []],
            ['<input type="number" name="rent" min="0">', ['rent' => '65300.5'], ['rent: 「65300.5」は step=1 に乗らない（送信が止まる）']],
            ['<input type="number" name="v" step="any">', ['v' => '0.25'], []],
            ['<input type="number" name="v" value="7" step="5">', ['v' => '7'], []],
            ['<input type="number" name="floor" min="1" max="50">', ['floor' => '0'], ['floor: 「0」は min=1 より小さい（送信が止まる）']],
            ['<input type="number" name="floor" min="1" max="50">', ['floor' => '51'], ['floor: 「51」は max=50 より大きい（送信が止まる）']],
            ['<input type="number" name="floor" min="1" max="50">', ['floor' => '50'], []],
            ['<input type="number" name="n">', ['n' => 'abc'], ['n: 「abc」は数の形でない（空で送られる）']],
            ['<input type="email" name="email">', ['email' => 'yamada@'], ['email: 「yamada@」はメールアドレスの形でない（送信が止まる）']],
            ['<input type="email" name="email">', ['email' => 'a@b.jp'], []],
            ['<input type="text" inputmode="email" name="email">', ['email' => 'yamada@'], []],
            ['<input type="month" name="built_date">', ['built_date' => '2010-4'], ['built_date: 「2010-4」は年月の形でない（空で送られる）']],
            ['<input type="month" name="built_date">', ['built_date' => '2010-04'], []],
            ['<input type="text" name="area" pattern="[0-9.]*">', ['area' => '35,5'], ['area: 「35,5」は pattern=[0-9.]* に合わない（送信が止まる）']],
            ['<input type="text" name="area" pattern="[0-9.]*">', ['area' => '35.5'], []],
            ['<input type="number" name="x[]" min="0" step="1000">', ['x' => ['15000', '2500']], ['x[1]: 「2500」は step=1000 に乗らない（送信が止まる）']],
            ['<input type="number" name="d" min="0" step="1000" disabled>', ['d' => '1'], []],
            ['<input type="number" name="g" min="0" step="1000" :disabled="skip">', [], []],
            ['<input type="number" name="g" min="0" step="1000" :disabled="skip">', ['g' => '2500'], ['g: 「2500」は step=1000 に乗らない（送信が止まる）']],
            ['<input type="hidden" name="h" min="1">', ['h' => '0'], []],
            ['<input type="number" name="s" data-step="3" min="0">', ['s' => '4'], []],
            ['<input type="text" name="r" required>', ['r' => ''], ['r: 空（required。送信が止まる）']],
            ['<input type="number" name="c" min="0" step="1000" class="disabled:opacity-60">', ['c' => '2500'], ['c: 「2500」は step=1000 に乗らない（送信が止まる）']],
            ['<input type="number" name="c" min="0" step="1000" title="disabled">', ['c' => '2500'], ['c: 「2500」は step=1000 に乗らない（送信が止まる）']],
            ['<input type="number" name="c" min="0" step="1000" @click="disabled = true">', ['c' => '2500'], ['c: 「2500」は step=1000 に乗らない（送信が止まる）']],
            ['<input type="text" name="r" title="required">', ['r' => ''], []],
            ['<input type="text" name="r" title="a > b" required>', ['r' => ''], ['r: 空（required。送信が止まる）']],
        ];

        $out = [];
        foreach ($rows as $i => $row) {
            $out['#' . $i . ' ' . $row[0]] = $row;
        }

        return $out;
    }

    #[DataProvider('samples')]
    public function test_violations(string $field, array $fields, array $expected): void
    {
        $html = '<form action="/x">' . $field . '</form>';

        $this->assertSame($expected, $this->browserConstraintViolations($html, 'action="/x"', $fields));
    }

    public function test_assert_browser_submits_lists_all_violations(): void
    {
        $html = '<form action="/x"><input type="number" name="a" min="1"><input type="number" name="b" min="1"></form>';

        $this->assertBrowserSubmits($html, 'action="/x"', ['a' => '1', 'b' => '2']);

        try {
            $this->assertBrowserSubmits($html, 'action="/x"', ['a' => '0', 'b' => '0']);
            $this->fail('違反があるのに通った');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('a: 「0」は min=1', $e->getMessage());
            $this->assertStringContainsString('b: 「0」は min=1', $e->getMessage());
        }
    }
}
