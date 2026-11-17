# Bug #137 の後回し 4 つ Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 買主の 2 か所の XMLHttpRequest が通信の失敗でも理由を出し、419 の理由の読み取りを 1 か所にし、419 の画面のタブ名を区別でき、保存の Ajax の走査の見落としを書き残す。

**Architecture:** 買主の画面（`buyers/index`・`buyers/_form`）の JS だけを直し、共通の関数は新しい Blade 部品に置く。レイアウト `guest-layout` に任意の `title` を足す。テストは画面が描いた JS を node で動かす道具（`DrivesAlpineFetch`）で見る。道具に「通信の失敗」の応答を足す。

**Tech Stack:** Laravel 12 / Blade / 素の JS（XMLHttpRequest）/ PHPUnit 11 / node（vm）

**Spec:** `docs/superpowers/specs/2026-11-17-expired-session-followups-design.md`

## Global Constraints

- テストは作業場所の cwd で: `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit --display-phpunit-deprecations <path>`
- 文言（逐語）:
  - ランクの通信の失敗: `通信エラーが発生しました。画面を開き直して、ランクが変わったか確かめてください。`
  - 取り込みの通信の失敗: `通信エラーが発生しました。顧客の一覧で、この部署に取り込まれたか確かめてください。`
  - 419 の画面のタブ名: `画面の有効期限が切れました - {アプリ名}`（`{アプリ名}` は `config('app.name', '経営管理システム')`）
- 部品のパス: `resources/views/buyers/_xhr_failure_message.blade.php`。関数は `function xhrFailureMessage(xhr, fallback)`（今の `buyers/index` の中身をそのまま移す）
- 名前は英語。コメントは既存の書き方に合わせる（この 2 画面の JS のコメントと Blade のコメントは日本語）
- `git stash` は使わない。コミットのトレイラーは `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`
- 13.x への早送り・push・`./deploy.sh` はしない（利用者の指示があってから）

## Review Focus

1. 通信の失敗のとき、サーバーに届いて変わった可能性がある → 文言が「変わっていません」と言わないこと（逐語の文言をテストで固定。Task 2）
2. 419 なのに本文が JSON でない（HTML の画面が返った）→ 番号つきの文に戻ること（Task 1 の 404 のテストに 1 つ足す）
3. 部品を読み忘れた画面で関数が無い → 2 画面とも、関数を持つ `<script>` がちょうど 1 つ描かれること（`withScripts` の数の確かめ。Task 1）
4. ログイン画面のタブ名が変わらないこと（Task 3）
5. 同じ 419 の読み取りがまた画面に書き足されること → `xhr.status === 419` を書いてよいのは部品だけ（走査。Task 1）

---

### Task 1: 419 の理由を読む処理を 1 か所の部品にする（振る舞いは変えない）

**Files:**
- Create: `resources/views/buyers/_xhr_failure_message.blade.php`
- Modify: `resources/views/buyers/index.blade.php`（140 行目の `<script>` の前に `@include`・186〜195 行目の関数を消す）
- Modify: `resources/views/buyers/_form.blade.php`（336 行目の `<script>` の前に `@include`・443〜452 行目を `alert(xhrFailureMessage(xhr, 'この部署に取り込めませんでした（' + xhr.status + '）'));` に）
- Test: `tests/Feature/Housing/Screens/CustomerScreensTest.php`

**Interfaces:**
- Produces: JS の `xhrFailureMessage(xhr, fallback): string`（419 で本文が JSON の `message`（空でない文字）を持てばそれ、ほかは `fallback`）。部品は `@once` の中に `<script>` 1 つ。テストは `withScripts: ['function xhrFailureMessage']` で読む

- [ ] **Step 1: テストを直す・足す**
  - クラス定数 `private const XHR_FAILURE_SCRIPT = 'function xhrFailureMessage';`
  - `test_a_refused_rank_change_says_why` と `test_a_refused_add_to_department_says_why` の、失敗の応答を渡す `driveAlpine()`（2 回目以降）に `withScripts: [self::XHR_FAILURE_SCRIPT]` を足す
  - `test_a_refused_rank_change_says_why` の最後に: 419 で本文が HTML（`['status' => 419, 'body' => '<!DOCTYPE html><title>x</title>']`）なら `['ランクを変更できませんでした（419）']`
  - 新しい `test_the_419_reason_of_an_xhr_is_read_in_one_place`: `resources/views` の `*.blade.php` のうち `xhr.status === 419` を含むファイルの一覧（相対パス）が `['buyers/_xhr_failure_message.blade.php']`、`function xhrFailureMessage` を含むファイルも同じ一覧

- [ ] **Step 2: 赤を確かめる**

Run: 上のコマンド ＋ `tests/Feature/Housing/Screens/CustomerScreensTest.php --filter 'refused|read_in_one_place'`
Expected: `test_a_refused_add_to_department_says_why` が「「function xhrFailureMessage」を含む <script> がちょうど 1 つ描かれていない」、`test_the_419_reason_of_an_xhr_is_read_in_one_place` が一覧の食い違いで FAIL。ランクのテストは緑（今の関数は selectRank と同じ script にある）

- [ ] **Step 3: 部品を作り、2 画面から読む**

部品の先頭に Blade コメント（何のための部品か・Bug #137・`@once` の理由）。中身は今の `buyers/index` の関数をコメントも含めてそのまま移す。

- [ ] **Step 4: 緑を確かめる**

Run: 上のコマンド ＋ `tests/Feature/Housing/Screens/CustomerScreensTest.php`
Expected: PASS（全部）

- [ ] **Step 5: Commit** — `refactor: 買主の XMLHttpRequest の 419 の読み取りを 1 つの部品にする`

---

### Task 2: 通信の失敗でも理由を出す（onerror）

**Files:**
- Modify: `tests/Concerns/DrivesAlpineFetch.php`（偽の XMLHttpRequest と `$responses` の説明・新しい補助）
- Modify: `resources/views/buyers/index.blade.php`（`selectRank()` に `xhr.onerror`）
- Modify: `resources/views/buyers/_form.blade.php`（`addToDepartment()` に `xhr.onerror`）
- Test: `tests/Feature/Housing/Screens/CustomerScreensTest.php`

**Interfaces:**
- Consumes: Task 1 の部品（通信の失敗では使わない。文は固定）
- Produces: `protected function networkFailure(): array`（`['network_error' => true]`）。`driveAlpine()` の `$responses` にこれを渡すと、偽の XMLHttpRequest は `status = 0`・`responseText = ''` で `onerror` を呼ぶ（無ければ何もしない。`onload` は呼ばない）。fetch には使わない（docblock に「XMLHttpRequest だけ」と書く）

- [ ] **Step 1: 道具を広げる** — 偽の XMLHttpRequest のコンストラクタに `this.onerror = null`、`send` で `response.network_error` なら上の動き。`networkFailure()` を `asFetchResponse()` の隣に置く

- [ ] **Step 2: テストを足す**
  - `test_a_rank_change_that_cannot_reach_the_server_says_so`: 一覧から `selectRank("A")`、応答 `[$this->networkFailure()]` → `alerts` が `['通信エラーが発生しました。画面を開き直して、ランクが変わったか確かめてください。']`、`requests` が 1 件
  - `test_an_add_to_department_that_cannot_reach_the_server_says_so`: 登録画面から `addToDepartment(id)`、応答 `[$this->networkFailure()]` → `alerts` が `['通信エラーが発生しました。顧客の一覧で、この部署に取り込まれたか確かめてください。']`、`navigations` が `[]`

- [ ] **Step 3: 赤を確かめる**

Run: 上のコマンド ＋ `tests/Feature/Housing/Screens/CustomerScreensTest.php --filter cannot_reach`
Expected: 2 本とも `alerts` が `[]` で FAIL

- [ ] **Step 4: 2 画面に `xhr.onerror = function () { alert('…'); };` を足す**（`xhr.onload` の直後。文言は Global Constraints の逐語）

- [ ] **Step 5: 緑を確かめる**

Run: 上のコマンド ＋ `tests/Feature/Housing/Screens/CustomerScreensTest.php`
Expected: PASS

- [ ] **Step 6: Commit** — `fix: 買主のランク変更と部署への取り込みで、通信が失敗したら理由を出す`

---

### Task 3: 419 の画面のタブ名

**Files:**
- Modify: `resources/views/components/guest-layout.blade.php`（先頭に `@props(['title' => null])`・`<title>`）
- Modify: `resources/views/errors/419.blade.php`（`<x-guest-layout title="画面の有効期限が切れました">`）
- Test: `tests/Feature/ExpiredSessionResponseTest.php`

**Interfaces:**
- Produces: `<x-guest-layout title="…">`（任意）。`<title>` は title があれば「title - アプリ名」、無ければ今と同じアプリ名だけ

- [ ] **Step 1: テストを足す**
  - `assertExpiredPage()` に: `<title>` + `e(self::PAGE_TITLE . ' - ' . config('app.name', '経営管理システム'))` + `</title>` を含む
  - `test_the_login_form_with_an_expired_page_shows_the_same_page` の、ログイン画面の `$html` に: `<title>` + `e(config('app.name', '経営管理システム'))` + `</title>` を含む

- [ ] **Step 2: 赤を確かめる**

Run: 上のコマンド ＋ `tests/Feature/ExpiredSessionResponseTest.php`
Expected: 419 の画面の 2 本がタブ名で FAIL・ログイン画面のタブ名の確かめは緑

- [ ] **Step 3: レイアウトと 419 の画面を直す**（レイアウトの Blade コメントに、title の形が `layouts/app` と同じであることを 1 行）

- [ ] **Step 4: 緑を確かめる** — 同じコマンドで PASS

- [ ] **Step 5: Commit** — `fix: 419 の画面のタブ名をログイン画面と区別できるようにする`

---

### Task 4: 保存の Ajax の走査の見落としを書き残す

**Files:**
- Modify: `tests/Feature/RealEstate/AjaxFetchSessionGuardTest.php`（`saveAjaxWithoutJson()` の docblock・`test_the_save_scanner_reads_each_way_of_writing()` の見本）

- [ ] **Step 1: docblock に死角を書く**（4 つ。どれも 2026-11-17 の実物は 0 件。設計メモの「4 の見落とし」と同じ文）
- [ ] **Step 2: 見本を 4 つ足す**（今の動きを固定する。2026-11-17 に測った値）

| ラベル | 見本 | fetch | XHR | 求めない行 |
|---|---|---|---|---|
| 死角: fetch の中のコメントの Accept も求めているとみなす | `fetch(url, { method: 'POST', /* 'Accept': 'application/json' */ headers: {} })` | 1 | 0 | `[]` |
| 死角: オプションを変数で渡す fetch は GET とみなす | `fetch(url, opts)` | 0 | 0 | `[]` |
| 死角: XHR の open(変数) は数えない | `x.open(verb, url);\nx.send(b);` | 0 | 0 | `[]` |
| 厳しすぎる向き: Accept に型を並べると求めていないとみなす | `fetch(url, { method: 'POST', headers: { 'Accept': 'application/json, text/plain' } })` | 1 | 0 | `[1]` |

- [ ] **Step 3: 緑を確かめる**

Run: 上のコマンド ＋ `tests/Feature/RealEstate/AjaxFetchSessionGuardTest.php`
Expected: PASS（今の動きの固定なので、赤の段は無い。効いていることは Task 5 の変異で見る）

- [ ] **Step 4: Commit** — `test: 保存の Ajax の走査の見落としを書き、見本で固定する`

---

### Task 5: 確かめと記録

**Files:**
- Modify: `docs/RULES.md`（Bug #137 の行に追記）・`docs/BACKLOG.md`（新しい節・Bug #137 の節の範囲外に「→ 2026-11-17 に直した」）
- Create（git の外）: `.claude/route-coverage-tools/mutate/specs_expired_session_followups.py`・`run_esf.py`（main repo の `run_es.py` の写し。WT・SPECS・TESTS・結果の名前だけ変える）。作業場所を片付ける前に main repo の同じ場所へ写す

- [ ] **Step 1: 全件テスト** — Expected: `OK (5213 + 新しい本数 tests, …)`・警告なし
- [ ] **Step 2: コンパイル済みビューの lint** — `view:cache` のあと `storage/framework/views/*.php` を `php -l`、INVALID 0（そのあと `view:clear`）
- [ ] **Step 3: 変異**（コミットしてから・1 つずつ・作業ツリーが空・着弾を `git diff --stat` で・落ちたテストの組と理由まで）

| # | 変異 | 落ちるはずのテストと理由 |
|---|---|---|
| C0 | ランクの番号つきの文を `ランクを変えられませんでした` に（カナリア） | `test_a_refused_rank_change_says_why`（404・HTML の 419 の文） |
| M1 | `buyers/index` の `xhr.onerror` を消す | `test_a_rank_change_that_cannot_reach_the_server_says_so`（alerts が `[]`） |
| M2 | `buyers/_form` の `xhr.onerror` を消す | `test_an_add_to_department_that_cannot_reach_the_server_says_so`（alerts が `[]`） |
| M3 | 道具が `onerror` を呼ばない | M1・M2 の 2 本 |
| M4 | `buyers/_form` を直す前のインラインの 419 の読み取りに戻す | `test_the_419_reason_of_an_xhr_is_read_in_one_place` |
| M5 | `buyers/_form` の `@include` を消す | `test_a_refused_add_to_department_says_why`（script がちょうど 1 つ無い） |
| M6 | `buyers/index` の `@include` を消す | `test_a_refused_rank_change_says_why`（同上） |
| M7 | 部品の関数が常に `fallback` を返す | 2 本の `refused` のテスト（419 の文） |
| M8 | 419 の画面から `title` を外す | 419 の画面の 2 本（タブ名） |
| M9 | レイアウトが `$title` を出さない | 同上 |
| M10 | レイアウトが title が無くても ` - ` を付ける | ログイン画面のタブ名の確かめ |
| M11 | `FETCH_ASKS_FOR_JSON` の `application\/json` の後ろの引用符を外す | `test_the_save_scanner_reads_each_way_of_writing`（厳しすぎる向きの見本） |

TESTS は `tests/Feature/Housing/Screens`・`tests/Feature/RealEstate`・`tests/Feature/ExpiredSessionResponseTest.php`・`tests/Feature/Approval/Phase2/ApprovalJsonExpiredSessionTest.php`。

- [ ] **Step 4: 実ブラウザ**（使い捨ての SQLite ＋ `artisan serve`・Playwright・画面が見えている状態）: 買主一覧を開いたあとサーバーを止めてランクを変える → 文言の alert／登録画面で同じことを「この部署に取り込む」で／`curl -X POST -d '' …/login` の 419 の `<title>`／ログイン画面の `<title>`／コンソールのエラー
- [ ] **Step 5: 記録を書いてコミット** — `docs: Bug #137 の後回し 4 つを記録する`（RULES の #137 の行・BACKLOG の節〈全件の数・変異の結果・実ブラウザ・範囲外〉）
