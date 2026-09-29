# 契約の上げ直しで二重にしない 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 契約系の 4 経路（テナントの契約・過去契約、賃貸マンションの部屋契約・駐車場契約）の CSV 取込で、登録済みの同じ契約の行を確認画面でスキップし、同じ CSV を上げ直しても契約が二重に入らないようにする。

**Architecture:** 設計書の案 1。4 経路の行の検査に「CSV 内の重複 → 登録済みの照合」を足す。照合はコントローラごとの非公開メソッド（テナントは `findRegisteredContract()` を 2 経路で共用・賃貸マンションは `findRegisteredRoomContract()` / `findRegisteredParkingContract()`）で、見分けのキーは「どこ＋誰＋開始日」（設計書 §4.2）。照合は日付の検査のあと・金額の検査の前に置き（駐車場契約は日付と金額の検査の順を入れ替える）、警告は行の中に貯めて、取り込むと決めた行の分だけ画面の一覧へ移す。確認画面の共通部品 2 つに「すべての行が登録済み」の灰色の文、取込の画面の契約の 4 タブに説明を 1 行ずつ足す。テスト用スキーマの `contracts.customer_id` を本番どおり空欄可にする。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade / PHPUnit 11（worktree の `./vendor/bin/phpunit`）/ SQLite（テスト）/ Playwright MCP（ローカルの実ブラウザ）

**Spec:** `docs/superpowers/specs/2026-09-29-contract-reimport-design.md`（2026-09-29 に利用者が承認。以下「設計書」）

## Global Constraints

- 範囲は契約系の 4 経路（`TenantImportController::executeContract()`・`executePastContract()`・`MansionImportController::executeRoomContract()`・`executeParkingContract()`）と、確認画面の共通部品 2 つ・取込の画面 2 つ・テスト用スキーマ（設計書 §4.1）。物件・区画・顧客・部屋・駐車場・入居者のタブの振る舞いは変えない（共通部品の灰色の文だけは、そのタブで全部スキップのときにも出る）
- 本番の DB 変更・新しい PHP クラス・依存の変更・ルートの変更は無い（`composer dump-autoload` は要らない）。CSS も変わらない（新しい Tailwind のクラスを使わない。試作で `vite build` すると `app-DW1DvPK7.css`・`app-NiVQbl_Q.js`＝本番と同じ名前）
- 見分けのキー（設計書 §4.2）: テナントの 2 経路は 区画の id・顧客の id（テナント名が空欄なら「顧客の無い契約」＝`customer_id` が null）・契約日 ／ 部屋契約は 部屋の id・入居者の id・契約日・入居日 ／ 駐車場契約は 駐車場の id・入居者の id・契約日・開始日。賃貸マンションの日付は空欄どうしだけが同じ（空欄は `whereNull`）。日付は正規化したあとの `Y-m-d` を `whereDate` で比べる。削除した契約とは突き合わせない。登録済みの契約は書き換えない。同じキーの契約が 2 件以上あれば id のいちばん小さいものを理由に出す（`orderBy('id')->first()`）
- 過去契約で顧客がまだ無い（取込で自動作成する予定の）行は照合しない（`$customerId` に null を渡すと「顧客の無い契約」と取り違える。設計書 §4.3）
- 行の検査の順番（設計書 §4.4）: 必須 → 物件 → 区画（部屋・駐車場）→ 顧客（入居者）→ 日付（見分けに使わない賃料開始日・解約日・退去日・終了日も全部）→ CSV 内の重複 → 登録済みの照合 → 金額 → 取り込むと決める。駐車場契約は日付の検査を金額の検査の前へ移す。⇒ 登録済みの行は金額に誤りがあってもスキップ、日付に誤りがあればエラー
- CSV 内の重複（設計書 §4.5）: 見分けのキーが同じ 2 つ目からをエラー「CSV内で行{N}と同じ契約が重複しています」（N は最初の行）。最初の行は照合の前に覚える（最初の行がスキップ・エラーになっても 2 つ目はエラー）
- 警告（設計書 §4.6）: 行の中の `$rowWarnings` に貯め、取り込むと決めた位置で `$warnings = array_merge($warnings, $rowWarnings);`。4 経路の本体に `$warnings[] =` を残さない（Task 4 の構造のテストが見る）
- スキップの理由の文（設計書 §4.7 (1)。全文。物件名・テナント名・入居者名は CSV の値、区画の表示名・部屋番号・駐車場番号は登録されている値、日付は正規化したあと）:
  - 契約・過去契約: 「区画「{物件名} {区画の表示名}」の契約（契約日 {Y-m-d}・顧客 {テナント名 または 空欄}）は既に登録済み（{契約番号}）のためスキップ」
  - 部屋契約: 「部屋「{物件名} {部屋番号}」の入居者 {入居者名} の契約（契約日 {Y-m-d または 空欄}・入居日 {Y-m-d または 空欄}）は既に登録済み（既存契約 ID: {id}）のためスキップ」
  - 駐車場契約: 「駐車場「{物件名} {駐車場番号}」の入居者 {入居者名} の契約（契約日 {Y-m-d または 空欄}・開始日 {Y-m-d または 空欄}）は既に登録済み（既存契約 ID: {id}）のためスキップ」
- すべての行がスキップでエラーも無いとき（設計書 §4.7 (2)）: 確定のフォームの代わりに、灰色（`#6b7280`・inline の style）で「すべての行が登録済みです（スキップ N 件）。取り込む行はありません。」。エラーが 1 件でもあれば今までどおり赤字の「インポート可能なデータがありません。CSVを修正してください。」。テナントと賃貸マンションの共通部品の両方に入れる
- タブの説明（設計書 §4.7 (3)）: テナントの契約・過去契約「同じ区画・テナント名・契約日の契約が登録済みなら、その行はスキップされます（家賃などが違っても書き換えません）」／ 部屋契約「同じ部屋・入居者名・契約日・入居日の契約が登録済みなら、その行はスキップされます（家賃などが違っても書き換えません）」／ 駐車場契約「同じ駐車場・入居者名・契約日・開始日の契約が登録済みなら、その行はスキップされます（月額料金などが違っても書き換えません）」
- 確定でも同じ検査をやり直す（`loadCsv()` が CSV を読み直して同じ行の検査を通る＝今の仕組みのまま。設計書 §4.8）。鍵（Bug #69）・戻り先・断りの文言・完了の文は変えない
- テスト用スキーマは作成の migration の行を直す。`Schema::table(...)->nullable()->change()` は使わない（SQLite がテーブルを作り直し、状態・部署の CHECK が黙って消える＝試作で実測）。migration はテスト用で本番では流さない（設計書 §4.9）
- この計画では `<script>` を足さない（Blade のコメントは `{{-- --}}`。Bug #30）

## Review Focus

- **過去契約で、1 回目の取込が自動作成した顧客の行を上げ直す** — 2 回目はその顧客が登録済みなので照合され、スキップされるのが期待（1 回目の「まだ無い顧客の行は照合しない」決まりが、2 回目のスキップを邪魔しない）。Task 2 の `test_a_past_contract_for_a_customer_created_by_the_first_import_is_skipped_when_uploaded_again`（要約の「過去契約 0件を新規作成」まで見る）が固定する
- **テナント名が空欄の契約を、取込で入れてから上げ直す** — 「顧客の無い契約」として照合され、理由の文が「顧客 空欄」でスキップされるのが期待。Task 2 の `test_a_contract_without_a_tenant_name_is_skipped_when_uploaded_again`（往復）と `test_a_row_without_a_tenant_name_skips_the_same_contract_without_a_customer`（モデルで作った契約）が固定する
- **同じ日付を別の書き方（`2026/4/1`・`2026-4-1` など）で書いた CSV を上げ直す** — 正規化したあとの `Y-m-d` で比べるので同じ契約としてスキップされるのが期待。Task 2・3 の「キー以外が違う行はスキップ」の日付の書き方のデータ（4 経路）が固定する
- **賃貸マンションで日付が空欄の契約を、取込で入れてから上げ直す** — 空欄どうしは同じなのでスキップされ、理由の文に「空欄」が出るのが期待。Task 3 の `test_a_contract_without_dates_is_skipped_when_uploaded_again`（部屋・駐車場の往復）と `test_blank_dates_match_only_blank_dates`（12 通り）が固定する
- **登録済みと同じキーの行で、見分けに使わない日付（賃料開始日・解約日・退去日・終了日）が読めない** — スキップではなく日付のエラーになるのが期待（日付の検査は照合の前にまとめて置く。設計書 §4.4）。Task 2・3 の `test_a_registered_row_with_a_bad_date_is_an_error_not_skipped`（4 経路）と、変異 O05〜O08 が固定する

---

## Context

- Bug #69（2026-09-28 修正・2026-09-29 本番反映）で取込の確定を「確認画面 1 つにつき 1 回」にしたとき、範囲外に残した「同じ CSV を上げ直したときの二重」（案 C）を直す。上げ直すと新しいプレビュー＝新しい鍵になるので、鍵では止まらない。設計書 §2.1 の読み取りで、4 経路はどれも上げ直すと取り込めてしまい、テナントの過去契約は注意すら出ない
- 作業場所: worktree `/Users/masanori/site/manage/.claude/worktrees/contract-reimport`（ブランチ `contract-reimport`）。この計画を書いた時点は、`13.x`（`134523fb`）の上に設計書の 2 コミット（`95cd39be`・`046c69c9`）と、この計画のコミット。`origin/13.x` も `134523fb`（push は利用者の指示のとき）
- 別の会話の worktree（`approval-phase2b`・`customer-import-double-submit`）には触らない・借りない。`approval-phase2b` は `13.x` より先へ進んでいる。先に `13.x` へ入ると、Task 9 の `CLAUDE.md`・`docs/BACKLOG.md`・`docs/RULES.md` がぶつかることがある（両方の段落を残す。Bug の番号は Task 9 Step 1 で数え直す）
- 利用者の決まり（この計画を実行する人にも適用）: 応答は日本語・選択肢は本文の表で出す（AskUserQuestion のウィジェットは使わない）・推薦と実測を先に書く・質問は 1 回に 1 つ・`.env` / `.env.*` は読まない（`ls` で存在を確かめることもしない）・`git stash` と `--no-verify` は使わない・push は指示があったときだけ・main repo で `artisan migrate` を流さない（実 DB は raw SQL 管理）・worktree に `.env` を作らない・**本番への反映（Task 10）と本番の読み取りは、承認をもらってから**

## 試作で確かめたこと（2026-09-29）

scratchpad に `git worktree add --detach … 046c69c9` で作った写し（vendor は `cp -Rc` で実体コピー。Bug #50）にこの計画のコードを入れて測った。表の数字は、そのまま各 Task の「期待」に使っている。

| 見たこと | 結果 |
|---|---|
| 着手前の全件（`046c69c9`）| `OK (2838 tests, 20265 assertions)` |
| テスト用スキーマの候補 1（新しい migration で `->nullable()->change()`）| `customer_id` は空欄可になるが、状態・部署の **CHECK が黙って消える**（ありえない値が入る）。外部キーと索引は残る → 採らない（変異 Z02 で固定）|
| テスト用スキーマの候補 2（作成の migration の行を直す）| `customer_id` が空欄可になり、CHECK・外部キー・索引とも残る → 採る（Task 1）|
| SQLite の日付の保存形式 | Eloquent の `date` キャストは `2026-04-01 00:00:00` の形で保存する。素の `where('contract_date', '2026-04-01')` は 0 件・`whereDate` は 1 件（設計書 §2.4 の読み取りを実測で確定）→ `whereDate` で比べる（変異 F13〜F15 で固定）|
| Task 1 の直す前（カナリア 3 本を足し、migration は元のまま）| `Tests: 22, Assertions: 165, Errors: 1.`（空欄のカナリアだけ `NOT NULL constraint failed: contracts.customer_id`）|
| Task 2 の直す前（テナントのテストと道具を足し、コントローラ・画面は元のまま）| `Tests: 56, Assertions: 329, Failures: 37.`（緑の 19 本は「別の契約として入る」「日付の誤りはエラー」など、直す前から成り立つことを守るテスト）|
| Task 3 の直す前（テストを足し、コントローラ・画面は元のまま）| `Tests: 68, Assertions: 326, Failures: 39.`（緑の 29 本は同じ種類）|
| Task 4 の構造のテストを、直す前のコントローラに当てる | `Tests: 3, Assertions: 17, Failures: 2.`（照合を呼んでいない・警告を直接積んでいる）|
| 既存のテストは直さずに通るか | 通る（全件）。ただし `customer_id` が NOT NULL だった頃の注記が 5 ファイル 6 か所にあり、事実と合わなくなるので直す（Task 1。コメントだけ）|
| 最終形 | 全件 **`OK (2968 tests, 21234 assertions)`**（+130 本: カナリア 3・テナント 56・賃貸マンション 68・構造 3）／ コンパイル済みビュー `views=283 invalid=0`（増減なし）|
| CSS | 写しで `vite build` → `app-DW1DvPK7.css`・`app-NiVQbl_Q.js`（本番と同じ名前）|
| 変異の 1 回目（99 通り。関係するテスト 20 本に絞って、隔離した写し 3 つで先測り）| 緑は 3 通り: **D18 はテストの穴**（過去契約で「最初の行を覚える位置」を照合の後ろへ動かしても緑＝「登録済みの契約を CSV に 2 行書く」テストが契約タブにしか無かった）→ そのテストを契約・過去契約の 2 つのデータにした（Task 2 のテストに反映済み）。S04 は変異の当て方の誤り（コメントをメソッドの外に置いていた）→ 対照に直し、S06 を足した。F25 は SQLite では等価 |
| 変異の 2 回目（テストを直したあと・100 通り＋カナリア）| Task 6 の表の「期待」はこの実測（下の「実測記録」）。緑は F25（等価）と S04（対照）だけ |

## 設計書との違い（試作で決めたこと）

| # | 設計書 | この計画 | 理由 |
|---|---|---|---|
| 1 | §4.3「形の見本。名前と書き方の細部は計画で決める」 | 名前は見本どおり（`findRegisteredContract`・`findRegisteredRoomContract`・`findRegisteredParkingContract`）。顧客の条件は `when($customerId === null, whereNull, where)`、賃貸マンションの日付は `when($date === null, whereNull, whereDate)` | 見本の `where(fn …)` と同じ意味で、空欄の分かれ目が読みやすい |
| 2 | §4.9「やり方は計画の段で実測して決める」 | 候補 2（作成の migration の行を直す）| 候補 1 は CHECK を黙って消す（試作で実測）|
| 3 | §5.2「public なメソッドの本体で…探す」 | public に限らず、取込のコントローラの**すべてのメソッド**を探す | 作成を private の部品へ切り出したときも見逃さない |
| 4 | §5.2 の探し方（`〈…Contract〉::create(` など）| 正規表現は `\b(?:[A-Z]\w*)?Contract::(create\|forceCreate\|firstOrCreate\|updateOrCreate\|insert)\s*\(` と `\bnew\s+(?:[A-Z]\w*)?Contract\s*\(` | 最初に書いた形（`[A-Z]\w*Contract`）は素の `Contract::create(` に当たらず、見つかった数が 3（下限 5 未満）で空振りを捕まえた（試作で実測。変異 S05 で固定）|
| 5 | §5.3「スキーマのカナリア」 | 3 本を `TenantUnitImportTest` の既存のカナリア（区画の状態の CHECK）の隣に置く | 同じファイルに Bug #60 のカナリア 5 本がある |
| 6 | §5.1 の 12「物件・顧客・入居者のタブで全部スキップ」 | テナントの物件タブ（Task 2）と賃貸マンションの入居者タブ（Task 3）で、それぞれのテストファイルに置く | 共通部品が 2 つなので、それぞれの経路のテストと一緒に見る |
| 7 | §5.1 の 14「取込の画面に 4 行が出る」 | タブごとの枠（その `<ul>`）を切り出し、その中に 1 行ずつあることを見る | ページのどこかに 4 行あるだけでは、別のタブに 2 行入っても緑になる |
| 8 | §5.1 の表（14 場面）| 7 場面を足した（Review Focus の 5 行・駐車場の「同じキーが 2 件」・駐車場の「日付と金額の両方が誤り」）| Review Focus と、駐車場の並べ替え（設計書 §4.4）を守る |
| 9 | §2.5「既存のテストは直さずに通る見込み」 | 通る。ただし注記 6 か所を直す（コメントだけ）| 「`customer_id` が NOT NULL なので…」という注記が事実と合わなくなる |
| 10 | §5.5「一覧と期待は計画で決める」 | 100 通り＋カナリア（Task 6 の表）| 下の「実測記録」 |

## 変えるファイル

| ファイル | 変更 | Task |
|---|---|---|
| `database/migrations/0001_01_01_000007_create_contracts_table.php` | `customer_id` を空欄可に（テスト用。本番では流さない）| 1 |
| `tests/Feature/Admin/TenantUnitImportTest.php` | スキーマのカナリア 3 本・注記 2 か所 | 1 |
| `tests/Feature/Admin/TenantImportDoubleSubmitTest.php`・`tests/Feature/SortableListWiringTest.php`・`tests/Feature/Tenant/SortBarTest.php`・`tests/Feature/Tenant/ContractListSortTest.php` | 注記を 1 か所ずつ（コメントだけ）| 1 |
| `tests/Concerns/SubmitsImportPreview.php` | 定数 `ALL_ROWS_REGISTERED`・道具 `assertPreviewSkipsEveryRow()` | 2 |
| `app/Http/Controllers/Admin/TenantImportController.php` | 契約・過去契約の CSV 内の重複・照合・行ごとの警告・`findRegisteredContract()` | 2 |
| `resources/views/admin/tenant-import/_preview.blade.php` | 灰色の文 | 2 |
| `resources/views/admin/tenant-import/index.blade.php` | 契約・過去契約タブの説明を 1 行ずつ | 2 |
| `tests/Feature/Admin/TenantContractReimportTest.php`（新規）| テナントの 2 経路（56 本）| 2 |
| `app/Http/Controllers/Admin/MansionImportController.php` | 部屋契約・駐車場契約の CSV 内の重複・照合・行ごとの警告・駐車場の並べ替え・照合のメソッド 2 つ | 3 |
| `resources/views/admin/mansion-import/_preview.blade.php` | 灰色の文 | 3 |
| `resources/views/admin/mansion-import/index.blade.php` | 部屋契約・駐車場契約タブの説明を 1 行ずつ | 3 |
| `tests/Feature/Admin/MansionContractReimportTest.php`（新規）| 賃貸マンションの 2 経路（68 本）| 3 |
| `tests/Feature/ImportControllerContractMatchScanTest.php`（新規）| 構造のテスト（全件分類・3 本）| 4 |
| `docs/RULES.md`・`CLAUDE.md`・`docs/BACKLOG.md`・この計画 | 記録 | 9・10 |

ルート・本番の DB・依存・`config/`・ほかの取込（顧客・ZEAL 会員・工程表・周辺ビル・本部 Sheet）は変えない。

## 共通の決まり

- 作業は worktree で行う。⚠ ハーネスの cwd が main repo に戻っていることがあるので、コマンドには `cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport &&` を付けるか `git -C <worktree>` にする
- 全件テスト（約 2〜3 分）: `cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`
- 決まったテストだけ: 上の末尾にファイルを並べる（PHPUnit 11 は複数のパスを受け付ける）
- ⚠ `.env` は読まない（worktree に `.env` は無い・作らない。テストは `APP_KEY` の環境変数だけで起動する。渡し忘れると Feature テストが大量に `MissingAppKeyException` で落ちる。本数は正しく出るので見落としやすい）
- コミット: Conventional Commits・件名は日本語で 72 文字以内・句点なし・1 コミット 1 関心事・末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`（サブエージェントは自分の文脈の Co-Authored-By 行を使う）。HEREDOC で書く。`--no-verify` は使わない。コミットの後に `git status --porcelain` が空であることを見る
- サブエージェントに任せるときは 1 つの worktree に 1 つのエージェント。モデルは sonnet 以上（haiku は自動で読み込む文書だけで文脈の上限に達し、開始の時点で止まる）。書いたらすぐコミットする（未コミットの編集が残っていると、変異の実行役が「作業ツリーが空でない」で止まる）
- zsh: 引用なしの `$VAR` は単語分割されない（複数のパスは配列で）・`=` で始まる語は展開される（区切りの `echo '-----'` は引用する）・グロブが 1 つも当たらないとコマンド全体が止まる・`grep` は ugrep（`-` で始まるパターンは `-e`・`$` を含む文字列は `-F`）・`php -r "…"` の `$` はシェルが展開する（`'…'` で囲む）
- 編集は「置き換える前」がファイルにちょうど決めた数だけあることを確かめてから当てる（Edit ツールは 1 か所でないと失敗する。2 か所ある M1・M2 は `replace_all` を使い、先に `grep -cF` で数を確かめる）。置き換えは書いてある順（ID の順）に当てる
- 行番号は `046c69c9` の時点のもの。ずれていたら「置き換える前」の文字列で探す
- 変異テスト（Task 6）は先にコミットしてから（Bug #44）

---

## Task 0: 前提の確認

**Files:** なし

- [ ] **Step 1: worktree とブランチ**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && git status --porcelain && git rev-parse --abbrev-ref HEAD && git log --oneline -3 && git merge-base --is-ancestor 13.x HEAD && echo 'ahead-of-13.x'
```

期待: `status` は何も出さない・`contract-reimport`・この計画のコミット（`docs: 契約の上げ直しで二重にしない実装計画を書く`）と設計書の 2 コミット（`046c69c9`・`95cd39be`）・`ahead-of-13.x`。

⚠ `ahead-of-13.x` が出ない（`13.x` がこの後に進んだ）ときは、作業を始める前に取り込む（リベースしない＝記録のコミット番号を保つ）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && git log --stat --format='%h %s' HEAD..13.x && git merge --no-edit -m "$(cat <<'EOF'
Merge branch '13.x' into contract-reimport

作業中に 13.x へ入ったコミットを取り込む。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" 13.x
```

取り込んだコミットが、この計画で触るファイル（上の「変えるファイル」）を変えていたら**止めて**、この計画の「置き換える前」がまだ合うかを確かめる。`composer.lock` が変わっていたら worktree で `composer install`（開発用の依存も入れる）。アプリのコードやテストが変わっていたら Step 2 の本数が変わるので測り直して記録し、以降の本数は**差**（この計画の +130 本・+969 アサーション）で突き合わせる。

- [ ] **Step 2: この時点の全件テスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

期待: `OK (2838 tests, 20265 assertions)`

---

## Task 1: テスト用スキーマ（`contracts.customer_id` を空欄可に）

**Files:**
- Modify: `tests/Feature/Admin/TenantUnitImportTest.php`（カナリア 3 本・注記 2 か所）
- Modify: `database/migrations/0001_01_01_000007_create_contracts_table.php:20`
- Modify: `tests/Feature/Admin/TenantImportDoubleSubmitTest.php`・`tests/Feature/SortableListWiringTest.php`・`tests/Feature/Tenant/SortBarTest.php`・`tests/Feature/Tenant/ContractListSortTest.php`（注記だけ）

**Interfaces:**
- Consumes: なし
- Produces: テストの SQLite で `contracts.customer_id` が空欄可（`Contract::create(['customer_id' => null, …])` が通る）。状態・部署の CHECK・外部キー・索引は今のまま。Task 2 の「顧客の無い契約」のテストが使う

- [ ] **Step 1: スキーマのカナリア 3 本を足す**（`tests/Feature/Admin/TenantUnitImportTest.php`。Edit。既存のカナリア `test_unit_status_is_still_checked()` の後ろ）

置き換える前（`tests/Feature/Admin/TenantUnitImportTest.php`・`046c69c9` の 141 行目から）:

```php
            $this->assertStringContainsString('CHECK constraint failed', $e->getMessage());
        }
    }

    // ============================================================
    // 区画の取込（プレビュー → 描画された「インポート実行」フォームをそのまま確定。Bug #54 ②）
```

置き換えた後:

```php
            $this->assertStringContainsString('CHECK constraint failed', $e->getMessage());
        }
    }

    /**
     * 本番の定義（2026-09-14 に読み取りで確認）: contracts.customer_id は NULL 可（テナント名が空欄の契約）。
     * テスト用スキーマは作成の migration の行で揃えた（2026-09-29。契約の取込の上げ直しのテストが顧客の無い契約を作る）。
     * ⚠ Schema::table(...)->nullable()->change() で揃えてはいけない — SQLite がテーブルを作り直し、状態・部署の CHECK が
     *   黙って消える（2026-09-29 に実測。下の 2 本のカナリアが止める）
     */
    public function test_the_contracts_table_accepts_a_contract_without_a_customer_like_production(): void
    {
        $property = $this->property();
        $unit = Unit::create($this->unitAttributes($property, 1, 'A'));

        DB::table('contracts')->insert([
            'contract_number' => 'C-2026-904', 'department' => 'tenant', 'property_id' => $property->id, 'unit_id' => $unit->id,
            'customer_id' => null, 'status' => 'active', 'contract_date' => '2026-09-01', 'rent_start_date' => '2026-09-01', 'rent' => 100000,
        ]);

        $this->assertNull(DB::table('contracts')->where('contract_number', 'C-2026-904')->value('customer_id'));
    }

    /** カナリア: 契約の状態・部署の CHECK（enum）が残っている（migration でテーブルを作り直すと消える） */
    public function test_contract_status_and_department_are_still_checked(): void
    {
        $property = $this->property();
        $unit = Unit::create($this->unitAttributes($property, 1, 'A'));
        // 顧客は埋める（customer_id の手当てと切り離して、CHECK だけを見る）
        $customer = \App\Models\Customer::create(['code' => 'CU-IMP-1', 'name' => '取込商事', 'customer_type' => 'corporation']);
        $row = [
            'contract_number' => 'C-2026-905', 'department' => 'tenant', 'property_id' => $property->id, 'unit_id' => $unit->id,
            'customer_id' => $customer->id, 'status' => 'active', 'contract_date' => '2026-09-01', 'rent_start_date' => '2026-09-01', 'rent' => 100000,
        ];

        foreach (['status', 'department'] as $column) {
            try {
                // ⚠ 配列の + は左の値を残すので、上書きしたい値を左に置く
                DB::table('contracts')->insert([$column => 'bogus'] + $row);
                $this->fail("contracts.{$column} の CHECK が無い（本番の enum と食い違う）");
            } catch (QueryException $e) {
                $this->assertStringContainsString("CHECK constraint failed: {$column}", $e->getMessage());
            }
        }
    }

    /** カナリア: 契約の外部キー（顧客は削除を止める）と索引が残っている（テーブルを作り直すと落ちることがある） */
    public function test_the_contracts_table_keeps_its_foreign_keys_and_indexes(): void
    {
        $foreignKeys = collect(DB::select('PRAGMA foreign_key_list(contracts)'))
            ->mapWithKeys(fn ($fk) => [$fk->from => "{$fk->table}.{$fk->to} {$fk->on_delete}"])
            ->sortKeys()
            ->all();
        $this->assertSame([
            'assigned_to' => 'users.id SET NULL',
            'customer_id' => 'customers.id RESTRICT',
            'property_id' => 'properties.id RESTRICT',
            'unit_id'     => 'units.id RESTRICT',
        ], $foreignKeys);

        $indexes = collect(DB::select('PRAGMA index_list(contracts)'))->pluck('name')->sort()->values()->all();
        $this->assertSame([
            'contracts_contract_number_unique',
            'idx_contracts_customer',
            'idx_contracts_property',
            'idx_contracts_status',
            'idx_contracts_unit',
        ], $indexes);
    }

    // ============================================================
    // 区画の取込（プレビュー → 描画された「インポート実行」フォームをそのまま確定。Bug #54 ②）
```

- [ ] **Step 2: 直す前に流す**（migration はまだ元のまま）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/TenantUnitImportTest.php 2>&1 | grep -E -A1 -e '^[0-9]+\) ' -e '^Tests:' | grep -v -e '^--$'
```

期待: `Tests: 22, Assertions: 165, Errors: 1.`。落ちるのは `test_the_contracts_table_accepts_a_contract_without_a_customer_like_production` だけで、理由は `Illuminate\Database\QueryException: SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: contracts.customer_id …`。ほかの 2 本（CHECK・外部キーと索引）は直す前も緑（今あるものが残ることを見るカナリア）。

- [ ] **Step 3: migration を直す**（`database/migrations/0001_01_01_000007_create_contracts_table.php`。Edit）

置き換える前（`database/migrations/0001_01_01_000007_create_contracts_table.php`・`046c69c9` の 20 行目から）:

```php
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
```

置き換えた後:

```php
            // 本番の contracts.customer_id は NULL 可（テナント名が空欄の契約。2026-09-14 に読み取りで確認。Bug #60）。
            // ⚠ 変えるときはこの行を直す。Schema::table(...)->nullable()->change() は使わない
            //    （SQLite がテーブルを作り直し、状態・部署の CHECK が黙って消える。2026-09-29 に実測）
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
```

⚠ 新しい migration で `->nullable()->change()` を足す形にしない。試作で測ると、SQLite がテーブルを作り直し、状態・部署の CHECK が黙って消える（変異 Z02 がこの形を当て、CHECK のカナリアが落ちる）。

- [ ] **Step 4: 事実と合わなくなる注記を直す**（コメントだけ。Edit を 6 回）

`tests/Feature/Admin/TenantUnitImportTest.php`（2 か所）:

置き換える前（`tests/Feature/Admin/TenantUnitImportTest.php`・`046c69c9` の 89 行目から）:

```php
        // ⚠ テスト用スキーマの contracts.customer_id と rent_start_date は NOT NULL だが、本番はどちらも NULL 可（2026-09-14 に読み取りで確認）。
        //   アプリはどちらも空のまま契約を作る経路を持つ。テスト用スキーマの漂流を直すのはこの変更の範囲外なので、ここではどちらも埋めて、初月・最終月の列だけを見る
```

置き換えた後:

```php
        // ⚠ テスト用スキーマの contracts.rent_start_date は NOT NULL だが、本番は NULL 可（2026-09-14 に読み取りで確認。
        //   customer_id は 2026-09-29 に本番と同じ NULL 可へ揃えた）。ここでは顧客と賃料開始日を埋めて、初月・最終月の列だけを見る
```

置き換える前（`tests/Feature/Admin/TenantUnitImportTest.php`・`046c69c9` の 343 行目から）:

```php
        // ⚠ テナント名と賃料開始日を埋める（テスト用スキーマの contracts.customer_id / rent_start_date が NOT NULL のため）
```

置き換えた後:

```php
        // ⚠ 賃料開始日を埋める（テスト用スキーマの contracts.rent_start_date が NOT NULL のため）
```

`tests/Feature/Admin/TenantImportDoubleSubmitTest.php`:

置き換える前（`tests/Feature/Admin/TenantImportDoubleSubmitTest.php`・`046c69c9` の 200 行目から）:

```php
                // ⚠ テナント名と賃料開始日を埋める（テスト用スキーマの contracts.customer_id / rent_start_date が NOT NULL。Bug #60）
```

置き換えた後:

```php
                // ⚠ 賃料開始日を埋める（テスト用スキーマの contracts.rent_start_date が NOT NULL。Bug #60）
```

`tests/Feature/SortableListWiringTest.php`:

置き換える前（`tests/Feature/SortableListWiringTest.php`・`046c69c9` の 337 行目から）:

```php
        // テナント契約一覧（設計書 2026-09-11 §7.3）。⚠ テストの SQLite では customer_id が NOT NULL
```

置き換えた後:

```php
        // テナント契約一覧（設計書 2026-09-11 §7.3）
```

`tests/Feature/Tenant/SortBarTest.php`:

置き換える前（`tests/Feature/Tenant/SortBarTest.php`・`046c69c9` の 70 行目から）:

```php
     * ⚠ テストの SQLite では customer_id / rent_start_date が NOT NULL。
```

置き換えた後:

```php
     * ⚠ テストの SQLite では rent_start_date が NOT NULL（customer_id は 2026-09-29 に本番と同じ NULL 可へ揃えた）。
```

`tests/Feature/Tenant/ContractListSortTest.php`:

置き換える前（`tests/Feature/Tenant/ContractListSortTest.php`・`046c69c9` の 49 行目から）:

```php
    /** 契約に要る顧客（テストの SQLite では customer_id が NOT NULL。1 件を使い回す） */
```

置き換えた後:

```php
    /** 契約の顧客（1 件を使い回す） */
```

- [ ] **Step 5: 通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/TenantUnitImportTest.php tests/Feature/Admin/TenantImportDoubleSubmitTest.php tests/Feature/SortableListWiringTest.php tests/Feature/Tenant/SortBarTest.php tests/Feature/Tenant/ContractListSortTest.php 2>&1 | tail -1
```

期待: `OK (72 tests, 833 assertions)`

- [ ] **Step 6: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && git add database/migrations/0001_01_01_000007_create_contracts_table.php tests/Feature/Admin/TenantUnitImportTest.php tests/Feature/Admin/TenantImportDoubleSubmitTest.php tests/Feature/SortableListWiringTest.php tests/Feature/Tenant/SortBarTest.php tests/Feature/Tenant/ContractListSortTest.php && git commit -m "$(cat <<'EOF'
test: テスト用の contracts.customer_id を本番どおり空欄可にする

テナント名が空欄の契約（顧客の無い契約）をテストで作れるようにする。作成の migration の行を直し、
状態・部署の CHECK・外部キー・索引が残ることをカナリア 3 本で見る。NOT NULL だった頃の注記も直す。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 2: テナントの契約・過去契約

**Files:**
- Modify: `tests/Concerns/SubmitsImportPreview.php`（定数と道具）
- Create: `tests/Feature/Admin/TenantContractReimportTest.php`
- Modify: `app/Http/Controllers/Admin/TenantImportController.php`（クラスの docblock・契約タブ・過去契約タブ・照合のメソッド）
- Modify: `resources/views/admin/tenant-import/_preview.blade.php`（灰色の文）
- Modify: `resources/views/admin/tenant-import/index.blade.php`（タブの説明 2 行）

**Interfaces:**
- Consumes: Task 1 の空欄可の `contracts.customer_id` ／ `Tests\Concerns\SubmitsImportPreview` の既存の道具 — `preview(string $tab, string $csv): TestResponse`（プレビューを描かせる）・`confirm(string $tab, string $csv): TestResponse`（プレビューが描いた確定のフォームを分解してそのまま送る）・`assertPreviewOffersNoImport(string $tab, string $csv): TestResponse`・`executive(): User`・使う側が定義する `importBasePath(): string` ／ `Tests\Concerns\ParsesForms`
- Produces（Task 3・4 が使う）:
  - `SubmitsImportPreview::ALL_ROWS_REGISTERED`（`'すべての行が登録済みです（スキップ %d 件）。取り込む行はありません。'`）
  - `SubmitsImportPreview::assertPreviewSkipsEveryRow(string $tab, string $csv, int $rows): TestResponse` — 全 N 件・正常 0・エラー 0・スキップ N 件・各行の「行N: 理由」が画面に出る・灰色の文（`color: #6b7280`）がある・赤字の文が無い・「インポート実行」が無い
  - `TenantImportController::findRegisteredContract(int $unitId, ?int $customerId, string $contractDate): ?Contract`（private。Task 4 の構造のテストがこの名前を見る）
  - 契約・過去契約の view データ `skippedRows`（`[['row' => int, 'message' => string], …]`）

- [ ] **Step 1: 道具を足す**（`tests/Concerns/SubmitsImportPreview.php`。Edit を 2 回）

1 回目（定数）:

置き換える前（`tests/Concerns/SubmitsImportPreview.php`・`046c69c9` の 32 行目から）:

```php
    /** 全行がエラーのときにプレビューが出す文言（フォームの代わりに描画される）。 */
    private const NO_IMPORTABLE_ROWS = 'インポート可能なデータがありません。CSVを修正してください。';
```

置き換えた後:

```php
    /** 取り込める行が無く、エラーの行があるときにプレビューが出す文言（フォームの代わりに描画される）。 */
    private const NO_IMPORTABLE_ROWS = 'インポート可能なデータがありません。CSVを修正してください。';

    /**
     * すべての行が登録済み（スキップ）で、エラーも無いときにプレビューが出す文言（%d はスキップの件数。フォームの代わりに
     * 灰色で描画される。設計書 2026-09-29-contract-reimport-design.md §4.7 (2)）。
     */
    private const ALL_ROWS_REGISTERED = 'すべての行が登録済みです（スキップ %d 件）。取り込む行はありません。';
```

2 回目（道具。ファイルの最後のメソッド `assertPreviewOffersNoImport()` の後ろ）:

置き換える前（`tests/Concerns/SubmitsImportPreview.php`・`046c69c9` の 171 行目から）:

```php
        $this->assertDoesNotMatchRegularExpression(
            self::IMPORT_BUTTON_PATTERN,
            $html,
            "取込できないはずのプレビューに「インポート実行」ボタンが出ている（tab={$tab}）"
        );

        return $preview;
    }
}
```

置き換えた後:

```php
        $this->assertDoesNotMatchRegularExpression(
            self::IMPORT_BUTTON_PATTERN,
            $html,
            "取込できないはずのプレビューに「インポート実行」ボタンが出ている（tab={$tab}）"
        );

        return $preview;
    }

    /**
     * すべての行が登録済み（スキップ）で、エラーも無い CSV のプレビュー（設計書 2026-09-29-contract-reimport-design.md §4.7 (2)・§5.1）。
     *
     * 正常 0・エラー 0・スキップ＝行数・灰色の文がある・赤字の文が無い・確定のフォームが無い、を見る。
     * 役割（viewData）と表示（画面の文字）は別々に見る（Bug #54 ④）。灰色の文は色まで見る（赤字の枝と取り違えない）。
     */
    private function assertPreviewSkipsEveryRow(string $tab, string $csv, int $rows): \Illuminate\Testing\TestResponse
    {
        $preview = $this->preview($tab, $csv);
        $preview->assertStatus(200);

        $html = $preview->getContent();

        $this->assertSame($rows, $preview->viewData('totalRows'), "CSV の行数が違う（tab={$tab}）");
        $this->assertSame(0, $preview->viewData('validCount'), "取り込む行が残っている（tab={$tab}）");
        $this->assertSame([], $preview->viewData('rowErrors'), "エラーの行がある（tab={$tab}）");

        // コントローラが数えたスキップの行が、灰色の一覧にも「行N: 理由」で出ていること（Bug #53: 件数と表示を突き合わせる）
        $skipped = $preview->viewData('skippedRows');
        $this->assertCount($rows, $skipped, "スキップの行の数が CSV の行数と違う（tab={$tab}）");
        foreach ($skipped as $skip) {
            $this->assertStringContainsString(
                '行' . $skip['row'] . ': ' . e($skip['message']),
                $html,
                "スキップの理由が画面に出ていない（tab={$tab}・行{$skip['row']}）"
            );
        }

        $this->assertStringContainsString(
            '<div style="font-size: 13px; color: #6b7280;">' . sprintf(self::ALL_ROWS_REGISTERED, $rows) . '</div>',
            $html,
            "灰色の「すべての行が登録済み」の文が出ていない（tab={$tab}）"
        );
        $this->assertStringNotContainsString(self::NO_IMPORTABLE_ROWS, $html, "エラーが無いのに赤字の文が出ている（tab={$tab}）");
        $this->assertDoesNotMatchRegularExpression(
            self::IMPORT_BUTTON_PATTERN,
            $html,
            "取り込む行が無いのに「インポート実行」ボタンが出ている（tab={$tab}）"
        );

        return $preview;
    }
}
```

- [ ] **Step 2: テストを書く**（`tests/Feature/Admin/TenantContractReimportTest.php` を新しく作る。この内容そのまま）

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * テナントの契約・過去契約の CSV 取込を、上げ直しても二重にしない（設計書 2026-09-29-contract-reimport-design.md）。
 *
 * ⚠ 直す前（2026-09-29 に試作で実測）: 同じ CSV を上げ直すと、契約・過去契約とも同じ契約がもう一度入った
 *   （契約タブは「既にアクティブな契約」の警告だけ、過去契約は契約中の契約と重ならなければ何も出なかった）。
 * ⚠ 見分けのキーは 区画・顧客（テナント名が空欄なら顧客の無い契約）・契約日（設計書 §4.2）。
 *   賃料・状態・解約日・備考は使わない。削除した契約とは突き合わせない。テナントの 2 タブは同じキーで照合する。
 * ⚠ どれも、プレビューが描いた確定のフォームを分解してそのまま送り返す往復（SubmitsImportPreview。Bug #47 / #54 ②）。
 *   スキップの理由は「行N: …」の全文で見て、役割（viewData）と表示（画面の文字）を別々に見る（Bug #43 / #54 ④）。
 * ⚠ 「入る」を見るテスト（見分けのキーが 1 つ違う・削除した契約・まだ無い顧客）は、直す前のコードでも緑になる。
 *   照合が広すぎる書き換え（キーの要素を外す・削除済みも含める・まだ無い顧客も照合する）を捕まえる役
 *   （計画 docs/superpowers/plans/2026-09-29-contract-reimport.md の変異テストで赤を確かめる）。
 */
class TenantContractReimportTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use SubmitsImportPreview;

    private const CONTRACT_HEADER = '物件名,階,部屋番号,テナント名,契約日,賃料開始日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考';
    private const PAST_HEADER = '物件名,階,部屋番号,テナント名,契約日,賃料開始日,解約日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考';
    private const PROPERTY_HEADER = '物件名,郵便番号,住所,構造,築年月,階数,所有区分,オーナー名,稼働状態';

    private const CONTRACT_ROW_1 = '再取込ビル,1,A,再取込商事,2026-04-01,2026-04-01,95000,8000,190000,1500,500,再取込商事 松山支店,';
    private const CONTRACT_ROW_2 = '再取込ビル,2,A,別商事,2026-05-01,2026-05-01,100000,,,,,,';
    private const PAST_ROW_1 = '再取込ビル,1,A,再取込商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,期間満了で解約';
    private const PAST_ROW_2 = '再取込ビル,2,A,別商事,2019-04-01,2019-04-01,2021-03-31,80000,,,,,,';

    /** CONTRACT_ROW_1 と同じ契約のスキップの理由（%s は登録済みの契約番号） */
    private const CONTRACT_SKIP = '区画「再取込ビル 1A」の契約（契約日 2026-04-01・顧客 再取込商事）は既に登録済み（%s）のためスキップ';

    /** PAST_ROW_1 と同じ契約のスキップの理由（%s は登録済みの契約番号） */
    private const PAST_SKIP = '区画「再取込ビル 1A」の契約（契約日 2020-04-01・顧客 再取込商事）は既に登録済み（%s）のためスキップ';

    /** 契約・過去契約のタブの説明に足した 1 行（2 タブで同じ文） */
    private const TAB_NOTE = '<li>同じ区画・テナント名・契約日の契約が登録済みなら、その行はスキップされます（家賃などが違っても書き換えません）</li>';

    private Property $property;

    private Unit $unit1A;

    private Unit $unit2A;

    private Customer $customer;

    private Customer $other;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::create([
            'code' => 'T-RE-1', 'name' => '再取込ビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 5,
        ]);
        $this->unit1A = $this->unit(1, 'A');
        $this->unit2A = $this->unit(2, 'A');
        $this->customer = Customer::create(['code' => 'CU-RE-1', 'name' => '再取込商事', 'customer_type' => 'corporation']);
        $this->other = Customer::create(['code' => 'CU-RE-2', 'name' => '別商事', 'customer_type' => 'corporation']);
    }

    /** 取込画面の URL の前半（SubmitsImportPreview が使う） */
    private function importBasePath(): string
    {
        return '/admin/tenant-import';
    }

    // ================================================================
    // 1・2: 同じ CSV を上げ直す／行を足した CSV を上げ直す
    // ================================================================

    /** @return array<string, array{0: string, 1: string}> [タブ, 2 行の CSV] */
    public static function twoRowCsvs(): array
    {
        return [
            '契約'     => ['contract', self::CONTRACT_HEADER . "\n" . self::CONTRACT_ROW_1 . "\n" . self::CONTRACT_ROW_2 . "\n"],
            '過去契約' => ['past-contract', self::PAST_HEADER . "\n" . self::PAST_ROW_1 . "\n" . self::PAST_ROW_2 . "\n"],
        ];
    }

    #[DataProvider('twoRowCsvs')]
    public function test_uploading_the_same_csv_again_skips_every_row(string $tab, string $csv): void
    {
        $this->confirm($tab, $csv)->assertRedirect(route('admin.tenant-import', ['tab' => $tab]));
        $this->assertSame(2, Contract::count(), '1 回目で 2 件が入っていない（測定が無効）');

        $this->assertPreviewSkipsEveryRow($tab, $csv, 2);
        $this->assertSame(2, Contract::count());
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}> */
    public static function addedRowCases(): array
    {
        return [
            '契約' => ['contract', self::CONTRACT_HEADER, self::CONTRACT_ROW_1, self::CONTRACT_ROW_2, self::CONTRACT_SKIP, '契約インポート完了: 1件を登録しました'],
            '過去契約' => ['past-contract', self::PAST_HEADER, self::PAST_ROW_1, self::PAST_ROW_2, self::PAST_SKIP, '過去契約インポート完了: 契約 1件を登録'],
        ];
    }

    #[DataProvider('addedRowCases')]
    public function test_uploading_a_csv_with_an_added_row_imports_only_the_new_row(
        string $tab, string $header, string $first, string $added, string $skipFormat, string $success
    ): void {
        $this->confirm($tab, "{$header}\n{$first}\n");
        $registered = Contract::sole();

        $csv = "{$header}\n{$first}\n{$added}\n";
        $preview = $this->preview($tab, $csv)->assertOk();
        $html = $preview->getContent();
        $message = sprintf($skipFormat, $registered->contract_number);

        // 役割: 1 行目はスキップ（エラーではない）・足した行は取り込む
        $this->assertSame([['row' => 2, 'message' => $message]], $preview->viewData('skippedRows'));
        $this->assertSame([], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));
        // 表示: 灰色の一覧に全文・件数のチップ・ボタンの下の注記（Bug #43: 全文で見る）
        $this->assertStringContainsString('行2: ' . $message, $html);
        $this->assertStringContainsString('スキップ: <strong>1</strong> 件', $html);
        $this->assertStringContainsString('※ 既存データ（1件）はスキップされます', $html);

        $this->confirm($tab, $csv)
            ->assertRedirect(route('admin.tenant-import', ['tab' => $tab]))
            ->assertSessionHas('success', $success);
        $this->assertSame(2, Contract::count());
    }

    // ================================================================
    // 3・4: 見分けのキーが 1 つ違えば入る／キー以外が違ってもスキップ
    // ================================================================

    /** @return array<string, array{0: string}> [CSV の行。登録済みは 1A・再取込商事・2026-04-01] */
    public static function contractRowsWithAnotherKey(): array
    {
        return [
            '区画が違う'   => ['再取込ビル,2,A,再取込商事,2026-04-01,2026-04-01,95000,,,,,,'],
            '顧客が違う'   => ['再取込ビル,1,A,別商事,2026-04-01,2026-04-01,95000,,,,,,'],
            '顧客が空欄'   => ['再取込ビル,1,A,,2026-04-01,2026-04-01,95000,,,,,,'],
            '契約日が違う' => ['再取込ビル,1,A,再取込商事,2026-04-02,2026-04-02,95000,,,,,,'],
        ];
    }

    #[DataProvider('contractRowsWithAnotherKey')]
    public function test_a_contract_row_that_differs_in_one_key_element_is_imported(string $row): void
    {
        $this->registered($this->unit1A, $this->customer, '2026-04-01');

        $preview = $this->preview('contract', self::CONTRACT_HEADER . "\n{$row}\n")->assertOk();

        $this->assertSame([], $preview->viewData('skippedRows'), '見分けのキーが違うのにスキップした');
        $this->assertSame(1, $preview->viewData('validCount'));
    }

    /** @return array<string, array{0: string}> [CSV の行。登録済みは 1A・再取込商事・2020-04-01] */
    public static function pastRowsWithAnotherKey(): array
    {
        return [
            '区画が違う'   => ['再取込ビル,2,A,再取込商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,'],
            '顧客が違う'   => ['再取込ビル,1,A,別商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,'],
            '契約日が違う' => ['再取込ビル,1,A,再取込商事,2020-04-02,2020-04-02,2023-03-31,90000,,,,,,'],
        ];
    }

    #[DataProvider('pastRowsWithAnotherKey')]
    public function test_a_past_contract_row_that_differs_in_one_key_element_is_imported(string $row): void
    {
        $this->registered($this->unit1A, $this->customer, '2020-04-01', ['status' => 'terminated', 'contract_end_date' => '2023-03-31']);

        $preview = $this->preview('past-contract', self::PAST_HEADER . "\n{$row}\n")->assertOk();

        $this->assertSame([], $preview->viewData('skippedRows'), '見分けのキーが違うのにスキップした');
        $this->assertSame(1, $preview->viewData('validCount'));
    }

    public function test_a_row_with_a_tenant_name_is_not_matched_to_a_contract_without_a_customer(): void
    {
        $this->registered($this->unit1A, null, '2026-04-01');

        $preview = $this->preview('contract', self::CONTRACT_HEADER . "\n" . self::CONTRACT_ROW_1 . "\n")->assertOk();

        $this->assertSame([], $preview->viewData('skippedRows'), '顧客のいる行を「顧客の無い契約」と取り違えてスキップした');
        $this->assertSame(1, $preview->viewData('validCount'));
    }

    public function test_a_row_without_a_tenant_name_skips_the_same_contract_without_a_customer(): void
    {
        $registered = $this->registered($this->unit1A, null, '2026-04-01');

        $preview = $this->preview('contract', self::CONTRACT_HEADER . "\n再取込ビル,1,A,,2026-04-01,2026-04-01,95000,,,,,,\n")->assertOk();

        $message = "区画「再取込ビル 1A」の契約（契約日 2026-04-01・顧客 空欄）は既に登録済み（{$registered->contract_number}）のためスキップ";
        $this->assertSame([['row' => 2, 'message' => $message]], $preview->viewData('skippedRows'));
        $this->assertStringContainsString('行2: ' . $message, $preview->getContent());
    }

    /** @return array<string, array{0: string, 1: bool}> [CSV の行, 取り込んだあとで解約したか] */
    public static function contractRowsWithTheSameKey(): array
    {
        return [
            '家賃を改定した'   => ['再取込ビル,1,A,再取込商事,2026-04-01,2026-04-01,100000,8000,190000,1500,500,再取込商事 松山支店,', false],
            '備考が違う'       => ['再取込ビル,1,A,再取込商事,2026-04-01,2026-04-01,95000,8000,190000,1500,500,再取込商事 松山支店,メモを足した', false],
            '屋号が違う'       => ['再取込ビル,1,A,再取込商事,2026-04-01,2026-04-01,95000,8000,190000,1500,500,再取込商事 本店,', false],
            '賃料開始日が違う' => ['再取込ビル,1,A,再取込商事,2026-04-01,2026-05-01,95000,8000,190000,1500,500,再取込商事 松山支店,', false],
            '日付の書き方が違う'       => ['再取込ビル,1,A,再取込商事,2026/4/1,2026/4/1,95000,8000,190000,1500,500,再取込商事 松山支店,', false],
            '取り込んだあとで解約した' => [self::CONTRACT_ROW_1, true],
        ];
    }

    #[DataProvider('contractRowsWithTheSameKey')]
    public function test_a_contract_row_that_differs_only_outside_the_key_is_skipped(string $row, bool $terminate): void
    {
        $this->confirm('contract', self::CONTRACT_HEADER . "\n" . self::CONTRACT_ROW_1 . "\n");
        $registered = Contract::sole();
        if ($terminate) {
            $registered->update(['status' => 'terminated', 'contract_end_date' => '2026-08-31']);
        }

        $preview = $this->preview('contract', self::CONTRACT_HEADER . "\n{$row}\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::CONTRACT_SKIP, $registered->contract_number)]], $preview->viewData('skippedRows'));
        $this->assertSame(0, $preview->viewData('validCount'));
    }

    /** @return array<string, array{0: string}> [CSV の行。登録済みは PAST_ROW_1 を取り込んだもの] */
    public static function pastRowsWithTheSameKey(): array
    {
        return [
            '家賃が違う'   => ['再取込ビル,1,A,再取込商事,2020-04-01,2020-04-01,2023-03-31,100000,,,,,,期間満了で解約'],
            '解約日が違う' => ['再取込ビル,1,A,再取込商事,2020-04-01,2020-04-01,2023-06-30,90000,,,,,,期間満了で解約'],
            '備考が違う'   => ['再取込ビル,1,A,再取込商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,'],
            '日付の書き方が違う' => ['再取込ビル,1,A,再取込商事,2020/4/1,2020/4/1,2023/3/31,90000,,,,,,期間満了で解約'],
        ];
    }

    #[DataProvider('pastRowsWithTheSameKey')]
    public function test_a_past_contract_row_that_differs_only_outside_the_key_is_skipped(string $row): void
    {
        $this->confirm('past-contract', self::PAST_HEADER . "\n" . self::PAST_ROW_1 . "\n");
        $registered = Contract::sole();

        $preview = $this->preview('past-contract', self::PAST_HEADER . "\n{$row}\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::PAST_SKIP, $registered->contract_number)]], $preview->viewData('skippedRows'));
        $this->assertSame(0, $preview->viewData('validCount'));
    }

    public function test_a_contract_without_a_tenant_name_is_skipped_when_uploaded_again(): void
    {
        // 顧客の無い契約（テナント名が空欄）を取り込み、同じ CSV を上げ直す（取込が customer_id に null を書き、照合も null どうしで見る）
        $csv = self::CONTRACT_HEADER . "\n再取込ビル,1,A,,2026-04-01,2026-04-01,95000,,,,,,\n";
        $this->confirm('contract', $csv)->assertSessionHas('success', '契約インポート完了: 1件を登録しました');
        $registered = Contract::sole();
        $this->assertNull($registered->customer_id);

        $preview = $this->assertPreviewSkipsEveryRow('contract', $csv, 1);

        $this->assertSame(
            [['row' => 2, 'message' => "区画「再取込ビル 1A」の契約（契約日 2026-04-01・顧客 空欄）は既に登録済み（{$registered->contract_number}）のためスキップ"]],
            $preview->viewData('skippedRows')
        );
    }

    public function test_a_past_contract_for_a_customer_created_by_the_first_import_is_skipped_when_uploaded_again(): void
    {
        // データ移行でよくある形: 1 回目の取込が顧客を自動作成し、上げ直したときにはその顧客が登録済みになっている
        $csv = self::PAST_HEADER . "\n再取込ビル,1,A,新しい商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,\n";
        $this->confirm('past-contract', $csv)->assertSessionHas('success', '過去契約インポート完了: 契約 1件を登録、顧客 1件を自動作成');
        $registered = Contract::sole();

        $preview = $this->assertPreviewSkipsEveryRow('past-contract', $csv, 1);

        $this->assertSame(
            [['row' => 2, 'message' => "区画「再取込ビル 1A」の契約（契約日 2020-04-01・顧客 新しい商事）は既に登録済み（{$registered->contract_number}）のためスキップ"]],
            $preview->viewData('skippedRows')
        );
        $this->assertSame('過去契約 0件を新規作成', $preview->viewData('summary'), '登録済みの行の顧客を、自動作成の予定に数えた');
        $this->assertSame(1, Customer::where('name', '新しい商事')->count());
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: array<string, string>, 5: string}> */
    public static function badDateOnRegisteredRowCases(): array
    {
        return [
            '契約: 賃料開始日' => [
                'contract', self::CONTRACT_HEADER, '再取込ビル,1,A,再取込商事,2026-04-01,2026-02-30,95000,,,,,,',
                '2026-04-01', [], '賃料開始日「2026-02-30」の形式が不正です',
            ],
            '過去契約: 解約日' => [
                'past-contract', self::PAST_HEADER, '再取込ビル,1,A,再取込商事,2020-04-01,2020-04-01,2023-02-30,90000,,,,,,',
                '2020-04-01', ['status' => 'terminated', 'contract_end_date' => '2023-03-31'], '解約日「2023-02-30」の形式が不正です（YYYY-MM-DD）',
            ],
        ];
    }

    #[DataProvider('badDateOnRegisteredRowCases')]
    public function test_a_registered_row_with_a_bad_date_is_an_error_not_skipped(
        string $tab, string $header, string $row, string $date, array $extra, string $error
    ): void {
        // 日付の検査は照合の前にまとめて置く（見分けに使わない日付も。設計書 §4.4）。登録済みと同じキーでも、日付の誤りはエラー
        $this->registered($this->unit1A, $this->customer, $date, $extra);

        $preview = $this->preview($tab, "{$header}\n{$row}\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => $error]], $preview->viewData('rowErrors'));
        $this->assertSame([], $preview->viewData('skippedRows'));
    }

    public function test_when_the_same_contract_is_already_registered_twice_the_first_one_is_named(): void
    {
        // 以前の二重送信の名残（片づけはしない。照合は id のいちばん小さいものを理由に出す。設計書 §4.3）
        $first = $this->registered($this->unit1A, $this->customer, '2026-04-01');
        $this->registered($this->unit1A, $this->customer, '2026-04-01');

        $preview = $this->preview('contract', self::CONTRACT_HEADER . "\n" . self::CONTRACT_ROW_1 . "\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::CONTRACT_SKIP, $first->contract_number)]], $preview->viewData('skippedRows'));
        $this->assertSame(2, Contract::count(), '重複の片づけはしない（消さない）');
    }

    // ================================================================
    // 5・7・8: 削除した契約／まだ無い顧客／タブをまたぐ
    // ================================================================

    public function test_a_contract_row_matching_only_a_deleted_contract_is_imported(): void
    {
        $this->registered($this->unit1A, $this->customer, '2026-04-01')->delete();

        $preview = $this->preview('contract', self::CONTRACT_HEADER . "\n" . self::CONTRACT_ROW_1 . "\n")->assertOk();

        $this->assertSame([], $preview->viewData('skippedRows'), '削除した契約と突き合わせてスキップした');
        $this->assertSame(1, $preview->viewData('validCount'));
    }

    public function test_a_past_contract_row_matching_only_a_deleted_contract_is_imported(): void
    {
        $this->registered($this->unit1A, $this->customer, '2020-04-01', ['status' => 'terminated', 'contract_end_date' => '2023-03-31'])->delete();

        $preview = $this->preview('past-contract', self::PAST_HEADER . "\n" . self::PAST_ROW_1 . "\n")->assertOk();

        $this->assertSame([], $preview->viewData('skippedRows'), '削除した契約と突き合わせてスキップした');
        $this->assertSame(1, $preview->viewData('validCount'));
    }

    public function test_a_past_contract_row_for_a_new_customer_is_not_matched_to_a_contract_without_a_customer(): void
    {
        // まだ無い顧客（取込で自動作成する）の行を「顧客の無い契約」と取り違えない（設計書 §4.3）
        $this->registered($this->unit1A, null, '2020-04-01', ['status' => 'terminated', 'contract_end_date' => '2023-03-31']);
        $csv = self::PAST_HEADER . "\n再取込ビル,1,A,新しい商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,\n";

        $preview = $this->preview('past-contract', $csv)->assertOk();
        $this->assertSame([], $preview->viewData('skippedRows'), 'まだ無い顧客の行を「顧客の無い契約」と取り違えてスキップした');
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('past-contract', $csv)->assertSessionHas('success', '過去契約インポート完了: 契約 1件を登録、顧客 1件を自動作成');
        $this->assertSame(1, Customer::where('name', '新しい商事')->count());
    }

    public function test_a_contract_imported_on_the_contract_tab_and_then_terminated_is_skipped_on_the_past_contract_tab(): void
    {
        $this->confirm('contract', self::CONTRACT_HEADER . "\n" . self::CONTRACT_ROW_1 . "\n");
        $registered = Contract::sole();
        $registered->update(['status' => 'terminated', 'contract_end_date' => '2026-08-31']);

        $preview = $this->preview('past-contract', self::PAST_HEADER . "\n再取込ビル,1,A,再取込商事,2026-04-01,2026-04-01,2026-08-31,95000,,,,,,\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::CONTRACT_SKIP, $registered->contract_number)]], $preview->viewData('skippedRows'));
        $this->assertSame(0, $preview->viewData('validCount'));
    }

    public function test_a_contract_imported_on_the_past_contract_tab_is_skipped_on_the_contract_tab(): void
    {
        $this->confirm('past-contract', self::PAST_HEADER . "\n" . self::PAST_ROW_1 . "\n");
        $registered = Contract::sole();

        $preview = $this->preview('contract', self::CONTRACT_HEADER . "\n再取込ビル,1,A,再取込商事,2020-04-01,2020-04-01,90000,,,,,,\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::PAST_SKIP, $registered->contract_number)]], $preview->viewData('skippedRows'));
        $this->assertSame(0, $preview->viewData('validCount'));
    }

    // ================================================================
    // 9: CSV の中の重複
    // ================================================================

    /** @return array<string, array{0: string}> [2 行目。1 行目は CONTRACT_ROW_1] */
    public static function contractDuplicateRows(): array
    {
        return [
            '同じ行'                         => [self::CONTRACT_ROW_1],
            '階を空欄にして部屋番号に書いた' => ['再取込ビル,,1A,再取込商事,2026-04-01,2026-04-01,95000,,,,,,'],
            '日付の書き方が違う'             => ['再取込ビル,1,A,再取込商事,2026/4/1,2026/4/1,95000,,,,,,'],
            '家賃が違う'                     => ['再取込ビル,1,A,再取込商事,2026-04-01,2026-04-01,120000,,,,,,'],
        ];
    }

    #[DataProvider('contractDuplicateRows')]
    public function test_the_second_row_with_the_same_key_in_a_contract_csv_is_an_error(string $second): void
    {
        $csv = self::CONTRACT_HEADER . "\n" . self::CONTRACT_ROW_1 . "\n{$second}\n";

        $preview = $this->preview('contract', $csv)->assertOk();
        $this->assertSame([['row' => 3, 'message' => 'CSV内で行2と同じ契約が重複しています']], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));
        $this->assertStringContainsString('行3: CSV内で行2と同じ契約が重複しています', $preview->getContent());

        $this->confirm('contract', $csv)->assertSessionHas('success', '契約インポート完了: 1件を登録しました');
        $this->assertSame(1, Contract::count());
    }

    /** @return array<string, array{0: string, 1: string}> [1 行目, 2 行目]。見分けのキーが 1 つだけ違う */
    public static function contractRowsThatDifferInOneKeyElement(): array
    {
        return [
            '区画'         => [self::CONTRACT_ROW_1, '再取込ビル,2,A,再取込商事,2026-04-01,2026-04-01,95000,,,,,,'],
            '顧客'         => [self::CONTRACT_ROW_1, '再取込ビル,1,A,別商事,2026-04-01,2026-04-01,95000,,,,,,'],
            '顧客（空欄）' => [self::CONTRACT_ROW_1, '再取込ビル,1,A,,2026-04-01,2026-04-01,95000,,,,,,'],
            '契約日'       => [self::CONTRACT_ROW_1, '再取込ビル,1,A,再取込商事,2026-04-02,2026-04-02,95000,,,,,,'],
        ];
    }

    #[DataProvider('contractRowsThatDifferInOneKeyElement')]
    public function test_two_contract_rows_that_differ_in_one_key_element_are_both_imported(string $first, string $second): void
    {
        $preview = $this->preview('contract', self::CONTRACT_HEADER . "\n{$first}\n{$second}\n")->assertOk();

        $this->assertSame([], $preview->viewData('rowErrors'), '見分けのキーが違う 2 行を CSV 内の重複にした');
        $this->assertSame(2, $preview->viewData('validCount'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: array<string, mixed>, 5: string}>
     *   [URL のタブ, 見出し, 登録済みと同じ行, 契約日, 登録済みの契約の追加の列, スキップの理由の書式]
     */
    public static function registeredRowsWrittenTwice(): array
    {
        return [
            '契約'     => ['contract', self::CONTRACT_HEADER, self::CONTRACT_ROW_1, '2026-04-01', [], self::CONTRACT_SKIP],
            '過去契約' => ['past-contract', self::PAST_HEADER, self::PAST_ROW_1, '2020-04-01', ['status' => 'terminated', 'contract_end_date' => '2023-03-31'], self::PAST_SKIP],
        ];
    }

    #[DataProvider('registeredRowsWrittenTwice')]
    public function test_a_registered_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error(
        string $tab, string $header, string $row, string $date, array $extra, string $skipFormat
    ): void {
        // 最初の行は、照合の前に覚える（設計書 §4.5）。1 行目はスキップ・2 行目は重複のエラー
        $registered = $this->registered($this->unit1A, $this->customer, $date, $extra);

        $preview = $this->preview($tab, "{$header}\n{$row}\n{$row}\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf($skipFormat, $registered->contract_number)]], $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 3, 'message' => 'CSV内で行2と同じ契約が重複しています']], $preview->viewData('rowErrors'));
        $this->assertSame(0, $preview->viewData('validCount'));
    }

    /** @return array<string, array{0: string}> [同じ行を 2 回書く] */
    public static function pastDuplicateRows(): array
    {
        return [
            '顧客は登録済み' => [self::PAST_ROW_1],
            '顧客はまだ無い' => ['再取込ビル,1,A,新しい商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,'],
        ];
    }

    #[DataProvider('pastDuplicateRows')]
    public function test_the_second_row_with_the_same_key_in_a_past_contract_csv_is_an_error(string $row): void
    {
        $csv = self::PAST_HEADER . "\n{$row}\n{$row}\n";

        $preview = $this->preview('past-contract', $csv)->assertOk();

        $this->assertSame([['row' => 3, 'message' => 'CSV内で行2と同じ契約が重複しています']], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));
    }

    public function test_two_past_contract_rows_for_different_new_customers_are_both_imported(): void
    {
        // まだ無い顧客は名前で比べる（同じ区画・同じ契約日でも、名前が違えば別の契約）
        $csv = self::PAST_HEADER . "\n"
            . "再取込ビル,1,A,新しい商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,\n"
            . "再取込ビル,1,A,新しい商店,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,\n";

        $preview = $this->preview('past-contract', $csv)->assertOk();

        $this->assertSame([], $preview->viewData('rowErrors'));
        $this->assertSame(2, $preview->viewData('validCount'));
    }

    // ================================================================
    // 10: 警告はスキップ・エラーの行には出さない
    // ================================================================

    public function test_on_the_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported(): void
    {
        $registered = $this->registered($this->unit1A, $this->customer, '2026-04-01');   // 契約中
        $csv = self::CONTRACT_HEADER . "\n"
            . self::CONTRACT_ROW_1 . "\n"                                            // 行2: 登録済み → スキップ
            . "再取込ビル,1,A,別商事,2026-06-01,2026-06-01,90000,,,,,,\n"               // 行3: 取り込む（同じ区画に契約中がある）
            . "再取込ビル,1,A,別商事,2026-07-01,2026-07-01,abc,,,,,,\n";                 // 行4: 家賃の誤り → エラー

        $preview = $this->preview('contract', $csv)->assertOk();
        $html = $preview->getContent();
        $warning = "区画「再取込ビル 1A」には既にアクティブな契約（{$registered->contract_number}）が存在します";

        // 役割: 警告は取り込む行（行3）だけ
        $this->assertSame([['row' => 3, 'message' => $warning]], $preview->viewData('warnings'));
        $this->assertCount(1, $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 4, 'message' => '家賃「abc」は不正な値です']], $preview->viewData('rowErrors'));
        // 表示: 警告の一覧に行3だけが出る
        $this->assertSame(1, substr_count($html, '⚠ 行3: ' . $warning));
        $this->assertStringNotContainsString('⚠ 行2:', $html);
        $this->assertStringNotContainsString('⚠ 行4:', $html);
    }

    public function test_on_the_past_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported(): void
    {
        $this->registered($this->unit1A, $this->other, '2021-04-01');   // 契約中（期間の重なりの相手）
        $this->registered($this->unit1A, $this->customer, '2020-04-01', ['status' => 'terminated', 'contract_end_date' => '2023-03-31']);
        $csv = self::PAST_HEADER . "\n"
            . "再取込ビル,1,A,再取込商事,2020-04-01,2020-04-01,2099-03-31,90000,,,,,,\n"   // 行2: 登録済み → スキップ
            . "再取込ビル,1,A,再取込商事,2022-04-01,2022-04-01,2099-03-31,abc,,,,,,\n"     // 行3: 家賃の誤り → エラー
            . "再取込ビル,1,A,再取込商事,2024-04-01,2024-04-01,2099-03-31,90000,,,,,,\n";  // 行4: 取り込む

        $preview = $this->preview('past-contract', $csv)->assertOk();
        $html = $preview->getContent();
        $future = '解約日（2099-03-31）が今日より未来です（過去契約として登録します）';
        $overlap = '区画「再取込ビル 1A」に期間が重なるアクティブ契約があります（取込は実行）';

        // 役割: どの行も「解約日が未来」「期間の重なり」に当たるが、警告は取り込む行（行4）だけ
        $this->assertSame([['row' => 4, 'message' => $future], ['row' => 4, 'message' => $overlap]], $preview->viewData('warnings'));
        $this->assertCount(1, $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 3, 'message' => '家賃「abc」は不正な値です']], $preview->viewData('rowErrors'));
        // 表示
        $this->assertSame(1, substr_count($html, '⚠ 行4: ' . $future));
        $this->assertSame(1, substr_count($html, '⚠ 行4: ' . $overlap));
        $this->assertStringNotContainsString('⚠ 行2:', $html);
        $this->assertStringNotContainsString('⚠ 行3:', $html);
    }

    public function test_the_deleted_unit_notice_is_not_shown_for_a_skipped_row(): void
    {
        $unit3A = $this->unit(3, 'A');
        $registered = $this->registered($unit3A, $this->customer, '2020-04-01', ['status' => 'terminated', 'contract_end_date' => '2023-03-31']);
        $unit3A->delete();
        $csv = self::PAST_HEADER . "\n"
            . "再取込ビル,3,A,再取込商事,2020-04-01,2020-04-01,2023-03-31,90000,,,,,,\n"   // 行2: 登録済み → スキップ
            . "再取込ビル,3,A,再取込商事,2016-04-01,2016-04-01,2019-03-31,90000,,,,,,\n";  // 行3: 取り込む

        $preview = $this->preview('past-contract', $csv)->assertOk();
        $notice = '物件「再取込ビル」の区画「3A」は削除済みです。削除済みの区画のまま過去契約として取り込みます';

        $this->assertSame([['row' => 3, 'message' => $notice]], $preview->viewData('warnings'));
        // 削除済みの区画でも、スキップの理由は表示名で書く（今の警告と同じ）
        $this->assertSame([['row' => 2, 'message' => "区画「再取込ビル 3A」の契約（契約日 2020-04-01・顧客 再取込商事）は既に登録済み（{$registered->contract_number}）のためスキップ"]], $preview->viewData('skippedRows'));
        $this->assertStringNotContainsString('⚠ 行2:', $preview->getContent());
    }

    // ================================================================
    // 11: プレビューのあとに同じ契約が登録された
    // ================================================================

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: array<string, string>, 6: string}> */
    public static function registeredAfterPreviewCases(): array
    {
        return [
            '契約' => ['contract', self::CONTRACT_HEADER, self::CONTRACT_ROW_1, self::CONTRACT_ROW_2, '2026-04-01', [], '契約インポート完了: 1件を登録しました'],
            '過去契約' => [
                'past-contract', self::PAST_HEADER, self::PAST_ROW_1, self::PAST_ROW_2, '2020-04-01',
                ['status' => 'terminated', 'contract_end_date' => '2023-03-31'], '過去契約インポート完了: 契約 1件を登録',
            ],
        ];
    }

    #[DataProvider('registeredAfterPreviewCases')]
    public function test_a_contract_registered_after_the_preview_is_skipped_at_confirmation(
        string $tab, string $header, string $first, string $second, string $date, array $extra, string $success
    ): void {
        $form = $this->parseImportForm($this->preview($tab, "{$header}\n{$first}\n{$second}\n")->getContent(), $tab);

        // プレビューのあとで、1 行目と同じ契約が（別の画面・別のタブで）登録された
        $this->registered($this->unit1A, $this->customer, $date, $extra);

        $this->actingAs($this->executive())->post($form['action'], $form['fields'])
            ->assertRedirect(route('admin.tenant-import', ['tab' => $tab]))
            ->assertSessionHas('success', $success);
        $this->assertSame(2, Contract::count(), '確定で、プレビューのあとに登録された契約をもう一度入れた');
    }

    // ================================================================
    // 13: 登録済みの行は、金額に誤りがあってもスキップ
    // ================================================================

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: array<string, string>, 6: string}> */
    public static function badAmountCases(): array
    {
        return [
            '契約' => [
                'contract', self::CONTRACT_HEADER,
                '再取込ビル,1,A,再取込商事,2026-04-01,2026-04-01,abc,,,,,,',
                '再取込ビル,2,A,別商事,2026-05-01,2026-05-01,abc,,,,,,',
                '2026-04-01', [], self::CONTRACT_SKIP,
            ],
            '過去契約' => [
                'past-contract', self::PAST_HEADER,
                '再取込ビル,1,A,再取込商事,2020-04-01,2020-04-01,2023-03-31,abc,,,,,,',
                '再取込ビル,2,A,別商事,2019-04-01,2019-04-01,2021-03-31,abc,,,,,,',
                '2020-04-01', ['status' => 'terminated', 'contract_end_date' => '2023-03-31'], self::PAST_SKIP,
            ],
        ];
    }

    #[DataProvider('badAmountCases')]
    public function test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error(
        string $tab, string $header, string $registeredRow, string $unregisteredRow, string $date, array $extra, string $skipFormat
    ): void {
        $registered = $this->registered($this->unit1A, $this->customer, $date, $extra);

        $preview = $this->preview($tab, "{$header}\n{$registeredRow}\n{$unregisteredRow}\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf($skipFormat, $registered->contract_number)]], $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 3, 'message' => '家賃「abc」は不正な値です']], $preview->viewData('rowErrors'));
    }

    // ================================================================
    // 12: 共通部品の灰色の文／赤字の文（物件タブ）
    // ================================================================

    public function test_a_property_csv_whose_rows_are_all_registered_shows_the_gray_notice(): void
    {
        // 再取込ビル は setUp で登録済み
        $preview = $this->assertPreviewSkipsEveryRow('property', self::PROPERTY_HEADER . "\n再取込ビル,790-0001,愛媛県松山市,,,,,,\n", 1);

        $this->assertSame([['row' => 2, 'message' => '物件「再取込ビル」は既に登録済みのためスキップ']], $preview->viewData('skippedRows'));
    }

    public function test_a_property_csv_with_an_error_row_keeps_the_red_notice(): void
    {
        $preview = $this->assertPreviewOffersNoImport('property', self::PROPERTY_HEADER . "\n再取込ビル,790-0001,愛媛県松山市,,,,,,\n新しいビル,,,,,,,,\n");

        $this->assertCount(1, $preview->viewData('skippedRows'), 'スキップの行が無い（灰色と赤字の分かれ目を測れていない）');
        $this->assertSame([['row' => 3, 'message' => '住所が未入力です']], $preview->viewData('rowErrors'));
        $this->assertStringNotContainsString('すべての行が登録済みです', $preview->getContent());
    }

    // ================================================================
    // 14: タブの説明
    // ================================================================

    public function test_the_contract_tabs_explain_that_registered_contracts_are_skipped(): void
    {
        $html = $this->actingAs($this->executive())->get(route('admin.tenant-import'))->assertOk()->getContent();

        // タブごとに見る（どちらかのタブに 2 行とも入っていても、数だけでは分からない）
        $contractTab = $this->between($html, "x-show=\"activeTab === 'contract'\"", "x-show=\"activeTab === 'past-contract'\"");
        $pastTab = $this->between($html, "x-show=\"activeTab === 'past-contract'\"", '<script>');
        $this->assertSame(1, substr_count($contractTab, self::TAB_NOTE), '契約タブの説明に出ていない');
        $this->assertSame(1, substr_count($pastTab, self::TAB_NOTE), '過去契約タブの説明に出ていない');
    }

    // ================================================================
    // 部品
    // ================================================================

    private function unit(int $floor, string $room): Unit
    {
        return Unit::create([
            'property_id' => $this->property->id, 'floor' => $floor, 'room_number' => $room,
            'display_name' => Unit::generateDisplayName($floor, $room), 'status' => 'vacant', 'area_tsubo' => 10,
        ]);
    }

    /**
     * 画面で登録した契約の代わり（モデルで直接作る）。契約番号は取込が付ける番号（C-{今年}-NNN・C-{契約日の年}-NNN）と
     * ぶつからない年にする
     *
     * @param  array<string, mixed>  $overrides
     */
    private function registered(Unit $unit, ?Customer $customer, string $contractDate, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'contract_number' => 'C-1999-' . (900 + ++$this->seq),
            'department' => 'tenant', 'property_id' => $this->property->id, 'unit_id' => $unit->id,
            'customer_id' => $customer?->id, 'status' => 'active',
            'contract_date' => $contractDate, 'rent_start_date' => $contractDate, 'rent' => 95000,
        ], $overrides));
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "画面に {$from} が無い");
        $end = strpos($html, $to, $start + strlen($from));
        $this->assertNotFalse($end, "画面の {$from} のあとに {$to} が無い");

        return substr($html, $start, $end - $start);
    }
}
```

- [ ] **Step 3: 直す前のコードで落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/TenantContractReimportTest.php 2>&1 | grep -E -A1 -e '^[0-9]+\) ' -e '^Tests:' | grep -v -e '^--$'
```

期待: `Tests: 56, Assertions: 329, Failures: 37.`。テストごとの赤・緑と理由（試作の実測）:

- 赤 `test_uploading_the_same_csv_again_skips_every_row`（2 通り） — `取り込む行が残っている（tab=contract）` ／ `取り込む行が残っている（tab=past-contract）`
- 赤 `test_uploading_a_csv_with_an_added_row_imports_only_the_new_row`（2 通り） — `Failed asserting that two arrays are identical.` ×2
- 緑 `test_a_contract_row_that_differs_in_one_key_element_is_imported`（4 通り）
- 緑 `test_a_past_contract_row_that_differs_in_one_key_element_is_imported`（3 通り）
- 緑 `test_a_row_with_a_tenant_name_is_not_matched_to_a_contract_without_a_customer`
- 赤 `test_a_row_without_a_tenant_name_skips_the_same_contract_without_a_customer` — `Failed asserting that two arrays are identical.`
- 赤 `test_a_contract_row_that_differs_only_outside_the_key_is_skipped`（6 通り） — `Failed asserting that two arrays are identical.` ×6
- 赤 `test_a_past_contract_row_that_differs_only_outside_the_key_is_skipped`（4 通り） — `Failed asserting that two arrays are identical.` ×4
- 赤 `test_a_contract_without_a_tenant_name_is_skipped_when_uploaded_again` — `取り込む行が残っている（tab=contract）`
- 赤 `test_a_past_contract_for_a_customer_created_by_the_first_import_is_skipped_when_uploaded_again` — `取り込む行が残っている（tab=past-contract）`
- 緑 `test_a_registered_row_with_a_bad_date_is_an_error_not_skipped`（2 通り）
- 赤 `test_when_the_same_contract_is_already_registered_twice_the_first_one_is_named` — `Failed asserting that two arrays are identical.`
- 緑 `test_a_contract_row_matching_only_a_deleted_contract_is_imported`
- 緑 `test_a_past_contract_row_matching_only_a_deleted_contract_is_imported`
- 緑 `test_a_past_contract_row_for_a_new_customer_is_not_matched_to_a_contract_without_a_customer`
- 赤 `test_a_contract_imported_on_the_contract_tab_and_then_terminated_is_skipped_on_the_past_contract_tab` — `Failed asserting that two arrays are identical.`
- 赤 `test_a_contract_imported_on_the_past_contract_tab_is_skipped_on_the_contract_tab` — `Failed asserting that two arrays are identical.`
- 赤 `test_the_second_row_with_the_same_key_in_a_contract_csv_is_an_error`（4 通り） — `Failed asserting that two arrays are identical.` ×4
- 緑 `test_two_contract_rows_that_differ_in_one_key_element_are_both_imported`（4 通り）
- 赤 `test_a_registered_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error`（2 通り） — `Failed asserting that two arrays are identical.` ×2
- 赤 `test_the_second_row_with_the_same_key_in_a_past_contract_csv_is_an_error`（2 通り） — `Failed asserting that two arrays are identical.` ×2
- 緑 `test_two_past_contract_rows_for_different_new_customers_are_both_imported`
- 赤 `test_on_the_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported` — `Failed asserting that two arrays are identical.`
- 赤 `test_on_the_past_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported` — `Failed asserting that two arrays are identical.`
- 赤 `test_the_deleted_unit_notice_is_not_shown_for_a_skipped_row` — `Failed asserting that two arrays are identical.`
- 赤 `test_a_contract_registered_after_the_preview_is_skipped_at_confirmation`（2 通り） — `Failed asserting that two strings are equal.` ×2
- 赤 `test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error`（2 通り） — `Failed asserting that two arrays are identical.` ×2
- 赤 `test_a_property_csv_whose_rows_are_all_registered_shows_the_gray_notice` — `灰色の「すべての行が登録済み」の文が出ていない（tab=property）`
- 緑 `test_a_property_csv_with_an_error_row_keeps_the_red_notice`
- 赤 `test_the_contract_tabs_explain_that_registered_contracts_are_skipped` — `契約タブの説明に出ていない`

緑の 19 本は、直す前から成り立つことを守るテスト（キーの要素が 1 つ違えば別の契約として入る・削除した契約とは突き合わせない・まだ無い顧客の行は照合しない・日付の誤りはエラー・エラーの行があれば赤字）。直したあとも緑のままであることで、照合が広すぎないことを見る。

- [ ] **Step 4: コントローラ — クラスの docblock**（`app/Http/Controllers/Admin/TenantImportController.php`。Edit）

置き換える前（`app/Http/Controllers/Admin/TenantImportController.php`・`046c69c9` の 40 行目から）:

```php
 *   （物件・区画・顧客は重複の確認で 2 回目が「0件を登録しました」になった）。
 */
class TenantImportController extends Controller
```

置き換えた後:

```php
 *   （物件・区画・顧客は重複の確認で 2 回目が「0件を登録しました」になった）。
 * ⚠ 契約・過去契約は、登録済みの同じ契約の行をスキップする（上げ直しても二重にしない。
 *   設計書 2026-09-29-contract-reimport-design.md）。見分けのキーは 区画・顧客（テナント名が空欄なら顧客の無い契約）・契約日で、
 *   賃料・状態・解約日・備考は使わない。削除した契約とは突き合わせず、登録済みの契約は書き換えない。
 *   照合は findRegisteredContract() の 1 か所。行の検査では、日付の検査のあと・金額の検査の前に
 *   「CSV 内の重複 → 登録済みの照合」の順で見る（確定でも同じ検査をやり直す）。
 *   警告は行の中に貯め、取り込むと決めたときだけ画面の一覧へ移す（スキップ・エラーの行には出さない）。
 */
class TenantImportController extends Controller
```

- [ ] **Step 5: コントローラ — 契約タブ（`executeContract()`）**（Edit を 5 回。書いてある順に）

1 回目（初期化と、ループの頭で行の警告を空にする）:

置き換える前（`app/Http/Controllers/Admin/TenantImportController.php`・`046c69c9` の 688 行目から）:

```php
        // 行バリデーション
        $errors = [];
        $warnings = [];
        $validRows = [];
        $propertyCache = [];
        $customerCache = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
```

置き換えた後:

```php
        // 行バリデーション
        $errors = [];
        $warnings = [];
        $validRows = [];
        $skippedRows = [];
        $propertyCache = [];
        $customerCache = [];
        $contractKeyTracker = [];   // 見分けのキー => 最初の行（CSV 内の重複）

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            // 警告はいったん行の中に貯め、取り込むと決めたときだけ画面の一覧へ移す（クラスの docblock）
            $rowWarnings = [];
```

2 回目（契約中の契約の警告を、行の警告に貯める）:

置き換える前（`app/Http/Controllers/Admin/TenantImportController.php`・`046c69c9` の 779 行目から）:

```php
                $warnings[] = ['row' => $rowNum, 'message' => "区画「{$propName} {$unit->display_name}」には既にアクティブな契約（{$activeContract->contract_number}）が存在します"];
```

置き換えた後:

```php
                $rowWarnings[] = ['row' => $rowNum, 'message' => "区画「{$propName} {$unit->display_name}」には既にアクティブな契約（{$activeContract->contract_number}）が存在します"];
```

3 回目（日付の検査のあと・金額の検査の前に、CSV 内の重複と照合）:

置き換える前（`app/Http/Controllers/Admin/TenantImportController.php`・`046c69c9` の 796 行目から）:

```php
                $row['rent_start_date'] = $rentStartDate;
            }

            // 金額フィールドチェック
```

置き換えた後:

```php
                $row['rent_start_date'] = $rentStartDate;
            }

            // CSV 内の重複（見分けのキー＝区画・顧客・契約日）。最初の行は、照合の前にここで覚える（クラスの docblock）
            $contractKey = $unit->id . '|' . ($customer ? 'id:' . $customer->id : 'none') . '|' . $contractDate;
            if (isset($contractKeyTracker[$contractKey])) {
                $errors[] = ['row' => $rowNum, 'message' => "CSV内で行{$contractKeyTracker[$contractKey]}と同じ契約が重複しています"];
                continue;
            }
            $contractKeyTracker[$contractKey] = $rowNum;

            // 登録済みの同じ契約はスキップ（書き換えない。金額に誤りがあってもスキップ）
            $registered = $this->findRegisteredContract($unit->id, $customer?->id, $contractDate);
            if ($registered) {
                $customerLabel = $row['customer_name'] !== '' ? $row['customer_name'] : '空欄';
                $skippedRows[] = ['row' => $rowNum, 'message' => "区画「{$propName} {$unit->display_name}」の契約（契約日 {$contractDate}・顧客 {$customerLabel}）は既に登録済み（{$registered->contract_number}）のためスキップ"];
                continue;
            }

            // 金額フィールドチェック
```

4 回目（取り込むと決めた位置で、行の警告を画面の一覧へ移す）:

置き換える前（`app/Http/Controllers/Admin/TenantImportController.php`・`046c69c9` の 820 行目から）:

```php
            $row['_property_id'] = $property->id;
            $row['_unit_id'] = $unit->id;
            $row['_customer_id'] = $customer?->id;  // customer_name 空欄なら null
```

置き換えた後:

```php
            $warnings = array_merge($warnings, $rowWarnings);

            $row['_property_id'] = $property->id;
            $row['_unit_id'] = $unit->id;
            $row['_customer_id'] = $customer?->id;  // customer_name 空欄なら null
```

5 回目（view へスキップの一覧を渡す）:

置き換える前（`app/Http/Controllers/Admin/TenantImportController.php`・`046c69c9` の 836 行目から）:

```php
                'skippedRows'  => [],
                'summary'      => '契約 ' . count($validRows) . '件を新規作成',
```

置き換えた後:

```php
                'skippedRows'  => $skippedRows,
                'summary'      => '契約 ' . count($validRows) . '件を新規作成',
```

- [ ] **Step 6: コントローラ — 過去契約タブ（`executePastContract()`）**（Edit を 6 回。書いてある順に）

1 回目（初期化と、ループの頭で行の警告を空にする）:

置き換える前（`app/Http/Controllers/Admin/TenantImportController.php`・`046c69c9` の 921 行目から）:

```php
        $customerCache = [];        // 既存顧客のキャッシュ
        $customerCreateList = [];   // 自動作成予定の顧客名リスト

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
```

置き換えた後:

```php
        $customerCache = [];        // 既存顧客のキャッシュ
        $customerCreateList = [];   // 自動作成予定の顧客名リスト
        $skippedRows = [];
        $contractKeyTracker = [];   // 見分けのキー => 最初の行（CSV 内の重複）

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            // 警告はいったん行の中に貯め、取り込むと決めたときだけ画面の一覧へ移す（クラスの docblock）
            $rowWarnings = [];
```

2 回目（解約日が未来の警告を、行の警告に貯める）:

置き換える前（`app/Http/Controllers/Admin/TenantImportController.php`・`046c69c9` の 1010 行目から）:

```php
                $warnings[] = ['row' => $rowNum, 'message' => "解約日（{$endDate}）が今日より未来です（過去契約として登録します）"];
```

置き換えた後:

```php
                $rowWarnings[] = ['row' => $rowNum, 'message' => "解約日（{$endDate}）が今日より未来です（過去契約として登録します）"];
```

3 回目（顧客の確かめのあと・期間の重なりの前に、CSV 内の重複と照合。まだ無い顧客の行は照合しない）:

置き換える前（`app/Http/Controllers/Admin/TenantImportController.php`・`046c69c9` の 1036 行目から）:

```php
                if (!in_array($custName, $customerCreateList, true)) {
                    $customerCreateList[] = $custName;
                }
            }

            // 期間重なりチェック（active 契約のみ対象、警告のみで取込は実行）
```

置き換えた後:

```php
                if (!in_array($custName, $customerCreateList, true)) {
                    $customerCreateList[] = $custName;
                }
            }

            // CSV 内の重複（見分けのキーは契約タブと同じ。まだ無い顧客は名前で比べる）。最初の行は、照合の前にここで覚える
            $existingCustomer = $customerCache[$custName];
            $contractKey = $unit->id . '|' . ($existingCustomer ? 'id:' . $existingCustomer->id : 'name:' . $custName) . '|' . $contractDate;
            if (isset($contractKeyTracker[$contractKey])) {
                $errors[] = ['row' => $rowNum, 'message' => "CSV内で行{$contractKeyTracker[$contractKey]}と同じ契約が重複しています"];
                continue;
            }
            $contractKeyTracker[$contractKey] = $rowNum;

            // 登録済みの同じ契約はスキップ（契約タブで入れた契約も同じキーで見る。書き換えない）。
            // ⚠ まだ無い顧客（取込で自動作成する予定）の行は照合しない。null を渡すと「顧客の無い契約」と取り違える
            if ($existingCustomer) {
                $registered = $this->findRegisteredContract($unit->id, $existingCustomer->id, $contractDate);
                if ($registered) {
                    $skippedRows[] = ['row' => $rowNum, 'message' => "区画「{$propName} {$unit->display_name}」の契約（契約日 {$contractDate}・顧客 {$custName}）は既に登録済み（{$registered->contract_number}）のためスキップ"];
                    continue;
                }
            }

            // 期間重なりチェック（active 契約のみ対象、警告のみで取込は実行）
```

4 回目（期間の重なりの警告を、行の警告に貯める）:

置き換える前（`app/Http/Controllers/Admin/TenantImportController.php`・`046c69c9` の 1053 行目から）:

```php
                $warnings[] = ['row' => $rowNum, 'message' => "区画「{$propName} {$unit->display_name}」に期間が重なるアクティブ契約があります（取込は実行）"];
```

置き換えた後:

```php
                $rowWarnings[] = ['row' => $rowNum, 'message' => "区画「{$propName} {$unit->display_name}」に期間が重なるアクティブ契約があります（取込は実行）"];
```

5 回目（削除済みの区画の注意も行の警告に貯め、取り込むと決めた位置で画面の一覧へ移す）:

置き換える前（`app/Http/Controllers/Admin/TenantImportController.php`・`046c69c9` の 1077 行目から）:

```php
            // 注意はこの行を取り込むと決めた位置で積む（途中で積むと、エラーになる行にも出てしまう）
            if ($unit->trashed()) {
                $warnings[] = ['row' => $rowNum, 'message' => "物件「{$propName}」の区画「{$displayName}」は削除済みです。削除済みの区画のまま過去契約として取り込みます"];
            }
```

置き換えた後:

```php
            // 注意もこの行の警告に貯め、取り込むと決めたここで画面の一覧へ移す（クラスの docblock）
            if ($unit->trashed()) {
                $rowWarnings[] = ['row' => $rowNum, 'message' => "物件「{$propName}」の区画「{$displayName}」は削除済みです。削除済みの区画のまま過去契約として取り込みます"];
            }
            $warnings = array_merge($warnings, $rowWarnings);
```

6 回目（view へスキップの一覧を渡す）:

置き換える前（`app/Http/Controllers/Admin/TenantImportController.php`・`046c69c9` の 1099 行目から）:

```php
                'skippedRows'  => [],
                'summary'      => '過去契約 ' . count($validRows) . '件を新規作成'
```

置き換えた後:

```php
                'skippedRows'  => $skippedRows,
                'summary'      => '過去契約 ' . count($validRows) . '件を新規作成'
```

- [ ] **Step 7: コントローラ — 照合のメソッド**（Edit。`getNextPropertyCodeNum()` の前に足す）

置き換える前（`app/Http/Controllers/Admin/TenantImportController.php`・`046c69c9` の 1324 行目から）:

```php
    /**
     * 物件コードの次の番号を取得
     */
    private function getNextPropertyCodeNum(): int
```

置き換えた後:

```php
    /**
     * 登録済みの同じ契約（区画・顧客・契約日。クラスの docblock）。顧客が null なら「顧客の無い契約」と比べる。
     * 削除した契約とは突き合わせない（Contract の既定の絞り込み）。同じキーの契約が 2 件以上あれば、
     * id のいちばん小さいもの（最初に登録されたもの）を返す（以前の二重送信の名残。消さない）。
     * ⚠ 日付は whereDate で比べる（テストの SQLite は date キャストの値を `Y-m-d 00:00:00` で保存するので、素の where は一致しない。
     *   2026-09-29 に実測）
     */
    private function findRegisteredContract(int $unitId, ?int $customerId, string $contractDate): ?Contract
    {
        return Contract::where('unit_id', $unitId)
            ->when(
                $customerId === null,
                fn ($query) => $query->whereNull('customer_id'),
                fn ($query) => $query->where('customer_id', $customerId)
            )
            ->whereDate('contract_date', $contractDate)
            ->orderBy('id')
            ->first();
    }

    /**
     * 物件コードの次の番号を取得
     */
    private function getNextPropertyCodeNum(): int
```

- [ ] **Step 8: 確認画面の灰色の文**（`resources/views/admin/tenant-import/_preview.blade.php`。Edit）

置き換える前（`resources/views/admin/tenant-import/_preview.blade.php`・`046c69c9` の 99 行目から）:

```blade
        @else
            <div style="font-size: 13px; color: #dc2626;">インポート可能なデータがありません。CSVを修正してください。</div>
        @endif
```

置き換えた後:

```blade
        @elseif(count($rowErrors ?? []) === 0)
            {{-- すべての行が登録済み（スキップ）で、エラーも無い（設計書 2026-09-29-contract-reimport-design.md §4.7 (2)）。
                 確認画面に来る CSV は必ず 1 行以上あるので、このときスキップは 1 件以上ある --}}
            <div style="font-size: 13px; color: #6b7280;">すべての行が登録済みです（スキップ {{ count($skippedRows ?? []) }} 件）。取り込む行はありません。</div>
        @else
            <div style="font-size: 13px; color: #dc2626;">インポート可能なデータがありません。CSVを修正してください。</div>
        @endif
```

- [ ] **Step 9: タブの説明**（`resources/views/admin/tenant-import/index.blade.php`。Edit を 2 回）

契約タブ:

置き換える前（`resources/views/admin/tenant-import/index.blade.php`・`046c69c9` の 151 行目から）:

```blade
                        <li>契約作成時に区画のステータスが「入居中」に更新されます</li>
```

置き換えた後:

```blade
                        <li>契約作成時に区画のステータスが「入居中」に更新されます</li>
                        <li>同じ区画・テナント名・契約日の契約が登録済みなら、その行はスキップされます（家賃などが違っても書き換えません）</li>
```

過去契約タブ:

置き換える前（`resources/views/admin/tenant-import/index.blade.php`・`046c69c9` の 183 行目から）:

```blade
                        <li>同一区画に期間が重なる契約があっても警告のみで取込実行されます</li>
```

置き換えた後:

```blade
                        <li>同一区画に期間が重なる契約があっても警告のみで取込実行されます</li>
                        <li>同じ区画・テナント名・契約日の契約が登録済みなら、その行はスキップされます（家賃などが違っても書き換えません）</li>
```

- [ ] **Step 10: 通ることと、関係するテストが緑のままであることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/TenantContractReimportTest.php 2>&1 | tail -1 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/TenantContractReimportTest.php tests/Feature/Admin/TenantUnitImportTest.php tests/Feature/Admin/TenantImportDoubleSubmitTest.php tests/Feature/Admin/TenantImportRejectionTest.php tests/Feature/Admin/MansionImportTest.php 2>&1 | tail -1
```

期待: `OK (56 tests, 484 assertions)` ／ `OK (113 tests, 1356 assertions)`（`SubmitsImportPreview` を使うもう 1 本の `MansionImportTest` も流す＝共用の道具を変えたので、両方の利用者で確かめる）

- [ ] **Step 11: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && git add tests/Concerns/SubmitsImportPreview.php tests/Feature/Admin/TenantContractReimportTest.php app/Http/Controllers/Admin/TenantImportController.php resources/views/admin/tenant-import/_preview.blade.php resources/views/admin/tenant-import/index.blade.php && git commit -m "$(cat <<'EOF'
fix: テナントの契約の取込で登録済みの同じ契約をスキップする

同じ CSV を上げ直すと契約・過去契約が二重に入っていた。区画・顧客・契約日が同じ登録済みの契約の行は
確認画面でスキップし、CSV の中の同じ契約の 2 行目はエラーにする。警告は取り込む行の分だけ出す。
すべての行がスキップのときは灰色の文を出し、タブの説明に 1 行ずつ足す。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 3: 賃貸マンションの部屋契約・駐車場契約

**Files:**
- Create: `tests/Feature/Admin/MansionContractReimportTest.php`
- Modify: `app/Http/Controllers/Admin/MansionImportController.php`（クラスの docblock・2 経路の初期化・部屋契約・駐車場契約・照合のメソッド 2 つ）
- Modify: `resources/views/admin/mansion-import/_preview.blade.php`（灰色の文）
- Modify: `resources/views/admin/mansion-import/index.blade.php`（タブの説明 2 行）

**Interfaces:**
- Consumes: Task 2 の `SubmitsImportPreview::assertPreviewSkipsEveryRow()`・`ALL_ROWS_REGISTERED` ／ `Tests\Concerns\CreatesMansionSchema::createMansionSchema()`（`ms_*` はテスト用 trait で作る）／ `preview()`・`confirm()`・`assertPreviewOffersNoImport()`・`executive()`
- Produces（Task 4 が名前を見る）:
  - `MansionImportController::findRegisteredRoomContract(int $roomId, int $tenantId, ?string $contractDate, ?string $moveInDate): ?MsContract`（private）
  - `MansionImportController::findRegisteredParkingContract(int $parkingId, int $tenantId, ?string $contractDate, ?string $startDate): ?MsParkingContract`（private）
  - 部屋契約・駐車場契約の view データ `skippedRows`

- [ ] **Step 1: テストを書く**（`tests/Feature/Admin/MansionContractReimportTest.php` を新しく作る。この内容そのまま）

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\MsContract;
use App\Models\MsParking;
use App\Models\MsParkingContract;
use App\Models\MsProperty;
use App\Models\MsRoom;
use App\Models\MsTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesMansionSchema;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * 賃貸マンションの部屋契約・駐車場契約の CSV 取込を、上げ直しても二重にしない（設計書 2026-09-29-contract-reimport-design.md）。
 *
 * ⚠ 直す前（2026-09-29 に試作で実測）: 同じ CSV を上げ直すと、契約中の行は警告のうえ・退去済みの行は何も出ずに、同じ契約がもう一度入った。
 * ⚠ 見分けのキーは 部屋（駐車場）・入居者・契約日・入居日（開始日）（設計書 §4.2）。日付は任意の列なので、
 *   空欄どうしは同じ・片方だけ空欄なら違う契約とみなす。賃料・状態・退去日（終了日）・メモは使わない。
 * ⚠ どれも、プレビューが描いた確定のフォームを分解してそのまま送り返す往復（SubmitsImportPreview。Bug #47 / #54 ②）。
 *   スキップの理由は「行N: …」の全文で見て、役割（viewData）と表示（画面の文字）を別々に見る（Bug #43 / #54 ④）。
 * ⚠ 「入る」を見るテスト（見分けのキーが 1 つ違う・片方だけ空欄の日付）は、直す前のコードでも緑になる。
 *   照合が広すぎる書き換えを捕まえる役（計画 docs/superpowers/plans/2026-09-29-contract-reimport.md の変異テストで赤を確かめる）。
 * ⚠ URL のタブは room-contract / parking-contract（ハイフン）、戻り先のタブは room_contract / parking_contract（下線）。
 */
class MansionContractReimportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMansionSchema;
    use ParsesForms;
    use SubmitsImportPreview;

    private const ROOM_HEADER = '物件名,部屋番号,入居者名,契約日,入居日,退去日,家賃,共益費,敷金,礼金,担当者ユーザー名,メモ';
    private const PARKING_HEADER = '物件名,駐車場番号,入居者名,紐付部屋番号,契約日,開始日,終了日,月額料金,敷金,担当者ユーザー名,メモ';
    private const TENANT_HEADER = '区分,氏名,電話番号,メールアドレス,勤務先,緊急連絡先氏名,緊急連絡先電話,続柄,備考';

    private const ROOM_ROW_1 = '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-15,,55000,3000,55000,55000,,';
    private const ROOM_ROW_2 = '再取込ハイツ,102,鈴木次郎,2024-05-01,2024-05-15,,60000,,,,,';
    private const PARKING_ROW_1 = '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-15,,8000,8000,,';
    private const PARKING_ROW_2 = '再取込ハイツ,P-2,鈴木次郎,,2024-05-01,2024-05-15,,9000,,,';

    /** ROOM_ROW_1 と同じ契約のスキップの理由（%d は登録済みの契約の ID） */
    private const ROOM_SKIP = '部屋「再取込ハイツ 101」の入居者 山田太郎 の契約（契約日 2024-04-01・入居日 2024-04-15）は既に登録済み（既存契約 ID: %d）のためスキップ';

    /** PARKING_ROW_1 と同じ契約のスキップの理由（%d は登録済みの契約の ID） */
    private const PARKING_SKIP = '駐車場「再取込ハイツ P-1」の入居者 山田太郎 の契約（契約日 2024-04-01・開始日 2024-04-15）は既に登録済み（既存契約 ID: %d）のためスキップ';

    private const ROOM_TAB_NOTE = '<li>同じ部屋・入居者名・契約日・入居日の契約が登録済みなら、その行はスキップされます（家賃などが違っても書き換えません）</li>';
    private const PARKING_TAB_NOTE = '<li>同じ駐車場・入居者名・契約日・開始日の契約が登録済みなら、その行はスキップされます（月額料金などが違っても書き換えません）</li>';

    private MsRoom $room101;

    private MsParking $parkingP1;

    private MsTenant $yamada;

    private MsTenant $suzuki;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMansionSchema();

        $property = MsProperty::create([
            'property_code' => 'MS-RE-1', 'property_name' => '再取込ハイツ', 'ownership_type' => 'self_owned',
            'address' => '愛媛県松山市', 'created_by' => 1,
        ]);
        $this->room101 = MsRoom::create(['property_id' => $property->id, 'room_number' => '101', 'status' => 'vacant']);
        MsRoom::create(['property_id' => $property->id, 'room_number' => '102', 'status' => 'vacant']);
        $this->parkingP1 = MsParking::create(['property_id' => $property->id, 'parking_number' => 'P-1', 'monthly_fee' => 8000, 'status' => 'vacant']);
        MsParking::create(['property_id' => $property->id, 'parking_number' => 'P-2', 'monthly_fee' => 9000, 'status' => 'vacant']);
        $this->yamada = MsTenant::create(['tenant_type' => 'resident', 'name' => '山田太郎']);
        $this->suzuki = MsTenant::create(['tenant_type' => 'resident', 'name' => '鈴木次郎']);
    }

    /** 取込画面の URL の前半（SubmitsImportPreview が使う） */
    private function importBasePath(): string
    {
        return '/admin/mansion-import';
    }

    // ================================================================
    // 1・2: 同じ CSV を上げ直す／行を足した CSV を上げ直す
    // ================================================================

    /** @return array<string, array{0: string, 1: string, 2: string}> [URL のタブ, 数える表, 2 行の CSV] */
    public static function twoRowCsvs(): array
    {
        return [
            '部屋契約'   => ['room-contract', 'ms_contracts', self::ROOM_HEADER . "\n" . self::ROOM_ROW_1 . "\n" . self::ROOM_ROW_2 . "\n"],
            '駐車場契約' => ['parking-contract', 'ms_parking_contracts', self::PARKING_HEADER . "\n" . self::PARKING_ROW_1 . "\n" . self::PARKING_ROW_2 . "\n"],
        ];
    }

    #[DataProvider('twoRowCsvs')]
    public function test_uploading_the_same_csv_again_skips_every_row(string $tab, string $table, string $csv): void
    {
        $this->confirm($tab, $csv);
        $this->assertSame(2, DB::table($table)->count(), '1 回目で 2 件が入っていない（測定が無効）');

        $this->assertPreviewSkipsEveryRow($tab, $csv, 2);
        $this->assertSame(2, DB::table($table)->count());
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}> */
    public static function addedRowCases(): array
    {
        return [
            '部屋契約' => ['room-contract', MsContract::class, self::ROOM_HEADER, self::ROOM_ROW_1, self::ROOM_ROW_2, self::ROOM_SKIP, '部屋契約インポート完了: 1件を登録しました'],
            '駐車場契約' => ['parking-contract', MsParkingContract::class, self::PARKING_HEADER, self::PARKING_ROW_1, self::PARKING_ROW_2, self::PARKING_SKIP, '駐車場契約インポート完了: 1件を登録しました'],
        ];
    }

    #[DataProvider('addedRowCases')]
    public function test_uploading_a_csv_with_an_added_row_imports_only_the_new_row(
        string $tab, string $model, string $header, string $first, string $added, string $skipFormat, string $success
    ): void {
        $this->confirm($tab, "{$header}\n{$first}\n");
        $registered = $model::sole();

        $csv = "{$header}\n{$first}\n{$added}\n";
        $preview = $this->preview($tab, $csv)->assertOk();
        $html = $preview->getContent();
        $message = sprintf($skipFormat, $registered->id);

        // 役割: 1 行目はスキップ（エラーではない）・足した行は取り込む
        $this->assertSame([['row' => 2, 'message' => $message]], $preview->viewData('skippedRows'));
        $this->assertSame([], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));
        // 表示: 灰色の一覧に全文・件数のチップ・ボタンの下の注記（Bug #43: 全文で見る）
        $this->assertStringContainsString('行2: ' . $message, $html);
        $this->assertStringContainsString('スキップ: <strong>1</strong> 件', $html);
        $this->assertStringContainsString('※ 既存データ（1件）はスキップされます', $html);

        $this->confirm($tab, $csv)->assertSessionHas('success', $success);
        $this->assertSame(2, $model::count());
    }

    // ================================================================
    // 3・4: 見分けのキーが 1 つ違えば入る／キー以外が違ってもスキップ
    // ================================================================

    /** @return array<string, array{0: string, 1: string}> [URL のタブ, CSV の行]。登録済みは 101（P-1）・山田太郎・2024-04-01・2024-04-15 */
    public static function rowsWithAnotherKey(): array
    {
        return [
            '部屋契約: 部屋が違う'       => ['room-contract', '再取込ハイツ,102,山田太郎,2024-04-01,2024-04-15,,,,,,,'],
            '部屋契約: 入居者が違う'     => ['room-contract', '再取込ハイツ,101,鈴木次郎,2024-04-01,2024-04-15,,,,,,,'],
            '部屋契約: 契約日が違う'     => ['room-contract', '再取込ハイツ,101,山田太郎,2024-04-02,2024-04-15,,,,,,,'],
            '部屋契約: 入居日が違う'     => ['room-contract', '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-16,,,,,,,'],
            '駐車場契約: 駐車場が違う'   => ['parking-contract', '再取込ハイツ,P-2,山田太郎,,2024-04-01,2024-04-15,,8000,,,'],
            '駐車場契約: 入居者が違う'   => ['parking-contract', '再取込ハイツ,P-1,鈴木次郎,,2024-04-01,2024-04-15,,8000,,,'],
            '駐車場契約: 契約日が違う'   => ['parking-contract', '再取込ハイツ,P-1,山田太郎,,2024-04-02,2024-04-15,,8000,,,'],
            '駐車場契約: 開始日が違う'   => ['parking-contract', '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-16,,8000,,,'],
        ];
    }

    #[DataProvider('rowsWithAnotherKey')]
    public function test_a_row_that_differs_in_one_key_element_is_imported(string $tab, string $row): void
    {
        $this->registeredRoomContract('2024-04-01', '2024-04-15');
        $this->registeredParkingContract('2024-04-01', '2024-04-15');

        $preview = $this->preview($tab, $this->header($tab) . "\n{$row}\n")->assertOk();

        $this->assertSame([], $preview->viewData('skippedRows'), '見分けのキーが違うのにスキップした');
        $this->assertSame(1, $preview->viewData('validCount'));
    }

    /** @return array<string, array{0: string, 1: string}> [URL のタブ, CSV の行]。登録済みは ROOM_ROW_1 / PARKING_ROW_1 を取り込んだもの */
    public static function rowsWithTheSameKey(): array
    {
        return [
            '部屋契約: 家賃を改定した'     => ['room-contract', '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-15,,60000,3000,55000,55000,,'],
            '部屋契約: 退去した'           => ['room-contract', '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-15,2025-03-31,55000,3000,55000,55000,,'],
            '部屋契約: メモが違う'         => ['room-contract', '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-15,,55000,3000,55000,55000,,メモを足した'],
            '部屋契約: 担当者が違う'       => ['room-contract', '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-15,,55000,3000,55000,55000,いない人,'],
            '部屋契約: 日付の書き方が違う' => ['room-contract', '再取込ハイツ,101,山田太郎,2024/4/1,2024/4/15,,55000,3000,55000,55000,,'],
            '駐車場契約: 月額料金を改定した' => ['parking-contract', '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-15,,9000,8000,,'],
            '駐車場契約: 終了した'         => ['parking-contract', '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-15,2025-03-31,8000,8000,,'],
            '駐車場契約: 敷金が違う'       => ['parking-contract', '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-15,,8000,16000,,'],
            '駐車場契約: 紐付部屋番号が違う' => ['parking-contract', '再取込ハイツ,P-1,山田太郎,102,2024-04-01,2024-04-15,,8000,8000,,'],
            '駐車場契約: 日付の書き方が違う' => ['parking-contract', '再取込ハイツ,P-1,山田太郎,,2024/4/1,2024/4/15,,8000,8000,,'],
        ];
    }

    #[DataProvider('rowsWithTheSameKey')]
    public function test_a_row_that_differs_only_outside_the_key_is_skipped(string $tab, string $row): void
    {
        $room = $tab === 'room-contract';
        $this->confirm($tab, $this->header($tab) . "\n" . ($room ? self::ROOM_ROW_1 : self::PARKING_ROW_1) . "\n");
        $registered = $room ? MsContract::sole() : MsParkingContract::sole();

        $preview = $this->preview($tab, $this->header($tab) . "\n{$row}\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf($room ? self::ROOM_SKIP : self::PARKING_SKIP, $registered->id)]], $preview->viewData('skippedRows'));
        $this->assertSame(0, $preview->viewData('validCount'));
        // スキップの行には警告を出さない（担当者が見つからない・紐付部屋に契約が無い、はこの行の警告だった）
        $this->assertSame([], $preview->viewData('warnings'));
    }

    public function test_when_the_same_room_contract_is_already_registered_twice_the_first_one_is_named(): void
    {
        $first = $this->registeredRoomContract('2024-04-01', '2024-04-15');
        $this->registeredRoomContract('2024-04-01', '2024-04-15');

        $preview = $this->preview('room-contract', self::ROOM_HEADER . "\n" . self::ROOM_ROW_1 . "\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::ROOM_SKIP, $first->id)]], $preview->viewData('skippedRows'));
        $this->assertSame(2, MsContract::count(), '重複の片づけはしない（消さない）');
    }

    public function test_when_the_same_parking_contract_is_already_registered_twice_the_first_one_is_named(): void
    {
        $first = $this->registeredParkingContract('2024-04-01', '2024-04-15');
        $this->registeredParkingContract('2024-04-01', '2024-04-15');

        $preview = $this->preview('parking-contract', self::PARKING_HEADER . "\n" . self::PARKING_ROW_1 . "\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::PARKING_SKIP, $first->id)]], $preview->viewData('skippedRows'));
        $this->assertSame(2, MsParkingContract::count(), '重複の片づけはしない（消さない）');
    }

    // ================================================================
    // 6: 日付が空欄
    // ================================================================

    /**
     * @return array<string, array{0: string, 1: ?string, 2: ?string, 3: string, 4: string, 5: bool}>
     *   [URL のタブ, 登録済みの契約日, 登録済みの入居日（開始日）, CSV の契約日, CSV の入居日（開始日）, スキップするか]
     */
    public static function blankDateCases(): array
    {
        $cases = [];
        foreach (['部屋契約' => 'room-contract', '駐車場契約' => 'parking-contract'] as $label => $tab) {
            $second = $tab === 'room-contract' ? '入居日' : '開始日';
            $cases += [
                "{$label}: 2 つとも空欄どうし"               => [$tab, null, null, '', '', true],
                "{$label}: 契約日は空欄どうし・{$second}は同じ" => [$tab, null, '2024-04-15', '', '2024-04-15', true],
                "{$label}: 契約日だけ登録済みが空欄"         => [$tab, null, '2024-04-15', '2024-04-01', '2024-04-15', false],
                "{$label}: 契約日だけ CSV が空欄"            => [$tab, '2024-04-01', '2024-04-15', '', '2024-04-15', false],
                "{$label}: {$second}だけ登録済みが空欄"      => [$tab, '2024-04-01', null, '2024-04-01', '2024-04-15', false],
                "{$label}: {$second}だけ CSV が空欄"         => [$tab, '2024-04-01', '2024-04-15', '2024-04-01', '', false],
            ];
        }

        return $cases;
    }

    #[DataProvider('blankDateCases')]
    public function test_blank_dates_match_only_blank_dates(string $tab, ?string $registeredContract, ?string $registeredSecond, string $csvContract, string $csvSecond, bool $skip): void
    {
        $room = $tab === 'room-contract';
        $registered = $room
            ? $this->registeredRoomContract($registeredContract, $registeredSecond)
            : $this->registeredParkingContract($registeredContract, $registeredSecond);
        $row = $room
            ? "再取込ハイツ,101,山田太郎,{$csvContract},{$csvSecond},,55000,,,,,"
            : "再取込ハイツ,P-1,山田太郎,,{$csvContract},{$csvSecond},,8000,,,";

        $preview = $this->preview($tab, $this->header($tab) . "\n{$row}\n")->assertOk();

        if (! $skip) {
            $this->assertSame([], $preview->viewData('skippedRows'), '片方だけ空欄の日付を同じとみなしてスキップした');
            $this->assertSame(1, $preview->viewData('validCount'));

            return;
        }
        $message = sprintf(
            $room
                ? '部屋「再取込ハイツ 101」の入居者 山田太郎 の契約（契約日 %s・入居日 %s）は既に登録済み（既存契約 ID: %d）のためスキップ'
                : '駐車場「再取込ハイツ P-1」の入居者 山田太郎 の契約（契約日 %s・開始日 %s）は既に登録済み（既存契約 ID: %d）のためスキップ',
            $csvContract === '' ? '空欄' : $csvContract,
            $csvSecond === '' ? '空欄' : $csvSecond,
            $registered->id
        );
        $this->assertSame([['row' => 2, 'message' => $message]], $preview->viewData('skippedRows'));
        $this->assertStringContainsString('行2: ' . $message, $preview->getContent());
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> [URL のタブ, 日付が空欄の行, スキップの理由（%d は登録済みの契約の ID）] */
    public static function blankDateRoundTripCases(): array
    {
        return [
            '部屋契約' => [
                'room-contract', '再取込ハイツ,101,山田太郎,,,,55000,,,,,',
                '部屋「再取込ハイツ 101」の入居者 山田太郎 の契約（契約日 空欄・入居日 空欄）は既に登録済み（既存契約 ID: %d）のためスキップ',
            ],
            '駐車場契約' => [
                'parking-contract', '再取込ハイツ,P-1,山田太郎,,,,,8000,,,',
                '駐車場「再取込ハイツ P-1」の入居者 山田太郎 の契約（契約日 空欄・開始日 空欄）は既に登録済み（既存契約 ID: %d）のためスキップ',
            ],
        ];
    }

    #[DataProvider('blankDateRoundTripCases')]
    public function test_a_contract_without_dates_is_skipped_when_uploaded_again(string $tab, string $row, string $skipFormat): void
    {
        // 移行の CSV は日付が無いことが多い。日付が空欄の契約を取り込み、同じ CSV を上げ直す（空欄どうしを同じとみなす）
        $csv = $this->header($tab) . "\n{$row}\n";
        $this->confirm($tab, $csv);
        $registered = $tab === 'room-contract' ? MsContract::sole() : MsParkingContract::sole();

        $preview = $this->assertPreviewSkipsEveryRow($tab, $csv, 1);

        $this->assertSame([['row' => 2, 'message' => sprintf($skipFormat, $registered->id)]], $preview->viewData('skippedRows'));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> [URL のタブ, 登録済みと同じキーの行, エラー] */
    public static function badDateOnRegisteredRowCases(): array
    {
        return [
            '部屋契約: 退去日'   => ['room-contract', '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-15,2025-02-30,55000,,,,,', '退去日「2025-02-30」の形式が不正です'],
            '駐車場契約: 終了日' => ['parking-contract', '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-15,2025-02-30,8000,,,', '終了日「2025-02-30」の形式が不正です'],
        ];
    }

    #[DataProvider('badDateOnRegisteredRowCases')]
    public function test_a_registered_row_with_a_bad_date_is_an_error_not_skipped(string $tab, string $row, string $error): void
    {
        // 日付の検査は照合の前にまとめて置く（見分けに使わない退去日・終了日も。設計書 §4.4）。登録済みと同じキーでも、日付の誤りはエラー
        $tab === 'room-contract'
            ? $this->registeredRoomContract('2024-04-01', '2024-04-15')
            : $this->registeredParkingContract('2024-04-01', '2024-04-15');

        $preview = $this->preview($tab, $this->header($tab) . "\n{$row}\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => $error]], $preview->viewData('rowErrors'));
        $this->assertSame([], $preview->viewData('skippedRows'));
    }

    // ================================================================
    // 9: CSV の中の重複
    // ================================================================

    /** @return array<string, array{0: string, 1: string, 2: string}> [URL のタブ, 1 行目, 2 行目]（見分けのキーが同じ） */
    public static function duplicateRows(): array
    {
        return [
            '部屋契約: 同じ行'               => ['room-contract', self::ROOM_ROW_1, self::ROOM_ROW_1],
            '部屋契約: 日付の書き方が違う'   => ['room-contract', self::ROOM_ROW_1, '再取込ハイツ,101,山田太郎,2024/4/1,2024/4/15,,55000,,,,,'],
            '部屋契約: 日付が 2 つとも空欄'  => ['room-contract', '再取込ハイツ,101,山田太郎,,,,55000,,,,,', '再取込ハイツ,101,山田太郎,,,,60000,,,,,'],
            '駐車場契約: 同じ行'             => ['parking-contract', self::PARKING_ROW_1, self::PARKING_ROW_1],
            '駐車場契約: 日付の書き方が違う' => ['parking-contract', self::PARKING_ROW_1, '再取込ハイツ,P-1,山田太郎,,2024/4/1,2024/4/15,,8000,,,'],
        ];
    }

    #[DataProvider('duplicateRows')]
    public function test_the_second_row_with_the_same_key_in_the_csv_is_an_error(string $tab, string $first, string $second): void
    {
        $csv = $this->header($tab) . "\n{$first}\n{$second}\n";

        $preview = $this->preview($tab, $csv)->assertOk();
        $this->assertSame([['row' => 3, 'message' => 'CSV内で行2と同じ契約が重複しています']], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));
        $this->assertStringContainsString('行3: CSV内で行2と同じ契約が重複しています', $preview->getContent());
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> [URL のタブ, 1 行目, 2 行目]（見分けのキーが 1 つだけ違う） */
    public static function rowsThatDifferInOneKeyElement(): array
    {
        return [
            '部屋契約: 部屋'           => ['room-contract', self::ROOM_ROW_1, '再取込ハイツ,102,山田太郎,2024-04-01,2024-04-15,,,,,,,'],
            '部屋契約: 入居者'         => ['room-contract', self::ROOM_ROW_1, '再取込ハイツ,101,鈴木次郎,2024-04-01,2024-04-15,,,,,,,'],
            '部屋契約: 契約日'         => ['room-contract', self::ROOM_ROW_1, '再取込ハイツ,101,山田太郎,2024-04-02,2024-04-15,,,,,,,'],
            '部屋契約: 入居日'         => ['room-contract', self::ROOM_ROW_1, '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-16,,,,,,,'],
            '部屋契約: 契約日が空欄'   => ['room-contract', self::ROOM_ROW_1, '再取込ハイツ,101,山田太郎,,2024-04-15,,,,,,,'],
            '駐車場契約: 駐車場'       => ['parking-contract', self::PARKING_ROW_1, '再取込ハイツ,P-2,山田太郎,,2024-04-01,2024-04-15,,8000,,,'],
            '駐車場契約: 入居者'       => ['parking-contract', self::PARKING_ROW_1, '再取込ハイツ,P-1,鈴木次郎,,2024-04-01,2024-04-15,,8000,,,'],
            '駐車場契約: 契約日'       => ['parking-contract', self::PARKING_ROW_1, '再取込ハイツ,P-1,山田太郎,,2024-04-02,2024-04-15,,8000,,,'],
            '駐車場契約: 開始日'       => ['parking-contract', self::PARKING_ROW_1, '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-16,,8000,,,'],
            '駐車場契約: 開始日が空欄' => ['parking-contract', self::PARKING_ROW_1, '再取込ハイツ,P-1,山田太郎,,2024-04-01,,,8000,,,'],
        ];
    }

    #[DataProvider('rowsThatDifferInOneKeyElement')]
    public function test_two_rows_that_differ_in_one_key_element_are_both_imported(string $tab, string $first, string $second): void
    {
        $preview = $this->preview($tab, $this->header($tab) . "\n{$first}\n{$second}\n")->assertOk();

        $this->assertSame([], $preview->viewData('rowErrors'), '見分けのキーが違う 2 行を CSV 内の重複にした');
        $this->assertSame(2, $preview->viewData('validCount'));
    }

    public function test_a_registered_room_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error(): void
    {
        // 最初の行は、照合の前に覚える（設計書 §4.5）。1 行目はスキップ・2 行目は重複のエラー
        $registered = $this->registeredRoomContract('2024-04-01', '2024-04-15');

        $preview = $this->preview('room-contract', self::ROOM_HEADER . "\n" . self::ROOM_ROW_1 . "\n" . self::ROOM_ROW_1 . "\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::ROOM_SKIP, $registered->id)]], $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 3, 'message' => 'CSV内で行2と同じ契約が重複しています']], $preview->viewData('rowErrors'));
    }

    public function test_a_registered_parking_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error(): void
    {
        $registered = $this->registeredParkingContract('2024-04-01', '2024-04-15');

        $preview = $this->preview('parking-contract', self::PARKING_HEADER . "\n" . self::PARKING_ROW_1 . "\n" . self::PARKING_ROW_1 . "\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf(self::PARKING_SKIP, $registered->id)]], $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 3, 'message' => 'CSV内で行2と同じ契約が重複しています']], $preview->viewData('rowErrors'));
    }

    // ================================================================
    // 10: 警告はスキップ・エラーの行には出さない
    // ================================================================

    public function test_room_contract_warnings_are_shown_only_for_rows_that_will_be_imported(): void
    {
        $registered = $this->registeredRoomContract('2024-04-01', '2024-04-15');   // 101 は契約中
        $csv = self::ROOM_HEADER . "\n"
            . "再取込ハイツ,101,山田太郎,2024-04-01,2024-04-15,,55000,,,,いない人,\n"   // 行2: 登録済み → スキップ
            . "再取込ハイツ,101,鈴木次郎,2025-04-01,,,abc,,,,いない人,\n"              // 行3: 家賃の誤り → エラー
            . "再取込ハイツ,101,鈴木次郎,2025-05-01,,,60000,,,,いない人,\n";           // 行4: 取り込む

        $preview = $this->preview('room-contract', $csv)->assertOk();
        $html = $preview->getContent();
        $staff = '担当者ユーザー名「いない人」がシステムに見つからないため、担当者は未設定でインポートします';
        $active = "部屋「再取込ハイツ 101」には既に契約中の入居者がいます（既存契約 ID: {$registered->id}）";

        // 役割: 警告は取り込む行（行4）だけ
        $this->assertSame([['row' => 4, 'message' => $staff], ['row' => 4, 'message' => $active]], $preview->viewData('warnings'));
        $this->assertCount(1, $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 3, 'message' => '家賃「abc」は不正な値です']], $preview->viewData('rowErrors'));
        // 表示: 警告の一覧・件数のチップ・ボタンの下の注記（どれも取り込む行の警告だけを数える）
        $this->assertSame(1, substr_count($html, '⚠ 行4: ' . $staff));
        $this->assertSame(1, substr_count($html, '⚠ 行4: ' . $active));
        $this->assertStringNotContainsString('⚠ 行2:', $html);
        $this->assertStringNotContainsString('⚠ 行3:', $html);
        $this->assertStringContainsString('警告: <strong>2</strong> 件', $html);
        $this->assertStringContainsString('※ 警告のある行（2件）もそのまま登録されます', $html);
    }

    public function test_parking_contract_warnings_are_shown_only_for_rows_that_will_be_imported(): void
    {
        $registered = $this->registeredParkingContract('2024-04-01', '2024-04-15');   // P-1 は使用中
        $csv = self::PARKING_HEADER . "\n"
            . "再取込ハイツ,P-1,山田太郎,102,2024-04-01,2024-04-15,,8000,,いない人,\n"   // 行2: 登録済み → スキップ（102 に契約が無い）
            . "再取込ハイツ,P-1,鈴木次郎,999,2025-04-01,,,abc,,いない人,\n"             // 行3: 月額料金の誤り → エラー（999 が無い）
            . "再取込ハイツ,P-1,鈴木次郎,999,2025-05-01,,,9000,,いない人,\n";           // 行4: 取り込む

        $preview = $this->preview('parking-contract', $csv)->assertOk();
        $html = $preview->getContent();
        $linked = '紐付部屋番号「999」が物件「再取込ハイツ」に見つからないため、部屋契約との紐付けはスキップします';
        $staff = '担当者ユーザー名「いない人」がシステムに見つからないため、担当者は未設定でインポートします';
        $active = "駐車場「再取込ハイツ P-1」には既に使用中の契約があります（既存契約 ID: {$registered->id}）";

        $this->assertSame(
            [['row' => 4, 'message' => $linked], ['row' => 4, 'message' => $staff], ['row' => 4, 'message' => $active]],
            $preview->viewData('warnings')
        );
        $this->assertCount(1, $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 3, 'message' => '月額料金「abc」は0以上の整数で入力してください']], $preview->viewData('rowErrors'));
        $this->assertStringNotContainsString('⚠ 行2:', $html);
        $this->assertStringNotContainsString('⚠ 行3:', $html);
        $this->assertStringContainsString('警告: <strong>3</strong> 件', $html);
        $this->assertStringNotContainsString('有効な部屋契約が見つからないため', $html);
    }

    // ================================================================
    // 11: プレビューのあとに同じ契約が登録された
    // ================================================================

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function registeredAfterPreviewCases(): array
    {
        return [
            '部屋契約'   => ['room-contract', MsContract::class, '部屋契約インポート完了: 1件を登録しました'],
            '駐車場契約' => ['parking-contract', MsParkingContract::class, '駐車場契約インポート完了: 1件を登録しました'],
        ];
    }

    #[DataProvider('registeredAfterPreviewCases')]
    public function test_a_contract_registered_after_the_preview_is_skipped_at_confirmation(string $tab, string $model, string $success): void
    {
        $room = $tab === 'room-contract';
        $csv = $this->header($tab) . "\n"
            . ($room ? self::ROOM_ROW_1 . "\n" . self::ROOM_ROW_2 : self::PARKING_ROW_1 . "\n" . self::PARKING_ROW_2) . "\n";
        $form = $this->parseImportForm($this->preview($tab, $csv)->getContent(), $tab);

        // プレビューのあとで、1 行目と同じ契約が（別の画面で）登録された
        $room ? $this->registeredRoomContract('2024-04-01', '2024-04-15') : $this->registeredParkingContract('2024-04-01', '2024-04-15');

        $this->actingAs($this->executive())->post($form['action'], $form['fields'])
            ->assertSessionHas('success', $success);
        $this->assertSame(2, $model::count(), '確定で、プレビューのあとに登録された契約をもう一度入れた');
    }

    // ================================================================
    // 13: 登録済みの行は、金額に誤りがあってもスキップ（駐車場契約は日付の検査を金額の前へ移した）
    // ================================================================

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> [URL のタブ, 登録済みの行, 登録済みでない行, 登録済みでない行のエラー] */
    public static function badAmountCases(): array
    {
        return [
            '部屋契約: 家賃' => [
                'room-contract',
                '再取込ハイツ,101,山田太郎,2024-04-01,2024-04-15,,abc,,,,,',
                '再取込ハイツ,102,鈴木次郎,2024-05-01,2024-05-15,,abc,,,,,',
                '家賃「abc」は不正な値です',
            ],
            '駐車場契約: 月額料金' => [
                'parking-contract',
                '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-15,,abc,,,',
                '再取込ハイツ,P-2,鈴木次郎,,2024-05-01,2024-05-15,,abc,,,',
                '月額料金「abc」は0以上の整数で入力してください',
            ],
            '駐車場契約: 敷金' => [
                'parking-contract',
                '再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-04-15,,8000,abc,,',
                '再取込ハイツ,P-2,鈴木次郎,,2024-05-01,2024-05-15,,9000,abc,,',
                '敷金「abc」は不正な値です',
            ],
        ];
    }

    #[DataProvider('badAmountCases')]
    public function test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error(string $tab, string $registeredRow, string $unregisteredRow, string $error): void
    {
        $room = $tab === 'room-contract';
        $registered = $room ? $this->registeredRoomContract('2024-04-01', '2024-04-15') : $this->registeredParkingContract('2024-04-01', '2024-04-15');

        $preview = $this->preview($tab, $this->header($tab) . "\n{$registeredRow}\n{$unregisteredRow}\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => sprintf($room ? self::ROOM_SKIP : self::PARKING_SKIP, $registered->id)]], $preview->viewData('skippedRows'));
        $this->assertSame([['row' => 3, 'message' => $error]], $preview->viewData('rowErrors'));
    }

    public function test_a_parking_row_with_both_a_bad_date_and_a_bad_fee_reports_the_date(): void
    {
        // 日付の検査を金額の前へ移したので、両方に誤りがある行は日付の誤りが出る（設計書 §4.4。どちらでも行はエラー）
        $preview = $this->preview('parking-contract', self::PARKING_HEADER . "\n再取込ハイツ,P-1,山田太郎,,2024-04-01,2024-02-30,,abc,,,\n")->assertOk();

        $this->assertSame([['row' => 2, 'message' => '開始日「2024-02-30」の形式が不正です']], $preview->viewData('rowErrors'));
    }

    // ================================================================
    // 12: 共通部品の灰色の文／赤字の文（入居者タブ）
    // ================================================================

    public function test_a_tenant_csv_whose_rows_are_all_registered_shows_the_gray_notice(): void
    {
        // 山田太郎 は setUp で登録済み
        $preview = $this->assertPreviewSkipsEveryRow('tenant', self::TENANT_HEADER . "\n入居者,山田太郎,,,,,,,\n", 1);

        $this->assertSame([['row' => 2, 'message' => '入居者「山田太郎」は既に登録済みのためスキップ']], $preview->viewData('skippedRows'));
    }

    public function test_a_tenant_csv_with_an_error_row_keeps_the_red_notice(): void
    {
        $preview = $this->assertPreviewOffersNoImport('tenant', self::TENANT_HEADER . "\n入居者,山田太郎,,,,,,,\n入居者,,,,,,,,\n");

        $this->assertCount(1, $preview->viewData('skippedRows'), 'スキップの行が無い（灰色と赤字の分かれ目を測れていない）');
        $this->assertSame([['row' => 3, 'message' => '氏名が未入力です']], $preview->viewData('rowErrors'));
        $this->assertStringNotContainsString('すべての行が登録済みです', $preview->getContent());
    }

    // ================================================================
    // 14: タブの説明
    // ================================================================

    public function test_the_contract_tabs_explain_that_registered_contracts_are_skipped(): void
    {
        $html = $this->actingAs($this->executive())->get(route('admin.mansion-import'))->assertOk()->getContent();

        $roomTab = $this->between($html, "x-show=\"activeTab === 'room_contract'\"", "x-show=\"activeTab === 'parking_contract'\"");
        $parkingTab = $this->between($html, "x-show=\"activeTab === 'parking_contract'\"", '<script>');
        $this->assertSame(1, substr_count($roomTab, self::ROOM_TAB_NOTE), '部屋契約タブの説明に出ていない');
        $this->assertSame(1, substr_count($parkingTab, self::PARKING_TAB_NOTE), '駐車場契約タブの説明に出ていない');
    }

    // ================================================================
    // 部品
    // ================================================================

    private function header(string $tab): string
    {
        return $tab === 'room-contract' ? self::ROOM_HEADER : self::PARKING_HEADER;
    }

    /** 画面で登録した部屋契約の代わり（モデルで直接作る。101・山田太郎） */
    private function registeredRoomContract(?string $contractDate, ?string $moveInDate): MsContract
    {
        return MsContract::create([
            'room_id' => $this->room101->id, 'tenant_id' => $this->yamada->id, 'status' => 'active',
            'contract_date' => $contractDate, 'move_in_date' => $moveInDate, 'rent' => 55000, 'created_by' => 1,
        ]);
    }

    /** 画面で登録した駐車場契約の代わり（モデルで直接作る。P-1・山田太郎） */
    private function registeredParkingContract(?string $contractDate, ?string $startDate): MsParkingContract
    {
        return MsParkingContract::create([
            'parking_id' => $this->parkingP1->id, 'tenant_id' => $this->yamada->id, 'status' => 'active',
            'contract_date' => $contractDate, 'start_date' => $startDate, 'monthly_fee' => 8000, 'created_by' => 1,
        ]);
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "画面に {$from} が無い");
        $end = strpos($html, $to, $start + strlen($from));
        $this->assertNotFalse($end, "画面の {$from} のあとに {$to} が無い");

        return substr($html, $start, $end - $start);
    }
}
```

- [ ] **Step 2: 直す前のコードで落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/MansionContractReimportTest.php 2>&1 | grep -E -A1 -e '^[0-9]+\) ' -e '^Tests:' | grep -v -e '^--$'
```

期待: `Tests: 68, Assertions: 326, Failures: 39.`。テストごとの赤・緑と理由（試作の実測）:

- 赤 `test_uploading_the_same_csv_again_skips_every_row`（2 通り） — `取り込む行が残っている（tab=room-contract）` ／ `取り込む行が残っている（tab=parking-contract）`
- 赤 `test_uploading_a_csv_with_an_added_row_imports_only_the_new_row`（2 通り） — `Failed asserting that two arrays are identical.` ×2
- 緑 `test_a_row_that_differs_in_one_key_element_is_imported`（8 通り）
- 赤 `test_a_row_that_differs_only_outside_the_key_is_skipped`（10 通り） — `Failed asserting that two arrays are identical.` ×10
- 赤 `test_when_the_same_room_contract_is_already_registered_twice_the_first_one_is_named` — `Failed asserting that two arrays are identical.`
- 赤 `test_when_the_same_parking_contract_is_already_registered_twice_the_first_one_is_named` — `Failed asserting that two arrays are identical.`
- 赤 4・緑 8 `test_blank_dates_match_only_blank_dates`（12 通り） — `Failed asserting that two arrays are identical.` ×4
- 赤 `test_a_contract_without_dates_is_skipped_when_uploaded_again`（2 通り） — `取り込む行が残っている（tab=room-contract）` ／ `取り込む行が残っている（tab=parking-contract）`
- 緑 `test_a_registered_row_with_a_bad_date_is_an_error_not_skipped`（2 通り）
- 赤 `test_the_second_row_with_the_same_key_in_the_csv_is_an_error`（5 通り） — `Failed asserting that two arrays are identical.` ×5
- 緑 `test_two_rows_that_differ_in_one_key_element_are_both_imported`（10 通り）
- 赤 `test_a_registered_room_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error` — `Failed asserting that two arrays are identical.`
- 赤 `test_a_registered_parking_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error` — `Failed asserting that two arrays are identical.`
- 赤 `test_room_contract_warnings_are_shown_only_for_rows_that_will_be_imported` — `Failed asserting that two arrays are identical.`
- 赤 `test_parking_contract_warnings_are_shown_only_for_rows_that_will_be_imported` — `Failed asserting that two arrays are identical.`
- 赤 `test_a_contract_registered_after_the_preview_is_skipped_at_confirmation`（2 通り） — `Failed asserting that two strings are equal.` ×2
- 赤 `test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error`（3 通り） — `Failed asserting that two arrays are identical.` ×3
- 赤 `test_a_parking_row_with_both_a_bad_date_and_a_bad_fee_reports_the_date` — `Failed asserting that two arrays are identical.`
- 赤 `test_a_tenant_csv_whose_rows_are_all_registered_shows_the_gray_notice` — `灰色の「すべての行が登録済み」の文が出ていない（tab=tenant）`
- 緑 `test_a_tenant_csv_with_an_error_row_keeps_the_red_notice`
- 赤 `test_the_contract_tabs_explain_that_registered_contracts_are_skipped` — `部屋契約タブの説明に出ていない`

緑の 29 本は、直す前から成り立つことを守るテスト（キーの要素が 1 つ違えば別の契約として入る・片方だけ空欄の日付は別の契約・日付の誤りはエラー・エラーの行があれば赤字）。

- [ ] **Step 3: コントローラ — クラスの docblock**（`app/Http/Controllers/Admin/MansionImportController.php`。Edit）

置き換える前（`app/Http/Controllers/Admin/MansionImportController.php`・`046c69c9` の 46 行目から）:

```php
 *   （物件・部屋・駐車場・入居者は重複の確認で 2 回目が「0件を登録しました」になった）。
 */
class MansionImportController extends Controller
```

置き換えた後:

```php
 *   （物件・部屋・駐車場・入居者は重複の確認で 2 回目が「0件を登録しました」になった）。
 * ⚠ 部屋契約・駐車場契約は、登録済みの同じ契約の行をスキップする（上げ直しても二重にしない。
 *   設計書 2026-09-29-contract-reimport-design.md）。見分けのキーは 部屋（駐車場）・入居者・契約日・入居日（開始日）で、
 *   日付は空欄どうしを同じ・片方だけ空欄なら違う契約とみなす。賃料・状態・退去日（終了日）・メモは使わず、
 *   登録済みの契約は書き換えない。照合は findRegisteredRoomContract() / findRegisteredParkingContract() の 1 か所ずつ。
 *   行の検査では、日付の検査のあと・金額の検査の前に「CSV 内の重複 → 登録済みの照合」の順で見る
 *   （駐車場契約は日付の検査を金額の前へ移した。確定でも同じ検査をやり直す）。
 *   警告は行の中に貯め、取り込むと決めたときだけ画面の一覧へ移す（スキップ・エラーの行には出さない）。
 */
class MansionImportController extends Controller
```

- [ ] **Step 4: コントローラ — 2 経路の初期化とループの頭**（Edit・`replace_all`。部屋契約と駐車場契約で同じ形が 2 か所ある。先に `grep -c -F '        $warnings = [];' app/Http/Controllers/Admin/MansionImportController.php` が `2` を返すことを確かめる＝この行はこの 2 か所にしか無い）

置き換える前（`app/Http/Controllers/Admin/MansionImportController.php`・`046c69c9` の 867・1091 行目から。**2 か所**＝`replace_all`）:

```php
        // 行バリデーション
        $errors = [];
        $warnings = [];
        $validRows = [];
        $propertyCache = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
```

置き換えた後:

```php
        // 行バリデーション
        $errors = [];
        $warnings = [];
        $validRows = [];
        $skippedRows = [];
        $propertyCache = [];
        $contractKeyTracker = [];   // 見分けのキー => 最初の行（CSV 内の重複）

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            // 警告はいったん行の中に貯め、取り込むと決めたときだけ画面の一覧へ移す（クラスの docblock）
            $rowWarnings = [];
```

- [ ] **Step 5: コントローラ — 担当者が見つからない警告**（Edit・`replace_all`。先に `grep -c -F 'がシステムに見つからないため、担当者は未設定でインポートします' app/Http/Controllers/Admin/MansionImportController.php` が `2` を返すことを確かめる）

置き換える前（`app/Http/Controllers/Admin/MansionImportController.php`・`046c69c9` の 930・1178 行目から。**2 か所**＝`replace_all`）:

```php
                    $warnings[] = ['row' => $rowNum, 'message' => "担当者ユーザー名「{$row['staff_user_name']}」がシステムに見つからないため、担当者は未設定でインポートします"];
```

置き換えた後:

```php
                    $rowWarnings[] = ['row' => $rowNum, 'message' => "担当者ユーザー名「{$row['staff_user_name']}」がシステムに見つからないため、担当者は未設定でインポートします"];
```

- [ ] **Step 6: コントローラ — 部屋契約（`executeRoomContract()`）**（Edit を 4 回。書いてある順に）

1 回目（状態を決めたあと・契約中の警告の前に、CSV 内の重複と照合）:

置き換える前（`app/Http/Controllers/Admin/MansionImportController.php`・`046c69c9` の 960 行目から）:

```php
            // ステータス自動決定
            $status = $moveOutDate ? MsContractStatus::Terminated->value : MsContractStatus::Active->value;
```

置き換えた後:

```php
            // ステータス自動決定
            $status = $moveOutDate ? MsContractStatus::Terminated->value : MsContractStatus::Active->value;

            // CSV 内の重複（見分けのキー＝部屋・入居者・契約日・入居日。空欄の日付は空欄として比べる）。最初の行は、照合の前にここで覚える
            $contractKey = $room->id . '|' . $tenant->id . '|' . ($contractDate ?? '') . '|' . ($moveInDate ?? '');
            if (isset($contractKeyTracker[$contractKey])) {
                $errors[] = ['row' => $rowNum, 'message' => "CSV内で行{$contractKeyTracker[$contractKey]}と同じ契約が重複しています"];
                continue;
            }
            $contractKeyTracker[$contractKey] = $rowNum;

            // 登録済みの同じ契約はスキップ（書き換えない。金額に誤りがあってもスキップ）
            $registered = $this->findRegisteredRoomContract($room->id, $tenant->id, $contractDate, $moveInDate);
            if ($registered) {
                $skippedRows[] = ['row' => $rowNum, 'message' => "部屋「{$propName} {$room->room_number}」の入居者 {$tenantName} の契約（契約日 " . ($contractDate ?? '空欄') . '・入居日 ' . ($moveInDate ?? '空欄') . "）は既に登録済み（既存契約 ID: {$registered->id}）のためスキップ"];
                continue;
            }
```

2 回目（契約中の警告を、行の警告に貯める）:

置き換える前（`app/Http/Controllers/Admin/MansionImportController.php`・`046c69c9` の 969 行目から）:

```php
                    $warnings[] = ['row' => $rowNum, 'message' => "部屋「{$propName} {$room->room_number}」には既に契約中の入居者がいます（既存契約 ID: {$existingActive->id}）"];
```

置き換えた後:

```php
                    $rowWarnings[] = ['row' => $rowNum, 'message' => "部屋「{$propName} {$room->room_number}」には既に契約中の入居者がいます（既存契約 ID: {$existingActive->id}）"];
```

3 回目（取り込むと決めた位置で、行の警告を画面の一覧へ移す）:

置き換える前（`app/Http/Controllers/Admin/MansionImportController.php`・`046c69c9` の 996 行目から）:

```php
            $row['_room_id']      = $room->id;
```

置き換えた後:

```php
            $warnings = array_merge($warnings, $rowWarnings);

            $row['_room_id']      = $room->id;
```

4 回目（view へスキップの一覧を渡す）:

置き換える前（`app/Http/Controllers/Admin/MansionImportController.php`・`046c69c9` の 1016 行目から）:

```php
                'skippedRows' => [],
                'summary'     => '部屋契約 ' . count($validRows) . '件を新規作成',
```

置き換えた後:

```php
                'skippedRows' => $skippedRows,
                'summary'     => '部屋契約 ' . count($validRows) . '件を新規作成',
```

- [ ] **Step 7: コントローラ — 駐車場契約（`executeParkingContract()`）**（Edit を 4 回。書いてある順に）

1 回目・2 回目（紐付部屋番号の警告 2 つを、行の警告に貯める）:

置き換える前（`app/Http/Controllers/Admin/MansionImportController.php`・`046c69c9` の 1158 行目から）:

```php
                    $warnings[] = ['row' => $rowNum, 'message' => "紐付部屋番号「{$row['linked_room_number']}」が物件「{$propName}」に見つからないため、部屋契約との紐付けはスキップします"];
```

置き換えた後:

```php
                    $rowWarnings[] = ['row' => $rowNum, 'message' => "紐付部屋番号「{$row['linked_room_number']}」が物件「{$propName}」に見つからないため、部屋契約との紐付けはスキップします"];
```

置き換える前（`app/Http/Controllers/Admin/MansionImportController.php`・`046c69c9` の 1166 行目から）:

```php
                        $warnings[] = ['row' => $rowNum, 'message' => "紐付部屋番号「{$row['linked_room_number']}」に有効な部屋契約が見つからないため、部屋契約との紐付けはスキップします"];
```

置き換えた後:

```php
                        $rowWarnings[] = ['row' => $rowNum, 'message' => "紐付部屋番号「{$row['linked_room_number']}」に有効な部屋契約が見つからないため、部屋契約との紐付けはスキップします"];
```

3 回目（日付の検査を金額の検査の前へ移し、CSV 内の重複と照合を足し、金額の検査を照合の後ろへ。取り込むと決めた位置で行の警告を移す）:

置き換える前（`app/Http/Controllers/Admin/MansionImportController.php`・`046c69c9` の 1182 行目から）:

```php
            // 月額料金チェック
            $monthlyFeeVal = str_replace(',', '', $row['monthly_fee']);
            if (!is_numeric($monthlyFeeVal) || (int) $monthlyFeeVal < 0) {
                $errors[] = ['row' => $rowNum, 'message' => "月額料金「{$row['monthly_fee']}」は0以上の整数で入力してください"];
                continue;
            }
            $row['monthly_fee'] = (int) $monthlyFeeVal;

            // 敷金チェック
            if ($row['deposit'] !== '') {
                $val = str_replace(',', '', $row['deposit']);
                if (!is_numeric($val) || (int) $val < 0) {
                    $errors[] = ['row' => $rowNum, 'message' => "敷金「{$row['deposit']}」は不正な値です"];
                    continue;
                }
                $row['deposit'] = (int) $val;
            }

            // 日付チェック
            $contractDate = null;
            if ($row['contract_date'] !== '') {
                $contractDate = CsvDate::normalize($row['contract_date']);
                if (!$contractDate) {
                    $errors[] = ['row' => $rowNum, 'message' => "契約日「{$row['contract_date']}」の形式が不正です（YYYY-MM-DD）"];
                    continue;
                }
            }
            $startDate = null;
            if ($row['start_date'] !== '') {
                $startDate = CsvDate::normalize($row['start_date']);
                if (!$startDate) {
                    $errors[] = ['row' => $rowNum, 'message' => "開始日「{$row['start_date']}」の形式が不正です"];
                    continue;
                }
            }
            $endDate = null;
            if ($row['end_date'] !== '') {
                $endDate = CsvDate::normalize($row['end_date']);
                if (!$endDate) {
                    $errors[] = ['row' => $rowNum, 'message' => "終了日「{$row['end_date']}」の形式が不正です"];
                    continue;
                }
            }

            // ステータス自動決定
            $status = $endDate ? MsContractStatus::Terminated->value : MsContractStatus::Active->value;

            // 二重契約チェック（active 契約のみ警告）
            if ($status === MsContractStatus::Active->value) {
                $existingActive = MsParkingContract::where('parking_id', $parking->id)
                    ->where('status', MsContractStatus::Active->value)
                    ->first();
                if ($existingActive) {
                    $warnings[] = ['row' => $rowNum, 'message' => "駐車場「{$propName} {$parking->parking_number}」には既に使用中の契約があります（既存契約 ID: {$existingActive->id}）"];
                }
            }

            $row['_parking_id']     = $parking->id;
```

置き換えた後:

```php
            // 日付チェック（見分けに使うので、金額より先に見る。クラスの docblock）
            $contractDate = null;
            if ($row['contract_date'] !== '') {
                $contractDate = CsvDate::normalize($row['contract_date']);
                if (!$contractDate) {
                    $errors[] = ['row' => $rowNum, 'message' => "契約日「{$row['contract_date']}」の形式が不正です（YYYY-MM-DD）"];
                    continue;
                }
            }
            $startDate = null;
            if ($row['start_date'] !== '') {
                $startDate = CsvDate::normalize($row['start_date']);
                if (!$startDate) {
                    $errors[] = ['row' => $rowNum, 'message' => "開始日「{$row['start_date']}」の形式が不正です"];
                    continue;
                }
            }
            $endDate = null;
            if ($row['end_date'] !== '') {
                $endDate = CsvDate::normalize($row['end_date']);
                if (!$endDate) {
                    $errors[] = ['row' => $rowNum, 'message' => "終了日「{$row['end_date']}」の形式が不正です"];
                    continue;
                }
            }

            // ステータス自動決定
            $status = $endDate ? MsContractStatus::Terminated->value : MsContractStatus::Active->value;

            // CSV 内の重複（見分けのキー＝駐車場・入居者・契約日・開始日。空欄の日付は空欄として比べる）。最初の行は、照合の前にここで覚える
            $contractKey = $parking->id . '|' . $tenant->id . '|' . ($contractDate ?? '') . '|' . ($startDate ?? '');
            if (isset($contractKeyTracker[$contractKey])) {
                $errors[] = ['row' => $rowNum, 'message' => "CSV内で行{$contractKeyTracker[$contractKey]}と同じ契約が重複しています"];
                continue;
            }
            $contractKeyTracker[$contractKey] = $rowNum;

            // 登録済みの同じ契約はスキップ（書き換えない。月額料金・敷金に誤りがあってもスキップ）
            $registered = $this->findRegisteredParkingContract($parking->id, $tenant->id, $contractDate, $startDate);
            if ($registered) {
                $skippedRows[] = ['row' => $rowNum, 'message' => "駐車場「{$propName} {$parking->parking_number}」の入居者 {$tenantName} の契約（契約日 " . ($contractDate ?? '空欄') . '・開始日 ' . ($startDate ?? '空欄') . "）は既に登録済み（既存契約 ID: {$registered->id}）のためスキップ"];
                continue;
            }

            // 二重契約チェック（active 契約のみ警告）
            if ($status === MsContractStatus::Active->value) {
                $existingActive = MsParkingContract::where('parking_id', $parking->id)
                    ->where('status', MsContractStatus::Active->value)
                    ->first();
                if ($existingActive) {
                    $rowWarnings[] = ['row' => $rowNum, 'message' => "駐車場「{$propName} {$parking->parking_number}」には既に使用中の契約があります（既存契約 ID: {$existingActive->id}）"];
                }
            }

            // 月額料金チェック（照合の後ろ。登録済みの行は金額に誤りがあってもスキップする）
            $monthlyFeeVal = str_replace(',', '', $row['monthly_fee']);
            if (!is_numeric($monthlyFeeVal) || (int) $monthlyFeeVal < 0) {
                $errors[] = ['row' => $rowNum, 'message' => "月額料金「{$row['monthly_fee']}」は0以上の整数で入力してください"];
                continue;
            }
            $row['monthly_fee'] = (int) $monthlyFeeVal;

            // 敷金チェック
            if ($row['deposit'] !== '') {
                $val = str_replace(',', '', $row['deposit']);
                if (!is_numeric($val) || (int) $val < 0) {
                    $errors[] = ['row' => $rowNum, 'message' => "敷金「{$row['deposit']}」は不正な値です"];
                    continue;
                }
                $row['deposit'] = (int) $val;
            }

            $warnings = array_merge($warnings, $rowWarnings);

            $row['_parking_id']     = $parking->id;
```

⚠ この置き換えは長い。「置き換える前」は `// 月額料金チェック` から `$row['_parking_id']     = $parking->id;` までで、ファイルに 1 か所。移したあとの順番は 日付 → 状態 → CSV 内の重複 → 照合 → 契約中の警告 → 月額料金 → 敷金 → 警告を移す → 取り込むと決める（設計書 §4.4 の表の駐車場契約の行）。

4 回目（view へスキップの一覧を渡す）:

置き換える前（`app/Http/Controllers/Admin/MansionImportController.php`・`046c69c9` の 1260 行目から）:

```php
                'skippedRows' => [],
                'summary'     => '駐車場契約 ' . count($validRows) . '件を新規作成',
```

置き換えた後:

```php
                'skippedRows' => $skippedRows,
                'summary'     => '駐車場契約 ' . count($validRows) . '件を新規作成',
```

- [ ] **Step 8: コントローラ — 照合のメソッド 2 つ**（Edit。`loadCsv()` の docblock の前に足す）

置き換える前（`app/Http/Controllers/Admin/MansionImportController.php`・`046c69c9` の 1408 行目から）:

```php
    /**
     * CSV を読み込んで行配列にする。
```

置き換えた後:

```php
    /**
     * 登録済みの同じ部屋契約（部屋・入居者・契約日・入居日。クラスの docblock）。
     * 日付は空欄どうしを同じ・片方だけ空欄なら違う契約とみなす。同じキーの契約が 2 件以上あれば、
     * id のいちばん小さいもの（最初に登録されたもの）を返す（以前の二重送信の名残。消さない）。
     * ⚠ 日付は whereDate で比べる（テストの SQLite は date キャストの値を `Y-m-d 00:00:00` で保存するので、素の where は一致しない。
     *   2026-09-29 に実測）
     */
    private function findRegisteredRoomContract(int $roomId, int $tenantId, ?string $contractDate, ?string $moveInDate): ?MsContract
    {
        return MsContract::where('room_id', $roomId)
            ->where('tenant_id', $tenantId)
            ->when(
                $contractDate === null,
                fn ($query) => $query->whereNull('contract_date'),
                fn ($query) => $query->whereDate('contract_date', $contractDate)
            )
            ->when(
                $moveInDate === null,
                fn ($query) => $query->whereNull('move_in_date'),
                fn ($query) => $query->whereDate('move_in_date', $moveInDate)
            )
            ->orderBy('id')
            ->first();
    }

    /**
     * 登録済みの同じ駐車場契約（駐車場・入居者・契約日・開始日。クラスの docblock）。
     * 日付の比べ方と、同じキーの契約が 2 件以上あるときは、findRegisteredRoomContract() と同じ。
     */
    private function findRegisteredParkingContract(int $parkingId, int $tenantId, ?string $contractDate, ?string $startDate): ?MsParkingContract
    {
        return MsParkingContract::where('parking_id', $parkingId)
            ->where('tenant_id', $tenantId)
            ->when(
                $contractDate === null,
                fn ($query) => $query->whereNull('contract_date'),
                fn ($query) => $query->whereDate('contract_date', $contractDate)
            )
            ->when(
                $startDate === null,
                fn ($query) => $query->whereNull('start_date'),
                fn ($query) => $query->whereDate('start_date', $startDate)
            )
            ->orderBy('id')
            ->first();
    }

    /**
     * CSV を読み込んで行配列にする。
```

- [ ] **Step 9: 確認画面の灰色の文**（`resources/views/admin/mansion-import/_preview.blade.php`。Edit）

置き換える前（`resources/views/admin/mansion-import/_preview.blade.php`・`046c69c9` の 105 行目から）:

```blade
        @else
            <div style="font-size: 13px; color: #dc2626;">インポート可能なデータがありません。CSVを修正してください。</div>
        @endif
```

置き換えた後:

```blade
        @elseif(count($rowErrors ?? []) === 0)
            {{-- すべての行が登録済み（スキップ）で、エラーも無い（設計書 2026-09-29-contract-reimport-design.md §4.7 (2)）。
                 確認画面に来る CSV は必ず 1 行以上あるので、このときスキップは 1 件以上ある --}}
            <div style="font-size: 13px; color: #6b7280;">すべての行が登録済みです（スキップ {{ count($skippedRows ?? []) }} 件）。取り込む行はありません。</div>
        @else
            <div style="font-size: 13px; color: #dc2626;">インポート可能なデータがありません。CSVを修正してください。</div>
        @endif
```

- [ ] **Step 10: タブの説明**（`resources/views/admin/mansion-import/index.blade.php`。Edit を 2 回）

部屋契約タブ:

置き換える前（`resources/views/admin/mansion-import/index.blade.php`・`046c69c9` の 187 行目から）:

```blade
                        <li>契約作成時に部屋のステータスが「入居中」に更新されます</li>
```

置き換えた後:

```blade
                        <li>契約作成時に部屋のステータスが「入居中」に更新されます</li>
                        <li>同じ部屋・入居者名・契約日・入居日の契約が登録済みなら、その行はスキップされます（家賃などが違っても書き換えません）</li>
```

駐車場契約タブ:

置き換える前（`resources/views/admin/mansion-import/index.blade.php`・`046c69c9` の 216 行目から）:

```blade
                        <li>紐付部屋番号は任意。記入時は active な部屋契約が必要です</li>
```

置き換えた後:

```blade
                        <li>紐付部屋番号は任意。記入時は active な部屋契約が必要です</li>
                        <li>同じ駐車場・入居者名・契約日・開始日の契約が登録済みなら、その行はスキップされます（月額料金などが違っても書き換えません）</li>
```

- [ ] **Step 11: 通ることと、関係するテストが緑のままであることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/MansionContractReimportTest.php 2>&1 | tail -1 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/MansionContractReimportTest.php tests/Feature/Admin/MansionImportTest.php tests/Feature/Admin/MansionImportDoubleSubmitTest.php tests/Feature/Admin/MansionImportRejectionTest.php 2>&1 | tail -1
```

期待: `OK (68 tests, 447 assertions)` ／ `OK (104 tests, 1167 assertions)`（`MansionImportTest::test_a_second_active_contract_warns_but_still_imports` は、登録済みと CSV の行で入居者も契約日も違う＝見分けのキーが違うので、今までどおり警告のうえ取り込む。設計書 §2.5）

- [ ] **Step 12: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && git add tests/Feature/Admin/MansionContractReimportTest.php app/Http/Controllers/Admin/MansionImportController.php resources/views/admin/mansion-import/_preview.blade.php resources/views/admin/mansion-import/index.blade.php && git commit -m "$(cat <<'EOF'
fix: 賃貸マンションの契約の取込で登録済みの同じ契約をスキップする

同じ CSV を上げ直すと部屋契約・駐車場契約が二重に入っていた。部屋（駐車場）・入居者・契約日・入居日
（開始日）が同じ登録済みの契約の行は確認画面でスキップする（空欄の日付は空欄どうしだけ同じ）。
駐車場契約は日付の検査を金額より前へ移す。警告は取り込む行の分だけ出し、タブの説明に 1 行ずつ足す。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 4: 構造のテスト（契約を作る取込の全件分類）

**Files:**
- Create: `tests/Feature/ImportControllerContractMatchScanTest.php`

**Interfaces:**
- Consumes: `Tests\Concerns\ScansImportControllers::importControllerFiles()`（`*ImportController.php` の列挙と下限 8 本。ほかの取込の走査テストと共用）／ Task 2・3 の照合のメソッドの名前（`findRegisteredContract`・`findRegisteredRoomContract`・`findRegisteredParkingContract`）と `$warnings = array_merge($warnings, $rowWarnings);` の形
- Produces: なし（Task 6 の変異 S01〜S05 がこのテストを見る）

- [ ] **Step 1: テストを書く**（`tests/Feature/ImportControllerContractMatchScanTest.php` を新しく作る。この内容そのまま）

```php
<?php

namespace Tests\Feature;

use ReflectionClass;
use Tests\Concerns\ScansImportControllers;
use Tests\TestCase;

/**
 * 契約を作る取込は、登録済みの同じ契約を照合してスキップすること（上げ直しても二重にしない。
 * 設計書 2026-09-29-contract-reimport-design.md §5.2）。
 *
 * ⚠ 全件分類（Top trap #13）。列挙はほかの取込の走査テストと同じ `ScansImportControllers` を共用する
 *   （別々に列挙すると、片方だけ範囲が変わって見落としが生まれる）。取込のコントローラの**どのメソッドでも**
 *   （public に限らない。作成を private の部品へ切り出しても見逃さない）、契約のモデル（名前が Contract で終わるクラス）を
 *   作る呼び出しを持てば集め、下の 2 つの表のどちらかに入っていなければ落とす。表に古い名前が残っても落とす。
 * ⚠ コメントを落としてから探す（docblock に `Contract::create(` と書いてあると、実体を消しても緑になる。Bug #42 ②）。
 * ⚠ 見えないもの（死角）: トレイト・サービスへ切り出した作成 ／ `*ImportController.php` という名前でない取込 ／
 *   照合が行の検査の正しい位置（日付の検査のあと・金額の検査の前）にあるか（これは振る舞いのテスト
 *   TenantContractReimportTest・MansionContractReimportTest が見る）／ `$warnings` という名前でない一覧へ直接積む書き方。
 */
class ImportControllerContractMatchScanTest extends TestCase
{
    use ScansImportControllers;

    /** 照合が要るメソッド => 照合のメソッド */
    private const MATCHED = [
        'App\Http\Controllers\Admin\MansionImportController::executeParkingContract' => 'findRegisteredParkingContract',
        'App\Http\Controllers\Admin\MansionImportController::executeRoomContract'    => 'findRegisteredRoomContract',
        'App\Http\Controllers\Admin\TenantImportController::executeContract'         => 'findRegisteredContract',
        'App\Http\Controllers\Admin\TenantImportController::executePastContract'     => 'findRegisteredContract',
    ];

    /** 照合しない（対象外の）メソッド => 理由 */
    private const NOT_MATCHED = [
        'App\Http\Controllers\Admin\ZealMemberImportController::execute' => 'ZealMemberContract は会員と一緒にしか作らない。会員は氏名＋入会日の重複で飛ばす（isDuplicate()）ので、上げ直しても契約は二重にならない',
    ];

    /** 見つかるメソッドの数の下限（2026-09-29 実測 5）。下回ったら走査が空振りしている */
    private const MIN_CONTRACT_CREATORS = 5;

    /** 契約のモデル（名前が Contract で終わるクラス。素の Contract も含む）を作る呼び出し */
    private const CREATES_CONTRACT = '/\b(?:[A-Z]\w*)?Contract::(?:create|forceCreate|firstOrCreate|updateOrCreate|insert)\s*\(|\bnew\s+(?:[A-Z]\w*)?Contract\s*\(/';

    public function test_every_import_method_that_creates_contracts_is_classified(): void
    {
        $found = array_keys($this->contractCreators());

        $classified = array_merge(array_keys(self::MATCHED), array_keys(self::NOT_MATCHED));
        sort($classified);

        $this->assertSame(
            $classified,
            $found,
            '契約を作る取込のメソッドと分類の表がそろっていない（新しい取込は、照合するか、理由をつけて NOT_MATCHED に足す。表に古い名前を残さない）'
        );
    }

    public function test_the_methods_that_need_matching_call_the_matcher(): void
    {
        $bodies = $this->contractCreators();

        foreach (self::MATCHED as $method => $matcher) {
            $this->assertArrayHasKey($method, $bodies, "{$method} が見つからない（分類が古い）");
            $this->assertStringContainsString(
                '$this->' . $matcher . '(',
                $bodies[$method],
                "{$method} が {$matcher}() を呼んでいない（同じ CSV を上げ直すと、契約が二重に入る）"
            );
        }
    }

    public function test_the_methods_that_match_collect_warnings_per_row(): void
    {
        $bodies = $this->contractCreators();

        foreach (array_keys(self::MATCHED) as $method) {
            $this->assertArrayHasKey($method, $bodies, "{$method} が見つからない（分類が古い）");
            $this->assertSame(
                0,
                preg_match_all('/\$warnings\s*\[\s*\]\s*=|array_push\(\s*\$warnings\b/', $bodies[$method]),
                "{$method} が画面の警告の一覧へ直接積んでいる（行の中の \$rowWarnings に貯める。直接積むと、スキップ・エラーの行にまた出る）"
            );
            $this->assertStringContainsString(
                '$warnings = array_merge($warnings, $rowWarnings);',
                $bodies[$method],
                "{$method} が、取り込むと決めた行の警告を画面の一覧へ移していない"
            );
        }
    }

    /**
     * 契約のモデルを作る呼び出しを持つメソッド。
     *
     * @return array<string, string> 「クラス::メソッド」=> コメントを落とした本体（キーの順）
     */
    private function contractCreators(): array
    {
        $found = [];

        foreach ($this->importControllerFiles() as $relative => $path) {
            $class = 'App\\' . str_replace('/', '\\', substr($relative, strlen('app/'), -strlen('.php')));
            $lines = file($path);

            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                if ($method->class !== $class) {
                    continue;
                }
                $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
                $body = $this->withoutComments($source);
                if (preg_match(self::CREATES_CONTRACT, $body) === 1) {
                    $found[$class . '::' . $method->name] = $body;
                }
            }
        }

        ksort($found);

        $this->assertGreaterThanOrEqual(
            self::MIN_CONTRACT_CREATORS,
            count($found),
            '走査が空振りしている（契約を作る取込のメソッドが少なすぎる）'
        );

        return $found;
    }

    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all('<?php ' . $source) as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
```

- [ ] **Step 2: 通ることを確かめる**（Task 2・3 が済んでいるので最初から緑）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/ImportControllerContractMatchScanTest.php tests/Feature/ImportControllerOneTimeKeyScanTest.php tests/Feature/ImportControllerReturnPathScanTest.php tests/Feature/ImportControllerValidationRedirectScanTest.php 2>&1 | tail -1
```

期待: `OK (103 tests, 275 assertions)`（新しい 3 本と、同じ列挙を使うほかの走査 3 本）

- [ ] **Step 3: 直す前のコントローラで落ちることを確かめる**（2 本のコントローラだけを一時的に `046c69c9` に戻して流し、すぐ戻す。`git stash` は使わない）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && git status --porcelain && git checkout 046c69c9 -- app/Http/Controllers/Admin/TenantImportController.php app/Http/Controllers/Admin/MansionImportController.php && git diff --cached --stat | tail -1; APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/ImportControllerContractMatchScanTest.php 2>&1 | grep -E -A1 -e '^[0-9]+\) ' -e '^Tests:' | grep -v -e '^--$'; git checkout HEAD -- app/Http/Controllers/Admin/TenantImportController.php app/Http/Controllers/Admin/MansionImportController.php && git status --porcelain && echo 'restored'
```

期待: 最初の `status` は何も出さない（始める前に作業ツリーが空）・`2 files changed`・`Tests: 3, Assertions: 17, Failures: 2.`。落ちるのは:
- `test_the_methods_that_need_matching_call_the_matcher` — `App\Http\Controllers\Admin\MansionImportController::executeParkingContract が findRegisteredParkingContract() を呼んでいない（同じ CSV を上げ直すと、契約が二重に入る）`
- `test_the_methods_that_match_collect_warnings_per_row` — `App\Http\Controllers\Admin\MansionImportController::executeParkingContract が画面の警告の一覧へ直接積んでいる（行の中の $rowWarnings に貯める。直接積むと、スキップ・エラーの行にまた出る）`

`test_every_import_method_that_creates_contracts_is_classified` は直す前も緑（契約を作るメソッドの集合は変わらない）。最後に `restored` が出て、その前の `status` が何も出さない（戻したことの確かめ）。

- [ ] **Step 4: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && git add tests/Feature/ImportControllerContractMatchScanTest.php && git commit -m "$(cat <<'EOF'
test: 契約を作る取込が登録済みの契約を照合しているかを全件分類で見る

取込のコントローラの全メソッドから契約を作る呼び出しを探し、照合が要るものは照合のメソッドを呼び、
警告を行ごとに貯めていることを見る。ZEAL 会員の取込は理由つきで対象外に分類する。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 5: 全件テストとコンパイル済みビューの lint

**Files:** なし（確かめるだけ）

- [ ] **Step 1: 全件テスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

期待: `OK (2968 tests, 21234 assertions)`（着手前 2838 / 20265 から +130 本・+969: カナリア 3・テナント 56・賃貸マンション 68・構造 3。Task 0 で `13.x` を取り込んでテストが増えたなどで本数が変わったら、その理由と本数を記録し、差で突き合わせる）

- [ ] **Step 2: コンパイル済みビューの lint**（⚠ `view:cache` の成功表示だけでは足りない。Bug #21 / #26 / #30）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && K="base64:$(php -r 'echo base64_encode(random_bytes(32));')" && APP_KEY="$K" php artisan view:cache 2>&1 | tail -2 && n=0; bad=0; for f in storage/framework/views/*.php; do n=$((n+1)); php -l "$f" >/dev/null 2>&1 || { bad=$((bad+1)); echo "INVALID: $f"; }; done; echo "views=$n invalid=$bad"; APP_KEY="$K" php artisan view:clear 2>&1 | tail -2; git status --porcelain
```

期待: `views=283 invalid=0`（ビューの数は変わらない）／ `status` は何も出さない。

---

## Task 6: 変異テスト（docs/RULES.md Bug #44 / #50 の作法）

**Files:** なし（隔離した worktree の中だけで変え、戻す。記録はこの計画の末尾の「実測記録」）

作法: 先にコミット（Task 5 まで済み）→ 隔離した worktree（`git worktree add --detach`）に vendor を**実体コピー**（`cp -Rc`。symlink は不可＝ Bug #50）→ 最初にカナリア → 変異 1 つごとに、前後で作業ツリーが空・置き換えがそれぞれ決めた数だけ当たる・着弾を確認・`--log-junit` で流す・落ちたテストと**理由の文言**まで記録・戻したあと空を再確認。実行役 `cr-mutate.py` がこの確認を全部行い、1 つでも食い違えば止まる。1 つの変異が複数の置き換え（切り取って別の場所へ貼る＝移す）を持てる。

`SP` はこのセッションの scratchpad（システムプロンプトの「Scratchpad directory」の絶対パス）。

- [ ] **Step 1: 隔離した worktree を 3 つ作る**（名前は用途とコミットで一意にする＝ Bug #50 の衝突を避ける。コミットは `$SP/cr-mut-commit` に控える）

```bash
SP=<Scratchpad directory>; WT=/Users/masanori/site/manage/.claude/worktrees/contract-reimport; C=$(git -C "$WT" rev-parse --short HEAD); echo "$C" > "$SP/cr-mut-commit"; for s in a b c; do git -C "$WT" worktree add --detach "$SP/cr-mut-$C-$s" HEAD && cp -Rc "$WT/vendor" "$SP/cr-mut-$C-$s/vendor"; done; git -C "$WT" worktree list
```

⚠ `vendor` は `.gitignore` 済みなので、コピーしても作業ツリーは空のまま（Step 3 で確かめる）。

- [ ] **Step 2: 実行役を置く**（`$SP/cr-mutate.py`。この内容そのまま。コミットしない）

```python
#!/usr/bin/env python3
"""契約の上げ直しで二重にしない改修（2026-09-29）の変異テストの実行役。

使い方（隔離した worktree の中で。docs/RULES.md Bug #44 / #50 の作法）:
    python3 cr-mutate.py --iso <隔離した worktree> [ID ...]      # ID を省くと全部。全件テストで流す
    python3 cr-mutate.py --iso <…> --narrow [ID ...]             # 関係するテスト（NARROW）だけで流す（先測り・当て直しの確認用）
    python3 cr-mutate.py --iso <…> --check                       # どの変異も決めた数だけ当たるかだけ見る
    python3 cr-mutate.py --list                                  # 定義の一覧だけ

1 つごとに: 前に作業ツリーが空 → 置き換えがそれぞれ決めた数（既定 1）だけ当たる → git status で着弾を確認 →
phpunit を --log-junit で流す → 落ちたテストと理由の 1 行目を記録 → git checkout で戻す → 後に作業ツリーが空。
どこかで食い違えば止まる（無効な測定を集めない）。
1 つの変異が複数の置き換え（切り取って別の場所へ貼る＝移す）を持てる。置き換えは書いた順に当てる。
"""
import argparse, base64, json, os, subprocess, sys, xml.etree.ElementTree as ET

TC = "app/Http/Controllers/Admin/TenantImportController.php"
MC = "app/Http/Controllers/Admin/MansionImportController.php"
CC = "app/Http/Controllers/Admin/CustomerImportController.php"
VT = "resources/views/admin/tenant-import/_preview.blade.php"
VM = "resources/views/admin/mansion-import/_preview.blade.php"
IT = "resources/views/admin/tenant-import/index.blade.php"
IM = "resources/views/admin/mansion-import/index.blade.php"
MIG = "database/migrations/0001_01_01_000007_create_contracts_table.php"
MIG2 = "database/migrations/2026_09_14_000002_add_first_and_last_month_columns_to_contracts_table.php"
SCAN = "tests/Feature/ImportControllerContractMatchScanTest.php"

# 関係するテスト（--narrow のとき）。表の「期待」はこれで測った値
NARROW = [
    "tests/Feature/Admin/TenantContractReimportTest.php",
    "tests/Feature/Admin/MansionContractReimportTest.php",
    "tests/Feature/ImportControllerContractMatchScanTest.php",
    "tests/Feature/Admin/TenantUnitImportTest.php",
    "tests/Feature/Admin/TenantImportDoubleSubmitTest.php",
    "tests/Feature/Admin/TenantImportRejectionTest.php",
    "tests/Feature/Admin/MansionImportTest.php",
    "tests/Feature/Admin/MansionImportDoubleSubmitTest.php",
    "tests/Feature/Admin/MansionImportRejectionTest.php",
    "tests/Feature/Admin/ImportPreviewRenderTest.php",
    "tests/Feature/Admin/ImportValidationFeedbackTest.php",
    "tests/Feature/Admin/CustomerImportTest.php",
    "tests/Feature/ImportControllerOneTimeKeyScanTest.php",
    "tests/Feature/ImportControllerReturnPathScanTest.php",
    "tests/Feature/ImportControllerValidationRedirectScanTest.php",
    "tests/Feature/SubmitOnceTest.php",
    "tests/Feature/JapaneseValidationMessagesTest.php",
    "tests/Feature/Tenant/ContractListSortTest.php",
    "tests/Feature/Tenant/SortBarTest.php",
    "tests/Feature/SortableListWiringTest.php",
]

# ---- 置き換える前の断片（どれも試作で当たる数を確かめた）----
DUP_IF = (
    "            if (isset($contractKeyTracker[$contractKey])) {\n"
    "                $errors[] = ['row' => $rowNum, 'message' => \"CSV内で行{$contractKeyTracker[$contractKey]}と同じ契約が重複しています\"];\n"
    "                continue;\n"
    "            }\n"
)
KEY_T = "            $contractKey = $unit->id . '|' . ($customer ? 'id:' . $customer->id : 'none') . '|' . $contractDate;\n"
KEY_P = "            $contractKey = $unit->id . '|' . ($existingCustomer ? 'id:' . $existingCustomer->id : 'name:' . $custName) . '|' . $contractDate;\n"
KEY_R = "            $contractKey = $room->id . '|' . $tenant->id . '|' . ($contractDate ?? '') . '|' . ($moveInDate ?? '');\n"
KEY_K = "            $contractKey = $parking->id . '|' . $tenant->id . '|' . ($contractDate ?? '') . '|' . ($startDate ?? '');\n"
TRACK = "            $contractKeyTracker[$contractKey] = $rowNum;\n"

MATCH_T = (
    "            // 登録済みの同じ契約はスキップ（書き換えない。金額に誤りがあってもスキップ）\n"
    "            $registered = $this->findRegisteredContract($unit->id, $customer?->id, $contractDate);\n"
    "            if ($registered) {\n"
    "                $customerLabel = $row['customer_name'] !== '' ? $row['customer_name'] : '空欄';\n"
    "                $skippedRows[] = ['row' => $rowNum, 'message' => \"区画「{$propName} {$unit->display_name}」の契約（契約日 {$contractDate}・顧客 {$customerLabel}）は既に登録済み（{$registered->contract_number}）のためスキップ\"];\n"
    "                continue;\n"
    "            }\n"
)
MATCH_P = (
    "            // 登録済みの同じ契約はスキップ（契約タブで入れた契約も同じキーで見る。書き換えない）。\n"
    "            // ⚠ まだ無い顧客（取込で自動作成する予定）の行は照合しない。null を渡すと「顧客の無い契約」と取り違える\n"
    "            if ($existingCustomer) {\n"
    "                $registered = $this->findRegisteredContract($unit->id, $existingCustomer->id, $contractDate);\n"
    "                if ($registered) {\n"
    "                    $skippedRows[] = ['row' => $rowNum, 'message' => \"区画「{$propName} {$unit->display_name}」の契約（契約日 {$contractDate}・顧客 {$custName}）は既に登録済み（{$registered->contract_number}）のためスキップ\"];\n"
    "                    continue;\n"
    "                }\n"
    "            }\n"
)
MATCH_R = (
    "            // 登録済みの同じ契約はスキップ（書き換えない。金額に誤りがあってもスキップ）\n"
    "            $registered = $this->findRegisteredRoomContract($room->id, $tenant->id, $contractDate, $moveInDate);\n"
    "            if ($registered) {\n"
    "                $skippedRows[] = ['row' => $rowNum, 'message' => \"部屋「{$propName} {$room->room_number}」の入居者 {$tenantName} の契約（契約日 \" . ($contractDate ?? '空欄') . '・入居日 ' . ($moveInDate ?? '空欄') . \"）は既に登録済み（既存契約 ID: {$registered->id}）のためスキップ\"];\n"
    "                continue;\n"
    "            }\n"
)
MATCH_K = (
    "            // 登録済みの同じ契約はスキップ（書き換えない。月額料金・敷金に誤りがあってもスキップ）\n"
    "            $registered = $this->findRegisteredParkingContract($parking->id, $tenant->id, $contractDate, $startDate);\n"
    "            if ($registered) {\n"
    "                $skippedRows[] = ['row' => $rowNum, 'message' => \"駐車場「{$propName} {$parking->parking_number}」の入居者 {$tenantName} の契約（契約日 \" . ($contractDate ?? '空欄') . '・開始日 ' . ($startDate ?? '空欄') . \"）は既に登録済み（既存契約 ID: {$registered->id}）のためスキップ\"];\n"
    "                continue;\n"
    "            }\n"
)

# CSV 内の重複と照合をまとめた塊（コメントの行から、照合の閉じ括弧のあとの空行まで）
DUP_HEAD_T = "            // CSV 内の重複（見分けのキー＝区画・顧客・契約日）。最初の行は、照合の前にここで覚える（クラスの docblock）\n"
DUP_HEAD_P = "            // CSV 内の重複（見分けのキーは契約タブと同じ。まだ無い顧客は名前で比べる）。最初の行は、照合の前にここで覚える\n"
DUP_HEAD_R = "            // CSV 内の重複（見分けのキー＝部屋・入居者・契約日・入居日。空欄の日付は空欄として比べる）。最初の行は、照合の前にここで覚える\n"
DUP_HEAD_K = "            // CSV 内の重複（見分けのキー＝駐車場・入居者・契約日・開始日。空欄の日付は空欄として比べる）。最初の行は、照合の前にここで覚える\n"
BLOCK_T = DUP_HEAD_T + KEY_T + DUP_IF + TRACK + "\n" + MATCH_T + "\n"
BLOCK_P = DUP_HEAD_P + "            $existingCustomer = $customerCache[$custName];\n" + KEY_P + DUP_IF + TRACK + "\n" + MATCH_P + "\n"
BLOCK_R = DUP_HEAD_R + KEY_R + DUP_IF + TRACK + "\n" + MATCH_R + "\n"
BLOCK_K = DUP_HEAD_K + KEY_K + DUP_IF + TRACK + "\n" + MATCH_K + "\n"

# 金額の検査の終わり（取り込むと決める直前）
MONEY_END_T = (
    "            if ($numericError) {\n"
    "                continue;\n"
    "            }\n"
    "\n"
    "            $warnings = array_merge($warnings, $rowWarnings);\n"
    "\n"
    "            $row['_property_id'] = $property->id;\n"
)
MONEY_END_P = (
    "            if ($numericError) {\n"
    "                continue;\n"
    "            }\n"
    "\n"
    "            // 注意もこの行の警告に貯め、取り込むと決めたここで画面の一覧へ移す（クラスの docblock）\n"
)
MONEY_END_R = (
    "            if ($numericError) {\n"
    "                continue;\n"
    "            }\n"
    "\n"
    "            $warnings = array_merge($warnings, $rowWarnings);\n"
    "\n"
    "            $row['_room_id']      = $room->id;\n"
)
MONEY_T = "            // 金額フィールドチェック\n            $numericFields = [\n                'rent' => '家賃', 'common_fee' => '共益費', 'deposit' => '敷金',\n"
MONEY_P = "            // 金額フィールドチェック（過去契約は家賃も任意。データ移行で空欄ありえる）\n"
MONEY_R = "（既存契約 ID: {$existingActive->id}）\"];\n                }\n            }\n\n            // 金額フィールドチェック\n"
FEE_K = (
    "            // 月額料金チェック（照合の後ろ。登録済みの行は金額に誤りがあってもスキップする）\n"
    "            $monthlyFeeVal = str_replace(',', '', $row['monthly_fee']);\n"
    "            if (!is_numeric($monthlyFeeVal) || (int) $monthlyFeeVal < 0) {\n"
    "                $errors[] = ['row' => $rowNum, 'message' => \"月額料金「{$row['monthly_fee']}」は0以上の整数で入力してください\"];\n"
    "                continue;\n"
    "            }\n"
    "            $row['monthly_fee'] = (int) $monthlyFeeVal;\n"
    "\n"
    "            // 敷金チェック\n"
    "            if ($row['deposit'] !== '') {\n"
    "                $val = str_replace(',', '', $row['deposit']);\n"
    "                if (!is_numeric($val) || (int) $val < 0) {\n"
    "                    $errors[] = ['row' => $rowNum, 'message' => \"敷金「{$row['deposit']}」は不正な値です\"];\n"
    "                    continue;\n"
    "                }\n"
    "                $row['deposit'] = (int) $val;\n"
    "            }\n"
    "\n"
)
MERGE = "            $warnings = array_merge($warnings, $rowWarnings);\n"
DATES_HEAD_K = "            // 日付チェック（見分けに使うので、金額より先に見る。クラスの docblock）\n"

GRAY_BRANCH = (
    "        @elseif(count($rowErrors ?? []) === 0)\n"
    "            {{-- すべての行が登録済み（スキップ）で、エラーも無い（設計書 2026-09-29-contract-reimport-design.md §4.7 (2)）。\n"
    "                 確認画面に来る CSV は必ず 1 行以上あるので、このときスキップは 1 件以上ある --}}\n"
    "            <div style=\"font-size: 13px; color: #6b7280;\">すべての行が登録済みです（スキップ {{ count($skippedRows ?? []) }} 件）。取り込む行はありません。</div>\n"
)

ROOM_FINDER_HEAD = (
    "        return MsContract::where('room_id', $roomId)\n"
    "            ->where('tenant_id', $tenantId)\n"
    "            ->when(\n"
    "                $contractDate === null,\n"
    "                fn ($query) => $query->whereNull('contract_date'),\n"
    "                fn ($query) => $query->whereDate('contract_date', $contractDate)\n"
    "            )\n"
)
PARKING_FINDER_HEAD = ROOM_FINDER_HEAD.replace(
    "        return MsContract::where('room_id', $roomId)\n",
    "        return MsParkingContract::where('parking_id', $parkingId)\n",
)
TENANT_CUSTOMER_WHEN = (
    "            ->when(\n"
    "                $customerId === null,\n"
    "                fn ($query) => $query->whereNull('customer_id'),\n"
    "                fn ($query) => $query->where('customer_id', $customerId)\n"
    "            )\n"
)
MOVE_IN_WHEN = (
    "            ->when(\n"
    "                $moveInDate === null,\n"
    "                fn ($query) => $query->whereNull('move_in_date'),\n"
    "                fn ($query) => $query->whereDate('move_in_date', $moveInDate)\n"
    "            )\n"
)
START_WHEN = (
    "            ->when(\n"
    "                $startDate === null,\n"
    "                fn ($query) => $query->whereNull('start_date'),\n"
    "                fn ($query) => $query->whereDate('start_date', $startDate)\n"
    "            )\n"
)
STAFF_WARN = (
    "                    $rowWarnings[] = ['row' => $rowNum, 'message' => \"担当者ユーザー名「{$row['staff_user_name']}」がシステムに見つからないため、担当者は未設定でインポートします\"];\n"
    "                }\n"
    "            }\n"
    "\n"
)
LOOP_HEAD_T = (
    "        $contractKeyTracker = [];   // 見分けのキー => 最初の行（CSV 内の重複）\n"
    "\n"
    "        foreach ($rows as $i => $row) {\n"
    "            $rowNum = $i + 2;\n"
    "            // 警告はいったん行の中に貯め、取り込むと決めたときだけ画面の一覧へ移す（クラスの docblock）\n"
    "            $rowWarnings = [];\n"
    "\n"
    "            // 必須チェック\n"
    "            if ($row['property_name'] === '') {\n"
    "                $errors[] = ['row' => $rowNum, 'message' => '物件名が未入力です'];\n"
    "                continue;\n"
    "            }\n"
    "            if ($row['room_number'] === '') {\n"
    "                $errors[] = ['row' => $rowNum, 'message' => '部屋番号が未入力です'];\n"
    "                continue;\n"
    "            }\n"
    "            // テナント名は任意（新規登録画面と同じ仕様）\n"
)

# 見分けに使わない日付の検査（設計書 §4.4。照合より前に置く）
RENT_START_T = (
    "            if ($row['rent_start_date'] !== '') {\n"
    "                $rentStartDate = CsvDate::normalize($row['rent_start_date']);\n"
    "                if (!$rentStartDate) {\n"
    "                    $errors[] = ['row' => $rowNum, 'message' => \"賃料開始日「{$row['rent_start_date']}」の形式が不正です\"];\n"
    "                    continue;\n"
    "                }\n"
    "                $row['rent_start_date'] = $rentStartDate;\n"
    "            }\n"
)
PAST_DATES_P = (
    "            $endDate = CsvDate::normalize($row['contract_end_date']);\n"
    "            if (!$endDate) {\n"
    "                $errors[] = ['row' => $rowNum, 'message' => \"解約日「{$row['contract_end_date']}」の形式が不正です（YYYY-MM-DD）\"];\n"
    "                continue;\n"
    "            }\n"
    "            $row['contract_end_date'] = $endDate;\n"
    "\n"
    "            // 解約日 >= 契約日\n"
    "            if ($endDate < $contractDate) {\n"
    "                $errors[] = ['row' => $rowNum, 'message' => \"解約日（{$endDate}）が契約日（{$contractDate}）より前です\"];\n"
    "                continue;\n"
    "            }\n"
    "\n"
    "            // 解約日が今日より未来 → 警告（過去契約のはず）\n"
    "            if ($endDate > JapanTime::today()->format('Y-m-d')) {\n"
    "                $rowWarnings[] = ['row' => $rowNum, 'message' => \"解約日（{$endDate}）が今日より未来です（過去契約として登録します）\"];\n"
    "            }\n"
    "\n"
    "            // 賃料開始日チェック（契約日 〜 解約日 の範囲内）\n"
    "            if ($row['rent_start_date'] !== '') {\n"
    "                $rentStartDate = CsvDate::normalize($row['rent_start_date']);\n"
    "                if (!$rentStartDate) {\n"
    "                    $errors[] = ['row' => $rowNum, 'message' => \"賃料開始日「{$row['rent_start_date']}」の形式が不正です\"];\n"
    "                    continue;\n"
    "                }\n"
    "                if ($rentStartDate < $contractDate || $rentStartDate > $endDate) {\n"
    "                    $errors[] = ['row' => $rowNum, 'message' => \"賃料開始日（{$rentStartDate}）は契約日〜解約日の範囲内である必要があります\"];\n"
    "                    continue;\n"
    "                }\n"
    "                $row['rent_start_date'] = $rentStartDate;\n"
    "            }\n"
    "\n"
)
CUSTOMER_HEAD_P = "            // 顧客の存在チェック（なければ自動作成予定リストに追加）\n"
MOVE_OUT_R = (
    "            $moveOutDate = null;\n"
    "            if ($row['move_out_date'] !== '') {\n"
    "                $moveOutDate = CsvDate::normalize($row['move_out_date']);\n"
    "                if (!$moveOutDate) {\n"
    "                    $errors[] = ['row' => $rowNum, 'message' => \"退去日「{$row['move_out_date']}」の形式が不正です\"];\n"
    "                    continue;\n"
    "                }\n"
    "            }\n"
    "\n"
    "            // ステータス自動決定\n"
    "            $status = $moveOutDate ? MsContractStatus::Terminated->value : MsContractStatus::Active->value;\n"
    "\n"
)
END_K = (
    "            $endDate = null;\n"
    "            if ($row['end_date'] !== '') {\n"
    "                $endDate = CsvDate::normalize($row['end_date']);\n"
    "                if (!$endDate) {\n"
    "                    $errors[] = ['row' => $rowNum, 'message' => \"終了日「{$row['end_date']}」の形式が不正です\"];\n"
    "                    continue;\n"
    "                }\n"
    "            }\n"
    "\n"
    "            // ステータス自動決定\n"
    "            $status = $endDate ? MsContractStatus::Terminated->value : MsContractStatus::Active->value;\n"
    "\n"
)
ACTIVE_HEAD_R = "            // 二重契約チェック（active 契約のみ警告。terminated 契約は無視）\n"
ACTIVE_HEAD_K = "            // 二重契約チェック（active 契約のみ警告）\n"

# 顧客の取込の showForm()（契約を作らないメソッド。コメントの中の語を数えないことの確かめに使う）
SHOW_FORM = "    public function showForm()\n    {\n        return view('admin.customers.import');\n"
SHOW_FORM_COMMENTED = "    public function showForm()\n    {\n        // ここでは Contract::create( を呼ばない（コメントの中の語は数えない）\n        return view('admin.customers.import');\n"

# (ID, [(ファイル, 置き換える前, 置き換えた後[, 当たる数]), ...])。当たる数の既定は 1
MUTATIONS = [
    # ---- カナリア（隔離が効いていれば、コピー側のコードが読まれて赤になる）----
    ("C0", [(TC, "\n        return view('admin.tenant-import.index', [\n", "\n        return view('admin.tenant-import.index-canary', [\n")]),
    # ---- 照合を外す（経路ごと）----
    ("K01", [(TC, "            $registered = $this->findRegisteredContract($unit->id, $customer?->id, $contractDate);\n", "            $registered = null;\n")]),
    ("K02", [(TC, "                $registered = $this->findRegisteredContract($unit->id, $existingCustomer->id, $contractDate);\n", "                $registered = null;\n")]),
    ("K03", [(MC, "            $registered = $this->findRegisteredRoomContract($room->id, $tenant->id, $contractDate, $moveInDate);\n", "            $registered = null;\n")]),
    ("K04", [(MC, "            $registered = $this->findRegisteredParkingContract($parking->id, $tenant->id, $contractDate, $startDate);\n", "            $registered = null;\n")]),
    # ---- 照合のメソッド: キーの要素を外す・比べ方を変える ----
    ("F01", [(TC, "        return Contract::where('unit_id', $unitId)\n", "        return Contract::query()\n")]),
    ("F02", [(TC, TENANT_CUSTOMER_WHEN, "")]),
    ("F03", [(TC, "            ->whereDate('contract_date', $contractDate)\n", "")]),
    ("F04", [(TC, "                fn ($query) => $query->whereNull('customer_id'),\n", "                fn ($query) => $query,\n")]),
    ("F05", [(MC, "        return MsContract::where('room_id', $roomId)\n            ->where('tenant_id', $tenantId)\n", "        return MsContract::where('tenant_id', $tenantId)\n")]),
    ("F06", [(MC, "        return MsContract::where('room_id', $roomId)\n            ->where('tenant_id', $tenantId)\n", "        return MsContract::where('room_id', $roomId)\n")]),
    ("F07", [(MC, ROOM_FINDER_HEAD, "        return MsContract::where('room_id', $roomId)\n            ->where('tenant_id', $tenantId)\n")]),
    ("F08", [(MC, MOVE_IN_WHEN, "")]),
    ("F09", [(MC, "        return MsParkingContract::where('parking_id', $parkingId)\n            ->where('tenant_id', $tenantId)\n", "        return MsParkingContract::where('tenant_id', $tenantId)\n")]),
    ("F10", [(MC, "        return MsParkingContract::where('parking_id', $parkingId)\n            ->where('tenant_id', $tenantId)\n", "        return MsParkingContract::where('parking_id', $parkingId)\n")]),
    ("F11", [(MC, PARKING_FINDER_HEAD, "        return MsParkingContract::where('parking_id', $parkingId)\n            ->where('tenant_id', $tenantId)\n")]),
    ("F12", [(MC, START_WHEN, "")]),
    ("F13", [(TC, "            ->whereDate('contract_date', $contractDate)\n", "            ->where('contract_date', $contractDate)\n")]),
    ("F14", [(MC, "                fn ($query) => $query->whereDate('move_in_date', $moveInDate)\n", "                fn ($query) => $query->where('move_in_date', $moveInDate)\n")]),
    ("F15", [(MC, "                fn ($query) => $query->whereDate('start_date', $startDate)\n", "                fn ($query) => $query->where('start_date', $startDate)\n")]),
    ("F16", [(MC, ROOM_FINDER_HEAD, ROOM_FINDER_HEAD.replace("fn ($query) => $query->whereNull('contract_date'),", "fn ($query) => $query,"))]),
    ("F17", [(MC, "                fn ($query) => $query->whereNull('move_in_date'),\n", "                fn ($query) => $query,\n")]),
    ("F18", [(MC, PARKING_FINDER_HEAD, PARKING_FINDER_HEAD.replace("fn ($query) => $query->whereNull('contract_date'),", "fn ($query) => $query,"))]),
    ("F19", [(MC, "                fn ($query) => $query->whereNull('start_date'),\n", "                fn ($query) => $query,\n")]),
    ("F20", [(TC, "        return Contract::where('unit_id', $unitId)\n", "        return Contract::withTrashed()->where('unit_id', $unitId)\n")]),
    ("F21", [(TC, "            ->orderBy('id')\n", "            ->orderByDesc('id')\n")]),
    ("F22", [(MC, "                fn ($query) => $query->whereDate('move_in_date', $moveInDate)\n            )\n            ->orderBy('id')\n", "                fn ($query) => $query->whereDate('move_in_date', $moveInDate)\n            )\n            ->orderByDesc('id')\n")]),
    ("F23", [(MC, "                fn ($query) => $query->whereDate('start_date', $startDate)\n            )\n            ->orderBy('id')\n", "                fn ($query) => $query->whereDate('start_date', $startDate)\n            )\n            ->orderByDesc('id')\n")]),
    ("F24", [(TC, "            if ($existingCustomer) {\n                $registered = $this->findRegisteredContract($unit->id, $existingCustomer->id, $contractDate);\n", "            if (true) {\n                $registered = $this->findRegisteredContract($unit->id, $existingCustomer?->id, $contractDate);\n")]),
    ("F25", [(TC, "            ->orderBy('id')\n", "")]),
    # 部屋の照合に、そろえる前の契約日（CSV の文字のまま）を渡す
    ("F26", [(MC, "            $registered = $this->findRegisteredRoomContract($room->id, $tenant->id, $contractDate, $moveInDate);\n", "            $registered = $this->findRegisteredRoomContract($room->id, $tenant->id, $row['contract_date'], $moveInDate);\n")]),
    # ---- CSV の中の重複 ----
    ("D01", [(TC, KEY_T + DUP_IF, KEY_T)]),
    ("D02", [(TC, KEY_P + DUP_IF, KEY_P)]),
    ("D03", [(MC, KEY_R + DUP_IF, KEY_R)]),
    ("D04", [(MC, KEY_K + DUP_IF, KEY_K)]),
    ("D05", [(TC, KEY_T, KEY_T.replace("$contractKey = $unit->id . '|' . ", "$contractKey = "))]),
    ("D06", [(TC, KEY_T, KEY_T.replace("($customer ? 'id:' . $customer->id : 'none') . '|' . ", ""))]),
    ("D07", [(TC, KEY_T, KEY_T.replace(" . '|' . $contractDate;", ";"))]),
    ("D08", [(TC, KEY_P, KEY_P.replace("'name:' . $custName", "'name:'"))]),
    ("D09", [(MC, KEY_R, KEY_R.replace("$room->id . '|' . ", ""))]),
    ("D10", [(MC, KEY_R, KEY_R.replace("$tenant->id . '|' . ", ""))]),
    ("D11", [(MC, KEY_R, KEY_R.replace("($contractDate ?? '') . '|' . ", ""))]),
    ("D12", [(MC, KEY_R, KEY_R.replace(" . '|' . ($moveInDate ?? '');", ";"))]),
    ("D13", [(MC, KEY_K, KEY_K.replace("$parking->id . '|' . ", ""))]),
    ("D14", [(MC, KEY_K, KEY_K.replace("$tenant->id . '|' . ", ""))]),
    ("D15", [(MC, KEY_K, KEY_K.replace("($contractDate ?? '') . '|' . ", ""))]),
    ("D16", [(MC, KEY_K, KEY_K.replace(" . '|' . ($startDate ?? '');", ";"))]),
    # 最初の行を覚える位置を照合の後ろへ（照合の塊の後ろへ移す）
    ("D17", [(TC, TRACK + "\n" + MATCH_T, MATCH_T + TRACK)]),
    ("D18", [(TC, TRACK + "\n" + MATCH_P, MATCH_P + TRACK)]),
    ("D19", [(MC, TRACK + "\n" + MATCH_R, MATCH_R + TRACK)]),
    ("D20", [(MC, TRACK + "\n" + MATCH_K, MATCH_K + TRACK)]),
    # ---- 警告を貯めずに直接積む（警告の場所ごと）----
    ("W01", [(TC, "                $rowWarnings[] = ['row' => $rowNum, 'message' => \"区画「{$propName} {$unit->display_name}」には既にアクティブな契約", "                $warnings[] = ['row' => $rowNum, 'message' => \"区画「{$propName} {$unit->display_name}」には既にアクティブな契約")]),
    ("W02", [(TC, "                $rowWarnings[] = ['row' => $rowNum, 'message' => \"解約日（{$endDate}）が今日より未来です", "                $warnings[] = ['row' => $rowNum, 'message' => \"解約日（{$endDate}）が今日より未来です")]),
    ("W03", [(TC, "                $rowWarnings[] = ['row' => $rowNum, 'message' => \"区画「{$propName} {$unit->display_name}」に期間が重なる", "                $warnings[] = ['row' => $rowNum, 'message' => \"区画「{$propName} {$unit->display_name}」に期間が重なる")]),
    ("W04", [(TC, "                $rowWarnings[] = ['row' => $rowNum, 'message' => \"物件「{$propName}」の区画「{$displayName}」は削除済みです。削除済みの区画のまま", "                $warnings[] = ['row' => $rowNum, 'message' => \"物件「{$propName}」の区画「{$displayName}」は削除済みです。削除済みの区画のまま")]),
    ("W05", [(MC, STAFF_WARN + "            // 日付チェック\n", STAFF_WARN.replace("$rowWarnings[]", "$warnings[]") + "            // 日付チェック\n")]),
    ("W06", [(MC, "                    $rowWarnings[] = ['row' => $rowNum, 'message' => \"部屋「{$propName} {$room->room_number}」には既に契約中の入居者がいます", "                    $warnings[] = ['row' => $rowNum, 'message' => \"部屋「{$propName} {$room->room_number}」には既に契約中の入居者がいます")]),
    ("W07", [(MC, "                    $rowWarnings[] = ['row' => $rowNum, 'message' => \"紐付部屋番号「{$row['linked_room_number']}」が物件", "                    $warnings[] = ['row' => $rowNum, 'message' => \"紐付部屋番号「{$row['linked_room_number']}」が物件")]),
    ("W08", [(MC, "                        $rowWarnings[] = ['row' => $rowNum, 'message' => \"紐付部屋番号「{$row['linked_room_number']}」に有効な部屋契約", "                        $warnings[] = ['row' => $rowNum, 'message' => \"紐付部屋番号「{$row['linked_room_number']}」に有効な部屋契約")]),
    ("W09", [(MC, STAFF_WARN + DATES_HEAD_K, STAFF_WARN.replace("$rowWarnings[]", "$warnings[]") + DATES_HEAD_K)]),
    ("W10", [(MC, "                    $rowWarnings[] = ['row' => $rowNum, 'message' => \"駐車場「{$propName} {$parking->parking_number}」には既に使用中の契約", "                    $warnings[] = ['row' => $rowNum, 'message' => \"駐車場「{$propName} {$parking->parking_number}」には既に使用中の契約")]),
    # 警告を一覧へ移す位置を金額の検査より前へ（経路ごと）
    ("W11", [(TC, MONEY_END_T, MONEY_END_T.replace(MERGE + "\n", "")), (TC, MONEY_T, MERGE + "\n" + MONEY_T)]),
    ("W12", [(TC, "削除済みの区画のまま過去契約として取り込みます\"];\n            }\n" + MERGE, "削除済みの区画のまま過去契約として取り込みます\"];\n            }\n"), (TC, MONEY_P, MERGE + "\n" + MONEY_P)]),
    ("W13", [(MC, MONEY_END_R, MONEY_END_R.replace(MERGE + "\n", "")), (MC, MONEY_R, MONEY_R.replace("            // 金額フィールドチェック\n", MERGE + "\n            // 金額フィールドチェック\n"))]),
    ("W14", [(MC, FEE_K + MERGE, FEE_K), (MC, FEE_K, MERGE + "\n" + FEE_K)]),
    # 行ごとに警告を空にしない（ループの外で 1 回だけ空にする）
    ("W15", [(TC, LOOP_HEAD_T, LOOP_HEAD_T.replace("            $rowWarnings = [];\n", "").replace("        foreach ($rows as $i => $row) {\n", "        $rowWarnings = [];\n        foreach ($rows as $i => $row) {\n"))]),
    # ---- 確定のときだけ照合を飛ばす ----
    ("X01", [(TC, "            if ($registered) {\n                $customerLabel", "            if ($registered && ! $request->boolean('confirmed')) {\n                $customerLabel")]),
    ("X02", [(TC, "                if ($registered) {\n                    $skippedRows[]", "                if ($registered && ! $request->boolean('confirmed')) {\n                    $skippedRows[]")]),
    ("X03", [(MC, "            if ($registered) {\n                $skippedRows[] = ['row' => $rowNum, 'message' => \"部屋「", "            if ($registered && ! $request->boolean('confirmed')) {\n                $skippedRows[] = ['row' => $rowNum, 'message' => \"部屋「")]),
    ("X04", [(MC, "            if ($registered) {\n                $skippedRows[] = ['row' => $rowNum, 'message' => \"駐車場「", "            if ($registered && ! $request->boolean('confirmed')) {\n                $skippedRows[] = ['row' => $rowNum, 'message' => \"駐車場「")]),
    # ---- 照合を金額の検査の後ろへ（経路ごと。駐車場は日付と金額の並べ替えを戻す）----
    ("O01", [(TC, BLOCK_T, ""), (TC, MONEY_END_T, MONEY_END_T.replace("            $warnings = array_merge", BLOCK_T + "            $warnings = array_merge"))]),
    ("O02", [(TC, BLOCK_P, ""), (TC, MONEY_END_P, MONEY_END_P.replace("            // 注意もこの行の警告に貯め", BLOCK_P + "            // 注意もこの行の警告に貯め"))]),
    ("O03", [(MC, BLOCK_R, ""), (MC, MONEY_END_R, MONEY_END_R.replace("            $warnings = array_merge", BLOCK_R + "            $warnings = array_merge"))]),
    ("O04", [(MC, FEE_K, ""), (MC, DATES_HEAD_K, FEE_K + DATES_HEAD_K)]),
    # 見分けに使わない日付の検査を照合の後ろへ（設計書 §4.4。登録済みの行の日付の誤りがスキップに化ける）
    ("O05", [(TC, RENT_START_T + "\n" + DUP_HEAD_T, DUP_HEAD_T), (TC, MATCH_T + "\n" + MONEY_T, MATCH_T + "\n" + RENT_START_T + "\n" + MONEY_T)]),
    ("O06", [(TC, PAST_DATES_P + CUSTOMER_HEAD_P, CUSTOMER_HEAD_P), (TC, MATCH_P + "\n", MATCH_P + "\n" + PAST_DATES_P)]),
    ("O07", [(MC, MOVE_OUT_R + DUP_HEAD_R, DUP_HEAD_R), (MC, MATCH_R + "\n" + ACTIVE_HEAD_R, MATCH_R + "\n" + MOVE_OUT_R + ACTIVE_HEAD_R)]),
    ("O08", [(MC, END_K + DUP_HEAD_K, DUP_HEAD_K), (MC, MATCH_K + "\n" + ACTIVE_HEAD_K, MATCH_K + "\n" + END_K + ACTIVE_HEAD_K)]),
    # ---- 画面: 灰色の文 ----
    ("V01", [(VT, "        @elseif(count($rowErrors ?? []) === 0)\n", "        @elseif(count($skippedRows ?? []) > 0)\n")]),
    ("V02", [(VT, GRAY_BRANCH, "")]),
    ("V03", [(VM, "        @elseif(count($rowErrors ?? []) === 0)\n", "        @elseif(count($skippedRows ?? []) > 0)\n")]),
    ("V04", [(VM, GRAY_BRANCH, "")]),
    ("V05", [(VT, "<div style=\"font-size: 13px; color: #6b7280;\">すべての行が登録済みです", "<div style=\"font-size: 13px; color: #dc2626;\">すべての行が登録済みです")]),
    # ---- スキップ・重複の理由の文 ----
    ("R01", [(TC, "・顧客 {$customerLabel}）は既に登録済み", "・顧客 {$customerLabel}）は登録済み")]),
    ("R02", [(TC, "・顧客 {$custName}）は既に登録済み", "・顧客 {$custName}）は登録済み")]),
    ("R03", [(MC, "'・入居日 ' . ($moveInDate ?? '空欄') . \"）は既に登録済み", "'・入居日 ' . ($moveInDate ?? '空欄') . \"）は登録済み")]),
    ("R04", [(MC, "'・開始日 ' . ($startDate ?? '空欄') . \"）は既に登録済み", "'・開始日 ' . ($startDate ?? '空欄') . \"）は登録済み")]),
    ("R05", [(TC, "$row['customer_name'] : '空欄';", "$row['customer_name'] : '';")]),
    ("R06", [(TC, KEY_T + DUP_IF, KEY_T + DUP_IF.replace("と同じ契約が重複しています", "と重複しています"))]),
    # ---- タブの説明 ----
    ("N01", [(IT, "                        <li>契約作成時に区画のステータスが「入居中」に更新されます</li>\n                        <li>同じ区画・テナント名・契約日の契約が登録済みなら、その行はスキップされます（家賃などが違っても書き換えません）</li>\n", "                        <li>契約作成時に区画のステータスが「入居中」に更新されます</li>\n")]),
    ("N02", [(IT, "                        <li>同一区画に期間が重なる契約があっても警告のみで取込実行されます</li>\n                        <li>同じ区画・テナント名・契約日の契約が登録済みなら、その行はスキップされます（家賃などが違っても書き換えません）</li>\n", "                        <li>同一区画に期間が重なる契約があっても警告のみで取込実行されます</li>\n")]),
    ("N03", [(IM, "                        <li>同じ部屋・入居者名・契約日・入居日の契約が登録済みなら、その行はスキップされます（家賃などが違っても書き換えません）</li>\n", "")]),
    ("N04", [(IM, "                        <li>同じ駐車場・入居者名・契約日・開始日の契約が登録済みなら、その行はスキップされます（月額料金などが違っても書き換えません）</li>\n", "")]),
    # ---- 構造のテスト ----
    ("S01", [(SCAN, "        'App\\Http\\Controllers\\Admin\\MansionImportController::executeParkingContract' => 'findRegisteredParkingContract',\n", "")]),
    ("S02", [(SCAN, "        'App\\Http\\Controllers\\Admin\\ZealMemberImportController::execute' => ", "        'App\\Http\\Controllers\\Admin\\ZealMemberImportController::executeX' => ")]),
    ("S03", [(CC, "class CustomerImportController extends Controller\n{\n", "class CustomerImportController extends Controller\n{\n    private function mutationProbe(): void\n    {\n        \\App\\Models\\Contract::create([]);\n    }\n\n")]),
    # 対照: メソッドの中のコメントに Contract::create( と書く（コメントは数えないので緑が正しい）
    ("S04", [(CC, SHOW_FORM, SHOW_FORM_COMMENTED)]),
    # 同じコメント＋走査のコメント除去を外す（除去が効いていることの確かめ。分類のテストが落ちる）
    ("S06", [(CC, SHOW_FORM, SHOW_FORM_COMMENTED), (SCAN, "        foreach (token_get_all('<?php ' . $source) as $token) {\n", "        return $source;\n        foreach (token_get_all('<?php ' . $source) as $token) {\n")]),
    ("S05", [(SCAN, "'/\\b(?:[A-Z]\\w*)?Contract::(?:create|forceCreate|firstOrCreate|updateOrCreate|insert)\\s*\\(|\\bnew\\s+(?:[A-Z]\\w*)?Contract\\s*\\(/'", "'/\\b[A-Z]\\w*Contract::(?:create|forceCreate|firstOrCreate|updateOrCreate|insert)\\s*\\(|\\bnew\\s+[A-Z]\\w*Contract\\s*\\(/'")]),
    # ---- テスト用スキーマ ----
    ("Z01", [(MIG, "            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();\n", "            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();\n")]),
    ("Z02", [
        (MIG, "            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();\n", "            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();\n"),
        (MIG2, "            $table->integer('final_month_amount')->nullable()->comment('最終月の金額（手動入力のとき）');\n        });\n    }\n", "            $table->integer('final_month_amount')->nullable()->comment('最終月の金額（手動入力のとき）');\n        });\n        Schema::table('contracts', function (Blueprint $table) {\n            $table->foreignId('customer_id')->nullable()->change();\n        });\n    }\n"),
    ]),
]


def git(iso, *args):
    return subprocess.run(["git", "-C", iso, *args], capture_output=True, text=True, errors="replace").stdout


def run_phpunit(iso, junit, targets):
    # JUnit は、このスクリプトが手元で起動した PHPUnit の書いたものだけを読む（外から来た XML は読まない）
    env = dict(os.environ, APP_KEY="base64:" + base64.b64encode(os.urandom(32)).decode())
    views = os.path.join(iso, "..", os.path.basename(iso) + "-views")
    os.makedirs(views, exist_ok=True)
    env["VIEW_COMPILED_PATH"] = views
    subprocess.run(["./vendor/bin/phpunit", "--do-not-cache-result", "--log-junit", junit] + targets, cwd=iso, env=env,
                   capture_output=True, text=True, errors="replace")
    if not os.path.exists(junit):
        return None
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
    ap.add_argument("--narrow", action="store_true")
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
            # 書いた順に当てたときの数を見る（移す変異は、1 つ目を当てたあとの本文で 2 つ目を数える）
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

    targets = NARROW if args.narrow else []
    out = os.path.join(iso, "..", os.path.basename(iso) + ("-narrow" if args.narrow else "") + "-results.jsonl")
    ids = args.ids or [mid for mid, _ in MUTATIONS]
    for mid in ids:
        edits = edits_of(mid)
        if git(iso, "status", "--porcelain").strip():
            sys.exit(f"{mid}: 始める前に作業ツリーが空でない（前の変異の残骸）。止める")
        paths = sorted({e[0] for e in edits})
        try:
            for e in edits:
                path, old, new = e[0], e[1], e[2]
                want = e[3] if len(e) > 3 else 1
                full = os.path.join(iso, path)
                src = open(full, encoding="utf-8").read()
                n = src.count(old)
                if n != want:
                    sys.exit(f"{mid}: {path} の置き換えが {n} か所（{want} でない）。止める")
                open(full, "w", encoding="utf-8").write(src.replace(old, new))
            landed = git(iso, "status", "--porcelain").strip()
            stat = git(iso, "diff", "--stat").strip().splitlines()
            if not landed:
                sys.exit(f"{mid}: 置き換えたのに作業ツリーが空（当たっていない）。止める")
            junit = os.path.join(iso, "..", f"junit-{os.path.basename(iso)}-{mid}.xml")
            failures = run_phpunit(iso, junit, targets)
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
⚠ 実行役は理由の **1 行目**しか記録しない。複数行の文字列を比べる失敗は PHPUnit が最初の改行で切るので、期待と違って見えたら、その変異だけを使い捨ての写しに当てて全文を見る（前回の J11 の教訓）。

- [ ] **Step 3: どの変異も決めた数だけ当たることと、作業ツリーが空であることを確かめる**

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cr-mut-commit"); for s in a b c; do git -C "$SP/cr-mut-$C-$s" status --porcelain | head -3; done; python3 "$SP/cr-mutate.py" --iso "$SP/cr-mut-$C-a" --check
```

期待: `status` はどれも何も出さない・`変異 101 通り・当たらないもの 0 件`（カナリア C0 を含めて 101 通り＝変異 100 通り＋カナリア）

- [ ] **Step 4: カナリアを 3 つのコピーで通す**（隔離が効いていれば、コピー側のコードが読まれて赤になる。3 つを Bash の `run_in_background` で同時に起動し、終わりの知らせを待つ）

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cr-mut-commit"); python3 "$SP/cr-mutate.py" --iso "$SP/cr-mut-$C-a" C0 > "$SP/cr-mut-$C-a-canary.log" 2>&1
```

（`-a` を `-b`・`-c` に替えた 2 本も同時に起動する）

期待: 3 つとも `== C0 app/Http/Controllers/Admin/TenantImportController.php  落ちたテスト 13 件`（下の表の C0 の行。試作では関係するテスト 20 本で測った。全件でも同じ 13 本の見込み＝`NARROW` の外に、テナントの取込の画面を描くテストは無い）。**どれか 1 つでも赤にならなければ止める**（コピーでなく元の worktree のコードが読まれている＝ Bug #50）。

- [ ] **Step 5: 変異を流す**（3 つを Bash の `run_in_background` で**同時に**起動し、3 つとも終わりの知らせを待つ。途中で作業ツリーやログを覗いて判断しない＝変異の途中を拾うと偽の赤になる。全件で流すので、それぞれ 33〜34 回・合わせて 1〜2 時間）

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cr-mut-commit"); python3 "$SP/cr-mutate.py" --list | cut -f1 | grep -v -e '^C0$' > "$SP/cr-ids.txt"; awk 'NR%3==1' "$SP/cr-ids.txt" | tr '\n' ' ' > "$SP/cr-ids-a.txt"; awk 'NR%3==2' "$SP/cr-ids.txt" | tr '\n' ' ' > "$SP/cr-ids-b.txt"; awk 'NR%3==0' "$SP/cr-ids.txt" | tr '\n' ' ' > "$SP/cr-ids-c.txt"; wc -w "$SP"/cr-ids-a.txt "$SP"/cr-ids-b.txt "$SP"/cr-ids-c.txt
```

期待: 34・33・33（計 100）。そのあと 3 本を同時に:

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cr-mut-commit"); python3 "$SP/cr-mutate.py" --iso "$SP/cr-mut-$C-a" $(cat "$SP/cr-ids-a.txt") > "$SP/cr-mut-$C-a.log" 2>&1
```

（`-a` を `-b`・`-c` に替えた 2 本も同時に起動する。⚠ zsh で `$(cat …)` は空白で分かれて渡る＝コマンド置換は単語分割される）

- [ ] **Step 6: 期待と突き合わせる**（下の表。**落ちたテストの集合・本数・理由の文言まで**。記録は `$SP/cr-mut-$C-{a,b,c}-results.jsonl`）
  - 表の「期待」は、試作で関係するテスト 20 本（実行役の `NARROW`）に絞って測った値。全件で流して表に無いテストが落ちたら、理由を調べて記録する
  - 期待と違ったら（緑のまま・別のテストが落ちた・理由が違う）、理由を調べる。テストの穴ならテストを足してコミットし、その変異を当て直す。当て直す前に、隔離した worktree を新しいコミットへ進める（名前は控えの `C` のまま）:

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cr-mut-commit"); NEW=$(git -C /Users/masanori/site/manage/.claude/worktrees/contract-reimport rev-parse HEAD); for s in a b c; do git -C "$SP/cr-mut-$C-$s" status --porcelain && git -C "$SP/cr-mut-$C-$s" checkout --detach "$NEW"; done
```

  - 1 つだけ当て直すときは `--narrow` で流すテストを絞れる（例 `python3 "$SP/cr-mutate.py" --iso "$SP/cr-mut-$C-a" --narrow D18`）。表の記録は全件で取り直す
  - 緑が正しい変異は 2 通り（表に理由を書いた）: **F25**（SQLite では等価）・**S04**（対照）。これ以外が緑なら穴

**カナリア:**

| ID | 変異 | 期待（落ちるテスト・理由の 1 行目）|
|---|---|---|
| C0 | テナントの取込の画面のビュー名を無いものに（カナリア） | 13 本: TenantContractReimportTest::test_the_contract_tabs_explain_that_registered_contracts_are_skipped — `Expected response status code [200] but received 500.`<br>TenantImportDoubleSubmitTest::test_sending_the_same_confirmation_twice_imports_once ×5 — `戻り先の画面の赤帯に、断りの文言が出ていない`（executeProperty・executeUnit・executeCustomer・executeContract・executePastContract）<br>TenantImportDoubleSubmitTest::test_a_confirmation_without_a_usable_token_imports_nothing ×3 — `戻り先の画面の赤帯に、断りの文言が出ていない`（鍵が無い・鍵が空・鍵が配列）<br>TenantImportRejectionTest::test_a_failed_reupload_from_another_tab_on_the_preview_goes_back_to_that_tab ×2 — `Expected response status code [200] but received 500.`（ファイルを選ばずに送った・見出しだけで行が無い）<br>TenantImportRejectionTest::test_a_failed_import_after_confirming_goes_back_to_the_same_tab — `Expected response status code [200] but received 500.`<br>ImportValidationFeedbackTest::test_an_invalid_file_shows_the_reason_on_screen — `/admin/tenant-import/property に不正なファイルを投げたのに、差し戻し先 /admin/tenant-import?tab=property に理由が出ていない`（テナントCSV） |

**照合を外す（経路ごと）:**

| ID | 変異 | 期待（落ちるテスト・理由の 1 行目）|
|---|---|---|
| K01 | 契約タブの照合を外す（`$registered = null`） | 17 本: TenantContractReimportTest::test_uploading_the_same_csv_again_skips_every_row — `取り込む行が残っている（tab=contract）`（契約）<br>TenantContractReimportTest::test_uploading_a_csv_with_an_added_row_imports_only_the_new_row — `Failed asserting that two arrays are identical.`（契約）<br>TenantContractReimportTest::test_a_row_without_a_tenant_name_skips_the_same_contract_without_a_customer — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_contract_row_that_differs_only_outside_the_key_is_skipped ×6 — `Failed asserting that two arrays are identical.`（家賃を改定した・備考が違う・屋号が違う・賃料開始日が違う・日付の書き方が違う・取り込んだあとで解約した）<br>TenantContractReimportTest::test_a_contract_without_a_tenant_name_is_skipped_when_uploaded_again — `取り込む行が残っている（tab=contract）`<br>TenantContractReimportTest::test_when_the_same_contract_is_already_registered_twice_the_first_one_is_named — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_contract_imported_on_the_past_contract_tab_is_skipped_on_the_contract_tab — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_registered_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.`（契約）<br>TenantContractReimportTest::test_on_the_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_contract_registered_after_the_preview_is_skipped_at_confirmation — `Failed asserting that two strings are equal.`（契約）<br>TenantContractReimportTest::test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error — `Failed asserting that two arrays are identical.`（契約）<br>ImportControllerContractMatchScanTest::test_the_methods_that_need_matching_call_the_matcher — `App\Http\Controllers\Admin\TenantImportController::executeContract が findRegisteredContract() を呼んでいない（同じ CSV を上げ直すと、契約が二重に入る）` |
| K02 | 過去契約タブの照合を外す | 14 本: TenantContractReimportTest::test_uploading_the_same_csv_again_skips_every_row — `取り込む行が残っている（tab=past-contract）`（過去契約）<br>TenantContractReimportTest::test_uploading_a_csv_with_an_added_row_imports_only_the_new_row — `Failed asserting that two arrays are identical.`（過去契約）<br>TenantContractReimportTest::test_a_past_contract_row_that_differs_only_outside_the_key_is_skipped ×4 — `Failed asserting that two arrays are identical.`（家賃が違う・解約日が違う・備考が違う・日付の書き方が違う）<br>TenantContractReimportTest::test_a_past_contract_for_a_customer_created_by_the_first_import_is_skipped_when_uploaded_again — `取り込む行が残っている（tab=past-contract）`<br>TenantContractReimportTest::test_a_contract_imported_on_the_contract_tab_and_then_terminated_is_skipped_on_the_past_contract_tab — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_registered_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.`（過去契約）<br>TenantContractReimportTest::test_on_the_past_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_the_deleted_unit_notice_is_not_shown_for_a_skipped_row — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_contract_registered_after_the_preview_is_skipped_at_confirmation — `Failed asserting that two strings are equal.`（過去契約）<br>TenantContractReimportTest::test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error — `Failed asserting that two arrays are identical.`（過去契約）<br>ImportControllerContractMatchScanTest::test_the_methods_that_need_matching_call_the_matcher — `App\Http\Controllers\Admin\TenantImportController::executePastContract が findRegisteredContract() を呼んでいない（同じ CSV を上げ直すと、契約が二重に入る）` |
| K03 | 部屋契約の照合を外す | 16 本: MansionContractReimportTest::test_uploading_the_same_csv_again_skips_every_row — `取り込む行が残っている（tab=room-contract）`（部屋契約）<br>MansionContractReimportTest::test_uploading_a_csv_with_an_added_row_imports_only_the_new_row — `Failed asserting that two arrays are identical.`（部屋契約）<br>MansionContractReimportTest::test_a_row_that_differs_only_outside_the_key_is_skipped ×5 — `Failed asserting that two arrays are identical.`（部屋契約: 家賃を改定した・部屋契約: 退去した・部屋契約: メモが違う・部屋契約: 担当者が違う・部屋契約: 日付の書き方が違う）<br>MansionContractReimportTest::test_when_the_same_room_contract_is_already_registered_twice_the_first_one_is_named — `Failed asserting that two arrays are identical.`<br>MansionContractReimportTest::test_blank_dates_match_only_blank_dates ×2 — `Failed asserting that two arrays are identical.`（部屋契約: 2 つとも空欄どうし・部屋契約: 契約日は空欄どうし・入居日は同じ）<br>MansionContractReimportTest::test_a_contract_without_dates_is_skipped_when_uploaded_again — `取り込む行が残っている（tab=room-contract）`（部屋契約）<br>MansionContractReimportTest::test_a_registered_room_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.`<br>MansionContractReimportTest::test_room_contract_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>MansionContractReimportTest::test_a_contract_registered_after_the_preview_is_skipped_at_confirmation — `Failed asserting that two strings are equal.`（部屋契約）<br>MansionContractReimportTest::test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error — `Failed asserting that two arrays are identical.`（部屋契約: 家賃）<br>ImportControllerContractMatchScanTest::test_the_methods_that_need_matching_call_the_matcher — `App\Http\Controllers\Admin\MansionImportController::executeRoomContract が findRegisteredRoomContract() を呼んでいない（同じ CSV を上げ直すと、契約が二重に入る）` |
| K04 | 駐車場契約の照合を外す | 17 本: MansionContractReimportTest::test_uploading_the_same_csv_again_skips_every_row — `取り込む行が残っている（tab=parking-contract）`（駐車場契約）<br>MansionContractReimportTest::test_uploading_a_csv_with_an_added_row_imports_only_the_new_row — `Failed asserting that two arrays are identical.`（駐車場契約）<br>MansionContractReimportTest::test_a_row_that_differs_only_outside_the_key_is_skipped ×5 — `Failed asserting that two arrays are identical.`（駐車場契約: 月額料金を改定した・駐車場契約: 終了した・駐車場契約: 敷金が違う・駐車場契約: 紐付部屋番号が違う・駐車場契約: 日付の書き方が違う）<br>MansionContractReimportTest::test_when_the_same_parking_contract_is_already_registered_twice_the_first_one_is_named — `Failed asserting that two arrays are identical.`<br>MansionContractReimportTest::test_blank_dates_match_only_blank_dates ×2 — `Failed asserting that two arrays are identical.`（駐車場契約: 2 つとも空欄どうし・駐車場契約: 契約日は空欄どうし・開始日は同じ）<br>MansionContractReimportTest::test_a_contract_without_dates_is_skipped_when_uploaded_again — `取り込む行が残っている（tab=parking-contract）`（駐車場契約）<br>MansionContractReimportTest::test_a_registered_parking_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.`<br>MansionContractReimportTest::test_parking_contract_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>MansionContractReimportTest::test_a_contract_registered_after_the_preview_is_skipped_at_confirmation — `Failed asserting that two strings are equal.`（駐車場契約）<br>MansionContractReimportTest::test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error ×2 — `Failed asserting that two arrays are identical.`（駐車場契約: 月額料金・駐車場契約: 敷金）<br>ImportControllerContractMatchScanTest::test_the_methods_that_need_matching_call_the_matcher — `App\Http\Controllers\Admin\MansionImportController::executeParkingContract が findRegisteredParkingContract() を呼んでいない（同じ CSV を上げ直すと、契約が二重に入る）` |

**照合のメソッド（キーの要素・比べ方・削除・順序・まだ無い顧客）:**

| ID | 変異 | 期待（落ちるテスト・理由の 1 行目）|
|---|---|---|
| F01 | テナントの照合から区画の条件を外す | 2 本: TenantContractReimportTest::test_a_contract_row_that_differs_in_one_key_element_is_imported — `見分けのキーが違うのにスキップした`（区画が違う）<br>TenantContractReimportTest::test_a_past_contract_row_that_differs_in_one_key_element_is_imported — `見分けのキーが違うのにスキップした`（区画が違う） |
| F02 | テナントの照合から顧客の条件を外す | 4 本: TenantContractReimportTest::test_a_contract_row_that_differs_in_one_key_element_is_imported ×2 — `見分けのキーが違うのにスキップした`（顧客が違う・顧客が空欄）<br>TenantContractReimportTest::test_a_past_contract_row_that_differs_in_one_key_element_is_imported — `見分けのキーが違うのにスキップした`（顧客が違う）<br>TenantContractReimportTest::test_a_row_with_a_tenant_name_is_not_matched_to_a_contract_without_a_customer — `顧客のいる行を「顧客の無い契約」と取り違えてスキップした` |
| F03 | テナントの照合から契約日の条件を外す | 4 本: TenantContractReimportTest::test_a_contract_row_that_differs_in_one_key_element_is_imported — `見分けのキーが違うのにスキップした`（契約日が違う）<br>TenantContractReimportTest::test_a_past_contract_row_that_differs_in_one_key_element_is_imported — `見分けのキーが違うのにスキップした`（契約日が違う）<br>TenantContractReimportTest::test_on_the_past_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_the_deleted_unit_notice_is_not_shown_for_a_skipped_row — `Failed asserting that two arrays are identical.` |
| F04 | テナントの照合で、顧客が空欄の行に顧客の条件を付けない（どの顧客の契約とも一致する） | 1 本: TenantContractReimportTest::test_a_contract_row_that_differs_in_one_key_element_is_imported — `見分けのキーが違うのにスキップした`（顧客が空欄） |
| F05 | 部屋の照合から部屋の条件を外す | 1 本: MansionContractReimportTest::test_a_row_that_differs_in_one_key_element_is_imported — `見分けのキーが違うのにスキップした`（部屋契約: 部屋が違う） |
| F06 | 部屋の照合から入居者の条件を外す | 1 本: MansionContractReimportTest::test_a_row_that_differs_in_one_key_element_is_imported — `見分けのキーが違うのにスキップした`（部屋契約: 入居者が違う） |
| F07 | 部屋の照合から契約日の条件を外す | 3 本: MansionContractReimportTest::test_a_row_that_differs_in_one_key_element_is_imported — `見分けのキーが違うのにスキップした`（部屋契約: 契約日が違う）<br>MansionContractReimportTest::test_blank_dates_match_only_blank_dates ×2 — `片方だけ空欄の日付を同じとみなしてスキップした`（部屋契約: 契約日だけ登録済みが空欄・部屋契約: 契約日だけ CSV が空欄） |
| F08 | 部屋の照合から入居日の条件を外す | 3 本: MansionContractReimportTest::test_a_row_that_differs_in_one_key_element_is_imported — `見分けのキーが違うのにスキップした`（部屋契約: 入居日が違う）<br>MansionContractReimportTest::test_blank_dates_match_only_blank_dates ×2 — `片方だけ空欄の日付を同じとみなしてスキップした`（部屋契約: 入居日だけ登録済みが空欄・部屋契約: 入居日だけ CSV が空欄） |
| F09 | 駐車場の照合から駐車場の条件を外す | 1 本: MansionContractReimportTest::test_a_row_that_differs_in_one_key_element_is_imported — `見分けのキーが違うのにスキップした`（駐車場契約: 駐車場が違う） |
| F10 | 駐車場の照合から入居者の条件を外す | 1 本: MansionContractReimportTest::test_a_row_that_differs_in_one_key_element_is_imported — `見分けのキーが違うのにスキップした`（駐車場契約: 入居者が違う） |
| F11 | 駐車場の照合から契約日の条件を外す | 3 本: MansionContractReimportTest::test_a_row_that_differs_in_one_key_element_is_imported — `見分けのキーが違うのにスキップした`（駐車場契約: 契約日が違う）<br>MansionContractReimportTest::test_blank_dates_match_only_blank_dates ×2 — `片方だけ空欄の日付を同じとみなしてスキップした`（駐車場契約: 契約日だけ登録済みが空欄・駐車場契約: 契約日だけ CSV が空欄） |
| F12 | 駐車場の照合から開始日の条件を外す | 3 本: MansionContractReimportTest::test_a_row_that_differs_in_one_key_element_is_imported — `見分けのキーが違うのにスキップした`（駐車場契約: 開始日が違う）<br>MansionContractReimportTest::test_blank_dates_match_only_blank_dates ×2 — `片方だけ空欄の日付を同じとみなしてスキップした`（駐車場契約: 開始日だけ登録済みが空欄・駐車場契約: 開始日だけ CSV が空欄） |
| F13 | テナントの契約日を `whereDate` でなく素の `where` で比べる | 29 本: TenantContractReimportTest::test_uploading_the_same_csv_again_skips_every_row ×2 — `取り込む行が残っている（tab=contract）`（契約） ／ `取り込む行が残っている（tab=past-contract）`（過去契約）<br>TenantContractReimportTest::test_uploading_a_csv_with_an_added_row_imports_only_the_new_row ×2 — `Failed asserting that two arrays are identical.`（契約・過去契約）<br>TenantContractReimportTest::test_a_row_without_a_tenant_name_skips_the_same_contract_without_a_customer — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_contract_row_that_differs_only_outside_the_key_is_skipped ×6 — `Failed asserting that two arrays are identical.`（家賃を改定した・備考が違う・屋号が違う・賃料開始日が違う・日付の書き方が違う・取り込んだあとで解約した）<br>TenantContractReimportTest::test_a_past_contract_row_that_differs_only_outside_the_key_is_skipped ×4 — `Failed asserting that two arrays are identical.`（家賃が違う・解約日が違う・備考が違う・日付の書き方が違う）<br>TenantContractReimportTest::test_a_contract_without_a_tenant_name_is_skipped_when_uploaded_again — `取り込む行が残っている（tab=contract）`<br>TenantContractReimportTest::test_a_past_contract_for_a_customer_created_by_the_first_import_is_skipped_when_uploaded_again — `取り込む行が残っている（tab=past-contract）`<br>TenantContractReimportTest::test_when_the_same_contract_is_already_registered_twice_the_first_one_is_named — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_contract_imported_on_the_contract_tab_and_then_terminated_is_skipped_on_the_past_contract_tab — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_contract_imported_on_the_past_contract_tab_is_skipped_on_the_contract_tab — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_registered_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error ×2 — `Failed asserting that two arrays are identical.`（契約・過去契約）<br>TenantContractReimportTest::test_on_the_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_on_the_past_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_the_deleted_unit_notice_is_not_shown_for_a_skipped_row — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_contract_registered_after_the_preview_is_skipped_at_confirmation ×2 — `Failed asserting that two strings are equal.`（契約・過去契約）<br>TenantContractReimportTest::test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error ×2 — `Failed asserting that two arrays are identical.`（契約・過去契約） |
| F14 | 部屋の入居日を素の `where` で比べる | 13 本: MansionContractReimportTest::test_uploading_the_same_csv_again_skips_every_row — `取り込む行が残っている（tab=room-contract）`（部屋契約）<br>MansionContractReimportTest::test_uploading_a_csv_with_an_added_row_imports_only_the_new_row — `Failed asserting that two arrays are identical.`（部屋契約）<br>MansionContractReimportTest::test_a_row_that_differs_only_outside_the_key_is_skipped ×5 — `Failed asserting that two arrays are identical.`（部屋契約: 家賃を改定した・部屋契約: 退去した・部屋契約: メモが違う・部屋契約: 担当者が違う・部屋契約: 日付の書き方が違う）<br>MansionContractReimportTest::test_when_the_same_room_contract_is_already_registered_twice_the_first_one_is_named — `Failed asserting that two arrays are identical.`<br>MansionContractReimportTest::test_blank_dates_match_only_blank_dates — `Failed asserting that two arrays are identical.`（部屋契約: 契約日は空欄どうし・入居日は同じ）<br>MansionContractReimportTest::test_a_registered_room_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.`<br>MansionContractReimportTest::test_room_contract_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>MansionContractReimportTest::test_a_contract_registered_after_the_preview_is_skipped_at_confirmation — `Failed asserting that two strings are equal.`（部屋契約）<br>MansionContractReimportTest::test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error — `Failed asserting that two arrays are identical.`（部屋契約: 家賃） |
| F15 | 駐車場の開始日を素の `where` で比べる | 14 本: MansionContractReimportTest::test_uploading_the_same_csv_again_skips_every_row — `取り込む行が残っている（tab=parking-contract）`（駐車場契約）<br>MansionContractReimportTest::test_uploading_a_csv_with_an_added_row_imports_only_the_new_row — `Failed asserting that two arrays are identical.`（駐車場契約）<br>MansionContractReimportTest::test_a_row_that_differs_only_outside_the_key_is_skipped ×5 — `Failed asserting that two arrays are identical.`（駐車場契約: 月額料金を改定した・駐車場契約: 終了した・駐車場契約: 敷金が違う・駐車場契約: 紐付部屋番号が違う・駐車場契約: 日付の書き方が違う）<br>MansionContractReimportTest::test_when_the_same_parking_contract_is_already_registered_twice_the_first_one_is_named — `Failed asserting that two arrays are identical.`<br>MansionContractReimportTest::test_blank_dates_match_only_blank_dates — `Failed asserting that two arrays are identical.`（駐車場契約: 契約日は空欄どうし・開始日は同じ）<br>MansionContractReimportTest::test_a_registered_parking_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.`<br>MansionContractReimportTest::test_parking_contract_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>MansionContractReimportTest::test_a_contract_registered_after_the_preview_is_skipped_at_confirmation — `Failed asserting that two strings are equal.`（駐車場契約）<br>MansionContractReimportTest::test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error ×2 — `Failed asserting that two arrays are identical.`（駐車場契約: 月額料金・駐車場契約: 敷金） |
| F16 | 部屋の照合で、契約日が空欄のとき条件を付けない（空欄なら何とでも一致） | 1 本: MansionContractReimportTest::test_blank_dates_match_only_blank_dates — `片方だけ空欄の日付を同じとみなしてスキップした`（部屋契約: 契約日だけ CSV が空欄） |
| F17 | 部屋の照合で、入居日が空欄のとき条件を付けない | 1 本: MansionContractReimportTest::test_blank_dates_match_only_blank_dates — `片方だけ空欄の日付を同じとみなしてスキップした`（部屋契約: 入居日だけ CSV が空欄） |
| F18 | 駐車場の照合で、契約日が空欄のとき条件を付けない | 1 本: MansionContractReimportTest::test_blank_dates_match_only_blank_dates — `片方だけ空欄の日付を同じとみなしてスキップした`（駐車場契約: 契約日だけ CSV が空欄） |
| F19 | 駐車場の照合で、開始日が空欄のとき条件を付けない | 1 本: MansionContractReimportTest::test_blank_dates_match_only_blank_dates — `片方だけ空欄の日付を同じとみなしてスキップした`（駐車場契約: 開始日だけ CSV が空欄） |
| F20 | テナントの照合で削除した契約も含める（`withTrashed()`） | 2 本: TenantContractReimportTest::test_a_contract_row_matching_only_a_deleted_contract_is_imported — `削除した契約と突き合わせてスキップした`<br>TenantContractReimportTest::test_a_past_contract_row_matching_only_a_deleted_contract_is_imported — `削除した契約と突き合わせてスキップした` |
| F21 | テナントの照合で id の大きいものを選ぶ（`orderByDesc`） | 1 本: TenantContractReimportTest::test_when_the_same_contract_is_already_registered_twice_the_first_one_is_named — `Failed asserting that two arrays are identical.` |
| F22 | 部屋の照合で id の大きいものを選ぶ | 1 本: MansionContractReimportTest::test_when_the_same_room_contract_is_already_registered_twice_the_first_one_is_named — `Failed asserting that two arrays are identical.` |
| F23 | 駐車場の照合で id の大きいものを選ぶ | 1 本: MansionContractReimportTest::test_when_the_same_parking_contract_is_already_registered_twice_the_first_one_is_named — `Failed asserting that two arrays are identical.` |
| F24 | 過去契約で、まだ無い顧客の行も照合する（null を渡す） | 1 本: TenantContractReimportTest::test_a_past_contract_row_for_a_new_customer_is_not_matched_to_a_contract_without_a_customer — `まだ無い顧客の行を「顧客の無い契約」と取り違えてスキップした` |
| F25 | テナントの照合の `orderBy('id')` を外す | **0 本** — **等価（SQLite では）**。SQLite は索引をたどるとき、同じ区画・顧客・契約日の行を id の順に返すので、`orderBy` が無くても最初の 1 件は id のいちばん小さいもの。本番の MySQL での順序を決めるために残す（外す変異 F21 の `orderByDesc` は捕まる） |
| F26 | 部屋の照合に、そろえる前の契約日（CSV の文字のまま）を渡す | 4 本: MansionContractReimportTest::test_a_row_that_differs_only_outside_the_key_is_skipped — `Failed asserting that two arrays are identical.`（部屋契約: 日付の書き方が違う）<br>MansionContractReimportTest::test_blank_dates_match_only_blank_dates ×2 — `Failed asserting that two arrays are identical.`（部屋契約: 2 つとも空欄どうし・部屋契約: 契約日は空欄どうし・入居日は同じ）<br>MansionContractReimportTest::test_a_contract_without_dates_is_skipped_when_uploaded_again — `取り込む行が残っている（tab=room-contract）`（部屋契約）（日付の書き方の違いと空欄の日付で、そろえる前の値が一致しない） |

**CSV の中の重複:**

| ID | 変異 | 期待（落ちるテスト・理由の 1 行目）|
|---|---|---|
| D01 | 契約タブの CSV 内の重複の確かめを外す | 5 本: TenantContractReimportTest::test_the_second_row_with_the_same_key_in_a_contract_csv_is_an_error ×4 — `Failed asserting that two arrays are identical.`（同じ行・階を空欄にして部屋番号に書いた・日付の書き方が違う・家賃が違う）<br>TenantContractReimportTest::test_a_registered_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.`（契約） |
| D02 | 過去契約タブの CSV 内の重複の確かめを外す | 3 本: TenantContractReimportTest::test_a_registered_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.`（過去契約）<br>TenantContractReimportTest::test_the_second_row_with_the_same_key_in_a_past_contract_csv_is_an_error ×2 — `Failed asserting that two arrays are identical.`（顧客は登録済み・顧客はまだ無い） |
| D03 | 部屋契約の CSV 内の重複の確かめを外す | 4 本: MansionContractReimportTest::test_the_second_row_with_the_same_key_in_the_csv_is_an_error ×3 — `Failed asserting that two arrays are identical.`（部屋契約: 同じ行・部屋契約: 日付の書き方が違う・部屋契約: 日付が 2 つとも空欄）<br>MansionContractReimportTest::test_a_registered_room_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.` |
| D04 | 駐車場契約の CSV 内の重複の確かめを外す | 3 本: MansionContractReimportTest::test_the_second_row_with_the_same_key_in_the_csv_is_an_error ×2 — `Failed asserting that two arrays are identical.`（駐車場契約: 同じ行・駐車場契約: 日付の書き方が違う）<br>MansionContractReimportTest::test_a_registered_parking_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.` |
| D05 | 契約タブの重複のキーから区画を外す | 1 本: TenantContractReimportTest::test_two_contract_rows_that_differ_in_one_key_element_are_both_imported — `見分けのキーが違う 2 行を CSV 内の重複にした`（区画） |
| D06 | 契約タブの重複のキーから顧客を外す | 2 本: TenantContractReimportTest::test_two_contract_rows_that_differ_in_one_key_element_are_both_imported ×2 — `見分けのキーが違う 2 行を CSV 内の重複にした`（顧客・顧客（空欄）） |
| D07 | 契約タブの重複のキーから契約日を外す | 2 本: TenantContractReimportTest::test_two_contract_rows_that_differ_in_one_key_element_are_both_imported — `見分けのキーが違う 2 行を CSV 内の重複にした`（契約日）<br>TenantContractReimportTest::test_on_the_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.` |
| D08 | 過去契約タブの重複のキーに、まだ無い顧客の名前を入れない | 1 本: TenantContractReimportTest::test_two_past_contract_rows_for_different_new_customers_are_both_imported — `Failed asserting that two arrays are identical.` |
| D09 | 部屋契約の重複のキーから部屋を外す | 1 本: MansionContractReimportTest::test_two_rows_that_differ_in_one_key_element_are_both_imported — `見分けのキーが違う 2 行を CSV 内の重複にした`（部屋契約: 部屋） |
| D10 | 部屋契約の重複のキーから入居者を外す | 1 本: MansionContractReimportTest::test_two_rows_that_differ_in_one_key_element_are_both_imported — `見分けのキーが違う 2 行を CSV 内の重複にした`（部屋契約: 入居者） |
| D11 | 部屋契約の重複のキーから契約日を外す | 3 本: MansionContractReimportTest::test_two_rows_that_differ_in_one_key_element_are_both_imported ×2 — `見分けのキーが違う 2 行を CSV 内の重複にした`（部屋契約: 契約日・部屋契約: 契約日が空欄）<br>MansionContractReimportTest::test_room_contract_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.` |
| D12 | 部屋契約の重複のキーから入居日を外す | 1 本: MansionContractReimportTest::test_two_rows_that_differ_in_one_key_element_are_both_imported — `見分けのキーが違う 2 行を CSV 内の重複にした`（部屋契約: 入居日） |
| D13 | 駐車場契約の重複のキーから駐車場を外す | 1 本: MansionContractReimportTest::test_two_rows_that_differ_in_one_key_element_are_both_imported — `見分けのキーが違う 2 行を CSV 内の重複にした`（駐車場契約: 駐車場） |
| D14 | 駐車場契約の重複のキーから入居者を外す | 1 本: MansionContractReimportTest::test_two_rows_that_differ_in_one_key_element_are_both_imported — `見分けのキーが違う 2 行を CSV 内の重複にした`（駐車場契約: 入居者） |
| D15 | 駐車場契約の重複のキーから契約日を外す | 2 本: MansionContractReimportTest::test_two_rows_that_differ_in_one_key_element_are_both_imported — `見分けのキーが違う 2 行を CSV 内の重複にした`（駐車場契約: 契約日）<br>MansionContractReimportTest::test_parking_contract_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.` |
| D16 | 駐車場契約の重複のキーから開始日を外す | 2 本: MansionContractReimportTest::test_two_rows_that_differ_in_one_key_element_are_both_imported ×2 — `見分けのキーが違う 2 行を CSV 内の重複にした`（駐車場契約: 開始日・駐車場契約: 開始日が空欄） |
| D17 | 契約タブで、最初の行を覚える位置を照合の後ろへ | 1 本: TenantContractReimportTest::test_a_registered_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.`（契約） |
| D18 | 過去契約タブで、最初の行を覚える位置を照合の後ろへ | 1 本: TenantContractReimportTest::test_a_registered_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.`（過去契約） |
| D19 | 部屋契約で、最初の行を覚える位置を照合の後ろへ | 1 本: MansionContractReimportTest::test_a_registered_room_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.` |
| D20 | 駐車場契約で、最初の行を覚える位置を照合の後ろへ | 1 本: MansionContractReimportTest::test_a_registered_parking_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.` |

**警告を行ごとに貯める:**

| ID | 変異 | 期待（落ちるテスト・理由の 1 行目）|
|---|---|---|
| W01 | 契約タブの「既にアクティブな契約」の警告を直接積む | 2 本: TenantContractReimportTest::test_on_the_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>ImportControllerContractMatchScanTest::test_the_methods_that_match_collect_warnings_per_row — `App\Http\Controllers\Admin\TenantImportController::executeContract が画面の警告の一覧へ直接積んでいる（行の中の $rowWarnings に貯める。直接積むと、スキップ・エラーの行にまた出る）` |
| W02 | 過去契約タブの「解約日が今日より未来」の警告を直接積む | 2 本: TenantContractReimportTest::test_on_the_past_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>ImportControllerContractMatchScanTest::test_the_methods_that_match_collect_warnings_per_row — `App\Http\Controllers\Admin\TenantImportController::executePastContract が画面の警告の一覧へ直接積んでいる（行の中の $rowWarnings に貯める。直接積むと、スキップ・エラーの行にまた出る）` |
| W03 | 過去契約タブの「期間が重なる」の警告を直接積む | 2 本: TenantContractReimportTest::test_on_the_past_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>ImportControllerContractMatchScanTest::test_the_methods_that_match_collect_warnings_per_row — `App\Http\Controllers\Admin\TenantImportController::executePastContract が画面の警告の一覧へ直接積んでいる（行の中の $rowWarnings に貯める。直接積むと、スキップ・エラーの行にまた出る）` |
| W04 | 過去契約タブの「削除済みの区画」の注意を直接積む | 1 本: ImportControllerContractMatchScanTest::test_the_methods_that_match_collect_warnings_per_row — `App\Http\Controllers\Admin\TenantImportController::executePastContract が画面の警告の一覧へ直接積んでいる（行の中の $rowWarnings に貯める。直接積むと、スキップ・エラーの行にまた出る）`（振る舞いは同じ＝注意は取り込むと決めた位置で積むので、直接積んでもスキップ・エラーの行には出ない。構造のテストだけが捕まえる） |
| W05 | 部屋契約の「担当者が見つからない」警告を直接積む | 3 本: MansionContractReimportTest::test_a_row_that_differs_only_outside_the_key_is_skipped — `Failed asserting that two arrays are identical.`（部屋契約: 担当者が違う）<br>MansionContractReimportTest::test_room_contract_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>ImportControllerContractMatchScanTest::test_the_methods_that_match_collect_warnings_per_row — `App\Http\Controllers\Admin\MansionImportController::executeRoomContract が画面の警告の一覧へ直接積んでいる（行の中の $rowWarnings に貯める。直接積むと、スキップ・エラーの行にまた出る）` |
| W06 | 部屋契約の「既に契約中の入居者」の警告を直接積む | 2 本: MansionContractReimportTest::test_room_contract_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>ImportControllerContractMatchScanTest::test_the_methods_that_match_collect_warnings_per_row — `App\Http\Controllers\Admin\MansionImportController::executeRoomContract が画面の警告の一覧へ直接積んでいる（行の中の $rowWarnings に貯める。直接積むと、スキップ・エラーの行にまた出る）` |
| W07 | 駐車場契約の「紐付部屋番号が見つからない」警告を直接積む | 2 本: MansionContractReimportTest::test_parking_contract_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>ImportControllerContractMatchScanTest::test_the_methods_that_match_collect_warnings_per_row — `App\Http\Controllers\Admin\MansionImportController::executeParkingContract が画面の警告の一覧へ直接積んでいる（行の中の $rowWarnings に貯める。直接積むと、スキップ・エラーの行にまた出る）` |
| W08 | 駐車場契約の「紐付部屋番号に有効な部屋契約が無い」警告を直接積む | 3 本: MansionContractReimportTest::test_a_row_that_differs_only_outside_the_key_is_skipped — `Failed asserting that two arrays are identical.`（駐車場契約: 紐付部屋番号が違う）<br>MansionContractReimportTest::test_parking_contract_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>ImportControllerContractMatchScanTest::test_the_methods_that_match_collect_warnings_per_row — `App\Http\Controllers\Admin\MansionImportController::executeParkingContract が画面の警告の一覧へ直接積んでいる（行の中の $rowWarnings に貯める。直接積むと、スキップ・エラーの行にまた出る）` |
| W09 | 駐車場契約の「担当者が見つからない」警告を直接積む | 2 本: MansionContractReimportTest::test_parking_contract_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>ImportControllerContractMatchScanTest::test_the_methods_that_match_collect_warnings_per_row — `App\Http\Controllers\Admin\MansionImportController::executeParkingContract が画面の警告の一覧へ直接積んでいる（行の中の $rowWarnings に貯める。直接積むと、スキップ・エラーの行にまた出る）` |
| W10 | 駐車場契約の「既に使用中の契約」の警告を直接積む | 2 本: MansionContractReimportTest::test_parking_contract_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>ImportControllerContractMatchScanTest::test_the_methods_that_match_collect_warnings_per_row — `App\Http\Controllers\Admin\MansionImportController::executeParkingContract が画面の警告の一覧へ直接積んでいる（行の中の $rowWarnings に貯める。直接積むと、スキップ・エラーの行にまた出る）` |
| W11 | 契約タブで、警告を一覧へ移す位置を金額の検査の前へ | 1 本: TenantContractReimportTest::test_on_the_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.` |
| W12 | 過去契約タブで、警告を一覧へ移す位置を金額の検査の前へ | 3 本: TenantContractReimportTest::test_on_the_past_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_the_deleted_unit_notice_is_not_shown_for_a_skipped_row — `Failed asserting that two arrays are identical.`<br>TenantUnitImportTest::test_a_past_contract_row_for_a_deleted_unit_is_attached_to_the_deleted_unit — `Failed asserting that two arrays are identical.` |
| W13 | 部屋契約で、警告を一覧へ移す位置を金額の検査の前へ | 1 本: MansionContractReimportTest::test_room_contract_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.` |
| W14 | 駐車場契約で、警告を一覧へ移す位置を金額の検査の前へ | 1 本: MansionContractReimportTest::test_parking_contract_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.` |
| W15 | 契約タブで、行の警告を行ごとに空にしない（ループの外で 1 回だけ） | 1 本: TenantContractReimportTest::test_on_the_contract_tab_warnings_are_shown_only_for_rows_that_will_be_imported — `Failed asserting that two arrays are identical.` |

**確定のときも同じ検査:**

| ID | 変異 | 期待（落ちるテスト・理由の 1 行目）|
|---|---|---|
| X01 | 契約タブで、確定のときだけ照合を飛ばす | 2 本: TenantContractReimportTest::test_uploading_a_csv_with_an_added_row_imports_only_the_new_row — `Failed asserting that two strings are equal.`（契約）<br>TenantContractReimportTest::test_a_contract_registered_after_the_preview_is_skipped_at_confirmation — `Failed asserting that two strings are equal.`（契約） |
| X02 | 過去契約タブで、確定のときだけ照合を飛ばす | 2 本: TenantContractReimportTest::test_uploading_a_csv_with_an_added_row_imports_only_the_new_row — `Failed asserting that two strings are equal.`（過去契約）<br>TenantContractReimportTest::test_a_contract_registered_after_the_preview_is_skipped_at_confirmation — `Failed asserting that two strings are equal.`（過去契約） |
| X03 | 部屋契約で、確定のときだけ照合を飛ばす | 2 本: MansionContractReimportTest::test_uploading_a_csv_with_an_added_row_imports_only_the_new_row — `Failed asserting that two strings are equal.`（部屋契約）<br>MansionContractReimportTest::test_a_contract_registered_after_the_preview_is_skipped_at_confirmation — `Failed asserting that two strings are equal.`（部屋契約） |
| X04 | 駐車場契約で、確定のときだけ照合を飛ばす | 2 本: MansionContractReimportTest::test_uploading_a_csv_with_an_added_row_imports_only_the_new_row — `Failed asserting that two strings are equal.`（駐車場契約）<br>MansionContractReimportTest::test_a_contract_registered_after_the_preview_is_skipped_at_confirmation — `Failed asserting that two strings are equal.`（駐車場契約） |

**行の検査の順番（照合の位置・見分けに使わない日付）:**

| ID | 変異 | 期待（落ちるテスト・理由の 1 行目）|
|---|---|---|
| O01 | 契約タブの CSV 内の重複と照合を、金額の検査の後ろへ | 1 本: TenantContractReimportTest::test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error — `Failed asserting that two arrays are identical.`（契約） |
| O02 | 過去契約タブの CSV 内の重複と照合を、金額の検査の後ろへ | 1 本: TenantContractReimportTest::test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error — `Failed asserting that two arrays are identical.`（過去契約） |
| O03 | 部屋契約の CSV 内の重複と照合を、金額の検査の後ろへ | 1 本: MansionContractReimportTest::test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error — `Failed asserting that two arrays are identical.`（部屋契約: 家賃） |
| O04 | 駐車場契約の金額の検査を日付の検査の前へ戻す（並べ替えを戻す） | 3 本: MansionContractReimportTest::test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error ×2 — `Failed asserting that two arrays are identical.`（駐車場契約: 月額料金・駐車場契約: 敷金）<br>MansionContractReimportTest::test_a_parking_row_with_both_a_bad_date_and_a_bad_fee_reports_the_date — `Failed asserting that two arrays are identical.` |
| O05 | 契約タブの賃料開始日の検査を照合の後ろへ | 1 本: TenantContractReimportTest::test_a_registered_row_with_a_bad_date_is_an_error_not_skipped — `Failed asserting that two arrays are identical.`（契約: 賃料開始日） |
| O06 | 過去契約タブの解約日（と解約日に続く検査・賃料開始日）の検査を照合の後ろへ | 1 本: TenantContractReimportTest::test_a_registered_row_with_a_bad_date_is_an_error_not_skipped — `Failed asserting that two arrays are identical.`（過去契約: 解約日） |
| O07 | 部屋契約の退去日の検査（と状態）を照合の後ろへ | 1 本: MansionContractReimportTest::test_a_registered_row_with_a_bad_date_is_an_error_not_skipped — `Failed asserting that two arrays are identical.`（部屋契約: 退去日） |
| O08 | 駐車場契約の終了日の検査（と状態）を照合の後ろへ | 1 本: MansionContractReimportTest::test_a_registered_row_with_a_bad_date_is_an_error_not_skipped — `Failed asserting that two arrays are identical.`（駐車場契約: 終了日） |

**画面（灰色の文・理由の文・タブの説明）:**

| ID | 変異 | 期待（落ちるテスト・理由の 1 行目）|
|---|---|---|
| V01 | テナントの確認画面で、灰色の文の条件を「スキップが 1 件以上」に（エラーの行があっても灰色） | 1 本: TenantContractReimportTest::test_a_property_csv_with_an_error_row_keeps_the_red_notice — `「インポート可能なデータがありません。CSVを修正してください。」が画面に出ていない（tab=property）` |
| V02 | テナントの確認画面の灰色の枝を消す | 5 本: TenantContractReimportTest::test_uploading_the_same_csv_again_skips_every_row ×2 — `灰色の「すべての行が登録済み」の文が出ていない（tab=contract）`（契約） ／ `灰色の「すべての行が登録済み」の文が出ていない（tab=past-contract）`（過去契約）<br>TenantContractReimportTest::test_a_contract_without_a_tenant_name_is_skipped_when_uploaded_again — `灰色の「すべての行が登録済み」の文が出ていない（tab=contract）`<br>TenantContractReimportTest::test_a_past_contract_for_a_customer_created_by_the_first_import_is_skipped_when_uploaded_again — `灰色の「すべての行が登録済み」の文が出ていない（tab=past-contract）`<br>TenantContractReimportTest::test_a_property_csv_whose_rows_are_all_registered_shows_the_gray_notice — `灰色の「すべての行が登録済み」の文が出ていない（tab=property）` |
| V03 | 賃貸マンションの確認画面で、灰色の文の条件を「スキップが 1 件以上」に | 1 本: MansionContractReimportTest::test_a_tenant_csv_with_an_error_row_keeps_the_red_notice — `「インポート可能なデータがありません。CSVを修正してください。」が画面に出ていない（tab=tenant）` |
| V04 | 賃貸マンションの確認画面の灰色の枝を消す | 5 本: MansionContractReimportTest::test_uploading_the_same_csv_again_skips_every_row ×2 — `灰色の「すべての行が登録済み」の文が出ていない（tab=room-contract）`（部屋契約） ／ `灰色の「すべての行が登録済み」の文が出ていない（tab=parking-contract）`（駐車場契約）<br>MansionContractReimportTest::test_a_contract_without_dates_is_skipped_when_uploaded_again ×2 — `灰色の「すべての行が登録済み」の文が出ていない（tab=room-contract）`（部屋契約） ／ `灰色の「すべての行が登録済み」の文が出ていない（tab=parking-contract）`（駐車場契約）<br>MansionContractReimportTest::test_a_tenant_csv_whose_rows_are_all_registered_shows_the_gray_notice — `灰色の「すべての行が登録済み」の文が出ていない（tab=tenant）` |
| V05 | テナントの灰色の文を赤字（`#dc2626`）に | 5 本: TenantContractReimportTest::test_uploading_the_same_csv_again_skips_every_row ×2 — `灰色の「すべての行が登録済み」の文が出ていない（tab=contract）`（契約） ／ `灰色の「すべての行が登録済み」の文が出ていない（tab=past-contract）`（過去契約）<br>TenantContractReimportTest::test_a_contract_without_a_tenant_name_is_skipped_when_uploaded_again — `灰色の「すべての行が登録済み」の文が出ていない（tab=contract）`<br>TenantContractReimportTest::test_a_past_contract_for_a_customer_created_by_the_first_import_is_skipped_when_uploaded_again — `灰色の「すべての行が登録済み」の文が出ていない（tab=past-contract）`<br>TenantContractReimportTest::test_a_property_csv_whose_rows_are_all_registered_shows_the_gray_notice — `灰色の「すべての行が登録済み」の文が出ていない（tab=property）` |
| R01 | 契約タブの理由の文から「既に」を落とす | 13 本: TenantContractReimportTest::test_uploading_a_csv_with_an_added_row_imports_only_the_new_row — `Failed asserting that two arrays are identical.`（契約）<br>TenantContractReimportTest::test_a_row_without_a_tenant_name_skips_the_same_contract_without_a_customer — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_contract_row_that_differs_only_outside_the_key_is_skipped ×6 — `Failed asserting that two arrays are identical.`（家賃を改定した・備考が違う・屋号が違う・賃料開始日が違う・日付の書き方が違う・取り込んだあとで解約した）<br>TenantContractReimportTest::test_a_contract_without_a_tenant_name_is_skipped_when_uploaded_again — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_when_the_same_contract_is_already_registered_twice_the_first_one_is_named — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_contract_imported_on_the_past_contract_tab_is_skipped_on_the_contract_tab — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_registered_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.`（契約）<br>TenantContractReimportTest::test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error — `Failed asserting that two arrays are identical.`（契約） |
| R02 | 過去契約タブの理由の文から「既に」を落とす | 10 本: TenantContractReimportTest::test_uploading_a_csv_with_an_added_row_imports_only_the_new_row — `Failed asserting that two arrays are identical.`（過去契約）<br>TenantContractReimportTest::test_a_past_contract_row_that_differs_only_outside_the_key_is_skipped ×4 — `Failed asserting that two arrays are identical.`（家賃が違う・解約日が違う・備考が違う・日付の書き方が違う）<br>TenantContractReimportTest::test_a_past_contract_for_a_customer_created_by_the_first_import_is_skipped_when_uploaded_again — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_contract_imported_on_the_contract_tab_and_then_terminated_is_skipped_on_the_past_contract_tab — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_registered_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.`（過去契約）<br>TenantContractReimportTest::test_the_deleted_unit_notice_is_not_shown_for_a_skipped_row — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error — `Failed asserting that two arrays are identical.`（過去契約） |
| R03 | 部屋契約の理由の文から「既に」を落とす | 12 本: MansionContractReimportTest::test_uploading_a_csv_with_an_added_row_imports_only_the_new_row — `Failed asserting that two arrays are identical.`（部屋契約）<br>MansionContractReimportTest::test_a_row_that_differs_only_outside_the_key_is_skipped ×5 — `Failed asserting that two arrays are identical.`（部屋契約: 家賃を改定した・部屋契約: 退去した・部屋契約: メモが違う・部屋契約: 担当者が違う・部屋契約: 日付の書き方が違う）<br>MansionContractReimportTest::test_when_the_same_room_contract_is_already_registered_twice_the_first_one_is_named — `Failed asserting that two arrays are identical.`<br>MansionContractReimportTest::test_blank_dates_match_only_blank_dates ×2 — `Failed asserting that two arrays are identical.`（部屋契約: 2 つとも空欄どうし・部屋契約: 契約日は空欄どうし・入居日は同じ）<br>MansionContractReimportTest::test_a_contract_without_dates_is_skipped_when_uploaded_again — `Failed asserting that two arrays are identical.`（部屋契約）<br>MansionContractReimportTest::test_a_registered_room_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.`<br>MansionContractReimportTest::test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error — `Failed asserting that two arrays are identical.`（部屋契約: 家賃） |
| R04 | 駐車場契約の理由の文から「既に」を落とす | 13 本: MansionContractReimportTest::test_uploading_a_csv_with_an_added_row_imports_only_the_new_row — `Failed asserting that two arrays are identical.`（駐車場契約）<br>MansionContractReimportTest::test_a_row_that_differs_only_outside_the_key_is_skipped ×5 — `Failed asserting that two arrays are identical.`（駐車場契約: 月額料金を改定した・駐車場契約: 終了した・駐車場契約: 敷金が違う・駐車場契約: 紐付部屋番号が違う・駐車場契約: 日付の書き方が違う）<br>MansionContractReimportTest::test_when_the_same_parking_contract_is_already_registered_twice_the_first_one_is_named — `Failed asserting that two arrays are identical.`<br>MansionContractReimportTest::test_blank_dates_match_only_blank_dates ×2 — `Failed asserting that two arrays are identical.`（駐車場契約: 2 つとも空欄どうし・駐車場契約: 契約日は空欄どうし・開始日は同じ）<br>MansionContractReimportTest::test_a_contract_without_dates_is_skipped_when_uploaded_again — `Failed asserting that two arrays are identical.`（駐車場契約）<br>MansionContractReimportTest::test_a_registered_parking_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.`<br>MansionContractReimportTest::test_a_registered_row_with_a_bad_amount_is_skipped_but_an_unregistered_one_is_an_error ×2 — `Failed asserting that two arrays are identical.`（駐車場契約: 月額料金・駐車場契約: 敷金） |
| R05 | 契約タブで、テナント名が空欄のとき「空欄」を書かない | 2 本: TenantContractReimportTest::test_a_row_without_a_tenant_name_skips_the_same_contract_without_a_customer — `Failed asserting that two arrays are identical.`<br>TenantContractReimportTest::test_a_contract_without_a_tenant_name_is_skipped_when_uploaded_again — `Failed asserting that two arrays are identical.` |
| R06 | 契約タブの CSV 内の重複の文を「と重複しています」に | 5 本: TenantContractReimportTest::test_the_second_row_with_the_same_key_in_a_contract_csv_is_an_error ×4 — `Failed asserting that two arrays are identical.`（同じ行・階を空欄にして部屋番号に書いた・日付の書き方が違う・家賃が違う）<br>TenantContractReimportTest::test_a_registered_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error — `Failed asserting that two arrays are identical.`（契約） |
| N01 | テナントの契約タブの説明の 1 行を消す | 1 本: TenantContractReimportTest::test_the_contract_tabs_explain_that_registered_contracts_are_skipped — `契約タブの説明に出ていない` |
| N02 | テナントの過去契約タブの説明の 1 行を消す | 1 本: TenantContractReimportTest::test_the_contract_tabs_explain_that_registered_contracts_are_skipped — `過去契約タブの説明に出ていない` |
| N03 | 賃貸マンションの部屋契約タブの説明の 1 行を消す | 1 本: MansionContractReimportTest::test_the_contract_tabs_explain_that_registered_contracts_are_skipped — `部屋契約タブの説明に出ていない` |
| N04 | 賃貸マンションの駐車場契約タブの説明の 1 行を消す | 1 本: MansionContractReimportTest::test_the_contract_tabs_explain_that_registered_contracts_are_skipped — `駐車場契約タブの説明に出ていない` |

**構造のテスト:**

| ID | 変異 | 期待（落ちるテスト・理由の 1 行目）|
|---|---|---|
| S01 | 構造のテストの「照合が要る」表から駐車場契約を抜く | 1 本: ImportControllerContractMatchScanTest::test_every_import_method_that_creates_contracts_is_classified — `契約を作る取込のメソッドと分類の表がそろっていない（新しい取込は、照合するか、理由をつけて NOT_MATCHED に足す。表に古い名前を残さない）` |
| S02 | 構造のテストの「対象外」表のメソッド名を実在しないものに | 1 本: ImportControllerContractMatchScanTest::test_every_import_method_that_creates_contracts_is_classified — `契約を作る取込のメソッドと分類の表がそろっていない（新しい取込は、照合するか、理由をつけて NOT_MATCHED に足す。表に古い名前を残さない）` |
| S03 | 顧客の取込に、契約を作る private メソッドを足す（分類されていない作成） | 1 本: ImportControllerContractMatchScanTest::test_every_import_method_that_creates_contracts_is_classified — `契約を作る取込のメソッドと分類の表がそろっていない（新しい取込は、照合するか、理由をつけて NOT_MATCHED に足す。表に古い名前を残さない）` |
| S04 | 顧客の取込の `showForm()` の中のコメントに `Contract::create(` と書く（対照） | **0 本** — **対照**（コメントの中の語は数えないので、緑が正しい。コメントの除去を外すと S06 で赤） |
| S05 | 構造のテストの正規表現を最初の形（素の `Contract::` に当たらない）に戻す | 3 本: ImportControllerContractMatchScanTest::test_every_import_method_that_creates_contracts_is_classified — `走査が空振りしている（契約を作る取込のメソッドが少なすぎる）`<br>ImportControllerContractMatchScanTest::test_the_methods_that_need_matching_call_the_matcher — `走査が空振りしている（契約を作る取込のメソッドが少なすぎる）`<br>ImportControllerContractMatchScanTest::test_the_methods_that_match_collect_warnings_per_row — `走査が空振りしている（契約を作る取込のメソッドが少なすぎる）`（下限の確かめが空振りを捕まえる。最初に書いた正規表現の形） |
| S06 | S04 のコメント＋構造のテストのコメント除去を外す | 1 本: ImportControllerContractMatchScanTest::test_every_import_method_that_creates_contracts_is_classified — `契約を作る取込のメソッドと分類の表がそろっていない（新しい取込は、照合するか、理由をつけて NOT_MATCHED に足す。表に古い名前を残さない）` |

**テスト用スキーマ:**

| ID | 変異 | 期待（落ちるテスト・理由の 1 行目）|
|---|---|---|
| Z01 | テスト用スキーマの `customer_id` を NOT NULL に戻す | 5 本: TenantContractReimportTest::test_a_row_with_a_tenant_name_is_not_matched_to_a_contract_without_a_customer — `Illuminate\Database\QueryException: SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: contracts.customer_id (Connection: sqlite, Database: :memory:, SQL: insert into "con`<br>TenantContractReimportTest::test_a_row_without_a_tenant_name_skips_the_same_contract_without_a_customer — `Illuminate\Database\QueryException: SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: contracts.customer_id (Connection: sqlite, Database: :memory:, SQL: insert into "con`<br>TenantContractReimportTest::test_a_contract_without_a_tenant_name_is_skipped_when_uploaded_again — `Failed asserting that null matches expected '契約インポート完了: 1件を登録しました'.`<br>TenantContractReimportTest::test_a_past_contract_row_for_a_new_customer_is_not_matched_to_a_contract_without_a_customer — `Illuminate\Database\QueryException: SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: contracts.customer_id (Connection: sqlite, Database: :memory:, SQL: insert into "con`<br>TenantUnitImportTest::test_the_contracts_table_accepts_a_contract_without_a_customer_like_production — `Illuminate\Database\QueryException: SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: contracts.customer_id (Connection: sqlite, Database: :memory:, SQL: insert into "con` |
| Z02 | Z01 に加えて、候補 1（新しい migration で `->nullable()->change()`）で空欄可にする | 1 本: TenantUnitImportTest::test_contract_status_and_department_are_still_checked — `contracts.status の CHECK が無い（本番の enum と食い違う）`（候補 1 の形。`customer_id` は空欄可になるので空欄のカナリアは緑のまま。CHECK のカナリアだけが落ちる＝この形を採らない理由） |

- [ ] **Step 7: 片づけて記録をコミット**

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/cr-mut-commit"); for s in a b c; do git -C /Users/masanori/site/manage/.claude/worktrees/contract-reimport worktree remove --force "$SP/cr-mut-$C-$s"; done; git -C /Users/masanori/site/manage/.claude/worktrees/contract-reimport worktree list
```

期待: 一覧に `cr-mut-…` が残っていない（ほかの会話の worktree と main repo とこの worktree だけ）。

この計画の末尾の「実測記録」に、表の結果（ID・落ちたテストの数と名前・理由）と、期待と違ったもの・その対応を書いてコミットする:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && git add docs/superpowers/plans/2026-09-29-contract-reimport.md && git commit -m "$(cat <<'EOF'
docs: 契約の上げ直しの改修の変異テストの結果を記録する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 7: ローカルの実ブラウザ確認

**Files:** なし（使い捨てのログイン用ルートは**コミットしない**。確認のあと必ず戻す）

使い捨ての SQLite ＋ `artisan serve` ＋ Playwright MCP（画面が見えている状態）。設計書 §5.7。
⚠ `preview_start` は使わない（main repo の launch.json を解決して実 MySQL に当たる）。
⚠ ブラウザでパスワードを入力しない。ログインは使い捨てのログイン用ルートで入る。
⚠ Playwright MCP が読み書きできるのは `/Users/masanori/site/manage` の下だけ（scratchpad は拒否される）。CSV とスクリーンショットは `.playwright-mcp/`（gitignore 済み）に置き、確認のあと消す。ファイルの選択は `<label>` か `locator.setInputFiles(<パス>)` で行う（「ファイルを選択」の文字では開かないことがある）。
⚠ キャッシュは本番と同じ file にする（`CACHE_STORE=file`。`array` だと `artisan serve` は要求ごとに別のプロセスなので、Bug #69 の鍵を覚えず確定が断られる）。
⚠ 試作では実ブラウザを見ていない。下の表の「見ること」は設計書と PHP のテストから決めた期待（この Task で初めて測る）。

- [ ] **Step 1: 使い捨ての環境**（環境変数は毎回 `source` する＝シェルの状態は次の呼び出しに残らない）

```bash
SP=<Scratchpad directory>; DB="$SP/cr-browser.sqlite"; rm -f "$DB"; : > "$DB"; cat > "$SP/cr-env.sh" <<EOF
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" APP_ENV=local APP_DEBUG=true DB_CONNECTION=sqlite DB_DATABASE="$DB" QUEUE_CONNECTION=sync MAIL_MAILER=log CACHE_STORE=file SESSION_DRIVER=file
EOF
sed 's/APP_KEY="[^"]*"/APP_KEY="…"/' "$SP/cr-env.sh"; ls /Users/masanori/site/manage/.claude/worktrees/contract-reimport/bootstrap/cache/; lsof -nP -iTCP:8768 -sTCP:LISTEN | head -2
```

期待: env の 1 行（APP_KEY は伏せる）・`bootstrap/cache/` に `config.php` が無い（あると環境変数が効かない。`packages.php`・`services.php` はあってよい）・8768 番を掴んでいるプロセスが無い（あれば別の番号にして、下の URL も替える）。

`$SP/seed-cr.php`（scratchpad に置く・コミットしない。**接続先が scratchpad の SQLite でなければ止まる**安全装置つき。第 1 引数にアプリの根を渡す）:

```php
<?php
// 使い捨ての SQLite に、契約の上げ直しの確認用のデータを入れる（scratchpad に置く・コミットしない）
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Customer;
use App\Models\MsProperty;
use App\Models\MsRoom;
use App\Models\MsTenant;
use App\Models\Property;
use App\Models\Unit;
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

// ms_* は本番も raw SQL 管理でマイグレーションに無い → テスト用スキーマの trait で作る
$schema = new class {
    use Tests\Concerns\CreatesMansionSchema;

    public function run(): void
    {
        $this->createMansionSchema();
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

// テナント: 物件・区画 3 つ（1A・2A・3A）・顧客 2 人
$property = Property::create([
    'code' => 'T-BR-1', 'name' => '上げ直しビル', 'property_type' => 'tenant', 'department' => 'tenant',
    'address' => '愛媛県松山市', 'total_floors' => 5,
]);
foreach ([[1, 'A'], [2, 'A'], [3, 'A']] as [$floor, $room]) {
    Unit::create([
        'property_id' => $property->id, 'floor' => $floor, 'room_number' => $room,
        'display_name' => Unit::generateDisplayName($floor, $room), 'status' => 'vacant', 'area_tsubo' => 10,
    ]);
}
Customer::create(['code' => 'CU-BR-1', 'name' => '上げ直し商事', 'customer_type' => 'corporation']);
Customer::create(['code' => 'CU-BR-2', 'name' => '別商事', 'customer_type' => 'corporation']);

// 賃貸マンション: 物件・部屋 3 つ（101・102・103）・入居者 3 人
$ms = MsProperty::create([
    'property_code' => 'MS-BR-1', 'property_name' => '上げ直しハイツ', 'ownership_type' => 'self_owned',
    'address' => '愛媛県松山市', 'created_by' => $user->id,
]);
foreach (['101', '102', '103'] as $no) {
    MsRoom::create(['property_id' => $ms->id, 'room_number' => $no, 'status' => 'vacant']);
}
foreach (['山田太郎', '鈴木次郎', '佐藤三郎'] as $name) {
    MsTenant::create(['tenant_type' => 'resident', 'name' => $name]);
}

echo "user id: {$user->id} / cache: " . config('cache.default') . "\n";
```

確認用の CSV（BOM つき UTF-8）:

```bash
python3 - <<'PY'
import os
d = '/Users/masanori/site/manage/.playwright-mcp'
os.makedirs(d, exist_ok=True)
tenant_header = '物件名,階,部屋番号,テナント名,契約日,賃料開始日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考'
tenant_rows = ['上げ直しビル,1,A,上げ直し商事,2026-04-01,2026-04-01,95000,8000,190000,1500,500,,',
               '上げ直しビル,2,A,別商事,2026-05-01,2026-05-01,100000,,,,,,',
               '上げ直しビル,3,A,別商事,2026-06-01,2026-06-01,110000,,,,,,']
room_header = '物件名,部屋番号,入居者名,契約日,入居日,退去日,家賃,共益費,敷金,礼金,担当者ユーザー名,メモ'
room_rows = ['上げ直しハイツ,101,山田太郎,2024-04-01,2024-04-15,,55000,3000,55000,55000,存在しない担当者,',
             '上げ直しハイツ,102,鈴木次郎,2024-05-01,2024-05-15,,60000,,,,,',
             '上げ直しハイツ,103,佐藤三郎,2024-06-01,2024-06-15,,65000,,,,,']
files = {
    'cr-tenant-contract-2.csv': [tenant_header] + tenant_rows[:2],
    'cr-tenant-contract-3.csv': [tenant_header] + tenant_rows,
    'cr-mansion-room-2.csv': [room_header] + room_rows[:2],
    'cr-mansion-room-3.csv': [room_header] + room_rows,
}
for name, lines in files.items():
    with open(os.path.join(d, name), 'w', encoding='utf-8') as f:
        f.write('﻿' + '\n'.join(lines) + '\n')
print('ok')
PY
```

```bash
SP=<Scratchpad directory>; WT=/Users/masanori/site/manage/.claude/worktrees/contract-reimport; cd "$WT" && source "$SP/cr-env.sh" && php artisan migrate --force 2>&1 | tail -2 && php "$SP/seed-cr.php" "$WT" && /Users/masanori/site/manage/node_modules/.bin/vite build 2>&1 | tail -3
```

期待: `user id: 1 / cache: file`。`vite build` は worktree の `public/build`（gitignore 済み）に `app-DW1DvPK7.css` を書く（本番と同じ名前＝CSS は変わらない）。⚠ `migrate` は `source` した環境変数で使い捨ての SQLite に向く（worktree に `.env` は無い）。main repo では流さない。

- [ ] **Step 2: 使い捨てのログイン用ルートと開発サーバ**（`routes/web.php` の末尾に一時的に足す。**コミットしない**）

```php
// ⚠ 使い捨て（ローカルの実ブラウザ確認だけ。コミットしない）
Route::get('/_dev/login-as/{id}', function (string $id) {
    abort_unless(app()->environment('local'), 404);
    \Illuminate\Support\Facades\Auth::loginUsingId((int) $id);
    return redirect(request('to', '/admin/tenant-import'));
});
```

開発サーバを Bash の `run_in_background` で起動する:

```bash
SP=<Scratchpad directory>; cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && source "$SP/cr-env.sh" && php artisan serve --host=127.0.0.1 --port=8768
```

- [ ] **Step 3: 見ること**（Playwright で `http://127.0.0.1:8768/_dev/login-as/1?to=/admin/tenant-import` を開くとテナントの取込の画面に着く。操作は画面のフォームで行う: タブを押し、ファイルを選んで送信（プレビュー）、確認画面の「インポート実行」を押す）

| # | 操作 | 見ること |
|---|---|---|
| 1 | テナントの契約タブ: `cr-tenant-contract-2.csv` をプレビュー → 「インポート実行」 | 「契約インポート完了: 2件を登録しました」 |
| 2 | 同じ `cr-tenant-contract-2.csv` をもう一度プレビュー | 「全 2 件」「正常: 0 件」「スキップ: 2 件」・灰色の一覧に「行2: 区画「上げ直しビル 1A」の契約（契約日 2026-04-01・顧客 上げ直し商事）は既に登録済み（C-2026-001）のためスキップ」と行3 の同じ形（契約番号は取込が付けた番号）・灰色（`rgb(107, 114, 128)`）の「すべての行が登録済みです（スキップ 2 件）。取り込む行はありません。」・赤字の文が無い・「インポート実行」のボタンが無い |
| 3 | `cr-tenant-contract-3.csv` をプレビュー → 「インポート実行」 | プレビューで「インポート実行（1件）」と「※ 既存データ（2件）はスキップされます」→ 「契約インポート完了: 1件を登録しました」・契約一覧（ステータス: すべて）で上げ直しビルの契約が 3 件（1A・2A・3A 各 1 件）|
| 4 | 賃貸マンションの部屋契約タブ（`/admin/mansion-import?selected_tab=room-contract`）: `cr-mansion-room-2.csv` をプレビュー → 「インポート実行」 | プレビューで「警告: 1 件」（行2 の担当者が見つからない）→ 「部屋契約インポート完了: 2件を登録しました」 |
| 5 | 同じ `cr-mansion-room-2.csv` をもう一度プレビュー | 「スキップ: 2 件」・理由の文（「部屋「上げ直しハイツ 101」の入居者 山田太郎 の契約（契約日 2024-04-01・入居日 2024-04-15）は既に登録済み（既存契約 ID: 1）のためスキップ」など）・**「警告」の件数も一覧も出ない**（スキップした行の警告は捨てる）・灰色の文・ボタンが無い |
| 6 | `cr-mansion-room-3.csv` をプレビュー → 「インポート実行」 | 「インポート実行（1件）」・「※ 既存データ（2件）はスキップされます」・警告なし → 「部屋契約インポート完了: 1件を登録しました」 |
| 7 | 取込の画面 2 つのタブの説明 | テナントの契約・過去契約タブ、賃貸マンションの部屋契約・駐車場契約タブに、それぞれ足した 1 行が出る（タブを切り替えて目で見る）|
| 8 | 375px で 2・5 の確認画面 | `main` の横スクロールが無い（`document.querySelector('main').scrollWidth === document.querySelector('main').clientWidth`）・灰色の文が文字の途中で割れずに折り返す |
| 9 | ここまでの全部 | `browser_console_messages`（`level: warning`・`all: true`）が 0 件 |

- [ ] **Step 4: 片づけ**（必ず行う）

```bash
SP=<Scratchpad directory>; WT=/Users/masanori/site/manage/.claude/worktrees/contract-reimport; git -C "$WT" checkout -- routes/web.php && rm -rf "${WT:?}/public/build" && rm -f "${SP:?}/cr-browser.sqlite" && for f in cr-tenant-contract-2.csv cr-tenant-contract-3.csv cr-mansion-room-2.csv cr-mansion-room-3.csv; do rm -f "/Users/masanori/site/manage/.playwright-mcp/${f:?}"; done; git -C "$WT" status --porcelain; lsof -nP -iTCP:8768 -sTCP:LISTEN | head -2
```

開発サーバは起動した Bash を止める（TaskStop）。`.playwright-mcp/` に溜まる `page-<UTC 時刻>.yml`・`console-<UTC 時刻>.log` は**今回の時刻の分だけ**消す（ほかのセッションのものは消さない。変数のパスの `rm` は `rm -f "${D:?}/${f:?}"` の形で書く）。`status` は何も出さない・8768 番を掴んでいるものが無いこと。`storage/framework/cache`・`sessions` にできたファイルは gitignore 済み（消してよい）。結果を「実測記録」に書く（Task 9 でコミット）。

---

## Task 8: 独立レビュー

**Files:** 指摘に応じて

- [ ] **Step 1: レビューを頼む**（Agent・`general-purpose`・モデルは sonnet 以上。コードは変えさせない。指摘には再現の手順を付けさせる）

頼む内容（そのまま渡す）:

> 契約の上げ直しで二重にしない改修を、欠陥を見つける目でレビューしてください（説明ではなく欠陥の指摘がほしい）。
> 対象: worktree `/Users/masanori/site/manage/.claude/worktrees/contract-reimport` の `git diff 13.x...HEAD`（アプリとテスト）。
> 設計書 `docs/superpowers/specs/2026-09-29-contract-reimport-design.md` と計画 `docs/superpowers/plans/2026-09-29-contract-reimport.md` を読んでから見てください。
> 見てほしいこと: ①設計書との食い違い ②見分けのキー（4 経路のどれかで、キーの要素の取り違え・日付の正規化の前の値で比べている・空欄の比べ方・論理削除・id のいちばん小さいもの）③照合と CSV 内の重複の位置（日付の検査より前に照合していないか・金額の検査より後ろに照合していないか・駐車場の並べ替えで別の検査を落としていないか・確定の経路でも同じ検査を通るか）④警告を行ごとに貯める形（スキップ・エラーの行の警告が画面に残る経路・取り込む行の警告が消える経路・過去契約の削除済みの区画の注意）⑤過去契約で顧客がまだ無い行（照合しない・CSV 内の重複は名前で比べる・自動作成の予定との関係）⑥画面（灰色の文の条件・物件・顧客・部屋・駐車場・入居者タブへの影響・タブの説明）⑦テスト用スキーマ（本番の定義との食い違い・CHECK・外部キー）⑧テストが緑のまま壊せる形が残っていないか（Bug #43・#44・#47・#54 ④ の型。計画の Task 6 の表で緑の F25・S04 の扱いも）⑨文書の誤り。
> 決まり: コードは変えない（探りは scratchpad のコピーか、テストを一時的に足して流し、元に戻す）。`.env` は読まない。全件テストは `cd <worktree> && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`。
> 報告: 指摘ごとに 重さ（Critical / Important / Minor）・場所・再現の手順（落ちるテストか、実行した探りとその出力）・直し方の案。推測だけの指摘は「未実測」と書いてください。

- [ ] **Step 2: 指摘を 1 つずつ実測してから直す**（実測で再現しないものは理由を書いて見送る）
  - 直すたびに、落ちるテストを先に足し（直す前に赤・直した後に緑を確かめる）、関係するテストを流してコミットする
  - 本番のコードを変えたら、その場所に当たる変異を `cr-mutate.py` で当て直す（Task 6 の手順で隔離した worktree を作り直すか進める）
  - 最後に全件テストとコンパイル済みビューの lint（Task 5）をやり直す
  - 指摘・実測・対応を「実測記録」に書く

---

## Task 9: 記録

**Files:**
- Modify: `docs/RULES.md`（Bug の行を 1 つ足す）
- Modify: `CLAUDE.md`（Bug の件数 2 か所）
- Modify: `docs/BACKLOG.md`（この作業の節・範囲外の行 3 つに矢印・完了状況）
- Modify: この計画（実測記録）

数字（本数・変異・ブラウザ）は Task 5〜8 の実測に合わせる。下の文は試作の実測で書いてあるので、違えば直す。日付は記録を書く日に合わせる。

- [ ] **Step 1: Bug の番号を最新の `13.x` で数え直す**（別の会話が先に RULES へ足していることがある）

```bash
git -C /Users/masanori/site/manage log --oneline -1 13.x && git -C /Users/masanori/site/manage show 13.x:docs/RULES.md | grep -c '^| [0-9]' && cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && grep -c '^| [0-9]' docs/RULES.md && git merge-base --is-ancestor 13.x HEAD && echo 'ahead-of-13.x'
```

期待（2026-09-29 時点）: `13.x` の最後のコミット・`69`・`69`・`ahead-of-13.x` → **この作業は Bug #70**。
⚠ `13.x` の数が 69 より大きい、または `ahead-of-13.x` が出ないときは、先に Task 0 Step 1 の手順で `13.x` を取り込み（全件テストを流し直す）、次の番号を使う。下の文の「70」をその番号に、`CLAUDE.md` の件数もそれに合わせて読み替える。⚠ 別の会話（`approval-phase2b` など）が先に入っていたら `docs/BACKLOG.md`・`CLAUDE.md` がぶつかることがある（両方の段落を残す）。

- [ ] **Step 2: `docs/RULES.md` に Bug #70 を足す**（Edit。表の最後の行（#69）の後ろ）

置き換える前:

```markdown
本番反映は `./deploy.sh`（DB 変更・新しい PHP クラス・依存の変更なし。CSS も変わらない） |

## Postal Code APIs
```

⚠ この「置き換える前」は #69 の行の末尾と、表のあとの見出し。ファイルに 1 か所だけか先に確かめる（`grep -c -F 'CSS も変わらない） |' docs/RULES.md` が 1）。

置き換えた後:

```markdown
本番反映は `./deploy.sh`（DB 変更・新しい PHP クラス・依存の変更なし。CSS も変わらない） |
| 70 | 契約系の 4 経路（テナントの契約・過去契約・賃貸マンションの部屋契約・駐車場契約）の CSV 取込で、**同じ CSV を上げ直すと契約が二重に入った**（2 件が 4 件に）。テナントの契約と賃貸マンションの契約中の行は「既にアクティブな契約が存在します」などの警告が出るだけで取り込まれ、テナントの過去契約と賃貸マンションの退去済みの行は**注意すら出なかった**。Bug #69 の鍵は「同じ確認画面の 2 回目」しか止めない（上げ直すと新しいプレビュー＝新しい鍵）ので、案 C として範囲外に残していた（2026-09-29 修正）| 4 経路の行の検査に、登録済みの契約との照合が無かった（物件・区画・顧客・部屋・駐車場・入居者のタブは「既に登録済みのためスキップ」を持つのに、契約のタブだけ持たなかった）。二重契約・期間の重なりの確かめは警告で、同じ契約かどうかは見ていなかった | **登録済みの同じ契約の行はスキップする**（同じ画面のほかのタブと同じ振る舞い）。見分けのキーは「どこ＋誰＋開始日」: テナントは区画・顧客（テナント名が空欄なら顧客の無い契約）・契約日、部屋契約は部屋・入居者・契約日・入居日、駐車場契約は駐車場・入居者・契約日・開始日（賃貸マンションの日付は空欄どうしだけが同じ）。賃料・状態・解約日・メモは見分けに使わない（改定・解約のあとの上げ直しでも二重にしない）。削除した契約とは突き合わせず、登録済みの契約は書き換えない。照合はコントローラごとの非公開メソッド（`findRegisteredContract()` を 2 経路で共用・`findRegisteredRoomContract()`・`findRegisteredParkingContract()`）で、`orderBy('id')->first()`（同じキーが 2 件以上なら最初に登録されたものを理由に出す）。行の検査は「日付 → CSV 内の重複 → 照合 → 金額」の順（駐車場契約は日付の検査を金額より前へ移した）＝登録済みの行は金額に誤りがあってもスキップ、日付に誤りがあればエラー。CSV 内の重複は「CSV内で行{N}と同じ契約が重複しています」のエラーで、最初の行は照合の前に覚える。警告は行の中に貯め、取り込むと決めた行の分だけ画面の一覧へ移す（スキップ・エラーの行に「既にアクティブな契約…」や「解約日が今日より未来です」を出さない。賃貸マンションの「警告: N 件」もエラーの行を数えなくなった）。確認画面の共通部品 2 つに、すべての行がスキップでエラーも無いときの灰色の「すべての行が登録済みです（スキップ N 件）。取り込む行はありません。」、契約の 4 タブの説明に 1 行ずつ。⚠ **日付は `whereDate` で比べる** — テストの SQLite は `date` キャストの値を `2026-04-01 00:00:00` の形で保存するので、素の `where` は 0 件になる（本番の MySQL の DATE 列では一致する＝テストでだけ壊れて見える逆向きの罠。変異 F13〜F15 で固定）。⚠ **過去契約で顧客がまだ無い（自動作成の予定の）行は照合しない** — null を渡すと「顧客の無い契約」と取り違え、同じ区画・契約日の顧客の無い契約でスキップされる。CSV 内の重複はまだ無い顧客の名前で比べる。⚠ **テスト用スキーマの `contracts.customer_id` を本番どおり空欄可にした**（顧客の無い契約をテストで作れなかった。Bug #60 の範囲外の件）。作成の migration の行を直した — `Schema::table(...)->nullable()->change()` は SQLite がテーブルを作り直し、状態・部署の CHECK が黙って消える（実測。カナリア 3 本で固定）。⚠ **全件分類の走査の正規表現を狭く書くと空振りする** — `[A-Z]\w*Contract::create(` は素の `Contract::create(` に当たらず、見つかった数が下限（5）を割って捕まえた（試作で実測）。走査はコメントを落としてから取込のコントローラの全メソッドを探す（`ImportControllerContractMatchScanTest`。ZEAL 会員の取込は理由つきで対象外）。⚠ **経路ごとに測る** — 変異の 1 回目で、過去契約で「最初の行を覚える位置」を照合の後ろへ動かす変異（D18）が緑だった（契約タブにしか「登録済みの契約を CSV に 2 行書く」テストが無かった）。テストを 2 タブのデータにして赤にした（Bug #44 の型）。回帰テスト: `TenantContractReimportTest`（56 本）・`MansionContractReimportTest`（68 本）・`ImportControllerContractMatchScanTest`（3 本）・`TenantUnitImportTest` のスキーマのカナリア 3 本。変異 100 通り＋カナリア（計画書 `docs/superpowers/plans/2026-09-29-contract-reimport.md` の実測記録）。本番反映は `./deploy.sh`（本番の DB 変更・新しい PHP クラス・依存の変更なし。CSS も変わらない。migration はテスト用で本番では流さない） |

## Postal Code APIs
```

- [ ] **Step 3: `CLAUDE.md` の件数**（Edit を 2 回）

```text
全 69 件の詳細バグカタログ   →   全 70 件の詳細バグカタログ
Bug #1–69                    →   Bug #1–70
```

- [ ] **Step 4: `docs/BACKLOG.md`**（Edit を 5 回）

1 回目（「ほかの取込の二重送信を止める」の範囲外の行）— 置き換える前:

```text
- 同じファイルを上げ直したときの二重（契約系の 4 経路。テナントの過去契約は注意すら出ない。案 C）。確認画面（POST の応答）を読み込み直してプレビューを送り直したときも同じ形（新しい鍵が出る）
```

置き換えた後:

```text
- 同じファイルを上げ直したときの二重（契約系の 4 経路。テナントの過去契約は注意すら出ない。案 C）。確認画面（POST の応答）を読み込み直してプレビューを送り直したときも同じ形（新しい鍵が出る）（→ 2026-09-29 に直した。登録済みの同じ契約の行をスキップする。下の「契約の上げ直しで二重にしない」の節）
```

2 回目（「区画の CSV 取込で削除済みの同名区画を扱う」の範囲外の行）— 置き換える前:

```text
- テスト用スキーマの `contracts.customer_id` / `rent_start_date` は NOT NULL だが、本番はどちらも NULL 可（反映前の読み取りで確認）＝テスト用スキーマの漂流。
```

置き換えた後:

```text
- テスト用スキーマの `contracts.customer_id` / `rent_start_date` は NOT NULL だが、本番はどちらも NULL 可（反映前の読み取りで確認）＝テスト用スキーマの漂流（→ 2026-09-29 に `customer_id` だけ空欄可にした。`rent_start_date` は今も NOT NULL。下の「契約の上げ直しで二重にしない」の節）。
```

3 回目（同じ節の範囲外の次の行）— 置き換える前:

```text
- 過去契約の取込の「解約日が今日より未来です」の警告は途中で積むので、その後の検査でエラーになる行にも出る（今回の注意は採用時にだけ積む形にした）
```

置き換えた後:

```text
- 過去契約の取込の「解約日が今日より未来です」の警告は途中で積むので、その後の検査でエラーになる行にも出る（今回の注意は採用時にだけ積む形にした）（→ 2026-09-29 に直した。契約系の 4 経路の警告を行ごとに貯め、取り込む行の分だけ出す。下の「契約の上げ直しで二重にしない」の節）
```

⚠ 2・3 回目の「置き換える前」は、それぞれファイルに 1 か所だけか先に確かめる（`grep -c -F` で 1）。

4 回目（この作業の節を足す。「ほかの取込の二重送信を止める」の節の後ろ・完了状況の前）— 置き換える前:

```markdown
⚠ 試算表（2026 年度）は本部 Sheet の URL が未設定なので、「本部 Sheet を取り込む」のボタンは出ない（URL があるときだけ出る作り）。シート取込を本番で使うのは、「Sheet URL 設定」で URL を入れてから。

⚠ `origin/13.x` への push はしていない。

---

## バックログ完了状況
```

置き換えた後:

```markdown
⚠ 試算表（2026 年度）は本部 Sheet の URL が未設定なので、「本部 Sheet を取り込む」のボタンは出ない（URL があるときだけ出る作り）。シート取込を本番で使うのは、「Sheet URL 設定」で URL を入れてから。

⚠ `origin/13.x` への push はしていない。

---

## ✅ 契約の上げ直しで二重にしない — 本番反映待ち

詳細仕様: @docs/superpowers/specs/2026-09-29-contract-reimport-design.md
実装計画（試作・変異テスト・ブラウザ確認の記録つき）: @docs/superpowers/plans/2026-09-29-contract-reimport.md

上の「ほかの取込の二重送信を止める」の範囲外に残した「同じファイルを上げ直したときの二重」（案 C）を直した（docs/RULES.md Bug #70）。
利用者の判断（2026-09-29）: 登録済みと同じ契約の行は自動でスキップ（質問 1 の案 1）・見分けは「どこ＋誰＋開始日」（案 A）・各経路の行の検査に照合を足す（案 1）。
**本番の DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（`composer dump-autoload` は要らない。CSS も変わらない。migration はテスト用で本番では流さない）。

| 区分 | 実装内容 |
|------|---------|
| Controller | テナント（契約・過去契約。照合は `findRegisteredContract()` を共用）・賃貸マンション（部屋契約・駐車場契約。`findRegisteredRoomContract()`・`findRegisteredParkingContract()`）に CSV 内の重複・照合・行ごとの警告。駐車場契約は日付の検査を金額の前へ |
| Blade | 確認画面の共通部品 2 つに灰色の文 ／ 取込の画面 2 つの契約の 4 タブに説明を 1 行ずつ |
| テスト用スキーマ | `contracts.customer_id` を本番どおり空欄可（作成の migration の行を直した）|
| テスト | 新規 `TenantContractReimportTest`（56 本）・`MansionContractReimportTest`（68 本）・`ImportControllerContractMatchScanTest`（3 本）／ `TenantUnitImportTest` にスキーマのカナリア 3 本 ／ `SubmitsImportPreview` に `assertPreviewSkipsEveryRow()`。2838 → **2968 tests / 21234 assertions green** |
| ルート / 本番の DB | **どちらも変更なし** |

### 直したこと

| 場面 | 直す前 | 直した後 |
|---|---|---|
| 同じ CSV を上げ直す | 契約が二重に入る（テナントの過去契約と賃貸マンションの退去済みの行は注意も出ない）| 全部の行が「…は既に登録済み（契約番号 または 既存契約 ID）のためスキップ」・灰色の「すべての行が登録済みです（スキップ N 件）。取り込む行はありません。」・確定のボタンは出ない |
| 行を足した CSV を上げ直す | 全部の行がもう一度入る | 新しい行だけが入る（「※ 既存データ（N件）はスキップされます」）|
| 賃料を改定・解約したあとで古い CSV を上げ直す | 二重に入る | スキップ（賃料・状態・解約日は見分けに使わない。登録済みの契約は書き換えない）|
| CSV の中に同じ契約が 2 行 | 両方入る | 2 行目が「CSV内で行N と同じ契約が重複しています」のエラー |
| スキップ・エラーの行の警告 | 画面の警告の一覧に残る | 取り込む行の警告だけ出す |

### 要点

- 照合は日付の検査のあと・金額の検査の前（登録済みの行は金額に誤りがあってもスキップ・日付に誤りがあればエラー）。確定でも同じ検査をやり直す（プレビューのあとで同じ契約が登録されていれば、確定でスキップされる）
- 日付は正規化したあとの `Y-m-d` を `whereDate` で比べる（`2026/4/1` と `2026-04-01` は同じ）。賃貸マンションの空欄の日付は空欄どうしだけが同じ
- 過去契約で顧客がまだ無い行は照合しない（自動作成の予定の顧客）。2 回目の上げ直しでは、1 回目が作った顧客で照合されてスキップされる
- 削除した契約とは突き合わせない（取り込み直せば入る）

### 範囲外（設計書 §6・§7 ＋ 試作で気づいたもの）

- 周辺ビルのテナント明細の上げ直しの二重（見分ける手がかりが弱く、作りも別）
- 登録済みの契約を CSV の値で書き換えること（スキップするだけ）
- 同じキーの契約がすでに 2 件以上あるとき（以前の二重送信の名残）の片づけ（照合は id のいちばん小さいものを理由に出すだけ。本番の数は反映の前に数える）
- 画面から契約を登録するときの重複の確かめ
- 2 つのタブで別々にプレビューした同じ CSV を**ほぼ同時に**確定すること（照合は読むだけで一意制約が無い。順に届けば 2 つ目はスキップ）
- 確定のときに全部の行がスキップになると「…インポート完了: 0件を登録しました」と成功の帯が出ること（Bug #69 §6 と同じ形）
- 削除した顧客の名前の行（契約タブは今までどおりエラー・過去契約タブは新しい顧客を作って取り込む）・まだ無い顧客の名前の表記ゆれ
- 過去契約の「顧客 N件を自動作成」の数え方（金額の誤りでエラーになった行の顧客まで数える）
- テスト用スキーマの `rent_start_date` の食い違い（今も NOT NULL）
- 行ごとに警告を貯める形を、ほかのタブへ広げること
- 完了の文にスキップの件数を足すこと
- 設計書 §7 の読み取り（物件・顧客・入居者の重複の文の閉じ括弧が半角・賃貸マンションの取込の画面の説明が実際と違う 3 か所・「※ 警告のある行（N件）」は行の数でなく警告の数・過去契約の期間の重なりの確かめはテストの SQLite では境目で本番と結果が違いうる）
- テナントの照合の `orderBy('id')` は SQLite では外しても結果が変わらない（変異 F25 は等価）。本番の MySQL での順序を決めるために残す

### 検証

- 全件テスト 2838 → **2968 tests / 21234 assertions green** ／ コンパイル済みビュー **283 本 / INVALID 0 件**
- 変異 100 通り＋カナリア（計画の Task 6。隔離した worktree 3 つ・全件で流す）: （未記入）
- ローカルの実ブラウザ（計画の Task 7。使い捨て SQLite ＋ `artisan serve` ＋ Playwright）: （未記入）
- 独立レビュー（計画の Task 8）: （未記入）

---

## バックログ完了状況
```

5 回目（「バックログ完了状況」の末尾の段落）— 置き換える前:

```text
その他の新規要件は別途追記する。
```

⚠ `docs/BACKLOG.md` に 1 か所だけか先に確かめる（`grep -c -F 'その他の新規要件は別途追記する。' docs/BACKLOG.md` が 1）。

置き換えた後（Task 10 のあとで「本番反映済み（`13.x` = …）」に直す）:

```text
**契約の上げ直しで二重にしない（2026-09-29）**は本番反映待ち（上の節）。

その他の新規要件は別途追記する。
```

- [ ] **Step 5: この計画の「実測記録」を埋める**（Task 0・5〜8 の結果。Task 10 の小節は Task 10 で埋める）。BACKLOG の節の「検証」の（未記入）3 か所も実測で埋める

- [ ] **Step 6: 書き残しが無いことを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && grep -n -e '（未記入）' docs/RULES.md CLAUDE.md docs/BACKLOG.md; sed -n '/^## 実測記録/,$p' docs/superpowers/plans/2026-09-29-contract-reimport.md | grep -c -e '（未記入）'
```

期待: 1 つ目の `grep` は何も出さない。2 つ目は Task 10 の小節の 1 か所だけ（`1`）。Task 10 の後は `0`。

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && git add docs/RULES.md CLAUDE.md docs/BACKLOG.md docs/superpowers/plans/2026-09-29-contract-reimport.md && git commit -m "$(cat <<'EOF'
docs: 契約の上げ直しで二重にしない改修の記録を残す

RULES に Bug #70（上げ直しの二重・whereDate・まだ無い顧客・テスト用スキーマ）を足し、
BACKLOG にこの作業の節と、範囲外の 3 行への矢印を足す。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 10: 本番反映（⚠ 承認をもらってから）

**Files:** なし（`13.x` の早送り・本番の読み取り（承認があれば）・本番への反映・読み取りの確認）

⚠ 実行の前に、利用者の承認を本文でもらう（①main repo で `13.x` を早送り ②（任意）反映の前に本番を読み取りだけで数える（Step 2）③`./deploy.sh` ④読み取りだけの確認（ssh での md5 とコンパイル済みビューの lint・ログイン済みの実 Chrome で 2 つの取込の画面を開く）。本番の DB 変更・ルート変更・新しい PHP クラス・依存の変更は無い＝`composer dump-autoload` は要らない。push はしない）。②は①③と別に承認をもらう（本番の読み取りも明示の承認が要る）。

- [ ] **Step 1: 早送り**（main repo で）

```bash
cd /Users/masanori/site/manage && git status --porcelain && git checkout 13.x && git merge-base --is-ancestor 13.x contract-reimport && git merge --ff-only contract-reimport && git log --oneline -1
```

⚠ `is-ancestor` が失敗したら（`13.x` が進んだ）止める。worktree で `13.x` をマージし（Task 0 の手順）、全件テスト（Task 5）を流してからやり直す。取り込むコミットに本番へまだ出ていない別の作業（`approval-phase2b` の DB の SQL など）が含まれるときは、それも一緒に出てよいか利用者に確かめる（`deploy.sh` は `13.x` の全体を送る）。

- [ ] **Step 2: （承認があれば）反映の前に、同じキーの契約が 2 件以上ある組を数える**（読み取りだけ。設計書 §8 の 2・§6。以前の二重送信の名残があれば利用者に伝える。消すかは利用者の判断）

手元に書いたスクリプトを本番の `/bin/sh` に流す（本番の既定の `php` は 7.4 なので 8.3 を明示する。本番にファイルを置かない。SQL の文字列は `?` のバインドで単一引用符を避ける）:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
$t = DB::select("SELECT COUNT(*) AS n FROM (SELECT unit_id, customer_id, contract_date FROM contracts WHERE deleted_at IS NULL GROUP BY unit_id, customer_id, contract_date HAVING COUNT(*) > 1) x");
$r = DB::select("SELECT COUNT(*) AS n FROM (SELECT room_id, tenant_id, contract_date, move_in_date FROM ms_contracts GROUP BY room_id, tenant_id, contract_date, move_in_date HAVING COUNT(*) > 1) x");
$p = DB::select("SELECT COUNT(*) AS n FROM (SELECT parking_id, tenant_id, contract_date, start_date FROM ms_parking_contracts GROUP BY parking_id, tenant_id, contract_date, start_date HAVING COUNT(*) > 1) x");
echo "tenant=" . $t[0]->n . " room=" . $r[0]->n . " parking=" . $p[0]->n . PHP_EOL;
'
SH
```

期待: `tenant=… room=… parking=…` の 1 行（組の数。`GROUP BY` は NULL どうしを同じ組にまとめる＝空欄どうしを同じとみなす照合と同じ）。0 でなければ数を利用者に伝える（反映は止めない。照合は id のいちばん小さいものを理由に出すだけで、名残の契約は消さない）。

- [ ] **Step 3: 本番へ送る vendor に dev の部品が無いこと**

```bash
cd /Users/masanori/site/manage && ls vendor/bin/phpunit 2>/dev/null; echo "phpunit-check-done"
```

期待: `ls` は何も出さない（dev の部品が無い）。出たら止める（`deploy.sh` が本番へ送ってしまう）。

- [ ] **Step 4: 反映**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

期待: exit 0・6 段すべて・3 つのキャッシュとも成功。送るアプリのファイルはコントローラ 2 本・ビュー 4 本（`admin/tenant-import/_preview`・`index`・`admin/mansion-import/_preview`・`index`）と migration 1 本（`database/` は rsync の対象だが本番では流さない）。CSS・JS の名前は変わらない（`app-DW1DvPK7.css`・`app-NiVQbl_Q.js`）＝旧バンドルの削除は 0 件。

- [ ] **Step 5: 読み取りだけの確認**

```bash
F="app/Http/Controllers/Admin/TenantImportController.php app/Http/Controllers/Admin/MansionImportController.php resources/views/admin/tenant-import/_preview.blade.php resources/views/admin/tenant-import/index.blade.php resources/views/admin/mansion-import/_preview.blade.php resources/views/admin/mansion-import/index.blade.php"; ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<SH
cd ~/apps/manage && md5 -q $F && n=0; bad=0; for f in storage/framework/views/*.php; do n=\$((n+1)); /usr/local/php/8.3/bin/php -l "\$f" >/dev/null 2>&1 || { bad=\$((bad+1)); echo "INVALID: \$f"; }; done; echo "views=\$n invalid=\$bad"
SH
cd /Users/masanori/site/manage && md5 -q $(echo "$F")
```

期待: 本番と手元の md5 が 6 本とも一致・`views=283 invalid=0`。⚠ zsh は引用なしの `$F` を単語分割しない（手元の `md5 -q` は `$(echo "$F")` で分けて渡す。ssh の側は本番の `/bin/sh` が分ける）。

- ログイン済みの実 Chrome（`claude-in-chrome`・読み取りだけ。ファイルは上げない・フォームは送らない）で、2 つの取込の画面を開き、どれも 200・契約の 4 タブの説明に足した 1 行が出る・コンソールのエラー 0 件を見る（⚠ URL は `/index.php/` を挟む）:
  - `https://www.mitsuwat.co.jp/system/manage/index.php/admin/tenant-import`（契約・過去契約タブ）
  - `…/index.php/admin/mansion-import`（部屋契約・駐車場契約タブ）
  - 確認画面（スキップの一覧・灰色の文）はプレビュー（ファイルを上げる）のあとにしか出ないので本番では見ない（テストとローカルの実ブラウザで確かめてある）。本番で実際に取り込むのは利用者が行う

- [ ] **Step 6: 記録**（worktree で BACKLOG の節の見出しを「本番反映済み」にし、本番反映の小節（日時・`13.x` のコミット・Step 2〜5 の結果）を足し、完了状況の段落を「本番反映済み（`13.x` = …）」に直し、この計画の「実測記録」の Task 10 を埋めてコミットする。そのあと main repo で `git merge --ff-only contract-reimport`（文書だけなので `deploy.sh` は要らない。`docs/` は rsync しない））

```bash
cd /Users/masanori/site/manage/.claude/worktrees/contract-reimport && git add docs/BACKLOG.md docs/superpowers/plans/2026-09-29-contract-reimport.md && git commit -m "$(cat <<'EOF'
docs: 契約の上げ直しで二重にしない改修を本番に出した記録を残す

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain && cd /Users/masanori/site/manage && git merge --ff-only contract-reimport && git log --oneline -1
```

⚠ push はしない（利用者の指示があったときだけ）。

---

## 範囲外（BACKLOG に書く。設計書 §6・§7 ＋ 試作で気づいたもの）

- 周辺ビルのテナント明細の上げ直しの二重（見分ける手がかりが弱く、作りも別。`AreaBuildingImportController::importTenants()` の注記に既知として書いてある）
- 登録済みの契約を CSV の値で書き換えること（スキップするだけ）
- 同じキーの契約がすでに 2 件以上あるとき（以前の二重送信の名残）の片づけ（照合は id のいちばん小さいものを理由に出すだけで、消さない。本番の数は Task 10 Step 2 で数える）
- 画面から契約を登録するときの重複の確かめ
- 2 つのタブで別々にプレビューした同じ CSV を**ほぼ同時に**確定すること（照合は読むだけで一意制約が無いので、両方が照合を通りうる。順に届けば 2 つ目はスキップ）
- 確定のときに全部の行がスキップになると、「…インポート完了: 0件を登録しました」と成功の帯が出ること（プレビューのあとに、別の画面で同じ契約が登録された場合。Bug #69 §6 の「0件を登録しました」と同じ形）
- 削除した顧客の名前の行: 契約タブは今までどおりエラー（「顧客「…」がシステムに登録されていません」）。過去契約タブは今までどおり新しい顧客を作って取り込む（削除した顧客の契約とは突き合わせない）
- まだ無い顧客の名前の表記ゆれ（大文字小文字・全角半角）は、今までどおり別の名前として扱う（CSV 内の重複も名前で比べる）
- 過去契約の「顧客 N件を自動作成」の数え方（金額の誤りでエラーになった行の顧客まで数える。設計書 §7）
- テスト用スキーマの `rent_start_date` の食い違い（テストは NOT NULL・本番は空欄可）
- 行ごとに警告を貯める形を、ほかのタブへ広げること
- 完了の文にスキップの件数を足すこと（物件タブとそろえて出さない）
- 設計書 §7 の読み取り（直さない）: 物件・顧客・入居者の「…がCSV内で重複しています（行N)」の閉じ括弧が半角 ／ 賃貸マンションの取込の画面の説明が実際と違う 3 か所 ／ 「※ 警告のある行（N件）」は行の数でなく警告の数 ／ 過去契約の期間の重なりの確かめ（`where('contract_date', '<=', $endDate)`）はテストの SQLite では日付が `Y-m-d H:i:s` の形で入るので、境目で本番と結果が違いうる
- テナントの照合の `orderBy('id')` は SQLite では外しても結果が変わらない（変異 F25 は等価。本番の MySQL でも索引の順になることが多いが保証は無いので残す）

## 完了の条件

- 全件テストが緑（`OK (2968 tests, 21234 assertions)` 前後。違えば理由を記録）・コンパイル済みビュー `views=283 invalid=0`
- 変異 100 通り＋カナリアがすべて期待どおり（緑は F25（等価）と S04（対照）だけ。違ったものは理由を調べて対応し、記録した）
- ローカルの実ブラウザの 9 項目がすべて期待どおり
- 独立レビューの指摘に、実測のうえで対応した
- RULES・CLAUDE.md・BACKLOG・この計画に記録した
- （承認のあと）本番に反映し、読み取りで確かめた

---

## 実測記録

### 試作の先測り（2026-09-29。この計画を書くときに測った）

写し: `git worktree add --detach … 046c69c9` に vendor を `cp -Rc`。計画のコードを入れて測った（変異は、写しを `git init` した独立のリポジトリ 3 つで流した）。

- 各 Task の「直す前に落ちる」「直すと通る」: 写しで、その Task のアプリのファイルだけを `046c69c9` に戻して流した値（各 Task の Step の「期待」）
- 全件 `OK (2968 tests, 21234 assertions)`・コンパイル済みビュー `views=283 invalid=0`
- テスト用スキーマの 2 候補（上の「試作で確かめたこと」）・SQLite の日付の保存形式（`2026-04-01 00:00:00`。素の `where` は 0 件・`whereDate` は 1 件）
- 構造のテストの正規表現: 最初の形は見つかった数が 3 で、下限 5 が空振りを捕まえた
- CSS: 写しで `vite build` → `app-DW1DvPK7.css`・`app-NiVQbl_Q.js`（本番と同じ名前）
- 変異（関係するテスト 20 本＝実行役の `NARROW` に絞って、`git init` した写し 3 つで流した）:
  - 1 回目（2026-09-29・99 通り＝カナリアを除く）: 緑は 3 通り（D18・F25・S04）。
    **D18 はテストの穴**だった — 過去契約で「最初の行を覚える位置」を照合の後ろへ動かしても緑（登録済みの契約を CSV に 2 行書くテストが契約タブにしか無かった）。`test_a_registered_contract_written_twice_in_the_csv_is_skipped_once_and_then_an_error` を契約・過去契約の 2 つのデータにした（テナントのテストは 55 → 56 本）。
    **S04 は当て方が意味をなしていなかった** — コメントをクラスの本体（メソッドの外）に置いていたので、コメントの除去を外しても走査に入らない。コメントを `showForm()` の中へ移して対照（緑が正しい）にし、コメントの除去も外す S06 を足した。
    **F25 は等価**（SQLite では `orderBy` が無くても id の順に返る）
  - 2 回目（テストを直したあと・101 通り＝カナリア＋100 通り）: カナリアは 3 つとも同じ 13 本が赤。緑は 2 通り（F25・S04）で、どれも理由がある（Task 6 の表）

### Task 0: 前提の確認

（未記入）

### Task 1〜4: 実装

（未記入）

### Task 5: 全件テストと lint

（未記入）

### Task 6: 変異テスト

（未記入）

### Task 7: ローカルの実ブラウザ

（未記入）

### Task 8: 独立レビュー

（未記入）

### Task 10: 本番反映

（未記入）
