# テナント契約・解約分析 月別集計 期間セレクト統合＋意匠改善 設計書

- 作成日: 2026-07-15
- 対象: テナント管理「契約・解約分析」（`/tenant/analysis`）の**月別集計カードのセレクト**
- 種別: 既存 read-only 集計画面への UI 機能追加＋意匠改善（DB 変更なし・ルート/権限/サイドバー変更なし）
- 前身 spec:
  - `docs/superpowers/specs/2026-07-14-tenant-analysis-year-month-split-design.md`（年別・月別分割リデザイン。本番稼働中 `13f101f6`）
  - `docs/superpowers/specs/2026-07-14-tenant-analysis-month-year-selector-design.md`（月別カードの年度セレクト。本番稼働中 `db71bd52`）← **本 spec はこの上に足す**
- 承認済みモック: `docs/mockups/tenant/analysis/month-period-selector-mock.html`（`13c8c7fc`）— 現行/A案/B案の意匠比較＋動く月別カード（本番の実分布124件を使用）
- 先例 / 罠:
  - Bug #16（`<option>` は `<template x-for>` でなく `@foreach` 静的注入）／Bug #19（Vite 未ビルドの Tailwind クラスは無効）／Bug #23・#26（`@json` でなく `Js::from`）／Bug #1（`x-data` は名前付き関数）／Bug #22（キャスト済み enum に `tryFrom` 禁止）。
  - Chart.js の raw インスタンス取得（`Chart.getChart()`）で Alpine reactive proxy を回避する構造は前身で確立・本番稼働。**本 spec でも維持する**。

## 1. 背景・目的

前身で月別集計カードに年度セレクト（全期間＋データのある各年度）を追加し本番稼働中。ここに 2 つの課題がある。

1. **意匠**: セレクトがブラウザ既定の native select のままで、フォーカス時に**既定の青いリング**が出てエメラルド基調の画面から浮く。角丸・フォント太さ・高さも周囲（総計バッジ）と揃っていない。
2. **機能**: 「単年」と「全期間」しか無く、**「直近数年」の季節性**（例: 直近4年の月別傾向）を見られない。

達成すること（契約・解約の両タブで統一）:

1. 既存の年度セレクトを**1つのまま拡張**し、`全期間` / `直近2・4・6・8年` / `各年度` を選べる 3 ブロック構成にする。
2. セレクトの意匠を **A 案**（白ベース・独自シェブロン・エメラルドのホバー/フォーカス）に差し替える。
3. 初期表示は**全期間**＝現状の見た目を維持。**年別集計カードは変更しない**。

## 2. 決定事項（確定）

ユーザー承認済み（モック `13c8c7fc` のレビューで確定）。

| # | 論点 | 決定 |
|---|---|---|
| E1 | セレクトの数 | **1つのまま**（増やさない）。既存の年度セレクトを拡張する |
| E2 | 構成 | **3ブロック**。`<optgroup>` で見出し: `全期間` / ──期間── `直近2/4/6/8年` / ──年度── `2026年` `2025年` …（降順・データのある全年度）。**2つのセレクトを並べる案は却下**（「直近2年」と「2024年」が同時選択され意味が矛盾するため） |
| E3 | 直近N年の定義 | **今年から暦年で遡って N 年（今年を含む）**。データが無い年も 0 件として含む（常に N 年ぶんの固定窓）。**今日=2026年なので直近2年 = 2026年・2025年**（暦年ベース。会計年度 5/1 始まりではない） |
| E4 | 期間の候補 | **2 / 4 / 6 / 8 年** の4つ |
| E5 | 初期値 | **全期間**（現状の見た目を維持） |
| E6 | 意匠 | **A案**（モックの `.sel-a`）: `appearance:none` ＋独自シェブロン SVG／角丸 8px／`font-weight:600`／**ホバー=薄いエメラルド・フォーカス=エメラルドのリング**（既定の青リングを潰すのが主目的）／高さを総計バッジと揃える |
| E7 | 年別集計カード | **変更なし**（最大10年のまま・セレクト無し） |
| E8 | 直近N年の合算場所 | **PHP 側（サービス）で算出**。理由: ①自動テストで検証できる（JS 計算はブラウザでしか検証できず、本プロジェクトは silent failure の前科が多い）②「今年」を**サーバー時刻**（`now()->year`）で決められ、クライアント時計に依存しない |
| E9 | ルート/権限/サイドバー/DB | すべて変更なし |

### 2.1 用語について（重要・将来の「修正」防止）

optgroup の見出しは **「年度」** だが、中身は **暦年（1〜12月）** である（前身 spec E2 で確定した集計軸）。画面の既存コピーもモックも一貫して「年度」と表記しており、ユーザーはその表記で承認している。**「暦年」への言い直しはしない**。

## 3. スコープ

### 3.1 変更対象ファイル

| ファイル | 区分 | 変更内容 |
|---|---|---|
| `app/Services/Tenant/ContractAnalysisService.php` | 改修 | 定数 `PERIOD_YEARS = [2,4,6,8]` ＋ **`byMonthByPeriod`**（`{N: {values:[12], total}}`）を `summarize()` に追加。既存 `byYear`/`byMonth`/`byMonthByYear` は不変 |
| `app/Http/Controllers/Tenant/AnalysisController.php` | 改修 | `chartPayload()` の `month` に **`periods`**（`[{key:'lastN',values,total}]`・N 昇順）を追加。既存 `labels`/`all`/`years` は不変 |
| `resources/views/tenant/analysis/_charts.blade.php` | 改修 | 月別カードのセレクトを **3ブロック（`<optgroup>`）** に。インライン style → `class="analysis-select"` |
| `resources/views/tenant/analysis/index.blade.php` | 改修 | **`<style>` ブロック（`.analysis-select` ＝ A案）を1つ追加**＋`updateMonth()` に `lastN` 分岐を追加＋導入文の文言更新 |
| `tests/Feature/Tenant/ContractAnalysisTest.php` | 改修 | **時刻凍結**（`setUp`/`tearDown`）＋期間集計・payload・セレクト描画の検証を追加（T16〜T20） |

### 3.2 変更しないファイル

- `routes/web.php`・`sidebar.blade.php`・DB スキーマ・`phpunit.xml`（`APP_URL=http://localhost` 固定行は死守）。
- **年別集計カード**（`_charts.blade.php` 前半）・`bar()`・`render()`・`init()`・`show()`。
- 既存の集計軸（契約=`contract_date`／解約=`terminated`＋`contract_end_date`／`department=tenant`／SoftDelete 除外）。

### 3.3 スコープ外

- 年別集計へのセレクト追加（月別のみ・E7）。
- 会計年度（5/1 始まり）軸・任意期間（from/to 指定）・期間候補のユーザー設定。
- 金額集計・物件別/担当者別フィルタ・CSV/Excel・ドリルダウン・他部署展開。

## 4. 実装設計

### 4.1 サービス `ContractAnalysisService`（改修）

**`byMonthByYear` の出力を合算して期間を組み立てる**（行を再スキャンしない）。これにより「セレクトが提供する年度」と「期間の合算値」が構造的に一致し、二重集計によるズレが起きない。

```php
class ContractAnalysisService
{
    /** 年別集計で表示する最大年数（新しい方から） */
    private const MAX_YEARS = 10;

    /** 月別集計の「直近N年」候補（今年を含む暦年ベース・セレクトの期間グループ順） */
    private const PERIOD_YEARS = [2, 4, 6, 8];
```

```php
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

- **窓の起点は `$thisYear - $n + 1`**（今年を含めて N 年ぶん）。`- $n` はオフバイワン（N+1 年ぶんになる）→ T17 で固定。
- `$byMonthByYear[$y]['values'] ?? []` でデータの無い年を 0 件として素通り（E3）。
- SQL 日付関数不使用（PHP/Carbon）を維持 → SQLite テスト可。
- キー順は `PERIOD_YEARS` の挿入順＝ **2,4,6,8 昇順**（セレクト表示順とビュー/payload の反復順がこれに従う）。
- **空データでも 4 キーが常に存在**し全て 0（`byMonthByYear` が `[]` になるのと非対称。期間は固定窓なので年の有無に依存しない）。

### 4.2 コントローラ `AnalysisController::chartPayload()`（改修）

`month` に `periods` を**追加のみ**（`all`/`years` は不変＝`init()`/`render()`/既存テスト T15 に影響なし）。

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

- **`key` は `'last' . $n` の文字列連結**。JS 側は `o.key === sel` の**厳密比較**なので型が効く。`year` の `(string)` キャストが load-bearing なのと同じ理由だが、こちらは連結の性質上つねに string になる（退行しにくい）。

### 4.3 ビュー `_charts.blade.php`（月別カードのセレクトのみ改修）

```blade
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
```

- `<option>` は**両 optgroup とも `@foreach` 静的注入**（Bug #16）。`<optgroup>` は表示上のグルーピングのみで `x-model` は `select.value` を読むため干渉しない。
- 期間 option もサービスの `byMonthByPeriod` キーから生成する（**候補の正はサービスの `PERIOD_YEARS` 1箇所**。将来 10 年を足すなら定数に足すだけで view/controller/JS は無変更）。
- インライン style を `class="analysis-select"` に置換（`:hover`/`:focus` がインラインで書けないため）。
- セレクト全体が `@if($monthTotal !== 0)` 配下にある点・総計バッジの Blade フォールバック文字列は**現状のまま**。
- 空 optgroup は発生しない（期間は常に4件／年度は `$monthTotal !== 0` なら 1 年以上ある）。

### 4.4 ビュー `index.blade.php`（`<style>` 追加・`updateMonth()` 改修・導入文）

#### 4.4.1 `<style>` の置き場所

**`index.blade.php` の `@section('content')` 内・末尾**（`</div>` の後、`<script>` の前）に置く。

- **`_charts.blade.php` に置いてはいけない**: 契約/解約で 2 回 include されるため `<style>` が重複する。
- レイアウトに styles 用の `@stack` は無い（`layouts/app.blade.php` は `@yield('title')`/`@yield('breadcrumb')`/`@yield('content')` のみ）。
- 先例: `resources/views/tenant/contracts/index.blade.php:159`（同じテナントモジュール。`@section('content')` 内の `<style>` で独自クラス＋`:hover` を定義）ほか 8 ファイル。

```blade
    {{-- 月別集計セレクト（:hover/:focus はインライン style で書けないためクラス化。_charts は2回 include されるため style はこちら側に1つだけ置く） --}}
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
        .analysis-select:hover  { border-color: #6EE7B7; background-color: #F0FDF4; }
        .analysis-select:focus  { outline: none; border-color: #059669; box-shadow: 0 0 0 3px rgba(5, 150, 105, .15); }
    </style>
```

- モック `.sel-a` の値をそのまま移植（クラス名のみ `analysis-select` に改名）。
- **Tailwind クラスを新規に足さない**ので Vite 再ビルド不要（Bug #19 の回避＝自前 CSS で完結）。
- Blade の特殊記号（`{{`・`@directive`）を含まないことを確認済み（`@media` 等は使わない）。

#### 4.4.2 `updateMonth()`（`lastN` 分岐を追加）

```js
            monthRange: { contract: 'all', termination: 'all' },      // 選択中の範囲（'all' / 'last2'…'last8' / '2024'）
```

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

- 分岐順は `all` → `lastN` → 年度。年度の value は `'2024'` 等なので `startsWith('last')` と衝突しない。
- **JS は合算しない**（E8）。`p.values`/`p.total` を PHP から受け取るだけ。
- `init()`・`render()`・`show()`・`bar()` は**無変更**（初期値は `all`＝`md.all` のまま）。

#### 4.4.3 導入文

期間が増えたので実態に合わせる（1行）。

```blade
        <p class="text-sm text-gray-500" style="margin-top:4px;">契約年ごとの件数（最大直近10年の推移）と、契約月ごとの件数（全期間／直近N年／年度別の季節性）を、それぞれ棒グラフで表示します。</p>
```

## 5. テスト計画（`ContractAnalysisTest.php` 改修）

### 5.1 時刻凍結（必須）

**直近N年は `now()->year` 基準のため、凍結しないと 2027-01-01 に自動で落ちる時限爆弾になる。** 既存 `tests/Unit/Tenant/InvestmentRecoveryTest.php` と同じ流儀で、クラス全体を固定する。

```php
    protected function setUp(): void
    {
        parent::setUp();
        // 「現在」を 2026-07-15 に固定（直近N年は now()->year 基準＝実時刻だと年跨ぎで落ちるため）
        Carbon::setTestNow(Carbon::parse('2026-07-15'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
```

- 既存 T1〜T15 は `now()` を読まない（すべて明示日付でシード）ため影響しない見込み。**ただし「見込み」なので、Task 1 で 16 本の既存テストが緑のままであることを実際に走らせて確認する**（フレームワーク側 `InteractsWithTestCaseLifecycle` も自動リセットするが、プロジェクトの既存流儀に合わせて明示 tearDown を書く）。

### 5.2 追加テスト

| # | 検証 | 主眼 |
|---|---|---|
| T16 | **期間の窓**: 2026/1・2025/2・2024/3・2022/4・2020/5・2017/6 に各1件 → `total` が last2=2 / last4=3 / last6=4 / last8=5、`byMonth.total`=6（2017 は全期間のみ）。キーは `[2,4,6,8]`。`values` も検証 | 4 つの窓が別々に効く |
| T17 | **境界（オフバイワン）**: 2026/1・2025/1・2024/1 → last2=2（今年+昨年）・2024 は入らない／last4=3 | E3 の核。`- $n + 1` の固定 |
| T18 | **空データ**: 0件 → 4 キーが存在し全て 0（`byMonthByYear=[]` との非対称） | ゼロ状態 |
| T19 | **payload**: `month.periods` の `key` が `['last2','last4','last6','last8']`（**文字列**）・N 昇順・`total`/`values` 一致。`all`/`years` の非退行も同時に確認 | JS との契約 |
| T20 | **セレクト描画**: `<optgroup label="期間">`/`<optgroup label="年度">`・`<option value="last2">直近2年</option>`・直近4/6/8年。**`.analysis-select:hover` の出現回数が 1**（style を `_charts` に置いた場合の重複検知） | 3ブロック構成＋style 単一性 |

- T20 の `期間` は **`全期間` に部分文字列として含まれる**ため、`assertSee('期間')` では検証にならない → **生 HTML で `<optgroup label="期間">` を厳密比較**（`assertSee(..., false)`）。
- T20 の `<option value="last2">` も生 HTML で検証する。**この value は JS の `startsWith('last')` / `o.key === sel` が依存する契約**なので、書式変更を検知させる意図で厳密に固定する。
- 既存 T1〜T15（16 本）は不変・全て緑を維持すること（後方互換）。

## 6. タスク分割（壊れない中間コミット）

| Task | 内容 | 単独で壊れないか |
|---|---|---|
| 1 | サービス `PERIOD_YEARS`＋`byMonthByPeriod`＋時刻凍結＋T16/T17/T18 | ✅ 誰も読まない新キーが増えるだけ。既存 16 本も緑 |
| 2 | コントローラ `month.periods`＋T19 | ✅ payload にキーが増えるだけ。ビュー/JS は未参照 |
| 3 | ビュー（3ブロック select＋`class`）＋`<style>`＋`updateMonth()` の `lastN` 分岐＋導入文＋T20 | ✅ **この3つは分割不可**（後述） |

**Task 3 を分割してはいけない理由**:
- select に `<option value="last2">` を出しつつ `updateMonth()` に `lastN` 分岐が無いと、選択が年度分岐へ落ちて `!y` → `return` → **グラフもバッジも無反応（例外も出ない silent failure）**。
- `class="analysis-select"` と `<style>` が別コミットだと**素のセレクト**（意匠が消える）。
- 前身で `month.values`→`month.all` のリネームが `render()` を壊した教訓と同型。

## 7. デプロイ・検証手順

**DB 変更・ルート変更・新規 PHP クラスとも なし** → SQL 実行・`composer dump-autoload` 不要。

1. worktree（`.claude/worktrees/tenant-analysis-period-selector`・13.x から）で実装。`composer install`（dev 込み）。
2. テスト: `APP_KEY=base64:vlyBvPwm9T6/Y7YLuWZIXeio0KHHIHYJz7AcrXawiaM= vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php`（worktree に `.env` は作れない＝環境変数方式）。
3. main repo で `git checkout 13.x && git merge --ff-only <branch>`。
4. **view:cache lint**（Bug #26 型対策・`view:cache` の成功表示は当てにならない）:
   `php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear`
5. **実ブラウザ検証**（認証必須画面・**worktree 側**で SQLite seed → `Auth::login` → render → `artisan serve` → Browser pane。前身で確立した手法）:
   - **年ごとに違う月分布**でシードし、`Chart.getChart('chart-contract-month').data.datasets[0].data` を `javascript_tool` で読んで**期待値と厳密比較**（目視しない）。
   - 全期間 → 直近2/4/6/8年 → 単年 → 全期間へ戻す、の順で棒グラフと総計バッジが期待どおり変わること。
   - **解約タブ（初期非表示）**でも同様に効くこと（`Chart.getChart` が両タブで取れる）。
   - `canvas.clientWidth`/`chart.width` が 0 でないこと（x-show 非表示タブの幅0崩れ検知）。
   - console エラー 0。
   - 意匠: フォーカス時に**青いリングが出ない**（エメラルドのリングになる）こと・optgroup の見出しが出ること。
6. `./deploy.sh`（**要ユーザー明示承認**。AskUserQuestion で承認文脈を作る）。
7. origin/13.x push（明示指示時のみ。モック doc `13c8c7fc` も一緒に上がる）。

## 8. リスク・留意点

- **年跨ぎの時限爆弾**: 期間テストは時刻凍結必須（§5.1）。凍結漏れは 2027-01-01 に赤くなる。
- **オフバイワン**: 窓の起点 `$thisYear - $n + 1`（今年含む）。T17 が唯一の防波堤。
- **`<style>` の重複**: `_charts.blade.php` は 2 回 include される → style は `index.blade.php` に 1 つだけ（T20 が出現回数 1 を検査）。
- **silent failure**: view と JS の分割コミット禁止（§6）。
- **データが全部古い場合**: 直近 N 年がすべて 0 件のグラフになりうる（例: 2017 年以前のデータのみ）。これは E3 の仕様どおり（「直近2年は0件でした」は正しい情報）。空表示への切替はしない。
- **Chart.js の実描画**: `data` 差し替え＋`update()` は標準 API だが**描画は自動テストで検証不可** → §7-5 の実ブラウザ実測が唯一の証拠。`Chart.getChart()` で raw インスタンスを取る構造は前身どおり維持（reactive proxy 回避）。
- **Bug #19**: 新規 Tailwind クラスを足さない（自前 `<style>` で完結）。任意値クラス（`min-w-[140px]` 等）も使わない。
- **Bug #16**: `<option>` は `@foreach` 静的注入（`<template x-for>` 不使用）。
- **Bug #23/#26**: チャートデータは `Js::from($charts)`。`@json` を `x-data` 属性・多行配列に入れない。
- **後方互換**: `byYear`/`byMonth`/`byMonthByYear`/`chartPayload.year`/`month.all`/`month.years` はすべて不変。年別カードは無変更。
- **`phpunit.xml`**: `APP_URL=http://localhost` 固定行を消さない（本番が `/manage` 配下のため）。
