# 決裁申請 段階6（6a: 試す人と試す期間）実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 段階6 の前半（6a）— 決裁の管理者が新しい画面 ⑬「使い始め」で試す人を選ぶと、使い始める前でも**試す人にだけ**申請を回す画面・お知らせ・メール・催促が届き、試しの印（帯・メールの件名の【試し】・PDF の上の余白の「試し」・Excel の名前の「試し_」）が付き、回る先の人がみな試す人のときだけ提出できる。6b の片付けで次の番号を戻せるように、「試しの採番だけを除いた次の番号」を今から覚えておく。試す人 0 人のまま本番へ出す（出しただけでは何も変わらない）。

**Architecture:** 「この人に申請を回す画面を見せるか」と「今は 準備中／試す期間／使い始めた のどれか」を `App\Support\Approval\LaunchState` の 1 か所にまとめ、門番・ホーム・2 つのサイドバー・基幹のメニューとベル・⑫・Notifier・催促のコマンド・試しの印・提出の確かめがすべてそれを読む（`ApprovalSetting::isLaunched()`・`launchedForMenu()` をほかで読まないことを走査のテストで止める）。試す人は `approval_members.is_tester`。回る先の人が試す人かの判定は `TrialCoverage` の 1 か所で、提出の確かめ（`SubmitChecker`）と ⑬ の「試せる部門と種類」が使う。【試し】は `ApprovalMailable::subjectLine()` の 1 か所で付け、付けるかどうかは積む時点で決めてメールに持たせる。次の番号は連番の行の `next_without_trials` に覚える（開始番号と紙の番号では動き、社長の判断の採番では動かない）。

**Tech Stack:** Laravel 12 / PHP 8.3（本番 8.3.32・手元のテストは 8.3 を名指し）/ MySQL 8（本番 8.0.40）・SQLite（テスト）/ Blade + Alpine.js 3 + Tailwind v4 / PHPUnit 11 / mPDF・PhpSpreadsheet（既にある）。**部品の追加・更新は無い**。

**Spec:** 設計書 @docs/superpowers/specs/2026-10-26-approval-phase6-design.md（この計画は **6a**: §5.1〜§5.6・§5.9 の欄 1・2・§5.10 の手引きのうち試す人向けの部分・§6 のうち 6a の分・§7。§10 の「実装計画で決めること」は §0 で決めた）。要件定義書 @docs/決裁申請_要件定義書_v1.md（v1.15。v1.16 にする）。前の計画 @docs/superpowers/plans/2026-10-08-approval-phase5b.md（本番反映の作法はそのまま効く）。

## Global Constraints

- ⑬（ルート名 `approvals.admin.launch.*`）は決裁の管理者だけ（門番は `approval.admin` だけ。`approval.launched` は付けない）。準備中・試す期間・使い始めたあとのどれでも開ける
- **試す人**として扱うのは、`approval_members.is_tester` が 1・`users.status` が `active`・削除していない、の 3 つがそろう人だけ（`LaunchState::isTester()`）。印だけ残った無効の人・削除した人は、画面も知らせも届かない
- **準備中（使い始める前で、試す人が 0 人）は今とまったく同じ**。試しの印と提出の確かめは**試す期間**（使い始める前で、試す人が 1 人以上）だけで効く（今のテストの多くが使い始める前に Workflow で提出している。§0.2）
- **使い始めたあと**（`launched_at` が入っている）は今とまったく同じ。試す人の印が残っていても、全員に見え・【試し】も「試し」も「試し_」も付かず・提出の確かめもしない
- 文言（設計書 §5.6 と利用者の決定のまま。変えるときは手引きとその読みやすい版も直す）:
  - 帯: `試しの期間です。ここで出した申請は使い始める前に消えます。`
  - メールの件名: 先頭に `【試し】`（`【試し】【決裁】部門長の確認のお願い：…`・`【試し】【決裁】対応待ちの申請が 2 件あります`）
  - PDF: 上の余白に小さく `試し`（本文と印の位置は変えない）
  - Excel: `試し_決裁台帳_YYYY-MM-DD.xlsx`（ASCII だけの代わりの名前は `trial_ledger_YYYY-MM-DD.xlsx`）
  - 提出の断り: `試す期間は、回る先の人がみな試す人のときだけ出せます。` ＋ 足りない人を `・` でつないで ＋ `が試す人に入っていません。`。足りない人は `{部門名}の部門長（{氏名}）`・`審査部門「{部門名}」の審査の担当`・`社長（{氏名}）` の順
  - 催促のコマンドの記録: 試す期間は `試す期間なので試す人にだけ送りました（N 人・M 件）。`、送る相手がいない日は `試す期間なので試す人にだけ送ります。送る相手はいませんでした。`。準備中は今の `使い始める前なので送りません。`
  - ⑫ の「次に催促を送る日」の下: 準備中は今の `決裁を使い始める前なので、まだ送りません。`、試す期間は `試す期間なので、試す人にだけ送ります。`
- 基幹の画面で問い合わせを増やさない: `LaunchState::opensFor()` は設定の行を作らずに読み（今の `launchedForMenu()`）、試す人かは `$user->approvalMember` から読む（基幹の画面はヘッダーのベルとサイドバーが同じ `Auth::user()` を読むので、`approvalMember` は 1 リクエストに 1 回＝ベルの `opensFor()` が先に読み、サイドバーの `isApprovalAdmin()` が使い回す。計画の点検で実測: 5 → 5 本・19 → 19 本）。`PropertyListSortTest` が見る「件数によらず同じ本数」を崩さない。決裁の画面は、準備中と試す期間に `exists` の問い合わせが数本増える（`launchedForMenu()` は覚えない。本数は件数によらず一定なので、本数を比べる今のテストは赤にならない。点検で実測: 決裁のみ利用者の `/approvals` 4 → 6 本）
- 本番の DDL は `database/sql/2026-10-27-approval-phase6a.sql`、テストの鏡は `database/migrations/2026_10_27_000001_add_approval_phase6a_columns.php`（`down()` を書く）。**DDL が先・`./deploy.sh` が後**
- 文字はすべてエスケープする（`{{ }}`）。コードのコメントは今のファイルと同じく日本語で、設計書の節（「段階6 設計書 §5.5」など）を添える
- テストは worktree で `APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit`（**この Mac の `php` は 8.5**。8.3 を名指しする）。main repo では作業もテストもしない。worktree に `.env` を作らない（`.env*` は読まない・grep しない）
- ⚠ `users.status` は `User::$fillable` に無い（`update(['status' => …])` は黙って何もしない）。テストで無効にするのは `forceFill(['status' => UserStatus::Inactive->value])->save()`（今のテストと同じ）。factory の `create(['status' => …])`（`approvalOnlyUser([...])` など）は効く
- ⚠ 走査のテスト `LaunchStateScanTest`（Task 2）は**コメントや docblock の文字にも当たる**。`ApprovalSetting.php`・`LaunchState.php` のほかのファイル（`app/`・`resources/views/`）のコメントに `launched_at`・`isLaunched(`・`launchedForMenu(` を書かない（`ApprovalLaunchStage`・`EnsureApprovalLaunched`・帯の部品・⑬ の画面とコントローラの注記も）
- コミットは Conventional Commits・日本語の件名（72 文字以内・句点なし）・1 コミット 1 関心事・本文の最後に空行を 1 つあけて `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`。`--no-verify`・`--amend`・`git reset` でのやり直し・`git stash` は使わない。push は利用者が指示したときだけ

## Review Focus

設計書が触れていないが、試す期間に使う人がいちばん出会いそうな場面。

1. **試す人を ⑦ で無効にした・削除した**（試す人の印は残る）→ 申請を回す画面は準備中と同じ・知らせも催促も届かない・⑬ の一覧に「無効」「削除済み」と出て外せる（Task 2 `test_an_inactive_or_deleted_tester_is_not_a_tester`〈試す人の判定〉・Task 3 `test_an_inactive_tester_gets_no_notice`〈無効の人は今の決まり 3 で先に外れる。印が残っても届かないことの守り〉・Task 6 `test_an_inactive_or_deleted_tester_is_listed_and_can_be_removed`）
2. **試す人でない人（決裁の管理者を含む）が、前に届いたメールのリンクやブックマークで、実在する試しの申請を開く・操作する** → 準備中と同じ応答（画面はホームへ・それ以外は 404。実在する ID でも存在しない ID でも同じ）（Task 2 `test_in_trial_a_non_tester_gets_the_same_answers_as_before_launch`・`test_in_trial_a_non_tester_gets_the_same_answers_for_a_real_request`）
3. **試しの途中で担当が入れ替わり**（社長の交代・部門長の交代・付け替え）、新しい担当が試す人でない → 知らせもメールも出ず、操作はエラーにならない（申請はそこで止まる）（Task 3 `test_a_new_handler_who_is_not_a_tester_gets_nothing`）
4. **申請者本人が審査の担当でもある／申請者が部門長本人** → 前者は本人を試す人の審査の担当に数えない（ほかに試す人がいなければ断る）、後者は部門長を見ない（Task 5 `test_the_applicant_does_not_count_as_the_tester_reviewer`・`test_an_applicant_who_is_the_head_does_not_need_the_head`）
5. **使い始めたあと、試す人の印が残ったまま** → 全員に見え・【試し】も「試し」も「試し_」も付かず・提出の確かめもしない（Task 2 `test_after_launch_the_tester_flag_changes_nothing`・Task 3 `test_after_launch_everyone_gets_notices_without_the_mark`・Task 4 `test_after_launch_there_is_no_trial_mark`・Task 5 `test_after_launch_the_route_is_not_checked`）

---

## 0. 計画を書く段階で決めたこと（設計書 §10 のうち 6a の分）

### 0.1 2 回に分ける（利用者の決定 2026-10-27）

- **6a（この計画）**: 試す人の印・3 つの状態の判定・門番とホームとメニュー・知らせと催促の絞り込み・試しの印・提出の確かめ・⑬ の欄 1「試す人」と欄 2「試せる部門と種類」・「試しの採番だけを除いた次の番号」を覚えること（画面には出ない）・受け入れ確認の手引きの試す人向けの部分・要件定義書 v1.16
- **6b（別の計画。6a を本番に出し、試し始めてから書く）**: ⑬ の欄 3「試しの申請の片付け」・欄 4「使い始める」・使い始めたあとの ⑬ の表示（日時と押した人）・使い始めるときに試す人の印を外すこと・要件 4.5・14.2・14.5・14.6 の例外と段階3 の D16 の注記・手引きの片付けと使い始めるの部分
- 覚えておく番号を 6a に入れる理由: 片付けは次の番号を「試しの申請が無かったら付いていた番号」へ戻す（設計書 §5.7）。試しの途中で開始番号や紙の番号が動くと、あとからは割り出せない
- 6a を出しただけでは本番は何も変わらない（試す人 0 人＝準備中）。6a の期間に出した試しの申請と、試しで進んだ決裁No は、6b が届いて片付けるまで残る（利用者に伝える）

### 0.2 3 つの状態と判定の 1 か所（§5.1・§5.2）

- `App\Enums\ApprovalLaunchStage`（string の enum。`Preparing = 'preparing'`・`Trial = 'trial'`・`Launched = 'launched'`。`label(): string` は `準備中`・`試す期間`・`使い始めた`）
- `App\Support\Approval\LaunchState`（`final`・static だけ）:

| メソッド | 中身 | 読む所 |
|---|---|---|
| `launched(): bool` | `ApprovalSetting::launchedForMenu()`（行を作らない） | 下のメソッド・Notifier |
| `isTester(User $user): bool` | `! $user->trashed() && $user->status === UserStatus::Active && (bool) $user->approvalMember?->is_tester` | 下・Notifier・催促・⑬・`TrialCoverage` |
| `opensFor(?User $user): bool` | `$user !== null && (self::launched() \|\| self::isTester($user))`（順番を変えない。使い始めたあとは関係を読まない） | 門番・ホーム・2 つのサイドバー・`ApprovalMenu` |
| `stage(): ApprovalLaunchStage` | `launched()` なら Launched／`ApprovalMember::testers()->exists()` なら Trial／ほかは Preparing | ⑫・催促・⑬・`inTrial()` |
| `inTrial(): bool` | `self::stage() === ApprovalLaunchStage::Trial` | PDF・Excel・提出の確かめ |
| `showsTrialBanner(User $user): bool` | `(self::isTester($user) \|\| $user->isApprovalAdmin()) && self::inTrial()`（この順。試す人でも管理者でもない人には問い合わせを足さない） | 帯（Task 4） |
| `launchedAt(): ?CarbonInterface` | `ApprovalSetting::current()->launched_at`（⑬ は決裁の管理の画面なので行を作ってよい） | ⑬ の「使い始めました（…）」（Task 6） |

- `ApprovalSetting::isLaunched()`・`launchedForMenu()`・`launched_at` を読むのは `app/Models/ApprovalSetting.php` と `app/Support/Approval/LaunchState.php` だけ（`LaunchStateScanTest` が `app/` と `resources/views/` を走査して止める。コメントにも当たる＝Global Constraints）。6b の「使い始める」で `launched_at` を書くときも、書く所を `LaunchState` に置く（走査は書くほうにも当たる）
- **試しの印と提出の確かめは「使い始める前」ではなく「試す期間」で効かせる。** 準備中に効かせると、使い始める前に Workflow で提出している今のテスト（`NotifierTest` など数十本）が提出を断られて赤になる。準備中は門番がだれも通さないので、提出の確かめが要るのは試す期間だけ（設計書 §5.4 も「試す期間の提出・出し直し」）
- Notifier だけは `! LaunchState::launched()` を「試す期間のメール」とみなす（使い始める前に知らせを受け取れるのは試す人だけなので、届くメールはすべて試す期間のもの。問い合わせを 1 本減らす）
- 基幹の画面の問い合わせ: `opensFor()` は launched の判定のあと、使い始める前だけ `approvalMember` を読む。基幹の画面はヘッダーのベル（`notice-bell` → `ApprovalMenu::unreadNotices()`）が先に `opensFor()` で `approvalMember` を読み、サイドバー（`sidebar.blade.php`）の `$user->isApprovalAdmin()` がそれを使い回すので本数は変わらない（同じ `Auth::user()`）

### 0.3 試す人の持ち方（§5.3）

- 列 `approval_members.is_tester` TINYINT(1) NOT NULL DEFAULT 0（`stamp_text` の後）。モデルは `$fillable` に足し、`boolean` の cast
- スコープ `ApprovalMember::scopeTesters(Builder $query): void` = `is_tester` が 1 で、`user_id` が「有効で削除していない利用者」の中にあるもの（`whereIn('user_id', User::query()->where('status', UserStatus::Active->value)->select('id'))`。`User` の既定のスコープが削除した人を除く）。⚠ `whereHas('user', …)` にしない（`user()` は `withTrashed()` なので削除した人が当たる）
- 足す・外すは `ApprovalMember::updateOrCreate(['user_id' => $user->id], ['is_tester' => true|false])`（基幹の利用者の管理 `Admin\UserController::syncApprovalFlags()`・⑦ の `saveStampText()` と同じ形。ほかの列を変えない）。記録は `SettingLogger::record('member.tester_added' | 'member.tester_removed', 'user', $user->id, ['is_tester' => 前], ['is_tester' => 後])`
- 使い始めたあとは ⑬ で出し入れできない（断る）。印を外すのは 6b の「使い始める」

### 0.4 試しの採番だけを除いた次の番号（§5.7。6b の片付けが使う）

- 列 `approval_number_sequences.next_without_trials` INT UNSIGNED NOT NULL DEFAULT 1（`last_issued` の後）。`ApprovalNumberSequence` の `$fillable` と integer の cast に足す
- 動かすのは `ApprovalNumber` の 3 か所だけ:
  - `lockedRow()` の upsert で作る新しい行は `next_without_trials = 1`（`next_number` と同じ。列の既定 1 と同じなので、消しても振る舞いは変わらない＝Task 7 で壊しても等価）
  - `setNext($department, $next)` は `next_number` と同じ値を入れる
  - `advancePast($department, $fiscalYear, $seq)` は、`next_number` とは別に比べて、`next_without_trials <= $seq` なら `$seq + 1` にする（戻さない）
  - `issue()` は動かさない
- ⇒ いつも `next_without_trials <= next_number`。紙の取り込みの取り消し・紙の 1 件の削除は今どおり番号を動かさない（設計書 §4.2 の「取り込み → 取り消し」のあとも、紙の番号＋1 を覚えている）
- 使い始めたあとも同じ決まりで動き続ける（読む所が無いだけ。扱いは 6b で決める）
- 本番の既存の行は SQL で `next_without_trials = next_number` にそろえる。**番号を付けた申請が 1 件も無いときだけ正しい**（Task 10 Step 2 で確かめる）

### 0.5 知らせと催促（§5.5）

- `Notifier::send()`: 最初に `$launched = LaunchState::launched()` を 1 回読む。`receives()` に引数 `bool $launched` を足し（`send()` が 1 回読んだ値を渡す）、最後に `&& ($launched || LaunchState::isTester($user))` を足す（関係を読むのは、使い始める前の、ほかの決まりを通った人だけ）。メールは `->asTrial(! $launched)` を付けて積む。今の「使い始める前は何もしない」の早い return は消す（入れ物が空なら return は残す）。試す人が 0 人の準備中は、だれも `receives()` を通らないので今と同じく何も作らない
- `ApprovalMailable`（2 つのメールの土台）に 1 か所:
  - `public const TRIAL_MARK = '【試し】';`
  - `public bool $trial = false;`（積むときに決めて持たせる。キューの中で設定を読まない＝段階3 設計書 §4.2）
  - `public function asTrial(bool $trial = true): static`
  - `protected function subjectLine(string $text): string` = `($this->trial ? self::TRIAL_MARK : '') . '【決裁】' . $text`
  - `ApprovalNoticeMail::envelope()` は `subjectLine($this->notice->headline . '：' . $this->context['subject'])`、`ApprovalReminderMail::envelope()` は `subjectLine("対応待ちの申請が {$this->total} 件あります")`
- `ApprovalRemindCommand::remind()`: `LaunchState::stage()` で分ける。Preparing は今の文で何もしない。Trial は宛先を `LaunchState::isTester()` の人だけにし（`User` の読み込みに `with('approvalMember')`）、まとめメールに `->asTrial()`、記録の文は Global Constraints のとおり。送らない日・1 日 1 回・20 件までは今のまま。Launched は今のまま

### 0.6 試しの印（§5.6）

- 帯: 部品 `resources/views/approvals/_trial_banner.blade.php`（`LaunchState::showsTrialBanner(Auth::user())` のときだけ出す。`role="status"`・色は `_mail_failure` と同じ琥珀色〈`border-amber-300 bg-amber-50 text-amber-900`〉・`mb-5 rounded-lg px-4 py-3 text-[13px]`）。`layouts/app.blade.php` のフラッシュメッセージの前に `@if(request()->routeIs('approvals.*')) @include('approvals._trial_banner') @endif`（基幹の画面では読まない＝問い合わせを増やさない）。決裁のみ利用者の枠も基幹の枠も同じレイアウトなので 1 か所で足りる
- PDF: `PdfSheet` に `public readonly bool $trial = false`（コンストラクタの最後の引数・既定値つき＝今の最後の 3 つが既定値つきなので、既定値が無いと PHP 8.3 が deprecation を出す。`for()` で `LaunchState::inTrial()`）。`ApprovalPdf::render()` に引数 `?string $headerHtml = null` を足し、あれば `margin_header` 5（mm）で `SetHTMLHeader()` する。`ApprovalPdf::sheet()` は `$sheet->trial` のとき新しい部品 `approvals.requests._pdf_trial_header`（右寄せ・7pt・灰色〈`#888`〉の `試し` だけ）を渡す。`margin_top` 12 は変えない（本文と印の位置は変わらない）
- Excel: `LedgerExcel::fileNames(bool $trial = false): array` は `$trial` のとき `['試し_' . 今の名前, 'trial_' . 今の代わりの名前]`。`LedgerController::excel()` が `LedgerExcel::fileNames(LaunchState::inTrial())` で呼ぶ。中身は変えない

### 0.7 提出の確かめと試せる部門・種類（§5.4・利用者の決定 2026-10-27）

- `App\Support\Approval\TrialCoverage`（`final`・static だけ）
- `missingForSubmit(ApprovalRequest $request, User $applicant): list<string>` — 足りない人の書き方を、部門長 → 審査 → 社長 の順に返す:
  1. 申請部門があり、申請者がその部門に所属し、部門長が決まっていて、部門長が申請者本人でない（要件 4.3。本人なら部門長の確認を飛ばす）とき、部門長が試す人でなければ `"{$department->name}の部門長（{$head->name}）"`
  2. 種類があり、停止した種類の下書きでなく（今の `SubmitChecker` が「選び直せば変わる理由は並べない」とする所。選び直しの理由だけを出す）、その審査部門に申請者本人以外の有効な審査担当者（`activeReviewers()`）が 1 人以上いて、その中に試す人が 1 人もいなければ `"審査部門「{$reviewDepartment->name}」の審査の担当"`
  3. 社長が決まっていて（`ApprovalSetting::current()->president`）、試す人でなければ `"社長（{$president->name}）"`
  - 部門長・社長が未設定、審査担当者がいない、申請部門に所属していない、停止した種類の下書きのときは、今の理由が出るので足さない（同じことを 2 回言わない）。⚠ 社長が未設定のときに `isTester(null)` を呼ばない（TypeError で提出が 500 になる。計画の点検で実測）
- `SubmitChecker::reasons()` の最後に: `LaunchState::inTrial()` で、`missingForSubmit()` が空でなければ、Global Constraints の文を 1 つ足す
- `overview(): array` — ⑬ の欄 2 の材料:

```php
/**
 * @return array{
 *     president: array{user: ?User, ok: bool},
 *     departments: list<array{department: ApprovalDepartment, head: ?User, ok: bool}>,
 *     types: list<array{type: ApprovalType, ok: bool, limitedTo: list<string>}>,
 *     combinations: list<string>,
 * }
 */
public static function overview(): array
```

  - departments: すべての部門を会社の並び → 部門の並び（`LedgerController::choices()` と同じ並び）。`ok` = 部門長が決まっていて試す人
  - types: 使っている種類（`is_active`）を `ApprovalType::query()->ordered()` の順。`ok` = 審査部門の有効な審査担当者に試す人が 1 人以上。`limitedTo` = 使える部門の名前（空なら全部門。`departments` の関係。並びは部門の並び＝会社 → 部門）
  - combinations: 社長が `ok` のときだけ、`ok` の部門 × `ok` の種類のうち `isUsableIn()` が通るものを `"{部門名} × {種類名}"`（部門の並び → 種類の並び）。社長が `ok` でなければ空

### 0.8 ⑬ 使い始め（§5.9・利用者の決定 2026-10-27「役のある人を並べる」）

- ルート（`routes/approval.php` の `approvals.admin.` のグループの中＝門番は `approval.admin` だけ。「過去の紙の決裁の取り込み」の後ろ）:
  - `GET /launch` → `LaunchController@index`、名前 `launch.index`
  - `POST /launch/testers` → `LaunchController@addTester`、名前 `launch.testers.store`（入力 `user_id`）
  - `DELETE /launch/testers/{user}` → `LaunchController@removeTester`、名前 `launch.testers.destroy`、`->withTrashed()`（削除した人の印も外せる）。パラメータ名 `{user}` は ⑦ の `approvals.admin.users.*` と同じ
- `App\Http\Controllers\Approval\LaunchController`（`public const SEARCH_LIMIT = 20`＝テストがクラスの外から読む）
  - `index(Request $request): View` — 見出し「使い始め」の横に `LaunchState::stage()->label()` → `試す人 {N} 人`（`ApprovalMember::testers()->count()`）の順
    - 使い始めたあとは、欄の代わりに `使い始めました（{{ \App\Support\JapanTime::format(\App\Support\Approval\LaunchState::launchedAt()) }}）。` だけ（押した人は 6b。`launched_at` を直に読むと走査のテストが赤。アプリの時刻は UTC なので `JapanTime::format()` を通す＝通さないと 9 時間ずれ、`StoredTimestampDisplayScanTest` も赤）
    - 欄 1「試す人」: 1 行の中は 氏名 → 役 → 印とボタン の順（`LaunchScreenTest` の `assertSeeInOrder` がこの順を前提にする）。**役のある人**（社長 → 部門長〈部門の並び〉→ 審査の担当〈審査部門の並び・`reviewers()`〉→ 決裁の管理者 の順に、1 人 1 行。役は `社長`・`部門長（{部門名}）`・`審査の担当（{部門名}）`・`決裁の管理者` を `・` でつなぐ）。その下に「役の無い試す人」。各行: 試す人なら「試す人」の印と「外す」、そうでなければ「試す人にする」。無効の人は `無効`、削除した人は `削除済み` を添え、試す人にするボタンは出さない（試す人なら「外す」は出す）。削除した人は試す人のときだけ行に出す
    - 「ほかの人を足す」: 同じ画面への GET の検索（`q`）。⑦ と同じ探し方（氏名は `NameSearch::terms()`・`whereNameHasAll()`、社員番号とメールは部分一致）で、有効で削除していない人を `SEARCH_LIMIT` 人まで（並びは `orderBy('name')->orderBy('id')`。`limit(SEARCH_LIMIT + 1)` で 1 人多く読んで超えたかを見る）。超えたら `self::SEARCH_LIMIT . ' 人まで出しています。絞り込んでください。'`（定数と文の数を割らない）。`q` は ⑦ の `scalarInput()` と同じく文字列だけ受ける（配列は空とみなす。配列だと `NameSearch::terms()` が TypeError で 500）。検索の欄に戻す値も同じもの
    - 欄 2「試せる部門と種類」: `TrialCoverage::overview()`（社長・部門ごとの部門長・種類ごとの審査の担当を ○／× で。× には足りない人の名前〈部門長の名前・`審査部門「…」の担当`・社長の名前。未設定は `未設定`〉。種類に使える部門の限りがあれば `{部門名}だけ`。いちばん下に「いま試せる組み合わせ」、無ければ `まだありません`）
    - 欄 3・4 は 6b で足す（6a では出さない）
  - `addTester(Request $request): RedirectResponse` — 使い始めたあとは `with('error', '使い始めたあとは試す人を変えられません。')` で ⑬ へ戻す（印を変えない）。`user_id` は `required|integer` を `try { $request->validate([...]) } catch (ValidationException $e) { throw $e->redirectTo(route('approvals.admin.launch.index')); }` で確かめる（`PaperImportController` と同じ。`validate()` のままだと前の URL へ戻る＝前に GET していなければ `http://localhost`。Bug #64）。そのうえで「有効で削除していない利用者」でなければ `redirect()->route('approvals.admin.launch.index')->withErrors(['user_id' => '有効な利用者を選んでください。'])`（`back()` は使わない）。すでに試す人なら何も変えず `error` で `{氏名} さんはすでに試す人です。`。足したら `{氏名} さんを試す人にしました。`
  - `removeTester(Request $request, User $user): RedirectResponse` — 使い始めたあとは同じく断る。試す人でなければ何も変えず `error` で `{氏名} さんは試す人ではありません。`。外したら `{氏名} さんを試す人から外しました。`
  - 戻り先はいつも `route('approvals.admin.launch.index')`（検索の `q` は持ち越さない）
- 二度押し止め: 「試す人にする」「外す」のフォームに決裁の管理の画面の部品 `approvals._submit_once`（`x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()"`・ボタンは `<button type="submit" :disabled="submitting" …>`・横に `<span role="status" x-text="submitting ? '送っています…' : ''">`。⑨・⑫・⑪ の 1 件ずつの登録と同じ。`_partials._submit_once` は基幹の取り込みの確定〈1 回限りの鍵〉向けなので使わない）。外すのに確認の小窓は出さない（外してもまた足せる。設計書 §5.9 の「削除の確認」はデータを消す操作の話で、外すのは印を戻すだけ＝計画の判断）
- リンク（ラベル `使い始め`・「過去の決裁の取り込み」の次）: 決裁のサイドバー（展開と引き出しの 2 か所）・基幹のサイドバーの「決裁の管理」（展開と引き出しの 2 か所）・決裁のホーム 2 つ（`home`・`home-launched`）の本文の管理のリンク（合わせて 6 か所。折りたたみ版は今どおり足さない）
- 全件分類: `LaunchGateTest::OPEN_BEFORE_LAUNCH` に `'approvals.admin.launch.' => '使い始め（⑬。準備中・試す期間に試す人を選ぶ。段階6 設計書 §5.9）'`

### 0.9 手引きと要件定義書（§5.10・§3）

- 手引き `docs/運用_受け入れ確認の手引き.md`（6a は試す人向けの部分。片付けと使い始めるは 6b で足すと書く）と、写真付きの読みやすい版（新しい artifact。元は `~/site/approval/acceptance-guide/acceptance-guide.html`＋`img/`）
- 要件 v1.16 = 設計書 §3 のうち 6a の分（3.1・8・9.2・13 の ⑬ の欄 1・2・14.6・15.5・15.6・16.1・16.2）。14.6（片付けた試しのデータもバックアップに残る。D15）は、試しの添付と DB の行が 6a で試し始めた日から夜間バックアップに入るので v1.16 に入れる。15.5 は試す人の印と「試しの採番だけを除いた次の番号」の列、15.6 は門番・知らせの絞り込み・提出の確かめのテスト（片付けの分は v1.17）。設計書 §3 に無いが 6a で利用者に見える変化（試す期間の提出の確かめ〈D6〉・帯・PDF の「試し」・Excel の「試し_」）は 13 の ⑬ の行か 16.1 の段階6 の行に書く。4.5・14.2・14.5 の例外と ⑬ の欄 3・4 は 6b（v1.17）。読みやすい版（https://claude.ai/artifact/A5GPFxxjsuDRAfLfCzcufY）も同じ URL で更新する
- 紙の取り込みの運用手順書 `docs/運用_過去の決裁の取り込み.md` の 2 か所（16 行目・133 行目。設計書 §5.10）と、その読みやすい版（https://claude.ai/artifact/HCZ6nEX7aKBmomYGiC3WkR。同じパス `~/site/approval/import-guide/import-guide.html` で直して publish）

### 0.10 作り方

- この計画は 5a・5b と違い、試作（scratchpad の写し）を先に作って測っていない。テストの名前と主な assert・署名・決まった値を書き、本体は担当が書く。Step の Expected は設計から決めたもの（全件の本数は Task 0 で測った数から増える）
- 進め方は 5b と同じ: 実装の担当は 1 度に 1 人（Task ごと）・点検は別の担当が WT の HEAD の写しで・変異の確かめは scratchpad の写しで（auto mode は WT のソースを壊す編集を断る）・Task 10（本番反映）は担当に任せず親の会話が行う
- **計画の点検（2026-10-27・会話 ab2e5ae8）**: 試作が無い分の備えとして、読むだけの担当 3 人が受け持ちを分け、WT の HEAD の写しに計画どおりの最小の実装を書いてテストと変異を流した（報告は `~/.claude/plans/approval-phase3-tasks/6a/review-a.md`・`review-b.md`・`review-c.md`）。直したもの: 利用者を無効にする書き方（`forceFill`）・SQLite で列の位置を比べない・`Phase2TablesTest` の `LATER_COLUMNS`・走査はコメントにも当たる（7 ファイル）・`LaunchState::launchedAt()`・試す期間のサイドバーのテスト・PDF の「試し」の位置を本当に見るテスト・基幹の画面の行き先・提出の確かめの未設定の役と申請者＝部門長のテスト・⑬ の二度押し止めの部品・戻り先・`$errors`・`q` の配列・ホームの本文のリンク・変異の表（少なくとも落ちるもの）・本番の確かめ（次の番号のずれ 0）・文書の直しの細部
- 点検のあとで親の会話が決めたこと: ①試しの提出の確かめは、今の `SubmitChecker` の「選び直せば変わる理由は並べない」に合わせ、申請部門に所属していないときは部門長を、停止した種類の下書きでは審査を見ない（その部門で使えない種類の場合は見る＝簡単さを取った）②⑬ の「すでに試す人です」「試す人ではありません」は `error`（頼んだ変更が起きていないため）③「外す」に確認の小窓を出さないのは計画の判断のまま（設計書 §5.9 の削除の確認はデータを消す操作）④要件 14.6 は v1.16 に入れる（試しのデータは 6a の試し始めからバックアップに入る）⑤決裁の画面で `exists` が数本増えるのは受け入れる（件数によらず一定）⑥`makeTester()` は覚えた設定も捨てる⑦門番の全件の走査に「基幹の管理者」（`UserRole::Executive`）も入れる

---

## 1. 触るファイル

### 新規

| ファイル | 役目 | Task |
|---|---|---|
| `database/sql/2026-10-27-approval-phase6a.sql`・`database/migrations/2026_10_27_000001_add_approval_phase6a_columns.php` | 本番の DDL とテストの鏡（列 2 つと番号のそろえ） | 1 |
| `app/Enums/ApprovalLaunchStage.php`・`app/Support/Approval/LaunchState.php` | 3 つの状態と判定の 1 か所 | 2 |
| `resources/views/approvals/_trial_banner.blade.php`・`resources/views/approvals/requests/_pdf_trial_header.blade.php` | 帯と PDF の「試し」 | 4 |
| `app/Support/Approval/TrialCoverage.php` | 回る先の人が試す人か | 5 |
| `app/Http/Controllers/Approval/LaunchController.php`・`resources/views/approvals/admin/launch.blade.php` | ⑬ 使い始め | 6 |
| `tests/Feature/Approval/Phase6/`（`Phase6aColumnsTest`・`TrialNumberBaseTest`・`TrialAccessTest`・`LaunchStateScanTest`・`TrialNoticeTest`・`TrialMarksTest`・`TrialCoverageTest`・`LaunchScreenTest`） | テスト | 1〜6 |
| `docs/運用_受け入れ確認の手引き.md` | 受け入れ確認の手引き（試す人向け） | 9 |

### 変更

| ファイル | 変えること | Task |
|---|---|---|
| `app/Models/ApprovalMember.php`・`app/Models/ApprovalNumberSequence.php` | 列・cast・`scopeTesters()` | 1 |
| `tests/Feature/Approval/Phase2/Phase2TablesTest.php` | `LATER_COLUMNS` に `next_without_trials` を足す（2a の表に列を足すため） | 1 |
| `app/Support/Approval/ApprovalNumber.php` | `lockedRow()`・`setNext()`・`advancePast()` | 1 |
| `tests/Concerns/BuildsApprovalFixtures.php` | `makeTester()`・`trialWorld()` | 1 |
| `app/Http/Middleware/EnsureApprovalLaunched.php`・`app/Http/Controllers/Approval/HomeController.php`・`app/Support/Approval/ApprovalMenu.php`・`resources/views/layouts/partials/sidebar_approval.blade.php`・`sidebar.blade.php`・`app/Http/Controllers/Approval/HolidayController.php`・`resources/views/approvals/admin/holidays.blade.php`・`routes/approval.php`（注記だけ） | 判定を `LaunchState` に置き換える | 2 |
| `app/Support/Approval/Notifier.php`・`app/Console/Commands/ApprovalRemindCommand.php` | Task 2 で `LaunchState::launched()` に置き換え、Task 3 で試す人に絞る | 2・3 |
| `app/Mail/ApprovalMailable.php`・`ApprovalNoticeMail.php`・`ApprovalReminderMail.php` | 【試し】の 1 か所 | 3 |
| `resources/views/layouts/app.blade.php`・`app/Support/Approval/LaunchState.php`（`showsTrialBanner()`）・`PdfSheet.php`・`ApprovalPdf.php`・`LedgerExcel.php`・`app/Http/Controllers/Approval/LedgerController.php` | 試しの印 | 4 |
| `app/Support/Approval/SubmitChecker.php` | 提出の確かめ | 5 |
| `routes/approval.php`・2 つのサイドバー・`resources/views/approvals/home.blade.php`・`home-launched.blade.php` | ⑬ のルートとリンク | 6 |
| `tests/Feature/Approval/Phase2/LaunchGateTest.php`・`tests/Feature/Approval/ApprovalSidebarTest.php`・`tests/Feature/Approval/Phase3/ApprovalRemindCommandTest.php`・`tests/Feature/Approval/Phase3/HolidaySettingsTest.php` | テストを足す | 2・3・6 |
| `docs/決裁申請_要件定義書_v1.md`・`docs/運用_過去の決裁の取り込み.md`・`docs/BACKLOG.md`・`CLAUDE.md` | 文書 | 9・10 |

## 2. 作業の順番

```
Task 0 準備 → Task 1 表の列と覚えておく番号 → Task 2 判定の 1 か所と門番・ホーム・メニュー → Task 3 知らせと催促
→ Task 4 試しの印 → Task 5 提出の確かめと試せる部門・種類 → Task 6 ⑬ 使い始め
→ Task 7 全件・変異 → Task 8 ブラウザと写真 → Task 9 文書 → Task 10 本番反映（親の会話）
```

各 Task のコミットのあとで全件が緑（途中の段でも）。Task 7・8 は記録だけをコミットする。

---

## Task 0: 作業場所の準備

**作業場所**: worktree `/Users/masanori/site/manage/.claude/worktrees/approval-phase3`（ブランチ `approval-phase3`。3a〜5b と同じ worktree＝dev の vendor の置き場）。この計画のコミットの親は `236dfe4a`（`13.x` = `69c43b0c` の上に設計書の 1 コミット）。**main repo では作業もテストもしない**。

- [ ] **Step 1: 並行の作業を確かめる**

```bash
cd /Users/masanori/site/manage && git status --short --branch && git log --oneline -3 && git worktree list && git for-each-ref --sort=-committerdate --format='%(refname:short) %(committerdate:short) %(subject)' refs/heads | head && git log --oneline approval-phase3..13.x
```

Expected: worktree `approval-phase3` の先頭がこの計画のコミット。最後のコマンドが空（`13.x` が進んでいない）。試す人・使い始め・受け入れの名前の付いた枝や worktree があれば、中身を読み、消さずに利用者へ報告して止まる。`13.x` が進んでいたら止まり、利用者に取り込むかを聞く（取り込むなら WT で `git merge 13.x`。rebase しない）。

- [ ] **Step 2: vendor と PHP を確かめる**（symlink にしない。Bug #50）

```bash
WT=/Users/masanori/site/manage/.claude/worktrees/approval-phase3; test -x "$WT/vendor/bin/phpunit" && ! test -L "$WT/vendor" && echo "vendor OK"; /opt/homebrew/opt/php@8.3/bin/php -v | head -1
```

Expected: `vendor OK`・`PHP 8.3.…`（部品は足さない。`composer` は打たない）。

- [ ] **Step 3: 全件テストが通る状態から始める**（以下すべての Task で同じ流し方）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `OK (N tests, …)`（N を台帳に控える。5b の反映のときは 4182。そのあと manage の会話がテストを足している。計画の点検の写しから見込んだ数は 4318）。赤があれば 6a の作業の前に利用者へ報告して止まる。

⚠ 以下の Task の「テストを流す」は、すべてこの形でファイルを並べたもの。`cd` はコマンドごとに書く。git は `git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 …` で呼ぶ。コミットのたびに `git status --porcelain` が空であることを確かめる。

---

## Task 1: 試す人の印と「試しの採番だけを除いた次の番号」

**Files:**
- Create: `database/sql/2026-10-27-approval-phase6a.sql`・`database/migrations/2026_10_27_000001_add_approval_phase6a_columns.php`
- Modify: `app/Models/ApprovalMember.php`・`app/Models/ApprovalNumberSequence.php`・`app/Support/Approval/ApprovalNumber.php`・`tests/Concerns/BuildsApprovalFixtures.php`・`tests/Feature/Approval/Phase2/Phase2TablesTest.php`
- Test: `tests/Feature/Approval/Phase6/Phase6aColumnsTest.php`・`tests/Feature/Approval/Phase6/TrialNumberBaseTest.php`

**Interfaces:**
- Produces: 列 `approval_members.is_tester`（bool）・`approval_number_sequences.next_without_trials`（int）／ `ApprovalMember::testers()`（スコープ）／ テストの部品 `makeTester(User ...$users): void`・`trialWorld(): array`（`approvalWorld()` と同じキー）

- [ ] **Step 1: テストの部品を足す**（`BuildsApprovalFixtures` の `makePresident()` の後）

```php
    /**
     * 試す人にする（段階6 §5.3）。覚えた approvalMember を捨てる（同じインスタンスで actingAs しても新しい印を読む）。
     * 覚えた設定も捨てる（ApprovalSetting::current() の社長は別のインスタンスで、古い approvalMember を持ちうるため）
     */
    protected function makeTester(User ...$users): void
    {
        foreach ($users as $user) {
            ApprovalMember::updateOrCreate(['user_id' => $user->id], ['is_tester' => true]);
            $user->unsetRelation('approvalMember');
        }
        ApprovalSetting::forget();
    }

    /** approvalWorld() の回る先の人（申請者・部門長・審査担当者・社長）をみな試す人にした世界（試す期間） */
    protected function trialWorld(): array
    {
        $world = $this->approvalWorld();
        $this->makeTester($world['applicant'], $world['head'], $world['reviewer'], $world['president']);

        return $world;
    }
```

- [ ] **Step 2: 失敗するテストを書く**

`Phase6aColumnsTest`（`Phase5aTablesTest` と同じ作り。SQL の `ALTER TABLE … ADD COLUMN` を読み、`Schema::getColumns()` と比べる。`RefreshDatabase`・`BuildsApprovalFixtures`）:

```php
    private const SQL = 'database/sql/2026-10-27-approval-phase6a.sql';

    private const MIGRATION = 'database/migrations/2026_10_27_000001_add_approval_phase6a_columns.php';

    /**
     * SQL の `ALTER TABLE `表` ADD COLUMN `列` 型 … DEFAULT 値 … AFTER `前の列`` を読む（コメント行を除く。Phase5aTablesTest の読み方）
     *
     * @return array<string, array<string, array{0: bool, 1: ?string, 2: string}>> 表 => 列 => [NULL を許すか, 既定, AFTER の列]
     */
    private function sqlAddedColumns(): array

    public function test_the_sql_and_the_migration_add_the_same_columns(): void
    {
        $this->assertSame([
            'approval_members'          => ['is_tester' => [false, '0', 'stamp_text']],
            'approval_number_sequences' => ['next_without_trials' => [false, '1', 'last_issued']],
        ], $this->sqlAddedColumns());

        foreach ($this->sqlAddedColumns() as $table => $columns) {
            foreach ($columns as $column => [$nullable, $default, $after]) {
                $migrated = collect(Schema::getColumns($table))->firstWhere('name', $column);
                $this->assertNotNull($migrated, "{$table}.{$column} が migration に無い");
                $this->assertSame($nullable, $migrated['nullable'], "{$table}.{$column} の NULL");
                $this->assertSame($default, trim((string) $migrated['default'], "'"), "{$table}.{$column} の既定");   // SQLite は '0' と引用符つきで返す
                // ⚠ SQLite は ->after() を無視して表の最後に足す（計画の点検で実測: next_without_trials は updated_at の後ろ）。位置は migration の文で比べる
                $this->assertMatchesRegularExpression("/'{$column}'\\)[^;]*->after\\('{$after}'\\)/", file_get_contents(base_path(self::MIGRATION)), "{$table}.{$column} の位置");
            }
        }
    }

    public function test_the_sql_and_the_migration_copy_the_next_number(): void
    {
        $this->assertStringContainsString('UPDATE `approval_number_sequences` SET `next_without_trials` = `next_number`;', file_get_contents(base_path(self::SQL)));
        $this->assertStringContainsString("'next_without_trials' => DB::raw('next_number')", file_get_contents(base_path(self::MIGRATION)));
    }

    public function test_the_testers_are_active_people_who_are_not_deleted(): void
    {
        $active   = $this->approvalOnlyUser(['name' => '有効 試す']);
        $inactive = $this->approvalOnlyUser(['name' => '無効 試す', 'status' => UserStatus::Inactive->value]);
        $deleted  = $this->approvalOnlyUser(['name' => '削除 試す']);
        $notOne   = $this->approvalAdmin();
        $this->makeTester($active, $inactive, $deleted);
        $deleted->delete();

        $this->assertSame([$active->id], ApprovalMember::testers()->pluck('user_id')->all());
        $this->assertFalse(ApprovalMember::where('user_id', $notOne->id)->sole()->is_tester);
    }
```

`TrialNumberBaseTest`（`ApprovalNumber` を直に呼ぶ。`setUp()` で `$this->travelTo(CarbonImmutable::parse('2026-10-27 10:00', 'Asia/Tokyo')->utc())`＝ミツワの R8 年度〈2026〉。行は `ApprovalNumberSequence::where('department_id', $dept->id)->where('fiscal_year', 2026)->sole()`）:

```php
    /** @return array{0: int, 1: int, 2: int} [次の番号, 使ったいちばん大きい番号, 試しの採番だけを除いた次の番号] */
    private function numbers(ApprovalDepartment $dept, int $fiscalYear = 2026): array

    public function test_issuing_moves_only_the_next_number(): void
    {
        $dept = $this->approvalWorld()['dept'];
        ApprovalNumber::issue($dept, now());
        ApprovalNumber::issue($dept, now());

        $this->assertSame([3, 2, 1], $this->numbers($dept));
    }

    public function test_the_start_number_sets_both(): void
    {
        $dept = $this->approvalWorld()['dept'];
        ApprovalNumber::setNext($dept, 50);
        ApprovalNumber::issue($dept, now());

        $this->assertSame([51, 50, 50], $this->numbers($dept));
    }

    /** 紙の番号は両方を進める（別々に比べる）。戻さない */
    public function test_a_paper_number_moves_both_forward_but_never_back(): void
    {
        $dept = $this->approvalWorld()['dept'];
        ApprovalNumber::setNext($dept, 5);
        ApprovalNumber::issue($dept, now());
        ApprovalNumber::issue($dept, now());
        ApprovalNumber::issue($dept, now());                // 次 8・覚えた 5

        ApprovalNumber::advancePast($dept, 2026, 6);
        $this->assertSame([8, 7, 7], $this->numbers($dept)); // 次の番号は 8 のまま・覚えた番号だけ 7

        ApprovalNumber::advancePast($dept, 2026, 20);
        $this->assertSame([21, 7, 21], $this->numbers($dept));

        ApprovalNumber::advancePast($dept, 2026, 3);
        $this->assertSame([21, 7, 21], $this->numbers($dept));
    }

    public function test_a_past_year_paper_number_leaves_it(): void
    {
        $dept = $this->approvalWorld()['dept'];
        ApprovalNumber::setNext($dept, 5);

        $this->assertNull(ApprovalNumber::advancePast($dept, 2025, 40));
        $this->assertSame([5, 0, 5], $this->numbers($dept));
        $this->assertFalse(ApprovalNumberSequence::where('department_id', $dept->id)->where('fiscal_year', 2025)->exists());
    }
```

- [ ] **Step 3: テストを流して落ちることを確かめる**

`tests/Feature/Approval/Phase6/` を流す。Expected: FAIL（SQL のファイルが無い・列 `is_tester`・`next_without_trials` が無い）。

- [ ] **Step 4: SQL と migration を書く**

`database/sql/2026-10-27-approval-phase6a.sql`（頭の注記は 5a と同じ形: 設計書の節・migration と対・**DDL が先・`./deploy.sh` が後**〈新しいコードが ⑬ と社長の判断・開始番号の設定で 2 つの列を読み書きする。逆だと Unknown column で 500〉・**3 は番号を付けた申請が 1 件も無いときだけ正しい**・流し方は段階1〜5b と同じ〈tinker で `DB::statement()` に 1 文ずつ〉）:

```sql
-- 1. 試す人（段階6 §5.3・D1。使い始める前に本番で申請を回して確かめる人）
ALTER TABLE `approval_members`
  ADD COLUMN `is_tester` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '試す人（使い始める前に本番で申請を回して確かめる人）' AFTER `stamp_text`;

-- 2. 試しの採番だけを除いた次の番号（段階6 §5.7。開始番号と紙の番号で動き、社長の判断の採番では動かない。6b の片付けで次の番号をこれに戻す）
ALTER TABLE `approval_number_sequences`
  ADD COLUMN `next_without_trials` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '試しの採番だけを除いた次の番号' AFTER `last_issued`;

-- 3. 今の行をそろえる（番号を付けた申請が 1 件も無いので、今の次の番号＝試しの採番を除いた次の番号）
UPDATE `approval_number_sequences` SET `next_without_trials` = `next_number`;
```

migration `2026_10_27_000001_add_approval_phase6a_columns.php`（`up()` は `$table->boolean('is_tester')->default(false)->after('stamp_text')->comment(…)`・`$table->unsignedInteger('next_without_trials')->default(1)->after('last_issued')->comment(…)`〈今の `is_admin`・`next_number` と同じ書き方。1 列を 1 文に書く＝`Phase6aColumnsTest` が文で位置を見る〉で足し、`DB::table('approval_number_sequences')->update(['next_without_trials' => DB::raw('next_number')])`。`down()` は 2 つの列を落とす）。

- [ ] **Step 5: モデルと `ApprovalNumber` を直す**（§0.3・§0.4）

- `ApprovalMember`: `$fillable` に `is_tester`・cast `'is_tester' => 'boolean'`・`public function scopeTesters(Builder $query): void`（§0.3 の形。docblock に「`whereHas('user')` にしない理由」）
- `ApprovalNumberSequence`: `$fillable` と cast に `next_without_trials`
- `Phase2TablesTest::LATER_COLUMNS` の最後（5a の `approval_requests` の行の次）に `'approval_number_sequences' => ['next_without_trials'],   // 6a（Phase6aColumnsTest）`（足さないと `test_the_sql_and_the_migration_declare_the_same_columns` が赤。点検で実測）
- `ApprovalNumber::lockedRow()`: upsert の値に `'next_without_trials' => 1`（更新の列は今の `['updated_at']` のまま）
- `ApprovalNumber::setNext()`: `$row->update(['next_number' => $next, 'next_without_trials' => $next])`
- `ApprovalNumber::advancePast()`: 2 つの列を別々に比べ、上がるものだけを 1 回の `update()` で（戻り値は今どおり `next_number`）
- クラスの docblock に「`next_without_trials` は試しの採番だけを除いた次の番号（段階6 §5.7）。`issue()` は動かさない」

- [ ] **Step 6: テストを流して通ることを確かめる**

`tests/Feature/Approval/Phase6/`・`tests/Feature/Approval/Phase2/ApprovalNumberTest.php`・`tests/Feature/Approval/Phase2/Phase2TablesTest.php`・`tests/Feature/Approval/Phase5/PaperNumberTest.php` を流す。Expected: PASS。続けて全件。Expected: `OK`（Task 0 の N ＋ 7）。

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add database/sql/2026-10-27-approval-phase6a.sql database/migrations/2026_10_27_000001_add_approval_phase6a_columns.php app/Models/ApprovalMember.php app/Models/ApprovalNumberSequence.php app/Support/Approval/ApprovalNumber.php tests/Concerns/BuildsApprovalFixtures.php tests/Feature/Approval/Phase2/Phase2TablesTest.php tests/Feature/Approval/Phase6
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "feat: 決裁の試す人の印と試しの採番を除いた次の番号を持つ"
```

（本文の最後に空行を 1 つあけて `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`。以下のコミットも同じ）

---

## Task 2: 判定の 1 か所と、門番・ホーム・サイドバー・メニュー・⑫

**Files:**
- Create: `app/Enums/ApprovalLaunchStage.php`・`app/Support/Approval/LaunchState.php`
- Modify: `app/Http/Middleware/EnsureApprovalLaunched.php`・`app/Http/Controllers/Approval/HomeController.php`・`app/Support/Approval/ApprovalMenu.php`・`resources/views/layouts/partials/sidebar_approval.blade.php`・`resources/views/layouts/partials/sidebar.blade.php`・`app/Http/Controllers/Approval/HolidayController.php`・`resources/views/approvals/admin/holidays.blade.php`・`app/Support/Approval/Notifier.php`・`app/Console/Commands/ApprovalRemindCommand.php`・`routes/approval.php`（注記だけ）
- Test: `tests/Feature/Approval/Phase6/TrialAccessTest.php`・`tests/Feature/Approval/Phase6/LaunchStateScanTest.php`・`tests/Feature/Approval/Phase2/LaunchGateTest.php`（足す）・`tests/Feature/Approval/Phase3/HolidaySettingsTest.php`（足す）・`tests/Feature/Approval/ApprovalSidebarTest.php`（足す）

**Interfaces:**
- Consumes: Task 1 の `ApprovalMember::testers()`・`is_tester`・`makeTester()`・`trialWorld()`
- Produces: `ApprovalLaunchStage`（`Preparing`・`Trial`・`Launched`・`label()`）／ `LaunchState::launched()`・`isTester(User)`・`opensFor(?User)`・`stage()`・`inTrial()`・`launchedAt()`（§0.2 の表。`showsTrialBanner()` は Task 4）

- [ ] **Step 1: 失敗するテストを書く**

`TrialAccessTest`:

```php
    public function test_the_stage_follows_the_launch_and_the_testers(): void
    {
        $w = $this->approvalWorld();
        $this->assertSame(ApprovalLaunchStage::Preparing, LaunchState::stage());

        $this->makeTester($w['applicant']);
        $this->assertSame(ApprovalLaunchStage::Trial, LaunchState::stage());
        $this->assertTrue(LaunchState::inTrial());

        $w['applicant']->forceFill(['status' => UserStatus::Inactive->value])->save();
        $this->assertSame(ApprovalLaunchStage::Preparing, LaunchState::stage());

        $this->launchApprovals();
        $this->assertSame(ApprovalLaunchStage::Launched, LaunchState::stage());
        $this->assertSame(['準備中', '試す期間', '使い始めた'], array_map(fn ($s) => $s->label(), ApprovalLaunchStage::cases()));
    }

    public function test_in_trial_a_tester_gets_the_launched_home_and_the_links(): void
    {
        $w = $this->trialWorld();

        $this->actingAs($w['applicant'])->get(route('approvals.home'))
            ->assertOk()->assertViewIs('approvals.home-launched')
            ->assertSee(route('approvals.requests.create'))->assertSee(route('approvals.ledger.index'));
    }

    public function test_in_trial_a_non_tester_keeps_the_preparing_home(): void
    {
        $w = $this->approvalWorld();
        $this->makeTester($w['head']);
        $this->assertSame(ApprovalLaunchStage::Trial, LaunchState::stage(), '試す期間になっていない');

        foreach ([$w['applicant'], $this->approvalAdmin()] as $user) {
            $this->actingAs($user)->get(route('approvals.home'))
                ->assertOk()->assertViewIs('approvals.home')->assertDontSee(route('approvals.requests.create'));
        }
    }

    public function test_in_trial_the_menu_count_and_the_bell_are_only_for_testers(): void
    {
        $w = $this->approvalWorld();
        $this->makeTester($w['head']);

        $this->assertSame(0, ApprovalMenu::pendingCount($w['head']));
        $this->assertSame(0, ApprovalMenu::unreadNotices($w['head']));
        $this->assertNull(ApprovalMenu::pendingCount($w['applicant']));
        $this->assertNull(ApprovalMenu::unreadNotices($w['applicant']));
    }

    public function test_an_inactive_or_deleted_tester_is_not_a_tester(): void
    {
        $w = $this->approvalWorld();
        $this->makeTester($w['applicant'], $w['head']);

        $w['applicant']->forceFill(['status' => UserStatus::Inactive->value])->save();
        $w['head']->delete();

        $this->assertFalse(LaunchState::opensFor($w['applicant']->fresh()));
        $this->assertFalse(LaunchState::opensFor(User::withTrashed()->find($w['head']->id)));
        $this->assertSame(ApprovalLaunchStage::Preparing, LaunchState::stage());   // 有効な試す人がいない
    }

    public function test_after_launch_the_tester_flag_changes_nothing(): void
    {
        $w = $this->approvalWorld();
        $this->makeTester($w['head']);
        $this->launchApprovals();

        $this->assertTrue(LaunchState::opensFor($w['applicant']));
        $this->actingAs($w['applicant'])->get(route('approvals.home'))->assertViewIs('approvals.home-launched');
        $this->assertSame(0, ApprovalMenu::pendingCount($w['applicant']));
    }
```

`LaunchStateScanTest`:

```php
    /** 使い始めたかを読むのは ApprovalSetting と LaunchState だけ（段階6 設計書 §5.1「判定は 1 か所」） */
    public function test_only_the_launch_state_reads_the_launch_flag(): void
    {
        $allowed = ['app/Models/ApprovalSetting.php', 'app/Support/Approval/LaunchState.php'];
        $found   = [];

        foreach (['app', 'resources/views'] as $dir) {
            foreach (File::allFiles(base_path($dir)) as $file) {
                $path = str_replace(base_path() . '/', '', $file->getPathname());
                if (! in_array($path, $allowed, true) && preg_match('/isLaunched\(|launchedForMenu\(|launched_at/', $file->getContents())) {
                    $found[] = $path;
                }
            }
        }

        $this->assertSame([], $found, '使い始めたかは LaunchState から読む');
    }
```

`LaunchGateTest` に足す（`use BuildsRouteUrls;`・`use Illuminate\Support\Str;`・`use App\Enums\ApprovalLaunchStage;`・`use App\Support\Approval\LaunchState;`）:

```php
    public function test_in_trial_a_tester_passes_the_gate(): void
    {
        $this->probeRoutes();
        $request = $this->draftFor($this->approvalWorld());
        $tester  = $this->approvalOnlyUser();
        $this->makeTester($tester);

        $this->actingAs($tester)->get('/approvals/_probe/' . $request->id)->assertOk()->assertSee('ok');
    }

    /**
     * 試す期間に試す人でない人は、止める側の**すべての**ルートで準備中と同じ応答（画面を開く GET はホームへ・それ以外は 404。
     * 存在しない ID でも同じ）。決裁の管理者でも同じ（段階6 設計書 §5.2・§6）
     */
    public function test_in_trial_a_non_tester_gets_the_same_answers_as_before_launch(): void
    {
        $this->resolveHttpKernel();
        $this->makeTester($this->approvalOnlyUser(['name' => '試す 人']));    // 試す期間にする（試すのは別の人）
        $this->assertSame(ApprovalLaunchStage::Trial, LaunchState::stage(), '試す期間になっていない（準備中のテストとして空振りしていないか）');
        $users   = ['決裁の管理者' => $this->approvalAdmin(), '決裁のみ利用者' => $this->approvalOnlyUser(), '基幹の管理者' => $this->baseUser(['role' => UserRole::Executive->value])];   // 設計書 §6 の人（UserRole を use）
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $middleware = app('router')->gatherRouteMiddleware($route);
            if (! str_starts_with((string) $route->getName(), 'approvals.') || ! in_array(EnsureApprovalLaunched::class, $middleware, true)) {
                continue;
            }
            $url = $this->urlForRoute($route, fn (string $name) => $name === 'notice' ? (string) Str::uuid() : '999999');

            foreach ($users as $label => $user) {
                if (! $user->isApprovalAdmin() && in_array(EnsureApprovalAdmin::class, $middleware, true)) {
                    continue;   // 管理の門番が先に 403（ここで見るのは稼働の門番）
                }
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $response = $this->actingAs($user)->call($method, $url);
                    $where    = "{$label} {$method} {$route->getName()}";
                    $method === 'GET'
                        ? $this->assertSame(route('approvals.home'), $response->headers->get('Location'), $where)
                        : $this->assertSame(404, $response->getStatusCode(), $where);
                    $checked++;
                }
            }
        }

        $this->assertGreaterThanOrEqual(self::MIN_GATED, $checked, '空振りしていないか');
    }

    public function test_in_trial_a_non_tester_gets_the_same_answers_for_a_real_request(): void
    {
        $w        = $this->trialWorld();
        $request  = $this->submittedFor($w);
        $outsider = $this->approvalAdmin();     // 試す人でない決裁の管理者

        $this->actingAs($outsider)->get(route('approvals.requests.show', $request))->assertRedirect(route('approvals.home'));
        $this->actingAs($outsider)->get(route('approvals.requests.pdf', $request))->assertRedirect(route('approvals.home'));
        $this->actingAs($outsider)->post(route('approvals.requests.withdraw', $request))->assertNotFound();
        $this->actingAs($outsider)->getJson(route('approvals.requests.show', $request))->assertNotFound();
        $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->assertOk();
    }
```

`HolidaySettingsTest` に足す:

```php
    public function test_the_next_day_note_follows_the_stage(): void
    {
        $admin = $this->approvalAdmin();
        $this->actingAs($admin)->get(route('approvals.admin.holidays.index'))->assertSee('決裁を使い始める前なので、まだ送りません。');

        $this->makeTester($this->approvalOnlyUser());
        $this->actingAs($admin)->get(route('approvals.admin.holidays.index'))
            ->assertSee('試す期間なので、試す人にだけ送ります。')->assertDontSee('決裁を使い始める前なので、まだ送りません。');

        $this->launchApprovals();
        $this->actingAs($admin)->get(route('approvals.admin.holidays.index'))
            ->assertDontSee('試す期間なので、試す人にだけ送ります。')->assertDontSee('決裁を使い始める前なので、まだ送りません。');
    }
```

`ApprovalSidebarTest` に足す（このファイルは `BuildsApprovalFixtures` を使わず、自前の `approvalAdmin(string $role)`・`sidebars()`・`assertHasLink()` を持つ。試す人は `ApprovalMember::create` で作る。計画の点検で、2 つのサイドバーを `launched()` に戻す変異をこの 1 本が落とすことを確かめた）:

```php
    /** 試す期間は、試す人にだけ申請の画面へのリンクを出す（段階6 設計書 §5.2・§6）。決裁のサイドバーと基幹のサイドバーの両方 */
    public function test_in_trial_the_request_links_are_only_for_testers(): void
    {
        $tester = User::factory()->approvalOnly()->create(['must_change_password' => false]);
        $other  = User::factory()->approvalOnly()->create(['must_change_password' => false]);
        $base   = User::factory()->create(['role' => UserRole::Staff->value, 'must_change_password' => false]);
        ApprovalMember::create(['user_id' => $tester->id, 'is_tester' => true]);
        ApprovalMember::create(['user_id' => $base->id, 'is_tester' => true, 'is_admin' => true]);   // 試す人の決裁の管理者
        $admin  = $this->approvalAdmin();                                                         // 試す人でない決裁の管理者

        $sidebars = $this->sidebars($this->actingAs($tester)->get(route('approvals.home'))->assertOk()->getContent());
        foreach (['expanded', 'drawer'] as $key) {
            $this->assertHasLink($sidebars[$key], route('approvals.requests.create'), '新しい申請', $key);
            $this->assertHasLink($sidebars[$key], route('approvals.ledger.index'), '決裁台帳', $key);
        }
        foreach ($this->sidebars($this->actingAs($other)->get(route('approvals.home'))->assertOk()->getContent()) as $key => $aside) {
            $this->assertStringNotContainsString(route('approvals.requests.index'), $aside, "{$key} に試す人でない人の申請の画面へのリンクが出ている");
        }
        $sidebars = $this->sidebars($this->actingAs($base)->get('/dashboard/tenant')->assertOk()->getContent());
        foreach (['expanded', 'drawer'] as $key) {
            $this->assertHasLink($sidebars[$key], route('approvals.ledger.index'), '決裁台帳', $key);
            $this->assertHasLink($sidebars[$key], route('approvals.admin.requests.index'), '進行中の申請の管理', $key);
        }
        foreach ($this->sidebars($this->actingAs($admin)->get('/dashboard/tenant')->assertOk()->getContent()) as $key => $aside) {
            $this->assertStringNotContainsString(route('approvals.ledger.index'), $aside, "{$key} に試す人でない管理者の決裁台帳が出ている");
            $this->assertStringNotContainsString(route('approvals.admin.requests.index'), $aside, "{$key} に試す人でない管理者の進行中の申請の管理が出ている");
        }
    }
```

- [ ] **Step 2: テストを流して落ちることを確かめる**

`tests/Feature/Approval/Phase6/`・`LaunchGateTest`・`HolidaySettingsTest`・`ApprovalSidebarTest` を流す。Expected: FAIL（`LaunchState`・`ApprovalLaunchStage` が無い。走査は 7 ファイルを見つける＝`ApprovalRemindCommand`・`HolidayController`・`HomeController`〈docblock も〉・`EnsureApprovalLaunched`・`ApprovalMenu`・`Notifier`・`sidebar_approval.blade.php`。`ApprovalSetting.php` は許す側）。

- [ ] **Step 3: `ApprovalLaunchStage` と `LaunchState` を書く**（§0.2 の表。`launchedAt()` もここで書く〈Task 6 が使う〉。`showsTrialBanner()` はまだ書かない。docblock に 3 つの状態の表と「準備中は今と同じ」「試しの印と提出の確かめは試す期間だけ」「基幹の画面で問い合わせを増やさない読み方」）

- [ ] **Step 4: 読んでいる所を置き換える**

| ファイル | 今 | 後 |
|---|---|---|
| `EnsureApprovalLaunched` | `ApprovalSetting::current()->isLaunched()` | `LaunchState::opensFor($request->user())`（docblock: 試す期間は試す人なら通す・ほかの人は準備中と同じ） |
| `HomeController::index()` | `! ApprovalSetting::current()->isLaunched()` | `! LaunchState::opensFor($user)`。⚠ クラスの docblock（17 行目「使い始める前（approval_settings.launched_at が空）は…」）も直す（走査に当たる。例: 「この人に申請を回す画面を見せないとき〈LaunchState::opensFor() が false＝準備中、または試す期間で試す人でない人〉は段階1 の「準備中」の画面のまま（§5.2・D1・段階6 §5.2）」） |
| `ApprovalMenu` の 2 つ | `ApprovalSetting::launchedForMenu() ? … : null` | `LaunchState::opensFor($user) ? … : null` |
| `sidebar_approval.blade.php` | `$approvalsLaunched = …current()->isLaunched()` | `$approvalsOpen = \App\Support\Approval\LaunchState::opensFor(Auth::user())`（変数の名前を 5 か所とも変える・コメントも「この人に申請を回す画面を見せるか」） |
| `sidebar.blade.php` | `$approvalsLaunched = $approvalPending !== null` | `$approvalsOpen = …`（同じ。2 か所） |
| `HolidayController::index()`・`holidays.blade.php` | `$launched = ApprovalSetting::launchedForMenu()`・`@unless($launched)` | `$stage = LaunchState::stage()`・準備中と試す期間で Global Constraints の文 |
| `Notifier::send()` | `! ApprovalSetting::launchedForMenu()` | `! LaunchState::launched()`（振る舞いは Task 3 で変える） |
| `ApprovalRemindCommand::remind()` | `! ApprovalSetting::launchedForMenu()` | `! LaunchState::launched()`（同じ） |
| `routes/approval.php` の 135 行目の注記 | 「使い始める前（approval_settings.launched_at が空）は」 | 「準備中と、試す期間の試す人でない人には」（`routes/` は走査の外だが事実と合わなくなる） |

- [ ] **Step 5: テストを流して通ることを確かめる**

同じファイルと `tests/Feature/Approval/ApprovalSidebarTest.php`・`ApprovalMenuTest`・`HomeAndListTest`・`tests/Feature/Tenant/PropertyListSortTest.php` を流す。Expected: PASS。続けて全件。Expected: `OK`。

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add -A app resources tests
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "feat: 決裁の試す期間は試す人にだけ申請の画面を開く"
```

---

## Task 3: 知らせと催促は試す人にだけ・件名の【試し】

**Files:**
- Modify: `app/Support/Approval/Notifier.php`・`app/Mail/ApprovalMailable.php`・`app/Mail/ApprovalNoticeMail.php`・`app/Mail/ApprovalReminderMail.php`・`app/Console/Commands/ApprovalRemindCommand.php`
- Test: `tests/Feature/Approval/Phase6/TrialNoticeTest.php`・`tests/Feature/Approval/Phase3/ApprovalRemindCommandTest.php`（足す）

**Interfaces:**
- Consumes: `LaunchState::launched()`・`isTester()`・`stage()`（Task 2）
- Produces: `ApprovalMailable::TRIAL_MARK`・`public bool $trial`・`asTrial(bool $trial = true): static`・`protected subjectLine(string $text): string`

- [ ] **Step 1: 失敗するテストを書く**

`TrialNoticeTest`（`ReadsApprovalNotices` も使う。メールを受け取らせる人は、その部品の `mailable($user, 'head')`〈許可するドメインを登録し、アドレスをそのドメインにする〉を通す。`judge()` は `NotifierTest` のものを写す）:

```php
    public function test_in_trial_only_testers_get_notices_and_mails(): void
    {
        Mail::fake();
        $w        = $this->trialWorld();
        $head     = $this->mailable($w['head'], 'head');
        $reviewer = $this->mailable($w['reviewer'], 'reviewer');
        $outsider = $this->mailable($this->baseUser(['name' => '試さない 審査']), 'outsider');   // 試す人でない審査担当者
        $w['reviewDept']->reviewers()->attach($outsider->id);

        $request = $this->submittedFor($w);                           // 部門長の番
        $this->judge($request, $head, 'head');                        // 審査の番（2 人のうち試す人は 1 人）

        $this->assertSame(1, ApprovalNotice::where('notifiable_id', $head->id)->count());
        $this->assertSame(1, ApprovalNotice::where('notifiable_id', $reviewer->id)->count());
        $this->assertSame(0, ApprovalNotice::where('notifiable_id', $outsider->id)->count());
        Mail::assertNotQueued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $m) => $m->hasTo($outsider->email));
        Mail::assertQueued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $m) => $m->hasTo($head->email)
            && $m->envelope()->subject === '【試し】【決裁】部門長の確認のお願い：社用車の購入');
    }

    public function test_the_mark_is_put_in_one_place_for_both_mails(): void
    {
        $context = ['subject' => '社用車', 'number' => null, 'applicant' => '申請 花子', 'department' => '住宅事業部', 'url' => 'https://example.test/r/1'];
        $notice  = new ApprovalNoticeMail('部門 長', NoticeText::turn(ApprovalStepKind::Head), $context);
        $digest  = new ApprovalReminderMail('部門 長', 2, [], 'https://example.test/home');

        $this->assertSame('【決裁】部門長の確認のお願い：社用車', $notice->envelope()->subject);
        $this->assertSame('【試し】【決裁】部門長の確認のお願い：社用車', $notice->asTrial()->envelope()->subject);
        $this->assertSame('【決裁】対応待ちの申請が 2 件あります', $digest->envelope()->subject);
        $this->assertSame('【試し】【決裁】対応待ちの申請が 2 件あります', $digest->asTrial()->envelope()->subject);
        $this->assertSame('【試し】【決裁】対応待ちの申請が 2 件あります', unserialize(serialize(new SendQueuedMailable($digest)))->mailable->envelope()->subject, 'キューを通ると印が落ちる');   // use Illuminate\Mail\SendQueuedMailable
    }

    public function test_a_new_handler_who_is_not_a_tester_gets_nothing(): void
    {
        $w       = $this->trialWorld();
        $request = $this->submittedFor($w);
        $this->judge($request, $w['head'], 'head');
        $this->judge($request, $w['reviewer'], 'review');             // 社長の番
        Mail::fake();
        $newPresident = $this->mailable($this->baseUser(['name' => '新しい 社長']), 'newpresident');   // 試す人でない（メールも届く人＝メールの確かめが空振りしない）

        Notifier::presidentChanged($this->approvalAdmin(), $newPresident);

        $this->assertSame(0, ApprovalNotice::where('notifiable_id', $newPresident->id)->count());
        Mail::assertNothingQueued();
    }

    /** 無効の人は今の決まり 3 で先に外れる（試す人の判定より前）。ここでは試す人の印が残っていても届かないことを守る（試す人の判定の status は Task 2 の G02 が見る） */
    public function test_an_inactive_tester_gets_no_notice(): void
    {
        $w       = $this->trialWorld();
        $request = $this->submittedFor($w);                           // 部門長の番
        ApprovalNotice::query()->delete();
        Mail::fake();
        $w['head']->forceFill(['status' => UserStatus::Inactive->value])->save();

        Notifier::turnArrived($request->refresh(), $w['applicant']);

        $this->assertSame(0, ApprovalNotice::count());
        Mail::assertNothingQueued();
    }

    /** 試す人から外した申請者には、差戻しのお知らせもメールも出ない（段階6 設計書 §6「試す人でない…申請者には」） */
    public function test_an_applicant_removed_from_the_testers_gets_nothing(): void
    {
        Mail::fake();
        $w         = $this->trialWorld();
        $applicant = $this->mailable($w['applicant'], 'applicant');
        $request   = $this->submittedFor($w);
        ApprovalMember::where('user_id', $applicant->id)->update(['is_tester' => false]);   // 試す人から外した（ほかの 3 人が試す人なので試す期間のまま）

        $this->judge($request, $w['head'], 'head', ApprovalStepResult::Return);

        $this->assertSame(0, ApprovalNotice::where('notifiable_id', $applicant->id)->count());
        Mail::assertNotQueued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $m) => $m->hasTo($applicant->email));
    }

    public function test_after_launch_everyone_gets_notices_without_the_mark(): void
    {
        Mail::fake();
        $w    = $this->approvalWorld();
        $head = $this->mailable($w['head'], 'head');                  // 試す人でない
        $this->makeTester($w['applicant']);                           // 印が残っていても
        $this->launchApprovals();

        $this->submittedFor($w);

        $this->assertSame(1, ApprovalNotice::where('notifiable_id', $head->id)->count());
        Mail::assertQueued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $m) => $m->hasTo($head->email)
            && $m->envelope()->subject === '【決裁】部門長の確認のお願い：社用車の購入');
    }
```

`ApprovalRemindCommandTest` に足す（同じファイルの `at()`・`remind()`・`reminders()`・`reminderTo()`・`mailable()`・`MONDAY_NINE` を使う。日付の進め方は `test_each_person_gets_one_digest_of_the_requests_waiting_three_days_or_more` と同じ）:

```php
    /** 試す期間は、対応待ちの人のうち試す人にだけ送る（段階6 設計書 §5.5） */
    public function test_in_trial_only_testers_are_reminded(): void
    {
        Mail::fake();
        $w        = $this->trialWorld();
        $reviewer = $this->mailable($w['reviewer'], 'reviewer');
        $outsider = $this->mailable($this->baseUser(['name' => '試さない 審査']), 'outsider');
        $w['reviewDept']->reviewers()->attach($outsider->id);

        $this->at('2026-10-01 10:00:00');
        $request = $this->submittedFor($w, ['subject' => '審査待ち']);
        app(Workflow::class)->judgeHead($request->refresh(), $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);

        $this->at(self::MONDAY_NINE);
        $this->assertSame('2026-10-05 試す期間なので試す人にだけ送りました（1 人・1 件）。', $this->remind());

        $this->assertCount(1, $this->reminders());
        $this->assertSame('【試し】【決裁】対応待ちの申請が 1 件あります', $this->reminderTo($reviewer)->envelope()->subject);
    }

    public function test_in_trial_a_day_with_no_tester_to_remind_says_so(): void
    {
        Mail::fake();
        $this->makeTester($this->approvalOnlyUser());

        $this->at(self::MONDAY_NINE);
        $this->assertSame('2026-10-05 試す期間なので試す人にだけ送ります。送る相手はいませんでした。', $this->remind());
        $this->assertSame([0, 0], [ApprovalReminderRun::sole()->recipient_count, ApprovalReminderRun::sole()->item_count]);
    }
```

（`mailable()` は今のファイルの部品。審査担当者の待ちが部門長の承認の時刻から数えて 3 日以上になる日付にする。ずれたら日付だけを直す）

- [ ] **Step 2: テストを流して落ちることを確かめる**

Expected: FAIL（`asTrial()` が無い・使い始める前は何も作らない・催促は「使い始める前なので送りません。」）。

- [ ] **Step 3: §0.5 のとおりに直す**

- `ApprovalMailable`: `TRIAL_MARK`・`$trial`・`asTrial()`・`subjectLine()`（docblock: 付けるかは積む時点で決める＝キューの中で設定を読まない）
- `ApprovalNoticeMail::envelope()`・`ApprovalReminderMail::envelope()`: `subjectLine()` を使う
- `Notifier`: クラスの docblock の決まり 7 を「使い始める前は試す人にだけ（段階6 §5.5）」に。`send()` と `receives()` は §0.5
- `ApprovalRemindCommand`: §0.5（`mails(bool $testersOnly)` に分ける。クラスの docblock の「順に確かめ」に試す期間を足す）

- [ ] **Step 4: テストを流して通ることを確かめる**

`TrialNoticeTest`・`tests/Feature/Approval/Phase3/` を流す。Expected: PASS（`NotifierTest::test_nothing_is_made_before_launch` と `ApprovalRemindCommandTest::test_nothing_is_sent_before_launch` も緑のまま＝準備中は今と同じ）。続けて全件。Expected: `OK`。

- [ ] **Step 5: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app tests
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "feat: 決裁の試す期間は知らせと催促を試す人にだけ届け件名に試しを付ける"
```

---

## Task 4: 試しの印（帯・PDF・Excel）

**Files:**
- Create: `resources/views/approvals/_trial_banner.blade.php`・`resources/views/approvals/requests/_pdf_trial_header.blade.php`
- Modify: `app/Support/Approval/LaunchState.php`（`showsTrialBanner()`）・`resources/views/layouts/app.blade.php`・`app/Support/Approval/PdfSheet.php`・`app/Support/Approval/ApprovalPdf.php`・`app/Support/Approval/LedgerExcel.php`・`app/Http/Controllers/Approval/LedgerController.php`
- Test: `tests/Feature/Approval/Phase6/TrialMarksTest.php`

**Interfaces:**
- Consumes: `LaunchState::inTrial()`・`isTester()`（Task 2）
- Produces: `LaunchState::showsTrialBanner(User $user): bool`／ `PdfSheet::$trial`／ `ApprovalPdf::render(string $html, string $footerHtml, string $title, ?string $headerHtml = null): string`／ `LedgerExcel::fileNames(bool $trial = false): array{0: string, 1: string}`

- [ ] **Step 1: 失敗するテストを書く**

`TrialMarksTest`（`private const BANNER = '試しの期間です。ここで出した申請は使い始める前に消えます。';`）:

```php
    public function test_the_banner_is_on_the_request_screens_for_a_tester(): void
    {
        $w = $this->trialWorld();

        $this->actingAs($w['applicant'])->get(route('approvals.home'))->assertSee(self::BANNER);
        $this->actingAs($w['applicant'])->get(route('approvals.requests.create'))->assertSee(self::BANNER);
        $this->actingAs($w['head'])->get(route('dashboard.tenant'))->assertOk()->assertDontSee(self::BANNER);   // 基幹の画面には出さない（dashboard は転送だけなので行き先を名指し）
    }

    public function test_the_banner_is_on_the_admin_screens_only_in_trial(): void
    {
        $admin = $this->approvalAdmin();                                  // 試す人でない決裁の管理者
        $this->actingAs($admin)->get(route('approvals.admin.types.index'))->assertDontSee(self::BANNER);   // 準備中

        $this->makeTester($this->approvalOnlyUser());
        $this->actingAs($admin)->get(route('approvals.admin.types.index'))->assertSee(self::BANNER);
        $this->actingAs($this->approvalOnlyUser())->get(route('approvals.home'))->assertDontSee(self::BANNER);   // 試す人でも管理者でもない
    }

    public function test_the_pdf_and_the_excel_are_marked_in_trial(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Tokyo')->utc());
        $w       = $this->trialWorld();
        $request = $this->submittedFor($w);

        $this->assertTrue(PdfSheet::for($w['applicant'], $request->refresh())->trial);
        $this->assertStringContainsString('試し', view('approvals.requests._pdf_trial_header')->render());
        $this->actingAs($w['applicant'])->get(route('approvals.requests.pdf', $request))->assertOk();
        $this->assertSame(
            "attachment; filename=trial_ledger_2026-10-05.xlsx; filename*=utf-8''" . rawurlencode('試し_決裁台帳_2026-10-05.xlsx'),
            $this->actingAs($w['applicant'])->get(route('approvals.ledger.excel'))->headers->get('Content-Disposition'),
        );
    }

    /**
     * 上の余白の「試し」は本文と印を動かさない（段階6 設計書 §5.6）。文字の置き場（BT x y Td）と絵の置き場（… cm）が、上の余白の
     * 「試し」の 1 つだけ増え、ほかは同じ値・同じ順（見た目は Task 8 で確かめる。計画の点検で、部品を渡さない・本文を下げる変異で落ちることを確かめた）
     */
    public function test_the_trial_mark_does_not_move_the_body(): void
    {
        $w = $this->trialWorld();
        $r = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($r->refresh(), $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);   // 部門長の印も載せる
        $sheet  = PdfSheet::for($w['applicant'], $r->refresh());
        $top    = 841.89 - 12 * 72 / 25.4;   // A4 の高さ（pt）− margin_top 12mm。これより上が上の余白
        $places = function (string $pdf): array {
            preg_match_all('#stream\r?\n(.*?)\nendstream#s', $pdf, $streams);
            $found = [];
            foreach ($streams[1] as $stream) {
                $content = @gzuncompress($stream);   // 文字の流れだけ圧縮されている（PdfSheetTest と同じ読み方）
                if ($content !== false && preg_match_all('#BT -?[\d.]+ -?[\d.]+ Td|[-\d. ]+ cm#', $content, $m)) {
                    array_push($found, ...array_map('trim', $m[0]));
                }
            }

            return $found;
        };
        $plain = $places(ApprovalPdf::render(view('approvals.requests.pdf', ['sheet' => $sheet])->render(), view('approvals.requests._pdf_footer', ['sheet' => $sheet])->render(), 't'));
        $trial = $places(ApprovalPdf::sheet($sheet));
        $added = array_values(array_diff($trial, $plain));

        $this->assertCount(count($plain) + 1, $trial, '「試し」が 1 つ増えていない（sheet() が上の余白の部品を渡していない）');
        $this->assertCount(1, $added, '本文・印・下の文字の位置が動いた');
        $this->assertMatchesRegularExpression('#^BT [\d.]+ [\d.]+ Td$#', $added[0]);
        $this->assertGreaterThan($top, (float) explode(' ', $added[0])[2], '「試し」が上の余白（12mm）の中にない');
        $this->assertSame($plain, array_values(array_filter($trial, fn (string $op) => $op !== $added[0])), '本文・印・下の文字の位置か順が変わった');
    }

    public function test_after_launch_there_is_no_trial_mark(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Tokyo')->utc());
        $w = $this->trialWorld();
        $request = $this->submittedFor($w);
        $this->launchApprovals();

        $this->actingAs($w['applicant'])->get(route('approvals.home'))->assertDontSee(self::BANNER);
        $this->assertFalse(PdfSheet::for($w['applicant'], $request->refresh())->trial);
        $this->assertStringStartsWith('attachment; filename=ledger_2026-10-05.xlsx', $this->actingAs($w['applicant'])->get(route('approvals.ledger.excel'))->headers->get('Content-Disposition'));
    }
```

- [ ] **Step 2: テストを流して落ちることを確かめる**

Expected: FAIL（帯が無い・`PdfSheet` に `trial` が無い・Excel の名前が今のまま）。

- [ ] **Step 3: §0.6 のとおりに書く**

- `LaunchState::showsTrialBanner()`（§0.2 の表）
- 帯の部品と `layouts/app.blade.php`（フラッシュメッセージの前。コメントに「決裁の画面だけ（基幹の画面で問い合わせを増やさない）」）
- `PdfSheet`: コンストラクタに `public readonly bool $trial = false`（最後の引数・既定値つき）・`for()` で `LaunchState::inTrial()`
- `ApprovalPdf`: `render()` の新しい引数・`sheet()` で `$sheet->trial` のとき部品を渡す・mPDF の設定に `'margin_header' => 5`
- `_pdf_trial_header.blade.php`: `<div style="text-align: right; font-size: 7pt; color: #888;">試し</div>`（コメントに「本文と印の位置を変えない。margin_top 12mm の中に収まる」）
- `LedgerExcel::fileNames(bool $trial = false)`・`LedgerController::excel()` の呼び方

- [ ] **Step 4: テストを流して通ることを確かめる**

`TrialMarksTest`・`tests/Feature/Approval/Phase4/`・`tests/Feature/Approval/Phase5/PdfTableTest.php` を流す。Expected: PASS。続けて全件。Expected: `OK`。

- [ ] **Step 5: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app resources tests
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "feat: 決裁の試す期間に帯と PDF と Excel の名前へ試しの印を付ける"
```

---

## Task 5: 提出の確かめと、試せる部門・種類

**Files:**
- Create: `app/Support/Approval/TrialCoverage.php`
- Modify: `app/Support/Approval/SubmitChecker.php`
- Test: `tests/Feature/Approval/Phase6/TrialCoverageTest.php`

**Interfaces:**
- Consumes: `LaunchState::inTrial()`・`isTester()`（Task 2）・`trialWorld()`（Task 1）
- Produces: `TrialCoverage::missingForSubmit(ApprovalRequest $request, User $applicant): list<string>`・`TrialCoverage::overview(): array`（§0.7 の形。Task 6 が使う）

- [ ] **Step 1: 失敗するテストを書く**

`TrialCoverageTest`（`private const LEAD = '試す期間は、回る先の人がみな試す人のときだけ出せます。';`。外す部品 `unmakeTester(User ...$users)` をこのテストに置く＝`ApprovalMember::where('user_id', $u->id)->update(['is_tester' => false])`・`$u->unsetRelation('approvalMember')`・最後に `ApprovalSetting::forget()`〈覚えた設定が社長の関係を持っているため〉。`SubmitChecker::reasons()` には毎回 `$draft->fresh()` を渡す〈部門と部門長の関係を覚えたままにしない〉）:

```php
    public function test_in_trial_a_request_goes_out_when_everyone_on_the_route_is_a_tester(): void
    {
        $w = $this->trialWorld();

        $this->assertSame([], SubmitChecker::reasons($this->draftFor($w), $w['applicant']));
        $this->assertSame(ApprovalStatus::HeadReview, $this->submittedFor($w)->status);
    }

    public function test_each_missing_person_is_named_in_one_reason(): void
    {
        $w     = $this->trialWorld();
        $draft = $this->draftFor($w);

        $this->unmakeTester($w['head']);
        $this->assertSame([self::LEAD . "住宅事業部の部門長（{$w['head']->name}）が試す人に入っていません。"], SubmitChecker::reasons($draft->fresh(), $w['applicant']));

        $this->unmakeTester($w['reviewer'], $w['president']);
        $this->assertSame([self::LEAD . "住宅事業部の部門長（{$w['head']->name}）・審査部門「総務部」の審査の担当・社長（{$w['president']->name}）が試す人に入っていません。"], SubmitChecker::reasons($draft->fresh(), $w['applicant']));
    }

    /** 申請者が部門長本人なら部門長は見ない（要件 4.3。画面からは試す人しか出せないので本番では等価。ここで直に確かめる） */
    public function test_an_applicant_who_is_the_head_does_not_need_the_head(): void
    {
        $w = $this->trialWorld();
        $w['dept']->update(['head_user_id' => $w['applicant']->id]);
        $this->unmakeTester($w['applicant']);   // 部門長＝申請者本人を試す人から外しても、部門長としては見ない（ほかの 3 人が試す人なので試す期間のまま）

        $this->assertSame([], SubmitChecker::reasons($this->draftFor($w), $w['applicant']));
    }

    public function test_one_tester_among_the_reviewers_is_enough(): void
    {
        $w = $this->trialWorld();
        $w['reviewDept']->reviewers()->attach($this->baseUser(['name' => '試さない 審査'])->id);

        $this->assertSame([], SubmitChecker::reasons($this->draftFor($w), $w['applicant']));
    }

    public function test_the_applicant_does_not_count_as_the_tester_reviewer(): void
    {
        $w = $this->trialWorld();
        $w['reviewDept']->reviewers()->attach($w['applicant']->id);       // 申請者本人も審査担当者
        $this->unmakeTester($w['reviewer']);

        $this->assertSame([self::LEAD . '審査部門「総務部」の審査の担当が試す人に入っていません。'], SubmitChecker::reasons($this->draftFor($w), $w['applicant']));
    }

    public function test_an_unset_role_gives_only_the_reason_of_today(): void
    {
        $w = $this->trialWorld();
        $w['dept']->update(['head_user_id' => null]);

        $reasons = SubmitChecker::reasons($this->draftFor($w), $w['applicant']);
        $this->assertSame(["部門「住宅事業部」の部門長が未設定です。管理者に連絡してください。"], $reasons);
    }

    public function test_an_unset_president_gives_only_the_reason_of_today(): void
    {
        $w = $this->trialWorld();
        ApprovalSetting::current()->update(['president_user_id' => null]);
        ApprovalSetting::forget();

        $this->assertSame(['社長が未設定です。管理者に連絡してください。'], SubmitChecker::reasons($this->draftFor($w), $w['applicant']));
    }

    public function test_no_other_reviewer_gives_only_the_reason_of_today(): void
    {
        $w = $this->trialWorld();
        $w['reviewDept']->reviewers()->detach();

        $this->assertSame(['審査部門「総務部」に、申請者本人以外の審査担当者がいません。管理者に連絡してください。'], SubmitChecker::reasons($this->draftFor($w), $w['applicant']));
    }

    public function test_before_any_tester_the_route_is_not_checked(): void
    {
        $w = $this->approvalWorld();                                       // 準備中（試す人 0 人）
        $this->assertSame([], SubmitChecker::reasons($this->draftFor($w), $w['applicant']));
    }

    public function test_after_launch_the_route_is_not_checked(): void
    {
        $w = $this->approvalWorld();
        $this->makeTester($w['applicant']);
        $this->launchApprovals();

        $this->assertSame([], SubmitChecker::reasons($this->draftFor($w), $w['applicant']));
    }

    public function test_the_overview_marks_each_role_and_lists_the_combinations(): void
    {
        $w      = $this->trialWorld();
        $other  = $this->approvalDepartment($w['company'], ['name' => 'ミツワ不動産', 'code' => 'F', 'head_user_id' => $this->baseUser(['name' => '田中 次郎'])->id]);
        $land   = $this->approvalType($w['reviewDept'], ['name' => '土地の仕入れ']);
        $land->departments()->attach($other->id);                         // ミツワ不動産だけ

        $o = TrialCoverage::overview();

        $this->assertSame([$w['president']->id, true], [$o['president']['user']->id, $o['president']['ok']]);
        $this->assertSame([['住宅事業部', true], ['総務部', false], ['ミツワ不動産', false]], array_map(fn ($d) => [$d['department']->name, $d['ok']], $o['departments']));
        $this->assertSame('田中 次郎', $o['departments'][2]['head']->name);
        $this->assertSame([[$w['type']->name, true, []], ['土地の仕入れ', true, ['ミツワ不動産']]], array_map(fn ($t) => [$t['type']->name, $t['ok'], $t['limitedTo']], $o['types']));
        $this->assertSame(["住宅事業部 × {$w['type']->name}"], $o['combinations']);
    }

    public function test_no_combination_without_the_president(): void
    {
        $w = $this->trialWorld();
        $this->unmakeTester($w['president']);

        $this->assertSame([], TrialCoverage::overview()['combinations']);
    }
```

（部門の並び・`approvalDepartment()` の引数は fixture の今の形に合わせる。審査部門「総務部」は部門長がいないので `ok` は false）

- [ ] **Step 2: テストを流して落ちることを確かめる**

Expected: FAIL（`TrialCoverage` が無い・提出は断られない）。

- [ ] **Step 3: §0.7 のとおりに書く**

- `TrialCoverage`（docblock: 判定はここ 1 か所・提出の確かめと ⑬ の欄 2 が使う・審査は申請者本人を数えない＝`SubmitChecker::hasOtherReviewer()` と同じ・未設定の役は今の理由に任せる）
- `SubmitChecker::reasons()` の最後（「種類が使う欄」の後）に試す期間の確かめ。クラスの docblock の出どころに「段階6 設計書 §5.4」

- [ ] **Step 4: テストを流して通ることを確かめる**

`TrialCoverageTest`・`tests/Feature/Approval/Phase2/SubmitCheckerTest.php`・`tests/Feature/Approval/Phase6/` を流す。Expected: PASS。続けて全件。Expected: `OK`。

- [ ] **Step 5: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app tests
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "feat: 決裁の試す期間は回る先の人がみな試す人のときだけ提出できる"
```

---

## Task 6: ⑬ 使い始め（試す人と試せる部門・種類）

**Files:**
- Create: `app/Http/Controllers/Approval/LaunchController.php`・`resources/views/approvals/admin/launch.blade.php`
- Modify: `routes/approval.php`・`resources/views/layouts/partials/sidebar_approval.blade.php`・`resources/views/layouts/partials/sidebar.blade.php`・`resources/views/approvals/home.blade.php`・`resources/views/approvals/home-launched.blade.php`
- Test: `tests/Feature/Approval/Phase6/LaunchScreenTest.php`・`tests/Feature/Approval/Phase2/LaunchGateTest.php`（`OPEN_BEFORE_LAUNCH`）・`tests/Feature/Approval/ApprovalSidebarTest.php`（足す）・`tests/Feature/Approval/Phase3/MailFailureBannerTest.php`（`adminPagesBeforeLaunch()` に足す）

**Interfaces:**
- Consumes: `LaunchState::stage()`・`isTester()`・`launchedAt()`（Task 2）・`TrialCoverage::overview()`（Task 5）・`ApprovalMember::testers()`（Task 1）
- Produces: ルート `approvals.admin.launch.index`・`approvals.admin.launch.testers.store`・`approvals.admin.launch.testers.destroy`（6b が欄 3・4 とそのルートを同じ画面とコントローラに足す）

- [ ] **Step 1: 失敗するテストを書く**

`LaunchScreenTest`:

```php
    public function test_the_screen_lists_the_role_holders_and_the_testers(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin(['name' => '管理 一郎']);
        $this->makeTester($w['head']);

        $this->actingAs($admin)->get(route('approvals.admin.launch.index'))->assertOk()
            ->assertSeeInOrder(['使い始め', '試す期間', '試す人 1 人'])
            ->assertSeeInOrder([$w['president']->name, '社長', $w['head']->name, '部門長（住宅事業部）', $w['reviewer']->name, '審査の担当（総務部）', '管理 一郎', '決裁の管理者'])
            ->assertSee(route('approvals.admin.launch.testers.destroy', $w['head']));
    }

    /** 「試す人にする」「外す」は 1 回だけ送る（決裁の管理の画面の部品。PaperRecordCrudTest::test_the_save_form_is_sent_only_once と同じ形） */
    public function test_the_tester_forms_are_sent_only_once(): void
    {
        $w = $this->approvalWorld();
        $this->makeTester($w['head']);
        $html = $this->actingAs($this->approvalAdmin())->get(route('approvals.admin.launch.index'))->getContent();

        foreach ([route('approvals.admin.launch.testers.store'), route('approvals.admin.launch.testers.destroy', $w['head'])] as $action) {
            $pos = strpos($html, 'action="' . $action . '"');
            $this->assertNotFalse($pos, "{$action} のフォームが無い");
            $form = substr($html, strrpos(substr($html, 0, $pos), '<form'));
            $form = substr($form, 0, strpos($form, '</form>'));
            $open = substr($form, 0, strpos($form, '>') + 1);
            foreach (['x-data="approvalSubmitOnce()"', 'x-on:submit="onSubmit($event)"', 'x-on:pageshow.window="resetSubmit()"'] as $attribute) {
                $this->assertStringContainsString($attribute, $open, "{$action} のフォームに {$attribute} が無い");
            }
            $this->assertMatchesRegularExpression('/<button type="submit" :disabled="submitting"/', $form);
        }
        $this->assertSame(1, substr_count($html, 'function approvalSubmitOnce()'), '二度押し止めの部品がページに 1 回だけ描かれていない');
    }

    public function test_adding_and_removing_a_tester_is_recorded(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();

        $this->actingAs($admin)->post(route('approvals.admin.launch.testers.store'), ['user_id' => $w['applicant']->id])
            ->assertRedirect(route('approvals.admin.launch.index'))->assertSessionHas('success', "{$w['applicant']->name} さんを試す人にしました。");
        $this->assertTrue(LaunchState::isTester($w['applicant']->fresh()));

        $this->actingAs($admin)->delete(route('approvals.admin.launch.testers.destroy', $w['applicant']))
            ->assertRedirect(route('approvals.admin.launch.index'))->assertSessionHas('success', "{$w['applicant']->name} さんを試す人から外しました。");
        $this->assertFalse(LaunchState::isTester($w['applicant']->fresh()));

        $this->assertSame(
            [['member.tester_added', ['is_tester' => false], ['is_tester' => true]], ['member.tester_removed', ['is_tester' => true], ['is_tester' => false]]],
            ApprovalSettingLog::where('target_type', 'user')->where('target_id', $w['applicant']->id)->orderBy('id')->get()->map(fn ($l) => [$l->action, $l->old_values, $l->new_values])->all(),
        );
    }

    public function test_only_an_active_user_who_is_not_deleted_can_be_added(): void
    {
        $admin    = $this->approvalAdmin();
        $inactive = $this->approvalOnlyUser(['status' => UserStatus::Inactive->value]);
        $deleted  = tap($this->approvalOnlyUser())->delete();

        foreach ([$inactive->id, $deleted->id, 999999, 'abc'] as $id) {
            $this->actingAs($admin)->post(route('approvals.admin.launch.testers.store'), ['user_id' => $id])
                ->assertRedirect(route('approvals.admin.launch.index'))->assertSessionHasErrors('user_id');
        }
        $this->assertSame(0, ApprovalMember::where('is_tester', true)->count());

        $this->actingAs($admin)->post(route('approvals.admin.launch.testers.store'), ['user_id' => $inactive->id]);
        $this->actingAs($admin)->get(route('approvals.admin.launch.index'))->assertSee('有効な利用者を選んでください。');   // 断りの理由が画面に出る
    }

    public function test_adding_a_tester_twice_or_removing_a_non_tester_changes_nothing(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->makeTester($w['head']);

        $this->actingAs($admin)->post(route('approvals.admin.launch.testers.store'), ['user_id' => $w['head']->id])
            ->assertRedirect(route('approvals.admin.launch.index'))->assertSessionHas('error', "{$w['head']->name} さんはすでに試す人です。");
        $this->actingAs($admin)->delete(route('approvals.admin.launch.testers.destroy', $w['applicant']))
            ->assertRedirect(route('approvals.admin.launch.index'))->assertSessionHas('error', "{$w['applicant']->name} さんは試す人ではありません。");
        $this->assertSame(0, ApprovalSettingLog::whereIn('action', ['member.tester_added', 'member.tester_removed'])->count());
    }

    public function test_an_inactive_or_deleted_tester_is_listed_and_can_be_removed(): void
    {
        $admin    = $this->approvalAdmin();
        $inactive = $this->approvalOnlyUser(['name' => '無効 試す']);
        $deleted  = $this->approvalOnlyUser(['name' => '削除 試す']);
        $this->makeTester($inactive, $deleted);
        $inactive->forceFill(['status' => UserStatus::Inactive->value])->save();
        $deleted->delete();

        $this->actingAs($admin)->get(route('approvals.admin.launch.index'))
            ->assertSeeInOrder(['無効 試す', '無効'])->assertSeeInOrder(['削除 試す', '削除済み']);

        $this->actingAs($admin)->delete(route('approvals.admin.launch.testers.destroy', $deleted->id))->assertRedirect(route('approvals.admin.launch.index'));
        $this->assertFalse(ApprovalMember::where('user_id', $deleted->id)->sole()->is_tester);
    }

    public function test_the_search_finds_people_without_a_role(): void
    {
        $admin = $this->approvalAdmin();
        $found = $this->approvalOnlyUser(['name' => '鈴木 三郎', 'employee_number' => 'M0123']);
        foreach (range(1, LaunchController::SEARCH_LIMIT + 1) as $i) {
            $this->approvalOnlyUser(['name' => "佐藤 {$i}"]);
        }

        $this->actingAs($admin)->get(route('approvals.admin.launch.index', ['q' => 'M0123']))->assertSee('鈴木 三郎');
        $this->actingAs($admin)->get(route('approvals.admin.launch.index', ['q' => '佐藤']))->assertSee('20 人まで出しています。絞り込んでください。');
        $this->actingAs($admin)->get(route('approvals.admin.launch.index', ['q' => ['佐藤']]))->assertOk();   // 配列でも 500 にしない
    }

    public function test_the_coverage_shows_the_marks_and_the_missing_people(): void
    {
        $w = $this->trialWorld();
        $this->approvalDepartment($w['company'], ['name' => 'ミツワ不動産', 'code' => 'F', 'head_user_id' => $this->baseUser(['name' => '田中 次郎'])->id]);

        $this->actingAs($this->approvalAdmin())->get(route('approvals.admin.launch.index'))
            ->assertSeeInOrder(['試せる部門と種類', 'ミツワ不動産', '田中 次郎', 'いま試せる組み合わせ', "住宅事業部 × {$w['type']->name}"]);
    }

    public function test_after_launch_the_testers_cannot_be_changed(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->makeTester($w['head']);
        $this->launchApprovals();

        $this->actingAs($admin)->get(route('approvals.admin.launch.index'))->assertSee('使い始めました（')->assertDontSee('試す人にする');
        $this->actingAs($admin)->post(route('approvals.admin.launch.testers.store'), ['user_id' => $w['applicant']->id])
            ->assertSessionHas('error', '使い始めたあとは試す人を変えられません。');
        $this->actingAs($admin)->delete(route('approvals.admin.launch.testers.destroy', $w['head']))
            ->assertSessionHas('error', '使い始めたあとは試す人を変えられません。');
        $this->assertSame([$w['head']->id], ApprovalMember::where('is_tester', true)->pluck('user_id')->all());
    }
```

`ApprovalSidebarTest` に足す（このファイルの `approvalAdmin(string $role)`・`sidebars()`・`assertHasLink()` を使う。ホームの本文のリンクは `<main>` だけを見る＝ページ全体ではサイドバーのリンクで緑になる）:

```php
    /** 使い始め（⑬）は準備中から決裁の管理者に出す（段階6 設計書 §5.9）。サイドバー 2 つ（展開・引き出し）と決裁のホーム 2 つの本文 */
    public function test_the_launch_link_is_offered_to_admins_before_launch(): void
    {
        $launch = route('approvals.admin.launch.index');
        $admin  = $this->approvalAdmin(UserRole::ApprovalOnly->value);

        $html = $this->actingAs($admin)->get(route('approvals.home'))->assertOk()->assertViewIs('approvals.home')->getContent();
        foreach (['expanded', 'drawer'] as $key) {
            $this->assertHasLink($this->sidebars($html)[$key], $launch, '使い始め', $key);
        }
        $this->assertHasLink($this->mainOf($html), $launch, '使い始め', '準備中のホームの本文');

        $sidebars = $this->sidebars($this->actingAs($this->approvalAdmin())->get('/dashboard/tenant')->assertOk()->getContent());
        foreach (['expanded', 'drawer'] as $key) {
            $this->assertHasLink($sidebars[$key], $launch, '使い始め', $key);
        }

        ApprovalSetting::current()->update(['launched_at' => now()]);
        $html = $this->actingAs($admin)->get(route('approvals.home'))->assertOk()->assertViewIs('approvals.home-launched')->getContent();
        $this->assertHasLink($this->mainOf($html), $launch, '使い始め', '使い始めたあとのホームの本文');

        $plain = User::factory()->approvalOnly()->create(['must_change_password' => false]);
        $this->assertStringNotContainsString($launch, $this->actingAs($plain)->get(route('approvals.home'))->assertOk()->getContent(), '管理者でない人に使い始めが出ている');
    }

    /** サイドバーを除いた本文（<main>） */
    private function mainOf(string $html): string
    {
        $this->assertSame(1, preg_match('#<main\b[^>]*>.*?</main>#s', $html, $m), '<main> が 1 つに定まらない');

        return $m[0];
    }
```

`MailFailureBannerTest::adminPagesBeforeLaunch()` の最後に `route('approvals.admin.launch.index'),` を足す（⑬ にもメールの失敗の帯が出ることを見る）。

`LaunchGateTest::OPEN_BEFORE_LAUNCH` に §0.8 の 1 行を足す（足すまで `test_every_approval_route_is_classified` が赤＝期待どおり）。

- [ ] **Step 2: テストを流して落ちることを確かめる**

Expected: FAIL（ルートが無い）。

- [ ] **Step 3: ルート・コントローラ・画面・リンクを書く**（§0.8）

- 画面はほかの管理の画面（`approvals/admin/holidays.blade.php`）と同じ枠・パンくず（`決裁申請 › 使い始め`＝今の管理の画面と同じ）・見出し・`_mail_failure` の帯・入力の断りの赤枠（`holidays.blade.php` の `@if($errors->any())` の塊と同じ。見出しの下。無いと `ValidationErrorFeedbackTest` が赤）。スマホの幅で 1 列（ボタンは行の右、狭いときは下に回す）。表は横スクロールの枠の中に置く（`MobileLayoutTest::test_every_table_has_a_horizontally_scrollable_ancestor`）
- 欄 2 の ○／× は文字（`○`・`×`）と色（緑・赤）の両方で示す（色だけに頼らない）
- 検索のフォームは `<form method="GET">`（`q`。ラベル「名前か社員番号で探す」）

- [ ] **Step 4: テストを流して通ることを確かめる**

`LaunchScreenTest`・`LaunchGateTest`・`ApprovalSidebarTest`・`MailFailureBannerTest`・`tests/Feature/Approval/ApprovalAdminGateTest.php`（新しいルートも全件分類に入る）・`tests/Feature/ValidationErrorFeedbackTest.php`（あれば。場所は grep で探す）・`MobileLayoutTest` を流す。Expected: PASS。続けて全件。Expected: `OK`。

- [ ] **Step 5: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app resources routes tests
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "feat: 決裁の管理に使い始めの画面を足し試す人を選べるようにする"
```

---

## Task 7: 全件テストと変異テスト（Bug #44 の作法）

- [ ] **Step 1: 全件**（Task 0 の流し方）。Expected: `OK`（N ＋ 足したテストの数）。本数を台帳に控える

- [ ] **Step 2: 変異**（WT のソースは壊さない。scratchpad に WT の HEAD を `git archive` で写し、vendor を `cp -Rc` で写して、そこで 1 つずつ壊して流す。道具は `~/.claude/plans/approval-phase3-tasks/5b/mutate.py`・`run-mut.sh` を `6a/` に写して直す）。**落ちたテストの名前まで**下の表と突き合わせる（赤か緑かだけで済ませない）

「落ちるはずのテスト」は**少なくとも落ちるもの**（ほかが落ちてもよい。挙げたものが 1 本でも緑なら生き残り）。G・B は計画の点検で写しに当てて測った名前（Task 4 以降のテストを足すと、G01・G05 では `TrialMarksTest` の 2 本も落ちる見込み）。

| ID | 壊し方 | 落ちるはずのテスト |
|---|---|---|
| G01 | `LaunchState::opensFor()` を `launched()` だけにする | `TrialAccessTest::test_in_trial_a_tester_gets_the_launched_home_and_the_links`・`test_in_trial_the_menu_count_and_the_bell_are_only_for_testers`・`LaunchGateTest::test_in_trial_a_tester_passes_the_gate`・`test_in_trial_a_non_tester_gets_the_same_answers_for_a_real_request` |
| G07 | `LaunchState::opensFor()` を `launched() \|\| inTrial()` にする（試す期間に試す人でない人も通す） | `TrialAccessTest::test_in_trial_a_non_tester_keeps_the_preparing_home`・`test_in_trial_the_menu_count_and_the_bell_are_only_for_testers`・`LaunchGateTest::test_in_trial_a_non_tester_gets_the_same_answers_as_before_launch`・`test_in_trial_a_non_tester_gets_the_same_answers_for_a_real_request` |
| G02 | `isTester()` の `status` の条件を消す | `TrialAccessTest::test_an_inactive_or_deleted_tester_is_not_a_tester` |
| G03 | `isTester()` の `trashed()` の条件を消す | `TrialAccessTest::test_an_inactive_or_deleted_tester_is_not_a_tester` |
| G04 | `scopeTesters()` の有効な利用者の条件を消す | `Phase6aColumnsTest::test_the_testers_are_active_people_who_are_not_deleted`・`TrialAccessTest::test_the_stage_follows_the_launch_and_the_testers`・`test_an_inactive_or_deleted_tester_is_not_a_tester` |
| G05 | `EnsureApprovalLaunched` を `LaunchState::launched()` に戻す | `LaunchGateTest::test_in_trial_a_tester_passes_the_gate`・`test_in_trial_a_non_tester_gets_the_same_answers_for_a_real_request` |
| G06 | `ApprovalMenu::pendingCount()` で試す人を見ない | `TrialAccessTest::test_in_trial_the_menu_count_and_the_bell_are_only_for_testers`・`ApprovalSidebarTest::test_in_trial_the_request_links_are_only_for_testers` |
| X01 | `sidebar_approval.blade.php` の `$approvalsOpen` を `LaunchState::launched()` にする | `ApprovalSidebarTest::test_in_trial_the_request_links_are_only_for_testers` |
| X02 | `sidebar.blade.php` の `$approvalsOpen` を `LaunchState::launched()` にする | `ApprovalSidebarTest::test_in_trial_the_request_links_are_only_for_testers` |
| N01 | `Notifier::receives()` の試す人の条件を消す | `TrialNoticeTest::test_in_trial_only_testers_get_notices_and_mails`・`test_a_new_handler_who_is_not_a_tester_gets_nothing`・`test_an_applicant_removed_from_the_testers_gets_nothing`（ほかに `NotifierTest::test_nothing_is_made_before_launch` と、使い始める前に提出してから使い始める `NotifierTest` のテスト 10 本ほども落ちる＝準備中の守りが `receives()` だけになったため。期待どおり） |
| N02 | `Notifier` で `asTrial()` を付けない | `TrialNoticeTest::test_in_trial_only_testers_get_notices_and_mails` |
| N03 | `subjectLine()` で `TRIAL_MARK` を付けない | `TrialNoticeTest::test_the_mark_is_put_in_one_place_for_both_mails`（ほかに `test_in_trial_only_testers_get_notices_and_mails`・`ApprovalRemindCommandTest::test_in_trial_only_testers_are_reminded` も落ちる。期待どおり） |
| R01 | 催促で試す人に絞らない | `ApprovalRemindCommandTest::test_in_trial_only_testers_are_reminded` |
| R02 | 催促で `asTrial()` を付けない | `ApprovalRemindCommandTest::test_in_trial_only_testers_are_reminded` |
| M01 | `showsTrialBanner()` の `inTrial()` を `! launched()` にする | `TrialMarksTest::test_the_banner_is_on_the_admin_screens_only_in_trial` |
| M02 | `PdfSheet::for()` の `trial` をいつも false | `TrialMarksTest::test_the_pdf_and_the_excel_are_marked_in_trial` |
| M03 | `LedgerController::excel()` で `fileNames()` に試しを渡さない | `TrialMarksTest::test_the_pdf_and_the_excel_are_marked_in_trial` |
| M04 | `ApprovalPdf::sheet()` で上の余白の部品を渡さない | `TrialMarksTest::test_the_trial_mark_does_not_move_the_body` |
| M05 | 上の余白があるときだけ `margin_top` を 20 にする | `TrialMarksTest::test_the_trial_mark_does_not_move_the_body` |
| M06 | `layouts/app.blade.php` の `routeIs('approvals.*')` を外す（帯の部品をいつも読む） | `TrialMarksTest::test_the_banner_is_on_the_request_screens_for_a_tester` |
| S01 | `missingForSubmit()` の「部門長が申請者本人なら見ない」を消す | `TrialCoverageTest::test_an_applicant_who_is_the_head_does_not_need_the_head`（本番の振る舞いでは等価＝画面から出す申請者はいつも試す人。テストは直に呼んで確かめる） |
| S02 | 審査の担当から申請者本人を除かない | `TrialCoverageTest::test_the_applicant_does_not_count_as_the_tester_reviewer` |
| S03a | `missingForSubmit()` の社長の確かめを消す | `TrialCoverageTest::test_each_missing_person_is_named_in_one_reason` |
| S03b | `overview()` の組み合わせで社長の `ok` を見ない | `TrialCoverageTest::test_no_combination_without_the_president` |
| S04 | `SubmitChecker` の `inTrial()` を `! launched()` にする | `TrialCoverageTest::test_before_any_tester_the_route_is_not_checked` と、使い始める前に提出している今のテスト多数 |
| S05 | 審査の「申請者本人以外の有効な審査担当者が 1 人以上いて」を消す | `TrialCoverageTest::test_no_other_reviewer_gives_only_the_reason_of_today` |
| S06 | 社長の「決まっていて」を消す | `TrialCoverageTest::test_an_unset_president_gives_only_the_reason_of_today`（エラー） |
| B01 | `setNext()` で `next_without_trials` を入れない | `TrialNumberBaseTest::test_the_start_number_sets_both`・`test_a_past_year_paper_number_leaves_it` |
| B02 | `advancePast()` で `next_without_trials` を `next_number` と一緒に比べる | `TrialNumberBaseTest::test_a_paper_number_moves_both_forward_but_never_back` |
| B03 | `issue()` で `next_without_trials` も進める | `TrialNumberBaseTest::test_issuing_moves_only_the_next_number`・`test_the_start_number_sets_both`・`test_a_paper_number_moves_both_forward_but_never_back` |
| L01 | `addTester()` の使い始めたあとの断りを消す | `LaunchScreenTest::test_after_launch_the_testers_cannot_be_changed` |
| L02 | `addTester()` で無効の人を断らない | `LaunchScreenTest::test_only_an_active_user_who_is_not_deleted_can_be_added` |
| L03 | 設定の記録を書かない | `LaunchScreenTest::test_adding_and_removing_a_tester_is_recorded` |
| L04 | 削除の `->withTrashed()` を外す | `LaunchScreenTest::test_an_inactive_or_deleted_tester_is_listed_and_can_be_removed` |
| L05 | `removeTester()` の使い始めたあとの断りを消す | `LaunchScreenTest::test_after_launch_the_testers_cannot_be_changed` |
| L06 | `addTester()` の検査の戻り先（`redirectTo`）を外す | `LaunchScreenTest::test_only_an_active_user_who_is_not_deleted_can_be_added` |

生き残りがあれば、テストを足して（実装の担当に戻す）流し直す。等価（壊しても振る舞いが変わらない）なら理由を書く。結果は `~/.claude/plans/approval-phase3-tasks/6a/measure/` に置き、計画の末尾に「Task 7 の実測記録」として足してコミットする（`docs: 決裁 段階6a の全件と変異の結果を記録する`）。

---

## Task 8: 手元のブラウザでの確認と写真（設計書 §6 の最後）

使い捨ての SQLite ＋ `artisan serve`（PHP 8.3）・Playwright。ログインは記憶「手元の画面にログイン」の作法（試しのパスワードを出さない）。写真は `/Users/masanori/site/approval/screenshots-6a/`。**スマホの幅（375）と PC の幅**で:

| # | 見るもの | 期待 |
|---|---|---|
| 1 | 試す人（申請者）のホーム | 帯が上に出る・「新しい申請」がある・帯がスマホで 1〜2 行 |
| 2 | 試す人でない人のホーム | 「準備中」のまま・帯なし |
| 3 | 試す人でない決裁の管理者の管理の画面 | 帯が出る |
| 4 | ⑬（試す人 0 人） | 状態「準備中」・役のある人の一覧・「試す人にする」 |
| 5 | ⑬ で 4 人を試す人にした後 | 状態「試す期間」・欄 2 の ○／×・いま試せる組み合わせ・成功の文 |
| 6 | ⑬ の検索 | 役の無い人が出て足せる |
| 7 | 部門長が試す人でない部門で提出 | 断りの文（足りない人の名前） |
| 8 | 提出から社長の決裁まで | 各段の人にお知らせ・PDF の上の余白に「試し」（本文・印の位置は使い始めたあとの PDF と同じ）・Excel の名前 |
| 9 | 「試す人にする」の二度押し | 1 回だけ効く |
| 10 | 使い始めた状態（手で `launched_at` を入れる） | 帯なし・⑬ は「使い始めました」だけ・PDF に「試し」なし |

- [ ] 1〜10 を確かめ、写真を撮る（手引きの読みやすい版に使う）。期待と違えば実装の担当に戻す
- [ ] 結果を計画の末尾に「Task 8 の実測記録」として足してコミットする（`docs: 決裁 段階6a の画面の確認の結果を記録する`）

---

## Task 9: ドキュメント

- [ ] **Step 1: 受け入れ確認の手引き** `docs/運用_受け入れ確認の手引き.md`（設計書 §5.10 のうち 6a の分。運用手順書 `docs/運用_過去の決裁の取り込み.md` と同じ書きぶり＝利用者が読む）:
  - 試す期間の始め方（⑦ で試す人のアカウントを用意 → 部門長・審査の担当を登録・社長は基幹の管理者が基幹の利用者の管理で指定〈要件 13 の注〉→ ⑬ で試す人にする → 欄 2 で試せる組み合わせを確かめる）
  - 役ごとにスマホで試すこと（申請者・部門長・審査の担当・社長・決裁の管理者。差戻し・条可・取り下げ・押し間違いの取り消し・付け替えを含む）。付け替える先・交代した社長も試す人にしておく（試す人でない人に回ると、その申請はそこで止まる。設計書 §5.4）。試す人から外した人は画面が見えなくなる（その人の試しの申請は残る。§5.3）
  - メールと催促（件名の【試し】・3 日待つと平日の朝 9 時に催促・試す期間の長さの目安）
  - 本番でしか確かめられないこと（設計書 §5.10 の 6 つ。php.ini を直すときの手順は要件 16.3 の 15 の場所を書く）
  - 利用者に伝えること（BACKLOG の段階5a「利用者に伝えること（受け入れ確認のとき）」の 3 つ）
  - **試す期間には本物の大事な書類を添付しない**（片付けても夜間バックアップの保管先に暗号化した形で残る。D15）
  - 試しの申請と試しで進んだ決裁No は、6b（片付け）が届くまで残ること。片付け・社員の一括登録・紙の台帳の取り込み・使い始めるの順（§1.1）は 6b で書き足すこと
- [ ] **Step 2: 手引きの読みやすい版**（新しい artifact。元は `~/site/approval/acceptance-guide/acceptance-guide.html`＋`img/`〈Task 8 の写真を切り出す〉。取り込みの手引きの読みやすい版と同じ形。publish の前に `artifact-design` を読む。URL を BACKLOG と CLAUDE.md と記憶に書く）
- [ ] **Step 3: 紙の取り込みの運用手順書** の 16 行目・133 行目（設計書 §5.10 のとおり）と、その読みやすい版（同じパスで直して同じ URL へ publish）:
  - 16 行目は「試す期間は、試す人のうちその決裁を見られる人には、決裁台帳と紙の決裁の詳細でも見えます」（紙の決裁の見られる範囲は役ごと＝`RequestVisibility::constrainPaper()`。「試す人には」だけだと全員に見えると読める）
  - 133 行目は「原因」の列（今「使い始めたあとに取り込んだ」）を「試す期間に、試しの申請が番号を使った（または使い始めたあとに取り込んだ）」に、対処の列に「紙の台帳は試しの申請を片付けてから取り込む〈片付けは段階6b で ⑬ に足す〉」
  - 85 行目「4. 使い始めてから、決裁台帳の「区分」を「紙」に…」は、試す期間は試す人が確かめられることを書き添える（任意）
- [ ] **Step 4: 要件定義書 v1.16**（§0.9。0 章の決定の出どころに v1.16 の 1 文・変更履歴）と、その読みやすい版（同じ URL・道具は `~/.claude/plans/approval-phase3-tasks/work/req115/` を `req116/` に写して直す）
- [ ] **Step 5: BACKLOG と CLAUDE.md**（BACKLOG に段階6 の節〈6a の中身・6b に回したもの・利用者に伝えること＝§0.1 の最後・段階3 設計書 §5.2 の申し送り〈Notifier と催促の「使い始めたか」の判定の見直し〉を閉じたこと〉。CLAUDE.md の決裁の注意に「使い始めたかは `LaunchState` から読む（走査のテストが止める）」「試す期間の印と提出の確かめは試す期間だけ」「`next_without_trials` は `ApprovalNumber` だけが動かす」）
- [ ] **Step 6: コミット**（関心ごとに分ける: 手引き／取り込みの手順書／要件定義書／BACKLOG と CLAUDE.md）。`git merge-tree --write-tree --name-only approval-phase3 13.x` で衝突が無いことを確かめる

---

## Task 10: 本番反映（利用者の了承を取ってから・親の会話が行う）

> ⚠ **サブエージェントに任せない。** 本番への `ssh`・`scp`・`./deploy.sh` は、それぞれ利用者の了承を取ってから行う。`.env` は読まない。本番のシェルは csh なので `/bin/sh` の heredoc を `ssh` に流し、PHP は `/usr/local/php/8.3/bin/php` を明示する（5b と同じ）。
> ⚠ 反映の前に manage の会話（`local_a4a698e6`）へ `SendMessage` で、反映が済むまで `13.x` の早送りと `./deploy.sh` を待ってもらう。

- [ ] **Step 1: 了承を求める（選択式）** — 伝えること: ①DB が先・`./deploy.sh` が後 ②表 2 つに列を 1 つずつ足し、番号の表の新しい列を今の次の番号にそろえる（今のデータは変えない）③部品の追加は無い ④**出しただけでは何も変わらない**（試す人 0 人＝準備中のまま。変わるのは決裁の管理者のサイドバーの「使い始め」と ⑬ だけ）⑤試し始めるのは利用者が ⑬ で試す人を選んだとき。6a の期間の試しの申請と試しで進んだ番号は 6b の片付けまで残る ⑥戻すときは `13.x` の前のコミットで `./deploy.sh`（足した列は残しても害は無い。戻したあとにもう一度出すときは、出す前に SQL の 3 を流し直す＝戻していたあいだに古いコードが動かした開始番号・紙の番号を拾う。試しの採番が始まる前に限る）

- [ ] **Step 2: 反映前に本番を読み取る**（5b の Task 12 Step 2 と同じ形）: `approvals=` の数（6a で 3 本増える）／ `approval_members` に `is_tester`・`approval_number_sequences` に `next_without_trials` が**無い** ／ `launched_at=NULL` ／ **`approval_requests` の `number` が入った行が 0**（SQL の 3 が正しいための条件。0 でなければ止まる）／ 連番の行の数と `next_number`・`last_issued` の一覧（`last_issued` がすべて 0）／ `laravel.log` の日付

- [ ] **Step 3: `13.x` へ早送りで取り込む**（5b と同じ。進んでいたら止まって利用者に選んでもらう）

- [ ] **Step 4: DB を先に変える**（5b の Task 12 Step 4 の (a)〜(d) と同じ形で `2026-10-27-approval-phase6a.sql` を。流す前の確かめは「2 つの列のどちらかがすでにあれば 1 文も流さずに止まる」。`statements=3`・`OK 1`〜`OK 3`（3 は `DB::affectingStatement()` で流して変えた行の数を出し、Step 2 で控えた連番の行の数と同じことを見る）。流した後に `SHOW FULL COLUMNS` で 2 つの列の型・NULL・既定と、連番の行の `next_without_trials = next_number` を確かめる）

- [ ] **Step 5: 読み込みの表を作り直す**（main repo で `dump-autoload --no-dev --optimize`。新しいクラス `LaunchState`・`TrialCoverage`・`LaunchController`・`ApprovalLaunchStage` の 4 つが classmap に入る＝`grep -c` で 4）

- [ ] **Step 6: `./deploy.sh`**（exit 0）

- [ ] **Step 7: 本番で確かめる**（読み取り）: ビューの lint `invalid=0` ／ `approvals=` が Step 2 より 3 多い ／ `launched_at=NULL` ／ 4 つのクラスが `ok` ／ `App\Support\Approval\LaunchState::stage()->value` が `preparing` ／ `App\Models\ApprovalMember::testers()->count()` が 0 ／ `DB::table('approval_number_sequences')->whereColumn('next_without_trials', '!=', 'next_number')->count()` が 0（Step 4〜6 のあいだに古いコードが開始番号か紙の番号で `next_number` だけを動かしていないか。0 でなければ、番号を付けた申請が 0 件・試す人が 0 人なのを確かめてから SQL の 3 をもう一度流す）／ `laravel.log` の日付が Step 2 と同じ

- [ ] **Step 8: 利用者の Chrome で見るだけ**（決裁の管理者でログイン済みの画面。URL に `index.php/` が要る。**ボタンは押さない**）: サイドバーの「使い始め」→ ⑬ に「準備中」・役のある人の一覧・欄 2・帯は無い ／ 決裁のホームは「準備中」のまま ／ コンソールのエラー 0

- [ ] **Step 9: 記録**（BACKLOG の段階6 の節に本番反映の表を足してコミット `docs: 決裁 段階6a を本番に出した記録を残す`。manage の会話に済んだと 13.x の sha を知らせる。push は利用者の指示があってから）。試し始める日（⑬ で試す人を選ぶ日）は利用者が決める

---

## Task 8 の実測記録（2026-10-27・会話 ab2e5ae8）

- 環境: WT の HEAD `8c82d8fc` の `git archive` の写し（`vendor` は WT から複製・`node_modules` は symlink で `vite build`）・使い捨ての SQLite・`artisan serve` 8773・Playwright。設定は `APP_ENV=local`・`APP_DEBUG=false`・乱数の `APP_KEY` と試しのパスワード・`MAIL_MAILER=log`・`QUEUE_CONNECTION=sync`（`.env` 系は読んでいない・作っていない）。試しのデータは `~/.claude/plans/approval-phase3-tasks/6a/seed.php`（テストの土台 `BuildsApprovalFixtures` を使う。部門 4・種類 2・人 10。名前・社員番号・メールはみな作りもの。始めは試す人 0 人）。ログインは記憶「手元の画面にログイン」の作法（パスワードは画面・記録に出していない。public の一時ファイルはすぐ消した）。「使い始めた状態」は写しの SQLite に `launched_at` を手で入れた
- **見ることの表 1〜10 はすべて期待どおり**（止めるべき不具合は無し）:

| # | 見るもの | 結果（要点） |
|---|---|---|
| 1 | 試す人のホーム | 帯が見出しより上（375 は 2 行・1440 は 1 行）・「新しい申請」あり |
| 2 | 試す人でない人のホーム（決裁のみ利用者・基幹の利用者） | 「決裁の機能は準備中です。…」・帯なし |
| 3 | 試す人でない決裁の管理者の管理の画面 7 つ | どれも帯が出る（375 は 2 行・1440 は 1 行） |
| 4 | ⑬（試す人 0 人） | 「準備中」・試す人 0 人・役のある人 6 人（社長 → 部門長 → 審査の担当 → 決裁の管理者）にみな「試す人にする」・欄 2 はみな ×・「まだありません」・帯なし |
| 5 | ⑬ で 4 人を試す人にした後 | 「試す期間」・試す人 4 人・欄 2 の ○／×（未設定・試す人でない部門長・使える部門の限り）・「いま試せる組み合わせ」に `住宅事業部 × 購入・発注`・成功の文 |
| 6 | ⑬ の検索 | 役の無い人が出て足せる・足した人は役の無い試す人として一覧に出る |
| 7 | 部門長が試す人でない部門で提出 | 断りの文が Global Constraints の文言どおり・中身は下書きに残る |
| 8 | 提出から社長の決裁まで | 各段の人にお知らせ・ベル・対応待ち。試す人でない人には 1 件も無い。メール 5 通の件名がみな `【試し】【決裁】…`。社長の「可」で `R8-J-001` |
| 9 | 「試す人にする」「外す」の二度押し | どれも送信 1 回・設定の記録 1 件。押した瞬間に押せなくなり「送っています…」 |
| 10 | 使い始めた状態 | 帯なし・⑬ は「使い始めました（日時）。」（日本時間）・試す人でない人にも使い始めたホーム |

- PDF: 試す期間と使い始めたあとの同じ申請の PDF（A4・1 ページ）を画像にして画素で比べた。違うのは上の余白の右端の「試し」と足の出力時刻の 1 文字だけで、**本文・枠・3 つの印・足の位置は画素まで同じ**（PDF を開くアプリの画面操作はしていない）
- Excel: 試す期間は `試し_決裁台帳_2026-10-27.xlsx`（代わりの名前 `trial_ledger_2026-10-27.xlsx`）、使い始めたあとは `決裁台帳_2026-10-27.xlsx`（中身は開いていない）
- はみ出し: 見た画面はすべて `main.scrollWidth === main.clientWidth`（375・1440）。コンソールのエラーと警告 0 件。コンパイル済みビュー 315 本を `php -l` → INVALID 0 件
- ついでに見たこと（Review Focus 2）: 試す期間に、試す人でない人が実在する試しの申請・その PDF・存在しない ID を開くと、どれもホームへ
- 見つけた 3 点（どれも Minor。直しは `5eaf8d8c`「fix: 決裁の使い始めの画面とホームの試す期間の見え方を直す」）:
  1. 375 の ⑬ 欄 2 の表で、○／× の列が横スクロールの先に隠れた → 2 つの表を 2 列にした（会社名と「全部門／…だけ」は名前の下に小さく）
  2. 試す期間に、試す人でない決裁の管理者のホームが「帯（試しの期間です）＋本文（準備中です）」で食い違って見えた → 本文を「いまは試す期間です。申請の画面は試す人にだけ見えています（試す人は「使い始め」で選べます）。」にした（試す人でも管理者でもない人は今の文のまま）
  3. 使い始めたあとの ⑬ の見出しに「試す人 N 人」が残った → 使い始めたあとは出さない
- 撮り直し（`5eaf8d8c`）: 同じ作法・同じ試しのデータで、3 点の直しに関わる画面を撮り直した（16 枚を上書き）。欄 2 の表の枠は 375 でも `scrollWidth <= clientWidth`（「← スクロールできます →」は出ない）・管理者のホームの本文は直した文・試す人でも管理者でもない人のホームは今の文のまま・使い始めたあとの ⑬ に試す人の数は出ない。はみ出し 0・コンソール 0・lint INVALID 0
- 写真 54 枚: `/Users/masanori/site/approval/screenshots-6a/`（リポジトリの外。受け入れ確認の手引きの読みやすい版に 12 枚を切り出して使った）。詳しいまとめは `~/.claude/plans/approval-phase3-tasks/6a/measure/task8-summary.md`
