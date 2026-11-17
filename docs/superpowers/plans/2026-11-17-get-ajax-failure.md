# GET の Ajax でログインが切れたときに理由を出す Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 基幹の画面の GET の Ajax（23 の失敗の分岐）が、ログインが切れた・無効にされた・権限が無いときに番号ではなく理由を出す。

**Architecture:** 共通の部品 `_partials/_ajax_get_failure.blade.php`（`<script>`・`@once`）に文を作る関数を 2 つ置き、23 の分岐がそれを通す。サーバーの応答は変えない。走査テストで「GET の失敗の分岐は部品を通す」「呼ぶ画面は部品を読み込む」を全件で固定する。

**Tech Stack:** Laravel 12 Blade・素の JS（ES5 の `var`/`function`。賃貸マンションの契約の登録だけ async/await）・PHPUnit・node の vm で画面の JS を動かす `Tests\Concerns\DrivesAlpineFetch`

**Spec:** docs/superpowers/specs/2026-11-17-get-ajax-failure-design.md

## Global Constraints

- サーバー・DB・ルート・`bootstrap/app.php` は変えない（401 は決裁の URL だけ日本語。Bug #137 の決め）
- 部品: `resources/views/_partials/_ajax_get_failure.blade.php`。中身は `@once` → `<script>` → 関数 2 つ → `</script>` → `@endonce`（`buyers/_xhr_failure_message` と同じ形）。説明は先頭の Blade コメントに書く（script の中のコメントにアットマークで始まる語・コンポーネントのタグを書かない。Bug #30）。部品の中に `fetch(` と書かない（`AjaxErrorFeedbackTest` の全件分類が拾う）
- `ajaxGetFailureMessage(status, body, what, fallback)` → 文字列か `fallback`（null を渡せば null）
  1. `(status === 401 || status === 403)` かつ `body.message` が文字列で ASCII 以外の文字を含む（`/[^\x00-\x7f]/`）→ `what + 'できませんでした。' + body.message`
  2. `status === 401` → `'画面を開いてから時間がたったか、ほかの画面でログアウトしたため、' + what + 'できませんでした。入力中の内容があれば控えてから、画面を開き直してください。'`
  3. ほか → `fallback`
- `ajaxGetFailure(res, what, fallback)` → Promise。`res.json()` を読み（拒否されたら本文なし＝null）、`ajaxGetFailureMessage(res.status, 本文, what, fallback)` で解決する
- 呼ぶ画面のファイル自身が `@include('_partials._ajax_get_failure')` を、関数を呼ぶ `<script>` より前に書く
- 各分岐の `what` と fallback は下の「分岐の表」どおり。fallback は今の文（「データの取得」の 7 か所だけ言い方を変える）
- null 返しの形（`if (!res.ok) { …; return …; }` と `if (!data) return;` の対）を崩さない（`AjaxErrorFeedbackTest`）
- テストのデータセットは `#[DataProvider]`（doc-comment の `@dataProvider` は PHPUnit の非推奨の警告）。`git stash` は使わない。全件は `--display-phpunit-deprecations` を付けて流す
- テストの流し方（作業場所の cwd）: `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit --display-phpunit-deprecations`

テストで使う文（各テストの定数にする）:

```php
private const EXPIRED = '画面を開いてから時間がたったか、ほかの画面でログアウトしたため、';
private const TAIL = 'できませんでした。入力中の内容があれば控えてから、画面を開き直してください。';
private const INACTIVE = 'このアカウントは無効になっています。管理者にお問い合わせください。';
private const NO_DEPARTMENT = 'この部門のデータへのアクセス権限がありません。';
```

### 分岐の表（2026-11-17 の 13.x の行番号）

| # | ファイル:行 | what | fallback（今の文の形） | 出し先 |
|---|---|---|---|---|
| 1 | tenant/contracts/create:545 | 空き区画を取得 | 空き区画の取得に失敗しました（N） | alert |
| 2 | tenant/contracts/create:592 | 問合せを取得 | 問合せの取得に失敗しました（N） | alert |
| 3 | tenant/contracts/create:497 | 顧客を検索 | 顧客の検索に失敗しました（N） | customerError |
| 4 | tenant/contracts/edit:435 | 顧客を検索 | 同上 | customerError |
| 5 | tenant/inquiries/create:49 | 顧客を検索 | 同上 | customerError |
| 6 | tenant/inquiries/edit:63 | 顧客を検索 | 同上 | customerError |
| 7 | housing/custom-orders/_form:357 | 区画を取得 | 区画の取得に失敗しました（N） | alert |
| 8 | housing/custom-orders/_form:402 | 仕入れ案件の情報を取得 | 仕入れ案件情報の取得に失敗しました（N） | alert |
| 9 | housing/custom-orders/_form:436 | 顧客を検索 | 顧客の検索に失敗しました（N） | customerError |
| 10 | housing/properties/_form:278 | 区画を取得 | 区画の取得に失敗しました（N） | alert |
| 11 | housing/properties/_form:324 | 仕入れ案件の情報を取得 | 仕入れ案件情報の取得に失敗しました（N） | alert |
| 12 | mansion/contracts/create:64-65（2 本で 1 分岐） | 空き部屋・空き駐車場を取得 | 空き部屋・空き駐車場の取得に失敗しました（N） | alert（await） |
| 13 | mansion/parking-contracts/create:57 | 空き駐車場を取得 | 空き駐車場の取得に失敗しました（N） | alert |
| 14 | realestate/_partials/supplier-picker:155 | 仕入れ先を検索 | 仕入れ先の検索に失敗しました（N） | alert |
| 15 | realestate/contracts/create:558 | 仕入れ案件の原価を取得 | 仕入れ案件の原価の取得に失敗しました（N） | alert |
| 16 | realestate/contracts/create:591 | 分譲地の区画を取得 | 分譲地の区画の取得に失敗しました（N） | alert |
| 17 | realestate/contracts/create:601 | 分譲地の原価を取得 | 分譲地の原価の取得に失敗しました（N） | alert |
| 18〜20 | realestate/contracts/edit:369・378・380 | 15〜17 と同じ | 15〜17 と同じ | alert |
| 21 | zeal/simulations/show:282 | プレビューを取得 | プレビューの取得に失敗しました（HTTP N） | throw → errorMsg |
| 22 | buyers/_form:524（XHR） | 郵便番号を取得 | （null。今の文のまま） | alert |
| 23 | mansion/properties/_form:495（XHR） | 郵便番号を取得 | （null。今の文のまま） | alert |

（N は `res.status`。14〜20 は今「データの取得に失敗しました（N）」）

## Review Focus

1. 401 なのに本文が JSON でない（途中の中継が HTML を返す）→ ログインが切れた文を出す（Task 1 の部品のテスト）
2. 403 の本文が英語（Laravel の既定の `This action is unauthorized.`）→ ログインが切れたと言わず、番号つきの今の文（Task 1）
3. 本文の `message` が文字列でない（配列など）→ 決まり 1 を通さない（Task 1）
4. 1 つの操作で GET が 2 本並ぶ（テナントの契約の登録で物件を選ぶ）→ それぞれの対象の名前で、送った順に 2 つ出る（Task 1）
5. 住所→〒の 404（`{error: '…'}`）と HTML の 500 → 今の「見つかりませんでした」系の文のまま・HTML でも止まらない（Task 2）

---

### Task 1: 部品とテナントの 6 か所

**Files:**
- Create: `resources/views/_partials/_ajax_get_failure.blade.php`
- Modify: `resources/views/tenant/contracts/create.blade.php`（分岐 1〜3）・`tenant/contracts/edit.blade.php`（4）・`tenant/inquiries/create.blade.php`（5）・`tenant/inquiries/edit.blade.php`（6）
- Test: `tests/Feature/Tenant/Screens/GetAjaxFailureTest.php`（新規。`TenantScreenTestCase` を継ぐ）

**Interfaces:**
- Produces: JS の `ajaxGetFailureMessage(status, body, what, fallback)`・`ajaxGetFailure(res, what, fallback)`（Global Constraints のとおり）。Task 2・3 が使う

- [ ] **Step 1: 失敗するテストを書く**（`GetAjaxFailureTest`。上の 4 つの定数）
  - `test_the_helper_says_why_for_each_kind_of_failure`: `htmlOf(route('tenant.contracts.create'))` を `driveAlpine($html, null, '({})', $steps, [], true, $evaluate, ['function ajaxGetFailureMessage('])` で動かす。`$evaluate` と期待:
    - `ajaxGetFailureMessage(401, {message: 'Unauthenticated.'}, '空き区画を取得', 'F')` → `EXPIRED . '空き区画を取得' . TAIL`
    - `ajaxGetFailureMessage(401, null, 'X', 'F')` → `EXPIRED . 'X' . TAIL`
    - `ajaxGetFailureMessage(401, {message: ['無効']}, 'X', 'F')` → `EXPIRED . 'X' . TAIL`
    - `ajaxGetFailureMessage(401, {message: INACTIVE}, 'X', 'F')` → `'Xできませんでした。' . INACTIVE`
    - `ajaxGetFailureMessage(403, {message: NO_DEPARTMENT}, 'X', 'F')` → `'Xできませんでした。' . NO_DEPARTMENT`
    - `ajaxGetFailureMessage(403, {message: 'This action is unauthorized.'}, 'X', 'F')` → `'F'`
    - `ajaxGetFailureMessage(500, {message: '日本語の理由'}, 'X', 'F')` → `'F'`
    - `ajaxGetFailureMessage(404, null, 'X', null)` → `null`
    - `$steps` で `ajaxGetFailure({status: 401, json: function () { return Promise.reject(new Error('HTML')); }}, 'X', 'F').then(function (m) { data.html = m; });` と、`status: 403` で `json` が `{message: NO_DEPARTMENT}` を返すものを `data.forbidden` に。`state['html']` = `EXPIRED . 'X' . TAIL`・`state['forbidden']` = `'Xできませんでした。' . NO_DEPARTMENT`
  - `test_choosing_a_property_says_why_when_refused`（`#[DataProvider]`: `signedOut`・`inactive`・`forbidden`）: 登録画面で `data.propertyId = '{building id}'; data.onPropertyChange();` を動かして要求 2 本を取り出す（`assertCount(2, …)`）→ 場面を作る（`signedOut`: `auth()->logout()`／`inactive`: `$this->user->status = UserStatus::Inactive` を保存／`forbidden`: 役割を `UserRole::Manager` にして保存〈部署なしなので部署の確かめが断る〉）→ 2 本を `sendCaptured` で送り `assertStatus(401/401/403)` → 応答を `asFetchResponse` で戻して同じ手順をもう一度動かし、`assertSame` で `alerts` が `[空き区画の文, 問合せの文]`:
    - signedOut: `EXPIRED . '空き区画を取得' . TAIL`, `EXPIRED . '問合せを取得' . TAIL`
    - inactive: `'空き区画を取得できませんでした。' . INACTIVE`, `'問合せを取得できませんでした。' . INACTIVE`
    - forbidden: `'空き区画を取得できませんでした。' . NO_DEPARTMENT`, `'問合せを取得できませんでした。' . NO_DEPARTMENT`
  - `test_other_failures_keep_the_old_message`: `data.propertyId = '999999'` で同じ流れ（ログインしたまま 404）→ `alerts` が `['空き区画の取得に失敗しました（404）', '問合せの取得に失敗しました（404）']`
  - `test_a_customer_search_says_why_under_the_field`（`#[DataProvider]`: 4 画面 = 契約の登録・編集・問合せの登録・編集。関数 `contractCreateForm`・`contractEditForm`・`inquiryCreateForm`・`inquiryEditForm`）: `$steps` の先頭で `setTimeout = function (fn) { fn(); return 0; };`（検索の待ち時間を飛ばす）、`data.customerQuery = '山田'; data.searchCustomers();`（4 画面とも `searchCustomers`）→ 要求 1 本 → `auth()->logout()` → 401 を戻して `state['customerError']` = `EXPIRED . '顧客を検索' . TAIL`・`state['showCustomerDropdown']` が true・`alerts` は空

- [ ] **Step 2: 流して赤を確かめる**
  Run: `… vendor/bin/phpunit tests/Feature/Tenant/Screens/GetAjaxFailureTest.php`
  Expected: FAIL（部品の script が描かれていない「function ajaxGetFailureMessage( を含む <script> がちょうど 1 つ描かれていない」・alerts が「空き区画の取得に失敗しました（401）」）

- [ ] **Step 3: 部品を作り、テナントの 6 か所を通す**
  - alert の分岐の形: `if (!res.ok) { self.units = []; self.renderUnits(); return ajaxGetFailure(res, '空き区画を取得', '空き区画の取得に失敗しました（' + res.status + '）').then(function (message) { alert(message); return null; }); }`（分岐の前の片付けは今のまま）
  - 顧客の検索の形: `… return ajaxGetFailure(res, '顧客を検索', '顧客の検索に失敗しました（' + res.status + '）').then(function (message) { self.customerError = message; return null; });`（`customerResults = []`・`showCustomerDropdown = true` は今のまま、文を入れるのと同じ時に）
  - 4 画面それぞれで、`<script>` より前に `@include('_partials._ajax_get_failure')`

- [ ] **Step 4: 流して緑を確かめる**（同じコマンド）＋ `tests/Feature/AjaxErrorFeedbackTest.php`・`tests/Feature/RealEstate/AjaxFetchSessionGuardTest.php`・`tests/Feature/Tenant/Screens` も緑

- [ ] **Step 5: コミット** `fix: テナントの画面の GET の Ajax で、ログインが切れたときに理由を出す`

---

### Task 2: 住宅事業・賃貸マンション・住所→〒（分岐 7〜13・22・23）

**Files:**
- Modify: `resources/views/housing/custom-orders/_form.blade.php`・`housing/properties/_form.blade.php`・`mansion/contracts/create.blade.php`・`mansion/parking-contracts/create.blade.php`・`mansion/properties/_form.blade.php`・`buyers/_form.blade.php`
- Test: `tests/Feature/Housing/Screens/GetAjaxFailureTest.php`・`tests/Feature/Mansion/Screens/GetAjaxFailureTest.php`（新規。各部署の `…ScreenTestCase` を継ぐ）

**Interfaces:**
- Consumes: Task 1 の 2 つの関数

- [ ] **Step 1: 失敗するテストを書く**（Task 1 と同じ定数。どれも要求を取り出す → `auth()->logout()` → 401 を戻す。期待は `EXPIRED . what . TAIL`）
  - Housing `test_choosing_a_lot_or_procurement_says_why_when_the_login_expired`（`#[DataProvider]` 4 つ: 建売・注文住宅 × 区画〈`data.landSourceType = "project_lot"; data.onSourceTypeChange(); data.selectedProjectId = "{id}"; data.onProjectChange();`〉・仕入れ案件〈`PropertyScreensTest` の手順〉）→ `alerts` が `[EXPIRED.'区画を取得'.TAIL]` か `[EXPIRED.'仕入れ案件の情報を取得'.TAIL]`
  - Housing `test_the_custom_order_customer_search_says_why`: 注文住宅の登録で `setTimeout` を差し替えて `data.customerName = '山田'; data.searchCustomer();`（こちらは単数形） → `customerError` = `EXPIRED . '顧客を検索' . TAIL`。もう 1 本、住宅事業だけの管理者（`member(UserRole::Manager)`）で同じ検索を送ると 403 → `'顧客を検索できませんでした。' . NO_DEPARTMENT`（設計メモの「今もある」場面）
  - Mansion `test_choosing_a_property_says_why_when_the_login_expired`（`#[DataProvider]` 2 つ: 部屋契約の登録〈`loadVacancies()`。要求 2 本・401 を 2 つ戻す〉・駐車場契約の登録〈`loadVacantParkings()`〉）→ `alerts` が `[EXPIRED.'空き部屋・空き駐車場を取得'.TAIL]`（1 つ）か `[EXPIRED.'空き駐車場を取得'.TAIL]`
  - Mansion `test_reverse_zip_says_why_when_the_login_expired`: 物件の登録画面で `driveAlpine($html, 'reverseMansionLookup', '({})', 'reverseMansionLookup();', …, elements: ['input[name="address"]' => '愛媛県松山市二番町'])`→ 401 を戻して `alerts` = `[EXPIRED . '郵便番号を取得' . TAIL]`
  - Mansion `test_reverse_zip_keeps_the_old_message_for_other_failures`: `{status: 404, body: {error: '該当なし'}}` → `['該当なし']`、`{status: 500, body: '<html>'}` → `['該当する郵便番号が見つかりませんでした']`
  - Housing `test_buyer_reverse_zip_says_why_when_the_login_expired`: 買主の登録画面（住宅事業の顧客）で `reverseLookup()`（`elements`: `select[name="prefecture"]` => '愛媛県'・`input[name="city"]` => '松山市'）→ 401 → `[EXPIRED . '郵便番号を取得' . TAIL]`。もう 1 本、`{status: 500, body: '<html>'}` で `['該当する郵便番号が見つかりませんでした']`（今は JSON.parse で止まって何も出ない）

- [ ] **Step 2: 流して赤を確かめる**（2 ファイル。理由は「…の取得に失敗しました（401）」・「該当する郵便番号が見つかりませんでした」・部品の script が無い）

- [ ] **Step 3: 6 ファイルを通す**
  - 7・8・10・11・13 は Task 1 の alert の形。9 は顧客の検索の形
  - 12（async）: `var failed = roomsRes.ok ? parkingsRes : roomsRes; alert(await ajaxGetFailure(failed, '空き部屋・空き駐車場を取得', '空き部屋・空き駐車場の取得に失敗しました（' + failed.status + '）')); return;`
  - 22・23（XHR の `onload` の 200 以外）: `var body = null; try { body = JSON.parse(xhr.responseText); } catch (e) {}` → `var message = ajaxGetFailureMessage(xhr.status, body, '郵便番号を取得', null); if (message) { alert(message); return; }` → あとは今の文を `body` から（買主: `alert((body && body.error) || '該当する郵便番号が見つかりませんでした')`）
  - 6 ファイルに `@include('_partials._ajax_get_failure')`

- [ ] **Step 4: 流して緑を確かめる**（2 ファイル＋ `AjaxErrorFeedbackTest`・`AjaxFetchSessionGuardTest`・`tests/Feature/Housing/Screens`・`tests/Feature/Mansion/Screens`）

- [ ] **Step 5: コミット** `fix: 住宅事業・賃貸マンション・住所→〒の GET で、ログインが切れたときに理由を出す`

---

### Task 3: 不動産と ZEAL（分岐 14〜21）

**Files:**
- Modify: `resources/views/realestate/_partials/supplier-picker.blade.php`・`realestate/contracts/create.blade.php`・`realestate/contracts/edit.blade.php`・`zeal/simulations/show.blade.php`
- Test: `tests/Feature/RealEstate/Screens/GetAjaxFailureTest.php`（新規。`RealEstateScreenTestCase`）・`tests/Feature/Zeal/SimulationSyncActualsTest.php`（1 本足す）

**Interfaces:**
- Consumes: Task 1 の 2 つの関数

- [ ] **Step 1: 失敗するテストを書く**（どれも `auth()->logout()` → 401）
  - RealEstate `test_choosing_on_the_contract_screen_says_why_when_the_login_expired`（`#[DataProvider]` 4 つ: 登録・編集 × 仕入れ案件〈要求 1 本 → `[EXPIRED.'仕入れ案件の原価を取得'.TAIL]`〉・分譲地〈要求 2 本 → `[EXPIRED.'分譲地の区画を取得'.TAIL, EXPIRED.'分譲地の原価を取得'.TAIL]`〉。手順は `AmountLimitTest`・`ContractScreensTest` の選び方）
  - RealEstate `test_other_failures_name_what_was_being_fetched`: 登録で仕入れ案件の要求に `{status: 500, body: null}` → `['仕入れ案件の原価の取得に失敗しました（500）']`
  - RealEstate `test_the_supplier_search_says_why_when_the_login_expired`: 仕入れ案件の登録画面で `withScripts: ['function supplierPicker(']`・`supplierPicker` の x-data を使い、`setTimeout` を差し替えて `data.supplierQuery = '松山'; data.searchSupplier();` → `[EXPIRED . '仕入れ先を検索' . TAIL]`
  - Zeal `test_the_preview_says_why_when_the_login_expired`: `openPreview()` の要求 → `auth()->logout()` → 401 → `state['errorMsg']` = `EXPIRED . 'プレビューを取得' . TAIL`

- [ ] **Step 2: 流して赤を確かめる**

- [ ] **Step 3: 4 ファイルを通す**（14〜20 は alert の形。21 は `if (!res.ok) { return ajaxGetFailure(res, 'プレビューを取得', 'プレビューの取得に失敗しました（HTTP ' + res.status + '）').then(function (message) { throw new Error(message); }); }`。4 ファイルに include。supplier-picker は部品の中に書く）

- [ ] **Step 4: 流して緑を確かめる**（2 ファイル＋ `AjaxErrorFeedbackTest`・`AjaxFetchSessionGuardTest`・`tests/Feature/RealEstate`・`tests/Feature/Zeal`）

- [ ] **Step 5: コミット** `fix: 不動産と ZEAL の画面の GET で、ログインが切れたときに理由を出す`

---

### Task 4: 走査で全件を固定する

**Files:**
- Create: `tests/Concerns/ScansSameOriginGetAjax.php`（`AjaxFetchSessionGuardTest` の `fetchCall()`・`precedingLines()` と、自社への GET の fetch・XMLHttpRequest を数える回し方を移す）
- Modify: `tests/Feature/RealEstate/AjaxFetchSessionGuardTest.php`（トレイトを使う。振る舞いは変えない）
- Create: `tests/Feature/GetAjaxFailureScanTest.php`

**Interfaces:**
- Produces（トレイト）: `sameOriginGetFetches(): array<int, array{file: string, line: int, pos: int, source: string, call: string}>`（`resources/views` の Blade の全件。`://` を含む・`method:` が GET 以外は除く）・`sameOriginGetXhrOpens(): array<int, array{file: string, line: int, pos: int, source: string}>`

- [ ] **Step 1: トレイトに移し、`AjaxFetchSessionGuardTest` を流して緑のまま**（変異で 1 回確かめる: ビュー 1 か所の `X-Requested-With` を消すと、そのファイルと行を名指しして赤）

- [ ] **Step 2: 走査のテストを書く**（`GetAjaxFailureScanTest`）
  - 除く分類（理由つき・実在しない分類は落とす）: `approvals/requests/form.blade.php`（決裁。サーバーが日本語の 401 を返す・担当は別の会話）・`_partials/_schedule_section.blade.php`（`send()` は保存に使い、GET では呼ばない）
  - `test_every_get_fetch_failure_goes_through_the_helper`: 各 fetch の呼び出しの後ろで最初に現れる `.ok` の分岐（`/if\s*\(\s*![a-zA-Z_$][\w$]*\.ok\b/`。中身は `{` から波括弧の対応で切る。`AjaxErrorFeedbackTest::okGuardBodies()` と同じ切り方）に `ajaxGetFailure(` があること。名指しは「ファイル:行」。拾えた分岐（重複を除く）の下限 21
  - `test_every_get_xhr_failure_goes_through_the_helper`: `.open('GET'` から `.send(` までに `ajaxGetFailureMessage(`。下限 2
  - `test_every_caller_includes_the_helper`: `ajaxGetFailure(` か `ajaxGetFailureMessage(` を呼ぶ Blade（部品を除く）は自分で `@include('_partials._ajax_get_failure')` を書く・関数の定義（`function ajaxGetFailure(`・`function ajaxGetFailureMessage(`）は部品にだけ。呼ぶファイルの下限 14
  - 見本（ソースの文字列を渡す）: 部品を通さない分岐を名指しする・`if (!roomsRes.ok || !parkingsRes.ok)` を分岐として読む・`://` の fetch は数えない

- [ ] **Step 3: 直す前の画面で赤を確かめる**: `git checkout 13.x -- <Task 1〜3 で変えたビュー 14 本>` → 走査を流す（分岐 21 か所・XHR 2 か所・include の不足を名指しして赤）→ `git checkout HEAD -- resources/views` → `git status --porcelain` が空

- [ ] **Step 4: 緑を確かめてコミット** `test: GET の Ajax の失敗が共通の部品を通すことを走査で固定する`

---

### Task 5: 検証と記録

- [ ] **Step 1: 全件** → `OK (… tests, … assertions)`・警告なし。コンパイル済みビューの lint（`view:cache` → 全部 `php -l` → `view:clear`）INVALID 0
- [ ] **Step 2: 変異**（作業場所の `.claude/route-coverage-tools/mutate/` に表を置く。前回の `run_esf.py` の形。コミットしてから 1 つずつ・落ちたテストの組と理由の 1 行目まで）: カナリア／決まり 1 を外す／決まり 1 の 403 を外す／ASCII の判定を外す（英語も理由として出す）／決まり 2 を外す／`ajaxGetFailure` が本文を読まない／`json()` の拒否を受け止めない／部署ごとに 1 か所を直す前の文に戻す（テナント・住宅・賃貸マンション・不動産・ZEAL・XHR 2）／include を 1 か所消す／走査の分かれ目（`.ok` の分岐の正規表現・除く分類・include の確かめ）
- [ ] **Step 3: 実ブラウザ**（`.claude/route-coverage-tools/browser/README.md` の使い捨ての環境）: テナントの契約の登録で cookie を消してから物件を選ぶ → 2 つの alert が新しい文・賃貸マンションの物件の登録で住所→〒・不動産の契約の登録で仕入れ案件を選ぶ・コンソールのエラーは意図した 401 だけ
- [ ] **Step 4: 記録**: `docs/RULES.md` の Bug #137 の行に追記（GET の 401）・`docs/BACKLOG.md` に節（直したこと・検証・範囲外）と、前回の反映のあとの ssh での本番の読み取りの結果（2026-11-17・ビュー 5 本の md5 一致・ビュー 322 本 INVALID 0・`laravel.log` は 6 月 18 日のまま・`bootstrap/cache` 600/700・CSS `app-o1yvrcrl.css`）・コミット
- [ ] **Step 5: 最後の点検**（別の担当・読み取りだけ）→ 直すものを直す
