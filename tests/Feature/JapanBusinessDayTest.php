<?php

namespace Tests\Feature;

use App\Http\Controllers\Dad\ProjectController as DadProjectController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Housing\HousingDashboardController;
use App\Http\Controllers\Housing\HsContractListController;
use App\Http\Controllers\RealEstate\ReContractController;
use App\Models\ZealMember;
use App\Models\ZealPlan;
use App\Support\ZealFiscalYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 年度・当月・年齢・キャンペーン期間を日本の日付で決める（Bug #61）。
 * 時刻は日本時間の 0:00〜8:59（UTC ではまだ前日）に固定する。
 *
 * 振る舞い（上半分）と、5 月始まりの年度の式の在処（下半分の走査）を対で固定する。
 * ⚠ ClockReadScanTest とは**走査するファイルは重なるのに、見ている性質が違う**（Bug #54 ⑥）——
 *   あちらは「どの時計を読むか」（`now()` を使ったら落ちる。走査範囲はここより広い）、
 *   ここは「年度の式がどこに何個あるか」。**片方が緑でももう片方は守っていない。**
 */
class JapanBusinessDayTest extends TestCase
{
    /**
     * 5 月始まりの年度のうち、**リフレクションで直接呼べる 5 つのメソッド**（振る舞いをここで固定する）。
     *
     * ⚠ **これで全部ではない。** 式そのものの複製はアプリに **8 か所**ある（2026-09-23 実測）。
     *   残る 3 か所はメソッドとして呼べないうえ、**どのテストも実行しない**＝壊れても無音:
     *     - app/Http/Controllers/TransactionController.php
     *         … ルートが 1 本も無い死にコード（`grep -rn TransactionController routes/` が 0 件。
     *           Property.php の DELETION_IGNORED_RELATIONS も「見る画面が無い」と記している）
     *     - resources/views/mansion/contracts/index.blade.php
     *         … `@php` ブロック。`mansion.contracts.index` を描画するテストが 0 本
     *           （テストが叩くのは show / terminate だけ）
     *     - resources/views/mansion/parking-contracts/index.blade.php
     *         … 同上（`mansion.parking-contracts.*` を叩くテストは 0 本）
     *   全件の在処は test_every_may_fiscal_year_expression_is_classified が
     *   MAY_FISCAL_YEAR_FILES との完全一致で守る（Bug #45 ① / Top trap #13）。
     * ⚠ **複製は 1 か所にまとめない**（2026-09-23 時点の方針。まとめるのは別の設計判断）。
     * ⚠ tests/Feature/DashboardControllerTest.php にも同じ式が 2 か所ある（9・10 個目の複製）。
     *   テスト側の重複なので走査の対象外にしてあるが、期待値を実装と同じ式で作っている以上
     *   **両方が同時に間違っても気づけない**ことは意識しておくこと。
     */
    private const MAY_FISCAL_YEAR_METHODS = [
        [DashboardController::class, 'getCurrentFiscalYear'],
        [ReContractController::class, 'getCurrentFiscalYear'],
        [HsContractListController::class, 'getCurrentFiscalYear'],
        [HousingDashboardController::class, 'getCurrentFiscalYear'],
        [DadProjectController::class, 'currentFiscalYear'],
    ];

    /**
     * 5 月始まりの年度の式を持つファイルの**全件分類**（Bug #45 ① / Top trap #13）。
     *
     * 2026-09-23 実測 = 8 か所（`app/` と `resources/views/` の .php 502 本を走査）。
     * ⚠ **「直した分を並べる」形にしない。** 機械的に列挙してこの一覧と完全一致を見るので、
     *   新しい複製が増えても減っても落ちる（列挙漏れで無音にならない）。
     * ⚠ 足すときは **`JapanTime::today()` から月と年を取っているか**を必ず確かめること
     *   （`now()` や `Carbon::today()` だと日本時間の 0:00〜8:59 に前の年度を返す。Bug #61）。
     *   その「どの時計を読むか」は ClockReadScanTest が別に守っている。
     * ⚠ **構造しか見ていない。** 振る舞いを固定しているのは MAY_FISCAL_YEAR_METHODS の 5 つだけで、
     *   残る 3 か所（TransactionController・賃貸マンションの 2 一覧）は**どのテストも実行しない**。
     *
     * @var list<string>
     */
    private const MAY_FISCAL_YEAR_FILES = [
        'app/Http/Controllers/Dad/ProjectController.php',
        'app/Http/Controllers/DashboardController.php',
        'app/Http/Controllers/Housing/HousingDashboardController.php',
        'app/Http/Controllers/Housing/HsContractListController.php',
        'app/Http/Controllers/RealEstate/ReContractController.php',
        'app/Http/Controllers/TransactionController.php',
        'resources/views/mansion/contracts/index.blade.php',
        'resources/views/mansion/parking-contracts/index.blade.php',
    ];

    /**
     * 5 月始まりでない年度の式（相対パス => 開始月）。ZEAL と DAD は 6 月始まり（CLAUDE.md）。
     *
     * ⚠ ここに載るのは**共有ヘルパー 1 本だけ**。`ZealFiscalYear::START_MONTH` を 5 に変えると
     *   このファイルが 5 月の側へ移り、MAY_FISCAL_YEAR_FILES との完全一致が破れて落ちる
     *   ＝ 開始月の変更が無音にならない。
     * ⚠ **`app/Http/Controllers/Dad/ProjectController.php` は 5 月始まりで実装されている**
     *   （2026-09-23 実測）。CLAUDE.md の「ZEAL/DAD は 6/1 始まり」と食い違うが、
     *   どちらが正しいかは業務判断なのでここでは決めず、**今のコードのとおりに分類してある**。
     *   直すなら業務判断が先（この一覧を書き換えるだけでは直らない）。
     *
     * @var array<string, int>
     */
    private const OTHER_FISCAL_YEAR_FILES = [
        'app/Support/ZealFiscalYear.php' => 6,
    ];

    /**
     * 年度の式（`月 >= 開始月 ? 年 : 年 - 1` と、その逆順）。開始月は整数か同じファイルの定数。
     *
     * ⚠ **変数名に依存しない**（`$today` / `$month` / `$jpToday` のどれでも拾う。Bug #57 の教訓）。
     * ⚠ 上期/下期の `($month >= 5 && $month <= 10) ? 'h1' : 'h2'`（DashboardController に実在）は
     *   年度ではないので拾わない。2 番目の枝は 2026-09-23 時点でリポジトリに 0 件＝
     *   **自己テストのサンプルだけが守っている**（サンプルを消すと枝を消しても緑になる。Bug #45）。
     *
     * @var list<string>
     */
    private const FISCAL_YEAR_EXPRESSIONS = [
        '/\bmonth\b[^;]{0,20}?>=\s*(\d+|(?:self|static)::[A-Z_][A-Z0-9_]*)\s*\?(?:[^;?:]|::|\?->)*?\byear\b(?:[^;?:]|::|\?->)*?:(?:[^;?]|::|\?->)*?\byear\b\s*-\s*1/',
        '/\bmonth\b[^;]{0,20}?<\s*(\d+|(?:self|static)::[A-Z_][A-Z0-9_]*)\s*\?(?:[^;?:]|::|\?->)*?\byear\b\s*-\s*1(?:[^;?:]|::|\?->)*?:(?:[^;?]|::|\?->)*?\byear\b/',
    ];

    /** 走査するファイル数の下限（2026-09-23 実測 = 502）。ディレクトリの歩きが壊れたことを捕まえる */
    private const MIN_SCANNED_FILES = 400;

    /** 年度の式の件数の下限（2026-09-23 実測 = 9 ＝ 5 月の 8 ＋ 6 月の 1） */
    private const MIN_FISCAL_YEAR_EXPRESSIONS = 9;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invokePrivate(string $class, string $method): mixed
    {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(app($class));
    }

    public function test_the_may_fiscal_year_turns_over_at_japan_midnight(): void
    {
        foreach (self::MAY_FISCAL_YEAR_METHODS as [$class, $method]) {
            Carbon::setTestNow(Carbon::parse('2026-04-30 14:59:00', 'UTC')); // 日本時間 4/30 23:59
            $this->assertSame(2025, $this->invokePrivate($class, $method), "{$class}::{$method}: 4/30 はまだ前の年度");

            Carbon::setTestNow(Carbon::parse('2026-04-30 15:30:00', 'UTC')); // 日本時間 5/1 0:30
            $this->assertSame(2026, $this->invokePrivate($class, $method), "{$class}::{$method}: 5/1 の朝は新しい年度");
        }
    }

    public function test_the_dashboard_half_turns_over_at_japan_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 15:30:00', 'UTC')); // 日本時間 5/1 0:30
        $this->assertSame('h1', $this->invokePrivate(DashboardController::class, 'getCurrentPeriod'));

        Carbon::setTestNow(Carbon::parse('2026-10-31 15:30:00', 'UTC')); // 日本時間 11/1 0:30
        $this->assertSame('h2', $this->invokePrivate(DashboardController::class, 'getCurrentPeriod'));
    }

    public function test_zeal_months_turn_over_at_japan_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-31 14:59:00', 'UTC')); // 日本時間 5/31 23:59
        $this->assertSame(2025, ZealFiscalYear::current());
        $this->assertSame('2026-05', ZealFiscalYear::currentMonthYm());

        Carbon::setTestNow(Carbon::parse('2026-05-31 15:30:00', 'UTC')); // 日本時間 6/1 0:30
        $this->assertSame(2026, ZealFiscalYear::current(), '6/1 の朝は新しい年度');
        $this->assertSame('2026-06', ZealFiscalYear::currentMonthYm(), '6/1 の朝は 6 月が当月');
        $this->assertTrue(ZealFiscalYear::isPastMonth('2026-05'), '5 月は締まった月');
        $this->assertTrue(ZealFiscalYear::isCurrentMonth('2026-06'));
        // ⚠ JapanTime::today() が日本時間の 0:00（UTC の前日 15:00）を返すと、ここで当月が「来月」と判定される
        $this->assertFalse(ZealFiscalYear::isFutureMonth('2026-06'), '当月が来月と判定されている（today() が日本時間の 0:00 を返している）');
        $this->assertTrue(ZealFiscalYear::isFutureMonth('2026-07'));
    }

    public function test_a_zeal_members_age_goes_up_at_japan_midnight_on_the_birthday(): void
    {
        $member = (new ZealMember())->forceFill(['birthday' => '1990-09-19']);

        Carbon::setTestNow(Carbon::parse('2026-09-18 14:59:00', 'UTC')); // 日本時間 9/18 23:59
        $this->assertSame(35, $member->age());

        Carbon::setTestNow(Carbon::parse('2026-09-18 15:30:00', 'UTC')); // 日本時間 9/19 0:30（誕生日）
        $this->assertSame(36, $member->age(), '誕生日の朝なのに年齢が上がっていない');
    }

    /**
     * ⚠ 振る舞いが変わる 2 か所のうちの 1 つ: 以前は now()（その日の途中の瞬間）を終了日の 0:00 と比べていたため、
     *   終了日の当日（UTC で 0:00 を過ぎた時点＝日本時間の 9:00 以降）が対象外だった。日付で比べるので終了日も適用中になる。
     * ⚠ もう 1 つは ZealMember::age()（未来の誕生日でだけ旧実装と 1 つ違う。理由と実測はそのメソッドの注記）。
     */
    public function test_a_campaign_runs_from_japan_midnight_of_the_start_day_through_the_end_day(): void
    {
        $plan = (new ZealPlan())->forceFill([
            'campaign_price_excl' => 5000,
            'campaign_starts_on'  => '2026-09-01',
            'campaign_ends_on'    => '2026-09-30',
        ]);

        $cases = [
            ['2026-08-31 14:59:00', false, '開始日の前日（日本時間 8/31 23:59）'],
            ['2026-08-31 15:00:00', true,  '開始日の 0:00（日本時間 9/1 0:00）'],
            ['2026-09-30 14:59:00', true,  '終了日の 23:59（日本時間 9/30 23:59）'],
            ['2026-09-30 15:00:00', false, '終了日の翌日の 0:00（日本時間 10/1 0:00）'],
        ];
        foreach ($cases as [$utc, $expected, $label]) {
            Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
            $this->assertSame($expected, $plan->isCampaignActive(), $label);
        }
    }

    /**
     * 走査するディレクトリ。
     *
     * ⚠ `tests/` は**入れない**（DashboardControllerTest が期待値を作るのに同じ式を 2 回書いている。
     *   テスト側の重複は別の話なので分類の対象にしない。件数は MAY_FISCAL_YEAR_METHODS の注記に残した）。
     * ⚠ 1 か所で持つ（本体の走査と下限のテストが別のものを見ると、片方だけ書き換えたときに守りが外れる）。
     *
     * @return list<string>
     */
    private function fiscalScanDirs(): array
    {
        return [app_path(), resource_path('views')];
    }

    /**
     * コメントを落としてから測る（説明文に書いた式に一致して false-pass しないように。Bug #42 ②）。
     *
     * ⚠ Blade で落とすのは `{{-- --}}` と `/* *\/` と**行頭の** `//` だけ。行中の `//` は残す
     *   （`https://` を消さないため。ClockReadScanTest と同じ方針。ビューの注意書きは `{{-- --}}` に書くこと）。
     * ⚠ Blade の `/*` は文字列の中（`request()->is('tenant/*')` 等）から始めない。始めると次の `*\/` まで
     *   実コードを飲み込み、走査が無音で止まる。
     */
    private function withoutComments(string $path): string
    {
        $source = File::get($path);
        $keepNewlines = fn (array $m) => str_repeat("\n", substr_count($m[0], "\n"));

        if (str_ends_with($path, '.blade.php')) {
            $source = preg_replace_callback('/\{\{--.*?--\}\}/s', $keepNewlines, $source);
            $source = preg_replace_callback('#(?<![\w\x27"])/\*.*?\*/#s', $keepNewlines, $source);

            return preg_replace('#^([ \t]*)//[^\n]*#m', '$1', $source);
        }

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** @return list<array{int, string, string}> [行, 開始月の字面, 一致した式] */
    private function fiscalYearExpressions(string $code): array
    {
        $found = [];
        foreach (self::FISCAL_YEAR_EXPRESSIONS as $pattern) {
            if (preg_match_all($pattern, $code, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $i => [$text, $offset]) {
                    $found[] = [
                        substr_count(substr($code, 0, $offset), "\n") + 1,
                        $m[1][$i][0],
                        preg_replace('/\s+/', ' ', trim($text)),
                    ];
                }
            }
        }

        return $found;
    }

    /**
     * 開始月を決める。整数はそのまま、`self::NAME` / `static::NAME` は同じファイルの `const NAME = 数字;` を引く。
     * 決められなければ null（= 分類できないとして落とす）。
     */
    private function resolveStartMonth(string $raw, string $code): ?int
    {
        if (ctype_digit($raw)) {
            return (int) $raw;
        }
        $name = substr($raw, strpos($raw, '::') + 2);

        return preg_match('/\bconst\s+' . preg_quote($name, '/') . '\s*=\s*(\d+)\s*;/', $code, $m) ? (int) $m[1] : null;
    }

    /** @return array{array<string, list<array{int, int|null, string}>>, int} [相対パス => 一致, 走査したファイル数] */
    private function scanFiscalYearExpressions(): array
    {
        $hits = [];
        $scanned = 0;
        foreach ($this->fiscalScanDirs() as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $scanned++;
                $code = $this->withoutComments($file->getPathname());
                foreach ($this->fiscalYearExpressions($code) as [$line, $raw, $text]) {
                    $path = str_replace(base_path() . '/', '', $file->getPathname());
                    $hits[$path][] = [$line, $this->resolveStartMonth($raw, $code), $text];
                }
            }
        }
        ksort($hits);

        return [$hits, $scanned];
    }

    public function test_every_may_fiscal_year_expression_is_classified(): void
    {
        [$found, $scanned] = $this->scanFiscalYearExpressions();

        // ⚠ 下限は**先に**見る。後ろに置くと、走査の空振りが「一覧に実在しないファイルが残っている」という
        //    別の理由で報告される（Tenant/PropertyDeletionGuardTest が同じ理由でそうしている）
        $this->assertGreaterThanOrEqual(
            self::MIN_SCANNED_FILES,
            $scanned,
            "走査できたファイルが {$scanned} 本しかない（ディレクトリの歩きか拡張子の絞り込みが壊れていないか）"
        );
        $this->assertGreaterThanOrEqual(
            self::MIN_FISCAL_YEAR_EXPRESSIONS,
            count($found),
            '年度の式が ' . count($found) . " 件しか見つからない。検出器が壊れたか、複製を実際に減らしたかのどちらか。\n"
            . "減らしたのなら MIN_FISCAL_YEAR_EXPRESSIONS と下の一覧の両方を直すこと。\n"
            . '見つかったのは: ' . (implode(', ', array_keys($found)) ?: '（0 件）')
        );

        $unresolved = [];
        foreach ($found as $path => $expressions) {
            foreach ($expressions as [$line, $startMonth, $text]) {
                if ($startMonth === null) {
                    $unresolved[] = "{$path}:{$line}  {$text}";
                }
            }
        }
        $this->assertSame(
            [],
            $unresolved,
            "年度の開始月を決められないので分類できない（定数は同じファイルに `const 名前 = 数字;` の形で置くこと）:\n"
            . implode("\n", $unresolved)
        );

        $may = [];
        $other = [];
        foreach ($found as $path => $expressions) {
            foreach ($expressions as [, $startMonth]) {
                if ($startMonth === 5) {
                    $may[$path] = true;
                } else {
                    $other[$path] = $startMonth;
                }
            }
        }
        ksort($may);
        ksort($other);

        $this->assertSame(
            self::MAY_FISCAL_YEAR_FILES,
            array_keys($may),
            "5 月始まりの年度の式の在処が変わった。\n"
            . "増えていた → その複製が JapanTime::today() から月と年を取っているかを確かめてから MAY_FISCAL_YEAR_FILES に足す。\n"
            . "減っていた → まとめたか消したということなので MAY_FISCAL_YEAR_FILES から外す。\n"
            . '⚠ 新しい複製は、リフレクションで呼べるなら MAY_FISCAL_YEAR_METHODS にも足して振る舞いを固定すること'
            . '（構造だけでは「日本の今日で切り替わる」ことを誰も確かめない）'
        );
        $this->assertSame(
            self::OTHER_FISCAL_YEAR_FILES,
            $other,
            '5 月始まりでない年度の式が増減した（開始月つきで OTHER_FISCAL_YEAR_FILES に分類すること）'
        );

        // 2 つの一覧が食い違わないようにする（振る舞いを見ている 5 つは、必ず 5 月の一覧の中にある）
        foreach (self::MAY_FISCAL_YEAR_METHODS as [$class, $method]) {
            $path = str_replace(base_path() . '/', '', (new ReflectionMethod($class, $method))->getFileName());
            $this->assertContains(
                $path,
                self::MAY_FISCAL_YEAR_FILES,
                "{$class}::{$method} のファイル（{$path}）が MAY_FISCAL_YEAR_FILES に無い＝ 2 つの一覧が食い違っている"
            );
        }
    }

    public function test_the_fiscal_year_detector_catches_what_it_should_and_ignores_the_rest(): void
    {
        // ⚠ 枝ごとに 1 つはサンプルを通す。サンプルの無い枝は消しても全テストが緑になる（Bug #45）
        $caught = [
            '$today->month >= 5 ? $today->year : $today->year - 1',
            '$month >= 5 ? $year : $year - 1',                                                  // 変数に取り出した形
            '$jpToday->month >= 5 ? $jpToday->year : $jpToday->year - 1',                       // 変数名に依存しない
            '$today->month >= self::FISCAL_YEAR_START_MONTH ? $today->year : $today->year - 1', // self:: の定数
            '$d->month >= static::START_MONTH ? $d->year : $d->year - 1',                       // static:: の定数
            '$today?->month >= 5 ? $today?->year : $today?->year - 1',                          // nullsafe
            '$today->month>=5?$today->year:$today->year-1',                                     // 空白なし
            "\$today->month >= 5\n    ? \$today->year\n    : \$today->year - 1",                // 改行またぎ
            '$today->month < 5 ? $today->year - 1 : $today->year',                              // 逆順（リポジトリには 0 件）
        ];
        // ⚠ 後半は「拾わない」＝死角と対照の記録。検出器を広げたらここが赤くなって「広げた」と分かる
        $ignored = [
            "(\$month >= 5 && \$month <= 10) ? 'h1' : 'h2'", // 上期/下期（DashboardController に実在。年度ではない）
            'ZealFiscalYear::current()',                     // 共有ヘルパーの呼び出し側
            '$start = "{$request->fiscal_year}-05-01";',     // 絞り込みの値（時計を読まない。賃貸マンションの 2 コントローラ）
            '$today->month >= 5 ? $today->year : $today->year',      // - 1 が無い
            '$today->month >= 5 ? $today->year : $today->year - 2',  // 引く数が違う
            '$today->day >= 5 ? $today->year : $today->year - 1',    // month ではない
            '$today->month >= 5 ? $today->quarter : $today->quarter - 1', // year ではない
        ];

        foreach ($caught as $sample) {
            $this->assertNotSame([], $this->fiscalYearExpressions($sample), "拾えていない: {$sample}");
        }
        foreach ($ignored as $sample) {
            $this->assertSame([], $this->fiscalYearExpressions($sample), "拾うべきでない: {$sample}");
        }

        // 開始月の解決（整数 / 解ける定数 / 解けない定数）。解けない枝が無いと分類漏れが無音になる
        $this->assertSame(5, $this->resolveStartMonth('5', ''));
        $this->assertSame(6, $this->resolveStartMonth('self::START_MONTH', 'const START_MONTH = 6;'));
        $this->assertNull($this->resolveStartMonth('self::START_MONTH', 'const OTHER = 6;'));
    }

    public function test_comments_are_dropped_before_the_fiscal_year_scan(): void
    {
        $expression = '$today->month >= 5 ? $today->year : $today->year - 1';
        $php = sys_get_temp_dir() . '/fiscal-' . uniqid('', true) . '.php';
        $blade = sys_get_temp_dir() . '/fiscal-' . uniqid('', true) . '.blade.php';
        File::put($php, "<?php\n// {$expression}\n/** {$expression} */\n\$ok = 1;\n");
        File::put($blade, "{{-- {$expression} --}}\n  // {$expression}\n<p>ok</p>\n");

        try {
            $this->assertSame([], $this->fiscalYearExpressions($this->withoutComments($php)), 'PHP のコメントの中の式を拾っている');
            $this->assertSame([], $this->fiscalYearExpressions($this->withoutComments($blade)), 'Blade のコメントの中の式を拾っている');
        } finally {
            File::delete([$php, $blade]);
        }
    }
}
