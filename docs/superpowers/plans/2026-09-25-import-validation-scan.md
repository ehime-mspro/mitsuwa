# 取込の入力チェックの包み忘れを走査テストで止める — 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 取込のコントローラ（`app/Http/Controllers` 配下の `*ImportController.php`）の入力チェックが「断られたときの戻り先を固定して投げ直す」形で包まれていることを、テストで全件分類して止める。本番のコード（`app/`・`resources/`・`routes/`）は 1 行も変えない。

**Architecture:** 新しい走査テスト `ImportControllerValidationRedirectScanTest` が `token_get_all()` のトークンでコントローラを読み、入力チェックの例外を投げる呼び出しごとに包み方（囲む try の catch か、投げる文そのものの `->redirectTo(`）を判定する。FormRequest は Reflection で探す。列挙は新しいトレイト `tests/Concerns/ScansImportControllers.php` に切り出し、既存の `ImportControllerReturnPathScanTest` と同じ集合を見る。既存の走査には「今の URL」を作る書き方の検出を足す。

**Tech Stack:** PHP 8.3 / Laravel 12 / PHPUnit 11.5（`token_get_all()`・Reflection・`#[DataProvider]`）／ Python 3（変異テストの実行役）

**設計書（承認済み）:** @docs/superpowers/specs/2026-09-25-import-validation-scan-design.md — この計画は §4（設計）と §5（検証）をそのまま実装する。

---

## Context

- 取込の確認画面は POST の応答で、その URL は多くが POST 専用。そこから送って入力チェックで断られると、既定の戻り先はリファラー＝その URL で、GET で開いて **405**（docs/RULES.md Bug #64）。2026-09-24 に 4 本を直し、`ImportControllerReturnPathScanTest` が `back(` などの書き方を数えて止めるようにした
- ただし**入力チェックの既定の戻り先は、コードに `back(` が現れないので走査に見えない**。今の 4 本は挙動のテストが守るが、新しい取込には守り手がいない（2026-09-24 のレビューの M4 の提案）
- 対話で決まったこと（2026-09-25・設計書 §1）: 守る範囲は**取込のコントローラだけ**（案 1。アプリ全体の安全網＝案 2 は別の作業）／既存の走査の穴（今の URL へ戻す形）も今回塞ぐ／確かめ方は**ソースを読む**（実際に送る案・両方の案は採らない）

## 試作で確かめたこと（2026-09-25。scratchpad の中だけで行い、worktree には触れていない）

計画に載せるコードは、**書いてから試作として実物に当てた**もの（手で写さず、試作のファイルから機械的に切り出して、組み立て直すと試作と 1 文字違わず一致することまで確かめた）。

| 確かめたこと | 実測 |
|---|---|
| 実物の 8 本に当てた新しい走査 | 投げる呼び出し **10**（すべて `->validate(`）・包んである **7**（設計書 §2.1 の行と一致）・包んでいない **3**（顧客 `:28`・周辺ビル `:85`・経営試算表の URL 保存 `:58`。どれも「try の外」）。解析できないファイル 0 |
| 既存の走査に足す「今の URL」の検出 | 実物の 8 本には 0 件（例外リストの件数は顧客 2・周辺ビル 2 のまま）|
| Task 3 の赤（見本だけ足す）| `Tests: 4, Assertions: 28, Failures: 1.` 理由「拾えていない: return redirect(Request::url());」|
| Task 3 の緑 | `OK (4 tests, 68 assertions)`（今は 39 assertions）|
| Task 4 の赤（骨組み・解析は空）| `Tests: 75, Assertions: 75, Failures: 64.`（拾わない見本の 11 本だけ緑）|
| Task 5 の緑 | `OK (75 tests, 113 assertions)` |
| Task 6 の赤（例外リストが空）| `Tests: 78, Assertions: 139, Failures: 1.` 3 本を行番号つきで名指し |
| Task 6 の緑 | `OK (78 tests, 139 assertions)` |
| 全件の見込み | **2247 tests / 14735 assertions**（今 2169 / 14567。+78 本、assertions は +139 と既存の走査の +29）|
| テスト側の変異 46 通りを試作に当てた | **1 回目は X12（catch の本体が `throw` で始まるかの判定を外す）だけが緑**。見本が「`return $e->redirectTo(…)`」を持っていなかった。同じ目で分かれ目を洗い直し、下の見本を足してから当て直すと **46 通りすべて赤** |
| 本番のコードに当てる変異 18 通り＋カナリア | 置き換えがどれもちょうど 1 か所。新しい走査・既存の走査が出す文言を、ファイルを書き換えずにメモリの上で当てて確かめた（下の Task 7 の表）|

### 設計書との違い（試作で決めたこと。どれも設計書の方針の範囲）

| # | 事柄 | 決めたこと | 理由 |
|---|---|---|---|
| 1 | 「まとめた use」の範囲 | `use A\{B, C};` と `use A\B, C\D;` の**両方**を解析できないとする | どちらも 1 文で 2 つ以上を読み込む。決まった手順で解決はできるが（ここに「推測することになる」と書いていたのは不正確。レビュー M-8）、走査はその読み方を持たないので、読み違えて包み方を誤判定するより落とす（安全側）|
| 2 | 解析できない書き方を 1 つ足す | 冒頭（波括弧の外）の `use` の後に名前が続かない（冒頭に書いたクロージャなど）→「読めない use」 | 推測しない方針（設計書 §4.3）。実物には無い |
| 3 | クラス名の大文字小文字 | メソッド名と同じく**区別しない**（`catch (\illuminate\validation\validationexception $e)` も受け止めるとみなす）| PHP と同じ規則 |
| 4 | 設計書 §4.8 に無い見本を 12 足した | 本体が `return $e->redirectTo(…)`／別のメソッドで投げ直す／`redirectTo` が呼び出しでない／内側の catch で決める／クロージャの `use`／`use function`・`use const`／先頭に `\` のある `use`／修飾名の catch（`Validation\ValidationException`）／`redirectTo` の引数の中の `;`／次の文の `->redirectTo(` は続いたことにしない／冒頭のクロージャの `use`／`new` の見本に `use \…` | 試作に変異を当てて、見本の無い分かれ目を見つけたため（Bug #61 ③「見本の無い分かれ目は消しても緑」）|
| 5 | 届かない守りを持たない | try・catch の後の `{` の有無の確認・`as` の後の確認は書かない | 正しい PHP では必ず成り立つ（見本の無い分かれ目を作らない）。⚠ ここには「属性 `#[` は文の中に現れず、括弧の数え方は書かない」とも書いていたが誤りだった（レビュー M-8。クロージャ・アロー関数・無名クラスとその引数に付けられる）。`redirectTo` の引数の中の属性つきクロージャで深さがずれて不合格になったので、`#[` を括弧の開きとして数え、見本を置いた（`7ee185aa`）|
| 6 | 変異の数 | 本番のコード 18＋カナリア 1（全件で流す）・テスト 46（走査の 2 本だけで流す）＝ **65 通り**（設計書 §5.2 の見込み 30〜40 より多い）。レビューの後に最後のコードへ当て直した 2 回目は、本番のコード 18＋カナリア・テスト 73＋カナリア | テスト側は分かれ目ごとに 1 つずつ当てるため。テスト側は 1 通り数秒で済む（下の「テスト側の変異を 2 本だけで流す理由」）|
| 7 | B（投げる文に `->redirectTo(` が続く）の範囲 | 例外を作る呼び出し（`::withMessages(`・`new ValidationException`）が `throw` の直後（括弧で括ってもよい）にあり、その後ろの**メソッドの連鎖**に `->redirectTo(` がある形に限る（`?->redirectTo(` は数えない）。`->validate(`・`::validate(` などは B では見ない（`b3529b06`・`4b605a0b`）| 設計書 §4.4 の「同じ文の中で呼び出しより後ろに `->redirectTo(` が続く」では、`withMessages()` の引数の中の入力チェックや、`match` の腕・`??` の隣の式の `->redirectTo(` まで包んであるとみなした（レビュー M-1。直す前と後の実測は下の「独立レビュー」）|
| 8 | 検出する形 | 設計書 §4.3 の 7 形に `->safe(`・`->validateWith(`・`::validateWithBag(` を足した（`25fadf7e`）| どれも既定の戻り先がリファラーの例外を投げる（レビュー M-4）|
| 9 | 別の ValidationException を受けたときの理由 | 「（use の無い ValidationException を受けている）」をやめ、書いた名前でなく解決した名前で判定して「（Illuminate\Validation\ValidationException でない ValidationException を受けている）」にした（`7ee185aa`）| `use App\Exceptions\ValidationException;` のように use があっても「use の無い」と出ていた（レビュー M-8）|
| 10 | 既存の走査の「リファラー」 | `url()->previousPath()`・`URL::previousPath()` も拾う（`d8da2211`）| `previousPath()` もリファラーから作る。2 本の走査とも緑のまま 405 になる形だった（レビュー I-1）|
| 11 | 見えないもの・拾いすぎるもの | 設計書 §4.9 に加えて、`redirectTo()` に渡す値が null になりうる形・外側の総称の catch が投げ直しを受け止める形・コンテナから作る FormRequest・例外リストを件数で見ること、拾いすぎとして投げ直しを括弧で括った形を docblock に書いた（`2485e6b3`・`b61f6995`）。既存の走査にも、呼び出し元が `$request` でない形・大文字小文字の違い・別名やコンテナから取る形・`REQUEST_URI`・今のルート名へ戻す形と、記録やビューに渡す `path()`・`current()` の拾いすぎを書いた（`251dae2c`）| レビュー M-2・M-3・M-4・M-5・M-6・M-8 で、直さずに記録すると決めたもの（理由は下の「独立レビュー」）|

⚠ **内側の catch で決める規則は厳しめ**（設計書 §4.4 のとおり）。内側の catch が `throw $e;` と投げ直し、外側の catch がそれを受けて `redirectTo()` する形は、実行すると取込の画面へ戻るが、走査は不合格にする（見本「内側の try の catch で決める（外側の catch は見ない）」で固定）。直すときは内側の catch を 1 文の形にする。

## 変えるファイル

| 区分 | ファイル | 中身 |
|---|---|---|
| テスト（新規）| `tests/Concerns/ScansImportControllers.php` | `*ImportController.php` の列挙と下限 8 本（2 本の走査で共用）|
| テスト（新規）| `tests/Feature/ImportControllerValidationRedirectScanTest.php` | 本体（78 本。レビューの後に 94 本）|
| テスト（変更）| `tests/Feature/ImportControllerReturnPathScanTest.php` | 列挙をトレイトへ・「今の URL」の検出・docblock |
| 記録 | この計画 ／ `docs/RULES.md`（Bug #64 の 1 段落）／ `docs/BACKLOG.md`（節を 1 つ・既存の節の 2 行）／ `CLAUDE.md`（1 行）| — |

**`app/`・`resources/`・`routes/`・DB・依存は変えない。** 本番への反映は要らない（`deploy.sh` は `tests/` と `docs/` を送らない）。

## 共通の決まり

- 作業場所: worktree `/Users/masanori/site/manage/.claude/worktrees/import-validation-scan`（ブランチ `import-validation-scan`）。**コマンドは毎回この場所で**（Bash ツールの cwd は途中で main repo に戻ることがある）。main repo の vendor は本番用で phpunit が無い
- 全件テスト（1 回 約 100 秒）: `APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`
- `.env` / `.env.*` は読まない。`git stash` と `--no-verify` は使わない。push しない。`app/`・`resources/`・`routes/` を変えない（変異テストの一時的な変更は隔離した worktree の中だけ）
- 書き方は既存のテストに合わせる（`'App\\' . …` の `.` の前後の空白、`! $x` の空白など）。**Pint にかけない**（既存のファイル自体が Pint の既定の書式に揃っていない＝ 2026-09-25 に `pint --test` で確認）
- コミットは Conventional Commits（日本語）で、末尾に必ず `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` を付ける（下のテンプレートのとおり HEREDOC で）
- 画面の文言を見るテストではセッションに触らない（Bug #49）。今回は画面を描かないので該当なし
- シェルは zsh: 引用なしの `$VAR` は単語分割されない（複数のパスは配列で）／ `grep` は ugrep の別名（`-` で始まるパターンは `-e` で）／ `php -r "…"` の中の `$` はシェルが展開する（使うときは scratchpad に `.php` を書く）／ `echo =====` は zsh の `=` 展開で落ちる（区切りは `echo '-----'`）

---

## Task 0: 前提の確認（コードは変えない）

- [ ] **Step 1: worktree とブランチ**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-validation-scan && git status --porcelain && git log --oneline -2 && git -C /Users/masanori/site/manage log --oneline -1 13.x && git merge-base --is-ancestor 13.x HEAD && echo "13.x を含む" && ls vendor/bin/phpunit
```

期待: 作業ツリーは空（この計画書だけが未追跡で出るなら Task 1 でコミットする）・HEAD は `2b7b5bad`（設計書）・`13.x` は `85b5662a`・「13.x を含む」・`vendor/bin/phpunit` がある。
⚠ `13.x` が進んでいたら、作業の前に `13.x` をこのブランチへマージする（リベースしない。記録のコミット番号を保つ）。

- [ ] **Step 2: 着手前の全件テスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-validation-scan && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

期待: `OK (2169 tests, 14567 assertions)`

## Task 1: 計画書をコミット

- [ ] **Step 1: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-validation-scan && git add docs/superpowers/plans/2026-09-25-import-validation-scan.md && git commit -m "$(cat <<'EOF'
docs: 取込の入力チェックの包み忘れを止める走査テストの計画書を書く

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

## Task 2: 列挙をトレイトへ切り出す（振る舞いは変えない）

**Files:**
- Create: `tests/Concerns/ScansImportControllers.php`
- Modify: `tests/Feature/ImportControllerReturnPathScanTest.php`（use 文・クラスの先頭・`scan()`）

- [ ] **Step 1: トレイトを作る**（`tests/Concerns/ScansImportControllers.php`。この内容そのまま）

```php
<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;

/**
 * 取込のコントローラ（`app/Http/Controllers` 配下の `*ImportController.php`）を機械的に列挙する（全件分類の走査用。Top trap #13）。
 *
 * ⚠ 2026-09-25 に `tests/Feature/ImportControllerReturnPathScanTest.php` から**そのまま**切り出した。
 *   入力チェックの包み方を見る `ImportControllerValidationRedirectScanTest` も同じ集合を見る必要があるため
 *   （2 本が別々に列挙すると、片方だけ範囲が変わって見落としが生まれる）。
 *   中身を変えるときは両方の利用者で測り直すこと。
 */
trait ScansImportControllers
{
    /** `*ImportController.php` の本数の下限（2026-09-25 実測 8 本）。下回ったら列挙が空振りしている */
    private const MIN_IMPORT_CONTROLLERS = 8;

    /**
     * 取込のコントローラ。本数が下限を下回ったら、その場で落とす（どの利用者も空振りに気づけるように）。
     *
     * @return array<string, string> プロジェクトルートからの相対パス => 絶対パス（相対パスの順）
     */
    private function importControllerFiles(): array
    {
        $files = [];

        foreach (File::allFiles(app_path('Http/Controllers')) as $file) {
            if (str_ends_with($file->getFilename(), 'ImportController.php')) {
                $files[str_replace(base_path() . '/', '', $file->getPathname())] = $file->getPathname();
            }
        }

        ksort($files);

        $this->assertGreaterThanOrEqual(
            self::MIN_IMPORT_CONTROLLERS,
            count($files),
            '走査が空振りしている（*ImportController.php が少なすぎる）'
        );

        return $files;
    }
}
```

- 下限の確認をトレイトの中に置く（どの利用者も空振りに気づける）。`assertGreaterThanOrEqual` は PHPUnit の数え方で 2 assertions になる
- 既存の定数の注記「このブランチは approval-phase1 の上に積んである」は、approval-phase1 が `13.x` に入ったので外した（設計書 §4.2）

- [ ] **Step 2: 既存の走査がトレイトを使う**（Edit を 3 つ。`tests/Feature/ImportControllerReturnPathScanTest.php`）

置き換える前（1/3）:

```php
use Illuminate\Support\Facades\File;
use Tests\TestCase;
```

置き換えた後（1/3）:

```php
use Illuminate\Support\Facades\File;
use Tests\Concerns\ScansImportControllers;
use Tests\TestCase;
```

置き換える前（2/3）:

```php
class ImportControllerReturnPathScanTest extends TestCase
{
    /**
     * `*ImportController.php` の本数の下限（2026-09-24 実測 8 本）。下回ったら列挙が空振りしている。
     * ⚠ 8 本には approval-phase1 で入った `Approval/UserImportController` を含む（このブランチはその上に積んである）
     */
    private const MIN_IMPORT_CONTROLLERS = 8;

```

置き換えた後（2/3）:

```php
class ImportControllerReturnPathScanTest extends TestCase
{
    use ScansImportControllers;

```

置き換える前（3/3。`scan()`）:

```php
    private function scan(): array
    {
        $hits = [];

        foreach (File::allFiles(app_path('Http/Controllers')) as $file) {
            if (! str_ends_with($file->getFilename(), 'ImportController.php')) {
                continue;
            }

            $hits[str_replace(base_path() . '/', '', $file->getPathname())] =
                $this->returnsToReferer($this->withoutComments($file->getPathname()));
        }

        ksort($hits);

        return $hits;
    }
```

置き換えた後（3/3）:

```php
    private function scan(): array
    {
        return array_map(
            fn (string $absolute) => $this->returnsToReferer($this->withoutComments($absolute)),
            $this->importControllerFiles()
        );
    }
```

⚠ `test_the_scan_sees_every_import_controller` の `self::MIN_IMPORT_CONTROLLERS` はそのまま（トレイトの定数を指す。PHP 8.2 から trait に定数を置ける）。`use Illuminate\Support\Facades\File;` も残す（`withoutComments()` が使う）。

- [ ] **Step 3: 走らせて緑**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-validation-scan && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/ImportControllerReturnPathScanTest.php 2>&1 | tail -1
```

期待: `OK (4 tests, 43 assertions)`（39 → 43 はトレイトの下限の確認 2 回分）

- [ ] **Step 4: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-validation-scan && git add tests/Concerns/ScansImportControllers.php tests/Feature/ImportControllerReturnPathScanTest.php && git commit -m "$(cat <<'EOF'
test: 取込のコントローラの列挙をトレイトに切り出す

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

## Task 3: 既存の走査に「今の URL」の検出を足す（TDD）

**Files:**
- Modify: `tests/Feature/ImportControllerReturnPathScanTest.php`（見本 → 正規表現 → docblock）

- [ ] **Step 1: 見本を先に足す**（Edit を 2 つ）

置き換える前（1/2。拾う見本の終わり）:

```php
            'return redirect(request( )->fullUrlWithoutQuery(\'page\'));',
        ];
```

置き換えた後（1/2）:

```php
            'return redirect(request( )->fullUrlWithoutQuery(\'page\'));',
            // 5. の呼び出し元 Request::（ファサード）と、今の URL を読むメソッド
            'return redirect(Request::url());', 'return redirect(Request::fullUrl());',
            'return redirect(\Illuminate\Support\Facades\Request::getPathInfo());',
            'return redirect($request->getUri());', 'return redirect($request->getRequestUri());',
            'return redirect($request->path());', 'return redirect(request()->decodedPath());',
            'return redirect($request->getPathInfo());',
            // 6. 今の URL を作る
            'return redirect(url()->current());', 'return redirect(url()->full());', 'return redirect(\url() -> current ());',
            'return redirect(URL::current());', 'return redirect(URL::full());',
            'return redirect(\Illuminate\Support\Facades\URL::current());',
            'return redirect(app(\'url\')->current());', 'return redirect(app("url")->full());',
            'throw $e->redirectTo(url()->current());',
        ];
```

置き換える前（2/2。拾わない見本の終わり）:

```php
            '$u = Storage::url($path);', '$u = $paginator->url(2);', '$u = $request->root();',
        ];
```

置き換えた後（2/2）:

```php
            '$u = Storage::url($path);', '$u = $paginator->url(2);', '$u = $request->root();',
            // 5. と 6. が名前だけ同じ別の呼び出しを拾わないこと
            '$item = $iterator->current();', '$p = Storage::path($path);', '$p = $request->file(\'csv\')->path();',
            '$u = FormRequest::url();', '$u = $menu->url()->current();', '$u = Menu::url()->current();',
            '$u = shorturl()->current();', '$u = ShortURL::current();',
        ];
```

- 拾う見本は設計書 §2.4 の 10 通り＋`path()` 系 3 つ＋`Request::` のほか、正規表現の分かれ目ごとに 1 つ以上（`\url()`・完全な名前の `URL::`・`app("url")` の二重引用符）
- 拾わない見本は、名前だけ同じ別の呼び出し（`$iterator->current()`・`Storage::path()`・`$request->file(…)->path()`・`FormRequest::url()`・`->url()->current()`・`::url()->current()`・`shorturl()`・`ShortURL::`）

- [ ] **Step 2: 赤を確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-validation-scan && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/ImportControllerReturnPathScanTest.php 2>&1 | grep -E -e '拾えていない|拾うべきでない|^Tests:|^OK'
```

期待: `拾えていない: return redirect(Request::url());` と `Tests: 4, Assertions: 28, Failures: 1.`

- [ ] **Step 3: 正規表現を足す**（5. を広げ、6. を新設）

置き換える前:

```php
            // 5. 今の URL（$request->url()・request()->fullUrl() など。POST 専用の URL を GET で開くことになる）
            '/(?:\$request|request\s*\(\s*\))\s*->\s*(?:url|fullUrl|fullUrlWithQuery|fullUrlWithoutQuery)\s*\(/',
```

置き換えた後:

```php
            // 5. リクエストから今の URL を読む（$request->url()・request()->fullUrl()・Request::url()・$request->path() など。
            //    相対パスの path() も、redirect() が今のホストの URL に組み直す。Request:: の前は語の途中でないこと
            //    ＝ FormRequest::url( は拾わない）
            '/(?:\$request\s*->|request\s*\(\s*\)\s*->|(?<![\w$])Request\s*::)\s*'
                . '(?:url|fullUrl|fullUrlWithQuery|fullUrlWithoutQuery|getUri|getRequestUri|path|decodedPath|getPathInfo)\s*\(/',
            // 6. 今の URL を作る（url()->current()・url()->full()・URL::current()・URL::full()・app('url')->current()。
            //    ->url() や ::url() のようなほかのメソッドの url() は拾わない）
            '/(?:(?<![\w$>:])url\s*\(\s*\)\s*->|(?<![\w$])URL\s*::|app\s*\(\s*[\'"]url[\'"]\s*\)\s*->)\s*(?:current|full)\s*\(/',
```

- [ ] **Step 4: 緑を確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-validation-scan && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/ImportControllerReturnPathScanTest.php 2>&1 | tail -1
```

期待: `OK (4 tests, 68 assertions)`（実物の分類も緑のまま＝実物の 8 本に新しい一致は 0 件）

- [ ] **Step 5: docblock を直す**（クラスの docblock を丸ごと置き換える。分担・見えないものの更新。設計書 §4.7）

置き換える前:

```php
/**
 * 取込のコントローラが、断るときにリファラー（「元の画面」）や今の URL へ戻していないことを全件分類で守る
 * （docs/RULES.md Bug #64・Top trap #13）。
 *
 * 取込の確認画面は POST の応答で、その URL は多くが POST 専用（`…/preview` など）。確認画面に載ったフォームから
 * 送って `back()` や `url()->previous()` で戻すと、リファラー＝その URL へ GET で戻り 405 になる
 * （2026-09-24 実測: テナント・賃貸マンション・ZEAL 会員・工程表）。今の URL へ戻す形（`redirect()->refresh()`・
 * `redirect($request->url())`）も、POST 専用の URL を GET で開くので同じ 405 になる。戻り先は取込の画面に固定する。
 *
 * ⚠ `app/Http/Controllers` 配下の `*ImportController.php` を機械的に列挙する（新しい取込は自動で検査対象に入る）。
 *   許すのは ALLOWED に載せたもの（件数と理由つき）だけ。ほかは 0 件でないと落ちる。件数が合わない・
 *   実在しないファイルが載っている、も落とす。
 * ⚠ コメントを落としてから数える。docblock に「`back()` を使わない」と書いてあるため（Bug #42 ②）。
 *
 * ⚠ 見えないもの:
 *   - **入力チェックの既定の戻り先。** `$request->validate([...])`・`Validator::make(...)->validate()`・
 *     `throw ValidationException::withMessages(...)` を `redirectTo()` 無しで書くと、失敗したときの戻り先は
 *     リファラーになるが、コードに `back(` が現れないので走査では拾えない（FormRequest の既定の戻り先も同じ）。
 *     今の 4 本は挙動のテスト（確認画面の URL をリファラーにして送る）が守る:
 *     `Admin\TenantImportRejectionTest` / `Admin\MansionImportRejectionTest` /
 *     `Admin\ZealMemberImportControllerTest` / `Housing\ScheduleImportTest`。**新しい取込には守り手がいない**
 *     （try で包んで `redirectTo()` を渡しているかを機械的に見る形は未実装。2026-09-24 のレビューの提案）
 *   - 走査するのは `*ImportController.php` の本文だけ。トレイト・親クラス・サービスへ切り出した `back()`
 *     （たとえばテナントと賃貸マンションでほぼ同じ `loadCsv()` をトレイトへ移す）は見えない
 *   - `*ImportController.php` という名前でない取込（ほかのコントローラに内蔵された確認画面）
 *   - 戻り先の URL を変数に入れて渡す形（`$to = $request->headers->get('referer')` や `$request->url()` は
 *     字面で拾うが、別のメソッドやクラスで作った URL を受け取る形は見えない）
 * ⚠ 過剰に拾うもの: 文字列リテラルの中の `back(`・`referer`（走査はトークンを見ない）・
 *   Carbon の `->previous(` のように別の意味の `previous()`。出てきたら ALLOWED に理由つきで載せる
 *   （検出器を緩めない）。
 */
```

置き換えた後:

```php
/**
 * 取込のコントローラが、断るときにリファラー（「元の画面」）や今の URL へ戻していないことを全件分類で守る
 * （docs/RULES.md Bug #64・Top trap #13）。
 *
 * 取込の確認画面は POST の応答で、その URL は多くが POST 専用（`…/preview` など）。確認画面に載ったフォームから
 * 送って `back()` や `url()->previous()` で戻すと、リファラー＝その URL へ GET で戻り 405 になる
 * （2026-09-24 実測: テナント・賃貸マンション・ZEAL 会員・工程表）。今の URL へ戻す形（`redirect()->refresh()`・
 * `redirect($request->url())`・`url()->current()`・`$request->path()` など）も、POST 専用の URL を GET で開くので
 * 同じ 405 になる。戻り先は取込の画面に固定する。
 *
 * ⚠ 対象は `ScansImportControllers` が列挙する `*ImportController.php`（新しい取込は自動で検査対象に入る）。
 *   入力チェックの包み方を見る `ImportControllerValidationRedirectScanTest` と同じ集合を見る。
 *   許すのは ALLOWED に載せたもの（件数と理由つき）だけ。ほかは 0 件でないと落ちる。件数が合わない・
 *   実在しないファイルが載っている、も落とす。
 * ⚠ コメントを落としてから数える。docblock に「`back()` を使わない」と書いてあるため（Bug #42 ②）。
 * ⚠ 分担: 入力チェックの既定の戻り先（`validate()` などを try で包み `redirectTo()` を渡しているか）と
 *   FormRequest は `ImportControllerValidationRedirectScanTest` が見る。こちらは戻り先の書き方（リファラー・今の URL）を、
 *   `redirectTo()` に渡すものも含めて数える（`throw $e->redirectTo(url()->current())` はこちらが拾う）。
 *
 * ⚠ 見えないもの:
 *   - 走査するのは `*ImportController.php` の本文だけ。トレイト・親クラス・サービスへ切り出した `back()`
 *     （たとえばテナントと賃貸マンションでほぼ同じ `loadCsv()` をトレイトへ移す）は見えない
 *   - `*ImportController.php` という名前でない取込（ほかのコントローラに内蔵された確認画面）
 *   - 戻り先の URL を変数に入れて渡す形（`$to = $request->headers->get('referer')` や `$request->url()` は
 *     字面で拾うが、別のメソッドやクラスで作った URL を受け取る形は見えない）
 *   - 今の URL を読む呼び出し元を変数に入れた形（`$req = $request; $req->url()`・`$this->request->url()`）
 * ⚠ 過剰に拾うもの: 文字列リテラルの中の `back(`・`referer`（走査はトークンを見ない）・
 *   Carbon の `->previous(` のように別の意味の `previous()`。出てきたら ALLOWED に理由つきで載せる
 *   （検出器を緩めない）。
 */
```

- [ ] **Step 6: もう一度走らせてコミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-validation-scan && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/ImportControllerReturnPathScanTest.php 2>&1 | tail -1 && git add tests/Feature/ImportControllerReturnPathScanTest.php && git commit -m "$(cat <<'EOF'
test: 取込の戻り先の走査で今の URL を作る書き方も拾う

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

期待: `OK (4 tests, 68 assertions)` のあとコミット

## Task 4: 新しい走査の骨組みと自己テスト（先に赤。コミットしない）

**Files:**
- Create: `tests/Feature/ImportControllerValidationRedirectScanTest.php`

- [ ] **Step 1: 骨組みを書く**（この内容そのまま。見本と自己テストは完成形。`analyze()` と `formRequestParameters()` だけ空を返す）

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ScansImportControllers;
use Tests\TestCase;

/**
 * 取込のコントローラが、入力チェックの例外を「戻り先を固定して投げ直す」形で包んでいることを全件分類で守る
 * （docs/RULES.md Bug #64・Top trap #13。設計書 docs/superpowers/specs/2026-09-25-import-validation-scan-design.md）。
 *
 * 取込の確認画面は POST の応答で、その URL は多くが POST 専用（`…/preview` など）。そこに載ったフォームから送って
 * 入力チェックで断られると、既定の戻り先はリファラー（`url()->previous()`）＝その URL で、GET で開いて 405 になる。
 * コードに `back(` が現れないので `ImportControllerReturnPathScanTest` には見えない。ここでは、入力チェックの例外を
 * 投げる呼び出しをトークンで探し、1 つずつ次の A か B を満たすかを見る:
 *
 *   A. 囲む try の catch のうち ValidationException を受け止める最初のものが、ValidationException を名指しし
 *      （`\Exception`・`\Throwable` が先に受け止めるなら不合格）、本体が `throw $<catch の変数>->redirectTo(…);` の 1 文だけ
 *   B. 投げる文そのものに `->redirectTo(` が続く（`throw ValidationException::withMessages([...])->redirectTo(…);`）
 *
 * ⚠ A は内側の try から順に見て、受け止める最初の catch で決める（外側の try は見ない）。内側の catch が `throw $e;` と
 *   投げ直し、外側の catch がそれを受けて `redirectTo()` する形も不合格にする（厳しめの規則。設計書 §4.4）。
 *
 * FormRequest（既定の戻り先がリファラー）を受ける public メソッドは Reflection で探し、1 つでもあれば落とす。
 *
 * ⚠ 分担: `redirectTo()` に渡す戻り先の中身（`back()`・`url()->previous()`・今の URL）はここでは見ない。
 *   それは `ImportControllerReturnPathScanTest` が見る。
 * ⚠ 対象は `ScansImportControllers` が列挙する `*ImportController.php`（新しい取込は自動で検査対象に入る）。
 *   包んでいない呼び出しを許すのは ALLOWED に載せたもの（件数と理由つき）だけ。件数が合わない・
 *   実在しないファイルが載っている、も落とす。
 * ⚠ 正規表現でなく `token_get_all()` のトークンで読む（コメントや文字列の中の `->validate(` を拾わない・
 *   try と catch の入れ子と catch の並び順を取り違えない。Bug #45 ④）。解析できない書き方（まとめた use
 *   ＝ `use A\{B, C};` と `use A\B, C\D;`・名前の続かない冒頭の use・namespace が 2 つ以上・`namespace X { … }` の形・
 *   波括弧の対応が取れない）は推測せずに落とす。クラス名もメソッド名も、PHP と同じく大文字小文字を区別しない。
 *
 * ⚠ 見えないもの:
 *   - トレイト・親クラス・サービスに移した入力チェック（2026-09-25 時点で 0 件）
 *   - `*ImportController.php` という名前でない取込（仕入れ案件・分譲地の原価の一括取込。詳細画面（GET）から
 *     JS で送るので 405 の形ではない。設計書 §2.5）
 *   - try の中で作って、try の外で呼ばれるクロージャ（字面では包まれて見える）
 *   - 見本に無い書き方（検出する 7 形のほかの投げ方。`namespace\ValidationException` のような相対名も含む）
 * ⚠ 拾いすぎるもの: 呼び出し元のメソッドで包んだ形（メソッドをまたぐと見えない）・`fails()` を確かめた後の
 *   `validated()`・別の物の `validate()`。呼び出しのすぐ外で包む形に直すか、ALLOWED に理由つきで載せる
 *   （検出を緩めない）。
 */
class ImportControllerValidationRedirectScanTest extends TestCase
{
    use ScansImportControllers;

    /** 入力チェックの例外（小文字で比べる。PHP のクラス名は大文字小文字を区別しない） */
    private const VALIDATION_EXCEPTION = 'illuminate\validation\validationexception';

    /** 入力チェックの例外も受け止めてしまう総称の型 */
    private const GENERIC_CATCHES = ['exception', 'throwable'];

    // ================================================================
    // 走査の自己テスト（見本の無い分かれ目は、消しても全テストが緑になる。Bug #61 ③）
    // ================================================================

    /** @return array<string, array{0: string, 1: list<string>}> 見本 => 拾う形 */
    public static function throwingCallSamples(): array
    {
        return [
            '->validate('                  => [self::sample('$request->validate([\'a\' => \'required\']);'), ['->validate(']],
            '?->validate('                 => [self::sample('$request?->validate([\'a\' => \'required\']);'), ['?->validate(']],
            '->validateWithBag('           => [self::sample('$request->validateWithBag(\'import\', [\'a\' => \'required\']);'), ['->validateWithBag(']],
            '->validated('                 => [self::sample('$data = $validator->validated();'), ['->validated(']],
            '::validate('                  => [self::sample('Validator::validate($data, [\'a\' => \'required\']);'), ['::validate(']],
            '::withMessages('              => [self::sample('throw ValidationException::withMessages([\'a\' => \'x\']);'), ['::withMessages(']],
            'new ValidationException'      => [self::sample('throw new ValidationException($validator);'), ['new ValidationException']],
            'ValidationException::class'   => [self::sample('throw_if($validator->fails(), ValidationException::class, $validator);'), ['ValidationException::class']],
            '大文字の ->Validate('          => [self::sample('$request->Validate([]);'), ['->Validate(']],
            '$this->validate('             => [self::sample('$this->validate($request, []);'), ['->validate(']],
            'make() の後の ->validate('     => [self::sample('Validator::make($data, [])->validate();'), ['->validate(']],
            '空白をはさむ'                 => [self::sample('$request -> validate ( [] );'), ['->validate(']],
            'コメントをはさむ'             => [self::sample('$request->/* 注 */validate([]);'), ['->validate(']],
            '完全な名前の new'             => [self::sample('throw new \Illuminate\Validation\ValidationException($v);', ''), ['new \Illuminate\Validation\ValidationException']],
            'as の別名の new'              => [self::sample('throw new InvalidInput($v);', "use Illuminate\\Validation\\ValidationException as InvalidInput;\n"), ['new InvalidInput']],
            '小文字のクラス名の new'       => [self::sample('throw new validationexception($v);'), ['new validationexception']],
            '先頭に \ のある use'           => [self::sample('throw new ValidationException($v);', "use \\Illuminate\\Validation\\ValidationException;\n"), ['new ValidationException']],
        ];
    }

    #[DataProvider('throwingCallSamples')]
    public function test_the_detector_finds_each_form_of_call_that_throws(string $source, array $forms): void
    {
        $this->assertSame($forms, array_column($this->analyze($source), 'form'));
    }

    /** @return array<string, array{0: string}> 拾ってはいけない見本 */
    public static function notThrowingSamples(): array
    {
        return [
            '名前の違うメソッド validateSales(' => [self::sample('$this->client->validateSales($parsed);')],
            '名前の違うメソッド validateRow('   => [self::sample('$errors = $this->validateRow($row);')],
            '変数 $validated'                   => [self::sample('$rows = $validated[\'rows\'];')],
            '文字列の中'                        => [self::sample('$s = \'$request->validate([])\';')],
            '// コメントの中'                   => [self::sample('// $request->validate([]);')],
            '/** */ の中'                       => [self::sample('/** $request->validate([]) */')],
            '呼び出しでないプロパティ'          => [self::sample('$rule = $request->validate;')],
            'Validator::make( だけ'             => [self::sample('$v = Validator::make($data, []);')],
            'use の無い new'                    => [self::sample('throw new ValidationException($v);', '')],
            'use の無い ::class'                => [self::sample('throw_if($x, ValidationException::class);', '')],
            'メソッドの宣言'                    => ["<?php\n\nnamespace App\\Http\\Controllers\\Admin;\n\nclass SampleImportController\n{\n    public function validate(\$request)\n    {\n    }\n}\n"],
        ];
    }

    #[DataProvider('notThrowingSamples')]
    public function test_the_detector_ignores_what_does_not_throw(string $source): void
    {
        $this->assertSame([], $this->analyze($source));
    }

    /** @return array<string, array{0: string}> 包んであるとみなす見本 */
    public static function wrappedSamples(): array
    {
        return [
            '今の書き方（catch の中の // コメント）' => [self::sample(<<<'PHP'
                try {
                    $request->validate(['a' => 'required']);
                } catch (ValidationException $e) {
                    // 取込の画面へ戻す
                    throw $e->redirectTo(route('admin.sample-import'));
                }
                PHP)],
            '完全な名前で書いた catch' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (\Illuminate\Validation\ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP, '')],
            'as の別名の catch' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (InvalidInput $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP, "use Illuminate\\Validation\\ValidationException as InvalidInput;\n")],
            'A | B の catch' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (CsvImportException | ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP, "use App\\Support\\CsvImportException;\nuse Illuminate\\Validation\\ValidationException;\n")],
            '内側の try が別の例外しか受けない入れ子' => [self::sample(<<<'PHP'
                try {
                    try {
                        $request->validate([]);
                    } catch (CsvImportException $e) {
                        return null;
                    }
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP, "use App\\Support\\CsvImportException;\nuse Illuminate\\Validation\\ValidationException;\n")],
            'use の無い Exception の catch が先（名前空間の中では別のクラス）' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (Exception $e) {
                    report($e);
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP)],
            'withMessages() に続く ->redirectTo(' => [self::sample(<<<'PHP'
                throw ValidationException::withMessages(['a' => 'x'])->redirectTo(route('admin.sample-import'));
                PHP)],
            '(new ValidationException()) に続く ->redirectTo(' => [self::sample(<<<'PHP'
                throw (new ValidationException($validator))->redirectTo(route('admin.sample-import'));
                PHP)],
            'catch の中の /** */ コメント' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    /** 取込の画面へ戻す */
                    throw $e->redirectTo('/sample');
                }
                PHP)],
            '小文字のクラス名と REDIRECTTO' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (\illuminate\validation\validationexception $e) {
                    throw $e->REDIRECTTO('/sample');
                }
                PHP, '')],
            'redirectTo() の後に続く呼び出し' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample')->errorBag('import');
                }
                PHP)],
            '文字列の中の {$…} と ${…} をはさむ' => [self::sample(<<<'PHP'
                try {
                    $label = "{$name} と ${name}";
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP)],
            'クロージャの use をはさむ（冒頭の use と取り違えない）' => [self::sample(<<<'PHP'
                try {
                    $rows = array_map(function ($row) use ($request) {
                        return $row;
                    }, []);
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP)],
            'use function・use const のあるファイル' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP, "use function sprintf;\nuse const PHP_EOL;\nuse Illuminate\\Validation\\ValidationException;\n")],
            '名前空間の一部を use した修飾名の catch' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (Validation\ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP, "use Illuminate\\Validation;\n")],
            'redirectTo の引数の中の ;（クロージャ）' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->redirectTo(value(function () { return '/sample'; }));
                }
                PHP)],
        ];
    }

    #[DataProvider('wrappedSamples')]
    public function test_the_judge_accepts_the_wrapped_forms(string $source): void
    {
        $calls = $this->analyze($source);

        $this->assertCount(1, $calls, '見本の中の投げる呼び出しは 1 つのはず');
        $this->assertNull($calls[0]['reason'], "包んであるのに不合格になった: {$calls[0]['reason']}");
    }

    /** @return array<string, array{0: string, 1: string}> 包んでいないとみなす見本 => 理由 */
    public static function unwrappedSamples(): array
    {
        $bodyNotOneStatement = '（catch の本体が throw $e->redirectTo(…) の 1 文でない）';

        return [
            'try が無い' => [self::sample('$request->validate([]);'), '（try の外）'],
            '\Exception の catch が先' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (\Exception $e) {
                    return null;
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP), '（\Exception の catch が先に受け止める）'],
            '\Throwable の catch が先' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (\Throwable $e) {
                    return null;
                }
                PHP), '（\Throwable の catch が先に受け止める）'],
            'use Exception; のある Exception の catch が先' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (Exception $e) {
                    return null;
                }
                PHP, "use Exception;\nuse Illuminate\\Validation\\ValidationException;\n"), '（Exception の catch が先に受け止める）'],
            'A | \Exception の catch' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException | \Exception $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP), '（\Exception の catch が先に受け止める）'],
            '本体が throw $e;' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e;
                }
                PHP), $bodyNotOneStatement],
            '本体の前にもう 1 文' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    report($e);
                    throw $e->redirectTo('/sample');
                }
                PHP), $bodyNotOneStatement],
            '本体の後にもう 1 文' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                    report($e);
                }
                PHP), $bodyNotOneStatement],
            '条件しだいで戻り先なしに投げ直す' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    if ($request->has('debug')) {
                        throw $e;
                    }
                    throw $e->redirectTo('/sample');
                }
                PHP), $bodyNotOneStatement],
            'throw の変数が catch の変数と違う' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $other->redirectTo('/sample');
                }
                PHP), $bodyNotOneStatement],
            '?-> で投げ直す' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e?->redirectTo('/sample');
                }
                PHP), $bodyNotOneStatement],
            '本体が throw でなく return' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    return $e->redirectTo('/sample');
                }
                PHP), $bodyNotOneStatement],
            '本体が redirectTo でない別のメソッドで投げ直す' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->errorBag('import');
                }
                PHP), $bodyNotOneStatement],
            '本体の redirectTo が呼び出しでない' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->redirectTo;
                }
                PHP), $bodyNotOneStatement],
            '内側の try の catch で決める（外側の catch は見ない）' => [self::sample(<<<'PHP'
                try {
                    try {
                        $request->validate([]);
                    } catch (ValidationException $e) {
                        throw $e;
                    }
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP), $bodyNotOneStatement],
            '変数の無い catch' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException) {
                    throw new \RuntimeException('x');
                }
                PHP), '（catch に変数が無く、投げ直せない）'],
            '呼び出しが catch の中' => [self::sample(<<<'PHP'
                try {
                    $this->load();
                } catch (ValidationException $e) {
                    $request->validate([]);
                }
                PHP), '（try の外）'],
            '呼び出しが finally の中' => [self::sample(<<<'PHP'
                try {
                    $this->load();
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                } finally {
                    $request->validate([]);
                }
                PHP), '（try の外）'],
            'use の無い ValidationException の catch' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP, ''), '（use の無い ValidationException を受けている）'],
            'redirectTo の無い withMessages()（次の文の ->redirectTo( は続いたことにしない）' => [self::sample(<<<'PHP'
                throw ValidationException::withMessages(['a' => 'x']);
                $next->redirectTo('/sample');
                PHP), '（try の外）'],
            '別の例外しか受けない catch' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (CsvImportException $e) {
                    return null;
                }
                PHP, "use App\\Support\\CsvImportException;\n"), '（ValidationException を受け止める catch が無い）'],
            '引数の中の ->redirectTo( は続いたことにしない' => [self::sample(<<<'PHP'
                throw ValidationException::withMessages(['a' => $x->redirectTo('/sample')]);
                PHP), '（try の外）'],
        ];
    }

    #[DataProvider('unwrappedSamples')]
    public function test_the_judge_rejects_the_unwrapped_forms(string $source, string $reason): void
    {
        $calls = $this->analyze($source);

        $this->assertCount(1, $calls, '見本の中の投げる呼び出しは 1 つのはず');
        $this->assertSame($reason, $calls[0]['reason']);
    }

    /** @return array<string, array{0: string, 1: string}> 解析できない見本 => 理由の一部 */
    public static function unparseableSamples(): array
    {
        return [
            'まとめた use（{ }）' => [self::sample('$request->validate([]);', "use Illuminate\\Validation\\{ValidationException, Validator};\n"), 'まとめた use'],
            'まとめた use（,）'   => [self::sample('$request->validate([]);', "use Illuminate\\Validation\\ValidationException, App\\Support\\CsvImportException;\n"), 'まとめた use'],
            'namespace が 2 つ'   => ["<?php\n\nnamespace A;\n\nclass X {}\n\nnamespace B;\n\nclass Y {}\n", 'namespace が 2 つ以上'],
            'namespace X { … }'   => ["<?php\n\nnamespace A {\n    class X {}\n}\n", 'namespace X { … } の形'],
            '閉じの足りない波括弧' => ["<?php\n\nnamespace A;\n\nclass X\n{\n    public function f()\n    {\n}\n", '波括弧の対応が取れない'],
            '閉じの多い波括弧'     => ["<?php\n\nnamespace A;\n\nclass X\n{\n}\n}\n", '波括弧の対応が取れない'],
            '冒頭のクロージャの use' => ["<?php\n\nnamespace A;\n\n\$f = function () use (\$x) {};\n", '読めない use'],
        ];
    }

    #[DataProvider('unparseableSamples')]
    public function test_unparseable_sources_are_rejected_instead_of_guessed(string $source, string $message): void
    {
        try {
            $this->analyze($source);
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString($message, $e->getMessage());

            return;
        }

        $this->fail("解析できないはずの書き方を読んでしまった（{$message}）");
    }

    public function test_comments_are_skipped_and_the_real_call_is_reported_on_its_line(): void
    {
        $source = "<?php\n\nnamespace App\\Http\\Controllers\\Admin;\n\nclass SampleImportController\n{\n"
            . "    public function execute(\$request)\n    {\n"
            . "        // \$request->validate([]);\n"
            . "        /** \$request->validate([]) */\n"
            . "        \$request->validate([]);\n"
            . "    }\n}\n";

        $this->assertSame([['line' => 11, 'form' => '->validate(', 'reason' => '（try の外）']], $this->analyze($source));
    }

    public function test_the_form_request_detector_sees_every_shape_of_type(): void
    {
        $sample = new \ReflectionClass(new class
        {
            public function plain(EmailVerificationRequest $request): void {}

            public function nullable(?EmailVerificationRequest $request): void {}

            public function union(EmailVerificationRequest|Request $request): void {}

            public function notAFormRequest(Request $request, int $id): void {}

            private function hidden(EmailVerificationRequest $request): void {}
        });

        $this->assertSame([
            'plain($request: ' . EmailVerificationRequest::class . ')',
            'nullable($request: ' . EmailVerificationRequest::class . ')',
            'union($request: ' . EmailVerificationRequest::class . ')',
        ], $this->formRequestParameters($sample));
    }

    // ================================================================
    // 走査
    // ================================================================

    /** 見本のメソッド本体を、名前空間・use・クラスで包んだソースにする */
    private static function sample(string $body, string $uses = "use Illuminate\\Validation\\ValidationException;\n"): string
    {
        return "<?php\n\nnamespace App\\Http\\Controllers\\Admin;\n\n{$uses}\nclass SampleImportController\n{\n"
            . "    public function execute(\$request)\n    {\n{$body}\n    }\n}\n";
    }

    /**
     * （Task 5 で本体を書く。先に見本が赤になることを確かめるための空の実装）
     *
     * @return list<array{line: int, form: string, reason: ?string}>
     */
    private function analyze(string $source): array
    {
        return [];
    }

    /**
     * （Task 5 で本体を書く）
     *
     * @return list<string>
     */
    private function formRequestParameters(\ReflectionClass $class): array
    {
        return [];
    }
}
```

- [ ] **Step 2: 赤を確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-validation-scan && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/ImportControllerValidationRedirectScanTest.php 2>&1 | grep -E '^(Tests:|OK)'
```

期待: `Tests: 75, Assertions: 75, Failures: 64.`（拾う見本 17・包んである見本 16・包んでいない見本 22・解析できない見本 7・行番号 1・FormRequest 1 が赤。拾わない見本 11 は空を返すので緑＝この段では何も確かめていない）

## Task 5: 走査を実装する（自己テストを緑に）

**Files:**
- Modify: `tests/Feature/ImportControllerValidationRedirectScanTest.php`（空の 2 つを本体に置き換える）

- [ ] **Step 1: 空の実装を置き換える**

置き換える前:

```php
    /**
     * （Task 5 で本体を書く。先に見本が赤になることを確かめるための空の実装）
     *
     * @return list<array{line: int, form: string, reason: ?string}>
     */
    private function analyze(string $source): array
    {
        return [];
    }

    /**
     * （Task 5 で本体を書く）
     *
     * @return list<string>
     */
    private function formRequestParameters(\ReflectionClass $class): array
    {
        return [];
    }
```

置き換えた後（`scan()`・`analyze()` と部品・FormRequest の Reflection）:

```php
    /**
     * @return array<string, list<array{line: int, form: string, reason: ?string}>|string>
     *   相対パス => 投げる呼び出し（解析できなければその理由の文字列）
     */
    private function scan(): array
    {
        $results = [];

        foreach ($this->importControllerFiles() as $path => $absolute) {
            try {
                $results[$path] = $this->analyze(File::get($absolute));
            } catch (\UnexpectedValueException $e) {
                $results[$path] = $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * ソースを読み、入力チェックの例外を投げる呼び出しごとに、包んでいない理由（包んであれば null）を返す。
     *
     * @return list<array{line: int, form: string, reason: ?string}>
     *
     * @throws \UnexpectedValueException 解析できない書き方（推測しない）
     */
    private function analyze(string $source): array
    {
        $tokens = $this->tokens($source);
        $braces = $this->braceMap($tokens);
        [$namespace, $imports] = $this->nameContext($tokens);
        $tries = $this->tryStatements($tokens, $braces);

        return array_map(fn (array $call) => [
            'line'   => $call['line'],
            'form'   => $call['form'],
            'reason' => $this->unwrappedReason($tokens, $call['index'], $tries, $namespace, $imports),
        ], $this->throwingCalls($tokens, $namespace, $imports));
    }

    /**
     * コメントと空白を除いたトークン。1 つは [id（1 文字の記号は null）, 字面, 行]。
     *
     * @return list<array{0: ?int, 1: string, 2: int}>
     */
    private function tokens(string $source): array
    {
        $tokens = [];
        $line = 1;

        foreach (token_get_all($source) as $token) {
            [$id, $text] = is_array($token) ? [$token[0], $token[1]] : [null, $token];
            $line = is_array($token) ? $token[2] : $line;

            if (! in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                $tokens[] = [$id, $text, $line];
            }

            $line += substr_count($text, "\n");
        }

        return $tokens;
    }

    /** 1 文字の記号か */
    private function is(array $token, string $char): bool
    {
        return $token[0] === null && $token[1] === $char;
    }

    /** クラス名のトークンか（`A`・`A\B`・`\A\B`） */
    private function isName(array $token): bool
    {
        return in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
    }

    /** 波括弧を開くか（文字列の中の `{$`・`${` も `}` で閉じるので数える） */
    private function opensBrace(array $token): bool
    {
        return $this->is($token, '{') || in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
    }

    /** 括弧を開くか（`(`・`[`・波括弧） */
    private function opens(array $token): bool
    {
        return $this->is($token, '(') || $this->is($token, '[') || $this->opensBrace($token);
    }

    /** 括弧を閉じるか */
    private function closes(array $token): bool
    {
        return $this->is($token, ')') || $this->is($token, ']') || $this->is($token, '}');
    }

    /**
     * 波括弧の対応（開きの位置 => 閉じの位置）。対応が取れなければ解析できない。
     *
     * @return array<int, int>
     */
    private function braceMap(array $tokens): array
    {
        $map = [];
        $open = [];

        foreach ($tokens as $i => $token) {
            if ($this->opensBrace($token)) {
                $open[] = $i;
            } elseif ($this->is($token, '}')) {
                if ($open === []) {
                    throw new \UnexpectedValueException("{$token[2]} 行目: 波括弧の対応が取れない（閉じが多い）");
                }

                $map[array_pop($open)] = $i;
            }
        }

        if ($open !== []) {
            throw new \UnexpectedValueException($tokens[end($open)][2] . ' 行目: 波括弧の対応が取れない（閉じが足りない）');
        }

        return $map;
    }

    /**
     * ファイルの名前空間と、冒頭（波括弧の外）の `use`（別名 => 完全な名前。どちらも小文字）。
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function nameContext(array $tokens): array
    {
        $namespace = null;
        $imports = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($this->opensBrace($token)) {
                $depth++;
            } elseif ($this->is($token, '}')) {
                $depth--;
            } elseif ($depth === 0 && $token[0] === T_NAMESPACE) {
                if ($namespace !== null) {
                    throw new \UnexpectedValueException("{$token[2]} 行目: namespace が 2 つ以上ある");
                }

                $name = $tokens[$i + 1] ?? [null, '', 0];
                if (! in_array($name[0], [T_STRING, T_NAME_QUALIFIED], true) || ! $this->is($tokens[$i + 2] ?? [null, '', 0], ';')) {
                    throw new \UnexpectedValueException("{$token[2]} 行目: namespace X { … } の形（または名前の無い namespace）");
                }

                $namespace = strtolower($name[1]);
                $i += 2;
            } elseif ($depth === 0 && $token[0] === T_USE) {
                $i = $this->readUse($tokens, $i, $imports);
            }
        }

        return [$namespace ?? '', $imports];
    }

    /**
     * 冒頭の `use` 文を 1 つ読んで $imports に足し、その `;` の位置を返す。
     *
     * @param  array<string, string>  $imports
     */
    private function readUse(array $tokens, int $i, array &$imports): int
    {
        $line = $tokens[$i][2];
        $j = $i + 1;

        if (in_array($tokens[$j][0] ?? null, [T_FUNCTION, T_CONST], true)) {
            // use function・use const はクラス名ではない
            while (isset($tokens[$j]) && ! $this->is($tokens[$j], ';')) {
                $j++;
            }

            return $j;
        }

        $name = $tokens[$j] ?? [null, '', 0];
        if (! $this->isName($name)) {
            throw new \UnexpectedValueException("{$line} 行目: 読めない use");
        }

        $full = strtolower(ltrim($name[1], '\\'));
        $alias = substr((string) strrchr('\\' . $full, '\\'), 1);
        $j++;

        if (($tokens[$j][0] ?? null) === T_AS) {
            $alias = strtolower($tokens[$j + 1][1]);
            $j += 2;
        }

        if (! $this->is($tokens[$j] ?? [null, '', 0], ';')) {
            throw new \UnexpectedValueException("{$line} 行目: まとめた use（1 文で 2 つ以上を読み込んでいる）");
        }

        $imports[$alias] = $full;

        return $j;
    }

    /**
     * 書かれたクラス名を、PHP と同じ規則で完全な名前（小文字・先頭の \ なし）にする。
     *
     * @param  array<string, string>  $imports
     */
    private function resolve(string $written, string $namespace, array $imports): string
    {
        if (str_starts_with($written, '\\')) {
            return strtolower(substr($written, 1));
        }

        $lower = strtolower($written);
        $first = explode('\\', $lower)[0];

        if (isset($imports[$first])) {
            return $imports[$first] . substr($lower, strlen($first));
        }

        return ltrim($namespace . '\\' . $lower, '\\');
    }

    /**
     * try 文ごとに、本体の範囲（try の直後の `{` と対応する `}`）と catch の並び（型・変数・本体の範囲）。
     *
     * @param  array<int, int>  $braces
     * @return list<array{open: int, close: int, catches: list<array{types: list<string>, var: ?string, open: int, close: int}>}>
     */
    private function tryStatements(array $tokens, array $braces): array
    {
        $tries = [];

        foreach ($tokens as $i => $token) {
            if ($token[0] !== T_TRY) {
                continue;
            }

            $catches = [];
            $j = $braces[$i + 1] + 1;

            while (($tokens[$j][0] ?? null) === T_CATCH) {
                $types = [];
                $var = null;

                for ($k = $j + 2; isset($tokens[$k]) && ! $this->is($tokens[$k], ')'); $k++) {
                    if ($this->isName($tokens[$k])) {
                        $types[] = $tokens[$k][1];
                    } elseif ($tokens[$k][0] === T_VARIABLE) {
                        $var = $tokens[$k][1];
                    }
                }

                $catches[] =['types' => $types, 'var' => $var, 'open' => $k + 1, 'close' => $braces[$k + 1]];
                $j = $braces[$k + 1] + 1;
            }

            $tries[] = ['open' => $i + 1, 'close' => $braces[$i + 1], 'catches' => $catches];
        }

        return $tries;
    }

    /**
     * 入力チェックの例外を投げる呼び出し（設計書 §4.3 の 7 形）。index は判定に使うトークンの位置。
     *
     * @param  array<string, string>  $imports
     * @return list<array{index: int, line: int, form: string}>
     */
    private function throwingCalls(array $tokens, string $namespace, array $imports): array
    {
        $calls = [];
        $none = [null, '', 0];

        foreach ($tokens as $i => $token) {
            $next = $tokens[$i + 1] ?? $none;
            $after = $tokens[$i + 2] ?? $none;
            $calledName = $next[0] === T_STRING && $this->is($after, '(') ? strtolower($next[1]) : null;

            if (in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && in_array($calledName, ['validate', 'validatewithbag', 'validated'], true)) {
                // ->validate(・?->validate(・->validateWithBag(・->validated(
                $calls[] = ['index' => $i + 1, 'line' => $next[2], 'form' => $token[1] . $next[1] . '('];
            } elseif ($token[0] === T_DOUBLE_COLON && in_array($calledName, ['validate', 'withmessages'], true)) {
                // ::validate(・::withMessages(
                $calls[] = ['index' => $i + 1, 'line' => $next[2], 'form' => '::' . $next[1] . '('];
            } elseif ($token[0] === T_NEW && $this->isName($next)
                && $this->resolve($next[1], $namespace, $imports) === self::VALIDATION_EXCEPTION) {
                // new ValidationException
                $calls[] = ['index' => $i, 'line' => $token[2], 'form' => 'new ' . $next[1]];
            } elseif ($this->isName($token) && $next[0] === T_DOUBLE_COLON && $after[0] === T_CLASS
                && $this->resolve($token[1], $namespace, $imports) === self::VALIDATION_EXCEPTION) {
                // ValidationException::class（throw_if() などに渡す）
                $calls[] = ['index' => $i, 'line' => $token[2], 'form' => $token[1] . '::class'];
            }
        }

        return $calls;
    }

    /**
     * 呼び出しが包んであれば null、包んでいなければ理由（設計書 §4.4 の A・B）。
     *
     * @param  list<array{open: int, close: int, catches: list<array{types: list<string>, var: ?string, open: int, close: int}>}>  $tries
     * @param  array<string, string>  $imports
     */
    private function unwrappedReason(array $tokens, int $call, array $tries, string $namespace, array $imports): ?string
    {
        // B. 投げる文そのものに ->redirectTo( が続く
        if ($this->throwBefore($tokens, $call) && $this->redirectToAfter($tokens, $call)) {
            return null;
        }

        // A. 囲む try を内側から外へたどり、ValidationException を受け止める最初の catch で決める
        $enclosing = array_values(array_filter($tries, fn (array $try) => $try['open'] < $call && $call < $try['close']));
        usort($enclosing, fn (array $a, array $b) => $b['open'] <=> $a['open']);

        if ($enclosing === []) {
            return '（try の外）';
        }

        $hint = null;

        foreach ($enclosing as $try) {
            foreach ($try['catches'] as $catch) {
                $catchesIt = false;

                foreach ($catch['types'] as $type) {
                    $resolved = $this->resolve($type, $namespace, $imports);

                    if (in_array($resolved, self::GENERIC_CATCHES, true)) {
                        return "（{$type} の catch が先に受け止める）";
                    }

                    if ($resolved === self::VALIDATION_EXCEPTION) {
                        $catchesIt = true;
                    } elseif (strcasecmp(substr((string) strrchr('\\' . $type, '\\'), 1), 'ValidationException') === 0) {
                        $hint = '（use の無い ValidationException を受けている）';
                    }
                }

                if (! $catchesIt) {
                    continue;
                }

                if ($catch['var'] === null) {
                    return '（catch に変数が無く、投げ直せない）';
                }

                $body = array_slice($tokens, $catch['open'] + 1, $catch['close'] - $catch['open'] - 1);

                return $this->rethrowsWithRedirectTo($body, $catch['var'])
                    ? null
                    : "（catch の本体が throw {$catch['var']}->redirectTo(…) の 1 文でない）";
            }
        }

        return $hint ?? '（ValidationException を受け止める catch が無い）';
    }

    /** catch の本体（コメントを除く）が `throw $<変数>->redirectTo(…)…;` の 1 文だけか */
    private function rethrowsWithRedirectTo(array $body, string $var): bool
    {
        $none = [null, '', 0];
        [$throw, $variable, $arrow, $method, $paren] = array_pad(array_slice($body, 0, 5), 5, $none);

        if ($throw[0] !== T_THROW
            || $variable[0] !== T_VARIABLE || $variable[1] !== $var
            || $arrow[0] !== T_OBJECT_OPERATOR
            || $method[0] !== T_STRING || strcasecmp($method[1], 'redirectTo') !== 0
            || ! $this->is($paren, '(')) {
            return false;
        }

        $depth = 0;

        foreach ($body as $k => $token) {
            if ($this->opens($token)) {
                $depth++;
            } elseif ($this->closes($token)) {
                $depth--;
            } elseif ($depth === 0 && $this->is($token, ';')) {
                return $k === count($body) - 1;   // 括弧の外の最初の ; で終わり、その後に何も無い
            }
        }

        return false;
    }

    /** 同じ文の中で、呼び出しより前に throw があるか（呼び出しを囲む ( [ の外へは出る。{ } ; で止まる） */
    private function throwBefore(array $tokens, int $call): bool
    {
        $depth = 0;

        for ($j = $call - 1; $j >= 0; $j--) {
            $token = $tokens[$j];

            if ($this->closes($token)) {
                if ($depth === 0 && $this->is($token, '}')) {
                    return false;   // 前のブロックの終わり
                }

                $depth++;
            } elseif ($this->opens($token)) {
                if ($depth === 0 && $this->opensBrace($token)) {
                    return false;   // この文を囲むブロックの始まり
                }

                $depth = max(0, $depth - 1);   // 入れ子を閉じる（0 のときは呼び出しを囲む ( [ の外へ出る）
            } elseif ($depth === 0 && $this->is($token, ';')) {
                return false;
            } elseif ($depth === 0 && $token[0] === T_THROW) {
                return true;
            }
        }

        return false;
    }

    /** 同じ文の中で、呼び出しより後ろ（入れ子の外）に ->redirectTo( が続くか */
    private function redirectToAfter(array $tokens, int $call): bool
    {
        $none = [null, '', 0];
        $depth = 0;
        $count = count($tokens);

        for ($j = $call + 1; $j < $count; $j++) {
            $token = $tokens[$j];

            if ($this->opens($token)) {
                $depth++;
            } elseif ($this->closes($token)) {
                if ($depth === 0 && $this->is($token, '}')) {
                    return false;   // この文を囲むブロックの終わり
                }

                $depth = max(0, $depth - 1);   // 入れ子を閉じる（0 のときは呼び出しを囲む ) ] の外へ出る）
            } elseif ($depth === 0 && $this->is($token, ';')) {
                return false;
            } elseif ($depth === 0 && $token[0] === T_OBJECT_OPERATOR) {
                $method = $tokens[$j + 1] ?? $none;

                if ($method[0] === T_STRING && strcasecmp($method[1], 'redirectTo') === 0 && $this->is($tokens[$j + 2] ?? $none, '(')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * public メソッドの引数に FormRequest（の子クラス）を受けるものがあれば「メソッド名($引数: 型)」を返す。
     *
     * @return list<string>
     */
    private function formRequestParameters(\ReflectionClass $class): array
    {
        $found = [];

        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                foreach ($this->classNamesIn($parameter->getType()) as $name) {
                    if (is_a($name, FormRequest::class, true)) {
                        $found[] = "{$method->getName()}(\${$parameter->getName()}: {$name})";
                    }
                }
            }
        }

        return $found;
    }

    /**
     * 型の中のクラス名（`A`・`?A`・`A|B`・`A&B`・`(A&B)|C`。組み込みの型は除く）。
     *
     * @return list<string>
     */
    private function classNamesIn(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }

        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            return array_merge(...array_map(fn (\ReflectionType $inner) => $this->classNamesIn($inner), $type->getTypes()));
        }

        return [];
    }
```

要点（設計書 §4.3〜§4.5）:
- トークンはコメント・空白・開始タグを落として `[id, 字面, 行]` にする（1 文字の記号は id が null）。波括弧は `{` のほか文字列の中の `{$`（`T_CURLY_OPEN`）・`${`（`T_DOLLAR_OPEN_CURLY_BRACES`）も数える
- 投げる呼び出し 7 形: `->validate(`・`?->validate(`・`->validateWithBag(`・`->validated(`（`->` か `?->` の後）／ `::validate(`・`::withMessages(`（`::` の後）／ `new ValidationException` ／ `ValidationException::class`。名前は完全一致で、大文字小文字を区別しない
- 判定: B（投げる文に `->redirectTo(` が続く）→ A（囲む try を内側から。受け止める最初の catch で決める）の順。理由の文言は見本と同じ 6 通り
- `scan()` はここで足す（Task 6 の実物のテストが使う）

- [ ] **Step 2: 緑を確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-validation-scan && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/ImportControllerValidationRedirectScanTest.php 2>&1 | tail -1
```

期待: `OK (75 tests, 113 assertions)`

## Task 6: 実物の取込のコントローラを全件分類する（例外リストは空から）

**Files:**
- Modify: `tests/Feature/ImportControllerValidationRedirectScanTest.php`（定数 2 つと実物のテスト 3 本を足す）

- [ ] **Step 1: 定数（例外リストは空）と実物のテストを足す**

置き換える前:

```php
    private const GENERIC_CATCHES = ['exception', 'throwable'];

    // ================================================================
    // 走査の自己テスト（見本の無い分かれ目は、消しても全テストが緑になる。Bug #61 ③）
```

置き換えた後:

```php
    private const GENERIC_CATCHES = ['exception', 'throwable'];

    /** 入力チェックの例外を投げる呼び出しの総数の下限（2026-09-25 実測 10 件）。下回ったら検出が空振りしている */
    private const MIN_THROWING_CALLS = 10;

    /** @var array<string, array{0: int, 1: string}> 相対パス => [包んでいない呼び出しの件数, 理由] */
    private const ALLOWED = [];

    // ================================================================
    // 実物の取込のコントローラ
    // ================================================================

    public function test_the_scan_finds_the_calls_that_throw_validation_exceptions(): void
    {
        $counts = array_map(fn ($calls) => is_array($calls) ? count($calls) : 0, $this->scan());

        $this->assertGreaterThanOrEqual(
            self::MIN_THROWING_CALLS,
            array_sum($counts),
            '入力チェックの例外を投げる呼び出しが少なすぎる（検出が空振りしている）: '
                . json_encode($counts, JSON_UNESCAPED_SLASHES)
        );
    }

    public function test_import_controllers_send_every_validation_failure_to_a_fixed_page(): void
    {
        $scan = $this->scan();
        $problems = [];

        foreach ($scan as $path => $calls) {
            if (is_string($calls)) {
                $problems[] = "{$path}: 解析できない（{$calls}）";

                continue;
            }

            $unwrapped = array_values(array_filter($calls, fn (array $call) => $call['reason'] !== null));
            $expected = self::ALLOWED[$path][0] ?? 0;

            if (count($unwrapped) !== $expected) {
                $problems[] = "{$path}: 包んでいない呼び出しが " . count($unwrapped) . " 件（分類は {$expected}）: "
                    . implode(' / ', array_map(fn (array $call) => ":{$call['line']} {$call['form']}{$call['reason']}", $unwrapped));
            }
        }

        foreach (array_keys(self::ALLOWED) as $path) {
            if (! array_key_exists($path, $scan)) {
                $problems[] = "{$path}: 分類にあるのに、そのファイルが無い（古い項目）";
            }
        }

        $this->assertSame([], $problems, '取込のコントローラが入力チェックの例外を包んでいない（既定の戻り先はリファラーで、'
            . '確認画面の URL が POST 専用だと 405。try で包み catch (ValidationException $e) { throw $e->redirectTo(route(…)); } '
            . "で取込の画面へ戻す。docs/RULES.md Bug #64）:\n" . implode("\n", $problems));
    }

    public function test_import_controllers_take_no_form_request(): void
    {
        $found = [];

        foreach (array_keys($this->importControllerFiles()) as $path) {
            $this->assertStringStartsWith('app/', $path);
            $class = 'App\\' . str_replace(['/', '.php'], ['\\', ''], substr($path, strlen('app/')));
            $this->assertTrue(class_exists($class), "{$path}: クラス {$class} が見つからない");

            foreach ($this->formRequestParameters(new \ReflectionClass($class)) as $parameter) {
                $found[] = "{$path}: {$parameter}";
            }
        }

        $this->assertSame([], $found, '取込のコントローラが FormRequest を受けている（FormRequest の既定の戻り先はリファラーで、'
            . "確認画面の URL が POST 専用だと 405。docs/RULES.md Bug #64）:\n" . implode("\n", $found));
    }

    // ================================================================
    // 走査の自己テスト（見本の無い分かれ目は、消しても全テストが緑になる。Bug #61 ③）
```

- [ ] **Step 2: 赤を確かめる**（走査が実物の 3 か所を見つけている証拠）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-validation-scan && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/ImportControllerValidationRedirectScanTest.php 2>&1 | grep -E -e '^app/Http/Controllers/.*包んでいない呼び出しが' -e '^Tests:'
```

期待（3 本を行番号つきで名指しし、ほかは緑）:

```
app/Http/Controllers/Admin/CustomerImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :28 ->validate(（try の外）
app/Http/Controllers/Tenant/AreaBuildingImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :85 ->validate(（try の外）
app/Http/Controllers/Zeal/SheetImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :58 ->validate(（try の外）
Tests: 78, Assertions: 139, Failures: 1.
```

- [ ] **Step 3: 例外リストを埋める**（理由の文言は設計書 §4.6）

置き換える前:

```php
    /** @var array<string, array{0: int, 1: string}> 相対パス => [包んでいない呼び出しの件数, 理由] */
    private const ALLOWED = [];
```

置き換えた後:

```php
    /** @var array<string, array{0: int, 1: string}> 相対パス => [包んでいない呼び出しの件数, 理由] */
    private const ALLOWED = [
        'app/Http/Controllers/Admin/CustomerImportController.php' => [
            1,
            '確認画面の URL が取込の画面と同じ（GET と POST がどちらも /admin/customers/import）。'
                . 'リファラーへ戻ると取込の画面が GET で開く（2026-09-24 実測 200）',
        ],
        'app/Http/Controllers/Tenant/AreaBuildingImportController.php' => [
            1,
            '確認は画面の中（SheetJS）で行い、確定の送信元は GET の取込の画面。'
                . 'リファラーへ戻ると取込の画面が開く（2026-09-24 実測 200）',
        ],
        'app/Http/Controllers/Zeal/SheetImportController.php' => [
            1,
            'updateUrls（PUT）の送信元は GET の URL 編集画面（editUrls が描く zeal/simulations/sheet-import/urls.blade.php）だけ。'
                . 'リファラーへ戻ると編集画面が GET で開く（2026-09-25 にルートとビューで確認）',
        ],
    ];
```

- [ ] **Step 4: 緑を確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-validation-scan && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/ImportControllerValidationRedirectScanTest.php 2>&1 | tail -1
```

期待: `OK (78 tests, 139 assertions)`

- [ ] **Step 5: 全件テスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-validation-scan && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

期待: `OK (2247 tests, 14735 assertions)`（違ったら差を説明できるまで調べる。`JapaneseValidationMessagesTest` は本番のコードを見るので変わらない）

- [ ] **Step 6: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-validation-scan && git add tests/Feature/ImportControllerValidationRedirectScanTest.php && git commit -m "$(cat <<'EOF'
test: 取込の入力チェックの包み忘れを全件分類で止める走査を足す

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

## Task 7: 変異テスト（docs/RULES.md Bug #44 / #50 の作法）

**Files:** なし（隔離した worktree の中だけで変え、戻す。記録はこの計画の末尾の「実測記録」）

作法: 先にコミット（Task 6 まで済み）→ 隔離した worktree（`git worktree add --detach`）に vendor を**実体コピー**（`cp -Rc`。symlink は不可＝ Bug #50）→ 最初にカナリア → 変異 1 つごとに、前後で作業ツリーが空・置き換えがちょうど 1 か所・着弾を確認・`--log-junit` で流す・落ちたテストと**理由の文言**まで記録・戻したあと空を再確認。実行役 `mutate.py` がこの確認を全部行い、1 つでも食い違えば止まる。

**テスト側の変異を 2 本だけで流す理由:** テスト側の変異が変えるのは `ImportControllerValidationRedirectScanTest.php`・`ImportControllerReturnPathScanTest.php`・`tests/Concerns/ScansImportControllers.php` の 3 つで、ほかのテストはこれらを読まない（Step 1 の grep で確かめる）。ほかのテストの結果は原理的に変わらないので、その 2 本だけを `--log-junit` で流す（1 通り数秒。全件なら 1 通り 100 秒）。本番のコードの変異とカナリアは全件で流す。

- [ ] **Step 1: 隔離した worktree を 3 つ作る**（`SP` はこのセッションの scratchpad＝システムプロンプトの「Scratchpad directory」の絶対パス。`C` は Task 6 のコミット。名前は用途とコミットで一意にする＝ Bug #50 の衝突を避ける。`C` は `$SP/ivs-mut-commit` に控え、以降の Step はそれを読む＝途中でテストを足してコミットしても名前がずれない）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-validation-scan && grep -rl -e 'ScansImportControllers' -e 'ImportControllerValidationRedirectScanTest' -e 'ImportControllerReturnPathScanTest' tests
```

期待: `tests/Concerns/ScansImportControllers.php`・`tests/Feature/ImportControllerValidationRedirectScanTest.php`・`tests/Feature/ImportControllerReturnPathScanTest.php` の 3 つだけ（ほかのテストが読んでいない）。

```bash
SP=<Scratchpad directory>; C=$(git -C /Users/masanori/site/manage/.claude/worktrees/import-validation-scan rev-parse --short HEAD); echo "$C" > "$SP/ivs-mut-commit"; for s in a b c; do git -C /Users/masanori/site/manage/.claude/worktrees/import-validation-scan worktree add --detach "$SP/ivs-mut-$C-$s" HEAD && cp -Rc /Users/masanori/site/manage/.claude/worktrees/import-validation-scan/vendor "$SP/ivs-mut-$C-$s/vendor"; done; git -C /Users/masanori/site/manage/.claude/worktrees/import-validation-scan worktree list
```

⚠ `vendor` は `.gitignore` 済みなので、コピーしても作業ツリーは空のまま（Step 3 で確かめる）。

- [ ] **Step 2: 実行役を置く**（`$SP/mutate.py`。この内容そのまま。コミットしない）

```python
#!/usr/bin/env python3
"""取込の入力チェックの走査（2026-09-25）の変異テストの実行役。

使い方（隔離した worktree の中で。docs/RULES.md Bug #44 / #50 の作法）:
    python3 mutate.py --iso <隔離した worktree> [ID ...]      # ID を省くと全部
    python3 mutate.py --list                                  # 定義の一覧だけ

1 つごとに: 前に作業ツリーが空 → 置き換えがちょうど 1 か所（新しいファイルは作る）→ git diff --stat / status で着弾を確認 →
phpunit を --log-junit で流す（本番のコードの変異は全件・テストの変異は走査 2 本だけ）→ 落ちたテストと理由の 1 行目を記録 →
git checkout / 削除で戻す → 後に作業ツリーが空。どこかで食い違えば止まる（無効な測定を集めない）。
"""
import argparse, base64, json, os, subprocess, sys, xml.etree.ElementTree as ET

T = "app/Http/Controllers/Admin/TenantImportController.php"
M = "app/Http/Controllers/Admin/MansionImportController.php"
Z = "app/Http/Controllers/Admin/ZealMemberImportController.php"
A = "app/Http/Controllers/Approval/UserImportController.php"
S = "app/Http/Controllers/Housing/ScheduleImportController.php"
C = "app/Http/Controllers/Admin/CustomerImportController.php"
Q = "app/Http/Controllers/Zeal/SheetImportController.php"
NEW = "app/Http/Controllers/Admin/SampleImportController.php"
V = "tests/Feature/ImportControllerValidationRedirectScanTest.php"
R = "tests/Feature/ImportControllerReturnPathScanTest.php"
TR = "tests/Concerns/ScansImportControllers.php"
SCAN_TESTS = [V, R]

RETRY = "// 確認画面からアップロードし直したときも取込の画面へ戻す（クラスの docblock。Bug #64）"


def unwrap_loadcsv(route):
    return (
        "            try {\n"
        "                $request->validate([\n"
        "                    'csv_file' => 'required|file|mimes:csv,txt|max:10240',\n"
        "                ]);\n"
        "            } catch (ValidationException $e) {\n"
        f"                {RETRY}\n"
        f"                throw $e->redirectTo({route});\n"
        "            }\n",
        "            $request->validate([\n"
        "                'csv_file' => 'required|file|mimes:csv,txt|max:10240',\n"
        "            ]);\n",
    )


# (ID, 範囲 full=全件 / scan=走査 2 本, ファイル, 置き換える前（None は新しいファイル）, 置き換えた後)
MUTATIONS = [
    # ---- カナリア（隔離が効いていれば、コピー側のコードが読まれて赤になる）----
    ("C0", "full", C, "        return view('admin.customers.import');\n", "        return view('admin.customers.import-canary');\n"),
    # ---- 本番のコード: 7 か所の try/catch を外す ----
    ("M01", "full", T, *unwrap_loadcsv("route('admin.tenant-import', ['tab' => $tab])")),
    ("M02", "full", M, *unwrap_loadcsv("route('admin.mansion-import', ['selected_tab' => $tab])")),
    ("M03", "full", Z,
        "            try {\n"
        "                $request->validate([\n"
        "                    'csv_file' => 'required|file|mimes:csv,txt|max:10240',\n"
        "                ]);\n"
        "            } catch (ValidationException $e) {\n"
        "                // 確定の送信から confirmed が抜けたときも、確認画面からここへ来る（クラスの docblock。Bug #64）\n"
        "                throw $e->redirectTo(route('admin.zeal.member-import'));\n"
        "            }\n",
        "            $request->validate([\n"
        "                'csv_file' => 'required|file|mimes:csv,txt|max:10240',\n"
        "            ]);\n"),
    ("M04", "full", A,
        "        try {\n"
        "            $request->validate([\n"
        "                'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],\n"
        "            ], [\n"
        "                'csv_file.required' => 'CSVファイルを選択してください。',\n"
        "                'csv_file.mimes'    => 'CSVファイルを選択してください。',\n"
        "            ], [\n"
        "                'csv_file' => 'CSVファイル',\n"
        "            ]);\n"
        "        } catch (ValidationException $e) {\n"
        f"            {RETRY}\n"
        "            throw $e->redirectTo(route('approvals.admin.users.import'));\n"
        "        }\n",
        "        $request->validate([\n"
        "            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],\n"
        "        ], [\n"
        "            'csv_file.required' => 'CSVファイルを選択してください。',\n"
        "            'csv_file.mimes'    => 'CSVファイルを選択してください。',\n"
        "        ], [\n"
        "            'csv_file' => 'CSVファイル',\n"
        "        ]);\n"),
    ("M05", "full", A,
        "        try {\n"
        "            $request->validate([\n"
        "                'csv_data' => ['required', 'string'],\n"
        "            ]);\n"
        "        } catch (ValidationException $e) {\n"
        "            // 確定のフォームも確認画面（POST の応答）に載っている。取込の画面へ戻す（クラスの docblock。Bug #64）\n"
        "            throw $e->redirectTo(route('approvals.admin.users.import'));\n"
        "        }\n",
        "        $request->validate([\n"
        "            'csv_data' => ['required', 'string'],\n"
        "        ]);\n"),
    ("M06", "full", S,
        "        try {\n"
        "            $request->validate([\n"
        "                'file' => 'required|file|mimes:xlsx|max:5120',\n"
        "            ], [], ['file' => '工程表の書き出しファイル']);\n"
        "        } catch (ValidationException $e) {\n"
        f"            {RETRY}\n"
        "            throw $e->redirectTo(route('housing.properties.schedule-import.form', $property));\n"
        "        }\n",
        "        $request->validate([\n"
        "            'file' => 'required|file|mimes:xlsx|max:5120',\n"
        "        ], [], ['file' => '工程表の書き出しファイル']);\n"),
    ("M07", "full", S,
        "        try {\n"
        "            $validated = $request->validate([\n"
        "                'rows_json' => 'required|string',\n"
        "            ], [], ['rows_json' => '取り込む工程']);\n"
        "        } catch (ValidationException $e) {\n"
        "            throw $e->redirectTo(route('housing.properties.schedule-import.form', $property));\n"
        "        }\n",
        "        $validated = $request->validate([\n"
        "            'rows_json' => 'required|string',\n"
        "        ], [], ['rows_json' => '取り込む工程']);\n"),
    # ---- 本番のコード: 包み方を崩す ----
    ("M08", "full", T, "            } catch (ValidationException $e) {\n", "            } catch (\\Exception $e) {\n"),
    ("M09", "full", M, "            } catch (ValidationException $e) {\n", "            } catch (\\Throwable $e) {\n"),
    ("M10", "full", Z, "            } catch (ValidationException $e) {\n",
        "            } catch (\\Exception $e) {\n"
        "                throw $e;\n"
        "            } catch (ValidationException $e) {\n"),
    ("M11", "full", A,
        f"            {RETRY}\n            throw $e->redirectTo(route('approvals.admin.users.import'));\n",
        f"            {RETRY}\n            throw $e;\n"),
    ("M12", "full", S,
        "        } catch (ValidationException $e) {\n"
        "            throw $e->redirectTo(route('housing.properties.schedule-import.form', $property));\n",
        "        } catch (ValidationException $e) {\n"
        "            if ($request->has('debug')) {\n"
        "                throw $e;\n"
        "            }\n"
        "            throw $e->redirectTo(route('housing.properties.schedule-import.form', $property));\n"),
    ("M13", "full", T, "use Illuminate\\Validation\\ValidationException;\n", ""),
    # ---- 本番のコード: 戻り先を今の URL にする（既存の走査が止める）----
    ("M14", "full", M,
        "throw $e->redirectTo(route('admin.mansion-import', ['selected_tab' => $tab]));",
        "throw $e->redirectTo(url()->current());"),
    ("M15", "full", S,
        f"            {RETRY}\n            throw $e->redirectTo(route('housing.properties.schedule-import.form', $property));\n",
        f"            {RETRY}\n            throw $e->redirectTo($request->path());\n"),
    # ---- 本番のコード: 例外リスト・新しい取込・FormRequest ----
    ("M16", "full", C,
        "        $request->validate([\n"
        "            'csv_file'   => 'required|file|mimes:csv,txt|max:10240',\n"
        "            'department' => 'required|in:housing,realestate',\n"
        "        ], [], [\n"
        "            // 画面ラベルに合わせる（既定は「部署」）\n"
        "            'department' => 'インポート先部署',\n"
        "        ]);\n",
        "        try {\n"
        "            $request->validate([\n"
        "                'csv_file'   => 'required|file|mimes:csv,txt|max:10240',\n"
        "                'department' => 'required|in:housing,realestate',\n"
        "            ], [], [\n"
        "                // 画面ラベルに合わせる（既定は「部署」）\n"
        "                'department' => 'インポート先部署',\n"
        "            ]);\n"
        "        } catch (\\Illuminate\\Validation\\ValidationException $e) {\n"
        "            throw $e->redirectTo(route('admin.customers.import'));\n"
        "        }\n"),
    ("M17", "full", NEW, None,
        "<?php\n\nnamespace App\\Http\\Controllers\\Admin;\n\n"
        "use App\\Http\\Controllers\\Controller;\n"
        "use Illuminate\\Http\\Request;\n\n"
        "class SampleImportController extends Controller\n{\n"
        "    public function execute(Request $request)\n    {\n"
        "        $request->validate([\n"
        "            'csv_file' => 'required|file|mimes:csv,txt|max:10240',\n"
        "        ]);\n\n"
        "        return redirect()->route('admin.customers.import');\n"
        "    }\n}\n"),
    ("M18", "full", Q,
        "    /* ===================== Private helpers ===================== */\n",
        "    public function sampleFormRequest(\\Illuminate\\Foundation\\Auth\\EmailVerificationRequest $request)\n"
        "    {\n"
        "    }\n\n"
        "    /* ===================== Private helpers ===================== */\n"),
    # ---- テスト: 検出を弱める（新しい走査）----
    ("X01", "scan", V, "['validate', 'validatewithbag', 'validated']", "['validate', 'validated']"),
    ("X02", "scan", V, "['validate', 'validatewithbag', 'validated']", "['validate', 'validatewithbag']"),
    ("X03", "scan", V, "in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)", "in_array($token[0], [T_OBJECT_OPERATOR], true)"),
    ("X04", "scan", V, "['validate', 'withmessages']", "['validate']"),
    ("X05", "scan", V, "} elseif ($token[0] === T_NEW && $this->isName($next)", "} elseif (false && $token[0] === T_NEW && $this->isName($next)"),
    ("X06", "scan", V, "} elseif ($this->isName($token) && $next[0] === T_DOUBLE_COLON && $after[0] === T_CLASS", "} elseif (false && $this->isName($token) && $next[0] === T_DOUBLE_COLON && $after[0] === T_CLASS"),
    ("X07", "scan", V, "? strtolower($next[1]) : null;", "? $next[1] : null;"),
    ("X08", "scan", V, "[T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG]", "[T_WHITESPACE, T_DOC_COMMENT, T_OPEN_TAG]"),
    ("X09", "scan", V, "[T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG]", "[T_WHITESPACE, T_COMMENT, T_OPEN_TAG]"),
    # ---- テスト: 判定を弱める（新しい走査）----
    ("X10", "scan", V, "                    if (in_array($resolved, self::GENERIC_CATCHES, true)) {\n                        return \"（{$type} の catch が先に受け止める）\";\n                    }\n", ""),
    ("X11", "scan", V, "return $k === count($body) - 1;", "return true;"),
    ("X12", "scan", V, "        if ($throw[0] !== T_THROW\n", "        if (false && $throw[0] !== T_THROW\n"),
    ("X13", "scan", V, "            || $method[0] !== T_STRING || strcasecmp($method[1], 'redirectTo') !== 0\n", ""),
    ("X14", "scan", V, "            || ! $this->is($paren, '(')) {", "            ) {"),
    ("X15", "scan", V, "            } elseif ($depth === 0 && $this->is($token, ';')) {\n                return $k === count($body) - 1;", "            } elseif ($this->is($token, ';')) {\n                return $k === count($body) - 1;"),
    ("X16", "scan", V, "if ($catch['var'] === null) {", "if (false) {"),
    ("X17", "scan", V, "$types[] = $tokens[$k][1];", "$types = $types ?: [$tokens[$k][1]];"),
    ("X18", "scan", V, "        usort($enclosing, fn (array $a, array $b) => $b['open'] <=> $a['open']);\n", ""),
    ("X19", "scan", V, "fn (array $try) => $try['open'] < $call && $call < $try['close']", "fn (array $try) => $try['open'] < $call && $call < ($try['catches'] === [] ? $try['close'] : $try['catches'][array_key_last($try['catches'])]['close'])"),
    ("X20", "scan", V, "$hint = '（use の無い ValidationException を受けている）';", "$hint = null;"),
    ("X21", "scan", V, "if ($this->throwBefore($tokens, $call) && $this->redirectToAfter($tokens, $call)) {", "if (false) {"),
    ("X22", "scan", V, "} elseif ($depth === 0 && $token[0] === T_OBJECT_OPERATOR) {", "} elseif ($token[0] === T_OBJECT_OPERATOR) {"),
    ("X23", "scan", V, "            } elseif ($depth === 0 && $this->is($token, ';')) {\n                return false;\n            } elseif ($depth === 0 && $token[0] === T_OBJECT_OPERATOR) {", "            } elseif ($depth === 0 && $token[0] === T_OBJECT_OPERATOR) {"),
    # ---- テスト: 名前の解決を弱める（新しい走査）----
    ("X24", "scan", V, "        if (isset($imports[$first])) {", "        if (false && isset($imports[$first])) {"),
    ("X25", "scan", V, "return $imports[$first] . substr($lower, strlen($first));", "return $imports[$first];"),
    ("X26", "scan", V, "$full = strtolower(ltrim($name[1], '\\\\'));", "$full = strtolower($name[1]);"),
    ("X27", "scan", V, "if (in_array($tokens[$j][0] ?? null, [T_FUNCTION, T_CONST], true)) {", "if (false) {"),
    ("X28", "scan", V, "                $depth++;\n            } elseif ($this->is($token, '}')) {\n                $depth--;", "                // 深さを数えない\n            } elseif ($this->is($token, '}')) {\n                // 深さを数えない"),
    # ---- テスト: 解析できない書き方を通してしまう（新しい走査）----
    ("X29", "scan", V, "            throw new \\UnexpectedValueException(\"{$line} 行目: まとめた use（1 文で 2 つ以上を読み込んでいる）\");", "            while (isset($tokens[$j]) && ! $this->is($tokens[$j], ';')) { $j++; }"),
    ("X30", "scan", V, "        if (! $this->isName($name)) {\n            throw new \\UnexpectedValueException(\"{$line} 行目: 読めない use\");\n        }\n", ""),
    ("X31", "scan", V, "if ($namespace !== null) {", "if (false) {"),
    ("X32", "scan", V, "if (! in_array($name[0], [T_STRING, T_NAME_QUALIFIED], true) || ! $this->is($tokens[$i + 2] ?? [null, '', 0], ';')) {", "if (! in_array($name[0], [T_STRING, T_NAME_QUALIFIED], true)) {"),
    ("X33", "scan", V, "                if ($open === []) {", "                if (false) {"),
    ("X34", "scan", V, "        if ($open !== []) {", "        if (false) {"),
    ("X35", "scan", V, "in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)", "in_array($token[0], [T_DOLLAR_OPEN_CURLY_BRACES], true)"),
    ("X36", "scan", V, "in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)", "in_array($token[0], [T_CURLY_OPEN], true)"),
    # ---- テスト: FormRequest・分類・列挙 ----
    ("X37", "scan", V, "if ($type instanceof \\ReflectionUnionType || $type instanceof \\ReflectionIntersectionType) {", "if (false) {"),
    ("X38", "scan", V, "$class->getMethods(\\ReflectionMethod::IS_PUBLIC)", "$class->getMethods()"),
    ("X39", "scan", V, "        'app/Http/Controllers/Admin/CustomerImportController.php' => [\n            1,", "        'app/Http/Controllers/Admin/CustomerImportController.php' => [\n            2,"),
    ("X40", "scan", V, "    private const ALLOWED = [\n", "    private const ALLOWED = [\n        'app/Http/Controllers/Admin/GoneImportController.php' => [1, '古い項目の変異'],\n"),
    ("X41", "scan", TR, "app_path('Http/Controllers')", "app_path('Http/Controllers/Admin')"),
    # ---- テスト: 既存の走査に足した検出を弱める ----
    ("X42", "scan", R, "            '/(?:(?<![\\w$>:])url\\s*\\(\\s*\\)\\s*->|(?<![\\w$])URL\\s*::|app\\s*\\(\\s*[\\'\"]url[\\'\"]\\s*\\)\\s*->)\\s*(?:current|full)\\s*\\(/',\n", ""),
    ("X43", "scan", R, "|(?<![\\w$])Request\\s*::)", ")"),
    ("X44", "scan", R, "|getUri|getRequestUri|path|decodedPath|getPathInfo)", "|getUri|getRequestUri)"),
    ("X45", "scan", R, "|(?<![\\w$])Request\\s*::)", "|Request\\s*::)"),
    ("X46", "scan", R, "(?<![\\w$>:])url\\s*\\(", "url\\s*\\("),
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
    ap.add_argument("ids", nargs="*")
    args = ap.parse_args()

    if args.list:
        for mid, scope, path, old, new in MUTATIONS:
            print(f"{mid}\t{scope}\t{path}")
        return

    iso = os.path.abspath(args.iso)
    out = os.path.join(iso, "..", os.path.basename(iso) + "-results.jsonl")
    for mid, scope, path, old, new in MUTATIONS:
        if args.ids and mid not in args.ids:
            continue
        if git(iso, "status", "--porcelain").strip():
            sys.exit(f"{mid}: 始める前に作業ツリーが空でない（前の変異の残骸）。止める")
        full = os.path.join(iso, path)
        if old is None:
            if os.path.exists(full):
                sys.exit(f"{mid}: 作るはずのファイルが既にある。止める")
            open(full, "w", encoding="utf-8").write(new)
        else:
            src = open(full, encoding="utf-8").read()
            n = src.count(old)
            if n != 1:
                sys.exit(f"{mid}: 置き換えが {n} か所（1 でない）。止める")
            open(full, "w", encoding="utf-8").write(src.replace(old, new))
        landed = git(iso, "status", "--porcelain").strip()
        stat = git(iso, "diff", "--stat").strip().splitlines()
        if not landed:
            sys.exit(f"{mid}: 置き換えたのに作業ツリーが空（当たっていない）。止める")
        junit = os.path.join(iso, "..", f"junit-{os.path.basename(iso)}-{mid}.xml")
        try:
            if scope == "full":
                failures = run_phpunit(iso, junit)
            else:
                failures = []
                for target in SCAN_TESTS:
                    got = run_phpunit(iso, junit, target)
                    failures = None if got is None or failures is None else failures + got
        finally:
            if old is None:
                os.remove(full)
            else:
                git(iso, "checkout", "--", path)
        if git(iso, "status", "--porcelain").strip():
            sys.exit(f"{mid}: 戻したのに作業ツリーが空でない。止める")
        record = {"id": mid, "scope": scope, "file": path, "diff": stat[-1] if stat else landed, "failures": failures}
        with open(out, "a", encoding="utf-8") as f:
            f.write(json.dumps(record, ensure_ascii=False) + "\n")
        count = "junit なし（phpunit が起動しなかった）" if failures is None else f"{len(failures)} 件"
        print(f"== {mid} ({scope}) {path}  落ちたテスト {count}")
        for fl in failures or []:
            print(f"   {fl['test']} :: {fl['reason']}")
    print(f"記録: {out}")


if __name__ == "__main__":
    main()
```

- [ ] **Step 3: カナリアを 3 つのコピーで通す**（隔離が効いていれば、コピー側のコードが読まれて赤になる）

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/ivs-mut-commit"); for s in a b c; do git -C "$SP/ivs-mut-$C-$s" status --porcelain | head -3; python3 "$SP/mutate.py" --iso "$SP/ivs-mut-$C-$s" C0; done
```

期待: 作業ツリーはどれも空（`status` は何も出さない）。3 つのコピーそれぞれで、C0 は顧客の取込の画面を開くテスト（`ImportValidationFeedbackTest` の顧客CSV など）が `View [admin.customers.import-canary] not found` で落ちる。**どれか 1 つでも赤にならなければ止める**（コピーでなく元の worktree のコードが読まれている＝ Bug #50）。全件を 3 回流すので 5 分ほどかかる（`run_in_background` で起動して終わりを待つ）。

- [ ] **Step 4: 変異を流す**（下の 3 つを Bash の `run_in_background` で**同時に**起動し、3 つとも終わりの知らせを待つ。途中で作業ツリーやログを覗いて判断しない＝変異の途中を拾うと偽の赤になる。a と b はそれぞれ全件 9 回で 15〜25 分、c は数分）

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/ivs-mut-commit"); python3 "$SP/mutate.py" --iso "$SP/ivs-mut-$C-a" M01 M02 M03 M04 M05 M06 M07 M08 M09 > "$SP/ivs-mut-$C-a.log" 2>&1
```

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/ivs-mut-commit"); python3 "$SP/mutate.py" --iso "$SP/ivs-mut-$C-b" M10 M11 M12 M13 M14 M15 M16 M17 M18 > "$SP/ivs-mut-$C-b.log" 2>&1
```

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/ivs-mut-commit"); python3 "$SP/mutate.py" --iso "$SP/ivs-mut-$C-c" $(python3 "$SP/mutate.py" --list | awk -F'\t' '$1 ~ /^X/ {print $1}') > "$SP/ivs-mut-$C-c.log" 2>&1
```

- [ ] **Step 5: 期待と突き合わせる**（下の 2 つの表。**落ちたテストの集合と理由の文言まで**）

- 期待と違ったら（緑のまま・別のテストが落ちた・理由が違う）、理由を調べる。テストの穴ならテストを足してコミットし、その変異を当て直す。当て直す前に、隔離した worktree を新しいコミットへ進める（名前は控えの `C` のまま）:

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/ivs-mut-commit"); NEW=$(git -C /Users/masanori/site/manage/.claude/worktrees/import-validation-scan rev-parse HEAD); for s in a b c; do git -C "$SP/ivs-mut-$C-$s" status --porcelain && git -C "$SP/ivs-mut-$C-$s" checkout --detach "$NEW"; done
```

- ⚠ 本番のコードの変異の「挙動のテスト」の列は、前の作業（`docs/superpowers/plans/2026-09-24-import-preview-back.md` の T02・M02・Z04・S01・S03 など）の実測から書いた見込み。走査の列（新しい走査・既存の走査の文言）はメモリの上で当てて確かめた値

**本番のコードに当てる変異（全件で流す）:**

| ID | 当てる場所 | 変異 | 期待（落ちるテストと理由）|
|---|---|---|---|
| C0 | 顧客 `showForm()` | ビュー名を `admin.customers.import-canary` に（カナリア） | 顧客の取込の画面を開くテスト（`ImportValidationFeedbackTest` の顧客CSV など）が `View [admin.customers.import-canary] not found`。走査は 2 本とも緑 |
| M01 | テナント `loadCsv()`（:1265） | try/catch を外す | 新しい走査「`TenantImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :1264 ->validate(（try の外）`」＋ `TenantImportRejectionTest`（ファイルを選ばずに送った）＋ `ImportValidationFeedbackTest`（テナントCSV） |
| M02 | 賃貸マンション `loadCsv()`（:1392） | try/catch を外す | 新しい走査「`MansionImportController.php: … :1391 ->validate(（try の外）`」＋ `MansionImportRejectionTest`（ファイルを選ばずに送った）＋ `ImportValidationFeedbackTest`（賃貸マンションCSV） |
| M03 | ZEAL 会員 `loadCsv()`（:234） | try/catch を外す | 新しい走査「`ZealMemberImportController.php: … :233 ->validate(（try の外）`」＋ `ZealMemberImportControllerTest::test_a_confirmation_without_the_confirmed_flag_goes_back_to_the_import_screen` |
| M04 | 決裁 `preview()`（:73） | try/catch を外す | 新しい走査「`Approval/UserImportController.php: … :72 ->validate(（try の外）`」＋ `ApprovalUserImportTest::test_a_failed_reupload_from_the_preview_goes_back_to_the_import_screen`（ファイルを選び忘れた） |
| M05 | 決裁 `execute()`（:103） | try/catch を外す | 新しい走査「`… :102 ->validate(（try の外）`」＋ `ApprovalUserImportTest::test_a_broken_confirmation_goes_back_to_the_import_screen` |
| M06 | 工程表 `preview()`（:56） | try/catch を外す | 新しい走査「`ScheduleImportController.php: … :55 ->validate(（try の外）`」＋ `ScheduleImportTest::test_a_rejection_from_the_preview_goes_back_to_the_import_form`（送り直し: ファイルを選ばなかった） |
| M07 | 工程表 `execute()`（:91） | try/catch を外す | 新しい走査「`… :90 ->validate(（try の外）`」＋ 同じテストの「確定: 取り込む工程が無い」 |
| M08 | テナント | catch を `\Exception` に | **新しい走査だけ**「`:1265 ->validate(（\Exception の catch が先に受け止める）`」（受けた `$e` は ValidationException なので `redirectTo()` が効き、挙動のテストは緑＝挙動では見えない） |
| M09 | 賃貸マンション | catch を `\Throwable` に | **新しい走査だけ**「`:1392 ->validate(（\Throwable の catch が先に受け止める）`」 |
| M10 | ZEAL 会員 | 前に `catch (\Exception $e) { throw $e; }` を足す | 新しい走査「`:234 ->validate(（\Exception の catch が先に受け止める）`」＋ M03 と同じ ZEAL のケース |
| M11 | 決裁 `preview()` | 本体を `throw $e;` に | 新しい走査「`:73 ->validate(（catch の本体が throw $e->redirectTo(…) の 1 文でない）`」＋ M04 と同じ決裁のケース |
| M12 | 工程表 `execute()` | 本体の前に `if ($request->has('debug')) { throw $e; }` | **新しい走査だけ**「`:91 ->validate(（catch の本体が throw $e->redirectTo(…) の 1 文でない）`」（挙動のテストは debug を送らない） |
| M13 | テナント | `use Illuminate\Validation\ValidationException;` を消す | 新しい走査「`:1264 ->validate(（use の無い ValidationException を受けている）`」＋ M01 と同じ挙動のテスト（catch が別のクラスを指して受け止めない） |
| M14 | 賃貸マンション | 戻り先を `url()->current()` に | **既存の走査**「`MansionImportController.php: 件数が 1（分類は 0）: :1397 url()->current(`」＋ `MansionImportRejectionTest`（ファイルを選ばずに送った）＋ `ImportValidationFeedbackTest`（賃貸マンションCSV）。新しい走査は緑（包み方は正しい＝分担どおり） |
| M15 | 工程表 `preview()` | 戻り先を `$request->path()` に | **既存の走査**「`ScheduleImportController.php: 件数が 1（分類は 0）: :61 $request->path(`」＋ `ScheduleImportTest`（送り直し: ファイルを選ばなかった）。新しい走査は緑 |
| M16 | 顧客 | 入力チェックを正しく包む（完全な名前の catch で受けて取込の画面へ） | **新しい走査だけ**「`CustomerImportController.php: 包んでいない呼び出しが 0 件（分類は 1）: `」（例外リストが古くなったら止まる） |
| M17 | 新しいファイル `Admin/SampleImportController.php` | 包んでいない入力チェックを持つ取込を足す | **新しい走査だけ**「`SampleImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :12 ->validate(（try の外）`」（新しい取込は自動で検査対象に入る） |
| M18 | シート取込 | FormRequest を受ける public メソッドを足す | **新しい走査の FormRequest のテストだけ**「`Zeal/SheetImportController.php: sampleFormRequest($request: Illuminate\Foundation\Auth\EmailVerificationRequest)`」 |

**テストに当てる変異（走査の 2 本だけで流す。期待は試作に当てた実測）:**

| ID | 変異 | 期待（試作で落ちたテスト）|
|---|---|---|
| X01 | 検出するメソッド名から `validateWithBag` を外す | 1 本: 拾う見本（->validateWithBag(） |
| X02 | `validated` を外す | 1 本: 拾う見本（->validated(） |
| X03 | `?->` を外す | 1 本: 拾う見本（?->validate(） |
| X04 | `::withMessages(` を外す | 4 本: 拾う見本（::withMessages(） ＋ 包んである見本（withMessages() に続く ->redirectTo(） ＋ 包んでいない見本（redirectTo の無い withMessages()（次の文の ->redirectTo( は続いたことにしない）・引数の中の ->redirectTo( は続いたことにしない） |
| X05 | `new ValidationException` の検出を外す | 6 本: 拾う見本（new ValidationException・完全な名前の new・as の別名の new・小文字のクラス名の new・先頭に \ のある use） ＋ 包んである見本（(new ValidationException()) に続く ->redirectTo(） |
| X06 | `ValidationException::class` の検出を外す | 1 本: 拾う見本（ValidationException::class） |
| X07 | メソッド名の大文字小文字を区別する | 6 本: 拾う見本（->validateWithBag(・::withMessages(・大文字の ->Validate(） ＋ 包んである見本（withMessages() に続く ->redirectTo(） ＋ 包んでいない見本（redirectTo の無い withMessages()（次の文の ->redirectTo( は続いたことにしない）・引数の中の ->redirectTo( は続いたことにしない） |
| X08 | `//`・`#`・`/* */` のコメントを落とさない | 3 本: 実物の分類 ＋ 拾う見本（コメントをはさむ） ＋ 包んである見本（今の書き方（catch の中の // コメント）） |
| X09 | `/** */` を落とさない | 1 本: 包んである見本（catch の中の /** */ コメント） |
| X10 | `\Exception`・`\Throwable` の catch を見ない | 4 本: 包んでいない見本（\Exception の catch が先・\Throwable の catch が先・use Exception; のある Exception の catch が先・A \| \Exception の catch） |
| X11 | 本体の最初の `;` の後ろを見ない（1 文だけかを見ない） | 1 本: 包んでいない見本（本体の後にもう 1 文） |
| X12 | 本体が `throw` で始まるかを見ない | 1 本: 包んでいない見本（本体が throw でなく return） |
| X13 | 本体のメソッド名が `redirectTo` かを見ない | 1 本: 包んでいない見本（本体が redirectTo でない別のメソッドで投げ直す） |
| X14 | 本体の `redirectTo` が呼び出し（`(`）かを見ない | 1 本: 包んでいない見本（本体の redirectTo が呼び出しでない） |
| X15 | 本体の `;` を括弧の深さに関係なく数える | 1 本: 包んである見本（redirectTo の引数の中の ;（クロージャ）） |
| X16 | 変数の無い catch を見分けない | 1 本: 包んでいない見本（変数の無い catch） |
| X17 | `A \| B` の catch の 2 つ目以降の型を見ない | 2 本: 包んである見本（A \| B の catch） ＋ 包んでいない見本（A \| \Exception の catch） |
| X18 | 囲む try を内側から見ない（出てきた順） | 1 本: 包んでいない見本（内側の try の catch で決める（外側の catch は見ない）） |
| X19 | catch の本体の中の呼び出しも try の中とみなす | 1 本: 包んでいない見本（呼び出しが catch の中） |
| X20 | 「use の無い ValidationException」の理由を出さない | 1 本: 包んでいない見本（use の無い ValidationException の catch） |
| X21 | B（投げる文の `->redirectTo(`）を見ない | 2 本: 包んである見本（withMessages() に続く ->redirectTo(・(new ValidationException()) に続く ->redirectTo(） |
| X22 | B で括弧の深さを見ない | 1 本: 包んでいない見本（引数の中の ->redirectTo( は続いたことにしない） |
| X23 | B で文の終わり（`;`）で止まらない | 1 本: 包んでいない見本（redirectTo の無い withMessages()（次の文の ->redirectTo( は続いたことにしない）） |
| X24 | 冒頭の use で名前を解決しない | 31 本: 実物の分類 ＋ 拾う見本（new ValidationException・ValidationException::class・as の別名の new・小文字のクラス名の new・先頭に \ のある use） ＋ 包んである見本 13 本 ＋ 包んでいない見本 12 本 |
| X25 | 修飾名の後ろ半分を捨てる | 1 本: 包んである見本（名前空間の一部を use した修飾名の catch） |
| X26 | use の先頭の `\` を落とさない | 1 本: 拾う見本（先頭に \ のある use） |
| X27 | `use function`・`use const` を飛ばさない | 1 本: 包んである見本（use function・use const のあるファイル） |
| X28 | 冒頭の use を探すときに波括弧の深さを数えない | 3 本: 実物の下限 ＋ 実物の分類 ＋ 包んである見本（クロージャの use をはさむ（冒頭の use と取り違えない）） |
| X29 | まとめた use を読み飛ばす | 2 本: 解析できない見本（まとめた use（{ }）・まとめた use（,）） |
| X30 | 名前の続かない冒頭の use を通す | 1 本: 解析できない見本（冒頭のクロージャの use） |
| X31 | namespace が 2 つでも読む | 1 本: 解析できない見本（namespace が 2 つ） |
| X32 | `namespace X { … }` でも読む | 1 本: 解析できない見本（namespace X { … }） |
| X33 | 閉じの多い波括弧を見逃す | 1 本: 解析できない見本（閉じの多い波括弧） |
| X34 | 閉じの足りない波括弧を見逃す | 1 本: 解析できない見本（閉じの足りない波括弧） |
| X35 | 文字列の中の `{$` を数えない | 3 本: 実物の下限 ＋ 実物の分類 ＋ 包んである見本（文字列の中の {$…} と ${…} をはさむ） |
| X36 | 文字列の中の `${` を数えない | 1 本: 包んである見本（文字列の中の {$…} と ${…} をはさむ） |
| X37 | FormRequest の型で `A\|B`・`A&B` を見ない | 1 本: FormRequest の自己テスト |
| X38 | FormRequest を public でないメソッドまで見る | 1 本: FormRequest の自己テスト |
| X39 | 例外リストの件数を変える（顧客 1 → 2） | 1 本: 実物の分類 |
| X40 | 例外リストに実在しないファイルを足す | 1 本: 実物の分類 |
| X41 | 列挙を `Admin` だけに狭める（トレイト） | 5 本: 実物の下限 ＋ 実物の分類 ＋ 実物の FormRequest ＋ 既存の走査の下限 ＋ 既存の走査の分類 |
| X42 | 既存の走査: 6.（今の URL を作る）を消す | 1 本: 既存の走査の自己テスト。理由「拾えていない: return redirect(url()->current());」 |
| X43 | 既存の走査: 5. の `Request::` を消す | 1 本: 既存の走査の自己テスト。理由「拾えていない: return redirect(Request::url());」 |
| X44 | 既存の走査: 5. から `path`・`decodedPath`・`getPathInfo` を消す | 1 本: 既存の走査の自己テスト。理由「拾えていない: return redirect(\Illuminate\Support\Facades\Request::getPathInfo());」 |
| X45 | 既存の走査: `Request::` の前の「語の途中でない」を外す | 1 本: 既存の走査の自己テスト。理由「拾うべきでない: $u = FormRequest::url();」 |
| X46 | 既存の走査: `url()` の前の「ほかのメソッドでない」を外す | 1 本: 既存の走査の自己テスト。理由「拾うべきでない: $u = $menu->url()->current();」 |

- [ ] **Step 6: 片づけて記録をコミット**

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/ivs-mut-commit"); for s in a b c; do git -C /Users/masanori/site/manage/.claude/worktrees/import-validation-scan worktree remove --force "$SP/ivs-mut-$C-$s"; done; git -C /Users/masanori/site/manage/.claude/worktrees/import-validation-scan worktree list
```

この計画の末尾の「実測記録」に、2 つの表（ID・落ちたテスト・理由）と、期待と違ったもの・その対応を書いてコミットする:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-validation-scan && git add docs/superpowers/plans/2026-09-25-import-validation-scan.md && git commit -m "$(cat <<'EOF'
docs: 取込の入力チェックの走査の変異テストの結果を記録する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

## Task 8: 独立レビュー（1 回）

- [ ] **Step 1: レビューを頼む**（`superpowers:requesting-code-review` か `feature-dev:code-reviewer` のサブエージェント。範囲は Task 2〜6 のコミット `git diff 2b7b5bad..HEAD -- tests`）。頼む観点:
  - 走査が**見落とす**書き方（包んでいないのに包んであると判定する＝ false negative）と、**拾いすぎる**書き方
  - トークンの扱い（波括弧・文字列の中の `{$`・コメント・`?->`・名前の解決）の誤り
  - 自己テストの見本が、その分かれ目を本当に押さえているか（見本を消しても緑になる分かれ目が無いか）
  - docblock と設計書 §4.9 の「見えないもの」が実態と合っているか
  - 既存の走査の正規表現の拾いすぎ・見落とし
- [ ] **Step 2: 指摘は実測してから直す**（`superpowers:receiving-code-review`）。直したら、その指摘に関わる変異を当て直す（Task 7 の手順）。直しは 1 つの関心事ごとにコミット（`test: …`）。直さない指摘は理由を「実測記録」に書く
- [ ] **Step 3: 全件テスト**（期待: 緑。本数は足した見本の分だけ増える）

## Task 9: 文書

**Files:**
- Modify: `docs/RULES.md`（Bug #64 の 1 段落）・`CLAUDE.md`（1 行）・`docs/BACKLOG.md`（既存の節の 2 行・新しい節・完了状況の 1 段落）・この計画（実測記録）

- [ ] **Step 1: `docs/RULES.md`**（Bug #64 の行の中の 1 段落を置き換える。表の 1 行の途中なので改行を入れない）

置き換える前:

```text
⚠ **走査に見えない形がある** — try で包まない入力チェック（`validate()`・`Validator::make()->validate()`・`ValidationException::withMessages()`。既定の戻り先がリファラー）は字面に `back(` が現れないので、挙動のテスト（`from(確認画面の URL)` で送る）が守る: `TenantImportRejectionTest` / `MansionImportRejectionTest` / `ZealMemberImportControllerTest` / `ScheduleImportTest`（新しい取込には守り手がいない）。トレイトやサービスへ切り出した `back()` も見えない。
```

置き換えた後（変異の数は Task 7・8 の実測に合わせる）:

```text
⚠ **入力チェックの包み方は別の走査が守る**（2026-09-25）— try で包まない入力チェック（`validate()`・`Validator::make()->validate()`・`ValidationException::withMessages()`。既定の戻り先がリファラー）は字面に `back(` が現れないので、この走査には見えない。`ImportControllerValidationRedirectScanTest` が同じ `*ImportController.php`（列挙は `tests/Concerns/ScansImportControllers.php` で共用）を `token_get_all()` のトークンで読み、入力チェックの例外を投げる呼び出し（`->validate(`・`?->validate(`・`->validateWithBag(`・`->validated(`・`::validate(`・`::withMessages(`・`new ValidationException`・`ValidationException::class`）ごとに、①囲む try の catch のうち ValidationException を受け止める最初のもの（内側の try から。`\Exception`・`\Throwable` が先なら不合格）が ValidationException を名指しし、本体が `throw $e->redirectTo(…);` の 1 文だけか ②投げる文そのものに `->redirectTo(` が続くか、を見て全件分類する（包んでいないのを許すのは顧客・周辺ビル・経営試算表の URL 保存 `updateUrls` の 3 か所だけ・件数と理由つき。FormRequest を受ける public メソッドは Reflection で探して落とす。まとめた use など解析できない書き方は推測せずに落とす）。`redirectTo()` に渡す戻り先の中身は `ImportControllerReturnPathScanTest` が見る（今の URL を作る `url()->current()`・`URL::full()`・`app('url')->current()`・`Request::url()`・`$request->path()`・`getPathInfo()` などの検出を 2026-09-25 に足した）。挙動のテスト（`from(確認画面の URL)` で送る）も引き続き 4 本を守る: `TenantImportRejectionTest` / `MansionImportRejectionTest` / `ZealMemberImportControllerTest` / `ScheduleImportTest`。⚠ どちらの走査にも見えない: トレイト・親クラス・サービスへ切り出した入力チェックや `back()`・`*ImportController.php` という名前でない取込・try の中で作って外で呼ぶクロージャ。⚠ 内側の try の catch で判定を決める（外側の catch が投げ直しを受け止める形も不合格にする厳しめの規則）。⚠ 試作にテスト側の変異を当てたら、catch の本体が `throw` で始まるかの判定だけを外す変異が緑のまま通った（見本が `return $e->redirectTo(…)` を持っていなかった）→ 分かれ目ごとに見本を置き、変異は本番のコード 18 通り＋カナリア・テスト 46 通りがすべて期待どおり（計画書 `docs/superpowers/plans/2026-09-25-import-validation-scan.md` の実測記録）。
```

- [ ] **Step 2: `CLAUDE.md`**（Laravel-specific quirks の User の行）

置き換える前: `取込は \`ImportControllerReturnPathScanTest\` が止める。Bug #64）`

置き換えた後: `取込は \`ImportControllerReturnPathScanTest\`（戻り先の書き方）と \`ImportControllerValidationRedirectScanTest\`（入力チェックの包み方）が止める。Bug #64）`

- [ ] **Step 3: `docs/BACKLOG.md`**（4 か所）

(1) 「CSV 取込の確認画面から断られると 405 になる件」の節の要点の行（置き換える前 → 後）:

```text
  ⚠ try で包まない入力チェックは字面に `back(` が現れないので**走査に見えない** → 挙動のテスト（`from(確認画面の URL)` で送る）が守る
```

```text
  ⚠ try で包まない入力チェックは字面に `back(` が現れないので**走査に見えない** → 挙動のテスト（`from(確認画面の URL)` で送る）が守る（→ 2026-09-25 から `ImportControllerValidationRedirectScanTest` が包み方を全件分類で見る。下の「取込の入力チェックの包み忘れを走査テストで止める」の節）
```

(2) 同じ節の「範囲外」の 1 項目:

```text
- 入力チェック（`validate()`・`Validator::make()`・`ValidationException::withMessages()`）を try で包んで `redirectTo()` を渡しているかを、走査で機械的に見る形（新しい取込の入力チェックには守り手がいない。レビューの提案）
```

```text
- 入力チェック（`validate()`・`Validator::make()`・`ValidationException::withMessages()`）を try で包んで `redirectTo()` を渡しているかを、走査で機械的に見る形（新しい取込の入力チェックには守り手がいない。レビューの提案） → **2026-09-25 に対応**（下の「取込の入力チェックの包み忘れを走査テストで止める」の節）
```

(3) 「## バックログ完了状況」の直前に節を足す（全件・変異・レビューの数は実測に合わせる）。Edit の置き換える前は `\n---\n\n## バックログ完了状況\n`（ファイルに 1 か所）、置き換えた後は `\n---\n\n` ＋ 下の節 ＋ `\n---\n\n## バックログ完了状況\n`:

````markdown
## ✅ 取込の入力チェックの包み忘れを走査テストで止める — テストと文書だけ（本番への反映は不要）

詳細仕様: @docs/superpowers/specs/2026-09-25-import-validation-scan-design.md
実装計画（試作・変異テスト・レビューの記録つき）: @docs/superpowers/plans/2026-09-25-import-validation-scan.md

上の「CSV 取込の確認画面から断られると 405 になる件（基幹の取込）」の範囲外として残した件（2026-09-24 のレビューの M4 の提案）。
入力チェックの既定の戻り先はリファラーで、コードに `back(` が現れないので `ImportControllerReturnPathScanTest` に見えず、
新しい取込には守り手がいなかった（今の 4 本は挙動のテストが守る）。
利用者の判断（2026-09-25）: **取込のコントローラだけをテストで守る**（本番のコードは 1 行も変えない。アプリ全体の安全網は別の作業）・
既存の走査の穴（今の URL へ戻す形）も塞ぐ・確かめ方は**ソースを読む**（実際に送って確かめる案は採らない）。
**`app/`・`resources/`・`routes/`・DB・依存の変更は無し。**

| 区分 | 実装内容 |
|------|---------|
| テスト（新規）| `tests/Feature/ImportControllerValidationRedirectScanTest.php`（78 本）／ `tests/Concerns/ScansImportControllers.php`（`*ImportController.php` の列挙と下限 8 本。2 本の走査で共用）|
| テスト（変更）| `tests/Feature/ImportControllerReturnPathScanTest.php`（列挙をトレイトへ・今の URL を作る書き方の検出）|
| 全件テスト | 2169 → **2247 tests / 14735 assertions green** |

### 要点

- 入力チェックの例外を投げる呼び出し（`->validate(`・`?->validate(`・`->validateWithBag(`・`->validated(`・`::validate(`・`::withMessages(`・`new ValidationException`・`ValidationException::class`）を `token_get_all()` のトークンで探し、1 つずつ判定する: ①囲む try の catch のうち ValidationException を受け止める最初のもの（内側の try から）が ValidationException を名指しし（`\Exception`・`\Throwable` が先なら不合格）、本体が `throw $e->redirectTo(…);` の 1 文だけ ②投げる文そのものに `->redirectTo(` が続く
- 実物: 10 か所（包んである 7・包んでいない 3＝顧客 `:28`・周辺ビル `:85`・経営試算表の URL 保存 `updateUrls` `:58`。3 か所は例外リストに件数と理由つきで載せた）
- FormRequest（既定の戻り先がリファラー）を受ける public メソッドは Reflection で探して落とす（今は 0 件）
- まとめた use（`use A\{B, C};` と `use A\B, C\D;`）・名前の続かない冒頭の use・namespace が 2 つ・`namespace X { … }`・波括弧の対応が取れない書き方は、推測せずに「解析できない」として落とす
- 既存の走査に、今の URL を作る書き方（`url()->current()`・`url()->full()`・`URL::current()`・`URL::full()`・`app('url')->current()`・ファサードの `Request::url()` など・`$request->getUri()`・`getRequestUri()`・`path()`・`decodedPath()`・`getPathInfo()`）の検出を足した（実物は 0 件）。`throw $e->redirectTo(url()->current())` は、包み方だけを見る新しい走査ではなく、こちらが拾う（分担）
- ⚠ 内側の try の catch で判定を決める（外側の catch が投げ直しを受け止める形も不合格にする厳しめの規則）

### 検証

- 計画のコードは先に scratchpad で試作して実物に当て、段階ごとの赤と緑を測ってから書いた。**試作にテスト側の変異を当てたら 1 通り（catch の本体が `throw` で始まるかの判定を外す）が緑のまま通った**（見本が `return $e->redirectTo(…)` を持っていなかった）→ 同じ目で分かれ目を洗い直し、見本を 12 足した
- 変異 65 通り（本番のコード 18＋カナリア 1 を全件で・テスト 46 を走査の 2 本で）がすべて期待どおり（計画書の実測記録）
- 独立レビュー 1 回（指摘と対応は計画書の実測記録）

### 範囲外（気づいたが直していない）

- アプリ全体の安全網（入力チェックで断られたとき、リファラーが POST 専用の URL なら直前の GET の画面へ戻す）／実際に送って確かめる走査／包んでいない 3 か所を包み直すこと
- BACKLOG の範囲外にあるほかの 2 件（ZEAL 会員の確定で DB の例外が出ると 500・顧客の取込は断られると確認画面の内容が消える）
````

(4) 「バックログ完了状況」の「その他の新規要件は別途追記する。」の直前に 1 段落。Edit の置き換える前は `\nその他の新規要件は別途追記する。\n`（ファイルに 1 か所）、置き換えた後は `\n` ＋ 下の段落 ＋ `\n\nその他の新規要件は別途追記する。\n`:

```text
**取込の入力チェックの包み忘れを止める走査テスト（2026-09-25）**はテストと文書だけの変更で、本番への反映は要らない（上の節。`13.x` へ早送りするだけ）。
```

- [ ] **Step 4: この計画の「実測記録」を仕上げる**（全件テストの数・変異の結果・レビューの指摘と対応）
- [ ] **Step 5: 置き換えが当たったことを確かめてコミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-validation-scan && grep -c -e 'ImportControllerValidationRedirectScanTest' docs/RULES.md CLAUDE.md docs/BACKLOG.md && git diff --stat && git add docs/RULES.md CLAUDE.md docs/BACKLOG.md docs/superpowers/plans/2026-09-25-import-validation-scan.md && git commit -m "$(cat <<'EOF'
docs: 取込の入力チェックの包み方を走査で止めることを記録する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

期待: 3 ファイルとも 1 以上。

## Task 10: `13.x` へ早送り（本番への反映は無し・push しない）

- [ ] **Step 1: 最後の全件テスト**（worktree で。期待: 緑・Task 6 の数＋ Task 8 で足した分）
- [ ] **Step 2: main repo で早送り**

```bash
git -C /Users/masanori/site/manage status --porcelain && git -C /Users/masanori/site/manage rev-parse --abbrev-ref HEAD && git -C /Users/masanori/site/manage merge-base --is-ancestor 13.x import-validation-scan && git -C /Users/masanori/site/manage merge --ff-only import-validation-scan && git -C /Users/masanori/site/manage log --oneline -3
```

期待: main repo の作業ツリーは空・今のブランチは `13.x`・早送りできる。⚠ `13.x` が進んでいて早送りできなければ、`13.x` をこのブランチへマージ → 全件テスト → 早送り（リベースしない）。
⚠ `composer dump-autoload` は要らない（新しいクラスは `tests/` の中だけで、main repo の vendor は `--no-dev`＝ autoload-dev を持たない）。`./deploy.sh` も要らない（`tests/`・`docs/` は送らない）。`origin/13.x` への push は利用者の指示があったときだけ。

## 完了の条件

- 全件テストが緑（`2247 tests / 14735 assertions` ＋ Task 8 で足した分）
- 変異がすべて期待どおり（期待と違ったものは、テストを足して赤にしたか、等価である理由を「実測記録」に書いた）
- 独立レビューの指摘に、直したか直さない理由を書いた
- 文書 4 か所と「実測記録」を更新した
- `13.x` がこのブランチまで早送りされている（push・deploy はしない）

## 範囲外（この計画ではやらない。設計書 §6）

- アプリ全体の安全網（入力チェックで断られたとき、リファラーが POST 専用の URL なら直前の GET の画面へ戻す。案 2）
- 実際に送って確かめる走査／包んでいない 3 か所を包み直す（本番のコードを変えない）
- FormRequest の例外リスト（今は 0 件）／ `*ImportController.php` という名前でない取込（今は 2 本で 405 の形ではない）
- BACKLOG の範囲外にあるほかの 2 件（ZEAL 会員の確定で DB の例外が出ると 500・顧客の取込は断られると確認画面の内容が消える）

---

## 実測記録

### 全件テスト

| 時点 | 結果 |
|---|---|
| 着手前（Task 0）| OK (2169 tests, 14567 assertions) |
| Task 6 の後（`2f226d04`）| OK (2247 tests, 14735 assertions)（見込みどおり）|
| レビューの後（`4b605a0b`）| OK (2263 tests, 14765 assertions)（+16 本＝レビューの後に足した見本）|
| 最後（Task 10。`b61f6995` ＋ 文書）| OK (2263 tests, 14765 assertions) |

### 変異テスト（1 回目・レビューの前のコード `2f226d04`）

隔離した worktree 3 つ（`git worktree add --detach`・vendor は `cp -Rc` で実体コピー）で、どれもカナリアから流した。
実行役 `mutate.py`（scratchpad）が 1 通りごとに、作業ツリーが空・置き換えがちょうど 1 か所・`git diff --stat` の着弾・`--log-junit`・戻して空、を確かめる。
**65 通り＋カナリアがすべて期待どおり。** 走査の理由の詳細の行は、変異を 1 つずつ当てて走査 2 本だけを流して取った（`detail_lines.py`）。

#### 本番のコード（全件）

| ID | 着弾（`git diff --stat`）| 落ちたテスト | 理由（走査は詳細の行・挙動のテストは 1 行目）| 期待どおりか |
|---|---|---|---|---|
| C0（a・b・c）| 1 file changed, 1 insertion(+), 1 deletion(-) | 3 つのコピーとも `ImportValidationFeedbackTest`（顧客CSV） だけ | テスト自身の文言「差し戻し先 /admin/customers/import に理由が出ていない」。原因は画面の 500（`View [admin.customers.import-canary] not found.`。使い捨てのテストで確認）| ✅ 落ちたテストと原因は期待どおり（計画書の期待は理由の書き方が不正確だった）|
| M01 | 1 file changed, 3 insertions(+), 8 deletions(-) | `ImportValidationFeedbackTest`（テナントCSV） ＋ `TenantImportRejectionTest`（ファイルを選ばずに送った） ＋ `ImportControllerValidationRedirectScanTest` | 「/admin/tenant-import/property の差し戻し先が違う」 ／ 「取込の画面（unit タブ）へ戻っていない（確認画面の URL へ戻ると GET で 405）」 ／ 走査「`Admin/TenantImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :1264 ->validate(（try の外）`」 | ✅ |
| M02 | 1 file changed, 3 insertions(+), 8 deletions(-) | `ImportValidationFeedbackTest`（賃貸マンションCSV） ＋ `MansionImportRejectionTest`（ファイルを選ばずに送った） ＋ `ImportControllerValidationRedirectScanTest` | 「/admin/mansion-import/property の差し戻し先が違う」 ／ 「取込の画面（room タブ）へ戻っていない（確認画面の URL へ戻ると GET で 405）」 ／ 走査「`Admin/MansionImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :1391 ->validate(（try の外）`」 | ✅ |
| M03 | 1 file changed, 3 insertions(+), 8 deletions(-) | `ZealMemberImportControllerTest` ＋ `ImportControllerValidationRedirectScanTest` | 「取込の画面へ戻っていない（確認画面の URL へ戻ると GET で 405）」 ／ 走査「`Admin/ZealMemberImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :233 ->validate(（try の外）`」 | ✅ |
| M04 | 1 file changed, 8 insertions(+), 13 deletions(-) | `ApprovalUserImportTest`（ファイルを選び忘れた） ＋ `ImportControllerValidationRedirectScanTest` | 「取込の画面へ戻っていない（preview へ戻ると GET で 405）」 ／ 走査「`Approval/UserImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :72 ->validate(（try の外）`」 | ✅ |
| M05 | 1 file changed, 3 insertions(+), 8 deletions(-) | `ApprovalUserImportTest` ＋ `ImportControllerValidationRedirectScanTest` | 「取込の画面へ戻っていない（preview へ戻ると GET で 405）」 ／ 走査「`Approval/UserImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :102 ->validate(（try の外）`」 | ✅ |
| M06 | 1 file changed, 3 insertions(+), 8 deletions(-) | `ScheduleImportTest`（送り直し: ファイルを選ばなかった） ＋ `ImportControllerValidationRedirectScanTest` | 「取込の画面へ戻っていない（確認画面の URL へ戻ると GET で 405）」 ／ 走査「`Housing/ScheduleImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :55 ->validate(（try の外）`」 | ✅ |
| M07 | 1 file changed, 3 insertions(+), 7 deletions(-) | `ScheduleImportTest`（確定: 取り込む工程が無い） ＋ `ImportControllerValidationRedirectScanTest` | 「取込の画面へ戻っていない（確認画面の URL へ戻ると GET で 405）」 ／ 走査「`Housing/ScheduleImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :90 ->validate(（try の外）`」 | ✅ |
| M08 | 1 file changed, 1 insertion(+), 1 deletion(-) | `ImportControllerValidationRedirectScanTest` | 走査「`Admin/TenantImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :1265 ->validate(（\Exception の catch が先に受け止める）`」 | ✅ |
| M09 | 1 file changed, 1 insertion(+), 1 deletion(-) | `ImportControllerValidationRedirectScanTest` | 走査「`Admin/MansionImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :1392 ->validate(（\Throwable の catch が先に受け止める）`」 | ✅ |
| M10 | 1 file changed, 2 insertions(+) | `ZealMemberImportControllerTest` ＋ `ImportControllerValidationRedirectScanTest` | 「取込の画面へ戻っていない（確認画面の URL へ戻ると GET で 405）」 ／ 走査「`Admin/ZealMemberImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :234 ->validate(（\Exception の catch が先に受け止める）`」 | ✅ |
| M11 | 1 file changed, 1 insertion(+), 1 deletion(-) | `ApprovalUserImportTest`（ファイルを選び忘れた） ＋ `ImportControllerValidationRedirectScanTest` | 「取込の画面へ戻っていない（preview へ戻ると GET で 405）」 ／ 走査「`Approval/UserImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :73 ->validate(（catch の本体が throw $e->redirectTo(…) の 1 文でない）`」 | ✅ |
| M12 | 1 file changed, 3 insertions(+) | `ImportControllerValidationRedirectScanTest` | 走査「`Housing/ScheduleImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :91 ->validate(（catch の本体が throw $e->redirectTo(…) の 1 文でない）`」 | ✅ |
| M13 | 1 file changed, 1 deletion(-) | `ImportValidationFeedbackTest`（テナントCSV） ＋ `TenantImportRejectionTest`（ファイルを選ばずに送った） ＋ `ImportControllerValidationRedirectScanTest` | 「/admin/tenant-import/property の差し戻し先が違う」 ／ 「取込の画面（unit タブ）へ戻っていない（確認画面の URL へ戻ると GET で 405）」 ／ 走査「`Admin/TenantImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :1264 ->validate(（use の無い ValidationException を受けている）`」 | ✅ |
| M14 | 1 file changed, 1 insertion(+), 1 deletion(-) | `ImportValidationFeedbackTest`（賃貸マンションCSV） ＋ `MansionImportRejectionTest`（ファイルを選ばずに送った） ＋ `ImportControllerReturnPathScanTest` | 「/admin/mansion-import/property の差し戻し先が違う」 ／ 「取込の画面（room タブ）へ戻っていない（確認画面の URL へ戻ると GET で 405）」 ／ 走査「`Admin/MansionImportController.php: 件数が 1（分類は 0）: :1397 url()->current(`」 | ✅ |
| M15 | 1 file changed, 1 insertion(+), 1 deletion(-) | `ScheduleImportTest`（送り直し: ファイルを選ばなかった） ＋ `ImportControllerReturnPathScanTest` | 「取込の画面へ戻っていない（確認画面の URL へ戻ると GET で 405）」 ／ 走査「`Housing/ScheduleImportController.php: 件数が 1（分類は 0）: :61 $request->path(`」 | ✅ |
| M16 | 1 file changed, 11 insertions(+), 7 deletions(-) | `ImportControllerValidationRedirectScanTest` | 走査「`Admin/CustomerImportController.php: 包んでいない呼び出しが 0 件（分類は 1）: `」 | ✅ |
| M17 | ?? app/Http/Controllers/Admin/SampleImportController.php | `ImportControllerValidationRedirectScanTest` | 走査「`Admin/SampleImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :12 ->validate(（try の外）`」 | ✅ |
| M18 | 1 file changed, 4 insertions(+) | `ImportControllerValidationRedirectScanTest` | 走査「`Zeal/SheetImportController.php: sampleFormRequest($request: Illuminate\Foundation\Auth\EmailVerificationRequest)`」 | ✅ |

#### テスト（走査の 2 本）

試作に当てた 46 通りと同じ定義を、worktree の本物のコードに当てた（試作の実測と同じ集合・同じ理由かを突き合わせた）。

| ID | 着弾 | 落ちたテスト（本数）| 期待どおりか |
|---|---|---|---|
| X01 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X02 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X03 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X04 | 1 file changed, 1 insertion(+), 1 deletion(-) | 4 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X05 | 1 file changed, 1 insertion(+), 1 deletion(-) | 6 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X06 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X07 | 1 file changed, 1 insertion(+), 1 deletion(-) | 6 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X08 | 1 file changed, 1 insertion(+), 1 deletion(-) | 3 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X09 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X10 | 1 file changed, 3 deletions(-) | 4 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X11 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X12 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X13 | 1 file changed, 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X14 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X15 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X16 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X17 | 1 file changed, 1 insertion(+), 1 deletion(-) | 2 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X18 | 1 file changed, 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X19 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X20 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X21 | 1 file changed, 1 insertion(+), 1 deletion(-) | 2 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X22 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X23 | 1 file changed, 2 deletions(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X24 | 1 file changed, 1 insertion(+), 1 deletion(-) | 31 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X25 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X26 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X27 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X28 | 1 file changed, 2 insertions(+), 2 deletions(-) | 3 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X29 | 1 file changed, 1 insertion(+), 1 deletion(-) | 2 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X30 | 1 file changed, 3 deletions(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X31 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X32 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X33 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X34 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X35 | 1 file changed, 1 insertion(+), 1 deletion(-) | 3 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X36 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X37 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X38 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X39 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X40 | 1 file changed, 1 insertion(+) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X41 | 1 file changed, 1 insertion(+), 1 deletion(-) | 5 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X42 | 1 file changed, 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X43 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X44 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X45 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |
| X46 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本（試作の実測と同じ集合・同じ理由）| ✅ |

### 変異テスト（2 回目・レビューの後の最後のコード `4b605a0b`）

レビューで直した後の最後のコードに当て直した（`b61f6995` はコメントだけの差）。実行役は `mutate2.py`（`mutate.py` の手順をそのまま使い、定義だけを差し替える）。
隔離した worktree 4 つ（本番のコード 3・走査 1。どれもカナリアから）。**本番のコード 18 通り＋カナリア（3 つのコピー）・テスト 73 通り＋カナリアがすべて期待どおり。**
走査の理由の詳細の行は、1 回目と比べて M13 の理由の文言（レビューで直したもの）だけが変わり、ほかは同じだった。

#### 本番のコード（全件。隔離した worktree 3 つ・それぞれカナリアから）

| ID | 着弾（`git diff --stat`）| 落ちたテスト | 1 回目（`2f226d04`）と比べて | 期待どおりか |
|---|---|---|---|---|
| C0（コピー a） | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本: `ImportValidationFeedbackTest`  | 同じ集合 | ✅ |
| C0（コピー b） | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本: `ImportValidationFeedbackTest`  | 同じ集合 | ✅ |
| C0（コピー c） | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本: `ImportValidationFeedbackTest`  | 同じ集合 | ✅ |
| M01 | 1 file changed, 3 insertions(+), 8 deletions(-) | 3 本: `ImportControllerValidationRedirectScanTest` ＋ `ImportValidationFeedbackTest` ＋ `TenantImportRejectionTest` ／ 走査「`app/Http/Controllers/Admin/TenantImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :1264 ->validate(（try の外）`」 | 同じ集合 | ✅ |
| M02 | 1 file changed, 3 insertions(+), 8 deletions(-) | 3 本: `ImportControllerValidationRedirectScanTest` ＋ `ImportValidationFeedbackTest` ＋ `MansionImportRejectionTest` ／ 走査「`app/Http/Controllers/Admin/MansionImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :1391 ->validate(（try の外）`」 | 同じ集合 | ✅ |
| M03 | 1 file changed, 3 insertions(+), 8 deletions(-) | 2 本: `ImportControllerValidationRedirectScanTest` ＋ `ZealMemberImportControllerTest` ／ 走査「`app/Http/Controllers/Admin/ZealMemberImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :233 ->validate(（try の外）`」 | 同じ集合 | ✅ |
| M04 | 1 file changed, 8 insertions(+), 13 deletions(-) | 2 本: `ApprovalUserImportTest` ＋ `ImportControllerValidationRedirectScanTest` ／ 走査「`app/Http/Controllers/Approval/UserImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :72 ->validate(（try の外）`」 | 同じ集合 | ✅ |
| M05 | 1 file changed, 3 insertions(+), 8 deletions(-) | 2 本: `ApprovalUserImportTest` ＋ `ImportControllerValidationRedirectScanTest` ／ 走査「`app/Http/Controllers/Approval/UserImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :102 ->validate(（try の外）`」 | 同じ集合 | ✅ |
| M06 | 1 file changed, 3 insertions(+), 8 deletions(-) | 2 本: `ImportControllerValidationRedirectScanTest` ＋ `ScheduleImportTest` ／ 走査「`app/Http/Controllers/Housing/ScheduleImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :55 ->validate(（try の外）`」 | 同じ集合 | ✅ |
| M07 | 1 file changed, 3 insertions(+), 7 deletions(-) | 2 本: `ImportControllerValidationRedirectScanTest` ＋ `ScheduleImportTest` ／ 走査「`app/Http/Controllers/Housing/ScheduleImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :90 ->validate(（try の外）`」 | 同じ集合 | ✅ |
| M08 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本: `ImportControllerValidationRedirectScanTest` ／ 走査「`app/Http/Controllers/Admin/TenantImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :1265 ->validate(（\Exception の catch が先に受け止める）`」 | 同じ集合 | ✅ |
| M09 | 1 file changed, 1 insertion(+), 1 deletion(-) | 1 本: `ImportControllerValidationRedirectScanTest` ／ 走査「`app/Http/Controllers/Admin/MansionImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :1392 ->validate(（\Throwable の catch が先に受け止める）`」 | 同じ集合 | ✅ |
| M10 | 1 file changed, 2 insertions(+) | 2 本: `ImportControllerValidationRedirectScanTest` ＋ `ZealMemberImportControllerTest` ／ 走査「`app/Http/Controllers/Admin/ZealMemberImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :234 ->validate(（\Exception の catch が先に受け止める）`」 | 同じ集合 | ✅ |
| M11 | 1 file changed, 1 insertion(+), 1 deletion(-) | 2 本: `ApprovalUserImportTest` ＋ `ImportControllerValidationRedirectScanTest` ／ 走査「`app/Http/Controllers/Approval/UserImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :73 ->validate(（catch の本体が throw $e->redirectTo(…) の 1 文でない）`」 | 同じ集合 | ✅ |
| M12 | 1 file changed, 3 insertions(+) | 1 本: `ImportControllerValidationRedirectScanTest` ／ 走査「`app/Http/Controllers/Housing/ScheduleImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :91 ->validate(（catch の本体が throw $e->redirectTo(…) の 1 文でない）`」 | 同じ集合 | ✅ |
| M13 | 1 file changed, 1 deletion(-) | 3 本: `ImportControllerValidationRedirectScanTest` ＋ `ImportValidationFeedbackTest` ＋ `TenantImportRejectionTest` ／ 走査「`app/Http/Controllers/Admin/TenantImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :1264 ->validate(（Illuminate\Validation\ValidationException でない ValidationException を受けている）`」 | 同じ集合（走査の理由の文言だけが新しい文言に変わった） | ✅ |
| M14 | 1 file changed, 1 insertion(+), 1 deletion(-) | 3 本: `ImportControllerReturnPathScanTest` ＋ `ImportValidationFeedbackTest` ＋ `MansionImportRejectionTest` ／ 走査「`app/Http/Controllers/Admin/MansionImportController.php: 件数が 1（分類は 0）: :1397 url()->current(`」 | 同じ集合 | ✅ |
| M15 | 1 file changed, 1 insertion(+), 1 deletion(-) | 2 本: `ImportControllerReturnPathScanTest` ＋ `ScheduleImportTest` ／ 走査「`app/Http/Controllers/Housing/ScheduleImportController.php: 件数が 1（分類は 0）: :61 $request->path(`」 | 同じ集合 | ✅ |
| M16 | 1 file changed, 11 insertions(+), 7 deletions(-) | 1 本: `ImportControllerValidationRedirectScanTest` ／ 走査「`app/Http/Controllers/Admin/CustomerImportController.php: 包んでいない呼び出しが 0 件（分類は 1）: `」 | 同じ集合 | ✅ |
| M17 | ?? app/Http/Controllers/Admin/SampleImportController.php | 1 本: `ImportControllerValidationRedirectScanTest` ／ 走査「`app/Http/Controllers/Admin/SampleImportController.php: 包んでいない呼び出しが 1 件（分類は 0）: :12 ->validate(（try の外）`」 | 同じ集合 | ✅ |
| M18 | 1 file changed, 4 insertions(+) | 1 本: `ImportControllerValidationRedirectScanTest` ／ 走査「`app/Http/Controllers/Zeal/SheetImportController.php: sampleFormRequest($request: Illuminate\Foundation\Auth\EmailVerificationRequest)`」 | 同じ集合 | ✅ |

#### テスト（走査の 2 本。隔離した worktree 1 つ・カナリア C1 から）

レビューで足した・変えた分岐（R）とカナリア:

| ID | 変異 | 落ちたテスト | 期待どおりか |
|---|---|---|---|
| C1 | カナリア: 投げる呼び出しの総数の下限を 99 に | 1 本: `ImportControllerValidationRedirectScanTest::test_the_scan_finds_the_calls_that_throw_validation_exceptions` | ✅ |
| R01 | `->` の一覧から `safe` を外す | 1 本: `ImportControllerValidationRedirectScanTest::test_the_detector_finds_each_form_of_call_that_throws`「->safe(（中で validated() を呼ぶ）」 | ✅ |
| R02 | `->` の一覧から `validatewith` を外す | 1 本: `ImportControllerValidationRedirectScanTest::test_the_detector_finds_each_form_of_call_that_throws`「->validateWith(」 | ✅ |
| R03 | `::` の一覧から `validatewithbag` を外す | 1 本: `ImportControllerValidationRedirectScanTest::test_the_detector_finds_each_form_of_call_that_throws`「::validateWithBag(（ファサード）」 | ✅ |
| R04 | `::` の一覧から `validate` を外す | 2 本: `ImportControllerValidationRedirectScanTest::test_the_detector_finds_each_form_of_call_that_throws`「::validate(」 ／ `ImportControllerValidationRedirectScanTest::test_the_judge_rejects_the_unwrapped_forms`「B: 例外を作らない ::validate( に続く ->redirectTo(（B で見るのは例外を作る呼び出しだけ）」 | ✅ |
| R05 | 理由の文言を、解決した名前でなく書いた名前で判定する（直す前の形） | 1 本: `ImportControllerValidationRedirectScanTest::test_the_judge_rejects_the_unwrapped_forms`「別の ValidationException を別名で use した catch（書いた名前でなく解決した名前で見る）」 | ✅ |
| R06 | B を丸ごと外す | 6 本: `ImportControllerValidationRedirectScanTest::test_the_judge_accepts_the_wrapped_forms`「withMessages() に続く ->redirectTo(」 ／ `ImportControllerValidationRedirectScanTest::test_the_judge_accepts_the_wrapped_forms`「(new ValidationException()) に続く ->redirectTo(」 ／ `ImportControllerValidationRedirectScanTest::test_the_judge_accepts_the_wrapped_forms`「B: 連鎖の途中の呼び出しをはさむ（->errorBag(…)->redirectTo(…)）」 ／ `ImportControllerValidationRedirectScanTest::test_the_judge_accepts_the_wrapped_forms`「B: 引数の無い new ValidationException」 ／ `ImportControllerValidationRedirectScanTest::test_the_judge_judges_each_call_on_its_own`「B: withMessages() の引数の中の入力チェックは包まれない」 ／ `ImportControllerValidationRedirectScanTest::test_the_judge_judges_each_call_on_its_own`「B: new ValidationException() の引数の中の入力チェックは包まれない」 | ✅ |
| R07 | B で throw を括った括弧をたどらない | 3 本: `ImportControllerValidationRedirectScanTest::test_the_judge_accepts_the_wrapped_forms`「(new ValidationException()) に続く ->redirectTo(」 ／ `ImportControllerValidationRedirectScanTest::test_the_judge_accepts_the_wrapped_forms`「B: 引数の無い new ValidationException」 ／ `ImportControllerValidationRedirectScanTest::test_the_judge_judges_each_call_on_its_own`「B: new ValidationException() の引数の中の入力チェックは包まれない」 | ✅ |
| R08 | B で「throw の直後」を確かめない | 1 本: `ImportControllerValidationRedirectScanTest::test_the_judge_rejects_the_unwrapped_forms`「B: throw の無い withMessages()->redirectTo()」 | ✅ |
| R09 | B の連鎖で括りの `)` を閉じない | 3 本: `ImportControllerValidationRedirectScanTest::test_the_judge_accepts_the_wrapped_forms`「(new ValidationException()) に続く ->redirectTo(」 ／ `ImportControllerValidationRedirectScanTest::test_the_judge_accepts_the_wrapped_forms`「B: 引数の無い new ValidationException」 ／ `ImportControllerValidationRedirectScanTest::test_the_judge_judges_each_call_on_its_own`「B: new ValidationException() の引数の中の入力チェックは包まれない」 | ✅ |
| R10 | B の連鎖で、括りの無い `)` も閉じたことにする | 1 本: `ImportControllerValidationRedirectScanTest::test_the_judge_rejects_the_unwrapped_forms`「B: throw を括った括弧の外の ->redirectTo(」 | ✅ |
| R11 | B の連鎖の外に出ても探し続ける | 4 本: `ImportControllerValidationRedirectScanTest::test_the_judge_rejects_the_unwrapped_forms`「redirectTo の無い withMessages()（次の文の ->redirectTo( は続いたことにしない）」 ／ `ImportControllerValidationRedirectScanTest::test_the_judge_rejects_the_unwrapped_forms`「B: match の腕の throw（, の後ろの ->redirectTo( は続いたことにしない）」 ／ `ImportControllerValidationRedirectScanTest::test_the_judge_rejects_the_unwrapped_forms`「B: ?? の後ろの throw（隣の引数の ->redirectTo( は続いたことにしない）」 ／ `ImportControllerValidationRedirectScanTest::test_the_judge_rejects_the_unwrapped_forms`「B: throw を括った括弧の外の ->redirectTo(」 | ✅ |
| R12 | B で `?->redirectTo(` も数える | 1 本: `ImportControllerValidationRedirectScanTest::test_the_judge_rejects_the_unwrapped_forms`「B: ?->redirectTo(」 | ✅ |
| R13 | B の連鎖の途中の呼び出しを飛ばさない | 1 本: `ImportControllerValidationRedirectScanTest::test_the_judge_accepts_the_wrapped_forms`「B: 連鎖の途中の呼び出しをはさむ（->errorBag(…)->redirectTo(…)）」 | ✅ |
| R14 | 括弧の対応（`afterParens`）で入れ子を数えない | 3 本: `ImportControllerValidationRedirectScanTest::test_the_judge_accepts_the_wrapped_forms`「B: 連鎖の途中の呼び出しをはさむ（->errorBag(…)->redirectTo(…)）」 ／ `ImportControllerValidationRedirectScanTest::test_the_judge_judges_each_call_on_its_own`「B: withMessages() の引数の中の入力チェックは包まれない」 ／ `ImportControllerValidationRedirectScanTest::test_the_judge_judges_each_call_on_its_own`「B: new ValidationException() の引数の中の入力チェックは包まれない」 | ✅ |
| R15 | 引数の無い `new ValidationException` の後ろを、括弧があるものとして読む | 1 本: `ImportControllerValidationRedirectScanTest::test_the_judge_accepts_the_wrapped_forms`「B: 引数の無い new ValidationException」 | ✅ |
| R16 | B を例外を作らない呼び出し（`::validate(`・`::validateWithBag(`）にも当てる | 1 本: `ImportControllerValidationRedirectScanTest::test_the_judge_rejects_the_unwrapped_forms`「B: 例外を作らない ::validate( に続く ->redirectTo(（B で見るのは例外を作る呼び出しだけ）」 | ✅ |
| R18 | 括弧の開きから属性の `#[` を外す | 1 本: `ImportControllerValidationRedirectScanTest::test_the_judge_accepts_the_wrapped_forms`「redirectTo の引数の中の属性つきクロージャ（#[…] の ] で深さをずらさない）」 | ✅ |
| R19 | 括弧の開きから `[` を外す | 2 本: `ImportControllerValidationRedirectScanTest::test_import_controllers_send_every_validation_failure_to_a_fixed_page` ／ `ImportControllerValidationRedirectScanTest::test_the_judge_accepts_the_wrapped_forms`「今の書き方（catch の中の // コメント・引数の中の [ ]）」 | ✅ |
| R21 | FormRequest の型から交差型を外す | 1 本: `ImportControllerValidationRedirectScanTest::test_the_form_request_detector_sees_every_shape_of_type` | ✅ |
| R22 | FormRequest の DNF 型の中の交差型をたどらない | 1 本: `ImportControllerValidationRedirectScanTest::test_the_form_request_detector_sees_every_shape_of_type` | ✅ |
| R23 | 既存の走査: `URL::` の後読みを外す | 1 本: 既存の走査の自己テスト「拾うべきでない: $u = ShortURL::current();」 | ✅ |
| R24 | 既存の走査: `previousPath(` を外す | 1 本: 既存の走査の自己テスト「拾えていない: throw $e->redirectTo(url()->previousPath());」 | ✅ |
| R25 | 既存の走査: `app('url')->` を外す | 1 本: 既存の走査の自己テスト「拾えていない: return redirect(app('url')->current());」 | ✅ |
| R26 | 既存の走査: `full(` を外す（`current(` だけ） | 1 本: 既存の走査の自己テスト「拾えていない: return redirect(url()->full());」 | ✅ |
| R27 | 既存の走査: `getUri(`・`getRequestUri(` を外す | 1 本: 既存の走査の自己テスト「拾えていない: return redirect($request->getUri());」 | ✅ |
| R28 | 既存の走査: `url()` の後読みから `>` を外す | 1 本: 既存の走査の自己テスト「拾うべきでない: $u = $menu->url()->current();」 | ✅ |
| R29 | 既存の走査: `url()` の後読みから `:` を外す | 1 本: 既存の走査の自己テスト「拾うべきでない: $u = Menu::url()->current();」 | ✅ |
| R30 | 既存の走査: `url()` の後読みから `$` を外す | 1 本: 既存の走査の自己テスト「拾うべきでない: $u = $url()->current();」 | ✅ |
| R31 | 既存の走査: `url()` の後読みから `\w` を外す | 1 本: 既存の走査の自己テスト「拾うべきでない: $u = shorturl()->current();」 | ✅ |
| R32 | 既存の走査: `$request` と `->` の間の空白を許さない（レビューの探り R-M4） | 1 本: 既存の走査の自己テスト「拾えていない: return redirect($request -> fullUrlWithQuery(['a' => 1]));」 | ✅ |

1 回目の X（43 通り。X21〜X23 は投げる式の判定を書き直したので捨てた。X01〜X04・X19・X20・X42・X43・X45 は今のコードに合わせて置き換えた）はすべて赤。落ちた本数が 1 回目と変わったのは、レビューの後に足した見本の分だけ: X04 4 → 11 本・X05 6 → 8 本・X07 6 → 15 本・X15 1 → 2 本・X20 1 → 3 本・X24 31 → 35 本。ほかは 1 回目と同じ本数。

⚠ **R16 は見本を足す前のコミット（`2485e6b3`）では緑のまま通った**（落ちたテスト 0。同じ定義を隔離した worktree で当てた）。
B を例外を作る呼び出しだけに限る分かれ目（`$constructs`）に見本が無かったので、`4b605a0b` で見本「B: 例外を作らない `::validate(` に続く `->redirectTo(`」を足して赤にした。
レビューの探りにあった R-M4（`$request` と `->` の間の空白）も R32 として当て、以前からある見本 `return redirect($request -> fullUrlWithQuery(['a' => 1]));` で赤になることを確かめた。

### 独立レビュー（1 回・`2b7b5bad..2f226d04`）

Important 1・Minor 8。どれも実測で再現してから、直すか docblock に書いた。
「実測」の列は、直す前（`2f226d04`）と最後（`4b605a0b`）の両方に同じ探りを当てた結果（`analyze()` の `[形, 理由]`。既存の走査は `returnsToReferer()` の件数と形。null＝合格）。

| # | 指摘 | 実測（直す前 → 最後）| 対応 |
|---|---|---|---|
| I-1 | 既存の走査が `url()->previousPath()`・`URL::previousPath()`（リファラーから作る）を拾わない。新しい走査は包み方だけを見るので、2 本とも緑のまま 405 になる | どちらも `[]`（拾わない）→ `->previousPath(`・`::previousPath(` を 1 件ずつ拾う | 直した（`d8da2211`。`previous(?:Path)?`・見本 2 つ）。変異 R24 で赤 |
| M-1 | B の判定が緩い: 投げる式の引数の中の入力チェック（a `withMessages($request->validate(…))`・b `new ValidationException(Validator::make(…)->validate())`）や、`match` の腕（c）・`??` の隣の引数（d）の `->redirectTo(` まで包んであるとみなす | a・b の内側の `->validate(` と c・d の `::withMessages(` が null → どれも「（try の外）」（a・b の外側の投げる式は null のまま）| 直した（`b3529b06`。B を「例外を作る呼び出しが throw の直後にあり、その後ろのメソッドの連鎖に `->redirectTo(`」に限る）。見本 9 つ（包んである 2・包んでいない 5・1 つずつ判定する 2）。変異 R06〜R15 で赤 |
| M-2 | `redirectTo()` に null（null になりうる式）を渡す形が、どちらの走査にも見えない（null なら例外ハンドラの `redirectTo ?? url()->previous()` でリファラーへ戻る）| `redirectTo(session('x'))` は null → null（変わらず）| docblock の「見えないもの」に書いた（`2485e6b3`）。リテラルの `null` だけを落としても、null になりうる式は見えないままなので、判定は足さなかった |
| M-3 | 内側で `redirectTo()` した例外を外側の総称の catch が受け止める形を合格にする（405 にはならないが、入力チェックのエラーが一般のエラーに化ける）| null → null（変わらず）| docblock に書いた（`2485e6b3`）。外側の catch が `back()` を返すなら既存の走査が拾う |
| M-4 | 検出の漏れ: `->safe(`（中で `validated()` を呼ぶ）・`Request::validateWithBag(`・`$this->validateWith(`・コンテナから作る FormRequest | 3 つとも `[]` → `->safe(`・`::validateWithBag(`・`->validateWith(` を拾う | 3 つを検出に足した（`25fadf7e`・見本 3 つ）。変異 R01〜R03 で赤。コンテナから作る FormRequest は docblock に書いた（続けて `->validated(` を呼べばそちらを拾う＝実測）|
| M-5 | 例外リストが件数だけで、どの呼び出しを許したかを固定していない（`updateUrls` を包み、`apply()` に包まない入力チェックを足しても緑）| 件数で見るまま | docblock に書いた（`2485e6b3`・`b61f6995`）。呼び出しを囲むメソッドの名前で固定する形（提案）は、メソッドを探す解析とその見本が要るので見送った |
| M-6 | 既存の走査の見落とし（`$request` の決め打ち・大文字小文字・別名やコンテナ・`REQUEST_URI`・今のルート名）と拾いすぎ（記録やビューに渡す `path()`・`current()`・`Foo\Request::url()`）| 実物 8 本には 0 件 | docblock に書いた（`251dae2c`）|
| M-7 | 見本の無い分かれ目: B の門番（throw の前をたどる判定の呼び出しと、その `;`・`}`・`{` の止まり）・`opens()` の `[`・交差型・理由の文言の大文字小文字・既存の走査の後読みの `$` | レビューの変異でそれぞれ緑 | B は書き直したので、新しい分かれ目ごとに見本を置いた（R06〜R16 で赤）。`[`・`#[`・交差型・DNF 型の見本を足した（`7ee185aa`。R18・R19・R21・R22 で赤）。理由は解決した（小文字の）名前で判定するので、大文字小文字の分かれ目は無くなった。後読みは `Request::`・`URL::` の前の `$` を外し（変数のクラス名の呼び出しは拾いすぎても害が小さい）、`url()` の前の `$` は見本で押さえた（`251dae2c`。R23・R28〜R31・X45・X46 で赤）|
| M-8 | 誤報: 別の ValidationException を use した catch に「use の無い」と出る・`throw ($e->redirectTo(…));` が「1 文でない」・属性 `#[` で深さがずれて「1 文でない」・計画書の #1 #5 の記述・空白の抜け | 「（use の無い…）」→「（Illuminate\Validation\ValidationException でない…）」／「1 文でない」→ 変わらず ／「1 文でない」→ null | 理由の文言・`#[`・空白を直した（`7ee185aa`。R05・R18 で赤）。括弧で括った投げ直しは「拾いすぎるもの」に書いた（`2485e6b3`）。計画書の #1 #5 を直した（上の「設計書との違い」）|

レビューの後に自分で見つけたもの: 変異を組み直す途中で、B を例外を作る呼び出しだけに限る分かれ目（`$constructs`）に見本が無いことに気づいた（上の R16。`4b605a0b`）。

### 付録: 2 回目の実行役 `mutate2.py`

Task 7 の `mutate.py` と同じ scratchpad に置き、`python3 mutate2.py --iso <隔離した worktree> [ID ...]` で流す（`--list` で定義の一覧）。

```python
#!/usr/bin/env python3
"""取込の入力チェックの走査（2026-09-25）の変異テスト — レビュー後の最終コード用の定義。

手順（作業ツリーが空・置き換えがちょうど 1 か所・着弾の確認・--log-junit・戻して空の確認）は mutate.py の main をそのまま使う。
ここでは定義だけを差し替える:
  - C0・M01〜M18 は mutate.py のまま（本番のコードは変えていない）
  - X01〜X46 のうち、レビューで書き換えたコードを指すものは今のコードに合わせて置き換える
    （X21〜X23 は投げる式の判定を書き直したので捨て、R06〜R16 に置き換える）
  - R01〜R31 はレビュー後に足した・変えた分岐。C1 は走査 2 本だけを流す隔離 worktree のカナリア
"""
import importlib.util, os, sys

HERE = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location("mutate", os.path.join(HERE, "mutate.py"))
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
V, R = base.V, base.R

ARROW_LIST = "['validate', 'validatewithbag', 'validated', 'safe', 'validatewith']"
COLON_LIST = "['validate', 'withmessages', 'validatewithbag']"
OPENS = "return $this->is($token, '(') || $this->is($token, '[') || $token[0] === T_ATTRIBUTE || $this->opensBrace($token);"
HINT = r"$hint = '（Illuminate\\Validation\\ValidationException でない ValidationException を受けている）';"
URL_LOOKBEHIND = r"(?<![\w$>:])url\s*\("

OVERRIDES = {
    "X01": ("scan", V, ARROW_LIST, "['validate', 'validated', 'safe', 'validatewith']"),
    "X02": ("scan", V, ARROW_LIST, "['validate', 'validatewithbag', 'safe', 'validatewith']"),
    "X03": ("scan", V,
            "if (in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)\n                && in_array($calledName",
            "if (in_array($token[0], [T_OBJECT_OPERATOR], true)\n                && in_array($calledName"),
    "X04": ("scan", V, COLON_LIST, "['validate', 'validatewithbag']"),
    "X19": ("scan", V,
            "fn (array $try) => $try['open'] < $index && $index < $try['close']",
            "fn (array $try) => $try['open'] < $index && $index < ($try['catches'] === [] ? $try['close'] : $try['catches'][array_key_last($try['catches'])]['close'])"),
    "X20": ("scan", V, HINT, "$hint = null;"),
    "X42": ("scan", R,
            "            '/(?:(?<![\\w$>:])url\\s*\\(\\s*\\)\\s*->|(?<!\\w)URL\\s*::|app\\s*\\(\\s*[\\'\"]url[\\'\"]\\s*\\)\\s*->)\\s*(?:current|full)\\s*\\(/',\n",
            ""),
    "X43": ("scan", R, r"|(?<!\w)Request\s*::)", ")"),
    "X45": ("scan", R, r"|(?<!\w)Request\s*::)", r"|Request\s*::)"),
}
DROPPED = {"X21", "X22", "X23"}

ADDED = [
    # ---- 隔離 worktree（走査だけを流す側）のカナリア ----
    ("C1", "scan", V, "private const MIN_THROWING_CALLS = 10;", "private const MIN_THROWING_CALLS = 99;"),
    # ---- 検出する形（レビューの M-4 で足した分と ::validate( ）----
    ("R01", "scan", V, ARROW_LIST, "['validate', 'validatewithbag', 'validated', 'validatewith']"),
    ("R02", "scan", V, ARROW_LIST, "['validate', 'validatewithbag', 'validated', 'safe']"),
    ("R03", "scan", V, COLON_LIST, "['validate', 'withmessages']"),
    ("R04", "scan", V, COLON_LIST, "['withmessages', 'validatewithbag']"),
    # ---- 理由の文言（書いた名前でなく解決した名前で見る）----
    ("R05", "scan", V,
        r"str_ends_with('\\' . $resolved, '\\validationexception')",
        r"strcasecmp(substr((string) strrchr('\\' . $type, '\\'), 1), 'ValidationException') === 0"),
    # ---- B（投げる式に ->redirectTo( が続く。レビューの M-1 で書き直した）----
    ("R06", "scan", V, "if ($call['head'] !== null && $this->thrownWithRedirectTo($tokens, $call['head'], $call['after'])) {", "if (false) {"),
    ("R07", "scan", V, "while ($this->is($tokens[$j] ?? $none, '(')) {", "while (false) {"),
    ("R08", "scan", V, "        if (($tokens[$j][0] ?? null) !== T_THROW) {\n            return false;\n        }\n", ""),
    ("R09", "scan", V, "if ($this->is($token, ')') && $groups > 0) {", "if (false) {"),
    ("R10", "scan", V, "if ($this->is($token, ')') && $groups > 0) {", "if ($this->is($token, ')')) {"),
    ("R11", "scan", V, "                return false;   // 連鎖の外に出た", "                $k++;\n\n                continue;   // 連鎖の外に出た"),
    ("R12", "scan", V, "if ($token[0] === T_OBJECT_OPERATOR && strcasecmp($method[1], 'redirectTo') === 0) {", "if (strcasecmp($method[1], 'redirectTo') === 0) {"),
    ("R13", "scan", V, "$k = $this->afterParens($tokens, $k + 2);   // 連鎖の途中の呼び出し（->errorBag(…) など）を飛ばす", "return false;"),
    ("R14", "scan", V, "} elseif ($this->is($tokens[$k], ')') && --$depth === 0) {", "} elseif ($this->is($tokens[$k], ')')) {"),
    ("R15", "scan", V, "'after' => $this->is($after, '(') ? $this->afterParens($tokens, $i + 2) : $i + 2,", "'after' => $this->afterParens($tokens, $i + 2),"),
    ("R16", "scan", V,
        "'head'  => $constructs ? $i - 1 : null,\n                    'after' => $constructs ? $this->afterParens($tokens, $i + 2) : null,",
        "'head'  => $i - 1,\n                    'after' => $this->afterParens($tokens, $i + 2),"),
    # ---- 括弧の深さ（レビューの M-7・M-8）----
    ("R18", "scan", V, OPENS, "return $this->is($token, '(') || $this->is($token, '[') || $this->opensBrace($token);"),
    ("R19", "scan", V, OPENS, "return $this->is($token, '(') || $token[0] === T_ATTRIBUTE || $this->opensBrace($token);"),
    # ---- FormRequest の型（交差型・DNF 型）----
    ("R21", "scan", V,
        r"if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {",
        r"if ($type instanceof \ReflectionUnionType) {"),
    ("R22", "scan", V,
        r"return array_merge(...array_map(fn (\ReflectionType $inner) => $this->classNamesIn($inner), $type->getTypes()));",
        r"return array_merge(...array_map(fn (\ReflectionType $inner) => $inner instanceof \ReflectionNamedType ? [$inner->getName()] : [], $type->getTypes()));"),
    # ---- 既存の走査に足した分岐 ----
    ("R23", "scan", R, r"|(?<!\w)URL\s*::|", r"|URL\s*::|"),
    ("R24", "scan", R, r"previous(?:Path)?\s*\(", r"previous\s*\("),
    ("R25", "scan", R, r"|app\s*\(\s*[\'" + '"' + r"]url[\'" + '"' + r"]\s*\)\s*->)", ")"),
    ("R26", "scan", R, r"\s*(?:current|full)\s*\(/'", r"\s*(?:current)\s*\(/'"),
    ("R27", "scan", R, "|getUri|getRequestUri|path|", "|path|"),
    ("R28", "scan", R, URL_LOOKBEHIND, r"(?<![\w$:])url\s*\("),
    ("R29", "scan", R, URL_LOOKBEHIND, r"(?<![\w$>])url\s*\("),
    ("R30", "scan", R, URL_LOOKBEHIND, r"(?<![\w>:])url\s*\("),
    ("R31", "scan", R, URL_LOOKBEHIND, r"(?<![$>:])url\s*\("),
    # ---- レビューの探り（R-M4）: $request と -> の間の空白 ----
    ("R32", "scan", R, r"'/(?:\$request\s*->|", r"'/(?:\$request->|"),
]

mutations = []
for mid, scope, path, old, new in base.MUTATIONS:
    if mid in DROPPED:
        continue
    if mid in OVERRIDES:
        scope, path, old, new = OVERRIDES[mid]
    mutations.append((mid, scope, path, old, new))
base.MUTATIONS = mutations + ADDED

if __name__ == "__main__":
    base.main()
```
