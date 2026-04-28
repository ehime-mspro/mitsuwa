# STEP 12 ダッシュボード（経営層・テナント）設計書

**作成日**: 2026-04-28
**対象**: BACKLOG 優先度 4「STEP 12 ダッシュボード」
**スコープ**: 経営ダッシュボード（経営層のみ）+ テナントダッシュボード（全ロール）を 1 つの spec / 1 PR で実装

---

## 1. 目的・背景

`/dashboard/executive` と `/dashboard/tenant` のルート・コントローラ雛形は既に存在するが、view はプレースホルダー（「STEP 10 で実装予定です」）のみ。今回の実装で 2 つのダッシュボードを完成させる。

- **経営ダッシュボード**: 経営層が全事業横断の収支・進捗を一画面で把握し、意思決定を支援する
- **テナントダッシュボード**: 全ロールが「今すぐ動く必要がある運用情報（空室・契約満了・問合せ・修繕投資）」を集約把握する

優先度 3 で完了した Housing 横断ダッシュボード（PR #5、`/housing` ルート）と同じ partial 分割パターンを踏襲し、コードベースの一貫性を保つ。

## 2. 主要ユーザー・利用シーン

| ダッシュボード | 主要ユーザー | 利用シーン |
|--------------|------------|-----------|
| 経営ダッシュボード | 経営層（`role:executive`） | 月次・四半期の経営会議、年度予算策定、事業ミックス判断 |
| テナントダッシュボード | 全ロール（auth 認証のみ） | 日次の運用確認、空室埋め優先度判断、契約更新スケジュール把握 |

## 3. URL・ナビゲーション

```
GET /dashboard            → 既存クロージャ（routes/web.php 内）
                            isExecutive() が true なら /dashboard/executive へ、それ以外は /dashboard/tenant へリダイレクト
GET /dashboard/executive  → DashboardController::executive(Request $request)  [middleware: role:executive]
GET /dashboard/tenant     → DashboardController::tenant(Request $request)     [middleware: auth のみ]
```

ルート定義は既存 `routes/web.php` のまま追加・変更なし。`/dashboard` のロール振り分けは既存クロージャが機能するため、Controller 側にメソッド追加は不要。

サイドバー（`resources/views/layouts/partials/sidebar.blade.php`）:
- 経営層: 「経営ダッシュボード」「テナントダッシュボード」両方を表示
- 一般: 「テナントダッシュボード」のみ表示
- 賃貸マンション・住宅事業の各専用ダッシュボードへのリンクは各モジュール内サイドバーに既設のため、変更なし

## 4. 画面構成

### 4.1 経営ダッシュボード（`/dashboard/executive`）

```
┌────────────────────────────────────────────────────┐
│ 経営ダッシュボード                                    │
└────────────────────────────────────────────────────┘
[ _executive_filter ] 年度 ▼ + 期 ▼

━━━ テナント事業 ━━━ [ _executive_tenant ]
┌──────────────────┬──────────────────┐
│ テナントビル          │ 賃貸マンション         │
│  入居率 ・ 月次収入 ・ │  入居率 ・ 月次収入 ・ │
│  空室数（YoY バッジ）│  空室数（YoY バッジ）│
│  [→ tenant 一覧へ]  │  [→ /mansion へ]     │
└──────────────────┴──────────────────┘

━━━ 不動産事業 ━━━ [ _executive_realestate ]
┌──────────────────┬──────────────────┐
│ 契約                │ 仕入れパイプライン       │
│  契約件数・粗利合計  │  進行中件数・予定金額  │
│  （YoY バッジ）      │  （YoY バッジ）        │
│  [→ /realestate]   │  [→ /procurements]   │
└──────────────────┴──────────────────┘

━━━ 住宅事業 ━━━ [ _executive_housing ]
┌────────────────────────────────────┐
│ 成約件数（合算）・粗利合計（合算）       │
│ 内訳バー: 件数・粗利の建売 vs 注文       │
│ [→ /housing]                         │
└────────────────────────────────────┘

━━━ 月次推移 ━━━ [ _executive_trends ]
グラフ A「月次収入推移」: テナントビル + 賃貸マンション 2系列
グラフ B「月次粗利推移」: 不動産 + 住宅事業 2系列
```

### 4.2 テナントダッシュボード（`/dashboard/tenant`）

```
┌────────────────────────────────────────────────────┐
│ テナントダッシュボード                                  │
└────────────────────────────────────────────────────┘

━━━ KPI 4枚 ━━━ [ _tenant_kpi ]
┌──────┬──────┬──────┬──────┐
│ 空室   │ 満了間近 │ 未対応  │ 進行中  │
│      │ 60日以内│ 問合せ │修繕投資│
└──────┴──────┴──────┴──────┘

━━━ 2×2 リストグリッド ━━━
┌─────────────────┬─────────────────┐
│ 空室一覧 (10件)     │ 契約満了間近 (10件)   │
│[ _tenant_vacancies] │[ _tenant_expiring ]  │
├─────────────────┼─────────────────┤
│ 未対応問合せ (10件)  │ 修繕・投資 (10件)     │
│[ _tenant_inquiries] │[ _tenant_works ]     │
└─────────────────┴─────────────────┘
```

## 5. データ集計式

### 5.1 経営ダッシュボード

#### テナント事業（テナントビル + 賃貸マンション 並列）

| カード | KPI | 集計式（概念） |
|------|------|----------------|
| テナントビル | 入居率 | `Unit(status='occupied').count() / Unit.all().count() × 100` |
| | 月次収入 | `Contract(status='active', start_date <= 当期月初, end_date IS NULL OR > 当期月初).sum(monthly_fee)` |
| | 空室数 | `Unit(status IN ['vacant','negotiating']).count()` |
| 賃貸マンション | 入居率 | `(MsRoom + MsParking)(status='occupied').count() / total × 100`（既存 `Mansion/DashboardController` から流用） |
| | 月次収入 | `MsContract(active).sum(monthly_rent + common_fee) + MsParkingContract(active).sum(monthly_fee)` |
| | 空室数 | `MsRoom(status='vacant').count() + MsParking(status='vacant').count()` |

#### 不動産事業（契約 + 仕入れパイプライン 2 カード）

| カード | KPI | 集計式 |
|------|------|--------|
| 契約 | 年度別契約件数 | `ReContract(契約日 ∈ 選択期間, status != 'lost').count()` |
| | 年度別粗利合計 | `ReContract(契約日 ∈ 選択期間).sum(gross_profit)` |
| 仕入れパイプライン | 進行中件数 | `ReProcurement(status NOT IN 終了系).count()` |
| | 仕入れ予定金額合計 | `ReProcurement(進行中).sum(予定金額カラム)` |

実装計画フェーズで `ReContract.gross_profit` の存在確認、`ReProcurement` の金額カラム名・終了系ステータスの確認を行い、実クエリを確定する。

#### 住宅事業（合算 + 内訳バー）

| KPI | 集計式 |
|------|--------|
| 年度別成約件数（合算） | `HsContract(契約日 ∈ 選択期間).count() + HsCustomOrder(delivered_date ∈ 選択期間, status='delivered').count()` |
| 年度別粗利合計（合算） | `HsContract.sum(gross_profit) + HsCustomOrder.sum(getTotalProfit())` |
| 内訳バー | 建売件数:注文件数 と 建売粗利:注文粗利 のスタック横棒（2 本） |

`HsCustomOrder::getTotalProfit()` は既存メソッドを使用。Housing dashboard との集計式整合は実装計画フェーズで verify。

### 5.2 月次推移グラフ

| 系列 | 性質 | 月次集計式 |
|------|------|-----------|
| テナントビル月次収入 | ストック | 各月 1 日時点で `start_date <= 月初 AND (end_date IS NULL OR > 月初)` の Active 契約 sum |
| 賃貸マンション月次収入 | ストック | 各月 1 日時点の Active な MsContract + MsParkingContract sum |
| 不動産月次粗利 | フロー | 契約日が当月内の ReContract.gross_profit sum |
| 住宅事業月次粗利 | フロー | 契約日（建売）/ 納品日（注文）が当月内の粗利合算 |

**グラフ分割の理由**: 収入はストック量、粗利はフロー量で性質が異なるため、Y 軸の意味を統一するため 2 つに分割（収入推移グラフ / 粗利推移グラフ）。

### 5.3 YoY バッジ

| 項目 | 仕様 |
|------|------|
| 当期値 | 選択 `fy + period` の集計 |
| 前期値 | 1 年前の同 `fy-1 + period` の集計 |
| 増減率 | `(当期 - 前期) / 前期 × 100`、小数 1 桁、絶対値 |
| バッジ表示 | 増益: ▲ 緑（`#047857`）、減益: ▼ 赤（`#dc2626`） |
| バッジ非表示 | 前期値 = 0 / null、または「全期間」選択時 |

### 5.4 テナントダッシュボード集計

#### KPI カード × 4

| KPI | 集計式 |
|------|--------|
| 空室数 | `Unit(status IN ['vacant','negotiating']).count()` |
| 契約満了間近 | `Contract(status='active', end_date BETWEEN today AND today+60days).count()` |
| 未対応問合せ | `Inquiry(status IN ['follow','on_hold','unreachable']).count()` |
| 進行中修繕投資 | `Repair(status IN ['planned','in_progress']).count() + Investment(status IN ['planning','in_progress','recovering']).count()` |

#### リストブロック × 4（各 10 件抜粋）

| ブロック | クエリ | カラム | リンク先 |
|------|------|------|--------|
| 空室一覧 | `Unit + Property`、新着順 | 物件名 / 区画 / ステータス | `tenant.units.show` |
| 契約満了間近 | `Contract + Unit`、終了日昇順 | 物件 / 区画 / 入居者 / 終了日 | `tenant.contracts.show` |
| 未対応問合せ | `Inquiry + Customer`、最終接触日昇順（古い順） | 顧客 / 物件 / 最終接触 / ステータス | `tenant.inquiries.show` |
| 修繕・投資 | `Repair / Investment` 混合、最新順 | 種別 / 対象 / ステータス / 期日 | 各 show |

## 6. 期間フィルタ仕様

### 6.1 年度セレクター

- 選択肢: 過去 2 年（FY-2024, FY-2025）/ 当年度（FY-2026）/ 来年度+1（FY-2027, FY-2028）/ 全期間
- デフォルト: 当年度
- URL パラメータ: `?fy=2026`（西暦 4 桁）
- セッション保存なし、`onchange="document.getElementById('filter-form').submit()"` で即時反映

### 6.2 期セレクター

- 選択肢: 全期 / 上期（5〜10 月）/ 下期（11〜4 月）
- デフォルト: 全期
- URL パラメータ: `?period=full|h1|h2`

### 6.3 「全期間」選択時の動作

- KPI: 累計値
- YoY バッジ: 全件非表示
- 月次推移グラフ: 過去 24 ヶ月固定で表示

### 6.4 グラフの期間軸

- 「FY 全期」選択時: 12 ヶ月
- 「上期」「下期」選択時: 6 ヶ月
- 「全期間」選択時: 過去 24 ヶ月固定

## 7. ファイル構成

### 7.1 新規ファイル（13 個）

```
resources/views/dashboard/_executive_filter.blade.php
resources/views/dashboard/_executive_tenant.blade.php
resources/views/dashboard/_executive_realestate.blade.php
resources/views/dashboard/_executive_housing.blade.php
resources/views/dashboard/_executive_trends.blade.php
resources/views/dashboard/_tenant_kpi.blade.php
resources/views/dashboard/_tenant_vacancies.blade.php
resources/views/dashboard/_tenant_expiring.blade.php
resources/views/dashboard/_tenant_inquiries.blade.php
resources/views/dashboard/_tenant_works.blade.php
docs/mockups/dashboard/executive.html
docs/mockups/dashboard/tenant.html
tests/Feature/DashboardControllerTest.php
```

### 7.2 既存ファイル変更（4 個）

| ファイル | 変更種別 | 内容 |
|---------|--------|------|
| `app/Http/Controllers/DashboardController.php` | 全面書き換え | プレースホルダー → 集計ロジック実装 |
| `resources/views/dashboard/executive.blade.php` | 全面書き換え | プレースホルダー → partial 組み立て |
| `resources/views/dashboard/tenant.blade.php` | 全面書き換え | プレースホルダー → partial 組み立て |
| `resources/views/layouts/partials/sidebar.blade.php` | 追記 | ダッシュボードリンク（ロール条件分岐） |

### 7.3 変更なし

- `routes/web.php`: 既存ルート（`/dashboard/executive`, `/dashboard/tenant`, `/dashboard`）をそのまま流用
- DB スキーマ: 新規テーブル・マイグレーションなし
- Enum: 追加なし
- Model: 変更なし

## 8. DashboardController 構造

`/dashboard` のロール振り分けは既存クロージャ（`routes/web.php` 内）で機能するため、Controller 側は `executive()` / `tenant()` の 2 メソッドのみ。

```php
class DashboardController extends Controller
{
    public function executive(Request $request)
    {
        $fy = $this->resolveFiscalYear($request);
        $period = $this->resolvePeriod($request);

        return view('dashboard.executive', [
            'fy' => $fy,
            'period' => $period,
            'fiscalYears' => $this->fiscalYearOptions(),
            'tenantStats' => $this->aggregateTenantStats($fy, $period),
            'mansionStats' => $this->aggregateMansionStats($fy, $period),
            'realestateContractStats' => $this->aggregateRealEstateContractStats($fy, $period),
            'realestateProcurementStats' => $this->aggregateProcurementStats(),
            'housingStats' => $this->aggregateHousingStats($fy, $period),
            'trendIncome' => $this->aggregateMonthlyIncomeTrend($fy, $period),
            'trendProfit' => $this->aggregateMonthlyProfitTrend($fy, $period),
        ]);
    }

    public function tenant(Request $request)
    {
        return view('dashboard.tenant', [
            'kpi' => $this->aggregateTenantKpi(),
            'vacancies' => $this->fetchVacanciesPreview(10),
            'expiringContracts' => $this->fetchExpiringContractsPreview(10),
            'pendingInquiries' => $this->fetchPendingInquiriesPreview(10),
            'activeWorks' => $this->fetchActiveWorksPreview(10),
        ]);
    }

    // private 集計メソッド × 約 15 個（aggregateXxx / fetchXxx / resolveXxx / fiscalYearOptions）
}
```

`role:executive` ミドルウェアはルート定義側で適用済みのため、Controller 内での再チェック不要。

## 9. テスト戦略

`tests/Feature/DashboardControllerTest.php` に以下を追加:

```
test_executive_requires_executive_role         # 一般ユーザー 403
test_tenant_accessible_by_all_authenticated_users
test_executive_filters_by_fiscal_year          # ?fy=2025 で集計範囲が変わる
test_executive_handles_all_period_param        # 全期間時のグラフ・YoY 動作
test_executive_calculates_yoy_correctly        # 増減率計算検証
test_tenant_kpi_counts_match_underlying_data   # シードと KPI カウント一致
test_tenant_lists_show_threshold_filtered_items # 60日閾値・status filter 動作
```

CI: 既存の PHP 8.3 / 8.4 / 8.5 マトリクスで自動実行。

## 10. エラー処理 / エッジケース

| ケース | 動作 |
|------|------|
| データ 0 件 | KPI: `0`、グラフ: 空白、YoY バッジ非表示 |
| 前期データなし | YoY バッジ「ー」表示 |
| 「全期間」選択 | YoY 全件非表示、グラフは過去 24 ヶ月固定 |
| Chart.js 読み込み失敗 | KPI 部分は表示継続、グラフは「読み込みエラー」テキスト |
| 不正な fy/period パラメータ | バリデーションで弾き、デフォルト（当年度・全期）にフォールバック |
| 削除済み Property/Unit/Contract | SoftDeletes 未使用テーブルが多いため、Eloquent デフォルト動作で自然除外 |
| ロール権限なしで `/dashboard/executive` 直叩き | `role:executive` middleware で 403 |

## 11. 段取り（フェーズ）

```
Phase 1: モック作成（HTML 2 ファイル）
  1-1. docs/mockups/dashboard/executive.html
  1-2. docs/mockups/dashboard/tenant.html
  ↓ ユーザー承認
Phase 2: モック調整（必要に応じて）
  ↓ ユーザー承認
Phase 3: Laravel 実装
  3-1. DashboardController 集計メソッド実装
  3-2. partial × 10 ファイル作成
  3-3. executive.blade.php / tenant.blade.php 組み立て
  3-4. サイドバー修正
  3-5. Feature テスト追加
Phase 4: 30 点品質監査
  4-1. CSS / Alpine / Blade 規約確認
  4-2. 機能網羅・ルート/サイドバー
  4-3. レスポンシブ・既存ダッシュボードとの一貫性
Phase 5: PR 作成（base: 13.x）
```

## 12. リスク・既知の限界

| リスク | 影響 | 緩和策 |
|------|------|------|
| `ReContract.gross_profit` が未実装の場合 | 不動産粗利 KPI が出せない | 実装計画フェーズで grep 確認、不在なら計算ロジック（契約金額 - 原価）で代替 |
| `ReProcurement` の予定金額カラム不在 | 仕入れ予定金額カードが空 | 同上、不在なら件数のみ表示に縮小 |
| 月次集計クエリの N+1 | 経営DB の応答が遅い | DB 集計関数（SUM/COUNT）で 1 クエリ化、`whereBetween` で月別分割 |
| 「全期間」グラフの 24 ヶ月固定が短すぎる/長すぎる | 経営層の不満 | 運用後に閾値調整可能（定数 1 行修正） |
| 並列カード（テナントビル/賃貸マンション）の集計式不揃い | 数値の意味が誤解される | カードヘッダーに「区画ベース」「部屋+駐車場ベース」と注記 |

## 13. 受け入れ条件

- [ ] `/dashboard/executive` へ executive ロールでアクセス可、他ロールは 403
- [ ] `/dashboard/tenant` へ全認証ユーザーがアクセス可
- [ ] 経営DB で年度・期セレクター変更時、URL パラメータが更新され KPI / グラフが再描画される
- [ ] YoY バッジが増益（▲緑）/ 減益（▼赤）で正しく表示される
- [ ] 「全期間」選択時、YoY が全件非表示・グラフが過去 24 ヶ月になる
- [ ] テナントDB の 4 KPI カードと 4 リストブロックが正しい件数を表示する
- [ ] テナントDB の各リスト行クリックで該当 show ページへ遷移する
- [ ] サイドバーが経営層 / 一般で出し分けされる
- [ ] CI（PHP 8.3 / 8.4 / 8.5）で全テスト通過
- [ ] 30 点品質監査で Critical / Important ゼロ
