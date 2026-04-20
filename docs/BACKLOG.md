# 未実装バックログ — 優先順位付き

## 優先度1: 賃貸マンション管理

詳細仕様: @docs/賃貸マンション管理_要件定義書_v1.md

### フェーズ1: モック作成（進行中）

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

### フェーズ2: 実装

モック確定後に着手:
- マイグレーション（`ms_*` テーブル一式 — 要件定義書 §2 参照）
- Enum 作成: `MsTenantType`（resident/parking_only）
- Controller: `Mansion/PropertyController`, `Mansion/RoomController`, `Mansion/TenantController`, `Mansion/ContractController`, `Mansion/ParkingContractController`
- Blade: モックを `_form.blade.php` 等の部分テンプレート化
- ルート: `mansion` prefix で約30ルート想定（部屋契約7 + 駐車場契約7 + その他）

---

## 優先度2: DAD（土木事業）管理

詳細仕様: @docs/DAD_土木事業_要件定義書_v1.md

---

## 優先度3: 住宅事業 横断一覧

### 要件

- 建売物件・注文住宅を横断的に閲覧できる一覧画面
- フィルター: 種別（建売/注文）、ステータス、担当者、年度

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
