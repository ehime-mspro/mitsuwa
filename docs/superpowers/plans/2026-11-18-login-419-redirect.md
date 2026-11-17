# ログイン画面の 419 を行き止まりにしない（案 A）

## Context（なぜ直すか）

2026-11-18 の深夜、利用者が本番でログインできなくなった（しばらくして直った）。手元の使い捨ての環境で再現できた:

1. ログイン画面を開いたまま、セッションが切れる（本番の有効期限は 2 時間。ほかの画面でログアウトしても同じ）
2. 送る → 419「画面の有効期限が切れました」（11/17 に本番へ出した日本語の画面。Bug #137）
3. 緑のボタン「前の画面に戻る」（`history.back()`）→ ブラウザが**古いログイン画面をそのまま**出す（鍵は 1 と同じ古いもの）
4. もう一度送る → また 419（何度やっても同じ）。「トップページへ」か開き直すと直る

本番は読むだけで確かめた: ログイン画面を 2 回読むと鍵が同じ＝セッションの保存は正常。

利用者の決定（2026-11-18・案 A）: **ログインの送信だけは 419 の画面を出さず、新しいログイン画面へ戻して理由を出す**。ほかの画面の 419 は今のまま（Bug #137 の日本語の画面）。

## 作業場所

13.x（`4c4c3a05`）から新しい作業場所 `.claude/worktrees/login-419-redirect`（枝 `login-419-redirect`）を作る（`git worktree add` → `EnterWorktree(path)` → vendor を `cp -a` → `composer install --no-scripts --no-interaction`）。
GET の Ajax の枝 `get-ajax-failure`（未マージ）とは別にし、こちらだけ先に本番へ出せるようにする。
⚠ どちらも `docs/RULES.md` の末尾の行と `docs/BACKLOG.md` の同じ場所を変えるので、あとに 13.x へ入れるほうで衝突を直す（両方を残す）。

## 直し方

### `bootstrap/app.php`（`HttpException` の render、今の 419 の JSON の分岐のすぐあと）

```php
// JSON を求める要求は今の分岐（日本語の JSON）が先に返す。
if ($e->getStatusCode() === 419 && $request->isMethod('POST') && $request->is('login')) {
    // ⚠ 文字でない値（手で組んだ login_id[]=x）は控えない。ログイン画面は old('login_id') を {{ }} で描くので 500 になる（Bug #121）
    $input = array_filter($request->only('login_id', 'remember'), 'is_string');
    return redirect()->route('login')
        ->withInput($input)            // パスワードは控えない
        ->withErrors(['login' => \App\Http\Controllers\Auth\AuthController::EXPIRED_PAGE_MESSAGE]);
}
```

- 鍵の確かめ（web グループ）は `guest`・`throttle:login`（ルートのミドルウェア）より前に走るので、失敗の回数には数えない（今と同じ）
- 戻った GET は新しい鍵の画面を描くので、そのまま送ればログインできる
- ほかの画面でログインした直後の古いログイン画面から送った場合は、戻った `/login` を `guest` がホームへ送る（今の動きのまま）
- 注記を書き換える: `bootstrap/app.php` の 419 の説明・`resources/views/errors/419.blade.php` の先頭の注記（「ログイン画面を開いたまま…も出る」→ ログインの送信には出ない）

### `app/Http/Controllers/Auth/AuthController.php`

`public const EXPIRED_PAGE_MESSAGE = '画面を開いてから時間がたったか、ほかの画面でログアウトしたため、ログインできませんでした。もう一度ログインしてください。';`
（前例: `EnsureUserIsActive::MESSAGE`。赤い帯 `$errors->has('login')` に出る。ほかの 419 の文と同じ言い方にそろえた）

## テスト（`tests/Feature/ExpiredSessionResponseTest.php`。先に赤を確かめてから直す）

鍵は `EnforcesCsrfToken::enforceCsrfToken()` で本物の 419 を出す。フォームは `ParsesForms::parseForm()` で描いた画面から分解して送る（Bug #47）。

1. 今の `test_the_login_form_with_an_expired_page_shows_the_same_page` を書き換える: 古い鍵で送る → `route('login')` へ 302 → 着いた画面に赤い帯の `<span>` の全文・ID は残る・パスワードは控えに無い・「ログイン状態を保持」は残る・まだログインしていない
2. 往復: 戻った画面のフォームをそのまま（正しいパスワードで）送ると、ホームへ着いてログインできる
3. `login_id[]=x` の古い鍵の送信でも、戻った画面が 200（ID は空）
4. JSON を求める古い鍵のログインは、今の日本語の JSON の 419 のまま（分岐の順番を固定）
5. ほかのフォーム（パスワードの変更）は今の 419 の画面のまま（今あるテストをそのまま残す）

## わざと壊して確かめる（変異。コミットしてから・落ちたテストの組と理由まで見る）

ログインの分岐を消す／JSON の分岐より前へ動かす／`is_string` の絞りを外す／パスワードも控える／ID を控えない／文を変える／`withErrors` のキーを `login_id` にする（赤い帯に出ない）／戻り先を `url('/')` にする ＋ カナリア。道具は今までどおり worktree の `.claude/route-coverage-tools/mutate/`（表と結果は、作業場所を消す前に main repo へ写す）。

## 記録

- `docs/RULES.md` に Bug #138（症状・原因・直し方・テスト）／ `CLAUDE.md` の件数 137 → 138（2 か所）
- `docs/BACKLOG.md` に節（直す前の再現・直したこと・検証・範囲外）
- 計画を `docs/superpowers/plans/2026-11-18-login-419-redirect.md` に写す
- 範囲外: ほかの画面の 419 の「前の画面に戻る」で古い鍵の画面に戻ること（文で案内している・Bug #137 の後回しのまま）・ログアウトの送信の 419（「トップページへ」でログイン画面に着く）

## 確かめ方

- 全件テスト（CLAUDE.md の流し方・`--display-phpunit-deprecations`）・コンパイル済みビューの lint
- 実ブラウザ（使い捨ての環境・Playwright）: 今朝の再現手順をそのまま流し、②で 419 の画面でなく「赤い帯の文つきのログイン画面」に着き、ID が残り、パスワードを入れて送ればログインできること／375px でも帯が枠に収まること
- 本番へは、利用者の別々の指示で 13.x へ早送り → `./deploy.sh`（DB・ルート・新しいクラス・依存の変更なし）。反映のあとは ssh なしの確かめ（`curl -X POST -d '' …/index.php/login` が 419 の画面でなくログイン画面への 302 になる）
