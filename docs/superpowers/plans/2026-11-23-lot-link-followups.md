# 契約済みの建売の削除で区画を戻す ／ 区画を足すときつなぐ変更の残り Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 契約のある建売を削除したら区画を販売中に戻し、区画を足すときにつなぐ変更（Bug #146）の残り 4 つ（同時の保存・指紋・開いていた編集画面・ステータスの誤り）を直す。

**Architecture:** A は建売の契約の削除と同じ処理を `HsProperty::releaseLots()` にまとめ、建売の削除からも呼ぶ。B1・B2・B4 は `ProcurementLotLinks` と `ProcurementLotController::store()` の中だけで直す。B3 は trait `LocksLandAfterContract` に「保存済みの区画を補う」判定を足し、土地を変えられない 3 つの保存の経路が入力チェックの前に使う。不動産の契約の編集は必須の文だけを変える。

**Tech Stack:** Laravel 12 / PHP 8.3 / PHPUnit（SQLite）/ Blade + Alpine（画面のテストは node の vm で描いたフォームを組む既存の道具）

**Spec:** docs/superpowers/specs/2026-11-23-lot-link-followups-design.md

## Global Constraints

- DB 変更・ルート変更・新しい PHP クラス・依存の変更はしない（本番反映は `./deploy.sh` だけ）
- 作業場所 `/Users/masanori/site/manage/.claude/worktrees/lot-link-followups`（枝 `lot-link-followups`）。`.env` を作らない
- テストは CLAUDE.md の流し方: `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit`（1 本だけなら末尾にファイルか `--filter`）。全件は `--display-phpunit-deprecations` を付けて警告 0 を見る
- 画面から送るテストは描いた画面のフォームを分解して送る（Bug #47）。入力エラーの期待は 1 件の全文で見る（Bug #49。`assertSessionHas*()` を呼ばずに描いた画面で見る）
- 文（逐語）:
  - B1・B2・B4 の断り: 今の `ProcurementLotController::LINK_STALE`（「区画を選んでいない契約・建売物件・注文住宅が、画面を開いたあとで変わりました。紐づけるものを確かめて、もう一度登録してください。」）
  - B3 の不動産の契約: `ReContractController::LOT_HELD` = `区画を選んでください。この契約は今、区画（:number号地）に紐づいています（画面を開いたあとで紐づけられた可能性があります）。`
- コミットは Conventional Commits・日本語の件名・本文の最後に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`（自分の文脈の Co-Authored-By 行を使う）。`git stash` は使わない。`#[DataProvider]` の属性を使う（doc-comment の `@dataProvider` は使わない）

## Review Focus

1. 契約の無い建売を削除しても、区画のステータスは動かない（今のまま）→ Task 1 のテスト `test_deleting_a_property_without_a_contract_leaves_the_lot_status`
2. 建売の削除の途中で落ちたら、区画も販売中に戻らない（1 つのトランザクション）→ Task 1 の `test_a_failed_delete_leaves_the_lot_sold`
3. B3 の補いは、送られた仕入れ案件が保存済みと違う送信・区画を送った送信には効かない（手で組んだ送信で別の土地の区画を残さない）→ Task 3 の `test_lot_kept_for_only_fills_an_empty_lot_on_the_same_land`
4. B3 で区画を補ったあとも、土地原価は保存済みの値のまま（紐付けが同じとみなされる。Bug #143 の決まり）→ Task 3 の `test_a_stale_property_edit_saves_and_keeps_the_linked_lot` で `land_cost` も見る
5. B1 のつなぐ書き込みは、持ち主がほかの土地へ付け替えられていたら 0 行（ほかの土地の契約に区画を書かない）→ Task 2 の `test_link_writes_nothing_when_the_owner_moved_or_is_already_linked`

---

### Task 1: 契約のある建売を削除したら区画を販売中に戻す（A）

**Files:**
- Modify: `app/Models/HsProperty.php`（`releaseLots()` を足す）
- Modify: `app/Http/Controllers/Housing/ContractController.php`（`destroy()` が `releaseLots()` を呼ぶ。私的メソッド `updateLotStatusOnUnsold()` は消す）
- Modify: `app/Http/Controllers/Housing/PropertyController.php:263-284`（`destroy()`）
- Create: `tests/Feature/Housing/Screens/PropertyDeleteLotReleaseTest.php`

**Interfaces:**
- Produces: `HsProperty::releaseLots(): void` — その建売の土地の区画を `ReProcurementLot::markOnSale()`、分譲地の区画（`re_project_lot_id`）を販売中にして `$lot->project?->syncStatusFromLots()`。中身は今の `ContractController::updateLotStatusOnUnsold()` と同じ

- [ ] **Step 1: 失敗するテストを書く**（`PropertyDeleteLotReleaseTest extends HousingScreenTestCase`。削除は建売の詳細画面が描いた `action="{route('housing.properties.destroy', $property)}"` のフォーム（`DELETE`）を `parseForm()` で掴んで送る）

```php
public function test_deleting_a_sold_property_puts_its_land_lot_back_on_sale(): void
{
    // 土地（status 'sold'）・区画 1 つ（'sold'）・その区画の建売 ＋ 建売の契約（$this->contract($property, $this->buyer())）
    // 詳細画面の削除フォームを送る → 一覧へ転送
    $this->assertSame(['on_sale', 'selling'], [$lot->fresh()->status->value, $land->fresh()->status->value]);
    $this->assertNull(HsProperty::find($property->id));
}

public function test_deleting_a_sold_property_puts_its_project_lot_back_on_sale(): void
{
    // 分譲地（status 'sold_out'）・区画 1 つ（'sold'）・その区画の建売（land_source_type 'project_lot'）＋ 契約
    $this->assertSame(['on_sale', 'selling'], [$lot->fresh()->status->value, $project->fresh()->status->value]);
}

public function test_deleting_a_property_without_a_contract_leaves_the_lot_status(): void
{
    // 区画 'sold'（手で成約にした形）・その区画の建売（契約なし）→ 削除しても 'sold' のまま
}

public function test_a_failed_delete_leaves_the_lot_sold(): void
{
    // 1 つ目と同じデータ。HsProperty::deleting(fn () => throw new \RuntimeException('削除の途中で落ちた'));
    // $this->withoutExceptionHandling() で送り、RuntimeException を try/catch で受ける
    $this->assertSame('sold', $lot->fresh()->status->value);
    $this->assertNotNull(HsProperty::find($property->id));
}
```

- [ ] **Step 2: 流して落ちることを確かめる** — Run: `…phpunit tests/Feature/Housing/Screens/PropertyDeleteLotReleaseTest.php` / Expected: 1・2 本目が `'sold'` のまま、4 本目が区画が `'on_sale'` でないので緑（今は区画を動かさない）＝ 1・2 本目だけ FAIL

- [ ] **Step 3: 直す** — `HsProperty::releaseLots()` を足し、`ContractController::destroy()` は `$property->releaseLots()` を呼ぶ（`updateLotStatusOnUnsold()` を消す。`updateLotStatusOnSold()` は残す）。`PropertyController::destroy()` は、ファイルの物理削除のあとを `DB::transaction()` で包み、その中で `if ($property->contract()->exists()) { $property->releaseLots(); }` → 工程の削除 → `$property->delete()`（今のコメントは残す。区画を戻すのは削除の前＝連鎖削除で契約が消える前に契約の有無を見るため、とコメントに 1 行）

- [ ] **Step 4: 流して通ることを確かめる** — 同じファイル ＋ 建売の契約の削除の既存テスト: `…phpunit tests/Feature/Housing` / Expected: PASS

- [ ] **Step 5: コミット** — `fix: 契約のある建売を削除したら土地と分譲地の区画を販売中に戻す`

---

### Task 2: 区画を足すときの同時の保存・指紋・ステータスの誤り（B1・B2・B4）

**Files:**
- Modify: `app/Support/ProcurementLotLinks.php`（`link()`・`fingerprint()`・クラスの説明）
- Modify: `app/Http/Controllers/RealEstate/ProcurementLotController.php`（`store()`・`check()` の after）
- Test: `tests/Feature/RealEstate/ProcurementLotClaimsTest.php`・`tests/Feature/RealEstate/Screens/ProcurementLotLinkScreensTest.php`

**Interfaces:**
- Produces: `ProcurementLotLinks::link(ReProcurementLot $lot, ReContract|HsProperty|HsCustomOrder $owner): int` — 書いた行の数。条件は「その id」「持ち主の区画の列が空」「持ち主の土地がその区画の土地」（契約は `procurement_id`、住宅事業は `re_procurement_id` ＋ `land_source_type` が `procurement`）
- Produces: `ProcurementLotLinks::fingerprint(array $candidates): string` — 候補ごとに `キー=ステータスの値`（例 `contract:8=sold`・`property:3=on_sale`）を並びのまま「,」でつなぐ。候補が無ければ ''

- [ ] **Step 1: 失敗するテストを書く**
  - `ProcurementLotClaimsTest::test_candidates_carry_the_key_and_the_label` の指紋の期待を `'contract:' . $contract->id . '=sold,property:' . $property->id . '=on_sale,order:' . $order->id . '=sold'` に直す（建売は契約なし＝販売中）
  - `ProcurementLotClaimsTest` に `test_link_writes_nothing_when_the_owner_moved_or_is_already_linked`（ほかの土地の区画で呼ぶと 0・すでに区画がある持ち主は 0・どちらも列は変わらない・ふつうは 1）
  - `ProcurementLotLinkScreensTest` の 111 行・203 行の指紋の期待を新しい形に直す
  - `ProcurementLotLinkScreensTest` に 3 本:

```php
/** B1: 開いたフォームを送るあいだに、ほかの保存が同じ契約をほかの区画につないだ */
public function test_a_link_taken_by_another_save_in_between_is_refused(): void
{
    // 土地・区画を選んでいない契約 1 件。追加のフォームを掴む
    // DB::listen: 号地の重なりの確かめ（SQL に "re_procurement_lots" と "lot_number" を含む select）の直後に 1 回だけ、
    //   DB::table('re_procurement_lots') へ 5 号地を insert し、DB::table('re_contracts') の procurement_lot_id をその id にする
    //   （入力チェックはトランザクションの外なので、ほかの要求の保存を真似られる。Bug #102 の形）
    // 送る → 区画の欄へ転送・assertErrorItem(STALE)
    $this->assertSame([5], ReProcurementLot::pluck('lot_number')->all()); // 足そうとした区画は作られていない
    $this->assertSame($otherLotId, $contract->fresh()->procurement_lot_id);
}

/** B2: 開いたあとで建売に契約が付いた（画面は「販売中」と出していた） */
public function test_a_contract_added_to_a_building_after_opening_is_refused(): void
{
    // 土地・契約の無い建売 1 件（$this->building($land, 'HS-001', false)）。追加のフォームを掴む
    // HsContract::create([...建売の契約...]) してから送る → assertErrorItem(STALE)・区画 0 件・建売の区画は空
}

/** B4: 開いたあとで候補が 0 件になった（開いた画面にステータスの欄は無かった） */
public function test_a_form_opened_with_owners_does_not_ask_for_the_status(): void
{
    // 'gone' と同じ形（契約を消してから送る）
    $this->assertErrorItem($html, self::STALE);
    $this->assertStringNotContainsString(e(trans('validation.required', ['attribute' => 'ステータス'])), $html);
}
```

- [ ] **Step 2: 流して落ちることを確かめる** — Run: `…phpunit tests/Feature/RealEstate/ProcurementLotClaimsTest.php tests/Feature/RealEstate/Screens/ProcurementLotLinkScreensTest.php` / Expected: 指紋の 3 か所・link の戻り値・B1（区画が 2 つできる）・B2（成約で保存される）・B4（ステータスの文が出る）が FAIL

- [ ] **Step 3: 直す** — `link()`・`fingerprint()` を Interfaces のとおりに。`store()` はトランザクションの中で `link()` が 1 でなければ `throw ValidationException::withMessages(['lot_link' => self::LINK_STALE])->redirectTo(self::back($procurement)->getTargetUrl());`（投げると区画の作成ごと巻き戻る）。`check()` の after で `LINK_STALE` を足すときに `$validator->errors()->forget('status')` も呼ぶ。クラスの説明の「候補のキー」の行を新しい指紋の形に直す

- [ ] **Step 4: 流して通ることを確かめる** — Run: `…phpunit tests/Feature/RealEstate` / Expected: PASS

- [ ] **Step 5: コミット** — `fix: 区画を足すときの同時の保存・指紋・ステータスの誤りを直す`

---

### Task 3: 土地を変えられない編集画面は、開いたあとでつないだ区画を残して保存する（B3 の住宅事業）

**Files:**
- Modify: `app/Models/Concerns/LocksLandAfterContract.php`（`lotKeptFor()` を足す）
- Modify: `app/Http/Controllers/Housing/PropertyController.php:211-213`（`update()`）
- Modify: `app/Http/Controllers/Housing/CustomOrderController.php:188-192`（`update()`）
- Modify: `app/Http/Controllers/Housing/HsContractListController.php:369-380`（`updateCustomOrder()`）
- Create: `tests/Feature/Housing/Screens/StaleLotLinkEditTest.php`

**Interfaces:**
- Produces: `LocksLandAfterContract::lotKeptFor(array $input): ?int` — 補う区画の id か null。返すのは次のすべてを満たすときだけ: 保存済みの種別が `procurement`・保存済みの区画がある・送られた種別が `procurement`・送られた仕入れ案件が保存済みと同じ（`idText()` で比べる）・送られた区画が null か ''
- 呼ぶ側: 建売の更新はいつも、注文住宅の 2 つの更新は `isLandLockedByContract()` のときだけ、入力チェックの前に `$request->merge(['re_procurement_lot_id' => $kept])`

- [ ] **Step 1: 失敗するテストを書く**（`StaleLotLinkEditTest extends HousingScreenTestCase`。どれも「区画の無い土地（`landProcurement()`）に区画を選んでいない持ち主 → 編集画面のフォームを先に組む（notes などを書き換える）→ 不動産の詳細の追加のフォーム（`id="lot-create-form"`）を送って区画を足す（その持ち主につながる）→ 先に組んだフォームを送る」）

```php
public function test_a_stale_property_edit_saves_and_keeps_the_linked_lot(): void
{
    // 契約の無い建売・土地原価は自動（is_land_cost_manual 0・land_cost 30000000）
    // 編集フォーム（housingPropertyForm）に notes '外構は別途' → 区画を足す → 送る
    // 詳細へ転送・成功の帯
    $this->assertSame([$lot->id, '外構は別途', 30000000], [$property->fresh()->re_procurement_lot_id, $property->fresh()->notes, $property->fresh()->land_cost]);
}

public function test_a_stale_edit_of_a_sold_property_saves(): void           // 建売 ＋ 建売の契約。区画は 'sold' のまま
public function test_a_stale_edit_of_a_contracted_order_saves(): void         // 注文住宅 'contracted'（customOrderEditForm）
public function test_a_stale_contract_edit_of_a_contracted_order_saves(): void // 契約一覧から開く契約の編集（composedForm 'customOrderEditForm'。LandLockTest::contractEditForm と同じ組み方）

/** 見積りの注文住宅は区画を選べる画面なので補わない（空にすると今どおり断る） */
public function test_an_estimate_order_that_clears_its_lot_is_still_refused(): void
{
    // 区画のある土地・区画 L を選んだ見積りの注文住宅。編集フォームの re_procurement_lot_id を '' にして送る
    $this->assertErrorItem($html, '区画を選んでください。');
    $this->assertSame($lot->id, $order->fresh()->re_procurement_lot_id);
}

#[DataProvider('keptCases')]
public function test_lot_kept_for_only_fills_an_empty_lot_on_the_same_land(array $input, bool $kept): void
// 保存済み: 種別 procurement・仕入れ案件 A・区画 L の建売。
// 補う: [procurement, A, null]・[procurement, A, '']・[procurement, '0' . A, null]（先頭の 0）
// 補わない: [procurement, B, null]・[procurement, A, L2]・[procurement, A, ['x']]・[project_lot, …]・[customer_land, …]
// 保存済みの区画が空の建売（#21 の形）は、何を送っても null
```

- [ ] **Step 2: 流して落ちることを確かめる** — Run: `…phpunit tests/Feature/Housing/Screens/StaleLotLinkEditTest.php` / Expected: 補う 4 本が FAIL（建売は「区画を選んでください。」、契約のある建売・契約済みの注文住宅は「土地の紐付けは変えられません」の文で断られる）・`lotKeptFor` が無い・見積りの 1 本は PASS

- [ ] **Step 3: 直す** — `lotKeptFor()` を trait に足し（説明に、土地を変えられない画面から区画が空で届くのは区画を足す前に開いた画面だけ、と 1 行）、3 つの更新の入口で入力チェックの前に呼ぶ

- [ ] **Step 4: 流して通ることを確かめる** — Run: `…phpunit tests/Feature/Housing` / Expected: PASS（`LandLockTest`・`ProcurementLotPropertyTest`・`ProcurementLotCustomOrderTest` も緑のまま）

- [ ] **Step 5: コミット** — `fix: 土地を変えられない編集画面は開いたあとでつないだ区画を残して保存する`

---

### Task 4: 不動産の契約の編集は、開いたあとでつないだ区画を文で知らせる（B3 の不動産）

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ReContractController.php`（定数 `LOT_HELD`・`validateContract()` の必須の文）
- Test: `tests/Feature/RealEstate/Screens/ContractProcurementLotTest.php`

**Interfaces:**
- Produces: `ReContractController::LOT_HELD`（Global Constraints の文）。`procurement_lot_id.required` の文を、`$current` があり・保存済みの `procurement_id` が選んだ案件と同じ・保存済みの `procurement_lot_id` がある、のときだけ `strtr(self::LOT_HELD, [':number' => その区画の lot_number])` にする（ほかは今の「区画を選んでください。」）

- [ ] **Step 1: 失敗するテストを書く**

```php
/** B3: 区画の無い土地の契約の編集画面を開いたあとで、区画を足してその契約につないだ */
public function test_a_stale_contract_edit_tells_which_lot_it_now_has(): void
{
    // contractOn($land, null)（区画の無い土地）→ 編集フォーム（contractEditForm）の memo を '鍵は 2 本' に
    // → 不動産の詳細の追加のフォームで 1 号地を足す（契約につながる）→ 先のフォームを送る
    // 編集画面へ転送・assertErrorItem(strtr(ReContractController::LOT_HELD, [':number' => '1']))・memo は変わらない
    // 戻った画面を browserForm(…, 'contractEditForm') で組むと procurement_lot_id が 1 号地の id → それを送ると保存できる
}
```

  - 区画を選ばずに新しく契約を登録すると、今の「区画を選んでください。」のまま（既存の `ContractProcurementLotTest` の必須のテストが緑のままであることで見る）

- [ ] **Step 2: 流して落ちることを確かめる** — Expected: FAIL（「区画を選んでください。」だけが出る）

- [ ] **Step 3: 直す** — `LOT_HELD` を足し、`validateContract()` の messages の `procurement_lot_id.required` を条件で変える

- [ ] **Step 4: 流して通ることを確かめる** — Run: `…phpunit tests/Feature/RealEstate` / Expected: PASS

- [ ] **Step 5: コミット** — `fix: 不動産の契約の編集で開いたあとでつないだ区画を文で知らせる`

---

### Task 5: 全件・変異・実ブラウザ

- [ ] **Step 1: 全件** — Run: 全件（`--display-phpunit-deprecations`）/ Expected: OK・警告 0。コンパイル済みビューの lint: `php artisan view:cache` → `storage/framework/views/*.php` を `php -l` → INVALID 0 → `view:clear`（作業場所で）
- [ ] **Step 2: 変異**（`.claude/route-coverage-tools/mutate/` の実行役をこの作業の表で。流す前に期待を書く。コミットしてから・前後で `git status --porcelain` が空・`git diff --stat` で着弾・落ちた理由の文まで突き合わせる）。最低限: A の `releaseLots()` を呼ばない／契約の有無を見ない／トランザクションを外す、B1 の `whereNull` を外す／土地の条件を外す／戻り値を見ない、B2 の指紋からステータスを外す、B4 の `forget('status')` を外す、B3 の 3 つの入口の補いを 1 つずつ外す／`lotKeptFor` の条件を 1 つずつ外す／注文住宅の `isLandLockedByContract()` の条件を外す、Task 4 の条件を外す（いつも新しい文）、カナリア
- [ ] **Step 3: 実ブラウザ**（使い捨ての SQLite ＋ `artisan serve` ＋ Playwright・画面が見えている状態）: 契約のある建売を詳細画面から削除して、土地の区画の表で販売中に戻る／開いたままの建売の編集画面から保存できる（区画が残る）／開いたままの不動産の契約の編集画面で新しい文が出て、もう一度押すと保存できる／375px・1440px で文が枠の中・コンソールのエラー 0
- [ ] **Step 4: 最後の点検**（別の担当・いちばん強いモデル・読み取りだけ）

---

### Task 6: 記録

- [ ] **Step 1:** `docs/RULES.md` に Bug #147（A）を足し、Bug #146 の行に B1〜B4 の追記を足す（記録の直前に最新の 13.x で番号がぶつからないか数える）。`CLAUDE.md` の件数を 147 に。`docs/BACKLOG.md` に節（Bug #146 の節の「範囲外・後回し」から A・B の行を直したと書き換え）
- [ ] **Step 2: コミット** — `docs: 契約済みの建売の削除で区画を戻す変更と区画を足すときの残りを記録する`
- [ ] **Step 3:** 本番反映は利用者の承認を段ごとに取る（反映の前の読み取りで、契約済みの建売の削除で成約のまま残った区画を数える＝区画を持たれていない成約の土地の区画・分譲地の区画）
