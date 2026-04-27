# 住宅事業 横断ダッシュボード（BACKLOG 優先度3）設計書

- 起票日: 2026-04-27
- 対応 BACKLOG: 優先度3「住宅事業 横断一覧」
- 関連既存機能: `/housing/contracts`（契約のみの横断一覧、別画面として併存）

## 1. 目的・背景

住宅事業（建売物件 HsProperty + 注文住宅 HsCustomOrder）を 1 画面で経営層が俯瞰できる「**住宅事業ダッシュボード**」を新設する。

- 既存の `/housing/contracts` は **契約済みのみ**を扱う。本タスクは **契約前段階を含む全件**を対象にし、経営サマリー視点で件数・金額・粗利を一望する。
- 優先度4「STEP 12 ダッシュボード」とは役割が異なる。あちらは全事業横断（テナント・不動産・住宅）の経営ダッシュボード。こちらは住宅事業限定。

## 2. 主要ユーザー・利用シーン

- **経営層**（executive）: 月次レビュー時に住宅事業の全体把握
- **住宅事業担当者**（housing department）: 自部署案件の進捗確認
- 権限: `$isExecutive || $user->belongsToDepartment('housing')`（他コントローラーと同条件）

## 3. URL・ナビゲーション

| 項目 | 内容 |
|------|------|
| URL | `/housing`（housing prefix のルート） |
| ルート名 | `housing.dashboard` |
| サイドバー | 住宅事業グループの**先頭**に「ダッシュボード」を追加（98行目周辺・326行目周辺の2箇所） |
| パンくず | 住宅事業 › ダッシュボード |

既存の `/housing/properties` `/housing/custom-orders` `/housing/contracts` `/housing/customers` には影響を与えない。

## 4. 画面構成

ページは上から下に以下のセクションで構成する。

```
┌─────────────────────────────────────────────┐
│ 住宅事業ダッシュボード        [2026年度 ▼]   │  ← ヘッダー + 年度セレクター
├─────────────────────────────────────────────┤
│ [件数][売上見込][原価][粗利+率]              │  ← KPI カード 4枚
├─────────────────────────────────────────────┤
│ ステータスマトリクス（5×3）                  │  ← 大分類グルーピング
├─────────────────────────────────────────────┤
│ 月次成約・引渡し件数 棒グラフ                │  ← Chart.js（年度選択時のみ）
├─────────────────────────────────────────────┤
│ [フィルターバー]                             │  ← 種別 / グループ / 担当者 / キーワード
│ 詳細テーブル（ページング 20件/ページ）       │
└─────────────────────────────────────────────┘
```

すべてのセクションは**選択中の年度フィルターを共有**する。年度は「2024年度」〜「現在年度+1」 + 「全期間」。

## 5. データ・DTO 設計

### 5.1 統合 DTO（PHP 連想配列）

両モデルを共通形に正規化して扱う。

```php
[
    'type'              => 'building' | 'custom-order',
    'id'                => int,
    'code'              => string,   // property_code | order_code
    'name'              => string,   // property_name | order_name
    'address'           => ?string,
    'status_value'      => string,   // 元 Enum 値
    'status_label'      => string,
    'status_group'      => 'consult' | 'design' | 'construction' | 'completed' | 'sold',
    'status_style'      => string,   // バッジ inline style
    'staff_name'        => ?string,  // 姓のみ（既存規約）
    'staff_id'          => ?int,
    'key_date'          => ?Carbon,  // ソート基準
    'selling_price'     => ?int,
    'total_cost'        => ?int,
    'gross_profit'      => ?int,
    'gross_profit_rate' => ?float,
    'detail_url'        => string,
]
```

### 5.2 ステータス → グループ マッピング

| グループ | ラベル | 建売 (HousingPropertyStatus) | 注文 (CustomOrderStatus) |
|----------|--------|------------------------------|--------------------------|
| `consult` | 商談・見積 | Estimation | Consultation, Estimation |
| `design` | 設計中 | Design | Design |
| `construction` | 建設中 | Construction | Construction |
| `completed` | 完成・販売中 | Completed, OnSale (`isSold()=false`) | Contracted, Completed |
| `sold` | 成約・引渡し | OnSale (`isSold()=true`) | Delivered |

注: 建売の "成約済み" は `HsProperty::isSold()`（既存メソッド）で判定。OnSale ステータスのうち成約済みは `sold` グループへ振り分ける。

### 5.3 並び替え基準 `key_date`

- **建売**: `HsContract.contract_date`（成約済み）→ なければ `HsProperty.created_at`
- **注文**: `HsCustomOrder.contract_date` → なければ `HsCustomOrder.created_at`
- ソート方向: 降順（新しい順）

### 5.4 月次集計の対象日

| 種別 | 集計対象日 |
|------|----------|
| 建売 | `HsContract.contract_date`（成約日） |
| 注文 | `HsCustomOrder.delivery_date`（引渡し日） |

該当日が null のレコードは月次集計から除外する。

### 5.5 金額の null 扱い

- 注文住宅で `building_contract_price` が未設定 → `selling_price = null`
- KPI 集計時は null を除外して合計
- テーブルは `—` 表示

## 6. 画面 UI 詳細

### 6.1 ページヘッダー

- タイトル: 「住宅事業ダッシュボード」（`text-lg font-bold`）
- 年度セレクター: `<select onchange="form.submit()">`、過去2年〜来年度 + 「全期間」
- 既存ヘッダー UI（`flex items-center justify-between`）を踏襲

### 6.2 KPI カード（4枚・横並び）

| カード | 1段目（ラベル） | 2段目（値） | 3段目（サブ） |
|--------|---------------|-------------|--------------|
| 案件件数 | 案件件数 | 〇〇件 | 建売〇 注文〇 |
| 売上見込合計 | 売上見込合計 | ○○○,○○○,○○○円 | （null除外） |
| 原価合計 | 原価合計 | ○○○,○○○,○○○円 | — |
| 粗利合計 | 粗利合計 | ○○○,○○○,○○○円 | 粗利率 〇〇.〇% |

- レイアウト: `grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3`
- カード: `bg-white border border-gray-200 rounded-lg px-4 py-3`
- 粗利合計の色: 黒（プラス） / 赤 `#dc2626`（マイナス）

### 6.3 ステータスマトリクス

```
              建売     注文      計
─────────────────────────────────────
商談・見積     2件      5件     7件
設計中        3件      4件     7件
建設中        5件      3件     8件
完成・販売中   8件      3件    11件
成約・引渡し   5件      4件     9件
─────────────────────────────────────
計           23件     19件    42件
```

- 各セル: クリック可能（0件以外）。クリック時 `?status_group=construction&type=building` を付けて同ページに遷移し `#detail-table` へ anchor スクロール
- 0件セル: グレー表示・clickable なし
- 通常 `<table>` で表現。スタイルは既存テーブルに準拠

### 6.4 月次棒グラフ

- ライブラリ: Chart.js v4 CDN（`cdn.jsdelivr.net/npm/chart.js`）
- 種類: スタック棒グラフ
- データ: 横軸 5月〜翌4月（12ヶ月）、縦軸 件数。建売成約件数 + 注文引渡し件数 を積み上げ
- 「全期間」選択時はグラフ全体を非表示

Controller から渡すデータ形式:

```php
$monthly = [
    'labels'   => ['5月','6月','7月','8月','9月','10月','11月','12月','1月','2月','3月','4月'],
    'building' => [3,5,2,...],    // 建売成約件数（12要素）
    'custom'   => [1,2,4,...],    // 注文引渡し件数（12要素）
];
```

Blade 側で `@json($monthly)` で JS に渡し、`new Chart(...)` を生成。Controller で配列を組み立てるため `@json()` 内で関数を呼ばない（CLAUDE.md 規則準拠）。

### 6.5 詳細テーブル

| 列 | 内容 |
|----|------|
| 種別 | バッジ（建売: 紫系 `background:#ede9fe;color:#5b21b6;` / 注文: 青系 `background:#dbeafe;color:#1e40af;`） |
| 番号 | `code`（リンク= 詳細ページ） |
| 案件名 | 1段目: `name`（太字）／ 2段目: `address`（小さめグレー） |
| ステータス | 既存 Enum の `badgeStyle()` 流用 |
| 担当者 | 姓のみ |
| 契約日 | `key_date`（YYYY-MM-DD） |
| 売上 | `number_format($selling_price)円` / null は `—` |
| 原価 | `number_format($total_cost)円` / null は `—` |
| 粗利 | 値+`円`、プラス緑 `#047857` 太字 / マイナス赤 |
| 詳細 | 詳細ボタン（既存 `properties/index.blade.php` のスタイル踏襲） |

- ページング: 20件/ページ（既存規約）
- 0件時の空状態: `colspan="10"` で「該当する案件がありません」

### 6.6 フィルターバー

| フィルター | パラメータ名 | 値 | UI |
|-----------|-------------|---|---|
| 年度 | `fiscal_year` | "2024".."現+1" / "all" | ヘッダー右の `<select>` |
| 種別 | `type` | "building" / "custom-order" / 空 | テーブル直前の `<select>` |
| ステータスグループ | `status_group` | 5値 / 空 | `<select>` |
| 担当者 | `staff_id` | User.id / 空 | `<select>` |
| キーワード | `keyword` | 文字列 | `<input type="text">`（コード・名前・住所 部分一致） |

- すべて `onchange` で即時送信
- クリアボタン: `/housing` に戻る（既存規約に準拠 `h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400`）

## 7. ファイル構成

### 7.1 新規ファイル

| パス | 役割 | 想定行数 |
|------|------|----------|
| `app/Http/Controllers/Housing/HousingDashboardController.php` | 本機能コントローラ | ~250 |
| `resources/views/housing/dashboard.blade.php` | ダッシュボード本体 | ~200 |
| `resources/views/housing/_dashboard_kpi.blade.php` | KPI カード partial | ~50 |
| `resources/views/housing/_dashboard_matrix.blade.php` | マトリクス partial | ~70 |
| `resources/views/housing/_dashboard_chart.blade.php` | グラフ partial | ~80 |
| `resources/views/housing/_dashboard_table.blade.php` | フィルター + テーブル partial | ~150 |
| `docs/mockups/housing/dashboard.html` | 先行モック（HTML 単体） | ~600 |

### 7.2 既存ファイル変更

| パス | 変更内容 |
|------|---------|
| `routes/web.php` | `Route::get('/housing', [HousingDashboardController::class, 'index'])->name('housing.dashboard');` を housing 認証グループの先頭に追加 |
| `resources/views/layouts/partials/sidebar.blade.php` | 住宅事業グループの先頭に「ダッシュボード」を追加（モバイル / デスクトップ 2 箇所） |

### 7.3 既存ファイルへの影響

`HsContractListController` `PropertyController` `CustomOrderController` `HsContract` `HsProperty` `HsCustomOrder` のいずれも変更しない。`HsProperty::isSold()` 等の既存メソッドのみ呼び出す。

## 8. HousingDashboardController 構造

```php
class HousingDashboardController extends Controller
{
    public function index(Request $request)
    {
        $fiscalYear = $request->input('fiscal_year', (string) $this->getCurrentFiscalYear());

        $items = $this->collectItems($fiscalYear);
        $kpi = $this->buildKpi($items);
        $matrix = $this->buildMatrix($items);
        $monthly = $fiscalYear === 'all' ? null : $this->buildMonthly($items, (int)$fiscalYear);
        $tableItems = $this->applyTableFilters($items, $request);
        $paginated = $this->paginate($tableItems, 20, $request);
        $filterOptions = $this->buildFilterOptions();

        return view('housing.dashboard', compact(
            'fiscalYear', 'kpi', 'matrix', 'monthly', 'paginated', 'filterOptions', 'request'
        ));
    }

    protected function collectItems(string $fiscalYear): \Illuminate\Support\Collection;
    protected function mapPropertyToDto(HsProperty $p): array;
    protected function mapOrderToDto(HsCustomOrder $o): array;
    protected function classifyStatusGroup(string $type, string $statusValue, bool $isSold): string;
    protected function buildKpi(\Illuminate\Support\Collection $items): array;
    protected function buildMatrix(\Illuminate\Support\Collection $items): array;
    protected function buildMonthly(\Illuminate\Support\Collection $items, int $fy): array;
    protected function applyTableFilters(\Illuminate\Support\Collection $items, Request $request): \Illuminate\Support\Collection;
    protected function paginate(\Illuminate\Support\Collection $items, int $perPage, Request $request): \Illuminate\Pagination\LengthAwarePaginator;
    protected function buildFilterOptions(): array;
    protected function getCurrentFiscalYear(): int;
}
```

設計判断:

- **HsContractListController と独立**: 役割が違う（あちらは契約のみ、こちらは全件＋ダッシュボード集計）
- **DTO は配列**: 専用クラスは作らない。Blade で `$item['code']` で参照、変換コストゼロ
- **集計ヘルパーは protected メソッド**: 単一目的のため Trait 化はしない

## 9. N+1 対策

```php
HsProperty::with(['createdBy', 'contracts', 'costs'])->get();
HsCustomOrder::with(['createdBy', 'costs'])->get();
```

- `isSold()` が contracts を見るため eager load 必須
- `getTotalCost()` `getSellingPriceTotal()` などが costs を見るため eager load 必須

## 10. 段取り（フェーズ）

| Phase | 作業 | 完了基準 |
|-------|------|---------|
| 1 | モック作成 `docs/mockups/housing/dashboard.html` | 全パーツ静的に見た目確認可能 |
| 1.5 | **ユーザーモックレビュー** | 承認 |
| 2 | Controller 骨格 + ルート + サイドバー | DTO 生成・KPI 集計まで動作（テーブル仮表示） |
| 3 | ビュー本体 + KPI + マトリクス + テーブル | Chart.js 以外動作 |
| 4 | Chart.js 連携 + フィルター | 全機能動作 |
| 5 | 30点品質監査 + 動作確認チェックリスト | チェックリスト消し込み |
| 6 | コミット・PR | feature ブランチでまとめる |

CLAUDE.md「実装する前はデザインモックで確認して進めること」に従い、Phase 1.5 でユーザー承認を必須とする。

## 11. テスト戦略

PHPUnit 整備が限定的なため、**手動動作確認チェックリスト**で代替（Phase 5 で実施）。

- [ ] 年度切り替えで KPI・マトリクス・グラフ・テーブルが連動更新
- [ ] マトリクスのセルクリックで詳細テーブルが絞り込まれ anchor スクロール
- [ ] 種別 / ステータスグループ / 担当者 / キーワード フィルター単独動作
- [ ] 複数フィルター組み合わせ動作
- [ ] クリアボタンで全フィルター解除
- [ ] ページング動作（20件/ページ）
- [ ] 0件時の空状態表示
- [ ] 「全期間」選択時にグラフ非表示
- [ ] 詳細リンク: 建売→ properties.show、注文→ custom-orders.show 遷移
- [ ] 権限なしユーザーで 403
- [ ] CSS: Vite ビルド未収録クラスの混入なし（`docs/RULES.md` 参照）
- [ ] Alpine.js: 矢印関数・x-data 内 `>` 混入なし
- [ ] Blade: `@else<` 等 compile エラー要因なし
- [ ] N+1 クエリなし（Laravel Debugbar で確認）

## 12. リスク・既知の限界

| リスク | 内容 | 対策 |
|-------|------|-----|
| パフォーマンス | コレクションマージ → 自前ページング方式は数千件超で遅延 | 当面 HsProperty + HsCustomOrder の合計が数百件想定。データ増加時に UNION クエリへ移行 |
| `isSold()` 重複呼び出し | 物件ごとに contracts を走査 | `with('contracts')` で eager load 済み |
| 注文住宅の `contract_date` null | 商談・設計段階は null | `key_date` フォールバックで `created_at` を使用 |
| 月次グラフの「引渡し」定義 | `delivery_date` カラムを採用 | 確認済（HsCustomOrder.php 42行目で casts 済み） |

## 13. 受け入れ条件

- 経営層 1 名・住宅事業担当者 1 名でテスト動作させ、本仕様書のすべての機能が画面上で動作する
- 30点品質監査チェックリスト全項目クリア
- 既存機能（`/housing/properties` `/housing/custom-orders` `/housing/contracts` `/housing/customers`）に regression なし
