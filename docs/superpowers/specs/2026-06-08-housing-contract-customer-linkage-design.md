# 住宅事業 契約⇔ダッシュボード／顧客マスタ 連携 設計書

- 日付: 2026-06-08
- 対象: 住宅事業（建売 + 注文住宅）
- 起点: 「契約管理で契約を追加したがダッシュボードに出ない／顧客を追加したが顧客一覧に出ない」という調査依頼

## 1. 背景・調査結果

本番データ（`www3586.sakura.ne.jp`）を読み取り専用で確認し、以下を確定した。

| 区分 | 実データ | 現象 | 原因 |
|---|---|---|---|
| 注文住宅 CO-001 | 顧客「高木豊」/ status=contracted（引渡し前）/ contract_date=2026-06-14 | ダッシュボードに出ない | ダッシュボードは注文住宅を `status=delivered` のみ集計する仕様（建売は契約時点で集計）。**非対称が原因** |
| 建売 HsContract#1 | 顧客「今津正則」/ contract_date=2026-05-28 / property_id=12 | ダッシュボードに出る | 建売は契約日基準で正しく集計（調査時 count=1 で確認） |
| 買主マスタ | 「今津正則」1件のみ（5/26登録・housing・rank=C・正常） | 顧客一覧に出る | 一覧クエリで count=1 確認。データ・クエリは正常 |
| 顧客「高木豊」 | 買主マスタに**存在しない** | 顧客一覧に出ない | 注文住宅契約の `customer_name` は**テキスト**で、買主マスタ（顧客一覧）に**自動登録されない**仕様 |

**結論**: いずれもデータ消失やバグではなく、「システムの仕様」と「ユーザーの期待」のギャップ。

## 2. ゴール

1. **注文住宅も契約段階でダッシュボードに反映**する（建売と同じ契約時点計上に揃える）。
2. **契約を登録すれば、その顧客が必ず顧客一覧（買主マスタ）に載る**ようにし、連携漏れを構造的に無くす。

## 3. 既存の仕組み（流用できる資産）

- `HsContract`・`HsCustomOrder` には **`customer_id` カラムが既に存在**し fillable 済み（`app/Models/HsContract.php:17`, `app/Models/HsCustomOrder.php:22`）。
- 契約の**編集**画面には既に「買主マスタ紐付け」プルダウンがある（`resources/views/housing/contracts/edit-custom-order.blade.php:542-554`、edit-building も同様）。ただし**任意**で、その場で新規登録はできない。
- 契約一覧（`HsContractListController::index`）は注文住宅を `status ∈ [contracted, construction, completed, delivered]` かつ `contract_date` ありで抽出している。**ダッシュボードもこれに揃える**。
- 買主登録ロジックは `CustomerController::store` に既にある（`Buyer::create` + `addToDepartment` + アンケート）。クイック登録はこれを最小化して流用する。

## 4. ① ダッシュボード集計の修正（フェーズ1・先行リリース）

**対象**: `app/Http/Controllers/Housing/HousingDashboardController.php`（1ファイル）

### 4.1 `collectContractedItems()` の注文住宅クエリ

変更前（引渡し済みのみ・引渡日基準）:
```php
$orders = HsCustomOrder::with(['createdBy'])
    ->where('status', CustomOrderStatus::Delivered->value)
    ->whereNotNull('delivery_date');
if ($range) {
    $orders = $orders->whereBetween('delivery_date', [$range[0], $range[1]]);
}
```

変更後（契約以降・契約日基準。契約一覧と同一条件）:
```php
$contractedStatuses = [
    CustomOrderStatus::Contracted->value,
    CustomOrderStatus::Construction->value,
    CustomOrderStatus::Completed->value,
    CustomOrderStatus::Delivered->value,
];
$orders = HsCustomOrder::with(['createdBy'])
    ->whereIn('status', $contractedStatuses)
    ->whereNotNull('contract_date');
if ($range) {
    $orders = $orders->whereBetween('contract_date', [$range[0], $range[1]]);
}
```

### 4.2 `mapOrderToDto()`

- `contracted_date` を `delivery_date` → `contract_date` 基準に変更。
- `status_label` を「引渡し済み」固定 → 実ステータスの `label()`（契約／着工／完成／引渡し）。
- `status_style` を実ステータスの `badgeStyle()`。
- `contracted_date` が null の行は除外する既存ガードは維持（契約日 null は出さない）。

```php
$contractDate = $o->contract_date ? Carbon::parse($o->contract_date) : null;
$statusEnum = $o->status; // CustomOrderStatus にキャスト済み（tryFrom は使わない。Bug #22）
...
'status_label'    => $statusEnum ? $statusEnum->label() : '—',
'status_style'    => $statusEnum ? $statusEnum->badgeStyle() : '',
'contracted_date' => $contractDate,
```

### 4.3 波及

- KPI（件数・売上・原価・粗利・粗利率）、月次グラフは `contracted_date` を基準に集計しているため、上記変更で**自動的に契約日基準**になる。追加改修不要。
- 建売側ロジックは変更しない。
- 結果として「成約一覧」は契約一覧と件数が一致し、画面間の整合が取れる。

### 4.4 仕様変更の明示

- ダッシュボードの「成約」の定義が **建売=契約時点／注文住宅=引渡し時点** から、**両方=契約時点** に変わる。
- KPI の売上・粗利には**引渡し前（未完工）の注文住宅も含まれる**ようになる。これはユーザー合意済みの意図的変更。

## 5. ② 契約 ⇔ 顧客マスタ連携（フェーズ2）

### 5.1 買主クイック登録 API

- **ルート追加**: `POST /housing/customers/quick-store`（`department.access:housing` + `role:executive,manager`）、name=`housing.customers.quick-store`。
  - 既存の `routes/web.php` 顧客管理ブロック（1130-1178行付近）に追記。
- **コントローラ**: `CustomerController::quickStore(Request $request)`。
  - バリデーション: `last_name` required|max:50, `first_name` required|max:50, `last_name_kana` nullable, `first_name_kana` nullable, `acquired_date` required|date, `postal_code`/`prefecture`/`city`/`address_detail`/`phone` nullable。
  - `resolveDepartment()` で部署判定（housing）。
  - `Buyer::create(...)` + `$buyer->addToDepartment($department, acquired_date)`（rank はデフォルト C）。アンケートは作らない（クイック登録のため）。
  - 戻り値: `{ id, full_name, full_name_kana, prefecture, city }`（JSON）。
- 重複サジェスト: 既存 `checkDuplicate` を流用し、モーダル内で姓名一致を軽く警告（任意・ブロッキングしない）。

### 5.2 契約フォーム（新規登録）への買主選択 + モーダル

**対象フォーム**:
- `resources/views/housing/custom-orders/create.blade.php`（注文住宅 新規）
- `resources/views/housing/contracts/create.blade.php`（建売契約 新規）

**追加要素**:
- 「買主マスタ紐付け」プルダウン（`name="customer_id"`）。`<option>` は `@foreach($buyers ...)` で静的注入（Bug #16: x-for で option を作らない）。
- 「＋新規顧客を登録」ボタン → モーダル。
- モーダル項目: 姓・名（必須）、姓カナ・名カナ、取得日（必須・既定=今日）、郵便番号・都道府県・市区町村・住所・電話（任意）。
- 送信は `fetch`（CSRF ヘッダ付き）で `housing.customers.quick-store` へ。成功時、返ってきた買主をプルダウンに `<option>` 追加し選択状態にする。
- Alpine の注意点（CLAUDE.md Top traps）を順守: `x-data="fn()"` 形式、`<option>` は静的、IME Enter ガード、`:style` 統一、Vite未収録Tailwindクラスは inline style。

**コントローラ（create メソッド）**:
- `CustomOrderController::create()` と `ContractController::create()` に、買主リスト `$buyers = Buyer::ofDepartment('housing')->orderBy('last_name_kana')->get()` を渡す。

### 5.3 store の customer_id 保存 + 必須化

**対象**: `CustomOrderController::store()`、`ContractController::store()`

- バリデーションに追加: `'customer_id' => ['required', 'integer', 'exists:buyers,id']`。
  - 「選択 or 新規登録」のどちらでも、最終的に `customer_id` がフォームに入る（新規登録はモーダルAjaxで id をセット）。必須にすることで連携漏れを防ぐ。
- 保存時に `customer_name` を選択買主の `full_name` で上書き（整合性確保。テキスト手入力との不一致を防ぐ）。
  - 実装: store 内で `$buyer = Buyer::withTrashed()->find($validated['customer_id']); $validated['customer_name'] = $buyer->full_name;`
- `customer_name` のテキスト入力欄は表示用に残すが、`customer_id` 選択時は JS で自動補完し読み取り専用扱いとする（任意）。最小実装ではサーバー側上書きで足りる。

### 5.4 編集画面の必須化（②の一貫性）

**対象**: `HsContractListController::editBuilding/updateBuilding/editCustomOrder/updateCustomOrder`、`edit-building.blade.php`、`edit-custom-order.blade.php`

- 編集フォームの `customer_id` プルダウンにも「＋新規顧客を登録」モーダルを追加（5.2 と共通化）。
- `updateBuilding/updateCustomOrder` のバリデーションを `customer_id` nullable → **required** に変更。
- `customer_name` を選択買主名で上書き（5.3 と同様）。
- これにより、既存契約（高木豊・今津正則）も**次回編集時に**買主マスタへ紐付く。

### 5.5 共通化方針

- 新規顧客登録モーダル + Alpine ロジックは、4フォーム（create×2 / edit×2）で共通利用するため Blade partial 化する。
  - 例: `resources/views/housing/contracts/_buyer-select.blade.php`（プルダウン + モーダル + script）。
  - `@stack('scripts')` が無いレイアウトのため、script はインラインで partial に内包する（既存 edit フォームと同様）。

## 6. 既存データの扱い

- 既存契約 2件（今津正則 / 高木豊、いずれも `customer_id=null`）。
- ①（ダッシュボード）は `customer_id` に依存しないため**影響なし**。フェーズ1リリース直後、高木豊（注文住宅・契約段階）がダッシュボードに表示されるようになる。
- ②リリース後、これらを顧客一覧に出すには契約編集画面で「既存選択（今津は登録済）」または「新規登録（高木）」を行う。データ移行スクリプトは作らない（2件のみ・手動で足りる）。

## 7. エッジケース

| ケース | 扱い |
|---|---|
| 注文住宅で contract_date が null（商談〜見積り段階や未入力）| ダッシュボード・契約一覧とも除外（既存ガード維持）|
| 同名異人の買主 | プルダウンは `full_name` 表示。モーダル登録時に `checkDuplicate` で姓名一致を警告（非ブロッキング）|
| SoftDelete 済み買主が契約に紐付く | プルダウンは `ofDepartment` の現役のみ。編集時は現在の `customer_id` を `withTrashed` で必ず候補に含める（既存 edit ロジック踏襲、Bug #12）|
| `customer_id` と `customer_name` の不一致 | store/update でサーバー側が買主名で上書きし常に一致させる |
| クイック登録の二重送信 | 送信ボタンを送信中 disabled にする |

## 8. テスト方針

- worktree ではテスト実行不可（vendor が main repo への --no-dev symlink）。**マージ後に main repo で** `php artisan test` を実行する。
- フェーズ1（①）:
  - `php -l` で構文確認。
  - `php artisan tinker` でローカル疑似データ（注文住宅 status=contracted + contract_date）を入れ、`collectContractedItems` が拾うことを確認。
  - 本番反映後、ダッシュボードに高木豊（契約）が出ることを確認。
- フェーズ2（②）:
  - クイック登録 API の Feature テスト（買主 + buyer_departments 作成、JSON 応答）。
  - 契約 store で customer_id 必須・customer_name 上書きの確認。
  - `php artisan route:list` で新ルート確認、`view:cache` でフォームの Blade コンパイル確認（本番 500 予防、Bug #21）。

## 9. 実装順序

1. **フェーズ1（①）**: `HousingDashboardController` 修正 → worktree で実装 → `/commit` → main repo へ FF-merge → `./deploy.sh` → 本番確認。小規模・即効。
2. **フェーズ2（②）**: クイック登録API + 4フォーム + store/update 改修 → 別 worktree で実装 → 検証 → デプロイ。

各フェーズで個別に writing-plans → executing-plans を回す。

## 10. 影響ファイル一覧

**フェーズ1（①）**
- `app/Http/Controllers/Housing/HousingDashboardController.php`

**フェーズ2（②）**
- `routes/web.php`（quick-store ルート追加）
- `app/Http/Controllers/CustomerController.php`（`quickStore` 追加）
- `app/Http/Controllers/Housing/CustomOrderController.php`（create に $buyers、store に customer_id）
- `app/Http/Controllers/Housing/ContractController.php`（create に $buyers、store に customer_id）
- `app/Http/Controllers/Housing/HsContractListController.php`（edit×2 に $buyers は既存、update×2 を required 化）
- `resources/views/housing/contracts/_buyer-select.blade.php`（新規・共通 partial）
- `resources/views/housing/custom-orders/create.blade.php`
- `resources/views/housing/contracts/create.blade.php`
- `resources/views/housing/contracts/edit-building.blade.php`
- `resources/views/housing/contracts/edit-custom-order.blade.php`

## 11. 非対象（YAGNI）

- 既存2件の自動データ移行（手動で足りる）。
- 顧客名テキスト欄の完全廃止（互換のため当面残す）。
- 建売・注文住宅以外の事業（賃貸マンション等）への展開。
- アンケートのクイック登録（通常の顧客登録画面で行う）。
