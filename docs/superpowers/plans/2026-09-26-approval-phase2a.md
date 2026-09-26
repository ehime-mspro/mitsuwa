# 決裁申請 段階2（2a: 申請して社長の決裁まで一通り回る）実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 決裁の本体のうち「申請を出して、部門長・審査・社長を回り、決裁No が付くまで」を作り、使い始める日まで申請を回す画面を誰にも見せない形で本番へ出す。

**Architecture:** 状態の移り変わりは `App\Support\Approval\Workflow` の 1 か所に、見られる範囲は `RequestVisibility` の 1 か所に、「この人は今この操作をできるか」は `RequestPermissions` の 1 か所に集め、画面（コントローラ・ビュー）はこれらを呼ぶだけにする。表は本番用の SQL とテスト用の migration を対で持つ（段階1 と同じ）。使い始める前は `approval_settings.launched_at` が空で、門番 `approval.launched` が申請を回す画面を止める。

**Tech Stack:** Laravel 12 / PHP 8.3（本番）・8.5（手元）/ MySQL 8（本番）・SQLite（テスト）/ Blade + Alpine.js 3 + Tailwind v4 / PHPUnit

設計書: @docs/superpowers/specs/2026-09-25-approval-phase2-design.md（この計画は §1.2・§5.1〜§5.12・§6・§7・§10 の **2a** の部分。2b は別の計画にする）
要件定義書: @docs/決裁申請_要件定義書_v1.md（v1.9）
前段: 段階1 の設計書 @docs/superpowers/specs/2026-09-16-approval-phase1-design.md・計画 @docs/superpowers/plans/2026-09-16-approval-phase1.md

---

## 0. 計画を書く段階で決めたこと（設計書 §9 の宿題のうち 2a の分）

### 0.1 表の型・索引名・外部キー

- 段階1 の SQL（`database/sql/2026-09-16-approval-phase1.sql`）と同じ流儀にする: 一意は `uq_`・索引は `idx_`・外部キーは `fk_` で始める名前・`ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`・時刻は `TIMESTAMP NULL`
- 既存の 2 つの表（`approval_departments`・`approval_settings`）は段階1 の SQL で作ったものなので、定義は SQL から分かる。本番の読み取りは反映の直前（Task 21）に行い、食い違いがあれば止める
- 状態などの値（`status`・`decision`・`kind`・`result`）は **VARCHAR** にし、PHP の enum で型を付ける（設計書 §5.3）
- 全文は Task 1 の SQL と migration

### 0.2 状態・段階・判断の値

| 列 | 値 |
|---|---|
| `approval_requests.status` | `draft` 下書き / `head_review` 部門長確認中 / `review` 審査中 / `president` 社長決裁待ち / `returned` 差戻し中 / `condition` 条件確認待ち / `approved` 決裁済み / `rejected` 否決 / `withdrawn` 取り下げ |
| `approval_requests.decision` | `approve` 可 / `conditional` 条可 / `reject` 否（社長が判断したときだけ） |
| `approval_steps.kind` | `head` 部門長 / `review` 審査 / `president` 社長 |
| `approval_steps.status` | `pending` まだ届いていない / `waiting` 待ち / `done` 済み / `skipped` 省略 / `cancelled` 打ち切り |
| `approval_steps.result` | 部門長: `approve` 承認・`return` 差戻し ／ 審査: `ok` 可・`hold` 保留・`ng` 否 ／ 社長: `approve` 可・`conditional` 条可・`return` 差戻し・`reject` 否 |

### 0.3 同時操作の見張り（要件 4.2・設計書 §5.8）

- 申請の行に `lock_version` を持ち、状態を進めるときは **`WHERE id = ? AND lock_version = ?` を条件にした 1 回の UPDATE** で `lock_version` を 1 増やす。当たった行が 0 なら、ほかの人（または別のタブの自分）が先に操作したので「すでに処理されています」
- 画面の操作のフォームは、描いたときの `lock_version` を hidden で送る。**古い画面から押した操作も同じ仕組みで断る**（見ていた中身と違うものに判断を付けさせない）
- SQLite（テスト）でも MySQL（本番）でも同じに効く（`lockForUpdate` に頼らない）

### 0.4 採番のロック（要件 6・設計書 §5.9）

- `approval_number_sequences` の（部門・年度）の行を `insertOrIgnore` で用意してから `lockForUpdate()` で読み、`next_number` を使って 1 進める。行が無いときに 2 人が同時に作っても一意の索引で 1 行になる（`insertOrIgnore` は MySQL の `INSERT IGNORE`・SQLite の `INSERT OR IGNORE`）
- 採番は社長の判断と同じトランザクションの中で行う（判断が断られたら番号も戻る）

### 0.5 添付の消し方とダウンロードの記録（設計書 §5.7 を細かくした）

設計書 §5.7 を書いたあと、**一度でも開いた添付の行を消すと、ダウンロードの記録（外部キー）とぶつかって 500 になる**ことに気づいた。次のように決める。

| 場面 | 添付を消したとき | 開いたときの記録 |
|---|---|---|
| 一度も提出していない下書き（`round = 0`） | 行もファイルも消す | **記録しない**（本人しか見られず、持ち出しに当たらない） |
| 一度でも提出した申請（`round >= 1`）の差戻し中 | 行もファイルも残し、`removed_round`・`removed_at` を入れて一覧から外すだけ | 記録する（14.2） |

- 下書きの削除（4.5）は `round = 0` だけなので、その申請には記録（履歴・控え・ダウンロード）が 1 行も無い。申請の行を消すと添付の行も消え（外部キーの `CASCADE`）、ファイルはフォルダごと消す
- 履歴（`approval_histories`）は**提出から**付ける（下書きの作成・保存は記録しない）。下書きを消せるのはこのため

### 0.6 使い始める前の応答（設計書 §5.2）

- 準備中に申請を回す画面を開くと、画面を開く GET・HEAD（Ajax でないもの）は `approvals.home` へ転送する（ホームが「準備中」を出すので、帯の言葉は足さない）。それ以外（POST・Ajax・JSON）は 404
- 門番は `SubstituteBindings` より前に並べる（存在しない ID でも同じ応答）

### 0.7 画面の配置

- 画面の見本は作らない（設計の 4 節で中身を承認済み）。仕上げ（Task 19）で手元のブラウザ（スマホの幅を含む）の画面の写真を撮り、利用者に見せる（設計書 D1）
- 型は既存の画面に合わせる: 一覧は部門の管理と同じ表＋スマホではカード、管理の追加と編集はモーダル、判断は確認のモーダル


### 0.8 設計書から変えた細部（計画を書いていて分かったこと）

| # | 設計書 | この計画 | 理由 |
|---|---|---|---|
| 1 | §5.3 `approval_requests.completed_at` | **`finished_at`** | 走査テスト `StoredTimestampDisplayScanTest` が `started_at`・`completed_at`・`executed_at` を「datetime にキャストしてはいけない名前」として予約している（既存の `Repair` などが date キャストで使う）。名前だけの違いで意味は同じ（決裁が完了した日時） |
| 2 | §5.1 `approvals.requests.submit`（提出の別ルート） | **作らない**。保存のフォームに `intent=submit` を付けて送る（`store` / `update` が保存のあと提出する） | 別のルートにすると、入力したばかりの中身を保存せずに提出して入力を失う・古い中身で提出する経路ができる。提出できなかったときも中身は下書きとして残る |
| 3 | §5.7 添付の消し方 | 計画 §0.5 の表のとおり | 記録の外部キーとぶつからないようにする |

### 0.9 走査テスト（全件分類）に登録するもの

2026-09-26 に走査テスト 11 本を読んで決めた。**登録を忘れると全件テストが赤になる**（それが狙い）。**各 Task が足したものは、その Task の中で登録する**（途中の各段でも全件が緑のまま。設計書 §10）。Task 17 は門番の数の下限を実数に上げる。

| 走査テスト | 登録するもの |
|---|---|
| `tests/Feature/ClockReadScanTest.php` の `ALLOWED` | `now()` を使う PHP ファイルと、その**件数**（理由つき）。`JapanTime::today()` は数えられない。**ビューには時計を読むものを 1 つも書かない**（JS の関数名 `today(` も数える） |
| `tests/Feature/AjaxErrorFeedbackTest.php` の `VIEWS_NULL_RETURN` | `fetch(` を書くビュー（申請書の画面）。`if (!res.ok` と `if (!data` を同じ数だけ書き、`!ok` の塊に `errorMessage =` を入れる |
| `tests/Feature/Approval/ApprovalAdminGateTest.php` の `OPEN_TO_EVERY_USER` | 管理の画面**でない** `approvals.` のルートすべて（理由つき） |
| 同じテストの `$existingValues` と `tableCounts()` | 新しいルートのパラメータ `approvalType`（実在する行を作る）・新しい表 |
| `lang/ja/validation.php` の `attributes` | 新しい入力の和名（`JapaneseValidationMessagesTest`。既存のキー `name`・`status`・`reason`・`type`・`file` などは**足さない**。重ねて書くと全画面の和名が変わる） |

- 期の式（`month >= N ? year : year - 1`）の走査 `JapanBusinessDayTest` は、**開始月が変数**の式を拾わない（`ApprovalFiscalYear` は会社ごとの開始月を受け取るので見えない）。登録は要らないが守られてもいないので、`ApprovalFiscalYearTest` で境目を固定する
- `StoredTimestampDisplayScanTest`: 保存した日時を画面に出すときは必ず `JapanTime::format()`（`->format()` や `->year` を直接呼ばない）。`MIN_FORMAT_CALLS = 26` は下限なので、増える分には構わない
- `ApprovalSidebarTest`: 基幹の一般の利用者の画面に「決裁」の文字を出すと 3 本が落ちる（2a では出さない。基幹のメニューの「決裁」は 2b）
- `AjaxFetchSessionGuardTest` は URL に `/api/` を含む fetch しか見ないので、申請書の画面の候補の検索（`approvals.numbers.search`）を拾わない。`X-Requested-With` を付けていること（Bug #35）は `RequestFormTest` が見る
- `ValidationErrorFeedbackTest`: `@csrf` を持つ画面（申請書・詳細・申請種類の管理）は `$errors` を出す手段を持つ（詳細は判断のコメントの長さなどで断られる）
- 登録する時計の読み取り（`ClockReadScanTest`）は 3 本: `ApprovalNumber.php` 1 件（Task 5）・`Workflow.php` 7 件（Task 7）・`RequestAttachmentController.php` 1 件（Task 14）
- `MobileLayoutTest`: `<table>` は必ず横スクロールできる親（既存の `scroll-hint`）の中に置く

### 0.10 テストの土台（`tests/Concerns/BuildsApprovalFixtures.php`）

段階1 のテストは利用者や部門を各クラスの `private` メソッドで作っている。段階2 のテストは同じ組み立て（会社・部門長のいる申請部門・審査担当者のいる審査部門・社長・種類・申請者）を何度も使うので、**新しい trait 1 本にまとめる**（段階1 のテストは書き換えない。範囲外）。約束は段階1 と同じ:
- 利用者は必ず `'must_change_password' => false`（既定の true だと `ForcePasswordChange` が転送する）
- `role` は明示する（ファクトリは入れない）。決裁の印（`ApprovalMember`）を付けたら `fresh()` を返す
- 画面の文言を見るテストでは `assertSessionHas*()` を呼ばない（呼ぶとフラッシュが消費され、次に描いた画面から帯が消える。Bug #49）

### 0.11 画面の帯と文言

- レイアウトが出す帯は `success`（緑）と `error`（赤）の 2 つだけ（`warning` はビューごと）。段階2 の画面は**この 2 つだけを使う**（「すでに処理されています」も `error`）
- 提出できない理由は、編集画面の上に**すべて**並べる（`withErrors(['submit' => $理由の配列])`）。ビューへ `errors` という名前のデータを渡さない（Bug #53）

---

## 1. 触るファイル

### 新規

| ファイル | 役目 |
|---|---|
| `database/sql/2026-09-25-approval-phase2a.sql` | 本番の DDL |
| `database/migrations/2026_09_25_000001_create_approval_phase2_tables.php` | テスト用の鏡 |
| `app/Enums/ApprovalStatus.php`・`ApprovalDecision.php`・`ApprovalStepKind.php`・`ApprovalStepStatus.php`・`ApprovalStepResult.php` | 状態・判断・段階の値 |
| `app/Models/Concerns/AppendOnly.php` | 追記のみの守り |
| `app/Models/ApprovalType.php`・`ApprovalRequest.php`・`ApprovalStep.php`・`ApprovalRevision.php`・`ApprovalHistory.php`・`ApprovalAttachment.php`・`ApprovalDownloadLog.php`・`ApprovalNumberSequence.php` | モデル |
| `app/Http/Middleware/EnsureApprovalLaunched.php` | 使い始める前の門番 |
| `app/Support/Approval/ApprovalFiscalYear.php`・`ApprovalNumber.php` | 期と和暦・採番 |
| `app/Support/Approval/BodyTemplate.php`・`RelatedNumbers.php`・`SubmitChecker.php` | 本文の見出し・関連する決裁No・提出の条件 |
| `app/Support/Approval/RequestVisibility.php`・`RequestPermissions.php` | 見られる範囲・操作の権限 |
| `app/Support/Approval/Workflow.php`・`WorkflowConflict.php`・`WorkflowRefused.php`・`HistoryRecorder.php`・`RequestSnapshot.php` | 状態の移り変わり・記録・控え |
| `app/Support/Approval/PendingWork.php`・`CurrentHandler.php` | 自分の対応待ち・いま誰の番か |
| `app/Http/Controllers/Approval/TypeController.php` | 申請種類の管理（⑨） |
| `app/Http/Controllers/Approval/RequestController.php` | 自分の申請一覧（④）・作成と編集（②）・詳細（③） |
| `app/Http/Controllers/Approval/RequestActionController.php` | 判断・条件確認・取り下げ |
| `app/Http/Controllers/Approval/RequestAttachmentController.php` | 添付 |
| `app/Http/Controllers/Approval/RelatedNumberController.php` | 関連する決裁No の候補 |
| `resources/views/approvals/admin/types.blade.php` | ⑨ |
| `resources/views/approvals/requests/index.blade.php`・`form.blade.php`・`show.blade.php` | ④・②・③ |
| `resources/views/approvals/requests/_steps.blade.php`・`_actions.blade.php` | ③ の回る順番・操作 |
| `resources/views/approvals/home-launched.blade.php` | 使い始めたあとのホーム（①） |
| `tests/Concerns/BuildsApprovalFixtures.php` | テストの土台 |
| `tests/Feature/Approval/Phase2/*Test.php` | 段階2 のテスト（各 Task） |
| `tests/Unit/Approval/*Test.php` | 部品の単体テスト |

### 変更

| ファイル | 変えること |
|---|---|
| `app/Models/ApprovalDepartment.php`・`ApprovalSetting.php`・`User.php` | 部門長・審査担当者・使い始めた日時・12.6 の理由 |
| `app/Http/Controllers/Approval/OrganizationController.php`・`resources/views/approvals/admin/organization.blade.php` | 部門長・審査担当者・開始番号・歯止め |
| `app/Http/Controllers/Approval/UserController.php`・`app/Http/Controllers/Admin/UserController.php` | 12.6 の歯止め |
| `app/Http/Controllers/Approval/HomeController.php`・`resources/views/approvals/home.blade.php` | 使い始めたあとのホーム・準備中のホームに申請種類の管理のリンク |
| `routes/approval.php`・`bootstrap/app.php` | ルート・門番の別名と並び・送信の上限を超えたときの日本語の応答 |
| `resources/views/layouts/partials/sidebar_approval.blade.php`・`sidebar.blade.php` | サイドバーの項目 |
| `lang/ja/validation.php` | 和名 |
| `tests/Feature/ClockReadScanTest.php`・`AjaxErrorFeedbackTest.php`・`Approval/ApprovalAdminGateTest.php`・`Approval/ApprovalSidebarTest.php` | 走査テストの分類・サイドバーのテスト |
| `CLAUDE.md`・`docs/ARCHITECTURE.md`・`docs/BACKLOG.md`・`routes/web.php`（見出しの本数） | ドキュメント |

## 2. 作業の順番

途中の各段でも、既存のテストを含めて全部が通る状態を保つ（設計書 §10）。⚠ 設計書 §10 の案から並びを変えた: 画面が `Workflow` を呼ぶので **`Workflow` を画面より前**（Task 7）に置き、申請書の画面が最初から候補の検索を使えるよう **候補の検索を申請書の前**（Task 12）に置いた。

| Task | 中身 | 設計書 |
|---|---|---|
| 0 | 作業場所の準備（worktree・vendor・全件が緑） | — |
| 1〜3 | 表（本番の SQL とテスト用の migration）・状態の enum・モデル・テストの土台 | §5.3 |
| 4 | 使い始める前の門番（`approval.launched`） | §5.2 |
| 5 | 期と和暦・決裁No の採番 | §5.9 |
| 6 | 5W2H の見出し・関連する決裁No の形・提出の条件 | §5.6・§5.8 |
| 7 | 状態の移り変わり（`Workflow`）・権限・記録・控え | §5.8・§5.11 |
| 8 | 見られる範囲（`RequestVisibility`） | §5.10 |
| 9〜10 | 部門の管理（部門長・審査担当者・開始番号・歯止め）・12.6 の歯止め | §5.4 |
| 11 | 申請種類の管理（⑨） | §5.5 |
| 12 | 関連する決裁No の候補 | §5.6 |
| 13 | 申請書（②）と詳細の中身（③） | §5.6・§5.12 |
| 14 | 添付 | §5.7 |
| 15 | 詳細の回る順番・記録・判断の操作 | §5.8・§5.12 |
| 16 | ホーム（①）・自分の申請一覧（④） | §5.12 |
| 17 | サイドバー・門番の数の下限 | §5.2・§5.12 |
| 18 | 全件テストと変異テスト | §6 |
| 19 | 手元のブラウザでの確認・利用者に見せる画面の写真 | §6・D1 |
| 20 | ドキュメント | §7 の 6 |
| 21 | 本番反映（利用者の了承を取ってから） | §7 |

---

## Task 0: 作業場所の準備

**作業場所**: worktree `/Users/masanori/site/manage/.claude/worktrees/approval-phase2a`（ブランチ `approval-phase2a`）。**main repo では作業もテストもしない**（main repo の vendor は `--no-dev` で phpunit が無い。dev 依存を入れると `./deploy.sh` が本番へ送る）。

- [ ] **Step 1: 並行の作業を確かめる**（ほかの会話が同じ課題を進めていないか）

```bash
cd /Users/masanori/site/manage && git status --short --branch && git log --oneline -5 && git worktree list && git for-each-ref --sort=-committerdate --format='%(refname:short) %(committerdate:short) %(subject)' refs/heads | head
```

Expected: `approval-phase2a` という worktree・ブランチがまだ無い。`docs/BACKLOG.md` の段階2 の節が「社内決裁申請」の会話の担当になっている。あれば中身を読み、消さずに利用者へ報告して止まる。

- [ ] **Step 2: worktree を作る**

```bash
cd /Users/masanori/site/manage && git check-ignore -q .claude/worktrees && git worktree add .claude/worktrees/approval-phase2a -b approval-phase2a 13.x && git -C .claude/worktrees/approval-phase2a log --oneline -1
```

- [ ] **Step 3: vendor を用意する**（dev 依存あり・実体のコピー）

⚠ **symlink にしない**（autoload が symlink の先を読み、測定が無音で空振りする。Bug #50）。既存の worktree に dev 依存入りの vendor があればそれを実体でコピーし、無ければ worktree の cwd で `composer install` する。**main repo では絶対に `composer install` しない。**

```bash
WT=/Users/masanori/site/manage/.claude/worktrees/approval-phase2a
SRC=$(ls -d /Users/masanori/site/manage/.claude/worktrees/*/vendor/bin/phpunit 2>/dev/null | grep -v approval-phase2a | head -1)
if [ -n "$SRC" ]; then cp -Rc "$(dirname "$(dirname "$(dirname "$SRC")")")/vendor" "$WT/vendor"; else cd "$WT" && composer install; fi
test -x "$WT/vendor/bin/phpunit" && ! test -L "$WT/vendor" && echo "vendor OK"
```

Expected: `vendor OK`

- [ ] **Step 4: 全件テストが通る状態から始める**

**テストの流し方**（以下すべての Task で同じ。`APP_KEY` は 32 バイトの本物の鍵を渡す。`base64:x` のような偽の鍵は暗号を使う経路が落ちるので使わない。worktree に `.env` を作らない）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -5
```

Expected: `OK (2169 tests, …)` 前後（2026-09-25 時点の件数。増えていてもよい）。赤があれば段階2 の作業の前に利用者へ報告して止まる。

⚠ 以下の Task の「テストを流す」は、すべてこの形で `--filter <テスト名>` を付けたもの。`cd` はコマンドごとに書く（ターンをまたぐと cwd が main repo へ戻る）。git は `git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a …` で呼ぶ。

⚠ コミットは Conventional Commits・日本語の件名（72 文字以内・句点なし）・1 コミット 1 関心事。本文の最後に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`。`--no-verify`・`--amend`・`git reset` でのやり直し・`git stash` は使わない。コミットのたびに `git status --porcelain` が空であることを確かめる。


---

## Task 1: 表（本番の SQL とテスト用の migration）

**Files:**
- Create: `database/sql/2026-09-25-approval-phase2a.sql`
- Create: `database/migrations/2026_09_25_000001_create_approval_phase2_tables.php`
- Test: `tests/Feature/Approval/Phase2/Phase2TablesTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/Phase2TablesTest.php`

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 段階2（2a）の表（設計書 §5.3）。
 *
 * ⚠ 本番は SQL を手で流し、テストは migration で作る。**両方の列が食い違わないこと**をここで固定する
 *   （段階1 は注釈だけで対にしていた。2a は表が 9 つあるので機械で見る）。
 */
class Phase2TablesTest extends TestCase
{
    use RefreshDatabase;

    private const SQL = 'database/sql/2026-09-25-approval-phase2a.sql';

    /** 段階1 から在る表。2a の SQL は列を足すだけなので「足した列」だけを比べる */
    private const ALTERED = ['approval_departments', 'approval_settings'];

    private const TYPES = 'BIGINT|INT|SMALLINT|TINYINT|VARCHAR|TEXT|JSON|TIMESTAMP';

    /** @return array<string, list<string>> 表 => 列 */
    private function columnsInSql(): array
    {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(base_path(self::SQL)));
        $tables = [];

        preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\n\) ENGINE/s', $sql, $creates, PREG_SET_ORDER);
        foreach ($creates as [, $table, $body]) {
            // 1 行に 2 列（`created_at` … , `updated_at` …）書く行があるので、行頭ではなく「列名 + 型」で拾う
            preg_match_all('/`(\w+)` (?:' . self::TYPES . ')\b/', $body, $columns);
            $tables[$table] = $columns[1];
        }

        preg_match_all('/ALTER TABLE `(\w+)`(.*?);/s', $sql, $alters, PREG_SET_ORDER);
        foreach ($alters as [, $table, $body]) {
            preg_match_all('/ADD COLUMN `(\w+)`/', $body, $columns);
            $tables[$table] = array_merge($tables[$table] ?? [], $columns[1]);
        }

        return $tables;
    }

    public function test_the_sql_and_the_migration_declare_the_same_columns(): void
    {
        $sql = $this->columnsInSql();

        // 空振りで緑にならないように（正規表現が 1 つも拾わなければ落とす）
        $this->assertCount(11, $sql, 'SQL から読めた表の数が違う（CREATE 9・ALTER 2）');

        foreach ($sql as $table => $columns) {
            $this->assertNotEmpty($columns, "{$table} の列を SQL から 1 つも読めていない");
            $migrated = Schema::getColumnListing($table);

            if (in_array($table, self::ALTERED, true)) {
                $this->assertSame([], array_values(array_diff($columns, $migrated)), "{$table} に足した列が migration に無い");

                continue;
            }

            sort($columns);
            sort($migrated);
            $this->assertSame($columns, $migrated, "{$table} の列が SQL と migration で違う");
        }
    }

    /** 追記のみの表と、上書きしない添付は updated_at を持たない（持つと「書き換えられる想定」に見える） */
    public function test_append_only_tables_have_no_updated_at(): void
    {
        foreach (['approval_revisions', 'approval_histories', 'approval_download_logs', 'approval_attachments'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'updated_at'), "{$table} に updated_at がある");
        }
    }

    /** 完了日時は `finished_at`（`completed_at` は走査テストが予約している名前。計画 §0.8） */
    public function test_the_request_uses_finished_at_not_completed_at(): void
    {
        $this->assertTrue(Schema::hasColumn('approval_requests', 'finished_at'));
        $this->assertFalse(Schema::hasColumn('approval_requests', 'completed_at'));
    }
}
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter Phase2TablesTest
```

Expected: FAIL（SQL のファイルが無い・表が無い）

- [ ] **Step 3: 本番の SQL を書く**

`database/sql/2026-09-25-approval-phase2a.sql`

```sql
-- 決裁申請 段階2（2a）— 2026-09-25
--
-- 設計書: docs/superpowers/specs/2026-09-25-approval-phase2-design.md §5.3
--
-- ⚠ database/migrations/2026_09_25_000001_create_approval_phase2_tables.php と
--   対で維持すること（あちらは SQLite のテストのための鏡）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** 新しいコードは approval_departments.head_user_id と
--   approval_settings.launched_at を読むので、コードを先に送ると部門の管理と決裁のホームが
--   Unknown column で 500 になる。
--
-- ⚠ 索引名・外部キー名は段階1（2026-09-16-approval-phase1.sql）と同じ流儀
--   （一意は uq_・索引は idx_・外部キーは fk_）。照合順序は utf8mb4_unicode_ci。
--
-- ⚠ 状態などの値は ENUM にせず VARCHAR（設計書 §5.3。値を足すたびの ALTER と、
--   SQLite で enum を変えると CHECK が消える落とし穴（Bug #60）を避ける）。
--
-- 適用: 段階1 と同じく php artisan tinker --execute で DB::statement() に **1 文ずつ**流す。
--   先頭で「approval_types がすでにあれば 1 文も流さずに止まる」確認をする（計画 Task 21）。

-- 1. 既存の表に列を足す
ALTER TABLE `approval_departments`
  ADD COLUMN `head_user_id` BIGINT UNSIGNED NULL COMMENT '部門長' AFTER `code`,
  ADD KEY `idx_approval_departments_head` (`head_user_id`),
  ADD CONSTRAINT `fk_approval_departments_head` FOREIGN KEY (`head_user_id`) REFERENCES `users` (`id`);

ALTER TABLE `approval_settings`
  ADD COLUMN `launched_at` TIMESTAMP NULL COMMENT '使い始めた日時（NULL のあいだは準備中）' AFTER `president_user_id`;

-- 2. 新しい表
CREATE TABLE `approval_reviewers` (
  `department_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`department_id`, `user_id`),
  KEY `idx_approval_reviewers_user` (`user_id`),
  CONSTRAINT `fk_approval_reviewers_dept` FOREIGN KEY (`department_id`) REFERENCES `approval_departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_approval_reviewers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_types` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `headings` TEXT NOT NULL COMMENT '5W2H の見出し（本文の初期値）',
  `review_department_id` BIGINT UNSIGNED NOT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_types_name` (`name`),
  KEY `idx_approval_types_review_dept` (`review_department_id`),
  CONSTRAINT `fk_approval_types_review_dept` FOREIGN KEY (`review_department_id`) REFERENCES `approval_departments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '申請者',
  `department_id` BIGINT UNSIGNED NULL COMMENT '申請部門（下書きでは空でもよい）',
  `type_id` BIGINT UNSIGNED NULL COMMENT '申請の種類（下書きでは空でもよい）',
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
  `decision` VARCHAR(20) NULL COMMENT '社長の判断（approve / conditional / reject）',
  `subject` VARCHAR(100) NULL,
  `amount` BIGINT UNSIGNED NULL COMMENT '円・税抜',
  `schedule` VARCHAR(50) NULL COMMENT '実施時期',
  `body` TEXT NULL COMMENT '重点ポイント（5W2H）',
  `related_numbers` JSON NULL COMMENT '関連する決裁No（10 個まで）',
  `round` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '提出の回数',
  `number` VARCHAR(20) NULL COMMENT '決裁No（R8-J-001）',
  `number_department_id` BIGINT UNSIGNED NULL,
  `number_fiscal_year` SMALLINT UNSIGNED NULL COMMENT '期の始まりの年',
  `number_seq` INT UNSIGNED NULL,
  `first_submitted_at` TIMESTAMP NULL,
  `last_submitted_at` TIMESTAMP NULL COMMENT '発信日',
  `decided_at` TIMESTAMP NULL COMMENT '決裁日',
  `finished_at` TIMESTAMP NULL COMMENT '決裁が完了した日時（可・否・条件確認のあと）',
  `status_changed_at` TIMESTAMP NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '同時操作の見張り',
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_requests_number` (`number`),
  KEY `idx_approval_requests_user_status` (`user_id`, `status`),
  KEY `idx_approval_requests_dept_status` (`department_id`, `status`),
  KEY `idx_approval_requests_type` (`type_id`),
  KEY `idx_approval_requests_status` (`status`),
  CONSTRAINT `fk_approval_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_approval_requests_dept` FOREIGN KEY (`department_id`) REFERENCES `approval_departments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_requests_type` FOREIGN KEY (`type_id`) REFERENCES `approval_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_requests_number_dept` FOREIGN KEY (`number_department_id`) REFERENCES `approval_departments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_steps` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT UNSIGNED NOT NULL,
  `round` SMALLINT UNSIGNED NOT NULL,
  `kind` VARCHAR(20) NOT NULL COMMENT 'head / review / president',
  `department_id` BIGINT UNSIGNED NULL COMMENT '部門長の段階は申請部門・審査の段階は審査部門の控え',
  `assignee_user_id` BIGINT UNSIGNED NULL COMMENT '付け替えた担当（NULL なら設定から引く）',
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending / waiting / done / skipped / cancelled',
  `arrived_at` TIMESTAMP NULL,
  `acted_at` TIMESTAMP NULL,
  `actor_user_id` BIGINT UNSIGNED NULL,
  `result` VARCHAR(20) NULL,
  `comment` TEXT NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_approval_steps_request_round` (`request_id`, `round`),
  KEY `idx_approval_steps_kind_status` (`kind`, `status`),
  KEY `idx_approval_steps_dept` (`department_id`),
  KEY `idx_approval_steps_assignee` (`assignee_user_id`),
  KEY `idx_approval_steps_actor` (`actor_user_id`),
  CONSTRAINT `fk_approval_steps_request` FOREIGN KEY (`request_id`) REFERENCES `approval_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_approval_steps_dept` FOREIGN KEY (`department_id`) REFERENCES `approval_departments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_steps_assignee` FOREIGN KEY (`assignee_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_approval_steps_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_revisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT UNSIGNED NOT NULL,
  `round` SMALLINT UNSIGNED NOT NULL,
  `snapshot` JSON NOT NULL COMMENT '提出したときの中身をまるごと',
  `submitted_by` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_revisions_request_round` (`request_id`, `round`),
  CONSTRAINT `fk_approval_revisions_request` FOREIGN KEY (`request_id`) REFERENCES `approval_requests` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_revisions_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_histories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT UNSIGNED NOT NULL,
  `round` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `actor_user_id` BIGINT UNSIGNED NULL COMMENT '省略・交代による移動など、人の操作でないものは NULL',
  `action` VARCHAR(40) NOT NULL,
  `from_status` VARCHAR(20) NULL,
  `to_status` VARCHAR(20) NULL,
  `step_id` BIGINT UNSIGNED NULL,
  `result` VARCHAR(20) NULL,
  `comment` TEXT NULL,
  `reason` TEXT NULL COMMENT '管理者の操作の理由（2b）',
  `meta` JSON NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_approval_histories_request` (`request_id`, `id`),
  KEY `idx_approval_histories_actor` (`actor_user_id`),
  CONSTRAINT `fk_approval_histories_request` FOREIGN KEY (`request_id`) REFERENCES `approval_requests` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_histories_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_approval_histories_step` FOREIGN KEY (`step_id`) REFERENCES `approval_steps` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT UNSIGNED NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_path` VARCHAR(255) NOT NULL COMMENT 'local ディスクの approvals/{申請}/{無作為}.{拡張子}',
  `mime` VARCHAR(100) NOT NULL,
  `size` INT UNSIGNED NOT NULL,
  `uploaded_by` BIGINT UNSIGNED NOT NULL,
  `added_round` SMALLINT UNSIGNED NOT NULL COMMENT 'この添付が初めて入る提出の回',
  `removed_round` SMALLINT UNSIGNED NULL,
  `removed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_attachments_path` (`stored_path`),
  KEY `idx_approval_attachments_request` (`request_id`),
  CONSTRAINT `fk_approval_attachments_request` FOREIGN KEY (`request_id`) REFERENCES `approval_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_approval_attachments_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_download_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT UNSIGNED NOT NULL,
  `attachment_id` BIGINT UNSIGNED NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `kind` VARCHAR(20) NOT NULL COMMENT 'attachment（段階4 で pdf / excel）',
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_approval_download_logs_request` (`request_id`),
  KEY `idx_approval_download_logs_user` (`user_id`),
  CONSTRAINT `fk_approval_download_logs_request` FOREIGN KEY (`request_id`) REFERENCES `approval_requests` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_download_logs_attachment` FOREIGN KEY (`attachment_id`) REFERENCES `approval_attachments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_download_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `approval_number_sequences` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` BIGINT UNSIGNED NOT NULL,
  `fiscal_year` SMALLINT UNSIGNED NOT NULL COMMENT '期の始まりの年',
  `next_number` INT UNSIGNED NOT NULL DEFAULT 1,
  `last_issued` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_number_sequences` (`department_id`, `fiscal_year`),
  CONSTRAINT `fk_approval_number_sequences_dept` FOREIGN KEY (`department_id`) REFERENCES `approval_departments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 4: テスト用の migration を書く**

`database/migrations/2026_09_25_000001_create_approval_phase2_tables.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 決裁申請 段階2（2a）の表（設計書 §5.3）。
 *
 * ⚠ **これは SQLite のテストのための鏡**。本番は `database/sql/2026-09-25-approval-phase2a.sql` を
 *   手で流す（このプロジェクトは migration で本番を管理していない）。**両方を対で維持すること。**
 *
 * ⚠ 状態などの値は enum にせず string（設計書 §5.3。SQLite で enum を変えると CHECK が消える。Bug #60）。
 * ⚠ 索引名は本番の SQL と同じ名前を渡す（本番に手で流すため。段階1 と同じ流儀）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_departments', function (Blueprint $table) {
            $table->foreignId('head_user_id')->nullable()->after('code')
                  ->constrained('users')->comment('部門長');
            $table->index('head_user_id', 'idx_approval_departments_head');
        });

        Schema::table('approval_settings', function (Blueprint $table) {
            $table->timestamp('launched_at')->nullable()->after('president_user_id')
                  ->comment('使い始めた日時（NULL のあいだは準備中）');
        });

        Schema::create('approval_reviewers', function (Blueprint $table) {
            $table->foreignId('department_id')->constrained('approval_departments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->primary(['department_id', 'user_id']);
            $table->index('user_id', 'idx_approval_reviewers_user');
        });

        Schema::create('approval_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->text('headings')->comment('5W2H の見出し（本文の初期値）');
            $table->foreignId('review_department_id')->constrained('approval_departments')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name', 'uq_approval_types_name');
            $table->index('review_department_id', 'idx_approval_types_review_dept');
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->comment('申請者');
            $table->foreignId('department_id')->nullable()->constrained('approval_departments')->restrictOnDelete();
            $table->foreignId('type_id')->nullable()->constrained('approval_types')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('decision', 20)->nullable()->comment('社長の判断');
            $table->string('subject', 100)->nullable();
            $table->unsignedBigInteger('amount')->nullable()->comment('円・税抜');
            $table->string('schedule', 50)->nullable()->comment('実施時期');
            $table->text('body')->nullable()->comment('重点ポイント（5W2H）');
            $table->json('related_numbers')->nullable();
            $table->unsignedSmallInteger('round')->default(0)->comment('提出の回数');
            $table->string('number', 20)->nullable()->comment('決裁No');
            $table->foreignId('number_department_id')->nullable()->constrained('approval_departments')->restrictOnDelete();
            $table->unsignedSmallInteger('number_fiscal_year')->nullable();
            $table->unsignedInteger('number_seq')->nullable();
            $table->timestamp('first_submitted_at')->nullable();
            $table->timestamp('last_submitted_at')->nullable()->comment('発信日');
            $table->timestamp('decided_at')->nullable()->comment('決裁日');
            $table->timestamp('finished_at')->nullable()->comment('決裁が完了した日時');
            $table->timestamp('status_changed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0)->comment('同時操作の見張り');
            $table->timestamps();

            $table->unique('number', 'uq_approval_requests_number');
            $table->index(['user_id', 'status'], 'idx_approval_requests_user_status');
            $table->index(['department_id', 'status'], 'idx_approval_requests_dept_status');
            $table->index('type_id', 'idx_approval_requests_type');
            $table->index('status', 'idx_approval_requests_status');
        });

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('round');
            $table->string('kind', 20);
            $table->foreignId('department_id')->nullable()->constrained('approval_departments')->restrictOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users');
            $table->string('status', 20)->default('pending');
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->string('result', 20)->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['request_id', 'round'], 'idx_approval_steps_request_round');
            $table->index(['kind', 'status'], 'idx_approval_steps_kind_status');
            $table->index('department_id', 'idx_approval_steps_dept');
            $table->index('assignee_user_id', 'idx_approval_steps_assignee');
            $table->index('actor_user_id', 'idx_approval_steps_actor');
        });

        Schema::create('approval_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->restrictOnDelete();
            $table->unsignedSmallInteger('round');
            $table->json('snapshot');
            $table->foreignId('submitted_by')->constrained('users');
            // ⚠ updated_at は持たない（追記のみ）
            $table->timestamp('created_at')->nullable();

            $table->unique(['request_id', 'round'], 'uq_approval_revisions_request_round');
        });

        Schema::create('approval_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->restrictOnDelete();
            $table->unsignedSmallInteger('round')->default(0);
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->string('action', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->foreignId('step_id')->nullable()->constrained('approval_steps')->restrictOnDelete();
            $table->string('result', 20)->nullable();
            $table->text('comment')->nullable();
            $table->text('reason')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['request_id', 'id'], 'idx_approval_histories_request');
            $table->index('actor_user_id', 'idx_approval_histories_actor');
        });

        Schema::create('approval_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->string('original_name', 255);
            $table->string('stored_path', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->unsignedSmallInteger('added_round');
            $table->unsignedSmallInteger('removed_round')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique('stored_path', 'uq_approval_attachments_path');
            $table->index('request_id', 'idx_approval_attachments_request');
        });

        Schema::create('approval_download_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->restrictOnDelete();
            $table->foreignId('attachment_id')->nullable()->constrained('approval_attachments')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('kind', 20);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('request_id', 'idx_approval_download_logs_request');
            $table->index('user_id', 'idx_approval_download_logs_user');
        });

        Schema::create('approval_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('approval_departments')->restrictOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedInteger('last_issued')->default(0);
            $table->timestamps();

            $table->unique(['department_id', 'fiscal_year'], 'uq_approval_number_sequences');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_number_sequences');
        Schema::dropIfExists('approval_download_logs');
        Schema::dropIfExists('approval_attachments');
        Schema::dropIfExists('approval_histories');
        Schema::dropIfExists('approval_revisions');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_types');
        Schema::dropIfExists('approval_reviewers');

        Schema::table('approval_settings', function (Blueprint $table) {
            $table->dropColumn('launched_at');
        });

        Schema::table('approval_departments', function (Blueprint $table) {
            $table->dropForeign(['head_user_id']);
            $table->dropIndex('idx_approval_departments_head');
            $table->dropColumn('head_user_id');
        });
    }
};
```

⚠ SQLite で既存の表（`approval_departments`）に外部キー付きの列を足すと、Laravel 12 は表を作り直して足す。
もし `Phase2TablesTest` 以外の段階1 のテストがこの migration のせいで赤になったら（CHECK が消えるなど。Bug #60）、
段階1 の計画 §0.2 と同じく**作成 migration（`2026_09_16_000001_create_approval_tables.php`）を直接書き換えて**
`head_user_id` をそこで作る形に替え、この migration からは `approval_departments` の変更を外す（本番の SQL はそのまま）。

- [ ] **Step 5: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'Phase2TablesTest|ApprovalTablesTest|OrganizationManagementTest'
```

Expected: PASS（段階1 の表のテストと部門の管理のテストも緑のまま）

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add database/sql/2026-09-25-approval-phase2a.sql database/migrations/2026_09_25_000001_create_approval_phase2_tables.php tests/Feature/Approval/Phase2/Phase2TablesTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
feat(approval): 段階2 の表を足す（本番の SQL とテスト用の migration）

申請・段階・控え・記録・添付・ダウンロードの記録・連番・種類・審査担当者の表と、
部門長・使い始めた日時の列。SQL と migration の列が食い違わないことをテストで固定する。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 2: 状態・判断・段階の値（enum 5 つ）

**Files:**
- Create: `app/Enums/ApprovalStatus.php`・`ApprovalDecision.php`・`ApprovalStepKind.php`・`ApprovalStepStatus.php`・`ApprovalStepResult.php`
- Test: `tests/Unit/Approval/ApprovalEnumsTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Approval/ApprovalEnumsTest.php`

```php
<?php

namespace Tests\Unit\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use PHPUnit\Framework\TestCase;

class ApprovalEnumsTest extends TestCase
{
    public function test_every_status_has_a_label_and_a_badge(): void
    {
        foreach (ApprovalStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
            $this->assertStringContainsString('background:', $status->badgeStyle());
        }
    }

    /** 自分の申請一覧の絞り込みが、すべての状態を 1 回ずつ受け持つ（漏れも重なりもない） */
    public function test_the_list_filters_cover_every_status_once(): void
    {
        $seen = [];
        foreach (ApprovalStatus::listFilters() as $filter) {
            foreach ($filter['statuses'] as $status) {
                $seen[] = $status->value;
            }
        }
        sort($seen);

        $all = array_map(fn (ApprovalStatus $s) => $s->value, ApprovalStatus::cases());
        sort($all);

        $this->assertSame($all, $seen);
    }

    public function test_only_draft_and_returned_are_editable(): void
    {
        $editable = array_values(array_filter(ApprovalStatus::cases(), fn (ApprovalStatus $s) => $s->isEditable()));

        $this->assertSame([ApprovalStatus::Draft, ApprovalStatus::Returned], $editable);
    }

    /** 取り下げは社長の判断の前だけ（要件 4.5）。条件確認待ち・決裁済み・否決・下書きは不可 */
    public function test_withdrawable_statuses(): void
    {
        $withdrawable = array_values(array_filter(ApprovalStatus::cases(), fn (ApprovalStatus $s) => $s->isWithdrawable()));

        $this->assertSame([ApprovalStatus::HeadReview, ApprovalStatus::Review, ApprovalStatus::President, ApprovalStatus::Returned], $withdrawable);
    }

    public function test_results_allowed_for_each_step(): void
    {
        $this->assertSame([ApprovalStepResult::Approve, ApprovalStepResult::Return], ApprovalStepResult::allowedFor(ApprovalStepKind::Head));
        $this->assertSame([ApprovalStepResult::Ok, ApprovalStepResult::Hold, ApprovalStepResult::Ng], ApprovalStepResult::allowedFor(ApprovalStepKind::Review));
        $this->assertSame(
            [ApprovalStepResult::Approve, ApprovalStepResult::Conditional, ApprovalStepResult::Return, ApprovalStepResult::Reject],
            ApprovalStepResult::allowedFor(ApprovalStepKind::President)
        );
    }

    /** 同じ値でも、部門長は「承認」・社長は「可」と表示する */
    public function test_approve_is_labelled_by_step(): void
    {
        $this->assertSame('承認', ApprovalStepResult::Approve->labelFor(ApprovalStepKind::Head));
        $this->assertSame('可', ApprovalStepResult::Approve->labelFor(ApprovalStepKind::President));
    }

    /** コメント必須は 差戻し・保留・否・条可（要件 4.2） */
    public function test_comment_is_required_for_return_hold_ng_conditional_and_reject(): void
    {
        $required = array_values(array_filter(ApprovalStepResult::cases(), fn (ApprovalStepResult $r) => $r->requiresComment()));

        $this->assertSame(
            [ApprovalStepResult::Return, ApprovalStepResult::Hold, ApprovalStepResult::Ng, ApprovalStepResult::Conditional, ApprovalStepResult::Reject],
            $required
        );
    }
}
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter ApprovalEnumsTest
```

Expected: FAIL（`Class "App\Enums\ApprovalStatus" not found`）

- [ ] **Step 3: enum を書く**

`app/Enums/ApprovalStatus.php`

```php
<?php

namespace App\Enums;

/**
 * 決裁の申請の状態（要件定義書 4.8・設計書 §5.8）。
 *
 * ⚠ DB は VARCHAR（ENUM にしない。設計書 §5.3）。モデルで casts() にかけるので、読み出した属性は
 *   すでに enum。キャスト済みの属性に tryFrom() を呼ばない（Bug #22）。クエリには ->value を渡す。
 */
enum ApprovalStatus: string
{
    case Draft      = 'draft';
    case HeadReview = 'head_review';
    case Review     = 'review';
    case President  = 'president';
    case Returned   = 'returned';
    case Condition  = 'condition';
    case Approved   = 'approved';
    case Rejected   = 'rejected';
    case Withdrawn  = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft      => '下書き',
            self::HeadReview => '部門長確認中',
            self::Review     => '審査中',
            self::President  => '社長決裁待ち',
            self::Returned   => '差戻し中',
            self::Condition  => '条件確認待ち',
            self::Approved   => '決裁済み',
            self::Rejected   => '否決',
            self::Withdrawn  => '取り下げ',
        };
    }

    /** ステータスバッジは inline style を返す（Tailwind クラス指定は規約で NG） */
    public function badgeStyle(): string
    {
        return match ($this) {
            self::Draft                                     => 'background: #f3f4f6; color: #374151;',
            self::HeadReview, self::Review, self::President => 'background: #dbeafe; color: #1e40af;',
            self::Returned, self::Condition                 => 'background: #fef3c7; color: #92400e;',
            self::Approved                                  => 'background: #d1fae5; color: #065f46;',
            self::Rejected                                  => 'background: #fee2e2; color: #991b1b;',
            self::Withdrawn                                 => 'background: #f3f4f6; color: #6b7280;',
        };
    }

    /** 回覧中（部門長・審査・社長のどこかで待っている） */
    public function isInCirculation(): bool
    {
        return in_array($this, [self::HeadReview, self::Review, self::President], true);
    }

    /** 申請者が中身と添付を直せる（要件 5.3・設計書 §5.11） */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Returned], true);
    }

    /** 申請者が取り下げられる（要件 4.5） */
    public function isWithdrawable(): bool
    {
        return in_array($this, [self::HeadReview, self::Review, self::President, self::Returned], true);
    }

    /**
     * 自分の申請一覧（画面④）の絞り込み（設計書 §5.12）。キーは URL の `?filter=` に出る。
     *
     * @return array<string, array{label: string, statuses: list<self>}>
     */
    public static function listFilters(): array
    {
        return [
            'draft'     => ['label' => '下書き',   'statuses' => [self::Draft]],
            'progress'  => ['label' => '進行中',   'statuses' => [self::HeadReview, self::Review, self::President]],
            'returned'  => ['label' => '差戻し中', 'statuses' => [self::Returned]],
            'done'      => ['label' => '完了',     'statuses' => [self::Condition, self::Approved, self::Rejected]],
            'withdrawn' => ['label' => '取り下げ', 'statuses' => [self::Withdrawn]],
        ];
    }
}
```

`app/Enums/ApprovalDecision.php`

```php
<?php

namespace App\Enums;

/** 社長の判断（要件 4.2・6.3）。番号が付く 3 つだけ（差戻しは判断に残さない） */
enum ApprovalDecision: string
{
    case Approve     = 'approve';
    case Conditional = 'conditional';
    case Reject      = 'reject';

    public function label(): string
    {
        return match ($this) {
            self::Approve     => '可',
            self::Conditional => '条可',
            self::Reject      => '否',
        };
    }
}
```

`app/Enums/ApprovalStepKind.php`

```php
<?php

namespace App\Enums;

/** 回る段階（要件 4.1）。並びは部門長 → 審査 → 社長で固定 */
enum ApprovalStepKind: string
{
    case Head      = 'head';
    case Review    = 'review';
    case President = 'president';

    public function label(): string
    {
        return match ($this) {
            self::Head      => '部門長',
            self::Review    => '審査',
            self::President => '社長',
        };
    }
}
```

`app/Enums/ApprovalStepStatus.php`

```php
<?php

namespace App\Enums;

/** 段階の状態（設計書 §5.3） */
enum ApprovalStepStatus: string
{
    case Pending   = 'pending';
    case Waiting   = 'waiting';
    case Done      = 'done';
    case Skipped   = 'skipped';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'まだ届いていない',
            self::Waiting   => '確認待ち',
            self::Done      => '済み',
            self::Skipped   => '省略',
            self::Cancelled => '打ち切り',
        };
    }
}
```

`app/Enums/ApprovalStepResult.php`

```php
<?php

namespace App\Enums;

/**
 * 段階ごとの判断（要件 4.2）。
 *
 * ⚠ `Approve` は部門長では「承認」、社長では「可」と表示が変わる（値は同じ）。
 *   表示は必ず labelFor() に段階を渡して作る。
 */
enum ApprovalStepResult: string
{
    case Approve     = 'approve';
    case Return      = 'return';
    case Ok          = 'ok';
    case Hold        = 'hold';
    case Ng          = 'ng';
    case Conditional = 'conditional';
    case Reject      = 'reject';

    public function labelFor(ApprovalStepKind $kind): string
    {
        return match ($this) {
            self::Approve     => $kind === ApprovalStepKind::Head ? '承認' : '可',
            self::Return      => '差戻し',
            self::Ok          => '可',
            self::Hold        => '保留',
            self::Ng          => '否',
            self::Conditional => '条可',
            self::Reject      => '否',
        };
    }

    /** その段階で選べる判断（画面の並び順） @return list<self> */
    public static function allowedFor(ApprovalStepKind $kind): array
    {
        return match ($kind) {
            ApprovalStepKind::Head      => [self::Approve, self::Return],
            ApprovalStepKind::Review    => [self::Ok, self::Hold, self::Ng],
            ApprovalStepKind::President => [self::Approve, self::Conditional, self::Return, self::Reject],
        };
    }

    /** コメントが必須か（要件 4.2: 差戻し・保留・否・条可） */
    public function requiresComment(): bool
    {
        return in_array($this, [self::Return, self::Hold, self::Ng, self::Conditional, self::Reject], true);
    }
}
```

- [ ] **Step 4: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter ApprovalEnumsTest
```

Expected: PASS（7 tests）

- [ ] **Step 5: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add app/Enums/ApprovalStatus.php app/Enums/ApprovalDecision.php app/Enums/ApprovalStepKind.php app/Enums/ApprovalStepStatus.php app/Enums/ApprovalStepResult.php tests/Unit/Approval/ApprovalEnumsTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
feat(approval): 申請の状態・社長の判断・段階の値を enum にする

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 3: モデル・追記のみの守り・テストの土台

**Files:**
- Create: `app/Models/Concerns/AppendOnly.php`
- Create: `app/Models/ApprovalType.php`・`ApprovalRequest.php`・`ApprovalStep.php`・`ApprovalRevision.php`・`ApprovalHistory.php`・`ApprovalAttachment.php`・`ApprovalDownloadLog.php`・`ApprovalNumberSequence.php`
- Modify: `app/Models/ApprovalDepartment.php`・`app/Models/ApprovalSetting.php`・`app/Models/User.php`
- Create: `tests/Concerns/BuildsApprovalFixtures.php`
- Test: `tests/Feature/Approval/Phase2/Phase2ModelsTest.php`

- [ ] **Step 1: テストの土台を書く**

`tests/Concerns/BuildsApprovalFixtures.php`

```php
<?php

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMember;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalType;
use App\Models\User;
use App\Support\Approval\BodyTemplate;
use App\Support\Approval\Workflow;

/**
 * 段階2 のテストの土台（計画 §0.10）。
 *
 * ⚠ 段階1 のテストは各クラスの private メソッドで作っている（ここへ寄せない。範囲外）。
 * ⚠ 利用者は必ず `must_change_password => false`（既定の true だと ForcePasswordChange が転送する）。
 * ⚠ `role` は明示する（ファクトリは入れない）。決裁の印を付けたら `fresh()` を返す。
 */
trait BuildsApprovalFixtures
{
    private int $approvalFixtureSeq = 0;

    /** 使い始めた状態にする（申請を回す画面が開く。設計書 §5.2） */
    protected function launchApprovals(): void
    {
        ApprovalSetting::current()->update(['launched_at' => now()]);
    }

    protected function approvalCompany(array $attributes = []): ApprovalCompany
    {
        return ApprovalCompany::create(array_merge(
            ['name' => 'ミツワ都市開発' . (++$this->approvalFixtureSeq), 'fiscal_start_month' => 5, 'sort_order' => 1],
            $attributes
        ));
    }

    /** 部門。アルファベットの既定は Z で始める（テストが名指しする J・S などとぶつけない） */
    protected function approvalDepartment(ApprovalCompany $company, array $attributes = []): ApprovalDepartment
    {
        $n = ++$this->approvalFixtureSeq;

        return ApprovalDepartment::create(array_merge([
            'company_id' => $company->id,
            'name'       => "部門{$n}",
            'short_name' => "部{$n}",
            'code'       => 'Z' . chr(65 + ($n % 26)) . chr(65 + intdiv($n, 26) % 26),
            'sort_order' => $n,
        ], $attributes));
    }

    /** 基幹を使う人（ファクトリの既定でメールアドレスあり） */
    protected function baseUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => UserRole::Staff->value, 'must_change_password' => false], $attributes));
    }

    /** 決裁のみ利用者（メールなしが既定） */
    protected function approvalOnlyUser(array $attributes = []): User
    {
        return User::factory()->approvalOnly()->create(array_merge(['must_change_password' => false], $attributes));
    }

    protected function approvalAdmin(array $attributes = []): User
    {
        $user = $this->baseUser(array_merge(['name' => '決裁 管理者'], $attributes));
        ApprovalMember::create(['user_id' => $user->id, 'is_admin' => true]);

        return $user->fresh();
    }

    protected function viewAllUser(): User
    {
        $user = $this->baseUser(['name' => '役員 閲覧']);
        ApprovalMember::create(['user_id' => $user->id, 'can_view_all' => true]);

        return $user->fresh();
    }

    protected function makePresident(?User $user = null): User
    {
        $user ??= $this->baseUser(['name' => '社長 太郎']);
        ApprovalSetting::current()->update(['president_user_id' => $user->id]);

        return $user->fresh();
    }

    protected function approvalType(ApprovalDepartment $reviewDept, array $attributes = []): ApprovalType
    {
        return ApprovalType::create(array_merge([
            'name'                 => '購入・発注' . (++$this->approvalFixtureSeq),
            'headings'             => BodyTemplate::DEFAULT,
            'review_department_id' => $reviewDept->id,
            'sort_order'           => 1,
            'is_active'            => true,
        ], $attributes));
    }

    /**
     * 申請が一通り回る最小の組織。
     *
     * 会社（5 月始まり）・申請部門「住宅事業部」J（部門長あり）・審査部門「総務部」S（審査担当者 1 人）・
     * 社長・種類・申請者（住宅事業部に所属する決裁のみ利用者）。
     *
     * @return array{company: ApprovalCompany, dept: ApprovalDepartment, reviewDept: ApprovalDepartment, head: User, reviewer: User, president: User, applicant: User, type: ApprovalType}
     */
    protected function approvalWorld(): array
    {
        $company    = $this->approvalCompany();
        $head       = $this->baseUser(['name' => '部門 長']);
        $reviewer   = $this->baseUser(['name' => '審査 担当']);
        $president  = $this->makePresident();
        $dept       = $this->approvalDepartment($company, ['name' => '住宅事業部', 'short_name' => '住宅', 'code' => 'J', 'head_user_id' => $head->id]);
        $reviewDept = $this->approvalDepartment($company, ['name' => '総務部', 'short_name' => '総務', 'code' => 'S']);
        $reviewDept->reviewers()->attach($reviewer->id);
        $type       = $this->approvalType($reviewDept);
        $applicant  = $this->approvalOnlyUser(['name' => '申請 花子']);
        $applicant->approvalDepartments()->attach($dept->id);

        return [
            'company'    => $company,
            'dept'       => $dept->fresh(),
            'reviewDept' => $reviewDept->fresh(),
            'head'       => $head,
            'reviewer'   => $reviewer,
            'president'  => $president,
            'applicant'  => $applicant->fresh(),
            'type'       => $type,
        ];
    }

    /** 中身の入った下書き（そのまま提出できる） */
    protected function draftFor(array $world, array $attributes = []): ApprovalRequest
    {
        return ApprovalRequest::create(array_merge([
            'user_id'         => $world['applicant']->id,
            'department_id'   => $world['dept']->id,
            'type_id'         => $world['type']->id,
            'subject'         => '社用車の購入',
            'amount'          => 2850000,
            'schedule'        => '2026年10月',
            'body'            => "■ なぜ（目的・理由）\n・老朽化のため\n",
            'related_numbers' => [],
        ], $attributes))->refresh();
    }

    /** 提出まで進めた申請（部門長確認中） */
    protected function submittedFor(array $world, array $attributes = []): ApprovalRequest
    {
        $request = $this->draftFor($world, $attributes);
        app(Workflow::class)->submit($request, $world['applicant']);

        return $request->refresh();
    }
}
```

⚠ `submittedFor()` は Task 7 の `Workflow` を使う。Task 3〜6 のテストでは呼ばない（呼ぶと `Class not found`）。

- [ ] **Step 2: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/Phase2ModelsTest.php`

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStatus;
use App\Models\ApprovalAttachment;
use App\Models\ApprovalDownloadLog;
use App\Models\ApprovalHistory;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\ApprovalSetting;
use App\Models\ApprovalType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

class Phase2ModelsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    public function test_a_department_has_a_head_and_reviewers(): void
    {
        $world = $this->approvalWorld();

        $this->assertSame($world['head']->id, $world['dept']->head->id);
        $this->assertSame([$world['reviewer']->id], $world['reviewDept']->reviewers->pluck('id')->all());
    }

    /** 12.6 の歯止めが出す理由（部門長が先・次に審査担当者・どちらでもなければ null） */
    public function test_the_assignment_label_names_the_department(): void
    {
        $world = $this->approvalWorld();

        $this->assertSame('決裁の部門「住宅事業部」の部門長', $world['head']->approvalAssignmentLabel());
        $this->assertSame('決裁の部門「総務部」の審査担当者', $world['reviewer']->approvalAssignmentLabel());
        $this->assertNull($world['applicant']->approvalAssignmentLabel());
    }

    public function test_the_settings_know_whether_approvals_are_launched(): void
    {
        $this->assertFalse(ApprovalSetting::current()->isLaunched());

        $this->launchApprovals();

        $this->assertTrue(ApprovalSetting::current()->isLaunched());
    }

    public function test_a_new_request_starts_as_a_draft_with_round_zero(): void
    {
        $request = $this->draftFor($this->approvalWorld());

        $this->assertSame(ApprovalStatus::Draft, $request->status);
        $this->assertSame(0, $request->round);
        $this->assertSame(0, $request->lock_version);
        $this->assertSame('2,850,000円', $request->amountLabel());
    }

    public function test_an_approved_request_shows_the_decision_in_its_status(): void
    {
        $request = $this->draftFor($this->approvalWorld());
        DB::table('approval_requests')->where('id', $request->id)->update(['status' => 'approved', 'decision' => 'conditional']);

        $this->assertSame('決裁済み（条可）', $request->refresh()->statusLabel());
        $this->assertSame(ApprovalDecision::Conditional, $request->decision);
    }

    public function test_a_type_name_is_unique(): void
    {
        $world = $this->approvalWorld();

        $this->expectException(QueryException::class);
        ApprovalType::create(['name' => $world['type']->name, 'headings' => 'x', 'review_department_id' => $world['reviewDept']->id]);
    }

    public function test_a_number_is_unique(): void
    {
        $world = $this->approvalWorld();
        $a = $this->draftFor($world);
        $b = $this->draftFor($world);
        DB::table('approval_requests')->where('id', $a->id)->update(['number' => 'R8-J-001']);

        $this->expectException(QueryException::class);
        DB::table('approval_requests')->where('id', $b->id)->update(['number' => 'R8-J-001']);
    }

    public function test_one_sequence_row_per_department_and_year(): void
    {
        $world = $this->approvalWorld();
        $row = ['department_id' => $world['dept']->id, 'fiscal_year' => 2026, 'next_number' => 1, 'last_issued' => 0];
        DB::table('approval_number_sequences')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('approval_number_sequences')->insert($row);
    }

    /** 控え・記録・ダウンロードの記録は、あとから変えられない（要件 14.2） */
    public function test_append_only_records_cannot_be_updated_or_deleted(): void
    {
        $world   = $this->approvalWorld();
        $request = $this->draftFor($world);

        // ⚠ 書き換える値は実在する列にする。変わらない値を渡すと Eloquent は UPDATE を出さず、
        //   updating も起きない（守りが無くても緑になる）
        $records = [
            [ApprovalRevision::create(['request_id' => $request->id, 'round' => 1, 'snapshot' => ['subject' => 'x'], 'submitted_by' => $world['applicant']->id]), ['round' => 9]],
            [ApprovalHistory::create(['request_id' => $request->id, 'round' => 1, 'action' => 'submitted']), ['action' => 'tampered']],
            [ApprovalDownloadLog::create(['request_id' => $request->id, 'user_id' => $world['applicant']->id, 'kind' => 'attachment']), ['kind' => 'tampered']],
        ];

        foreach ($records as [$record, $change]) {
            try {
                $record->update($change);
                $this->fail(get_class($record) . ' が書き換えられた');
            } catch (RuntimeException $e) {
                $this->assertSame('この記録は書き換えられません。', $e->getMessage());
            }

            try {
                $record->delete();
                $this->fail(get_class($record) . ' が削除された');
            } catch (RuntimeException $e) {
                $this->assertSame('この記録は削除できません。', $e->getMessage());
            }
        }
    }

    /** 記録の表示名。審査の意見は可・保留・否を添える（詳細の画面の「操作の記録」） */
    public function test_a_history_label_names_the_review_opinion(): void
    {
        $this->assertSame('審査の意見（保留）', (new ApprovalHistory(['action' => 'reviewed', 'result' => 'hold']))->label());
        $this->assertSame('部門長が承認', (new ApprovalHistory(['action' => 'head_approved', 'result' => 'approve']))->label());
    }

    public function test_attachment_helpers(): void
    {
        $pdf  = new ApprovalAttachment(['stored_path' => 'approvals/1/abc.pdf', 'size' => 1572864]);
        $heic = new ApprovalAttachment(['stored_path' => 'approvals/1/abc.heic', 'size' => 300000]);

        $this->assertTrue($pdf->opensInline());
        $this->assertFalse($heic->opensInline(), 'HEIC はブラウザで開かない（D14）');
        $this->assertSame('1.5 MB', $pdf->sizeLabel());
        $this->assertSame('293 KB', $heic->sizeLabel());
    }

    /** 画面から来ない列（状態・番号・回数）は fillable に入れない（Workflow だけが書く） */
    public function test_state_columns_are_not_mass_assignable(): void
    {
        $fillable = (new ApprovalRequest())->getFillable();

        foreach (['status', 'decision', 'number', 'round', 'lock_version', 'finished_at'] as $column) {
            $this->assertNotContains($column, $fillable);
        }
    }
}
```

- [ ] **Step 3: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter Phase2ModelsTest
```

Expected: FAIL（モデルが無い）

- [ ] **Step 4: モデルを書く**

`app/Models/Concerns/AppendOnly.php`

```php
<?php

namespace App\Models\Concerns;

use RuntimeException;

/**
 * 追記のみの記録（要件 14.2「記録は後から変更も削除もできない」）。
 *
 * 段階1 の `ApprovalSettingLog` と同じ守りを、段階2 の記録（操作の記録・提出ごとの控え・
 * ダウンロードの記録）に使う。⚠ `ApprovalSettingLog` は今のまま（この trait に寄せない。範囲外）。
 * ⚠ 使うモデルは `const UPDATED_AT = null;` も書く（更新日時の列を持たない）。
 */
trait AppendOnly
{
    protected static function bootAppendOnly(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('この記録は書き換えられません。');
        });

        static::deleting(function (): void {
            throw new RuntimeException('この記録は削除できません。');
        });
    }
}
```

`app/Models/ApprovalType.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 申請の種類（要件 5.5.1 のうち段階2 の分・設計書 §5.5）。
 *
 * ⚠ 本文の形（金額の明細表）・件名の決まり文句・明細表の行・追加の入力欄・定型文・使える部門は
 *   段階5 で列を足す。ここでは 5W2H の見出しの形だけ。
 */
class ApprovalType extends Model
{
    protected $fillable = ['name', 'headings', 'review_department_id', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['review_department_id' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    public function reviewDepartment(): BelongsTo
    {
        return $this->belongsTo(ApprovalDepartment::class, 'review_department_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'type_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
```

`app/Models/ApprovalRequest.php`

```php
<?php

namespace App\Models;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 申請（設計書 §5.3・§5.8）。
 *
 * ⚠ **状態を `update()` で直接変えない。** 状態の移り変わりは `App\Support\Approval\Workflow` だけが行う
 *   （同時操作の見張り `lock_version` と記録を一緒に書くため。設計書 §5.1）。
 * ⚠ 中身（件名・金額・実施時期・本文・関連する決裁No・種類・申請部門）を書き換えてよいのは
 *   下書きと差戻し中だけ（`RequestController` が `RequestPermissions::canEdit()` で確かめる）。
 */
class ApprovalRequest extends Model
{
    protected $fillable = [
        'user_id', 'department_id', 'type_id', 'subject', 'amount', 'schedule', 'body', 'related_numbers',
    ];

    protected function casts(): array
    {
        return [
            'user_id'            => 'integer',
            'department_id'      => 'integer',
            'type_id'            => 'integer',
            'status'             => ApprovalStatus::class,
            'decision'           => ApprovalDecision::class,
            'amount'             => 'integer',
            'related_numbers'    => 'array',
            'round'              => 'integer',
            'number_seq'         => 'integer',
            'number_fiscal_year' => 'integer',
            'lock_version'       => 'integer',
            'first_submitted_at' => 'datetime',
            'last_submitted_at'  => 'datetime',
            'decided_at'         => 'datetime',
            'finished_at'        => 'datetime',
            'status_changed_at'  => 'datetime',
        ];
    }

    /** 申請者（⚠ 利用者は SoftDeletes。退職で削除されても申請は読めること。Top trap #18） */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ApprovalDepartment::class, 'department_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ApprovalType::class, 'type_id');
    }

    /** すべての回の段階（古い順） */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class, 'request_id')->orderBy('round')->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ApprovalRevision::class, 'request_id')->orderBy('round');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ApprovalHistory::class, 'request_id')->orderBy('id');
    }

    /** 今の添付（外したものを除く） */
    public function attachments(): HasMany
    {
        return $this->hasMany(ApprovalAttachment::class, 'request_id')->whereNull('removed_at')->orderBy('id');
    }

    /** 外したものを含むすべての添付（履歴を見るとき用） */
    public function allAttachments(): HasMany
    {
        return $this->hasMany(ApprovalAttachment::class, 'request_id')->orderBy('id');
    }

    /** 今の回の段階（部門長・審査・社長の順） */
    public function currentSteps()
    {
        return $this->steps->where('round', $this->round)->values();
    }

    /** 表示用の状態（決裁済みは「決裁済み（条可）」のように判断を添える） */
    public function statusLabel(): string
    {
        if ($this->status === ApprovalStatus::Approved && $this->decision !== null) {
            return $this->status->label() . '（' . $this->decision->label() . '）';
        }

        return $this->status->label();
    }

    /** 金額の表示（税抜・末尾に「円」。規約: `¥` 接頭辞 NG） */
    public function amountLabel(): ?string
    {
        return $this->amount === null ? null : number_format($this->amount) . '円';
    }
}
```

`app/Models/ApprovalStep.php`

```php
<?php

namespace App\Models;

use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Enums\ApprovalStepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 回る段階（設計書 §5.3・§5.8）。1 回の提出ごとに部門長・審査・社長の 3 行を作る。
 *
 * ⚠ 担当者の列は「付け替え」のときだけ入る（`assignee_user_id`）。空なら部門長は申請部門の
 *   **今の**部門長、社長は**今の**社長、審査はその部門の**今の**審査担当者（要件 4.3 のケース 5〜7）。
 */
class ApprovalStep extends Model
{
    protected $fillable = [
        'request_id', 'round', 'kind', 'department_id', 'assignee_user_id', 'status',
        'arrived_at', 'acted_at', 'actor_user_id', 'result', 'comment',
    ];

    protected function casts(): array
    {
        return [
            'request_id'       => 'integer',
            'round'            => 'integer',
            'kind'             => ApprovalStepKind::class,
            'department_id'    => 'integer',
            'assignee_user_id' => 'integer',
            'status'           => ApprovalStepStatus::class,
            'actor_user_id'    => 'integer',
            'result'           => ApprovalStepResult::class,
            'arrived_at'       => 'datetime',
            'acted_at'         => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ApprovalDepartment::class, 'department_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id')->withTrashed();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
```

`app/Models/ApprovalRevision.php`

```php
<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 提出ごとの中身の控え（設計書 §5.11）。**追記のみ。** 2b の変更点と履歴がこれを使う */
class ApprovalRevision extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['request_id', 'round', 'snapshot', 'submitted_by'];

    protected function casts(): array
    {
        return ['request_id' => 'integer', 'round' => 'integer', 'snapshot' => 'array', 'submitted_by' => 'integer'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }
}
```

`app/Models/ApprovalHistory.php`

```php
<?php

namespace App\Models;

use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 操作の記録（要件 14.2・設計書 §5.11）。**追記のみ。**
 *
 * ⚠ 書くのは `App\Support\Approval\HistoryRecorder` だけ（IP・端末の情報をそろえるため）。
 */
class ApprovalHistory extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    /** 画面に出す言葉（action → 表示） */
    public const LABELS = [
        'submitted'           => '提出',
        'resubmitted'         => '出し直し',
        'head_skipped'        => '部門長の確認を省略（申請者が部門長のため）',
        'head_approved'       => '部門長が承認',
        'head_returned'       => '部門長が差戻し',
        'reviewed'            => '審査の意見',
        'president_approved'  => '社長が可',
        'president_conditional' => '社長が条可',
        'president_rejected'  => '社長が否',
        'president_returned'  => '社長が差戻し',
        'condition_confirmed' => '条件を確認',
        'withdrawn'           => '取り下げ',
        'head_changed'        => '部門長の交代で担当が移った',
    ];

    protected $fillable = [
        'request_id', 'round', 'actor_user_id', 'action', 'from_status', 'to_status', 'step_id',
        'result', 'comment', 'reason', 'meta', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['request_id' => 'integer', 'round' => 'integer', 'actor_user_id' => 'integer', 'step_id' => 'integer', 'meta' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }

    public function label(): string
    {
        $label = self::LABELS[$this->action] ?? $this->action;

        // 審査の意見は可・保留・否を添える（ほかの判断は action の名前に入っている）
        if ($this->action === 'reviewed' && $this->result !== null) {
            $label .= '（' . ApprovalStepResult::from($this->result)->labelFor(ApprovalStepKind::Review) . '）';
        }

        return $label;
    }
}
```

`app/Models/ApprovalAttachment.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 添付（要件 5.3・14.3・設計書 §5.7）。ファイルは `local` ディスク（storage/app/private）。
 *
 * ⚠ ファイルを**上書きしない**（夜間バックアップは同じパス・同じ大きさなら送り直さない。CLAUDE.md）。
 * ⚠ 一度でも提出した申請の添付は、外しても行とファイルを残す（計画 §0.5）。
 */
class ApprovalAttachment extends Model
{
    public const UPDATED_AT = null;

    /** 1 ファイルの上限（KB。基幹の添付と同じ 10MB） */
    public const MAX_KB = 10240;

    /** 1 件の申請に付けられる数（設計書 D14） */
    public const MAX_COUNT = 20;

    /** 受け付ける拡張子 → 返すときの Content-Type（送られてきた MIME は信じない） */
    public const TYPES = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv'  => 'text/csv',
        'txt'  => 'text/plain',
    ];

    /** ブラウザで開くもの（それ以外はダウンロード。HEIC は Safari 以外で表示できないので外す。D14） */
    public const INLINE = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];

    protected $fillable = [
        'request_id', 'original_name', 'stored_path', 'mime', 'size', 'uploaded_by', 'added_round', 'removed_round', 'removed_at',
    ];

    protected function casts(): array
    {
        return ['request_id' => 'integer', 'size' => 'integer', 'uploaded_by' => 'integer', 'added_round' => 'integer', 'removed_round' => 'integer', 'removed_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->stored_path, PATHINFO_EXTENSION));
    }

    public function opensInline(): bool
    {
        return in_array($this->extension(), self::INLINE, true);
    }

    /** 大きさの表示（例: 1.2 MB・340 KB） */
    public function sizeLabel(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1) . ' MB'
            : max(1, (int) ceil($this->size / 1024)) . ' KB';
    }
}
```

`app/Models/ApprovalDownloadLog.php`

```php
<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/** 添付を開いた記録（要件 14.2・設計書 §5.7）。**追記のみ。** 段階4 で PDF・Excel を足す */
class ApprovalDownloadLog extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['request_id', 'attachment_id', 'user_id', 'kind', 'ip_address', 'user_agent'];
}
```

`app/Models/ApprovalNumberSequence.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 部門・年度ごとの連番（要件 6・設計書 §5.9）。
 *
 * ⚠ 読み書きは `App\Support\Approval\ApprovalNumber` だけが行う（行をロックして採るため）。
 */
class ApprovalNumberSequence extends Model
{
    protected $fillable = ['department_id', 'fiscal_year', 'next_number', 'last_issued'];

    protected function casts(): array
    {
        return ['department_id' => 'integer', 'fiscal_year' => 'integer', 'next_number' => 'integer', 'last_issued' => 'integer'];
    }
}
```

既存のモデルに足すもの:

`app/Models/ApprovalDepartment.php`（`$fillable` に `head_user_id` を足し、関係を 3 つ足す）

```php
    protected $fillable = ['company_id', 'name', 'short_name', 'code', 'sort_order', 'head_user_id'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'sort_order' => 'integer', 'head_user_id' => 'integer'];
    }

    /** 部門長（⚠ 利用者は SoftDeletes。Top trap #18） */
    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id')->withTrashed();
    }

    /** 審査担当者（設計書 §5.4。所属は問わない。D6） */
    public function reviewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'approval_reviewers', 'department_id', 'user_id')
                    ->withTimestamps('created_at', false);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'department_id');
    }
```

（`use Illuminate\Database\Eloquent\Relations\HasMany;` を足す）

`app/Models/ApprovalSetting.php`（`launched_at` を足す）

```php
    protected $fillable = ['president_user_id', 'launched_at'];

    protected function casts(): array
    {
        return ['president_user_id' => 'integer', 'launched_at' => 'datetime'];
    }

    /** 使い始めたか（設計書 §5.2・D1）。空のあいだは申請を回す画面を誰にも見せない */
    public function isLaunched(): bool
    {
        return $this->launched_at !== null;
    }
```

`app/Models/User.php`（決裁の関係の並びに足す）

```php
    /** 部門長を務める決裁の部門（設計書 §5.4） */
    public function approvalHeadedDepartments(): HasMany
    {
        return $this->hasMany(ApprovalDepartment::class, 'head_user_id');
    }

    /** 審査担当者を務める決裁の部門（設計書 §5.4） */
    public function approvalReviewerDepartments(): BelongsToMany
    {
        return $this->belongsToMany(ApprovalDepartment::class, 'approval_reviewers', 'user_id', 'department_id')
                    ->withTimestamps('created_at', false);
    }

    /**
     * 部門長・審査担当者に指定されているとき、画面に出す理由（要件 12.6・設計書 §5.4）。
     *
     * ⚠ 無効化・削除・メールアドレスを空にする操作の歯止めが使う（基幹と決裁の利用者管理の両方）。
     *   文言はここで完成させる（呼ぶ側で前後を足さない。`approvalPrivilegeLabel()` と同じ流儀）。
     */
    public function approvalAssignmentLabel(): ?string
    {
        if ($dept = $this->approvalHeadedDepartments()->orderBy('id')->first()) {
            return "決裁の部門「{$dept->name}」の部門長";
        }

        if ($dept = $this->approvalReviewerDepartments()->orderBy('approval_departments.id')->first()) {
            return "決裁の部門「{$dept->name}」の審査担当者";
        }

        return null;
    }
```

⚠ `ApprovalHistory` は `created_at` だけを持つ（`UPDATED_AT = null`）。`HistoryRecorder`（Task 7）が書くまでは、
テストの中だけで直接 `create()` する。

- [ ] **Step 5: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'Phase2ModelsTest|ApprovalTablesTest|StoredTimestampDisplayScanTest'
```

Expected: PASS。`StoredTimestampDisplayScanTest` も緑（新しいモデルの日時の列は `completed_at` などの予約名を使っていない）

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add app/Models tests/Concerns/BuildsApprovalFixtures.php tests/Feature/Approval/Phase2/Phase2ModelsTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
feat(approval): 段階2 のモデルと追記のみの守りを足す

申請・段階・控え・記録・添付・ダウンロードの記録・連番・種類のモデル。控えと記録は
更新と削除を拒む。部門に部門長と審査担当者、設定に使い始めた日時を足す。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 4: 使い始める前の門番（`approval.launched`）

**Files:**
- Create: `app/Http/Middleware/EnsureApprovalLaunched.php`
- Modify: `bootstrap/app.php`（別名と並び）
- Test: `tests/Feature/Approval/Phase2/LaunchGateTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/LaunchGateTest.php`

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Http\Middleware\EnsureApprovalAdmin;
use App\Http\Middleware\EnsureApprovalLaunched;
use App\Models\ApprovalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 使い始める前は、申請を回す画面を誰にも見せない（設計書 §5.2・D1・計画 §0.6）。
 *
 * ⚠ 分類は**全件**で見る（Top trap #13）。新しい `approvals.` のルートは、準備中も開く一覧
 *   （OPEN_BEFORE_LAUNCH）に入れるか、`approval.launched` を付けるかのどちらか。
 */
class LaunchGateTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    /** 準備中も開いてよいルート（名前が完全一致か、`.` で終わるものは先頭一致） */
    private const OPEN_BEFORE_LAUNCH = [
        'approvals.home'                => '準備中の画面を出す（設計書 §5.2）',
        'approvals.admin.users.'        => '利用者の管理（段階1。準備の画面）',
        'approvals.admin.organization.' => '部門の管理（準備の画面。本番で先に登録する。D1）',
        'approvals.admin.types.'        => '申請種類の管理（準備の画面。D1）',
    ];

    /** 門番の付いたルートの数の下限（空振りで緑にならないように。Task 17 で最終の数に上げる） */
    private const MIN_GATED = 0;

    private function isOpenBeforeLaunch(string $name): bool
    {
        foreach (array_keys(self::OPEN_BEFORE_LAUNCH) as $pattern) {
            if (str_ends_with($pattern, '.') ? str_starts_with($name, $pattern) : $name === $pattern) {
                return true;
            }
        }

        return false;
    }

    public function test_every_approval_route_is_classified(): void
    {
        $problems = [];
        $gated    = 0;

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'approvals.')) {
                continue;
            }

            $hasGate = in_array(EnsureApprovalLaunched::class, app('router')->gatherRouteMiddleware($route), true);
            $open    = $this->isOpenBeforeLaunch($name);

            if ($open && $hasGate) {
                $problems[] = "{$name}: 準備中も開く画面なのに approval.launched が付いている";
            }
            if (! $open && ! $hasGate) {
                $problems[] = "{$name}: 申請を回す画面なのに approval.launched が無い（準備中に誰でも開ける）";
            }
            if ($hasGate) {
                $gated++;
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
        $this->assertGreaterThanOrEqual(self::MIN_GATED, $gated, '門番の付いたルートが少なすぎる（分類が空振りしていないか）');
    }

    /** 門番だけを試す見本のルート（このテストの中だけで在る。型宣言でルートモデル結合を起こす） */
    private function probeRoutes(): void
    {
        Route::middleware(['web', 'auth', 'approval.launched'])->group(function (): void {
            Route::get('/approvals/_probe/{approvalRequest}', fn (ApprovalRequest $approvalRequest) => 'ok')->name('approvals._probe.show');
            Route::post('/approvals/_probe', fn () => 'ok')->name('approvals._probe.store');
        });
    }

    /** 準備中に画面を開くと、ホーム（「準備中」を出す）へ送る。存在しない ID でも 404 にしない */
    public function test_before_launch_a_page_goes_to_the_home(): void
    {
        $this->probeRoutes();
        $user = $this->approvalOnlyUser();

        $this->actingAs($user)->get('/approvals/_probe/999999')->assertRedirect(route('approvals.home'));
    }

    public function test_before_launch_a_post_is_not_found(): void
    {
        $this->probeRoutes();

        $this->actingAs($this->approvalOnlyUser())->post('/approvals/_probe')->assertNotFound();
    }

    public function test_before_launch_an_ajax_request_is_not_found(): void
    {
        $this->probeRoutes();

        $this->actingAs($this->approvalOnlyUser())
            ->getJson('/approvals/_probe/999999', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertNotFound();
    }

    public function test_after_launch_the_page_opens(): void
    {
        $this->probeRoutes();
        $request = $this->draftFor($this->approvalWorld());
        $this->launchApprovals();

        $this->actingAs($this->approvalOnlyUser())->get('/approvals/_probe/' . $request->id)->assertOk()->assertSee('ok');
    }

    /** 門番はルートモデル結合より前（データの有無を漏らさない）・決裁の管理の門番より後（管理の画面は 403 が先） */
    public function test_the_gate_runs_after_the_admin_gate_and_before_bindings(): void
    {
        $route  = Route::middleware(['web', 'approval.admin', 'approval.launched'])->get('/approvals/_probe_order', fn () => 'ok');
        $sorted = array_values(app('router')->gatherRouteMiddleware($route));

        $admin    = array_search(EnsureApprovalAdmin::class, $sorted, true);
        $launched = array_search(EnsureApprovalLaunched::class, $sorted, true);
        $bindings = array_search(SubstituteBindings::class, $sorted, true);

        $this->assertIsInt($admin);
        $this->assertIsInt($launched);
        $this->assertIsInt($bindings);
        $this->assertLessThan($launched, $admin, '決裁の管理の門番が先に走っていない');
        $this->assertLessThan($bindings, $launched, 'ルートモデル結合より後に走っている（存在しない ID が 404 になる）');
    }
}
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter LaunchGateTest
```

Expected: FAIL（`EnsureApprovalLaunched` が無い・別名 `approval.launched` が無い）

- [ ] **Step 3: 門番を書く**

`app/Http/Middleware/EnsureApprovalLaunched.php`

```php
<?php

namespace App\Http\Middleware;

use App\Models\ApprovalSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 使い始める前は、申請を回す画面を誰にも見せない（設計書 §5.2・D1・計画 §0.6）。
 *
 * ⚠ 決裁の管理者にも見せない。準備の画面（利用者・部門・申請の種類の管理）にはこの門番を付けない。
 * ⚠ 画面を開く GET・HEAD はホーム（「準備中」を出す）へ送る。それ以外（POST・Ajax・JSON）は 404。
 * ⚠ 並びは `SubstituteBindings` より前・`EnsureApprovalAdmin` より後（bootstrap/app.php）。
 */
class EnsureApprovalLaunched
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ApprovalSetting::current()->isLaunched()) {
            return $next($request);
        }

        $opensAPage = ($request->isMethod('GET') || $request->isMethod('HEAD'))
            && ! $request->expectsJson()
            && ! $request->ajax();

        if ($opensAPage) {
            return redirect()->route('approvals.home');
        }

        abort(404);
    }
}
```

- [ ] **Step 4: 別名と並びを足す**

`bootstrap/app.php` の `$middleware->alias([...])` に 1 行足す:

```php
            // 決裁の管理者に指定された人だけを通す（設計書 §5.2・§5.17）
            'approval.admin' => \App\Http\Middleware\EnsureApprovalAdmin::class,
            // 使い始める前は申請を回す画面を誰にも見せない（段階2 設計書 §5.2・D1）
            'approval.launched' => \App\Http\Middleware\EnsureApprovalLaunched::class,
```

同じファイルの最後の `appendToPriorityList(...EnsureApprovalAdmin::class)` の**あと**に足す:

```php
        // 使い始める前の門番（段階2 設計書 §5.2）も SubstituteBindings より前に出す（存在しない ID でも同じ応答）。
        // ⚠ EnsureApprovalAdmin の**後ろ**に置く。前に置くと、管理の画面を権限の無い人が開いたときに
        //    403 より先に転送・404 が返り、ApprovalAdminGateTest（403 と文言を見る）が落ちる。
        $middleware->appendToPriorityList(
            \App\Http\Middleware\EnsureApprovalAdmin::class,
            \App\Http\Middleware\EnsureApprovalLaunched::class,
        );
```

- [ ] **Step 5: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'LaunchGateTest|ApprovalOnlyLockoutTest|ApprovalAdminGateTest|ApprovalHomeTest'
```

Expected: PASS。段階1 の門番のテスト（並びの固定を含む）も緑のまま。

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add app/Http/Middleware/EnsureApprovalLaunched.php bootstrap/app.php tests/Feature/Approval/Phase2/LaunchGateTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
feat(approval): 使い始める前は申請を回す画面を誰にも見せない門番を足す

approval_settings.launched_at が空のあいだは、画面を開く GET をホーム（準備中）へ送り、
それ以外は 404 にする。approvals. のルートを全件分類して付け忘れを止める。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---



## Task 5: 期と和暦・決裁No の採番

**Files:**
- Create: `app/Support/Approval/ApprovalFiscalYear.php`・`app/Support/Approval/ApprovalNumber.php`
- Modify: `tests/Feature/ClockReadScanTest.php`（`ApprovalNumber.php` の `now()` 1 件）
- Test: `tests/Feature/Approval/Phase2/ApprovalFiscalYearTest.php`・`tests/Feature/Approval/Phase2/ApprovalNumberTest.php`

- [ ] **Step 1: 失敗するテストを書く（期と和暦）**

`tests/Feature/Approval/Phase2/ApprovalFiscalYearTest.php`

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Support\Approval\ApprovalFiscalYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 会社ごとの期と和暦（要件 6.1・6.2）。
 *
 * ⚠ 期の式の走査（JapanBusinessDayTest）は、開始月が変数の式を拾わない（計画 §0.9）。境目はここで固定する。
 * ⚠ Laravel を起動する TestCase にする（起動しない Unit テストは php.ini の timezone に支配される。Bug #54）。
 */
class ApprovalFiscalYearTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function boundaries(): array
    {
        return [
            '5月始まり・4/30 は前の年度' => ['2026-04-30', 5, 2025],
            '5月始まり・5/1 から新しい年度' => ['2026-05-01', 5, 2026],
            '6月始まり・5/31 は前の年度' => ['2026-05-31', 6, 2025],
            '6月始まり・6/1 から新しい年度' => ['2026-06-01', 6, 2026],
            '1月始まり・1/1' => ['2026-01-01', 1, 2026],
            '4月始まり・翌年の 3/31' => ['2027-03-31', 4, 2026],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_the_fiscal_year_of_a_japanese_date(string $date, int $startMonth, int $expected): void
    {
        $this->assertSame($expected, ApprovalFiscalYear::of(CarbonImmutable::parse($date), $startMonth));
    }

    /** 保存した瞬間（UTC）は日本時間に直してから見る（Bug #61） */
    public function test_a_moment_is_read_in_japan_time(): void
    {
        // UTC 4/30 15:30 = 日本時間 5/1 0:30 → 新しい年度
        $this->assertSame(2026, ApprovalFiscalYear::ofMoment(CarbonImmutable::parse('2026-04-30 15:30:00', 'UTC'), 5));
        // UTC 4/30 14:59 = 日本時間 4/30 23:59 → 前の年度
        $this->assertSame(2025, ApprovalFiscalYear::ofMoment(CarbonImmutable::parse('2026-04-30 14:59:59', 'UTC'), 5));
    }

    public function test_the_current_fiscal_year_uses_the_japanese_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 16:00:00', 'UTC'));   // 日本時間 5/1 1:00

        $this->assertSame(2026, ApprovalFiscalYear::current(5));
    }

    public static function eras(): array
    {
        return [
            '令和8年度（5月始まり）' => [2026, 5, 'R8'],
            '令和元年度（令和の初日に始まる期）' => [2019, 5, 'R1'],
            '令和元年度（6月始まり）' => [2019, 6, 'R1'],
            '平成31年度（4月始まりは令和の前に始まる）' => [2019, 4, 'H31'],
            '平成30年度' => [2018, 5, 'H30'],
        ];
    }

    #[DataProvider('eras')]
    public function test_the_era_label_follows_the_first_day_of_the_fiscal_year(int $fiscalYear, int $startMonth, string $expected): void
    {
        $this->assertSame($expected, ApprovalFiscalYear::eraLabel($fiscalYear, $startMonth));
    }
}
```

- [ ] **Step 2: 失敗するテストを書く（採番）**

`tests/Feature/Approval/Phase2/ApprovalNumberTest.php`

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Models\ApprovalNumberSequence;
use App\Support\Approval\ApprovalNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 決裁No の採番（要件 6・設計書 §5.9・D9） */
class ApprovalNumberTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-26 01:00:00', 'UTC'));   // 日本時間 9/26 10:00（R8）
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_numbers_run_per_department_and_year(): void
    {
        $world = $this->approvalWorld();
        $other = $this->approvalDepartment($world['company'], ['name' => 'ミツワ不動産', 'short_name' => '不動産', 'code' => 'M']);

        $this->assertSame('R8-J-001', ApprovalNumber::issue($world['dept'], now())['number']);
        $this->assertSame('R8-J-002', ApprovalNumber::issue($world['dept'], now())['number']);
        $this->assertSame('R8-M-001', ApprovalNumber::issue($other, now())['number']);
        $this->assertSame(1, ApprovalNumberSequence::where('department_id', $world['dept']->id)->count(), '連番の行が 2 行できた');
    }

    /** 期の境目（日本時間の 5/1 0:00）で連番が 1 に戻る */
    public function test_a_new_fiscal_year_starts_again_from_one(): void
    {
        $world = $this->approvalWorld();

        $this->assertSame('R8-J-001', ApprovalNumber::issue($world['dept'], Carbon::parse('2027-04-30 14:00:00', 'UTC'))['number']);
        $issued = ApprovalNumber::issue($world['dept'], Carbon::parse('2027-04-30 15:30:00', 'UTC'));

        $this->assertSame('R9-J-001', $issued['number']);
        $this->assertSame(2027, $issued['fiscal_year']);
        $this->assertSame(1, $issued['seq']);
    }

    /** 期は申請部門の会社ごと（DAD・ZEAL は 6 月始まり） */
    public function test_the_fiscal_year_follows_the_company_of_the_department(): void
    {
        $dad  = $this->approvalCompany(['name' => 'DAD', 'fiscal_start_month' => 6]);
        $dept = $this->approvalDepartment($dad, ['name' => '土木', 'short_name' => '土木', 'code' => 'D']);

        $this->assertSame('R7-D-001', ApprovalNumber::issue($dept, Carbon::parse('2026-05-31 03:00:00', 'UTC'))['number']);
        $this->assertSame('R8-D-001', ApprovalNumber::issue($dept, Carbon::parse('2026-06-01 03:00:00', 'UTC'))['number']);
    }

    public function test_the_start_number_sets_the_next_number(): void
    {
        $world = $this->approvalWorld();

        ApprovalNumber::setNext($world['dept'], 21);

        $this->assertSame('R8-J-021', ApprovalNumber::issue($world['dept'], now())['number']);
    }

    /** 使った番号より小さくできない（D9）。大きい数なら飛ばしてよい */
    public function test_the_start_number_cannot_go_back_over_used_numbers(): void
    {
        $world = $this->approvalWorld();
        ApprovalNumber::setNext($world['dept'], 5);
        ApprovalNumber::issue($world['dept'], now());   // R8-J-005

        try {
            ApprovalNumber::setNext($world['dept'], 5);
            $this->fail('使った番号へ戻せた');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('今年度はすでに R8-J-005 まで使っています。6 以上を入れてください。', $e->getMessage());
        }

        ApprovalNumber::setNext($world['dept'], 10);
        $this->assertSame(['fiscal_year' => 2026, 'era' => 'R8', 'next' => 10, 'last_issued' => 5], ApprovalNumber::currentState($world['dept']));
    }

    /** 999 の次は 1000（要件 6.1） */
    public function test_numbers_past_999_keep_counting(): void
    {
        $world = $this->approvalWorld();
        ApprovalNumber::setNext($world['dept'], 999);

        $this->assertSame('R8-J-999', ApprovalNumber::issue($world['dept'], now())['number']);
        $this->assertSame('R8-J-1000', ApprovalNumber::issue($world['dept'], now())['number']);
    }

    public function test_the_current_state_of_an_unused_department(): void
    {
        $world = $this->approvalWorld();

        $this->assertSame(['fiscal_year' => 2026, 'era' => 'R8', 'next' => 1, 'last_issued' => 0], ApprovalNumber::currentState($world['dept']));
        $this->assertFalse(ApprovalNumber::departmentHasNumbers($world['dept']));
    }
}
```

- [ ] **Step 3: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'ApprovalFiscalYearTest|ApprovalNumberTest'
```

Expected: FAIL（クラスが無い）

- [ ] **Step 4: 部品を書く**

`app/Support/Approval/ApprovalFiscalYear.php`

```php
<?php

namespace App\Support\Approval;

use App\Support\JapanTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * 会社ごとの期と和暦（要件 6.1・6.2・設計書 §5.9）。
 *
 * 年度の値は**期の始まりの年**（ミツワは 5 月始まりなので 2026-05-01〜2027-04-30 → 2026）。
 *
 * ⚠ 日付は**日本の暦**で見る。判断した瞬間（UTC で保存）は日本時間に直してから月を見る。
 *   UTC のままだと日本時間の 0:00〜8:59 が前日になり、期の境目（5/1 の朝）の判断を前の年度に
 *   数えてしまう（Bug #61）。
 * ⚠ 基幹の 5 月始まりの式（`JapanBusinessDayTest` が全件分類）とは別物。こちらは会社ごとの
 *   開始月を受け取る（ミツワ 5 月・DAD／ZEAL 6 月）。
 */
final class ApprovalFiscalYear
{
    /** 令和の始まり。これより前に始まる期は平成（段階5 の過去分の取り込みで使う） */
    private const REIWA_START = '2019-05-01';

    /** 日本の暦の日付が属する年度 */
    public static function of(CarbonInterface $japanDate, int $startMonth): int
    {
        return $japanDate->month >= $startMonth ? $japanDate->year : $japanDate->year - 1;
    }

    /** 瞬間（UTC で保存された日時）が、日本の暦で属する年度 */
    public static function ofMoment(DateTimeInterface $moment, int $startMonth): int
    {
        return self::of(CarbonImmutable::instance($moment)->setTimezone(JapanTime::ZONE), $startMonth);
    }

    /** 日本の今日が属する年度（部門の管理の「今年度の開始番号」） */
    public static function current(int $startMonth): int
    {
        return self::of(JapanTime::today(), $startMonth);
    }

    /** 和暦の略号（R8・H30）。期の始まりの日が属する元号・年で決める（要件 6.1） */
    public static function eraLabel(int $fiscalYear, int $startMonth): string
    {
        $start = sprintf('%04d-%02d-01', $fiscalYear, $startMonth);

        return $start >= self::REIWA_START
            ? 'R' . ($fiscalYear - 2018)
            : 'H' . ($fiscalYear - 1988);
    }
}
```

`app/Support/Approval/ApprovalNumber.php`

```php
<?php

namespace App\Support\Approval;

use App\Models\ApprovalDepartment;
use App\Models\ApprovalNumberSequence;
use App\Models\ApprovalRequest;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 決裁No の採番（要件 6・設計書 §5.9・計画 §0.4）。
 *
 * ⚠ **社長の判断と同じトランザクションの中で呼ぶ**（判断が断られたら番号も戻る）。
 * ⚠ 連番の行は `insertOrIgnore` で用意してから `lockForUpdate()` で読む。行が無いときに 2 人が
 *   同時に作っても、一意の索引（部門・年度）で 1 行になる。
 */
final class ApprovalNumber
{
    /**
     * 次の番号を採る。
     *
     * @return array{number: string, fiscal_year: int, seq: int}
     */
    public static function issue(ApprovalDepartment $department, DateTimeInterface $decidedAt): array
    {
        $startMonth = $department->company->fiscal_start_month;
        $fiscalYear = ApprovalFiscalYear::ofMoment($decidedAt, $startMonth);

        $row = self::lockedRow($department, $fiscalYear);
        $seq = $row->next_number;
        $row->update(['next_number' => $seq + 1, 'last_issued' => $seq]);

        return [
            'number'      => self::format(ApprovalFiscalYear::eraLabel($fiscalYear, $startMonth), $department->code, $seq),
            'fiscal_year' => $fiscalYear,
            'seq'         => $seq,
        ];
    }

    /** `R8-J-001`（999 の次は `R8-J-1000`。要件 6.1） */
    public static function format(string $era, string $code, int $seq): string
    {
        return sprintf('%s-%s-%03d', $era, $code, $seq);
    }

    /**
     * 今年度の状態（部門の管理に出す）。
     *
     * @return array{fiscal_year: int, era: string, next: int, last_issued: int}
     */
    public static function currentState(ApprovalDepartment $department): array
    {
        $startMonth = $department->company->fiscal_start_month;
        $fiscalYear = ApprovalFiscalYear::current($startMonth);
        $row        = ApprovalNumberSequence::where('department_id', $department->id)->where('fiscal_year', $fiscalYear)->first();

        return [
            'fiscal_year' => $fiscalYear,
            'era'         => ApprovalFiscalYear::eraLabel($fiscalYear, $startMonth),
            'next'        => $row?->next_number ?? 1,
            'last_issued' => $row?->last_issued ?? 0,
        ];
    }

    /**
     * 今年度の「次に付く番号」を設定する（要件 6.4）。
     *
     * ⚠ その年度に使った番号より小さい数は断る（D9。番号が重なるのを防ぐ）。
     *
     * @throws InvalidArgumentException 使った番号以下のとき（メッセージはそのまま画面に出せる）
     */
    public static function setNext(ApprovalDepartment $department, int $next): void
    {
        DB::transaction(function () use ($department, $next): void {
            $fiscalYear = ApprovalFiscalYear::current($department->company->fiscal_start_month);
            $row        = self::lockedRow($department, $fiscalYear);

            if ($next <= $row->last_issued) {
                $used = self::format(ApprovalFiscalYear::eraLabel($fiscalYear, $department->company->fiscal_start_month), $department->code, $row->last_issued);
                throw new InvalidArgumentException("今年度はすでに {$used} まで使っています。" . ($row->last_issued + 1) . ' 以上を入れてください。');
            }

            $row->update(['next_number' => $next]);
        });
    }

    /** 番号を付けた申請がある部門か（会社とアルファベットを変えられない。D8） */
    public static function departmentHasNumbers(ApprovalDepartment $department): bool
    {
        return ApprovalRequest::where('number_department_id', $department->id)->exists();
    }

    private static function lockedRow(ApprovalDepartment $department, int $fiscalYear): ApprovalNumberSequence
    {
        // ⚠ 時計は 1 回だけ読む（ClockReadScanTest はファイルごとの件数を見る）
        $now = now();

        DB::table('approval_number_sequences')->insertOrIgnore([
            'department_id' => $department->id,
            'fiscal_year'   => $fiscalYear,
            'next_number'   => 1,
            'last_issued'   => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return ApprovalNumberSequence::where('department_id', $department->id)
            ->where('fiscal_year', $fiscalYear)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
```

- [ ] **Step 5: 時計の走査に登録する**

`tests/Feature/ClockReadScanTest.php` の `ALLOWED` に 1 行足す（件数は `now(` の数。コメントは数えない）:

```php
        'app/Support/Approval/ApprovalNumber.php'             => [1, '連番の行を作った瞬間（created_at・updated_at は TIMESTAMP 列。insertOrIgnore は Eloquent を通らないので手で入れる）'],
```

- [ ] **Step 6: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'ApprovalFiscalYearTest|ApprovalNumberTest|ClockReadScanTest|JapanBusinessDayTest|StoredTimestampDisplayScanTest'
```

Expected: PASS

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add app/Support/Approval/ApprovalFiscalYear.php app/Support/Approval/ApprovalNumber.php tests/Feature/ClockReadScanTest.php tests/Feature/Approval/Phase2/ApprovalFiscalYearTest.php tests/Feature/Approval/Phase2/ApprovalNumberTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
feat(approval): 会社ごとの期と和暦で決裁No を採番する

年度は申請部門の会社の期（ミツワ 5 月・DAD／ZEAL 6 月）で、判断した瞬間を日本時間に
直して決める。連番は部門・年度ごとに行をロックして採り、開始番号は使った番号より
小さくできない。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 6: 本文の見出し・関連する決裁No・提出の条件

**Files:**
- Create: `app/Support/Approval/BodyTemplate.php`・`app/Support/Approval/RelatedNumbers.php`・`app/Support/Approval/SubmitChecker.php`
- Test: `tests/Unit/Approval/BodyTemplateTest.php`・`tests/Unit/Approval/RelatedNumbersTest.php`・`tests/Feature/Approval/Phase2/SubmitCheckerTest.php`

- [ ] **Step 1: 失敗するテストを書く（本文の見出し・関連する決裁No）**

`tests/Unit/Approval/BodyTemplateTest.php`

```php
<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\BodyTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 「見出しのまま」の判定（設計書 D12） */
class BodyTemplateTest extends TestCase
{
    public static function blanks(): array
    {
        return [
            '標準の見出しのまま' => [BodyTemplate::DEFAULT],
            '空'                  => [''],
            'null'                => [null],
            '・の後ろに空白'      => ["■ なぜ（目的・理由）\n・ \n■ 何を（内容）\n・\u{3000}"],
            '改行が CRLF'         => ["■ なぜ\r\n・\r\n"],
            '見出しを書き換えただけ' => ["■ 目的\n・\n■ 費用\n・"],
        ];
    }

    #[DataProvider('blanks')]
    public function test_headings_only_is_blank(?string $body): void
    {
        $this->assertTrue(BodyTemplate::isBlank($body));
    }

    public static function written(): array
    {
        return [
            '箇条書きに中身'  => ["■ なぜ（目的・理由）\n・老朽化のため"],
            '「なし」と書いた' => ["■ 補足\n・なし"],
            '見出しの外に文'  => ["社用車を 1 台買い替えたい"],
        ];
    }

    #[DataProvider('written')]
    public function test_any_content_is_not_blank(string $body): void
    {
        $this->assertFalse(BodyTemplate::isBlank($body));
    }

    /** 標準の見出しは 6 つ（いつ・いくらは入れない。要件 5.2） */
    public function test_the_default_has_six_headings_without_when_and_how_much(): void
    {
        $this->assertSame(6, substr_count(BodyTemplate::DEFAULT, '■'));
        $this->assertStringNotContainsString('いつ', BodyTemplate::DEFAULT);
        $this->assertStringNotContainsString('いくら', BodyTemplate::DEFAULT);
    }
}
```

`tests/Unit/Approval/RelatedNumbersTest.php`

```php
<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\RelatedNumbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 関連する決裁No（設計書 D15） */
class RelatedNumbersTest extends TestCase
{
    public function test_numbers_are_normalized(): void
    {
        $this->assertSame('R8-J-001', RelatedNumbers::normalize('ｒ８－ｊ－００１'));
        $this->assertSame('R8-J-015', RelatedNumbers::normalize(' r8ーj―015 '));
        $this->assertSame('H30-JB-120', RelatedNumbers::normalize("\u{3000}h30-jb-120"));
    }

    public function test_clean_drops_empties_and_duplicates_in_order(): void
    {
        $this->assertSame(['R8-J-002', 'R8-J-001'], RelatedNumbers::clean(['r8-j-002', '', 'R8-J-001', 'Ｒ８－Ｊ－００２', null]));
        $this->assertSame([], RelatedNumbers::clean(null));
    }

    public static function shapes(): array
    {
        return [
            ['R8-J-001', true],
            ['H30-JB-120', true],
            ['R10-ABC-1000', true],
            ['R8-J-01', false],
            ['8-J-001', false],
            ['R8-JABC-001', false],
            ['R8J001', false],
        ];
    }

    #[DataProvider('shapes')]
    public function test_the_shape(string $number, bool $ok): void
    {
        $this->assertSame($ok, (bool) preg_match(RelatedNumbers::PATTERN, $number));
    }
}
```

- [ ] **Step 2: 失敗するテストを書く（提出の条件）**

`tests/Feature/Approval/Phase2/SubmitCheckerTest.php`

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\UserStatus;
use App\Models\ApprovalSetting;
use App\Support\Approval\BodyTemplate;
use App\Support\Approval\SubmitChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 提出の条件（要件 4.3 のケース 4・5.1・設計書 D4・D10・D12） */
class SubmitCheckerTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    public function test_a_complete_draft_has_no_reasons(): void
    {
        $world = $this->approvalWorld();

        $this->assertSame([], SubmitChecker::reasons($this->draftFor($world), $world['applicant']));
    }

    /** 社長は申請できない（D4） */
    public function test_the_president_cannot_submit(): void
    {
        $world = $this->approvalWorld();
        $this->makePresident($world['applicant']);

        $this->assertContains('社長に指定されている人は申請できません。', SubmitChecker::reasons($this->draftFor($world), $world['applicant']->fresh()));
    }

    public function test_someone_without_departments_cannot_submit(): void
    {
        $world = $this->approvalWorld();
        $world['applicant']->approvalDepartments()->detach();

        $reasons = SubmitChecker::reasons($this->draftFor($world), $world['applicant']);

        $this->assertContains('所属部門が未設定です。管理者に連絡してください。', $reasons);
    }

    public function test_the_department_must_be_one_of_mine(): void
    {
        $world = $this->approvalWorld();
        $other = $this->approvalDepartment($world['company'], ['name' => '賃貸事業部', 'head_user_id' => $world['head']->id]);

        $reasons = SubmitChecker::reasons($this->draftFor($world, ['department_id' => $other->id]), $world['applicant']);

        $this->assertContains('申請部門「賃貸事業部」に所属していません。申請部門を選び直してください。', $reasons);
    }

    /** 4.3 のケース 4: 部門長が未設定 */
    public function test_a_department_without_a_head_cannot_receive_requests(): void
    {
        $world = $this->approvalWorld();
        $world['dept']->update(['head_user_id' => null]);

        $this->assertContains(
            '部門「住宅事業部」の部門長が未設定です。管理者に連絡してください。',
            SubmitChecker::reasons($this->draftFor($world), $world['applicant'])
        );
    }

    /** 4.3 のケース 4: 社長が未設定 */
    public function test_nothing_can_be_submitted_without_a_president(): void
    {
        $world = $this->approvalWorld();
        ApprovalSetting::current()->update(['president_user_id' => null]);

        $this->assertContains('社長が未設定です。管理者に連絡してください。', SubmitChecker::reasons($this->draftFor($world), $world['applicant']));
    }

    /** 4.3 のケース 4: 申請者本人以外の有効な審査担当者がいない（無効な人は数えない） */
    public function test_there_must_be_another_active_reviewer(): void
    {
        $world = $this->approvalWorld();
        $world['reviewer']->status = UserStatus::Inactive->value;
        $world['reviewer']->save();
        $world['reviewDept']->reviewers()->attach($world['applicant']->id);

        $this->assertContains(
            '審査部門「総務部」に、申請者本人以外の審査担当者がいません。管理者に連絡してください。',
            SubmitChecker::reasons($this->draftFor($world), $world['applicant'])
        );
    }

    /** 停止した種類: 下書きは選び直し、差戻し中はそのまま出し直せる（D10） */
    public function test_a_stopped_type_blocks_drafts_but_not_returned_requests(): void
    {
        $world = $this->approvalWorld();
        $world['type']->update(['is_active' => false]);
        $draft = $this->draftFor($world);

        $this->assertContains('申請の種類「' . $world['type']->name . '」は使えなくなりました。種類を選び直してください。', SubmitChecker::reasons($draft, $world['applicant']));

        DB::table('approval_requests')->where('id', $draft->id)->update(['status' => 'returned', 'round' => 1]);

        $this->assertSame([], SubmitChecker::reasons($draft->refresh(), $world['applicant']));
    }

    /** 件名と本文（D12）。理由はまとめて返す */
    public function test_subject_and_body_are_required_and_reasons_are_collected(): void
    {
        $world = $this->approvalWorld();

        $reasons = SubmitChecker::reasons($this->draftFor($world, ['subject' => ' ', 'body' => BodyTemplate::DEFAULT, 'type_id' => null]), $world['applicant']);

        $this->assertSame([
            '申請の種類を選んでください。',
            '件名を入力してください。',
            '重点ポイント（5W2H）が見出しのままです。中身を書いてください。',
        ], $reasons);
    }
}
```

- [ ] **Step 3: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'BodyTemplateTest|RelatedNumbersTest|SubmitCheckerTest'
```

Expected: FAIL（クラスが無い）

- [ ] **Step 4: 部品を書く**

`app/Support/Approval/BodyTemplate.php`

```php
<?php

namespace App\Support\Approval;

/**
 * 5W2H の見出し（要件 5.2）と「見出しのまま」の判定（設計書 D12）。
 */
final class BodyTemplate
{
    /** 標準の見出し（「いつ」「いくら」は実施時期・金額の欄で書くので入れない。要件 5.2） */
    public const DEFAULT = "■ なぜ（目的・理由）\n・\n■ 何を（内容）\n・\n■ 誰が（担当・実施者）\n・\n■ どこで（場所・対象）\n・\n■ どのように（方法・手順）\n・\n■ 補足（費用の内訳・その他）\n・";

    /**
     * 何も書き足していないか。
     *
     * 見出しの行（`■` で始まる行）・中身の無い `・` の行・空の行を除いて、何も残らなければ「書いていない」。
     * ⚠ 前後の空白は全角（U+3000）も落とす（`trim()` は全角の空白を落とさない）。
     */
    public static function isBlank(?string $body): bool
    {
        foreach (preg_split('/\R/u', (string) $body) as $line) {
            $line = preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $line);

            if ($line === '' || $line === '・' || str_starts_with($line, '■')) {
                continue;
            }

            return false;
        }

        return true;
    }
}
```

`app/Support/Approval/RelatedNumbers.php`

```php
<?php

namespace App\Support\Approval;

/**
 * 関連する決裁No（要件 5.1・設計書 D15）。
 *
 * 紙の時代の番号も手で入れられるので、**形だけ**を確かめる（その番号の決裁が在るかは見ない）。
 */
final class RelatedNumbers
{
    public const MAX = 10;

    /** 和暦（令和 R・平成 H）-部門のアルファベット-3 桁以上の連番 */
    public const PATTERN = '/\A[RH][0-9]{1,2}-[A-Z]{1,3}-[0-9]{3,}\z/';

    /** 1 つの番号を正規化する（全角→半角・大文字・前後の空白・長音やダッシュ類をハイフンに） */
    public static function normalize(?string $value): string
    {
        $value = mb_convert_kana((string) $value, 'as');
        $value = str_replace(['ー', '―', '‐', '−', '–', '—'], '-', $value);

        return mb_strtoupper(preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $value), 'UTF-8');
    }

    /**
     * 画面から来た配列を正規化し、空を落として重複を除く（並びは入れた順）。
     *
     * @param  array<int, mixed>|null  $values
     * @return list<string>
     */
    public static function clean(?array $values): array
    {
        $out = [];

        foreach ($values ?? [] as $value) {
            $n = self::normalize(is_string($value) ? $value : '');
            if ($n !== '' && ! in_array($n, $out, true)) {
                $out[] = $n;
            }
        }

        return $out;
    }
}
```
`app/Support/Approval/SubmitChecker.php`

```php
<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\UserStatus;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalType;
use App\Models\User;

/**
 * 提出（出し直し）の条件（要件 4.3 のケース 4・5.1・設計書 D4・D10・D12・§5.8）。
 *
 * 理由をすべて集めて返す（1 つずつ直して出し直させない）。画面は編集画面の上にまとめて出す。
 */
final class SubmitChecker
{
    /** @return list<string> 提出できない理由（空なら提出できる） */
    public static function reasons(ApprovalRequest $request, User $user): array
    {
        $reasons = [];

        if ($user->isApprovalPresident()) {
            $reasons[] = '社長に指定されている人は申請できません。';
        }

        $department = $request->department;

        if (! $user->approvalDepartments()->exists()) {
            $reasons[] = '所属部門が未設定です。管理者に連絡してください。';
        } elseif ($department === null) {
            $reasons[] = '申請部門を選んでください。';
        } elseif (! $user->approvalDepartments()->whereKey($department->id)->exists()) {
            $reasons[] = "申請部門「{$department->name}」に所属していません。申請部門を選び直してください。";
        } elseif ($department->head_user_id === null) {
            $reasons[] = "部門「{$department->name}」の部門長が未設定です。管理者に連絡してください。";
        }

        $type = $request->type;

        if ($type === null) {
            $reasons[] = '申請の種類を選んでください。';
        } else {
            // 停止した種類: 下書きは選び直し、差戻し中はそのまま出し直せる（D10）
            if (! $type->is_active && $request->status === ApprovalStatus::Draft) {
                $reasons[] = "申請の種類「{$type->name}」は使えなくなりました。種類を選び直してください。";
            }

            if (! self::hasOtherReviewer($type, $user)) {
                $reasons[] = "審査部門「{$type->reviewDepartment->name}」に、申請者本人以外の審査担当者がいません。管理者に連絡してください。";
            }
        }

        if (ApprovalSetting::current()->president_user_id === null) {
            $reasons[] = '社長が未設定です。管理者に連絡してください。';
        }

        if (trim((string) $request->subject) === '') {
            $reasons[] = '件名を入力してください。';
        }

        if (BodyTemplate::isBlank($request->body)) {
            $reasons[] = '重点ポイント（5W2H）が見出しのままです。中身を書いてください。';
        }

        return $reasons;
    }

    /** 審査部門に、申請者本人以外の有効な審査担当者がいるか（4.3 のケース 4） */
    private static function hasOtherReviewer(ApprovalType $type, User $user): bool
    {
        return $type->reviewDepartment->reviewers()
            ->where('users.id', '!=', $user->id)
            ->where('users.status', UserStatus::Active->value)
            ->exists();
    }
}
```

- [ ] **Step 5: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'BodyTemplateTest|RelatedNumbersTest|SubmitCheckerTest'
```

Expected: PASS

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add app/Support/Approval/BodyTemplate.php app/Support/Approval/RelatedNumbers.php app/Support/Approval/SubmitChecker.php tests/Unit/Approval/BodyTemplateTest.php tests/Unit/Approval/RelatedNumbersTest.php tests/Feature/Approval/Phase2/SubmitCheckerTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
feat(approval): 本文の見出し・関連する決裁No の形・提出の条件をまとめる

社長は申請できない・部門長と社長と申請者以外の審査担当者が要る・停止した種類の
下書きは選び直し・見出しのままでは出せない、を理由つきでまとめて返す。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 7: 状態の移り変わり（提出・判断・条件確認・取り下げ・部門長の交代）

**Files:**
- Create: `app/Support/Approval/RequestPermissions.php`・`HistoryRecorder.php`・`RequestSnapshot.php`・`WorkflowConflict.php`・`WorkflowRefused.php`・`Workflow.php`
- Modify: `tests/Feature/ClockReadScanTest.php`（`Workflow.php` の `now()` 7 件）
- Test: `tests/Feature/Approval/Phase2/WorkflowTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/WorkflowTest.php`

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalAttachment;
use App\Models\ApprovalHistory;
use App\Models\ApprovalNumberSequence;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\ApprovalStep;
use App\Support\Approval\Workflow;
use App\Support\Approval\WorkflowConflict;
use App\Support\Approval\WorkflowRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 状態の移り変わり（要件 4 章・4.3 の表・設計書 §5.8・D16〜D23） */
class WorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-26 01:00:00', 'UTC'));   // 日本時間 9/26（R8）
        $this->workflow = app(Workflow::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, string> 今の回の 段階 => 状態 */
    private function stepsOf(ApprovalRequest $request): array
    {
        return ApprovalStep::where('request_id', $request->id)->where('round', $request->round)->orderBy('id')->get()
            ->mapWithKeys(fn (ApprovalStep $s) => [$s->kind->value => $s->status->value])->all();
    }

    /** @return list<string> */
    private function actionsOf(ApprovalRequest $request): array
    {
        return ApprovalHistory::where('request_id', $request->id)->orderBy('id')->pluck('action')->all();
    }

    /** 部門長・審査・社長をすべて通して、社長の決裁待ちまで進める */
    private function toPresident(array $w): ApprovalRequest
    {
        $r = $this->submittedFor($w);
        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);
        $r->refresh();
        $this->workflow->judgeReview($r, $w['reviewer'], $r->lock_version, ApprovalStepResult::Ok, null);

        return $r->refresh();
    }

    public function test_submitting_starts_with_the_head(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->assertSame(ApprovalStatus::HeadReview, $r->status);
        $this->assertSame(1, $r->round);
        $this->assertSame(['head' => 'waiting', 'review' => 'pending', 'president' => 'pending'], $this->stepsOf($r));
        $this->assertSame(['submitted'], $this->actionsOf($r));
        $this->assertNotNull($r->first_submitted_at);
        $this->assertSame('社用車の購入', ApprovalRevision::where('request_id', $r->id)->sole()->snapshot['subject']);
    }

    /** 4.3 のケース 1: 申請者が部門長なら、部門長の確認を省いて審査から */
    public function test_a_head_applying_skips_the_head_step(): void
    {
        $w = $this->approvalWorld();
        $w['head']->approvalDepartments()->attach($w['dept']->id);
        $w['applicant'] = $w['head']->fresh();

        $r = $this->submittedFor($w);

        $this->assertSame(ApprovalStatus::Review, $r->status);
        $this->assertSame(['head' => 'skipped', 'review' => 'waiting', 'president' => 'pending'], $this->stepsOf($r));
        $this->assertSame(['submitted', 'head_skipped'], $this->actionsOf($r));
    }

    /** 4.3 のケース 2: 部門長が社長を兼ねていても省かない */
    public function test_a_head_who_is_also_the_president_is_not_skipped(): void
    {
        $w = $this->approvalWorld();
        $this->makePresident($w['head']);

        $this->assertSame(ApprovalStatus::HeadReview, $this->submittedFor($w)->status);
    }

    /** 審査は否でも社長へ回り、社長の可で番号が付いて完了する（4.2・6.3） */
    public function test_the_full_route_to_approval_with_a_number(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);
        $r->refresh();
        $this->assertSame(ApprovalStatus::Review, $r->status);

        $this->workflow->judgeReview($r, $w['reviewer'], $r->lock_version, ApprovalStepResult::Ng, '予算超過の恐れ');
        $r->refresh();
        $this->assertSame(ApprovalStatus::President, $r->status, '審査の「否」でも社長へ回る');

        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Approve, null);
        $r->refresh();

        $this->assertSame(ApprovalStatus::Approved, $r->status);
        $this->assertSame(ApprovalDecision::Approve, $r->decision);
        $this->assertSame('R8-J-001', $r->number);
        $this->assertNotNull($r->decided_at);
        $this->assertNotNull($r->finished_at);
        $this->assertSame(['head' => 'done', 'review' => 'done', 'president' => 'done'], $this->stepsOf($r));
        $this->assertSame(['submitted', 'head_approved', 'reviewed', 'president_approved'], $this->actionsOf($r));
    }

    /** 条可は番号が付き、申請者が確認するまで完了にしない（4.6） */
    public function test_a_conditional_approval_waits_for_the_applicant(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);

        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Conditional, '予算内に収めること');
        $r->refresh();
        $this->assertSame(ApprovalStatus::Condition, $r->status);
        $this->assertSame('R8-J-001', $r->number);
        $this->assertNull($r->finished_at);

        $this->workflow->confirmCondition($r, $w['applicant'], $r->lock_version, null);
        $r->refresh();
        $this->assertSame(ApprovalStatus::Approved, $r->status);
        $this->assertSame(ApprovalDecision::Conditional, $r->decision);
        $this->assertNotNull($r->finished_at);
        $this->assertSame('決裁済み（条可）', $r->statusLabel());
    }

    /** 否にも番号を付ける（6.3） */
    public function test_a_rejection_gets_a_number(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);

        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Reject, '時期を見直すこと');
        $r->refresh();

        $this->assertSame(ApprovalStatus::Rejected, $r->status);
        $this->assertSame('R8-J-001', $r->number);
    }

    /** 差戻しは申請者へ戻り、出し直すと最初から回り直す（4.4） */
    public function test_a_return_goes_back_and_resubmission_starts_over(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Return, '見積りを添付してください');
        $r->refresh();
        $this->assertSame(ApprovalStatus::Returned, $r->status);
        $this->assertSame(['head' => 'done', 'review' => 'cancelled', 'president' => 'cancelled'], $this->stepsOf($r));

        $r->update(['subject' => '社用車の購入（見積り添付）']);
        $this->workflow->submit($r, $w['applicant']);
        $r->refresh();

        $this->assertSame(ApprovalStatus::HeadReview, $r->status);
        $this->assertSame(2, $r->round);
        $this->assertSame(['head' => 'waiting', 'review' => 'pending', 'president' => 'pending'], $this->stepsOf($r));
        $this->assertSame('社用車の購入（見積り添付）', ApprovalRevision::where('request_id', $r->id)->where('round', 2)->sole()->snapshot['subject']);
        $this->assertSame(['submitted', 'head_returned', 'resubmitted'], $this->actionsOf($r));
    }

    /** 社長の差戻しも同じ（番号は付けない） */
    public function test_a_president_return_gets_no_number(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);

        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Return, '金額の根拠を');
        $r->refresh();

        $this->assertSame(ApprovalStatus::Returned, $r->status);
        $this->assertNull($r->number);
    }

    /** 出し直しは、出し直した時点の審査部門で回る（D11・D18） */
    public function test_resubmission_uses_the_current_review_department(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Return, '直して');

        $newDept = $this->approvalDepartment($w['company'], ['name' => '経理部']);
        $newDept->reviewers()->attach($this->baseUser()->id);
        $w['type']->update(['review_department_id' => $newDept->id]);

        $this->workflow->submit($r->refresh(), $w['applicant']);

        $this->assertSame($w['reviewDept']->id, ApprovalStep::where('request_id', $r->id)->where('round', 1)->where('kind', 'review')->value('department_id'));
        $this->assertSame($newDept->id, ApprovalStep::where('request_id', $r->id)->where('round', 2)->where('kind', 'review')->value('department_id'));
    }

    public static function commentRequired(): array
    {
        return [
            '部門長の差戻し' => ['head', ApprovalStepResult::Return, '「差戻し」にはコメントが必要です。'],
            '審査の保留'     => ['review', ApprovalStepResult::Hold, '「保留」にはコメントが必要です。'],
            '審査の否'       => ['review', ApprovalStepResult::Ng, '「否」にはコメントが必要です。'],
            '社長の条可'     => ['president', ApprovalStepResult::Conditional, '「条可」にはコメントが必要です。'],
            '社長の否'       => ['president', ApprovalStepResult::Reject, '「否」にはコメントが必要です。'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('commentRequired')]
    public function test_some_judgements_need_a_comment(string $kind, ApprovalStepResult $result, string $message): void
    {
        $w = $this->approvalWorld();
        $r = match ($kind) {
            'head'      => $this->submittedFor($w),
            'review'    => tap($this->submittedFor($w), fn ($r) => $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null))->refresh(),
            'president' => $this->toPresident($w),
        };
        $actor = ['head' => $w['head'], 'review' => $w['reviewer'], 'president' => $w['president']][$kind];
        $before = $r->lock_version;

        try {
            match ($kind) {
                'head'      => $this->workflow->judgeHead($r, $actor, $r->lock_version, $result, "　\n"),
                'review'    => $this->workflow->judgeReview($r, $actor, $r->lock_version, $result, null),
                'president' => $this->workflow->judgePresident($r, $actor, $r->lock_version, $result, ''),
            };
            $this->fail('コメントなしで通った');
        } catch (WorkflowRefused $e) {
            $this->assertSame($message, $e->getMessage());
        }

        $this->assertSame($before, $r->refresh()->lock_version, '断ったのに状態が進んだ');
    }

    /** 古い画面から押すと「すでに処理されています」（4.2・計画 §0.3） */
    public function test_a_stale_screen_is_refused(): void
    {
        $w     = $this->approvalWorld();
        $r     = $this->submittedFor($w);
        $stale = $r->lock_version;

        $this->workflow->judgeHead($r, $w['head'], $stale, ApprovalStepResult::Approve, null);

        $this->expectException(WorkflowConflict::class);
        $this->expectExceptionMessage('すでに処理されています。画面を開き直して、今の状態を確かめてください。');
        $this->workflow->judgeHead($r, $w['head'], $stale, ApprovalStepResult::Approve, null);
    }

    /**
     * 同時に押された 2 人目（計画 §0.3 の 2 段目）: 画面の lock_version が新しくても、状態を進める
     * UPDATE の条件（lock_version）に当たらなければ断る。
     *
     * ⚠ 1 段目（読み直した値との比較）をすり抜ける瞬間を作る: Workflow がトランザクションの中で
     *   読み直した**直後**に、別の人の操作が lock_version を進めた状況を retrieved の合図で起こす
     *   （RefreshDatabase が 1 段目のトランザクションを張るので、それより深いときだけ進める）。
     *   これが無いと、UPDATE の条件から lock_version を外す変異が全テスト緑になる。
     */
    public function test_a_simultaneous_second_press_is_refused_by_the_conditional_update(): void
    {
        $w      = $this->approvalWorld();
        $r      = $this->submittedFor($w);
        $before = $r->lock_version;
        $base   = DB::transactionLevel();

        ApprovalRequest::retrieved(function (ApprovalRequest $model) use ($base): void {
            if (DB::transactionLevel() > $base) {
                DB::table('approval_requests')->where('id', $model->id)->increment('lock_version');
            }
        });

        try {
            $this->workflow->judgeHead($r, $w['head'], $before, ApprovalStepResult::Approve, null);
            $this->fail('同時に押された 2 人目が通った');
        } catch (WorkflowConflict $e) {
            $this->assertSame(WorkflowConflict::MESSAGE, $e->getMessage());
        }

        $fresh = $r->fresh();
        $this->assertSame(ApprovalStatus::HeadReview, $fresh->status);
        $this->assertSame($before, $fresh->lock_version, '断ったのに状態が進んだ');
    }

    public function test_someone_who_is_not_assigned_cannot_judge(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->expectException(WorkflowRefused::class);
        $this->expectExceptionMessage('この申請を判断する権限がありません。');
        $this->workflow->judgeHead($r, $w['reviewer'], $r->lock_version, ApprovalStepResult::Approve, null);
    }

    /** D16: 申請のあとで申請者本人が部門長になっても、自分の申請には判断できない */
    public function test_nobody_judges_their_own_request(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $w['dept']->update(['head_user_id' => $w['applicant']->id]);

        $this->expectException(WorkflowRefused::class);
        $this->expectExceptionMessage('自分の申請には判断できません。');
        $this->workflow->judgeHead($r->refresh(), $w['applicant'], $r->lock_version, ApprovalStepResult::Approve, null);
    }

    /** 4.3 のケース 3: 申請者が審査担当者でも、自分の申請には意見を入れられない（ほかの担当者は入れられる） */
    public function test_a_reviewer_who_applied_leaves_it_to_the_others(): void
    {
        $w = $this->approvalWorld();
        $w['reviewDept']->reviewers()->attach($w['applicant']->id);
        $r = $this->submittedFor($w);
        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);
        $r->refresh();

        try {
            $this->workflow->judgeReview($r, $w['applicant'], $r->lock_version, ApprovalStepResult::Ok, null);
            $this->fail('自分の申請に意見を入れられた');
        } catch (WorkflowRefused $e) {
            $this->assertSame('自分の申請には判断できません。', $e->getMessage());
        }

        $this->workflow->judgeReview($r->refresh(), $w['reviewer'], $r->lock_version, ApprovalStepResult::Ok, null);
        $this->assertSame(ApprovalStatus::President, $r->refresh()->status);
    }

    /** 取り下げは社長の判断の前だけ（4.5）。待っている段階は打ち切る */
    public function test_the_applicant_can_withdraw_before_the_president_decides(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);

        $this->workflow->withdraw($r, $w['applicant'], $r->lock_version, null);
        $r->refresh();

        $this->assertSame(ApprovalStatus::Withdrawn, $r->status);
        $this->assertSame(['head' => 'done', 'review' => 'done', 'president' => 'cancelled'], $this->stepsOf($r));
        $this->assertSame('withdrawn', ApprovalHistory::where('request_id', $r->id)->orderByDesc('id')->value('action'));
    }

    public function test_a_decided_request_cannot_be_withdrawn(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Conditional, '条件');
        $r->refresh();

        $this->expectException(WorkflowRefused::class);
        $this->expectExceptionMessage('この申請は取り下げられる状態ではありません。');
        $this->workflow->withdraw($r, $w['applicant'], $r->lock_version, null);
    }

    /** 4.3 のケース 5・D23: 部門長を変えると、付け替えた申請も新しい部門長へ移る */
    public function test_changing_the_head_moves_waiting_requests(): void
    {
        $w       = $this->approvalWorld();
        $r       = $this->submittedFor($w);
        $stand   = $this->baseUser(['name' => '代理 担当']);
        $newHead = $this->baseUser(['name' => '新 部門長']);
        ApprovalStep::where('request_id', $r->id)->where('kind', 'head')->update(['assignee_user_id' => $stand->id]);   // 2b の付け替えの形

        $w['dept']->update(['head_user_id' => $newHead->id]);
        $this->workflow->headChanged($w['dept']->fresh(), $w['head']->id, $this->approvalAdmin());

        $this->assertNull(ApprovalStep::where('request_id', $r->id)->where('kind', 'head')->value('assignee_user_id'));
        $history = ApprovalHistory::where('request_id', $r->id)->where('action', 'head_changed')->sole();
        $this->assertSame(['from_user_id' => $stand->id, 'to_user_id' => $newHead->id], $history->meta);

        $this->workflow->judgeHead($r->refresh(), $newHead, $r->lock_version, ApprovalStepResult::Approve, null);
        $this->assertSame(ApprovalStatus::Review, $r->refresh()->status);
    }

    /** 4.3 のケース 6: 社長の指定が変わると、新しい社長が決裁する */
    public function test_a_new_president_takes_over(): void
    {
        $w   = $this->approvalWorld();
        $r   = $this->toPresident($w);
        $new = $this->makePresident($this->baseUser(['name' => '新 社長']));

        try {
            $this->workflow->judgePresident($r, $w['president']->fresh(), $r->lock_version, ApprovalStepResult::Approve, null);
            $this->fail('前の社長が決裁できた');
        } catch (WorkflowRefused $e) {
            $this->assertSame('この申請を判断する権限がありません。', $e->getMessage());
        }

        $this->workflow->judgePresident($r->refresh(), $new, $r->lock_version, ApprovalStepResult::Approve, null);
        $this->assertSame(ApprovalStatus::Approved, $r->refresh()->status);
    }

    /** 4.3 のケース 7: 審査担当者があとから増えれば、その人も意見を入れられる */
    public function test_a_reviewer_added_later_can_review(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);
        $late = $this->baseUser(['name' => '後から 担当']);
        $w['reviewDept']->reviewers()->attach($late->id);

        $this->workflow->judgeReview($r->refresh(), $late, $r->lock_version, ApprovalStepResult::Hold, '確認中');

        $this->assertSame(ApprovalStatus::President, $r->refresh()->status);
    }

    /** 取り消しで番号が残っていれば、同じ番号を使う（6.5・D21。2b の取り消しの前提） */
    public function test_an_existing_number_is_reused(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);
        DB::table('approval_requests')->where('id', $r->id)->update(['number' => 'R8-J-007', 'number_department_id' => $w['dept']->id, 'number_fiscal_year' => 2026, 'number_seq' => 7]);

        $this->workflow->judgePresident($r->refresh(), $w['president'], $r->lock_version, ApprovalStepResult::Approve, null);

        $this->assertSame('R8-J-007', $r->refresh()->number);
        $this->assertSame(0, ApprovalNumberSequence::count(), '番号があるのに連番を進めた');
    }

    /** 控えには名前と添付の一覧も残す（あとで名前が変わっても、その回の中身のまま見せる） */
    public function test_the_snapshot_keeps_names_and_attachments(): void
    {
        $w     = $this->approvalWorld();
        $draft = $this->draftFor($w);
        ApprovalAttachment::create([
            'request_id' => $draft->id, 'original_name' => '見積書.pdf', 'stored_path' => 'approvals/' . $draft->id . '/a.pdf',
            'mime' => 'application/pdf', 'size' => 1234, 'uploaded_by' => $w['applicant']->id, 'added_round' => 1,
        ]);

        $this->workflow->submit($draft, $w['applicant']);
        $snapshot = ApprovalRevision::where('request_id', $draft->id)->sole()->snapshot;

        $this->assertSame('住宅事業部', $snapshot['department']['name']);
        $this->assertSame($w['type']->name, $snapshot['type']['name']);
        $this->assertSame([['id' => ApprovalAttachment::sole()->id, 'name' => '見積書.pdf', 'size' => 1234]], $snapshot['attachments']);
    }

    /** 提出の条件に当たれば断り、何も書かない（D4 の例） */
    public function test_submission_is_refused_with_all_reasons(): void
    {
        $w = $this->approvalWorld();
        $this->makePresident($w['applicant']);
        $draft = $this->draftFor($w);

        try {
            $this->workflow->submit($draft, $w['applicant']->fresh());
            $this->fail('社長が提出できた');
        } catch (WorkflowRefused $e) {
            $this->assertContains('社長に指定されている人は申請できません。', $e->reasons);
        }

        $this->assertSame(ApprovalStatus::Draft, $draft->refresh()->status);
        $this->assertSame(0, ApprovalHistory::count());
    }

    /**
     * 4.8 の表（設計書 §5.8）: 状態ごとに、通る操作と断る操作。
     *
     * ⚠ 表から機械的に組み立てる（設計書 §6）。状態か操作を足したら、ここに足すまで下のテストが全件で落ちる。
     */
    private const ALLOWED = [
        'submit'           => [ApprovalStatus::Draft, ApprovalStatus::Returned],
        'judgeHead'        => [ApprovalStatus::HeadReview],
        'judgeReview'      => [ApprovalStatus::Review],
        'judgePresident'   => [ApprovalStatus::President],
        'confirmCondition' => [ApprovalStatus::Condition],
        'withdraw'         => [ApprovalStatus::HeadReview, ApprovalStatus::Review, ApprovalStatus::President, ApprovalStatus::Returned],
    ];

    /** その状態の申請を、本物の操作を順にたどって作る（状態を直接書き込まない） */
    private function requestIn(ApprovalStatus $status, array $w): ApprovalRequest
    {
        $r    = $this->draftFor($w);
        $path = match ($status) {
            ApprovalStatus::Draft      => [],
            ApprovalStatus::HeadReview => ['submit'],
            ApprovalStatus::Review     => ['submit', 'head'],
            ApprovalStatus::President  => ['submit', 'head', 'review'],
            ApprovalStatus::Returned   => ['submit', 'return'],
            ApprovalStatus::Condition  => ['submit', 'head', 'review', 'conditional'],
            ApprovalStatus::Approved   => ['submit', 'head', 'review', 'approve'],
            ApprovalStatus::Rejected   => ['submit', 'head', 'review', 'reject'],
            ApprovalStatus::Withdrawn  => ['submit', 'withdraw'],
        };

        foreach ($path as $step) {
            $r->refresh();
            match ($step) {
                'submit'      => $this->workflow->submit($r, $w['applicant']),
                'head'        => $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null),
                'return'      => $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Return, '直してください'),
                'review'      => $this->workflow->judgeReview($r, $w['reviewer'], $r->lock_version, ApprovalStepResult::Ok, null),
                'approve'     => $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Approve, null),
                'conditional' => $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Conditional, '条件'),
                'reject'      => $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Reject, '見送り'),
                'withdraw'    => $this->workflow->withdraw($r, $w['applicant'], $r->lock_version, null),
            };
        }

        $r->refresh();
        $this->assertSame($status, $r->status, "前提: {$status->value} の申請を作れていない");

        return $r;
    }

    /** 表のすべての組み合わせ（9 状態 × 6 操作）で、通るものは通り、それ以外は WorkflowRefused で断る */
    public function test_every_state_accepts_only_the_operations_in_the_table(): void
    {
        $w        = $this->approvalWorld();
        $problems = [];

        foreach (ApprovalStatus::cases() as $status) {
            foreach (self::ALLOWED as $operation => $allowedIn) {
                $r       = $this->requestIn($status, $w);
                $allowed = in_array($status, $allowedIn, true);

                try {
                    match ($operation) {
                        'submit'           => $this->workflow->submit($r, $w['applicant']),
                        'judgeHead'        => $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null),
                        'judgeReview'      => $this->workflow->judgeReview($r, $w['reviewer'], $r->lock_version, ApprovalStepResult::Ok, null),
                        'judgePresident'   => $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Approve, null),
                        'confirmCondition' => $this->workflow->confirmCondition($r, $w['applicant'], $r->lock_version, null),
                        'withdraw'         => $this->workflow->withdraw($r, $w['applicant'], $r->lock_version, null),
                    };
                    if (! $allowed) {
                        $problems[] = "{$status->value} で {$operation} が通った（表では断る）";
                    }
                } catch (WorkflowRefused $e) {
                    if ($allowed) {
                        $problems[] = "{$status->value} で {$operation} が断られた（表では通る）: {$e->getMessage()}";
                    }
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter WorkflowTest
```

Expected: FAIL（`Workflow` が無い）

- [ ] **Step 3: 部品を書く**

`app/Support/Approval/RequestPermissions.php`

```php
<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 「この人は今この申請に何ができるか」（設計書 §5.1・§5.8・§5.12）。
 *
 * **画面のボタンの出し分けと、POST の受け付けの両方がこれを使う**（2 か所で判定しない）。
 * 見てよいかどうか（`RequestVisibility`）は別。ここは「見られる」前提で操作だけを見る。
 */
final class RequestPermissions
{
    private ?ApprovalStep $waiting = null;
    private bool $waitingLoaded = false;

    private function __construct(private readonly User $user, private readonly ApprovalRequest $request)
    {
    }

    public static function for(User $user, ApprovalRequest $request): self
    {
        return new self($user, $request);
    }

    public function isApplicant(): bool
    {
        return $this->request->user_id === $this->user->id;
    }

    /** 中身と添付を直せる（下書き・差戻し中の申請者。要件 5.3） */
    public function canEdit(): bool
    {
        return $this->isApplicant() && $this->request->status->isEditable();
    }

    /** 下書きを消せる（一度も提出していない下書きだけ。要件 4.5） */
    public function canDelete(): bool
    {
        return $this->isApplicant() && $this->request->status === ApprovalStatus::Draft && $this->request->round === 0;
    }

    /** 取り下げられる（社長の判断の前。要件 4.5） */
    public function canWithdraw(): bool
    {
        return $this->isApplicant() && $this->request->status->isWithdrawable();
    }

    /** 条件を確認できる（要件 4.6） */
    public function canConfirmCondition(): bool
    {
        return $this->isApplicant() && $this->request->status === ApprovalStatus::Condition;
    }

    /** 今の回で待っている段階（無ければ null） */
    public function waitingStep(): ?ApprovalStep
    {
        if (! $this->waitingLoaded) {
            $this->waiting = $this->request->status->isInCirculation()
                ? ApprovalStep::with('department')
                    ->where('request_id', $this->request->id)
                    ->where('round', $this->request->round)
                    ->where('status', ApprovalStepStatus::Waiting->value)
                    ->first()
                : null;
            $this->waitingLoaded = true;
        }

        return $this->waiting;
    }

    /** この人がその段階の担当に当たるか（自分の申請かどうかはここでは見ない） */
    public function isAssigneeOf(ApprovalStep $step): bool
    {
        return match ($step->kind) {
            // 付け替えがあればその人、無ければ申請部門の今の部門長（要件 4.3 のケース 5）
            ApprovalStepKind::Head => $step->assignee_user_id !== null
                ? $step->assignee_user_id === $this->user->id
                : $step->department?->head_user_id === $this->user->id,
            // その審査部門の今の審査担当者（ケース 7）
            ApprovalStepKind::Review => DB::table('approval_reviewers')
                ->where('department_id', $step->department_id)
                ->where('user_id', $this->user->id)
                ->exists(),
            // 今の社長（ケース 6）
            ApprovalStepKind::President => $this->user->isApprovalPresident(),
        };
    }

    /** 判断できる段階（担当で、かつ自分の申請でない。D16）。できなければ null */
    public function judgeableStep(): ?ApprovalStep
    {
        $step = $this->waitingStep();

        return ($step !== null && $this->isAssigneeOf($step) && ! $this->isApplicant()) ? $step : null;
    }

    /** 担当に当たるのに判断できない理由（画面に出す）。担当でなければ null */
    public function judgeRefusal(): ?string
    {
        $step = $this->waitingStep();

        return ($step !== null && $this->isAssigneeOf($step) && $this->isApplicant())
            ? '自分の申請には判断できません。'
            : null;
    }
}
```

`app/Support/Approval/HistoryRecorder.php`

```php
<?php

namespace App\Support\Approval;

use App\Models\ApprovalHistory;
use App\Models\ApprovalRequest;
use App\Models\User;

/**
 * 操作の記録を 1 行足す（要件 14.2・設計書 §5.11）。IP と端末の情報をそろえるため 1 本化する。
 *
 * ⚠ 省略・交代による移動など、人の操作でないものは `$actor` を null で渡す。
 */
final class HistoryRecorder
{
    /** @param array<string, mixed> $attributes from_status / to_status / step_id / result / comment / reason / meta */
    public static function record(ApprovalRequest $request, string $action, ?User $actor, array $attributes = []): ApprovalHistory
    {
        return ApprovalHistory::create(array_merge($attributes, [
            'request_id'    => $request->id,
            'round'         => $request->round,
            'actor_user_id' => $actor?->id,
            'action'        => $action,
            'ip_address'    => request()->ip(),
            'user_agent'    => mb_substr((string) request()->userAgent(), 0, 255),
        ]));
    }
}
```

`app/Support/Approval/RequestSnapshot.php`

```php
<?php

namespace App\Support\Approval;

use App\Models\ApprovalAttachment;
use App\Models\ApprovalRequest;

/**
 * 提出ごとの中身の控え（設計書 §5.11）。2b の変更点と履歴がこれを比べる。
 *
 * ⚠ 名前（種類名・部門名・ファイル名）も一緒に控える。あとで種類や部門の名前が変わっても、
 *   その回に出した中身のまま見せるため。段階5 で明細表の行と追加の入力欄を足す。
 */
final class RequestSnapshot
{
    /** @return array<string, mixed> */
    public static function make(ApprovalRequest $request): array
    {
        $request->loadMissing(['type', 'department']);

        return [
            'type'            => ['id' => $request->type_id, 'name' => $request->type?->name],
            'department'      => ['id' => $request->department_id, 'name' => $request->department?->name],
            'subject'         => $request->subject,
            'amount'          => $request->amount,
            'schedule'        => $request->schedule,
            'body'            => $request->body,
            'related_numbers' => $request->related_numbers ?? [],
            'attachments'     => $request->attachments()->get()
                ->map(fn (ApprovalAttachment $a) => ['id' => $a->id, 'name' => $a->original_name, 'size' => $a->size])
                ->all(),
        ];
    }
}
```

`app/Support/Approval/WorkflowConflict.php`

```php
<?php

namespace App\Support\Approval;

use RuntimeException;

/** ほかの人（または別のタブの自分）が先に操作した（要件 4.2・計画 §0.3） */
final class WorkflowConflict extends RuntimeException
{
    public const MESSAGE = 'すでに処理されています。画面を開き直して、今の状態を確かめてください。';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
```

`app/Support/Approval/WorkflowRefused.php`

```php
<?php

namespace App\Support\Approval;

use RuntimeException;

/** 今の状態・権限ではできない操作（理由を画面に出す） */
final class WorkflowRefused extends RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct($reasons[0] ?? 'この操作はできません。');
    }
}
```

`app/Support/Approval/Workflow.php`

```php
<?php

namespace App\Support\Approval;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\ApprovalStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 状態の移り変わり（要件 4 章・設計書 §5.8）。**申請の状態を変えるのはここだけ。**
 *
 * 流れは 提出 → 部門長 → 審査 → 社長 →（条可なら）条件確認。操作はすべて
 *   1. トランザクションの中で申請を読み直す
 *   2. 画面の `lock_version` と比べる（古い画面から押した操作を断る）
 *   3. `RequestPermissions` で権限を確かめる
 *   4. `lock_version` を条件にした 1 回の UPDATE で状態を進める（同時に押された 2 人目を断る）
 *   5. 段階と記録を書く
 * の順に進む（計画 §0.3）。
 *
 * ⚠ 行のロックは「申請の行 → 連番の行」の順にそろえる（社長の判断で採番するとき）。
 */
final class Workflow
{
    /** 提出・出し直し（要件 4.1・4.3・4.4） */
    public function submit(ApprovalRequest $request, User $actor): void
    {
        DB::transaction(function () use ($request, $actor): void {
            $request->refresh();

            if (! RequestPermissions::for($actor, $request)->canEdit()) {
                throw new WorkflowRefused(['この申請は提出できる状態ではありません。']);
            }

            $reasons = SubmitChecker::reasons($request, $actor);
            if ($reasons !== []) {
                throw new WorkflowRefused($reasons);
            }

            $from       = $request->status;
            $round      = $request->round + 1;
            $department = $request->department;
            $reviewDept = $request->type->review_department_id;   // 提出の時点の審査部門（D11・D18）
            // 申請者が申請部門の部門長なら部門長の確認を省く（4.3 のケース 1）。部門長が社長を兼ねていても省かない（ケース 2）
            $skipHead   = $department->head_user_id === $actor->id;
            $to         = $skipHead ? ApprovalStatus::Review : ApprovalStatus::HeadReview;
            $now        = now();

            $this->move($request, $request->lock_version, $to, [
                'round'              => $round,
                'first_submitted_at' => $request->first_submitted_at ?? $now,
                'last_submitted_at'  => $now,
            ]);

            ApprovalRevision::create([
                'request_id'   => $request->id,
                'round'        => $round,
                'snapshot'     => RequestSnapshot::make($request),
                'submitted_by' => $actor->id,
            ]);

            $head = ApprovalStep::create([
                'request_id'    => $request->id,
                'round'         => $round,
                'kind'          => ApprovalStepKind::Head,
                'department_id' => $department->id,
                'status'        => $skipHead ? ApprovalStepStatus::Skipped : ApprovalStepStatus::Waiting,
                'arrived_at'    => $skipHead ? null : $now,
            ]);

            ApprovalStep::create([
                'request_id'    => $request->id,
                'round'         => $round,
                'kind'          => ApprovalStepKind::Review,
                'department_id' => $reviewDept,
                'status'        => $skipHead ? ApprovalStepStatus::Waiting : ApprovalStepStatus::Pending,
                'arrived_at'    => $skipHead ? $now : null,
            ]);

            ApprovalStep::create([
                'request_id' => $request->id,
                'round'      => $round,
                'kind'       => ApprovalStepKind::President,
                'status'     => ApprovalStepStatus::Pending,
            ]);

            HistoryRecorder::record($request, $round === 1 ? 'submitted' : 'resubmitted', $actor, [
                'from_status' => $from->value,
                'to_status'   => $to->value,
            ]);

            if ($skipHead) {
                HistoryRecorder::record($request, 'head_skipped', null, ['step_id' => $head->id]);
            }
        });
    }

    public function judgeHead(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStepResult $result, ?string $comment): void
    {
        $this->judge($request, $actor, $lockVersion, ApprovalStepKind::Head, $result, $comment);
    }

    public function judgeReview(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStepResult $result, ?string $comment): void
    {
        $this->judge($request, $actor, $lockVersion, ApprovalStepKind::Review, $result, $comment);
    }

    public function judgePresident(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStepResult $result, ?string $comment): void
    {
        $this->judge($request, $actor, $lockVersion, ApprovalStepKind::President, $result, $comment);
    }

    /** 条件の確認（要件 4.6） */
    public function confirmCondition(ApprovalRequest $request, User $actor, int $lockVersion, ?string $comment): void
    {
        DB::transaction(function () use ($request, $actor, $lockVersion, $comment): void {
            $request->refresh();
            $this->assertFresh($request, $lockVersion);

            if (! RequestPermissions::for($actor, $request)->canConfirmCondition()) {
                throw new WorkflowRefused(['条件を確認できる状態ではありません。']);
            }

            $this->move($request, $lockVersion, ApprovalStatus::Approved, ['finished_at' => now()]);

            HistoryRecorder::record($request, 'condition_confirmed', $actor, [
                'from_status' => ApprovalStatus::Condition->value,
                'to_status'   => ApprovalStatus::Approved->value,
                'comment'     => self::cleanComment($comment),
            ]);
        });
    }

    /** 取り下げ（要件 4.5。申請者のコメントは任意。D17） */
    public function withdraw(ApprovalRequest $request, User $actor, int $lockVersion, ?string $comment): void
    {
        DB::transaction(function () use ($request, $actor, $lockVersion, $comment): void {
            $request->refresh();
            $this->assertFresh($request, $lockVersion);

            if (! RequestPermissions::for($actor, $request)->canWithdraw()) {
                throw new WorkflowRefused(['この申請は取り下げられる状態ではありません。']);
            }

            $from = $request->status;
            $this->move($request, $lockVersion, ApprovalStatus::Withdrawn);
            $this->cancelRest($request);

            HistoryRecorder::record($request, 'withdrawn', $actor, [
                'from_status' => $from->value,
                'to_status'   => ApprovalStatus::Withdrawn->value,
                'comment'     => self::cleanComment($comment),
            ]);
        });
    }

    /**
     * 部門長の交代（要件 4.3 のケース 5・D23）。部門の管理が部門長を変えた**あと**に呼ぶ。
     *
     * 部門長の確認を待っている申請は、付け替えていても新しい部門長へ移す（付け替えを空に戻す）。
     */
    public function headChanged(ApprovalDepartment $department, ?int $oldHeadId, User $admin): void
    {
        $steps = ApprovalStep::with('request')
            ->where('kind', ApprovalStepKind::Head->value)
            ->where('status', ApprovalStepStatus::Waiting->value)
            ->where('department_id', $department->id)
            ->get();

        foreach ($steps as $step) {
            $before = $step->assignee_user_id ?? $oldHeadId;

            if ($step->assignee_user_id !== null) {
                $step->update(['assignee_user_id' => null]);
            }

            HistoryRecorder::record($step->request, 'head_changed', $admin, [
                'step_id' => $step->id,
                'meta'    => ['from_user_id' => $before, 'to_user_id' => $department->head_user_id],
            ]);
        }
    }

    private function judge(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStepKind $kind, ApprovalStepResult $result, ?string $comment): void
    {
        DB::transaction(function () use ($request, $actor, $lockVersion, $kind, $result, $comment): void {
            $request->refresh();
            // ⚠ 権限より先に見る。先に誰かが判断した画面から押すと、段階が進んで権限が無くなっているので、
            //   先に権限を見ると「権限がありません」と出て何が起きたか分からない
            $this->assertFresh($request, $lockVersion);

            $permissions = RequestPermissions::for($actor, $request);
            $step        = $permissions->judgeableStep();

            if ($step === null || $step->kind !== $kind) {
                throw new WorkflowRefused([$permissions->judgeRefusal() ?? 'この申請を判断する権限がありません。']);
            }

            if (! in_array($result, ApprovalStepResult::allowedFor($kind), true)) {
                throw new WorkflowRefused(['選べない判断です。']);
            }

            $comment = self::cleanComment($comment);
            if ($result->requiresComment() && $comment === null) {
                throw new WorkflowRefused(['「' . $result->labelFor($kind) . '」にはコメントが必要です。']);
            }

            match ($kind) {
                ApprovalStepKind::Head      => $this->afterHead($request, $actor, $lockVersion, $step, $result, $comment),
                ApprovalStepKind::Review    => $this->afterReview($request, $actor, $lockVersion, $step, $result, $comment),
                ApprovalStepKind::President => $this->afterPresident($request, $actor, $lockVersion, $step, $result, $comment),
            };
        });
    }

    private function afterHead(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStep $step, ApprovalStepResult $result, ?string $comment): void
    {
        $from = $request->status;

        if ($result === ApprovalStepResult::Approve) {
            $this->move($request, $lockVersion, ApprovalStatus::Review);
            $this->finishStep($step, $actor, $result, $comment);
            $this->arrive($request, ApprovalStepKind::Review);
            $this->recordJudgement($request, 'head_approved', $actor, $from, $step, $result, $comment);

            return;
        }

        $this->move($request, $lockVersion, ApprovalStatus::Returned);
        $this->finishStep($step, $actor, $result, $comment);
        $this->cancelRest($request);
        $this->recordJudgement($request, 'head_returned', $actor, $from, $step, $result, $comment);
    }

    private function afterReview(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStep $step, ApprovalStepResult $result, ?string $comment): void
    {
        $from = $request->status;

        // 意見は可・保留・否のどれでも社長へ回す（要件 4.2）
        $this->move($request, $lockVersion, ApprovalStatus::President);
        $this->finishStep($step, $actor, $result, $comment);
        $this->arrive($request, ApprovalStepKind::President);
        $this->recordJudgement($request, 'reviewed', $actor, $from, $step, $result, $comment);
    }

    private function afterPresident(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStep $step, ApprovalStepResult $result, ?string $comment): void
    {
        $from = $request->status;

        if ($result === ApprovalStepResult::Return) {
            $this->move($request, $lockVersion, ApprovalStatus::Returned);
            $this->finishStep($step, $actor, $result, $comment);
            $this->recordJudgement($request, 'president_returned', $actor, $from, $step, $result, $comment);

            return;
        }

        [$decision, $to, $action] = match ($result) {
            ApprovalStepResult::Approve     => [ApprovalDecision::Approve, ApprovalStatus::Approved, 'president_approved'],
            ApprovalStepResult::Conditional => [ApprovalDecision::Conditional, ApprovalStatus::Condition, 'president_conditional'],
            ApprovalStepResult::Reject      => [ApprovalDecision::Reject, ApprovalStatus::Rejected, 'president_rejected'],
        };

        $now = now();

        // ⚠ 申請の行を先に進めて（ロック）から採番する（ロックの順をそろえる）
        $this->move($request, $lockVersion, $to, [
            'decision'     => $decision->value,
            'decided_at'   => $now,
            'finished_at'  => $to === ApprovalStatus::Condition ? null : $now,
        ]);

        // 取り消しで番号が残っていればそのまま使う（要件 6.5・D21）
        if ($request->number === null) {
            $issued = ApprovalNumber::issue($request->department()->with('company')->firstOrFail(), $now);

            ApprovalRequest::whereKey($request->id)->update([
                'number'               => $issued['number'],
                'number_department_id' => $request->department_id,
                'number_fiscal_year'   => $issued['fiscal_year'],
                'number_seq'           => $issued['seq'],
            ]);
            $request->refresh();
        }

        $this->finishStep($step, $actor, $result, $comment);
        $this->recordJudgement($request, $action, $actor, $from, $step, $result, $comment);
    }

    private function assertFresh(ApprovalRequest $request, int $lockVersion): void
    {
        if ($request->lock_version !== $lockVersion) {
            throw new WorkflowConflict();
        }
    }

    /**
     * 状態を進める。`lock_version` を条件にした 1 回の UPDATE（当たらなければ先を越された）。
     *
     * @param array<string, mixed> $extra DB に書く生の値（enum は ->value で渡す）
     */
    private function move(ApprovalRequest $request, int $lockVersion, ApprovalStatus $to, array $extra = []): void
    {
        $now = now();

        $affected = ApprovalRequest::whereKey($request->id)
            ->where('lock_version', $lockVersion)
            ->update(array_merge($extra, [
                'status'            => $to->value,
                'status_changed_at' => $now,
                'lock_version'      => $lockVersion + 1,
                'updated_at'        => $now,
            ]));

        if ($affected !== 1) {
            throw new WorkflowConflict();
        }

        $request->refresh();
    }

    private function finishStep(ApprovalStep $step, User $actor, ApprovalStepResult $result, ?string $comment): void
    {
        $step->update([
            'status'        => ApprovalStepStatus::Done,
            'acted_at'      => now(),
            'actor_user_id' => $actor->id,
            'result'        => $result,
            'comment'       => $comment,
        ]);
    }

    /** 今の回のその段階を「待ち」にして届いた日時を入れる */
    private function arrive(ApprovalRequest $request, ApprovalStepKind $kind): void
    {
        $now = now();

        ApprovalStep::where('request_id', $request->id)
            ->where('round', $request->round)
            ->where('kind', $kind->value)
            ->update(['status' => ApprovalStepStatus::Waiting->value, 'arrived_at' => $now, 'updated_at' => $now]);
    }

    /** 今の回の残りの段階を打ち切る（差戻し・取り下げ） */
    private function cancelRest(ApprovalRequest $request): void
    {
        ApprovalStep::where('request_id', $request->id)
            ->where('round', $request->round)
            ->whereIn('status', [ApprovalStepStatus::Pending->value, ApprovalStepStatus::Waiting->value])
            ->update(['status' => ApprovalStepStatus::Cancelled->value, 'updated_at' => now()]);
    }

    private function recordJudgement(ApprovalRequest $request, string $action, User $actor, ApprovalStatus $from, ApprovalStep $step, ApprovalStepResult $result, ?string $comment): void
    {
        HistoryRecorder::record($request, $action, $actor, [
            'from_status' => $from->value,
            'to_status'   => $request->status->value,
            'step_id'     => $step->id,
            'result'      => $result->value,
            'comment'     => $comment,
        ]);
    }

    private static function cleanComment(?string $comment): ?string
    {
        $comment = $comment === null ? null : preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $comment);

        return ($comment === null || $comment === '') ? null : $comment;
    }
}
```

- [ ] **Step 4: 時計の走査に登録する**

`tests/Feature/ClockReadScanTest.php` の `ALLOWED` に 1 行足す（`now(` は 7 件: 提出・条件確認・社長の判断・状態を進める・段階を終える・段階が届く・打ち切り）:

```php
        'app/Support/Approval/Workflow.php'                   => [7, '提出・届いた・判断・完了・状態が変わった瞬間と updated_at（すべて TIMESTAMP 列。画面では JapanTime で日本時間に直して出す）'],
```

- [ ] **Step 5: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'WorkflowTest|ClockReadScanTest|StoredTimestampDisplayScanTest'
```

Expected: PASS

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add app/Support/Approval tests/Feature/ClockReadScanTest.php tests/Feature/Approval/Phase2/WorkflowTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
feat(approval): 提出から社長の決裁・条件確認・取り下げまでの移り変わりを作る

状態を変えるのは Workflow だけにし、画面の lock_version を条件にした 1 回の UPDATE で
同時操作と古い画面を断る。申請者が部門長なら部門長を省き、自分の申請には判断できない。
提出ごとに中身を控え、操作をすべて記録する。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 8: 見られる範囲（要件 7 章）

**Files:**
- Create: `app/Support/Approval/RequestVisibility.php`
- Test: `tests/Feature/Approval/Phase2/RequestVisibilityTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/RequestVisibilityTest.php`

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\Approval\RequestVisibility;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 見られる範囲（要件 7 章・設計書 §5.10・D19）。
 *
 * ⚠ 1 件の判定（canView）と一覧（apply）が**同じ答え**を返すことを毎回確かめる（規則は 1 か所）。
 */
class RequestVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workflow = app(Workflow::class);
    }

    /** @return list<int> */
    private function visibleIds(User $user): array
    {
        return RequestVisibility::apply(ApprovalRequest::query(), $user)->orderBy('id')->pluck('id')->all();
    }

    /** 見られるかどうか（一覧と 1 件の判定が食い違えば落とす） */
    private function sees(User $user, ApprovalRequest $request): bool
    {
        $inList = in_array($request->id, $this->visibleIds($user), true);
        $this->assertSame($inList, RequestVisibility::canView($user, $request), "一覧と 1 件の判定が食い違う（request {$request->id}・user {$user->id}）");

        return $inList;
    }

    private function approveAsHead(array $w, ApprovalRequest $r): void
    {
        $this->workflow->judgeHead($r->refresh(), $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);
    }

    public function test_the_applicant_sees_own_requests_including_drafts(): void
    {
        $w = $this->approvalWorld();

        $this->assertTrue($this->sees($w['applicant'], $this->draftFor($w)));
        $this->assertTrue($this->sees($w['applicant'], $this->submittedFor($w)));
    }

    /** 他人の下書きは誰も見られない（全件閲覧者・決裁の管理者・部門長・社長も） */
    public function test_nobody_else_sees_a_draft(): void
    {
        $w     = $this->approvalWorld();
        $draft = $this->draftFor($w);

        foreach ([$this->approvalAdmin(), $this->viewAllUser(), $w['head'], $w['president'], $w['reviewer']] as $user) {
            $this->assertFalse($this->sees($user, $draft), "{$user->name} が他人の下書きを見られる");
        }
    }

    /** 申請部門の今の部門長は、その部門のすべての申請を見る（過去分を含む） */
    public function test_the_current_head_sees_every_request_of_the_department(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->assertTrue($this->sees($w['head'], $r));

        $newHead = $this->baseUser(['name' => '新 部門長']);
        $w['dept']->update(['head_user_id' => $newHead->id]);

        $this->assertTrue($this->sees($newHead, $r), '新しい部門長が過去の申請を見られない');
        $this->assertFalse($this->sees($w['head'], $r), '判断していない前の部門長が見られる');
    }

    /** 判断した人は、担当を外れた後も見られる */
    public function test_people_who_judged_keep_seeing(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $this->approveAsHead($w, $r);

        $w['dept']->update(['head_user_id' => $this->baseUser()->id]);

        $this->assertTrue($this->sees($w['head'], $r));
    }

    /** 審査担当者は、審査の段階が届いた申請だけ。届く前は見えない。一度届けばその後もずっと（D19） */
    public function test_reviewers_see_requests_that_reached_review(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->assertFalse($this->sees($w['reviewer'], $r), '審査に届く前から見える');

        $this->approveAsHead($w, $r);
        $this->assertTrue($this->sees($w['reviewer'], $r->refresh()));

        // 社長が差し戻し、出し直した直後（審査はまだ届いていない回）も見える（D19）
        $this->workflow->judgeReview($r->refresh(), $w['reviewer'], $r->lock_version, ApprovalStepResult::Ok, null);
        $this->workflow->judgePresident($r->refresh(), $w['president'], $r->lock_version, ApprovalStepResult::Return, '直して');
        $this->workflow->submit($r->refresh(), $w['applicant']);

        $this->assertTrue($this->sees($this->addReviewer($w), $r->refresh()), '今の審査担当者が、一度届いた申請を見られない');
    }

    private function addReviewer(array $w): User
    {
        $another = $this->baseUser(['name' => '追加 審査']);
        $w['reviewDept']->reviewers()->attach($another->id);

        return $another;
    }

    /** 社長は、社長の段階が届いた申請だけ */
    public function test_the_president_sees_requests_that_reached_the_president(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->assertFalse($this->sees($w['president'], $r));

        $this->approveAsHead($w, $r);
        $this->workflow->judgeReview($r->refresh(), $w['reviewer'], $r->lock_version, ApprovalStepResult::Ok, null);

        $this->assertTrue($this->sees($w['president'], $r->refresh()));
    }

    /** 全件閲覧者・決裁の管理者はすべて（下書きを除く） */
    public function test_view_all_users_and_admins_see_every_submitted_request(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->assertTrue($this->sees($this->viewAllUser(), $r));
        $this->assertTrue($this->sees($this->approvalAdmin(), $r));
    }

    /** 同じ部署の同僚でも、関わっていなければ見えない */
    public function test_a_colleague_in_the_same_department_does_not_see(): void
    {
        $w         = $this->approvalWorld();
        $r         = $this->submittedFor($w);
        $colleague = $this->approvalOnlyUser(['name' => '同僚']);
        $colleague->approvalDepartments()->attach($w['dept']->id);

        $this->assertFalse($this->sees($colleague->fresh(), $r));
    }

    /** 付け替えられた担当（2b）は、その段階が届いた申請を見る */
    public function test_a_reassigned_head_sees_the_request(): void
    {
        $w     = $this->approvalWorld();
        $r     = $this->submittedFor($w);
        $stand = $this->baseUser(['name' => '代理']);
        ApprovalStep::where('request_id', $r->id)->where('kind', 'head')->update(['assignee_user_id' => $stand->id]);

        $this->assertTrue($this->sees($stand, $r));
    }
}
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter RequestVisibilityTest
```

Expected: FAIL（`RequestVisibility` が無い）

- [ ] **Step 3: 部品を書く**

`app/Support/Approval/RequestVisibility.php`

```php
<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * 見られる範囲（要件 7 章・設計書 §5.10）。**規則はここ 1 か所。**
 *
 * 1 件の判定（`canView`）も一覧の絞り込み（`apply`）も、同じ `apply()` から作る
 * （2 か所に書くと、画面と一覧で見える申請が食い違う）。
 *
 * ⚠ 他人の下書きは誰も見られない（全件閲覧者・決裁の管理者も）。
 * ⚠ 部門長・審査担当者・社長は「今の」担当で判定する。担当を外れた人は、自分が判断した申請だけ見られる。
 */
final class RequestVisibility
{
    public static function canView(User $user, ApprovalRequest $request): bool
    {
        return self::apply(ApprovalRequest::query()->whereKey($request->getKey()), $user)->exists();
    }

    /** 見られる申請に絞る（`approval_requests` を主にしたクエリに使う） */
    public static function apply(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user): void {
            // 申請者は自分の申請（下書きを含む）
            $q->where('approval_requests.user_id', $user->id)
              // ほかの人は下書きを見られない
              ->orWhere(function (Builder $q) use ($user): void {
                  $q->where('approval_requests.status', '!=', ApprovalStatus::Draft->value)
                    ->where(fn (Builder $q) => self::othersRules($q, $user));
              });
        });
    }

    private static function othersRules(Builder $q, User $user): void
    {
        // 全件閲覧者・決裁の管理者はすべて（下書きを除く）
        if ($user->canViewAllApprovals() || $user->isApprovalAdmin()) {
            $q->whereRaw('1 = 1');

            return;
        }

        // 申請部門の今の部門長
        $q->whereIn('approval_requests.department_id', ApprovalDepartment::query()->select('id')->where('head_user_id', $user->id));

        // 審査部門の今の審査担当者（審査の段階が一度でも届いた。D19）
        $q->orWhereExists(function (QueryBuilder $s) use ($user): void {
            self::stepsOfThisRequest($s)
                ->where('approval_steps.kind', ApprovalStepKind::Review->value)
                ->whereNotNull('approval_steps.arrived_at')
                ->whereIn('approval_steps.department_id', DB::table('approval_reviewers')->select('department_id')->where('user_id', $user->id));
        });

        // 今の社長（社長の段階が一度でも届いた。D19）
        if ($user->isApprovalPresident()) {
            $q->orWhereExists(function (QueryBuilder $s): void {
                self::stepsOfThisRequest($s)
                    ->where('approval_steps.kind', ApprovalStepKind::President->value)
                    ->whereNotNull('approval_steps.arrived_at');
            });
        }

        // 付け替えられた担当（その段階が届いた。2b の付け替えで入る）
        $q->orWhereExists(function (QueryBuilder $s) use ($user): void {
            self::stepsOfThisRequest($s)
                ->where('approval_steps.assignee_user_id', $user->id)
                ->whereNotNull('approval_steps.arrived_at');
        });

        // 判断した人（担当を外れた後も。前の回の判断を含む）
        $q->orWhereExists(function (QueryBuilder $s) use ($user): void {
            self::stepsOfThisRequest($s)->where('approval_steps.actor_user_id', $user->id);
        });
    }

    private static function stepsOfThisRequest(QueryBuilder $s): QueryBuilder
    {
        return $s->selectRaw('1')->from('approval_steps')->whereColumn('approval_steps.request_id', 'approval_requests.id');
    }
}
```

- [ ] **Step 4: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter RequestVisibilityTest
```

Expected: PASS

- [ ] **Step 5: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add app/Support/Approval/RequestVisibility.php tests/Feature/Approval/Phase2/RequestVisibilityTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
feat(approval): 申請を見られる範囲を 1 か所にまとめる

申請者・今の部門長・届いた段階の審査担当者と社長・判断した人・全件閲覧者と決裁の管理者。
他人の下書きは誰も見られない。1 件の判定と一覧の絞り込みを同じ規則から作る。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 9: 部門の管理に部門長・審査担当者・今年度の開始番号（画面⑧）

**Files:**
- Modify: `app/Http/Controllers/Approval/OrganizationController.php`（全文を下に示す）
- Modify: `resources/views/approvals/admin/organization.blade.php`（全文を下に示す）
- Modify: `lang/ja/validation.php`（和名 4 つ）
- Test: `tests/Feature/Approval/Phase2/OrganizationPhase2Test.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/OrganizationPhase2Test.php`

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\UserStatus;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalHistory;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalNumberSequence;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use App\Support\Approval\ApprovalNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/** 部門の管理の段階2 の分（設計書 §5.4・D6〜D9・D23） */
class OrganizationPhase2Test extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ParsesForms;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-26 01:00:00', 'UTC'));   // 日本時間 9/26（R8）
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function indexHtml(User $admin): string
    {
        return $this->actingAs($admin)->get(route('approvals.admin.organization.index'))->assertOk()->getContent();
    }

    /** 編集のモーダルが送るのと同じ形の中身 */
    private function payload(ApprovalDepartment $dept, array $overrides = []): array
    {
        $dept = $dept->fresh(['reviewers']);

        return array_merge([
            'company_id'   => (string) $dept->company_id,
            'name'         => $dept->name,
            'short_name'   => $dept->short_name,
            'code'         => $dept->code,
            'sort_order'   => (string) $dept->sort_order,
            'head_user_id' => $dept->head_user_id === null ? '' : (string) $dept->head_user_id,
            'reviewer_ids' => $dept->reviewers->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'next_number'  => (string) ApprovalNumber::currentState($dept)['next'],
        ], $overrides);
    }

    private function update(User $admin, ApprovalDepartment $dept, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($admin)->put(route('approvals.admin.organization.departments.update', $dept), $this->payload($dept, $overrides));
    }

    /** レイアウトの赤帯に、ちょうど 1 回だけ出ていること（段階1 の OrganizationManagementTest と同じ見方） */
    private function assertErrorBanner(string $html, string $message): void
    {
        $this->assertSame(1, substr_count($html, '<span class="text-sm text-red-800">' . e($message) . '</span>'), "赤帯に理由が出ていない: {$message}");
    }

    public function test_the_table_shows_the_head_reviewers_and_the_next_number(): void
    {
        $this->approvalWorld();
        $html = $this->indexHtml($this->approvalAdmin());

        $this->assertStringContainsString('部門 長', $html);
        $this->assertStringContainsString('審査 担当', $html);
        $this->assertStringContainsString('R8-J-001', $html);
        // ⚠ 編集のモーダルへ渡す説明（number_hint）は Js::from で \uXXXX に符号化されるので、HTML の文字列では探さない
    }

    /** 追加のフォームに新しい欄が載っている（描いたフォームの往復） */
    public function test_a_department_can_be_created_with_a_head_reviewers_and_a_start_number(): void
    {
        $company = $this->approvalCompany();
        $admin   = $this->approvalAdmin();
        $head    = $this->baseUser(['name' => '部門 長']);
        $rev     = $this->baseUser(['name' => '審査 担当']);

        $form = $this->parseForm($this->indexHtml($admin), 'action="' . route('approvals.admin.organization.departments.store') . '"');
        $this->assertArrayHasKey('head_user_id', $form['fields']);
        $this->assertSame('1', $form['fields']['next_number']);

        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], [
            'company_id'   => (string) $company->id,
            'name'         => '住宅事業部',
            'short_name'   => '住宅',
            'code'         => 'j',
            'sort_order'   => '1',
            'head_user_id' => (string) $head->id,
            'reviewer_ids' => [(string) $rev->id],
            'next_number'  => '21',
        ]))->assertRedirect(route('approvals.admin.organization.index'));

        $dept = ApprovalDepartment::where('code', 'J')->sole();
        $this->assertSame($head->id, $dept->head_user_id);
        $this->assertSame([$rev->id], $dept->reviewers()->pluck('users.id')->all());
        $this->assertSame(21, ApprovalNumber::currentState($dept)['next']);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'department.reviewers_changed')->count());
        $this->assertSame(1, ApprovalSettingLog::where('action', 'department.next_number_set')->count());
    }

    /** 選べるのは有効でメールアドレスのある人（D7） */
    public function test_only_active_users_with_mail_can_be_chosen(): void
    {
        $w        = $this->approvalWorld();
        $noMail   = $this->approvalOnlyUser(['name' => 'メール なし']);
        $inactive = $this->baseUser(['name' => '無効 の人', 'status' => UserStatus::Inactive->value]);

        $this->update($this->approvalAdmin(), $w['dept'], [
            'head_user_id' => (string) $noMail->id,
            'reviewer_ids' => [(string) $inactive->id],
        ])->assertSessionHasErrors([
            'head_user_id'   => '部門長には、有効でメールアドレスのある人を選んでください。',
            'reviewer_ids.0' => '審査担当者には、有効でメールアドレスのある人を選んでください。',
        ]);

        $this->assertSame($w['head']->id, $w['dept']->fresh()->head_user_id);
    }

    /** 許可していないドメインの人も選べるが、注意を出す（D7） */
    public function test_people_without_an_allowed_domain_are_marked(): void
    {
        ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);
        $this->approvalCompany();
        $this->baseUser(['name' => '外部 ドメイン', 'email' => 'x@example.com']);
        $this->baseUser(['name' => '社内 ドメイン', 'email' => 'y@mitsuwat.co.jp']);

        $html = $this->indexHtml($this->approvalAdmin(['email' => 'admin@mitsuwat.co.jp']));

        $this->assertStringContainsString('外部 ドメイン ※通知メールが届きません', $html);
        $this->assertStringNotContainsString('社内 ドメイン ※通知メールが届きません', $html);
    }

    /** 部門長・審査担当者は所属していなくてもよい（D6） */
    public function test_the_head_need_not_belong_to_the_department(): void
    {
        $w       = $this->approvalWorld();
        $outside = $this->baseUser(['name' => '所属 外']);

        $this->update($this->approvalAdmin(), $w['dept'], ['head_user_id' => (string) $outside->id])->assertSessionHasNoErrors();

        $this->assertSame($outside->id, $w['dept']->fresh()->head_user_id);
    }

    /** 4.3 のケース 5・D23: 部門長を変えると待っている申請が移り、申請ごとに記録が残る */
    public function test_changing_the_head_moves_waiting_requests(): void
    {
        $w   = $this->approvalWorld();
        $r   = $this->submittedFor($w);
        $new = $this->baseUser(['name' => '新 部門長']);

        $this->update($this->approvalAdmin(), $w['dept'], ['head_user_id' => (string) $new->id])
            ->assertRedirect(route('approvals.admin.organization.index'));

        $history = ApprovalHistory::where('request_id', $r->id)->where('action', 'head_changed')->sole();
        $this->assertSame($new->id, $history->meta['to_user_id']);
        $this->assertSame(['head_user_id' => $new->id], ApprovalSettingLog::where('action', 'department.updated')->sole()->new_values);
    }

    public function test_the_head_cannot_be_cleared_while_requests_wait(): void
    {
        $w = $this->approvalWorld();
        $this->submittedFor($w);

        $this->update($this->approvalAdmin(), $w['dept'], ['head_user_id' => ''])
            ->assertSessionHas('error', 'この部門には部門長の確認を待っている申請が 1 件あるため、部門長を空にできません。後任を選んでください。');

        $this->assertSame($w['head']->id, $w['dept']->fresh()->head_user_id);
    }

    /** 断る理由が赤帯に出る（役割と表示を別々に見る。Bug #46・#49） */
    public function test_the_reason_the_head_cannot_be_cleared_is_shown(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->submittedFor($w);

        $this->update($admin, $w['dept'], ['head_user_id' => ''])->assertRedirect(route('approvals.admin.organization.index'));

        $this->assertErrorBanner($this->indexHtml($admin), 'この部門には部門長の確認を待っている申請が 1 件あるため、部門長を空にできません。後任を選んでください。');
    }

    /** D9: 使った番号より小さくできない。ほかの変更もまとめて巻き戻る */
    public function test_the_next_number_cannot_go_back(): void
    {
        $w = $this->approvalWorld();
        ApprovalNumber::setNext($w['dept'], 5);
        ApprovalNumber::issue($w['dept'], now());   // R8-J-005

        $this->update($this->approvalAdmin(), $w['dept'], ['next_number' => '3', 'short_name' => '住宅2'])
            ->assertSessionHas('error', '今年度はすでに R8-J-005 まで使っています。6 以上を入れてください。');

        $this->assertSame('住宅', $w['dept']->fresh()->short_name, 'ほかの変更が巻き戻っていない');
    }

    /** D8: 番号を付けた申請がある部門は、会社とアルファベットを変えられない */
    public function test_company_and_code_are_locked_once_numbers_exist(): void
    {
        $w     = $this->approvalWorld();
        $draft = $this->draftFor($w);
        DB::table('approval_requests')->where('id', $draft->id)->update(['number' => 'R8-J-001', 'number_department_id' => $w['dept']->id]);

        $this->update($this->approvalAdmin(), $w['dept'], ['code' => 'JX'])
            ->assertSessionHas('error', 'この部門には決裁No を付けた申請があるため、会社とアルファベットは変えられません。');

        $this->assertSame('J', $w['dept']->fresh()->code);
    }

    public function test_a_department_used_by_requests_cannot_be_deleted(): void
    {
        $w     = $this->approvalWorld();
        $other = $this->approvalDepartment($w['company'], ['name' => '賃貸事業部', 'head_user_id' => $w['head']->id]);
        $this->draftFor($w, ['department_id' => $other->id]);

        $this->actingAs($this->approvalAdmin())->delete(route('approvals.admin.organization.departments.destroy', $other))
            ->assertSessionHas('error', 'この部門は申請 1 件に使われているため削除できません。');

        $this->assertNotNull($other->fresh());
    }

    public function test_a_review_department_of_a_type_cannot_be_deleted(): void
    {
        $w = $this->approvalWorld();

        $this->actingAs($this->approvalAdmin())->delete(route('approvals.admin.organization.departments.destroy', $w['reviewDept']))
            ->assertSessionHas('error', 'この部門は申請の種類 1 件の審査部門になっているため削除できません。先に申請種類の管理で審査部門を変えてください。');
    }

    /** 開始番号を入れただけ（番号を使っていない）の部門は消せる。連番の行も一緒に消える */
    public function test_an_unused_department_with_a_start_number_can_be_deleted(): void
    {
        $company = $this->approvalCompany();
        $dept    = $this->approvalDepartment($company);
        ApprovalNumber::setNext($dept, 30);

        $this->actingAs($this->approvalAdmin())->delete(route('approvals.admin.organization.departments.destroy', $dept))
            ->assertSessionHas('success', '部門を削除しました。');

        $this->assertNull($dept->fresh());
        $this->assertSame(0, ApprovalNumberSequence::count());
    }
}
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter OrganizationPhase2Test
```

Expected: FAIL（部門長・審査担当者・開始番号をまだ受け取らない）

- [ ] **Step 3: コントローラを書き換える**

`app/Http/Controllers/Approval/OrganizationController.php`（全文）

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalNumberSequence;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\ApprovalType;
use App\Models\User;
use App\Support\Approval\ApprovalNumber;
use App\Support\Approval\SettingLogger;
use App\Support\Approval\Workflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use InvalidArgumentException;

/**
 * 会社・部門・許可するメールのドメイン（段階1 設計書 §5.8）と、段階2 の部門長・審査担当者・
 * 今年度の開始番号（段階2 設計書 §5.4）。
 *
 * 画面は 1 枚で 3 つの欄を並べる。追加と編集は基幹の利用者管理と同じくモーダル。
 *
 * ⚠ 決裁の所属部門（`approval_department_user`）は**この画面では編集しない**（人数を出すだけ）。
 *   編集は利用者の管理（§5.9）と CSV（§5.10）で行う。
 * ⚠ 部門長・審査担当者は所属を問わない（D6）。選べるのは有効でメールアドレスのある人（D7）。
 */
class OrganizationController extends Controller
{
    public function index()
    {
        $companies = ApprovalCompany::with(['departments' => fn ($q) => $q->withCount('users')->with(['head', 'reviewers'])])
            ->orderBy('sort_order')->orderBy('id')->get();

        // 部門の行に会社名と今年度の番号を出すので、親を入れておく（1 件ずつ引き直さない）
        $companies->each(fn (ApprovalCompany $company) => $company->departments->each(
            function (ApprovalDepartment $department) use ($company): void {
                $department->setRelation('company', $company);
                $department->number_state = ApprovalNumber::currentState($department);
                $department->has_numbers  = ApprovalNumber::departmentHasNumbers($department);
            }
        ));

        $mailDomains = ApprovalMailDomain::orderBy('domain')->get()
            ->map(function (ApprovalMailDomain $domain) {
                // 削除するとこの人数に通知メールが届かなくなる（§5.8）
                $domain->affected_user_count = User::whereNotNull('email')
                    ->where('email', 'like', '%@' . $domain->domain)
                    ->count();

                return $domain;
            });

        // 部門長・審査担当者の選択肢（D7）。許可していないドメインの人は選べるが注意を出す
        $allowed    = ApprovalMailDomain::pluck('domain')->all();
        $candidates = User::where('status', UserStatus::Active->value)->whereNotNull('email')
            ->orderBy('name')->get(['id', 'name', 'email', 'employee_number'])
            ->map(function (User $user) use ($allowed) {
                $user->mail_allowed = in_array(mb_strtolower(substr((string) $user->email, strrpos((string) $user->email, '@') + 1), 'UTF-8'), $allowed, true);

                return $user;
            });

        return view('approvals.admin.organization', compact('companies', 'mailDomains', 'candidates'));
    }

    // --- 会社 ---

    public function storeCompany(Request $request)
    {
        $validated = $this->validateCompany($request);

        $company = ApprovalCompany::create($validated);
        SettingLogger::record('company.created', 'approval_company', $company->id, [], $validated);

        return $this->back('会社を登録しました。');
    }

    public function updateCompany(Request $request, ApprovalCompany $approvalCompany)
    {
        $validated = $this->validateCompany($request, $approvalCompany);
        $before    = $approvalCompany->only(array_keys($validated));

        $approvalCompany->update($validated);
        SettingLogger::recordChange('company.updated', 'approval_company', $approvalCompany->id, $before, $validated);

        return $this->back('会社を更新しました。');
    }

    public function destroyCompany(ApprovalCompany $approvalCompany)
    {
        $count = $approvalCompany->departments()->count();

        if ($count > 0) {
            return $this->back(null, "この会社には部門が {$count} 件あるため削除できません。先に部門を削除してください。");
        }

        $before = $approvalCompany->only(['name', 'fiscal_start_month', 'sort_order']);
        $id     = $approvalCompany->id;
        $approvalCompany->delete();

        SettingLogger::record('company.deleted', 'approval_company', $id, $before, []);

        return $this->back('会社を削除しました。');
    }

    private function validateCompany(Request $request, ?ApprovalCompany $current = null): array
    {
        return $request->validate([
            'name'               => ['required', 'string', 'max:50', Rule::unique('approval_companies', 'name')->ignore($current?->id)],
            'fiscal_start_month' => ['required', 'integer', 'between:1,12'],
            'sort_order'         => ['required', 'integer', 'min:0', 'max:9999'],
        ], [
            'name.required' => '会社名を入力してください。',
            'name.max' => '会社名は50文字以内で入力してください。',
            'name.unique' => 'この会社名は既に登録されています。',
            'fiscal_start_month.required' => '期の始まりの月を選択してください。',
            'fiscal_start_month.between' => '期の始まりの月は1〜12で入力してください。',
            'sort_order.required' => '表示順を入力してください。',
        ], [
            'name' => '会社名',
        ]);
    }

    // --- 部門 ---

    public function storeDepartment(Request $request)
    {
        $validated = $this->validateDepartment($request);
        [$base, $headId, $reviewerIds, $next] = $this->splitDepartmentInput($validated);

        try {
            DB::transaction(function () use ($base, $headId, $reviewerIds, $next): void {
                $department = ApprovalDepartment::create($base + ['head_user_id' => $headId]);
                SettingLogger::record('department.created', 'approval_department', $department->id, [], $base + ['head_user_id' => $headId]);

                $this->syncReviewers($department, $reviewerIds);
                $this->setNextNumber($department, $next);
            });
        } catch (InvalidArgumentException $e) {
            return $this->back(null, $e->getMessage());
        }

        return $this->back('部門を登録しました。');
    }

    public function updateDepartment(Request $request, ApprovalDepartment $approvalDepartment)
    {
        $validated = $this->validateDepartment($request, $approvalDepartment);
        [$base, $headId, $reviewerIds, $next] = $this->splitDepartmentInput($validated);

        // 番号を付けた申請がある部門は、会社とアルファベットを変えられない（D8）
        if (ApprovalNumber::departmentHasNumbers($approvalDepartment)
            && ((int) $base['company_id'] !== $approvalDepartment->company_id || $base['code'] !== $approvalDepartment->code)) {
            return $this->back(null, 'この部門には決裁No を付けた申請があるため、会社とアルファベットは変えられません。');
        }

        $oldHeadId = $approvalDepartment->head_user_id;

        // 部門長の確認を待っている申請があるうちは、部門長を空にできない（後任を選ぶ）
        if ($headId === null && $oldHeadId !== null && ($waiting = $this->waitingHeadSteps($approvalDepartment)) > 0) {
            return $this->back(null, "この部門には部門長の確認を待っている申請が {$waiting} 件あるため、部門長を空にできません。後任を選んでください。");
        }

        try {
            DB::transaction(function () use ($request, $approvalDepartment, $base, $headId, $reviewerIds, $next, $oldHeadId): void {
                $before = $approvalDepartment->only(array_keys($base)) + ['head_user_id' => $oldHeadId];

                $approvalDepartment->update($base + ['head_user_id' => $headId]);
                SettingLogger::recordChange('department.updated', 'approval_department', $approvalDepartment->id, $before, $base + ['head_user_id' => $headId]);

                $this->syncReviewers($approvalDepartment, $reviewerIds);
                $this->setNextNumber($approvalDepartment->fresh('company'), $next);

                // 部門長の確認を待っている申請を、新しい部門長へ移す（4.3 のケース 5・D23）
                if ($headId !== $oldHeadId) {
                    app(Workflow::class)->headChanged($approvalDepartment->fresh(), $oldHeadId, $request->user());
                }
            });
        } catch (InvalidArgumentException $e) {
            // 開始番号が使った番号以下（D9）。ほかの変更もまとめて巻き戻る
            return $this->back(null, $e->getMessage());
        }

        return $this->back('部門を更新しました。');
    }

    /**
     * ⚠ `users()` は既定のスコープなので、所属者が**論理削除された利用者だけ**の部門は
     *   ここが 0 件と数え、中間テーブルの行ごと黙って消える（段階1 の注記のまま）。
     */
    public function destroyDepartment(ApprovalDepartment $approvalDepartment)
    {
        $count = $approvalDepartment->users()->count();

        if ($count > 0) {
            return $this->back(null, "この部門には所属者が {$count} 人いるため削除できません。先に利用者の管理で所属部門を変えてください。");
        }

        // 申請（下書きを含む）や回った記録に使われている部門は削除できない（段階2 設計書 §5.4）
        $used = ApprovalRequest::where('department_id', $approvalDepartment->id)
            ->orWhereHas('steps', fn ($q) => $q->where('department_id', $approvalDepartment->id))
            ->count();

        if ($used > 0) {
            return $this->back(null, "この部門は申請 {$used} 件に使われているため削除できません。");
        }

        $types = ApprovalType::where('review_department_id', $approvalDepartment->id)->count();

        if ($types > 0) {
            return $this->back(null, "この部門は申請の種類 {$types} 件の審査部門になっているため削除できません。先に申請種類の管理で審査部門を変えてください。");
        }

        $before = $approvalDepartment->only(['company_id', 'name', 'short_name', 'code', 'sort_order', 'head_user_id']);
        $id     = $approvalDepartment->id;

        DB::transaction(function () use ($approvalDepartment): void {
            // 番号を 1 つも使っていない連番の行（開始番号の設定で作られる）は部門と一緒に消す。
            // 審査担当者の行は外部キーの CASCADE で消える
            ApprovalNumberSequence::where('department_id', $approvalDepartment->id)->delete();
            $approvalDepartment->delete();
        });

        SettingLogger::record('department.deleted', 'approval_department', $id, $before, []);

        return $this->back('部門を削除しました。');
    }

    private function validateDepartment(Request $request, ?ApprovalDepartment $current = null): array
    {
        // ⚠ 検証の前に正規化する（保存する値で一意を検査するため。設計書 §5.6 と同じ理由）
        $request->merge(['code' => mb_strtoupper(trim(mb_convert_kana((string) $request->input('code'), 'as')), 'UTF-8')]);

        return $request->validate([
            'company_id'     => ['required', Rule::exists('approval_companies', 'id')],
            'name'           => ['required', 'string', 'max:50', Rule::unique('approval_departments', 'name')->where('company_id', $request->input('company_id'))->ignore($current?->id)],
            'short_name'     => ['required', 'string', 'max:6'],
            'code'           => ['required', 'string', 'regex:/\A[A-Z]{1,3}\z/', Rule::unique('approval_departments', 'code')->ignore($current?->id)],
            'sort_order'     => ['required', 'integer', 'min:0', 'max:9999'],
            'head_user_id'   => ['nullable', 'integer', $this->assignableRule()],
            'reviewer_ids'   => ['nullable', 'array', 'max:50'],
            'reviewer_ids.*' => ['integer', 'distinct', $this->assignableRule()],
            'next_number'    => ['nullable', 'integer', 'min:1', 'max:99999'],
        ], [
            'company_id.required' => '会社を選択してください。',
            'name.required' => '部門名を入力してください。',
            'name.unique' => 'この会社にはその部門名が既に登録されています。',
            'short_name.required' => '略称を入力してください。',
            'short_name.max' => '略称は6文字以内で入力してください（データ印に入るため）。',
            'code.required' => 'アルファベットを入力してください。',
            'code.regex' => 'アルファベットは英大文字1〜3文字で入力してください。',
            'code.unique' => 'このアルファベットは既に使われています。',
            'sort_order.required' => '表示順を入力してください。',
            'head_user_id.exists' => '部門長には、有効でメールアドレスのある人を選んでください。',
            'reviewer_ids.*.exists' => '審査担当者には、有効でメールアドレスのある人を選んでください。',
            'next_number.min' => '今年度の次の番号は1以上で入力してください。',
        ], [
            'name' => '部門名',
            'code' => 'アルファベット',
        ]);
    }

    /** 部門長・審査担当者に選べる人（有効・メールあり・削除されていない。D7） */
    private function assignableRule(): Exists
    {
        return Rule::exists('users', 'id')
            ->whereNull('deleted_at')
            ->where('status', UserStatus::Active->value)
            ->whereNotNull('email');
    }

    /** @return array{0: array<string, mixed>, 1: ?int, 2: list<int>, 3: ?int} */
    private function splitDepartmentInput(array $validated): array
    {
        $base        = array_intersect_key($validated, array_flip(['company_id', 'name', 'short_name', 'code', 'sort_order']));
        $headId      = isset($validated['head_user_id']) ? (int) $validated['head_user_id'] : null;
        $reviewerIds = array_values(array_unique(array_map('intval', $validated['reviewer_ids'] ?? [])));
        $next        = isset($validated['next_number']) ? (int) $validated['next_number'] : null;

        return [$base, $headId, $reviewerIds, $next];
    }

    /** 審査担当者を入れ替え、変わったときだけ記録する */
    private function syncReviewers(ApprovalDepartment $department, array $reviewerIds): void
    {
        $before = $department->reviewers()->pluck('users.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $after  = collect($reviewerIds)->sort()->values()->all();

        if ($before === $after) {
            return;
        }

        $department->reviewers()->sync($reviewerIds);
        SettingLogger::record('department.reviewers_changed', 'approval_department', $department->id, ['reviewer_ids' => $before], ['reviewer_ids' => $after]);
    }

    /**
     * 今年度の次の番号（要件 6.4・D9）。空なら変えない。変わったときだけ記録する。
     *
     * @throws InvalidArgumentException 使った番号以下のとき（呼び出し側がトランザクションごと巻き戻す）
     */
    private function setNextNumber(ApprovalDepartment $department, ?int $next): void
    {
        if ($next === null) {
            return;
        }

        $previous = ApprovalNumber::currentState($department)['next'];

        if ($previous === $next) {
            return;
        }

        ApprovalNumber::setNext($department, $next);
        SettingLogger::record('department.next_number_set', 'approval_department', $department->id, ['next_number' => $previous], ['next_number' => $next]);
    }

    private function waitingHeadSteps(ApprovalDepartment $department): int
    {
        return ApprovalStep::where('kind', ApprovalStepKind::Head->value)
            ->where('status', ApprovalStepStatus::Waiting->value)
            ->where('department_id', $department->id)
            ->count();
    }

    // --- 許可するドメイン ---

    public function storeMailDomain(Request $request)
    {
        $request->merge(['domain' => ApprovalMailDomain::normalize($request->input('domain'))]);

        $validated = $request->validate([
            // ⚠ ドメインだけ（`@` は normalize が外す）。完全一致で判定するのでサブドメインは別に登録する
            'domain' => ['required', 'string', 'max:255', 'regex:/\A[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+\z/', Rule::unique('approval_mail_domains', 'domain')],
        ], [
            'domain.required' => 'ドメインを入力してください。',
            'domain.regex' => 'ドメインの形式が正しくありません（例: mitsuwat.co.jp）。',
            'domain.unique' => 'このドメインは既に登録されています。',
        ]);

        $domain = ApprovalMailDomain::create($validated);
        SettingLogger::record('mail_domain.created', 'approval_mail_domain', $domain->id, [], $validated);

        return $this->back('ドメインを登録しました。');
    }

    public function destroyMailDomain(ApprovalMailDomain $mailDomain)
    {
        $before = $mailDomain->only(['domain']);
        $id     = $mailDomain->id;
        $mailDomain->delete();

        SettingLogger::record('mail_domain.deleted', 'approval_mail_domain', $id, $before, []);

        return $this->back('ドメインを削除しました。');
    }

    private function back(?string $success, ?string $error = null)
    {
        $redirect = redirect()->route('approvals.admin.organization.index');

        return $error !== null ? $redirect->with('error', $error) : $redirect->with('success', $success);
    }
}
```

- [ ] **Step 4: 画面を書き換える**

`resources/views/approvals/admin/organization.blade.php`（全文。段階1 の中身はそのままで、部門の一覧に 3 列・追加と編集のモーダルに 3 つの欄・Alpine の状態を足した）

```blade
@extends('layouts.app')

@section('title', '部門の管理')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">部門の管理</span>
@endsection

@section('content')
<div x-data="approvalOrganization()" x-cloak>

    {{-- 成功・失敗の帯はレイアウトが出す（ここで出すと画面に 2 回出る）。$errors だけ各ビューの責任 --}}
    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <h1 class="text-lg font-bold text-gray-900 mb-5">部門の管理</h1>

    {{-- 会社 --}}
    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
            <h2 class="text-[14px] font-bold text-gray-900">会社</h2>
            <button type="button" @click="companyCreateModal = true" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-semibold rounded-md cursor-pointer">会社を追加</button>
        </div>
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
        <table class="w-full min-w-[520px] border-collapse">
            <thead>
                <tr>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">会社名</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">期の始まり</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">表示順</th>
                    <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($companies as $company)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-900">{{ $company->name }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $company->fiscal_start_month }}月</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $company->sort_order }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-right whitespace-nowrap">
                            <button type="button" @click="openCompanyEdit({{ \Illuminate\Support\Js::from($company->only(['id', 'name', 'fiscal_start_month', 'sort_order'])) }})" class="text-[12px] text-blue-600 hover:underline cursor-pointer bg-transparent border-none p-0">編集</button>
                            <span class="text-gray-200 mx-1">|</span>
                            <form method="POST" action="{{ route('approvals.admin.organization.companies.destroy', $company) }}" class="inline" onsubmit="return confirm('この会社を削除しますか。');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-[12px] text-red-600 hover:underline cursor-pointer bg-transparent border-none p-0">削除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-[13px] text-gray-400">会社が登録されていません。</td></tr>
                @endforelse
            </tbody>
        </table>
            </div>{{-- /scroll-hint-inner --}}
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>{{-- /scroll-hint --}}
    </section>

    {{-- 部門 --}}
    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
            <h2 class="text-[14px] font-bold text-gray-900">部門</h2>
            {{-- ⚠ 押せない理由はボタン自身の title では出ない（ホバーを受ける span で包む。Bug #43） --}}
            <span @if($companies->isEmpty()) title="先に会社を登録してください。" @endif style="display: inline-flex;">
                <button type="button" @click="departmentCreateModal = true" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-semibold rounded-md cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed" @if($companies->isEmpty()) disabled @endif>部門を追加</button>
            </span>
        </div>
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
        <table class="w-full min-w-[1080px] border-collapse">
            <thead>
                <tr>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">会社</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">部門名</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">略称</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">アルファベット</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">所属</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">部門長</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">審査担当者</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">今年度の次の番号</th>
                    <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($companies->flatMap->departments as $dept)
                    @php
                        // 今年度の次の番号（要件 6.4）。編集のモーダルの説明にも使う
                        $state      = $dept->number_state;
                        $nextLabel  = \App\Support\Approval\ApprovalNumber::format($state['era'], $dept->code, $state['next']);
                        $numberHint = $state['last_issued'] > 0
                            ? "今年度（{$state['era']}）は " . \App\Support\Approval\ApprovalNumber::format($state['era'], $dept->code, $state['last_issued']) . ' まで使っています。'
                            : "今年度（{$state['era']}）はまだ番号を使っていません。";
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $dept->company->name }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-900">{{ $dept->name }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $dept->short_name }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-mono text-gray-700 whitespace-nowrap">{{ $dept->code }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $dept->users_count }} 人</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] whitespace-nowrap {{ $dept->head ? 'text-gray-900' : 'text-red-700' }}">{{ $dept->head?->name ?? '未設定' }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $dept->reviewers->pluck('name')->join('、') ?: '—' }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-mono text-gray-700 whitespace-nowrap">{{ $nextLabel }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-right whitespace-nowrap">
                            <button type="button" @click="openDepartmentEdit({{ \Illuminate\Support\Js::from($dept->only(['id', 'company_id', 'name', 'short_name', 'code', 'sort_order', 'head_user_id']) + [
                                'reviewer_ids' => $dept->reviewers->pluck('id')->all(),
                                'next_number'  => $state['next'],
                                'number_hint'  => $numberHint,
                                'has_numbers'  => $dept->has_numbers,
                            ]) }})" class="text-[12px] text-blue-600 hover:underline cursor-pointer bg-transparent border-none p-0">編集</button>
                            <span class="text-gray-200 mx-1">|</span>
                            <form method="POST" action="{{ route('approvals.admin.organization.departments.destroy', $dept) }}" class="inline" onsubmit="return confirm('この部門を削除しますか。');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-[12px] text-red-600 hover:underline cursor-pointer bg-transparent border-none p-0">削除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-8 text-center text-[13px] text-gray-400">部門が登録されていません。</td></tr>
                @endforelse
            </tbody>
        </table>
            </div>{{-- /scroll-hint-inner --}}
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>{{-- /scroll-hint --}}
    </section>

    {{-- 許可するドメイン --}}
    <section class="bg-white rounded-lg border border-gray-200">
        <div class="px-4 py-3 border-b border-gray-200">
            <h2 class="text-[14px] font-bold text-gray-900">通知メールを送ってよいドメイン</h2>
            <p class="text-[12px] text-gray-500 mt-1">ここに登録したドメインのメールアドレスにだけ、パスワード再発行の通知を送ります。CSV 取込のメールアドレスの検査にも使います。</p>
        </div>
        <form method="POST" action="{{ route('approvals.admin.organization.mailDomains.store') }}" class="flex flex-wrap items-center gap-2 px-4 py-3 border-b border-gray-100">
            @csrf
            <input type="text" name="domain" value="{{ old('domain') }}" placeholder="例: mitsuwat.co.jp" autocapitalize="none" spellcheck="false"
                   class="h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 w-full sm:w-[280px]">
            <button type="submit" class="h-8 px-3.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-semibold rounded-md cursor-pointer">追加</button>
        </form>
        <ul>
            @forelse($mailDomains as $domain)
                <li class="flex flex-wrap items-center gap-2 px-4 py-2.5 border-b border-gray-100 text-[13px]">
                    @php
                        // 削除の確認に、通知メールが届かなくなる人数を出す（設計書 §5.8・F4）。
                        // ⚠ 行には中立な人数だけ。「届かなくなります」を行に常に出すと「今届いていない」と読める
                        $deleteConfirm = 'このドメインを削除しますか。';
                        if ($domain->affected_user_count > 0) {
                            $deleteConfirm .= "\n\nこのドメインのメールアドレスを持つ利用者: {$domain->affected_user_count} 人（この人たちには通知メールが届かなくなります）";
                        }
                    @endphp
                    <span class="font-mono text-gray-900">{{ $domain->domain }}</span>
                    <span class="text-[12px] text-gray-500">このドメインのメールアドレスを持つ利用者: {{ $domain->affected_user_count }} 人</span>
                    <form method="POST" action="{{ route('approvals.admin.organization.mailDomains.destroy', $domain) }}" class="ml-auto" onsubmit="return confirm({{ \Illuminate\Support\Js::from($deleteConfirm) }});">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-[12px] text-red-600 hover:underline cursor-pointer bg-transparent border-none p-0">削除</button>
                    </form>
                </li>
            @empty
                <li class="px-4 py-8 text-center text-[13px] text-gray-400">ドメインが登録されていません。登録するまで通知メールは送られません。</li>
            @endforelse
        </ul>
    </section>

    {{-- 会社の追加（追加と編集でフォームを分ける。基幹の利用者管理と同じ形）--}}
    <div x-show="companyCreateModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="companyCreateModal = false" class="bg-white rounded-xl w-full max-w-[420px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" action="{{ route('approvals.admin.organization.companies.store') }}">
                @csrf
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">会社の追加</div>
                <div class="px-6 py-4 space-y-3.5">
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">会社名<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="name" required maxlength="50" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">期の始まりの月<span class="text-red-600 ml-0.5">*</span></label>
                        <select name="fiscal_start_month" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                            @foreach(range(1, 12) as $month)
                                <option value="{{ $month }}">{{ $month }}月</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">表示順<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="number" name="sort_order" value="0" required inputmode="numeric" min="0" max="9999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="companyCreateModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">保存する</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 会社の編集 --}}
    <div x-show="companyEditModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="companyEditModal = false" class="bg-white rounded-xl w-full max-w-[420px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" :action="'{{ url('approvals/admin/organization/companies') }}/' + editCompanyId">
                @csrf
                @method('PUT')
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">会社の編集</div>
                <div class="px-6 py-4 space-y-3.5">
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">会社名<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="name" x-model="editCompanyName" required maxlength="50" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">期の始まりの月<span class="text-red-600 ml-0.5">*</span></label>
                        {{-- ⚠ <option> は @@foreach で静的に出す（x-for は x-model の同期より後に描画されて値がズレる。Bug #16） --}}
                        <select name="fiscal_start_month" x-model="editCompanyMonth" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                            @foreach(range(1, 12) as $month)
                                <option value="{{ $month }}">{{ $month }}月</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">表示順<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="number" name="sort_order" x-model="editCompanySort" required inputmode="numeric" min="0" max="9999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="companyEditModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">保存する</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 部門の追加 --}}
    <div x-show="departmentCreateModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="departmentCreateModal = false" class="bg-white rounded-xl w-full max-w-[420px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" action="{{ route('approvals.admin.organization.departments.store') }}">
                @csrf
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">部門の追加</div>
                <div class="px-6 py-4 space-y-3.5">
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">会社<span class="text-red-600 ml-0.5">*</span></label>
                        <select name="company_id" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                            @foreach($companies as $company)
                                <option value="{{ $company->id }}">{{ $company->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">部門名<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="name" required maxlength="50" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">略称<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="short_name" required maxlength="6" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                        <p class="text-[11px] text-gray-400 mt-1">データ印の上段に入るので6文字まで</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">アルファベット<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="code" required maxlength="3" autocapitalize="characters" spellcheck="false" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] font-mono uppercase">
                        <p class="text-[11px] text-gray-400 mt-1">申請番号に使う英大文字1〜3文字（グループ全体で重複不可）</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">表示順<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="number" name="sort_order" value="0" required inputmode="numeric" min="0" max="9999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">部門長</label>
                        {{-- ⚠ <option> は @@foreach で静的に出す（Bug #16）。選べるのは有効でメールアドレスのある人（D7）。
                             今の部門長は必ず選択肢に入る（部門長・審査担当者は無効化・削除・メールを空にできない。12.6） --}}
                        <select name="head_user_id" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                            <option value="">（未設定）</option>
                            @foreach($candidates as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->name }}{{ $candidate->employee_number ? '（' . $candidate->employee_number . '）' : '' }}{{ $candidate->mail_allowed ? '' : ' ※通知メールが届きません' }}</option>
                            @endforeach
                        </select>
                        <p class="text-[11px] text-gray-400 mt-1">未設定のままだと、この部門では申請を提出できません</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">審査担当者</label>
                        <input type="text" x-model="reviewerFilter" @keydown.enter.prevent placeholder="氏名で絞り込む" class="w-full h-8 px-2.5 border border-gray-300 rounded-md text-[12px] mb-1.5">
                        <div class="max-h-[180px] overflow-y-auto border border-gray-200 rounded-md">
                            @foreach($candidates as $candidate)
                                <label class="flex items-center gap-2 px-2.5 py-1.5 text-[12px] border-b border-gray-100 cursor-pointer" x-show="matchesReviewer({{ \Illuminate\Support\Js::from($candidate->name) }})">
                                    <input type="checkbox" name="reviewer_ids[]" value="{{ $candidate->id }}">
                                    <span class="text-gray-800">{{ $candidate->name }}</span>
                                    @unless($candidate->mail_allowed)<span class="text-[11px] text-amber-700">通知メールが届きません</span>@endunless
                                </label>
                            @endforeach
                        </div>
                        <p class="text-[11px] text-gray-400 mt-1">この部門が審査部門になった申請に、意見を入れる人（何人でも）</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">今年度の次の番号</label>
                        <input type="number" name="next_number" value="1" inputmode="numeric" min="1" max="99999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                        <p class="text-[11px] text-gray-400 mt-1">紙で 20 番まで使っていたら 21 を入れる（要件 6.4）</p>
                    </div>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="departmentCreateModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">保存する</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 部門の編集 --}}
    <div x-show="departmentEditModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="departmentEditModal = false" class="bg-white rounded-xl w-full max-w-[420px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" :action="'{{ url('approvals/admin/organization/departments') }}/' + editDepartmentId">
                @csrf
                @method('PUT')
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">部門の編集</div>
                <div class="px-6 py-4 space-y-3.5">
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">会社<span class="text-red-600 ml-0.5">*</span></label>
                        <select name="company_id" x-model="editDepartmentCompanyId" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                            @foreach($companies as $company)
                                <option value="{{ $company->id }}">{{ $company->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">部門名<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="name" x-model="editDepartmentName" required maxlength="50" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">略称<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="short_name" x-model="editDepartmentShortName" required maxlength="6" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                        <p class="text-[11px] text-gray-400 mt-1">データ印の上段に入るので6文字まで</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">アルファベット<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="code" x-model="editDepartmentCode" required maxlength="3" autocapitalize="characters" spellcheck="false" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] font-mono uppercase">
                        <p class="text-[11px] text-gray-400 mt-1">申請番号に使う英大文字1〜3文字（グループ全体で重複不可）</p>
                        <p x-show="editDepartmentHasNumbers" class="text-[11px] text-amber-700 mt-1">決裁No を付けた申請があるため、会社とアルファベットは変えられません（D8）。</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">表示順<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="number" name="sort_order" x-model="editDepartmentSort" required inputmode="numeric" min="0" max="9999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">部門長</label>
                        <select name="head_user_id" x-model="editDepartmentHeadId" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                            <option value="">（未設定）</option>
                            @foreach($candidates as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->name }}{{ $candidate->employee_number ? '（' . $candidate->employee_number . '）' : '' }}{{ $candidate->mail_allowed ? '' : ' ※通知メールが届きません' }}</option>
                            @endforeach
                        </select>
                        <p class="text-[11px] text-gray-400 mt-1">変えると、部門長の確認を待っている申請は新しい部門長へ移ります</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">審査担当者</label>
                        <input type="text" x-model="reviewerFilter" @keydown.enter.prevent placeholder="氏名で絞り込む" class="w-full h-8 px-2.5 border border-gray-300 rounded-md text-[12px] mb-1.5">
                        <div class="max-h-[180px] overflow-y-auto border border-gray-200 rounded-md">
                            @foreach($candidates as $candidate)
                                <label class="flex items-center gap-2 px-2.5 py-1.5 text-[12px] border-b border-gray-100 cursor-pointer" x-show="matchesReviewer({{ \Illuminate\Support\Js::from($candidate->name) }})">
                                    <input type="checkbox" name="reviewer_ids[]" value="{{ $candidate->id }}" x-model="editDepartmentReviewerIds">
                                    <span class="text-gray-800">{{ $candidate->name }}</span>
                                    @unless($candidate->mail_allowed)<span class="text-[11px] text-amber-700">通知メールが届きません</span>@endunless
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">今年度の次の番号</label>
                        <input type="number" name="next_number" x-model="editDepartmentNext" inputmode="numeric" min="1" max="99999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                        <p class="text-[11px] text-gray-400 mt-1" x-text="editDepartmentNumberHint"></p>
                    </div>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="departmentEditModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">保存する</button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
function approvalOrganization() {
    return {
        companyCreateModal: false,
        companyEditModal: false,
        editCompanyId: null,
        editCompanyName: '',
        editCompanyMonth: '1',
        editCompanySort: '0',

        departmentCreateModal: false,
        departmentEditModal: false,
        editDepartmentId: null,
        editDepartmentCompanyId: '',
        editDepartmentName: '',
        editDepartmentShortName: '',
        editDepartmentCode: '',
        editDepartmentSort: '0',
        editDepartmentHeadId: '',
        editDepartmentReviewerIds: [],
        editDepartmentNext: '1',
        editDepartmentNumberHint: '',
        editDepartmentHasNumbers: false,
        reviewerFilter: '',

        openCompanyEdit(row) {
            this.editCompanyId = row.id;
            this.editCompanyName = row.name;
            this.editCompanyMonth = String(row.fiscal_start_month);
            this.editCompanySort = String(row.sort_order);
            this.companyEditModal = true;
        },

        openDepartmentEdit(row) {
            this.editDepartmentId = row.id;
            this.editDepartmentCompanyId = String(row.company_id);
            this.editDepartmentName = row.name;
            this.editDepartmentShortName = row.short_name;
            this.editDepartmentCode = row.code;
            this.editDepartmentSort = String(row.sort_order);
            this.editDepartmentHeadId = row.head_user_id === null ? '' : String(row.head_user_id);
            // ⚠ チェックボックスの value は文字列なので、x-model の配列も文字列にそろえる
            this.editDepartmentReviewerIds = row.reviewer_ids.map(String);
            this.editDepartmentNext = String(row.next_number);
            this.editDepartmentNumberHint = row.number_hint;
            this.editDepartmentHasNumbers = row.has_numbers;
            this.reviewerFilter = '';
            this.departmentEditModal = true;
        },

        // 審査担当者の一覧を氏名で絞り込む（空白は無視する）
        matchesReviewer(name) {
            const q = this.reviewerFilter.replace(/\s/g, '');
            return q === '' || name.replace(/\s/g, '').includes(q);
        }
    };
}
</script>
@endpush
```

- [ ] **Step 5: 和名を足す**

`lang/ja/validation.php` の `attributes` の最後（`'user_ids.*' => '選んだ利用者',` の次）に足す:

```php

        // --- 決裁申請 段階2 ---
        // 部門の管理（部門長・審査担当者・今年度の開始番号。段階2 設計書 §5.4）
        'head_user_id'   => '部門長',
        'reviewer_ids'   => '審査担当者',
        'reviewer_ids.*' => '審査担当者',
        'next_number'    => '今年度の次の番号',
```

- [ ] **Step 6: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'OrganizationPhase2Test|OrganizationManagementTest|ApprovalAdminGateTest|JapaneseValidationMessagesTest|MobileLayoutTest'
```

Expected: PASS。段階1 の部門の管理のテストも緑のまま（段階1 のテストは `head_user_id` などを送らないが、どれも `nullable` なので通る）。

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add app/Http/Controllers/Approval/OrganizationController.php resources/views/approvals/admin/organization.blade.php lang/ja/validation.php tests/Feature/Approval/Phase2/OrganizationPhase2Test.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
feat(approval): 部門の管理に部門長・審査担当者・今年度の開始番号を足す

選べるのは有効でメールアドレスのある人（所属は問わない）。部門長を変えると待っている申請を
新しい部門長へ移し、番号を付けた部門は会社とアルファベットを変えられない。開始番号は使った
番号より小さくできない。申請や種類に使われている部門は削除できない。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 10: 部門長・審査担当者の無効化・削除・メールアドレスを空にする操作の歯止め（要件 12.6）

**Files:**
- Modify: `app/Http/Controllers/Admin/UserController.php`（入口 4 つ）
- Modify: `app/Http/Controllers/Approval/UserController.php`（`toggleStatus()`）
- Test: `tests/Feature/Approval/Phase2/AssignmentGuardTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/AssignmentGuardTest.php`

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 部門長・審査担当者に指定されている人は、後任を決めるまで無効化・削除・メールアドレスを空にできない
 * （要件 12.6・段階2 設計書 §5.4）。社長の守り（段階1 §5.7）と同じ入口 4 つすべてで文言まで見る。
 */
class AssignmentGuardTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private Department $baseDepartment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDepartment = Department::create(['name' => 'テナント', 'code' => 'tenant', 'display_order' => 1]);
    }

    private function executive(): User
    {
        return $this->baseUser(['role' => UserRole::Executive->value, 'name' => '経営 太郎']);
    }

    /** 基幹を使う部門長（基幹の所属部門つき・メールあり） */
    private function baseHead(): array
    {
        $w    = $this->approvalWorld();
        $head = $w['head'];
        $head->forceFill(['employee_number' => 'M001', 'email' => 'head@example.com'])->save();
        $head->departments()->attach($this->baseDepartment->id);

        return [$w, $head->fresh()];
    }

    private function editPayload(User $target, array $overrides = []): array
    {
        return array_merge([
            'name' => $target->name, 'employee_number' => 'M001', 'email' => 'head@example.com',
            'role' => $target->role->value, 'status' => UserStatus::Active->value,
            'departments' => [$this->baseDepartment->id],
        ], $overrides);
    }

    public function test_the_base_screens_refuse_to_disable_delete_or_empty_the_mail_of_a_head(): void
    {
        [, $head] = $this->baseHead();
        $expected = "{$head->name}さんは決裁の部門「住宅事業部」の部門長に指定されています。先に後任を設定してください。";
        $admin    = $this->executive();

        // ① 行の無効化
        $this->actingAs($admin)->patch(route('admin.users.toggleStatus', $head), ['status' => UserStatus::Inactive->value])
            ->assertSessionHas('error', $expected);
        // ② 削除
        $this->actingAs($admin)->delete(route('admin.users.destroy', $head))->assertSessionHas('error', $expected);
        // ③ 編集でメールアドレスを空に
        $this->actingAs($admin)->put(route('admin.users.update', $head), $this->editPayload($head, ['email' => '']))
            ->assertSessionHas('error', $expected);
        // ④ 編集で無効化
        $this->actingAs($admin)->put(route('admin.users.update', $head), $this->editPayload($head, ['status' => UserStatus::Inactive->value]))
            ->assertSessionHas('error', $expected);

        $head->refresh();
        $this->assertTrue($head->isActive());
        $this->assertFalse($head->trashed());
        $this->assertSame('head@example.com', $head->email);
    }

    public function test_a_reviewer_is_protected_too(): void
    {
        $w        = $this->approvalWorld();
        $reviewer = $w['reviewer'];

        $this->actingAs($this->executive())->delete(route('admin.users.destroy', $reviewer))
            ->assertSessionHas('error', "{$reviewer->name}さんは決裁の部門「総務部」の審査担当者に指定されています。先に後任を設定してください。");

        $this->assertFalse($reviewer->fresh()->trashed());
    }

    /** 決裁の管理者の画面（決裁のみ利用者の無効化）も同じ */
    public function test_the_approval_user_screen_refuses_to_disable_an_approval_only_head(): void
    {
        $w    = $this->approvalWorld();
        $head = $this->approvalOnlyUser(['name' => '決裁 部門長', 'email' => 'h2@example.com']);
        $w['dept']->update(['head_user_id' => $head->id]);

        $this->actingAs($this->approvalAdmin())
            ->patch(route('approvals.admin.users.toggleStatus', $head), ['status' => UserStatus::Inactive->value])
            ->assertSessionHas('error', '決裁 部門長さんは決裁の部門「住宅事業部」の部門長に指定されています。先に部門の管理で後任を設定してください。');

        $this->assertTrue($head->fresh()->isActive());
    }

    /** 有効に戻すのは止めない・指定されていない人は今までどおり */
    public function test_others_are_not_affected(): void
    {
        $w     = $this->approvalWorld();
        $other = $this->baseUser(['name' => '一般 社員']);

        $this->actingAs($this->executive())->patch(route('admin.users.toggleStatus', $other), ['status' => UserStatus::Inactive->value])
            ->assertSessionMissing('error');

        $this->assertFalse($other->fresh()->isActive());
    }
}
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter AssignmentGuardTest
```

Expected: FAIL（部門長を無効化・削除できてしまう）

- [ ] **Step 3: 基幹の利用者管理に歯止めを足す**

`app/Http/Controllers/Admin/UserController.php`

(a) `update()` の社長の守り（`if ($user->isApprovalPresident() && (...)) { return $this->refuseToTouchThePresident($user); }`）の**すぐ後**に足す:

```php
        // 部門長・審査担当者に指定されている人も、無効化・メールアドレスを空にできない（要件 12.6・段階2 設計書 §5.4）
        if (($validated['status'] === UserStatus::Inactive->value || ($validated['email'] ?? null) === null)
            && ($label = $user->approvalAssignmentLabel()) !== null
        ) {
            return $this->refuseToTouchAnAssignee($user, $label);
        }
```

(b) `toggleStatus()` の社長の守り（`if ($newStatus === UserStatus::Inactive->value && $user->isApprovalPresident()) {...}`）の**すぐ後**に足す:

```php
        // 部門長・審査担当者も無効化できない（要件 12.6）。有効化は止めない
        if ($newStatus === UserStatus::Inactive->value && ($label = $user->approvalAssignmentLabel()) !== null) {
            return $this->refuseToTouchAnAssignee($user, $label);
        }
```

(c) `destroy()` の社長の守り（`if ($user->isApprovalPresident()) {...}`）の**すぐ後**に足す:

```php
        // 部門長・審査担当者も削除できない（要件 12.6）
        if (($label = $user->approvalAssignmentLabel()) !== null) {
            return $this->refuseToTouchAnAssignee($user, $label);
        }
```

(d) `refuseToTouchThePresident()` の**すぐ後**にメソッドを足す:

```php
    /**
     * 部門長・審査担当者を守る断り（要件 12.6・段階2 設計書 §5.4）。
     *
     * ⚠ 社長の守りと同じ入口 4 つ（編集の無効化・編集のメール空・行の無効化・削除）に置く。
     *   文言は `User::approvalAssignmentLabel()` が完成させる（前後に言葉を足さない）。
     */
    private function refuseToTouchAnAssignee(User $user, string $label): \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('admin.users.index')
            ->with('error', "{$user->name}さんは{$label}に指定されています。先に後任を設定してください。");
    }
```

- [ ] **Step 4: 決裁の利用者管理に歯止めを足す**

`app/Http/Controllers/Approval/UserController.php` の `toggleStatus()` で、`$validated = $request->validate(...)` の**すぐ後**に足す:

```php
        // 部門長・審査担当者に指定されている人は無効化できない（要件 12.6・段階2 設計書 §5.4）
        if ($validated['status'] === UserStatus::Inactive->value && ($label = $user->approvalAssignmentLabel()) !== null) {
            return redirect()->route('approvals.admin.users.index')
                ->with('error', "{$user->name}さんは{$label}に指定されています。先に部門の管理で後任を設定してください。");
        }
```

- [ ] **Step 5: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'AssignmentGuardTest|UserManagementApprovalTest|ApprovalUserManagementTest'
```

Expected: PASS（段階1 の社長の守りのテストも緑のまま）

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add app/Http/Controllers/Admin/UserController.php app/Http/Controllers/Approval/UserController.php tests/Feature/Approval/Phase2/AssignmentGuardTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
feat(approval): 部門長と審査担当者を無効化・削除・メール空から守る

社長の守りと同じ入口 4 つに置き、後任を設定するよう案内する。決裁の管理者の画面の
無効化も同じ。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 11: 申請種類の管理（画面⑨）

**Files:**
- Create: `app/Http/Controllers/Approval/TypeController.php`・`resources/views/approvals/admin/types.blade.php`
- Modify: `routes/approval.php`（管理のグループに 4 本）
- Modify: `lang/ja/validation.php`（和名 3 つ）
- Modify: `tests/Feature/Approval/ApprovalAdminGateTest.php`（パラメータ `approvalType` と行数の一覧）
- Test: `tests/Feature/Approval/Phase2/TypeManagementTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/TypeManagementTest.php`

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Models\ApprovalSettingLog;
use App\Models\ApprovalType;
use App\Models\User;
use App\Support\Approval\BodyTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/** 申請種類の管理（画面⑨・設計書 §5.5） */
class TypeManagementTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ParsesForms;

    private function indexHtml(User $admin): string
    {
        return $this->actingAs($admin)->get(route('approvals.admin.types.index'))->assertOk()->getContent();
    }

    public function test_the_list_shows_the_review_department_the_state_and_the_count(): void
    {
        $w = $this->approvalWorld();
        $this->draftFor($w);
        $w['type']->update(['is_active' => false]);

        $html = $this->indexHtml($this->approvalAdmin());

        $this->assertStringContainsString($w['type']->name, $html);
        $this->assertStringContainsString('総務部', $html);
        $this->assertStringContainsString('停止', $html);
        $this->assertStringContainsString('1 件', $html);
        // 小窓を動かす部品（@push('scripts') の中身）が描かれていること（LayoutScriptStackTest と同じ見方）
        $this->assertStringContainsString('function approvalTypes()', $html);
    }

    /** 描いた追加のフォームをそのまま送り返す（見出しの初期値は標準の 6 つ） */
    public function test_a_type_can_be_created_from_the_rendered_form(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();

        $form = $this->parseForm($this->indexHtml($admin), 'action="' . route('approvals.admin.types.store') . '"');
        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields']);
        $this->assertSame(BodyTemplate::DEFAULT, $form['fields']['headings']);
        $this->assertSame('1', $form['fields']['is_active']);

        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], [
            'name' => '人事', 'review_department_id' => (string) $w['reviewDept']->id,
        ]))->assertRedirect(route('approvals.admin.types.index'));

        $type = ApprovalType::where('name', '人事')->sole();
        $this->assertTrue($type->is_active);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'type.created')->count());
    }

    /** チェックを外して保存すると停止（送られないチェックボックス） */
    public function test_a_type_can_be_stopped(): void
    {
        $w = $this->approvalWorld();

        $this->actingAs($this->approvalAdmin())->put(route('approvals.admin.types.update', $w['type']), [
            'name' => $w['type']->name, 'headings' => BodyTemplate::DEFAULT,
            'review_department_id' => (string) $w['reviewDept']->id, 'sort_order' => '1',
        ])->assertRedirect(route('approvals.admin.types.index'));

        $this->assertFalse($w['type']->fresh()->is_active);
        $this->assertSame(['is_active' => false], ApprovalSettingLog::where('action', 'type.updated')->sole()->new_values);
    }

    public function test_the_name_is_unique_and_a_review_department_is_required(): void
    {
        $w = $this->approvalWorld();

        $this->actingAs($this->approvalAdmin())->post(route('approvals.admin.types.store'), [
            'name' => $w['type']->name, 'headings' => 'x', 'review_department_id' => '', 'sort_order' => '0', 'is_active' => '1',
        ])->assertSessionHasErrors([
            'name' => 'この種類名は既に登録されています。',
            'review_department_id' => '審査部門を選択してください。',
        ]);
    }

    public function test_a_type_with_requests_cannot_be_deleted(): void
    {
        $w = $this->approvalWorld();
        $this->draftFor($w);

        $this->actingAs($this->approvalAdmin())->delete(route('approvals.admin.types.destroy', $w['type']))
            ->assertSessionHas('error', 'この種類の申請が 1 件あるため削除できません。使わなくなった種類は「停止」にしてください。');

        $this->assertNotNull($w['type']->fresh());
    }

    public function test_an_unused_type_can_be_deleted(): void
    {
        $w = $this->approvalWorld();

        $this->actingAs($this->approvalAdmin())->delete(route('approvals.admin.types.destroy', $w['type']))
            ->assertSessionHas('success', '申請の種類を削除しました。');

        $this->assertNull($w['type']->fresh());
        $this->assertSame(1, ApprovalSettingLog::where('action', 'type.deleted')->count());
    }

    /** 決裁の管理者でない人は開けない（管理の門番。全ルートの確かめは ApprovalAdminGateTest） */
    public function test_someone_who_is_not_an_admin_gets_403(): void
    {
        $this->actingAs($this->baseUser())->get(route('approvals.admin.types.index'))->assertForbidden();
    }
}
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter TypeManagementTest
```

Expected: FAIL（`Route [approvals.admin.types.index] not defined.`）

- [ ] **Step 3: コントローラと画面を書く**

`app/Http/Controllers/Approval/TypeController.php`

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalType;
use App\Support\Approval\SettingLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 申請種類の管理（画面⑨・段階2 設計書 §5.5）。
 *
 * ⚠ 段階2 は 5W2H の見出しの形だけ。金額の明細表・件名の決まり文句・使える部門などは段階5 で足す。
 * ⚠ 申請が 1 件でもある種類は削除できない（停止を使う）。審査部門を変えても回覧中の申請は
 *   提出したときの審査部門のまま（D11。段階の行が控えを持つ）。
 */
class TypeController extends Controller
{
    public function index()
    {
        $types = ApprovalType::with('reviewDepartment.company')->withCount('requests')->ordered()->get();

        $departments = ApprovalDepartment::with('company')->get()
            ->sortBy(fn (ApprovalDepartment $d) => [$d->company->sort_order, $d->company_id, $d->sort_order, $d->id])
            ->values();

        return view('approvals.admin.types', compact('types', 'departments'));
    }

    public function store(Request $request)
    {
        $validated = $this->validateType($request);

        $type = ApprovalType::create($validated);
        SettingLogger::record('type.created', 'approval_type', $type->id, [], $validated);

        return $this->back('申請の種類を登録しました。');
    }

    public function update(Request $request, ApprovalType $approvalType)
    {
        $validated = $this->validateType($request, $approvalType);
        $before    = $approvalType->only(array_keys($validated));

        $approvalType->update($validated);
        SettingLogger::recordChange('type.updated', 'approval_type', $approvalType->id, $before, $validated);

        return $this->back('申請の種類を更新しました。');
    }

    public function destroy(ApprovalType $approvalType)
    {
        $count = $approvalType->requests()->count();

        if ($count > 0) {
            return $this->back(null, "この種類の申請が {$count} 件あるため削除できません。使わなくなった種類は「停止」にしてください。");
        }

        $before = $approvalType->only(['name', 'headings', 'review_department_id', 'sort_order', 'is_active']);
        $id     = $approvalType->id;
        $approvalType->delete();

        SettingLogger::record('type.deleted', 'approval_type', $id, $before, []);

        return $this->back('申請の種類を削除しました。');
    }

    private function validateType(Request $request, ?ApprovalType $current = null): array
    {
        // チェックボックスは外すと送られない（送られなければ停止）
        $request->merge(['is_active' => $request->boolean('is_active')]);

        return $request->validate([
            'name'                 => ['required', 'string', 'max:50', Rule::unique('approval_types', 'name')->ignore($current?->id)],
            'headings'             => ['required', 'string', 'max:2000'],
            'review_department_id' => ['required', 'integer', Rule::exists('approval_departments', 'id')],
            'sort_order'           => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active'            => ['boolean'],
        ], [
            'name.required' => '種類名を入力してください。',
            'name.max' => '種類名は50文字以内で入力してください。',
            'name.unique' => 'この種類名は既に登録されています。',
            'headings.required' => '見出しを入力してください。',
            'headings.max' => '見出しは2000文字以内で入力してください。',
            'review_department_id.required' => '審査部門を選択してください。',
            'sort_order.required' => '表示順を入力してください。',
        ], [
            'name' => '種類名',
        ]);
    }

    private function back(?string $success, ?string $error = null)
    {
        $redirect = redirect()->route('approvals.admin.types.index');

        return $error !== null ? $redirect->with('error', $error) : $redirect->with('success', $success);
    }
}
```

`resources/views/approvals/admin/types.blade.php`

```blade
@extends('layouts.app')

@section('title', '申請種類の管理')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">申請種類の管理</span>
@endsection

@section('content')
<div x-data="approvalTypes()" x-cloak>

    {{-- 成功・失敗の帯はレイアウトが出す（ここで出すと画面に 2 回出る）。$errors だけ各ビューの責任 --}}
    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <h1 class="text-lg font-bold text-gray-900 mb-2">申請種類の管理</h1>
    <p class="text-[12px] text-gray-500 mb-5 max-w-[720px]">
        申請の画面で選ぶ種類です。種類ごとに、本文に最初から入る見出しと、回る審査部門を決めます。
        使わなくなった種類は「停止」にします（新しい申請で選べなくなるだけで、過去の申請はそのまま）。
    </p>

    <section class="bg-white rounded-lg border border-gray-200">
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
            <h2 class="text-[14px] font-bold text-gray-900">申請の種類</h2>
            {{-- ⚠ 押せない理由はボタン自身の title では出ない（ホバーを受ける span で包む。Bug #43） --}}
            <span @if($departments->isEmpty()) title="先に部門の管理で部門を登録してください。" @endif style="display: inline-flex;">
                <button type="button" @click="createModal = true" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-semibold rounded-md cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed" @if($departments->isEmpty()) disabled @endif>種類を追加</button>
            </span>
        </div>
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
        <table class="w-full min-w-[760px] border-collapse">
            <thead>
                <tr>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">種類名</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">審査部門</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">状態</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">申請</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">表示順</th>
                    <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($types as $type)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-900">{{ $type->name }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $type->reviewDepartment->company->name }}・{{ $type->reviewDepartment->name }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 whitespace-nowrap">
                            <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $type->is_active ? 'background: #d1fae5; color: #065f46;' : 'background: #f3f4f6; color: #6b7280;' }}">{{ $type->is_active ? '利用中' : '停止' }}</span>
                        </td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $type->requests_count }} 件</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $type->sort_order }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-right whitespace-nowrap">
                            <button type="button" @click="openEdit({{ \Illuminate\Support\Js::from($type->only(['id', 'name', 'headings', 'review_department_id', 'sort_order', 'is_active'])) }})" class="text-[12px] text-blue-600 hover:underline cursor-pointer bg-transparent border-none p-0">編集</button>
                            <span class="text-gray-200 mx-1">|</span>
                            <form method="POST" action="{{ route('approvals.admin.types.destroy', $type) }}" class="inline" onsubmit="return confirm('この種類を削除しますか。');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-[12px] text-red-600 hover:underline cursor-pointer bg-transparent border-none p-0">削除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-[13px] text-gray-400">申請の種類が登録されていません。</td></tr>
                @endforelse
            </tbody>
        </table>
            </div>{{-- /scroll-hint-inner --}}
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>{{-- /scroll-hint --}}
    </section>

    {{-- 種類の追加（追加と編集でフォームを分ける。部門の管理と同じ形）--}}
    <div x-show="createModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="createModal = false" class="bg-white rounded-xl w-full max-w-[560px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" action="{{ route('approvals.admin.types.store') }}">
                @csrf
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">種類の追加</div>
                <div class="px-6 py-4 space-y-3.5">
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">種類名<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="name" required maxlength="50" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">審査部門<span class="text-red-600 ml-0.5">*</span></label>
                        {{-- ⚠ <option> は @@foreach で静的に出す（Bug #16） --}}
                        <select name="review_department_id" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                            @foreach($departments as $department)
                                <option value="{{ $department->id }}">{{ $department->company->name }}・{{ $department->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">5W2H の見出し<span class="text-red-600 ml-0.5">*</span></label>
                        <textarea name="headings" required maxlength="2000" rows="12" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] font-mono leading-relaxed">{{ \App\Support\Approval\BodyTemplate::DEFAULT }}</textarea>
                        <p class="text-[11px] text-gray-400 mt-1">申請の本文に最初から入る見出し。「いつ」「いくら」は実施時期・金額の欄で書くので入れない（要件 5.2）</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">表示順<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="number" name="sort_order" value="0" required inputmode="numeric" min="0" max="9999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <label class="flex items-center gap-2 text-[13px] text-gray-800 cursor-pointer">
                        <input type="checkbox" name="is_active" value="1" checked>
                        利用中（申請の画面で選べる）
                    </label>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="createModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">保存する</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 種類の編集 --}}
    <div x-show="editModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="editModal = false" class="bg-white rounded-xl w-full max-w-[560px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" :action="'{{ url('approvals/admin/types') }}/' + editId">
                @csrf
                @method('PUT')
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">種類の編集</div>
                <div class="px-6 py-4 space-y-3.5">
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">種類名<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="name" x-model="editName" required maxlength="50" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">審査部門<span class="text-red-600 ml-0.5">*</span></label>
                        <select name="review_department_id" x-model="editDepartmentId" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                            @foreach($departments as $department)
                                <option value="{{ $department->id }}">{{ $department->company->name }}・{{ $department->name }}</option>
                            @endforeach
                        </select>
                        <p class="text-[11px] text-gray-400 mt-1">変えても、回覧中の申請は提出したときの審査部門のまま回ります（出し直すと新しい設定）</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">5W2H の見出し<span class="text-red-600 ml-0.5">*</span></label>
                        <textarea name="headings" x-model="editHeadings" required maxlength="2000" rows="12" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] font-mono leading-relaxed"></textarea>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">表示順<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="number" name="sort_order" x-model="editSort" required inputmode="numeric" min="0" max="9999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <label class="flex items-center gap-2 text-[13px] text-gray-800 cursor-pointer">
                        <input type="checkbox" name="is_active" value="1" x-model="editActive">
                        利用中（外すと停止。新しい申請で選べなくなる）
                    </label>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="editModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">保存する</button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
function approvalTypes() {
    return {
        createModal: false,
        editModal: false,
        editId: null,
        editName: '',
        editDepartmentId: '',
        editHeadings: '',
        editSort: '0',
        editActive: true,

        openEdit(row) {
            this.editId = row.id;
            this.editName = row.name;
            this.editDepartmentId = String(row.review_department_id);
            this.editHeadings = row.headings;
            this.editSort = String(row.sort_order);
            this.editActive = row.is_active;
            this.editModal = true;
        }
    };
}
</script>
@endpush
```

- [ ] **Step 4: ルートを足す**

`routes/approval.php` の先頭の `use` に足す:

```php
use App\Http\Controllers\Approval\TypeController;
```

管理のグループ（`Route::middleware('approval.admin')->prefix('approvals/admin')->name('approvals.admin.')->group(...)`）の最後（ドメインの 2 本の後）に足す:

```php

    // 申請種類の管理（段階2 設計書 §5.5）。使い始める前から使える（準備の画面。D1）
    // ⚠ パラメータ名は `{approvalType}`（`{type}` は基幹で別の意味に使われうるので避ける）
    Route::get('/types', [TypeController::class, 'index'])->name('types.index');
    Route::post('/types', [TypeController::class, 'store'])->name('types.store');
    Route::put('/types/{approvalType}', [TypeController::class, 'update'])->name('types.update');
    Route::delete('/types/{approvalType}', [TypeController::class, 'destroy'])->name('types.destroy');
```

- [ ] **Step 5: 和名を足す**

`lang/ja/validation.php` の段階2 の節（Task 9 で足した `'next_number' => ...` の次）に足す:

```php
        // 申請種類の管理（§5.5）。name は TypeController の validate() 第 3 引数で「種類名」に上書きする
        'headings'             => '見出し',
        'review_department_id' => '審査部門',
        'is_active'            => '利用中',
```

- [ ] **Step 6: 管理の画面の走査に種類を足す**

`tests/Feature/Approval/ApprovalAdminGateTest.php`

(a) `use` に足す:

```php
use App\Models\ApprovalType;
use App\Support\Approval\BodyTemplate;
```

(b) `test_every_admin_route_refuses_outsiders_without_revealing_ids()` の `$manageableUser = $this->makeManageableUser();` の次に足し、`$existingValues` に 1 行足す:

```php
        $type = ApprovalType::create([
            'name' => '購入・発注', 'headings' => BodyTemplate::DEFAULT,
            'review_department_id' => $department->id, 'sort_order' => 1, 'is_active' => true,
        ]);

        $existingValues = [
            'user' => (string) $manageableUser->id,
            'approvalCompany' => (string) $company->id,
            'approvalDepartment' => (string) $department->id,
            'mailDomain' => (string) $mailDomain->id,
            'approvalType' => (string) $type->id,
        ];
```

(c) `tableCounts()` に足す（権限の無い要求で種類・審査担当者・連番が変わらないこと）:

```php
            'approval_types' => DB::table('approval_types')->count(),
            'approval_reviewers' => DB::table('approval_reviewers')->count(),
            'approval_number_sequences' => DB::table('approval_number_sequences')->count(),
```

- [ ] **Step 7: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'TypeManagementTest|ApprovalAdminGateTest|LaunchGateTest|ApprovalOnlyLockoutTest|JapaneseValidationMessagesTest|MobileLayoutTest'
```

Expected: PASS

- [ ] **Step 8: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add app/Http/Controllers/Approval/TypeController.php resources/views/approvals/admin/types.blade.php routes/approval.php lang/ja/validation.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase2/TypeManagementTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
feat(approval): 申請種類の管理の画面を足す

種類名・5W2H の見出し・審査部門・表示順・利用中/停止。申請がある種類は削除できない
（停止を使う）。使い始める前から決裁の管理者が使える。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 12: 関連する決裁No の候補（`approvals.numbers.search`）

申請書の画面（Task 13）が入力中に呼ぶ検索を先に作る。見られる範囲（Task 8 の `RequestVisibility`）の、番号の付いた申請の番号と件名だけを返す。

**Files:**
- Create: `app/Http/Controllers/Approval/RelatedNumberController.php`
- Modify: `routes/approval.php`（申請を回す画面のグループを作り、1 本足す）
- Modify: `tests/Feature/Approval/ApprovalAdminGateTest.php`（`OPEN_TO_EVERY_USER` に 1 行）
- Test: `tests/Feature/Approval/Phase2/RelatedNumberSearchTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/RelatedNumberSearchTest.php`

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 関連する決裁No の候補（設計書 §5.6・D15。見られる範囲は §5.10 と同じ） */
class RelatedNumberSearchTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    /** 番号の付いた申請（決裁済み）。ここで見るのは検索だけなので、状態と番号は直接入れる */
    private function decided(array $world, string $number, string $subject): ApprovalRequest
    {
        $request = $this->draftFor($world, ['subject' => $subject]);
        DB::table('approval_requests')->where('id', $request->id)->update([
            'status' => 'approved', 'decision' => 'approve', 'number' => $number, 'round' => 1,
        ]);

        return $request->refresh();
    }

    /** 画面の fetch と同じヘッダーで呼ぶ（Bug #35） */
    private function search(User $user, string $query): TestResponse
    {
        return $this->actingAs($user)->getJson(
            route('approvals.numbers.search') . '?q=' . rawurlencode($query),
            ['X-Requested-With' => 'XMLHttpRequest']
        );
    }

    public function test_it_finds_a_number_typed_in_full_width_and_a_subject(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->decided($w, 'R8-J-001', '社用車の購入');
        $this->decided($w, 'R8-J-002', '事務所の改装');

        $this->search($w['applicant'], 'ｒ８－ｊ－００１')->assertOk()
            ->assertExactJson(['items' => [['number' => 'R8-J-001', 'subject' => '社用車の購入']]]);

        $this->search($w['applicant'], '改装')->assertOk()
            ->assertExactJson(['items' => [['number' => 'R8-J-002', 'subject' => '事務所の改装']]]);
    }

    /** 見られない申請と、番号の無い申請は出さない */
    public function test_it_returns_only_numbered_requests_the_user_can_see(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $stranger  = $this->approvalOnlyUser(['name' => '賃貸 次郎']);
        $otherDept = $this->approvalDepartment($w['company'], ['name' => '賃貸事業部', 'code' => 'K']);
        $stranger->approvalDepartments()->attach($otherDept->id);
        $this->decided(['applicant' => $stranger, 'dept' => $otherDept, 'type' => $w['type']], 'R8-K-001', '他部門の決裁');
        $this->draftFor($w, ['subject' => '番号のない下書き']);

        $this->search($w['applicant'], 'R8')->assertOk()->assertExactJson(['items' => []]);
        $this->search($w['applicant'], '下書き')->assertOk()->assertExactJson(['items' => []]);

        // 全件閲覧者には見える（同じ規則の別の行）
        $this->search($this->viewAllUser(), 'r8-k')->assertOk()
            ->assertExactJson(['items' => [['number' => 'R8-K-001', 'subject' => '他部門の決裁']]]);
    }

    public function test_a_blank_or_malformed_query_returns_nothing(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->decided($w, 'R8-J-001', '社用車の購入');

        $this->search($w['applicant'], '　 ')->assertOk()->assertExactJson(['items' => []]);

        // 配列で送られても 500 にしない
        $this->actingAs($w['applicant'])
            ->getJson(route('approvals.numbers.search') . '?q[]=R8', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertExactJson(['items' => []]);
    }

    public function test_at_most_ten_candidates_come_back_newest_first(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        for ($i = 1; $i <= 12; $i++) {
            $this->decided($w, sprintf('R8-J-%03d', $i), "決裁{$i}");
        }

        $items = $this->search($w['applicant'], 'R8-J')->assertOk()->json('items');

        $this->assertCount(10, $items);
        $this->assertSame('R8-J-012', $items[0]['number']);
    }

    /** 使い始める前は 404（Ajax。設計書 §5.2） */
    public function test_before_launch_the_search_is_not_found(): void
    {
        $w = $this->approvalWorld();

        $this->search($w['applicant'], 'R8')->assertNotFound();
    }
}
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter RelatedNumberSearchTest
```

Expected: FAIL（`Route [approvals.numbers.search] not defined.`）

- [ ] **Step 3: コントローラを書く**

`app/Http/Controllers/Approval/RelatedNumberController.php`

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Support\Approval\RelatedNumbers;
use App\Support\Approval\RequestVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 関連する決裁No の候補（設計書 §5.6・D15）。申請書の画面が入力中に Ajax の GET で呼ぶ。
 *
 * 見られる範囲（RequestVisibility）の、番号の付いた申請だけを返す（番号と件名）。
 * ⚠ 呼ぶ側の fetch は X-Requested-With を付ける（Bug #35。付けないとセッションの直前 URL が
 *   この JSON で上書きされる）。
 * ⚠ LIKE の % と _ は逃がさない（アプリのほかの検索と同じ。見られる範囲の中で候補が広がるだけ）。
 */
class RelatedNumberController extends Controller
{
    /** 候補の数の上限 */
    private const LIMIT = 10;

    public function search(Request $request): JsonResponse
    {
        // ⚠ ?q[]=… のように配列で来ても 500 にしない（文字列でなければ空として扱う）
        $raw  = $request->query('q');
        $text = is_string($raw) ? preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $raw) : '';

        if ($text === '') {
            return response()->json(['items' => []]);
        }

        // 番号は全角・小文字でも当たるようにそろえる。件名は入力のまま探す
        $number = RelatedNumbers::normalize($text);

        $items = RequestVisibility::apply(ApprovalRequest::query(), $request->user())
            ->whereNotNull('number')
            ->where(fn (Builder $q) => $q->where('number', 'like', "%{$number}%")->orWhere('subject', 'like', "%{$text}%"))
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['number', 'subject'])
            ->map(fn (ApprovalRequest $found) => ['number' => $found->number, 'subject' => $found->subject])
            ->all();

        return response()->json(['items' => $items]);
    }
}
```

- [ ] **Step 4: ルートを足す**

`routes/approval.php` の先頭の `use` に足す:

```php
use App\Http\Controllers\Approval\RelatedNumberController;
```

ファイルの**最後**（管理のグループの `});` の後）に足す:

```php

/*
|--------------------------------------------------------------------------
| 申請を回す画面（段階2。使い始めるまで誰にも見せない）
|--------------------------------------------------------------------------
|
| ⚠ 門番 `approval.launched` は、使い始める前（approval_settings.launched_at が空）は
|   画面を開く GET をホームへ送り、それ以外を 404 にする（段階2 設計書 §5.2・D1）。
|   このグループの外に申請の画面を足さないこと（LaunchGateTest が全件分類で止める）。
| ⚠ パラメータ名は `{approvalRequest}` / `{approvalAttachment}`（モデル名の camelCase）。
| ⚠ `/requests/create` を `/requests/{approvalRequest}` より前に置く（登録順がマッチの優先順）。
|
*/
Route::middleware('approval.launched')->prefix('approvals')->name('approvals.')->group(function () {

    // 関連する決裁No の候補（Ajax・JSON。設計書 §5.6）
    Route::get('/numbers', [RelatedNumberController::class, 'search'])->name('numbers.search');
});
```

- [ ] **Step 5: 管理の画面の走査に分類を足す**

`tests/Feature/Approval/ApprovalAdminGateTest.php` の `OPEN_TO_EVERY_USER` に 1 行足す:

```php
    private const OPEN_TO_EVERY_USER = [
        'approvals.home' => '決裁のホーム（全ログイン利用者が入れる。設計書 §5.1・§5.15）',
        // 段階2（使い始めてから。門番 approval.launched は LaunchGateTest が見る）
        'approvals.numbers.search' => '関連する決裁No の候補（見られる範囲だけを返す。段階2 設計書 §5.6）',
    ];
```

- [ ] **Step 6: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'RelatedNumberSearchTest|LaunchGateTest|ApprovalAdminGateTest|ApprovalOnlyLockoutTest'
```

Expected: PASS

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add app/Http/Controllers/Approval/RelatedNumberController.php routes/approval.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase2/RelatedNumberSearchTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
feat(approval): 関連する決裁No の候補を返す検索を足す

見られる範囲の、番号の付いた申請の番号と件名を 10 件まで返す。番号は全角・小文字でも
当たる。使い始める前は 404。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 13: 申請書（作成・編集・下書き・提出・削除・コピー）と詳細の中身（画面②③）

**Files:**
- Create: `app/Http/Controllers/Approval/RequestController.php`
- Create: `resources/views/approvals/requests/form.blade.php`・`show.blade.php`・`_actions.blade.php`
- Modify: `routes/approval.php`（申請を回す画面のグループに 6 本）
- Modify: `lang/ja/validation.php`（和名 8 つ）
- Modify: `tests/Feature/Approval/ApprovalAdminGateTest.php`（`OPEN_TO_EVERY_USER` に 6 行）
- Modify: `tests/Feature/AjaxErrorFeedbackTest.php`（`VIEWS_NULL_RETURN` に申請書の画面）
- Test: `tests/Feature/Approval/Phase2/RequestFormTest.php`

⚠ 詳細の画面はこの Task では「中身」だけ。添付は Task 14、回る順番・記録・判断の操作は Task 15 で足す。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/RequestFormTest.php`

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Support\Approval\BodyTemplate;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 申請書（画面②）・下書き・提出・削除・コピーと、詳細の中身（設計書 §5.6・§5.12）。
 *
 * ⚠ 画面の文言を見るテストでは assertSessionHas*() を呼ばない（呼ぶとエラーの表示が消える。Bug #49）。
 */
class RequestFormTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ParsesForms;

    /** 中身のそろった入力（そのまま提出できる） */
    private function filled(array $w, array $overrides = []): array
    {
        return array_merge([
            'type_id'         => (string) $w['type']->id,
            'department_id'   => (string) $w['dept']->id,
            'subject'         => '社用車の購入',
            'amount'          => '2,850,000',
            'schedule'        => '2026年10月',
            'body'            => "■ なぜ（目的・理由）\n・老朽化のため",
            'related_numbers' => [],
        ], $overrides);
    }

    /** 描いたフォーム（関連する決裁No は JS が描くので、x-for の中の空の hidden は送らない） */
    private function renderedForm(string $html, string $action): array
    {
        $form = $this->parseForm($html, 'action="' . $action . '"');
        unset($form['fields']['related_numbers[]']);

        return $form;
    }

    public function test_the_create_page_offers_active_types_and_preselects_the_only_department(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $stopped = $this->approvalType($w['reviewDept'], ['name' => '停止した種類', 'is_active' => false]);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.create'))->assertOk()->getContent();
        $form = $this->renderedForm($html, route('approvals.requests.store'));

        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields']);
        $this->assertSame((string) $w['dept']->id, $form['fields']['department_id']);
        $this->assertSame('', $form['fields']['type_id']);
        $this->assertSame('', $form['fields']['amount'], '金額に 0 の既定値を入れない');
        $this->assertStringContainsString($w['type']->name, $html);
        $this->assertStringNotContainsString($stopped->name, $html);

        // 見出しは種類を選んだときに JS が入れる。対応表と部品の定義が同じページに載っていること
        $this->assertStringContainsString(Js::from([$w['type']->id => BodyTemplate::DEFAULT])->toHtml(), $html);
        $this->assertStringContainsString('function approvalRequestForm()', $html);
        // 保存と提出の区別は、サーバーが描いたボタンの値が送る（Bug #47）
        $this->assertStringContainsString('name="intent" value="save"', $html);
        $this->assertStringContainsString('name="intent" value="submit"', $html);

        // 候補の検索は X-Requested-With を付けて呼ぶ（Bug #35。基幹の走査は /api/ しか見ないのでここで見る）
        $fetchAt = strpos($html, "fetch('" . route('approvals.numbers.search'));
        $this->assertNotFalse($fetchAt, '候補の検索の fetch が見つからない');
        $this->assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", substr($html, $fetchAt, 300));
    }

    /** 下書きは途中でも保存できる（D13）。初めての保存で行ができ、編集の画面へ移る */
    public function test_a_partial_draft_can_be_saved(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $response = $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), ['subject' => '途中まで', 'intent' => 'save']);

        $request = ApprovalRequest::sole();
        $response->assertRedirect(route('approvals.requests.edit', $request))->assertSessionHas('success', '下書きを保存しました。');
        $this->assertSame(ApprovalStatus::Draft, $request->status);
        $this->assertSame('途中まで', $request->subject);
        $this->assertNull($request->type_id);
        $this->assertSame($w['applicant']->id, $request->user_id);
    }

    /** 金額はカンマ・全角・「円」を落とし、関連する決裁No はそろえて重複を除く */
    public function test_inputs_are_normalized_before_saving(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->filled($w, [
            'amount'          => '２８，５００，０００円',
            'related_numbers' => ['ｒ７－ｊ－０１５', 'R7-J-015', ' r8-s-001 ', ''],
            'intent'          => 'save',
        ]))->assertSessionHasNoErrors();

        $request = ApprovalRequest::sole();
        $this->assertSame(28500000, $request->amount);
        $this->assertSame(['R7-J-015', 'R8-S-001'], $request->related_numbers);
    }

    public function test_the_shape_of_inputs_is_checked_even_for_a_draft(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $stopped   = $this->approvalType($w['reviewDept'], ['is_active' => false]);
        $otherDept = $this->approvalDepartment($w['company']);

        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), [
            'type_id'         => (string) $stopped->id,
            'department_id'   => (string) $otherDept->id,
            'subject'         => str_repeat('あ', 101),
            'amount'          => 'たくさん',
            'related_numbers' => ['R8-J-1'],
            'intent'          => 'save',
        ])->assertRedirect(route('approvals.requests.create'))->assertSessionHasErrors([
            'type_id'           => '選んだ申請の種類は使えません。選び直してください。',
            'department_id'     => '申請部門は、自分の所属部門から選んでください。',
            'subject'           => '件名は100文字以下で入力してください。',
            'amount'            => '金額は整数で入力してください。',
            'related_numbers.0' => '関連する決裁No「R8-J-1」の形が違います（例: R8-J-001）。',
        ]);

        $this->assertSame(0, ApprovalRequest::count());
    }

    public function test_more_than_ten_related_numbers_are_refused(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $numbers = array_map(fn (int $i) => sprintf('R8-J-%03d', $i), range(1, 11));

        $this->actingAs($w['applicant'])
            ->post(route('approvals.requests.store'), $this->filled($w, ['related_numbers' => $numbers, 'intent' => 'save']))
            ->assertSessionHasErrors(['related_numbers' => '関連する決裁No は 10 個までです。']);
    }

    /** 保存と同時に提出する（計画 §0.8 の 2）。提出すると詳細の画面へ */
    public function test_a_complete_request_can_be_submitted_from_the_form(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $response = $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->filled($w, ['intent' => 'submit']));

        $request = ApprovalRequest::sole();
        $response->assertRedirect(route('approvals.requests.show', $request))->assertSessionHas('success', '提出しました。');
        $this->assertSame(ApprovalStatus::HeadReview, $request->status);
        $this->assertSame(1, $request->round);
    }

    /** 提出できないときも中身は下書きとして残る（計画 §0.11） */
    public function test_a_refused_submission_keeps_the_draft(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $response = $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), [
            'department_id' => (string) $w['dept']->id,
            'amount'        => '1000',
            'body'          => BodyTemplate::DEFAULT,
            'intent'        => 'submit',
        ]);

        $request = ApprovalRequest::sole();
        $response->assertRedirect(route('approvals.requests.edit', $request))
            ->assertSessionHasErrors(['submit' => '申請の種類を選んでください。'])
            ->assertSessionHasErrors(['submit' => '件名を入力してください。'])
            ->assertSessionHasErrors(['submit' => '重点ポイント（5W2H）が見出しのままです。中身を書いてください。']);
        $this->assertSame(ApprovalStatus::Draft, $request->status);
        $this->assertSame(1000, $request->amount);
    }

    /** 理由は編集の画面の上にすべて出る（⚠ assertSessionHas* を呼ばずに描く。Bug #49） */
    public function test_the_refusal_reasons_are_shown_on_the_edit_page(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->draftFor($w, ['subject' => null, 'body' => BodyTemplate::DEFAULT]);

        $this->actingAs($w['applicant'])->put(route('approvals.requests.update', $request), $this->filled($w, [
            'subject' => '', 'body' => BodyTemplate::DEFAULT, 'intent' => 'submit',
        ]))->assertRedirect(route('approvals.requests.edit', $request));

        $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $request))->assertOk()->assertSeeInOrder([
            '提出することができませんでした',
            '件名を入力してください。',
            '重点ポイント（5W2H）が見出しのままです。中身を書いてください。',
        ]);
    }

    /** 編集の画面は保存した中身をそのまま描く（描いたフォームを送り返すと同じ中身になる） */
    public function test_the_edit_form_round_trips_the_saved_content(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->draftFor($w, ['related_numbers' => ['R7-J-015']]);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $request))->assertOk()->getContent();
        $form = $this->renderedForm($html, route('approvals.requests.update', $request));

        $this->assertSame('PUT', $form['method']);
        $this->assertSame((string) $w['type']->id, $form['fields']['type_id']);
        $this->assertSame((string) $w['dept']->id, $form['fields']['department_id']);
        $this->assertSame('2,850,000', $form['fields']['amount']);
        // 関連する決裁No は JS（x-for）が描くので、初期値を見る
        $this->assertStringContainsString(Js::from(['R7-J-015'])->toHtml(), $html);

        $this->actingAs($w['applicant'])
            ->post($form['action'], $form['fields'] + ['related_numbers' => ['R7-J-015'], 'intent' => 'save'])
            ->assertRedirect(route('approvals.requests.edit', $request));

        $fresh = $request->fresh();
        $this->assertSame('社用車の購入', $fresh->subject);
        $this->assertSame(2850000, $fresh->amount);
        $this->assertSame('2026年10月', $fresh->schedule);
        $this->assertSame("■ なぜ（目的・理由）\n・老朽化のため", $fresh->body);
        $this->assertSame(['R7-J-015'], $fresh->related_numbers);
    }

    /** 差戻し中の申請は、種類が停止していても保存して出し直せる（D10）。差戻しの理由が上に出る */
    public function test_a_returned_request_keeps_its_stopped_type_and_can_be_resubmitted(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, '金額の根拠を足してください');
        $w['type']->update(['is_active' => false]);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $request))->assertOk()->getContent();
        $this->assertStringContainsString('（停止中）', $html);
        $this->assertStringContainsString('金額の根拠を足してください', $html);

        $form = $this->renderedForm($html, route('approvals.requests.update', $request));
        $this->assertSame((string) $w['type']->id, $form['fields']['type_id']);

        $this->actingAs($w['applicant'])->post($form['action'], $form['fields'] + ['intent' => 'submit'])
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('success', '出し直しました。');

        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status);
        $this->assertSame(2, $request->fresh()->round);
    }

    /** 他人の下書きは、管理者・全件閲覧者・部門長でも見られない（404。設計書 §5.10） */
    public function test_someone_elses_draft_is_not_found_even_for_admins(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);

        foreach ([$this->approvalAdmin(), $this->viewAllUser(), $w['head']] as $user) {
            $this->actingAs($user)->get(route('approvals.requests.show', $draft))->assertNotFound();
            $this->actingAs($user)->get(route('approvals.requests.edit', $draft))->assertNotFound();
            $this->actingAs($user)->put(route('approvals.requests.update', $draft), ['subject' => '書き換え'])->assertNotFound();
            $this->actingAs($user)->delete(route('approvals.requests.destroy', $draft))->assertNotFound();
        }

        $this->assertSame('社用車の購入', $draft->fresh()->subject);
    }

    /** 回覧中の申請は申請者でも直せない（直せるのは下書きと差戻し中だけ。§5.11） */
    public function test_a_request_in_circulation_cannot_be_edited(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $request))
            ->assertRedirect(route('approvals.requests.show', $request));
        $this->actingAs($w['applicant'])->put(route('approvals.requests.update', $request), $this->filled($w, ['subject' => '書き換え', 'intent' => 'save']))
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('error', 'この申請は直せる状態ではありません。');

        $this->assertSame('社用車の購入', $request->fresh()->subject);
    }

    public function test_a_draft_that_was_never_submitted_can_be_deleted(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);

        $this->actingAs($w['applicant'])->delete(route('approvals.requests.destroy', $draft))
            ->assertRedirect(route('approvals.home'))
            ->assertSessionHas('success', '下書きを削除しました。');

        $this->assertNull($draft->fresh());
    }

    /** 一度でも提出した申請は、差し戻されても削除できない（記録を残す。要件 4.5） */
    public function test_a_request_that_was_submitted_cannot_be_deleted(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, '直してください');

        $this->actingAs($w['applicant'])->delete(route('approvals.requests.destroy', $request))
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('error', '削除できるのは、一度も提出していない下書きだけです。');

        $this->assertNotNull($request->fresh());
    }

    /** コピーして作成: 中身を写す。停止した種類と、今は所属していない部門は空にする（設計書 §5.6） */
    public function test_copying_a_request_prefills_a_new_form(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $source = $this->draftFor($w, ['related_numbers' => ['R7-J-015']]);
        $w['type']->update(['is_active' => false]);
        $other = $this->approvalDepartment($w['company'], ['name' => '賃貸事業部']);
        $w['applicant']->approvalDepartments()->sync([$other->id]);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.create', ['copy' => $source->id]))->assertOk()->getContent();
        $form = $this->renderedForm($html, route('approvals.requests.store'));

        $this->assertSame('社用車の購入', $form['fields']['subject']);
        $this->assertSame('2,850,000', $form['fields']['amount']);
        $this->assertSame('', $form['fields']['type_id']);
        // 写した部門は使えないので空に戻し、所属が 1 つだけならそれを選んでおく
        $this->assertSame((string) $other->id, $form['fields']['department_id']);
        $this->assertStringContainsString(Js::from(['R7-J-015'])->toHtml(), $html);
        $this->assertSame(1, ApprovalRequest::count(), '保存するまで下書きはできない');
    }

    /** 写せるのは自分の申請だけ（部門長は見られるが写せない） */
    public function test_only_your_own_request_can_be_copied(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $this->actingAs($w['head'])->get(route('approvals.requests.create', ['copy' => $request->id]))->assertNotFound();
    }

    /** 詳細: 中身と、見られる関連の申請へのリンク（紙の時代の番号は文字だけ） */
    public function test_the_detail_shows_the_content_and_links_related_requests(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $old = $this->draftFor($w, ['subject' => '前の決裁']);
        DB::table('approval_requests')->where('id', $old->id)->update(['status' => 'approved', 'decision' => 'approve', 'number' => 'R8-J-001', 'round' => 1]);
        // 申請者が見られない、他部門の決裁（番号はあるがリンクにしない）
        $stranger  = $this->approvalOnlyUser(['name' => '賃貸 次郎']);
        $otherDept = $this->approvalDepartment($w['company'], ['name' => '賃貸事業部', 'code' => 'K']);
        $stranger->approvalDepartments()->attach($otherDept->id);
        $hidden = $this->draftFor(['applicant' => $stranger, 'dept' => $otherDept, 'type' => $w['type']], ['subject' => '他部門の決裁']);
        DB::table('approval_requests')->where('id', $hidden->id)->update(['status' => 'approved', 'decision' => 'approve', 'number' => 'R8-K-001', 'round' => 1]);
        $request = $this->submittedFor($w, ['related_numbers' => ['R8-J-001', 'R8-K-001', 'R6-J-099']]);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->assertOk()->getContent();

        $this->assertStringContainsString('社用車の購入', $html);
        $this->assertStringContainsString('2,850,000円', $html);
        $this->assertStringContainsString('部門長確認中', $html);
        $this->assertStringContainsString('href="' . route('approvals.requests.show', $old) . '"', $html);
        $this->assertStringContainsString('<span class="font-mono mr-2">R6-J-099</span>', $html);
        // 見られない申請の番号は文字だけ（リンクから在ることを漏らさない。§5.10）
        $this->assertStringContainsString('<span class="font-mono mr-2">R8-K-001</span>', $html);
        $this->assertStringNotContainsString(route('approvals.requests.show', $hidden), $html);
    }

    public function test_the_detail_is_not_found_for_someone_who_cannot_see_it(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk();
        $this->actingAs($this->approvalOnlyUser())->get(route('approvals.requests.show', $request))->assertNotFound();
    }

    /** 社長は申請できない（D4）。作成の画面で先に知らせる */
    public function test_the_president_is_told_they_cannot_apply(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $this->actingAs($w['president'])->get(route('approvals.requests.create'))->assertOk()
            ->assertSee('社長に指定されている人は申請できません');
    }
}
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter RequestFormTest
```

Expected: FAIL（`Route [approvals.requests.create] not defined.`）

- [ ] **Step 3: コントローラを書く**

`app/Http/Controllers/Approval/RequestController.php`

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepResult;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\ApprovalType;
use App\Models\User;
use App\Support\Approval\RelatedNumbers;
use App\Support\Approval\RequestPermissions;
use App\Support\Approval\RequestVisibility;
use App\Support\Approval\Workflow;
use App\Support\Approval\WorkflowConflict;
use App\Support\Approval\WorkflowRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 申請書（画面②）と申請の詳細（画面③）（設計書 §5.6・§5.12）。
 *
 * ⚠ 状態を変えるのは Workflow だけ。ここは中身の保存と、提出の呼び出しだけを行う。
 * ⚠ 保存のフォームに `intent=submit` が付いていれば、保存のあと提出する（計画 §0.8 の 2）。
 *   提出できなくても中身は残し、理由を編集の画面の上にすべて並べる（計画 §0.11）。
 * ⚠ 見られない申請は 404（在るかどうかを漏らさない。他人の下書きは誰も見られない）。
 * ⚠ 戻り先は画面ごとに決めた固定のルート（Bug #64。検証で断るときも）。
 */
class RequestController extends Controller
{
    public function __construct(private readonly Workflow $workflow)
    {
    }

    /** 作成（`?copy={id}` で自分の申請を写す。保存するまで下書きはできない） */
    public function create(Request $request): View
    {
        $user  = $request->user();
        $draft = new ApprovalRequest();

        $copyId = $request->query('copy');
        if ($copyId !== null) {
            // 写せるのは自分の申請だけ（取り下げ・否決を含む。設計書 §5.6）
            $source = is_string($copyId) ? ApprovalRequest::where('user_id', $user->id)->find($copyId) : null;
            abort_if($source === null, 404);
            $draft->fill($this->copiedFields($source, $user));
        }

        // 所属部門が 1 つなら最初から選んでおく
        if ($draft->department_id === null) {
            $memberOf = $user->approvalDepartments()->pluck('approval_departments.id');
            if ($memberOf->count() === 1) {
                $draft->department_id = $memberOf->first();
            }
        }

        return view('approvals.requests.form', $this->formData($draft, $user));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request, null, route('approvals.requests.create'));

        $approvalRequest = ApprovalRequest::create($validated + ['user_id' => $request->user()->id]);

        // 状態・回数は DB の既定値で入る。読み直してから次へ渡す
        return $this->afterSave($request, $approvalRequest->refresh());
    }

    public function show(Request $request, ApprovalRequest $approvalRequest): View
    {
        $user = $request->user();
        $this->assertVisible($user, $approvalRequest);

        $approvalRequest->load(['applicant', 'department', 'type']);

        return view('approvals.requests.show', [
            'approvalRequest' => $approvalRequest,
            'permissions'     => RequestPermissions::for($user, $approvalRequest),
            'relatedLinks'    => $this->relatedLinks($approvalRequest, $user),
        ]);
    }

    public function edit(Request $request, ApprovalRequest $approvalRequest): View|RedirectResponse
    {
        $user = $request->user();
        $this->assertVisible($user, $approvalRequest);

        if (! RequestPermissions::for($user, $approvalRequest)->canEdit()) {
            return $this->notEditable($approvalRequest);
        }

        return view('approvals.requests.form', $this->formData($approvalRequest, $user));
    }

    public function update(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $user = $request->user();
        $this->assertVisible($user, $approvalRequest);

        if (! RequestPermissions::for($user, $approvalRequest)->canEdit()) {
            return $this->notEditable($approvalRequest);
        }

        $validated = $this->validated($request, $approvalRequest, route('approvals.requests.edit', $approvalRequest));
        $approvalRequest->update($validated);

        return $this->afterSave($request, $approvalRequest);
    }

    /** 下書きの削除（一度も提出していないものだけ。要件 4.5・計画 §0.5） */
    public function destroy(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $user = $request->user();
        $this->assertVisible($user, $approvalRequest);

        $refusal = redirect()->route('approvals.requests.show', $approvalRequest)
            ->with('error', '削除できるのは、一度も提出していない下書きだけです。');

        if (! RequestPermissions::for($user, $approvalRequest)->canDelete()) {
            return $refusal;
        }

        // ⚠ 状態を条件にして消す（別のタブで提出した直後の申請を消さない。提出した申請には記録があり、
        //   記録の外部キーが削除を拒むので 500 になる）。添付の行は外部キーの CASCADE で消える
        $deleted = ApprovalRequest::whereKey($approvalRequest->id)
            ->where('status', ApprovalStatus::Draft->value)
            ->where('round', 0)
            ->delete();

        if ($deleted !== 1) {
            return $refusal;
        }

        // 添付のファイルはフォルダごと消す（計画 §0.5。一度も提出していないので記録から開かれることもない）
        Storage::disk('local')->deleteDirectory("approvals/{$approvalRequest->id}");

        return redirect()->route('approvals.home')->with('success', '下書きを削除しました。');
    }

    /** 保存のあと: 下書きの保存なら編集の画面へ、提出なら Workflow に渡す */
    private function afterSave(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $edit = redirect()->route('approvals.requests.edit', $approvalRequest);

        if ($request->input('intent') !== 'submit') {
            return $edit->with('success', $approvalRequest->status === ApprovalStatus::Returned
                ? '保存しました（まだ出し直していません）。'
                : '下書きを保存しました。');
        }

        try {
            $this->workflow->submit($approvalRequest, $request->user());
        } catch (WorkflowRefused $e) {
            // 中身は保存してある。理由をすべて並べる（計画 §0.11）
            return $edit->withErrors(['submit' => $e->reasons]);
        } catch (WorkflowConflict) {
            return redirect()->route('approvals.requests.show', $approvalRequest)->with('error', WorkflowConflict::MESSAGE);
        }

        return redirect()->route('approvals.requests.show', $approvalRequest)
            ->with('success', $approvalRequest->round > 1 ? '出し直しました。' : '提出しました。');
    }

    /**
     * 形の検査（下書きは途中でも保存できる。D13）。そろっているかは提出のとき SubmitChecker が見る。
     *
     * ⚠ ルールは literal の配列で書く（JapaneseValidationMessagesTest の走査が和名の漏れを見る）。
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ApprovalRequest $current, string $redirectTo): array
    {
        $user = $request->user();

        $request->merge([
            'amount'          => self::normalizeAmount($request->input('amount')),
            'related_numbers' => RelatedNumbers::clean(is_array($request->input('related_numbers')) ? $request->input('related_numbers') : []),
        ]);

        // 選べる種類は利用中の種類と、この申請が今使っている種類（停止していても保存はできる。D10）
        $typeIds = ApprovalType::active()->pluck('id')->push($current?->type_id)->filter()->all();
        // 選べる申請部門は自分の所属部門と、この申請が今使っている部門（提出のとき SubmitChecker が所属を見る）
        $departmentIds = $user->approvalDepartments()->pluck('approval_departments.id')->push($current?->department_id)->filter()->all();

        try {
            return $request->validate([
                'type_id'           => ['nullable', 'integer', Rule::in($typeIds)],
                'department_id'     => ['nullable', 'integer', Rule::in($departmentIds)],
                'subject'           => ['nullable', 'string', 'max:100'],
                'amount'            => ['nullable', 'integer', 'min:0', 'max:999999999999'],
                'schedule'          => ['nullable', 'string', 'max:50'],
                'body'              => ['nullable', 'string', 'max:20000'],
                'related_numbers'   => ['array', 'max:' . RelatedNumbers::MAX],
                'related_numbers.*' => ['string', 'regex:' . RelatedNumbers::PATTERN],
            ], [
                'type_id.in'              => '選んだ申請の種類は使えません。選び直してください。',
                'department_id.in'        => '申請部門は、自分の所属部門から選んでください。',
                'amount.min'              => '金額は 0 以上で入力してください。',
                'amount.max'              => '金額は 999,999,999,999 円以下で入力してください。',
                'related_numbers.max'     => '関連する決裁No は ' . RelatedNumbers::MAX . ' 個までです。',
                'related_numbers.*.regex' => '関連する決裁No「:input」の形が違います（例: R8-J-001）。',
            ], [
                'type_id'       => '申請の種類',
                'department_id' => '申請部門',
                'body'          => '重点ポイント（5W2H）',
            ]);
        } catch (ValidationException $e) {
            throw $e->redirectTo($redirectTo);
        }
    }

    /** 金額の入力を数字だけにそろえる（全角・カンマ・「円」・「¥」・空白を落とす）。数字でなければ検査で断る */
    private static function normalizeAmount(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $digits = str_replace([',', '円', '¥', '￥', ' '], '', mb_convert_kana($value, 'as'));

        return $digits === '' ? null : $digits;
    }

    /** コピーして作成で写す中身（設計書 §5.6。添付は写さない） */
    private function copiedFields(ApprovalRequest $source, User $user): array
    {
        $typeUsable       = $source->type_id !== null && ApprovalType::active()->whereKey($source->type_id)->exists();
        $departmentUsable = $source->department_id !== null && $user->approvalDepartments()->whereKey($source->department_id)->exists();

        return [
            // 停止した種類・今は所属していない部門は空にして選び直してもらう
            'type_id'         => $typeUsable ? $source->type_id : null,
            'department_id'   => $departmentUsable ? $source->department_id : null,
            'subject'         => $source->subject,
            'amount'          => $source->amount,
            'schedule'        => $source->schedule,
            'body'            => $source->body,
            'related_numbers' => $source->related_numbers ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function formData(ApprovalRequest $approvalRequest, User $user): array
    {
        $types = ApprovalType::active()->ordered()->get();
        // 停止した種類を使っている申請は、その種類も選択肢に残す（保存で消えないように。D10）
        if ($approvalRequest->type_id !== null && ! $types->contains('id', $approvalRequest->type_id)) {
            $types->push($approvalRequest->type()->firstOrFail());
        }

        $departments = $user->approvalDepartments()->with('company')->orderBy('approval_departments.sort_order')->get();
        $memberOf    = $departments->pluck('id')->all();
        // 今は所属していない部門を使っている申請も同じ（提出のときに選び直してもらう）
        if ($approvalRequest->department_id !== null && ! in_array($approvalRequest->department_id, $memberOf, true)) {
            $departments->push($approvalRequest->department()->with('company')->firstOrFail());
        }

        return [
            'approvalRequest' => $approvalRequest,
            'types'           => $types,
            'typeHeadings'    => $types->mapWithKeys(fn (ApprovalType $type) => [$type->id => $type->headings])->all(),
            'departments'     => $departments,
            'memberOf'        => $memberOf,
            'isPresident'     => $user->isApprovalPresident(),
            // 差戻しの理由（差戻し中の申請を直すとき、画面の上に出す）
            'returnNote'      => $approvalRequest->status === ApprovalStatus::Returned
                ? ApprovalStep::with('actor')
                    ->where('request_id', $approvalRequest->id)
                    ->where('round', $approvalRequest->round)
                    ->where('result', ApprovalStepResult::Return->value)
                    ->first()
                : null,
        ];
    }

    /** 関連する決裁No のうち、この人が見られる申請（番号 => 申請の id） @return array<string, int> */
    private function relatedLinks(ApprovalRequest $approvalRequest, User $user): array
    {
        $numbers = $approvalRequest->related_numbers ?? [];

        if ($numbers === []) {
            return [];
        }

        return RequestVisibility::apply(ApprovalRequest::query(), $user)
            ->whereIn('number', $numbers)
            ->pluck('id', 'number')
            ->all();
    }

    /** 見られない申請は 404（在るかどうかを漏らさない。設計書 §5.10） */
    private function assertVisible(User $user, ApprovalRequest $approvalRequest): void
    {
        abort_unless(RequestVisibility::canView($user, $approvalRequest), 404);
    }

    private function notEditable(ApprovalRequest $approvalRequest): RedirectResponse
    {
        return redirect()->route('approvals.requests.show', $approvalRequest)->with('error', 'この申請は直せる状態ではありません。');
    }
}
```

- [ ] **Step 4: 申請書の画面を書く**

`resources/views/approvals/requests/form.blade.php`

```blade
@extends('layouts.app')

@php
    $editing     = $approvalRequest->exists;
    $returned    = $editing && $approvalRequest->status === \App\Enums\ApprovalStatus::Returned;
    $pageTitle   = $returned ? '差戻しの申請を直す' : ($editing ? '下書きの編集' : '新しい申請');
    $submitLabel = $returned ? '出し直す' : '提出する';
    // 提出できなかった理由（submit）と、入力の形の誤りを分けて出す（計画 §0.11）
    $fieldErrors = collect($errors->getMessages())->except('submit')->flatten();
@endphp

@section('title', $pageTitle)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $pageTitle }}</span>
@endsection

@section('content')
<div class="max-w-[880px]" x-data="approvalRequestForm()">

    {{-- 成功・失敗の帯はレイアウトが出す。$errors だけ各ビューの責任 --}}
    @if($errors->has('submit'))
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">{{ $submitLabel }}ことができませんでした（入力した中身は保存してあります）。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->get('submit') as $reason)<li>{{ $reason }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if($fieldErrors->isNotEmpty())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります（まだ保存していません）。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($fieldErrors as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if($isPresident)
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-800">
            社長に指定されている人は申請できません（下書きは保存できますが、提出はできません）。
        </div>
    @endif

    @if($returnNote)
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
            <p class="text-[12px] font-semibold text-amber-800 mb-1">{{ $returnNote->kind->label() }}（{{ $returnNote->actor?->name }}）からの差戻し・{{ \App\Support\JapanTime::format($returnNote->acted_at) }}</p>
            <p class="text-[13px] text-amber-900 whitespace-pre-wrap break-words">{{ $returnNote->comment }}</p>
        </div>
    @endif

    <h1 class="text-lg font-bold text-gray-900 mb-1">{{ $pageTitle }}</h1>
    <p class="text-[12px] text-gray-500 mb-5">
        @if($returned)
            中身を直して「出し直す」を押してください。出し直すと部門長の確認から回り直します。
        @else
            途中でも「下書きを保存」で保存できます。添付は、下書きを 1 回保存したあとに追加できます。
        @endif
    </p>

    <form method="POST" action="{{ $editing ? route('approvals.requests.update', $approvalRequest) : route('approvals.requests.store') }}"
          class="bg-white rounded-lg border border-gray-200 px-5 py-5 space-y-5">
        @csrf
        @if($editing)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="type_id" class="block text-[12px] font-semibold text-gray-700 mb-1">申請の種類<span class="text-red-600 ml-0.5">*</span></label>
                {{-- ⚠ <option> は @@foreach で静的に出す（Bug #16）。選び直しは @@change で拾う（初期値は selected） --}}
                <select id="type_id" name="type_id" @change="typeChanged($event.target.value)"
                        class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                    <option value="">選んでください</option>
                    @foreach($types as $type)
                        <option value="{{ $type->id }}" @selected((string) old('type_id', $approvalRequest->type_id) === (string) $type->id)>{{ $type->name }}{{ $type->is_active ? '' : '（停止中）' }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="department_id" class="block text-[12px] font-semibold text-gray-700 mb-1">申請部門<span class="text-red-600 ml-0.5">*</span></label>
                <select id="department_id" name="department_id"
                        class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                    <option value="">選んでください</option>
                    @foreach($departments as $department)
                        <option value="{{ $department->id }}" @selected((string) old('department_id', $approvalRequest->department_id) === (string) $department->id)>{{ $department->company->name }}・{{ $department->name }}{{ in_array($department->id, $memberOf, true) ? '' : '（所属していません）' }}</option>
                    @endforeach
                </select>
                @if($memberOf === [])
                    <p class="text-[11px] text-red-600 mt-1">所属部門が未設定です。管理者に連絡してください。</p>
                @endif
            </div>
        </div>

        {{-- 種類を選び直したとき、本文を書き始めていれば入れ替えるか確かめる（設計書 §5.6） --}}
        <div x-show="pendingTypeId !== null" x-cloak class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-[13px] text-amber-800">
            <p class="mb-2">本文を、選んだ種類の見出しに入れ替えますか？（いま書いてある本文は消えます）</p>
            <div class="flex flex-wrap gap-2">
                <button type="button" @click="replaceBody()" class="px-3 py-1.5 text-[12px] font-semibold text-white bg-amber-600 rounded-md hover:bg-amber-700 cursor-pointer">入れ替える</button>
                <button type="button" @click="pendingTypeId = null" class="px-3 py-1.5 text-[12px] font-semibold text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50 cursor-pointer">本文はそのまま</button>
            </div>
        </div>

        <div>
            <label for="subject" class="block text-[12px] font-semibold text-gray-700 mb-1">件名<span class="text-red-600 ml-0.5">*</span></label>
            <input type="text" id="subject" name="subject" value="{{ old('subject', $approvalRequest->subject) }}" maxlength="100"
                   class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="amount" class="block text-[12px] font-semibold text-gray-700 mb-1">金額（円・税抜）</label>
                {{-- ⚠ value="0" の既定値を入れない（設計書 §5.6）。カンマ入りでも受け付ける --}}
                <input type="text" id="amount" name="amount" inputmode="numeric" placeholder="例: 28,500,000"
                       value="{{ old('amount', $approvalRequest->amount === null ? '' : number_format($approvalRequest->amount)) }}"
                       class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
            </div>
            <div>
                <label for="schedule" class="block text-[12px] font-semibold text-gray-700 mb-1">実施時期</label>
                <input type="text" id="schedule" name="schedule" value="{{ old('schedule', $approvalRequest->schedule) }}" maxlength="50" placeholder="例: 2026年10月"
                       class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
            </div>
        </div>

        <div>
            <label for="body" class="block text-[12px] font-semibold text-gray-700 mb-1">重点ポイント（5W2H）<span class="text-red-600 ml-0.5">*</span></label>
            <textarea id="body" name="body" x-ref="body" rows="16" maxlength="20000" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ old('body', $approvalRequest->body) }}</textarea>
            <p class="text-[11px] text-gray-400 mt-1">種類を選ぶと見出しが入ります。見出しのままでは提出できません。「いつ」「いくら」は実施時期・金額の欄に書きます。</p>
        </div>

        {{-- 関連する決裁No（10 個まで。候補は見られる申請の番号と件名。設計書 §5.6・D15） --}}
        <div>
            <label for="related-number" class="block text-[12px] font-semibold text-gray-700 mb-1">関連する決裁No（{{ \App\Support\Approval\RelatedNumbers::MAX }} 個まで）</label>
            <div class="flex flex-wrap gap-1.5 mb-2" x-show="numbers.length > 0">
                <template x-for="(number, index) in numbers" :key="number">
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-gray-100 text-[12px] font-mono text-gray-800">
                        <span x-text="number"></span>
                        <input type="hidden" name="related_numbers[]" :value="number">
                        <button type="button" @click="removeNumber(index)" :title="number + ' を外す'" class="text-gray-400 hover:text-red-600 cursor-pointer">×</button>
                    </span>
                </template>
            </div>
            <div class="relative max-w-[420px]" @click.outside="suggestions = []">
                {{-- ⚠ Enter は IME の確定を除いて「追加」にする（Bug #6）。フォームの送信にしない --}}
                <input type="text" id="related-number" x-model="numberInput" autocomplete="off"
                       @input.debounce.300ms="searchNumbers()"
                       @keydown.enter.prevent="$event.isComposing || addNumber(numberInput)"
                       :disabled="numbers.length >= maxNumbers"
                       placeholder="例: R8-J-001（番号か件名の一部で候補が出ます）"
                       class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] disabled:bg-gray-100">
                <ul x-show="suggestions.length > 0" x-cloak class="absolute z-10 left-0 right-0 mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-[240px] overflow-y-auto">
                    <template x-for="item in suggestions" :key="item.number">
                        <li>
                            <button type="button" @click="addNumber(item.number)" class="w-full text-left px-3 py-2 text-[12px] hover:bg-emerald-50 cursor-pointer">
                                <span class="font-mono text-gray-900" x-text="item.number"></span>
                                <span class="text-gray-500 ml-1" x-text="item.subject"></span>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>
            <div class="flex flex-wrap items-center gap-2 mt-1.5">
                <button type="button" @click="addNumber(numberInput)" class="px-3 py-1.5 text-[12px] font-semibold text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 cursor-pointer">追加</button>
                <p class="text-[11px] text-gray-400">紙の時代の番号も入れられます（形だけ確かめます）</p>
            </div>
            <p x-show="errorMessage" x-cloak class="text-[12px] text-red-600 mt-1" x-text="errorMessage"></p>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-gray-100">
            <a href="{{ $editing ? route('approvals.requests.show', $approvalRequest) : route('approvals.home') }}" class="text-[13px] text-gray-500 hover:underline">やめる</a>
            <div class="flex flex-wrap gap-2">
                {{-- ⚠ 入力欄で Enter を押したときに送られるのは、この「保存」（先頭の submit ボタン）。提出にはならない --}}
                <button type="submit" name="intent" value="save" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-700 hover:bg-gray-50 cursor-pointer">{{ $returned ? '保存する' : '下書きを保存' }}</button>
                <button type="button" @click="confirmSubmit = true" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">{{ $submitLabel }}</button>
            </div>
        </div>

        {{-- 提出の確認（要件 4.1）。ここの submit が intent=submit を送る --}}
        <div x-show="confirmSubmit" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
            <div @click.outside="confirmSubmit = false" class="bg-white rounded-xl w-full max-w-[440px] shadow-xl mx-4 px-6 py-5">
                <p class="text-[15px] font-bold text-gray-900 mb-2">この内容で{{ $returned ? '出し直し' : '提出' }}しますか？</p>
                <p class="text-[12px] text-gray-500 mb-4">部門長（申請者が部門長なら省略）・審査・社長の順に回ります。{{ $returned ? '出し直した' : '提出した' }}あとは、差し戻されるまで中身を直せません。</p>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="confirmSubmit = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" name="intent" value="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">{{ $submitLabel }}</button>
                </div>
            </div>
        </div>
    </form>

</div>
@endsection

@push('scripts')
<script>
{{-- ⚠ Js::from を使う（@@json は構造の " を素のまま出す。Bug #23）。x-data にアロー関数を書かない（Top trap #4） --}}
function approvalRequestForm() {
    return {
        headings: {{ \Illuminate\Support\Js::from($typeHeadings) }},
        numbers: {{ \Illuminate\Support\Js::from(array_values(old('related_numbers', $approvalRequest->related_numbers ?? []))) }},
        maxNumbers: {{ \App\Support\Approval\RelatedNumbers::MAX }},
        numberInput: '',
        suggestions: [],
        searchSeq: 0,
        errorMessage: '',
        pendingTypeId: null,
        confirmSubmit: false,

        // 本文が見出しのままか（App\Support\Approval\BodyTemplate::isBlank() と同じ判定）
        isBlankBody: function (text) {
            var lines = String(text || '').split(/\r\n|\r|\n/);
            for (var i = 0; i < lines.length; i++) {
                var line = lines[i].replace(/^[\s　]+|[\s　]+$/g, '');
                if (line === '' || line === '・' || line.charAt(0) === '■') {
                    continue;
                }
                return false;
            }
            return true;
        },

        // 種類を選んだ: 本文が見出しのままなら入れ替え、書き始めていれば確かめる
        typeChanged: function (typeId) {
            var headings = this.headings[typeId];
            this.pendingTypeId = null;
            if (!headings) {
                return;
            }
            if (this.isBlankBody(this.$refs.body.value)) {
                this.$refs.body.value = headings;
                return;
            }
            this.pendingTypeId = typeId;
        },

        replaceBody: function () {
            this.$refs.body.value = this.headings[this.pendingTypeId] || '';
            this.pendingTypeId = null;
        },

        // App\Support\Approval\RelatedNumbers::normalize() と同じそろえ方（最後はサーバーでもう一度そろえる）
        normalizeNumber: function (value) {
            var text = String(value || '').replace(/[！-～]/g, function (c) {
                return String.fromCharCode(c.charCodeAt(0) - 0xFEE0);
            });
            text = text.replace(/[ー―‐−–—]/g, '-').replace(/　/g, ' ');
            return text.replace(/^\s+|\s+$/g, '').toUpperCase();
        },

        addNumber: function (value) {
            var number = this.normalizeNumber(value);
            this.errorMessage = '';
            if (number === '') {
                return;
            }
            if (!/^[RH][0-9]{1,2}-[A-Z]{1,3}-[0-9]{3,}$/.test(number)) {
                this.errorMessage = '「' + number + '」は決裁No の形ではありません（例: R8-J-001）。';
                return;
            }
            if (this.numbers.indexOf(number) === -1) {
                if (this.numbers.length >= this.maxNumbers) {
                    this.errorMessage = '関連する決裁No は ' + this.maxNumbers + ' 個までです。';
                    return;
                }
                this.numbers.push(number);
            }
            this.numberInput = '';
            this.suggestions = [];
        },

        removeNumber: function (index) {
            this.numbers.splice(index, 1);
            this.errorMessage = '';
        },

        // 候補を探す。⚠ GET の fetch には X-Requested-With を付ける（Bug #35）
        searchNumbers: function () {
            var self = this;
            var query = self.numberInput.replace(/^[\s　]+|[\s　]+$/g, '');
            var seq = ++self.searchSeq;
            if (query === '') {
                self.suggestions = [];
                return;
            }

            fetch('{{ route('approvals.numbers.search') }}?q=' + encodeURIComponent(query), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) {
                if (!res.ok) {
                    self.errorMessage = '候補を読み込めませんでした（' + res.status + '）。番号はそのまま入れられます。';
                    return null;
                }
                return res.json();
            })
            .then(function (data) {
                // 古い検索の答えが後から届いたら捨てる
                if (!data || seq !== self.searchSeq) return;
                self.suggestions = data.items;
            })
            .catch(function () {
                self.errorMessage = '候補を読み込めませんでした。番号はそのまま入れられます。';
            });
        }
    };
}
</script>
@endpush
```

- [ ] **Step 5: 詳細の画面と操作の部品を書く**

`resources/views/approvals/requests/show.blade.php`

```blade
@extends('layouts.app')

@section('title', $approvalRequest->number ?? '申請の詳細')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">申請の詳細</span>
@endsection

@section('content')
<div class="max-w-[880px]">

    <div class="flex flex-wrap items-center gap-2 mb-1">
        <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $approvalRequest->status->badgeStyle() }}">{{ $approvalRequest->statusLabel() }}</span>
        @if($approvalRequest->number)
            <span class="text-[13px] font-mono font-semibold text-gray-800">{{ $approvalRequest->number }}</span>
        @else
            <span class="text-[12px] text-gray-400">決裁No は社長の判断のときに付きます</span>
        @endif
    </div>
    <h1 class="text-lg font-bold text-gray-900 mb-4 break-words">{{ $approvalRequest->subject ?? '（件名なし）' }}</h1>

    @include('approvals.requests._actions')

    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">申請の中身</h2>
        <dl class="px-5 py-4 grid grid-cols-1 sm:grid-cols-[9em_1fr] gap-x-4 gap-y-2 text-[13px]">
            <dt class="text-gray-500">申請者</dt>
            <dd class="text-gray-900">{{ $approvalRequest->applicant->name }}</dd>
            <dt class="text-gray-500">申請部門</dt>
            <dd class="text-gray-900">{{ $approvalRequest->department?->name ?? '—' }}</dd>
            <dt class="text-gray-500">申請の種類</dt>
            <dd class="text-gray-900">{{ $approvalRequest->type?->name ?? '—' }}</dd>
            <dt class="text-gray-500">発信日</dt>
            <dd class="text-gray-900">{{ \App\Support\JapanTime::format($approvalRequest->last_submitted_at, 'Y/m/d') ?? '—' }}</dd>
            <dt class="text-gray-500">決裁日</dt>
            <dd class="text-gray-900">{{ \App\Support\JapanTime::format($approvalRequest->decided_at, 'Y/m/d') ?? '—' }}</dd>
            <dt class="text-gray-500">金額（税抜）</dt>
            <dd class="text-gray-900">{{ $approvalRequest->amountLabel() ?? '—' }}</dd>
            <dt class="text-gray-500">実施時期</dt>
            <dd class="text-gray-900 break-words">{{ $approvalRequest->schedule ?? '—' }}</dd>
            <dt class="text-gray-500">関連する決裁No</dt>
            <dd class="text-gray-900">
                @forelse($approvalRequest->related_numbers ?? [] as $number)
                    {{-- 見られる申請はリンクにする（紙の時代の番号・見られない申請は文字だけ。設計書 §5.6） --}}
                    @if(isset($relatedLinks[$number]))
                        <a href="{{ route('approvals.requests.show', $relatedLinks[$number]) }}" class="font-mono text-emerald-600 hover:underline mr-2">{{ $number }}</a>
                    @else
                        <span class="font-mono mr-2">{{ $number }}</span>
                    @endif
                @empty
                    —
                @endforelse
            </dd>
        </dl>
        <div class="px-5 pb-5">
            <p class="text-[12px] font-semibold text-gray-500 mb-1.5">重点ポイント（5W2H）</p>
            <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-[13px] text-gray-900 leading-relaxed whitespace-pre-wrap break-words">{{ $approvalRequest->body }}</div>
        </div>
    </section>

</div>
@endsection
```

`resources/views/approvals/requests/_actions.blade.php`

```blade
{{-- 申請の詳細の操作（設計書 §5.12）。出すのは RequestPermissions がこの人に「今できる」と判定したものだけ。
     ⚠ Task 15 で判断・取り下げ・条件確認を足す（このファイルを丸ごと置き換える）。 --}}
@if($permissions->isApplicant())
    <div class="flex flex-wrap items-center gap-2 mb-5" x-data="{ confirmDelete: false }">
        @if($permissions->canEdit())
            <a href="{{ route('approvals.requests.edit', $approvalRequest) }}" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold">{{ $approvalRequest->status === \App\Enums\ApprovalStatus::Returned ? '直して出し直す' : '編集する' }}</a>
        @endif
        <a href="{{ route('approvals.requests.create', ['copy' => $approvalRequest->id]) }}" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-700 hover:bg-gray-50">コピーして新しい申請</a>

        @if($permissions->canDelete())
            <form method="POST" action="{{ route('approvals.requests.destroy', $approvalRequest) }}">
                @csrf
                @method('DELETE')
                <button type="button" @click="confirmDelete = true" class="px-4 py-2 bg-white border border-red-200 rounded-md text-[13px] font-semibold text-red-600 hover:bg-red-50 cursor-pointer">下書きを削除</button>

                <div x-show="confirmDelete" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
                    <div @click.outside="confirmDelete = false" class="bg-white rounded-xl w-full max-w-[420px] shadow-xl mx-4 px-6 py-5">
                        <p class="text-[15px] font-bold text-gray-900 mb-2">この下書きを削除しますか？</p>
                        <p class="text-[12px] text-gray-500 mb-4">添付したファイルも消えます。元に戻せません。</p>
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="confirmDelete = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                            <button type="submit" class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">削除する</button>
                        </div>
                    </div>
                </div>
            </form>
        @endif
    </div>
@endif
```

- [ ] **Step 6: ルートを足す**

`routes/approval.php` の先頭の `use` に足す:

```php
use App\Http\Controllers\Approval\RequestController;
```

Task 12 で作った「申請を回す画面」のグループの中（`numbers.search` の後）に足す:

```php

    // 申請書（画面②）と詳細（画面③）。段階2 設計書 §5.6・§5.12
    // ⚠ 提出は別のルートにしない（保存のフォームに intent=submit を付けて送る。計画 §0.8 の 2）
    // ⚠ create を {approvalRequest} より前に置く
    Route::get('/requests/create', [RequestController::class, 'create'])->name('requests.create');
    Route::post('/requests', [RequestController::class, 'store'])->name('requests.store');
    Route::get('/requests/{approvalRequest}', [RequestController::class, 'show'])->name('requests.show');
    Route::get('/requests/{approvalRequest}/edit', [RequestController::class, 'edit'])->name('requests.edit');
    Route::put('/requests/{approvalRequest}', [RequestController::class, 'update'])->name('requests.update');
    Route::delete('/requests/{approvalRequest}', [RequestController::class, 'destroy'])->name('requests.destroy');
```

- [ ] **Step 7: 和名を足す**

`lang/ja/validation.php` の段階2 の節（Task 11 で足した `'is_active' => '利用中',` の次）に足す:

```php
        // 申請書（§5.6）。type_id / department_id / body は RequestController の validate() 第 3 引数で
        // 「申請の種類」「申請部門」「重点ポイント（5W2H）」に上書きする（ほかの画面で同じ名前を使うときのため一般の語にする）
        'type_id'           => '種類',
        'department_id'     => '部門',
        'subject'           => '件名',
        'amount'            => '金額',
        'schedule'          => '実施時期',
        'body'              => '本文',
        'related_numbers'   => '関連する決裁No',
        'related_numbers.*' => '関連する決裁No',
```

⚠ 足す前に `grep -n "'type_id'\|'department_id'\|'subject'\|'amount'\|'schedule'\|'body'" lang/ja/validation.php` が何も出さないことを確かめる（既存のキーを重ねて書くと全画面の和名が変わる。計画 §0.9）。

- [ ] **Step 8: 走査テストに分類を足す**

`tests/Feature/Approval/ApprovalAdminGateTest.php` の `OPEN_TO_EVERY_USER` の `'approvals.numbers.search' => …` の次に足す:

```php
        'approvals.requests.create'  => '申請書の作成（誰でも申請できる。社長は提出で断る。段階2 設計書 §5.6）',
        'approvals.requests.store'   => '下書きの保存・提出（本人の申請として作る）',
        'approvals.requests.show'    => '申請の詳細（見られる範囲だけ。RequestVisibility）',
        'approvals.requests.edit'    => '下書き・差戻し中の編集（申請者だけ。RequestPermissions）',
        'approvals.requests.update'  => '同じく保存・提出',
        'approvals.requests.destroy' => '一度も提出していない下書きの削除（申請者だけ）',
```

`tests/Feature/AjaxErrorFeedbackTest.php` の `VIEWS_NULL_RETURN` の最後（`'components/attachment-section.blade.php',` の次）に足す:

```php
        // 決裁申請（段階2）。候補の検索と、Task 14 の添付の追加・削除。`.ok` の分岐と `!data` のガードを同じ数だけ書く
        'approvals/requests/form.blade.php',
```

- [ ] **Step 9: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'RequestFormTest|LaunchGateTest|ApprovalAdminGateTest|ApprovalOnlyLockoutTest|AjaxErrorFeedbackTest|AjaxFetchSessionGuardTest|JapaneseValidationMessagesTest|ValidationErrorFeedbackTest|ClockReadScanTest|StoredTimestampDisplayScanTest|MobileLayoutTest|AlpineXShowDisplayConflictTest'
```

Expected: PASS

- [ ] **Step 10: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add app/Http/Controllers/Approval/RequestController.php resources/views/approvals/requests routes/approval.php lang/ja/validation.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/AjaxErrorFeedbackTest.php tests/Feature/Approval/Phase2/RequestFormTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
feat(approval): 申請書の作成・編集・提出と詳細の画面を足す

下書きは途中でも保存でき、保存と同時に提出もできる（提出できなければ理由をすべて並べ、
中身は下書きとして残す）。種類を選ぶと本文に見出しが入る。関連する決裁No は候補から
選べる。一度も提出していない下書きは削除でき、自分の申請はコピーして作り直せる。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 14: 添付（追加・開く・外す・記録）

**Files:**
- Create: `app/Http/Controllers/Approval/RequestAttachmentController.php`
- Modify: `app/Models/ApprovalAttachment.php`（`listItem()` を足す）
- Modify: `app/Http/Controllers/Approval/RequestController.php`（編集の画面へ添付の一覧を渡す・詳細で添付を読む）
- Modify: `resources/views/approvals/requests/form.blade.php`・`show.blade.php`（添付の欄）
- Modify: `routes/approval.php`（3 本）
- Modify: `bootstrap/app.php`（送信の上限を超えたときの日本語の応答）
- Modify: `tests/Feature/ClockReadScanTest.php`（`ALLOWED` に 1 行）・`tests/Feature/Approval/ApprovalAdminGateTest.php`（`OPEN_TO_EVERY_USER` に 3 行）
- Test: `tests/Feature/Approval/Phase2/RequestAttachmentTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/RequestAttachmentTest.php`

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalAttachment;
use App\Models\ApprovalDownloadLog;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 添付（要件 5.3・14.3・設計書 §5.7・計画 §0.5） */
class RequestAttachmentTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const AJAX = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function upload(User $user, ApprovalRequest $request, UploadedFile $file): TestResponse
    {
        return $this->actingAs($user)->post(route('approvals.requests.attachments.store', $request), ['file' => $file], self::AJAX);
    }

    private function remove(User $user, ApprovalAttachment $attachment): TestResponse
    {
        return $this->actingAs($user)->delete(route('approvals.attachments.destroy', $attachment), [], self::AJAX);
    }

    private function pdf(string $name = '見積書.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    /** 下書きを提出して、部門長が差し戻す（一度提出した申請にする） */
    private function submitAndReturn(ApprovalRequest $draft, array $w): ApprovalRequest
    {
        $workflow = app(Workflow::class);
        $workflow->submit($draft->refresh(), $w['applicant']);
        $draft->refresh();
        $workflow->judgeHead($draft, $w['head'], $draft->lock_version, ApprovalStepResult::Return, '見積書を差し替えてください');

        return $draft->refresh();
    }

    public function test_the_applicant_can_attach_a_file_to_a_draft(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);

        $response = $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();

        $attachment = ApprovalAttachment::sole();
        $response->assertJson([
            'success'    => true,
            'message'    => '見積書.pdf を添付しました。',
            'attachment' => ['id' => $attachment->id, 'name' => '見積書.pdf', 'url' => route('approvals.attachments.show', $attachment)],
        ]);
        $this->assertSame(1, $attachment->added_round, 'この添付が初めて入るのは 1 回目の提出');
        $this->assertSame('application/pdf', $attachment->mime);
        $this->assertMatchesRegularExpression('#^approvals/' . $draft->id . '/[A-Za-z0-9]{40}\.pdf$#', $attachment->stored_path);
        Storage::disk('local')->assertExists($attachment->stored_path);
    }

    /** 同じ名前でも上書きしない（新しい内容は新しい名前） */
    public function test_the_same_name_never_overwrites(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);

        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();

        $paths = ApprovalAttachment::pluck('stored_path');
        $this->assertCount(2, $paths->unique());
        foreach ($paths as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_the_kind_and_the_size_are_checked(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);

        $this->upload($w['applicant'], $draft, UploadedFile::fake()->create('tool.exe', 10, 'application/x-msdownload'))
            ->assertStatus(422)
            ->assertJsonPath('message', '添付できるのは、画像（jpg・png・gif・webp・heic）・PDF・Word・Excel・CSV・テキストです。');

        $this->upload($w['applicant'], $draft, UploadedFile::fake()->create('big.pdf', ApprovalAttachment::MAX_KB + 1, 'application/pdf'))
            ->assertStatus(422)
            ->assertJsonPath('message', '1 ファイル 10MB までです。');

        $this->assertSame(0, ApprovalAttachment::count());
    }

    /** 1 件の申請に 20 ファイルまで（D14） */
    public function test_twenty_files_is_the_limit(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        for ($i = 1; $i <= ApprovalAttachment::MAX_COUNT; $i++) {
            $this->upload($w['applicant'], $draft, $this->pdf("見積書{$i}.pdf"))->assertOk();
        }

        $this->upload($w['applicant'], $draft, $this->pdf())
            ->assertStatus(422)
            ->assertJsonPath('message', '添付は 1 件の申請に 20 ファイルまでです。');
        $this->assertSame(ApprovalAttachment::MAX_COUNT, ApprovalAttachment::count());
    }

    /** 足せるのは申請者だけ・下書きと差戻し中だけ（要件 5.3） */
    public function test_only_the_applicant_can_attach_and_only_while_editable(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $submitted = $this->submittedFor($w);

        $this->upload($w['applicant'], $submitted, $this->pdf())
            ->assertForbidden()
            ->assertJsonPath('message', '添付を足せるのは、下書きと差戻し中の申請者だけです。');
        // 部門長は見られるが足せない。見られない人は 404
        $this->upload($w['head'], $submitted, $this->pdf())->assertForbidden();
        $this->upload($this->approvalOnlyUser(), $submitted, $this->pdf())->assertNotFound();

        $this->assertSame(0, ApprovalAttachment::count());
    }

    /** PDF・画像はブラウザで開き、それ以外（HEIC を含む）はダウンロード。種類はこちらの表から出す（§5.7・D14） */
    public function test_files_open_inline_or_download_with_safe_headers(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $this->upload($w['applicant'], $draft, UploadedFile::fake()->create('現場.heic', 50, 'image/heic'))->assertOk();
        [$pdf, $heic] = ApprovalAttachment::orderBy('id')->get()->all();

        $response = $this->actingAs($w['applicant'])->get(route('approvals.attachments.show', $pdf))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("filename*=utf-8''" . rawurlencode('見積書.pdf'), $response->headers->get('Content-Disposition'));

        $response = $this->actingAs($w['applicant'])->get(route('approvals.attachments.show', $heic))->assertOk();
        $this->assertSame('image/heic', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
    }

    /** 一度でも提出した申請の添付は、開くたびに記録する。一度も提出していない下書きは記録しない（計画 §0.5） */
    public function test_opening_is_logged_once_the_request_was_submitted(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $attachment = ApprovalAttachment::sole();

        $this->actingAs($w['applicant'])->get(route('approvals.attachments.show', $attachment))->assertOk();
        $this->assertSame(0, ApprovalDownloadLog::count());

        app(Workflow::class)->submit($draft->refresh(), $w['applicant']);
        $this->actingAs($w['head'])->get(route('approvals.attachments.show', $attachment))->assertOk();

        $log = ApprovalDownloadLog::sole();
        $this->assertSame(
            [$draft->id, $attachment->id, $w['head']->id, 'attachment'],
            [(int) $log->request_id, (int) $log->attachment_id, (int) $log->user_id, $log->kind]
        );
    }

    /** 見られない人は、URL を知っていても開けない（毎回確かめる。下書きの添付は申請者だけ） */
    public function test_someone_who_cannot_see_the_request_cannot_open_its_files(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $attachment = ApprovalAttachment::sole();

        $this->actingAs($this->approvalAdmin())->get(route('approvals.attachments.show', $attachment))->assertNotFound();

        app(Workflow::class)->submit($draft->refresh(), $w['applicant']);
        $this->actingAs($this->approvalOnlyUser())->get(route('approvals.attachments.show', $attachment))->assertNotFound();

        $this->assertSame(0, ApprovalDownloadLog::count());
    }

    /** 一度も提出していない下書きの添付は、外すと行もファイルも消える */
    public function test_removing_from_a_draft_deletes_the_file(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $attachment = ApprovalAttachment::sole();

        $this->remove($w['applicant'], $attachment)->assertOk()
            ->assertJson(['success' => true, 'message' => '見積書.pdf を外しました。']);

        $this->assertNull($attachment->fresh());
        Storage::disk('local')->assertMissing($attachment->stored_path);
    }

    /** 一度でも提出した申請の添付は、外しても行とファイルを残す（控えと記録から開ける。計画 §0.5） */
    public function test_removing_from_a_returned_request_keeps_the_record(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $attachment = ApprovalAttachment::sole();
        $request = $this->submitAndReturn($draft, $w);

        $this->remove($w['applicant'], $attachment)->assertOk();

        $fresh = $attachment->fresh();
        $this->assertSame(2, $fresh->removed_round);
        $this->assertNotNull($fresh->removed_at);
        Storage::disk('local')->assertExists($fresh->stored_path);
        $this->assertSame(0, $request->attachments()->count());
        // 1 回目の提出の控えには残っていて、部門長は開ける（範囲の確認と記録は同じ）
        $this->assertSame([$attachment->id], array_column($request->revisions()->first()->snapshot['attachments'], 'id'));
        $this->actingAs($w['head'])->get(route('approvals.attachments.show', $attachment))->assertOk();
        $this->assertSame(1, ApprovalDownloadLog::count());
    }

    public function test_files_cannot_be_removed_while_the_request_is_in_circulation(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $attachment = ApprovalAttachment::sole();
        app(Workflow::class)->submit($draft->refresh(), $w['applicant']);

        $this->remove($w['applicant'], $attachment)
            ->assertForbidden()
            ->assertJsonPath('message', '添付を外せるのは、下書きと差戻し中の申請者だけです。');

        $this->assertNull($attachment->fresh()->removed_at);
    }

    /** 下書きを削除すると、添付のファイルも消える（計画 §0.5） */
    public function test_deleting_a_draft_deletes_its_files(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $attachment = ApprovalAttachment::sole();

        $this->actingAs($w['applicant'])->delete(route('approvals.requests.destroy', $draft))->assertRedirect(route('approvals.home'));

        $this->assertSame(0, ApprovalAttachment::count());
        Storage::disk('local')->assertMissing($attachment->stored_path);
    }

    /** PHP の送信の上限を超えたときも、日本語の理由を JSON で返す（§5.7） */
    public function test_a_post_that_is_too_large_gets_a_japanese_reason(): void
    {
        // ⚠ 本物の上限（php.ini の post_max_size）は環境で変わるので、例外そのものを投げる見本のルートで測る
        Route::post('/approvals/_probe_too_large', fn () => throw new PostTooLargeException());

        $this->postJson('/approvals/_probe_too_large')
            ->assertStatus(413)
            ->assertExactJson(['message' => 'ファイルが大きすぎて受け取れませんでした。1 ファイル 10MB までです。']);
    }

    /** 添付の欄は下書きを 1 回保存したあとに出る（D14） */
    public function test_the_attachment_area_appears_after_the_first_save(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $this->actingAs($w['applicant'])->get(route('approvals.requests.create'))->assertOk()
            ->assertSee('下書きを 1 回保存すると、ここで添付を追加できます。')
            ->assertDontSee('function approvalAttachments()', false);

        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $attachment = ApprovalAttachment::sole();

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $draft))->assertOk()->getContent();
        $this->assertStringContainsString('function approvalAttachments()', $html);
        $this->assertStringContainsString(Js::from([$attachment->listItem()])->toHtml(), $html);
    }

    /** 詳細には今の添付だけが並ぶ（外したものは出さない） */
    public function test_the_detail_lists_current_attachments(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $this->upload($w['applicant'], $draft, $this->pdf('古い図面.pdf'))->assertOk();
        $request = $this->submitAndReturn($draft, $w);
        $this->remove($w['applicant'], ApprovalAttachment::where('original_name', '古い図面.pdf')->sole())->assertOk();

        $html = $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()->getContent();

        $this->assertStringContainsString('見積書.pdf', $html);
        $this->assertStringNotContainsString('古い図面.pdf', $html);
    }
}
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter RequestAttachmentTest
```

Expected: FAIL（`Route [approvals.requests.attachments.store] not defined.`）

- [ ] **Step 3: モデルに一覧の形を足す**

`app/Models/ApprovalAttachment.php` の `sizeLabel()` の後に足す:

```php
    /**
     * 申請書の添付の一覧（画面の JS）に渡す形。
     *
     * @return array{id: int, name: string, size: string, url: string, delete_url: string}
     */
    public function listItem(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->original_name,
            'size'       => $this->sizeLabel(),
            'url'        => route('approvals.attachments.show', $this),
            'delete_url' => route('approvals.attachments.destroy', $this),
        ];
    }
```

- [ ] **Step 4: コントローラを書く**

`app/Http/Controllers/Approval/RequestAttachmentController.php`

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalAttachment;
use App\Models\ApprovalDownloadLog;
use App\Models\ApprovalRequest;
use App\Support\Approval\RequestPermissions;
use App\Support\Approval\RequestVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 決裁の添付（要件 5.3・14.3・設計書 §5.7・計画 §0.5）。
 *
 * ⚠ ファイルは local ディスク（storage/app/private）に置く。public ディスクに置かない（14.3 の「非公開」）。
 * ⚠ 開くたびに RequestVisibility で確かめる（URL を知っていても、見られない人は 404）。
 * ⚠ 上書きしない（新しい内容は新しい名前。夜間バックアップは同じパス・同じ大きさを送り直さない）。
 * ⚠ 一度も提出していない下書き（round = 0）の添付は、外すと行もファイルも消え、開いても記録しない。
 *   一度でも提出した申請の添付は、外しても行とファイルを残し、開くたびに記録する（計画 §0.5）。
 */
class RequestAttachmentController extends Controller
{
    /** 追加（1 ファイル・Ajax・JSON。画面の JS が 1 つずつ順に送る。D14） */
    public function store(Request $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        $user = $request->user();
        abort_unless(RequestVisibility::canView($user, $approvalRequest), 404);

        $request->validate([
            'file' => ['required', 'file', 'max:' . ApprovalAttachment::MAX_KB, 'mimes:' . implode(',', array_keys(ApprovalAttachment::TYPES))],
        ], [
            'file.max'   => '1 ファイル 10MB までです。',
            'file.mimes' => '添付できるのは、画像（jpg・png・gif・webp・heic）・PDF・Word・Excel・CSV・テキストです。',
        ], [
            'file' => '添付ファイル',
        ]);

        $file = $request->file('file');
        // ⚠ 名前に / と \ があると、開くときの Content-Disposition が作れない
        $originalName = str_replace(['/', '\\'], '_', $file->getClientOriginalName());

        if (mb_strlen($originalName) > 255) {
            return response()->json(['message' => 'ファイル名が長すぎます（255 文字まで）。名前を短くしてから選んでください。'], 422);
        }

        // 拡張子は中身から決める（mimes の検査と同じもの。送られてきた名前や MIME は信じない）
        $extension = $file->guessExtension();

        return DB::transaction(function () use ($approvalRequest, $user, $file, $originalName, $extension): JsonResponse {
            // ⚠ 申請の行をロックしてから確かめる（別のタブで提出した直後・同時の送信で 20 を超えない）
            $locked = ApprovalRequest::whereKey($approvalRequest->id)->lockForUpdate()->firstOrFail();

            if (! RequestPermissions::for($user, $locked)->canEdit()) {
                return response()->json(['message' => '添付を足せるのは、下書きと差戻し中の申請者だけです。'], 403);
            }

            if ($locked->attachments()->count() >= ApprovalAttachment::MAX_COUNT) {
                return response()->json(['message' => '添付は 1 件の申請に ' . ApprovalAttachment::MAX_COUNT . ' ファイルまでです。'], 422);
            }

            $path = $file->storeAs("approvals/{$locked->id}", Str::random(40) . '.' . $extension, 'local');

            $attachment = ApprovalAttachment::create([
                'request_id'    => $locked->id,
                'original_name' => $originalName,
                'stored_path'   => $path,
                'mime'          => ApprovalAttachment::TYPES[$extension],
                'size'          => $file->getSize(),
                'uploaded_by'   => $user->id,
                // この添付が初めて入る提出の回（差戻し中なら次の出し直し）
                'added_round'   => $locked->round + 1,
            ]);

            return response()->json([
                'success'    => true,
                'message'    => "{$originalName} を添付しました。",
                'attachment' => $attachment->listItem(),
            ]);
        });
    }

    /** 開く・ダウンロード（毎回、見られる範囲を確かめる。§5.7） */
    public function show(Request $request, ApprovalAttachment $approvalAttachment): StreamedResponse
    {
        $user            = $request->user();
        $approvalRequest = $approvalAttachment->request;
        abort_unless(RequestVisibility::canView($user, $approvalRequest), 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($approvalAttachment->stored_path), 404);

        // 一度でも提出した申請の添付は、開くたびに記録する（ブラウザで開いた場合も。14.2・計画 §0.5）
        if ($approvalRequest->round >= 1) {
            ApprovalDownloadLog::create([
                'request_id'    => $approvalRequest->id,
                'attachment_id' => $approvalAttachment->id,
                'user_id'       => $user->id,
                'kind'          => 'attachment',
                'ip_address'    => $request->ip(),
                'user_agent'    => mb_substr((string) $request->userAgent(), 0, 255),
            ]);
        }

        // 日本語の名前は filename*（UTF-8）で渡す（Laravel が ASCII の代わりの名前も付ける）
        return $disk->response(
            $approvalAttachment->stored_path,
            $approvalAttachment->original_name,
            [
                'Content-Type'           => ApprovalAttachment::TYPES[$approvalAttachment->extension()],
                'X-Content-Type-Options' => 'nosniff',
            ],
            $approvalAttachment->opensInline() ? 'inline' : 'attachment',
        );
    }

    /** 外す（Ajax・JSON。計画 §0.5 の表のとおり、下書きなら消し、提出したことがあれば外すだけ） */
    public function destroy(Request $request, ApprovalAttachment $approvalAttachment): JsonResponse
    {
        $user = $request->user();
        abort_unless(RequestVisibility::canView($user, $approvalAttachment->request), 404);

        $fileToDelete = null;

        $response = DB::transaction(function () use ($approvalAttachment, $user, &$fileToDelete): JsonResponse {
            $locked = ApprovalRequest::whereKey($approvalAttachment->request_id)->lockForUpdate()->firstOrFail();

            if (! RequestPermissions::for($user, $locked)->canEdit()) {
                return response()->json(['message' => '添付を外せるのは、下書きと差戻し中の申請者だけです。'], 403);
            }

            if ($approvalAttachment->removed_at !== null) {
                return response()->json(['message' => 'この添付はもう外してあります。画面を開き直してください。'], 422);
            }

            if ($locked->round === 0) {
                // 一度も提出していない: 記録（控え・ダウンロード）が無いので行ごと消す
                $approvalAttachment->delete();
                $fileToDelete = $approvalAttachment->stored_path;
            } else {
                // 一度でも提出した: 控えと記録から開けるように残し、一覧から外すだけ
                $approvalAttachment->update(['removed_round' => $locked->round + 1, 'removed_at' => now()]);
            }

            return response()->json(['success' => true, 'message' => "{$approvalAttachment->original_name} を外しました。"]);
        });

        // ファイルは行の削除が確定してから消す
        if ($fileToDelete !== null) {
            Storage::disk('local')->delete($fileToDelete);
        }

        return $response;
    }
}
```

- [ ] **Step 5: 申請書と詳細に添付の一覧を渡す**

`app/Http/Controllers/Approval/RequestController.php`

(a) `show()` の読み込みに `attachments` を足す:

```php
        $approvalRequest->load(['applicant', 'department', 'type', 'attachments']);
```

(b) `formData()` の返す配列の最後（`'returnNote' => …` の後）に足す:

```php
            // 今の添付（外したものを除く）。添付は 1 回保存したあとに足せる（D14）
            'attachmentList'  => $approvalRequest->exists
                ? $approvalRequest->attachments()->get()->map->listItem()->all()
                : [],
```

- [ ] **Step 6: 申請書の画面に添付の欄を足す**

`resources/views/approvals/requests/form.blade.php`

(a) 保存のフォームの `</form>` の直後（ルートの `</div>` の前）に足す:

```blade

    {{-- 添付（下書きを 1 回保存したあとに追加する。何枚かまとめて選べ、1 つずつ順に送る。D14・設計書 §5.7） --}}
    @if($editing)
        <section class="bg-white rounded-lg border border-gray-200 px-5 py-5 mt-5" x-data="approvalAttachments()">
            <h2 class="text-[14px] font-bold text-gray-900 mb-1">添付</h2>
            <p class="text-[11px] text-gray-400 mb-3">画像・PDF・Word・Excel・CSV・テキスト。1 ファイル 10MB まで、1 件の申請に {{ \App\Models\ApprovalAttachment::MAX_COUNT }} ファイルまで。何枚かまとめて選べます。</p>

            <div class="border-2 border-dashed rounded-lg p-4 text-center mb-3 transition-colors"
                 :class="dragOver ? 'border-emerald-400 bg-emerald-50' : 'border-gray-300 bg-gray-50'"
                 @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false" @drop.prevent="drop($event)">
                <label class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-700 hover:bg-gray-50 cursor-pointer">
                    ファイルを選ぶ
                    <input type="file" multiple class="hidden" accept="{{ '.' . implode(',.', array_keys(\App\Models\ApprovalAttachment::TYPES)) }}"
                           @change="choose($event)" :disabled="uploading">
                </label>
                <p class="text-[11px] text-gray-400 mt-2">ここへドラッグしても追加できます</p>
            </div>

            <p x-show="progress" x-cloak class="text-[12px] text-emerald-700 mb-2" x-text="progress"></p>
            <p x-show="successMessage" x-cloak class="text-[12px] text-emerald-700 mb-2" x-text="successMessage"></p>
            <p x-show="errorMessage" x-cloak class="text-[12px] text-red-600 mb-2 whitespace-pre-line" x-text="errorMessage"></p>

            <ul x-show="files.length > 0" class="divide-y divide-gray-100 border border-gray-200 rounded-md">
                <template x-for="file in files" :key="file.id">
                    <li class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-2 text-[13px]">
                        <a :href="file.url" target="_blank" rel="noopener" class="text-emerald-600 hover:underline break-all" x-text="file.name"></a>
                        <span class="text-[11px] text-gray-400" x-text="file.size"></span>
                        <span class="ml-auto inline-flex items-center gap-3">
                            <button type="button" x-show="confirmingId !== file.id" @click="confirmingId = file.id" class="text-[12px] text-red-600 hover:underline cursor-pointer">外す</button>
                            <button type="button" x-show="confirmingId === file.id" @click="remove(file)" class="text-[12px] font-semibold text-red-600 hover:underline cursor-pointer">外す（確定）</button>
                            <button type="button" x-show="confirmingId === file.id" @click="confirmingId = null" class="text-[12px] text-gray-500 hover:underline cursor-pointer">やめる</button>
                        </span>
                    </li>
                </template>
            </ul>
            <p x-show="files.length === 0" x-cloak class="text-[12px] text-gray-400">添付はまだありません。</p>
        </section>
    @else
        <p class="mt-5 text-[12px] text-gray-500">下書きを 1 回保存すると、ここで添付を追加できます。</p>
    @endif
```

(b) `@push('scripts')` の `<script>` の中、`function approvalRequestForm() { … }` の閉じ括弧の後（`</script>` の前）に足す:

```blade

@if($editing)
// 添付を 1 ファイルずつ順に送る（D14）。⚠ fetch の `.ok` の分岐と `!data` のガードは同じ数（AjaxErrorFeedbackTest）
function approvalAttachments() {
    return {
        files: {{ \Illuminate\Support\Js::from($attachmentList) }},
        storeUrl: '{{ route('approvals.requests.attachments.store', $approvalRequest) }}',
        csrfToken: '{{ csrf_token() }}',
        maxCount: {{ \App\Models\ApprovalAttachment::MAX_COUNT }},
        maxBytes: {{ \App\Models\ApprovalAttachment::MAX_KB }} * 1024,
        dragOver: false,
        uploading: false,
        progress: '',
        successMessage: '',
        errorMessage: '',
        confirmingId: null,

        choose: function (event) {
            var picked = Array.prototype.slice.call(event.target.files || []);
            event.target.value = '';
            this.enqueue(picked);
        },

        drop: function (event) {
            this.dragOver = false;
            this.enqueue(Array.prototype.slice.call(event.dataTransfer.files || []));
        },

        // 送る前に、10MB を超えるものと 20 ファイルを超える分を断る（理由を出す）
        enqueue: function (picked) {
            var self = this;
            var accepted = [];
            var refused = [];
            var room = self.maxCount - self.files.length;
            if (self.uploading || picked.length === 0) {
                return;
            }
            picked.forEach(function (file) {
                if (file.size > self.maxBytes) {
                    refused.push(file.name + '（10MB を超えています）');
                } else if (accepted.length >= room) {
                    refused.push(file.name + '（1 件の申請に ' + self.maxCount + ' ファイルまで）');
                } else {
                    accepted.push(file);
                }
            });
            self.successMessage = '';
            self.errorMessage = refused.length > 0 ? '次のファイルは送りませんでした: ' + refused.join('、') : '';
            self.uploadNext(accepted, 0, accepted.length);
        },

        uploadNext: function (queue, done, total) {
            var self = this;
            if (queue.length === 0) {
                self.uploading = false;
                self.progress = '';
                return;
            }
            var file = queue.shift();
            var body = new FormData();
            body.append('file', file);
            self.uploading = true;
            self.progress = (done + 1) + ' / ' + total + '：' + file.name + ' を送っています…';

            fetch(self.storeUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': self.csrfToken, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            })
            .then(function (res) {
                if (!res.ok) {
                    return res.json().then(function (err) {
                        self.errorMessage = self.appendLine(self.errorMessage, file.name + '：' + (err.message || '添付できませんでした。'));
                        return null;
                    }).catch(function () {
                        self.errorMessage = self.appendLine(self.errorMessage, file.name + '：添付できませんでした（' + res.status + '）。');
                        return null;
                    });
                }
                return res.json();
            })
            .then(function (data) {
                if (!data) {
                    self.uploadNext(queue, done + 1, total);
                    return;
                }
                self.files.push(data.attachment);
                self.successMessage = data.message;
                self.uploadNext(queue, done + 1, total);
            })
            .catch(function () {
                self.errorMessage = self.appendLine(self.errorMessage, file.name + '：通信に失敗しました。もう一度選んでください。');
                self.uploadNext(queue, done + 1, total);
            });
        },

        appendLine: function (text, line) {
            return text === '' ? line : text + '\n' + line;
        },

        remove: function (file) {
            var self = this;
            self.successMessage = '';
            self.errorMessage = '';

            fetch(file.delete_url, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': self.csrfToken, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) {
                if (!res.ok) {
                    return res.json().then(function (err) {
                        self.errorMessage = err.message || '外せませんでした。';
                        return null;
                    }).catch(function () {
                        self.errorMessage = '外せませんでした（' + res.status + '）。';
                        return null;
                    });
                }
                return res.json();
            })
            .then(function (data) {
                self.confirmingId = null;
                if (!data) return;
                self.files = self.files.filter(function (f) { return f.id !== file.id; });
                self.successMessage = data.message;
            })
            .catch(function () {
                self.confirmingId = null;
                self.errorMessage = '通信に失敗しました。もう一度お試しください。';
            });
        }
    };
}
@endif
```

- [ ] **Step 7: 詳細の画面に添付の欄を足す**

`resources/views/approvals/requests/show.blade.php` の「申請の中身」の `</section>` の後に足す:

```blade

    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">添付</h2>
        <ul class="px-5 py-3 space-y-1.5 text-[13px]">
            @forelse($approvalRequest->attachments as $attachment)
                <li class="flex flex-wrap items-center gap-x-2">
                    {{-- 開くたびに見られる範囲を確かめ、記録する（§5.7） --}}
                    <a href="{{ route('approvals.attachments.show', $attachment) }}" target="_blank" rel="noopener" class="text-emerald-600 hover:underline break-all">{{ $attachment->original_name }}</a>
                    <span class="text-[11px] text-gray-400">{{ $attachment->sizeLabel() }}{{ $attachment->opensInline() ? '' : '・ダウンロード' }}</span>
                </li>
            @empty
                <li class="text-gray-400">添付はありません。</li>
            @endforelse
        </ul>
    </section>
```

- [ ] **Step 8: ルートを足す**

`routes/approval.php` の先頭の `use` に足す:

```php
use App\Http\Controllers\Approval\RequestAttachmentController;
```

申請を回す画面のグループの最後（`requests.destroy` の後）に足す:

```php

    // 添付（段階2 設計書 §5.7・計画 §0.5）。追加と外すのは Ajax・JSON
    Route::post('/requests/{approvalRequest}/attachments', [RequestAttachmentController::class, 'store'])->name('requests.attachments.store');
    Route::get('/attachments/{approvalAttachment}', [RequestAttachmentController::class, 'show'])->name('attachments.show');
    Route::delete('/attachments/{approvalAttachment}', [RequestAttachmentController::class, 'destroy'])->name('attachments.destroy');
```

- [ ] **Step 9: 送信の上限を超えたときの応答を足す**

`bootstrap/app.php` の `->withExceptions(function (Exceptions $exceptions) { // })` の中身を次にする:

```php
    ->withExceptions(function (Exceptions $exceptions) {
        // 決裁の添付（Ajax）が PHP の送信の上限（post_max_size）を超えたとき、英語の既定文ではなく
        // 日本語の理由を JSON で返す（段階2 設計書 §5.7）。⚠ 決裁の URL だけ（基幹の画面の応答は変えない）
        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, \Illuminate\Http\Request $request) {
            if ($request->is('approvals/*') && $request->expectsJson()) {
                return response()->json(['message' => 'ファイルが大きすぎて受け取れませんでした。1 ファイル 10MB までです。'], 413);
            }
        });
    })
```

- [ ] **Step 10: 走査テストに分類を足す**

`tests/Feature/ClockReadScanTest.php` の `ALLOWED` の最後に足す:

```php
        'app/Http/Controllers/Approval/RequestAttachmentController.php' => [1, 'approval_attachments.removed_at は TIMESTAMP 列（外した瞬間を UTC で保存する）'],
```

`tests/Feature/Approval/ApprovalAdminGateTest.php` の `OPEN_TO_EVERY_USER` の `'approvals.requests.destroy' => …` の次に足す:

```php
        'approvals.requests.attachments.store' => '添付の追加（申請者だけ。下書き・差戻し中。段階2 設計書 §5.7）',
        'approvals.attachments.show'           => '添付を開く（見られる範囲を毎回確かめ、記録する）',
        'approvals.attachments.destroy'        => '添付を外す（申請者だけ。下書き・差戻し中）',
```

- [ ] **Step 11: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'RequestAttachmentTest|RequestFormTest|LaunchGateTest|ApprovalAdminGateTest|AjaxErrorFeedbackTest|ClockReadScanTest|JapaneseValidationMessagesTest|AttachmentAuthorizationTest|AttachmentDeliveryTest'
```

Expected: PASS（基幹の添付のテストも緑のまま）

- [ ] **Step 12: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add app/Http/Controllers/Approval/RequestAttachmentController.php app/Http/Controllers/Approval/RequestController.php app/Models/ApprovalAttachment.php resources/views/approvals/requests routes/approval.php bootstrap/app.php tests/Feature/ClockReadScanTest.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase2/RequestAttachmentTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
feat(approval): 申請の添付を足す

非公開の保管場所に上書きせずに置き、開くたびに見られる範囲を確かめる。一度でも提出した
申請の添付は、開くたびに記録し、外しても控えから開けるように残す。1 ファイル 10MB・
1 件 20 ファイルまで。画面はまとめて選んで 1 つずつ順に送る。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 15: 詳細の回る順番・操作の記録と、判断・条件確認・取り下げ（画面③の操作）

**Files:**
- Create: `app/Http/Controllers/Approval/RequestActionController.php`
- Create: `app/Support/Approval/CurrentHandler.php`（いま誰の番か。Task 16 の一覧・ホームでも使う）
- Create: `resources/views/approvals/requests/_steps.blade.php`
- Modify: `resources/views/approvals/requests/_actions.blade.php`（丸ごと置き換える）・`show.blade.php`
- Modify: `app/Http/Controllers/Approval/RequestController.php`（`show()`）
- Modify: `app/Enums/ApprovalStepStatus.php`（`badgeStyle()` を足す）
- Modify: `routes/approval.php`（5 本）・`lang/ja/validation.php`（和名 2 つ）
- Modify: `tests/Feature/Approval/ApprovalAdminGateTest.php`（`OPEN_TO_EVERY_USER` に 5 行）
- Test: `tests/Feature/Approval/Phase2/RequestActionTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/RequestActionTest.php`

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 詳細の回る順番・操作の記録と、判断・条件確認・取り下げ（設計書 §5.8・§5.12）。
 *
 * ⚠ 画面の文言を見るテストでは、描く前に assertSessionHas*() を呼ばない（Bug #49）。
 */
class RequestActionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ParsesForms;

    protected function setUp(): void
    {
        parent::setUp();
        // 決裁No の年度が動かないように止める（日本時間 2026-09-26 → ミツワの R8）。
        // ⚠ 止める時刻は UTC で渡す（日本時間のまま渡すと、now() で保存する日時が 9 時間ずれる）
        $this->travelTo(CarbonImmutable::parse('2026-09-26 10:00', 'Asia/Tokyo')->utc());
    }

    /** 画面と同じ形で送る（lock_version は今の値） */
    private function act(User $user, ApprovalRequest $request, string $route, array $data = []): TestResponse
    {
        return $this->actingAs($user)->post(route($route, $request), $data + ['lock_version' => (string) $request->fresh()->lock_version]);
    }

    private function showHtml(User $user, ApprovalRequest $request): string
    {
        return $this->actingAs($user)->get(route('approvals.requests.show', $request))->assertOk()->getContent();
    }

    /** 部門長・審査を通して、社長の決裁待ちまで進める */
    private function toPresident(array $w): ApprovalRequest
    {
        $request = $this->submittedFor($w);
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve']);
        $this->act($w['reviewer'], $request, 'approvals.requests.review', ['result' => 'ok']);

        return $request->refresh();
    }

    /** 部門長は判断の欄を見て、描いたフォームで承認できる */
    public function test_the_head_approves_through_the_rendered_form(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $html = $this->showHtml($w['head'], $request);
        $this->assertStringContainsString('部門長としての判断', $html);
        $this->assertStringContainsString('function approvalJudge(choices)', $html);
        $form = $this->parseForm($html, 'action="' . route('approvals.requests.headReview', $request) . '"');
        $this->assertSame((string) $request->lock_version, $form['fields']['lock_version']);
        $this->assertArrayHasKey('_token', $form['fields']);
        // 判断の値は、サーバーが描いた確定のボタンが送る（Bug #47）。押したボタンの result が一緒に送られる
        //   ⚠ 値と「どの判断を選んだときに見せるか」を対で見る（値を入れ替えても本数は同じなので、数えるだけでは見逃す）
        $this->assertStringContainsString('name="result" value="approve" x-show="choice === \'approve\'"', $html);
        $this->assertStringContainsString('name="result" value="return" x-show="choice === \'return\'"', $html);

        $this->actingAs($w['head'])->post($form['action'], $form['fields'] + ['result' => 'approve'])
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('success', '承認しました。審査へ回りました。');

        $this->assertSame(ApprovalStatus::Review, $request->fresh()->status);
    }

    public function test_a_return_needs_a_comment(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'return', 'comment' => ''])
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('error', '「差戻し」にはコメントが必要です。');

        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status);
    }

    /** 部門長 → 審査 → 社長の可で番号が付く（HTTP を通して。審査担当者は届くまで見られない。D19） */
    public function test_the_whole_route_ends_with_a_number(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $this->actingAs($w['reviewer'])->get(route('approvals.requests.show', $request))->assertNotFound();

        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve']);
        $this->assertStringContainsString('審査としての判断', $this->showHtml($w['reviewer'], $request));

        $this->act($w['reviewer'], $request, 'approvals.requests.review', ['result' => 'hold', 'comment' => '予算の根拠が弱い'])
            ->assertSessionHas('success', '意見（保留）を送りました。社長へ回りました。');

        $this->act($w['president'], $request, 'approvals.requests.decide', ['result' => 'approve'])
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('success', '「可」で決裁しました（決裁No R8-J-001）。');

        $fresh = $request->fresh();
        $this->assertSame(ApprovalStatus::Approved, $fresh->status);
        $this->assertSame('R8-J-001', $fresh->number);
    }

    /** 条可は申請者が条件を確認して決裁済み（条可）になる（要件 4.6） */
    public function test_a_conditional_approval_is_confirmed_by_the_applicant(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->toPresident($w);

        $this->act($w['president'], $request, 'approvals.requests.decide', ['result' => 'conditional', 'comment' => '見積りを 2 社から取ること']);

        $html = $this->showHtml($w['applicant'], $request);
        $this->assertStringContainsString('社長の条件を確認してください', $html);
        $this->assertStringContainsString('見積りを 2 社から取ること', $html);
        $form = $this->parseForm($html, 'action="' . route('approvals.requests.confirmCondition', $request) . '"');
        $this->assertSame((string) $request->fresh()->lock_version, $form['fields']['lock_version']);

        $this->actingAs($w['applicant'])->post($form['action'], $form['fields'])
            ->assertSessionHas('success', '条件を確認しました。決裁が完了しました。');

        $this->assertSame('決裁済み（条可）', $request->fresh()->statusLabel());
    }

    /** 古い画面から押した操作は断る（同時操作。計画 §0.3） */
    public function test_a_stale_screen_is_refused(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $stale   = (string) $request->lock_version;

        // 別のタブで先に承認した
        app(Workflow::class)->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);

        $this->actingAs($w['head'])->post(route('approvals.requests.headReview', $request), ['result' => 'return', 'comment' => '差し戻し', 'lock_version' => $stale])
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('error', 'すでに処理されています。画面を開き直して、今の状態を確かめてください。');

        $this->assertSame(ApprovalStatus::Review, $request->fresh()->status);
    }

    /** 担当でない人は判断できない。見られない人は 404 */
    public function test_someone_who_is_not_assigned_cannot_judge(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $this->act($this->viewAllUser(), $request, 'approvals.requests.headReview', ['result' => 'approve'])
            ->assertSessionHas('error', 'この申請を判断する権限がありません。');
        $this->act($this->approvalOnlyUser(), $request, 'approvals.requests.headReview', ['result' => 'approve'])
            ->assertNotFound();

        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status);
    }

    /** 自分の申請には判断できない。理由を出し、押せないボタンに理由を付ける（D16・Bug #43） */
    public function test_nobody_judges_their_own_request(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        // 申請のあとで申請者が部門長になった（画面の歯止めは Task 9。ここは判断の禁止だけを見る）
        $w['dept']->update(['head_user_id' => $w['applicant']->id]);

        $html = $this->showHtml($w['applicant'], $request);
        $this->assertStringContainsString('<span title="自分の申請には判断できません。"', $html);
        $this->assertStringNotContainsString('action="' . route('approvals.requests.headReview', $request) . '"', $html);

        $this->act($w['applicant'], $request, 'approvals.requests.headReview', ['result' => 'approve'])
            ->assertSessionHas('error', '自分の申請には判断できません。');

        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status);
    }

    /** 申請者は社長の判断の前なら取り下げられる（コメントは任意。D17） */
    public function test_the_applicant_can_withdraw(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $form = $this->parseForm($this->showHtml($w['applicant'], $request), 'action="' . route('approvals.requests.withdraw', $request) . '"');

        $this->actingAs($w['applicant'])->post($form['action'], array_merge($form['fields'], ['comment' => '別の案で出し直します']))
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('success', '取り下げました。');

        $this->assertSame(ApprovalStatus::Withdrawn, $request->fresh()->status);
    }

    /** 回る順番（担当は今の設定から）と、操作の記録（新しい順） */
    public function test_the_steps_and_the_history_are_shown(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve', 'comment' => '了解です']);

        $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->assertOk()->assertSeeInOrder([
            '回る順番',
            '部門長', '済み', '承認', '部門 長', '了解です',
            '審査', '確認待ち', '担当: 総務部（審査 担当）',
            '社長', 'まだ届いていない', '担当: 社長 太郎',
            '操作の記録',
            '部門長が承認', '部門 長', '了解です',
            '提出', '申請 花子',
        ]);
    }

    /** 申請者が部門長なら、部門長の段階は省略と出る（4.3 のケース 1） */
    public function test_a_skipped_head_step_says_why(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $w['head']->approvalDepartments()->attach($w['dept']->id);
        $w['applicant'] = $w['head']->fresh();
        $request = $this->submittedFor($w);

        $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()
            ->assertSee('申請者が部門長のため省略')
            ->assertSee('部門長の確認を省略（申請者が部門長のため）');
    }

    /** 全件閲覧者は見られるが、操作は何も出ない */
    public function test_a_view_all_user_sees_no_actions(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $html = $this->showHtml($this->viewAllUser(), $request);

        $this->assertStringNotContainsString('としての判断', $html);
        $this->assertStringNotContainsString('取り下げる', $html);
        $this->assertStringNotContainsString('コピーして新しい申請', $html);
    }

    /** 長すぎるコメントは詳細の画面にエラーとして出る（⚠ assertSessionHas* を呼ばずに描く。Bug #49） */
    public function test_a_too_long_comment_is_shown_on_the_detail(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'return', 'comment' => str_repeat('あ', 2001)])
            ->assertRedirect(route('approvals.requests.show', $request));

        $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()
            ->assertSee('入力内容にエラーがあります。')
            ->assertSee('コメントは2000文字以下で入力してください。');
    }
}
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter RequestActionTest
```

Expected: FAIL（`Route [approvals.requests.headReview] not defined.`）

- [ ] **Step 3: 段階の状態にバッジの色を足す**

`app/Enums/ApprovalStepStatus.php` の `label()` の後に足す:

```php
    /** 回る順番のバッジ（inline style を返す。Tailwind クラス指定は規約で NG） */
    public function badgeStyle(): string
    {
        return match ($this) {
            self::Waiting                                  => 'background: #dbeafe; color: #1e40af;',
            self::Done                                     => 'background: #d1fae5; color: #065f46;',
            self::Pending, self::Skipped, self::Cancelled => 'background: #f3f4f6; color: #6b7280;',
        };
    }
```

- [ ] **Step 4: 「いま誰の番か」の部品を書く**

`app/Support/Approval/CurrentHandler.php`

```php
<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;

/**
 * 「いま誰の番か」（詳細の回る順番・自分の申請一覧・ホーム。設計書 §5.12）。
 *
 * ⚠ 担当は「今の」設定から引く（部門長は付け替えが無ければ申請部門の今の部門長、審査はその審査部門の
 *   今の審査担当者、社長は今の社長。要件 4.3 のケース 5〜7）。RequestPermissions::isAssigneeOf() と同じ規則。
 * ⚠ 一覧で使うときは steps.department.head・steps.department.reviewers・steps.assignee を先に読む（N+1）。
 */
final class CurrentHandler
{
    /** その段階の担当者の名前 @return list<string> */
    public static function namesFor(ApprovalStep $step): array
    {
        return match ($step->kind) {
            ApprovalStepKind::Head => array_values(array_filter([
                ($step->assignee_user_id !== null ? $step->assignee : $step->department?->head)?->name,
            ])),
            ApprovalStepKind::Review    => $step->department?->reviewers->pluck('name')->all() ?? [],
            ApprovalStepKind::President => array_values(array_filter([ApprovalSetting::current()->president?->name])),
        };
    }

    /** 段階の担当の説明（詳細の回る順番に出す。審査は部門名を添える） */
    public static function describe(ApprovalStep $step): string
    {
        $names = self::namesFor($step);
        $who   = $names === [] ? '担当者が未設定' : implode('・', $names);

        return $step->kind === ApprovalStepKind::Review ? "{$step->department?->name}（{$who}）" : $who;
    }

    /** いま誰の番か（一覧・ホームに出す短い言葉） */
    public static function label(ApprovalRequest $request): string
    {
        return match ($request->status) {
            ApprovalStatus::Draft     => '申請者（下書き）',
            ApprovalStatus::Returned  => '申請者（差戻しの対応）',
            ApprovalStatus::Condition => '申請者（条件の確認）',
            ApprovalStatus::HeadReview, ApprovalStatus::Review, ApprovalStatus::President => self::waitingLabel($request),
            ApprovalStatus::Approved, ApprovalStatus::Rejected, ApprovalStatus::Withdrawn => '—',
        };
    }

    private static function waitingLabel(ApprovalRequest $request): string
    {
        $step = $request->steps
            ->where('round', $request->round)
            ->firstWhere('status', ApprovalStepStatus::Waiting);

        if ($step === null) {
            return '—';
        }

        return $step->kind === ApprovalStepKind::Review
            ? "審査（{$step->department?->name}）"
            : $step->kind->label() . '（' . self::describe($step) . '）';
    }
}
```

- [ ] **Step 5: 操作のコントローラを書く**

`app/Http/Controllers/Approval/RequestActionController.php`

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Support\Approval\RequestVisibility;
use App\Support\Approval\Workflow;
use App\Support\Approval\WorkflowConflict;
use App\Support\Approval\WorkflowRefused;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 判断・条件確認・取り下げ（要件 4.2・4.5・4.6・設計書 §5.8）。
 *
 * ⚠ 権限と状態は Workflow（RequestPermissions）が確かめる。ここは入力の形を見て渡すだけ。
 * ⚠ 画面が描いたときの lock_version を渡す（古い画面から押した操作を「すでに処理されています」で断る。計画 §0.3）。
 *   送られてこなければ 0 になる（提出した申請の lock_version は 1 以上なので必ず断られる）。
 * ⚠ 戻り先はいつも詳細の画面（Bug #64）。
 */
class RequestActionController extends Controller
{
    public function __construct(private readonly Workflow $workflow)
    {
    }

    /** 部門長の承認・差戻し */
    public function headReview(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        return $this->judge($request, $approvalRequest, ApprovalStepKind::Head);
    }

    /** 審査の意見（可・保留・否） */
    public function review(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        return $this->judge($request, $approvalRequest, ApprovalStepKind::Review);
    }

    /** 社長の決裁（可・条可・差戻し・否） */
    public function decide(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        return $this->judge($request, $approvalRequest, ApprovalStepKind::President);
    }

    /** 条件の確認（申請者。要件 4.6） */
    public function confirmCondition(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $comment = $this->optionalComment($request, $approvalRequest);

        return $this->run(
            $approvalRequest,
            fn () => $this->workflow->confirmCondition($approvalRequest, $request->user(), $request->integer('lock_version'), $comment),
            '条件を確認しました。決裁が完了しました。',
        );
    }

    /** 取り下げ（申請者。コメントは任意。D17） */
    public function withdraw(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $comment = $this->optionalComment($request, $approvalRequest);

        return $this->run(
            $approvalRequest,
            fn () => $this->workflow->withdraw($approvalRequest, $request->user(), $request->integer('lock_version'), $comment),
            '取り下げました。',
        );
    }

    private function judge(Request $request, ApprovalRequest $approvalRequest, ApprovalStepKind $kind): RedirectResponse
    {
        $this->assertVisible($request, $approvalRequest);

        $allowed = array_map(fn (ApprovalStepResult $result) => $result->value, ApprovalStepResult::allowedFor($kind));

        try {
            $validated = $request->validate([
                'result'  => ['required', Rule::in($allowed)],
                'comment' => ['nullable', 'string', 'max:2000'],
            ], [
                'result.required' => '判断を選んでください。',
                'result.in'       => '選べない判断です。',
            ]);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('approvals.requests.show', $approvalRequest));
        }

        $result  = ApprovalStepResult::from($validated['result']);
        $comment = $validated['comment'] ?? null;
        $version = $request->integer('lock_version');
        $actor   = $request->user();

        return $this->run(
            $approvalRequest,
            fn () => match ($kind) {
                ApprovalStepKind::Head      => $this->workflow->judgeHead($approvalRequest, $actor, $version, $result, $comment),
                ApprovalStepKind::Review    => $this->workflow->judgeReview($approvalRequest, $actor, $version, $result, $comment),
                ApprovalStepKind::President => $this->workflow->judgePresident($approvalRequest, $actor, $version, $result, $comment),
            },
            fn () => self::judgedMessage($kind, $result, $approvalRequest),
        );
    }

    /**
     * Workflow を呼び、詳細の画面へ戻す（断られたら理由、先を越されたら「すでに処理されています」）。
     *
     * @param Closure(): void $action
     * @param string|Closure(): string $success
     */
    private function run(ApprovalRequest $approvalRequest, Closure $action, string|Closure $success): RedirectResponse
    {
        $show = redirect()->route('approvals.requests.show', $approvalRequest);

        try {
            $action();
        } catch (WorkflowConflict) {
            return $show->with('error', WorkflowConflict::MESSAGE);
        } catch (WorkflowRefused $e) {
            return $show->with('error', implode(' ', $e->reasons));
        }

        return $show->with('success', $success instanceof Closure ? $success() : $success);
    }

    /** 判断のあとの帯の言葉（社長の判断は付いた決裁No を添える。Workflow が同じインスタンスを読み直している） */
    private static function judgedMessage(ApprovalStepKind $kind, ApprovalStepResult $result, ApprovalRequest $approvalRequest): string
    {
        if ($result === ApprovalStepResult::Return) {
            return '差し戻しました。';
        }

        return match ($kind) {
            ApprovalStepKind::Head      => '承認しました。審査へ回りました。',
            ApprovalStepKind::Review    => '意見（' . $result->labelFor($kind) . '）を送りました。社長へ回りました。',
            ApprovalStepKind::President => '「' . $result->labelFor($kind) . '」で決裁しました（決裁No ' . $approvalRequest->number . '）。',
        };
    }

    /** 条件確認・取り下げのコメント（任意・2,000 文字まで） */
    private function optionalComment(Request $request, ApprovalRequest $approvalRequest): ?string
    {
        $this->assertVisible($request, $approvalRequest);

        try {
            $validated = $request->validate([
                'comment' => ['nullable', 'string', 'max:2000'],
            ]);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('approvals.requests.show', $approvalRequest));
        }

        return $validated['comment'] ?? null;
    }

    /** 見られない申請は 404（在るかどうかを漏らさない。設計書 §5.10） */
    private function assertVisible(Request $request, ApprovalRequest $approvalRequest): void
    {
        abort_unless(RequestVisibility::canView($request->user(), $approvalRequest), 404);
    }
}
```

- [ ] **Step 6: 詳細の画面に回る順番・記録・操作を足す**

`resources/views/approvals/requests/_steps.blade.php`

```blade
{{-- 回る順番と各人の判断（今の回。設計書 §5.12）。担当は今の設定から引く（CurrentHandler）。
     前の回の判断は「操作の記録」に出る。データ印は段階4。 --}}
@php $steps = $approvalRequest->currentSteps(); @endphp
<section class="bg-white rounded-lg border border-gray-200 mb-5">
    <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">
        回る順番
        @if($approvalRequest->round > 1)
            <span class="ml-1 text-[12px] font-normal text-gray-500">（{{ $approvalRequest->round }} 回目の提出）</span>
        @endif
    </h2>
    @if($steps->isEmpty())
        <p class="px-5 py-4 text-[13px] text-gray-400">提出すると、部門長・審査・社長の順に回ります。</p>
    @else
        <ol class="divide-y divide-gray-100">
            @foreach($steps as $step)
                <li class="px-5 py-3 text-[13px]">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="font-semibold text-gray-900 min-w-[4em]">{{ $step->kind->label() }}</span>
                        <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $step->status->badgeStyle() }}">{{ $step->status->label() }}</span>
                        @switch($step->status)
                            @case(\App\Enums\ApprovalStepStatus::Done)
                                <span class="font-semibold text-gray-900">{{ $step->result->labelFor($step->kind) }}</span>
                                <span class="text-gray-700">{{ $step->actor?->name }}</span>
                                <span class="text-[12px] text-gray-400">{{ \App\Support\JapanTime::format($step->acted_at) }}</span>
                                @break
                            @case(\App\Enums\ApprovalStepStatus::Skipped)
                                <span class="text-gray-500">申請者が部門長のため省略</span>
                                @break
                            @case(\App\Enums\ApprovalStepStatus::Cancelled)
                                <span class="text-gray-500">差戻し・取り下げのため打ち切り</span>
                                @break
                            @default
                                <span class="text-gray-700">担当: {{ \App\Support\Approval\CurrentHandler::describe($step) }}</span>
                                @if($step->arrived_at)
                                    <span class="text-[12px] text-gray-400">{{ \App\Support\JapanTime::format($step->arrived_at) }} に届きました</span>
                                @endif
                        @endswitch
                    </div>
                    @if($step->comment)
                        <p class="mt-1.5 rounded-md bg-gray-50 px-3 py-2 text-gray-800 whitespace-pre-wrap break-words">{{ $step->comment }}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</section>
```

`resources/views/approvals/requests/_actions.blade.php` を**丸ごと**次にする（Task 13 の申請者の操作に、取り下げ・条件確認・判断を足したもの）:

```blade
{{-- 申請の詳細の操作（設計書 §5.12）。出すのは RequestPermissions がこの人に「今できる」と判定したものだけ。
     押せないけれど役割のある人（自分の申請の担当に当たる人。D16）には理由を出す（Bug #43）。
     ⚠ 判断・条件確認・取り下げのフォームは、描いたときの lock_version を送る（古い画面から押した操作を断る。計画 §0.3） --}}
@php
    $judgeable = $permissions->judgeableStep();
    $refusal   = $permissions->judgeRefusal();
    $waiting   = $permissions->waitingStep();
@endphp

{{-- 申請者の操作 --}}
@if($permissions->isApplicant())
    <div class="flex flex-wrap items-center gap-2 mb-5" x-data="{ confirmDelete: false, confirmWithdraw: false }">
        @if($permissions->canEdit())
            <a href="{{ route('approvals.requests.edit', $approvalRequest) }}" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold">{{ $approvalRequest->status === \App\Enums\ApprovalStatus::Returned ? '直して出し直す' : '編集する' }}</a>
        @endif
        <a href="{{ route('approvals.requests.create', ['copy' => $approvalRequest->id]) }}" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-700 hover:bg-gray-50">コピーして新しい申請</a>
        @if($permissions->canWithdraw())
            <button type="button" @click="confirmWithdraw = true" class="px-4 py-2 bg-white border border-red-200 rounded-md text-[13px] font-semibold text-red-600 hover:bg-red-50 cursor-pointer">取り下げる</button>
        @endif

        @if($permissions->canDelete())
            <form method="POST" action="{{ route('approvals.requests.destroy', $approvalRequest) }}">
                @csrf
                @method('DELETE')
                <button type="button" @click="confirmDelete = true" class="px-4 py-2 bg-white border border-red-200 rounded-md text-[13px] font-semibold text-red-600 hover:bg-red-50 cursor-pointer">下書きを削除</button>

                <div x-show="confirmDelete" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
                    <div @click.outside="confirmDelete = false" class="bg-white rounded-xl w-full max-w-[420px] shadow-xl mx-4 px-6 py-5">
                        <p class="text-[15px] font-bold text-gray-900 mb-2">この下書きを削除しますか？</p>
                        <p class="text-[12px] text-gray-500 mb-4">添付したファイルも消えます。元に戻せません。</p>
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="confirmDelete = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                            <button type="submit" class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">削除する</button>
                        </div>
                    </div>
                </div>
            </form>
        @endif

        @if($permissions->canWithdraw())
            {{-- 取り下げの確認（コメントは任意。D17） --}}
            <div x-show="confirmWithdraw" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
                <div @click.outside="confirmWithdraw = false" class="bg-white rounded-xl w-full max-w-[480px] shadow-xl mx-4">
                    <form method="POST" action="{{ route('approvals.requests.withdraw', $approvalRequest) }}">
                        @csrf
                        <input type="hidden" name="lock_version" value="{{ $approvalRequest->lock_version }}">
                        <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">この申請を取り下げますか？</div>
                        <div class="px-6 py-4">
                            <p class="text-[12px] text-gray-500 mb-3">取り下げると回覧が止まり、元に戻せません。{{ $approvalRequest->number ? '決裁No はそのまま残ります。' : '' }}</p>
                            <label for="withdraw-comment" class="block text-[12px] font-semibold text-gray-700 mb-1">コメント<span class="text-gray-400 font-normal ml-1">（任意）</span></label>
                            <textarea id="withdraw-comment" name="comment" rows="3" maxlength="2000" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed"></textarea>
                        </div>
                        <div class="px-6 pb-5 flex justify-end gap-2">
                            <button type="button" @click="confirmWithdraw = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                            <button type="submit" class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">取り下げる</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endif

{{-- 条件の確認（申請者。要件 4.6。条件は社長の段階のコメント） --}}
@if($permissions->canConfirmCondition())
    @php $conditionStep = $approvalRequest->currentSteps()->firstWhere('kind', \App\Enums\ApprovalStepKind::President); @endphp
    <section class="bg-amber-50 rounded-lg border border-amber-200 mb-5 px-5 py-4" x-data="{ confirmCondition: false }">
        <h2 class="text-[14px] font-bold text-amber-900 mb-1">社長の条件を確認してください</h2>
        <p class="text-[13px] text-amber-900 whitespace-pre-wrap break-words mb-3">{{ $conditionStep?->comment }}</p>
        <button type="button" @click="confirmCondition = true" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">条件を確認しました</button>

        <div x-show="confirmCondition" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
            <div @click.outside="confirmCondition = false" class="bg-white rounded-xl w-full max-w-[480px] shadow-xl mx-4">
                <form method="POST" action="{{ route('approvals.requests.confirmCondition', $approvalRequest) }}">
                    @csrf
                    <input type="hidden" name="lock_version" value="{{ $approvalRequest->lock_version }}">
                    <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">条件を確認したことを記録しますか？</div>
                    <div class="px-6 py-4">
                        <p class="text-[12px] text-gray-500 mb-3">記録すると決裁済み（条可）になります。</p>
                        <label for="condition-comment" class="block text-[12px] font-semibold text-gray-700 mb-1">コメント<span class="text-gray-400 font-normal ml-1">（任意）</span></label>
                        <textarea id="condition-comment" name="comment" rows="3" maxlength="2000" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed"></textarea>
                    </div>
                    <div class="px-6 pb-5 flex justify-end gap-2">
                        <button type="button" @click="confirmCondition = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                        <button type="submit" class="px-5 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">確認しました</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
@endif

{{-- 判断（部門長・審査・社長。要件 4.2）。選んだ判断とコメントを確認のモーダルで確かめてから送る --}}
@if($judgeable)
    @php
        $kind       = $judgeable->kind;
        $choices    = \App\Enums\ApprovalStepResult::allowedFor($kind);
        $judgeRoute = match ($kind) {
            \App\Enums\ApprovalStepKind::Head      => 'approvals.requests.headReview',
            \App\Enums\ApprovalStepKind::Review    => 'approvals.requests.review',
            \App\Enums\ApprovalStepKind::President => 'approvals.requests.decide',
        };
        $hint = match ($kind) {
            \App\Enums\ApprovalStepKind::Head      => '承認すると審査へ回ります。差し戻すと申請者が直して出し直します（差戻しはコメントが必要）。',
            \App\Enums\ApprovalStepKind::Review    => '可・保留・否のどれでも社長へ回ります（保留・否はコメントが必要）。',
            \App\Enums\ApprovalStepKind::President => '可・条可・否で決裁No が付きます。条可はコメントに条件を書いてください。差し戻すと申請者が直して出し直します。',
        };
        $choiceData = [];
        foreach ($choices as $choice) {
            $choiceData[$choice->value] = ['label' => $choice->labelFor($kind), 'comment' => $choice->requiresComment()];
        }
    @endphp
    <section class="bg-white rounded-lg border-2 border-emerald-200 mb-5 px-5 py-4" x-data="approvalJudge({{ \Illuminate\Support\Js::from($choiceData) }})">
        <h2 class="text-[14px] font-bold text-gray-900 mb-1">{{ $kind->label() }}としての判断</h2>
        <p class="text-[12px] text-gray-500 mb-3">{{ $hint }}</p>
        <div class="flex flex-wrap gap-2">
            @foreach($choices as $choice)
                <button type="button" @click="open('{{ $choice->value }}')"
                        class="px-4 py-2 rounded-md text-[13px] font-semibold cursor-pointer border {{ in_array($choice, [\App\Enums\ApprovalStepResult::Approve, \App\Enums\ApprovalStepResult::Ok], true) ? 'bg-emerald-600 hover:bg-emerald-700 text-white border-emerald-600' : 'bg-white hover:bg-gray-50 text-gray-800 border-gray-300' }}">{{ $choice->labelFor($kind) }}</button>
            @endforeach
        </div>

        <div x-show="choice !== null" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
            <div @click.outside="choice = null" class="bg-white rounded-xl w-full max-w-[480px] shadow-xl mx-4">
                <form method="POST" action="{{ route($judgeRoute, $approvalRequest) }}">
                    @csrf
                    <input type="hidden" name="lock_version" value="{{ $approvalRequest->lock_version }}">
                    <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">「<span x-text="label()"></span>」で確定しますか？</div>
                    <div class="px-6 py-4">
                        <label for="judge-comment" class="block text-[12px] font-semibold text-gray-700 mb-1">
                            コメント
                            <span x-show="needsComment()" class="text-red-600">（必須）</span>
                            <span x-show="!needsComment()" class="text-gray-400 font-normal">（任意）</span>
                        </label>
                        <textarea id="judge-comment" name="comment" rows="5" maxlength="2000" :required="needsComment()"
                                  class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed"></textarea>
                    </div>
                    <div class="px-6 pb-5 flex justify-end gap-2">
                        <button type="button" @click="choice = null" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                        {{-- ⚠ 判断の値はサーバーが描く（:value にすると往復テストが配線を拾えない。Bug #47）。選んだものだけを見せる --}}
                        @foreach($choices as $choice)
                            <button type="submit" name="result" value="{{ $choice->value }}" x-show="choice === '{{ $choice->value }}'"
                                    class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">確定する</button>
                        @endforeach
                    </div>
                </form>
            </div>
        </div>
    </section>

    @push('scripts')
    <script>
    {{-- ⚠ x-data にアロー関数を書かない（Top trap #4）。選べる判断は Js::from で渡す（Bug #23） --}}
    function approvalJudge(choices) {
        return {
            choices: choices,
            choice: null,
            open: function (value) { this.choice = value; },
            label: function () { return this.choice ? this.choices[this.choice].label : ''; },
            needsComment: function () { return this.choice ? this.choices[this.choice].comment : false; }
        };
    }
    </script>
    @endpush
@elseif($refusal)
    {{-- 担当に当たるが自分の申請なので判断できない（D16）。押せないボタンは span で包んで理由を付ける（Bug #43） --}}
    <section class="bg-amber-50 rounded-lg border border-amber-200 mb-5 px-5 py-4">
        <p class="text-[13px] font-semibold text-amber-900 mb-1">{{ $refusal }}</p>
        <p class="text-[12px] text-amber-800 mb-3">{{ $waiting->kind === \App\Enums\ApprovalStepKind::Review ? 'ほかの審査担当者が判断します。' : '担当を替えるには、決裁の管理者に相談してください。' }}</p>
        <div class="flex flex-wrap gap-2">
            @foreach(\App\Enums\ApprovalStepResult::allowedFor($waiting->kind) as $choice)
                <span title="{{ $refusal }}" style="display: inline-flex;">
                    <button type="button" disabled class="px-4 py-2 rounded-md text-[13px] font-semibold border border-gray-300 bg-white text-gray-400 cursor-not-allowed">{{ $choice->labelFor($waiting->kind) }}</button>
                </span>
            @endforeach
        </div>
    </section>
@endif
```

`resources/views/approvals/requests/show.blade.php`

(a) `<div class="max-w-[880px]">` の直後に足す:

```blade

    {{-- 成功・失敗の帯はレイアウトが出す。$errors（コメントの長さなど）だけここで出す --}}
    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif
```

(b) 「添付」の `</section>` の後（ルートの `</div>` の前）に足す:

```blade

    @include('approvals.requests._steps')

    {{-- 操作の記録（新しい順。記録は提出から付く。§5.11・§5.12） --}}
    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">操作の記録</h2>
        <ol class="px-5 py-3 space-y-2.5 text-[13px]">
            @forelse($histories as $history)
                <li>
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="text-[12px] text-gray-400 tabular-nums">{{ \App\Support\JapanTime::format($history->created_at) }}</span>
                        <span class="font-semibold text-gray-900">{{ $history->label() }}</span>
                        @if($history->actor)
                            <span class="text-gray-600">{{ $history->actor->name }}</span>
                        @endif
                    </div>
                    @if($history->comment)
                        <p class="mt-0.5 text-gray-700 whitespace-pre-wrap break-words">{{ $history->comment }}</p>
                    @endif
                </li>
            @empty
                <li class="text-gray-400">まだ記録はありません（提出から記録します）。</li>
            @endforelse
        </ol>
    </section>
```

- [ ] **Step 7: 詳細のコントローラで段階と記録を読む**

`app/Http/Controllers/Approval/RequestController.php` の `use` に `use App\Models\ApprovalHistory;` を足し、`show()` を次にする:

```php
    public function show(Request $request, ApprovalRequest $approvalRequest): View
    {
        $user = $request->user();
        $this->assertVisible($user, $approvalRequest);

        // 回る順番の担当は今の設定から引く（CurrentHandler）。部門長・審査担当者・付け替え・判断した人を先に読む
        $approvalRequest->load([
            'applicant', 'department', 'type', 'attachments',
            'steps.department.head', 'steps.department.reviewers', 'steps.assignee', 'steps.actor',
        ]);

        return view('approvals.requests.show', [
            'approvalRequest' => $approvalRequest,
            'permissions'     => RequestPermissions::for($user, $approvalRequest),
            'relatedLinks'    => $this->relatedLinks($approvalRequest, $user),
            // 操作の記録（新しい順。§5.12）
            'histories'       => ApprovalHistory::with('actor')->where('request_id', $approvalRequest->id)->orderByDesc('id')->get(),
        ]);
    }
```

- [ ] **Step 8: ルートと和名を足す**

`routes/approval.php` の先頭の `use` に `use App\Http\Controllers\Approval\RequestActionController;` を足し、申請を回す画面のグループの最後（`attachments.destroy` の後）に足す:

```php

    // 判断・条件確認・取り下げ（段階2 設計書 §5.8）。役割ごとに分ける（権限の確かめ方が違うため）
    Route::post('/requests/{approvalRequest}/head-review', [RequestActionController::class, 'headReview'])->name('requests.headReview');
    Route::post('/requests/{approvalRequest}/review', [RequestActionController::class, 'review'])->name('requests.review');
    Route::post('/requests/{approvalRequest}/decide', [RequestActionController::class, 'decide'])->name('requests.decide');
    Route::post('/requests/{approvalRequest}/confirm-condition', [RequestActionController::class, 'confirmCondition'])->name('requests.confirmCondition');
    Route::post('/requests/{approvalRequest}/withdraw', [RequestActionController::class, 'withdraw'])->name('requests.withdraw');
```

`lang/ja/validation.php` の段階2 の節（Task 13 で足した `'related_numbers.*' => …` の次）に足す（足す前に `grep -n "'result'\|'comment'" lang/ja/validation.php` が何も出さないことを確かめる）:

```php
        // 判断・条件確認・取り下げ（§5.8）
        'result'            => '判断',
        'comment'           => 'コメント',
```

- [ ] **Step 9: 管理の画面の走査に分類を足す**

`tests/Feature/Approval/ApprovalAdminGateTest.php` の `OPEN_TO_EVERY_USER` の `'approvals.attachments.destroy' => …` の次に足す:

```php
        'approvals.requests.headReview'       => '部門長の承認・差戻し（担当かどうかは Workflow が確かめる。段階2 設計書 §5.8）',
        'approvals.requests.review'           => '審査の意見（同上）',
        'approvals.requests.decide'           => '社長の決裁（同上）',
        'approvals.requests.confirmCondition' => '条件の確認（申請者だけ）',
        'approvals.requests.withdraw'         => '取り下げ（申請者だけ）',
```

- [ ] **Step 10: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'RequestActionTest|RequestFormTest|RequestAttachmentTest|WorkflowTest|Phase2ModelsTest|LaunchGateTest|ApprovalAdminGateTest|JapaneseValidationMessagesTest|ValidationErrorFeedbackTest|StoredTimestampDisplayScanTest|ClockReadScanTest|AlpineXShowDisplayConflictTest|MobileLayoutTest'
```

Expected: PASS

- [ ] **Step 11: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add app/Http/Controllers/Approval/RequestActionController.php app/Http/Controllers/Approval/RequestController.php app/Support/Approval/CurrentHandler.php app/Enums/ApprovalStepStatus.php resources/views/approvals/requests routes/approval.php lang/ja/validation.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase2/RequestActionTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
feat(approval): 申請の詳細に回る順番と記録、判断の操作を足す

部門長・審査・社長の判断、条件の確認、取り下げを詳細の画面から行う。出すのはその人が
今できる操作だけで、判断は確認の小窓で確かめてから送る。自分の申請には判断できない理由を
押せないボタンに添える。古い画面から押した操作は「すでに処理されています」で断る。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 16: 使い始めたあとのホーム（画面①）と自分の申請一覧（画面④）

**Files:**
- Create: `app/Support/Approval/PendingWork.php`
- Create: `resources/views/approvals/home-launched.blade.php`・`resources/views/approvals/requests/index.blade.php`
- Modify: `app/Http/Controllers/Approval/HomeController.php`（丸ごと置き換える）・`RequestController.php`（`index()` を足す）
- Modify: `routes/approval.php`（1 本）・`tests/Feature/Approval/ApprovalAdminGateTest.php`（1 行）
- Test: `tests/Feature/Approval/Phase2/PendingWorkTest.php`・`HomeAndListTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/PendingWorkTest.php`

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\Approval\PendingWork;
use App\Support\Approval\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 自分の対応待ち（ホーム①・設計書 §5.12・D16・D20） */
class PendingWorkTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    /** @return list<string> 「件名 / 役割 / 対応」 */
    private function listFor(User $user): array
    {
        return PendingWork::for($user)
            ->map(fn (array $item) => "{$item['request']->subject} / {$item['role']} / {$item['action']}")
            ->all();
    }

    public function test_each_role_sees_its_own_turn(): void
    {
        $w        = $this->approvalWorld();
        $workflow = app(Workflow::class);
        $request  = $this->submittedFor($w);

        $this->assertSame(['社用車の購入 / 部門長 / 承認・差戻し'], $this->listFor($w['head']));
        $this->assertSame([], $this->listFor($w['reviewer']));

        $workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);
        $request->refresh();
        $this->assertSame([], $this->listFor($w['head']));
        $this->assertSame(['社用車の購入 / 審査 / 意見'], $this->listFor($w['reviewer']));

        $workflow->judgeReview($request, $w['reviewer'], $request->lock_version, ApprovalStepResult::Ok, null);
        $request->refresh();
        $this->assertSame(['社用車の購入 / 社長 / 決裁'], $this->listFor($w['president']));

        $workflow->judgePresident($request, $w['president'], $request->lock_version, ApprovalStepResult::Conditional, '2 社から見積りを取ること');
        $this->assertSame([], $this->listFor($w['president']));
        $this->assertSame(['社用車の購入 / 申請者 / 条件の確認'], $this->listFor($w['applicant']));
    }

    public function test_a_returned_request_waits_for_the_applicant(): void
    {
        $w       = $this->approvalWorld();
        $request = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, '見積りを付けてください');

        $this->assertSame(['社用車の購入 / 申請者 / 差戻しの対応'], $this->listFor($w['applicant']));
        $this->assertSame([], $this->listFor($w['head']));
    }

    /** 自分の申請は、部門長・審査・社長としての対応待ちに出さない（判断できないため。D16） */
    public function test_your_own_request_is_not_your_judging_work(): void
    {
        $w = $this->approvalWorld();
        $this->submittedFor($w);
        $w['dept']->update(['head_user_id' => $w['applicant']->id]);

        $this->assertSame([], $this->listFor($w['applicant']));
    }

    /** 付け替えた部門長の段階は、付け替え先の人の対応待ち（2b の付け替えで入る。部門長ではない） */
    public function test_an_assigned_head_step_goes_to_the_assignee(): void
    {
        $w       = $this->approvalWorld();
        $request = $this->submittedFor($w);
        $deputy  = $this->baseUser(['name' => '代理 部長']);
        ApprovalStep::where('request_id', $request->id)->where('kind', 'head')->update(['assignee_user_id' => $deputy->id]);

        $this->assertSame(['社用車の購入 / 部門長 / 承認・差戻し'], $this->listFor($deputy));
        $this->assertSame([], $this->listFor($w['head']));
    }

    /** 古い順に並ぶ */
    public function test_the_oldest_comes_first(): void
    {
        $w = $this->approvalWorld();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00', 'Asia/Tokyo')->utc());
        $this->submittedFor($w, ['subject' => '先に出した申請']);
        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00', 'Asia/Tokyo')->utc());
        $this->submittedFor($w, ['subject' => '後に出した申請']);

        $this->assertSame(
            ['先に出した申請', '後に出した申請'],
            PendingWork::for($w['head'])->map(fn (array $item) => $item['request']->subject)->all()
        );
    }

    /** 待ち日数は日本の暦で数える（日本時間の 0:00〜8:59 に 1 日ずれない。D20・Bug #61） */
    public function test_waiting_days_count_japanese_calendar_days(): void
    {
        // 日本時間 9/25 23:30 に届いた（UTC では 9/25 14:30）
        $arrived = CarbonImmutable::parse('2026-09-25 23:30', 'Asia/Tokyo')->utc();

        $this->travelTo(CarbonImmutable::parse('2026-09-25 23:59', 'Asia/Tokyo')->utc());
        $this->assertSame(0, PendingWork::waitingDays($arrived));

        // 日本時間 9/26 0:30（UTC ではまだ 9/25）→ 1 日
        $this->travelTo(CarbonImmutable::parse('2026-09-26 00:30', 'Asia/Tokyo')->utc());
        $this->assertSame(1, PendingWork::waitingDays($arrived));

        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:00', 'Asia/Tokyo')->utc());
        $this->assertSame(7, PendingWork::waitingDays($arrived));

        // 日本時間 9/26 8:00 に届き（UTC では 9/25 23:00）、同じ日の 10:00 に見る → 0 日
        $morning = CarbonImmutable::parse('2026-09-26 08:00', 'Asia/Tokyo')->utc();
        $this->travelTo(CarbonImmutable::parse('2026-09-26 10:00', 'Asia/Tokyo')->utc());
        $this->assertSame(0, PendingWork::waitingDays($morning));

        $this->assertSame(0, PendingWork::waitingDays(null));
    }
}
```

`tests/Feature/Approval/Phase2/HomeAndListTest.php`

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Support\Approval\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 使い始めたあとのホーム（画面①）と自分の申請一覧（画面④）（設計書 §5.12） */
class HomeAndListTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    /** 状態と番号を直接入れた、終わった申請（ここで見るのは画面の並びだけ） */
    private function finished(array $w, string $subject, string $status, ?string $number): ApprovalRequest
    {
        $request = $this->draftFor($w, ['subject' => $subject]);
        DB::table('approval_requests')->where('id', $request->id)->update([
            'status' => $status, 'number' => $number, 'round' => 1, 'status_changed_at' => now(),
            'decision' => $status === 'approved' ? 'approve' : ($status === 'rejected' ? 'reject' : null),
        ]);

        return $request->refresh();
    }

    public function test_before_launch_the_home_is_still_the_placeholder(): void
    {
        $w = $this->approvalWorld();

        $this->actingAs($w['applicant'])->get(route('approvals.home'))->assertOk()
            ->assertSee('決裁の機能は準備中です')
            ->assertDontSee('対応待ち');
    }

    /** 部門長のホームに、自分の番の申請が待ち日数つきで出る（D20） */
    public function test_the_head_sees_what_waits_for_them(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00', 'Asia/Tokyo')->utc());
        $request = $this->submittedFor($w);
        $this->travelTo(CarbonImmutable::parse('2026-09-26 09:00', 'Asia/Tokyo')->utc());

        $this->actingAs($w['head'])->get(route('approvals.home'))->assertOk()
            ->assertSeeInOrder(['対応待ち', '1 件', '部門長・承認・差戻し', '社用車の購入', '申請 花子・住宅事業部', '2 日待ち'])
            ->assertSee('href="' . route('approvals.requests.show', $request) . '"', false);
    }

    /** 申請者のホームには、自分の申請の進み具合（いま誰の番か）と最近の完了が出る */
    public function test_the_applicant_sees_the_progress_of_their_requests(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->submittedFor($w, ['subject' => '回覧中の申請']);
        $this->finished($w, '終わった申請', 'approved', 'R8-J-001');

        $this->actingAs($w['applicant'])->get(route('approvals.home'))->assertOk()
            ->assertSee('いま対応が必要な申請はありません。')
            ->assertSeeInOrder(['自分の申請の進み具合', '回覧中の申請', 'いま: 部門長（部門 長）', '最近の完了', 'R8-J-001', '終わった申請']);
    }

    /** 一覧は自分の申請だけ・新しい順（他人の申請は出さない） */
    public function test_the_list_shows_only_my_requests_newest_first(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->draftFor($w, ['subject' => '社用車の購入']);
        $this->submittedFor($w, ['subject' => '事務所の改装']);
        $other = $this->approvalOnlyUser(['name' => '別 申請者']);
        $other->approvalDepartments()->attach($w['dept']->id);
        $this->draftFor(['applicant' => $other] + $w, ['subject' => '他人の申請']);

        $this->actingAs($w['applicant'])->get(route('approvals.requests.index'))->assertOk()
            ->assertSeeInOrder(['事務所の改装', '社用車の購入'])
            ->assertSee('部門長（部門 長）')
            ->assertDontSee('他人の申請');
    }

    public function test_the_filters_narrow_the_list(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->draftFor($w, ['subject' => '下書きの申請']);
        $this->submittedFor($w, ['subject' => '回覧中の申請']);
        $returned = $this->submittedFor($w, ['subject' => '差戻しの申請']);
        app(Workflow::class)->judgeHead($returned, $w['head'], $returned->lock_version, ApprovalStepResult::Return, '直してください');
        $this->finished($w, '取り下げた申請', 'withdrawn', null);
        $this->finished($w, '決裁済みの申請', 'approved', 'R8-J-001');

        $expected = [
            'draft'     => '下書きの申請',
            'progress'  => '回覧中の申請',
            'returned'  => '差戻しの申請',
            'done'      => '決裁済みの申請',
            'withdrawn' => '取り下げた申請',
        ];

        foreach ($expected as $filter => $subject) {
            $response = $this->actingAs($w['applicant'])->get(route('approvals.requests.index', ['filter' => $filter]))->assertOk();
            $this->assertSame([$subject], $response->viewData('requests')->pluck('subject')->all(), "絞り込み {$filter}");
        }

        // 知らない絞り込みは「すべて」
        $this->assertCount(5, $this->actingAs($w['applicant'])->get(route('approvals.requests.index', ['filter' => 'bogus']))->viewData('requests'));
    }

    public function test_the_list_pages_by_twenty(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        for ($i = 1; $i <= 21; $i++) {
            $this->draftFor($w, ['subject' => "下書き{$i}"]);
        }

        $this->assertCount(20, $this->actingAs($w['applicant'])->get(route('approvals.requests.index'))->viewData('requests'));
        $this->assertSame(['下書き1'], $this->actingAs($w['applicant'])->get(route('approvals.requests.index', ['page' => 2]))->viewData('requests')->pluck('subject')->all());
    }
}
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'PendingWorkTest|HomeAndListTest'
```

Expected: FAIL（`Class "App\Support\Approval\PendingWork" not found`・`Route [approvals.requests.index] not defined.`）

- [ ] **Step 3: 対応待ちの部品を書く**

`app/Support/Approval/PendingWork.php`

```php
<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\JapanTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 自分の対応待ち（ホーム①・設計書 §5.12）。2b のメニューの件数も同じものを数える。
 *
 * ⚠ 自分の申請は、部門長・審査・社長としての対応待ちに出さない（判断できないため。D16）。
 */
final class PendingWork
{
    /**
     * @return Collection<int, array{request: ApprovalRequest, role: string, action: string, since: ?DateTimeInterface}>
     */
    public static function for(User $user): Collection
    {
        $headDeptIds   = ApprovalDepartment::where('head_user_id', $user->id)->pluck('id');
        $reviewDeptIds = DB::table('approval_reviewers')->where('user_id', $user->id)->pluck('department_id');
        $isPresident   = $user->isApprovalPresident();

        $steps = ApprovalStep::with(['request.applicant', 'request.department', 'request.type'])
            ->where('status', ApprovalStepStatus::Waiting->value)
            ->whereHas('request', fn ($q) => $q->where('user_id', '!=', $user->id))
            ->where(function ($q) use ($user, $headDeptIds, $reviewDeptIds, $isPresident): void {
                $q->where(function ($q) use ($user, $headDeptIds): void {
                    $q->where('kind', ApprovalStepKind::Head->value)
                      ->where(function ($q) use ($user, $headDeptIds): void {
                          $q->where('assignee_user_id', $user->id)
                            ->orWhere(fn ($q) => $q->whereNull('assignee_user_id')->whereIn('department_id', $headDeptIds));
                      });
                })->orWhere(fn ($q) => $q->where('kind', ApprovalStepKind::Review->value)->whereIn('department_id', $reviewDeptIds));

                if ($isPresident) {
                    $q->orWhere('kind', ApprovalStepKind::President->value);
                }
            })
            ->get();

        $items = $steps->map(fn (ApprovalStep $step) => [
            'request' => $step->request,
            'role'    => $step->kind->label(),
            'action'  => match ($step->kind) {
                ApprovalStepKind::Head      => '承認・差戻し',
                ApprovalStepKind::Review    => '意見',
                ApprovalStepKind::President => '決裁',
            },
            'since'   => $step->arrived_at,
        ]);

        $own = ApprovalRequest::with(['department', 'type', 'applicant'])
            ->where('user_id', $user->id)
            ->whereIn('status', [ApprovalStatus::Returned->value, ApprovalStatus::Condition->value])
            ->get()
            ->map(fn (ApprovalRequest $request) => [
                'request' => $request,
                'role'    => '申請者',
                'action'  => $request->status === ApprovalStatus::Returned ? '差戻しの対応' : '条件の確認',
                'since'   => $request->status_changed_at,
            ]);

        return $items->concat($own)
            ->sortBy(fn (array $item) => $item['since']?->getTimestamp() ?? PHP_INT_MAX)
            ->values();
    }

    /** 待ち日数（自分の番が来た日から数えた暦の日数・日本時間。D20） */
    public static function waitingDays(?DateTimeInterface $since): int
    {
        if ($since === null) {
            return 0;
        }

        // ⚠ 日本の暦の日付どうしで数える（UTC のままだと日本時間の 0:00〜8:59 に 1 日ずれる。Bug #61）。
        // ⚠ `CarbonImmutable::parse(…)->…` の形は走査（StoredTimestampDisplayScanTest）に掛かるので使わない
        $from  = CarbonImmutable::instance($since)->setTimezone(JapanTime::ZONE)->startOfDay();
        $today = CarbonImmutable::createFromFormat('!Y-m-d', JapanTime::today()->toDateString(), JapanTime::ZONE);

        return max(0, (int) round($from->diffInDays($today)));
    }
}
```

- [ ] **Step 4: ホームを書き換える**

`app/Http/Controllers/Approval/HomeController.php` を丸ごと次にする:

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Support\Approval\PendingWork;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 決裁のホーム（画面①・段階2 設計書 §5.12）。
 *
 * ⚠ 使い始める前（approval_settings.launched_at が空）は段階1 の「準備中」の画面のまま（§5.2・D1）。
 *   申請を回す画面の門番 `approval.launched` も、準備中はここへ送る。
 */
class HomeController extends Controller
{
    /** 「最近の完了」に出す数 */
    private const RECENT_FINISHED = 5;

    public function index(Request $request): View
    {
        $user = $request->user();

        if (! ApprovalSetting::current()->isLaunched()) {
            return view('approvals.home', [
                'user'    => $user,
                'loginId' => $user->employee_number ?? $user->email,
            ]);
        }

        // いま誰の番かを出すので、段階の担当（部門長・審査担当者・付け替え）を先に読む（CurrentHandler）
        $withHandler = ['type', 'steps.department.head', 'steps.department.reviewers', 'steps.assignee'];

        return view('approvals.home-launched', [
            'user'             => $user,
            'pending'          => PendingWork::for($user),
            // 自分の申請の進み具合（回覧中・差戻し中・条件確認待ち）と、最近の完了（§5.12）
            'inProgress'       => ApprovalRequest::with($withHandler)
                ->where('user_id', $user->id)
                ->whereIn('status', array_map(fn (ApprovalStatus $s) => $s->value, [
                    ApprovalStatus::HeadReview, ApprovalStatus::Review, ApprovalStatus::President,
                    ApprovalStatus::Returned, ApprovalStatus::Condition,
                ]))
                ->orderByDesc('status_changed_at')
                ->get(),
            'recentlyFinished' => ApprovalRequest::with('type')
                ->where('user_id', $user->id)
                ->whereIn('status', array_map(fn (ApprovalStatus $s) => $s->value, [
                    ApprovalStatus::Approved, ApprovalStatus::Rejected, ApprovalStatus::Withdrawn,
                ]))
                ->orderByDesc('status_changed_at')
                ->limit(self::RECENT_FINISHED)
                ->get(),
        ]);
    }
}
```

`resources/views/approvals/home-launched.blade.php`

```blade
@extends('layouts.app')

@section('title', '決裁申請')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">決裁申請</span>
@endsection

@section('content')
<div class="max-w-[960px]">

    @if(session('warning'))
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-800">
            {{ session('warning') }}
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">決裁申請</h1>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('approvals.requests.create') }}" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold">新しい申請</a>
            <a href="{{ route('approvals.requests.index') }}" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-700 hover:bg-gray-50">自分の申請</a>
        </div>
    </div>

    {{-- 対応待ち（古い順・待ち日数つき。自分の申請は部門長・審査・社長としては出さない。§5.12・D16・D20） --}}
    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">
            対応待ち <span class="ml-1 text-[12px] font-semibold text-emerald-700">{{ $pending->count() }} 件</span>
        </h2>
        @if($pending->isEmpty())
            <p class="px-5 py-6 text-[13px] text-gray-400">いま対応が必要な申請はありません。</p>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach($pending as $item)
                    @php $days = \App\Support\Approval\PendingWork::waitingDays($item['since']); @endphp
                    <li>
                        <a href="{{ route('approvals.requests.show', $item['request']) }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 hover:bg-gray-50">
                            <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="background: #dbeafe; color: #1e40af;">{{ $item['role'] }}・{{ $item['action'] }}</span>
                            <span class="text-[13px] font-semibold text-gray-900 break-words">{{ $item['request']->subject ?? '（件名なし）' }}</span>
                            <span class="text-[12px] text-gray-500">{{ $item['request']->applicant->name }}・{{ $item['request']->department?->name ?? '—' }}</span>
                            <span class="ml-auto text-[12px] text-gray-600 whitespace-nowrap">{{ $days === 0 ? '今日' : $days . ' 日待ち' }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- 自分の申請の進み具合（§5.12） --}}
    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">自分の申請の進み具合</h2>
        @if($inProgress->isEmpty() && $recentlyFinished->isEmpty())
            <p class="px-5 py-6 text-[13px] text-gray-400">まだ申請はありません。「新しい申請」から始めます。</p>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach($inProgress as $item)
                    <li>
                        <a href="{{ route('approvals.requests.show', $item) }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 hover:bg-gray-50">
                            <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $item->status->badgeStyle() }}">{{ $item->statusLabel() }}</span>
                            <span class="text-[13px] font-semibold text-gray-900 break-words">{{ $item->subject ?? '（件名なし）' }}</span>
                            <span class="ml-auto text-[12px] text-gray-600">いま: {{ \App\Support\Approval\CurrentHandler::label($item) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
            @if($recentlyFinished->isNotEmpty())
                <p class="px-5 pt-3 pb-1 text-[12px] font-semibold text-gray-500 border-t border-gray-100">最近の完了</p>
                <ul class="divide-y divide-gray-100">
                    @foreach($recentlyFinished as $item)
                        <li>
                            <a href="{{ route('approvals.requests.show', $item) }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-2.5 hover:bg-gray-50">
                                <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $item->status->badgeStyle() }}">{{ $item->statusLabel() }}</span>
                                <span class="text-[12px] font-mono text-gray-600">{{ $item->number }}</span>
                                <span class="text-[13px] text-gray-900 break-words">{{ $item->subject ?? '（件名なし）' }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
        <div class="px-5 py-3 border-t border-gray-100 text-right">
            <a href="{{ route('approvals.requests.index') }}" class="text-[13px] text-emerald-600 hover:underline">自分の申請をすべて見る</a>
        </div>
    </section>

    <div class="flex flex-wrap gap-3 text-[13px]">
        <a href="{{ route('password.change') }}" class="text-emerald-600 hover:underline">パスワードを変更する</a>
        @if($user->isApprovalAdmin())
            <a href="{{ route('approvals.admin.users.index') }}" class="text-emerald-600 hover:underline">利用者の管理</a>
            <a href="{{ route('approvals.admin.organization.index') }}" class="text-emerald-600 hover:underline">部門の管理</a>
            <a href="{{ route('approvals.admin.types.index') }}" class="text-emerald-600 hover:underline">申請種類の管理</a>
        @endif
    </div>
</div>
@endsection
```

- [ ] **Step 5: 自分の申請一覧を書く**

`app/Http/Controllers/Approval/RequestController.php` のクラスの先頭（`__construct` の前）に定数を、`create()` の前に `index()` を足す:

```php
    /** 自分の申請一覧の 1 ページの件数（§5.12） */
    private const PER_PAGE = 20;
```

```php
    /** 自分の申請一覧（画面④。新しい順。絞り込みは ApprovalStatus::listFilters()。設計書 §5.12） */
    public function index(Request $request): View
    {
        $filters = ApprovalStatus::listFilters();
        $filter  = $request->query('filter');
        $filter  = is_string($filter) && isset($filters[$filter]) ? $filter : null;

        // いま誰の番かを出すので、段階の担当を先に読む（CurrentHandler）
        $requests = ApprovalRequest::with(['type', 'steps.department.head', 'steps.department.reviewers', 'steps.assignee'])
            ->where('user_id', $request->user()->id)
            ->when($filter !== null, fn ($query) => $query->whereIn(
                'status',
                array_map(fn (ApprovalStatus $status) => $status->value, $filters[$filter]['statuses'])
            ))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('approvals.requests.index', compact('requests', 'filters', 'filter'));
    }
```

`resources/views/approvals/requests/index.blade.php`

```blade
@extends('layouts.app')

@section('title', '自分の申請')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">自分の申請</span>
@endsection

@section('content')
<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h1 class="text-lg font-bold text-gray-900">自分の申請</h1>
        <a href="{{ route('approvals.requests.create') }}" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold">新しい申請</a>
    </div>

    {{-- 絞り込み（押すとすぐ切り替わる。§5.12） --}}
    <nav class="flex flex-wrap gap-1.5 mb-4" aria-label="絞り込み">
        <a href="{{ route('approvals.requests.index') }}" @if($filter === null) aria-current="page" @endif
           class="px-3 py-1.5 rounded-full text-[12px] font-semibold border {{ $filter === null ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">すべて</a>
        @foreach($filters as $key => $definition)
            <a href="{{ route('approvals.requests.index', ['filter' => $key]) }}" @if($filter === $key) aria-current="page" @endif
               class="px-3 py-1.5 rounded-full text-[12px] font-semibold border {{ $filter === $key ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">{{ $definition['label'] }}</a>
        @endforeach
    </nav>

    <div class="bg-white rounded-lg border border-gray-200">
        @if($requests->isEmpty())
            <p class="px-4 py-8 text-center text-[13px] text-gray-400">{{ $filter === null ? 'まだ申請はありません。' : '該当する申請はありません。' }}</p>
        @else
            {{-- スマホの幅では 1 件 1 枚のカード（要件 14.4。横スクロールにしない） --}}
            <ul class="md:hidden divide-y divide-gray-100">
                @foreach($requests as $item)
                    <li>
                        <a href="{{ route('approvals.requests.show', $item) }}" class="block px-4 py-3 hover:bg-gray-50">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $item->status->badgeStyle() }}">{{ $item->statusLabel() }}</span>
                                @if($item->number)
                                    <span class="text-[12px] font-mono text-gray-600">{{ $item->number }}</span>
                                @endif
                            </div>
                            <p class="text-[14px] font-semibold text-gray-900 break-words">{{ $item->subject ?? '（件名なし）' }}</p>
                            <p class="text-[12px] text-gray-500 mt-0.5">{{ $item->type?->name ?? '種類未選択' }}・提出日 {{ \App\Support\JapanTime::format($item->last_submitted_at, 'Y/m/d') ?? '—' }}</p>
                            <p class="text-[12px] text-gray-700 mt-0.5">いま: {{ \App\Support\Approval\CurrentHandler::label($item) }}</p>
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="hidden md:block">
                <div class="scroll-hint at-start">
                    <div class="scroll-hint-inner">
                <table class="w-full min-w-[760px] border-collapse">
                    <thead>
                        <tr>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">決裁No</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">件名</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">種類</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">状態</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">提出日</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">いま誰の番か</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($requests as $item)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-mono text-gray-700 whitespace-nowrap">{{ $item->number ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px]">
                                    <a href="{{ route('approvals.requests.show', $item) }}" class="text-emerald-600 hover:underline break-words">{{ $item->subject ?? '（件名なし）' }}</a>
                                </td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $item->type?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 whitespace-nowrap">
                                    <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $item->status->badgeStyle() }}">{{ $item->statusLabel() }}</span>
                                </td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ \App\Support\JapanTime::format($item->last_submitted_at, 'Y/m/d') ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ \App\Support\Approval\CurrentHandler::label($item) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                    </div>{{-- /scroll-hint-inner --}}
                    <div class="scroll-hint-text">← スクロールできます →</div>
                </div>{{-- /scroll-hint --}}
            </div>

            {{-- ページ送り（->links() は使わない。プロジェクト規約 / Bug #24） --}}
            @if($requests->hasPages())
                <div class="flex justify-center gap-0.5 py-3 border-t border-gray-200">
                    @if($requests->onFirstPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                    @else
                        <a href="{{ $requests->previousPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
                    @endif
                    @foreach($requests->getUrlRange(1, $requests->lastPage()) as $page => $url)
                        @if($page == $requests->currentPage())
                            <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                        @endif
                    @endforeach
                    @if($requests->hasMorePages())
                        <a href="{{ $requests->nextPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
                    @else
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                    @endif
                </div>
            @endif
        @endif
    </div>
</div>
@endsection
```

- [ ] **Step 6: ルートと分類を足す**

`routes/approval.php` の申請を回す画面のグループで、`requests.create` の**前**に足す:

```php
    Route::get('/requests', [RequestController::class, 'index'])->name('requests.index');
```

`tests/Feature/Approval/ApprovalAdminGateTest.php` の `OPEN_TO_EVERY_USER` の `'approvals.numbers.search' => …` の次に足す:

```php
        'approvals.requests.index'   => '自分の申請一覧（本人の申請だけ。段階2 設計書 §5.12）',
```

- [ ] **Step 7: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'PendingWorkTest|HomeAndListTest|ApprovalHomeTest|LaunchGateTest|ApprovalAdminGateTest|ApprovalOnlyLockoutTest|MobileLayoutTest|StoredTimestampDisplayScanTest|ClockReadScanTest'
```

Expected: PASS（段階1 の `ApprovalHomeTest`＝準備中のホームも緑のまま）

- [ ] **Step 8: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add app/Support/Approval/PendingWork.php app/Http/Controllers/Approval/HomeController.php app/Http/Controllers/Approval/RequestController.php resources/views/approvals/home-launched.blade.php resources/views/approvals/requests/index.blade.php routes/approval.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase2/PendingWorkTest.php tests/Feature/Approval/Phase2/HomeAndListTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
feat(approval): 使い始めたあとのホームと自分の申請一覧を足す

ホームに自分の対応待ち（古い順・日本の暦で数えた待ち日数）と、自分の申請の進み具合を出す。
自分の申請一覧は状態で絞り込み、20 件ずつ。スマホの幅では 1 件 1 枚のカードにする。
使い始める前のホームは準備中のまま。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 17: サイドバーの項目と、門番の数の下限（仕上げ）

**Files:**
- Modify: `resources/views/layouts/partials/sidebar_approval.blade.php`（新しい申請・自分の申請〈使い始めてから〉・申請種類の管理〈管理者〉）
- Modify: `resources/views/layouts/partials/sidebar.blade.php`（「決裁の管理」の 2 か所に申請種類の管理）
- Modify: `resources/views/approvals/home.blade.php`（準備中のホームの管理のリンクに申請種類の管理）
- Modify: `tests/Feature/Approval/ApprovalSidebarTest.php`（テスト 2 本）
- Modify: `tests/Feature/Approval/Phase2/LaunchGateTest.php`（`MIN_GATED`）・`tests/Feature/Approval/ApprovalAdminGateTest.php`（走査の下限）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/ApprovalSidebarTest.php` の `use` に `use App\Models\ApprovalSetting;` を足し、クラスの最後（`test_the_approval_sidebar_follows_the_cloak_rules()` の後）に足す:

```php

    /** 申請種類の管理は、使い始める前から決裁の管理者に出る（段階2 設計書 §5.2・§5.12） */
    public function test_the_type_management_link_is_offered_to_admins_before_launch(): void
    {
        // 決裁のみ利用者の管理者: 決裁のサイドバー
        $sidebars = $this->sidebars(
            $this->actingAs($this->approvalAdmin(UserRole::ApprovalOnly->value))->get(route('approvals.home'))->assertOk()->getContent()
        );
        foreach (['expanded', 'drawer'] as $key) {
            $this->assertHasLink($sidebars[$key], route('approvals.admin.types.index'), '申請種類の管理', $key);
        }

        // 基幹を使う管理者: 基幹のサイドバーの「決裁の管理」
        $sidebars = $this->sidebars($this->actingAs($this->approvalAdmin())->get('/dashboard/tenant')->assertOk()->getContent());
        foreach (['expanded', 'drawer'] as $key) {
            $this->assertHasLink($sidebars[$key], route('approvals.admin.types.index'), '申請種類の管理', $key);
        }

        // 管理者でない人には出さない（@if の外へ出す取り違えを止める）
        $plain = User::factory()->approvalOnly()->create(['must_change_password' => false]);
        foreach ($this->sidebars($this->actingAs($plain)->get(route('approvals.home'))->assertOk()->getContent()) as $key => $aside) {
            $this->assertStringNotContainsString(route('approvals.admin.types.index'), $aside, "{$key} に管理者でない人の申請種類の管理が出ている");
        }
    }

    /** 申請の画面へのリンクは、使い始めてから出す（準備中は誰にも見せない。段階2 設計書 D1） */
    public function test_the_request_links_appear_only_after_launch(): void
    {
        $user = User::factory()->approvalOnly()->create(['must_change_password' => false]);

        $before = $this->sidebars($this->actingAs($user)->get(route('approvals.home'))->assertOk()->getContent());
        foreach ($before as $key => $aside) {
            $this->assertStringNotContainsString(route('approvals.requests.index'), $aside, "{$key} に準備中の申請の画面へのリンクが出ている");
        }

        ApprovalSetting::current()->update(['launched_at' => now()]);

        $after = $this->sidebars($this->actingAs($user)->get(route('approvals.home'))->assertOk()->getContent());
        foreach (['expanded', 'drawer'] as $key) {
            $this->assertHasLink($after[$key], route('approvals.requests.create'), '新しい申請', $key);
            $this->assertHasLink($after[$key], route('approvals.requests.index'), '自分の申請', $key);
        }
        $this->assertStringContainsString('title="自分の申請"', $after['rail'], 'rail に自分の申請のアイコンリンクが無い');
    }
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter ApprovalSidebarTest
```

Expected: FAIL（「申請種類の管理」の行き先が無い・「新しい申請」の行き先が無い）

- [ ] **Step 3: 決裁のサイドバーに項目を足す**

`resources/views/layouts/partials/sidebar_approval.blade.php`

(a) 先頭の注意書きの 2 行目を次にする:

```blade
     ⚠ 中身は「決裁のホーム」、使い始めてから「新しい申請」「自分の申請」（段階2 設計書 §5.12）、
       決裁の管理者なら「利用者の管理」「部門の管理」「申請種類の管理」。
```

（元の `⚠ 中身は「決裁のホーム」と、決裁の管理者なら「利用者の管理」「部門の管理」だけ。` の 1 行を置き換える）

(b) `@php` の中を次にする:

```blade
@php
    $isApprovalAdmin = Auth::user()->isApprovalAdmin();
    // 申請を回す画面へのリンクは使い始めてから（準備中は誰にも見せない。段階2 設計書 §5.2・D1）
    $approvalsLaunched = \App\Models\ApprovalSetting::current()->isLaunched();
@endphp
```

(c) PC 用の展開サイドバーとモバイルのドロワーの**両方**で、`決裁のホーム` の `<x-sidebar-item …/>` から `@endif` までを次にする:

```blade
    <x-sidebar-item :href="route('approvals.home')" label="決裁のホーム" :active="request()->routeIs('approvals.home')" />
    @if($approvalsLaunched)
        <x-sidebar-item :href="route('approvals.requests.create')" label="新しい申請" :active="request()->routeIs('approvals.requests.create')" />
        <x-sidebar-item :href="route('approvals.requests.index')" label="自分の申請" :active="request()->routeIs('approvals.requests.index', 'approvals.requests.show', 'approvals.requests.edit')" />
    @endif
    @if($isApprovalAdmin)
        <x-sidebar-item :href="route('approvals.admin.users.index')" label="利用者の管理" :active="request()->routeIs('approvals.admin.users.*')" />
        <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
        <x-sidebar-item :href="route('approvals.admin.types.index')" label="申請種類の管理" :active="request()->routeIs('approvals.admin.types.*')" />
    @endif
```

（展開サイドバーは `<div class="mb-1">` の中なので、字下げを 1 段深くする）

(d) PC 用の折りたたみサイドバーで、`title="決裁のホーム"` の `<a>` の次に足す（管理のアイコンは `approvals.admin.*` で光るので種類の管理はそのまま含まれる）:

```blade
    @if($approvalsLaunched)
        <a href="{{ route('approvals.requests.index') }}" title="自分の申請" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->routeIs('approvals.requests.*') ? 'bg-emerald-50' : 'hover:bg-gray-100' }}">申</a>
    @endif
```

- [ ] **Step 4: 基幹のサイドバーとホームに種類の管理を足す**

`resources/views/layouts/partials/sidebar.blade.php` の「決裁の管理」の `<x-sidebar-group>` **2 か所**（展開版とドロワー）で、`部門の管理` の `<x-sidebar-item …/>` の次に足す:

```blade
            <x-sidebar-item :href="route('approvals.admin.types.index')" label="申請種類の管理" :active="request()->routeIs('approvals.admin.types.*')" />
```

（折りたたみ版のアイコン 1 本は `approvals.admin.*` で光るので変えない）

`resources/views/approvals/home.blade.php` の `部門の管理` のリンクの次に足す:

```blade
                <a href="{{ route('approvals.admin.types.index') }}" class="text-emerald-600 hover:underline">申請種類の管理</a>
```

- [ ] **Step 5: 門番の数の下限を実数に上げる**

`tests/Feature/Approval/Phase2/LaunchGateTest.php`:

```php
    /**
     * 門番の付いたルートの数の下限（空振りで緑にならないように）。
     * 2a の実数 16 本 = 候補の検索 1・申請書と詳細 7・添付 3・判断など 5（2b で増える）。
     */
    private const MIN_GATED = 16;
```

`tests/Feature/Approval/ApprovalAdminGateTest.php` の `test_every_approvals_route_is_classified()` の最後の 2 行を次にする:

```php
        // 走査が空振りして緑になる事故を防ぐ（段階2 の 2a で 39 本 = 決裁の管理 22 本 + ホーム 1 本 + 申請を回す画面 16 本）
        $this->assertGreaterThanOrEqual(39, $found, 'approvals. のルートの走査に失敗している');
```

- [ ] **Step 6: ルートの数を確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" php artisan route:list --name=approvals. --json | php -r '$r = json_decode(stream_get_contents(STDIN), true); $admin = count(array_filter($r, fn ($x) => str_starts_with($x["name"], "approvals.admin."))); echo "all=" . count($r) . " admin=" . $admin . PHP_EOL;'
```

Expected: `all=39 admin=22`（違えば、どの Task のルートが足りない・多いかを `route:list --name=approvals.` で見比べてから下限を直す）

- [ ] **Step 7: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit --filter 'ApprovalSidebarTest|LayoutSidebarCloakTest|LayoutSidebarDrawerTest|LaunchGateTest|ApprovalAdminGateTest|ApprovalHomeTest|HomeAndListTest'
```

Expected: PASS

- [ ] **Step 8: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add resources/views/layouts/partials/sidebar_approval.blade.php resources/views/layouts/partials/sidebar.blade.php resources/views/approvals/home.blade.php tests/Feature/Approval/ApprovalSidebarTest.php tests/Feature/Approval/Phase2/LaunchGateTest.php tests/Feature/Approval/ApprovalAdminGateTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
feat(approval): サイドバーに申請の画面と申請種類の管理を足す

新しい申請・自分の申請は使い始めてから出す（準備中は誰にも見せない）。申請種類の管理は
使い始める前から決裁の管理者に出す（決裁のサイドバーと基幹の「決裁の管理」の両方）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 18: 全件テストと変異テスト（Bug #44 の作法）

**Files:** なし（測るだけ。穴が見つかったらテストを足してコミットする）

- [ ] **Step 1: 全件が緑の状態から始める**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && git status --porcelain && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `git status --porcelain` が空・`OK (…)`。本数と assertions の数を記録する（以降の基準）。

各変異ごとに: ①`git status --porcelain` が空 → ②変異を当てる → ③`git diff --stat` が**非空**（当たったことの確認）→ ④全件を流す → ⑤`git restore --source=HEAD --staged --worktree <ファイル>` → ⑥`git status --porcelain` が空。

⚠ **赤/緑ではなく「落ちたテストの集合」と「落ちた理由の文言」まで突き合わせる。** 意図と別の機構が落としているなら（例: 変異で `now()` を足して `ClockReadScanTest` が落ちた）、その変異の測定は無効。当て方を変えて測り直す。

⚠ **カナリアを先に通す** — `resources/views/approvals/home-launched.blade.php` に `{{ $canaryUndefinedVariable }}` を足して `HomeAndListTest` が 500 で赤になることを確かめる（測定装置が worktree のコードを読んでいることの証明）。

- [ ] **Step 2: 変異を当てて表を埋める**

| # | 変異 | 期待して落ちるテスト |
|---|---|---|
| M01 | `EnsureApprovalLaunched::handle()` の先頭を `return $next($request);` にする（門番を素通し） | `LaunchGateTest` の準備中の 3 本 ＋ `RelatedNumberSearchTest::test_before_launch_the_search_is_not_found` |
| M02 | 同じく、画面を開く GET の分岐を `abort(404)` に | `LaunchGateTest::test_before_launch_a_page_goes_to_the_home` |
| M03 | `routes/approval.php` のグループから `'approval.launched'` を外す | `LaunchGateTest::test_every_approval_route_is_classified`（`approval.launched が無い（準備中に誰でも開ける）`）|
| M04 | `bootstrap/app.php` の `EnsureApprovalLaunched` の `appendToPriorityList` を消す | `LaunchGateTest::test_the_gate_runs_after_the_admin_gate_and_before_bindings` ＋ `..._a_page_goes_to_the_home`（存在しない ID が 404 になる）|
| M05 | `sidebar_approval` の `$approvalsLaunched` を `true` 固定 | `ApprovalSidebarTest::test_the_request_links_appear_only_after_launch` |
| M06 | `RequestVisibility::apply()` の「ほかの人は下書きを見られない」（`status != draft`）を外す | `RequestVisibilityTest` ＋ `RequestFormTest::test_someone_elses_draft_is_not_found_even_for_admins` ＋ `RequestAttachmentTest::test_someone_who_cannot_see_…` |
| M07 | 同じく部門長の行を消す | `RequestVisibilityTest` ＋ `RequestActionTest::test_the_head_approves_…`（404）|
| M08 | 審査担当者の行の `whereNotNull('approval_steps.arrived_at')` を外す（届く前から見える） | `RequestVisibilityTest`（D19）＋ `RequestActionTest::test_the_whole_route_ends_with_a_number`（届く前に 200）|
| M09 | 社長の行の `whereNotNull('approval_steps.arrived_at')` を外す | `RequestVisibilityTest` |
| M10 | 判断した人の行（`actor_user_id`）を消す | `RequestVisibilityTest`（担当を外れたあと見られない）|
| M11 | 全件閲覧者・管理者の行を `canViewAllApprovals()` だけに | `RequestVisibilityTest`（管理者）|
| M12 | `RequestAttachmentController::show()` の `canView` を外す | `RequestAttachmentTest::test_someone_who_cannot_see_the_request_cannot_open_its_files` |
| M13 | `RelatedNumberController` の `RequestVisibility::apply()` を外す（素の `ApprovalRequest::query()`） | `RelatedNumberSearchTest::test_it_returns_only_numbered_requests_the_user_can_see` |
| M14 | `RequestController::relatedLinks()` の `RequestVisibility::apply()` を外す | `RequestFormTest::test_the_detail_shows_the_content_and_links_related_requests`（見られない R8-K-001 がリンクになる）|
| M15 | `RequestPermissions::judgeableStep()` の `! $this->isApplicant()` を外す（D16） | `WorkflowTest::test_nobody_judges_their_own_request` ＋ `..._a_reviewer_who_applied_…` ＋ `RequestActionTest::test_nobody_judges_their_own_request` |
| M16 | `PendingWork::for()` の `whereHas('request', …user_id != …)` を外す | `PendingWorkTest::test_your_own_request_is_not_your_judging_work` |
| M17 | `SubmitChecker` の社長の断り（D4）を消す | `SubmitCheckerTest` の社長のケース |
| M18 | `SubmitChecker::hasOtherReviewer()` の `users.id != 申請者` を外す（4.3 のケース 4） | `SubmitCheckerTest` の審査担当者が本人だけのケース |
| M19 | `SubmitChecker` の「見出しのまま」（D12）を消す | `SubmitCheckerTest` ＋ `RequestFormTest::test_a_refused_submission_keeps_the_draft` |
| M20 | 停止した種類の断りから `status === Draft` を外す（差戻し中も断る） | `RequestFormTest::test_a_returned_request_keeps_its_stopped_type_and_can_be_resubmitted` ＋ `SubmitCheckerTest`（D10）|
| M21 | `RequestPermissions::canEdit()` を `isApplicant()` だけに | `RequestFormTest::test_a_request_in_circulation_cannot_be_edited` ＋ `RequestAttachmentTest` の 2 本（回覧中に足せる・外せる）|
| M22 | `RequestController::validated()` の `Rule::in($typeIds)` を外す | `RequestFormTest::test_the_shape_of_inputs_is_checked_even_for_a_draft` |
| M23 | 同じく `->push($current?->type_id)` を外す（停止した種類の差戻しを保存できない） | `RequestFormTest::test_a_returned_request_keeps_its_stopped_type_…`（`type_id.in`）|
| M24 | `RequestController::create()` の `where('user_id', …)` を外す（他人の申請を写せる） | `RequestFormTest::test_only_your_own_request_can_be_copied` |
| M25 | `copiedFields()` で停止した種類をそのまま写す | `RequestFormTest::test_copying_a_request_prefills_a_new_form` |
| M26 | `form.blade.php` の確認の小窓の `value="submit"` を `value="save"` に | `RequestFormTest::test_the_create_page_offers_…`（`name="intent" value="submit"` が無い）|
| M27 | `form.blade.php` の候補の検索の fetch から `X-Requested-With` を消す | `RequestFormTest::test_the_create_page_offers_…` |
| M28 | `Workflow::move()` の `->where('lock_version', $lockVersion)` を外す | `WorkflowTest::test_a_simultaneous_second_press_is_refused_by_the_conditional_update` **だけ**（1 段目の比較が残るので、ほかは緑のはず）|
| M29 | `Workflow::judge()` の `assertFresh()` を消す | `WorkflowTest::test_a_stale_screen_is_refused` ＋ `RequestActionTest::test_a_stale_screen_is_refused`（2 段目の UPDATE が拾うので、文言が同じでも落ち方を見る）|
| M30 | `_actions` の取り下げのフォームから `lock_version` の hidden を消す | `RequestActionTest::test_the_applicant_can_withdraw`（「すでに処理されています」）|
| M31 | `_actions` の判断の確定ボタンの `value` を入れ替える（approve ↔ return） | `RequestActionTest::test_the_head_approves_through_the_rendered_form`（値と `x-show` の対）|
| M32 | `_actions` の判断のフォームの送り先を `requests.review` に | 同上（`action="…head-review"` のフォームが見つからない）|
| M33 | `ApprovalNumber::issue()` で `next_number` を進めない | `ApprovalNumberTest` ＋ `WorkflowTest` の 2 件目の採番 |
| M34 | `ApprovalFiscalYear::of()` の `>=` を `>` に | `ApprovalFiscalYearTest` の期の境目 |
| M35 | `ApprovalNumber::setNext()` の `<=` を `<` に（使った番号と同じ数を許す） | `ApprovalNumberTest`（D9）|
| M36 | `Workflow::afterPresident()` の `if ($request->number === null)` を外す（いつも新しく採る） | `WorkflowTest::test_an_existing_number_is_reused`（D21）|
| M37 | `AppendOnly` の `updating` の守りを消す | `Phase2ModelsTest::test_append_only_records_cannot_be_updated_or_deleted` |
| M38 | `RequestAttachmentController::show()` の `X-Content-Type-Options` を消す | `RequestAttachmentTest::test_files_open_inline_or_download_with_safe_headers` |
| M39 | 同じく `opensInline()` を `true` 固定（HEIC もブラウザで開く） | 同上 |
| M40 | 同じく `if ($approvalRequest->round >= 1)` を外す（下書きでも記録する） | `RequestAttachmentTest::test_opening_is_logged_once_the_request_was_submitted` |
| M41 | `destroy()` を `round` によらず行ごと消す | `RequestAttachmentTest::test_removing_from_a_returned_request_keeps_the_record` |
| M42 | `store()` の保存名を元のファイル名に（`storeAs(…, $originalName, …)`） | `RequestAttachmentTest::test_the_same_name_never_overwrites` |
| M43 | `store()` の 20 ファイルの確かめを消す | `RequestAttachmentTest::test_twenty_files_is_the_limit` |
| M44 | `PendingWork::waitingDays()` の `$from` の `->setTimezone(JapanTime::ZONE)` を外す | `PendingWorkTest::test_waiting_days_count_japanese_calendar_days`（朝 8 時台に届いた申請が「1 日」になる）|
| M45 | `RequestController::index()` の `where('user_id', …)` を外す | `HomeAndListTest::test_the_list_shows_only_my_requests_newest_first` |
| M46 | `sidebar_approval` の「申請種類の管理」を `@if($isApprovalAdmin)` の外へ出す | `ApprovalSidebarTest::test_the_type_management_link_is_offered_to_admins_before_launch`（管理者でない人に出る）|
| M47 | `TypeController::destroy()` の申請の件数の確かめを消す | `TypeManagementTest::test_a_type_with_requests_cannot_be_deleted`（外部キーで 500 になるなら、理由の文言が違う＝測り直す）|
| M48 | `OrganizationController` の部門長の交代で `Workflow::headChanged()` を呼ばない | `OrganizationPhase2Test` の交代のテスト（D23）|
| M49 | `Admin\UserController` の 12.6 の歯止め（無効化）を外す | `AssignmentGuardTest` |

⚠ **等価変異として記録するもの**（緑が正しい。「守られていない」と読み違えないこと）:
- `RequestPermissions::canDelete()` の `round === 0`、`RequestController::destroy()` の `where('round', 0)`・`where('status', draft)`: 2a の流れでは「下書きで round ≥ 1」も「確かめた直後に別のタブで提出」も作れない（二重の守り）
- `ApprovalNumber::lockedRow()` の `lockForUpdate()`: SQLite のテストは 1 本の接続なので、行のロックの有無が結果に出ない（同時の採番は MySQL でしか起きない）
- `DB::transaction(…)` を外す変異: 正常系では結果が同じ（Bug #48 の型）

- [ ] **Step 3: 検出できなかった変異にテストを足す**

⚠ **表を終えた時点で漏れが 0 件なら、測り方を疑う。** 同じ行の隣の不変条件（例: 取り下げのコメントの 2,000 文字・条件確認の `lock_version`）にも当ててみる。

- [ ] **Step 4: 結果をこの計画に追記してコミット**

検出 / 当初検出漏れ→追加で検出 / 等価変異 を区別して、この計画の末尾に「Task 18 の実測記録」として書き足す。

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add docs/superpowers/plans/2026-09-26-approval-phase2a.md tests/
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
test(approval): 段階2a の変異テストの結果を記録し、見つかった穴を塞ぐ

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 19: 手元のブラウザでの確認と、利用者に見せる画面の写真（設計書 §6・D1）

**Files:** なし（測るだけ）

テストが原理的に測れない領域（JS の動き・見た目・スマホの幅）を見る。**本番では申請を回す画面を誰にも見せないので、利用者に見てもらうのはここで撮る写真**（D1）。使い捨ての SQLite ＋ `artisan serve`。ブラウザは Playwright（利用者の決まり）で開く。

- [ ] **Step 1: 使い捨ての環境を作る**

⚠ Bash の呼び出しごとにシェルが新しくなるので、使い捨ての設定は 1 つのファイルにまとめ、以降のコマンドの先頭で `source` する。置き場所はその会話の scratchpad（以下 `<scratchpad>`）。⚠ worktree に `.env` を作らない。

```bash
LOCAL=<scratchpad>/approval-phase2a-local.sh
cat > "$LOCAL" <<SH
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
export DB_CONNECTION=sqlite
export DB_DATABASE="<scratchpad>/approval-phase2a.sqlite"
export TRIAL_PASSWORD="$(php -r 'echo bin2hex(random_bytes(6));')"
SH
source "$LOCAL" && touch "$DB_DATABASE" && cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && php artisan migrate --force && /Users/masanori/site/manage/node_modules/.bin/vite build
```

⚠ `vite build` は main repo の `node_modules` を cwd=worktree で使う（worktree に `node_modules` は無い）。できた `public/build` がコミットの対象に出ないことを `git status` で確かめる。

- [ ] **Step 2: 試しのデータを入れて、画面を出す**

`php artisan tinker --execute` で入れる（`database/seeders` は作らない。⚠ ログイン用の使い捨てルートを作らない）。パスワードは Step 1 で作った試しの値を全員に使い、チャットに書かない。⚠ `User` の `role` と `status` は `$fillable` に無いので `forceCreate` で入れる:

```bash
source <scratchpad>/approval-phase2a-local.sh && cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && php artisan tinker --execute='
$pw = getenv("TRIAL_PASSWORD");
$mk = fn (array $a) => App\Models\User::forceCreate($a + ["password" => $pw, "must_change_password" => false, "status" => "active"]);
$company   = App\Models\ApprovalCompany::create(["name" => "ミツワ都市開発", "fiscal_start_month" => 5, "sort_order" => 1]);
$head      = $mk(["name" => "住宅 部長", "email" => "head@example.test", "role" => "staff"]);
$reviewer  = $mk(["name" => "総務 審査", "email" => "reviewer@example.test", "role" => "staff"]);
$president = $mk(["name" => "社長 太郎", "email" => "president@example.test", "role" => "executive"]);
$applicant = $mk(["name" => "申請 花子", "employee_number" => "A1001", "role" => "approval_only"]);
$admin     = $mk(["name" => "決裁 管理者", "email" => "admin@example.test", "role" => "staff"]);
App\Models\ApprovalMember::create(["user_id" => $admin->id, "is_admin" => true]);
$j = App\Models\ApprovalDepartment::create(["company_id" => $company->id, "name" => "住宅事業部", "short_name" => "住宅", "code" => "J", "sort_order" => 1, "head_user_id" => $head->id]);
$s = App\Models\ApprovalDepartment::create(["company_id" => $company->id, "name" => "総務部", "short_name" => "総務", "code" => "S", "sort_order" => 2]);
$s->reviewers()->attach($reviewer->id);
$applicant->approvalDepartments()->attach($j->id);
App\Models\ApprovalType::create(["name" => "購入・発注", "headings" => App\Support\Approval\BodyTemplate::DEFAULT, "review_department_id" => $s->id, "sort_order" => 1, "is_active" => true]);
App\Models\ApprovalSetting::current()->update(["president_user_id" => $president->id, "launched_at" => now()]);
echo "ok", PHP_EOL;
'
```

画面を出す（**バックグラウンドで**。止めるまで動き続ける）:

```bash
source <scratchpad>/approval-phase2a-local.sh && cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && php artisan serve --port=8765
```

ログインは `http://127.0.0.1:8765/login` の画面から、社員番号 `A1001`（申請者）や各メールアドレスと、Step 1 の試しのパスワード（`source` したシェルの `$TRIAL_PASSWORD`）で行う。

- [ ] **Step 3: 見ること**（1440px と 375px の両方）

| # | 画面 | 見ること |
|---|---|---|
| 1 | 申請者: ホーム | 「新しい申請」「自分の申請」・対応待ちが空・サイドバー 3 か所に「新しい申請」「自分の申請」 |
| 2 | 申請書 | 種類を選ぶと本文に見出しが入る。本文を書いてから種類を選び直すと「入れ替えますか？」が出る |
| 3 | 申請書 | 関連する決裁No に `ｒ８－ｊ` と打つと候補が出ない（まだ番号が無い）／ `R7-J-015` を追加できる ／ `R8-J-1` は形の誤りが出る ／ Enter で追加され、フォームは送られない（IME の確定の Enter でも追加されない。Bug #6）|
| 4 | 申請書 | 「下書きを保存」で編集の画面へ移り、添付の欄が出る |
| 5 | 添付 | 3 ファイルをまとめて選ぶと「1 / 3：…を送っています…」と進み、一覧に並ぶ ／ 11MB のファイルは送る前に断られる ／ PDF はブラウザで開き、HEIC はダウンロードになる ／ 「外す」→「外す（確定）」で消える |
| 6 | 提出 | 見出しのままで「提出する」→ 確認の小窓 → 理由がすべて並び、中身は残っている ／ 書き足して提出 → 詳細の画面・「提出しました。」|
| 7 | 部門長 | ホームの対応待ちに「部門長・承認・差戻し」と待ち日数 ／ 詳細で「差戻し」を選ぶとコメントが必須になる ／ 「承認」で確定 |
| 8 | 審査・社長 | 審査担当者は部門長の承認の前は詳細を開けない（404）・後は「審査としての判断」／ 社長の「可」で `R8-J-001` が付く（日本時間の日付で年度が決まる）|
| 9 | 条可 | 社長の「条可」→ 申請者に「社長の条件を確認してください」→ 確認で「決裁済み（条可）」|
| 10 | 同時操作 | 部門長の詳細を 2 つのタブで開き、片方で承認 → もう片方で差戻し →「すでに処理されています。…」|
| 11 | 自分の申請一覧 | 絞り込みの 6 つ・375px で 1 件 1 枚のカード・1440px で表（横スクロールの案内）|
| 12 | 申請種類の管理（管理者） | 追加の小窓に見出しの初期値 ／ 停止にすると新しい申請で選べない |
| 13 | 部門の管理（管理者） | 部門長・審査担当者・今年度の開始番号（Task 9）|
| 14 | 準備中 | tinker で `launched_at` を空に戻すと、申請の画面の URL がホーム（準備中）へ送られ、サイドバーから申請のリンクが消える |
| 15 | 全画面 | `main.scrollWidth === main.clientWidth` を 1800 / 1200 / 375px で（Bug #29）・コンソールのエラーと警告が 0 件 |

- [ ] **Step 4: 利用者に見せる写真を撮る**

375px と 1440px で、ホーム（対応待ちあり）・申請書（見出し入り）・添付を送っているところ・詳細（回る順番と判断の小窓）・自分の申請一覧・申請種類の管理を撮り、scratchpad に保存して利用者に送る（`SendUserFile`）。写真には試しのデータしか写らないことを確かめる。

- [ ] **Step 5: コンパイル済みビューを lint する**

⚠ `view:cache` の成功表示だけでは足りない（Bug #21 / #26 / #30）。

```bash
source <scratchpad>/approval-phase2a-local.sh && cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2a && php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done; php artisan view:clear
```

Expected: INVALID 0 件。

- [ ] **Step 6: 片付けて結果を記録する**

`artisan serve` を止めてから:

```bash
source <scratchpad>/approval-phase2a-local.sh && rm -f "$DB_DATABASE" <scratchpad>/approval-phase2a-local.sh && git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a status --porcelain
```

Expected: 何も出ない（使い捨てのコードやビルドの成果が残っていない）。見たことと見つけた不具合をこの計画の末尾に「Task 19 の実測記録」として書き足してコミットする（不具合は直してから。直すときは Task 12〜17 のテストに再現を足す）。


---

## Task 20: ドキュメント

**Files:**
- Modify: `CLAUDE.md`（Completed modules の決裁の行）
- Modify: `docs/ARCHITECTURE.md`（ルートの本数・表・門番）
- Modify: `routes/web.php`（`require …approval.php` の真上の見出しの本数）
- Modify: `docs/BACKLOG.md`（段階2 の節）

- [ ] **Step 1: 書き換える**

`CLAUDE.md` の Completed modules の表:

```markdown
| 決裁申請 段階1・2a | `/approvals/*` | `Approval\*Controller`（門番 3 本・CSV 一括登録・ログイン案内・申請の回覧。状態を変えるのは `App\Support\Approval\Workflow` だけ）|
```

（元の `| 決裁申請 段階1 | … |` の 1 行を置き換える）

`docs/ARCHITECTURE.md`

- `routes/approval.php` の行: `# 決裁申請 段階1・2a (39 ルート。管理系は approval.admin、申請を回す画面は approval.launched)`
- 表の一覧の `approval_setting_logs` の行の次に足す:

```markdown
| `approval_types` | 決裁: 申請の種類（5W2H の見出し・審査部門・利用中/停止）|
| `approval_reviewers` | 決裁: 審査部門の審査担当者（複合主キー）|
| `approval_requests` | 決裁: 申請（状態は VARCHAR ＋ PHP の enum・`lock_version` で同時操作を見張る・決裁No）|
| `approval_steps` | 決裁: 回る段階（提出ごとに部門長・審査・社長の 3 行）|
| `approval_revisions` | 決裁: 提出ごとの中身の控え（**追記のみ**）|
| `approval_histories` | 決裁: 操作の記録（**追記のみ**）|
| `approval_attachments` | 決裁: 添付（`local` ディスク＝非公開。上書きしない）|
| `approval_download_logs` | 決裁: 添付を開いた記録（**追記のみ**）|
| `approval_number_sequences` | 決裁: 部門・年度ごとの連番（行をロックして採る）|
```

- `approval_departments` の行に「・部門長（`head_user_id`）」、`approval_settings` の行に「・使い始めた日時（`launched_at`。空のあいだは準備中）」を足す
- Authentication & Authorization の決裁の行の後に足す:

```markdown
- 決裁（段階2）: 申請を回す画面は 3 段目の `approval.launched`（`EnsureApprovalLaunched`）が守る。`approval_settings.launched_at` が空のあいだは、画面を開く GET を決裁のホーム（準備中）へ送り、それ以外を 404 にする（`EnsureApprovalAdmin` の後・`SubstituteBindings` の前）。見られる範囲は `RequestVisibility`、操作できるかは `RequestPermissions` の 1 か所ずつ
```

`routes/web.php` の `require __DIR__ . '/approval.php';` の真上の見出しの「決裁申請（19ルート）」を「決裁申請（39ルート）」にする。

`docs/BACKLOG.md` の「🚧 決裁申請 段階2（決裁の本体）」の節の見出しを「🚧 決裁申請 段階2（決裁の本体）— 2a 実装済み・本番反映の前」にし、節の最後に足す:

```markdown
### 2a（申請して社長の決裁まで一通り回る）

実装計画: @docs/superpowers/plans/2026-09-26-approval-phase2a.md（Task 0〜21）。worktree `.claude/worktrees/approval-phase2a`（ブランチ `approval-phase2a`）。

- 計画で決めた設計書からの細部（計画 §0.8）: `completed_at` → **`finished_at`**（走査テストの予約語）／提出の別ルートは作らず保存のフォームに `intent=submit` ／添付の消し方は `round` で分ける（計画 §0.5）
- 本番反映の手順（設計書 §7）: **DB が先・`deploy.sh` が後**（新しいコードが `approval_departments.head_user_id` と `approval_settings.launched_at` を読む）。本番の `launched_at` は空のまま（使い始めるのは段階6）
```

- [ ] **Step 2: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a add CLAUDE.md docs/ARCHITECTURE.md routes/web.php docs/BACKLOG.md
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2a commit -m "$(cat <<'MSG'
docs: 決裁 段階2a の画面・表・門番をドキュメントに反映する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 21: 本番反映（利用者の了承を取ってから・親の会話が行う）

> ⚠ **サブエージェントに任せない。** 本番への `ssh`・`scp`・`./deploy.sh` は、それぞれ利用者の了承を取ってから行う。本番のファイルの削除は利用者が行う（実行する 1 行を渡す）。`.env` は読まない。
> ⚠ 本番のシェルは csh なので、`/bin/sh` の heredoc を `ssh` に流す（段階1 と同じ作法）。PHP は `/usr/local/php/8.3/bin/php` を明示する（既定の `php` は 7.4）。

- [ ] **Step 1: 了承を求める（選択式）**

伝えること: ①**DB が先・`./deploy.sh` が後**（新しいコードが `approval_departments.head_user_id` と `approval_settings.launched_at` を読むので、逆だと部門の管理と決裁のホームが 500 になる）②本番に表が 9 つ増え、既存の 2 表に列が 1 つずつ増える（データは変えない）③**申請を回す画面は誰にも見えない**（`launched_at` は空のまま。使い始めるのは段階6）④決裁の管理者は、部門長・審査担当者・今年度の開始番号・申請の種類を本番で先に登録できるようになる。

- [ ] **Step 2: 反映前に本番を読み取る**（読み取りだけ）

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
foreach (["approval_departments", "approval_settings"] as $t) {
    echo $db->selectOne("SHOW CREATE TABLE `" . $t . "`")->{"Create Table"}, PHP_EOL, PHP_EOL;
}
foreach (["approval_types", "approval_reviewers", "approval_requests", "approval_steps", "approval_revisions", "approval_histories", "approval_attachments", "approval_download_logs", "approval_number_sequences"] as $t) {
    echo $t, "=", $db->getSchemaBuilder()->hasTable($t) ? "ある" : "ない", PHP_EOL;
}
echo "departments=", $db->table("approval_departments")->count(), " settings=", $db->table("approval_settings")->count(), " mysql=", $db->selectOne("SELECT VERSION() AS v")->v, PHP_EOL;
'
SH
```

Expected: `approval_departments` に `code` の列があり `head_user_id` が無い ／ `approval_settings` に `president_user_id` があり `launched_at` が無い ／ どちらも `ENGINE=InnoDB`・`utf8mb4_unicode_ci` ／ 9 表とも「ない」。**1 つでも違えば止まり、利用者に伝える**（SQL の前提が崩れている）。

- [ ] **Step 3: `13.x` へ早送りで取り込む**（手元）

```bash
cd /Users/masanori/site/manage && git status --short && git merge-base --is-ancestor 13.x approval-phase2a && echo "FF できる" || echo "13.x が進んでいる"
```

「FF できる」なら:

```bash
git -C /Users/masanori/site/manage merge --ff-only approval-phase2a && git -C /Users/masanori/site/manage log --oneline -3
```

「13.x が進んでいる」なら止まり、取り込み方（マージか、ブランチを載せ替えるか）を利用者に選んでもらう（ほかの会話の作業が入っている）。

- [ ] **Step 4: DB を先に変える**

(a) SQL を本番の置き場所へ送る（`./deploy.sh` もあとで同じ場所へ同じものを送る）:

```bash
scp /Users/masanori/site/manage/database/sql/2026-09-25-approval-phase2a.sql mitsuwa-ud@www3586.sakura.ne.jp:apps/manage/database/sql/
```

(b) 流す前に、文の数と頭を見る（まだ何も変えない）:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$sql = preg_replace("/^--.*$/m", "", file_get_contents(base_path("database/sql/2026-09-25-approval-phase2a.sql")));
$statements = array_values(array_filter(array_map("trim", explode(";", $sql))));
echo "statements=", count($statements), PHP_EOL;
foreach ($statements as $i => $s) { echo $i + 1, ": ", strtok($s, "\n"), PHP_EOL; }
'
SH
```

Expected: `statements=11`（1〜2 が `ALTER TABLE`、3〜11 が `CREATE TABLE`）。

(c) 1 文ずつ流す（**すでに流した形跡があれば 1 文も流さずに止まる**。`PDO::MYSQL_ATTR_MULTI_STATEMENTS` が未設定なので 1 回に 2 文は流せない）:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
$schema = $db->getSchemaBuilder();
$traces = array_keys(array_filter([
    "approval_types"           => $schema->hasTable("approval_types"),
    "approval_requests"        => $schema->hasTable("approval_requests"),
    "departments.head_user_id" => $schema->hasColumn("approval_departments", "head_user_id"),
    "settings.launched_at"     => $schema->hasColumn("approval_settings", "launched_at"),
]));
if ($traces !== []) {
    echo "STOP: すでに流した形跡がある: ", implode(", ", $traces), PHP_EOL;
} else {
    $sql = preg_replace("/^--.*$/m", "", file_get_contents(base_path("database/sql/2026-09-25-approval-phase2a.sql")));
    foreach (array_values(array_filter(array_map("trim", explode(";", $sql)))) as $i => $statement) {
        $db->statement($statement);
        echo "OK ", $i + 1, PHP_EOL;
    }
}
'
SH
```

Expected: `OK 1`〜`OK 11`。⚠ **途中で止まったら**（MySQL の DDL は 1 文ごとに確定する）、出た `OK` の番号と例外の文言を利用者に伝えて止まる。流し直さない（次の手は相談して決める）。

(d) 流した後を読み取る:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
foreach (["approval_departments", "approval_settings", "approval_requests"] as $t) {
    echo $db->selectOne("SHOW CREATE TABLE `" . $t . "`")->{"Create Table"}, PHP_EOL, PHP_EOL;
}
foreach (["approval_types", "approval_reviewers", "approval_requests", "approval_steps", "approval_revisions", "approval_histories", "approval_attachments", "approval_download_logs", "approval_number_sequences"] as $t) {
    echo $t, "=", $db->table($t)->count(), PHP_EOL;
}
echo "departments=", $db->table("approval_departments")->count(), " settings=", $db->table("approval_settings")->count(), PHP_EOL;
'
SH
```

Expected: `head_user_id`・`launched_at` の列と外部キー・索引が SQL どおり ／ 9 表とも 0 行 ／ 部門と設定の行数は Step 2 と同じ。

- [ ] **Step 5: 新しいクラスを読み込めるようにする**（手元の main repo で）

```bash
cd /Users/masanori/site/manage && test ! -e vendor/bin/phpunit && composer dump-autoload --no-dev --optimize && git status --short
```

Expected: `vendor/bin/phpunit` が無い（dev の部品が混ざっていない）・`git status` に何も出ない（`composer.lock` は変わらない）。⚠ **main repo の cwd で行う**（worktree から行うと autoloader に worktree のパスが焼き込まれる）。依存は増やしていないので `composer install` は要らない。

- [ ] **Step 6: 反映**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

Expected: exit 0・6 段すべて成功（CSS が変わるので旧バンドルの掃除が出る）。

- [ ] **Step 7: 本番で確かめる**（すべて読み取り）

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage || exit 1
n=0; bad=0
for f in storage/framework/views/*.php; do n=$((n+1)); /usr/local/php/8.3/bin/php -l "$f" >/dev/null 2>&1 || { bad=$((bad+1)); echo "INVALID: $f"; }; done
echo "views=$n invalid=$bad"
/usr/local/php/8.3/bin/php artisan route:list --name=approvals. --json | /usr/local/php/8.3/bin/php -r '$r = json_decode(stream_get_contents(STDIN), true); echo "approvals=", count($r), PHP_EOL;'
/usr/local/php/8.3/bin/php artisan tinker --execute='
echo "launched_at=", var_export(App\Models\ApprovalSetting::current()->launched_at, true), PHP_EOL;
foreach (["App\\Support\\Approval\\Workflow", "App\\Support\\Approval\\RequestVisibility", "App\\Http\\Middleware\\EnsureApprovalLaunched", "App\\Http\\Controllers\\Approval\\RequestController"] as $c) {
    echo $c, "=", class_exists($c) ? "ok" : "NG", PHP_EOL;
}
'
SH
curl -s https://www.mitsuwat.co.jp/system/manage/index.php/login | grep -c "社員番号またはメールアドレス"
```

Expected: `invalid=0`（⚠ `view:cache` の成功表示だけでは足りない。Bug #21 / #26）・**`approvals=39`**（⚠ テキストの `route:list | grep -c` は長い行を省略して少なく数える。`--json` で数える）・`launched_at=NULL`・4 つとも `ok`・ログイン画面の文字が 1 以上。

ログインした画面の確認は、利用者の了承を取ってから、利用者のブラウザ（ログイン済み。**フォームは送らない**）で行う:

| # | 見ること |
|---|---|
| 1 | `/approvals` が「決裁の機能は準備中です。…」のまま |
| 2 | `/approvals/requests/create` を URL で開くと `/approvals`（準備中）へ送られる ＝ **申請を回す画面が誰にも見えない** |
| 3 | サイドバーに「新しい申請」「自分の申請」が無い |
| 4 | 決裁の管理者に指定された人がいれば、部門の管理（部門長・審査担当者・開始番号の欄）と申請種類の管理が開く。いなければ `/approvals/admin/types` が 403 |
| 5 | 基幹の画面（ダッシュボード・各部署の一覧）が 200・コンソールのエラー 0 件 |

- [ ] **Step 8: 記録する**

`docs/BACKLOG.md` の段階2 の節の見出しを「🚧 決裁申請 段階2（決裁の本体）— 2a 本番反映済み・2b の計画の前」にし、2a の節に反映日・`13.x` のコミット・Step 2〜7 で見たことの表を書き足してコミットする（`docs:` 1 本）。

⚠ `origin/13.x` への push は利用者の明示の指示があったときだけ。⚠ worktree `approval-phase2a` とブランチの片付けは利用者に聞いてから（消さずに残してもよい）。

