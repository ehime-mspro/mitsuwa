# 決裁申請 段階3（3a: 操作のたびのお知らせとメール・ベル・お知らせ一覧・ホームのお知らせ・送れないときの表示）実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 段階3 の前半（3a）— 申請の操作のたびに、届くべき人にだけお知らせ（画面）とメールを 1 回ずつ出し、ヘッダーのベル・お知らせ一覧（⑥）・ホームの「新しいお知らせ」で見られるようにし、メールが送れないときは決裁の管理者の画面に黄色の帯を出す — を作り、使い始めるまで何も見えず何も届かないまま本番へ出す。

**Architecture:** 知らせを出すのは `App\Support\Approval\Notifier` の 1 か所（場面ごとに宛先を決め、共通の決まり 7 つで絞り、`notifications` の行とキューのメールを作る）。呼ぶのは状態を変える `Workflow` と、Workflow の外で担当が変わる 2 か所（部門の管理の審査担当者・基幹の社長の指定）で、どれも操作と同じトランザクションの中。段階の今の担当は新しい部品 `StepHandlers`（権限の `RequestPermissions`・対応待ちの `PendingWork` と同じ人を返すことを突き合わせで守る）、文言は `NoticeText` の 1 か所、メールは土台 `ApprovalMailable`（送り直し 3 回・送れた／送れなかったの記録）の上に作る。始めに 2a・2b の丸写し（詳細の画面へ戻す形）と「有効な審査担当者」の条件を 1 か所に寄せる。

**Tech Stack:** Laravel 12 / PHP 8.3（本番 8.3.32・手元 8.3.35）/ MySQL 8（本番）・SQLite（テスト）/ Blade + Alpine.js 3 + Tailwind v4 / PHPUnit 11

**Spec:** 設計書 @docs/superpowers/specs/2026-09-30-approval-phase3-design.md（この計画は §1.2・§4・§5.1〜§5.7・§6・§7 の **3a** の部分。3b〈催促・祝日・⑫〉は別の計画）。要件定義書 @docs/決裁申請_要件定義書_v1.md（v1.11 の 8 章・13 章の ⑥・15.3・15.5）。2b の計画 @docs/superpowers/plans/2026-09-28-approval-phase2b.md（§0.1 作り方・§0.15 テストの土台は 3a でもそのまま効く）。

## Global Constraints

- 使い始める前（`approval_settings.launched_at` が空）は、知らせを作らない・メールを積まない・ベルを出さない（段階3 設計書 §5.2）。⑥ は門番 `approval.launched` の内側。基幹の画面に「決裁」の文字を出さない（`ApprovalSidebarTest`）。基幹の画面と管理の画面は設定の行を作らずに読む（`ApprovalSetting::launchedForMenu()`）
- 知らせを出すのは `Notifier` だけ。**呼ぶ側のトランザクションの中で呼ぶ**（お知らせの行とメールの `jobs` の行が操作と一緒に巻き戻る。`QUEUE_CONNECTION=database` が前提。§5.5）
- 宛先・文・リンクは操作の時点で決めてメールに持たせる。キューの中で `route()`・`config('app.url')`・`ApprovalSetting::current()` を読まない（本番で `/index.php` が抜ける・長く動くキュー処理で古い設定を読む。§4.2・§4.3）
- メールはテキストだけ。件名の頭は「【決裁】」、差出人の名前は「ミツワ都市開発 決裁システム」（アドレスは今の `MAIL_FROM_ADDRESS`。D12）。書くのは件名・申請者（申請部門）・決裁No・必要な対応・リンクだけ（金額・本文・添付・コメントは書かない。要件 8.2）。Laravel 標準の通知メールの雛形は使わない（Top trap #10）
- 送り直しは 3 回・60 秒あけ（パスワード再発行のメールも。D6）
- お知らせは消さない（D16）。引くときは必ず持ち主で絞る（`ApprovalNotice::ownedBy()`）。ほかの人のお知らせの ID は 404
- 表は `notifications`（新）と `approval_settings` の 3 列だけ。本番は SQL を手で流し、テストは同じ形の migration（`Phase3TablesTest` が突き合わせる）
- 保存した日時は `JapanTime::format()` で出す（Bug #61）。TIMESTAMP 列への保存は `now()`（`ClockReadScanTest` に理由つきで登録）
- `x-data` に矢印関数を書かない（Top trap #4）。ページ送りに `->links()` を使わない（Bug #24）。操作のあとの戻り先は固定のルート（Bug #64）
- 画面の帯は `success` と `error` の 2 つ（2a §0.11）と、決裁の管理者にだけ出す D2 の黄色の帯
- テストは worktree で `APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`。main repo では作業もテストもしない。worktree に `.env` を作らない（`.env*` は読まない・grep しない）
- コミットは Conventional Commits・日本語の件名（72 文字以内・句点なし）・1 コミット 1 関心事・本文の最後に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`。`--no-verify`・`--amend`・`git reset` でのやり直し・`git stash` は使わない。push は利用者が指示したときだけ

## Review Focus

設計書が触れていないが、使う人がいちばん出会いそうな場面（どの場面のテストも見ていなかったもの）。計画を書く段階で 5 つとも試作にテストを足し、コードを 1 か所壊すとそのテストが落ちることを確かめた（変異。Task 9 の表の ID）。

1. **件名に「&」「<」「"」を含む申請** → メールの件名と本文は打ったとおりに届き（`&amp;` にならない）、⑥ とホームでは HTML にならずに文字として出る（Task 4 の `test_the_handler_gets_a_notice_and_a_mail`・`test_the_mail_says_only_what_the_requirements_allow`、Task 7 の `test_the_notice_text_is_escaped`。変異 N13・V11）
2. **ある人が詳細を開いたとき、同じ申請へのほかの人の未読** → そのまま残る（開いた本人のその申請の未読だけ既読。別の申請の未読も残る）（Task 7 の `test_opening_the_request_marks_only_my_notices_of_that_request_read`。変異 V02・V03）
3. **⑥ の範囲の外のページ（`?page=99`・`?page=abc`）** → 500 にならず、「お知らせはありません。」かいちばん新しいページ（Task 7 の `test_the_list_pages_by_twenty`）
4. **件名に改行を入れて送った申請（細工した送信）** → メールの件名は 1 行に収まる（改行は空白に）（Task 4 の `test_control_characters_in_the_subject_become_spaces`。変異 N10）
5. **本番の形の URL（`/system/manage/index.php/…`）で画面から操作** → メールのリンクにも `/index.php` が入る（キューの中で作らない）（Task 5 の `test_the_mail_link_keeps_the_front_controller_of_the_request`。変異 N11）

---

## 0. 計画を書く段階で決めたこと（設計書 §9 の宿題のうち 3a の分）

### 0.1 作り方（試作を先に作って確かめた）

- この計画のコードは、scratchpad の試作（`approval-phase3` = `73fc9939` の写し）で Task ごとにコミットし、**各段で全件のテストが緑**であることと、**テストだけを先に入れると何が落ちるか**を測ってから書き写したもの（2b と同じ作り方）。各 Step の Expected の本数と落ちるテストの名前は実測（2026-10-01。全件の本数は、Task 1〜6 の段をメモリの上限 512M の試作で、Task 0・7・8 の段を 1G にした試作で測った。本数は上限によらない）
- 同じ中身の差分のファイルを `~/.claude/plans/approval-phase3-tasks/patches/`（`0000`〜`0009`。この計画のコミット 1 つに 1 ファイル。`0000` は Task 0 のテストのメモリの上限）に置いた。担当は計画のコードを打ち直さず、この差分を当ててよい（**テストを先に** `git apply --include='tests/*'`、実装を**あとで** `git apply --exclude='tests/*'`）。当てたら `git diff --stat` が各 Task の「差分の大きさ」と同じことを確かめる。**差分のファイルと計画のコードが食い違ったら、計画を正として止まり、報告する**
- 各 Task のコードの塊のうち、新しいファイルは全文、既存のファイルは差分（`diff` の形。行の頭の `+` が足す行・`-` が消す行）で示す
- 進め方は 2b と同じ（`~/.claude/plans/approval-phase2b-tasks/rules.md`）: 実装の担当は 1 度に 1 人（Task ごと）・コードの点検は別の担当が WT の HEAD の写しで行い、点検と次の Task の実装は並べてよい・変異の確かめは写しで・Task 12（本番反映）は担当に任せず親の会話が行う

### 0.2 表と本番の SQL（§5.3・§9 の 2）

- **`notifications`（新）**: Laravel 標準の形（`id` uuid・`type`・`notifiable_type`・`notifiable_id`・`data`・`read_at`・日時）に、**`approval_request_id`（申請への外部キー・NULL 可・RESTRICT）を足す**。「その申請の未読」（D13）を `data` の JSON の中から探さず、索引で引くため（MySQL と SQLite で JSON の取り出し方と照合順序が違う＝2b §0.14 の 4 と同じ落とし穴を避ける）
  - 索引: `idx_notifications_notifiable`（`notifiable_type`, `notifiable_id`, `read_at`。未読の数・一覧・既読にする更新が使う）・`idx_notifications_request`（`approval_request_id`。外部キーの索引）
  - `id` は `Str::orderedUuid()`（作った順に並ぶ。同じ秒の知らせも作った順に出る）。`type` は `ApprovalNotice::TYPE`＝`approval`（Laravel の通知のクラスの名前の代わり）
  - 外部キーは RESTRICT: 知らせが出るのは提出した申請だけで、提出した申請は消せない（下書きの削除は一度も提出していないものだけ）
- **`approval_settings` に 3 列**: `mail_last_sent_at`・`mail_last_failed_at`（TIMESTAMP）・`mail_last_failed_to`（VARCHAR(255)・氏名）。書くのは `MailDelivery` だけ（D2）
- 本番の SQL は `database/sql/2026-09-30-approval-phase3a.sql`、テストの鏡は `database/migrations/2026_09_30_000001_create_approval_phase3a_tables.php`。`Phase2TablesTest` の型の正規表現は `CHAR` を拾わないので、3a の表は新しい `Phase3TablesTest` で突き合わせる（`Phase2TablesTest` は 3a が `approval_settings` に足した 3 列を比べる対象から外す）
- **使い捨ての MySQL 8.4.11 で確かめた**（2026-09-30。migration の列のコメントの位置を直したあと、2026-10-01 にも同じ結果）: 3a のテストが MySQL でも通る（`OK (62 tests, 387 assertions)`）／SQL ファイルを流した表と migration で作った表の `SHOW CREATE TABLE` が、列・型・NULL・コメント・索引・外部キーの参照先と扱いで一致（違いは外部キーの名前だけ）。⚠ migration の `->comment()` は `->constrained()` の前に書く（後ろに書くと外部キーの定義に付き、列のコメントにならない。最初の試作で見つけて直した）。⚠ `mysql` の道具で SQL を流すときは `--default-character-set=utf8mb4` が要る（付けないと日本語のコメントが化けて入る。本番は tinker の `DB::statement()`＝PDO の utf8mb4 なので起きない）

### 0.3 お知らせとメールの作り方（§5.5・§9 の 1・3）

- **Laravel の通知のクラス（`Notification`）は使わない**。`notifications` の行（モデル `App\Models\ApprovalNotice extends DatabaseNotification`）と自前のメール（`App\Mail\ApprovalNoticeMail`）を `Notifier` が直接作る。理由: (1) 再発行（`PasswordReissuer`）と同じ形で、操作のトランザクションに行もメールも乗る (2) 標準の通知メールの雛形（英語の文）を使わない (3) 1 人 1 つにまとめる・宛先で絞るのを 1 か所で書ける
- お知らせの `data`（操作の時点の控え。あとで件名が変わっても文は変えない）: `scene`（場面）・`headline`（見出し）・`subject`（件名）・`number`（決裁No）・`applicant`（申請者）・`department`（申請部門）・`action`（必要な対応）・`actor`（操作した人の名前）
- **メールの土台 `App\Mail\ApprovalMailable`**（抽象・`ShouldQueue`）: `$tries = 3`・`$backoff = 60`。`send()` で送れたら `MailDelivery::recordSent()`（キューの中でも `SendQueuedMailable::handle()` がここを通る）、`failed()`（3 回だめのときキューが呼ぶ）で `laravel.log` と `MailDelivery::recordFailed(宛先の氏名)`。「送れた」をメールのイベント（`MessageSent`）で拾わないのは、バックアップの失敗のメールなど決裁でないメールまで数えてしまうため
- パスワード再発行のメール（段階1）も同じ土台に移す（D6。送り直し 1 回 → 3 回・帯の対象）。差出人は全体の設定のまま（「経営管理システム」のメール）
- `MailDelivery` は `DB::table()` で書く（設定の `updated_at` を動かさない）。読むとき（帯）は行を作らない
- ⚠ **`Mail::fake()` はキューへの挿入を飛ばすので、`jobs` の行の巻き戻りは測れない**（`PasswordReissuer` の docblock）。3a はキューを `database` に替えたテストで `jobs` の行を数えて確かめる（Task 4 の `test_the_notice_and_the_queued_mail_roll_back_with_the_operation`）
- テストの `phpunit.xml` は `QUEUE_CONNECTION=sync`・`MAIL_MAILER=array`。`Mail::fake()` を使わないテストでは、積んだメールが操作の中でその場で（配列のメーラーへ）送られる。困ることは無い（メールの描き方の誤りがその場で赤になる）

### 0.4 宛先の決め方（§5.4・§9 の 4・5）

**場面と `Notifier` のメソッド**（どれも static。呼ぶ側のトランザクションの中）

| 場面 | メソッド | 宛先 | 呼ぶ所 |
|---|---|---|---|
| 1 番が来た | `turnArrived($request, $actor)` | 今の回で待っている段階の担当（`StepHandlers`） | `Workflow::submit`・`judgeHead`（承認）・`judgeReview` |
| 2 担当が入れ替わった | `handlerChanged($actor, $steps, $to, $why)` | 呼ぶ側が明示で渡す新しい担当（段階ごと） | `Workflow::reassignHead`（付け替え先）・`headChanged`（新しい部門長） |
| 2（審査担当者の追加） | `reviewersAdded($actor, $department, $added)` | 足した人に、その部門で審査を**待っている**段階ごと | `OrganizationController::syncReviewers`（前後の差） |
| 2（社長の交代） | `presidentChanged($actor, $president)` | 新しい社長に、社長の決裁を待っている段階ごと | `Admin\UserController::setPresident`（前と違うときだけ） |
| 3 差戻し | `returned($request, $actor, $by)` | 申請者 | `judgeHead`・`judgePresident`（差戻し） |
| 4・5 決裁 | `decided($request, $actor, $decision)` | 申請者（条可なら条件の確認のお願い）・その回で部門長として判断した人・意見を入れた審査担当者（段階の `actor_user_id`。D7） | `judgePresident`（可・条可・否） |
| 6 条件の確認 | `conditionConfirmed($request, $actor)` | 決裁した社長・部門長として判断した人（お知らせだけ） | `confirmCondition` |
| 7 取り下げ | `withdrawn($request, $actor, $handlers, $byAdmin)` | 取り下げの前に待っていた段階の担当（`cancelRest()` の前に `StepHandlers::ofWaiting()` で取る）。代理なら申請者も（D8） | `withdraw`・`withdrawByAdmin` |
| 8 取り消し | `undone($request, $admin, $target)` | 番が戻った人（戻った段階の今の担当・条件の確認の取り消しなら申請者）に「もう一度」、取り消された操作をした人と申請者に「取り消されました」 | `undo` |

**共通の決まり**（`Notifier` の `add()`・`send()`・`receives()` の 1 か所）

1. 操作した本人には出さない（管理者の操作なら管理者本人。例: 自分を新しい部門長にした管理者）
2. 1 回の操作で同じ人に同じ申請の知らせは 1 つ（`申請の id:人の id` で先に足したものを残す）。**対応が要る知らせを先に足す**ので、場面 8 で「取り消された人」と「番が戻った人」が同じなら「もう一度」だけが残る（D10 の相手には初めから足さない）
3. 無効の人・削除した人には出さない
4. メールはアドレスがあり `ApprovalMailDomain::allows()` が通す人だけ（ほかの人はお知らせだけ）。場面 6 はお知らせだけ（`Notice::$mailed`）
5. 担当の番の知らせ（場面 1・2・8 の「もう一度」。`Notice::$handlerTurn`）は申請者本人に出さない（D16）
6. 件名と申請部門は今の回の控え（`approval_revisions.snapshot`）から（差戻し中の直しかけを出さない。D26）。件名の改行などの制御文字は空白に（メールの件名に入るため）
7. 使い始める前は何もしない（`ApprovalSetting::launchedForMenu()`。部門の管理・社長の指定は使い始める前から呼ばれるので、行を作らずに読む）

**段階の今の担当 `StepHandlers`**（`App\Support\Approval\StepHandlers`）

- `for($step, $request)`: 部門長＝付け替えた人（`assignee_user_id`）か申請部門の今の部門長／審査＝その審査部門の `activeReviewers()`／社長＝今の社長（`ApprovalSetting::current()`）。そのうえで**無効の人・削除した人・申請者本人を除く**（「今判断できる人」）
- `waitingStep($request)`・`ofWaiting($request)`: 今の回で待っている段階とその担当（申請者の番・終わった申請は空）
- 規則は `RequestPermissions::isAssigneeOf()` と同じ。4 か所目の規則として別に育たないよう、`StepHandlersTest` が「有効な人について、担当に入る ⇔ 対応待ち（`PendingWork::for()`）に出る ⇔ 判断できる（`judgeableStep()`）」を、いろいろな段階の申請 8 件 × 有効な人 8 人で突き合わせる。表示の `CurrentHandler` は無効の人の名前も出すので別のまま（振る舞いを変えない）
- ⚠ 部門長の交代は部門の行を**更新する前**に `headChanged()` が呼ばれるので、`StepHandlers` は前の部門長を返す。`headChanged()` は新しい部門長（引数の `$newHeadId`）を明示で渡す

**Workflow の外の 2 か所**

- 審査担当者の追加（`syncReviewers`）: 部門の登録・更新のトランザクションの中。足した人（前後の差）に、その部門で審査を**待っている**申請ごと（まだ届いていない審査は、届いたときに場面 1 で）。外した人には出さない（D9）
- 社長の指定（`setPresident`）: 今はトランザクションが無いので、**設定の更新・記録・知らせを `DB::transaction()` で囲む**。前と同じ人を選び直したときは知らせない

### 0.5 お知らせの文言（§5.5 の表・§9 の 6）

`NoticeText` の 1 か所。メールの件名は「【決裁】{見出し}：{件名}」、お知らせの文は「{見出し}：{件名}」（同じ表から作る）。

| 場面・宛先 | 見出し | 必要な対応 | メールの前置き（宛名の次の行） |
|---|---|---|---|
| 1 部門長 | 部門長の確認のお願い | 部門長の確認（承認か差戻し） | 次の申請が、あなたの確認の番になりました。 |
| 1 審査担当者 | 審査の意見のお願い | 審査の意見（可・保留・否） | 次の申請が、あなたの審査の意見の番になりました。 |
| 1 社長 | 社長の決裁のお願い | 社長の決裁（可・条可・差戻し・否） | 次の申請が、あなたの決裁の番になりました。 |
| 2 | 場面 1 と同じ | 場面 1 と同じ | 1 行目にわけ（下）＋場面 1 の文 |
| 3 | 差戻しされました | 直して出し直すか、取り下げてください | 次の申請が{部門長／社長}から差し戻されました。 |
| 4 可・否 | 決裁されました（可）／否決されました | 対応は要りません（お知らせです） | 次の申請が決裁されました（可）。／次の申請が否決されました。 |
| 5 申請者 | 条件の確認のお願い（条可） | 条件を確かめて「条件を確認しました」を押してください | 次の申請が条件付きで決裁されました（条可）。社長の条件を確かめてください。 |
| 5 部門長・審査担当者 | 決裁されました（条可） | 対応は要りません（お知らせです） | 次の申請が条件付きで決裁されました（条可）。 |
| 6（お知らせだけ） | 条件が確認されました | 対応は要りません（お知らせです） | —（申請者が条件を確認し、決裁が完了しました。） |
| 7 | 取り下げられました | 対応は要りません（お知らせです） | 申請者が次の申請を取り下げました。／決裁の管理者が、申請者に代わって次の申請を取り下げました。 |
| 8 対応不要の人 | 操作が取り消されました | 対応は要りません（お知らせです） | 決裁の管理者が「{取り消した操作の名前}」を取り消しました。 |
| 8 番が戻った人 | 操作が取り消されました | 戻った段階の対応（条件の確認なら条件の確認） | 上の 1 行＋「次の申請が、もう一度あなたの{確認}の番になりました。」（条件の確認は「もう一度、条件を確認してください。」） |

- 場面 2 のわけ: 「決裁の管理者が担当を付け替えたため、あなたの担当になりました。」「部門長が交代したため、…」「社長の指定が変わったため、…」「審査担当者に加わったため、…」
- 取り消した操作の名前は記録の表示と同じ（`ApprovalHistory::label()`。例「部門長が承認」）
- メールの本文（テキスト。`resources/views/mail/approval-notice.blade.php`）: 宛名・前置き・件名・申請者（申請部門）・決裁No（無ければ「まだありません」）・必要な対応・「▼ 申請を開く」とリンク・送信専用の注意・「金額や本文はメールに書いていません」。**`{!! !!}` で書く**（テキストのメールで `{{ }}` を使うと `&` が `&amp;` のまま届く）
- ⑥ とホームの 1 件: 1 行目「{見出し}：{件名}」と日時（`n/j H:i`・日本時間）、2 行目「{申請者}（{申請部門}）・{決裁No}・{操作した人}さんの操作・{必要な対応}」。未読は太字と赤い「未読」の印（色だけに頼らない）

### 0.6 画面（§5.6・D13〜D15）

- **ベル**（`resources/views/layouts/partials/notice-bell.blade.php`。名前を `sidebar` で始めない・`<nav>` を使わない）: ヘッダーの右（利用者のメニューの左）。未読があれば赤い丸に数（100 以上は「99+」）、無ければ数を出さない。押すと ⑥（D14）。読み上げは `aria-label="お知らせ（未読 N 件）"`。数は `ApprovalMenu::unreadNotices()`（リクエストの attributes に 1 回・使い始める前は `null`＝ベルを出さない）
- **⑥ お知らせ一覧**（`Approval\NoticeController`・`resources/views/approvals/notices/index.blade.php`）: 新しい順（`created_at`・`id` の降順）に 20 件ずつ。ページ送りは ⑩ と同じ（2b の ⑩ から部品 `App\Support\Approval\PageNumbers` と `approvals._pager` に寄せて、両方で使う）。未読があるときだけ「すべて既読にする」（POST）。絞り込みは作らない（D15）。表は使わず 1 件 1 行のリスト（スマホの幅でも 1 件 1 枚）
- **ルート**（`routes/approval.php`・門番 `approval.launched`）: `approvals.notices.index`（GET `/approvals/notices`）・`approvals.notices.readAll`（POST `/approvals/notices/read-all`）・`approvals.notices.open`（GET `/approvals/notices/{notice}`・`whereUuid`）。`read-all` を `{notice}` より前に置く
- **1 件を開く**（`open`）: 持ち主で絞って `findOrFail`（ほかの人のお知らせ・無い ID は 404）→ 既読 → 見られれば詳細へ、見られなくなった申請なら ⑥ へ戻して「この申請は、今は見られません。」（`error` の帯。詳細の 404 にしない）
- **既読の 3 つの入口**（D13）: お知らせを押したとき（`markAsRead()`）・その申請の詳細を開いたとき（`RequestController::show` で `ApprovalNotice::markReadFor($user, $request)`＝**開いた本人のその申請の未読だけ**）・「すべて既読にする」（`markReadFor($user)`）
- **ホーム（①）**: 「対応待ち」「自分の申請の進み具合」の下に「新しいお知らせ」（未読の新しい 5 件。無ければ「新しいお知らせはありません。」）と「お知らせをすべて見る」。中身は控えなので関係を読まない（問い合わせの数が件数で増えない）
- **D2 の帯**（`resources/views/approvals/_mail_failure.blade.php`）: 決裁の管理者にだけ、`MailDelivery::pendingFailure()` があるとき。置き場所は決裁の管理者のホーム（準備中の画面と使い始めたあとのホーム）と管理の 4 画面（利用者・部門・申請種類・進行中の申請。3b で催促の設定）の上。文は「通知メールが送れていません。最後に送れなかったのは 9/30 10:05（宛先: 山田 花子さん）です。決裁のお知らせは画面にも届いています。メールの設定の確認が必要です。」

### 0.7 始めの片付け（§5.7）

- **トレイト `App\Http\Controllers\Approval\Concerns\ReturnsToRequestDetail`**: `prepareOnDetail()`（見られない申請は 404 → そのあとで開き直す小窓をフラッシュ＝2b の最後の点検 M-4 の順にそろえる）・`validateForDetail()`（入力の検査で断られたら詳細へ）・`runOnDetail()`（先を越されたら入力を戻さない・断られたら入力と理由を戻す・成功の文）。`RequestActionController` と `AdminRequestController` の丸写し（2b の最後の点検 I-1）を消して使う。画面の 4 行（開き直す小窓・版・打った中身・理由）はそのまま
- **`ApprovalDepartment::activeReviewers()`**（有効な審査担当者）: `SubmitChecker`・`TypeController`（`withCount('activeReviewers')`＝ビューは `active_reviewers_count`）・`AdminRequestController`（⑩ の印）と、3a の `StepHandlers` が使う。`CurrentHandler` と部門の管理は `reviewers()` のまま（無効の人の名前も出す。振る舞いを変えない）

### 0.8 走査テスト（全件分類）に登録するもの

2b §0.12 と同じく、**各 Task が足したものは、その Task の中で登録する**（途中の各段でも全件が緑）。

| 走査テスト | 登録するもの | Task |
|---|---|---|
| `tests/Feature/Approval/ApprovalAdminGateTest.php` | `tableCounts()` に `notifications`（2）。`OPEN_TO_EVERY_USER` に ⑥ の 3 本（理由つき）・走査の下限を 43 → **46**（7） | 2・7 |
| `tests/Feature/Approval/Phase2/LaunchGateTest.php` | `MIN_GATED` を 20 → **23**（⑥ の 3 本） | 7 |
| `tests/Feature/Approval/Phase2/Phase2TablesTest.php` | `LATER_COLUMNS`（3a が `approval_settings` に足した 3 列は Phase3TablesTest が見る） | 2 |
| `tests/Feature/ClockReadScanTest.php` | `MailDelivery.php` 2 件（4）・`ApprovalNotice.php` 1 件（7）。どちらも TIMESTAMP 列への保存 | 4・7 |
| `tests/Feature/ValidationErrorFeedbackTest.php` | `EXEMPT` に `approvals/notices/index.blade.php`（「すべて既読にする」の POST だけ・検証する入力が無い） | 7 |

- `ApprovalOnlyLockoutTest`: ⑥ は `approvals.` の名前で `App\Http\Controllers\Approval\` に置く（登録は要らない）
- `ApprovalSidebarTest`・`ApprovalMenuTest`: 使い始める前はベルを出さない（既存のテストのまま緑）
- `StoredTimestampDisplayScanTest`: 新しい画面の日時はすべて `JapanTime::format()`（登録は要らない。件数の下限が上がる向きなので変えない）
- `LoginGuideTest` の 1 ファイル 1 宣言: 新しいファイルはどれも 1 つ（トレイト・抽象クラスを含む）
- `JapaneseValidationMessagesTest`: 新しい入力のキーは無い（⑥ の POST は入力なし）

### 0.9 既存のテストを変えるもの（振る舞いが決まって変わるもの）

| テスト | 変えること | 理由 | Task |
|---|---|---|---|
| `Phase2/HomeAndListTest::test_the_home_shows_only_my_own_requests` | 部門長のホームで「他人の差戻しの申請」が出ないことは、「新しいお知らせ」より前（対応待ちと進み具合）だけで見る。直しかけの件名は画面全体で出ないまま | 部門長には提出されたときの知らせ（最後に提出した件名）がホームの「新しいお知らせ」に正しく残る | 7 |
| `PasswordReissueTest::test_it_gives_up_after_one_attempt` | `test_it_is_tried_three_times_a_minute_apart`（キューのジョブの形で 3 回・60 秒） | D6（送り直しを 1 回から 3 回へ） | 8 |
| `Phase2/Phase2TablesTest` | 3a の 3 列を比べる対象から外す（§0.8） | 3a の表は Phase3TablesTest が見る | 2 |

### 0.10 設計書から変えた細部（計画を書いていて分かったこと）

| # | 設計書 | この計画 | 理由 |
|---|---|---|---|
| 1 | §5.3 `notifications` は Laravel 標準の形・「その申請の未読」は `data` の中の申請の ID（§9 の宿題） | 標準の形に `approval_request_id`（外部キー・索引）を足した | JSON の中を探さずに索引で引く。MySQL と SQLite で JSON の取り出し方・照合順序が違う（2b §0.14 の 4） |
| 2 | §5.5 の表の「必要な対応」は場面 4 だけ「対応は要りません（お知らせです）」、ほかは「対応は要りません」 | すべて「対応は要りません（お知らせです）」 | 同じ意味の文を 1 つに（`NoticeText::NO_ACTION`） |
| 3 | §5.6 の ⑥ のページ送りは「⑩ と同じ」 | ⑩ の `pageNumbers()` とページ送りの HTML を部品（`PageNumbers`・`approvals._pager`）にして両方で使う | 同じものを 2 つ書かない |
| 4 | §5.1 の部品の表に無い | `ApprovalMenu::unreadNotices()`（ベルの数）・`MailDelivery`（送れた・送れなかったの記録と帯の判定）・`Notice`（知らせの中身の値）・`ApprovalMailable`（メールの土台）を足した | §5.1 の「名前は仮。実装計画で決める」の範囲 |
| 5 | §5.4 の場面 8「戻った段階の今の担当には『もう一度お願いします』」 | 前置きは「決裁の管理者が「…」を取り消しました。」＋「次の申請が、もう一度あなたの{確認}の番になりました。」 | 付け替えや交代で新しくなった担当にも読める文にした |
| 6 | §5.6 の ⑥ の 1 件の文の例「『〇〇〇〇の購入について』が社長から差戻しされました」 | 「差戻しされました：〇〇〇〇の購入について」（メールの件名と同じ「{見出し}：{件名}」）。誰の操作かは 2 行目の「{操作した人}さんの操作」 | 文言を 1 か所（`NoticeText`）から作る（§5.5「お知らせの文も同じ表から作る」） |
| 7 | §6 の巻き戻り「メールの `jobs` の行は `Mail::fake()` では測れないので、設定（キューが database）を読む試験と docblock で守る」 | キューを `database` に替えたテストで、操作が巻き戻ると `jobs` の行も残らないことを数えて確かめる（Task 4 の `test_the_notice_and_the_queued_mail_roll_back_with_the_operation`）。本番の設定は Task 12 の読み取りで見る | 設定を読むより、巻き戻りそのものを測るほうが確か |
| 8 | §5.6 の帯の文「画面のお知らせは届いています。」 | 「決裁のお知らせは画面にも届いています。」 | 帯はパスワード再発行のメール（D6）が送れなかったときにも出るため（利用者の決定 2026-10-01） |

### 0.11 受け入れた隙間

| # | 隙間 | 起きたとき | 塞ぐなら |
|---|---|---|---|
| 1 | 社長の交代・審査担当者の追加は、待っている申請の `lock_version` を進めない（設計書 §4.3 の事実のまま。3a は知らせだけ足す） | 交代の前に開いた画面から前の担当が押すと、権限が無いと断られる（今と同じ） | Workflow に社長の交代・審査担当者の入れ替えを移す |
| 2 | 使い始めたあと、基幹の全画面でベルのために問い合わせが 1〜2 本増える（使い始めたかの読み取り・未読の数。件数によらず一定） | 体感には出ない（索引が効く） | 使い始めたかを `current()` の入れ物に覚える（2b の Task 7 Minor 3 と同じ） |
| 3 | 同じ秒に「送れなかった」と「送れた」が起きると、帯は出たまま | 次の 1 通が送れたときに消える | 日時の列をミリ秒にする |
| 4 | 審査担当者から外れた人の未読（まだ意見を入れていない審査の知らせ）は残る | 押すと ⑥ に戻して「この申請は、今は見られません。」（既読になる） | 外したときにその人の未読を既読にする |
| 5 | お知らせの件名は操作の時点の控え。出し直しで件名を変えると、前の回の知らせは前の件名のまま | 一覧に前の件名と新しい件名が並ぶ（どちらも同じ申請を開く） | 設計どおり（§5.3） |

### 0.12 テストの土台

- 2b §0.15 の土台（`BuildsApprovalFixtures`・`ParsesForms`）をそのまま使う。3a で `tests/Concerns/ReadsApprovalNotices.php` を足す: `mailable($user, $local)`（許可したドメイン mitsuwat.co.jp のアドレスを付ける）・`noticesOf($user)`・`headlinesOf($user)`（作った順）・`mailSubjectsTo($address)`（`Mail::fake()` のあと）
- `Notifier` を直接呼ぶテスト（`NotifierTest`）は、申請を**使い始める前に** Workflow で進めておき、使い始めてから呼ぶ（Workflow からの知らせと混ざらない）
- 画面から操作したときのリンクは、`withServerVariables(['SCRIPT_NAME' => '/system/manage/index.php', 'SCRIPT_FILENAME' => base_path('public/index.php')])` と `/system/manage/index.php/approvals/…` への送信で本番の形を作って見る（Review Focus 5）
- 利用者の `status` は `forceFill(['status' => 'inactive'])->save()` で変える（2b §0.15）
- **テストのメモリの上限を 512M → 1G にする（Task 0 の Step 5）**: 全件のピークが土台で 503.50 MB（3140 本・上限の 98%）、3a のテストを足すと 515.50 MB（3204 本）で、上限 512M のままだと Task 7 の段から全件が `Allowed memory size … exhausted` で途中で止まる（2026-10-01 の実測。止まったときは本数の行が出ない）。1 本あたりの増え方は 3a の前と同じ平均 160 KB ほどで、3a のコードの漏れではない（増え続けるわけは調べていない。2026-08-16 に 128M → 512M にしたときと同じ扱い）

## 1. 触るファイル

### 新規

| ファイル | 役目 | Task |
|---|---|---|
| `app/Http/Controllers/Approval/Concerns/ReturnsToRequestDetail.php` | 詳細の画面へ戻す形（2a・2b の丸写しを寄せる） | 1 |
| `database/sql/2026-09-30-approval-phase3a.sql`・`database/migrations/2026_09_30_000001_create_approval_phase3a_tables.php` | 表（本番の SQL とテストの鏡） | 2 |
| `app/Models/ApprovalNotice.php` | お知らせの行（持ち主で絞る・既読にする） | 2・7 |
| `app/Support/Approval/StepHandlers.php` | 段階の今の担当を人で返す | 3 |
| `app/Support/Approval/Notifier.php`・`Notice.php`・`NoticeText.php` | 知らせを出す 1 か所・知らせの中身・文言の表 | 4・6 |
| `app/Mail/ApprovalMailable.php`・`ApprovalNoticeMail.php`・`resources/views/mail/approval-notice.blade.php` | メールの土台・知らせのメール・本文 | 4 |
| `app/Support/Approval/MailDelivery.php` | 送れた・送れなかったの記録と帯の判定 | 4・8 |
| `app/Support/Approval/PageNumbers.php`・`resources/views/approvals/_pager.blade.php` | ページ送り（⑩ から寄せる） | 7 |
| `app/Http/Controllers/Approval/NoticeController.php`・`resources/views/approvals/notices/index.blade.php`・`_item.blade.php` | ⑥ | 7 |
| `resources/views/layouts/partials/notice-bell.blade.php` | ベル | 7 |
| `resources/views/approvals/_mail_failure.blade.php` | D2 の帯 | 8 |
| `tests/Concerns/ReadsApprovalNotices.php` | テストの土台 | 4 |
| `tests/Feature/Approval/Phase3/Phase3TablesTest.php`・`StepHandlersTest.php`・`NotifierTest.php`・`WorkflowNoticeTest.php`・`HandlerChangeNoticeTest.php`・`NoticeScreenTest.php`・`MailFailureBannerTest.php` | 3a のテスト | 2〜8 |

### 変更

| ファイル | 変えること | Task |
|---|---|---|
| `phpunit.xml` | テストのメモリの上限（512M → 1G。§0.12） | 0 |
| `app/Http/Controllers/Approval/RequestActionController.php` | トレイトを使う（丸写しを消す・M-4） | 1 |
| `app/Http/Controllers/Approval/AdminRequestController.php` | トレイト（1-A）・`activeReviewers`（1-B）・`PageNumbers`（7） | 1・7 |
| `app/Models/ApprovalDepartment.php`・`app/Support/Approval/SubmitChecker.php`・`app/Http/Controllers/Approval/TypeController.php`・`resources/views/approvals/admin/types.blade.php` | `activeReviewers()` | 1 |
| `app/Support/Approval/Workflow.php` | 場面 1・3〜8 と付け替え（5）・部門長の交代（6） | 5・6 |
| `app/Http/Controllers/Approval/OrganizationController.php` | 審査担当者の追加の知らせ | 6 |
| `app/Http/Controllers/Admin/UserController.php` | 社長の指定をトランザクションで囲み、知らせる | 6 |
| `app/Http/Controllers/Approval/RequestController.php`・`HomeController.php`・`app/Support/Approval/ApprovalMenu.php`・`routes/approval.php` | D13・ホームの欄・ベルの数・⑥ のルート | 7 |
| `resources/views/layouts/partials/header.blade.php`・`resources/views/approvals/home-launched.blade.php`・`admin/requests.blade.php` | ベル・ホームの欄・ページ送りの部品（7）・帯（8） | 7・8 |
| `app/Models/ApprovalSetting.php`・`app/Mail/PasswordReissuedMail.php`・`resources/views/approvals/home.blade.php`・`admin/users/index.blade.php`・`admin/organization.blade.php`・`admin/types.blade.php` | 日時の読み方・再発行のメールの土台・帯 | 8 |
| テスト: `RequestActionTest`・`Phase2ModelsTest`・`ApprovalAdminGateTest`・`Phase2TablesTest`・`ClockReadScanTest`・`HomeAndListTest`・`LaunchGateTest`・`ValidationErrorFeedbackTest`・`PasswordReissueTest` | 各 Task の説明のとおり | 1〜8 |
| `docs/superpowers/specs/2026-09-30-approval-phase3-design.md`・`CLAUDE.md`・`docs/ARCHITECTURE.md`・`routes/web.php`・`docs/BACKLOG.md`・`docs/運用_バックアップとメール.md` | ドキュメント（要件定義書は変えない。15.5 は「詳しい列は実装計画で決める」） | 11 |

## 2. 作業の順番

途中の各段でも、既存のテストを含めて全部が通る状態を保つ（設計書 §10）。順番は設計書 §10 の 3a の案のとおり。

| Task | 中身 | 設計書 | コミット | 全件（実測） |
|---|---|---|---|---|
| 0 | 作業場所の準備（worktree・vendor・全件が緑）とテストのメモリの上限（512M → 1G） | — | 1 | 3,140 本 |
| 1 | 始めの片付け（詳細の画面へ戻す形・有効な審査担当者） | §5.7 | 2 | 3,142 本 |
| 2 | 表（`notifications`・`approval_settings` の 3 列）とお知らせのモデル | §5.3 | 1 | 3,147 本 |
| 3 | 段階の今の担当（`StepHandlers`） | §5.4 | 1 | 3,150 本 |
| 4 | 知らせを出す部品・文言・メールの土台と知らせのメール | §5.4・§5.5 | 1 | 3,163 本 |
| 5 | Workflow への組み込み（場面 1・3〜8・付け替え） | §5.1・§5.4 | 1 | 3,182 本 |
| 6 | 担当の入れ替わり（部門長の交代・審査担当者の追加・社長の交代。D1） | §5.1・§5.4 | 1 | 3,188 本 |
| 7 | 画面（ベル・⑥・ホームの欄・既読・見られなくなった申請） | §5.6 | 1 | 3,199 本 |
| 8 | メールが送れないときの帯（D2）・再発行のメールの送り直し（D6） | §5.5・§5.6 | 1 | 3,204 本 |
| 9 | 全件テストと変異テスト | §6 | 0〜1 | — |
| 10 | 手元のブラウザでの確認と、利用者に見せる画面の写真 | §6 | 0〜1 | — |
| 11 | ドキュメント（設計書への書き戻しを含む） | §7 の 6 | 2 | — |
| 12 | 本番反映（利用者の了承を取ってから・親の会話が行う） | §7 | 1 | — |

---

## Task 0: 作業場所の準備

**作業場所**: worktree `/Users/masanori/site/manage/.claude/worktrees/approval-phase3`（ブランチ `approval-phase3`。この計画のコミットの親は `73fc9939`＝段階3 の設計書・要件定義書 v1.11 に `13.x` の `78999fb7` を取り込んだもの）。**main repo では作業もテストもしない**（main repo の vendor は `--no-dev` で phpunit が無い。dev 依存を入れると `./deploy.sh` が本番へ送る）。

- [ ] **Step 1: 並行の作業を確かめる**（ほかの会話が同じ課題を進めていないか）

```bash
cd /Users/masanori/site/manage && git status --short --branch && git log --oneline -5 && git worktree list && git for-each-ref --sort=-committerdate --format='%(refname:short) %(committerdate:short) %(subject)' refs/heads | head && git log --oneline approval-phase3..13.x
```

Expected: worktree `approval-phase3` の先頭がこの計画のコミット。最後のコマンド（`13.x` にあって `approval-phase3` に無いコミット）が空。ほかの worktree（`contract-reimport`・`customer-import-double-submit`・`import-double-submit`・`same-name-customer-match`）は別の会話のもの（触らない）。段階3 の名前の付いたほかの枝や worktree があれば、中身を読み、消さずに利用者へ報告して止まる。`13.x` が進んでいたら止まり、利用者に取り込むかを聞く（取り込むなら WT で `git merge 13.x`。rebase しない）。

- [ ] **Step 2: vendor を確かめる**（dev 依存あり・実体）

⚠ **symlink にしない**（autoload が symlink の先を読み、別の場所のコードでテストが流れる。Bug #50）。worktree には 2b の worktree から実体で写した vendor がある。

```bash
WT=/Users/masanori/site/manage/.claude/worktrees/approval-phase3; test -x "$WT/vendor/bin/phpunit" && ! test -L "$WT/vendor" && echo "vendor OK"
```

Expected: `vendor OK`（無ければ worktree の cwd で `composer install`。**main repo では絶対に `composer install` しない**）

- [ ] **Step 3: 差分のファイルを確かめる**

```bash
ls ~/.claude/plans/approval-phase3-tasks/patches/ && cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --check ~/.claude/plans/approval-phase3-tasks/patches/0001-*.patch && echo "0001 を当てられる"
```

Expected: `0000-…` 〜 `0009-…` の 10 本と `0001 を当てられる`（`0000` は Step 5 のテストのメモリの上限）。無ければ計画のコードを打ち込む（中身は同じ）。

- [ ] **Step 4: 全件テストが通る状態から始める**

**テストの流し方**（以下すべての Task で同じ。`APP_KEY` は 32 バイトの本物の鍵を渡す。worktree に `.env` を作らない）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `OK (3140 tests, 22385 assertions)`（2026-10-01 の実測。約 2.5 分）。赤があれば 3a の作業の前に利用者へ報告して止まる。

⚠ 以下の Task の「テストを流す」は、すべてこの形でファイルを並べたもの。`cd` はコマンドごとに書く（ターンをまたぐと cwd が main repo へ戻る）。git は `git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 …` で呼ぶ。

⚠ コミットのたびに `git status --porcelain` が空であることを確かめる。差分のファイルを当てたときは、`git diff --stat` が各 Task の「差分の大きさ」と同じことも確かめる。

- [ ] **Step 5: テストのメモリの上限を 1G にする**（§0.12）

全件のテストのメモリのピークは、土台で 503.50 MB（上限 512M の 98%）。3a のテストを足すと Task 7 の段で上限を超え、`Allowed memory size of 536870912 bytes exhausted` で全件が途中で止まる（2026-10-01 の実測。Task 8 の段で 515.50 MB）。テスト 1 本あたりの増え方は 3a の前と同じ（平均 160 KB ほど）で、3a のコードが漏らしているのではない。2026-08-16 に 128M → 512M にしたときと同じ形で上げる。

⚠ 先に `grep -n memory_limit phpunit.xml` を見る。`13.x` で別の会話がもう上げていて、取り込んだ worktree が 1G 以上ならこの Step は飛ばす（同じ行を別の値で書き換えると取り込みで衝突する）。

`phpunit.xml`（変更）

```diff
--- a/phpunit.xml
+++ b/phpunit.xml
@@ -23,8 +23,11 @@
              （実測: 9 回中 4 回が Fatal error。落ちる場所は毎回フレームワーク側で、
              コンパイル済み Blade や PHPUnit のメタデータパーサだった）。
              テストが増えるほど確実に壊れるので明示的に引き上げる。
-             ⚠ この行を消すと「特定のテストが悪い」ように見える不安定な失敗が戻る。 -->
-        <ini name="memory_limit" value="512M"/>
+             ⚠ この行を消すと「特定のテストが悪い」ように見える不安定な失敗が戻る。
+             2026-10-01: 512M でもピークが 503.50 MB（3140 本。上限の 98%）になり、決裁 段階3a のテストを
+             足すと 515.50 MB（3204 本）で Fatal error になったので 1G にした。テスト 1 本あたり平均 160 KB ほど
+             増えたまま戻らない（フレームワーク側で解放されない分。2026-08-16 から同じ傾向）。 -->
+        <ini name="memory_limit" value="1G"/>
         <env name="APP_ENV" value="testing"/>
         <env name="APP_URL" value="http://localhost"/>
         <!-- 本番は APP_LOCALE=ja / APP_FALLBACK_LOCALE=ja。config/app.php の既定は 'en' なので
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply ~/.claude/plans/approval-phase3-tasks/patches/0000-*.patch`）

全件を流す（Step 4 と同じコマンド）。Expected: `OK (3140 tests, 22385 assertions)`（本数は Step 4 と同じ）

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add phpunit.xml
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
test: phpunit の memory_limit を 1G にする

全件のテストのメモリのピークが 503.50 MB（3140 本・上限 512M の 98%）になり、
決裁 段階3a のテストを足すと 515.50 MB で Fatal error になって全件が途中で
止まる。テスト 1 本あたりの増え方は前と同じなので、2026-08-16 と同じく上限を
引き上げる。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 1: 始めの片付け（詳細の画面へ戻す形・有効な審査担当者）

3a で触る所の丸写しと、4 か所目になりそうな条件を、先に 1 か所へ寄せる（設計書 §5.7・§0.7）。コミットは 2 つ（1-A・1-B）。

### 1-A: 詳細の画面へ戻す形を `ReturnsToRequestDetail` に寄せる

2a の `RequestActionController`（判断・条件確認・取り下げ）と 2b の `AdminRequestController`（付け替え・取り消し・代理の取り下げ）に同じ中身があった「見られなければ 404・開き直す小窓を残す・入力の検査で断られたら詳細へ・Workflow を呼んで詳細へ戻す」を、トレイトに寄せる（2b の最後の点検 I-1）。あわせて、条件確認と取り下げだけ 404 の確かめの**前**に小窓をフラッシュしていた順を、ほかと同じ「404 のあと」にそろえる（2b の最後の点検 M-4。見られない申請への操作で、セッションに開き直す小窓が残らない）。画面に戻す 4 つ（開き直す小窓・版・打った中身・理由）と文言は変えない。

**Files:**
- Create: `app/Http/Controllers/Approval/Concerns/ReturnsToRequestDetail.php`
- Modify: `app/Http/Controllers/Approval/RequestActionController.php`・`app/Http/Controllers/Approval/AdminRequestController.php`（持っていた写し〈`run()`・`assertVisible()`・入力の検査の try/catch〉を消してトレイトを使う）
- Test: Modify `tests/Feature/Approval/Phase2/RequestActionTest.php`（1 本足す）

**Interfaces:**
- Produces: トレイト `App\Http\Controllers\Approval\Concerns\ReturnsToRequestDetail`（どれも `private`）: `prepareOnDetail(Request $request, ApprovalRequest $approvalRequest, string $modal): void`（見られなければ 404、見られれば `approval_reopen` に `$modal` をフラッシュ）／`validateForDetail(Request $request, ApprovalRequest $approvalRequest, array $rules, array $messages = []): array`（断られたら詳細の画面へ）／`runOnDetail(ApprovalRequest $approvalRequest, Closure $action, string|Closure $success): RedirectResponse`（`WorkflowConflict` は入力を戻さず `error`、`WorkflowRefused` は入力と理由を戻して `error`、成功は `success`）

**差分の大きさ:** 4 ファイル・+123 / −109 行（差分のファイル `0001-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/RequestActionTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/RequestActionTest.php
+++ b/tests/Feature/Approval/Phase2/RequestActionTest.php
@@ -491,6 +491,24 @@ public function test_the_applicant_operations_are_not_found_for_someone_who_cann
         $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status);
     }
 
+    /**
+     * 見られない申請への操作は 404 で、開き直す小窓も残さない（フラッシュは 404 の確かめのあと。2b の最後の点検 M-4・
+     * 段階3 設計書 §5.7。前は条件確認と取り下げだけ 404 の前に残していた）
+     */
+    public function test_an_operation_on_a_request_that_cannot_be_seen_leaves_no_modal_to_reopen(): void
+    {
+        $w = $this->approvalWorld();
+        $this->launchApprovals();
+        $request  = $this->submittedFor($w);
+        $outsider = $this->approvalOnlyUser();
+
+        foreach (['headReview', 'review', 'decide', 'confirmCondition', 'withdraw'] as $route) {
+            $this->act($outsider, $request, "approvals.requests.{$route}", ['result' => 'approve'])
+                ->assertNotFound()
+                ->assertSessionMissing('approval_reopen');
+        }
+    }
+
     /** その段階で選べない判断は、入力の誤りとして詳細の画面に出す（⚠ assertSessionHas* を呼ばずに描く。Bug #49） */
     public function test_a_result_that_is_not_offered_is_refused_as_an_input_error(): void
     {
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/patches/0001-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase2/RequestActionTest.php
```

Expected: `FAILURES!` `Tests: 44, Assertions: 493, Failures: 1.`

- `RequestActionTest::test_an_operation_on_a_request_that_cannot_be_seen_leaves_no_modal_to_reopen` — `Session has unexpected key [approval_reopen].`

（条件確認と取り下げが 404 の前に小窓を残している。ほかの 43 本は緑のまま＝寄せる前の振る舞いを固定している）

- [ ] **Step 3: トレイトを書く**

`app/Http/Controllers/Approval/Concerns/ReturnsToRequestDetail.php`（新規）

```php
<?php

namespace App\Http\Controllers\Approval\Concerns;

use App\Models\ApprovalRequest;
use App\Support\Approval\RequestVisibility;
use App\Support\Approval\WorkflowConflict;
use App\Support\Approval\WorkflowRefused;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 詳細の画面（③）から送る操作の共通の形（判断・条件確認・取り下げと、決裁の管理者の 3 つの操作。段階3 設計書 §5.7）。
 * 2a の RequestActionController と 2b の AdminRequestController に同じ中身があったものを寄せた（2b の最後の点検 I-1）。
 *
 * ⚠ 戻り先はいつも詳細の画面（Bug #64）。
 * ⚠ 断られたとき詳細の画面が開き直す小窓は、送り先で決めてセッションに残す（approval_reopen。フォームの値で受け取らないので、
 *   状態が進んだあとに古い画面の小窓が断られても、別の小窓は開かない。2b 計画 §0.9）。
 */
trait ReturnsToRequestDetail
{
    /**
     * 見られない申請は 404（在るかどうかを漏らさない。段階2 設計書 §5.10）。見られるなら、断られたときに開き直す小窓を残す。
     * ⚠ フラッシュは 404 の確かめのあと（2b の最後の点検 M-4。前は送り先によって前後が揺れていた）
     */
    private function prepareOnDetail(Request $request, ApprovalRequest $approvalRequest, string $modal): void
    {
        abort_unless(RequestVisibility::canView($request->user(), $approvalRequest), 404);
        $request->session()->flash('approval_reopen', $modal);
    }

    /**
     * 入力の検査。断られたら詳細の画面へ戻す（打った中身が戻り、小窓が開き直す）
     *
     * @param array<string, mixed> $rules
     * @param array<string, string> $messages
     * @return array<string, mixed>
     */
    private function validateForDetail(Request $request, ApprovalRequest $approvalRequest, array $rules, array $messages = []): array
    {
        try {
            return $request->validate($rules, $messages);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('approvals.requests.show', $approvalRequest));
        }
    }

    /**
     * Workflow を呼び、詳細の画面へ戻す。先を越されたら「すでに処理されています」（入力は戻さない＝今の状態を見てもらう）、
     * 断られたら理由と打った中身を戻す（画面が小窓を開き直す。入力の検査で断られたときと同じ。2a の Task 19 の C8）
     *
     * @param Closure(): void $action
     * @param string|Closure(): string $success
     */
    private function runOnDetail(ApprovalRequest $approvalRequest, Closure $action, string|Closure $success): RedirectResponse
    {
        $show = redirect()->route('approvals.requests.show', $approvalRequest);

        try {
            $action();
        } catch (WorkflowConflict) {
            return $show->with('error', WorkflowConflict::MESSAGE);
        } catch (WorkflowRefused $e) {
            return $show->withInput()->with('error', implode(' ', $e->reasons));
        }

        return $show->with('success', $success instanceof Closure ? $success() : $success);
    }
}
```

- [ ] **Step 4: 2 つのコントローラをトレイトに寄せる**

`app/Http/Controllers/Approval/RequestActionController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/RequestActionController.php
+++ b/app/Http/Controllers/Approval/RequestActionController.php
@@ -4,18 +4,14 @@
 
 use App\Enums\ApprovalStepKind;
 use App\Enums\ApprovalStepResult;
+use App\Http\Controllers\Approval\Concerns\ReturnsToRequestDetail;
 use App\Http\Controllers\Controller;
 use App\Models\ApprovalRequest;
 use App\Support\Approval\FormInput;
-use App\Support\Approval\RequestVisibility;
 use App\Support\Approval\Workflow;
-use App\Support\Approval\WorkflowConflict;
-use App\Support\Approval\WorkflowRefused;
-use Closure;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Validation\Rule;
-use Illuminate\Validation\ValidationException;
 
 /**
  * 判断・条件確認・取り下げ（要件 4.2・4.5・4.6・設計書 §5.8）。
@@ -23,12 +19,12 @@
  * ⚠ 権限と状態は Workflow（RequestPermissions）が確かめる。ここは入力の形を見て渡すだけ。
  * ⚠ 画面が描いたときの lock_version を渡す（古い画面から押した操作を「すでに処理されています」で断る。計画 §0.3）。
  *   送られてこない・0 以上の整数の形でないときは -1 にする（必ず断られる。FormInput::lockVersion()）。
- * ⚠ 戻り先はいつも詳細の画面（Bug #64）。
- * ⚠ 断られたとき詳細の画面が開き直す小窓は、送り先で決めてセッションに残す（approval_reopen。フォームの値で受け取らないので、
- *   状態が進んだあとに古い画面の小窓が断られても、別の小窓は開かない。2a の Task 19 の点検 m-6・2b 計画 §0.9）。
+ * ⚠ 見られるかの確かめ・開き直す小窓・詳細の画面への戻し方は ReturnsToRequestDetail（決裁の管理者の操作と共通。段階3 設計書 §5.7）。
  */
 class RequestActionController extends Controller
 {
+    use ReturnsToRequestDetail;
+
     public function __construct(private readonly Workflow $workflow)
     {
     }
@@ -54,10 +50,10 @@ public function decide(Request $request, ApprovalRequest $approvalRequest): Redi
     /** 条件の確認（申請者。要件 4.6） */
     public function confirmCondition(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
     {
-        $request->session()->flash('approval_reopen', 'condition');
+        $this->prepareOnDetail($request, $approvalRequest, 'condition');
         $comment = $this->optionalComment($request, $approvalRequest);
 
-        return $this->run(
+        return $this->runOnDetail(
             $approvalRequest,
             fn () => $this->workflow->confirmCondition($approvalRequest, $request->user(), FormInput::lockVersion($request), $comment),
             '条件を確認しました。決裁が完了しました。',
@@ -67,10 +63,10 @@ public function confirmCondition(Request $request, ApprovalRequest $approvalRequ
     /** 取り下げ（申請者。コメントは任意。D17） */
     public function withdraw(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
     {
-        $request->session()->flash('approval_reopen', 'withdraw');
+        $this->prepareOnDetail($request, $approvalRequest, 'withdraw');
         $comment = $this->optionalComment($request, $approvalRequest);
 
-        return $this->run(
+        return $this->runOnDetail(
             $approvalRequest,
             fn () => $this->workflow->withdraw($approvalRequest, $request->user(), FormInput::lockVersion($request), $comment),
             '取り下げました。',
@@ -79,30 +75,25 @@ public function withdraw(Request $request, ApprovalRequest $approvalRequest): Re
 
     private function judge(Request $request, ApprovalRequest $approvalRequest, ApprovalStepKind $kind): RedirectResponse
     {
-        $this->assertVisible($request, $approvalRequest);
-        $request->session()->flash('approval_reopen', 'judge');
+        $this->prepareOnDetail($request, $approvalRequest, 'judge');
 
         $allowed = array_map(fn (ApprovalStepResult $result) => $result->value, ApprovalStepResult::allowedFor($kind));
         FormInput::unifyNewlines($request, 'comment');
 
-        try {
-            $validated = $request->validate([
-                'result'  => ['required', Rule::in($allowed)],
-                'comment' => ['nullable', 'string', 'max:2000'],
-            ], [
-                'result.required' => '判断を選んでください。',
-                'result.in'       => '選べない判断です。',
-            ]);
-        } catch (ValidationException $e) {
-            throw $e->redirectTo(route('approvals.requests.show', $approvalRequest));
-        }
+        $validated = $this->validateForDetail($request, $approvalRequest, [
+            'result'  => ['required', Rule::in($allowed)],
+            'comment' => ['nullable', 'string', 'max:2000'],
+        ], [
+            'result.required' => '判断を選んでください。',
+            'result.in'       => '選べない判断です。',
+        ]);
 
         $result  = ApprovalStepResult::from($validated['result']);
         $comment = $validated['comment'] ?? null;
         $version = FormInput::lockVersion($request);
         $actor   = $request->user();
 
-        return $this->run(
+        return $this->runOnDetail(
             $approvalRequest,
             fn () => match ($kind) {
                 ApprovalStepKind::Head      => $this->workflow->judgeHead($approvalRequest, $actor, $version, $result, $comment),
@@ -113,29 +104,6 @@ private function judge(Request $request, ApprovalRequest $approvalRequest, Appro
         );
     }
 
-    /**
-     * Workflow を呼び、詳細の画面へ戻す（断られたら理由、先を越されたら「すでに処理されています」）。
-     * 断られたとき（コメントが要る判断のコメントの不足など）は、選んだ判断と打ったコメントも戻す（画面が小窓を開き直す。
-     * 入力の検査で断られたときと同じ。Task 19 の C8）。先を越されたときは戻さない（今の状態を見てもらう）
-     *
-     * @param Closure(): void $action
-     * @param string|Closure(): string $success
-     */
-    private function run(ApprovalRequest $approvalRequest, Closure $action, string|Closure $success): RedirectResponse
-    {
-        $show = redirect()->route('approvals.requests.show', $approvalRequest);
-
-        try {
-            $action();
-        } catch (WorkflowConflict) {
-            return $show->with('error', WorkflowConflict::MESSAGE);
-        } catch (WorkflowRefused $e) {
-            return $show->withInput()->with('error', implode(' ', $e->reasons));
-        }
-
-        return $show->with('success', $success instanceof Closure ? $success() : $success);
-    }
-
     /** 判断のあとの帯の言葉（社長の判断は付いた決裁No を添える。Workflow が同じインスタンスを読み直している） */
     private static function judgedMessage(ApprovalStepKind $kind, ApprovalStepResult $result, ApprovalRequest $approvalRequest): string
     {
@@ -153,23 +121,10 @@ private static function judgedMessage(ApprovalStepKind $kind, ApprovalStepResult
     /** 条件確認・取り下げのコメント（任意・2,000 文字まで） */
     private function optionalComment(Request $request, ApprovalRequest $approvalRequest): ?string
     {
-        $this->assertVisible($request, $approvalRequest);
         FormInput::unifyNewlines($request, 'comment');
 
-        try {
-            $validated = $request->validate([
-                'comment' => ['nullable', 'string', 'max:2000'],
-            ]);
-        } catch (ValidationException $e) {
-            throw $e->redirectTo(route('approvals.requests.show', $approvalRequest));
-        }
-
-        return $validated['comment'] ?? null;
-    }
-
-    /** 見られない申請は 404（在るかどうかを漏らさない。設計書 §5.10） */
-    private function assertVisible(Request $request, ApprovalRequest $approvalRequest): void
-    {
-        abort_unless(RequestVisibility::canView($request->user(), $approvalRequest), 404);
+        return $this->validateForDetail($request, $approvalRequest, [
+            'comment' => ['nullable', 'string', 'max:2000'],
+        ])['comment'] ?? null;
     }
 }
```

`app/Http/Controllers/Approval/AdminRequestController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/AdminRequestController.php
+++ b/app/Http/Controllers/Approval/AdminRequestController.php
@@ -6,6 +6,7 @@
 use App\Enums\ApprovalStepKind;
 use App\Enums\ApprovalStepStatus;
 use App\Enums\UserStatus;
+use App\Http\Controllers\Approval\Concerns\ReturnsToRequestDetail;
 use App\Http\Controllers\Controller;
 use App\Models\ApprovalRequest;
 use App\Models\ApprovalSetting;
@@ -16,15 +17,10 @@
 use App\Support\Approval\FormInput;
 use App\Support\Approval\PendingWork;
 use App\Support\Approval\RequestPermissions;
-use App\Support\Approval\RequestVisibility;
 use App\Support\Approval\Workflow;
-use App\Support\Approval\WorkflowConflict;
-use App\Support\Approval\WorkflowRefused;
-use Closure;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Support\Collection;
-use Illuminate\Validation\ValidationException;
 use Illuminate\View\View;
 
 /**
@@ -35,10 +31,12 @@
  * ⚠ 権限と状態は Workflow（RequestPermissions）が確かめる。ここは入力の形を見て渡すだけ（判断の操作と同じ）。
  * ⚠ 一覧の件名・申請部門は、最後に提出した控えのもの（差戻し中の直しかけを出さない。D26）。
  * ⚠ 操作の戻り先はいつも詳細の画面（Bug #64）。断られたら、送った小窓を打った中身で開き直す（どの小窓かは送り先で決める。
- *   2b 計画 §0.9）。
+ *   2b 計画 §0.9。判断の操作と共通の ReturnsToRequestDetail。段階3 設計書 §5.7）。
  */
 class AdminRequestController extends Controller
 {
+    use ReturnsToRequestDetail;
+
     /** 「決裁済み・否決」の 1 ページの件数 */
     private const DECIDED_PER_PAGE = 20;
 
@@ -96,7 +94,7 @@ public function reassign(Request $request, ApprovalRequest $approvalRequest): Re
         ]);
         $to = User::findOrFail((int) $validated['assignee_user_id']);
 
-        return $this->run(
+        return $this->runOnDetail(
             $approvalRequest,
             fn () => $this->workflow->reassignHead($approvalRequest, $request->user(), FormInput::lockVersion($request), $to, $validated['admin_reason']),
             "部門長の確認を{$to->name}さんに付け替えました。",
@@ -112,7 +110,7 @@ public function undo(Request $request, ApprovalRequest $approvalRequest): Redire
         $target = RequestPermissions::for($request->user(), $approvalRequest)->undoTarget();
         $label  = $target?->label() ?? '直前の操作';
 
-        return $this->run(
+        return $this->runOnDetail(
             $approvalRequest,
             fn () => $this->workflow->undo($approvalRequest, $request->user(), FormInput::lockVersion($request), $validated['admin_reason']),
             "「{$label}」を取り消しました。",
@@ -125,7 +123,7 @@ public function withdraw(Request $request, ApprovalRequest $approvalRequest): Re
         $this->prepare($request, $approvalRequest, 'admin_withdraw');
         $validated = $this->validated($request, $approvalRequest);
 
-        return $this->run(
+        return $this->runOnDetail(
             $approvalRequest,
             fn () => $this->workflow->withdrawByAdmin($approvalRequest, $request->user(), FormInput::lockVersion($request), $validated['admin_reason']),
             '申請者に代わって取り下げました。',
@@ -205,14 +203,10 @@ private static function pageNumbers(int $current, int $last): array
         return $numbers;
     }
 
-    /**
-     * 見られない申請は 404（在るかどうかを漏らさない）。断られたら開き直す小窓を、送り先で決めて残す（2b 計画 §0.9。
-     * 小窓の名前をフォームの値で受け取らないので、状態が進んだあとに古い画面の小窓が断られても、別の小窓は開かない）
-     */
+    /** 見られるかの確かめと開き直す小窓（ReturnsToRequestDetail）に、理由の改行をそろえるのを足す */
     private function prepare(Request $request, ApprovalRequest $approvalRequest, string $modal): void
     {
-        abort_unless(RequestVisibility::canView($request->user(), $approvalRequest), 404);
-        $request->session()->flash('approval_reopen', $modal);
+        $this->prepareOnDetail($request, $approvalRequest, $modal);
         FormInput::unifyNewlines($request, 'admin_reason');
     }
 
@@ -225,34 +219,10 @@ private function prepare(Request $request, ApprovalRequest $approvalRequest, str
      */
     private function validated(Request $request, ApprovalRequest $approvalRequest, array $rules = [], array $messages = []): array
     {
-        try {
-            return $request->validate(array_merge([
-                'admin_reason' => ['required', 'string', 'max:2000'],
-            ], $rules), array_merge([
-                'admin_reason.required' => '理由を入力してください。',
-            ], $messages));
-        } catch (ValidationException $e) {
-            throw $e->redirectTo(route('approvals.requests.show', $approvalRequest));
-        }
-    }
-
-    /**
-     * Workflow を呼び、詳細の画面へ戻す（断られたら理由と打った中身、先を越されたら「すでに処理されています」）
-     *
-     * @param Closure(): void $action
-     */
-    private function run(ApprovalRequest $approvalRequest, Closure $action, string $success): RedirectResponse
-    {
-        $show = redirect()->route('approvals.requests.show', $approvalRequest);
-
-        try {
-            $action();
-        } catch (WorkflowConflict) {
-            return $show->with('error', WorkflowConflict::MESSAGE);
-        } catch (WorkflowRefused $e) {
-            return $show->withInput()->with('error', implode(' ', $e->reasons));
-        }
-
-        return $show->with('success', $success);
+        return $this->validateForDetail($request, $approvalRequest, array_merge([
+            'admin_reason' => ['required', 'string', 'max:2000'],
+        ], $rules), array_merge([
+            'admin_reason.required' => '理由を入力してください。',
+        ], $messages));
     }
 }
```

（差分のファイルを使うなら: `git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/patches/0001-*.patch`）

- [ ] **Step 5: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (44 tests, …)`

- [ ] **Step 6: 全件を流す**

Expected: `OK (3141 tests, 22396 assertions)`（2b の画面のテスト〈`AdminRequestActionTest` など〉が、寄せたあとも同じ振る舞いを見ている）

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Http/Controllers/Approval/Concerns/ReturnsToRequestDetail.php app/Http/Controllers/Approval/RequestActionController.php app/Http/Controllers/Approval/AdminRequestController.php tests/Feature/Approval/Phase2/RequestActionTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
refactor(approval): 詳細の画面へ戻す形を ReturnsToRequestDetail に寄せる

判断・条件確認・取り下げと決裁の管理者の 3 つの操作に同じ中身があった
「見られなければ 404・開き直す小窓・入力の検査・Workflow を呼んで詳細へ戻す」を
トレイトに寄せる（2b の最後の点検 I-1）。小窓のフラッシュは 404 の確かめのあとに
そろえ、見られない申請への操作でセッションに小窓が残らないようにする（M-4）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

### 1-B: 有効な審査担当者の条件を `activeReviewers()` の 1 か所にする

「有効な審査担当者」（無効の人・削除した人を除く）を、提出の条件（`SubmitChecker`）・申請種類の一覧の人数（`TypeController`）・⑩ の印（`AdminRequestController`）がそれぞれ書いていた。3a の `StepHandlers`（Task 3）が 4 か所目にならないよう、`ApprovalDepartment::activeReviewers()` に寄せる。「いま誰の番か」の名前（`CurrentHandler`）と部門の管理の一覧は `reviewers()` のまま（無効の人の名前も出す。振る舞いを変えない）。

**Files:**
- Modify: `app/Models/ApprovalDepartment.php`（`activeReviewers()` を足す）・`app/Support/Approval/SubmitChecker.php`・`app/Http/Controllers/Approval/TypeController.php`（`withCount('activeReviewers')`）・`resources/views/approvals/admin/types.blade.php`（`active_reviewers_count`）・`app/Http/Controllers/Approval/AdminRequestController.php`（⑩ の印）
- Test: Modify `tests/Feature/Approval/Phase2/Phase2ModelsTest.php`（1 本足す）

**Interfaces:**
- Produces: `ApprovalDepartment::activeReviewers(): BelongsToMany`（`reviewers()` に `users.status = active` を足したもの。削除した人は SoftDeletes で入らない）。Task 3 の `StepHandlers` が使う

**差分の大きさ:** 6 ファイル・+40 / −10 行（差分のファイル `0002-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/Phase2ModelsTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/Phase2ModelsTest.php
+++ b/tests/Feature/Approval/Phase2/Phase2ModelsTest.php
@@ -34,6 +34,26 @@ public function test_a_department_has_a_head_and_reviewers(): void
         $this->assertSame([$world['reviewer']->id], $world['reviewDept']->reviewers->pluck('id')->all());
     }
 
+    /**
+     * 有効な審査担当者（段階3 設計書 §5.7）: 無効の人と削除した人を入れない。審査担当者の並び（reviewers）には残る
+     * （「いま誰の番か」の表示と部門の管理はこちらを使う）
+     */
+    public function test_active_reviewers_leave_out_inactive_and_deleted_users(): void
+    {
+        $world    = $this->approvalWorld();
+        $inactive = $this->baseUser(['name' => '無効 審査']);
+        $inactive->forceFill(['status' => 'inactive'])->save();
+        $deleted  = $this->baseUser(['name' => '削除 審査']);
+        $world['reviewDept']->reviewers()->attach([$inactive->id, $deleted->id]);
+        $deleted->delete();
+
+        $department = $world['reviewDept']->fresh();
+
+        $this->assertSame([$world['reviewer']->id], $department->activeReviewers->pluck('id')->all());
+        $this->assertSame(1, $department->activeReviewers()->count());
+        $this->assertEqualsCanonicalizing([$world['reviewer']->id, $inactive->id], $department->reviewers->pluck('id')->all());
+    }
+
     /** 12.6 の歯止めが出す理由（部門長が先・次に審査担当者・どちらでもなければ null） */
     public function test_the_assignment_label_names_the_department(): void
     {
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase2/Phase2ModelsTest.php
```

Expected: `ERRORS!` `Tests: 15, Assertions: 36, Errors: 1.`

- `Phase2ModelsTest::test_active_reviewers_leave_out_inactive_and_deleted_users` — `Error: Call to a member function pluck() on null`

（関係が無いので `activeReviewers` のプロパティが null）

- [ ] **Step 3: 関係を足し、3 か所を寄せる**

`app/Models/ApprovalDepartment.php`（変更）

```diff
--- a/app/Models/ApprovalDepartment.php
+++ b/app/Models/ApprovalDepartment.php
@@ -2,6 +2,7 @@
 
 namespace App\Models;
 
+use App\Enums\UserStatus;
 use Illuminate\Database\Eloquent\Model;
 use Illuminate\Database\Eloquent\Relations\BelongsTo;
 use Illuminate\Database\Eloquent\Relations\BelongsToMany;
@@ -41,6 +42,18 @@ public function reviewers(): BelongsToMany
                     ->withTimestamps('created_at', false);
     }
 
+    /**
+     * 有効な審査担当者（今判断できる人。段階3 設計書 §5.7）。「有効な審査担当者」の条件はここ 1 か所にする。
+     * 提出の条件（SubmitChecker）・申請種類の一覧の人数（TypeController）・⑩ の印（AdminRequestController）・
+     * 知らせの宛先（StepHandlers）が使う。削除した人は reviewers() と同じく SoftDeletes で入らない。
+     *
+     * ⚠ 「いま誰の番か」の名前（CurrentHandler）と部門の管理の一覧は reviewers() のまま（無効の人の名前も出す。振る舞いを変えない）
+     */
+    public function activeReviewers(): BelongsToMany
+    {
+        return $this->reviewers()->where('users.status', UserStatus::Active->value);
+    }
+
     public function requests(): HasMany
     {
         return $this->hasMany(ApprovalRequest::class, 'department_id');
```

`app/Support/Approval/SubmitChecker.php`（変更）

```diff
--- a/app/Support/Approval/SubmitChecker.php
+++ b/app/Support/Approval/SubmitChecker.php
@@ -3,7 +3,6 @@
 namespace App\Support\Approval;
 
 use App\Enums\ApprovalStatus;
-use App\Enums\UserStatus;
 use App\Models\ApprovalRequest;
 use App\Models\ApprovalSetting;
 use App\Models\ApprovalType;
@@ -73,9 +72,8 @@ public static function reasons(ApprovalRequest $request, User $user): array
     /** 審査部門に、申請者本人以外の有効な審査担当者がいるか（4.3 のケース 4） */
     private static function hasOtherReviewer(ApprovalType $type, User $user): bool
     {
-        return $type->reviewDepartment->reviewers()
+        return $type->reviewDepartment->activeReviewers()
             ->where('users.id', '!=', $user->id)
-            ->where('users.status', UserStatus::Active->value)
             ->exists();
     }
 }
```

`app/Http/Controllers/Approval/TypeController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/TypeController.php
+++ b/app/Http/Controllers/Approval/TypeController.php
@@ -2,7 +2,6 @@
 
 namespace App\Http\Controllers\Approval;
 
-use App\Enums\UserStatus;
 use App\Http\Controllers\Controller;
 use App\Models\ApprovalDepartment;
 use App\Models\ApprovalType;
@@ -25,9 +24,9 @@ public function index()
     {
         // 審査部門の審査担当者の人数も読む（いない部門は一覧で知らせる。Task 11 の点検の軽微）。
         // 提出の条件（SubmitChecker）と同じく有効な人だけ数える（無効の人しかいないのに注意が出ない食い違いを無くす。
-        // 削除した人は User の SoftDeletes で数えない。2a の Task 11 の再点検 N-1）
+        // 削除した人は User の SoftDeletes で数えない。2a の Task 11 の再点検 N-1。条件は activeReviewers() の 1 か所）
         $types = ApprovalType::with(['reviewDepartment' => fn ($q) => $q
-                ->withCount(['reviewers' => fn ($q) => $q->where('users.status', UserStatus::Active->value)])
+                ->withCount('activeReviewers')
                 ->with('company')])
             ->withCount('requests')->ordered()->get();
```

`resources/views/approvals/admin/types.blade.php`（変更）

```diff
--- a/resources/views/approvals/admin/types.blade.php
+++ b/resources/views/approvals/admin/types.blade.php
@@ -73,7 +73,7 @@
                         <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">
                             {{ $type->reviewDepartment->company->name }}・{{ $type->reviewDepartment->name }}
                             {{-- 審査担当者のいない審査部門は、申請者の提出が断られて初めて分かるので、ここで知らせる（Task 11 の点検の軽微） --}}
-                            @if((int) $type->reviewDepartment->reviewers_count === 0)
+                            @if((int) $type->reviewDepartment->active_reviewers_count === 0)
                                 <span class="ml-1 text-[11px] font-semibold text-red-700">審査担当者がいません</span>
                             @endif
                         </td>
```

`app/Http/Controllers/Approval/AdminRequestController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/AdminRequestController.php
+++ b/app/Http/Controllers/Approval/AdminRequestController.php
@@ -5,7 +5,6 @@
 use App\Enums\ApprovalStatus;
 use App\Enums\ApprovalStepKind;
 use App\Enums\ApprovalStepStatus;
-use App\Enums\UserStatus;
 use App\Http\Controllers\Approval\Concerns\ReturnsToRequestDetail;
 use App\Http\Controllers\Controller;
 use App\Models\ApprovalRequest;
@@ -70,7 +69,8 @@ public function index(Request $request): View
         }
 
         $presidentId = ApprovalSetting::current()->president_user_id;
-        $rows = ApprovalRequest::with($with)
+        // 印の「審査担当者が申請者本人しかいない」は有効な人で数える（activeReviewers()）
+        $rows = ApprovalRequest::with([...$with, 'steps.department.activeReviewers'])
             ->whereIn('status', array_map(fn (ApprovalStatus $status) => $status->value, self::IN_PROGRESS))
             ->get()
             ->map(fn (ApprovalRequest $r) => $this->row($r, $presidentId))
@@ -172,7 +172,7 @@ private static function flags(ApprovalRequest $r, ApprovalStep $step, ?int $pres
     private static function onlyApplicantReviews(ApprovalRequest $r, ApprovalStep $step): bool
     {
         /** @var Collection<int, User> $active */
-        $active = ($step->department?->reviewers ?? collect())->filter(fn (User $u) => $u->status === UserStatus::Active);
+        $active = $step->department?->activeReviewers ?? collect();
 
         return $active->contains('id', $r->user_id) && $active->where('id', '!=', $r->user_id)->isEmpty();
     }
```

（差分のファイルを使うなら: `git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/patches/0002-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (15 tests, …)`

- [ ] **Step 5: 全件を流す**

Expected: `OK (3142 tests, 22399 assertions)`（提出の条件・一覧の注意・⑩ の印の既存のテストが、寄せたあとも同じ振る舞いを見ている）

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Models/ApprovalDepartment.php app/Support/Approval/SubmitChecker.php app/Http/Controllers/Approval/TypeController.php resources/views/approvals/admin/types.blade.php app/Http/Controllers/Approval/AdminRequestController.php tests/Feature/Approval/Phase2/Phase2ModelsTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
refactor(approval): 有効な審査担当者の条件を activeReviewers() の 1 か所にする

提出の条件・申請種類の一覧の人数・進行中の申請の印がそれぞれ書いていた
「有効な審査担当者」を ApprovalDepartment::activeReviewers() に寄せる
（段階3 設計書 §5.7）。名前を出す所は reviewers() のまま。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 2: 表（`notifications`・`approval_settings` の 3 列）とお知らせのモデル

お知らせの行を入れる表と、メールの送れた・送れなかったを残す 3 列を足す（設計書 §5.3・§0.2）。本番は SQL を手で流し、テストは同じ形の migration（`Phase3TablesTest` が突き合わせる）。お知らせのモデルは Laravel 標準の `DatabaseNotification` を土台にし、引くときは必ず持ち主で絞る `ownedBy()` を持たせる。

⚠ `approval_request_id` の `->comment()` は `->constrained()` の**前**に書く（後ろに書くと外部キーの定義に付き、列のコメントにならない。使い捨ての MySQL 8.4.11 で見つけた）。

**Files:**
- Create: `database/sql/2026-09-30-approval-phase3a.sql`（本番）・`database/migrations/2026_09_30_000001_create_approval_phase3a_tables.php`（テストの鏡）・`app/Models/ApprovalNotice.php`
- Test: Create `tests/Feature/Approval/Phase3/Phase3TablesTest.php`・Modify `tests/Feature/Approval/ApprovalAdminGateTest.php`（`tableCounts()` に `notifications`）・`tests/Feature/Approval/Phase2/Phase2TablesTest.php`（`LATER_COLUMNS`＝3a の 3 列は比べない）

**Interfaces:**
- Produces: 表 `notifications`（`id` CHAR(36)・`type`・`notifiable_type`・`notifiable_id`・`approval_request_id`〈NULL 可・RESTRICT〉・`data`・`read_at`・日時。索引 `idx_notifications_notifiable`〈notifiable_type, notifiable_id, read_at〉・`idx_notifications_request`）／`approval_settings` の `mail_last_sent_at`・`mail_last_failed_at`（TIMESTAMP）・`mail_last_failed_to`（VARCHAR(255)）
- Produces: `App\Models\ApprovalNotice extends DatabaseNotification`: `ApprovalNotice::TYPE = 'approval'`／スコープ `ownedBy(User $user)`（決裁のお知らせで持ち主がその人）／`approvalRequest(): BelongsTo`／`data` は配列・`read_at` は日時。Task 4 の `Notifier` が作り、Task 7 の画面が読む

**差分の大きさ:** 6 ファイル・+285 / −0 行（差分のファイル `0003-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase3/Phase3TablesTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase3;

use App\Models\ApprovalNotice;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 段階3（3a）の表（段階3 設計書 §5.3）。
 *
 * ⚠ 本番は SQL を手で流し、テストは migration で作る。**両方が食い違わないこと**をここで固定する（Phase2TablesTest と同じ考え）。
 *   比べるのは、作る・変える表・列の名前・NULL を許すか・外部キー。**両方向に比べる**。
 *   Phase2TablesTest の型の正規表現は CHAR を拾わないので（段階3 設計書 §4.5）、3a の表はこちらで見る（uuid の CHAR(36)）。
 */
class Phase3TablesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const SQL = 'database/sql/2026-09-30-approval-phase3a.sql';

    private const MIGRATION = 'database/migrations/2026_09_30_000001_create_approval_phase3a_tables.php';

    private const TYPES = 'BIGINT|INT|SMALLINT|TINYINT|CHAR|VARCHAR|MEDIUMTEXT|TEXT|JSON|TIMESTAMP|DATE';

    /** @return array<string, array{body: string, alter: bool}> 表 => CREATE の括弧の中か ALTER の中身 */
    private function statements(): array
    {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(base_path(self::SQL)));
        $statements = [];

        preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\n\) ENGINE/s', $sql, $creates, PREG_SET_ORDER);
        foreach ($creates as [, $table, $body]) {
            $statements[$table] = ['body' => $body, 'alter' => false];
        }

        preg_match_all('/ALTER TABLE `(\w+)`(.*?);/s', $sql, $alters, PREG_SET_ORDER);
        foreach ($alters as [, $table, $body]) {
            $statements[$table] = ['body' => $body, 'alter' => true];
        }

        return $statements;
    }

    /** @return array<string, array<string, bool>> 表 => [列 => NULL を許すか] */
    private function sqlColumns(): array
    {
        $tables = [];

        foreach ($this->statements() as $table => ['body' => $body, 'alter' => $alter]) {
            $prefix = $alter ? 'ADD COLUMN ' : '';
            preg_match_all('/' . $prefix . '`(\w+)` (?:' . self::TYPES . ')\b([^,]*)/', $body, $columns, PREG_SET_ORDER);
            foreach ($columns as [, $column, $definition]) {
                $tables[$table][$column] = ! str_contains($definition, 'NOT NULL');
            }
        }

        return $tables;
    }

    public function test_the_sql_and_the_migration_touch_the_same_tables(): void
    {
        preg_match_all("/Schema::(?:create|table)\\('(\\w+)'/", file_get_contents(base_path(self::MIGRATION)), $matches);

        $this->assertEqualsCanonicalizing(['approval_settings', 'notifications'], array_keys($this->statements()));
        $this->assertEqualsCanonicalizing(array_keys($this->statements()), array_values(array_unique($matches[1])));
    }

    public function test_the_sql_and_the_migration_declare_the_same_columns(): void
    {
        $sql = $this->sqlColumns();

        // 空振りで緑にならないように（CREATE 1 は 9 列・ALTER 1 は 3 列）
        $this->assertCount(9, $sql['notifications'] ?? [], 'SQL から notifications の列を読めていない');
        $this->assertCount(3, $sql['approval_settings'] ?? [], 'SQL から approval_settings に足す列を読めていない');

        foreach ($sql as $table => $columns) {
            $migrated = [];
            foreach (Schema::getColumns($table) as $column) {
                $migrated[$column['name']] = (bool) $column['nullable'];
            }

            if ($this->statements()[$table]['alter']) {
                // 前の段階から在る表は、足した列だけを比べる（足した列がすべて migration にあること）
                $migrated = array_intersect_key($migrated, $columns);
            }

            ksort($columns);
            ksort($migrated);
            $this->assertSame($columns, $migrated, "{$table} の列（名前と NULL を許すか）が SQL と migration で違う");
        }
    }

    public function test_the_sql_and_the_migration_declare_the_same_foreign_keys(): void
    {
        preg_match_all('/FOREIGN KEY \(`(\w+)`\) REFERENCES `(\w+)` \(`\w+`\) ON DELETE (\w+)/', $this->statements()['notifications']['body'], $keys, PREG_SET_ORDER);
        $this->assertSame([['FOREIGN KEY (`approval_request_id`) REFERENCES `approval_requests` (`id`) ON DELETE RESTRICT', 'approval_request_id', 'approval_requests', 'RESTRICT']], $keys);

        $migrated = array_map(
            fn (array $key) => [$key['columns'], $key['foreign_table'], strtolower((string) $key['on_delete'])],
            Schema::getForeignKeys('notifications'),
        );
        // SQLite は RESTRICT を restrict と返す（書かない＝NO ACTION と区別する）
        $this->assertSame([[['approval_request_id'], 'approval_requests', 'restrict']], $migrated);
    }

    /** 提出した申請はお知らせがある限り消せない（外部キーの RESTRICT。提出した申請はもともと消せない） */
    public function test_a_request_with_notices_cannot_be_deleted(): void
    {
        $world   = $this->approvalWorld();
        $request = $this->draftFor($world);
        $this->insertNotice($world['head'], $request->id);

        $this->expectException(QueryException::class);
        DB::table('approval_requests')->where('id', $request->id)->delete();
    }

    /** ownedBy() はその人の決裁のお知らせだけ（ほかの人のもの・決裁でない通知は入らない） */
    public function test_owned_by_returns_only_the_users_approval_notices(): void
    {
        $world   = $this->approvalWorld();
        $request = $this->draftFor($world);
        $mine    = $this->insertNotice($world['head'], $request->id);
        $this->insertNotice($world['reviewer'], $request->id);
        $this->insertNotice($world['head'], null, 'App\\Notifications\\SomethingElse');

        $this->assertSame([$mine], ApprovalNotice::ownedBy($world['head'])->pluck('id')->all());
        $this->assertSame($request->id, ApprovalNotice::find($mine)->approvalRequest->id);
    }

    private function insertNotice(User $user, ?int $requestId, string $type = ApprovalNotice::TYPE): string
    {
        $id = (string) Str::orderedUuid();

        DB::table('notifications')->insert([
            'id' => $id, 'type' => $type, 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
            'approval_request_id' => $requestId, 'data' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
```

`tests/Feature/Approval/ApprovalAdminGateTest.php`（変更）

```diff
--- a/tests/Feature/Approval/ApprovalAdminGateTest.php
+++ b/tests/Feature/Approval/ApprovalAdminGateTest.php
@@ -363,6 +363,8 @@ private function tableCounts(): array
             'approval_requests' => DB::table('approval_requests')->count(),
             'approval_steps' => DB::table('approval_steps')->count(),
             'approval_histories' => DB::table('approval_histories')->count(),
+            // お知らせ（段階3）。部門長の交代・審査担当者の追加は担当に知らせを作る
+            'notifications' => DB::table('notifications')->count(),
         ];
     }
```

`tests/Feature/Approval/Phase2/Phase2TablesTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/Phase2TablesTest.php
+++ b/tests/Feature/Approval/Phase2/Phase2TablesTest.php
@@ -31,6 +31,11 @@ class Phase2TablesTest extends TestCase
 
     private const TYPES = 'BIGINT|INT|SMALLINT|TINYINT|VARCHAR|MEDIUMTEXT|TEXT|JSON|TIMESTAMP';
 
+    /** あとの段階が 2a の表に足した列（その段階の表のテストが見る。ここでは比べない）。表 => 列 */
+    private const LATER_COLUMNS = [
+        'approval_settings' => ['mail_last_sent_at', 'mail_last_failed_at', 'mail_last_failed_to'],   // 3a（Phase3TablesTest）
+    ];
+
     /** @return list<array{string, string, bool}> [表, CREATE の括弧の中か ALTER の中身, ALTER か] */
     private function statements(string $path): array
     {
@@ -137,6 +142,7 @@ public function test_the_sql_and_the_migration_declare_the_same_columns(): void
             foreach (Schema::getColumns($table) as $column) {
                 $migrated[$column['name']] = (bool) $column['nullable'];
             }
+            $migrated = array_diff_key($migrated, array_flip(self::LATER_COLUMNS[$table] ?? []));
 
             ksort($columns);
             ksort($migrated);
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/patches/0003-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase2/Phase2TablesTest.php tests/Feature/Approval/Phase3/Phase3TablesTest.php
```

Expected: `ERRORS!` `Tests: 12, Assertions: 67, Errors: 6.`

- `ApprovalAdminGateTest::test_every_admin_route_refuses_outsiders_without_revealing_ids` — `Illuminate\Database\QueryException: SQLSTATE[HY000]: General error: 1 no such table: notifications (Connection: sqlite, Database: :memory:, SQL: select count(*)`
- `Phase3TablesTest::test_the_sql_and_the_migration_touch_the_same_tables` — `ErrorException: file_get_contents(/Users/masanori/site/manage/.claude/worktrees/approval-phase3/database/migratio`
- `Phase3TablesTest::test_the_sql_and_the_migration_declare_the_same_columns` — `ErrorException: file_get_contents(/Users/masanori/site/manage/.claude/worktrees/approval-phase3/database/sql/2026`
- `Phase3TablesTest::test_the_sql_and_the_migration_declare_the_same_foreign_keys` — `ErrorException: file_get_contents(/Users/masanori/site/manage/.claude/worktrees/approval-phase3/database/sql/2026`
- `Phase3TablesTest::test_a_request_with_notices_cannot_be_deleted` — `Error: Class "App\Models\ApprovalNotice" not found`
- `Phase3TablesTest::test_owned_by_returns_only_the_users_approval_notices` — `Error: Class "App\Models\ApprovalNotice" not found`

（表・SQL のファイル・モデルが無い。`Phase2TablesTest` は 3a の列がまだ無いので緑のまま）

- [ ] **Step 3: 本番の SQL とテストの鏡を書く**

`database/sql/2026-09-30-approval-phase3a.sql`（新規）

```sql
-- 決裁申請 段階3（3a）— 2026-09-30
--
-- 設計書: docs/superpowers/specs/2026-09-30-approval-phase3-design.md §5.3
--
-- ⚠ database/migrations/2026_09_30_000001_create_approval_phase3a_tables.php と
--   対で維持すること（あちらは SQLite のテストのための鏡。Phase3TablesTest が見る）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** 新しいコードは notifications と approval_settings.mail_last_* を読むので、
--   コードを先に送ると、決裁の画面（ヘッダーのベル・管理の画面の帯）が Unknown table / column で 500 になる。
--
-- ⚠ notifications は Laravel 標準の形に approval_request_id（その申請の未読を索引で引く）を足したもの（3a 計画 §0.2）。
--   索引名・外部キー名は段階1・2 と同じ流儀（索引は idx_・外部キーは fk_）。照合順序は utf8mb4_unicode_ci。
--
-- 適用: 段階1・2 と同じく php artisan tinker --execute で DB::statement() に **1 文ずつ**流す。
--   先頭で「notifications がすでにあるか、approval_settings に mail_last_sent_at があれば 1 文も流さずに止まる」確認をする（計画 Task 12）。

-- 1. 既存の表に列を足す（D2 の表示に使う。書くのは App\Support\Approval\MailDelivery）
ALTER TABLE `approval_settings`
  ADD COLUMN `mail_last_sent_at` TIMESTAMP NULL COMMENT '決裁のメールが最後に送れた日時' AFTER `launched_at`,
  ADD COLUMN `mail_last_failed_at` TIMESTAMP NULL COMMENT '決裁のメールが最後に送れなかった日時（送り直し 3 回のあと）' AFTER `mail_last_sent_at`,
  ADD COLUMN `mail_last_failed_to` VARCHAR(255) NULL COMMENT '最後に送れなかったメールの宛先（氏名）' AFTER `mail_last_failed_at`;

-- 2. 新しい表（お知らせ。1 人 1 行・消さない。D16）
CREATE TABLE `notifications` (
  `id` CHAR(36) NOT NULL,
  `type` VARCHAR(255) NOT NULL,
  `notifiable_type` VARCHAR(255) NOT NULL,
  `notifiable_id` BIGINT UNSIGNED NOT NULL,
  `approval_request_id` BIGINT UNSIGNED NULL COMMENT '決裁の申請（その申請の未読を既読にするため）',
  `data` TEXT NOT NULL,
  `read_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_notifiable` (`notifiable_type`, `notifiable_id`, `read_at`),
  KEY `idx_notifications_request` (`approval_request_id`),
  CONSTRAINT `fk_notifications_approval_request` FOREIGN KEY (`approval_request_id`) REFERENCES `approval_requests` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`database/migrations/2026_09_30_000001_create_approval_phase3a_tables.php`（新規）

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 決裁申請 段階3（3a）の表（段階3 設計書 §5.3）。
 *
 * ⚠ **これは SQLite のテストのための鏡**。本番は `database/sql/2026-09-30-approval-phase3a.sql` を
 *   手で流す（このプロジェクトは migration で本番を管理していない）。**両方を対で維持すること**（Phase3TablesTest が見る）。
 * ⚠ `notifications` は Laravel 標準の形に、その申請の未読を索引で引くための `approval_request_id` を足したもの（3a 計画 §0.2）。
 * ⚠ 索引名は本番の SQL と同じ名前を渡す（段階1・2 と同じ流儀）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_settings', function (Blueprint $table) {
            $table->timestamp('mail_last_sent_at')->nullable()->after('launched_at')
                  ->comment('決裁のメールが最後に送れた日時');
            $table->timestamp('mail_last_failed_at')->nullable()->after('mail_last_sent_at')
                  ->comment('決裁のメールが最後に送れなかった日時（送り直し 3 回のあと）');
            $table->string('mail_last_failed_to', 255)->nullable()->after('mail_last_failed_at')
                  ->comment('最後に送れなかったメールの宛先（氏名）');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->foreignId('approval_request_id')->nullable()->comment('決裁の申請（その申請の未読を既読にするため）')
                  ->constrained('approval_requests')->restrictOnDelete();
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'idx_notifications_notifiable');
            $table->index('approval_request_id', 'idx_notifications_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');

        Schema::table('approval_settings', function (Blueprint $table) {
            $table->dropColumn(['mail_last_sent_at', 'mail_last_failed_at', 'mail_last_failed_to']);
        });
    }
};
```

- [ ] **Step 4: お知らせのモデルを書く**

`app/Models/ApprovalNotice.php`（新規）

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;

/**
 * 決裁のお知らせ（段階3 設計書 §5.3・§5.6）。Laravel 標準の `notifications` 表の 1 行（1 人 1 行）。
 *
 * ⚠ 作るのは App\Support\Approval\Notifier だけ（宛先の決まりと、操作と同じトランザクション。§5.5）。
 * ⚠ 消さない（D16）。`data` は操作の時点の控え（あとで件名が変わってもお知らせの文は変えない）。
 * ⚠ 引くときは必ず ownedBy() で持ち主に絞る（ほかの人のお知らせを開かせない・既読にしない）。
 */
class ApprovalNotice extends DatabaseNotification
{
    /** 決裁のお知らせの印（`type` の列。Laravel の通知のクラスの名前の代わり） */
    public const TYPE = 'approval';

    protected $casts = [
        'data'                => 'array',
        'read_at'             => 'datetime',
        'approval_request_id' => 'integer',
    ];

    /** その人の決裁のお知らせだけに絞る */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where('type', self::TYPE)
              ->where('notifiable_type', $user->getMorphClass())
              ->where('notifiable_id', $user->id);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }
}
```

（差分のファイルを使うなら: `git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/patches/0003-*.patch`）

- [ ] **Step 5: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (12 tests, …)`

- [ ] **Step 6: 全件を流す**

Expected: `OK (3147 tests, 22412 assertions)`

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add database/sql/2026-09-30-approval-phase3a.sql database/migrations/2026_09_30_000001_create_approval_phase3a_tables.php app/Models/ApprovalNotice.php tests/Feature/Approval/Phase3/Phase3TablesTest.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase2/Phase2TablesTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): お知らせの表とメールの送れた・送れなかったの列を足す

notifications（Laravel 標準の形に、その申請の未読を索引で引くための
approval_request_id を足したもの）と approval_settings の 3 列を、本番の SQL と
テストの migration の対で足す（段階3 設計書 §5.3）。お知らせのモデル
ApprovalNotice は持ち主で絞る ownedBy() を持つ。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 3: 段階の今の担当（`StepHandlers`）

知らせの宛先にする「今判断できる人」を、段階ごとに人で返す部品（設計書 §5.4・§0.4）。規則は権限の `RequestPermissions::isAssigneeOf()` と同じで、そのうえで無効の人・削除した人・申請者本人を除く。4 か所目の規則として別に育たないよう、テストが「有効な人について、担当に入る ⇔ 対応待ち（`PendingWork::for()`）に出る ⇔ 判断できる（`judgeableStep()`）」を、いろいろな段階の申請 8 件 × 有効な人 8 人で突き合わせる。

**Files:**
- Create: `app/Support/Approval/StepHandlers.php`
- Test: Create `tests/Feature/Approval/Phase3/StepHandlersTest.php`

**Interfaces:**
- Consumes: `ApprovalDepartment::activeReviewers()`（Task 1-B）
- Produces: `StepHandlers::for(ApprovalStep $step, ApprovalRequest $request): Collection<int, User>`（部門長＝付け替えた人か申請部門の今の部門長／審査＝その審査部門の有効な審査担当者／社長＝今の社長。有効・削除されていない・申請者本人でない人だけ）／`StepHandlers::waitingStep(ApprovalRequest $request): ?ApprovalStep`（今の回で待っている段階。申請者の番と終わった申請は null）／`StepHandlers::ofWaiting(ApprovalRequest $request): Collection<int, User>`（その担当。無ければ空）。Task 4〜6 の `Notifier` と `Workflow` が使う

**差分の大きさ:** 2 ファイル・+196 / −0 行（差分のファイル `0004-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase3/StepHandlersTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase3;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\PendingWork;
use App\Support\Approval\RequestPermissions;
use App\Support\Approval\StepHandlers;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 段階の今の担当を人で返す部品（段階3 設計書 §5.4・§6 の「今の担当」）。
 *
 * ⚠ 規則を 4 か所目に書かないための突き合わせ: 有効な人について「StepHandlers が返す」⇔「対応待ち（PendingWork）に出る」
 *   ⇔「判断できる（RequestPermissions::judgeableStep）」を、いろいろな段階の申請と全員の組み合わせで確かめる。
 */
class StepHandlersTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private function workflow(): Workflow
    {
        return app(Workflow::class);
    }

    /** 判断して読み直す（判断を省くと部門長・社長は承認〈可〉、審査は可） */
    private function judge(string $kind, ApprovalRequest $request, User $actor, ?ApprovalStepResult $result = null): ApprovalRequest
    {
        $request->refresh();
        $result ??= $kind === 'review' ? ApprovalStepResult::Ok : ApprovalStepResult::Approve;
        $method   = ['head' => 'judgeHead', 'review' => 'judgeReview', 'president' => 'judgePresident'][$kind];
        $this->workflow()->{$method}($request, $actor, $request->lock_version, $result, $result->requiresComment() ? '理由です' : null);

        return $request->refresh();
    }

    /**
     * 部門長確認中（そのまま・付け替え）・審査中（審査担当者に申請者本人・無効・削除を混ぜる）・社長決裁待ち・差戻し中・
     * 条件確認待ち・決裁済みの申請と、全員の組み合わせ
     */
    public function test_the_handlers_are_the_people_who_have_it_in_their_pending_work_and_can_judge(): void
    {
        $w        = $this->approvalWorld();
        $admin    = $this->approvalAdmin();
        $other    = $this->baseUser(['name' => '付け替え 先']);
        $second   = $this->baseUser(['name' => '審査 二人目']);
        $inactive = $this->baseUser(['name' => '無効 審査']);
        $deleted  = $this->baseUser(['name' => '削除 審査']);
        // 審査担当者でもある申請者（自分の申請の審査の担当には入らない）
        $reviewerApplicant = $this->approvalOnlyUser(['name' => '審査 兼 申請']);
        $reviewerApplicant->approvalDepartments()->attach($w['dept']->id);
        $w['reviewDept']->reviewers()->attach([$second->id, $inactive->id, $deleted->id, $reviewerApplicant->id]);
        $inactive->forceFill(['status' => 'inactive'])->save();
        $deleted->delete();

        $headReview = $this->submittedFor($w);
        $reassigned = $this->submittedFor($w);
        $this->workflow()->reassignHead($reassigned, $admin, $reassigned->lock_version, $other, '出張のため');
        $review      = $this->judge('head', $this->submittedFor($w), $w['head']);
        $ownReview   = $this->judge('head', $this->submittedFor(array_merge($w, ['applicant' => $reviewerApplicant])), $w['head']);
        $president   = $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']);
        $returned    = $this->judge('head', $this->submittedFor($w), $w['head'], ApprovalStepResult::Return);
        $condition   = $this->judge('president', $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']), $w['president'], ApprovalStepResult::Conditional);
        $approved    = $this->judge('president', $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']), $w['president']);

        $requests = [$headReview, $reassigned, $review, $ownReview, $president, $returned, $condition, $approved];
        $people   = User::all();   // 削除した人は入らない（SoftDeletes）
        $this->assertCount(9, $people);

        $checked = 0;
        foreach ($requests as $request) {
            $request->refresh();
            $handlers = StepHandlers::ofWaiting($request)->pluck('id')->all();

            foreach ($people as $user) {
                if (! $user->isActive()) {
                    $this->assertNotContains($user->id, $handlers, "無効の {$user->name} が担当に入っている");
                    continue;
                }

                $pending = PendingWork::for($user)
                    ->filter(fn (array $item) => $item['role'] !== '申請者')
                    ->contains(fn (array $item) => $item['request']->id === $request->id);
                $judgeable = RequestPermissions::for($user, $request)->judgeableStep() !== null;
                $isHandler = in_array($user->id, $handlers, true);

                $this->assertSame($pending, $isHandler, "申請 {$request->id}（{$request->status->value}）と {$user->name}: 対応待ちと担当が食い違う");
                $this->assertSame($judgeable, $isHandler, "申請 {$request->id}（{$request->status->value}）と {$user->name}: 判断できるかと担当が食い違う");
                $checked++;
            }
        }

        // 空振りで緑にならないように（有効な人 8 人 × 申請 8 件）
        $this->assertSame(64, $checked);
        // 見ておきたい組み合わせが実際に起きている
        $this->assertSame([$w['head']->id], StepHandlers::ofWaiting($headReview)->pluck('id')->all());
        $this->assertSame([$other->id], StepHandlers::ofWaiting($reassigned)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$w['reviewer']->id, $second->id, $reviewerApplicant->id], StepHandlers::ofWaiting($review)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$w['reviewer']->id, $second->id], StepHandlers::ofWaiting($ownReview)->pluck('id')->all());
        $this->assertSame([$w['president']->id], StepHandlers::ofWaiting($president)->pluck('id')->all());
        $this->assertTrue(StepHandlers::ofWaiting($returned)->isEmpty());
        $this->assertTrue(StepHandlers::ofWaiting($condition)->isEmpty());
        $this->assertTrue(StepHandlers::ofWaiting($approved)->isEmpty());
    }

    /** 部門長・社長を削除したり無効にしたりすると、担当はいなくなる（知らせは誰にも出ない） */
    public function test_a_deleted_or_inactive_head_or_president_is_not_a_handler(): void
    {
        $w         = $this->approvalWorld();
        $head      = $this->submittedFor($w);
        $president = $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']);

        $w['head']->delete();
        $w['president']->forceFill(['status' => 'inactive'])->save();

        $this->assertTrue(StepHandlers::ofWaiting($head->refresh())->isEmpty());
        $this->assertTrue(StepHandlers::ofWaiting($president->refresh())->isEmpty());
    }

    /** 社長を申請者本人に替えると、その申請の社長の段階には担当がいない（自分の申請には判断できない。D16） */
    public function test_the_applicant_is_not_the_handler_even_as_the_president(): void
    {
        $w       = $this->approvalWorld();
        $request = $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']);

        $this->makePresident($w['applicant']);

        $this->assertTrue(StepHandlers::ofWaiting($request->refresh())->isEmpty());
        $this->assertNull(RequestPermissions::for($w['applicant'], $request)->judgeableStep());
    }
}
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/patches/0004-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase3/StepHandlersTest.php
```

Expected: `ERRORS!` `Tests: 3, Assertions: 1, Errors: 3.`

- `StepHandlersTest::test_the_handlers_are_the_people_who_have_it_in_their_pending_work_and_can_judge` — `Error: Class "App\Support\Approval\StepHandlers" not found`
- `StepHandlersTest::test_a_deleted_or_inactive_head_or_president_is_not_a_handler` — `Error: Class "App\Support\Approval\StepHandlers" not found`
- `StepHandlersTest::test_the_applicant_is_not_the_handler_even_as_the_president` — `Error: Class "App\Support\Approval\StepHandlers" not found`

- [ ] **Step 3: 部品を書く**

`app/Support/Approval/StepHandlers.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepStatus;
use App\Enums\UserStatus;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 段階の今の担当を人（User）で返す（知らせの宛先。段階3 設計書 §5.1・§5.4）。
 *
 * 規則は RequestPermissions::isAssigneeOf() と同じ（部門長＝付け替えた人か申請部門の今の部門長／審査＝その審査部門の
 * 有効な審査担当者／社長＝今の社長。要件 4.3 のケース 5〜7）で、そのうえで「今判断できる人」に絞る:
 * 無効の人・削除した人・申請者本人（D16）を除く。
 *
 * ⚠ 4 か所目の規則を別に書かない。RequestPermissions（権限）・PendingWork（対応待ち）と同じ人を返すことを
 *   StepHandlersTest の突き合わせが守る。CurrentHandler（表示の名前）は無効の人の名前も出すので別（振る舞いを変えない）。
 * ⚠ 部門長の交代で、部門の行を更新する前に呼ぶと前の部門長を返す（Workflow::headChanged() は新しい部門長を明示で渡す。
 *   3a 計画 §0.4）。
 */
final class StepHandlers
{
    /** その段階の今の担当（有効・削除されていない・申請者本人でない） @return Collection<int, User> */
    public static function for(ApprovalStep $step, ApprovalRequest $request): Collection
    {
        $users = match ($step->kind) {
            ApprovalStepKind::Head      => User::whereKey($step->assignee_user_id ?? $step->department?->head_user_id)->get(),
            ApprovalStepKind::Review    => $step->department?->activeReviewers()->get() ?? collect(),
            ApprovalStepKind::President => User::whereKey(ApprovalSetting::current()->president_user_id)->get(),
        };

        return $users
            ->filter(fn (User $user) => $user->status === UserStatus::Active && $user->id !== $request->user_id)
            ->values();
    }

    /** 今の回で待っている段階（無ければ null。申請者の番〈差戻し中・条件確認待ち〉と、終わった申請） */
    public static function waitingStep(ApprovalRequest $request): ?ApprovalStep
    {
        return ApprovalStep::with('department')
            ->where('request_id', $request->id)
            ->where('round', $request->round)
            ->where('status', ApprovalStepStatus::Waiting->value)
            ->first();
    }

    /** 今の回で待っている段階の担当（待っている段階が無ければ空） @return Collection<int, User> */
    public static function ofWaiting(ApprovalRequest $request): Collection
    {
        $step = self::waitingStep($request);

        return $step === null ? collect() : self::for($step, $request);
    }
}
```

（差分のファイルを使うなら: `git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/patches/0004-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (3 tests, …)`

- [ ] **Step 5: 全件を流す**

Expected: `OK (3150 tests, 22563 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/StepHandlers.php tests/Feature/Approval/Phase3/StepHandlersTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 段階の今の担当を人で返す StepHandlers を足す

知らせの宛先にする「今判断できる人」を段階ごとに返す（段階3 設計書 §5.4）。
規則は RequestPermissions と同じで、無効・削除した人と申請者本人を除く。
対応待ち（PendingWork）と判断できる段階（judgeableStep）との突き合わせで守る。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 4: 知らせを出す部品・文言・メールの土台と知らせのメール

知らせを出す 1 か所 `Notifier`（場面ごとの宛先と共通の決まり 7 つ）、文言の表 `NoticeText` と知らせの中身 `Notice`、メールの土台 `ApprovalMailable`（送り直し 3 回・送れた／送れなかったの記録）と知らせのメール `ApprovalNoticeMail`、記録の `MailDelivery` を作る（設計書 §5.4・§5.5・§0.3〜§0.5）。この Task ではまだどこからも呼ばない（Task 5・6 で組み込む）。テストは `Notifier` を直接呼んで決まりを 1 つずつ見る（申請は**使い始める前に** Workflow で進めておき、使い始めてから呼ぶ＝Workflow からの知らせと混ざらない。§0.12）。

**Files:**
- Create: `app/Support/Approval/Notifier.php`・`app/Support/Approval/Notice.php`・`app/Support/Approval/NoticeText.php`・`app/Support/Approval/MailDelivery.php`・`app/Mail/ApprovalMailable.php`・`app/Mail/ApprovalNoticeMail.php`・`resources/views/mail/approval-notice.blade.php`
- Test: Create `tests/Concerns/ReadsApprovalNotices.php`・`tests/Feature/Approval/Phase3/NotifierTest.php`・Modify `tests/Feature/ClockReadScanTest.php`（`MailDelivery.php` の 2 件を理由つきで登録）

**Interfaces:**
- Consumes: `StepHandlers::for()`・`waitingStep()`（Task 3）・`ApprovalNotice`（Task 2）・`ApprovalSetting::launchedForMenu()`・`ApprovalMailDomain::allows()`・`ApprovalHistory::label()`（既存）
- Produces: `Notifier`（どれも static・呼ぶ側のトランザクションの中で呼ぶ）: `turnArrived(ApprovalRequest $request, User $actor): void`／`handlerChanged(User $actor, iterable<ApprovalStep> $steps, Collection|User|null $to, string $why): void`（`$step->request` を読むので、呼ぶ側が関係を持たせて渡す）／`returned(ApprovalRequest $request, User $actor, ApprovalStepKind $by): void`／`decided(ApprovalRequest $request, User $actor, ApprovalDecision $decision): void`／`conditionConfirmed(ApprovalRequest $request, User $actor): void`／`withdrawn(ApprovalRequest $request, User $actor, Collection $handlers, bool $byAdmin): void`／`undone(ApprovalRequest $request, User $admin, ApprovalHistory $target): void`。Task 5・6 が呼ぶ
- Produces: `NoticeText::NO_ACTION`・`REASSIGNED`・`HEAD_CHANGED`・`PRESIDENT_CHANGED`・`REVIEWER_ADDED`（場面 2 のわけ）と、`turn()`・`handlerChanged()`・`returned()`・`decided()`・`conditionRequested()`・`conditionConfirmed()`・`withdrawn()`・`undone()`・`undoneTurn()`（どれも `Notice` を返す）／`new Notice(string $scene, string $headline, string $action, list<string> $lead, bool $mailed = true, bool $handlerTurn = false)`
- Produces: `abstract class ApprovalMailable extends Mailable implements ShouldQueue`（`FROM_NAME`・`$tries = 3`・`$backoff = 60`・`send()` で送れた記録・`failed()` でログと送れなかった記録・抽象 `failedRecipientName(): string`・`failureLabel(): string`）。Task 8 の `PasswordReissuedMail` もこれに移る／`new ApprovalNoticeMail(string $recipientName, Notice $notice, array{subject: string, number: ?string, applicant: string, department: string, url: string} $context)`
- Produces: `MailDelivery::recordSent(): void`・`MailDelivery::recordFailed(string $recipientName): void`（Task 8 で `pendingFailure()` を足す）
- Produces（テストの土台）: トレイト `Tests\Concerns\ReadsApprovalNotices`: `mailable(User $user, string $local): User`・`noticesOf(User $user): Collection`・`headlinesOf(User $user): list<string>`・`mailSubjectsTo(string $address): list<string>`。Task 5〜8 のテストが使う

**差分の大きさ:** 10 ファイル・+882 / −0 行（差分のファイル `0005-…`）

- [ ] **Step 1: テストの土台と、失敗するテストを書く**

`tests/Concerns/ReadsApprovalNotices.php`（新規）

```php
<?php

namespace Tests\Concerns;

use App\Mail\ApprovalNoticeMail;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalNotice;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * 段階3 のお知らせとメールを読むテストの土台（3a 計画 §0.12）。BuildsApprovalFixtures と一緒に使う。
 *
 * ⚠ 通知メールは許可したドメインの人にだけ届く（要件 8.2）。ファクトリのメールアドレスは example.* なので、
 *   届く人にするには mailable() で mitsuwat.co.jp のアドレスを付ける。
 */
trait ReadsApprovalNotices
{
    /** 許可したドメイン（mitsuwat.co.jp）のメールアドレスを付ける（通知メールが届く人にする） */
    protected function mailable(User $user, string $local): User
    {
        ApprovalMailDomain::firstOrCreate(['domain' => 'mitsuwat.co.jp']);
        $user->update(['email' => "{$local}@mitsuwat.co.jp"]);

        return $user->fresh();
    }

    /** その人のお知らせ（作った順） @return Collection<int, ApprovalNotice> */
    protected function noticesOf(User $user): Collection
    {
        return ApprovalNotice::ownedBy($user)->orderBy('created_at')->orderBy('id')->get();
    }

    /** その人のお知らせの見出し（作った順） @return list<string> */
    protected function headlinesOf(User $user): array
    {
        return $this->noticesOf($user)->map(fn (ApprovalNotice $notice) => $notice->data['headline'])->all();
    }

    /** その宛先に積んだ知らせのメールの件名（積んだ順・Mail::fake() のあと） @return list<string> */
    protected function mailSubjectsTo(string $address): array
    {
        return Mail::queued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $mail) => $mail->hasTo($address))
            ->map(fn (ApprovalNoticeMail $mail) => $mail->envelope()->subject)
            ->values()
            ->all();
    }
}
```

`tests/Feature/Approval/Phase3/NotifierTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase3;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Mail\ApprovalNoticeMail;
use App\Models\ApprovalNotice;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\Approval\Notifier;
use App\Support\Approval\NoticeText;
use App\Support\Approval\Workflow;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ReadsApprovalNotices;
use Tests\TestCase;

/**
 * 知らせを出す部品（段階3 設計書 §5.4 の共通の決まり・§5.5 の作り方）。
 *
 * ⚠ 申請は使い始める前に Workflow で進めておき（使い始める前は知らせが出ない）、使い始めてから Notifier を直接呼ぶ
 *   （Workflow からの呼び出しは WorkflowNoticeTest が見る）。
 */
class NotifierTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ReadsApprovalNotices;

    private function judge(ApprovalRequest $request, User $actor, string $kind, ?ApprovalStepResult $result = null): ApprovalRequest
    {
        $request->refresh();
        $result ??= $kind === 'review' ? ApprovalStepResult::Ok : ApprovalStepResult::Approve;
        $method   = ['head' => 'judgeHead', 'review' => 'judgeReview', 'president' => 'judgePresident'][$kind];
        app(Workflow::class)->{$method}($request, $actor, $request->lock_version, $result, $result->requiresComment() ? '理由です' : null);

        return $request->refresh();
    }

    /** 使い始める前は何も作らない（決まり 7・§5.2） */
    public function test_nothing_is_made_before_launch(): void
    {
        Mail::fake();
        $w       = $this->approvalWorld();
        $head    = $this->mailable($w['head'], 'head');
        $request = $this->submittedFor($w);

        Notifier::turnArrived($request, $w['applicant']);

        $this->assertSame(0, ApprovalNotice::count());
        Mail::assertNothingQueued();
        $this->assertSame([], $this->headlinesOf($head));
    }

    /** 番が来た担当に、お知らせ 1 つとメール 1 通（件名・宛先・差出人の名前・お知らせの控え） */
    public function test_the_handler_gets_a_notice_and_a_mail(): void
    {
        Mail::fake();
        $w       = $this->approvalWorld();
        $head    = $this->mailable($w['head'], 'head');
        $request = $this->submittedFor($w, ['subject' => 'A&B社の<契約>について']);
        $this->launchApprovals();

        Notifier::turnArrived($request, $w['applicant']);

        $notice = $this->noticesOf($head)->sole();
        $this->assertSame($request->id, $notice->approval_request_id);
        $this->assertNull($notice->read_at);
        $this->assertSame([
            'scene' => 'turn', 'headline' => '部門長の確認のお願い', 'subject' => 'A&B社の<契約>について', 'number' => null,
            'applicant' => '申請 花子', 'department' => '住宅事業部', 'action' => '部門長の確認（承認か差戻し）', 'actor' => '申請 花子',
        ], $notice->data);

        $this->assertSame(['【決裁】部門長の確認のお願い：A&B社の<契約>について'], $this->mailSubjectsTo('head@mitsuwat.co.jp'));
        Mail::assertQueued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $mail) => $mail->envelope()->from->name === 'ミツワ都市開発 決裁システム'
            && $mail->envelope()->from->address === config('mail.from.address'));
        Mail::assertQueuedCount(1);
    }

    /** メールに書くのは件名・申請者（申請部門）・決裁No・必要な対応・リンクだけ（要件 8.2）。& や < はそのまま（テキストのメール） */
    public function test_the_mail_says_only_what_the_requirements_allow(): void
    {
        Mail::fake();
        $w       = $this->approvalWorld();
        $this->mailable($w['applicant'], 'applicant');
        $request = $this->judge($this->submittedFor($w, ['subject' => 'A&B社の<契約>について', 'body' => "■ なぜ\n・老朽化のため\n"]), $w['head'], 'head', ApprovalStepResult::Return);
        $this->launchApprovals();

        Notifier::returned($request, $w['head'], ApprovalStepKind::Head);

        $mail = Mail::queued(ApprovalNoticeMail::class)->sole();
        $text = $mail->render();

        $this->assertSame('【決裁】差戻しされました：A&B社の<契約>について', $mail->envelope()->subject);
        $this->assertStringStartsWith("申請 花子 様\n\n次の申請が部門長から差し戻されました。\n\n", $text);
        $this->assertStringContainsString("件名　　　: A&B社の<契約>について\n", $text);
        $this->assertStringContainsString("申請者　　: 申請 花子（住宅事業部）\n", $text);
        $this->assertStringContainsString("決裁No　　: まだありません\n", $text);
        $this->assertStringContainsString("必要な対応: 直して出し直すか、取り下げてください\n", $text);
        $this->assertStringContainsString("▼ 申請を開く\n" . route('approvals.requests.show', $request) . "\n", $text);
        // 金額・本文・差戻しの理由（コメント）は書かない
        foreach (['2,850,000', '2850000', '老朽化', '理由です', '&amp;', '&lt;'] as $never) {
            $this->assertStringNotContainsString($never, $text);
        }
    }

    /** 決まり 1: 操作した本人には出さない（自分を新しい部門長にした管理者） */
    public function test_the_person_who_did_it_gets_nothing(): void
    {
        Mail::fake();
        $w       = $this->approvalWorld();
        $admin   = $this->mailable($this->approvalAdmin(), 'admin');
        $request = $this->submittedFor($w);
        $this->launchApprovals();

        $step = ApprovalStep::with('request')->where('request_id', $request->id)->where('kind', 'head')->sole();
        Notifier::handlerChanged($admin, [$step], $admin, NoticeText::HEAD_CHANGED);

        $this->assertSame(0, ApprovalNotice::count());
        Mail::assertNothingQueued();
    }

    /** 決まり 2: 1 回の操作で同じ人に同じ申請の知らせは 1 つ（部門長として承認し、審査担当者として意見も入れた人） */
    public function test_one_person_gets_one_notice_for_a_request_in_one_operation(): void
    {
        Mail::fake();
        $w = $this->approvalWorld();
        $w['reviewDept']->reviewers()->attach($w['head']->id);
        $head    = $this->mailable($w['head'], 'head');
        $request = $this->judge($this->judge($this->submittedFor($w), $w['head'], 'head'), $w['head'], 'review');
        $request = $this->judge($request, $w['president'], 'president');
        $this->launchApprovals();

        Notifier::decided($request, $w['president'], ApprovalDecision::Approve);

        $this->assertSame(['決裁されました（可）'], $this->headlinesOf($head));
        $this->assertSame(['決裁されました（可）'], $this->headlinesOf($w['applicant']));
        $this->assertCount(1, $this->mailSubjectsTo('head@mitsuwat.co.jp'));
    }

    /** 決まり 3: 無効の人・削除した人には何も出さない */
    public function test_inactive_or_deleted_people_get_nothing(): void
    {
        Mail::fake();
        $w        = $this->approvalWorld();
        $inactive = $this->mailable($this->baseUser(['name' => '無効 審査']), 'inactive');
        $deleted  = $this->mailable($this->baseUser(['name' => '削除 審査']), 'deleted');
        $request  = $this->judge($this->submittedFor($w), $w['head'], 'head');
        $this->launchApprovals();
        $inactive->forceFill(['status' => 'inactive'])->save();
        $deleted->delete();

        $step = ApprovalStep::with('request')->where('request_id', $request->id)->where('kind', 'review')->sole();
        Notifier::handlerChanged($w['president'], [$step], collect([$inactive, User::withTrashed()->find($deleted->id), $w['reviewer']]), NoticeText::REVIEWER_ADDED);

        $this->assertSame([], $this->headlinesOf($inactive));
        $this->assertSame(0, ApprovalNotice::where('notifiable_id', $deleted->id)->count());
        $this->assertSame(['審査の意見のお願い'], $this->headlinesOf($w['reviewer']));
        Mail::assertNothingQueued();   // 届く 1 人（審査 担当）は許可していないドメイン
    }

    /** 決まり 4: メールはアドレスがあり許可したドメインの人だけ（ほかの人はお知らせだけ） */
    public function test_only_people_on_an_allowed_domain_get_a_mail(): void
    {
        Mail::fake();
        $w        = $this->approvalWorld();
        $allowed  = $this->mailable($this->baseUser(['name' => '社内 審査']), 'inhouse');
        $outside  = $this->baseUser(['name' => '社外 審査', 'email' => 'someone@example.com']);
        $noEmail  = $this->approvalOnlyUser(['name' => 'メール無し 審査']);
        $w['reviewDept']->reviewers()->attach([$allowed->id, $outside->id, $noEmail->id]);
        $request = $this->judge($this->submittedFor($w), $w['head'], 'head');
        $this->launchApprovals();

        Notifier::turnArrived($request, $w['head']);

        foreach ([$allowed, $outside, $noEmail, $w['reviewer']] as $reviewer) {
            $this->assertSame(['審査の意見のお願い'], $this->headlinesOf($reviewer), $reviewer->name);
        }
        $this->assertSame(['【決裁】審査の意見のお願い：社用車の購入'], $this->mailSubjectsTo('inhouse@mitsuwat.co.jp'));
        Mail::assertQueuedCount(1);
    }

    /** 決まり 5: 担当の番の知らせは申請者本人に出さない（申請者本人を社長にした）。申請者への知らせ（差戻し）は出す */
    public function test_the_applicant_gets_no_turn_notice_for_their_own_request(): void
    {
        Mail::fake();
        $w       = $this->approvalWorld();
        $request = $this->judge($this->judge($this->submittedFor($w), $w['head'], 'head'), $w['reviewer'], 'review');
        $this->launchApprovals();
        $this->makePresident($w['applicant']);

        $step = ApprovalStep::with('request')->where('request_id', $request->id)->where('kind', 'president')->sole();
        Notifier::handlerChanged($w['president'], [$step], $w['applicant'], NoticeText::PRESIDENT_CHANGED);
        $this->assertSame([], $this->headlinesOf($w['applicant']));

        Notifier::returned($request, $w['president'], ApprovalStepKind::President);
        $this->assertSame(['差戻しされました'], $this->headlinesOf($w['applicant']));
    }

    /** 決まり 6: 件名と申請部門は最後に提出した控えから（差戻し中の直しかけは出さない。D26） */
    public function test_the_subject_comes_from_the_last_submitted_content(): void
    {
        Mail::fake();
        $w       = $this->approvalWorld();
        $request = $this->judge($this->submittedFor($w), $w['head'], 'head', ApprovalStepResult::Return);
        DB::table('approval_requests')->where('id', $request->id)->update(['subject' => '直しかけの件名']);
        $this->launchApprovals();

        Notifier::returned($request->refresh(), $w['head'], ApprovalStepKind::Head);

        $this->assertSame('社用車の購入', $this->noticesOf($w['applicant'])->sole()->data['subject']);
    }

    /** 件名の改行などの制御文字は空白にする（メールの件名が 1 行に収まる） */
    public function test_control_characters_in_the_subject_become_spaces(): void
    {
        Mail::fake();
        $w       = $this->approvalWorld();
        $this->mailable($w['head'], 'head');
        $request = $this->submittedFor($w, ['subject' => "社用車の\r\nBcc: someone@example.com"]);
        $this->launchApprovals();

        Notifier::turnArrived($request, $w['applicant']);

        $this->assertSame(['【決裁】部門長の確認のお願い：社用車の Bcc: someone@example.com'], $this->mailSubjectsTo('head@mitsuwat.co.jp'));
    }

    /**
     * お知らせの行とメールの jobs の行は、操作と同じトランザクションで巻き戻る（キューが database のとき。§5.5）。
     * ⚠ Mail::fake() はキューへの挿入を飛ばすので、ここでは偽物にせず、キューを database にして jobs の行を数える
     */
    public function test_the_notice_and_the_queued_mail_roll_back_with_the_operation(): void
    {
        config(['queue.default' => 'database']);
        $this->assertNull(config('queue.connections.database.connection'), 'キューが別の接続だと、操作と一緒に巻き戻らない');
        $w       = $this->approvalWorld();
        $this->mailable($w['head'], 'head');
        $request = $this->submittedFor($w);
        $this->launchApprovals();

        try {
            DB::transaction(function () use ($request, $w): void {
                Notifier::turnArrived($request, $w['applicant']);
                throw new RuntimeException('操作が途中で失敗した');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, ApprovalNotice::count());
        $this->assertSame(0, DB::table('jobs')->count());

        DB::transaction(fn () => Notifier::turnArrived($request, $w['applicant']));

        $this->assertSame(1, ApprovalNotice::count());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    /** 送り直しは 3 回・60 秒あけ（要件 15.3） */
    public function test_the_mail_is_tried_three_times_a_minute_apart(): void
    {
        $job = new SendQueuedMailable(new ApprovalNoticeMail('部門 長', NoticeText::turn(ApprovalStepKind::Head), [
            'subject' => '件名', 'number' => null, 'applicant' => '申請 花子', 'department' => '住宅事業部', 'url' => 'http://localhost/x',
        ]));

        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->backoff());
    }

    /** 送れたら「送れた日時」、3 回だめなら laravel.log と「送れなかった日時と宛先」を記録する（D2） */
    public function test_a_sent_and_a_failed_mail_are_recorded(): void
    {
        $this->travelTo(now()->startOfSecond());
        $settings = ApprovalSetting::current();
        $updated  = DB::table('approval_settings')->where('id', $settings->id)->value('updated_at');
        $mail     = (new ApprovalNoticeMail('部門 長', NoticeText::turn(ApprovalStepKind::Head), [
            'subject' => '件名', 'number' => null, 'applicant' => '申請 花子', 'department' => '住宅事業部', 'url' => 'http://localhost/x',
        ]))->to('head@mitsuwat.co.jp');

        $mail->send(app(MailFactory::class));

        $row = DB::table('approval_settings')->where('id', $settings->id)->first();
        $this->assertSame(now()->format('Y-m-d H:i:s'), $row->mail_last_sent_at);
        $this->assertNull($row->mail_last_failed_at);

        $this->travel(5)->minutes();
        Log::shouldReceive('error')->once()->with('決裁の通知メールを送れませんでした（宛先: head@mitsuwat.co.jp）: 送信サーバーにつながらない');
        $mail->failed(new RuntimeException('送信サーバーにつながらない'));

        $row = DB::table('approval_settings')->where('id', $settings->id)->first();
        $this->assertSame(now()->format('Y-m-d H:i:s'), $row->mail_last_failed_at);
        $this->assertSame('部門 長', $row->mail_last_failed_to);
        $this->assertSame($updated, $row->updated_at, '設定の更新日時は動かさない');
    }
}
```

`tests/Feature/ClockReadScanTest.php`（変更）

```diff
--- a/tests/Feature/ClockReadScanTest.php
+++ b/tests/Feature/ClockReadScanTest.php
@@ -56,6 +56,7 @@ class ClockReadScanTest extends TestCase
         'app/Support/Approval/ApprovalNumber.php'             => [1, '連番の行を作った瞬間（created_at・updated_at は TIMESTAMP 列。upsert は Eloquent を通らないので手で入れる）'],
         'app/Support/Approval/Workflow.php'                   => [9, '提出・届いた・判断・完了・状態が変わった瞬間と updated_at（付け替えの版の繰り上げ・取り消しで段階を戻したときを含む。すべて TIMESTAMP 列。画面では JapanTime で日本時間に直して出す）'],
         'app/Http/Controllers/Approval/RequestAttachmentController.php' => [1, 'approval_attachments.removed_at は TIMESTAMP 列（外した瞬間を UTC で保存する）'],
+        'app/Support/Approval/MailDelivery.php'               => [2, '決裁のメールが送れた・送れなかった瞬間（approval_settings の TIMESTAMP 列。帯では JapanTime で日本時間に直して出す）'],
     ];
 
     /** @return list<array{int, string}> [行, 呼び出し] */
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/patches/0005-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase3/NotifierTest.php tests/Feature/ClockReadScanTest.php
```

Expected: `ERRORS!` `Tests: 18, Assertions: 65, Errors: 13, Failures: 1.`

- `NotifierTest::test_nothing_is_made_before_launch` — `Error: Class "App\Support\Approval\Notifier" not found`
- `NotifierTest::test_the_handler_gets_a_notice_and_a_mail` — `Error: Class "App\Support\Approval\Notifier" not found`
- `NotifierTest::test_the_mail_says_only_what_the_requirements_allow` — `Error: Class "App\Support\Approval\Notifier" not found`
- `NotifierTest::test_the_person_who_did_it_gets_nothing` — `Error: Class "App\Support\Approval\Notifier" not found`
- `NotifierTest::test_one_person_gets_one_notice_for_a_request_in_one_operation` — `Error: Class "App\Support\Approval\Notifier" not found`
- `NotifierTest::test_inactive_or_deleted_people_get_nothing` — `Error: Class "App\Support\Approval\Notifier" not found`
- `NotifierTest::test_only_people_on_an_allowed_domain_get_a_mail` — `Error: Class "App\Support\Approval\Notifier" not found`
- `NotifierTest::test_the_applicant_gets_no_turn_notice_for_their_own_request` — `Error: Class "App\Support\Approval\Notifier" not found`
- `NotifierTest::test_the_subject_comes_from_the_last_submitted_content` — `Error: Class "App\Support\Approval\Notifier" not found`
- `NotifierTest::test_control_characters_in_the_subject_become_spaces` — `Error: Class "App\Support\Approval\Notifier" not found`
- `NotifierTest::test_the_notice_and_the_queued_mail_roll_back_with_the_operation` — `Error: Class "App\Support\Approval\Notifier" not found`
- `NotifierTest::test_the_mail_is_tried_three_times_a_minute_apart` — `Error: Class "App\Mail\ApprovalNoticeMail" not found`
- `NotifierTest::test_a_sent_and_a_failed_mail_are_recorded` — `Error: Class "App\Mail\ApprovalNoticeMail" not found`
- `ClockReadScanTest::test_php_clock_reads_are_classified` — `時計の読み取りが分類と合わない:`

（部品が無い。`ClockReadScanTest` は、まだ無い `MailDelivery.php` を登録したので分類が合わない）

- [ ] **Step 3: 知らせの中身と文言の表を書く**（文言は §0.5 の表のとおり。金額・本文・添付・コメントは書かない）

`app/Support/Approval/Notice.php`（新規）

```php
<?php

namespace App\Support\Approval;

/**
 * 1 人に出す 1 つの知らせの中身（段階3 設計書 §5.5）。作るのは NoticeText だけ（文言を 1 か所に置く）。
 *
 * ⚠ メールに持たせてキューに積むので、中身は文字と真偽だけにする（モデルを持たせない。キューの中で読み直さない）。
 */
final class Notice
{
    /**
     * @param string       $scene       場面（お知らせの data に残す。turn・changed・returned・decided・condition・confirmed・withdrawn・undone）
     * @param string       $headline    見出し（メールの件名の「【決裁】」の後ろ・お知らせの文の頭）
     * @param string       $action      必要な対応
     * @param list<string> $lead        メールの本文の前置き（宛名の次の行から）
     * @param bool         $mailed      メールも送るか（場面 6 はお知らせだけ）
     * @param bool         $handlerTurn 担当の番の知らせか（申請者本人には出さない。§5.4 の決まり 5）
     */
    public function __construct(
        public readonly string $scene,
        public readonly string $headline,
        public readonly string $action,
        public readonly array $lead,
        public readonly bool $mailed = true,
        public readonly bool $handlerTurn = false,
    ) {}
}
```

`app/Support/Approval/NoticeText.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStepKind;

/**
 * 場面ごとの知らせの文（メールの件名の見出し・必要な対応・本文の前置き）を 1 か所に（段階3 設計書 §5.5 の表）。
 * お知らせの文もメールの件名も同じ見出しから作る。
 *
 * ⚠ 金額・本文・添付・コメント（差戻しの理由・条件）は書かない（要件 8.2）。
 */
final class NoticeText
{
    /** 対応が要らない知らせの「必要な対応」 */
    public const NO_ACTION = '対応は要りません（お知らせです）';

    /** 場面 2 のわけ（本文の 1 行目。D1） */
    public const REASSIGNED        = '決裁の管理者が担当を付け替えたため、あなたの担当になりました。';
    public const HEAD_CHANGED      = '部門長が交代したため、あなたの担当になりました。';
    public const PRESIDENT_CHANGED = '社長の指定が変わったため、あなたの担当になりました。';
    public const REVIEWER_ADDED    = '審査担当者に加わったため、あなたの担当になりました。';

    /** 担当の番（段階ごと）: [見出し, 必要な対応, 番の言い方] */
    private const TURNS = [
        'head'      => ['部門長の確認のお願い', '部門長の確認（承認か差戻し）', '確認'],
        'review'    => ['審査の意見のお願い', '審査の意見（可・保留・否）', '審査の意見'],
        'president' => ['社長の決裁のお願い', '社長の決裁（可・条可・差戻し・否）', '決裁'],
    ];

    private const CONDITION_ACTION = '条件を確かめて「条件を確認しました」を押してください';

    /** 場面 1: 自分の番が来た */
    public static function turn(ApprovalStepKind $kind): Notice
    {
        [$headline, $action, $turn] = self::TURNS[$kind->value];

        return new Notice('turn', $headline, $action, ["次の申請が、あなたの{$turn}の番になりました。"], handlerTurn: true);
    }

    /** 場面 2: 担当が入れ替わった（件名と対応は場面 1 と同じ。本文の 1 行目にわけ） */
    public static function handlerChanged(ApprovalStepKind $kind, string $why): Notice
    {
        [$headline, $action, $turn] = self::TURNS[$kind->value];

        return new Notice('changed', $headline, $action, [$why, "次の申請が、あなたの{$turn}の番になりました。"], handlerTurn: true);
    }

    /** 場面 3: 差戻しされた（申請者へ） */
    public static function returned(ApprovalStepKind $by): Notice
    {
        return new Notice('returned', '差戻しされました', '直して出し直すか、取り下げてください', ["次の申請が{$by->label()}から差し戻されました。"]);
    }

    /** 場面 4（可・否）と、場面 5 の部門長・審査担当者（条可） */
    public static function decided(ApprovalDecision $decision): Notice
    {
        return match ($decision) {
            ApprovalDecision::Approve     => new Notice('decided', '決裁されました（可）', self::NO_ACTION, ['次の申請が決裁されました（可）。']),
            ApprovalDecision::Conditional => new Notice('decided', '決裁されました（条可）', self::NO_ACTION, ['次の申請が条件付きで決裁されました（条可）。']),
            ApprovalDecision::Reject      => new Notice('decided', '否決されました', self::NO_ACTION, ['次の申請が否決されました。']),
        };
    }

    /** 場面 5 の申請者: 条件の確認のお願い */
    public static function conditionRequested(): Notice
    {
        return new Notice('condition', '条件の確認のお願い（条可）', self::CONDITION_ACTION, ['次の申請が条件付きで決裁されました（条可）。社長の条件を確かめてください。']);
    }

    /** 場面 6: 条件が確認された（お知らせだけ） */
    public static function conditionConfirmed(): Notice
    {
        return new Notice('confirmed', '条件が確認されました', self::NO_ACTION, ['申請者が条件を確認し、決裁が完了しました。'], mailed: false);
    }

    /** 場面 7: 取り下げられた（管理者の代理なら、そう書く。D8） */
    public static function withdrawn(bool $byAdmin): Notice
    {
        return new Notice('withdrawn', '取り下げられました', self::NO_ACTION, [
            $byAdmin ? '決裁の管理者が、申請者に代わって次の申請を取り下げました。' : '申請者が次の申請を取り下げました。',
        ]);
    }

    /** 場面 8: 操作が取り消された（取り消された操作をした人・申請者。対応は要らない） */
    public static function undone(string $undoneLabel): Notice
    {
        return new Notice('undone', '操作が取り消されました', self::NO_ACTION, ["決裁の管理者が「{$undoneLabel}」を取り消しました。"]);
    }

    /**
     * 場面 8: 取り消しで番が戻った人（戻った段階の今の担当。$kind が null なら条件の確認を取り消された申請者）
     */
    public static function undoneTurn(string $undoneLabel, ?ApprovalStepKind $kind): Notice
    {
        $first = "決裁の管理者が「{$undoneLabel}」を取り消しました。";

        if ($kind === null) {
            return new Notice('undone', '操作が取り消されました', self::CONDITION_ACTION, [$first, 'もう一度、条件を確認してください。']);
        }

        [, $action, $turn] = self::TURNS[$kind->value];

        return new Notice('undone', '操作が取り消されました', $action, [$first, "次の申請が、もう一度あなたの{$turn}の番になりました。"], handlerTurn: true);
    }
}
```

- [ ] **Step 4: メールの土台・記録・知らせのメールを書く**（本文はテキストだけ・`{!! !!}`。§0.3）

`app/Support/Approval/MailDelivery.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Models\ApprovalSetting;
use Illuminate\Support\Facades\DB;

/**
 * 決裁のメールが送れたか・送れなかったかの記録（段階3 設計書 D2・§5.5）。決裁の管理者の画面の黄色の帯に使う。
 *
 * ⚠ 書くのはキューの中（ApprovalMailable）。ApprovalSetting::current() を通さない（行が無ければ作るうえ、長く動くキュー処理では
 *   覚えた設定が古いままになる。§4.3）。本番は SQL が入れた 1 行がある。DB::table で書くのは、設定の updated_at を動かさない
 *   ため（メールを送るたびに「設定を変えた日時」が動いて見えないように）
 * ⚠ 送れなかったメールの送り直しはしない（D2。止まった申請は朝の催促で拾われる）。1 通でも送れたら帯は消える
 */
final class MailDelivery
{
    /** 1 通送れた */
    public static function recordSent(): void
    {
        DB::table('approval_settings')->where('id', ApprovalSetting::SINGLETON_ID)->update(['mail_last_sent_at' => now()]);
    }

    /** 送り直し 3 回のあとも送れなかった（宛先は氏名で残す） */
    public static function recordFailed(string $recipientName): void
    {
        DB::table('approval_settings')->where('id', ApprovalSetting::SINGLETON_ID)->update([
            'mail_last_failed_at' => now(),
            'mail_last_failed_to' => mb_substr($recipientName, 0, 255),
        ]);
    }
}
```

`app/Mail/ApprovalMailable.php`（新規）

```php
<?php

namespace App\Mail;

use App\Support\Approval\MailDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 決裁のメールの土台（段階3 設計書 §5.5・D2・D6）。キューに積んで送る。
 *
 * - 送り直しは 3 回・60 秒あけ（要件 15.3。キュー処理の起動のオプション `--tries=3 --backoff=60` と同じ）
 * - 送れたら「最後に送れた日時」、3 回だめなら laravel.log と「最後に送れなかった日時と宛先」を記録する
 *   （決裁の管理者の画面の黄色の帯。MailDelivery）
 *
 * ⚠ 中身（宛先の名前・リンク）は積むときに決めて持たせる。キューの中で route() を呼ぶと本番で /index.php が抜け、
 *   ApprovalSetting::current() は長く動くキュー処理で古い値を読む（段階3 設計書 §4.2・§4.3）。
 */
abstract class ApprovalMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** 決裁の知らせの差出人の名前（D12。アドレスは全体の設定 MAIL_FROM_ADDRESS のまま） */
    public const FROM_NAME = 'ミツワ都市開発 決裁システム';

    public $tries = 3;

    public $backoff = 60;

    /** 帯に出す宛先（氏名） */
    abstract protected function failedRecipientName(): string;

    /** 送れなかったときに laravel.log に書く頭の言葉 */
    abstract protected function failureLabel(): string;

    /** 送れたら記録する（キューの中ではここを通って送る。SendQueuedMailable::handle） */
    public function send($mailer)
    {
        $sent = parent::send($mailer);

        if ($sent !== null) {
            MailDelivery::recordSent();
        }

        return $sent;
    }

    /** 3 回送れなかった（キューが呼ぶ） */
    public function failed(Throwable $e): void
    {
        Log::error($this->failureLabel() . '（宛先: ' . implode('、', array_column($this->to, 'address')) . '）: ' . $e->getMessage());
        MailDelivery::recordFailed($this->failedRecipientName());
    }
}
```

`app/Mail/ApprovalNoticeMail.php`（新規）

```php
<?php

namespace App\Mail;

use App\Support\Approval\Notice;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 決裁の知らせのメール（段階3 設計書 §5.5・要件 8.2）。テキストだけ。作るのは Notifier だけ。
 *
 * ⚠ 書くのは件名・申請者（申請部門）・決裁No・必要な対応・リンクだけ。金額・本文・添付・コメント（差戻しの理由・条件）は
 *   書かない（8.2）。材料は Notifier が操作の時点で決めて渡す（キューの中で読み直さない）
 * ⚠ Laravel 標準の通知メールの雛形は使わない（英語の「Hello!」などが出る。Top trap #10）
 * ⚠ 本文はテキストなので `{!! !!}` で書く（`{{ }}` だと件名の & が &amp; のまま届く）
 */
class ApprovalNoticeMail extends ApprovalMailable
{
    /**
     * @param array{subject: string, number: ?string, applicant: string, department: string, url: string} $context
     */
    public function __construct(
        public string $recipientName,
        public Notice $notice,
        public array $context,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), self::FROM_NAME),
            subject: '【決裁】' . $this->notice->headline . '：' . $this->context['subject'],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.approval-notice');
    }

    protected function failedRecipientName(): string
    {
        return $this->recipientName;
    }

    protected function failureLabel(): string
    {
        return '決裁の通知メールを送れませんでした';
    }
}
```

`resources/views/mail/approval-notice.blade.php`（新規）

```blade
{!! $recipientName !!} 様

@foreach($notice->lead as $line)
{!! $line !!}
@endforeach

件名　　　: {!! $context['subject'] !!}
申請者　　: {!! $context['applicant'] !!}（{!! $context['department'] !!}）
決裁No　　: {!! $context['number'] ?? 'まだありません' !!}
必要な対応: {!! $notice->action !!}

▼ 申請を開く
{!! $context['url'] !!}

・このメールは送信専用です。返信しても届きません。
・金額や本文はメールに書いていません。リンクから開いて確かめてください。
```

- [ ] **Step 5: 知らせを出す 1 か所を書く**（場面ごとの宛先と共通の決まり 7 つ。§0.4）

`app/Support/Approval/Notifier.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\UserStatus;
use App\Mail\ApprovalNoticeMail;
use App\Models\ApprovalHistory;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalNotice;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * 知らせを出す 1 か所（段階3 設計書 §5.1・§5.4・§5.5）。場面ごとに宛先を決め、共通の決まりで絞り、1 人 1 つのお知らせを
 * 作ってメールを積む。
 *
 * ⚠ **呼ぶ側のトランザクションの中で呼ぶ**（Workflow の各操作・部門の管理・社長の指定）。お知らせの行もメールの `jobs` の行も
 *   同じ DB なので、操作が途中で失敗して巻き戻ればどちらも残らない（PasswordReissuer と同じ。`QUEUE_CONNECTION=database` で
 *   `DB_QUEUE_CONNECTION` が空だから成り立つ。redis などに替えると「起きなかった操作の知らせが届く」が無音で戻る）
 * ⚠ 宛先・文の材料・リンクは操作の時点で決めてメールに持たせる（キューの中で設定や route() を読まない。本番で /index.php が抜ける）
 *
 * 共通の決まり（§5.4）: 1 操作した本人には出さない ／ 2 1 回の操作で同じ人に同じ申請の知らせは 1 つ（先に足したものを残すので、
 * 対応が要る知らせを先に足す）／ 3 無効の人・削除した人には出さない ／ 4 メールはアドレスがあり許可したドメインの人だけ
 * （ほかの人はお知らせだけ）／ 5 担当の番の知らせは申請者本人に出さない（D16）／ 6 件名と申請部門は最後に提出した控えから
 * （差戻し中の直しかけを出さない。D26）／ 7 使い始める前は何もしない（§5.2）
 */
final class Notifier
{
    /** @var array<string, array{user: User, request: ApprovalRequest, notice: Notice}> 「申請の id:人の id」 => 知らせ */
    private array $entries = [];

    private function __construct(private readonly User $actor)
    {
    }

    /** 場面 1: 自分の番が来た（提出・出し直し・部門長の承認・審査の意見のあと。今の回で待っている段階の担当へ） */
    public static function turnArrived(ApprovalRequest $request, User $actor): void
    {
        $step = StepHandlers::waitingStep($request);

        if ($step !== null) {
            (new self($actor))->add(StepHandlers::for($step, $request), $request, NoticeText::turn($step->kind))->send();
        }
    }

    /**
     * 場面 2: 担当が入れ替わった（付け替え・部門長の交代・社長の交代・審査担当者の追加。D1）。新しい担当は呼ぶ側が明示で渡す
     * （部門長の交代は部門の行を更新する前に呼ばれるので、StepHandlers では前の部門長が出る）
     *
     * @param iterable<ApprovalStep> $steps 待っている段階（1 つの操作で移ったもの。申請ごとに 1 つずつ）
     * @param Collection<int, User>|User|null $to 新しい担当
     */
    public static function handlerChanged(User $actor, iterable $steps, Collection|User|null $to, string $why): void
    {
        $notifier = new self($actor);

        foreach ($steps as $step) {
            $notifier->add($to, $step->request, NoticeText::handlerChanged($step->kind, $why));
        }

        $notifier->send();
    }

    /** 場面 3: 差戻しされた（申請者へ） */
    public static function returned(ApprovalRequest $request, User $actor, ApprovalStepKind $by): void
    {
        (new self($actor))->add($request->applicant, $request, NoticeText::returned($by))->send();
    }

    /**
     * 場面 4・5: 社長の判断。申請者（条可なら条件の確認のお願い）と、その回で部門長として判断した人・意見を入れた審査担当者
     * （段階の actor。部門長の段階を省いた回は部門長がいない。D7）
     */
    public static function decided(ApprovalRequest $request, User $actor, ApprovalDecision $decision): void
    {
        $toApplicant = $decision === ApprovalDecision::Conditional ? NoticeText::conditionRequested() : NoticeText::decided($decision);

        (new self($actor))
            ->add($request->applicant, $request, $toApplicant)
            ->add(self::actorsOf($request, ApprovalStepKind::Head, ApprovalStepKind::Review), $request, NoticeText::decided($decision))
            ->send();
    }

    /** 場面 6: 条件が確認された（決裁した社長と、部門長として判断した人。お知らせだけ。D7） */
    public static function conditionConfirmed(ApprovalRequest $request, User $actor): void
    {
        (new self($actor))
            ->add(self::actorsOf($request, ApprovalStepKind::President, ApprovalStepKind::Head), $request, NoticeText::conditionConfirmed())
            ->send();
    }

    /**
     * 場面 7: 取り下げられた（その時点の担当。管理者の代理の取り下げなら申請者にも。D8）
     *
     * @param Collection<int, User> $handlers 取り下げの前に待っていた段階の担当（段階を打ち切る前に StepHandlers::ofWaiting() で取る）
     */
    public static function withdrawn(ApprovalRequest $request, User $actor, Collection $handlers, bool $byAdmin): void
    {
        $notice = NoticeText::withdrawn($byAdmin);

        (new self($actor))
            ->add($handlers, $request, $notice)
            ->add($byAdmin ? $request->applicant : null, $request, $notice)
            ->send();
    }

    /**
     * 場面 8: 操作が取り消された。番が戻った人（戻った段階の今の担当・条件の確認の取り消しなら申請者）に「もう一度」を先に足し、
     * 取り消された操作をした人と申請者に「取り消されました」（重なった人は先の知らせだけ。決まり 2）。
     * 決裁のあとの取り消しで、場面 4 を受け取った部門長・審査担当者には出さない（D10）
     */
    public static function undone(ApprovalRequest $request, User $admin, ApprovalHistory $target): void
    {
        $label    = $target->label();
        $step     = StepHandlers::waitingStep($request);
        $notifier = new self($admin);

        if ($step !== null) {
            $notifier->add(StepHandlers::for($step, $request), $request, NoticeText::undoneTurn($label, $step->kind));
        } elseif ($request->status === ApprovalStatus::Condition) {
            $notifier->add($request->applicant, $request, NoticeText::undoneTurn($label, null));
        }

        $notifier
            ->add($target->actor, $request, NoticeText::undone($label))
            ->add($request->applicant, $request, NoticeText::undone($label))
            ->send();
    }

    /** その回の段階で判断した人（段階の並び順） @return Collection<int, User> */
    private static function actorsOf(ApprovalRequest $request, ApprovalStepKind ...$kinds): Collection
    {
        return ApprovalStep::with('actor')
            ->where('request_id', $request->id)
            ->where('round', $request->round)
            ->whereIn('kind', array_map(fn (ApprovalStepKind $kind) => $kind->value, $kinds))
            ->get()
            ->sortBy(fn (ApprovalStep $step) => array_search($step->kind, $kinds, true))
            ->map(fn (ApprovalStep $step) => $step->actor)
            ->filter()
            ->values();
    }

    /** @param Collection<int, User>|User|null $users */
    private function add(Collection|User|null $users, ApprovalRequest $request, Notice $notice): self
    {
        foreach ($users instanceof User ? [$users] : ($users ?? []) as $user) {
            $this->entries[$request->id . ':' . $user->id] ??= ['user' => $user, 'request' => $request, 'notice' => $notice];
        }

        return $this;
    }

    private function send(): void
    {
        // ⚠ 行を作らずに読む（部門の管理・社長の指定は使い始める前から呼ばれる。§5.2）
        if ($this->entries === [] || ! ApprovalSetting::launchedForMenu()) {
            return;
        }

        $contexts = [];

        foreach ($this->entries as ['user' => $user, 'request' => $request, 'notice' => $notice]) {
            if (! $this->receives($user, $request, $notice)) {
                continue;
            }

            $context = $contexts[$request->id] ??= self::context($request);

            ApprovalNotice::create([
                'id'                  => (string) Str::orderedUuid(),
                'type'                => ApprovalNotice::TYPE,
                'notifiable_type'     => $user->getMorphClass(),
                'notifiable_id'       => $user->id,
                'approval_request_id' => $request->id,
                'data'                => [
                    'scene'      => $notice->scene,
                    'headline'   => $notice->headline,
                    'subject'    => $context['subject'],
                    'number'     => $context['number'],
                    'applicant'  => $context['applicant'],
                    'department' => $context['department'],
                    'action'     => $notice->action,
                    'actor'      => $this->actor->name,
                ],
            ]);

            if ($notice->mailed && ApprovalMailDomain::allows($user->email)) {
                Mail::to($user->email)->queue(new ApprovalNoticeMail($user->name, $notice, $context));
            }
        }
    }

    /** 共通の決まり 1・3・5 */
    private function receives(User $user, ApprovalRequest $request, Notice $notice): bool
    {
        return $user->id !== $this->actor->id
            && ! $user->trashed()
            && $user->status === UserStatus::Active
            && ! ($notice->handlerTurn && $user->id === $request->user_id);
    }

    /**
     * 申請ごとの材料（件名と申請部門は最後に提出した控えから。決まり 6）とリンク（操作の画面のリクエストから作る＝本番の
     * /index.php が入る）
     *
     * @return array{subject: string, number: ?string, applicant: string, department: string, url: string}
     */
    private static function context(ApprovalRequest $request): array
    {
        $snapshot = ApprovalRevision::where('request_id', $request->id)->where('round', $request->round)->first()?->snapshot ?? [];

        return [
            'subject'    => self::oneLine($snapshot['subject'] ?? $request->subject),
            'number'     => $request->number,
            'applicant'  => self::oneLine($request->applicant?->name),
            'department' => self::oneLine($snapshot['department']['name'] ?? null),
            'url'        => route('approvals.requests.show', $request),
        ];
    }

    /** メールの件名に入るので、改行などの制御文字を空白にする */
    private static function oneLine(?string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $text));
    }
}
```

（差分のファイルを使うなら: `git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/patches/0005-*.patch`）

- [ ] **Step 6: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (18 tests, …)`

- [ ] **Step 7: 全件を流す**

Expected: `OK (3163 tests, 22626 assertions)`

- [ ] **Step 8: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/Notifier.php app/Support/Approval/Notice.php app/Support/Approval/NoticeText.php app/Support/Approval/MailDelivery.php app/Mail/ApprovalMailable.php app/Mail/ApprovalNoticeMail.php resources/views/mail/approval-notice.blade.php tests/Concerns/ReadsApprovalNotices.php tests/Feature/Approval/Phase3/NotifierTest.php tests/Feature/ClockReadScanTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 知らせを出す Notifier とお知らせの文・知らせのメールを足す

場面ごとに宛先を決め、共通の決まり（操作した本人を除く・1 人 1 つ・無効と削除を
除く・許可したドメインだけメール・担当の番は申請者に出さない・件名は提出した控え・
使い始める前は何もしない）で絞って、お知らせの行とキューのメールを作る
（段階3 設計書 §5.4・§5.5）。メールの土台 ApprovalMailable は送り直し 3 回と
送れた・送れなかったの記録を持つ。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 5: Workflow への組み込み（場面 1・3〜8 と付け替え）

状態を変える `Workflow` の各操作の最後（同じトランザクションの中）で `Notifier` を呼ぶ（設計書 §5.1・§5.4・§0.4 の表）。断られた操作・先を越された操作は巻き戻るので、知らせも残らない。取り下げは、段階を打ち切る**前**にその時点の担当を `StepHandlers::ofWaiting()` で取っておく。

**Files:**
- Modify: `app/Support/Approval/Workflow.php`（`submit`・`confirmCondition`・`withdraw`・`reassignHead`・`undo`・`withdrawByAdmin`・`afterHead`・`afterReview`・`afterPresident`）
- Test: Create `tests/Feature/Approval/Phase3/WorkflowNoticeTest.php`

**Interfaces:**
- Consumes: `Notifier` の 7 つ・`NoticeText::REASSIGNED`（Task 4）・`StepHandlers::ofWaiting()`（Task 3）
- Produces: なし（Workflow の外から見た形は変わらない。知らせが増えるだけ）

**差分の大きさ:** 2 ファイル・+378 / −0 行（差分のファイル `0006-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase3/WorkflowNoticeTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase3;

use App\Enums\ApprovalStepResult;
use App\Mail\ApprovalNoticeMail;
use App\Models\ApprovalNotice;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\Workflow;
use App\Support\Approval\WorkflowConflict;
use App\Support\Approval\WorkflowRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ReadsApprovalNotices;
use Tests\TestCase;

/**
 * 申請の操作から出る知らせ（段階3 設計書 §5.4 の場面 1・3〜8 と付け替え・D7〜D11）。
 *
 * 組織: 部門長・審査担当者 2 人（審査 担当・審査 二人目）・社長・申請者（approvalWorld に 1 人足す）。
 * ⚠ 使い始めてから操作する（使い始める前は何も出ない。NotifierTest）。
 */
class WorkflowNoticeTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ReadsApprovalNotices;

    /** @var array<string, mixed> */
    private array $w;

    private User $second;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->w      = $this->approvalWorld();
        $this->second = $this->baseUser(['name' => '審査 二人目']);
        $this->w['reviewDept']->reviewers()->attach($this->second->id);
        $this->admin  = $this->approvalAdmin();
        $this->launchApprovals();
    }

    private function workflow(): Workflow
    {
        return app(Workflow::class);
    }

    private function judge(ApprovalRequest $request, User $actor, string $kind, ?ApprovalStepResult $result = null): ApprovalRequest
    {
        $request->refresh();
        $result ??= $kind === 'review' ? ApprovalStepResult::Ok : ApprovalStepResult::Approve;
        $method   = ['head' => 'judgeHead', 'review' => 'judgeReview', 'president' => 'judgePresident'][$kind];
        $this->workflow()->{$method}($request, $actor, $request->lock_version, $result, $result->requiresComment() ? '理由です' : null);

        return $request->refresh();
    }

    /** 社長の決裁待ちまで進めた申請 */
    private function atPresident(): ApprovalRequest
    {
        return $this->judge($this->judge($this->submittedFor($this->w), $this->w['head'], 'head'), $this->w['reviewer'], 'review');
    }

    /** 人ごとのお知らせの見出し（知らせが無い人は出さない） @return array<string, list<string>> */
    private function everyone(): array
    {
        $out = [];
        foreach (User::withTrashed()->orderBy('id')->get() as $user) {
            if (($headlines = $this->headlinesOf($user)) !== []) {
                $out[$user->name] = $headlines;
            }
        }

        return $out;
    }

    private function forget(): void
    {
        ApprovalNotice::query()->delete();
    }

    /** 場面 1: 提出 → 部門長だけ */
    public function test_a_submission_reaches_the_head_only(): void
    {
        $this->submittedFor($this->w);

        $this->assertSame(['部門 長' => ['部門長の確認のお願い']], $this->everyone());
    }

    /** 場面 1: 申請者が申請部門の部門長なら部門長の段階を省き、審査担当者全員に届く（4.3 のケース 1） */
    public function test_a_submission_by_the_head_reaches_every_reviewer(): void
    {
        $this->w['head']->approvalDepartments()->attach($this->w['dept']->id);
        $this->submittedFor(array_merge($this->w, ['applicant' => $this->w['head']]));

        $this->assertSame(['審査 担当' => ['審査の意見のお願い'], '審査 二人目' => ['審査の意見のお願い']], $this->everyone());
    }

    /** 場面 1: 部門長の承認 → 審査担当者全員 ／ 審査の意見 → 社長だけ（ほかの審査担当者には出さない。D11） */
    public function test_each_judgement_reaches_the_next_handlers(): void
    {
        $request = $this->submittedFor($this->w);
        $this->forget();

        $this->judge($request, $this->w['head'], 'head');
        $this->assertSame(['審査 担当' => ['審査の意見のお願い'], '審査 二人目' => ['審査の意見のお願い']], $this->everyone());

        $this->forget();
        $this->judge($request, $this->w['reviewer'], 'review');
        $this->assertSame(['社長 太郎' => ['社長の決裁のお願い']], $this->everyone());
    }

    /** 場面 3: 部門長・社長の差戻し → 申請者。出し直し → 部門長にもう一度 */
    public function test_a_return_reaches_the_applicant_and_a_resubmission_reaches_the_head_again(): void
    {
        $request = $this->submittedFor($this->w);
        $this->forget();

        $this->judge($request, $this->w['head'], 'head', ApprovalStepResult::Return);
        $this->assertSame(['申請 花子' => ['差戻しされました']], $this->everyone());
        $this->assertSame('差戻しされました', $this->noticesOf($this->w['applicant'])->sole()->data['headline']);

        $this->forget();
        $this->workflow()->submit($request->refresh(), $this->w['applicant'], $request->lock_version);
        $this->assertSame(['部門 長' => ['部門長の確認のお願い']], $this->everyone());

        $this->forget();
        $request = $this->judge($this->judge($request, $this->w['head'], 'head'), $this->w['reviewer'], 'review');
        $this->forget();
        $this->judge($request, $this->w['president'], 'president', ApprovalStepResult::Return);
        $this->assertSame(['申請 花子' => ['差戻しされました']], $this->everyone());
    }

    /** 場面 4: 可 → 申請者・部門長として判断した人・意見を入れた審査担当者（意見を入れていない審査担当者には出さない。D7・D11） */
    public function test_an_approval_reaches_the_applicant_and_the_people_who_judged(): void
    {
        $request = $this->atPresident();
        $this->forget();

        $this->judge($request, $this->w['president'], 'president');

        $this->assertSame([
            '部門 長'   => ['決裁されました（可）'],
            '審査 担当' => ['決裁されました（可）'],
            '申請 花子' => ['決裁されました（可）'],
        ], $this->everyone());
    }

    /** 場面 4: 否 → 同じ人に「否決されました」 */
    public function test_a_rejection_reaches_the_same_people(): void
    {
        $request = $this->atPresident();
        $this->forget();

        $this->judge($request, $this->w['president'], 'president', ApprovalStepResult::Reject);

        $this->assertSame(['部門 長' => ['否決されました'], '審査 担当' => ['否決されました'], '申請 花子' => ['否決されました']], $this->everyone());
    }

    /** 場面 4 の「部門長」は判断した人（承認のあとで部門長が交代しても、承認した人に届く。D7） */
    public function test_the_head_who_judged_gets_the_decision_after_a_head_change(): void
    {
        $request = $this->atPresident();
        $newHead = $this->baseUser(['name' => '新 部門長']);
        $this->w['dept']->update(['head_user_id' => $newHead->id]);
        $this->forget();

        $this->judge($request, $this->w['president'], 'president');

        $this->assertArrayHasKey('部門 長', $this->everyone());
        $this->assertArrayNotHasKey('新 部門長', $this->everyone());
    }

    /** 場面 5: 条可 → 申請者に「条件の確認のお願い」、部門長・審査担当者に「決裁されました（条可）」 */
    public function test_a_conditional_approval_asks_the_applicant_to_confirm(): void
    {
        $request = $this->atPresident();
        $this->forget();

        $this->judge($request, $this->w['president'], 'president', ApprovalStepResult::Conditional);

        $this->assertSame([
            '部門 長'   => ['決裁されました（条可）'],
            '審査 担当' => ['決裁されました（条可）'],
            '申請 花子' => ['条件の確認のお願い（条可）'],
        ], $this->everyone());
    }

    /** 場面 6: 条件の確認 → 決裁した社長と部門長として判断した人（お知らせだけ・メールは無し） */
    public function test_a_confirmed_condition_is_a_notice_only_for_the_president_and_the_head(): void
    {
        $request = $this->judge($this->atPresident(), $this->w['president'], 'president', ApprovalStepResult::Conditional);
        $this->forget();
        $this->mailable($this->w['president'], 'president');

        $this->workflow()->confirmCondition($request, $this->w['applicant'], $request->lock_version, null);

        $this->assertSame(['部門 長' => ['条件が確認されました'], '社長 太郎' => ['条件が確認されました']], $this->everyone());
        $this->assertSame([], $this->mailSubjectsTo('president@mitsuwat.co.jp'));
    }

    /** 場面 7: 審査中に申請者が取り下げ → 審査担当者全員（その時点の担当）。済んだ部門長と、まだ届いていない社長には出さない */
    public function test_a_withdrawal_reaches_the_handlers_at_that_time(): void
    {
        $request = $this->judge($this->submittedFor($this->w), $this->w['head'], 'head');
        $this->forget();

        $this->workflow()->withdraw($request, $this->w['applicant'], $request->lock_version, null);

        $this->assertSame(['審査 担当' => ['取り下げられました'], '審査 二人目' => ['取り下げられました']], $this->everyone());
    }

    /** 場面 7: 差戻し中（申請者の番）に申請者が取り下げても、誰にも出ない（本人の操作） */
    public function test_a_withdrawal_of_a_returned_request_reaches_nobody(): void
    {
        $request = $this->judge($this->submittedFor($this->w), $this->w['head'], 'head', ApprovalStepResult::Return);
        $this->forget();

        $this->workflow()->withdraw($request, $this->w['applicant'], $request->lock_version, null);

        $this->assertSame([], $this->everyone());
    }

    /** 場面 7: 管理者の代理の取り下げ → その時点の担当と申請者（D8） */
    public function test_a_withdrawal_by_the_admin_also_reaches_the_applicant(): void
    {
        $request = $this->submittedFor($this->w);
        $this->forget();
        $this->mailable($this->w['applicant'], 'applicant');

        $this->workflow()->withdrawByAdmin($request, $this->admin, $request->lock_version, '申請者が休職のため');

        $this->assertSame(['部門 長' => ['取り下げられました'], '申請 花子' => ['取り下げられました']], $this->everyone());
        $mail = Mail::queued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $m) => $m->hasTo('applicant@mitsuwat.co.jp'))->sole();
        $this->assertSame(['決裁の管理者が、申請者に代わって次の申請を取り下げました。'], $mail->notice->lead);
    }

    /** 付け替え → 付け替え先だけ（わけを添える）。外れた部門長には出さない（D9） */
    public function test_a_reassignment_reaches_the_new_assignee_only(): void
    {
        $other   = $this->mailable($this->baseUser(['name' => '付け替え 先']), 'other');
        $request = $this->submittedFor($this->w);
        $this->forget();

        $this->workflow()->reassignHead($request, $this->admin, $request->lock_version, $other, '出張のため');

        $this->assertSame(['付け替え 先' => ['部門長の確認のお願い']], $this->everyone());
        $mail = Mail::queued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $m) => $m->hasTo('other@mitsuwat.co.jp'))->sole();
        $this->assertSame(['決裁の管理者が担当を付け替えたため、あなたの担当になりました。', '次の申請が、あなたの確認の番になりました。'], $mail->notice->lead);
    }

    /** 場面 8: 部門長の承認の取り消し → 部門長（取り消された人で、番が戻った人）に「もう一度」1 つ・申請者に「取り消されました」 */
    public function test_undoing_the_head_approval_gives_the_head_the_turn_back(): void
    {
        $request = $this->judge($this->submittedFor($this->w), $this->w['head'], 'head');
        $this->forget();

        $this->workflow()->undo($request, $this->admin, $request->lock_version, '押し間違い');

        $this->assertSame(['部門 長' => ['操作が取り消されました'], '申請 花子' => ['操作が取り消されました']], $this->everyone());
        $this->assertSame('部門長の確認（承認か差戻し）', $this->noticesOf($this->w['head'])->sole()->data['action']);
        $this->assertSame('対応は要りません（お知らせです）', $this->noticesOf($this->w['applicant'])->sole()->data['action']);
    }

    /** 場面 8: 決裁のあとの取り消し → 社長に「もう一度」・申請者に「取り消されました」。場面 4 を受け取った部門長・審査担当者には出さない（D10） */
    public function test_undoing_a_decision_does_not_reach_the_head_and_the_reviewer(): void
    {
        $request = $this->judge($this->atPresident(), $this->w['president'], 'president');
        $this->forget();

        $this->workflow()->undo($request, $this->admin, $request->lock_version, '押し間違い');

        $this->assertSame(['社長 太郎' => ['操作が取り消されました'], '申請 花子' => ['操作が取り消されました']], $this->everyone());
        $this->assertSame('社長の決裁（可・条可・差戻し・否）', $this->noticesOf($this->w['president'])->sole()->data['action']);
    }

    /** 場面 8: 条件の確認の取り消し → 申請者に 1 つ（取り消された人＝番が戻った人。「もう一度、条件を確認」） */
    public function test_undoing_the_condition_confirmation_asks_the_applicant_once_again(): void
    {
        $request = $this->judge($this->atPresident(), $this->w['president'], 'president', ApprovalStepResult::Conditional);
        $this->workflow()->confirmCondition($request, $this->w['applicant'], $request->lock_version, null);
        $request->refresh();
        $this->forget();

        $this->workflow()->undo($request, $this->admin, $request->lock_version, '押し間違い');

        $this->assertSame(['申請 花子' => ['操作が取り消されました']], $this->everyone());
        $this->assertSame('条件を確かめて「条件を確認しました」を押してください', $this->noticesOf($this->w['applicant'])->sole()->data['action']);
    }

    /** 場面 8: 差戻しの取り消しのあと、部門長が交代していたら、新しい部門長に「もう一度」・前の部門長（取り消された人）に「取り消されました」 */
    public function test_undoing_a_return_after_a_head_change_reaches_both_heads(): void
    {
        $request = $this->judge($this->submittedFor($this->w), $this->w['head'], 'head', ApprovalStepResult::Return);
        $newHead = $this->baseUser(['name' => '新 部門長']);
        $this->w['dept']->update(['head_user_id' => $newHead->id]);
        $this->forget();

        $this->workflow()->undo($request, $this->admin, $request->lock_version, '押し間違い');

        $this->assertSame([
            '部門 長'   => ['操作が取り消されました'],
            '申請 花子' => ['操作が取り消されました'],
            '新 部門長' => ['操作が取り消されました'],
        ], $this->everyone());
        $this->assertSame('対応は要りません（お知らせです）', $this->noticesOf($this->w['head'])->sole()->data['action']);
        $this->assertSame('部門長の確認（承認か差戻し）', $this->noticesOf($newHead)->sole()->data['action']);
    }

    /** 断られた操作・先を越された操作は、知らせを残さない（同じトランザクション） */
    public function test_a_refused_or_conflicting_operation_leaves_no_notice(): void
    {
        $request = $this->submittedFor($this->w);
        $this->forget();

        try {
            $this->workflow()->judgeHead($request, $this->w['head'], $request->lock_version, ApprovalStepResult::Return, null);
            $this->fail('コメントの無い差戻しが通った');
        } catch (WorkflowRefused) {
        }

        try {
            $this->workflow()->judgeHead($request, $this->w['head'], $request->lock_version + 1, ApprovalStepResult::Approve, null);
            $this->fail('古い画面の判断が通った');
        } catch (WorkflowConflict) {
        }

        $this->assertSame([], $this->everyone());
    }

    /** 画面から操作したとき、メールのリンクは操作のリクエストから作る（本番の /system/manage/index.php/… が入る。要件 15.1） */
    public function test_the_mail_link_keeps_the_front_controller_of_the_request(): void
    {
        $this->mailable($this->w['reviewer'], 'reviewer');
        $request = $this->submittedFor($this->w);

        $this->actingAs($this->w['head'])
            ->withServerVariables(['SCRIPT_NAME' => '/system/manage/index.php', 'SCRIPT_FILENAME' => base_path('public/index.php')])
            ->post("/system/manage/index.php/approvals/requests/{$request->id}/head-review", ['result' => 'approve', 'lock_version' => (string) $request->lock_version])
            ->assertRedirect();

        $mail = Mail::queued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $m) => $m->hasTo('reviewer@mitsuwat.co.jp'))->sole();
        $this->assertSame("http://localhost/system/manage/index.php/approvals/requests/{$request->id}", $mail->context['url']);
    }
}
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/patches/0006-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase3/WorkflowNoticeTest.php
```

Expected: `ERRORS!` `Tests: 19, Assertions: 19, Errors: 1, Failures: 16.`

- `WorkflowNoticeTest::test_the_mail_link_keeps_the_front_controller_of_the_request` — `Illuminate\Support\ItemNotFoundException:`
- `WorkflowNoticeTest::test_a_submission_reaches_the_head_only` — `Failed asserting that two arrays are identical.`
- `WorkflowNoticeTest::test_a_submission_by_the_head_reaches_every_reviewer` — `Failed asserting that two arrays are identical.`
- `WorkflowNoticeTest::test_each_judgement_reaches_the_next_handlers` — `Failed asserting that two arrays are identical.`
- `WorkflowNoticeTest::test_a_return_reaches_the_applicant_and_a_resubmission_reaches_the_head_again` — `Failed asserting that two arrays are identical.`
- `WorkflowNoticeTest::test_an_approval_reaches_the_applicant_and_the_people_who_judged` — `Failed asserting that two arrays are identical.`
- `WorkflowNoticeTest::test_a_rejection_reaches_the_same_people` — `Failed asserting that two arrays are identical.`
- `WorkflowNoticeTest::test_the_head_who_judged_gets_the_decision_after_a_head_change` — `Failed asserting that an array has the key '部門 長'.`
- `WorkflowNoticeTest::test_a_conditional_approval_asks_the_applicant_to_confirm` — `Failed asserting that two arrays are identical.`
- `WorkflowNoticeTest::test_a_confirmed_condition_is_a_notice_only_for_the_president_and_the_head` — `Failed asserting that two arrays are identical.`
- `WorkflowNoticeTest::test_a_withdrawal_reaches_the_handlers_at_that_time` — `Failed asserting that two arrays are identical.`
- `WorkflowNoticeTest::test_a_withdrawal_by_the_admin_also_reaches_the_applicant` — `Failed asserting that two arrays are identical.`
- `WorkflowNoticeTest::test_a_reassignment_reaches_the_new_assignee_only` — `Failed asserting that two arrays are identical.`
- `WorkflowNoticeTest::test_undoing_the_head_approval_gives_the_head_the_turn_back` — `Failed asserting that two arrays are identical.`
- `WorkflowNoticeTest::test_undoing_a_decision_does_not_reach_the_head_and_the_reviewer` — `Failed asserting that two arrays are identical.`
- `WorkflowNoticeTest::test_undoing_the_condition_confirmation_asks_the_applicant_once_again` — `Failed asserting that two arrays are identical.`
- `WorkflowNoticeTest::test_undoing_a_return_after_a_head_change_reaches_both_heads` — `Failed asserting that two arrays are identical.`

（まだ知らせを出していない。`test_a_withdrawal_of_a_returned_request_reaches_nobody` と `test_a_refused_or_conflicting_operation_leaves_no_notice` は「出さない」を見るので、今も緑。組み込んだあとも緑のままであることが大事）

- [ ] **Step 3: 各操作の最後で知らせる**

`app/Support/Approval/Workflow.php`（変更）

```diff
--- a/app/Support/Approval/Workflow.php
+++ b/app/Support/Approval/Workflow.php
@@ -26,6 +26,8 @@
  *   3. `RequestPermissions` で権限を確かめる
  *   4. `lock_version` を条件にした 1 回の UPDATE で状態を進める（同時に押された 2 人目を断る）
  *   5. 段階と記録を書く
+ *   6. 知らせを出す（Notifier。同じトランザクションの中なので、断られたり先を越されたりした操作の知らせは残らない。
+ *      段階3 設計書 §5.1・§5.5）
  * の順に進む（計画 §0.3）。
  *
  * ⚠ 行のロックは「申請の行 → 段階の行 → 連番の行」の順にそろえる（社長の判断で採番するとき・部門長の交代）。
@@ -121,6 +123,8 @@ public function submit(ApprovalRequest $request, User $actor, ?int $lockVersion
             if ($skipHead) {
                 HistoryRecorder::record($request, 'head_skipped', null, ['step_id' => $head->id]);
             }
+
+            Notifier::turnArrived($request, $actor);
         });
     }
 
@@ -157,6 +161,8 @@ public function confirmCondition(ApprovalRequest $request, User $actor, int $loc
                 'to_status'   => ApprovalStatus::Approved->value,
                 'comment'     => self::cleanComment($comment),
             ]);
+
+            Notifier::conditionConfirmed($request, $actor);
         });
     }
 
@@ -171,6 +177,9 @@ public function withdraw(ApprovalRequest $request, User $actor, int $lockVersion
                 throw new WorkflowRefused(['この申請は取り下げられる状態ではありません。']);
             }
 
+            // その時点の担当（段階を打ち切る前に取る。差戻し中なら申請者の番なので空）
+            $handlers = StepHandlers::ofWaiting($request);
+
             $from = $request->status;
             $this->move($request, $lockVersion, ApprovalStatus::Withdrawn);
             $this->cancelRest($request);
@@ -180,6 +189,8 @@ public function withdraw(ApprovalRequest $request, User $actor, int $lockVersion
                 'to_status'   => ApprovalStatus::Withdrawn->value,
                 'comment'     => self::cleanComment($comment),
             ]);
+
+            Notifier::withdrawn($request, $actor, $handlers, byAdmin: false);
         });
     }
 
@@ -283,6 +294,9 @@ public function reassignHead(ApprovalRequest $request, User $admin, int $lockVer
                 'reason'  => $reason,
                 'meta'    => ['from_user_id' => $before, 'to_user_id' => $to->id],
             ]);
+
+            // 新しい担当にだけ知らせる（外れた前の担当には出さない。D9）
+            Notifier::handlerChanged($admin, [$step->setRelation('request', $request)], $to, NoticeText::REASSIGNED);
         });
     }
 
@@ -350,6 +364,8 @@ public function undo(ApprovalRequest $request, User $admin, int $lockVersion, ?s
                 'reason'      => $reason,
                 'meta'        => ['undone_history_id' => $target->id, 'undone_action' => $target->action],
             ]);
+
+            Notifier::undone($request, $admin, $target);
         });
     }
 
@@ -366,6 +382,9 @@ public function withdrawByAdmin(ApprovalRequest $request, User $admin, int $lock
             }
             $reason = self::requireReason($reason);
 
+            // その時点の担当（段階を打ち切る前に取る）。代理の取り下げは申請者にも知らせる（D8）
+            $handlers = StepHandlers::ofWaiting($request);
+
             $from = $request->status;
             $this->move($request, $lockVersion, ApprovalStatus::Withdrawn);
             $this->cancelRest($request);
@@ -375,6 +394,8 @@ public function withdrawByAdmin(ApprovalRequest $request, User $admin, int $lock
                 'to_status'   => ApprovalStatus::Withdrawn->value,
                 'reason'      => $reason,
             ]);
+
+            Notifier::withdrawn($request, $admin, $handlers, byAdmin: true);
         });
     }
 
@@ -419,6 +440,7 @@ private function afterHead(ApprovalRequest $request, User $actor, int $lockVersi
             $this->finishStep($step, $actor, $result, $comment);
             $this->arrive($request, ApprovalStepKind::Review);
             $this->recordJudgement($request, 'head_approved', $actor, $from, $step, $result, $comment);
+            Notifier::turnArrived($request, $actor);
 
             return;
         }
@@ -427,6 +449,7 @@ private function afterHead(ApprovalRequest $request, User $actor, int $lockVersi
         $this->finishStep($step, $actor, $result, $comment);
         $this->cancelRest($request);
         $this->recordJudgement($request, 'head_returned', $actor, $from, $step, $result, $comment);
+        Notifier::returned($request, $actor, ApprovalStepKind::Head);
     }
 
     private function afterReview(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStep $step, ApprovalStepResult $result, ?string $comment): void
@@ -438,6 +461,8 @@ private function afterReview(ApprovalRequest $request, User $actor, int $lockVer
         $this->finishStep($step, $actor, $result, $comment);
         $this->arrive($request, ApprovalStepKind::President);
         $this->recordJudgement($request, 'reviewed', $actor, $from, $step, $result, $comment);
+        // ほかの審査担当者には知らせない（ホームの対応待ちから消える。D11）
+        Notifier::turnArrived($request, $actor);
     }
 
     private function afterPresident(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStep $step, ApprovalStepResult $result, ?string $comment): void
@@ -448,6 +473,7 @@ private function afterPresident(ApprovalRequest $request, User $actor, int $lock
             $this->move($request, $lockVersion, ApprovalStatus::Returned);
             $this->finishStep($step, $actor, $result, $comment);
             $this->recordJudgement($request, 'president_returned', $actor, $from, $step, $result, $comment);
+            Notifier::returned($request, $actor, ApprovalStepKind::President);
 
             return;
         }
@@ -482,6 +508,7 @@ private function afterPresident(ApprovalRequest $request, User $actor, int $lock
 
         $this->finishStep($step, $actor, $result, $comment);
         $this->recordJudgement($request, $action, $actor, $from, $step, $result, $comment);
+        Notifier::decided($request, $actor, $decision);
     }
 
     private function assertFresh(ApprovalRequest $request, int $lockVersion): void
```

（差分のファイルを使うなら: `git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/patches/0006-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (19 tests, …)`

- [ ] **Step 5: 全件を流す**

Expected: `OK (3182 tests, 22660 assertions)`（2a・2b のテストの多く〈`Phase2` の 23 ファイルのうち 12〉は使い始めた状態で操作するので、知らせとメールがその場で作られる。どれも緑のまま＝メールの描き方の誤りもその場で赤になる。§0.3）

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/Workflow.php tests/Feature/Approval/Phase3/WorkflowNoticeTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 申請の操作から知らせを出す（場面 1・3〜8 と付け替え）

提出・判断・差戻し・決裁・条件の確認・取り下げ・付け替え・取り消しの最後で、
同じトランザクションの中から Notifier を呼ぶ（段階3 設計書 §5.1・§5.4）。
断られた・先を越された操作の知らせは残らない。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 6: 担当の入れ替わり（部門長の交代・審査担当者の追加・社長の交代。D1）

担当が入れ替わったとき、新しい担当に、待っている申請ごとに「自分の番が来た」を知らせる（設計書 D1・§0.4 の「Workflow の外の 2 か所」）。外れた前の担当には出さない（D9）。

- 部門長の交代（`Workflow::headChanged()`）: 部門の行を**更新する前**に呼ばれるので、新しい部門長は引数から取って明示で渡す（`StepHandlers` では前の部門長が出る）
- 審査担当者の追加（`OrganizationController::syncReviewers()`）: 部門の登録・更新のトランザクションの中。足した人（前後の差）に、その部門で審査を**待っている**申請ごと
- 社長の交代（`Admin\UserController::setPresident()`）: 今はトランザクションが無いので、**設定の更新・記録・知らせを `DB::transaction()` で囲む**（知らせだけ残る・知らせだけ消えるを作らない）。前と同じ人を選び直したときは知らせない

**Files:**
- Modify: `app/Support/Approval/Notifier.php`（`reviewersAdded()`・`presidentChanged()` を足す）・`app/Support/Approval/Workflow.php`（`headChanged`）・`app/Http/Controllers/Approval/OrganizationController.php`（`syncReviewers` に管理者を渡す）・`app/Http/Controllers/Admin/UserController.php`（`setPresident`）
- Test: Create `tests/Feature/Approval/Phase3/HandlerChangeNoticeTest.php`

**Interfaces:**
- Consumes: `Notifier::handlerChanged()`・`NoticeText::HEAD_CHANGED`・`REVIEWER_ADDED`・`PRESIDENT_CHANGED`（Task 4）
- Produces: `Notifier::reviewersAdded(User $actor, ApprovalDepartment $department, Collection<int, User> $added): void`／`Notifier::presidentChanged(User $actor, User $president): void`

**差分の大きさ:** 5 ファイル・+259 / −12 行（差分のファイル `0007-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase3/HandlerChangeNoticeTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase3;

use App\Enums\ApprovalStepResult;
use App\Enums\UserRole;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalNotice;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use App\Support\Approval\ApprovalNumber;
use App\Support\Approval\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ReadsApprovalNotices;
use Tests\TestCase;

/**
 * 担当が入れ替わったときの知らせ（段階3 設計書 D1・§5.4 の場面 2）。部門長の交代と審査担当者の追加は部門の管理、
 * 社長の交代は基幹の利用者の管理から（画面と同じ形で送る）。
 */
class HandlerChangeNoticeTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ReadsApprovalNotices;

    /** @var array<string, mixed> */
    private array $w;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // 決裁No の年度が動かないように止める（日本時間 2026-09-26 → R8）
        $this->travelTo(CarbonImmutable::parse('2026-09-26 10:00', 'Asia/Tokyo')->utc());
        Mail::fake();
        $this->w     = $this->approvalWorld();
        $this->admin = $this->approvalAdmin();
    }

    private function judge(ApprovalRequest $request, User $actor, string $kind): ApprovalRequest
    {
        $request->refresh();
        $method = ['head' => 'judgeHead', 'review' => 'judgeReview'][$kind];
        app(Workflow::class)->{$method}($request, $actor, $request->lock_version, $kind === 'review' ? ApprovalStepResult::Ok : ApprovalStepResult::Approve, null);

        return $request->refresh();
    }

    /** 部門の管理の編集のモーダルが送るのと同じ形で更新する */
    private function updateDepartment(ApprovalDepartment $dept, array $overrides, ?User $admin = null): TestResponse
    {
        $dept = $dept->fresh(['reviewers']);

        return $this->actingAs($admin ?? $this->admin)->put(route('approvals.admin.organization.departments.update', $dept), array_merge([
            'company_id'        => (string) $dept->company_id,
            'name'              => $dept->name,
            'short_name'        => $dept->short_name,
            'code'              => $dept->code,
            'sort_order'        => (string) $dept->sort_order,
            'head_user_id'      => $dept->head_user_id === null ? '' : (string) $dept->head_user_id,
            'reviewer_ids'      => $dept->reviewers->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'next_number'       => (string) ApprovalNumber::currentState($dept)['next'],
            'next_number_shown' => (string) ApprovalNumber::currentState($dept)['next'],
        ], $overrides))->assertRedirect(route('approvals.admin.organization.index'))->assertSessionHas('success');
    }

    /** 部門長の交代: 部門長の確認を待っている申請ごとに新しい部門長へ（付け替えていたものも移る。D23）。前の部門長・外れた付け替え先には出さない（D9） */
    public function test_a_new_head_gets_one_notice_per_waiting_request(): void
    {
        $this->launchApprovals();
        $plain      = $this->submittedFor($this->w);
        $reassigned = $this->submittedFor($this->w);
        $other      = $this->baseUser(['name' => '付け替え 先']);
        app(Workflow::class)->reassignHead($reassigned, $this->admin, $reassigned->lock_version, $other, '出張のため');
        $this->judge($this->submittedFor($this->w), $this->w['head'], 'head');   // 審査中（部門長の確認は済んだ）
        ApprovalNotice::query()->delete();
        $newHead = $this->mailable($this->baseUser(['name' => '新 部門長']), 'newhead');

        $this->updateDepartment($this->w['dept'], ['head_user_id' => (string) $newHead->id]);

        $notices = $this->noticesOf($newHead);
        $this->assertEqualsCanonicalizing([$plain->id, $reassigned->id], $notices->pluck('approval_request_id')->all());
        $this->assertSame(['部門長の確認のお願い', '部門長の確認のお願い'], $notices->map(fn ($n) => $n->data['headline'])->all());
        $this->assertSame('決裁 管理者', $notices->first()->data['actor']);
        $this->assertSame([], $this->headlinesOf($this->w['head']));
        $this->assertSame([], $this->headlinesOf($other));
        $this->assertCount(2, $this->mailSubjectsTo('newhead@mitsuwat.co.jp'));
    }

    /** 自分を新しい部門長にした管理者には出さない（操作した本人。§5.4 の決まり 1） */
    public function test_the_admin_who_became_the_head_gets_nothing(): void
    {
        $this->launchApprovals();
        $this->submittedFor($this->w);
        ApprovalNotice::query()->delete();

        $this->updateDepartment($this->w['dept'], ['head_user_id' => (string) $this->admin->id]);

        $this->assertSame(0, ApprovalNotice::count());
    }

    /** 審査担当者の追加: 足した人にだけ、審査を待っている申請ごとに（まだ審査に届いていない申請は、届いたときに場面 1 で） */
    public function test_an_added_reviewer_gets_one_notice_per_waiting_review(): void
    {
        $this->launchApprovals();
        $first   = $this->judge($this->submittedFor($this->w), $this->w['head'], 'head');
        $second  = $this->judge($this->submittedFor($this->w), $this->w['head'], 'head');
        $pending = $this->submittedFor($this->w);   // 部門長確認中（審査はまだ届いていない）
        ApprovalNotice::query()->delete();
        $added = $this->baseUser(['name' => '追加 審査']);

        $this->updateDepartment($this->w['reviewDept'], ['reviewer_ids' => [(string) $this->w['reviewer']->id, (string) $added->id]]);

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->noticesOf($added)->pluck('approval_request_id')->all());
        $this->assertSame(['審査の意見のお願い', '審査の意見のお願い'], $this->headlinesOf($added));
        $this->assertSame([], $this->headlinesOf($this->w['reviewer']), '前からいる審査担当者には出さない');
        $this->assertNotContains($pending->id, ApprovalNotice::pluck('approval_request_id')->all());

        // 外しただけ（足した人がいない）なら誰にも出ない
        ApprovalNotice::query()->delete();
        $this->updateDepartment($this->w['reviewDept'], ['reviewer_ids' => [(string) $added->id]]);
        $this->assertSame(0, ApprovalNotice::count());
    }

    /** 使い始める前は、部門長や審査担当者を変えても何も出ない（§5.2） */
    public function test_nothing_is_made_before_launch(): void
    {
        $this->submittedFor($this->w);
        $newHead = $this->baseUser(['name' => '新 部門長']);

        $this->updateDepartment($this->w['dept'], ['head_user_id' => (string) $newHead->id]);
        $this->updateDepartment($this->w['reviewDept'], ['reviewer_ids' => [(string) $this->w['reviewer']->id, (string) $newHead->id]]);

        $this->assertSame(0, ApprovalNotice::count());
    }

    /** 社長の交代: 社長の決裁を待っている申請ごとに新しい社長へ。同じ人を選び直しても出ない。申請者本人の申請には出ない（D16） */
    public function test_a_new_president_gets_one_notice_per_waiting_request(): void
    {
        $this->launchApprovals();
        $first  = $this->judge($this->judge($this->submittedFor($this->w), $this->w['head'], 'head'), $this->w['reviewer'], 'review');
        $second = $this->judge($this->judge($this->submittedFor($this->w), $this->w['head'], 'head'), $this->w['reviewer'], 'review');
        $this->submittedFor($this->w);   // 部門長確認中（社長の決裁はまだ）
        // 新しい社長が出していた申請（社長の決裁待ち）
        $newPresident = $this->baseUser(['name' => '新 社長']);
        $newPresident->approvalDepartments()->attach($this->w['dept']->id);
        $own = $this->judge($this->judge($this->submittedFor(array_merge($this->w, ['applicant' => $newPresident])), $this->w['head'], 'head'), $this->w['reviewer'], 'review');
        ApprovalNotice::query()->delete();
        $executive = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]);

        $this->actingAs($executive)->post(route('admin.users.president'), ['president_user_id' => (string) $newPresident->id])
            ->assertRedirect(route('admin.users.index'));

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->noticesOf($newPresident)->pluck('approval_request_id')->all());
        $this->assertSame(['社長の決裁のお願い', '社長の決裁のお願い'], $this->headlinesOf($newPresident));
        $this->assertNotContains($own->id, ApprovalNotice::pluck('approval_request_id')->all());
        $this->assertSame([], $this->headlinesOf($this->w['president']), '前の社長には出さない');

        ApprovalSetting::forget();
        $this->actingAs($executive)->post(route('admin.users.president'), ['president_user_id' => (string) $newPresident->id]);
        $this->assertCount(2, $this->noticesOf($newPresident), '同じ人を選び直しても出さない');
    }

    /** 社長の指定は、設定の更新・記録・知らせを 1 つのトランザクションで（知らせが作れなければ、指定も記録も残らない） */
    public function test_the_president_designation_rolls_back_when_the_notice_fails(): void
    {
        $this->launchApprovals();
        $this->judge($this->judge($this->submittedFor($this->w), $this->w['head'], 'head'), $this->w['reviewer'], 'review');
        $newPresident = $this->baseUser(['name' => '新 社長']);
        $executive    = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]);
        ApprovalNotice::creating(fn () => throw new RuntimeException('お知らせを作れない'));

        $this->actingAs($executive)->post(route('admin.users.president'), ['president_user_id' => (string) $newPresident->id])
            ->assertServerError();

        ApprovalSetting::forget();
        $this->assertSame($this->w['president']->id, ApprovalSetting::current()->president_user_id);
        $this->assertSame(0, ApprovalSettingLog::where('action', 'president.changed')->count());
    }
}
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/patches/0007-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase3/HandlerChangeNoticeTest.php
```

Expected: `FAILURES!` `Tests: 6, Assertions: 23, Failures: 4.`

- `HandlerChangeNoticeTest::test_a_new_head_gets_one_notice_per_waiting_request` — `Failed asserting that two arrays are equal.`
- `HandlerChangeNoticeTest::test_an_added_reviewer_gets_one_notice_per_waiting_review` — `Failed asserting that two arrays are equal.`
- `HandlerChangeNoticeTest::test_a_new_president_gets_one_notice_per_waiting_request` — `Failed asserting that two arrays are equal.`
- `HandlerChangeNoticeTest::test_the_president_designation_rolls_back_when_the_notice_fails` — `Expected response status code [>=500, < 600] but received 302.`

（まだ知らせていない。巻き戻りのテストは、お知らせを作ると例外になるようにして 500 と「指定も記録も残らない」を見る。今は知らせを作らないので例外にならず 302 で、指定が変わってしまう。`test_the_admin_who_became_the_head_gets_nothing` と `test_nothing_is_made_before_launch` は「出さない」を見るので、今も緑）

- [ ] **Step 3: 審査担当者の追加と社長の交代の宛先を足す**

`app/Support/Approval/Notifier.php`（変更）

```diff
--- a/app/Support/Approval/Notifier.php
+++ b/app/Support/Approval/Notifier.php
@@ -5,8 +5,10 @@
 use App\Enums\ApprovalDecision;
 use App\Enums\ApprovalStatus;
 use App\Enums\ApprovalStepKind;
+use App\Enums\ApprovalStepStatus;
 use App\Enums\UserStatus;
 use App\Mail\ApprovalNoticeMail;
+use App\Models\ApprovalDepartment;
 use App\Models\ApprovalHistory;
 use App\Models\ApprovalMailDomain;
 use App\Models\ApprovalNotice;
@@ -70,6 +72,40 @@ public static function handlerChanged(User $actor, iterable $steps, Collection|U
         $notifier->send();
     }
 
+    /**
+     * 場面 2（審査担当者の追加）: 足した人に、その審査部門で審査を待っている申請ごとに（まだ届いていない審査は、届いたときに
+     * 場面 1 で知らせる）
+     *
+     * @param Collection<int, User> $added 足した審査担当者（前後の差）
+     */
+    public static function reviewersAdded(User $actor, ApprovalDepartment $department, Collection $added): void
+    {
+        if ($added->isEmpty()) {
+            return;
+        }
+
+        $steps = ApprovalStep::with('request')
+            ->where('kind', ApprovalStepKind::Review->value)
+            ->where('status', ApprovalStepStatus::Waiting->value)
+            ->where('department_id', $department->id)
+            ->orderBy('id')
+            ->get();
+
+        self::handlerChanged($actor, $steps, $added, NoticeText::REVIEWER_ADDED);
+    }
+
+    /** 場面 2（社長の交代）: 新しい社長に、社長の決裁を待っているすべての申請ごとに */
+    public static function presidentChanged(User $actor, User $president): void
+    {
+        $steps = ApprovalStep::with('request')
+            ->where('kind', ApprovalStepKind::President->value)
+            ->where('status', ApprovalStepStatus::Waiting->value)
+            ->orderBy('id')
+            ->get();
+
+        self::handlerChanged($actor, $steps, $president, NoticeText::PRESIDENT_CHANGED);
+    }
+
     /** 場面 3: 差戻しされた（申請者へ） */
     public static function returned(ApprovalRequest $request, User $actor, ApprovalStepKind $by): void
     {
```

- [ ] **Step 4: 3 か所から呼ぶ**

`app/Support/Approval/Workflow.php`（変更）

```diff
--- a/app/Support/Approval/Workflow.php
+++ b/app/Support/Approval/Workflow.php
@@ -199,7 +199,8 @@ public function withdraw(ApprovalRequest $request, User $actor, int $lockVersion
      *
      * 部門長の確認を待っている申請は、付け替えていても新しい部門長へ移す（付け替えを空に戻す）。
      * 移した申請は `lock_version` を 1 進める（交代の前に開いた画面から押した判断・取り下げを
-     * 「すでに処理されています」で断る。計画 §0.3）。
+     * 「すでに処理されています」で断る。計画 §0.3）。新しい部門長には、移した申請ごとに「自分の番が来た」を知らせる
+     * （段階3 設計書 D1。前の部門長には出さない D9。部門の行を更新する前なので、新しい部門長は引数から取る）。
      *
      * ⚠ ロックはほかの操作と同じ「申請の行 → 段階の行」の順に、主キーで取る（2026-09-27 の Task 7 の再点検で、
      *   MySQL 8.4.11 の REPEATABLE READ・READ COMMITTED とも 1213 が出ないことを実測）。
@@ -228,6 +229,8 @@ public function headChanged(ApprovalDepartment $department, ?int $oldHeadId, ?in
             $requests = ApprovalRequest::whereKey($candidates->pluck('request_id')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
             $steps    = ApprovalStep::whereKey($candidates->pluck('id')->all())->orderBy('id')->lockForUpdate()->get();
 
+            $moved = [];
+
             foreach ($steps as $step) {
                 // ロックを待つあいだに判断・取り下げが済んだ段階は動かさない（ロック付きの読み取りは最新の行を読む）
                 if ($step->status !== ApprovalStepStatus::Waiting) {
@@ -246,7 +249,11 @@ public function headChanged(ApprovalDepartment $department, ?int $oldHeadId, ?in
                     'step_id' => $step->id,
                     'meta'    => ['from_user_id' => $before, 'to_user_id' => $newHeadId],
                 ]);
+
+                $moved[] = $step->setRelation('request', $requests[$step->request_id]);
             }
+
+            Notifier::handlerChanged($admin, $moved, $newHeadId === null ? null : User::find($newHeadId), NoticeText::HEAD_CHANGED);
         });
     }
```

`app/Http/Controllers/Approval/OrganizationController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/OrganizationController.php
+++ b/app/Http/Controllers/Approval/OrganizationController.php
@@ -15,6 +15,7 @@
 use App\Models\User;
 use App\Support\Approval\ApprovalNumber;
 use App\Support\Approval\Assignees;
+use App\Support\Approval\Notifier;
 use App\Support\Approval\SettingLogger;
 use App\Support\Approval\Workflow;
 use Illuminate\Http\Request;
@@ -139,11 +140,11 @@ public function storeDepartment(Request $request)
         [$base, $headId, $reviewerIds, $next, $shown] = $this->splitDepartmentInput($validated);
 
         try {
-            DB::transaction(function () use ($base, $headId, $reviewerIds, $next, $shown): void {
+            DB::transaction(function () use ($request, $base, $headId, $reviewerIds, $next, $shown): void {
                 $department = ApprovalDepartment::create($base + ['head_user_id' => $headId]);
                 SettingLogger::record('department.created', 'approval_department', $department->id, [], $base + ['head_user_id' => $headId]);
 
-                $this->syncReviewers($department, $reviewerIds);
+                $this->syncReviewers($department, $reviewerIds, $request->user());
                 $this->setNextNumber($department, $next, $shown);
             });
         } catch (InvalidArgumentException $e) {
@@ -190,7 +191,7 @@ public function updateDepartment(Request $request, ApprovalDepartment $approvalD
                 $approvalDepartment->update($base + ['head_user_id' => $headId]);
                 SettingLogger::recordChange('department.updated', 'approval_department', $approvalDepartment->id, $before, $base + ['head_user_id' => $headId]);
 
-                $this->syncReviewers($approvalDepartment, $reviewerIds);
+                $this->syncReviewers($approvalDepartment, $reviewerIds, $request->user());
                 $this->setNextNumber($approvalDepartment->fresh('company'), $next, $shown);
             });
         } catch (InvalidArgumentException $e) {
@@ -304,8 +305,11 @@ private function splitDepartmentInput(array $validated): array
         return [$base, $headId, $reviewerIds, $next, $shown];
     }
 
-    /** 審査担当者を入れ替え、変わったときだけ記録する */
-    private function syncReviewers(ApprovalDepartment $department, array $reviewerIds): void
+    /**
+     * 審査担当者を入れ替え、変わったときだけ記録する。足した人には、この部門で審査を待っている申請ごとに知らせる
+     * （段階3 設計書 D1。外した人には出さない D9。部門の更新と同じトランザクションの中）
+     */
+    private function syncReviewers(ApprovalDepartment $department, array $reviewerIds, User $admin): void
     {
         $before = $department->reviewers()->pluck('users.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
         $after  = collect($reviewerIds)->sort()->values()->all();
@@ -316,6 +320,8 @@ private function syncReviewers(ApprovalDepartment $department, array $reviewerId
 
         $department->reviewers()->sync($reviewerIds);
         SettingLogger::record('department.reviewers_changed', 'approval_department', $department->id, ['reviewer_ids' => $before], ['reviewer_ids' => $after]);
+
+        Notifier::reviewersAdded($admin, $department, User::whereKey(array_diff($after, $before))->orderBy('id')->get());
     }
 
     /**
```

`app/Http/Controllers/Admin/UserController.php`（変更）

```diff
--- a/app/Http/Controllers/Admin/UserController.php
+++ b/app/Http/Controllers/Admin/UserController.php
@@ -10,6 +10,7 @@
 use App\Models\Department;
 use App\Models\User;
 use App\Support\Approval\LoginGuide;
+use App\Support\Approval\Notifier;
 use App\Support\Approval\PasswordReissuer;
 use App\Support\Approval\SettingLogger;
 use App\Support\InitialPassword;
@@ -464,14 +465,22 @@ public function setPresident(Request $request)
             'president_user_id.exists'   => '有効でメールアドレスのある利用者を選択してください。',
         ]);
 
-        $settings = ApprovalSetting::current();
-        $before   = ['president_user_id' => $settings->president_user_id];
+        // 設定の更新・記録・知らせを 1 つのトランザクションで（段階3 設計書 §5.1。知らせだけが残る・知らせだけが消えるを作らない）
+        DB::transaction(function () use ($request, $validated): void {
+            $settings = ApprovalSetting::current();
+            $before   = ['president_user_id' => $settings->president_user_id];
 
-        $settings->update(['president_user_id' => (int) $validated['president_user_id']]);
+            $settings->update(['president_user_id' => (int) $validated['president_user_id']]);
 
-        SettingLogger::recordChange('president.changed', 'approval_setting', $settings->id, $before, [
-            'president_user_id' => $settings->president_user_id,
-        ]);
+            SettingLogger::recordChange('president.changed', 'approval_setting', $settings->id, $before, [
+                'president_user_id' => $settings->president_user_id,
+            ]);
+
+            // 社長が変わったら、社長の決裁を待っている申請ごとに新しい社長へ知らせる（D1。前の社長には出さない D9）
+            if ($before['president_user_id'] !== $settings->president_user_id) {
+                Notifier::presidentChanged($request->user(), User::findOrFail($settings->president_user_id));
+            }
+        });
 
         return redirect()->route('admin.users.index')->with('success', '決裁の社長を設定しました。');
     }
```

（差分のファイルを使うなら: `git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/patches/0007-*.patch`）

- [ ] **Step 5: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (6 tests, …)`

- [ ] **Step 6: 全件を流す**

Expected: `OK (3188 tests, 22701 assertions)`（部門の管理と社長の指定の既存のテストは使い始める前の操作なので、知らせは作られず振る舞いが変わらない）

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/Notifier.php app/Support/Approval/Workflow.php app/Http/Controllers/Approval/OrganizationController.php app/Http/Controllers/Admin/UserController.php tests/Feature/Approval/Phase3/HandlerChangeNoticeTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 部門長・審査担当者・社長が替わったとき新しい担当に知らせる

部門長の交代・審査担当者の追加・社長の交代で、新しい担当に待っている申請ごとの
「自分の番」を知らせる（段階3 設計書 D1。前の担当には出さない D9）。
社長の指定は設定の更新・記録・知らせを 1 つのトランザクションで囲む。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 7: 画面（ベル・⑥・ホームの欄・既読・見られなくなった申請）

お知らせを見る所を作る（設計書 §5.6・D13〜D15・§0.6）: ヘッダーのベル（未読の数・押すと ⑥）、お知らせ一覧 ⑥（新しい順に 20 件ずつ・すべて既読にする）、ホームの「新しいお知らせ」（未読の新しい 5 件）、既読の 3 つの入口（1 件を押す・その申請の詳細を開く・すべて既読にする）。見られなくなった申請のお知らせを押したら、詳細の 404 にせず ⑥ に戻して「この申請は、今は見られません。」。ページ送りは ⑩ のものを部品（`PageNumbers`・`approvals._pager`）にして ⑥ と ⑩ の両方で使う。

走査テストの登録もこの Task で行う（§0.8）: ⑥ の 3 本のルート（`ApprovalAdminGateTest` の `OPEN_TO_EVERY_USER` と下限 46・`LaunchGateTest` の下限 23）・`ApprovalNotice.php` の時計の読み取り 1 件（`ClockReadScanTest`）・⑥ のビュー（`ValidationErrorFeedbackTest` の `EXEMPT`）。部門長のホームのテスト（`HomeAndListTest`）は、「新しいお知らせ」に提出のときの知らせ（最後に提出した件名）が正しく残るので、他人の申請が出ないことを対応待ちと進み具合の欄だけで見るように変える（§0.9）。

**Files:**
- Create: `app/Http/Controllers/Approval/NoticeController.php`・`app/Support/Approval/PageNumbers.php`・`resources/views/approvals/_pager.blade.php`・`resources/views/approvals/notices/index.blade.php`・`resources/views/approvals/notices/_item.blade.php`・`resources/views/layouts/partials/notice-bell.blade.php`
- Modify: `app/Models/ApprovalNotice.php`（`markReadFor()`）・`app/Support/Approval/ApprovalMenu.php`（`unreadNotices()`）・`app/Http/Controllers/Approval/RequestController.php`（詳細を開いたら既読）・`app/Http/Controllers/Approval/HomeController.php`（新しいお知らせ）・`app/Http/Controllers/Approval/AdminRequestController.php`（`PageNumbers` に寄せる）・`routes/approval.php`・`resources/views/layouts/partials/header.blade.php`・`resources/views/approvals/home-launched.blade.php`・`resources/views/approvals/admin/requests.blade.php`（`_pager` に寄せる）
- Test: Create `tests/Feature/Approval/Phase3/NoticeScreenTest.php`・Modify `tests/Feature/Approval/ApprovalAdminGateTest.php`・`tests/Feature/Approval/Phase2/LaunchGateTest.php`・`tests/Feature/Approval/Phase2/HomeAndListTest.php`・`tests/Feature/ClockReadScanTest.php`・`tests/Feature/ValidationErrorFeedbackTest.php`

**Interfaces:**
- Consumes: `ApprovalNotice::ownedBy()`（Task 2）・`ReadsApprovalNotices`（Task 4）・知らせを作る Workflow（Task 5）
- Produces: ルート `approvals.notices.index`（GET `/approvals/notices`）・`approvals.notices.readAll`（POST `/approvals/notices/read-all`）・`approvals.notices.open`（GET `/approvals/notices/{notice}`・`whereUuid`）。どれも門番 `approval.launched` の内側
- Produces: `ApprovalNotice::markReadFor(User $user, ?ApprovalRequest $request = null): void`（その人の未読を既読に。申請を渡せばその申請の分だけ）／`ApprovalMenu::unreadNotices(User $user): ?int`（使い始める前は null・1 リクエストに 1 回）／`PageNumbers::around(int $current, int $last): list<int|null>`（null は「…」）／ビューの部品 `approvals.notices._item`（`$notice`）・`approvals._pager`（`$paginator`・`$pages`）

**差分の大きさ:** 21 ファイル・+566 / −58 行（差分のファイル `0008-…`）

- [ ] **Step 1: 失敗するテストを書き、走査テストに登録する**

`tests/Feature/Approval/Phase3/NoticeScreenTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase3;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalNotice;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ReadsApprovalNotices;
use Tests\TestCase;

/**
 * ベル・お知らせ一覧（⑥）・ホームの「新しいお知らせ」・既読の決まり（段階3 設計書 §5.6・D13〜D15）。
 */
class NoticeScreenTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ReadsApprovalNotices;

    /** @var array<string, mixed> */
    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 10:05', 'Asia/Tokyo')->utc());
        Mail::fake();
        $this->w = $this->approvalWorld();
    }

    /** お知らせを直接作る（中身は Notifier が作るものと同じ形） */
    private function notice(User $user, ApprovalRequest $request, string $headline = '部門長の確認のお願い', array $data = []): ApprovalNotice
    {
        return ApprovalNotice::create([
            'id' => (string) Str::orderedUuid(), 'type' => ApprovalNotice::TYPE,
            'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id, 'approval_request_id' => $request->id,
            'data' => array_merge([
                'scene' => 'turn', 'headline' => $headline, 'subject' => $request->subject, 'number' => null,
                'applicant' => '申請 花子', 'department' => '住宅事業部', 'action' => '部門長の確認（承認か差戻し）', 'actor' => '申請 花子',
            ], $data),
        ]);
    }

    private function bell(string $html): ?string
    {
        return preg_match('#<a href="' . preg_quote(route('approvals.notices.index'), '#') . '"[^>]*aria-label="[^"]*"[^>]*>.*?</a>#s', $html, $m) ? $m[0] : null;
    }

    /** 使い始める前はベルを出さない（基幹の画面にも決裁のみ利用者の画面にも。§5.2） */
    public function test_there_is_no_bell_before_launch(): void
    {
        $this->assertNull($this->bell($this->actingAs($this->w['head'])->get(route('password.change'))->assertOk()->getContent()));
        $this->assertNull($this->bell($this->actingAs($this->w['applicant'])->get(route('approvals.home'))->assertOk()->getContent()));
    }

    /** ベル: 未読 0 は数を出さない・1 は「1」・100 は「99+」。読み上げは「お知らせ（未読 N 件）」。基幹の画面にも決裁のみ利用者にも */
    public function test_the_bell_shows_the_unread_count(): void
    {
        $this->launchApprovals();
        $request = $this->submittedFor($this->w);   // 部門長に 1 つ
        ApprovalNotice::query()->delete();

        $bell = $this->bell($this->actingAs($this->w['head'])->get(route('password.change'))->getContent());
        $this->assertStringContainsString('aria-label="お知らせ（未読 0 件）"', $bell);
        $this->assertStringNotContainsString('bg-red-600', $bell);

        $this->notice($this->w['head'], $request);
        $bell = $this->bell($this->actingAs($this->w['head'])->get(route('password.change'))->getContent());
        $this->assertStringContainsString('aria-label="お知らせ（未読 1 件）"', $bell);
        $this->assertMatchesRegularExpression('#bg-red-600[^>]*>1</span>#', $bell);

        for ($i = 0; $i < 99; $i++) {
            $this->notice($this->w['head'], $request);
        }
        $this->notice($this->w['head'], $request)->markAsRead();
        $bell = $this->bell($this->actingAs($this->w['head'])->get(route('password.change'))->getContent());
        $this->assertStringContainsString('aria-label="お知らせ（未読 100 件）"', $bell);
        $this->assertMatchesRegularExpression('#bg-red-600[^>]*>99\+</span>#', $bell);

        $this->notice($this->w['applicant'], $request, '差戻しされました');
        $bell = $this->bell($this->actingAs($this->w['applicant'])->get(route('approvals.home'))->getContent());
        $this->assertStringContainsString('aria-label="お知らせ（未読 1 件）"', $bell);
    }

    /** ⑥: 新しい順・未読は太字と「未読」の印・自分のお知らせだけ。日時は日本時間 */
    public function test_the_list_shows_my_notices_newest_first(): void
    {
        $this->launchApprovals();
        $request = $this->draftFor($this->w, ['subject' => '社用車の購入']);
        $this->notice($this->w['head'], $request, '部門長の確認のお願い')->markAsRead();
        $this->travel(1)->minutes();
        $this->notice($this->w['head'], $request, '取り下げられました');
        $this->notice($this->w['reviewer'], $request, '審査の意見のお願い');

        $html = $this->actingAs($this->w['head'])->get(route('approvals.notices.index'))->assertOk()
            ->assertSeeInOrder(['取り下げられました：社用車の購入', '部門長の確認のお願い：社用車の購入'])
            ->assertDontSee('審査の意見のお願い')
            ->assertSee('9/30 10:06')
            ->getContent();

        $this->assertMatchesRegularExpression('#>未読</span>\s*<span class="[^"]*font-bold[^"]*">取り下げられました：社用車の購入</span>#u', $html);
        $this->assertDoesNotMatchRegularExpression('#>未読</span>\s*<span class="[^"]*">部門長の確認のお願い#u', $html);
        $this->assertSame(1, substr_count($html, '>未読</span>'));
    }

    /** ⑥: 20 件ずつ。範囲の外のページでも 500 にならない（ページ送りは出さない） */
    public function test_the_list_pages_by_twenty(): void
    {
        $this->launchApprovals();
        $request = $this->draftFor($this->w);
        for ($i = 1; $i <= 21; $i++) {
            $this->notice($this->w['head'], $request, "見出し{$i}番");
            $this->travel(1)->seconds();
        }

        $first = $this->actingAs($this->w['head'])->get(route('approvals.notices.index'))->assertOk();
        $first->assertSee('見出し21番')->assertDontSee('見出し1番：')->assertSee('aria-label="ページ送り"', false);

        $this->actingAs($this->w['head'])->get(route('approvals.notices.index', ['page' => 2]))->assertOk()->assertSee('見出し1番：');
        $this->actingAs($this->w['head'])->get(route('approvals.notices.index', ['page' => 99]))->assertOk()
            ->assertSee('お知らせはありません。')->assertDontSee('aria-label="ページ送り"', false);
        $this->actingAs($this->w['head'])->get(route('approvals.notices.index', ['page' => 'abc']))->assertOk()->assertSee('見出し21番');
    }

    /** お知らせを押すと既読にして詳細へ。ほかの人のお知らせ・無い ID は 404 で、既読にもしない */
    public function test_opening_a_notice_marks_it_read_and_goes_to_the_request(): void
    {
        $this->launchApprovals();
        $request = $this->submittedFor($this->w);
        $mine    = $this->noticesOf($this->w['head'])->sole();
        $theirs  = $this->notice($this->w['reviewer'], $request);

        $this->actingAs($this->w['head'])->get(route('approvals.notices.open', $mine->id))
            ->assertRedirect(route('approvals.requests.show', $request));
        $this->assertNotNull($mine->fresh()->read_at);

        $this->actingAs($this->w['head'])->get(route('approvals.notices.open', $theirs->id))->assertNotFound();
        $this->assertNull($theirs->fresh()->read_at);
        $this->actingAs($this->w['head'])->get(route('approvals.notices.open', (string) Str::uuid()))->assertNotFound();
        $this->actingAs($this->w['head'])->get('/approvals/notices/not-a-uuid')->assertNotFound();
    }

    /** 見られなくなった申請のお知らせは、詳細の 404 にせず ⑥ に戻して知らせる（お知らせは既読にする。§5.6） */
    public function test_a_notice_of_a_request_that_can_no_longer_be_seen_goes_back_to_the_list(): void
    {
        $this->launchApprovals();
        $request = $this->submittedFor($this->w);
        app(Workflow::class)->judgeHead($request, $this->w['head'], $request->lock_version, ApprovalStepResult::Approve, null);
        $notice = $this->noticesOf($this->w['reviewer'])->sole();
        // 審査の意見を入れる前に審査部門から外れた（後任の審査担当者は残る）
        $this->w['reviewDept']->reviewers()->attach($this->baseUser(['name' => '後任 審査'])->id);
        $this->w['reviewDept']->reviewers()->detach($this->w['reviewer']->id);

        $this->actingAs($this->w['reviewer'])->get(route('approvals.notices.open', $notice->id))
            ->assertRedirect(route('approvals.notices.index'))
            ->assertSessionHas('error', 'この申請は、今は見られません。');
        $this->assertNotNull($notice->fresh()->read_at);
    }

    /** 詳細を開くと、開いた人のその申請の未読がすべて既読になる。ほかの申請の未読・ほかの人の未読は残す（D13） */
    public function test_opening_the_request_marks_only_my_notices_of_that_request_read(): void
    {
        $this->launchApprovals();
        $request = $this->submittedFor($this->w);
        $other   = $this->submittedFor($this->w, ['subject' => '別の申請']);
        $this->notice($this->w['head'], $request, '取り下げられました');
        $this->notice($this->w['president'], $request, '社長の決裁のお願い');

        $this->actingAs($this->w['head'])->get(route('approvals.requests.show', $request))->assertOk();

        $this->assertSame(0, ApprovalNotice::ownedBy($this->w['head'])->where('approval_request_id', $request->id)->unread()->count());
        $this->assertSame(1, ApprovalNotice::ownedBy($this->w['head'])->where('approval_request_id', $other->id)->unread()->count());
        $this->assertSame(1, ApprovalNotice::ownedBy($this->w['president'])->unread()->count());
    }

    /** 「すべて既読にする」: 自分の未読だけ。未読が無ければボタンを出さない */
    public function test_marking_all_read_touches_only_my_notices(): void
    {
        $this->launchApprovals();
        $request = $this->submittedFor($this->w);
        $this->notice($this->w['head'], $request, '取り下げられました');
        $this->notice($this->w['reviewer'], $request, '審査の意見のお願い');

        $html = $this->actingAs($this->w['head'])->get(route('approvals.notices.index'))->getContent();
        $form = $this->formAction($html, route('approvals.notices.readAll'));
        $this->assertNotNull($form, '「すべて既読にする」のフォームが無い');

        $this->actingAs($this->w['head'])->post(route('approvals.notices.readAll'))
            ->assertRedirect(route('approvals.notices.index'))
            ->assertSessionHas('success', 'お知らせをすべて既読にしました。');

        $this->assertSame(0, ApprovalNotice::ownedBy($this->w['head'])->unread()->count());
        $this->assertSame(1, ApprovalNotice::ownedBy($this->w['reviewer'])->unread()->count());
        $this->assertNull($this->formAction($this->actingAs($this->w['head'])->get(route('approvals.notices.index'))->getContent(), route('approvals.notices.readAll')));
    }

    /** ホーム: 未読の新しい 5 件と ⑥ へのリンク。未読が無ければ「新しいお知らせはありません」 */
    public function test_the_home_shows_the_five_newest_unread_notices(): void
    {
        $this->launchApprovals();
        $request = $this->draftFor($this->w);

        $this->actingAs($this->w['head'])->get(route('approvals.home'))->assertOk()
            ->assertSeeInOrder(['新しいお知らせ', '新しいお知らせはありません。', 'お知らせをすべて見る']);

        for ($i = 1; $i <= 6; $i++) {
            $this->notice($this->w['head'], $request, "見出し{$i}番");
            $this->travel(1)->seconds();
        }
        $this->notice($this->w['head'], $request, '既読の見出し')->markAsRead();

        $this->actingAs($this->w['head'])->get(route('approvals.home'))->assertOk()
            ->assertSeeInOrder(['新しいお知らせ', '見出し6番', '見出し5番', '見出し4番', '見出し3番', '見出し2番', 'お知らせをすべて見る'])
            ->assertDontSee('見出し1番')
            ->assertDontSee('既読の見出し')
            ->assertSee(route('approvals.notices.index'));
    }

    /** 件名などは画面ではエスケープする（お知らせの控えに < や & が入っていても HTML にならない） */
    public function test_the_notice_text_is_escaped(): void
    {
        $this->launchApprovals();
        $request = $this->draftFor($this->w, ['subject' => '<b>A&B社</b>の契約']);
        $this->notice($this->w['head'], $request, '差戻しされました', ['actor' => '<i>社長</i>']);

        foreach ([route('approvals.notices.index'), route('approvals.home')] as $url) {
            $html = $this->actingAs($this->w['head'])->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('&lt;b&gt;A&amp;B社&lt;/b&gt;の契約', $html);
            $this->assertStringNotContainsString('<b>A&B社</b>', $html);
            $this->assertStringNotContainsString('<i>社長</i>', $html);
        }
    }

    /** ⑥ の問い合わせの数は、お知らせの件数で増えない */
    public function test_the_list_does_not_query_per_notice(): void
    {
        $this->launchApprovals();
        $request = $this->draftFor($this->w);
        $queries = 0;
        DB::listen(function () use (&$queries): void { $queries++; });
        $measure = function () use (&$queries): int {
            $this->actingAs($this->w['head'])->get(route('approvals.notices.index'))->assertOk();
            $before = $queries;
            $this->actingAs($this->w['head'])->get(route('approvals.notices.index'))->assertOk();

            return $queries - $before;
        };

        $this->notice($this->w['head'], $request);
        $few = $measure();
        for ($i = 0; $i < 10; $i++) {
            $this->notice($this->w['head'], $request);
        }

        $this->assertSame($few, $measure());
    }

    /** そのフォームの action（無ければ null） */
    private function formAction(string $html, string $action): ?string
    {
        return preg_match('#<form method="POST" action="' . preg_quote($action, '#') . '">#', $html) === 1 ? $action : null;
    }
}
```

`tests/Feature/Approval/ApprovalAdminGateTest.php`（変更）

```diff
--- a/tests/Feature/Approval/ApprovalAdminGateTest.php
+++ b/tests/Feature/Approval/ApprovalAdminGateTest.php
@@ -102,6 +102,10 @@ class ApprovalAdminGateTest extends TestCase
         'approvals.requests.decide'           => '社長の決裁（同上）',
         'approvals.requests.confirmCondition' => '条件の確認（申請者だけ）',
         'approvals.requests.withdraw'         => '取り下げ（申請者だけ）',
+        // 段階3（使い始めてから）
+        'approvals.notices.index'   => 'お知らせ一覧（自分のお知らせだけ。段階3 設計書 §5.6）',
+        'approvals.notices.open'    => 'お知らせを開く（自分のお知らせだけ。ほかの人のものは 404）',
+        'approvals.notices.readAll' => '自分のお知らせをすべて既読にする',
     ];
 
     /** ラベル用: HEAD を除いた先頭の HTTP メソッド（1 つで十分な場所） */
@@ -183,8 +187,8 @@ public function test_every_approvals_route_is_classified(): void
         //   出ている本当の理由（分類漏れ・門番の欠落・逆方向の見落とし）が隠れる。
         $this->assertSame([], $problems, "分類漏れ・門番の欠落・逆方向の見落とし:\n" . implode("\n", $problems));
 
-        // 走査が空振りして緑になる事故を防ぐ（2b で 43 本 = 決裁の管理 22 本 + 進行中の申請の管理 4 本 + ホーム 1 本 + 申請を回す画面 16 本）
-        $this->assertGreaterThanOrEqual(43, $found, 'approvals. のルートの走査に失敗している');
+        // 走査が空振りして緑になる事故を防ぐ（3a で 46 本 = 決裁の管理 22 本 + 進行中の申請の管理 4 本 + ホーム 1 本 + 申請を回す画面 16 本 + お知らせ 3 本）
+        $this->assertGreaterThanOrEqual(46, $found, 'approvals. のルートの走査に失敗している');
     }
 
     /**
```

`tests/Feature/Approval/Phase2/LaunchGateTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/LaunchGateTest.php
+++ b/tests/Feature/Approval/Phase2/LaunchGateTest.php
@@ -32,9 +32,10 @@ class LaunchGateTest extends TestCase
 
     /**
      * 門番の付いたルートの数の下限（空振りで緑にならないように）。
-     * 実数 20 本 = 候補の検索 1・申請書と詳細 7・添付 3・判断など 5（2a）＋ 進行中の申請の管理 4（2b。管理の門番も持つ）。
+     * 実数 23 本 = 候補の検索 1・申請書と詳細 7・添付 3・判断など 5（2a）＋ 進行中の申請の管理 4（2b。管理の門番も持つ）
+     * ＋ お知らせ 3（3a）。
      */
-    private const MIN_GATED = 20;
+    private const MIN_GATED = 23;
 
     private function isOpenBeforeLaunch(string $name): bool
     {
```

`tests/Feature/Approval/Phase2/HomeAndListTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/HomeAndListTest.php
+++ b/tests/Feature/Approval/Phase2/HomeAndListTest.php
@@ -176,9 +176,14 @@ public function test_the_home_shows_only_my_own_requests(): void
         foreach (['他人の回覧中の申請', '他人の差戻しの申請', '他人の直しかけの件名', '他人の終わった申請'] as $subject) {
             $mine->assertDontSee($subject);
         }
+        // 部門長のホームの「新しいお知らせ」（段階3）には、提出されたときの知らせ（最後に提出した件名）が残るので、
+        // 対応待ちと進み具合だけを見る。直しかけの件名はお知らせにも出ない（控えから作る。D26）
+        $headSections = strstr($head->getContent(), '新しいお知らせ', true);
+        $this->assertNotFalse($headSections, 'ホームに「新しいお知らせ」の欄が無い');
         foreach (['他人の差戻しの申請', '他人の直しかけの件名', '他人の終わった申請'] as $subject) {
-            $head->assertDontSee($subject);
+            $this->assertStringNotContainsString($subject, $headSections);
         }
+        $head->assertDontSee('他人の直しかけの件名');
     }
 
     /**
```

`tests/Feature/ClockReadScanTest.php`（変更）

```diff
--- a/tests/Feature/ClockReadScanTest.php
+++ b/tests/Feature/ClockReadScanTest.php
@@ -56,6 +56,7 @@ class ClockReadScanTest extends TestCase
         'app/Support/Approval/ApprovalNumber.php'             => [1, '連番の行を作った瞬間（created_at・updated_at は TIMESTAMP 列。upsert は Eloquent を通らないので手で入れる）'],
         'app/Support/Approval/Workflow.php'                   => [9, '提出・届いた・判断・完了・状態が変わった瞬間と updated_at（付け替えの版の繰り上げ・取り消しで段階を戻したときを含む。すべて TIMESTAMP 列。画面では JapanTime で日本時間に直して出す）'],
         'app/Http/Controllers/Approval/RequestAttachmentController.php' => [1, 'approval_attachments.removed_at は TIMESTAMP 列（外した瞬間を UTC で保存する）'],
+        'app/Models/ApprovalNotice.php'                       => [1, 'notifications.read_at は TIMESTAMP 列（既読にした瞬間を UTC で保存する）'],
         'app/Support/Approval/MailDelivery.php'               => [2, '決裁のメールが送れた・送れなかった瞬間（approval_settings の TIMESTAMP 列。帯では JapanTime で日本時間に直して出す）'],
     ];
```

`tests/Feature/ValidationErrorFeedbackTest.php`（変更）

```diff
--- a/tests/Feature/ValidationErrorFeedbackTest.php
+++ b/tests/Feature/ValidationErrorFeedbackTest.php
@@ -35,6 +35,8 @@ class ValidationErrorFeedbackTest extends TestCase
         // 検証そのものが無いフォーム
         'layouts/partials/header.blade.php'
             => 'ログアウトの POST のみ。検証する入力が無い',
+        'approvals/notices/index.blade.php'
+            => '「すべて既読にする」の POST のみ。検証する入力が無い（段階3 のお知らせ一覧）',
 
         // ⚠ 取込画面は「独自の警告 UI があるから除外してよい」ではない。
         //   行単位の警告と `validate()` の失敗は**別物**で、後者は $errors に入る。
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/patches/0008-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase3/NoticeScreenTest.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase2/LaunchGateTest.php tests/Feature/Approval/Phase2/HomeAndListTest.php tests/Feature/ClockReadScanTest.php tests/Feature/ValidationErrorFeedbackTest.php
```

Expected: `ERRORS!` `Tests: 45, Assertions: 283, Errors: 9, Failures: 7.`

- `NoticeScreenTest::test_there_is_no_bell_before_launch` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.notices.index] not defined.`
- `NoticeScreenTest::test_the_bell_shows_the_unread_count` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.notices.index] not defined.`
- `NoticeScreenTest::test_the_list_shows_my_notices_newest_first` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.notices.index] not defined.`
- `NoticeScreenTest::test_the_list_pages_by_twenty` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.notices.index] not defined.`
- `NoticeScreenTest::test_opening_a_notice_marks_it_read_and_goes_to_the_request` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.notices.open] not defined.`
- `NoticeScreenTest::test_a_notice_of_a_request_that_can_no_longer_be_seen_goes_back_to_the_list` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.notices.open] not defined.`
- `NoticeScreenTest::test_marking_all_read_touches_only_my_notices` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.notices.index] not defined.`
- `NoticeScreenTest::test_the_notice_text_is_escaped` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.notices.index] not defined.`
- `NoticeScreenTest::test_the_list_does_not_query_per_notice` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.notices.index] not defined.`
- `ApprovalAdminGateTest::test_every_approvals_route_is_classified` — `分類漏れ・門番の欠落・逆方向の見落とし:`
- `HomeAndListTest::test_the_home_shows_only_my_own_requests` — `ホームに「新しいお知らせ」の欄が無い`
- `LaunchGateTest::test_every_approval_route_is_classified` — `門番の付いたルートが少なすぎる（分類が空振りしていないか）`
- `NoticeScreenTest::test_opening_the_request_marks_only_my_notices_of_that_request_read` — `Failed asserting that 2 is identical to 0.`
- `NoticeScreenTest::test_the_home_shows_the_five_newest_unread_notices` — `Failed asserting that Failed asserting that '<!DOCTYPE html>`
- `ClockReadScanTest::test_php_clock_reads_are_classified` — `時計の読み取りが分類と合わない:`
- `ValidationErrorFeedbackTest::test_the_exempt_list_has_no_stale_entries` — `EXEMPT の棚卸しが必要:`

（ルート・画面・既読の部品が無い。走査テストは、登録したルートとファイルがまだ無いので合わない）

- [ ] **Step 3: 既読の部品とベルの数を足す**

`app/Models/ApprovalNotice.php`（変更）

```diff
--- a/app/Models/ApprovalNotice.php
+++ b/app/Models/ApprovalNotice.php
@@ -36,4 +36,18 @@ public function approvalRequest(): BelongsTo
     {
         return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
     }
+
+    /**
+     * その人の未読を既読にする（申請を渡せばその申請の分だけ）。既読になる 3 つの入口のうち 2 つ（段階3 設計書 D13）:
+     * 申請の詳細を開いたとき（申請を渡す）と「すべて既読にする」。1 件を押したときは markAsRead()。
+     * ⚠ ほかの人の未読は変えない（ownedBy で持ち主に絞る）
+     */
+    public static function markReadFor(User $user, ?ApprovalRequest $request = null): void
+    {
+        static::query()
+            ->ownedBy($user)
+            ->unread()
+            ->when($request !== null, fn (Builder $query) => $query->where('approval_request_id', $request->id))
+            ->update(['read_at' => now()]);
+    }
 }
```

`app/Support/Approval/ApprovalMenu.php`（変更）

```diff
--- a/app/Support/Approval/ApprovalMenu.php
+++ b/app/Support/Approval/ApprovalMenu.php
@@ -2,6 +2,7 @@
 
 namespace App\Support\Approval;
 
+use App\Models\ApprovalNotice;
 use App\Models\ApprovalSetting;
 use App\Models\User;
 
@@ -12,11 +13,15 @@
  * - 1 リクエストに 1 回だけ数える（サイドバー 3 か所とダッシュボードで数え直さない。§5.15）。覚えるのはリクエストの
  *   attributes（リクエストごとに新しいので、テストで画面を 2 回開いても前の数を使わない）
  * - 数え方は ホーム①の対応待ちと同じ（PendingWork::countFor()）
+ *
+ * ヘッダーのベルの未読の数（段階3 設計書 §5.6）も同じ形で持つ（使い始める前は null＝ベルを出さない・1 リクエストに 1 回）。
  */
 final class ApprovalMenu
 {
     private const ATTRIBUTE = 'approval.pending_count';
 
+    private const UNREAD_ATTRIBUTE = 'approval.unread_notices';
+
     public static function pendingCount(User $user): ?int
     {
         $attributes = request()->attributes;
@@ -28,4 +33,17 @@ public static function pendingCount(User $user): ?int
 
         return $attributes->get($key);
     }
+
+    /** ベルに出す未読のお知らせの数（使い始める前は null） */
+    public static function unreadNotices(User $user): ?int
+    {
+        $attributes = request()->attributes;
+        $key        = self::UNREAD_ATTRIBUTE . '.' . $user->id;
+
+        if (! $attributes->has($key)) {
+            $attributes->set($key, ApprovalSetting::launchedForMenu() ? ApprovalNotice::ownedBy($user)->unread()->count() : null);
+        }
+
+        return $attributes->get($key);
+    }
 }
```

- [ ] **Step 4: ページ送りを部品にして ⑩ を寄せる**（⑩ の見た目と振る舞いは変えない）

`app/Support/Approval/PageNumbers.php`（新規）

```php
<?php

namespace App\Support\Approval;

/**
 * ページ送りに出す番号（段階2 の ⑩「決裁済み・否決」から寄せた。⑥ のお知らせ一覧も使う。段階3 設計書 §5.6）。
 * 画面の部品は approvals._pager（->links() は使わない。Bug #24）。
 */
final class PageNumbers
{
    /**
     * 出す番号（null は「…」）。先頭・最後・今のページの前後 1 つだけにする（全部を並べるとスマホの幅からはみ出す。
     * 2b の Task 9 の B1・利用者の決定 C5）。1 ページだけの間は「…」にせず、その番号を出す。
     * 番号と「…」は多くて 7 つ（前後の「<」「>」を足して 9 個）
     *
     * @return list<int|null>
     */
    public static function around(int $current, int $last): array
    {
        $shown = array_unique(array_filter([1, $current - 1, $current, $current + 1, $last], fn (int $page) => $page >= 1 && $page <= $last));
        sort($shown);

        $numbers = [];
        foreach ($shown as $i => $page) {
            $between = $i === 0 ? 0 : $page - $shown[$i - 1] - 1;   // 前に出した番号との間のページ数
            if ($between === 1) {
                $numbers[] = $page - 1;
            } elseif ($between > 1) {
                $numbers[] = null;
            }
            $numbers[] = $page;
        }

        return $numbers;
    }
}
```

`resources/views/approvals/_pager.blade.php`（新規）

```blade
{{-- ページ送り（->links() は使わない。プロジェクト規約 / Bug #24）。番号は先頭・最後・今のページの前後 1 つだけで、間は「…」
     （全部を並べるとスマホの幅からはみ出す。番号は App\Support\Approval\PageNumbers::around()。2b の Task 9 の B1・利用者の決定 C5）。
     渡すもの: $paginator（LengthAwarePaginator）・$pages（PageNumbers::around() の戻り値）。⑩ の「決裁済み・否決」と ⑥ のお知らせ一覧が使う --}}
@if($paginator->hasPages())
    <nav aria-label="ページ送り" class="flex justify-center gap-0.5 py-3 border-t border-gray-200">
        @if($paginator->onFirstPage())
            <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
        @endif
        @foreach($pages as $page)
            @if($page === null)
                <span class="w-8 h-8 flex items-center justify-center text-xs text-gray-400">…</span>
            @elseif($page === $paginator->currentPage())
                <span aria-current="page" class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
            @else
                <a href="{{ $paginator->url($page) }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
            @endif
        @endforeach
        @if($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
        @else
            <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
        @endif
    </nav>
@endif
```

`app/Http/Controllers/Approval/AdminRequestController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/AdminRequestController.php
+++ b/app/Http/Controllers/Approval/AdminRequestController.php
@@ -14,6 +14,7 @@
 use App\Support\Approval\Assignees;
 use App\Support\Approval\CurrentHandler;
 use App\Support\Approval\FormInput;
+use App\Support\Approval\PageNumbers;
 use App\Support\Approval\PendingWork;
 use App\Support\Approval\RequestPermissions;
 use App\Support\Approval\Workflow;
@@ -65,7 +66,7 @@ public function index(Request $request): View
                 ->paginate(self::DECIDED_PER_PAGE)
                 ->withQueryString();
 
-            return view('approvals.admin.requests', ['tab' => $tab, 'requests' => $requests, 'rows' => null, 'pages' => self::pageNumbers($requests->currentPage(), $requests->lastPage())]);
+            return view('approvals.admin.requests', ['tab' => $tab, 'requests' => $requests, 'rows' => null, 'pages' => PageNumbers::around($requests->currentPage(), $requests->lastPage())]);
         }
 
         $presidentId = ApprovalSetting::current()->president_user_id;
@@ -177,32 +178,6 @@ private static function onlyApplicantReviews(ApprovalRequest $r, ApprovalStep $s
         return $active->contains('id', $r->user_id) && $active->where('id', '!=', $r->user_id)->isEmpty();
     }
 
-    /**
-     * 「決裁済み・否決」のページ送りに出す番号（null は「…」）。先頭・最後・今のページの前後 1 つだけにする
-     * （全部を並べるとスマホの幅からはみ出す。Task 9 の B1・利用者の決定 C5）。1 ページだけの間は「…」にせず、その番号を出す。
-     * 番号と「…」は多くて 7 つ（前後の「<」「>」を足して 9 個）
-     *
-     * @return list<int|null>
-     */
-    private static function pageNumbers(int $current, int $last): array
-    {
-        $shown = array_unique(array_filter([1, $current - 1, $current, $current + 1, $last], fn (int $page) => $page >= 1 && $page <= $last));
-        sort($shown);
-
-        $numbers = [];
-        foreach ($shown as $i => $page) {
-            $between = $i === 0 ? 0 : $page - $shown[$i - 1] - 1;   // 前に出した番号との間のページ数
-            if ($between === 1) {
-                $numbers[] = $page - 1;
-            } elseif ($between > 1) {
-                $numbers[] = null;
-            }
-            $numbers[] = $page;
-        }
-
-        return $numbers;
-    }
-
     /** 見られるかの確かめと開き直す小窓（ReturnsToRequestDetail）に、理由の改行をそろえるのを足す */
     private function prepare(Request $request, ApprovalRequest $approvalRequest, string $modal): void
     {
```

`resources/views/approvals/admin/requests.blade.php`（変更）

```diff
--- a/resources/views/approvals/admin/requests.blade.php
+++ b/resources/views/approvals/admin/requests.blade.php
@@ -149,31 +149,7 @@ class="px-3 py-1.5 rounded-full text-[12px] font-semibold border {{ $tab === 'de
                     </div>{{-- /scroll-hint --}}
                 </div>
 
-                {{-- ページ送り（->links() は使わない。プロジェクト規約 / Bug #24）。番号は先頭・最後・今のページの前後 1 つだけで、間は「…」
-                     （全部を並べるとスマホの幅からはみ出す。番号はコントローラの pageNumbers()。Task 9 の B1・利用者の決定 C5） --}}
-                @if($requests->hasPages())
-                    <nav aria-label="ページ送り" class="flex justify-center gap-0.5 py-3 border-t border-gray-200">
-                        @if($requests->onFirstPage())
-                            <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
-                        @else
-                            <a href="{{ $requests->previousPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
-                        @endif
-                        @foreach($pages as $page)
-                            @if($page === null)
-                                <span class="w-8 h-8 flex items-center justify-center text-xs text-gray-400">…</span>
-                            @elseif($page === $requests->currentPage())
-                                <span aria-current="page" class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
-                            @else
-                                <a href="{{ $requests->url($page) }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
-                            @endif
-                        @endforeach
-                        @if($requests->hasMorePages())
-                            <a href="{{ $requests->nextPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
-                        @else
-                            <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
-                        @endif
-                    </nav>
-                @endif
+                @include('approvals._pager', ['paginator' => $requests, 'pages' => $pages])
             @endif
         @endif
     </div>
```

- [ ] **Step 5: ⑥ のコントローラ・ルート・画面を書く**

`app/Http/Controllers/Approval/NoticeController.php`（新規）

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalNotice;
use App\Support\Approval\PageNumbers;
use App\Support\Approval\RequestVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * お知らせ一覧（画面⑥・段階3 設計書 §5.6）。決裁のみ利用者も使う。
 *
 * ⚠ 引くのは必ず自分のお知らせ（ApprovalNotice::ownedBy）。ほかの人のお知らせの ID を与えても 404（在るかどうかを漏らさない）
 * ⚠ 既読になるのは 3 つ（D13）: お知らせを押したとき（open）・その申請の詳細を開いたとき（RequestController::show）・
 *   「すべて既読にする」（readAll）。「未読だけ」の絞り込みは作らない（D15）
 * ⚠ 戻り先は固定のルート（Bug #64）
 */
class NoticeController extends Controller
{
    /** 1 ページの件数 */
    private const PER_PAGE = 20;

    /** 一覧（新しい順・20 件ずつ） */
    public function index(Request $request): View
    {
        $user    = $request->user();
        $notices = ApprovalNotice::ownedBy($user)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return view('approvals.notices.index', [
            'notices'   => $notices,
            'pages'     => PageNumbers::around($notices->currentPage(), $notices->lastPage()),
            'hasUnread' => ApprovalNotice::ownedBy($user)->unread()->exists(),
        ]);
    }

    /**
     * 1 件を開く: 既読にして申請の詳細へ。付け替えなどで見られなくなった申請は、詳細の 404 にせず一覧に戻して知らせる
     * （お知らせは既読にする。§5.6）
     */
    public function open(Request $request, string $notice): RedirectResponse
    {
        $row = ApprovalNotice::ownedBy($request->user())->findOrFail($notice);
        $row->markAsRead();

        $approvalRequest = $row->approvalRequest;

        if ($approvalRequest === null || ! RequestVisibility::canView($request->user(), $approvalRequest)) {
            return redirect()->route('approvals.notices.index')->with('error', 'この申請は、今は見られません。');
        }

        return redirect()->route('approvals.requests.show', $approvalRequest);
    }

    /** すべて既読にする */
    public function readAll(Request $request): RedirectResponse
    {
        ApprovalNotice::markReadFor($request->user());

        return redirect()->route('approvals.notices.index')->with('success', 'お知らせをすべて既読にしました。');
    }
}
```

`routes/approval.php`（変更）

```diff
--- a/routes/approval.php
+++ b/routes/approval.php
@@ -2,6 +2,7 @@
 
 use App\Http\Controllers\Approval\AdminRequestController;
 use App\Http\Controllers\Approval\HomeController;
+use App\Http\Controllers\Approval\NoticeController;
 use App\Http\Controllers\Approval\OrganizationController;
 use App\Http\Controllers\Approval\RelatedNumberController;
 use App\Http\Controllers\Approval\RequestActionController;
@@ -138,4 +139,10 @@
     Route::post('/requests/{approvalRequest}/decide', [RequestActionController::class, 'decide'])->name('requests.decide');
     Route::post('/requests/{approvalRequest}/confirm-condition', [RequestActionController::class, 'confirmCondition'])->name('requests.confirmCondition');
     Route::post('/requests/{approvalRequest}/withdraw', [RequestActionController::class, 'withdraw'])->name('requests.withdraw');
+
+    // お知らせ（画面⑥。段階3 設計書 §5.6）。自分のお知らせだけ（ほかの人のお知らせの ID は 404）
+    // ⚠ `/notices/read-all` を `/notices/{notice}` より前に置く（今は HTTP メソッドが違うので当たらないが、登録順がマッチの優先順）
+    Route::get('/notices', [NoticeController::class, 'index'])->name('notices.index');
+    Route::post('/notices/read-all', [NoticeController::class, 'readAll'])->name('notices.readAll');
+    Route::get('/notices/{notice}', [NoticeController::class, 'open'])->whereUuid('notice')->name('notices.open');
 });
```

`resources/views/approvals/notices/index.blade.php`（新規）

```blade
@extends('layouts.app')

@section('title', 'お知らせ')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">お知らせ</span>
@endsection

{{-- お知らせ一覧（画面⑥・段階3 設計書 §5.6）。新しい順に 20 件ずつ。未読は太字と「未読」の印。押すと既読にして申請の詳細へ。
     「未読だけ」の絞り込みは作らない（D15）。スマホの幅でも 1 件 1 枚（表は使わない） --}}
@section('content')
<div class="max-w-[960px]">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h1 class="text-lg font-bold text-gray-900">お知らせ</h1>
        @if($hasUnread)
            <form method="POST" action="{{ route('approvals.notices.readAll') }}">
                @csrf
                <button type="submit" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-700 hover:bg-gray-50">すべて既読にする</button>
            </form>
        @endif
    </div>

    <div class="bg-white rounded-lg border border-gray-200">
        @if($notices->isEmpty())
            <p class="px-5 py-8 text-center text-[13px] text-gray-400">お知らせはありません。</p>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach($notices as $notice)
                    @include('approvals.notices._item', ['notice' => $notice])
                @endforeach
            </ul>
            @include('approvals._pager', ['paginator' => $notices, 'pages' => $pages])
        @endif
    </div>
</div>
@endsection
```

`resources/views/approvals/notices/_item.blade.php`（新規）

```blade
{{-- お知らせの 1 件（⑥ とホームの「新しいお知らせ」。段階3 設計書 §5.6）。未読は太字と「未読」の印。押すと既読にして申請の詳細へ。
     中身は操作の時点の控え（data）。保存した日時は JapanTime::format（Bug #61） --}}
@php $data = $notice->data; $unread = $notice->read_at === null; @endphp
<li>
    <a href="{{ route('approvals.notices.open', $notice->id) }}" class="block px-5 py-3 hover:bg-gray-50">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            @if($unread)
                <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-semibold text-white bg-red-600">未読</span>
            @endif
            <span class="text-[13px] break-words {{ $unread ? 'font-bold text-gray-900' : 'text-gray-700' }}">{{ $data['headline'] ?? '' }}：{{ $data['subject'] ?? '' }}</span>
            <span class="ml-auto text-[12px] text-gray-500 whitespace-nowrap">{{ \App\Support\JapanTime::format($notice->created_at, 'n/j H:i') }}</span>
        </div>
        <p class="mt-0.5 text-[12px] text-gray-500 break-words">{{ $data['applicant'] ?? '' }}（{{ $data['department'] ?? '' }}）@if(! empty($data['number']))・{{ $data['number'] }}@endif・{{ $data['actor'] ?? '' }}さんの操作・{{ $data['action'] ?? '' }}</p>
    </a>
</li>
```

- [ ] **Step 6: ベル・ホームの欄・詳細を開いたときの既読**

`resources/views/layouts/partials/notice-bell.blade.php`（新規）

```blade
{{-- お知らせのベル（ヘッダーの右・基幹の利用者にも決裁のみ利用者にも同じ所。段階3 設計書 §5.6・D14）。
     未読があれば赤い丸に数（100 以上は 99+）。押すとお知らせ一覧（⑥）へ（プルダウンは出さない）。
     使い始める前は出さない（ApprovalMenu::unreadNotices() が null。基幹の画面に決裁の気配を出さない）。
     ⚠ この partial の名前を sidebar で始めない・<nav> を使わない（LayoutSidebar*Test・LayoutBreadcrumbHomeTest の走査） --}}
@php $unreadNotices = \App\Support\Approval\ApprovalMenu::unreadNotices(Auth::user()); @endphp
@if($unreadNotices !== null)
    <a href="{{ route('approvals.notices.index') }}" class="relative inline-flex items-center justify-center w-9 h-9 rounded-md text-emerald-100 hover:text-white hover:bg-white/10 transition-colors"
       aria-label="お知らせ（未読 {{ $unreadNotices }} 件）" title="お知らせ">
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
            <path d="M13.73 21a2 2 0 0 1-3.46 0" />
        </svg>
        @if($unreadNotices > 0)
            <span class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10px] font-bold leading-[18px] text-center" aria-hidden="true">{{ $unreadNotices > 99 ? '99+' : $unreadNotices }}</span>
        @endif
    </a>
@endif
```

`resources/views/layouts/partials/header.blade.php`（変更）

```diff
--- a/resources/views/layouts/partials/header.blade.php
+++ b/resources/views/layouts/partials/header.blade.php
@@ -17,7 +17,9 @@ class="lg:hidden text-emerald-200 hover:text-white focus:outline-none"
         <img src="{{ asset('images/logo_yoko.png') }}" alt="ミツワ都市開発" class="h-5 w-auto">
     </div>
 
-    {{-- 右側: ユーザーメニュー --}}
+    {{-- 右側: お知らせのベル（段階3）・ユーザーメニュー --}}
+    <div class="flex items-center gap-3">
+    @include('layouts.partials.notice-bell')
     <div class="relative" x-data="{ userMenuOpen: false }">
         <button
             @click="userMenuOpen = !userMenuOpen"
@@ -65,4 +67,5 @@ class="absolute right-0 top-full mt-2 w-48 bg-white rounded-lg shadow-lg border
             </form>
         </div>
     </div>
+    </div>
 </header>
```

`app/Http/Controllers/Approval/HomeController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/HomeController.php
+++ b/app/Http/Controllers/Approval/HomeController.php
@@ -4,6 +4,7 @@
 
 use App\Enums\ApprovalStatus;
 use App\Http\Controllers\Controller;
+use App\Models\ApprovalNotice;
 use App\Models\ApprovalRequest;
 use App\Models\ApprovalSetting;
 use App\Support\Approval\PendingWork;
@@ -21,6 +22,9 @@ class HomeController extends Controller
     /** 「最近の完了」に出す数 */
     private const RECENT_FINISHED = 5;
 
+    /** 「新しいお知らせ」に出す数（未読の新しいもの。段階3 設計書 §5.6） */
+    private const NEW_NOTICES = 5;
+
     public function index(Request $request): View
     {
         $user = $request->user();
@@ -57,6 +61,12 @@ public function index(Request $request): View
                 ->orderByDesc('status_changed_at')
                 ->limit(self::RECENT_FINISHED)
                 ->get(),
+            // 新しいお知らせ（未読の新しい 5 件。中身は控えの data なので関係を読まない）
+            'notices'          => ApprovalNotice::ownedBy($user)->unread()
+                ->orderByDesc('created_at')
+                ->orderByDesc('id')
+                ->limit(self::NEW_NOTICES)
+                ->get(),
         ]);
     }
 }
```

`resources/views/approvals/home-launched.blade.php`（変更）

```diff
--- a/resources/views/approvals/home-launched.blade.php
+++ b/resources/views/approvals/home-launched.blade.php
@@ -85,6 +85,23 @@
         </div>
     </section>
 
+    {{-- 新しいお知らせ（未読の新しい 5 件。段階3 設計書 §5.6） --}}
+    <section class="bg-white rounded-lg border border-gray-200 mb-5">
+        <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">新しいお知らせ</h2>
+        @if($notices->isEmpty())
+            <p class="px-5 py-6 text-[13px] text-gray-400">新しいお知らせはありません。</p>
+        @else
+            <ul class="divide-y divide-gray-100">
+                @foreach($notices as $notice)
+                    @include('approvals.notices._item', ['notice' => $notice])
+                @endforeach
+            </ul>
+        @endif
+        <div class="px-5 py-3 border-t border-gray-100 text-right">
+            <a href="{{ route('approvals.notices.index') }}" class="text-[13px] text-emerald-600 hover:underline">お知らせをすべて見る</a>
+        </div>
+    </section>
+
     <div class="flex flex-wrap gap-3 text-[13px]">
         <a href="{{ route('password.change') }}" class="text-emerald-600 hover:underline">パスワードを変更する</a>
         @if($user->isApprovalAdmin())
```

`app/Http/Controllers/Approval/RequestController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/RequestController.php
+++ b/app/Http/Controllers/Approval/RequestController.php
@@ -6,6 +6,7 @@
 use App\Enums\ApprovalStepResult;
 use App\Http\Controllers\Controller;
 use App\Models\ApprovalHistory;
+use App\Models\ApprovalNotice;
 use App\Models\ApprovalRequest;
 use App\Models\ApprovalRevision;
 use App\Models\ApprovalStep;
@@ -115,6 +116,9 @@ public function show(Request $request, ApprovalRequest $approvalRequest): View
         $user = $request->user();
         $this->assertVisible($user, $approvalRequest);
 
+        // 開いた人の、この申請の未読のお知らせを既読にする（メールのリンクから開いたあとにベルの数が残らない。段階3 設計書 D13）
+        ApprovalNotice::markReadFor($user, $approvalRequest);
+
         // 回る順番の担当は今の設定から引く（CurrentHandler）。部門長・審査担当者・付け替え・判断した人を先に読む
         $approvalRequest->load([
             'applicant', 'department', 'type', 'attachments',
```

（差分のファイルを使うなら: `git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/patches/0008-*.patch`）

- [ ] **Step 7: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (45 tests, …)`

- [ ] **Step 8: 全件を流す**

Expected: `OK (3199 tests, 22788 assertions)`（`ApprovalMenuTest` の「使い始める前は何も足さない」「基幹の画面は設定の行を作らない」は、ベルが `launchedForMenu()` で読むので緑のまま。⑩ のページ送りの並びは `AdminRequestsTest` が見ていて、寄せたあとも緑のまま）

- [ ] **Step 9: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Http/Controllers/Approval/NoticeController.php app/Support/Approval/PageNumbers.php resources/views/approvals/_pager.blade.php resources/views/approvals/notices/index.blade.php resources/views/approvals/notices/_item.blade.php resources/views/layouts/partials/notice-bell.blade.php app/Models/ApprovalNotice.php app/Support/Approval/ApprovalMenu.php app/Http/Controllers/Approval/RequestController.php app/Http/Controllers/Approval/HomeController.php app/Http/Controllers/Approval/AdminRequestController.php routes/approval.php resources/views/layouts/partials/header.blade.php resources/views/approvals/home-launched.blade.php resources/views/approvals/admin/requests.blade.php tests/Feature/Approval/Phase3/NoticeScreenTest.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase2/LaunchGateTest.php tests/Feature/Approval/Phase2/HomeAndListTest.php tests/Feature/ClockReadScanTest.php tests/Feature/ValidationErrorFeedbackTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): ベル・お知らせ一覧・ホームの新しいお知らせ・既読の決まりを足す

ヘッダーのベル（未読の数）・お知らせ一覧（⑥・20 件ずつ・すべて既読にする）・
ホームの「新しいお知らせ」を足す（段階3 設計書 §5.6）。既読はお知らせを押したとき・
その申請の詳細を開いたとき（開いた本人の分だけ）・すべて既読にするの 3 つ（D13）。
見られなくなった申請のお知らせは ⑥ に戻して知らせる。ページ送りは ⑩ と同じ部品。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 8: メールが送れないときの帯（D2）・再発行のメールの送り直し（D6）

決裁のメールが送り直し 3 回のあとも送れなかったら、決裁の管理者の画面に黄色の帯を出す（設計書 D2・§0.6）。帯は「最後に送れなかった」のあとに 1 通も送れていないときだけ出て、1 通でも送れたら消える。置き場所は決裁の管理者のホーム（準備中の画面と使い始めたあとのホーム）と管理の 4 画面（利用者・部門・申請種類・進行中の申請）の上。パスワード再発行のメール（段階1）も同じ土台 `ApprovalMailable` に移す（送り直し 1 回 → 3 回・帯の対象。D6。差出人は全体の設定のまま）。

**Files:**
- Create: `resources/views/approvals/_mail_failure.blade.php`
- Modify: `app/Support/Approval/MailDelivery.php`（`pendingFailure()`）・`app/Models/ApprovalSetting.php`（日時の読み方）・`app/Mail/PasswordReissuedMail.php`（土台を移す）・`resources/views/approvals/home.blade.php`・`resources/views/approvals/home-launched.blade.php`・`resources/views/approvals/admin/users/index.blade.php`・`resources/views/approvals/admin/organization.blade.php`・`resources/views/approvals/admin/types.blade.php`・`resources/views/approvals/admin/requests.blade.php`（帯を置く）
- Test: Create `tests/Feature/Approval/Phase3/MailFailureBannerTest.php`・Modify `tests/Feature/Approval/PasswordReissueTest.php`（`test_it_gives_up_after_one_attempt` を `test_it_is_tried_three_times_a_minute_apart` に。§0.9）

**Interfaces:**
- Consumes: `ApprovalMailable`・`MailDelivery::recordSent()`・`recordFailed()`（Task 4）
- Produces: `MailDelivery::pendingFailure(): ?array{at: CarbonInterface, to: string}`（設定の行を作らずに読む）／ビューの部品 `approvals._mail_failure`（決裁の管理者で、送れなかったまま のときだけ出す）

**差分の大きさ:** 12 ファイル・+176 / −24 行（差分のファイル `0009-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase3/MailFailureBannerTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase3;

use App\Mail\PasswordReissuedMail;
use App\Models\ApprovalSetting;
use App\Models\User;
use App\Support\Approval\MailDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 通知メールが送れないときの帯（段階3 設計書 D2・§5.6）と、パスワード再発行のメールも同じ記録を使うこと（D6）。
 */
class MailFailureBannerTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const BANNER = '通知メールが送れていません。';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 10:05', 'Asia/Tokyo')->utc());
        ApprovalSetting::current();   // 本番は SQL が入れた 1 行がある
    }

    /** 決裁の管理者の画面（使い始める前）: ホーム（準備中）と管理の 3 画面 */
    private function adminPagesBeforeLaunch(): array
    {
        return [
            route('approvals.home'),
            route('approvals.admin.users.index'),
            route('approvals.admin.organization.index'),
            route('approvals.admin.types.index'),
        ];
    }

    public function test_there_is_no_banner_while_mail_is_fine(): void
    {
        $admin = $this->approvalAdmin();
        MailDelivery::recordSent();

        foreach ($this->adminPagesBeforeLaunch() as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertDontSee(self::BANNER);
        }
    }

    /** 送れなかったら、決裁の管理者のホームと管理の画面の上に、日時（日本時間）と宛先を出す */
    public function test_a_failure_shows_the_banner_on_the_admin_pages(): void
    {
        $admin = $this->approvalAdmin();
        MailDelivery::recordFailed('山田 花子');

        foreach ($this->adminPagesBeforeLaunch() as $url) {
            $this->actingAs($admin)->get($url)->assertOk()
                ->assertSee('通知メールが送れていません。最後に送れなかったのは 9/30 10:05（宛先: 山田 花子さん）です。画面のお知らせは届いています。メールの設定の確認が必要です。');
        }

        $this->launchApprovals();
        foreach ([route('approvals.home'), route('approvals.admin.requests.index')] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertSee(self::BANNER);
        }
    }

    /** 決裁の管理者でない人には出さない */
    public function test_the_banner_is_only_for_approval_admins(): void
    {
        MailDelivery::recordFailed('山田 花子');
        $this->launchApprovals();

        $this->actingAs($this->approvalOnlyUser())->get(route('approvals.home'))->assertOk()->assertDontSee(self::BANNER);
    }

    /** 送れなかったより後に 1 通でも送れたら消える。送れたのが前なら出たまま */
    public function test_a_later_success_clears_the_banner(): void
    {
        $admin = $this->approvalAdmin();
        MailDelivery::recordSent();
        $this->travel(1)->minutes();
        MailDelivery::recordFailed('山田 花子');

        $this->actingAs($admin)->get(route('approvals.home'))->assertSee(self::BANNER);

        $this->travel(1)->minutes();
        MailDelivery::recordSent();

        $this->actingAs($admin)->get(route('approvals.home'))->assertDontSee(self::BANNER);
    }

    /** パスワード再発行のメールも、送れた・送れなかったを同じ所に記録する（D6） */
    public function test_the_password_mail_records_success_and_failure(): void
    {
        $admin = $this->approvalAdmin();
        $mail  = (new PasswordReissuedMail(User::factory()->create(['name' => '再発行 太郎', 'must_change_password' => false]), '管理 花子', now(), 'https://example.com/login'))
            ->to('taro@mitsuwat.co.jp');

        Log::spy();
        $mail->failed(new RuntimeException('SMTP がつながりません'));
        $this->actingAs($admin)->get(route('approvals.home'))->assertSee('（宛先: 再発行 太郎さん）');

        $this->travel(1)->minutes();
        $mail->send(app(MailFactory::class));
        $this->actingAs($admin)->get(route('approvals.home'))->assertDontSee(self::BANNER);
    }
}
```

`tests/Feature/Approval/PasswordReissueTest.php`（変更）

```diff
--- a/tests/Feature/Approval/PasswordReissueTest.php
+++ b/tests/Feature/Approval/PasswordReissueTest.php
@@ -196,14 +196,12 @@ public function test_the_guide_does_not_swap_the_notified_and_skipped_counts():
     }
 
     /**
-     * 1 回の失敗でそのまま `failed()` を呼ばせる（`$tries = 1`）。
+     * 送り直しは 3 回・60 秒あけ（要件 15.3。段階3 設計書 D6 で 1 回から揃えた。決裁の知らせのメールと同じ ApprovalMailable）。
      *
-     * ⚠ これは**実挙動**を決めている。`routes/console.php` の worker は
-     *   `--tries=3 --backoff=60` なので、この行が消えると 3 回・60 秒待ちに変わる
-     *   （`SendQueuedMailable` が `property_exists($mailable, 'tries')` でペイロードに載せ、
-     *   `Job::maxTries()` が worker の指定を上書きする。vendor で確認済み）。
+     * ⚠ これは**実挙動**を決めている（`SendQueuedMailable` が `property_exists($mailable, 'tries')` でペイロードに載せ、
+     *   `Job::maxTries()` が worker の指定を上書きする。vendor で確認済み）。キューのジョブの形で見る。
      */
-    public function test_it_gives_up_after_one_attempt(): void
+    public function test_it_is_tried_three_times_a_minute_apart(): void
     {
         $mail = new PasswordReissuedMail(
             User::factory()->create(['must_change_password' => false]),
@@ -212,9 +210,9 @@ public function test_it_gives_up_after_one_attempt(): void
             'https://example.com/login',
         );
 
-        // ⚠ 先に存在を見る（消されたときの赤が「Undefined property」になって理由が読めないため）
-        $this->assertTrue(property_exists($mail, 'tries'), '$tries が無い（worker の --tries=3 に従うようになる）');
-        $this->assertSame(1, $mail->tries);
+        $job = new \Illuminate\Mail\SendQueuedMailable($mail);
+        $this->assertSame(3, $job->tries);
+        $this->assertSame(60, $job->backoff());
     }
 
     /**
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/patches/0009-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase3/MailFailureBannerTest.php tests/Feature/Approval/PasswordReissueTest.php
```

Expected: `FAILURES!` `Tests: 20, Assertions: 60, Failures: 4.`

- `PasswordReissueTest::test_it_is_tried_three_times_a_minute_apart` — `Failed asserting that 1 is identical to 3.`
- `MailFailureBannerTest::test_a_failure_shows_the_banner_on_the_admin_pages` — `Failed asserting that '<!DOCTYPE html>\n`
- `MailFailureBannerTest::test_a_later_success_clears_the_banner` — `Failed asserting that '<!DOCTYPE html>\n`
- `MailFailureBannerTest::test_the_password_mail_records_success_and_failure` — `Failed asserting that '<!DOCTYPE html>\n`

（帯が無い・再発行のメールは 1 回で諦める。`test_there_is_no_banner_while_mail_is_fine` と `test_the_banner_is_only_for_approval_admins` は「出さない」を見るので、今も緑）

- [ ] **Step 3: 帯の判定と日時の読み方を足す**

`app/Support/Approval/MailDelivery.php`（変更）

```diff
--- a/app/Support/Approval/MailDelivery.php
+++ b/app/Support/Approval/MailDelivery.php
@@ -29,4 +29,26 @@ public static function recordFailed(string $recipientName): void
             'mail_last_failed_to' => mb_substr($recipientName, 0, 255),
         ]);
     }
+
+    /**
+     * 帯に出す「最後に送れなかった」（そのあとに 1 通も送れていないときだけ）。無ければ null。
+     * ⚠ 設定の行を作らずに読む（管理の画面は使い始める前から開く。行が無ければ帯も無い）
+     *
+     * @return array{at: \Carbon\CarbonInterface, to: string}|null
+     */
+    public static function pendingFailure(): ?array
+    {
+        $row = ApprovalSetting::query()->whereKey(ApprovalSetting::SINGLETON_ID)
+            ->first(['id', 'mail_last_sent_at', 'mail_last_failed_at', 'mail_last_failed_to']);
+
+        if ($row?->mail_last_failed_at === null) {
+            return null;
+        }
+
+        if ($row->mail_last_sent_at !== null && $row->mail_last_sent_at->gt($row->mail_last_failed_at)) {
+            return null;
+        }
+
+        return ['at' => $row->mail_last_failed_at, 'to' => (string) $row->mail_last_failed_to];
+    }
 }
```

`app/Models/ApprovalSetting.php`（変更）

```diff
--- a/app/Models/ApprovalSetting.php
+++ b/app/Models/ApprovalSetting.php
@@ -24,7 +24,13 @@ class ApprovalSetting extends Model
 
     protected function casts(): array
     {
-        return ['president_user_id' => 'integer', 'launched_at' => 'datetime'];
+        return [
+            'president_user_id'   => 'integer',
+            'launched_at'         => 'datetime',
+            // 決裁のメールが送れた・送れなかった（段階3 設計書 D2。書くのは MailDelivery だけ）
+            'mail_last_sent_at'   => 'datetime',
+            'mail_last_failed_at' => 'datetime',
+        ];
     }
 
     /** 使い始めたか（設計書 §5.2・D1）。空のあいだは申請を回す画面を誰にも見せない */
```

- [ ] **Step 4: 帯の部品を書き、6 つの画面に置く**

`resources/views/approvals/_mail_failure.blade.php`（新規）

```blade
{{-- 通知メールが送れないときの帯（決裁の管理者だけ。段階3 設計書 D2・§5.6）。最後に送れなかった日時より後に 1 通でも送れたら出さない
     （MailDelivery::pendingFailure()）。決裁の管理者のホーム（使い始める前の準備中の画面も）と管理の画面の上に置く。
     送れなかったメールの送り直しの操作は作らない（D2）。保存した日時は JapanTime::format（Bug #61） --}}
@if(Auth::user()->isApprovalAdmin() && ($mailFailure = \App\Support\Approval\MailDelivery::pendingFailure()) !== null)
    <div class="mb-5 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-[13px] text-amber-900">
        通知メールが送れていません。最後に送れなかったのは {{ \App\Support\JapanTime::format($mailFailure['at'], 'n/j H:i') }}（宛先: {{ $mailFailure['to'] }}さん）です。画面のお知らせは届いています。メールの設定の確認が必要です。
    </div>
@endif
```

`resources/views/approvals/home.blade.php`（変更）

```diff
--- a/resources/views/approvals/home.blade.php
+++ b/resources/views/approvals/home.blade.php
@@ -10,6 +10,8 @@
 @section('content')
 <div class="max-w-[640px]">
 
+    @include('approvals._mail_failure')
+
     @if(session('warning'))
         <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-800">
             {{ session('warning') }}
```

`resources/views/approvals/home-launched.blade.php`（変更）

```diff
--- a/resources/views/approvals/home-launched.blade.php
+++ b/resources/views/approvals/home-launched.blade.php
@@ -10,6 +10,8 @@
 @section('content')
 <div class="max-w-[960px]">
 
+    @include('approvals._mail_failure')
+
     @if(session('warning'))
         <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-800">
             {{ session('warning') }}
```

`resources/views/approvals/admin/users/index.blade.php`（変更）

```diff
--- a/resources/views/approvals/admin/users/index.blade.php
+++ b/resources/views/approvals/admin/users/index.blade.php
@@ -20,6 +20,8 @@
 --}}
 <div x-data="approvalUsers()" x-cloak>
 
+    @include('approvals._mail_failure')
+
     @if($errors->any())
         <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
             <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
```

`resources/views/approvals/admin/organization.blade.php`（変更）

```diff
--- a/resources/views/approvals/admin/organization.blade.php
+++ b/resources/views/approvals/admin/organization.blade.php
@@ -12,6 +12,8 @@
 @section('content')
 <div x-data="approvalOrganization()" x-cloak>
 
+    @include('approvals._mail_failure')
+
     {{-- 成功・失敗の帯はレイアウトが出す（ここで出すと画面に 2 回出る）。$errors だけ各ビューの責任 --}}
     @if($errors->any())
         <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
```

`resources/views/approvals/admin/types.blade.php`（変更）

```diff
--- a/resources/views/approvals/admin/types.blade.php
+++ b/resources/views/approvals/admin/types.blade.php
@@ -28,6 +28,7 @@
     $refusedCreate = $refused && old('edit_id') === null && old('_method') === null;
 @endphp
 <div x-data="approvalTypes()" x-cloak>
+    @include('approvals._mail_failure')
 
     {{-- 成功・失敗の帯はレイアウトが出す（ここで出すと画面に 2 回出る）。$errors だけ各ビューの責任 --}}
     @if($errors->any())
```

`resources/views/approvals/admin/requests.blade.php`（変更）

```diff
--- a/resources/views/approvals/admin/requests.blade.php
+++ b/resources/views/approvals/admin/requests.blade.php
@@ -14,6 +14,7 @@
      ⚠ 件名・申請部門は最後に提出した控えのもの（差戻し中の直しかけを出さない。D26）。保存した日時は JapanTime::format（Bug #61） --}}
 @section('content')
 <div>
+    @include('approvals._mail_failure')
     <h1 class="text-lg font-bold text-gray-900 mb-2">進行中の申請の管理</h1>
     <p class="text-[12px] text-gray-500 mb-4 max-w-[720px]">止まっている申請を見つけて、詳細の画面で部門長の確認の付け替え・押し間違いの取り消し・申請者に代わっての取り下げを行います。決裁したあとの押し間違いは「決裁済み・否決」から開きます。</p>
```

- [ ] **Step 5: 再発行のメールを同じ土台に移す**

`app/Mail/PasswordReissuedMail.php`（変更）

```diff
--- a/app/Mail/PasswordReissuedMail.php
+++ b/app/Mail/PasswordReissuedMail.php
@@ -5,14 +5,8 @@
 use App\Models\User;
 use App\Support\JapanTime;
 use Carbon\CarbonInterface;
-use Illuminate\Bus\Queueable;
-use Illuminate\Contracts\Queue\ShouldQueue;
-use Illuminate\Mail\Mailable;
 use Illuminate\Mail\Mailables\Content;
 use Illuminate\Mail\Mailables\Envelope;
-use Illuminate\Queue\SerializesModels;
-use Illuminate\Support\Facades\Log;
-use Throwable;
 
 /**
  * パスワードを再発行したことの通知（設計書 §5.13・要件 12.5）。
@@ -21,14 +15,11 @@
  *   このメールは「身に覚えのない再発行に気づけるように」するためのもの。
  * ⚠ ログイン画面の URL は**呼び出し側から渡す**。キューの中で `route()` を呼ぶと
  *   `APP_URL` に頼ることになり、本番で `/index.php` が抜ける（要件 15.1）。
+ * ⚠ 送り直しは 3 回・60 秒あけ、送れた・送れなかったを記録する（決裁の管理者の画面の帯。段階3 設計書 D6・D2。ApprovalMailable）。
+ *   差出人は全体の設定のまま（「経営管理システム」のメール。決裁の知らせの差出人の名前は使わない）
  */
-class PasswordReissuedMail extends Mailable implements ShouldQueue
+class PasswordReissuedMail extends ApprovalMailable
 {
-    use Queueable, SerializesModels;
-
-    // 段階0 の OpsTestMail と同じ方針: 1 回の失敗でそのまま failed() を呼ばせ、laravel.log に残す
-    public $tries = 1;
-
     public function __construct(
         public User $recipient,
         public string $actorName,
@@ -52,8 +43,13 @@ public function content(): Content
         ]);
     }
 
-    public function failed(Throwable $e): void
+    protected function failedRecipientName(): string
+    {
+        return $this->recipient->name;
+    }
+
+    protected function failureLabel(): string
     {
-        Log::error('パスワード再発行の通知メールを送れませんでした（宛先: ' . implode('、', array_column($this->to, 'address')) . '）: ' . $e->getMessage());
+        return 'パスワード再発行の通知メールを送れませんでした';
     }
 }
```

（差分のファイルを使うなら: `git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/patches/0009-*.patch`）

- [ ] **Step 6: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (20 tests, …)`

- [ ] **Step 7: 全件を流す**

Expected: `OK (3204 tests, 22817 assertions)`

- [ ] **Step 8: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add resources/views/approvals/_mail_failure.blade.php app/Support/Approval/MailDelivery.php app/Models/ApprovalSetting.php app/Mail/PasswordReissuedMail.php resources/views/approvals/home.blade.php resources/views/approvals/home-launched.blade.php resources/views/approvals/admin/users/index.blade.php resources/views/approvals/admin/organization.blade.php resources/views/approvals/admin/types.blade.php resources/views/approvals/admin/requests.blade.php tests/Feature/Approval/Phase3/MailFailureBannerTest.php tests/Feature/Approval/PasswordReissueTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): メールが送れないときの帯と再発行のメールの送り直し 3 回を足す

決裁のメールが送り直し 3 回のあとも送れなかったら、決裁の管理者のホームと管理の
画面に黄色の帯を出す（1 通でも送れたら消える。段階3 設計書 D2）。パスワード
再発行のメールも同じ土台に移し、送り直しを 3 回にそろえる（D6）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---


## Task 9: 全件テストと変異テスト（Bug #44 の作法）

**Files:** なし（測るだけ。穴が見つかったらテストを足してコミットする）

計画を書く段階で、試作（この計画のコードと同じ中身）に下の表の変異を 1 つずつ当てて測った（2026-09-30 夕〜10-01。決裁のテスト〈`tests/Feature/Approval`・`tests/Unit/Approval`〉と `UserManagementApprovalTest`・走査テスト 8 本・`PropertyListSortTest` を流した）。この Task では、実装したコードで**同じ結果になること**を確かめる。

⚠ WT のファイルを一時的に壊す変異は、自動の許可の判定に断られる。**WT の HEAD の写しを scratchpad に作ってそこで当てる**（WT は読むだけ）。以下の `<scratchpad>` は、その会話の scratchpad のパス（Mac を再起動すると消える。残したい結果は `~/.claude/plans/approval-phase3-tasks/` へ写す）:

```bash
SCR=<scratchpad>/p3a-mutation && mkdir -p "$SCR" && git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 archive HEAD | tar -x -C "$SCR" && cp -Rc /Users/masanori/site/manage/.claude/worktrees/approval-phase3/vendor "$SCR/vendor" && ! test -L "$SCR/vendor" && echo "写し OK"
```

- [ ] **Step 1: 全件が緑の状態から始める**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git status --porcelain && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `git status --porcelain` が空・`OK (3204 tests, 22817 assertions)`

- [ ] **Step 2: 変異を当てて表と突き合わせる**

道具は `~/.claude/plans/approval-phase3-tasks/mutate.py`（変異の一覧入り。1 つ当てて流し、必ず元に戻して、戻ったことを確かめる。書き換える前の文字列が 1 回だけ現れないものは当てずに SKIP と記録する）。先に `--check` で、76 通りとも当てる場所がちょうど 1 回ずつ見つかることを確かめてから流す（1 通り約 40 秒・全部で約 55 分）:

```bash
python3 ~/.claude/plans/approval-phase3-tasks/mutate.py <scratchpad>/p3a-mutation /dev/null --check
python3 ~/.claude/plans/approval-phase3-tasks/mutate.py <scratchpad>/p3a-mutation <scratchpad>/mutations.jsonl
```

Expected（1 行目）: `NG` の行が無く `checked 76`。途中で止めるときは python に SIGINT（写しのファイルが元に戻る）。同じ出力のファイルを渡して流し直すと、記録済みの変異を飛ばして続きから流す。

⚠ **最初の `CANARY`（お知らせの 1 件の部品に未定義の変数）が赤になること**を確かめてから結果を読む（測定が写しのコードを読んでいることの証明）。
⚠ **赤/緑ではなく「落ちたテストの集合」と「落ちた理由の文言」まで突き合わせる。** 意図と別の機構が落としているなら、その変異の測定は無効。当て方を変えて測り直す。

計画の時点の実測（試作。「落ちたテスト」はクラス名を省いたテストの名前）:

| # | 変異（ファイル） | 結果 | 落ちたテスト（実測） | 判定 |
|---|---|---|---|---|
| CANARY | カナリア: お知らせの 1 件の部品に未定義の変数（`_item.blade.php`） | Tests: 953, Assertions: 7294, Errors: 1, Failures: 15. | 16 本（ApprovalMenuTest, HomeAndListTest, NoticeScreenTest ほか） | カナリア（赤が正しい） |
| R01 | M-4: フラッシュを 404 の前に戻す（`ReturnsToRequestDetail.php`） | Tests: 953, Assertions: 7413, Failures: 1. | `test_an_operation_on_a_request_that_cannot_be_seen_leaves_no_modal_to_reopen` | 検出 |
| R02 | 先を越されたときも入力を戻す（`ReturnsToRequestDetail.php`） | Tests: 953, Assertions: 7399, Failures: 3. | `test_a_refused_judgement_reopens_with_the_choice_and_the_comment`、`test_a_refused_reassignment_reopens_its_modal`、`test_a_refused_withdrawal_or_condition_reopens_with_the_comment` | 検出 |
| R03 | 断られたとき入力を戻さない（`ReturnsToRequestDetail.php`） | Tests: 953, Assertions: 7402, Failures: 3. | `test_a_refused_judgement_reopens_with_the_choice_and_the_comment`、`test_a_refused_reassignment_reopens_its_modal`、`test_the_reason_in_the_reopened_modal_belongs_to_the_refused_choice` | 検出 |
| R04 | 入力の検査で断られたら詳細へ戻さない（`ReturnsToRequestDetail.php`） | Tests: 953, Assertions: 7279, Errors: 13. | `test_a_reason_over_two_thousand_characters_is_refused`、`test_a_reassignment_sent_as_an_array_reopens_its_modal`、`test_a_refused_judgement_reopens_with_the_choice_and_the_comment`、`test_a_refused_reassignment_reopens_its_modal`、`test_a_refused_undo_reopens_its_modal`、`test_a_refused_withdrawal_or_condition_reopens_with_the_comment` ほか 7 本 | 検出 |
| A01 | 有効な審査担当者に無効の人を入れる（`ApprovalDepartment.php`） | Tests: 953, Assertions: 7418, Failures: 4. | `test_a_review_department_whose_only_reviewer_is_inactive_is_flagged`、`test_active_reviewers_leave_out_inactive_and_deleted_users`、`test_the_list_flags_requests_stuck_on_the_applicant`、`test_there_must_be_another_active_reviewer` | 検出 |
| A02 | 提出の条件で申請者本人を審査担当者に数える（`SubmitChecker.php`） | Tests: 953, Assertions: 7421, Failures: 1. | `test_there_must_be_another_active_reviewer` | 検出 |
| A03 | 種類の一覧の人数を読み違える（`types.blade.php`） | Tests: 953, Assertions: 7419, Failures: 1. | `test_the_table_cells_show_each_type_in_display_order` | 検出 |
| A04 | ⑩ の印で無効の審査担当者を数える（`AdminRequestController.php`） | Tests: 953, Assertions: 7420, Failures: 1. | `test_the_list_flags_requests_stuck_on_the_applicant` | 検出 |
| T01 | SQL の外部キーを CASCADE に（`2026-09-30-approval-phase3a.sql`） | Tests: 953, Assertions: 7420, Failures: 1. | `test_the_sql_and_the_migration_declare_the_same_foreign_keys` | 検出 |
| T02 | migration の列の NULL を許さない（`2026_09_30_000001_create_approval_phase3a_tables.php`） | Tests: 953, Assertions: 1647, Errors: 496, Failures: 107. | `test_a_base_page_reads_the_launch_without_the_remembered_setting`、`test_a_blank_or_malformed_query_returns_nothing`、`test_a_circulating_request_stays_with_its_review_department`、`test_a_colleague_in_the_same_department_does_not_see`、`test_a_complete_draft_has_no_reasons`、`test_a_complete_request_can_be_submitted_from_the_form` ほか 558 本 | 検出（狙いの `test_the_sql_and_the_migration_declare_the_same_columns` を含む。ほかは設定の行を作れずに巻き添えで落ちたもの） |
| T03 | ownedBy で決裁のお知らせに絞らない（`ApprovalNotice.php`） | Tests: 953, Assertions: 7420, Failures: 1. | `test_owned_by_returns_only_the_users_approval_notices` | 検出 |
| S01 | 無効の人を担当に入れる（`StepHandlers.php`） | Tests: 953, Assertions: 7421, Failures: 1. | `test_a_deleted_or_inactive_head_or_president_is_not_a_handler` | 検出 |
| S02 | 申請者本人を担当に入れる（`StepHandlers.php`） | Tests: 953, Assertions: 7342, Failures: 2. | `test_the_applicant_is_not_the_handler_even_as_the_president`、`test_the_handlers_are_the_people_who_have_it_in_their_pending_work_and_can_judge` | 検出 |
| S03 | 付け替えを見ない（`StepHandlers.php`） | Tests: 953, Assertions: 7294, Failures: 1. | `test_the_handlers_are_the_people_who_have_it_in_their_pending_work_and_can_judge` | 検出 |
| S04 | 審査担当者を activeReviewers() でなく reviewers() で読む（等価の見込み: 下の filter が無効の人を落とす）（`StepHandlers.php`） | OK (953 tests, 7421 assertions) | — | 等価:すぐ下の filter が無効の人を落とし、削除した人はどちらも SoftDeletes で入らない（activeReviewers() を使うのは条件を 1 か所にするため。Task 1-B） |
| N01 | 決まり 1: 操作した本人にも出す（`Notifier.php`） | Tests: 953, Assertions: 7420, Failures: 2. | `test_the_admin_who_became_the_head_gets_nothing`、`test_the_person_who_did_it_gets_nothing` | 検出 |
| N02 | 決まり 2: 後に足した知らせで上書きする（`Notifier.php`） | Tests: 953, Assertions: 7420, Failures: 3. | `test_undoing_a_decision_does_not_reach_the_head_and_the_reviewer`、`test_undoing_the_condition_confirmation_asks_the_applicant_once_again`、`test_undoing_the_head_approval_gives_the_head_the_turn_back` | 検出 |
| N03 | 決まり 3: 削除した人にも出す（`Notifier.php`） | Tests: 953, Assertions: 7419, Failures: 1. | `test_inactive_or_deleted_people_get_nothing` | 検出 |
| N04 | 決まり 3: 無効の人にも出す（`Notifier.php`） | Tests: 953, Assertions: 7418, Failures: 1. | `test_inactive_or_deleted_people_get_nothing` | 検出 |
| N05 | 決まり 4: 許可していないドメインにもメール（`Notifier.php`） | Tests: 953, Assertions: 7421, Failures: 2. | `test_inactive_or_deleted_people_get_nothing`、`test_only_people_on_an_allowed_domain_get_a_mail` | 検出 |
| N06 | 決まり 5: 担当の番の知らせを申請者本人にも（`Notifier.php`） | Tests: 953, Assertions: 7416, Failures: 2. | `test_a_new_president_gets_one_notice_per_waiting_request`、`test_the_applicant_gets_no_turn_notice_for_their_own_request` | 検出 |
| N07 | 決まり 6: 件名を今の申請の行から（`Notifier.php`） | Tests: 953, Assertions: 7421, Failures: 1. | `test_the_subject_comes_from_the_last_submitted_content` | 検出 |
| N08 | 決まり 7: 使い始める前も出す（`Notifier.php`） | Tests: 953, Assertions: 7387, Errors: 3, Failures: 8. | `test_control_characters_in_the_subject_become_spaces`、`test_inactive_or_deleted_people_get_nothing`、`test_nothing_is_made_before_launch`、`test_one_person_gets_one_notice_for_a_request_in_one_operation`、`test_only_people_on_an_allowed_domain_get_a_mail`、`test_the_handler_gets_a_notice_and_a_mail` ほか 4 本 | 検出 |
| N09 | 場面 6 にもメール（`Notifier.php`） | Tests: 953, Assertions: 7421, Failures: 1. | `test_a_confirmed_condition_is_a_notice_only_for_the_president_and_the_head` | 検出 |
| N10 | 件名の制御文字を残す（`Notifier.php`） | Tests: 953, Assertions: 7421, Failures: 1. | `test_control_characters_in_the_subject_become_spaces` | 検出 |
| N11 | リンクを設定から作る（/index.php が抜ける）（`Notifier.php`） | Tests: 953, Assertions: 7421, Failures: 1. | `test_the_mail_link_keeps_the_front_controller_of_the_request` | 検出 |
| N12 | 操作した人の名前を宛先の名前に（`Notifier.php`） | Tests: 953, Assertions: 7415, Failures: 2. | `test_a_new_head_gets_one_notice_per_waiting_request`、`test_the_handler_gets_a_notice_and_a_mail` | 検出 |
| N13 | メールの件名の行をエスケープ（&amp; が出る）（`approval-notice.blade.php`） | Tests: 953, Assertions: 7411, Failures: 1. | `test_the_mail_says_only_what_the_requirements_allow` | 検出 |
| N14 | 差出人の名前を全体の設定のまま（`ApprovalNoticeMail.php`） | Tests: 953, Assertions: 7420, Failures: 1. | `test_the_handler_gets_a_notice_and_a_mail` | 検出 |
| N15 | 送り直しを 1 回に（`ApprovalMailable.php`） | Tests: 953, Assertions: 7419, Failures: 2. | `test_it_is_tried_three_times_a_minute_apart`、`test_the_mail_is_tried_three_times_a_minute_apart` | 検出 |
| N16 | 送り直しの間をあけない（`ApprovalMailable.php`） | Tests: 953, Assertions: 7421, Failures: 2. | `test_it_is_tried_three_times_a_minute_apart`、`test_the_mail_is_tried_three_times_a_minute_apart` | 検出 |
| N17 | 送れた記録を残さない（`ApprovalMailable.php`） | Tests: 953, Assertions: 7416, Failures: 2. | `test_a_sent_and_a_failed_mail_are_recorded`、`test_the_password_mail_records_success_and_failure` | 検出 |
| N18 | 送れなかった記録を残さない（`ApprovalMailable.php`） | Tests: 953, Assertions: 7418, Failures: 2. | `test_a_sent_and_a_failed_mail_are_recorded`、`test_the_password_mail_records_success_and_failure` | 検出 |
| N19 | 送れなかった記録で設定の updated_at を動かす（`MailDelivery.php`） | Tests: 953, Assertions: 7421, Failures: 1. | `test_a_sent_and_a_failed_mail_are_recorded` | 検出 |
| N20 | 場面 4 の見出しを変える（`NoticeText.php`） | Tests: 953, Assertions: 7419, Failures: 2. | `test_an_approval_reaches_the_applicant_and_the_people_who_judged`、`test_one_person_gets_one_notice_for_a_request_in_one_operation` | 検出 |
| N21 | 部門長の必要な対応を変える（`NoticeText.php`） | Tests: 953, Assertions: 7417, Failures: 3. | `test_the_handler_gets_a_notice_and_a_mail`、`test_undoing_a_return_after_a_head_change_reaches_both_heads`、`test_undoing_the_head_approval_gives_the_head_the_turn_back` | 検出 |
| W01 | 提出で知らせない（`Workflow.php`） | Tests: 953, Assertions: 7412, Errors: 1, Failures: 4. | `test_a_return_reaches_the_applicant_and_a_resubmission_reaches_the_head_again`、`test_a_submission_by_the_head_reaches_every_reviewer`、`test_a_submission_reaches_the_head_only`、`test_opening_a_notice_marks_it_read_and_goes_to_the_request`、`test_opening_the_request_marks_only_my_notices_of_that_request_read` | 検出 |
| W02 | 部門長の承認で知らせない（`Workflow.php`） | Tests: 953, Assertions: 7415, Errors: 2, Failures: 1. | `test_a_notice_of_a_request_that_can_no_longer_be_seen_goes_back_to_the_list`、`test_each_judgement_reaches_the_next_handlers`、`test_the_mail_link_keeps_the_front_controller_of_the_request` | 検出 |
| W03 | 部門長の差戻しで知らせない（`Workflow.php`） | Tests: 953, Assertions: 7418, Failures: 1. | `test_a_return_reaches_the_applicant_and_a_resubmission_reaches_the_head_again` | 検出 |
| W04 | 審査の意見で知らせない（`Workflow.php`） | Tests: 953, Assertions: 7421, Failures: 1. | `test_each_judgement_reaches_the_next_handlers` | 検出 |
| W05 | 社長の差戻しで知らせない（`Workflow.php`） | Tests: 953, Assertions: 7421, Failures: 1. | `test_a_return_reaches_the_applicant_and_a_resubmission_reaches_the_head_again` | 検出 |
| W06 | 社長の判断で知らせない（`Workflow.php`） | Tests: 953, Assertions: 7420, Failures: 4. | `test_a_conditional_approval_asks_the_applicant_to_confirm`、`test_a_rejection_reaches_the_same_people`、`test_an_approval_reaches_the_applicant_and_the_people_who_judged`、`test_the_head_who_judged_gets_the_decision_after_a_head_change` | 検出 |
| W07 | 条件の確認で知らせない（`Workflow.php`） | Tests: 953, Assertions: 7420, Failures: 1. | `test_a_confirmed_condition_is_a_notice_only_for_the_president_and_the_head` | 検出 |
| W08 | 取り下げの担当を打ち切ったあとに取る（`Workflow.php`） | Tests: 953, Assertions: 7421, Failures: 1. | `test_a_withdrawal_reaches_the_handlers_at_that_time` | 検出 |
| W09 | 代理の取り下げで申請者に知らせない（`Workflow.php`） | Tests: 953, Assertions: 7420, Failures: 1. | `test_a_withdrawal_by_the_admin_also_reaches_the_applicant` | 検出 |
| W10 | 取り消しで知らせない（`Workflow.php`） | Tests: 953, Assertions: 7415, Failures: 4. | `test_undoing_a_decision_does_not_reach_the_head_and_the_reviewer`、`test_undoing_a_return_after_a_head_change_reaches_both_heads`、`test_undoing_the_condition_confirmation_asks_the_applicant_once_again`、`test_undoing_the_head_approval_gives_the_head_the_turn_back` | 検出 |
| W11 | 付け替えで知らせない（`Workflow.php`） | Tests: 953, Assertions: 7420, Failures: 1. | `test_a_reassignment_reaches_the_new_assignee_only` | 検出 |
| W12 | 場面 4: 部門長として判断した人に出さない（`Notifier.php`） | Tests: 953, Assertions: 7420, Failures: 4. | `test_a_conditional_approval_asks_the_applicant_to_confirm`、`test_a_rejection_reaches_the_same_people`、`test_an_approval_reaches_the_applicant_and_the_people_who_judged`、`test_the_head_who_judged_gets_the_decision_after_a_head_change` | 検出 |
| W13 | 場面 6: 部門長に出さない（`Notifier.php`） | Tests: 953, Assertions: 7420, Failures: 1. | `test_a_confirmed_condition_is_a_notice_only_for_the_president_and_the_head` | 検出 |
| W14 | 場面 8: 条件の確認の取り消しで番が戻ったことを出さない（`Notifier.php`） | Tests: 953, Assertions: 7421, Failures: 1. | `test_undoing_the_condition_confirmation_asks_the_applicant_once_again` | 検出 |
| H01 | 部門長の交代で知らせない（`Workflow.php`） | Tests: 953, Assertions: 7416, Failures: 1. | `test_a_new_head_gets_one_notice_per_waiting_request` | 検出 |
| H02 | 部門長の交代で前の部門長に知らせる（`Workflow.php`） | Tests: 953, Assertions: 7416, Failures: 2. | `test_a_new_head_gets_one_notice_per_waiting_request`、`test_the_admin_who_became_the_head_gets_nothing` | 検出 |
| H03 | 審査担当者の追加で前からいる人にも知らせる（`OrganizationController.php`） | Tests: 953, Assertions: 7416, Failures: 1. | `test_an_added_reviewer_gets_one_notice_per_waiting_review` | 検出 |
| H04 | 審査担当者の追加でまだ届いていない審査にも知らせる（`Notifier.php`） | Tests: 953, Assertions: 7414, Failures: 1. | `test_an_added_reviewer_gets_one_notice_per_waiting_review` | 検出 |
| H05 | 社長の指定をトランザクションで囲まない（`UserController.php`） | Tests: 953, Assertions: 7420, Failures: 1. | `test_the_president_designation_rolls_back_when_the_notice_fails` | 検出 |
| H06 | 同じ社長を選び直しても知らせる（`UserController.php`） | Tests: 953, Assertions: 7421, Failures: 1. | `test_a_new_president_gets_one_notice_per_waiting_request` | 検出 |
| V01 | D13: 詳細を開いても既読にしない（`RequestController.php`） | Tests: 953, Assertions: 7419, Failures: 1. | `test_opening_the_request_marks_only_my_notices_of_that_request_read` | 検出 |
| V02 | D13: ほかの申請の未読も既読に（`ApprovalNotice.php`） | Tests: 953, Assertions: 7420, Failures: 1. | `test_opening_the_request_marks_only_my_notices_of_that_request_read` | 検出 |
| V03 | D13: ほかの人の未読も既読に（`ApprovalNotice.php`） | Tests: 953, Assertions: 7420, Failures: 2. | `test_marking_all_read_touches_only_my_notices`、`test_opening_the_request_marks_only_my_notices_of_that_request_read` | 検出 |
| V04 | ほかの人のお知らせも開ける（`NoticeController.php`） | Tests: 953, Assertions: 7418, Failures: 1. | `test_opening_a_notice_marks_it_read_and_goes_to_the_request` | 検出 |
| V05 | 見られなくなった申請も詳細へ（404）（`NoticeController.php`） | Tests: 953, Assertions: 7419, Failures: 1. | `test_a_notice_of_a_request_that_can_no_longer_be_seen_goes_back_to_the_list` | 検出 |
| V06 | お知らせを押しても既読にしない（`NoticeController.php`） | Tests: 953, Assertions: 7417, Failures: 2. | `test_a_notice_of_a_request_that_can_no_longer_be_seen_goes_back_to_the_list`、`test_opening_a_notice_marks_it_read_and_goes_to_the_request` | 検出 |
| V07 | ⑥ を古い順に（`NoticeController.php`） | Tests: 953, Assertions: 7407, Failures: 2. | `test_the_list_pages_by_twenty`、`test_the_list_shows_my_notices_newest_first` | 検出 |
| V08 | ⑥ を 21 件ずつ（`NoticeController.php`） | Tests: 953, Assertions: 7413, Failures: 1. | `test_the_list_pages_by_twenty` | 検出 |
| V09 | ベルを使い始める前も出す（`ApprovalMenu.php`） | Tests: 953, Assertions: 7419, Failures: 1. | `test_there_is_no_bell_before_launch` | 検出 |
| V10 | ベルの 99+ の境目を変える（`notice-bell.blade.php`） | Tests: 953, Assertions: 7420, Failures: 1. | `test_the_bell_shows_the_unread_count` | 検出 |
| V11 | お知らせの件名をエスケープしない（`_item.blade.php`） | Tests: 953, Assertions: 7415, Failures: 1. | `test_the_notice_text_is_escaped` | 検出 |
| V12 | ホームのお知らせを 6 件に（`HomeController.php`） | Tests: 953, Assertions: 7419, Failures: 1. | `test_the_home_shows_the_five_newest_unread_notices` | 検出 |
| V13 | ホームに既読も出す（`HomeController.php`） | Tests: 953, Assertions: 7418, Failures: 1. | `test_the_home_shows_the_five_newest_unread_notices` | 検出 |
| V14 | 未読が無くても「すべて既読にする」を出す（`index.blade.php`） | Tests: 953, Assertions: 7421, Failures: 1. | `test_marking_all_read_touches_only_my_notices` | 検出 |
| M01 | 送れたあとも帯を出す（`MailDelivery.php`） | Tests: 953, Assertions: 7421, Failures: 2. | `test_a_later_success_clears_the_banner`、`test_the_password_mail_records_success_and_failure` | 検出 |
| M02 | 管理者でない人にも帯（`_mail_failure.blade.php`） | Tests: 953, Assertions: 7421, Failures: 1. | `test_the_banner_is_only_for_approval_admins` | 検出 |
| M03 | 帯の宛先をアドレスに（`PasswordReissuedMail.php`） | Tests: 953, Assertions: 7420, Failures: 1. | `test_the_password_mail_records_success_and_failure` | 検出 |
| M04 | 申請種類の管理に帯を出さない（`types.blade.php`） | Tests: 953, Assertions: 7417, Failures: 1. | `test_a_failure_shows_the_banner_on_the_admin_pages` | 検出 |
| M05 | 送れなかった日時を日時として読まない（`ApprovalSetting.php`） | Tests: 953, Assertions: 7408, Failures: 3. | `test_a_failure_shows_the_banner_on_the_admin_pages`、`test_a_later_success_clears_the_banner`、`test_the_password_mail_records_success_and_failure` | 検出 |

- [ ] **Step 3: 表と違ったものを調べ、検出できなかった変異にテストを足す**

⚠ **等価**（緑が正しい）と書いたものは、なぜ等価かを 1 行で確かめる（読み違えて「守られていない」としない）。等価でないのに緑のものは、その Task のテストに 1 本足し、足したテストが変異で赤・元に戻して緑になることを確かめてからコミットする。

- [ ] **Step 4: 結果をこの計画に追記してコミット**

検出／当初検出漏れ→追加で検出／等価を区別して、この計画の末尾に「Task 9 の実測記録」として書き足す。

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add docs/superpowers/plans/2026-09-30-approval-phase3a.md tests/
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
test(approval): 段階3a の変異テストの結果を記録する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

写し（`<scratchpad>/p3a-mutation`）は、この Task が済んだら消してよい（`rm -rf` の前に中身が写しであることを確かめる）。


---

## Task 10: 手元のブラウザでの確認と、利用者に見せる画面の写真（設計書 §6）

**Files:** なし（測るだけ）

テストが原理的に測れない領域（見た目・スマホの幅・ベルの位置・帯の折り返し）を見る。**本番では使い始めるまで 3a の画面を誰にも見せないので、利用者に見てもらうのはここで撮る写真**。WT の HEAD の写し（scratchpad）＋使い捨ての SQLite ＋ `artisan serve`。ブラウザは Playwright（利用者の決まり）。`<scratchpad>` は Task 9 と同じ（その会話の scratchpad のパス）。⚠ Playwright がファイルを扱えるのは会話の作業フォルダの下だけ（2a Task 19 の注意）。

- [ ] **Step 1: 写しと使い捨ての環境を作る**

⚠ Bash の呼び出しごとにシェルが新しくなるので、使い捨ての設定は 1 つのファイルにまとめ、以降のコマンドの先頭で `source` する。⚠ WT にも写しにも `.env` を作らない。⚠ `APP_LOCALE=ja`・`APP_FALLBACK_LOCALE=ja` を入れる（無いと検査の文が英語になる）。⚠ メールは `log`（`storage/logs/laravel.log` に書くだけ・外へ送らない）、キューは `sync`（積んだメールをその場で書く）。⚠ 試しのパスワードの値はチャット・報告・写真に出さない。

```bash
SCR=<scratchpad>/p3a-browser && mkdir -p "$SCR" && git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 archive HEAD | tar -x -C "$SCR" && cp -Rc /Users/masanori/site/manage/.claude/worktrees/approval-phase3/vendor "$SCR/vendor"
LOCAL=<scratchpad>/approval-phase3a-local.sh
cat > "$LOCAL" <<SH
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
export APP_LOCALE=ja
export APP_FALLBACK_LOCALE=ja
export APP_URL=http://127.0.0.1:8767
export DB_CONNECTION=sqlite
export DB_DATABASE="<scratchpad>/approval-phase3a.sqlite"
export MAIL_MAILER=log
export QUEUE_CONNECTION=sync
export TRIAL_PASSWORD="$(php -r 'echo bin2hex(random_bytes(6));')"
SH
source "$LOCAL" && touch "$DB_DATABASE" && cd "$SCR" && php artisan migrate --force && ln -s /Users/masanori/site/manage/node_modules node_modules && ./node_modules/.bin/vite build
```

⚠ 3a は新しい CSS のクラス（ベルの丸・帯の色など）を足したので、見る前に `vite build` が要る（写しに main repo の `node_modules` のシンボリックリンクを置く）。

- [ ] **Step 2: 試しのデータを入れて、画面を出す**

テストの土台（`BuildsApprovalFixtures`）をそのまま使う（写しの vendor は dev 依存ありなので `Tests\` を読める）。**使い始めてから** Workflow で申請を動かすので、お知らせとメールが本物の流れで作られる。⚠ ログイン用の使い捨てルートを作らない。

```bash
source <scratchpad>/approval-phase3a-local.sh && cd <scratchpad>/p3a-browser && php artisan tinker --execute='
use App\Enums\ApprovalStepResult as R;
use App\Models\ApprovalNotice;
use App\Support\Approval\Notifier;
use Illuminate\Support\Str;
$f = new class {
    use Tests\Concerns\BuildsApprovalFixtures { approvalWorld as public; submittedFor as public; approvalAdmin as public; baseUser as public; launchApprovals as public; }
    use Tests\Concerns\CreatesMansionSchema { createMansionSchema as public; }
    use Tests\Concerns\CreatesRealEstateSchema { createRealEstateSchema as public; }
};
$f->createMansionSchema(); $f->createRealEstateSchema();
$wf = app(App\Support\Approval\Workflow::class);
$w = $f->approvalWorld();
$admin = $f->approvalAdmin(["name" => "決裁 管理者"]);
$w["president"]->forceFill(["role" => "executive"])->save();
App\Models\ApprovalMailDomain::create(["domain" => substr(strrchr($w["head"]->email, "@"), 1)]);
$f->launchApprovals();
for ($i = 1; $i <= 22; $i++) { $f->submittedFor($w, ["subject" => sprintf("備品の購入 %02d", $i)]); }
$amp = $f->submittedFor($w, ["subject" => "A&B社の<見積り>\"比較\""]);
$done = $f->submittedFor($w, ["subject" => "展示会の出展"]);
$wf->judgeHead($done, $w["head"], $done->lock_version, R::Approve, null);
$wf->judgeReview($done->refresh(), $w["reviewer"], $done->lock_version, R::Ok, null);
$wf->judgePresident($done->refresh(), $w["president"], $done->lock_version, R::Conditional, "見積りを 2 社から取ること");
$ret = $f->submittedFor($w, ["subject" => "倉庫の賃借"]);
$wf->judgeHead($ret, $w["head"], $ret->lock_version, R::Return, "契約期間を書いてください");
$wait = $f->submittedFor($w, ["subject" => "社用車の購入（審査待ち）"]);
$wf->judgeHead($wait, $w["head"], $wait->lock_version, R::Approve, null);
$extra = $f->baseUser(["name" => "追加 審査"]);
DB::transaction(function () use ($w, $admin, $extra) {
    $w["reviewDept"]->reviewers()->attach($extra->id);
    Notifier::reviewersAdded($admin, $w["reviewDept"], collect([$extra]));
});
$w["reviewDept"]->reviewers()->detach($extra->id);
$many = $f->baseUser(["name" => "多数 未読"]);
for ($i = 0; $i < 120; $i++) {
    ApprovalNotice::create(["id" => (string) Str::orderedUuid(), "type" => ApprovalNotice::TYPE, "notifiable_type" => $many->getMorphClass(), "notifiable_id" => $many->id, "approval_request_id" => $amp->id,
        "data" => ["scene" => "turn", "headline" => "部門長の確認のお願い", "subject" => "未読の多い人の確かめ", "number" => null, "applicant" => "申請 花子", "department" => "住宅事業部", "action" => "部門長の確認（承認か差戻し）", "actor" => "申請 花子"]]);
}
App\Support\Approval\MailDelivery::recordFailed("山田 花子");
DB::table("users")->update(["password" => Hash::make(getenv("TRIAL_PASSWORD")), "must_change_password" => false]);
foreach (["applicant", "head", "reviewer", "president"] as $k) { echo $k, " ", $w[$k]->email ?? $w[$k]->employee_number, " unread=", ApprovalNotice::ownedBy($w[$k])->unread()->count(), PHP_EOL; }
echo "admin ", $admin->email, PHP_EOL, "extra ", $extra->email, " unread=", ApprovalNotice::ownedBy($extra)->unread()->count(), PHP_EOL, "many ", $many->email, PHP_EOL;
echo "banner=", json_encode(App\Support\Approval\MailDelivery::pendingFailure() !== null), " done_id=", $done->id, PHP_EOL;
'
```

Expected（2026-10-01 に試作で実測）: `applicant … unread=2`・`head … unread=27`・`reviewer … unread=3`・`president … unread=1`・`extra … unread=1`・`banner=true`・`done_id=`（「展示会の出展」の申請の番号）と、ログイン名（メールアドレスか社員番号）。パスワードは `source` したシェルの `$TRIAL_PASSWORD`（値を出さない）。「追加 審査」は、審査を待っている申請の知らせを受け取ったあと審査担当者から外れた人（見られなくなった申請の確かめ用。§0.11 の 4）。「多数 未読」は未読 120 件（ベルの「99+」の確かめ用。お知らせだけを直接作った）。

画面を出す（**バックグラウンドで**。止めるまで動き続ける）:

```bash
source <scratchpad>/approval-phase3a-local.sh && cd <scratchpad>/p3a-browser && php artisan serve --port=8767
```

- [ ] **Step 3: 見ること**（1440px と 375px の両方）

| # | 画面（ログインする人） | 見ること |
|---|---|---|
| 1 | ベル（部門長） | ヘッダーの右（利用者のメニューの左）に赤い丸「27」・読み上げ（`aria-label`）が「お知らせ（未読 27 件）」・押すと ⑥ ・375px でもヘッダーに収まり、メニューのボタンと重ならない |
| 2 | ベル（多数 未読） | 丸が「99+」 |
| 3 | ⑥（部門長） | 新しい順・未読は太字と赤い「未読」・1 行目「{見出し}：{件名}」と日時（日本時間）・2 行目「申請 花子（住宅事業部）・申請 花子さんの操作・部門長の確認（承認か差戻し）」・`A&B社の<見積り>"比較"` が**打ったとおりの文字**で出る・20 件で 2 ページ目へ（ページ送りは ⑩ と同じ形）・375px は 1 件 1 枚 |
| 4 | 1 件を押す（部門長） | 申請の詳細が開く・⑥ に戻るとその 1 件が既読（太字でない・印が無い）・ベルの数が 1 減る |
| 5 | 詳細を開くだけ（部門長・`/approvals/requests/{done_id}` を URL で開く） | お知らせを押さなくても、その申請の部門長の「決裁されました（条可）」が既読になる（ベルの数がその申請の自分の未読の分だけ減る。試しのデータでは 2）。審査担当者と申請者でログインすると、同じ申請の「決裁されました（条可）」「条件の確認のお願い（条可）」は未読のまま（開いた本人の分だけ。D13） |
| 6 | すべて既読にする（部門長） | 「お知らせをすべて既読にしました。」・ボタンが消える・ベルの丸が消える（ベルは残る） |
| 7 | 見られなくなった申請（追加 審査） | ⑥ の 1 件を押すと ⑥ に戻り、赤の帯「この申請は、今は見られません。」・その 1 件は既読になる |
| 8 | ホーム（申請者） | 「新しいお知らせ」に「条件の確認のお願い（条可）：展示会の出展」「差戻しされました：倉庫の賃借」・「お知らせをすべて見る」で ⑥。新しいお知らせが無い人（決裁 管理者）は「新しいお知らせはありません。」 |
| 9 | D2 の帯（決裁 管理者） | ホーム・利用者の管理・部門の管理・申請種類の管理・進行中の申請の 5 画面の上に黄色の帯「通知メールが送れていません。最後に送れなかったのは {日時}（宛先: 山田 花子さん）です。…」・375px で折り返して読める・部門長でログインすると出ない。続けて tinker で `App\Support\Approval\MailDelivery::recordSent();` を流すと帯が消える |
| 10 | 使い始める前（tinker で `launched_at` を空に戻し、`MailDelivery::recordFailed("山田 花子")` を流し直す） | 基幹の画面にベルが出ない・`/approvals/notices` はホーム（準備中）へ・準備中のホームに D2 の帯（決裁 管理者だけ）。確かめたら `launched_at` を入れ直す |
| 11 | 全画面 | `main.scrollWidth === main.clientWidth` を 1800 / 1200 / 375px で（Bug #29）・コンソールのエラーと警告が 0 件 |
| 12 | メールの文面（記録から 1 通読む。下のコマンド） | 差出人の名前「ミツワ都市開発 決裁システム」・件名「【決裁】部門長の確認のお願い：A&B社の<見積り>"比較"」（`&amp;` にならない）・宛名・前置き・件名・申請者（申請部門）・決裁No「まだありません」・必要な対応・リンク（`http://127.0.0.1:8767/approvals/requests/…`）・金額や本文が無い |

メールの文面を読むコマンド（記録の件名と本文は MIME の形で書かれているので、文字に戻す）:

```bash
cd <scratchpad>/p3a-browser && php -r '
$entries = preg_split("/^\[\d{4}-\d\d-\d\d [\d:]+\] \w+\.\w+: /m", file_get_contents("storage/logs/laravel.log"), -1, PREG_SPLIT_NO_EMPTY);
foreach ($entries as $e) {
    [$head, $body] = array_pad(preg_split("/\r?\n\r?\n/", $e, 2), 2, "");
    $h = iconv_mime_decode_headers($head, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, "UTF-8");
    if (! isset($h["Subject"]) || ! str_contains($h["Subject"], "A&B")) continue;
    echo "From: ", $h["From"], PHP_EOL, "To: ", $h["To"], PHP_EOL, "Subject: ", $h["Subject"], PHP_EOL, PHP_EOL, quoted_printable_decode($body), PHP_EOL;
    break;
}'
```

- [ ] **Step 4: 利用者に見せる写真を撮る**

375px と 1440px で、ベル（未読あり・99+）・⑥（未読と既読が混ざった 1 ページ目・ページ送り）・⑥ の「この申請は、今は見られません。」・ホームの「新しいお知らせ」・D2 の帯（決裁の管理者のホームと管理の画面 1 つ）を撮り、Step 3 の 12 のメールの文面と一緒に scratchpad に保存して利用者に送る（`SendUserFile`。まとめのページを作るなら 2b の写真のページと同じ形）。写真には試しのデータしか写らないことを確かめる。

- [ ] **Step 5: コンパイル済みビューを lint する**

⚠ `view:cache` の成功表示だけでは足りない（Bug #21 / #26 / #30）。

```bash
source <scratchpad>/approval-phase3a-local.sh && cd <scratchpad>/p3a-browser && php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done; php artisan view:clear
```

Expected: INVALID 0 件。

- [ ] **Step 6: 片付けて結果を記録する**

`artisan serve` を止めたことを確かめてから、写しと使い捨てのファイルを消す（`rm -rf` の前に、消すのが scratchpad の写しであることを `pwd` と `ls` で確かめる）。WT の `git status --porcelain` が空であることも確かめる。見たことと見つけた不具合を、この計画の末尾に「Task 10 の実測記録」として書き足してコミットする（不具合は直してから。直すときは Task 1〜8 のテストに再現を足し、「テストを先に入れると落ち、直しを入れると緑」を確かめる）。


---

## Task 11: ドキュメント

**Files:**
- Modify: `docs/superpowers/specs/2026-09-30-approval-phase3-design.md`（計画で決めた細部の書き戻し）
- Modify: `CLAUDE.md`（Completed modules の決裁の行）・`docs/ARCHITECTURE.md`（ルートの本数・表・門番）・`routes/web.php`（`require …approval.php` の真上の見出しの本数）・`docs/BACKLOG.md`（段階3 の節）・`docs/運用_バックアップとメール.md`（決裁の通知メールの送り直しと帯）

⚠ 要件定義書は変えない（15.5 は「詳しい列は実装計画で決める」。`notifications` に足した `approval_request_id` はこの計画 §0.2 が記録）。

- [ ] **Step 1: 設計書に書き戻す**

- 冒頭の「実装計画:」の行を置き換える: `実装計画: 3a は @docs/superpowers/plans/2026-09-30-approval-phase3a.md（この設計書から変えた細部は §0.10、受け入れた隙間は §0.11）。3b はまだ無い。計画を書くときは §4（調べて分かった事実）と §9（計画で決めること）を先に読む`
- §5.1 の「**部品の置き場所**（名前は仮。実装計画で決める）」の表の後ろに足す: `- 3a の計画で決めた名前（計画 §1）: Notifier・StepHandlers・NoticeText・Notice（知らせの中身）・ApprovalMailable（メールの土台）・ApprovalNoticeMail・MailDelivery（送れた・送れなかったの記録と帯の判定）・ApprovalNotice（お知らせのモデル）・ApprovalMenu::unreadNotices()（ベルの数）・PageNumbers と approvals._pager（⑩ から寄せたページ送り）`
- §5.3 の表の後の箇条書きの最後に足す: `- 3a の計画で決めた細部（計画 §0.2）: notifications に approval_request_id（申請への外部キー・NULL 可・RESTRICT・索引）を足し、「その申請の未読」を data の JSON の中から探さずに引く（MySQL と SQLite で JSON の取り出し方と照合順序が違うため）。索引は (notifiable_type, notifiable_id, read_at) と (approval_request_id)。id は Str::orderedUuid()・type は approval。approval_settings の 3 列は mail_last_sent_at・mail_last_failed_at・mail_last_failed_to（氏名）`
- §5.5 の最後に足す: `- 3a の計画で決めた細部（計画 §0.3・§0.5）: Laravel の通知のクラスは使わず、お知らせの行と自前のメールを Notifier が直接作る（再発行のメールと同じ形・同じトランザクション）。メールの「送れた」はメールのイベントでなく、土台 ApprovalMailable の send() で拾う（決裁でないメールを数えない）。「必要な対応」の対応が要らない知らせは、すべて「対応は要りません（お知らせです）」にそろえた。場面 8 で番が戻った人の文は「決裁の管理者が「…」を取り消しました。」＋「次の申請が、もう一度あなたの{確認}の番になりました。」`
- §5.6 の最後に足す: `- 3a の計画で決めた細部（計画 §0.6）: ⑥ のページ送りは ⑩ の番号の出し方と HTML を部品（PageNumbers・approvals._pager）にして両方で使う。ベルの部品は layouts/partials/notice-bell.blade.php（名前を sidebar で始めない）。見られなくなった申請のお知らせは、押すと既読にして ⑥ に戻し「この申請は、今は見られません。」`
- §9 の行の最後に、どこで決めたかを足す（項目の頭の言葉 → 足す文）:

| §9 の項目 | 足す文 |
|---|---|
| お知らせを Laravel の通知のクラス… | `→ 3a の計画 §0.3 で決めた（直接作る）` |
| `notifications` の索引… | `→ 3a の計画 §0.2 で決めた（approval_request_id の列）` |
| メールが「送れた」ことの拾い方… | `→ 3a の計画 §0.3 で決めた（ApprovalMailable の send()）` |
| `StepHandlers` の置き場所… | `→ 3a の計画 §0.4 で決めた` |
| 各 Workflow のメソッドのどこで Notifier を呼ぶか… | `→ 3a の計画 §0.4 と Task 5・6 で決めた` |
| お知らせの文言の全部… | `→ 3a の計画 §0.5 で決めた` |
| 走査テストへの登録… | `→ 3a の分は計画 §0.8 と Task 9 で決めた` |

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add docs/superpowers/specs/2026-09-30-approval-phase3-design.md
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
docs(approval): 設計書に 3a の計画で決めた細部を書き戻す

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

- [ ] **Step 2: 画面・ルート・表・運用を書き換える**

`CLAUDE.md` の Completed modules の表の決裁の行を置き換える:

```markdown
| 決裁申請 段階1・2a・2b・3a | `/approvals/*` | `Approval\*Controller`（門番 3 本・CSV 一括登録・ログイン案内・申請の回覧と進行中の申請の管理・お知らせ〈ベル・⑥〉と通知メール。状態を変えるのは `App\Support\Approval\Workflow` だけ・知らせを出すのは `App\Support\Approval\Notifier` だけ）|
```

`docs/ARCHITECTURE.md`

- `routes/approval.php` の行: `# 決裁申請 段階1・2a・2b・3a (46 ルート。管理系は approval.admin、申請を回す画面とお知らせは approval.launched、進行中の申請の管理は両方)`
- 表の一覧の `approval_settings` の行: `| `approval_settings` | 決裁: 社長の指定（1 行）・使い始めた日時（`launched_at`。空のあいだは準備中）・決裁のメールが最後に送れた／送れなかった日時と宛先（`mail_last_*`。書くのは `MailDelivery` だけ）|`
- 表の一覧の `approval_number_sequences` の行の後に足す: `| `notifications` | 決裁: お知らせ（Laravel 標準の形 ＋ `approval_request_id`。1 人 1 行・消さない。作るのは `Notifier` だけ・引くときは `ApprovalNotice::ownedBy()`）|`
- 決裁（段階2）の行の後に足す: `- 決裁（段階3）: 知らせを出すのは `App\Support\Approval\Notifier` だけで、呼ぶ側（`Workflow`・部門の管理・社長の指定）のトランザクションの中で呼ぶ（お知らせの行もメールの `jobs` の行も操作と一緒に巻き戻る。`QUEUE_CONNECTION=database` が前提）。宛先・文・リンクは操作の時点で決めてメールに持たせる（キューの中で `route()` を呼ばない）。メールは土台 `App\Mail\ApprovalMailable`（送り直し 3 回・送れた／送れなかったの記録）の上に作る。お知らせ一覧（⑥ `approvals.notices.*`）は `approval.launched` の内側`

`routes/web.php` の `require __DIR__ . '/approval.php';` の真上の見出しの「決裁申請（43ルート）」を「決裁申請（46ルート）」にする。

`docs/BACKLOG.md` の「🚧 決裁申請 段階2（決裁の本体）」の節の後（次の「## ✅ CSV 取込の確認画面から…」の前）に足す:

```markdown
## 🚧 決裁申請 段階3（通知）— 3a 実装済み・本番反映の前

要件定義書: @docs/決裁申請_要件定義書_v1.md（v1.11。8 章・13 章の ⑥⑫・15.3・15.5）
設計書: @docs/superpowers/specs/2026-09-30-approval-phase3-design.md（設計の 5 節は 2026-09-30 に利用者が 1 節ずつ承認）

- 作り方: 2 回に分ける（**3a** 操作のたびのお知らせとメール・ベル・お知らせ一覧・ホームのお知らせ・送れないときの表示 ／ **3b** 毎朝の催促・祝日・催促の設定 ⑫）。
  どちらも使い始めるまで何も見えず何も届かない（`approval_settings.launched_at` が空のあいだ）

### 3a（操作のたびのお知らせとメール・ベル・⑥・ホームのお知らせ・送れないときの帯）

実装計画: @docs/superpowers/plans/2026-09-30-approval-phase3a.md（Task 0〜12）。worktree `.claude/worktrees/approval-phase3`（ブランチ `approval-phase3`）。

- 表: `notifications`（新）と `approval_settings` の 3 列。本番反映は **DB が先・`./deploy.sh` が後**（新しいコードが `approval_settings.mail_last_*` を読む）
- 計画で決めた細部（計画 §0.10）: お知らせの表に申請の番号の列（`approval_request_id`）を足した・対応の要らない知らせの文を「対応は要りません（お知らせです）」にそろえた・⑥ のページ送りは ⑩ と同じ部品・取り消しで番が戻った人への文
- 既存のテストで意味が変わったもの 2 本（計画 §0.9）: 部門長のホームの見る範囲（「新しいお知らせ」に提出のときの知らせが残る）・再発行のメールの送り直し（1 回 → 3 回。D6）
- 受け入れた隙間（計画 §0.11）: 社長の交代・審査担当者の追加は待っている申請の版を進めない・外れた審査担当者の未読は残る（押すと ⑥ に戻して知らせる）ほか
```

`docs/運用_バックアップとメール.md`

- 「1. この仕組みでできること」の表の「5 分おき」の行の右の列を置き換える: `送信待ちのメールを送る（決裁の通知メール・パスワード再発行のメール。送れなければ間をあけて 3 回まで送り直す）`
- 「4. ふだんの見方」の最後に足す: `- **決裁の通知メールが 3 回送り直しても送れなかったときは、決裁の管理者のホームと管理の画面に黄色の帯「通知メールが送れていません。…」が出ます**（決裁を使い始めてから。画面のお知らせは届いています）。3 の 1 のテストメールで送れるかを確かめ、送れなければメールの設定（2.5。決裁用メールアドレスのパスワードを変えた・消した など）を直す。直ったあと 1 通でも送れると帯は消えます（送れなかったメールは送り直しません）。理由は `laravel.log` の「決裁の通知メールを送れませんでした」の行に出ています`

- [ ] **Step 3: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add CLAUDE.md docs/ARCHITECTURE.md routes/web.php docs/BACKLOG.md docs/運用_バックアップとメール.md
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
docs: 決裁 段階3a のお知らせ・ルート・表・運用をドキュメントに反映する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

- [ ] **Step 4: `13.x` と衝突しないことを確かめる**（`docs/BACKLOG.md`・`CLAUDE.md` は別の会話も書き足す。設計書 §7 の 6）

```bash
git -C /Users/masanori/site/manage merge-tree --write-tree approval-phase3 13.x >/dev/null && echo "衝突なし" || echo "衝突あり"
```

Expected: `衝突なし`。「衝突あり」なら、どのファイルかを `git -C /Users/masanori/site/manage merge-tree --write-tree --name-only approval-phase3 13.x` で見て、本番反映（Task 12 の Step 3）の前に利用者に伝える。


---

## Task 12: 本番反映（利用者の了承を取ってから・親の会話が行う）

> ⚠ **サブエージェントに任せない。** 本番への `ssh`・`scp`・`./deploy.sh` は、それぞれ利用者の了承を取ってから行う。本番のファイルの削除は利用者が行う（実行する 1 行を渡す）。`.env` は読まない。
> ⚠ 本番のシェルは csh なので、`/bin/sh` の heredoc を `ssh` に流す（段階1・2 と同じ作法）。PHP は `/usr/local/php/8.3/bin/php` を明示する（既定の `php` は 7.4）。

- [ ] **Step 1: 了承を求める（選択式）**

伝えること: ①**DB が先・`./deploy.sh` が後**（新しいコードが `approval_settings` の新しい列を読むので、逆だと決裁の管理の画面が 500 になる）②本番に表が 1 つ（お知らせ）増え、設定の表に列が 3 つ増える（データは変えない）③**使い始める前なので、お知らせも決裁の通知メールも作られず、ベルも出ない**（`launched_at` が空のあいだ。画面の見た目は変わらない）④今から変わるのは、パスワード再発行のメールだけ: 送り直しが 1 回から 3 回になり、3 回とも送れなかったときは決裁の管理者のホームと管理の画面に黄色の帯「通知メールが送れていません。…」が出る（使い始める前でも）⑤戻すときは、`13.x` の前のコミットで `./deploy.sh`（足した表と列は残しても害は無い）。

- [ ] **Step 2: 反映前に本番を読み取る**（読み取りだけ）

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage || exit 1
/usr/local/php/8.3/bin/php artisan route:list --name=approvals. --json | /usr/local/php/8.3/bin/php -r '$r = json_decode(stream_get_contents(STDIN), true); echo "approvals=", count($r), PHP_EOL;'
/usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
$schema = $db->getSchemaBuilder();
echo $db->selectOne("SHOW CREATE TABLE `approval_settings`")->{"Create Table"}, PHP_EOL, PHP_EOL;
echo "notifications=", $schema->hasTable("notifications") ? "ある" : "ない", " jobs=", $schema->hasTable("jobs") ? "ある" : "ない", " failed_jobs=", $schema->hasTable("failed_jobs") ? "ある" : "ない", PHP_EOL;
echo "launched_at=", var_export(App\Models\ApprovalSetting::current()->launched_at, true), PHP_EOL;
echo "queue=", config("queue.default"), " queue_db_connection=", var_export(config("queue.connections.database.connection"), true), PHP_EOL;
foreach (["approval_requests", "approval_steps", "approval_histories", "approval_revisions"] as $t) { echo $t, "=", $db->table($t)->count(), PHP_EOL; }
echo "jobs_waiting=", $db->table("jobs")->count(), " failed_jobs_rows=", $schema->hasTable("failed_jobs") ? $db->table("failed_jobs")->count() : "表が無い", " mysql=", $db->selectOne("SELECT VERSION() AS v")->v, PHP_EOL;
'
ls -la storage/logs/laravel.log
SH
```

Expected: `approvals=43`（2b のまま）／ `approval_settings` に `launched_at` があり `mail_last_sent_at` が**無い**・`ENGINE=InnoDB`・`utf8mb4_unicode_ci` ／ `notifications=ない`・`jobs=ある`・`failed_jobs=ある` ／ `launched_at=NULL` ／ `queue=database`・`queue_db_connection=NULL`（**お知らせとメールが操作と一緒に巻き戻る前提**。§0.3。違えば止まる）／ 4 表とも 0 行（使い始める前）。**1 つでも違えば止まり、利用者に伝える**。

- [ ] **Step 3: `13.x` へ早送りで取り込む**（手元）

```bash
cd /Users/masanori/site/manage && git status --short && git merge-base --is-ancestor 13.x approval-phase3 && echo "FF できる" || echo "13.x が進んでいる"
```

「FF できる」なら:

```bash
git -C /Users/masanori/site/manage merge --ff-only approval-phase3 && git -C /Users/masanori/site/manage log --oneline -3
```

「13.x が進んでいる」なら止まり、取り込み方（WT で `git merge 13.x` をしてから全件を流し直す、など。rebase しない）を利用者に選んでもらう（ほかの会話の作業が入っている）。

- [ ] **Step 4: DB を先に変える**

(a) SQL を本番の置き場所へ送る（`./deploy.sh` もあとで同じ場所へ同じものを送る）:

```bash
scp /Users/masanori/site/manage/database/sql/2026-09-30-approval-phase3a.sql mitsuwa-ud@www3586.sakura.ne.jp:apps/manage/database/sql/
```

(b) 流す前に、文の数と頭を見る（まだ何も変えない）:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$sql = preg_replace("/^--.*$/m", "", file_get_contents(base_path("database/sql/2026-09-30-approval-phase3a.sql")));
$statements = array_values(array_filter(array_map("trim", explode(";", $sql))));
echo "statements=", count($statements), PHP_EOL;
foreach ($statements as $i => $s) { echo $i + 1, ": ", strtok($s, "\n"), PHP_EOL; }
'
SH
```

Expected: `statements=2`（1 が `ALTER TABLE \`approval_settings\``、2 が `CREATE TABLE \`notifications\` (`）。

(c) 1 文ずつ流す（**すでに流した形跡があれば 1 文も流さずに止まる**。`PDO::MYSQL_ATTR_MULTI_STATEMENTS` が未設定なので 1 回に 2 文は流せない）:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
$schema = $db->getSchemaBuilder();
$traces = array_keys(array_filter([
    "notifications"                => $schema->hasTable("notifications"),
    "settings.mail_last_sent_at"   => $schema->hasColumn("approval_settings", "mail_last_sent_at"),
    "settings.mail_last_failed_at" => $schema->hasColumn("approval_settings", "mail_last_failed_at"),
    "settings.mail_last_failed_to" => $schema->hasColumn("approval_settings", "mail_last_failed_to"),
]));
if ($traces !== []) {
    echo "STOP: すでに流した形跡がある: ", implode(", ", $traces), PHP_EOL;
} else {
    $sql = preg_replace("/^--.*$/m", "", file_get_contents(base_path("database/sql/2026-09-30-approval-phase3a.sql")));
    foreach (array_values(array_filter(array_map("trim", explode(";", $sql)))) as $i => $statement) {
        $db->statement($statement);
        echo "OK ", $i + 1, PHP_EOL;
    }
}
'
SH
```

Expected: `OK 1`・`OK 2`。⚠ **途中で止まったら**（MySQL の DDL は 1 文ごとに確定する）、出た `OK` の番号と例外の文言を利用者に伝えて止まる。流し直さない（次の手は相談して決める）。

(d) 流した後を読み取る:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
foreach (["approval_settings", "notifications"] as $t) {
    echo $db->selectOne("SHOW CREATE TABLE `" . $t . "`")->{"Create Table"}, PHP_EOL, PHP_EOL;
}
echo "notifications=", $db->table("notifications")->count(), " settings=", $db->table("approval_settings")->count(), PHP_EOL;
'
SH
```

Expected: `approval_settings` に 3 列（`launched_at` の後・コメントが日本語で読める）／ `notifications` の列・索引 2 つ・外部キー（`approval_requests` へ・`ON DELETE RESTRICT`）・`approval_request_id` のコメントが SQL どおり ／ `notifications=0`・設定の行数は Step 2 と同じ。

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
foreach (["App\\Support\\Approval\\Notifier", "App\\Support\\Approval\\StepHandlers", "App\\Support\\Approval\\NoticeText", "App\\Support\\Approval\\MailDelivery", "App\\Support\\Approval\\PageNumbers", "App\\Mail\\ApprovalNoticeMail", "App\\Models\\ApprovalNotice", "App\\Http\\Controllers\\Approval\\NoticeController"] as $c) {
    echo $c, "=", class_exists($c) ? "ok" : "NG", PHP_EOL;
}
echo "pendingFailure=", var_export(App\Support\Approval\MailDelivery::pendingFailure(), true), " notifications=", app("db")->table("notifications")->count(), PHP_EOL;
'
ls -la storage/logs/laravel.log
SH
curl -s https://www.mitsuwat.co.jp/system/manage/index.php/login | grep -c "社員番号またはメールアドレス"
```

Expected: `invalid=0`・**`approvals=46`**（`--json` で数える。テキストの `grep -c` は長い行を省いて少なく数える）・`launched_at=NULL`・8 つとも `ok`・`pendingFailure=NULL`・`notifications=0`・`laravel.log` の日時が反映の前から変わっていない（反映のあとのエラー 0 件）・ログイン画面の文字が 1 以上。

ログインした画面の確認は、利用者の了承を取ってから、利用者の Chrome（ログイン済み。URL に `index.php/` が要る。**フォームは送らない**）で行う:

| # | 見ること |
|---|---|
| 1 | 基幹の画面のヘッダーに**ベルが無い**（使い始める前） |
| 2 | `/approvals` が「決裁の機能は準備中です。…」のままで、**黄色の帯が無い**（メールの失敗の記録が無い） |
| 3 | `/approvals/notices` を URL で開くと、ホーム（準備中）へ送られる |
| 4 | 決裁の管理者なら、利用者・部門・申請種類の管理が開き、帯が無い |
| 5 | 基幹の画面の `main` のはみ出し 0・コンソールのエラー 0 件 |

- [ ] **Step 8: 記録する**

`docs/BACKLOG.md` の段階3 の節の見出しを「🚧 決裁申請 段階3（通知）— 3a 本番反映済み・使い始める前」にし、3a の節に反映日・`13.x` のコミット・Step 2〜7 で見たことの表を書き足してコミットする（`docs:` 1 本）。

⚠ `origin/13.x` への push は利用者の明示の指示があったときだけ。⚠ worktree `approval-phase3` とブランチの片付けは利用者に聞いてから（3b もこの worktree で作るなら残す。dev の vendor の置き場なので、消すなら次の worktree へ `cp -Rc` してから）。


---

## Task 9 の実測記録（2026-10-01）

実装したコードに、計画の表の 76 通り（カナリア 1 + 変異 75）を `~/.claude/plans/approval-phase3-tasks/mutate.py` で 1 つずつ当てて測った。**WT では当てず、WT の HEAD の写しで当てた**（WT は読むだけ）。

### 測った条件

| 項目 | 内容 |
|---|---|
| 測った HEAD | `70cca1cd`（`Merge branch '13.x' into approval-phase3`）。計画の試作に、Task 6 の手直し `2f272bce`（`OrganizationController::lockWaitingReviews()` と `HandlerChangeNoticeTest` の 1 本）と 13.x の取り込み（`tests/TestCase.php` の `tearDown()` で Carbon の翻訳を片付ける・`CarbonTranslatorTrimTest`・テナントの取込の直し）が足されている |
| 流した範囲 | `mutate.py` の `TARGET`（決裁のテスト〈`tests/Feature/Approval`・`tests/Unit/Approval`〉・`UserManagementApprovalTest`・走査テスト 8 本・`PropertyListSortTest`）= **954 本**（表の 953 本より 1 本多い。下の「表との違い」） |
| 全件（Step 1） | `OK (3211 tests, 22860 assertions)`（`git status --porcelain` は空）。計画の Expected（3204 / 22817）より多いのは、上の足された分のため |
| `--check` | `NG` の行は無く `checked 76`（当てる場所はどの変異もちょうど 1 回ずつ見つかった。直しで文字列が変わった変異は無い） |
| カナリア | 赤になった（`Tests: 954, Assertions: 7299, Errors: 1, Failures: 15.`）。原因が狙いどおりであることも別に確かめた（`_item.blade.php` に未定義の変数を入れて 1 本流すと、写しの `resources/views/approvals/notices/_item.blade.php` から `Undefined variable $canaryUndefinedVariable` が出る）＝測定は写しのコードを読んでいる |
| 所要 | 76 通りで約 50 分（1 通り 23〜89 秒）。結果の jsonl: `~/.claude/plans/approval-phase3-tasks/work/measure/mutations-wt.jsonl` |

### 結果

**76 通り = 検出 74・カナリア 1（赤が正しい）・等価 1（S04）・SKIP 0・見逃し 0（等価でないのに緑 0）。** 「当初検出漏れ→追加で検出」は無い（テストは 1 本も足していない）。

- **検出（74）**: R01〜R04・A01〜A04・T01〜T03・S01〜S03・N01〜N21・W01〜W14・H01〜H06・V01〜V14・M01〜M05
- **等価（1）**: **S04**（審査担当者を `activeReviewers()` でなく `reviewers()` で読む）= `OK (954 tests, 7426 assertions)`。計画の見込みどおり。理由: `StepHandlers.php` のすぐ下の `filter(fn (User $user) => $user->status === UserStatus::Active && $user->id !== $request->user_id)` が無効の人を落とし、削除した人は `SoftDeletes` のグローバルスコープで `reviewers()` でも入らない。`activeReviewers()` を使うのは条件を 1 か所に集めるため（Task 1-B）で、振る舞いの差は無い
- **SKIP（0）**: 無し

### 76 通りの実測（`70cca1cd` の写し）

| # | 変異（ファイル） | 実測（流した 954 本） | 落ちたテストの数（クラス名つきの名前の重複を除く。Errors と Failures の合計と食い違うのは、1 本のテストが複数回落ちるものがあるため） | 判定 |
|---|---|---|---|---|
| CANARY | カナリア: お知らせの 1 件の部品に未定義の変数（`_item.blade.php`） | 954 本・7299 assertions・Errors 1・Failures 15 | 16 | カナリア（赤が正しい） |
| R01 | M-4: フラッシュを 404 の前に戻す（`ReturnsToRequestDetail.php`） | 954 本・7418 assertions・Failures 1 | 1 | 検出 |
| R02 | 先を越されたときも入力を戻す（`ReturnsToRequestDetail.php`） | 954 本・7404 assertions・Failures 3 | 3 | 検出 |
| R03 | 断られたとき入力を戻さない（`ReturnsToRequestDetail.php`） | 954 本・7407 assertions・Failures 3 | 3 | 検出 |
| R04 | 入力の検査で断られたら詳細へ戻さない（`ReturnsToRequestDetail.php`） | 954 本・7284 assertions・Errors 13 | 13 | 検出 |
| A01 | 有効な審査担当者に無効の人を入れる（`ApprovalDepartment.php`） | 954 本・7423 assertions・Failures 4 | 4 | 検出 |
| A02 | 提出の条件で申請者本人を審査担当者に数える（`SubmitChecker.php`） | 954 本・7426 assertions・Failures 1 | 1 | 検出 |
| A03 | 種類の一覧の人数を読み違える（`types.blade.php`） | 954 本・7424 assertions・Failures 1 | 1 | 検出 |
| A04 | ⑩ の印で無効の審査担当者を数える（`AdminRequestController.php`） | 954 本・7425 assertions・Failures 1 | 1 | 検出 |
| T01 | SQL の外部キーを CASCADE に（`2026-09-30-approval-phase3a.sql`） | 954 本・7425 assertions・Failures 1 | 1 | 検出 |
| T02 | migration の列の NULL を許さない（`2026_09_30_000001_create_approval_phase3a_tables.php`） | 954 本・1647 assertions・Errors 497・Failures 107 | 573 | 検出 |
| T03 | ownedBy で決裁のお知らせに絞らない（`ApprovalNotice.php`） | 954 本・7425 assertions・Failures 1 | 1 | 検出 |
| S01 | 無効の人を担当に入れる（`StepHandlers.php`） | 954 本・7426 assertions・Failures 1 | 1 | 検出 |
| S02 | 申請者本人を担当に入れる（`StepHandlers.php`） | 954 本・7347 assertions・Failures 2 | 2 | 検出 |
| S03 | 付け替えを見ない（`StepHandlers.php`） | 954 本・7299 assertions・Failures 1 | 1 | 検出 |
| S04 | 審査担当者を activeReviewers() でなく reviewers() で読む（等価の見込み: 下の filter が無効の人を落とす）（`StepHandlers.php`） | OK（954 本・7426 assertions・全部緑） | 0 | 等価 |
| N01 | 決まり 1: 操作した本人にも出す（`Notifier.php`） | 954 本・7425 assertions・Failures 2 | 2 | 検出 |
| N02 | 決まり 2: 後に足した知らせで上書きする（`Notifier.php`） | 954 本・7425 assertions・Failures 3 | 3 | 検出 |
| N03 | 決まり 3: 削除した人にも出す（`Notifier.php`） | 954 本・7424 assertions・Failures 1 | 1 | 検出 |
| N04 | 決まり 3: 無効の人にも出す（`Notifier.php`） | 954 本・7423 assertions・Failures 1 | 1 | 検出 |
| N05 | 決まり 4: 許可していないドメインにもメール（`Notifier.php`） | 954 本・7426 assertions・Failures 2 | 2 | 検出 |
| N06 | 決まり 5: 担当の番の知らせを申請者本人にも（`Notifier.php`） | 954 本・7421 assertions・Failures 2 | 2 | 検出 |
| N07 | 決まり 6: 件名を今の申請の行から（`Notifier.php`） | 954 本・7426 assertions・Failures 1 | 1 | 検出 |
| N08 | 決まり 7: 使い始める前も出す（`Notifier.php`） | 954 本・7392 assertions・Errors 3・Failures 8 | 11 | 検出 |
| N09 | 場面 6 にもメール（`Notifier.php`） | 954 本・7426 assertions・Failures 1 | 1 | 検出 |
| N10 | 件名の制御文字を残す（`Notifier.php`） | 954 本・7426 assertions・Failures 1 | 1 | 検出 |
| N11 | リンクを設定から作る（/index.php が抜ける）（`Notifier.php`） | 954 本・7426 assertions・Failures 1 | 1 | 検出 |
| N12 | 操作した人の名前を宛先の名前に（`Notifier.php`） | 954 本・7420 assertions・Failures 2 | 2 | 検出 |
| N13 | メールの件名の行をエスケープ（&amp; が出る）（`approval-notice.blade.php`） | 954 本・7416 assertions・Failures 1 | 1 | 検出 |
| N14 | 差出人の名前を全体の設定のまま（`ApprovalNoticeMail.php`） | 954 本・7425 assertions・Failures 1 | 1 | 検出 |
| N15 | 送り直しを 1 回に（`ApprovalMailable.php`） | 954 本・7424 assertions・Failures 2 | 2 | 検出 |
| N16 | 送り直しの間をあけない（`ApprovalMailable.php`） | 954 本・7426 assertions・Failures 2 | 2 | 検出 |
| N17 | 送れた記録を残さない（`ApprovalMailable.php`） | 954 本・7421 assertions・Failures 2 | 2 | 検出 |
| N18 | 送れなかった記録を残さない（`ApprovalMailable.php`） | 954 本・7423 assertions・Failures 2 | 2 | 検出 |
| N19 | 送れなかった記録で設定の updated_at を動かす（`MailDelivery.php`） | 954 本・7426 assertions・Failures 1 | 1 | 検出 |
| N20 | 場面 4 の見出しを変える（`NoticeText.php`） | 954 本・7424 assertions・Failures 2 | 2 | 検出 |
| N21 | 部門長の必要な対応を変える（`NoticeText.php`） | 954 本・7422 assertions・Failures 3 | 3 | 検出 |
| W01 | 提出で知らせない（`Workflow.php`） | 954 本・7417 assertions・Errors 1・Failures 4 | 5 | 検出 |
| W02 | 部門長の承認で知らせない（`Workflow.php`） | 954 本・7420 assertions・Errors 2・Failures 1 | 3 | 検出 |
| W03 | 部門長の差戻しで知らせない（`Workflow.php`） | 954 本・7423 assertions・Failures 1 | 1 | 検出 |
| W04 | 審査の意見で知らせない（`Workflow.php`） | 954 本・7426 assertions・Failures 1 | 1 | 検出 |
| W05 | 社長の差戻しで知らせない（`Workflow.php`） | 954 本・7426 assertions・Failures 1 | 1 | 検出 |
| W06 | 社長の判断で知らせない（`Workflow.php`） | 954 本・7425 assertions・Failures 4 | 4 | 検出 |
| W07 | 条件の確認で知らせない（`Workflow.php`） | 954 本・7425 assertions・Failures 1 | 1 | 検出 |
| W08 | 取り下げの担当を打ち切ったあとに取る（`Workflow.php`） | 954 本・7426 assertions・Failures 1 | 1 | 検出 |
| W09 | 代理の取り下げで申請者に知らせない（`Workflow.php`） | 954 本・7425 assertions・Failures 1 | 1 | 検出 |
| W10 | 取り消しで知らせない（`Workflow.php`） | 954 本・7420 assertions・Failures 4 | 4 | 検出 |
| W11 | 付け替えで知らせない（`Workflow.php`） | 954 本・7425 assertions・Failures 1 | 1 | 検出 |
| W12 | 場面 4: 部門長として判断した人に出さない（`Notifier.php`） | 954 本・7425 assertions・Failures 4 | 4 | 検出 |
| W13 | 場面 6: 部門長に出さない（`Notifier.php`） | 954 本・7425 assertions・Failures 1 | 1 | 検出 |
| W14 | 場面 8: 条件の確認の取り消しで番が戻ったことを出さない（`Notifier.php`） | 954 本・7426 assertions・Failures 1 | 1 | 検出 |
| H01 | 部門長の交代で知らせない（`Workflow.php`） | 954 本・7421 assertions・Failures 1 | 1 | 検出 |
| H02 | 部門長の交代で前の部門長に知らせる（`Workflow.php`） | 954 本・7421 assertions・Failures 2 | 2 | 検出 |
| H03 | 審査担当者の追加で前からいる人にも知らせる（`OrganizationController.php`） | 954 本・7421 assertions・Failures 1 | 1 | 検出 |
| H04 | 審査担当者の追加でまだ届いていない審査にも知らせる（`Notifier.php`） | 954 本・7419 assertions・Failures 1 | 1 | 検出 |
| H05 | 社長の指定をトランザクションで囲まない（`UserController.php`） | 954 本・7425 assertions・Failures 1 | 1 | 検出 |
| H06 | 同じ社長を選び直しても知らせる（`UserController.php`） | 954 本・7426 assertions・Failures 1 | 1 | 検出 |
| V01 | D13: 詳細を開いても既読にしない（`RequestController.php`） | 954 本・7424 assertions・Failures 1 | 1 | 検出 |
| V02 | D13: ほかの申請の未読も既読に（`ApprovalNotice.php`） | 954 本・7425 assertions・Failures 1 | 1 | 検出 |
| V03 | D13: ほかの人の未読も既読に（`ApprovalNotice.php`） | 954 本・7425 assertions・Failures 2 | 2 | 検出 |
| V04 | ほかの人のお知らせも開ける（`NoticeController.php`） | 954 本・7423 assertions・Failures 1 | 1 | 検出 |
| V05 | 見られなくなった申請も詳細へ（404）（`NoticeController.php`） | 954 本・7424 assertions・Failures 1 | 1 | 検出 |
| V06 | お知らせを押しても既読にしない（`NoticeController.php`） | 954 本・7422 assertions・Failures 2 | 2 | 検出 |
| V07 | ⑥ を古い順に（`NoticeController.php`） | 954 本・7412 assertions・Failures 2 | 2 | 検出 |
| V08 | ⑥ を 21 件ずつ（`NoticeController.php`） | 954 本・7418 assertions・Failures 1 | 1 | 検出 |
| V09 | ベルを使い始める前も出す（`ApprovalMenu.php`） | 954 本・7424 assertions・Failures 1 | 1 | 検出 |
| V10 | ベルの 99+ の境目を変える（`notice-bell.blade.php`） | 954 本・7425 assertions・Failures 1 | 1 | 検出 |
| V11 | お知らせの件名をエスケープしない（`_item.blade.php`） | 954 本・7420 assertions・Failures 1 | 1 | 検出 |
| V12 | ホームのお知らせを 6 件に（`HomeController.php`） | 954 本・7424 assertions・Failures 1 | 1 | 検出 |
| V13 | ホームに既読も出す（`HomeController.php`） | 954 本・7423 assertions・Failures 1 | 1 | 検出 |
| V14 | 未読が無くても「すべて既読にする」を出す（`index.blade.php`） | 954 本・7426 assertions・Failures 1 | 1 | 検出 |
| M01 | 送れたあとも帯を出す（`MailDelivery.php`） | 954 本・7426 assertions・Failures 2 | 2 | 検出 |
| M02 | 管理者でない人にも帯（`_mail_failure.blade.php`） | 954 本・7426 assertions・Failures 1 | 1 | 検出 |
| M03 | 帯の宛先をアドレスに（`PasswordReissuedMail.php`） | 954 本・7425 assertions・Failures 1 | 1 | 検出 |
| M04 | 申請種類の管理に帯を出さない（`types.blade.php`） | 954 本・7422 assertions・Failures 1 | 1 | 検出 |
| M05 | 送れなかった日時を日時として読まない（`ApprovalSetting.php`） | 954 本・7413 assertions・Failures 3 | 3 | 検出 |

### 表（計画の試作の実測）との違い

1. **本数が 1 本多い（953 → 954）**。足された 1 本は `HandlerChangeNoticeTest::test_the_waiting_reviews_are_locked_before_the_department_row_is_updated`（`2f272bce`）。`mutate.py` の `TARGET` に入っているので、全部の変異で流れる。
2. **75 通りは「954 本・assertions が表より +5・Errors と Failures の数は表と同じ」**。新しい 1 本は、これらの変異のどれでも緑のまま 5 つの assertion を足すだけ（どの変異でも落ちない）。
3. **T02 だけは、Errors が表より +1（496 → 497）・assertions は表と同じ（1647）**。新しい 1 本が、設定の行（`approval_settings`）を作れず（`NOT NULL constraint failed`）Error で落ちるため。T02 は「設定の行を作れずに巻き添えで落ちる」変異なので、巻き添えが 1 本増えただけ。狙いの `test_the_sql_and_the_migration_declare_the_same_columns` は落ちている。
4. 上の 1・2・3 のほかに、**数も落ちたテストの集合も表と同じ**。

### 落ちたテストの集合と理由の文言が狙いと合うか

- 計画の表の「落ちたテスト」の名前は、76 通りの全部が実測と一致する（`CANARY`・`R04`・`T02`・`N08` の「ほか N 本」の部分は、表では名前が省かれているので件数のみ照合）。
- 名前の省かれた部分も含めて確かめるため、計画を書く段階の試作の実測（`~/.claude/plans/approval-phase3-tasks/work/measure/mutations.jsonl`）と、**（落ちたテスト・理由の文言）の組を 76 通りで突き合わせた: 74 通りは完全に同じ。違うのは 2 通りだけで、いずれも上の 1〜3 で説明がつく**（T02 は新しい 1 本が増えただけ。CANARY は例外の文言の中の scratchpad のパスだけが違う）。
- 理由の文言は、狙いの機構と合っている（意図と別の機構が落としているものは無かった）。例: R01 `Session has unexpected key [approval_reopen]`／R04 `Call to a member function all() on array`（検査の例外が詳細に戻らず素通り）／T02 `NOT NULL constraint failed: approval_settings...`／S02・S03 `対応待ちと担当が食い違う`／N05 `The following mailables were queued unexpectedly`／N14 `The expected [App\Mail\ApprovalNoticeMail] mailable was not queued`／N15 `1 is identical to 3`・N16 `null is identical to 60`（送り直しの回数と間）／V04 `Expected response status code [404] but received 302`／H03 `前からいる審査担当者には出さない`・H06 `同じ人を選び直しても出さない`／N19 `設定の更新日時は動かさない`（テストに書いた文言そのもの）。

### 測ったあとに HEAD が進んだこと

測っている間に、WT に `7fe4e1a3`（`fix(approval): 帯の文を「決裁のお知らせは画面にも届いています。」にする`）が足された（`_mail_failure.blade.php` の 1 文と `MailFailureBannerTest` の 1 行だけ。本数・assertions は変わらない）。上の 76 通りは `70cca1cd` の写しで測ったので、次を足して確かめた:

- WT の全件（HEAD `7fe4e1a3`）: `OK (3211 tests, 22860 assertions)`（`git status --porcelain` は空）
- 写しに `7fe4e1a3` の 2 ファイルを写し、`--check`（`checked 76`・NG なし）のあと、**その帯の部品とテストに当たる M01〜M05 だけを測り直した**: M01 `Failures: 2`・M02 `Failures: 1`・M03 `Failures: 1`・M04 `Failures: 1`・M05 `Failures: 3`（いずれも落ちたテストの名前は表と同じ・assertions は +5。結果: `~/.claude/plans/approval-phase3-tasks/work/measure/mutations-wt-after-7fe4e1a3.jsonl`）。M02 の当てる場所は同じファイルの別の行（文の行ではない）で、`--check` でも 1 回ずつ見つかった。ほかの変異は、当てる場所も流れるテストの集合も `7fe4e1a3` で変わらない

### 参考（表の外）: Task 6 の手直しを守るテストが効くか

表の 76 通りには `lockWaitingReviews()` を壊す変異が無い。足された 1 本が本当にその直しを守っているかを、写しで 3 通り当てて `HandlerChangeNoticeTest` を流して確かめた（`mutate.py` は使っていない・当てたあと元に戻した）:

| 変異 | 結果 |
|---|---|
| `lockWaitingReviews()` の呼び出しを消す | `Tests: 7, Failures: 1`（`test_the_waiting_reviews_are_locked_before_the_department_row_is_updated` のみ） |
| 呼び出しを部門の行の `update()` の**あと**に移す（MySQL のデッドロックの順に戻す） | 同じ 1 本のみ落ちる |
| 呼び出しの条件を常に偽にする | 同じ 1 本のみ落ちる |

### 後始末

写し（`<scratchpad>/p3a-mutation`）は、中身が写しであること（`pwd`・`ls`）を確かめて消した。WT の `git status --porcelain` は空。

## Task 10 の実測記録（2026-10-01）

WT の HEAD `70cca1cd` の写しで見た。
手元のブラウザ（Playwright）で、使い捨ての SQLite ＋ `artisan serve --port=8767` の写しを見た。メールは `log`・キューは `sync`・`APP_LOCALE=ja`。画面の幅は 1440px と 375px（11 は 1800 / 1200 / 375px）。コードは変えていない。

### 試しのデータ（Step 2 の出力。Expected と一致）

`applicant unread=2`・`head unread=27`・`reviewer unread=3`・`president unread=1`・`extra unread=1`・`banner=true`・`done_id=24`（展示会の出展）。ほかに、A&B 社の申請は 23、倉庫の賃借は 25。「多数 未読」は未読 120 件。

### 見たこと 12 項目

12 項目すべて合格（5 だけ、計画の数の書き方が実際と食い違う。下の「気づいたこと」の 1）。

| # | 画面（ログインした人） | 結果 | 実測 |
|---|---|---|---|
| 1 | ベル（部門長） | 合格 | 丸「27」・`aria-label`「お知らせ（未読 27 件）」・リンク先は `/approvals/notices`。ベルはメニューのボタンの左で、間は 12px（1440px: ベル右端 1339・メニュー左端 1351 / 375px: 278 と 290）。375px でもヘッダー（高さ 52px）に収まり、横にはみ出さない |
| 2 | ベル（多数 未読） | 合格 | 丸「99+」・`aria-label`「お知らせ（未読 120 件）」。丸の右端はベルの右端より 2px 出るが、メニューのボタンとは 10px 空く（1440px: 1327 と 1337 / 375px: 266 と 276） |
| 3 | ⑥（部門長） | 合格 | 20 件 + 7 件（27 件）で 2 ページ。新しい順（社用車 → 倉庫 → 決裁されました → 展示会 → A&B → 備品の購入 22 … 08 / 2 ページ目は 07 … 01）。未読は太字 + 赤い「未読」（20 件とも）。1 行目「{見出し}：{件名}」と日時（保存は UTC の 06:34・表示は日本時間の 10/1 15:34）。2 行目「申請 花子（住宅事業部）・申請 花子さんの操作・部門長の確認（承認か差戻し）」。`A&B社の<見積り>"比較"` は打ったとおりの文字。ページ送りは `approvals/_pager.blade.php`（⑩ の決裁済み・否決も同じ部品）で `< 1 2 >`。375px は 1 件ずつ縦に積む（表ではない・横スクロールなし。1 つの白い枠の中を線で区切る形で、1 件ごとの別の枠ではない） |
| 4 | 1 件を押す（部門長） | 合格 | A&B を押す → `/approvals/requests/23` が開く（見出しも打ったとおりの文字）。⑥ に戻ると A&B は太字でなく印も無い・ベル 27 → 26。375px では倉庫の賃借を押して `/approvals/requests/25`・26 → 25（詳細を開いた時点で既に減っている） |
| 5 | 詳細を URL で開く（部門長）→ 他の人 | 合格（数は 2 減る。気づいたこと 1） | `/approvals/requests/24` を開く → 部門長のこの申請のお知らせ 2 件（「決裁されました（条可）」と「部門長の確認のお願い」）が既読になり、ベル 25 → 23。そのあと審査担当者でログインすると、同じ申請の「決裁されました（条可）」「審査の意見のお願い」は未読のまま（ベル 3 件）。申請者でも「条件の確認のお願い（条可）」は未読のまま（ベル 2 件）。開いた本人の分だけ（D13） |
| 6 | すべて既読にする | 合格 | 部門長（1440px）: 緑の帯「お知らせをすべて既読にしました。」・ボタンが消える・ベルの丸が消える（ベルは残り、`aria-label` は「お知らせ（未読 0 件）」）。375px は「多数 未読」で確かめた（部門長は 1440px で既読にしたため）: 同じ帯・ボタンが消える・「99+」が消える |
| 7 | 見られなくなった申請（追加 審査） | 合格 | ⑥ に「審査の意見のお願い：社用車の購入（審査待ち）」の 1 件（未読）。押す → 302 で ⑥ に戻り、赤の帯「この申請は、今は見られません。」・その 1 件は既読（太字でない・印が無い）・ベルの丸が消える。1440px と 375px の両方（375px は `read_at` を空に戻してから） |
| 8 | ホーム（申請者・決裁 管理者） | 合格 | 申請者: 「新しいお知らせ」に「差戻しされました：倉庫の賃借」「条件の確認のお願い（条可）：展示会の出展」（どちらも未読）。「お知らせをすべて見る」→ `/approvals/notices`。決裁 管理者: 「新しいお知らせはありません。」。375px でも同じ |
| 9 | D2 の帯（決裁 管理者） | 合格 | ホーム・利用者の管理・部門の管理・申請種類の管理・進行中の申請の 5 画面すべてで、黄色の帯「通知メールが送れていません。最後に送れなかったのは 10/1 15:34（宛先: 山田 花子さん）です。画面のお知らせは届いています。メールの設定の確認が必要です。」。375px は 4 行に折り返し（高さ 104px・幅 343px・はみ出しなし）。部門長でログインしても出ない（ホーム・⑥・基幹のダッシュボード）。`MailDelivery::recordSent()` のあと `pendingFailure()` は空になり、5 画面とも帯が消える（1440px と 375px） |
| 10 | 使い始める前 | 合格 | `launched_at` を空にして `recordFailed("山田 花子")`。部門長・決裁 管理者とも、基幹のダッシュボードにベルが出ない。`/approvals/notices` は 302 で `/approvals`（「決裁の機能は準備中です。使い始める日が決まったらお知らせします。」）へ。準備中のホームの D2 の帯は決裁 管理者だけ（部門長には出ない）。確かめたあと `launched_at` を入れ直した（`launched=true`） |
| 11 | 全画面 | 合格 | 下の「はみ出しの測り方と結果」 |
| 12 | メールの文面 | 合格 | 差出人の名前「ミツワ都市開発 決裁システム」・件名「【決裁】部門長の確認のお願い：A&B社の<見積り>"比較"」（`&amp;` にならない）・宛名「部門 長 様」・前置き「次の申請が、あなたの確認の番になりました。」・件名・申請者「申請 花子（住宅事業部）」・決裁No「まだありません」・必要な対応「部門長の確認（承認か差戻し）」・リンク `http://127.0.0.1:8767/approvals/requests/23`・金額や本文は無し（「金額や本文はメールに書いていません。リンクから開いて確かめてください。」）。プレーンテキストだけ（HTML の部分は無い）。ログには 28 通。文面は `screenshots-3a/12-mail.txt` |

### 見つけた不具合

なし。

### 気づいたこと（不具合ではない）

1. **計画の 5 の数**: 計画は「ベルの数が 1 減る」と書くが、実測は 2 減る（25 → 23）。部門長には、展示会の出展について未読が 2 件あった（「部門長の確認のお願い」と「決裁されました（条可）」）。`RequestController::show` は `ApprovalNotice::markReadFor($user, $approvalRequest)` で、**開いた人のその申請の未読をすべて**既読にする（D13・コードのコメントのとおり）ので、実装は意図どおり。計画の文を「その申請の自分の未読が全部既読になる（部門長はこの申請で未読 2 件 → ベルが 2 減る）」に直すとよい。ほかの人の分が変わらないことは、審査担当者（3 件）と申請者（2 件）が未読のままで確かめた。
2. **「99+」の丸**: 丸が広く（約 29px・ベルの絵は 20px）、ベルの絵の上の部分に重なる（ベルの上のつまみが隠れる）。読めるしメニューとも重ならないので見た目だけの話。直すなら、丸の位置を右へ 2〜4px ずらす程度。
3. **375px の日時**: 見出しが 1 行に収まらない件では、日時が右寄せで次の行に回る（読める）。
4. **試した順の都合**: 6 の 375px は「多数 未読」で行った（部門長は 1440px で既読にしたため）。7 の 375px は、`ApprovalNotice::ownedBy($u)->update(['read_at' => null])` で未読に戻して行った。11 の前に、部門長と「多数 未読」の未読を同じ方法で戻した（使い捨ての SQLite）。

### コンソール

全部のブラウザの画面（見たこと 1〜10 の各画面・幅ごと、11 の 87 回）で `console` の error / warning と `pageerror` は **0 件**。

### はみ出しの測り方と結果（見たこと 11・Bug #29）

測り方: 幅 1800 / 1200 / 375px のブラウザ（スマホの擬似なし・ちょうどその幅）で、5 人（部門長・申請者・決裁 管理者・多数 未読・追加 審査）が、それぞれ見られる画面を `goto` → `networkidle` のあと

```js
const main = document.querySelector('main');
main.scrollWidth === main.clientWidth && document.documentElement.scrollWidth <= innerWidth
```

を測った。見た画面（1 つの幅につき 29 回）: 基幹のダッシュボード（ベル付き）・決裁のホーム・⑥（1 ページ目・2 ページ目・多数 未読は 2 ページ目と 6 ページ目）・自分の申請・新しい申請・申請の詳細（23・24・25）・利用者の管理・利用者の一括登録・部門の管理・申請種類の管理・進行中の申請・パスワード変更。帯（D2）が出ている状態で測った。

結果: 1800px 29 画面・1200px 29 画面・375px 29 画面（合計 87 回）、**はみ出し 0 件**・200 以外の応答 0 件。`/approvals/numbers` は JSON を返す部品（画面ではない）なので数えない。

### コンパイル済みビューの lint（Step 5）

`php artisan view:cache` のあと `storage/framework/views/*.php` の 294 ファイルを `php -l`: **INVALID 0 件**。`view:clear` 済み。

### 写真（`/Users/masanori/site/approval/screenshots-3a/`・画像 22 枚 + 文面 1 つ）

写っているのは試しのデータだけ（名前は「部門 長」「申請 花子」「決裁 管理者」など。パスワードは写っていない・ログインの画面は撮っていない）。375px は 2 倍の解像度（750px 幅）・1440px は等倍。

| ファイル（`-1440` / `-375` の 2 枚ずつ） | 写っているもの |
|---|---|
| `01-bell` | ベル（未読 27）。部門長 |
| `02-bell99` | ベル（99+）。多数 未読 |
| `03-notices` | ⑥ の 1 ページ目（未読と既読が混ざる。A&B 社の 1 件が既読。375px は倉庫の賃借が既読）。1440px は 26 件・375px は 25 件の未読 |
| `03-notices-pager` | ⑥ の 1 ページ目の下（ページ送り） |
| `07-notice-gone` | ⑥ の赤の帯「この申請は、今は見られません。」。追加 審査 |
| `08-home-notices` | ホームの「新しいお知らせ」（申請者・未読 2 件）。見えるところまで画面を送って撮影 |
| `08-home-nonotices` | ホームの「新しいお知らせはありません。」（決裁 管理者。D2 の帯が出ている状態） |
| `09-band-home` | D2 の帯。決裁 管理者のホーム |
| `09-band-users` | D2 の帯。利用者の管理 |
| `10-preparing-head` / `10-preparing-admin` | 使い始める前の準備中のホーム（部門長は帯なし・決裁 管理者は帯あり。ベルなし） |
| `12-mail.txt` | メールの文面（A&B 社の部門長の確認のお願い） |

`01` 〜 `09`・`12` が計画の Step 4 の分。`10-preparing-*` は見たこと 10 の確認用に足した。

注: ブラウザの画面は `main` の中だけが縦に動く作りなので、`fullPage` の撮影は画面の高さまでしか撮れない。そのため 1 画面ぶん（1440×900 か 1000・375×812）で撮り、長いものは見せたい所まで送って撮った。

### 片付け（Step 6）

`artisan serve`（port 8767）を止めた（`lsof` で待ち受けなし・`curl` が接続できない）。scratchpad の写し（`p3a-browser`。先に `pwd` と `ls` で写しと確かめ、`node_modules` のシンボリックリンクを先に外して main repo の `node_modules` は無事）・使い捨ての SQLite・設定のファイル・`artisan serve` のログ・途中の確認用の画像を消した。Playwright の作業フォルダの `.playwright-mcp`（コンソールの記録）も消した。WT の `git status --porcelain` は空・HEAD は `70cca1cd` のまま（WT にはコミットしていない）。`screenshots-3a/` は残してある。

## 実装中に決めたこと（2026-10-01）

計画を書いた時点では分からず、実装と確かめの途中で決めた 3 つ。

- **Task 6 の手直し（`2f272bce`）**
  - 指摘: 点検で「審査担当者の追加は、部門の行を更新したあとで申請の行に触る（お知らせの行の外部キーが申請の行に共有ロックを取る）」と言われた。
  - 再現: 使い捨ての MySQL 8.4.11 で、申請部門と審査部門が同じ申請への審査の意見・取り下げと 1213（デッドロック）になった（REPEATABLE READ でも READ COMMITTED でも）。
  - 直し: `OrganizationController::lockWaitingReviews()` が、部門の行を更新する前に、その部門で審査を待っている申請の行を主キー順にロックする（`headChanged()` と同じ順）。直したあとは 1213 が出ない。順序を見るテストを 1 本足した（`HandlerChangeNoticeTest::test_the_waiting_reviews_are_locked_before_the_department_row_is_updated`）。
  - 残り（受け入れた）: REPEATABLE READ では、同時に審査が済んだ申請にも古い「審査の意見のお願い」が出うる（押すと詳細で済んだと分かる）。
- **D2 の帯の文（`7fe4e1a3`）**: 帯はパスワード再発行のメールが送れなかったときにも出るので、文の「画面のお知らせは届いています。」を、利用者の決定（2026-10-01）で「決裁のお知らせは画面にも届いています。」にした（§0.10 の 8）。
- **13.x の取り込み 2 回（`b6acb94f`・`70cca1cd`）**: 2 回目で、テストのメモリの直し（`tests/TestCase.php` の `tearDown()` で Carbon の翻訳を片付ける。全件のピークは 505.50 MB から約 310 MB）が入った。全件は `OK (3211 tests, 22860 assertions)`。
