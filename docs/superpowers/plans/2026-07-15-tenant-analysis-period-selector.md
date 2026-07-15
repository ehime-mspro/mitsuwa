# テナント契約・解約分析 月別集計 期間セレクト統合＋意匠改善 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `/tenant/analysis` の月別集計カードのセレクトを 1 つのまま 3 ブロック（全期間 / 直近2・4・6・8年 / 各年度）に拡張し、意匠を A 案（白ベース・エメラルドのホバー/フォーカス）へ差し替える。

**Architecture:** 「直近N年」の合算は **PHP 側（`ContractAnalysisService`）で `byMonthByYear` を合算して算出**する（自動テストで検証でき、「今年」がサーバー時刻で決まる）。コントローラは `month.periods`（`[{key:'lastN',values,total}]`）を payload に**追加のみ**し、既存の `all`/`years` は不変。Alpine の `updateMonth()` は `lastN` 分岐で**引くだけ**（JS では合算しない）。月別チャートの更新は既存どおり `Chart.getChart()` の raw インスタンス経由（Alpine reactive proxy 回避）。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade + Alpine.js 3 / Chart.js 4.4.1（jsdelivr CDN）/ PHPUnit（SQLite in-memory）

**設計の正:** `docs/superpowers/specs/2026-07-15-tenant-analysis-period-selector-design.md`（`5163080b`）
**承認済みモック:** `docs/mockups/tenant/analysis/month-period-selector-mock.html`（`13c8c7fc`）

---

## File Structure

| ファイル | 責務 | 本プランでの変更 |
|---|---|---|
| `app/Services/Tenant/ContractAnalysisService.php` | 集計のすべて（DB 非依存・SQL 日付関数不使用） | `PERIOD_YEARS` 定数＋`byMonthByPeriod()` を追加。既存メソッドは不変 |
| `app/Http/Controllers/Tenant/AnalysisController.php` | 集計 → Chart.js 用 payload 整形 | `chartPayload()` の `month` に `periods` を追加 |
| `resources/views/tenant/analysis/_charts.blade.php` | 年別/月別カード（契約・解約で**2回 include**） | 月別セレクトを 3 ブロック化＋`class="analysis-select"` |
| `resources/views/tenant/analysis/index.blade.php` | ページ枠・Alpine `tenantAnalysis()`・チャートデータ埋め込み | `<style>`（`.analysis-select`）を**1つだけ**追加＋`updateMonth()` に `lastN` 分岐＋導入文 |
| `tests/Feature/Tenant/ContractAnalysisTest.php` | 集計・payload・描画の検証（既存 16 本） | 時刻凍結（`setUp`/`tearDown`）＋T16〜T20 を追加 |

**変更しない:** `routes/web.php` / `sidebar.blade.php` / DB スキーマ / `phpunit.xml`（`APP_URL=http://localhost` 固定行は死守）/ 年別集計カード / `bar()` / `render()` / `init()` / `show()`。

**タスク境界の根拠（壊れない中間コミット）:** Task 1・2 は「誰も読まない新キーが増えるだけ」なので単独で安全。Task 3 の 3 要素（view の `<option value="lastN">` / JS の `lastN` 分岐 / `<style>`）は**分割不可** — option だけ先に出すと選択が年度分岐へ落ちて `return` し、**例外も出ずグラフもバッジも無反応**（silent failure）になる。`class` と `<style>` が別だと素のセレクトになる。

---

## Task 1: サービス層 — 直近N年の月別合算 `byMonthByPeriod`

**Files:**
- Modify: `app/Services/Tenant/ContractAnalysisService.php`
- Test: `tests/Feature/Tenant/ContractAnalysisTest.php`

**Work from:** worktree `/Users/masanori/site/manage/.claude/worktrees/tenant-analysis-period-selector`

- [ ] **Step 1: 時刻凍結を追加（テストの土台）**

`tests/Feature/Tenant/ContractAnalysisTest.php` の import に `use Carbon\Carbon;` を追加（`use App\Services\Tenant\ContractAnalysisService;` の**次の行**＝アルファベット順）:

```php
use App\Services\Tenant\ContractAnalysisService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
```

`private int $seq = 0;` の直後に挿入（既存 `tests/Unit/Tenant/InvestmentRecoveryTest.php` と同じ流儀）:

```php
    protected function setUp(): void
    {
        parent::setUp();
        // 「現在」を 2026-07-15 に固定（直近N年は now()->year 基準＝実時刻だと年跨ぎでテストが落ちるため）
        Carbon::setTestNow(Carbon::parse('2026-07-15'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
```

- [ ] **Step 2: 既存 16 本が凍結後も緑であることを確認（回帰の切り分け）**

Run:
```bash
cd /Users/masanori/site/manage/.claude/worktrees/tenant-analysis-period-selector
APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php
```
Expected: **OK (16 tests)** — 既存テストは `now()` を読まない（すべて明示日付でシード）ため凍結の影響を受けない想定。**ここが赤いなら先に原因を潰す**（Task 1 の失敗テストと混ざると切り分け不能になる）。

- [ ] **Step 3: 失敗するテスト T16/T17/T18 を書く**

`test_chart_payload_month_shape`（T15・ファイル末尾）の**後ろ**に追加:

```php
    /** T16: 直近N年（今年=2026 基準）の月別集計。2/4/6/8 それぞれの窓で件数が変わる */
    public function test_month_by_period_windows(): void
    {
        $this->makeContract('tenant', '2026-01-10'); // 直近 2/4/6/8年
        $this->makeContract('tenant', '2025-02-10'); // 直近 2/4/6/8年
        $this->makeContract('tenant', '2024-03-10'); // 直近 4/6/8年
        $this->makeContract('tenant', '2022-04-10'); // 直近 6/8年
        $this->makeContract('tenant', '2020-05-10'); // 直近 8年のみ
        $this->makeContract('tenant', '2017-06-10'); // どの期間にも入らない（全期間のみ）

        $c = (new ContractAnalysisService)->build()['contract'];
        $p = $c['byMonthByPeriod'];

        $this->assertSame([2, 4, 6, 8], array_keys($p)); // N 昇順（セレクト表示順）
        $this->assertSame(2, $p[2]['total']);            // 2025..2026
        $this->assertSame(3, $p[4]['total']);            // 2023..2026
        $this->assertSame(4, $p[6]['total']);            // 2021..2026
        $this->assertSame(5, $p[8]['total']);            // 2019..2026
        $this->assertSame(6, $c['byMonth']['total']);    // 全期間は 2017 も含む

        // 直近8年: 1〜5月に各1件（index0..4）・6月(2017年)は窓の外
        $this->assertSame([1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0], $p[8]['values']);
        // 直近2年: 1月(2026)・2月(2025) のみ
        $this->assertSame([1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], $p[2]['values']);
    }

    /** T17: 直近2年 = 今年(2026)＋昨年(2025)。一昨年(2024)は入らない（窓の起点のオフバイワン検知） */
    public function test_last_two_years_window_includes_current_year(): void
    {
        $this->makeContract('tenant', '2026-01-10');
        $this->makeContract('tenant', '2025-01-15');
        $this->makeContract('tenant', '2024-01-20'); // 直近2年には入らない

        $p = (new ContractAnalysisService)->build()['contract']['byMonthByPeriod'];

        $this->assertSame(2, $p[2]['total']);     // 2026 + 2025（2024 が入って 3 になるなら起点が1年ズレている）
        $this->assertSame(2, $p[2]['values'][0]); // 3件とも1月・うち窓内は2件
        $this->assertSame(3, $p[4]['total']);     // 直近4年(2023..2026)なら 2024 も入る
    }

    /** T18: 空データでも4期間は常に存在し全て0（byMonthByYear=[] と非対称＝期間は固定窓） */
    public function test_month_by_period_empty(): void
    {
        $c = (new ContractAnalysisService)->build()['contract'];

        $this->assertSame([], $c['byMonthByYear']);                          // 年度は「データのある年」のみ → 空
        $this->assertSame([2, 4, 6, 8], array_keys($c['byMonthByPeriod']));  // 期間は常に4つ
        foreach ([2, 4, 6, 8] as $n) {
            $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], $c['byMonthByPeriod'][$n]['values']);
            $this->assertSame(0, $c['byMonthByPeriod'][$n]['total']);
        }
    }
```

- [ ] **Step 4: テストを走らせて失敗を確認**

Run:
```bash
cd /Users/masanori/site/manage/.claude/worktrees/tenant-analysis-period-selector
APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php
```
Expected: **FAILURES/ERRORS (3)** — `Undefined array key "byMonthByPeriod"` 由来（`array_keys(null)` の TypeError 等）。既存 16 本は緑のまま。

- [ ] **Step 5: 実装 — 定数を追加**

`app/Services/Tenant/ContractAnalysisService.php` の `private const MAX_YEARS = 10;` の直後:

```php
    /** 年別集計で表示する最大年数（新しい方から） */
    private const MAX_YEARS = 10;

    /** 月別集計の「直近N年」候補（今年を含む暦年ベース・セレクトの期間グループ順） */
    private const PERIOD_YEARS = [2, 4, 6, 8];
```

- [ ] **Step 6: 実装 — `summarize()` を差し替え**

既存の `summarize()` を**丸ごと**以下に置き換える（docblock 込み）:

```php
    /**
     * [year, month] ペアから年別・月別・年度別月別・期間別月別を組み立てる。
     *
     * @param  Collection<int, array{0:int,1:int}>  $pairs
     * @return array{byYear: array, byMonth: array, byMonthByYear: array, byMonthByPeriod: array}
     */
    private function summarize(Collection $pairs): array
    {
        $byMonthByYear = $this->byMonthByYear($pairs);

        return [
            'byYear'          => $this->byYear($pairs->map(fn (array $p) => $p[0])),
            'byMonth'         => $this->byMonth($pairs->map(fn (array $p) => $p[1])),
            'byMonthByYear'   => $byMonthByYear,
            'byMonthByPeriod' => $this->byMonthByPeriod($byMonthByYear),
        ];
    }
```

- [ ] **Step 7: 実装 — `byMonthByPeriod()` を追加**

`byMonthByYear()` の**後ろ**（クラス末尾）に追加:

```php
    /**
     * 「直近N年」の月別集計。N年 = 今年を含めて暦年で N 年ぶん（今年-N+1 〜 今年）。
     * 範囲内にデータの無い年は 0 件として扱う（常に N 年ぶんの固定窓）。
     * 「今年」はサーバー時刻基準（クライアント時計に依存しない）。
     *
     * @param  array<int, array{values: list<int>, total: int}>  $byMonthByYear
     * @return array<int, array{values: list<int>, total: int}>  N(int) => {values:[1月..12月], total}
     */
    private function byMonthByPeriod(array $byMonthByYear): array
    {
        $thisYear = (int) now()->year;
        $periods  = [];

        foreach (self::PERIOD_YEARS as $n) {
            $values = array_fill(0, 12, 0); // index0=1月 … index11=12月
            for ($y = $thisYear - $n + 1; $y <= $thisYear; $y++) {
                foreach ($byMonthByYear[$y]['values'] ?? [] as $i => $v) {
                    $values[$i] += $v;
                }
            }
            $periods[$n] = ['values' => $values, 'total' => array_sum($values)];
        }

        return $periods;
    }
```

- [ ] **Step 8: テストを走らせて全て緑を確認**

Run:
```bash
cd /Users/masanori/site/manage/.claude/worktrees/tenant-analysis-period-selector
APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php
```
Expected: **OK (19 tests)** — 既存 16 ＋ T16/T17/T18。

- [ ] **Step 9: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/tenant-analysis-period-selector
git add app/Services/Tenant/ContractAnalysisService.php tests/Feature/Tenant/ContractAnalysisTest.php
git commit -m "feat(tenant): 月別集計に直近N年の合算 byMonthByPeriod を追加（サービス層）"
```

---

## Task 2: コントローラ — payload に `month.periods` を追加

**Files:**
- Modify: `app/Http/Controllers/Tenant/AnalysisController.php`
- Test: `tests/Feature/Tenant/ContractAnalysisTest.php`

- [ ] **Step 1: 失敗するテスト T19 を書く**

T18 の後ろに追加:

```php
    /** T19: month.periods payload（key は 'lastN' 文字列・N昇順）＋既存 all/years の非退行 */
    public function test_chart_payload_month_periods(): void
    {
        $this->makeContract('tenant', '2026-01-10');
        $this->makeContract('tenant', '2024-03-05');

        $response = $this->actingAs($this->executive())->get('/tenant/analysis');
        $response->assertOk();
        $month = $response->viewData('charts')['contract']['month'];

        // key は JS の `o.key === sel`（厳密比較）と一致する文字列・N 昇順
        $this->assertSame(['last2', 'last4', 'last6', 'last8'], array_column($month['periods'], 'key'));
        $this->assertSame(1, $month['periods'][0]['total']); // 直近2年: 2026年のみ
        $this->assertSame(2, $month['periods'][1]['total']); // 直近4年: 2026年 + 2024年
        $this->assertSame([1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], $month['periods'][0]['values']); // 1月
        $this->assertSame([1, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0], $month['periods'][1]['values']); // 1月 + 3月
        // 既存キーの非退行
        $this->assertSame([1, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0], $month['all']);
        $this->assertSame(['2026', '2024'], array_column($month['years'], 'year'));
    }
```

- [ ] **Step 2: テストを走らせて失敗を確認**

Run:
```bash
cd /Users/masanori/site/manage/.claude/worktrees/tenant-analysis-period-selector
APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php --filter test_chart_payload_month_periods
```
Expected: **FAIL** — `Undefined array key "periods"`（`array_column(null, 'key')` の TypeError）。

- [ ] **Step 3: 実装 — `chartPayload()` の `month` に `periods` を追加**

`app/Http/Controllers/Tenant/AnalysisController.php` の `'month' => [ ... ]` ブロックを**丸ごと**以下に置き換える（`labels`/`all`/`years` は現状のまま・`periods` を `all` と `years` の間に挿入）:

```php
            'month' => [
                'labels'  => array_map(fn (int $m) => $m . '月', $summary['byMonth']['labels']), // ['1月'..'12月']
                'all'     => $summary['byMonth']['values'],   // 全期間の12件
                'periods' => collect($summary['byMonthByPeriod'])
                    ->map(fn (array $d, int $n) => [
                        'key'    => 'last' . $n,   // セレクト value と厳密一致（'last2'…'last8'）
                        'values' => $d['values'],
                        'total'  => $d['total'],
                    ])
                    ->values()
                    ->all(), // [{key:'last2',values:[..],total:..}, ...] N 昇順
                'years'   => collect($summary['byMonthByYear'])
                    ->map(fn (array $d, int $y) => [
                        'year'   => (string) $y,
                        'values' => $d['values'],
                        'total'  => $d['total'],
                    ])
                    ->values()
                    ->all(), // [{year:'2025',values:[..],total:..}, ...] 年降順
            ],
```

- [ ] **Step 4: テストを走らせて全て緑を確認**

Run:
```bash
cd /Users/masanori/site/manage/.claude/worktrees/tenant-analysis-period-selector
APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php
```
Expected: **OK (20 tests)**

- [ ] **Step 5: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/tenant-analysis-period-selector
git add app/Http/Controllers/Tenant/AnalysisController.php tests/Feature/Tenant/ContractAnalysisTest.php
git commit -m "feat(tenant): 月別集計 payload に直近N年データ periods を追加"
```

---

## Task 3: 表示層 — 3ブロックセレクト＋A案の意匠＋Alpine 分岐

**⚠ このタスクは分割してコミットしないこと**（view の option / JS の分岐 / `<style>` が揃って初めて動く。片方だけだと例外も出ずに無反応 or 素のセレクトになる）。

**Files:**
- Modify: `resources/views/tenant/analysis/_charts.blade.php:32-40`（月別セレクト）
- Modify: `resources/views/tenant/analysis/index.blade.php`（導入文 / `<style>` 追加 / `updateMonth()`）
- Test: `tests/Feature/Tenant/ContractAnalysisTest.php`

- [ ] **Step 1: 失敗するテスト T20 を書く**

T19 の後ろに追加:

```php
    /** T20: 月別セレクトの3ブロック（全期間 / 期間 optgroup / 年度 optgroup）＋ style が1つだけ */
    public function test_month_period_selector_rendered(): void
    {
        $this->makeContract('tenant', '2026-01-10');
        $this->makeContract('tenant', '2025-03-05');

        $response = $this->actingAs($this->executive())->get('/tenant/analysis');
        $response->assertOk();

        // optgroup 見出し（「期間」は「全期間」の部分文字列なので生 HTML で厳密に検証）
        $response->assertSee('<optgroup label="期間">', false);
        $response->assertSee('<optgroup label="年度">', false);
        // 期間 option の value は JS の startsWith('last') / o.key === sel が依存する契約
        $response->assertSee('<option value="last2">直近2年</option>', false);
        $response->assertSee('<option value="last8">直近8年</option>', false);
        $response->assertSee('直近4年');
        $response->assertSee('直近6年');
        // 既存の全期間・年度 option は維持
        $response->assertSee('全期間');
        $response->assertSee('2026年');

        // _charts は契約/解約で2回 include されるが、style は index 側に1つだけ
        $this->assertSame(1, substr_count($response->getContent(), '.analysis-select:hover'));
    }
```

- [ ] **Step 2: テストを走らせて失敗を確認**

Run:
```bash
cd /Users/masanori/site/manage/.claude/worktrees/tenant-analysis-period-selector
APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php --filter test_month_period_selector_rendered
```
Expected: **FAIL** — `Response status code [200] ... unable to find '<optgroup label="期間">'`。

- [ ] **Step 3: 実装 — `_charts.blade.php` のセレクトを3ブロック化**

`resources/views/tenant/analysis/_charts.blade.php` の `@if($monthTotal !== 0)` 〜 `@endif`（32〜40 行目）を**丸ごと**置き換える:

```blade
            @if($monthTotal !== 0)
                <select x-model="monthRange.{{ $prefix }}" @change="updateMonth('{{ $prefix }}')" class="analysis-select">
                    <option value="all">全期間</option>
                    <optgroup label="期間">
                        @foreach($summary['byMonthByPeriod'] as $n => $d)
                            <option value="last{{ $n }}">直近{{ $n }}年</option>
                        @endforeach
                    </optgroup>
                    <optgroup label="年度">
                        @foreach($summary['byMonthByYear'] as $year => $d)
                            <option value="{{ $year }}">{{ $year }}年</option>
                        @endforeach
                    </optgroup>
                </select>
            @endif
```

**注意:** `<option>` は両 optgroup とも `@foreach` の静的注入（`<template x-for>` 禁止・Bug #16）。インライン `style=` は削除し `class="analysis-select"` に一本化する（`style=` と `class` の二重指定はしない）。

- [ ] **Step 4: 実装 — `index.blade.php` に `<style>` を追加**

`resources/views/tenant/analysis/index.blade.php` の `x-data` ラッパを閉じる `</div>`（41 行目）と `{{-- Chart.js（cdn.jsdelivr.net のみ許可…）--}}` の**あいだ**に挿入:

```blade
{{-- 月別集計セレクト（A案）: :hover/:focus はインライン style で書けないためクラス化。
     _charts は契約/解約で2回 include されるため、style はこちら側に1つだけ置く --}}
<style>
    .analysis-select {
        appearance: none; -webkit-appearance: none;
        font-size: 12px; font-weight: 600; color: #374151; line-height: 1.5;
        background-color: #fff;
        background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none' stroke='%236B7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right 7px center; background-size: 14px;
        border: 1px solid #D1D5DB; border-radius: 8px;
        padding: 5px 28px 5px 11px; cursor: pointer;
        box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
        transition: border-color .15s, box-shadow .15s, background-color .15s;
    }
    .analysis-select:hover { border-color: #6EE7B7; background-color: #F0FDF4; }
    .analysis-select:focus { outline: none; border-color: #059669; box-shadow: 0 0 0 3px rgba(5, 150, 105, .15); }
</style>
```

**注意:** 値はモック `.sel-a` からそのまま移植（クラス名のみ改名）。**新規 Tailwind クラスは足さない**（Vite 未ビルドで無効化される Bug #19 を自前 CSS で回避）。先例: `resources/views/tenant/contracts/index.blade.php:159`。

- [ ] **Step 5: 実装 — `updateMonth()` に `lastN` 分岐を追加**

`index.blade.php` の `monthRange` 行のコメントを更新:

```js
            monthRange: { contract: 'all', termination: 'all' },      // 選択中の範囲（'all' / 'last2'…'last8' / '2024'）
```

`updateMonth(which)` を**丸ごと**置き換える:

```js
            // 期間/年度セレクト変更 → 月別チャートの data と総計バッジを更新
            updateMonth(which) {
                const md  = TENANT_ANALYSIS_CHARTS[which].month;
                const sel = this.monthRange[which];
                let values, total;
                if (sel === 'all') {
                    values = md.all;
                    total  = md.all.reduce((a, b) => a + b, 0);
                } else if (sel.startsWith('last')) {
                    const p = (md.periods || []).find(o => o.key === sel); // 直近N年（PHP側で合算済み）
                    if (!p) return;
                    values = p.values;
                    total  = p.total;
                } else {
                    const y = (md.years || []).find(o => o.year === sel);
                    if (!y) return;
                    values = y.values;
                    total  = y.total;
                }
                const chart = Chart.getChart('chart-' + which + '-month'); // raw インスタンス（reactive proxy 回避）
                if (chart) {
                    chart.data.datasets[0].data = values;
                    chart.update();
                }
                this.monthTotalText[which] = '総計 ' + total.toLocaleString() + '件';
            },
```

**注意:** `init()` / `render()` / `show()` / `bar()` は**触らない**（初期値 `all` のままなので変更不要）。JS 側で合算はしない（PHP の値を引くだけ）。

- [ ] **Step 6: 実装 — 導入文を期間対応の文言に更新**

`index.blade.php:18` を置き換える:

```blade
        <p class="text-sm text-gray-500" style="margin-top:4px;">契約年ごとの件数（最大直近10年の推移）と、契約月ごとの件数（全期間／直近N年／年度別の季節性）を、それぞれ棒グラフで表示します。</p>
```

- [ ] **Step 7: テストを走らせて全て緑を確認**

Run:
```bash
cd /Users/masanori/site/manage/.claude/worktrees/tenant-analysis-period-selector
APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php
```
Expected: **OK (21 tests)** — 既存 16 ＋ T16〜T20。

- [ ] **Step 8: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/tenant-analysis-period-selector
git add resources/views/tenant/analysis/_charts.blade.php resources/views/tenant/analysis/index.blade.php tests/Feature/Tenant/ContractAnalysisTest.php
git commit -m "feat(tenant): 月別集計セレクトを期間統合＋A案の意匠に刷新"
```

---

## Task 4: 統合検証（オーケストレータが実施・サブエージェントに投げない）

**Files:** なし（検証のみ）

Chart.js の実描画・Alpine の実挙動は**自動テストで検証できない**（本プロジェクトは silent failure の前科多数）。実ブラウザで**チャートの data 配列を読んで期待値と厳密比較**する。目視で済ませない。

- [ ] **Step 1: main repo へ FF マージ**

```bash
cd /Users/masanori/site/manage
git checkout 13.x && git merge --ff-only tenant-analysis-period-selector
git log --oneline -4
```
Expected: 3 コミットが 13.x の先頭に乗る（FF なのでマージコミット無し）。

- [ ] **Step 2: view:cache lint（Bug #26 型の対策・`view:cache` の成功表示は当てにならない）**

```bash
cd /Users/masanori/site/manage
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```
Expected: `INVALID:` の行が**1つも出ない**こと。

- [ ] **Step 3: 検証用レンダラを用意（worktree 側・実DB保護）**

**必ず worktree で実行する。** main repo には実 MySQL（`masa8787kanri63732`）の `.env` があり、`migrate` が実 DB を破壊しうる。worktree は `.env` 無し＝MySQL 認証情報が存在せず原理的に到達不能。

まず Vite manifest 用に build を symlink（worktree の `public/build` は gitignore で存在しない → 無いと `ViteManifestNotFoundException`）:

```bash
ln -sfn /Users/masanori/site/manage/public/build \
        /Users/masanori/site/manage/.claude/worktrees/tenant-analysis-period-selector/public/build
```

スクラッチパッドに `render-analysis.php` を作成（スタンドアロン PHP。tinker は `<?php` 不可 / group use 衝突で不可）:

```php
<?php
// テナント契約・解約分析 期間セレクトの実ブラウザ検証用レンダラ（SQLite・実DBには触れない）

$WT = '/Users/masanori/site/manage/.claude/worktrees/tenant-analysis-period-selector';
$DB = '/private/tmp/claude-501/-Users-masanori-site-manage/42047dcb-b28d-48f0-a08b-91a6d32d11d1/scratchpad/verify.sqlite';

// Dotenv は immutable（既存 env が勝つ）→ bootstrap 前に必ずセット
foreach ([
    'APP_KEY'        => 'base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM=',
    'APP_ENV'        => 'local',
    'APP_DEBUG'      => 'true',
    'DB_CONNECTION'  => 'sqlite',
    'DB_DATABASE'    => $DB,
    'SESSION_DRIVER' => 'array',
    'CACHE_STORE'    => 'array',
] as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}

@unlink($DB);
touch($DB);

require $WT . '/vendor/autoload.php';
$app = require $WT . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 実DB破壊の防止ガード（sqlite 以外なら即死）
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $DB) {
    fwrite(STDERR, "ABORT: sqlite ではない（実DB保護）\n");
    exit(1);
}

Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);

$seq = 0;
$mk = function (string $date, ?string $end = null, string $status = 'active') use (&$seq) {
    $seq++;
    $customer = App\Models\Customer::create(['code' => 'V-C-' . $seq, 'name' => '検証商事' . $seq, 'customer_type' => 'corporation']);
    $property = App\Models\Property::create(['code' => 'V-P-' . $seq, 'name' => '検証ビル' . $seq, 'property_type' => 'tenant', 'department' => 'tenant', 'address' => '愛媛県松山市本町1-1']);
    $unit = App\Models\Unit::create(['property_id' => $property->id, 'room_number' => 'A' . $seq, 'display_name' => '1A-' . $seq, 'status' => 'occupied']);
    App\Models\Contract::create([
        'contract_number' => 'V-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
        'department' => 'tenant', 'property_id' => $property->id, 'unit_id' => $unit->id,
        'customer_id' => $customer->id, 'status' => $status,
        'contract_date' => $date, 'rent_start_date' => $date, 'contract_end_date' => $end,
        'rent' => 100000, 'common_fee' => 10000,
    ]);
};

// 契約: 年ごとに違う「月」と「件数」→ 期間ごとの期待値が一意に決まる
foreach ([['2026-01-10', 1], ['2025-02-10', 2], ['2024-03-10', 3], ['2022-04-10', 4], ['2020-05-10', 5], ['2017-06-10', 6]] as [$d, $n]) {
    for ($i = 0; $i < $n; $i++) { $mk($d); }
}
// 解約: contract_end_date 基準。contract_date は 2017-01-01（全期間の1月に寄せ、どの期間窓にも入れない）
foreach ([['2026-07-10', 1], ['2025-09-10', 2], ['2021-11-10', 3]] as [$d, $n]) {
    for ($i = 0; $i < $n; $i++) { $mk('2017-01-01', $d, 'terminated'); }
}

// テストの executive() ヘルパと同じ作り方（User の必須列/キャストに依存しない実績のある経路）
$user = App\Models\User::factory()->create([
    'role' => App\Enums\UserRole::Executive->value,
    'must_change_password' => false,
]);
Illuminate\Support\Facades\Auth::login($user);

Illuminate\Support\Facades\URL::forceRootUrl('http://127.0.0.1:8000');
Illuminate\Support\Facades\View::share('errors', new Illuminate\Support\ViewErrorBag);

$html = app(App\Http\Controllers\Tenant\AnalysisController::class)
    ->index(app(App\Services\Tenant\ContractAnalysisService::class))
    ->render();

file_put_contents($WT . '/public/analysis.html', $html);
echo "OK: " . strlen($html) . " bytes\n";
```

Run:
```bash
APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= php \
  /private/tmp/claude-501/-Users-masanori-site-manage/42047dcb-b28d-48f0-a08b-91a6d32d11d1/scratchpad/render-analysis.php
```
Expected: `OK: <数万> bytes`。`ABORT:` が出たら**そこで止める**（実DBに向いている）。

- [ ] **Step 4: サーバ起動＋ブラウザで開く**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/tenant-analysis-period-selector
APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= php artisan serve --port=8000
```
（`run_in_background: true` で起動）。次に Browser pane: `preview_start {url: "http://127.0.0.1:8000/analysis.html"}`。

- [ ] **Step 5: 契約タブ — 全期間/直近N年/単年 の data を厳密比較**

セレクトは `read_page` で ref を取り `form_input` で選択（Alpine の `x-model`/`@change` が発火する）。各選択後に `javascript_tool` で:

```js
JSON.stringify({
  data:  Chart.getChart('chart-contract-month').data.datasets[0].data,
  badge: document.querySelector('[x-text="monthTotalText.contract"]').textContent.trim(),
  width: Chart.getChart('chart-contract-month').width,
})
```

**期待値（契約タブ・シードは Step 3 のとおり・今日=2026年）:**

| 選択 | data（[1月..12月]） | バッジ |
|---|---|---|
| 全期間 | `[7,2,3,4,5,6,0,0,0,0,0,0]` | 総計 27件 |
| 直近2年 | `[1,2,0,0,0,0,0,0,0,0,0,0]` | 総計 3件 |
| 直近4年 | `[1,2,3,0,0,0,0,0,0,0,0,0]` | 総計 6件 |
| 直近6年 | `[1,2,3,4,0,0,0,0,0,0,0,0]` | 総計 10件 |
| 直近8年 | `[1,2,3,4,5,0,0,0,0,0,0,0]` | 総計 15件 |
| 2024年 | `[0,0,3,0,0,0,0,0,0,0,0,0]` | 総計 3件 |
| 全期間へ戻す | `[7,2,3,4,5,6,0,0,0,0,0,0]` | 総計 27件 |

（全期間の 1月=7 は 2026-01 の 1 件＋解約用シードの `contract_date=2017-01-01` × 6 件。2017 はどの期間窓にも入らない）

`width` が **0 でない**こと（x-show 非表示タブの幅0崩れ検知）。

- [ ] **Step 6: 解約タブ（初期非表示）でも効くことを確認**

「解約分析」タブをクリック → 同様にセレクトを切替え、`Chart.getChart('chart-termination-month')` を読む。

**期待値（解約タブ）:**

| 選択 | data（[1月..12月]） | バッジ |
|---|---|---|
| 全期間 | `[0,0,0,0,0,0,1,0,2,0,3,0]` | 総計 6件 |
| 直近2年 | `[0,0,0,0,0,0,1,0,2,0,0,0]` | 総計 3件 |
| 直近6年 | `[0,0,0,0,0,0,1,0,2,0,3,0]` | 総計 6件 |

（直近2年=2025〜2026 は 2021-11 を含まない → 直近6年 と差が出る＝タブまたぎで `Chart.getChart` が正しく効いている証拠）

- [ ] **Step 7: 意匠と console を確認**

- `read_console_messages` → **エラー 0**。
- セレクトをクリック/フォーカスして `computer {action:"screenshot"}` → **フォーカスリングが青でなくエメラルド**であること・optgroup の見出し（期間 / 年度）が出ること。
- `javascript_tool`: `getComputedStyle(document.querySelector('.analysis-select')).appearance` → `"none"`（style が効いている＝クラス名の綴り間違い検知）。

- [ ] **Step 8: 後片付け（git status を汚さない）**

```bash
pkill -f "artisan serve"
rm -f /Users/masanori/site/manage/.claude/worktrees/tenant-analysis-period-selector/public/analysis.html
rm -f /Users/masanori/site/manage/.claude/worktrees/tenant-analysis-period-selector/public/build
rm -f /private/tmp/claude-501/-Users-masanori-site-manage/42047dcb-b28d-48f0-a08b-91a6d32d11d1/scratchpad/verify.sqlite
cd /Users/masanori/site/manage && git status --short
```
Expected: **何も出ない**（clean）。

---

## Task 5: 最終レビュー → デプロイ

- [ ] **Step 1: holistic レビュー**

working tree が clean だと `/code-review` の "current diff" が空になる → **明示 range `db71bd52..HEAD` ＋プロジェクト規約チェックリスト**を渡した opus subagent で実施する。チェック項目: Bug #1（名前付き `x-data`）/ #16（`<option>` 静的）/ #19（Vite 未ビルドクラス）/ #22（キャスト済み enum に `tryFrom`）/ #23・#26（`Js::from`・`@json` に多行配列）/ 金額表示規約 / `phpunit.xml` の `APP_URL` 行 / `->links()` 不使用。

**指摘は必ず検証してから直す**（前回、reviewer の提案 assert 値が実際は誤りで、そのまま入れたら fail した）。

- [ ] **Step 2: 本番デプロイの明示承認を取る**

AskUserQuestion で本番デプロイ可否を確認する（承認文脈が無いと自動モード分類器が `./deploy.sh` を止める）。回避のための rsync/ssh 直叩きは**しない**。

- [ ] **Step 3: デプロイ**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```
DB 変更・新規 PHP クラスとも無し → SQL 実行・`composer dump-autoload` は不要。

- [ ] **Step 4: 本番確認**

本番画面は認証必須でアシスタントはログインできない（パスワード入力は禁止事項）→ **ユーザーに目視確認を依頼**する（`/tenant/analysis` の月別セレクトで 全期間 / 直近N年 / 単年 が切り替わるか）。

- [ ] **Step 5: worktree 掃除・push**

`superpowers:finishing-a-development-branch` に従う。origin/13.x への push は**ユーザーの明示指示があった時のみ**（モック doc `13c8c7fc`・設計書 `5163080b` も一緒に上がる）。
