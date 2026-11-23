# 区画を足すとき、区画を選んでいない契約などをその区画につなぐ Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 区画の無い土地に契約などがある状態（本番の #21）で区画を足すと、その区画が契約などにつながり、契約を消せば「販売中」に戻るようにする。あわせて、区画のある土地で区画を選んでいない見積りの注文住宅を「契約」へ進めさせない。

**Architecture:** 「区画を選んでいないもの」の顔ぶれは `ProcurementLotClaims::unlinkedTakers()`（取られ方の判定と同じクラス・同じ顔ぶれ）が決め、新しいクラス `ProcurementLotLinks` が候補（キー・名前・つないだ区画のステータス）と、区画の列だけを書くつなぎ方を持つ。区画の登録（`ProcurementLotController::store()`）は候補を 1 つの入力チェックで確かめ（画面を開いたあとで顔ぶれが変わったら断る）、区画の作成とつなぐ書き込みを 1 つのトランザクションで行う。注文住宅は一覧の小窓と編集画面の 2 か所で決まり 7 を足す。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade（追加のフォームは Alpine を使わないふつうの送信）/ PHPUnit（SQLite）/ 画面の JS は既存の道具（`DrivesAlpineFetch`）

**Spec:** `docs/superpowers/specs/2026-11-22-lot-link-on-add-design.md`

## 決めたこと（設計書の「作る人に任せること」。承認のとき利用者に見てもらう）

| # | 決めたこと |
|---|---|
| 1 | 顔ぶれは `ProcurementLotClaims::unlinkedTakers()` に置く（`takers()` と同じ「区画を取るもの」の定義を 1 か所に保つ。Bug #41）。候補・つなぎ方は新しいクラス `App\Support\ProcurementLotLinks`（本番反映で `composer dump-autoload` が要る） |
| 2 | 選択欄の値は `contract:12`・`property:3`・`order:5`（種類と id）。追加のフォームは hidden の `lot_link_candidates` に、開いたときの候補のキーを並びのまま「,」でつないで持つ（候補が無ければ空） |
| 3 | 決まり 5（顔ぶれが変わった）は、上の帯でなく**入力エラー**（欄「紐づけるもの」）で断る。打った号地・面積などが残り、戻った画面には今の候補が出るので、そのまま選び直して送れる（設計書の「開き直して選び直す」と同じ結果で、手間が少ない） |
| 4 | 1 件のときは hidden で送り、「この区画は契約「…」に紐づけます（区画のステータスは「成約」になります）。」を出す。2 件以上のときは選択欄（必須・「— 選んでください —」から始める）。選択肢は「建売物件「HS-001 勝山の建売」（区画は販売中）」のように、つないだときの区画のステータスを添える |
| 5 | つなぐ書き込みは区画の列だけを、モデルを通さない問い合わせで書く（保存のフック・更新日時・更新者を動かさない。決まり 6） |
| 6 | 区画の作成・つなぐ書き込み・仕入れ案件のステータスの集約を 1 つのトランザクションにする（今の登録はトランザクションを使っていない） |
| 7 | 決まり 7: 一覧の小窓は 422 で「区画のある土地で区画を選んでいないため、「契約」へ進められません。編集画面で区画を選んでから進めてください。」。編集画面は区画の欄の誤りで「区画のある土地で区画を選んでいないため、「契約」へ進められません。区画を選んでください。」（今の「区画を選ばずに保存すると…」の例外は、「契約」へ進める保存には効かせない）。注文住宅の編集画面の案内は「区画を選ばずに保存すると、今のまま（区画なし）で保存します（「契約」へ進めるときは区画を選んでください）。」にする（不動産の契約・契約一覧から開く注文住宅の契約の編集の案内は今のまま） |
| 8 | 区画の行の編集（更新）は変えない。新しい欄の規則は `exclude`（画面が `old()` で描く欄は同じ画面のすべての送り先に規則が要る。Bug #121） |

## Global Constraints

- 「区画を選んでいないもの」: その仕入れ案件を土地元にしていて区画が空の、不動産の契約（`procurement_id` がその案件・`procurement_lot_id` が空）・建売（保存済みの `land_source_type` が `procurement`・`re_procurement_id` がその案件・`re_procurement_lot_id` が空。契約の前も）・契約以降の注文住宅（同じ列 ＋ ステータスが `isContractedOrLater()`）。見積り以前の注文住宅は入れない
- 並び: 契約・建売・注文住宅の順、それぞれ id の順
- つないだ区画のステータス: 不動産の契約・契約のある建売（`HsProperty::contract()` がある）・契約以降の注文住宅 → `sold`／契約の無い建売 → `on_sale`
- 名前の形は `DeletionBlockers::assemble()` と同じ（例 `契約「勝山の土地（山田 太郎 様）」`・`建売物件「HS-001 勝山の建売」`・`注文住宅「CO-001 勝山の注文住宅」`）
- 文（全文・この字のまま）:
  - 登録の帯（つないだとき）: `区画を登録しました（契約「勝山の土地（山田 太郎 様）」をこの区画に紐づけました）。`（`%s` に名前）
  - 登録の帯（つながないとき）: `区画を登録しました。`（今のまま）
  - 1 件の案内: `この区画は契約「勝山の土地（山田 太郎 様）」に紐づけます（区画のステータスは「成約」になります）。`
  - 2 件以上の案内: `区画を選んでいない契約・建売物件・注文住宅が N 件あります。この区画に紐づけるものを選んでください（区画を足すたびに 1 つずつ紐づけます。区画のステータスは、紐づけるものに合わせて決まります）。`
  - 選んでいない: `この区画に紐づけるものを選んでください。`
  - 顔ぶれが変わった: `区画を選んでいない契約・建売物件・注文住宅が、画面を開いたあとで変わりました。紐づけるものを確かめて、もう一度登録してください。`
  - 決まり 7 の 2 つの文と案内は「決めたこと 7」のとおり
- 和名（`lang/ja/validation.php` の attributes）: `lot_link` → `紐づけるもの`・`lot_link_candidates` → `紐づけるものの一覧`
- つなぐときに書くのは区画の列だけ（土地原価・契約の原価・更新者・更新日時・ステータスは変えない）
- 区画を選んでいないものが無い土地の追加のフォームは今のまま（ステータスの欄を出す）。初期値はいつも `on_sale`。`ReProcurement::defaultLotStatus()` は消す
- DB・ルートは変えない。新しい PHP クラスは `App\Support\ProcurementLotLinks` の 1 つ
- テストは CLAUDE.md の流し方（`APP_KEY` を環境変数・PHP 8.3 を名指し・OPcache あり）。データプロバイダは `#[DataProvider]` の属性。送る値は描いた画面のフォームから取る（Bug #47）。入力エラーの期待文は 1 件の全文で見る（Bug #49）
- `git stash` を使わない（作業場所どうしで共有される）

## Review Focus

- 開いたあとで顔ぶれが変わった送信（区画を選んでいないものが増えた・消えた・この変更の前に開いた画面で新しい欄が無い）は、何も保存せず「顔ぶれが変わった」の文を出す → Task 2 の `test_an_old_screen_is_refused_when_the_owners_changed`（3 つの形）
- 手で組んだ `lot_link`（候補に無いもの・ほかの土地の契約・見積りの注文住宅・無い id）は断り、ほかの土地の契約に区画を書かない → Task 2 の `test_a_hand_made_link_outside_the_list_is_refused`
- 配列で送られた `lot_link`・`lot_link_candidates` は 500 にせず断り、戻った画面が開く（Bug #121）→ Task 2 の `test_array_shaped_link_fields_are_refused`
- 2 件以上で選んだあと号地の重なりなどで断られて戻ると、打った値と選んだものが残る → Task 2 の `test_an_input_error_keeps_the_chosen_owner`
- つないでも、つないだものの更新日時・更新者・原価・土地原価・ステータスは変わらない → Task 1 の `test_link_writes_only_the_lot_column`

---

### Task 0: 作業場所を整える（済み・2026-11-22）

- [x] **Step 1:** 作業場所 `/Users/masanori/site/manage/.claude/worktrees/lot-link-on-add`（枝 `lot-link-on-add`・13.x = `790f502f` から・設計 `aeb6b438`）に `cp -a /Users/masanori/site/manage/vendor ./vendor && composer install --no-scripts --no-interaction`（`.env` は作らない）
- [x] **Step 2:** 直す前の動きを使い捨てのテストで確かめた（実測記録）

---

### Task 1: 区画を選んでいないものの顔ぶれ・候補・つなぎ方

**Files:**
- Modify: `app/Support/ProcurementLotClaims.php`（`unlinkedTakers()`・`lotMissing()` を足す）
- Create: `app/Support/ProcurementLotLinks.php`
- Test: `tests/Feature/RealEstate/ProcurementLotClaimsTest.php`（節「区画を足すときに紐づけるもの」を足す。今の土台 `contractOn()`・`propertyOn()`・`orderOn()` を使う。新しい土地は `landProcurement(['procurement_code' => 'RE-PRC-002', …])`）

**Interfaces:**
- Produces（`ProcurementLotClaims`）:
  - `public static function unlinkedTakers(ReProcurement $procurement): array` — `array<int, ReContract|HsProperty|HsCustomOrder>`（Global Constraints の顔ぶれと並び）
  - `public static function lotMissing(ReContract|HsProperty|HsCustomOrder $owner): bool` — 保存済みの土地元（`savedProcurementId()`）に区画が 1 つ以上あり、保存済みの区画（`savedLotId()`）が空なら true
- Produces（`ProcurementLotLinks`・final・static だけ）:
  - `public static function candidates(ReProcurement $procurement): array` — `array<int, array{key: string, label: string, status: LotStatus, owner: ReContract|HsProperty|HsCustomOrder}>`（`unlinkedTakers()` の並びのまま）
  - `public static function fingerprint(array $candidates): string` — キーを並びのまま「,」でつなぐ（空なら `''`）
  - `public static function link(ReProcurementLot $lot, ReContract|HsProperty|HsCustomOrder $owner): void` — 契約は `procurement_lot_id`、建売・注文住宅は `re_procurement_lot_id` だけを `$lot->id` にする（`Model::query()->whereKey(...)->toBase()->update([...])`。更新日時も動かさない）

- [ ] **Step 1: テストを書く**

```php
test_unlinked_takers_are_the_owners_without_a_lot_on_that_land()
    // 区画の無い土地 RE-PRC-002 に: 契約（区画なし）・建売 A（区画なし・契約なし）・建売 B（区画なし・HsContract あり）・
    // 注文住宅 契約（区画なし）・注文住宅 見積り（区画なし）・注文住宅 着工（区画なし）
    // ほかに数えないもの: 土台の土地（区画 1〜5）に付いた 5 つ・別の土地 RE-PRC-003 の区画なしの契約・
    // 種別が customer_land で re_procurement_id だけ残った建売（create のあとで DB の列を直に書く）
    // → unlinkedTakers(RE-PRC-002) の [クラス名, id] が [契約, 建売 A, 建売 B, 注文住宅 契約, 注文住宅 着工] の順
test_lot_missing()
    // 区画の無い土地の区画なしの契約 → false ／ 区画のある土地（土台）に区画なしの見積りの注文住宅 → true ／
    // 土台の区画 3 の見積り（$this->estimate）→ false ／ 種別が customer_land の注文住宅 → false
#[DataProvider('linkedStatuses')]
test_the_linked_lot_status_follows_the_owner(string $case, LotStatus $expected)
    // provider（ProcurementLotModelTest の statusCases から写す）: 契約 => sold・契約の無い建売 => on_sale・契約のある建売 => sold・
    // 注文住宅 契約 => sold・注文住宅 着工 => sold。区画の無い土地にその 1 つだけ作り、candidates() がちょうど 1 件・その status が $expected
test_candidates_carry_the_key_and_the_label()
    // 契約（買主 山田 太郎）・建売 HS-001・注文住宅 CO-002 → key が ['contract:<id>', 'property:<id>', 'order:<id>']、
    // label が ['契約「勝山の土地（山田 太郎 様）」', '建売物件「HS-001 勝山の建売」', '注文住宅「CO-002 勝山の注文住宅」']、
    // fingerprint が 'contract:<id>,property:<id>,order:<id>'。候補が無い土地の fingerprint は ''
test_link_writes_only_the_lot_column()
    // 区画の無い土地の契約（cost_amount 9000000・updated_by は別の利用者）・契約の無い建売（land_cost 5000000・is_land_cost_manual false）・
    // 契約の注文住宅。区画を 3 つ作ってから $this->travel(1)->days()、それぞれ link() →
    // 区画の列はその区画の id、ほかの列（updated_at を含む）は link() の前の getAttributes() と同じ（区画の列を除いて比べる）
```

- [ ] **Step 2: 赤を確かめる**

Run: `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit tests/Feature/RealEstate/ProcurementLotClaimsTest.php`
Expected: 新しい 5 メソッドが `Call to undefined method …unlinkedTakers()` / `Class "App\Support\ProcurementLotLinks" not found` で赤・既存は緑

- [ ] **Step 3: `ProcurementLotClaims::unlinkedTakers()`・`lotMissing()` を書く**

`takers()` と同じ列とステータスの絞り方（`contractedOrderStatuses()`）を使う。住宅事業は `land_source_type = procurement` で絞る。`lotMissing()` は `savedProcurementId()`・`savedLotId()` を使う（保存済みの値で読む）。docblock に「区画を足すとき（`ProcurementLotLinks`）と注文住宅を『契約』へ進めるとき（決まり 7）が使う」と書く。

- [ ] **Step 4: `ProcurementLotLinks` を書く**

名前は `DeletionBlockers::assemble()` に 1 件の Eloquent のコレクション（契約は `buyer` を読んで）を渡し、`label . '「' . items[0].name . '」'`。ステータスは Global Constraints の対応（建売は `contract()->exists()`）。docblock に設計書の名前と決まり 1・2・6 を書く。

- [ ] **Step 5: 緑を確かめる**

Run: `… vendor/bin/phpunit tests/Feature/RealEstate/ProcurementLotClaimsTest.php` → `OK`

- [ ] **Step 6: コミット**

```bash
git add app/Support/ProcurementLotClaims.php app/Support/ProcurementLotLinks.php tests/Feature/RealEstate/ProcurementLotClaimsTest.php
git commit -m "feat: 区画を選んでいない契約などの顔ぶれと、区画につなぐ部品を足す

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: 区画の登録でつなぐ（追加のフォーム・入力チェック・保存）

**Files:**
- Modify: `app/Http/Controllers/RealEstate/ProcurementLotController.php`（`store()`・`validated()`・`check()`・文の定数）
- Modify: `app/Http/Controllers/RealEstate/ProcurementController.php`（`show()`: `$lotLinks`・`$lotLinkFingerprint` を渡す・初期のステータスを `on_sale` に）
- Modify: `app/Models/ReProcurement.php`（`defaultLotStatus()` を消す。使わなくなった use を外す）
- Modify: `resources/views/realestate/procurements/_lot_section.blade.php`（追加のフォーム）
- Modify: `lang/ja/validation.php`（和名 2 つ）
- Create: `tests/Feature/RealEstate/Screens/ProcurementLotLinkScreensTest.php`（`RealEstateScreenTestCase` を継ぐ）
- Modify（意味が変わる既存のテスト）:
  - `tests/Feature/RealEstate/Screens/ProcurementLotScreensTest.php`: `test_the_registration_form_starts_sold_when_the_land_is_already_sold` を消す（新しいファイルの 1 本目に替わる）・`test_the_create_form_defaults` の 2 つ目の土地から契約と `defaultLotStatus()` の前提の行を外す
  - `tests/Feature/RealEstate/Screens/ProcurementLotModelTest.php`: `statusCases()` と `test_the_default_status_follows_existing_links` を消す（Task 1 の `linkedStatuses` に替わる）
  - `tests/Feature/RealEstate/Screens/ContractProcurementLotTest.php`: `test_a_legacy_contract_without_a_lot_saves_as_is` の区画は、追加のフォームでなく `procurementLot($land, ['status' => 'sold'])` で作る（この変更より前に作られた #21 の形のデータ）

**Interfaces:**
- Consumes: Task 1 の `ProcurementLotLinks::candidates()`・`fingerprint()`・`link()`
- Produces（`ProcurementLotController`）: `LINKED = '区画を登録しました（%sをこの区画に紐づけました）。'`・`LINK_STALE`（顔ぶれが変わった文）
- `check(Request $request, string $statuses, ReProcurement $procurement, ?ReProcurementLot $lot, ?array $links): array` — `$links` は登録では `candidates()`、更新では null。`Validator::make(...)->after(...)->validate()` にする（今の `$request->validate()` から）
  - 規則: `status` は「登録で候補がある」とき `exclude`、ほかは今の `required|in:…` ／ `lot_link` は候補があるとき `['bail', 'required', 'string', Rule::in(キー)]`、ほかは `exclude` ／ `lot_link_candidates` は登録なら `nullable|string`、更新なら `exclude`
  - after（登録だけ）: `fingerprint($links) !== (string) ($request->input('lot_link_candidates') ?? '')` なら `lot_link` の誤りを消して `LINK_STALE` の 1 件にする
- ビューに渡す: `$lotLinks`（`candidates()` の結果。土地でなければ `[]`）・`$lotLinkFingerprint`

- [ ] **Step 1: 新しいファイルにテストを書く**

土台（private）: `lotlessContract(ReProcurement $land): ReContract`（買主 山田 太郎・区画なし・`cost_amount` 9000000）・`building(ReProcurement $land, string $code, bool $contracted): HsProperty`・`order(ReProcurement $land, string $code, string $status): HsCustomOrder`。フォームは `id="lot-create-form"` で掴む。

```php
test_adding_a_lot_links_the_only_contract_without_a_lot()
    // 土地 status=sold ＋ lotlessContract。詳細の追加のフォーム: status の欄が無い・lot_link = 'contract:<id>'・
    // lot_link_candidates = 同じ・画面に 1 件の案内の全文。そのまま送る → 区画の欄の帯（assertLotNotice）に LINKED の全文・
    // 区画は sold・契約の procurement_lot_id = 区画・土地は sold・区画の表のその行の「紐づいているもの」に契約のリンク
test_the_linked_lot_returns_to_on_sale_when_its_contract_is_deleted()
    // 上の続き → 契約の詳細の削除フォームを送る → 区画 on_sale・土地 selling（設計書の目標 2）
test_a_second_contract_on_the_linked_lot_is_refused_even_after_it_is_set_on_sale()
    // つないだあと、区画の行の編集フォームで status=on_sale にして送る → 契約の登録の画面と同じ送り先に、その区画で 2 件目を送る →
    // ProcurementLotClaims::TAKEN の文（契約「勝山の土地（山田 太郎 様）」）・契約は 1 件のまま（直す前の ① の形）
test_two_or_more_owners_are_chosen_on_the_form()
    // 区画の無い土地に 建売 HS-001（契約なし）・建売 HS-002（契約あり）・注文住宅 CO-001（契約）・注文住宅 CO-002（見積り）
    // → 選択欄 id="lot-new-link" の option の value が ['', 'property:<HS-001>', 'property:<HS-002>', 'order:<CO-001>']、
    //   1 つ目の名前が「建売物件「HS-001 勝山の建売」（区画は販売中）」・2 件以上の案内（N=3）の全文・status の欄が無い
    // 選ばずに送る → 「この区画に紐づけるものを選んでください。」・区画 0
    // HS-001 を選ぶ → 区画 on_sale・HS-001 につながる。戻った画面の候補は HS-002・CO-001 の 2 件（fingerprint も 2 件）
    // CO-001 を選ぶ → 区画 sold・CO-001 につながる。残りは HS-002 の 1 件（1 件の案内に替わる）
test_a_land_without_owners_keeps_the_status_field()
    // 区画の無い土地に見積りの注文住宅（区画なし）だけ → 追加のフォームに status の欄（初期値 on_sale）・lot_link が無い・
    // lot_link_candidates は '' → status=negotiating で送る → 区画 negotiating・帯は「区画を登録しました。」・注文住宅の区画は空のまま
#[DataProvider('changedOwners')]
test_an_old_screen_is_refused_when_the_owners_changed(string $case)
    // 'appeared': 候補の無いときに開いたフォーム → lotlessContract() を作ってから送る
    // 'gone': 契約 1 件で開いたフォーム → 契約を削除してから送る
    // 'old screen': 契約 1 件があるのに、フォームから lot_link と lot_link_candidates を外し status=on_sale を足して送る（この変更の前に開いた画面）
    // → どれも assertErrorItem(LINK_STALE)・区画 0・契約があれば区画は空のまま・戻った画面の号地の欄に送った値が残る
#[DataProvider('handMadeLinks')]
test_a_hand_made_link_outside_the_list_is_refused(string $case)
    // 契約 1 件の土地で hidden の lot_link を書き換える: 'contract:999999'・別の区画の無い土地の契約・同じ土地の見積りの注文住宅・'x'
    // → trans('validation.in', ['attribute' => '紐づけるもの'])・区画 0・別の土地の契約の区画は空のまま
#[DataProvider('arrayFields')]
test_array_shaped_link_fields_are_refused(string $field)
    // 契約 1 件の土地で $field（lot_link / lot_link_candidates）を ['x'] にして送る → 500 にならず #lot へ戻る・
    // 戻った画面が開く・trans('validation.string', ['attribute' => 和名])・区画 0
test_an_input_error_keeps_the_chosen_owner()
    // 区画 1 号地がある土地に建売 2 件（どちらも区画なし。この変更の前のデータの形）→ 2 件目を選び、号地を 1 にして送る →
    // ProcurementLotController::DUPLICATE_NUMBER の文・戻ったフォームの lot_link が 2 件目・lot_number が '1'・区画は 1 つのまま
```

- [ ] **Step 2: 赤を確かめる**

Run: `… vendor/bin/phpunit tests/Feature/RealEstate/Screens/ProcurementLotLinkScreensTest.php`
Expected: 新しいテストが赤（フォームに lot_link が無い・status の欄がある・帯の文が違う・つながっていない）。`test_a_land_without_owners_keeps_the_status_field` は直す前から緑（直しすぎの守り）

- [ ] **Step 3: コントローラを直す**

`store()`: 土地でなければ今どおり → `$links = ProcurementLotLinks::candidates($procurement)` → `validated()` → 選んだキーの候補を `$links` から引く（候補が無ければ null）→ `DB::transaction()` の中で区画を作る（ステータスは候補があればその `status`、なければ入力の `status`）・候補があれば `link()`・`syncStatusFromLots()` → 帯は候補があれば `sprintf(self::LINKED, label)`。`lot_form`・`lot_link`・`lot_link_candidates` は保存しない。`update()` は `$links = null` を渡すだけ（ほかは変えない）。messages に `'lot_link.required' => 'この区画に紐づけるものを選んでください。'`。クラスの docblock に決まり 1・2・5 と「決めたこと 3・5・6」を 1 行ずつ足す。

`ProcurementController::show()`: `$lotCreateDefaults['status']` を両方 `LotStatus::OnSale->value` にし、コメントを直す。`$lotLinks`・`$lotLinkFingerprint` を compact に足す。

- [ ] **Step 4: 追加のフォームを直す（`_lot_section.blade.php`）**

先頭の `@php`: `$lotKeys` に `lot_link`・`lot_link_candidates` を足す。`$createStatus` は `$createOld && is_string(old('status'))` のときだけ `old('status')`、ほかは `$lotCreateDefaults['status']`（顔ぶれが変わって戻った画面で、ステータスの欄が「未販売」で始まらないように）。

フォームの中（ステータスの欄の位置）:
- いつも `<input type="hidden" name="lot_link_candidates" value="{{ $lotLinkFingerprint }}">`
- 候補 1 件: hidden の `lot_link` と `<p id="lot-link-notice" class="sm:col-span-2 … wrap-anywhere">`（1 件の案内）
- 候補 2 件以上: `<label for="lot-new-link">この区画に紐づけるもの *</label>`・`<select id="lot-new-link" name="lot_link" required>`（`— 選んでください —` の空の選択肢・`@selected($createOld && old('lot_link') === key)`）・2 件以上の案内
- 候補 0 件: 今のステータスの選択欄

先頭の Blade コメントに「区画を選んでいないものがある土地は、ステータスの欄の代わりに紐づけるものを出す（設計書・決まり 1・2）」を 1 行足す。

- [ ] **Step 5: 消す・直す**

`ReProcurement::defaultLotStatus()` を消す（`grep -rn defaultLotStatus app resources tests` が 0 件）。`lang/ja/validation.php` に和名 2 つ（`lot_form` の行の下）。Files に書いた既存のテスト 3 ファイルを直す。

- [ ] **Step 6: 緑を確かめる**

Run: `… vendor/bin/phpunit tests/Feature/RealEstate` → `OK`
Run: `… vendor/bin/phpunit tests/Feature/OldInputRuleCoverageTest.php` → `OK`（落ちて、ほかの送り先を名指ししたら、その送り先の入力チェックに `'lot_link' => 'exclude'` を足す）
Run: `… vendor/bin/phpunit tests/Feature/JapaneseValidationMessagesTest.php` → `OK`

- [ ] **Step 7: コミット**

```bash
git add app/Http/Controllers/RealEstate/ProcurementLotController.php app/Http/Controllers/RealEstate/ProcurementController.php app/Models/ReProcurement.php resources/views/realestate/procurements/_lot_section.blade.php lang/ja/validation.php tests/Feature/RealEstate
git commit -m "fix: 区画を足すとき区画を選んでいない契約などをその区画につなぐ

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: 区画を選んでいない見積りの注文住宅を「契約」へ進めさせない（決まり 7）

**Files:**
- Modify: `app/Models/HsCustomOrder.php`（文の定数 2 つ）
- Modify: `app/Http/Controllers/Housing/CustomOrderController.php`（`updateStatus()`・`validateOrder()`）
- Modify: `resources/views/housing/custom-orders/_form.blade.php`（区画なしの案内の文）
- Test: `tests/Feature/Housing/Screens/ProcurementLotCustomOrderTest.php`

**Interfaces:**
- Consumes: Task 1 の `ProcurementLotClaims::lotMissing()`
- Produces: `HsCustomOrder::CONTRACT_NEEDS_LOT`（一覧の小窓の文）・`HsCustomOrder::CONTRACT_NEEDS_LOT_HERE`（編集画面の文）

- [ ] **Step 1: テストを書く・直す**

```php
test_the_popup_refuses_contracted_for_an_order_without_a_lot_on_a_land_with_lots()
    // twoLotLand() に orderOn($land, null, ['status' => 'estimation']) → changeStatusFromList('contracted', '契約') →
    // 422・json === ['success' => false, 'message' => CONTRACT_NEEDS_LOT の全文]・alerts がその 1 つ・状態は見積りのまま・区画と土地の状態は変わらない
test_the_popup_still_moves_an_order_on_a_land_without_lots()
    // 区画の無い土地の見積りの注文住宅（区画なし）→ 小窓で「契約」へ → 200・contracted（直しすぎの守り）
test_a_contracted_order_without_a_lot_moves_on_from_the_popup()
    // 区画のある土地に、区画の無い契約の注文住宅（この変更の前のデータ）→ 小窓で「着工」へ → 200（「契約」をまたがないので断らない）
test_the_edit_screen_refuses_contracted_until_a_lot_is_chosen()
    // 区画のある土地の見積りの注文住宅（区画なし）の編集画面 → status=contracted で送る → assertErrorItem(CONTRACT_NEEDS_LOT_HERE)・見積りのまま
    // → 区画 1 を選んで（composedForm の chooseLot）status=contracted → 成功の帯・区画 1 sold
```

直す: `test_a_legacy_order_without_a_lot_saves_as_is` の「小窓で『契約』へ進めても区画は動かない」の段を、422（`CONTRACT_NEEDS_LOT`）で見積りのまま・区画と土地の状態が変わらないことに替える。その後の「契約済みの編集画面・契約の編集もそのまま保存できる」の段は、`orderForContractEdit($land, null, ['order_code' => 'CO-002', 'status' => 'contracted'])` で作った区画の無い契約の注文住宅で見る。`LEGACY_NOTICE` はこのファイルでは注文住宅の編集画面の新しい案内（決めたこと 7）にし、契約の編集の画面の案内は今の文の定数で見る。

- [ ] **Step 2: 赤を確かめる**

Run: `… vendor/bin/phpunit tests/Feature/Housing/Screens/ProcurementLotCustomOrderTest.php`
Expected: 小窓と編集画面で断る 2 本と、直した legacy のテストが赤（小窓が 200・編集画面が保存してしまう・案内の文が違う）。直しすぎの守りの 2 本は緑

- [ ] **Step 3: 直す**

`updateStatus()`: 「契約」をまたぐ分岐の先頭で `ProcurementLotClaims::lotMissing($customOrder)` なら `CONTRACT_NEEDS_LOT` で 422（今の `refusal()` の応答と同じ形・errors を付けない）。

`validateOrder()`: 今の after の中の `$selfTakes`（保存するステータスが「契約」以降で、保存済みは「契約」より前）を `Validator::make` の前で求めて after でも使う。`$lotRequired = $isProcurement && ProcurementLotClaims::requiresLot($chosen, $selfTakes ? null : $current)`。区画が要るのが決まり 7 のためだけ（`requiresLot($chosen, $current)` は false）のとき、`'re_procurement_lot_id.required'` の文を `CONTRACT_NEEDS_LOT_HERE` にする（ほかは今の「区画を選んでください。」）。

`_form.blade.php` の区画なしの案内を決めたこと 7 の文に替える。

- [ ] **Step 4: 緑を確かめる**

Run: `… vendor/bin/phpunit tests/Feature/Housing` → `OK`

- [ ] **Step 5: コミット**

```bash
git add app/Models/HsCustomOrder.php app/Http/Controllers/Housing/CustomOrderController.php resources/views/housing/custom-orders/_form.blade.php tests/Feature/Housing/Screens/ProcurementLotCustomOrderTest.php
git commit -m "fix: 区画のある土地で区画を選んでいない注文住宅を「契約」へ進めさせない

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: 確かめ（全件・変異・実ブラウザ・最後の点検）

- [ ] **Step 1: 全件** — CLAUDE.md の流し方に `--display-phpunit-deprecations` を足して流す。Expected: `OK (5586 + 足した本数 − 消した本数 tests, …)`・警告なし。コンパイル済みビューの lint（`php artisan view:cache` → `storage/framework/views/*.php` を `php -l` → `view:clear`）INVALID 0
- [ ] **Step 2: 変異** — main repo の `.claude/route-coverage-tools/mutate/run_lml.py` を作業場所の `.claude/route-coverage-tools/mutate/` に写して `run_lla.py`・表は `specs_lot_link_on_add.py`（作業場所の会話からは main repo の `.claude/` へ書けない。作業場所を消す前に親の会話が main repo へ写す）。docs/RULES.md Bug #44 の作法（先にコミット・各変異の前に `git status --porcelain` が空・置き換えはちょうど 1 か所・落ちたテストと理由の 1 行目まで流す前に書く）。流すのは `tests/Feature/RealEstate` と `tests/Feature/Housing`

| # | 変異 | 期待（落ちるテスト） |
|---|---|---|
| C0 | カナリア: `LINKED` の文を 1 字変える | つなぐ帯を見るテスト（1 件目・2 件以上） |
| K1 | `unlinkedTakers()` の注文住宅のステータスの絞りを外す | 顔ぶれ・`test_a_land_without_owners_keeps_the_status_field`・2 件以上（option の並び） |
| K2 | 建売・注文住宅の `land_source_type` の絞りを外す | 顔ぶれ（customer_land の建売） |
| K3 | 仕入れ案件の絞りを外す | 顔ぶれ・手組みの「別の土地の契約」 |
| K4 | 区画が空の絞りを外す | 顔ぶれ・`lotMissing`・2 件以上（つないだあとも残る） |
| K5 | `lotMissing()` が区画の有無を見ない | `test_the_popup_still_moves_an_order_on_a_land_without_lots`・`test_lot_missing` |
| S1 | 建売のステータスを契約の有無によらず on_sale | 状態の「契約のある建売」 |
| S2 | どれも sold | 状態の「契約の無い建売」・2 件以上（HS-001 は販売中） |
| S3 | 区画のステータスを候補でなく入力から取る（無ければ on_sale） | 1 件目の契約（sold のはず） |
| L1 | `link()` を呼ばない | 1 件目・削除で戻る・2 件目の契約を断る |
| L2 | `link()` をモデルの `update()` で書く | `test_link_writes_only_the_lot_column`（updated_at） |
| L3 | `link()` の契約の列を `re_procurement_lot_id` に取り違える | 1 件目ほか（SQL の誤り） |
| F1 | 顔ぶれの確かめ（after）を外す | 顔ぶれが変わった 3 データ |
| F2 | 候補が無いときは顔ぶれを確かめない | 顔ぶれが変わった の 'appeared' |
| V1 | `lot_link` の `Rule::in` を外す | 手組みの 4 データ |
| V2 | 候補があっても `status` を必須にする | 1 件目・2 件以上 |
| V3 | 1 件のとき hidden の `lot_link` を描かない | 1 件目（選んでいない文） |
| V4 | 2 件以上の選択肢の `@selected(old)` を外す | `test_an_input_error_keeps_the_chosen_owner` |
| V5 | 候補があってもステータスの欄を描く | 1 件目・2 件以上（status の欄が無いはず） |
| H1 | `updateStatus()` の `lotMissing()` の断りを外す | 小窓で断る 1 本・直した legacy |
| H2 | `validateOrder()` の `requiresLot()` に今までどおり `$current` を渡す | 編集画面で断る 1 本 |
| H3 | 決まり 7 の文を「区画を選んでください。」のままにする | 編集画面で断る 1 本 |
| H4 | `$selfTakes` が保存済みの「契約」以降を見ない（保存するステータスだけで決める） | 直した legacy の「区画の無い契約の注文住宅を編集画面でそのまま保存」の段（区画が要るとされ、土地は読み取り専用で送られないので断られる） |

緑のまま通った変異があれば、テストを足して赤を確かめてから記録する（等価なら理由を書く）。

- [ ] **Step 3: 実ブラウザ**（使い捨ての SQLite ＋ `artisan serve`・Playwright・画面が見えている状態。作業場所で `npm run build`）: #21 の形の土地の詳細で 1 件の案内 → 登録 → 区画の表が成約・「紐づいているもの」に契約 → 契約を削除 → 区画が販売中。区画を選んでいない建売 2 件と契約の注文住宅の土地で選択欄 → 選ばずに送る → 選んで送る。開いたあとで（tinker で）契約を足してから送る → 顔ぶれの文。注文住宅の一覧の小窓で区画なしの見積りを「契約」へ → alert。375px と 1440px で `main` の横スクロール 0・案内の文が枠の中・コンソールのエラー 0
- [ ] **Step 4: 最後の点検** — 別の担当（いちばん強いモデル・読み取りだけ）に枝全体を見てもらう。指摘を直したら範囲を絞って再点検

---

### Task 5: 記録

**Files:**
- Modify: `docs/RULES.md`（Bug #146 の行。番号は書く直前に最新の 13.x で数える）
- Modify: `CLAUDE.md`（「全 145 件」「Bug #1–145」を 146 に）
- Modify: `docs/BACKLOG.md`（新しい節「区画を足すとき、区画を選んでいない契約などをその区画につなぐ」・「土地の仕入れ案件に区画を複数登録できるようにする」の節の「利用者に伝えること」の 1 つ目に → を書き足す・「バックログ完了状況」の段落）
- Modify: `docs/ARCHITECTURE.md`（`re_procurement_lots` の行に「区画を足すとき、区画を選んでいない契約・建売・契約以降の注文住宅があれば 1 つをつなぐ（`ProcurementLotLinks`）」）
- Modify: この計画の末尾の「実測記録」

- [ ] **Step 1: 書く**（RULES の行は「症状・原因・直し方・テスト・変異」の形。BACKLOG の節は「直す前」「直したこと」「決めたこと」「検証」「範囲外」「本番反映の手順」）
- [ ] **Step 2: コミット**

```bash
git add docs/RULES.md CLAUDE.md docs/BACKLOG.md docs/ARCHITECTURE.md docs/superpowers/plans/2026-11-22-lot-link-on-add.md
git commit -m "docs: 区画を足すとき区画を選んでいない契約などをつなぐ変更を記録する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: 本番反映（親の会話が行う。各段で利用者の承認を取る）

- [ ] **Step 1: 13.x の確かめ** — `git merge-base --is-ancestor 13.x HEAD` と ListAgents。進んでいたら枝へ取り込んで全件を流し直す
- [ ] **Step 2: 本番の読み取り（承認）** — `ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh < 手順ファイル` で `artisan tinker --execute`（php は 8.3 を名指し）。件数と id だけ: 土地ごとの「区画を選んでいないもの」（2026-11-21 は #21 の契約 1 件だけ）・区画のある土地で区画を選んでいないもの（0 件のはず）・区画のある土地で区画の無い見積りの注文住宅（0 件のはず＝決まり 7 で止まるもの）／変わるファイルの md5 が今の 13.x と一致／`laravel.log` が Nov 18 01:45 のまま
- [ ] **Step 3: 早送り（承認）** — main repo で `git checkout 13.x && git merge --ff-only lot-link-on-add`
- [ ] **Step 4: 読み込みの表** — main repo の cwd で `composer dump-autoload --no-dev --optimize`（PHP 8.3。`ProcurementLotLinks` が表に入り、作業場所のパスが 0・`vendor/bin/phpunit` が無い）
- [ ] **Step 5: `./deploy.sh`（承認）**
- [ ] **Step 6: 反映の後の読み取り（承認）** — 送ったファイルの md5 が手元と一致・コンパイル済みビューの lint INVALID 0・`ProcurementLotLinks` が読める・`laravel.log` が Nov 18 01:45 のまま
- [ ] **Step 7: ログインした画面（承認・見るだけ・送信しない）** — #21 の詳細の追加のフォームに 1 件の案内（ステータスの欄が無い）・#9 の詳細は今のまま（ステータスの欄がある）。⚠ 区画の登録は押さない（本番のデータを変える）。`main` の横スクロール 0・コンソールのエラー 0
- [ ] **Step 8: 記録** — BACKLOG の節に「本番反映」の表、計画の実測記録。push は利用者が「push して」と言ったときだけ

---

## 範囲外（設計書のとおり・計画で気づいたもの）

- すでに区画があり、区画を選んでいないものも残っている土地の空いている区画の扱い（本番 0 件。次に区画を足したときにつながる）
- 区画の行の編集でつなぐこと
- ほぼ同時の 2 つの保存（ロックは足さない）
- 区画のある土地で区画の無い建売に建売の契約を登録すること（区画の無い建売は、区画のある土地では古いデータにしか無い。決まり 7 は注文住宅だけ）
- 契約一覧から開く注文住宅の契約の編集（ステータスを変えないので、決まり 7 は当たらない）

## 実測記録

### 直す前（2026-11-22・`aeb6b438`・使い捨てのテスト `scratchpad/probe/LotLinkBeforeProbeTest.php`）

区画の無い土地（status=sold）に区画を選んでいない契約 1 件:

| 手順 | 結果 |
|---|---|
| 詳細の追加のフォームの初期値 | ステータスは `sold`（`defaultLotStatus()`） |
| そのまま登録 | 区画は `sold`・契約の `procurement_lot_id` は NULL・土地は `sold` |
| 区画を `on_sale` にして、その区画で 2 件目の契約を登録 | 入力エラーなしで通り、契約が 2 件（設計書の ①） |
| 2 件目を消し区画を `sold` に戻してから、区画を選んでいない契約を削除 | 区画は `sold` のまま・土地も `sold`（設計書の ②） |

注文住宅: 区画のある土地で区画の無い見積りの注文住宅を一覧の小窓で「契約」へ進めると 200 で通る（既存の `test_a_legacy_order_without_a_lot_saves_as_is` が今の形として固定している）。

### Task 4 の確かめ（2026-11-22〜23）

- 全件（Step 1・`ecc7c897`）: **OK (5606 tests, 66848 assertions)**（`--display-phpunit-deprecations`・警告なし・3 分 56 秒・177.50 MB）／ コンパイル済みビュー **327 本 / INVALID 0**
- ⚠ 全件で `ProcurementLotStatusSyncTest::test_a_custom_order_crossing_contracted_moves_the_lot` の最後の段（区画のある土地の区画なしの見積りを小窓で「契約」へ）が 422 で落ちた。この計画は決まり 7 の確かめを住宅事業のテストだけにしていた → 決まり 7 のとおり「422・`CONTRACT_NEEDS_LOT`・見積りのまま・区画は動かない」に直した（`ecc7c897`）
- 変異（Step 2・作業場所の `.claude/route-coverage-tools/mutate/run_lla.py`・表は `specs_lot_link_on_add.py`。流したテストは `tests/Feature/RealEstate`・`tests/Feature/Housing`・`OldInputRuleCoverageTest`・`JapaneseValidationMessagesTest`）: 表の 24 通りすべて期待どおり（C0〜V5 の 20 通りは 2026-11-22・結果 `results-lot-link-on-add.part1-C0-V5.txt`／H1〜H4 は 2026-11-23・結果 `results-lot-link-on-add.txt`）。緑のまま通った変異 0。H4 は狙った `test_a_legacy_order_without_a_lot_saves_as_is` のほかに `test_a_contracted_order_is_not_refused_for_its_own_lot` も同じ理由で落ちた（検出は正しい）
- 実ブラウザ（Step 3・2026-11-23・`.superpowers/sdd/2026-11-22-lot-link-on-add/lla-env.php` の使い捨て SQLite ＋ `artisan serve` ＋ Playwright・作業場所で `vite build`＝CSS は本番と同じ `app-o1yvrcrl.css`）:

| 土地 | 見たこと | 結果 |
|---|---|---|
| A（#21 の形） | 追加のフォーム → 登録 → 契約を削除 | 「この区画は契約「勝山の土地（山田 太郎 様）」に紐づけます（区画のステータスは「成約」になります）。」・ステータスの欄なし → 帯「区画を登録しました（契約「…」をこの区画に紐づけました）。」・区画は成約・「紐づいているもの」に契約 → 削除で区画も土地も販売中 |
| B（区画なしの建売 2・契約の注文住宅 1・見積りの注文住宅 1） | 選択欄 → 選ばずに押す → required を外して送る → 3 件を順につなぐ | 選択肢 3 件（見積りの CO-002 は出ない）→ ブラウザが「リスト内の項目を選択してください。」で止める → サーバーが「この区画に紐づけるものを選んでください。」・備考は残る → HS-002（契約あり）は成約・HS-001 は販売中・残り 1 件は案内に変わり CO-001 は成約 → 全部つながると追加のフォームはステータスの欄（初期値 販売中） |
| C（候補なし） | 開いたあとで契約 2 件を DB に足してから送る | 顔ぶれの文・区画は作られない・打った値は残り今の 2 件の選択欄 → 選び直して登録できる |
| D（区画あり・区画なしの見積り CO-003） | 注文住宅の一覧の小窓で「契約」へ | alert「区画のある土地で区画を選んでいないため、「契約」へ進められません。編集画面で区画を選んでから進めてください。」・見積りのまま・区画は販売中のまま |

  375px・1440px とも `main` の横スクロール 0・案内と断りの文と選択欄は枠の中・コンソールのエラーは意図した 422 の 1 件だけ
- 最後の点検（Step 4・2026-11-23・いちばん強いモデル・読み取りだけ・`review-790f502f..ecc7c897.diff`）: 「マージしてよい」・Critical 0・Important 0・Minor 6。古くなったコメント 2 か所は記録のコミットで直した。ほかの Minor は BACKLOG の節の範囲外と利用者に伝えることに書いた
