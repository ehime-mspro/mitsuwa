<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 日付ピッカーの「1ヶ月前」ボタンが、前月に同じ日が無い日でも前月の日付を選ぶこと（docs/RULES.md Bug #62 の JS 版）。
 *
 * 症状: 7 画面の日付ピッカー（Alpine のコンポーネント関数 datePicker() の setMonthAgo）が、前月にその日が無い日に
 *   押すと**当月**の日付を選んでいた（3/31 → 3/3、5/31 → 5/1、12/31 → 12/1。カレンダーの表示月も当月のまま）。
 *   月だけを 1 つ戻していたので、JS の Date が無い日（2/31）を翌月へ繰り越していた。
 * 決定（利用者・2026-09-24）: 前月に同じ日が無ければ**前月の末日で止める**（Excel の EDATE と同じ）。
 *   ふつうの日の結果は今までと同じ。
 *
 * ⚠ PHP のテストから JS は動かないので、node の vm で**画面に載るのと同じ関数**を実際に動かす
 *   （前例: Tenant\AreaBuildingMapTabTest::runMapScript()。文字列の形を見るだけでは振る舞いを押さえられない ＝
 *   Bug #47 の追記「振る舞いの正本は実駆動」）。関数は Blade のソースから波括弧の対応で切り出し、
 *   引数なしの new Date() と Date.now() だけを「今日」に差し替えて setMonthAgo() を呼ぶ。
 *   切り出した関数の中に Blade 構文が無いことを test_every_month_ago_definition_is_found_and_has_no_blade_syntax が
 *   確かめるので、node が動かす文字列とブラウザが受け取る文字列は同じ。
 * ⚠ コピーはファイル名を並べずに機械的に列挙する（Top trap #13）。コピーが増えても自動で検査対象に入り、
 *   下限（MIN_DEFINITIONS）で空振りを止める。
 *
 * ⚠ 自己検査の「今日」（KEY_DAY）を 2026-03-31 にするのが load-bearing。3/31 は前月（2 月）に同じ日が無く、
 *   月だけを 1 つ戻す書き方だと 3/3（当月のまま）になる。月の途中（3/15）や、前月に同じ日がある月末
 *   （1/31 → 12/31）では、正しい書き方と素朴な書き方が同じ値になり、**修正を戻してもテストは緑のまま**。
 *   日付を差し替えた瞬間に落ちるよう、振る舞いのテストの先頭で「素朴な書き方がその日に溢れる」ことを
 *   node で確かめる（MonthEndOverflowTest::assertTodayOverflows() と同じ考え方）。
 * ⚠ 期待値はリテラルで書き、掃引の答えは月の日数の表と閏年の規則で求める。実装と同じ式
 *   （new Date(年, 月, 0) や Math.min(…, lastDay)）で作ると同義反復になり、実装を壊しても緑になる。
 *
 * ⚠ 見えないもの:
 *   - ボタンの配線（@click="setMonthAgo"。DAD は partial の _date-picker / _date-picker-row）と、Alpine の描画・
 *     カレンダーの見た目（実ブラウザで確かめる）
 *   - 「1ヶ月前」以外のショートカット（setWeekAgo は日を 7 日引くだけなので溢れない）
 *   - 走査はビューだけ。resources/js・app/・routes/・config/ に月だけを動かす呼び出しは無い（2026-09-24 実測で 0 件）
 */
class DatePickerMonthAgoTest extends TestCase
{
    /** 自己検査の「今日」。前月（2 月）に同じ日が無く、素朴な書き方だと当月へ溢れる日（クラスの注記を参照） */
    private const KEY_DAY = '2026-03-31';

    /** 定義の下限。走査が空振りして緑になるのを防ぐ（2026-09-24 実測で 7 か所） */
    private const MIN_DEFINITIONS = 7;

    /** 走査した Blade の本数の下限（2026-09-24 実測で 249 本） */
    private const MIN_BLADE_FILES = 200;

    /** 掃引する日数: 2026-01-01〜2028-12-31（365 + 365 + 366） */
    private const SWEEP_DAYS = 1096;

    /**
     * setMonthAgo の定義（`setMonthAgo: function` のほか、アロー関数・メソッドの短縮記法も拾う）。
     * 呼び出し（@click="setMonthAgo"）には当たらない。
     */
    private const DEFINITION = '/\bsetMonthAgo\s*(?::|\([^()]*\)\s*\{)/';

    /** 月だけを動かす呼び出し（ボタンの名前 setMonthAgo には当たらない: 前に . が無く、直後に ( が無い） */
    private const SET_MONTH = '/\.set(UTC)?Month\s*\(/';

    /**
     * Blade が書き換える構文: {{ }} / {!! !!} / ディレクティブ / コンポーネントのタグ / 生の PHP。
     * Blade は JS のコメントの中でも展開する（docs/RULES.md Bug #30）。
     */
    private const BLADE_SYNTAX = '/\{\{|\{!!|@[A-Za-z]|<\/?x[-:]|<\?/';

    /**
     * ビューで月だけを動かしてよい呼び出し（今は無い）。
     * どうしても要るときだけ、理由を書いて載せる（ClockReadScanTest::ALLOWED と同じ形）。
     *
     * @var array<string, array{0: int, 1: string}> 相対パス => [件数, 理由]
     */
    private const ALLOWED = [];

    /**
     * 「1ヶ月前」を定義するビューを機械的に列挙でき、どれも datePicker() をちょうど 1 つ持ち、
     * その中に Blade 構文が無いこと（＝ node が動かす文字列とブラウザが受け取る文字列が同じ）。
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
        }
    }

    /**
     * 前月に同じ日が無い日に押すと前月の末日を選び、カレンダーも前月へ移り、閉じること（すべてのコピー）。
     *
     * ⚠ 押す前にカレンダーを開いておく（open = true）。開いていないまま押すと、閉じる処理を消しても緑になる。
     */
    public function test_month_ago_stops_at_the_last_day_of_the_previous_month(): void
    {
        $this->assertKeyDayOverflows();

        $cases = [
            // [今日, 選ばれる日（isoValue）, カレンダーの表示月]
            [self::KEY_DAY, '2026-02-28', '2026-02'], // 2/31 は無い（素朴な書き方だと当月の 3/3）
            ['2026-03-29', '2026-02-28', '2026-02'],  // 2026 年は閏年でないので 2/29 も無い
            ['2026-05-31', '2026-04-30', '2026-04'],  // 4/31 は無い
            ['2026-12-31', '2026-11-30', '2026-11'],  // 11/31 は無い
            ['2026-01-31', '2025-12-31', '2025-12'],  // 年をまたぐ（12 月には 31 日がある）
            ['2028-03-31', '2028-02-29', '2028-02'],  // 閏年の 2 月の末日
            ['2026-03-15', '2026-02-15', '2026-02'],  // ふつうの日は今までと同じ
        ];

        $sources = $this->datePickerSources();
        $results = $this->runInNode($sources, ['days' => array_column($cases, 0)])['results'];

        foreach (array_keys($sources) as $view) {
            foreach ($cases as [$today, $picked, $month]) {
                $got = $results[$view][$today] ?? [];
                $this->assertSame(
                    ['isoValue' => $picked, 'view' => $month, 'open' => false],
                    ['isoValue' => $got['isoValue'] ?? null, 'view' => $this->viewMonth($got), 'open' => $got['open'] ?? null],
                    "{$view} で今日が {$today} のとき「1ヶ月前」の結果が違う"
                    . '（isoValue ＝ 選ばれる日・view ＝ カレンダーの表示月・open ＝ 押したあと開いたままか）'
                );
            }
        }
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

        foreach (array_keys($sources) as $view) {
            $this->assertArrayHasKey($view, $sweeps, "{$view}: 掃引の結果が返ってこない");
            $sweep = $sweeps[$view];

            $this->assertSame(
                self::SWEEP_DAYS,
                $sweep['count'],
                "{$view}: 掃引した日数が 2026-01-01〜2028-12-31 の " . self::SWEEP_DAYS . ' 日と合わない（ハーネスの暦が壊れている）'
            );

            $shown = array_map(
                fn (array $x) => "  今日 {$x['today']} → {$x['isoValue']}（表示月 {$x['view']}）／答え {$x['expected']}",
                array_slice($sweep['mismatches'], 0, 5)
            );
            $this->assertSame(
                0,
                count($sweep['mismatches']),
                "{$view}: 前月の答えと食い違う日が " . count($sweep['mismatches']) . " 日ある（最初の 5 日）:\n" . implode("\n", $shown)
            );
        }
    }

    /**
     * ビューで月だけを動かす呼び出し（.setMonth( / .setUTCMonth(）が 0 件であること（ラチェット）。
     *
     * 月だけを動かすと、移った先の月にその日が無いとき翌月へ溢れる（3/31 の 1 か月前が 3/3）。
     * 新しく書くときは、移った先の月にその日が無ければその月の末日で止める形にする（setMonthAgo の直し方と同じ）。
     * どうしても要るなら理由を書いて ALLOWED に載せる。
     *
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
                $problems[] = "{$view}: 一覧にあるのに月だけを動かしていない（古い項目）";
            }
        }

        $this->assertSame(
            [],
            $problems,
            'ビューで月だけを動かしている（.setMonth( / .setUTCMonth(）。月だけを動かすと存在しない日で翌月へ溢れる'
            . '（3/31 の 1 か月前が 3/3）。移った先の月にその日が無ければその月の末日で止める形にし（前月なら前月の末日）、'
            . "どうしても要るなら理由を書いて ALLOWED に足す:\n"
            . implode("\n", $problems)
        );
    }

    /**
     * 自己検査: KEY_DAY が「月だけを 1 つ戻す書き方」では当月のままになる（＝溢れる）日であること。
     *
     * 溢れない日（3/15 など）に差し替えると、修正を元に戻しても振る舞いのテストが緑のまま通ってしまう。
     */
    private function assertKeyDayOverflows(): void
    {
        $naive = $this->runInNode([], ['naiveDays' => [self::KEY_DAY]])['naive'][self::KEY_DAY] ?? null;

        $this->assertSame(
            substr(self::KEY_DAY, 0, 7),
            is_string($naive) ? substr($naive, 0, 7) : null,
            'KEY_DAY（' . self::KEY_DAY . '）は、月だけを 1 つ戻す素朴な書き方でも前月へ移る（結果 ' . var_export($naive, true) . '）＝溢れない日。'
            . 'この日では修正を元に戻してもテストが緑になるので、前月に同じ日が無い日（3/31 など）にすること'
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

    /**
     * datePicker() の各コピーを node の vm で実際に動かし、結果を 1 つの JSON で受け取る（アサートは PHP 側で行う）。
     *
     * ⚠ ハーネスはブラウザより寛容であってはいけない（AreaBuildingMapTabTest::runMapScript() と同じ方針）。
     *   datePicker() は DOM を触らない関数なので、文脈には何も渡さない（差し替えるのは引数なしの Date だけ）。
     * ⚠ TZ は Asia/Tokyo に固定する（利用者のブラウザは日本時間。走らせるマシンの設定に結果を左右させない）。
     *
     * @param  array<string, string>  $sources  ビューの相対パス => datePicker() のソース
     * @param  array<string, mixed>  $request  naiveDays（自己検査の日）/ days（リテラルで見る日）/ sweep（掃引する年の範囲）
     * @return array<string, mixed>
     */
    private function runInNode(array $sources, array $request): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので日付ピッカーの実駆動を飛ばす');
        }

        $dir = sys_get_temp_dir() . '/date-picker-month-ago-' . bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            file_put_contents(
                $dir . '/input.json',
                json_encode(['sources' => $sources] + $request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
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
                    fn (string $view, string $error) => "{$view}: " . implode(' / ', array_slice(explode("\n", $error), 0, 2)),
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
     * - days: 各コピーで、その日を今日として「1ヶ月前」を押した結果（isoValue / viewYear / viewMonth / open）
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
        if (arguments.length === 0) return new RealDate(globalThis.__today);
        return new (Function.prototype.bind.apply(RealDate, [null].concat([].slice.call(arguments))))();
    }
    FakeDate.prototype = RealDate.prototype;
    FakeDate.now = function () { return globalThis.__today; };
    FakeDate.UTC = RealDate.UTC;
    FakeDate.parse = RealDate.parse;
    Date = FakeDate;
}

function load(source) {
    const context = vm.createContext({});
    vm.runInContext('(' + installFakeDate.toString() + ')();', context);
    vm.runInContext(source, context, { filename: 'datePicker.js' });
    if (typeof context.datePicker !== 'function') {
        throw new Error('datePicker が関数として定義されなかった');
    }
    return context;
}

// 今日（ローカル時刻の正午。日付の境目での時差の影響を避ける）に、開いたカレンダーで「1ヶ月前」を押す
function pressMonthAgo(context, y, m, d) {
    context.__today = new Date(y, m - 1, d, 12, 0, 0).getTime();
    const p = context.datePicker('');
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
                const got = pressMonthAgo(context, y, m, d);
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
        const context = load(input.sources[view]);
        if (input.days) {
            out.results[view] = {};
            input.days.forEach(function (day) {
                const t = parseDay(day);
                out.results[view][day] = pressMonthAgo(context, t.y, t.m, t.d);
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
