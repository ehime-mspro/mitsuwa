<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 幅・スクロール位置に触るページの `<script>` が、Alpine の起動前後で幅が変わっても正しく動くこと（docs/RULES.md Bug #56）。
 *
 * ページの `<script>` はパース中に同期で走り、Alpine（`@vite` の module ＝ defer）より前に動く。
 * 2026-09-13 まで、その瞬間は PC 展開サイドバーが `x-cloak` で隠れていて、表示領域が 220px 広く測れた
 * （根本原因の `x-cloak` は外した。LayoutSidebarCloakTest が固定する）。
 * 1024px 以上のある幅で ①工程表ボードの初期スクロールが 220px 手前で止まる ②3 一覧でスクロールできるのに
 * 「横にスクロールして全項目を表示」と右端のフェードが出ない、になっていた（2026-09-11）。
 * 入居者一覧は制御する script 自体が無く、表が収まる幅でもヒントとフェードが常に出ていた（2026-09-13 に追加）。
 * Alpine の起動後に幅が変わる要因はほかにもある（縦スクロールバーの出入り等）ので、各画面の方針をここで固定する。
 *
 * 方針（LAYOUT_SCRIPT_VIEWS）:
 *   DCL_ONLY      … 初回の計測を DOMContentLoaded（DCL）まで待ち、パース中には呼ばない（スクロール位置は丸めが戻らない）
 *   PARSE_AND_DCL … パース中にも判定して最初の描画を決め、DCL でも測り直す（表示の出し分けは何度測っても害が無い）。
 *                   ⚠ DCL だけにすると、`app.js` の取得が遅いとき DCL までヒントが既定の「表示」のまま描かれ、
 *                   表が収まる幅でも一瞬出て消える（300ms 遅延で 51〜345ms の全描画に出た。2026-09-13 実測）
 *   null          … 利用者の操作（クリック・保存）の後にだけ測る
 *
 * ⚠ 構造しか見られない。実際にヒントが出るか・スクロールが止まる位置はブラウザでしか測れない。
 * ⚠ 見えないもの: Chart.js の生成（親の幅を読む）・Alpine の `init()` ／ `x-init` の中の計測・文字列展開の `${}` の中。
 *   ここで拾うのはインライン `<script>` の中の 5 つのプロパティ（LAYOUT_ACCESS）だけ。
 * ⚠ Blade コメントと JS コメントを落としてから測る（説明文の語に一致して false-pass しないように。Bug #42 ②）。
 */
class LayoutMeasuringScriptTest extends TestCase
{
    /** 幅・スクロール位置を読む（書く）JS */
    private const LAYOUT_ACCESS = '/\.(?:scrollWidth|clientWidth|offsetWidth|getBoundingClientRect|scrollLeft)\b/';

    private const DCL_ONLY = 'dcl-only';

    private const PARSE_AND_DCL = 'parse-and-dcl';

    /**
     * 幅・スクロール位置に触るインライン `<script>` を持つ Blade の全件分類（Bug #45 ① / Top trap #13）。
     * 値は [初回の計測関数の名前, 方針] ／ null（操作の後にだけ測る。理由をコメントに書く）。
     *
     * ⚠ 新しく増えて落ちたら、パース中に測っていないかを確かめてから分類して足すこと。
     */
    private const LAYOUT_SCRIPT_VIEWS = [
        '_partials/_schedule_board.blade.php' => ['scheduleBoardSetInitialScroll', self::DCL_ONLY],
        'tenant/units/index.blade.php' => ['checkScroll', self::DCL_ONLY],
        'mansion/contracts/index.blade.php' => ['update', self::PARSE_AND_DCL],
        'mansion/tenants/index.blade.php' => ['update', self::PARSE_AND_DCL],
        'zeal/inquiries/index.blade.php' => ['update', self::PARSE_AND_DCL],
        'zeal/members/index.blade.php' => ['update', self::PARSE_AND_DCL],

        '_partials/_schedule_section.blade.php' => null,   // 保存の Ajax 応答でガントを差し替えた後
        'buyers/index.blade.php' => null,                  // ランクのバッジをクリックした時
        'housing/custom-orders/index.blade.php' => null,   // 進捗バッジをクリックした時
        'housing/properties/index.blade.php' => null,      // ステータスのバッジをクリックした時
        'realestate/procurements/index.blade.php' => null, // 同上
        'realestate/projects/index.blade.php' => null,     // 同上
    ];

    /** DOMContentLoaded を登録している `addEventListener(` の頭（`code` 側で見る。引数は後ろに続く） */
    private const DCL_HEAD = '(?:document|window)\.addEventListener\(\s*[\'"]DOMContentLoaded[\'"]\s*,\s*';

    public function test_every_layout_measuring_script_is_classified(): void
    {
        [$found, $scriptCount] = $this->layoutScripts();

        // 空振り防止（2026-09-13 時点でインライン <script> 96 個）
        $this->assertGreaterThan(80, $scriptCount, '走査したインライン <script> が少なすぎる（抽出が壊れていないか）');

        $classified = array_keys(self::LAYOUT_SCRIPT_VIEWS);

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($found), $classified)),
            '幅・スクロール位置に触る <script> が増えた。パース中に測っていないかを確かめてから LAYOUT_SCRIPT_VIEWS に分類して足すこと'
        );
        $this->assertSame(
            [],
            array_values(array_diff($classified, array_keys($found))),
            'LAYOUT_SCRIPT_VIEWS にあるのに幅・スクロール位置に触っていない（消えた・書き換わった）。分類表から外すこと'
        );
    }

    /**
     * 幅の読み取りは関数の中にだけ置く（パース中の最上位・即時実行関数の中に直接書かない）。
     * 分類がどれでも同じ。null 分類のビューにパース中の計測を足しても、ここで落ちる。
     */
    public function test_layout_is_not_read_directly_at_parse_time(): void
    {
        [$found] = $this->layoutScripts();

        $problems = [];
        foreach ($found as $view => $scripts) {
            foreach ($scripts as $script) {
                $js = $this->analyze($script, $view);
                preg_match_all(self::LAYOUT_ACCESS, $js['shape'], $reads, PREG_OFFSET_CAPTURE);
                foreach ($reads[0] as [$text, $offset]) {
                    if ($this->runsAtParseTime($offset, $js['blocks'])) {
                        $problems[] = "{$view}: パース中に直接 {$text} を読んでいる（" . $this->excerpt($script, $offset) . '）';
                    }
                }
            }
        }

        $this->assertSame([], $problems, "幅・スクロール位置をパース中に直接読んでいる（関数に入れて方針どおりに呼ぶこと）:\n" . implode("\n", $problems));
    }

    public function test_initial_measurement_follows_each_policy(): void
    {
        [$found] = $this->layoutScripts();

        // 1 画面目で止めず、全画面の問題を集めてからまとめて判定する（どの画面が壊れたかを全部名指しする）
        $problems = [];
        foreach (array_filter(self::LAYOUT_SCRIPT_VIEWS) as $view => [$fn, $policy]) {
            $name = preg_quote($fn, '/');
            $scripts = array_values(array_filter(
                $found[$view] ?? [],
                fn ($script) => preg_match('/\bfunction\s+' . $name . '\s*\(/', $script) === 1
            ));
            $this->assertCount(1, $scripts, "{$view}: 計測関数 {$fn} を定義する <script> が 1 本に定まらない");
            $js = $this->analyze($scripts[0], $view);

            // 計測関数の呼び出し（定義 `function 名(` とメソッド呼び出し `x.名(` は除く）
            $atParse = [];
            $atDcl = 0;
            preg_match_all('/(?<![\w$.])' . $name . '\s*\(/', $js['shape'], $calls, PREG_OFFSET_CAPTURE);
            foreach ($calls[0] as [, $offset]) {
                if (preg_match('/\bfunction\s+$/', substr($js['shape'], 0, $offset)) === 1) {
                    continue;
                }
                if ($this->runsAtParseTime($offset, $js['blocks'])) {
                    $atParse[] = $this->excerpt($scripts[0], $offset);
                } elseif ($this->runsAtDcl($offset, $js['blocks'])) {
                    $atDcl++;
                }
                // それ以外（クリック・resize などの別のコールバックの中）は初回とは数えない
            }

            // DOMContentLoaded に関数そのものを渡す登録（`{ once: true }` 等の後続の引数は可）。
            // 登録がパース中に行われ、文字列やコメントの中でないこと
            preg_match_all('/' . self::DCL_HEAD . $name . '\s*[,)]/', $js['code'], $refs, PREG_OFFSET_CAPTURE);
            foreach ($refs[0] as [$text, $offset]) {
                if (substr($js['shape'], $offset, 16) === substr($js['code'], $offset, 16)
                    && $this->runsAtParseTime($offset, $js['blocks'])) {
                    $atDcl++;
                }
            }

            if ($policy === self::DCL_ONLY) {
                foreach ($atParse as $call) {
                    $problems[] = "{$view}: パース中に {$fn}() を呼んでいる（{$call}）。初回は DOMContentLoaded まで待つ方針";
                }
            } elseif ($atParse === []) {
                $problems[] = "{$view}: パース中の {$fn}() が無い（最初の描画をパース中に決める方針。"
                    . 'DCL だけだと JS の取得が遅いとき表示が一瞬出て消える）';
            }
            if ($atDcl === 0) {
                $problems[] = "{$view}: 初回の {$fn}() が DOMContentLoaded に登録されていない（Alpine の起動後に測り直していない）";
            }
        }

        $this->assertSame([], $problems, "幅・スクロール位置の初回の計測が方針どおりでない:\n" . implode("\n", $problems));
    }

    /**
     * 右端のフェード（`class="scroll-fade-right"`）を持つビューは、制御する script を持ち PARSE_AND_DCL に分類されていること。
     *
     * 2026-09-13 まで賃貸マンション入居者一覧は script が無く、表が収まる幅でもヒントとフェードが常に出ていた。
     * 幅を読む script が無い画面は上の分類テストの走査に原理的に入らないので、見た目の側（フェードの要素）から数える。
     */
    public function test_every_scroll_fade_has_a_controlling_script(): void
    {
        $root = resource_path('views') . '/';
        $views = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($file->getPathname()));
            if (preg_match('/class="[^"]*\bscroll-fade-right\b[^"]*"/', $source) === 1) {
                $views[] = str_replace($root, '', $file->getPathname());
            }
        }
        sort($views);

        $this->assertNotEmpty($views, '右端のフェードを持つビューが 1 本も見つからない（走査が壊れていないか）');

        $controlled = array_keys(array_filter(
            self::LAYOUT_SCRIPT_VIEWS,
            fn ($entry) => $entry !== null && $entry[1] === self::PARSE_AND_DCL
        ));
        $this->assertSame(
            [],
            array_values(array_diff($views, $controlled)),
            '右端のフェードを持つのに、制御する script（パース中と DOMContentLoaded の両方で判定）が無い。'
            . 'ヒントとフェードが幅に関係なく常に出る'
        );
    }

    // ============================================================
    // 走査と解析
    // ============================================================

    /**
     * 幅・スクロール位置に触るインライン `<script>` を Blade ごとに集める。
     *
     * @return array{0: array<string, list<string>>, 1: int} [views からの相対パス => script の本体の一覧, 走査したインライン script の数]
     */
    private function layoutScripts(): array
    {
        $root = resource_path('views') . '/';
        $found = [];
        $count = 0;

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($file->getPathname()));

            preg_match_all('#<script\b([^>]*)>(.*?)</script>#s', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $attributes, $body]) {
                if (preg_match('/\bsrc\s*=/', $attributes) === 1) {
                    continue;
                }
                // 実行されない script（type="text/plain" や text/template 等）は数えない。
                // 数えると、スクリプトを不活性にしても「制御する script がある」と判定してしまう
                if (preg_match('/\btype\s*=\s*["\']?(?!(?:text|application)\/javascript\b|module\b)[\w\/+-]+/i', $attributes) === 1) {
                    continue;
                }
                $count++;
                if (preg_match(self::LAYOUT_ACCESS, $this->scan($body)['shape']) === 1) {
                    $found[str_replace($root, '', $file->getPathname())][] = $body;
                }
            }
        }
        ksort($found);

        return [$found, $count];
    }

    /**
     * JS を元と同じ長さ・同じ位置のまま 2 通りに写す。
     *   code  … コメントを空白にしたもの（文字列はそのまま。`'DOMContentLoaded'` を見るため）
     *   shape … コメントと文字列の中身を空白にしたもの（波括弧の対応・呼び出し・幅の読み取りを見る）
     * 行末の `//` コメントも落とす（文字列の中の `//` は落とさない）。
     *
     * ⚠ 正規表現リテラルは解釈しない。中に引用符や `//` があると写し方がずれるが、そのときは波括弧が
     *   釣り合わなくなり analyze() が「解析できない」として落ちる（黙って通さない）。
     *
     * @return array{code: string, shape: string}
     */
    private function scan(string $source): array
    {
        $code = '';
        $shape = '';
        $n = strlen($source);
        for ($i = 0; $i < $n; $i++) {
            $c = $source[$i];
            $next = $source[$i + 1] ?? '';

            if ($c === '/' && ($next === '/' || $next === '*')) {
                $end = $next === '/'
                    ? (($p = strpos($source, "\n", $i)) === false ? $n : $p)
                    : (($p = strpos($source, '*/', $i + 2)) === false ? $n : $p + 2);
                $blank = preg_replace('/[^\n]/', ' ', substr($source, $i, $end - $i));
                $code .= $blank;
                $shape .= $blank;
                $i = $end - 1;

                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $j = $i + 1;
                while ($j < $n && $source[$j] !== $c) {
                    $j += $source[$j] === '\\' ? 2 : 1;
                }
                $end = min($j, $n - 1);
                $literal = substr($source, $i, $end - $i + 1);
                $code .= $literal;
                $shape .= strlen($literal) < 2
                    ? $literal
                    : $c . preg_replace('/[^\n]/', ' ', substr($literal, 1, -1)) . substr($literal, -1);
                $i = $end;

                continue;
            }

            $code .= $c;
            $shape .= $c;
        }

        return ['code' => $code, 'shape' => $shape];
    }

    /**
     * @return array{code: string, shape: string, blocks: list<array{open: int, close: int, function: bool, iife: bool, dcl: bool, start: int}>}
     */
    private function analyze(string $script, string $view): array
    {
        $js = $this->scan($script);
        $stack = [];
        $blocks = [];
        for ($i = 0, $n = strlen($js['shape']); $i < $n; $i++) {
            if ($js['shape'][$i] === '{') {
                $stack[] = $i;
            } elseif ($js['shape'][$i] === '}') {
                $this->assertNotEmpty($stack, "{$view}: 波括弧が釣り合わない（この script は解析できない）");
                $blocks[] = $this->describeBlock($js, array_pop($stack), $i);
            }
        }
        $this->assertSame([], $stack, "{$view}: 波括弧が閉じていない（この script は解析できない）");
        $js['blocks'] = $blocks;

        return $js;
    }

    /**
     * `{` の直前を見て、関数の本体か（即時実行か・DCL のリスナーか）を決める。
     *
     * @param  array{code: string, shape: string}  $js
     * @return array{open: int, close: int, function: bool, iife: bool, dcl: bool, start: int}
     */
    private function describeBlock(array $js, int $open, int $close): array
    {
        $before = substr($js['shape'], 0, $open);
        $patterns = [
            '/\bfunction\b\s*[\w$]*\s*\([^()]*\)\s*$/',                                            // function 名(…) { ／ function (…) {
            '/(?:\([^()]*\)|[\w$]+)\s*=>\s*$/',                                                    // (…) => { ／ x => {
            '/(?<![\w$.])(?!(?:if|for|while|switch|catch|with|function)\b)[\w$]+\s*\([^()]*\)\s*$/', // 名前(…) {（メソッドの短縮記法）
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $before, $m, PREG_OFFSET_CAPTURE) === 1) {
                $start = $m[0][1];
                $after = substr($js['shape'], $close + 1);

                return [
                    'open' => $open,
                    'close' => $close,
                    'function' => true,
                    // (function () { … })() ／ (function () { … }()) ／ (() => { … })()
                    'iife' => preg_match('/\(\s*$/', substr($js['shape'], 0, $start)) === 1
                        && preg_match('/^\s*(?:\)\s*\(|\(\s*\))/', $after) === 1,
                    'dcl' => preg_match('/' . self::DCL_HEAD . '$/', substr($js['code'], 0, $start)) === 1,
                    'start' => $start,
                ];
            }
        }

        return ['open' => $open, 'close' => $close, 'function' => false, 'iife' => false, 'dcl' => false, 'start' => $open];
    }

    /** 外側の関数を内側から順に（関数でない波括弧＝if やオブジェクトは飛ばす） */
    private function enclosingFunctions(int $offset, array $blocks): array
    {
        $functions = array_filter($blocks, fn ($b) => $b['function'] && $b['open'] < $offset && $offset < $b['close']);
        usort($functions, fn ($a, $b) => $b['open'] <=> $a['open']);

        return $functions;
    }

    /** パース中に走る位置か（囲む関数が即時実行関数だけ、または無い） */
    private function runsAtParseTime(int $offset, array $blocks): bool
    {
        foreach ($this->enclosingFunctions($offset, $blocks) as $function) {
            if (! $function['iife']) {
                return false;
            }
        }

        return true;
    }

    /**
     * DOMContentLoaded の初回に走る位置か（いちばん内側の関数が DCL のリスナーで、その登録がパース中に行われる）。
     * ⚠ DCL のリスナーの中で登録した別のコールバック（resize 等）の中は数えない。
     */
    private function runsAtDcl(int $offset, array $blocks): bool
    {
        $innermost = $this->enclosingFunctions($offset, $blocks)[0] ?? null;

        return $innermost !== null && $innermost['dcl'] && $this->runsAtParseTime($innermost['start'], $blocks);
    }

    private function excerpt(string $script, int $offset): string
    {
        return trim(strtok(substr($script, $offset, 80), "\n"));
    }
}
