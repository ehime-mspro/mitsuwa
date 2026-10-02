# 決裁申請 段階3（3b: 毎朝の催促・祝日の判定・催促の設定 ⑫）実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 段階3 の後半（3b）— 対応を待っている申請が「3 日待ち」以上になった人に、平日（土日・祝日・会社の休みを除く）の朝 9 時に 1 人 1 通のまとめメールを送り、決裁の管理者が催促を送らない日を登録できる画面（⑫）を作り、使い始めるまで何も届かないまま本番へ出す。

**Architecture:** 定期実行（`routes/console.php`）に `approvals:remind`（`App\Console\Commands\ApprovalRemindCommand`）を 9:00〜9:04 の 1 回で足す。コマンドは「使い始めたか → 送る日か（`App\Support\Approval\ReminderCalendar`。土日・日本の祝日〈部品 Yasumi〉・⑫ で登録した送らない日 `approval_holidays`）→ 今日の行（`approval_reminder_runs`・一意の日付）を入れられるか」を順に見て、今日の行とまとめメール（`App\Mail\ApprovalReminderMail`。3a の土台 `ApprovalMailable` の上）を 1 つのトランザクションで積む。載せる申請はホームの対応待ちと同じもので、全員分を 1 回で引く `PendingWork::everyone()` を足し、全員について `PendingWork::for()` と同じ結果になることを突き合わせで守る。⑫ は `Approval\HolidayController`（申請種類の管理と同じ形の画面）。

**Tech Stack:** Laravel 12 / PHP 8.3（本番 8.3.32・手元 8.3.35）/ MySQL 8（本番）・SQLite（テスト）/ Blade + Alpine.js 3 + Tailwind v4 / PHPUnit 11 / **azuyalabs/yasumi ^2.12（新規。2.12.0・php >=8.2・依存は ext-json だけ）**

**Spec:** 設計書 @docs/superpowers/specs/2026-09-30-approval-phase3-design.md（この計画は §1.3・§4・§5.2・§5.3・§5.8〜§5.10・§6・§7 の **3b** の部分。§9 の「計画で決めること」の 3b の分は §0 で決めた）。要件定義書 @docs/決裁申請_要件定義書_v1.md（v1.11 の 8.3・8.4・13 章の ⑫・15.3・15.5）。3a の計画 @docs/superpowers/plans/2026-09-30-approval-phase3a.md（§0.1 作り方・§0.12 テストの土台・Task 12 の本番反映の作法は 3b でもそのまま効く）。

## Global Constraints

- 使い始める前（`approval_settings.launched_at` が空）は催促を 1 通も送らず、記録も残さない（コマンドの中で `ApprovalSetting::launchedForMenu()` を読む。定期実行に門番は無い。設計書 §5.2）。⑫ は使い始める前から開く（門番は `approval.admin` だけ）
- 催促はメールだけ（お知らせは作らない。要件 8.1）。宛先は有効で削除しておらず、`ApprovalMailDomain::allows()` が通すメールアドレスのある人だけ
- 今日の行（`approval_reminder_runs`）とまとめメールの `jobs` の行は **1 つのトランザクション**（`QUEUE_CONNECTION=database` が前提。3a の `Notifier` と同じ）。同じ日に 2 回送らない守りは `sent_on` の一意の索引
- まとめメールの中身（宛名・リンク）はコマンドが積むときに決めて持たせる。リンクは設定 `approval.mail_link_root`（既定は `APP_URL` に `/index.php` を足したもの）に `route(…, false)` の道をつないで作る（定期実行の `route()` は本番で `/index.php` が抜ける。D20）
- メールはテキストだけ。件名の頭は「【決裁】」、差出人の名前は「ミツワ都市開発 決裁システム」（3a の `ApprovalMailable::FROM_NAME`）。1 件ごとに書くのは件名・申請者（申請部門）・必要な対応・待ち日数・リンクだけ（金額・本文・添付・コメントは書かない。要件 8.2）。1 通 20 件まで（D18）
- 日付は日本の暦（`JapanTime::today()`）。待ち日数は `PendingWork::waitingDays()`（D5・段階2 の D20）。保存した日時・日付の表示は `JapanTime::format()`（Bug #61。date キャストも）
- 祝日の部品は手元で入れてから `vendor` ごと本番へ送る（本番では `composer install` をしない。段階1 の QR の部品と同じ。設計書 §5.10）。WT では Task 2 で `composer install`（lock どおり）だけを打つ。**main repo では Task 9 まで打たない**
- 日付の入力は `date_format:Y-m-d` で存在しない日付（2/30）を断る（Top trap #15）。`x-data` に矢印関数を書かない（Top trap #4）・`<option>` は `@foreach`（Bug #16）・操作のあとの戻り先は固定のルート（Bug #64）
- テストは worktree で `APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`。main repo では作業もテストもしない。worktree に `.env` を作らない（`.env*` は読まない・grep しない）
- コミットは Conventional Commits・日本語の件名（72 文字以内・句点なし）・1 コミット 1 関心事・本文の最後に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`。`--no-verify`・`--amend`・`git reset` でのやり直し・`git stash` は使わない。push は利用者が指示したときだけ

## Review Focus

設計書が触れていないが、使う人がいちばん出会いそうな場面（どの場面のテストも見ていなかったもの）。計画を書く段階で 5 つとも試作にテストを足し、コードを 1 か所壊すとそのテストが落ちることを確かめた（変異。Task 6 の表の ID）。

1. **件名に改行・「&」「<」を含む申請** → まとめメールの 1 件は 1 行に収まり、打ったとおりの文字で届く（`&amp;` にならない）（Task 4 の `test_the_mail_text_and_links`。変異 M14・M15）
2. **日本時間の 0:00 の前後に番が来た申請** → 「3 日待ち」の境目は日本の暦（10/2 23:59 は月曜の朝に 3 日待ち・10/3 0:00 は 2 日待ち）（Task 4 の `test_each_person_gets_one_digest_of_the_requests_waiting_three_days_or_more`。変異 M01・M02）
3. **存在しない日付（2/30）を送らない日に打つ** → 繰り上げずに断る（Task 5 の `test_the_dates_and_the_description_are_checked`。変異 V05）
4. **2/29 を含む毎年の送らない日** → うるう年でない年は 2/28 と 3/1 で区切られる・「1 年より短く」の決まりは翌年の 2/28 で区切る（Task 1 の `test_february_the_twenty_ninth_in_a_yearly_period`・Task 5 の `test_a_yearly_period_must_be_shorter_than_a_year`。変異 V02）
5. **本番の定期実行の形（`APP_URL` に途中の道 `/system/manage` がある）** → メールのリンクの道が二重にならず、`/index.php` が 1 回だけ入る（Task 4 の `test_the_links_do_not_repeat_the_sub_path_of_the_app_url`。変異 M11・M12）

---

## 0. 計画を書く段階で決めたこと（設計書 §9 の宿題のうち 3b の分）

### 0.1 作り方（試作を先に作って確かめた）

- この計画のコードは、scratchpad の試作（`approval-phase3` = `91fe7969` の写し）で Task ごとにコミットし、**各段で全件のテストが緑**であることと、**テストだけを先に入れると何が落ちるか**を測ってから書き写したもの（2b・3a と同じ作り方）。各 Step の Expected の本数と落ちるテストの名前は実測（2026-10-02。はじめ `7ae25fa3` の上で作って測り、計画を書いている間に `13.x` が `91fe7969` へ進んだので、`91fe7969` の上に作り直して全件と赤を測り直した。3b の 5 段の差分は同じものがそのまま当たる）
- 同じ中身の差分のファイルを `~/.claude/plans/approval-phase3-tasks/3b/patches/`（`0001`〜`0005`。この計画のコミット 1 つに 1 ファイル）に置いた。担当は計画のコードを打ち直さず、この差分を当ててよい（**テストを先に** `git apply --include='tests/*'`、実装を**あとで** `git apply --exclude='tests/*'`）。当てたら `git diff --stat` が各 Task の「差分の大きさ」と同じことを確かめる。**差分のファイルと計画のコードが食い違ったら、計画を正として止まり、報告する**
- 各 Task のコードの塊のうち、新しいファイルは全文、既存のファイルは差分（`diff` の形）で示す
- 進め方は 3a と同じ（担当の決まりは `~/.claude/plans/approval-phase3-tasks/3b/rules.md`。3a の `rules.md` に、Task 2 の `composer install` だけを足したもの）: 実装の担当は 1 度に 1 人（Task ごと）・コードの点検は別の担当が WT の HEAD の写しで行う・変異の確かめは写しで・Task 9（本番反映）は担当に任せず親の会話が行う
- **使い捨ての MySQL 8.4.11 で確かめた**（2026-10-02・ポート 34419・確かめたあと止めた）: SQL ファイルを流した表と migration で作った表の `SHOW CREATE TABLE` が 2 表とも完全に一致・3b のテスト 6 本（38 tests）が MySQL でも通る・本番と同じ分け方（`--` の行を消して `;` で分ける）で文が 2 つ

### 0.2 表と本番の SQL（§5.3・§9 の 9）

- **`approval_holidays`（新・送らない日）**: `start_date`・`end_date`（DATE）・`repeats_yearly`（TINYINT(1)・既定 0）・`description`（VARCHAR(50)・必須。利用者の決定 2026-10-02）・`created_at`・`updated_at`。索引は主キーだけ（行は数十件の見込み。判定のときに全件を 1 回読む）
- **`approval_reminder_runs`（新・催促を送った日）**: `sent_on`（DATE・**一意** `uq_approval_reminder_runs_sent_on`）・`recipient_count`（送った人数）・`item_count`（催促した申請の件数。のべ・1 通に載せきれなかった分も数える）・`created_at`（更新の列は無い）。書くのは催促のコマンドだけ
- 本番の SQL は `database/sql/2026-10-02-approval-phase3b.sql`、テストの鏡は `database/migrations/2026_10_02_000001_create_approval_phase3b_tables.php`。`Phase2TablesTest` の型の正規表現は `DATE` を拾わないので、新しい `Phase3bTablesTest` で突き合わせる（作る表・列と NULL・一意の索引を両方向に）
- ⚠ date キャストの列に Eloquent で書くと `Y-m-d 00:00:00` の文字で入る（MySQL の DATE は日付だけを残す・SQLite は文字のまま）。**読むときは `whereDate()`**、書くときは必ず Eloquent を通す（`insertOrIgnore` などで `Y-m-d` を直接書くと、SQLite で同じ日の行が別の文字になり一意が効かない）

### 0.3 送る日の判定 `ReminderCalendar`（§5.10・§9 の 11・D4・D17・D19）

- **部品は `azuyalabs/yasumi ^2.12`**（2.12.0 は 2026-09-30 公開・`php >=8.2`・ほかの部品に頼らない）。`composer require` で変わるのは `composer.json` 1 行と、`composer.lock` の Yasumi の追加と content-hash だけ（2026-10-02 に実測）
- **祝日の中身を実測で確かめた**（2026-10-02）: Yasumi の日本の 2025〜2027 年の祝日が、内閣府の「国民の祝日」の一覧（`syukujitsu.csv`）と 1 日も違わない（19・18・17 日。振替休日・国民の休日を含む）。テストは 2026〜2027 年の 730 日を、一覧と土日で 1 日ずつ突き合わせる（送る日は 489 日）
- ⚠ Yasumi の `isHoliday()` は、渡した日時の**その時刻帯での年月日**で比べ、別の年の日には false を返す（UTC 15:00＝日本の翌 0:00 を渡すと前の日として判定する）。なので**年ごとに祝日の表（`Y-m-d` → 名前）を 1 回だけ作り、日本の暦の日付の文字で引く**
- 送らないわけ（`reasonNotToSend()`）: 「土曜日」「日曜日」「祝日（{名前}）」「送らない日（{説明}）」の順に見る（名前の例: 「振替休日 (憲法記念日)」「国民の休日」）。コマンドが画面（ログのファイル）に出す
- 毎年繰り返す期間は**月と日**で比べ、開始の月日が終了の月日より後なら年をまたぐとみなす（`ApprovalHoliday::covers()`。12/29〜1/3）
- **⑫ の「次に催促を送る日」**（`nextSendDay()`）: 今日が送る日で、日本時間の 9:05 より前で、今日の行がまだ無ければ今日。そうでなければ明日から数えて最初の送る日（366 日先まで探し、無ければ「1 年以内にありません」）。使い始める前かどうかは見ない（画面が「決裁を使い始める前なので、まだ送りません。」と添える）

### 0.4 全員分の対応待ち `PendingWork::everyone()`（§5.8・§9 の 7）

- 利用者の id ごとに、`for()` と**同じ形・同じ並び**で返す（対応待ちが無い人は入らない）。問い合わせは、部門長の表・審査担当者の表・設定・待ちの段階（申請と申請者・部門・種類を前もって読む）・申請者の番の申請の 1 回ずつで、人数や申請の数で増えない
- 規則は `for()` の `waitingSteps()`・`ownTurns()` と同じ（部門長＝付け替えた人か申請部門の部門長／審査＝審査部門の審査担当者／社長＝今の社長。自分の申請は除く＝D16）。1 件の形（`stepItem()`・`ownItem()`）と並べ方（`sorted()`）は `for()` と共有する
- 有効かどうか・削除したか・メールを送れるかは見ない（`for()` と同じ）。宛先はコマンドが絞る
- ⚠ id は整数にそろえてから比べる（MySQL の接続の設定しだいで文字で来ても、自分の申請を除けるように。SQLite のテストでは区別がつかない＝変異 P06 は等価）
- **`for()` を `id` の順に読むようにした**（§0.9）: 同じ秒に番が来た申請の並びが、DB の索引の使い方で変わっていた（試作の突き合わせで、申請者の番の 2 件が状態の順に出た）

### 0.5 催促のコマンド `approvals:remind` とまとめメール（§5.8・§9 の 8・10）

- 順に確かめて、当たれば何もしないで終わる（画面に 1 行出す）: 「使い始める前なので送りません。」→「送らない日なので送りません（{わけ}）。」→ 今日の行を入れる（一意の索引で断られたら「今日の分はもう送っています。」）。送ったら「催促を送りました（N 人・M 件）。」。行の頭に日本の日付
- **「今日の分をもう送ったか」は先に読まず、今日の行を入れられるかだけで決める**（入れる操作そのものが守り。先に読んでから入れると、2 つが同時に動いたときに読みと入れるのあいだが空く）。今日の行とまとめメールは 1 つのトランザクション
- **送る相手がいない日も 0 人・0 件で記録する**（⑫ の「前回の催促」で、催促が動いていることが分かる）
- 載せるのは `PendingWork::everyone()` のうち `waitingDays() >= 3`（`MIN_DAYS`）のもの。1 通 20 件まで（`MAX_ITEMS`）、それより多いときは「ほか N 件はホームで確かめてください。」。件名の数は全部の件数
- 1 件の「必要な対応」は**ホームの対応待ちと同じ「{役割}・{対応}」**（例「部門長・承認・差戻し」「申請者・差戻しの対応」）。件名・申請者・申請部門も**ホームと同じ今の値**（§0.10 の 1・2）
- まとめメールの本文（テキスト。`resources/views/mail/approval-reminder.blade.php`）は設計書 §5.8 の見本のとおり。`{!! !!}` で書く。件名・申請者・申請部門は `ApprovalMailable::oneLine()`（3a の `Notifier` の私的な部品を土台へ移した。§0.9）で改行などを空白に
- **リンク**: 設定 `approval.mail_link_root`（`config/approval.php`。既定 `rtrim(APP_URL, '/') . '/index.php'`。本番は `https://www.mitsuwat.co.jp/system/manage/index.php`）に `route(名前, 引数, false)` の道をつなぐ
  - 本番の読み取り（2026-10-02・利用者の了承のあと）: `config('app.url')` は `https://www.mitsuwat.co.jp/system/manage`（`/index.php` なし）・コマンドの中の `route('approvals.requests.show', 123)` は `https://www.mitsuwat.co.jp/system/manage/approvals/requests/123`（このままでは本番で開けない）
  - `route(…, false)` は、コマンドの中でも（`APP_URL` に `/system/manage` があっても）`/approvals/requests/123` を返す（Laravel の `SetRequestForConsole` がその道をリクエストの元の道にし、相対の URL から除くため。2026-10-02 に実測。テスト `test_the_links_do_not_repeat_the_sub_path_of_the_app_url` が本番の形を作って守る）
  - ⚠ `URL::forceRootUrl()` で元を差し替える形は使わない（通信の種類〈https〉をリクエストのものに置き換え、試作で `https` が `http` に化けた）
- 予定（`routes/console.php`）: `Schedule::command('approvals:remind')->cron('0-4 9 * * *')->appendOutputTo(storage_path('logs/approval-reminder.log'))->withoutOverlapping(30)`。**キュー処理より前**（同じ回のキュー処理が積んだメールを送る）。画面の出力をログのファイルに足すのは、本番の `laravel.log` が error だけ残すため（送ったか・送らなかったわけが毎朝 1 行残る。`deploy.sh` は `*.log` を送らない）。目印の期限 30 分は、残っても翌朝を止めないため。メンテナンス中は送らない
- 送り直し・送れたかの記録は 3a の土台のまま（`$tries = 3`・`$backoff = 60`・`MailDelivery`）。送れなかったときの laravel.log の頭は「決裁の催促のメールを送れませんでした」

### 0.6 催促の設定（⑫・§5.9・§9 の 12）

- ルート（`routes/approval.php` の決裁の管理のグループ・門番 `approval.admin`）: `approvals.admin.holidays.index`（GET `/approvals/admin/holidays`）・`store`（POST）・`update`（PUT `/{approvalHoliday}`）・`destroy`（DELETE）
- 画面（`resources/views/approvals/admin/holidays.blade.php`）は申請種類の管理と同じ形: D2 の帯 → 断られた理由 → 見出しと説明 → **「次に催促を送る日 10/5（月） 9 時」「前回の催促 10/1（木）（3 人・5 件）」**の枠 → 送らない日の表（期間・繰り返し・説明・編集｜削除。開始日の順）→ 追加と編集の小窓（開始日・終了日〈`type="date"`〉・毎年繰り返す・説明）。断られたら同じ小窓を打った中身で開き直す。表は `scroll-hint` の中（375px で横に送れる）
- 期間の出し方（`ApprovalHoliday::periodLabel()`）: 毎年は月日だけ「12/29〜1/3」、その年だけは「2026/08/13〜2026/08/14」、1 日だけなら 1 つ
- 入力の決まり（利用者の決定 2026-10-02）: 開始日・終了日は必須で存在する日付（`date_format:Y-m-d`）・終了日は開始日と同じか後（断るときの文は**年をまたぐ期間の直し方を添える**「終了日は開始日より前にできません（年をまたぐ期間は、終了日を次の年の日付にしてください。例: 2026/12/29〜2027/1/3）。」＝年末年始をいちばん起きやすい打ち間違い〈終了日を同じ年〉で打ったときに直し方が分かる。別の会話の試作から取り入れた）・**毎年繰り返す期間は 1 年より短く**（終了日が開始日の 1 年後〈2/29 は翌年の 2/28。`addYearNoOverflow()`〉と同じか後なら断る。その年だけの期間には当てない）・**2/29 は毎年の期間にも使える**（うるう年だけ当たる。ほかの年は 2/28 と 3/1 で区切る）・**説明は必須・50 文字まで**
- 追加・修正・削除は `SettingLogger`（`holiday.created`・`holiday.updated`〈変わった項目だけ〉・`holiday.deleted`。対象 `approval_holiday`）。前と後の値は `ApprovalHoliday::formValues()`（日付は `Y-m-d` の文字。Carbon のまま比べると、変えていなくても「変わった」と記録される）
- 入口: 「決裁の管理」のサイドバー 4 か所（決裁のみ利用者の PC・スマホ、基幹の PC・スマホ。「申請種類の管理」の下）と、決裁の管理者のホーム 2 画面（準備中・使い始めたあと）のリンクの行。使い始める前から出す
- D2 の帯（`approvals._mail_failure`）を ⑫ の上にも出す（3a の帯の置き場所の「3b で催促の設定」）

### 0.7 走査テスト（全件分類）に登録するもの

3a §0.8 と同じく、**各 Task が足したものは、その Task の中で登録する**（途中の各段でも全件が緑）。

| 走査テスト | 登録するもの | Task |
|---|---|---|
| `tests/Feature/ClockReadScanTest.php` | `ReminderCalendar.php` 1 件（今の瞬間が日本時間の 9:05 より前か） | 2 |
| `tests/Feature/Ops/ScheduleTest.php` | 催促の予定（時刻・時刻帯・重ねない・目印の期限・ログのファイル・メンテナンス中・1 日 1 回・キュー処理より前） | 4 |
| `tests/Feature/Approval/ApprovalAdminGateTest.php` | `$existingValues` に `approvalHoliday`・`tableCounts()` に `approval_holidays`・下限を 46 → **50**（ルート）・26 → **30**（管理のルート）・234 → **270**（要求） | 5 |
| `tests/Feature/Approval/Phase2/LaunchGateTest.php` | `OPEN_BEFORE_LAUNCH` に `approvals.admin.holidays.`（理由つき） | 5 |
| `tests/Feature/Approval/Phase3/MailFailureBannerTest.php` | 使い始める前の管理の画面に ⑫ | 5 |
| `tests/Feature/Approval/ApprovalSidebarTest.php` | ⑫ のリンクが使い始める前から決裁の管理者に出る | 5 |
| `lang/ja/validation.php` | `repeats_yearly` の和名「毎年繰り返す」（`description` は汎用の「内容」なので画面で「説明」に上書き。`start_date`・`end_date` は汎用のまま） | 5 |

- `ValidationErrorFeedbackTest`: ⑫ のビューは `$errors` を出すので登録は要らない
- `StoredTimestampDisplayScanTest`: 新しい日付の表示はすべて `JapanTime::format()`（登録は要らない。下限が上がる向き）
- `LoginGuideTest` の 1 ファイル 1 宣言: 新しいファイルはどれも 1 つ
- `ApprovalOnlyLockoutTest`: ⑫ は `approvals.` の名前で `App\Http\Controllers\Approval\` に置く（登録は要らない）

### 0.8 既存のコードを変えるもの（振る舞いを変えない片付け）

| ファイル | 変えること | 理由 | Task |
|---|---|---|---|
| `app/Support/Approval/PendingWork.php` | 1 件の形と並べ方を `stepItem()`・`ownItem()`・`sorted()` に出して `everyone()` と共有・`for()` を `id` の順に読む | 規則と形を 2 か所に書かない・同じ秒の並びを決める | 3 |
| `app/Support/Approval/Notifier.php`・`app/Mail/ApprovalMailable.php` | `Notifier` の私的な `oneLine()` を `ApprovalMailable::oneLine()`（public static）へ移す | まとめメールも同じ部品を使う | 4 |

### 0.9 既存のテストを変えるもの

| テスト | 変えること | Task |
|---|---|---|
| `ScheduleTest`・`ClockReadScanTest`・`ApprovalAdminGateTest`・`LaunchGateTest`・`MailFailureBannerTest`・`ApprovalSidebarTest` | §0.7 のとおり足すだけ（意味は変えない） | 2・4・5 |

既存のテストの意味が変わるものは無い（`for()` の並びの直しは、同じ秒に番が来たものの並びだけで、どの既存のテストも見ていない）。

### 0.10 設計書から変えた細部（計画を書いていて分かったこと）

| # | 設計書 | この計画 | 理由 |
|---|---|---|---|
| 1 | §5.8 のまとめメールの見本「部門長の確認 ／ 5 日待ち」 | 「部門長・承認・差戻し ／ 5 日待ち」（ホームの対応待ちの「{役割}・{対応}」と同じ言葉） | 「ホームの対応待ちと同じもの」を載せるので、ホームで探すときに同じ言葉で見つかる。言い換えの表を 2 つ持たない |
| 2 | §5.4 の決まり 6（件名は最後に提出した中身）は 3a のお知らせの決まり | まとめメールの件名・申請者・申請部門は**ホームと同じ今の値** | 差戻し中の申請の催促が届くのは申請者本人だけで、ほかの人に直しかけを見せる場面が無い（D26 の考えに当たらない）。ホームの表示と同じになる |
| 3 | §5.8「今日の分をもう送った（approval_reminder_runs に今日の行を入れられなければ送らない）」を 3 つ目の確かめに | 先に読まず、今日の行を入れる操作そのもので決める | 読みと入れるのあいだを空けない。1 つの道で済む |
| 4 | §5.9 の「前回の催促（3 人・5 件）」 | 送る相手がいない日も 0 人・0 件で記録する | 催促が毎朝動いていることが ⑫ で分かる |
| 5 | §5.1「催促のコマンド・全員分の対応待ち・送る日の判定」（名前は仮） | `ApprovalRemindCommand`（`approvals:remind`）・`PendingWork::everyone()`・`ReminderCalendar`・`ApprovalReminderMail`・`ApprovalHoliday`・`ApprovalReminderRun`・`HolidayController` | §5.1 の「名前は仮。実装計画で決める」の範囲 |

### 0.11 受け入れた隙間

| # | 隙間 | 起きたとき | 塞ぐなら |
|---|---|---|---|
| 1 | 無効の人・メールの無い人・許可外のドメインの人の対応待ちは、催促が誰にも届かない | その申請はホームの対応待ちと ⑩ には出る（決裁の管理者が ⑩ で見る。管理者あてのまとめは作らない＝設計書 §8） | 管理者あての「止まっている申請」のまとめ |
| 2 | 審査担当者が多い部門では、`item_count`（のべ）が申請の数より多く見える | ⑫ の「前回の催促」の件数が大きく見える | 申請の数も別に記録する |
| 3 | 祝日が法律で変わったら、部品を新しくするまで判定が古い（D4） | 新しい祝日に催促が届く（決裁は止まらない） | `composer update azuyalabs/yasumi` → `vendor` ごと反映。それまでは ⑫ でその日を登録する |
| 4 | ⑫ の「次に催促を送る日」は、使い始める前でも日付を出す | 「決裁を使い始める前なので、まだ送りません。」を添える | 使い始める前は日付を出さない |
| 5 | 定期実行が 9:00〜9:04 に起動しなかった日は送らない（D17） | その日の催促は抜け、⑫ の「前回の催促」が前の日のまま | 設計どおり（次の送る日に載る） |

### 0.12 テストの土台

- 3a §0.12 の土台（`BuildsApprovalFixtures`・`ReadsApprovalNotices`・`ParsesForms`）をそのまま使う
- ⚠ **時計を進めるときは UTC にした瞬間を渡す**（`$this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'Asia/Tokyo')->utc())`）。日本時間の Carbon をそのまま渡すと、テストのあいだ Carbon が保存した日時（UTC）を日本時間として読み、番が来た日時が 9 時間ずれる（2026-10-02 に試作で実測。3a の `MailFailureBannerTest` も同じ形。本番では起きない）
- 催促のテストの日付: 2026-10-05（月）の朝 9:00 に流す。10/1（木）に番が来たものは 4 日待ち、10/2（金）は 3 日待ち、10/3（土）は 2 日待ち
- 巻き戻りのテストは、キューを `database` にして、2 通目を積む直前の合図（`Illuminate\Queue\Events\JobQueueing`）で例外を投げ、今日の行と `jobs` の行がどちらも残らないことを数える（SQLite でも MySQL でも同じに動く。3a の巻き戻りのテストと同じ考え）
- 設定の変更の記録（JSON の列）は MySQL が項目の並びを入れ替えるので、`ksort()` してから比べる（`HolidaySettingsTest::byKey()`）
- 本番の定期実行の形は、`Request::create('https://www.mitsuwat.co.jp/system/manage', …, ['SCRIPT_FILENAME' => '/system/manage', 'SCRIPT_NAME' => '/system/manage'])` を `app('request')` と `URL::setRequest()` に入れて作る（Laravel の `SetRequestForConsole` と同じ形）

## 1. 触るファイル

### 新規

| ファイル | 役目 | Task |
|---|---|---|
| `database/sql/2026-10-02-approval-phase3b.sql`・`database/migrations/2026_10_02_000001_create_approval_phase3b_tables.php` | 表（本番の SQL とテストの鏡） | 1 |
| `app/Models/ApprovalHoliday.php` | 送らない日（期間の比べ方・フォームの値・期間の出し方） | 1・5 |
| `app/Models/ApprovalReminderRun.php` | 催促を送った日 | 1 |
| `app/Support/Approval/ReminderCalendar.php` | 送る日の判定・次に送る日 | 2 |
| `app/Console/Commands/ApprovalRemindCommand.php`・`app/Mail/ApprovalReminderMail.php`・`resources/views/mail/approval-reminder.blade.php` | 催促のコマンドとまとめメール | 4 |
| `app/Http/Controllers/Approval/HolidayController.php`・`resources/views/approvals/admin/holidays.blade.php` | ⑫ | 5 |
| `tests/Feature/Approval/Phase3/Phase3bTablesTest.php`・`ApprovalHolidayTest.php`・`ReminderCalendarTest.php`・`PendingWorkEveryoneTest.php`・`ApprovalRemindCommandTest.php`・`HolidaySettingsTest.php` | 3b のテスト | 1〜5 |

### 変更

| ファイル | 変えること | Task |
|---|---|---|
| `composer.json`・`composer.lock` | `azuyalabs/yasumi ^2.12` | 2 |
| `app/Support/Approval/PendingWork.php` | `everyone()`・形と並べ方の共有・`for()` を id の順に | 3 |
| `app/Mail/ApprovalMailable.php`・`app/Support/Approval/Notifier.php` | `oneLine()` を土台へ | 4 |
| `config/approval.php`・`routes/console.php` | リンクの元の設定・催促の予定 | 4 |
| `routes/approval.php`・`lang/ja/validation.php`・`resources/views/layouts/partials/sidebar.blade.php`・`sidebar_approval.blade.php`・`resources/views/approvals/home.blade.php`・`home-launched.blade.php` | ⑫ のルート・和名・入口 | 5 |
| テスト: `ClockReadScanTest`・`ScheduleTest`・`ApprovalAdminGateTest`・`LaunchGateTest`・`MailFailureBannerTest`・`ApprovalSidebarTest` | §0.7 のとおり | 2・4・5 |
| `docs/superpowers/specs/2026-09-30-approval-phase3-design.md`・`CLAUDE.md`・`docs/ARCHITECTURE.md`・`routes/web.php`・`docs/BACKLOG.md`・`docs/運用_バックアップとメール.md` | ドキュメント（要件定義書は変えない。15.5 は「詳しい列は実装計画で決める」） | 8 |

## 2. 作業の順番

途中の各段でも、既存のテストを含めて全部が通る状態を保つ（設計書 §10）。順番は設計書 §10 の 3b の案のとおり（1 の「本番の `APP_URL` の読み取り」は計画を書く段階で済ませた。§0.5）。

| Task | 中身 | 設計書 | コミット | 全件（実測） |
|---|---|---|---|---|
| 0 | 作業場所の準備（worktree・vendor・全件が緑） | — | 0 | 3,252 本 |
| 1 | 表（`approval_holidays`・`approval_reminder_runs`）とモデル | §5.3 | 1 | 3,261 本 |
| 2 | 送る日の判定（Yasumi・`ReminderCalendar`） | §5.10 | 1 | 3,268 本 |
| 3 | 全員分の対応待ち（`PendingWork::everyone()`） | §5.8 | 1 | 3,270 本 |
| 4 | 催促のコマンド・予定・まとめメール | §5.8 | 1 | 3,285 本 |
| 5 | 催促の設定（⑫） | §5.9 | 1 | 3,294 本 |
| 6 | 全件テストと変異テスト | §6 | 0〜1 | — |
| 7 | 手元のブラウザでの確認と、利用者に見せる画面の写真とメールの文面 | §6 | 0〜1 | — |
| 8 | ドキュメント（設計書への書き戻しを含む） | §7 の 6 | 2 | — |
| 9 | 本番反映（利用者の了承を取ってから・親の会話が行う） | §7 | 1 | — |

---

## Task 0: 作業場所の準備

**作業場所**: worktree `/Users/masanori/site/manage/.claude/worktrees/approval-phase3`（ブランチ `approval-phase3`。3a と同じ worktree を使う＝利用者の決定・dev の vendor の置き場。この計画のコミットの親は `91fe7969`＝`13.x`〈3a の本番反映の記録 `7ae25fa3` に、別の会話の周辺ビルのテナント明細の修正が載ったもの。計画を書く段階で早送りした〉）。**main repo では作業もテストもしない**（main repo の vendor は `--no-dev` で phpunit が無い。dev 依存を入れると `./deploy.sh` が本番へ送る）。

- [ ] **Step 1: 並行の作業を確かめる**（ほかの会話が同じ課題を進めていないか）

```bash
cd /Users/masanori/site/manage && git status --short --branch && git log --oneline -5 && git worktree list && git for-each-ref --sort=-committerdate --format='%(refname:short) %(committerdate:short) %(subject)' refs/heads | head && git log --oneline approval-phase3..13.x
```

Expected: worktree `approval-phase3` の先頭がこの計画のコミット。最後のコマンド（`13.x` にあって `approval-phase3` に無いコミット）が空。ほかの worktree（`customer-import-double-submit`・`import-double-submit`）は別の会話のもの（触らない）。⚠ `approval-phase3b`（worktree とブランチ）と `approval-phase3b-proto`（別の会話の scratchpad の試作）は、**別の会話が同じ 3b を並行して作り始めたもの**で、利用者の決定（2026-10-02）でこの計画の会話が続けることになり、相手には止まってもらった。**消さない・触らない**（片付けは利用者に聞いてから）。ほかにも段階3 の名前の付いた枝や worktree があれば、中身を読み、消さずに利用者へ報告して止まる。`13.x` が進んでいたら止まり、利用者に取り込むかを聞く（取り込むなら WT で `git merge 13.x`。rebase しない）。

- [ ] **Step 2: vendor を確かめる**（dev 依存あり・実体）

⚠ **symlink にしない**（autoload が symlink の先を読み、別の場所のコードでテストが流れる。Bug #50）。

```bash
WT=/Users/masanori/site/manage/.claude/worktrees/approval-phase3; test -x "$WT/vendor/bin/phpunit" && ! test -L "$WT/vendor" && echo "vendor OK"
```

Expected: `vendor OK`

- [ ] **Step 3: 差分のファイルを確かめる**

```bash
ls ~/.claude/plans/approval-phase3-tasks/3b/patches/ && cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --check ~/.claude/plans/approval-phase3-tasks/3b/patches/0001-*.patch && echo "0001 を当てられる"
```

Expected: `0001-…` 〜 `0005-…` の 5 本と `0001 を当てられる`。無ければ計画のコードを打ち込む（中身は同じ）。

- [ ] **Step 4: 全件テストが通る状態から始める**

**テストの流し方**（以下すべての Task で同じ。`APP_KEY` は 32 バイトの本物の鍵を渡す。worktree に `.env` を作らない）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `OK (3252 tests, 23239 assertions)`（2026-10-02 の実測。約 2.5 分）。赤があれば 3b の作業の前に利用者へ報告して止まる。

⚠ 以下の Task の「テストを流す」は、すべてこの形でファイルを並べたもの。`cd` はコマンドごとに書く（ターンをまたぐと cwd が main repo へ戻る）。git は `git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 …` で呼ぶ。

⚠ コミットのたびに `git status --porcelain` が空であることを確かめる。差分のファイルを当てたときは、`git diff --stat` が各 Task の「差分の大きさ」と同じことも確かめる。


---

## Task 1: 表（`approval_holidays`・`approval_reminder_runs`）とモデル

催促を送らない日と、催促を送った日の 2 表を、本番の SQL とテストの migration の対で足す（設計書 §5.3・§0.2）。モデルの `ApprovalHoliday` は期間の比べ方（その年だけは日付の範囲・毎年は月と日だけ・年をまたぐ期間）を持つ。

**Files:**
- Create: `database/sql/2026-10-02-approval-phase3b.sql`・`database/migrations/2026_10_02_000001_create_approval_phase3b_tables.php`・`app/Models/ApprovalHoliday.php`・`app/Models/ApprovalReminderRun.php`
- Test: Create `tests/Feature/Approval/Phase3/Phase3bTablesTest.php`・`tests/Feature/Approval/Phase3/ApprovalHolidayTest.php`

**Interfaces:**
- Produces: `ApprovalHoliday`（`start_date`・`end_date` は date キャスト・`repeats_yearly` は bool・`description`）: `scopeOrdered()`（開始日の順）・`covers(CarbonInterface $day): bool`（日本の暦の日付を渡す）。Task 2・5 が使う（Task 5 で `formValues()`・`periodLabel()` を足す）
- Produces: `ApprovalReminderRun`（`sent_on` は date キャスト・`recipient_count`・`item_count`・`UPDATED_AT = null`）: `latestRun(): ?self`。Task 2・4・5 が使う

**差分の大きさ:** 6 ファイル・+346 / −0 行（差分のファイル `0001-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase3/Phase3bTablesTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase3;

use App\Models\ApprovalReminderRun;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 段階3（3b）の表（段階3 設計書 §5.3）。
 *
 * ⚠ 本番は SQL を手で流し、テストは migration で作る。**両方が食い違わないこと**をここで固定する（Phase3TablesTest と同じ考え）。
 *   比べるのは、作る表・列の名前・NULL を許すか・一意の索引。**両方向に比べる**。
 *   Phase2TablesTest の型の正規表現は DATE を拾わないので（段階3 設計書 §4.5）、3b の表はこちらで見る。
 */
class Phase3bTablesTest extends TestCase
{
    use RefreshDatabase;

    private const SQL = 'database/sql/2026-10-02-approval-phase3b.sql';

    private const MIGRATION = 'database/migrations/2026_10_02_000001_create_approval_phase3b_tables.php';

    private const TYPES = 'BIGINT|INT|SMALLINT|TINYINT|CHAR|VARCHAR|MEDIUMTEXT|TEXT|JSON|TIMESTAMP|DATE';

    /** @return array<string, string> 表 => CREATE の括弧の中 */
    private function creates(): array
    {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(base_path(self::SQL)));
        preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\n\) ENGINE/s', $sql, $creates, PREG_SET_ORDER);

        $tables = [];
        foreach ($creates as [, $table, $body]) {
            $tables[$table] = $body;
        }

        return $tables;
    }

    public function test_the_sql_and_the_migration_create_the_same_tables(): void
    {
        preg_match_all("/Schema::create\\('(\\w+)'/", file_get_contents(base_path(self::MIGRATION)), $matches);

        $this->assertEqualsCanonicalizing(['approval_holidays', 'approval_reminder_runs'], array_keys($this->creates()));
        $this->assertEqualsCanonicalizing(array_keys($this->creates()), $matches[1]);
    }

    public function test_the_sql_and_the_migration_declare_the_same_columns(): void
    {
        $counts = [];

        foreach ($this->creates() as $table => $body) {
            preg_match_all('/`(\w+)` (?:' . self::TYPES . ')\b([^,]*)/', $body, $columns, PREG_SET_ORDER);
            $sql = [];
            foreach ($columns as [, $column, $definition]) {
                $sql[$column] = ! str_contains($definition, 'NOT NULL');
            }

            $migrated = [];
            foreach (Schema::getColumns($table) as $column) {
                $migrated[$column['name']] = (bool) $column['nullable'];
            }

            ksort($sql);
            ksort($migrated);
            $this->assertSame($sql, $migrated, "{$table} の列（名前と NULL を許すか）が SQL と migration で違う");
            $counts[$table] = count($sql);
        }

        // 空振りで緑にならないように（送らない日 7 列・送った日 5 列）
        $this->assertSame(['approval_holidays' => 7, 'approval_reminder_runs' => 5], $counts);
    }

    /** 同じ日に 2 回送らない守りは、送った日の一意の索引（SQL と migration の両方にあること） */
    public function test_the_sent_date_is_unique_in_the_sql_and_the_migration(): void
    {
        $this->assertStringContainsString('UNIQUE KEY `uq_approval_reminder_runs_sent_on` (`sent_on`)', $this->creates()['approval_reminder_runs']);

        $unique = array_values(array_filter(Schema::getIndexes('approval_reminder_runs'), fn (array $index) => $index['unique'] && ! $index['primary']));
        $this->assertSame([['uq_approval_reminder_runs_sent_on', ['sent_on']]], array_map(fn (array $index) => [$index['name'], $index['columns']], $unique));
    }

    public function test_the_same_day_cannot_be_recorded_twice(): void
    {
        ApprovalReminderRun::create(['sent_on' => '2026-10-01', 'recipient_count' => 1, 'item_count' => 2]);
        ApprovalReminderRun::create(['sent_on' => '2026-10-02', 'recipient_count' => 0, 'item_count' => 0]);

        $this->assertSame('2026-10-02', ApprovalReminderRun::latestRun()->sent_on->format('Y-m-d'));

        $this->expectException(UniqueConstraintViolationException::class);
        ApprovalReminderRun::create(['sent_on' => '2026-10-01', 'recipient_count' => 3, 'item_count' => 4]);
    }
}
```

`tests/Feature/Approval/Phase3/ApprovalHolidayTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase3;

use App\Models\ApprovalHoliday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 送らない日の期間の比べ方（段階3 設計書 §5.10・D19）。
 *
 * ⚠ 渡す日は日本の暦の日付（JapanTime::today() と同じ「UTC の 0:00」の形）。
 */
class ApprovalHolidayTest extends TestCase
{
    use RefreshDatabase;

    private function holiday(string $from, string $to, bool $yearly): ApprovalHoliday
    {
        return ApprovalHoliday::create(['start_date' => $from, 'end_date' => $to, 'repeats_yearly' => $yearly, 'description' => '休み'])->refresh();
    }

    /** @param list<string> $days @return array<string, bool> */
    private function covered(ApprovalHoliday $holiday, array $days): array
    {
        $result = [];
        foreach ($days as $day) {
            $result[$day] = $holiday->covers(Carbon::parse($day));
        }

        return $result;
    }

    public function test_a_one_time_period_covers_its_dates_inclusively(): void
    {
        $this->assertSame(
            ['2026-08-12' => false, '2026-08-13' => true, '2026-08-15' => true, '2026-08-16' => false, '2027-08-14' => false],
            $this->covered($this->holiday('2026-08-13', '2026-08-15', false), ['2026-08-12', '2026-08-13', '2026-08-15', '2026-08-16', '2027-08-14']),
        );
    }

    public function test_a_yearly_period_is_compared_by_month_and_day(): void
    {
        $this->assertSame(
            ['2025-08-14' => true, '2031-08-13' => true, '2031-08-16' => false, '2031-08-12' => false],
            $this->covered($this->holiday('2026-08-13', '2026-08-15', true), ['2025-08-14', '2031-08-13', '2031-08-16', '2031-08-12']),
        );
    }

    /** 年をまたぐ期間（12/29〜1/3）は、開始の月日が終了の月日より後なのでまたぐとみなす（D19） */
    public function test_a_yearly_period_can_cross_the_new_year(): void
    {
        $this->assertSame(
            ['2027-12-28' => false, '2027-12-29' => true, '2027-12-31' => true, '2028-01-01' => true, '2028-01-03' => true, '2028-01-04' => false, '2028-06-30' => false],
            $this->covered($this->holiday('2026-12-29', '2027-01-03', true), ['2027-12-28', '2027-12-29', '2027-12-31', '2028-01-01', '2028-01-03', '2028-01-04', '2028-06-30']),
        );
    }

    /** 2/29 を含む毎年の期間は、うるう年でない年は 2/28 と 3/1 で区切られる */
    public function test_february_the_twenty_ninth_in_a_yearly_period(): void
    {
        $this->assertSame(
            ['2028-02-29' => true, '2029-02-28' => false, '2029-03-01' => false],
            $this->covered($this->holiday('2028-02-29', '2028-02-29', true), ['2028-02-29', '2029-02-28', '2029-03-01']),
        );
        $this->assertSame(
            ['2028-02-29' => true, '2029-02-28' => true, '2029-03-01' => true, '2029-03-02' => false],
            $this->covered($this->holiday('2028-02-28', '2028-03-01', true), ['2028-02-29', '2029-02-28', '2029-03-01', '2029-03-02']),
        );
    }

    public function test_a_single_day(): void
    {
        $this->assertSame(
            ['2026-11-01' => false, '2026-11-02' => true, '2026-11-03' => false],
            $this->covered($this->holiday('2026-11-02', '2026-11-02', false), ['2026-11-01', '2026-11-02', '2026-11-03']),
        );
    }
}
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/3b/patches/0001-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase3/Phase3bTablesTest.php tests/Feature/Approval/Phase3/ApprovalHolidayTest.php
```

Expected: `ERRORS!` `Tests: 9, Assertions: 0, Errors: 9.`

- `ApprovalHolidayTest::test_a_one_time_period_covers_its_dates_inclusively` — `Error: Class "App\Models\ApprovalHoliday" not found`
- `ApprovalHolidayTest::test_a_yearly_period_is_compared_by_month_and_day` — `Error: Class "App\Models\ApprovalHoliday" not found`
- `ApprovalHolidayTest::test_a_yearly_period_can_cross_the_new_year` — `Error: Class "App\Models\ApprovalHoliday" not found`
- `ApprovalHolidayTest::test_february_the_twenty_ninth_in_a_yearly_period` — `Error: Class "App\Models\ApprovalHoliday" not found`
- `ApprovalHolidayTest::test_a_single_day` — `Error: Class "App\Models\ApprovalHoliday" not found`
- `Phase3bTablesTest::test_the_sql_and_the_migration_create_the_same_tables` — `ErrorException: file_get_contents(/Users/masanori/site/manage/.claude/worktrees/approval-phase3/database/migratio`
- `Phase3bTablesTest::test_the_sql_and_the_migration_declare_the_same_columns` — `ErrorException: file_get_contents(/Users/masanori/site/manage/.claude/worktrees/approval-phase3/database/sql/2026`
- `Phase3bTablesTest::test_the_sent_date_is_unique_in_the_sql_and_the_migration` — `ErrorException: file_get_contents(/Users/masanori/site/manage/.claude/worktrees/approval-phase3/database/sql/2026`
- `Phase3bTablesTest::test_the_same_day_cannot_be_recorded_twice` — `Error: Class "App\Models\ApprovalReminderRun" not found`

- [ ] **Step 3: 表を書く**（本番の SQL とテストの鏡）

`database/sql/2026-10-02-approval-phase3b.sql`（新規）

```sql
-- 決裁申請 段階3（3b）— 2026-10-02
--
-- 設計書: docs/superpowers/specs/2026-09-30-approval-phase3-design.md §5.3・§5.8〜§5.10
--
-- ⚠ database/migrations/2026_10_02_000001_create_approval_phase3b_tables.php と
--   対で維持すること（あちらは SQLite のテストのための鏡。Phase3bTablesTest が見る）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** 新しいコードは approval_holidays と approval_reminder_runs を読むので、
--   コードを先に送ると、催促の設定（⑫）の画面と朝の催促のコマンドが Unknown table で止まる。
--
-- ⚠ 索引名は段階1・2・3a と同じ流儀（一意は uq_）。照合順序は utf8mb4_unicode_ci。
--
-- 適用: 3a と同じく php artisan tinker --execute で DB::statement() に **1 文ずつ**流す。
--   先頭で「approval_holidays か approval_reminder_runs がすでにあれば 1 文も流さずに止まる」確認をする（計画 Task 9）。

-- 1. 催促を送らない日（⑫・要件 8.3。毎年繰り返すものは月と日だけで比べる。D19）
CREATE TABLE `approval_holidays` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `start_date` DATE NOT NULL COMMENT '開始日',
  `end_date` DATE NOT NULL COMMENT '終了日（開始日と同じか後）',
  `repeats_yearly` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '毎年繰り返すか（1 なら月と日だけで比べる）',
  `description` VARCHAR(50) NOT NULL COMMENT '説明（例: 年末年始）',
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 催促を送った日（1 日 1 行。一意の日付で、同じ日に 2 回送らない。⑫ の「前回の催促」にも使う）
CREATE TABLE `approval_reminder_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sent_on` DATE NOT NULL COMMENT '催促を送った日（日本の日付）',
  `recipient_count` INT UNSIGNED NOT NULL COMMENT '催促のメールを送った人数',
  `item_count` INT UNSIGNED NOT NULL COMMENT '催促した申請の件数（のべ。1 通に載せきれなかった分も数える）',
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_reminder_runs_sent_on` (`sent_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`database/migrations/2026_10_02_000001_create_approval_phase3b_tables.php`（新規）

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 決裁申請 段階3（3b）の表（段階3 設計書 §5.3）。
 *
 * ⚠ **これは SQLite のテストのための鏡**。本番は `database/sql/2026-10-02-approval-phase3b.sql` を
 *   手で流す（このプロジェクトは migration で本番を管理していない）。**両方を対で維持すること**（Phase3bTablesTest が見る）。
 * ⚠ 索引名は本番の SQL と同じ名前を渡す（段階1・2・3a と同じ流儀）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('start_date')->comment('開始日');
            $table->date('end_date')->comment('終了日（開始日と同じか後）');
            $table->boolean('repeats_yearly')->default(false)->comment('毎年繰り返すか（1 なら月と日だけで比べる）');
            $table->string('description', 50)->comment('説明（例: 年末年始）');
            $table->timestamps();
        });

        Schema::create('approval_reminder_runs', function (Blueprint $table) {
            $table->id();
            $table->date('sent_on')->comment('催促を送った日（日本の日付）');
            $table->unsignedInteger('recipient_count')->comment('催促のメールを送った人数');
            $table->unsignedInteger('item_count')->comment('催促した申請の件数（のべ。1 通に載せきれなかった分も数える）');
            $table->timestamp('created_at')->nullable();

            $table->unique('sent_on', 'uq_approval_reminder_runs_sent_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_reminder_runs');
        Schema::dropIfExists('approval_holidays');
    }
};
```

- [ ] **Step 4: モデルを書く**

`app/Models/ApprovalHoliday.php`（新規）

```php
<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 催促を送らない日（⑫・要件 8.3。段階3 設計書 §5.9・§5.10）。土日と日本の祝日は ReminderCalendar が自動で見るので、
 * ここには会社の休み（年末年始・夏季休暇など）を登録する。
 *
 * ⚠ 毎年繰り返すものは**月と日だけ**で比べる（D19）。開始の月日が終了の月日より後なら年をまたぐとみなす（12/29〜1/3）。
 * ⚠ 毎年繰り返すものは 1 年より短い（終了日は開始日の 1 年後より前。HolidayController が断る）。1 年まるごとの期間を
 *   月と日で比べると、始まりと終わりの月日が隣り合ったり重なったりして、どの日が入るかが分かりにくくなるため。
 */
class ApprovalHoliday extends Model
{
    protected $fillable = ['start_date', 'end_date', 'repeats_yearly', 'description'];

    protected function casts(): array
    {
        return [
            'start_date'     => 'date',
            'end_date'       => 'date',
            'repeats_yearly' => 'boolean',
        ];
    }

    /** 一覧の並び（開始日の順） */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('start_date')->orderBy('id');
    }

    /**
     * その日（日本の暦の日付。JapanTime::today() と同じ形）がこの期間に入るか。
     *
     * ⚠ 日付どうしは 'Y-m-d'・'md' の文字列で比べる（date キャストの属性は UTC の 0:00、渡す日も UTC の 0:00 の
     *   日本の日付なので、時刻やタイムゾーンを持ち込まない）
     */
    public function covers(CarbonInterface $day): bool
    {
        if (! $this->repeats_yearly) {
            $date = $day->format('Y-m-d');

            return $this->start_date->format('Y-m-d') <= $date && $date <= $this->end_date->format('Y-m-d');
        }

        $monthDay = $day->format('md');
        $from     = $this->start_date->format('md');
        $to       = $this->end_date->format('md');

        return $from <= $to
            ? $from <= $monthDay && $monthDay <= $to
            : $monthDay >= $from || $monthDay <= $to;
    }
}
```

`app/Models/ApprovalReminderRun.php`（新規）

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 催促を送った日の記録（1 日 1 行。段階3 設計書 §5.3・§5.8）。
 *
 * ⚠ `sent_on` は一意。同じ日に 2 回送らない守りはこの一意の日付（ApprovalRemindCommand が、この行を入れることと
 *   まとめメールを積むことを 1 つのトランザクションで行う）。⑫ の「前回の催促」にも使う。
 * ⚠ 書くのは催促のコマンドだけ（画面からは書かない）。更新日時の列は持たない。
 */
class ApprovalReminderRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['sent_on', 'recipient_count', 'item_count'];

    protected function casts(): array
    {
        return [
            'sent_on'         => 'date',
            'recipient_count' => 'integer',
            'item_count'      => 'integer',
        ];
    }

    /** いちばん新しい催促（無ければ null） */
    public static function latestRun(): ?self
    {
        return static::query()->orderByDesc('sent_on')->first();
    }
}
```

（差分のファイルを使うなら: `git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/3b/patches/0001-*.patch`）

- [ ] **Step 5: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (9 tests, …)`

- [ ] **Step 6: 全件を流す**

Expected: `OK (3261 tests, 23258 assertions)`

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add database/sql/2026-10-02-approval-phase3b.sql database/migrations/2026_10_02_000001_create_approval_phase3b_tables.php app/Models/ApprovalHoliday.php app/Models/ApprovalReminderRun.php tests/Feature/Approval/Phase3/Phase3bTablesTest.php tests/Feature/Approval/Phase3/ApprovalHolidayTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 催促を送らない日と催促を送った日の表を足す

approval_holidays（送らない日。毎年繰り返すものは月と日だけで比べ、年を
またぐ期間も 1 件で持つ）と approval_reminder_runs（催促を送った日。一意の
日付で同じ日に 2 回送らない）を、本番の SQL とテストの migration の対で足す
（段階3 設計書 §5.3）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 2: 送る日の判定（Yasumi・`ReminderCalendar`）

送らない日（土曜・日曜・日本の祝日・⑫ で登録した日）の判定と、次に送る日を 1 か所に作る（設計書 §5.10・§0.3）。日本の祝日は部品 Yasumi が計算する（振替休日・国民の休日を含む）。テストは 2026〜2027 年の 730 日を、内閣府の祝日の一覧と土日で 1 日ずつ突き合わせる。

**Files:**
- Modify: `composer.json`・`composer.lock`（`azuyalabs/yasumi ^2.12`）
- Create: `app/Support/Approval/ReminderCalendar.php`
- Test: Create `tests/Feature/Approval/Phase3/ReminderCalendarTest.php`・Modify `tests/Feature/ClockReadScanTest.php`（`ReminderCalendar.php` の 1 件を理由つきで登録）

**Interfaces:**
- Consumes: `ApprovalHoliday::ordered()`・`covers()`・`ApprovalReminderRun`（Task 1）・`JapanTime::today()`・`format()`（既存）
- Produces: `new ReminderCalendar()`: `reasonNotToSend(CarbonInterface $day): ?string`（送る日なら null）・`isSendDay(CarbonInterface $day): bool`・`nextSendDayAfter(CarbonInterface $day): ?Carbon`・`nextSendDay(): ?Carbon`・`static label(CarbonInterface $day): string`（「10/1（木）」）・`SEND_UNTIL = '09:05'`。Task 4（コマンド）・Task 5（⑫）が使う

**差分の大きさ:** 5 ファイル・+359 / −1 行（差分のファイル `0002-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase3/ReminderCalendarTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase3;

use App\Models\ApprovalHoliday;
use App\Models\ApprovalReminderRun;
use App\Support\Approval\ReminderCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 催促を送る日の判定（段階3 設計書 §5.10・§6 の「催促」・D4・D17・D19）。
 */
class ReminderCalendarTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 内閣府「国民の祝日について」の syukujitsu.csv（2026-10-02 に取得）の 2026〜2027 年。
     * 「休日」は振替休日と国民の休日（祝日に挟まれた日）。
     */
    private const CABINET_OFFICE = [
        '2026-01-01', '2026-01-12', '2026-02-11', '2026-02-23', '2026-03-20', '2026-04-29', '2026-05-03', '2026-05-04',
        '2026-05-05', '2026-05-06', '2026-07-20', '2026-08-11', '2026-09-21', '2026-09-22', '2026-09-23', '2026-10-12',
        '2026-11-03', '2026-11-23',
        '2027-01-01', '2027-01-11', '2027-02-11', '2027-02-23', '2027-03-21', '2027-03-22', '2027-04-29', '2027-05-03',
        '2027-05-04', '2027-05-05', '2027-07-19', '2027-08-11', '2027-09-20', '2027-09-23', '2027-10-11', '2027-11-03',
        '2027-11-23',
    ];

    private function day(string $date): Carbon
    {
        return Carbon::parse($date);
    }

    private function holiday(string $from, string $to, bool $yearly, string $description): void
    {
        ApprovalHoliday::create(['start_date' => $from, 'end_date' => $to, 'repeats_yearly' => $yearly, 'description' => $description]);
    }

    /** 2026〜2027 年の毎日を、内閣府の祝日の一覧と土日で突き合わせる（部品の祝日の中身を確かめる。D4） */
    public function test_every_day_of_2026_and_2027_matches_the_cabinet_office_list_and_weekends(): void
    {
        $calendar  = new ReminderCalendar();
        $holidays  = array_flip(self::CABINET_OFFICE);
        $day       = Carbon::parse('2026-01-01');
        $sendDays  = 0;
        $problems  = [];

        while ($day->format('Y') !== '2028') {
            $date     = $day->format('Y-m-d');
            $reason   = $calendar->reasonNotToSend($day);
            $expected = match (true) {
                $day->isSaturday()            => '土曜日',
                $day->isSunday()              => '日曜日',
                isset($holidays[$date])       => '祝日',
                default                       => null,
            };

            if ($expected === null ? $reason !== null : ! str_starts_with((string) $reason, $expected)) {
                $problems[] = "{$date}: {$reason}（期待: " . ($expected ?? '送る日') . '）';
            }
            $sendDays += $reason === null ? 1 : 0;
            $day->addDay();
        }

        $this->assertSame([], $problems);
        // 空振りで緑にならないように（2 年の平日 522 日から、平日に当たる祝日 33 日〈2026 年 17・2027 年 16〉を引く）
        $this->assertSame(489, $sendDays);
    }

    public function test_the_reasons_name_the_weekday_or_the_holiday(): void
    {
        $calendar = new ReminderCalendar();

        $this->assertSame('土曜日', $calendar->reasonNotToSend($this->day('2026-10-03')));
        $this->assertSame('日曜日', $calendar->reasonNotToSend($this->day('2026-10-04')));
        $this->assertNull($calendar->reasonNotToSend($this->day('2026-10-05')));
        $this->assertSame('祝日（スポーツの日）', $calendar->reasonNotToSend($this->day('2026-10-12')));
        $this->assertSame('祝日（振替休日 (憲法記念日)）', $calendar->reasonNotToSend($this->day('2026-05-06')));
        $this->assertSame('祝日（国民の休日）', $calendar->reasonNotToSend($this->day('2026-09-22')));
    }

    /** ⑫ で登録した送らない日（年をまたぐ毎年の期間・その年だけの期間） */
    public function test_the_registered_days_off_are_not_send_days(): void
    {
        $this->holiday('2026-12-29', '2027-01-03', true, '年末年始');
        $this->holiday('2026-08-13', '2026-08-14', false, '夏季休暇');
        $calendar = new ReminderCalendar();

        $this->assertSame('送らない日（年末年始）', $calendar->reasonNotToSend($this->day('2026-12-29')));
        $this->assertSame('送らない日（年末年始）', $calendar->reasonNotToSend($this->day('2031-12-30')));
        $this->assertNull($calendar->reasonNotToSend($this->day('2026-12-28')));
        $this->assertNull($calendar->reasonNotToSend($this->day('2027-01-04')));
        $this->assertSame('送らない日（夏季休暇）', $calendar->reasonNotToSend($this->day('2026-08-13')));
        $this->assertNull($calendar->reasonNotToSend($this->day('2027-08-13')));
        // 祝日と重なれば祝日を先に言う
        $this->assertSame('祝日（元日）', $calendar->reasonNotToSend($this->day('2027-01-01')));
    }

    public function test_the_next_send_day_after_skips_weekends_holidays_and_days_off(): void
    {
        $this->holiday('2026-12-29', '2027-01-03', true, '年末年始');
        $calendar = new ReminderCalendar();

        // 金曜の次は、月曜のスポーツの日を飛ばして火曜
        $this->assertSame('2026-10-13', $calendar->nextSendDayAfter($this->day('2026-10-09'))->format('Y-m-d'));
        // 年末の月曜の次は、年末年始と土日を飛ばして 1/4
        $this->assertSame('2027-01-04', $calendar->nextSendDayAfter($this->day('2026-12-28'))->format('Y-m-d'));
        // 送る日の次の日が送る日なら、その日（その日自身は数えない）
        $this->assertSame('2026-10-06', $calendar->nextSendDayAfter($this->day('2026-10-05'))->format('Y-m-d'));
    }

    public function test_there_is_no_next_send_day_when_a_year_is_blocked(): void
    {
        $this->holiday('2026-10-01', '2028-12-31', false, '長い休み');

        $this->assertNull((new ReminderCalendar())->nextSendDayAfter($this->day('2026-10-02')));
    }

    /** ⑫ の「次に催促を送る日」: 今日の 9:05 より前で、今日の分をまだ送っていなければ今日（D17） */
    public function test_the_next_send_day_is_today_only_before_five_past_nine_and_before_sending(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:59:59', 'Asia/Tokyo')->utc());
        $this->assertSame('2026-10-05', (new ReminderCalendar())->nextSendDay()->format('Y-m-d'));

        // 日本時間の朝（UTC ではまだ前の日の夜）でも、日本の今日で決める
        $this->travelTo(CarbonImmutable::parse('2026-10-04 23:30:00', 'UTC'));
        $this->assertSame('2026-10-05', (new ReminderCalendar())->nextSendDay()->format('Y-m-d'));

        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:05:00', 'Asia/Tokyo')->utc());
        $this->assertSame('2026-10-06', (new ReminderCalendar())->nextSendDay()->format('Y-m-d'));

        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:01:00', 'Asia/Tokyo')->utc());
        ApprovalReminderRun::create(['sent_on' => '2026-10-05', 'recipient_count' => 1, 'item_count' => 1]);
        $this->assertSame('2026-10-06', (new ReminderCalendar())->nextSendDay()->format('Y-m-d'));

        // 日本時間の朝（UTC ではまだ前の日）に今日の行がもうあれば、明日（日本の今日の行で見る）
        $this->travelTo(CarbonImmutable::parse('2026-10-06 08:30:00', 'Asia/Tokyo')->utc());
        ApprovalReminderRun::create(['sent_on' => '2026-10-06', 'recipient_count' => 1, 'item_count' => 1]);
        $this->assertSame('2026-10-07', (new ReminderCalendar())->nextSendDay()->format('Y-m-d'));

        // 土曜の朝は、次の月曜
        $this->travelTo(CarbonImmutable::parse('2026-10-03 07:00:00', 'Asia/Tokyo')->utc());
        $this->assertSame('2026-10-05', (new ReminderCalendar())->nextSendDay()->format('Y-m-d'));
    }

    public function test_the_label_has_the_japanese_weekday(): void
    {
        $this->assertSame('10/1（木）', ReminderCalendar::label($this->day('2026-10-01')));
        $this->assertSame('1/4（月）', ReminderCalendar::label($this->day('2027-01-04')));

        ApprovalReminderRun::create(['sent_on' => '2026-10-05', 'recipient_count' => 1, 'item_count' => 1]);
        $this->assertSame('10/5（月）', ReminderCalendar::label(ApprovalReminderRun::latestRun()->sent_on));
    }
}
```

`tests/Feature/ClockReadScanTest.php`（変更）

```diff
--- a/tests/Feature/ClockReadScanTest.php
+++ b/tests/Feature/ClockReadScanTest.php
@@ -58,6 +58,7 @@ class ClockReadScanTest extends TestCase
         'app/Http/Controllers/Approval/RequestAttachmentController.php' => [1, 'approval_attachments.removed_at は TIMESTAMP 列（外した瞬間を UTC で保存する）'],
         'app/Models/ApprovalNotice.php'                       => [1, 'notifications.read_at は TIMESTAMP 列（既読にした瞬間を UTC で保存する）'],
         'app/Support/Approval/MailDelivery.php'               => [2, '決裁のメールが送れた・送れなかった瞬間（approval_settings の TIMESTAMP 列。帯では JapanTime で日本時間に直して出す）'],
+        'app/Support/Approval/ReminderCalendar.php'           => [1, '今の瞬間が日本時間の 9:05（催促を送る時刻の終わり）より前か（瞬間どうしを比べる。日付は JapanTime::today()）'],
     ];
 
     /** @return list<array{int, string}> [行, 呼び出し] */
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/3b/patches/0002-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase3/ReminderCalendarTest.php tests/Feature/ClockReadScanTest.php
```

Expected: `ERRORS!` `Tests: 12, Assertions: 64, Errors: 7, Failures: 1.`

- `ReminderCalendarTest::test_every_day_of_2026_and_2027_matches_the_cabinet_office_list_and_weekends` — `Error: Class "App\Support\Approval\ReminderCalendar" not found`
- `ReminderCalendarTest::test_the_reasons_name_the_weekday_or_the_holiday` — `Error: Class "App\Support\Approval\ReminderCalendar" not found`
- `ReminderCalendarTest::test_the_registered_days_off_are_not_send_days` — `Error: Class "App\Support\Approval\ReminderCalendar" not found`
- `ReminderCalendarTest::test_the_next_send_day_after_skips_weekends_holidays_and_days_off` — `Error: Class "App\Support\Approval\ReminderCalendar" not found`
- `ReminderCalendarTest::test_there_is_no_next_send_day_when_a_year_is_blocked` — `Error: Class "App\Support\Approval\ReminderCalendar" not found`
- `ReminderCalendarTest::test_the_next_send_day_is_today_only_before_five_past_nine_and_before_sending` — `Error: Class "App\Support\Approval\ReminderCalendar" not found`
- `ReminderCalendarTest::test_the_label_has_the_japanese_weekday` — `Error: Class "App\Support\Approval\ReminderCalendar" not found`
- `ClockReadScanTest::test_php_clock_reads_are_classified` — `時計の読み取りが分類と合わない:`

- [ ] **Step 3: 部品の名前と判定の部品を書く**

`composer.json`（変更）

```diff
--- a/composer.json
+++ b/composer.json
@@ -8,6 +8,7 @@
     "require": {
         "php": "^8.2",
         "aws/aws-sdk-php": "^3.337",
+        "azuyalabs/yasumi": "^2.12",
         "chillerlan/php-qrcode": "^6.0",
         "laravel/framework": "^12.0",
         "laravel/tinker": "^2.10.1",
```

`composer.lock`（変更）

```diff
--- a/composer.lock
+++ b/composer.lock
@@ -4,7 +4,7 @@
         "Read more about it at https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies",
         "This file is @generated automatically"
     ],
-    "content-hash": "2119a26f39f1b8a4616156a93f5cf323",
+    "content-hash": "bcfc8a02f2643a9367797ddee61c7cfe",
     "packages": [
         {
             "name": "aws/aws-crt-php",
@@ -157,6 +157,80 @@
             },
             "time": "2026-09-10T18:07:33+00:00"
         },
+        {
+            "name": "azuyalabs/yasumi",
+            "version": "2.12.0",
+            "source": {
+                "type": "git",
+                "url": "https://github.com/azuyalabs/yasumi.git",
+                "reference": "24727701cd50b1177e39b3e20ed764fb0943bfb3"
+            },
+            "dist": {
+                "type": "zip",
+                "url": "https://api.github.com/repos/azuyalabs/yasumi/zipball/24727701cd50b1177e39b3e20ed764fb0943bfb3",
+                "reference": "24727701cd50b1177e39b3e20ed764fb0943bfb3",
+                "shasum": ""
+            },
+            "require": {
+                "ext-json": "*",
+                "php": ">=8.2"
+            },
+            "require-dev": {
+                "azuyalabs/php-cs-fixer-config": "^0.3",
+                "ext-intl": "*",
+                "mikey179/vfsstream": "^1.6",
+                "phpstan/phpstan": "^2.2",
+                "phpstan/phpstan-deprecation-rules": "^2.0",
+                "phpunit/phpunit": "^11.5",
+                "rector/rector": "^2.6"
+            },
+            "suggest": {
+                "ext-calendar": "For calculating the date of Easter"
+            },
+            "type": "library",
+            "autoload": {
+                "psr-4": {
+                    "Yasumi\\": "src/Yasumi/"
+                }
+            },
+            "notification-url": "https://packagist.org/downloads/",
+            "license": [
+                "MIT"
+            ],
+            "authors": [
+                {
+                    "name": "Sacha Telgenhof",
+                    "email": "me@sachatelgenhof.com",
+                    "homepage": "https://www.sachatelgenhof.com",
+                    "role": "Maintainer"
+                }
+            ],
+            "description": "The easy PHP Library for calculating holidays",
+            "homepage": "https://www.yasumi.dev",
+            "keywords": [
+                "Bank",
+                "calculation",
+                "calendar",
+                "celebration",
+                "date",
+                "holiday",
+                "holidays",
+                "national",
+                "time"
+            ],
+            "support": {
+                "docs": "https://www.yasumi.dev",
+                "issues": "https://github.com/azuyalabs/yasumi/issues",
+                "source": "https://github.com/azuyalabs/yasumi"
+            },
+            "funding": [
+                {
+                    "url": "https://www.buymeacoffee.com/sachatelgenhof",
+                    "type": "other"
+                }
+            ],
+            "time": "2026-09-30T14:35:38+00:00"
+        },
         {
             "name": "brick/math",
             "version": "0.14.8",
```

`app/Support/Approval/ReminderCalendar.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Models\ApprovalHoliday;
use App\Models\ApprovalReminderRun;
use App\Support\JapanTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Yasumi\Holiday;
use Yasumi\Yasumi;

/**
 * 催促を送る日の判定（段階3 設計書 §5.10・D4・D17・D19）。送らない日 ＝ 土曜・日曜 ／ 日本の祝日（部品 Yasumi。
 * 振替休日・国民の休日を含む）／ 催促の設定（⑫）で登録した送らない日。
 *
 * ⚠ 日付は日本の暦の日付で渡す（JapanTime::today() と同じ「UTC の 0:00」の形）。Yasumi の isHoliday() は渡した日時の
 *   その時刻帯での年月日で比べ、年をまたいだ日は false を返す（2026-10-02 に実測）ので、年ごとに祝日の表を作り、
 *   'Y-m-d' の文字列で引く。
 * ⚠ 祝日の中身は部品が計算する（Yasumi 2.12.0 の 2025〜2027 年は、内閣府の「国民の祝日」の一覧と 1 日も違わないことを
 *   2026-10-02 に確かめた）。法律で祝日が変わったら部品を新しくする（D4）。年末年始・会社の休業日は⑫で登録する。
 * ⚠ 1 つのインスタンスの中では、登録した送らない日は 1 回だけ読み、祝日の表は年ごとに 1 回だけ作る（次に送る日を探すときに
 *   日ごとに読まない）。登録を変えたあとに同じインスタンスで判定し直さない。
 */
final class ReminderCalendar
{
    /** 定期実行が催促を送る時刻の終わり（9:00〜9:04 の起動。D17）。これより後は、その日の分はもう送られない */
    public const SEND_UNTIL = '09:05';

    /** 次に送る日を探す範囲（日数）。これより先に見つからなければ「ない」 */
    private const SEARCH_DAYS = 366;

    private const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    /** @var Collection<int, ApprovalHoliday>|null */
    private ?Collection $registered = null;

    /** @var array<int, array<string, string>> 年 => ['Y-m-d' => 祝日の名前] */
    private array $nationalHolidays = [];

    /** 送らないわけ（送る日なら null）。例: 「土曜日」「祝日（敬老の日）」「送らない日（年末年始）」 */
    public function reasonNotToSend(CarbonInterface $day): ?string
    {
        if ($day->isSaturday()) {
            return '土曜日';
        }

        if ($day->isSunday()) {
            return '日曜日';
        }

        $name = $this->nationalHolidaysOf((int) $day->format('Y'))[$day->format('Y-m-d')] ?? null;
        if ($name !== null) {
            return "祝日（{$name}）";
        }

        $this->registered ??= ApprovalHoliday::ordered()->get();
        $holiday = $this->registered->first(fn (ApprovalHoliday $holiday) => $holiday->covers($day));

        return $holiday === null ? null : "送らない日（{$holiday->description}）";
    }

    public function isSendDay(CarbonInterface $day): bool
    {
        return $this->reasonNotToSend($day) === null;
    }

    /** その日より後（その日は含めない）で最初に送る日。366 日先まで無ければ null */
    public function nextSendDayAfter(CarbonInterface $day): ?Carbon
    {
        $candidate = Carbon::parse($day->format('Y-m-d'));

        for ($i = 0; $i < self::SEARCH_DAYS; $i++) {
            $candidate->addDay();

            if ($this->isSendDay($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * ⑫ の「次に催促を送る日」。今日が送る日で、今日の分をまだ送っておらず、日本時間の 9:05 より前なら今日。
     * そうでなければ明日から数えて最初の送る日（9:00〜9:04 に動かなかった日は送らない。D17）。
     *
     * ⚠ 使い始める前かどうかは見ない（使い始める前は送らないことは、画面が添える）
     */
    public function nextSendDay(): ?Carbon
    {
        $today = JapanTime::today();
        $until = CarbonImmutable::createFromFormat('!Y-m-d H:i', $today->format('Y-m-d') . ' ' . self::SEND_UNTIL, JapanTime::ZONE);

        if (now()->lt($until) && $this->isSendDay($today) && ! ApprovalReminderRun::whereDate('sent_on', $today->format('Y-m-d'))->exists()) {
            return $today;
        }

        return $this->nextSendDayAfter($today);
    }

    /** 「10/1（木）」の形（日本の暦の日付・date キャストの属性を渡す。Bug #61 の決まりで JapanTime::format を通す） */
    public static function label(CarbonInterface $day): string
    {
        return JapanTime::format($day, 'n/j') . '（' . self::WEEKDAYS[(int) JapanTime::format($day, 'w')] . '）';
    }

    /** @return array<string, string> その年の日本の祝日（'Y-m-d' => 名前） */
    private function nationalHolidaysOf(int $year): array
    {
        if (! isset($this->nationalHolidays[$year])) {
            $this->nationalHolidays[$year] = [];

            foreach (Yasumi::create('Japan', $year, 'ja_JP') as $holiday) {
                /** @var Holiday $holiday */
                $this->nationalHolidays[$year][$holiday->format('Y-m-d')] ??= $holiday->getName();
            }
        }

        return $this->nationalHolidays[$year];
    }
}
```

（差分のファイルを使うなら: `git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/3b/patches/0002-*.patch`。`composer.json`・`composer.lock`・`ReminderCalendar.php` の 3 つが入る）

- [ ] **Step 4: 祝日の部品を WT の vendor に入れる**（**WT だけ。main repo では打たない**＝Task 9 で本番用に入れる）

lock のとおりに入れる（`composer require` は使わない。lock と違う版が入りうる）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && pwd && composer install --no-interaction 2>&1 | grep -E 'azuyalabs|Nothing to install|Package operations' ; composer show azuyalabs/yasumi | grep -E '^versions'
```

Expected: `pwd` が WT のパス・`Package operations: 1 install, 0 updates, 0 removals`・`Installing azuyalabs/yasumi (2.12.0)`・`versions : * 2.12.0`。ほかの部品が入れ替わったら（`updates` か `removals` が 0 でない）止まって報告する。`git status --porcelain` に `vendor/` は出ない（git に入らない）。

- [ ] **Step 5: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (12 tests, …)`

- [ ] **Step 6: 全件を流す**

Expected: `OK (3268 tests, 23287 assertions)`

- [ ] **Step 7: コミット**（`vendor/` は git に入らない）

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add composer.json composer.lock app/Support/Approval/ReminderCalendar.php tests/Feature/Approval/Phase3/ReminderCalendarTest.php tests/Feature/ClockReadScanTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 催促を送る日の判定（土日・祝日・送らない日）を足す

土曜・日曜・日本の祝日（部品 azuyalabs/yasumi。振替休日・国民の休日を
含む）・催促の設定で登録した送らない日を判定し、次に催促を送る日を返す
ReminderCalendar を足す（段階3 設計書 §5.10・D4）。2026〜2027 年の毎日を
内閣府の祝日の一覧と突き合わせるテストで守る。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 3: 全員分の対応待ち（`PendingWork::everyone()`）

毎朝の催促に載せる「全員分の対応待ち」を、1 人ずつ `for()` を呼ばずに 1 回の問い合わせでまとめる（設計書 §5.8・§0.4）。規則を 2 か所に育てないよう、テストがいろいろな段階の申請 10 件と 11 人の組み合わせで「全員について `for()` と同じ結果」を突き合わせ、問い合わせの数が人数や申請の数で増えないことも見る。

**Files:**
- Modify: `app/Support/Approval/PendingWork.php`
- Test: Create `tests/Feature/Approval/Phase3/PendingWorkEveryoneTest.php`

**Interfaces:**
- Consumes: 既存の `PendingWork::for()`・`waitingDays()`・`ApprovalSetting::current()`
- Produces: `PendingWork::everyone(): Collection<int 利用者の id, Collection<int, array{request: ApprovalRequest, role: string, action: string, since: ?DateTimeInterface}>>`（`for()` と同じ形・同じ並び。対応待ちが無い人は入らない。有効かどうか・メールは見ない）。Task 4 が使う

**差分の大きさ:** 2 ファイル・+236 / −17 行（差分のファイル `0003-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase3/PendingWorkEveryoneTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase3;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\PendingWork;
use App\Support\Approval\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 全員分の対応待ち（毎朝の催促。段階3 設計書 §5.8・§6 の「全員分の部品と PendingWork::for() の突き合わせ」）。
 *
 * ⚠ 規則をここだけ変えると落ちるように、いろいろな段階の申請と全員の組み合わせで for() と比べる（StepHandlersTest と同じ考え）。
 */
class PendingWorkEveryoneTest extends TestCase
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

    /** @return list<array{int, string, string, ?string}> 申請の id・役割・対応・番が来た日時（比べやすい形） */
    private function keys(Collection $items): array
    {
        return $items->map(fn (array $item) => [$item['request']->id, $item['role'], $item['action'], $item['since']?->format('Y-m-d H:i:s')])->all();
    }

    /**
     * 部門長確認中（そのまま・付け替え・部門長が申請者で省いた審査）・審査中（審査担当者に申請者本人・無効・削除を混ぜる）・
     * 社長決裁待ち（あとで申請者を社長に指定したものを含む）・差戻し中・条件確認待ち・決裁済みの申請と、全員の組み合わせ
     */
    public function test_everyone_gives_each_person_exactly_what_for_gives(): void
    {
        $w        = $this->approvalWorld();
        $admin    = $this->approvalAdmin();
        $other    = $this->baseUser(['name' => '付け替え 先']);
        $second   = $this->baseUser(['name' => '審査 二人目']);
        $inactive = $this->baseUser(['name' => '無効 審査']);
        $deleted  = $this->baseUser(['name' => '削除 審査']);
        $reviewerApplicant = $this->approvalOnlyUser(['name' => '審査 兼 申請']);
        $reviewerApplicant->approvalDepartments()->attach($w['dept']->id);
        $w['reviewDept']->reviewers()->attach([$second->id, $inactive->id, $deleted->id, $reviewerApplicant->id]);
        $inactive->forceFill(['status' => 'inactive'])->save();
        $deleted->delete();
        // 部門長が申請者の申請（部門長の段階を省いて審査へ）と、2 人目の申請者
        $w['head']->approvalDepartments()->attach($w['dept']->id);
        $applicant2 = $this->approvalOnlyUser(['name' => '申請 二郎']);
        $applicant2->approvalDepartments()->attach($w['dept']->id);

        // 番が来た順と段階の id の順を食い違わせる（審査 兼 申請の人は、先に自分の申請が差し戻され〈1 日目〉、あとから審査の番が
        // 来る〈2 日目〉。全員分は段階 → 申請者の番の順に集めるので、番が来た順に並べ替えないと逆の順になる）
        // ⚠ 時計は UTC にしてから渡す（日本時間のまま渡すと、保存した日時を日本時間として読む。計画 §0.12）
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Asia/Tokyo')->utc());
        $this->judge('head', $this->submittedFor(array_merge($w, ['applicant' => $reviewerApplicant])), $w['head'], ApprovalStepResult::Return);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00:00', 'Asia/Tokyo')->utc());

        $this->submittedFor($w);
        $reassigned = $this->submittedFor($w);
        $this->workflow()->reassignHead($reassigned, $admin, $reassigned->lock_version, $other, '出張のため');
        $this->judge('head', $this->submittedFor($w), $w['head']);
        $this->judge('head', $this->submittedFor(array_merge($w, ['applicant' => $reviewerApplicant])), $w['head']);
        $this->submittedFor(array_merge($w, ['applicant' => $w['head']]));
        $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']);
        $this->judge('review', $this->judge('head', $this->submittedFor(array_merge($w, ['applicant' => $applicant2])), $w['head']), $w['reviewer']);
        $this->judge('head', $this->submittedFor($w), $w['head'], ApprovalStepResult::Return);
        $this->judge('president', $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']), $w['president'], ApprovalStepResult::Conditional);
        $this->judge('president', $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']), $w['president']);
        // 社長決裁待ちの申請の申請者を社長に指定する（自分の申請は社長の対応待ちに出ない。D16）
        $this->makePresident($w['applicant']);

        $everyone = PendingWork::everyone();
        $people   = User::withTrashed()->get();
        $this->assertCount(11, $people);

        $compared = 0;
        $items    = 0;
        foreach ($people as $user) {
            // 削除した人は for() を呼ばない（画面に出ない）。全員分では審査担当者として残るが、催促の宛先で絞る
            if ($user->trashed()) {
                continue;
            }

            $expected = $this->keys(PendingWork::for($user));
            $this->assertSame($expected, $this->keys($everyone->get($user->id, collect())), "{$user->name} の対応待ちが for() と違う");
            $compared++;
            $items += count($expected);
        }

        // 空振りで緑にならないように（削除した 1 人を除く 10 人を比べ、対応待ちは合わせて 17 件）
        $this->assertSame(10, $compared);
        $this->assertSame(17, $items);
        // 並べ替えを見る場面になっていること（審査 兼 申請の人は、審査の番より先に自分の差戻しの番が来ている）
        $this->assertSame(['申請者', '審査', '審査'], $everyone->get($reviewerApplicant->id)->pluck('role')->all());
        // 対応待ちの無い人は入らない
        $this->assertFalse($everyone->has($admin->id));
    }

    /** 問い合わせの数は人数・申請の数によらない（1 人ずつ for() を呼ばない） */
    public function test_the_number_of_queries_does_not_grow_with_people_or_requests(): void
    {
        $w = $this->approvalWorld();
        $this->submittedFor($w);
        $this->judge('head', $this->submittedFor($w), $w['head'], ApprovalStepResult::Return);

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            PendingWork::everyone();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $few = $count();

        foreach (range(1, 3) as $n) {
            $w['reviewDept']->reviewers()->attach($this->baseUser(['name' => "審査 {$n}"])->id);
            $this->judge('head', $this->submittedFor($w), $w['head']);
            $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']);
            $this->judge('head', $this->submittedFor($w), $w['head'], ApprovalStepResult::Return);
        }

        $this->assertSame($few, $count());
        $this->assertGreaterThan(5, PendingWork::everyone()->flatten(1)->count());
    }
}
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/3b/patches/0003-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase3/PendingWorkEveryoneTest.php
```

Expected: `ERRORS!` `Tests: 2, Assertions: 0, Errors: 2.`

- `PendingWorkEveryoneTest::test_everyone_gives_each_person_exactly_what_for_gives` — `Error: Call to undefined method App\Support\Approval\PendingWork::everyone()`
- `PendingWorkEveryoneTest::test_the_number_of_queries_does_not_grow_with_people_or_requests` — `Error: Call to undefined method App\Support\Approval\PendingWork::everyone()`

- [ ] **Step 3: 全員分を書く**（1 件の形と並べ方を `for()` と共有し、`for()` を id の順に読む。§0.4・§0.8）

`app/Support/Approval/PendingWork.php`（変更）

```diff
--- a/app/Support/Approval/PendingWork.php
+++ b/app/Support/Approval/PendingWork.php
@@ -7,6 +7,7 @@
 use App\Enums\ApprovalStepStatus;
 use App\Models\ApprovalDepartment;
 use App\Models\ApprovalRequest;
+use App\Models\ApprovalSetting;
 use App\Models\ApprovalStep;
 use App\Models\User;
 use App\Support\JapanTime;
@@ -23,14 +24,82 @@
  */
 final class PendingWork
 {
+    /** 申請者の番の状態（差戻し中・条件確認待ち） */
+    private const OWN_TURN_STATUSES = [ApprovalStatus::Returned->value, ApprovalStatus::Condition->value];
+
     /**
      * @return Collection<int, array{request: ApprovalRequest, role: string, action: string, since: ?DateTimeInterface}>
      */
     public static function for(User $user): Collection
     {
-        $steps = self::waitingSteps($user)->with(['request.applicant', 'request.department', 'request.type'])->get();
+        // ⚠ id の順に読む（同じ日時に番が来たものの並びを決める。並べないと DB が索引の順で返し、everyone() と食い違う）
+        $steps = self::waitingSteps($user)->with(['request.applicant', 'request.department', 'request.type'])->orderBy('id')->get();
+        $own   = self::ownTurns($user)->with(['department', 'type', 'applicant'])->orderBy('id')->get();
+
+        return self::sorted($steps->map(fn (ApprovalStep $step) => self::stepItem($step))
+            ->concat($own->map(fn (ApprovalRequest $request) => self::ownItem($request))));
+    }
+
+    /**
+     * 全員分の対応待ち（毎朝の催促。段階3 設計書 §5.8）。利用者の id ごとに、for() と同じ形・同じ並びで返す
+     * （対応待ちが無い人は入らない）。
+     *
+     * ⚠ 1 人ずつ for() を呼ばない（問い合わせが人数で増える）。問い合わせの数は人数・申請の数によらず一定。
+     * ⚠ 規則は for()（waitingSteps・ownTurns）と同じ: 部門長＝付け替えた人か申請部門の部門長／審査＝その審査部門の
+     *   審査担当者／社長＝今の社長。自分の申請は除く（D16）。全員について for() と同じ結果になることを
+     *   PendingWorkEveryoneTest の突き合わせが守る（規則をここだけ変えると落ちる）。
+     * ⚠ 有効かどうか・削除したか・メールを送れるかは見ない（for() と同じ）。催促の宛先は呼ぶ側が絞る。
+     *
+     * @return Collection<int, Collection<int, array{request: ApprovalRequest, role: string, action: string, since: ?DateTimeInterface}>>
+     */
+    public static function everyone(): Collection
+    {
+        $headOf      = ApprovalDepartment::query()->pluck('head_user_id', 'id');
+        $reviewersOf = DB::table('approval_reviewers')->get(['department_id', 'user_id'])
+            ->groupBy('department_id')
+            ->map(fn (Collection $rows) => $rows->pluck('user_id')->all());
+        $presidentId = ApprovalSetting::current()->president_user_id;
+
+        $items = [];
+
+        $steps = ApprovalStep::query()
+            ->where('status', ApprovalStepStatus::Waiting->value)
+            ->with(['request.applicant', 'request.department', 'request.type'])
+            ->orderBy('id')
+            ->get();
+
+        foreach ($steps as $step) {
+            $handlers = match ($step->kind) {
+                ApprovalStepKind::Head      => [$step->assignee_user_id ?? $headOf[$step->department_id] ?? null],
+                ApprovalStepKind::Review    => $reviewersOf[$step->department_id] ?? [],
+                ApprovalStepKind::President => [$presidentId],
+            };
+
+            // ⚠ 型をそろえて比べる（MySQL の接続の設定しだいで、読んだ id が文字列で来ても自分の申請を除けるように）
+            foreach (array_unique(array_map('intval', array_filter($handlers))) as $userId) {
+                if ($userId !== (int) $step->request->user_id) {
+                    $items[$userId][] = self::stepItem($step);
+                }
+            }
+        }
+
+        $own = ApprovalRequest::query()
+            ->whereIn('status', self::OWN_TURN_STATUSES)
+            ->with(['department', 'type', 'applicant'])
+            ->orderBy('id')
+            ->get();
+
+        foreach ($own as $request) {
+            $items[(int) $request->user_id][] = self::ownItem($request);
+        }
 
-        $items = $steps->map(fn (ApprovalStep $step) => [
+        return collect($items)->map(fn (array $list) => self::sorted(collect($list)));
+    }
+
+    /** @return array{request: ApprovalRequest, role: string, action: string, since: ?DateTimeInterface} */
+    private static function stepItem(ApprovalStep $step): array
+    {
+        return [
             'request' => $step->request,
             'role'    => $step->kind->label(),
             'action'  => match ($step->kind) {
@@ -39,20 +108,24 @@ public static function for(User $user): Collection
                 ApprovalStepKind::President => '決裁',
             },
             'since'   => $step->arrived_at,
-        ]);
-
-        $own = self::ownTurns($user)->with(['department', 'type', 'applicant'])
-            ->get()
-            ->map(fn (ApprovalRequest $request) => [
-                'request' => $request,
-                'role'    => '申請者',
-                'action'  => $request->status === ApprovalStatus::Returned ? '差戻しの対応' : '条件の確認',
-                'since'   => $request->status_changed_at,
-            ]);
-
-        return $items->concat($own)
-            ->sortBy(fn (array $item) => $item['since']?->getTimestamp() ?? PHP_INT_MAX)
-            ->values();
+        ];
+    }
+
+    /** @return array{request: ApprovalRequest, role: string, action: string, since: ?DateTimeInterface} */
+    private static function ownItem(ApprovalRequest $request): array
+    {
+        return [
+            'request' => $request,
+            'role'    => '申請者',
+            'action'  => $request->status === ApprovalStatus::Returned ? '差戻しの対応' : '条件の確認',
+            'since'   => $request->status_changed_at,
+        ];
+    }
+
+    /** 番が来た順（古い順）。同じ日時は前の並び（段階の id の順 → 申請者の番の申請の id の順）のまま */
+    private static function sorted(Collection $items): Collection
+    {
+        return $items->sortBy(fn (array $item) => $item['since']?->getTimestamp() ?? PHP_INT_MAX)->values();
     }
 
     /**
@@ -94,7 +167,7 @@ private static function ownTurns(User $user): Builder
     {
         return ApprovalRequest::query()
             ->where('user_id', $user->id)
-            ->whereIn('status', [ApprovalStatus::Returned->value, ApprovalStatus::Condition->value]);
+            ->whereIn('status', self::OWN_TURN_STATUSES);
     }
 
     /** 待ち日数（自分の番が来た日から数えた暦の日数・日本時間。D20） */
```

（差分のファイルを使うなら: `git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/3b/patches/0003-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 と同じコマンドに、既存の対応待ちと担当のテストを足す）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase3/PendingWorkEveryoneTest.php tests/Feature/Approval/Phase2/PendingWorkTest.php tests/Feature/Approval/Phase2/HomeAndListTest.php tests/Feature/Approval/Phase3/StepHandlersTest.php
```

Expected: `OK`（1 行目のファイルは `OK (2 tests, …)` と同じ本数を含む）

- [ ] **Step 5: 全件を流す**

Expected: `OK (3270 tests, 23304 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/PendingWork.php tests/Feature/Approval/Phase3/PendingWorkEveryoneTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 全員分の対応待ちを 1 回でまとめる PendingWork::everyone() を足す

毎朝の催促に載せる全員分の対応待ちを、1 人ずつ for() を呼ばずに一定の
問い合わせの数で返す（段階3 設計書 §5.8）。1 件の形と並べ方は for() と
共有し、全員について for() と同じ結果になることを突き合わせで守る。
for() は同じ日時に番が来たものの並びを決めるため id の順に読む。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 4: 催促のコマンド・予定・まとめメール

毎朝 9:00〜9:04 の 1 回で動く `approvals:remind` と、1 人 1 通のまとめメールを作る（設計書 §5.8・§0.5）。使い始める前・送らない日・今日の分を送ったあとは何もしない。今日の行とまとめメールは 1 つのトランザクション。リンクは設定 `approval.mail_link_root` から作る（本番の `APP_URL` には `/index.php` が無い）。あわせて 3a の `Notifier` の私的な `oneLine()` をメールの土台へ移して共有する（§0.8）。

**Files:**
- Create: `app/Console/Commands/ApprovalRemindCommand.php`・`app/Mail/ApprovalReminderMail.php`・`resources/views/mail/approval-reminder.blade.php`
- Modify: `app/Mail/ApprovalMailable.php`（`oneLine()`）・`app/Support/Approval/Notifier.php`（`oneLine()` を土台のものに）・`config/approval.php`（`mail_link_root`）・`routes/console.php`（予定）
- Test: Create `tests/Feature/Approval/Phase3/ApprovalRemindCommandTest.php`・Modify `tests/Feature/Ops/ScheduleTest.php`

**Interfaces:**
- Consumes: `ReminderCalendar::reasonNotToSend()`（Task 2）・`PendingWork::everyone()`・`waitingDays()`（Task 3）・`ApprovalReminderRun`（Task 1）・`ApprovalMailable`・`MailDelivery`（3a）・`ApprovalSetting::launchedForMenu()`・`ApprovalMailDomain::allows()`（既存）
- Produces: `ApprovalRemindCommand`（`approvals:remind`・`MIN_DAYS = 3`・`MAX_ITEMS = 20`）／`new ApprovalReminderMail(string $recipientName, int $total, list<array{subject: string, applicant: string, department: string, task: string, days: int, url: string}> $items, string $homeUrl)`／`ApprovalMailable::oneLine(?string $text): string`（public static）／設定 `approval.mail_link_root`

**差分の大きさ:** 9 ファイル・+647 / −9 行（差分のファイル `0004-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase3/ApprovalRemindCommandTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase3;

use App\Enums\ApprovalStepResult;
use App\Mail\ApprovalReminderMail;
use App\Models\ApprovalHoliday;
use App\Models\ApprovalReminderRun;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\User;
use App\Support\Approval\MailDelivery;
use App\Support\Approval\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ReadsApprovalNotices;
use Tests\TestCase;

/**
 * 朝の催促のまとめメール（段階3 設計書 §5.8・§6 の「催促」・要件 8.3）。
 *
 * ⚠ 日付の組み立て: 2026-10-05（月）の朝 9:00（日本時間）に流す。10/1（木）に番が来たものは 4 日待ち、10/2（金）は 3 日待ち、
 *   10/3（土）は 2 日待ち（日本の暦の日数。D5）。
 */
class ApprovalRemindCommandTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ReadsApprovalNotices;

    private const MONDAY_NINE = '2026-10-05 09:00:00';

    /**
     * 日本時間のその時刻へ時計を進める。
     * ⚠ UTC にしてから渡す。日本時間の Carbon をそのまま渡すと、テストのあいだ Carbon が保存した日時（UTC）をその時刻帯で
     *   読み、番が来た日時が 9 時間ずれる（2026-10-02 に試作で実測。本番では起きない）
     */
    private function at(string $japanTime): void
    {
        $this->travelTo(CarbonImmutable::parse($japanTime, 'Asia/Tokyo')->utc());
    }

    /** コマンドを流して、画面に出した 1 行を返す */
    private function remind(): string
    {
        $this->assertSame(0, Artisan::call('approvals:remind'));

        return trim(Artisan::output());
    }

    /** @return Collection<int, ApprovalReminderMail> 積んだまとめメール */
    private function reminders(): Collection
    {
        return Mail::queued(ApprovalReminderMail::class)->values();
    }

    private function reminderTo(User $user): ApprovalReminderMail
    {
        return Mail::queued(ApprovalReminderMail::class, fn (ApprovalReminderMail $mail) => $mail->hasTo($user->email))->sole();
    }

    private function returnByHead(ApprovalRequest $request, User $head): void
    {
        $request->refresh();
        app(Workflow::class)->judgeHead($request, $head, $request->lock_version, ApprovalStepResult::Return, '理由です');
    }

    /** 1 人 1 通。載せるのは 3 日待ち以上だけで、境目は日本時間の 0:00（D5）。申請者の差戻し中も載る（要件 8.3） */
    public function test_each_person_gets_one_digest_of_the_requests_waiting_three_days_or_more(): void
    {
        Mail::fake();
        $w = $this->approvalWorld();
        $head      = $this->mailable($w['head'], 'head');
        $applicant = $this->mailable($w['applicant'], 'applicant');
        $this->launchApprovals();

        $this->at('2026-10-01 10:00:00');
        $fourDays = $this->submittedFor($w, ['subject' => '4 日待ち']);
        $this->returnByHead($this->submittedFor($w, ['subject' => '差し戻した申請']), $head);
        $this->at('2026-10-02 23:59:59');
        $this->submittedFor($w, ['subject' => '3 日待ち']);
        $this->at('2026-10-03 00:00:00');
        $this->submittedFor($w, ['subject' => '2 日待ち']);

        $this->at(self::MONDAY_NINE);
        $this->assertSame('2026-10-05 催促を送りました（2 人・3 件）。', $this->remind());

        $this->assertCount(2, $this->reminders());
        $toHead = $this->reminderTo($head);
        $this->assertSame(2, $toHead->total);
        $this->assertSame([['4 日待ち', 4, '部門長・承認・差戻し'], ['3 日待ち', 3, '部門長・承認・差戻し']], array_map(fn (array $item) => [$item['subject'], $item['days'], $item['task']], $toHead->items));
        // リンクの元の既定（APP_URL に /index.php を足したもの。D20）
        $this->assertSame("http://localhost/index.php/approvals/requests/{$fourDays->id}", $toHead->items[0]['url']);
        $toApplicant = $this->reminderTo($applicant);
        $this->assertSame([['差し戻した申請', 4, '申請者・差戻しの対応']], array_map(fn (array $item) => [$item['subject'], $item['days'], $item['task']], $toApplicant->items));

        $run = ApprovalReminderRun::sole();
        $this->assertSame(['2026-10-05', 2, 3], [$run->sent_on->format('Y-m-d'), $run->recipient_count, $run->item_count]);
    }

    /** 件名・本文・差出人・リンク（設定の元から作る。本番の形 /index.php 入り。D20）。金額・本文・コメントは書かない（8.2） */
    public function test_the_mail_text_and_links(): void
    {
        Mail::fake();
        config(['approval.mail_link_root' => 'https://www.mitsuwat.co.jp/system/manage/index.php']);
        $w    = $this->approvalWorld();
        $head = $this->mailable($w['head'], 'head');
        $this->launchApprovals();
        $this->at('2026-10-01 10:00:00');
        $request = $this->submittedFor($w, ['subject' => "A&B社の<契約>\nについて"]);

        $this->at(self::MONDAY_NINE);
        $this->remind();

        $mail = $this->reminderTo($head);
        $text = $mail->render();
        $this->assertSame('【決裁】対応待ちの申請が 1 件あります', $mail->envelope()->subject);
        $this->assertSame(['ミツワ都市開発 決裁システム', config('mail.from.address')], [$mail->envelope()->from->name, $mail->envelope()->from->address]);
        $this->assertSame(
            "部門 長 様\n\n"
            . "あなたの対応を待っている申請が 1 件あります（番が来てから 3 日以上たったもの）。\n\n"
            . "1. A&B社の<契約> について（申請 花子・住宅事業部）\n"
            . "   部門長・承認・差戻し ／ 4 日待ち\n"
            . "   https://www.mitsuwat.co.jp/system/manage/index.php/approvals/requests/{$request->id}\n\n"
            . "▼ 決裁のホーム（ほかの対応待ちも見られます）\n"
            . "https://www.mitsuwat.co.jp/system/manage/index.php/approvals\n\n"
            . "・このメールは、対応されるまで平日の朝 9 時に届きます（土日・祝日・会社の休みの日は届きません）。\n"
            . "・送信専用です。返信しても届きません。\n",
            $text,
        );
        foreach (['2,850,000', '2850000', '老朽化', '&amp;', '&lt;'] as $never) {
            $this->assertStringNotContainsString($never, $text);
        }

    }

    /**
     * 本番の定期実行の形（APP_URL に途中の道 /system/manage がある。コマンドのリクエストは SetRequestForConsole がその道を
     * SCRIPT_NAME にして作る）でも、リンクの道は 1 回だけ（/system/manage が二重にならない。/index.php が 1 回入る）
     */
    public function test_the_links_do_not_repeat_the_sub_path_of_the_app_url(): void
    {
        Mail::fake();
        config(['approval.mail_link_root' => 'https://www.mitsuwat.co.jp/system/manage/index.php']);
        $console = Request::create('https://www.mitsuwat.co.jp/system/manage', 'GET', [], [], [], ['SCRIPT_FILENAME' => '/system/manage', 'SCRIPT_NAME' => '/system/manage']);
        $this->app->instance('request', $console);
        URL::setRequest($console);
        $this->assertSame('https://www.mitsuwat.co.jp/system/manage/approvals', route('approvals.home'), '本番の定期実行の route() の形（/index.php なし）になっていない');

        $w    = $this->approvalWorld();
        $head = $this->mailable($w['head'], 'head');
        $this->launchApprovals();
        $this->at('2026-10-01 10:00:00');
        $request = $this->submittedFor($w);
        $this->at(self::MONDAY_NINE);
        $this->remind();

        $mail = $this->reminderTo($head);
        $this->assertSame("https://www.mitsuwat.co.jp/system/manage/index.php/approvals/requests/{$request->id}", $mail->items[0]['url']);
        $this->assertSame('https://www.mitsuwat.co.jp/system/manage/index.php/approvals', $mail->homeUrl);
    }

    /** リンクの元の既定は APP_URL に /index.php を足したもの（本番の APP_URL には /index.php が無い。D20） */
    public function test_the_default_link_root_adds_the_front_controller_to_the_app_url(): void
    {
        $this->assertSame('http://localhost', config('app.url'));
        $this->assertSame('http://localhost/index.php', config('approval.mail_link_root'));
    }

    /** 1 通に 20 件まで。多いときは残りの件数を書いてホームへ（D18）。記録の件数は載せきれなかった分も数える */
    public function test_a_digest_lists_twenty_at_most_and_tells_how_many_more(): void
    {
        Mail::fake();
        $w    = $this->approvalWorld();
        $head = $this->mailable($w['head'], 'head');
        $this->launchApprovals();
        $this->at('2026-10-01 10:00:00');
        foreach (range(1, 22) as $n) {
            $this->submittedFor($w, ['subject' => "申請 {$n}"]);
        }

        $this->at(self::MONDAY_NINE);
        $this->assertSame('2026-10-05 催促を送りました（1 人・22 件）。', $this->remind());

        $mail = $this->reminderTo($head);
        $this->assertSame(22, $mail->total);
        $this->assertCount(20, $mail->items);
        $this->assertSame('【決裁】対応待ちの申請が 22 件あります', $mail->envelope()->subject);
        $this->assertStringContainsString("20. 申請 20（", $mail->render());
        $this->assertStringNotContainsString("21. ", $mail->render());
        $this->assertStringContainsString("ほか 2 件はホームで確かめてください。\n", $mail->render());
        $this->assertSame(22, ApprovalReminderRun::sole()->item_count);
    }

    /** 宛先は有効で、許可したドメインのメールアドレスがある人だけ（無効・削除・メールなし・許可外のドメインには送らない） */
    public function test_only_active_people_with_an_allowed_address_get_a_digest(): void
    {
        Mail::fake();
        $w         = $this->approvalWorld();
        $reviewer  = $this->mailable($w['reviewer'], 'reviewer');
        $inactive  = $this->mailable($this->baseUser(['name' => '無効 審査']), 'inactive');
        $deleted   = $this->mailable($this->baseUser(['name' => '削除 審査']), 'deleted');
        $noAddress = $this->approvalOnlyUser(['name' => 'メールなし 審査']);
        $outside   = $this->baseUser(['name' => '外 審査', 'email' => 'outside@example.com']);
        $w['reviewDept']->reviewers()->attach([$inactive->id, $deleted->id, $noAddress->id, $outside->id]);
        $this->launchApprovals();

        $this->at('2026-10-01 10:00:00');
        $request = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($request->refresh(), $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);
        $inactive->forceFill(['status' => 'inactive'])->save();
        $deleted->delete();

        $this->at(self::MONDAY_NINE);
        $this->assertSame('2026-10-05 催促を送りました（1 人・1 件）。', $this->remind());
        $this->assertSame([$reviewer->email], $this->reminders()->map(fn (ApprovalReminderMail $mail) => $mail->to[0]['address'])->all());
    }

    public function test_nothing_is_sent_before_launch(): void
    {
        Mail::fake();
        $w = $this->approvalWorld();
        $this->mailable($w['head'], 'head');
        $this->at('2026-10-01 10:00:00');
        $this->submittedFor($w);

        $this->at(self::MONDAY_NINE);
        $this->assertSame('2026-10-05 使い始める前なので送りません。', $this->remind());
        $this->assertCount(0, $this->reminders());
        $this->assertSame(0, ApprovalReminderRun::count());
    }

    /** 土日・祝日・⑫で登録した日は送らない（記録も残さない） */
    public function test_nothing_is_sent_on_days_off(): void
    {
        Mail::fake();
        $w = $this->approvalWorld();
        $this->mailable($w['head'], 'head');
        $this->launchApprovals();
        $this->at('2026-09-28 10:00:00');
        $this->submittedFor($w);
        ApprovalHoliday::create(['start_date' => '2026-10-06', 'end_date' => '2026-10-06', 'repeats_yearly' => false, 'description' => '創立記念日']);

        $this->at('2026-10-03 09:00:00');
        $this->assertSame('2026-10-03 送らない日なので送りません（土曜日）。', $this->remind());
        $this->at('2026-10-06 09:00:00');
        $this->assertSame('2026-10-06 送らない日なので送りません（送らない日（創立記念日））。', $this->remind());
        $this->at('2026-10-12 09:00:00');
        $this->assertSame('2026-10-12 送らない日なので送りません（祝日（スポーツの日））。', $this->remind());

        $this->assertCount(0, $this->reminders());
        $this->assertSame(0, ApprovalReminderRun::count());
    }

    /** 同じ日に 2 回動いても 1 回だけ（一意の日付）。対応されるまで、次の送る日の朝にまた載る（要件 8.3） */
    public function test_it_sends_once_a_day_and_again_on_the_next_send_day(): void
    {
        Mail::fake();
        $w    = $this->approvalWorld();
        $head = $this->mailable($w['head'], 'head');
        $this->launchApprovals();
        $this->at('2026-10-01 10:00:00');
        $this->submittedFor($w);

        $this->at(self::MONDAY_NINE);
        $this->assertSame('2026-10-05 催促を送りました（1 人・1 件）。', $this->remind());
        $this->at('2026-10-05 09:04:00');
        $this->assertSame('2026-10-05 今日の分はもう送っています。', $this->remind());
        $this->assertCount(1, $this->reminders());

        $this->at('2026-10-06 09:00:00');
        $this->assertSame('2026-10-06 催促を送りました（1 人・1 件）。', $this->remind());
        $this->assertSame([4, 5], $this->reminders()->map(fn (ApprovalReminderMail $mail) => $mail->items[0]['days'])->all());
        $this->assertSame(['2026-10-05', '2026-10-06'], ApprovalReminderRun::orderBy('sent_on')->get()->map(fn (ApprovalReminderRun $run) => $run->sent_on->format('Y-m-d'))->all());
    }

    /** 送る相手がいない日も記録する（⑫ の「前回の催促」で、催促が動いたことが分かる） */
    public function test_a_day_with_nobody_to_remind_is_recorded(): void
    {
        Mail::fake();
        $this->launchApprovals();

        $this->at(self::MONDAY_NINE);
        $this->assertSame('2026-10-05 催促を送りました（0 人・0 件）。', $this->remind());
        $this->assertSame([0, 0], [ApprovalReminderRun::sole()->recipient_count, ApprovalReminderRun::sole()->item_count]);
    }

    /** 送り直しは 3 回・60 秒あけ。送れたら「送れた日時」、3 回だめなら laravel.log と「送れなかった日時と宛先」（D2 の帯。§5.5） */
    public function test_the_digest_is_retried_and_a_sent_or_failed_digest_is_recorded(): void
    {
        $this->travelTo(now()->startOfSecond());
        $settings = ApprovalSetting::current();   // 本番は SQL が入れた 1 行がある
        $mail     = (new ApprovalReminderMail('部門 長', 1, [], 'http://localhost/index.php/approvals'))->to('head@mitsuwat.co.jp');

        $job = new SendQueuedMailable($mail);
        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->backoff());

        $mail->send(app(MailFactory::class));
        $this->assertSame(now()->format('Y-m-d H:i:s'), DB::table('approval_settings')->where('id', $settings->id)->value('mail_last_sent_at'));

        $this->travel(5)->minutes();
        Log::shouldReceive('error')->once()->with('決裁の催促のメールを送れませんでした（宛先: head@mitsuwat.co.jp）: 送信サーバーにつながらない');
        $mail->failed(new RuntimeException('送信サーバーにつながらない'));

        $this->assertSame(['at' => now()->format('Y-m-d H:i:s'), 'to' => '部門 長'], [
            'at' => MailDelivery::pendingFailure()['at']->format('Y-m-d H:i:s'),
            'to' => MailDelivery::pendingFailure()['to'],
        ]);
    }

    /** 今日の行とまとめメール（jobs の行）は一緒に巻き戻る（途中で止まっても「送ったことになっているのにメールが無い」を作らない） */
    public function test_the_run_and_the_queued_mails_roll_back_together(): void
    {
        $w = $this->approvalWorld();
        $this->mailable($w['head'], 'head');
        $this->mailable($w['applicant'], 'applicant');
        $this->launchApprovals();
        $this->at('2026-10-01 10:00:00');
        $this->submittedFor($w);
        $this->returnByHead($this->submittedFor($w), $w['head']);

        config(['queue.default' => 'database']);
        $this->assertNull(config('queue.connections.database.connection'), 'キューが別の接続だと、記録と一緒に巻き戻らない');
        // 2 通目を積むところで止める（積む直前の合図。SQLite でも MySQL でも同じに動く）
        $queued = 0;
        $stop   = true;
        Event::listen(JobQueueing::class, function () use (&$queued, &$stop): void {
            if ($stop && ++$queued === 2) {
                throw new RuntimeException('2 通目で止める');
            }
        });

        $this->at(self::MONDAY_NINE);
        try {
            Artisan::call('approvals:remind');
            $this->fail('2 通目で止まっていない');
        } catch (RuntimeException $e) {
            $this->assertSame('2 通目で止める', $e->getMessage());
        }
        $this->assertSame(0, ApprovalReminderRun::count());
        $this->assertSame(0, DB::table('jobs')->count());

        $stop = false;
        $this->assertSame('2026-10-05 催促を送りました（2 人・2 件）。', $this->remind());
        $this->assertSame(1, ApprovalReminderRun::count());
        $this->assertSame(2, DB::table('jobs')->count());
    }
}
```

`tests/Feature/Ops/ScheduleTest.php`（変更）

```diff
--- a/tests/Feature/Ops/ScheduleTest.php
+++ b/tests/Feature/Ops/ScheduleTest.php
@@ -52,6 +52,53 @@ public function test_backup_runs_exactly_once_a_day_whatever_minute_the_cron_sta
         }
     }
 
+    public function test_the_approval_reminder_runs_between_nine_and_nine_oh_four_japan_time(): void
+    {
+        // 決裁の催促（段階3 設計書 §5.8）。送る日か・使い始めたか・今日の分を送ったかはコマンドが見る
+        $event = $this->event('approvals:remind');
+
+        $this->assertSame('0-4 9 * * *', $event->expression);
+        $this->assertSame('Asia/Tokyo', $this->timezoneName($event));
+        $this->assertTrue($event->withoutOverlapping);
+        // 目印（ロック）が残っても、翌朝の催促を止めない
+        $this->assertSame(30, $event->expiresAt);
+        $this->assertSame(storage_path('logs/approval-reminder.log'), $event->output);
+        $this->assertTrue($event->shouldAppendOutput);
+        // メンテナンス中は送らない（バックアップだけが動く）
+        $this->assertFalse($event->evenInMaintenanceMode);
+    }
+
+    public function test_the_approval_reminder_runs_exactly_once_a_day_whatever_minute_the_cron_starts_at(): void
+    {
+        // さくらの CRON が 0・5・10… 分に起動しても、ずれて起動しても、毎日ちょうど 1 回 9 時台に予定に当たること
+        $event = $this->event('approvals:remind');
+
+        foreach (range(0, 4) as $offset) {
+            $due = [];
+            $firstTick = CarbonImmutable::create(2026, 10, 5, 0, $offset, 0, 'Asia/Tokyo');
+            for ($i = 0; $i < 24 * 12; $i++) {
+                $tick = $firstTick->addMinutes(5 * $i);
+                $this->travelTo($tick);
+                if ($event->isDue($this->app)) {
+                    $due[] = $tick->format('H:i');
+                }
+            }
+            $this->assertSame([sprintf('09:%02d', $offset)], $due, "CRON が毎時 {$offset} 分から 5 分おきに起動する場合");
+        }
+    }
+
+    public function test_the_approval_reminder_is_scheduled_before_the_queue_worker(): void
+    {
+        // 9:00 の回に積んだまとめメールを、同じ回のキュー処理が送るように
+        $commands = array_values(array_map(fn (Event $event) => (string) $event->command, $this->app->make(Schedule::class)->events()));
+        $reminder = array_keys(array_filter($commands, fn (string $command) => str_contains($command, 'approvals:remind')));
+        $queue    = array_keys(array_filter($commands, fn (string $command) => str_contains($command, 'queue:work')));
+
+        $this->assertCount(1, $reminder);
+        $this->assertCount(1, $queue);
+        $this->assertLessThan($queue[0], $reminder[0], 'approvals:remind は queue:work より前に登録されていること');
+    }
+
     public function test_application_timezone_stays_utc(): void
     {
         // 予定の時刻だけを日本時間にし、アプリ全体の日時の扱いは変えない
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/3b/patches/0004-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase3/ApprovalRemindCommandTest.php tests/Feature/Ops/ScheduleTest.php
```

Expected: `ERRORS!` `Tests: 27, Assertions: 91, Errors: 11, Failures: 4.`

- `ApprovalRemindCommandTest::test_each_person_gets_one_digest_of_the_requests_waiting_three_days_or_more` — `Symfony\Component\Console\Exception\CommandNotFoundException: The command "approvals:remind" does not exist.`
- `ApprovalRemindCommandTest::test_the_mail_text_and_links` — `Symfony\Component\Console\Exception\CommandNotFoundException: The command "approvals:remind" does not exist.`
- `ApprovalRemindCommandTest::test_the_links_do_not_repeat_the_sub_path_of_the_app_url` — `Symfony\Component\Console\Exception\CommandNotFoundException: The command "approvals:remind" does not exist.`
- `ApprovalRemindCommandTest::test_a_digest_lists_twenty_at_most_and_tells_how_many_more` — `Symfony\Component\Console\Exception\CommandNotFoundException: The command "approvals:remind" does not exist.`
- `ApprovalRemindCommandTest::test_only_active_people_with_an_allowed_address_get_a_digest` — `Symfony\Component\Console\Exception\CommandNotFoundException: The command "approvals:remind" does not exist.`
- `ApprovalRemindCommandTest::test_nothing_is_sent_before_launch` — `Symfony\Component\Console\Exception\CommandNotFoundException: The command "approvals:remind" does not exist.`
- `ApprovalRemindCommandTest::test_nothing_is_sent_on_days_off` — `Symfony\Component\Console\Exception\CommandNotFoundException: The command "approvals:remind" does not exist.`
- `ApprovalRemindCommandTest::test_it_sends_once_a_day_and_again_on_the_next_send_day` — `Symfony\Component\Console\Exception\CommandNotFoundException: The command "approvals:remind" does not exist.`
- `ApprovalRemindCommandTest::test_a_day_with_nobody_to_remind_is_recorded` — `Symfony\Component\Console\Exception\CommandNotFoundException: The command "approvals:remind" does not exist.`
- `ApprovalRemindCommandTest::test_the_digest_is_retried_and_a_sent_or_failed_digest_is_recorded` — `Error: Class "App\Mail\ApprovalReminderMail" not found`
- `ApprovalRemindCommandTest::test_the_run_and_the_queued_mails_roll_back_together` — `Symfony\Component\Console\Exception\CommandNotFoundException: The command "approvals:remind" does not exist.`
- `ApprovalRemindCommandTest::test_the_default_link_root_adds_the_front_controller_to_the_app_url` — `Failed asserting that null is identical to 'http://localhost/index.php'.`
- `ScheduleTest::test_the_approval_reminder_runs_between_nine_and_nine_oh_four_japan_time` — `approvals:remind の予定がちょうど 1 件あること`
- `ScheduleTest::test_the_approval_reminder_runs_exactly_once_a_day_whatever_minute_the_cron_starts_at` — `approvals:remind の予定がちょうど 1 件あること`
- `ScheduleTest::test_the_approval_reminder_is_scheduled_before_the_queue_worker` — `Failed asserting that actual size 0 matches expected size 1.`

- [ ] **Step 3: `oneLine()` をメールの土台へ移す**（振る舞いは変えない。3a の `NotifierTest` の改行のテストがそのまま守る）

`app/Mail/ApprovalMailable.php`（変更）

```diff
--- a/app/Mail/ApprovalMailable.php
+++ b/app/Mail/ApprovalMailable.php
@@ -31,6 +31,12 @@ abstract class ApprovalMailable extends Mailable implements ShouldQueue
 
     public $backoff = 60;
 
+    /** メールの件名や 1 行の欄に入れる文字の改行などの制御文字を空白にする（細工した送信で件名が 2 行にならないように） */
+    public static function oneLine(?string $text): string
+    {
+        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $text));
+    }
+
     /** 帯に出す宛先（氏名） */
     abstract protected function failedRecipientName(): string;
```

`app/Support/Approval/Notifier.php`（変更）

```diff
--- a/app/Support/Approval/Notifier.php
+++ b/app/Support/Approval/Notifier.php
@@ -7,6 +7,7 @@
 use App\Enums\ApprovalStepKind;
 use App\Enums\ApprovalStepStatus;
 use App\Enums\UserStatus;
+use App\Mail\ApprovalMailable;
 use App\Mail\ApprovalNoticeMail;
 use App\Models\ApprovalDepartment;
 use App\Models\ApprovalHistory;
@@ -256,17 +257,11 @@ private static function context(ApprovalRequest $request): array
         $snapshot = ApprovalRevision::where('request_id', $request->id)->where('round', $request->round)->first()?->snapshot ?? [];
 
         return [
-            'subject'    => self::oneLine($snapshot['subject'] ?? $request->subject),
+            'subject'    => ApprovalMailable::oneLine($snapshot['subject'] ?? $request->subject),
             'number'     => $request->number,
-            'applicant'  => self::oneLine($request->applicant?->name),
-            'department' => self::oneLine($snapshot['department']['name'] ?? null),
+            'applicant'  => ApprovalMailable::oneLine($request->applicant?->name),
+            'department' => ApprovalMailable::oneLine($snapshot['department']['name'] ?? null),
             'url'        => route('approvals.requests.show', $request),
         ];
     }
-
-    /** メールの件名に入るので、改行などの制御文字を空白にする */
-    private static function oneLine(?string $text): string
-    {
-        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $text));
-    }
 }
```

- [ ] **Step 4: リンクの元の設定・まとめメール・コマンド・予定を書く**

`config/approval.php`（変更）

```diff
--- a/config/approval.php
+++ b/config/approval.php
@@ -32,4 +32,18 @@
 
     'guide_token_ttl_hours' => env('APPROVAL_GUIDE_TOKEN_TTL_HOURS', 12),
 
+    /*
+    |--------------------------------------------------------------------------
+    | 朝の催促のメールのリンクの元（段階3 設計書 §5.8・D20）
+    |--------------------------------------------------------------------------
+    |
+    | 催促は画面の操作が無い定期実行で作るので、操作の画面のリクエストからリンクを作れない。
+    | 本番は mod_rewrite が無く、URL に /index.php が要る（要件 15.1）のに、本番の APP_URL は
+    | https://www.mitsuwat.co.jp/system/manage（/index.php が無い。2026-10-02 に本番で読み取った）。
+    | 既定は APP_URL に /index.php を足したもの。リンクはこれを元に route() で作る（ApprovalRemindCommand）。
+    |
+    */
+
+    'mail_link_root' => env('APPROVAL_MAIL_LINK_ROOT', rtrim((string) env('APP_URL', 'http://localhost'), '/') . '/index.php'),
+
 ];
```

`app/Mail/ApprovalReminderMail.php`（新規）

```php
<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 朝の催促のまとめメール（段階3 設計書 §5.8・要件 8.3）。1 人 1 通・テキストだけ。作るのは ApprovalRemindCommand だけ。
 *
 * ⚠ 1 件ごとに書くのは件名・申請者（申請部門）・必要な対応・待ち日数・リンクだけ。金額・本文・添付・コメントは書かない（8.2）
 * ⚠ 1 通に載せるのは 20 件まで（D18）。多いときは残りの件数を書いてホームへ案内する（$total が全部の件数）
 * ⚠ 中身（宛名・リンク）はコマンドが積むときに決めて渡す（キューの中で route() や設定を読まない。段階3 設計書 §4.2）
 * ⚠ 本文はテキストなので `{!! !!}` で書く（`{{ }}` だと件名の & が &amp; のまま届く）
 */
class ApprovalReminderMail extends ApprovalMailable
{
    /**
     * @param list<array{subject: string, applicant: string, department: string, task: string, days: int, url: string}> $items 載せる分（20 件まで）
     */
    public function __construct(
        public string $recipientName,
        public int $total,
        public array $items,
        public string $homeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), self::FROM_NAME),
            subject: "【決裁】対応待ちの申請が {$this->total} 件あります",
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.approval-reminder', with: ['rest' => $this->total - count($this->items)]);
    }

    protected function failedRecipientName(): string
    {
        return $this->recipientName;
    }

    protected function failureLabel(): string
    {
        return '決裁の催促のメールを送れませんでした';
    }
}
```

`resources/views/mail/approval-reminder.blade.php`（新規）

```blade
{!! $recipientName !!} 様

あなたの対応を待っている申請が {!! $total !!} 件あります（番が来てから 3 日以上たったもの）。

@foreach($items as $i => $item)
{!! $i + 1 !!}. {!! $item['subject'] !!}（{!! $item['applicant'] !!}・{!! $item['department'] !!}）
   {!! $item['task'] !!} ／ {!! $item['days'] !!} 日待ち
   {!! $item['url'] !!}

@endforeach
@if($rest > 0)
ほか {!! $rest !!} 件はホームで確かめてください。

@endif
▼ 決裁のホーム（ほかの対応待ちも見られます）
{!! $homeUrl !!}

・このメールは、対応されるまで平日の朝 9 時に届きます（土日・祝日・会社の休みの日は届きません）。
・送信専用です。返信しても届きません。
```

`app/Console/Commands/ApprovalRemindCommand.php`（新規）

```php
<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Mail\ApprovalMailable;
use App\Mail\ApprovalReminderMail;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalReminderRun;
use App\Models\ApprovalSetting;
use App\Models\User;
use App\Support\Approval\PendingWork;
use App\Support\Approval\ReminderCalendar;
use App\Support\JapanTime;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * 朝の催促のまとめメール（段階3 設計書 §5.8・要件 8.3）。定期実行が平日の朝 9:00〜9:04 の起動で呼ぶ（routes/console.php）。
 *
 * 順に確かめ、当たれば何もしないで終わる: 使い始める前 → 送らない日（土日・祝日・⑫で登録した日。ReminderCalendar）→
 * 今日の分をもう送った（approval_reminder_runs に今日の行を入れられない）。
 *
 * ⚠ 今日の行を入れることと、まとめメールを積むことは 1 つのトランザクション（途中で止まれば両方残らない。キューが database
 *   なので jobs の行も一緒に巻き戻る。Notifier と同じ前提）。同じ日に 2 回目が動いても、一意の日付で入れられずに止まる
 * ⚠ 載せるのはホームの対応待ち（PendingWork）と同じもののうち、待ち日数が 3 以上のもの（D5）。宛先は有効でメールを送れる人だけ
 *   （催促はメールだけで、お知らせは作らない。要件 8.1）
 * ⚠ リンクは設定 approval.mail_link_root（本番は APP_URL に /index.php を足したもの）から作る（画面の操作が無いため。D20）
 */
class ApprovalRemindCommand extends Command
{
    /** 催促に載せる待ち日数（ホームの「3 日待ち」以上。D5） */
    public const MIN_DAYS = 3;

    /** 1 通に載せる件数（D18） */
    public const MAX_ITEMS = 20;

    protected $signature = 'approvals:remind';

    protected $description = '決裁の催促のまとめメールを送信待ちに入れる（平日の朝 9 時の定期実行。使い始める前・送らない日・今日の分を送ったあとは何もしない）';

    public function handle(): int
    {
        $today = JapanTime::today();

        // 定期実行は画面の出力を storage/logs/approval-reminder.log に足す（本番の laravel.log は error だけ残るため）
        $this->line($today->format('Y-m-d') . ' ' . $this->remind($today));

        return self::SUCCESS;
    }

    private function remind(Carbon $today): string
    {
        if (! ApprovalSetting::launchedForMenu()) {
            return '使い始める前なので送りません。';
        }

        $reason = (new ReminderCalendar())->reasonNotToSend($today);
        if ($reason !== null) {
            return "送らない日なので送りません（{$reason}）。";
        }

        $mails = $this->mails();
        $items = array_sum(array_map(fn (ApprovalReminderMail $mail) => $mail->total, $mails));

        $sent = DB::transaction(function () use ($today, $mails, $items): bool {
            try {
                ApprovalReminderRun::create(['sent_on' => $today->format('Y-m-d'), 'recipient_count' => count($mails), 'item_count' => $items]);
            } catch (UniqueConstraintViolationException) {
                return false;
            }

            foreach ($mails as $email => $mail) {
                Mail::to($email)->queue($mail);
            }

            return true;
        });

        return $sent ? '催促を送りました（' . count($mails) . " 人・{$items} 件）。" : '今日の分はもう送っています。';
    }

    /** @return array<string, ApprovalReminderMail> メールアドレス => まとめメール（宛先は有効で、メールを送れる人だけ） */
    private function mails(): array
    {
        $pending = PendingWork::everyone()
            ->map(fn (Collection $items) => $items->filter(fn (array $item) => PendingWork::waitingDays($item['since']) >= self::MIN_DAYS)->values())
            ->filter(fn (Collection $items) => $items->isNotEmpty());

        if ($pending->isEmpty()) {
            return [];
        }

        $mails = [];

        // 削除した人は SoftDeletes で入らない
        foreach (User::whereKey($pending->keys())->where('status', UserStatus::Active->value)->orderBy('id')->get() as $user) {
            if (ApprovalMailDomain::allows($user->email)) {
                $mails[$user->email] = $this->digest($user, $pending[$user->id]);
            }
        }

        return $mails;
    }

    private function digest(User $user, Collection $items): ApprovalReminderMail
    {
        $listed = $items->take(self::MAX_ITEMS)->map(fn (array $item) => [
            'subject'    => ApprovalMailable::oneLine($item['request']->subject),
            'applicant'  => ApprovalMailable::oneLine($item['request']->applicant?->name),
            'department' => ApprovalMailable::oneLine($item['request']->department?->name),
            'task'       => "{$item['role']}・{$item['action']}",
            'days'       => PendingWork::waitingDays($item['since']),
            'url'        => self::link('approvals.requests.show', $item['request']),
        ])->all();

        return new ApprovalReminderMail($user->name, $items->count(), $listed, self::link('approvals.home'));
    }

    /**
     * リンクは設定の元（approval.mail_link_root）に、ルートの道（/approvals/…）をつないで作る（D20）。
     *
     * ⚠ 定期実行の中の route() は APP_URL を元にし、本番で /index.php が抜ける。URL::forceRootUrl() で元を差し替える形は
     *   通信の種類（https）をリクエストのものに置き換えるので使わない（試作で https が http に化けた）。
     *   route() の第 3 引数 false は、ホストとリクエストの元の道（/system/manage など）を除いた道を返す（2026-10-02 に実測）
     */
    private static function link(string $name, mixed $parameters = []): string
    {
        return rtrim((string) config('approval.mail_link_root'), '/') . route($name, $parameters, false);
    }
}
```

`routes/console.php`（変更）

```diff
--- a/routes/console.php
+++ b/routes/console.php
@@ -79,6 +79,15 @@
         : '終了コードなし: '.$failed->exception->getMessage());
 });
 
+// 決裁の催促のまとめメール（段階3 設計書 §5.8・要件 8.3）。9:00〜9:04 に起動された 1 回だけ実行する（バックアップと同じく
+// 5 分の幅を持たせる。9:00〜9:04 に動かなかった日は送らない。D17）。送る日か・使い始めたか・今日の分をもう送ったかはコマンドが見る。
+// キュー処理より前に置く: ここで積んだまとめメールを、同じ回のキュー処理がそのまま送る。
+// 画面の出力（送った人数・送らなかったわけ）はログのファイルに足す（本番の laravel.log は error だけ残るため）。
+Schedule::command('approvals:remind')
+    ->cron('0-4 9 * * *')
+    ->appendOutputTo(storage_path('logs/approval-reminder.log'))
+    ->withoutOverlapping(30);
+
 // 送信待ちのメールなどを、空になるまで処理して終わる（常駐させない）。
 // CRON（5 分おき）で schedule:run が起動されるたびに回す（CRON の分の設定がずれていても止まらないように）。
 Schedule::command('queue:work --stop-when-empty --max-time=240 --tries=3 --backoff=60')
```

（差分のファイルを使うなら: `git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/3b/patches/0004-*.patch`）

- [ ] **Step 5: テストを流して通ることを確かめる**（Step 2 と同じコマンドに、3a の知らせのテストを足す）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase3/ApprovalRemindCommandTest.php tests/Feature/Ops/ScheduleTest.php tests/Feature/Approval/Phase3/NotifierTest.php
```

Expected: `OK`（1・2 行目のファイルは `OK (27 tests, …)` と同じ本数を含む）

- [ ] **Step 6: 全件を流す**

Expected: `OK (3285 tests, 23398 assertions)`

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Console/Commands/ApprovalRemindCommand.php app/Mail/ApprovalReminderMail.php resources/views/mail/approval-reminder.blade.php app/Mail/ApprovalMailable.php app/Support/Approval/Notifier.php config/approval.php routes/console.php tests/Feature/Approval/Phase3/ApprovalRemindCommandTest.php tests/Feature/Ops/ScheduleTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 毎朝の催促のまとめメールを送るコマンドと予定を足す

approvals:remind を定期実行の 9:00〜9:04 の 1 回に足す（キュー処理より前）。
使い始める前・送らない日・今日の分を送ったあとは何もせず、3 日待ち以上の
対応待ちを 1 人 1 通（20 件まで）にまとめ、今日の記録と一緒に 1 つの
トランザクションで積む（段階3 設計書 §5.8）。リンクは APP_URL に /index.php
を足した設定から作る（D20）。改行を空白にする部品はメールの土台へ移した。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 5: 催促の設定（⑫）

決裁の管理者が催促を送らない日を登録・修正・削除する画面と、「次に催促を送る日」「前回の催促」の表示を作る（設計書 §5.9・§0.6）。使い始める前から開く。入口は「決裁の管理」のサイドバー 4 か所とホームのリンクの行。走査テストへの登録もこの Task で行う（§0.7）。

**Files:**
- Create: `app/Http/Controllers/Approval/HolidayController.php`・`resources/views/approvals/admin/holidays.blade.php`
- Modify: `app/Models/ApprovalHoliday.php`（`formValues()`・`periodLabel()`）・`routes/approval.php`・`lang/ja/validation.php`・`resources/views/layouts/partials/sidebar.blade.php`・`resources/views/layouts/partials/sidebar_approval.blade.php`・`resources/views/approvals/home.blade.php`・`resources/views/approvals/home-launched.blade.php`
- Test: Create `tests/Feature/Approval/Phase3/HolidaySettingsTest.php`・Modify `tests/Feature/Approval/ApprovalAdminGateTest.php`・`tests/Feature/Approval/Phase2/LaunchGateTest.php`・`tests/Feature/Approval/Phase3/MailFailureBannerTest.php`・`tests/Feature/Approval/ApprovalSidebarTest.php`

**Interfaces:**
- Consumes: `ReminderCalendar::nextSendDay()`・`label()`（Task 2）・`ApprovalReminderRun::latestRun()`・`ApprovalHoliday`（Task 1）・`SettingLogger`・`approvals._mail_failure`・`approvals._submit_once`（既存）
- Produces: ルート `approvals.admin.holidays.index`・`store`・`update`・`destroy`（パラメータ `{approvalHoliday}`）／`ApprovalHoliday::formValues(): array{start_date: string, end_date: string, repeats_yearly: bool, description: string}`・`periodLabel(): string`

**差分の大きさ:** 14 ファイル・+662 / −7 行（差分のファイル `0005-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase3/HolidaySettingsTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase3;

use App\Models\ApprovalHoliday;
use App\Models\ApprovalReminderRun;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 催促の設定（画面⑫・段階3 設計書 §5.9・§6 の「⑫」）。
 *
 * ⚠ 時計は 2026-10-02（金）10:00（日本時間）に止める。今日の 9:05 を過ぎているので、次に送る日は 10/5（月）。
 */
class HolidaySettingsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ParsesForms;

    /** 終了日が開始日より前のときの文（年をまたぐ期間の直し方も添える。利用者の決定 2026-10-02） */
    private const END_BEFORE_START = '終了日は開始日より前にできません（年をまたぐ期間は、終了日を次の年の日付にしてください。例: 2026/12/29〜2027/1/3）。';

    protected function setUp(): void
    {
        parent::setUp();
        // ⚠ UTC にしてから渡す（日本時間の Carbon を渡すと、保存した日時をその時刻帯で読む。ApprovalRemindCommandTest と同じ）
        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00:00', 'Asia/Tokyo')->utc());
    }

    private function indexHtml(User $admin): string
    {
        return $this->actingAs($admin)->get(route('approvals.admin.holidays.index'))->assertOk()->getContent();
    }

    /** 表の行ごとのセルの文字（タグを除いて空白を詰めたもの） @return list<list<string>> */
    private function tableRows(string $html): array
    {
        preg_match_all('/<tr class="hover:bg-gray-50">(.*?)<\/tr>/s', $html, $rows);

        return array_map(function (string $row): array {
            preg_match_all('/<td\b[^>]*>(.*?)<\/td>/s', $row, $cells);

            return array_map(fn (string $c): string => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($c), ENT_QUOTES, 'UTF-8'))), $cells[1]);
        }, $rows[1]);
    }

    /** 画面の文字（タグを除いて空白を詰めたもの） */
    private function text(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
    }

    /** 項目の並びを問わずに比べるため、キーの順に並べる（設定の変更の記録は JSON の列で、MySQL は項目の並びを入れ替える） */
    private function byKey(?array $values): ?array
    {
        if ($values !== null) {
            ksort($values);
        }

        return $values;
    }

    private function holiday(string $from, string $to, bool $yearly, string $description): ApprovalHoliday
    {
        return ApprovalHoliday::create(['start_date' => $from, 'end_date' => $to, 'repeats_yearly' => $yearly, 'description' => $description]);
    }

    /** @param array<string, string> $fields */
    private function store(User $admin, array $fields)
    {
        return $this->actingAs($admin)->from(route('approvals.admin.holidays.index'))->post(route('approvals.admin.holidays.store'), $fields);
    }

    public function test_the_screen_shows_the_next_send_day_the_last_run_and_the_days_off(): void
    {
        $admin = $this->approvalAdmin();
        $this->holiday('2026-12-29', '2027-01-03', true, '年末年始');
        $this->holiday('2026-08-13', '2026-08-14', false, '夏季休暇');
        $this->holiday('2026-11-02', '2026-11-02', false, '創立記念日');

        $html = $this->indexHtml($admin);
        $text = $this->text($html);
        $this->assertStringContainsString('次に催促を送る日 10/5（月） 9 時', $text);
        $this->assertStringContainsString('決裁を使い始める前なので、まだ送りません。', $text);
        $this->assertStringContainsString('前回の催促 まだありません', $text);
        // 開始日の順
        $this->assertSame([
            ['2026/08/13〜2026/08/14', 'その年だけ', '夏季休暇', '編集 | 削除'],
            ['2026/11/02', 'その年だけ', '創立記念日', '編集 | 削除'],
            ['12/29〜1/3', '毎年', '年末年始', '編集 | 削除'],
        ], $this->tableRows($html));
        $this->assertStringContainsString('function approvalHolidays()', $html);

        ApprovalReminderRun::create(['sent_on' => '2026-10-01', 'recipient_count' => 3, 'item_count' => 5]);
        $this->launchApprovals();
        $text = $this->text($this->indexHtml($admin));
        $this->assertStringContainsString('前回の催促 10/1（木）（3 人・5 件）', $text);
        $this->assertStringNotContainsString('使い始める前', $text);
    }

    public function test_the_screen_says_so_when_no_send_day_comes_within_a_year(): void
    {
        $this->holiday('2026-10-01', '2028-12-31', false, '長い休み');

        $this->assertStringContainsString('次に催促を送る日 1 年以内にありません（送らない日の登録を確かめてください）', $this->text($this->indexHtml($this->approvalAdmin())));
    }

    /** 描いた追加のフォームをそのまま送り返す。登録すると次に送る日が動き、設定の変更の記録が残る */
    public function test_a_day_off_can_be_added_from_the_rendered_form(): void
    {
        $admin = $this->approvalAdmin();
        $form  = $this->parseForm($this->indexHtml($admin), 'action="' . route('approvals.admin.holidays.store') . '"');
        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields']);
        $this->assertArrayNotHasKey('repeats_yearly', $form['fields'], '毎年繰り返すは、はじめは外れている');

        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], [
            'start_date' => '2026-10-05', 'end_date' => '2026-10-09', 'description' => '秋休み',
        ]))->assertRedirect(route('approvals.admin.holidays.index'))->assertSessionHas('success', '送らない日を登録しました。');

        $holiday = ApprovalHoliday::sole();
        $this->assertSame(['start_date' => '2026-10-05', 'end_date' => '2026-10-09', 'repeats_yearly' => false, 'description' => '秋休み'], $holiday->formValues());
        $this->assertSame(
            $this->byKey(['start_date' => '2026-10-05', 'end_date' => '2026-10-09', 'repeats_yearly' => false, 'description' => '秋休み']),
            $this->byKey(ApprovalSettingLog::where('action', 'holiday.created')->sole()->new_values),
        );
        // 10/5〜10/9 を止めたので、10/12（スポーツの日）も飛ばして 10/13
        $this->assertStringContainsString('次に催促を送る日 10/13（火） 9 時', $this->text($this->indexHtml($admin)));
    }

    public function test_the_dates_and_the_description_are_checked(): void
    {
        $admin = $this->approvalAdmin();

        $this->store($admin, ['start_date' => '2026-10-09', 'end_date' => '2026-10-08', 'description' => '休み'])
            ->assertSessionHasErrors(['end_date' => self::END_BEFORE_START]);
        // 年をまたぐ毎年の期間を、終了日を同じ年のまま打った（年末年始でいちばん起きやすい打ち間違い）
        $this->store($admin, ['start_date' => '2026-12-29', 'end_date' => '2026-01-03', 'repeats_yearly' => '1', 'description' => '年末年始'])
            ->assertSessionHasErrors(['end_date' => self::END_BEFORE_START]);
        // 存在しない日付は繰り上げずに断る（Top trap #15）
        $this->store($admin, ['start_date' => '2026-02-30', 'end_date' => '2026-03-02', 'description' => '休み'])
            ->assertSessionHasErrors(['start_date' => '開始日は正しい日付で入力してください。']);
        $this->store($admin, ['start_date' => '', 'end_date' => '', 'description' => ''])
            ->assertSessionHasErrors([
                'start_date' => '開始日を入力してください。',
                'end_date' => '終了日を入力してください。',
                'description' => '説明を入力してください（例: 年末年始）。',
            ]);
        $this->store($admin, ['start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'description' => str_repeat('あ', 51)])
            ->assertSessionHasErrors(['description' => '説明は50文字以内で入力してください。']);

        $this->assertSame(0, ApprovalHoliday::count());
    }

    /** 毎年繰り返す期間は 1 年より短く（利用者の決定 2026-10-02）。その年だけの期間は長くてよい */
    public function test_a_yearly_period_must_be_shorter_than_a_year(): void
    {
        $admin  = $this->approvalAdmin();
        $refuse = ['end_date' => '毎年繰り返す期間は 1 年より短くしてください。'];

        $this->store($admin, ['start_date' => '2026-12-29', 'end_date' => '2027-12-29', 'repeats_yearly' => '1', 'description' => '休み'])->assertSessionHasErrors($refuse);
        // 2/29 の 1 年後は翌年の 2/28
        $this->store($admin, ['start_date' => '2028-02-29', 'end_date' => '2029-02-28', 'repeats_yearly' => '1', 'description' => '休み'])->assertSessionHasErrors($refuse);
        $this->assertSame(0, ApprovalHoliday::count());

        $this->store($admin, ['start_date' => '2026-12-29', 'end_date' => '2027-12-28', 'repeats_yearly' => '1', 'description' => 'ほぼ 1 年'])->assertSessionHasNoErrors();
        $this->store($admin, ['start_date' => '2028-02-29', 'end_date' => '2029-02-27', 'repeats_yearly' => '1', 'description' => 'うるう年から'])->assertSessionHasNoErrors();
        $this->store($admin, ['start_date' => '2026-12-29', 'end_date' => '2027-12-29', 'description' => 'その年だけの長い休み'])->assertSessionHasNoErrors();
        $this->assertSame(3, ApprovalHoliday::count());
    }

    /** 修正は変わった項目だけを記録し、何も変えなければ記録しない。削除は前の値を記録する */
    public function test_changes_and_deletions_are_recorded(): void
    {
        $admin   = $this->approvalAdmin();
        $holiday = $this->holiday('2026-12-29', '2027-01-03', true, '年末年始');
        $same    = ['start_date' => '2026-12-29', 'end_date' => '2027-01-03', 'repeats_yearly' => '1'];

        $this->actingAs($admin)->put(route('approvals.admin.holidays.update', $holiday), $same + ['description' => '年末年始の休業'])
            ->assertRedirect(route('approvals.admin.holidays.index'))->assertSessionHas('success', '送らない日を更新しました。');
        $log = ApprovalSettingLog::where('action', 'holiday.updated')->sole();
        $this->assertSame([['description' => '年末年始'], ['description' => '年末年始の休業']], [$log->old_values, $log->new_values]);

        $this->actingAs($admin)->put(route('approvals.admin.holidays.update', $holiday), $same + ['description' => '年末年始の休業']);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'holiday.updated')->count());

        $this->actingAs($admin)->delete(route('approvals.admin.holidays.destroy', $holiday))
            ->assertRedirect(route('approvals.admin.holidays.index'))->assertSessionHas('success', '送らない日を削除しました。');
        $this->assertSame(0, ApprovalHoliday::count());
        $this->assertSame(
            $this->byKey(['start_date' => '2026-12-29', 'end_date' => '2027-01-03', 'repeats_yearly' => true, 'description' => '年末年始の休業']),
            $this->byKey(ApprovalSettingLog::where('action', 'holiday.deleted')->sole()->old_values),
        );
    }

    /** 断られた修正は、同じ送らない日の小窓を、打った中身で開き直す（申請種類の管理と同じ形） */
    public function test_a_refused_edit_reopens_the_same_dialog(): void
    {
        $admin   = $this->approvalAdmin();
        $holiday = $this->holiday('2026-12-29', '2027-01-03', true, '年末年始');

        $html = $this->actingAs($admin)->from(route('approvals.admin.holidays.index'))
            ->followingRedirects()
            ->put(route('approvals.admin.holidays.update', $holiday), [
                'edit_id' => (string) $holiday->id, 'start_date' => '2027-01-03', 'end_date' => '2026-12-29', 'repeats_yearly' => '1', 'description' => '打った説明',
            ])->assertOk()->getContent();

        $this->assertStringContainsString('x-show="editId === ' . $holiday->id . '"', $html);
        $this->assertStringContainsString(e(self::END_BEFORE_START), $html);
        // 開き直す中身（Js::from は日本語を \u で符号化するので、HTML の文字列では探さない。TypeManagementTest と同じ読み方）
        $this->assertSame(1, preg_match("/var refused = JSON\\.parse\\('([^']*)'\\);/", $html, $m));
        $this->assertSame(
            ['id' => $holiday->id, 'start_date' => '2027-01-03', 'end_date' => '2026-12-29', 'repeats_yearly' => true, 'description' => '打った説明'],
            json_decode(json_decode('"' . $m[1] . '"'), true),
        );
        $this->assertSame('年末年始', $holiday->fresh()->description);
    }

    public function test_people_other_than_the_approval_admin_cannot_open_it(): void
    {
        $this->actingAs($this->baseUser())->get(route('approvals.admin.holidays.index'))->assertForbidden();
    }
}
```

`tests/Feature/Approval/ApprovalAdminGateTest.php`（変更）

```diff
--- a/tests/Feature/Approval/ApprovalAdminGateTest.php
+++ b/tests/Feature/Approval/ApprovalAdminGateTest.php
@@ -6,6 +6,7 @@
 use App\Http\Middleware\EnsureApprovalAdmin;
 use App\Models\ApprovalCompany;
 use App\Models\ApprovalDepartment;
+use App\Models\ApprovalHoliday;
 use App\Models\ApprovalMailDomain;
 use App\Models\ApprovalMember;
 use App\Models\ApprovalRequest;
@@ -187,8 +188,9 @@ public function test_every_approvals_route_is_classified(): void
         //   出ている本当の理由（分類漏れ・門番の欠落・逆方向の見落とし）が隠れる。
         $this->assertSame([], $problems, "分類漏れ・門番の欠落・逆方向の見落とし:\n" . implode("\n", $problems));
 
-        // 走査が空振りして緑になる事故を防ぐ（3a で 46 本 = 決裁の管理 22 本 + 進行中の申請の管理 4 本 + ホーム 1 本 + 申請を回す画面 16 本 + お知らせ 3 本）
-        $this->assertGreaterThanOrEqual(46, $found, 'approvals. のルートの走査に失敗している');
+        // 走査が空振りして緑になる事故を防ぐ（3b で 50 本 = 決裁の管理 22 本 + 進行中の申請の管理 4 本 + ホーム 1 本 + 申請を回す画面 16 本 + お知らせ 3 本
+        // + 催促の設定 4 本）
+        $this->assertGreaterThanOrEqual(50, $found, 'approvals. のルートの走査に失敗している');
     }
 
     /**
@@ -369,6 +371,8 @@ private function tableCounts(): array
             'approval_histories' => DB::table('approval_histories')->count(),
             // お知らせ（段階3）。部門長の交代・審査担当者の追加は担当に知らせを作る
             'notifications' => DB::table('notifications')->count(),
+            // 催促の設定（3b）。送らない日の追加・削除
+            'approval_holidays' => DB::table('approval_holidays')->count(),
         ];
     }
 
@@ -511,6 +515,9 @@ public function test_every_admin_route_refuses_outsiders_without_revealing_ids()
             'user_id' => $manageableUser->id, 'department_id' => $reviewDepartment->id, 'type_id' => $type->id, 'subject' => '門番の確かめ',
         ]);
 
+        // 催促の設定（3b）の相手の送らない日
+        $holiday = ApprovalHoliday::create(['start_date' => '2026-12-29', 'end_date' => '2027-01-03', 'repeats_yearly' => true, 'description' => '年末年始']);
+
         $existingValues = [
             'user' => (string) $manageableUser->id,
             'approvalCompany' => (string) $company->id,
@@ -518,6 +525,7 @@ public function test_every_admin_route_refuses_outsiders_without_revealing_ids()
             'mailDomain' => (string) $mailDomain->id,
             'approvalType' => (string) $type->id,
             'approvalRequest' => (string) $approvalRequest->id,
+            'approvalHoliday' => (string) $holiday->id,
         ];
 
         $outsiders = $this->outsiders();
@@ -547,10 +555,10 @@ public function test_every_admin_route_refuses_outsiders_without_revealing_ids()
         //   に出ている本当の理由（どのルート・どの変種・どの相手で止まらなかったか）が隠れる。
         $this->assertSame([], $problems, "権限の無い利用者を止められていないルート:\n" . implode("\n", $problems));
 
-        // 走査が空振りして緑になる事故を防ぐ（2b の実測 26 ルート＝2a の 22 本＋進行中の申請の管理 4 本。
-        // パラメータの有無で 1 変種・2 変種、いずれもメソッド 1 つずつ、× 6 人 ＝ 234 件）
-        $this->assertGreaterThanOrEqual(26, $adminRoutesChecked, 'approvals.admin. のルートの走査に失敗している');
-        $this->assertGreaterThanOrEqual(234, $requestsMade, '要求した件数が想定より少ない（走査が空振りしている）');
+        // 走査が空振りして緑になる事故を防ぐ（3b の実測 30 ルート＝2a の 22 本＋進行中の申請の管理 4 本＋催促の設定 4 本。
+        // パラメータの有無で 1 変種・2 変種、いずれもメソッド 1 つずつ、× 6 人 ＝ 270 件）
+        $this->assertGreaterThanOrEqual(30, $adminRoutesChecked, 'approvals.admin. のルートの走査に失敗している');
+        $this->assertGreaterThanOrEqual(270, $requestsMade, '要求した件数が想定より少ない（走査が空振りしている）');
 
         // ⚠ これが唯一、門番の判定を $next() の後ろへ動かす変異（クライアントには 403 の
         //   まま返るが、コントローラの副作用は既に実行済み）を検出できる。⚠ ただし検出できる
```

`tests/Feature/Approval/Phase2/LaunchGateTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/LaunchGateTest.php
+++ b/tests/Feature/Approval/Phase2/LaunchGateTest.php
@@ -28,6 +28,7 @@ class LaunchGateTest extends TestCase
         'approvals.admin.users.'        => '利用者の管理（段階1。準備の画面）',
         'approvals.admin.organization.' => '部門の管理（準備の画面。本番で先に登録する。D1）',
         'approvals.admin.types.'        => '申請種類の管理（準備の画面。D1）',
+        'approvals.admin.holidays.'     => '催促の設定（準備の画面。使い始める前から送らない日を登録できる。段階3 設計書 §5.9）',
     ];
 
     /**
```

`tests/Feature/Approval/Phase3/MailFailureBannerTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase3/MailFailureBannerTest.php
+++ b/tests/Feature/Approval/Phase3/MailFailureBannerTest.php
@@ -35,7 +35,7 @@ protected function setUp(): void
         ApprovalSetting::current();   // 本番は SQL が入れた 1 行がある
     }
 
-    /** 決裁の管理者の画面（使い始める前）: ホーム（準備中）と管理の 3 画面 */
+    /** 決裁の管理者の画面（使い始める前）: ホーム（準備中）と管理の 4 画面（3b で催促の設定を足した） */
     private function adminPagesBeforeLaunch(): array
     {
         return [
@@ -43,6 +43,7 @@ private function adminPagesBeforeLaunch(): array
             route('approvals.admin.users.index'),
             route('approvals.admin.organization.index'),
             route('approvals.admin.types.index'),
+            route('approvals.admin.holidays.index'),
         ];
     }
```

`tests/Feature/Approval/ApprovalSidebarTest.php`（変更）

```diff
--- a/tests/Feature/Approval/ApprovalSidebarTest.php
+++ b/tests/Feature/Approval/ApprovalSidebarTest.php
@@ -324,6 +324,30 @@ public function test_the_type_management_link_is_offered_to_admins_before_launch
         }
     }
 
+    /** 催促の設定は、使い始める前から決裁の管理者に出る（段階3 設計書 §5.9） */
+    public function test_the_reminder_settings_link_is_offered_to_admins_before_launch(): void
+    {
+        // 決裁のみ利用者の管理者: 決裁のサイドバー
+        $sidebars = $this->sidebars(
+            $this->actingAs($this->approvalAdmin(UserRole::ApprovalOnly->value))->get(route('approvals.home'))->assertOk()->getContent()
+        );
+        foreach (['expanded', 'drawer'] as $key) {
+            $this->assertHasLink($sidebars[$key], route('approvals.admin.holidays.index'), '催促の設定', $key);
+        }
+
+        // 基幹を使う管理者: 基幹のサイドバーの「決裁の管理」
+        $sidebars = $this->sidebars($this->actingAs($this->approvalAdmin())->get('/dashboard/tenant')->assertOk()->getContent());
+        foreach (['expanded', 'drawer'] as $key) {
+            $this->assertHasLink($sidebars[$key], route('approvals.admin.holidays.index'), '催促の設定', $key);
+        }
+
+        // 管理者でない人には出さない
+        $plain = User::factory()->approvalOnly()->create(['must_change_password' => false]);
+        foreach ($this->sidebars($this->actingAs($plain)->get(route('approvals.home'))->assertOk()->getContent()) as $key => $aside) {
+            $this->assertStringNotContainsString(route('approvals.admin.holidays.index'), $aside, "{$key} に管理者でない人の催促の設定が出ている");
+        }
+    }
+
     /** 申請の画面へのリンクは、使い始めてから出す（準備中は誰にも見せない。段階2 設計書 D1） */
     public function test_the_request_links_appear_only_after_launch(): void
     {
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/3b/patches/0005-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase3/HolidaySettingsTest.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase2/LaunchGateTest.php tests/Feature/Approval/Phase3/MailFailureBannerTest.php tests/Feature/Approval/ApprovalSidebarTest.php
```

Expected: `ERRORS!` `Tests: 33, Assertions: 222, Errors: 11, Failures: 2.`

- `ApprovalSidebarTest::test_the_reminder_settings_link_is_offered_to_admins_before_launch` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.holidays.index] not defined.`
- `HolidaySettingsTest::test_the_screen_shows_the_next_send_day_the_last_run_and_the_days_off` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.holidays.index] not defined.`
- `HolidaySettingsTest::test_the_screen_says_so_when_no_send_day_comes_within_a_year` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.holidays.index] not defined.`
- `HolidaySettingsTest::test_a_day_off_can_be_added_from_the_rendered_form` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.holidays.index] not defined.`
- `HolidaySettingsTest::test_the_dates_and_the_description_are_checked` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.holidays.index] not defined.`
- `HolidaySettingsTest::test_a_yearly_period_must_be_shorter_than_a_year` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.holidays.index] not defined.`
- `HolidaySettingsTest::test_changes_and_deletions_are_recorded` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.holidays.update] not defined.`
- `HolidaySettingsTest::test_a_refused_edit_reopens_the_same_dialog` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.holidays.index] not defined.`
- `HolidaySettingsTest::test_people_other_than_the_approval_admin_cannot_open_it` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.holidays.index] not defined.`
- `MailFailureBannerTest::test_there_is_no_banner_while_mail_is_fine` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.holidays.index] not defined.`
- `MailFailureBannerTest::test_a_failure_shows_the_banner_on_the_admin_pages` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.holidays.index] not defined.`
- `ApprovalAdminGateTest::test_every_approvals_route_is_classified` — `approvals. のルートの走査に失敗している`
- `ApprovalAdminGateTest::test_every_admin_route_refuses_outsiders_without_revealing_ids` — `approvals.admin. のルートの走査に失敗している`

- [ ] **Step 3: ルート・和名・モデルの表示の部品を書く**

`routes/approval.php`（変更）

```diff
--- a/routes/approval.php
+++ b/routes/approval.php
@@ -1,6 +1,7 @@
 <?php
 
 use App\Http\Controllers\Approval\AdminRequestController;
+use App\Http\Controllers\Approval\HolidayController;
 use App\Http\Controllers\Approval\HomeController;
 use App\Http\Controllers\Approval\NoticeController;
 use App\Http\Controllers\Approval\OrganizationController;
@@ -81,6 +82,13 @@
     Route::post('/types', [TypeController::class, 'store'])->name('types.store');
     Route::put('/types/{approvalType}', [TypeController::class, 'update'])->name('types.update');
     Route::delete('/types/{approvalType}', [TypeController::class, 'destroy'])->name('types.destroy');
+
+    // 催促の設定（画面⑫。段階3 設計書 §5.9）。使い始める前から使える（準備の画面）
+    // ⚠ パラメータ名は `{approvalHoliday}`
+    Route::get('/holidays', [HolidayController::class, 'index'])->name('holidays.index');
+    Route::post('/holidays', [HolidayController::class, 'store'])->name('holidays.store');
+    Route::put('/holidays/{approvalHoliday}', [HolidayController::class, 'update'])->name('holidays.update');
+    Route::delete('/holidays/{approvalHoliday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');
 });
 
 /*
```

`lang/ja/validation.php`（変更）

```diff
--- a/lang/ja/validation.php
+++ b/lang/ja/validation.php
@@ -611,6 +611,10 @@
         'admin_reason'      => '理由',
         'assignee_user_id'  => '付け替え先',
 
+        // --- 決裁申請 段階3 ---
+        // 催促の設定（⑫・3b）。開始日・終了日は上の汎用の和名のまま。'description' は汎用の「内容」なので、画面で「説明」に上書きする
+        'repeats_yearly'    => '毎年繰り返す',
+
     ],
 
 ];
```

`app/Models/ApprovalHoliday.php`（変更）

```diff
--- a/app/Models/ApprovalHoliday.php
+++ b/app/Models/ApprovalHoliday.php
@@ -2,6 +2,7 @@
 
 namespace App\Models;
 
+use App\Support\JapanTime;
 use Carbon\CarbonInterface;
 use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Database\Eloquent\Model;
@@ -33,6 +34,27 @@ public function scopeOrdered(Builder $query): Builder
         return $query->orderBy('start_date')->orderBy('id');
     }
 
+    /** 画面のフォームと設定の変更の記録に使う形（日付は 'Y-m-d'。画面から来た値とそのまま比べられる） */
+    public function formValues(): array
+    {
+        return [
+            'start_date'     => JapanTime::format($this->start_date, 'Y-m-d'),
+            'end_date'       => JapanTime::format($this->end_date, 'Y-m-d'),
+            'repeats_yearly' => $this->repeats_yearly,
+            'description'    => $this->description,
+        ];
+    }
+
+    /** 一覧の期間（毎年は月と日だけ「12/29〜1/3」、その年だけは「2026/08/13〜2026/08/14」。1 日だけなら 1 つ） */
+    public function periodLabel(): string
+    {
+        $format = $this->repeats_yearly ? 'n/j' : 'Y/m/d';
+        $from   = JapanTime::format($this->start_date, $format);
+        $to     = JapanTime::format($this->end_date, $format);
+
+        return $from === $to ? $from : "{$from}〜{$to}";
+    }
+
     /**
      * その日（日本の暦の日付。JapanTime::today() と同じ形）がこの期間に入るか。
      *
```

- [ ] **Step 4: コントローラと画面を書く**

`app/Http/Controllers/Approval/HolidayController.php`（新規）

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalHoliday;
use App\Models\ApprovalReminderRun;
use App\Models\ApprovalSetting;
use App\Support\Approval\ReminderCalendar;
use App\Support\Approval\SettingLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * 催促の設定（画面⑫・段階3 設計書 §5.9・要件 8.3）。催促を送らない日（会社の休み）の登録と、次に催促を送る日・前回の催促。
 *
 * ⚠ 使い始める前から使える（準備の画面。門番は approval.admin だけ）。土日と日本の祝日は ReminderCalendar が自動で見る。
 * ⚠ 毎年繰り返す期間は 1 年より短く（利用者の決定 2026-10-02）。月と日だけで比べるので、1 年まるごとの期間は始まりと終わりの
 *   月日が重なり、どの日が入るかが分からなくなる。説明は必須・50 文字まで（同）。
 * ⚠ 追加・修正・削除は設定の変更の記録（SettingLogger）に残す。
 */
class HolidayController extends Controller
{
    public function index()
    {
        $holidays = ApprovalHoliday::ordered()->get();
        $nextDay  = (new ReminderCalendar())->nextSendDay();
        $lastRun  = ApprovalReminderRun::latestRun();
        // ⚠ 行を作らずに読む（管理の画面は使い始める前から開く）
        $launched = ApprovalSetting::launchedForMenu();

        return view('approvals.admin.holidays', compact('holidays', 'nextDay', 'lastRun', 'launched'));
    }

    public function store(Request $request)
    {
        $validated = $this->validateHoliday($request);

        $holiday = ApprovalHoliday::create($validated);
        SettingLogger::record('holiday.created', 'approval_holiday', $holiday->id, [], $validated);

        return $this->back('送らない日を登録しました。');
    }

    public function update(Request $request, ApprovalHoliday $approvalHoliday)
    {
        $validated = $this->validateHoliday($request);
        // ⚠ 日付は 'Y-m-d' の文字列で比べる（Carbon のままだと、変えていなくても「変わった」と記録される）
        $before    = $approvalHoliday->formValues();

        $approvalHoliday->update($validated);
        SettingLogger::recordChange('holiday.updated', 'approval_holiday', $approvalHoliday->id, $before, $validated);

        return $this->back('送らない日を更新しました。');
    }

    public function destroy(ApprovalHoliday $approvalHoliday)
    {
        $before = $approvalHoliday->formValues();
        $id     = $approvalHoliday->id;
        $approvalHoliday->delete();

        SettingLogger::record('holiday.deleted', 'approval_holiday', $id, $before, []);

        return $this->back('送らない日を削除しました。');
    }

    private function validateHoliday(Request $request): array
    {
        // チェックボックスは外すと送られない（送られなければ繰り返さない）
        $request->merge(['repeats_yearly' => $request->boolean('repeats_yearly')]);

        return $request->validate([
            // ⚠ date_format で存在しない日付（2026-02-30）を断る（strtotime は繰り上げて通す。Top trap #15）
            'start_date'     => ['required', 'date_format:Y-m-d'],
            'end_date'       => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date', function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                if (! $request->boolean('repeats_yearly') || ! is_string($value) || ! is_string($request->input('start_date'))) {
                    return;
                }

                $start = CarbonImmutable::createFromFormat('!Y-m-d', $request->input('start_date'));
                $end   = CarbonImmutable::createFromFormat('!Y-m-d', $value);

                // 開始日の 1 年後（2/29 は翌年の 2/28）と同じ日か後なら断る
                if ($start !== false && $end !== false && $end->gte($start->addYearNoOverflow())) {
                    $fail('毎年繰り返す期間は 1 年より短くしてください。');
                }
            }],
            'repeats_yearly' => ['boolean'],
            'description'    => ['required', 'string', 'max:50'],
        ], [
            'start_date.required'     => '開始日を入力してください。',
            'start_date.date_format'  => '開始日は正しい日付で入力してください。',
            'end_date.required'       => '終了日を入力してください。',
            'end_date.date_format'    => '終了日は正しい日付で入力してください。',
            // 年をまたぐ毎年の期間（12/29〜1/3）を、終了日を同じ年のまま打ったときにも直し方が分かる文にする（利用者の決定 2026-10-02）
            'end_date.after_or_equal' => '終了日は開始日より前にできません（年をまたぐ期間は、終了日を次の年の日付にしてください。例: 2026/12/29〜2027/1/3）。',
            'description.required'    => '説明を入力してください（例: 年末年始）。',
            'description.max'         => '説明は50文字以内で入力してください。',
        ], [
            'description' => '説明',
        ]);
    }

    private function back(string $success)
    {
        return redirect()->route('approvals.admin.holidays.index')->with('success', $success);
    }
}
```

`resources/views/approvals/admin/holidays.blade.php`（新規）

```blade
@extends('layouts.app')

@section('title', '催促の設定')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">催促の設定</span>
@endsection

@section('content')
@php
    // 断られた入力を、送った小窓に戻して開き直す（申請種類の管理と同じ形）。どの送らない日かの edit_id を送るのは編集の小窓だけ。
    // ⚠ 手で組んだ送信の配列などは文字として扱わない
    $oldText = fn (string $key): string => is_string(old($key)) ? old($key) : '';
    $refused = $errors->any();
    $editId  = is_string(old('edit_id')) ? (int) old('edit_id') : 0;
    $refusedEdit = $refused && $holidays->contains('id', $editId) ? [
        'id'             => $editId,
        'start_date'     => $oldText('start_date'),
        'end_date'       => $oldText('end_date'),
        'repeats_yearly' => (bool) old('repeats_yearly'),
        'description'    => $oldText('description'),
    ] : null;
    $refusedCreate = $refused && old('edit_id') === null && old('_method') === null;
@endphp
<div x-data="approvalHolidays()" x-cloak>
    @include('approvals._mail_failure')

    {{-- 成功・失敗の帯はレイアウトが出す（ここで出すと画面に 2 回出る）。$errors だけ各ビューの責任 --}}
    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <h1 class="text-lg font-bold text-gray-900 mb-2">催促の設定</h1>
    <p class="text-[12px] text-gray-500 mb-5 max-w-[720px]">
        対応を待っている申請が「3 日待ち」以上になった人に、平日の朝 9 時にまとめメールを 1 通送ります。
        土曜・日曜と祝日（振替休日・国民の休日を含む）は自動で送りません。会社の休み（年末年始・夏季休暇など）は、下の「送らない日」に登録してください。
    </p>

    {{-- 次に送る日と前回の催促（要件 13 章の⑫） --}}
    <section class="bg-white rounded-lg border border-gray-200 mb-5 px-4 py-3">
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <dt class="text-[11px] font-semibold text-gray-500">次に催促を送る日</dt>
                <dd class="mt-0.5 text-[14px] font-semibold text-gray-900">
                    @if($nextDay !== null)
                        {{ \App\Support\Approval\ReminderCalendar::label($nextDay) }} 9 時
                    @else
                        1 年以内にありません（送らない日の登録を確かめてください）
                    @endif
                </dd>
                @unless($launched)
                    <dd class="mt-0.5 text-[11px] text-gray-500">決裁を使い始める前なので、まだ送りません。</dd>
                @endunless
            </div>
            <div>
                <dt class="text-[11px] font-semibold text-gray-500">前回の催促</dt>
                <dd class="mt-0.5 text-[14px] text-gray-900">
                    @if($lastRun !== null)
                        {{ \App\Support\Approval\ReminderCalendar::label($lastRun->sent_on) }}（{{ $lastRun->recipient_count }} 人・{{ $lastRun->item_count }} 件）
                    @else
                        まだありません
                    @endif
                </dd>
            </div>
        </dl>
    </section>

    <section class="bg-white rounded-lg border border-gray-200">
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
            <h2 class="text-[14px] font-bold text-gray-900">送らない日</h2>
            <button type="button" @click="createModal = true" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-semibold rounded-md cursor-pointer">送らない日を追加</button>
        </div>
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
        <table class="w-full min-w-[560px] border-collapse">
            <thead>
                <tr>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">期間</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">繰り返し</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">説明</th>
                    <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($holidays as $holiday)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-900 whitespace-nowrap tabular-nums">{{ $holiday->periodLabel() }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $holiday->repeats_yearly ? '毎年' : 'その年だけ' }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $holiday->description }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-right whitespace-nowrap">
                            <button type="button" @click="openEdit({{ \Illuminate\Support\Js::from(['id' => $holiday->id] + $holiday->formValues()) }})" class="text-[12px] text-blue-600 hover:underline cursor-pointer bg-transparent border-none p-0">編集</button>
                            <span class="text-gray-200 mx-1">|</span>
                            <form method="POST" action="{{ route('approvals.admin.holidays.destroy', $holiday) }}" class="inline" onsubmit="return confirm('この送らない日を削除しますか。');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-[12px] text-red-600 hover:underline cursor-pointer bg-transparent border-none p-0">削除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-[13px] text-gray-400">送らない日は登録されていません（土日と祝日だけ送りません）。</td></tr>
                @endforelse
            </tbody>
        </table>
            </div>{{-- /scroll-hint-inner --}}
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>{{-- /scroll-hint --}}
    </section>

    {{-- 送らない日の追加（追加と編集でフォームを分ける。申請種類の管理と同じ形） --}}
    <div x-show="createModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="createModal = false" class="bg-white rounded-xl w-full max-w-[480px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" action="{{ route('approvals.admin.holidays.store') }}"
                  x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                @csrf
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">送らない日の追加</div>
                @if($refusedCreate)
                    {{-- 断られた理由を小窓の中にも出す（上の帯は開き直した小窓に隠れる） --}}
                    <div class="mx-6 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-700">
                        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                    </div>
                @endif
                <div class="px-6 py-4 space-y-3.5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[12px] font-semibold text-gray-700 mb-1">開始日<span class="text-red-600 ml-0.5">*</span></label>
                            <input type="date" name="start_date" value="{{ $refusedCreate ? $oldText('start_date') : '' }}" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                        </div>
                        <div>
                            <label class="block text-[12px] font-semibold text-gray-700 mb-1">終了日<span class="text-red-600 ml-0.5">*</span></label>
                            <input type="date" name="end_date" value="{{ $refusedCreate ? $oldText('end_date') : '' }}" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                        </div>
                    </div>
                    <p class="text-[11px] text-gray-400 -mt-2">1 日だけなら、開始日と終了日を同じ日にします。</p>
                    <label class="flex items-start gap-2 text-[13px] text-gray-800 cursor-pointer">
                        <input type="checkbox" name="repeats_yearly" value="1" class="mt-1"{{ $refusedCreate && old('repeats_yearly') ? ' checked' : '' }}>
                        <span>毎年繰り返す（月と日だけで比べます。12/29〜1/3 のように年をまたぐ期間も 1 件で登録できます）</span>
                    </label>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">説明<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="description" value="{{ $refusedCreate ? $oldText('description') : '' }}" required maxlength="50" placeholder="例: 年末年始" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                </div>
                <div class="px-6 pb-5 flex flex-wrap items-center justify-end gap-2">
                    <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                    <button type="button" @click="createModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" :disabled="submitting" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">保存する</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 送らない日の編集 --}}
    <div x-show="editModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="editModal = false" class="bg-white rounded-xl w-full max-w-[480px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" :action="'{{ url('approvals/admin/holidays') }}/' + editId"
                  x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                @csrf
                @method('PUT')
                {{-- どの送らない日の小窓か（断られたときに同じ日の小窓を開き直す） --}}
                <input type="hidden" name="edit_id" :value="editId">
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">送らない日の編集</div>
                @if($refusedEdit !== null)
                    {{-- この小窓は送らない日ごとに使い回すので、断られた日を編集しているあいだだけ出す --}}
                    <div x-show="editId === {{ $refusedEdit['id'] }}" class="mx-6 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-700">
                        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                    </div>
                @endif
                <div class="px-6 py-4 space-y-3.5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[12px] font-semibold text-gray-700 mb-1">開始日<span class="text-red-600 ml-0.5">*</span></label>
                            <input type="date" name="start_date" x-model="editStart" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                        </div>
                        <div>
                            <label class="block text-[12px] font-semibold text-gray-700 mb-1">終了日<span class="text-red-600 ml-0.5">*</span></label>
                            <input type="date" name="end_date" x-model="editEnd" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                        </div>
                    </div>
                    <label class="flex items-start gap-2 text-[13px] text-gray-800 cursor-pointer">
                        <input type="checkbox" name="repeats_yearly" value="1" x-model="editYearly" class="mt-1">
                        <span>毎年繰り返す（月と日だけで比べます）</span>
                    </label>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">説明<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="description" x-model="editDescription" required maxlength="50" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                </div>
                <div class="px-6 pb-5 flex flex-wrap items-center justify-end gap-2">
                    <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                    <button type="button" @click="editModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" :disabled="submitting" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">保存する</button>
                </div>
            </form>
        </div>
    </div>

</div>

{{-- 保存の二度押し止めの部品（approvalSubmitOnce。定義は 1 か所） --}}
@include('approvals._submit_once')
@endsection

@push('scripts')
<script>
function approvalHolidays() {
    return {
        // 断られた入力で開き直す。追加の小窓はサーバーが打った中身を描き、編集の小窓は init で打った中身を入れる
        createModal: {{ $refusedCreate ? 'true' : 'false' }},
        editModal: false,
        editId: null,
        editStart: '',
        editEnd: '',
        editYearly: false,
        editDescription: '',

        init() {
            var refused = {{ \Illuminate\Support\Js::from($refusedEdit) }};
            if (refused) {
                this.openEdit(refused);
            }
        },

        openEdit(row) {
            this.editId = row.id;
            this.editStart = row.start_date;
            this.editEnd = row.end_date;
            this.editYearly = row.repeats_yearly;
            this.editDescription = row.description;
            this.editModal = true;
        }
    };
}
</script>
@endpush
```

- [ ] **Step 5: 入口を足す**（サイドバー 4 か所・ホーム 2 画面。どれも「申請種類の管理」の下）

`resources/views/layouts/partials/sidebar.blade.php`（変更）

```diff
--- a/resources/views/layouts/partials/sidebar.blade.php
+++ b/resources/views/layouts/partials/sidebar.blade.php
@@ -160,6 +160,7 @@ class="inline-flex items-center gap-1 px-2 py-1 rounded-md border border-gray-30
             <x-sidebar-item :href="route('approvals.admin.users.index')" label="利用者の管理" :active="request()->routeIs('approvals.admin.users.*')" />
             <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
             <x-sidebar-item :href="route('approvals.admin.types.index')" label="申請種類の管理" :active="request()->routeIs('approvals.admin.types.*')" />
+            <x-sidebar-item :href="route('approvals.admin.holidays.index')" label="催促の設定" :active="request()->routeIs('approvals.admin.holidays.*')" />
             @if($approvalsLaunched)
                 <x-sidebar-item :href="route('approvals.admin.requests.index')" label="進行中の申請の管理" :active="request()->routeIs('approvals.admin.requests.*')" />
             @endif
@@ -478,6 +479,7 @@ class="fixed inset-y-0 left-0 z-30 w-[260px] bg-white overflow-y-auto pt-4 pb-6
             <x-sidebar-item :href="route('approvals.admin.users.index')" label="利用者の管理" :active="request()->routeIs('approvals.admin.users.*')" />
             <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
             <x-sidebar-item :href="route('approvals.admin.types.index')" label="申請種類の管理" :active="request()->routeIs('approvals.admin.types.*')" />
+            <x-sidebar-item :href="route('approvals.admin.holidays.index')" label="催促の設定" :active="request()->routeIs('approvals.admin.holidays.*')" />
             @if($approvalsLaunched)
                 <x-sidebar-item :href="route('approvals.admin.requests.index')" label="進行中の申請の管理" :active="request()->routeIs('approvals.admin.requests.*')" />
             @endif
```

`resources/views/layouts/partials/sidebar_approval.blade.php`（変更）

```diff
--- a/resources/views/layouts/partials/sidebar_approval.blade.php
+++ b/resources/views/layouts/partials/sidebar_approval.blade.php
@@ -39,6 +39,7 @@ class="inline-flex items-center gap-1 px-2 py-1 rounded-md border border-gray-30
             <x-sidebar-item :href="route('approvals.admin.users.index')" label="利用者の管理" :active="request()->routeIs('approvals.admin.users.*')" />
             <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
             <x-sidebar-item :href="route('approvals.admin.types.index')" label="申請種類の管理" :active="request()->routeIs('approvals.admin.types.*')" />
+            <x-sidebar-item :href="route('approvals.admin.holidays.index')" label="催促の設定" :active="request()->routeIs('approvals.admin.holidays.*')" />
             @if($approvalsLaunched)
                 <x-sidebar-item :href="route('approvals.admin.requests.index')" label="進行中の申請の管理" :active="request()->routeIs('approvals.admin.requests.*')" />
             @endif
@@ -85,6 +86,7 @@ class="p-1 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transi
         <x-sidebar-item :href="route('approvals.admin.users.index')" label="利用者の管理" :active="request()->routeIs('approvals.admin.users.*')" />
         <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
         <x-sidebar-item :href="route('approvals.admin.types.index')" label="申請種類の管理" :active="request()->routeIs('approvals.admin.types.*')" />
+        <x-sidebar-item :href="route('approvals.admin.holidays.index')" label="催促の設定" :active="request()->routeIs('approvals.admin.holidays.*')" />
         @if($approvalsLaunched)
             <x-sidebar-item :href="route('approvals.admin.requests.index')" label="進行中の申請の管理" :active="request()->routeIs('approvals.admin.requests.*')" />
         @endif
```

`resources/views/approvals/home.blade.php`（変更）

```diff
--- a/resources/views/approvals/home.blade.php
+++ b/resources/views/approvals/home.blade.php
@@ -42,6 +42,7 @@
                 <a href="{{ route('approvals.admin.users.index') }}" class="text-emerald-600 hover:underline">利用者の管理</a>
                 <a href="{{ route('approvals.admin.organization.index') }}" class="text-emerald-600 hover:underline">部門の管理</a>
                 <a href="{{ route('approvals.admin.types.index') }}" class="text-emerald-600 hover:underline">申請種類の管理</a>
+                <a href="{{ route('approvals.admin.holidays.index') }}" class="text-emerald-600 hover:underline">催促の設定</a>
             @endif
         </div>
     </div>
```

`resources/views/approvals/home-launched.blade.php`（変更）

```diff
--- a/resources/views/approvals/home-launched.blade.php
+++ b/resources/views/approvals/home-launched.blade.php
@@ -110,6 +110,7 @@
             <a href="{{ route('approvals.admin.users.index') }}" class="text-emerald-600 hover:underline">利用者の管理</a>
             <a href="{{ route('approvals.admin.organization.index') }}" class="text-emerald-600 hover:underline">部門の管理</a>
             <a href="{{ route('approvals.admin.types.index') }}" class="text-emerald-600 hover:underline">申請種類の管理</a>
+            <a href="{{ route('approvals.admin.holidays.index') }}" class="text-emerald-600 hover:underline">催促の設定</a>
         @endif
     </div>
 </div>
```

（差分のファイルを使うなら: `git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/3b/patches/0005-*.patch`）

- [ ] **Step 6: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (33 tests, …)`

- [ ] **Step 7: 全件を流す**

Expected: `OK (3294 tests, 23498 assertions)`

- [ ] **Step 8: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Http/Controllers/Approval/HolidayController.php resources/views/approvals/admin/holidays.blade.php app/Models/ApprovalHoliday.php routes/approval.php lang/ja/validation.php resources/views/layouts/partials/sidebar.blade.php resources/views/layouts/partials/sidebar_approval.blade.php resources/views/approvals/home.blade.php resources/views/approvals/home-launched.blade.php tests/Feature/Approval/Phase3/HolidaySettingsTest.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase2/LaunchGateTest.php tests/Feature/Approval/Phase3/MailFailureBannerTest.php tests/Feature/Approval/ApprovalSidebarTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 催促の設定の画面（⑫）を足す

決裁の管理者が催促を送らない日（期間・毎年繰り返すか・説明）を登録・
修正・削除し、次に催促を送る日と前回の催促を見られる画面を足す（段階3
設計書 §5.9）。使い始める前から開く。毎年繰り返す期間は 1 年より短く、
説明は必須（利用者の決定）。変更は設定の変更の記録に残す。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 6: 全件テストと変異テスト（Bug #44 の作法）

**Files:** なし（測るだけ。穴が見つかったらテストを足してコミットする）

計画を書く段階で、試作（この計画のコードと同じ中身）に下の表の変異を 1 つずつ当てて測った（2026-10-02。決裁のテスト〈`tests/Feature/Approval`・`tests/Unit/Approval`〉と `ScheduleTest`・走査テスト 7 本を流した。表・送る日・コマンドの 36 通り〈T・H・C・M〉は `7ae25fa3` の上の試作で、カナリアと全員分・⑫ の 24 通り〈P・V〉は `91fe7969` の上の最終の試作で測った。あいだで変わったのは土台の `13.x`〈周辺ビルの取込。流したテストにも 3b のファイルにも関わらない〉と、⑫ の断りの文・全員分と ⑫ のテストだけ）。この Task では、実装したコードで**同じ結果になること**を確かめる。

⚠ WT のファイルを一時的に壊す変異は、自動の許可の判定に断られる。**WT の HEAD の写しを scratchpad に作ってそこで当てる**（WT は読むだけ）。以下の `<scratchpad>` は、その会話の scratchpad のパス（Mac を再起動すると消える。残したい結果は `~/.claude/plans/approval-phase3-tasks/3b/` へ写す）:

```bash
SCR=<scratchpad>/p3b-mutation && mkdir -p "$SCR" && git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 archive HEAD | tar -x -C "$SCR" && cp -Rc /Users/masanori/site/manage/.claude/worktrees/approval-phase3/vendor "$SCR/vendor" && ! test -L "$SCR/vendor" && test -d "$SCR/vendor/azuyalabs/yasumi" && echo "写し OK"
```

- [ ] **Step 1: 全件が緑の状態から始める**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git status --porcelain && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `git status --porcelain` が空・`OK (3294 tests, 23498 assertions)`

- [ ] **Step 2: 変異を当てて表と突き合わせる**

道具は `~/.claude/plans/approval-phase3-tasks/3b/mutate.py`（3a の道具の写しで、変異の一覧だけ 3b にしたもの。1 つ当てて流し、必ず元に戻して、戻ったことを確かめる。書き換える前の文字列が 1 回だけ現れないものは当てずに SKIP と記録する）。先に `--check` で、当てる場所がちょうど 1 回ずつ見つかることを確かめてから流す（1 通り約 45 秒・全部で約 45 分）:

```bash
python3 ~/.claude/plans/approval-phase3-tasks/3b/mutate.py <scratchpad>/p3b-mutation /dev/null --check
python3 ~/.claude/plans/approval-phase3-tasks/3b/mutate.py <scratchpad>/p3b-mutation <scratchpad>/mutations.jsonl
```

Expected（1 行目）: `NG` の行が無く `checked 60`。途中で止めるときは python に SIGINT（写しのファイルが元に戻る）。同じ出力のファイルを渡して流し直すと、記録済みの変異を飛ばして続きから流す。

⚠ **最初の `CANARY`（⑫ の見出しに未定義の変数）が赤になること**を確かめてから結果を読む（測定が写しのコードを読んでいることの証明）。
⚠ **赤/緑ではなく「落ちたテストの集合」と「落ちた理由の文言」まで突き合わせる。** 意図と別の機構が落としているなら、その変異の測定は無効。当て方を変えて測り直す。

計画の時点の実測（試作。「落ちたテスト」はクラス名を省いたテストの名前）:

| # | 変異（ファイル） | 結果 | 落ちたテスト（実測） | 判定 |
|---|---|---|---|---|
| CANARY | カナリア: ⑫ の見出しに未定義の変数（`holidays.blade.php`） | Tests: 936, Assertions: 7092, Failures: 6. | 6 本（HolidaySettingsTest, MailFailureBannerTest ほか） | カナリア（赤が正しい） |
| T01 | SQL から送った日の一意の索引を消す（`2026-10-02-approval-phase3b.sql`） | Tests: 936, Assertions: 7122, Failures: 1. | `test_the_sent_date_is_unique_in_the_sql_and_the_migration` | 検出 |
| T02 | migration から送った日の一意の索引を消す（`2026_10_02_000001_create_approval_phase3b_tables.php`） | Tests: 936, Assertions: 7118, Failures: 3. | `test_it_sends_once_a_day_and_again_on_the_next_send_day`、`test_the_same_day_cannot_be_recorded_twice`、`test_the_sent_date_is_unique_in_the_sql_and_the_migration` | 検出 |
| T03 | migration の説明の列の NULL を許す（`2026_10_02_000001_create_approval_phase3b_tables.php`） | Tests: 936, Assertions: 7121, Failures: 1. | `test_the_sql_and_the_migration_declare_the_same_columns` | 検出 |
| H01 | 年をまたぐ毎年の期間を比べ損なう（`ApprovalHoliday.php`） | Tests: 936, Assertions: 7116, Failures: 3. | `test_a_yearly_period_can_cross_the_new_year`、`test_the_next_send_day_after_skips_weekends_holidays_and_days_off`、`test_the_registered_days_off_are_not_send_days` | 検出 |
| H02 | その年だけの期間の終了日を含めない（`ApprovalHoliday.php`） | Tests: 936, Assertions: 7119, Failures: 4. | `test_a_day_off_can_be_added_from_the_rendered_form`、`test_a_one_time_period_covers_its_dates_inclusively`、`test_a_single_day`、`test_nothing_is_sent_on_days_off` | 検出 |
| H03 | 毎年繰り返すを年月日で比べる（`ApprovalHoliday.php`） | Tests: 936, Assertions: 7118, Failures: 4. | `test_a_yearly_period_can_cross_the_new_year`、`test_a_yearly_period_is_compared_by_month_and_day`、`test_february_the_twenty_ninth_in_a_yearly_period`、`test_the_registered_days_off_are_not_send_days` | 検出 |
| C01 | 土曜日を送る日にする（`ReminderCalendar.php`） | Tests: 936, Assertions: 7102, Failures: 7. | `test_a_day_off_can_be_added_from_the_rendered_form`、`test_every_day_of_2026_and_2027_matches_the_cabinet_office_list_and_weekends`、`test_nothing_is_sent_on_days_off`、`test_the_next_send_day_after_skips_weekends_holidays_and_days_off`、`test_the_next_send_day_is_today_only_before_five_past_nine_and_before_sending`、`test_the_reasons_name_the_weekday_or_the_holiday` ほか 1 本 | 検出 |
| C02 | 祝日の表を 2026 年だけで引く（`ReminderCalendar.php`） | Tests: 936, Assertions: 7122, Failures: 2. | `test_every_day_of_2026_and_2027_matches_the_cabinet_office_list_and_weekends`、`test_the_registered_days_off_are_not_send_days` | 検出 |
| C03 | 登録した送らない日を見ない（`ReminderCalendar.php`） | Tests: 936, Assertions: 7112, Failures: 6. | `test_a_day_off_can_be_added_from_the_rendered_form`、`test_nothing_is_sent_on_days_off`、`test_the_next_send_day_after_skips_weekends_holidays_and_days_off`、`test_the_registered_days_off_are_not_send_days`、`test_the_screen_says_so_when_no_send_day_comes_within_a_year`、`test_there_is_no_next_send_day_when_a_year_is_blocked` | 検出 |
| C04 | 9:05 を過ぎても今日を次に送る日にする（`ReminderCalendar.php`） | Tests: 936, Assertions: 7113, Failures: 4. | `test_a_day_off_can_be_added_from_the_rendered_form`、`test_php_clock_reads_are_classified`、`test_the_next_send_day_is_today_only_before_five_past_nine_and_before_sending`、`test_the_screen_shows_the_next_send_day_the_last_run_and_the_days_off` | 検出 |
| C05 | 今日の分を送ったあとも今日を次に送る日にする（`ReminderCalendar.php`） | Tests: 936, Assertions: 7121, Failures: 1. | `test_the_next_send_day_is_today_only_before_five_past_nine_and_before_sending` | 検出 |
| C06 | 次に送る日の今日を UTC で作る（`ReminderCalendar.php`） | Tests: 936, Assertions: 7122, Failures: 2. | `test_php_clock_reads_are_classified`、`test_the_next_send_day_is_today_only_before_five_past_nine_and_before_sending` | 検出 |
| C07 | その日自身を次に送る日に含める（`ReminderCalendar.php`） | Tests: 936, Assertions: 7111, Failures: 4. | `test_a_day_off_can_be_added_from_the_rendered_form`、`test_the_next_send_day_after_skips_weekends_holidays_and_days_off`、`test_the_next_send_day_is_today_only_before_five_past_nine_and_before_sending`、`test_the_screen_shows_the_next_send_day_the_last_run_and_the_days_off` | 検出 |
| C08 | 日付の曜日を出さない（`ReminderCalendar.php`） | Tests: 936, Assertions: 7114, Failures: 3. | `test_a_day_off_can_be_added_from_the_rendered_form`、`test_the_label_has_the_japanese_weekday`、`test_the_screen_shows_the_next_send_day_the_last_run_and_the_days_off` | 検出 |
| P01 | 全員分で自分の申請を除かない（`PendingWork.php`） | Tests: 936, Assertions: 7116, Failures: 1. | `test_everyone_gives_each_person_exactly_what_for_gives` | 検出 |
| P02 | 全員分で付け替え先を見ない（`PendingWork.php`） | Tests: 936, Assertions: 7113, Failures: 1. | `test_everyone_gives_each_person_exactly_what_for_gives` | 検出 |
| P03 | 全員分で社長の段階を落とす（`PendingWork.php`） | Tests: 936, Assertions: 7116, Failures: 1. | `test_everyone_gives_each_person_exactly_what_for_gives` | 検出 |
| P04 | 全員分で条件確認待ちを落とす（`PendingWork.php`） | Tests: 936, Assertions: 7116, Failures: 1. | `test_everyone_gives_each_person_exactly_what_for_gives` | 検出 |
| P05 | for() の申請者の番を id の順に読まない（`PendingWork.php`） | Tests: 936, Assertions: 7116, Failures: 1. | `test_everyone_gives_each_person_exactly_what_for_gives` | 検出 |
| P06 | 全員分で id の型をそろえない（SQLite は整数で返すので等価の見込み）（`PendingWork.php`） | OK (936 tests, 7126 assertions) | — | 等価: SQLite のテストは id を整数で返す（MySQL の接続の設定で文字が来たときの備え。§0.4） |
| P07 | 全員分で申請を前もって読まない（N+1）（`PendingWork.php`） | Tests: 936, Assertions: 7125, Failures: 1. | `test_the_number_of_queries_does_not_grow_with_people_or_requests` | 検出 |
| P08 | 全員分を番が来た順に並べ替えない（別の会話の測定で見つかった穴。テストを足した）（`PendingWork.php`） | Tests: 936, Assertions: 7121, Failures: 1. | `test_everyone_gives_each_person_exactly_what_for_gives` | 検出 |
| M01 | 2 日待ちから載せる（`ApprovalRemindCommand.php`） | Tests: 936, Assertions: 7117, Failures: 1. | `test_each_person_gets_one_digest_of_the_requests_waiting_three_days_or_more` | 検出 |
| M02 | 3 日待ちを載せない（`ApprovalRemindCommand.php`） | Tests: 936, Assertions: 7117, Failures: 1. | `test_each_person_gets_one_digest_of_the_requests_waiting_three_days_or_more` | 検出 |
| M03 | 使い始める前でも送る（`ApprovalRemindCommand.php`） | Tests: 936, Assertions: 7121, Failures: 1. | `test_nothing_is_sent_before_launch` | 検出 |
| M04 | 送らない日にも送る（`ApprovalRemindCommand.php`） | Tests: 936, Assertions: 7117, Failures: 1. | `test_nothing_is_sent_on_days_off` | 検出 |
| M05 | 一意で断られたときに止まらない（`ApprovalRemindCommand.php`） | Tests: 936, Assertions: 7116, Errors: 1. | `test_it_sends_once_a_day_and_again_on_the_next_send_day` | 検出 |
| M06 | まとめメールをトランザクションの外で積む（`ApprovalRemindCommand.php`） | Tests: 936, Assertions: 7114, Failures: 2. | `test_it_sends_once_a_day_and_again_on_the_next_send_day`、`test_the_run_and_the_queued_mails_roll_back_together` | 検出 |
| M07 | 無効の人にも送る（`ApprovalRemindCommand.php`） | Tests: 936, Assertions: 7122, Failures: 1. | `test_only_active_people_with_an_allowed_address_get_a_digest` | 検出 |
| M08 | 許可していないドメインにも送る（`ApprovalRemindCommand.php`） | Tests: 936, Assertions: 7122, Failures: 1. | `test_only_active_people_with_an_allowed_address_get_a_digest` | 検出 |
| M09 | 1 通に 21 件載せる（`ApprovalRemindCommand.php`） | Tests: 936, Assertions: 7118, Failures: 1. | `test_a_digest_lists_twenty_at_most_and_tells_how_many_more` | 検出 |
| M10 | 件数を載せた分だけにする（`ApprovalRemindCommand.php`） | Tests: 936, Assertions: 7116, Failures: 1. | `test_a_digest_lists_twenty_at_most_and_tells_how_many_more` | 検出 |
| M11 | リンクを route() のまま作る（`ApprovalRemindCommand.php`） | Tests: 936, Assertions: 7115, Failures: 3. | `test_each_person_gets_one_digest_of_the_requests_waiting_three_days_or_more`、`test_the_links_do_not_repeat_the_sub_path_of_the_app_url`、`test_the_mail_text_and_links` | 検出 |
| M12 | リンクの道に元の道を重ねる（`ApprovalRemindCommand.php`） | Tests: 936, Assertions: 7115, Failures: 3. | `test_each_person_gets_one_digest_of_the_requests_waiting_three_days_or_more`、`test_the_links_do_not_repeat_the_sub_path_of_the_app_url`、`test_the_mail_text_and_links` | 検出 |
| M13 | リンクの元の既定に /index.php を足さない（`approval.php`） | Tests: 936, Assertions: 7121, Failures: 2. | `test_each_person_gets_one_digest_of_the_requests_waiting_three_days_or_more`、`test_the_default_link_root_adds_the_front_controller_to_the_app_url` | 検出 |
| M14 | 件名の改行を残す（`ApprovalRemindCommand.php`） | Tests: 936, Assertions: 7118, Failures: 1. | `test_the_mail_text_and_links` | 検出 |
| M15 | 本文の件名をエスケープする（`approval-reminder.blade.php`） | Tests: 936, Assertions: 7118, Failures: 1. | `test_the_mail_text_and_links` | 検出 |
| M16 | 残りの件数を書かない（`approval-reminder.blade.php`） | Tests: 936, Assertions: 7122, Failures: 1. | `test_a_digest_lists_twenty_at_most_and_tells_how_many_more` | 検出 |
| M17 | 差出人の名前を全体の設定にする（`ApprovalReminderMail.php`） | Tests: 936, Assertions: 7117, Failures: 1. | `test_the_mail_text_and_links` | 検出 |
| M18 | 送れなかった宛先の名前を残さない（`ApprovalReminderMail.php`） | Tests: 936, Assertions: 7123, Failures: 1. | `test_the_digest_is_retried_and_a_sent_or_failed_digest_is_recorded` | 検出 |
| M19 | 催促の予定を 10 時にする（`console.php`） | Tests: 936, Assertions: 7113, Failures: 2. | `test_the_approval_reminder_runs_between_nine_and_nine_oh_four_japan_time`、`test_the_approval_reminder_runs_exactly_once_a_day_whatever_minute_the_cron_starts_at` | 検出 |
| M20 | 催促の予定をキュー処理の後ろに置く（`console.php`） | Tests: 936, Assertions: 7123, Failures: 1. | `test_the_approval_reminder_is_scheduled_before_the_queue_worker` | 検出 |
| M21 | 送った人数を記録しない（`ApprovalRemindCommand.php`） | Tests: 936, Assertions: 7123, Failures: 1. | `test_each_person_gets_one_digest_of_the_requests_waiting_three_days_or_more` | 検出 |
| M22 | 送る相手がいない日を記録しない（`ApprovalRemindCommand.php`） | Tests: 936, Assertions: 7122, Failures: 1. | `test_a_day_with_nobody_to_remind_is_recorded` | 検出 |
| V01 | ちょうど 1 年の毎年の期間を通す（`HolidayController.php`） | Tests: 936, Assertions: 7118, Failures: 1. | `test_a_yearly_period_must_be_shorter_than_a_year` | 検出 |
| V02 | 2/29 の 1 年後を 3/1 にする（`HolidayController.php`） | Tests: 936, Assertions: 7120, Failures: 1. | `test_a_yearly_period_must_be_shorter_than_a_year` | 検出 |
| V03 | その年だけの期間にも 1 年の決まりを当てる（`HolidayController.php`） | Tests: 936, Assertions: 7125, Failures: 1. | `test_a_yearly_period_must_be_shorter_than_a_year` | 検出 |
| V04 | 終了日が開始日より前でも通す（`HolidayController.php`） | Tests: 936, Assertions: 7110, Failures: 2. | `test_a_refused_edit_reopens_the_same_dialog`、`test_the_dates_and_the_description_are_checked` | 検出 |
| V05 | 開始日を date で検査する（`HolidayController.php`） | Tests: 936, Assertions: 7119, Failures: 1. | `test_the_dates_and_the_description_are_checked` | 検出 |
| V06 | 説明を空でも通す（`HolidayController.php`） | Tests: 936, Assertions: 7123, Failures: 1. | `test_the_dates_and_the_description_are_checked` | 検出 |
| V07 | 変わっていなくても記録する（`HolidayController.php`） | Tests: 936, Assertions: 7120, Failures: 1. | `test_changes_and_deletions_are_recorded` | 検出 |
| V08 | 前の値を Carbon のまま比べる（`HolidayController.php`） | Tests: 936, Assertions: 7120, Failures: 1. | `test_changes_and_deletions_are_recorded` | 検出 |
| V09 | 一覧を開始日の順に並べない（`HolidayController.php`） | Tests: 936, Assertions: 7122, Failures: 1. | `test_the_screen_shows_the_next_send_day_the_last_run_and_the_days_off` | 検出 |
| V10 | 次に送る日に今日を出す（`holidays.blade.php`） | Tests: 936, Assertions: 7119, Failures: 2. | `test_a_day_off_can_be_added_from_the_rendered_form`、`test_the_screen_shows_the_next_send_day_the_last_run_and_the_days_off` | 検出 |
| V11 | 使い始める前の添え書きを逆に出す（`holidays.blade.php`） | Tests: 936, Assertions: 7120, Failures: 1. | `test_the_screen_shows_the_next_send_day_the_last_run_and_the_days_off` | 検出 |
| V12 | 前回の人数に件数を出す（`holidays.blade.php`） | Tests: 936, Assertions: 7125, Failures: 1. | `test_the_screen_shows_the_next_send_day_the_last_run_and_the_days_off` | 検出 |
| V13 | 毎年の期間に年を出す（`ApprovalHoliday.php`） | Tests: 936, Assertions: 7122, Failures: 1. | `test_the_screen_shows_the_next_send_day_the_last_run_and_the_days_off` | 検出 |
| V14 | ⑫ に送れないときの帯を出さない（`holidays.blade.php`） | Tests: 936, Assertions: 7122, Failures: 1. | `test_a_failure_shows_the_banner_on_the_admin_pages` | 検出 |
| V15 | 決裁のサイドバー（PC）から催促の設定を消す（`sidebar_approval.blade.php`） | Tests: 936, Assertions: 7104, Failures: 1. | `test_the_reminder_settings_link_is_offered_to_admins_before_launch` | 検出 |

- [ ] **Step 3: 表と違ったものを調べ、検出できなかった変異にテストを足す**

⚠ **等価**（緑が正しい）と書いたものは、なぜ等価かを 1 行で確かめる（読み違えて「守られていない」としない）。等価でないのに緑のものは、その Task のテストに 1 本足し、足したテストが変異で赤・元に戻して緑になることを確かめてからコミットする。

- [ ] **Step 4: 結果をこの計画に追記してコミット**

検出／当初検出漏れ→追加で検出／等価を区別して、この計画の末尾に「Task 6 の実測記録」として書き足す。

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add docs/superpowers/plans/2026-10-02-approval-phase3b.md tests/
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
test(approval): 段階3b の変異テストの結果を記録する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

写し（`<scratchpad>/p3b-mutation`）は、この Task が済んだら消してよい（`rm -rf` の前に中身が写しであることを確かめる）。


---

## Task 7: 手元のブラウザでの確認と、利用者に見せる画面の写真とメールの文面（設計書 §6）

**Files:** なし（測るだけ）

テストが原理的に測れない領域（見た目・スマホの幅・小窓・日付の入力の部品・メールの見え方）を見る。**本番では使い始めるまで催促は届かないので、利用者に見てもらうのはここで撮る写真とメールの文面**。WT の HEAD の写し（scratchpad）＋使い捨ての SQLite ＋ `artisan serve`。ブラウザは Playwright（利用者の決まり）。`<scratchpad>` は Task 6 と同じ。⚠ Playwright がファイルを扱えるのは会話の作業フォルダの下だけ（2a Task 19 の注意）。

- [ ] **Step 1: 写しと使い捨ての環境を作る**

⚠ Bash の呼び出しごとにシェルが新しくなるので、使い捨ての設定は 1 つのファイルにまとめ、以降のコマンドの先頭で `source` する。⚠ WT にも写しにも `.env` を作らない。⚠ `APP_LOCALE=ja`・`APP_FALLBACK_LOCALE=ja` を入れる。⚠ メールは `log`（`storage/logs/laravel.log` に書くだけ・外へ送らない）、キューは `sync`。⚠ 試しのパスワードの値はチャット・報告・写真に出さない。

```bash
SCR=<scratchpad>/p3b-browser && mkdir -p "$SCR" && git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 archive HEAD | tar -x -C "$SCR" && cp -Rc /Users/masanori/site/manage/.claude/worktrees/approval-phase3/vendor "$SCR/vendor"
LOCAL=<scratchpad>/approval-phase3b-local.sh
cat > "$LOCAL" <<SH
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
export APP_LOCALE=ja
export APP_FALLBACK_LOCALE=ja
export APP_URL=http://127.0.0.1:8768
export DB_CONNECTION=sqlite
export DB_DATABASE="<scratchpad>/approval-phase3b.sqlite"
export MAIL_MAILER=log
export QUEUE_CONNECTION=sync
export TRIAL_PASSWORD="$(php -r 'echo bin2hex(random_bytes(6));')"
SH
source "$LOCAL" && touch "$DB_DATABASE" && cd "$SCR" && php artisan migrate --force && ln -s /Users/masanori/site/manage/node_modules node_modules && ./node_modules/.bin/vite build
```

- [ ] **Step 2: 試しのデータを入れて、催促を 1 回流す**

テストの土台（`BuildsApprovalFixtures`）をそのまま使う。時計を 2026-10-01（木）に止めて申請を出し、2026-10-05（月）9:00（日本時間）に止めて `approvals:remind` を流す（本物のコマンドでまとめメールが作られ、⑫ の「前回の催促」が入る）。⚠ ログイン用の使い捨てルートを作らない。

```bash
source <scratchpad>/approval-phase3b-local.sh && cd <scratchpad>/p3b-browser && php artisan tinker --execute='
use App\Enums\ApprovalStepResult as R;
use App\Models\ApprovalHoliday;
use Carbon\Carbon;
$f = new class {
    use Tests\Concerns\BuildsApprovalFixtures { approvalWorld as public; submittedFor as public; approvalAdmin as public; launchApprovals as public; }
    use Tests\Concerns\CreatesMansionSchema { createMansionSchema as public; }
    use Tests\Concerns\CreatesRealEstateSchema { createRealEstateSchema as public; }
};
$f->createMansionSchema(); $f->createRealEstateSchema();
$wf = app(App\Support\Approval\Workflow::class);
$w = $f->approvalWorld();
$admin = $f->approvalAdmin(["name" => "決裁 管理者"]);
App\Models\ApprovalMailDomain::create(["domain" => "mitsuwat.co.jp"]);
$w["head"]->update(["email" => "head@mitsuwat.co.jp"]);
$w["applicant"]->update(["email" => "applicant@mitsuwat.co.jp"]);
$f->launchApprovals();
Carbon::setTestNow(Carbon::parse("2026-10-01 10:00:00", "Asia/Tokyo")->utc());
$f->submittedFor($w, ["subject" => "A&B社の<見積り>\"比較\""]);
for ($i = 1; $i <= 21; $i++) { $f->submittedFor($w, ["subject" => sprintf("備品の購入 %02d", $i)]); }
$ret = $f->submittedFor($w, ["subject" => "倉庫の賃借"]);
$wf->judgeHead($ret, $w["head"], $ret->lock_version, R::Return, "契約期間を書いてください");
ApprovalHoliday::create(["start_date" => "2026-12-29", "end_date" => "2027-01-03", "repeats_yearly" => true, "description" => "年末年始"]);
ApprovalHoliday::create(["start_date" => "2026-08-13", "end_date" => "2026-08-14", "repeats_yearly" => false, "description" => "夏季休暇"]);
Carbon::setTestNow(Carbon::parse("2026-10-05 09:00:00", "Asia/Tokyo")->utc());
Artisan::call("approvals:remind");
echo trim(Artisan::output()), PHP_EOL;
Carbon::setTestNow();
App\Support\Approval\MailDelivery::recordFailed("山田 花子");
DB::table("users")->update(["password" => Hash::make(getenv("TRIAL_PASSWORD")), "must_change_password" => false]);
echo "admin ", $admin->email, PHP_EOL, "head ", $w["head"]->email, PHP_EOL;
'
```

Expected: `2026-10-05 催促を送りました（2 人・23 件）。`（部門長 22 件〈1 件目が `A&B社の<見積り>"比較"`・21・22 件目は「ほか 2 件」〉・申請者 1 件）と、ログイン名。パスワードは `source` したシェルの `$TRIAL_PASSWORD`（値を出さない）。

画面を出す（**バックグラウンドで**。止めるまで動き続ける）:

```bash
source <scratchpad>/approval-phase3b-local.sh && cd <scratchpad>/p3b-browser && php artisan serve --port=8768
```

- [ ] **Step 3: 見ること**（1440px と 375px の両方。ログインは「決裁 管理者」）

| # | 画面 | 見ること |
|---|---|---|
| 1 | ⑫（サイドバーの「決裁の管理」→「催促の設定」） | サイドバーの「申請種類の管理」の下に「催促の設定」があり、開くと選ばれた色になる・パンくず「決裁申請 › 催促の設定」・D2 の黄色の帯・見出しと説明・「次に催促を送る日 {日付}（{曜}） 9 時」（その日の実際の次の平日）・「前回の催促 10/5（月）（2 人・23 件）」・送らない日の表が開始日の順（「2026/08/13〜2026/08/14 その年だけ 夏季休暇」「12/29〜1/3 毎年 年末年始」）・375px で表が横に送れて「← スクロールできます →」が出る |
| 2 | 追加の小窓 | 開始日・終了日が日付の部品（カレンダー）・「毎年繰り返す」のチェック・説明（例: 年末年始）・375px で小窓が画面に収まる。「2026-11-02〜2026-11-02・創立記念日」で保存 → 緑の帯「送らない日を登録しました。」・表に「2026/11/02」が 1 つで出る |
| 3 | 断られたとき | 開始日 2027-01-01・終了日 2028-01-01・毎年にチェック → 赤の帯と小窓の中に「毎年繰り返す期間は 1 年より短くしてください。」・小窓が打った中身のまま開き直る。開始日 2026-12-29・終了日 2026-01-03（年末年始を同じ年で打つ）→「終了日は開始日より前にできません（年をまたぐ期間は、終了日を次の年の日付にしてください。例: 2026/12/29〜2027/1/3）。」 |
| 4 | 編集の小窓 | 「年末年始」の編集で今の値が入っている（日付・チェック・説明）・説明を「年末年始の休業」にして保存 →「送らない日を更新しました。」 |
| 5 | 削除 | 確かめのダイアログ「この送らない日を削除しますか。」→ OK で「送らない日を削除しました。」 |
| 6 | 次に送る日の動き | 次に送る日の週の平日を 1 日だけ登録すると、「次に催促を送る日」がその次の送る日に動く（確かめたら消す） |
| 7 | 入口 | 決裁のホームのリンクの行に「催促の設定」・基幹の画面（経営ダッシュボード）のサイドバーの「決裁の管理」にも「催促の設定」 |
| 8 | 使い始める前（tinker で `launched_at` を空に戻す） | ⑫ が開き「決裁を使い始める前なので、まだ送りません。」が添えられる・準備中のホームのリンクの行にも「催促の設定」。確かめたら `launched_at` を入れ直す |
| 9 | 全画面 | `main.scrollWidth === main.clientWidth` を 1800 / 1200 / 375px で（Bug #29）・コンソールのエラーと警告が 0 件 |
| 10 | まとめメールの文面（記録から読む。下のコマンド） | 差出人の名前「ミツワ都市開発 決裁システム」・件名「【決裁】対応待ちの申請が 22 件あります」（部門長あて）・宛名・前置き・1〜20 の 1 件ごとに「件名（申請者・申請部門）」「部門長・承認・差戻し ／ 4 日待ち」とリンク・`A&B社の<見積り>"比較"` が打ったとおり（`&amp;` にならない）・「ほか 2 件はホームで確かめてください。」・ホームへのリンク・注意書き 2 行・金額や本文が無い。申請者あては「申請者・差戻しの対応 ／ 4 日待ち」の 1 件 |
| 11 | メールのリンク | 文面のリンク（`http://127.0.0.1:8768/index.php/approvals/requests/…`）をブラウザで開くと、その申請の詳細が開く（`/index.php` 入りの形で開ける） |

メールの文面を読むコマンド（記録の件名と本文は MIME の形で書かれているので、文字に戻す）:

```bash
cd <scratchpad>/p3b-browser && php -r '
$entries = preg_split("/^\[\d{4}-\d\d-\d\d [\d:]+\] \w+\.\w+: /m", file_get_contents("storage/logs/laravel.log"), -1, PREG_SPLIT_NO_EMPTY);
foreach ($entries as $e) {
    [$head, $body] = array_pad(preg_split("/\r?\n\r?\n/", $e, 2), 2, "");
    $h = iconv_mime_decode_headers($head, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, "UTF-8");
    if (! isset($h["Subject"]) || ! str_contains($h["Subject"], "対応待ちの申請")) continue;
    echo "From: ", $h["From"], PHP_EOL, "To: ", $h["To"], PHP_EOL, "Subject: ", $h["Subject"], PHP_EOL, PHP_EOL, quoted_printable_decode($body), PHP_EOL, "----", PHP_EOL;
}'
```

- [ ] **Step 4: 利用者に見せる写真とメールの文面**

375px と 1440px で、⑫（次に送る日・前回の催促・表・D2 の帯）・追加の小窓・断られたときの小窓・使い始める前の添え書きを撮り、Step 3 の 10 のメールの文面（部門長あてと申請者あての 2 通）と一緒に scratchpad に保存して利用者に送る（`SendUserFile`）。写真には試しのデータしか写らないことを確かめる。

- [ ] **Step 5: コンパイル済みビューを lint する**

⚠ `view:cache` の成功表示だけでは足りない（Bug #21 / #26 / #30）。

```bash
source <scratchpad>/approval-phase3b-local.sh && cd <scratchpad>/p3b-browser && php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done; php artisan view:clear
```

Expected: INVALID 0 件。

- [ ] **Step 6: 片付けて結果を記録する**

`artisan serve` を止めたことを確かめてから、写しと使い捨てのファイルを消す（`rm -rf` の前に、消すのが scratchpad の写しであることを `pwd` と `ls` で確かめる）。WT の `git status --porcelain` が空であることも確かめる。見たことと見つけた不具合を、この計画の末尾に「Task 7 の実測記録」として書き足してコミットする（不具合は直してから。直すときは Task 1〜5 のテストに再現を足し、「テストを先に入れると落ち、直しを入れると緑」を確かめる）。


---

## Task 8: ドキュメント

**Files:**
- Modify: `docs/superpowers/specs/2026-09-30-approval-phase3-design.md`（計画で決めた細部の書き戻し）
- Modify: `CLAUDE.md`（Completed modules の決裁の行）・`docs/ARCHITECTURE.md`（ルートの本数・表・定期実行）・`routes/web.php`（`require …approval.php` の真上の見出しの本数）・`docs/BACKLOG.md`（段階3 の節）・`docs/運用_バックアップとメール.md`（朝の催促の予定と見方）

⚠ 要件定義書は変えない（15.5 は「詳しい列は実装計画で決める」。列はこの計画 §0.2 が記録）。

- [ ] **Step 1: 設計書に書き戻す**

- 冒頭の「実装計画:」の行を置き換える: `実装計画: 3a は @docs/superpowers/plans/2026-09-30-approval-phase3a.md（この設計書から変えた細部は §0.10、受け入れた隙間は §0.11）。3b は @docs/superpowers/plans/2026-10-02-approval-phase3b.md（同じく §0.10・§0.11）`
- §5.1 の「3a の計画で決めた名前」の行の後に足す: `- 3b の計画で決めた名前（計画 §1）: ApprovalRemindCommand（approvals:remind）・ApprovalReminderMail・ReminderCalendar（送る日の判定と次に送る日）・PendingWork::everyone()（全員分の対応待ち）・ApprovalHoliday・ApprovalReminderRun・HolidayController（⑫）`
- §5.3 の箇条書きの最後に足す: `- 3b の計画で決めた細部（計画 §0.2）: approval_holidays は start_date・end_date（DATE）・repeats_yearly・description（VARCHAR(50)・必須）。approval_reminder_runs は sent_on（DATE・一意）・recipient_count・item_count（のべ）・created_at。date キャストの列は Eloquent で書き、whereDate() で読む`
- §5.8 の最後に足す: `- 3b の計画で決めた細部（計画 §0.5）: 「今日の分をもう送ったか」は先に読まず、今日の行を入れられるかで決める。送る相手がいない日も 0 人・0 件で記録する。1 件の「必要な対応」はホームと同じ「{役割}・{対応}」、件名・申請者・申請部門もホームと同じ今の値。リンクの元は設定 approval.mail_link_root（既定は APP_URL に /index.php を足したもの。本番の APP_URL は https://www.mitsuwat.co.jp/system/manage＝2026-10-02 に読み取り）に route(…, false) の道をつなぐ。予定の画面の出力は storage/logs/approval-reminder.log に足す`
- §5.9 の最後に足す: `- 3b の計画で決めた細部（計画 §0.6）: 毎年繰り返す期間は 1 年より短く（終了日が開始日の 1 年後〈2/29 は翌年の 2/28〉と同じか後なら断る）・説明は必須で 50 文字まで（利用者の決定 2026-10-02）。入口は「決裁の管理」のサイドバー 4 か所とホームのリンクの行`
- §5.10 の最後に足す: `- 3b の計画で決めた細部（計画 §0.3）: azuyalabs/yasumi ^2.12（2.12.0・php >=8.2）。2025〜2027 年の日本の祝日が内閣府の一覧と一致することを確かめた。isHoliday() は渡した日時のその時刻帯の年月日で比べるので、年ごとの祝日の表を日本の暦の日付の文字で引く`
- §9 の 3b の行の最後に、どこで決めたかを足す（項目の頭の言葉 → 足す文）:

| §9 の項目 | 足す文 |
|---|---|
| 全員分の対応待ちをまとめる問い合わせ… | `→ 3b の計画 §0.4 で決めた（PendingWork::everyone()）` |
| 催促のコマンドの名前・予定の書き方… | `→ 3b の計画 §0.2・§0.5 で決めた` |
| リンクの設定の名前と既定値… | `→ 3b の計画 §0.5 で決めた（approval.mail_link_root）` |
| Yasumi の版… | `→ 3b の計画 §0.3 で決めた（^2.12。内閣府の一覧と突き合わせ）` |
| ⑫ の入力の決まりの細部… | `→ 3b の計画 §0.6 で決めた（利用者の決定）` |
| 走査テストへの登録… | 今の文の最後に `／3b の分は計画 §0.7 と Task 6` を足す |

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add docs/superpowers/specs/2026-09-30-approval-phase3-design.md
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
docs(approval): 設計書に 3b の計画で決めた細部を書き戻す

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

- [ ] **Step 2: 画面・ルート・表・運用を書き換える**

`CLAUDE.md` の Completed modules の表の決裁の行を置き換える:

```markdown
| 決裁申請 段階1・2a・2b・3a・3b | `/approvals/*` | `Approval\*Controller`（門番 3 本・CSV 一括登録・ログイン案内・申請の回覧と進行中の申請の管理・お知らせ〈ベル・⑥〉と通知メール・毎朝の催促〈`approvals:remind`〉と催促の設定〈⑫〉。状態を変えるのは `App\Support\Approval\Workflow` だけ・知らせを出すのは `App\Support\Approval\Notifier` だけ）|
```

`docs/ARCHITECTURE.md`

- `routes/approval.php` の行: `│   ├── approval.php                 # 決裁申請 段階1・2a・2b・3a・3b (50 ルート。管理系は approval.admin、申請を回す画面とお知らせは approval.launched、進行中の申請の管理は両方)`
- `routes/console.php` の行: `│   └── console.php                  # 定期実行の予定 (schedule:run が読む。夜間バックアップ 3:00・決裁の催促 9:00・キュー処理 5 分おき)`
- 表の一覧の `notifications` の行の後に足す:
  - `| `approval_holidays` | 決裁: 催促を送らない日（期間・毎年繰り返すか・説明。毎年は月と日だけで比べる。土日と祝日は登録しない）|`
  - `| `approval_reminder_runs` | 決裁: 催促を送った日（1 日 1 行・`sent_on` が一意＝同じ日に 2 回送らない。書くのは `approvals:remind` だけ）|`
- 決裁（段階3）の行の最後に足す: `。毎朝の催促は `approvals:remind`（9:00〜9:04 の 1 回・キュー処理より前）が、使い始める前・送らない日（`ReminderCalendar`。祝日は部品 Yasumi）・今日の分を送ったあとは何もせず、今日の行とまとめメールを 1 つのトランザクションで積む。載せる申請は `PendingWork::everyone()`（全員について `for()` と同じ）。催促の設定（⑫ `approvals.admin.holidays.*`）は `approval.admin` だけ（使い始める前から開く）`

`routes/web.php` の `require __DIR__ . '/approval.php';` の真上の見出しの「決裁申請（46ルート）」を「決裁申請（50ルート）」にする。

`docs/BACKLOG.md` の段階3 の節

- 見出しを `## 🚧 決裁申請 段階3（通知）— 3a 本番反映済み・3b 実装済み・使い始める前` にする
- 3a の「#### 本番反映（2026-10-02 実施）」の表と 2 つの ⚠ の行の後（節の最後の `---` の前）に足す:

```markdown
### 3b（毎朝の催促・祝日の判定・催促の設定 ⑫）

実装計画: @docs/superpowers/plans/2026-10-02-approval-phase3b.md（Task 0〜9）。worktree `.claude/worktrees/approval-phase3`（3a と同じ）。

- 表: `approval_holidays`・`approval_reminder_runs`（新）。本番反映は **DB が先・`./deploy.sh` が後**（新しいコードが 2 表を読む）。新しい部品 `azuyalabs/yasumi`（main repo で `composer install --no-dev` してから `./deploy.sh` が `vendor` ごと送る）
- 毎朝 9:00〜9:04 の 1 回で `approvals:remind`。使い始める前は何も送らない（画面の出力は `storage/logs/approval-reminder.log` に毎朝 1 行）
- 利用者の決定（2026-10-02）: 本番の `APP_URL` を読み取る（`/index.php` が無い → リンクの元は `APP_URL` に `/index.php` を足したもの）・毎年繰り返す送らない日は 1 年より短く・説明は必須・年をまたぐ期間を同じ年で打ったときは直し方を添えて断る・2/29 は毎年の期間にも使える。3b は 2 つの会話が並行して作り始め、この計画の会話が続けた（もう一方の枝 `approval-phase3b`・`approval-phase3b-proto` は残してある）
- 計画で決めた細部（計画 §0.10）: まとめメールの「必要な対応」と件名はホームの対応待ちと同じ・「今日の分を送ったか」は今日の行を入れられるかで決める・送る相手がいない日も 0 人・0 件で記録
- 受け入れた隙間（計画 §0.11）: 無効の人・メールの無い人の対応待ちは催促が誰にも届かない（⑩ で見る）・祝日の法改正は部品の更新が要る ほか
```

`docs/運用_バックアップとメール.md`

- 「1. この仕組みでできること」の表の「5 分おき」の行の後に足す: `| 平日の朝 9:00 ごろ（日本時間） | 決裁の対応を 3 日以上待っている人に、催促のまとめメールを 1 人 1 通送る（決裁を使い始めてから。土日・祝日・決裁の管理者が「催促の設定」で登録した送らない日は送らない） |`
- 「4. ふだんの見方」の最後に足す: `- **朝の催促は `storage/logs/approval-reminder.log` に毎朝 1 行残ります**（「2026-10-05 催促を送りました（3 人・5 件）。」「送らない日なので送りません（祝日（スポーツの日））。」「使い始める前なので送りません。」など。日付は日本時間）。決裁の画面の「催促の設定」でも「前回の催促」と「次に催促を送る日」が見られます。9:00〜9:04 に定期実行が動かなかった日は送りません（次の送る日の朝に載ります）。年末年始・夏季休暇などの会社の休みは「催促の設定」で登録してください（祝日は自動で判定します。法律で祝日が変わったときは部品の更新が要ります）`

- [ ] **Step 3: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add CLAUDE.md docs/ARCHITECTURE.md routes/web.php docs/BACKLOG.md docs/運用_バックアップとメール.md
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
docs: 決裁 段階3b の催促・ルート・表・運用をドキュメントに反映する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

- [ ] **Step 4: `13.x` と衝突しないことを確かめる**（`docs/BACKLOG.md`・`CLAUDE.md` は別の会話も書き足す。設計書 §7 の 6）

```bash
git -C /Users/masanori/site/manage merge-tree --write-tree approval-phase3 13.x >/dev/null && echo "衝突なし" || echo "衝突あり"
```

Expected: `衝突なし`。「衝突あり」なら、どのファイルかを `git -C /Users/masanori/site/manage merge-tree --write-tree --name-only approval-phase3 13.x` で見て、本番反映（Task 9 の Step 3）の前に利用者に伝える。


---

## Task 9: 本番反映（利用者の了承を取ってから・親の会話が行う）

> ⚠ **サブエージェントに任せない。** 本番への `ssh`・`scp`・`./deploy.sh`、main repo での `composer install` は、それぞれ利用者の了承を取ってから行う。本番のファイルの削除は利用者が行う（実行する 1 行を渡す）。`.env` は読まない。
> ⚠ 本番のシェルは csh なので、`/bin/sh` の heredoc を `ssh` に流す（段階1・2・3a と同じ作法）。PHP は `/usr/local/php/8.3/bin/php` を明示する（既定の `php` は 7.4）。

- [ ] **Step 1: 了承を求める（選択式）**

伝えること: ①**DB が先・`./deploy.sh` が後**（新しいコードが 2 つの表を読むので、逆だと催促の設定の画面と朝の催促が止まる）②本番に表が 2 つ（送らない日・催促を送った日）増える（データは変えない）③新しい部品（祝日の判定 Yasumi）を手元の main repo に入れて、`./deploy.sh` が本番へ送る（本番で `composer install` はしない）④**使い始める前なので、催促は 1 通も送られない**（毎朝 9 時に「使い始める前なので送りません。」とログのファイルに 1 行残るだけ）⑤決裁の管理者には「催促の設定」の画面とサイドバーの入口が見える（使い始める前から送らない日を登録できる）⑥戻すときは、`13.x` の前のコミットで `./deploy.sh`（足した表は残しても害は無い）。

- [ ] **Step 2: 反映前に本番を読み取る**（読み取りだけ）

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage || exit 1
/usr/local/php/8.3/bin/php artisan route:list --name=approvals. --json | /usr/local/php/8.3/bin/php -r '$r = json_decode(stream_get_contents(STDIN), true); echo "approvals=", count($r), PHP_EOL;'
/usr/local/php/8.3/bin/php artisan schedule:list
/usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
$schema = $db->getSchemaBuilder();
echo "approval_holidays=", $schema->hasTable("approval_holidays") ? "ある" : "ない", " approval_reminder_runs=", $schema->hasTable("approval_reminder_runs") ? "ある" : "ない", PHP_EOL;
echo "launched_at=", var_export(App\Models\ApprovalSetting::current()->launched_at, true), PHP_EOL;
echo "queue=", config("queue.default"), " queue_db_connection=", var_export(config("queue.connections.database.connection"), true), PHP_EOL;
echo "app.url=", config("app.url"), PHP_EOL;
echo "jobs_waiting=", $db->table("jobs")->count(), " failed_jobs_rows=", $db->table("failed_jobs")->count(), " notifications=", $db->table("notifications")->count(), PHP_EOL;
'
ls -d vendor/azuyalabs 2>/dev/null || echo "vendor/azuyalabs=ない"
ls -la storage/logs/laravel.log storage/logs/approval-reminder.log 2>&1
SH
```

Expected: `approvals=46`（3a のまま）／ `schedule:list` に `ops:backup`（0-4 3）と `queue:work`（毎分）の 2 つだけ ／ `approval_holidays=ない`・`approval_reminder_runs=ない` ／ `launched_at=NULL` ／ `queue=database`・`queue_db_connection=NULL`（**今日の行とまとめメールが一緒に巻き戻る前提**。§0.5。違えば止まる）／ `app.url=https://www.mitsuwat.co.jp/system/manage`（計画を書いたときと同じ。違えばリンクの元が変わるので止まる）／ `vendor/azuyalabs=ない` ／ `approval-reminder.log` は無い。**1 つでも違えば止まり、利用者に伝える**。

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
scp /Users/masanori/site/manage/database/sql/2026-10-02-approval-phase3b.sql mitsuwa-ud@www3586.sakura.ne.jp:apps/manage/database/sql/
```

(b) 流す前に、文の数と頭を見る（まだ何も変えない）:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$sql = preg_replace("/^--.*$/m", "", file_get_contents(base_path("database/sql/2026-10-02-approval-phase3b.sql")));
$statements = array_values(array_filter(array_map("trim", explode(";", $sql))));
echo "statements=", count($statements), PHP_EOL;
foreach ($statements as $i => $s) { echo $i + 1, ": ", strtok($s, "\n"), PHP_EOL; }
'
SH
```

Expected: `statements=2`（1 が `CREATE TABLE \`approval_holidays\` (`、2 が `CREATE TABLE \`approval_reminder_runs\` (`）。

(c) 1 文ずつ流す（**すでに流した形跡があれば 1 文も流さずに止まる**）:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
$schema = $db->getSchemaBuilder();
$traces = array_keys(array_filter([
    "approval_holidays"      => $schema->hasTable("approval_holidays"),
    "approval_reminder_runs" => $schema->hasTable("approval_reminder_runs"),
]));
if ($traces !== []) {
    echo "STOP: すでに流した形跡がある: ", implode(", ", $traces), PHP_EOL;
} else {
    $sql = preg_replace("/^--.*$/m", "", file_get_contents(base_path("database/sql/2026-10-02-approval-phase3b.sql")));
    foreach (array_values(array_filter(array_map("trim", explode(";", $sql)))) as $i => $statement) {
        $db->statement($statement);
        echo "OK ", $i + 1, PHP_EOL;
    }
}
'
SH
```

Expected: `OK 1`・`OK 2`。⚠ **途中で止まったら**（MySQL の DDL は 1 文ごとに確定する）、出た `OK` の番号と例外の文言を利用者に伝えて止まる。流し直さない。

(d) 流した後を読み取る:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
foreach (["approval_holidays", "approval_reminder_runs"] as $t) {
    echo $db->selectOne("SHOW CREATE TABLE `" . $t . "`")->{"Create Table"}, PHP_EOL, PHP_EOL;
    echo $t, "=", $db->table($t)->count(), PHP_EOL;
}
'
SH
```

Expected: 2 表の定義がこの計画の末尾「計画を書く段階の実測」の「使い捨ての MySQL の `SHOW CREATE TABLE`」と同じ（列・型・NULL・既定・コメントが日本語で読める・`UNIQUE KEY uq_approval_reminder_runs_sent_on`・InnoDB・`utf8mb4_unicode_ci`）／ どちらも 0 行。

- [ ] **Step 5: 祝日の部品と新しいクラスを読み込めるようにする**（手元の main repo で）

```bash
cd /Users/masanori/site/manage && test ! -e vendor/bin/phpunit && composer install --no-dev --no-interaction 2>&1 | grep -E 'Package operations|azuyalabs|Nothing' && test ! -e vendor/bin/phpunit && composer dump-autoload --no-dev --optimize && git status --short && ls -d vendor/azuyalabs/yasumi
```

Expected: `Package operations: 1 install, 0 updates, 0 removals`・`Installing azuyalabs/yasumi (2.12.0)`・`vendor/bin/phpunit` が無いまま（dev の部品が混ざっていない）・`git status` に何も出ない（`composer.lock` は WT で入れたものと同じ）・`vendor/azuyalabs/yasumi` がある。⚠ **main repo の cwd で行う**（worktree から行うと autoloader に worktree のパスが焼き込まれる）。ほかの部品が入れ替わったら（`updates` か `removals` が 0 でない）止まって利用者に伝える。

- [ ] **Step 6: 反映**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

Expected: exit 0・6 段すべて成功（`vendor/azuyalabs` が送られる。CSS が変われば旧バンドルの掃除が出る）。

- [ ] **Step 7: 本番で確かめる**（すべて読み取り。催促のコマンドは使い始める前なので何も送らず、何も書かない）

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage || exit 1
n=0; bad=0
for f in storage/framework/views/*.php; do n=$((n+1)); /usr/local/php/8.3/bin/php -l "$f" >/dev/null 2>&1 || { bad=$((bad+1)); echo "INVALID: $f"; }; done
echo "views=$n invalid=$bad"
/usr/local/php/8.3/bin/php artisan route:list --name=approvals. --json | /usr/local/php/8.3/bin/php -r '$r = json_decode(stream_get_contents(STDIN), true); echo "approvals=", count($r), PHP_EOL;'
/usr/local/php/8.3/bin/php artisan schedule:list
/usr/local/php/8.3/bin/php artisan approvals:remind
/usr/local/php/8.3/bin/php artisan tinker --execute='
echo "launched_at=", var_export(App\Models\ApprovalSetting::current()->launched_at, true), PHP_EOL;
foreach (["App\\Support\\Approval\\ReminderCalendar", "App\\Console\\Commands\\ApprovalRemindCommand", "App\\Mail\\ApprovalReminderMail", "App\\Models\\ApprovalHoliday", "App\\Models\\ApprovalReminderRun", "App\\Http\\Controllers\\Approval\\HolidayController", "Yasumi\\Yasumi"] as $c) {
    echo $c, "=", class_exists($c) ? "ok" : "NG", PHP_EOL;
}
$calendar = new App\Support\Approval\ReminderCalendar();
echo "2026-10-12=", var_export($calendar->reasonNotToSend(Illuminate\Support\Carbon::parse("2026-10-12")), true), " next=", App\Support\Approval\ReminderCalendar::label($calendar->nextSendDay()), PHP_EOL;
echo "mail_link_root=", config("approval.mail_link_root"), PHP_EOL;
echo "runs=", app("db")->table("approval_reminder_runs")->count(), " jobs_waiting=", app("db")->table("jobs")->count(), PHP_EOL;
'
ls -la storage/logs/laravel.log
SH
curl -s https://www.mitsuwat.co.jp/system/manage/index.php/login | grep -c "社員番号またはメールアドレス"
```

Expected: `invalid=0`・**`approvals=50`**（`--json` で数える）・`schedule:list` に `approvals:remind`（`0-4 9 * * *`・キュー処理より上）が足されて 3 つ・`approvals:remind` の出力が「{今日の日付} 使い始める前なので送りません。」・`launched_at=NULL`・7 つとも `ok`・`2026-10-12='祝日（スポーツの日）'`・`next=` が次の平日・`mail_link_root=https://www.mitsuwat.co.jp/system/manage/index.php`・`runs=0`・`jobs_waiting` が Step 2 と同じ・`laravel.log` の日時が反映の前から変わっていない（反映のあとのエラー 0 件）・ログイン画面の文字が 1 以上。

ログインした画面の確認は、利用者の了承を取ってから、利用者の Chrome（ログイン済み。URL に `index.php/` が要る。**フォームは送らない**）で行う:

| # | 見ること |
|---|---|
| 1 | 決裁の管理者のサイドバー（基幹の画面の「決裁の管理」と、決裁の画面）に「催促の設定」がある |
| 2 | `/approvals/admin/holidays` が開き、「次に催促を送る日 {次の平日}（{曜}） 9 時」「決裁を使い始める前なので、まだ送りません。」「前回の催促 まだありません」・送らない日の表が空（「送らない日は登録されていません（土日と祝日だけ送りません）。」）・黄色の帯が無い |
| 3 | 決裁のホーム（準備中）のリンクの行に「催促の設定」 |
| 4 | 基幹の画面と ⑫ の `main` のはみ出し 0・コンソールのエラー 0 件 |

- [ ] **Step 8: 記録する**

`docs/BACKLOG.md` の段階3 の節の見出しを「🚧 決裁申請 段階3（通知）— 3a・3b 本番反映済み・使い始める前」にし、3b の節に反映日・`13.x` のコミット・Step 2〜7 で見たことの表を書き足してコミットする（`docs:` 1 本）。翌朝 9:05 以降に `storage/logs/approval-reminder.log` に「使い始める前なので送りません。」の 1 行が増えていることを、次の会話で読み取りで確かめる（定期実行が催促を呼んでいることの確かめ。了承を取ってから）。

⚠ `origin/13.x` への push は利用者の明示の指示があったときだけ。⚠ worktree `approval-phase3` とブランチの片付けは利用者に聞いてから（dev の vendor の置き場なので、消すなら次の worktree へ `cp -Rc` してから）。



---

## 計画を書く段階の実測（2026-10-02）

### 本番の読み取り（利用者の了承のあと・読むだけ）

```text
app.url=https://www.mitsuwat.co.jp/system/manage
route_show=https://www.mitsuwat.co.jp/system/manage/approvals/requests/123
route_home=https://www.mitsuwat.co.jp/system/manage/approvals
php=8.3.32
```

コマンドの中の `route()` は `/index.php` を含まない（本番は mod_rewrite が無く、この URL では開けない）→ リンクの元は `APP_URL` に `/index.php` を足したもの（§0.5）。

### Yasumi 2.12.0 と内閣府の祝日の一覧

`https://www8.cao.go.jp/chosei/shukujitsu/syukujitsu.csv`（Shift_JIS を UTF-8 に直した）と、Yasumi の `Japan` の各年の祝日を日付で突き合わせた（どちらかにしか無い日は 0）:

```text
== 2025: yasumi=19 cao=19
== 2026: yasumi=18 cao=18
== 2027: yasumi=17 cao=17
2026-01-01 00:00:00 UTC => isHoliday=true
2026-01-01 23:30:00 UTC => isHoliday=true
2025-12-31 15:00:00 UTC => isHoliday=false   ← 日本の 2026-01-01 0:00 だが、UTC の年月日（12/31）で比べる
2026-01-01 00:00:00 Asia/Tokyo => isHoliday=true
2026-09-22 00:00:00 UTC => isHoliday=true   ← 国民の休日
2026-05-06 00:00:00 UTC => isHoliday=true   ← 振替休日
```

2026 年の Yasumi の名前（`ja_JP`）: 元日・成人の日・建国記念の日・天皇誕生日・春分の日・昭和の日・憲法記念日・みどりの日・こどもの日・振替休日 (憲法記念日)・海の日・山の日・敬老の日・国民の休日・秋分の日・スポーツの日・文化の日・勤労感謝の日。別の年の日を渡すと `isHoliday()` は false（年ごとに作る）。

### 使い捨ての MySQL 8.4.11 の `SHOW CREATE TABLE`

SQL ファイルを `mysql --default-character-set=utf8mb4` で流した表と、3b のテストを MySQL で流して migration で作った表が、2 表とも完全に一致した（`AUTO_INCREMENT=` の値を除いて `diff` が空）:

```sql
CREATE TABLE `approval_holidays` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `start_date` date NOT NULL COMMENT '開始日',
  `end_date` date NOT NULL COMMENT '終了日（開始日と同じか後）',
  `repeats_yearly` tinyint(1) NOT NULL DEFAULT '0' COMMENT '毎年繰り返すか（1 なら月と日だけで比べる）',
  `description` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '説明（例: 年末年始）',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
CREATE TABLE `approval_reminder_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sent_on` date NOT NULL COMMENT '催促を送った日（日本の日付）',
  `recipient_count` int unsigned NOT NULL COMMENT '催促のメールを送った人数',
  `item_count` int unsigned NOT NULL COMMENT '催促した申請の件数（のべ。1 通に載せきれなかった分も数える）',
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_reminder_runs_sent_on` (`sent_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

3b のテスト 6 本（`Phase3bTablesTest`・`ApprovalHolidayTest`・`ReminderCalendarTest`・`PendingWorkEveryoneTest`・`ApprovalRemindCommandTest`・`HolidaySettingsTest`）は MySQL でも `OK (38 tests, 193 assertions)`（変異 C06 の場面を足す前）。MySQL で流して見つけて直したテストの書き方が 2 つ（§0.12）: 巻き戻りのテストの止め方（SQLite の TRIGGER をやめて、積む直前の合図で止める）・設定の変更の記録の比べ方（MySQL の JSON の列は項目の並びを入れ替える）。

### 試作で見つけて直したこと（計画の形に入っている）

- `PendingWork::for()` の同じ秒の並びが DB の索引の使い方で変わる → `orderBy('id')`（§0.4）
- `URL::forceRootUrl()` で `https` が `http` に化ける → 設定の元に `route(…, false)` の道をつなぐ（§0.5）
- テストで日本時間の Carbon を `travelTo()` に渡すと、保存した日時を 9 時間ずらして読む → UTC にして渡す（§0.12）
- 変異 C06（「次に催促を送る日」の今日を UTC で作る）に、はじめは時刻の読み方の走査テストしか気づかなかった。調べると、振る舞いに違いが出るのは「日本時間の 9:00 より前に、今日の行がもうある」ときだけ（それ以外は 9:05 の締めと「次の送る日」の計算が偶然同じ答えを出す）→ その場面を `ReminderCalendarTest` に足した（2026-10-06 08:30 に 10/6 の行があれば 10/7）
- **別の会話（同じ 3b を並行して作り始め、利用者の決定で止まった）の測定から取り入れたこと**: 全員分の対応待ちを番が来た順に並べ替えない変異（P08）を、はじめの突き合わせは見逃していた（申請がみな同じ時刻に番が来ていて、並べ替えても並びが変わらなかった）→ `PendingWorkEveryoneTest` に「先に自分の申請が差し戻され（1 日目）、あとから審査の番が来る（2 日目）人」を足した。年をまたぐ期間の断りの文（直し方を添える）も取り入れた（利用者の決定）
- 社長は申請できない（Workflow が断る）→「社長が申請者の申請」は、社長の段階まで進めてから社長の指定をその申請者に替えて作る（`PendingWorkEveryoneTest`）
