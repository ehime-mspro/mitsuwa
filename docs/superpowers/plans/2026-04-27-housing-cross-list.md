# 住宅事業ダッシュボード（BACKLOG優先度3）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 建売物件 (HsProperty) と注文住宅 (HsCustomOrder) の **成約済み** を `/housing` ルートで横断するダッシュボード（KPI・成約一覧・月次グラフ）を新設する。

**Architecture:** 新規 `HousingDashboardController` 1本 + 部分ビュー4本で構成。両モデルから成約済みのみを統合 DTO 配列に正規化し、コレクション操作で集計・ページングする。Chart.js v4 (CDN) で月次成約件数の単一系列棒グラフを描画。既存のコントローラ・モデル・ルートには触らない。

**Tech Stack:** Laravel 12.x / PHP 8.5.4 / MySQL 8.0 / Blade + Tailwind CSS v4 (Vite build) + Alpine.js v3 / Chart.js v4 (cdn.jsdelivr.net)

**Reference Spec:** `docs/superpowers/specs/2026-04-27-housing-cross-list-design.md`

**Mock (承認済み):** `docs/mockups/housing/dashboard.html` (Task 1〜3 で構築、ユーザーレビュー完了)

---

## 共通ガードレール（全タスク共通）

- **CSS**: 新規 Tailwind クラス追加禁止。`docs/RULES.md` の「Working Tailwind Classes」のみ使用。それ以外はインラインスタイル。
- **Alpine.js**: `x-data` 内で `>` 禁止。`<script>` 内は `function()` のみ。`style=` と `:style=` 同時使用禁止。
- **Blade**: `@if/@else/@endif` は multi-line。`@json()` 内で関数呼び出し禁止。
- **金額表示**: `number_format($v) . '円'`、`¥` プレフィックス禁止。
- **担当者表示**: 姓のみ。
- **PHP CLI 不可**: 動作確認はブラウザで URL を開く。
- **コミット**: 機能単位で小さく。日本語メッセージ。

---

## ファイル構成（最終状態）

### 新規ファイル

| パス | 役割 |
|------|------|
| `app/Http/Controllers/Housing/HousingDashboardController.php` | 本機能コントローラ |
| `resources/views/housing/dashboard.blade.php` | ダッシュボード本体ビュー |
| `resources/views/housing/_dashboard_kpi.blade.php` | KPI カード partial |
| `resources/views/housing/_dashboard_contracted.blade.php` | 成約一覧 partial |
| `resources/views/housing/_dashboard_chart.blade.php` | 月次グラフ partial |
| `docs/mockups/housing/dashboard.html` | 先行モック（既に作成・承認済み） |

### 修正ファイル

| パス | 修正内容 |
|------|---------|
| `routes/web.php` | `/housing` ルート追加 |
| `resources/views/layouts/partials/sidebar.blade.php` | 住宅事業グループ先頭にダッシュボード項目追加（モバイル/デスクトップ 2 箇所） |

---

## Task 4: ルートとサイドバーを追加（Laravel 実装の入口）

**Files:**
- Modify: `routes/web.php`（housing prefix グループの先頭）
- Modify: `resources/views/layouts/partials/sidebar.blade.php`（100行目周辺・326行目周辺の2箇所）
- Create: `app/Http/Controllers/Housing/HousingDashboardController.php`（仮実装）
- Create: `resources/views/housing/dashboard.blade.php`（最小 skeleton）

- [ ] **Step 1: routes/web.php にルート追加**

`routes/web.php` の `Route::prefix('housing')->group(function () {` ブロック（900行目付近）の最初の Route 定義のすぐ上に以下を追加:

```php
        // 住宅事業ダッシュボード（建売 + 注文住宅 成約フォーカス）
        Route::get('/', [\App\Http\Controllers\Housing\HousingDashboardController::class, 'index'])
            ->name('housing.dashboard');
```

- [ ] **Step 2: サイドバー（デスクトップ）にダッシュボード項目追加**

`resources/views/layouts/partials/sidebar.blade.php` の 100-104 行目周辺、`<x-sidebar-group label="住宅事業">` 内最初の `<x-sidebar-item>` の **直前** に追加:

```blade
            <x-sidebar-item :href="url('/housing')" label="ダッシュボード" :active="request()->is('housing') || request()->is('housing/')" />
```

- [ ] **Step 3: サイドバー（モバイル）も同様に追加**

326-330 行目周辺の `<x-sidebar-group label="住宅事業">` の最初の `<x-sidebar-item>` 直前にも同じ行を追加。

- [ ] **Step 4: HousingDashboardController を仮実装で作成**

`app/Http/Controllers/Housing/HousingDashboardController.php` を新規作成:

```php
<?php

namespace App\Http\Controllers\Housing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * 住宅事業ダッシュボード（建売 + 注文住宅 成約フォーカス）
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
        $now = now();
        return view('housing.dashboard', [
            'fiscalYear' => (string) ($now->month >= 5 ? $now->year : $now->year - 1),
            'period' => 'all',
            'fiscalYearOptions' => [],
            'kpi' => null,
            'monthly' => null,
            'paginated' => null,
            'request' => $request,
        ]);
    }
}
```

- [ ] **Step 5: dashboard.blade.php の最小 skeleton を作成**

`resources/views/housing/dashboard.blade.php`:

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
        {{-- 年度・期セレクターは後続タスクで実装 --}}
    </div>

    <p class="text-sm text-gray-500">Phase 2 実装中です（KPI / 成約一覧 / グラフ は後続タスクで実装）。</p>

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

## Task 5: DTO 正規化と成約フィルター・期判定ロジックを実装

**Files:**
- Modify: `app/Http/Controllers/Housing/HousingDashboardController.php`

- [ ] **Step 1: use 文を追加**

ファイル冒頭の `use Illuminate\Http\Request;` の前後に以下を追加:

```php
use App\Enums\CustomOrderStatus;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
```

- [ ] **Step 2: index() を更新**

```php
    public function index(Request $request)
    {
        $fiscalYear = $request->input('fiscal_year', (string) $this->getCurrentFiscalYear());
        $period = $request->input('period', 'all');
        if (!in_array($period, ['all', 'first', 'second'], true)) {
            $period = 'all';
        }

        $items = $this->collectContractedItems($fiscalYear, $period);

        $fiscalYearOptions = $this->buildFiscalYearOptions();

        return view('housing.dashboard', [
            'fiscalYear' => $fiscalYear,
            'period' => $period,
            'fiscalYearOptions' => $fiscalYearOptions,
            'items' => $items,           // 一時的: 後続タスクで kpi / monthly / paginated に置き換える
            'kpi' => null,
            'monthly' => null,
            'paginated' => null,
            'request' => $request,
        ]);
    }
```

- [ ] **Step 3: 成約収集・DTO・期判定の private メソッドを追加**

`index()` 閉じ括弧の直後に追加:

```php
    /**
     * 年度・期フィルター込みで両モデルから成約済み DTO コレクションを生成する
     */
    protected function collectContractedItems(string $fiscalYear, string $period): Collection
    {
        // 成約日範囲（fiscal_year=all なら null）
        $range = ($fiscalYear === 'all' || $fiscalYear === '')
            ? null
            : $this->periodRange((int) $fiscalYear, $period);

        // 建売: 成約済み（contract が紐づいている）のみ
        $properties = HsProperty::with(['createdBy', 'contract', 'projectLot', 'procurement'])
            ->whereHas('contract', function ($q) use ($range) {
                if ($range) {
                    $q->whereBetween('contract_date', [$range[0], $range[1]]);
                }
            })
            ->get();

        // 注文: 引渡し済み（status=delivered, delivery_date あり）のみ
        $orders = HsCustomOrder::with(['createdBy'])
            ->where('status', CustomOrderStatus::Delivered->value)
            ->whereNotNull('delivery_date');
        if ($range) {
            $orders = $orders->whereBetween('delivery_date', [$range[0], $range[1]]);
        }
        $orders = $orders->get();

        $items = collect();
        foreach ($properties as $p) {
            $items->push($this->mapPropertyToDto($p));
        }
        foreach ($orders as $o) {
            $items->push($this->mapOrderToDto($o));
        }

        // 成約日降順
        return $items->sortByDesc(function ($it) {
            return $it['contracted_date'] ? $it['contracted_date']->timestamp : 0;
        })->values();
    }

    /**
     * HsProperty を統合 DTO に変換する（成約済み前提）
     */
    protected function mapPropertyToDto(HsProperty $p): array
    {
        $contractDate = $p->contract && $p->contract->contract_date
            ? Carbon::parse($p->contract->contract_date)
            : null;

        return [
            'type'              => 'building',
            'id'                => $p->id,
            'code'              => $p->property_code,
            'name'              => $p->property_name,
            'address'           => $p->address,
            'status_label'      => '成約',
            'status_style'      => 'background: #d1fae5; color: #065f46;',
            'staff_name'        => $this->lastNameOnly($p->createdBy?->name),
            'staff_id'          => $p->created_by,
            'contracted_date'   => $contractDate,
            'selling_price'     => $p->getSellingPriceTotal(),
            'total_cost'        => $p->getTotalCost(),
            'gross_profit'      => $p->getGrossProfit(),
            'gross_profit_rate' => $p->getGrossProfitRate(),
            'detail_url'        => route('housing.properties.show', $p),
        ];
    }

    /**
     * HsCustomOrder を統合 DTO に変換する（引渡し済み前提）
     */
    protected function mapOrderToDto(HsCustomOrder $o): array
    {
        $deliveryDate = $o->delivery_date ? Carbon::parse($o->delivery_date) : null;

        return [
            'type'              => 'custom-order',
            'id'                => $o->id,
            'code'              => $o->order_code,
            'name'              => $o->order_name,
            'address'           => $o->address,
            'status_label'      => '引渡し済み',
            'status_style'      => 'background: #a7f3d0; color: #064e3b;',
            'staff_name'        => $this->lastNameOnly($o->createdBy?->name),
            'staff_id'          => $o->created_by,
            'contracted_date'   => $deliveryDate,
            'selling_price'     => $o->getTotalSellingPrice(),
            'total_cost'        => $o->getTotalCost(),
            'gross_profit'      => $o->getTotalProfit(),
            'gross_profit_rate' => $o->getTotalProfitRate(),
            'detail_url'        => route('housing.custom-orders.show', $o),
        ];
    }

    /**
     * 年度・期から Carbon 範囲を返す
     * - 全期: 5月1日〜翌年4月30日
     * - 上期: 5月1日〜10月31日
     * - 下期: 11月1日〜翌年4月30日
     */
    protected function periodRange(int $fy, string $period): array
    {
        if ($period === 'first') {
            return [
                Carbon::create($fy, 5, 1)->startOfDay(),
                Carbon::create($fy, 10, 31)->endOfDay(),
            ];
        }
        if ($period === 'second') {
            return [
                Carbon::create($fy, 11, 1)->startOfDay(),
                Carbon::create($fy + 1, 4, 30)->endOfDay(),
            ];
        }
        // 'all' (= 全期)
        return [
            Carbon::create($fy, 5, 1)->startOfDay(),
            Carbon::create($fy + 1, 4, 30)->endOfDay(),
        ];
    }

    /**
     * 年度オプションリスト（過去2年〜来年度 + 全期間）
     */
    protected function buildFiscalYearOptions(): array
    {
        $current = $this->getCurrentFiscalYear();
        $options = [];
        for ($y = $current + 1; $y >= $current - 2; $y--) {
            $options[(string) $y] = $y . '年度';
        }
        $options['all'] = '全期間';
        return $options;
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
        <p class="text-sm text-gray-700">成約 DTO 件数: <span class="font-bold">{{ count($items ?? []) }}</span> 件</p>
        @if(count($items ?? []) > 0)
            <p class="text-xs text-gray-500 mt-2">先頭1件: {{ $items[0]['code'] ?? '' }} / {{ $items[0]['name'] ?? '' }} / {{ $items[0]['contracted_date']?->format('Y-m-d') ?? '' }}</p>
        @endif
    </div>
```

- [ ] **Step 5: ブラウザで `/housing` を再読み込み**

期待: 「成約 DTO 件数: NN 件」が表示。エラーなし。

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/Housing/HousingDashboardController.php resources/views/housing/dashboard.blade.php
git commit -m "住宅事業ダッシュボード: 成約 DTO 正規化・期判定ロジックを実装"
```

---

## Task 6: KPI 集計と KPI カード partial を実装、年度・期セレクターをヘッダーに追加

**Files:**
- Modify: `app/Http/Controllers/Housing/HousingDashboardController.php`
- Create: `resources/views/housing/_dashboard_kpi.blade.php`
- Modify: `resources/views/housing/dashboard.blade.php`

- [ ] **Step 1: buildKpi メソッドを Controller に追加**

`getCurrentFiscalYear()` の **直前** に以下を追加:

```php
    /**
     * KPI 集計（成約のみ - 件数・売上・原価・粗利・粗利率・種別内訳）
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

`$items = $this->collectContractedItems(...)` の直後に追加:

```php
        $kpi = $this->buildKpi($items);
```

view 配列の `'kpi' => null,` を `'kpi' => $kpi,` に変更。

- [ ] **Step 3: KPI partial を作成**

`resources/views/housing/_dashboard_kpi.blade.php`:

```blade
{{-- KPI カード（4枚・成約のみ） --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    {{-- 成約件数 --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-3">
        <div class="text-xs text-gray-500">成約件数</div>
        <div class="text-lg font-bold text-gray-900" style="font-size: 22px;">{{ number_format($kpi['count_total']) }}件</div>
        <div class="text-xs text-gray-400">建売 {{ $kpi['count_building'] }} / 注文 {{ $kpi['count_custom'] }}</div>
    </div>

    {{-- 売上合計 --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-3">
        <div class="text-xs text-gray-500">売上合計</div>
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

- [ ] **Step 4: dashboard.blade.php のヘッダーに 年度・期セレクター追加 + KPI partial include**

ヘッダー部の `{{-- 年度・期セレクターは後続タスクで実装 --}}` を以下で置き換え:

```blade
        <form method="GET" action="{{ route('housing.dashboard') }}" style="display: flex; gap: 8px;">
            <select name="fiscal_year" onchange="this.form.submit()"
                    class="h-9 px-3 border border-gray-300 rounded-md text-sm bg-white">
                @foreach($fiscalYearOptions as $value => $label)
                    <option value="{{ $value }}" {{ $fiscalYear === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            <select name="period" onchange="this.form.submit()"
                    class="h-9 px-3 border border-gray-300 rounded-md text-sm bg-white">
                <option value="all"    {{ $period === 'all' ? 'selected' : '' }}>全期</option>
                <option value="first"  {{ $period === 'first' ? 'selected' : '' }}>上期</option>
                <option value="second" {{ $period === 'second' ? 'selected' : '' }}>下期</option>
            </select>
        </form>
```

そして DTO 件数仮表示の `<div class="bg-white...">成約 DTO 件数...</div>` を以下で置き換え:

```blade
    @include('housing._dashboard_kpi', ['kpi' => $kpi])
```

- [ ] **Step 5: ブラウザで `/housing` を再読み込み**

期待:
- ヘッダー右に [年度▼] [期▼] が横並び
- 年度切替で URL に `?fiscal_year=2026` が付き、KPI が連動更新
- 期切替で URL に `&period=first` が付き、KPI が連動更新
- KPI カード4枚（成約件数・売上・原価・粗利+率）が表示される

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/Housing/HousingDashboardController.php resources/views/housing/_dashboard_kpi.blade.php resources/views/housing/dashboard.blade.php
git commit -m "住宅事業ダッシュボード: KPI 集計・年度/期セレクター・KPI カード partial を実装"
```

---

## Task 7: 成約一覧テーブル + ページングを実装

**Files:**
- Modify: `app/Http/Controllers/Housing/HousingDashboardController.php`
- Create: `resources/views/housing/_dashboard_contracted.blade.php`
- Modify: `resources/views/housing/dashboard.blade.php`

- [ ] **Step 1: paginate メソッドを Controller に追加**

`buildKpi()` の直後に追加:

```php
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
```

- [ ] **Step 2: index() で paginate を呼ぶ**

`$kpi = $this->buildKpi($items);` の直後に:

```php
        $paginated = $this->paginate($items, 20, $request);
```

view 配列の `'paginated' => null,` を `'paginated' => $paginated,` に変更。

- [ ] **Step 3: 成約一覧 partial を作成**

`resources/views/housing/_dashboard_contracted.blade.php`:

```blade
{{-- 成約一覧（成約済みのみ・8列・20件/ページ） --}}
<div class="bg-white border border-gray-200 rounded-lg px-4 py-3 mb-5" id="contracted-list">
    <div class="text-sm font-semibold text-gray-700 mb-3">成約一覧</div>

    <div style="overflow-x: auto;">
        <table class="w-full" style="border-collapse: collapse;">
            <thead>
                <tr>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; white-space: nowrap;">種別</th>
                    <th class="py-2.5 text-left text-xs font-semibold text-gray-600 bg-gray-50" style="padding-left: 16px; border-bottom: 2px solid #e5e7eb; white-space: nowrap;">案件名</th>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; white-space: nowrap;">担当者</th>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; white-space: nowrap;">成約日</th>
                    <th class="px-3 py-2.5 text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; text-align: right; padding-right: 16px; white-space: nowrap;">売上</th>
                    <th class="px-3 py-2.5 text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; text-align: right; padding-right: 16px; white-space: nowrap;">原価</th>
                    <th class="px-3 py-2.5 text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; text-align: right; padding-right: 16px; white-space: nowrap;">粗利</th>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; white-space: nowrap;">詳細</th>
                </tr>
            </thead>
            <tbody>
                @forelse($paginated as $it)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-3" style="border-bottom: 1px solid #f3f4f6; white-space: nowrap; text-align: center;">
                            @if($it['type'] === 'building')
                                <span style="display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; background: #d1fae5; color: #065f46;">建売</span>
                            @else
                                <span style="display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; background: #dbeafe; color: #1e40af;">注文</span>
                            @endif
                        </td>
                        <td class="py-3" style="padding-left: 16px; border-bottom: 1px solid #f3f4f6; text-align: left;">
                            <div class="text-sm font-semibold text-gray-900">{{ $it['name'] }}</div>
                            @if($it['address'])
                                <div class="text-xs text-gray-500">{{ $it['address'] }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-sm text-gray-800" style="border-bottom: 1px solid #f3f4f6; white-space: nowrap; text-align: center;">{{ $it['staff_name'] ?? '—' }}</td>
                        <td class="px-3 py-3 text-sm text-gray-800" style="border-bottom: 1px solid #f3f4f6; white-space: nowrap; text-align: center;">
                            {{ $it['contracted_date'] ? $it['contracted_date']->format('Y-m-d') : '—' }}
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
                        <td class="px-3 py-3" style="border-bottom: 1px solid #f3f4f6; white-space: nowrap; text-align: center;">
                            <a href="{{ $it['detail_url'] }}"
                               style="display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #b45309; border: 1px solid #b45309; border-radius: 5px; background: #fff; text-decoration: none;">詳細</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-3 py-8 text-center text-sm text-gray-500" style="border-bottom: 1px solid #f3f4f6;">該当する成約がありません</td>
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

- [ ] **Step 4: dashboard.blade.php に成約一覧 partial を include（KPI の直後）**

KPI include の直後に追加:

```blade
    @include('housing._dashboard_contracted', ['paginated' => $paginated])
```

- [ ] **Step 5: ブラウザで `/housing` を再読み込み**

期待:
- KPI カード下に成約一覧（8列）が表示される
- 21件以上ある場合はページング動作
- 0件時に「該当する成約がありません」表示
- 詳細リンク: 建売→ properties.show、注文→ custom-orders.show
- 種別バッジ: 建売(緑) / 注文(青)
- セル整列: ヘッダー中央 = データ中央（種別/担当者/成約日/詳細）/ 案件名 = 左揃え / 金額3列 = 右揃え

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/Housing/HousingDashboardController.php resources/views/housing/_dashboard_contracted.blade.php resources/views/housing/dashboard.blade.php
git commit -m "住宅事業ダッシュボード: 成約一覧テーブル・ページングを実装"
```

---

## Task 8: 月次成約件数 棒グラフ (Chart.js) を実装

**Files:**
- Modify: `app/Http/Controllers/Housing/HousingDashboardController.php`
- Create: `resources/views/housing/_dashboard_chart.blade.php`
- Modify: `resources/views/housing/dashboard.blade.php`

- [ ] **Step 1: buildMonthly メソッドを Controller に追加**

`paginate()` の直後に追加:

```php
    /**
     * 月次集計（成約件数 - 建売成約 + 注文引渡し合算）
     * 全期: 12ヶ月（5月〜翌4月）
     * 上期: 6ヶ月（5月〜10月）
     * 下期: 6ヶ月（11月〜翌4月）
     */
    protected function buildMonthly(Collection $items, int $fy, string $period): array
    {
        // 月ラベル + 範囲
        if ($period === 'first') {
            $labels = ['5月','6月','7月','8月','9月','10月'];
            $start = Carbon::create($fy, 5, 1)->startOfDay();
        } elseif ($period === 'second') {
            $labels = ['11月','12月','1月','2月','3月','4月'];
            $start = Carbon::create($fy, 11, 1)->startOfDay();
        } else {
            $labels = ['5月','6月','7月','8月','9月','10月','11月','12月','1月','2月','3月','4月'];
            $start = Carbon::create($fy, 5, 1)->startOfDay();
        }
        $count = count($labels);
        $data = array_fill(0, $count, 0);

        foreach ($items as $it) {
            $date = $it['contracted_date'];
            if (!$date) continue;
            // start からの月オフセット（0始まり）
            $offset = ($date->year - $start->year) * 12 + ($date->month - $start->month);
            if ($offset >= 0 && $offset < $count) {
                $data[$offset]++;
            }
        }

        return [
            'labels' => $labels,
            'data'   => $data,
        ];
    }
```

- [ ] **Step 2: index() で buildMonthly を呼ぶ**

`$paginated = $this->paginate(...)` の直後に追加:

```php
        $monthly = $fiscalYear === 'all' ? null : $this->buildMonthly($items, (int) $fiscalYear, $period);
```

view 配列の `'monthly' => null,` を `'monthly' => $monthly,` に変更。

- [ ] **Step 3: chart partial を作成**

`resources/views/housing/_dashboard_chart.blade.php`:

```blade
{{-- 月次成約件数 棒グラフ（Chart.js） --}}
@if($monthly !== null)
<div class="bg-white border border-gray-200 rounded-lg px-4 py-3 mb-5">
    <div class="text-sm font-semibold text-gray-700 mb-3">月次成約件数</div>
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
                { label: '成約件数', data: data.data, backgroundColor: '#047857' }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            },
            plugins: { legend: { display: false } }
        }
    });
})();
</script>
@endpush
@endif
```

- [ ] **Step 4: dashboard.blade.php に chart partial を include（成約一覧の直後）**

成約一覧 include の直後に追加:

```blade
    @include('housing._dashboard_chart', ['monthly' => $monthly])
```

- [ ] **Step 5: ブラウザで `/housing?fiscal_year=2026` を再読み込み**

期待:
- 成約一覧の下に月次グラフ表示
- 全期: 12 月分の緑バー
- 上期選択時: 5月〜10月（6本）のみ
- 下期選択時: 11月〜翌4月（6本）のみ
- 凡例非表示
- `?fiscal_year=all` でグラフ非表示

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/Housing/HousingDashboardController.php resources/views/housing/_dashboard_chart.blade.php resources/views/housing/dashboard.blade.php
git commit -m "住宅事業ダッシュボード: 月次成約件数 棒グラフ (Chart.js) を実装"
```

---

## Task 9: 30点品質監査 + 手動動作確認

**Files:**
- Read-only review (修正があれば該当ファイルを修正)

- [ ] **Step 1: CSS 規約監査**

```bash
grep -nE "(gap-5|md:grid-cols-2|mt-auto|py-0\.5|pb-2\.5|items-end|border-red-600|pl-9|pl-10|border-l-4 border-emerald-500)" resources/views/housing/dashboard.blade.php resources/views/housing/_dashboard_*.blade.php
```

期待: マッチなし。

- [ ] **Step 2: Alpine.js 規約監査**

```bash
grep -nE "(=>|x-data=\".*>.*\")" resources/views/housing/dashboard.blade.php resources/views/housing/_dashboard_*.blade.php
```

期待: 矢印関数 `=>` および x-data 内 `>` のマッチなし。

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
- [ ] 4-3. 年度切り替えで KPI / 成約一覧 / グラフ すべて連動更新
- [ ] 4-4. 期切替（全期/上期/下期）で表示データ範囲が変わる
- [ ] 4-5. 「全期間」選択時にグラフ非表示
- [ ] 4-6. ページング動作（21件以上ある場合）
- [ ] 4-7. テーブル0件時に「該当する成約がありません」表示
- [ ] 4-8. 詳細リンク: 建売→ `/housing/properties/{id}`、注文→ `/housing/custom-orders/{id}`
- [ ] 4-9. 種別バッジ: 建売(緑) / 注文(青)
- [ ] 4-10. 粗利額の色: プラス緑 / マイナス赤
- [ ] 4-11. 担当者は姓のみ表示
- [ ] 4-12. 金額表示は `28,500,000円`（`¥` プレフィックスなし）
- [ ] 4-13. 権限なしユーザーで 403（住宅事業外のロールで確認）
- [ ] 4-14. グラフ: 凡例非表示、緑バー単一系列

- [ ] **Step 5: パフォーマンス確認**

ブラウザの開発者ツール Network タブで `/housing` 読み込み時間が3秒以下を確認。

- [ ] **Step 6: 修正があればコミット**

監査で見つかった問題を修正したらコミット:

```bash
git add <修正したファイル>
git commit -m "住宅事業ダッシュボード: 30点品質監査の指摘を修正"
```

---

## Task 10: BACKLOG 更新と PR

**Files:**
- Modify: `docs/BACKLOG.md`

- [ ] **Step 1: BACKLOG を更新**

`docs/BACKLOG.md` の優先度3セクションを以下に置換:

```markdown
## ✅ 優先度3: 住宅事業 横断ダッシュボード（実装完了）

詳細仕様: @docs/superpowers/specs/2026-04-27-housing-cross-list-design.md
実装計画: @docs/superpowers/plans/2026-04-27-housing-cross-list.md

### 概要

`/housing` ルートに住宅事業ダッシュボードを新設。建売物件 + 注文住宅の **成約フォーカス** で KPI / 成約一覧 / 月次グラフを構成。

### 実装内容

- Controller: `Housing/HousingDashboardController` (1本)
- Blade: `housing/dashboard.blade.php` + 3 partial（KPI / 成約一覧 / グラフ）
- ルート: `/housing` (housing.dashboard)
- サイドバー: 住宅事業グループ先頭にダッシュボード項目追加
- フィルター: 年度（過去2年〜来年度+1 + 全期間）+ 期（全期/上期/下期）
```

- [ ] **Step 2: コミット**

```bash
git add docs/BACKLOG.md
git commit -m "BACKLOG: 優先度3 住宅事業ダッシュボード 完了マークを追加"
```

- [ ] **Step 3: 全コミット履歴を確認**

```bash
git log --oneline 13.x..HEAD
```

期待: Task 1〜10 の各コミットが表示される。

- [ ] **Step 4: PR 作成（ユーザー承認後）**

ユーザーに方針提示:

> ブランチ `claude/amazing-elbakyan-5bd780` を origin に push し、`13.x` 向けの PR を作成してよいか？タイトル案: 「住宅事業ダッシュボード（BACKLOG優先度3）を追加」

ユーザー承認後:

```bash
git push -u origin claude/amazing-elbakyan-5bd780
gh pr create --base 13.x --title "住宅事業ダッシュボード（BACKLOG優先度3）を追加" --body "$(cat <<'EOF'
## Summary
- 建売 + 注文住宅の成約をフォーカスする `/housing` ダッシュボードを新設
- KPI 4枚（成約のみ）/ 成約一覧（20件ページング）/ 月次成約件数グラフ (Chart.js)
- 年度・期（全期/上期/下期）フィルター
- 既存コントローラー・モデル・ルートには影響なし

## Test plan
- [ ] `/housing` でダッシュボード表示
- [ ] 年度・期切り替えで全セクション連動更新
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
| HsProperty isSold / cost methods | `app/Models/HsProperty.php`（contract HasOne, getTotalCost, getSellingPriceTotal, getGrossProfit, getGrossProfitRate） |
| HsCustomOrder | `app/Models/HsCustomOrder.php`（status, delivery_date, getTotalSellingPrice, getTotalCost, getTotalProfit, getTotalProfitRate） |
| CustomOrderStatus Enum | `app/Enums/CustomOrderStatus.php`（Delivered = 'delivered'） |
| 既存 properties/index.blade.php | カラム構成・スタイル参考 |
| 承認済みモック | `docs/mockups/housing/dashboard.html` |
