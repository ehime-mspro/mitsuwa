# テナント契約・解約分析 月別集計 年度セレクト追加 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** テナント「契約・解約分析」(`/tenant/analysis`) の月別集計カードに年度セレクトを追加し、選択年度の月別棒グラフへリロードなしで切替（総計バッジ連動・初期表示は全期間）。

**Architecture:** サービス層に年度別の月別内訳 `byMonthByYear` を追加 → コントローラが Chart.js 用 `month` payload に全期間 `all` と年度別 `years` を持たせる → ビューに `@foreach` 静的 `<option>` の年度セレクトを追加 → Alpine が `Chart.getChart()` で raw インスタンスを取得し `data.datasets[0].data` を差し替えて `update()`。DB 変更・ルート変更・新規 PHP クラスとも一切なし（既存4ファイルの改修のみ）。

**Tech Stack:** Laravel 12 / PHP 8.3(prod)/8.5(local) / Blade + Alpine.js 3 / Chart.js 4.4.1 (CDN `cdn.jsdelivr.net`) / PHPUnit + SQLite in-memory

**設計の正:** `docs/superpowers/specs/2026-07-14-tenant-analysis-month-year-selector-design.md`（本番稼働中の前身 `13f101f6` の上に月別チャートの動的データ更新を足す）

---

## 罠チェックリスト（実装時に必ず遵守・docs/RULES.md）

- **Bug #16**: セレクト `<option>` は `@foreach` 静的注入。`<template x-for>` 禁止（x-model 同期前レンダリングで値ズレ）。
- **Bug #1**: `x-data="tenantAnalysis()"` は名前付き関数（末尾 `<script>` 定義）。アロー関数 x-data 禁止。
- **Bug #23/#26**: Chart データは `\Illuminate\Support\Js::from($charts)`。`@json` を x-data 属性/多行配列に入れない（現状踏襲）。
- **Bug #19**: セレクト・バッジは inline style（新規 Tailwind クラス追加なし）。
- **Bug #22**: 解約判定は `status === ContractStatus::Terminated`（比較）。キャスト済み enum に `tryFrom()` を使わない（現状踏襲）。
- **SQL 日付関数禁止**: 集計は PHP/Carbon（SQLite テスト対応）。`selectRaw('YEAR/MONTH')` を混入させない（現状踏襲）。
- **前身 finding の回避**: 月別データ更新は `Chart.getChart(canvasId)` で raw インスタンスを取得。`this.charts` の Alpine reactive proxy を経由しない。

---

## Setup（実装開始前・1回のみ）

worktree は `superpowers:using-git-worktrees` で作成する（13.x=d16d74ea＝spec コミットが HEAD）。

```bash
# main repo で（cd を明示）
cd /Users/masanori/site/manage
git worktree add .claude/worktrees/tenant-analysis-month-selector 13.x
cd .claude/worktrees/tenant-analysis-month-selector
composer install   # worktree に vendor 無し（dev込みで入れる）
```

- **worktree に `.env` は Write/cp 不可（権限保護）** → テストは APP_KEY 環境変数方式で実行:
  ```bash
  APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= \
    vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php
  ```
  （この鍵はテスト用ダミー。無効なら `php artisan key:generate --show` で再生成し以降その値を使う）
- 単一テスト実行は `--filter <method_name>` を付す。

**現在の全12テストが green であることを着手前に確認**（回帰ベースライン）:
Run: `APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php`
Expected: `OK (12 tests, ...)`

---

## File Structure（変更対象・責務）

| ファイル | 区分 | 責務（この変更で担う範囲） | Task |
|---|---|---|---|
| `app/Services/Tenant/ContractAnalysisService.php` | 改修 | `summarize()` に `byMonthByYear`（年降順・年度別の12月内訳＋total）を追加。`byYear`/`byMonth` は不変 | 1 |
| `app/Http/Controllers/Tenant/AnalysisController.php` | 改修 | `chartPayload()` の `month` を `{labels, all, years}` に（`values`→`all` リネーム＋`years` 追加） | 2 |
| `resources/views/tenant/analysis/index.blade.php` | 改修 | `tenantAnalysis()` に `monthYear`/`monthTotalText`/`updateMonth()` を追加。`init()` でバッジ初期化・`render()` を明示 `{labels, values}` 化（月は `data.month.all`） | 2 |
| `resources/views/tenant/analysis/_charts.blade.php` | 改修 | **月別カードのみ**: ヘッダーに年度セレクト（データあり時のみ）＋総計バッジを `x-text` 連動化。年別カードは不変 | 3 |
| `tests/Feature/Tenant/ContractAnalysisTest.php` | 改修 | T12（byMonthByYear 集計・年降順）／T13（空データ）／T14（HTTP セレクト描画）を追加 | 1, 3 |

**タスク順序の根拠（各コミットを壊さないため）:**
- Task 2 でコントローラ payload の `month.values` を `month.all` にリネームするため、`data.month.values` を読む既存 `render()` を**同一コミット**で修正する必要がある（∴ コントローラと index.blade.php は分割不可）。
- Task 3 の `_charts` の `@change="updateMonth()"`/`x-text="monthTotalText.*"` は Task 2 の Alpine 状態・メソッドに依存 → Task 2 → Task 3 の順。
- Task 1（サービス）は独立。Task 3 の T14 セレクト描画は Task 1 の `byMonthByYear` に依存 → Task 1 → Task 3。

---

## Task 1: サービス層 — `byMonthByYear` 追加（TDD）

**Files:**
- Modify: `app/Services/Tenant/ContractAnalysisService.php`
- Test: `tests/Feature/Tenant/ContractAnalysisTest.php`

- [ ] **Step 1: 失敗するテスト T12・T13 を追加**

`tests/Feature/Tenant/ContractAnalysisTest.php` の末尾（`test_empty_data_renders_no_data_message` の閉じ `}` の直後、クラス閉じ `}` の直前）に以下2メソッドを追加:

```php
    /** T12: 年度別の月別集計（byMonthByYear）。年降順キー・各年 values(index0=1月)/total */
    public function test_month_by_year_summary(): void
    {
        $this->makeContract('tenant', '2024-08-10');
        $this->makeContract('tenant', '2024-08-25');
        $this->makeContract('tenant', '2024-03-05');
        $this->makeContract('tenant', '2025-08-20');

        $byYear = (new ContractAnalysisService)->build()['contract']['byMonthByYear'];

        // 年降順（最新が先頭キー・セレクト表示順）
        $this->assertSame([2025, 2024], array_keys($byYear));
        $this->assertSame(2025, array_key_first($byYear));
        // 2024年: 8月×2・3月×1・計3
        $this->assertSame(2, $byYear[2024]['values'][7]); // 8月（index7）
        $this->assertSame(1, $byYear[2024]['values'][2]); // 3月（index2）
        $this->assertSame(3, $byYear[2024]['total']);
        // 2025年: 8月×1・計1
        $this->assertSame(1, $byYear[2025]['values'][7]); // 8月（index7）
        $this->assertSame(1, $byYear[2025]['total']);
    }

    /** T13: 空データ → byMonthByYear は空配列（年度が1つも無い） */
    public function test_month_by_year_empty(): void
    {
        $byYear = (new ContractAnalysisService)->build()['contract']['byMonthByYear'];

        $this->assertSame([], $byYear);
    }
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php --filter 'test_month_by_year_summary|test_month_by_year_empty'`
Expected: FAIL（`Undefined array key "byMonthByYear"` 等）

- [ ] **Step 3: サービスに `byMonthByYear` を実装**

`app/Services/Tenant/ContractAnalysisService.php` の `summarize()` に `byMonthByYear` を追加する。現状:

```php
    private function summarize(Collection $pairs): array
    {
        return [
            'byYear'  => $this->byYear($pairs->map(fn (array $p) => $p[0])),
            'byMonth' => $this->byMonth($pairs->map(fn (array $p) => $p[1])),
        ];
    }
```

を、以下に置き換える（docblock `@return` も更新）:

```php
    /**
     * [year, month] ペアから年別・月別・年度別月別を組み立てる。
     *
     * @param  Collection<int, array{0:int,1:int}>  $pairs
     * @return array{byYear: array, byMonth: array, byMonthByYear: array}
     */
    private function summarize(Collection $pairs): array
    {
        return [
            'byYear'        => $this->byYear($pairs->map(fn (array $p) => $p[0])),
            'byMonth'       => $this->byMonth($pairs->map(fn (array $p) => $p[1])),
            'byMonthByYear' => $this->byMonthByYear($pairs),
        ];
    }
```

さらに、`byMonth()` メソッドの閉じ `}`（ファイル末尾のクラス閉じ `}` の直前）に、新メソッド `byMonthByYear()` を追加:

```php
    /**
     * 年度別の月別集計。データのある年度のみ・年降順（セレクト表示順）。
     * values は index0=1月 … index11=12月。空データ時は空配列。
     *
     * @param  Collection<int, array{0:int,1:int}>  $pairs
     * @return array<int, array{values: list<int>, total: int}>  年(int) => {values:[1月..12月], total}
     */
    private function byMonthByYear(Collection $pairs): array
    {
        $byYear = [];
        foreach ($pairs as [$y, $m]) {
            if (! isset($byYear[$y])) {
                $byYear[$y] = array_fill(1, 12, 0);
            }
            $byYear[$y][$m]++;
        }
        krsort($byYear); // 年 降順（セレクトは最新が上）

        return array_map(fn (array $counts) => [
            'values' => array_values($counts), // index0=1月 … index11=12月
            'total'  => array_sum($counts),
        ], $byYear);
    }
```

- [ ] **Step 4: テストを実行して成功を確認**

Run: `APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php --filter 'test_month_by_year_summary|test_month_by_year_empty'`
Expected: `OK (2 tests, ...)`

- [ ] **Step 5: 全テストを実行して回帰なしを確認**

Run: `APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php`
Expected: `OK (14 tests, ...)`（既存12＋T12＋T13）

- [ ] **Step 6: コミット**

commit-commands プラグインの `/commit` を使う（対象: サービス＋テスト）。メッセージ例:

```
feat(tenant): 月別集計に年度別内訳 byMonthByYear を追加（サービス層）
```

---

## Task 2: コントローラ payload ＋ Alpine 更新ロジック

**Files:**
- Modify: `app/Http/Controllers/Tenant/AnalysisController.php`
- Modify: `resources/views/tenant/analysis/index.blade.php`

> **このタスクに新規自動テストは無い。** 追加する `month.all`/`month.years` payload と Alpine の `monthYear`/`monthTotalText`/`updateMonth()` は、Task 3 で年度セレクトが描画されるまでビューから到達不能な「配線」であり、動的チャート更新は自動テストで検証不可（spec §7）。健全性は「既存14テスト green（`render()` の `.all` 化後も 200＋canvas 描画）＋ `php -l` ＋ view:cache lint」で担保し、最終的なチャート更新は Task 3 後の実ブラウザ検証で確認する。**この2ファイルは payload 契約（`month.values`→`month.all`）で密結合のため同一コミットで着地させる**（`render()` が `data.month.values` を読むため、コントローラ単独では月別チャートが壊れる）。

- [ ] **Step 1: コントローラ `chartPayload()` の `month` を `{labels, all, years}` に変更**

`app/Http/Controllers/Tenant/AnalysisController.php` の `chartPayload()` 現状:

```php
    private function chartPayload(array $summary): array
    {
        return [
            'year'  => [
                'labels' => array_map(fn (int $y) => (string) $y, $summary['byYear']['labels']),
                'values' => $summary['byYear']['values'],
            ],
            'month' => [
                'labels' => array_map(fn (int $m) => $m . '月', $summary['byMonth']['labels']),
                'values' => $summary['byMonth']['values'],
            ],
        ];
    }
```

を、以下に置き換える（`year` は不変。`month` の `values` を `all` にリネームし `years` を追加）:

```php
    private function chartPayload(array $summary): array
    {
        return [
            'year'  => [
                'labels' => array_map(fn (int $y) => (string) $y, $summary['byYear']['labels']),
                'values' => $summary['byYear']['values'],
            ],
            'month' => [
                'labels' => array_map(fn (int $m) => $m . '月', $summary['byMonth']['labels']), // ['1月'..'12月']
                'all'    => $summary['byMonth']['values'],   // 全期間の12件
                'years'  => collect($summary['byMonthByYear'])
                    ->map(fn (array $d, int $y) => [
                        'year'   => (string) $y,
                        'values' => $d['values'],
                        'total'  => $d['total'],
                    ])
                    ->values()
                    ->all(), // [{year:'2025',values:[..],total:..}, ...] 年降順
            ],
        ];
    }
```

- [ ] **Step 2: `php -l` でコントローラの構文確認**

Run: `php -l app/Http/Controllers/Tenant/AnalysisController.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: index.blade.php の `tenantAnalysis()` を更新**

`resources/views/tenant/analysis/index.blade.php` の `<script>` 内 `tenantAnalysis()` を、以下に置き換える。**変更点は (a) `monthYear`/`monthTotalText` 状態追加、(b) `init()` にバッジ初期化、(c) `render()` を明示 `{labels, values}` 化（月は `data.month.all`）、(d) `updateMonth()` 追加。`bar()` 本体は不変。**

置換対象は現状の関数全体（`function tenantAnalysis() {` から対応する閉じ `}` まで）:

```js
    function tenantAnalysis() {
        return {
            tab: 'contract',
            built: { contract: false, termination: false },
            charts: {},
            monthYear: { contract: 'all', termination: 'all' },       // 選択中の年度（'all' or '2024'）
            monthTotalText: { contract: '', termination: '' },         // 月別バッジ文言

            init() {
                // 月別バッジ初期値（全期間計）
                ['contract', 'termination'].forEach(w => {
                    const all = (TENANT_ANALYSIS_CHARTS[w].month.all || []);
                    this.monthTotalText[w] = '総計 ' + all.reduce((a, b) => a + b, 0).toLocaleString() + '件';
                });
                // 初期タブ（契約）はレイアウト確定後に描画（幅0回避）
                this.$nextTick(() => this.render('contract'));
            },

            show(which) {
                this.tab = which;
                // display:none → 表示に切り替わった後に描画 / リフロー
                this.$nextTick(() => this.render(which));
            },

            render(which) {
                if (this.built[which]) {
                    // 既存チャートは表示時にリフロー（幅を再計算して棒を再配置）
                    (this.charts[which] || []).forEach(c => { c.resize(); c.update('none'); });
                    return;
                }
                this.built[which] = true;
                const data = TENANT_ANALYSIS_CHARTS[which];
                this.charts[which] = [
                    this.bar('chart-' + which + '-year', { labels: data.year.labels, values: data.year.values }),
                    this.bar('chart-' + which + '-month', { labels: data.month.labels, values: data.month.all }),
                ].filter(Boolean); // 空データ（canvas 無し）は null → 除外
            },

            // 年度セレクト変更 → 月別チャートの data と総計バッジを更新
            updateMonth(which) {
                const md = TENANT_ANALYSIS_CHARTS[which].month;
                const sel = this.monthYear[which];
                let values, total;
                if (sel === 'all') {
                    values = md.all;
                    total  = md.all.reduce((a, b) => a + b, 0);
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

            bar(canvasId, ds) {
                const el = document.getElementById(canvasId);
                if (!el) return null;
                return new Chart(el, {
                    type: 'bar',
                    data: { labels: ds.labels, datasets: [{
                        data: ds.values,
                        backgroundColor: 'rgba(5,150,105,0.82)',
                        hoverBackgroundColor: '#059669',
                        borderRadius: 4, maxBarThickness: 48,
                    }]},
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        // 非表示→表示やコンテナ幅変化のたびに棒を再配置（左寄り防止）
                        onResize: (chart) => requestAnimationFrame(() => chart.update('none')),
                        plugins: { legend: { display: false }, tooltip: { displayColors: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { precision: 0, color: '#9CA3AF', font: { size: 11 } }, grid: { color: '#F3F4F6' } },
                            x: { ticks: { color: '#6B7280', font: { size: 11 } }, grid: { display: false } },
                        },
                    },
                });
            },
        };
    }
```

- [ ] **Step 4: 全テストを実行して回帰なしを確認**

Run: `APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php`
Expected: `OK (14 tests, ...)`（T10 が 200＋canvas 描画を担保）

- [ ] **Step 5: view:cache lint（Bug #26 型対策・コンパイル済み PHP を lint）**

Run:
```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```
Expected: `INVALID:` 行が1つも出ない（＋ view:cache/clear が成功）

- [ ] **Step 6: コミット**

`/commit`（対象: コントローラ＋index.blade.php）。メッセージ例:

```
feat(tenant): 月別集計 payload に年度別データ追加＋Alpine 更新ロジック
```

---

## Task 3: ビュー — 月別カードに年度セレクト＋バッジ連動（TDD）

**Files:**
- Modify: `resources/views/tenant/analysis/_charts.blade.php`（月別カードのみ）
- Test: `tests/Feature/Tenant/ContractAnalysisTest.php`

- [ ] **Step 1: 失敗するテスト T14 を追加**

`tests/Feature/Tenant/ContractAnalysisTest.php` の末尾（クラス閉じ `}` の直前）に追加:

```php
    /** T14: 複数年データで月別カードに年度セレクトの option（全期間・◯◯年）が描画される */
    public function test_month_year_selector_rendered(): void
    {
        $this->makeContract('tenant', '2024-08-10');
        $this->makeContract('tenant', '2025-03-05');

        $response = $this->actingAs($this->executive())->get('/tenant/analysis');

        $response->assertOk();
        $response->assertSee('全期間');
        $response->assertSee('2024年');
        $response->assertSee('2025年');
    }
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php --filter test_month_year_selector_rendered`
Expected: FAIL（`Failed asserting that ... contains "全期間"`）

- [ ] **Step 3: `_charts.blade.php` の月別カードを改修**

`resources/views/tenant/analysis/_charts.blade.php` の**月別集計カード全体**（現状の2つ目の `<div class="bg-white ...">` ブロック＝`{{-- 月別集計カード --}}` コメントから対応する閉じ `</div>` まで）を、以下に置き換える。**年別集計カード（1つ目のブロック）と冒頭 `@php` は変更しない。**

```blade
{{-- 月別集計カード --}}
<div class="bg-white border border-gray-200 rounded-lg" style="padding:16px 18px; margin-bottom:16px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="width:8px; height:16px; border-radius:3px; background:#059669; display:inline-block;"></span>
            <span style="font-size:14px; font-weight:700; color:#111827;">月別集計</span>
            <span style="font-size:12px; color:#9CA3AF; font-weight:500;">{{ $noun }}月ごとの合計件数</span>
        </div>
        <div style="display:flex; align-items:center; gap:10px;">
            @if($monthTotal !== 0)
                <select x-model="monthYear.{{ $prefix }}" @change="updateMonth('{{ $prefix }}')"
                        style="font-size:12px; color:#374151; background:white; border:1px solid #d1d5db; border-radius:6px; padding:4px 8px; cursor:pointer;">
                    <option value="all">全期間</option>
                    @foreach($summary['byMonthByYear'] as $year => $d)
                        <option value="{{ $year }}">{{ $year }}年</option>
                    @endforeach
                </select>
            @endif
            <span style="font-size:12px; font-weight:700; color:#047857; background:#ECFDF5; border:1px solid #A7F3D0; border-radius:999px; padding:3px 12px; white-space:nowrap;"
                  x-text="monthTotalText.{{ $prefix }}">総計 {{ number_format($monthTotal) }}件</span>
        </div>
    </div>
    @if($monthTotal === 0)
        <div style="padding:40px; text-align:center; color:#9CA3AF; font-size:14px;">{{ $noun }}データがありません</div>
    @else
        <div style="width:100%; height:300px; position:relative;"><canvas id="chart-{{ $prefix }}-month"></canvas></div>
    @endif
</div>
```

- 要点: `<option>` は `@foreach` 静的注入（Bug #16）。セレクトは `@if($monthTotal !== 0)` のときのみ（＝canvas と同条件でデータあり時のみ）。バッジは `x-text="monthTotalText.{{ $prefix }}"` ＋ Blade フォールバック `総計 {{ number_format($monthTotal) }}件`（Alpine 未評価時のちらつき防止・同じ全期間計で上書き）。説明文から「（全年合算）」を除去（動的表示のため）。

- [ ] **Step 4: T14 を実行して成功を確認**

Run: `APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php --filter test_month_year_selector_rendered`
Expected: `OK (1 test, ...)`

- [ ] **Step 5: 全テストを実行して回帰なしを確認**

Run: `APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php`
Expected: `OK (15 tests, ...)`（既存12＋T12＋T13＋T14）

- [ ] **Step 6: view:cache lint（Bug #26 型対策）**

Run:
```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```
Expected: `INVALID:` 行が1つも出ない

- [ ] **Step 7: コミット**

`/commit`（対象: `_charts.blade.php`＋テスト）。メッセージ例:

```
feat(tenant): 月別集計カードに年度セレクトを追加（表示層）
```

---

## Post-Implementation（メインセッションで実施・subagent タスクではない）

実装3タスク完了後、以下を順に行う（spec §6）。

- [ ] **A. `/review`（code-review high）でセルフレビュー**（過去バグ＋project conventions）。指摘は fix → 再検証。

- [ ] **B. main repo へ FF-merge**
  ```bash
  cd /Users/masanori/site/manage
  git checkout 13.x && git merge --ff-only tenant-analysis-month-selector
  ```
  - 新規 PHP クラスなし → `composer dump-autoload` 不要。DB 変更なし → SQL 実行不要。

- [ ] **C. view:cache lint（本番同等・main repo で）**
  ```bash
  php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
  ```

- [ ] **D. 実ブラウザ検証（認証必須画面手法・memory: `project_local_verify_env_and_technique`）**

  検証観点（spec §6-5）:
  - **契約タブ**: 月別セレクトで「全期間 → 2024 → 2025 …」と切替え、棒グラフが該当年度の1〜12月に更新され**崩れない**こと・総計バッジが連動すること（全期間計 → 各年度計）・console エラー 0。
  - **解約タブ（初期非表示）**: タブ切替後に月別セレクト切替が同様に効くこと（`Chart.getChart` が両タブで正しく raw インスタンスを取得）。
  - **全期間へ戻す**と現状（前身 `13f101f6`）と同じ表示になること。
  - 手順: SQLite で `migrate` → tinker で seed＋`Auth::login` → `$controller->index()->render()` を `public/*.html` 出力（`URL::forceRootUrl` で同一オリジン化）→ main repo の `public/build` を worktree に symlink → `artisan serve --port=8000` → Browser pane で `127.0.0.1:8000/*.html`。検証後 `rm public/*.html` ＋ build symlink 除去・`artisan serve` 停止・`git status` clean 確認。

- [ ] **E. `./deploy.sh`（要ユーザー明示承認）**
  - AskUserQuestion で本番デプロイ可否を明示確認し承認文脈を作る（memory: `project_deploy_needs_explicit_user_authorization`）。
  - deploy 後、本番で Playwright 動作確認（任意）。

- [ ] **F. origin/13.x push（ユーザー明示指示時のみ）**
  - spec `d16d74ea`（未push）も一緒に上がる。push 前後で `git rev-parse` の SHA 一致を確認。

**注意（rsync --delete 無し）:** 今回は**ファイル削除が無い**（前身の `_matrix.blade.php` 削除のような掃除は不要）ため、本番残存物の手動掃除は発生しない。

---

## Self-Review（spec との突合・writing-plans チェックリスト）

**1. Spec coverage:**
- §4.1 サービス `byMonthByYear`（年降順 krsort・data のある年度のみ・空時 `[]`）→ Task 1 ✓
- §4.2 コントローラ `month.all`＋`month.years`（年降順・`year` を string 化）→ Task 2 Step 1 ✓
- §4.3 ビュー 年度セレクト（`@foreach` 静的 option・データあり時のみ）＋バッジ `x-text`＋Blade フォールバック → Task 3 Step 3 ✓
- §4.4 Alpine `monthYear`/`monthTotalText`/`updateMonth()`（`Chart.getChart` で raw インスタンス）＋`init()` バッジ初期化＋`render()` の `{labels, values}` 化 → Task 2 Step 3 ✓
- §5 テスト T12/T13/T14 → Task 1 Step 1（T12/T13）・Task 3 Step 1（T14）✓
- §3.2 年別カード・空データ分岐・ルート/権限/DB 不変 → いずれのタスクも触れない ✓

**2. Placeholder scan:** 各コード step は完全な実コードを掲載。TBD/TODO/「適宜」無し。Task 2 に新規テストが無い点は「payload/Alpine 配線＝ビュー到達前で自動テスト不可・チャート更新は自動テスト不可」の理由を明記し、既存14 green＋lint＋実ブラウザで担保する旨を明示（プレースホルダではなく意図的な検証方針）✓

**3. Type consistency:**
- サービス戻り値キー `byMonthByYear`（Task 1）＝コントローラ `collect($summary['byMonthByYear'])`（Task 2）＝ビュー `$summary['byMonthByYear']`（Task 3）で一致 ✓
- payload `month.all`/`month.years`（Task 2）＝ Alpine `md.all`/`md.years`（Task 2 `updateMonth`/`init`）で一致 ✓
- `years[].year` は string（Task 2）＝ `updateMonth` の `o.year === sel`（sel は `<option value="{{ $year }}">` の string）で `===` 一致 ✓
- Alpine 状態 `monthYear.{prefix}`/`monthTotalText.{prefix}`（`prefix` ∈ {contract, termination}）＝ index の state キー・ビューの `x-model`/`x-text` で一致 ✓
- canvas id `chart-{prefix}-month`（ビュー）＝ `Chart.getChart('chart-'+which+'-month')`（Alpine）で一致 ✓
