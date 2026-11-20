<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * 画面の数の入力欄の `step` を全件分類する（Top trap #13）。
 *
 * `step` は、取込や古い画面で入った刻みに乗らない保存済みの値の画面を、ほかの項目だけ直したいときも
 * ブラウザが送信を止める原因になる（賃貸マンションの金額の欄で起きた。2026-11-13）。新しく `step` を足すときは、
 * 刻みに乗らない値が保存されうるかを確かめて、ここに理由つきで載せる。
 *
 * 拾うもの: `step="…"`・`:step="…"`・`x-bind:step="…"`（Blade のコメント `{{-- --}}` を落としてから。`data-step` などは拾わない）。
 * 欄は、その `<input>` の `name`、無ければ `x-model`（修飾子つきを含む）の値。
 * 決裁の画面（`approvals/`）は別の会話の担当なので見ない。
 *
 * ⚠ 死角: 引用符なしの `step=5` は拾わない／`<input>` 以外のタグの `step`・`<script>` の中の文字列で組む入力欄・
 *   `:name` だけで名前を決める欄（name も x-model も無ければ「（欄なし）」として載せる）。
 */
class NumberInputStepScanTest extends TestCase
{
    /** @var array<string, array{0: int, 1: string}> 鍵 => [件数, 理由] */
    private const CLASSIFIED = [
        'tenant/contracts/revise.blade.php|depositMonths|0.1' => [1, '敷金の月数を打つ計算用の欄（name が無く送らない・初期値は空）'],
        'tenant/units/revise.blade.php|depositMonths|0.1' => [1, '同上'],
        'realestate/projects/_form.blade.php|building_coverage|10' => [1, '建蔽率は 10% 刻み。⚠ 刻みに乗らない保存済みの値は送信を止める（2026-11-13 の設計で範囲外にした）'],
        'realestate/projects/_form.blade.php|floor_area_ratio|10' => [1, '容積率。同上'],
        'realestate/procurements/_form.blade.php|building_coverage|10' => [1, '建蔽率。同上'],
        'realestate/procurements/_form.blade.php|floor_area_ratio|10' => [1, '容積率。同上'],
        'realestate/procurements/_lot_section.blade.php|area_sqm|0.01' => [2, '区画の面積（列は DECIMAL(10,2)。0.01 刻みで保存済みの値は必ず乗る。登録と編集の 2 か所）'],
        'buyers/_survey_form.blade.php|survey[{{ $qId }}]|any' => [1, '数値入力の設問（小数も可。範囲はサーバーが見る）'],
        'buyers/_survey_form.blade.php|survey[{{ $qId }}][sub][{{ $sf[\'key\'] }}]|any' => [1, '条件分岐の付随の数の欄'],
        'buyers/_survey_form.blade.php|sliderVal|{{ $slider[\'step\'] }}' => [1, 'スライダー（name を持たず、送るのは hidden。Bug #120）'],
    ];

    /**
     * @return list<array{0: string, 1: string}> [欄, step の値]（見つけた順）
     */
    private function scan(string $source): array
    {
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $found = [];

        preg_match_all('/<input\b(?:\{\{.*?\}\}|"[^"]*"|\'[^\']*\'|[^>"\'])*>/s', $source, $tags);
        foreach ($tags[0] as $tag) {
            if (! preg_match_all('/(?<=\s)(?::|x-bind:)?step=(?:"([^"]*)"|\'([^\']*)\')/', $tag, $steps, PREG_SET_ORDER)) {
                continue;
            }

            $field = '（欄なし）';
            if (preg_match('/(?<=\s)name=(?:"([^"]*)"|\'([^\']*)\')/', $tag, $m)) {
                $field = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            } elseif (preg_match('/(?<=\s)x-model(?:\.[\w.]+)*=(?:"([^"]*)"|\'([^\']*)\')/', $tag, $m)) {
                $field = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            }

            foreach ($steps as $s) {
                $found[] = [$field, ($s[1] ?? '') !== '' ? $s[1] : ($s[2] ?? '')];
            }
        }

        return $found;
    }

    public function test_every_step_attribute_in_the_views_is_classified(): void
    {
        $root = dirname(__DIR__, 2) . '/resources/views';
        $counts = [];
        $files = 0;

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (str_starts_with($relative, 'approvals/')) {
                continue;
            }
            $files++;
            foreach ($this->scan(file_get_contents($file->getPathname())) as [$field, $step]) {
                $key = "{$relative}|{$field}|{$step}";
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        $this->assertGreaterThan(200, $files, 'ビューを拾えていない（走査が空振りしている）');

        $problems = [];
        foreach ($counts as $key => $n) {
            if (! isset(self::CLASSIFIED[$key])) {
                $problems[] = "分類されていない step: {$key}（{$n} 件）";
            } elseif (self::CLASSIFIED[$key][0] !== $n) {
                $problems[] = "件数が違う: {$key}（分類 " . self::CLASSIFIED[$key][0] . " 件・実際 {$n} 件）";
            }
        }
        foreach (array_keys(self::CLASSIFIED) as $key) {
            if (! isset($counts[$key])) {
                $problems[] = "分類にあるが画面に無い（古い分類）: {$key}";
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_the_scanner_reads_only_step_attributes(): void
    {
        $this->assertSame([['a', '5']], $this->scan('<input type="number" name="a" step="5">'));
        $this->assertSame([['n', 'n']], $this->scan('<input type="number" name="n" :step="n">'));
        $this->assertSame([['m', 'n']], $this->scan('<input type="number" name="m" x-bind:step="n">'));

        $this->assertSame([], $this->scan('<input name="a" data-step="3">'));
        $this->assertSame([], $this->scan('{{-- <input name="a" step="9"> --}}'));
        $this->assertSame([], $this->scan('<script>var o = { step: 1 };</script><input name="a">'));

        // 欄は name、無ければ x-model（修飾子つきも）
        $this->assertSame([['depositMonths', '0.1']], $this->scan('<input type="number" x-model.number="depositMonths" step="0.1">'));
        $this->assertSame([['q', '1']], $this->scan('<input name="q" x-model="z" step="1">'));
        $this->assertSame([['（欄なし）', '2']], $this->scan('<input step="2">'));

        // 属性値の中の > と {{ }} に惑わされない
        $this->assertSame([['b', '10']], $this->scan('<input name="b" value="{{ old(\'b\', $p?->b) }}" step="10">'));
    }
}
