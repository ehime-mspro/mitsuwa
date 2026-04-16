# 住宅事業 契約管理 設計書

- **作成日**: 2026-04-16
- **ブランチ**: `feature/manage-system`
- **優先度**: 1（`docs/BACKLOG.md`より）
- **実装方針**: 案A 統合コントローラ型（新規DBテーブル・モデル追加なし）

---

## 1. 概要

住宅事業（建売物件・注文住宅）の契約情報を横断的に一覧・詳細閲覧・編集できる「契約管理」機能を新規実装する。既存の`HsContract`（建売契約）と`HsCustomOrder`（注文住宅、契約情報を内包）の2モデルを**単一のUIで統合表示**し、担当者・経営層が契約実績を俯瞰できるようにする。

### 機能性格: ハイブリッド型

- 集約一覧・詳細閲覧 + 契約情報+原価情報の編集
- 新規登録は本ページから開始するが、フォーム自体は既存ページ（`/housing/properties/{id}/contract/create`・`/housing/custom-orders/create`）を再利用して二重管理を回避

### 対象範囲

**契約成立済みのみ**を対象とする：

- 建売: `HsContract`レコードが存在する`HsProperty`（`contract_date`が入っているもの）
- 注文住宅: `HsCustomOrder.status >= Contracted` かつ `contract_date`が入っているもの

契約前段階（商談・設計・見積・仕入れ検討中など）は対象外。既存の物件一覧・注文住宅一覧で扱う。

---

## 2. 要件確定事項（ブレインストーミング結果）

| 決定事項 | 内容 |
|---|---|
| 機能性格 | ハイブリッド型（集約一覧+詳細+編集+新規登録導線） |
| 対象範囲 | 契約成立済みのみ |
| 編集スコープ | 契約情報 + 原価情報（土地原価・建物原価） |
| 一覧構成 | 不動産契約管理(`re_contracts`)の構成踏襲 + 住宅特有拡張（土地/建物粗利分離、注文住宅の進行ステータス列） |
| 権限 | staff: 閲覧+新規登録可、編集不可。manager/executive: 全操作可 |
| 用語規約 | `building_cost`の表示ラベルは「建物原価」（「建築費」は使わない） |

---

## 3. アーキテクチャ（案A 統合コントローラ型）

### 新規作成ファイル

```
app/Http/Controllers/Housing/
  HousingContractController.php   ← 新規（統合Controller）

resources/views/housing/contracts/
  index.blade.php               ← 新規（集約一覧）
  show.blade.php                ← 新規（詳細）
  edit-building.blade.php       ← 新規（建売編集フォーム）
  edit-custom-order.blade.php   ← 新規（注文住宅編集フォーム）
  create.blade.php              ← 新規（新規登録: 種別選択→遷移）
  _select-building-property.blade.php ← 新規（建売の物件選択画面）
```

### 変更ファイル（既存）

| ファイル | 変更内容 |
|---|---|
| `routes/web.php` | `/housing/contracts`系ルート追加（新規5ルート） |
| `resources/views/layouts/partials/sidebar.blade.php` | 「契約管理」リンクを`route('housing.contracts.index')`に設定 |

### 参照のみ（変更なし）

- `app/Models/HsContract.php`
- `app/Models/HsCustomOrder.php`
- `app/Models/HsProperty.php`
- `app/Enums/CustomOrderStatus.php`
- `app/Enums/HousingPropertyStatus.php`
- `app/Enums/HousingLandSourceType.php`

### 既存ページとの関係

- `/housing/properties/{id}/contract/*` は残す（元ページからの編集導線として機能）
- `/housing/custom-orders/*` は残す（ステータス遷移・添付管理などの全項目編集）
- 本ページ（契約管理）からは「元ページへ」ボタンで遷移誘導

---

## 4. URL・ルート設計

### ルート一覧

| メソッド | URL | Controllerメソッド | 権限 | 名前 |
|---|---|---|---|---|
| GET | `/housing/contracts` | `index` | all | `housing.contracts.index` |
| GET | `/housing/contracts/create/{type}` | `create` | all | `housing.contracts.create` |
| GET | `/housing/contracts/{type}/{id}` | `show` | all | `housing.contracts.show` |
| GET | `/housing/contracts/{type}/{id}/edit` | `edit` | executive,manager | `housing.contracts.edit` |
| PUT | `/housing/contracts/{type}/{id}` | `update` | executive,manager | `housing.contracts.update` |

- `{type}` の制約: `'building'` または `'custom-order'`
- `{id}` の制約: 数字のみ
- 権限`all` = `role:executive,manager,staff` かつ住宅事業所属

### ルート定義イメージ

```php
// 閲覧・新規登録（全員）
Route::middleware(['auth', 'role:executive,manager,staff'])
    ->prefix('housing/contracts')
    ->name('housing.contracts.')
    ->group(function () {
        Route::get('/', [HousingContractController::class, 'index'])->name('index');
        Route::get('/create/{type}', [HousingContractController::class, 'create'])
            ->where('type', 'building|custom-order')
            ->name('create');
        Route::get('/{type}/{id}', [HousingContractController::class, 'show'])
            ->where(['type' => 'building|custom-order', 'id' => '[0-9]+'])
            ->name('show');
    });

// 編集・更新（manager/executive のみ）
Route::middleware(['auth', 'role:executive,manager'])
    ->prefix('housing/contracts')
    ->name('housing.contracts.')
    ->group(function () {
        Route::get('/{type}/{id}/edit', [HousingContractController::class, 'edit'])
            ->where(['type' => 'building|custom-order', 'id' => '[0-9]+'])
            ->name('edit');
        Route::put('/{type}/{id}', [HousingContractController::class, 'update'])
            ->where(['type' => 'building|custom-order', 'id' => '[0-9]+'])
            ->name('update');
    });
```

---

## 5. 一覧ページ仕様（`index.blade.php`）

### データ取得

1. 建売契約: `HsContract::whereNotNull('contract_date')->with(['property.projectLot', 'property.procurement'])->get()`
2. 注文住宅契約: `HsCustomOrder::whereIn('status', ['contracted','construction','completed','delivered'])->whereNotNull('contract_date')->get()`
3. 両コレクションを統一フォーマットにマップして`merge()`
4. 年度・種別・担当者フィルター適用
5. 契約日降順でソート
6. `LengthAwarePaginator`を手動構築（20件/ページ）

### 統一フォーマット（View用DTO相当）

```php
[
    'type'              => 'building' | 'custom-order',
    'id'                => int,
    'contract_date'     => Carbon,
    'name'              => string,  // 物件名 or 注文住宅名
    'customer_name'     => string,
    'selling_price_total' => int,   // 税抜合計
    'land_profit'       => int|null, // 顧客所有地はnull
    'building_profit'   => int,
    'total_profit'      => int,
    'status_label'      => string,  // 建売="成約済", 注文住宅=CustomOrderStatus.label()
    'status_badge_style' => string, // inline style文字列
    'staff_name'        => string,  // 姓のみ
    'model'             => Model,   // 元モデル（詳細リンク用）
]
```

### フィルター（onchangeで即時送信）

| フィルター | 選択肢 | デフォルト |
|---|---|---|
| 年度 | 今年度 / 前年度 / 2年前 / 3年前 / 4年前 | 今年度 |
| 種別 | すべて / 建売 / 注文住宅 | すべて |
| 担当者 | すべて / 各User姓 | すべて |

- 会計年度: 5月始まり（`docs/RULES.md`「Fiscal Year Calculation」のPHPコードを使用）
- フォーム送信: `<form id="filter-form" method="GET">` + 各selectに`onchange="document.getElementById('filter-form').submit()"`
- クリアボタン: `h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400`

### サマリーカード（フィルター適用後の集計）

| カード | 内容 |
|---|---|
| 契約件数 | 合計 X件（建売 Y件 / 注文住宅 Z件） |
| 契約額合計 | `XX,XXX,XXX円`（税抜） |
| 土地粗利合計 | `X,XXX,XXX円`（`color:#047857;font-weight:700`） |
| 建物粗利合計 | `X,XXX,XXX円`（`color:#047857;font-weight:700`） |
| 合計粗利 | `X,XXX,XXX円` + 粗利率平均(%)（`color:#047857;font-weight:700`） |

※ 土地粗利のnull（顧客所有地）は集計時に0扱い（`$collection->sum('land_profit')`で自動的にnullは0と見なされる）。粗利率は「合計粗利合計 / 契約額合計 × 100」で計算。

### テーブル列構成

| # | 列 | 内容 |
|---|---|---|
| 1 | 契約日 | `YYYY/MM/DD` |
| 2 | 種別 | バッジ（建売/注文住宅、inline style） |
| 3 | 物件名 | `property_name` or `order_name` → 詳細リンク |
| 4 | 顧客 | `customer_name` |
| 5 | 契約額 | 税抜合計 `XX,XXX,XXX円` |
| 6 | 土地粗利 | `X,XXX,XXX円` または「—」（顧客所有地） |
| 7 | 建物粗利 | `X,XXX,XXX円` |
| 8 | 合計粗利 | `X,XXX,XXX円`（太字、緑） |
| 9 | 進行状況 | 建売=「成約済」/ 注文住宅=ステータスバッジ |
| 10 | 担当 | 姓のみ |
| 11 | アクション | [詳細]リンク |

### ヘッダー右側アクション

- `[+ 新規契約登録]` ボタン（Alpine.jsドロップダウン）
  - クリックするとボタン直下に展開: `[建売を登録]` / `[注文住宅を登録]` の2項目
  - `[建売を登録]` → `GET /housing/contracts/create/building` （未契約HsProperty選択画面）
  - `[注文住宅を登録]` → `GET /housing/contracts/create/custom-order` （`/housing/custom-orders/create`へ302リダイレクト）

### 空状態

「契約実績がありません」

---

## 6. 詳細ページ仕様（`show.blade.php`）

### URL

`GET /housing/contracts/{type}/{id}` (type=building|custom-order)

### 404条件

- 該当ID未存在
- 建売: `HsProperty`は存在するが`HsContract`未作成
- 注文住宅: `status < Contracted` または `contract_date`未設定

### ページ構成

```
┌─ ヘッダー ─────────────────────────────┐
│ [種別バッジ] 物件名（注文住宅名）       │
│          [編集※] [元ページへ] [戻る]   │
└────────────────────────────────────┘
  ※[編集]はstaffには非表示（executive/manager のみ）

┌─ 基本情報 ────────┬─ 金額サマリー ─────┐
│ 契約日             │ 契約額合計  28,500,000円│
│ 顧客名             │ 原価合計    22,000,000円│
│ 担当者             │ 合計粗利     6,500,000円│
│                    │ 粗利率          22.8%   │
└────────────────┴──────────────────┘

┌─ 契約金額内訳 ──────────────────────┐
│ 土地販売価格   18,000,000円            │
│ 建物販売価格   10,500,000円（税率10%）  │
│ 建物消費税      1,050,000円            │
│ 税込合計       29,550,000円            │
└────────────────────────────────┘

┌─ 原価内訳 ─────────────────────────┐
│ 土地原価        13,000,000円（参照元: 分譲地PJ/仕入案件/顧客所有地）│
│ 建物原価         9,000,000円            │
│ 合計原価        22,000,000円            │
└────────────────────────────────┘

┌─ 粗利分析 ─────────────────────────┐
│ 土地粗利        5,000,000円（27.8%）    │
│ 建物粗利        1,500,000円（14.3%）    │
│ 合計粗利        6,500,000円（22.8%）    │
└────────────────────────────────┘

┌─ 物件情報 ─────────────────────────┐
│ (建売) 物件コード/住所/土地面積/建物面積/構造/階数/竣工予定 │
│ (注文) 注文コード/住所/土地面積/建物面積/構造/階数/完成予定/引渡日 │
└────────────────────────────────┘

<注文住宅のみ>
┌─ 進行ステータス ─────────────────────┐
│ ●────●────●────●────○────○────○  │
│ 商談  設計  見積  契約  着工  完成 引渡し │
│ ※ステータス変更は注文住宅ページへ       │
└────────────────────────────────┘
```

### 表示ルール（CLAUDE.md準拠）

- 金額: `number_format()`で3桁区切り+「円」、`¥`プレフィックス使用しない
- 粗利色: `color: #047857; font-weight: 700`（inline style）
- 担当者: 姓のみ
- バッジ: Enumの`badgeStyle()`でinline style
- 戻るボタン: `f7274bda`コミットで統一した「ヘッダー右側ボーダースタイル」

### 顧客所有地（注文住宅で `land_source_type='customer_land'`）

- 「土地販売価格」「土地原価」「土地粗利」は「—（顧客所有地）」と表示
- 合計粗利は建物粗利のみで算出

### アクションボタン（ヘッダー右）

| ボタン | 遷移先 | 表示条件 |
|---|---|---|
| 編集 | `/housing/contracts/{type}/{id}/edit` | manager/executive のみ |
| 元ページへ | 建売: `/housing/properties/{propertyId}`<br>注文住宅: `/housing/custom-orders/{id}` | 全員 |
| 戻る | `/housing/contracts` | 全員 |

---

## 7. 編集ページ仕様（`edit-building.blade.php` / `edit-custom-order.blade.php`）

### URL

- `GET /housing/contracts/{type}/{id}/edit`
- `PUT /housing/contracts/{type}/{id}`
- 権限: executive/manager のみ（ミドルウェアで制御、staffは403）

### フォーム項目

#### (A) 基本情報セクション（共通）

| 項目 | 入力UI | バリデーション |
|---|---|---|
| 契約日 | `<input type="date">` | required, date |
| 顧客 | 既存の建売契約フォーム（`housing/contracts/_form.blade.php` もしくは `housing/properties/{id}/contract/create`内の顧客選択部分）のコンポーネントを再利用。Buyerマスタ紐付け or 手入力。 | required (customer_name), nullable (customer_id) |
| 担当者 | セレクト（`User::orderBy('name')->get()`、姓のみ表示） | required, exists:users,id |
| 備考 | `<textarea>` | nullable, max:2000 |

※ **決済日は表示しない**（ユーザー指示により今回スコープ外）

#### (B) 契約金額セクション

**建売:**

| 項目 | バリデーション |
|---|---|
| 土地販売価格 | required, integer, min:0 |
| 建物販売価格 | required, integer, min:0 |
| 税率(%) | required, numeric, min:0, max:100 |

**注文住宅（Alpine.jsで土地源別に表示切替）:**

| 項目 | 表示条件 | バリデーション |
|---|---|---|
| 土地販売価格 | `land_source_type !== 'customer_land'`のみ表示 | required_unless:land_source_type,customer_land, integer, min:0 |
| 建物契約価格 | 常時 | required, integer, min:0 |
| 税率(%) | 常時 | required, numeric, min:0, max:100 |

#### (C) 原価セクション

**土地原価の手動入力モード切替（両種別共通）:**

```
[ ] 土地原価を手動入力する（is_land_cost_manual）
   ├ チェックOFF: 紐付け元から自動参照「紐付け元（分譲地PJ/仕入案件）の販売価格を土地原価として自動参照」
   └ チェックON: <input type="number">表示、円サフィックス
```

| 項目 | バリデーション | 備考 |
|---|---|---|
| 土地原価 | is_land_cost_manual=1 のとき required, integer, min:0 | 注文住宅で顧客所有地の場合はセクション丸ごと非表示 |
| 建物原価 | required, integer, min:0 | 常時表示 |

#### (D) 送信ボタン

- `[キャンセル]` → 詳細ページへ戻る（灰色アウトライン）
- `[更新]` → PUT送信（`bg-emerald-600 hover:bg-emerald-700`）
- `[元ページで全項目編集]` → 建売/注文住宅のフル編集画面へ（補助的リンク）

### バリデーション（Controller@update 内）

```php
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

// 建売
$building = [
    'selling_price_land'     => 'required|integer|min:0',
    'selling_price_building' => 'required|integer|min:0',
    'land_cost'              => 'required_if:is_land_cost_manual,1|integer|min:0',
];

// 注文住宅
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

### 更新処理（トランザクション）

**建売:**

```php
DB::transaction(function() use ($validated, $contract) {
    $contract->update([
        'contract_date', 'customer_id', 'customer_name',
        'selling_price_land', 'selling_price_building', 'tax_rate',
        'notes', 'updated_by' => auth()->id(),
    ]);
    $contract->property->update([
        'land_cost', 'building_cost', 'is_land_cost_manual',
    ]);
});
```

**注文住宅（単一テーブル）:**

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

### 成功時リダイレクト

`redirect()->route('housing.contracts.show', ['type' => $type, 'id' => $id])` + フラッシュ「契約情報を更新しました」

### エラー時

`back()->withErrors()->withInput()` — Alpine状態は`old('land_source_type')`などで復元

### CLAUDE.md準拠の細則

- `placeholder="0"` 付けない
- item spacing: `margin-bottom: 26px`
- Alpine.jsの`x-show`で隠すフィールドは`:disabled`併用（重複name対策）
- `<style>`/inline styleでの装飾OK（Viteビルド対象外のTailwindクラスは効かない）

---

## 8. 新規登録フロー（`create.blade.php`）

### 方針

契約管理ページ起点で新規登録を開始するが、フォーム本体は既存ページを再利用して二重管理を回避。

### フロー

```
【一覧ページ】
  [+ 新規契約登録] ボタン
    │
    ▼（種別選択 — ボタン直下ドロップダウン or 中間ページ）
  GET /housing/contracts/create/building        GET /housing/contracts/create/custom-order
    │                                              │
    ▼                                              ▼
  未契約HsProperty一覧画面                    /housing/custom-orders/create へ直リダイレクト
  （property_codeで並び、選択可能）
    │
    ▼（物件を選択）
  /housing/properties/{id}/contract/create へ遷移
    │
    ▼（既存フォームで契約登録）
  登録完了 → 既存の遷移先（物件詳細 or 契約詳細）へ
```

### 未契約HsProperty一覧の取得

```php
HsProperty::whereDoesntHave('contract')
    ->orderBy('property_code')
    ->get();
```

### staff の新規登録可能性

- ルート`create`はstaff許可
- 既存の`/housing/properties/{id}/contract/create`がstaff許可している前提で、遷移後の登録もstaff可能
- もし既存ページがmanager以上のみなら、そちらの権限を確認（本設計では既存権限をそのまま踏襲）

---

## 9. 権限制御まとめ

| 操作 | staff | manager | executive |
|---|---|---|---|
| 一覧閲覧 | ○ | ○ | ○ |
| 詳細閲覧 | ○ | ○ | ○ |
| 新規登録導線 | ○ | ○ | ○ |
| 編集 | **×**（ボタン非表示、URL直打ち403） | ○ | ○ |
| 更新 | **×** | ○ | ○ |

### Controller内でも二重チェック

ミドルウェアに加え、`edit`/`update`の先頭で `abort_unless(in_array(auth()->user()->role, ['executive','manager']), 403)` を入れる（防御的プログラミング）。

### View側制御

```blade
@if(in_array(auth()->user()->role, ['executive', 'manager']))
    <a href="{{ route('housing.contracts.edit', ['type' => $type, 'id' => $id]) }}"
       class="...">編集</a>
@endif
```

### 住宅事業所属チェック

```php
$user = auth()->user();
abort_unless(
    $user->role === 'executive' || $user->belongsToDepartment('housing'),
    403
);
```

Controllerコンストラクタまたはミドルウェアで実施。

---

## 10. サイドバー配置

### 対象ファイル

`resources/views/layouts/partials/sidebar.blade.php`

### 変更内容

住宅事業グループの既存「契約管理」メニュー項目のリンク先を `{{ route('housing.contracts.index') }}` に設定。既存リンクが未定義(`#`)または別URLの場合は修正。

### アクティブ判定

```blade
{{ request()->routeIs('housing.contracts.*') ? 'active-class' : '' }}
```

### アクセスガード

```blade
@if(auth()->user()->role === 'executive' || auth()->user()->belongsToDepartment('housing'))
    <li><a href="{{ route('housing.contracts.index') }}">契約管理</a></li>
@endif
```

---

## 11. データ整合性・エラーハンドリング

### トランザクション

- 建売更新: `DB::transaction` で HsContract + HsProperty の2テーブル更新
- 注文住宅更新: 単一テーブルだが`DB::transaction`で統一

### 同時編集対策

- 楽観ロックは**未導入**（プロジェクトの他モジュールに準ずる）
- `updated_by`に`auth()->id()`を記録

### ソフトデリート対応

- Buyer参照時は`->withTrashed()`必須（`Customer`/`Buyer`が`SoftDeletes`のため）

### エラー表

| 発生条件 | 挙動 |
|---|---|
| 未認証 | middleware:authでログインページ |
| 権限不足 | middleware:roleで403 |
| 存在しないID | `findOrFail()` → 404 |
| `type`不正 | ルート制約で404 |
| 建売でHsContract未作成 | `abort(404)` |
| 注文住宅でstatus<Contracted | `abort(404)` |
| バリデーションエラー | `back()->withErrors()->withInput()` |
| DB更新失敗 | トランザクションロールバック → Laravelエラーページ |

---

## 12. 実装前の確認タスク（デザインモック）

CLAUDE.md「実装する前はデザインモックで確認」に従い、コード着手前に以下のHTMLモックを `docs/mockups/housing-contracts/` 配下に作成してユーザー承認を得る:

1. `index.html` — 一覧ページ（フィルター・サマリー・テーブル・新規登録ボタン）
2. `show-building.html` — 詳細ページ（建売）
3. `show-custom-order.html` — 詳細ページ（注文住宅、顧客所有地パターン含む）
4. `edit-building.html` — 編集ページ（建売）
5. `edit-custom-order.html` — 編集ページ（注文住宅、Alpine.js土地源切替含む）
6. `create-select.html` — 種別選択・物件選択画面（建売用）

---

## 13. テスト方針

### 既存テストの状況確認

実装計画フェーズで `tests/` ディレクトリ・PHPUnit/Pestの導入状況を確認。

- 既存テストあり → 同パターンで新機能のFeature Test / Unit Testを書く
- 既存テストなし → 手動テスト項目リストのみ作成

### 自動テスト想定項目（既存テストあり時）

**Feature Test:**

- staffでindex/show閲覧可能
- staffでedit/updateアクセス時403
- manager/executiveでedit/update成功
- 建売編集でHsContract + HsProperty両方が正しく更新される（トランザクション）
- 注文住宅編集でHsCustomOrderが正しく更新される
- 顧客所有地の注文住宅では土地関連フィールドがスキップされる
- 年度・種別・担当者フィルターが正しく動作する

**Unit Test:**

- 契約コレクションのマージ・ソートロジック
- 未契約HsProperty判定

### 手動テスト項目（最低限）

1. 一覧: フィルター各種の動作、サマリー計算、ページネーション
2. 詳細: 建売・注文住宅（CompanyLand）・注文住宅（CustomerLand）の3パターン
3. 編集: バリデーションエラー、成功リダイレクト、土地原価切替のAlpine挙動
4. 権限: staff→編集ボタン非表示、URL直打ち403
5. 新規登録: 未契約物件のみ選択可、既存フォーム遷移
6. サイドバー: メニュークリックで遷移、アクティブ判定

---

## 14. スコープ外（今回実装しない）

- 契約削除機能（既存ページで行う）
- ステータス遷移（注文住宅の進行段階は元ページで管理）
- 添付ファイル管理（元ページで管理）
- 決済日の表示・編集（ユーザー指示により今回対象外、DBカラムは残す）
- 経営ダッシュボードとの連携（BACKLOG.md 優先度3）
- 住宅事業横断一覧（BACKLOG.md 優先度2、別タスク）
- 契約書類の電子化・印刷機能

---

## 15. 参考: 既存コード

### 構造参考元

- `app/Http/Controllers/RealEstate/ReContractController.php` — 一覧/詳細のベースパターン
- `resources/views/realestate/contracts/index.blade.php` — フィルター・サマリー構造
- `resources/views/realestate/contracts/show.blade.php` — 詳細ページ構造

### 計算ロジック活用元

- `HsContract::getSellingPriceTotal()`, `getTotalProfit()`, `getLandProfit()`, `getBuildingProfit()` 他
- `HsCustomOrder::getTotalSellingPrice()`, `getTotalProfit()` 他

### Enum・型

- `CustomOrderStatus::label()`, `badgeStyle()`
- `HousingPropertyStatus::label()`, `badgeStyle()`
- `HousingLandSourceType`

---

## 16. 次のステップ

1. この設計書をユーザーがレビュー → 承認
2. 実装計画（writing-plansスキル）へ移行し、`docs/superpowers/plans/` に詳細な実装プラン作成
3. 実装計画に従い、**デザインモック作成 → 承認 → 実装**の順で進める
