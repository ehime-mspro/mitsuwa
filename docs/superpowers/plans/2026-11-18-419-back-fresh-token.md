# 419 の画面から戻った画面を送り直せるようにする 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 419 の画面の「前の画面に戻る」で戻った画面の送信の鍵を新しい鍵に差し替え、そのまま送り直せるようにする。あわせて、419 の画面の案内をログインしているかで出し分ける。

**Architecture:** 419 の画面が自分のメタの鍵を `sessionStorage` に 1 つだけ書く。2 つのレイアウトの head（メタの直後）にある部品が、読み込みのたびにそれを読んで消し、「戻る・進む」で表示したときだけメタと `_token` を差し替える。サーバーの鍵の確かめ・ルート・DB は変えない。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade / 素の JavaScript（ES5 の書き方。部品は Alpine を使わない）/ PHPUnit・node の vm（部品の実駆動）

**Spec:** `docs/superpowers/specs/2026-11-18-419-back-fresh-token-design.md`（2026-11-18 承認）

**作業場所:** `/Users/masanori/site/manage/.claude/worktrees/back-fresh-token`（枝 `back-fresh-token`。vendor は整え済み）

## Global Constraints

- 鍵を書くキーは `freshCsrfTokenAfter419` の 1 つ。部品だけが知る（419 の画面は部品の関数 `window.rememberFreshCsrfToken()` を呼ぶ）
- `sessionStorage` への読み書きはすべて try で包む（例外で画面の JS を止めない）
- サーバーの鍵の確かめ（`VerifyCsrfToken`）・`bootstrap/app.php`・ルート・DB は変えない
- 説明は Blade コメント `{{-- --}}` に書く。script の中のコメントにアットマークで始まる語・コンポーネントのタグを書かない（Bug #30）
- コードの名前は英語、コメントは日本語（既存の Blade・JS に合わせる）
- 419 の画面の文（逐語）:
  - 1 段落目（今のまま）: `画面を開いてから時間がたったか、ほかの画面でログアウトしたため、送れませんでした（何も保存していません）。`
  - ログインしている: `「前の画面に戻る」で戻ると、そのまま送り直せます。入力した内容が消えていたら、入れ直してください。`
  - ログインしていない: `ログインが切れています。「前の画面に戻る」で戻って入力した内容を控えてから、ログインし直して、もう一度入力してください（戻った画面から送ると、ログイン画面に移ります）。`
  - ボタン: 1 つめは両方とも `前の画面に戻る`（`onclick="history.back()"`）。2 つめはログインしている `トップページへ`（`url('/')`）／していない `ログイン画面へ`（`route('login')`）
- テストの流し方（作業場所の cwd。3〜4 分。全件は裏で）:
  `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit --display-phpunit-deprecations [ファイル]`
- 作業場所に `.env` を作らない・`git stash` を使わない・13.x の早送り・push・`./deploy.sh` はしない（利用者の指示を待つ）
- コミットの最後の行: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`（各自の文脈の行を使う）

## Review Focus

1. **ブラウザが「戻る」で hidden の `_token` を古い値に戻す**（Chromium はフォームの値を戻す）→ `pageshow`（persisted でないときも）で差し替え直す。Task 1 の back の テストで「DOMContentLoaded のあと古い値に戻された」場合を持つ。実ブラウザは Task 4
2. **419 の画面のあと、戻らずにほかの画面へ進み、あとで「戻る」で別の画面を出す** → ふつうの読み込みで鍵を消すので、古い鍵を当てない。Task 1 の normal load のテスト
3. **`sessionStorage` が使えない（例外・無い）** → 何もせず、画面の JS は止まらない。Task 1
4. **「ログイン状態を保持」の人** → 419 の画面の判定で自動でログインし直し鍵が変わる。メタはその後の鍵でなければ送り直しが 419 になる。Task 2 の remembered のテスト（判定をメタより前に行う）
5. **1 つの画面に `_token` が複数ある**（ヘッダーのログアウトのフォームと画面のフォーム）→ すべて差し替える。Task 1 のテストは 2 つ持つ

---

### Task 1: 戻った画面の鍵を差し替える部品

**Files:**
- Create: `resources/views/_partials/_fresh_csrf_token.blade.php`
- Modify: `resources/views/layouts/app.blade.php`（6 行目の `<meta name="csrf-token">` の直後・`@vite` の前に `@include('_partials._fresh_csrf_token')`）
- Modify: `resources/views/components/guest-layout.blade.php`（8 行目のメタの直後・`@vite` の前に同じ include）
- Test: `tests/Feature/FreshCsrfTokenTest.php`

**Interfaces:**
- Produces: グローバル関数 `window.rememberFreshCsrfToken()`（引数なし・戻り値なし。自分のページのメタの鍵を `sessionStorage['freshCsrfTokenAfter419']` に書く。例外は握りつぶす）。Task 2 の 419 の画面が呼ぶ

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/FreshCsrfTokenTest.php`（`Tests\TestCase`）。部品を `Blade::render("@include('_partials._fresh_csrf_token')")` で描き、`/<script>(.*?)<\/script>/s` で取り出した script を node の vm で動かす（`SubmitOnceTest::runInNode()` と同じ流儀。node が無ければ `markTestSkipped`）。偽の環境（ハーネスの先頭で作る）:

- `storage`: `getItem`・`setItem`・`removeItem` を持つ Map（`mode` で `'ok'`・`'throws'`〈3 つとも例外〉・`'missing'`〈`window.sessionStorage` が undefined〉）
- `nav.type`: `performance.getEntriesByType('navigation')` が返す `[{ type }]` の type
- `meta`: `{ content }`（`document.querySelector('meta[name="csrf-token"]')` が返す。`getAttribute('content')`・`setAttribute('content', v)` を持つ）
- `inputs`: `document.querySelectorAll('input[name="_token"]')` が返す配列。**script を走らせる時点では空**（部品は head で走り、本文はまだ無い）。
  `parse()` で `[{ value: 'old' }, { value: 'old' }]` にする（本文を読み終えた。ヘッダーのログアウトと画面のフォームの 2 つ）
- `fire(target, type, event)`: `window`・`document` の `addEventListener` で登録された関数を呼ぶ
- 結果は `{ meta: meta.content, inputs: inputs.map(i => i.value), stored: storage の値, error: 例外の文 }` を JSON で返す

テスト（鍵は `'fresh'`、もとの値は `'old'`）:

```php
public function test_a_back_navigation_swaps_in_the_token_left_by_the_419_page(): void
// 前提: storage に 'fresh'・nav.type = 'back_forward'。script を走らせた直後（本文はまだ無い）→ meta === 'fresh'
// parse()（inputs は 'old'）→ fire(document,'DOMContentLoaded') → inputs === ['fresh','fresh']
// inputs を 'old' に戻す（ブラウザがフォームの値を戻した）→ fire(window,'pageshow',{persisted:false}) → inputs === ['fresh','fresh']
// stored === null

#[DataProvider('normalLoads')]   // 'ふつうに開いた' => ['navigate'], '読み込み直した' => ['reload']
public function test_a_normal_load_throws_the_token_away(string $type): void
// storage に 'fresh'。走らせ、parse()・DOMContentLoaded・pageshow(false) → meta === 'old'・inputs === ['old','old']・stored === null

public function test_a_page_shown_from_the_back_forward_cache_swaps_in_the_token(): void
// storage は空・nav.type = 'navigate' で走らせ、parse()・DOMContentLoaded・pageshow(false)（最初の表示）。
// そのあと storage に 'fresh' を入れ（419 の画面が書いた）、
// fire(window,'pageshow',{persisted:true}) → meta === 'fresh'・inputs === ['fresh','fresh']・stored === null

public function test_a_page_shown_from_the_back_forward_cache_without_a_token_is_left_alone(): void
// storage は空。pageshow(true) → meta === 'old'・inputs === ['old','old']

#[DataProvider('unusableStorage')]  // '鍵が無い' => ['ok'], '例外を投げる' => ['throws'], '無い' => ['missing']
public function test_nothing_happens_without_a_token_or_storage(string $mode): void
// storage は空（'ok'）か使えない。nav.type = 'back_forward'。走らせ、parse()・DOMContentLoaded・pageshow(true)・rememberFreshCsrfToken()
// → error === null・meta === 'old'・inputs === ['old','old']

public function test_the_419_page_leaves_its_own_token(): void
// meta.content = 'fresh'。window.rememberFreshCsrfToken() → stored === 'fresh'（キーは 'freshCsrfTokenAfter419' をハーネスに直書きして読む）

public function test_the_partial_sits_right_after_the_token_meta_in_both_layouts(): void
// layouts/app.blade.php と components/guest-layout.blade.php の Blade ソース（{{-- --}} を落としてから）で:
// "@include('_partials._fresh_csrf_token')" がちょうど 1 つ、strpos(メタ) < strpos(include) < strpos("@vite(")
```

ハーネスの storage の初期値は `'freshCsrfTokenAfter419'` のキーに入れる（キーの取り違えをテストが捕まえる）。

- [ ] **Step 2: 落ちることを確かめる**

Run: `… vendor/bin/phpunit tests/Feature/FreshCsrfTokenTest.php`
Expected: FAIL（部品のファイルが無い＝ `View [_partials._fresh_csrf_token] not found`。構造のテストは include が 0）

- [ ] **Step 3: 部品を作り、2 つのレイアウトに読み込む**

Blade コメントに: 何のためか（419 の画面の「前の画面に戻る」で戻った画面は古い鍵のままで、送るとまた 419。設計メモの名前）・呼ぶ側（419 の画面）・⚠ head のメタの直後に置く（ページの script より前にメタを差し替える）・⚠ ふつうの読み込みで鍵を消す理由（Review Focus 2）・⚠ pageshow で差し替え直す理由（Review Focus 1）。script は次のとおり（決まりそのものなので本文を載せる）:

```html
<script>
(function () {
    var key = 'freshCsrfTokenAfter419';
    var fresh = null;
    function take() {
        try {
            var token = window.sessionStorage.getItem(key);
            window.sessionStorage.removeItem(key);
            return token;
        } catch (e) {
            return null;
        }
    }
    function navigationType() {
        var entries = window.performance && window.performance.getEntriesByType
            ? window.performance.getEntriesByType('navigation') : [];
        return entries.length > 0 ? entries[0].type : '';
    }
    function apply() {
        if (!fresh) return;
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.setAttribute('content', fresh);
        var inputs = document.querySelectorAll('input[name="_token"]');
        for (var i = 0; i < inputs.length; i++) inputs[i].value = fresh;
    }
    window.rememberFreshCsrfToken = function () {
        try {
            var meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) window.sessionStorage.setItem(key, meta.getAttribute('content'));
        } catch (e) {}
    };
    var token = take();
    if (navigationType() === 'back_forward') fresh = token;
    apply();
    document.addEventListener('DOMContentLoaded', apply);
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            var shown = take();
            if (shown) fresh = shown;
        }
        apply();
    });
})();
</script>
```

（`take()` が `sessionStorage` の取得そのもので例外を投げる場合〈`'missing'` で `window.sessionStorage` が undefined〉も try の中に入っている。`rememberFreshCsrfToken` も同じ）

- [ ] **Step 4: 通ることを確かめる**

Run: `… vendor/bin/phpunit tests/Feature/FreshCsrfTokenTest.php tests/Feature/LayoutScriptStackTest.php tests/Feature/LayoutStyleStackTest.php`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
git add resources/views/_partials/_fresh_csrf_token.blade.php resources/views/layouts/app.blade.php resources/views/components/guest-layout.blade.php tests/Feature/FreshCsrfTokenTest.php
git commit -m "feat: 419 の画面から戻った画面の送信の鍵を差し替える部品を足す" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 2: 419 の画面が鍵を残し、ログインしているかで案内を出し分ける

**Files:**
- Modify: `resources/views/errors/419.blade.php`
- Test: `tests/Feature/ExpiredSessionResponseTest.php`

**Interfaces:**
- Consumes: `window.rememberFreshCsrfToken()`（Task 1。guest レイアウトの head にある）

- [ ] **Step 1: 失敗するテストを書く（既存の手直しと 3 本の追加）**

- 定数 `PAGE_NEXT` を `PAGE_NEXT_SIGNED_IN`・`PAGE_NEXT_SIGNED_OUT`（Global Constraints の逐語）に替える
- `assertExpiredPage(TestResponse $response, bool $signedIn)`: 今の見出し・タブ名・理由・「前の画面に戻る」に加えて、
  - その場合の 2 段落目が `<p>` で全文あり、もう一方の文が無い
  - 2 つめのボタン: ログインしている `<a href="url('/')">トップページへ</a>`／していない `<a href="route('login')">ログイン画面へ</a>`
  - `<script>` の中に `window.rememberFreshCsrfToken()` の呼び出しがある（メッセージ `'鍵を残す script が無い'`）
- `private function tokenOnPage(string $html): string` … `<meta name="csrf-token" content="…">` の値
- `private function sendPasswordForm(array $form, string $token): TestResponse` … 描いた画面のフォーム（`parseForm`）の欄に `current_password` = `'password'`・`password` と `password_confirmation` = `'NewPass1234'`・`_token` = `$token` を入れて `call('POST', $form['action'], …)`

```php
// 既存: actingAs のまま古い鍵で送る → assertExpiredPage($response, true)
public function test_a_form_sent_with_an_expired_session_shows_a_japanese_page(): void

// ほかの画面でログアウトしてログインし直した（ログインしたまま鍵が変わった）
public function test_a_signed_in_user_can_send_the_same_form_again_with_the_token_from_the_419_page(): void
// actingAs → GET password.change → $form（鍵 T0）→ session()->regenerateToken() → enforceCsrfToken()
// sendPasswordForm($form, T0) → assertExpiredPage(…, true)・$fresh = tokenOnPage → assertSame(session()->token(), $fresh)・assertNotSame(T0, $fresh)
// sendPasswordForm($form, $fresh) → assertRedirect(route($user->homeRouteName()))・Hash::check('NewPass1234', $user->fresh()->password)

// ログインが切れた（セッションが切れた・ほかの画面でログアウトした）
public function test_a_signed_out_user_is_told_so_and_the_token_takes_the_form_to_the_login_page(): void
// actingAs → GET → $form → session()->invalidate()・$this->app['auth']->forgetGuards() → enforceCsrfToken()
// sendPasswordForm($form, T0) → assertExpiredPage(…, false)・assertGuest()
// sendPasswordForm($form, tokenOnPage(…)) → assertRedirect(route('login'))（419 でない）・パスワードは変わっていない

// 「ログイン状態を保持」の人（セッションが切れても次の要求で自動でログインし直す。そのとき鍵が変わる）
public function test_a_remembered_user_is_signed_back_in_and_the_page_carries_the_new_token(): void
// remember_token を持つ人で actingAs → GET → $form → invalidate・forgetGuards → enforceCsrfToken()
// withCookie(Auth::guard('web')->getRecallerName(), "{$user->id}|{$user->remember_token}|{$user->password}")
// sendPasswordForm($form, T0) → assertExpiredPage(…, true)・assertAuthenticatedAs($user)・assertSame(session()->token(), tokenOnPage(…))
// sendPasswordForm($form, そのメタの鍵) → assertRedirect(home)・新しいパスワード
```

⚠ 最後の 1 本は、判定（`auth()->check()`）をメタより後に行う形では、メタが自動でログインし直す前の鍵になって `assertSame` が落ちる（Review Focus 4）。

- [ ] **Step 2: 落ちることを確かめる**

Run: `… vendor/bin/phpunit tests/Feature/ExpiredSessionResponseTest.php`
Expected: FAIL（`鍵を残す script が無い`・2 段落目の文が無い・「ログイン画面へ」が無い）。ログインの 419 の 5 本（Bug #138）は緑のまま

- [ ] **Step 3: 419 の画面を直す**

- `<x-guest-layout …>` より前に `@php($signedIn = auth()->check())`（Blade コメントで: ⚠ メタを描くレイアウトより前に判定する理由＝「ログイン状態を保持」の人はここで鍵が変わる・コンポーネントは中身を先に描く）
- 2 段落目とボタンの 2 つめを `$signedIn` で出し分ける（Global Constraints の逐語。見た目のクラスは今のまま）
- カードの後ろ（スロットの最後）に `<script>if (window.rememberFreshCsrfToken) { window.rememberFreshCsrfToken(); }</script>`
- 先頭の Blade コメントに、戻った画面の鍵を差し替える仕組みと部品の名前・設計メモを 2〜3 行足す

- [ ] **Step 4: 通ることを確かめる**

Run: `… vendor/bin/phpunit tests/Feature/ExpiredSessionResponseTest.php tests/Feature/FreshCsrfTokenTest.php tests/Feature/Auth`
Expected: PASS

- [ ] **Step 5: コミット**

```bash
git add resources/views/errors/419.blade.php tests/Feature/ExpiredSessionResponseTest.php
git commit -m "fix: 419 の画面が新しい鍵を残し、ログインしているかで案内を出し分ける" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 3: 全件・コンパイル済みビューの lint・変異

**Files:**
- Create（gitignore 済み・コミットしない）: `.claude/route-coverage-tools/mutate/specs_back_fresh_token.py`・`run_bft.py`（main repo の `.claude/route-coverage-tools/mutate/run_l419.py` の写し。違いは `WT`・`SPECS`・`TESTS`・結果の名前 `results-back-fresh-token.*`）

- [ ] **Step 1: 全件**（裏で） → Expected: `OK (…)`・警告なし。本数を控える（13.x は 5251 本）
- [ ] **Step 2: コンパイル済みビューの lint** → `php artisan view:cache` のあと `storage/framework/views/*.php` を `php -l` → INVALID 0（324 本の見込み）→ `view:clear`
- [ ] **Step 3: 変異の表を流す前に書き、流す**（`TESTS` は `tests/Feature/FreshCsrfTokenTest.php`・`tests/Feature/ExpiredSessionResponseTest.php`・`tests/Feature/Auth`・`tests/Feature/LayoutScriptStackTest.php`・`tests/Feature/LayoutStyleStackTest.php`・`tests/Feature/Approval/Phase2/ApprovalJsonExpiredSessionTest.php`）

| id | 変異 | 落ちるはずのテスト（理由） |
|---|---|---|
| C0 | カナリア: 419 の画面の見出しを変える | 419 の画面を見る 4 本（`見出しが無い`） |
| P1 | 部品: `if (navigationType() === 'back_forward')` を外す（いつも当てる） | normal load の 2 データ |
| P2 | 部品: `removeItem` を消す | back・normal load・bfcache（stored が残る） |
| P3 | 部品: 走らせた直後の `apply();` を消す | back（メタがすぐに差し替わらない） |
| P4 | 部品: `DOMContentLoaded` の登録を消す | back（DOMContentLoaded のあと inputs が old） |
| P5 | 部品: pageshow の最後の `apply();` を `if (event.persisted) apply();` の中だけにする | back（戻された値を差し替え直さない） |
| P6 | 部品: persisted の枝を消す | bfcache |
| P7 | 部品: `take()` の try を外す | 使えない storage の 2 データ（例外） |
| P8 | 部品: `rememberFreshCsrfToken` のキーを変える | 419 の画面が残す鍵のテスト |
| L1 | app レイアウトの include を消す | 構造のテスト |
| L2 | guest レイアウトの include を `@vite` の後ろへ動かす | 構造のテスト |
| V1 | 419 の画面: 鍵を残す script を消す | 419 の画面を見る 4 本（`鍵を残す script が無い`） |
| V2 | 419 の画面: `$signedIn = true` に固定 | signed out のテスト |
| V3 | 419 の画面: `$signedIn = false` に固定 | 既存・signed in・remembered |
| V4 | 419 の画面: `auth()->check()` を `auth()->hasUser()` にする（自動でログインし直さない） | remembered |

- [ ] **Step 4: 結果を表に書く**（期待と違ったものは理由まで見て、表の誤りか穴かを分ける。穴ならテストを足して当て直す。Bug #44 の作法）。記録は Task 5 で計画の末尾へ

### Task 4: 実ブラウザで確かめる（使い捨ての環境・Playwright）

手順は main repo の `.claude/route-coverage-tools/browser/README.md`（demo.sqlite・`login-route.php.txt` を作業場所の routes/web.php の末尾へ一時的に足す〈コミットしない・終わったら外す〉・`public/build` のリンク・`CACHE_STORE=file SESSION_DRIVER=file` で `artisan serve` を裏で）。画面はテナントの顧客の登録（`/tenant/customers/create`。Bug #137 で使った）。

- [ ] **Step 1: ログインしたまま鍵が変わった場合** — タブ A で登録画面を開き名前を入れる → タブ B でログアウトしてログインし直す → タブ A で登録 → 419 の画面（ログインしている文・「トップページへ」）→ 「前の画面に戻る」→ 名前が残っているか・`_token` とメタが新しい鍵か・bfcache か HTTP のキャッシュか（`window` に目印を置いておき残るかで見る）を記録 → そのまま登録 → 一覧に出る
- [ ] **Step 2: ログインが切れた場合** — タブ A で開いて名前を入れる → cookie を消す → 登録 → 419 の画面（ログインが切れている文・「ログイン画面へ」）→ 戻る → 登録 → ログイン画面に移る（419 でない）
- [ ] **Step 3: 419 の画面を 375px・1440px で開き、横のはみ出し 0・コンソールのエラー 0**
- [ ] **Step 4: 後片付け** — サーバーを止める・routes/web.php を戻す（`git status --porcelain` が空）

### Task 5: 記録

**Files:**
- Modify: `docs/RULES.md`（Bug #137 の行の末尾に「⚠ 同じ形（2026-11-18・419 の画面から戻った画面）: …」を 1 段落。症状・仕組み・テスト・変異の数）
- Modify: `docs/BACKLOG.md`（「ログイン画面の 419 を行き止まりにしない（Bug #138）」の節の後ろ・「バックログ完了状況」の前に新しい節。完了状況の最後に 1 段落。あわせて Bug #138 と GET の Ajax の 2 つの節の「⚠ `origin/13.x` への push はしていない。」に「→ 2026-11-18 に push 済み（913e362f に含まれる）。」を足す）
- Modify: この計画の末尾（Task 3・4 の実測記録）

- [ ] **Step 1: 書く**（本番反映は未。反映したら節に書き足す）
- [ ] **Step 2: コミット**

```bash
git add docs/RULES.md docs/BACKLOG.md docs/superpowers/plans/2026-11-18-419-back-fresh-token.md
git commit -m "docs: 419 の画面から戻った画面を送り直せるようにした件を記録する" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## 実測記録（2026-11-18）

**Task 1・2**: 新しいテストは直す前に赤（Task 1: `View [_partials._fresh_csrf_token] not found`・include が 0 ／ Task 2: 4 本が `次にすることの文が無い`。ログインの 419 の 5 本は緑のまま）→ 直して緑。

**Task 3**:
- 1 回目の全件で決裁の 2 本（`ApprovalUserManagementTest`・`LedgerScreenTest` の `test_the_filter_form_is_reset_whenever_the_page_is_shown`）が赤 — ページ全体の `pageshow` を「ちょうど 1 つ」と数えていた（レイアウトの部品が全画面に 1 つ足す）。計画の漏れ。新しいトレイト `Tests\Concerns\RemovesFreshCsrfTokenScript` で部品の script を除いてから（ちょうど 1 回描かれていることも確かめて）数える形にした（`62ca7506`）。部品の側を走査から逃がす形は採らない
- 全件 **OK (5264 tests, 54768 assertions)**・警告なし ／ コンパイル済みビュー **324 本 / INVALID 0**
- 変異（V5 は台帳の Ruling で足した）: 16 通りすべて MATCH。1 回目は V2 だけ DIFF＝表の期待が狭かった（`$signedIn = true` に固定すると `auth()->check()` も消え、「ログイン状態を保持」の人がメタを描く前にログインし直さないので remembered も鍵のアサートで落ちる）→ 期待を直して V2 だけ流し直し MATCH
- 気づき: remembered のテストの `assertAuthenticatedAs` はテスト自身が自動のログインを起こすので、419 の画面がログインし直した証にはならない（鍵の `assertSame` が代わりに捕まえる＝V2・V4・V5）

| id | 結果 | 落ちたテスト（理由） |
|---|---|---|
| C0 | MATCH | 419 の画面を見る 4 本（見出しが無い） |
| P1 | MATCH | normal load の 2 データ |
| P2 | MATCH | back・normal load 2・bfcache（残した鍵を消していない） |
| P3 | MATCH | back（head で走った時点でメタが差し替わっていない） |
| P4 | MATCH | back（本文を読み終えた時点で…差し替わっていない） |
| P5 | MATCH | back（ブラウザがフォームの値を戻したあと…） |
| P6 | MATCH | bfcache（メタを差し替えていない） |
| P7 | MATCH | 使えない storage の 2 データ（例外で止まった） |
| P8 | MATCH | 419 の画面が鍵を残すテスト |
| L1 | MATCH | 構造（app レイアウトが部品を読み込んでいない） |
| L2 | MATCH | 構造（guest レイアウトの部品が @vite より前にない） |
| V1 | MATCH | 419 の画面を見る 4 本（鍵を残す script が無い） |
| V2 | MATCH（2 回目） | signed out（文）・remembered（鍵） |
| V3 | MATCH | 既存・signed in・remembered（文） |
| V4 | MATCH | remembered（文） |
| V5 | MATCH | signed out（文）・remembered（自動でログインし直したあとの鍵でない） |

**Task 4**（使い捨ての SQLite・`artisan serve`・Playwright の Chromium・テナントの顧客の登録）:
- ①タブ B でログアウトしてログインし直す → タブ A で登録 → 419（ログインしている文・「トップページへ」・残した鍵＝今の鍵）→「前の画面に戻る」は HTTP のキャッシュ（`window` の目印が消え、種類 `back_forward`。bfcache は使わなかった）・名前と種別は残り、`_token` 2 つとメタが新しい鍵 → そのまま登録で顧客 1 件だけ増えた
- ②cookie を消す → 419（ログインが切れている文・「ログイン画面へ」）→ 戻る → 登録でログイン画面（419 でない）
- ③419 の画面は 375・1440px で横スクロール 0・カードは枠の中・`pageerror` 0（コンソールは意図した 419 の読み込みの失敗だけ）
- 後片付け済み（routes/web.php を戻した・build のリンクと写真を消した）
