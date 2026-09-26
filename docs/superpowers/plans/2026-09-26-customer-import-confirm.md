# 顧客CSVインポートの確定を通す 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 顧客CSVインポート（`/admin/customers/import`・経営層のみ）の「インポート実行」（確定）を通し、確定で初めて動き出す書き込みの問題（黙った誤保存・1 行の誤りで全行の巻き戻し）も同時に塞ぐ。

**Architecture:** 確定は、プレビューが hidden の `csv_data`（base64）で持ち回った CSV を読み直し、部署・行の検査・重複の確認を最初からやり直す（テナント・ZEAL 会員の取込と同じ形）。1 行の検査と変換は新しい `App\Support\BuyerCsvRow`（DB に触らない値のオブジェクト。`HacomonoMemberMapper` → `MappedMember` の形）に集める。プレビューの確定の欄は、取り込める行の数（V）と重複候補の数（D）で出し分け、ボタンの件数はチェックに合わせて変える。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade + Alpine.js 3 / PHPUnit（worktree の `./vendor/bin/phpunit`）/ node（画面が描いた `<script>` を vm で動かす）

設計書: `docs/superpowers/specs/2026-09-25-customer-import-confirm-design.md`（2026-09-25 承認。範囲は案 1＝候補 1〜6 すべて・運び方は案 A＝`csv_data` の hidden）

---

## Context

- 確定は最初のコミット 2046289d（2026-04-08）から一度も通っていない。確定のフォームが送るのは `csv_data`（base64）なのに、コントローラが `csv_file` を常に必須にしていて、押すと「CSVファイルは必須です。」で取込の画面へ戻る（設計書 §2.1）
- 運び方だけ直すと、一度も動いたことのない書き込みの問題が本番で動き出す（生年月日 `19800102` が 1970-08-18 になる・`昭和55年1月2日` の 1 行で全行が巻き戻る・`2026-02-30` が 3/2 に繰り上がる・`2人` で本番の MySQL が全行を巻き戻す可能性が高い。設計書 §2.2）ので、候補 1〜6 をまとめて直す
- 作業場所: worktree `/Users/masanori/site/manage/.claude/worktrees/customer-import-confirm`（ブランチ `customer-import-confirm`）。この計画を書いた時点は `192a7ae1`（設計書と要件定義書の更新の上に、`13.x` の `3659b24f` までをマージ済み）
- 利用者の決まり（この計画を実行する人にも適用）: 応答は日本語・選択肢は本文の表で出す（AskUserQuestion のウィジェットは使わない）・推薦と実測を先に書く・質問は 1 回に 1 つ・`.env` / `.env.*` は読まない・`git stash` と `--no-verify` は使わない・push は指示があったときだけ・**本番の読み取り（Task 1）と本番への反映（Task 10）は、それぞれ承認をもらってから**

## 試作で確かめたこと（2026-09-26）

scratchpad に `git archive 698d74c2` で作ったコピー（vendor は `cp -Rc` で実体コピー。Bug #50）へこの計画のコードを入れて測った（`698d74c2` と `192a7ae1` の違いは文書だけ）。

| 見たこと | 結果 |
|---|---|
| 全件テスト | **OK (2344 tests, 15190 assertions)**（この計画を書いた時点の 2263 tests / 14765 assertions ＋ Unit 61 本 ＋ Feature 20 本）|
| 新しい Unit テストを `BuyerCsvRow` が無い状態で流す | `Tests: 61, Assertions: 0, Errors: 61.`（`Error: Class "App\Support\BuyerCsvRow" not found`）|
| 新しい Feature テストを直す前のコントローラ・ビューで流す（`BuyerCsvRow` とテスト用スキーマは入れた状態）| `Tests: 20, Assertions: 183, Failures: 18.` 緑の 2 本は守りのテスト（部署を書き換えた確定は断られる・テンプレートの見出しが全部読める）で、直す前から通ってよいもの |
| コントローラだけ直してビューは直す前のまま | `Tests: 20, Assertions: 234, Failures: 4.`（画面側の 4 本: 0 件の表示・重複候補だけのときのボタン・正常と重複候補のときのボタン・件数の JS）|
| 走査テスト 2 本 | コントローラを直すと例外リストの顧客の件数が合わなくなり、2 本とも 1 件ずつ赤（`件数が 0（分類は 2）`・`包んでいない呼び出しが 0 件（分類は 1）`）→ 例外リストから顧客を外すと緑 |
| 既存の `ImportPreviewRenderTest`・`ImportValidationFeedbackTest`・`JapaneseValidationMessagesTest` | 変えずに緑（設計書 §5.3 の見込みどおり）|
| コンパイル済みビュー | **274 本 / INVALID 0 件** |
| 変異 58 通り＋カナリア 1 の先測り（関係するテスト 8 本に絞って）| **59 通りすべて赤**（緑 0）。Task 6 の表の「期待」の列はこの実測 |
| テストの穴（先測りの途中で見つけた）| 設計書 §5.1 の場面だけでテストを書くと、①チェックボックスの `x-model="includeDupes"` を消す（V06）②「重複候補だけです」の `x-show` を消す（V07）③件数の JS（`importCount()`）をチェックに合わせないように変える（V08）の 3 通りが**どのテストも落とさなかった**（実測: 足す前のテスト 19 本で 3 通りとも「落ちたテスト 0 件」）。ブラウザでは、重複候補だけのときにチェックを入れてもボタンが出なくなる。→ 構造のアサート 2 つと、画面の `<script>` を node で動かすテスト 1 本を足し、3 通りとも赤になることを確かめた |
| 書き込みの途中の PHP の `Error` | `addToDepartment()` は取得日を `string` で受けるので、取得日が読めない行が書き込みに届くと `TypeError` になり、ほかの取込と同じ `catch (\Exception $e)` を素通りして 500 になる。通常の操作では書き込みに届く行は必ず取得日が読めている（`BuyerCsvRow` が誤りにする）ので起きない。変異 M15（確定で行の検査を飛ばす）はこれを踏み、開いたままのトランザクションが後続のテストへ連鎖する（Task 6 の表に注記）|
| 使い捨ての SQLite の種データ（Task 7）| `migrate` のあとテスト用スキーマの trait で `buyers` などを作り、利用者 1 人と重複候補の相手 1 人を入れられた |
| node | v24.11.1（`command -v node` で見つかる。無ければ件数の JS のテストだけ飛ばす）|

## 設計書との違い（試作で決めたこと）

| # | 設計書 | この計画 | 理由 |
|---|---|---|---|
| 1 | §4.2 の文言「『重複候補もインポートする』にチェック…」| 「「重複候補もインポートする」にチェック…」| 文全体を括弧で囲まないので、中の引用は「」でよい（画面のほかの文言も「」）|
| 2 | §4.2 `(string) base64_decode($request->input('csv_data', ''))` | `(string) base64_decode((string) $request->input('csv_data', ''))` | 空の `csv_data` は `ConvertEmptyStringsToNull` で null になり、`base64_decode(null)` は PHP 8.1 から非推奨の警告を出す |
| 3 | §4.3 元号の範囲の文言の例 `生年月日「2000-01-01」` | 生年月日は変換後の `Y-m-d` で出す（`2000/1/1` と書いても `2000-01-01`）| 範囲は変換後の値で決めるので、同じ値を見せる |
| 4 | §4.2 の 7「重複候補を飛ばした結果 0 件なら…」| 分岐は `$dupeRows !== []` だけで決める | チェックを入れると重複候補は取り込む行に入るので、「0 件かつ重複候補あり」は必ずチェックなし。`&& $skipDupes` を足すと等価変異になる |
| 5 | §4.4「V = 0 ならボタンを包む要素を display: none で描く」| `style="{{ $validCount === 0 ? 'display: none;' : '' }}"`（V > 0 では空の style）| 属性ごと出し分けるより短い。空の style は無害 |
| 6 | §5.1 の場面 1〜12 | ＋「部署はプレビューで選んだもの」「0 件の確定をサーバが断る」「件数の JS を node で動かす」の 3 本と、構造のアサート 2 つ | 上の「テストの穴」。設計書 §4.4 の出し分けを守るテストが無かった |
| 7 | — | 部署の検査をファイルの検査より先に行う（両方が誤りなら部署の誤りだけが出る）| 設計書 §4.2 の 1・2 の順どおり。直す前は 1 回の `validate()` で両方の誤りが出ていた（振る舞いの小さな違いとして記録する）|
| 8 | — | アンケートの書き込み（分譲地の前方一致・担当者の部分一致・`if ($row->projectName)` の真偽判定）と `catch (\Exception $e)` は今のまま | 設計書 §4.2 の 7「今の探し方のまま」。ほかの取込もすべて `catch (\Exception $e)`（最小差分）|
| 9 | §2.6 `buyer_surveys.staff_name` は 50 | 100（`BuyerCsvRow::MAX_LENGTH`・Unit テストの `textColumns()`・テスト用スキーマ・変異 B21）| 本番の定義が `varchar(100)`（Task 1 で読み取り）。上限は列の大きさに合わせる（設計書 §4.3）|
| 10 | §2.6 `buyer_survey_answers.question_snapshot` は text | `json` で NULL 不可（テスト用スキーマは `$t->json('question_snapshot')`。SQLite では TEXT NOT NULL になる）| 本番の定義（Task 1）。取込は `SurveyQuestion::toSnapshot()`（配列）を必ず書くので、NULL 不可で困る経路は無い |
| 11 | — | テスト用スキーマの `buyer_survey_answers` に `UNIQUE(survey_id, question_id)`（名前 `uq_survey_answer`）を張る | 本番の定義（Task 1）。テスト用スキーマに本番の一意制約が無いと、本番だけで取込全体が巻き戻る失敗をテストが隠す（Bug #60）。FK は trait の方針どおり張らない |

## 変えるファイル

| ファイル | 変更 |
|---|---|
| `app/Support/BuyerCsvRow.php` | **新規**（1 行の検査と変換。DB に触らない）|
| `app/Http/Controllers/Admin/CustomerImportController.php` | 確定は `csv_data` から読み直す・戻り先を取込の画面に固定・1 行の検査を `BuyerCsvRow` へ・0 件の確定を断る |
| `resources/views/admin/customers/import.blade.php` | 確定の欄の出し分け（0 件・重複候補）と、件数をチェックに合わせる Alpine |
| `tests/Concerns/CreatesRealEstateSchema.php` | `buyer_survey_answers` を足し、`buyer_surveys.staff_name` を 100 にする（Task 1 で読んだ本番の定義で）|
| `tests/Unit/Support/BuyerCsvRowTest.php` | **新規**（61 本）|
| `tests/Feature/Admin/CustomerImportTest.php` | **新規**（20 本）|
| `tests/Feature/ImportControllerReturnPathScanTest.php`・`tests/Feature/ImportControllerValidationRedirectScanTest.php` | 例外リストから顧客を外す |
| `docs/RULES.md`・`CLAUDE.md`・`docs/BACKLOG.md`・この計画 | 記録（Task 9）|

DB・ルート・テンプレート（`downloadTemplate()`）・設問の列（Q1:…）の対応づけ・依存は変えない。

## 共通の決まり

- 作業は worktree で行う。⚠ `cd` を含まないコマンドは、ハーネスの cwd が main repo に戻っていることがあるので、`cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm &&` を付けるか `git -C <worktree>` にする
- 全件テスト（約 100 秒）: `cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`
- 1 本だけ: 上の末尾にファイルを足す（例 `./vendor/bin/phpunit tests/Feature/Admin/CustomerImportTest.php`）
- ⚠ `.env` は読まない（worktree に `.env` は無い。テストは `APP_KEY` の環境変数だけで起動する）
- コミット: Conventional Commits・件名は日本語で 72 文字以内・句点なし・末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`（サブエージェントは自分の文脈の Co-Authored-By 行を使う）。HEREDOC で書く。`--no-verify` は使わない。コミットの後に `git status --porcelain` が空であることを見る
- サブエージェントに任せるときは 1 つの worktree に 1 つのエージェント。書いたらすぐコミットする（未コミットの編集が残っていると、変異の実行役が「作業ツリーが空でない」で止まる）
- zsh: 引用なしの `$VAR` は単語分割されない（複数のパスは配列で）・`grep` は ugrep（`-` で始まるパターンは `-e`）・`php -r "…"` の `$` はシェルが展開する（`'…'` で囲む）

---

## Task 0: 前提の確認

**Files:** なし

- [ ] **Step 1: worktree とブランチ**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && git status --porcelain && git rev-parse --abbrev-ref HEAD && git log --oneline -1 && git merge-base --is-ancestor 13.x HEAD && echo 'ahead-of-13.x'
```

期待: `status` は何も出さない・`customer-import-confirm`・この計画のコミット（`docs: 顧客CSVインポートの確定を通す実装計画を書く`）・`ahead-of-13.x`。

⚠ `ahead-of-13.x` が出ない（`13.x` がこの後に進んだ）ときは、作業を始める前に取り込む（リベースしない＝記録のコミット番号を保つ）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && git log --stat --format='%h %s' HEAD..13.x && git merge --no-edit -m "$(cat <<'EOF'
Merge branch '13.x' into customer-import-confirm

作業中に 13.x へ入ったコミットを取り込む。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" 13.x
```

取り込んだコミットがアプリのコードやテストを変えていたら、Step 2 の期待の本数が変わるので測り直して記録する。

- [ ] **Step 2: この時点の全件テスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

期待: `OK (2263 tests, 14765 assertions)`

- [ ] **Step 3: node**

```bash
command -v node && node --version
```

期待: node のパスと版（v24 系）。無ければ Task 4 の件数の JS のテストは飛ばされる（`markTestSkipped`）ので、Task 7 のブラウザで必ず見る。

---

## Task 1: 本番の列の定義を読み取る（⚠ 承認をもらってから）

**Files:** なし（読み取りだけ。本番のデータには触れない）

⚠ 実行の前に、利用者の承認を本文でもらう（「本番の `buyers`・`buyer_departments`・`buyer_surveys`・`buyer_survey_answers` の定義を `SHOW CREATE TABLE` で読みます。書き込みはしません。よろしいですか」と示し、はっきりした了承を待つ）。

- [ ] **Step 1: 読む**

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='foreach (["buyers", "buyer_departments", "buyer_surveys", "buyer_survey_answers"] as $t) { $r = (array) DB::select("SHOW CREATE TABLE " . $t)[0]; echo $r["Create Table"], "\n\n"; }'
SH
```

⚠ 本番のログインシェルは csh なので、`/bin/sh` へ heredoc で流す（`'SH'` と引用するので手元の zsh は何も展開しない）。素の `php` は 7.4 なので 8.3 を明示する。表の名前は固定の 4 つを文字列でつなぐだけ（`SHOW CREATE TABLE` は表の名前をバインドできない）。

期待: `CREATE TABLE` が 4 つ出る。

- [ ] **Step 2: 設計書 §2.6 と突き合わせる**

| 表 | 列 | 設計書 §2.6（＝ Task 3 の `BuyerCsvRow` の上限・Task 2 のテスト用スキーマ）|
|---|---|---|
| buyers | `last_name`・`first_name`・`last_name_kana`・`first_name_kana` | varchar(50) |
| | `birth_era` | varchar(10)（`S`・`H`・`R` の 1 文字しか入れない）|
| | `postal_code`・`prefecture` | varchar(10) |
| | `city` | varchar(50) |
| | `address_detail`・`building_name`・`email` | varchar(255) |
| | `phone` | varchar(20) |
| | `occupation` | varchar(50) |
| | `employer` | varchar(100) |
| | `family_adults`・`family_children` | tinyint unsigned（0〜255）|
| | `years_employed` | smallint unsigned（0〜65535）|
| | `birth_date` | date |
| buyer_departments | `acquired_date` ／ 索引 | date ／ UNIQUE(`buyer_id`, `department`) |
| buyer_surveys | `staff_name` ／ `survey_date` | varchar(50) ／ date |
| buyer_survey_answers | `id`・`survey_id`・`question_id`・`answer_value`・`question_snapshot`・`created_at`・`updated_at` | テスト用スキーマに無い（Task 2 で足す。型は Step 3 で合わせる）|

- [ ] **Step 3: 違いがあれば、この計画を直してから Task 2 へ進む**
  - 文字の列の大きさが違う → Task 3 の `MAX_LENGTH` と Unit テストの `textColumns()`、`tests/Concerns/CreatesRealEstateSchema.php` の `buyers` を本番に合わせる
  - 整数の列の型が違う → `MAX_INTEGER` と `integerColumns()` を合わせる（符号つきの `tinyint` は 127・`smallint` は 32767 が上限。整数の誤りの文言の「0〜255」も変わる）
  - `buyer_survey_answers` の列の型・NULL の可否 → Task 2 のコードを本番に合わせる（`question_snapshot` が `json` なら `$t->json('question_snapshot')->nullable()`）
  - 上限を変えたら、Task 3・4・5 の期待の本数（アサートの数）と Task 6 の表の文言（「0〜255」など）も変わる。測り直して記録する
  - 4 表の `CREATE TABLE` の要点（列・型・NULL の可否・索引）を、この計画の末尾の「実測記録」に書く（Task 9 でコミット）

---

## Task 2: テスト用スキーマに buyer_survey_answers を足す

**Files:**
- Modify: `tests/Concerns/CreatesRealEstateSchema.php`（`buyer_surveys` の注記と `staff_name`、その直後）

- [ ] **Step 1: 置き換える**（Edit。`buyer_surveys` の「作らない」の注記を消して `staff_name` を本番の 100 にし、直後に回答の表を足す。列は Task 1 で読んだ本番の定義に合わせる）

置き換える前:

```php
        // 買主アンケート。本番も raw SQL 管理でマイグレーションに無い。
        // CustomerController::show() が eager load するので、顧客詳細を HTTP で叩くには必要。
        // （回答テーブル buyer_survey_answers は show が触らないので作らない）
        Schema::create('buyer_surveys', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('buyer_id');
            $t->string('department', 20);
            $t->date('survey_date');
            $t->unsignedBigInteger('project_id')->nullable();
            $t->unsignedInteger('staff_user_id')->nullable();
            $t->string('staff_name', 50)->nullable();
            $t->text('memo')->nullable();
            $t->timestamps();
        });
```

置き換えた後:

```php
        // 買主アンケート。本番も raw SQL 管理でマイグレーションに無い。
        // CustomerController::show() が eager load するので、顧客詳細を HTTP で叩くには必要。
        Schema::create('buyer_surveys', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('buyer_id');
            $t->string('department', 20);
            $t->date('survey_date');
            $t->unsignedBigInteger('project_id')->nullable();
            $t->unsignedInteger('staff_user_id')->nullable();
            $t->string('staff_name', 100)->nullable();
            $t->text('memo')->nullable();
            $t->timestamps();
        });

        // アンケートの回答。本番も raw SQL 管理でマイグレーションに無い。
        // 顧客 CSV 取込の確定が書く（Admin\CustomerImportController）。
        // 実 DB（2026-09-26 に読み取り）:
        //   id / survey_id / question_id / answer_value text / question_snapshot json NOT NULL
        //   / created_at・updated_at（CURRENT_TIMESTAMP 既定値）+ UNIQUE (survey_id, question_id)
        // ⚠ 一意制約も張る（無いと、本番だけで取込全体が巻き戻る失敗をテストが隠す。Bug #60）
        Schema::create('buyer_survey_answers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('survey_id');
            $t->unsignedBigInteger('question_id');
            $t->text('answer_value')->nullable();
            $t->json('question_snapshot');
            $t->timestamps();
            $t->unique(['survey_id', 'question_id'], 'uq_survey_answer');
        });
```

- [ ] **Step 2: 全件テスト**（まだどのテストもこの表を使わないので、本数は変わらない）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

期待: `OK (2263 tests, 14765 assertions)`（ほかのテストが同じ名前の表を自分で作っていれば、ここで `table buyer_survey_answers already exists` になる。試作では出なかった）

- [ ] **Step 3: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && git add tests/Concerns/CreatesRealEstateSchema.php && git commit -m "$(cat <<'EOF'
test: テスト用スキーマにアンケートの回答の表を足す

buyer_survey_answers は本番も raw SQL 管理でマイグレーションに無い。顧客 CSV 取込の
確定がアンケートの回答を書くので、確定まで往復するテストに要る。列は本番の定義
（2026-09-26 に読み取り）に合わせた（question_snapshot は json で NULL 不可・
UNIQUE(survey_id, question_id) も張る）。buyer_surveys.staff_name も本番の 100 に直す。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 3: 1 行の検査と変換（BuyerCsvRow）

**Files:**
- Create: `tests/Unit/Support/BuyerCsvRowTest.php`
- Create: `app/Support/BuyerCsvRow.php`

設計書 §4.3 の表のとおり。⚠ Laravel を起動しない Unit テストなので、`BuyerCsvRow` に時刻や timezone に頼る処理を入れない（Bug #54 ①。`CsvDate` は `checkdate()` なので依存しない）。

- [ ] **Step 1: 落ちるテストを書く**（`tests/Unit/Support/BuyerCsvRowTest.php` をこの内容で作る）

```php
<?php

namespace Tests\Unit\Support;

use App\Support\BuyerCsvRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 顧客 CSV 取込の 1 行の検査と変換（設計書 docs/superpowers/specs/2026-09-25-customer-import-confirm-design.md §4.3）。
 *
 * ⚠ 下の「誤り」の値は、どれも直す前の取込を素通りして、壊れた値を書くか全行を巻き戻していたもの（設計書 §2.2）。
 *   消さないこと。
 * ⚠ 上限の数は本番の列の大きさ（設計書 §2.6）。クラスの定数を読まずに数を書く（読むと同じ式で確かめることになる）。
 * ⚠ Laravel を起動しないテスト。`BuyerCsvRow` は時刻も timezone も使わないので、固定は要らない（Bug #54 ①）。
 */
class BuyerCsvRowTest extends TestCase
{
    /** 必須の 3 項目が入った行。ほかの列は空欄（$cells で足す・上書きする） */
    private function row(array $cells = []): BuyerCsvRow
    {
        return BuyerCsvRow::from($cells + [
            'last_name' => '山田', 'first_name' => '太郎', 'acquired_date' => '2026-09-01',
        ]);
    }

    // ================================================================
    // 必須・空欄・前後の空白
    // ================================================================

    public function test_a_row_with_only_the_required_columns_is_valid(): void
    {
        $row = $this->row();

        $this->assertFalse($row->hasErrors());
        $this->assertSame([], $row->errors);
        $this->assertSame(['last_name' => '山田', 'first_name' => '太郎'], $row->buyer);
        $this->assertSame('2026-09-01', $row->acquiredDate);
        $this->assertSame('', $row->projectName);
        $this->assertSame('', $row->staffName);
    }

    public function test_the_three_required_columns_report_their_own_messages(): void
    {
        $row = BuyerCsvRow::from([]);

        $this->assertTrue($row->hasErrors());
        $this->assertSame(['姓が未入力です', '名が未入力です', '取得日が未入力です'], $row->errors);
        $this->assertNull($row->acquiredDate);
    }

    public function test_blank_cells_are_left_out_of_the_buyer_values(): void
    {
        $row = $this->row(['last_name_kana' => '', 'city' => '   ', 'birth_era' => '']);

        $this->assertFalse($row->hasErrors());
        $this->assertSame(['last_name', 'first_name'], array_keys($row->buyer));
    }

    public function test_cells_are_trimmed(): void
    {
        $row = $this->row(['last_name' => '  山田 ', 'city' => "松山市\r", 'staff_name' => ' 田中 ']);

        $this->assertSame('山田', $row->buyer['last_name']);
        $this->assertSame('松山市', $row->buyer['city']);
        $this->assertSame('田中', $row->staffName);
    }

    public function test_every_column_goes_to_the_right_place(): void
    {
        $row = BuyerCsvRow::from([
            'last_name' => '山田', 'first_name' => '太郎', 'last_name_kana' => 'ヤマダ', 'first_name_kana' => 'タロウ',
            'birth_date' => '1980/1/2', 'birth_era' => '昭和', 'family_adults' => '２', 'family_children' => '1',
            'postal_code' => '790-0001', 'prefecture' => '愛媛県', 'city' => '松山市', 'address_detail' => '一番町1-1',
            'building_name' => 'ミツワビル', 'phone' => '089-000-0000', 'email' => 'taro@example.com',
            'occupation' => '会社員', 'employer' => '株式会社ミツワ', 'years_employed' => '12',
            'acquired_date' => '2026/9/1', 'project_name' => '南梅本の杜', 'staff_name' => '田中',
        ]);

        $this->assertSame([], $row->errors);
        $this->assertSame([
            'last_name' => '山田', 'first_name' => '太郎', 'last_name_kana' => 'ヤマダ', 'first_name_kana' => 'タロウ',
            'birth_date' => '1980-01-02', 'birth_era' => 'S', 'family_adults' => 2, 'family_children' => 1,
            'postal_code' => '790-0001', 'prefecture' => '愛媛県', 'city' => '松山市', 'address_detail' => '一番町1-1',
            'building_name' => 'ミツワビル', 'phone' => '089-000-0000', 'email' => 'taro@example.com',
            'occupation' => '会社員', 'employer' => '株式会社ミツワ', 'years_employed' => 12,
        ], $row->buyer);
        $this->assertSame('2026-09-01', $row->acquiredDate);
        $this->assertSame('南梅本の杜', $row->projectName);
        $this->assertSame('田中', $row->staffName);
    }

    // ================================================================
    // 日付（CsvDate::normalize()。checkdate() で存在を見る）
    // ================================================================

    /** @return array<string, array{0: string, 1: string}> [内部のキー, 見出し] */
    public static function dateColumns(): array
    {
        return [
            '生年月日' => ['birth_date', '生年月日'],
            '取得日'   => ['acquired_date', '取得日'],
        ];
    }

    #[DataProvider('dateColumns')]
    public function test_a_date_that_cannot_be_read_is_an_error(string $key, string $label): void
    {
        // 直す前: 19800102 は date キャストが Unix 時刻として読み 1970-08-18 に、
        // 2026-02-30 は 3/2 に繰り上がり、昭和55年1月2日 は確定で例外になり全行が巻き戻った
        foreach (['19800102', '2026-02-30', '1980-02-30', '昭和55年1月2日'] as $value) {
            $row = $this->row([$key => $value]);

            $this->assertSame(
                ["{$label}「{$value}」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）"],
                $row->errors,
                "{$label} {$value}"
            );
        }
    }

    #[DataProvider('dateColumns')]
    public function test_a_date_is_normalized_to_hyphens(string $key): void
    {
        // 直す前: 2026/9/1 は取得日の正規表現（2 桁を要求）で、1970-01-01 は strtotime() が 0 を返して弾かれた
        foreach (['2026/9/1' => '2026-09-01', '2026-09-01' => '2026-09-01', '1970-01-01' => '1970-01-01'] as $value => $expected) {
            $row = $this->row([$key => $value]);

            $this->assertSame([], $row->errors, "{$key} {$value}");
            $this->assertSame($expected, $key === 'acquired_date' ? $row->acquiredDate : $row->buyer[$key]);
        }
    }

    // ================================================================
    // 元号
    // ================================================================

    public function test_an_era_is_accepted_as_a_letter_in_any_width_or_case_or_as_its_name(): void
    {
        $cases = [
            'S' => 'S', 's' => 'S', 'Ｓ' => 'S', 'ｓ' => 'S', '昭和' => 'S',
            'H' => 'H', 'ｈ' => 'H', '平成' => 'H',
            'R' => 'R', 'Ｒ' => 'R', '令和' => 'R',
        ];

        foreach ($cases as $value => $expected) {
            $row = $this->row(['birth_era' => $value]);

            $this->assertSame([], $row->errors, "元号 {$value}");
            $this->assertSame($expected, $row->buyer['birth_era'], "元号 {$value}");
        }
    }

    public function test_an_unknown_era_is_an_error(): void
    {
        foreach (['明治', 'T', 'S.', '昭'] as $value) {
            $this->assertSame(
                ["元号「{$value}」は S・H・R（昭和・平成・令和）で入力してください"],
                $this->row(['birth_era' => $value])->errors,
                "元号 {$value}"
            );
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: string|null}> [元号, 生年月日, 誤り（合えば null）] */
    public static function eraRanges(): array
    {
        return [
            '昭和の前の年'       => ['S', '1925-12-31', '元号「S」と生年月日「1925-12-31」が合いません（昭和は1926〜1989年）'],
            '昭和の最初の年'     => ['S', '1926-01-01', null],
            '昭和の最後の年'     => ['S', '1989-01-07', null],
            '昭和の次の年'       => ['S', '1990-01-01', '元号「S」と生年月日「1990-01-01」が合いません（昭和は1926〜1989年）'],
            '平成の最初の年'     => ['H', '1989-01-08', null],
            '平成の前の年'       => ['H', '1988-12-31', '元号「H」と生年月日「1988-12-31」が合いません（平成は1989〜2019年）'],
            '平成の最後の年'     => ['H', '2019-04-30', null],
            '平成の次の年'       => ['H', '2020-01-01', '元号「H」と生年月日「2020-01-01」が合いません（平成は1989〜2019年）'],
            '令和の前の年'       => ['R', '2018-12-31', '元号「R」と生年月日「2018-12-31」が合いません（令和は2019年から）'],
            '令和の最初の年'     => ['R', '2019-05-01', null],
            '名前で書いた元号'   => ['昭和', '2000-01-01', '元号「昭和」と生年月日「2000-01-01」が合いません（昭和は1926〜1989年）'],
        ];
    }

    #[DataProvider('eraRanges')]
    public function test_an_era_must_match_the_year_of_birth(string $era, string $birthDate, ?string $error): void
    {
        $row = $this->row(['birth_era' => $era, 'birth_date' => $birthDate]);

        $this->assertSame($error === null ? [] : [$error], $row->errors);
    }

    public function test_an_era_without_a_birth_date_is_kept_and_not_range_checked(): void
    {
        $row = $this->row(['birth_era' => 'R']);

        $this->assertSame([], $row->errors);
        $this->assertSame('R', $row->buyer['birth_era']);
        $this->assertArrayNotHasKey('birth_date', $row->buyer);
    }

    public function test_an_era_is_not_range_checked_against_a_birth_date_that_cannot_be_read(): void
    {
        $row = $this->row(['birth_era' => 'R', 'birth_date' => '1980-02-30']);

        $this->assertSame(['生年月日「1980-02-30」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）'], $row->errors);
    }

    // ================================================================
    // 整数（人数・勤続年数）
    // ================================================================

    /** @return array<string, array{0: string, 1: string, 2: int}> [内部のキー, 見出し, 上限] */
    public static function integerColumns(): array
    {
        return [
            '大人人数' => ['family_adults', '大人人数', 255],
            '子供人数' => ['family_children', '子供人数', 255],
            '勤続年数' => ['years_employed', '勤続年数', 65535],
        ];
    }

    #[DataProvider('integerColumns')]
    public function test_an_integer_is_read_up_to_its_limit(string $key, string $label, int $max): void
    {
        $cases = ['0' => 0, '02' => 2, '２' => 2, (string) $max => $max];

        foreach ($cases as $value => $expected) {
            $row = $this->row([$key => (string) $value]);

            $this->assertSame([], $row->errors, "{$label} {$value}");
            $this->assertSame($expected, $row->buyer[$key], "{$label} {$value}");
        }
    }

    #[DataProvider('integerColumns')]
    public function test_a_value_that_is_not_an_integer_in_range_is_an_error(string $key, string $label, int $max): void
    {
        // 直す前: 2人 は SQLite にそのまま入り、本番の MySQL（strict モード）では全行が巻き戻った
        foreach ([(string) ($max + 1), '2人', '-1', '1.5', '99999999999999999999'] as $value) {
            $this->assertSame(
                ["{$label}「{$value}」は0〜{$max}の整数で入力してください"],
                $this->row([$key => $value])->errors,
                "{$label} {$value}"
            );
        }
    }

    // ================================================================
    // 文字数（全角で数える）
    // ================================================================

    /** @return array<string, array{0: string, 1: string, 2: int}> [内部のキー, 見出し, 上限] */
    public static function textColumns(): array
    {
        return [
            '姓'             => ['last_name', '姓', 50],
            '名'             => ['first_name', '名', 50],
            'セイ'           => ['last_name_kana', 'セイ', 50],
            'メイ'           => ['first_name_kana', 'メイ', 50],
            '郵便番号'       => ['postal_code', '郵便番号', 10],
            '都道府県'       => ['prefecture', '都道府県', 10],
            '市区町村'       => ['city', '市区町村', 50],
            '住所詳細'       => ['address_detail', '住所詳細', 255],
            '建物名'         => ['building_name', '建物名', 255],
            '電話番号'       => ['phone', '電話番号', 20],
            'メールアドレス' => ['email', 'メールアドレス', 255],
            '職業'           => ['occupation', '職業', 50],
            '勤務先'         => ['employer', '勤務先', 100],
            '担当者名'       => ['staff_name', '担当者名', 100],
        ];
    }

    #[DataProvider('textColumns')]
    public function test_text_up_to_the_limit_is_kept(string $key, string $label, int $max): void
    {
        $row = $this->row([$key => str_repeat('あ', $max)]);

        $this->assertSame([], $row->errors, $label);
        $this->assertSame(str_repeat('あ', $max), $key === 'staff_name' ? $row->staffName : $row->buyer[$key]);
    }

    #[DataProvider('textColumns')]
    public function test_text_over_the_limit_is_an_error(string $key, string $label, int $max): void
    {
        // 担当者名は、回答が空欄でアンケートを作らない行でも見る（行によって規則が変わらないように）
        $this->assertSame(
            ["{$label}は{$max}文字以内で入力してください（" . ($max + 1) . '文字）'],
            $this->row([$key => str_repeat('あ', $max + 1)])->errors,
            $label
        );
    }

    public function test_the_project_name_is_not_checked(): void
    {
        $row = $this->row(['project_name' => str_repeat('あ', 300)]);

        $this->assertSame([], $row->errors);
        $this->assertSame(str_repeat('あ', 300), $row->projectName);
        $this->assertArrayNotHasKey('project_name', $row->buyer);
    }

    // ================================================================
    // 1 行に誤りが複数あるとき
    // ================================================================

    public function test_every_error_in_a_row_is_reported_in_column_order_and_joined(): void
    {
        $row = BuyerCsvRow::from([
            'first_name' => '太郎', 'birth_date' => '19800102', 'birth_era' => '明治',
            'family_adults' => '2人', 'city' => str_repeat('あ', 51), 'acquired_date' => '2026-02-30',
        ]);

        $expected = [
            '姓が未入力です',
            '生年月日「19800102」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）',
            '元号「明治」は S・H・R（昭和・平成・令和）で入力してください',
            '大人人数「2人」は0〜255の整数で入力してください',
            '市区町村は50文字以内で入力してください（51文字）',
            '取得日「2026-02-30」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）',
        ];

        $this->assertSame($expected, $row->errors);
        $this->assertSame(implode('／', $expected), $row->errorMessage());
        $this->assertNull($row->acquiredDate);
    }

    public function test_the_columns_follow_the_template_order(): void
    {
        // 誤りの並び（CSV の列の順）と、コントローラが見出しを引く表はこの並びに依存する
        $this->assertSame([
            '姓', '名', 'セイ', 'メイ', '生年月日', '元号', '大人人数', '子供人数', '郵便番号', '都道府県',
            '市区町村', '住所詳細', '建物名', '電話番号', 'メールアドレス', '職業', '勤務先', '勤続年数',
            '取得日', '来場分譲地名', '担当者名',
        ], array_keys(BuyerCsvRow::COLUMNS));
    }
}
```

- [ ] **Step 2: 落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Unit/Support/BuyerCsvRowTest.php 2>&1 | grep -m1 -e 'not found'; APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Unit/Support/BuyerCsvRowTest.php 2>&1 | tail -1
```

期待: `Error: Class "App\Support\BuyerCsvRow" not found` と `Tests: 61, Assertions: 0, Errors: 61.`

- [ ] **Step 3: 作る**（`app/Support/BuyerCsvRow.php` をこの内容で作る。Task 1 で本番の列が §2.6 と違ったら、`MAX_LENGTH`・`MAX_INTEGER` を本番に合わせる）

```php
<?php

namespace App\Support;

/**
 * 顧客 CSV 取込の 1 行の検査と変換（DB に触らない）。
 *
 * 取込（`Admin\CustomerImportController`）はプレビューでも確定でもこれを通す。確定でも検査をやり直すので、
 * 確認画面から送り返された CSV（`csv_data`）が書き換えられていても、プレビューで通らない行は入らない。
 *
 * ⚠ 1 行の誤りはすべて集め、CSV の列の順に並べる（直して上げ直すたびに次の誤りが出る、を避ける）。
 * ⚠ 日付は `CsvDate::normalize()`（`checkdate()`）で読む。モデルの date キャストに任せると `19800102` は
 *   Unix 時刻として読まれ、`2026-02-30` は 3/2 に繰り上がる（docs/RULES.md Bug #54・#66）。
 * ⚠ 上限は本番の列の大きさ（設計書 docs/superpowers/specs/2026-09-25-customer-import-confirm-design.md §2.6）。
 *   超えると本番の MySQL（strict モード）が 1 行の誤りで取込の全行を巻き戻す。
 * ⚠ Laravel を起動しない Unit テストで使うので、時刻や timezone に頼る処理を入れない（Bug #54 ①）。
 */
final class BuyerCsvRow
{
    /** CSV の見出し => 内部のキー（テンプレートの並び。設問の列（Q1:…）は含まない） */
    public const COLUMNS = [
        '姓' => 'last_name', '名' => 'first_name',
        'セイ' => 'last_name_kana', 'メイ' => 'first_name_kana',
        '生年月日' => 'birth_date', '元号' => 'birth_era',
        '大人人数' => 'family_adults', '子供人数' => 'family_children',
        '郵便番号' => 'postal_code', '都道府県' => 'prefecture',
        '市区町村' => 'city', '住所詳細' => 'address_detail',
        '建物名' => 'building_name', '電話番号' => 'phone',
        'メールアドレス' => 'email', '職業' => 'occupation',
        '勤務先' => 'employer', '勤続年数' => 'years_employed',
        '取得日' => 'acquired_date', '来場分譲地名' => 'project_name',
        '担当者名' => 'staff_name',
    ];

    /** 空欄なら誤りにする列（文言は「〇〇が未入力です」） */
    private const REQUIRED = ['last_name', 'first_name', 'acquired_date'];

    /** 文字の列の上限（buyers の列の大きさ。担当者名は buyer_surveys.staff_name） */
    private const MAX_LENGTH = [
        'last_name' => 50, 'first_name' => 50,
        'last_name_kana' => 50, 'first_name_kana' => 50,
        'postal_code' => 10, 'prefecture' => 10, 'city' => 50,
        'address_detail' => 255, 'building_name' => 255,
        'phone' => 20, 'email' => 255, 'occupation' => 50, 'employer' => 100,
        'staff_name' => 100,
    ];

    /** 整数の列の上限（大人人数・子供人数は tinyint unsigned、勤続年数は smallint unsigned） */
    private const MAX_INTEGER = [
        'family_adults' => 255, 'family_children' => 255, 'years_employed' => 65535,
    ];

    /**
     * 元号の記号 => [名前, 最初の年, 最後の年（令和は null）]。
     * 年は詳細画面（`Buyer::getBirthDateDisplayAttribute()`）の差と同じ（昭和 1〜64 年・平成 1〜31 年・令和 1 年〜）。
     */
    private const ERAS = [
        'S' => ['昭和', 1926, 1989],
        'H' => ['平成', 1989, 2019],
        'R' => ['令和', 2019, null],
    ];

    /** buyers に入れない列（部署の取得日・アンケートの分譲地と担当者に使う） */
    private const NOT_BUYER = ['acquired_date', 'project_name', 'staff_name'];

    /**
     * @param  array<string, string|int>  $buyer  buyers に入れる値（空欄の項目は入れない）
     * @param  string|null  $acquiredDate  取得日（Y-m-d）。誤りなら null
     * @param  list<string>  $errors  誤り（CSV の列の順）
     */
    private function __construct(
        public readonly array $buyer,
        public readonly ?string $acquiredDate,
        public readonly string $projectName,
        public readonly string $staffName,
        public readonly array $errors,
    ) {}

    /** @param  array<string, string>  $values  内部のキー => セルの値（列が無ければ空文字でよい） */
    public static function from(array $values): self
    {
        $parsed = [];
        $errors = [];

        foreach (self::COLUMNS as $label => $key) {
            $cell = trim((string) ($values[$key] ?? ''));

            if ($cell === '') {
                if (in_array($key, self::REQUIRED, true)) {
                    $errors[] = "{$label}が未入力です";
                }

                continue;
            }

            [$value, $error] = self::check($key, $label, $cell, $parsed);

            if ($error !== null) {
                $errors[] = $error;

                continue;
            }

            $parsed[$key] = $value;
        }

        return new self(
            array_diff_key($parsed, array_flip(self::NOT_BUYER)),
            $parsed['acquired_date'] ?? null,
            $parsed['project_name'] ?? '',
            $parsed['staff_name'] ?? '',
            $errors,
        );
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** 1 行の誤りを 1 件の文にする（プレビューの「⚠ 行N: …」） */
    public function errorMessage(): string
    {
        return implode('／', $this->errors);
    }

    /**
     * 空欄でないセルを 1 つ検査する。
     *
     * @param  array<string, string|int>  $parsed  ここまでの列の読めた値（元号の範囲は、先に読んだ生年月日で見る）
     * @return array{0: string|int|null, 1: string|null} [保存する値, 誤り]
     */
    private static function check(string $key, string $label, string $cell, array $parsed): array
    {
        if (isset(self::MAX_INTEGER[$key])) {
            return self::integer($label, $cell, self::MAX_INTEGER[$key]);
        }

        return match ($key) {
            'birth_date', 'acquired_date' => self::date($label, $cell),
            'birth_era'    => self::era($cell, $parsed['birth_date'] ?? null),
            // 検査しない（保存せず、分譲地を探すのに使うだけ）
            'project_name' => [$cell, null],
            default        => self::text($label, $cell, self::MAX_LENGTH[$key]),
        };
    }

    /** @return array{0: string|null, 1: string|null} */
    private static function date(string $label, string $cell): array
    {
        $date = CsvDate::normalize($cell);

        if ($date === null) {
            return [null, "{$label}「{$cell}」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）"];
        }

        return [$date, null];
    }

    /**
     * 元号を S・H・R にする。生年月日が読めた行では、その年が元号の範囲にあるかも見る
     * （CSV は元号と生年月日が別の列なので「S と 2000 年」のような食い違いが起きやすい。
     * そのままだと詳細画面に「S.75年」と出る）。生年月日が空欄か誤りなら範囲は見ない。
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function era(string $cell, ?string $birthDate): array
    {
        $code = self::eraCode($cell);

        if ($code === null) {
            return [null, "元号「{$cell}」は S・H・R（昭和・平成・令和）で入力してください"];
        }

        [$name, $first, $last] = self::ERAS[$code];

        if ($birthDate !== null) {
            $year = (int) substr($birthDate, 0, 4);

            if ($year < $first || ($last !== null && $year > $last)) {
                $range = $last === null ? "{$name}は{$first}年から" : "{$name}は{$first}〜{$last}年";

                return [null, "元号「{$cell}」と生年月日「{$birthDate}」が合いません（{$range}）"];
            }
        }

        return [$code, null];
    }

    /** S・H・R（全角・小文字も可）か 昭和・平成・令和 を記号にする。どれでもなければ null */
    private static function eraCode(string $cell): ?string
    {
        $letter = strtoupper(mb_convert_kana($cell, 'a'));

        if (isset(self::ERAS[$letter])) {
            return $letter;
        }

        foreach (self::ERAS as $code => [$name]) {
            if ($cell === $name) {
                return $code;
            }
        }

        return null;
    }

    /**
     * 0 から上限までの整数。全角の数字は半角に直す。先頭の 0 は可（02 は 2）。
     * 桁あふれするほど長い数字は、(int) が PHP_INT_MAX に張り付くので上限で落ちる。
     *
     * @return array{0: int|null, 1: string|null}
     */
    private static function integer(string $label, string $cell, int $max): array
    {
        $digits = mb_convert_kana($cell, 'n');

        if (preg_match('/^[0-9]+$/', $digits) !== 1 || (int) $digits > $max) {
            return [null, "{$label}「{$cell}」は0〜{$max}の整数で入力してください"];
        }

        return [(int) $digits, null];
    }

    /** @return array{0: string|null, 1: string|null} */
    private static function text(string $label, string $cell, int $max): array
    {
        $length = mb_strlen($cell);

        if ($length > $max) {
            return [null, "{$label}は{$max}文字以内で入力してください（{$length}文字）"];
        }

        return [$cell, null];
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Unit/Support/BuyerCsvRowTest.php 2>&1 | tail -1
```

期待: `OK (61 tests, 168 assertions)`

- [ ] **Step 5: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && git add app/Support/BuyerCsvRow.php tests/Unit/Support/BuyerCsvRowTest.php && git commit -m "$(cat <<'EOF'
feat: 顧客 CSV の 1 行を検査して変換する BuyerCsvRow を足す

取込の確定で初めて動く書き込みに、壊れた値や本番の列に入らない値が届かないように、
1 行の検査と変換を DB に触らない値のオブジェクトにまとめる。日付は checkdate() で読み、
元号は S・H・R にそろえて生年月日の年と突き合わせ、人数・勤続年数・文字数は本番の列の
大きさで見る。1 行の誤りはすべて集めて CSV の列の順に並べる（設計書
2026-09-25-customer-import-confirm-design.md §4.3）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 4: 確定を通す（コントローラ・画面・走査テスト）

**Files:**
- Create: `tests/Feature/Admin/CustomerImportTest.php`
- Modify: `app/Http/Controllers/Admin/CustomerImportController.php`（`downloadTemplate()` 以外の全体）
- Modify: `resources/views/admin/customers/import.blade.php`（プレビューの STEP 4 と `csvImport()`）
- Modify: `tests/Feature/ImportControllerReturnPathScanTest.php`・`tests/Feature/ImportControllerValidationRedirectScanTest.php`（例外リスト）

- [ ] **Step 1: 落ちるテストを書く**（`tests/Feature/Admin/CustomerImportTest.php` をこの内容で作る）

```php
<?php

namespace Tests\Feature\Admin;

use App\Enums\BuyerRank;
use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\SurveyQuestion;
use App\Models\User;
use App\Support\BuyerCsvRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Concerns\CreatesSurveyQuestionSchema;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 顧客 CSV インポート（`Admin\CustomerImportController`）のプレビュー → 確定の往復。
 *
 * ⚠ 確定（「インポート実行」）は最初のコミット 2046289d から 2026-09-26 まで一度も通っていなかった
 *   （docs/RULES.md Bug #66）。確定のフォームが送るのは `csv_data`（base64）なのに、コントローラが
 *   `csv_file` を常に必須にしていて、押すと「CSVファイルは必須です。」で取込の画面へ戻っていた。
 *   確定まで送るテストが 0 本だった（ImportPreviewRenderTest はプレビューの描画しか見ない）。
 *
 * ⚠ 確定は、プレビューが描いた確定のフォームを分解して**そのまま**送り返す（Bug #47・Bug #54 ②）。
 *   送信先も hidden も自前で組み立てない。チェックボックスは、フォームに描かれていることを確かめてから値を足す
 *   （利用者がチェックを入れるのと同じ）。
 * ⚠ 送信は from(取込の画面) で行い、転送をたどって**着いた画面で**文言を見る（Bug #63）。
 * ⚠ セッションに触らない（`assertSessionHas*()` を呼ぶと、そのあと描いた画面からエラー表示が消える。Bug #49）。
 */
class CustomerImportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;
    use CreatesSurveyQuestionSchema;
    use ParsesForms;

    /** 住宅のテンプレートと同じ並びの見出し（設問の列は無し） */
    private const HEADER = [
        '姓', '名', 'セイ', 'メイ', '生年月日', '元号', '大人人数', '子供人数', '郵便番号', '都道府県',
        '市区町村', '住所詳細', '建物名', '電話番号', 'メールアドレス', '職業', '勤務先', '勤続年数',
        '取得日', '来場分譲地名', '担当者名',
    ];

    /** 「インポート実行」ボタン（見出しにも同じ語があるので、ボタンごと探す。SubmitsImportPreview と同じ理由） */
    private const IMPORT_BUTTON = '/<button\b[^>]*>\s*インポート実行/u';

    private const NO_IMPORTABLE_ROWS = 'インポート可能なデータがありません。CSVを修正してください。';

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        $this->createSurveyQuestionSchema();

        $this->actor = User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    // ================================================================
    // 部品
    // ================================================================

    /**
     * CSV の本文。行は見出し => 値 で渡し、書かない列は空欄にする。
     *
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $extraColumns  見出しの後ろに足す列（設問の列など）
     */
    private function csv(array $rows, array $extraColumns = []): string
    {
        $columns = array_merge(self::HEADER, $extraColumns);
        $lines   = [implode(',', $columns)];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn (string $column) => $row[$column] ?? '', $columns));
        }

        return implode("\n", $lines) . "\n";
    }

    /** 必須の 3 項目と住所（重複の確認に使う）が入った行 */
    private function person(string $last, string $first, array $cells = []): array
    {
        return $cells + ['姓' => $last, '名' => $first, '都道府県' => '愛媛県', '市区町村' => '松山市', '取得日' => '2026-09-01'];
    }

    private function existingBuyer(string $last, string $first): Buyer
    {
        return Buyer::create(['last_name' => $last, 'first_name' => $first, 'prefecture' => '愛媛県', 'city' => '松山市']);
    }

    /** 取込の画面からファイルを上げてプレビューを描かせる（Excel と同じく BOM を付ける） */
    private function preview(string $csv, string $department = 'housing'): TestResponse
    {
        return $this->actingAs($this->actor)
            ->from('/admin/customers/import')
            ->post('/admin/customers/import', [
                'department' => $department,
                'csv_file'   => UploadedFile::fake()->createWithContent('customers.csv', "\xEF\xBB\xBF" . $csv),
            ]);
    }

    /**
     * プレビューが描いた確定のフォームを、ブラウザと同じように分解する。
     *
     * @return array{method: string, action: string, fields: array<string, string>}
     */
    private function confirmForm(TestResponse $preview): array
    {
        $preview->assertStatus(200);

        $form = $this->parseForm($preview->getContent(), 'action="' . route('admin.customers.import.execute') . '"');

        $this->assertSame('POST', $form['method']);
        // ⚠ @csrf の欠落は挙動から検出できない（VerifyCsrfToken が runningUnitTests() で素通りする。Bug #47）
        $this->assertArrayHasKey('_token', $form['fields'], '確定のフォームに @csrf が無い');
        // 確定の印が抜けると、押してもプレビューが描き直されるだけになる（Bug #54 ②）
        $this->assertSame('1', $form['fields']['confirmed'] ?? null, '確定のフォームに confirmed が無い');

        return $form;
    }

    /** 確定のフォームの HTML（開始タグから閉じタグの手前まで） */
    private function confirmFormHtml(string $html): string
    {
        $pos = strpos($html, 'action="' . route('admin.customers.import.execute') . '"');
        $this->assertNotFalse($pos, '確定のフォームが無い');

        $open  = strrpos(substr($html, 0, $pos), '<form');
        $close = strpos($html, '</form>', $pos);

        return substr($html, $open, $close - $open);
    }

    /** 確定のフォームに描かれたチェックボックスの開始タグ */
    private function checkboxTag(string $html, string $name): string
    {
        $found = preg_match(
            '/<input\b[^>]*(?<![\w:.@-])name="' . preg_quote($name, '/') . '"[^>]*>/u',
            $this->confirmFormHtml($html),
            $m
        );
        $this->assertSame(1, $found, "確定のフォームにチェックボックス {$name} が無い");
        $this->assertSame('checkbox', $this->htmlAttr($m[0], 'type'));

        return $m[0];
    }

    /** 利用者がチェックを入れるのと同じ: 確定のフォームに描かれたチェックボックスの value を足す */
    private function tick(TestResponse $preview, array $form, string $name): array
    {
        $form['fields'][$name] = $this->htmlAttr($this->checkboxTag($preview->getContent(), $name), 'value') ?? 'on';

        return $form;
    }

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

    /** 確定のフォームを送り、転送をたどって着いた画面を返す */
    private function submit(array $form): TestResponse
    {
        $response = $this->actingAs($this->actor)
            ->from('/admin/customers/import')
            ->post($form['action'], $form['fields']);

        // ⚠ assertRedirect() は使わない（検証エラーを持つ応答で外れると、失敗文を組み立てる途中で落ちて理由が読めない）
        $this->assertSame(302, $response->getStatusCode(), '確定の応答が転送になっていない');
        $this->assertSame(route('admin.customers.import'), $response->headers->get('Location'));

        return $this->followRedirects($response);
    }

    /**
     * 「インポート実行」ボタンを直接包む要素の開始タグ（x-show で出し分ける要素）。
     *
     * ⚠ 開始タグは引用符の中の `>` を飛ばして切り出す（`x-show="importCount() > 0"` の `>` で途切れる）。
     */
    private function buttonWrapper(string $html): string
    {
        $found = preg_match(self::IMPORT_BUTTON, $html, $m, PREG_OFFSET_CAPTURE);
        $this->assertSame(1, $found, '「インポート実行」ボタンが無い');

        $before = substr($html, 0, $m[0][1]);
        preg_match_all('/<div\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/u', $before, $tags, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($tags[0], 'ボタンを包む要素が無い');

        [$tag, $offset] = end($tags[0]);
        $this->assertStringNotContainsString('</div>', substr($before, $offset), 'ボタンを直接包む要素でない');

        return $tag;
    }

    // ================================================================
    // 確定まで通る
    // ================================================================

    public function test_confirming_the_preview_imports_every_valid_row(): void
    {
        $preview = $this->preview($this->csv([
            $this->person('山田', '太郎'),
            $this->person('佐藤', '花子', ['取得日' => '2026-09-02']),
        ]));
        $this->assertSame(2, $preview->viewData('validCount'));

        $landed = $this->submit($this->confirmForm($preview));

        $landed->assertSee('2件のインポートが完了しました。');
        $this->assertSame(2, Buyer::count());

        $pivot = Buyer::where('last_name', '佐藤')->firstOrFail()->getDepartmentPivot('housing');
        $this->assertNotNull($pivot, '部署の紐付けが無い');
        $this->assertSame('2026-09-02', $pivot->acquired_date->toDateString());
        $this->assertSame(BuyerRank::C, $pivot->rank);
    }

    public function test_the_department_chosen_for_the_preview_is_used_by_the_confirmation(): void
    {
        $preview = $this->preview($this->csv([$this->person('山田', '太郎')]), 'realestate');

        $this->submit($this->confirmForm($preview))->assertSee('1件のインポートが完了しました。');

        $buyer = Buyer::firstOrFail();
        $this->assertNotNull($buyer->getDepartmentPivot('realestate'));
        $this->assertNull($buyer->getDepartmentPivot('housing'));
    }

    public function test_values_are_converted_before_they_are_saved(): void
    {
        // 直す前: 生年月日は date キャストに任せ、元号は書かれた文字のまま、取得日は正規表現が 2 桁を要求していた
        $preview = $this->preview($this->csv([$this->person('山田', '太郎', [
            '生年月日' => '1980/1/2', '元号' => '昭和', '大人人数' => '２', '取得日' => '2026/9/1',
        ])]));

        $this->submit($this->confirmForm($preview))->assertSee('1件のインポートが完了しました。');

        $buyer = Buyer::firstOrFail();
        $this->assertSame('1980-01-02', $buyer->birth_date->toDateString());
        $this->assertSame('S', $buyer->birth_era);
        $this->assertSame(2, $buyer->family_adults);
        // 詳細画面の表示（元号を S にそろえないと「昭和.1980年 1月 2日」と崩れる）
        $this->assertSame('S.55年 1月 2日', $buyer->birth_date_display);
        $this->assertSame('2026-09-01', $buyer->getDepartmentPivot('housing')->acquired_date->toDateString());
    }

    public function test_invalid_rows_are_reported_in_the_preview_and_left_out_of_the_import(): void
    {
        // 直す前: どの行もプレビューを「正常」で通り、確定で壊れた値を書くか全行を巻き戻した（設計書 §2.2）
        $preview = $this->preview($this->csv([
            $this->person('山田', '太郎'),
            $this->person('佐藤', '一', ['生年月日' => '昭和55年1月2日']),
            $this->person('鈴木', '二', ['大人人数' => '2人']),
            $this->person('高橋', '三', ['取得日' => '2026-02-30']),
            $this->person('田中', '四', ['生年月日' => '19800102']),
        ]));

        $expected = [
            ['row' => 3, 'message' => '生年月日「昭和55年1月2日」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）'],
            ['row' => 4, 'message' => '大人人数「2人」は0〜255の整数で入力してください'],
            ['row' => 5, 'message' => '取得日「2026-02-30」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）'],
            ['row' => 6, 'message' => '生年月日「19800102」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）'],
        ];

        // 役割（コントローラが数えたエラー）と表示（画面の「⚠ 行N: …」）を別々に見る（Bug #54 ④）
        $this->assertSame($expected, $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));
        foreach ($expected as $error) {
            $preview->assertSee("⚠ 行{$error['row']}: {$error['message']}");
        }

        $this->submit($this->confirmForm($preview))->assertSee('1件のインポートが完了しました。');

        $this->assertSame(['山田'], Buyer::pluck('last_name')->all());
    }

    public function test_a_survey_and_its_answers_are_imported_with_the_row(): void
    {
        $question = SurveyQuestion::create([
            'department' => 'housing', 'label' => '来場のきっかけ', 'question_type' => 'text',
            'sort_order' => 1, 'is_active' => true,
        ]);
        $projectId = DB::table('re_projects')->insertGetId([
            'project_code' => 'P-0001', 'project_name' => '南梅本の杜', 'status' => 'selling',
            'address' => '愛媛県松山市', 'created_by' => $this->actor->id,
        ]);
        $staff = User::factory()->create(['name' => '田中一郎', 'role' => UserRole::Staff->value]);

        $preview = $this->preview($this->csv([
            $this->person('山田', '太郎', ['来場分譲地名' => '南梅本', '担当者名' => '田中', 'Q1:来場のきっかけ' => '看板']),
            // 回答が空欄の行はアンケートを作らない
            $this->person('佐藤', '花子', ['担当者名' => '田中']),
        ], ['Q1:来場のきっかけ']));

        $this->submit($this->confirmForm($preview))->assertSee('2件のインポートが完了しました。');

        $survey = BuyerSurvey::sole();
        $this->assertSame(Buyer::where('last_name', '山田')->value('id'), $survey->buyer_id);
        $this->assertSame('housing', $survey->department);
        $this->assertSame('2026-09-01', $survey->survey_date->toDateString());
        $this->assertSame($projectId, $survey->project_id);
        $this->assertSame('田中', $survey->staff_name);
        $this->assertSame($staff->id, $survey->staff_user_id);

        $answer = $survey->answers()->sole();
        $this->assertSame($question->id, $answer->question_id);
        $this->assertSame('看板', $answer->answer_value);
        $this->assertSame('来場のきっかけ', $answer->question_snapshot['label']);
    }

    // ================================================================
    // 取り込める行が無いとき・重複候補
    // ================================================================

    public function test_a_preview_with_no_importable_rows_offers_no_import(): void
    {
        $preview = $this->preview($this->csv([$this->person('', '太郎')]));
        $preview->assertStatus(200);
        $html = $preview->getContent();

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertSame([], $preview->viewData('dupeRows'));
        $preview->assertSee('⚠ 行2: 姓が未入力です');
        $preview->assertSee(self::NO_IMPORTABLE_ROWS);

        // 直す前: 0 件でも「インポート実行（0件）」のボタンと csv_data を持つフォームが出ていた
        $this->assertDoesNotMatchRegularExpression(self::IMPORT_BUTTON, $html);
        $this->assertStringNotContainsString('name="csv_data"', $html);
    }

    public function test_a_preview_of_only_duplicates_hides_the_button_until_the_check(): void
    {
        $this->existingBuyer('山田', '太郎');

        $preview = $this->preview($this->csv([$this->person('山田', '太郎')]));
        $html    = $preview->getContent();

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertCount(1, $preview->viewData('dupeRows'));

        // 最初の状態はサーバが描く（V＝0 なのでボタンを包む要素を隠して描く。設計書 §4.4）
        $wrapper = $this->buttonWrapper($html);
        $this->assertStringContainsString('x-show="importCount() > 0"', $wrapper);
        $this->assertStringContainsString('display: none', $wrapper);
        $this->assertMatchesRegularExpression('/インポート実行（<span x-text="importCount\(\)">0<\/span>件）/u', $html);
        // 理由は件数が 0 の間だけ出す（チェックを入れたら消える）
        $this->assertMatchesRegularExpression(
            '/<div x-show="importCount\(\) === 0"[^>]*>重複候補だけです。取り込むときは、上のチェックを入れてください。<\/div>/u',
            $html
        );
        // チェックが件数に結びついている（外すと、チェックを入れてもボタンが出ない）
        $this->assertStringContainsString('x-model="includeDupes"', $this->checkboxTag($html, 'include_duplicates'));

        // Alpine がチェックに合わせて数える元の値（サーバが数えた値）
        $this->assertStringContainsString('validCount: 0,', $html);
        $this->assertStringContainsString('dupeCount: 1,', $html);
    }

    public function test_confirming_only_duplicates_without_the_check_imports_nothing(): void
    {
        $this->existingBuyer('山田', '太郎');
        $preview = $this->preview($this->csv([$this->person('山田', '太郎')]));

        // 画面はボタンを隠すが、JavaScript が動かずボタンが出たままでも、サーバが 0 件の確定を断る
        $landed = $this->submit($this->confirmForm($preview));

        $landed->assertSee('取り込む行がありません。重複候補を取り込むときは「重複候補もインポートする」にチェックを入れてください。');
        $landed->assertDontSee('0件のインポートが完了しました。');
        $this->assertSame(1, Buyer::count());
    }

    public function test_confirming_only_duplicates_with_the_check_imports_them(): void
    {
        $this->existingBuyer('山田', '太郎');
        $preview = $this->preview($this->csv([$this->person('山田', '太郎')]));

        $landed = $this->submit($this->tick($preview, $this->confirmForm($preview), 'include_duplicates'));

        $landed->assertSee('1件のインポートが完了しました。');
        $this->assertSame(2, Buyer::where('last_name', '山田')->count());
    }

    public function test_valid_rows_and_duplicates_show_the_button_with_the_valid_count(): void
    {
        $this->existingBuyer('山田', '太郎');

        $preview = $this->preview($this->csv([$this->person('山田', '太郎'), $this->person('佐藤', '花子')]));
        $html    = $preview->getContent();

        $this->assertSame(1, $preview->viewData('validCount'));
        $this->assertCount(1, $preview->viewData('dupeRows'));

        $wrapper = $this->buttonWrapper($html);
        $this->assertStringContainsString('x-show="importCount() > 0"', $wrapper);
        $this->assertStringNotContainsString('display: none', $wrapper);
        $this->assertMatchesRegularExpression('/インポート実行（<span x-text="importCount\(\)">1<\/span>件）/u', $html);
        $preview->assertDontSee('重複候補だけです。');
        $this->assertStringContainsString('validCount: 1,', $html);
        $this->assertStringContainsString('dupeCount: 1,', $html);
    }

    public function test_the_import_count_follows_the_duplicates_check(): void
    {
        $this->existingBuyer('山田', '太郎');

        // 重複候補だけ（V＝0・D＝1）: チェックを入れるまで 0 件（ボタンは隠れたまま）、入れると 1 件
        $onlyDupes = $this->preview($this->csv([$this->person('山田', '太郎')]));
        $this->assertSame([0, 1], $this->importCountsInNode($onlyDupes->getContent()));

        // 正常 1 件と重複候補 1 件（V＝1・D＝1）: チェックを入れると 2 件
        $mixed = $this->preview($this->csv([$this->person('山田', '太郎'), $this->person('佐藤', '花子')]));
        $this->assertSame([1, 2], $this->importCountsInNode($mixed->getContent()));
    }

    public function test_without_the_check_only_the_valid_rows_are_imported(): void
    {
        $this->existingBuyer('山田', '太郎');
        $preview = $this->preview($this->csv([$this->person('山田', '太郎'), $this->person('佐藤', '花子')]));

        $this->submit($this->confirmForm($preview))->assertSee('1件のインポートが完了しました。');

        $this->assertSame(1, Buyer::where('last_name', '山田')->count());
        $this->assertSame(1, Buyer::where('last_name', '佐藤')->count());
    }

    public function test_with_the_check_the_duplicates_are_imported_too(): void
    {
        $this->existingBuyer('山田', '太郎');
        $preview = $this->preview($this->csv([$this->person('山田', '太郎'), $this->person('佐藤', '花子')]));

        $landed = $this->submit($this->tick($preview, $this->confirmForm($preview), 'include_duplicates'));

        $landed->assertSee('2件のインポートが完了しました。');
        $this->assertSame(2, Buyer::where('last_name', '山田')->count());
        $this->assertSame(1, Buyer::where('last_name', '佐藤')->count());
    }

    // ================================================================
    // 確定でも検査をやり直す（ブラウザから届いた値を信用しない）
    // ================================================================

    /** @return array<string, array{0: string|null}> [csv_data に入れる値（null なら送らない）] */
    public static function brokenCsvData(): array
    {
        return [
            'csv_data が無い'     => [null],
            'csv_data が壊れている' => ['%%%'],
        ];
    }

    #[DataProvider('brokenCsvData')]
    public function test_a_confirmation_without_readable_csv_data_imports_nothing(?string $csvData): void
    {
        $form = $this->confirmForm($this->preview($this->csv([$this->person('山田', '太郎')])));

        if ($csvData === null) {
            unset($form['fields']['csv_data']);
        } else {
            $form['fields']['csv_data'] = $csvData;
        }

        $this->submit($form)->assertSee('CSVファイルにデータがありません。');
        $this->assertSame(0, Buyer::count());
    }

    public function test_a_confirmation_with_a_rewritten_department_is_turned_back(): void
    {
        $form = $this->confirmForm($this->preview($this->csv([$this->person('山田', '太郎')])));
        $form['fields']['department'] = 'tenant';

        $landed = $this->submit($form);

        $landed->assertSee('入力内容にエラーがあります。');
        $landed->assertSee('<li>' . trans('validation.in', ['attribute' => 'インポート先部署']) . '</li>', false);
        $this->assertSame(0, Buyer::count());
    }

    public function test_a_rewritten_csv_data_is_checked_again_on_confirmation(): void
    {
        $form = $this->confirmForm($this->preview($this->csv([$this->person('山田', '太郎')])));
        $form['fields']['csv_data'] = base64_encode($this->csv([
            $this->person('山田', '太郎'),
            $this->person('佐藤', '花子', ['取得日' => '2026-02-30']),
        ]));

        $this->submit($form)->assertSee('1件のインポートが完了しました。');

        $this->assertSame(['山田'], Buyer::pluck('last_name')->all());
    }

    public function test_a_confirmation_with_no_importable_rows_is_turned_back(): void
    {
        // 画面は 0 件のとき確定のフォームを出さないが、書き換えた csv_data で送られてもサーバが断る
        $form = $this->confirmForm($this->preview($this->csv([$this->person('山田', '太郎')])));
        $form['fields']['csv_data'] = base64_encode($this->csv([$this->person('', '太郎')]));

        $landed = $this->submit($form);

        $landed->assertSee(self::NO_IMPORTABLE_ROWS);
        $landed->assertDontSee('0件のインポートが完了しました。');
        $this->assertSame(0, Buyer::count());
    }

    public function test_a_failure_while_writing_rolls_back_every_row(): void
    {
        $form = $this->confirmForm($this->preview($this->csv([
            $this->person('山田', '太郎'),
            $this->person('佐藤', '花子'),
        ])));

        // ⚠ 2 行目で失敗させる（1 行目で失敗させると、巻き戻しを消しても 0 件のままで緑になる。Bug #64 で踏んだ形）
        $created = 0;
        Buyer::creating(function () use (&$created) {
            if (++$created === 2) {
                throw new \RuntimeException('2 行目の書き込みの失敗（テスト）');
            }
        });

        $this->submit($form)->assertSee('インポートに失敗しました: 2 行目の書き込みの失敗（テスト）');

        $this->assertSame(0, DB::table('buyers')->count(), '1 行目が巻き戻っていない');
        $this->assertSame(0, DB::table('buyer_departments')->count());
    }

    // ================================================================
    // テンプレート
    // ================================================================

    public function test_every_template_column_except_questions_is_a_known_column(): void
    {
        SurveyQuestion::create([
            'department' => 'housing', 'label' => '来場のきっかけ', 'question_type' => 'text',
            'sort_order' => 1, 'is_active' => true,
        ]);

        foreach (['housing', 'realestate'] as $department) {
            $csv    = $this->actingAs($this->actor)->get('/admin/customers/import/template?department=' . $department)->getContent();
            $header = str_getcsv(explode("\n", preg_replace('/^\xEF\xBB\xBF/', '', $csv))[0]);

            $unknown = array_values(array_filter(
                $header,
                fn (string $column) => preg_match('/^Q\d+:/', $column) !== 1 && ! array_key_exists($column, BuyerCsvRow::COLUMNS)
            ));

            $this->assertSame([], $unknown, "{$department} のテンプレートに、取込が読まない見出しがある");
        }
    }
}
```

- [ ] **Step 2: 落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/CustomerImportTest.php 2>&1 | tail -1
```

期待: `Tests: 20, Assertions: 183, Failures: 18.` 緑は `test_a_confirmation_with_a_rewritten_department_is_turned_back`（直す前も部署は検査していた）と `test_every_template_column_except_questions_is_a_known_column` の 2 本だけ。確定を送るテストは、着いた取込の画面に期待の文言が無くて落ちる（着いた画面には「CSVファイルは必須です。」）。`test_invalid_rows_…` は `Failed asserting that two arrays are identical.`（直す前は誤りの行を見逃す）、`test_the_import_count_…` は `node で csvImport() を動かせなかった`（直す前の `csvImport()` に `importCount()` が無い）、ボタンを見る 2 本は `ボタンを直接包む要素でない`。

- [ ] **Step 3: コントローラを直す**（`app/Http/Controllers/Admin/CustomerImportController.php` を次の内容にする。`downloadTemplate()` の中身は 1 文字も変えない）

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\SurveyQuestion;
use App\Models\User;
use App\Support\BuyerCsvRow;
use App\Support\CsvImportReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 顧客 CSV インポート（`/admin/customers/import`。経営層のみ）。
 *
 * プレビューと確定を 1 つの `execute()` で受ける（`confirmed` の hidden で分ける）。
 * 確定では、確認画面が `csv_data`（base64）で持ち回った CSV を読み直し、部署・行の検査・重複の確認を
 * すべてやり直す（ブラウザから届いた値は信用しない）。
 *
 * ⚠ 確定（「インポート実行」）は最初のコミット 2046289d から 2026-09-26 まで一度も通っていなかった
 *   （docs/RULES.md Bug #66）。確定のフォームが送るのは `csv_data` なのに `csv_file` を常に必須にしていて、
 *   押すと「CSVファイルは必須です。」で取込の画面へ戻っていた。
 * ⚠ 断るときの戻り先は取込の画面に固定する（`back()` や入力チェックの既定の戻り先＝リファラーを使わない）。
 *   今は確認画面の URL が取込の画面と同じなので 405 にはならないが、ほかの取込と同じ形にそろえる（Bug #64）。
 */
class CustomerImportController extends Controller
{
    /**
     * インポート画面表示
     */
    public function showForm()
    {
        return view('admin.customers.import');
    }

    /**
     * CSVインポート（プレビューと確定）
     */
    public function execute(Request $request)
    {
        try {
            $request->validate([
                'department' => 'required|in:housing,realestate',
            ], [], [
                // 画面ラベルに合わせる（既定は「部署」）
                'department' => 'インポート先部署',
            ]);
        } catch (ValidationException $e) {
            // 戻り先は取込の画面に固定する（クラスの docblock。Bug #64）
            throw $e->redirectTo(route('admin.customers.import'));
        }

        $department = $request->input('department');
        $confirmed  = $request->boolean('confirmed');
        $skipDupes  = !$request->boolean('include_duplicates');

        $content = $this->loadCsv($request, $confirmed);

        $lines = array_filter(explode("\n", $content), function ($line) {
            return trim($line) !== '';
        });

        if (count($lines) < 2) {
            return redirect()->route('admin.customers.import')->with('error', 'CSVファイルにデータがありません。');
        }

        $header = str_getcsv(array_shift($lines));
        $header = array_map('trim', $header);

        // 設問カラム検出（Q1:xxx, Q2:xxx ...）
        $questions = SurveyQuestion::ofDepartment($department)->active()->ordered()->get();
        $questionMap = []; // headerIndex => question
        foreach ($header as $hIdx => $hVal) {
            if (preg_match('/^Q(\d+):/', $hVal)) {
                // sort_order順で対応
                $qNum = (int) preg_replace('/^Q(\d+):.*$/', '$1', $hVal);
                if (isset($questions[$qNum - 1])) {
                    $questionMap[$hIdx] = $questions[$qNum - 1];
                }
            }
        }

        // カラムインデックスマッピング
        $colMap = [];
        foreach ($header as $hIdx => $hVal) {
            $cleanHeader = preg_replace('/^Q\d+:/', '', $hVal);
            $cleanHeader = trim($cleanHeader);
            if (isset(BuyerCsvRow::COLUMNS[$cleanHeader])) {
                $colMap[BuyerCsvRow::COLUMNS[$cleanHeader]] = $hIdx;
            }
        }

        $rowErrors = [];
        $dupeRows  = [];
        $validRows = [];
        $rowNum    = 1;

        foreach ($lines as $line) {
            $rowNum++;
            $cols = str_getcsv($line);

            $values = [];
            foreach ($colMap as $key => $hIdx) {
                $values[$key] = $cols[$hIdx] ?? '';
            }
            $row = BuyerCsvRow::from($values);

            // 1 行の誤りはすべて 1 件にまとめて出す（BuyerCsvRow の docblock）
            if ($row->hasErrors()) {
                $rowErrors[] = ['row' => $rowNum, 'message' => $row->errorMessage()];
                continue;
            }

            // 重複チェック
            $lastName   = $row->buyer['last_name'];
            $firstName  = $row->buyer['first_name'];
            $prefecture = $row->buyer['prefecture'] ?? '';
            $city       = $row->buyer['city'] ?? '';
            $existing   = Buyer::where('last_name', $lastName)
                ->where('first_name', $firstName)
                ->where('prefecture', $prefecture)
                ->where('city', $city)
                ->first();

            if ($existing) {
                $dupeRows[] = [
                    'row'         => $rowNum,
                    'name'        => "{$lastName} {$firstName}",
                    'address'     => "{$prefecture}{$city}",
                    'existing_id' => $existing->id,
                ];
                if ($skipDupes) {
                    continue;
                }
            }

            // バリデーション通過
            $validRows[] = [
                'row'  => $row,
                'cols' => $cols,
            ];
        }

        // プレビューモード（確認前）
        if (!$confirmed) {
            return view('admin.customers.import', [
                'preview'    => true,
                'department' => $department,
                'totalRows'  => count($lines),
                'validCount' => count($validRows),
                'rowErrors'  => $rowErrors,
                'dupeRows'   => $dupeRows,
                'csvData'    => base64_encode($content),
            ]);
        }

        // 取り込む行が 0 件の確定は書かずに断る（画面はそのときボタンを出さないが、JS が動かなくても止める）。
        // 重複候補があるのに 0 件なのは、チェックが入っていないとき（入っていれば重複候補も取り込む行に入る）
        if ($validRows === []) {
            $message = $dupeRows !== []
                ? '取り込む行がありません。重複候補を取り込むときは「重複候補もインポートする」にチェックを入れてください。'
                : 'インポート可能なデータがありません。CSVを修正してください。';

            return redirect()->route('admin.customers.import')->with('error', $message);
        }

        // インポート実行
        DB::beginTransaction();
        try {
            $imported = 0;
            foreach ($validRows as $valid) {
                $row  = $valid['row'];
                $cols = $valid['cols'];

                $buyer = Buyer::create($row->buyer);

                // 部署ピボット（取得日は BuyerCsvRow が Y-m-d にそろえた値）
                $buyer->addToDepartment($department, $row->acquiredDate);

                // アンケート（設問があり、回答データがある場合）
                if (!empty($questionMap)) {
                    $hasAnswer = false;
                    foreach ($questionMap as $hIdx => $q) {
                        if (isset($cols[$hIdx]) && trim($cols[$hIdx]) !== '') {
                            $hasAnswer = true;
                            break;
                        }
                    }

                    if ($hasAnswer) {
                        $surveyData = [
                            'buyer_id'    => $buyer->id,
                            'department'  => $department,
                            'survey_date' => $row->acquiredDate,
                        ];

                        // 分譲地
                        if ($row->projectName) {
                            $project = DB::table('re_projects')
                                ->where('project_name', 'like', $row->projectName . '%')
                                ->first();
                            if ($project) {
                                $surveyData['project_id'] = $project->id;
                            }
                        }

                        // 担当者
                        if ($row->staffName) {
                            $surveyData['staff_name'] = $row->staffName;
                            $staffUser = User::baseUsers()->where('name', 'like', '%' . $row->staffName . '%')->first();
                            if ($staffUser) {
                                $surveyData['staff_user_id'] = $staffUser->id;
                            }
                        }

                        $survey = BuyerSurvey::create($surveyData);

                        foreach ($questionMap as $hIdx => $q) {
                            $val = trim($cols[$hIdx] ?? '');
                            if ($val === '') {
                                continue;
                            }
                            $survey->answers()->create([
                                'question_id'       => $q->id,
                                'answer_value'      => $val,
                                'question_snapshot' => $q->toSnapshot(),
                            ]);
                        }
                    }
                }

                $imported++;
            }

            DB::commit();

            return redirect()->route('admin.customers.import')
                ->with('success', "{$imported}件のインポートが完了しました。");
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->route('admin.customers.import')->with('error', 'インポートに失敗しました: ' . $e->getMessage());
        }
    }

    /**
     * テンプレートCSVダウンロード
     */
    public function downloadTemplate(Request $request)
    {
        $department = $request->input('department', 'housing');

        $headers = ['姓', '名', 'セイ', 'メイ', '生年月日', '元号', '大人人数', '子供人数',
                     '郵便番号', '都道府県', '市区町村', '住所詳細', '建物名', '電話番号',
                     'メールアドレス', '職業', '勤務先', '勤続年数', '取得日'];

        if ($department === 'housing') {
            $headers[] = '来場分譲地名';
        }
        $headers[] = '担当者名';

        // 設問ヘッダー
        $questions = SurveyQuestion::ofDepartment($department)->active()->ordered()->get();
        $qNum = 1;
        foreach ($questions as $q) {
            $headers[] = "Q{$qNum}:{$q->label}";
            $qNum++;
        }

        $deptLabel = ($department === 'housing') ? '住宅事業' : '不動産事業';
        $filename  = "顧客インポートテンプレート_{$deptLabel}.csv";

        $bom = "\xEF\xBB\xBF";
        $csv = $bom . implode(',', $headers) . "\n";

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * CSV の中身（UTF-8・BOM なし）を返す。
     *
     * 確定なら、確認画面が持ち回った base64 から読み直す（プレビューで UTF-8 にそろえ BOM を除いた後の内容なので、
     * 変換し直さない）。壊れていても無くても空文字になり、呼び出し元の「データがありません」で止まる。
     */
    private function loadCsv(Request $request, bool $confirmed): string
    {
        if ($confirmed) {
            return (string) base64_decode((string) $request->input('csv_data', ''));
        }

        try {
            $request->validate([
                'csv_file' => 'required|file|mimes:csv,txt|max:10240',
            ]);
        } catch (ValidationException $e) {
            // 戻り先は取込の画面に固定する（クラスの docblock。Bug #64）
            throw $e->redirectTo(route('admin.customers.import'));
        }

        return CsvImportReader::decode(file_get_contents($request->file('csv_file')->getRealPath()));
    }
}
```

- [ ] **Step 4: `downloadTemplate()` が変わっていないことを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && diff <(git show HEAD:app/Http/Controllers/Admin/CustomerImportController.php | sed -n '/public function downloadTemplate/,/^    }$/p') <(sed -n '/public function downloadTemplate/,/^    }$/p' app/Http/Controllers/Admin/CustomerImportController.php) && echo 'downloadTemplate unchanged'
```

期待: `downloadTemplate unchanged`

- [ ] **Step 5: 画面側の 4 本だけが残ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/CustomerImportTest.php 2>&1 | grep -e '^[0-9]) ' -e '^Tests:'
```

期待: `Tests: 20, Assertions: 234, Failures: 4.` 落ちるのは `test_a_preview_with_no_importable_rows_offers_no_import`・`test_a_preview_of_only_duplicates_hides_the_button_until_the_check`・`test_valid_rows_and_duplicates_show_the_button_with_the_valid_count`・`test_the_import_count_follows_the_duplicates_check` の 4 本（確定そのものはもう通る）。

- [ ] **Step 6: 確定の欄を出し分ける**（`resources/views/admin/customers/import.blade.php`。Edit で置き換える）

置き換える前:

```blade
        {{-- STEP 4: 実行確認 --}}
        <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
            <div style="width: 28px; height: 28px; border-radius: 50%; background: #059669; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0;">4</div>
            <div style="flex: 1;">
                <div style="font-weight: 600; margin-bottom: 8px;">インポート実行</div>
                <form method="POST" action="{{ route('admin.customers.import.execute') }}">
                    @csrf
                    <input type="hidden" name="department" value="{{ $department }}">
                    <input type="hidden" name="confirmed" value="1">
                    <input type="hidden" name="csv_data" value="{{ $csvData }}">

                    @if(count($dupeRows ?? []) > 0)
                        <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; margin-bottom: 12px; cursor: pointer;">
                            <input type="checkbox" name="include_duplicates" value="1" style="accent-color: #059669; width: 16px; height: 16px;">
                            重複候補もインポートする（別人として新規登録）
                        </label>
                    @endif

                    <button type="submit"
                            style="background: #059669; color: #fff; padding: 10px 28px; border-radius: 6px; font-size: 15px; font-weight: 600; border: none; cursor: pointer;">
                        インポート実行（{{ $validCount }}件）
                    </button>
                    <div style="font-size: 12px; color: #6b7280; margin-top: 6px;">※ エラー行（{{ count($rowErrors ?? []) }}件）はスキップされます</div>
                </form>
```

置き換えた後:

```blade
        {{-- STEP 4: 実行確認
             ⚠ 出し分け（V＝取り込める行の数・D＝重複候補の数。設計書 2026-09-25-customer-import-confirm-design.md §4.4）:
               V＝0 かつ D＝0 は確定のフォームを出さない ／ D がある間はボタンの件数をチェックに合わせて変える ／
               V＝0 かつ D がある間は、チェックを入れるまでボタンを隠して理由を出す
             ⚠ 押せないボタンは disabled にせず隠す（disabled の要素の title はホバーで出ない。Top trap #12）
             ⚠ x-show はボタンを包む要素に付け、ボタン自身の style に触らない（Top trap #5・Bug #32）。
               最初の状態はサーバが描く（V＝0 なら display: none）ので、開いた直後に一瞬出て消えない
             ⚠ サーバにも同じ歯止めがある（0 件の確定は断る。CustomerImportController::execute()） --}}
        <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
            <div style="width: 28px; height: 28px; border-radius: 50%; background: #059669; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0;">4</div>
            <div style="flex: 1;">
                <div style="font-weight: 600; margin-bottom: 8px;">インポート実行</div>
                @if($validCount > 0 || count($dupeRows ?? []) > 0)
                    <form method="POST" action="{{ route('admin.customers.import.execute') }}">
                        @csrf
                        <input type="hidden" name="department" value="{{ $department }}">
                        <input type="hidden" name="confirmed" value="1">
                        <input type="hidden" name="csv_data" value="{{ $csvData }}">

                        @if(count($dupeRows ?? []) > 0)
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; margin-bottom: 12px; cursor: pointer;">
                                <input type="checkbox" name="include_duplicates" value="1" x-model="includeDupes" style="accent-color: #059669; width: 16px; height: 16px;">
                                重複候補もインポートする（別人として新規登録）
                            </label>
                        @endif

                        <div x-show="importCount() > 0" style="{{ $validCount === 0 ? 'display: none;' : '' }}">
                            <button type="submit"
                                    style="background: #059669; color: #fff; padding: 10px 28px; border-radius: 6px; font-size: 15px; font-weight: 600; border: none; cursor: pointer;">
                                インポート実行（<span x-text="importCount()">{{ $validCount }}</span>件）
                            </button>
                        </div>
                        @if($validCount === 0)
                            <div x-show="importCount() === 0" style="font-size: 13px; color: #d97706;">重複候補だけです。取り込むときは、上のチェックを入れてください。</div>
                        @endif
                        <div style="font-size: 12px; color: #6b7280; margin-top: 6px;">※ エラー行（{{ count($rowErrors ?? []) }}件）はスキップされます</div>
                    </form>
                @else
                    <div style="font-size: 13px; color: #dc2626;">インポート可能なデータがありません。CSVを修正してください。</div>
                @endif
```

- [ ] **Step 7: 件数をチェックに合わせる**（同じファイルの `csvImport()`。Edit で置き換える）

置き換える前:

```js
        fileName: '',
        onFileSelect: function(event) {
```

置き換えた後:

```js
        fileName: '',
        // 確定の欄: 取り込める行の数と重複候補の数（サーバが数えた値）。チェックを入れると重複候補も数える
        validCount: {{ (int) ($validCount ?? 0) }},
        dupeCount: {{ count($dupeRows ?? []) }},
        includeDupes: false,
        importCount: function() {
            return this.validCount + (this.includeDupes ? this.dupeCount : 0);
        },
        onFileSelect: function(event) {
```

⚠ 関数は `<script>` の中に書く（属性にアロー関数を書かない。Top trap #4）。`x-show` はボタンを包む要素に付け、ボタン自身の `style` に触らない（Top trap #5・Bug #32）。押せないボタンは `disabled` にせず隠す（Top trap #12）。

- [ ] **Step 8: 通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/CustomerImportTest.php 2>&1 | tail -1
```

期待: `OK (20 tests, 256 assertions)`

- [ ] **Step 9: 走査テストが例外リストの食い違いで落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && for t in tests/Feature/ImportControllerReturnPathScanTest.php tests/Feature/ImportControllerValidationRedirectScanTest.php; do APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit "$t" 2>&1 | grep -e 'CustomerImportController.php: ' -e '^Tests:'; done
```

期待（失敗の文と差分の 2 行に同じ文言が出て、そのあとに件数の行）:
- `ImportControllerReturnPathScanTest`: `app/Http/Controllers/Admin/CustomerImportController.php: 件数が 0（分類は 2）: ` ／ `Tests: 4, Assertions: 71, Failures: 1.`
- `ImportControllerValidationRedirectScanTest`: `app/Http/Controllers/Admin/CustomerImportController.php: 包んでいない呼び出しが 0 件（分類は 1）: ` ／ `Tests: 94, Assertions: 166, Failures: 1.`

- [ ] **Step 10: 例外リストから顧客を外す**（2 つのファイルでそれぞれ Edit。次の 5 行を消す）

`tests/Feature/ImportControllerReturnPathScanTest.php` から消す:

```php
        'app/Http/Controllers/Admin/CustomerImportController.php' => [
            2,
            '確認画面の URL が取込の画面と同じ（GET と POST がどちらも /admin/customers/import）。'
                . 'リファラーへ戻ると取込の画面が GET で開く（2026-09-24 実測 200）',
        ],
```

`tests/Feature/ImportControllerValidationRedirectScanTest.php` から消す:

```php
        'app/Http/Controllers/Admin/CustomerImportController.php' => [
            1,
            '確認画面の URL が取込の画面と同じ（GET と POST がどちらも /admin/customers/import）。'
                . 'リファラーへ戻ると取込の画面が GET で開く（2026-09-24 実測 200）',
        ],
```

⚠ `ImportControllerValidationRedirectScanTest::MIN_THROWING_CALLS = 10`（とその注記「2026-09-25 実測 10 件」）はそのまま（実物は 10 → 11 件に増える。下限は下回らない。設計書 §4.5）。

- [ ] **Step 11: 走査テストが通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && for t in tests/Feature/ImportControllerReturnPathScanTest.php tests/Feature/ImportControllerValidationRedirectScanTest.php; do APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit "$t" 2>&1 | tail -1; done
```

期待: `OK (4 tests, 71 assertions)` と `OK (94 tests, 166 assertions)`

- [ ] **Step 12: 関係する既存のテスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && for t in tests/Unit/Support/BuyerCsvRowTest.php tests/Feature/Admin/ImportPreviewRenderTest.php tests/Feature/Admin/ImportValidationFeedbackTest.php tests/Feature/JapaneseValidationMessagesTest.php; do printf '%s => ' "$t"; APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit "$t" 2>&1 | tail -1; done
```

期待: `OK (61 tests, 168 assertions)` ／ `OK (2 tests, 6 assertions)` ／ `OK (3 tests, 12 assertions)` ／ `OK (10 tests, 57 assertions)`

- [ ] **Step 13: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && git add app/Http/Controllers/Admin/CustomerImportController.php resources/views/admin/customers/import.blade.php tests/Feature/Admin/CustomerImportTest.php tests/Feature/ImportControllerReturnPathScanTest.php tests/Feature/ImportControllerValidationRedirectScanTest.php && git commit -m "$(cat <<'EOF'
fix: 顧客CSVインポートの確定を通す

確定のフォームが送るのは csv_data（base64）なのに csv_file を常に必須にしていたため、
「インポート実行」は最初のコミットから一度も通っていなかった。確定は csv_data から
読み直し、部署・行の検査・重複の確認を最初からやり直す。1 行の検査は BuyerCsvRow に
任せ、取り込める行が 0 件の確定は断る。画面の確定の欄は取り込める行と重複候補の数で
出し分け、ボタンの件数はチェックに合わせて変える。戻り先は取込の画面に固定し、
走査テスト 2 本の例外リストから顧客を外す（Bug #64）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 5: 全件テストとコンパイル済みビューの lint

**Files:** なし（確かめるだけ）

- [ ] **Step 1: 全件テスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

期待: `OK (2344 tests, 15190 assertions)`（Task 1 で上限を変えた・Task 0 で `13.x` を取り込んでテストが増えた、などで本数が変わったら、その理由と本数を記録する）

- [ ] **Step 2: コンパイル済みビューの lint**（⚠ `view:cache` の成功表示だけでは足りない。Bug #21 / #26 / #30）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && K="base64:$(php -r 'echo base64_encode(random_bytes(32));')" && APP_KEY="$K" php artisan view:cache 2>&1 | tail -1 && n=0; bad=0; for f in storage/framework/views/*.php; do n=$((n+1)); php -l "$f" >/dev/null 2>&1 || { bad=$((bad+1)); echo "INVALID: $f"; }; done; echo "views=$n invalid=$bad"; APP_KEY="$K" php artisan view:clear 2>&1 | tail -1; git status --porcelain
```

期待: `Blade templates cached successfully.` ／ `views=274 invalid=0` ／ `Compiled views cleared successfully.` ／ `status` は何も出さない。

---

## Task 6: 変異テスト（docs/RULES.md Bug #44 / #50 の作法）

**Files:** なし（隔離した worktree の中だけで変え、戻す。記録はこの計画の末尾の「実測記録」）

作法: 先にコミット（Task 4 まで済み）→ 隔離した worktree（`git worktree add --detach`）に vendor を**実体コピー**（`cp -Rc`。symlink は不可＝ Bug #50）→ 最初にカナリア → 変異 1 つごとに、前後で作業ツリーが空・置き換えがちょうど 1 か所・着弾を確認・`--log-junit` で全件を流す・落ちたテストと**理由の文言**まで記録・戻したあと空を再確認。実行役 `cic-mutate.py` がこの確認を全部行い、1 つでも食い違えば止まる。

`SP` はこのセッションの scratchpad（システムプロンプトの「Scratchpad directory」の絶対パス）。

- [ ] **Step 1: 隔離した worktree を 3 つ作る**（名前は用途とコミットで一意にする＝ Bug #50 の衝突を避ける。コミットは `$SP/cic-mut-commit` に控える）

```bash
SP=<Scratchpad directory>; WT=/Users/masanori/site/manage/.claude/worktrees/customer-import-confirm; C=$(git -C "$WT" rev-parse --short HEAD); echo "$C" > "$SP/cic-mut-commit"; for s in a b c; do git -C "$WT" worktree add --detach "$SP/cic-mut-$C-$s" HEAD && cp -Rc "$WT/vendor" "$SP/cic-mut-$C-$s/vendor"; done; git -C "$WT" worktree list
```

⚠ `vendor` は `.gitignore` 済みなので、コピーしても作業ツリーは空のまま（Step 3 で確かめる）。

- [ ] **Step 2: 実行役を置く**（`$SP/cic-mutate.py`。この内容そのまま。コミットしない）

```python
#!/usr/bin/env python3
"""顧客 CSV 取込の確定（2026-09-26）の変異テストの実行役。

使い方（隔離した worktree の中で。docs/RULES.md Bug #44 / #50 の作法）:
    python3 cic-mutate.py --iso <隔離した worktree> [ID ...]         # ID を省くと全部。全件テストで流す
    python3 cic-mutate.py --iso <…> --targets <テスト> … -- [ID ...]  # 流すテストを絞る（当て直しの確認用）
    python3 cic-mutate.py --iso <…> --check                         # どの変異もちょうど 1 か所に当たるかだけ見る
    python3 cic-mutate.py --list                                    # 定義の一覧だけ

1 つごとに: 前に作業ツリーが空 → 置き換えがちょうど 1 か所 → git status で着弾を確認 →
phpunit を --log-junit で流す → 落ちたテストと理由の 1 行目を記録 → git checkout で戻す → 後に作業ツリーが空。
どこかで食い違えば止まる（無効な測定を集めない）。
"""
import argparse, base64, json, os, subprocess, sys, xml.etree.ElementTree as ET

C = "app/Http/Controllers/Admin/CustomerImportController.php"
B = "app/Support/BuyerCsvRow.php"
V = "resources/views/admin/customers/import.blade.php"

DEPARTMENT_TRY = (
    "        try {\n"
    "            $request->validate([\n"
    "                'department' => 'required|in:housing,realestate',\n"
    "            ], [], [\n"
    "                // 画面ラベルに合わせる（既定は「部署」）\n"
    "                'department' => 'インポート先部署',\n"
    "            ]);\n"
    "        } catch (ValidationException $e) {\n"
    "            // 戻り先は取込の画面に固定する（クラスの docblock。Bug #64）\n"
    "            throw $e->redirectTo(route('admin.customers.import'));\n"
    "        }\n"
)
DEPARTMENT_BARE = (
    "        $request->validate([\n"
    "            'department' => 'required|in:housing,realestate',\n"
    "        ], [], [\n"
    "            // 画面ラベルに合わせる（既定は「部署」）\n"
    "            'department' => 'インポート先部署',\n"
    "        ]);\n"
)
FILE_TRY = (
    "        try {\n"
    "            $request->validate([\n"
    "                'csv_file' => 'required|file|mimes:csv,txt|max:10240',\n"
    "            ]);\n"
    "        } catch (ValidationException $e) {\n"
    "            // 戻り先は取込の画面に固定する（クラスの docblock。Bug #64）\n"
    "            throw $e->redirectTo(route('admin.customers.import'));\n"
    "        }\n"
)
FILE_BARE = (
    "        $request->validate([\n"
    "            'csv_file' => 'required|file|mimes:csv,txt|max:10240',\n"
    "        ]);\n"
)
ERA_RANGE = "            if ($year < $first || ($last !== null && $year > $last)) {\n"
INTEGER_CHECK = "        if (preg_match('/^[0-9]+$/', $digits) !== 1 || (int) $digits > $max) {\n"
INTEGER_LIMITS = "        'family_adults' => 255, 'family_children' => 255, 'years_employed' => 65535,\n"
FORM_GATE = "                @if($validCount > 0 || count($dupeRows ?? []) > 0)\n"
WRAPPER = "<div x-show=\"importCount() > 0\" style=\"{{ $validCount === 0 ? 'display: none;' : '' }}\">"

# (ID, ファイル, 置き換える前, 置き換えた後)。どれも全件テストで流す
MUTATIONS = [
    # ---- カナリア（隔離が効いていれば、コピー側のコードが読まれて赤になる）----
    ("C0", C, "        return view('admin.customers.import');\n", "        return view('admin.customers.import-canary');\n"),
    # ---- コントローラ ----
    ("M01", C, "        if ($confirmed) {\n            return (string) base64_decode((string) $request->input('csv_data', ''));\n        }\n\n", ""),
    ("M02", C, "        if ($validRows === []) {\n", "        if (false) {\n"),
    ("M03", C, "            $message = $dupeRows !== []\n", "            $message = $dupeRows === []\n"),
    ("M04", C, "            return redirect()->route('admin.customers.import')->with('error', 'CSVファイルにデータがありません。');\n",
               "            return back()->with('error', 'CSVファイルにデータがありません。');\n"),
    ("M05", C, "            return redirect()->route('admin.customers.import')->with('error', 'インポートに失敗しました: ' . $e->getMessage());\n",
               "            return back()->with('error', 'インポートに失敗しました: ' . $e->getMessage());\n"),
    ("M06", C, "            return redirect()->route('admin.customers.import')\n                ->with('success',",
               "            return back()\n                ->with('success',"),
    ("M07", C, DEPARTMENT_TRY, DEPARTMENT_BARE),
    ("M08", C, FILE_TRY, FILE_BARE),
    ("M09", C, "            DB::rollBack();\n", ""),
    ("M10", C, "                if ($skipDupes) {\n                    continue;\n                }\n", ""),
    ("M11", C, "                ->where('city', $city)\n                ->first();\n",
               "                ->where('city', $city)\n                ->whereRaw('1 = 0')\n                ->first();\n"),
    ("M12", C, "        $skipDupes  = !$request->boolean('include_duplicates');\n", "        $skipDupes  = true;\n"),
    ("M13", C, "                $buyer->addToDepartment($department, $row->acquiredDate);\n",
               "                $buyer->addToDepartment($department, '2000-01-01');\n"),
    ("M14", C, "                            'survey_date' => $row->acquiredDate,\n", "                            'survey_date' => '2000-01-01',\n"),
    ("M15", C, "            if ($row->hasErrors()) {\n", "            if (! $confirmed && $row->hasErrors()) {\n"),
    ("M16", C, "            if ($existing) {\n", "            if (! $confirmed && $existing) {\n"),
    # ---- 1 行の検査（BuyerCsvRow）----
    ("B01", B, "        $date = CsvDate::normalize($cell);\n",
               "        $date = (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $cell) && strtotime($cell)) ? $cell : null;\n"),
    ("B02", B, "            $parsed[$key] = $value;\n", "            $parsed[$key] = $cell;\n"),
    ("B03", B, "                $errors[] = $error;\n\n                continue;\n", "                $errors[] = $error;\n\n                break;\n"),
    ("B04", B, "        return implode('／', $this->errors);\n", "        return $this->errors[0] ?? '';\n"),
    ("B05", B, "        'S' => ['昭和', 1926, 1989],\n", "        'S' => ['昭和', 1927, 1989],\n"),
    ("B06", B, "        'S' => ['昭和', 1926, 1989],\n", "        'S' => ['昭和', 1926, 1988],\n"),
    ("B07", B, "        'H' => ['平成', 1989, 2019],\n", "        'H' => ['平成', 1990, 2019],\n"),
    ("B08", B, "        'H' => ['平成', 1989, 2019],\n", "        'H' => ['平成', 1989, 2020],\n"),
    ("B09", B, "        'R' => ['令和', 2019, null],\n", "        'R' => ['令和', 2018, null],\n"),
    ("B10", B, ERA_RANGE, "            if ($year <= $first || ($last !== null && $year > $last)) {\n"),
    ("B11", B, ERA_RANGE, "            if ($year < $first || ($last !== null && $year >= $last)) {\n"),
    ("B12", B, ERA_RANGE, "            if ($year < $first || $year > $last) {\n"),
    ("B13", B, "        if ($birthDate !== null) {\n", "        if (false) {\n"),
    ("B14", B, "        $letter = strtoupper(mb_convert_kana($cell, 'a'));\n", "        $letter = $cell;\n"),
    ("B15", B, "            if ($cell === $name) {\n", "            if (false) {\n"),
    ("B16", B, "        $digits = mb_convert_kana($cell, 'n');\n", "        $digits = $cell;\n"),
    ("B17", B, INTEGER_CHECK, "        if ((int) $digits > $max) {\n"),
    ("B18", B, INTEGER_CHECK, "        if (preg_match('/^[0-9]+$/', $digits) !== 1 || (int) $digits >= $max) {\n"),
    ("B19", B, INTEGER_LIMITS, "        'family_adults' => 256, 'family_children' => 255, 'years_employed' => 65535,\n"),
    ("B20", B, INTEGER_LIMITS, "        'family_adults' => 255, 'family_children' => 255, 'years_employed' => 65534,\n"),
    ("B21", B, "        'staff_name' => 100,\n", "        'staff_name' => 101,\n"),
    ("B22", B, "        $length = mb_strlen($cell);\n", "        $length = strlen($cell);\n"),
    ("B23", B, "        if ($length > $max) {\n", "        if ($length >= $max) {\n"),
    ("B24", B, "            $cell = trim((string) ($values[$key] ?? ''));\n", "            $cell = (string) ($values[$key] ?? '');\n"),
    ("B25", B, "            array_diff_key($parsed, array_flip(self::NOT_BUYER)),\n", "            $parsed,\n"),
    ("B26", B, "    private const REQUIRED = ['last_name', 'first_name', 'acquired_date'];\n",
               "    private const REQUIRED = ['last_name', 'first_name'];\n"),
    ("B27", B, "            'project_name' => [$cell, null],\n", "            'project_name' => self::text($label, $cell, 50),\n"),
    # ---- プレビューの画面 ----
    ("V01", V, FORM_GATE, "                @if($validCount > 0)\n"),
    ("V02", V, FORM_GATE, "                @if(true)\n"),
    ("V03", V, WRAPPER, "<div x-show=\"importCount() > 0\">"),
    ("V04", V, WRAPPER, "<div x-show=\"importCount() > 0\" style=\"display: none;\">"),
    ("V05", V, "<div x-show=\"importCount() > 0\" style=", "<div style="),
    ("V06", V, " x-model=\"includeDupes\"", ""),
    ("V07", V, "<div x-show=\"importCount() === 0\" style=", "<div style="),
    ("V08", V, "            return this.validCount + (this.includeDupes ? this.dupeCount : 0);\n", "            return this.validCount;\n"),
    ("V09", V, "        dupeCount: {{ count($dupeRows ?? []) }},\n", "        dupeCount: 0,\n"),
    ("V10", V, "        validCount: {{ (int) ($validCount ?? 0) }},\n", "        validCount: 0,\n"),
    ("V11", V, "<span x-text=\"importCount()\">{{ $validCount }}</span>", "{{ $validCount }}"),
    ("V12", V, "                        <input type=\"hidden\" name=\"confirmed\" value=\"1\">\n", ""),
    ("V13", V, "                        <input type=\"hidden\" name=\"csv_data\" value=\"{{ $csvData }}\">\n", ""),
    ("V14", V, "                        <input type=\"hidden\" name=\"department\" value=\"{{ $department }}\">\n", ""),
    ("V15", V, "                        @csrf\n", ""),
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
        for mid, path, old, new in MUTATIONS:
            print(f"{mid}\t{path}")
        return

    iso = os.path.abspath(args.iso)
    if args.check:
        bad = 0
        for mid, path, old, new in MUTATIONS:
            n = open(os.path.join(iso, path), encoding="utf-8").read().count(old)
            if n != 1:
                bad += 1
                print(f"{mid}: 置き換えが {n} か所（1 でない）")
        print(f"変異 {len(MUTATIONS)} 通り・当たらないもの {bad} 件")
        sys.exit(1 if bad else 0)

    out = os.path.join(iso, "..", os.path.basename(iso) + "-results.jsonl")
    for mid, path, old, new in MUTATIONS:
        if args.ids and mid not in args.ids:
            continue
        if git(iso, "status", "--porcelain").strip():
            sys.exit(f"{mid}: 始める前に作業ツリーが空でない（前の変異の残骸）。止める")
        full = os.path.join(iso, path)
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
            if not args.targets:
                failures = run_phpunit(iso, junit)
            else:
                failures = []
                for target in args.targets:
                    got = run_phpunit(iso, junit, target)
                    failures = None if got is None or failures is None else failures + got
        finally:
            git(iso, "checkout", "--", path)
        if git(iso, "status", "--porcelain").strip():
            sys.exit(f"{mid}: 戻したのに作業ツリーが空でない。止める")
        record = {"id": mid, "file": path, "diff": stat[-1] if stat else landed, "failures": failures}
        with open(out, "a", encoding="utf-8") as f:
            f.write(json.dumps(record, ensure_ascii=False) + "\n")
        count = "junit なし（phpunit が起動しなかった）" if failures is None else f"{len(failures)} 件"
        print(f"== {mid} {path}  落ちたテスト {count}")
        # 開いたままのトランザクションが後続のテストへ連鎖すると数百件になるので、画面には先頭 30 件だけ出す（記録には全件）
        for fl in (failures or [])[:30]:
            print(f"   {fl['test']} :: {fl['reason']}")
        if failures and len(failures) > 30:
            print(f"   …ほか {len(failures) - 30} 件（記録の jsonl を見る）")
    print(f"記録: {out}")


if __name__ == "__main__":
    main()
```

⚠ 読む XML は、この実行役が手元で起動した PHPUnit の `--log-junit` が書いたものだけ（外から来た XML は読まない）。

- [ ] **Step 3: どの変異もちょうど 1 か所に当たることと、作業ツリーが空であることを確かめる**

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cic-mut-commit"); for s in a b c; do git -C "$SP/cic-mut-$C-$s" status --porcelain | head -3; done; python3 "$SP/cic-mutate.py" --iso "$SP/cic-mut-$C-a" --check
```

期待: `status` はどれも何も出さない・`変異 59 通り・当たらないもの 0 件`（カナリア C0 を含めて 59 通り）

- [ ] **Step 4: カナリアを 3 つのコピーで通す**（隔離が効いていれば、コピー側のコードが読まれて赤になる。3 つを Bash の `run_in_background` で同時に起動し、終わりの知らせを待つ。5 分ほど）

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cic-mut-commit"); python3 "$SP/cic-mutate.py" --iso "$SP/cic-mut-$C-a" C0 > "$SP/cic-mut-$C-a-canary.log" 2>&1
```

（`-a` を `-b`・`-c` に替えた 2 本も同時に起動する）

期待: 3 つとも `== C0 … 落ちたテスト 16 件` — `CustomerImportTest` の確定を送る 15 本（着いた画面がビューの無いエラーの画面）と `ImportValidationFeedbackTest::test_an_invalid_file_shows_the_reason_on_screen with data set "顧客CSV"`。**どれか 1 つでも赤にならなければ止める**（コピーでなく元の worktree のコードが読まれている＝ Bug #50）。

- [ ] **Step 5: 変異を流す**（下の 3 つを Bash の `run_in_background` で**同時に**起動し、3 つとも終わりの知らせを待つ。途中で作業ツリーやログを覗いて判断しない＝変異の途中を拾うと偽の赤になる。それぞれ全件 19〜20 回で 35〜50 分）

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cic-mut-commit"); python3 "$SP/cic-mutate.py" --iso "$SP/cic-mut-$C-a" M01 M02 M03 M04 M05 M06 M07 M08 M09 M10 M11 M12 M13 M14 M15 M16 B01 B02 B03 B04 > "$SP/cic-mut-$C-a.log" 2>&1
```

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cic-mut-commit"); python3 "$SP/cic-mutate.py" --iso "$SP/cic-mut-$C-b" B05 B06 B07 B08 B09 B10 B11 B12 B13 B14 B15 B16 B17 B18 B19 B20 B21 B22 B23 > "$SP/cic-mut-$C-b.log" 2>&1
```

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cic-mut-commit"); python3 "$SP/cic-mutate.py" --iso "$SP/cic-mut-$C-c" B24 B25 B26 B27 V01 V02 V03 V04 V05 V06 V07 V08 V09 V10 V11 V12 V13 V14 V15 > "$SP/cic-mut-$C-c.log" 2>&1
```

- [ ] **Step 6: 期待と突き合わせる**（下の表。**落ちたテストの集合と理由の文言まで**。記録は `$SP/cic-mut-$C-{a,b,c}-results.jsonl`）
  - 表の「期待」は、試作で関係するテスト 8 本（`CustomerImportTest`・`BuyerCsvRowTest`・走査 2 本・`ImportPreviewRenderTest`・`ImportValidationFeedbackTest`・`JapaneseValidationMessagesTest`・`AlpineXShowDisplayConflictTest`）に絞って測った値。全件で流すと、M09・M15 は開いたままのトランザクションが後続の**すべての**テストへ連鎖する（`There is already an active transaction`。変異の性質）。それ以外の変異で、表に無いテストが落ちたら理由を調べる
  - 期待と違ったら（緑のまま・別のテストが落ちた・理由が違う）、理由を調べる。テストの穴ならテストを足してコミットし、その変異を当て直す。当て直す前に、隔離した worktree を新しいコミットへ進める（名前は控えの `C` のまま）:

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cic-mut-commit"); NEW=$(git -C /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm rev-parse HEAD); for s in a b c; do git -C "$SP/cic-mut-$C-$s" status --porcelain && git -C "$SP/cic-mut-$C-$s" checkout --detach "$NEW"; done
```

  - 1 つだけ当て直すときは `--targets` で流すテストを絞れる（例 `python3 "$SP/cic-mutate.py" --iso "$SP/cic-mut-$C-a" --targets tests/Feature/Admin/CustomerImportTest.php -- M13`）。表の記録は全件で取り直す

**コントローラ（`app/Http/Controllers/Admin/CustomerImportController.php`）:**

| ID | 変異 | 期待（落ちるテストと理由の 1 行目）|
|---|---|---|
| C0 | `showForm()` のビュー名を無いものに（カナリア）| 16 本: 確定を送る 15 本（`Failed asserting that '<!DOCTYPE html>…`＝着いた画面がエラーの画面）＋ `ImportValidationFeedbackTest`「顧客CSV」（差し戻し先に理由が出ていない）|
| M01 | 確定でも `csv_file` から読む（元の不具合）| 14 本: 確定を送るテストのうち部署を書き換える 1 本を除く全部（`Failed asserting that '<!DOCTYPE html>…`＝着いた画面は「CSVファイルは必須です。」）|
| M02 | 0 件の歯止めを消す（`if (false)`）| 2 本: `test_confirming_only_duplicates_without_the_check_imports_nothing`・`test_a_confirmation_with_no_importable_rows_is_turned_back` |
| M03 | 歯止めの文言の分岐を逆に | 同じ 2 本 |
| M04 | データが無いときの戻り先を `back()` に | 1 本: `ImportControllerReturnPathScanTest::test_import_controllers_never_send_the_user_back_to_the_referer`（取込のコントローラがリファラーか今の URL へ戻している…）。挙動のテストは `from(取込の画面)` で送るので同じ URL に戻って緑 |
| M05 | 書き込みの失敗の戻り先を `back()` に | 同じ走査の 1 本 |
| M06 | 完了の戻り先を `back()` に | 同じ走査の 1 本 |
| M07 | 部署の検査の try を外す | 1 本: `ImportControllerValidationRedirectScanTest::test_import_controllers_send_every_validation_failure_to_a_fixed_page`（取込のコントローラが入力チェックの例外を包んでいない…）|
| M08 | ファイルの検査の try を外す | 同じ走査の 1 本 |
| M09 | `DB::rollBack()` を消す | `test_a_failure_while_writing_rolls_back_every_row`（1 行目が巻き戻っていない）。続くテストは `PDOException: There is already an active transaction` で連鎖（全件では後続のすべて）|
| M10 | 重複候補を飛ばさない | 5 本: `…only_duplicates_hides_the_button…`（`Failed asserting that 1 is identical to 0.`）・`…only_duplicates_without_the_check…`・`…valid_rows_and_duplicates_show_the_button…`（`2 is identical to 1`）・`…import_count_follows…`（`two arrays are identical`）・`…without_the_check_only_the_valid_rows…` |
| M11 | 重複の確認を常に「無し」に（`whereRaw('1 = 0')`）| 7 本: M10 の 5 本 ＋ チェックを入れる 2 本（確定のフォームにチェックボックス include_duplicates が無い）|
| M12 | チェックを無視して常に飛ばす | 2 本: `…only_duplicates_with_the_check_imports_them`・`…with_the_check_the_duplicates_are_imported_too` |
| M13 | 部署に違う取得日（2000-01-01）を入れる | 2 本: `…imports_every_valid_row`・`…values_are_converted…`（`two strings are identical`）|
| M14 | アンケートの日付を取得日にしない（2000-01-01）| 1 本: `…survey_and_its_answers…`（`two strings are identical`）|
| M15 | 確定で行の検査を飛ばす | `…invalid_rows…`（確定の応答が転送になっていない＝取得日の読めない行が `addToDepartment()` に届いて `TypeError` で 500）。続くテストは `There is already an active transaction` で連鎖 |
| M16 | 確定で重複の確認を飛ばす | 2 本: `…only_duplicates_without_the_check…`・`…without_the_check_only_the_valid_rows…` |

**1 行の検査（`app/Support/BuyerCsvRow.php`）:** 表の「Unit」は `BuyerCsvRowTest`、「Feature」は `CustomerImportTest`

| ID | 変異 | 期待 |
|---|---|---|
| B01 | 日付の検査を `strtotime()` に戻す | 10 本: Feature 3（`…values_are_converted…`＝フォームが見つからない・`…invalid_rows…`・`…rewritten_csv_data…`）＋ Unit 7（`…every_column…`・日付の 4 ケース（`生年月日 2026-02-30`・`取得日 2026-02-30`・`birth_date 2026/9/1`・`acquired_date 2026/9/1`）・`…era_is_not_range_checked_against_a_birth_date_that_cannot_be_read`・`…every_error…`）|
| B02 | 変換前の生の値を保存 | 8 本: Feature `…values_are_converted…` ＋ Unit 7（`…every_column…`・日付の 2 ケース・`元号 s`・整数の 3 ケース（`大人人数 0` など））|
| B03 | 最初の誤りで検査を止める（`break`）| 1 本: Unit `…every_error_in_a_row…` |
| B04 | つなぎ方を最初の 1 件だけに | 1 本: Unit `…every_error_in_a_row…`（`two strings are identical`）|
| B05 | 昭和の最初の年を 1927 に | Unit 4 ケース: 昭和の前の年・昭和の最初の年・昭和の次の年・名前で書いた元号（文言の「1926〜1989年」も変わる）|
| B06 | 昭和の最後の年を 1988 に | Unit 4 ケース: 昭和の前の年・昭和の最後の年・昭和の次の年・名前で書いた元号 |
| B07 | 平成の最初の年を 1990 に | Unit 3 ケース: 平成の最初の年・平成の前の年・平成の次の年 |
| B08 | 平成の最後の年を 2020 に | Unit 2 ケース: 平成の前の年・平成の次の年 |
| B09 | 令和の最初の年を 2018 に | Unit 1 ケース: 令和の前の年 |
| B10 | 範囲の下限の比較を `<=` に | Unit 3 ケース: 昭和・平成・令和の最初の年 |
| B11 | 範囲の上限の比較を `>=` に | Unit 2 ケース: 昭和・平成の最後の年 |
| B12 | 令和に上限が無いことを忘れる | Unit 1 ケース: 令和の最初の年 |
| B13 | 元号の範囲を見ない | Unit 6 ケース: 誤りになるはずの 6 つ |
| B14 | 元号の全角・小文字を受けない | Unit 1 本: `…era_is_accepted…`（`元号 s`）|
| B15 | 元号の名前（昭和・平成・令和）を受けない | 4 本: Feature `…values_are_converted…` ＋ Unit `…every_column…`・`元号 昭和`・名前で書いた元号 |
| B16 | 全角の数字を半角にしない | 5 本: Feature `…values_are_converted…` ＋ Unit `…every_column…`・整数の 3 ケース（`大人人数 ２` など）|
| B17 | 整数の形を見ない | 5 本: Feature `…invalid_rows…` ＋ Unit 整数の誤りの 3 ケース（`大人人数 2人` など）・`…every_error…` |
| B18 | 整数の上限の比較を `>=` に | Unit 3 ケース: `大人人数 255`・`子供人数 255`・`勤続年数 65535` |
| B19 | 大人人数の上限を 256 に | 3 本: Feature `…invalid_rows…`（文言の「0〜255」）＋ Unit `大人人数 256`・`…every_error…` |
| B20 | 勤続年数の上限を 65534 に | Unit 2 ケース: `勤続年数 65535`・`勤続年数 65536`（文言）|
| B21 | 担当者名の上限を 101 に | Unit 1 ケース: 文字数の上限を超える「担当者名」|
| B22 | 文字数を `strlen()` で数える | Unit 29: 上限まで 14 ケース・上限を超える 14 ケース・`…every_error…` |
| B23 | 文字数の境目の比較を `>=` に | Unit 14: 上限まで の 14 ケース |
| B24 | 前後の空白を除かない | Unit 2 本: `…blank_cells…`・`…cells_are_trimmed` |
| B25 | buyers に入れない列（取得日・分譲地名・担当者名）を除かない | Unit 4 本: `…only_the_required_columns…`・`…blank_cells…`・`…every_column…`・`…project_name_is_not_checked`（`does not have the key 'project_name'`）|
| B26 | 取得日を必須にしない | Unit 1 本: `…three_required_columns…` |
| B27 | 分譲地名も 50 文字で検査する | Unit 1 本: `…project_name_is_not_checked` |

**プレビューの画面（`resources/views/admin/customers/import.blade.php`）:** すべて `CustomerImportTest`

| ID | 変異 | 期待 |
|---|---|---|
| V01 | 確定の欄を V > 0 のときだけ出す | 3 本: `…only_duplicates_hides_the_button…`（「インポート実行」ボタンが無い）・チェックを入れる／入れない確定の 2 本（フォームが見つからない）|
| V02 | 0 件でも確定の欄を出す | 1 本: `…no_importable_rows_offers_no_import` |
| V03 | ボタンを包む要素を最初から隠さない | 1 本: `…only_duplicates_hides_the_button…`（contains "display: none"）|
| V04 | 常に隠して描く | 1 本: `…valid_rows_and_duplicates_show_the_button…`（does not contain "display: none"）|
| V05 | ボタンを包む要素の `x-show` を外す | 2 本: ボタンを見る 2 本（contains "x-show="importCount() > 0""）|
| V06 | チェックボックスの `x-model` を外す | 1 本: `…only_duplicates_hides_the_button…`（contains "x-model="includeDupes""）⚠ 足す前のテストでは緑だった |
| V07 | 理由の `x-show` を外す | 1 本: `…only_duplicates_hides_the_button…` ⚠ 足す前のテストでは緑だった |
| V08 | 件数の JS をチェックに合わせない | 1 本: `…import_count_follows…`（`two arrays are identical`）⚠ 足す前のテストでは緑だった |
| V09 | 重複候補の数を Alpine に渡さない | 3 本: ボタンを見る 2 本・`…import_count_follows…` |
| V10 | 取り込める行の数を Alpine に渡さない | 2 本: `…valid_rows_and_duplicates_show_the_button…`・`…import_count_follows…` |
| V11 | ボタンの件数を `x-text` で入れ替えない | 2 本: ボタンを見る 2 本 |
| V12 | 確定の印（`confirmed`）を落とす | 15 本: 確定のフォームを分解するテスト全部（確定のフォームに confirmed が無い）|
| V13 | `csv_data` を落とす | 10 本: 画面の `csv_data` をそのまま送るテスト（`csv_data` を自分で入れる・消す 4 本と、部署を書き換える 1 本は緑）|
| V14 | `department` を落とす | 14 本: 部署を書き換える 1 本を除く確定のテスト全部 |
| V15 | `@csrf` を落とす | 15 本: 確定のフォームを分解するテスト全部（確定のフォームに @csrf が無い）|

- [ ] **Step 7: 片づけて記録をコミット**

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cic-mut-commit"); for s in a b c; do git -C /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm worktree remove --force "$SP/cic-mut-$C-$s"; done; git -C /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm worktree list
```

この計画の末尾の「実測記録」に、3 つの表の結果（ID・落ちたテストの数と名前・理由）と、期待と違ったもの・その対応を書いてコミットする:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && git add docs/superpowers/plans/2026-09-26-customer-import-confirm.md && git commit -m "$(cat <<'EOF'
docs: 顧客CSVインポートの確定の変異テストの結果を記録する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 7: ローカルの実ブラウザ確認

**Files:** なし（使い捨てのログイン用ルートは**コミットしない**。確認のあと必ず戻す）

使い捨ての SQLite ＋ `artisan serve` ＋ Playwright（画面が見えている状態。⚠ Alpine のタイミングは画面が見えているブラウザで測る）。
⚠ `preview_start` は使わない（main repo の launch.json を解決して実 MySQL に当たる）。
⚠ ブラウザでパスワードを入力しない。ログインは使い捨てのログイン用ルートで入る。
⚠ Playwright MCP が読めるファイルは `/Users/masanori/site/manage` の下だけ（scratchpad は拒否される）ので、CSV は `.playwright-mcp/`（gitignore 済み）に置き、確認のあと消す。

- [ ] **Step 1: 使い捨ての環境**（`SP` は scratchpad。環境変数は毎回 `source` する＝シェルの状態は次の呼び出しに残らない）

```bash
SP=<Scratchpad directory>; DB="$SP/cic-browser.sqlite"; rm -f "$DB"; : > "$DB"; cat > "$SP/cic-env.sh" <<EOF
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE="$DB" QUEUE_CONNECTION=sync MAIL_MAILER=log
EOF
cat "$SP/cic-env.sh" | sed 's/APP_KEY="[^"]*"/APP_KEY="…"/'
```

`$SP/seed-cic.php`（scratchpad に置く・コミットしない。**接続先が scratchpad の SQLite でなければ止まる**安全装置つき）:

```php
<?php
// 使い捨ての SQLite に顧客 CSV 取込の確認用のデータを入れる（scratchpad に置く・コミットしない）
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Buyer;
use App\Models\User;

$root = '/Users/masanori/site/manage/.claude/worktrees/customer-import-confirm';
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

// 重複候補の相手（確認用の CSV の「山田 太郎・愛媛県・松山市」と同じ）
Buyer::create(['last_name' => '山田', 'first_name' => '太郎', 'prefecture' => '愛媛県', 'city' => '松山市']);

echo "user id: {$user->id} / buyers: " . Buyer::count() . "\n";
```

```bash
SP=<Scratchpad directory>; cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && source "$SP/cic-env.sh" && php artisan migrate --force 2>&1 | tail -1 && php "$SP/seed-cic.php" && /Users/masanori/site/manage/node_modules/.bin/vite build 2>&1 | tail -2
```

期待: `user id: 1 / buyers: 1`（試作で確かめた）。`vite build` は worktree の `public/build`（gitignore 済み）に書く。

- [ ] **Step 2: 確認用の CSV**（BOM つき UTF-8。住宅のテンプレートと同じ見出し）

```bash
python3 - <<'PY'
import os
H = ['姓', '名', 'セイ', 'メイ', '生年月日', '元号', '大人人数', '子供人数', '郵便番号', '都道府県', '市区町村', '住所詳細',
     '建物名', '電話番号', 'メールアドレス', '職業', '勤務先', '勤続年数', '取得日', '来場分譲地名', '担当者名']
def person(last, first, extra=None):
    cells = {'姓': last, '名': first, '都道府県': '愛媛県', '市区町村': '松山市', '取得日': '2026-09-01'}
    cells.update(extra or {})
    return ','.join(cells.get(h, '') for h in H)
files = {
    'cic-mixed.csv': [person('山田', '太郎'), person('佐藤', '花子', {'生年月日': '1980/1/2', '元号': '昭和', '大人人数': '２'}),
                      person('鈴木', '一', {'取得日': '2026-02-30'})],
    'cic-dupes-only.csv': [person('山田', '太郎')],
    'cic-none.csv': [person('', '太郎')],
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
Route::get('/_dev/login-as/{id}', function (string $id) {
    abort_unless(app()->environment('local'), 404);
    \Illuminate\Support\Facades\Auth::loginUsingId((int) $id);
    return redirect('/admin/customers/import');
});
```

開発サーバを Bash の `run_in_background` で起動する:

```bash
SP=<Scratchpad directory>; cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && source "$SP/cic-env.sh" && php artisan serve --host=127.0.0.1 --port=8766
```

- [ ] **Step 4: 見ること**（Playwright で `http://127.0.0.1:8766/_dev/login-as/1` を開くと取込の画面に着く。ファイルは「ファイルを選択」の `<label>` を押して選ぶ。コンソールのエラーも毎画面見る）

| # | 操作 | 見ること |
|---|---|---|
| 1 | ログイン用ルート → 取込の画面 | 初期フォームが開く |
| 2 | 住宅事業のまま `cic-mixed.csv` を選び「アップロードしてプレビュー」| 全 3・正常 1・エラー 1・重複候補 1 ／「⚠ 行4: 取得日「2026-02-30」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）」／「🔄 行2: 重複の可能性 — 山田 太郎（愛媛県松山市）」／ボタン「インポート実行（1件）」が見える |
| 3 | 「重複候補もインポートする」を入れる → 外す | 入れると「インポート実行（2件）」、外すと「インポート実行（1件）」（下の JS で読む）|
| 4 | 外したまま「インポート実行（1件）」| 取込の画面に「1件のインポートが完了しました。」／ 下の `sqlite3` が 2 行（種の山田と、佐藤 花子 `1980-01-02`・`S`・`2`。SQLite では生年月日が `1980-01-02 00:00:00` と見える）|
| 5 | `cic-dupes-only.csv` をプレビュー | 正常 0・重複候補 1 ／ボタンが見えない・「重複候補だけです。取り込むときは、上のチェックを入れてください。」が見える |
| 6 | チェックを入れる → 外す | 入れるとボタン「インポート実行（1件）」が出て理由が消える。外すと元に戻る |
| 7 | 入れて「インポート実行（1件）」| 「1件のインポートが完了しました。」・`buyers` が 3 行（山田 太郎が 2 人）|
| 8 | `cic-none.csv` をプレビュー | エラー 1・「⚠ 行2: 姓が未入力です」・ボタンもチェックも無く、赤字で「インポート可能なデータがありません。CSVを修正してください。」|
| 9 | 2 と 5 のプレビューを 1440px と 375px で | `main.scrollWidth === main.clientWidth`（Bug #29）|
| 10 | ここまでの全部の画面 | コンソールのエラー 0 件 |

4・7 で DB を見るコマンド:

```bash
SP=<Scratchpad directory>; sqlite3 "$SP/cic-browser.sqlite" "select id, last_name, first_name, birth_date, birth_era, family_adults from buyers order by id"
```

3・5・6 で読む JS（`browser_evaluate`）:

```js
() => {
  const button = [...document.querySelectorAll('button')].find(b => b.textContent.includes('インポート実行（'));
  const reason = [...document.querySelectorAll('div')].find(d => d.textContent.trim() === '重複候補だけです。取り込むときは、上のチェックを入れてください。');
  const main = document.querySelector('main');
  return {
    button: button ? { visible: button.offsetParent !== null, text: button.textContent.replace(/\s+/g, '') } : null,
    reason: reason ? reason.offsetParent !== null : null,
    main: [main.scrollWidth, main.clientWidth],
  };
}
```

- [ ] **Step 5: 片付け**

```bash
SP=<Scratchpad directory>; cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && git checkout -- routes/web.php && rm -rf public/build && rm -f "$SP/cic-browser.sqlite" /Users/masanori/site/manage/.playwright-mcp/cic-mixed.csv /Users/masanori/site/manage/.playwright-mcp/cic-dupes-only.csv /Users/masanori/site/manage/.playwright-mcp/cic-none.csv && git status --porcelain
```

開発サーバは起動したバックグラウンドの作業を止める（`TaskStop`）。`status` は何も出さないこと。結果を「実測記録」に書く（Task 9 でコミット）。

---

## Task 8: 独立レビュー

**Files:** 指摘に応じて

- [ ] **Step 1: レビューを頼む**（Agent・`general-purpose`。コードは変えさせない。指摘には再現の手順を付けさせる）

頼む内容（そのまま渡す）:

> 顧客CSVインポートの確定を通す改修を、欠陥を見つける目でレビューしてください（説明ではなく欠陥の指摘がほしい）。
> 対象: worktree `/Users/masanori/site/manage/.claude/worktrees/customer-import-confirm` の `git diff 13.x...HEAD`（アプリとテスト）。
> 設計書 `docs/superpowers/specs/2026-09-25-customer-import-confirm-design.md` と計画 `docs/superpowers/plans/2026-09-26-customer-import-confirm.md` を読んでから見てください。
> 見てほしいこと: ①設計書との食い違い ②確定で信用してはいけない値（`csv_data`・`department`・`include_duplicates`）の扱い ③本番の MySQL（strict）でだけ落ちる値が残っていないか ④画面の出し分け（Alpine の `x-show`・`x-model`・最初に描く状態）と CLAUDE.md の Top trap #4・#5・#12・#14 ⑤テストが緑のまま壊せる形が残っていないか（docs/RULES.md Bug #43・#46・#47・#49・#54・#64 の型） ⑥文書の誤り。
> 決まり: コードは変えない（探りは scratchpad のコピーか、テストを一時的に足して流し、元に戻す）。`.env` は読まない。全件テストは `cd <worktree> && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`。
> 報告: 指摘ごとに 重さ（Critical / Important / Minor）・場所・再現の手順（落ちるテストか、実行した探りとその出力）・直し方の案。推測だけの指摘は「未実測」と書いてください。

- [ ] **Step 2: 指摘を 1 つずつ実測してから直す**（実測で再現しないものは理由を書いて見送る）
  - 直すたびに、落ちるテストを先に足し（直す前に赤・直した後に緑を確かめる）、関係するテストを流してコミットする
  - 本番のコードを変えたら、その場所に当たる変異を `cic-mutate.py` で当て直す（隔離した worktree を作り直すか、Task 6 の手順で進める）
  - 最後に全件テストとコンパイル済みビューの lint（Task 5）をやり直す
  - 指摘・実測・対応を「実測記録」に書く

---

## Task 9: 記録

**Files:**
- Modify: `docs/RULES.md`（Bug #66 を足す・Bug #64 に書き足す）
- Modify: `CLAUDE.md`（Bug の件数）
- Modify: `docs/BACKLOG.md`（この作業の節・既存の 2 か所に矢印・完了状況）
- Modify: この計画（実測記録）

数字（本数・変異・ブラウザ）は Task 5〜8 の実測に合わせる。下の文は試作の実測で書いてあるので、違えば直す。

- [ ] **Step 1: `docs/RULES.md` に Bug #66 を足す**（Edit。表の最後の行（#65）の後ろ）

置き換える前:

```markdown
本番反映は `./deploy.sh` |

## Postal Code APIs
```

置き換えた後:

```markdown
本番反映は `./deploy.sh` |
| 66 | 顧客CSVインポート（`/admin/customers/import`・経営層のみ）の「インポート実行」（確定）を押すと、取込の画面に戻って「CSVファイルは必須です。」が出るだけで**1 件も入らない**。プレビューまでは正しく動くので、確定を押すまで気づけない。**最初のコミット 2046289d（2026-04-08）からずっとこの状態**だった（2026-09-25 に発見、2026-09-26 修正） | 確定のフォームが送るのは hidden の `csv_data`（プレビューが読んだ CSV の base64）なのに、コントローラが `csv_file` を**常に**必須にし、ファイルからしか読まなかった（`csv_data` を読む箇所が 0 件）。確定まで送るテストが 0 本で（`ImportPreviewRenderTest` はプレビューの描画、`ImportValidationFeedbackTest` は不正なファイルしか見ない）、原理的に見えなかった。⚠ **運び方だけ直すと、一度も動いたことのない書き込みが本番で動き出す**（使い捨てのテストで測った）: 生年月日 `19800102` は date キャストが Unix 時刻として読んで 1970-08-18 に、取得日 `2026-02-30` は 3/2 に繰り上がり（Bug #54 と同じ `strtotime()` の型）、`昭和55年1月2日` の 1 行で**全行が巻き戻り**、`2人` は本番の MySQL（strict）で全行を巻き戻す可能性が高く、元号「昭和」は詳細画面で「昭和.1980年」と崩れ、取り込める行が 0 件でも「インポート実行（0件）」が出て「0件のインポートが完了しました。」に着いた | **確定は `csv_data` から読み直し、部署・行の検査・重複の確認を最初からやり直す**（テナント・ZEAL 会員の取込と同じ `loadCsv()`。ブラウザから届いた値は信用しない）。1 行の検査と変換は `App\Support\BuyerCsvRow`（DB に触らない値のオブジェクト）: 日付は `CsvDate::normalize()`（`checkdate()`）・元号は `S`・`H`・`R` にそろえて生年月日の年と突き合わせる（詳細画面の計算と同じ年の差）・人数と勤続年数は本番の列の範囲の整数・文字数は本番の列の大きさ・1 行の誤りは全部集めて列の順に「／」でつなぐ。0 件の確定はサーバが断り、画面は確定の欄を V（取り込める行）と D（重複候補）で出し分ける（ボタンの件数はチェックに合わせて変わる。押せないボタンは `disabled` にせず隠す＝Top trap #12・最初の状態はサーバが描く）。戻り先は取込の画面に固定し、走査テスト 2 本の例外リストから顧客を外した（Bug #64）。⚠ **設計書の場面だけでテストを書くと、Alpine の配線（チェックの `x-model`・理由の `x-show`）と件数の JS を壊しても全部緑だった**（試作の先測りで 3 通りとも実測）→ 構造のアサートと、画面が描いた `<script>` を node の vm で動かすテストを足した（Bug #47 の「振る舞いの正本は実駆動」）。⚠ 書き込みの途中の PHP の `Error`（型の誤りなど）は、ほかの取込と同じ `catch (\Exception $e)` を素通りして 500 になる（書き込みに届く行は必ず検査を通っているので、通常の操作では起きない）。変異で踏むと、開いたままのトランザクションが後続のテストへ連鎖する。回帰テスト: `tests/Unit/Support/BuyerCsvRowTest.php`（61 本。直す前の取込を素通りした値を誤りとして固定）／ `tests/Feature/Admin/CustomerImportTest.php`（20 本。プレビューが描いた確定のフォームを分解してそのまま送り返す往復・`from(取込の画面)` で送って着いた画面で文言を見る・書き込みの失敗は 2 行目で起こす）。変異 58 通り＋カナリアを全件で当て、すべて期待どおり（計画書 `docs/superpowers/plans/2026-09-26-customer-import-confirm.md` の実測記録）。本番反映は main repo の cwd で `composer dump-autoload`（新しいクラス）→ `./deploy.sh`（DB 変更なし） |

## Postal Code APIs
```

- [ ] **Step 2: `docs/RULES.md` の Bug #64 に書き足す**（Edit を 2 回。どちらも `docs/RULES.md` にちょうど 1 か所ずつある。2026-09-26 に数えた）

1 回目 — 置き換える前:

```text
は実測 200 で直していない。
```

置き換えた後:

```text
は実測 200 で直していない（顧客は 2026-09-26 に確定を通したとき、戻り先を取込の画面に固定して 2 本の走査の例外リストから外した。Bug #66）。
```

2 回目 — 置き換える前:

```text
（包んでいないのを許すのは顧客・周辺ビル・経営試算表の URL 保存 `updateUrls` の 3 か所だけ・件数と理由つき。
```

置き換えた後:

```text
（包んでいないのを許すのは顧客・周辺ビル・経営試算表の URL 保存 `updateUrls` の 3 か所だけ・件数と理由つき。2026-09-26 から顧客を除く 2 か所。
```

- [ ] **Step 3: `CLAUDE.md` の件数**（Edit を 2 回）

```text
全 65 件の詳細バグカタログ   →   全 66 件の詳細バグカタログ
Bug #1–65                    →   Bug #1–66
```

- [ ] **Step 4: `docs/BACKLOG.md`**（Edit を 4 回）

1 回目（405 の件の表の顧客の行）— 置き換える前:

```text
| 顧客 | `/admin/customers/import`（GET と同じ URL）| —（実測 200）| 直さない |
```

置き換えた後:

```text
| 顧客 | `/admin/customers/import`（GET と同じ URL）| —（実測 200）| 直さない（→ 2026-09-26 に戻り先を取込の画面に固定した。下の「顧客CSVインポートの確定を通す」の節）|
```

2 回目（走査の件の範囲外）— 置き換える前:

```text
／包んでいない 3 か所を包み直すこと
```

置き換えた後:

```text
／包んでいない 3 か所を包み直すこと（→ 顧客の 1 か所は 2026-09-26 に包み直した。下の「顧客CSVインポートの確定を通す」の節）
```

3 回目（「本番の部品の名簿をデプロイのたびに作り直す」の節の後ろに、この作業の節を足す）— 置き換える前:

```markdown
---

## バックログ完了状況
```

置き換えた後（文案の「（未記入）」は Task 5〜8 の実測で置き換える）:

```markdown
---

## ✅ 顧客CSVインポートの確定を通す — 本番反映待ち

詳細仕様: @docs/superpowers/specs/2026-09-25-customer-import-confirm-design.md
実装計画（試作・変異テスト・ブラウザ確認の記録つき）: @docs/superpowers/plans/2026-09-26-customer-import-confirm.md

顧客CSVインポート（サイドバーの「顧客CSVインポート」、`/admin/customers/import`、経営層のみ）の「インポート実行」（確定）は、
最初のコミット 2046289d（2026-04-08）から一度も通っていなかった（docs/RULES.md Bug #66）。確定のフォームが送るのは
`csv_data`（base64）なのに、コントローラが `csv_file` を常に必須にしていた。運び方だけ直すと、一度も動いたことのない
書き込みの問題（黙った誤保存・1 行の誤りで全行の巻き戻し）が本番で動き出すので、利用者の判断（2026-09-25・案 1）で
6 つをまとめて直した。**DB 変更・ルート変更・新規依存は無し。新規 PHP クラスは `App\Support\BuyerCsvRow` の 1 本。**

| 区分 | 実装内容 |
|------|---------|
| Support | `App\Support\BuyerCsvRow`（1 行の検査と変換。DB に触らない。`HacomonoMemberMapper` → `MappedMember` の形）|
| Controller | `Admin\CustomerImportController` — 確定は `csv_data` から読み直す（`loadCsv()`）・戻り先を取込の画面に固定・1 行の検査を `BuyerCsvRow` へ・0 件の確定を断る |
| Blade | `admin/customers/import.blade.php` の確定の欄（0 件は確定のフォームを出さない・重複候補だけならチェックを入れるまでボタンを隠す・ボタンの件数はチェックに合わせて変わる）|
| テスト | `tests/Unit/Support/BuyerCsvRowTest.php`（61 本）／ `tests/Feature/Admin/CustomerImportTest.php`（20 本）／ テスト用スキーマに `buyer_survey_answers` ／ 走査テスト 2 本の例外リストから顧客を外した。2263 → **2344 tests / 15190 assertions green** |
| ルート / DB | **どちらも変更なし** |

### 直したこと（設計書の候補 1〜6）

| # | 直す前 | 直した後 |
|---|---|---|
| 1 確定の運び方 | 押すと「CSVファイルは必須です。」で戻り、1 件も入らない | `csv_data` から読み直し、部署・行の検査・重複の確認を最初からやり直す |
| 2 取得日 | `2026-02-30` が 3/2 に繰り上がって入る・`2026/9/1` と `1970-01-01` は理由なく誤り | `CsvDate::normalize()`（`checkdate()`）。`2026/9/1`・`1970-01-01` は通る |
| 3 生年月日 | `19800102` が 1970-08-18 に・`昭和55年1月2日` の 1 行で全行が巻き戻る | 取得日と同じ検査。読めなければその行だけ誤り |
| 4 元号 | 書かれた文字のまま入り、「昭和」は詳細画面で「昭和.1980年」と崩れる | `S`・`H`・`R`（全角・小文字・「昭和」なども可）にそろえ、生年月日の年と合わなければ誤り |
| 5 人数・勤続年数・文字数 | `2人` は本番の MySQL（strict）で全行を巻き戻す可能性が高い | 本番の列の範囲の整数（全角の数字も可）・本番の列の大きさの文字数 |
| 6 0 件のときの確定 | 「インポート実行（0件）」が出て「0件のインポートが完了しました。」に着く | 確定のフォームを出さず理由を出す。サーバも 0 件の確定を断る |

### 要点

- 1 行の誤りはすべて集め、CSV の列の順に「／」でつないで 1 件のエラーとして出す（直して上げ直すたびに次の誤りが出る、を避ける）。件数は今までどおり行の数（全 N 件＝正常＋エラー＋重複候補）
- 確定でもブラウザから届いた値を信用しない（`csv_data` と `department` を書き換えても、プレビューで通らないものは入らない）
- 押せないボタンは `disabled` にせず隠す（Top trap #12）。最初の状態はサーバが描く（V＝0 ならボタンを包む要素を `display: none` で描く）ので、開いた直後に一瞬出て消えない
- ⚠ テストの穴: 設計書の場面だけで書くと、チェックの `x-model`・理由の `x-show`・件数の JS を壊しても全部緑だった（試作の先測りで実測）→ 構造のアサートと、画面の `<script>` を node の vm で動かすテストを足した
- ⚠ 部署の検査をファイルの検査より先に行うので、両方が誤りなら部署の誤りだけが出る（直す前は 2 つとも出ていた）

### 範囲外（気づいたが直していない。設計書 §6 ＋ 試作で気づいたもの）

- CSV の中の同じ人の 2 行（両方とも登録される）
- 重複の確認で、都道府県・市区町村が空欄の行は既存の顧客と照合されない可能性が高い（空欄は NULL で保存されるのに、確認は空文字と比べる。コードからの推測で未実測）
- 複数選択の回答の保存形式（画面は JSON の配列・取込は `A,B`）
- 分譲地名（前方一致）・担当者名（部分一致）のあいまいな照合
- 設問の列（Q1:…）が並び順で設問と結びつくこと
- 同じ設問番号の列が 2 つある CSV（`Q1:…` を 2 列など）は、2 列とも同じ設問の回答になり、本番では回答の一意制約（`uq_survey_answer`）に当たって取込全体が巻き戻る可能性が高い（テンプレートどおりなら起きない。コードと本番の定義からの推測で未実測。Task 1 で気づいた）
- 引用符の中の改行（行を `explode("\n")` で分けるので壊れる）
- テンプレートに記入例が無いこと
- 断られると確認画面の内容が消えて取込の画面に戻ること（今と同じ）
- 確定で例外が出たときの文言が英語のまま（`$e->getMessage()`）
- 取込のコントローラ（顧客・テナント・賃貸マンションなど）の `catch (\Exception $e)` は PHP の `Error`（型の誤りなど）を受け止めず、500 になってトランザクションが開いたまま残る（本番では接続が閉じて巻き戻る。書き込みに届く値を検査で守っているので通常の操作では起きない）

### 検証

- 全件テスト 2263 → **2344 tests / 15190 assertions green** ／ コンパイル済みビュー **274 本 / INVALID 0 件**
- 変異 58 通り＋カナリア（計画の Task 6）: （未記入）
- ローカルの実ブラウザ（計画の Task 7）: （未記入）
- 独立レビュー（計画の Task 8）: （未記入）

⚠ `origin/13.x` への push はしていない。

---

## バックログ完了状況
```

4 回目（「バックログ完了状況」の末尾の段落）— 置き換える前:

```text
その他の新規要件は別途追記する。
```

置き換えた後（Task 10 のあとで「本番反映済み（`13.x` = …）」に直す）:

```text
**顧客CSVインポートの確定を通す（2026-09-26）**は本番反映待ち（上の節）。

その他の新規要件は別途追記する。
```

- [ ] **Step 5: この計画の「実測記録」を埋める**（Task 1・5・6・7・8 の結果。Task 10 の小節は Task 10 で埋める）

- [ ] **Step 6: 書き残しが無いことを確かめる**（文案と実測記録の「（未記入）」を、実測で置き換えたか）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && grep -n -e '（未記入）' docs/RULES.md CLAUDE.md docs/BACKLOG.md; sed -n '/^## 実測記録/,$p' docs/superpowers/plans/2026-09-26-customer-import-confirm.md | grep -c -e '（未記入）'
```

期待: 1 つ目の `grep` は何も出さない。2 つ目は Task 10 の小節の 1 か所だけ（`1`）。Task 10 の後は `0`。

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && git add docs/RULES.md CLAUDE.md docs/BACKLOG.md docs/superpowers/plans/2026-09-26-customer-import-confirm.md && git commit -m "$(cat <<'EOF'
docs: 顧客CSVインポートの確定を通した記録を残す

RULES に Bug #66（確定が最初のコミットから通っていなかったこと・潜んでいた書き込みの
問題・テストの穴）を足し、Bug #64 の例外リストの記述に顧客を外したことを書き足す。
BACKLOG にこの作業の節と範囲外を足す。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 10: 本番反映（⚠ 承認をもらってから）

**Files:** なし（`13.x` の早送り・本番への反映・読み取りの確認）

⚠ 実行の前に、利用者の承認を本文でもらう（①main repo で `13.x` を早送り ②main repo の cwd で `composer dump-autoload --no-dev --optimize`（新しいクラス `App\Support\BuyerCsvRow` があるため）③`./deploy.sh` ④読み取りだけの確認。DB 変更・ルート変更・依存の変更は無い。push はしない）。

- [ ] **Step 1: 早送り**（main repo で）

```bash
cd /Users/masanori/site/manage && git status --porcelain && git checkout 13.x && git merge-base --is-ancestor 13.x customer-import-confirm && git merge --ff-only customer-import-confirm && git log --oneline -1
```

⚠ `is-ancestor` が失敗したら（`13.x` が進んだ）止める。worktree で `13.x` をマージし（Task 0 の手順）、全件テスト（Task 5）を流してからやり直す。取り込むコミットに本番へまだ出ていない別の作業が含まれるときは、それも一緒に出てよいか利用者に確かめる（`deploy.sh` は `13.x` の全体を送る）。

- [ ] **Step 2: 本番へ送る vendor に dev の部品が無いこと・新しいクラスの autoload**（main repo の cwd で。⚠ worktree から実行すると autoloader に worktree のパスが焼き込まれる）

```bash
cd /Users/masanori/site/manage && ls vendor/bin/phpunit 2>/dev/null; composer dump-autoload --no-dev --optimize 2>&1 | tail -1 && grep -c 'App\\\\Support\\\\BuyerCsvRow' vendor/composer/autoload_classmap.php && grep -n 'baseDir = ' vendor/composer/autoload_classmap.php
```

期待: `ls` は何も出さない（dev の部品が無い）・`Generated optimized autoload files …`・`1`・`$baseDir = dirname($vendorDir);`

- [ ] **Step 3: 反映**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

期待: exit 0・6 段すべて・3 つのキャッシュとも成功。送るアプリのファイルは `app/Support/BuyerCsvRow.php`・コントローラ・ビューの 3 本（ほかに `vendor/composer` の autoload の数ファイル）。

- [ ] **Step 4: 読み取りだけの確認**

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && md5 -q app/Support/BuyerCsvRow.php app/Http/Controllers/Admin/CustomerImportController.php resources/views/admin/customers/import.blade.php && /usr/local/php/8.3/bin/php artisan tinker --execute='echo class_exists(App\Support\BuyerCsvRow::class) ? "class OK" : "class NG", "\n";' && n=0; bad=0; for f in storage/framework/views/*.php; do n=$((n+1)); /usr/local/php/8.3/bin/php -l "$f" >/dev/null 2>&1 || { bad=$((bad+1)); echo "INVALID: $f"; }; done; echo "views=$n invalid=$bad"
SH
cd /Users/masanori/site/manage && md5 -q app/Support/BuyerCsvRow.php app/Http/Controllers/Admin/CustomerImportController.php resources/views/admin/customers/import.blade.php
```

期待: 本番と手元の md5 が 3 つとも一致・`class OK`・`views=274 invalid=0`

- ログイン済みの実 Chrome（`claude-in-chrome`・読み取りだけ。ファイルは上げない・フォームは送らない）で `https://www.mitsuwat.co.jp/system/manage/index.php/admin/customers/import` を開き、初期フォームが出ること・コンソールのエラー 0 件を見る（⚠ URL は `/index.php/` を挟む）。本番で実際に取り込むのは利用者が行う

- [ ] **Step 5: 記録**（worktree で BACKLOG の節の見出しを「本番反映済み」にし、本番反映の小節（日時・`13.x` のコミット・Step 2〜4 の結果）を足し、完了状況の段落を直してコミットする。そのあと main repo で `git merge --ff-only customer-import-confirm`（文書だけなので `deploy.sh` は要らない。`docs/` は rsync しない））

```bash
cd /Users/masanori/site/manage/.claude/worktrees/customer-import-confirm && git add docs/BACKLOG.md && git commit -m "$(cat <<'EOF'
docs: 顧客CSVインポートの確定を本番に出した記録を残す

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain && cd /Users/masanori/site/manage && git merge --ff-only customer-import-confirm && git log --oneline -1
```

⚠ push はしない（利用者の指示があったときだけ）。

---

## 範囲外（BACKLOG に書く。設計書 §6 ＋ 試作で気づいたもの）

- CSV の中の同じ人の 2 行（両方とも登録される）
- 重複の確認で、都道府県・市区町村が空欄の行は既存の顧客と照合されない可能性が高い（空欄は NULL で保存されるのに、確認は空文字と比べる。コードからの推測で未実測）
- 複数選択の回答の保存形式（画面は JSON の配列・取込は `A,B`）
- 分譲地名（前方一致）・担当者名（部分一致）のあいまいな照合
- 設問の列（Q1:…）が並び順で設問と結びつくこと
- 同じ設問番号の列が 2 つある CSV（`Q1:…` を 2 列など）は、2 列とも同じ設問の回答になり、本番では回答の一意制約（`uq_survey_answer`）に当たって取込全体が巻き戻る可能性が高い（テンプレートどおりなら起きない。コードと本番の定義からの推測で未実測。Task 1 で気づいた）
- 引用符の中の改行（行を `explode("\n")` で分けるので壊れる）
- テンプレートに記入例が無いこと
- 断られると確認画面の内容が消えて取込の画面に戻ること（今と同じ）
- 確定で例外が出たときの文言が英語のまま（`$e->getMessage()`）
- 取込のコントローラ（顧客・テナント・賃貸マンションなど）の `catch (\Exception $e)` は PHP の `Error`（型の誤りなど）を受け止めず、500 になってトランザクションが開いたまま残る（本番では接続が閉じて巻き戻る。書き込みに届く値を検査で守っているので通常の操作では起きない。試作の変異 M15 で気づいた）

## 完了の条件

- 全件テストが緑（`OK (2344 tests, 15190 assertions)` 前後。違えば理由を記録）・コンパイル済みビュー INVALID 0 件
- 変異 58 通り＋カナリアがすべて期待どおり（違ったものは理由を調べて対応し、記録した）
- ローカルの実ブラウザの 10 項目がすべて期待どおり
- 独立レビューの指摘に、実測のうえで対応した
- RULES・CLAUDE.md・BACKLOG・この計画に記録した
- （承認のあと）本番に反映し、読み取りで確かめた

---

## 実測記録

### 試作の先測り（2026-09-26。この計画を書くときに測った）

- 試作: `git archive 698d74c2` のコピー（vendor は `cp -Rc`）に、この計画のコード（Task 2〜4）を入れたもの
- 全件テスト **OK (2344 tests, 15190 assertions)**・コンパイル済みビュー **274 本 / INVALID 0 件**
- 変異 58 通りとカナリアを、関係するテスト 8 本に絞って 3 つのコピーで同時に当てた（`--targets`）: **59 通りすべて赤**。結果は Task 6 の表の「期待」の列
- 足す前のテスト（設計書 §5.1 の場面だけ・19 本）では V06・V07・V08 が 3 通りとも「落ちたテスト 0 件」だった（Task 4 のテストで足した構造のアサート 2 つと node のテスト 1 本で塞いだ）
- M13 は最初「取得日の引数を落とす」形で当てたら `ArgumentCountError` で 500 になり、開いたままのトランザクションが後続のテストへ連鎖した。「違う取得日を入れる」形に変えて、落ちるテストが 2 本に絞れることを確かめた

### Task 0: 前提の確認（2026-09-26）

- `13.x` の `a162957b`（文書だけ: 決裁 段階2a の計画・BACKLOG の 3 行）をマージした（`f566d818`）。アプリのコードとテストは変わらない
- 全件テスト **OK (2263 tests, 14765 assertions)**（期待どおり）・node v24.11.1

### Task 1: 本番の列の定義

2026-09-26 に利用者の承認のうえ `SHOW CREATE TABLE` で読み取った（書き込みなし）。4 表とも InnoDB・`utf8mb4_unicode_ci`。

| 表 | 本番の定義の要点 | 設計書 §2.6・この計画との違い |
|---|---|---|
| buyers | 姓・名 `varchar(50) NOT NULL` ／ セイ・メイ 50・元号 10・郵便番号 10・都道府県 10・市区町村 50・住所詳細 255・建物名 255・電話番号 20・メール 255・職業 50・勤務先 100（どれも NULL 可）／ 大人人数・子供人数 `tinyint unsigned`・勤続年数 `smallint unsigned`・生年月日 `date`（NULL 可）／ `memo text`・`deleted_at` ／ 索引 `idx_buyers_name_pref_city (last_name, first_name, prefecture, city)`（一意でない）| 無し（§2.6 どおり）。一意でない索引はテスト用スキーマに無いが、振る舞いに関わらない |
| buyer_departments | `department enum('housing','realestate') NOT NULL` ／ `acquired_date date NOT NULL` ／ `rank enum(…) NOT NULL DEFAULT 'C'` ／ `created_at` だけ（`updated_at` 無し）／ `UNIQUE uq_buyer_department (buyer_id, department)` ／ FK buyer_id → buyers（CASCADE）| 無し（テスト用スキーマの注記どおり）|
| buyer_surveys | `department enum NOT NULL` ／ `survey_date date NOT NULL` ／ `project_id`・`staff_user_id` `bigint unsigned` NULL 可 ／ **`staff_name varchar(100)`** NULL 可 ／ `memo text` ／ FK 3 本（buyers CASCADE・re_projects SET NULL・users SET NULL）| **`staff_name` は 100**（§2.6 とテスト用スキーマは 50）→ 直した（下）。`staff_user_id` はテスト用スキーマが `int unsigned` だが、SQLite ではどちらも INTEGER で振る舞いが同じなので変えない |
| buyer_survey_answers | `survey_id`・`question_id` `bigint unsigned NOT NULL` ／ `answer_value text` NULL 可 ／ **`question_snapshot json NOT NULL`** ／ `created_at`・`updated_at`（CURRENT_TIMESTAMP 既定値）／ **`UNIQUE uq_survey_answer (survey_id, question_id)`** ／ FK 2 本（survey_questions CASCADE・buyer_surveys CASCADE）| **`question_snapshot` は json で NULL 不可**（計画は text・NULL 可）／ **一意制約がある**（計画に無かった）→ 直した（下）|

直したもの（Task 1 Step 3。「設計書との違い」の 9〜11）:

- `buyer_surveys.staff_name` の上限 50 → 100: Task 2 のテスト用スキーマ・Task 3 の `MAX_LENGTH` と Unit テストの `textColumns()`・Task 6 の変異 B21（100 → 101）。テストの本数とアサートの数は変わらない見込み（データプロバイダの値と、文言の数字が変わるだけ）。Task 3・5 で測って確かめる
- Task 2 の `buyer_survey_answers`: `question_snapshot` を `$t->json('question_snapshot')`（NULL 不可）にし、`UNIQUE(survey_id, question_id)` を足した。⚠ Step 3 の例は `->nullable()` を付けていたが、本番は NULL 不可なので付けない。FK は trait の方針どおり張らない
- 範囲外に 1 つ足した: 同じ設問番号の列が 2 つある CSV は、本番では一意制約に当たって取込全体が巻き戻る可能性が高い（`$questionMap` は見出しの位置ごとに設問を引くので、`Q1:` の列が 2 つあると同じ設問の回答を 2 つ書く。テンプレートどおりなら起きない。未実測）

### Task 5: 全件テストと lint

コミット `5ca383ae`（Task 4 完了時点）で確認（コントローラが実行。Ruling 1）。

- 全件テスト **OK (2344 tests, 15190 assertions)**
- `php artisan view:cache` → `Blade templates cached successfully.` → **274 本 / INVALID 0 件** → `view:clear`
- `git status --porcelain` 空（作業ツリー清浄）

### Task 6: 変異テスト

対象コミット `5ca383ae`。隔離した worktree 3 つ（`cic-mut-5ca383ae-{a,b,c}`）で計測。

カナリア（`showForm()` のビュー名を無いものにする）は 3 コピーとも同一の **16 本**が落ちた（`CustomerImportTest` 15 本＋`ImportValidationFeedbackTest` の「顧客CSV」ケース 1 本。いずれも差し戻し先にエラーの画面が出ている型の失敗）＝ 隔離が効いている証拠。

58 通りの変異（コントローラ 16・`BuyerCsvRow` 27・プレビューの画面 15）＋カナリアの計 **59 通りすべてが期待どおり（MATCH）**。落ちたテストの集合（データセット名まで）と理由の 1 行目が、計画（Task 6 のブリーフ）の期待表とすべて一致し、GREEN（検出漏れ）は 0 件。

**コントローラ（`app/Http/Controllers/Admin/CustomerImportController.php`）**

| ID | 変異 | 落ちたテスト・理由 | 判定 |
|---|---|---|---|
| M01 | 確定でも `csv_file` から読む（元の不具合） | 14 本（C0 の 15 本から部署書き換えテストを除く全部）。エラーの画面に着く | MATCH |
| M02 | 0 件の歯止めを消す | 2 本（`confirming_only_duplicates_without_the_check_imports_nothing` / `a_confirmation_with_no_importable_rows_is_turned_back`）。エラーの画面 | MATCH |
| M03 | 歯止めの文言の分岐を逆に | M02 と同一の 2 本 | MATCH |
| M04 | データが無いときの戻り先を `back()` に | 1 本（`ImportControllerReturnPathScanTest`：戻り先がリファラー／今の URL） | MATCH |
| M05 | 書き込みの失敗の戻り先を `back()` に | 同上 1 本 | MATCH |
| M06 | 完了の戻り先を `back()` に | 同上 1 本 | MATCH |
| M07 | 部署の検査の try を外す | 1 本（`ImportControllerValidationRedirectScanTest`：入力チェックの例外を包んでいない） | MATCH |
| M08 | ファイルの検査の try を外す | 同上 1 本 | MATCH |
| M09 | `DB::rollBack()` を消す | 主因 1 本（`test_a_failure_while_writing_rolls_back_every_row`：1 行目が巻き戻っていない）＋連鎖 1555 件（`There is already an active transaction`） | MATCH |
| M10 | 重複候補を飛ばさない | 5 本（件数・配列の不一致） | MATCH |
| M11 | 重複の確認を常に「無し」に | 7 本（M10 の 5 本＋チェックボックスが無い 2 本） | MATCH |
| M12 | チェックを無視して常に飛ばす | 2 本。エラーの画面 | MATCH |
| M13 | 部署に違う取得日（2000-01-01）を入れる | 2 本。文字列不一致 | MATCH |
| M14 | アンケートの日付を取得日にしない | 1 本。文字列不一致 | MATCH |
| M15 | 確定で行の検査を飛ばす | 主因 1 本（`test_invalid_rows_are_reported_in_the_preview_and_left_out_of_the_import`：確定の応答が転送になっていない）＋連鎖 1570 件 | MATCH |
| M16 | 確定で重複の確認を飛ばす | 2 本。エラーの画面 | MATCH |

**1 行の検査（`app/Support/BuyerCsvRow.php`）**

| ID | 変異 | 落ちたテスト | 判定 |
|---|---|---|---|
| B01 | 日付の検査を `strtotime()` に戻す | 10 本（Unit 7・Feature 3） | MATCH |
| B02 | 変換前の生の値を保存 | 8 本 | MATCH |
| B03 | 最初の誤りで検査を止める | 1 本 | MATCH |
| B04 | つなぎ方を最初の 1 件だけに | 1 本 | MATCH |
| B05 | 昭和の最初の年を 1927 に | Unit 4 ケース | MATCH |
| B06 | 昭和の最後の年を 1988 に | Unit 4 ケース | MATCH |
| B07 | 平成の最初の年を 1990 に | Unit 3 ケース | MATCH |
| B08 | 平成の最後の年を 2020 に | Unit 2 ケース | MATCH |
| B09 | 令和の最初の年を 2018 に | Unit 1 ケース | MATCH |
| B10 | 範囲下限の比較を `<=` に | Unit 3 ケース | MATCH |
| B11 | 範囲上限の比較を `>=` に | Unit 2 ケース | MATCH |
| B12 | 令和に上限が無いことを忘れる | Unit 1 ケース | MATCH |
| B13 | 元号の範囲を見ない | Unit 6 ケース | MATCH |
| B14 | 元号の全角・小文字を受けない | Unit 1 本 | MATCH |
| B15 | 元号の名前（昭和・平成・令和）を受けない | 4 本 | MATCH |
| B16 | 全角の数字を半角にしない | 5 本 | MATCH |
| B17 | 整数の形を見ない | 5 本 | MATCH |
| B18 | 整数の上限の比較を `>=` に | Unit 3 ケース | MATCH |
| B19 | 大人人数の上限を 256 に | 3 本 | MATCH |
| B20 | 勤続年数の上限を 65534 に | Unit 2 ケース | MATCH |
| B21 | 担当者名の上限を 101 に | Unit 1 ケース | MATCH |
| B22 | 文字数を `strlen()` で数える | Unit 29 件 | MATCH |
| B23 | 文字数の境目の比較を `>=` に | Unit 14 件 | MATCH |
| B24 | 前後の空白を除かない | Unit 2 本 | MATCH |
| B25 | buyers に入れない列を除かない | Unit 4 本 | MATCH |
| B26 | 取得日を必須にしない | Unit 1 本 | MATCH |
| B27 | 分譲地名も 50 文字で検査する | Unit 1 本 | MATCH |

**プレビューの画面（`resources/views/admin/customers/import.blade.php`）**

| ID | 変異 | 落ちたテスト | 判定 |
|---|---|---|---|
| V01 | 確定の欄を V > 0 のときだけ出す | 3 本 | MATCH |
| V02 | 0 件でも確定の欄を出す | 1 本 | MATCH |
| V03 | ボタンを包む要素を最初から隠さない | 1 本 | MATCH |
| V04 | 常に隠して描く | 1 本 | MATCH |
| V05 | ボタンを包む要素の `x-show` を外す | 2 本 | MATCH |
| V06 | チェックボックスの `x-model` を外す | 1 本 | MATCH |
| V07 | 理由の `x-show` を外す | 1 本 | MATCH |
| V08 | 件数の JS をチェックに合わせない | 1 本 | MATCH |
| V09 | 重複候補の数を Alpine に渡さない | 3 本 | MATCH |
| V10 | 取り込める行の数を Alpine に渡さない | 2 本 | MATCH |
| V11 | ボタンの件数を `x-text` で入れ替えない | 2 本 | MATCH |
| V12 | 確定の印（`confirmed`）を落とす | 15 本（確定のフォームに `confirmed` が無い） | MATCH |
| V13 | `csv_data` を落とす | 10 本。エラーの画面 | MATCH |
| V14 | `department` を落とす | 14 本。エラーの画面 | MATCH |
| V15 | `@csrf` を落とす | 15 本（確定のフォームに `@csrf` が無い） | MATCH |

期待と違ったもの: なし。M09・M15 は主因のテストが期待どおりの理由で落ち、残り（M09: 1555 件・M15: 1570 件）はすべて `PDOException: There is already an active transaction` という単一の連鎖効果だった。

### Task 7: ローカルの実ブラウザ

2026-09-26 21:50〜21:56（使い捨て SQLite ＋ `artisan serve`〈ポート 8766〉＋ Playwright。実行前に `bootstrap/cache/config.php` が無いこと・接続先がこの使い捨て SQLite であることを確認）。

1. ログイン → 取込画面: フォーム（部署 housing・ファイル欄・「アップロードしてプレビュー」）が表示 ✓
2. `cic-mixed.csv` のプレビュー（1440px 幅）: 全3・正常1・エラー1・重複候補1／「⚠ 行4: 取得日「2026-02-30」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）」／「🔄 行2: 重複の可能性 — 山田 太郎（愛媛県松山市）※ 既存ID: 1」／ボタン「インポート実行（1件）」が見える ✓
3. 重複候補にチェックを入れると「インポート実行（2件）」に、外すと「（1件）」に戻る ✓
4. チェックを外したまま確定 → 「1件のインポートが完了しました。」。DB に佐藤花子（1980-01-02・元号 S・取得日 2）が追加され、`buyer_departments` に housing・2026-09-01・ランク C で入る ✓
5. `cic-dupes-only.csv`（正常0・重複候補1）: ボタンが隠れ（「インポート実行（0件）」が表示されない）、理由の案内が見える ✓
6. チェックを入れるとボタン「（1件）」が現れ理由が消える。外すと元に戻る ✓
7. チェックを入れたまま確定 → 「1件のインポートが完了しました。」。`buyers` が 3 行（山田太郎 ×2）に ✓
8. `cic-none.csv`（エラー1「⚠ 行2: 姓が未入力です」）: ボタン・チェックボックス・`csv_data` とも出ず、赤字（`rgb(220,38,38)`）で「インポート可能なデータがありません。CSVを修正してください。」 ✓
9. `main.scrollWidth === main.clientWidth`: 1440px 幅のプレビュー 2 画面（項目 2・5）が 1220/1220、375px 幅の重複候補のみ画面（チェック有無とも）と確定後の画面（正常0・重複候補2）が 375/375（`document` も 375/375）。画面外へはみ出す要素なし ✓
10. コンソールのエラー・警告 0 件 ✓

⚠ 「ファイルを選択」の文字リンクへのクリックはファイル選択ダイアログを開かず、`label:has(input[type="file"][name="csv_file"])` を押して初めて開いた。⚠ 375px への resize を 1 回試みたときにページが about:blank にリセットされた（Playwright MCP 側の挙動）。再ログイン後にやり直し、正常0・重複候補2 のプレビュー → チェック（「インポート実行（2件）」）→ 確定（「2件のインポートが完了しました。」）まで再確認し、コンソールは引き続き 0/0 だった。

後片付け: サーバ停止（ポート解放）・`routes/web.php` を元に戻す・`public/build`／使い捨て sqlite／CSV 3 本／このチェックで作った Playwright スナップショット 24 件を削除・両 worktree とも作業ツリー清浄を確認。

### Task 8: 独立レビュー

最終レビュー（opus。対象は `review-final-a162957b..5ca383ae.diff`＝ `app`／`resources`／`tests` のみ。範囲外にしていた軽微指摘の洗い直しも依頼）。

判定: **With fixes**（マージ可）。Critical・Important は 0 件。Minor 6 件（M1〜M6）:

- M1 `BuyerCsvRow` の `trim()` は ASCII のみ。全角スペースだけの姓が必須検査を通る（探りで実測）／末尾に全角スペースがある「山田　」は重複検査を素通りする可能性（SQLite の探り。本番の照合順序は未実測）／有効に見えるセル（元号・整数・日付）の前後に全角スペースが付くと誤りになる。→ `Str::trim()` へ変更＋ユニットテスト追加
- M2 新しい案内文の色 `#d97706` が白地で 3.19:1（AA の 4.5:1 未満）。既存の同系統の行は範囲外。→ `#b45309`（5.02:1）
- M3 0 件の歯止めの説明（「JS が動かずボタンが出たままでも」）が起こり得ない場面を書いている（V＝0 のときボタンはサーバが描かない）。実際に着く場面は二重送信・別タブでの先行確定・細工した送信（探りで実測: 2 回目の送信は「重複候補もインポートする」にチェックを、のメッセージに着地する）。→ コントローラ・テスト・設計書の 3 箇所の文言を直す。1 回だけ送らせる仕組みは BACKLOG へ
- M4 `loadCsv()` の docblock「壊れていても空文字になる」が、non-strict な `base64_decode()`（部分的に壊れていても読めた分を返す）の実際の挙動と食い違う。→ 文言を直す（strict 化はしない。挙動は行の再検査で安全なため）
- M5 docblock が設計書 §2.6／§4.3 を出典に挙げているが、そちらは `staff_name` 50 のまま（コード・テストは Task 1 の実測どおり 100）。→ 出典・数字を揃える
- M6 設問の回答の文字数を検査していない（`text` 型＝65,535 バイト。本番 strict でのみ全行が巻き戻る。現実にはまず起きない）。→ BACKLOG へ

Task 3 で保留していた軽微指摘 2 件（全角混じりの整数・`check()` の二段判定）は「対策を足しても検出力が変わらない」ことを確認し不採用。`MIN_THROWING_CALLS` は設計書 §4.5 どおり 10 のまま据え置き。決裁・周辺ビル調査との重複チェックの非対称・行ごとのクエリ・長いトランザクション・`catch (\Exception)`（Ruling 5）など既存の設計判断は範囲外として了承。

**修正の実施**（1 波でまとめて対応。`5ca383ae` → `66d1faf9` → `1de3efdf` → `e135ffb3`）:

- `66d1faf9`: M1。`BuyerCsvRow` を `Str::trim()` に変更＋ユニットテスト 2 本追加（`test_a_cell_of_only_full_width_spaces_is_blank` ／ `test_full_width_spaces_around_a_cell_are_trimmed`）。追加直後（実装変更前）は RED（2 件失敗）→ 実装変更後 GREEN（63/63）
- `1de3efdf`: M2。案内文の色を `#b45309` に（テストは変化なし・256 assertions のまま）
- `e135ffb3`: M3〜M5。コントローラ・テスト・`BuyerCsvRow` の docblock と、設計書 §2.6／§4.2-2／§4.3／§4.4 に日付つきで追記（挙動は変えず説明のみ）

**修正後の変異の再実測**（隔離コピー 1 つ。`--check 60/0`）: カナリア（C0）16 件は修正前と同一の集合。`B24`（前後の空白を除かない）は改めた old 文字列（`Str::trim` → `trim`）で 4 件（空白のあるセル・トリムされたセル・新設の 2 本）。新設の **B28**（`Str::trim` → `trim` に戻す）は**追加した 2 本のテストだけ**が検出 ＝ 新テストが load-bearing であることを確認。

**修正の範囲レビュー**（sonnet）: F1〜F5 すべて ADDRESSED（unit 63/174・feature 20/256 を再実行して確認）。新しい壊れは無し。

**残った軽微指摘**（レビュー後に発見・範囲外）: `CustomerImportController` には ASCII のみの `trim()` がまだ 4 箇所ある（空行判定・見出し整形・設問の回答の検出／値。F1 と同じ型だが、こちらは全角スペース入りの見出しが列に結びつかず行のエラーとして画面に出るので「黙った誤保存」ではない）→ BACKLOG へ

最終状態: 全件テスト **OK (2346 tests, 15196 assertions)**（63 本＋2 本の追加分を含む）／コンパイル済みビュー **274 本 / INVALID 0 件**。

### Task 10: 本番反映

2026-09-27 0:22〜0:33（日本時間）。新しいセッションで、利用者の「進めて」のあとにコントローラが実行した（前のセッションは Ruling 9 のとおり Task 9 のあとで止まっていた。引き継ぎの本文の貼り付けだけでは依頼として扱わず、利用者自身の一言をもらってから始めた）。

- 着手前の読み取り: main repo の `13.x` = `a162957b`・作業ツリーは空・`customer-import-confirm`（`a7335c27`。14 コミット先で、うち 3 つは `13.x` を取り込んだマージ）の祖先 ／ `approval-phase2a`（`fa88da79`）は `13.x` にもこのブランチにも入っていない ／ `462e7740..13.x` は `docs/` の 4 ファイルだけ ／ main repo に `vendor/bin/phpunit` は無い
- 直前の全件テスト（worktree・`a7335c27`）: **OK (2346 tests, 15196 assertions)**（1 分 40 秒）。⚠ 1 回目は素の `vendor/bin/phpunit` で流し、**1190 errors ＋ 2 failures がすべて `MissingAppKeyException`** だった。worktree に `.env` が無く、`phpunit.xml` も `APP_KEY` を持たないため（環境の問題で、コードは同じ）。使い捨てのキーを環境変数（`APP_KEY="base64:$(openssl rand -base64 32)"`）で渡して流し直した
- Step 1: `13.x` を `a162957b` → `a7335c27` へ早送り。作業ツリーは空のまま
- Step 2: `ls vendor/bin/phpunit` は何も出さない ／ `Generated optimized autoload files containing 6660 classes` ／ `BuyerCsvRow` が `1` ／ `$baseDir = dirname($vendorDir);` ／ `Tests\` の項目は 0（`--no-dev`）。worktree の `vendor`・`bootstrap/cache` は main repo と別のファイル（inode が違い、更新時刻も変わっていない）で、テストの流し直しに影響していない
- Step 3: `./deploy.sh` exit 0（UTC 2026-09-26 15:25:52〜15:26:02）。[1/6] ビルドの成果物は名前が変わらず（`app-wzJ6Tjji.css` 51.17 kB・`app-NiVQbl_Q.js` 46.56 kB）。[2/6] で送ったアプリのファイルは `app/Http/Controllers/Admin/CustomerImportController.php`・`app/Support/BuyerCsvRow.php`・`resources/views/admin/customers/import.blade.php`・`vendor/composer/autoload_classmap.php`・`vendor/composer/autoload_static.php`（ビルド成果物 3 つは更新時刻の違いで送り直し）。[3/6] はビルド成果物 3 つ。[4/6] で消えたファイルは 0。[5/6] は部品の名簿（laravel/tinker・nesbot/carbon・nunomaduro/termwind）と config / route / view のキャッシュとも成功
- Step 4: 本番の 3 ファイルの md5 が手元と一致（`12b0ce3e0dcc0bf7412b9e8375fefa2c`・`7a62b12a0a98e7b299ea1699e80b7a92`・`6888213dab87ff4ae87f3cfb0407ff09`）／ `class OK` ／ **`views=274 invalid=0`** ／ `bootstrap/cache` の `packages.php`・`services.php` は 0:25 に作り直され 700、`config.php`・`routes-v7.php` は 600 ／ 反映のあとの `laravel.log` の記録は 0 件（Chrome で見た後の 0:28 にも 0 件）
- 実 Chrome（ログイン済み・読み取りだけ・1440px）: `…/index.php/admin/customers/import` の題は「顧客CSVインポート - ミツワ都市開発管理システム」。フォームは POST・`multipart/form-data` の 1 本（部署のラジオ「住宅事業」（選択中）・「不動産事業」、`csv_file`（`accept=".csv,.txt"`）、「アップロードしてプレビュー」）。確定の欄は出ない（プレビューの前なので期待どおり）。画面の HTML に今回の `importCount` がある（＝新しいビュー）。`main` の横スクロール 0。開き直してコンソールの出力 0 件。ファイルは上げていない・フォームは送っていない。タブは閉じた
- `post_max_size`（読み取りだけ）: CLI（`/usr/local/php/8.3/etc/php.ini`）と `php-cgi -i` の既定値は `post_max_size=8M`・`upload_max_filesize=5M`。`~/www/php.ini`（2023-02-13。さくらのコントロールパネルの「php.ini設定」の保存先で、`/www` の下に効く）は `upload_max_filesize = 512M`・`post_max_size = 512M`。`~/www` の下（深さ 4 まで）の php.ini・`.user.ini` はほかのサイト（`chart/*`・`housing/*`・`websystem/*`）の下にだけあり、アプリの公開フォルダ `~/www/mitsuwa-t/system/manage` とその上の階層には無い → **Web の値は 512M の可能性が高い**（Web から直接は測っていない）
- 判断: 上限近いファイルの確定の送信は、Web が 512M なら落ちない見込み。⚠ 見積もりの前提を直した — 確定で送る `csv_data` はアップロードしたファイルではなく **UTF-8 に読み直した中身**（`CsvImportReader::decode()` の後の `$content`）の base64 で、確定のフォームは `enctype` 無し（URL エンコード）で送る。Shift_JIS の日本語は UTF-8 で 1.5 倍（半角カナは 3 倍）になるので、10,240 KB のファイルは約 13.3 MiB ではなく、日本語ばかりなら約 20 MiB（さらに URL エンコードで `+` `/` が 3 文字になる分が増える）。512M には収まる。既定値（8M・5M）に戻ると、5MB を超えるファイルはプレビューのアップロードで、それ以下でも日本語が多いファイルは確定の送信で落ちうる（BACKLOG の範囲外に書いた）
