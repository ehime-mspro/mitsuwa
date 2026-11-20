# 契約のある建売・注文住宅の土地を変えさせない Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 契約のある建売（契約の行がある）・注文住宅（保存済みのステータスが「契約」以降）の土地の紐付け 3 つ（種別・区画・仕入れ案件）を、3 つの保存の経路のどこからも変えられないようにする。

**Architecture:** 判定はモデルの共通の部品（trait `LocksLandAfterContract`）の 1 か所。3 つのコントローラは入力チェックを `Validator::make(...)->after(...)` にし、after のフックでその判定を呼んで `land_source_type` の入力エラーにする（ほかの誤りと一度に出る）。注文住宅の 2 画面は、契約済みなら土地を読み取り専用の表示＋hidden（今の値）にする。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade + Alpine.js 3 / PHPUnit（SQLite）

**Spec:** `docs/superpowers/specs/2026-11-20-housing-land-lock-design.md`

## Global Constraints

- 今と同じ紐付けなら通す（ほかの欄だけ直す保存・何も変えない保存は通る。Bug #130 の形を作らない）
- 黙って値を捨てない（違えば理由つきで断る。Bug #38 の形を作らない）
- 契約前の注文住宅・契約の無い建売の動きは変えない
- 文言（コピーは逐語）:
  - 建売: `この建売物件には契約があるため、土地の紐付け（種別・区画・仕入れ案件）は変えられません。`
  - 注文住宅: `この注文住宅は契約済み（ステータスが「契約」以降）のため、土地の紐付け（種別・区画・仕入れ案件）は変えられません。変えるときは、ステータスを「見積り」以前に戻して保存してから変えてください。`
  - 注文住宅の編集画面の案内: `契約済みのため、土地の紐付け（種別・区画・仕入れ案件）は変えられません。変えるときは、ステータスを「見積り」以前に戻して保存してから変えてください。`
  - 契約一覧から開く注文住宅の契約の編集画面の案内: `契約済みのため、土地の紐付け（種別・区画・仕入れ案件）は変えられません。変えるときは、注文住宅の画面でステータスを「見積り」以前に戻して保存してから変えてください。`
- テストの流し方は CLAUDE.md の「テストの流し方」（作業場所の cwd・`--display-phpunit-deprecations` を付ける）。`git stash` は使わない
- コミットの末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## Review Focus

1. 契約のある物件・注文住宅の編集画面を**何も変えずに**（または土地以外だけ直して）保存すると通る → Task 2・3・4 の「そのまま保存」のテスト
2. 土地の欄が**丸ごと無い**手組みの送信（建売は今、区画・仕入れ案件を空にしてしまう）→ Task 2 で断ることを固定
3. 保存済みの紐付けに**使わない欄の残り**（種別がお客様の土地なのに区画の id が残っている等）があっても、そのまま保存は通る → Task 1 の正規化のテスト
4. 注文住宅で**ステータスを「契約」より前に戻すのと同時に**土地を変える → 保存済みのステータスで判定して断る／戻すだけなら通って区画が販売中に戻る → Task 3
5. 契約一覧から開く契約の編集で、契約済みかつ**種別が空**の注文住宅をそのまま保存できる → Task 4

---

### Task 1: 判定の部品（trait）と 2 つのモデル

**Files:**
- Create: `app/Models/Concerns/LocksLandAfterContract.php`
- Modify: `app/Models/HsProperty.php`（trait を use・定数・`isLandLockedByContract()`）
- Modify: `app/Models/HsCustomOrder.php`（同上）
- Test: `tests/Feature/Housing/Screens/LandLockTest.php`（新規。`HousingScreenTestCase` を継承）

**Interfaces:**
- Produces:
  - `trait App\Models\Concerns\LocksLandAfterContract`
    - `abstract public function isLandLockedByContract(): bool`
    - `public function refusesLandChange(array $input): bool` — `$input` は送られた値そのまま（`$request->all()`）。キー `land_source_type` / `re_project_lot_id` / `re_procurement_id`（無いキーは null とみなす）。固定されていなければ false。固定されていれば、送られた紐付けと保存済みの紐付けを同じ形に直して比べ、違えば true
    - 同じ形: `[種別 or null, 種別が project_lot のときだけ区画 id の文字, 種別が procurement のときだけ仕入れ案件 id の文字]`。種別は空文字・文字でない値を null。id は int なら文字に、数字だけの文字は先頭の 0 を落とした文字に、ほかの空でない文字はそのまま、それ以外は null
  - `HsProperty::LAND_LOCKED_BY_CONTRACT`（建売の文言）・`isLandLockedByContract()` = 契約の行があるか（`contract()->exists()`）
  - `HsCustomOrder::LAND_LOCKED_BY_CONTRACT`（注文住宅の文言）・`isLandLockedByContract()` = **保存済み**（`getOriginal('status')`）のステータスが `isContractedOrLater()`

- [ ] **Step 1: 失敗するテストを書く**（`LandLockTest`。区画は `$this->lot()`・建売は `$this->property()`・契約は `$this->contract()`）

```php
public function test_a_property_without_a_contract_never_refuses(): void
// 契約の無い区画の建売で refusesLandChange(['land_source_type' => 'procurement', 're_procurement_id' => '9']) === false

public function test_a_property_with_a_contract_refuses_only_a_different_link(): void
// 区画 A の建売＋契約。false: ['project_lot', (string) A]・['project_lot', A（int）]・['project_lot', '0'.A]・
//   ['project_lot', A, 're_procurement_id' => '9']（使わない欄は見ない）
// true: ['project_lot', B]・['procurement', 're_procurement_id' => (string) 仕入れ案件]・[]（キーが無い）・['land_source_type' => '']・['customer_land']

public function test_leftover_ids_of_the_saved_link_are_ignored(): void
// HsCustomOrder::create で status contracted・land_source_type customer_land・re_project_lot_id = A（残り）を作る
// refusesLandChange(['land_source_type' => 'customer_land']) === false

public function test_a_custom_order_is_locked_by_its_saved_status(): void
// 区画 A の注文住宅。consultation / design / estimation では ['project_lot', B] でも false。
// contracted / construction / completed / delivered では true。
// estimation で保存した注文住宅に $order->status = 'contracted' を（保存せず）入れても false（保存済みで見る）
```

- [ ] **Step 2: 落ちることを確かめる** — `…phpunit tests/Feature/Housing/Screens/LandLockTest.php` → `Call to undefined method …refusesLandChange()` で FAIL
- [ ] **Step 3: trait と 2 つのモデルを実装する**（上の Interfaces どおり。種別の値は `HousingLandSourceType::ProjectLot->value` / `Procurement->value` で比べる）
- [ ] **Step 4: 通ることを確かめる** — 同じコマンドで PASS
- [ ] **Step 5: コミット** — `feat: 契約のある建売・注文住宅の土地の紐付けを判定する部品を足す`

---

### Task 2: 建売の編集の保存で断る

**Files:**
- Modify: `app/Http/Controllers/Housing/PropertyController.php`（`validateProperty(Request $request, ?HsProperty $current = null): array` を `Validator::make($request->all(), 規則, messages, attributes)->after(...)->validate()` に。after は `$current?->refusesLandChange($request->all())` が true なら `$validator->errors()->add('land_source_type', HsProperty::LAND_LOCKED_BY_CONTRACT)`。`update()` が `$property` を渡す。`store()` は渡さない）
- Test: `tests/Feature/Housing/Screens/LandLockTest.php`

**Interfaces:**
- Consumes: Task 1 の `refusesLandChange()`・`HsProperty::LAND_LOCKED_BY_CONTRACT`

- [ ] **Step 1: 失敗するテストを書く**（描いた編集画面のフォーム `browserForm($this->htmlOf(edit), 'action="' . route('housing.properties.update', $p) . '"', 'housingPropertyForm')` を元にする。手組みの送信は `$form['fields']` を直接書き換える＝画面には無い送信であることをコメントに書く）

```php
public function test_a_sold_property_is_saved_as_shown(): void
// 区画 A（sold）の建売＋契約。notes だけ打って送る → 帯「建売物件「HS-001」を更新しました。」・区画 A のまま・notes が入る

public function test_a_sold_property_refuses_another_lot(): void
// 同じ。re_project_lot_id を区画 B に書き換えて送る → assertErrorItem(HsProperty::LAND_LOCKED_BY_CONTRACT)・区画 A のまま・
// 区画 A は sold・区画 B は on_sale のまま

public function test_a_sold_property_refuses_a_request_without_the_land_fields(): void
// land_source_type と re_project_lot_id を unset して送る → 同じ文で断る・区画 A のまま（今は空にしていた）

public function test_a_sold_property_refuses_another_land_type(): void
// land_source_type を procurement・re_procurement_id を仕入れ案件にして送る → 同じ文で断る

public function test_the_lock_error_comes_with_the_other_errors(): void
// property_name を空・re_project_lot_id を B にして送る → 「物件名は必須です。」と建売の文の 2 つが同じ画面に出る

public function test_a_property_without_a_contract_can_still_change_its_land_by_request(): void
// 契約の無い建売で re_project_lot_id を B に書き換えて送る → 更新され区画 B になる（今どおり）
```

- [ ] **Step 2: 落ちることを確かめる** — `--filter 'sold_property|lock_error|without_a_contract'` → 断るべき 4 本が「入力エラーに「…」が出ていない」で FAIL（そのまま保存・契約の無い建売の 2 本は PASS のまま＝守りのテスト）
- [ ] **Step 3: 実装する**（Files のとおり。規則・messages・attributes の中身は変えない）
- [ ] **Step 4: 通ることを確かめる** — `LandLockTest` と `PropertyScreensTest`・`AmountLimitTest`・`ArrayInputTest`（住宅）が PASS
- [ ] **Step 5: コミット** — `fix: 契約のある建売の土地の紐付けを保存で変えさせない`

---

### Task 3: 注文住宅の編集（保存で断る＋契約済みなら土地を読み取り専用に）

**Files:**
- Modify: `app/Http/Controllers/Housing/CustomOrderController.php`（`validateOrder(Request $request, bool $creating = false, ?HsCustomOrder $current = null): array` を Task 2 と同じ形に。文は `HsCustomOrder::LAND_LOCKED_BY_CONTRACT`。`update()` が `$customOrder` を渡す）
- Modify: `resources/views/housing/custom-orders/_form.blade.php`
  - `@php` に `$landLocked = $isEdit && $o->isLandLockedByContract();`
  - `$landLocked` のとき: 土地種別のラジオと 3 つの選択（分譲地区画・仕入れ案件）の代わりに、建売の `_form` の読み取り専用の箱と同じ見た目（種別のラベル・分譲地／仕入れ案件へのリンク・`紐付け先（変更不可）`）＋ hidden `land_source_type`（保存済み）・`re_project_lot_id`（種別が project_lot のとき保存済み。関係が無くても出す）・`re_procurement_id`（procurement のとき）＋ Global Constraints の案内文
  - `$landLocked` のとき JS の `landSourceType` の初期値は保存済み（`old()` を見ない）。お客様の土地の箱・所在地以下は今のまま
- Test: `tests/Feature/Housing/Screens/LandLockTest.php`

**Interfaces:**
- Consumes: Task 1 の `refusesLandChange()`・`isLandLockedByContract()`・`HsCustomOrder::LAND_LOCKED_BY_CONTRACT`

- [ ] **Step 1: 失敗するテストを書く**（`browserForm(…, 'action="' . route('housing.custom-orders.update', $o) . '"', 'customOrderForm')`）

```php
public function test_a_contracted_order_shows_its_land_read_only_and_saves_as_shown(): void
// 区画 A（sold）・status contracted の注文住宅の編集画面:
//   HTML に type="radio" の name="land_source_type" が無い・案内文（画面の案内）が出る・
//   fields の land_source_type = 'project_lot'・re_project_lot_id = (string) A
//   notes だけ打って送る → 帯「注文住宅案件「CO-001」を更新しました。」・区画 A のまま・区画 A は sold

public function test_a_contracted_order_refuses_another_lot_or_customer_land(): void
// dataProvider ではなく 2 回: re_project_lot_id を B／land_source_type を customer_land にして送る →
//   assertErrorItem(HsCustomOrder::LAND_LOCKED_BY_CONTRACT)・区画 A のまま・A は sold・B は on_sale

public function test_turning_the_status_back_and_changing_the_land_at_once_is_refused(): void
// status を estimation・re_project_lot_id を B にして送る → 同じ文で断る・status は contracted のまま

public function test_turning_the_status_back_releases_the_lot_and_unlocks_the_land(): void
// status だけ estimation にして送る → 更新・区画 A は on_sale。編集画面を開き直すと type="radio" の
//   name="land_source_type" が描かれ、JS で区画 B を選んで（既存の test_an_order_on_a_project_lot… と同じ手順）送ると区画 B になる

public function test_an_order_before_the_contract_still_changes_its_land(): void
// status estimation・区画 A の注文住宅で、画面の JS で区画 B を選んで送る → 区画 B になる
```

- [ ] **Step 2: 落ちることを確かめる** — `--filter 'contracted_order|status_back|before_the_contract'` → 読み取り専用・断る・同時に変えるの 3 本が FAIL（戻すだけ・契約前の 2 本は PASS のまま）
- [ ] **Step 3: 実装する**（Files のとおり）
- [ ] **Step 4: 通ることを確かめる** — `LandLockTest`・`CustomOrderScreensTest`（`:selected` の構造のテストは 6 のまま）・`JsEmbeddingTest`・`OldInputRuleCoverageTest`・`JsEchoClassificationTest` が PASS
- [ ] **Step 5: コミット** — `fix: 契約済みの注文住宅の土地の紐付けを編集で変えさせない`

---

### Task 4: 契約一覧から開く注文住宅の契約の編集

**Files:**
- Modify: `app/Http/Controllers/Housing/HsContractListController.php`（`updateCustomOrder` の `$request->validate([...], messages)` を `Validator::make($request->all(), 規則, messages)->after(...)->validate()` に。after は `$hsCustomOrder->refusesLandChange($request->all())` で `HsCustomOrder::LAND_LOCKED_BY_CONTRACT`。`land_source_type` の規則を `['nullable', Rule::requiredIf(! $hsCustomOrder->isLandLockedByContract()), Rule::in([...今の 3 つ...])]` に）
- Modify: `resources/views/housing/contracts/edit-custom-order.blade.php`（`$landLocked = $hsCustomOrder->isLandLockedByContract();`。固定のとき: 「土地種別」のカードはラジオの代わりにラベル＋hidden `land_source_type`（保存済み・空なら空）、「建築土地」のカードは選択の代わりに今の分譲地区画／仕入れ案件の名前とリンク＋hidden、x-data の `landSourceType` は保存済み（`'procurement'` の既定を使わない）、上の帯の下に Global Constraints の案内文）
- Modify: `tests/Feature/Housing/Screens/ContractScreensTest.php`（`test_a_custom_order_contract_on_company_land_without_a_link_is_refused` の注文住宅を `status => 'estimation'` にする＝固定の無い注文住宅で D1 の断りを見続ける。意味が変わることをテストのコメントに書く）
- Test: `tests/Feature/Housing/Screens/LandLockTest.php`

**Interfaces:**
- Consumes: Task 1・Task 3 と同じ

- [ ] **Step 1: 失敗するテストを書く**（`composedForm($html, route('housing.contracts.update-custom-order', $o), 'customOrderEditForm', null, ['datePicker' => '', 'buyerSelect' => ''])`）

```php
public function test_the_contract_edit_shows_the_land_read_only_and_saves_as_shown(): void
// 区画 A（sold）・contracted・contract_date あり・customer_id ありの注文住宅: type="radio" の name="land_source_type" が無い・
// 案内文（契約の編集の案内）が出る・fields の land_source_type/re_project_lot_id が保存済み。
// building_contract_price を変えて送る → 帯「契約を更新しました。」・区画 A のまま

public function test_the_contract_edit_refuses_another_land(): void
// re_project_lot_id を B に書き換えて送る → assertErrorItem(HsCustomOrder::LAND_LOCKED_BY_CONTRACT)・区画 A のまま

public function test_the_contract_edit_saves_a_contracted_order_without_a_land_type(): void
// land_source_type が null の contracted の注文住宅を、画面のまま送る → 帯「契約を更新しました。」（今は「土地種別は必須です」）

public function test_the_contract_edit_of_an_order_before_the_contract_can_change_the_land(): void
// estimation（contract_date あり）の注文住宅を URL で開き、JS で landSourceType = 'customer_land' にして送る → customer_land になる
```

- [ ] **Step 2: 落ちることを確かめる** — `--filter 'contract_edit'` → 読み取り専用・断る・種別が空の 3 本が FAIL（契約前の 1 本は PASS のまま）
- [ ] **Step 3: 実装する**（Files のとおり）
- [ ] **Step 4: 通ることを確かめる** — `LandLockTest`・`ContractScreensTest` が PASS
- [ ] **Step 5: コミット** — `fix: 契約の編集でも注文住宅の土地の紐付けを変えさせない`

---

### Task 5: 全件・変異・実ブラウザ・記録

- [ ] **Step 1: 全件** — CLAUDE.md の「テストの流し方」に `--display-phpunit-deprecations` → `OK`・警告なし。コンパイル済みビューの lint（`view:cache` → `php -l` 全件 → `view:clear`）→ INVALID 0
- [ ] **Step 2: 変異**（docs/RULES.md Bug #44 の作法。先にコミット・各変異の前に `git status --porcelain` が空・`git diff --stat` 非空で着弾・落ちた理由の文まで突き合わせる。表は流す前に書く。最低限: 各モデルの `isLandLockedByContract` を false／true に・`getOriginal` を `$this->status` に・正規化の「使わない欄を見ない」を外す・id の文字化を外す・3 つの after のフックを 1 つずつ外す・横断画面の `requiredIf` を `required` に戻す・2 画面の読み取り専用の分岐を外す・カナリア）
- [ ] **Step 3: 実ブラウザ**（使い捨ての SQLite ＋ `artisan serve`・Playwright・画面が見えている状態）: 契約済みの注文住宅の編集と契約の編集が読み取り専用で、そのまま保存できる・375px と 1440px で `main` の横スクロール 0・コンソールのエラー 0
- [ ] **Step 4: 記録** — `docs/RULES.md` に Bug #142（症状・原因・直し方・変異の結果）・`docs/BACKLOG.md` に節（`利用者の判断を待っていた 1〜3` の節の範囲外の ④ に → を足す）・`CLAUDE.md` の件数 141 → 142・この計画の末尾に実測記録 → コミット `docs: 契約のある建売・注文住宅の土地の固定を Bug #142 として記録する`

範囲外（記録だけ）: 登録のときに販売済の区画を選べること・ほぼ同時の 2 回の登録・建売の削除で区画の販売済が残ること（`PropertyController::destroy` は区画を戻さない）・判断待ちの ⑤〜⑧

---

## 実測記録（2026-11-20）

- Task 1〜4: どれも計画の Expected どおり（落ちるべきテストが狙った理由で赤 → 直して緑。守りのテストは直す前から緑）
- 計画から変えたこと（台帳の Ruling）:
  - `JsEmbeddingTest` は `tests/Feature/Housing/Screens/` にあった（計画は `tests/Feature/` と書いた）。`tests/Feature/Housing` に含めて流した
  - Task 4 に `test_the_contract_edit_of_an_order_without_a_land_type_survives_a_request_without_the_field` と種別の `?? null` を足した（固定・種別が空・欄が無い送信が 500。守りを外して赤を確かめた）
  - 意味が変わった既存テスト 3 本: `ContractScreensTest::test_a_custom_order_contract_on_company_land_without_a_link_is_refused`（計画どおり契約前に）・`JsEmbeddingTest::test_the_contract_edit_screens_survive_a_typed_value_after_an_input_error`（契約前に。Bug #76 の逃がしは固定の無い注文住宅でしか入力が JS に届かない）・`HousingContractBuyerRankTest`（登録の土地を契約の編集で送る土地＝お客様の土地にそろえた。目的は買主のランク）
- 全件: OK (5341 tests, 56334 assertions)・警告なし・3 分 38 秒・ピーク 173.50 MB（着手前 5321 本）
- コンパイル済みビュー: 323 本 / INVALID 0
- 変異: 23 通り（カナリア C0 を含む）すべて期待どおり。1 回目は M19 だけ DIFF（`AmountLimitTest::test_an_amount_over_the_limit_is_refused_on_the_contract_edit_screens` も同じ欄を fill して落ちる＝表が狭かった）→ 表を直して流し直し MATCH。M18 は予定どおり緑（表示だけの死角）。表と結果は scratchpad の `mutate/`（`specs_housing_land_lock.py`・`results-housing-land-lock.txt`・1 回目は `.round1.txt`）
- 実ブラウザ（使い捨ての SQLite・`artisan serve`・Playwright）: 契約済みの注文住宅（区画 3・販売済）の編集と契約の編集で、ラジオ・選択欄 0・読み取り専用の表示と案内・送る値は `project_lot` / `3`・フォームの検査が通る・保存できて区画 3 のまま販売済のまま・帯「契約を更新しました。」。見積りの注文住宅はラジオ 3・区画の選択あり。1440px／375px で `main` の横スクロール 0・枠からのはみ出し 0・コンソールのエラー／警告 0
