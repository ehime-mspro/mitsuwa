<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * モバイル（375px 幅）でのレイアウト崩れを構造で固定する。
 *
 * 【背景】2026-08-03 に実ブラウザ 375px で 80 ページを実測し、2 類型の崩れを確認した。
 *
 *   ① 横スクロール型（11 ページ / 最大 +258px）
 *      main.scrollWidth > main.clientWidth。インラインの
 *      `grid-template-columns: repeat(4, 1fr)` などが原因。
 *
 *   ② 無音の切り落とし型（16 ページ / 最大 559px 欠落）
 *      幅広 table を overflow:hidden の親が切る。**スクロールバーが出ないので
 *      操作列に到達できない。** mansion/parking-contracts は編集・削除ボタンごと
 *      画面外だった。
 *
 * ⚠ ②は「main の横スクロール」を測るだけでは原理的に検出できない
 *   （main.scrollWidth は 375 のまま）。両方を別々に固定する必要がある。
 *
 * ⚠ 検証は「HTML に出るか」では不可能で、本来はブラウザで計測するしかない。
 *   ここではブラウザ無しでも再発を捕まえられるよう、**崩れの原因になる構造**を
 *   ソース側で固定する。
 *
 * ⚠ Bug #45 の教訓により「直したファイルを列挙する」形にはしない。
 *   対象を全件走査し、条件を満たさないものがあれば落とす。
 *   新しいビューが無検査のまま増えることが原理的に無くなる。
 */
class MobileLayoutTest extends TestCase
{
    /**
     * 横スクロールを提供する祖先として認めるもの。
     *
     * このアプリには 3 通りの正当なパターンが併存している（Bug #45 の
     * 「単一の正準パターンを機械適用しない」に従い、どれも認める）:
     *   - .scroll-hint-inner … グラデーション付きの共通パターン（app.css / app.js）
     *   - .scroll-area       … zeal / mansion 一覧が使う独自クラス（各ビューの <style>）
     *   - overflow-x: auto   … インライン指定
     *   - overflow-y: auto   … CSS 仕様上 overflow-x が visible なら auto に計算されるため
     *                          横方向にもスクロールする
     */
    private const SCROLLABLE_ANCESTOR = '/scroll-hint-inner|scroll-area|overflow-x:\s*auto|overflow-x-auto|overflow-y:\s*auto|overflow:\s*auto/';

    /**
     * 横スクロールの祖先が無くてよい <table> を持つファイル。
     * 追加するときは「なぜ 375px の画面で崩れないか」を必ず書くこと。
     */
    private const TABLE_SCROLL_EXEMPT = [
        // mPDF に渡す A4 の紙面。ブラウザの画面には出さない（段階4a の PDF）
        'approvals/requests/pdf.blade.php'         => 'mPDF に渡す紙面で画面に出さない',
        'approvals/requests/_pdf_footer.blade.php' => 'mPDF に渡す紙面で画面に出さない',
    ];

    /**
     * インラインの多列グリッドのうち、モバイル用クラスが無くてよいもの。
     * 追加するときは「なぜ 375px で壊れないか」を必ず書くこと。
     */
    private const GRID_EXEMPT = [
        // auto-fill + minmax(200px, 1fr) は列数が幅に応じて自動で減るため本質的にレスポンシブ
        'realestate/projects/lots.blade.php' => 'auto-fill minmax で列数が自動調整される',
    ];

    /**
     * table-layout: fixed でありながら min-width を持たなくてよい表。
     * いずれも「固定 96px + auto + 固定 120px」の 3 列で、375px 画面の
     * 内容領域（約 343px）に収まるため、潰れずに読める。
     * min-width を足すとむしろ不要な横スクロールが出る。
     */
    private const FIXED_LAYOUT_NO_MIN_WIDTH_EXEMPT = [
        'admin/master/re-cost-items/index.blade.php',
        'admin/master/structure-types/index.blade.php',
        'admin/master/usage-types/index.blade.php',
        'admin/master/zoning-types/index.blade.php',
    ];

    /** @return array<int, string> */
    private function bladeFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(resource_path('views') . '/', '', $path);
    }

    /**
     * 走査が空振りしていると以下のテストが「対象ゼロで緑」になる。先に固定する。
     */
    public function test_scanner_actually_finds_blade_files(): void
    {
        $this->assertGreaterThan(200, count($this->bladeFiles()), 'Blade ファイルの走査が機能していない');
    }

    /**
     * すべての <table> が横スクロール可能な祖先を持つこと（崩れ ②の固定）。
     *
     * ⚠ ファイル単位の grep では駄目。実際 realestate/procurements/show.blade.php は
     *   ファイル内の別の表に overflow-x があるせいで「対策済み」に見えていたが、
     *   原価テーブル（:301）だけが無防備だった。**div の入れ子を実際に辿って
     *   table ごとに判定する。**
     */
    public function test_every_table_has_a_horizontally_scrollable_ancestor(): void
    {
        $offenders = [];
        $tableCount = 0;

        foreach ($this->bladeFiles() as $path) {
            $src = file_get_contents($path);

            if (! str_contains($src, '<table')) {
                continue;
            }

            if (isset(self::TABLE_SCROLL_EXEMPT[$this->relative($path)])) {
                continue;
            }

            $stack = [];
            preg_match_all('/<(\/?)(div|table)\b([^>]*)>/i', $src, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

            foreach ($matches as $m) {
                $closing = $m[1][0] !== '';
                $name    = strtolower($m[2][0]);
                $attrs   = $m[3][0];

                if ($name === 'table') {
                    if ($closing) {
                        continue;
                    }

                    $tableCount++;
                    $scrollable = false;

                    foreach ($stack as $ancestor) {
                        if (preg_match(self::SCROLLABLE_ANCESTOR, $ancestor)) {
                            $scrollable = true;
                            break;
                        }
                    }

                    if (! $scrollable) {
                        $line = substr_count(substr($src, 0, $m[0][1]), "\n") + 1;
                        $offenders[] = $this->relative($path) . ':' . $line;
                    }

                    continue;
                }

                if ($closing) {
                    array_pop($stack);
                } elseif (! str_ends_with(rtrim($attrs), '/')) {
                    $stack[] = $attrs;
                }
            }
        }

        // 走査が壊れて 0 件になったのを「緑」と誤読しないための下限
        $this->assertGreaterThanOrEqual(90, $tableCount, '<table> の走査が機能していない（空振り防止）');

        $this->assertSame(
            [],
            $offenders,
            "横スクロールできない <table> がある。375px では右側の列（操作列を含む）に到達できない。\n"
            . "既存パターンで囲むこと:\n"
            . "  <div class=\"scroll-hint at-start\">\n"
            . "  <div class=\"scroll-hint-inner\">\n"
            . "      <table … style=\"min-width: NNNpx\">…</table>\n"
            . "  </div>\n"
            . "  <div class=\"scroll-hint-text\">← スクロールできます →</div>\n"
            . "  </div>\n"
            . '該当: ' . implode(', ', $offenders)
        );
    }

    /**
     * table-layout: fixed の表が横スクロール枠の中にあるとき、min-width を持つこと。
     *
     * ⚠ 枠で囲むだけでは直らない。table-layout: fixed かつ width:100% の表は
     *   親の幅に合わせて**列が潰れる**ので、スクロールが発生せず読めないままになる。
     *   実測でも「枠は付いているのに 375px で列が 30px しかない」状態が作れてしまう。
     */
    public function test_fixed_layout_tables_in_a_scroller_declare_a_min_width(): void
    {
        $offenders = [];
        $checked   = 0;

        foreach ($this->bladeFiles() as $path) {
            $src = file_get_contents($path);

            if (! str_contains($src, '<table')) {
                continue;
            }

            $stack = [];
            preg_match_all('/<(\/?)(div|table)\b([^>]*)>/i', $src, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

            foreach ($matches as $m) {
                $closing = $m[1][0] !== '';
                $name    = strtolower($m[2][0]);
                $attrs   = $m[3][0];

                if ($name === 'table') {
                    if ($closing) {
                        continue;
                    }

                    if (! preg_match('/table-layout:\s*fixed/', $attrs)) {
                        continue;
                    }

                    $inScroller = false;
                    foreach ($stack as $ancestor) {
                        if (preg_match(self::SCROLLABLE_ANCESTOR, $ancestor)) {
                            $inScroller = true;
                            break;
                        }
                    }

                    if (! $inScroller) {
                        continue;
                    }

                    $checked++;

                    if (in_array($this->relative($path), self::FIXED_LAYOUT_NO_MIN_WIDTH_EXEMPT, true)) {
                        continue;
                    }

                    // インライン min-width でも Tailwind の min-w-[NNNpx] でもよい
                    if (preg_match('/min-width:|min-w-\[/', $attrs)) {
                        continue;
                    }

                    $line = substr_count(substr($src, 0, $m[0][1]), "\n") + 1;
                    $offenders[] = $this->relative($path) . ':' . $line;

                    continue;
                }

                if ($closing) {
                    array_pop($stack);
                } elseif (! str_ends_with(rtrim($attrs), '/')) {
                    $stack[] = $attrs;
                }
            }
        }

        $this->assertGreaterThanOrEqual(15, $checked, 'table-layout: fixed の走査が機能していない（空振り防止）');

        $this->assertSame(
            [],
            $offenders,
            "table-layout: fixed の表が横スクロール枠の中にあるのに min-width が無い。\n"
            . "枠があっても列が潰れるだけでスクロールしない。列数に応じた min-width を付けること。\n"
            . '該当: ' . implode(', ', $offenders)
        );
    }

    /**
     * インラインで 3 列以上（または固定 px を含む）のグリッドを指定している要素は、
     * モバイル用クラスを持つこと（崩れ ①の固定）。
     *
     * ⚠ インラインの style は media query より強いので、CSS 側だけでは直せない。
     *   .grid-stack-sm / .grid-2col-sm / .dl-stack-sm のいずれかを要素に付けて、
     *   モバイル幅のときだけ列指定を上書きする。
     */
    public function test_inline_multi_column_grids_declare_a_mobile_class(): void
    {
        $offenders = [];
        $gridCount = 0;

        foreach ($this->bladeFiles() as $path) {
            $src = file_get_contents($path);

            if (! str_contains($src, 'grid-template-columns')) {
                continue;
            }

            // <style> ブロック内はクラス定義なので対象外（media query で直せる）
            $styleRegions = [];
            if (preg_match_all('/<style\b.*?<\/style>/s', $src, $sm, PREG_OFFSET_CAPTURE)) {
                foreach ($sm[0] as $s) {
                    $styleRegions[] = [$s[1], $s[1] + strlen($s[0])];
                }
            }

            preg_match_all(
                '/style="([^"]*grid-template-columns:\s*([^;"]+)[^"]*)"/',
                $src,
                $matches,
                PREG_OFFSET_CAPTURE | PREG_SET_ORDER
            );

            foreach ($matches as $m) {
                $pos = $m[0][1];

                foreach ($styleRegions as [$from, $to]) {
                    if ($pos >= $from && $pos < $to) {
                        continue 2;
                    }
                }

                $value = trim($m[2][0]);

                if (! $this->isRiskyGrid($value)) {
                    continue;
                }

                $gridCount++;

                if (isset(self::GRID_EXEMPT[$this->relative($path)])) {
                    continue;
                }

                if (preg_match('/grid-stack-sm|grid-2col-sm|dl-stack-sm/', $this->enclosingTag($src, $pos))) {
                    continue;
                }

                $line = substr_count(substr($src, 0, $pos), "\n") + 1;
                $offenders[] = $this->relative($path) . ':' . $line . ' (' . $value . ')';
            }
        }

        $this->assertGreaterThanOrEqual(40, $gridCount, '多列グリッドの走査が機能していない（空振り防止）');

        $this->assertSame(
            [],
            $offenders,
            "モバイルで段組みが落ちない多列グリッドがある。375px で横スクロールが出る。\n"
            . "要素に .grid-stack-sm（1 列へ）/ .grid-2col-sm（2 列へ）/ .dl-stack-sm（定義リスト）\n"
            . "のいずれかを付けること。インライン style は触らなくてよい。\n"
            . '該当: ' . implode(', ', $offenders)
        );
    }

    /**
     * 3 列以上、または固定 px トラックを含むか。
     * 2 列（1fr 1fr）は 375px でも各 165px 取れるため対象外。
     */
    private function isRiskyGrid(string $value): bool
    {
        if (str_contains($value, '{{')) {
            return true;    // 動的な列数は最大値が読めないので危険側に倒す
        }

        if (preg_match('/repeat\(\s*(\d+)\s*,/', $value, $m)) {
            return (int) $m[1] >= 3;
        }

        if (str_contains($value, 'repeat(')) {
            return false;   // auto-fill / auto-fit は幅に応じて列数が変わる
        }

        if (preg_match('/\d+px/', $value)) {
            return true;
        }

        return count(preg_split('/\s+/', $value)) >= 3;
    }

    private function enclosingTag(string $src, int $pos): string
    {
        $start = strrpos(substr($src, 0, $pos), '<');

        while ($start !== false && ! preg_match('/^<[a-zA-Z]/', substr($src, $start, 2))) {
            $start = strrpos(substr($src, 0, $start), '<');
        }

        if ($start === false) {
            return '';
        }

        $end = strpos($src, '>', $pos);

        return substr($src, $start, $end === false ? 200 : $end - $start);
    }

    /**
     * ユーティリティクラスがスタイルシートに実在し、モバイル幅の media query に
     * 入っていること。Blade 側だけ直してもクラスが無ければ無音で効かない
     * （Bug #28 と同じ「呼び出し側と定義側を対で検証する」）。
     */
    public function test_mobile_grid_utilities_exist_in_stylesheet(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*640px\)\s*\{[^}]*\.grid-stack-sm[^}]*\}/s',
            $css,
            '.grid-stack-sm がモバイル幅の media query に無い'
        );

        foreach (['grid-stack-sm', 'grid-2col-sm', 'dl-stack-sm'] as $class) {
            $this->assertStringContainsString(
                '.' . $class,
                $css,
                ".{$class} が app.css に無い（Blade 側でクラスを付けても無音で効かない）"
            );

            // インライン宣言に勝つ必要があるので !important は load-bearing
            $this->assertMatchesRegularExpression(
                '/\.' . preg_quote($class, '/') . '\s*\{[^}]*!important/',
                $css,
                ".{$class} に !important が無い。インラインの grid-template-columns に負けて無音で効かない"
            );
        }
    }

    /**
     * .scroll-hint の見た目と挙動は CSS と JS の両方が揃って初めて成立する。
     * 片方が消えても HTML としては妥当なので、対で固定する（Bug #28 と同じ構図）。
     */
    public function test_scroll_hint_css_and_js_are_both_present(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $js  = file_get_contents(resource_path('js/app.js'));

        $this->assertMatchesRegularExpression(
            '/\.scroll-hint-inner\s*\{[^}]*overflow-x:\s*auto/s',
            $css,
            '.scroll-hint-inner の overflow-x: auto が失われている'
        );

        $this->assertStringContainsString(
            'scroll-hint-inner',
            $js,
            'scroll-hint の状態制御 JS が失われている（グラデーションが出っぱなしになる）'
        );
    }

    // ============================================================
    // 2026-10-07 に実ブラウザ 375px で測った死角（賃貸マンションの契約の詳細・改定・解約・入居申込書・部屋の登録）
    // ============================================================

    /**
     * 2 列のインライングリッドは「375px でも各 165px 取れる」として上の走査の外だった。だが中に、同じビューの `<style>` で
     * 固定幅のラベル（`.ms-info-row { grid-template-columns: 140px 1fr }` など）を持つ行が入ると、1 列の幅が足りず
     * 122px まで横にはみ出した（`1fr` は `minmax(auto, 1fr)` の略で、中身の幅が下限になる。Bug #29 と同じ形）。
     * そういう 2 列には mobile のクラス（1 列へ落とす）を求める。
     */
    public function test_two_column_grids_holding_fixed_label_rows_declare_a_mobile_class(): void
    {
        $found = 0;
        $offenders = [];
        foreach ($this->bladeFiles() as $path) {
            $src = file_get_contents($path);
            $labelClasses = $this->fixedTrackClasses($src, 1);
            if ($labelClasses === []) {
                continue;
            }
            preg_match_all('/<div\b[^>]*style="[^"]*grid-template-columns:\s*([^;"]+)[^"]*"[^>]*>/', $src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            foreach ($m as $grid) {
                if ($this->trackCount(trim($grid[1][0])) !== 2) {
                    continue;
                }
                $body = $this->divBody($src, $grid[0][1]);
                $holds = false;
                foreach ($labelClasses as $class) {
                    if (preg_match('/class="[^"]*(?<![\w-])' . preg_quote($class, '/') . '(?![\w-])/', $body)) {
                        $holds = true;
                    }
                }
                if (! $holds) {
                    continue;
                }
                $found++;
                if (! preg_match('/grid-stack-sm|dl-stack-sm/', $grid[0][0])) {
                    $offenders[] = $this->relative($path) . ':' . (substr_count(substr($src, 0, $grid[0][1]), "\n") + 1);
                }
            }
        }

        $this->assertGreaterThanOrEqual(8, $found, '固定幅のラベルの行を持つ 2 列を拾えていない（走査の空振り）');
        $this->assertSame([], $offenders, '固定幅のラベルの行を持つ 2 列に .grid-stack-sm が無い（375px で横にはみ出す）。該当: ' . implode(', ', $offenders));
    }

    /**
     * `<style>` の中のグリッドは「media query で直せる」として上の走査の外だった。だが直していなかった（改定の「現行 → 新」の
     * `1fr 40px 1fr` が 375px で 103〜116px はみ出した）。固定幅の列を持つ 3 列以上のグリッドのクラスは、同じビューの
     * `@media (max-width: …)` で上書きする。
     */
    public function test_style_block_grids_with_fixed_tracks_are_overridden_on_mobile(): void
    {
        $found = 0;
        $offenders = [];
        foreach ($this->bladeFiles() as $path) {
            $src = file_get_contents($path);
            foreach ($this->fixedTrackClasses($src, 3) as $class) {
                $found++;
                if (! preg_match('/@media\s*\(max-width:\s*\d+px\)\s*\{[^@]*?\.' . preg_quote($class, '/') . '\s*\{[^}]*grid-template-columns/s', $src)) {
                    $offenders[] = $this->relative($path) . ' .' . $class;
                }
            }
        }

        $this->assertGreaterThanOrEqual(9, $found, '<style> の固定幅の列を持つグリッドを拾えていない（走査の空振り）');
        $this->assertSame([], $offenders, '固定幅の列を持つ 3 列以上のグリッドが、狭い画面で上書きされていない（375px で横にはみ出す）。該当: ' . implode(', ', $offenders));
    }

    /**
     * `flex: 1` の入力欄は、中身の幅（size の既定 20 文字ぶん）を下限に持つので縮まない。単位（円・㎡）と並べた 2 列の中で
     * 21px はみ出した（部屋の登録）。`min-width: 0`（か、意図した最小の幅）を併せて書く。
     */
    public function test_flexible_inputs_can_shrink(): void
    {
        $found = 0;
        $offenders = [];
        foreach ($this->bladeFiles() as $path) {
            $src = file_get_contents($path);
            preg_match_all('/<(input|select|textarea)\b[^>]*\bstyle="([^"]*)"[^>]*>/', $src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            foreach ($m as $tag) {
                if (! preg_match('/(?<![\w-])flex:\s*1\s*(;|$)/', $tag[2][0])) {
                    continue;
                }
                $found++;
                // 最小の幅を明示していれば、書いた人が決めた下限なので通す（検索欄の `min-width: 180px` など）
                if (! preg_match('/(?<![\w-])min-width:\s*[^;]+/', $tag[2][0])) {
                    $offenders[] = $this->relative($path) . ':' . (substr_count(substr($src, 0, $tag[0][1]), "\n") + 1);
                }
            }
        }

        $this->assertGreaterThanOrEqual(23, $found, '`flex: 1` の入力欄を拾えていない（走査の空振り）');
        $this->assertSame([], $offenders, '`flex: 1` の入力欄に `min-width` が無い（狭い画面で縮まずにはみ出す）。該当: ' . implode(', ', $offenders));
    }

    /**
     * 一覧の絞り込みの行（`<form>` の中に select・input・textarea が 2 つ以上で、横に並べる flex）は折り返すこと。
     *
     * 2026-10-08 に使い捨ての環境で一覧 42 本を 375〜1440px で測ると、`flex flex-col sm:flex-row` で折り返さない行が、
     * 640px 以上で横 1 列になったとたん select（中身の幅）と検索欄（最小 140px）が収まらず、`main` を最大 164px はみ出した
     * （駐車場契約・仕入れ案件・投資・修繕・部屋契約。1024px でもサイドバーの分だけ狭くなり、仕入れ案件は 4px・駐車場契約は 16px）。
     * select の幅は選択肢の中身（物件名など）で決まるので、本番の長い名前ではほかの一覧もはみ出しうる → 形で止める。
     *
     * ⚠ 死角: `<form>` の中の div で横に並べる行（form 自身は flex でない）は見ない。本当に収まるかは実ブラウザで測る。
     */
    public function test_filter_forms_laid_out_in_a_row_can_wrap(): void
    {
        $found = 0;
        $offenders = [];
        foreach ($this->bladeFiles() as $path) {
            foreach ($this->rowFilterForms(file_get_contents($path)) as [$line, $wraps]) {
                $found++;
                if (! $wraps) {
                    $offenders[] = $this->relative($path) . ':' . $line;
                }
            }
        }

        $this->assertGreaterThanOrEqual(20, $found, '横に並べる絞り込みの行を拾えていない（走査の空振り。2026-10-08 の実測は 26）');
        $this->assertSame([], $offenders, "横に並べる絞り込みの行が折り返さない（flex-wrap が無い。select の中身が長いと main をはみ出す）:\n" . implode("\n", $offenders));
    }

    public function test_sample_row_filter_forms_are_told_apart(): void
    {
        $describe = fn (string $src) => array_map(fn ($form) => $form[1] ? 'wraps' : 'no', $this->rowFilterForms($src));

        $this->assertSame(['no'], $describe('<form class="flex flex-col sm:flex-row gap-2"><select name="a"></select><input name="q"></form>'));
        $this->assertSame(['wraps'], $describe('<form class="flex flex-col sm:flex-row sm:flex-wrap gap-2"><select name="a"></select><input name="q"></form>'));
        $this->assertSame(['no'], $describe('<form class="flex items-center gap-2"><select name="a"></select><select name="b"></select></form>'), '既定の向き（横）の flex を見落としている');
        $this->assertSame(['wraps'], $describe('<form class="flex flex-wrap items-center"><select name="a"></select><textarea name="b"></textarea></form>'));
        $this->assertSame([], $describe('<form class="flex flex-col gap-2"><select name="a"></select><input name="q"></form>'), '縦に積む行を拾っている');
        $this->assertSame([], $describe('<form class="flex sm:flex-row"><input type="hidden" name="_token"><input name="q"></form>'), 'hidden の input を数えている');
        $this->assertSame([], $describe('<form class="grid sm:flex-row-x"><select name="a"></select><input name="q"></form>'), 'クラス名の一部を flex-row と数えている');
        $this->assertSame(['no'], $describe("<form method=\"GET\"\n      class=\"flex flex-col sm:flex-row\" onchange=\"a > b\"><select></select><input></form>"), '属性の中の > でタグを切っている');
        $this->assertSame([], $describe('<form class="flex"><input name="a"></form><form class="grid"><select name="b"></select></form>'), '次の form の部品まで数えている');
    }

    /**
     * 横に並べる flex の `<form>` で、中に select・input（hidden 以外）・textarea が 2 つ以上あるもの。
     *
     * @return list<array{0: int, 1: bool}> [行, 折り返すか]
     */
    private function rowFilterForms(string $src): array
    {
        $forms = [];
        preg_match_all('/<form\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/', $src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($m as $form) {
            $class = preg_match('/\sclass="([^"]*)"/', $form[1][0], $c) ? preg_split('/\s+/', trim($c[1])) : [];
            $row = (bool) array_filter($class, fn ($token) => preg_match('/^((sm|md|lg|xl):)?flex-row$/', $token))
                || (in_array('flex', $class, true) && ! in_array('flex-col', $class, true));
            if (! $row) {
                continue;
            }
            $start = $form[0][1] + strlen($form[0][0]);
            $end = strpos($src, '</form>', $start);
            $body = substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
            if (preg_match_all('/<(select|textarea)\b|<input\b(?![^>]*type="hidden")/', $body) < 2) {
                continue;
            }
            $forms[] = [
                substr_count(substr($src, 0, $form[0][1]), "\n") + 1,
                (bool) array_filter($class, fn ($token) => preg_match('/^((sm|md|lg|xl):)?flex-wrap$/', $token)),
            ];
        }

        return $forms;
    }

    /**
     * 同じビューの `<style>` の中で、固定幅（px）の列を持ち、列が $minTracks 以上のグリッドのクラス名（`@media` の中の上書きは除く）。
     *
     * @return array<int, string>
     */
    private function fixedTrackClasses(string $src, int $minTracks): array
    {
        $classes = [];
        preg_match_all('/<style\b[^>]*>(.*?)<\/style>/s', $src, $styles);
        foreach ($styles[1] as $css) {
            // @media の中（狭い画面の上書き）は数えない。中の規則の波括弧を 1 段まで許して外す
            $plain = (string) preg_replace('/@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/s', '', $css);
            preg_match_all('/([^{}]+)\{([^}]*)\}/', $plain, $rules, PREG_SET_ORDER);
            foreach ($rules as $rule) {
                if (! preg_match('/grid-template-columns:\s*([^;]+)/', $rule[2], $value)) {
                    continue;
                }
                $value = trim($value[1]);
                if (! preg_match('/\d+px/', $value) || $this->trackCount($value) < max(2, $minTracks)) {
                    continue;
                }
                if (preg_match_all('/\.([\w-]+)/', $rule[1], $names) && $names[1] !== []) {
                    $classes[] = end($names[1]);
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /** グリッドの列の数（`repeat(2, 1fr)` は 2。`minmax(0, 1fr)` は 1 列と数える） */
    private function trackCount(string $value): int
    {
        if (preg_match('/^repeat\(\s*(\d+)\s*,/', $value, $m)) {
            return (int) $m[1];
        }
        $flat = (string) preg_replace('/\([^()]*\)/', '', $value);

        return count(preg_split('/\s+/', trim($flat)));
    }

    /** $start の `<div` から、対応する `</div>` までの中身 */
    private function divBody(string $src, int $start): string
    {
        preg_match_all('/<div\b|<\/div>/', $src, $tokens, PREG_OFFSET_CAPTURE, $start);
        $depth = 0;
        foreach ($tokens[0] as [$token, $offset]) {
            $depth += $token === '</div>' ? -1 : 1;
            if ($depth === 0) {
                return substr($src, $start, $offset - $start);
            }
        }

        return substr($src, $start);
    }
}
