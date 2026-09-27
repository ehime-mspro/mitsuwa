# 顧客CSVインポートの二重送信を止める 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 顧客CSVインポート（`/admin/customers/import`・経営層のみ）の確定（「インポート実行」）を「確認画面 1 つにつき 1 回」だけ通し、同じ確認画面からの 2 回目で全員がもう一度入る事故と、チェックを勧める誤った案内をなくす。

**Architecture:** 設計書の案 A（サーバの 1 回限りの鍵 ＋ 画面の二度押し止め）。サーバは、プレビューで `OneTimeAction::issue()` の鍵を発行して確定のフォームの hidden `import_token` に載せ、確定では部署の入力チェックの直後・CSV を読み直す前に `OneTimeAction::claimFrom($request, 'import_token')` で使う（使えなければ取込の画面へ戻して案内を出す）。画面は Alpine の `onSubmit()` で 2 回目の送信を取り消し、送信中はボタンを `:disabled` にして Tailwind の `disabled:` で見た目を変え、ボタンの横の `role="status"` に「取り込んでいます…」を出す。`pageshow`（`window`）で印を下ろす。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade + Alpine.js 3 / Tailwind v4 / PHPUnit（worktree の `./vendor/bin/phpunit`）/ node（画面が描いた `<script>` を vm で動かす）/ Playwright MCP（ローカルの実ブラウザ）

**Spec:** `docs/superpowers/specs/2026-09-27-customer-import-double-submit-design.md`（2026-09-27 に利用者が ①〜④ を承認。以下「設計書」）

## Global Constraints

- 範囲は顧客CSVインポートだけ。ほかの取込（テナント・賃貸マンション・ZEAL 会員・工程表・周辺ビル）の二重送信は BACKLOG に記録するだけ（設計書 §1・§6）
- 本番の DB 変更・新しい PHP クラス・依存の変更は無い（`composer dump-autoload` は要らない。設計書 §4.1）
- `OneTimeAction::claimFrom(Request $request, string $field = 'guide_token'): bool` — 既定は `guide_token` のまま。**決裁の 5 か所の呼び出しは変えない**（設計書 §4.2）
- 顧客の取込の鍵の hidden の名前は `import_token`（顧客の画面に `guide_token` という名前を載せない。設計書 §4.2）
- 鍵は部署の入力チェックの直後・CSV を読み直す前に使う（少なくとも 0 件の歯止めより前。書き込みの直前に置かない。設計書 §4.3）
- 覚えておく時間は `config('approval.guide_token_ttl_hours')`（既定 12 時間。`config/approval.php:33`）を共用する（設計書 §4.2）
- 断るときの戻り先は取込の画面 `route('admin.customers.import')` に固定する（Bug #64）
- 案内の文言は設計書 §4.4 の文をそのまま使う:
  - 鍵が使えない: 「この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「顧客管理」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。」
  - 確定の時点で 0 件・重複候補はある: 「取り込める行がありません。プレビューのあとに、同じ人が登録された可能性があります。CSVをアップロードし直して、重複候補を確かめてください。」
  - 確定の時点で 0 件・重複候補も 0 件: 変えない（「インポート可能なデータがありません。CSVを修正してください。」）
- ボタンの文言「インポート実行（N件）」は変えない（ボタンの中身の先頭は「インポート実行」のまま。テストの `IMPORT_BUTTON` の前提）
- 送信中の見た目は Tailwind の `disabled:` のクラスで切り替える（`:style` にしない＝Top trap #5）。`cursor` は style に書かずクラスで書く
- 押せない間の理由はボタンの横の `role="status"` の文字で出し、`title` は使わない（Top trap #12）。`role="status"` の要素はボタンを包む要素の中にいつも置き、文字だけ変える
- `pageshow` は `window` で受け（`x-on:pageshow.window`）、`persisted` で絞らない（Bug #65）
- `<script>` の JS コメントに Blade のディレクティブ名（`@` で始まる語）や `<x-` を書かない（Bug #30）

## Review Focus

- **Alpine の起動前のダブルクリック・JS が動かないブラウザで、ほぼ同時に届く 2 回** — 片方だけが取り込み、もう片方は鍵の案内に着くのが期待。順に流す PHPUnit では排他性を原理的に測れない（テストのキャッシュは `array`）。既存の `LoginGuideTest::test_the_token_is_claimed_atomically`（`Cache::add` を使っていること）が守り、Task 3 の全件テストで緑を確かめる（本番の file ドライバの `add()` は排他ロックで書く。`OneTimeAction` の docblock）
- **Chrome の「戻る」で出る確認画面から押す** — 試作の実測で、Chromium は POST の確認画面を bfcache に入れず、手元の控えから描き直す（POST は送り直さない・Alpine は最初から＝ボタンは押せる・鍵は使用済みのまま）。押すと鍵の案内に着き件数は増えないのが期待。サーバ側は T1（同じフォームを 2 回送る）が固定し、画面は Task 5 の 5・6 で見る
- **bfcache に入れるブラウザで戻った確認画面** — 印が立ったまま戻るので、`pageshow` で下ろして押せるようにし、押すとサーバが断るのが期待。T6（`x-on:pageshow.window` が確定のフォームにある）と T7（`resetSubmit()` のあとはまた 1 回通す）が固定し、Task 5 の 7 で合成の `pageshow`（`persisted: true`）を送って見る
- **デプロイの前に開いた確認画面（鍵の hidden が無い）から押す** — 鍵の案内に着き、何も入らず、500 にならないのが期待。T4「鍵が無い」が固定する
- **狭い画面（375px）で押す** — 「取り込んでいます…」がまとまってボタンの下の行に出て、横にはみ出さないのが期待（試作で、文字の途中で「取」と「り込んでいます…」に割れるのを見つけた）。T6（`display: inline-block`）が固定し、Task 5 の 9 で見る

---

## Context

- 顧客CSVインポートの確定は 2026-09-27 に本番へ出た（docs/RULES.md Bug #66）。そのとき範囲外に残した「二重送信を 1 回だけにする仕組みと、2 回目に出る文言」を直す。利用者がこれから本番で実際の顧客 CSV を取り込むので、その操作の安全を先に固める（設計書 §1）
- 作業場所: worktree `/Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit`（ブランチ `customer-import-double-submit`）。この計画を書いた時点は `fdd7316c`（`13.x` = `origin/13.x` = `f61290be` の上に設計書だけ）
- worktree `approval-phase2a`（`df35b57c`）は別の会話「社内決裁申請」が作業中。触らない・借りない。2026-09-27 時点の差分は、この作業で触るファイル（`app/Support/OneTimeAction.php`・`tests/Feature/Approval/LoginGuideTest.php`・`config/approval.php`・顧客の取込の 3 ファイル・`docs/RULES.md`・`docs/BACKLOG.md`・`CLAUDE.md`）に触れていない（設計書 §2.4）
- 利用者の決まり（この計画を実行する人にも適用）: 応答は日本語・選択肢は本文の表で出す（AskUserQuestion のウィジェットは使わない）・推薦と実測を先に書く・質問は 1 回に 1 つ・`.env` / `.env.*` は読まない・`git stash` と `--no-verify` は使わない・push は指示があったときだけ・**本番への反映（Task 8）と本番の読み取りは、承認をもらってから**

## 試作で確かめたこと（2026-09-27）

scratchpad に `git archive fdd7316c` で作った写し（vendor は `cp -Rc` で実体コピー。Bug #50）にこの計画のコードを入れて測った。ブラウザは、写しを `php artisan serve` で動かし Playwright で見た（worktree は変えていない）。

| 見たこと | 結果 |
|---|---|
| T2 だけを直す前のコードで流す | `Tests: 21, Assertions: 273, Failures: 1.`（`2 回目の送信で、重複候補がもう一度入った` / `Failed asserting that 3 is identical to 2.`）＝設計書 §2.2 の読みは本当だった |
| 2 回目に着いた画面（使い捨ての探り）| チェックあり: 2 回目も「2件のインポートが完了しました。」が出て、山田 3 人・佐藤 2 人（全員がもう一度入る）。チェックなし: 「取り込む行がありません。重複候補を取り込むときは「重複候補もインポートする」にチェックを入れてください。」・件数は 2 のまま |
| Task 1 のテストを直す前のコードで流す | `Tests: 27, Assertions: 246, Failures: 22.`（22 本とも「確定のフォームに 1 回限りの鍵（import_token）が無い」）。部品の鍵の確認を外して流すと、8 本が振る舞いの理由で赤（T2 は `3 is identical to 2`・T1・T4 の 3・T5・期待を直した既存の 1 本は期待の案内が画面に無い・T3 は `Undefined array key "import_token"`）|
| Task 1 のコードで流す | `OK (27 tests, 388 assertions)`。鍵を共用する決裁の 4 本（`LoginGuideTest`・`ApprovalUserImportTest`・`ApprovalUserManagementTest`・`UserManagementApprovalTest`）は `OK (185 tests, 1308 assertions)` |
| Task 2 のテスト（T6・T7）を Task 1 のコードで流す | `Tests: 29, Assertions: 392, Failures: 2.`（T6: 確定のフォームの開始タグに `x-on:submit="onSubmit($event)"` が無い ／ T7: `TypeError: data.onSubmit is not a function`）|
| 最終形 | `OK (29 tests, 406 assertions)` ／ 全件 **`OK (2355 tests, 15346 assertions)`**（着手前 2346 / 15196 から +9 本）／ コンパイル済みビュー `views=274 invalid=0` |
| 変異 29 通り＋カナリア（関係するテスト 10 本に絞って先測り）| カナリアは 23 本が赤（写しのコードが読まれている）。28 通りは狙ったテストが狙った文言で赤。**K04（鍵を「行の検査の後・0 件の歯止めの前」へ動かす）だけ緑＝等価**（0 件の歯止めより前ならどこでも同じ）。Task 4 の表の「期待」はこの実測 |
| 実ブラウザ: ダブルクリック | 確定の POST は 1 回・「2件のインポートが完了しました。」・DB は 2 行 |
| 実ブラウザ: 確定の応答を 3 秒遅らせる | 押した直後（同じフレーム）にボタンが `disabled`・カーソル `not-allowed`・不透明度 `0.6`・「取り込んでいます…」。確定の POST は 1 回・「1件のインポートが完了しました。」 |
| 実ブラウザ: 完了のあと「戻る」| 確認画面が出る（`navigation.type` は `back_forward`）。サーバの記録に POST は無く、手元の控えから描き直していた（bfcache ではない・Alpine は最初から＝ボタンは押せる）。押すと鍵の案内（「この確認画面からは取り込めません（…）」）・DB は増えない |
| 実ブラウザ: 合成の `pageshow`（`persisted: true`）| 送信を止めて押すと印が立ち、`pageshow` で下り（押せる・文字が消える）、もう一度押すとまた立つ |
| 実ブラウザ: 幅 | 1440px・375px とも `main.scrollWidth === main.clientWidth`（1220/1220・375/375）。コンソールのエラー 0 件 |
| **見つけたこと: 375px** | 押したあと「取り込んでいます…」が「取」（ボタンの右）と「り込んでいます…」（次の行）に割れた（日本語は文字の途中で折り返せる）→ `display: inline-block` にすると、まとまって 1 行でボタンの下に移った（押す前の高さは 43px で変わらない）。設計書との違い 1 |
| 送信の中止（CDP の `Page.stopLoading`）| 確認画面に押せないボタンと「取り込んでいます…」が残る（範囲外に記録）|
| 道具の癖 | Playwright の `page.evaluate` は画面の移り変わりの途中は答えない（遅らせた 3 秒のあいだ待たされ、移ったあとで `Execution context was destroyed` になる）→ 画面の中に見張り（MutationObserver → localStorage）を仕込み、移ったあとで読む。`browser_run_code_unsafe` の中では `setTimeout`・`Buffer` が無いが、`page.waitForTimeout`・`page.route`・`locator.setInputFiles(<パス>)` は使える |
| CSS | 新しいクラスがビルドに入る（`.disabled\:opacity-60:disabled{opacity:.6}`・`.disabled\:cursor-not-allowed:disabled{cursor:not-allowed}`）。CSS の名前が変わる（本番の `app-wzJ6Tjji.css` → 試作では `app-D-wd4D2y.css`）|
| node | v24.11.1 |

## 設計書との違い（試作で決めたこと）

| # | 設計書 | この計画 | 理由 |
|---|---|---|---|
| 1 | §4.5 の送信中の文字の style `margin-left: 12px; font-size: 13px; color: #374151;` | 先頭に `display: inline-block;` を足す。T6 で固定する（変異 U14）| 375px で文字の途中で折り返した（試作の実ブラウザで実測）|
| 2 | §4.6「RULES の Bug #67」 | コードの注記には Bug 番号を書かず設計書を指す。番号は Task 7 で数え直してから RULES にだけ書く | 別の会話が先に RULES へ足すと番号がずれる |
| 3 | §2.2「チェック済みの 2 回目は案内すら出ずに全員がもう一度入る可能性が高い」 | 実測: 2 回目も「2件のインポートが完了しました。」が**出て**全員がもう一度入った（山田 3 人・佐藤 2 人）| 探りで確かめた（上の表）|
| 4 | §4.5「「戻る」で確認画面がそのまま戻ったとき（bfcache）」 | 実測: Chromium は POST の確認画面を bfcache に入れず、控えから描き直す（使用済みの鍵のまま・Alpine は最初から）。押すとサーバが断る。`pageshow` の印の下ろしは bfcache に入れるブラウザのためで、合成の `pageshow` で確かめる | 上の表 |
| 5 | §5.2 の候補「0 件の歯止めの後ろへ動かす」 | それに加えて「行の検査の後・0 件の歯止めの前へ動かす」（K04）が**等価**と実測。守る位置は「0 件の歯止めより前」で、T1 の「2 回目は 0 件の案内に着かない」が固定する。鍵を置く場所は設計書どおり（CSV を読み直す前）| 先測り |
| 6 | §5.1 T6 | `role="status"` の要素は開始タグを取り出してから中を見る（属性の順番に依存しない）。`display: inline-block` も見る | 属性を並べ替えただけで落ちるテストにしない |
| 7 | §5.1 T2 | アサートを「件数 → 案内」の順にする | 直す前の赤を「全員がもう一度入る」そのもので出す |
| 8 | §5.1「node の部品は `importCountsInNode()` を一般化して共用」 | `csvImportInNode(string $html, string $steps): array`（`result` に入れた値を返す）を足し、`importCountsInNode()` はその上に残す | 件数のテストはそのまま読める |

## 変えるファイル

| ファイル | 変更 |
|---|---|
| `app/Support/OneTimeAction.php` | `claimFrom()` に hidden の名前の引数（既定は `guide_token`）・docblock（Task 1）|
| `app/Http/Controllers/Admin/CustomerImportController.php` | プレビューで鍵を発行・確定では部署の入力チェックの直後に鍵を使う・0 件の案内と注記を直す・クラスの docblock（Task 1）|
| `resources/views/admin/customers/import.blade.php` | hidden の `import_token`（Task 1）・二度押し止め（Task 2）|
| `tests/Feature/Admin/CustomerImportTest.php` | T1〜T5・既存 1 本の期待・部品（Task 1）／ T6・T7・node の部品の一般化（Task 2）。20 → 29 本 |
| `docs/RULES.md`・`CLAUDE.md`・`docs/BACKLOG.md`・この計画 | 記録（Task 7・Task 8）|

ルート・DB・テンプレート（`downloadTemplate()`）・依存・`config/approval.php`・`LoginGuideTest` は変えない。

## 共通の決まり

- 作業は worktree で行う。⚠ `cd` を含まないコマンドは、ハーネスの cwd が main repo に戻っていることがあるので、`cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit &&` を付けるか `git -C <worktree>` にする
- 全件テスト（約 100 秒）: `cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`
- 1 本だけ: 上の末尾にファイルを足す（例 `./vendor/bin/phpunit tests/Feature/Admin/CustomerImportTest.php`）
- ⚠ `.env` は読まない（worktree に `.env` は無い・作らない。テストは `APP_KEY` の環境変数だけで起動する。渡し忘れると Feature テストが大量に `MissingAppKeyException` で落ちる）
- コミット: Conventional Commits・件名は日本語で 72 文字以内・句点なし・末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`（サブエージェントは自分の文脈の Co-Authored-By 行を使う）。HEREDOC で書く。`--no-verify` は使わない。コミットの後に `git status --porcelain` が空であることを見る
- サブエージェントに任せるときは 1 つの worktree に 1 つのエージェント。書いたらすぐコミットする（未コミットの編集が残っていると、変異の実行役が「作業ツリーが空でない」で止まる）
- zsh: 引用なしの `$VAR` は単語分割されない（複数のパスは配列で）・`grep` は ugrep（`-` で始まるパターンは `-e`）・`php -r "…"` の `$` はシェルが展開する（`'…'` で囲む）
- 編集は「置き換える前」がファイルにちょうど 1 か所あることを確かめてから当てる（Edit ツールは 1 か所でないと失敗する）

---

## Task 0: 前提の確認

**Files:** なし

- [ ] **Step 1: worktree とブランチ**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && git status --porcelain && git rev-parse --abbrev-ref HEAD && git log --oneline -1 && git merge-base --is-ancestor 13.x HEAD && echo 'ahead-of-13.x'
```

期待: `status` は何も出さない・`customer-import-double-submit`・この計画のコミット（`docs: 顧客CSVインポートの二重送信を止める実装計画を書く`）・`ahead-of-13.x`。

⚠ `ahead-of-13.x` が出ない（`13.x` がこの後に進んだ）ときは、作業を始める前に取り込む（リベースしない＝記録のコミット番号を保つ）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && git log --stat --format='%h %s' HEAD..13.x && git merge --no-edit -m "$(cat <<'EOF'
Merge branch '13.x' into customer-import-double-submit

作業中に 13.x へ入ったコミットを取り込む。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" 13.x
```

取り込んだコミットが、この計画で触るファイル（上の「変えるファイル」と `tests/Feature/Approval/LoginGuideTest.php`・`config/approval.php`）を変えていたら**止めて**、この計画の「置き換える前」がまだ合うかを確かめる。`composer.lock` が変わっていたら worktree で `composer install`（開発用の依存も入れる）。アプリのコードやテストが変わっていたら Step 2 の本数が変わるので測り直して記録する。

- [ ] **Step 2: この時点の全件テスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

期待: `OK (2346 tests, 15196 assertions)`

- [ ] **Step 3: node**

```bash
command -v node && node --version
```

期待: node のパスと版（v24 系）。無ければ T7 と件数の JS のテストは飛ばされる（`markTestSkipped`）ので、Task 5 のブラウザで必ず見る。

---

## Task 1: サーバの 1 回限りの鍵（T1〜T5）

**Files:**
- Modify: `app/Support/OneTimeAction.php`（docblock 3 か所・`claim()` の注記 1 行・`claimFrom()` の引数）
- Modify: `app/Http/Controllers/Admin/CustomerImportController.php`（`use`・クラスの docblock・鍵を使う・鍵を発行・0 件の案内）
- Modify: `resources/views/admin/customers/import.blade.php`（hidden の `import_token`・Blade コメント 1 行）
- Test: `tests/Feature/Admin/CustomerImportTest.php`

**Interfaces:**
- Consumes: 既存の `OneTimeAction::issue(): string`（40 文字）・private の `OneTimeAction::claim(string $token): bool`・テストの部品 `preview()`・`confirmForm()`・`submit()`・`tick()`・`existingBuyer()`・`person()`・`csv()`
- Produces:
  - `OneTimeAction::claimFrom(Request $request, string $field = 'guide_token'): bool`
  - プレビューの view データ `importToken`（`OneTimeAction::issue()` の文字列）と、確定のフォームの `<input type="hidden" name="import_token" value="{{ $importToken }}">`
  - テストの定数 `USED_TOKEN`・`REGISTERED_AFTER_PREVIEW`（このテストファイルの中だけで使う）
  - 確定の欄の Blade コメント「⚠ 確定は確認画面 1 つにつき 1 回だけ（…）」（Task 2 がその前に行を足す）

- [ ] **Step 1: T2 を先に書く**（Edit を 2 回）

1 回目 — 置き換える前:

```php
    private const NO_IMPORTABLE_ROWS = 'インポート可能なデータがありません。CSVを修正してください。';
```

置き換えた後:

```php
    private const NO_IMPORTABLE_ROWS = 'インポート可能なデータがありません。CSVを修正してください。';

    /** 同じ確認画面から 2 回目を送ったとき（1 回限りの鍵が使えないとき）の案内（設計書 2026-09-27 §4.4） */
    private const USED_TOKEN = 'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「顧客管理」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。';
```

2 回目 — 置き換える前:

```php
    // ================================================================
    // テンプレート
    // ================================================================
```

置き換えた後:

```php
    // ================================================================
    // 確定は確認画面 1 つにつき 1 回だけ（設計書 2026-09-27-customer-import-double-submit-design.md）
    // ================================================================

    public function test_sending_a_checked_confirmation_twice_imports_once(): void
    {
        $this->existingBuyer('山田', '太郎');
        $preview = $this->preview($this->csv([$this->person('山田', '太郎'), $this->person('佐藤', '花子')]));
        $form    = $this->tick($preview, $this->confirmForm($preview), 'include_duplicates');

        $this->submit($form)->assertSee('2件のインポートが完了しました。');
        $second = $this->submit($form);

        // 直す前: チェック済みなので、2 回目は 1 回目で入った人も重複候補として取り込み、「2件のインポートが完了しました。」が
        // もう一度出て全員がもう一度入った（2026-09-27 の試作で実測: 山田 3 人・佐藤 2 人）
        $this->assertSame(2, Buyer::where('last_name', '山田')->count(), '2 回目の送信で、重複候補がもう一度入った');
        $this->assertSame(1, Buyer::where('last_name', '佐藤')->count(), '2 回目の送信で、1 回目に入った人がもう一度入った');
        $second->assertSee(self::USED_TOKEN);
    }

    // ================================================================
    // テンプレート
    // ================================================================
```

- [ ] **Step 2: 流して赤を確かめる**（設計書 §2.2 の「チェック済みの 2 回目は全員がもう一度入る」を実測で確かめる。**ここが赤にならなければ止める**）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/CustomerImportTest.php 2>&1 | grep -E -e '^Tests:' -e 'もう一度入った' -e 'Failed asserting'
```

期待:

```text
2 回目の送信で、重複候補がもう一度入った
Failed asserting that 3 is identical to 2.
Tests: 21, Assertions: 273, Failures: 1.
```

「実測記録」の Task 1 に、この 3 行をそのまま書く。

- [ ] **Step 3: 残りのテストを書く**（Edit を 6 回）

1 回目（案内の定数）— 置き換える前:

```php
    /** 同じ確認画面から 2 回目を送ったとき（1 回限りの鍵が使えないとき）の案内（設計書 2026-09-27 §4.4） */
    private const USED_TOKEN = 'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「顧客管理」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。';
```

置き換えた後:

```php
    /** 同じ確認画面から 2 回目を送ったとき（1 回限りの鍵が使えないとき）の案内（設計書 2026-09-27 §4.4） */
    private const USED_TOKEN = 'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「顧客管理」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。';

    /** 確定の時点で取り込める行が 0 件・重複候補はあるときの案内（設計書 2026-09-27 §4.4。チェックは勧めない） */
    private const REGISTERED_AFTER_PREVIEW = '取り込める行がありません。プレビューのあとに、同じ人が登録された可能性があります。CSVをアップロードし直して、重複候補を確かめてください。';
```

2 回目（クラスの docblock）— 置き換える前:

```php
 * ⚠ セッションに触らない（`assertSessionHas*()` を呼ぶと、そのあと描いた画面からエラー表示が消える。Bug #49）。
 */
```

置き換えた後:

```php
 * ⚠ セッションに触らない（`assertSessionHas*()` を呼ぶと、そのあと描いた画面からエラー表示が消える。Bug #49）。
 * ⚠ 確定は確認画面 1 つにつき 1 回だけ（hidden の `import_token`。設計書 2026-09-27-customer-import-double-submit-design.md）。
 *   同じフォームを 2 回送るテストは、1 つのテストの中でキャッシュ（phpunit.xml の `CACHE_STORE=array`）が
 *   使った鍵を覚えていることに頼る。ほぼ同時の 2 回（本番の file ドライバの排他ロック）はここでは測れない
 *   （`LoginGuideTest::test_the_token_is_claimed_atomically` が `Cache::add` を使っていることを構造で守る）。
 */
```

3 回目（`confirmForm()` で鍵を見る）— 置き換える前:

```php
        // 確定の印が抜けると、押してもプレビューが描き直されるだけになる（Bug #54 ②）
        $this->assertSame('1', $form['fields']['confirmed'] ?? null, '確定のフォームに confirmed が無い');

        return $form;
```

置き換えた後:

```php
        // 確定の印が抜けると、押してもプレビューが描き直されるだけになる（Bug #54 ②）
        $this->assertSame('1', $form['fields']['confirmed'] ?? null, '確定のフォームに confirmed が無い');
        // 確定は確認画面 1 つにつき 1 回だけ。鍵が描かれていないと、どの確定も断られる（設計書 2026-09-27 §4.3）
        $this->assertNotSame('', $form['fields']['import_token'] ?? '', '確定のフォームに 1 回限りの鍵（import_token）が無い');

        return $form;
```

4 回目（既存のテストの期待を新しい案内に）— 置き換える前:

```php
        // 画面はボタンを隠すが、隠れたフォームがそのまま送られてきても（二重送信・細工した送信と同じ形）、
        // サーバが 0 件の確定を断る
        $landed = $this->submit($this->confirmForm($preview));

        $landed->assertSee('取り込む行がありません。重複候補を取り込むときは「重複候補もインポートする」にチェックを入れてください。');
```

置き換えた後:

```php
        // 画面はボタンを隠すが、隠れたフォームがそのまま送られてきても（細工した送信と同じ形）、サーバが 0 件の確定を断る。
        // 同じ確認画面からの 2 回目は、この歯止めより先に 1 回限りの鍵が断る（test_sending_the_same_confirmation_twice_imports_once）
        $landed = $this->submit($this->confirmForm($preview));

        $landed->assertSee(self::REGISTERED_AFTER_PREVIEW);
```

5 回目（T1 を T2 の前に）— 置き換える前:

```php
    public function test_sending_a_checked_confirmation_twice_imports_once(): void
```

置き換えた後:

```php
    public function test_sending_the_same_confirmation_twice_imports_once(): void
    {
        $form = $this->confirmForm($this->preview($this->csv([$this->person('山田', '太郎'), $this->person('佐藤', '花子')])));

        $this->submit($form)->assertSee('2件のインポートが完了しました。');
        $second = $this->submit($form);

        $second->assertSee(self::USED_TOKEN);
        // 鍵をほかの検査より先に使っている（0 件の歯止めより後ろに置くと、2 回目は 1 回目で入った人を重複候補と数えて
        // 0 件の案内に着く。設計書 2026-09-27 §4.3）
        $second->assertDontSee(self::REGISTERED_AFTER_PREVIEW);
        $second->assertDontSee('件のインポートが完了しました。');
        $this->assertSame(2, Buyer::count());
    }

    public function test_sending_a_checked_confirmation_twice_imports_once(): void
```

6 回目（T3・T4・T5 を「テンプレート」の節の前に）— 置き換える前:

```php
    // ================================================================
    // テンプレート
    // ================================================================
```

置き換えた後:

```php
    public function test_each_preview_issues_its_own_token(): void
    {
        $first  = $this->confirmForm($this->preview($this->csv([$this->person('山田', '太郎')])));
        $second = $this->confirmForm($this->preview($this->csv([$this->person('佐藤', '花子')])));

        $this->assertNotSame($first['fields']['import_token'], $second['fields']['import_token'], 'プレビューごとに鍵が変わっていない');

        $this->submit($first)->assertSee('1件のインポートが完了しました。');
        // 別のプレビューの確定は、1 つ目を使ったあとでも取り込める
        $this->submit($second)->assertSee('1件のインポートが完了しました。');
        $this->assertSame(['山田', '佐藤'], Buyer::orderBy('id')->pluck('last_name')->all());
    }

    /** @return array<string, array{0: string|list<string>|null}> [import_token に入れる値（null なら送らない）] */
    public static function unusableTokens(): array
    {
        return [
            '鍵が無い' => [null],
            '鍵が空'   => [''],
            '鍵が配列' => [['a', 'b']],
        ];
    }

    #[DataProvider('unusableTokens')]
    public function test_a_confirmation_without_a_usable_token_imports_nothing(string|array|null $token): void
    {
        $form = $this->confirmForm($this->preview($this->csv([$this->person('山田', '太郎')])));

        if ($token === null) {
            unset($form['fields']['import_token']);
        } else {
            $form['fields']['import_token'] = $token;
        }

        // 500 にならない（配列の鍵は OneTimeAction::claimFrom() が is_string で断る。submit() が 302 と戻り先を見る）
        $this->submit($form)->assertSee(self::USED_TOKEN);
        $this->assertSame(0, Buyer::count());
    }

    public function test_someone_registered_after_the_preview_turns_the_confirmation_back(): void
    {
        $form = $this->confirmForm($this->preview($this->csv([$this->person('山田', '太郎')])));

        // プレビューのあとに、別の画面（別のタブ・ほかの人）で同じ人が登録された
        $this->existingBuyer('山田', '太郎');

        $landed = $this->submit($form);

        $landed->assertSee(self::REGISTERED_AFTER_PREVIEW);
        // この場面でチェックを入れると、先に登録された人がもう一度入るので勧めない（設計書 2026-09-27 §4.4）
        $landed->assertDontSee('チェックを入れてください');
        $this->assertSame(1, Buyer::count());
    }

    // ================================================================
    // テンプレート
    // ================================================================
```

- [ ] **Step 4: 流して赤を確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && OUT=$(APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/CustomerImportTest.php 2>&1); printf '%s\n' "$OUT" | grep -E '^Tests:'; printf '%s\n' "$OUT" | grep -A1 -E '^[0-9]+\) ' | grep -vE '^[0-9]+\) |^--' | sort | uniq -c
```

期待: `Tests: 27, Assertions: 246, Failures: 22.` と `22 確定のフォームに 1 回限りの鍵（import_token）が無い`（確定のフォームを分解するテストが全部、鍵が無いことで落ちる。落ちないのはフォームを分解しない 5 本）

- [ ] **Step 5: `OneTimeAction` に hidden の名前の引数を足す**（`app/Support/OneTimeAction.php`。Edit を 5 回）

1 回目 — 置き換える前:

```php
 * ログイン案内を出す POST（新規登録・再発行・CSV の確定）に鍵を入れておき、処理した鍵を覚えておく。
 * ブラウザの「フォームを再送信しますか」で続けてしまうと、**印刷済みの案内がこっそり無効になる**ため。
```

置き換えた後:

```php
 * ログイン案内を出す POST（新規登録・再発行・CSV の確定）に鍵を入れておき、処理した鍵を覚えておく。
 * ブラウザの「フォームを再送信しますか」で続けてしまうと、**印刷済みの案内がこっそり無効になる**ため。
 * 顧客 CSV の取込の確定（`Admin\CustomerImportController`）も同じ鍵で 1 回だけにする
 * （2 回目を通すと、チェック済みなら全員がもう一度入る。設計書 2026-09-27-customer-import-double-submit-design.md）。
```

2 回目 — 置き換える前:

```php
        // ⚠ `max(1, …)` が要る。`Repository::add()` は秒数が 0 以下だと**キーの存在も見ずに**
```

置き換えた後:

```php
        // 覚えておく時間は決裁の設定を顧客の取込でも共用する（既定 12 時間。二度押しと「戻る」には足りる）。
        // ⚠ `max(1, …)` が要る。`Repository::add()` は秒数が 0 以下だと**キーの存在も見ずに**
```

3 回目 — 置き換える前:

```php
     * リクエストの hidden `guide_token` を受け取って鍵を使う。**入口はここを通す。**
```

置き換えた後:

```php
     * リクエストの hidden（既定は `guide_token`。顧客の取込は `import_token`）を受け取って鍵を使う。**入口はここを通す。**
```

4 回目 — 置き換える前:

```php
     * ⚠ `guide_token` は配列で送られることがある（`guide_token[]=x` のような**手組みの送信**。
     *   すべての画面が `name="guide_token"` の hidden を**単一の値でしか描画しない**ので、
     *   通常のブラウザ操作では配列にならない——実測 5 箇所とも `name="guide_token"` の単数形）。
```

置き換えた後:

```php
     * ⚠ 鍵の hidden は配列で送られることがある（`guide_token[]=x` のような**手組みの送信**。
     *   すべての画面が鍵の hidden を**単一の値でしか描画しない**ので、通常のブラウザ操作では
     *   配列にならない——実測 `name="guide_token"` 5 箇所・`name="import_token"` 1 箇所とも単数形）。
```

5 回目 — 置き換える前:

```php
    public static function claimFrom(Request $request): bool
    {
        $token = $request->input('guide_token');
```

置き換えた後:

```php
    public static function claimFrom(Request $request, string $field = 'guide_token'): bool
    {
        $token = $request->input($field);
```

- [ ] **Step 6: コントローラ**（`app/Http/Controllers/Admin/CustomerImportController.php`。Edit を 5 回）

1 回目（`use`）— 置き換える前:

```php
use App\Support\CsvImportReader;
```

置き換えた後:

```php
use App\Support\CsvImportReader;
use App\Support\OneTimeAction;
```

2 回目（クラスの docblock）— 置き換える前:

```php
 * ⚠ 断るときの戻り先は取込の画面に固定する（`back()` や入力チェックの既定の戻り先＝リファラーを使わない）。
 *   今は確認画面の URL が取込の画面と同じなので 405 にはならないが、ほかの取込と同じ形にそろえる（Bug #64）。
 */
```

置き換えた後:

```php
 * ⚠ 断るときの戻り先は取込の画面に固定する（`back()` や入力チェックの既定の戻り先＝リファラーを使わない）。
 *   今は確認画面の URL が取込の画面と同じなので 405 にはならないが、ほかの取込と同じ形にそろえる（Bug #64）。
 * ⚠ 確定は確認画面 1 つにつき 1 回だけ（hidden の `import_token`・`OneTimeAction`。
 *   設計書 2026-09-27-customer-import-double-submit-design.md §4.3）。
 *   鍵は部署の検査の直後・CSV を読み直す前に使う。書き込みの直前（決裁の取込と同じ位置）に置くと、
 *   1 回目のあとに届いた 2 回目は、1 回目で入った人を重複候補と数えて 0 件の歯止めに着き、
 *   チェックを勧める誤った案内が出る（入れると全員がもう一度入る）。
 */
```

3 回目（鍵を使う）— 置き換える前:

```php
        $skipDupes  = !$request->boolean('include_duplicates');

        $content = $this->loadCsv($request, $confirmed);
```

置き換えた後:

```php
        $skipDupes  = !$request->boolean('include_duplicates');

        // 確定は確認画面 1 つにつき 1 回だけ（クラスの docblock）。⚠ ほかの検査より先に使う
        if ($confirmed && ! OneTimeAction::claimFrom($request, 'import_token')) {
            return redirect()->route('admin.customers.import')
                ->with('error', 'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「顧客管理」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。');
        }

        $content = $this->loadCsv($request, $confirmed);
```

4 回目（鍵を発行）— 置き換える前:

```php
                'csvData'    => base64_encode($content),
            ]);
```

置き換えた後:

```php
                'csvData'    => base64_encode($content),
                // 確定を 1 回だけ通す鍵（クラスの docblock）
                'importToken' => OneTimeAction::issue(),
            ]);
```

5 回目（0 件の案内）— 置き換える前:

```php
        // 画面はプレビューのあと DB が変わっていなければ 0 件の確定を出さない（V＝0 ならボタンを隠す）。
        // ここに来るのは、二重送信・別のタブで先に確定・細工した送信など、プレビューのあと DB が変わったときだけ。
        // ⚠ 二重送信は 1 回目で取り込んだ行が既存になるので、2 回目はこの歯止めで
        //   「重複候補もインポートする」にチェックを入れてください、に着く（1 回だけ送らせる仕組みは範囲外。BACKLOG へ）
        if ($validRows === []) {
            $message = $dupeRows !== []
                ? '取り込む行がありません。重複候補を取り込むときは「重複候補もインポートする」にチェックを入れてください。'
                : 'インポート可能なデータがありません。CSVを修正してください。';
```

置き換えた後:

```php
        // 画面はプレビューのあと DB が変わっていなければ 0 件の確定を出さない（V＝0 ならボタンを隠す）。
        // ここに来るのは、プレビューのあとに同じ人が登録されたとき（別のタブ・ほかの人）と、細工した送信だけ
        // （同じ確認画面からの 2 回目は、上の鍵が先に断る）。
        // ⚠ 重複候補があってもチェックは勧めない（入れると、先に登録された人がもう一度入る）
        if ($validRows === []) {
            $message = $dupeRows !== []
                ? '取り込める行がありません。プレビューのあとに、同じ人が登録された可能性があります。CSVをアップロードし直して、重複候補を確かめてください。'
                : 'インポート可能なデータがありません。CSVを修正してください。';
```

- [ ] **Step 7: 確定のフォームに鍵の hidden**（`resources/views/admin/customers/import.blade.php`。Edit を 2 回）

1 回目（STEP 4 の Blade コメント）— 置き換える前:

```blade
             ⚠ サーバにも同じ歯止めがある（0 件の確定は断る。CustomerImportController::execute()） --}}
```

置き換えた後:

```blade
             ⚠ 確定は確認画面 1 つにつき 1 回だけ（hidden の import_token。JS が動かないときも、サーバが 2 回目を断る）
             ⚠ サーバにも同じ歯止めがある（0 件の確定は断る。CustomerImportController::execute()） --}}
```

2 回目 — 置き換える前:

```blade
                        <input type="hidden" name="csv_data" value="{{ $csvData }}">
```

置き換えた後:

```blade
                        <input type="hidden" name="csv_data" value="{{ $csvData }}">
                        <input type="hidden" name="import_token" value="{{ $importToken }}">
```

- [ ] **Step 8: 流して緑を確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/CustomerImportTest.php 2>&1 | tail -1
```

期待: `OK (27 tests, 388 assertions)`

- [ ] **Step 9: 鍵を共用する決裁のテストも流す**（既定の `guide_token` が変わっていないこと）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'LoginGuideTest|ApprovalUserImportTest|ApprovalUserManagementTest|UserManagementApprovalTest' 2>&1 | tail -1
```

期待: `OK (185 tests, 1308 assertions)`（決裁の `claimFrom($request)` 5 か所は変えていない。`LoginGuideTest` の `claimFrom(` の数の下限は 5 で、6 か所目を足しても通る）

- [ ] **Step 10: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && git add app/Support/OneTimeAction.php app/Http/Controllers/Admin/CustomerImportController.php resources/views/admin/customers/import.blade.php tests/Feature/Admin/CustomerImportTest.php && git commit -m "$(cat <<'EOF'
fix: 顧客CSVインポートの確定を確認画面 1 つにつき 1 回にする

プレビューで 1 回限りの鍵を発行して確定のフォームの hidden import_token に載せ、
確定は部署の入力チェックの直後に OneTimeAction::claimFrom() で使う。同じ確認画面からの
2 回目は、チェック済みなら全員がもう一度入り、チェックなしならチェックを勧める誤った
案内に着いていた。claimFrom() は hidden の名前を引数で受ける（既定は guide_token）。
0 件の案内はチェックを勧めない文に変える。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 2: 画面の二度押し止め（T6・T7）

**Files:**
- Modify: `resources/views/admin/customers/import.blade.php`（確定のフォーム・ボタン・送信中の文字・Blade コメント・`csvImport()`）
- Test: `tests/Feature/Admin/CustomerImportTest.php`（node の部品の一般化・T6・T7）

**Interfaces:**
- Consumes: Task 1 の確定のフォーム（hidden の `import_token`）・Blade コメントの「⚠ 確定は確認画面 1 つにつき 1 回だけ（…）」・テストの部品 `confirmFormHtml()`・`buttonWrapper()`・`htmlAttr()`（`ParsesForms`）
- Produces:
  - `csvImport()` の `submitting: false`・`onSubmit(event)`（1 回目は通して `submitting = true`、2 回目以降は `event.preventDefault()`）・`resetSubmit()`（`submitting = false`）
  - テストの部品 `csvImportInNode(string $html, string $steps): array`（`$steps` の JS は `data`＝`csvImport()` の戻り値を使い、返したい値を `result` に入れる）

- [ ] **Step 1: node の部品を一般化する**（Edit を 1 回。振る舞いは変えない）

置き換える前:

```php
    /**
     * 確定の欄の件数（`csvImport()` の `importCount()`）を node の vm で実際に動かす。
     *
     * ⚠ PHP のテストからブラウザの JavaScript は動かせないので、画面が描いた `<script>` をそのまま node で読み込む
     *   （AreaBuildingMapTabTest・DatePickerMonthAgoTest と同じ流儀。Bug #47 の「振る舞いの正本は実駆動」）。
     *   文脈には何も渡さない（ブラウザより寛容にしない）。チェックとの結びつき（x-model）とボタンの出し分け（x-show）は
     *   構造で見る（test_a_preview_of_only_duplicates_hides_the_button_until_the_check）
     *
     * @return list<int>  [チェックなしの件数, チェックありの件数]
     */
    private function importCountsInNode(string $html): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので確定の欄の件数の実駆動を飛ばす');
        }

        $found = preg_match('/<script>\s*(function csvImport\(\) \{.*?)<\/script>/su', $html, $m);
        $this->assertSame(1, $found, 'csvImport() の <script> が無い');

        $harness = <<<'JS'
            const fs = require('fs');
            const vm = require('vm');
            const context = vm.createContext({});
            vm.runInContext(fs.readFileSync(process.argv[1], 'utf8'), context, { filename: 'admin/customers/import.blade.php' });
            const data = vm.runInContext('csvImport()', context);
            const counts = [data.importCount()];
            data.includeDupes = true;
            counts.push(data.importCount());
            process.stdout.write(JSON.stringify(counts));
            JS;

        $file = tempnam(sys_get_temp_dir(), 'csv-import-count-');
        try {
            file_put_contents($file, $m[1]);
            $output = shell_exec(sprintf('%s -e %s %s 2>&1', escapeshellarg($node), escapeshellarg($harness), escapeshellarg($file)));
        } finally {
            unlink($file);
        }

        $counts = json_decode((string) $output, true);
        $this->assertIsArray($counts, "node で csvImport() を動かせなかった:\n" . $output);

        return $counts;
    }
```

置き換えた後:

```php
    /**
     * 画面が描いた `csvImport()` を node の vm で作り、$steps（JavaScript）を走らせて、`result` に入れた値を返す。
     *
     * ⚠ PHP のテストからブラウザの JavaScript は動かせないので、画面が描いた `<script>` をそのまま node で読み込む
     *   （AreaBuildingMapTabTest・DatePickerMonthAgoTest と同じ流儀。Bug #47 の「振る舞いの正本は実駆動」）。
     *   文脈には何も渡さない（ブラウザより寛容にしない）。Alpine との結びつき（x-model・x-show・x-on・:disabled）は
     *   構造で見る（test_a_preview_of_only_duplicates_hides_the_button_until_the_check・
     *   test_the_confirmation_form_guards_against_a_second_press）
     *
     * @param  string  $steps  `data`（csvImport() が返したもの）を使い、返したい値を `result` に入れる
     */
    private function csvImportInNode(string $html, string $steps): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので確定の欄の JavaScript の実駆動を飛ばす');
        }

        $found = preg_match('/<script>\s*(function csvImport\(\) \{.*?)<\/script>/su', $html, $m);
        $this->assertSame(1, $found, 'csvImport() の <script> が無い');

        $prelude = <<<'JS'
            const fs = require('fs');
            const vm = require('vm');
            const context = vm.createContext({});
            vm.runInContext(fs.readFileSync(process.argv[1], 'utf8'), context, { filename: 'admin/customers/import.blade.php' });
            const data = vm.runInContext('csvImport()', context);
            let result;
            JS;
        $harness = $prelude . "\n" . $steps . "\nprocess.stdout.write(JSON.stringify(result));\n";

        $file = tempnam(sys_get_temp_dir(), 'csv-import-js-');
        try {
            file_put_contents($file, $m[1]);
            $output = shell_exec(sprintf('%s -e %s %s 2>&1', escapeshellarg($node), escapeshellarg($harness), escapeshellarg($file)));
        } finally {
            unlink($file);
        }

        $result = json_decode((string) $output, true);
        $this->assertIsArray($result, "node で csvImport() を動かせなかった:\n" . $output);

        return $result;
    }

    /**
     * 確定の欄の件数（`csvImport()` の `importCount()`）。
     *
     * @return list<int>  [チェックなしの件数, チェックありの件数]
     */
    private function importCountsInNode(string $html): array
    {
        return $this->csvImportInNode($html, <<<'JS'
            result = [data.importCount()];
            data.includeDupes = true;
            result.push(data.importCount());
            JS);
    }
```

流して、件数のテストが今までどおり緑であることを見る:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/CustomerImportTest.php 2>&1 | tail -1
```

期待: `OK (27 tests, 388 assertions)`

- [ ] **Step 2: T6・T7 を書く**（Edit を 1 回。「テンプレート」の節の前＝T5 の後ろ）

置き換える前:

```php
    // ================================================================
    // テンプレート
    // ================================================================
```

置き換えた後:

```php
    public function test_the_confirmation_form_guards_against_a_second_press(): void
    {
        $html = $this->preview($this->csv([$this->person('山田', '太郎')]))->getContent();

        // 送信の印は確定のフォームそのものに付ける（ページのどこかに在るだけでは足りない。Bug #47）
        $form    = $this->confirmFormHtml($html);
        $formTag = substr($form, 0, strpos($form, '>') + 1);
        $this->assertStringContainsString('x-on:submit="onSubmit($event)"', $formTag);
        // 「戻る」で戻った確認画面の印を下ろす。pageshow は window にしか届かない（Bug #65）
        $this->assertStringContainsString('x-on:pageshow.window="resetSubmit()"', $formTag);

        // 送信中はボタンを押せなくする。見た目は Tailwind の disabled: で切り替える
        $this->assertSame(1, preg_match('/<button\b[^>]*>(?=\s*インポート実行)/u', $html, $m), '「インポート実行」ボタンが無い');
        $button = $m[0];
        $this->assertStringContainsString(':disabled="submitting"', $button);
        $classes = preg_split('/\s+/', (string) $this->htmlAttr($button, 'class'));
        foreach (['cursor-pointer', 'disabled:cursor-not-allowed', 'disabled:opacity-60'] as $class) {
            $this->assertContains($class, $classes, "「インポート実行」ボタンに {$class} が無い");
        }
        // style に cursor があると disabled:cursor-not-allowed に勝つ（style はクラスより強い）
        $this->assertStringNotContainsString('cursor', (string) $this->htmlAttr($button, 'style'));

        // 押したことを知らせる文字は、ボタンを包む要素の中にいつも置いて、文字だけ変える（設計書 2026-09-27 §4.5）
        $wrapper = $this->buttonWrapper($html);
        $start   = strpos($html, $wrapper) + strlen($wrapper);
        $inside  = substr($html, $start, strpos($html, '</div>', $start) - $start);
        $this->assertSame(1, preg_match('/<span\b[^>]*\brole="status"[^>]*>/u', $inside, $status), 'ボタンを包む要素の中に role="status" が無い');
        $this->assertStringContainsString('x-text="submitting ? \'取り込んでいます…\' : \'\'"', $status[0]);
        // 幅が狭いとき（375px）に文字の途中で折り返さず、まとまって次の行へ移る
        $this->assertStringContainsString('display: inline-block', (string) $this->htmlAttr($status[0], 'style'));
    }

    public function test_a_second_submit_is_cancelled_until_the_page_is_shown_again(): void
    {
        $html = $this->preview($this->csv([$this->person('山田', '太郎')]))->getContent();

        // 1 回目は通して印を立て、2 回目は取り消す。「戻る」で戻った画面（pageshow → resetSubmit()）では、また 1 回通す
        $this->assertSame(
            ['before' => false, 'first' => false, 'afterFirst' => true, 'second' => true, 'afterShown' => false, 'third' => false],
            $this->csvImportInNode($html, <<<'JS'
                const press = () => {
                    const event = { cancelled: false, preventDefault() { this.cancelled = true; } };
                    data.onSubmit(event);
                    return event.cancelled;
                };
                result = { before: data.submitting };
                result.first = press();
                result.afterFirst = data.submitting;
                result.second = press();
                data.resetSubmit();
                result.afterShown = data.submitting;
                result.third = press();
                JS)
        );
    }

    // ================================================================
    // テンプレート
    // ================================================================
```

- [ ] **Step 3: 流して赤を確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/CustomerImportTest.php 2>&1 | grep -E -e '^Tests:' -e '^[0-9]+\) ' -e 'Failed asserting' -e 'TypeError'
```

期待: `Tests: 29, Assertions: 392, Failures: 2.` — `test_the_confirmation_form_guards_against_a_second_press`（`Failed asserting that '<form method="POST" action="http://localhost/admin/customers/import">' … contains "x-on:submit="onSubmit($event)""`）と `test_a_second_submit_is_cancelled_until_the_page_is_shown_again`（`TypeError: data.onSubmit is not a function` ／ `Failed asserting that null is of type array.`）

- [ ] **Step 4: 画面を直す**（`resources/views/admin/customers/import.blade.php`。Edit を 4 回）

1 回目（STEP 4 の Blade コメント）— 置き換える前:

```blade
             ⚠ 押せないボタンは disabled にせず隠す（disabled の要素の title はホバーで出ない。Top trap #12）
             ⚠ x-show はボタンを包む要素に付け、ボタン自身の style に触らない（Top trap #5・Bug #32）。
               最初の状態はサーバが描く（V＝0 なら display: none）ので、開いた直後に一瞬出て消えない
```

置き換えた後:

```blade
             ⚠ 押せないボタンは disabled にせず隠す（disabled の要素の title はホバーで出ない。Top trap #12）。
               ただし送信中（1 回目を押したあと）だけは disabled にする（二度押し止め。理由はボタンの横の
               role="status" の文字で出し、title は使わない。設計書 2026-09-27-customer-import-double-submit-design.md §4.5）
             ⚠ x-show はボタンを包む要素に付け、ボタン自身の style に触らない（Top trap #5・Bug #32）。
               最初の状態はサーバが描く（V＝0 なら display: none）ので、開いた直後に一瞬出て消えない
             ⚠ 送信中の見た目は Tailwind の disabled: で切り替える（:style にすると Top trap #5 に触れる）。
               cursor を style に書くとクラスに勝つので、クラスで書く。送信中の文字は inline-block（375px で
               文字の途中で折り返さず、まとまってボタンの下の行へ移る。2026-09-27 の試作で実測）
```

2 回目（確定のフォームの開始タグ）— 置き換える前:

```blade
                    <form method="POST" action="{{ route('admin.customers.import.execute') }}">
                        @csrf
                        <input type="hidden" name="department" value="{{ $department }}">
```

置き換えた後:

```blade
                    <form method="POST" action="{{ route('admin.customers.import.execute') }}"
                          x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                        @csrf
                        <input type="hidden" name="department" value="{{ $department }}">
```

⚠ 開始タグの属性に `>` を書かない（テストの `parseForm()` は開始タグを最初の `>` で切る）。

3 回目（ボタンと送信中の文字）— 置き換える前:

```blade
                            <button type="submit"
                                    style="background: #059669; color: #fff; padding: 10px 28px; border-radius: 6px; font-size: 15px; font-weight: 600; border: none; cursor: pointer;">
                                インポート実行（<span x-text="importCount()">{{ $validCount }}</span>件）
                            </button>
                        </div>
```

置き換えた後:

```blade
                            <button type="submit" :disabled="submitting"
                                    class="cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"
                                    style="background: #059669; color: #fff; padding: 10px 28px; border-radius: 6px; font-size: 15px; font-weight: 600; border: none;">
                                インポート実行（<span x-text="importCount()">{{ $validCount }}</span>件）
                            </button>
                            <span role="status" x-text="submitting ? '取り込んでいます…' : ''" style="display: inline-block; margin-left: 12px; font-size: 13px; color: #374151;"></span>
                        </div>
```

4 回目（`csvImport()`）— 置き換える前:

```js
        includeDupes: false,
        importCount: function() {
            return this.validCount + (this.includeDupes ? this.dupeCount : 0);
        },
```

置き換えた後:

```js
        includeDupes: false,
        // 確定の二度押し止め: 1 回目は通して印を立て、2 回目以降は送信を取り消す（サーバの鍵が最後の歯止め）
        submitting: false,
        importCount: function() {
            return this.validCount + (this.includeDupes ? this.dupeCount : 0);
        },
        onSubmit: function(event) {
            if (this.submitting) {
                event.preventDefault();
                return;
            }
            this.submitting = true;
        },
        // 「戻る」で確認画面がそのまま戻ったとき（bfcache）に印を下ろす（Bug #65 と同じく persisted で絞らない）。
        // Chromium は POST の確認画面を bfcache に入れず控えから描き直す（最初から false・鍵はサーバが断る）
        resetSubmit: function() {
            this.submitting = false;
        },
```

- [ ] **Step 5: 流して緑を確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/CustomerImportTest.php 2>&1 | tail -1
```

期待: `OK (29 tests, 406 assertions)`

- [ ] **Step 6: 画面の走査テストも流す**（`x-show` と `:style` の衝突・取込のプレビューの描画・差し戻しの表示）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && K="base64:$(php -r 'echo base64_encode(random_bytes(32));')"; for f in tests/Feature/AlpineXShowDisplayConflictTest.php tests/Feature/Admin/ImportPreviewRenderTest.php tests/Feature/Admin/ImportValidationFeedbackTest.php; do printf '%s: ' "$f"; APP_KEY="$K" ./vendor/bin/phpunit "$f" 2>&1 | tail -1; done
```

期待: `OK (2 tests, 2 assertions)` ／ `OK (2 tests, 6 assertions)` ／ `OK (3 tests, 12 assertions)`

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && git add resources/views/admin/customers/import.blade.php tests/Feature/Admin/CustomerImportTest.php && git commit -m "$(cat <<'EOF'
fix: 顧客CSVインポートの確定ボタンの二度押しを止める

1 回目の送信で印を立て、2 回目の送信を取り消す。送信中はボタンを押せなくして
Tailwind の disabled: で見た目を変え、ボタンの横の role="status" に「取り込んでいます…」を
出す（375px で文字の途中で折り返さないよう inline-block）。「戻る」で戻った確認画面は
pageshow で印を下ろす（押すとサーバの鍵が断る）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 3: 全件テストとコンパイル済みビューの lint

**Files:** なし（確かめるだけ）

- [ ] **Step 1: 全件テスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

期待: `OK (2355 tests, 15346 assertions)`（Task 0 で `13.x` を取り込んでテストが増えたなどで本数が変わったら、その理由と本数を記録する）

- [ ] **Step 2: コンパイル済みビューの lint**（⚠ `view:cache` の成功表示だけでは足りない。Bug #21 / #26 / #30）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && K="base64:$(php -r 'echo base64_encode(random_bytes(32));')" && APP_KEY="$K" php artisan view:cache 2>&1 | tail -2 && n=0; bad=0; for f in storage/framework/views/*.php; do n=$((n+1)); php -l "$f" >/dev/null 2>&1 || { bad=$((bad+1)); echo "INVALID: $f"; }; done; echo "views=$n invalid=$bad"; APP_KEY="$K" php artisan view:clear 2>&1 | tail -2; git status --porcelain
```

期待: `views=274 invalid=0` ／ `status` は何も出さない。

---

## Task 4: 変異テスト（docs/RULES.md Bug #44 / #50 の作法）

**Files:** なし（隔離した worktree の中だけで変え、戻す。記録はこの計画の末尾の「実測記録」）

作法: 先にコミット（Task 2 まで済み）→ 隔離した worktree（`git worktree add --detach`）に vendor を**実体コピー**（`cp -Rc`。symlink は不可＝ Bug #50）→ 最初にカナリア → 変異 1 つごとに、前後で作業ツリーが空・置き換えがそれぞれちょうど 1 か所・着弾を確認・`--log-junit` で全件を流す・落ちたテストと**理由の文言**まで記録・戻したあと空を再確認。実行役 `cids-mutate.py` がこの確認を全部行い、1 つでも食い違えば止まる。1 つの変異が複数の置き換え（切り取って別の場所へ貼る＝移す）を持てる。

`SP` はこのセッションの scratchpad（システムプロンプトの「Scratchpad directory」の絶対パス）。

- [ ] **Step 1: 隔離した worktree を 3 つ作る**（名前は用途とコミットで一意にする＝ Bug #50 の衝突を避ける。コミットは `$SP/cids-mut-commit` に控える）

```bash
SP=<Scratchpad directory>; WT=/Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit; C=$(git -C "$WT" rev-parse --short HEAD); echo "$C" > "$SP/cids-mut-commit"; for s in a b c; do git -C "$WT" worktree add --detach "$SP/cids-mut-$C-$s" HEAD && cp -Rc "$WT/vendor" "$SP/cids-mut-$C-$s/vendor"; done; git -C "$WT" worktree list
```

⚠ `vendor` は `.gitignore` 済みなので、コピーしても作業ツリーは空のまま（Step 3 で確かめる）。

- [ ] **Step 2: 実行役を置く**（`$SP/cids-mutate.py`。この内容そのまま。コミットしない）

```python
#!/usr/bin/env python3
"""顧客 CSV 取込の二重送信を止める改修（2026-09-27）の変異テストの実行役。

使い方（隔離した worktree の中で。docs/RULES.md Bug #44 / #50 の作法）:
    python3 cids-mutate.py --iso <隔離した worktree> [ID ...]         # ID を省くと全部。全件テストで流す
    python3 cids-mutate.py --iso <…> --targets <テスト> … -- [ID ...]  # 流すテストを絞る（当て直しの確認用）
    python3 cids-mutate.py --iso <…> --check                         # どの変異もちょうど 1 か所ずつ当たるかだけ見る
    python3 cids-mutate.py --list                                    # 定義の一覧だけ

1 つごとに: 前に作業ツリーが空 → 置き換えがそれぞれちょうど 1 か所 → git status で着弾を確認 →
phpunit を --log-junit で流す → 落ちたテストと理由の 1 行目を記録 → git checkout で戻す → 後に作業ツリーが空。
どこかで食い違えば止まる（無効な測定を集めない）。
1 つの変異が複数の置き換え（切り取って別の場所へ貼る＝移す）を持てる。置き換えは書いた順に当てる。
"""
import argparse, base64, json, os, subprocess, sys, xml.etree.ElementTree as ET

C = "app/Http/Controllers/Admin/CustomerImportController.php"
O = "app/Support/OneTimeAction.php"
V = "resources/views/admin/customers/import.blade.php"

USED_TOKEN_MESSAGE = "この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「顧客管理」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。"
CLAIM_BLOCK = (
    "        // 確定は確認画面 1 つにつき 1 回だけ（クラスの docblock）。⚠ ほかの検査より先に使う\n"
    "        if ($confirmed && ! OneTimeAction::claimFrom($request, 'import_token')) {\n"
    "            return redirect()->route('admin.customers.import')\n"
    "                ->with('error', '" + USED_TOKEN_MESSAGE + "');\n"
    "        }\n"
    "\n"
)
WRITE_START = "        // インポート実行\n        DB::beginTransaction();\n"
PREVIEW_END = "                'importToken' => OneTimeAction::issue(),\n            ]);\n        }\n\n"
COMMIT = "            DB::commit();\n"
BUTTON_OPEN = "                            <button type=\"submit\" :disabled=\"submitting\"\n"
BUTTON_STYLE = "style=\"background: #059669; color: #fff; padding: 10px 28px; border-radius: 6px; font-size: 15px; font-weight: 600; border: none;\">"
STATUS = "                            <span role=\"status\" x-text=\"submitting ? '取り込んでいます…' : ''\" style=\"display: inline-block; margin-left: 12px; font-size: 13px; color: #374151;\"></span>\n"
WRAPPER_CLOSE = "                        </div>\n                        @if($validCount === 0)\n"

# (ID, [(ファイル, 置き換える前, 置き換えた後), ...])。どれも全件テストで流す
MUTATIONS = [
    # ---- カナリア（隔離が効いていれば、コピー側のコードが読まれて赤になる）----
    ("C0", [(C, "        return view('admin.customers.import');\n", "        return view('admin.customers.import-canary');\n")]),
    # ---- サーバの鍵（コントローラ）----
    ("K01", [(C, CLAIM_BLOCK, "")]),                                                      # 鍵を使わない
    ("K02", [(C, CLAIM_BLOCK, ""), (C, WRITE_START, CLAIM_BLOCK + WRITE_START)]),        # 0 件の歯止めの後ろ（書き込みの直前）へ
    ("K03", [(C, CLAIM_BLOCK, ""), (C, COMMIT, COMMIT + CLAIM_BLOCK)]),                  # 書き込みの後ろへ
    ("K04", [(C, CLAIM_BLOCK, ""), (C, PREVIEW_END, PREVIEW_END + CLAIM_BLOCK)]),        # 行の検査の後ろ・0 件の歯止めの前へ（等価）
    ("K05", [(C, "                'importToken' => OneTimeAction::issue(),\n", "                'importToken' => 'fixed-token-for-mutation',\n")]),
    ("K06", [(C, "OneTimeAction::claimFrom($request, 'import_token')", "OneTimeAction::claimFrom($request)")]),
    ("K07", [(C, "                ->with('error', '" + USED_TOKEN_MESSAGE + "');\n",
                 "                ->with('error', 'この操作はすでに実行されました。');\n")]),
    ("K08", [(C, "                ? '取り込める行がありません。プレビューのあとに、同じ人が登録された可能性があります。CSVをアップロードし直して、重複候補を確かめてください。'\n",
                 "                ? '取り込む行がありません。重複候補を取り込むときは「重複候補もインポートする」にチェックを入れてください。'\n")]),
    # ---- 鍵の部品（OneTimeAction）----
    ("K09", [(O, "        $token = $request->input($field);\n", "        $token = $request->input('guide_token');\n")]),
    ("K10", [(O, "    public static function claimFrom(Request $request, string $field = 'guide_token'): bool\n",
                 "    public static function claimFrom(Request $request, string $field = 'import_token'): bool\n")]),
    ("K11", [(O, "        return is_string($token) && self::claim($token);\n", "        return self::claim($token);\n")]),
    # ---- 画面（確定のフォーム）----
    ("U01", [(V, "                        <input type=\"hidden\" name=\"import_token\" value=\"{{ $importToken }}\">\n",
                 "                        <input type=\"hidden\" name=\"guide_token\" value=\"{{ $importToken }}\">\n")]),
    ("U02", [(V, " x-on:submit=\"onSubmit($event)\"", "")]),
    ("U03", [(V, " x-on:submit=\"onSubmit($event)\"", ""),
             (V, BUTTON_OPEN, "                            <button type=\"submit\" :disabled=\"submitting\" x-on:click=\"onSubmit($event)\"\n")]),
    ("U04", [(V, "x-on:submit=\"onSubmit($event)\"", "x-on:submit=\"onSubmit()\"")]),
    ("U05", [(V, " x-on:pageshow.window=\"resetSubmit()\"", "")]),
    ("U06", [(V, "x-on:pageshow.window=\"resetSubmit()\"", "x-on:pageshow=\"resetSubmit()\"")]),
    ("U07", [(V, " :disabled=\"submitting\"\n", "\n")]),
    ("U08", [(V, " :disabled=\"submitting\"\n", "\n"),
             (V, "<div x-show=\"importCount() > 0\" style=", "<div x-show=\"importCount() > 0\" :disabled=\"submitting\" style=")]),
    ("U09", [(V, "class=\"cursor-pointer disabled:cursor-not-allowed disabled:opacity-60\"", "class=\"cursor-pointer disabled:cursor-not-allowed\"")]),
    ("U10", [(V, BUTTON_STYLE, BUTTON_STYLE.replace("border: none;\">", "border: none; cursor: pointer;\">"))]),
    ("U11", [(V, "<span role=\"status\" x-text=", "<span x-text=")]),
    ("U12", [(V, STATUS + WRAPPER_CLOSE, WRAPPER_CLOSE.replace("                        @if($validCount === 0)\n", "") + STATUS + "                        @if($validCount === 0)\n")]),
    ("U13", [(V, "x-text=\"submitting ? '取り込んでいます…' : ''\"", "x-text=\"submitting ? '送信中…' : ''\"")]),
    ("U14", [(V, "style=\"display: inline-block; margin-left: 12px;", "style=\"margin-left: 12px;")]),
    # ---- 画面（csvImport() の JS）----
    ("J01", [(V, "                event.preventDefault();\n", "")]),
    ("J02", [(V, "            this.submitting = true;\n", "")]),
    ("J03", [(V, "            this.submitting = false;\n", "")]),
    ("J04", [(V, "        submitting: false,\n", "        submitting: true,\n")]),
]


def git(iso, *args):
    return subprocess.run(["git", "-C", iso, *args], capture_output=True, text=True, errors="replace").stdout


def run_phpunit(iso, junit, target=None):
    # JUnit は、このスクリプトが手元で起動した PHPUnit の書いたものだけを読む（外から来た XML は読まない）
    env = dict(os.environ, APP_KEY="base64:" + base64.b64encode(os.urandom(32)).decode())
    cmd = ["./vendor/bin/phpunit", "--log-junit", junit] + ([target] if target else [])
    subprocess.run(cmd, cwd=iso, env=env, capture_output=True, text=True, errors="replace")
    failures = []
    if not os.path.exists(junit):
        return None
    for tc in ET.parse(junit).getroot().iter("testcase"):
        for kind in ("failure", "error"):
            el = tc.find(kind)
            if el is not None:
                lines = [l for l in (el.text or "").strip().splitlines() if l.strip()]
                reason = next((l for l in lines if not l.startswith("Tests\\")), lines[0] if lines else "")
                failures.append({"test": f"{tc.get('class', '').split(chr(92))[-1]}::{tc.get('name')}", "reason": reason[:200]})
    os.remove(junit)
    return failures


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--iso")
    ap.add_argument("--list", action="store_true")
    ap.add_argument("--check", action="store_true")
    ap.add_argument("--targets", nargs="*")
    ap.add_argument("ids", nargs="*")
    args = ap.parse_args()

    if args.list:
        for mid, edits in MUTATIONS:
            print(f"{mid}\t" + ", ".join(sorted({p for p, _, _ in edits})))
        return

    iso = os.path.abspath(args.iso)
    if args.check:
        bad = 0
        for mid, edits in MUTATIONS:
            for path, old, new in edits:
                n = open(os.path.join(iso, path), encoding="utf-8").read().count(old)
                if n != 1:
                    bad += 1
                    print(f"{mid}: {path} の置き換えが {n} か所（1 でない）")
        print(f"変異 {len(MUTATIONS)} 通り・当たらないもの {bad} 件")
        sys.exit(1 if bad else 0)

    out = os.path.join(iso, "..", os.path.basename(iso) + "-results.jsonl")
    for mid, edits in MUTATIONS:
        if args.ids and mid not in args.ids:
            continue
        if git(iso, "status", "--porcelain").strip():
            sys.exit(f"{mid}: 始める前に作業ツリーが空でない（前の変異の残骸）。止める")
        paths = sorted({p for p, _, _ in edits})
        try:
            for path, old, new in edits:
                full = os.path.join(iso, path)
                src = open(full, encoding="utf-8").read()
                n = src.count(old)
                if n != 1:
                    sys.exit(f"{mid}: {path} の置き換えが {n} か所（1 でない）。止める")
                open(full, "w", encoding="utf-8").write(src.replace(old, new))
            landed = git(iso, "status", "--porcelain").strip()
            stat = git(iso, "diff", "--stat").strip().splitlines()
            if not landed:
                sys.exit(f"{mid}: 置き換えたのに作業ツリーが空（当たっていない）。止める")
            junit = os.path.join(iso, "..", f"junit-{os.path.basename(iso)}-{mid}.xml")
            if not args.targets:
                failures = run_phpunit(iso, junit)
            else:
                failures = []
                for target in args.targets:
                    got = run_phpunit(iso, junit, target)
                    failures = None if got is None or failures is None else failures + got
        finally:
            git(iso, "checkout", "--", *paths)
        if git(iso, "status", "--porcelain").strip():
            sys.exit(f"{mid}: 戻したのに作業ツリーが空でない。止める")
        record = {"id": mid, "files": paths, "diff": stat[-1] if stat else landed, "failures": failures}
        with open(out, "a", encoding="utf-8") as f:
            f.write(json.dumps(record, ensure_ascii=False) + "\n")
        count = "junit なし（phpunit が起動しなかった）" if failures is None else f"{len(failures)} 件"
        print(f"== {mid} {', '.join(paths)}  落ちたテスト {count}", flush=True)
        for fl in (failures or [])[:40]:
            print(f"   {fl['test']} :: {fl['reason']}", flush=True)
        if failures and len(failures) > 40:
            print(f"   …ほか {len(failures) - 40} 件（記録の jsonl を見る）", flush=True)
    print(f"記録: {out}")


if __name__ == "__main__":
    main()
```

⚠ 読む XML は、この実行役が手元で起動した PHPUnit の `--log-junit` が書いたものだけ（外から来た XML は読まない。`defusedxml` は入っていない）。

- [ ] **Step 3: どの変異もちょうど 1 か所ずつ当たることと、作業ツリーが空であることを確かめる**

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cids-mut-commit"); for s in a b c; do git -C "$SP/cids-mut-$C-$s" status --porcelain | head -3; done; python3 "$SP/cids-mutate.py" --iso "$SP/cids-mut-$C-a" --check
```

期待: `status` はどれも何も出さない・`変異 30 通り・当たらないもの 0 件`（カナリア C0 を含めて 30 通り＝変異 29 通り＋カナリア）

- [ ] **Step 4: カナリアを 3 つのコピーで通す**（隔離が効いていれば、コピー側のコードが読まれて赤になる。3 つを Bash の `run_in_background` で同時に起動し、終わりの知らせを待つ。5 分ほど）

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cids-mut-commit"); python3 "$SP/cids-mutate.py" --iso "$SP/cids-mut-$C-a" C0 > "$SP/cids-mut-$C-a-canary.log" 2>&1
```

（`-a` を `-b`・`-c` に替えた 2 本も同時に起動する）

期待: 3 つとも `== C0 … 落ちたテスト 23 件` — `CustomerImportTest` の確定を送る 22 本（着いた画面がビューの無いエラーの画面: `Failed asserting that '<!DOCTYPE html>…`）と `ImportValidationFeedbackTest::test_an_invalid_file_shows_the_reason_on_screen with data set "顧客CSV"`。**どれか 1 つでも赤にならなければ止める**（コピーでなく元の worktree のコードが読まれている＝ Bug #50）。

- [ ] **Step 5: 変異を流す**（下の 3 つを Bash の `run_in_background` で**同時に**起動し、3 つとも終わりの知らせを待つ。途中で作業ツリーやログを覗いて判断しない＝変異の途中を拾うと偽の赤になる。それぞれ全件 9〜10 回で 20〜30 分）

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cids-mut-commit"); python3 "$SP/cids-mutate.py" --iso "$SP/cids-mut-$C-a" K01 K02 K03 K04 K05 K06 K07 K08 K09 K10 > "$SP/cids-mut-$C-a.log" 2>&1
```

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cids-mut-commit"); python3 "$SP/cids-mutate.py" --iso "$SP/cids-mut-$C-b" K11 U01 U02 U03 U04 U05 U06 U07 U08 U09 > "$SP/cids-mut-$C-b.log" 2>&1
```

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cids-mut-commit"); python3 "$SP/cids-mutate.py" --iso "$SP/cids-mut-$C-c" U10 U11 U12 U13 U14 J01 J02 J03 J04 > "$SP/cids-mut-$C-c.log" 2>&1
```

- [ ] **Step 6: 期待と突き合わせる**（下の表。**落ちたテストの集合と理由の文言まで**。記録は `$SP/cids-mut-$C-{a,b,c}-results.jsonl`）
  - 表の「期待」は、試作で関係するテスト 10 本（`CustomerImportTest`・`LoginGuideTest`・`ApprovalUserImportTest`・`ApprovalUserManagementTest`・`UserManagementApprovalTest`・`ImportPreviewRenderTest`・`ImportValidationFeedbackTest`・`AlpineXShowDisplayConflictTest`・`ImportControllerReturnPathScanTest`・`ImportControllerValidationRedirectScanTest`）に絞って測った値。全件で流して表に無いテストが落ちたら、理由を調べる
  - 期待と違ったら（緑のまま・別のテストが落ちた・理由が違う）、理由を調べる。テストの穴ならテストを足してコミットし、その変異を当て直す。当て直す前に、隔離した worktree を新しいコミットへ進める（名前は控えの `C` のまま）:

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cids-mut-commit"); NEW=$(git -C /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit rev-parse HEAD); for s in a b c; do git -C "$SP/cids-mut-$C-$s" status --porcelain && git -C "$SP/cids-mut-$C-$s" checkout --detach "$NEW"; done
```

  - 1 つだけ当て直すときは `--targets` で流すテストを絞れる（例 `python3 "$SP/cids-mutate.py" --iso "$SP/cids-mut-$C-a" --targets tests/Feature/Admin/CustomerImportTest.php -- K02`）。表の記録は全件で取り直す

以下、T1〜T7 は Task 1・Task 2 のテスト（T1 `test_sending_the_same_confirmation_twice_imports_once` ／ T2 `test_sending_a_checked_confirmation_twice_imports_once` ／ T3 `test_each_preview_issues_its_own_token` ／ T4 `test_a_confirmation_without_a_usable_token_imports_nothing`（データセット 3 つ）／ T5 `test_someone_registered_after_the_preview_turns_the_confirmation_back` ／ T6 `test_the_confirmation_form_guards_against_a_second_press` ／ T7 `test_a_second_submit_is_cancelled_until_the_page_is_shown_again`）。

**サーバの鍵（`app/Http/Controllers/Admin/CustomerImportController.php`）:**

| ID | 変異 | 期待（落ちるテストと理由の 1 行目）|
|---|---|---|
| C0 | `showForm()` のビュー名を無いものに（カナリア）| 23 本: 確定を送る 22 本（`Failed asserting that '<!DOCTYPE html>…`＝着いた画面がエラーの画面）＋ `ImportValidationFeedbackTest`「顧客CSV」（差し戻し先に理由が出ていない）|
| K01 | 鍵を使わない（ブロックごと消す）| 5 本: T1（着いた画面に鍵の案内が無い `Failed asserting that '<!DOCTYPE html>…`）・T2（`2 回目の送信で、重複候補がもう一度入った`）・T4 の 3（鍵の案内が無い）|
| K02 | 鍵を 0 件の歯止めの後ろ（書き込みの直前＝決裁の取込と同じ位置）へ | 1 本: T1（2 回目が 0 件の案内に着く `Failed asserting that '<!DOCTYPE html>…`）|
| K03 | 鍵を書き込みの後ろ（commit の後）へ | 5 本: T1・T2（`2 回目の送信で、重複候補がもう一度入った`）・T4 の 3（`Failed asserting that 1 is identical to 0.`＝断る前に書いた）|
| K04 | 鍵を行の検査の後ろ・0 件の歯止めの前へ | **0 本（等価）**。0 件の歯止めより前ならどこでも同じ（設計書との違い 5）|
| K05 | 鍵を固定値に | 1 本: T3（`プレビューごとに鍵が変わっていない`）|
| K06 | コントローラが読む名前を既定（`guide_token`）に | 18 本: 確定を送る 22 本から、断られてよい 4 本（T4 の 3・部署を書き換える 1）を除く全部（鍵の案内に着く `Failed asserting that '<!DOCTYPE html>…`）|
| K07 | 鍵の案内の文言を変える | 5 本: T1・T2・T4 の 3（`Failed asserting that '<!DOCTYPE html>…`）|
| K08 | 0 件の案内を元の文（チェックを勧める）に戻す | 2 本: `test_confirming_only_duplicates_without_the_check_imports_nothing`・T5 |

**鍵の部品（`app/Support/OneTimeAction.php`）:**

| ID | 変異 | 期待 |
|---|---|---|
| K09 | `claimFrom()` が名前の引数を無視する（常に `guide_token`）| 18 本: K06 と同じ |
| K10 | 既定の名前を `import_token` に | 45 本（顧客は 0 本）: `LoginGuideTest` 5（`有効時間 0 で 1 回目から弾かれている`・`正しい文字列トークンの 1 回目が通っていない` ほか）・`ApprovalUserImportTest` 15・`ApprovalUserManagementTest` 13・`UserManagementApprovalTest` 12（多くは `Expected response status code [200] but received 302.`＝決裁の鍵が 1 回目から断られる）|
| K11 | `is_string()` を外す | 5 本: T4 の 3（`確定の応答が転送になっていない`＝`TypeError` で 500）・`LoginGuideTest::test_claim_from_accepts_only_a_string_token`（`TypeError: App\Support\OneTimeAction::claim(): Argument #1 ($token) must be of type string, array given…`）・`ApprovalUserImportTest::test_an_array_guide_token_is_refused_without_a_500`（`… but received 500.`）|

**画面（`resources/views/admin/customers/import.blade.php`）:** すべて `CustomerImportTest`

| ID | 変異 | 期待 |
|---|---|---|
| U01 | 鍵の hidden の名前を `guide_token` に | 22 本: 確定のフォームを分解するテスト全部（`確定のフォームに 1 回限りの鍵（import_token）が無い`）|
| U02 | `x-on:submit` を外す | 1 本: T6（`Failed asserting that '<form method="POST" action="http://localhost/admin/customers/import"…` contains `x-on:submit="onSubmit($event)"`）|
| U03 | `x-on:submit` をボタンの `x-on:click` へ移す | 1 本: T6（同上）|
| U04 | `onSubmit()` に `$event` を渡さない | 1 本: T6（同上）|
| U05 | `pageshow` を外す | 1 本: T6（contains `x-on:pageshow.window="resetSubmit()"`）|
| U06 | `pageshow` の `.window` を外す | 1 本: T6（同上）|
| U07 | `:disabled` を外す | 1 本: T6（`Failed asserting that '<button type="submit"…` contains `:disabled="submitting"`）|
| U08 | `:disabled` をボタンを包む要素へ移す | 1 本: T6（同上）|
| U09 | `disabled:opacity-60` を外す | 1 本: T6（`「インポート実行」ボタンに disabled:opacity-60 が無い`）|
| U10 | `cursor: pointer` を style に戻す | 1 本: T6（style に `cursor` がある）|
| U11 | `role="status"` を外す | 1 本: T6（`ボタンを包む要素の中に role="status" が無い`）|
| U12 | `role="status"` の要素をボタンを包む要素の外へ | 1 本: T6（同上）|
| U13 | 送信中の文字を変える | 1 本: T6（contains `x-text="submitting ? '取り込んでいます…' : ''"`）|
| U14 | 送信中の文字の `display: inline-block` を外す | 1 本: T6（contains `display: inline-block`）|
| J01 | 2 回目を取り消さない（`event.preventDefault()` を消す）| 1 本: T7（`Failed asserting that two arrays are identical.`）|
| J02 | 1 回目で印を立てない | 1 本: T7（同上）|
| J03 | `resetSubmit()` が印を下ろさない | 1 本: T7（同上）|
| J04 | 印の最初の値を `true` に | 1 本: T7（同上）|

- [ ] **Step 7: 片づけて記録をコミット**

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cids-mut-commit"); for s in a b c; do git -C /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit worktree remove --force "$SP/cids-mut-$C-$s"; done; git -C /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit worktree list
```

期待: 一覧に `cids-mut-…` が残っていない（`approval-phase2a` と main repo とこの worktree だけ）。

この計画の末尾の「実測記録」に、3 つの表の結果（ID・落ちたテストの数と名前・理由）と、期待と違ったもの・その対応を書いてコミットする:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && git add docs/superpowers/plans/2026-09-27-customer-import-double-submit.md && git commit -m "$(cat <<'EOF'
docs: 顧客CSVインポートの二重送信を止める改修の変異テストの結果を記録する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 5: ローカルの実ブラウザ確認

**Files:** なし（使い捨てのログイン用ルートは**コミットしない**。確認のあと必ず戻す）

使い捨ての SQLite ＋ `artisan serve` ＋ Playwright MCP（画面が見えている状態。⚠ Alpine のタイミングは画面が見えているブラウザで測る）。
⚠ `preview_start` は使わない（main repo の launch.json を解決して実 MySQL に当たる）。
⚠ ブラウザでパスワードを入力しない。ログインは使い捨てのログイン用ルートで入る。
⚠ Playwright MCP が読み書きできるのは `/Users/masanori/site/manage` の下だけ（scratchpad は拒否される）。CSV とスクリーンショットは `.playwright-mcp/`（gitignore 済み）に置き、確認のあと消す。
⚠ Playwright の癖（試作で実測）: `page.evaluate` は画面の移り変わりの途中は答えない（移ったあとで `Execution context was destroyed`）→ 送信中の様子は画面の中の見張りで記録して、移ったあとで読む。`browser_run_code_unsafe` の中では `setTimeout`・`Buffer` が無い（`page.waitForTimeout`・`page.route`・`locator.setInputFiles(<パス>)` は使える）。
⚠ キャッシュは本番と同じ file にする（`CACHE_STORE=file`）。`array` だと `artisan serve` は要求ごとに別のプロセスなので鍵を覚えず、2 回目も通ってしまう。

- [ ] **Step 1: 使い捨ての環境**（`SP` は scratchpad。環境変数は毎回 `source` する＝シェルの状態は次の呼び出しに残らない）

```bash
SP=<Scratchpad directory>; DB="$SP/cids-browser.sqlite"; rm -f "$DB"; : > "$DB"; cat > "$SP/cids-env.sh" <<EOF
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE="$DB" QUEUE_CONNECTION=sync MAIL_MAILER=log CACHE_STORE=file
EOF
sed 's/APP_KEY="[^"]*"/APP_KEY="…"/' "$SP/cids-env.sh"; ls /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit/bootstrap/cache/; lsof -nP -iTCP:8767 -sTCP:LISTEN | head -2
```

期待: env の 1 行（APP_KEY は伏せる）・`bootstrap/cache/` に何も無い（設定のキャッシュがあると環境変数が効かない）・8767 番を掴んでいるプロセスが無い（あれば別の番号にして、下の URL も替える）。

`$SP/seed-cids.php`（scratchpad に置く・コミットしない。**接続先が scratchpad の SQLite でなければ止まる**安全装置つき。第 1 引数にアプリの根を渡す）:

```php
<?php
// 使い捨ての SQLite に顧客 CSV 取込（二重送信）の確認用のデータを入れる（scratchpad に置く・コミットしない）
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;

$root = $argv[1] ?? '';
if ($root === '' || ! is_file($root . '/artisan')) {
    fwrite(STDERR, "中断: 第 1 引数にアプリの根（artisan のあるフォルダ）を渡す\n");
    exit(1);
}
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$path = (string) config('database.connections.sqlite.database');
if (config('database.default') !== 'sqlite' || ! str_starts_with($path, '/private/tmp/claude-501/')) {
    fwrite(STDERR, '中断: 接続先が使い捨ての SQLite ではない（' . config('database.default') . " {$path}）\n");
    exit(1);
}

// buyers などは本番も raw SQL 管理でマイグレーションに無い → テスト用スキーマの trait で作る
$schema = new class {
    use Tests\Concerns\CreatesRealEstateSchema;
    use Tests\Concerns\CreatesSurveyQuestionSchema;

    public function run(): void
    {
        $this->createRealEstateSchema();
        $this->createSurveyQuestionSchema();
    }
};
$schema->run();

$user = new User();
$user->forceFill([
    'name' => '経営 一郎', 'email' => 'e0001@example.invalid',
    'role' => UserRole::Executive->value, 'status' => UserStatus::Active->value,
    // ブラウザでは使わない（使い捨てのログイン用ルートで入る）
    'password' => bin2hex(random_bytes(16)), 'must_change_password' => false,
])->save();

echo "user id: {$user->id} / cache: " . config('cache.default') . "\n";
```

```bash
SP=<Scratchpad directory>; WT=/Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit; cd "$WT" && source "$SP/cids-env.sh" && php artisan migrate --force 2>&1 | tail -2 && php "$SP/seed-cids.php" "$WT" && /Users/masanori/site/manage/node_modules/.bin/vite build 2>&1 | tail -3
```

期待: `user id: 1 / cache: file`（試作で確かめた）。`vite build` は worktree の `public/build`（gitignore 済み）に書く。

- [ ] **Step 2: 確認用の CSV**（BOM つき UTF-8。住宅のテンプレートと同じ見出し。どれも新しい人なので、重複候補は出ない）

```bash
python3 - <<'PY'
import os
H = ['姓', '名', 'セイ', 'メイ', '生年月日', '元号', '大人人数', '子供人数', '郵便番号', '都道府県', '市区町村', '住所詳細',
     '建物名', '電話番号', 'メールアドレス', '職業', '勤務先', '勤続年数', '取得日', '来場分譲地名', '担当者名']
def person(last, first):
    cells = {'姓': last, '名': first, '都道府県': '愛媛県', '市区町村': '松山市', '取得日': '2026-09-01'}
    return ','.join(cells.get(h, '') for h in H)
files = {
    'cids-double.csv': [person('佐藤', '花子'), person('鈴木', '一')],
    'cids-slow.csv': [person('高橋', '三')],
    'cids-pageshow.csv': [person('伊藤', '五')],
    'cids-narrow.csv': [person('渡辺', '六')],
}
d = '/Users/masanori/site/manage/.playwright-mcp'
os.makedirs(d, exist_ok=True)
for name, rows in files.items():
    with open(os.path.join(d, name), 'w', encoding='utf-8') as f:
        f.write('﻿' + '\n'.join([','.join(H)] + rows) + '\n')
    print(name, len(rows))
PY
```

- [ ] **Step 3: 使い捨てのログイン用ルートと開発サーバ**

`routes/web.php` の末尾に一時的に足す（**コミットしない**）:

```php
// ⚠ 使い捨て（ローカルの実ブラウザ確認だけ。コミットしない）
Route::get('/_dev/login-as/{id}', function (string $id) {
    abort_unless(app()->environment('local'), 404);
    \Illuminate\Support\Facades\Auth::loginUsingId((int) $id);
    return redirect('/admin/customers/import');
});
```

開発サーバを Bash の `run_in_background` で起動する（出力ファイルのパスを控える。Step 4 の 5 で読む）:

```bash
SP=<Scratchpad directory>; cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && source "$SP/cids-env.sh" && php artisan serve --host=127.0.0.1 --port=8767
```

- [ ] **Step 4: 見ること**（Playwright で `http://127.0.0.1:8767/_dev/login-as/1` を開くと取込の画面に着く。プレビューは、`browser_click` の target に `label:has(input[type="file"][name="csv_file"])` を渡して選択の窓を開き、`browser_file_upload` で CSV を選び、「アップロードしてプレビュー」を押す。下の JS C・E・F・G は自分でプレビューまで進める）

| # | 操作 | 見ること（試作の実測）|
|---|---|---|
| 1 | ログイン用ルート → 取込の画面 | 初期フォームが開く |
| 2 | `cids-double.csv` をプレビュー → JS A | ボタン「インポート実行（2件）」が見えて押せる・`cursor` は `pointer`・`opacity` は `1`・送信中の文字は空・鍵の hidden は 40 文字 |
| 3 | JS B（ダブルクリック）→ 下の `sqlite3` | 確定の POST は **1 回**（`posts` が 1 件・`confirmed: true`）・「2件のインポートが完了しました。」・鍵の案内なし ／ `buyers` が 2 行（佐藤・鈴木）|
| 4 | JS C（`cids-slow.csv` をプレビューし、確定の応答を 3 秒遅らせて押す。画面の中の見張りで記録）| `watch` の `click-listener` の直後の記録が `disabled: true`・`cursor: not-allowed`・`opacity: 0.6`・`status: 取り込んでいます…` ／ 確定の POST は 1 回 ／ 「1件のインポートが完了しました。」|
| 5 | `browser_navigate_back`（ブラウザの「戻る」）→ JS A → 開発サーバの出力の末尾 | 確認画面が出る（`navType: back_forward`・ボタンは押せる・`submitting: false`）。**戻った時刻の行に `/admin/customers/import` が無い**（控えから描き直した。POST を送り直していない）|
| 6 | JS D（そのまま押す）→ `sqlite3` | 確定の POST は 1 回・着いた画面に「この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。…」・完了の文は無い ／ `buyers` は 3 行のまま（高橋は 1 人）|
| 7 | JS E（`cids-pageshow.csv` をプレビューし、送信を止めて押す → 合成の `pageshow` → もう一度押す）| `afterPress` は `disabled: true`・`not-allowed`・`0.6`・「取り込んでいます…」・`submitting: true` → `afterPageshow` は `disabled: false`・`pointer`・`1`・空・`false` → `afterSecondPress` はまた `true` の側 ／ `submits: 2` |
| 8 | JS F（1440px と 375px で `cids-narrow.csv` をプレビュー）| どちらも `main` が `[scrollWidth, clientWidth]` で等しい（1440px: 1220/1220・375px: 375/375）・ボタンが見える |
| 9 | JS G（375px で送信を止めて押す＋スクリーンショット）→ スクリーンショットを Read で見る | `lines: 1`・送信中の文字の上端がボタンの下端より下（ボタンの下の行にまとまって出る）・`main` は 375/375 ／ 画像で「取り込んでいます…」が割れずに 1 行 |
| 10 | ここまでの全部 | `browser_console_messages`（`level: error`・`all: true`）が 0 件 |

3・6 で DB を見るコマンド:

```bash
SP=<Scratchpad directory>; sqlite3 "$SP/cids-browser.sqlite" "select id, last_name, first_name from buyers order by id"
```

JS A（`browser_evaluate`）:

```js
() => {
  const button = [...document.querySelectorAll('button')].find(b => b.textContent.includes('インポート実行（'));
  const status = document.querySelector('[role="status"]');
  const token = button && button.closest('form').querySelector('input[name="import_token"]');
  const cs = button ? getComputedStyle(button) : null;
  return {
    navType: performance.getEntriesByType('navigation')[0].type,
    button: button ? { visible: button.offsetParent !== null, disabled: button.disabled, text: button.textContent.replace(/\s+/g, ''), cursor: cs.cursor, opacity: cs.opacity } : null,
    status: status ? status.textContent : null,
    tokenLength: token ? token.value.length : null,
    submitting: button ? window.Alpine.$data(button).submitting : null,
  };
}
```

JS B（`browser_run_code_unsafe`）:

```js
async (page) => {
  const posts = [];
  const onReq = r => { if (r.method() === 'POST') posts.push({ confirmed: (r.postData() || '').includes('confirmed=1') }); };
  page.on('request', onReq);
  await page.locator('button:has-text("インポート実行（")').dblclick();
  await page.waitForLoadState('load');
  await page.waitForTimeout(1500);
  page.off('request', onReq);
  const text = await page.locator('body').innerText();
  return { posts, done: (text.match(/\d+件のインポートが完了しました。/) || [null])[0], key: text.includes('この確認画面からは取り込めません') };
}
```

JS C（`browser_run_code_unsafe`）:

```js
async (page) => {
  await page.goto('http://127.0.0.1:8767/admin/customers/import');
  await page.locator('input[type="file"][name="csv_file"]').setInputFiles('/Users/masanori/site/manage/.playwright-mcp/cids-slow.csv');
  await Promise.all([page.waitForNavigation(), page.locator('button:has-text("アップロードしてプレビュー")').click()]);
  // 見張り: ボタンの属性・送信中の文字が変わった瞬間の見た目を localStorage に残す（移ったあとで読む）
  await page.evaluate(() => {
    localStorage.removeItem('cids-watch');
    const b = [...document.querySelectorAll('button')].find(x => x.textContent.includes('インポート実行（'));
    const s = document.querySelector('[role="status"]');
    const log = [];
    const snap = (why) => {
      const cs = getComputedStyle(b);
      log.push({ why, disabled: b.disabled, cursor: cs.cursor, opacity: cs.opacity, status: s.textContent });
      localStorage.setItem('cids-watch', JSON.stringify(log));
    };
    snap('before');
    new MutationObserver(() => snap('button')).observe(b, { attributes: true });
    new MutationObserver(() => snap('status')).observe(s, { childList: true, characterData: true, subtree: true });
    b.addEventListener('click', () => snap('click-listener'));
  });
  const posts = [];
  await page.route('**/*', async (route) => {
    const req = route.request();
    if (req.method() === 'POST' && (req.postData() || '').includes('confirmed=1')) {
      posts.push('confirm');
      await page.waitForTimeout(3000);
    }
    await route.continue();
  });
  await page.locator('button:has-text("インポート実行（")').click({ noWaitAfter: true });
  await page.waitForTimeout(4500);
  await page.unrouteAll({ behavior: 'ignoreErrors' });
  const watch = await page.evaluate(() => JSON.parse(localStorage.getItem('cids-watch') || '[]'));
  const text = await page.locator('body').innerText();
  return { posts, watch, done: (text.match(/\d+件のインポートが完了しました。/) || [null])[0], key: text.includes('この確認画面からは取り込めません') };
}
```

JS D（`browser_run_code_unsafe`）:

```js
async (page) => {
  const posts = [];
  const onReq = r => { if (r.method() === 'POST') posts.push((r.postData() || '').includes('confirmed=1') ? 'confirm' : 'other'); };
  page.on('request', onReq);
  await Promise.all([page.waitForNavigation(), page.locator('button:has-text("インポート実行（")').click()]);
  page.off('request', onReq);
  const text = await page.locator('body').innerText();
  return { posts, done: (text.match(/\d+件のインポートが完了しました。/) || [null])[0], key: (text.match(/この確認画面からは取り込めません[^\n]*/) || [null])[0] };
}
```

JS E（`browser_run_code_unsafe`）:

```js
async (page) => {
  await page.goto('http://127.0.0.1:8767/admin/customers/import');
  await page.locator('input[type="file"][name="csv_file"]').setInputFiles('/Users/masanori/site/manage/.playwright-mcp/cids-pageshow.csv');
  await Promise.all([page.waitForNavigation(), page.locator('button:has-text("アップロードしてプレビュー")').click()]);
  const read = () => page.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(x => x.textContent.includes('インポート実行（'));
    const s = document.querySelector('[role="status"]');
    const cs = getComputedStyle(b);
    return { disabled: b.disabled, cursor: cs.cursor, opacity: cs.opacity, status: s.textContent, submitting: window.Alpine.$data(b).submitting };
  });
  // 送信そのものは止める（Alpine の onSubmit のあとに走る、自分の submit の見張りで preventDefault）
  await page.evaluate(() => {
    window.__submits = 0;
    const b = [...document.querySelectorAll('button')].find(x => x.textContent.includes('インポート実行（'));
    b.closest('form').addEventListener('submit', e => { window.__submits++; e.preventDefault(); });
  });
  const before = await read();
  await page.locator('button:has-text("インポート実行（")').click();
  await page.waitForTimeout(200);
  const afterPress = await read();
  await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
  await page.waitForTimeout(200);
  const afterPageshow = await read();
  await page.locator('button:has-text("インポート実行（")').click();
  await page.waitForTimeout(200);
  const afterSecondPress = await read();
  const submits = await page.evaluate(() => window.__submits);
  return { before, afterPress, afterPageshow, afterSecondPress, submits };
}
```

JS F（`browser_run_code_unsafe`）:

```js
async (page) => {
  const out = {};
  for (const [w, h] of [[1440, 900], [375, 812]]) {
    await page.setViewportSize({ width: w, height: h });
    await page.goto('http://127.0.0.1:8767/admin/customers/import');
    await page.locator('input[type="file"][name="csv_file"]').setInputFiles('/Users/masanori/site/manage/.playwright-mcp/cids-narrow.csv');
    await Promise.all([page.waitForNavigation(), page.locator('button:has-text("アップロードしてプレビュー")').click()]);
    out[w] = await page.evaluate(() => {
      const main = document.querySelector('main');
      const b = [...document.querySelectorAll('button')].find(x => x.textContent.includes('インポート実行（'));
      return { main: [main.scrollWidth, main.clientWidth], buttonVisible: b.offsetParent !== null };
    });
  }
  await page.setViewportSize({ width: 1440, height: 900 });
  return out;
}
```

JS G（`browser_run_code_unsafe`）:

```js
async (page) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto('http://127.0.0.1:8767/admin/customers/import');
  await page.locator('input[type="file"][name="csv_file"]').setInputFiles('/Users/masanori/site/manage/.playwright-mcp/cids-narrow.csv');
  await Promise.all([page.waitForNavigation(), page.locator('button:has-text("アップロードしてプレビュー")').click()]);
  await page.evaluate(() => {
    const b = [...document.querySelectorAll('button')].find(x => x.textContent.includes('インポート実行（'));
    b.closest('form').addEventListener('submit', e => e.preventDefault());
  });
  await page.locator('button:has-text("インポート実行（")').click();
  await page.waitForTimeout(200);
  const r = await page.evaluate(() => {
    const main = document.querySelector('main');
    const b = [...document.querySelectorAll('button')].find(x => x.textContent.includes('インポート実行（'));
    const s = document.querySelector('[role="status"]');
    const br = b.getBoundingClientRect(), sr = s.getBoundingClientRect();
    return { main: [main.scrollWidth, main.clientWidth], status: s.textContent, lines: s.getClientRects().length,
             buttonBottom: Math.round(br.bottom), statusTop: Math.round(sr.top) };
  });
  await page.screenshot({ path: '/Users/masanori/site/manage/.playwright-mcp/cids-submitting-375.png' });
  await page.setViewportSize({ width: 1440, height: 900 });
  return r;
}
```

スクリーンショットは scratchpad へ写してから Read で見る（Playwright の出力を直接 Read してもよい）:

```bash
SP=<Scratchpad directory>; mkdir -p "$SP/cids-shots" && cp /Users/masanori/site/manage/.playwright-mcp/cids-submitting-375.png "$SP/cids-shots/"
```

- [ ] **Step 5: 片付け**

開発サーバは起動したバックグラウンドの作業を止める（`TaskStop`）。Playwright は `browser_close`。そのあと:

```bash
SP=<Scratchpad directory>; WT=/Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit; D=/Users/masanori/site/manage/.playwright-mcp; cd "$WT" && git checkout -- routes/web.php && rm -rf public/build && find storage/framework/cache/data -mindepth 1 ! -name .gitignore -delete && rm -f "$SP/cids-browser.sqlite" && for f in cids-double.csv cids-slow.csv cids-pageshow.csv cids-narrow.csv cids-submitting-375.png; do [ -e "$D/$f" ] && rm -f "${D:?}/${f:?}"; done; git status --porcelain; lsof -nP -iTCP:8767 -sTCP:LISTEN | head -2; echo done
```

`browser_*` のたびに `.playwright-mcp/` へ溜まる `page-<UTC 時刻>.yml`・`console-<UTC 時刻>.log` は、**今回の時刻の分だけ**消す（ほかのセッションのものは消さない。変数のパスの `rm` は `rm -f "${D:?}/${f:?}"` の形で書く）。`status` は何も出さない・8767 番を掴んでいるものが無いこと。結果を「実測記録」に書く（Task 7 でコミット）。

---

## Task 6: 独立レビュー

**Files:** 指摘に応じて

- [ ] **Step 1: レビューを頼む**（Agent・`general-purpose`。コードは変えさせない。指摘には再現の手順を付けさせる）

頼む内容（そのまま渡す）:

> 顧客CSVインポートの二重送信を止める改修を、欠陥を見つける目でレビューしてください（説明ではなく欠陥の指摘がほしい）。
> 対象: worktree `/Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit` の `git diff 13.x...HEAD`（アプリとテスト）。
> 設計書 `docs/superpowers/specs/2026-09-27-customer-import-double-submit-design.md` と計画 `docs/superpowers/plans/2026-09-27-customer-import-double-submit.md` を読んでから見てください。
> 見てほしいこと: ①設計書との食い違い ②鍵を使う位置（2 回目が 0 件の歯止めや書き込みに着く経路が残っていないか）と、決裁の 5 か所の `claimFrom()` を壊していないか ③画面の二度押し止め（Alpine の `x-on:submit`・`:disabled`・`pageshow`・`role="status"`）と CLAUDE.md の Top trap #4・#5・#12、docs/RULES.md Bug #30・#32・#65 ④送信中に固まる・二度と押せなくなる経路（「戻る」・送信の中止・通信の失敗）⑤テストが緑のまま壊せる形が残っていないか（Bug #43・#47・#48・#54 の型）⑥文書の誤り。
> 決まり: コードは変えない（探りは scratchpad のコピーか、テストを一時的に足して流し、元に戻す）。`.env` は読まない。全件テストは `cd <worktree> && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`。
> 報告: 指摘ごとに 重さ（Critical / Important / Minor）・場所・再現の手順（落ちるテストか、実行した探りとその出力）・直し方の案。推測だけの指摘は「未実測」と書いてください。

- [ ] **Step 2: 指摘を 1 つずつ実測してから直す**（実測で再現しないものは理由を書いて見送る）
  - 直すたびに、落ちるテストを先に足し（直す前に赤・直した後に緑を確かめる）、関係するテストを流してコミットする
  - 本番のコードを変えたら、その場所に当たる変異を `cids-mutate.py` で当て直す（隔離した worktree を作り直すか、Task 4 の手順で進める）
  - 最後に全件テストとコンパイル済みビューの lint（Task 3）をやり直す
  - 指摘・実測・対応を「実測記録」に書く

---

## Task 7: 記録

**Files:**
- Modify: `docs/RULES.md`（Bug の行を 1 つ足す）
- Modify: `CLAUDE.md`（Bug の件数 2 か所）
- Modify: `docs/BACKLOG.md`（この作業の節・既存の範囲外の 1 行に矢印・完了状況）
- Modify: この計画（実測記録）

数字（本数・変異・ブラウザ）は Task 3〜6 の実測に合わせる。下の文は試作の実測で書いてあるので、違えば直す。

- [ ] **Step 1: Bug の番号を最新の `13.x` で数え直す**（別の会話が先に RULES へ足していることがある）

```bash
git -C /Users/masanori/site/manage log --oneline -1 13.x && git -C /Users/masanori/site/manage show 13.x:docs/RULES.md | grep -c '^| [0-9]' && cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && grep -c '^| [0-9]' docs/RULES.md && git merge-base --is-ancestor 13.x HEAD && echo 'ahead-of-13.x'
```

期待（2026-09-27 時点）: `13.x` の最後のコミット・`66`・`66`・`ahead-of-13.x` → **この作業は Bug #67**。
⚠ `13.x` の数が 66 より大きい、または `ahead-of-13.x` が出ないときは、先に Task 0 Step 1 の手順で `13.x` を取り込み（全件テストを流し直す）、次の番号を使う。下の文の「67」をその番号に、`CLAUDE.md` の件数もそれに合わせて読み替える。

- [ ] **Step 2: `docs/RULES.md` に Bug #67 を足す**（Edit。表の最後の行（#66）の後ろ）

置き換える前:

```markdown
本番反映は main repo の cwd で `composer dump-autoload`（新しいクラス）→ `./deploy.sh`（DB 変更なし） |

## Postal Code APIs
```

置き換えた後:

```markdown
本番反映は main repo の cwd で `composer dump-autoload`（新しいクラス）→ `./deploy.sh`（DB 変更なし） |
| 67 | 顧客CSVインポートの確定（「インポート実行」）を**同じ確認画面から 2 回**送ると、「重複候補もインポートする」にチェックを入れていた場合は 2 回目も「N件のインポートが完了しました。」が出て**全員がもう一度入る**（試作で実測: 山田 3 人・佐藤 2 人）。チェックが無い場合は 2 回目が「取り込む行がありません。重複候補を取り込むときは「重複候補もインポートする」にチェックを入れてください。」に着き、**案内どおりチェックを入れると全員がもう一度入る**。ダブルクリック・Chrome の「戻る」で出る確認画面からの押し直しで起きる（Bug #66 で確定が通るようになってから起こりうる。2026-09-27 修正） | 1 回だけ送らせる仕組みが画面にもサーバにも無かった。重複の確認（姓・名・都道府県・市区町村）は、2 回目に 1 回目で入った人を「重複候補」と数えるだけで、チェック済みならそのまま取り込む。0 件の歯止めの案内はチェックを勧めていた。⚠ 決裁の取込（`Approval\UserImportController`）と同じく**書き込みの直前**に鍵を置くと、顧客の取込では 2 回目が鍵より先に 0 件の歯止めに着く（2 回目の行が重複候補になるため。決裁は既存の人を「更新」と数えるので着かない） | **確定は確認画面 1 つにつき 1 回だけ**: プレビューで `OneTimeAction::issue()` の鍵を発行して hidden `import_token` に載せ、確定は部署の入力チェックの直後・CSV を読み直す前に `OneTimeAction::claimFrom($request, 'import_token')` で使う（`claimFrom()` に hidden の名前の引数を足した。既定は `guide_token` のままで、決裁の 5 か所は変えない）。使えなければ取込の画面へ戻して「この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。…」。0 件の歯止めの案内はチェックを勧めない文にした。画面は Alpine の `onSubmit()` で 2 回目の送信を取り消し、送信中はボタンを `:disabled` にして Tailwind の `disabled:` で見た目を変え（`:style` にしない＝Top trap #5・`cursor` は style でなくクラス）、ボタンの横の `role="status"` に「取り込んでいます…」（`title` は使わない＝Top trap #12。`inline-block` にしないと 375px で文字の途中で割れた）。`pageshow`（`window`・`persisted` で絞らない＝Bug #65）で印を下ろす。⚠ **Chromium は POST の確認画面を bfcache に入れない** — 「戻る」は手元の控えから描き直す（POST は送り直さない・Alpine は最初から＝ボタンは押せる・鍵は使用済みのまま）ので、止めるのは画面の印でなくサーバの鍵（実ブラウザで、押すと鍵の案内・件数は増えないことを実測）。⚠ **0 件の歯止めより前ならどこに鍵を置いても同じ**（変異 K04 は等価）— 守る位置は「0 件の歯止めより前」で、`test_sending_the_same_confirmation_twice_imports_once` の「2 回目は 0 件の案内に着かない」が固定する（書き込みの直前へ動かす K02 はこの 1 本だけが落とす）。⚠ Playwright の `page.evaluate` は画面の移り変わりの途中は答えない — 送信中の見た目は、画面の中の見張り（MutationObserver → localStorage）で記録して移ったあとで読んだ。⚠ 範囲外: 送信を中止（Esc など）すると、押せないボタンと「取り込んでいます…」が残る（実測）・12 時間（決裁の鍵の設定を共用）より古い確認画面は鍵で止まらない・ほかの取込（テナント・賃貸マンション・ZEAL 会員・工程表・周辺ビル）は未対応。回帰テスト: `tests/Feature/Admin/CustomerImportTest.php`（20 → 29 本。同じフォームを 2 回送る往復・鍵が無い／空／配列・プレビューのあとに同じ人が登録された・確定のフォームとボタンの構造・画面の `<script>` を node の vm で動かす）。変異 29 通り＋カナリアを全件で当て、等価の K04 を除いてすべて期待どおり（計画書 `docs/superpowers/plans/2026-09-27-customer-import-double-submit.md` の実測記録）。本番反映は `./deploy.sh`（DB 変更・新しい PHP クラスなし。CSS の名前が変わる） |

## Postal Code APIs
```

- [ ] **Step 3: `CLAUDE.md` の件数**（Edit を 2 回）

```text
全 66 件の詳細バグカタログ   →   全 67 件の詳細バグカタログ
Bug #1–66                    →   Bug #1–67
```

- [ ] **Step 4: `docs/BACKLOG.md`**（Edit を 3 回）

1 回目（「顧客CSVインポートの確定を通す」の範囲外の二重送信の行）— 置き換える前:

```text
1 回だけ送らせる仕組み（決裁の取込の `OneTimeAction` が前例）と文言の見直しは別の作業。兄弟の取込も同じ形
```

置き換えた後:

```text
1 回だけ送らせる仕組み（決裁の取込の `OneTimeAction` が前例）と文言の見直しは別の作業。兄弟の取込も同じ形（→ 2026-09-27 に顧客の取込は直した。下の「顧客CSVインポートの二重送信を止める」の節）
```

2 回目（この作業の節を足す）— 置き換える前:

```markdown
⚠ `origin/13.x` への push はしていない。→ 2026-09-27 に push 済み（`f98dc15e` に含まれる）。

---

## バックログ完了状況
```

置き換えた後:

```markdown
⚠ `origin/13.x` への push はしていない。→ 2026-09-27 に push 済み（`f98dc15e` に含まれる）。

---

## ✅ 顧客CSVインポートの二重送信を止める — 本番反映待ち

詳細仕様: @docs/superpowers/specs/2026-09-27-customer-import-double-submit-design.md
実装計画（試作・変異テスト・ブラウザ確認の記録つき）: @docs/superpowers/plans/2026-09-27-customer-import-double-submit.md

上の「顧客CSVインポートの確定を通す」の範囲外に残した「二重送信を 1 回だけにする仕組みと、2 回目に出る文言」を直した（docs/RULES.md Bug #67）。
利用者がこれから本番で実際の顧客 CSV を取り込むので、その操作そのものの安全を先に固めた（利用者の判断: 2026-09-27・案 A＝サーバの 1 回限りの鍵 ＋ 画面の二度押し止め）。
**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（`composer dump-autoload` は要らない）。

| 区分 | 実装内容 |
|------|---------|
| Support | `OneTimeAction::claimFrom()` に hidden の名前の引数（既定は `guide_token` のまま＝決裁の 5 か所は変えない）|
| Controller | `Admin\CustomerImportController` — プレビューで鍵を発行・確定では部署の入力チェックの直後に鍵を使う・0 件の案内を直す |
| Blade | `admin/customers/import.blade.php` — hidden の `import_token`・二度押し止め（`submitting`・`:disabled`・`role="status"`・`pageshow`）|
| テスト | `tests/Feature/Admin/CustomerImportTest.php`（20 → 29 本）。2346 → **2355 tests / 15346 assertions green** |
| ルート / DB | **どちらも変更なし** |

### 直したこと

| 場面 | 直す前（試作で実測）| 直した後 |
|---|---|---|
| チェック済みの確定を同じ確認画面から 2 回 | 2 回目も「2件のインポートが完了しました。」が出て全員がもう一度入る（山田 3 人・佐藤 2 人）| 2 回目は「この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。…」。取り込みは 1 回 |
| チェックなしの確定を 2 回 | 2 回目は「…「重複候補もインポートする」にチェックを入れてください。」に着き、従うと全員がもう一度入る | 同上（0 件の案内には着かない）|
| プレビューのあとに同じ人が登録された | 「…チェックを入れてください。」| 「取り込める行がありません。プレビューのあとに、同じ人が登録された可能性があります。CSVをアップロードし直して、重複候補を確かめてください。」|
| ダブルクリック | —（画面の歯止めが無かった）| 確定の POST は 1 回（実ブラウザ）|
| 送信中 | 何も変わらない | ボタンが押せなくなり（不透明度 0.6・カーソル not-allowed）、横に「取り込んでいます…」 |
| Chrome の「戻る」で出る確認画面から押す | 2 回目と同じ | 鍵の案内・件数は増えない（実ブラウザ）|

### 要点

- 鍵は部署の入力チェックの直後・CSV を読み直す前に使う。**0 件の歯止めより後ろに置くと**、2 回目は 1 回目で入った人を重複候補と数えて 0 件の案内に着く（決裁の取込は書き込みの直前に置いているが、顧客の取込では通用しない）
- 「確認画面 1 つにつき、送信は 1 回」。断られたあとは同じ確認画面から送り直せないので、CSV をアップロードし直す（断られると取込の画面に戻るので、画面の上ではふつうの操作と同じ）。デプロイの前に開いた確認画面（鍵が無い）も断られる
- 画面の歯止め（Alpine）が効かないとき（JS が動かない・Alpine の起動前）も、サーバの鍵が止める
- ⚠ Chromium は POST の確認画面を bfcache に入れない。「戻る」は控えから描き直し（POST は送り直さない）、使用済みの鍵のまま押せる → サーバが断る。`pageshow` で印を下ろすのは bfcache に入れるブラウザのため（合成の `pageshow` で確かめた）
- 本番のキャッシュは file ドライバで、`FileStore::add()` が排他ロックを取ってから書くので、ほぼ同時の 2 回でも片方しか通らない（2026-09-25 に本番で確かめた。`OneTimeAction` の docblock）

### 範囲外（気づいたが直していない。設計書 §6 ＋ 試作で気づいたもの）

- ほかの取込（テナント・賃貸マンション・ZEAL 会員・工程表・周辺ビル）の二重送信（決裁の取込は対策済み。ほかは未実測）
- 都道府県か市区町村が空欄の行が重複候補にならない件（前回からの範囲外・未実測）
- 書き込みに失敗したあと、同じ確認画面から送り直すこと（アップロードし直す）
- 12 時間（`config('approval.guide_token_ttl_hours')` を共用）より古い確認画面からの送り直し（鍵の記録が消えたあとは重複の確認に頼る）
- アップロード（プレビュー）の二度押し（DB に書き込まないので害が無い）
- 画面の歯止めが効かないとき（JS が動かない・Alpine の起動前）の 2 回目の応答で、1 回目の完了の表示が見えないこと
- 送信を中止（Esc など）すると、押せないボタンと「取り込んでいます…」が残る（試作で実測。ページを開き直すか、アップロードし直す）
- `LoginGuideTest::test_the_token_is_claimed_atomically` の docblock が本番のキャッシュを database と書いている（本番は file。`OneTimeAction` の docblock は 2026-09-25 に直してある）
- 実ブラウザの確認は Chromium（Playwright）だけ。POST の確認画面を bfcache に入れるブラウザ（Safari・Firefox かどうかは未確認）での「戻る」は、合成の `pageshow` で代わりに確かめた

### 検証

- 全件テスト 2346 → **2355 tests / 15346 assertions green** ／ コンパイル済みビュー **274 本 / INVALID 0 件**
- 変異 29 通り＋カナリア（計画の Task 4。隔離した worktree 3 つ・全件で流す）: （未記入）
- ローカルの実ブラウザ（計画の Task 5。使い捨て SQLite ＋ `artisan serve` ＋ Playwright）: （未記入）
- 独立レビュー（計画の Task 6）: （未記入）

---

## バックログ完了状況
```

3 回目（「バックログ完了状況」の末尾の段落）— 置き換える前:

```text
その他の新規要件は別途追記する。
```

置き換えた後（Task 8 のあとで「本番反映済み（`13.x` = …）」に直す）:

```text
**顧客CSVインポートの二重送信を止める（2026-09-27）**は本番反映待ち（上の節）。

その他の新規要件は別途追記する。
```

- [ ] **Step 5: この計画の「実測記録」を埋める**（Task 0・1・3・4・5・6 の結果。Task 8 の小節は Task 8 で埋める）。BACKLOG の節の「検証」の（未記入）3 か所も実測で埋める

- [ ] **Step 6: 書き残しが無いことを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && grep -n -e '（未記入）' docs/RULES.md CLAUDE.md docs/BACKLOG.md; sed -n '/^## 実測記録/,$p' docs/superpowers/plans/2026-09-27-customer-import-double-submit.md | grep -c -e '（未記入）'
```

期待: 1 つ目の `grep` は何も出さない。2 つ目は Task 8 の小節の 1 か所だけ（`1`）。Task 8 の後は `0`。

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && git add docs/RULES.md CLAUDE.md docs/BACKLOG.md docs/superpowers/plans/2026-09-27-customer-import-double-submit.md && git commit -m "$(cat <<'EOF'
docs: 顧客CSVインポートの二重送信を止めた記録を残す

RULES に Bug #67（同じ確認画面からの 2 回目で全員がもう一度入ること・鍵を置く位置・
Chromium の「戻る」の実際）を足し、BACKLOG にこの作業の節と範囲外を足す。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 8: 本番反映（⚠ 承認をもらってから）

**Files:** なし（`13.x` の早送り・本番への反映・読み取りの確認）

⚠ 実行の前に、利用者の承認を本文でもらう（①main repo で `13.x` を早送り ②`./deploy.sh` ③読み取りだけの確認（ssh での md5 とコンパイル済みビューの lint・本番の CSS の取得・ログイン済みの実 Chrome で取込の画面を開く）。DB 変更・ルート変更・新しい PHP クラス・依存の変更は無い＝`composer dump-autoload` は要らない。push はしない）。

- [ ] **Step 1: 早送り**（main repo で）

```bash
cd /Users/masanori/site/manage && git status --porcelain && git checkout 13.x && git merge-base --is-ancestor 13.x customer-import-double-submit && git merge --ff-only customer-import-double-submit && git log --oneline -1
```

⚠ `is-ancestor` が失敗したら（`13.x` が進んだ）止める。worktree で `13.x` をマージし（Task 0 の手順）、全件テスト（Task 3）を流してからやり直す。取り込むコミットに本番へまだ出ていない別の作業（`approval-phase2a` の DB の SQL など）が含まれるときは、それも一緒に出てよいか利用者に確かめる（`deploy.sh` は `13.x` の全体を送る）。

- [ ] **Step 2: 本番へ送る vendor に dev の部品が無いこと**

```bash
cd /Users/masanori/site/manage && ls vendor/bin/phpunit 2>/dev/null; echo "phpunit-check-done"
```

期待: `ls` は何も出さない（dev の部品が無い）。出たら止める（`deploy.sh` が本番へ送ってしまう）。

- [ ] **Step 3: 反映**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

期待: exit 0・6 段すべて・3 つのキャッシュとも成功。送るアプリのファイルはコントローラ・`OneTimeAction.php`・ビューの 3 本と、ビルドした CSS（名前が変わる。試作では `app-D-wd4D2y.css`）と `manifest.json`。旧 CSS（`app-wzJ6Tjji.css`）は `public/build/` の `--delete` で消える。

- [ ] **Step 4: 読み取りだけの確認**

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && md5 -q app/Support/OneTimeAction.php app/Http/Controllers/Admin/CustomerImportController.php resources/views/admin/customers/import.blade.php && n=0; bad=0; for f in storage/framework/views/*.php; do n=$((n+1)); /usr/local/php/8.3/bin/php -l "$f" >/dev/null 2>&1 || { bad=$((bad+1)); echo "INVALID: $f"; }; done; echo "views=$n invalid=$bad"
SH
cd /Users/masanori/site/manage && md5 -q app/Support/OneTimeAction.php app/Http/Controllers/Admin/CustomerImportController.php resources/views/admin/customers/import.blade.php
```

期待: 本番と手元の md5 が 3 つとも一致・`views=274 invalid=0`

本番の CSS（ログイン不要で取れる）:

```bash
CSS=$(python3 -c "import json;print(json.load(open('/Users/masanori/site/manage/public/build/manifest.json'))['resources/css/app.css']['file'])"); echo "$CSS"; curl -s "https://www.mitsuwat.co.jp/system/manage/build/$CSS" | grep -oF -e '.disabled\:opacity-60:disabled{opacity:.6}' -e '.disabled\:cursor-not-allowed:disabled{cursor:not-allowed}'; curl -s -o /dev/null -w '%{http_code} %{content_type}\n' https://www.mitsuwat.co.jp/system/manage/build/assets/app-wzJ6Tjji.css
```

期待: 新しい CSS の名前・2 つのルールがそれぞれ 1 行ずつ出る・旧 CSS は `text/css` で返らない（404 か `text/html`）。

- ログイン済みの実 Chrome（`claude-in-chrome`・読み取りだけ。ファイルは上げない・フォームは送らない）で `https://www.mitsuwat.co.jp/system/manage/index.php/admin/customers/import` を開き、初期フォームが出ること・コンソールのエラー 0 件を見る（⚠ URL は `/index.php/` を挟む）。確定の欄の二度押し止めと鍵は、プレビュー（ファイルを上げる）の後にしか出ないので本番では見ない（テストとローカルの実ブラウザで確かめてある）。本番で実際に取り込むのは利用者が行う

- [ ] **Step 5: 記録**（worktree で BACKLOG の節の見出しを「本番反映済み」にし、本番反映の小節（日時・`13.x` のコミット・Step 2〜4 の結果）を足し、完了状況の段落を「本番反映済み（`13.x` = …）」に直し、この計画の「実測記録」の Task 8 を埋めてコミットする。そのあと main repo で `git merge --ff-only customer-import-double-submit`（文書だけなので `deploy.sh` は要らない。`docs/` は rsync しない））

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-double-submit && git add docs/BACKLOG.md docs/superpowers/plans/2026-09-27-customer-import-double-submit.md && git commit -m "$(cat <<'EOF'
docs: 顧客CSVインポートの二重送信を止めた改修を本番に出した記録を残す

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain && cd /Users/masanori/site/manage && git merge --ff-only customer-import-double-submit && git log --oneline -1
```

⚠ push はしない（利用者の指示があったときだけ）。

---

## 範囲外（BACKLOG に書く。設計書 §6 ＋ 試作で気づいたもの）

- ほかの取込（テナント・賃貸マンション・ZEAL 会員・工程表・周辺ビル）の二重送信（決裁の取込は対策済み。ほかは未実測）
- 都道府県か市区町村が空欄の行が重複候補にならない件（設計書 §2.6。前回からの範囲外・未実測）
- 書き込みに失敗したあと、同じ確認画面から送り直すこと（アップロードし直す）
- 12 時間より古い確認画面からの送り直し（鍵の記録が消えたあとは重複の確認に頼る）
- アップロード（プレビュー）の二度押し（DB に書き込まないので害が無い）
- 画面の歯止めが効かないとき（JS が動かない・Alpine の起動前）の 2 回目の応答で、1 回目の完了の表示が見えないこと
- 送信を中止（Esc など）すると、押せないボタンと「取り込んでいます…」が残る（試作で実測。直すなら「一定時間で印を下ろす」などの別の設計が要る）
- `LoginGuideTest::test_the_token_is_claimed_atomically` の docblock が本番のキャッシュを database と書いている（本番は file）
- POST の確認画面を bfcache に入れるブラウザでの「戻る」の実機確認（Chromium では起きないので、合成の `pageshow` で代わりに確かめる）

## 完了の条件

- 全件テストが緑（`OK (2355 tests, 15346 assertions)` 前後。違えば理由を記録）・コンパイル済みビュー INVALID 0 件
- 変異 29 通り＋カナリアがすべて期待どおり（等価の K04 は緑。違ったものは理由を調べて対応し、記録した）
- ローカルの実ブラウザの 10 項目がすべて期待どおり
- 独立レビューの指摘に、実測のうえで対応した
- RULES・CLAUDE.md・BACKLOG・この計画に記録した
- （承認のあと）本番に反映し、読み取りで確かめた

---

## 実測記録

### 試作の先測り（2026-09-27。この計画を書くときに測った）

上の「試作で確かめたこと」の表のとおり。写しは scratchpad の `cids-proto-fdd7316c`（`git archive fdd7316c`＋vendor の実体コピー）とブラウザ用の `cids-browser-d9cfb08`。変異の先測りは関係するテスト 10 本に絞り、29 通り＋カナリアを当てた（C0 23 本・K01 5・K02 1・K03 5・K04 0・K05 1・K06 18・K07 5・K08 2・K09 18・K10 45・K11 5・U01 22・U02〜U14 各 1・J01〜J04 各 1）。

### Task 0: 前提の確認

（未記入）

### Task 1: サーバの 1 回限りの鍵

（未記入）

### Task 3: 全件テストと lint

（未記入）

### Task 4: 変異テスト

2026-09-27 19:00〜19:25（日本時間）。`0df5c0f2`（Task 2 のコミット）を `git worktree add --detach` した 3 つのコピー（vendor は `cp -Rc` で実体コピー）で、どれも全件テストを流した。

- Step 3: `--check` は「変異 30 通り・当たらないもの 0 件」・3 つのコピーとも作業ツリーは空
- Step 4 カナリア C0: a・b・c とも 23 本（確定を送る `CustomerImportTest` 22 本「Failed asserting that '<!DOCTYPE html>…」＋ `ImportValidationFeedbackTest`「顧客CSV」「…差し戻し先 /admin/customers/import に理由が…」）。3 つの集合が一致＝コピー側のコードが読まれている
- Step 5・6: **29 通りすべてが表の期待どおり**（落ちたテストの集合・本数・理由の 1 行目まで）。等価の K04 は 0 本。期待と違ったものは無く、テストは足していない。変異の前後で作業ツリーが空・置き換えがちょうど 1 か所・着弾は実行役がすべて確かめた（止まらずに最後まで流れた）

| ID | 本数 | 落ちたテスト（理由の 1 行目）|
|---|---|---|
| C0 | 23 | 上のとおり |
| K01 | 5 | T1・T4 の 3（`Failed asserting that '<!DOCTYPE html>…`）・T2（`2 回目の送信で、重複候補がもう一度入った`）|
| K02 | 1 | T1（`Failed asserting that '<!DOCTYPE html>…`＝2 回目が 0 件の案内に着く）|
| K03 | 5 | T1（`'<!DOCTYPE html>…`）・T2（`もう一度入った`）・T4 の 3（`Failed asserting that 1 is identical to 0.`＝断る前に書いた）|
| K04 | 0 | （等価）|
| K05 | 1 | T3（`プレビューごとに鍵が変わっていない`）|
| K06 | 18 | 確定を送る 22 本から T4 の 3・部署を書き換える 1 本を除く全部（`'<!DOCTYPE html>…`）|
| K07 | 5 | T1・T2・T4 の 3（`'<!DOCTYPE html>…`）|
| K08 | 2 | `test_confirming_only_duplicates_without_the_check_imports_nothing`・T5（`'<!DOCTYPE html>…`）|
| K09 | 18 | K06 と同じ集合 |
| K10 | 45 | 顧客は 0 本。`LoginGuideTest` 5（`有効時間 0 で 1 回目から弾かれている`・`正しい文字列トークンの 1 回目が通っていない`・`Failed asserting that false is true.` ×3）・`ApprovalUserImportTest` 15・`ApprovalUserManagementTest` 13・`UserManagementApprovalTest` 12（決裁の 40 本のうち 37 本が `Expected response status code [200] but received 302.`）|
| K11 | 5 | T4 の 3（`確定の応答が転送になっていない`＝500）・`LoginGuideTest::test_claim_from_accepts_only_a_string_token`（`TypeError: App\Support\OneTimeAction::claim(): Argument #1 ($token) must be of type string, array given…`）・`ApprovalUserImportTest::test_an_array_guide_token_is_refused_without_a_500`（`… but received 500.`）|
| U01 | 22 | 確定のフォームを分解する 22 本（`確定のフォームに 1 回限りの鍵（import_token）が無い`）|
| U02〜U06 | 各 1 | T6（`Failed asserting that '<form method="POST" action="http://localhost/admin/customers/import"…`。記録は 1 行目だけだが、U05・U06 は `x-on:submit` に触れないので、落ちたのは `x-on:pageshow.window` の contains）|
| U07・U08 | 各 1 | T6（`Failed asserting that '<button type="submit"…` が `:disabled="submitting"` を含まない）|
| U09 | 1 | T6（`「インポート実行」ボタンに disabled:opacity-60 が無い`）|
| U10 | 1 | T6（`Failed asserting that 'background: #059669; …; cursor: pointer;'…`＝style に cursor がある）|
| U11・U12 | 各 1 | T6（`ボタンを包む要素の中に role="status" が無い`）|
| U13 | 1 | T6（`'<span role="status" x-text="submitting ? '送信中…' : ''"…` が期待の `x-text` を含まない）|
| U14 | 1 | T6（`'margin-left: 12px; font-size: 13px; color: #374151;'` が `display: inline-block` を含まない）|
| J01〜J04 | 各 1 | T7（`Failed asserting that two arrays are identical.`）|

- Task 5（ローカルの実ブラウザ）は、変異の実行と並行して元の worktree と使い捨ての SQLite で行った（変異は隔離したコピーの中だけで動くので干渉しない。Task 5 の一時的なルートは変異のコピーに含まれない）
- 片づけ: 3 つのコピーを `git worktree remove --force` で消した（一覧は main repo・`approval-phase2a`・この worktree の 3 つ）。記録の jsonl は scratchpad に残した

### Task 5: ローカルの実ブラウザ

（未記入）

### Task 6: 独立レビュー

（未記入）

### Task 8: 本番反映

（未記入）
