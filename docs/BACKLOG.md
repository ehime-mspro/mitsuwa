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

## 優先度2: DAD（土木事業）管理

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

**原価管理カード**（工事案件 `projects/create.html` + `projects/show.html` 共通パターン）:
- インライン行追加（`＋ 行追加` で空行追加 → 直接入力）
- Excel取込（3ステップ: ファイル選択 → 列マッピング → プレビュー → 末尾に追加）
- SheetJS（`cdn.jsdelivr.net/npm/xlsx@0.18.5`）によるクライアントサイド解析
- カテゴリエイリアス自動変換（材料→材料費、外注/下請→外注費 等）
- プレビューで カテゴリ不一致（黄ハイライト）・金額NG（赤ハイライト）を警告

**モックはすべて完了**。次フェーズは Phase 2 Laravel 実装（`dad_*` テーブル / Enum 5本 / Controller 6本 / Blade 約30本 / ルート約34本）。本番での Excel取込は PhpSpreadsheet + `ProjectImportController@preview/execute` を追加。

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

## 優先度4: STEP 12 ダッシュボード

### 要件

2種類のダッシュボードを作成:

#### 経営ダッシュボード（経営層のみ）
- 全事業横断の収支サマリー
- テナント: 入居率、月次収入、空室数
- 不動産: 年度別契約件数・粗利額合計
- 住宅事業: 年度別販売件数・粗利額合計
- Chart.js によるグラフ表示（CDNは `cdn.jsdelivr.net` のみ）

#### テナントダッシュボード（全ロール）
- 空室一覧
- 契約満了間近の案件
- 未対応の問合せ
- 直近の修繕・投資案件
