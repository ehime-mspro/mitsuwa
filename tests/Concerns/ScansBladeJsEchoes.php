<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;

/**
 * Blade のビューを読み、画面の JavaScript の場所（`<script>` の中と、`x-*` `:` `@` `on*` の属性の中）にある
 * `{{ }}`・`{!! !!}` を集める（Bug #76 の形の走査）。`JsStringEchoScanTest`（入力の値）と `JsEchoClassificationTest`（全件分類）が使う。
 *
 * ⚠ 先に Blade のコメント・`@php`・ディレクティブの引数（`@selected(…)` など）を位置を保って空白にし、`{{ }}` の中身を伏せてから
 *   タグを読む（中身の `"` や `>` で属性の区切りを読み違えないように）。JS の `//` コメントは消さない（Blade はその中も出力する）。
 * ⚠ `src=` のある `<script>` は中身が動かないので数えない。<script> の中の文字（`'<b onclick="…">'`）はタグとして読まない。
 *   JSON の `<script type="application/json">` は JS の場所として数える（`\` で JSON.parse が壊れる）。
 * ⚠ 括弧の閉じないディレクティブは推測せず「読めない」として返す。
 * ⚠ 位置はバイトで数える（空白にするときもバイトの数を保つ）ので、行番号は元のビューの行と一致する。
 */
trait ScansBladeJsEchoes
{
    /** JS として読まれる属性の名前 */
    private const JS_ATTRIBUTE = '/^(?:x-[\w:.-]+|:[\w:.-]+|@[\w:.-]+|on[a-z]+)$/i';

    /** @return array<string, string> views からの相対パス => 中身 */
    protected function bladeViews(): array
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
     * JS の場所にある `{{ }}`・`{!! !!}`（`expr` は中身の式。前後の空白を落とし、続く空白は 1 つにする）。
     *
     * @return array{echoes: list<array{line: int, echo: string, expr: string}>, unreadable: list<string>, scripts: int, jsAttributes: int}
     */
    protected function jsEchoes(string $source): array
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
                if (preg_match(self::JS_ATTRIBUTE, $a[1][0]) !== 1) {
                    continue;
                }
                $value = ($a[2][1] ?? -1) >= 0 ? $a[2] : $a[3];
                $start = $tag[2][1] + $value[1];
                $jsAttributes++;
                $ranges[] = [$start, $start + strlen($value[0])];
            }
        }

        // 5. JS の場所にある `{{ }}`
        $inJs = [];
        foreach ($echoes as $echo) {
            foreach ($ranges as [$from, $to]) {
                if ($from <= $echo['pos'] && $echo['pos'] < $to) {
                    $inJs[] = [
                        'line' => substr_count($source, "\n", 0, $echo['pos']) + 1,
                        'echo' => $echo['echo'],
                        'expr' => preg_replace('/\s+/', ' ', trim($echo['expr'])),
                    ];
                    break;
                }
            }
        }

        return ['echoes' => $inJs, 'unreadable' => $unreadable, 'scripts' => $scripts, 'jsAttributes' => $jsAttributes];
    }

    /** 壊れない書き方か（Js::from の 1 つの呼び出し ／ (int)・(float) の 1 つの呼び出し ／ 文字だけを返す三項演算） */
    protected static function isSafeForm(string $expression): bool
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
    protected static function closingParen(string $s, int $open): ?int
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
