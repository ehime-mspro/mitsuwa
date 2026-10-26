<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * ⚠ 先に Blade のコメント・`@php`・ディレクティブの引数（`@selected(…)` など）を位置を保って空白にし、`{{ }}` の中身を伏せてから
 *   タグを読む（中身の `"` や `>` で属性の区切りを読み違えないように）。JS の `//` コメントは消さない（Blade はその中も出力する）。
 * ⚠ `src=` のある `<script>` は中身が動かないので数えない。<script> の中の文字（`'<b onclick="…">'`）はタグとして読まない。
 *   JSON の `<script type="application/json">` は JS の場所として数える（`\` で JSON.parse が壊れる）。
 * ⚠ 見えないもの（死角）: `old()` を通らない値（コントローラが渡した変数・DB の値）・`@php` で変数に入れてから書く値・
 *   部品の中で組み立てる値・JS の側で `innerHTML` などに入れる値。決裁の画面（`approvals/`）も見る（数で持たないので、決裁の作業を止めない）。
 */
class JsStringEchoScanTest extends TestCase
{
    /**
     * 走査が空振りして緑になるのを防ぐ下限（2026-10-26 の実測: <script> 108・JS の属性 3,649・安全な書き方 71。
     * 安全な書き方は直す前 31 → 直したあと 71）
     */
    private const MIN_SCRIPTS = 100;

    private const MIN_JS_ATTRIBUTES = 3000;

    private const MIN_SAFE_READS = 65;

    /** 入力の値を読む呼び出し（`$x->old(` のようなメソッドは数えない） */
    private const READS = '/(?<![\w$>:\\\\])(?:old|request)\s*\(/';

    /** JS として読まれる属性の名前 */
    private const JS_ATTRIBUTE = '/^(?:x-[\w:.-]+|:[\w:.-]+|@[\w:.-]+|on[a-z]+)$/i';

    public function test_input_values_are_written_into_javascript_in_a_safe_form(): void
    {
        $violations = [];
        foreach ($this->views() as $relative => $source) {
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
        foreach ($this->views() as $source) {
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
            '複数行の属性と中の二重引用符'     => ["<div x-data=\"{\n    f: u => u.id > 0,\n    a: '{{ old(\"a\", \$b ? \"x\" : \"y\") }}'\n}\"></div>", 1],
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

    /** @return array<string, string> views からの相対パス => 中身 */
    private function views(): array
    {
        $views = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $views[str_replace(resource_path('views') . '/', '', $file->getPathname())] = file_get_contents($file->getPathname());
            }
        }
        ksort($views);
        $this->assertGreaterThan(250, count($views), 'ビューを拾えていない');

        return $views;
    }

    /**
     * @return array{violations: list<array{0: int, 1: string}>, unreadable: list<string>, scripts: int, jsAttributes: int, safe: int}
     */
    private function scan(string $source): array
    {
        $blank = fn (array $m) => preg_replace('/[^\n]/', ' ', $m[0]);
        // 1. Blade のコメント・@php の中身は出力されないので消す（位置は保つ）
        $text = preg_replace_callback('/\{\{--.*?--\}\}/s', $blank, $source);
        $text = preg_replace_callback('/@php\b(?!\s*\().*?@endphp/s', $blank, $text);

        // 2. `{{ }}` / `{!! !!}`（`@{{` は Blade が出力しない文字なので数えない）の位置と中身。中身は伏せる
        preg_match_all('/(?<!@)\{\{(?!--)(.*?)\}\}|(?<!@)\{!!(.*?)!!\}/s', $text, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $echoes = [];
        $masked = $text;
        foreach ($found as $m) {
            $inner = ($m[1][1] ?? -1) >= 0 ? $m[1] : $m[2];
            $echoes[] = ['pos' => $m[0][1], 'echo' => $m[0][0], 'expr' => $inner[0]];
            $masked = substr_replace($masked, preg_replace('/[^\n]/', '_', $inner[0]), $inner[1], strlen($inner[0]));
        }

        // 3. ディレクティブの引数（`@selected(…)` `@json(…)` など。PHP の式）は伏せる。`@@` は文字
        $unreadable = [];
        $offset = 0;
        while (preg_match('/(?<!@)@[A-Za-z_]\w*\s*\(/', $masked, $d, PREG_OFFSET_CAPTURE, $offset)) {
            $open = $d[0][1] + strlen($d[0][0]) - 1;
            $close = self::closingParen($masked, $open);
            if ($close === null) {
                $unreadable[] = trim(substr($masked, $d[0][1], 40));
                break;
            }
            $masked = substr_replace($masked, preg_replace('/[^\n]/', ' ', substr($masked, $d[0][1], $close - $d[0][1] + 1)), $d[0][1], $close - $d[0][1] + 1);
            $offset = $close + 1;
        }

        // 4. JS の場所: <script> の中（src のあるものは除く）と、JS として読まれる属性の値
        $ranges = [];
        $scripts = 0;
        preg_match_all('#<script\b([^>]*)>(.*?)</script\s*>#is', $masked, $sm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $outside = $masked;
        foreach ($sm as $s) {
            $outside = substr_replace($outside, preg_replace('/[^\n]/', ' ', $s[2][0]), $s[2][1], strlen($s[2][0]));
            if (preg_match('/\bsrc\s*=/i', $s[1][0]) === 1) {
                continue;
            }
            $scripts++;
            $ranges[] = [$s[2][1], $s[2][1] + strlen($s[2][0])];
        }

        $jsAttributes = 0;
        preg_match_all('/<([a-zA-Z][\w.:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', $outside, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($tags as $tag) {
            preg_match_all('/(?<=\s)([^\s=>"\'\/]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/s', $tag[2][0], $attrs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($attrs as $a) {
                $name = $a[1][0];
                if (preg_match(self::JS_ATTRIBUTE, $name) !== 1) {
                    continue;
                }
                $value = ($a[2][1] ?? -1) >= 0 ? $a[2] : $a[3];
                $start = $tag[2][1] + $value[1];
                $jsAttributes++;
                $ranges[] = [$start, $start + strlen($value[0])];
            }
        }

        // 5. JS の場所にある `{{ }}` のうち、入力の値を読むもの
        $violations = [];
        $safe = 0;
        foreach ($echoes as $echo) {
            $inJs = false;
            foreach ($ranges as [$from, $to]) {
                if ($from <= $echo['pos'] && $echo['pos'] < $to) {
                    $inJs = true;
                    break;
                }
            }
            if (! $inJs || preg_match(self::READS, $echo['expr']) !== 1) {
                continue;
            }
            if (self::isSafe($echo['expr'])) {
                $safe++;
            } else {
                $violations[] = [substr_count($source, "\n", 0, $echo['pos']) + 1, $echo['echo']];
            }
        }

        return ['violations' => $violations, 'unreadable' => $unreadable, 'scripts' => $scripts, 'jsAttributes' => $jsAttributes, 'safe' => $safe];
    }

    /** 壊れない書き方か（Js::from の 1 つの呼び出し ／ (int)・(float) の 1 つの呼び出し ／ 文字だけを返す三項演算） */
    private static function isSafe(string $expression): bool
    {
        $e = trim($expression);
        if (preg_match('/^\\\\?(?:Illuminate\\\\Support\\\\)?Js::from\s*\(/', $e, $m) === 1) {
            return self::closingParen($e, strlen($m[0]) - 1) === strlen($e) - 1;
        }
        if (preg_match('/^\((?:int|float)\)\s*([\\\\\w:$>-]*\s*\()/', $e, $m, PREG_OFFSET_CAPTURE) === 1) {
            return self::closingParen($e, $m[1][1] + strlen($m[1][0]) - 1) === strlen($e) - 1;
        }

        return preg_match("/\\?\\s*'[\\w-]*'\\s*:\\s*'[\\w-]*'$/", $e) === 1;
    }

    /** $s[$open] の `(` に対応する `)` の位置（引用符の中の括弧は数えない）。閉じていなければ null */
    private static function closingParen(string $s, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        for ($i = $open, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }
}
