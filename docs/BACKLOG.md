# 未実装バックログ — 優先順位付き

## ✅ 優先度1: 賃貸マンション管理（実装完了）

詳細仕様: @docs/賃貸マンション管理_要件定義書_v1.md
実装計画: @docs/superpowers/plans/2026-04-20-mansion-management.md

### フェーズ1: モック作成（完了）

モック配置先: `docs/mockups/mansion/`

| モジュール | ディレクトリ | index | show | create | edit | 状態 |
|-----------|-------------|:-----:|:----:|:------:|:----:|------|
| 物件管理 | `properties/` | ✅ | ✅ | ✅ | ✅ | 完了 |
| 部屋マスタ | `rooms/` | — | — | ✅ | ✅ | create/edit のみ（一覧は物件詳細に内蔵） |
| 駐車場マスタ | `parkings/` | — | — | ✅ | — | 物件詳細に内蔵。create のみモック作成済み |
| 入居者管理 | `tenants/` | ✅ | ✅ | ✅ | ✅ | 完了（resident / parking_only 2区分対応） |
| 部屋契約 | `contracts/` | ✅ | ✅ | ✅ | ✅ | 完了 |
| 駐車場契約 | `parking-contracts/` | ✅ | ✅ | ✅ | ✅ | 完了（案C datepicker 適用済み） |

**賃料改定モック**（部屋契約・駐車場契約 共通パターン）:
- `contracts/revise.html` — 賃料＋共益費の改定（差分バッジ・改定理由付き）
- `parking-contracts/revise.html` — 月額料金の改定（差分バッジ・改定理由付き）

**解約処理モック**（部屋契約・駐車場契約 共通パターン）:
- `contracts/terminate.html` — 退去日・敷金精算（差引項目動的追加）・紐付く駐車場一括解約
- `parking-contracts/terminate.html` — 利用終了日・敷金精算・駐車場ステータス連動（使用中→空き）

**ダッシュボード**:
- `dashboard.html` — 「賃貸マンションダッシュボード」完了。部屋KPI5枚 → 物件別稼働状況テーブル → 空室カード + 空き駐車場カード（2カラム）。駐車場稼働情報は空き駐車場ヘッダーにインラインテキスト統合

**入居申込書**:
- `tenants/application.html` — 入居申込書アップロード画面完了。申込者情報 / ドラッグ&ドロップ + ファイル選択 / 推奨書類ヒント / アップロード済みファイル一覧（削除確認 + 削除履歴）

**モックはすべて完了**。次フェーズは Phase 2 Laravel 実装（ms_* テーブル / Enum / Controller / Blade / ルート約30本）。

### フェーズ2: Laravel 実装（完了）

全 9 Phase（A〜I）で実装完了:

| Phase | 内容 | 主な成果物 |
|-------|------|-----------|
| A | 基盤（DB / Enum / Model / サイドバー） | `ms_*` テーブル 8本、Enum 5本、Model 8本、サイドバー 3 パターン追記 |
| B | 物件管理 | `Mansion/PropertyController`、`properties/` Blade 5本 |
| C | 部屋マスタ | `Mansion/RoomController`、`rooms/` Blade 3本 |
| D | 駐車場マスタ | `Mansion/ParkingController`、`parkings/` Blade 3本 |
| E | 入居者管理 | `Mansion/TenantController`、`tenants/` Blade 5本（入居申込書アップロード含む） |
| F | 部屋契約 | `Mansion/ContractController`（賃料改定・解約・Ajax）、`contracts/` Blade 7本 |
| G | 駐車場契約 | `Mansion/ParkingContractController`（料金改定・解約）、`parking-contracts/` Blade 7本 |
| H | ダッシュボード | `Mansion/DashboardController`、`dashboard.blade.php` |
| I | 30 点品質監査 + PR | CLAUDE.md 準拠確認、@json 内関数呼び出し 1 件修正 |

**合計**: Controller 7本 / Blade 約 35 本 / ルート約 43 本 / Model 8本 / Enum 5本

---

## ✅ 優先度2: DAD（土木事業）管理（実装完了）

詳細仕様: @docs/DAD_土木事業_要件定義書_v1.md

### フェーズ1: モック作成（完了）

モック配置先: `docs/mockups/dad/`

| モジュール | ディレクトリ | index | show | create | edit | 状態 |
|-----------|-------------|:-----:|:----:|:------:|:----:|------|
| 工事案件 | `projects/` | ✅ | ✅ | ✅ | ✅ | 完了（原価管理カード + Excel取込 内蔵） |
| 発注者 | `clients/` | ✅ | ✅ | ✅ | ✅ | 完了 |
| 協力業者 | `subcontractors/` | ✅ | ✅ | ✅ | ✅ | 完了 |
| 従業員 | `employees/` | ✅ | ✅ | ✅ | ✅ | 完了 |
| 専門分野マスタ | `specialties/` | ✅ | — | ✅ | ✅ | create/edit + 一覧（show は無し） |

### フェーズ2: Laravel 実装（完了 — 本番稼働中）

| 区分 | 実装内容 |
|------|---------|
| Controllers | `Dad/{Project,Client,Subcontractor,Employee}Controller.php` + `Admin/DadSpecialtyController.php` |
| Models | `DadProject` / `DadProjectCost` / `DadProjectAssignment` / `DadClient` / `DadSubcontractor` / `DadEmployee` / `DadSpecialty` |
| Enums | `DadProjectStatus` / `DadProjectType` / `DadCostCategory` / `DadClientType` / `DadEmployeeStatus` |
| Blade | 23本（4 モジュール × index/show/create/edit/_form + projects の partial 3本: `_excel_import` / `_date-picker` / `_date-picker-row`） |
| ルート | 28本（リソース × 4）+ 7本（admin/dad-specialties） |
| サイドバー | 「DAD」グループに 4 項目登録済み |

**原価管理カード**: クライアント側 SheetJS で Excel 取込、サーバー側は ProjectController 内に preview/execute ロジック内蔵。カテゴリエイリアス自動変換（材料→材料費、外注/下請→外注費 等）、プレビューで カテゴリ不一致・金額NG を警告。

---

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

---

## ✅ 優先度4: ZEAL（フィットネス事業）（実装完了）

詳細仕様: @docs/ZEAL_フィットネス事業_要件定義書_v2.md

### フェーズ1: モック作成（完了）

モック配置先: `docs/mockups/zeal/`

| ファイル | 役割 | 状態 |
|---------|------|------|
| `dashboard.html` | ZEAL ダッシュボード（KPI + 月会費売上 + Chart.js 月次グラフ） | ✅ |
| `members/index.html` | 会員一覧 | ✅ |
| `members/show.html` | 会員詳細（4 タブ + プラン変更モーダル） | ✅ |
| `members/create.html` | 会員登録 | ✅ |
| `simulations/` | 経営シミュレーション（追加要件） | ✅ |

### フェーズ2: Laravel 実装（完了 — 本番稼働中）

| 区分 | 実装内容 |
|------|---------|
| Controllers | `Zeal/{Dashboard,Inquiry,Member,Plan,Trainer,Store,Simulation}Controller.php` (7本) + `Admin/{ZealMemberImport,ZealSimulationCategory}Controller.php` (2本) |
| Models | `GymInquiry` / `ZealMember` / `ZealMemberContract` (SCD Type-2) / `ZealPlan` / `ZealStore` / `ZealTrainer` / `ZealSimulation` / `ZealSimulationCategory` / `ZealSimulationValue` (9本) |
| Enums | `ZealAcquisitionSource` / `ZealContractChangeReason` / `ZealGender` / `ZealGymInquiryStatus` / `ZealPurpose` / `ZealSimulationCalcType` / `ZealSimulationGroup` / `ZealWithdrawReason` (8本) |
| Blade | 19 本（`zeal/dashboard.blade.php` + `zeal/{inquiries,members,plans,trainers,stores,simulations}/`） |
| ルート | 約 70 本（プレフィックス `/zeal/*` + 経営試算表項目マスタ `admin/master/zeal-simulation-categories/*`） |
| サイドバー | 「ZEAL」グループに体験予約 / 会員 / プラン / トレーナー / 店舗 / 経営試算表 / ダッシュボードを登録 |

### Phase 別実装ステータス

| Phase | 内容 | 状態 |
|-------|------|------|
| 3-A | 基盤（DDL / Model 9本 / Enum 8本 / サイドバー / `config/database.php` の `zeal` 接続 / 外部 DB の `GymInquiry`） | ✅ |
| 3-B | 体験予約閲覧: `Zeal\InquiryController` index/show（read-only） | ✅ |
| 3-C | プランマスタ: `Zeal\PlanController` フル CRUD | ✅ |
| 3-D | トレーナーマスタ: `Zeal\TrainerController` Ajax CRUD | ✅ |
| 3-E | 会員管理: `Zeal\MemberController` フル CRUD + `changePlan` / `withdraw` | ✅ |
| 3-F | 会員 CSV インポート: `Admin\ZealMemberImportController` | ✅ |
| 3-G | ダッシュボード: `Zeal\DashboardController` + Chart.js | ✅ |
| 3-H | 30 点品質監査 + デプロイ | ✅ |
| 3-I | 店舗マスタ Ajax CRUD（追加要件）: `Zeal\StoreController` | ✅ |

### 追加実装（要件定義書 v2 範囲外で実施）

経営シミュレーション（経営試算表）を実装段階で追加し、本番稼働中:

- `Zeal\SimulationController` — CRUD + 実績連動 `syncActuals` / `syncActualsPreview`
- `Admin\ZealSimulationCategoryController` — 項目マスタ（ドラッグ&ドロップ並び替え対応）
- Phase 1〜7 + 予算機能 + 未確定月の予測表示まで稼働済み

---

## ✅ 優先度5: STEP 12 ダッシュボード（実装完了）

### 経営ダッシュボード（経営層のみ）

- Controller: `DashboardController::executive`
- ルート: `/dashboard/executive`（middleware: `role:executive`）
- Blade: `dashboard/executive.blade.php` + 5 partial（`_executive_filter` + `_executive_charts` + `_executive_housing` / `_executive_realestate` / `_executive_mansion` / `_executive_tenant`）
- 構成: 5 事業横断 KPI（テナント / 不動産 / 住宅事業 / 賃貸マンション / ZEAL）+ 月次推移グラフ（Chart.js、`cdn.jsdelivr.net` のみ）

### テナントダッシュボード（全ロール）

- Controller: `DashboardController::tenant`
- ルート: `/dashboard/tenant`
- Blade: `dashboard/tenant.blade.php` + 2 partial（`_tenant_summary_main` + `_tenant_buildings`）
- 構成: 空室一覧 / 契約満了間近 / 未対応問合せ / 直近の修繕・投資案件

### 自動ロール振り分け

`/dashboard` ルートが `role:executive` ユーザーであれば `dashboard.executive` に、それ以外は `dashboard.tenant` に自動リダイレクト（`DashboardController` 内のクロージャ）。

### 並行実装された事業別ダッシュボード（優先度1〜4 内で個別に実装済み）

- 住宅事業: `Housing\HousingDashboardController` → `/housing`（優先度3）
- 賃貸マンション: `Mansion\DashboardController` → `/mansion/dashboard`（優先度1）
- ZEAL: `Zeal\DashboardController` → `/zeal`（優先度4）

---

## バックログ完了状況

優先度 1〜5 のすべてのバックログ項目が本番稼働中。新規要件は別途追記する。
