# 住宅事業 契約管理 設計書

- **作成日**: 2026-04-16（2026-04-16 既存実装発見により全面更新）
- **ブランチ**: `feature/manage-system`
- **優先度**: 1（`docs/BACKLOG.md`より）
- **実装方針**: 案A 統合コントローラ型。**既存の`HsContractListController`を拡張**

---

## 1. 概要

住宅事業（建売物件・注文住宅）の契約情報を横断的に一覧・詳細閲覧・編集できる「契約管理」機能。既存実装で一覧と建売詳細は完成しており、本設計では未実装部分（注文住宅詳細・編集・新規登録導線）の追加および既存部分の改修（列追加・粗利分離表示）を定義する。

### 機能性格: ハイブリッド型

- 集約一覧・詳細閲覧 + 契約情報+原価情報の編集
- 新規登録は本ページから開始するが、フォーム自体は既存ページ（`/housing/properties/{id}/contract/create`・`/housing/custom-orders/create`）を再利用して二重管理を回避

### 対象範囲

**契約成立済みのみ**を対象とする：

- 建売: `HsContract`レコードが存在する`HsProperty`（`contract_date`が入っているもの）
- 注文住宅: `HsCustomOrder.status >= Contracted` かつ `contract_date`が入っているもの

---

## 2. 要件確定事項（ブレインストーミング結果）

| 決定事項 | 内容 |
|---|---|
| 機能性格 | ハイブリッド型（集約一覧+詳細+編集+新規登録導線） |
| 対象範囲 | 契約成立済みのみ |
| 編集スコープ | 契約情報 + 原価情報（土地原価・建物原価） |
| 一覧構成 | 不動産契約管理の構成踏襲 + 住宅特有拡張（土地/建物粗利分離、注文住宅の進行ステータス列） |
| 権限 | staff: 閲覧+新規登録可、編集不可。manager/executive: 全操作可 |
| 用語規約 | `building_cost`の表示ラベルは「建物原価」 |
| 実装方針 | 既存`HsContractListController`を拡張（新規作成しない） |

---

## 3. 既存実装の現状

### 3.1 既存ファイル（変更または再利用）

| ファイル | 行数 | 状態 | 本設計での扱い |
|---|---|---|---|
| `app/Http/Controllers/Housing/HsContractListController.php` | 262 | 一覧(index) + 建売詳細(show) 実装済み | **拡張**（メソッド追加・DTO拡張） |
| `resources/views/housing/contracts/index.blade.php` | 200 | 一覧UI 実装済み | **改修**（列追加、粗利分離、新規登録ボタン追加） |
| `resources/views/housing/contracts/show.blade.php` | 203 | 建売詳細 実装済み | **改修**（「元ページへ」ボタン追加、注文住宅モード対応可の汎用化） |
| `resources/views/housing/contracts/create.blade.php` | 177 | 建売契約登録（物件サブリソース用） | **変更なし**（既存の`ContractController`で使われる） |
| `resources/views/housing/contracts/edit.blade.php` | 145 | 建売契約編集（物件サブリソース用） | **変更なし**（既存の`ContractController`で使われる） |
| `resources/views/layouts/partials/sidebar.blade.php` | — | `/housing/contracts`リンク設定済み | **変更なし** |
| `routes/web.php` (L678-686) | — | `housing.contract-list.index` / `housing.contract-list.show` 定義済み | **拡張**（ルート追加） |

### 3.2 既存の実装内容（保持する部分）

**`HsContractListController::index()` (L23-120):**
- `HsContract::whereNotNull('contract_date')` と `HsCustomOrder::where(status, Contracted以上)->whereNotNull('contract_date')` を取得
- `mapTateuriToDto()` / `mapCustomOrderToDto()` で統一DTOに変換
- 年度・種別・担当者フィルター適用
- `merge()` → 契約日降順ソート → `LengthAwarePaginator`手動構築

**`HsContractListController::show(HsContract $hsContract)` (L126-139):**
- 建売契約の詳細ページ表示

**既存の一覧テーブル (現状9列):**
契約日 / 種別 / 物件名 / 顧客 / 契約額 / 原価 / 粗利額 / 担当 / 詳細

**既存のサマリーカード (現状):**
契約件数（建売/注文住宅内訳） / 契約額合計 / 粗利合計 / 粗利率

### 3.3 既存実装と設計要件の差分

#### (a) 本設計で**追加**する要素

- 統一DTOに `land_profit` / `building_profit` キーを追加
- 一覧テーブルに「土地粗利」「建物粗利」「進行状況」列を追加（9列→11列）
- サマリーカードに「土地粗利合計」「建物粗利合計」カードを追加（3列→5列）
- 一覧ヘッダーに `[+ 新規契約登録]` Alpine.jsドロップダウン追加
- 詳細ページに「元ページへ」ボタン追加
- 注文住宅詳細ページ対応
- 編集ページ（建売・注文住宅）新規実装
- 新規登録導線（建売=物件選択画面 / 注文住宅=既存create画面へリダイレクト）新規実装

#### (b) 既存から**置換/削除**する要素

- 一覧テーブルの「粗利額」1列 → 「土地粗利」「建物粗利」「合計粗利」3列へ置換
- 既存の「[削除]」ボタン（detail page）は保持（既存機能維持）
- 既存の「契約一覧に戻る」ボタン名称は保持

---

## 4. アーキテクチャ

### 新規追加ファイル

```
resources/views/housing/contracts/
  edit-building.blade.php         ← 新規（建売契約編集フォーム）
  edit-custom-order.blade.php     ← 新規（注文住宅契約編集フォーム）
  show-custom-order.blade.php     ← 新規（注文住宅契約詳細）
  select-building-property.blade.php ← 新規（建売の物件選択画面）
```

※ 既存の `create.blade.php`, `edit.blade.php`（建売物件サブリソース用）とは名称を明確に分離。

### 変更ファイル

| ファイル | 変更内容 |
|---|---|
| `app/Http/Controllers/Housing/HsContractListController.php` | メソッド追加: `showCustomOrder`, `editBuilding`, `updateBuilding`, `editCustomOrder`, `updateCustomOrder`, `createBuilding`（選択画面）, `createCustomOrder`（リダイレクト）。DTO拡張: `mapTateuriToDto` / `mapCustomOrderToDto` に `land_profit` / `building_profit` / `status_label` / `status_badge_style` キー追加 |
| `resources/views/housing/contracts/index.blade.php` | 列追加（土地粗利/建物粗利/進行状況）、サマリーカード追加、新規登録ドロップダウン追加 |
| `resources/views/housing/contracts/show.blade.php` | 「元ページへ」ボタン追加。必要に応じて注文住宅モードにも対応する共通化 |
| `routes/web.php` | ルート追加（`/housing/contracts/create/{type}`, `/housing/contracts/custom-order/{id}`, edit系4ルート） |

### 参照のみ（変更なし）

- `app/Models/HsContract.php`
- `app/Models/HsCustomOrder.php`
- `app/Models/HsProperty.php`
- `app/Enums/CustomOrderStatus.php`
- `app/Enums/HousingPropertyStatus.php`
- `app/Enums/HousingLandSourceType.php`
- `resources/views/housing/contracts/create.blade.php`（既存の物件サブリソース契約登録画面、登録時にリダイレクトする先）
- `resources/views/housing/contracts/edit.blade.php`（既存の物件サブリソース契約編集画面、補助的リンク先として残す）
- `resources/views/layouts/partials/sidebar.blade.php`

### 既存ページとの関係

- `/housing/properties/{id}/contract/*` は残す（元ページからの編集導線として機能）
- `/housing/custom-orders/*` は残す（ステータス遷移・添付管理などの全項目編集）
- 本ページ（契約管理）詳細からは「元ページへ」ボタンで遷移誘導

---

## 5. URL・ルート設計

### ルート一覧（既存＋新規）

| メソッド | URL | 状態 | Controllerメソッド | 権限 | 名前 |
|---|---|---|---|---|---|
| GET | `/housing/contracts` | 既存 | `index` | all | `housing.contract-list.index` |
| GET | `/housing/contracts/{hsContract}` | 既存 | `show` | all | `housing.contract-list.show` |
| GET | `/housing/contracts/custom-order/{customOrder}` | **新規** | `showCustomOrder` | all | `housing.contract-list.show-custom-order` |
| GET | `/housing/contracts/create/building` | **新規** | `createBuilding` | all | `housing.contract-list.create-building` |
| GET | `/housing/contracts/create/custom-order` | **新規** | `createCustomOrder` | all | `housing.contract-list.create-custom-order` |
| GET | `/housing/contracts/{hsContract}/edit` | **新規** | `editBuilding` | manager,executive | `housing.contract-list.edit-building` |
| PUT | `/housing/contracts/{hsContract}` | **新規** | `updateBuilding` | manager,executive | `housing.contract-list.update-building` |
| GET | `/housing/contracts/custom-order/{customOrder}/edit` | **新規** | `editCustomOrder` | manager,executive | `housing.contract-list.edit-custom-order` |
| PUT | `/housing/contracts/custom-order/{customOrder}` | **新規** | `updateCustomOrder` | manager,executive | `housing.contract-list.update-custom-order` |

※権限`all` = 認証済かつ住宅事業アクセス可能（executive または belongsToDepartment('housing')）

### ルート定義例

```php
Route::prefix('housing')->group(function () {
    // 既存（全員）
    Route::get('/contracts', [HsContractListController::class, 'index'])
        ->name('housing.contract-list.index');

    // 新規登録導線（全員=staff含む）
    Route::get('/contracts/create/building',
        [HsContractListController::class, 'createBuilding'])
        ->name('housing.contract-list.create-building');
    Route::get('/contracts/create/custom-order',
        [HsContractListController::class, 'createCustomOrder'])
        ->name('housing.contract-list.create-custom-order');

    // 注文住宅詳細（全員）
    Route::get('/contracts/custom-order/{customOrder}',
        [HsContractListController::class, 'showCustomOrder'])
        ->name('housing.contract-list.show-custom-order');

    // 建売詳細（既存、全員）
    Route::get('/contracts/{hsContract}', [HsContractListController::class, 'show'])
        ->name('housing.contract-list.show');

    // 編集系（manager/executive のみ）
    Route::middleware('role:executive,manager')->group(function () {
        Route::get('/contracts/{hsContract}/edit',
            [HsContractListController::class, 'editBuilding'])
            ->name('housing.contract-list.edit-building');
        Route::put('/contracts/{hsContract}',
            [HsContractListController::class, 'updateBuilding'])
            ->name('housing.contract-list.update-building');

        Route::get('/contracts/custom-order/{customOrder}/edit',
            [HsContractListController::class, 'editCustomOrder'])
            ->name('housing.contract-list.edit-custom-order');
        Route::put('/contracts/custom-order/{customOrder}',
            [HsContractListController::class, 'updateCustomOrder'])
            ->name('housing.contract-list.update-custom-order');
    });
});
```

### ルート登録の注意点

- `/contracts/custom-order/{customOrder}` は `/contracts/{hsContract}` より**先に**登録する必要あり（`custom-order`が`hsContract`のIDとして解釈されるのを防ぐ）
- `/contracts/create/building` と `/contracts/create/custom-order` も同様に先に登録

---

## 6. 一覧ページ仕様（`index.blade.php` の改修）

### 6.1 DTO拡張（Controller側）

既存DTOに以下のキーを追加:

```php
[
    // 既存キーに加えて
    'land_profit'        => int|null,  // 顧客所有地=null
    'building_profit'    => int,
    'land_selling_price' => int|null,
    'land_cost'          => int|null,
    'status_label'       => string,  // 建売="成約済" / 注文住宅=CustomOrderStatus.label()
    'status_badge_style' => string,  // バッジのinline style
    'edit_url'           => string|null,  // manager/executive のみ生成
]
```

計算ロジックは既存モデルのヘルパーメソッドを流用:
- 建売: `HsContract::getLandProfit()`, `getBuildingProfit()`, `getTotalProfit()`
- 注文住宅: `HsCustomOrder::getLandProfit()`, `getBuildingProfit()`, `getTotalProfit()`

### 6.2 サマリーカード（改修）

既存3〜4枚から**5枚**に拡張:

| # | カード | 内容 |
|---|---|---|
| 1 | 契約件数 | 合計 X件（建売 Y件 / 注文住宅 Z件） |
| 2 | 契約額合計 | `XX,XXX,XXX円`（税抜） |
| 3 | 土地粗利合計 | `X,XXX,XXX円`（`color:#047857;font-weight:700`） **★新規** |
| 4 | 建物粗利合計 | `X,XXX,XXX円`（`color:#047857;font-weight:700`） **★新規** |
| 5 | 合計粗利 | `X,XXX,XXX円` + 粗利率平均(%)（`color:#047857;font-weight:700`） |

※ `$collection->sum('land_profit')` でnullは自動的に0扱い。粗利率は「合計粗利合計 / 契約額合計 × 100」で計算。

### 6.3 テーブル列構成（9列→11列）

| # | 列 | 内容 | 既存/新規 |
|---|---|---|---|
| 1 | 契約日 | `YYYY/MM/DD` | 既存 |
| 2 | 種別 | バッジ（建売/注文住宅、inline style） | 既存 |
| 3 | 物件名 | `property_name` or `order_name` → 詳細リンク | 既存 |
| 4 | 顧客 | `customer_name` | 既存 |
| 5 | 契約額 | 税抜合計 `XX,XXX,XXX円` | 既存 |
| 6 | 土地粗利 | `X,XXX,XXX円` または「—」（顧客所有地） | **新規** |
| 7 | 建物粗利 | `X,XXX,XXX円` | **新規** |
| 8 | 合計粗利 | `X,XXX,XXX円`（太字、緑） | 既存（名称変更：「粗利額」→「合計粗利」） |
| 9 | 進行状況 | 建売=「成約済」/ 注文住宅=ステータスバッジ | **新規** |
| 10 | 担当 | 姓のみ | 既存 |
| 11 | アクション | [詳細]リンク | 既存 |

### 6.4 ヘッダー右側に [+ 新規契約登録] 追加

```blade
<div x-data="{ open: false }" class="relative">
    <button @click="open = !open" class="...">
        + 新規契約登録
    </button>
    <div x-show="open" @click.away="open = false" class="absolute ...">
        <a href="{{ route('housing.contract-list.create-building') }}">建売を登録</a>
        <a href="{{ route('housing.contract-list.create-custom-order') }}">注文住宅を登録</a>
    </div>
</div>
```

全員（staff含む）に表示。Alpine.jsのドロップダウン。

### 6.5 既存の詳細リンク分岐

既存DTOの`detail_url`で分岐。建売は `route('housing.contract-list.show', $c)`、注文住宅は新規追加した `route('housing.contract-list.show-custom-order', $customOrderId)`。

### 6.6 既存維持部分

- フィルター（年度・種別・担当者、onchangeで送信）
- ページネーション 20件/ページ
- クリアボタンスタイル
- 空状態メッセージ

---

## 7. 詳細ページ仕様

### 7.1 建売詳細（既存`show.blade.php`の改修）

#### 改修点

1. ヘッダー右のボタン構成を変更:
   - 既存: `[契約一覧に戻る] [編集] [削除]`
   - 新規: `[編集※] [元ページへ] [戻る] [削除※]`
   - ※ 編集・削除は manager/executive のみ

2. 「元ページへ」ボタン追加: `/housing/properties/{propertyId}` へ遷移

3. 既存の全セクション（基本情報/契約金額/原価/粗利/物件情報/備考）は**そのまま維持**

4. 表示ルール: CLAUDE.md準拠（金額円、粗利色`#047857`、姓のみ等）— **既存実装を踏襲**

### 7.2 注文住宅詳細（新規 `show-custom-order.blade.php`）

#### URL

`GET /housing/contracts/custom-order/{customOrder}` → `showCustomOrder(HsCustomOrder $customOrder)`

#### 404条件

- `$customOrder->status < Contracted`
- `$customOrder->contract_date === null`

```php
abort_if(
    $customOrder->status->stepIndex() < CustomOrderStatus::Contracted->stepIndex()
    || $customOrder->contract_date === null,
    404
);
```

#### ページ構成

建売詳細と同じセクション構成 + **進行ステータスのステップバー**:

```
┌─ ヘッダー ─────────────────────────────┐
│ [注文住宅バッジ] {order_name}            │
│      [編集※] [元ページへ] [戻る]        │
└────────────────────────────────────┘

┌─ 基本情報 ────────┬─ 金額サマリー ─────┐
│ 契約日             │ 契約額合計           │
│ 顧客名             │ 原価合計             │
│ 担当者             │ 合計粗利             │
│                    │ 粗利率               │
└────────────────┴──────────────────┘

┌─ 契約金額内訳 ──────────────────────┐
│ 土地販売価格（顧客所有地なら「—」）   │
│ 建物契約価格（税率%）                 │
│ 建物消費税                            │
│ 税込合計                              │
└────────────────────────────────┘

┌─ 原価内訳 ─────────────────────────┐
│ 土地原価（顧客所有地なら「—」） │
│ 建物原価                        │
│ 合計原価                        │
└────────────────────────────────┘

┌─ 粗利分析 ─────────────────────────┐
│ 土地粗利（顧客所有地なら「—」）       │
│ 建物粗利                              │
│ 合計粗利                              │
└────────────────────────────────┘

┌─ 物件情報 ─────────────────────────┐
│ 注文コード/住所/土地面積/建物面積/構造/階数/完成予定/引渡日 │
└────────────────────────────────┘

┌─ 進行ステータス ─────────────────────┐
│ ●────●────●────●────○────○────○  │
│ 商談  設計  見積  契約  着工  完成 引渡し │
│ ※ステータス変更は注文住宅ページへ       │
└────────────────────────────────┘
```

#### 顧客所有地（`land_source_type='customer_land'`）の表示

- 「土地販売価格」「土地原価」「土地粗利」は「— （顧客所有地）」と表示
- 合計粗利は建物粗利のみで算出

#### アクションボタン

| ボタン | 遷移先 | 表示条件 |
|---|---|---|
| 編集 | `/housing/contracts/custom-order/{id}/edit` | manager/executive のみ |
| 元ページへ | `/housing/custom-orders/{id}` | 全員 |
| 戻る | `/housing/contracts` | 全員 |

---

## 8. 編集ページ仕様

### 8.1 建売編集（新規 `edit-building.blade.php`）

#### URL

- `GET /housing/contracts/{hsContract}/edit` → `editBuilding`
- `PUT /housing/contracts/{hsContract}` → `updateBuilding`
- 権限: executive/manager のみ

#### フォーム項目

**(A) 基本情報セクション**

| 項目 | 入力UI | バリデーション |
|---|---|---|
| 契約日 | `<input type="date">` | required, date |
| 顧客 | 既存 `housing/contracts/create.blade.php` の顧客選択UI部品を抽出再利用（Buyer紐付け or 手入力） | required (customer_name), nullable (customer_id) |
| 担当者 | セレクト（`User::orderBy('name')`、姓のみ表示） | required, exists:users,id |
| 備考 | `<textarea>` | nullable, max:2000 |

**(B) 契約金額セクション**

| 項目 | バリデーション |
|---|---|
| 土地販売価格 | required, integer, min:0 |
| 建物販売価格 | required, integer, min:0 |
| 税率(%) | required, numeric, min:0, max:100 |

**(C) 原価セクション**

```
[ ] 土地原価を手動入力する（is_land_cost_manual）
   ├ OFF: 紐付け元（分譲地PJ/仕入案件）から自動参照
   └ ON: <input type="number">表示、円サフィックス
```

| 項目 | バリデーション |
|---|---|
| 土地原価 | is_land_cost_manual=1 のとき required, integer, min:0 |
| 建物原価 | required, integer, min:0 |

**(D) 送信ボタン**

- `[キャンセル]` → 建売詳細ページへ戻る
- `[更新]` → PUT送信（`bg-emerald-600`）
- `[元ページで全項目編集]` → `/housing/properties/{propertyId}/contract/edit`

#### 更新処理（トランザクション）

```php
DB::transaction(function() use ($validated, $hsContract) {
    $hsContract->update([
        'contract_date', 'customer_id', 'customer_name',
        'selling_price_land', 'selling_price_building', 'tax_rate',
        'notes', 'updated_by' => auth()->id(),
    ]);
    $hsContract->property->update([
        'land_cost', 'building_cost', 'is_land_cost_manual',
    ]);
});
```

### 8.2 注文住宅編集（新規 `edit-custom-order.blade.php`）

#### URL

- `GET /housing/contracts/custom-order/{customOrder}/edit` → `editCustomOrder`
- `PUT /housing/contracts/custom-order/{customOrder}` → `updateCustomOrder`
- 権限: executive/manager のみ

#### フォーム項目

**(A) 基本情報セクション** — 建売と同じ

**(B) 契約金額セクション（Alpine.jsで土地源別に表示切替）**

| 項目 | 表示条件 | バリデーション |
|---|---|---|
| 土地販売価格 | `land_source_type !== 'customer_land'` のみ表示（非表示時`:disabled`） | required_unless:land_source_type,customer_land, integer, min:0 |
| 建物契約価格 | 常時 | required, integer, min:0 |
| 税率(%) | 常時 | required, numeric, min:0, max:100 |

**(C) 原価セクション** — 建売と同じロジック。ただし顧客所有地の場合はセクション丸ごと非表示（土地原価入力を出さず、建物原価のみ）

#### 更新処理（トランザクション、単一テーブル）

```php
DB::transaction(function() use ($validated, $customOrder) {
    $customOrder->update([
        'contract_date', 'customer_id', 'customer_name',
        'land_selling_price', 'building_contract_price', 'tax_rate',
        'land_cost', 'building_cost', 'is_land_cost_manual',
        'notes', 'updated_by' => auth()->id(),
    ]);
});
```

### 8.3 共通の送信フロー

- 成功時: `redirect()->route('housing.contract-list.show', $hsContract)` または `show-custom-order` + フラッシュ「契約情報を更新しました」
- エラー時: `back()->withErrors()->withInput()` — Alpine状態は`old()`で復元
- CLAUDE.md準拠: `placeholder="0"`禁止、`margin-bottom:26px`、Alpine `x-show`時は`:disabled`併用

### 8.4 バリデーション詳細（Controller側）

```php
// 共通
$common = [
    'contract_date' => 'required|date',
    'customer_name' => 'required|string|max:255',
    'customer_id'   => 'nullable|integer|exists:buyers,id',
    'staff_user_id' => 'required|integer|exists:users,id',
    'tax_rate'      => 'required|numeric|min:0|max:100',
    'notes'         => 'nullable|string|max:2000',
    'is_land_cost_manual' => 'nullable|boolean',
    'building_cost' => 'required|integer|min:0',
];

// 建売固有
$building = [
    'selling_price_land'     => 'required|integer|min:0',
    'selling_price_building' => 'required|integer|min:0',
    'land_cost'              => 'required_if:is_land_cost_manual,1|integer|min:0',
];

// 注文住宅固有
$customOrder = [
    'land_source_type'       => 'required|in:project_lot,procurement,customer_land',
    'building_contract_price'=> 'required|integer|min:0',
    'land_selling_price'     => 'required_unless:land_source_type,customer_land|integer|min:0',
    'land_cost'              => [
        Rule::requiredIf(fn() => $request->land_source_type !== 'customer_land'
                                 && $request->boolean('is_land_cost_manual')),
        'integer', 'min:0',
    ],
];
```

---

## 9. 新規登録フロー

### 9.1 全体フロー

```
【一覧ページ】
  [+ 新規契約登録] ドロップダウン
    ├ [建売を登録]     → GET /housing/contracts/create/building
    └ [注文住宅を登録] → GET /housing/contracts/create/custom-order
        │                     │
        ▼                     ▼
    未契約HsProperty一覧     /housing/custom-orders/create へ302リダイレクト
    （select-building-      （既存画面を再利用）
     property.blade.php）
        │
        ▼（物件を選択）
    /housing/properties/{id}/contract/create へ遷移
    （既存画面）
        │
        ▼（既存フォームで契約登録）
    登録完了 → 既存の遷移先
```

### 9.2 建売物件選択画面（新規）

**Controller: `createBuilding()`**

```php
public function createBuilding()
{
    $properties = HsProperty::whereDoesntHave('contract')
        ->orderBy('property_code')
        ->get();

    return view('housing.contracts.select-building-property',
        compact('properties'));
}
```

**View: `select-building-property.blade.php`**

- 未契約物件のリスト表示（`property_code`, `property_name`, `status`, 住所 等）
- 各行に「この物件で契約登録する」リンク → `/housing/properties/{id}/contract/create`
- 物件がない場合の空状態: 「未契約の建売物件がありません」+ 物件登録画面へのリンク

### 9.3 注文住宅リダイレクト

**Controller: `createCustomOrder()`**

```php
public function createCustomOrder()
{
    return redirect()->route('housing.custom-orders.create');
}
```

---

## 10. 権限制御

### 10.1 操作マトリクス

| 操作 | staff | manager | executive |
|---|---|---|---|
| 一覧閲覧 | ○ | ○ | ○ |
| 詳細閲覧（建売・注文住宅） | ○ | ○ | ○ |
| 新規登録導線（dropdown/物件選択） | ○ | ○ | ○ |
| 編集（edit/update） | **×** | ○ | ○ |

※ 住宅事業に所属しないstaffは全アクセス不可（既存の部署判定に従う）。

### 10.2 実装

- ルートレベル: 編集系4ルートに`role:executive,manager`ミドルウェア
- Controllerレベル: 編集系メソッドの先頭で防御的チェック（`abort_unless(in_array(auth()->user()->role, ['executive','manager']), 403)`）
- Viewレベル: 詳細ページで編集ボタンを条件表示

```blade
@if(in_array(auth()->user()->role, ['executive', 'manager']))
    <a href="{{ $editUrl }}">編集</a>
@endif
```

### 10.3 住宅事業所属チェック

既存実装に準ずる。`HsContractListController`コンストラクタまたはミドルウェアで:

```php
$user = auth()->user();
abort_unless(
    $user->role === 'executive' || $user->belongsToDepartment('housing'),
    403
);
```

※ 既存実装で既に制御されていれば踏襲。

---

## 11. サイドバー

**変更なし。** 既存で`/housing/contracts`リンク済み、アクティブ判定`request()->is('housing/contracts*')`も機能中。

ルート名`housing.contract-list.index`も変更なし。

---

## 12. データ整合性・エラーハンドリング

### トランザクション

- 建売更新: `DB::transaction`で HsContract + HsProperty の2テーブル更新
- 注文住宅更新: 単一テーブルだが`DB::transaction`で統一

### 同時編集対策

- 楽観ロック未導入（プロジェクト全体の方針に準ずる）
- `updated_by`に`auth()->id()`記録

### ソフトデリート対応

- Buyer参照時は`->withTrashed()`必須

### エラー表

| 発生条件 | 挙動 |
|---|---|
| 未認証 | middleware:authでログインページ |
| 権限不足 | middleware:roleで403 |
| 存在しないID | ルートモデルバインディング失敗 → 404 |
| 注文住宅でstatus<Contracted | `abort(404)` |
| 注文住宅でcontract_date null | `abort(404)` |
| バリデーションエラー | `back()->withErrors()->withInput()` |
| DB更新失敗 | トランザクションロールバック → Laravelエラーページ |

---

## 13. 実装前の確認タスク（デザインモック）

CLAUDE.md方針「実装する前はデザインモックで確認」に従い、コード着手前に以下のHTMLモックを `docs/mockups/housing-contracts/` 配下に作成してユーザー承認を得る:

1. `index.html` — 一覧ページ（改修版: 5サマリーカード、11列テーブル、新規登録ドロップダウン）
2. `show-building.html` — 建売詳細（改修版: 「元ページへ」ボタン追加）
3. `show-custom-order.html` — 注文住宅詳細（新規、顧客所有地パターン含む、進行ステータスバー）
4. `edit-building.html` — 建売編集（新規、土地原価手動入力切替含む）
5. `edit-custom-order.html` — 注文住宅編集（新規、Alpine.js土地源切替）
6. `select-building-property.html` — 建売物件選択画面（新規）

※ デザインは既存`index.blade.php` / `show.blade.php`のスタイルを踏襲し、一貫性を保つ。

---

## 14. テスト方針

### 既存テスト状況

`tests/Feature/`, `tests/Unit/`はあるが、Laravel初期のサンプルのみ（Auth系中心）。住宅事業の既存モジュールにもテストコードなし。本機能もテスト**任意**とする。

### 手動テスト項目（最低限）

1. **一覧**
   - フィルター各種（年度・種別・担当者）が動作
   - サマリー5カード（契約件数/契約額/土地粗利/建物粗利/合計粗利）の計算
   - テーブル11列の表示
   - [+ 新規契約登録]ドロップダウンの開閉・遷移
   - 建売/注文住宅で詳細リンクが正しい先へ遷移
2. **建売詳細**
   - 全セクションの表示
   - 「元ページへ」ボタンの遷移先
   - staffで編集ボタン非表示、manager/executiveで表示
3. **注文住宅詳細**
   - 全セクションの表示（進行ステータスバー含む）
   - 顧客所有地（CustomerLand）パターンの「—」表示
   - 編集ボタン権限制御
4. **建売編集**
   - バリデーションエラー表示
   - 土地原価「手動入力」切替のAlpine挙動
   - 更新成功時のリダイレクトとフラッシュ
   - HsContract + HsProperty 両方更新されることを確認
5. **注文住宅編集**
   - 顧客所有地での土地フィールド非表示挙動
   - 顧客所有地以外での全フィールド入力
   - 更新成功
6. **権限制御**
   - staffでedit URL直打ち → 403
   - staffで新規登録ドロップダウン使用可
7. **新規登録**
   - 建売: 未契約物件のみ選択肢に出る
   - 注文住宅: `/housing/custom-orders/create`へリダイレクト

### 自動テスト（オプション、時間があれば）

- Feature Test: 各ルートの権限、CRUD動作、年度フィルターの年境界
- Unit Test: 統一DTOマップ関数、未契約判定

---

## 15. スコープ外（今回実装しない）

- 契約削除機能（既存showの[削除]は維持、注文住宅はステータスから既存画面で管理）
- ステータス遷移（注文住宅の進行段階は元ページで管理）
- 添付ファイル管理（元ページで管理）
- 決済日の表示・編集（DBカラムは残す）
- 経営ダッシュボード連携（BACKLOG.md 優先度3）
- 住宅事業横断一覧（BACKLOG.md 優先度2）
- 契約書類の電子化・印刷機能

---

## 16. 参考既存コード

### 拡張対象（本設計で変更）

- `app/Http/Controllers/Housing/HsContractListController.php`
- `resources/views/housing/contracts/index.blade.php`
- `resources/views/housing/contracts/show.blade.php`
- `routes/web.php`（L678-686の付近にルート追加）

### 構造参考元（読み取りのみ）

- `app/Http/Controllers/RealEstate/ReContractController.php` — 一覧/詳細パターン（既に`HsContractListController`で類似パターン採用済み）
- `app/Http/Controllers/Housing/ContractController.php` — 建売契約のサブリソースCRUD
- `app/Http/Controllers/Housing/CustomOrderController.php` — 注文住宅CRUD、`updateStatus`ロジック
- `resources/views/housing/contracts/create.blade.php` — 顧客選択UI部品の抽出元
- `resources/views/housing/custom-orders/_form.blade.php` — 注文住宅フォーム構造の参考

### 計算ロジック活用元

- `HsContract::getSellingPriceTotal()`, `getTotalProfit()`, `getLandProfit()`, `getBuildingProfit()` 他
- `HsCustomOrder::getTotalSellingPrice()`, `getTotalProfit()`, `getLandProfit()`, `getBuildingProfit()` 他

### Enum・型

- `CustomOrderStatus::label()`, `badgeStyle()`, `stepIndex()`
- `HousingPropertyStatus::label()`, `badgeStyle()`
- `HousingLandSourceType`

---

## 17. 次のステップ

1. この設計書をユーザーがレビュー → 承認
2. 実装計画（writing-plansスキル）で`docs/superpowers/plans/`に詳細プラン作成
3. 実装計画に従い、**デザインモック作成 → 承認 → 実装**の順で進める
