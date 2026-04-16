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
