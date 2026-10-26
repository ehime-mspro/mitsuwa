<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ScansBladeJsEchoes;
use Tests\TestCase;

/**
 * 画面の JavaScript に入力の値（`old()`・`request()`）を、壊れない書き方で渡す（Bug #76 の形）。全ビューを走査する。
 *
 * ⚠ `'{{ old('x') }}'` と JS の文字列に書くと、`{{ }}` は HTML 用の逃がし（`'` → `&#039;`）なので JS の文字列としては守られない。
 *   <script> の中では実体参照が戻らず文字が変わり、末尾が `\` だと次の `'` を飲み込んで画面の JS が丸ごと止まる。
 *   HTML の属性（`x-data`・`:selected` など）の中ではブラウザが実体参照を戻すので、`'` で文字列が閉じて値が JS のコードとして動く。
 *   引用符で囲まない `{{ old('x') }}` は、空なら構文エラー（Bug #84）、文字ならそのままコードになる。
 * ⚠ JS の場所（`<script>` の中と、`x-*` `:` `@` `on*` の属性の中）で `old(` / `request(` を読む `{{ }}`・`{!! !!}` は、
 *   次のどれかでなければ落とす: `Js::from(…)` の 1 つの呼び出し ／ `(int)` `(float)` を付けた 1 つの呼び出し ／
 *   文字だけを返す三項演算（`… ? 'true' : 'false'`）。`@json(…)` は `{{ }}` を通らないので見ない（壊れない）。
 * ⚠ ビューの読み方（コメントなどを空白にして JS の場所を見つける）は Tests\Concerns\ScansBladeJsEchoes（全件分類の
 *   JsEchoClassificationTest と共用）。下の見本は、その読み方の分かれ目を固定する。
 * ⚠ 見えないもの（死角）: `@php` で変数に入れてから書く値・部品の中で組み立てる値・JS の側で `innerHTML` などに入れる値。
 *   `old()` を通らない値（コントローラが渡した変数・DB の値）は JsEchoClassificationTest が全件分類で見る。
 *   決裁の画面（`approvals/`）も見る（数で持たないので、決裁の作業を止めない）。
 */
class JsStringEchoScanTest extends TestCase
{
    use ScansBladeJsEchoes;

    /**
     * 走査が空振りして緑になるのを防ぐ下限（2026-10-26 の実測: <script> 108・JS の属性 3,649・安全な書き方 71。
     * 安全な書き方は直す前 31 → 直したあと 71）
     */
    private const MIN_SCRIPTS = 100;

    private const MIN_JS_ATTRIBUTES = 3000;

    private const MIN_SAFE_READS = 65;

    /** 入力の値を読む呼び出し（`$x->old(` のようなメソッドは数えない） */
    private const READS = '/(?<![\w$>:\\\\])(?:old|request)\s*\(/';

    public function test_input_values_are_written_into_javascript_in_a_safe_form(): void
    {
        $violations = [];
        foreach ($this->bladeViews() as $relative => $source) {
            $result = $this->scan($source);
            $this->assertSame([], $result['unreadable'], "{$relative} を読めない（括弧が閉じていない）: " . implode(' / ', $result['unreadable']));
            foreach ($result['violations'] as [$line, $echo]) {
                $violations[] = "resources/views/{$relative}:{$line}  {$echo}";
            }
        }

        $this->assertSame([], $violations,
            "画面の JS に入力の値を引用符で直に（または囲まずに）書いている。`{{ \\Illuminate\\Support\\Js::from((string) old('x', …)) }}` か `@json(…)` にする:\n"
            . implode("\n", $violations));
    }

    public function test_the_scan_did_not_come_up_empty(): void
    {
        $scripts = $attributes = $safe = 0;
        foreach ($this->bladeViews() as $source) {
            $result = $this->scan($source);
            $scripts += $result['scripts'];
            $attributes += $result['jsAttributes'];
            $safe += $result['safe'];
        }

        $this->assertGreaterThanOrEqual(self::MIN_SCRIPTS, $scripts, '走査した <script> が少なすぎる（取り出し方が壊れていないか）');
        $this->assertGreaterThanOrEqual(self::MIN_JS_ATTRIBUTES, $attributes, '走査した JS の属性が少なすぎる（タグの読み方が壊れていないか）');
        $this->assertGreaterThanOrEqual(self::MIN_SAFE_READS, $safe, '安全な書き方で入力の値を渡している所が少なすぎる（判定が壊れていないか）');
    }

    /**
     * 分かれ目ごとの見本（当たる数）。
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function samples(): array
    {
        return [
            'script の引用符の中'              => ["<script>var a = '{{ old('a') }}';</script>", 1],
            'script の二重引用符の中'          => ['<script>var a = "{{ old("a") }}";</script>', 1],
            'script で囲まない'                => ["<script>var a = {{ old('a') }};</script>", 1],
            'Js::from の 1 つの呼び出し'       => ["<script>var a = {{ \\Illuminate\\Support\\Js::from((string) old('a', '')) }};</script>", 0],
            '短い名前の Js::from'              => ["<script>var a = {{ Js::from(old('a')) }};</script>", 0],
            'Js::from のあとに続ける'          => ["<script>var a = {{ Js::from(old('a')) . old('b') }};</script>", 1],
            '(int) の 1 つの呼び出し'          => ["<script>var a = {{ (int) old('a', \$x->rent) }};</script>", 0],
            '(int) のあとに続ける'             => ["<script>var a = {{ (int) old('a') . old('b') }};</script>", 1],
            '文字だけの三項演算'               => ["<script>var a = {{ old('a', \$x ?? 0) ? 'true' : 'false' }};</script>", 0],
            '値を返す三項演算'                 => ["<script>var a = '{{ old('a') ? old('b') : 'x' }}';</script>", 1],
            '{!! !!}'                          => ["<script>var a = '{!! old('a') !!}';</script>", 1],
            'request()'                        => ["<script>var a = '{{ request('a', '') }}';</script>", 1],
            'old の中の request は 1 つと数える' => ["<script>var a = '{{ old('a', request('b', '')) }}';</script>", 1],
            'old という名前のメソッド'         => ["<script>var a = '{{ \$user->old('a') }}';</script>", 0],
            '入力の値を読まない'               => ["<script>var a = '{{ \$contract->contract_type->value }}';</script>", 0],
            'JSON の script'                   => ["<script type=\"application/json\">{\"a\": \"{{ old('a') }}\"}</script>", 1],
            'src のある script'                => ["<script src=\"a.js\">{{ old('a') }}</script>", 0],
            'x-data の属性'                    => ["<div x-data=\"{ a: '{{ old('a') }}' }\"></div>", 1],
            '一重引用符の属性'                 => ["<div x-data='{ a: \"{{ old('a') }}\" }'></div>", 1],
            ':selected の属性'                 => ["<option :selected=\"u.id == '{{ old('a') }}'\"></option>", 1],
            '@click の属性'                    => ["<button @click=\"go('{{ old('a') }}')\"></button>", 1],
            'x-on: の属性'                     => ["<button x-on:click.prevent=\"go('{{ old('a') }}')\"></button>", 1],
            'onsubmit の属性'                  => ["<form onsubmit=\"return go('{{ old('a') }}')\"></form>", 1],
            'ふつうの属性と文'                 => ["<input value=\"{{ old('a') }}\" title='{{ old('b') }}'><p>{{ old('c') }}</p>", 0],
            'Blade のコメント'                 => ["<script>{{-- var a = '{{ old('a') }}'; --}}</script>", 0],
            '@php の中'                        => ["<script>@php \$a = \"{{ old('a') }}\"; @endphp</script>", 0],
            '@{{ の逃がし'                     => ["<script>var a = '@{{ old('a') }}';</script>", 0],
            '部品の属性'                       => ["<x-field x-data=\"{ a: '{{ old('a') }}' }\" />", 1],
            '前の {{ }} の中の二重引用符'      => ["<div x-data=\"{\n    f: u => u.id > 0,\n    b: {{ \$x ? \"1\" : \"2\" }},\n    a: '{{ old('a') }}'\n}\"></div>", 1],
            'ディレクティブの引数の >'         => ["<option @selected(\$a > 1) x-data=\"{ a: '{{ old('b') }}' }\"></option>", 1],
            'ディレクティブの引数の引用符の中の )' => ["<option @selected(old('a') == \"x)'>\") x-data=\"{ a: '{{ old('b') }}' }\"></option>", 1],
        ];
    }

    #[DataProvider('samples')]
    public function test_the_scan_classifies_each_sample(string $source, int $expected): void
    {
        $result = $this->scan($source);

        $this->assertSame([], $result['unreadable'], '見本を読めない');
        $this->assertCount($expected, $result['violations'], "当たる数が違う: {$source}");
    }

    public function test_an_unbalanced_directive_is_reported_as_unreadable(): void
    {
        $this->assertNotSame([], $this->scan("<div @selected(old('a') x-data=\"{}\"></div>")['unreadable'], '括弧の閉じないディレクティブを黙って通した');
    }

    public function test_tags_inside_scripts_are_not_read_as_markup(): void
    {
        $this->assertSame(0, $this->scan("<script>var t = \"<b onclick='go()'>\";</script>")['jsAttributes'], '<script> の中の文字をタグとして読んだ');
    }

    public function test_the_violation_names_its_line(): void
    {
        $this->assertSame([[3, "{{ old('a') }}"]], $this->scan("<div>\n<p>x</p>\n<div x-data=\"{ a: '{{ old('a') }}' }\"></div>\n</div>")['violations']);
    }

    // ============================================================
    // 走査
    // ============================================================

    /**
     * @return array{violations: list<array{0: int, 1: string}>, unreadable: list<string>, scripts: int, jsAttributes: int, safe: int}
     */
    private function scan(string $source): array
    {
        $found = $this->jsEchoes($source);
        $violations = [];
        $safe = 0;
        foreach ($found['echoes'] as $echo) {
            if (preg_match(self::READS, $echo['expr']) !== 1) {
                continue;
            }
            if (self::isSafeForm($echo['expr'])) {
                $safe++;
            } else {
                $violations[] = [$echo['line'], $echo['echo']];
            }
        }

        return ['violations' => $violations, 'unreadable' => $found['unreadable'], 'scripts' => $found['scripts'], 'jsAttributes' => $found['jsAttributes'], 'safe' => $safe];
    }
}
