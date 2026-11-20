# 注文住宅の編集画面でも買主を選ぶ Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 注文住宅の編集画面の顧客を、登録画面と同じ「買主を選ぶ欄」にし、保存で買主を必須・顧客名を買主の名前で置き換える（判断待ち ⑥）。

**Architecture:** `_form.blade.php` は登録・編集とも `housing/contracts/_buyer-select` を描く（自由入力とテナントの顧客の検索の JS を外す）。`CustomOrderController::edit()` が選択肢（住宅事業の買主＋今の買主〈削除済みも〉）を渡し、`validateOrder()` は `customer_id` を登録・編集とも必須にし、`update()` は `store()` と同じく買主から `customer_id`・`customer_name` を入れる。ランクは今ある `HsCustomOrder` の saved フックが動かす（新しいコードは無い）。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade + Alpine.js 3 / PHPUnit（SQLite）

**Spec:** `docs/superpowers/specs/2026-11-20-custom-order-edit-buyer-design.md`

## Global Constraints

- 編集画面の選択肢は `HsContractListController::editCustomOrder()` と同じ: `Buyer::ofDepartment('housing')->orderBy('last_name_kana')->get()` に、今の買主（`Buyer::withTrashed()->find($customOrder->customer_id)`）が無ければ足す（部署を問わない・削除済みは部品が「（削除済み）」を付ける）
- 保存: `Buyer::withTrashed()->findOrFail($request->integer('customer_id'))` → `customer_id`・`customer_name`（`$buyer->full_name`）を上書き（`store()` と同じ）
- 入力チェックは 1 回のまま（Bug #121）。`customer_id` は `['required', 'integer', 'exists:buyers,id']`（登録と同じ）。`customer_name` は今の `required|string|max:100` のまま。誤りの項目名は今の和名「顧客」（`lang/ja/validation.php`。登録・契約の編集と同じ）
- 買主が紐付いている案件を**何も変えずに保存して断られない**こと（Bug #130 の形を作らない）
- 前の買主のランクは戻さない（成約のまま）。saved フックは変えない
- 本番の DB は変えない（列もデータも）。本番の買主の無い 1 件は書き換えない
- テストの流し方は CLAUDE.md の「テストの流し方」（作業場所 `.claude/worktrees/custom-order-buyer` の cwd・`--display-phpunit-deprecations` を付ける）。`git stash` は使わない
- コミットの末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## Review Focus

1. **買主の無い案件**（本番に 1 件）: 編集画面は未選択で開き、選ばずに保存すると「顧客は必須です。」で断られ、打った欄は残る。選んで保存すると紐付く → Task 1 の `test_an_order_without_a_buyer_must_pick_one`
2. **ほかの欄の入力エラーで戻った画面**で、選び直した買主が今の買主に戻らない（`old('customer_id')`）→ Task 1 の `test_the_chosen_buyer_survives_an_input_error`
3. **今の買主が削除済み・住宅事業にいない**: 選ばれたまま開き、そのまま保存できる（Bug #58・#113 の形）→ Task 1 の `test_a_deleted_current_buyer_stays_selected` ・ `test_a_current_buyer_outside_housing_stays_selected`
4. **契約済みの案件で買主を変える**: 新しい買主が住宅事業で「成約」・前の買主も成約のまま → Task 1 の `test_changing_the_buyer_of_a_contracted_order_marks_the_new_buyer_contracted`
5. **手で組んだ顧客名**（買主と違う名前を送る）: 保存は買主の名前になる → Task 1 の `test_a_sent_customer_name_is_replaced_by_the_buyer_name`

---

### Task 1: 編集画面で買主を選ぶ（サーバー・画面・既存のテストの組み方）

試作（2026-11-20）で、変える前のテストの組み方のままだと 19 本が落ちることを測った。17 本は編集画面のフォームを `browserForm($html, …, 'customOrderForm')` で親だけ評価していて、入れ子の部品の `x-model="customerName"` を親の状態で探して止まる。残り 2 本は外す顧客の検索のテスト。下の Step 1 の 2 つの部品で 17 本は直り、ほかに落ちるテストは無かった。試作の差分は `/private/tmp/claude-501/-Users-masanori-site-manage/dbdc39d0-5ceb-4705-a764-1adb5a77f695/scratchpad/proto-custom-order-buyer.patch`（消えていてもこの計画だけで書ける）。

**Files:**
- Create: `tests/Feature/Housing/Screens/CustomOrderEditBuyerTest.php`（`HousingScreenTestCase` を継承）
- Modify: `tests/Feature/Housing/Screens/HousingScreenTestCase.php`（`customOrder()`・新しい `customOrderEditForm()`）
- Modify: `app/Http/Controllers/Housing/CustomOrderController.php`（`edit()`・`update()`・`validateOrder()`）
- Modify: `resources/views/housing/custom-orders/edit.blade.php`（`'buyers' => $buyers` を `_form` に渡す）
- Modify: `resources/views/housing/custom-orders/_form.blade.php`（顧客情報の `@isset … @else … @endisset` を部品だけに・JS から顧客の検索を外す）
- Modify: `resources/views/housing/contracts/_buyer-select.blade.php`（先頭の注記の「4フォーム」だけ）
- Modify tests: `AmountLimitTest.php`・`LandLockTest.php`・`LandCostAutoTest.php`・`CustomOrderScreensTest.php`・`JsEmbeddingTest.php`・`GetAjaxFailureTest.php`（どれも `tests/Feature/Housing/Screens/`）

**Interfaces:**
- Produces（テストの土台・`HousingScreenTestCase`）:
  - `customOrder(array $overrides = []): HsCustomOrder` — `$overrides` に `customer_id` のキーが無ければ `$this->buyer()`（山田 太郎・住宅事業）を作って紐付ける。買主の無い案件は `['customer_id' => null]`
  - `customOrderEditForm(HsCustomOrder $order, string $steps = '', ?string $html = null, string $buyerSteps = '', array $responses = []): array` — `composedForm($html ?? htmlOf(edit), route('housing.custom-orders.update', $order), 'customOrderForm', null, ['buyerSelect' => $buyerSteps], $steps, $responses)`。`$buyerSteps` は既存の `chooseBuyer(?Buyer)` の戻り値

- [ ] **Step 1: テストの土台に 2 つを足す**

`customOrder()` の配列の `'status'` の次に `'customer_id' => $buyer?->id,`（`$buyer = array_key_exists('customer_id', $overrides) ? null : $this->buyer();`）。`customOrderEditForm()` は `contract()` の前に置き、docblock に「`browserForm()` で親だけを評価すると、部品の x-model（customerName）を親の状態で探して止まる」と書く。

- [ ] **Step 2: 失敗するテストを書く**（`CustomOrderEditBuyerTest`。送る値は描いた画面から取る＝Bug #47。帯は `assertFlash`、誤りは `assertErrorItem`）

```php
private const UPDATED = '注文住宅案件「CO-001」を更新しました。';

public function test_the_edit_screen_picks_a_buyer_and_no_longer_searches_tenant_customers(): void
{
    $buyer = $this->buyer();
    $outsider = Buyer::create(['last_name' => '部外', 'first_name' => '花子']); // 住宅事業にいない・今の買主でもない
    $order = $this->customOrder(['customer_id' => $buyer->id, 'customer_name' => $buyer->full_name]);
    $html = $this->htmlOf(route('housing.custom-orders.edit', $order));

    $this->assertStringContainsString('x-data="buyerSelect(', $html);
    $this->assertStringNotContainsString('/api/tenant/customers/search', $html);
    $this->assertStringNotContainsString('searchCustomer', $html);
    $this->assertStringNotContainsString('href="' . route('tenant.customers.create') . '"', $html);
    $this->assertStringNotContainsString('value="' . $outsider->id . '"', $html, '住宅事業にいない買主まで選択肢に出ている');
    $this->assertSame((string) $buyer->id, $this->customOrderEditForm($order, html: $html)['fields']['customer_id'], '今の買主が選ばれていない');
}

public function test_swapping_the_buyer_from_the_screen_updates_both_id_and_name(): void
{   // 買主 A の案件で B を選んで保存 → assertFlash(UPDATED)・[customer_id, customer_name] === [$b->id, $b->full_name]・updated_by === $this->user->id
}

public function test_a_sent_customer_name_is_replaced_by_the_buyer_name(): void
{   // 描いたフォームの customer_name を fill() で 'でたらめ' に → 保存の customer_name === 買主の full_name
}

public function test_an_order_without_a_buyer_must_pick_one(): void
{   // customOrder(['customer_id' => null]) → 描いたフォームの customer_id === ''。notes に '外構は別途' を入れて送る
    // → assertErrorItem($html, trans('validation.required', ['attribute' => '顧客']))・DB の customer_id は null のまま・notes は変わらない
    // → 戻った画面を customOrderEditForm($order, html: $html, buyerSteps: $this->chooseBuyer($buyer)) で組み、notes が '外構は別途' のまま
    // → 送って assertFlash(UPDATED)・customer_id === $buyer->id
}

public function test_the_chosen_buyer_survives_an_input_error(): void
{   // 買主 A の案件で B を選び、order_name を '' にして送る → 誤り
    // → 戻った画面の fields['customer_id'] === (string) $b->id（A に戻らない）・customer_name === $b->full_name
}

public function test_a_deleted_current_buyer_stays_selected(): void
{   // 買主を紐付けてから $buyer->delete() → 画面に e($buyer->full_name) . ' （削除済み）' がある・fields['customer_id'] === (string) $buyer->id
    // → 何も変えずに送って assertFlash(UPDATED)・customer_id は同じ
}

public function test_a_current_buyer_outside_housing_stays_selected(): void
{   // Buyer::create(...) だけ（部署なし）を紐付け → fields['customer_id'] === (string) その id → そのまま送って assertFlash(UPDATED)
}

public function test_changing_the_buyer_of_a_contracted_order_marks_the_new_buyer_contracted(): void
{   // status 'contracted'・contract_date '2026-09-10'・買主 A（作った時点で A は成約）→ B を選んで保存
    // → B と A の getDepartmentPivot('housing')->rank がどちらも BuyerRank::Contracted
}
```

- [ ] **Step 3: 落ちることを確かめる**

Run: `… vendor/bin/phpunit tests/Feature/Housing/Screens/CustomOrderEditBuyerTest.php`
Expected: 8 本とも FAIL（理由は「画面のフォームの中に buyerSelect が無い」か、最初の本は `x-data="buyerSelect(` が無い）

- [ ] **Step 4: コントローラ（`CustomOrderController`）**

- `edit()`: Global Constraints の選択肢を `$buyers` に作り、`compact(…, 'buyers')` で渡す
- `validateOrder()`: `customer_id` を `['required', 'integer', 'exists:buyers,id']`（`$creating` で分けない）。上の注記を「買主（登録・編集とも必須。判断待ち ⑥）。入力チェックは 1 回にまとめる（Bug #121）」に直す。`$creating` の引数は残す（ほかで使っていなければ消してよい＝実装の判断）
- `update()`: `validateOrder()` のすぐあとに `store()` と同じ 3 行（買主を引いて `customer_id`・`customer_name` を入れる。注記「登録と同じく買主の名前で上書き（判断待ち ⑥）」）

- [ ] **Step 5: 画面**

- `edit.blade.php`: `_form` の `@include` の配列に `'buyers' => $buyers`
- `_form.blade.php`: 顧客情報の `@isset($buyers) … @else（自由入力・検索の結果・「顧客登録」のリンク）… @endisset` を、中の `@include('housing.contracts._buyer-select', […])` だけにする（引数は今のまま）。`customOrderForm()` から `customerName`・`customerResults`・`customerError`・`searchTimer` と `searchCustomer`・`selectCustomer` を外す（`fetchProcurementInfo` の後ろのカンマも）。`_ajax_get_failure` の `@include` は区画・仕入れ案件が使うので残す
- `_buyer-select.blade.php`: 注記の「注文住宅・建売の create/edit 4フォームで共通利用」を「注文住宅の登録・編集・注文住宅の契約の編集・建売の契約の登録と編集 2 つで共通利用」に

- [ ] **Step 6: 新しいテストが通ることを確かめる**

Run: 同じファイル。Expected: 8 本とも PASS

- [ ] **Step 7: 既存のテストを新しい形に合わせる**

- 編集画面のフォームの組み方を `customOrderEditForm()` に: `AmountLimitTest::orderEditForm()`・`LandLockTest::orderEditForm()`・`LandCostAutoTest::orderEditForm()` の中身を `return $this->customOrderEditForm($order, $steps/$js[, $html]);` に、`CustomOrderScreensTest` の 3 か所（区画を保つ・仕入れ案件を保つ・仕入れ案件が空を断る）の `browserForm(…, 'customOrderForm'[, $js])` を `customOrderEditForm($order[, $js])` に
- `JsEmbeddingTest::test_the_custom_order_form_keeps_the_saved_customer_name_and_address` → `…_keeps_the_saved_buyer_name_and_address` に書き換え: 買主を `Buyer::create(['last_name' => "O'Brien & 山田\\", 'first_name' => '太郎'])` ＋ `addToDepartment('housing', '2026-09-01')` で作り、案件は `customer_id`・`customer_name`（その `full_name`）・`address`（`self::TRICKY`）で作る。`customOrderEditForm()` の `[address, customer_name]` が `[TRICKY, full_name]`、保存後の DB も同じ
- `GetAjaxFailureTest`: `customerSearchRefusals()` と `test_the_custom_order_customer_search_says_why` を消し、先頭の docblock の「顧客の検索」を消す（`member()`・`NO_DEPARTMENT` などをほかで使わなくなったら一緒に消す）

- [ ] **Step 8: 住宅事業と関係する走査のテストを流す**

Run: `… vendor/bin/phpunit tests/Feature/Housing tests/Feature/GetAjaxFailureScanTest.php tests/Feature/RealEstate/AjaxFetchSessionGuardTest.php tests/Feature/AjaxErrorFeedbackTest.php tests/Feature/OldInputRuleCoverageTest.php tests/Feature/JsEchoClassificationTest.php tests/Feature/JsStringEchoScanTest.php tests/Feature/JapaneseValidationMessagesTest.php`
Expected: OK（試作では走査の下限〈GET の分岐 15・呼び出し 20 など〉は足りた）

- [ ] **Step 9: 全件を流す**

Run: CLAUDE.md のコマンド（`--display-phpunit-deprecations` 付き・background）。Expected: `OK (5384 tests, …)`（5378 − 2 ＋ 8）・警告なし

- [ ] **Step 10: コミット**

```bash
git add app/Http/Controllers/Housing/CustomOrderController.php resources/views/housing/custom-orders resources/views/housing/contracts/_buyer-select.blade.php tests/Feature/Housing/Screens
git commit -m "fix: 注文住宅の編集画面でも買主を選び、顧客名を買主の名前にそろえる（判断待ち ⑥）"   # 末尾に Co-Authored-By
```

---

### Task 2: 変異テスト・コンパイル済みビュー・実ブラウザ

**Files:** なし（測るだけ。記録は Task 3）

- [ ] **Step 1: 変異テスト**（docs/RULES.md Bug #44 の作法。実行役は main repo の `.claude/route-coverage-tools/mutate/run.py` を scratchpad に写し、`WT` をこの作業場所に・表は scratchpad の `specs_custom_order_buyer.py`。流すテストは Task 1 Step 8 の集合。**流す前に期待の表を書く**）

| # | 置き換え | 期待（落ちるテスト・理由） |
|---|---|---|
| C0 | `update()` の帯の「を更新しました。」を「を更新。」に（カナリア） | 編集から保存する住宅事業のテスト（`CustomOrderEditBuyerTest` の 6 本ほか）・帯の文 |
| M1 | `customer_id` を `$creating ? [...] : 'exclude'` に戻す | `test_an_order_without_a_buyer_must_pick_one`（入力エラーにならず 404） |
| M2 | `update()` の `customer_name` の上書きを消す | `…replaced_by_the_buyer_name`・`…updates_both_id_and_name` |
| M3 | `update()` の `customer_id` の代入を消す | `…updates_both_id_and_name`・`…marks_the_new_buyer_contracted` |
| M4 | `edit()` で今の買主を足さない | `…deleted_current_buyer…`・`…outside_housing…` |
| M5 | `edit()` の選択肢を `Buyer::orderBy('last_name_kana')->get()`（部署で絞らない） | `…no_longer_searches_tenant_customers`（部外の買主） |
| M6 | `update()` の `findOrFail` から `withTrashed()` を外す | `…deleted_current_buyer…`（404） |
| M7 | `_form` の部品に渡す `selectedId` を `$o?->customer_id` に（`old()` を外す） | 緑＝等価（部品の中でも `old('customer_id', …)` を見る） |
| M8 | saved フックの `wasChanged(['customer_id', 'status'])` を `wasChanged(['status'])` に | `…marks_the_new_buyer_contracted` を含む |
| M9 | `edit.blade.php` から `'buyers' => $buyers` を外す | 編集画面を開くテスト全部（`$buyers` が無い） |
| T1 | 土台の `customOrder()` の既定の買主を外す | 編集画面から保存する既存のテスト（「顧客は必須です」） |

期待と違ったら、落ちたテストの組と理由の文言まで突き合わせ、穴ならテストを足してから当て直す。

- [ ] **Step 2: コンパイル済みビューの lint**

Run: 作業場所で `view:cache` → `storage/framework/views/*.php` を全部 `php -l` → `view:clear`。Expected: INVALID 0（本数は手元で数えた値を記録）

- [ ] **Step 3: 実ブラウザ**（使い捨ての環境。手順は main repo の `.claude/route-coverage-tools/browser/README.md`。⚠ ログイン用のルートはコミットしない・全件の前に外す。CSS は新しいクラスを足していないので main repo の `public/build` へのリンクでよい）

使い捨ての DB に「買主の無い契約済みの注文住宅」と「買主が紐付いた注文住宅」を作り、Playwright（画面が見えている状態）で:
1. 紐付いた案件の編集画面: 今の買主が選ばれている・顧客名（自動）が読み取り専用・「新規顧客を登録」が開く／閉じる・保存できる
2. 買主の無い案件: 未選択で開く → 保存で「顧客は必須です。」と赤い箱・打った備考が残る → 選んで保存できる
3. 編集画面から「新規顧客を登録」で買主を作る → 選ばれた状態になり保存できる
4. 1440px・375px で `main` の横スクロール 0・コンソールのエラー 0（Google Maps の以前からの警告は除く）

Expected: すべて期待どおり。⚠ 済んだらサーバーを止め、ログイン用のルートを外し、作業ツリーが空であることを確かめる

---

### Task 3: 記録

**Files:**
- Modify: `docs/RULES.md`（Bug の表に 1 行。番号は**記録の直前に最新の 13.x で数える**＝並行する会話とぶつからないように。今は #144）
- Modify: `docs/BACKLOG.md`（直近の「土地原価…」の節の次に節を足す・末尾の「バックログ完了状況」に 1 段落。判断待ちの残りの行の ⑥ に「→ 直した」）
- Modify: `CLAUDE.md`（RULES の件数 143 → 144 の 2 か所）
- Modify: この計画（末尾に実測記録: 全件の数・変異の表の結果・lint の本数・実ブラウザ）

- [ ] **Step 1: RULES に 1 行**（症状・原因・直し方・回帰テスト・変異の結果。症状は設計書の「なぜ」、本番は「注文住宅 4 件・買主なし 1・名前の食い違い 0〈2026-11-20 の読み取り〉」）
- [ ] **Step 2: BACKLOG・CLAUDE.md・計画の実測記録**
- [ ] **Step 3: コミット**

```bash
git add docs/RULES.md docs/BACKLOG.md CLAUDE.md docs/superpowers/plans/2026-11-20-custom-order-edit-buyer.md
git commit -m "docs: 注文住宅の編集画面で買主を選ぶ直しを記録する（Bug #144）"   # 末尾に Co-Authored-By
```

13.x への早送り・push・`./deploy.sh` は利用者の指示があったときだけ（この計画には含めない）。本番反映は DB 変更・新しい PHP クラスなし＝ `./deploy.sh` だけ。
