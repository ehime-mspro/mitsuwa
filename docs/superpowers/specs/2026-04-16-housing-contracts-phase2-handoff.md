# 住宅事業 契約管理 — Phase 2 以降 引き継ぎ資料

**作成日**: 2026-04-16
**目的**: `/clear` 後に新セッションで Phase 2 以降の実装を継続するための引き継ぎ
**関連**: `docs/BACKLOG.md` 優先度1「住宅事業 契約管理」

---

## 1. 現状サマリー

### Phase 1（完了）
デザインモック全 6 画面を作成しユーザー承認取得済み。すべて `docs/mockups/housing-contracts/` 配下にコミット済み。

| # | モック | 実装予定ルート |
|---|--------|---------------|
| 1 | `index.html` | `GET /housing/contracts` |
| 2 | `show-building.html` | `GET /housing/contracts/building/{id}` |
| 3 | `show-custom-order.html` | `GET /housing/contracts/custom-order/{id}` |
| 4 | `edit-building.html` | `GET /housing/contracts/building/{id}/edit` |
| 5 | `edit-custom-order.html` | `GET /housing/contracts/custom-order/{id}/edit` |
| 6 | `select-building-property.html` | `GET /housing/contracts/create/building/select-property` |

補助モック: `datepicker-proposals.html` / `datepicker-c-final.html`（案C採用）

### Phase 2 以降（次にやる作業）
- Phase 2: ルート追加・Controller拡張・DTOマッパー拡張
- Phase 3: 一覧画面改修（5サマリーカード + 11列テーブル + 新規登録ドロップダウン）
- Phase 4: 詳細ページ実装（建売改修 + 注文住宅新規）
- Phase 5: 編集ページ新規作成（両方）
- Phase 6: 物件選択画面 + 注文住宅リダイレクト
- Phase 7: 手動テスト

---

## 2. 必読ファイル（優先順位順）

新セッション冒頭で以下を順に確認してください。

1. **本ファイル**（今読んでいるもの）
2. **`docs/superpowers/specs/2026-04-16-housing-contracts-design.md`** — 主設計書（770行、17セクション）。全仕様の根拠
3. **`docs/BACKLOG.md`** — 優先度1の要件定義
4. **`docs/mockups/housing-contracts/*.html`** — 承認済みモック6画面（本書の「4章 追加決定事項」を先に読むこと）
5. **`docs/RULES.md`** — Vite CSS制限・Alpine.js/Blade 制約・過去バグカタログ
6. **`docs/ARCHITECTURE.md`** — ディレクトリ構造・DBテーブル一覧
7. **`CLAUDE.md`** — プロジェクト全体のコーディングルール

### 参考既存実装（コピー元／改修対象）
| ファイル | 役割 |
|---------|------|
| `app/Http/Controllers/Housing/HsContractListController.php` | 現行 `/housing/contracts` 集約一覧。基本フロー完成済。**これを拡張する** |
| `app/Http/Controllers/Housing/ContractController.php` | 建売サブリソースCRUD（元ページ側、参照用） |
| `app/Http/Controllers/Housing/CustomOrderController.php` | 注文住宅CRUD（元ページ側、参照用） |
| `resources/views/housing/contracts/index.blade.php` | 現行一覧Blade。**改修対象** |
| `resources/views/housing/contracts/show.blade.php` | 現行建売詳細。**改修（リネーム）対象** |
| `resources/views/housing/contracts/create.blade.php` | 顧客選択UI部品の抽出元 |

### データモデル
| モデル | テーブル | 備考 |
|-------|---------|------|
| `HsContract` | `hs_contracts` | 建売契約。HsPropertyのサブリソース |
| `HsCustomOrder` | `hs_custom_orders` | 注文住宅。土地・建物カラム直持ち、`is_land_cost_manual` フラグあり |
| `HsProperty` | `hs_properties` | 建売物件。建売契約では土地/建物原価の参照元 |
| `Buyer` | `buyers` | **SoftDeletes使用。`withTrashed()` 必須** |

### Enum
- `CustomOrderStatus` — 7段階（商談→設計→見積→契約→着工→完成→引渡）
- `HousingPropertyStatus` — 建売物件ステータス
- `HousingLandSourceType` — 土地種別3パターン（`procurement` / `project` / `customer_land`）

---

## 3. 既存実装 vs 設計書 の差分（= 未実装項目）

### 完全実装済み
- 建売 + 注文住宅統合データ取得
- 年度・種別・担当者フィルター（onchange即時送信）
- 基本サマリー（件数・金額・粗利率）
- 建売契約詳細ページ
- 編集ボタン権限制御

### 未実装（Phase 2〜6で実装）
| 差分項目 | 対応Phase |
|---------|----------|
| ヘッダー `[+ 新規契約登録]` Alpine.jsドロップダウン | Phase 3 |
| 編集ページ（`edit-building.blade.php` / `edit-custom-order.blade.php`）・update アクション | Phase 5 |
| 注文住宅詳細の統合（現 `/housing/custom-orders/{id}` → `/housing/contracts/custom-order/{id}`） | Phase 4 |
| URL設計変更: `/housing/contracts/{type}/{id}` 形式（type=building\|custom-order） | Phase 2 |
| テーブル11列化（契約日/種別/物件名/顧客/契約額/土地粗利率/建物粗利率/合計粗利率/進行状況/担当/アクション） | Phase 3 |
| サマリーカード5分割（件数/契約額/土地粗利/建物粗利/合計粗利） | Phase 3 |
| 詳細ページ「元ページへ」ボタン（建売→`/housing/properties/{id}`、注文→`/housing/custom-orders/{id}`） | Phase 4 |
| DTO拡張: `land_profit` / `building_profit` / `land_profit_rate` / `building_profit_rate` / `total_profit_rate` / `status` | Phase 2 |
| 建売物件選択画面の実装 | Phase 6 |

---

## 4. モック承認プロセスで決定された追加事項（設計書に未反映）

これらはモック承認プロセスで追加決定されたので、**設計書の記述より優先**してください。

### 4.1 案C日付ピッカー（年月選択ピッカー展開UI）を採用
- 契約日入力には `datepicker-c-final.html` の案Cを流用
- Alpine.js `datePicker()` ファクトリ関数で実装（`x-data="datePicker()"`）
- 年月ピッカー展開モードで西暦/月の選択が可能
- `edit-building.html` / `edit-custom-order.html` に実装サンプルあり

### 4.2 建築土地フィールドの3パターン条件分岐（注文住宅編集）
ラジオボタンで土地種別を切替：
- **仕入れ土地** (`procurement`): 仕入れ案件詳細へのリンク表示（`/realestate/procurements/{id}`）
- **分譲地** (`project`): 分譲地PJ詳細へのリンク表示（`/realestate/projects/{id}`）
- **自社所有土地** (`customer_land`): テキスト表示のみ

データモデル側: `HsCustomOrder.land_source_type` enum (`HousingLandSourceType`) で分岐。

### 4.3 注文住宅編集のスコープ調整
- **進行ステータスセクション**: 非表示（スコープ外）
- **決済日 (`settlement_date`) 編集フィールド**: 非表示（DBカラムは残すがフォーム外）
- **登録情報セクション**（登録日時/最終更新/登録者）: 詳細ページに表示

### 4.4 注文住宅詳細のUI整理
- **注文コード表示**: 非表示
- **住所フィールド**: `dl-grid` 内で `span3` クラスにより全幅表示
- **完成予定・引渡日**: 詳細ページでは非表示

### 4.5 カードセクションタイトルに緑色アクセントバー
- 編集ページ（edit-building / edit-custom-order）の各カード見出し左側に緑色バー
- CSS: 左 `border-left: 4px solid #059669` または `::before` 疑似要素で実装
- 色: `#059669`

### 4.6 建売契約編集のラベル修正
- 「土地販売価格」等のラベル調整あり（詳細はモック `edit-building.html` 参照）

### 4.7 一覧テーブル11列のうち粗利率3列
- 土地粗利率 / 建物粗利率 / 合計粗利率 の3列は **パーセンテージ表示**（金額ではない）
- 粗利色: `color: #047857; font-weight: 700`

### 4.8 サマリーカードの土地/建物金額レイアウト
- 土地と建物の金額は **横並び**（縦並びではない）
- カード内 `display: flex; gap: 32px;` または `grid-template-columns: 1fr 1fr;`
- カード全体のgap: **32px**（左寄り問題を防ぐため）

### 4.9 その他の決定
- 建売物件選択画面のフィルターは「物件名検索」のみ（ステータスは「販売中」固定、列から削除）
- `select-building-property.html` の表は7列構成（物件コード/物件名/住所/土地面積/建物面積/予定販売価格/アクション）

---

## 5. Phase 2〜7 作業項目

### Phase 2: 基盤整備

#### 2-1. `routes/web.php` L678-686付近に新規ルート追加
```php
Route::middleware('role:executive,manager,staff')->group(function () {
    Route::get('housing/contracts', [HsContractListController::class, 'index'])->name('housing.contracts.index');
    Route::get('housing/contracts/create/building/select-property', [HsContractListController::class, 'selectBuildingProperty'])->name('housing.contracts.select-building-property');
    Route::get('housing/contracts/building/{hsContract}', [HsContractListController::class, 'showBuilding'])->name('housing.contracts.show-building');
    Route::get('housing/contracts/custom-order/{hsCustomOrder}', [HsContractListController::class, 'showCustomOrder'])->name('housing.contracts.show-custom-order');
});

Route::middleware('role:executive,manager')->group(function () {
    Route::get('housing/contracts/building/{hsContract}/edit', [HsContractListController::class, 'editBuilding'])->name('housing.contracts.edit-building');
    Route::put('housing/contracts/building/{hsContract}', [HsContractListController::class, 'updateBuilding'])->name('housing.contracts.update-building');
    Route::get('housing/contracts/custom-order/{hsCustomOrder}/edit', [HsContractListController::class, 'editCustomOrder'])->name('housing.contracts.edit-custom-order');
    Route::put('housing/contracts/custom-order/{hsCustomOrder}', [HsContractListController::class, 'updateCustomOrder'])->name('housing.contracts.update-custom-order');
});
```
※ 既存の `/housing/contracts` ルートは index のみ残し、上記で置き換え。

#### 2-2. DTOマッパー拡張（`HsContractListController` 内部）
以下のフィールドを追加：
- `land_profit`（土地粗利額）
- `building_profit`（建物粗利額）
- `land_profit_rate`（土地粗利率 %）
- `building_profit_rate`（建物粗利率 %）
- `total_profit_rate`（合計粗利率 %）
- `status_label`（進行ステータスラベル）
- `status_color`（バッジ色）
- `original_url`（元ページURL）

#### 2-3. Controller新規メソッドスケルトン追加
- `HsContractListController@showBuilding`
- `HsContractListController@showCustomOrder`
- `HsContractListController@editBuilding`
- `HsContractListController@editCustomOrder`
- `HsContractListController@updateBuilding`
- `HsContractListController@updateCustomOrder`
- `HsContractListController@selectBuildingProperty`

---

### Phase 3: 一覧画面改修

- `resources/views/housing/contracts/index.blade.php` を改修
  - 5サマリーカード化（件数/契約額/土地粗利/建物粗利/合計粗利）
  - 11列テーブル化
  - ヘッダー `[+ 新規契約登録]` Alpine.jsドロップダウン実装
    - 建売 → `route('housing.contracts.select-building-property')` へ
    - 注文住宅 → `/housing/custom-orders/create` へリダイレクト

---

### Phase 4: 詳細ページ実装

- `show.blade.php` を `show-building.blade.php` にリネーム・モックに合わせ改修
- `show-custom-order.blade.php` 新規作成
- 両方に「元ページへ」ボタン追加
  - 建売: `/housing/properties/{id}`
  - 注文: `/housing/custom-orders/{id}`
- 注文住宅詳細のUI整理（注文コード非表示、住所span3、登録情報セクション追加）

---

### Phase 5: 編集ページ実装

#### 5-1. `edit-building.blade.php` 新規作成
- カードセクションタイトルに緑アクセントバー
- 契約日フィールドに案C日付ピッカー
- `DB::transaction` で HsContract + HsProperty 同時更新

#### 5-2. `edit-custom-order.blade.php` 新規作成
- 建築土地3パターン条件分岐（仕入れ/分譲地/自社所有）
- 進行ステータスセクション非表示
- 登録情報セクション表示
- `land_cost` の条件付き必須バリデーション:
  `Rule::requiredIf(fn() => $this->land_source_type !== 'customer_land' && $this->is_land_cost_manual)`
- `DB::transaction` で HsCustomOrder 単一更新

#### 5-3. 共通
- Buyer参照時は `withTrashed()` 必須（既存Buyerも含む）
- Request クラス or Controller 内バリデーション

---

### Phase 6: 物件選択／リダイレクト

- `select-building-property.blade.php` 新規作成
  - 未契約の建売物件一覧（`HsProperty::whereDoesntHave('contract')`）
  - 物件名検索フィルター
  - 20件/ページ
  - 7列: 物件コード/物件名/住所/土地面積/建物面積/予定販売価格/アクション
  - 空状態UIも実装（`select-building-property.html` 下部のコメント参照）
- 注文住宅新規登録のリダイレクト動作確認

---

### Phase 7: 包括的手動テスト

- [ ] 全ルート疎通（GET/PUT含む）
- [ ] 権限制御（staff は edit 403、manager/executive は全操作可能）
- [ ] 粗利計算（土地・建物分離、消費税処理）
- [ ] Buyer ソフトデリート対応（`withTrashed` でドロップダウンに表示）
- [ ] `DB::transaction` のロールバック動作
- [ ] サイドバーの active 判定（`request()->is('housing/contracts*')`）
- [ ] 案C日付ピッカーの動作
- [ ] 建築土地3パターン切替（注文住宅編集）
- [ ] 5サマリーカード・11列テーブルの表示
- [ ] 新規登録ドロップダウン → 物件選択画面 → 契約登録 の導線
- [ ] 「元ページへ」ボタンの遷移先確認

---

## 6. 重要な実装上の制約（CLAUDE.md / RULES.md より抜粋）

### DB運用
- マイグレーションファイルは使わない方針
- `sudo mysql manage < file.sql` で直接SQL実行（必要な場合のみ）

### CSS
- Vite ビルド（CDNではない）。**新規 Tailwind クラスは動かない**
- インラインスタイル or `<style>` ブロックを使用
- 動作確認済みクラス: `docs/RULES.md` の「Working Tailwind Classes」参照

### Alpine.js
- `x-data` 内で `=>` アロー関数禁止（HTML閉じタグとして解釈される）
  → `<script>` に named function 抽出: `x-data="datePicker()"`
- `style=` と `:style=` の併用禁止 → 単一 `:style` バインドに統合
- `<template x-if>` は `x-for` や SVG 内では使わない。`x-show` を使用

### Blade
- `@if/@else/@endif` は複数行必須（`@else<` は Laravel 12 でコンパイルエラー）
- `@json()` 内で PHP関数禁止（`number_format()` 等）→ Controller で pre-format
- 添付ファイル: `@include('components.attachment-section', [...])` 使用（`<x-attachment-section>` ではない）

### データ表示
- 金額: 税抜・円サフィックス（`28,500,000円`）。`¥` プレフィックス不可
- 粗利色: `color: #047857; font-weight: 700`
- 建蔽率/容積率: 整数表示（`80%` であり `80.00%` ではない）
- 担当者: 姓のみ（重複時のみフルネーム）
- 20件/ページ
- 会計年度: 5月1日開始（5月〜翌4月）
- 部署検出: `resolveDepartment()` via `request()->segment(1)`

### DB / モデル
- `re_projects` テーブルのカラムは `project_name`（`name` ではない）
- `User` モデルに `deleted_at` なし → `User::orderBy('name')` のみ使用
- Buyer は SoftDeletes → `->withTrashed()` 必須

### キャッシュクリア
```bash
sudo rm -f storage/framework/views/*.php && sudo systemctl restart apache2
```

---

## 7. 新セッション開始時の初動

```
1. 本ハンドオフ資料を読む（今読んでいるファイル）
2. docs/superpowers/specs/2026-04-16-housing-contracts-design.md を通読
3. docs/mockups/housing-contracts/ 配下の6モックを確認
4. git log --oneline -10 で最新コミット確認
5. TodoWrite で Phase 2 のタスクを登録
6. Phase 2-1（routes/web.php追加）から着手
```

### 未完了TODO（継続用）
1. Phase 2: routes/web.phpに新規ルート追加
2. Phase 2: DTOマッパー拡張（land_profit/building_profit/status等）
3. Phase 2: Controller新規メソッドスケルトン追加
4. Phase 3: 一覧サマリー5カード化 + 11列テーブル + 新規登録ドロップダウン
5. Phase 4: 建売/注文住宅 詳細ページ実装
6. Phase 5: 建売/注文住宅 編集ページ実装
7. Phase 6: 建売物件選択画面実装
8. Phase 7: 包括的手動テスト

---

## 8. 最近のコミット（Phase 1 関連）

```
82043b8a モック: 建売物件選択画面のフィルター整理とステータス列削除
20da7209 モック: 注文住宅契約編集をedit-buildingと統一化し、土地種別ラベル整理
96edd373 モック: 日付ピッカー3案を作成し、案C（年月ピッカー付き）を建売契約編集に実装
552399a5 注文住宅契約詳細モックのUI整理
f005eaa0 モック: 建売契約詳細画面のUI整理
c428e741 モック: サマリーを両端配置（space-between）+ 左右余白16pxで視覚調整
```

---

## 9. 未確認事項・要ヒアリング（新セッション冒頭で確認推奨）

- [ ] 消費税処理の具体ロジック（HsContract::calculateProfit() 等の既存メソッド確認）
- [ ] 注文住宅の `is_land_cost_manual` フラグ動作の再確認
- [ ] `/housing/custom-orders/create` への注文住宅新規登録リダイレクト設計の最終決定
- [ ] 注文住宅詳細ページの URL 統合タイミング（既存 `/housing/custom-orders/{id}` を廃止するか、共存するか）

---

以上。本ハンドオフ資料があれば新セッションでも Phase 1 と同等の粒度で Phase 2 以降を進められます。

---

## 10. Phase 2 実行結果（2026-04-16 実施済み）

Phase 2（基盤整備）は完了。次セッションは Phase 3 から着手する。

### 完了した変更

#### 10-1. ルート命名規約の確定
- 設計書初版の `housing.contract-list.*` は廃止
- 新体系 `housing.contracts.*` に統一（本ハンドオフ資料 §2-1 の案を採用）
- URL: `/housing/contracts/{type}/{id}` 形式（type = building | custom-order）

#### 10-2. `routes/web.php` L677-711
既存の `housing.contract-list.index` / `housing.contract-list.show` を削除し、以下 8 ルートを追加:

| ルート名 | URL | 権限 |
|---------|-----|------|
| `housing.contracts.index` | GET `/housing/contracts` | 全ロール |
| `housing.contracts.select-building-property` | GET `/housing/contracts/create/building/select-property` | 全ロール |
| `housing.contracts.show-building` | GET `/housing/contracts/building/{hsContract}` | 全ロール |
| `housing.contracts.show-custom-order` | GET `/housing/contracts/custom-order/{hsCustomOrder}` | 全ロール |
| `housing.contracts.edit-building` | GET `/housing/contracts/building/{hsContract}/edit` | executive,manager |
| `housing.contracts.update-building` | PUT `/housing/contracts/building/{hsContract}` | executive,manager |
| `housing.contracts.edit-custom-order` | GET `/housing/contracts/custom-order/{hsCustomOrder}/edit` | executive,manager |
| `housing.contracts.update-custom-order` | PUT `/housing/contracts/custom-order/{hsCustomOrder}` | executive,manager |

既存の `housing.contracts.create/store/edit/update/destroy`（建売物件サブリソースの契約 `/housing/properties/{property}/contract/*`）はそのまま温存。

#### 10-3. `HsContractListController`
- 既存 `show()` を `showBuilding()` にリネーム（ロジック保持）
- 新規 6 メソッド追加:
  - `showCustomOrder()` — 旧URL `housing.custom-orders.show` へ 302 redirect（Phase 4 で本実装）
  - `editBuilding()` — 既存 `housing.contracts.edit` へ redirect（Phase 5 で本実装）
  - `editCustomOrder()` — 既存 `housing.custom-orders.edit` へ redirect（Phase 5 で本実装）
  - `updateBuilding()` / `updateCustomOrder()` — `abort(501)`（Phase 5 で本実装）
  - `selectBuildingProperty()` — `abort(501)`（Phase 6 で本実装）

#### 10-4. DTOマッパー拡張
`mapTateuriToDto()` / `mapCustomOrderToDto()` に以下を追加:

| フィールド | 内容 |
|-----------|------|
| `land_selling` / `building_selling` | 土地・建物別売価（サマリー集計用） |
| `land_profit` / `building_profit` | 土地・建物別粗利額 |
| `land_profit_rate` / `building_profit_rate` / `total_profit_rate` | 粗利率3種 |
| `status_label` / `status_color` | 建売は「契約済」固定、注文住宅は `CustomOrderStatus::label()/badgeStyle()` |
| `original_url` | 元ページURL（建売→`properties.show`、注文→`custom-orders.show`） |

`detail_url` / `edit_url` を新ルート名に更新。

**注意**: DBカラム名は `HsContract` は `selling_price_land`/`selling_price_building`、`HsCustomOrder` は `land_selling_price`/`building_contract_price`（不統一）。DTO内で吸収済み。

#### 10-5. `index()` の view 引数拡張
Phase 3 のサマリーカード5分割向けに以下を view に渡すよう追加:
- `landProfitTotal` / `buildingProfitTotal`
- `landSellingTotal` / `buildingSellingTotal`
- `landProfitRate` / `buildingProfitRate`

#### 10-6. Blade のルート名参照更新
- `resources/views/housing/contracts/index.blade.php` L65, L90
- `resources/views/housing/contracts/show.blade.php` L9, L23
- すべて `housing.contract-list.index` → `housing.contracts.index` に更新

### URL統合方針の確定（§4 追記）
- 注文住宅詳細は **共存方式** を採用（2026-04-16 確認）
- 新URL `/housing/contracts/custom-order/{id}` を正式パスとし、既存 `/housing/custom-orders/{id}` は当面維持
- Phase 4 で `showCustomOrder()` を本実装し、表示ロジックを新URL側に集約する

### 次セッションでの着手順序
1. サーバキャッシュクリア: `sudo rm -f storage/framework/views/*.php && sudo systemctl restart apache2`
2. ブラウザで `/housing/contracts` が既存の見た目で表示されることを確認
3. Phase 3 着手 — `resources/views/housing/contracts/index.blade.php` の全面改修（5サマリーカード + 11列テーブル + 新規登録ドロップダウン）
4. モック `docs/mockups/housing-contracts/index.html` を実装の参照源とする

---

## 11. Phase 5 実行結果（2026-04-17 実施済み）

Phase 5（編集ページ実装）は完了。建売・注文住宅いずれも編集フォームから更新処理まで機能する。

### 完了した変更

#### 11-1. `resources/views/housing/contracts/edit-building.blade.php` 新規作成
- カード3枚構成: 基本情報 / 契約金額 / 原価情報
- 各カード見出しに緑アクセントバー `.hc-section-title .bar`（3×20px, `#059669`）
- 契約日フィールドに案C `datePicker()` を組み込み（年月選択ピッカー展開 UI）
  - `x-data="datePicker('{{ $contractDateValue }}')"` で初期値を渡し、hidden input に ISO 日付をバインド
- 土地原価の手動入力切替: フォームタグに `x-data="{ isLandCostManual: {{ ... }} }"` を付与し、`x-show` で ON/OFF 表示切替
- チェックボックスのフォールバック: `<input type="hidden" name="is_land_cost_manual" value="0">` を先置き → チェック時に `value="1"` で上書きする二重フィールドパターン
- 買主プルダウンは `$buyers` を `@foreach` でループし、`$buyer->trashed()` 時に「（削除済み）」表示（SoftDeletes 対応）
- フォーム `action` は `route('housing.contracts.update-building', $hsContract)`、`@method('PUT')`
- スタイル/スクリプトは `@section('content')` 冒頭に `<style>` / `<script>` で直接記述（`@stack('styles')` が layouts/app.blade.php に存在しないため）

#### 11-2. `resources/views/housing/contracts/edit-custom-order.blade.php` 新規作成
- 土地種別3パターン条件分岐（`land_source_type` ラジオボタン）:
  - `project_lot`（分譲地PJ区画） / `procurement`（仕入れ案件） / `customer_land`（顧客所有地）
- Alpine.js コンテキストはラッパー `<div>` に `x-data="customOrderEditForm({ landSourceType, isLandCostManual, reProjectLotId, reProcurementId })"` で付与（`datePicker()` をネストできる）
- 紐付け先リンク: `projectLotUrls` / `procurementUrls` を `@json()` でオブジェクト化し、`:href="projectLotUrls[reProjectLotId]"` で動的に URL 設定
- 建築土地カード全体: `x-show="landSourceType !== 'customer_land'"` で顧客所有地選択時に非表示
- 登録情報セクション（登録者 / 登録日時 / 更新者 / 更新日時）を inline grid で表示
- `settlement_date` および進行ステータスセクションは非表示（仕様通り）
- フォーム `action` は `route('housing.contracts.update-custom-order', $hsCustomOrder)`、`@method('PUT')`

#### 11-3. `HsContractListController` の4メソッド本実装
`redirect()` / `abort(501)` スタブを実装に置き換え:

| メソッド | 責務 |
|---------|------|
| `editBuilding()` | `property.projectLot.project` / `procurement` / `createdBy` を eager load、`staffUsers` / `buyers`（withTrashed で現行 buyer を含める）を取得 → `edit-building` view 返却 |
| `updateBuilding()` | `HsContract`（売価・顧客・契約日・備考）と `HsProperty`（building_cost / is_land_cost_manual / land_cost）を `DB::transaction` で同時更新 |
| `editCustomOrder()` | `projectLot.project` / `procurement` / `createdBy` を eager load、`staffUsers` / `buyers` / `procurements` / `projectLots` を渡す |
| `updateCustomOrder()` | 土地種別に応じて関連カラムを整理。`customer_land` の場合は `re_project_lot_id` / `re_procurement_id` / `land_selling_price` / `land_cost` / `is_land_cost_manual` をすべて null/false にクリア |

### 主要な設計判断

1. **土地原価の手動入力 OFF 時は land_cost を更新しない** — 紐付け先参照の参考値はフォームに送信されないため、既存値を維持して整合性を保つ
2. **土地種別切替時のクリア処理** — 注文住宅で分譲地/仕入れ → 顧客所有地に変更した場合、土地関連カラムをすべて null にクリアし `is_land_cost_manual` も false にリセット
3. **`created_by`（担当者）は明示指定時のみ上書き** — 空の場合は既存値を維持するパターンを両 update メソッドで採用
4. **バリデーション**: `land_cost` を `Rule::requiredIf(fn() => ...)` で条件付き必須化（PHP アロー関数は Alpine.js 制約対象外）

### 更新後のリダイレクト

- `updateBuilding` → `route('housing.contracts.show-building', $hsContract)` + `session('success', '契約を更新しました。')`
- `updateCustomOrder` → `route('housing.contracts.show-custom-order', $hsCustomOrder)` + 同上

---

## 12. Phase 6 実行結果（2026-04-17 実施済み）

Phase 6（建売物件選択画面 + 注文住宅リダイレクト）は完了。新規契約登録の導線が完結した。

### 完了した変更

#### 12-1. `resources/views/housing/contracts/select-building-property.blade.php` 新規作成
- **7列テーブル**: 物件コード / 物件名 / 住所 / 土地面積 / 建物面積 / 予定販売価格 / アクション
- 物件名 LIKE 検索フォーム（keyword パラメータ）+ 検索ボタン + クリアボタン
- 青色の説明バナー「契約を登録する建売物件を選択してください。一覧には未契約の物件のみ表示されています。」
- パンくず: ホーム › 住宅事業 › 契約管理（リンク） › 建売契約登録 — 物件選択
- 物件名セル → `route('housing.properties.show', $prop)`（詳細ページ）
- 「この物件で契約登録」ボタン → `route('housing.contracts.create', $prop)`（既存 `ContractController@create` の建売物件サブリソース契約登録フォーム）
- **空状態UI**: 検索結果 0 件 / 全体で 0 件 で文言を出し分け（「「{keyword}」に該当する未契約の建売物件はありません」 vs 「未契約の建売物件がありません」）
- 空状態CTA: 「建売物件を新規登録する」→ `route('housing.properties.create')`
- ページネーション 20件/ページ（contracts/index と同UIで統一）

#### 12-2. `HsContractListController::selectBuildingProperty()` 本実装
`abort(501)` から以下に置き換え:

```php
$query = HsProperty::whereDoesntHave('contract')
    ->with(['projectLot', 'procurement']);

$keyword = trim((string) $request->input('keyword', ''));
if ($keyword !== '') {
    $query->where('property_name', 'LIKE', '%' . $keyword . '%');
}

$properties = $query->orderBy('property_code')->paginate(20)->withQueryString();
```

- `whereDoesntHave('contract')` で未契約物件のみ取得（`HsProperty::contract()` は HasOne リレーション）
- `projectLot` / `procurement` を eager load（`getSellingPriceTotal()` が紐付け先の参考販売価格を参照するため）
- `withQueryString()` で検索条件をページ遷移で維持
- 予定販売価格は `$prop->getSellingPriceTotal()`（建物予定販売価格 + 紐付け先の土地参考販売価格）

### 注文住宅新規登録の導線確認

Phase 3 で既に index.blade.php のドロップダウン内に `route('housing.custom-orders.create')` へのリンクを配線済み。Phase 6 では追加変更不要。

### 導線全体像（Phase 6 完了時点）

```
/housing/contracts
   └─ ヘッダー「+ 新規契約登録」ドロップダウン
        ├─ 「建売を登録」
        │    → /housing/contracts/create/building/select-property（物件選択画面）
        │         → [この物件で契約登録]
        │              → /housing/properties/{property}/contract/create（既存の建売契約登録フォーム）
        └─ 「注文住宅を登録」
             → /housing/custom-orders/create（既存の注文住宅登録フォーム）
```

### コミット情報

Phase 2〜6 の実装をまとめて `acf6c310` でコミット（10ファイル変更 / +3,030 / −289）。

```
acf6c310 住宅事業契約管理 Phase 2〜6: 建売・注文住宅の統合実装
```

---

## 13. Phase 7 残タスク（次セッション）

Phase 2〜6 の実装はすべて完了。残るは **Phase 7: 包括的手動テスト** のみ。

### 実施前のキャッシュクリア

```bash
sudo rm -f storage/framework/views/*.php && sudo systemctl restart apache2
```

### 手動テストの観点

- [ ] `/housing/contracts` 一覧表示（年度・種別・担当者フィルター、5サマリーカード、11列テーブル）
- [ ] 新規契約登録ドロップダウン → 建売物件選択 → 契約登録 の導線
- [ ] 新規契約登録ドロップダウン → 注文住宅登録 の導線
- [ ] 建売契約詳細 `/housing/contracts/building/{id}` — 「元ページへ」ボタン、編集ボタン
- [ ] 注文住宅契約詳細 `/housing/contracts/custom-order/{id}` — 同上
- [ ] 建売契約編集 — 案C日付ピッカー動作、土地原価手動入力切替、更新後のリダイレクト
- [ ] 注文住宅契約編集 — 土地種別3パターン切替、紐付け先リンク動作、更新後のリダイレクト
- [ ] 物件選択画面 — 物件名検索、ページネーション、空状態UI
- [ ] 権限: manager/staff が編集ボタンを見られないこと、URL直打ちで 403 が返ること

