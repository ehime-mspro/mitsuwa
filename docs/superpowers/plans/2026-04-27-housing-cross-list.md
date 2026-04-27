# 住宅事業ダッシュボード（BACKLOG優先度3）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 建売物件 (HsProperty) と注文住宅 (HsCustomOrder) を `/housing` ルートで横断するダッシュボード（KPI・ステータスマトリクス・月次グラフ・フィルター付き詳細テーブル）を新設する。

**Architecture:** 新規 `HousingDashboardController` 1本 + 部分ビュー6本で構成。両モデルを統合 DTO 配列に正規化して扱い、コレクション操作で集計・フィルター・ページングする。Chart.js v4 (CDN) で月次棒グラフ1本を描画。既存のコントローラ・モデル・ルートには触らない。

**Tech Stack:** Laravel 12.x / PHP 8.5.4 / MySQL 8.0 / Blade + Tailwind CSS v4 (Vite build) + Alpine.js v3 / Chart.js v4 (cdn.jsdelivr.net)

**Reference Spec:** `docs/superpowers/specs/2026-04-27-housing-cross-list-design.md`

---

## 共通ガードレール（全タスク共通）

- **CSS**: 新規 Tailwind クラス追加禁止。`docs/RULES.md` の「Working Tailwind Classes」のみ使用。それ以外はインラインスタイル。
- **Alpine.js**: `x-data` 内で `>` 禁止（矢印関数禁止）。`<script>` 内も `function()` 構文のみ。`style=` と `:style=` 同時使用禁止。
- **Blade**: `@if/@else/@endif` は必ず multi-line。`@json()` 内で関数呼び出し禁止。
- **金額表示**: `number_format($v) . '円'`、`¥` プレフィックス禁止。
- **担当者表示**: 姓のみ（既存規約）。
- **PHP CLI 不可**: `php artisan` 系のコマンドは実行できない。動作確認は **ブラウザで URL を開いて目視** が基本。
- **コミット**: 機能単位で小さく。日本語メッセージ。

---

## ファイル構成（全タスク完了時の最終状態）

### 新規ファイル

| パス | 役割 |
|------|------|
| `app/Http/Controllers/Housing/HousingDashboardController.php` | 本機能コントローラ |
| `resources/views/housing/dashboard.blade.php` | ダッシュボード本体ビュー |
| `resources/views/housing/_dashboard_kpi.blade.php` | KPI カード partial |
| `resources/views/housing/_dashboard_matrix.blade.php` | ステータスマトリクス partial |
| `resources/views/housing/_dashboard_chart.blade.php` | 月次グラフ partial |
| `resources/views/housing/_dashboard_table.blade.php` | フィルター + 詳細テーブル partial |
| `docs/mockups/housing/dashboard.html` | 先行モック（HTML 単体） |

### 修正ファイル

| パス | 修正内容 |
|------|---------|
| `routes/web.php` | `/housing` ルート追加 |
| `resources/views/layouts/partials/sidebar.blade.php` | 住宅事業グループ先頭にダッシュボード項目追加（モバイル / デスクトップ 2 箇所） |

---

## Task 1: モック HTML 骨格 + ヘッダー + KPI カード

**Files:**
- Create: `docs/mockups/housing/dashboard.html`

- [ ] **Step 1: モック HTML 骨格を作成**

`docs/mockups/housing/dashboard.html` を新規作成し、以下を記述:

```html
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>住宅事業ダッシュボード - モック</title>
<style>
  body { font-family: 'Hiragino Sans', sans-serif; margin: 0; padding: 24px; background: #f9fafb; color: #111827; }
  .container { max-width: 1200px; margin: 0 auto; }
  .h1 { font-size: 18px; font-weight: 700; color: #111827; margin: 0; }
  .header-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
  .year-select { height: 36px; padding: 0 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }

  .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
  .kpi-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 18px; }
  .kpi-label { font-size: 12px; color: #6b7280; }
  .kpi-value { font-size: 22px; font-weight: 700; color: #111827; margin-top: 4px; }
  .kpi-sub { font-size: 12px; color: #9ca3af; margin-top: 4px; }
  .kpi-profit { color: #047857; }
  @media (max-width: 768px) { .kpi-grid { grid-template-columns: 1fr 1fr; } }
</style>
</head>
<body>
<div class="container">

  <div class="header-row">
    <h1 class="h1">住宅事業ダッシュボード</h1>
    <select class="year-select">
      <option>2026年度</option>
      <option>2025年度</option>
      <option>2024年度</option>
      <option>全期間</option>
    </select>
  </div>

  <!-- KPI カード -->
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-label">案件件数</div>
      <div class="kpi-value">42件</div>
      <div class="kpi-sub">建売 23 / 注文 19</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">売上見込合計</div>
      <div class="kpi-value">1,250,000,000円</div>
      <div class="kpi-sub">&nbsp;</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">原価合計</div>
      <div class="kpi-value">980,000,000円</div>
      <div class="kpi-sub">&nbsp;</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">粗利合計</div>
      <div class="kpi-value kpi-profit">270,000,000円</div>
      <div class="kpi-sub">粗利率 21.6%</div>
    </div>
  </div>

  <!-- 以降のセクションは Task 2 / 3 で追加 -->

</div>
</body>
</html>
```

- [ ] **Step 2: モックをブラウザで開いて目視確認**

`open docs/mockups/housing/dashboard.html`（または `file://` で開く）。
期待: ヘッダーに「住宅事業ダッシュボード」+ 年度セレクター、KPI カード4枚が横並び表示。

- [ ] **Step 3: コミット**

```bash
git add docs/mockups/housing/dashboard.html
git commit -m "住宅事業ダッシュボード: モック骨格と KPI カードを追加"
```

---

## Task 2: モックにステータスマトリクス + フィルター + テーブルを追加

**Files:**
- Modify: `docs/mockups/housing/dashboard.html`

- [ ] **Step 1: マトリクス・フィルター・テーブル CSS を追加**

`<style>` セクションの末尾、`@media` の上に以下を追加:

```css
  .section { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 20px; }
  .section-title { font-size: 14px; font-weight: 600; color: #374151; margin: 0 0 12px; }

  .matrix { width: 100%; border-collapse: collapse; font-size: 13px; }
  .matrix th, .matrix td { padding: 8px 12px; border-bottom: 1px solid #f3f4f6; text-align: center; }
  .matrix th { background: #f9fafb; font-weight: 600; color: #6b7280; font-size: 12px; }
  .matrix th:first-child, .matrix td:first-child { text-align: left; padding-left: 16px; }
  .matrix .total-row td { font-weight: 700; background: #f9fafb; }
  .matrix .clickable { color: #1d4ed8; cursor: pointer; text-decoration: underline; }
  .matrix .zero { color: #d1d5db; }

  .filter-bar { display: flex; gap: 8px; margin-bottom: 12px; align-items: center; flex-wrap: wrap; }
  .filter-bar select, .filter-bar input { height: 36px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; background: #fff; }
  .filter-bar input[type="text"] { flex: 1; min-width: 160px; }
  .filter-clear { height: 36px; padding: 0 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 12px; color: #9ca3af; background: #fff; cursor: pointer; }

  .table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .table th { padding: 10px; background: #f9fafb; border-bottom: 2px solid #e5e7eb; font-size: 12px; font-weight: 600; color: #6b7280; text-align: center; white-space: nowrap; }
  .table td { padding: 10px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; white-space: nowrap; }
  .table .text-right { text-align: right; }
  .table .text-left { text-align: left; }
  .table .name-col { white-space: normal; }
  .badge { display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; }
  .badge-tateuri { background: #ede9fe; color: #5b21b6; }
  .badge-custom  { background: #dbeafe; color: #1e40af; }
  .profit-pos { color: #047857; font-weight: 700; }
  .profit-neg { color: #dc2626; font-weight: 700; }
  .detail-btn { display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #b45309; border: 1px solid #b45309; border-radius: 5px; background: #fff; text-decoration: none; cursor: pointer; }
```

- [ ] **Step 2: マトリクスとフィルター・テーブル本体を追加**

`<!-- 以降のセクションは Task 2 / 3 で追加 -->` の行を以下で置き換え:

```html
  <!-- ステータスマトリクス -->
  <div class="section">
    <h2 class="section-title">ステータス別件数</h2>
    <table class="matrix">
      <thead>
        <tr><th>区分</th><th>建売</th><th>注文</th><th>計</th></tr>
      </thead>
      <tbody>
        <tr><td>商談・見積</td><td><a class="clickable">2</a></td><td><a class="clickable">5</a></td><td>7</td></tr>
        <tr><td>設計中</td><td><a class="clickable">3</a></td><td><a class="clickable">4</a></td><td>7</td></tr>
        <tr><td>建設中</td><td><a class="clickable">5</a></td><td><a class="clickable">3</a></td><td>8</td></tr>
        <tr><td>完成・販売中</td><td><a class="clickable">8</a></td><td><a class="clickable">3</a></td><td>11</td></tr>
        <tr><td>成約・引渡し</td><td><a class="clickable">5</a></td><td><a class="clickable">4</a></td><td>9</td></tr>
        <tr class="total-row"><td>計</td><td>23</td><td>19</td><td>42</td></tr>
      </tbody>
    </table>
  </div>

  <!-- 詳細テーブル + フィルター -->
  <div class="section" id="detail-table">
    <h2 class="section-title">案件詳細</h2>
    <div class="filter-bar">
      <select><option>種別: 全て</option><option>建売</option><option>注文</option></select>
      <select><option>ステータス: 全て</option><option>商談・見積</option><option>設計中</option><option>建設中</option><option>完成・販売中</option><option>成約・引渡し</option></select>
      <select><option>担当者: 全て</option><option>田中</option><option>佐藤</option></select>
      <input type="text" placeholder="案件番号・案件名・住所">
      <button class="filter-clear">クリア</button>
    </div>
    <div style="overflow-x: auto;">
      <table class="table">
        <thead>
          <tr>
            <th>種別</th><th>番号</th><th class="text-left">案件名</th><th>ステータス</th>
            <th>担当者</th><th>契約日</th><th>売上</th><th>原価</th><th>粗利</th><th>詳細</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><span class="badge badge-tateuri">建売</span></td>
            <td><a href="#">HSP-0123</a></td>
            <td class="text-left name-col"><div style="font-weight:600;">松山市〇〇分譲地 1号棟</div><div style="font-size:11px;color:#9ca3af;">愛媛県松山市〇〇 1-2-3</div></td>
            <td><span class="badge" style="background:#c7d2fe;color:#3730a3;">販売中</span></td>
            <td>田中</td>
            <td>—</td>
            <td class="text-right">35,000,000円</td>
            <td class="text-right">28,000,000円</td>
            <td class="text-right profit-pos">7,000,000円</td>
            <td><a class="detail-btn">詳細</a></td>
          </tr>
          <tr>
            <td><span class="badge badge-custom">注文</span></td>
            <td><a href="#">HCO-0042</a></td>
            <td class="text-left name-col"><div style="font-weight:600;">今治市〇〇邸新築工事</div><div style="font-size:11px;color:#9ca3af;">愛媛県今治市〇〇 4-5</div></td>
            <td><span class="badge" style="background:#fed7aa;color:#9a3412;">着工</span></td>
            <td>佐藤</td>
            <td>2026-04-01</td>
            <td class="text-right">28,000,000円</td>
            <td class="text-right">22,000,000円</td>
            <td class="text-right profit-pos">6,000,000円</td>
            <td><a class="detail-btn">詳細</a></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div style="text-align: right; font-size: 12px; color: #6b7280; margin-top: 8px;">全 42 件</div>
  </div>
```

- [ ] **Step 3: ブラウザでリロード確認**

期待: KPI カード下にステータスマトリクスが表示され、その下にフィルターバー + 2行のサンプルテーブル + 「全 42 件」の文字が出る。

- [ ] **Step 4: コミット**

```bash
git add docs/mockups/housing/dashboard.html
git commit -m "住宅事業ダッシュボード: モックにマトリクス・フィルター・テーブルを追加"
```

---

## Task 3: モックに月次棒グラフを追加

**Files:**
- Modify: `docs/mockups/housing/dashboard.html`

- [ ] **Step 1: Chart.js CDN を `<head>` に追加**

`<style>` の前に以下を追加:

```html
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
```

- [ ] **Step 2: 月次グラフ section を マトリクス と テーブル の間に挿入**

ステータスマトリクスの `</div>` 直後に以下を追加:

```html
  <!-- 月次棒グラフ -->
  <div class="section">
    <h2 class="section-title">月次成約・引渡し件数（年度内）</h2>
    <div style="height: 240px;">
      <canvas id="monthlyChart"></canvas>
    </div>
  </div>
```

- [ ] **Step 3: グラフ描画スクリプトを `</body>` 直前に追加**

```html
<script>
(function() {
  var ctx = document.getElementById('monthlyChart');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: ['5月','6月','7月','8月','9月','10月','11月','12月','1月','2月','3月','4月'],
      datasets: [
        { label: '建売 成約', data: [3,5,2,4,3,2,1,2,3,4,2,3], backgroundColor: '#7c3aed' },
        { label: '注文 引渡し', data: [1,2,4,1,3,2,2,3,1,2,3,1], backgroundColor: '#2563eb' }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } } },
      plugins: { legend: { position: 'top' } }
    }
  });
})();
</script>
```

- [ ] **Step 4: ブラウザで再読み込み確認**

期待: マトリクスと詳細テーブルの間に高さ 240px のスタック棒グラフ（紫=建売成約 / 青=注文引渡し）。横軸 5月〜4月。

- [ ] **Step 5: コミット**

```bash
git add docs/mockups/housing/dashboard.html
git commit -m "住宅事業ダッシュボード: モックに月次棒グラフ (Chart.js) を追加"
```

- [ ] **Step 6: ユーザーレビューを依頼（HOLD）**

このタスクの完了後、ユーザーにモックを開いて承認をもらうまで Task 4 以降に進まない。レビュー依頼文:

> モック `docs/mockups/housing/dashboard.html` をブラウザで開いてご確認ください。承認いただけたら Laravel 実装に進みます。

---

## Task 4: ルートとサイドバーを追加（Laravel 実装の入口）

**Files:**
- Modify: `routes/web.php`（housing prefix グループの先頭）
- Modify: `resources/views/layouts/partials/sidebar.blade.php`（98行目周辺・326行目周辺の2箇所）

- [ ] **Step 1: HousingDashboardController の use 文と route を `routes/web.php` に追加**

`routes/web.php` の `Route::prefix('housing')->group(function () {` ブロック（900行目付近）の最初の Route 定義のすぐ上に以下を追加:

```php
        // 住宅事業ダッシュボード（建売 + 注文住宅 横断）
        Route::get('/', [\App\Http\Controllers\Housing\HousingDashboardController::class, 'index'])
            ->name('housing.dashboard');
```

- [ ] **Step 2: サイドバー（デスクトップ）にダッシュボード項目を追加**

`resources/views/layouts/partials/sidebar.blade.php` の 100-104 行目周辺、`<x-sidebar-group label="住宅事業">` の中の最初の `<x-sidebar-item>` の **直前** に以下を追加:

```blade
            <x-sidebar-item :href="url('/housing')" label="ダッシュボード" :active="request()->is('housing') || request()->is('housing/')" />
```

- [ ] **Step 3: サイドバー（モバイル）も同様に追加**

326-330 行目周辺の `<x-sidebar-group label="住宅事業">` の最初の `<x-sidebar-item>` 直前にも同じ行を追加。

- [ ] **Step 4: 仮の Controller を作成（次タスクで本実装）**

`app/Http/Controllers/Housing/HousingDashboardController.php` を新規作成:

```php
<?php

namespace App\Http\Controllers\Housing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * 住宅事業ダッシュボード（建売 + 注文住宅 横断）
 * BACKLOG 優先度3: spec docs/superpowers/specs/2026-04-27-housing-cross-list-design.md
 */
class HousingDashboardController extends Controller
{
    /**
     * GET /housing
     * 住宅事業ダッシュボードを表示する
     */
    public function index(Request $request)
    {
        // Phase 2 仮実装: 空データを渡してビューを返す
        return view('housing.dashboard', [
            'fiscalYear' => (string) (now()->month >= 5 ? now()->year : now()->year - 1),
            'kpi' => null,
            'matrix' => null,
            'monthly' => null,
            'paginated' => null,
            'filterOptions' => ['staffUsers' => collect()],
            'request' => $request,
        ]);
    }
}
```

- [ ] **Step 5: dashboard.blade.php の最小 skeleton を作成**

`resources/views/housing/dashboard.blade.php` を新規作成:

```blade
@extends('layouts.app')

@section('title', '住宅事業ダッシュボード')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">ダッシュボード</span>
@endsection

@section('content')

    <div class="flex items-center justify-between mb-5">
        <h1 class="text-lg font-bold text-gray-900">住宅事業ダッシュボード</h1>
        {{-- 年度セレクターは後続タスクで実装 --}}
    </div>

    <p class="text-sm text-gray-500">Phase 2 実装中です（KPI / マトリクス / グラフ / テーブル は後続タスクで実装）。</p>

@endsection
```

- [ ] **Step 6: ブラウザで動作確認**

URL: `https://domain/manage/public/housing` を開く。
期待: パンくず「住宅事業 › ダッシュボード」、見出し「住宅事業ダッシュボード」、サイドバーで「ダッシュボード」がアクティブ表示。

- [ ] **Step 7: コミット**

```bash
git add routes/web.php resources/views/layouts/partials/sidebar.blade.php app/Http/Controllers/Housing/HousingDashboardController.php resources/views/housing/dashboard.blade.php
git commit -m "住宅事業ダッシュボード: ルート・サイドバー・Controller 骨格を追加"
```

---

## Task 5: DTO 正規化ロジックを実装（collectItems / mapPropertyToDto / mapOrderToDto / classifyStatusGroup）

**Files:**
- Modify: `app/Http/Controllers/Housing/HousingDashboardController.php`

- [ ] **Step 1: use 文を追加**

ファイル冒頭の `use Illuminate\Http\Request;` の **前後** に以下を追加:

```php
use App\Enums\CustomOrderStatus;
use App\Enums\HousingPropertyStatus;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
```

- [ ] **Step 2: index() メソッドを更新して collectItems を呼ぶ形に変える**

既存の `index` を以下で置き換え:

```php
    public function index(Request $request)
    {
        $fiscalYear = $request->input('fiscal_year', (string) $this->getCurrentFiscalYear());

        $items = $this->collectItems($fiscalYear);

        return view('housing.dashboard', [
            'fiscalYear' => $fiscalYear,
            'items' => $items,                          // 一時的: 次タスク以降で kpi / matrix / paginated に置き換える
            'kpi' => null,
            'matrix' => null,
            'monthly' => null,
            'paginated' => null,
            'filterOptions' => ['staffUsers' => collect()],
            'request' => $request,
        ]);
    }
```

- [ ] **Step 3: 4 つの protected メソッドをクラス内に追加**

`index()` の閉じ括弧の直後に以下を追加:

```php
    /**
     * 年度フィルター込みで両モデルから DTO コレクションを生成する
     */
    protected function collectItems(string $fiscalYear): Collection
    {
        // 建売: getSellingPriceTotal() が contract / projectLot / procurement を辿るため eager load
        $properties = HsProperty::with(['createdBy', 'contract', 'projectLot', 'procurement'])->get();

        // 注文: createdBy のみ eager load（cost / selling は直接カラム）
        $orders = HsCustomOrder::with(['createdBy'])->get();

        // 年度フィルター（"all" 以外）
        if ($fiscalYear !== 'all' && $fiscalYear !== '') {
            $fy = (int) $fiscalYear;
            $start = Carbon::create($fy, 5, 1)->startOfDay();
            $end = Carbon::create($fy + 1, 4, 30)->endOfDay();

            $properties = $properties->filter(function ($p) use ($start, $end) {
                $date = $this->propertyKeyDate($p);
                return $date && $date->between($start, $end);
            });
            $orders = $orders->filter(function ($o) use ($start, $end) {
                $date = $this->orderKeyDate($o);
                return $date && $date->between($start, $end);
            });
        }

        $items = collect();
        foreach ($properties as $p) {
            $items->push($this->mapPropertyToDto($p));
        }
        foreach ($orders as $o) {
            $items->push($this->mapOrderToDto($o));
        }

        // key_date 降順
        return $items->sortByDesc(function ($it) {
            return $it['key_date'] ? $it['key_date']->timestamp : 0;
        })->values();
    }

    /**
     * HsProperty を統合 DTO に変換する
     */
    protected function mapPropertyToDto(HsProperty $p): array
    {
        $isSold = $p->isSold();
        $sellingTotal = $p->getSellingPriceTotal();
        $totalCost = $p->getTotalCost();
        $grossProfit = $p->getGrossProfit();
        $grossProfitRate = $p->getGrossProfitRate();
        $statusValue = $p->status?->value ?? '';
        $statusLabel = $isSold ? '成約' : ($p->status?->label() ?? '');
        $statusStyle = $isSold
            ? 'background: #d1fae5; color: #065f46;'
            : ($p->status?->badgeStyle() ?? '');

        return [
            'type'              => 'building',
            'id'                => $p->id,
            'code'              => $p->property_code,
            'name'              => $p->property_name,
            'address'           => $p->address,
            'status_value'      => $statusValue,
            'status_label'      => $statusLabel,
            'status_group'      => $this->classifyStatusGroup('building', $statusValue, $isSold),
            'status_style'      => $statusStyle,
            'staff_name'        => $this->lastNameOnly($p->createdBy?->name),
            'staff_id'          => $p->created_by,
            'key_date'          => $this->propertyKeyDate($p),
            'selling_price'     => $sellingTotal,
            'total_cost'        => $totalCost,
            'gross_profit'      => $grossProfit,
            'gross_profit_rate' => $grossProfitRate,
            'detail_url'        => route('housing.properties.show', $p),
        ];
    }

    /**
     * HsCustomOrder を統合 DTO に変換する
     * 金額計算は Model 側のヘルパー（getTotalSellingPrice/getTotalCost/getTotalProfit/getTotalProfitRate）を流用する
     */
    protected function mapOrderToDto(HsCustomOrder $o): array
    {
        $statusValue = $o->status?->value ?? '';
        $statusLabel = $o->status?->label() ?? '';
        $statusStyle = $o->status?->badgeStyle() ?? '';

        return [
            'type'              => 'custom-order',
            'id'                => $o->id,
            'code'              => $o->order_code,
            'name'              => $o->order_name,
            'address'           => $o->address,
            'status_value'      => $statusValue,
            'status_label'      => $statusLabel,
            'status_group'      => $this->classifyStatusGroup('custom-order', $statusValue, false),
            'status_style'      => $statusStyle,
            'staff_name'        => $this->lastNameOnly($o->createdBy?->name),
            'staff_id'          => $o->created_by,
            'key_date'          => $this->orderKeyDate($o),
            'selling_price'     => $o->getTotalSellingPrice(),
            'total_cost'        => $o->getTotalCost(),
            'gross_profit'      => $o->getTotalProfit(),
            'gross_profit_rate' => $o->getTotalProfitRate(),
            'detail_url'        => route('housing.custom-orders.show', $o),
        ];
    }

    /**
     * Enum 値からステータス大分類グループを判定する
     */
    protected function classifyStatusGroup(string $type, string $statusValue, bool $isSold): string
    {
        if ($type === 'building') {
            if ($statusValue === HousingPropertyStatus::OnSale->value) {
                return $isSold ? 'sold' : 'completed';
            }
            return match ($statusValue) {
                HousingPropertyStatus::Estimation->value   => 'consult',
                HousingPropertyStatus::Design->value       => 'design',
                HousingPropertyStatus::Construction->value => 'construction',
                HousingPropertyStatus::Completed->value    => 'completed',
                default => 'consult',
            };
        }
        // custom-order
        return match ($statusValue) {
            CustomOrderStatus::Consultation->value => 'consult',
            CustomOrderStatus::Estimation->value   => 'consult',
            CustomOrderStatus::Design->value       => 'design',
            CustomOrderStatus::Construction->value => 'construction',
            CustomOrderStatus::Contracted->value   => 'completed',
            CustomOrderStatus::Completed->value    => 'completed',
            CustomOrderStatus::Delivered->value    => 'sold',
            default => 'consult',
        };
    }

    /**
     * 建売の並び替え基準日（成約日 → 登録日）
     */
    protected function propertyKeyDate(HsProperty $p): ?Carbon
    {
        // contract() は HasOne。成約済みなら HsContract.contract_date を使う
        if ($p->contract && $p->contract->contract_date) {
            return Carbon::parse($p->contract->contract_date);
        }
        return $p->created_at ? Carbon::parse($p->created_at) : null;
    }

    /**
     * 注文住宅の並び替え基準日（契約日 → 登録日）
     */
    protected function orderKeyDate(HsCustomOrder $o): ?Carbon
    {
        if ($o->contract_date) {
            return Carbon::parse($o->contract_date);
        }
        return $o->created_at ? Carbon::parse($o->created_at) : null;
    }

    /**
     * フルネームから姓のみ抽出（既存規約: 姓のみ表示）
     */
    protected function lastNameOnly(?string $fullName): ?string
    {
        if ($fullName === null) return null;
        $parts = preg_split('/\s+/u', trim($fullName));
        return $parts[0] ?? $fullName;
    }

    /**
     * 現在の年度（5月始まり）を返す
     */
    protected function getCurrentFiscalYear(): int
    {
        $now = now();
        return $now->month >= 5 ? $now->year : $now->year - 1;
    }
```

- [ ] **Step 4: dashboard.blade.php に件数だけ仮表示してコレクション動作確認**

`<p class="text-sm text-gray-500">Phase 2 実装中です...</p>` の行を以下で置き換え:

```blade
    <div class="bg-white border border-gray-200 rounded-lg p-4">
        <p class="text-sm text-gray-700">DTO 件数: <span class="font-bold">{{ count($items ?? []) }}</span> 件</p>
        @if(count($items ?? []) > 0)
            <p class="text-xs text-gray-500 mt-2">先頭1件: {{ $items[0]['code'] ?? '' }} / {{ $items[0]['name'] ?? '' }} / グループ: {{ $items[0]['status_group'] ?? '' }}</p>
        @endif
    </div>
```

- [ ] **Step 5: ブラウザで `/housing` を再読み込み**

期待: 「DTO 件数: NN 件」が表示され、先頭1件のコード・案件名・ステータスグループが見える。エラーなし。

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/Housing/HousingDashboardController.php resources/views/housing/dashboard.blade.php
git commit -m "住宅事業ダッシュボード: DTO 正規化ロジック (collectItems / map*) を実装"
```

---

## Task 6: KPI 集計と KPI カード partial を実装

**Files:**
- Modify: `app/Http/Controllers/Housing/HousingDashboardController.php`
- Create: `resources/views/housing/_dashboard_kpi.blade.php`
- Modify: `resources/views/housing/dashboard.blade.php`

- [ ] **Step 1: buildKpi メソッドを Controller に追加**

`getCurrentFiscalYear()` の **直前** に以下を追加:

```php
    /**
     * KPI 集計（件数・売上見込・原価・粗利・粗利率・種別内訳）
     */
    protected function buildKpi(Collection $items): array
    {
        $sellingTotal = (int) $items->whereNotNull('selling_price')->sum('selling_price');
        $costTotal = (int) $items->whereNotNull('total_cost')->sum('total_cost');
        $profitTotal = (int) $items->whereNotNull('gross_profit')->sum('gross_profit');
        $profitRate = $sellingTotal > 0
            ? round(($profitTotal / $sellingTotal) * 100, 1)
            : null;

        return [
            'count_total'    => $items->count(),
            'count_building' => $items->where('type', 'building')->count(),
            'count_custom'   => $items->where('type', 'custom-order')->count(),
            'selling_total'  => $sellingTotal,
            'cost_total'     => $costTotal,
            'profit_total'   => $profitTotal,
            'profit_rate'    => $profitRate,
        ];
    }
```

- [ ] **Step 2: index() を更新して buildKpi を呼ぶ**

`$items = $this->collectItems($fiscalYear);` の直後に追加:

```php
        $kpi = $this->buildKpi($items);
```

そして view に渡す配列の `'kpi' => null,` を `'kpi' => $kpi,` に変更。

- [ ] **Step 3: KPI partial を作成**

`resources/views/housing/_dashboard_kpi.blade.php`:

```blade
{{-- KPI カード（4枚） --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    {{-- 案件件数 --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-3">
        <div class="text-xs text-gray-500">案件件数</div>
        <div class="text-lg font-bold text-gray-900" style="font-size: 22px;">{{ number_format($kpi['count_total']) }}件</div>
        <div class="text-xs text-gray-400">建売 {{ $kpi['count_building'] }} / 注文 {{ $kpi['count_custom'] }}</div>
    </div>

    {{-- 売上見込合計 --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-3">
        <div class="text-xs text-gray-500">売上見込合計</div>
        <div class="text-lg font-bold text-gray-900" style="font-size: 22px;">{{ number_format($kpi['selling_total']) }}円</div>
        <div class="text-xs text-gray-400">&nbsp;</div>
    </div>

    {{-- 原価合計 --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-3">
        <div class="text-xs text-gray-500">原価合計</div>
        <div class="text-lg font-bold text-gray-900" style="font-size: 22px;">{{ number_format($kpi['cost_total']) }}円</div>
        <div class="text-xs text-gray-400">&nbsp;</div>
    </div>

    {{-- 粗利合計 --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-3">
        <div class="text-xs text-gray-500">粗利合計</div>
        <div class="font-bold" style="font-size: 22px; {{ $kpi['profit_total'] >= 0 ? 'color: #047857;' : 'color: #dc2626;' }}">{{ number_format($kpi['profit_total']) }}円</div>
        <div class="text-xs text-gray-400">
            @if($kpi['profit_rate'] !== null)
                粗利率 {{ $kpi['profit_rate'] }}%
            @else
                粗利率 —
            @endif
        </div>
    </div>
</div>
```

- [ ] **Step 4: dashboard.blade.php で KPI partial を include**

Step 4-5(Task 5) で書いた `<div class="bg-white...">DTO 件数...</div>` を以下で置き換え:

```blade
    @include('housing._dashboard_kpi', ['kpi' => $kpi])
```

- [ ] **Step 5: ブラウザで `/housing` を再読み込み**

期待: KPI カード4枚が横並び表示され、件数・金額・粗利率が表示される。

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/Housing/HousingDashboardController.php resources/views/housing/_dashboard_kpi.blade.php resources/views/housing/dashboard.blade.php
git commit -m "住宅事業ダッシュボード: KPI 集計と KPI カード partial を実装"
```

---

## Task 7: ステータスマトリクスを実装

**Files:**
- Modify: `app/Http/Controllers/Housing/HousingDashboardController.php`
- Create: `resources/views/housing/_dashboard_matrix.blade.php`
- Modify: `resources/views/housing/dashboard.blade.php`

- [ ] **Step 1: buildMatrix メソッドを Controller に追加**

`buildKpi()` の直後に追加:

```php
    /**
     * ステータスマトリクス（5グループ × 種別）を構築
     */
    protected function buildMatrix(Collection $items): array
    {
        $groups = [
            'consult'      => '商談・見積',
            'design'       => '設計中',
            'construction' => '建設中',
            'completed'    => '完成・販売中',
            'sold'         => '成約・引渡し',
        ];

        $rows = [];
        foreach ($groups as $key => $label) {
            $b = $items->where('type', 'building')->where('status_group', $key)->count();
            $c = $items->where('type', 'custom-order')->where('status_group', $key)->count();
            $rows[] = [
                'key'      => $key,
                'label'    => $label,
                'building' => $b,
                'custom'   => $c,
                'total'    => $b + $c,
            ];
        }

        return [
            'rows'           => $rows,
            'total_building' => $items->where('type', 'building')->count(),
            'total_custom'   => $items->where('type', 'custom-order')->count(),
            'total_all'      => $items->count(),
        ];
    }
```

- [ ] **Step 2: index() で buildMatrix を呼ぶ**

`$kpi = $this->buildKpi($items);` の直後に:

```php
        $matrix = $this->buildMatrix($items);
```

view 配列の `'matrix' => null,` を `'matrix' => $matrix,` に変更。

- [ ] **Step 3: matrix partial を作成**

`resources/views/housing/_dashboard_matrix.blade.php`:

```blade
{{-- ステータスマトリクス --}}
<div class="bg-white border border-gray-200 rounded-lg px-4 py-3 mb-5">
    <div class="text-sm font-semibold text-gray-700 mb-3">ステータス別件数</div>
    <div style="overflow-x: auto;">
        <table class="w-full" style="border-collapse: collapse; font-size: 13px;">
            <thead>
                <tr>
                    <th style="padding: 8px 12px; background: #f9fafb; border-bottom: 1px solid #f3f4f6; text-align: left; font-weight: 600; color: #6b7280; font-size: 12px;">区分</th>
                    <th style="padding: 8px 12px; background: #f9fafb; border-bottom: 1px solid #f3f4f6; text-align: center; font-weight: 600; color: #6b7280; font-size: 12px;">建売</th>
                    <th style="padding: 8px 12px; background: #f9fafb; border-bottom: 1px solid #f3f4f6; text-align: center; font-weight: 600; color: #6b7280; font-size: 12px;">注文</th>
                    <th style="padding: 8px 12px; background: #f9fafb; border-bottom: 1px solid #f3f4f6; text-align: center; font-weight: 600; color: #6b7280; font-size: 12px;">計</th>
                </tr>
            </thead>
            <tbody>
                @foreach($matrix['rows'] as $row)
                    <tr>
                        <td style="padding: 8px 12px; border-bottom: 1px solid #f3f4f6; text-align: left;">{{ $row['label'] }}</td>
                        <td style="padding: 8px 12px; border-bottom: 1px solid #f3f4f6; text-align: center;">
                            @if($row['building'] > 0)
                                <a href="{{ route('housing.dashboard', ['fiscal_year' => $fiscalYear, 'type' => 'building', 'status_group' => $row['key']]) }}#detail-table"
                                   style="color: #1d4ed8; text-decoration: underline;">{{ $row['building'] }}</a>
                            @else
                                <span style="color: #d1d5db;">0</span>
                            @endif
                        </td>
                        <td style="padding: 8px 12px; border-bottom: 1px solid #f3f4f6; text-align: center;">
                            @if($row['custom'] > 0)
                                <a href="{{ route('housing.dashboard', ['fiscal_year' => $fiscalYear, 'type' => 'custom-order', 'status_group' => $row['key']]) }}#detail-table"
                                   style="color: #1d4ed8; text-decoration: underline;">{{ $row['custom'] }}</a>
                            @else
                                <span style="color: #d1d5db;">0</span>
                            @endif
                        </td>
                        <td style="padding: 8px 12px; border-bottom: 1px solid #f3f4f6; text-align: center; font-weight: 600;">{{ $row['total'] }}</td>
                    </tr>
                @endforeach
                <tr style="background: #f9fafb; font-weight: 700;">
                    <td style="padding: 8px 12px; text-align: left;">計</td>
                    <td style="padding: 8px 12px; text-align: center;">{{ $matrix['total_building'] }}</td>
                    <td style="padding: 8px 12px; text-align: center;">{{ $matrix['total_custom'] }}</td>
                    <td style="padding: 8px 12px; text-align: center;">{{ $matrix['total_all'] }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
```

- [ ] **Step 4: dashboard.blade.php で matrix partial を include**

`@include('housing._dashboard_kpi', ...)` の直後に追加:

```blade
    @include('housing._dashboard_matrix', ['matrix' => $matrix, 'fiscalYear' => $fiscalYear])
```

- [ ] **Step 5: ブラウザで `/housing` を再読み込み**

期待: KPI 下にマトリクス表（5行 × 3種別 + 合計行）が表示され、件数 0 はグレー、それ以外は青リンク。

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/Housing/HousingDashboardController.php resources/views/housing/_dashboard_matrix.blade.php resources/views/housing/dashboard.blade.php
git commit -m "住宅事業ダッシュボード: ステータスマトリクスを実装"
```

---

## Task 8: フィルター + 詳細テーブル + ページングを実装

**Files:**
- Modify: `app/Http/Controllers/Housing/HousingDashboardController.php`
- Create: `resources/views/housing/_dashboard_table.blade.php`
- Modify: `resources/views/housing/dashboard.blade.php`

- [ ] **Step 1: applyTableFilters / paginate / buildFilterOptions を Controller に追加**

`buildMatrix()` の直後に追加:

```php
    /**
     * 詳細テーブル用のフィルター適用（種別 / ステータスグループ / 担当者 / キーワード）
     */
    protected function applyTableFilters(Collection $items, Request $request): Collection
    {
        $type = $request->input('type', '');
        if ($type === 'building' || $type === 'custom-order') {
            $items = $items->where('type', $type);
        }

        $group = $request->input('status_group', '');
        if ($group !== '') {
            $items = $items->where('status_group', $group);
        }

        $staffId = $request->input('staff_id', '');
        if ($staffId !== '') {
            $items = $items->where('staff_id', (int) $staffId);
        }

        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $items = $items->filter(function ($it) use ($keyword) {
                $haystack = ($it['code'] ?? '') . ' ' . ($it['name'] ?? '') . ' ' . ($it['address'] ?? '');
                return mb_stripos($haystack, $keyword) !== false;
            });
        }

        return $items->values();
    }

    /**
     * Collection を LengthAwarePaginator に変換する
     */
    protected function paginate(Collection $items, int $perPage, Request $request): LengthAwarePaginator
    {
        $page = max(1, (int) $request->input('page', 1));
        $offset = ($page - 1) * $perPage;
        $sliced = $items->slice($offset, $perPage)->values();

        return new LengthAwarePaginator(
            $sliced,
            $items->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }

    /**
     * フィルター用のオプションリスト（担当者一覧）
     */
    protected function buildFilterOptions(): array
    {
        return [
            'staffUsers' => User::orderBy('name')->get(['id', 'name']),
        ];
    }
```

- [ ] **Step 2: index() を完全実装に更新**

`index()` 全体を以下で置き換え:

```php
    public function index(Request $request)
    {
        $fiscalYear = $request->input('fiscal_year', (string) $this->getCurrentFiscalYear());

        $items = $this->collectItems($fiscalYear);
        $kpi = $this->buildKpi($items);
        $matrix = $this->buildMatrix($items);
        $tableItems = $this->applyTableFilters($items, $request);
        $paginated = $this->paginate($tableItems, 20, $request);
        $filterOptions = $this->buildFilterOptions();

        // 月次データは Task 9 で実装予定
        $monthly = null;

        // 利用可能年度リスト（過去2年〜来年度）
        $currentFy = $this->getCurrentFiscalYear();
        $fiscalYearOptions = [
            (string) ($currentFy + 1) => ($currentFy + 1) . '年度',
            (string) $currentFy        => $currentFy . '年度',
            (string) ($currentFy - 1) => ($currentFy - 1) . '年度',
            (string) ($currentFy - 2) => ($currentFy - 2) . '年度',
            'all'                      => '全期間',
        ];

        return view('housing.dashboard', compact(
            'fiscalYear', 'fiscalYearOptions', 'kpi', 'matrix', 'monthly', 'paginated', 'filterOptions', 'request'
        ));
    }
```

- [ ] **Step 3: table partial を作成**

`resources/views/housing/_dashboard_table.blade.php`:

```blade
{{-- 詳細テーブル + フィルターバー --}}
<div class="bg-white border border-gray-200 rounded-lg px-4 py-3 mb-5" id="detail-table">
    <div class="text-sm font-semibold text-gray-700 mb-3">案件詳細</div>

    {{-- フィルターバー --}}
    <form id="filter-form" method="GET" action="{{ route('housing.dashboard') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4">
        <input type="hidden" name="fiscal_year" value="{{ $fiscalYear }}">

        <select name="type" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm bg-white w-full sm:w-auto">
            <option value="" {{ request('type', '') === '' ? 'selected' : '' }}>種別: 全て</option>
            <option value="building" {{ request('type') === 'building' ? 'selected' : '' }}>建売</option>
            <option value="custom-order" {{ request('type') === 'custom-order' ? 'selected' : '' }}>注文</option>
        </select>

        <select name="status_group" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm bg-white w-full sm:w-auto">
            <option value="" {{ request('status_group', '') === '' ? 'selected' : '' }}>ステータス: 全て</option>
            <option value="consult"      {{ request('status_group') === 'consult' ? 'selected' : '' }}>商談・見積</option>
            <option value="design"       {{ request('status_group') === 'design' ? 'selected' : '' }}>設計中</option>
            <option value="construction" {{ request('status_group') === 'construction' ? 'selected' : '' }}>建設中</option>
            <option value="completed"    {{ request('status_group') === 'completed' ? 'selected' : '' }}>完成・販売中</option>
            <option value="sold"         {{ request('status_group') === 'sold' ? 'selected' : '' }}>成約・引渡し</option>
        </select>

        <select name="staff_id" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm bg-white w-full sm:w-auto">
            <option value="" {{ request('staff_id', '') === '' ? 'selected' : '' }}>担当者: 全て</option>
            @foreach($filterOptions['staffUsers'] as $u)
                @php
                    $parts = preg_split('/\s+/u', trim($u->name));
                    $lastName = $parts[0] ?? $u->name;
                @endphp
                <option value="{{ $u->id }}" {{ (string) request('staff_id') === (string) $u->id ? 'selected' : '' }}>{{ $lastName }}</option>
            @endforeach
        </select>

        <input type="text" name="keyword" value="{{ request('keyword') }}"
               placeholder="番号・名前・住所"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm flex-1 min-w-[140px] bg-white">

        <a href="{{ route('housing.dashboard') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 inline-flex items-center justify-center whitespace-nowrap">
            クリア
        </a>
    </form>

    {{-- テーブル --}}
    <div style="overflow-x: auto;">
        <table class="w-full" style="border-collapse: collapse;">
            <thead>
                <tr>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; white-space: nowrap;">種別</th>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; white-space: nowrap;">番号</th>
                    <th class="py-2.5 text-left text-xs font-semibold text-gray-600 bg-gray-50" style="padding-left: 16px; border-bottom: 2px solid #e5e7eb; white-space: nowrap;">案件名</th>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; white-space: nowrap;">ステータス</th>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; white-space: nowrap;">担当者</th>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; white-space: nowrap;">基準日</th>
                    <th class="px-3 py-2.5 text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; text-align: right; padding-right: 16px; white-space: nowrap;">売上</th>
                    <th class="px-3 py-2.5 text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; text-align: right; padding-right: 16px; white-space: nowrap;">原価</th>
                    <th class="px-3 py-2.5 text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; text-align: right; padding-right: 16px; white-space: nowrap;">粗利</th>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; white-space: nowrap;">詳細</th>
                </tr>
            </thead>
            <tbody>
                @forelse($paginated as $it)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-3 text-center" style="border-bottom: 1px solid #f3f4f6; white-space: nowrap;">
                            @if($it['type'] === 'building')
                                <span style="display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; background: #ede9fe; color: #5b21b6;">建売</span>
                            @else
                                <span style="display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; background: #dbeafe; color: #1e40af;">注文</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-center" style="border-bottom: 1px solid #f3f4f6; white-space: nowrap;">
                            <a href="{{ $it['detail_url'] }}" class="text-sm font-semibold" style="color: #1d4ed8; text-decoration: underline;">{{ $it['code'] }}</a>
                        </td>
                        <td class="py-3" style="padding-left: 16px; border-bottom: 1px solid #f3f4f6;">
                            <div class="text-sm font-semibold text-gray-900">{{ $it['name'] }}</div>
                            @if($it['address'])
                                <div class="text-xs text-gray-500">{{ $it['address'] }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-center" style="border-bottom: 1px solid #f3f4f6; white-space: nowrap;">
                            <span style="display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; {{ $it['status_style'] }}">{{ $it['status_label'] }}</span>
                        </td>
                        <td class="px-3 py-3 text-center text-sm text-gray-800" style="border-bottom: 1px solid #f3f4f6; white-space: nowrap;">{{ $it['staff_name'] ?? '—' }}</td>
                        <td class="px-3 py-3 text-center text-sm text-gray-800" style="border-bottom: 1px solid #f3f4f6; white-space: nowrap;">
                            {{ $it['key_date'] ? $it['key_date']->format('Y-m-d') : '—' }}
                        </td>
                        <td class="px-3 py-3 text-sm" style="border-bottom: 1px solid #f3f4f6; text-align: right; padding-right: 16px; white-space: nowrap;">
                            @if($it['selling_price'] !== null)
                                {{ number_format($it['selling_price']) }}円
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-3 py-3 text-sm" style="border-bottom: 1px solid #f3f4f6; text-align: right; padding-right: 16px; white-space: nowrap;">
                            @if($it['total_cost'] !== null)
                                {{ number_format($it['total_cost']) }}円
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-3 py-3 text-sm" style="border-bottom: 1px solid #f3f4f6; text-align: right; padding-right: 16px; white-space: nowrap; {{ $it['gross_profit'] !== null && $it['gross_profit'] >= 0 ? 'color: #047857; font-weight: 700;' : ($it['gross_profit'] !== null ? 'color: #dc2626; font-weight: 700;' : '') }}">
                            @if($it['gross_profit'] !== null)
                                {{ number_format($it['gross_profit']) }}円
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-3 py-3 text-center" style="border-bottom: 1px solid #f3f4f6; white-space: nowrap;">
                            <a href="{{ $it['detail_url'] }}"
                               style="display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #b45309; border: 1px solid #b45309; border-radius: 5px; background: #fff; text-decoration: none;">詳細</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-3 py-8 text-center text-sm text-gray-500" style="border-bottom: 1px solid #f3f4f6;">該当する案件がありません</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($paginated->hasPages())
        <div class="mt-4">{{ $paginated->withQueryString()->links() }}</div>
    @endif

    <div class="text-sm text-gray-500 text-right mt-2">全 {{ $paginated->total() }} 件</div>
</div>
```

- [ ] **Step 4: dashboard.blade.php に table partial を include + 年度セレクター追加**

ヘッダー部の `{{-- 年度セレクターは後続タスクで実装 --}}` を以下で置き換え:

```blade
        <form method="GET" action="{{ route('housing.dashboard') }}">
            <select name="fiscal_year" onchange="this.form.submit()"
                    class="h-9 px-3 border border-gray-300 rounded-md text-sm bg-white">
                @foreach($fiscalYearOptions as $value => $label)
                    <option value="{{ $value }}" {{ $fiscalYear === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </form>
```

そしてマトリクス include の直後（次タスク Chart.js partial 実装後はその直前）に:

```blade
    @include('housing._dashboard_table', ['paginated' => $paginated, 'filterOptions' => $filterOptions, 'fiscalYear' => $fiscalYear])
```

- [ ] **Step 5: ブラウザで `/housing` を再読み込み**

期待:
- 年度セレクターが動作（年度切替で URL に `?fiscal_year=2026` が付く）
- 詳細テーブルが10列で表示される
- フィルター（種別/ステータス/担当者/キーワード）が動作
- ページング動作（21件以上ある場合）
- マトリクスのリンククリックで該当行に絞り込みされ `#detail-table` にスクロール

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/Housing/HousingDashboardController.php resources/views/housing/_dashboard_table.blade.php resources/views/housing/dashboard.blade.php
git commit -m "住宅事業ダッシュボード: フィルター・詳細テーブル・ページング・年度切替を実装"
```

---

## Task 9: 月次棒グラフ (Chart.js) を実装

**Files:**
- Modify: `app/Http/Controllers/Housing/HousingDashboardController.php`
- Create: `resources/views/housing/_dashboard_chart.blade.php`
- Modify: `resources/views/housing/dashboard.blade.php`

- [ ] **Step 1: buildMonthly メソッドを Controller に追加**

`buildFilterOptions()` の直後に追加:

```php
    /**
     * 月次集計（建売成約件数 + 注文引渡し件数）を構築
     * - 建売: HsContract.contract_date を集計
     * - 注文: HsCustomOrder.delivery_date を集計
     */
    protected function buildMonthly(Collection $items, int $fy): array
    {
        // 月ラベル: 5月〜翌4月
        $labels = ['5月','6月','7月','8月','9月','10月','11月','12月','1月','2月','3月','4月'];
        $building = array_fill(0, 12, 0);
        $custom = array_fill(0, 12, 0);

        // 建売: status_group=sold のみ抽出 + key_date が年度内
        $buildingSold = $items->where('type', 'building')->where('status_group', 'sold');
        foreach ($buildingSold as $it) {
            $idx = $this->monthIndexInFiscalYear($it['key_date'], $fy);
            if ($idx !== null) {
                $building[$idx]++;
            }
        }

        // 注文: delivery_date を別途取得（DTO に含めていないので Model から再取得）
        $deliveredOrders = HsCustomOrder::whereNotNull('delivery_date')
            ->whereBetween('delivery_date', [
                Carbon::create($fy, 5, 1)->startOfDay(),
                Carbon::create($fy + 1, 4, 30)->endOfDay(),
            ])
            ->get(['id', 'delivery_date']);

        foreach ($deliveredOrders as $o) {
            $idx = $this->monthIndexInFiscalYear(Carbon::parse($o->delivery_date), $fy);
            if ($idx !== null) {
                $custom[$idx]++;
            }
        }

        return [
            'labels'   => $labels,
            'building' => $building,
            'custom'   => $custom,
        ];
    }

    /**
     * Carbon 日付が年度内なら 0..11 のインデックスを返す（5月=0、4月=11）
     */
    protected function monthIndexInFiscalYear(?Carbon $date, int $fy): ?int
    {
        if (!$date) return null;
        $start = Carbon::create($fy, 5, 1)->startOfDay();
        $end = Carbon::create($fy + 1, 4, 30)->endOfDay();
        if (!$date->between($start, $end)) return null;

        $month = $date->month;
        // 5月=0, 6月=1, ..., 12月=7, 1月=8, ..., 4月=11
        return $month >= 5 ? $month - 5 : $month + 7;
    }
```

- [ ] **Step 2: index() で buildMonthly を呼ぶ**

`$monthly = null;` の行を以下で置き換え:

```php
        $monthly = $fiscalYear === 'all' ? null : $this->buildMonthly($items, (int) $fiscalYear);
```

- [ ] **Step 3: chart partial を作成**

`resources/views/housing/_dashboard_chart.blade.php`:

```blade
{{-- 月次棒グラフ（Chart.js） --}}
@if($monthly !== null)
<div class="bg-white border border-gray-200 rounded-lg px-4 py-3 mb-5">
    <div class="text-sm font-semibold text-gray-700 mb-3">月次成約・引渡し件数（{{ $fiscalYear }}年度）</div>
    <div style="height: 240px;">
        <canvas id="housingMonthlyChart"></canvas>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function() {
    var data = @json($monthly);
    var canvas = document.getElementById('housingMonthlyChart');
    if (!canvas || typeof Chart === 'undefined') return;

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [
                { label: '建売 成約', data: data.building, backgroundColor: '#7c3aed' },
                { label: '注文 引渡し', data: data.custom,   backgroundColor: '#2563eb' }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { stacked: true },
                y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } }
            },
            plugins: { legend: { position: 'top' } }
        }
    });
})();
</script>
@endpush
@endif
```

- [ ] **Step 4: dashboard.blade.php で chart partial を include**

マトリクス include の直後、テーブル include の **直前** に追加:

```blade
    @include('housing._dashboard_chart', ['monthly' => $monthly, 'fiscalYear' => $fiscalYear])
```

- [ ] **Step 5: ブラウザで `/housing?fiscal_year=2026` を再読み込み**

期待:
- マトリクスとテーブルの間にスタック棒グラフが表示
- 横軸 5月〜4月、縦軸件数
- 建売(紫) + 注文(青) の積み上げ

`?fiscal_year=all` の場合グラフ非表示を確認。

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/Housing/HousingDashboardController.php resources/views/housing/_dashboard_chart.blade.php resources/views/housing/dashboard.blade.php
git commit -m "住宅事業ダッシュボード: 月次棒グラフ (Chart.js) を実装"
```

---

## Task 10: 30点品質監査 + 手動動作確認

**Files:**
- Read-only review (修正があれば該当ファイルを修正)

- [ ] **Step 1: CSS 規約監査**

以下を実行し、Vite ビルド未収録クラスが混入していないか確認:

```bash
grep -nE "(gap-5|md:grid-cols-2|mt-auto|py-0\.5|pb-2\.5|items-end|border-red-600|pl-9|pl-10|border-l-4 border-emerald-500)" resources/views/housing/dashboard.blade.php resources/views/housing/_dashboard_*.blade.php
```

期待: マッチなし。あればインラインスタイルに置き換え。

- [ ] **Step 2: Alpine.js 規約監査**

```bash
grep -nE "(=>|x-data=\".*>.*\")" resources/views/housing/dashboard.blade.php resources/views/housing/_dashboard_*.blade.php
```

期待: 矢印関数 `=>` および x-data 内 `>` のマッチなし（Chart.js は外部 script なので影響なし）。

- [ ] **Step 3: Blade 規約監査**

```bash
grep -nE "@else<|@else[a-zA-Z0-9]" resources/views/housing/dashboard.blade.php resources/views/housing/_dashboard_*.blade.php
grep -nE "@json\([^)]*\(" resources/views/housing/dashboard.blade.php resources/views/housing/_dashboard_*.blade.php
```

期待: いずれもマッチなし。

- [ ] **Step 4: 手動動作確認チェックリスト**

ブラウザで以下を全項目確認:

- [ ] 4-1. URL `/housing` でダッシュボード表示
- [ ] 4-2. サイドバーで「ダッシュボード」がアクティブ
- [ ] 4-3. 年度切り替え → KPI / マトリクス / グラフ / テーブル すべて連動更新
- [ ] 4-4. 「全期間」選択時にグラフ非表示
- [ ] 4-5. マトリクスのセルクリック → `?type=...&status_group=...` 付与 + `#detail-table` スクロール
- [ ] 4-6. 0件セルはグレー表示・クリック不可
- [ ] 4-7. 種別フィルター（建売 / 注文）動作
- [ ] 4-8. ステータスグループフィルター動作
- [ ] 4-9. 担当者フィルター動作
- [ ] 4-10. キーワード検索動作（番号・名前・住所のいずれか部分一致）
- [ ] 4-11. 複数フィルター組み合わせ動作
- [ ] 4-12. クリアボタンで全フィルター解除
- [ ] 4-13. ページング動作（21件以上ある場合）
- [ ] 4-14. テーブル0件時に「該当する案件がありません」表示
- [ ] 4-15. 詳細リンク: 建売→ `/housing/properties/{id}`、注文→ `/housing/custom-orders/{id}`
- [ ] 4-16. 種別バッジ: 建売(紫)、注文(青) で視覚区別される
- [ ] 4-17. 粗利額の色: プラス緑 / マイナス赤
- [ ] 4-18. 担当者は姓のみ表示
- [ ] 4-19. 金額表示は `28,500,000円`（`¥` プレフィックスなし）
- [ ] 4-20. 権限なしユーザーで 403（住宅事業外のロールで確認）

- [ ] **Step 5: パフォーマンス確認**

ブラウザの開発者ツール Network タブで `/housing` 読み込み時間が3秒以下を確認。
DBクエリが大量発火している場合は N+1 問題の可能性 — eager load 設定を見直す。

- [ ] **Step 6: 修正があればコミット**

監査で見つかった問題を修正したらコミット:

```bash
git add <修正したファイル>
git commit -m "住宅事業ダッシュボード: 30点品質監査の指摘を修正"
```

修正なしの場合はこの Step スキップ。

---

## Task 11: 最終ドキュメント更新と PR

**Files:**
- Modify: `docs/BACKLOG.md`（優先度3 のステータス更新）

- [ ] **Step 1: BACKLOG を更新**

`docs/BACKLOG.md` の優先度3セクションに完了マークを追記:

```markdown
## ✅ 優先度3: 住宅事業 横断一覧（実装完了）

詳細仕様: @docs/superpowers/specs/2026-04-27-housing-cross-list-design.md
実装計画: @docs/superpowers/plans/2026-04-27-housing-cross-list.md

### 概要

`/housing` ルートに住宅事業ダッシュボードを新設。建売物件 + 注文住宅を横断する KPI / ステータスマトリクス / 月次グラフ / フィルター付き詳細テーブルで構成。

### 実装内容

- Controller: `Housing/HousingDashboardController` (1本)
- Blade: `housing/dashboard.blade.php` + 4 partial
- ルート: `/housing` (housing.dashboard)
- サイドバー: 住宅事業グループ先頭にダッシュボード項目追加
```

既存の優先度3の見出しを上記の見出しに変更し、本文を上記内容に置き換え。

- [ ] **Step 2: コミット**

```bash
git add docs/BACKLOG.md
git commit -m "BACKLOG: 優先度3 住宅事業ダッシュボード 完了マークを追加"
```

- [ ] **Step 3: 全コミット履歴を確認**

```bash
git log --oneline 13.x..HEAD
```

期待: Task 1〜10 の各コミットがすべて表示される。

- [ ] **Step 4: PR 作成（ユーザー承認後）**

ユーザーに以下の方針を提示:

> ブランチ `claude/amazing-elbakyan-5bd780` を origin に push し、`13.x` 向けの PR を作成してよいか？タイトル案: 「住宅事業ダッシュボード（BACKLOG優先度3）を追加」

ユーザー承認後:

```bash
git push -u origin claude/amazing-elbakyan-5bd780
gh pr create --base 13.x --title "住宅事業ダッシュボード（BACKLOG優先度3）を追加" --body "$(cat <<'EOF'
## Summary
- 建売 + 注文住宅を横断する `/housing` ダッシュボードを新設
- KPI 4枚 / ステータスマトリクス / 月次棒グラフ (Chart.js) / フィルター付き詳細テーブル
- 既存コントローラー・モデル・ルートには影響なし

## Test plan
- [ ] `/housing` でダッシュボード表示
- [ ] 年度切り替えで全セクション連動更新
- [ ] マトリクスのセルクリックで詳細テーブル絞り込み
- [ ] 全フィルター動作（種別 / ステータス / 担当者 / キーワード）
- [ ] 詳細リンク遷移（建売 / 注文）
- [ ] 権限制御（住宅事業外で 403）

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## 参考: 既存ファイル位置情報（探索済み）

| 項目 | パス・行番号 |
|------|------------|
| Housing prefix ルート開始 | `routes/web.php` 900 行目周辺 |
| サイドバー（デスクトップ） | `resources/views/layouts/partials/sidebar.blade.php` 100-104 行目 |
| サイドバー（モバイル） | `resources/views/layouts/partials/sidebar.blade.php` 326-330 行目 |
| HsProperty isSold / cost methods | `app/Models/HsProperty.php` |
| HsCustomOrder delivery_date | `app/Models/HsCustomOrder.php` 42, 64 行目 |
| HousingPropertyStatus Enum | `app/Enums/HousingPropertyStatus.php` |
| CustomOrderStatus Enum | `app/Enums/CustomOrderStatus.php` |
| 既存 properties/index.blade.php | カラム構成・フィルターバー UI 参考 |
| 既存 HsContractListController | DTO マージ・コレクションページング パターン参考 |
