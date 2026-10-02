# 周辺ビルのテナント明細の上げ直しで二重にしない 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 周辺ビル調査のテナント明細の取込（`AreaBuildingImportController::importTenants()`）で、登録済みの行と同じ行（ビル・階・部屋番号・テナント名）をスキップし、同じファイルや一部が重なるファイルを上げ直しても行が二重に入らないようにする。

**Architecture:** 設計書のとおり、コントローラの中だけで直す（新しいクラスは作らない）。行を処理する前に、ファイルに出てくるビルのテナント明細（退去済みも含む）を 1 本の問い合わせで読み、見分けのキー（`json_encode([ビル id, 階, normalizeName(部屋番号), normalizeName(テナント名)])`）ごとの数を作る。行ごとに、階の検査のあとでキーを作り、残りが 1 以上ならスキップして 1 減らし、0 なら今どおり登録する。完了のメッセージに「登録済みのためスキップ N 件」を足し、現況テナント数にはスキップした行のビルも並べる。取込の画面の注意書きを書き換える。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade / PHPUnit 11（worktree の `vendor/bin/phpunit`）/ SQLite（テスト）/ Playwright MCP（ローカルの実ブラウザ）

**Spec:** `docs/superpowers/specs/2026-10-01-area-tenant-reimport-design.md`（2026-10-01 に利用者が承認。以下「設計書」）

## Global Constraints

- 範囲は `AreaBuildingImportController::importTenants()` とその部品（先読み・キー）と、取込の画面のテナント明細の注意書き（設計書 §4.1）。ビル＋調査の取込（`importBuildings()`）・1 回限りの鍵（Bug #69）・戻り先・断りの文言・取込のプレビュー（JS）は変えない
- 本番の DB 変更・新しい PHP クラス・依存・ルートの変更は無い（`composer dump-autoload` は要らない）。CSS も変わらない（外す `text-amber-700` はほかに 6 ファイルが使う。Task 4 で `vite build` して確かめる）
- 見分けのキー（設計書 §4.2）: ビルの id・階（`int|null`。null（空欄）と 0 は別）・部屋番号・テナント名。部屋番号とテナント名は `AreaBuilding::normalizeName()` で比べる（全角空白・続く空白・前後の空白の違いは同じ。英数字の全角・半角と英字の大文字・小文字は区別する。null と空白だけの値はどちらも空欄）。区切り文字でつながず JSON（`JSON_THROW_ON_ERROR`）。ファイル側の値は `nullableString()` で切ってから渡す。業種・状態・確認日・退去日・備考は見ない
- 突き合わせる相手は、ファイルに出てくるビルのテナント明細の全行（退去済みも含む。`AreaBuildingTenant` に論理削除は無い）。削除したビルは今どおり対象外（台帳の地図に入らない）
- 数で扱う: キーごとに登録済みの行の数まではスキップし、超えた分は入れる。この取込で入れた行は残りに足さない。登録済みの行は書き換えない
- 行の検査の順番: 行が配列でない／ビル名が空 → 台帳に無いビル → 階が読めない（「値が不正」）→ 登録済みの照合 → 登録
- 完了のメッセージ（全文）: 「取込が完了しました。テナント登録 %d 件 / 登録済みのためスキップ %d 件 / ビル名が空でスキップ %d 件 / 値が不正でスキップ %d 件 / 台帳に無いビルでスキップ %d 行」。そのあとの「（N 棟: …）」と「 取込後の現況テナント数: …」は今どおり。現況テナント数にはスキップした行のビルも並べる
- 取込の画面の注意書き（全文・1 行で書く）: 「テナント明細は、台帳に既にあるビル名の行だけを取り込みます。台帳に無いビルは作成しません。登録済みと同じ行（ビル・階・部屋番号・テナント名が同じ。退去済みの行も含む）は取り込まずにスキップします。業種・状態が違っても書き換えないので、直すときは詳細画面から直してください。取込後に表示される件数と現況テナント数を確認してください。」。`<strong class="text-amber-700">` は外す
- 問い合わせは先読みの 1 本だけ増える（`test_tenant_import_does_not_scale_queries_per_row` の上限 25 は変えない）
- この計画では `<script>` を足さない（Blade のコメントは `{{-- --}}`。Bug #30）
- テストは PHP 8.3 で流す（手元の既定の `php` は 8.5 で、既存のコードが Deprecation を出す）

## Review Focus

- **ビル名の空白の書き方が台帳と違うファイルを上げ直す**（台帳「アルファ ビル」・ファイル「アルファ　　ビル」）— 同じビルとして照合しスキップするのが期待（先読みの対象を集めるときも `normalizeName` を通す）。Task 2 の `test_building_name_spacing_differences_still_match` と変異 P04 が固定する
- **階の書き方が登録済みと違う**（`3F`・`３階`・`B1`・`地下1階`）— 読んだあとの階が同じなら同じ行としてスキップするのが期待。Task 2 の `test_floor_notation_differences_are_the_same_floor`（4 通り）が固定する
- **1 つのファイルに、ビル名が空・台帳に無いビル・読めない階・登録済み・新しい行が混じる** — 件数がそれぞれの欄に 1 件ずつ入り、メッセージ全体が崩れないのが期待。Task 2 の `test_the_summary_counts_every_kind_of_row`（メッセージを `assertSame` で全文）が固定する
- **空き区画に入居したあとの新しい調査のファイル**（登録済み「3 階・301・名前なし」・ファイル「3 階・301・新しい店」）— 名前が違うので新しい行として入り、空き区画の行は書き換えずに残るのが期待（同期は範囲外。設計書 §1）。Task 2 の `test_a_vacancy_that_got_a_tenant_is_added_as_a_new_row` が固定する
- **画面で削除した行を、同じファイルで取り込み直す** — 削除は行ごと消えるので、もう一度入るのが期待。Task 2 の `test_a_row_deleted_on_the_screen_is_added_again` が固定する

---

## Context

- 周辺ビル調査のテナント明細の取込は、行を 1 行ずつ `create()` するだけで登録済みの行と突き合わせない。Bug #69（2026-09-28）で同じ取込の画面からの 2 回目は鍵で止めたが、画面を開き直してファイルを選び直す（上げ直す）と新しい鍵になるので止まらない。契約の上げ直し（Bug #70）の範囲外に残していた
- 作業場所: worktree `/Users/masanori/site/manage/.claude/worktrees/area-tenant-reimport`（ブランチ `worktree-area-tenant-reimport`）。この計画を書いた時点は、`13.x`（`b6e0fa49`）の上に設計書のコミット（`468e41c8`）と、この計画のコミット。`origin/13.x` も `b6e0fa49`（push は利用者の指示のとき）
- worktree には `composer install`（開発用の依存も）済み（`vendor/bin/phpunit` がある）。`.env` は無い・作らない
- 別の会話の worktree（`approval-phase3`・`customer-import-double-submit`・`import-double-submit`）と `.claude/worktrees/` の直下のほかの物（`phpunit-memory` など）には触らない・借りない
- 利用者の決まり（この計画を実行する人にも適用）: 応答は日本語・選択肢は本文の表で出す（AskUserQuestion のウィジェットは使わない）・推薦と実測を先に書く・質問は 1 回に 1 つ・`.env` / `.env.*` は読まない（`ls` で存在を確かめることもしない）・`git stash` と `--no-verify` は使わない・push は指示があったときだけ・main repo で `artisan migrate` を流さない・**本番への反映（Task 9）と本番の読み取りは、承認をもらってから**

## 試作で確かめたこと（2026-10-01）

この worktree の作業ツリーで、この計画のコード（Task 1〜3）を当てて測り、測り終えたあと元に戻した（計画のコミットには入れていない）。表の数字は、そのまま各 Task の「期待」に使っている。

| 見たこと | 結果 |
|---|---|
| 着手前の全件（`b6e0fa49`）| `OK (3144 tests, 22421 assertions)` |
| Task 1 の前後（部品を移すだけ）| `tests/Feature/Tenant/` が前後とも `OK (499 tests, 3428 assertions)` |
| Task 2 の直す前（新しいテスト＋既存の 1 本の書き換え。コントローラは元のまま）| 2 ファイルで `Tests: 94, Failures: 35.`（新しい 34 本と `test_repeated_tenant_import_reports_the_current_total`）。理由はどれも「行が二重になった」か「完了のメッセージに `登録済みのためスキップ` が無い」|
| Task 2 のあと | `tests/Feature/Tenant/` が `OK (533 tests, …)` |
| Task 3 の直す前（テストだけ書き換え）| 注意書きのテストが `Failed asserting that two strings are identical.`（今の画面の `<strong class="text-amber-700">…</strong>` が出る）|
| 最終形 | 全件 **`OK (3178 tests, 22737 assertions)`**（+34 本）／ コンパイル済みビュー `views=288 invalid=0`（2026-09-30 の本番反映と同じ数）／ `vite build` は `app-Cz5Vm3yg.css`・`app-NiVQbl_Q.js`（本番と同じ名前）|
| 変異の 1 回目（21 通り＋カナリア。区切り文字のテストと Review Focus の 5 本を足す前・P04 も無い）| 21 通りとも赤・等価の F08 だけ緑。ただし **F01（キーからビルを外す）を捕まえたのは「階の空欄と 0」のテストだけ**で、F01 のために書いた「別のビルのファイル」のテストは緑のまま（先読みがファイルに出るビルだけを読むため）→ 2 棟が混じるテストを足した（設計書との違いの 2）|
| 変異の 2 回目（最終のテスト・22 通り＋カナリア）| 22 通りとも期待どおり（Task 5 の表）。緑は等価の F08 だけ。カナリアは関係する 11 ファイルで 73 本・全件で 73 本 |

## 設計書との違い（試作で決めたこと）

| # | 設計書 | この計画 | 理由 |
|---|---|---|---|
| 1 | §4.2・§4.3「形の見本。名前と細部は計画で決める」 | 名前は見本どおり（`tenantKey()`）。先読みは `buildingIdsInRows()` と `registeredTenantCounts()` の 2 つ | ファイルに出るビルを集める処理と、登録済みを数える処理を分けると、それぞれの変異（P03・P04 と P01・P02）が別のテストで落ちる |
| 2 | §5.1 の 3「別のビルのファイル」 | 残したうえで、1 つのファイルに 2 棟が混じるテスト（`test_the_building_is_part_of_the_key_when_one_file_has_two_buildings`）を足した | 先読みはファイルに出るビルだけを読むので、3 のテストはキーからビルを外しても緑のまま（先測りで実測。変異 F01）。3 は先読みの範囲（P03）を守る |
| 3 | §5.1 の表（14 場面）| 区切り文字のテスト（`test_a_separator_inside_a_value_does_not_merge_two_rows`）と、Review Focus の 5 本を足した | JSON にした理由（設計書 §4.2）を守るテストが無かった（変異 F07）。Review Focus は上のとおり |
| 4 | §5.1 の 13「全部スキップしたときも現況テナント数」 | 1 のテスト（`test_uploading_the_same_file_again_skips_every_row`）の中で見る | 同じ場面なので 1 本にまとめた（変異 T01 が 1 のテストを落とす）|
| 5 | §5.1「送る部品を基底クラスへ移す（名前は計画で決める）」 | 名前は今のまま（`importForm()`・`sendImport()`・`importTenants()`）。`IMPORT_URL` も基底クラスの protected の定数にする | 子の private の定数を残すと、親の protected の定数と同じ名前で衝突する（PHP の決まり）。呼び出す側の書き方が変わらない |
| 6 | §5.3「変異の一覧と期待は計画で決める」 | 23 通り（カナリアを含む。Task 5 の表）。等価は F08 だけ | 下の「実測記録」 |

## 変えるファイル

| ファイル | 変更 | Task |
|---|---|---|
| `tests/Feature/Tenant/AreaBuildingTestCase.php` | 取込を送る部品 3 つと `IMPORT_URL` を受け取る | 1 |
| `tests/Feature/Tenant/AreaBuildingImportTest.php` | 部品を渡す（1）・`test_repeated_tenant_import_reports_the_current_total` と問い合わせの数の注記（2）・注意書きのテスト（3）| 1・2・3 |
| `tests/Feature/Tenant/AreaBuildingTenantReimportTest.php`（新規）| 上げ直しのテスト（24 メソッド・34 本）| 2 |
| `app/Http/Controllers/Tenant/AreaBuildingImportController.php` | 先読み・キー・スキップ・完了のメッセージ・現況テナント数・コメント | 2 |
| `resources/views/tenant/area-buildings/import.blade.php` | 注意書き | 3 |
| `docs/RULES.md`・`docs/BACKLOG.md`・この計画 | 記録 | 8・9 |

ルート・本番の DB・依存・`config/`・ほかの取込は変えない。

## 共通の決まり

- 作業は worktree の中で行う（`cd /Users/masanori/site/manage/.claude/worktrees/area-tenant-reimport`）。⚠ ハーネスが「`cd` と `$(…)` やパイプが混じった複雑なコマンド」を断ることがある。断られたら、`cd` を別の呼び出しにし、コマンドを単純な形に分けて流す
- 決まったテストだけ（worktree の中で）: `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --do-not-cache-result <テストファイル…>`
- 全件テスト（約 3〜5 分）: 上からファイルを外したもの
- ⚠ `APP_KEY` を渡し忘れると Feature テストが大量に `MissingAppKeyException` で落ちる（本数は正しく出るので見落としやすい）
- ⚠ 手元の既定の `php` は 8.5 なので、`/opt/homebrew/opt/php@8.3/bin/php` を明示する（8.5 だと既存の `CsvImportReader` が Deprecation を出し、最後の行が `OK, but there were issues!` になる）
- 編集は「置き換える前」がファイルにちょうど 1 か所あることを確かめてから当てる（Edit ツールは 1 か所でないと失敗する）。置き換えは書いてある順（ID の順）に当てる。行番号は `b6e0fa49` の時点のもの
- コミット: Conventional Commits・件名は日本語で 72 文字以内・句点なし・1 コミット 1 関心事・末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`（サブエージェントは自分の文脈の Co-Authored-By 行を使う）。HEREDOC で書く。`--no-verify` は使わない。コミットのあとに `git status --porcelain` が空であることを見る
- サブエージェントに任せるときは 1 つの worktree に 1 つのエージェント。モデルは sonnet 以上（haiku は自動で読み込む文書だけで文脈の上限に達する）。書いたらすぐコミットする
- zsh: 引用なしの `$VAR` は単語分割されない・`=` で始まる語は展開される（区切りの `echo '-----'` は引用する）・グロブが 1 つも当たらないとコマンド全体が止まる・`grep` は ugrep（数えるときは `/usr/bin/grep`）
- 変異テスト（Task 5）は先にコミットしてから（Bug #44）

---

## Task 0: 前提の確認

**Files:** なし

- [ ] **Step 1: worktree とブランチ**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/area-tenant-reimport
```

```bash
git status --porcelain; git rev-parse --abbrev-ref HEAD; git log --oneline -3; git merge-base --is-ancestor 13.x HEAD && echo 'ahead-of-13.x'; ls vendor/bin/phpunit
```

期待: `status` は何も出さない・`worktree-area-tenant-reimport`・この計画のコミット（`docs: 周辺ビルのテナント明細の上げ直しの実装計画を書く`）と設計書のコミット（`468e41c8`）と `b6e0fa49`・`ahead-of-13.x`・`vendor/bin/phpunit`。

⚠ `ahead-of-13.x` が出ない（`13.x` がこの後に進んだ）ときは、作業を始める前に取り込む（リベースしない＝記録のコミット番号を保つ）:

```bash
git log --stat --format='%h %s' HEAD..13.x
```

```bash
git merge --no-edit -m "Merge branch '13.x' into worktree-area-tenant-reimport" -m "作業中に 13.x へ入ったコミットを取り込む。" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" 13.x
```

取り込んだコミットが、この計画で触るファイル（上の「変えるファイル」）を変えていたら**止めて**、この計画の「置き換える前」がまだ合うかを確かめる。`composer.lock` が変わっていたら worktree で `composer install`（開発用の依存も入れる）。アプリのコードやテストが変わっていたら Step 2 の本数が変わるので測り直して記録し、以降の本数は**差**（この計画の +34 本）で突き合わせる。

- [ ] **Step 2: この時点の全件テスト**

```bash
APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --do-not-cache-result 2>&1 | tail -3
```

期待: `OK (3144 tests, 22421 assertions)`

---

## Task 1: 取込を送る部品をテストの基底クラスへ移す

**Files:**
- Modify: `tests/Feature/Tenant/AreaBuildingImportTest.php:62`・`:67-98`（部品を渡す）
- Modify: `tests/Feature/Tenant/AreaBuildingTestCase.php:25`・`:96`（部品を受け取る）

**Interfaces:**
- Produces: `AreaBuildingTestCase` の `protected const IMPORT_URL = '/tenant/area-buildings/import';`・`protected function importForm($user): array`・`protected function sendImport(array $fields)`・`protected function importTenants(array $rows)`（どれも今の private のものと同じ中身）。Task 2 の新しいテストが `importTenants()` を使う。⚠ 呼ぶたびに取込の画面を開き直す（新しい鍵）ので、2 回呼ぶと「上げ直し」になる

振る舞いは変えない（移すだけ）。

- [ ] **Step 1: 移す前の本数を控える**

```bash
APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --do-not-cache-result tests/Feature/Tenant/ 2>&1 | tail -1
```

期待: `OK (499 tests, 3428 assertions)`

- [ ] **Step 2: `AreaBuildingImportTest.php` から部品を消す**（3 か所）

**1-A** 置き換える前（`tests/Feature/Tenant/AreaBuildingImportTest.php`。ちょうど 1 か所）:

```php
    private const IMPORT_URL = '/tenant/area-buildings/import';

```

⚠ 置き換える前の末尾の空行 1 つも含む。

置き換えた後: 何も無い（消す）。

**1-B** 置き換える前（`tests/Feature/Tenant/AreaBuildingImportTest.php`。ちょうど 1 か所）:

```php
    /** 取込の画面を開き、画面が描いたフォームを分解する */
    private function importForm($user): array
    {
        $html = $this->actingAs($user)->get(self::IMPORT_URL)->assertOk()->getContent();

        return $this->parseForm($html, 'action="' . route('tenant.area-buildings.import.execute') . '"');
    }

    /**
     * 取込の画面を開き、画面が描いたフォームに Alpine が入れる 3 つ（kind・surveyed_month・rows）だけを埋めて送る。
     * ⚠ 鍵（import_token）は画面が描いたものを使う。手で組んで送ると鍵が無くて断られ、断られても戻り先が
     *   取込の画面なので、戻り先だけを見るテストは緑のまま狙った経路を通らない（設計書 §5.3）
     */
    private function sendImport(array $fields)
    {
        $manager = $this->manager();
        $form    = $this->importForm($manager);

        return $this->actingAs($manager)->post($form['action'], array_merge($form['fields'], $fields));
    }

```

⚠ 置き換える前の末尾の空行 1 つも含む。

置き換えた後: 何も無い（消す）。

**1-C** 置き換える前（`tests/Feature/Tenant/AreaBuildingImportTest.php`。ちょうど 1 か所）:

```php
    /** テナント明細のとき、画面の surveyed_month の hidden は ''（`kind === 'buildings' ? surveyedMonth : ''`） */
    private function importTenants(array $rows)
    {
        return $this->sendImport(['kind' => 'tenants', 'surveyed_month' => '', 'rows' => json_encode($rows)]);
    }

```

⚠ 置き換える前の末尾の空行 1 つも含む。

置き換えた後: 何も無い（消す）。

⚠ `importBuildings()`（`sendImport()` を呼ぶ）と `USED_TOKEN` の定数は残す。`self::IMPORT_URL`（32 か所）は、親の protected の定数を指すようになるので書き換えない。

- [ ] **Step 3: `AreaBuildingTestCase.php` に部品を足す**（2 か所）

**1-D** 置き換える前（`tests/Feature/Tenant/AreaBuildingTestCase.php`。ちょうど 1 か所）:

```php
    use ParsesForms;

```

⚠ 置き換える前の末尾の空行 1 つも含む。

置き換えた後:

```php
    use ParsesForms;

    /** 周辺ビル調査の取込の画面 */
    protected const IMPORT_URL = '/tenant/area-buildings/import';

```

**1-E** 置き換える前（`tests/Feature/Tenant/AreaBuildingTestCase.php`。ちょうど 1 か所）:

```php
    /** ページャに載った行のビル名（表示順のまま） */
```

置き換えた後:

```php
    // ============================================================
    // 取込（AreaBuildingImportTest と AreaBuildingTenantReimportTest で共用。2026-10-01 に AreaBuildingImportTest から移した）
    // ============================================================

    /** 取込の画面を開き、画面が描いたフォームを分解する */
    protected function importForm($user): array
    {
        $html = $this->actingAs($user)->get(self::IMPORT_URL)->assertOk()->getContent();

        return $this->parseForm($html, 'action="' . route('tenant.area-buildings.import.execute') . '"');
    }

    /**
     * 取込の画面を開き、画面が描いたフォームに Alpine が入れる 3 つ（kind・surveyed_month・rows）だけを埋めて送る。
     * ⚠ 鍵（import_token）は画面が描いたものを使う。手で組んで送ると鍵が無くて断られ、断られても戻り先が
     *   取込の画面なので、戻り先だけを見るテストは緑のまま狙った経路を通らない（設計書 2026-09-28-import-double-submit-design.md §5.3）
     * ⚠ 呼ぶたびに取込の画面を開き直す（新しい鍵）。2 回呼ぶと「上げ直し」になる
     */
    protected function sendImport(array $fields)
    {
        $manager = $this->manager();
        $form    = $this->importForm($manager);

        return $this->actingAs($manager)->post($form['action'], array_merge($form['fields'], $fields));
    }

    /** テナント明細のとき、画面の surveyed_month の hidden は ''（`kind === 'buildings' ? surveyedMonth : ''`） */
    protected function importTenants(array $rows)
    {
        return $this->sendImport(['kind' => 'tenants', 'surveyed_month' => '', 'rows' => json_encode($rows)]);
    }

    /** ページャに載った行のビル名（表示順のまま） */
```

- [ ] **Step 4: 本数が変わらないことを確かめる**

```bash
APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --do-not-cache-result tests/Feature/Tenant/ 2>&1 | tail -1
```

期待: Step 1 と同じ `OK (499 tests, 3428 assertions)`

- [ ] **Step 5: コミット**

```bash
git add tests/Feature/Tenant/AreaBuildingImportTest.php tests/Feature/Tenant/AreaBuildingTestCase.php
```

```bash
git commit -q -F - <<'EOF'
test: 周辺ビル調査の取込を送る部品をテストの基底クラスへ移す

上げ直しのテスト（次のコミット）と共用するため。中身は変えない。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

```bash
git status --porcelain; git log --oneline -1
```

---

## Task 2: 登録済みの行との照合（コントローラ）

**Files:**
- Create: `tests/Feature/Tenant/AreaBuildingTenantReimportTest.php`
- Modify: `tests/Feature/Tenant/AreaBuildingImportTest.php`（`test_repeated_tenant_import_reports_the_current_total` と問い合わせの数の注記）
- Modify: `app/Http/Controllers/Tenant/AreaBuildingImportController.php:40-45`（クラスの docblock）・`:269-341`（`importTenants()`）・`:434`（部品 3 つを足す）

**Interfaces:**
- Consumes: Task 1 の `importTenants(array $rows)`・`makeBuilding()`・`makeTenant()`（`AreaBuildingTestCase`）
- Produces: コントローラの private の `buildingIdsInRows(array $rows, array $map): array`（`list<int>`）・`registeredTenantCounts(array $buildingIds): array`（`array<string, int>`）・`tenantKey(int $buildingId, ?int $floor, ?string $roomNumber, ?string $name): string`。完了のメッセージに「登録済みのためスキップ %d 件」（Task 5 の変異・Task 6 の実ブラウザが見る）

- [ ] **Step 1: 新しいテストを書く**（`tests/Feature/Tenant/AreaBuildingTenantReimportTest.php`。この内容そのまま）

```php
<?php

namespace Tests\Feature\Tenant;

use App\Enums\AreaTenantStatus;
use App\Models\AreaBuildingTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 周辺ビルのテナント明細の上げ直しで二重にしない（設計書 docs/superpowers/specs/2026-10-01-area-tenant-reimport-design.md）。
 *
 * 見分けのキーは ビル・階・部屋番号・テナント名（部屋番号と名前は AreaBuilding::normalizeName() で比べる）。
 * 登録済みの行（退去済みも含む）と同じキーの行は、登録済みの行の数までスキップし、登録済みの行は書き換えない。
 *
 * ⚠ 取込は importTenants()（AreaBuildingTestCase）で送る。毎回取込の画面を開き直して、画面が描いた鍵で送るので、
 *   2 回送る場面は「上げ直し」になる（同じ画面からの 2 回目は Bug #69 の鍵が断る。AreaBuildingImportTest の別のテスト）。
 * ⚠ 「書き込まなかった」は行数だけでなく creating の回数でも見る（Bug #48）。
 * ⚠ 完了のメッセージは件数の並びを全文で見る（summary()。部分一致の false-pass を避ける。Bug #43）。
 */
class AreaBuildingTenantReimportTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    /** 完了のメッセージの件数の並び（AreaBuildingImportController::importTenants() と同じ文） */
    private function summary(int $created, int $registered, int $blank = 0, int $invalid = 0, int $unmatched = 0): string
    {
        return "取込が完了しました。テナント登録 {$created} 件 / 登録済みのためスキップ {$registered} 件 / "
            . "ビル名が空でスキップ {$blank} 件 / 値が不正でスキップ {$invalid} 件 / 台帳に無いビルでスキップ {$unmatched} 行";
    }

    /** AreaBuildingTenant の creating の回数を数える（Bug #48） */
    private function watchCreates(): object
    {
        $seen = new class
        {
            public int $creates = 0;
        };

        AreaBuildingTenant::creating(function () use ($seen): void {
            $seen->creates++;
        });

        return $seen;
    }

    // ============================================================
    // ① 同じファイル・一部が重なるファイル ② 別のビルのファイル
    // ============================================================

    /** 直す前: 2 回目も 3 件が入り、6 件になった */
    public function test_uploading_the_same_file_again_skips_every_row(): void
    {
        $this->makeBuilding('アルファビル');
        $this->makeBuilding('ベータビル');
        $rows = [
            ['building_name' => 'アルファビル', 'floor' => '3', 'room_number' => '301', 'name' => '大街道珈琲', 'industry' => '飲食', 'status' => '営業中'],
            ['building_name' => 'アルファビル', 'floor' => 'B1', 'room_number' => 'B101', 'status' => '空室'],
            ['building_name' => 'ベータビル', 'floor' => '2', 'room_number' => '201', 'name' => '花屋', 'industry' => '小売', 'status' => '営業'],
        ];

        $this->importTenants($rows)->assertRedirect(route('tenant.area-buildings.index'));
        $this->assertSame(3, AreaBuildingTenant::count(), '1 回目で取り込まれていない（測定が無効）');

        $seen = $this->watchCreates();
        $this->importTenants($rows)->assertRedirect(route('tenant.area-buildings.index'));

        $this->assertSame(3, AreaBuildingTenant::count(), '上げ直しで行が二重になった');
        $this->assertSame(0, $seen->creates, '上げ直しで書き込みが走った');
        $this->assertStringContainsString($this->summary(0, 3), session('success'));
        // スキップした行のビルも現況テナント数に並べる（全部スキップしたときも出る）
        $this->assertStringContainsString('取込後の現況テナント数: アルファビル 2 件 / ベータビル 1 件', session('success'));
    }

    public function test_only_the_new_rows_of_an_overlapping_file_are_added(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $first    = [
            ['building_name' => 'アルファビル', 'floor' => '1', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業'],
            ['building_name' => 'アルファビル', 'floor' => '2', 'room_number' => '201', 'name' => '乙商店', 'status' => '営業'],
        ];
        $this->importTenants($first)->assertRedirect();

        $this->importTenants(array_merge($first, [
            ['building_name' => 'アルファビル', 'floor' => '3', 'room_number' => '301', 'name' => '丙商店', 'status' => '営業'],
        ]))->assertRedirect();

        $this->assertSame(['甲商店', '乙商店', '丙商店'], $building->tenants()->orderBy('id')->pluck('name')->all());
        $this->assertStringContainsString($this->summary(1, 2), session('success'));
    }

    public function test_a_file_for_another_building_is_added_even_with_the_same_floor_room_and_name(): void
    {
        $alpha = $this->makeBuilding('アルファビル');
        $beta  = $this->makeBuilding('ベータビル');
        $this->makeTenant($alpha, ['floor' => 3, 'room_number' => '301', 'name' => '大街道珈琲']);

        $this->importTenants([
            ['building_name' => 'ベータビル', 'floor' => '3', 'room_number' => '301', 'name' => '大街道珈琲', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $beta->tenants()->count(), '別のビルの同じ階・部屋番号・名前の行とスキップした');
        $this->assertStringContainsString($this->summary(1, 0), session('success'));
    }

    /**
     * ビルもキーの要素（1 つのファイルに 2 棟が混じるとき）。
     * ⚠ 上のテストは、先読みがファイルに出るビルだけを読むので、キーからビルを外しても緑になる（2026-10-01 の先測りで実測）。
     *   2 棟を同じファイルに入れ、登録済みでないビルの行を先に置くと、キーからビルを外したときに取り違えて落ちる
     */
    public function test_the_building_is_part_of_the_key_when_one_file_has_two_buildings(): void
    {
        $alpha = $this->makeBuilding('アルファビル');
        $beta  = $this->makeBuilding('ベータビル');
        $this->makeTenant($alpha, ['floor' => 3, 'room_number' => '301', 'name' => '大街道珈琲']);

        $this->importTenants([
            ['building_name' => 'ベータビル', 'floor' => '3', 'room_number' => '301', 'name' => '大街道珈琲', 'status' => '営業'],
            ['building_name' => 'アルファビル', 'floor' => '3', 'room_number' => '301', 'name' => '大街道珈琲', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $alpha->tenants()->count(), '登録済みのビルの行が入った');
        $this->assertSame(1, $beta->tenants()->count(), '別のビルの行をスキップした');
        $this->assertStringContainsString($this->summary(1, 1), session('success'));
    }

    // ============================================================
    // 数で扱う（空き区画は並ぶのが普通）
    // ============================================================

    /** 登録済み「3 階・部屋番号なし・名前なし」1 行・ファイルに 2 行 → 1 行目はスキップ・2 行目は登録（設計書 §4.4 の例）*/
    public function test_rows_beyond_the_registered_count_are_added(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 3, 'status' => 'vacant']);
        $vacancy = ['building_name' => 'アルファビル', 'floor' => '3', 'status' => '空室'];

        $this->importTenants([$vacancy, $vacancy])->assertRedirect();

        $this->assertSame(2, $building->tenants()->where('floor', 3)->count());
        $this->assertStringContainsString($this->summary(1, 1), session('success'));
    }

    /** ⚠ この取込で入れた行を残りに足すと、2 行目がスキップされて 1 行しか入らない */
    public function test_identical_rows_in_one_file_are_all_added_when_none_is_registered(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $vacancy  = ['building_name' => 'アルファビル', 'floor' => '3', 'status' => '空室'];

        $this->importTenants([$vacancy, $vacancy])->assertRedirect();

        $this->assertSame(2, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(2, 0), session('success'));
    }

    /** ⚠ 残りを減らさない（あるかないかだけで見る）と、3 行とも消える */
    public function test_each_registered_row_absorbs_one_identical_row(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 3, 'status' => 'vacant']);
        $this->makeTenant($building, ['floor' => 3, 'status' => 'vacant']);
        $vacancy = ['building_name' => 'アルファビル', 'floor' => '3', 'status' => '空室'];

        $this->importTenants([$vacancy, $vacancy, $vacancy])->assertRedirect();

        $this->assertSame(3, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(1, 2), session('success'));
    }

    // ============================================================
    // 突き合わせる相手・書き換えない
    // ============================================================

    /** 古いファイルを上げ直しても、退去したテナントが現況に戻らない（案 A）*/
    public function test_moved_out_rows_are_matched_and_stay_moved_out(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $tenant   = $this->makeTenant($building, ['floor' => 1, 'room_number' => '101', 'name' => '甲商店', 'moved_out_on' => '2026-03-31']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '1', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $building->tenants()->count(), '退去済みの行と同じ行が現況として入った');
        $this->assertSame('2026-03-31', $tenant->fresh()->moved_out_on->format('Y-m-d'));
        $this->assertStringContainsString($this->summary(0, 1), session('success'));
        $this->assertStringContainsString('取込後の現況テナント数: アルファビル 0 件', session('success'));
    }

    public function test_a_skipped_row_does_not_overwrite_the_registered_row(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $tenant   = $this->makeTenant($building, [
            'floor' => 2, 'room_number' => '201', 'name' => '乙商店', 'industry' => '飲食', 'status' => 'operating',
            'confirmed_on' => '2026-05-01', 'notes' => '画面で確かめた',
        ]);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '2', 'room_number' => '201', 'name' => '乙商店', 'industry' => '小売', 'status' => '空室'],
        ])->assertRedirect();

        $tenant = $tenant->fresh();
        $this->assertSame(1, $building->tenants()->count());
        $this->assertSame('飲食', $tenant->industry);
        $this->assertSame(AreaTenantStatus::Operating, $tenant->status);
        $this->assertSame('2026-05-01', $tenant->confirmed_on->format('Y-m-d'));
        $this->assertNull($tenant->moved_out_on);
        $this->assertSame('画面で確かめた', $tenant->notes);
        $this->assertStringContainsString($this->summary(0, 1), session('success'));
    }

    // ============================================================
    // 見分けのキー
    // ============================================================

    /** @return array<string, array{0: string, 1: string, 2: string}> [階, 部屋番号, テナント名] */
    public static function oneElementDiffers(): array
    {
        return [
            '階が違う'       => ['2', '301', '大街道珈琲'],
            '部屋番号が違う' => ['3', '302', '大街道珈琲'],
            '名前が違う'     => ['3', '301', '別の店'],
        ];
    }

    #[DataProvider('oneElementDiffers')]
    public function test_each_key_element_tells_rows_apart(string $floor, string $room, string $name): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 3, 'room_number' => '301', 'name' => '大街道珈琲']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => $floor, 'room_number' => $room, 'name' => $name, 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(2, $building->tenants()->count(), '1 つの要素が違う行をスキップした');
        $this->assertStringContainsString($this->summary(1, 0), session('success'));
    }

    /** 空欄（null）と 0 階は別のキー（'0' '0F' は 0 として保存される。設計書 §2.3）*/
    public function test_a_blank_floor_and_floor_zero_are_different(): void
    {
        $alpha = $this->makeBuilding('アルファビル');
        $beta  = $this->makeBuilding('ベータビル');
        $this->makeTenant($alpha, ['floor' => null, 'room_number' => 'A', 'name' => '甲商店']);
        $this->makeTenant($beta, ['floor' => 0, 'room_number' => 'A', 'name' => '甲商店']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '0', 'room_number' => 'A', 'name' => '甲商店', 'status' => '営業'],
            ['building_name' => 'ベータビル', 'floor' => '', 'room_number' => 'A', 'name' => '甲商店', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame([null, 0], $alpha->tenants()->orderBy('id')->pluck('floor')->all());
        $this->assertSame([0, null], $beta->tenants()->orderBy('id')->pluck('floor')->all());
        $this->assertStringContainsString($this->summary(2, 0), session('success'));
    }

    /**
     * 値の中に区切り文字があっても、別の行どうしが同じキーにならない（キーを JSON にする理由。設計書 §4.2）。
     * ⚠ `|` でつなぐと、部屋番号「A|B」・名前「C」と、部屋番号「A」・名前「B|C」がどちらも「A|B|C」になってスキップされる
     */
    public function test_a_separator_inside_a_value_does_not_merge_two_rows(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 1, 'room_number' => 'A|B', 'name' => 'C']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '1', 'room_number' => 'A', 'name' => 'B|C', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(2, $building->tenants()->count(), '区切り文字の位置だけが違う別の行をスキップした');
        $this->assertStringContainsString($this->summary(1, 0), session('success'));
    }

    /** @return array<string, array{0: string, 1: string}> [ファイルの部屋番号, ファイルのテナント名]（登録済みは '301' / '大街道 珈琲'）*/
    public static function whitespaceVariants(): array
    {
        return [
            '部屋番号の前後に全角空白'   => ["\u{3000}301\u{3000}", '大街道 珈琲'],
            '名前の間が全角空白'         => ['301', "大街道\u{3000}珈琲"],
            '名前の間に空白が続く'       => ['301', "大街道 \u{3000} 珈琲"],
        ];
    }

    /** 全角空白・続く空白・前後の空白の違いは同じ行とみなす（normalizeName）*/
    #[DataProvider('whitespaceVariants')]
    public function test_whitespace_differences_are_ignored(string $room, string $name): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 3, 'room_number' => '301', 'name' => '大街道 珈琲']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '3', 'room_number' => $room, 'name' => $name, 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $building->tenants()->count(), '空白の違いだけの行を別の行として入れた');
        $this->assertStringContainsString($this->summary(0, 1), session('success'));
    }

    /** @return array<string, array{0: string, 1: string}> [ファイルの部屋番号, ファイルのテナント名]（登録済みは 'A301' / 'ABC商店'）*/
    public static function widthAndCaseVariants(): array
    {
        return [
            '部屋番号の数字が全角'   => ['A３０１', 'ABC商店'],
            '部屋番号の英字が全角'   => ['Ａ301', 'ABC商店'],
            '名前の英字が小文字'     => ['A301', 'abc商店'],
        ];
    }

    /** 英数字の全角・半角と、英字の大文字・小文字は区別する（ビル名の突き合わせと同じ扱い）*/
    #[DataProvider('widthAndCaseVariants')]
    public function test_width_and_case_differences_are_not_ignored(string $room, string $name): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 3, 'room_number' => 'A301', 'name' => 'ABC商店']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '3', 'room_number' => $room, 'name' => $name, 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(2, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(1, 0), session('success'));
    }

    /** @return array<string, array{0: ?string, 1: string}> [登録済みの部屋番号・名前, ファイルの部屋番号・名前] */
    public static function blankVariants(): array
    {
        return [
            '登録済みは null・ファイルは全角空白だけ' => [null, "\u{3000}"],
            '登録済みは全角空白だけ・ファイルは空欄'   => ["\u{3000}", ''],
        ];
    }

    /**
     * null と「空白だけ」の値は、どちらも空欄として同じとみなす。
     * ⚠ 取込は全角空白だけのセルを null にせず '　' のまま保存する（nullableString() の trim() は半角の空白だけを落とす。設計書 §7）
     */
    #[DataProvider('blankVariants')]
    public function test_a_blank_only_value_matches_a_blank(?string $registered, string $file): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 3, 'room_number' => $registered, 'name' => $registered, 'status' => 'vacant']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '3', 'room_number' => $file, 'name' => $file, 'status' => '空室'],
        ])->assertRedirect();

        $this->assertSame(1, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(0, 1), session('success'));
    }

    // ============================================================
    // 削除したビル・検査の順番・長い文字列・画面で登録した行
    // ============================================================

    /** 削除したビルの行とは照合しない（同じ名前で作り直したビルに入る）*/
    public function test_rows_of_a_deleted_building_are_not_matched(): void
    {
        $old = $this->makeBuilding('アルファビル');
        $this->makeTenant($old, ['floor' => 1, 'room_number' => '101', 'name' => '甲商店']);
        $old->delete();
        $new = $this->makeBuilding('アルファビル');

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '1', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $new->tenants()->count());
        $this->assertStringContainsString($this->summary(1, 0), session('success'));
    }

    /**
     * 階が読めない行は、照合の前に「値が不正」に数える（設計書 §4.4）。
     * ⚠ 登録済みを 0 階にしておく。読めない階（false）は int の引数へ渡ると 0 になるので、照合を階の検査より前へ動かすと
     *   この行が「登録済み」に数えられて落ちる
     */
    public function test_an_unreadable_floor_is_invalid_even_if_the_rest_matches(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 0, 'room_number' => 'A', 'name' => '甲商店']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => 'ペントハウス', 'room_number' => 'A', 'name' => '甲商店', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(0, 0, invalid: 1), session('success'));
    }

    /** 1 回目に切って入った長い値と、同じファイルの上げ直しの値が同じキーになる（切ってから比べる。設計書 §4.2）*/
    public function test_long_values_are_compared_after_truncation(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $rows     = [[
            'building_name' => 'アルファビル', 'floor' => '1',
            'room_number'   => str_repeat('あ', 80), 'name' => str_repeat('い', 400), 'status' => '営業',
        ]];
        $this->importTenants($rows)->assertRedirect();
        $this->assertSame(50, mb_strlen($building->tenants()->firstOrFail()->room_number), '1 回目で切られていない（測定が無効）');

        $this->importTenants($rows)->assertRedirect();

        $this->assertSame(1, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(0, 1), session('success'));
    }

    // ============================================================
    // 計画の Review Focus（2026-10-01-area-tenant-reimport.md）
    // ============================================================

    /** ビル名の空白の書き方が台帳と違っても同じビルとして照合する（先読みの対象も normalizeName で拾う）*/
    public function test_building_name_spacing_differences_still_match(): void
    {
        $building = $this->makeBuilding('アルファ ビル');
        $this->makeTenant($building, ['floor' => 1, 'room_number' => '101', 'name' => '甲商店']);

        $this->importTenants([
            ['building_name' => "アルファ\u{3000}\u{3000}ビル", 'floor' => '1', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(0, 1), session('success'));
    }

    /** @return array<string, array{0: int, 1: string}> [登録済みの階, ファイルの階] */
    public static function floorNotations(): array
    {
        return [
            '3F'      => [3, '3F'],
            '３階'    => [3, '３階'],
            'B1'      => [-1, 'B1'],
            '地下1階' => [-1, '地下1階'],
        ];
    }

    /** 階の書き方が違っても、読んだあとの階が同じなら同じ行（FloorNumber::parse()）*/
    #[DataProvider('floorNotations')]
    public function test_floor_notation_differences_are_the_same_floor(int $registered, string $file): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => $registered, 'room_number' => '1', 'name' => '甲商店']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => $file, 'room_number' => '1', 'name' => '甲商店', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(0, 1), session('success'));
    }

    /** 1 つのファイルに 5 種類の行が混じっても、件数がそれぞれの欄に入る（メッセージ全体を固定する）*/
    public function test_the_summary_counts_every_kind_of_row(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 1, 'room_number' => '101', 'name' => '甲商店']);

        $this->importTenants([
            ['building_name' => '', 'floor' => '1', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業'],
            ['building_name' => '知らないビル', 'floor' => '1', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業'],
            ['building_name' => 'アルファビル', 'floor' => 'ペントハウス', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業'],
            ['building_name' => 'アルファビル', 'floor' => '1', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業'],
            ['building_name' => 'アルファビル', 'floor' => '2', 'room_number' => '201', 'name' => '乙商店', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(2, $building->tenants()->count());
        $this->assertSame(
            $this->summary(1, 1, 1, 1, 1) . '（1 棟: 知らないビル） 取込後の現況テナント数: アルファビル 2 件',
            session('success')
        );
    }

    /**
     * 空き区画に入居したあとの新しい調査のファイルは、名前が違うので新しい行として入り、空き区画の行は残る。
     * ⚠ 調査し直して現況を置き換える（同期）は範囲外（設計書 §1・§6）。空き区画の行は画面から退去日を入れるか削除する
     */
    public function test_a_vacancy_that_got_a_tenant_is_added_as_a_new_row(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $vacancy  = $this->makeTenant($building, ['floor' => 3, 'room_number' => '301', 'status' => 'vacant']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '3', 'room_number' => '301', 'name' => '新しい店', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(2, $building->tenants()->whereNull('moved_out_on')->count());
        $this->assertNull($vacancy->fresh()->name, '空き区画の行が書き換えられた');
        $this->assertStringContainsString($this->summary(1, 0), session('success'));
    }

    /** 画面で削除した行（行ごと消える）は、取り込み直すと入る */
    public function test_a_row_deleted_on_the_screen_is_added_again(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $rows     = [['building_name' => 'アルファビル', 'floor' => '1', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業']];
        $this->importTenants($rows)->assertRedirect();
        $building->tenants()->firstOrFail()->delete();

        $this->importTenants($rows)->assertRedirect();

        $this->assertSame(1, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(1, 0), session('success'));
    }

    /** 取込でなく画面から 1 件ずつ登録した行とも照合する */
    public function test_rows_registered_on_the_screen_are_matched(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 2, 'room_number' => '201', 'name' => '花屋', 'industry' => '小売']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '2F', 'room_number' => '201', 'name' => '花屋', 'industry' => '小売', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(0, 1), session('success'));
    }
}
```

- [ ] **Step 2: 既存のテストを書き換える**（`tests/Feature/Tenant/AreaBuildingImportTest.php`。3 か所）

**2-G** 置き換える前（`tests/Feature/Tenant/AreaBuildingImportTest.php`。ちょうど 1 か所）:

```php
    /**
     * 再取込は行を二重にする。突合キーが設計に無いので防げないが、**気づけるようにする**（I-5）。
     * ⚠ `AreaBuildingController::divergence()` が現況テナント数を見るので、
     *   二重取込は乖離警告に嘘の数字を出させる。
     */
```

置き換えた後:

```php
    /**
     * 取込のあとに現況テナント数を返す（I-5）。⚠ `AreaBuildingController::divergence()` が現況テナント数を見るので、
     *   二重の行は乖離警告に嘘の数字を出させる。
     * 上げ直しは登録済みと同じ行をスキップする（2026-10-01。設計書 2026-10-01-area-tenant-reimport-design.md。
     *   詳しい場面は AreaBuildingTenantReimportTest）。それまでは 2 回で 2 件になっていた。
     */
```

**2-H** 置き換える前（`tests/Feature/Tenant/AreaBuildingImportTest.php`。ちょうど 1 か所）:

```php
        $this->importTenants($rows)->assertRedirect();
        $this->assertSame(2, $building->tenants()->count());
        $this->assertStringContainsString('取込後の現況テナント数: アルファビル 2 件', session('success'));
```

置き換えた後:

```php
        $this->importTenants($rows)->assertRedirect();
        $this->assertSame(1, $building->tenants()->count());
        $this->assertStringContainsString('登録済みのためスキップ 1 件', session('success'));
        $this->assertStringContainsString('取込後の現況テナント数: アルファビル 1 件', session('success'));
```

**2-I** 置き換える前（`tests/Feature/Tenant/AreaBuildingImportTest.php`。ちょうど 1 か所）:

```php
        // 内訳: ビル一覧 1 + INSERT 20 + 現況テナント数の集計 1 = 22
```

置き換えた後:

```php
        // 内訳: ビル一覧 1 + 登録済みの行の先読み 1 + INSERT 20 + 現況テナント数の集計 1 = 23
        // ⚠ 先読みをビルごと・行ごとにすると 20 本増えて落ちる（設計書 2026-10-01-area-tenant-reimport-design.md §4.3）
```

- [ ] **Step 3: 直す前のコードで赤になることを確かめる**

```bash
APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --do-not-cache-result tests/Feature/Tenant/AreaBuildingTenantReimportTest.php tests/Feature/Tenant/AreaBuildingImportTest.php 2>&1 | tail -2
```

期待: `Tests: 94, …, Failures: 35.`（新しい 34 本と `test_repeated_tenant_import_reports_the_current_total`）。落ちた理由は、どれも「行が二重になった（`Failed asserting that 2 is identical to 1.` や `上げ直しで行が二重になった` など）」か「完了のメッセージに `登録済みのためスキップ` が無い（`… contains "取込が完了しました。テナント登録 N 件 / 登録済みのためスキップ …"`）」のどちらか。違う理由（`MissingAppKeyException`・`Call to undefined method` など）で落ちていたら止める。

- [ ] **Step 4: コントローラを直す**（`app/Http/Controllers/Tenant/AreaBuildingImportController.php`。6 か所）

**2-A** 置き換える前（`app/Http/Controllers/Tenant/AreaBuildingImportController.php`。ちょうど 1 か所）:

```php
 *   断ったら取込の画面へ戻す（開き直すので新しい鍵が出る）。送ったあと「戻る」で戻った画面は、画面の部品が読み込み直す
 *   （_partials/_submit_once の reloadOnReturn）。
 */
```

置き換えた後:

```php
 *   断ったら取込の画面へ戻す（開き直すので新しい鍵が出る）。送ったあと「戻る」で戻った画面は、画面の部品が読み込み直す
 *   （_partials/_submit_once の reloadOnReturn）。
 *
 * ⚠ 取込の画面を開き直してファイルを選び直す（上げ直す）と新しい鍵になるので、上げ直しは鍵では止まらない。テナント明細は
 *   登録済みの行（退去済みも含む）と ビル・階・部屋番号・テナント名 で照合し、同じ行は登録済みの行の数までスキップする
 *   （設計書 2026-10-01-area-tenant-reimport-design.md。importTenants()・tenantKey()）。2026-10-01 までは突き合わせず二重に入っていた。
 */
```

**2-B** 置き換える前（`app/Http/Controllers/Tenant/AreaBuildingImportController.php`。ちょうど 1 か所）:

```php
        $created        = 0;
        $blank          = 0;
        $invalid        = 0;
        $unmatchedRows  = 0;
        $unmatchedNames = [];
        $touched        = [];

        $map = $this->buildingMapByNormalizedName();

```

⚠ 置き換える前の末尾の空行 1 つも含む。

置き換えた後:

```php
        $created        = 0;
        $registered     = 0;
        $blank          = 0;
        $invalid        = 0;
        $unmatchedRows  = 0;
        $unmatchedNames = [];
        $touched        = [];

        $map = $this->buildingMapByNormalizedName();

        // 登録済みの行の数（見分けのキーごと）。上げ直しても二重にしない（設計書 2026-10-01-area-tenant-reimport-design.md）
        $remaining = $this->registeredTenantCounts($this->buildingIdsInRows($rows, $map));

```

**2-C** 置き換える前（`app/Http/Controllers/Tenant/AreaBuildingImportController.php`。ちょうど 1 か所）:

```php
            $building = $map[$key];

            AreaBuildingTenant::create([
                'area_building_id' => $building->id,
                'floor'            => $floor,
                'room_number'      => $this->nullableString($row['room_number'] ?? null, 50),
                'name'             => $this->nullableString($row['name'] ?? null, 255),
```

置き換えた後:

```php
            $building   = $map[$key];
            $roomNumber = $this->nullableString($row['room_number'] ?? null, 50);
            $name       = $this->nullableString($row['name'] ?? null, 255);

            // 登録済みと同じ行（ビル・階・部屋番号・テナント名）は、登録済みの行の数までスキップする（書き換えない）。
            // ⚠ 階の検査のあとに置く（読めない階の行は「値が不正」に数える）。
            // ⚠ この取込で入れた行は残りに足さない（ファイルの中で同じ行が並べば、超えた分はすべて入る。空き区画は並ぶのが普通）。
            $tenantKey = $this->tenantKey($building->id, $floor, $roomNumber, $name);
            if (($remaining[$tenantKey] ?? 0) > 0) {
                $remaining[$tenantKey]--;
                $registered++;
                // 現況テナント数には並べる（全部スキップしたときも、増えていないことがその場で分かる）
                $touched[$building->id] = $building;
                continue;
            }

            AreaBuildingTenant::create([
                'area_building_id' => $building->id,
                'floor'            => $floor,
                'room_number'      => $roomNumber,
                'name'             => $name,
```

**2-D** 置き換える前（`app/Http/Controllers/Tenant/AreaBuildingImportController.php`。ちょうど 1 か所）:

```php
            '取込が完了しました。テナント登録 %d 件 / ビル名が空でスキップ %d 件 / 値が不正でスキップ %d 件 / 台帳に無いビルでスキップ %d 行',
            $created,
            $blank,
```

置き換えた後:

```php
            '取込が完了しました。テナント登録 %d 件 / 登録済みのためスキップ %d 件 / ビル名が空でスキップ %d 件 / 値が不正でスキップ %d 件 / 台帳に無いビルでスキップ %d 行',
            $created,
            $registered,
            $blank,
```

**2-E** 置き換える前（`app/Http/Controllers/Tenant/AreaBuildingImportController.php`。ちょうど 1 か所）:

```php
        // ⚠ 再取込は行を二重にする（突合キーが設計に無いので重複判定ができない）。
        //   `AreaBuildingController::divergence()` が現況テナント数と調査回の件数を
        //   突き合わせるため、二重取込は乖離警告に嘘の数字を出させる。
        //   せめて「今そのビルに何件あるか」を返して、その場で気づけるようにする。
```

置き換えた後:

```php
        // 「今そのビルに何件あるか」を返して、その場で確かめられるようにする。
        //   `AreaBuildingController::divergence()` が現況テナント数と調査回の件数を突き合わせるため、
        //   二重の行は乖離警告に嘘の数字を出させる。登録済みと同じ行はスキップする（上の照合）が、
        //   ファイルの中で同じ行が並べば並んだ分だけ入るので、件数はここで確かめる。
        //   ⚠ スキップした行のビルも並べる（全部スキップしたときも出る）。
```

**2-F** 置き換える前（`app/Http/Controllers/Tenant/AreaBuildingImportController.php`。ちょうど 1 か所）:

```php
    /**
     * 件数欄。空欄は 0、数値にならない値・範囲外は null（＝その行を取り込まない）。
     */
```

置き換えた後:

```php
    /**
     * ファイルの行に出てくるビルのうち、台帳で見つかったものの id（設計書 2026-10-01-area-tenant-reimport-design.md §4.3）。
     * ⚠ 判定は importTenants() の「ビル名が空」「台帳に無いビル」と同じ（ここで拾わないビルの行は照合まで来ない）。
     *
     * @param  array<string, AreaBuilding>  $map
     * @return list<int>
     */
    private function buildingIdsInRows(array $rows, array $map): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = AreaBuilding::normalizeName($this->text($row['building_name'] ?? null));
            if ($key !== '' && isset($map[$key])) {
                $ids[$map[$key]->id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * 登録済みのテナント明細の数（見分けのキーごと）。対象のビルの行を 1 本の問い合わせで読む（設計書 §4.3）。
     *
     * ⚠ 退去済みの行も数える（moved_out_on を問わない）。現況の行だけにすると、古いファイルを上げ直したときに
     *   退去したテナントが現況に戻る。
     * ⚠ ビルごと・行ごとに引かない（2000 行で 2000 往復になる。test_tenant_import_does_not_scale_queries_per_row）。
     *
     * @param  list<int>  $buildingIds
     * @return array<string, int>
     */
    private function registeredTenantCounts(array $buildingIds): array
    {
        if ($buildingIds === []) {
            return [];
        }

        $counts  = [];
        $tenants = AreaBuildingTenant::whereIn('area_building_id', $buildingIds)
            ->get(['area_building_id', 'floor', 'room_number', 'name']);

        foreach ($tenants as $tenant) {
            $key          = $this->tenantKey($tenant->area_building_id, $tenant->floor, $tenant->room_number, $tenant->name);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * テナント明細の見分けのキー（設計書 §4.2）。ビル・階・部屋番号・テナント名。業種・状態・確認日・退去日・備考は見ない。
     *
     * ⚠ 部屋番号とテナント名は AreaBuilding::normalizeName() で比べる（全角空白・続く空白・前後の空白の違いは同じ。
     *   英数字の全角・半角と英字の大文字・小文字は区別する。null と空白だけの値はどちらも空欄）。
     * ⚠ 区切り文字でつながず JSON にする。`|` などでつなぐと、名前に区切り文字が入ったとき別の行が同じキーになりうる。
     *   JSON なら階の null（空欄）と 0 も区別できる。
     * ⚠ ファイル側の値は nullableString() で列の長さに切ってから渡す（保存される値と同じ形で比べる）。
     * ⚠ 引数の型（int・?int）を外さない。ドライバが数を文字列で返しても、型の宣言が int にそろえる
     *   （JSON では 1 と "1" が別のキーになる）。
     */
    private function tenantKey(int $buildingId, ?int $floor, ?string $roomNumber, ?string $name): string
    {
        return json_encode([
            $buildingId,
            $floor,
            AreaBuilding::normalizeName($roomNumber),
            AreaBuilding::normalizeName($name),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * 件数欄。空欄は 0、数値にならない値・範囲外は null（＝その行を取り込まない）。
     */
```

- [ ] **Step 5: 緑になることを確かめる**

```bash
APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --do-not-cache-result tests/Feature/Tenant/ 2>&1 | tail -1
```

期待: `OK (533 tests, …)`（Task 1 の 499 本＋新しい 34 本）

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/Tenant/AreaBuildingImportController.php tests/Feature/Tenant/AreaBuildingImportTest.php tests/Feature/Tenant/AreaBuildingTenantReimportTest.php
```

```bash
git commit -q -F - <<'EOF'
fix: 周辺ビルのテナント明細を上げ直しても登録済みの行を二重にしない

ビル・階・部屋番号・テナント名が同じ行（退去済みも含む）は、登録済みの行の数までスキップし、書き換えない。
完了のメッセージに「登録済みのためスキップ N 件」を足し、現況テナント数にはスキップした行のビルも並べる。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

```bash
git status --porcelain; git log --oneline -1
```

---

## Task 3: 取込の画面の注意書き

**Files:**
- Modify: `tests/Feature/Tenant/AreaBuildingImportTest.php`（`test_the_screen_warns_about_duplicate_tenant_imports` を置き換える）
- Modify: `resources/views/tenant/area-buildings/import.blade.php:56-58`

**Interfaces:**
- Consumes: Task 1 の `IMPORT_URL`・`manager()`

- [ ] **Step 1: テストを書き換える**

**3-B** 置き換える前（`tests/Feature/Tenant/AreaBuildingImportTest.php`。ちょうど 1 か所）:

```php
    /** 画面にも「2 回取り込むと二重になる」注意書きを出す */
    public function test_the_screen_warns_about_duplicate_tenant_imports(): void
    {
        $html = $this->actingAs($this->manager())->get(self::IMPORT_URL)->getContent();

        $this->assertStringContainsString('同じファイルを 2 回取り込むと行が二重になります', $html);
    }
```

置き換えた後:

```php
    /**
     * テナント明細の注意書き（設計書 2026-10-01-area-tenant-reimport-design.md §4.6）。
     * ⚠ 全文で、テナント明細のときだけ出る <p> の中を見る（部分一致だと、ほかの場所の同じ語に当たる。Bug #43）。
     * ⚠ 2026-10-01 までの「同じファイルを 2 回取り込むと行が二重になります」は、もう事実でないので出さない。
     */
    public function test_the_screen_explains_that_registered_tenant_rows_are_skipped(): void
    {
        $html = $this->actingAs($this->manager())->get(self::IMPORT_URL)->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match('/<p class="mt-3 text-xs text-gray-500" x-show="kind === \'tenants\'">(.*?)<\/p>/su', $html, $m),
            'テナント明細の注意書きが見つからない'
        );
        $this->assertSame(
            'テナント明細は、台帳に既にあるビル名の行だけを取り込みます。台帳に無いビルは作成しません。'
            . '登録済みと同じ行（ビル・階・部屋番号・テナント名が同じ。退去済みの行も含む）は取り込まずにスキップします。'
            . '業種・状態が違っても書き換えないので、直すときは詳細画面から直してください。'
            . '取込後に表示される件数と現況テナント数を確認してください。',
            trim(preg_replace('/\s+/u', ' ', $m[1]))
        );
        $this->assertStringNotContainsString('行が二重になります', $html);
    }
```

- [ ] **Step 2: 直す前の画面で赤になることを確かめる**

```bash
APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --do-not-cache-result --filter test_the_screen_explains_that_registered_tenant_rows_are_skipped tests/Feature/Tenant/AreaBuildingImportTest.php 2>&1 | tail -12
```

期待: `Failed asserting that two strings are identical.` で、`+` の行（今の画面）に `<strong class="text-amber-700">同じファイルを 2 回取り込むと行が二重になります</strong>` が出る。`テナント明細の注意書きが見つからない` で落ちたら止める（正規表現が画面の `<p>` に当たっていない）。

- [ ] **Step 3: 注意書きを書き換える**

**3-A** 置き換える前（`resources/views/tenant/area-buildings/import.blade.php`。ちょうど 1 か所）:

```blade
            テナント明細は、台帳に既にあるビル名の行だけを取り込みます。台帳に無いビルは作成しません。
            <strong class="text-amber-700">同じファイルを 2 回取り込むと行が二重になります</strong>（既存行との突合は行いません）。
            取込後に表示される現況テナント数を確認してください。
```

置き換えた後:

```blade
            テナント明細は、台帳に既にあるビル名の行だけを取り込みます。台帳に無いビルは作成しません。登録済みと同じ行（ビル・階・部屋番号・テナント名が同じ。退去済みの行も含む）は取り込まずにスキップします。業種・状態が違っても書き換えないので、直すときは詳細画面から直してください。取込後に表示される件数と現況テナント数を確認してください。
```

⚠ 注意書きは 1 行で書く（行を分けると、日本語の文の間に空白が入って表示される・テストの全文の比べ方とも合わなくなる）。`x-show` と `class` は変えない。

- [ ] **Step 4: 緑になることを確かめる**

```bash
APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --do-not-cache-result tests/Feature/Tenant/AreaBuildingImportTest.php 2>&1 | tail -1
```

期待: `OK (60 tests, …)`

- [ ] **Step 5: コミット**

```bash
git add resources/views/tenant/area-buildings/import.blade.php tests/Feature/Tenant/AreaBuildingImportTest.php
```

```bash
git commit -q -F - <<'EOF'
fix: 周辺ビルの取込の画面の注意書きを上げ直しのスキップに合わせる

「同じファイルを 2 回取り込むと行が二重になります」は事実でなくなったので、
登録済みと同じ行はスキップし書き換えないことを書く。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

```bash
git status --porcelain; git log --oneline -1
```

---

## Task 4: 全件テスト・コンパイル済みビューの lint・CSS

**Files:** なし

- [ ] **Step 1: 全件テスト**

```bash
APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --do-not-cache-result 2>&1 | tail -3
```

期待: `OK (3178 tests, 22737 assertions)`（Task 0 の 3144 本＋新しい 34 本）

- [ ] **Step 2: コンパイル済みビューの lint**（⚠ `view:cache` の成功表示だけでは足りない。Bug #21 / #26 / #30。コンパイル先は scratchpad にして worktree を汚さない。`SP` はこのセッションの scratchpad の絶対パス）

```bash
SP=<Scratchpad directory>; mkdir -p "$SP/atr-views"; APP_KEY="base64:$(openssl rand -base64 32)" VIEW_COMPILED_PATH="$SP/atr-views" /opt/homebrew/opt/php@8.3/bin/php artisan view:cache 2>&1 | tail -1
```

ループをそのまま打つとハーネスが断ることがあるので、小さなスクリプトにする（`$SP/atr-lint-views.sh`。scratchpad に置く・コミットしない）:

```sh
#!/bin/sh
# コンパイル済みビューを php -l で確かめる（Bug #21 / #26 / #30）。第 1 引数にコンパイル先のフォルダを渡す
dir="$1"
if [ -z "$dir" ] || [ ! -d "$dir" ]; then
    echo "中断: 第 1 引数にコンパイル先のフォルダを渡す" >&2
    exit 1
fi
n=0
bad=0
for f in "$dir"/*.php; do
    [ -e "$f" ] || continue
    n=$((n + 1))
    if ! /opt/homebrew/opt/php@8.3/bin/php -l "$f" >/dev/null 2>&1; then
        bad=$((bad + 1))
        echo "INVALID: $f"
    fi
done
echo "views=$n invalid=$bad"
```

```bash
sh <Scratchpad directory>/atr-lint-views.sh <Scratchpad directory>/atr-views
```

期待: `views=288 invalid=0`

- [ ] **Step 3: CSS が変わらないこと**（worktree の `public/build` は gitignore 済み。Task 6 でも使う）

```bash
/Users/masanori/site/manage/node_modules/.bin/vite build 2>&1 | /usr/bin/grep -E "assets/app-.*\.(css|js)"
```

期待: `public/build/assets/app-Cz5Vm3yg.css と public/build/assets/app-NiVQbl_Q.js`（本番と同じ名前＝CSS・JS は変わらない）

---

## Task 5: 変異テスト（docs/RULES.md Bug #44 の作法）

**Files:** なし（作業ツリーの中で変えて、書き戻す。記録はこの計画の末尾の「実測記録」）

作法: 先にコミット（Task 4 まで済み）→ 最初にカナリア → 変異 1 つごとに、置き換えがそれぞれ決めた数だけ当たる・作業ツリーが変わったことで着弾を確かめる・`--log-junit` で流す・落ちたテストと**理由の文言**まで記録・元の本文で書き戻したあと作業ツリーが前と同じことを確かめる。実行役 `atr_mutate.py` がこの確認を全部行い、1 つでも食い違えば止まる。

⚠ この worktree の作業ツリーで流す（並行して触る人がいないので隔離の写しは要らない。docs/RULES.md Bug #50）。**流している間は worktree のファイルを触らない**（変異の途中を拾う・実行役の「前と同じか」の確かめが止まる）。書き戻しは `git checkout` でなく元の本文で行う（未コミットの編集を巻き戻さない。Bug #44 の 2026-08-17 の事故）。
⚠ 流すテストは関係する 11 ファイル（実行役の `NARROW`）。全件で流したカナリアと落ちた数が同じであることを Step 4 で確かめる（`NARROW` の外に、取込の画面やコントローラを通るテストが無いことの確かめ）。

`SP` はこのセッションの scratchpad（システムプロンプトの「Scratchpad directory」の絶対パス）。

- [ ] **Step 1: 実行役を置く**（`$SP/atr_mutate.py`。この内容そのまま。コミットしない）

```python
#!/usr/bin/env python3
"""周辺ビルのテナント明細の上げ直し（2026-10-01）の変異テストの実行役。

使い方（docs/RULES.md Bug #44 / #50 の作法）:
    python3 atr_mutate.py --iso <worktree> [ID ...]            # ID を省くと全部。関係するテスト（NARROW）で流す
    python3 atr_mutate.py --iso <worktree> --full [ID ...]     # 全件テストで流す
    python3 atr_mutate.py --iso <worktree> --check             # どの変異も決めた数だけ当たるかだけ見る
    python3 atr_mutate.py --list                               # 定義の一覧だけ

1 つごとに: 前の作業ツリーの状態（git status と git diff）を控える → 置き換えがそれぞれ決めた数（既定 1）だけ当たる →
git diff が変わったことで着弾を確認 → phpunit を --log-junit で流す → 落ちたテストと理由の 1 行目を記録 →
書き戻す → 作業ツリーの状態が前と同じことを確かめる。どこかで食い違えば止まる（無効な測定を集めない）。
書き戻しは元の本文で行う（git checkout を使わない＝未コミットの編集があっても巻き戻さない。2026-08-17 の事故。Bug #44）。
1 つの変異が複数の置き換え（切り取って別の場所へ貼る＝移す）を持てる。置き換えは書いた順に当てる。
"""
import argparse, base64, hashlib, json, os, re, subprocess, sys, xml.etree.ElementTree as ET

# 記録・JUnit・コンパイル済みビューは、この実行役を置いた場所（scratchpad）に作る（worktree の外の .claude/worktrees/ を汚さない）
HERE = os.path.dirname(os.path.abspath(__file__))

CTRL = "app/Http/Controllers/Tenant/AreaBuildingImportController.php"
VIEW = "resources/views/tenant/area-buildings/import.blade.php"

NARROW = [
    "tests/Feature/Tenant/AreaBuildingImportTest.php",
    "tests/Feature/Tenant/AreaBuildingTenantReimportTest.php",
    "tests/Feature/Tenant/AreaBuildingTenantCrudTest.php",
    "tests/Feature/Tenant/AreaBuildingShowTest.php",
    "tests/Feature/Tenant/AreaBuildingCrudTest.php",
    "tests/Feature/ImportControllerOneTimeKeyScanTest.php",
    "tests/Feature/ImportControllerReturnPathScanTest.php",
    "tests/Feature/ImportControllerValidationRedirectScanTest.php",
    "tests/Feature/ImportControllerContractMatchScanTest.php",
    "tests/Feature/SubmitOnceTest.php",
    "tests/Feature/JapaneseValidationMessagesTest.php",
]

# ---- 置き換える前の断片（どれも試作で当たる数を確かめた）----
SKIP_BLOCK = (
    "            // 登録済みと同じ行（ビル・階・部屋番号・テナント名）は、登録済みの行の数までスキップする（書き換えない）。\n"
    "            // ⚠ 階の検査のあとに置く（読めない階の行は「値が不正」に数える）。\n"
    "            // ⚠ この取込で入れた行は残りに足さない（ファイルの中で同じ行が並べば、超えた分はすべて入る。空き区画は並ぶのが普通）。\n"
    "            $tenantKey = $this->tenantKey($building->id, $floor, $roomNumber, $name);\n"
    "            if (($remaining[$tenantKey] ?? 0) > 0) {\n"
    "                $remaining[$tenantKey]--;\n"
    "                $registered++;\n"
    "                // 現況テナント数には並べる（全部スキップしたときも、増えていないことがその場で分かる）\n"
    "                $touched[$building->id] = $building;\n"
    "                continue;\n"
    "            }\n"
    "\n"
)
CREATED_TAIL = (
    "            $created++;\n"
    "            $touched[$building->id] = $building;\n"
    "        }\n"
)
FLOOR_IF = (
    "            if ($floor === false) {\n"
    "                $invalid++;\n"
    "                continue;\n"
    "            }\n"
    "\n"
)
PRELOAD = "        $remaining = $this->registeredTenantCounts($this->buildingIdsInRows($rows, $map));\n"
NOTE_NEW = (
    "            テナント明細は、台帳に既にあるビル名の行だけを取り込みます。台帳に無いビルは作成しません。登録済みと同じ行（ビル・階・部屋番号・テナント名が同じ。退去済みの行も含む）は取り込まずにスキップします。業種・状態が違っても書き換えないので、直すときは詳細画面から直してください。取込後に表示される件数と現況テナント数を確認してください。\n"
)
NOTE_OLD = (
    "            テナント明細は、台帳に既にあるビル名の行だけを取り込みます。台帳に無いビルは作成しません。\n"
    "            <strong class=\"text-amber-700\">同じファイルを 2 回取り込むと行が二重になります</strong>（既存行との突合は行いません）。\n"
    "            取込後に表示される現況テナント数を確認してください。\n"
)
KEY_JSON = (
    "        return json_encode([\n"
    "            $buildingId,\n"
    "            $floor,\n"
    "            AreaBuilding::normalizeName($roomNumber),\n"
    "            AreaBuilding::normalizeName($name),\n"
    "        ], JSON_THROW_ON_ERROR);\n"
)

MUTATIONS = [
    # ---- カナリア（その写しのコードが読まれていれば、取込の画面を開くテストが赤になる）----
    ("C0", [(CTRL, "        return view('tenant.area-buildings.import', [\n", "        return view('tenant.area-buildings.import-canary', [\n")]),
    # ---- 照合を外す・書き込みのあとへ動かす ----
    ("K01", [(CTRL, "            if (($remaining[$tenantKey] ?? 0) > 0) {\n", "            if (false) {\n")]),
    ("K02", [(CTRL, SKIP_BLOCK, ""), (CTRL, CREATED_TAIL, "            $created++;\n            $touched[$building->id] = $building;\n" + SKIP_BLOCK.replace("            // ", "            // (moved) ") + "        }\n")]),
    # ---- 見分けのキー ----
    ("F01", [(CTRL, "            $buildingId,\n", "            0,\n")]),
    ("F02", [(CTRL, "            $floor,\n            AreaBuilding::normalizeName($roomNumber),\n", "            null,\n            AreaBuilding::normalizeName($roomNumber),\n")]),
    ("F03", [(CTRL, "            AreaBuilding::normalizeName($roomNumber),\n", "            '',\n")]),
    ("F04", [(CTRL, "            AreaBuilding::normalizeName($name),\n        ], JSON_THROW_ON_ERROR);\n", "            '',\n        ], JSON_THROW_ON_ERROR);\n")]),
    ("F05", [(CTRL, "            AreaBuilding::normalizeName($roomNumber),\n            AreaBuilding::normalizeName($name),\n", "            (string) $roomNumber,\n            (string) $name,\n")]),
    ("F06", [(CTRL, "            $floor,\n            AreaBuilding::normalizeName($roomNumber),\n", "            (int) $floor,\n            AreaBuilding::normalizeName($roomNumber),\n")]),
    ("F07", [(CTRL, KEY_JSON, "        return implode('|', [\n            $buildingId,\n            $floor,\n            AreaBuilding::normalizeName($roomNumber),\n            AreaBuilding::normalizeName($name),\n        ]);\n")]),
    # 等価（緑が正しい）: SQLite は数を整数で返すので、型の宣言を外しても変わらない（本番のドライバが文字列で返したときの保険。テストでは測れない）
    ("F08", [(CTRL, "    private function tenantKey(int $buildingId, ?int $floor, ?string $roomNumber, ?string $name): string\n", "    private function tenantKey($buildingId, $floor, ?string $roomNumber, ?string $name): string\n")]),
    # ---- 先読み ----
    ("P01", [(CTRL, "        $tenants = AreaBuildingTenant::whereIn('area_building_id', $buildingIds)\n", "        $tenants = AreaBuildingTenant::whereIn('area_building_id', $buildingIds)->whereNull('moved_out_on')\n")]),
    ("P02", [(CTRL, PRELOAD, "        $remaining = [];\n"),
             (CTRL, "            $tenantKey = $this->tenantKey($building->id, $floor, $roomNumber, $name);\n", "            $remaining += $this->registeredTenantCounts([$building->id]);\n            $tenantKey = $this->tenantKey($building->id, $floor, $roomNumber, $name);\n")]),
    ("P03", [(CTRL, "        return array_keys($ids);\n", "        return array_slice(array_keys($ids), 0, 1);\n")]),
    ("P04", [(CTRL, "            if (! is_array($row)) {\n                continue;\n            }\n\n            $key = AreaBuilding::normalizeName($this->text($row['building_name'] ?? null));\n",
                    "            if (! is_array($row)) {\n                continue;\n            }\n\n            $key = $this->text($row['building_name'] ?? null);\n")]),
    # ---- 数で扱う ----
    ("N01", [(CTRL, "                $remaining[$tenantKey]--;\n", "")]),
    ("N02", [(CTRL, CREATED_TAIL, "            $created++;\n            $remaining[$tenantKey] = ($remaining[$tenantKey] ?? 0) + 1;\n            $touched[$building->id] = $building;\n        }\n")]),
    # ---- 検査の順番・切ってから比べる ----
    ("O01", [(CTRL, FLOOR_IF, ""), (CTRL, SKIP_BLOCK, SKIP_BLOCK + FLOOR_IF)]),
    ("L01", [(CTRL, "            $tenantKey = $this->tenantKey($building->id, $floor, $roomNumber, $name);\n", "            $tenantKey = $this->tenantKey($building->id, $floor, $this->text($row['room_number'] ?? null), $this->text($row['name'] ?? null));\n")]),
    # ---- 完了のメッセージ・現況テナント数 ----
    ("T01", [(CTRL, "                $touched[$building->id] = $building;\n                continue;\n", "                continue;\n")]),
    ("M01", [(CTRL, " / 登録済みのためスキップ %d 件", ""), (CTRL, "            $created,\n            $registered,\n", "            $created,\n")]),
    # ---- 取込の画面の注意書き ----
    ("V01", [(VIEW, NOTE_NEW, NOTE_OLD)]),
    ("V02", [(VIEW, NOTE_NEW, NOTE_NEW.replace("取込後に表示される件数と", "取込後に表示される"))]),
]


def git(iso, *args):
    return subprocess.run(["git", "-C", iso, *args], capture_output=True, text=True, errors="replace").stdout


def state(iso):
    # 作業ツリーの状態（未コミットの編集があっても比べられるように、status と diff の両方の指紋を取る）
    return hashlib.sha256((git(iso, "status", "--porcelain") + git(iso, "diff")).encode()).hexdigest()


def run_phpunit(iso, junit, targets, use_junit=True):
    # JUnit は、このスクリプトが手元で起動した PHPUnit の書いたものだけを読む（外から来た XML は読まない）
    env = dict(os.environ, APP_KEY="base64:" + base64.b64encode(os.urandom(32)).decode())
    views = os.path.join(HERE, os.path.basename(iso) + "-views")
    os.makedirs(views, exist_ok=True)
    env["VIEW_COMPILED_PATH"] = views
    php = "/opt/homebrew/opt/php@8.3/bin/php"
    args = [php, "vendor/bin/phpunit", "--do-not-cache-result"] + (["--log-junit", junit] if use_junit else []) + targets
    proc = subprocess.run(args, cwd=iso, env=env,
                          capture_output=True, text=True, errors="replace")
    if not os.path.exists(junit) or os.path.getsize(junit) == 0:
        # JUnit を使わない（--full）か、PHPUnit が JUnit を書き終えずに終わった。画面の出力の「N) Tests\…::…」と次の行から読む。
        # ⚠ 全件でカナリアのように 70 本以上が落ちると、失敗の文（画面の HTML を含む）で JUnit の XML が大きくなり、
        #   phpunit.xml の <ini> が決める memory_limit（512M）を超えて、失敗の一覧を出す前に止まる（2026-10-01 の先測りで実測）。
        #   だから --full では最初から JUnit を使わない
        if os.path.exists(junit):
            os.remove(junit)
        return failures_from_stdout(proc.stdout or "")
    failures = []
    for tc in ET.parse(junit).getroot().iter("testcase"):
        for kind in ("failure", "error"):
            el = tc.find(kind)
            if el is not None:
                lines = [l for l in (el.text or "").strip().splitlines() if l.strip()]
                reason = next((l for l in lines if not l.startswith("Tests\\")), lines[0] if lines else "")
                failures.append({"test": f"{tc.get('class', '').split(chr(92))[-1]}::{tc.get('name')}", "reason": reason[:200]})
    os.remove(junit)
    return failures


def failures_from_stdout(stdout):
    # 「1) Tests\Feature\…\Class::test_name …」の行と、その次の空でない行（理由）を拾う。1 本も拾えなければ None（測れなかった）
    lines = stdout.splitlines()
    failures = []
    for i, line in enumerate(lines):
        m = re.match(r"^\d+\) (Tests\\\S+?::\S+)(.*)$", line)
        if not m:
            continue
        reason = next((l for l in lines[i + 1:i + 6] if l.strip()), "")
        cls, name = m.group(1).split("::", 1)
        failures.append({"test": f"{cls.split(chr(92))[-1]}::{name}{m.group(2)}", "reason": reason[:200]})
    if failures or re.search(r"^OK \(", stdout, re.M):
        return failures
    return None


def edits_of(mid):
    for m, edits in MUTATIONS:
        if m == mid:
            return edits
    sys.exit(f"{mid}: 定義に無い")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--iso")
    ap.add_argument("--list", action="store_true")
    ap.add_argument("--check", action="store_true")
    ap.add_argument("--full", action="store_true")
    ap.add_argument("ids", nargs="*")
    args = ap.parse_args()

    if args.list:
        for mid, edits in MUTATIONS:
            print(f"{mid}\t" + ", ".join(sorted({e[0] for e in edits})))
        return

    iso = os.path.abspath(args.iso)
    if args.check:
        bad = 0
        for mid, edits in MUTATIONS:
            texts = {}
            for e in edits:
                path, old, new = e[0], e[1], e[2]
                want = e[3] if len(e) > 3 else 1
                src = texts.get(path) or open(os.path.join(iso, path), encoding="utf-8").read()
                n = src.count(old)
                if n != want:
                    bad += 1
                    print(f"{mid}: {path} の置き換えが {n} か所（{want} でない）")
                texts[path] = src.replace(old, new)
        print(f"変異 {len(MUTATIONS)} 通り・当たらないもの {bad} 件")
        sys.exit(1 if bad else 0)

    targets = [] if args.full else NARROW
    out = os.path.join(HERE, os.path.basename(iso) + ("-full" if args.full else "-narrow") + "-results.jsonl")
    ids = args.ids or [mid for mid, _ in MUTATIONS]
    for mid in ids:
        edits = edits_of(mid)
        before = state(iso)
        paths = sorted({e[0] for e in edits})
        originals = {p: open(os.path.join(iso, p), encoding="utf-8").read() for p in paths}
        try:
            texts = dict(originals)
            for e in edits:
                path, old, new = e[0], e[1], e[2]
                want = e[3] if len(e) > 3 else 1
                n = texts[path].count(old)
                if n != want:
                    sys.exit(f"{mid}: {path} の置き換えが {n} か所（{want} でない）。止める")
                texts[path] = texts[path].replace(old, new)
            for p in paths:
                open(os.path.join(iso, p), "w", encoding="utf-8").write(texts[p])
            if state(iso) == before:
                sys.exit(f"{mid}: 置き換えたのに作業ツリーが変わっていない（当たっていない）。止める")
            stat = git(iso, "diff", "--stat", "--", *paths).strip().splitlines()
            junit = os.path.join(HERE, f"junit-{os.path.basename(iso)}-{mid}.xml")
            failures = run_phpunit(iso, junit, targets, use_junit=not args.full)
        finally:
            for p, src in originals.items():
                open(os.path.join(iso, p), "w", encoding="utf-8").write(src)
        if state(iso) != before:
            sys.exit(f"{mid}: 書き戻したのに作業ツリーが前と違う。止める")
        record = {"id": mid, "files": paths, "diff": stat[-1] if stat else "", "failures": failures}
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

⚠ 読む XML は、この実行役が手元で起動した PHPUnit の `--log-junit` が書いたものだけ（外から来た XML は読まない）。
⚠ 実行役は理由の **1 行目**しか記録しない。期待と違って見えたら、その変異だけを当てて全文を見る。
⚠ 全件で 70 本以上が落ちると（カナリアなど）、失敗の文で JUnit の XML が大きくなり、`phpunit.xml` の `memory_limit`（512M）を超えて PHPUnit が XML を書き終えずに止まる（2026-10-01 の先測りで実測。`Allowed memory size of 536870912 bytes exhausted … JunitXmlLogger.php`）。実行役はそのとき画面の出力の「N) Tests\…」から読む（`failures_from_stdout()`）。

- [ ] **Step 2: どの変異も決めた数だけ当たることを確かめる**

```bash
SP=<Scratchpad directory>; git -C /Users/masanori/site/manage/.claude/worktrees/area-tenant-reimport status --porcelain; python3 "$SP/atr_mutate.py" --iso /Users/masanori/site/manage/.claude/worktrees/area-tenant-reimport --check
```

期待: `status` は何も出さない・`変異 23 通り・当たらないもの 0 件`

- [ ] **Step 3: 変異を流す**（Bash の `run_in_background` で起動し、終わりの知らせを待つ。途中で作業ツリーやログを覗いて判断しない。23 通り × 約 40 秒）

```bash
SP=<Scratchpad directory>; python3 "$SP/atr_mutate.py" --iso /Users/masanori/site/manage/.claude/worktrees/area-tenant-reimport > "$SP/atr-mut.log" 2>&1
```

- [ ] **Step 4: カナリアを全件で流す**（`NARROW` で足りていることの確かめ）

```bash
SP=<Scratchpad directory>; python3 "$SP/atr_mutate.py" --iso /Users/masanori/site/manage/.claude/worktrees/area-tenant-reimport --full C0 > "$SP/atr-mut-full-c0.log" 2>&1; /usr/bin/grep -E "^== " "$SP/atr-mut-full-c0.log"
```

期待: `== C0 …  落ちたテスト 73 件`（Step 3 の C0 と同じ数）。多ければ、`NARROW` の外で落ちたテストのファイルを `NARROW` に足して Step 3 をやり直す。

- [ ] **Step 5: 表と突き合わせる**（落ちたテストの集合と本数・理由の文言まで。表の「期待」は 2026-10-01 の先測りの実測）

```bash
SP=<Scratchpad directory>; /usr/bin/grep -E "^== " "$SP/atr-mut.log"; git -C /Users/masanori/site/manage/.claude/worktrees/area-tenant-reimport status --porcelain
```

`R::` は `AreaBuildingTenantReimportTest::`、`I::` は `AreaBuildingImportTest::` の略。

| ID | 変異 | 期待（落ちるテストと理由の 1 行目）|
|---|---|---|
| C0 | カナリア（取込の画面のビュー名を存在しない名前にする） | 73 本（AreaBuildingImportTest 38・AreaBuildingTenantReimportTest 34・AreaBuildingCrudTest 1）。理由の多くは `Expected response status code [200] but received 500.` |
| K01 | 照合を外す（`if (false)`） | 21 本（下の「K01・K02 の 21 本」）。理由は `上げ直しで行が二重になった`・`Failed asserting that 2 is identical to 1.` など、行が増えたこと |
| K02 | スキップの判定を `create()` のあとへ動かす（書き込んでからスキップに数える） | 21 本（下の「K01・K02 の 21 本」）。理由は `上げ直しで行が二重になった`・`Failed asserting that 2 is identical to 1.` など、行が増えたこと |
| F01 | キーからビルを外す（`0`） | 2 本: `R::test_the_building_is_part_of_the_key_when_one_file_has_two_buildings`（`登録済みのビルの行が入った`）／`R::test_a_blank_floor_and_floor_zero_are_different`（`Failed asserting that two arrays are identical.`） |
| F02 | キーから階を外す（`null`） | 2 本: `R::test_each_key_element_tells_rows_apart with data set "階が違う"`（`1 つの要素が違う行をスキップした`）／`R::test_a_blank_floor_and_floor_zero_are_different`（`Failed asserting that two arrays are identical.`） |
| F03 | キーから部屋番号を外す（`''`） | 3 本: `R::test_each_key_element_tells_rows_apart with data set "部屋番号が違う"`（`1 つの要素が違う行をスキップした`）／`R::test_width_and_case_differences_are_not_ignored with data set "部屋番号の数字が全角"`（`Failed asserting that 1 is identical to 2.`）／`R::test_width_and_case_differences_are_not_ignored with data set "部屋番号の英字が全角"`（`Failed asserting that 1 is identical to 2.`） |
| F04 | キーからテナント名を外す（`''`） | 3 本: `R::test_each_key_element_tells_rows_apart with data set "名前が違う"`（`1 つの要素が違う行をスキップした`）／`R::test_width_and_case_differences_are_not_ignored with data set "名前の英字が小文字"`（`Failed asserting that 1 is identical to 2.`）／`R::test_a_vacancy_that_got_a_tenant_is_added_as_a_new_row`（`Failed asserting that 1 is identical to 2.`） |
| F05 | 正規化を外す（`(string)` で生の値を比べる） | 5 本: `R::test_whitespace_differences_are_ignored with data set "部屋番号の前後に全角空白"`（`空白の違いだけの行を別の行として入れた`）／`R::test_whitespace_differences_are_ignored with data set "名前の間が全角空白"`（`空白の違いだけの行を別の行として入れた`）／`R::test_whitespace_differences_are_ignored with data set "名前の間に空白が続く"`（`空白の違いだけの行を別の行として入れた`）／`R::test_a_blank_only_value_matches_a_blank with data set "登録済みは null・ファイルは全角空白だけ"`（`Failed asserting that 2 is identical to 1.`）／`R::test_a_blank_only_value_matches_a_blank with data set "登録済みは全角空白だけ・ファイルは空欄"`（`Failed asserting that 2 is identical to 1.`） |
| F06 | 階を `(int)` にする（null と 0 が同じになる） | 1 本: `R::test_a_blank_floor_and_floor_zero_are_different`（`Failed asserting that two arrays are identical.`） |
| F07 | JSON をやめて `implode('\|', …)` でつなぐ | 1 本: `R::test_a_separator_inside_a_value_does_not_merge_two_rows`（`区切り文字の位置だけが違う別の行をスキップした`） |
| F08 | `tenantKey()` の引数の型（`int`・`?int`）を外す（等価・緑が正しい） | 0 本（緑） |
| P01 | 退去済みの行を先読みから除く（`whereNull('moved_out_on')`） | 1 本: `R::test_moved_out_rows_are_matched_and_stay_moved_out`（`退去済みの行と同じ行が現況として入った`） |
| P02 | 先読みを行ごとにする（`registeredTenantCounts([$building->id])` を行の中で） | 2 本: `I::test_tenant_import_does_not_scale_queries_per_row`（`20 行の取込で area_building 系のクエリが 43 本。行ごとにビルを引き直している`）／`R::test_identical_rows_in_one_file_are_all_added_when_none_is_registered`（`Failed asserting that 1 is identical to 2.`） |
| P03 | 先読みの対象をファイルの最初のビルだけにする | 2 本: `R::test_uploading_the_same_file_again_skips_every_row`（`上げ直しで行が二重になった`）／`R::test_the_building_is_part_of_the_key_when_one_file_has_two_buildings`（`登録済みのビルの行が入った`） |
| P04 | 先読みの対象を集めるときにビル名を正規化しない | 1 本: `R::test_building_name_spacing_differences_still_match`（`Failed asserting that 2 is identical to 1.`） |
| N01 | 残りを減らさない（あるかないかだけで見る） | 2 本: `R::test_rows_beyond_the_registered_count_are_added`（`Failed asserting that 1 is identical to 2.`）／`R::test_each_registered_row_absorbs_one_identical_row`（`Failed asserting that 2 is identical to 3.`） |
| N02 | この取込で入れた行を残りに足す | 1 本: `R::test_identical_rows_in_one_file_are_all_added_when_none_is_registered`（`Failed asserting that 1 is identical to 2.`） |
| O01 | 階の検査を照合のあとへ動かす | 1 本: `R::test_an_unreadable_floor_is_invalid_even_if_the_rest_matches`（`Failed asserting that '取込が完了しました。テナント登録 0 件 / 登録済みのためスキップ 1 件 / ビル名が空で`） |
| L01 | 切る前の値でキーを作る | 1 本: `R::test_long_values_are_compared_after_truncation`（`Failed asserting that 2 is identical to 1.`） |
| T01 | 現況テナント数からスキップした行のビルを外す | 3 本: `I::test_repeated_tenant_import_reports_the_current_total`（`Failed asserting that '取込が完了しました。テナント登録 0 件 / 登録済みのためスキップ 1 件 / ビル名が空で`）／`R::test_uploading_the_same_file_again_skips_every_row`（`Failed asserting that '取込が完了しました。テナント登録 0 件 / 登録済みのためスキップ 3 件 / ビル名が空で`）／`R::test_moved_out_rows_are_matched_and_stay_moved_out`（`Failed asserting that '取込が完了しました。テナント登録 0 件 / 登録済みのためスキップ 1 件 / ビル名が空で`） |
| M01 | 完了のメッセージから「登録済みのためスキップ N 件」を外す | 35 本（`I::test_repeated_tenant_import_reports_the_current_total` と R の全 34 本）。理由は完了のメッセージが `登録済みのためスキップ` を含まない |
| V01 | 注意書きを古い文に戻す | 1 本: `I::test_the_screen_explains_that_registered_tenant_rows_are_skipped`（`Failed asserting that two strings are identical.`） |
| V02 | 注意書きの「件数と」を消す | 1 本: `I::test_the_screen_explains_that_registered_tenant_rows_are_skipped`（`Failed asserting that two strings are identical.`） |

K01・K02 の 21 本（同じ集合）: `I::test_repeated_tenant_import_reports_the_current_total`、`R::test_uploading_the_same_file_again_skips_every_row`、`R::test_only_the_new_rows_of_an_overlapping_file_are_added`、`R::test_the_building_is_part_of_the_key_when_one_file_has_two_buildings`、`R::test_rows_beyond_the_registered_count_are_added`、`R::test_each_registered_row_absorbs_one_identical_row`、`R::test_moved_out_rows_are_matched_and_stay_moved_out`、`R::test_a_skipped_row_does_not_overwrite_the_registered_row`、`R::test_whitespace_differences_are_ignored with data set "部屋番号の前後に全角空白"`、`R::test_whitespace_differences_are_ignored with data set "名前の間が全角空白"`、`R::test_whitespace_differences_are_ignored with data set "名前の間に空白が続く"`、`R::test_a_blank_only_value_matches_a_blank with data set "登録済みは null・ファイルは全角空白だけ"`、`R::test_a_blank_only_value_matches_a_blank with data set "登録済みは全角空白だけ・ファイルは空欄"`、`R::test_long_values_are_compared_after_truncation`、`R::test_building_name_spacing_differences_still_match`、`R::test_floor_notation_differences_are_the_same_floor with data set "3F"`、`R::test_floor_notation_differences_are_the_same_floor with data set "３階"`、`R::test_floor_notation_differences_are_the_same_floor with data set "B1"`、`R::test_floor_notation_differences_are_the_same_floor with data set "地下1階"`、`R::test_the_summary_counts_every_kind_of_row`、`R::test_rows_registered_on_the_screen_are_matched`

期待と違ったら: 落ちたテストの集合・理由を記録し、①テストの穴なら、穴を塞ぐテストを足してから（直す前に赤・直した後に緑）、その変異を当て直す ②変異の当て方の誤りなら直して当て直す。どちらも「実測記録」に書く。`status` は最後に何も出さない。

---

## Task 6: ローカルの実ブラウザ確認

**Files:** なし（使い捨てのログイン用ルートは**コミットしない**。確認のあと必ず戻す）

使い捨ての SQLite ＋ `artisan serve` ＋ Playwright MCP（画面が見えている状態）。設計書 §5.5。
⚠ `preview_start` は使わない（main repo の launch.json を解決して実 MySQL に当たる）。
⚠ ブラウザでパスワードを入力しない。ログインは使い捨てのログイン用ルートで入る。
⚠ Playwright MCP が読み書きできるのは `/Users/masanori/site/manage` の下だけ（scratchpad は拒否される）。取り込む Excel とスクリーンショットは `.playwright-mcp/`（gitignore 済み）に置き、確認のあと消す。ファイルの選択は `<label>` か `locator.setInputFiles(<パス>)` で行う。
⚠ キャッシュとセッションは本番と同じ file にする（`CACHE_STORE=file`。`array` だと `artisan serve` は要求ごとに別のプロセスなので、Bug #69 の鍵を覚えず取込が断られる）。
⚠ Task 4 Step 3 の `vite build` が済んでいること（worktree の `public/build` が無いと画面の CSS・JS が読めない）。

- [ ] **Step 1: 使い捨ての環境**（環境変数は毎回 `source` する）

```bash
SP=<Scratchpad directory>; DB="$SP/atr-browser.sqlite"; rm -f "${DB:?}"; : > "$DB"; printf 'export APP_KEY="base64:%s" APP_ENV=local APP_DEBUG=true DB_CONNECTION=sqlite DB_DATABASE="%s" QUEUE_CONNECTION=sync MAIL_MAILER=log CACHE_STORE=file SESSION_DRIVER=file\n' "$(openssl rand -base64 32)" "$DB" > "$SP/atr-env.sh"; ls /Users/masanori/site/manage/.claude/worktrees/area-tenant-reimport/bootstrap/cache/; lsof -nP -iTCP:8769 -sTCP:LISTEN | head -2
```

期待: `bootstrap/cache/` に `config.php` が無い（あると環境変数が効かない。`packages.php`・`services.php` はあってよい）・8769 番を掴んでいるプロセスが無い（あれば別の番号にして、下の URL も替える）。

`$SP/seed-atr.php`（scratchpad に置く・コミットしない。**接続先が scratchpad の SQLite でなければ止まる**安全装置つき。第 1 引数にアプリの根を渡す）:

```php
<?php
// 使い捨ての SQLite に、周辺ビルのテナント明細の上げ直しの確認用のデータを入れる（scratchpad に置く・コミットしない）
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AreaBuilding;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;

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

(new DepartmentSeeder())->run();

$user = new User();
$user->forceFill([
    'name' => '管理 一郎', 'email' => 'm0001@example.invalid',
    'role' => UserRole::Manager->value, 'status' => UserStatus::Active->value,
    // ブラウザでは使わない（使い捨てのログイン用ルートで入る）
    'password' => bin2hex(random_bytes(16)), 'must_change_password' => false,
])->save();
$user->departments()->attach(Department::where('code', 'tenant')->value('id'));

AreaBuilding::create(['name' => '上げ直しビル']);
AreaBuilding::create(['name' => '追加ビル']);

echo "user id: {$user->id} / cache: " . config('cache.default') . "\n";
```

取り込む Excel（`$SP/make-atr-xlsx.php`。worktree の PhpSpreadsheet で書く。scratchpad に置く・コミットしない）:

```php
<?php
// 周辺ビルのテナント明細の取込の確認用の Excel を書く（.playwright-mcp/ に置く。確認のあと消す）
require ($argv[1] ?? '') . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$dir = '/Users/masanori/site/manage/.playwright-mcp';
@mkdir($dir, 0777, true);
$header = ['ビル名', '階', '部屋番号', 'テナント名', '業種', '状態'];
$rows   = [
    ['上げ直しビル', '1F', '101', '甲商店', '飲食', '営業中'],
    ['上げ直しビル', '2F', '201', '乙商店', '小売', '営業中'],
    ['上げ直しビル', '3F', '', '', '', '空室'],
];
$files = [
    'atr-tenants-3.xlsx' => $rows,
    'atr-tenants-4.xlsx' => array_merge($rows, [['追加ビル', '1F', '101', '丙商店', '飲食', '営業中']]),
];
foreach ($files as $name => $data) {
    $book  = new Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->fromArray(array_merge([$header], $data), null, 'A1', true);
    (new Xlsx($book))->save("{$dir}/{$name}");
}
echo "ok\n";
```

```bash
SP=<Scratchpad directory>; WT=/Users/masanori/site/manage/.claude/worktrees/area-tenant-reimport; cd "$WT" && source "$SP/atr-env.sh" && /opt/homebrew/opt/php@8.3/bin/php artisan migrate --force 2>&1 | tail -2 && /opt/homebrew/opt/php@8.3/bin/php "$SP/seed-atr.php" "$WT" && /opt/homebrew/opt/php@8.3/bin/php "$SP/make-atr-xlsx.php" "$WT"
```

期待: `user id: 1 / cache: file` と `ok`。⚠ `migrate` は `source` した環境変数で使い捨ての SQLite に向く（worktree に `.env` は無い）。main repo では流さない。

- [ ] **Step 2: 使い捨てのログイン用ルートと開発サーバ**（`routes/web.php` の末尾に一時的に足す。**コミットしない**）

```php
// ⚠ 使い捨て（ローカルの実ブラウザ確認だけ。コミットしない）
Route::get('/_dev/login-as/{id}', function (string $id) {
    abort_unless(app()->environment('local'), 404);
    \Illuminate\Support\Facades\Auth::loginUsingId((int) $id);
    return redirect(request('to', '/tenant/area-buildings/import'));
});
```

開発サーバを Bash の `run_in_background` で起動する:

```bash
SP=<Scratchpad directory>; cd /Users/masanori/site/manage/.claude/worktrees/area-tenant-reimport && source "$SP/atr-env.sh" && /opt/homebrew/opt/php@8.3/bin/php artisan serve --host=127.0.0.1 --port=8769
```

- [ ] **Step 3: 見ること**（Playwright で `http://127.0.0.1:8769/_dev/login-as/1?to=/tenant/area-buildings/import` を開くと取込の画面に着く。操作は画面で行う: 取込の種類で「テナント明細」を選び、ファイルを選び、シートと見出しの行・列の対応を確かめてプレビューし、「取り込む」の送信を押す。⚠ 2 回目は**取込の画面を開き直してから**選ぶ＝上げ直し）

| # | 操作 | 見ること |
|---|---|---|
| 1 | 取込の種類で「テナント明細」を選ぶ | 注意書きが Global Constraints の全文で出る・黄色の強調（「二重になります」）が無い |
| 2 | `atr-tenants-3.xlsx` を取り込む | 一覧の画面に「取込が完了しました。テナント登録 3 件 / 登録済みのためスキップ 0 件 / ビル名が空でスキップ 0 件 / 値が不正でスキップ 0 件 / 台帳に無いビルでスキップ 0 行 取込後の現況テナント数: 上げ直しビル 3 件」 |
| 3 | 取込の画面を開き直し、同じ `atr-tenants-3.xlsx` をもう一度取り込む | 「テナント登録 0 件 / 登録済みのためスキップ 3 件 / …」と「取込後の現況テナント数: 上げ直しビル 3 件」・上げ直しビルの詳細画面の入居テナントが 3 行のまま |
| 4 | 取込の画面を開き直し、`atr-tenants-4.xlsx`（追加ビルの 1 行を足したもの）を取り込む | 「テナント登録 1 件 / 登録済みのためスキップ 3 件 / …」と「取込後の現況テナント数: 上げ直しビル 3 件 / 追加ビル 1 件」 |
| 5 | 375px で取込の画面（テナント明細）と一覧の画面（完了の帯）| `main` の横スクロールが無い（`document.querySelector('main').scrollWidth === document.querySelector('main').clientWidth`）・注意書きと完了の帯が文字の途中で割れずに折り返す |
| 6 | ここまでの全部 | `browser_console_messages`（`level: warning`・`all: true`）が 0 件 |

- [ ] **Step 4: 片づけ**（必ず行う）

```bash
SP=<Scratchpad directory>; WT=/Users/masanori/site/manage/.claude/worktrees/area-tenant-reimport; git -C "$WT" checkout -- routes/web.php && rm -f "${SP:?}/atr-browser.sqlite" && for f in atr-tenants-3.xlsx atr-tenants-4.xlsx; do rm -f "/Users/masanori/site/manage/.playwright-mcp/${f:?}"; done; git -C "$WT" status --porcelain; lsof -nP -iTCP:8769 -sTCP:LISTEN | head -2
```

開発サーバは起動した Bash を止める（TaskStop）。`.playwright-mcp/` に溜まる `page-<UTC 時刻>.yml`・`console-<UTC 時刻>.log` は**今回の時刻の分だけ**消す（ほかのセッションのものは消さない）。`status` は何も出さない・8769 番を掴んでいるものが無いこと。worktree の `public/build` は gitignore 済み（残してよい）。結果を「実測記録」に書く（Task 8 でコミット）。

---

## Task 7: 独立レビュー

**Files:** 指摘に応じて

- [ ] **Step 1: レビューを頼む**（Agent・`general-purpose`・モデルは sonnet 以上。コードは変えさせない。指摘には再現の手順を付けさせる）

頼む内容（そのまま渡す）:

> 周辺ビルのテナント明細の上げ直しで二重にしない改修を、欠陥を見つける目でレビューしてください（説明ではなく欠陥の指摘がほしい）。
> 対象: worktree `/Users/masanori/site/manage/.claude/worktrees/area-tenant-reimport` の `git diff 13.x...HEAD`（アプリとテスト）。
> 設計書 `docs/superpowers/specs/2026-10-01-area-tenant-reimport-design.md` と計画 `docs/superpowers/plans/2026-10-01-area-tenant-reimport.md` を読んでから見てください。
> 見てほしいこと: ①設計書との食い違い ②見分けのキー（要素の取り違え・切る前の値で比べている・正規化の範囲・階の null と 0・型の宣言）③先読み（ファイルに出るビルを集める判定が importTenants() と食い違う経路・退去済み・削除したビル・問い合わせの数）④数で扱う（残りの減らし方・この取込で入れた行）⑤検査の順番（階の検査・ビル名が空・台帳に無いビルとの関係）⑥完了のメッセージと現況テナント数 ⑦取込の画面の注意書き ⑧テストが緑のまま壊せる形が残っていないか（Bug #43・#44・#47・#48 の型。計画の Task 5 の表で緑の F08 の扱いも）⑨文書の誤り。
> 決まり: コードは変えない（探りは scratchpad のコピーか、テストを一時的に足して流し、元に戻す）。`.env` は読まない。テストは worktree の中で `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --do-not-cache-result <ファイル…>`。
> 報告: 指摘ごとに 重さ（Critical / Important / Minor）・場所・再現の手順（落ちるテストか、実行した探りとその出力）・直し方の案。推測だけの指摘は「未実測」と書いてください。

- [ ] **Step 2: 指摘を 1 つずつ実測してから直す**（実測で再現しないものは理由を書いて見送る）
  - 直すたびに、落ちるテストを先に足し（直す前に赤・直した後に緑を確かめる）、関係するテストを流してコミットする
  - 本番のコードを変えたら、その場所に当たる変異を `atr_mutate.py` で当て直す（Task 5 の手順）
  - 最後に全件テストとコンパイル済みビューの lint（Task 4 の Step 1・2）をやり直す
  - 指摘・実測・対応を「実測記録」に書く

---

## Task 8: 記録

**Files:**
- Modify: `docs/RULES.md`（Bug の行を 1 つ足す）
- Modify: `docs/BACKLOG.md`（この作業の節・「契約の上げ直しで二重にしない」の範囲外の 1 行に矢印・完了状況）
- Modify: この計画（実測記録）

数字（本数・変異・ブラウザ）は Task 4〜7 の実測に合わせる。下の文は試作の実測で書いてあるので、違えば直す。日付は記録を書く日に合わせる。`CLAUDE.md` の Top traps には足さない（設計書 §4.8）。⚠ `CLAUDE.md` の「全 70 件の詳細バグカタログ」の件数は、Bug を足したら合わせる（Step 3）。

- [ ] **Step 1: Bug の番号を最新の `13.x` で数え直す**（別の会話が先に RULES へ足していることがある）

```bash
git -C /Users/masanori/site/manage log --oneline -1 13.x; git -C /Users/masanori/site/manage show 13.x:docs/RULES.md | /usr/bin/grep -c '^| [0-9]'; /usr/bin/grep -c '^| [0-9]' docs/RULES.md; git merge-base --is-ancestor 13.x HEAD && echo 'ahead-of-13.x'
```

期待（2026-10-01 時点）: `13.x` の最後のコミット・`70`・`70`・`ahead-of-13.x` → **この作業は Bug #71**。
⚠ `13.x` の数が 70 より大きい、または `ahead-of-13.x` が出ないときは、先に Task 0 Step 1 の手順で `13.x` を取り込み（全件テストを流し直す）、次の番号を使う。下の文の「71」をその番号に読み替える。⚠ 別の会話（`approval-phase3` など）が先に入っていたら `docs/BACKLOG.md`・`docs/RULES.md`・`CLAUDE.md` がぶつかることがある（両方の段落を残す）。

- [ ] **Step 2: `docs/RULES.md` に Bug #71 を足す**（表の最後の行（#70）の後ろ。⚠ 置き換える前の文字列がファイルに 1 か所だけか先に確かめる: `/usr/bin/grep -c -F 'CSS も変わらない。migration はテスト用で本番では流さない） |' docs/RULES.md` が 1）

置き換える前:

```markdown
本番反映は `./deploy.sh`（本番の DB 変更・新しい PHP クラス・依存の変更なし。CSS も変わらない。migration はテスト用で本番では流さない） |

## Postal Code APIs
```

置き換えた後（Bug #71 の行は 1 行。表の中の `\|` は表の区切りでなく文字の `|`）:

```markdown
本番反映は `./deploy.sh`（本番の DB 変更・新しい PHP クラス・依存の変更なし。CSS も変わらない。migration はテスト用で本番では流さない） |
| 71 | 周辺ビル調査のテナント明細の取込で、**同じファイル（一部が重なるファイル）を上げ直すと行が二重に入った**（3 件が 6 件に）。取込の画面にも「同じファイルを 2 回取り込むと行が二重になります（既存行との突合は行いません）」と注意書きを出していた。二重の行は、現況テナント数と調査回の件数を突き合わせる乖離の警告（`AreaBuildingController::divergence()`）に嘘の数字を出させる。Bug #69 の鍵は同じ取込の画面からの 2 回目しか止めない（画面を開き直すと新しい鍵）ので、上げ直しは止まらなかった（2026-10-01 修正） | `AreaBuildingImportController::importTenants()` は行を 1 行ずつ `create()` するだけで、登録済みの行と突き合わせなかった（見分けるキーを決めていなかった） | **登録済みと同じ行（ビル・階・部屋番号・テナント名）は、登録済みの行の数までスキップし、書き換えない**（利用者の決定。業種・状態は見ない・退去済みの行とも突き合わせる＝古いファイルを上げ直しても退去したテナントが現況に戻らない）。行を処理する前に、ファイルに出てくるビルのテナント明細を 1 本の問い合わせで読み（`registeredTenantCounts()`）、キーごとの数を作る。キーは `json_encode([ビル id, 階, normalizeName(部屋番号), normalizeName(テナント名)])`（`tenantKey()`）。⚠ **区切り文字でつながない** — `\|` でつなぐと部屋番号「A\|B」・名前「C」と部屋番号「A」・名前「B\|C」が同じキーになる（変異 F07）。JSON なら階の null（空欄）と 0 も区別できる（`'0'` `'0F'` は 0 として保存される）。⚠ **ファイル側の値は列の長さに切ってから比べる**（保存される値と同じ形。変異 L01）。⚠ **数で扱う** — 空き区画（階だけで部屋番号も名前も空欄）はファイルの中で並ぶのが普通なので、あるかないかだけで見ると 2 行目以降が消える（変異 N01）。この取込で入れた行は残りに足さない（変異 N02）。照合は階の検査のあと（読めない階の行は「値が不正」。変異 O01。⚠ テストの登録済みを 0 階にしておく — 読めない階（false）は int の引数で 0 になるので、照合を前へ動かしたときだけ落ちる）。完了のメッセージに「登録済みのためスキップ N 件」を足し、現況テナント数にはスキップした行のビルも並べる（全部スキップしたときも出る）。取込の画面の注意書きを書き換えた。⚠ **先測りで見つけた穴** — 「別のビルのファイルは入る」のテストは、先読みがファイルに出るビルだけを読むので、キーからビルを外しても緑のままだった（変異 F01）。1 つのファイルに 2 棟が混じり、登録済みでないビルの行を先に置くテストを足した（Bug #44 の型）。⚠ 範囲外: 調査し直して現況を置き換える同期・プレビューにスキップを出すこと・本番に今ある二重の行の片づけ・画面から 1 件ずつ登録するときの重複の確かめ・2 つのタブでほぼ同時に取り込むこと（一意制約が無い）。回帰テスト: `tests/Feature/Tenant/AreaBuildingTenantReimportTest.php`（24 メソッド・34 本）＋ `AreaBuildingImportTest` の 2 本の書き換え。送る部品は `AreaBuildingTestCase` へ移して共用。変異 22 通り＋カナリアがすべて期待どおり（緑は等価の F08＝型の宣言を外す・SQLite は数を整数で返すだけ）（計画書 `docs/superpowers/plans/2026-10-01-area-tenant-reimport.md` の実測記録）。本番反映は `./deploy.sh`（DB 変更・新しい PHP クラス・依存の変更なし。CSS も変わらない） |

## Postal Code APIs
```

- [ ] **Step 3: `CLAUDE.md` の件数**（`全 70 件の詳細バグカタログ` → `全 71 件の詳細バグカタログ`。⚠ `/usr/bin/grep -c -F '全 70 件' CLAUDE.md` が 1 であることを先に確かめる。`docs/RULES.md` の冒頭の見出しにも件数があれば合わせる: `/usr/bin/grep -n -F '#1–70' CLAUDE.md docs/RULES.md`）

- [ ] **Step 4: `docs/BACKLOG.md`**
  - 「契約の上げ直しで二重にしない」の節の「範囲外」の 1 行目（`- 周辺ビルのテナント明細の上げ直しの二重（見分ける手がかりが弱く、作りも別）`）の末尾に `（→ 2026-10-01 に直した。下の「周辺ビルのテナント明細の上げ直しで二重にしない」の節）` を足す
  - 「同名の顧客の紐づけ先を最初に登録された顧客に固定する」の節のあと（`## バックログ完了状況` の前）に、この作業の節を足す。見出しは `## ✅ 周辺ビルのテナント明細の上げ直しで二重にしない — 本番反映待ち`。中身は、契約の上げ直しの節と同じ並び（詳細仕様・実装計画へのリンク・利用者の決定・区分の表（Controller・Blade・テスト・ルート / DB）・直したこと（場面の表: 同じファイルを上げ直す／一部が重なる／別のビルの追加／退去済みと同じ行／業種・状態だけ違う）・要点・範囲外（設計書 §6・§7＋この計画の「範囲外」）・検証（全件・変異・ブラウザ・レビュー））
  - `## バックログ完了状況` に 1 段落（本番反映待ち）

- [ ] **Step 5: この計画の「実測記録」を埋める**（Task 0〜7 の実測。期待と違ったものは理由と対応）

- [ ] **Step 6: コミット**

```bash
git add docs/RULES.md CLAUDE.md docs/BACKLOG.md docs/superpowers/plans/2026-10-01-area-tenant-reimport.md
```

```bash
git commit -q -F - <<'EOF'
docs: 周辺ビルのテナント明細の上げ直しで二重にしない修正の記録を残す

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

```bash
git status --porcelain; git log --oneline -1
```

- [ ] **Step 7: 決定のメモを消す**（設計書が `13.x` に入る前に消すと、メモから設計書へたどれなくなるので、Task 9 Step 1 の早送りのあとに行う）: `~/.claude/projects/-Users-masanori-site-manage/memory/project_area_tenant_reimport_decisions.md` を消し、`MEMORY.md` の該当の 1 行を消す

---

## Task 9: 本番反映（⚠ 承認をもらってから）

**Files:** なし（`13.x` の早送り・本番の読み取り（承認があれば）・本番への反映・読み取りの確認）

⚠ 実行の前に、利用者の承認を本文でもらう（①main repo で `13.x` を早送り ②（任意）反映の前に本番を読み取りだけで数える（Step 2）③`./deploy.sh` ④読み取りだけの確認（ssh での md5 とコンパイル済みビューの lint・ログイン済みの実 Chrome で取込の画面を開く）。本番の DB 変更・ルート変更・新しい PHP クラス・依存の変更は無い＝`composer dump-autoload` は要らない。push はしない）。②は①③と別に承認をもらう（本番の読み取りも明示の承認が要る）。
⚠ この会話が `EnterWorktree` の中にいるときは、main repo への git 操作が拒まれる。先に `ExitWorktree`（`keep`）で main repo に戻る。

- [ ] **Step 1: 早送り**（main repo で）

```bash
cd /Users/masanori/site/manage
```

```bash
git status --porcelain; git checkout 13.x; git merge-base --is-ancestor 13.x worktree-area-tenant-reimport && git merge --ff-only worktree-area-tenant-reimport; git log --oneline -1
```

⚠ `is-ancestor` が失敗したら（`13.x` が進んだ）止める。worktree で `13.x` をマージし（Task 0 の手順）、全件テスト（Task 4）を流してからやり直す。取り込むコミットに本番へまだ出ていない別の作業（`approval-phase3` の DB の SQL など）が含まれるときは、それも一緒に出てよいか利用者に確かめる（`deploy.sh` は `13.x` の全体を送る）。

- [ ] **Step 2: （承認があれば）反映の前に本番を読み取りだけで数える**（設計書 §8 の 2。出すのは id と件数だけ。名前は出さない）

手元に書いたスクリプトを本番の `/bin/sh` に流す（本番の既定の `php` は 7.4 なので 8.3 を明示する。本番にファイルを置かない）:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
use App\Models\AreaBuilding;
use App\Models\AreaBuildingTenant;
$all = AreaBuildingTenant::query()->get(["id", "area_building_id", "floor", "room_number", "name", "moved_out_on"]);
$live = AreaBuilding::pluck("id")->flip();
$groups = [];
$blankRoom = 0; $blankName = 0; $changed = 0; $onDeleted = 0; $perBuilding = [];
foreach ($all as $t) {
    $room = AreaBuilding::normalizeName($t->room_number);
    $name = AreaBuilding::normalizeName($t->name);
    $key = json_encode([(int) $t->area_building_id, $t->floor, $room, $name]);
    $groups[$key][] = $t->id;
    $blankRoom += $room === "" ? 1 : 0;
    $blankName += $name === "" ? 1 : 0;
    $changed += (($t->room_number !== null && $room !== $t->room_number) || ($t->name !== null && $name !== $t->name)) ? 1 : 0;
    $onDeleted += isset($live[$t->area_building_id]) ? 0 : 1;
    $perBuilding[$t->area_building_id] = ($perBuilding[$t->area_building_id] ?? 0) + 1;
}
$dups = array_filter($groups, fn ($ids) => count($ids) > 1);
echo "rows=" . $all->count() . " current=" . $all->whereNull("moved_out_on")->count() . " moved_out=" . $all->whereNotNull("moved_out_on")->count() . PHP_EOL;
echo "buildings_with_rows=" . count($perBuilding) . " max_rows_per_building=" . (count($perBuilding) ? max($perBuilding) : 0) . PHP_EOL;
echo "duplicate_groups=" . count($dups) . " duplicate_rows=" . array_sum(array_map("count", $dups)) . PHP_EOL;
echo "blank_room=" . $blankRoom . " blank_name=" . $blankName . " changed_by_normalize=" . $changed . " rows_on_deleted_buildings=" . $onDeleted . PHP_EOL;
foreach (array_slice($dups, 0, 20) as $ids) { echo "dup ids: " . implode(",", $ids) . PHP_EOL; }
'
SH
```

期待: 4 行（＋二重の組があれば `dup ids:` の行を最大 20 行）。`duplicate_groups` が 0 でなければ、数と id を利用者に伝える（反映は止めない。照合は数で扱うので、今ある二重の行があっても壊れない。消すかは利用者の判断＝範囲外）。

- [ ] **Step 3: 本番へ送る vendor に dev の部品が無いこと**

```bash
ls /Users/masanori/site/manage/vendor/bin/phpunit 2>/dev/null; echo "phpunit-check-done"
```

期待: `ls` は何も出さない（dev の部品が無い）。出たら止める（`deploy.sh` が本番へ送ってしまう）。

- [ ] **Step 4: 反映**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

期待: exit 0・6 段すべて・3 つのキャッシュとも成功。送るアプリのファイルはコントローラ 1 本・ビュー 1 本（`tests/` と `docs/` は rsync の対象外）。CSS・JS の名前は Task 4 Step 3 と同じ＝旧バンドルの削除は 0 件。

- [ ] **Step 5: 読み取りだけの確認**

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && md5 -q app/Http/Controllers/Tenant/AreaBuildingImportController.php resources/views/tenant/area-buildings/import.blade.php && n=0; bad=0; for f in storage/framework/views/*.php; do n=$((n+1)); /usr/local/php/8.3/bin/php -l "$f" >/dev/null 2>&1 || { bad=$((bad+1)); echo "INVALID: $f"; }; done; echo "views=$n invalid=$bad"
SH
```

```bash
md5 -q /Users/masanori/site/manage/app/Http/Controllers/Tenant/AreaBuildingImportController.php /Users/masanori/site/manage/resources/views/tenant/area-buildings/import.blade.php
```

期待: 本番と手元の md5 が 2 本とも一致・`views=…`（本番のビューの数。2026-09-30 の反映では 288）`invalid=0`。

- ログイン済みの実 Chrome（`claude-in-chrome`・読み取りだけ。ファイルは上げない・フォームは送らない）で `https://www.mitsuwat.co.jp/system/manage/index.php/tenant/area-buildings/import` を開き、200・取込の種類で「テナント明細」を選ぶと新しい注意書きが出る・コンソールのエラー 0 件を見る（⚠ URL は `/index.php/` を挟む）。実際の取り込みは利用者が行う

- [ ] **Step 6: 記録**（worktree で BACKLOG の節の見出しを「本番反映済み」にし、本番反映の小節（日時・`13.x` のコミット・Step 2〜5 の結果）を足し、完了状況の段落を「本番反映済み（`13.x` = …）」に直し、この計画の「実測記録」の Task 9 を埋めてコミットする。そのあと main repo で `git merge --ff-only worktree-area-tenant-reimport`（文書だけなので `deploy.sh` は要らない。`docs/` は rsync しない）。Task 8 Step 7 のメモを消す）

```bash
git add docs/BACKLOG.md docs/superpowers/plans/2026-10-01-area-tenant-reimport.md
```

```bash
git commit -q -F - <<'EOF'
docs: 周辺ビルのテナント明細の上げ直しの修正を本番に出した記録を残す

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

⚠ push はしない（利用者の指示があったときだけ）。

---

## 範囲外（BACKLOG に書く。設計書 §6・§7 ＋ 試作で気づいたもの）

- 調査し直して現況を置き換える（ファイルに無い行を退去にする・状態を更新する同期）。空き区画に入居したあとの新しい調査のファイルは、名前が違うので新しい行として入り、空き区画の行は残る（Review Focus の 4 行目。画面から退去日を入れるか削除する）
- プレビューにスキップの見込みを出すこと（登録済みの行をブラウザへ渡す仕組みが要る）
- 本番に今ある二重の行の片づけ（数で扱うので照合は壊れない。数は Task 9 Step 2 で数える）
- 画面から 1 件ずつ登録するときの重複の確かめ
- 2 つのタブでほぼ同時に取り込むこと（照合は読むだけで一意制約が無いので、両方が照合を通りうる。順に届けば 2 つ目はスキップ）
- 同じ区画に同じテナントが入り直したとき（退去済みの行と同じキーになるのでスキップする。画面から追加する）
- 英数字の全角・半角や英字の大文字・小文字の表記ゆれを同じとみなすこと（ビル名の突き合わせと同じく区別する）
- 設計書 §7 の読み取り（直さない）: `nullableString()` は `trim()`（半角の空白だけ）なので、全角空白だけのセルは null でなく `'　'` のまま保存される ／ 取込のプレビュー（JS）はサーバの登録済みの行を見ない
- 型の宣言（`tenantKey()` の `int`・`?int`）を外す変異（F08）はテストで測れない（SQLite は数を整数で返す。本番のドライバが文字列で返したときの保険）

## 完了の条件

- 全件テストが緑（`OK (3178 tests, 22737 assertions)` 前後。違えば理由を記録）・コンパイル済みビュー `views=288 invalid=0`
- 変異 22 通り＋カナリアがすべて期待どおり（緑は等価の F08 だけ。違ったものは理由を調べて対応し、記録した）
- ローカルの実ブラウザの 6 項目がすべて期待どおり
- 独立レビューの指摘に、実測のうえで対応した
- RULES・CLAUDE.md・BACKLOG・この計画に記録した
- （承認のあと）本番に反映し、読み取りで確かめた

---

## 実測記録

### 試作の先測り（2026-10-01。この計画を書くときに測った）

この worktree の作業ツリーにこの計画のコード（Task 1〜3）を当てて測り、測り終えたあと元に戻した。

- 試作の編集は scratchpad の `atr_edits.py`（Task 1〜3 の置き換えを ID ごとに持ち、ちょうど 1 か所ずつ当たることを確かめてから当てる）。この計画の「置き換える前／後」はそこから差し込んだ（手で写していない）
- 測り終えたあと、作業ツリーを `b6e0fa49`＋設計書のコミットの状態に戻し（`git status --porcelain` が空）、計画だけをコミットした
- 上の「試作で確かめたこと」の表が、測った値のすべて

### Task 0: 前提の確認

2026-10-02 に実施。worktree は `c27127a2`・作業ツリーは空。`13.x` が `832d5dc3`（`b6e0fa49` から 29 コミット。テストのメモリの片付け 2 本と決裁 段階3a）へ進んでいたので、Step 1 の手順で取り込んだ（`19a48a51`・衝突なし・この計画で触るファイルと `composer.lock` は変わっていない）。取り込んだあとの全件は **OK (3213 tests, 22877 assertions)**（計画の 3144 本から +69 本）。以降は計画の +34 本の差で突き合わせた。

Task 8 の直前にも `13.x` が `7ae25fa3`（`docs/BACKLOG.md` だけ。決裁 3a を 2026-10-02 に本番へ出した記録）へ進んでいたので取り込んだ（`e9c66863`。コードは変わらないので全件は流し直していない）。これで本番のコードは `832d5dc3` になり、Task 9 で一緒に出る別の作業は無い。

### Task 1〜3: 実装

どれも計画の期待どおり。置き換えは計画の「前／後」を scratchpad のスクリプトで当てた（ちょうど 1 か所ずつ当たることを確かめてから。手で写していない）。

- Task 1（`faee24b9`）: 前後とも `tests/Feature/Tenant/` が OK (499 tests, 3428 assertions)
- Task 2（`e222a070`）: 直す前は 2 ファイルで `Tests: 94, Failures: 35.`（理由はどれも「行が二重になった」か「完了のメッセージに `登録済みのためスキップ` が無い」）→ 直したあと OK (533 tests, 3741 assertions)
- Task 3（`0c313057`）: 直す前は注意書きのテストが `Failed asserting that two strings are identical.`（今の画面の `<strong class="text-amber-700">…</strong>` が出る）→ 直したあと `AreaBuildingImportTest` が OK (60 tests, 576 assertions)

### Task 4: 全件テスト・lint・CSS

- 全件 **OK (3247 tests, 23193 assertions)**（3213＋34。差で予測した値とちょうど一致）
- コンパイル済みビュー **views=294 invalid=0**（計画の 288 は 13.x の取り込み前の数。決裁 3a のビュー 6 本〈`_mail_failure`・`_pager`・`notices/_item`・`notices/index`・`notice-bell`・`mail/approval-notice`〉が増えた）
- `vite build` は `app-C3HUCZpZ.css`・`app-NiVQbl_Q.js`。CSS の名前は計画の `app-Cz5Vm3yg.css` と違うが、注意書きを `19a48a51` の版に一時的に戻してビルドしても同じ `app-C3HUCZpZ.css` になり、main repo の 13.x のビルドともバイトが一致した＝変化は決裁 3a によるもので、この作業は CSS を変えていない（3a は 2026-10-02 に本番へ出たので、本番も `app-C3HUCZpZ.css`）

### Task 5: 変異テスト

- `--check`: 変異 23 通り・当たらないもの 0 件
- 関係する 11 ファイルで流した 23 通りが、落ちたテストの集合と理由の 1 行目まで表とすべて一致（突き合わせは scratchpad の `atr_compare.py`）。緑は等価の F08 だけ
- 全件で流したカナリア C0 も 73 本（`AreaBuildingImportTest` 38・`AreaBuildingTenantReimportTest` 34・`AreaBuildingCrudTest` 1）で、関係する 11 ファイルと同じ集合
- テストは足していない
- 独立レビューの修正のあとに 2 回目を流した（Task 7 の記録）

### Task 6: ローカルの実ブラウザ

6 項目すべて期待どおり。

| # | 結果 |
|---|---|
| 1 | 注意書きが全文どおり・`<strong>` 無し・「二重になります」無し（ページに残る `text-amber-700` 1 つはプレビューの警告件数〈非表示〉で無関係）|
| 2 | 「取込が完了しました。テナント登録 3 件 / 登録済みのためスキップ 0 件 / ビル名が空でスキップ 0 件 / 値が不正でスキップ 0 件 / 台帳に無いビルでスキップ 0 行 取込後の現況テナント数: 上げ直しビル 3 件」|
| 3 | 開き直して同じファイル →「テナント登録 0 件 / 登録済みのためスキップ 3 件 / …」・「上げ直しビル 3 件」・DB の行も詳細画面の入居テナントも 3 行のまま |
| 4 | 追加ビルの 1 行を足したファイル →「テナント登録 1 件 / 登録済みのためスキップ 3 件 / …」・「上げ直しビル 3 件 / 追加ビル 1 件」|
| 5 | 375px で一覧（完了の帯）・取込の画面（注意書き）とも `main` 375＝375・枠の中で折り返す |
| 6 | コンソールの警告・エラー 0 件 |

⚠ 今回の Playwright MCP が読み書きできるのは worktree の下だけだった（main repo の `.playwright-mcp/` は拒否された）。Excel を worktree の `.playwright-mcp/`（gitignore 済み）へ写して使い、確認のあとフォルダごと消した（中身はすべて今回の分）。

### Task 7: 独立レビュー

レビュー役（fable）の判定は「With fixes」。Critical 0・Important 1・Minor 4。

- **I-1（Important）**: テナント名が空欄の退去済みの行（退去日を入れて閉じた空き区画）が、あとの調査で同じ階・部屋がまた空いたときの行を「登録済みのためスキップ」で吸い込む。自分の探りでも再現した（登録済み「3F・空欄・退去日あり」に、次の調査の「3F・空欄・空室」→「登録 0 件 / スキップ 1 件」・現況は増えない。部屋番号 301 でも同じ）。設計書の案 A に関わるので利用者に選んでもらい、**案 B（名前が空欄の行だけは現況の行とだけ照合する）**に決まった（2026-10-02）。
  - テスト（`3af7ca3c`）: `test_a_moved_out_vacancy_does_not_absorb_a_new_vacancy`（3 通り）と `test_only_current_vacancies_absorb_identical_vacancy_rows` は直す前に赤（4 本・「また空いた区画が入らなかった」など）→ 直したあと緑。`test_a_moved_out_named_row_without_a_room_number_is_still_matched` は名前で判定することを守る役（直す前も緑）
  - 注意書き（`5453f0d6`）: 「退去済みの行も含む」のままだと案 B と食い違うので「ただしテナント名が空欄の退去済みの行は除く」を足した（テストの期待の全文を先に直して赤を確かめた）
  - 変異の 2 回目（関係する 11 ファイル・28 通り＝23＋P05〜P09）: **流す前に書いた期待とすべて一致**（C0 78 本・K01/K02 23・P01 2・N01 3・M01 40・P05 4・P06 4・P07 1・P08 3・P09 9。緑は等価の F08 だけ）
  - 全件 **OK (3252 tests, 23239 assertions)**・コンパイル済みビュー views=294 invalid=0
- **Minor（直していない。BACKLOG の範囲外に書いた）**: M-1 変異 F08 は Feature テスト（SQLite）では測れないが、Reflection で文字列の数を渡す Unit テストなら測れる（「テストでは測れない」は言い過ぎだった。RULES の記録はこの言い方にした）／M-2 「画面で登録した行」のテストは HTTP の登録でなくモデルから作っている（レビューの探りで HTTP の経路でも照合することは確かめた）／M-3 ビル名の判定が `buildingIdsInRows()` と `importTenants()` に逐語で 2 回ある（今は一致）／M-4 設計書 §2.5 の「`text-amber-700` はほかに 6 ファイル」は実測 5 ファイル（＋この画面のプレビューの 2 か所）・この計画の「22 通り＋カナリア」と「23 通り（カナリアを含む）」は同じもの
- レビュー役が「判断を控えた」もの（同じ名前のビルが 2 棟あるときの畳み込み・全角空白だけのセル・英数字の全角半角・スキップした行を名指ししない・現況テナント数は 10 棟まで・同時の取込・`JsonException`・先読みを Eloquent で持つ）は、どれも設計書の範囲外か以前からの振る舞いとして、そのままにした

### Task 9: 本番反映

（実行のときに書く）
