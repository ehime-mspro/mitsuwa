# 原価管理の一覧入力（見込み額・確定額を表で入れる）— 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 仕入れ案件・分譲地の原価管理の「＋ 費用追加」（1 行ずつ）をやめ、「見込み額を入力」「確定額を入力」で費用項目マスタの全項目を並べた表を開き、押したほうの列だけを Excel のように打ってまとめて保存できるようにする（詳細画面 2 つ・新規登録の共用部品）。

**Architecture:** 表のマークアップ（`_cost_grid`）と JS（`costGridFactory(opts)`）を 1 つずつ作り、詳細画面 2 つと新規登録の部品に `Object.assign` で足す。詳細画面は新しい受け口 `PUT …/costs/batch` に変えた行だけを 1 回で送り、サーバーの `App\Support\RealEstateCostGrid` が親と原価の行をロックして、開いたときの値と比べ（ほかの人の変更を断る）、7.3 の決まりで全行を確かめてから 1 つのトランザクションで書く。新規登録は送らず、表を閉じると今の `costs[idx][...]` の hidden に載る。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade + Alpine.js 3 / PHPUnit 11（画面の JS は `Tests\Concerns\DrivesAlpineFetch` で node の vm で動かす）

**Spec:** `docs/superpowers/specs/2026-11-16-realestate-cost-grid-design.md`（コミット `2eaf7d4d`。2026-11-16 利用者承認）・モック `docs/mockups/realestate/procurement-cost-grid.html`（承認済み・JS の動きの手本）

## 着手前（作業場所）

- 新しい作業場所を、**この計画のコミット**から作る（設計書・モック・計画を含む。EnterWorktree は origin から分かれるので使わない）。main repo の cwd で:
  `git worktree add .claude/worktrees/realestate-cost-grid -b realestate-cost-grid <この計画のコミット>` → 作業場所で `git merge-base --is-ancestor 2eaf7d4d HEAD` が 0
- vendor: `cp -a /Users/masanori/site/manage/vendor ./vendor && composer install --no-scripts --no-interaction`（`.env` は作らない）
- 基準の全件: 下の流し方で OK を確かめ、本数を記録する（2026-11-16 時点で 5006 本の見込み）
- 13.x が進んでいたら作業場所へ merge する（rebase しない＝記録のコミット番号を保つ）

## Global Constraints

- 受け口（2 本・どちらも `role:executive,manager` のグループに置く）:
  - `PUT /realestate/procurements/{procurement}/costs/batch` → `ProcurementController@saveCostGrid`・名前 `realestate.procurements.costs.batch`
  - `PUT /realestate/projects/{project}/costs/batch` → `ProjectController@saveCostGrid`・名前 `realestate.projects.costs.batch`
- 要求（JSON）: `{"mode": "estimated"|"actual", "rows": [{"id": int|null, "cost_item_id": int, "amount": int|null, "notes": string|null, "original": {"estimated_amount": int|null, "actual_amount": int|null, "notes": string|null}}]}`（rows は 1〜100 件・**画面は変えた行だけ**を表の順に送る。キーの順もこのとおり）
- 応答: 200 `{"success": true, "message": "…", "costs": [RealEstateCostImportService::formatCostsForJson と同じ形, id の順]}`／422 `errors` は行の断りが `rows.{送った順の番号}.amount`、表全体の断り（衝突）が `rows`／一般担当は 403（ルート）
- 文言（ここに無い文言は作らない。PHP の定数 `RealEstateCostGrid::MESSAGES` に入れ、画面の JS は同じ定数を `@json` で受け取る）:

  | キー | 文言 |
  |---|---|
  | `notNumber` | 金額は数字で入力してください。 |
  | `tooLarge` | 2,147,483,647 以下で入力してください。 |
  | `hasActual` | 確定額が入っているので、見込み額は空にできません（0 は入れられます）。 |
  | `onlyExecutive` | 保存済みの行を消せるのは経営層だけです（0 は入れられます）。 |
  | `notesOnlyEstimated` | 見込み額を入力してください（備考だけでは保存できません）。 |
  | `notesOnlyActual` | 確定額を入力してください（備考だけでは保存できません）。 |
  | `propertyPurchase` | 物件購入費は査定価格・購入価格から自動で入るため、ここでは直せません。 |
  | `conflict` | 表を開いたあとで、ほかの人がこの原価を変えました。画面を開き直してから入れ直してください。 |

- 画面だけの文言: 保存の成功（サーバーが組む）`見込み額を保存しました（追加 N 件・変更 N 件・削除 N 件）。`（削除は 1 件以上のときだけ・確定額の表は「確定額を保存しました」）／0 件なら `変更はありませんでした。`／行の断りのまとめ `保存できませんでした。赤い行を直してください（N 件）。何も保存していません。`／`保存していない変更 N 件`・`変更はありません`／取消の確かめ `保存していない変更が N 件あります。捨ててよいですか？`／削除の確かめ `次の N 件の行を削除します: 金利負担、その他。よろしいですか？`（名前は「、」区切り・表の順）／貼り付け `N 行に貼り付けました（空のセル M 個は今の値のまま）。まだ保存していません。`（M が 0 なら括弧ごと書かない）／ほかの失敗 `サーバーエラーが発生しました（{status}）。何も保存していません。`／通信の失敗 `通信エラーが発生しました。画面を開き直して、保存されたか確かめてください。`／新規登録で開いたまま登録 `原価の表を開いたままです。「表を閉じる」を押してから登録してください。`／新規登録で表を閉じた `表の内容を一覧に入れました。「登録する」を押すまで保存されません。`
- ボタン・表示: `見込み額を入力`・`確定額を入力`・`取消`・`見込み額を保存`／`確定額を保存`（詳細）・`表を閉じる`（新規登録）・ピル `自動`・`停止中`・物件購入費の備考欄 `査定価格・購入価格から自動で入ります`・一覧の確定額が空なら `未定`（今の `—` から変える。モックと 7.1 に合わせる）
- 金額は 0〜`Controller::MAX_INT_COLUMN`（2147483647）の整数。備考 200 文字・前後の空白は落とす（サーバーは TrimStrings）。物件購入費の名前は `ReCostItem::PROPERTY_PURCHASE` / `isPropertyPurchase()`（直書きを増やさない）
- 権限: 入力のボタンと受け口は経営層・管理者。保存済みの行を消す（見込み額を空にする・一覧の「削除」）は経営層だけ。新規登録の表には権限の断りも削除の確かめも無い
- JS の決まり: `x-data` は名前付き関数（Top trap #4）／**factory には getter を置かない**（`Object.assign` の source 側の getter は固定値に焼き付く。Top trap #8。計算は全部メソッド）／factory のキーは `costGrid`・`_costGrid`・`openCostGrid` などの接頭辞（Excel 取込の factory と同じ衝突よけ）／`style=` と `:style=` を同じ要素に並べない（Top trap #5）・`x-show` の要素の `:style` に `display` を書かない（Bug #32）／JS へ値を書くのは `@json`・`route()`（Bug #76・`JsEchoClassificationTest`）／`@json` の中で関数を呼ばない（Bug #7。コントローラか `@php` で変数にしてから）／自社への `fetch` に `X-Requested-With: XMLHttpRequest`（Top trap #9）／日本語入力の確定の Enter で動かない（`$event.isComposing ||`。Top trap #6）
- 表は 375px で `main` を横にはみ出さない（`overflow-x-auto` の枠の中・`table-layout: fixed; min-width: 760px`）
- DB・依存の変更なし。新しい PHP クラスは `App\Support\RealEstateCostGrid` の 1 つ（本番では main repo の cwd で `composer dump-autoload --no-dev --optimize`）。ルートは 2 本増えて 4 本減る
- テストの流し方（作業場所の cwd）: `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit --display-phpunit-deprecations [ファイル|--filter 名前]`。データプロバイダは `#[DataProvider]`（doc-comment は非推奨の警告）。`git stash` は使わない（共有）
- コミット: Conventional Commits・日本語の件名・本文の最後に空行を挟んで `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`（サブエージェントは自分の文脈の Co-Authored-By 行を使う）。1 コミット 1 関心事

## 計画で決めたこと（設計書 13 章への答えと、それ以外の細部）

| 項目 | 決めたこと | 理由 |
|---|---|---|
| 部品の分け方 | 表のマークアップ `realestate/_partials/_cost_grid.blade.php`（引数 `$costGridLocal`）と JS `realestate/_partials/_cost_grid_script.blade.php`（`function costGridFactory(opts)`）を 3 画面で共用。**見るだけの一覧と合計の getter は各画面に残す** | getter は factory に置けない（Top trap #8）。詳細画面 2 つの一覧の写しをまとめるのは別の作業（最小差分） |
| 受け口の形 | 上の Global Constraints。断りは Laravel の 422 の形（`ValidationException`）。保存の成功の文はサーバーが組む | 画面は `errors` の鍵の番号で行へ戻せる。文言を 1 か所に |
| `storeCost`・`updateCost` | 消す（2 コントローラ・4 ルート）。Task 3 | 画面から使わない受け口を残さない。`PUT …/costs/{cost}` が `PUT …/costs/batch` を食う心配も消える |
| 開いたまま登録 | 新規登録の部品の根に `@submit.window="costGridGuardSubmit($event)"`。表が開いていれば送信を止めて `alert` と表の上に案内（上の文言） | 打った値を黙って捨てない。開いている表を勝手に一覧へ入れると、行の断りがあるときに半端になる |
| 衝突の判定 | 親の行と原価の行を `lockForUpdate()`。送られた行の `original` を今の DB と比べる（備考は `Str::trim` してから）。id が案件の行に無い・同じ id が 2 つ・新しい行の費用項目に今の行がある・同じ費用項目の新しい行が 2 つ → どれも衝突の文で何も保存しない | 他の案件の id を送る改ざんも、ほかの人の削除も、同じ文で断れば足りる（何も漏らさず、何も書かない） |
| 物件購入費 | 詳細画面の表にだけ出し（表示だけ・保存済みの行が無ければ `—`）、新規登録の表には出さない。送られたら行の断り `propertyPurchase` | 7.2・8 章 |
| 停止中の項目 | 保存済みの行があるときだけ出し、名前の横に `停止中`。新しい行の費用項目は `exists:re_cost_items,id` だけで見る（停止中かは見ない） | 画面は停止中の空の行を出さないので、新しく入る経路が無い（YAGNI） |
| 項目の並び | `ReCostItem::forCostGrid()`（停止中も含む全件・`sort_order`・`id` の順）。同じ項目の保存済みの行は `costs` の順（id の順） | `sort_order` の同点を決める |
| 打った値の持ち方 | 行ごとに `draft.amount`（開いた列の値の文字）と `draft.notes`。開いていない列は `orig` を表示するだけ | 片方の列を開いているあいだにもう片方を書き換えない（案 A） |

## Review Focus

1. **保存済みの備考に前後の空白がある行**（Excel 取込や古いデータ）— 画面が送る `original.notes` は TrimStrings で削られて届くので、素で比べると永久に「ほかの人が変えました」で直せなくなる。保存できる。Task 1 `test_saved_notes_with_surrounding_spaces_are_not_a_conflict`
2. **表を開いたあとに別のタブで「削除」や Excel 取込（上書き）をした** — 黙って上書き・作り直しをせず、衝突の文で何も保存しない。Task 1 の衝突のテスト（何が変わったかのデータプロバイダ）と Task 2 `test_a_change_by_someone_else_is_shown_and_nothing_is_saved`
3. **新規登録で表を開いたまま「登録する」（Enter で送った場合も）** — 打った値を捨てて送らない。Task 4 `test_registering_with_the_grid_open_is_stopped`
4. **同じ費用項目が 2 行ある分譲地（本番 1 件・「その他」2 行）** — 表に 2 行出て、片方だけ直すと片方の id だけが送られる。Task 2 `test_two_rows_of_the_same_item_are_edited_separately`
5. **Excel からの貼り付けで、行が足りない・空のセル・全角やカンマや「円」入り・物件購入費を挟む** — 余りは捨て、空は今の値のまま、物件購入費は飛ばし、数は読める。Task 2 `test_paste_fills_down_skipping_auto_rows_and_blank_cells`

---

## ファイルの地図

| ファイル | 役割 | Task |
|---|---|---|
| `app/Support/RealEstateCostGrid.php`（新規） | 表の保存の決まり（衝突・7.3 の表・書き込み）と `MESSAGES` | 1 |
| `app/Support/RealEstateCostImportService.php` | `formatCostsForJson()` を public static に（応答の形を共用） | 1 |
| `app/Http/Controllers/RealEstate/ProcurementController.php`・`ProjectController.php` | `saveCostGrid()` を足す（T1）・`show()` と `create()` が表の項目を渡す（T2・T4）・`storeCost()`・`updateCost()` を消す（T3） | 1〜4 |
| `routes/web.php` | batch の 2 本を足す（T1）・store / update の 4 本を消す（T3） | 1・3 |
| `app/Models/ReCostItem.php` | `forCostGrid()` | 2 |
| `resources/views/realestate/_partials/_cost_grid.blade.php`（新規） | 入力の表（案内・まとめの断り・表・保存のバー） | 2 |
| `resources/views/realestate/_partials/_cost_grid_script.blade.php`（新規） | `costGridFactory(opts)` | 2 |
| `resources/views/realestate/procurements/show.blade.php`・`projects/show.blade.php` | 表を組み込む（T2）・古い追加と編集を外す（T3） | 2・3 |
| `resources/views/realestate/_partials/_cost_section_form.blade.php` | 新規登録を表に置き換える | 4 |
| `tests/Concerns/DrivesAlpineFetch.php` | 偽の `window` に `addEventListener` と `__fireWindowEvent()` | 2 |
| `tests/Feature/RealEstate/Screens/RealEstateScreenTestCase.php` | `userWithRole()`（T1）・`formWithCostSection()` に表の script（T4） | 1・4 |
| テスト（新規） | `tests/Feature/RealEstate/CostGridSaveTest.php`（T1）・`tests/Feature/RealEstate/Screens/CostGridScreenTest.php`（T2）・`CostGridCreateFormTest.php`（T4） | |
| テスト（書き直し） | `ProcurementScreensTest`・`ProjectScreensTest`・`DetailCostEntryTest`・`AmountLimitTest`（T3・T4）・`AjaxErrorFeedbackTest` の分類（T2） | |

---

### Task 1: 表をまとめて保存する受け口

**Files:**
- Create: `app/Support/RealEstateCostGrid.php`・`tests/Feature/RealEstate/CostGridSaveTest.php`
- Modify: `app/Support/RealEstateCostImportService.php`（`formatCostsForJson` を `public static`・中の 2 か所の呼び出しを `self::`）・`app/Http/Controllers/RealEstate/ProcurementController.php`・`ProjectController.php`（`saveCostGrid()` を足す）・`routes/web.php`（各 `role:executive,manager` の原価のグループの**先頭**＝`costs.update`（`PUT …/costs/{cost}`）より前に 1 本ずつ。後ろに置くと `batch` が `{cost}` に当たって 404）・`tests/Feature/RealEstate/Screens/RealEstateScreenTestCase.php`（`protected function userWithRole(UserRole $role): User` を足す。realestate の部署に付ける＝`ProcurementListCreateDropdownTest::staff()` と同じ。Task 2 も使う）

**Interfaces:**
- Consumes: `ReProcurement::costs()` / `ReProject::costs()`（HasMany）・`ReCostItem::isPropertyPurchase()`・`Controller::MAX_INT_COLUMN`
- Produces:
  - `final class App\Support\RealEstateCostGrid`
    - `public const MESSAGES = [...]`（Global Constraints の 8 キー）
    - `public function save(ReProcurement|ReProject $owner, string $mode, array $rows, bool $canDelete): array` → `['message' => string, 'costs' => list<array>]`。断りは `Illuminate\Validation\ValidationException` を投げる（何も書かない）
  - `RealEstateCostImportService::formatCostsForJson(iterable $costs): array`（public static）
  - `ProcurementController::saveCostGrid(Request $request, ReProcurement $procurement): JsonResponse`・`ProjectController::saveCostGrid(Request $request, ReProject $project): JsonResponse`

- [ ] **Step 1: 失敗するテストを書く** — `CostGridSaveTest extends Tests\Feature\RealEstate\Screens\RealEstateScreenTestCase`。データプロバイダ `owners(): ['仕入れ案件' => ['procurement'], '分譲地' => ['project']]` を全部のテストに付ける。助け: `ownerOf($kind)`・`costOf($kind, $owner, ReCostItem $item, int $est, ?int $act = null, ?string $notes = null)`（モデルで直接作る）・`rowFor(?Model $cost, ReCostItem $item, ?int $amount, ?string $notes): array`（`original` は `$cost` の値・無ければ全部 null）・`put($kind, $owner, string $mode, array $rows, ?User $as = null)`（`actingAs($as ?? $this->user)->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->putJson(route("realestate.{$kind}s.costs.batch", $owner), ['mode' => …, 'rows' => …])`）・土台の `userWithRole()`。`$this->otherItem = ReCostItem::create(['name' => 'その他', 'sort_order' => 3])`。どのテストも DB の行を `[estimated_amount, actual_amount, notes]` で見る

  | テスト | 送るもの | 期待 |
  |---|---|---|
  | `test_an_estimate_creates_a_row_without_an_actual` | estimated・新しい行（測量費）300000・`現況測量` | 200・行 `[300000, null, '現況測量']`・`message` `見込み額を保存しました（追加 1 件・変更 0 件）。`・`costs` に 1 行 |
  | `test_an_estimate_updates_only_the_estimate_and_notes` | 保存済み `[500000, 480000, 'a']` を 520000・`b` | `[520000, 480000, 'b']`・`（追加 0 件・変更 1 件）` |
  | `test_clearing_an_estimate_deletes_the_row_for_an_executive` | 保存済み `[500000, null]` を amount null | 行が無い・`（追加 0 件・変更 0 件・削除 1 件）` |
  | `test_clearing_an_estimate_of_a_row_with_an_actual_is_refused` | 保存済み `[500000, 480000]` を null | 422・`errors` が **ちょうど** `['rows.0.amount' => [hasActual]]`・行はそのまま |
  | `test_a_manager_cannot_clear_a_saved_estimate` | 管理者で保存済み `[500000, null]` を null | 422 `rows.0.amount` = onlyExecutive・行は残る |
  | `test_notes_without_an_amount_are_refused`（`['estimated', notesOnlyEstimated]`・`['actual', notesOnlyActual]` を掛ける） | 新しい行・amount null・備考 `x` | 422 `rows.0.amount` = その文・行 0 |
  | `test_an_actual_on_an_item_without_a_row_creates_a_zero_estimate_row` | actual・新しい行 750000 | `[0, 750000, null]`・`確定額を保存しました（追加 1 件・変更 0 件）。` |
  | `test_an_actual_updates_only_the_actual_and_notes` | 保存済み `[500000, null, 'a']` を 480000・`b` | `[500000, 480000, 'b']` |
  | `test_clearing_an_actual_sets_it_back_to_undecided` | 保存済み `[500000, 480000]` を null | `[500000, null, …]`・行は残る |
  | `test_one_bad_row_saves_nothing` | 0: 保存済みの正しい変更・1: 確定額ありの見込み額を空 | 422・`errors` がちょうど `rows.1.amount` だけ・行 0 も変わっていない |
  | `test_a_change_by_someone_else_after_opening_is_refused`（`'見込み額'`・`'確定額'`・`'備考'` のどれを他人が変えたか） | `rowFor` で original を作ってから DB を書き換え、別の正しい行と一緒に送る | 422・`errors` がちょうど `['rows' => [conflict]]`・どちらの行も変わっていない |
  | `test_a_new_row_for_an_item_that_already_has_one_is_refused` | 新しい行（測量費）のあいだに測量費の行がある／同じ費用項目の新しい行を 2 つ（データプロバイダ 2 件） | 422 `rows` = conflict・行の数が変わらない |
  | `test_a_row_deleted_or_owned_by_someone_else_is_refused` | 存在しない id／もう一方の案件の行の id／同じ id を 2 つ（3 件） | 422 `rows` = conflict・もう一方の案件の行は変わらない |
  | `test_the_property_purchase_row_cannot_be_changed` | 物件購入費の保存済みの行を変える／物件購入費の新しい行（2 件） | 422 `rows.0.amount` = propertyPurchase |
  | `test_amounts_must_be_whole_numbers_within_the_column` | amount `-1`→notNumber・`'abc'`→notNumber・`1.5`→notNumber・`2147483648`→tooLarge・`2147483647`→200 で保存 | 断りは `rows.0.amount` |
  | `test_notes_up_to_200_characters_are_saved` | `str_repeat('あ', 200)` は保存・201 は 422 `rows.0.notes` = `trans('validation.max.string', ['attribute' => '備考', 'max' => 200])` | |
  | `test_saved_notes_with_surrounding_spaces_are_not_a_conflict` | DB に notes `'  境界  '` を直接入れ、original.notes も `'  境界  '`（TrimStrings が削って届く）で見込み額を変える | 200・見込み額が変わる |
  | `test_nothing_changes_when_the_sent_values_are_already_saved` | 保存済みと同じ値を送る | 200・`変更はありませんでした。` |
  | `test_a_staff_member_cannot_save` | 一般担当で送る | 403・行 0 |
  | `test_the_batch_url_reaches_the_grid_action` | `app('router')->getRoutes()->match(Request::create(route(...), 'PUT'))` | 名前が `realestate.{kind}s.costs.batch`（`route:list` の並びで見ない） |
  | `test_the_save_locks_the_owner_and_its_costs` | `RealEstateCostGrid.php` のコメントを落とした字句に `->lockForUpdate()` が 2 つ以上 | 構造（順に流すテストではロックの有無が見えない。Bug #102 と同じ） |

- [ ] **Step 2: 落ちることを確かめる** — 新しいファイルだけ流す。期待: ルートが無い（`Route [realestate.procurements.costs.batch] not defined.`）で全部落ちる
- [ ] **Step 3: `RealEstateCostGrid::save()` を書く** — `DB::transaction()` の中で、この順:
  1. 親の行を `->whereKey()->lockForUpdate()->first()`、原価の行を `$owner->costs()->with('costItem')->lockForUpdate()->get()->keyBy('id')`
  2. 衝突の確かめ（上の「計画で決めたこと」の条件。比べるのは `estimated_amount`・`actual_amount` と `Str::trim((string) ($cost->notes ?? ''))` 対 `(string) ($original['notes'] ?? '')`）。1 つでもあれば `ValidationException::withMessages(['rows' => self::MESSAGES['conflict']])`
  3. 行の決まり（設計書 7.3 の 2 つの表。物件購入費が先）を全行で確かめ、断りを `rows.{i}.amount` に集めて、あれば投げる
  4. 書く。見込み額の表の新しい行は `actual_amount` null、確定額の表の新しい行は `estimated_amount` 0。更新は開いた列と `notes` だけ `fill()` し、`isDirty()` のときだけ「変更」に数えて保存。備考は空なら null
  5. 文を組む（Global Constraints）・`costs` は `formatCostsForJson($owner->costs()->with('costItem')->orderBy('id')->get())`
- [ ] **Step 4: コントローラとルート** — `saveCostGrid()` は `$request->validate()` に**リテラルの配列**を 3 つ渡し（和名の走査 `JapaneseValidationMessagesTest` が第 3 引数を読む）、`app(RealEstateCostGrid::class)->save($owner, $v['mode'], $v['rows'], $request->user()->role->isExecutive())` を `response()->json(['success' => true] + $result)` で返す。規則:
  - `mode` `required|in:estimated,actual`・`rows` `required|array|min:1|max:100`・`rows.*.id` `'nullable|integer|min:1|max:' . PHP_INT_MAX`・`rows.*.cost_item_id` `required|integer|exists:re_cost_items,id`・`rows.*.amount` `'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN`・`rows.*.notes` `nullable|string|max:200`・`rows.*.original` `required|array`・`rows.*.original.estimated_amount` と `.actual_amount` `'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN`・`rows.*.original.notes` `nullable|string|max:200`（整数の規則は全部に上限＝`AmountLimitTest` の走査）
  - メッセージ: `rows.*.amount.integer` と `.min` → `MESSAGES['notNumber']`・`.max` → `MESSAGES['tooLarge']`
  - 和名（第 3 引数）: `mode` 入力する金額・`rows` 原価の表・`rows.*.id` 原価の行・`rows.*.cost_item_id` 費用項目・`rows.*.amount` 金額・`rows.*.notes` 備考・`rows.*.original` 開いたときの値・`rows.*.original.estimated_amount` 開いたときの見込み額・`rows.*.original.actual_amount` 開いたときの確定額・`rows.*.original.notes` 開いたときの備考
- [ ] **Step 5: 通ることを確かめる** — `CostGridSaveTest`・`JapaneseValidationMessagesTest`・`ValidationKeyScannerTest`・`AmountLimitTest`・`ArrayInputTest`（不動産）が OK・警告なし
- [ ] **Step 6: コミット** — `feat: 不動産の原価の表をまとめて保存する受け口を足す`

### Task 2: 入力の表を作り、詳細画面 2 つに組み込む

この Task では古い「＋ 費用追加」「編集」を残したまま足す（Task 3 で外す）。古いボタンと新しいボタンは同時に出さない。

**Files:**
- Create: `resources/views/realestate/_partials/_cost_grid.blade.php`・`_cost_grid_script.blade.php`・`tests/Feature/RealEstate/Screens/CostGridScreenTest.php`
- Modify: `app/Models/ReCostItem.php`・2 コントローラの `show()`・`procurements/show.blade.php`・`projects/show.blade.php`・`tests/Concerns/DrivesAlpineFetch.php`・`tests/Feature/AjaxErrorFeedbackTest.php`（`VIEWS_OTHER` に `'realestate/_partials/_cost_grid_script.blade.php', // エンベロープ（原価の表の保存）'`）

**Interfaces:**
- Consumes: Task 1 の受け口・`RealEstateCostGrid::MESSAGES`・ホストの `costs`（`{id, cost_item_id, cost_item_name, estimated_amount, actual_amount, notes, is_property_purchase}`）・`showMessage(msg)`
- Produces:
  - `ReCostItem::forCostGrid(): array` → `list<array{id: int, name: string, is_active: bool, is_property_purchase: bool}>`（全件・`sort_order`・`id` の順）
  - `show()` が渡す `$costGridItems`（上）と `$costGridCanDelete`（`$request->user()->role->isExecutive()`）
  - `costGridFactory(opts)`: `opts = {items, saveUrl: string|null, csrf: string|null, canDelete: bool}`（`saveUrl` が null ＝新規登録）。状態 `costGrid = {mode: null|'estimated'|'actual', rows: [], rowErrors: {}, error: '', notice: '', saving: false}`・`_costGridOpts`・`_costGridUnloadBound`・`_costGridSent`（送った順の行の key）。行 `{key, ref, id, cost_item_id, name, auto, inactive, orig: {est, act, notes}, draft: {amount, notes}}`（`key` は保存済み `'c' + id`・空の行 `'n' + 費用項目の id`・新規登録の一覧の行 `'l' + 一覧の番号`。`ref` は開いたときの `this.costs` の番号か null）
  - メソッド（getter は使わない）: `openCostGrid(mode)`・`cancelCostGrid()`・`saveCostGrid()`（詳細）・`closeCostGrid()`（新規登録・Task 4）・`costGridGuardSubmit(e)`（Task 4）・`costGridParse(text) → '' | number | NaN`・`costGridFormat(n) → string`・`costGridTidy(row)`・`costGridIsChanged(row) → bool`・`costGridChangeCount() → int`・`costGridPlan() → {rowErrors: {key: msg}, ops: [{row, op: 'create'|'update'|'delete', amount: int|null, notes: string|null}]}`・`costGridValue(row, col: 'est'|'act') → int|null`・`costGridTotal(kind: 'est'|'act'|'eff') → int`・`costGridDiffText(row)`・`costGridDiffStyle(row)`・`costGridTotalDiff()`・`costGridNext(idx, step) → int|null`・`costGridKey(e, idx) → int|null`・`costGridPaste(e, idx)`・`costGridRowError(row) → string`
  - 偽の `window.addEventListener(type, fn)` と、`steps`・`evaluate` から呼べる `__fireWindowEvent(type) → {prevented: bool, returnValue: string|null}`

- [ ] **Step 1: 失敗するテストを書く** — `CostGridScreenTest extends RealEstateScreenTestCase`。データプロバイダ `screens(): ['仕入れ案件' => ['procurement'], '分譲地' => ['project']]`。setUp で項目を足す: `仲介手数料`（sort 0）・`その他`（3）・`旧項目`（4・停止中・行なし）・`停止項目`（5・停止中）。並びは 仲介手数料・物件購入費（自動）・測量費・その他 …。助け: `page($kind, ?User $as = null) → [html, function名, owner]`（`$this->user` を差し替えて `htmlOf()`）・`run($html, $function, $steps, $responses = [], $evaluate = [], $confirm = true)`（`driveAlpine(…, ['function costExcelImporterFactory(', 'function costGridFactory('])`）・JS の式 `row("測量費")` → `data.costGrid.rows.filter(function (r) { return r.name === "測量費"; })[0]`。保存の往復は 1 回目で要求を取り出して `sendCaptured()`、2 回目に `asFetchResponse()` を戻して状態を見る（今の詳細画面のテストと同じ）

  | テスト | 手順 | 期待 |
  |---|---|---|
  | `test_the_input_buttons_are_shown_to_managers_and_not_to_staff` | 経営層・管理者・一般担当（`userWithRole()`）で開く | 前 2 人の HTML に `@click="openCostGrid('estimated')"` と `@click="openCostGrid('actual')"` のボタン（`見込み額を入力`・`確定額を入力`）・一般担当には無い |
  | `test_opening_lists_every_item_in_master_order` | 保存済み: 測量費 1 行・その他 2 行・停止項目 1 行。`openCostGrid("estimated")` | `rows.map(name + 保存済みか + 自動 + 停止中)` がちょうど `仲介手数料/空` `物件購入費/空/自動` `測量費/保存` `その他/保存` `その他/保存` `停止項目/保存/停止中`（旧項目は出ない）・draft は保存済みの見込み額をカンマ付き |
  | `test_the_estimate_grid_is_saved_in_one_request` | 新しい行（仲介手数料）`1,200,000`・備考 `現況`／保存済みの測量費の備考だけ変える／その他は触らない → `saveCostGrid()` | 要求はちょうど 1・PUT・URL は batch・ヘッダー `X-Requested-With: XMLHttpRequest`・`Content-Type`・`Accept` が JSON・本文が Global Constraints の形で**変えた 2 行だけ**（`assertSame` で全文。original も）。送ると 200。戻すと `costGrid.mode === null`・`costs` が新しい内容・`costMessage` が `見込み額を保存しました（追加 1 件・変更 1 件）。` |
  | `test_the_actual_grid_creates_a_zero_estimate_row` | actual・保存の無い測量費に `750000` | 送った本文の amount 750000・DB `[0, 750000]`・戻したあと一覧に 1 行 |
  | `test_client_checks_match_the_server_rules`（データプロバイダ 8 件: `abc`→notNumber・`-5`→notNumber・`2147483648`→tooLarge・空＋備考（estimated／actual）→notesOnly…・確定額ありの行の見込み額を空→hasActual・**管理者**で保存済みの行の見込み額を空→onlyExecutive・`2,147,483,647` は送られる） | 開いて打って保存 | 断るときは要求 0・`costGrid.rowErrors[key]` がその文・`costGrid.error` が `保存できませんでした。赤い行を直してください（1 件）。何も保存していません。`・表は開いたまま |
  | `test_deleting_rows_asks_first_and_names_them` | 経営層で測量費とその他（1 行目）の見込み額を空 | 確かめ `次の 2 件の行を削除します: 測量費、その他。よろしいですか？`・`$confirm = false` なら要求 0 で表は開いたまま・true なら送って 2 行消える |
  | `test_server_row_errors_are_shown_on_the_rows` | 経営層の画面で保存済みの行（確定額なし）を空にした要求を取り出し、**管理者で**送る | 422 `rows.0.amount`。戻すとその行の key に onlyExecutive・まとめの文（1 件）・表は開いたまま |
  | `test_a_change_by_someone_else_is_shown_and_nothing_is_saved` | 要求を取り出したあと DB の見込み額を書き換えて送る | 422。戻すと `costGrid.error` = conflict・`rowErrors` は空・DB はほかの人の値のまま |
  | `test_two_rows_of_the_same_item_are_edited_separately` | その他 2 行の 2 行目だけ変える | 本文の行は 1 つで id は 2 行目・1 行目の DB は変わらない |
  | `test_paste_fills_down_skipping_auto_rows_and_blank_cells` | 測量費に見込み 50000 を保存。estimated で開き、仲介手数料（0 行目）に `{clipboardData: {getData: () => "１，２００円\n\n300\t9\n400\n"}, preventDefault}` | 防いだ・仲介手数料 `1,200`・物件購入費は飛ばす・測量費は `50,000` のまま・その他 `300`・400 は捨てる・`costGrid.notice` = `2 行に貼り付けました（空のセル 1 個は今の値のまま）。まだ保存していません。`。1 行だけ（`"5000"`）なら防がず何も変わらない |
  | `test_enter_and_arrows_move_between_editable_cells` | `costGridKey(e, idx)` に偽のキーの出来事 | Enter（0）→ 2（自動を飛ばす）・Shift+Enter（2）→ 0・ArrowDown（0）→ 2・ArrowUp（2）→ 0・最後の行の Enter → null。どれも `preventDefault` された。`isComposing: true` の Enter → null で防がない。ほかのキー → null で防がない |
  | `test_change_count_and_cancel_confirmation` | 2 行を変える | `costGridChangeCount() === 2`・取消は `保存していない変更が 2 件あります。捨ててよいですか？`・false なら開いたまま・true なら閉じる。変えずに取消なら確かめない |
  | `test_leaving_with_unsaved_changes_asks` | 開いて変えて `__fireWindowEvent("beforeunload")` | `prevented: true`・変えていなければ false・閉じたあとは false |
  | `test_parsing_formatting_and_totals` | actual で開き、測量費（見込み 50000 保存）に `１２０,０００円 ` → `costGridTidy` | draft `120,000`・`costGridDiffText` `+70,000`・`costGridDiffStyle` に `#B91C1C`・確定額が見込みより低いと `#047857`・合計（est・act・eff・差額）を数で見る（物件購入費の保存済みの行も合計に入る） |
  | `test_saving_without_changes_sends_nothing` | 開いてすぐ保存 | 要求 0・閉じる・`costMessage` = `変更はありませんでした。` |
  | `test_the_grid_markup_follows_the_traps` | `_cost_grid.blade.php` の字句 | 金額のセルに `inputmode="numeric"`・`@keydown="$event.isComposing \|\| costGridKey($event, idx)"`・`@focus="$event.target.select()"`・`@blur="costGridTidy(row)"`・備考に `maxlength="200"`・同じ要素に `style=` と `:style=` が無い・表が `overflow-x-auto` の中で `min-width` を持つ・保存のボタンに `:disabled="costGrid.saving"` |

- [ ] **Step 2: 落ちることを確かめる** — 新しいファイルだけ。期待: ボタンが無い・`function costGridFactory( を含む <script> がちょうど 1 つ描かれていない` で落ちる
- [ ] **Step 3: 道具** — `DrivesAlpineFetch::ALPINE_HARNESS` の偽の `window` に `addEventListener`（種類と関数を記録）を足し、文脈に `__fireWindowEvent(type)`（`{type, defaultPrevented: false, returnValue: undefined, preventDefault()}` を作って記録した関数を呼び、`{prevented, returnValue}` を返す）を足す。docblock の「偽物にしているもの」に 1 行足す。既存の呼び出しは変えない
- [ ] **Step 4: `ReCostItem::forCostGrid()` と `show()`** — 2 コントローラの `show()` で `$costGridItems`・`$costGridCanDelete` を作って `compact()` に足す
- [ ] **Step 5: `_cost_grid_script.blade.php`** — 先頭の Blade コメントに使い方と 3 つの約束（getter を置かない・接頭辞・`Object.assign` は getter を持つ側が先）を書く。`@php $costGridMessages = \App\Support\RealEstateCostGrid::MESSAGES; @endphp` を `@json($costGridMessages)` で JS の定数に。動きは設計書 7.2・7.3 とモックの `costMock()`（`parse`・`fmt`・`isChanged`・`value`・`total`・`diffText`・`moveBy`・`paste`・`save`）に合わせ、次だけ決めておく:
  - `openCostGrid(mode)`: 行を `opts.items` の順に組む（物件購入費は `saveUrl` が null なら出さない・停止中は保存済みの行があるときだけ）。draft は開いた列の保存済みの値をカンマ付き（無ければ `''`）・備考はそのまま。`rowErrors`・`error`・`notice` を空に。初めて開いたときだけ `beforeunload` を登録（開いていて変更があれば `preventDefault()` と `returnValue = ''`）。`$nextTick` で最初の入力できるセル（`[data-cost-cell="{mode}-{idx}"]`）へ
  - `costGridPlan()`: 7.3 の表どおり。`canDelete` が false なら保存済みの行を消す操作を onlyExecutive で断る。新規登録（`saveUrl` が null）は衝突も権限も無し
  - `saveCostGrid()`: 断りがあれば `rowErrors` とまとめの文を入れて終わる。ops が 0 なら要求を出さず閉じて `showMessage('変更はありませんでした。')`。消す行があれば確かめる。送るのは ops の行だけ（`amount` は数か null・`notes` は空なら null・`original` は `{estimated_amount: orig.est, actual_amount: orig.act, notes: orig.notes === '' ? null : orig.notes}`）。`fetch` はエンベロープ（`r.json().then(function (j) { return {ok: r.ok, status: r.status, body: j}; }, …)`）で `if (!res.ok) {` の中で必ず `self.costGrid.error = …` に入れる（`AjaxErrorFeedbackTest` の SINK）。422 の `errors` は `^rows\.(\d+)\.` を `_costGridSent` で行の key に戻し、それ以外の鍵（`rows`・`mode`）は `costGrid.error` に。行の断りがあればまとめの文。成功で `this.costs = res.body.costs`・閉じる・`this.showMessage(res.body.message)`。送信中は `saving`
  - `costGridKey`: Enter・ArrowDown・ArrowUp（Shift+Enter は上）だけ扱い、`isComposing` か `keyCode === 229` なら何もしない。扱うキーは必ず `preventDefault()`（新規登録の表はフォームの中にあり、Enter で登録が送られてしまう）。行き先へ focus し、その番号を返す
  - `costGridPaste`: 末尾の改行を落として改行が無ければ何もしない（ふつうに貼る）。あれば `preventDefault()`、行ごとにタブの前だけ使い、空なら今の値のまま飛ばして数え、自動の行は飛ばし、足りなければ捨てる。読めない値はそのまま入れる（保存で notNumber）
- [ ] **Step 6: `_cost_grid.blade.php`** — モックの「入力の表」を移す（`x-show="costGrid.mode !== null" x-cloak`）: 案内（見込み額の表・確定額の表の 2 文はモックのまま。新規登録は「…「表を閉じる」を押すと一覧に入ります（「登録する」で保存します）。」に変える。2 行目の Enter と貼り付けの案内は共通）・`costGrid.notice`・まとめの断り（`costGrid.error`）・表（列は設計書 7.2。差額の列は `actual` のときだけ・`<template x-for="(row, idx) in costGrid.rows" :key="row.key">`・セルは `<template x-if>` で入力と表示を分ける・**入力に `name` を付けない**＝新規登録のフォームで送られない）・合計の行・保存のバー（変更の件数・取消・保存か表を閉じる。`$costGridLocal` で出し分け）
- [ ] **Step 7: 詳細画面 2 つに組み込む** — 見出しの右に `見込み額を入力`（`openCostGrid('estimated')`）・`確定額を入力`（`openCostGrid('actual')`）を今のボタンと同じ `@if(isManagerOrAbove())` の中に足す。カード右上の 4 つのボタンは全部 `costGrid.mode === null && !showAddCost && !costExcelImport.open` のときだけ出す（今の 2 つの x-show に `costGrid.mode === null &&` を足す）。メッセージの下に `@include('realestate._partials._cost_grid', ['costGridLocal' => false])`。一覧の枠に `x-show="costGrid.mode === null"`。script は Excel の factory の include の後に `@include('realestate._partials._cost_grid_script')`、ホストは `Object.assign({…今のリテラル…}, costExcelEi, costGridFactory({items: @json($costGridItems), saveUrl: '{{ route('realestate.procurements.costs.batch', $procurement) }}', csrf: document.querySelector('meta[name="csrf-token"]').content, canDelete: @json($costGridCanDelete)}))`（分譲地も同じ形）
- [ ] **Step 8: 通ることを確かめる** — `CostGridScreenTest`・`ProcurementScreensTest`・`ProjectScreensTest`・`DetailCostEntryTest`・`AmountLimitTest`・`AjaxErrorFeedbackTest`・`JsEchoClassificationTest`・`MobileLayoutTest`・`LongUnbrokenValueWrapTest`・`LayoutMeasuringScriptTest` が OK（古いテストは古い UI のまま緑）
- [ ] **Step 9: コミット** — `feat: 原価管理に見込み額・確定額を表で入れる入力を足す`（道具の変更は別のコミット `test: 画面の JS の偽の window に addEventListener を足す`）

### Task 3: 詳細画面の「＋ 費用追加」と「編集」を外す

**Files:**
- Modify: `procurements/show.blade.php`・`projects/show.blade.php`・2 コントローラ（`storeCost()`・`updateCost()` を消す）・`routes/web.php`（`costs.store`・`costs.update` の 4 本を消す。グループの見出しのコメント「3ルート」を直す）・`app/Support/RealEstateCostImportService.php`（`formatCostsForJson` のコメントの storeCost / updateCost を表の受け口に）
- Test（書き直し）: `ProcurementScreensTest`・`ProjectScreensTest`（詳細の追加・編集のテストを消す。削除・Excel 取込は残す）・`DetailCostEntryTest`・`AmountLimitTest::test_a_detail_cost_over_the_limit_is_refused`

**Interfaces:**
- Consumes: Task 2 の `costGridFactory`・`openCostGrid`・`saveCostGrid`
- Produces: なし（受け口が減る）

- [ ] **Step 1: テストを書き直す（先に赤）**
  - `ProcurementScreensTest`・`ProjectScreensTest`: `test_a_cost_is_added_from_the_detail_screen`・`test_a_cost_is_edited_from_the_detail_screen` を消し（同じことを `CostGridScreenTest` が見る）、`test_the_detail_list_has_no_add_or_edit_controls` を足す: 画面の HTML に `＋ 費用追加`・`startEditCost`・`addCost(` が無い・一覧の確定額の空の表示が `未定`（`—` でない）・空の一覧の文 `原価データがありません。「見込み額を入力」から入れてください。`・経営層の HTML に `deleteCost(cost)` があり管理者の HTML に無い
  - `test_the_old_add_and_update_routes_are_gone`（`ProcurementScreensTest` に 1 本）: `Route::has()` が 4 つとも false
  - `DetailCostEntryTest`（データプロバイダの 2 画面のまま。0 円と空を区別して守る）: `test_a_zero_estimate_is_saved_as_zero`（見込み額の表の空の行に `0` → 送る amount 0 → DB `[0, null]`）・`test_a_zero_actual_is_saved_as_zero_not_undecided`（保存済みの行の確定額に `0` → DB の確定額 0）・`test_clearing_an_estimate_deletes_the_row_instead_of_saving_zero`（経営層・確定額なしの行を空 → 確かめのあと amount null → 行が無い）・`test_clearing_an_actual_sets_undecided_instead_of_zero`（確定額を空 → amount null → 確定額 null・行は残る）
  - `AmountLimitTest::test_a_detail_cost_over_the_limit_is_refused`: 確定額の表で `2147483648` → 要求 0・その行の断りが `2,147,483,647 以下で入力してください。`（サーバーの上限は Task 1 が見る）
- [ ] **Step 2: 落ちることを確かめる** — 期待: 一覧にまだ `＋ 費用追加` がある・ルートがまだある
- [ ] **Step 3: 外す** — 2 画面から: `＋ 費用追加` のボタン・費用追加のフォーム・`showAddCost`・`newCost`・`editingCostId`・`editCost`・`addCost`・`startEditCost`・`cancelEditCost`・`saveCost`・`hasAmount`。一覧のセルは表示だけ（`editingCostId === cost.id` の三項演算は表示側の値に）・確定額が空なら `未定`・操作は物件購入費が `自動同期`、ほかは経営層だけ `削除`・空の一覧の文を差し替え。カード右上の x-show から `showAddCost` を外す。コントローラとルートを消す
- [ ] **Step 4: 通ることを確かめる** — 不動産のテスト（`tests/Feature/RealEstate`）・`AjaxErrorFeedbackTest`（null 返しの `.ok` と `!data` の数がまだ揃う）・`JapaneseValidationMessagesTest`・`JsEchoClassificationTest` が OK。和名の走査の下限（呼び出し・キーの数）を割ったら、消した `validate()` の数だけ下げ、「storeCost・updateCost を消した（2026-11-16）」とコメントに書く（化けて見えなくなったのでなく消したことを、走査の出力で確かめてから）
- [ ] **Step 5: コミット** — `refactor: 詳細画面の原価の 1 行ずつの追加と編集を外す`（受け口とルートの削除を含む）

### Task 4: 新規登録の原価欄を表に置き換える

**Files:**
- Modify: `resources/views/realestate/_partials/_cost_section_form.blade.php`・2 コントローラの `create()`（`$costGridItems` を渡す）・`tests/Feature/RealEstate/Screens/RealEstateScreenTestCase.php`（`formWithCostSection()` の原価欄の `browserForm()` に渡す一緒に読む script を `['function costExcelImporterFactory(', 'function costGridFactory(']` に。足さないと `costGridFactory is not defined` で登録のテストが全部落ちる）
- Create: `tests/Feature/RealEstate/Screens/CostGridCreateFormTest.php`
- Test（書き直し）: `ProcurementScreensTest::test_a_procurement_is_registered_from_the_screen_with_a_cost_row`・`ProjectScreensTest::test_a_project_is_registered_from_the_screen_with_a_cost_row`・`AmountLimitTest::test_a_cost_row_over_the_limit_is_refused_on_the_registration_screen`

**Interfaces:**
- Consumes: `costGridFactory`（`saveUrl: null`）・`RealEstateScreenTestCase::formWithCostSection()`（`$costSteps` で表を動かす）
- Produces: `closeCostGrid()`・`costGridGuardSubmit(e)`

- [ ] **Step 1: 失敗するテストを書く** — `CostGridCreateFormTest`（データプロバイダ 2 画面。助けは `formWithCostSection()` と `driveAlpine(…, 'costSectionFormController', …, ['function costExcelImporterFactory(', 'function costGridFactory('])`）

  | テスト | 手順 | 期待 |
  |---|---|---|
  | `test_rows_from_the_grid_are_registered_with_the_procurement_or_project` | 見込み額の表で測量費 `300,000`・備考 `境界確定` → `closeCostGrid()` → 確定額の表で `280000` → `closeCostGrid()` → 登録 | hidden で `costs[0]` が `300000`・`280000`・`境界確定`・登録の文 `…（原価 1 件を含む）。`・DB の行 |
  | `test_the_create_grid_has_no_property_purchase_row` | 開く | 物件購入費の行が無い |
  | `test_an_actual_only_row_is_listed_with_a_zero_estimate` | 確定額の表だけで `750000` → 閉じる | `costs` に `{estimated_amount: 0, actual_amount: 750000}` |
  | `test_clearing_an_estimate_removes_the_row_from_the_list` | 一覧に 1 行ある状態で見込み額を空 → 閉じる | 確かめ無し（confirms 0）・`costs` が 0 行 |
  | `test_clearing_an_estimate_with_an_actual_is_refused` | 確定額のある行の見込み額を空 → 閉じる | 表は開いたまま・その行に hasActual・`costs` は変わらない |
  | `test_registering_with_the_grid_open_is_stopped` | 開いて変えて `costGridGuardSubmit({target: {querySelector: () => ({})}, preventDefault})` | 防いだ・alert と `costGrid.error` に `原価の表を開いたままです。「表を閉じる」を押してから登録してください。`。閉じていれば防がない・ほかのフォーム（`querySelector` が null）なら防がない |
  | `test_old_costs_come_back_into_the_grid_after_an_input_error` | 原価の行つきで物件名を空にして送る → 戻った画面で開く | 一覧と表の行に送った値（`old('costs')`） |
  | `test_the_section_guards_the_surrounding_form` | 部品の字句 | 根に `data-cost-section` と `@submit.window="costGridGuardSubmit($event)"`・`表を閉じる` のボタン・`＋ 費用追加` が無い |

  - 書き直し: 2 つの登録のテストは `$costSteps` を表の手順に（上の 1 本目と同じ形）。`AmountLimitTest` の登録の上限は、表では打てないので `$costSteps` で `data.costs.push({cost_item_id: …, cost_item_name: "測量費", estimated_amount: 2147483648, actual_amount: null, notes: "", is_property_purchase: false});` を入れて送る（期待は今のまま）
- [ ] **Step 2: 落ちることを確かめる** — 期待: `function costGridFactory(` の script が無い・`closeCostGrid is not a function`
- [ ] **Step 3: 部品を置き換える** — `＋ 費用追加` のボタン・追加のフォーム・`showAddCost`・`newCost`・`resetNewCost`・`addCost` を外す。右上は `📂 試算表 Excel 取込`・`見込み額を入力`・`確定額を入力`（`costGrid.mode === null && !costExcelImport.open` のときだけ）。根の `div` に `data-cost-section` と `@submit.window="costGridGuardSubmit($event)"`。説明の文を「「見込み額を入力」「確定額を入力」で表を開いて入れ、「表を閉じる」で一覧に入れます。登録するまで保存されません。物件購入費は仕入れ情報から自動同期されます。」に、空の一覧の文を Global Constraints のものに。`@include('realestate._partials._cost_grid', ['costGridLocal' => true])`・script を include・`Object.assign({…}, ei, costGridFactory({items: opts.gridItems, saveUrl: null, csrf: null, canDelete: true}))`（`opts.gridItems: @json($costGridItems ?? [])`）。一覧の「削除」と hidden はそのまま
- [ ] **Step 4: `closeCostGrid()` と `costGridGuardSubmit(e)`** — `closeCostGrid()`: `costGridPlan()` に断りがあれば `saveCostGrid()` と同じく行に出して終わる。無ければ ops を `this.costs` に当てる（update はその番号の要素の開いた列と `notes`・delete は番号の大きい順に外す・create は末尾に `{cost_item_id, cost_item_name, estimated_amount: 見込みの表なら amount で確定の表なら 0, actual_amount: 確定の表なら amount で見込みの表なら null, notes, is_property_purchase: false}`）。閉じて、ops が 1 つ以上なら `showMessage` に Global Constraints の文。`costGridGuardSubmit(e)`: 表が閉じているか、`e.target` が `[data-cost-section]` を含まなければ何もしない。それ以外は `preventDefault()`・`alert`・`costGrid.error`
- [ ] **Step 5: 通ることを確かめる** — `CostGridCreateFormTest`・`ProcurementScreensTest`・`ProjectScreensTest`・`AmountLimitTest`・`ArrayInputTest`（不動産）・`OldInputRuleCoverageTest`・`ProcurementFormJsTest` が OK
- [ ] **Step 6: コミット** — `feat: 新規登録の原価欄を見込み額・確定額の表に置き換える`

### Task 5: 全件・変異・実ブラウザ・記録

**Files:**
- Modify: `docs/BACKLOG.md`（新しい節）・`CLAUDE.md`（Completed modules の不動産の 2 行に「原価は一覧入力（見込み額・確定額の表）」を足す）
- 作業場所の `.claude/route-coverage-tools/mutate/specs.realestate-cost-grid.py`（main repo の `mutate/` を写して使う。worktree から main repo の `.claude/` へは書けない）

- [ ] **Step 1: 全件** — Global Constraints の流し方で全件 OK・警告なし。本数と assertions を記録
- [ ] **Step 2: コンパイル済みビュー** — `view:cache` → `storage/framework/views/*.php` を全部 `php -l`（INVALID 0）→ `view:clear`
- [ ] **Step 3: 変異**（docs/RULES.md Bug #44 の作法: 先にコミット・各変異の前に `git status --porcelain` が空・`git diff --stat` で着弾・落ちた理由の 1 行目まで記録・戻す）。流す前に期待を表に書き、関係するテスト（`tests/Feature/RealEstate`・`AjaxErrorFeedbackTest`）で流す:

  | # | 変異 | 落ちるはずのテスト |
  |---|---|---|
  | C0 | `MESSAGES['hasActual']` の文を変える（カナリア） | hasActual を見るテスト全部 |
  | S1 | hasActual の確かめを外す | `test_clearing_an_estimate_of_a_row_with_an_actual_is_refused`・`test_one_bad_row_saves_nothing` |
  | S2 | `$canDelete` を見ない | `test_a_manager_cannot_clear_a_saved_estimate`・`test_server_row_errors_are_shown_on_the_rows` |
  | S3 | 衝突で備考を比べない | `test_a_change_by_someone_else_after_opening_is_refused`（備考） |
  | S4 | 備考を `Str::trim` せずに比べる | `test_saved_notes_with_surrounding_spaces_are_not_a_conflict` |
  | S5 | 新しい行の「今の行がある」を見ない | `test_a_new_row_for_an_item_that_already_has_one_is_refused` |
  | S6 | 今の行を案件で絞らずに `find($id)` | `test_a_row_deleted_or_owned_by_someone_else_is_refused`（もう一方の案件） |
  | S7 | 物件購入費の確かめを外す | `test_the_property_purchase_row_cannot_be_changed` |
  | S8 | 備考だけの行の断りを外す | `test_notes_without_an_amount_are_refused` |
  | S9 | 確定額の表の新しい行の見込み額を 0 でなく amount に | `test_an_actual_on_an_item_without_a_row_creates_a_zero_estimate_row` |
  | S10 | 見込み額の更新で確定額も null に | `test_an_estimate_updates_only_the_estimate_and_notes` |
  | S11 | 確定額を空にすると行を消す | `test_clearing_an_actual_sets_it_back_to_undecided` |
  | S12 | 行を確かめながら書く（全行を確かめる前に書く） | `test_one_bad_row_saves_nothing` |
  | S13 | `isDirty()` を見ずに変更を数える | `test_nothing_changes_when_the_sent_values_are_already_saved` |
  | S14 | batch のルートを `role:` のグループの外へ | `test_a_staff_member_cannot_save` |
  | S15 | `lockForUpdate()` を 1 つ外す | `test_the_save_locks_the_owner_and_its_costs` |
  | J1 | `costGridKey` の `isComposing` の確かめを外す | `test_enter_and_arrows_move_between_editable_cells` |
  | J2 | `costGridNext` が自動の行を飛ばさない | 同上・`test_paste_fills_down_skipping_auto_rows_and_blank_cells` |
  | J3 | 貼り付けの空のセルで値を消す | `test_paste_fills_down_skipping_auto_rows_and_blank_cells` |
  | J4 | 変えていない行も送る | `test_the_estimate_grid_is_saved_in_one_request` |
  | J5 | `X-Requested-With` を外す | 同上 |
  | J6 | `original` を送らない（null に） | `test_the_estimate_grid_is_saved_in_one_request`・`DetailCostEntryTest` の更新 |
  | J7 | 削除の確かめを飛ばす | `test_deleting_rows_asks_first_and_names_them` |
  | J8 | 画面で `canDelete` を見ない | `test_client_checks_match_the_server_rules`（管理者） |
  | J9 | 422 の行の番号を 1 つずらす | `test_server_row_errors_are_shown_on_the_rows` |
  | J10 | `beforeunload` で `preventDefault()` しない | `test_leaving_with_unsaved_changes_asks` |
  | J11 | 新規登録の `closeCostGrid` が hasActual を断らない | `test_clearing_an_estimate_with_an_actual_is_refused` |
  | J12 | `costGridGuardSubmit` が `preventDefault()` しない | `test_registering_with_the_grid_open_is_stopped` |
  | J13 | 新規登録の表に物件購入費を出す | `test_the_create_grid_has_no_property_purchase_row` |
  | J14 | `costGridParse` がカンマを落とさない | `test_parsing_formatting_and_totals`・`test_the_estimate_grid_is_saved_in_one_request` |
  | V1 | `projects/show` から `_cost_grid` の include を外す | `CostGridScreenTest` の分譲地のデータ |

  緑のまま通った変異があれば、穴としてテストを足してから当て直す（等価なら理由を書く）
- [ ] **Step 4: 実ブラウザ**（使い捨ての SQLite ＋ `artisan serve`・Playwright・画面が見えている状態。手順は main repo の `.claude/route-coverage-tools/browser/README.md`。作業場所で `npm run build` してから・使い捨てのログイン用ルートは戻す）: ① 仕入れ案件の詳細で見込み額の表を開くと最初のセルにカーソル・Enter / Shift+Enter / 上下の矢印で物件購入費を飛ばして動く ② 欄を離れるとカンマ付き・合計が打つたびに変わる ③ 本物の `paste`（`DataTransfer`）で列が下へ入る ④ 保存 → 一覧に反映・成功の文 ⑤ 確定額の表の差額の色 ⑥ 保存せずにリロードでブラウザの確かめが出る（Playwright の dialog） ⑦ 見込み額を空にして保存で削除の確かめ ⑧ 新規登録で表を開いたまま「登録する」で止まり、閉じてから登録できる ⑨ 375px と 1440px で `main` の横スクロール 0・表は枠の中でスクロール ⑩ 試算表 Excel 取込のパネルが今どおり開閉する ⑪ コンソールのエラー 0。分譲地は ①④⑦⑨ を測る
- [ ] **Step 5: 記録** — BACKLOG に節（設計書・計画へのリンク・決めたこと・範囲外・検証の数）。CLAUDE.md を 1 行ずつ直す
- [ ] **Step 6: コミット** — `docs: 原価管理の一覧入力の実装と検証を BACKLOG に記録する`

### Task 6: 本番反映（利用者の承認を段ごとに取る）

- [ ] **Step 1**: 「社内決裁申請」の会話が動いていないか ListAgents で確かめる（動いていれば先に知らせ、13.x の早送りと `./deploy.sh` を待ってもらう）。13.x が進んでいれば作業場所へ merge して全件を流し直す
- [ ] **Step 2（承認）**: 本番の読み取り — 変わるファイルの md5 が今の 13.x と一致・`laravel.log` の日付・CSS の名前
- [ ] **Step 3（承認）**: main repo で `git merge --ff-only realestate-cost-grid` → **main repo の cwd で** `composer dump-autoload --no-dev --optimize`（`RealEstateCostGrid` が classmap に 1 行・作業場所のパスが 0・`vendor/bin/phpunit` が無い）→ `./deploy.sh`
- [ ] **Step 4（承認）**: 反映後の読み取り — 変えたファイルの md5 一致・コンパイル済みビューの lint（INVALID 0）・`route:list --json` で batch の 2 本があり store / update が無い・`laravel.log` に新しいエラーが無い
- [ ] **Step 5（承認）**: ログイン済みの Chrome で見るだけ（保存しない）— 仕入れ案件・分譲地の詳細で 2 つのボタンと表が開いて取消で閉じる・新規登録の画面・`main` の横スクロール 0・コンソールのエラー 0
- [ ] **Step 6**: BACKLOG に本番反映を追記してコミット。push は利用者が「push して」と言ったときだけ
