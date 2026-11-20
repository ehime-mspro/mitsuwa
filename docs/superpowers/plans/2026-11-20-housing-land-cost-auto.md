# 土地原価の手動入力をやめたら紐付け先から計算し直す（判断待ち ⑤・案 A＋b）実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 手動 OFF の保存で、土地原価をサーバーが決める（手入力をやめた・新規・紐付けを変えた・「今の計算値にする」を押したときは紐付け先から計算、それ以外は保存済みのまま）。4 画面に、保存される値と、今の計算値に合わせるチェックを出す。

**Architecture:** 計算と決まりは新しいクラス `App\Support\HousingLandCost` の 1 か所。区画の按分の式は `ReProject` から 1 つのメソッドに切り出して、区画の一覧の API と共用する。画面の JS は決まりを 1 つの関数 `housingLandCostView()`（部品 `housing/_land_cost_script`）に写し、PHP と同じ場面で突き合わせるテストを置く（Bug #41）。

**Tech Stack:** Laravel 12 / PHP 8.3・Blade＋Alpine.js 3・PHPUnit（node の vm で画面の JS を動かす既存の道具 `DrivesAlpineFetch`・`ComposesScreenForms`）

**Spec:** `docs/superpowers/specs/2026-11-20-housing-land-cost-auto-design.md`（`1340ba6b`。利用者が 2026-11-20 に承認）

## Context

住宅事業の土地原価で「手動入力」を外して保存しても、前に手で入れた値が残る（判断待ち ⑤）。契約の編集画面ではその値が「（区画名）から自動参照」と出る。
本番（2026-11-20 の読み取り）は、手動 OFF の建売 5 件のうち 3 件で、保存されている値が今の計算値と違う（3 件とも契約前）。手動 ON は建売 8・注文住宅 2。
利用者の決定: 手入力をやめたらサーバーが計算し直す（案 A）。すでにある行は、利用者が「今の計算値にする」を押したときだけ合わせる（案 b）。チェックに触らず保存したときは値を動かさない（「古い契約の原価が勝手に動かないように」）。本番のデータは書き換えない。

作業場所: `.claude/worktrees/housing-land-cost`（枝 `housing-land-cost`。13.x = `a7c40dab` から。設計書は `1340ba6b`）。

## Global Constraints

- 保存の決まり（4 つの保存の経路すべて。手動 ON はどれも今どおり送られた値）:
  手動 OFF なら `新規登録 || 保存済みが手動 ON || 紐付け先が保存済みと違う || refresh_land_cost=1` のとき `HousingLandCost::forLink()`、それ以外は保存済みの値。**手動 OFF で送られた `land_cost` は使わない**。お客様の土地（注文住宅）は今どおり空
- 紐付け先の比べ方は `LocksLandAfterContract` の `landLink()`（種別＋その種別で使う id だけ。先頭の 0 を落とす）を使う。新しく書かない
- 仕入れ案件は原価の行が 1 つも無ければ計算できない（null）。区画は分譲地の全区画に販売価格が無ければ null（今の式どおり）
- 「今の計算値にする」は手動 OFF・新規でない・保存済みが手動 OFF・紐付け先が同じ・計算値がある・保存値と違う、のときだけ出す。送る欄の名前は `refresh_land_cost`（チェックボックス・値 `1`）。入力チェックは 4 つの経路とも `'refresh_land_cost' => 'nullable|boolean'`（保存しない欄。`$validated` から外して保存する）
- 画面の文言（そのまま使う）:
  - 計算できない: `紐付け先から計算できません（分譲地の全区画に販売価格が入っていない／仕入れ案件に原価が無い）。このまま保存すると空になります。`
  - 計算する: `保存すると、紐付け先から計算したこの値になります。`
  - 違うとき: `保存されている値は、今の紐付け先から計算した値（{Y}円）と違います。`／チェックのラベル `今の計算値（{Y}円）にする`
  - 契約の編集の表示: `（{紐付け先}から取得した値）`（今の「から自動参照」を置き換える）
  - 注文住宅の契約の編集で紐付け先を変えたとき: `保存すると、新しい紐付け先から計算します。`
  - 金額の書き方は `Number(v).toLocaleString('ja-JP')`（例 `4,000,000円`）
- JS の値は `Js::from` か `@json` で渡す（Top trap・Bug #76）。x-data に矢印関数を書かない（Top trap #4）。getter を使わずメソッドにする（Top trap #8）
- 本番のデータは書き換えない。DB の変更・ルートの変更は無い。新しいクラスが 1 つ（本番反映では main repo の cwd で `composer dump-autoload --no-dev --optimize`）
- テストは作業場所の cwd で CLAUDE.md の「テストの流し方」のコマンドに `--display-phpunit-deprecations` を付けて流す

## Review Focus

1. 入力エラーで戻った画面: 「保存済みが手動 ON か」は DB の値で決め（`old()` ではない）、「今の計算値にする」は `old('refresh_land_cost')` で戻す → Task 3・4 に往復テスト
2. 手動 ON で保存すると、`refresh_land_cost=1` が送られても送られた値を保存する → Task 2
3. 契約済みの注文住宅（紐付けは固定）で、手動 OFF のまま備考だけ直す → 土地原価は動かない（いちばんよくある操作）→ Task 2・4
4. 計算できない紐付け先（全区画に販売価格が無い）で手入力をやめる → 画面に「計算できません」が出て、保存すると空 → Task 2・3
5. 紐付けの無い建売（種別が空）の新規登録 → 空のまま・「計算できません」は出さない → Task 2・3

---

### Task 1: 計算の部品と決まり（`HousingLandCost`）

**Files:**
- Create: `app/Support/HousingLandCost.php`
- Modify: `app/Models/ReProject.php`（`housingLotOptions()` の按分を切り出す）・`app/Models/Concerns/LocksLandAfterContract.php`（`landLinkDiffers()` を足す）・`app/Http/Controllers/Housing/PropertyController.php`（`procurementInfo()` に `land_cost`）
- Test: `tests/Feature/Housing/HousingLandCostTest.php`（`Tests\Feature\Housing\Screens\HousingScreenTestCase` を継承し、`project()`・`lot()`・`procurement()`・`property()`・`customOrder()` を使う）

**Interfaces:**
- Produces:
  - `ReProject::housingLotLandCost(int $lotId): ?int` — 区画 1 つの按分（`housingLotOptions()` の `land_cost` と同じ値。その分譲地に無い区画は null）
  - `LocksLandAfterContract::landLinkDiffers(array $input): bool` — 契約の有無に関係なく比べる。`refusesLandChange()` は `isLandLockedByContract() && landLinkDiffers()` に書き換える
  - `HousingLandCost::forLink(?string $sourceType, mixed $lotId, mixed $procurementId): ?int`
  - `HousingLandCost::decide(bool $creating, bool $savedManual, bool $linkChanged, bool $refresh, ?int $computed, ?int $saved): ?int`
  - `HousingLandCost::whenAutomatic(HsProperty|HsCustomOrder|null $current, array $link, bool $refresh): ?int` — `$link` は保存する紐付け（`land_source_type`・`re_project_lot_id`・`re_procurement_id`）。保存済みは `getOriginal()` で読む
  - `GET /api/housing/procurement-info/{procurement}` の応答に `land_cost`（`forLink('procurement', …)`。`effective_cost_total` は残す）

- [ ] **Step 1: 失敗するテストを書く** — 値は「分譲地の原価 24,000,000（見込み）・区画 2 が 12,000,000・区画 4 が 18,000,000」→ 区画 2 は 9,600,000・区画 4 は 14,400,000。仕入れ案件は「見込み 7,000,000・確定 7,350,000」と「見込み 500,000」→ 7,850,000。手入力の値は 5,000,000。
  - `test_a_lot_is_allocated_like_the_lot_list` — 2 区画とも `forLink('project_lot', id, null)` が上の値で、`housingLotOptions()` の `land_cost` と同じ
  - `test_a_lot_cannot_be_computed_when_a_lot_has_no_price` — 区画を 1 つ販売価格なしで足すと null
  - `test_a_procurement_uses_actual_or_estimated_costs` — 7,850,000。原価の行が無い仕入れ案件は null
  - `test_other_links_cannot_be_computed` — `customer_land`・null・存在しない区画・存在しない仕入れ案件は null
  - `test_decide_computes_only_when_the_save_says_so` — 16 通り（4 つの bool）すべてで、どれかが true なら computed、全部 false なら saved（computed=9,600,000・saved=5,000,000。computed=null も 1 行）
  - `test_when_automatic_reads_the_saved_row` — 新規（`null`）→ 計算／保存済み ON → 計算／保存済み OFF・同じ紐付け → 5,000,000／同じ紐付けで refresh → 計算／契約前の注文住宅で区画 4 へ → 14,400,000
  - `test_link_differs_ignores_the_contract` — 契約の無い建売でも `landLinkDiffers()` は違う紐付けで true・同じ紐付け（`'02'`・int の id・使わない欄の残り）で false
  - `test_the_procurement_info_returns_the_land_cost` — `apiResponse()` の本文の `land_cost` が 7,850,000・原価の無い仕入れ案件は null
- [ ] **Step 2: 流して失敗を確かめる** — `HousingLandCost` が無い・`land_cost` のキーが無い
- [ ] **Step 3: 実装する** — `ReProject` は按分の 1 行を private の `allocatedLandCost(ReProjectLot $lot, int $costTotal, int $sellingTotal, bool $allPriced): ?int` にし、`housingLotOptions()` と `housingLotLandCost()` が使う。`forLink()` は区画なら `ReProjectLot::find()` → その分譲地を `with('lots', 'costs')` で引いて `housingLotLandCost()`、仕入れ案件なら `with('costs')` で引いて行が無ければ null・あれば `getEffectiveCostTotal()`。id は `is_numeric` でなければ null。`whenAutomatic()` は `decide($current === null, (bool) $current?->getOriginal('is_land_cost_manual'), $current?->landLinkDiffers($link) ?? false, $refresh, forLink(...), $current?->getOriginal('land_cost'))`
- [ ] **Step 4: 流して通ることと、`LandLockTest`（`refusesLandChange` の書き換え）が緑のままであることを確かめる**
- [ ] **Step 5: コミット** `feat: 土地原価を紐付け先から計算する部品を足す`

### Task 2: 4 つの保存の経路でサーバーが決める

**Files:**
- Modify: `app/Http/Controllers/Housing/PropertyController.php`（`store`・`update`・`validateProperty`）・`app/Http/Controllers/Housing/CustomOrderController.php`（`store`・`update`・`validateOrder`）・`app/Http/Controllers/Housing/HsContractListController.php`（`updateBuilding`・`updateCustomOrder`）
- Test: `tests/Feature/Housing/Screens/LandCostAutoTest.php`（新規・`HousingScreenTestCase`）

**Interfaces:**
- Consumes: `HousingLandCost::whenAutomatic()`（Task 1）
- Produces: 4 つの経路が `refresh_land_cost` を受け取る（画面は Task 3・4 で足す）

- [ ] **Step 1: 失敗するテストを書く** — 描いた画面のフォームを `browserForm()`／`composedForm()`（`LandLockTest` の `propertyEditForm`・`orderEditForm`・`contractEditForm` と同じ組み方）で組み、`fill()` で手動入力の欄を変えて送る。`refresh_land_cost` はまだ画面に無いので、この Task では `$form['fields']['refresh_land_cost'] = '1'` で足す（Task 3・4 で画面から送る往復に置き換える）。区画 2・仕入れ案件の値は Task 1 と同じ
  - `test_turning_manual_off_on_a_property_computes_the_land_cost` — 保存済み ON・5,000,000 → OFF で送る → 9,600,000・手動 false
  - `test_an_untouched_automatic_property_keeps_its_saved_value` — 保存済み OFF・5,000,000（古い値）で欄の値を 7,000,000 に変えて送る → 5,000,000 のまま
  - `test_refresh_sets_the_current_computed_value` — 上と同じで refresh=1 → 9,600,000
  - `test_manual_on_saves_the_sent_value_even_with_refresh` — ON・6,000,000・refresh=1 → 6,000,000
  - `test_a_new_property_computes_regardless_of_the_sent_value` — 登録で区画 2・OFF・欄 7,000,000 → 9,600,000／紐付けなし・OFF → null
  - `test_an_uncomputable_lot_saves_empty` — 区画の販売価格が欠けた分譲地で ON → OFF → null
  - `test_a_custom_order_follows_the_same_rules` — 契約前で区画 2 → 区画 4・OFF（保存済み OFF）→ 14,400,000／契約済み・保存済み ON → OFF → 9,600,000／契約済み・保存済み OFF・備考だけ → そのまま／仕入れ案件（Task 1 の仕入れ案件）で ON → OFF → 7,850,000／お客様の土地・refresh=1 → null
  - `test_the_building_contract_edit_follows_the_same_rules` — 保存済み ON → OFF → 9,600,000（直す前は 5,000,000 のまま＝⑤）／保存済み OFF・備考だけ → そのまま／refresh=1 → 9,600,000
  - `test_the_custom_order_contract_edit_follows_the_same_rules` — 同じ 3 つ（契約済みの注文住宅）
- [ ] **Step 2: 流して失敗を確かめる** — ⑤ の場面が「5,000,000 のまま」で落ちる
- [ ] **Step 3: 実装する** — 4 つの経路で、紐付け先の外部キーを整えたあと（`setLandSourceForeignKeys()`・`clearLandFieldsForCustomerLand()`・契約の編集の `$data` を組んだあと）に、手動 OFF で種別がお客様の土地でなければ `land_cost = HousingLandCost::whenAutomatic($current, $link, $request->boolean('refresh_land_cost'))`。`$current` は登録では null、建売の契約の編集では `$property`。入力チェックに `refresh_land_cost` を足し、`$validated` から外す。契約の編集 2 つの「手動入力ON のときのみ land_cost を上書き」の枝とコメントは、この決まりに書き換える
- [ ] **Step 4: 流して通ること、`tests/Feature/Housing` 全体と `OldInputRuleCoverageTest`・`JapaneseValidationMessagesTest` が緑であることを確かめる**
- [ ] **Step 5: コミット** `fix: 手動入力をやめた土地原価を紐付け先から計算して保存する`

### Task 3: 登録・編集の画面（建売・注文住宅の `_form`）

**Files:**
- Create: `resources/views/housing/_land_cost_script.blade.php`（`@once` の script。`housingLandCostView(s)`・`housingLandLinkChanged(saved, now)`・`housingYen(v)`）
- Modify: `resources/views/housing/properties/_form.blade.php`・`resources/views/housing/custom-orders/_form.blade.php`・`PropertyController`（`create`・`edit` に `$computedLandCost`）・`CustomOrderController`（同じ）
- Test: `tests/Feature/Housing/Screens/LandCostAutoTest.php`（足す）・`tests/Feature/Housing/Screens/LandCostViewParityTest.php`（新規）

**Interfaces:**
- Consumes: `HousingLandCost::forLink()`・`decide()`（Task 1）。API の `land_cost`（区画の一覧はもとからある）
- Produces: `housingLandCostView(s)` — `s = { manual, creating, savedManual, linkChanged, refresh, computed, saved, hasLink }` → `{ willCompute, value, refreshOffered, unavailable }`（`willCompute = !manual && (creating || savedManual || linkChanged || refresh)`・`value = willCompute ? computed : saved`・`refreshOffered = !manual && !creating && !savedManual && !linkChanged && computed !== null && computed !== saved`・`unavailable = willCompute && computed === null && hasLink`）。Task 4 も使う

- [ ] **Step 1: 失敗するテストを書く**
  - パリティ（`LandCostViewParityTest`。`AreaBuildingImportParserParityTest` と同じく、描いた建売の編集画面の script を node の vm で読む）: `test_the_screen_decides_like_the_server` — 4 つの bool の 16 通り × computed（9,600,000／null）× saved（5,000,000／null）で、`housingLandCostView({manual:false,...}).value` が `HousingLandCost::decide()` と同じ
  - 画面（`driveAlpine()` の `evaluate` で `landCostView()` と `landCost` を読む・`browserForm()` で送る）:
    - `test_unchecking_manual_shows_and_saves_the_computed_value` — 保存済み ON・5,000,000 の建売の編集で `data.isLandCostManual = false` → 欄 9,600,000・「保存すると、紐付け先から計算したこの値になります。」→ 送ると 9,600,000
    - `test_a_stale_automatic_value_offers_the_refresh` — 保存済み OFF・5,000,000：描いた画面に `今の計算値（9,600,000円）にする` のチェック。押さずに送る → 5,000,000／押して送る → 9,600,000（ここで Task 2 の手で足した `refresh_land_cost` を画面から送る往復に置き換える）
    - `test_an_equal_value_offers_no_refresh` — 保存済み OFF・9,600,000 → チェックを出さない
    - `test_an_uncomputable_link_says_so` — 販売価格の欠けた分譲地で ON → OFF → 計算できない文。紐付けなしの新規登録では出さない
    - `test_choosing_a_lot_on_the_create_screen_shows_its_value` — 登録画面で分譲地・区画 4 を選ぶ（API の応答を渡す）→ 14,400,000。仕入れ案件を選ぶ（`procurement-info` の応答）→ 7,850,000・原価の無い仕入れ案件 → 空と計算できない文
    - `test_typing_then_unchecking_keeps_the_saved_value` — 保存済み OFF・5,000,000：ON にして 7,000,000 と打ち OFF に戻す → 欄 5,000,000・送ると 5,000,000（⑤ の別の形）
    - `test_the_refresh_survives_an_input_error` — refresh を付けて物件名を空にして送る → 戻った画面でチェックが付いたまま・欄 9,600,000（Review Focus 1）
    - 注文住宅の `_form` でも「外すと計算値」「違うときのチェック」の 2 本（契約済み＝紐付け固定の画面で）
- [ ] **Step 2: 流して失敗を確かめる**
- [ ] **Step 3: 実装する** — 部品を `_ajax_get_failure` と同じ形（説明は Blade コメント・`@once`）で書き、2 つの `_form` が include。コンポーネントに `savedManual`・`savedLandCost`・`savedLink`（DB の値。`old()` を見ない）・`creating`・`computedLandCost`（コントローラが今の紐付け＝old か保存済み、の `forLink()` を渡す）・`refreshLandCost`（`old('refresh_land_cost')`）を足し、`landCostView()` と `syncLandCost()`（手動 OFF なら `landCost` を `landCostView().value` に）を置く。`init()` で `$watch('isLandCostManual')`・`$watch('refreshLandCost')` から `syncLandCost()`。`onLotChange()`・`onProcurementChange()`・`onSourceTypeChange()`・`onProjectChange()` は `computedLandCost` を更新して `syncLandCost()`（手動 ON のときに `landCost` を書き換えない今の動きは残す）。文言とチェックは土地原価の欄の下に `x-show` で出す
- [ ] **Step 4: 流して通ること、`tests/Feature/Housing` 全体・`JsEchoClassificationTest`・`JsStringEchoScanTest`・`OldInputRuleCoverageTest`・`LayoutMeasuringScriptTest` が緑であることを確かめる**
- [ ] **Step 5: コミット** `fix: 登録・編集の画面で手動入力をやめたときの土地原価を出す`

### Task 4: 契約の編集の画面（建売・注文住宅）

**Files:**
- Modify: `resources/views/housing/contracts/edit-building.blade.php`（インラインの x-data を名前つきの関数 `buildingContractCostForm(initial)` に）・`resources/views/housing/contracts/edit-custom-order.blade.php`（`customOrderEditForm(initial)` に足す）・`HsContractListController`（`editBuilding`・`editCustomOrder` に `$computedLandCost`）
- Test: `tests/Feature/Housing/Screens/LandCostAutoTest.php`（足す）

**Interfaces:**
- Consumes: `housingLandCostView()`・`housingYen()`（Task 3）・`forLink()`（Task 1）

- [ ] **Step 1: 失敗するテストを書く**（建売の契約の編集・注文住宅の契約の編集の 2 画面で同じ場面）
  - `test_the_contract_edit_shows_the_value_it_will_save` — 保存済み OFF・9,600,000 → 「9,600,000円（{紐付け先}から取得した値）」。「から自動参照」は 0 件
  - `test_unchecking_manual_on_the_contract_edit_shows_the_computed_value` — 保存済み ON → 外す → 「保存すると、紐付け先から計算したこの値になります。」と 9,600,000円 → 送ると 9,600,000
  - `test_the_contract_edit_offers_the_refresh` — 保存済み OFF・5,000,000 → チェックを付けて送ると 9,600,000（Task 2 の手で足した値を置き換える）／付けずに備考だけ → 5,000,000
  - `test_the_custom_order_contract_edit_explains_a_new_link` — 契約前の注文住宅（URL で開いた場合だけ）で区画を変える → 「保存すると、新しい紐付け先から計算します。」・金額は出さない
- [ ] **Step 2: 流して失敗を確かめる**
- [ ] **Step 3: 実装する** — 2 画面とも部品を include。手動 OFF の表示を `x-text` で `landCostView()` から組む（`hc-land-ref` の中）。`refresh_land_cost` のチェックは手動 OFF の欄の中に置き、`refreshOffered` のときだけ出す（付けたあとは外せるよう、付いているあいだも出す）
- [ ] **Step 4: 流して通ること、`tests/Feature/Housing` 全体と Task 3 の走査テストが緑であることを確かめる**
- [ ] **Step 5: コミット** `fix: 契約の編集で保存される土地原価と今の計算値を出す`

### Task 5: 全件・変異・実ブラウザ・記録・最後の点検

- [ ] **Step 1: 全件** — `OK (N tests, …)`・警告なし。コンパイル済みビューの `php -l`（`view:cache` → 全部 → `view:clear`）で INVALID 0
- [ ] **Step 2: 変異**（docs/RULES.md Bug #44 の作法。先にコミット・各変異の前に `git status --porcelain` が空・`git diff --stat` で着弾・理由の文まで突き合わせる。表と実行役は scratchpad の `mutate/specs_housing_land_cost.py`・`run_hlc.py`。流す前に期待を書く）— 少なくとも: `decide()` の 4 つの条件を 1 つずつ外す・`whenAutomatic()` が `getOriginal` でなく今の属性を読む・`forLink()` の「原価の行が無ければ null」を外す・按分を `housingLotOptions()` の写しに戻す・4 経路のそれぞれで手動 OFF の枝を外す（送られた値を保存）・`refresh_land_cost` を読まない・JS の `willCompute` の条件を 1 つずつ外す・`refreshOffered` の `computed !== saved` を外す・`savedManual` を `old()` から作る・カナリア 1
- [ ] **Step 3: 実ブラウザ**（使い捨ての SQLite ＋ `artisan serve`・Playwright・画面が見えている状態。作業場所で `npm run build`）— 4 画面で「外すと計算値に替わる」「違うときのチェック」「計算できない文」・1440px と 375px で `main` の横スクロール 0・コンソールのエラー 0
- [ ] **Step 4: 記録** — docs/RULES.md に Bug #143、docs/BACKLOG.md に節（範囲外: 原価が変わったときの自動追従・本番 3 件の値）、CLAUDE.md の件数を 143 に、計画書に実測記録
- [ ] **Step 5: 最後の点検**（別の担当・最も強いモデル・読み取りだけ）→ 直す → 再点検

## 検証（end-to-end）

- 自動テスト: `tests/Feature/Housing`・新しい 3 本（`HousingLandCostTest`・`LandCostAutoTest`・`LandCostViewParityTest`）と全件
- 本番反映は利用者の指示があってから（反映の順: main repo で 13.x を早送り → `composer dump-autoload --no-dev --optimize` → `./deploy.sh` → 本番の md5・ビューの lint・`laravel.log`〈Nov 18 01:45 のまま〉→ ログインした画面で 4 画面を見るだけ。本番の 3 件は書き換えない）

## 進め方

5 つの Task は順に前の Task の部品を使う。私がこの会話で順に実装し、最後に別の担当（最も強いモデル）が枝全体を点検する形（Native）を推薦します。

## 実測記録（2026-11-20）

進め方は Native（この会話で Task 1〜5 を順に実装）。台帳（`.superpowers/sdd/2026-11-20-housing-land-cost-auto/progress.md`。git の管理外）の決めごと:

- Task 2: 注文住宅のテストを場面ごとに 4 本に分けた／`refresh_land_cost` の和名を `lang/ja/validation.php` に足した（和名の走査テストが求める。計画の Files に無かった）／固定のデータ（`pricedProject()` など）を土台 `HousingScreenTestCase` へ移した
- Task 3: JS の決まりの部品は script のタグを持たず、コンポーネントの script の中に include する（`housing/_land_cost_functions`。画面のテストの道具がコンポーネントを定義する script だけを読むため。計画は `@once` の script の部品 `_land_cost_script` と書いていた）／欄は部品 `housing/_land_cost_field`。手動 OFF の入力欄は `:disabled`（送らない）で表示は読み取り専用の別の欄（道具の `$watch` は何もしないので、表示は状態から決まる関数にした）／Task 2 のテストで手動 OFF の欄に打っていたものは、`$form['fields']['land_cost']` を手で入れる形（古い画面・手で組んだ送信）にした
- Task 4: 建売の契約の編集のインラインの x-data を名前つきの関数 `buildingContractCostForm()` にし、`ContractScreensTest`・`AmountLimitTest` はその関数でフォームを組む／契約の編集 2 画面の表示は部品 `housing/contracts/_land_cost_ref` 1 つ
- Task 5: 実ブラウザで見つけた「今の計算値にする」の縦割れを直した（下）

全件: 1 回目 **OK (5375 tests, 58212 assertions)**・警告なし（Task 4 のあと）。2 回目 **OK (5376 tests, 58216 assertions)**・警告なし（Task 5 のラベルの直しのあと。着手前の 5342 本に 34 本）。コンパイル済みビュー **326 本 / INVALID 0**（新しい部品 3 本の分増えた）。

変異（scratchpad の `mutate/specs_housing_land_cost.py`・実行役 `run_hlc.py`。関係するテスト 362 本＝`tests/Feature/Housing`・`OldInputRuleCoverageTest`・`JapaneseValidationMessagesTest`・`JsEchoClassificationTest`・`JsStringEchoScanTest`）。流す前に期待を書いた。1 回目は 23 通りのうち 22 通りが期待どおり。J6 は狙ったテストに加えて注文住宅の契約の編集のテストも落ちた（契約の編集は紐付け先を変えたとき `hasLink` を false で渡すので、そこでも「計算できない」が出る＝検出は正しく、表が狭かった）→ 表を直して J6 だけ流し直し、期待どおり。

| # | 変異 | 落ちたテスト |
|---|---|---|
| C0 | カナリア: 登録・編集の画面の「計算する」の文を変える | 1（文が無い） |
| D1〜D4 | `decide()` の 4 つの条件を 1 つずつ外す | 4・12・5・9（`decide` の 16 通り・`whenAutomatic`・突き合わせ・画面からの往復） |
| W1・W2 | `whenAutomatic()` が手動 ON・保存値を保存前の属性で読む | 1・1（「保存していない属性で決めた」） |
| F1 | 原価の行が無い仕入れ案件も計算する（0 になる） | 3 |
| F2 | 仕入れ案件の情報の API から `land_cost` を外す | 2 |
| A1 | 区画の一覧だけ按分の式がずれる（+1 円） | 2 |
| P1〜P4 | 4 つの保存の経路で手動 OFF の枝を外す（送られた値／保存済みのまま） | 7・4・3・4 |
| R1 | 建売の登録・編集で `refresh_land_cost` を読まない | 2 |
| J1〜J4 | JS の `willCompute` の 4 つの条件を 1 つずつ外す | 2・6・2・3（どれも突き合わせのテストを含む） |
| J5 | `refreshOffered` の「保存値と違う」を外す | 2 |
| J6 | `unavailable` の「紐付け先を選んでいる」を外す | 2（2 回目で期待どおり） |
| K1 | 注文住宅の契約の編集で紐付け先を変えても前の計算値を出す | 1 |
| O1 | 建売の `_form` で保存済みが手動 ON かを `old()` から作る | 1（「戻った画面で、手入力をやめたことを忘れた」） |

実ブラウザ（使い捨ての SQLite〈`route-coverage-tools/browser/demo.sqlite` の写しに、原価 25,000,000・区画 12/18/10/10 百万の分譲地・販売価格の欠けた分譲地・原価 7,850,000 の仕入れ案件・建売 4・契約 1・注文住宅 2 を足した〉＋ `artisan serve`・Playwright・画面が見えている状態。作業場所でビルドした CSS は本番と同じ名前 `app-o1yvrcrl.css`）:

| 画面 | 操作 | 結果 |
|---|---|---|
| 建売の編集（手動 ON・5,000,000） | 手動入力を外す → 更新 | 欄が 6,000,000 に替わり「保存すると、紐付け先から計算したこの値になります。」→ 保存も 6,000,000・手動 OFF |
| 建売の編集（手動 OFF・5,000,000） | 開く → チェック → 更新 | 「保存されている値は、今の紐付け先から計算した値（9,000,000円）と違います。」とチェック → 押すと欄が 9,000,000 → 保存も 9,000,000 |
| 建売の編集（販売価格の欠けた分譲地・手動 ON） | 手動入力を外す | 欄が空・「紐付け先から計算できません（…）。このまま保存すると空になります。」 |
| 建売の契約の編集（手動 OFF・3,000,000） | 開く → チェック → 更新 | 「3,000,000円 （RE-PRJ-951 土地原価の分譲地 > 3号地から取得した値）」・「自動参照」の文字なし → 5,000,000 で保存 |
| 注文住宅の契約の編集（仕入れ案件・手動 OFF・5,000,000） | チェックに触らず更新 | 7,850,000 との違いとチェックを出し、保存は 5,000,000 のまま |
| 注文住宅の編集（手動 ON・4,000,000） | 手動入力を外す → 更新 | 5,000,000 に替わり、保存も 5,000,000 |

- 4 画面とも 1440px・375px で `main` の横スクロール 0。セッション全体でコンソールのエラー 0・警告 0
- ⚠ 375px の契約の編集で「今の計算値（7,850,000円）にする」が、flex のラベルの項目ごとに縦に割れた（「今の / 計算 / 値（」・金額・「）にする」が別々の列）。1440px でも括弧の内側に flex の隙間が入っていた → 文を 1 つの span にまとめ（2 つの部品）、構造のテスト `test_the_refresh_label_reads_as_one_run_of_text` を足した（直す前に赤）。直したあと 375px・1440px とも 1 つの文として流れることを確かめた

### 最後の点検（2026-11-20）

別の担当（いちばん強いモデル・読み取りだけ）に枝全体（`a7c40dab..ade0c7c6`）と Review Focus 1〜5 を渡した。判定「マージしてよい」・Critical・Important 0・Minor 6。Focus 1〜5 はすべて満たすと読み、`land_cost` を書く経路が 4 つだけであることも数え直していた。

利用者に届く影響で格付けし直し、2 つを Important として直した（直す前に赤のテストを書き、実ブラウザで確かめた）:

| 指摘 | 直したこと | テスト |
|---|---|---|
| 紐付けの無い行で手動入力を外すと、欄は空なのに「保存すると、紐付け先から計算したこの値になります。」と出る（保存すると前の手入力の値が消える） | 緑の文は値があるときだけ出す（2 つの部品） | `test_an_unlinked_row_does_not_claim_a_computed_value`（紐付けのある行では今どおり出すことも見る） |
| 新しい部品の文が Alpine の起動まで一瞬見える（契約の編集の画面は `style="display: none;"` で隠す流儀で、置き換えた部分だけ抜けていた） | 表示の決まりで出し分ける要素を最初は隠す（`_form` の 2 つの入力欄は `_form` の流儀のまま） | `test_the_land_cost_messages_start_hidden` |

実ブラウザ（同じ使い捨ての環境）: 契約の編集で値・出どころ・「今の計算値にする」の 3 つだけが見え、ほかの 4 つは隠れたまま／紐付けの無い建売で手動入力を外すと文は出ない／紐付けのある建売では今どおり緑の文と 6,000,000／コンソールのエラー・警告 0。

後回しにした Minor 4 つ（台帳の `Final: minor (deferred)`）: 「今の計算値にする」を付けたまま計算できなくなると空で保存される／仕入れ案件の情報の取得の失敗・応答の前後／突き合わせのテストが紐付けの比べ方を見ていない／API が仕入れ案件をもう一度引く。

最後の点検の直しのあとの全件: **OK (5378 tests, 58250 assertions)**・警告なし（着手前の 5342 本に 36 本）。コンパイル済みビュー **326 本 / INVALID 0**。
