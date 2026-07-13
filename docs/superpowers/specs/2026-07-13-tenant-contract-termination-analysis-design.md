# テナント管理 契約・解約分析（年×月集計）設計書

- 作成日: 2026-07-13
- 対象: テナント管理（新カテゴリー「分析」）
- 種別: 新機能（read-only 集計画面。DB 変更なし）
- 直接の先例:
  - **`RentalIncomeService`**（`app/Services/Tenant/RentalIncomeService.php`）— 契約から集計を **DB非依存（PHP側）** で組み立てるサービス構造を踏襲。SQLite テストでも動く。
  - **`Tenant\ContractController::index`**（`app/Http/Controllers/Tenant/ContractController.php`）— `contracts.department = Tenant` で部署横断テーブルを絞る書式・フィルタバー/一覧ビューの雛形。
  - **Bug #26 / #23 / #1**（`docs/RULES.md`）— `@json` 多行配列・`x-data` 属性内 `@json`・アロー関数の罠。本 spec はサーバ側 `@foreach` レンダリングで全て回避。

## 1. 背景・目的

テナント管理に新カテゴリー「**分析**」を設ける。第一弾として、契約と解約が **どの年・どの月に多いか**（季節性・年次トレンド）を一目で把握できる集計画面を提供する。

達成すること:

1. **契約分析**: 契約日（`contract_date`）を **暦年 × 暦月** で集計し、件数のマトリクスで表示。
2. **解約分析**: 解約済み契約の退去日（`contract_end_date`）を同じく **暦年 × 暦月** で集計。
3. 件数の多いセルを緑の濃淡（ヒートマップ）で塗り、**年計（どの年）・月計（どの月＝季節性）** を行/列合計で同時に把握。
4. 契約／解約は **1画面のタブ切替**（Alpine、リロードなし）。

## 2. 決定事項（確定）

brainstorming でユーザー確認済み。

| # | 論点 | 決定 |
|---|---|---|
| D1 | 年の集計軸 | **暦年（1〜12月）**。例: 2024年 = 2024/1〜2024/12。会計期（5/1始まり）ではない |
| D2 | 集計値 | **件数のみ**。金額（賃料合計）は入れない |
| D3 | 表示方式 | **年×月クロス集計マトリクス（ヒートマップ濃淡）** ＋ 年計/月計/総計。グラフ（Chart.js）は使わない |
| D4 | 契約／解約の配置 | **1画面をタブ切替**（契約タブ / 解約タブ）。両方サーバ側集計済みで Alpine の `x-show` 切替 |
| D5 | 契約分析の対象 | **全ステータス**（`active`＋`terminated`）を `contract_date` で計上。解約済み契約も「契約した月」で 1 件数える |
| D6 | 解約分析の対象 | **`status = terminated` のみ** を `contract_end_date`（退去日）で計上 |
| D7 | サイドバー | テナント管理グループ内に **「分析」サブ見出し**（システム管理と同じ区切りスタイル）＋ 項目「**契約・解約分析**」1本 |
| D8 | 権限 | テナント管理の他一覧と同じ **`department.access:tenant`**（全ロール閲覧可）。read-only のため `role:` 制限なし |

## 3. 現状の整理（検証済みのコード事実）

### 3.1 契約データの日付・ステータス

- `Contract`（`app/Models/Contract.php`）: `contract_date`・`contract_end_date` はともに `casts()` で `'date'` → **Carbon インスタンス**（`->year` / `->month` が使える）。`status` は `ContractStatus` cast。
- `ContractStatus`（`app/Enums/ContractStatus.php`）: `Active = 'active'` / `Terminated = 'terminated'` の 2 値のみ。
- 解約処理 `ContractController::terminate`（`app/Http/Controllers/Tenant/ContractController.php:374-430`）は `status = Terminated` かつ `contract_end_date = 退去日` を設定。→ **terminated 契約は `contract_end_date` を必ず保有**（terminate は `required|date`）。
- `Contract` は **SoftDeletes** → 素の `->get()` は削除済み（`deleted_at`）を自動除外。**論理削除された契約は分析から自動的に外れる**。

### 3.2 `contracts` は部署横断テーブル（絞り込み必須）

- `contracts` は `department` 列を持つ横断テーブル。実 DB 分布は現在 `tenant` のみ 10 件だが、将来他部署が入り得る。
- `ContractController::index` は `Contract::where('contracts.department', DepartmentCode::Tenant)` で絞る（`ContractController.php:40`）。
- → 分析集計も **必ず `where('department', DepartmentCode::Tenant)`** で絞る。`DepartmentCode::Tenant = 'tenant'`。

### 3.3 テスト DB（SQLite）に `YEAR()`/`MONTH()` 関数が無い ← 設計の要

- Feature テストは **SQLite in-memory**（`phpunit.xml`・memory）。SQLite には MySQL の `YEAR()`/`MONTH()` 関数が **存在しない**（`strftime` のみ）。
- → `selectRaw('YEAR(contract_date) ...')` で集計すると **本番 MySQL では動くがテストで落ちる**。
- **対策**: 集計は SQL の日付関数に頼らず、**日付行を取得して PHP 側（Carbon）で年月グルーピング**する。`RentalIncomeService` と同じ DB非依存方針。テナント契約は低ボリューム（現状 10 件）なので PHP 集計で十分。

### 3.4 サイドバー: テナント管理グループは 2 箇所

- `resources/views/layouts/partials/sidebar.blade.php` に `<x-sidebar-group label="テナント管理" section="tenant">` が **2 箇所**（`:61` 通常展開・`:330` システム管理展開時）。**両方に**分析項目を追加する。
- 折りたたみアイコン版（`:217`）は `/tenant/*` 単一アイコンで、分析も `/tenant/*` 配下のため既存アイコンが自動でアクティブになる → **変更不要**。
- サブ見出しの区切りスタイルは システム管理グループ内（`:136-139`）の div マークアップを流用。

### 3.5 ビューの雛形（`tenant/contracts/index.blade.php`）

- `@extends('layouts.app')` ＋ `@section('title')` / `@section('breadcrumb')` / `@section('content')`。
- ページヘッダー: `<h1 class="text-lg font-bold text-gray-900">`。
- `x-cloak` の CSS は `app.css` に定義済み（memory 3007）→ タブ初期表示のちらつき防止に使える。

## 4. 実装設計

方針は **read-only・最小構成**。変更/新規は 6 ファイル（新規 4・編集 2）。**DB 変更なし・raw SQL なし**。

| ファイル | 区分 | 役割 |
|---|---|---|
| `app/Services/Tenant/ContractAnalysisService.php` | 新規 | 集計ロジック（DB非依存・PHP 集計） |
| `app/Http/Controllers/Tenant/AnalysisController.php` | 新規 | `index` で集計 → ビューへ |
| `resources/views/tenant/analysis/index.blade.php` | 新規 | タブ切替 + 2 マトリクス |
| `resources/views/tenant/analysis/_matrix.blade.php` | 新規 | マトリクス表（契約/解約で共用） |
| `routes/web.php` | 編集 | ルート 1 本追加 |
| `resources/views/layouts/partials/sidebar.blade.php` | 編集 | サブ見出し＋項目を 2 箇所に追加 |

### 4.1 集計サービス `ContractAnalysisService`（新規）

`contract_date` / `status` / `contract_end_date` の 3 列だけ取得し、PHP 側で契約・解約 2 種のマトリクスを 1 パスで構築する。

```php
<?php

namespace App\Services\Tenant;

use App\Enums\ContractStatus;
use App\Enums\DepartmentCode;
use App\Models\Contract;
use Illuminate\Support\Collection;

class ContractAnalysisService
{
    /**
     * テナント契約の「契約」「解約」を暦年×暦月で集計する（DB非依存・PHP側集計）。
     *
     * @return array{contract: array, termination: array}
     */
    public function build(): array
    {
        // department=tenant・非削除のみ（SoftDeletes グローバルスコープで deleted_at 自動除外）
        $rows = Contract::query()
            ->where('department', DepartmentCode::Tenant)
            ->get(['contract_date', 'status', 'contract_end_date']);

        // 契約: 全ステータス・contract_date 基準（terminated も契約月で 1 件計上・D5）
        $contractDates = $rows
            ->filter(fn (Contract $c) => $c->contract_date !== null)
            ->map(fn (Contract $c) => [(int) $c->contract_date->year, (int) $c->contract_date->month]);

        // 解約: terminated のみ・contract_end_date 基準（D6）
        $terminationDates = $rows
            ->filter(fn (Contract $c) => $c->status === ContractStatus::Terminated && $c->contract_end_date !== null)
            ->map(fn (Contract $c) => [(int) $c->contract_end_date->year, (int) $c->contract_end_date->month]);

        return [
            'contract'    => $this->matrix($contractDates),
            'termination' => $this->matrix($terminationDates),
        ];
    }

    /**
     * [year, month] のコレクションから 年×月 マトリクスを組み立てる。
     *
     * @param  Collection<int, array{0:int,1:int}>  $pairs
     * @return array{years: list<int>, cells: array, yearTotals: array, monthTotals: array, grandTotal: int, max: int}
     */
    private function matrix(Collection $pairs): array
    {
        $cells = [];                       // [year][1..12] => count
        $yearTotals = [];                  // [year] => count
        $monthTotals = array_fill(1, 12, 0);
        $grandTotal = 0;

        foreach ($pairs as [$y, $m]) {
            $cells[$y][$m] = ($cells[$y][$m] ?? 0) + 1;
            $yearTotals[$y] = ($yearTotals[$y] ?? 0) + 1;
            $monthTotals[$m]++;
            $grandTotal++;
        }

        // ヒートマップ濃淡スケール用の最大セル値（合計は除外）
        $max = 0;
        foreach ($cells as $row) {
            foreach ($row as $v) {
                $max = max($max, $v);
            }
        }

        $years = array_keys($yearTotals);
        rsort($years); // 新しい年を上に

        return [
            'years'       => $years,       // 降順
            'cells'       => $cells,       // [year][month] => count（欠損セルは未定義＝0扱い）
            'yearTotals'  => $yearTotals,  // [year] => count
            'monthTotals' => $monthTotals, // [1..12] => count（0 埋め済み）
            'grandTotal'  => $grandTotal,
            'max'         => $max,         // 0 のとき空データ（ゼロ除算ガードに使用）
        ];
    }
}
```

**要点**:
- SQL の日付関数を使わない（SQLite テスト対策・3.3）。
- `contract_date` が null の行は契約集計から除外（実務上ほぼ無いが安全のためガード）。
- 契約集計は `status` を問わない（D5）。解約集計は `Terminated` かつ `contract_end_date` 非 null（D6）。
- 戻り値は純粋な配列（`@json` に渡さない・Blade 側で `@foreach`）。

### 4.2 コントローラ `Tenant\AnalysisController`（新規）

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\ContractAnalysisService;
use Illuminate\View\View;

class AnalysisController extends Controller
{
    /**
     * 契約・解約の年×月分析
     * Route: GET /tenant/analysis
     */
    public function index(ContractAnalysisService $service): View
    {
        $data = $service->build();

        return view('tenant.analysis.index', [
            'contract'    => $data['contract'],
            'termination' => $data['termination'],
        ]);
    }
}
```

### 4.3 ルート（`routes/web.php`）

tenant prefix グループ（`:167` `Route::prefix('tenant')->middleware('department.access:tenant')`）内、契約ブロックの後に 1 本追加:

```php
// 分析（契約・解約の年×月集計・read-only）
Route::get('/analysis', [\App\Http\Controllers\Tenant\AnalysisController::class, 'index'])
    ->name('tenant.analysis.index');
```

- `department.access:tenant` グループ内なので追加ミドルウェア不要（D8）。
- 新規 PHP クラス（Controller / Service）を追加するため、**本番反映時は main repo で `composer dump-autoload` が必要**（§6）。

### 4.4 サイドバー（`sidebar.blade.php`・2 箇所）

`:61` と `:330` の `<x-sidebar-group label="テナント管理">` それぞれで、末尾の「問合せ管理」項目の直後に以下を追加:

```blade
{{-- サブ見出し: 分析 --}}
<div style="display: flex; align-items: center; gap: 8px; padding: 8px 20px 3px;">
    <span style="font-size: 10px; font-weight: 600; color: #6B7280; letter-spacing: 0.05em; white-space: nowrap;">分析</span>
    <span style="flex: 1; height: 1px; background: #D1D5DB;"></span>
</div>
<x-sidebar-item :href="url('/tenant/analysis')" label="契約・解約分析" :active="request()->is('tenant/analysis*')" />
```

- サブ見出しの div マークアップはシステム管理グループ（`:136-139`）と同一（インライン style のため Vite CSS 制約と無縁）。

### 4.5 ビュー `tenant/analysis/index.blade.php`（新規）

Alpine のタブ状態だけを持つ最小の `x-data`（**オブジェクトリテラル・関数でない**＝Bug #1 回避。`@json` を **使わない**＝Bug #23/#26 回避）。マトリクス本体はサーバ側 `@foreach` で描画。

```blade
@extends('layouts.app')

@section('title', '契約・解約分析')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">契約・解約分析</span>
@endsection

@section('content')
<div x-data="{ tab: 'contract' }">

    {{-- ページヘッダー --}}
    <div class="mb-5">
        <h1 class="text-lg font-bold text-gray-900">契約・解約分析</h1>
        <p class="text-sm text-gray-500" style="margin-top:4px;">契約日・解約日を暦年×暦月で集計。件数の多いセルほど濃く表示します。</p>
    </div>

    {{-- タブ（契約 / 解約） --}}
    <div class="flex gap-1 mb-4" role="tablist">
        <button type="button" @click="tab = 'contract'"
                :class="tab === 'contract' ? 'bg-emerald-600 text-white' : 'bg-white text-gray-700 border border-gray-300'"
                class="px-4 py-2 rounded-md text-sm font-semibold transition-colors">
            契約分析
        </button>
        <button type="button" @click="tab = 'termination'"
                :class="tab === 'termination' ? 'bg-emerald-600 text-white' : 'bg-white text-gray-700 border border-gray-300'"
                class="px-4 py-2 rounded-md text-sm font-semibold transition-colors">
            解約分析
        </button>
    </div>

    {{-- 契約マトリクス --}}
    <div x-show="tab === 'contract'" x-cloak>
        @include('tenant.analysis._matrix', ['matrix' => $contract, 'emptyLabel' => '契約データがありません'])
    </div>

    {{-- 解約マトリクス --}}
    <div x-show="tab === 'termination'" x-cloak>
        @include('tenant.analysis._matrix', ['matrix' => $termination, 'emptyLabel' => '解約データがありません'])
    </div>

</div>
@endsection
```

### 4.6 パーシャル `tenant/analysis/_matrix.blade.php`（新規・契約/解約共用）

`$matrix`（`ContractAnalysisService` の 1 マトリクス）と `$emptyLabel` を受け取る。横 14 列（年＋1〜12月＋年計）のため **`overflow-x:auto` コンテナ**で囲む。ヒートマップ濃淡・境界線は **インライン style**（Bug #19: 任意値 Tailwind は未コンパイル）。

```blade
@if($matrix['grandTotal'] === 0)
    <div class="bg-white border border-gray-200 rounded-lg" style="padding:40px; text-align:center; color:#9CA3AF; font-size:14px;">
        {{ $emptyLabel }}
    </div>
@else
    <div class="bg-white border border-gray-200 rounded-lg" style="padding:12px; overflow-x:auto;">
        <table style="border-collapse:collapse; width:100%; min-width:720px; font-size:13px;">
            <thead>
                <tr>
                    <th style="padding:6px 8px; text-align:left; color:#6B7280; font-weight:600; border-bottom:2px solid #E5E7EB; white-space:nowrap;">年＼月</th>
                    @for($m = 1; $m <= 12; $m++)
                        <th style="padding:6px 4px; text-align:center; color:#6B7280; font-weight:600; border-bottom:2px solid #E5E7EB; width:52px;">{{ $m }}</th>
                    @endfor
                    <th style="padding:6px 8px; text-align:center; color:#374151; font-weight:700; border-bottom:2px solid #E5E7EB; border-left:1px solid #E5E7EB; white-space:nowrap;">年計</th>
                </tr>
            </thead>
            <tbody>
                @foreach($matrix['years'] as $y)
                    <tr>
                        <th style="padding:6px 8px; text-align:left; color:#374151; font-weight:700; border-bottom:1px solid #F3F4F6; white-space:nowrap;">{{ $y }}</th>
                        @for($m = 1; $m <= 12; $m++)
                            @php
                                $count   = $matrix['cells'][$y][$m] ?? 0;
                                $ratio   = $matrix['max'] > 0 ? $count / $matrix['max'] : 0;
                                $opacity = $count > 0 ? number_format(0.12 + $ratio * 0.73, 2) : '0';
                                $textCol = $ratio > 0.55 ? '#ffffff' : '#111827';
                            @endphp
                            <td style="padding:6px 4px; text-align:center; border-bottom:1px solid #F3F4F6; background-color:rgba(5,150,105,{{ $opacity }}); color:{{ $textCol }}; font-variant-numeric:tabular-nums;">{{ $count > 0 ? $count : '' }}</td>
                        @endfor
                        <td style="padding:6px 8px; text-align:center; font-weight:700; color:#374151; border-bottom:1px solid #F3F4F6; border-left:1px solid #E5E7EB; font-variant-numeric:tabular-nums;">{{ $matrix['yearTotals'][$y] }}</td>
                    </tr>
                @endforeach
                {{-- 月計 --}}
                <tr>
                    <th style="padding:6px 8px; text-align:left; color:#374151; font-weight:700; border-top:2px solid #E5E7EB;">月計</th>
                    @for($m = 1; $m <= 12; $m++)
                        <td style="padding:6px 4px; text-align:center; font-weight:700; color:#374151; border-top:2px solid #E5E7EB; font-variant-numeric:tabular-nums;">{{ $matrix['monthTotals'][$m] > 0 ? $matrix['monthTotals'][$m] : '' }}</td>
                    @endfor
                    <td style="padding:6px 8px; text-align:center; font-weight:700; color:#047857; border-top:2px solid #E5E7EB; border-left:1px solid #E5E7EB; font-variant-numeric:tabular-nums;">{{ $matrix['grandTotal'] }}</td>
                </tr>
            </tbody>
        </table>
    </div>
@endif
```

**要点**:
- ヒートマップ: `opacity = 0.12 + ratio*0.73`（最小 0.12〜最大 0.85）。0 件は無色（`opacity=0`）＋空欄。文字色は濃いセルで白に反転。
- 総計は粗利色 `#047857`（Conventions）。
- `min-width:720px` + `overflow-x:auto` で狭幅時は横スクロール（body は横スクロールさせない）。
- Alpine データに一切依存しない純サーバ描画 → `@json`/属性内式の罠と無縁。

## 5. テスト計画（`tests/Feature/Tenant/ContractAnalysisTest.php` 新規・RefreshDatabase）

`Contract` は `HasFactory`（`ContractDeletionTest` 等で使用実績あり）。SQLite テスト DB に `contracts` は migration で構築される。集計は PHP 側のため SQLite でも `YEAR()`/`MONTH()` 問題なし（3.3 の担保）。

| # | 検証 |
|---|---|
| T1 | **契約マトリクス基本**: 複数年・複数月の tenant 契約を作成 → `cells[y][m]` / `yearTotals` / `monthTotals` / `grandTotal` が一致。`years` が降順 |
| T2 | **解約は退去日基準・契約は契約日基準**: `contract_date=2024-08`・`terminate` で `contract_end_date=2025-03` の契約 → 契約マトリクスは 2024/8 に +1、解約マトリクスは 2025/3 に +1（同一契約が両方に別セルで計上・D5/D6） |
| T3 | **active は解約に出ない**: `status=active` 契約は解約マトリクス `grandTotal=0`（`contract_end_date` があっても terminated でなければ除外） |
| T4 | **部署フィルタ**: `department=mansion` 等の契約は契約・解約どちらにも計上されない |
| T5 | **SoftDelete 除外**: 論理削除した契約は両マトリクスに出ない |
| T6 | **空データ**: 契約 0 件 → `grandTotal=0` / `years=[]` / `max=0`（ゼロ除算なし）。ビューは空状態メッセージ |
| T7 | **ヒートマップ max**: 最多セルが `max` に一致（濃淡スケールの分母） |
| T8 | **ルート 200・タブ描画**: tenant アクセス権ユーザーで `GET /tenant/analysis` → 200、「契約分析」「解約分析」両見出しと年/月計が表示 |

> 集計の中核（T1〜T7）は `ContractAnalysisService::build()` を直接呼ぶ Feature テストで検証（DB シードのみ、HTTP 不要）。T8 のみ HTTP リクエスト。

## 6. デプロイ・検証手順（本番反映）

1. worktree（`.claude/worktrees/tenant-analysis`）で実装 → `/commit`（1 コミット 1 関心事: Service / Controller+Route / View / Sidebar / Test を論理単位で分割可）。
2. main repo（`/Users/masanori/site/manage`）で `git checkout 13.x && git merge --ff-only tenant-analysis`。
3. **新規 PHP クラスあり**（`AnalysisController` / `ContractAnalysisService`）→ **main repo の cwd で `composer dump-autoload`**（⚠ worktree から実行すると autoloader の baseDir に worktree パスが焼き込まれる。必ず main repo で）。
4. **DB 変更なし** → SQL 実行不要。
5. **Blade 検証（Bug #26 対策・習慣として実施）**: `@json` 多行配列は使っていないが、コンパイル済みビューを lint:
   ```
   php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
   ```
6. **実データ検証**: 本番相当データ（複数年の契約・解約済み契約あり）で `/tenant/analysis` を開き、タブ切替・ヒートマップ濃淡・年計/月計/総計・空状態を確認。ローカルは実 DB（memory: `masa8787kanri63732`）でレンダリング確認。
7. `./deploy.sh`（rsync + 本番 `config:cache && route:cache && view:cache` 再生成）。**要ユーザー明示承認**。
8. origin/13.x への push はユーザー明示指示時のみ。

## 7. スコープ外（今回やらないこと）

- **金額集計**（賃料合計）。件数のみ（D2）。
- **物件別フィルタ**・担当者別・用途別などの絞り込み。まず全社（全テナント契約）集計。
- **グラフ**（Chart.js 折れ線/棒）。
- **CSV / Excel エクスポート**。
- **会計期（5/1始まり・第XX期）ベース**の集計軸。暦年のみ（D1）。
- **セルのドリルダウン**（クリックで該当月の契約一覧へ遷移）。
- **他部署**（Mansion / Housing / RealEstate / DAD）の同種分析。本 spec はテナント契約のみ。

## 8. リスク・留意点

- **SQLite と MySQL の日付関数差（最重要）**: 集計を SQL の `YEAR()`/`MONTH()` で書くと本番（MySQL）では動くがテスト（SQLite）で落ちる。**PHP 側集計（Carbon）で統一**してこれを回避する（3.3 / 4.1）。レビュー時、`selectRaw` に日付関数が紛れ込んでいないか確認。
- **ボリューム**: 全 tenant 契約を `->get()` して PHP 集計する。テナント契約は低ボリューム（現状 10 件）で問題なし。将来数千件規模になったら DB 集計（MySQL 側 `YEAR/MONTH` + SQLite 用分岐 or `strftime`）へ移行を検討（現時点では YAGNI）。
- **`contract_end_date` の意味**: active 契約にも予定終了日が入り得るが、解約集計は `status=Terminated` に限定するため予定終了日は混入しない（D6 / T3）。
- **Bug #23 / #26（@json）回避**: マトリクスはサーバ側 `@foreach` 描画。`x-data` には `@json` を入れず単純なタブ状態オブジェクトのみ。
- **Bug #1（アロー関数 x-data）回避**: `x-data="{ tab: 'contract' }"` はオブジェクトリテラルで `=>` を含まない。
- **Bug #19（未コンパイル Tailwind）回避**: ヒートマップ濃淡・任意値・テーブル境界はすべてインライン style。タブボタンは確認済みクラス（`bg-emerald-600` / `text-white` / `border` / `border-gray-300` / `rounded-md` / `px-4` / `py-2` / `text-sm` / `font-semibold`）のみ。`:class` バインドの値も確認済みクラスに限定。
- **composer dump-autoload の実行場所**: 新規クラス 2 本のため必須。worktree でなく main repo cwd で（§6-3・CLAUDE.md ワークフロー）。
- **サイドバー 2 箇所**: `:61` と `:330` の両方に追加漏れなく（片方だけだと展開状況により項目が出ないことがある）。
