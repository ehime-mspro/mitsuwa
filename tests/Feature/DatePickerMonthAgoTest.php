<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 日付ピッカーの「1ヶ月前」ボタンが、前月に同じ日が無い日でも前月の日付を選ぶこと（docs/RULES.md Bug #62 の JS 版）。
 *
 * 症状: 日付ピッカー（Alpine のコンポーネント関数 datePicker() の setMonthAgo。定義 7 か所・画面 8。DAD の _form は
 *   登録と編集の 2 画面に入る）が、前月にその日が無い日に押すと**当月**の日付を選んでいた（3/31 → 3/3、5/31 → 5/1、
 *   12/31 → 12/1。カレンダーの表示月も当月のまま）。月だけを 1 つ戻していたので、JS の Date が無い日（2/31）を
 *   翌月へ繰り越していた。
 * 決定（利用者・2026-09-24）: 前月に同じ日が無ければ**前月の末日で止める**（Excel の EDATE と同じ）。
 *   ふつうの日の結果は今までと同じ。
 *
 * ⚠ PHP のテストから JS は動かないので、node の vm で**画面に載るのと同じ関数**を実際に動かす
 *   （前例: Tenant\AreaBuildingMapTabTest::runMapScript()。文字列の形を見るだけでは振る舞いを押さえられない ＝
 *   Bug #47 の追記「振る舞いの正本は実駆動」）。関数は Blade のソースから波括弧の対応で切り出し、
 *   引数なしの new Date() と Date.now() だけを「今日」に差し替えて setMonthAgo() を呼ぶ。new なしの Date(...) は
 *   ブラウザと同じく「今」の文字列を返す（Date オブジェクトを返すと、new を落とした書き換えが緑のまま通る）。
 *   切り出した関数の中に Blade 構文が無いことを test_every_month_ago_definition_is_found_and_has_no_blade_syntax が
 *   確かめるので、node が動かす文字列とブラウザが受け取る文字列は同じ。
 * ⚠ コピーはファイル名を並べずに機械的に列挙する（Top trap #13）。コピーが増えても自動で検査対象に入り、
 *   下限（MIN_DEFINITIONS）で空振りを止める。
 *
 * ⚠ 振る舞いのテストの表の 1 行目（KEY_DAY = 2026-03-31）は、溢れる日の代表として置いている。3/31 は前月（2 月）に
 *   同じ日が無く、月だけを 1 つ戻す書き方だと 3/3（当月のまま）になる。先頭の自己検査（assertKeyDayOverflows()）は
 *   1 行目を溢れる日に固定しておく保険にすぎない —— 表にはほかにも溢れる日（3/29・5/31・12/31・2028-03-31）があり、
 *   掃引も全日を見るので、KEY_DAY を溢れない日（3/15 など）に変えても修正を戻せば赤になる
 *   （MonthEndOverflowTest::assertTodayOverflows() と違い、ここでは唯一の守りではない）。
 * ⚠ 期待値はリテラルで書き、掃引の答えは月の日数の表と閏年の規則で求める。実装と同じ式
 *   （new Date(年, 月, 0) や Math.min(…, lastDay)）で作ると同義反復になり、実装を壊しても緑になる。
 *
 * ⚠ 見えないもの（検出器の「死角」「過剰」は test_the_detectors_catch_what_they_should_and_ignore_the_rest に
 *   サンプルとして並べてあり、この一覧と対。見えるようにしたら、その行を「拾う」へ移す）:
 *   - ハーネスは関数を Blade の**ソース**から切り出すので、それを含む <script> が実際に描画・実行されるかは見えない
 *     （@if の中に入る・type="text/template" になる・@push に対応する @stack が無い。Bug #28 の型）
 *   - ボタンの配線（@click="setMonthAgo"。DAD は partial の _date-picker / _date-picker-row）と、Alpine の描画・
 *     カレンダーの見た目（実ブラウザで確かめる）
 *   - 「1ヶ月前」以外のショートカット（setWeekAgo は日を 7 日引くだけなので溢れない）
 *   - 列挙（DEFINITION）が拾わない定義: 引用符つきのキー（'setMonthAgo': function）・代入（p.setMonthAgo = function）。
 *     既存のコピーをこの形に書き換えると下限（MIN_DEFINITIONS）で止まるが、新しく増えたコピーは検査から漏れる
 *   - 関数の中の <!--<script>（--> で閉じないもの）。ブラウザはその後の </script> で <script> を閉じなくなるが、
 *     SCRIPT_END は </script だけを見る
 *   - 走査（test_views_do_not_move_months_with_set_month）が見るのは `.setMonth(` / `.setUTCMonth(` という**字面**だけで、
 *     月を動かす書き方すべてではない。次の書き方は拾わない:
 *     ① コンストラクタで月を足し引きする new Date(y, m - 1, d)（直した行から Math.min を外した形。
 *        元の不具合を再発させるいちばん自然な書き方で、これを止めるのは振る舞いのテストと掃引のほう）
 *     ② setFullYear(y, m…) で月を渡す形
 *     ③ d['setMonth'](…)・.setMonth.call(…)・別名（.setMonth.bind(…) など）で呼ぶ形
 *     ④ ドットの後に空白や改行を挟む形（d. の次の行に setMonth( を書く）
 *   - 走査はビューだけ。resources/js・app/・routes/・config/ に字面は無い（2026-09-24 実測で 0 件）
 */
class DatePickerMonthAgoTest extends TestCase
{
    /** 自己検査の「今日」。前月（2 月）に同じ日が無く、素朴な書き方だと当月へ溢れる日（クラスの注記を参照） */
    private const KEY_DAY = '2026-03-31';

    /** 定義の下限。走査が空振りして緑になるのを防ぐ（2026-09-24 実測で 7 か所） */
    private const MIN_DEFINITIONS = 7;

    /**
     * 走査した Blade の本数の下限（2026-09-24 実測で 249 本）。0 件を確かめるラチェットなので、ゆるくすると
     * 走査から黙って落ちたファイルを見逃す（200 だと 49 本落ちても緑）。
     */
    private const MIN_BLADE_FILES = 240;

    /** 掃引する日数: 2026-01-01〜2028-12-31（365 + 365 + 366） */
    private const SWEEP_DAYS = 1096;

    /**
     * setMonthAgo の定義（`setMonthAgo: function` のほか、アロー関数・メソッドの短縮記法も拾う）。
     * 呼び出し（@click="setMonthAgo"）には当たらない。
     */
    private const DEFINITION = '/\bsetMonthAgo\s*(?::|\([^()]*\)\s*\{)/';

    /**
     * `.setMonth(` / `.setUTCMonth(` という字面（月を動かす書き方すべてではない。クラスの docblock の「見えないもの」）。
     * ボタンの名前 setMonthAgo には当たらない（前に . が無く、直後に ( が無い）。
     */
    private const SET_MONTH = '/\.set(UTC)?Month\s*\(/';

    /**
     * Blade が書き換える構文: {{ }} / {!! !!} / ディレクティブ / コンポーネントのタグ / 生の PHP。
     * Blade は JS のコメントの中でも展開する（docs/RULES.md Bug #30）。コンポーネントのタグは < や </ の後の空白も
     * Blade が許す（ComponentTagCompiler の正規表現が `<\s*x[-\:]` と `<\/\s*x[-\:]`）ので、こちらも許す。
     */
    private const BLADE_SYNTAX = '/\{\{|\{!!|@[A-Za-z]|<\/?\s*x[-:]|<\?/';

    /**
     * スクリプトの終わりのタグ。ブラウザは <script> の中身を最初の </script（大文字小文字を問わず、空白・/・> が
     * 続くもの）で切るので、関数の中のコメントや文字列に書くとピッカーが丸ごと SyntaxError になる
     * （node は関数を最後まで動かすので、振る舞いのテストは緑のまま）。
     */
    private const SCRIPT_END = '/<\/script[\s\/>]/i';

    /**
     * ビューに `.setMonth(` / `.setUTCMonth(` の字面があってよいファイル（今は無い）。
     * どうしても要るときだけ、理由を書いて載せる（ClockReadScanTest::ALLOWED と同じ形）。
     *
     * @var array<string, array{0: int, 1: string}> 相対パス => [件数, 理由]
     */
    private const ALLOWED = [];

    /**
     * 「1ヶ月前」を定義するビューを機械的に列挙でき、どれも datePicker() をちょうど 1 つ持ち、その中に
     * Blade 構文が無いこと（＝ node が動かす文字列とブラウザが受け取る文字列が同じ）と、
     * </script が無いこと（＝ ブラウザがその関数を途中で切らずに 1 つの <script> として読む）。
     */
    public function test_every_month_ago_definition_is_found_and_has_no_blade_syntax(): void
    {
        foreach ($this->datePickerSources() as $view => $function) {
            $found = preg_match(self::BLADE_SYNTAX, $function, $m) === 1 ? $m[0] : null;
            $this->assertNull(
                $found,
                "{$view}: datePicker() の中に Blade 構文「{$found}」がある。"
                . 'Blade のソースとブラウザが受け取る文字列が食い違い、node で動かした結果が画面の動きを表さなくなる。'
                . 'Blade の値は関数の外（引数や別の <script>）から渡すこと'
            );

            $end = preg_match(self::SCRIPT_END, $function, $m) === 1 ? $m[0] : null;
            $this->assertNull(
                $end,
                "{$view}: datePicker() の中に「{$end}」がある。ブラウザは最初の </script で <script> を切るので、"
                . 'ピッカーが SyntaxError で丸ごと動かなくなる（node は最後まで動かすので振る舞いのテストは緑のまま）。'
                . 'コメントや文字列に書くなら <\/script> と逃がすこと'
            );
        }
    }

    /**
     * 前月に同じ日が無い日に押すと前月の末日を選び、カレンダーも前月へ移り、閉じること（すべてのコピー）。
     *
     * ⚠ 押す前にカレンダーを開いておく（ハーネスが open = true にしてから押す）。開いていないまま押すと、
     *   閉じる処理を消しても緑になる。
     * ⚠ 実際の画面には初期値がある（住宅は契約日、マンションは改定日の old 値）。「1ヶ月前」の基準は今日であって
     *   選択中の日ではないので、今日から遠い初期値で開いた行も置く（基準を選択中の日に変えると、その行が赤になる）。
     * ⚠ 食い違いはコピーごとに集めて最後にまとめて出す（複数のコピーが壊れたとき全件が一度に分かる）。
     */
    public function test_month_ago_stops_at_the_last_day_of_the_previous_month(): void
    {
        $this->assertKeyDayOverflows();

        $cases = [
            // [今日, 開いたときの初期値, 選ばれる日（isoValue）, カレンダーの表示月]
            [self::KEY_DAY, '', '2026-02-28', '2026-02'],           // 2/31 は無い（素朴な書き方だと当月の 3/3）
            ['2026-03-29', '', '2026-02-28', '2026-02'],            // 2026 年は閏年でないので 2/29 も無い
            ['2026-05-31', '', '2026-04-30', '2026-04'],            // 4/31 は無い
            ['2026-12-31', '', '2026-11-30', '2026-11'],            // 11/31 は無い
            ['2026-01-31', '', '2025-12-31', '2025-12'],            // 年をまたぐ（12 月には 31 日がある）
            ['2028-03-31', '', '2028-02-29', '2028-02'],            // 閏年の 2 月の末日
            ['2026-03-15', '', '2026-02-15', '2026-02'],            // ふつうの日は今までと同じ
            [self::KEY_DAY, '2020-06-15', '2026-02-28', '2026-02'], // 初期値があっても基準は今日（選択中の日ではない）
        ];

        $sources = $this->datePickerSources();
        $presses = array_map(fn (array $c) => ['today' => $c[0], 'initial' => $c[1]], $cases);
        $results = $this->runInNode($sources, ['presses' => $presses])['results'];

        $problems = [];
        foreach (array_keys($sources) as $view) {
            foreach ($cases as $i => [$today, $initial, $picked, $month]) {
                $got = $results[$view][$i] ?? [];
                $expected = ['isoValue' => $picked, 'view' => $month, 'open' => false];
                $actual = ['isoValue' => $got['isoValue'] ?? null, 'view' => $this->viewMonth($got), 'open' => $got['open'] ?? null];
                if ($actual !== $expected) {
                    $problems[] = "{$view} で今日が {$today}" . ($initial === '' ? '' : "（初期値 {$initial}）")
                        . ' のとき: ' . $this->show($actual) . '（期待 ' . $this->show($expected) . '）';
                }
            }
        }

        $this->assertSame(
            [],
            $problems,
            '「1ヶ月前」の結果が違う（isoValue ＝ 選ばれる日・view ＝ カレンダーの表示月・open ＝ 押したあと開いたままか）:'
            . "\n" . implode("\n", $problems)
        );
    }

    /**
     * 2026-01-01〜2028-12-31 の毎日について、すべてのコピーの結果（選ばれる日とカレンダーの表示月）が
     * 独立した暦の答えと一致すること。
     *
     * 答えはハーネスの中で「前月の日数の表（閏年の規則つき）と今日の日の小さいほう」として求める
     * （実装の new Date(年, 月, 0) を使わない）。前月に同じ日が無い日は 2026 年だけで 7 日ある
     * （3/29・3/30・3/31・5/31・7/31・10/31・12/31）。
     */
    public function test_month_ago_matches_an_independent_calendar_for_every_day_from_2026_to_2028(): void
    {
        $sources = $this->datePickerSources();
        $sweeps = $this->runInNode($sources, ['sweep' => ['fromYear' => 2026, 'toYear' => 2028]])['sweep'];

        $problems = [];
        foreach (array_keys($sources) as $view) {
            $sweep = $sweeps[$view] ?? null;
            if ($sweep === null) {
                $problems[] = "{$view}: 掃引の結果が返ってこない";

                continue;
            }
            if ($sweep['count'] !== self::SWEEP_DAYS) {
                $problems[] = "{$view}: 掃引した日数が {$sweep['count']} 日（2026-01-01〜2028-12-31 の "
                    . self::SWEEP_DAYS . ' 日と合わない。ハーネスの暦が壊れている）';
            }
            if ($sweep['mismatches'] !== []) {
                $problems[] = "{$view}: 前月の答えと食い違う日が " . count($sweep['mismatches']) . ' 日ある（最初の 5 日）:';
                foreach (array_slice($sweep['mismatches'], 0, 5) as $x) {
                    $problems[] = "  今日 {$x['today']} → {$x['isoValue']}（表示月 {$x['view']}）／答え {$x['expected']}";
                }
            }
        }

        $this->assertSame([], $problems, "独立した暦の答えと食い違う:\n" . implode("\n", $problems));
    }

    /**
     * ビューに `.setMonth(` / `.setUTCMonth(` という字面が 0 件であること（ラチェット）。
     *
     * 月だけを動かすと、移った先の月にその日が無いとき翌月へ溢れる（3/31 の 1 か月前が 3/3）。
     * 新しく書くときは、移った先の月にその日が無ければその月の末日で止める形にする（setMonthAgo の直し方と同じ）。
     * どうしても要るなら理由を書いて ALLOWED に載せる。
     *
     * ⚠ 見るのは字面だけ。new Date(y, m - 1, d) のような月を動かすほかの書き方は拾わない
     *   （クラスの docblock の「見えないもの」。元の不具合の再発を止めるのは振る舞いのテストと掃引のほう）。
     * ⚠ コメントの中にも当たる（コメントは落とさない）。注意書きには「setMonth」の直後に括弧を書かない。
     */
    public function test_views_do_not_move_months_with_set_month(): void
    {
        $blades = $this->bladeFiles();
        $this->assertGreaterThanOrEqual(self::MIN_BLADE_FILES, count($blades), '走査が空振りしている（Blade のファイルが少なすぎる）');

        $hits = [];
        foreach ($blades as $view => $absolute) {
            $source = (string) file_get_contents($absolute);
            if (preg_match_all(self::SET_MONTH, $source, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[0] as [, $offset]) {
                    $hits[$view][] = substr_count(substr($source, 0, $offset), "\n") + 1;
                }
            }
        }

        $problems = [];
        foreach ($hits as $view => $lines) {
            if (! isset(self::ALLOWED[$view])) {
                foreach ($lines as $line) {
                    $problems[] = "{$view}:{$line}";
                }

                continue;
            }
            if (count($lines) !== self::ALLOWED[$view][0]) {
                $problems[] = "{$view}: 件数が " . count($lines) . '（一覧は ' . self::ALLOWED[$view][0] . '）: 行 ' . implode(', ', $lines);
            }
        }
        foreach (array_keys(self::ALLOWED) as $view) {
            if (! isset($hits[$view])) {
                $problems[] = "{$view}: 一覧にあるのに字面が無い（古い項目）";
            }
        }

        $this->assertSame(
            [],
            $problems,
            'ビューに .setMonth( / .setUTCMonth( の字面がある。月だけを動かすと存在しない日で翌月へ溢れる'
            . '（3/31 の 1 か月前が 3/3）。移った先の月にその日が無ければその月の末日で止める形にし（前月なら前月の末日）、'
            . "どうしても要るなら理由を書いて ALLOWED に足す:\n"
            . implode("\n", $problems)
        );
    }

    /**
     * 検出用の正規表現（DEFINITION・SET_MONTH・BLADE_SYNTAX・SCRIPT_END）が、拾うべきものを拾い、ほかを拾わないこと。
     *
     * ⚠ 正規表現の枝は 1 つずつサンプルを通す。サンプルも実出現も無い枝は、消しても全テストが緑になる
     *   （Bug #45 / #61 ③。前例: ClockReadScanTest::test_the_detector_catches_what_it_should_and_ignores_the_rest）。
     * ⚠ 「死角」は拾うべきなのに拾わないもの、「過剰」は拾わなくてよいのに拾うもの（安全側＝赤に倒れる）の記録で、
     *   クラスの docblock の「見えないもの」と対。見えないこと・拾い過ぎることをここで固定する。
     *   検出器を広げて死角が見えるようになったら（ここが赤くなって気づける）、その行を「拾う」へ移す。
     */
    public function test_the_detectors_catch_what_they_should_and_ignore_the_rest(): void
    {
        $detectors = [
            'DEFINITION' => [
                'pattern' => self::DEFINITION,
                'caught' => [
                    'setMonthAgo: function () {',           // : の枝
                    'setMonthAgo : function () {',          // : の前の空白
                    'setMonthAgo: () => {',                 // アロー関数も : の枝
                    'setMonthAgo() {',                      // 短縮記法の枝
                    'setMonthAgo(today) {',                 // 短縮記法（引数あり）
                ],
                'ignored' => [
                    '@click="setMonthAgo"',                 // ボタンの配線（呼び出し）
                    'this.setMonthAgo();',                  // 呼び出し
                    "setMonthAgoLabel: '1ヶ月前'",          // 名前の前方一致
                    'resetMonthAgo: function () {',         // 名前の後方一致（\b）
                ],
                'blind' => [
                    "'setMonthAgo': function () {",         // 引用符つきのキー
                    'p.setMonthAgo = function () {',        // 代入
                ],
                'over' => [
                    "labels: { setMonthAgo: '1ヶ月前' }",   // 関数でない値（列挙されても datePicker( が無ければ赤）
                ],
            ],
            'SET_MONTH' => [
                'pattern' => self::SET_MONTH,
                'caught' => [
                    'd.setMonth(d.getMonth() - 1);',        // (UTC)? なし
                    'd.setUTCMonth(1);',                    // (UTC)? あり
                    'd.setMonth (1);',                      // ( の前の空白
                ],
                'ignored' => [
                    '@click="setMonthAgo"',                 // ボタンの名前（前に . が無い）
                    'this.setMonthAgo()',                   // 直後が ( でない
                    'setMonthAgo: function () {',           // 定義（前に . が無い）
                    '@click="setMonth(3)"',                 // ドットの無い呼び出し（Date のメソッドではない）
                    'd.getMonth() - 1',                     // 読むだけ
                ],
                'blind' => [
                    'new Date(y, m - 1, d)',                // ① コンストラクタで月を足し引きする
                    'd.setFullYear(y, m - 1, d);',          // ② setFullYear で月を渡す
                    "d['setMonth'](m - 1);",                // ③ 角括弧で呼ぶ
                    'd.setMonth.call(d, m - 1);',           // ③ call で呼ぶ
                    'var move = d.setMonth.bind(d); move(m - 1);', // ③ 別名で呼ぶ
                    "d.\n    setMonth(m - 1);",             // ④ ドットの後に改行
                ],
                'over' => [
                    '// d.setMonth(1) は使わない',           // コメントの中（落とさない）
                    'picker.setMonth(3);',                  // Date でないオブジェクトの同名メソッド
                ],
            ],
            'BLADE_SYNTAX' => [
                'pattern' => self::BLADE_SYNTAX,
                'caught' => [
                    '{{ $x }}',                             // 表示
                    '{!! $x !!}',                           // 生の表示
                    '@json($x)',                            // ディレクティブ
                    '@If($x)',                              // 大文字も展開される（Blade は 'compile'.ucfirst(名前) を method_exists で探し、PHP のメソッド名は大文字小文字を区別しない）
                    '// @if 注意',                          // JS のコメントの中でも展開される（Bug #30）
                    '<x-foo>',                              // コンポーネント
                    '</x-foo>',                             // コンポーネントの閉じタグ
                    '<x:bar>',                              // コンポーネント（: の書き方）
                    '< x-foo>',                             // < の後の空白も Blade は許す
                    '</ x-foo>',                            // </ の後の空白も Blade は許す
                    '<?php',                                // 生の PHP
                ],
                'ignored' => [
                    'a && b',                               // JS の論理積
                    'a @ b',                                // @ の直後が空白
                    '"@1"',                                 // @ の直後が数字（Blade も書き換えない）
                    'if (a) { b(); }',                      // 1 重の波括弧
                    'cells.length < 42',                    // 比較（< の後が x でない）
                    'i < x - 1',                            // < の後の x の次が - / : でない
                    '<xmp>',                                // x の次が - / : でない
                ],
                'blind' => [],
                'over' => [
                    "'x@example.com'",                      // @ の前が英字（Blade は書き換えないが、安全側で拾う）
                ],
            ],
            'SCRIPT_END' => [
                'pattern' => self::SCRIPT_END,
                'caught' => [
                    '// </script> は書かない',              // コメントの中でもブラウザは切る
                    "'</SCRIPT>'",                          // 大文字小文字を問わない
                    "'</script >'",                         // 空白
                    "'</script\n>'",                        // 改行
                    "'</script/>'",                         // スラッシュ
                ],
                'ignored' => [
                    "'<\\/script>'",                        // 逃がした書き方
                    "'</scripts>'",                         // 別の名前
                    "'<script>'",                           // 開きタグ
                ],
                'blind' => [
                    "'<!--<script>'",                       // --> で閉じないと、その後の </script> で閉じなくなる
                ],
                'over' => [],
            ],
        ];

        foreach ($detectors as $name => $d) {
            foreach ($d['caught'] as $sample) {
                $this->assertSame(1, preg_match($d['pattern'], $sample), "{$name} が拾えていない: " . $this->show($sample));
            }
            foreach ($d['ignored'] as $sample) {
                $this->assertSame(0, preg_match($d['pattern'], $sample), "{$name} が拾うべきでないものを拾った: " . $this->show($sample));
            }
            foreach ($d['blind'] as $sample) {
                $this->assertSame(
                    0,
                    preg_match($d['pattern'], $sample),
                    "{$name} の死角が見えるようになった: " . $this->show($sample)
                    . '（クラスの docblock の「見えないもの」から外し、この行を「拾う」へ移す）'
                );
            }
            foreach ($d['over'] as $sample) {
                $this->assertSame(
                    1,
                    preg_match($d['pattern'], $sample),
                    "{$name} が過剰に拾っていたものを拾わなくなった: " . $this->show($sample)
                    . '（危険側へ倒れていないか確かめ、この行を「拾わない」へ移す）'
                );
            }
        }
    }

    /**
     * 自己検査: KEY_DAY が「月だけを 1 つ戻す書き方」では当月のままになる（＝溢れる）日であること。
     *
     * 表の 1 行目を溢れる日の代表に固定しておく保険。表にはほかにも溢れる日があり掃引も全日を見るので、
     * KEY_DAY を溢れない日（3/15 など）に変えても修正を戻せば赤になるが、1 行目とその注記
     * （「素朴な書き方だと当月の 3/3」）が事実でなくなる。
     */
    private function assertKeyDayOverflows(): void
    {
        $naive = $this->runInNode([], ['naiveDays' => [self::KEY_DAY]])['naive'][self::KEY_DAY] ?? null;

        $this->assertSame(
            substr(self::KEY_DAY, 0, 7),
            is_string($naive) ? substr($naive, 0, 7) : null,
            'KEY_DAY（' . self::KEY_DAY . '）は、月だけを 1 つ戻す素朴な書き方でも前月へ移る（結果 ' . var_export($naive, true) . '）＝溢れない日。'
            . '表の 1 行目は溢れる日の代表として置いているので、前月に同じ日が無い日（3/31 など）にすること'
        );
    }

    /**
     * 「1ヶ月前」を定義するビューを機械的に列挙し、各ビューの datePicker() を切り出す。
     *
     * @return array<string, string> ビューの相対パス => datePicker() のソース
     */
    private function datePickerSources(): array
    {
        $sources = [];
        foreach ($this->bladeFiles() as $view => $absolute) {
            $source = (string) file_get_contents($absolute);
            if (preg_match(self::DEFINITION, $source) === 1) {
                $sources[$view] = $this->datePickerFunction($view, $source);
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_DEFINITIONS,
            count($sources),
            '「1ヶ月前」（setMonthAgo）の定義が ' . count($sources) . ' か所しか見つからない（下限 ' . self::MIN_DEFINITIONS . '）。'
            . '走査が空振りしているか、定義の書き方が変わった'
        );

        return $sources;
    }

    /**
     * ビューのソースから `function datePicker(` の関数全体を、波括弧の対応で切り出す。
     *
     * 文字列（'…' "…" `…`）とコメント（行コメント・ブロックコメント）の中の波括弧は数えない。
     */
    private function datePickerFunction(string $view, string $source): string
    {
        $this->assertSame(
            1,
            substr_count($source, 'function datePicker('),
            "{$view}: function datePicker( がちょうど 1 つではない（どの関数を動かすか決められない）"
        );

        $start = (int) strpos($source, 'function datePicker(');
        $open = strpos($source, '{', $start);
        $this->assertNotFalse($open, "{$view}: datePicker() の本体の「{」が見つからない");

        $depth = 0;
        $end = null;
        $quote = null;          // 文字列の中なら、開いた引用符
        $lineComment = false;
        $blockComment = false;
        for ($i = $open, $n = strlen($source); $i < $n; $i++) {
            $c = $source[$i];
            $next = $source[$i + 1] ?? '';
            if ($lineComment) {
                $lineComment = $c !== "\n";

                continue;
            }
            if ($blockComment) {
                if ($c === '*' && $next === '/') {
                    $blockComment = false;
                    $i++;
                }

                continue;
            }
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($c === '/' && ($next === '/' || $next === '*')) {
                $lineComment = $next === '/';
                $blockComment = $next === '*';
                $i++;

                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;

                continue;
            }
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}' && --$depth === 0) {
                $end = $i;
                break;
            }
        }
        $this->assertNotNull($end, "{$view}: datePicker() の波括弧が閉じていない（切り出せない）");

        $function = substr($source, $start, $end - $start + 1);
        $this->assertSame(
            1,
            preg_match(self::DEFINITION, $function),
            "{$view}: 切り出した datePicker() の中に setMonthAgo の定義が無い（定義が別の関数にある？）"
        );

        return $function;
    }

    /** @return array<string, string> resources/views/… の相対パス => 絶対パス（*.blade.php すべて） */
    private function bladeFiles(): array
    {
        $files = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $files[str_replace(base_path() . '/', '', $file->getPathname())] = $file->getPathname();
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * カレンダーの表示月を YYYY-MM で返す（JS の月は 0 始まり）。
     *
     * @param  array<string, mixed>  $got
     */
    private function viewMonth(array $got): ?string
    {
        return isset($got['viewYear'], $got['viewMonth'])
            ? sprintf('%04d-%02d', $got['viewYear'], $got['viewMonth'] + 1)
            : null;
    }

    /** 失敗文に出すための表記（改行などの制御文字も見えるように JSON で書く） */
    private function show(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * node のエラー（e.stack）を失敗文の 1 行にまとめる。
     *
     * 先頭 2 行（実行時のエラーなら「種類: 内容」と場所、構文エラーなら「ファイル:行」とその行のソース）を出し、
     * そこに誤りの種類の行（SyntaxError: … など）が無ければ添える。vm の構文エラーは先頭が「ファイル:行」
     * 「その行のソース」「^」で、種類の行は 4 行目以降に来るので、先頭 2 行だけでは何の誤りかが落ちる。
     */
    private function nodeErrorSummary(string $stack): string
    {
        $lines = explode("\n", $stack);
        $picked = array_slice($lines, 0, 2);
        if (preg_grep('/^\w*Error\b/', $picked) === []) {
            $type = preg_grep('/^\w*Error\b/', $lines);
            if ($type !== []) {
                $picked[] = reset($type);
            }
        }

        return implode(' / ', array_map('trim', $picked));
    }

    /**
     * datePicker() の各コピーを node の vm で実際に動かし、結果を 1 つの JSON で受け取る（アサートは PHP 側で行う）。
     *
     * ⚠ ハーネスはブラウザより寛容であってはいけない（AreaBuildingMapTabTest::runMapScript() と同じ方針）。
     *   datePicker() は DOM を触らない関数なので、文脈には何も渡さない（差し替えるのは引数なしの Date だけ）。
     *   new なしの Date(...) はブラウザと同じく引数を無視して「今」の文字列を返す（Date オブジェクトを返すと、
     *   直した 2 行や var now = new Date(); から new が抜けてもブラウザだけが壊れ、テストは緑のまま）。
     * ⚠ TZ は Asia/Tokyo に固定する（利用者のブラウザは日本時間。走らせるマシンの設定に結果を左右させない）。
     *
     * @param  array<string, string>  $sources  ビューの相対パス => datePicker() のソース
     * @param  array<string, mixed>  $request  naiveDays（自己検査の日）/ presses（今日と初期値の組。リテラルで見る）/
     *                                         sweep（掃引する年の範囲）
     * @return array<string, mixed>
     */
    private function runInNode(array $sources, array $request): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので日付ピッカーの実駆動を飛ばす');
        }

        // node のスタックトレースの行・桁を Blade ファイルの行・桁に合わせる（filename にビューの名前を渡すので、
        // 切り出した関数の中の行番号のままだと Blade の行に見えて紛らわしい）
        $positions = [];
        foreach (array_keys($sources) as $view) {
            $blade = (string) file_get_contents(base_path($view));
            $start = (int) strpos($blade, 'function datePicker(');
            $before = substr($blade, 0, $start);
            $positions[$view] = ['line' => substr_count($before, "\n"), 'column' => $start - (int) strrpos("\n" . $before, "\n")];
        }

        $dir = sys_get_temp_dir() . '/date-picker-month-ago-' . bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            file_put_contents(
                $dir . '/input.json',
                json_encode(
                    ['sources' => $sources, 'positions' => $positions] + $request,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );
            file_put_contents($dir . '/harness.js', self::HARNESS);

            $output = shell_exec(sprintf(
                'TZ=Asia/Tokyo %s %s %s 2>&1',
                escapeshellarg($node),
                escapeshellarg($dir . '/harness.js'),
                escapeshellarg($dir . '/input.json')
            ));
            $decoded = json_decode((string) $output, true);
            $this->assertIsArray($decoded, "node の実行に失敗した:\n" . $output);

            $errors = $decoded['errors'] ?? [];
            $this->assertSame(
                0,
                count($errors),
                "node で datePicker() を動かせなかったコピーがある:\n" . implode("\n", array_map(
                    fn (string $view, string $error) => "{$view}: " . $this->nodeErrorSummary($error),
                    array_keys($errors),
                    $errors
                ))
            );

            return $decoded;
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    /**
     * node で動かすハーネス。入力の JSON（argv[2]）を読み、結果の JSON を 1 つだけ標準出力へ書く。
     *
     * - naiveDays: 月だけを 1 つ戻す素朴な書き方の結果（自己検査）
     * - presses: 各コピーで、[今日, 初期値] の組ごとに、その初期値で開いたカレンダーで「1ヶ月前」を押した結果
     *   （isoValue / viewYear / viewMonth / open。順番は入力のまま）
     * - sweep: 各コピーで、fromYear〜toYear の毎日を押し、独立した暦の答えと食い違った日（count と mismatches）
     */
    private const HARNESS = <<<'JS'
const fs = require('fs');
const vm = require('vm');

const input = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));

function pad(n) { return String(n).padStart(2, '0'); }
function iso(y, m, d) { return y + '-' + pad(m) + '-' + pad(d); }
function parseDay(s) { const p = s.split('-').map(Number); return { y: p[0], m: p[1], d: p[2] }; }

// 独立した暦（実装と同じ式を使わない）: 月の日数の表と閏年の規則
function isLeap(y) { return (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0; }
function daysIn(y, m) { return [31, isLeap(y) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][m - 1]; }
function expectedMonthAgo(y, m, d) {
    const py = m === 1 ? y - 1 : y;
    const pm = m === 1 ? 12 : m - 1;
    const last = daysIn(py, pm);
    return iso(py, pm, d <= last ? d : last);
}

// 月だけを 1 つ戻す素朴な書き方（自己検査用。直す前の setMonthAgo と同じ計算）
function naiveMonthAgo(y, m, d) {
    const x = new Date(y, m - 1, d);
    x.setMonth(x.getMonth() - 1);
    return iso(x.getFullYear(), x.getMonth() + 1, x.getDate());
}

// 文脈の中で動かす: 引数なしの new Date() と Date.now() だけを「今日」（globalThis.__today）に差し替える
function installFakeDate() {
    var RealDate = Date;
    globalThis.__today = 0;
    function FakeDate() {
        // new なしの Date(...) は、ブラウザでは引数を無視して「今」の文字列を返す
        if (!new.target) return new RealDate(globalThis.__today).toString();
        if (arguments.length === 0) return new RealDate(globalThis.__today);
        return Reflect.construct(RealDate, arguments);
    }
    FakeDate.prototype = RealDate.prototype;
    FakeDate.now = function () { return globalThis.__today; };
    FakeDate.UTC = RealDate.UTC;
    FakeDate.parse = RealDate.parse;
    Date = FakeDate;
}

// スタックトレースに画面の名前と Blade ファイルの行・桁が出るように読み込む
function load(source, view, position) {
    const context = vm.createContext({});
    vm.runInContext('(' + installFakeDate.toString() + ')();', context);
    vm.runInContext(source, context, { filename: view, lineOffset: position.line || 0, columnOffset: position.column || 0 });
    if (typeof context.datePicker !== 'function') {
        throw new Error('datePicker が関数として定義されなかった');
    }
    return context;
}

// 今日（ローカル時刻の正午。日付の境目での時差の影響を避ける）に、初期値（'' なら未選択）で開いたカレンダーで
// 「1ヶ月前」を押す
function pressMonthAgo(context, y, m, d, initial) {
    context.__today = new Date(y, m - 1, d, 12, 0, 0).getTime();
    const p = context.datePicker(initial);
    p.open = true;
    p.setMonthAgo();
    return { isoValue: p.isoValue, viewYear: p.viewYear, viewMonth: p.viewMonth, open: p.open };
}

function sweep(context, fromYear, toYear) {
    let count = 0;
    const mismatches = [];
    for (let y = fromYear; y <= toYear; y++) {
        for (let m = 1; m <= 12; m++) {
            for (let d = 1; d <= daysIn(y, m); d++) {
                count++;
                const got = pressMonthAgo(context, y, m, d, '');
                const want = expectedMonthAgo(y, m, d);
                const view = typeof got.viewYear === 'number' && typeof got.viewMonth === 'number'
                    ? got.viewYear + '-' + pad(got.viewMonth + 1)
                    : null;
                if (got.isoValue !== want || view !== want.slice(0, 7)) {
                    mismatches.push({ today: iso(y, m, d), isoValue: got.isoValue, view: view, expected: want });
                }
            }
        }
    }
    return { count: count, mismatches: mismatches };
}

const out = { naive: {}, results: {}, sweep: {}, errors: {} };

(input.naiveDays || []).forEach(function (day) {
    const t = parseDay(day);
    out.naive[day] = naiveMonthAgo(t.y, t.m, t.d);
});

Object.keys(input.sources || {}).forEach(function (view) {
    try {
        const context = load(input.sources[view], view, (input.positions || {})[view] || {});
        if (input.presses) {
            out.results[view] = input.presses.map(function (press) {
                const t = parseDay(press.today);
                return pressMonthAgo(context, t.y, t.m, t.d, press.initial);
            });
        }
        if (input.sweep) {
            out.sweep[view] = sweep(context, input.sweep.fromYear, input.sweep.toYear);
        }
    } catch (e) {
        out.errors[view] = String((e && e.stack) || e);
    }
});

process.stdout.write(JSON.stringify(out));
JS;
}
