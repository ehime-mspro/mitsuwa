<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 幅・スクロール位置に触るページの `<script>` が、初回の計測を Alpine の起動後まで待つこと。
 *
 * ページの `<script>` はパース中に同期で走り、Alpine（`@vite` の module ＝ defer）より前に動く。
 * その瞬間は PC サイドバー（layouts/partials/sidebar.blade.php の `x-cloak`）が `display: none` で、
 * 表示領域が 220px 広く測れる。1024px 以上のある幅で次の症状になっていた（docs/RULES.md Bug #56）:
 *   - 工程表ボード: 初期スクロールが 220px 手前で止まる（2026-09-11 に修正。36b86b80）
 *   - 賃貸マンション契約一覧・ZEAL 体験予約一覧・ZEAL 会員一覧: スクロールできるのに
 *     「横にスクロールして全項目を表示」と右端のフェードが出ない（2026-09-11 に修正）
 * DOMContentLoaded は defer / module のスクリプト（Alpine）と、そのあとの `x-cloak` の除去が済んでから発火する。
 * ⚠ `requestAnimationFrame` は不可（非表示のタブでは止まり、Alpine より後に走る保証も無い）。
 *
 * ⚠ 構造しか見られない。実際にヒントが出るか・スクロールが止まる位置はブラウザでしか測れない。
 * ⚠ Blade コメントと JS コメントを落としてから測る（説明文の語に一致して false-pass しないように。Bug #42 ②）。
 */
class LayoutMeasuringScriptTest extends TestCase
{
    /** 幅・スクロール位置を読む（書く）JS */
    private const LAYOUT_ACCESS = '/\.(?:scrollWidth|clientWidth|offsetWidth|getBoundingClientRect|scrollLeft)\b/';

    /** DOMContentLoaded のリスナーのうち、関数の本体を持つもの（`{` まで） */
    private const DCL_WITH_BODY = '/(?:document|window)\.addEventListener\(\s*[\'"]DOMContentLoaded[\'"]\s*,\s*function\s*\([^)]*\)\s*\{/';

    /**
     * 幅・スクロール位置に触るインライン `<script>` を持つ Blade の全件分類（Bug #45 ① / Top trap #13）。
     *
     * 値が文字列 = 初回の計測を DOMContentLoaded まで待つ、その計測関数の名前（下のテストが構造を見る）
     * 値が null   = 利用者の操作（クリック・保存）の後にだけ測るので、パース中には走らない
     *
     * ⚠ 新しく増えて落ちたら、**パース中に測っていないか**を確かめてから、どちらかに分類して足すこと。
     */
    private const LAYOUT_SCRIPT_VIEWS = [
        '_partials/_schedule_board.blade.php' => 'scheduleBoardSetInitialScroll',
        'mansion/contracts/index.blade.php' => 'update',
        'tenant/units/index.blade.php' => 'checkScroll',
        'zeal/inquiries/index.blade.php' => 'update',
        'zeal/members/index.blade.php' => 'update',

        '_partials/_schedule_section.blade.php' => null,   // 保存の Ajax 応答でガントを差し替えた後
        'buyers/index.blade.php' => null,                  // ランクのバッジをクリックした時
        'housing/custom-orders/index.blade.php' => null,   // 進捗バッジをクリックした時
        'housing/properties/index.blade.php' => null,      // ステータスのバッジをクリックした時
        'realestate/procurements/index.blade.php' => null, // 同上
        'realestate/projects/index.blade.php' => null,     // 同上
    ];

    public function test_every_layout_measuring_script_is_classified(): void
    {
        [$found, $scriptCount] = $this->layoutScripts();

        // 空振り防止（2026-09-11 時点でインライン <script> 96 個）
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

    public function test_initial_measurement_waits_for_dom_content_loaded(): void
    {
        [$found] = $this->layoutScripts();

        // 1 画面目で止めず、全画面の問題を集めてからまとめて判定する（どの画面が壊れたかを全部名指しする）
        $problems = [];
        foreach (array_filter(self::LAYOUT_SCRIPT_VIEWS) as $view => $fn) {
            $name = preg_quote($fn, '/');
            $scripts = array_values(array_filter(
                $found[$view] ?? [],
                fn ($script) => preg_match('/\bfunction\s+' . $name . '\s*\(/', $script) === 1
            ));
            $this->assertCount(1, $scripts, "{$view}: 計測関数 {$fn} を定義する <script> が 1 本に定まらない");
            $script = $scripts[0];

            // DOMContentLoaded のリスナーの本体（波括弧の対応で切り出す。固定長で切らない。Bug #45 ④）
            $bodies = [];
            preg_match_all(self::DCL_WITH_BODY, $script, $listeners, PREG_OFFSET_CAPTURE);
            foreach ($listeners[0] as [$text, $offset]) {
                $open = $offset + strlen($text) - 1;
                $bodies[] = [$open, $this->closingBrace($script, $open, $view)];
            }

            // DOMContentLoaded に関数そのものを渡す登録（document / window どちらでもよい）
            $byReference = preg_match_all(
                '/(?:document|window)\.addEventListener\(\s*[\'"]DOMContentLoaded[\'"]\s*,\s*' . $name . '\s*\)/',
                $script
            );

            // 計測関数の呼び出し（定義 `function 名(` とメソッド呼び出し `x.名(` は除く）
            $inside = 0;
            $outside = [];
            preg_match_all('/(?<![\w$.])' . $name . '\s*\(/', $script, $calls, PREG_OFFSET_CAPTURE);
            foreach ($calls[0] as [, $offset]) {
                if (preg_match('/\bfunction\s+$/', substr($script, 0, $offset)) === 1) {
                    continue;
                }
                if ($this->insideAny($offset, $bodies)) {
                    $inside++;
                } else {
                    $outside[] = trim(strtok(substr($script, $offset, 80), "\n"));
                }
            }

            foreach ($outside as $call) {
                $problems[] = "{$view}: パース中に {$fn}() を呼んでいる（{$call}）";
            }
            if ($byReference + $inside === 0) {
                $problems[] = "{$view}: 初回の {$fn}() が DOMContentLoaded に登録されていない";
            }
        }

        $this->assertSame(
            [],
            $problems,
            "初回の計測が Alpine の起動前に走る（サイドバーが x-cloak で隠れていて 220px 広く測る。"
            . "DOMContentLoaded の後に呼ぶこと）:\n" . implode("\n", $problems)
        );
    }

    /**
     * 幅・スクロール位置に触るインライン `<script>` を Blade ごとに集める。
     *
     * @return array{0: array<string, list<string>>, 1: int} [views からの相対パス => JS コメントを落とした本体の一覧, 走査したインライン script の数]
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
                $count++;
                $body = $this->withoutJsComments($body);
                if (preg_match(self::LAYOUT_ACCESS, $body) === 1) {
                    $found[str_replace($root, '', $file->getPathname())][] = $body;
                }
            }
        }
        ksort($found);

        return [$found, $count];
    }

    /** JS の `/* *&#47;` と行頭 `//` コメントを落とす（ScheduleBoardTest と同じ方式）。 */
    private function withoutJsComments(string $source): string
    {
        $source = preg_replace('#/\*.*?\*/#s', '', $source);

        // ⚠ 行頭アンカーを外さないこと。URL の `https://` まで消える。
        return preg_replace('#^[ \t]*//.*$#m', '', $source);
    }

    /** `$source[$open]` の `{` に対応する `}` の位置 */
    private function closingBrace(string $source, int $open, string $view): int
    {
        $this->assertSame('{', $source[$open] ?? null, "{$view}: 切り出しの起点が { でない");

        $depth = 0;
        for ($i = $open, $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}' && --$depth === 0) {
                return $i;
            }
        }

        $this->fail("{$view}: DOMContentLoaded のリスナーの波括弧が閉じていない");
    }

    /** @param list<array{0: int, 1: int}> $ranges */
    private function insideAny(int $offset, array $ranges): bool
    {
        foreach ($ranges as [$open, $close]) {
            if ($offset > $open && $offset < $close) {
                return true;
            }
        }

        return false;
    }
}
