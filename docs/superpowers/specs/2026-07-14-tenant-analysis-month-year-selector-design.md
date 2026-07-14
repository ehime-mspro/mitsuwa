# テナント契約・解約分析 月別集計 年度セレクト追加 設計書

- 作成日: 2026-07-14
- 対象: テナント管理「契約・解約分析」（`/tenant/analysis`）の**月別集計カード**
- 種別: 既存 read-only 集計画面への UI 機能追加（DB 変更なし・ルート/権限/サイドバー変更なし）
- 前身 spec: `docs/superpowers/specs/2026-07-14-tenant-analysis-year-month-split-design.md`（年別・月別分割リデザイン。本番稼働中 `13f101f6`）
- 先例 / 罠:
  - Chart.js × Alpine `x-show` 非表示タブ（遅延初期化＋resize/update＋onResize）は前身で実装・本番稼働。**本 spec はその上に月別チャートの動的データ更新を足す**。
  - Bug #16（`<option>` を `<template x-for>` で生成せず `@foreach` 静的注入）／Bug #23/#26（`@json` を x-data 属性/多行に入れない→`Js::from`）／Bug #19（inline style）。
  - 前身 code-review finding: Chart.js インスタンスを Alpine reactive `x-data`(`this.charts`) に格納する点（実測動作するがアンチパターン）。**本 spec の月別データ更新は `Chart.getChart()` で raw インスタンスを取得**し、reactive proxy を経由しない（finding の実質的回避）。

## 1. 背景・目的

前身リデザインで月別集計は「全年合算（全期間の月別件数）」を1枚の棒グラフで表示している。しかし「特定の年だけの月別の傾向（季節性）」を見たいニーズがある。そこで**月別集計カード内に年度セレクトボックスを追加**し、選択した年度の月別集計に瞬時に切り替えられるようにする。

達成すること（契約・解約の両タブで統一）:
1. 月別集計カードに**年度セレクト**を追加。先頭「全期間（全年合算）」＋データのある各年度（降順）。
2. セレクト選択で、その年度の月別棒グラフ（1〜12月）に**リロードなしで切替**。総計バッジも選択年度の合計に連動。
3. 初期表示は**全期間（全年合算）**＝現状の見た目を維持。
4. **年別集計カードは変更なし**。

## 2. 決定事項（確定）

前セッションの brainstorming でユーザー確認済み。

| # | 論点 | 決定 |
|---|---|---|
| E1 | 全期間の扱い | セレクト先頭に「全期間（全年合算）」＋各年度。**初期表示＝全期間**（現状維持に年度絞込を追加） |
| E2 | 年度の定義 | **暦年（1〜12月）**。現状の集計軸（`contract_date`/`contract_end_date` の暦年・暦月）のまま。X 軸は 1月〜12月固定 |
| E3 | セレクトの範囲 | **データのある全年度**（全期間の全年度・10年で切らない）。降順（最新年度が上）＋先頭「全期間」 |
| E4 | 切替方式 | **クライアントサイド**（全年度分の月別データを `Js::from` で埋め込み、Alpine セレクト＋`Chart.getChart().update()` で切替。リロードなし） |
| E5 | 総計バッジ連動 | 月別カードのバッジは選択に連動（全期間＝全期間計／年度＝その年度計。例 全期間44件→2024年6件） |
| E6 | 対象タブ | 契約・解約**両タブ**（`_charts` 共用）。年別集計カードは不変 |
| E7 | ルート/権限/サイドバー/DB | すべて変更なし |

## 3. スコープ

### 3.1 変更対象ファイル

| ファイル | 区分 | 変更内容 |
|---|---|---|
| `app/Services/Tenant/ContractAnalysisService.php` | 改修 | `summarize()` に **`byMonthByYear`**（`{年: {values:[12], total}}`・データのある全年度・年降順）を追加。既存 `byYear`/`byMonth` はそのまま |
| `app/Http/Controllers/Tenant/AnalysisController.php` | 改修 | `chartPayload()` の `month` に `all`（全期間 values）＋`years`（`[{year,values,total}]` 年降順）を追加 |
| `resources/views/tenant/analysis/_charts.blade.php` | 改修 | **月別カードのヘッダーに年度セレクト**（静的 `@foreach` option・データあり時のみ）＋総計バッジを Alpine 連動に |
| `resources/views/tenant/analysis/index.blade.php` | 改修 | `tenantAnalysis()` に `monthYear`/`monthTotalText` 状態と `updateMonth()`（`Chart.getChart` で month チャートの `data` 差し替え＋`update()`）を追加。`init()` でバッジ初期化 |
| `tests/Feature/Tenant/ContractAnalysisTest.php` | 改修 | `byMonthByYear` の集計検証（新テスト）＋HTTP（セレクト option 描画） |

### 3.2 変更しないファイル
- `routes/web.php`・`sidebar.blade.php`・DB スキーマ（一切なし）。
- **年別集計カード**（`_charts.blade.php` の前半）は変更しない。

### 3.3 スコープ外
- 年別集計へのセレクト追加（月別のみ）。
- 会計年度（5/1始まり）軸（暦年のみ・E2）。
- 金額集計・物件別/担当者別フィルタ・CSV/Excel・ドリルダウン・他部署展開。

## 4. 実装設計

### 4.1 サービス `ContractAnalysisService`（改修）

`summarize()` は既存 `byYear`/`byMonth` に加え `byMonthByYear` を返す。`[year, month]` ペア抽出（契約=全 status/contract_date、解約=terminated/end_date、department=tenant、SoftDelete 除外）は現状のまま流用。

```php
private function summarize(Collection $pairs): array
{
    return [
        'byYear'        => $this->byYear($pairs->map(fn (array $p) => $p[0])),
        'byMonth'       => $this->byMonth($pairs->map(fn (array $p) => $p[1])),
        'byMonthByYear' => $this->byMonthByYear($pairs),
    ];
}

/**
 * 年度別の月別集計。データのある年度のみ・年降順（セレクト表示順）。
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

- SQL 日付関数不使用（PHP/Carbon）を維持。空データ時は `byMonthByYear = []`（年度が1つも無い）。
- 既存 `byMonth`（全年合算）は総計バッジ・空判定・全期間表示に引き続き使用。

### 4.2 コントローラ `AnalysisController::chartPayload()`（改修）

`month` に「全期間」＋「各年度」のデータを持たせる。年ラベルは「◯月」（既存）。

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
                ->all(), // [{year:'2026',values:[..],total:..}, ...] 年降順
        ],
    ];
}
```

- `byMonth.total`（全期間計）は総計バッジ初期値に使う（ビューへ既存どおり `$summary` を渡す）。

### 4.3 ビュー `_charts.blade.php`（月別カードのみ改修）

月別カードのヘッダーに**年度セレクト**を追加。バッジは Alpine 連動（`x-text`）に。**年別カード・空データ分岐は現状維持**。セレクトは canvas と同じく `@if($monthTotal === 0)` の else（データあり時のみ）に置く。

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

- `<option>` は **`@foreach` で静的注入**（Bug #16 回避）。`x-model` は `monthYear.contract`/`monthYear.termination`。
- バッジは `x-text="monthTotalText.{{ $prefix }}"` で Alpine 管理。**Blade 側にも初期値 `総計 {{ number_format($monthTotal) }}件` を残す**（Alpine 未評価時のフォールバック表示＝ちらつき防止。Alpine 初期化で同じ全期間計に上書き）。
- 「全年合算」の注記は動的表示にそぐわないため説明文から外す（`{{ $noun }}月ごとの合計件数`）。

### 4.4 Alpine `tenantAnalysis()`（index.blade.php・改修）

状態 `monthYear`/`monthTotalText` と `updateMonth()` を追加。**月別チャートは `Chart.getChart()` で raw インスタンスを取得**して `data` を差し替える（`this.charts` の reactive proxy を経由せず＝前身 finding の回避）。既存の `tab`/`built`/`charts`/`init`/`show`/`render`/`bar` は維持。

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
            this.$nextTick(() => this.render('contract'));
        },

        show(which) {
            this.tab = which;
            this.$nextTick(() => this.render(which));
        },

        render(which) {
            if (this.built[which]) {
                (this.charts[which] || []).forEach(c => { c.resize(); c.update('none'); });
                return;
            }
            this.built[which] = true;
            const data = TENANT_ANALYSIS_CHARTS[which];
            this.charts[which] = [
                this.bar('chart-' + which + '-year', { labels: data.year.labels, values: data.year.values }),
                this.bar('chart-' + which + '-month', { labels: data.month.labels, values: data.month.all }),
            ].filter(Boolean);
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
                const y = md.years.find(o => o.year === sel);
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

        bar(canvasId, ds) { /* 現状のまま（labels/values で new Chart） */ },
    };
}
```

- `bar()` の呼び出しシグネチャを `{labels, values}` に統一（現状 `data.year`/`data.month` を直接渡していたのを、`data.month.all` を使うため明示的に組み立てる）。`bar()` 本体は不変。
- `Chart.getChart(canvasId)` は Chart.js 4 の公式 API（canvas id/element から既存インスタンスを返す）。`this.charts` を使わないので Alpine reactive proxy の影響を受けない。
- 総計は JS 側で `values.reduce`／`y.total`。`toLocaleString()` で3桁区切り（`number_format` 相当）。

## 5. テスト計画（`ContractAnalysisTest.php` 改修）

既存 T1〜T11＋T1b（12本）は維持。以下を追加:

| # | 検証 | 主眼 |
|---|---|---|
| T12 | **年度別月別集計**: 2024/8×2・2024/3×1・2025/8×1 → `byMonthByYear[2024].values[7]`(8月)=2・`[2]`(3月)=1・`total`=3、`byMonthByYear[2025].values[7]`=1・`total`=1。キーは**年降順**（最初のキーが 2025） | `byMonthByYear` 集計 |
| T13 | **空データ**: 0件 → `byMonthByYear = []`（空配列） | ゼロ状態 |
| T14 | **HTTP・セレクト描画**: 複数年データで `GET /tenant/analysis` → 200、`全期間`・`◯◯年`（例 `2024年`）の option 文字列を `assertSee` | セレクト描画 |

- T12 は `build()['contract']['byMonthByYear']` を直接検証（DB シードのみ）。年降順は `array_key_first()` で先頭キーを確認。
- 既存の `byYear`/`byMonth` テストは不変（後方互換）。

## 6. デプロイ・検証手順

前身と同じ。**DB 変更・ルート変更・新規 PHP クラスとも なし**。

1. worktree（`.claude/worktrees/tenant-analysis-month-selector` 等）で実装。`composer install`（dev込み）＋テストは `APP_KEY=base64:... vendor/bin/phpunit`（`.env` は権限で作れない）。
2. `/commit`（サービス / コントローラ+ビュー / テスト を論理単位で）。
3. main repo で `git checkout 13.x && git merge --ff-only <branch>`。
4. **view:cache lint**（Bug #26 型対策）: `php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear`。
5. **実ブラウザ検証**（認証必須画面・SQLite seed→`Auth::login`→render→`artisan serve`→Browser。前身で確立した手法）:
   - 契約タブ: 月別セレクトで「全期間→2024→…」と切替え、棒グラフが該当年度に更新され**崩れない**こと・総計バッジ連動・console 0。
   - 解約タブ（初期非表示）: タブ切替後にセレクト切替が同様に効くこと（`Chart.getChart` が両タブで正しく取得）。
   - 全期間へ戻して現状と同じ表示になること。
6. `./deploy.sh`（**要ユーザー明示承認**）。
7. origin/13.x push（明示指示時のみ）。

## 7. リスク・留意点

- **Chart.js data 更新の実描画**: `chart.data.datasets[0].data` 差し替え＋`chart.update()` は Chart.js 標準だが、実挙動（棒の再描画・崩れなし）は**実ブラウザ検証必須**（自動テストで描画は検証不可）。`Chart.getChart` で raw インスタンスを取得するため前身 finding（reactive proxy）は回避見込みだが、往復・タブまたぎで実測する。
- **Bug #16**: セレクト `<option>` は `@foreach` 静的注入（`<template x-for>` 不使用）。
- **Bug #23/#26**: Chart データは `Js::from($charts)`。`@json` を x-data 属性/多行配列に入れない。`x-data="tenantAnalysis()"` は名前付き関数（Bug #1）。
- **Bug #19**: セレクト・バッジは inline style（新規 Tailwind クラス追加なし）。
- **総計バッジのフォールバック**: Alpine 未評価時のちらつき防止に Blade 初期値を残す（`x-text` が即座に上書き）。全期間の初期表示は現状と一致。
- **後方互換**: `byYear`/`byMonth`/`chartPayload.year` は不変。年別カードは無変更。
- **enum/SQL の罠**: 集計は現状踏襲（`status === ContractStatus::Terminated`・SQL 日付関数不使用）。
