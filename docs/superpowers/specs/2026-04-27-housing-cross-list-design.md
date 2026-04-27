# 住宅事業 横断ダッシュボード（BACKLOG 優先度3）設計書

- 起票日: 2026-04-27
- モック反映改訂日: 2026-04-27
- 対応 BACKLOG: 優先度3「住宅事業 横断一覧」
- 関連既存機能: `/housing/contracts`（契約のみの横断一覧、別画面として併存）

## 1. 目的・背景

住宅事業（建売物件 HsProperty + 注文住宅 HsCustomOrder）を 1 画面で経営層が俯瞰できる「**住宅事業ダッシュボード**」を新設する。

ユーザーレビューを経て、ダッシュボードは「**成約フォーカス**」（成約・引渡し済みの実績把握）に特化することが決定した。

- 既存の `/housing/contracts` は契約済みのみを扱う **明細一覧**。本タスクは「ダッシュボード視点の集約画面」として、KPI と月次推移グラフ、成約一覧をセット表示する。
- 優先度4「STEP 12 ダッシュボード」とは役割が異なる。あちらは全事業横断（テナント・不動産・住宅）の経営ダッシュボード。こちらは住宅事業限定。

## 2. 主要ユーザー・利用シーン

- **経営層**（executive）: 月次レビュー時に住宅事業の成約状況を俯瞰
- **住宅事業担当者**（housing department）: 自部署の成約実績確認
- 権限: `$isExecutive || $user->belongsToDepartment('housing')`（他コントローラーと同条件）

## 3. URL・ナビゲーション

| 項目 | 内容 |
|------|------|
| URL | `/housing`（housing prefix のルート） |
| ルート名 | `housing.dashboard` |
| サイドバー | 住宅事業グループの**先頭**に「ダッシュボード」を追加（モバイル / デスクトップ 2 箇所） |
| パンくず | 住宅事業 › ダッシュボード |

既存の `/housing/properties` `/housing/custom-orders` `/housing/contracts` `/housing/customers` には影響を与えない。

## 4. 画面構成

```
┌─────────────────────────────────────────────┐
│ 住宅事業ダッシュボード   [2026年度 ▼] [全期 ▼] │  ← ヘッダー + 年度 + 期セレクター
├─────────────────────────────────────────────┤
│ [成約件数][売上合計][原価合計][粗利合計+率]   │  ← KPI カード4枚（成約のみ集計）
├─────────────────────────────────────────────┤
│ 成約一覧（8列テーブル、20件/ページ）         │  ← 成約済みのみ表示
├─────────────────────────────────────────────┤
│ 月次成約件数（年度内）緑色単一系列棒グラフ    │  ← Chart.js
└─────────────────────────────────────────────┘
```

すべてのセクションは **選択中の年度フィルターと期フィルターを共有** する。

### 4.1 年度・期フィルター

| フィルター | パラメータ | 値 | デフォルト |
|-----------|-----------|---|----------|
| 年度 | `fiscal_year` | "2024".."現在年度+1" / "all"（全期間） | 現在年度 |
| 期 | `period` | "all"（全期）/ "first"（上期）/ "second"（下期） | "all" |

期の定義:
- 上期 (`first`): 5月1日 〜 10月31日
- 下期 (`second`): 11月1日 〜 翌年4月30日

「全期間」（`fiscal_year=all`）選択時、期セレクターはクライアント側で disabled 風に表示するが、サーバー側は値を無視する（データ範囲に意味がないため）。

## 5. データ・DTO 設計

### 5.1 統合 DTO（PHP 連想配列）

成約一覧テーブルには **status_group が `sold` の DTO のみ** を渡す（KPI も同じ DTO 集合で集計する）。

```php
[
    'type'              => 'building' | 'custom-order',
    'id'                => int,
    'code'              => string,   // property_code | order_code
    'name'              => string,   // property_name | order_name
    'address'           => ?string,
    'status_label'      => string,   // '成約' (建売) / '引渡し済み' (注文)
    'status_style'      => string,   // バッジ inline style（ただし成約一覧では非表示）
    'staff_name'        => ?string,  // 姓のみ（既存規約）
    'staff_id'          => ?int,
    'contracted_date'   => ?Carbon,  // 成約日（建売: HsContract.contract_date / 注文: delivery_date）
    'selling_price'     => ?int,
    'total_cost'        => ?int,
    'gross_profit'      => ?int,
    'gross_profit_rate' => ?float,
    'detail_url'        => string,   // 建売: housing.properties.show / 注文: housing.custom-orders.show
]
```

### 5.2 成約判定ロジック

DTO 生成時点で **成約済みのみ** をフィルターする。

| 種別 | 成約条件 | 成約日 |
|------|---------|-------|
| 建売 (HsProperty) | `isSold() === true`（`HsContract` が紐づいている） | `HsContract.contract_date` |
| 注文 (HsCustomOrder) | `status === Delivered`（引渡し済み） | `delivery_date` |

成約日が null のレコードは集計対象外とする（理論上発生しないが防御的に除外）。

### 5.3 年度・期フィルター適用

`contracted_date` が以下の範囲内にあるレコードのみ採用:

```php
// 年度範囲
if ($fiscalYear !== 'all') {
    $fy = (int) $fiscalYear;
    if ($period === 'first') {
        // 上期: 5月1日〜10月31日
        [$start, $end] = [Carbon::create($fy, 5, 1)->startOfDay(), Carbon::create($fy, 10, 31)->endOfDay()];
    } elseif ($period === 'second') {
        // 下期: 11月1日〜翌年4月30日
        [$start, $end] = [Carbon::create($fy, 11, 1)->startOfDay(), Carbon::create($fy + 1, 4, 30)->endOfDay()];
    } else {
        // 全期: 5月1日〜翌年4月30日
        [$start, $end] = [Carbon::create($fy, 5, 1)->startOfDay(), Carbon::create($fy + 1, 4, 30)->endOfDay()];
    }
    // contracted_date が [start, end] の範囲内のみ採用
}
// fiscal_year === 'all' の場合は期フィルター無視、全件採用
```

### 5.4 並び替え

`contracted_date` 降順（新しい成約が上）。

### 5.5 月次集計（グラフ用）

選択中の年度・期フィルター適用後の DTO 集合から、`contracted_date` の月ごとに件数集計（建売 + 注文を合算した単一系列）。

- 全期: 5月〜翌4月（12ヶ月）
- 上期: 5月〜10月（6ヶ月）
- 下期: 11月〜翌4月（6ヶ月）

`fiscal_year=all` の場合、グラフは非表示。

### 5.6 金額の null 扱い

- 注文住宅で `getTotalSellingPrice()` が null → `selling_price = null`
- KPI 集計時は null を除外して合計
- テーブルは `—` 表示

## 6. 画面 UI 詳細

### 6.1 ページヘッダー

- タイトル: 「住宅事業ダッシュボード」（`text-lg font-bold`）
- 年度セレクター: 過去2年〜来年度 + 「全期間」、`<select onchange="form.submit()">`
- 期セレクター: 全期 / 上期 / 下期、`<select onchange="form.submit()">`
- 2 つの select を `display: flex; gap: 8px;` ラッパーで横並び

### 6.2 KPI カード（4枚・横並び・成約のみ集計）

| カード | 1段目（ラベル） | 2段目（値） | 3段目（サブ） |
|--------|---------------|-------------|--------------|
| 成約件数 | 成約件数 | 〇件 | 建売〇 / 注文〇 |
| 売上合計 | 売上合計 | ○○○,○○○,○○○円 | （null除外） |
| 原価合計 | 原価合計 | ○○○,○○○,○○○円 | — |
| 粗利合計 | 粗利合計 | ○○○,○○○,○○○円 | 粗利率 〇〇.〇% |

- レイアウト: `grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3`
- カード: `bg-white border border-gray-200 rounded-lg px-4 py-3`
- 粗利合計: 黒（プラス） / 赤 `#dc2626`（マイナス）

### 6.3 成約一覧テーブル

8列構成（20件/ページのページング）:

| 列 | 内容 |
|----|------|
| 種別 | バッジ（建売: 緑系 `background:#d1fae5;color:#065f46;` / 注文: 青系 `background:#dbeafe;color:#1e40af;`）。中央揃え |
| 案件名 | 1段目: `name`（太字）／ 2段目: `address`（小さめグレー）。左揃え |
| 担当者 | 姓のみ。中央揃え |
| 成約日 | YYYY-MM-DD（`contracted_date`）。中央揃え |
| 売上 | `number_format($selling_price)円`、null は `—`。右揃え |
| 原価 | `number_format($total_cost)円`、null は `—`。右揃え |
| 粗利 | 値+`円`、プラス緑 `#047857` 太字 / マイナス赤 `#dc2626`。右揃え |
| 詳細 | 詳細ボタン。中央揃え |

詳細リンク:
- 建売: `route('housing.properties.show', $id)`
- 注文: `route('housing.custom-orders.show', $id)`

ページング: 20件/ページ（既存規約）。フィルターバーは設けない（年度・期はヘッダーで一括制御）。
0件時の空状態: `colspan="8"` で「該当する成約がありません」。

### 6.4 月次成約件数 棒グラフ

- ライブラリ: Chart.js v4 CDN（`cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js`）
- 種類: 単一系列の棒グラフ（建売成約 + 注文引渡し合算）
- 色: `#047857`（粗利と同色の濃い緑）
- 凡例: 非表示（`legend: { display: false }`）
- データ: 横軸 = 期に応じた月数、縦軸 = 件数
- `fiscal_year=all` の場合グラフ全体を非表示

Controller から渡すデータ形式:

```php
$monthly = [
    'labels' => ['5月','6月','7月','8月','9月','10月','11月','12月','1月','2月','3月','4月'],  // 全期: 12要素 / 上期: 6要素 / 下期: 6要素
    'data'   => [4,7,6,5,6,4,3,5,4,6,5,4],  // 件数（要素数 = labels と同じ）
];
```

Blade 側で `@json($monthly)` で JS に渡し、`new Chart(...)` を生成。Controller で配列を組み立てるため `@json()` 内で関数を呼ばない（CLAUDE.md 規則準拠）。

## 7. ファイル構成

### 7.1 新規ファイル

| パス | 役割 | 想定行数 |
|------|------|----------|
| `app/Http/Controllers/Housing/HousingDashboardController.php` | 本機能コントローラ | ~200 |
| `resources/views/housing/dashboard.blade.php` | ダッシュボード本体 | ~150 |
| `resources/views/housing/_dashboard_kpi.blade.php` | KPI カード partial | ~50 |
| `resources/views/housing/_dashboard_contracted.blade.php` | 成約一覧 partial | ~120 |
| `resources/views/housing/_dashboard_chart.blade.php` | グラフ partial | ~80 |
| `docs/mockups/housing/dashboard.html` | 先行モック（既に作成・承認済み） | 約170行 |

### 7.2 既存ファイル変更

| パス | 修正内容 |
|------|---------|
| `routes/web.php` | `Route::get('/', [HousingDashboardController::class, 'index'])->name('housing.dashboard');` を housing 認証グループの先頭に追加 |
| `resources/views/layouts/partials/sidebar.blade.php` | 住宅事業グループの先頭に「ダッシュボード」を追加（モバイル / デスクトップ 2 箇所） |

### 7.3 既存ファイルへの影響

`HsContractListController` `PropertyController` `CustomOrderController` `HsContract` `HsProperty` `HsCustomOrder` のいずれも変更しない。`HsProperty::isSold()` `getTotalCost()` 等の既存メソッドのみ呼び出す。

## 8. HousingDashboardController 構造

```php
class HousingDashboardController extends Controller
{
    public function index(Request $request)
    {
        $fiscalYear = $request->input('fiscal_year', (string) $this->getCurrentFiscalYear());
        $period = $request->input('period', 'all');

        // 1. 成約済み DTO のみ収集（年度・期フィルター適用済み）
        $items = $this->collectContractedItems($fiscalYear, $period);

        // 2. KPI 集計
        $kpi = $this->buildKpi($items);

        // 3. 月次グラフデータ（fiscal_year=all 時は null）
        $monthly = $fiscalYear === 'all' ? null : $this->buildMonthly($items, (int)$fiscalYear, $period);

        // 4. 成約一覧（20件ページング）
        $paginated = $this->paginate($items, 20, $request);

        // 5. 年度オプションリスト
        $fiscalYearOptions = $this->buildFiscalYearOptions();

        return view('housing.dashboard', compact(
            'fiscalYear', 'period', 'fiscalYearOptions', 'kpi', 'monthly', 'paginated', 'request'
        ));
    }

    protected function collectContractedItems(string $fiscalYear, string $period): \Illuminate\Support\Collection;
    protected function mapPropertyToDto(HsProperty $p): array;
    protected function mapOrderToDto(HsCustomOrder $o): array;
    protected function buildKpi(\Illuminate\Support\Collection $items): array;
    protected function buildMonthly(\Illuminate\Support\Collection $items, int $fy, string $period): array;
    protected function paginate(\Illuminate\Support\Collection $items, int $perPage, Request $request): \Illuminate\Pagination\LengthAwarePaginator;
    protected function buildFiscalYearOptions(): array;
    protected function getCurrentFiscalYear(): int;
    protected function periodRange(int $fy, string $period): array;  // [Carbon $start, Carbon $end]
    protected function lastNameOnly(?string $fullName): ?string;
}
```

設計判断:

- **HsContractListController と独立**: 役割が違う（あちらは契約のみの一覧、こちらは成約フォーカスのダッシュボード）
- **DTO は配列**: 専用クラスは作らない。Blade で `$item['code']` で参照、変換コストゼロ
- **成約フィルターは DTO 生成時点で適用**: 後段の集計・テーブル・グラフはすべて成約済みのみを扱う
- **applyTableFilters や buildMatrix は不要**: フィルターはヘッダーの年度・期のみ、マトリクスは無し

## 9. N+1 対策

```php
HsProperty::with(['createdBy', 'contract', 'projectLot', 'procurement'])->get();
HsCustomOrder::with(['createdBy'])->get();
```

- `HsProperty::isSold()` / `getSellingPriceTotal()` が `contract`（HasOne）・`projectLot`（BelongsTo）・`procurement`（BelongsTo）を辿るため eager load 必須
- 原価関連は両モデルとも直接カラム（`land_cost`, `building_cost` 等）のため `costs` リレーションは存在せず eager load 不要
- `HsCustomOrder` の金額計算は Model 側のヘルパー（`getTotalSellingPrice` / `getTotalCost` / `getTotalProfit` / `getTotalProfitRate`）を利用

## 10. 段取り（フェーズ）

| Phase | 作業 | 完了基準 |
|-------|------|---------|
| 1 | モック作成 `docs/mockups/housing/dashboard.html` | 全パーツ静的に見た目確認可能（**完了済み**） |
| 1.5 | **ユーザーモックレビュー** | 承認（**完了済み — 成約フォーカス確定**） |
| 2 | Controller 骨格 + ルート + サイドバー | DTO 生成・KPI 集計まで動作 |
| 3 | KPI + 成約一覧 ビュー | テーブル・KPI 完成 |
| 4 | Chart.js 連携 | 月次グラフ動作 |
| 5 | 30点品質監査 + 動作確認チェックリスト | チェックリスト消し込み |
| 6 | コミット・PR | feature ブランチでまとめる |

## 11. テスト戦略

PHPUnit 整備が限定的なため、**手動動作確認チェックリスト**で代替（Phase 5 で実施）。

- [ ] 年度切り替えで KPI・成約一覧・グラフが連動更新
- [ ] 期切り替え（全期/上期/下期）で表示データ範囲が変わる
- [ ] 「全期間」選択時にグラフ非表示
- [ ] ページング動作（20件/ページ）
- [ ] 0件時の空状態表示
- [ ] 詳細リンク: 建売→ properties.show、注文→ custom-orders.show 遷移
- [ ] 権限なしユーザーで 403
- [ ] CSS: Vite ビルド未収録クラスの混入なし（`docs/RULES.md` 参照）
- [ ] Alpine.js: 矢印関数・x-data 内 `>` 混入なし
- [ ] Blade: `@else<` 等 compile エラー要因なし
- [ ] N+1 クエリなし（Laravel Debugbar で確認）

## 12. リスク・既知の限界

| リスク | 内容 | 対策 |
|-------|------|-----|
| パフォーマンス | コレクションマージ → 自前ページング方式は数千件超で遅延 | 当面、住宅事業の成約件数は年数件〜数十件規模。データ増加時に UNION クエリへ移行 |
| `isSold()` 重複呼び出し | 物件ごとに contract を走査 | `with('contract')` で eager load 済み |
| 注文住宅の `delivery_date` null | 引渡し前は null | 成約フィルターで自動的に除外される |
| 期跨ぎ集計 | 下期は年をまたぐ（11月-翌4月） | `periodRange()` で適切に Carbon 範囲を生成 |

## 13. 受け入れ条件

- 経営層 1 名・住宅事業担当者 1 名でテスト動作させ、本仕様書のすべての機能が画面上で動作する
- 30点品質監査チェックリスト全項目クリア
- 既存機能（`/housing/properties` `/housing/custom-orders` `/housing/contracts` `/housing/customers`）に regression なし
