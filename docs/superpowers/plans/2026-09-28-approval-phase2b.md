# 決裁申請 段階2（2b: 出し直しの変更点と履歴・進行中の申請の管理・基幹のメニューの件数）実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 段階2 の残り（2b）— 出し直した申請の「前回からの変更点」と提出の回ごとの履歴、決裁の管理者の「進行中の申請の管理（画面⑩）」（部門長の確認の付け替え・押し間違いの取り消し・代理の取り下げ）、基幹のメニューとダッシュボードの「決裁」の対応待ちの件数 — を作り、申請を回す画面を使い始める日まで誰にも見せないまま本番へ出す。

**Architecture:** 2a の骨組みをそのまま使う。状態を変えるのは `App\Support\Approval\Workflow` だけ（付け替え・取り消し・代理の取り下げの 3 つを足す）、できるかどうかは `RequestPermissions`、見られる範囲は `RequestVisibility`（`ApprovalRequest::scopeVisibleTo`）、申請者以外に見せる中身は `RequestContent`（D26）の 1 か所ずつ。変更点は提出の控え（`approval_revisions.snapshot`）どうしを比べる（新しい部品 `LineDiff` と `RequestSnapshot::changes()`）。表は変えない（2a の表に 2b の列がある）。

**Tech Stack:** Laravel 12 / PHP 8.3（本番 8.3.32・手元 8.3.35）/ MySQL 8（本番）・SQLite（テスト）/ Blade + Alpine.js 3 + Tailwind v4 / PHPUnit 11

**Spec:** 設計書 @docs/superpowers/specs/2026-09-25-approval-phase2-design.md（この計画は §1.3・§5.13〜§5.16・§6・§7 の **2b** の部分）。要件定義書 @docs/決裁申請_要件定義書_v1.md（v1.10）。2a の計画 @docs/superpowers/plans/2026-09-26-approval-phase2a.md（§0.3 同時操作・§0.9 走査テスト・§0.10 テストの土台・§0.11 帯と文言・§0.12 実装中に変えたこと・§0.13 受け入れた隙間は 2b でもそのまま効く）。2a の作業メモ `~/.claude/plans/approval-phase2a-tasks/README.md` の「後回しにした軽微」（m-6・m-7・保存の版・N-1）もこの計画で片付ける。

## Global Constraints

- 表と本番の SQL は変えない（2a の表に 2b の列がある）。composer の依存を足さない（`sebastian/diff` は開発用で本番の vendor に無い。設計書 §5.13）
- 申請を回す画面は `approval_settings.launched_at` が空のあいだ誰にも見せない（D1）。2b の新しいルートも `approval.launched` の内側。⑩ の `approvals.admin.requests.*` は `approval.admin` と `approval.launched` の両方（§5.16）
- 状態を変えるのは `Workflow` だけ。同時操作は `lock_version` を条件にした 1 回の UPDATE で見張る（2a §0.3）。ロックは申請の行 → 段階の行の順（2a §0.12 の 7）
- 記録（`approval_histories`）と控え（`approval_revisions`）は追記のみ。段階の行は消さない（記録の `step_id` は RESTRICT。§5.16）
- 管理者の操作の理由は必須・2,000 文字まで・改行を LF にそろえてから数えて保存する（D22・§5.8・2a の B1）。前後の空白（全角の空白を含む）だけの理由は空とみなす
- 決裁の管理者でも、自分が申請者の申請には付け替え・取り消し・代理の取り下げをしない（D25）
- 申請者以外には最後に提出した中身を見せる（D26）。⑩ の一覧・変更点・履歴・関連する決裁No の候補も同じ
- 保存した日時は `JapanTime::format()` で出す（Bug #61）。金額は「28,500,000円」の形（¥ を付けない）
- `<table>` は横スクロールの親（`scroll-hint`）の中に置き、スマホの幅では 1 件 1 枚のカードにする（要件 14.4・`MobileLayoutTest`）
- 押せないボタンは `<span>` で包んで理由を `aria-describedby` で紐づける（Bug #43）。確定のボタンは `approvals._submit_once` の `approvalSubmitOnce()`（2a §0.12 の 42・47）
- `x-data` の中に矢印関数を書かない（Top trap #4）。ページ送りに `->links()` を使わない（Bug #24）。操作のあとの戻り先は固定のルート（Bug #64）
- 画面の帯は `success` と `error` の 2 つだけ（2a §0.11）
- 対応待ちの件数（§5.15）は 1 リクエストに 1 回だけ数える。使い始める前は基幹のメニューとダッシュボードに何も足さない
- テストは worktree で `APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`。main repo では作業もテストもしない。worktree に `.env` を作らない（`.env*` は読まない・grep しない）
- コミットは Conventional Commits・日本語の件名（72 文字以内・句点なし）・1 コミット 1 関心事・本文の最後に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`。`--no-verify`・`--amend`・`git reset` でのやり直し・`git stash` は使わない。push は利用者が指示したときだけ

## Review Focus

設計書が触れていないが、使う人がいちばん出会いそうな場面（どの Task のテストも見ていなかったもの）。計画を書く段階で 5 つとも試作にテストを足し、コードを 1 か所壊すとそのテストが落ちることを確かめた（変異）。

1. **退職して削除した申請者の申請**を ⑩ から開いて代理で取り下げる → 一覧に名前つきで出て、取り下げられる（Task 5 の `test_a_request_of_an_applicant_who_left_can_be_withdrawn_for_them`。変異: 申請者の関係から `withTrashed()` を外すと落ちる）
2. **取り消しの二度押し・2 つのタブから同じ取り消し** → 1 つだけさかのぼり、2 回目は「すでに処理されています」（Task 5 の `test_sending_the_same_undo_twice_goes_back_only_one_step`。変異: 取り消しに画面の版でなく今の版を渡すと落ちる）
3. **差戻しを取り消したあと、申請者が開いたままの編集画面から保存** → 保存されず、部門長の確認中の中身は変わらない（Task 5 の `test_the_open_edit_form_does_not_save_after_the_return_is_undone`。変異: 差戻しの取り消しで状態を差戻し中のままにすると落ちる）
4. **部門長が交代したあとに部門長の承認を取り消す** → 今の部門長の対応待ちに入り、前の部門長の対応待ちには入らない（前の部門長は記録で見続けられる）（Task 4 の `test_undoing_the_head_approval_after_a_head_change_goes_to_the_new_head`。変異: 取り消しで担当を判断した人に固定すると落ちる）
5. **⑩ の「決裁済み・否決」が 20 件を超える** → 次のページもこのタブのまま（Task 5 の `test_the_decided_tab_pages_by_twenty_and_keeps_the_tab`。変異: ページ送りの `withQueryString()` を外すと落ちる）

---

## 0. 計画を書く段階で決めたこと（設計書 §9 の宿題のうち 2b の分）

### 0.1 作り方（試作を先に作って確かめた）

- この計画のコードは、scratchpad の試作（`13.x` = `071f028c` の写し）で Task ごとにコミットし、**各段で全件のテストが緑**であることと、**テストだけを先に入れると何が落ちるか**を測ってから書き写したもの（2a の計画は構文の確かめだけだった）。各 Step の Expected の本数と落ちるテストの名前は実測（2026-09-28 夜〜29 朝）
- 同じ中身の差分のファイルを `~/.claude/plans/approval-phase2b-tasks/patches/`（`0001`〜`0010`。この計画のコミット 1 つに 1 ファイル）に置いた。担当は計画のコードを打ち直さず、この差分を当ててよい（**テストを先に** `git apply --include='tests/*'`、実装を**あとで** `git apply --exclude='tests/*'`）。当てたら `git diff --stat` が各 Task の「差分の大きさ」と同じことを確かめる。**差分のファイルと計画のコードが食い違ったら、計画を正として止まり、報告する**
- 各 Task のコードの塊のうち、新しいファイルは全文、既存のファイルは差分（`diff` の形。行の頭の `+` が足す行・`-` が消す行）で示す
- 進め方は 2a と同じ（`~/.claude/plans/approval-phase2a-tasks/README.md` の「進め方」）: 実装の担当は 1 度に 1 人（Task ごと。コミットがぶつからないように）・コードの点検は別の担当が WT の HEAD の写しで行い、点検と次の Task の実装は並べてよい・手直しのファイルは親の会話が全文を用意し、担当が写して入れる・Task 11 は担当に任せず親の会話が行う

### 0.2 表と本番の SQL

- **表は変えない**。2b が使う列は 2a の表にある: `approval_steps.assignee_user_id`（付け替え）・`approval_histories.reason`・`meta`（管理者の操作の理由・前後の担当・取り消した記録）・`approval_requests.decision`・`decided_at`・`finished_at`（社長の判断の取り消しで空に戻す）
- 本番の SQL は無い。新しいクラスがあるので、反映の前に main repo の cwd で `composer dump-autoload --no-dev --optimize`（依存は増やさない。Task 11）

### 0.3 前回からの変更点と提出の履歴（§5.13）

- **行ごとの差**は自前の小さな部品 `LineDiff`: 改行を LF にそろえてから行に分け、前後の同じ行を除いてから、残りを LCS（最長共通部分列）で比べる。残りの行数の積が 250,000（`LineDiff::MAX_CELLS`）を超えたら、消えた行を全部 → 増えた行を全部の順に出す（時間とメモリの上限。本文の上限 20,000 文字で 1 文字の行ばかりでも落ちない）
- **比べ方** `RequestSnapshot::changes($before, $after)`: 種類と申請部門は **id で比べて名前を出す**（名前を変えただけは変更にしない）・関連する決裁No は並びを無視・金額は 0 と空を区別・空は「（なし）」・添付は id で比べる（足したもの・外したもの）
- 比べるのは**直前の回の控えと今の回の控え**（どちらも提出した中身なので、差戻し中の直しかけは入らない＝D26）。詳細の中身の下・添付の上に「前回からの変更点（N-1 回目 → N 回目の提出）」。何も変わっていなければ「前回の提出から、中身と添付は変わっていません。」
- **履歴**は詳細の最後（操作の記録の下）に、提出の回ごとの `<details>`（その回の控えの中身・添付・その回の段階の判断）。外した添付も開ける（`RequestContent::mayOpen()` が通す。開いた記録は 2a §0.5 と同じ）。新しいルートは作らない
- 色だけに頼らない: 「＋」「−」の印と、読み上げ用の「増えた行:」「消えた行:」「前:」「後:」を付ける（要件 14.4）

### 0.4 押し間違いの取り消し（§5.14・D3・D21・D24）

- **取り消す操作**（`UndoTarget::find()`）: 今の回の記録を新しい順に見て、`head_skipped`（部門長の省略）・`head_changed`（部門長の交代）・`reassigned`（付け替え）・`undone`（取り消し）と、取り消し済みの記録（`undone` の `meta.undone_history_id`）を飛ばし、最初に当たったものが取り消せる操作（`head_approved`・`head_returned`・`reviewed`・`president_approved`・`president_conditional`・`president_rejected`・`president_returned`・`condition_confirmed`）ならそれ。`submitted`・`resubmitted`・`withdrawn`・`withdrawn_by_admin` に当たったら無し（提出の手前まで。取り下げは取り消せない）。続けて行えば 1 つずつさかのぼる（D24）
- **戻し方**（段階の行は消さない・`arrived_at` は空にしない＝§5.16）:

| 取り消す操作 | 状態 | 段階 | ほか |
|---|---|---|---|
| `head_approved` 部門長の承認 | 審査中 → 部門長確認中 | 部門長 済み → 待ち（判断した人・結果・コメント・日時を空に）・審査 待ち → まだ届いていない | — |
| `head_returned` 部門長の差戻し | 差戻し中 → 部門長確認中 | 部門長 済み → 待ち・審査と社長 打ち切り → まだ届いていない | D3 |
| `reviewed` 審査の意見 | 社長決裁待ち → 審査中 | 審査 済み → 待ち・社長 待ち → まだ届いていない | — |
| `president_returned` 社長の差戻し | 差戻し中 → 社長決裁待ち | 社長 済み → 待ち | D3 |
| `president_approved`・`_conditional`・`_rejected` 社長の判断 | 決裁済み・条件確認待ち・否決 → 社長決裁待ち | 社長 済み → 待ち | `decision`・`decided_at`・`finished_at` を空に。**番号は残す**（D21。次の判断で同じ番号を使う） |
| `condition_confirmed` 条件の確認 | 決裁済み → 条件確認待ち | 動かさない（社長の段階は条可のまま） | `finished_at` を空に |

- 記録 `undone`（`reason`・`step_id`・`meta.undone_history_id`・`meta.undone_action`）。表示は「押し間違いの取り消し（元の操作の名前）」。元の記録は消さない
- **D3**: 申請の行をロックしてから、今の中身（`RequestSnapshot::make()`。申請の行と今の添付）と今の回の控えを `RequestSnapshot::editableFingerprint()`（種類 id・部門 id・件名・金額・実施時期・本文・関連する決裁No・添付の id を決まった並びに詰め直す。名前は入れない）で比べる。控えが見つからなければ「変えた」とみなす（分からないときは取り消さない）。断りの文は `UndoTarget::EDITED_SINCE_RETURN`
- **待ちに戻した段階の担当**: 担当の欄（`assignee_user_id`）が空の部門長の段階は、部門の今の部門長（承認のあとに交代していれば新しい部門長。Review Focus 4）。付け替えた段階は付け替えた人のまま。審査と社長は 2a と同じく今の設定から読む
- 待ち日数は、待ちに戻した段階の元の届いた日から数える（`arrived_at` を空にしない）。回る順番の「〇〇に届きました」は**待ちの段階だけ**に出す（まだ届いていないに戻した段階にも `arrived_at` が残るため）

### 0.5 取り消された判断をした人の見られる範囲（§5.16）

- 取り消すと段階の「判断した人」（`approval_steps.actor_user_id`）を空に戻す（段階は「待ち」に戻るので、判断した人を残せない）。そのままだと、担当を外れた人（部門長が交代した前の部門長・審査部門から外れた審査担当者など）はその申請を見られなくなり、段階3 の通知（4.7）の先で 404 になる
- そこで `RequestVisibility` の「判断した人」の行に、記録（`approval_histories`）の判断（`head_approved`・`head_returned`・`reviewed`・`president_*`）の `actor_user_id` も足す（§5.16 の「消すかどうか」への答え）。段階の条件は残す（2a の場面では両方が同じ人）

### 0.6 部門長の確認の付け替え（§5.14・D2・D22・D23・D25）

- 部門長確認中で、部門長の段階が待ちの申請だけ（社長の段階は付け替えない＝D2）。付け替え先は有効でメールアドレスのある人（`Assignees::rule()`・削除した人は選べない）・申請者本人でない・今の担当でない。候補の一覧で、許可していないドメインの人に「※通知メールが届きません」（D7 と同じ）
- ロックは `headChanged()` と同じ順（申請の行 → 段階の行を主キーで）→ 読み直し → `assignee_user_id` を入れる → **`lock_version` だけ進める**（`status_changed_at` は変えない＝待ち日数は元の届いた日から）。付け替えの前に開いた画面からの判断・取り下げは「すでに処理されています」
- 記録 `reassigned`（`reason`・`step_id`・`meta.from_user_id`・`meta.to_user_id`）。詳細の記録に「新しい担当: 〇〇」（2a の部門長の交代と同じ出し方）
- 付け替えた段階も、部門長を交代すると新しい部門長へ移る（D23。`headChanged()` が担当の欄を空に戻す。2a のまま）
- 付け替えた担当が見られる範囲（`RequestVisibility` の担当の行）は段階の種類を見ていないが、付け替えるのは部門長の段階だけ（D2）なので条件を足さない（§5.16。ほかの段階を付け替えるときに足す）

### 0.7 代理の取り下げ（要件 4.3 のケース 8・4.7）

- 申請者が取り下げられる状態と同じとき（部門長確認中・審査中・社長決裁待ち・差戻し中）。理由は必須（D17 の申請者の取り下げは任意）。記録 `withdrawn_by_admin`（状態の前後・`reason`。`comment` は空）

### 0.8 管理者の操作の場所と ⑩ の一覧

- 3 つの操作は**詳細（③）の「決裁の管理者の操作」**に置く（中身・回る順番・記録を見てから操作する。2a の判断の小窓と同じ作り）。⑩ は見つけるための一覧で、各行から詳細へ。操作のあとは詳細へ戻す（Bug #64）
- ⑩ のタブ: **「進行中」**（部門長確認中・審査中・社長決裁待ち・差戻し中・条件確認待ち。待ち日数の長い順・同じなら先に届いた順・全件を 1 ページ）と **「決裁済み・否決」**（決裁日の新しい順・20 件ずつ。決裁のあとの押し間違いの取り消しの入口）
- 列: 状態・待ち日数・件名・申請者・申請部門・決裁No・いま誰の番か（`CurrentHandler`）・印。**件名と申請部門は最後に提出した控えのもの**（D26）。印は「担当が申請者本人」（D16 で止まっている）と「審査担当者が申請者本人しかいない」（有効な人で数える）
- 自分が申請者の申請では、操作の代わりに「自分が申請者の申請には、付け替え・取り消し・代理の取り下げはできません。」（D25）。差戻しのあと申請者が直し始めていたら、取り消しのボタンは押せず理由を出す（D3・Bug #43）
- サイドバーの「進行中の申請の管理」は、決裁の管理者に・使い始めてから出す（基幹のサイドバーと決裁のみのサイドバーの、展開とドロワー）
- ⑩・履歴・記録に出す名前（申請者・判断した人・担当）は、2a のモデルの関係（`withTrashed()` 付き）から読む。退職して削除した人の名前も出る（Review Focus 1）。添付の `uploaded_by`・控えの `submitted_by`・ダウンロードの記録の `user_id` の名前は 2b でも画面に出さないので、関係は足さない（段階4 の台帳で足す。§5.16）

### 0.9 小窓の開き直し（2a の軽微 m-6 の直しを兼ねる）

- 断られたときに開き直す小窓は、**送り先のルートで決める**（コントローラがセッションのフラッシュ `approval_reopen` に `judge`・`condition`・`withdraw`・`reassign`・`undo`・`admin_withdraw` を入れる。フォームの値では受け取らない）。開き直すのは `old('lock_version')` があるとき（入力の誤り・断り）だけで、先を越されたときは開き直さない（2a のまま）
- これで、状態が進んだあとに古い画面の取り下げが断られても、条件確認の小窓が取り下げのコメントで開かない（m-6）

### 0.10 基幹のメニューとダッシュボードの件数（§5.15）

- `PendingWork::countFor()` は `for()` と同じ条件（自分が判断する番の待ちの段階＋自分の申請の差戻し中・条件確認待ち）を数えるだけ。`for()` と同じ数になることを突き合わせのテストが守る（2b の管理者の操作のあとも。§5.16）
- `ApprovalMenu::pendingCount()` が 1 リクエストに 1 回だけ数え、リクエストの attributes に置く。使い始める前は `null`（何も出さない）
- 出す場所: 基幹のサイドバー（展開の最初のグループ・スマホのドロワー・折りたたみのアイコン）に「決裁」と件数の赤い丸（0 件は丸を出さない。読み上げは「対応待ち N 件」）／決裁のみのサイドバーの「決裁のホーム」に件数／経営層とテナント（部門）のダッシュボードの先頭にカード「決裁の対応待ち N 件」（0 件でも出す。決裁のホームへの入口を兼ねる）
- `ApprovalSetting::launchedForMenu()`: 基幹の全画面で読むので、設定の行が無くても作らない（読むだけ）。同じリクエストで `current()` を読んでいればそれを使う（問い合わせを増やさない。`PropertyListSortTest` の問い合わせの数）

### 0.11 2a の軽微の片付け（2a の作業メモ「後回しにした軽微」）

- **m-7**: 改行と版の読み方を `FormInput`（`unifyNewlines()`・`lockVersion()`）の 1 か所へ寄せる（3 つのコントローラの同じ中身を消す）。2b の管理者の操作の理由も同じ部品で読む
- **保存の版**（2a Task 15 の軽微）: 申請書の保存の `lock_version` も「0 以上の整数の形」だけを受け付ける（`intval` で「0abc」を 0 と読まない。送られてこなければ 0 のまま＝2a の下書きの作り方と同じ）
- **N-1**: 申請種類の一覧の「審査担当者がいません」は有効な人だけ数える（削除した人は SoftDeletes で数えない＝テストで固定）
- **m-6**: §0.9
- **m-2**: 押せない判断の理由の 2 行目（部門長の段階）を「取り下げて出し直すか、決裁の管理者に部門長の確認の付け替えを頼んでください。」にする（付け替えができたので案内する。§5.16。C7 で利用者が決めた「…決裁の管理者に相談してください。」を 2b で直す）

### 0.12 走査テスト（全件分類）に登録するもの

2a §0.9 と同じく、**各 Task が足したものは、その Task の中で登録する**（途中の各段でも全件が緑）。

| 走査テスト | 登録するもの | Task |
|---|---|---|
| `tests/Feature/ClockReadScanTest.php` | `Workflow.php` の `now()` を 7 → **9** 件（付け替えの版の繰り上げ・取り消しで段階を戻したとき） | 4 |
| `tests/Feature/Approval/Phase2/WorkflowTest.php` の `ALLOWED` | `reassignHead`・`undo`・`withdrawByAdmin`（状態ごとにできる操作の表） | 4 |
| `tests/Feature/Approval/ApprovalAdminGateTest.php` | ⑩ の 4 本は管理の画面（`OPEN_TO_EVERY_USER` に入れない）。`$existingValues` に `approvalRequest`・`tableCounts()` に申請・段階・記録・下限を 43 / 26 / 234 へ | 5 |
| `tests/Feature/Approval/Phase2/LaunchGateTest.php` | `MIN_GATED` を 16 → **20** | 5 |
| `lang/ja/validation.php` の `attributes` | `admin_reason` 理由・`assignee_user_id` 付け替え先（既存の `reason` は「改定理由」なので使わない。`JapaneseValidationMessagesTest`） | 5 |

- `StoredTimestampDisplayScanTest`: 新しい画面の日時はすべて `JapanTime::format()`（登録は要らない）
- `MobileLayoutTest`: ⑩ の表は `scroll-hint` の中（登録は要らない）
- `LayoutSidebarCloakTest`: 展開のサイドバーの「決裁」に `x-cloak` を付けない
- `ApprovalSidebarTest`: 使い始める前は基幹の画面に「決裁」を出さない（2a のテストのまま緑。件数は使い始めてから）

### 0.13 設計書から変えた細部（計画を書いていて分かったこと）

| # | 設計書 | この計画 | 理由 |
|---|---|---|---|
| 1 | §5.16「D3 はキーを再帰的に並べてから `===` で比べる」 | 比べる値だけを決まった並びに詰め直して（`editableFingerprint()`）`===` で比べる | 名前（種類・部門の名前の変更）や控えだけにある値（添付の名前・大きさ）で「変えた」と誤らない。MySQL の JSON のキーの並びに左右されないのは同じ |
| 2 | §5.14 の一覧は進行中の申請だけ | タブ「決裁済み・否決」を足した | 社長の判断の押し間違いは、決裁済み・否決の状態から取り消す。進行中の一覧だけでは入口が無い |
| 3 | §5.14 の操作の置き場所は書いていない | 詳細（③）の「決裁の管理者の操作」 | 中身・回る順番・記録を見てから操作する。2a の判断の小窓と同じ作りで、同時操作の見張りも同じ |
| 4 | §5.16「取り消した判断の `actor_user_id` を消すかは段階3 と決める」 | 段階の `actor_user_id` は空に戻し、見られる範囲は記録の判断した人で保つ | 段階は「待ち」に戻るので判断した人を残せない。見られなくなると、段階3 の通知（4.7）を受けた人が開けない |
| 5 | §5.16「待ち日数に届き直した日時が要るなら別の列か記録」 | 要らない。待ち日数は元の届いた日から（付け替え・取り消しのあとも） | 2a の C11（待ち日数は段階が届いた日から）と同じ読み方。列を足さない（表を変えない） |

### 0.14 受け入れた隙間

| # | 隙間 | 起きたとき | 塞ぐなら |
|---|---|---|---|
| 1 | 付け替えたあとに承認された部門長の段階は、そのあと部門長を交代しても担当の欄が残る（交代で動かすのは待ちの段階だけ＝2a）。その承認を取り消すと、付け替えた人の待ちに戻る | 付け替えた人に届くだけ（判断はできる）。管理者が付け替え直せる | 取り消しのときに、付け替えの後に交代があれば担当の欄を空に戻す |
| 2 | ⑩ の「進行中」は全件を 1 ページに出す | 会社の規模（進行中は数十件）では困らない | 「決裁済み・否決」と同じページ送りを足す |
| 3 | ⑩ の「待ち日数」は、差戻し中・条件確認待ち（申請者の番）では状態が変わった日から数える（ホームの対応待ちと同じ） | 条件の確認を取り消して条件確認待ちに戻したときは、取り消した日から数え直す | 社長の条可の記録の日時から数える |
| 4 | 関連する決裁No の候補で、差戻し中の番号付きの申請は控えの件名（JSON）で当てる。本番の MySQL では JSON から取り出した文字列の照合が `utf8mb4_bin` になるので、大文字・小文字やひらがな・カタカナの違いを区別して当てる（ふだんの件名の列は区別しない `utf8mb4_unicode_ci`） | 番号付きで差戻し中の申請（社長の判断を取り消したあと社長が差し戻したもの）だけ、打ち方によって候補に出ないことがある。番号で探せば必ず出る | 当てる式に照合順序を付ける（SQLite と書き方が分かれる） |

### 0.15 テストの土台

- 2a の `tests/Concerns/BuildsApprovalFixtures.php`（`approvalWorld()`・`submittedFor()`・`draftFor()`・`approvalAdmin()`・`baseUser()`・`approvalOnlyUser()`・`launchApprovals()`）と `tests/Concerns/ParsesForms.php`（`parseForm()`）をそのまま使う（変えない）
- 利用者の `status` は `$fillable` に無いので、テストで変えるときは `forceFill(['status' => 'inactive'])->save()`（`update()` は黙って何もしない）
- 経営層・テナントのダッシュボードを開くテストは `CreatesMansionSchema`・`CreatesRealEstateSchema` を使う（無いとダッシュボードが 500）
- 画面の文言を見るテストでは、`assertSessionHas*()` を呼んだあとに画面を開かない（フラッシュが消費される。Bug #49）

## 1. 触るファイル

### 新規

| ファイル | 役目 | Task |
|---|---|---|
| `app/Support/Approval/FormInput.php` | 入力の改行と版の読み方（m-7） | 1 |
| `app/Support/Approval/LineDiff.php` | 本文の行ごとの差 | 2 |
| `resources/views/approvals/requests/_changes.blade.php`・`_history.blade.php` | 詳細の前回からの変更点・提出の履歴 | 3 |
| `app/Support/Approval/UndoTarget.php` | 次に取り消す操作と D3 の確かめ | 4 |
| `app/Support/Approval/Assignees.php` | 担当に選べる人の決まり（部門の管理と付け替えで共通） | 5 |
| `app/Http/Controllers/Approval/AdminRequestController.php` | ⑩ の一覧と、管理者の操作の送り先 | 5 |
| `resources/views/approvals/admin/requests.blade.php` | ⑩ | 5 |
| `resources/views/approvals/requests/_admin_actions.blade.php` | 詳細の「決裁の管理者の操作」（3 つの小窓） | 5 |
| `app/Support/Approval/ApprovalMenu.php` | メニューの件数（1 リクエストに 1 回） | 7 |
| `resources/views/approvals/_pending_card.blade.php` | ダッシュボードのカード | 7 |
| `tests/Unit/Approval/FormInputTest.php`・`LineDiffTest.php`・`RequestSnapshotTest.php` | 部品の単体テスト | 1・2 |
| `tests/Feature/Approval/Phase2/RequestChangesTest.php`・`WorkflowAdminTest.php`・`AdminRequestsTest.php`・`ApprovalMenuTest.php` | 2b のテスト | 3・4・5・7 |

### 変更

| ファイル | 変えること | Task |
|---|---|---|
| `app/Http/Controllers/Approval/RequestController.php` | 版の読み方（1）・変更点と履歴を渡す（3）・付け替えの候補と権限を渡す（5） | 1・3・5 |
| `app/Http/Controllers/Approval/RequestActionController.php` | `FormInput`（1）・開き直す小窓を送り先で決める（5） | 1・5 |
| `app/Http/Controllers/Approval/TypeController.php` | `FormInput`・N-1 | 1 |
| `app/Http/Controllers/Approval/OrganizationController.php` | `Assignees` | 5 |
| `app/Http/Controllers/Approval/RelatedNumberController.php` | 差戻し中は控えの件名（D26） | 6 |
| `app/Support/Approval/RequestSnapshot.php` | `changes()`・`hasChanges()`・`editableFingerprint()` | 2 |
| `app/Support/Approval/Workflow.php` | `reassignHead()`・`undo()`・`withdrawByAdmin()` | 4 |
| `app/Support/Approval/RequestPermissions.php` | 管理者の操作の権限と断りの理由 | 4 |
| `app/Support/Approval/RequestVisibility.php` | 判断を取り消された人 | 4 |
| `app/Support/Approval/PendingWork.php` | `countFor()` | 7 |
| `app/Models/ApprovalHistory.php` | 記録の名前 3 つ | 4 |
| `app/Models/ApprovalSetting.php` | `launchedForMenu()` | 5 |
| `routes/approval.php`・`lang/ja/validation.php` | ⑩ のルート 4 本・和名 2 つ | 5 |
| `resources/views/approvals/requests/show.blade.php` | 変更点・履歴（3）・管理者の操作（5） | 3・5 |
| `resources/views/approvals/requests/_actions.blade.php`・`_steps.blade.php` | 開き直す小窓・m-2・届いた日時は待ちの段階だけ | 5 |
| `resources/views/layouts/partials/sidebar.blade.php`・`sidebar_approval.blade.php` | ⑩ のリンク（5）・「決裁」と件数（7） | 5・7 |
| `resources/views/components/sidebar-item.blade.php`・`resources/views/dashboard/executive.blade.php`・`tenant.blade.php` | 件数の丸・ダッシュボードのカード | 7 |
| テスト: `RequestFormTest`・`TypeManagementTest`・`RequestActionTest`・`RequestAttachmentTest`・`WorkflowTest`・`ClockReadScanTest`・`ApprovalAdminGateTest`・`LaunchGateTest`・`RelatedNumberSearchTest`・`PendingWorkTest` | 各 Task の説明のとおり | 1〜7 |
| `docs/superpowers/specs/2026-09-25-approval-phase2-design.md`・`CLAUDE.md`・`docs/ARCHITECTURE.md`・`routes/web.php`（見出しの本数）・`docs/BACKLOG.md` | ドキュメント | 10 |

## 2. 作業の順番

途中の各段でも、既存のテストを含めて全部が通る状態を保つ（設計書 §10）。設計書 §10 の 2b の案（変更点 → ⑩ → 件数）の前に 2a の軽微の片付けを置き、⑩ の前に `Workflow` を置いた（画面が `Workflow` を呼ぶ。2a と同じ）。

| Task | 中身 | 設計書 | コミット | 全件（実測） |
|---|---|---|---|---|
| 0 | 作業場所の準備（worktree・vendor・全件が緑） | — | — | 2,765 本 |
| 1 | 2a の軽微の片付け（m-7 の `FormInput`・保存の版・N-1） | §5.16 | 2 | 2,781 本 |
| 2 | 行ごとの差と控えの比べ方（`LineDiff`・`RequestSnapshot`） | §5.13 | 1 | 2,799 本 |
| 3 | 詳細の前回からの変更点と提出の履歴 | §5.13 | 1 | 2,806 本 |
| 4 | `Workflow` の付け替え・取り消し・代理の取り下げ（権限・見られる範囲・記録） | §5.14・§5.16 | 1 | 2,830 本 |
| 5 | ⑩ と詳細の管理者の操作（`Assignees`・m-6・m-2・門番・サイドバー） | §5.14・§5.16 | 3 | 2,852 本 |
| 6 | 関連する決裁No の候補（D26） | §5.16 | 1 | 2,853 本 |
| 7 | 基幹のメニューとダッシュボードの件数 | §5.15 | 1 | 2,860 本 |
| 8 | 全件テストと変異テスト | §6 | 0〜1 | — |
| 9 | 手元のブラウザでの確認と、利用者に見せる画面の写真 | §6・D1 | 0〜 | — |
| 10 | ドキュメント（設計書への書き戻しを含む） | §7 の 6 | 1〜2 | — |
| 11 | 本番反映（利用者の了承を取ってから・親の会話が行う） | §7 | 1 | — |

---

## Task 0: 作業場所の準備

**作業場所**: worktree `/Users/masanori/site/manage/.claude/worktrees/approval-phase2b`（ブランチ `approval-phase2b`。この計画のコミットの親は `13.x` = `071f028c`）。**main repo では作業もテストもしない**（main repo の vendor は `--no-dev` で phpunit が無い。dev 依存を入れると `./deploy.sh` が本番へ送る）。

- [ ] **Step 1: 並行の作業を確かめる**（ほかの会話が同じ課題を進めていないか）

```bash
cd /Users/masanori/site/manage && git status --short --branch && git log --oneline -5 && git worktree list && git for-each-ref --sort=-committerdate --format='%(refname:short) %(committerdate:short) %(subject)' refs/heads | head
```

Expected: worktree `approval-phase2b` の先頭がこの計画のコミット。ほかに 2b の枝や worktree が無い（`customer-import-double-submit`・`import-double-submit` は別の会話のもの。触らない）。2b の名前の付いたほかの枝や worktree があれば、中身を読み、消さずに利用者へ報告して止まる。

- [ ] **Step 2: vendor を確かめる**（dev 依存あり・実体）

⚠ **symlink にしない**（autoload が symlink の先を読み、別の場所のコードでテストが流れる。Bug #50）。worktree には 2a の worktree から実体で写した vendor がある。

```bash
WT=/Users/masanori/site/manage/.claude/worktrees/approval-phase2b; test -x "$WT/vendor/bin/phpunit" && ! test -L "$WT/vendor" && echo "vendor OK"
```

Expected: `vendor OK`（無ければ worktree の cwd で `composer install`。**main repo では絶対に `composer install` しない**）

- [ ] **Step 3: 差分のファイルを確かめる**

```bash
ls ~/.claude/plans/approval-phase2b-tasks/patches/ && cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2b && git apply --check ~/.claude/plans/approval-phase2b-tasks/patches/0001-*.patch && echo "0001 を当てられる"
```

Expected: `0001-…` 〜 `0010-…` の 10 本と `redfirst.txt`・`0001 を当てられる`。無ければ計画のコードを打ち込む（中身は同じ）。

- [ ] **Step 4: 全件テストが通る状態から始める**

**テストの流し方**（以下すべての Task で同じ。`APP_KEY` は 32 バイトの本物の鍵を渡す。worktree に `.env` を作らない）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2b && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `OK (2765 tests, 19311 assertions)`（2026-09-28 の実測。約 2 分）。赤があれば 2b の作業の前に利用者へ報告して止まる。

⚠ 以下の Task の「テストを流す」は、すべてこの形でファイルを並べたもの。`cd` はコマンドごとに書く（ターンをまたぐと cwd が main repo へ戻る）。git は `git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b …` で呼ぶ。

⚠ コミットのたびに `git status --porcelain` が空であることを確かめる。差分のファイルを当てたときは、`git diff --stat` が各 Task の「差分の大きさ」と同じことも確かめる。


---

## Task 1: 2a の軽微の片付け（m-7 の `FormInput`・保存の版・N-1）

2b で入力欄（管理者の操作の理由）が増える前に、2a の点検で後回しにした軽微を片付ける（§0.11）。コミットは 2 つ（1-A・1-B）。

### 1-A: 版と改行の読み方を `FormInput` の 1 か所へ

3 つのコントローラに同じ中身であった `unifyNewlines()`（改行を LF にそろえる。2a の B1）と版の読み方（`lock_version`。2a Task 15 の m-1）を、新しい部品 `FormInput` に寄せる。申請書の保存（`RequestController::update`）の版も同じ形で読む（今は `integer()` で「0abc」を 0 と読む）。

**Files:**
- Create: `app/Support/Approval/FormInput.php`
- Modify: `app/Http/Controllers/Approval/RequestActionController.php`・`RequestController.php`・`TypeController.php`（持っていた写しを消して部品を呼ぶ）
- Test: Create `tests/Unit/Approval/FormInputTest.php`・Modify `tests/Feature/Approval/Phase2/RequestFormTest.php`（1 本足す）

**Interfaces:**
- Produces: `FormInput::unifyNewlines(Request $request, string ...$keys): void`（指定した入力のうち文字列のものの `\r\n`・`\r` を `\n` にする。文字列でないものは入力の検査に任せる）／`FormInput::lockVersion(Request $request, int $whenMissing = -1): int`（`lock_version` が無ければ `$whenMissing`、`/\A\d{1,9}\z/` の形ならその数、ほかは `-1`＝どの版とも合わず先を越された扱い）。Task 5 のコントローラが両方を使う

**差分の大きさ:** 6 ファイル・+137 / −60 行（差分のファイル `0001-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Approval/FormInputTest.php`（新規）

```php
<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\FormInput;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 決裁の画面から送られてきた値の読み方（段階2b 計画 §0.11） */
class FormInputTest extends TestCase
{
    public function test_newlines_are_unified_for_each_key(): void
    {
        $request = Request::create('/', 'POST', ['comment' => "一行目\r\n二行目\r三行目\n四行目", 'reason' => "あ\r\nい", 'other' => "う\r\nえ"]);

        FormInput::unifyNewlines($request, 'comment', 'reason');

        $this->assertSame("一行目\n二行目\n三行目\n四行目", $request->input('comment'));
        $this->assertSame("あ\nい", $request->input('reason'));
        $this->assertSame("う\r\nえ", $request->input('other'), '名指ししていない値は変えない');
    }

    public function test_values_that_are_not_strings_are_left_for_the_validation(): void
    {
        $request = Request::create('/', 'POST', ['comment' => ["a\r\nb"]]);

        FormInput::unifyNewlines($request, 'comment', 'missing');

        $this->assertSame(["a\r\nb"], $request->input('comment'));
        $this->assertNull($request->input('missing'), '送られていない値を足さない');
    }

    public static function versions(): array
    {
        return [
            '0'           => ['0', 0],
            '12'          => ['12', 12],
            '9 桁まで'    => ['123456789', 123456789],
            '10 桁'       => ['1234567890', -1],
            '文字が混ざる' => ['1abc', -1],
            '負の数'      => ['-1', -1],
            '空'          => ['', -1],
            '小数'        => ['1.0', -1],
            '前後の空白'  => [' 1', -1],
            '配列'        => [['1'], -1],
        ];
    }

    #[DataProvider('versions')]
    public function test_lock_version_is_read_only_in_the_shape_of_a_whole_number(mixed $sent, int $expected): void
    {
        $this->assertSame($expected, FormInput::lockVersion(Request::create('/', 'POST', ['lock_version' => $sent])));
    }

    public function test_a_missing_lock_version_falls_back_to_the_given_value(): void
    {
        $this->assertSame(-1, FormInput::lockVersion(Request::create('/', 'POST', [])), '既定は必ず断る -1');
        $this->assertSame(0, FormInput::lockVersion(Request::create('/', 'POST', []), 0), '申請書の保存は 0');
        $this->assertSame(-1, FormInput::lockVersion(Request::create('/', 'POST', ['lock_version' => '0x1']), 0), '形が違えば既定の値に関係なく -1');
    }
}
```

`tests/Feature/Approval/Phase2/RequestFormTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/RequestFormTest.php
+++ b/tests/Feature/Approval/Phase2/RequestFormTest.php
@@ -599,6 +599,21 @@ public function test_others_who_can_see_a_returned_request_cannot_open_or_save_i
         $this->assertSame('直しかけの件名', $request->fresh()->subject);
     }
 
+    /** 版が 0 以上の整数の形でなければ保存しない（intval で「0abc」を 0 と読まない。2a の Task 15 の点検の軽微。2b 計画 Task 1） */
+    public function test_a_lock_version_that_is_not_a_whole_number_does_not_save(): void
+    {
+        $w = $this->approvalWorld();
+        $this->launchApprovals();
+        $draft = $this->draftFor($w);   // 作ってから一度も保存し直していない下書き（版 0）
+
+        $this->actingAs($w['applicant'])->put(route('approvals.requests.update', $draft), $this->filled($w, [
+            'subject' => '書き換え', 'lock_version' => '0abc', 'intent' => 'save',
+        ]))->assertRedirect(route('approvals.requests.edit', $draft));
+
+        $this->assertSame('社用車の購入', $draft->fresh()->subject);
+        $this->assertSame(0, $draft->fresh()->lock_version);
+    }
+
     /** 控えの無い提出済みの申請は、申請者以外には 404（今の行に落とさない。分からないときは見せない。仕様の 1.3） */
     public function test_others_get_not_found_when_the_last_submission_is_missing(): void
     {
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2b && git apply --include='tests/*' ~/.claude/plans/approval-phase2b-tasks/patches/0001-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2b && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Unit/Approval/FormInputTest.php tests/Feature/Approval/Phase2/RequestFormTest.php
```

Expected: `ERRORS!` `Tests: 49, Assertions: 407, Errors: 13, Failures: 1.`

- `FormInputTest::test_newlines_are_unified_for_each_key`
- `FormInputTest::test_values_that_are_not_strings_are_left_for_the_validation`
- `FormInputTest::test_lock_version_is_read_only_in_the_shape_of_a_whole_number`（with data set #0 ('0', 0)）
- `FormInputTest::test_lock_version_is_read_only_in_the_shape_of_a_whole_number`（with data set #1 ('12', 12)）
- `FormInputTest::test_lock_version_is_read_only_in_the_shape_of_a_whole_number`（with data set "9 桁まで" ('123456789', 123456789)）
- `FormInputTest::test_lock_version_is_read_only_in_the_shape_of_a_whole_number`（with data set "10 桁" ('1234567890', -1)）
- `FormInputTest::test_lock_version_is_read_only_in_the_shape_of_a_whole_number`（with data set "文字が混ざる" ('1abc', -1)）
- `FormInputTest::test_lock_version_is_read_only_in_the_shape_of_a_whole_number`（with data set "負の数" ('-1', -1)）
- `FormInputTest::test_lock_version_is_read_only_in_the_shape_of_a_whole_number`（with data set "空" ('', -1)）
- `FormInputTest::test_lock_version_is_read_only_in_the_shape_of_a_whole_number`（with data set "小数" ('1.0', -1)）
- `FormInputTest::test_lock_version_is_read_only_in_the_shape_of_a_whole_number`（with data set "前後の空白" (' 1', -1)）
- `FormInputTest::test_lock_version_is_read_only_in_the_shape_of_a_whole_number`（with data set "配列" (['1'], -1)）
- `FormInputTest::test_a_missing_lock_version_falls_back_to_the_given_value`
- `RequestFormTest::test_a_lock_version_that_is_not_a_whole_number_does_not_save`

`FormInputTest` は部品が無いので Error（`Class "App\Support\Approval\FormInput" not found`）、`RequestFormTest` の 1 本は「0abc」を 0 と読んで保存するので Failure。

- [ ] **Step 3: 部品を書く**

`app/Support/Approval/FormInput.php`（新規）

```php
<?php

namespace App\Support\Approval;

use Illuminate\Http\Request;

/**
 * 決裁の画面から送られてきた値の読み方（段階2b 計画 §0.11）。2a では 3 つのコントローラに同じ中身の
 * private メソッドがあった（Task 19 の点検 m-7）ので、2b で理由の入力欄が増えるのに合わせて 1 か所に寄せる。
 */
final class FormInput
{
    /**
     * 改行を \n にそろえる（Task 19 の B1）。ブラウザの maxlength は改行を 1 文字と数えるが、送るときは \r\n にするので、
     * そろえずに数えると改行の多い入力が上限の手前で断られる。検査の前に呼ぶ（保存する値もそろえた形になる）。
     * 文字列でない値（配列など）はそのまま（検査の `string` で断る）。
     */
    public static function unifyNewlines(Request $request, string ...$keys): void
    {
        foreach ($keys as $key) {
            $value = $request->input($key);

            if (is_string($value)) {
                $request->merge([$key => str_replace(["\r\n", "\r"], "\n", $value)]);
            }
        }
    }

    /**
     * 画面が描いたときの lock_version（計画 2a §0.3）。0 以上の整数の形でなければ -1（必ず「すでに処理されています」）。
     * ⚠ `$request->integer()` は intval なので、配列を 1、'1abc' を 1 と読んでしまう（2a の Task 15 の点検 m-1）。
     *
     * @param int $whenMissing 送られてこなかったときの値。判断などの操作は -1（必ず断る）。申請書の保存は 0
     *                         （作ってから一度も保存し直していない下書きの版。2a からの振る舞い）
     */
    public static function lockVersion(Request $request, int $whenMissing = -1): int
    {
        $value = $request->input('lock_version');

        if ($value === null) {
            return $whenMissing;
        }

        return is_string($value) && preg_match('/\A\d{1,9}\z/', $value) === 1 ? (int) $value : -1;
    }
}
```

- [ ] **Step 4: 3 つのコントローラを部品に寄せる**（持っていた写しを消す。申請書の保存は、送られてこなければ 0 のまま＝2a の下書きの作り方と同じ）

`app/Http/Controllers/Approval/RequestActionController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/RequestActionController.php
+++ b/app/Http/Controllers/Approval/RequestActionController.php
@@ -6,6 +6,7 @@
 use App\Enums\ApprovalStepResult;
 use App\Http\Controllers\Controller;
 use App\Models\ApprovalRequest;
+use App\Support\Approval\FormInput;
 use App\Support\Approval\RequestVisibility;
 use App\Support\Approval\Workflow;
 use App\Support\Approval\WorkflowConflict;
@@ -21,7 +22,7 @@
  *
  * ⚠ 権限と状態は Workflow（RequestPermissions）が確かめる。ここは入力の形を見て渡すだけ。
  * ⚠ 画面が描いたときの lock_version を渡す（古い画面から押した操作を「すでに処理されています」で断る。計画 §0.3）。
- *   送られてこない・0 以上の整数の形でないときは -1 にする（必ず断られる。lockVersion()）。
+ *   送られてこない・0 以上の整数の形でないときは -1 にする（必ず断られる。FormInput::lockVersion()）。
  * ⚠ 戻り先はいつも詳細の画面（Bug #64）。
  */
 class RequestActionController extends Controller
@@ -55,7 +56,7 @@ public function confirmCondition(Request $request, ApprovalRequest $approvalRequ
 
         return $this->run(
             $approvalRequest,
-            fn () => $this->workflow->confirmCondition($approvalRequest, $request->user(), self::lockVersion($request), $comment),
+            fn () => $this->workflow->confirmCondition($approvalRequest, $request->user(), FormInput::lockVersion($request), $comment),
             '条件を確認しました。決裁が完了しました。',
         );
     }
@@ -67,7 +68,7 @@ public function withdraw(Request $request, ApprovalRequest $approvalRequest): Re
 
         return $this->run(
             $approvalRequest,
-            fn () => $this->workflow->withdraw($approvalRequest, $request->user(), self::lockVersion($request), $comment),
+            fn () => $this->workflow->withdraw($approvalRequest, $request->user(), FormInput::lockVersion($request), $comment),
             '取り下げました。',
         );
     }
@@ -77,7 +78,7 @@ private function judge(Request $request, ApprovalRequest $approvalRequest, Appro
         $this->assertVisible($request, $approvalRequest);
 
         $allowed = array_map(fn (ApprovalStepResult $result) => $result->value, ApprovalStepResult::allowedFor($kind));
-        self::unifyNewlines($request, 'comment');
+        FormInput::unifyNewlines($request, 'comment');
 
         try {
             $validated = $request->validate([
@@ -93,7 +94,7 @@ private function judge(Request $request, ApprovalRequest $approvalRequest, Appro
 
         $result  = ApprovalStepResult::from($validated['result']);
         $comment = $validated['comment'] ?? null;
-        $version = self::lockVersion($request);
+        $version = FormInput::lockVersion($request);
         $actor   = $request->user();
 
         return $this->run(
@@ -148,7 +149,7 @@ private static function judgedMessage(ApprovalStepKind $kind, ApprovalStepResult
     private function optionalComment(Request $request, ApprovalRequest $approvalRequest): ?string
     {
         $this->assertVisible($request, $approvalRequest);
-        self::unifyNewlines($request, 'comment');
+        FormInput::unifyNewlines($request, 'comment');
 
         try {
             $validated = $request->validate([
@@ -161,30 +162,6 @@ private function optionalComment(Request $request, ApprovalRequest $approvalRequ
         return $validated['comment'] ?? null;
     }
 
-    /**
-     * 改行を \n にそろえてから検査する（Task 19 の B1）。ブラウザの maxlength は改行を 1 文字と数えるが、送るときは \r\n にするので、
-     * そろえずに数えると改行の多いコメントが max:2000 で断られる。保存する値もそろえた形になる
-     */
-    private static function unifyNewlines(Request $request, string $key): void
-    {
-        $value = $request->input($key);
-
-        if (is_string($value)) {
-            $request->merge([$key => str_replace(["\r\n", "\r"], "\n", $value)]);
-        }
-    }
-
-    /**
-     * 画面が描いたときの lock_version。送られてこない・0 以上の整数の形でないときは -1（必ず「すでに処理されています」）。
-     * ⚠ `$request->integer()` は intval なので、配列を 1、'1abc' を 1 と読んでしまう（Task 15 の点検）
-     */
-    private static function lockVersion(Request $request): int
-    {
-        $value = $request->input('lock_version');
-
-        return is_string($value) && preg_match('/\A\d{1,9}\z/', $value) === 1 ? (int) $value : -1;
-    }
-
     /** 見られない申請は 404（在るかどうかを漏らさない。設計書 §5.10） */
     private function assertVisible(Request $request, ApprovalRequest $approvalRequest): void
     {
```

`app/Http/Controllers/Approval/RequestController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/RequestController.php
+++ b/app/Http/Controllers/Approval/RequestController.php
@@ -10,6 +10,7 @@
 use App\Models\ApprovalStep;
 use App\Models\ApprovalType;
 use App\Models\User;
+use App\Support\Approval\FormInput;
 use App\Support\Approval\RelatedNumbers;
 use App\Support\Approval\RequestContent;
 use App\Support\Approval\RequestPermissions;
@@ -153,8 +154,9 @@ public function update(Request $request, ApprovalRequest $approvalRequest): Redi
         }
 
         $validated = $this->validated($request, $approvalRequest, route('approvals.requests.edit', $approvalRequest));
-        // 編集の画面を描いたときの版（送られてこなければ 0＝作ってから一度も保存し直していない下書きの版）
-        $lockVersion = $request->integer('lock_version');
+        // 編集の画面を描いたときの版（送られてこなければ 0＝作ってから一度も保存し直していない下書きの版。
+        // 0 以上の整数の形でなければ -1 で必ず断る。2a の Task 15 の点検の軽微＝intval で「1abc」を 1 と読んでいた）
+        $lockVersion = FormInput::lockVersion($request, 0);
 
         // ⚠ lock_version を条件にした 1 回の UPDATE で保存し、lock_version を 1 進める（計画 §0.3）。別のタブで先に
         //   保存・提出した申請は lock_version が進んでいるので 0 行になる（状態を変える操作も lock_version を進める）。
@@ -244,7 +246,7 @@ private function validated(Request $request, ?ApprovalRequest $current, string $
             'amount'          => self::normalizeAmount($request->input('amount')),
             'related_numbers' => RelatedNumbers::clean(is_array($request->input('related_numbers')) ? $request->input('related_numbers') : []),
         ]);
-        self::unifyNewlines($request, 'body');
+        FormInput::unifyNewlines($request, 'body');
 
         // 選べる種類は利用中の種類と、この申請が今使っている種類（停止していても保存はできる。D10）
         $typeIds = ApprovalType::active()->pluck('id')->push($current?->type_id)->filter()->all();
@@ -290,19 +292,6 @@ private static function normalizeAmount(mixed $value): mixed
         return $digits === '' ? null : $digits;
     }
 
-    /**
-     * 改行を \n にそろえてから検査する（Task 19 の B1）。ブラウザの maxlength は改行を 1 文字と数えるが、送るときは \r\n にするので、
-     * そろえずに数えると改行の多い本文が max:20000 で断られる。保存する値もそろえた形になる
-     */
-    private static function unifyNewlines(Request $request, string $key): void
-    {
-        $value = $request->input($key);
-
-        if (is_string($value)) {
-            $request->merge([$key => str_replace(["\r\n", "\r"], "\n", $value)]);
-        }
-    }
-
     /** コピーして作成で写す中身（設計書 §5.6。添付は写さない） */
     private function copiedFields(ApprovalRequest $source, User $user): array
     {
```

`app/Http/Controllers/Approval/TypeController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/TypeController.php
+++ b/app/Http/Controllers/Approval/TypeController.php
@@ -6,6 +6,7 @@
 use App\Models\ApprovalDepartment;
 use App\Models\ApprovalType;
 use App\Support\Approval\BodyTemplate;
+use App\Support\Approval\FormInput;
 use App\Support\Approval\SettingLogger;
 use Illuminate\Http\Request;
 use Illuminate\Validation\Rule;
@@ -74,7 +75,7 @@ private function validateType(Request $request, ?ApprovalType $current = null):
     {
         // チェックボックスは外すと送られない（送られなければ停止）
         $request->merge(['is_active' => $request->boolean('is_active')]);
-        self::unifyNewlines($request, 'headings');
+        FormInput::unifyNewlines($request, 'headings');
 
         return $request->validate([
             'name'                 => ['required', 'string', 'max:50', Rule::unique('approval_types', 'name')->ignore($current?->id)],
@@ -101,19 +102,6 @@ private function validateType(Request $request, ?ApprovalType $current = null):
         ]);
     }
 
-    /**
-     * 改行を \n にそろえてから検査する（Task 19 の B1）。ブラウザの maxlength は改行を 1 文字と数えるが、送るときは \r\n にするので、
-     * そろえずに数えると改行の多い見出しが max:2000 で断られる。保存する値もそろえた形になる
-     */
-    private static function unifyNewlines(Request $request, string $key): void
-    {
-        $value = $request->input($key);
-
-        if (is_string($value)) {
-            $request->merge([$key => str_replace(["\r\n", "\r"], "\n", $value)]);
-        }
-    }
-
     private function back(?string $success, ?string $error = null)
     {
         $redirect = redirect()->route('approvals.admin.types.index');
```

（差分のファイルを使うなら: `git apply --exclude='tests/*' ~/.claude/plans/approval-phase2b-tasks/patches/0001-*.patch`）

- [ ] **Step 5: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (49 tests, …)`

- [ ] **Step 6: 全件を流す**

Expected: `OK (2779 tests, 19334 assertions)`

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b add app/Support/Approval/FormInput.php app/Http/Controllers/Approval/RequestActionController.php app/Http/Controllers/Approval/RequestController.php app/Http/Controllers/Approval/TypeController.php tests/Unit/Approval/FormInputTest.php tests/Feature/Approval/Phase2/RequestFormTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b commit -m "$(cat <<'MSG'
fix(approval): 版と改行の読み方を FormInput の 1 か所にそろえる

3 つのコントローラに同じ中身であった改行のそろえ方と版の読み方を
App\Support\Approval\FormInput に寄せる（2a の点検 m-7）。申請書の保存の版も
0 以上の整数の形だけを受け付け、「0abc」を 0 と読まない（2a Task 15 の軽微）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

### 1-B: 申請種類の一覧の「審査担当者がいません」は有効な人だけ数える（N-1）

提出の条件（`SubmitChecker`）は有効な審査担当者だけを数えるのに、申請種類の一覧の注意は無効の人も 1 人と数えていた（唯一の担当者が無効だと「一覧に注意なし・提出は断る」と食い違う）。削除した人を数えないこと（SoftDeletes の関係）もテストで固定する（2a の変異 N07 が緑だった）。

**Files:**
- Modify: `app/Http/Controllers/Approval/TypeController.php`（`withCount` の条件）
- Test: Modify `tests/Feature/Approval/Phase2/TypeManagementTest.php`（2 本足す）

**Interfaces:** なし（画面の注意だけ）

**差分の大きさ:** 2 ファイル・+32 / −2 行（差分のファイル `0002-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/TypeManagementTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/TypeManagementTest.php
+++ b/tests/Feature/Approval/Phase2/TypeManagementTest.php
@@ -482,6 +482,31 @@ public function test_a_review_department_without_reviewers_is_flagged(): void
         $this->assertStringContainsString('<span class="ml-1 text-[11px] font-semibold text-red-700">審査担当者がいません</span>', $html);
     }
 
+    /**
+     * 無効にした審査担当者しかいない審査部門も「審査担当者がいません」と出す（提出の条件 SubmitChecker と同じく、有効な人だけ数える。
+     * 2a の Task 11 の再点検 N-1。2b 計画 Task 1）
+     */
+    public function test_a_review_department_whose_only_reviewer_is_inactive_is_flagged(): void
+    {
+        $w = $this->approvalWorld();
+        $w['reviewer']->forceFill(['status' => 'inactive'])->save();   // status は一括代入できない列（User の $fillable の注意）
+
+        $html = $this->indexHtml($this->approvalAdmin());
+
+        $this->assertStringContainsString('<span class="ml-1 text-[11px] font-semibold text-red-700">審査担当者がいません</span>', $html);
+    }
+
+    /** 削除した審査担当者しかいない審査部門も「審査担当者がいません」と出す（削除した人を数えないことを固定する。N-1 の後半・変異 N07） */
+    public function test_a_review_department_whose_only_reviewer_is_deleted_is_flagged(): void
+    {
+        $w = $this->approvalWorld();
+        $w['reviewer']->delete();
+
+        $html = $this->indexHtml($this->approvalAdmin());
+
+        $this->assertStringContainsString('<span class="ml-1 text-[11px] font-semibold text-red-700">審査担当者がいません</span>', $html);
+    }
+
     /**
      * 追加の小窓で断られたら、打った中身（種類名・審査部門・見出し・表示順・利用中）で小窓を開き直す（Task 19 の C4。
      * 利用者の決定 2026-09-28。部門の管理は今のまま）。描き直した追加のフォームを送り返せば、打った中身になる
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2b && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase2/TypeManagementTest.php
```

Expected: `FAILURES!` `Tests: 30, Assertions: 232, Failures: 1.`

- `TypeManagementTest::test_a_review_department_whose_only_reviewer_is_inactive_is_flagged`

削除した人のテストは今でも緑（SoftDeletes の関係が数えない。変わらないことを固定するためのテスト）。

- [ ] **Step 3: 有効な人だけ数える**

`app/Http/Controllers/Approval/TypeController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/TypeController.php
+++ b/app/Http/Controllers/Approval/TypeController.php
@@ -2,6 +2,7 @@
 
 namespace App\Http\Controllers\Approval;
 
+use App\Enums\UserStatus;
 use App\Http\Controllers\Controller;
 use App\Models\ApprovalDepartment;
 use App\Models\ApprovalType;
@@ -22,8 +23,12 @@ class TypeController extends Controller
 {
     public function index()
     {
-        // 審査部門の審査担当者の人数も読む（いない部門は一覧で知らせる。Task 11 の点検の軽微）
-        $types = ApprovalType::with(['reviewDepartment' => fn ($q) => $q->withCount('reviewers')->with('company')])
+        // 審査部門の審査担当者の人数も読む（いない部門は一覧で知らせる。Task 11 の点検の軽微）。
+        // 提出の条件（SubmitChecker）と同じく有効な人だけ数える（無効の人しかいないのに注意が出ない食い違いを無くす。
+        // 削除した人は User の SoftDeletes で数えない。2a の Task 11 の再点検 N-1）
+        $types = ApprovalType::with(['reviewDepartment' => fn ($q) => $q
+                ->withCount(['reviewers' => fn ($q) => $q->where('users.status', UserStatus::Active->value)])
+                ->with('company')])
             ->withCount('requests')->ordered()->get();
 
         $departments = ApprovalDepartment::with('company')->get()
```

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (30 tests, …)`

- [ ] **Step 5: 全件を流す**

Expected: `OK (2781 tests, 19338 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b add app/Http/Controllers/Approval/TypeController.php tests/Feature/Approval/Phase2/TypeManagementTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b commit -m "$(cat <<'MSG'
fix(approval): 申請種類の一覧で無効な審査担当者を数えない

一覧の「審査担当者がいません」は、提出の条件と同じく有効な人だけを数える
（2a の点検 N-1）。削除した人を数えないこともテストで固定する。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 2: 行ごとの差と控えの比べ方（`LineDiff`・`RequestSnapshot`）

画面（Task 3）と取り消し（Task 4）が使う部品を先に作る（§0.3・§0.4 の D3）。`sebastian/diff` は開発用の依存で本番の vendor に無いので、行ごとの差は自前で作る（設計書 §5.13）。

**Files:**
- Create: `app/Support/Approval/LineDiff.php`
- Modify: `app/Support/Approval/RequestSnapshot.php`（`changes()`・`hasChanges()`・`editableFingerprint()` を足す）
- Test: Create `tests/Unit/Approval/LineDiffTest.php`・`tests/Unit/Approval/RequestSnapshotTest.php`

**Interfaces:**
- Produces: `LineDiff::SAME`・`REMOVED`・`ADDED`（`'same'`・`'removed'`・`'added'`）・`LineDiff::MAX_CELLS = 250000`／`LineDiff::text(?string $before, ?string $after): list<array{type: string, line: string}>`／`LineDiff::lines(list<string> $before, list<string> $after)`（同じ形）／`LineDiff::hasChanges(list<…> $diff): bool`
- Produces: `RequestSnapshot::changes(array $before, array $after): array{fields: list<array{label: string, before: string, after: string}>, body: ?list<array{type: string, line: string}>, attachments_added: list<array{id: int, name: string, size: int}>, attachments_removed: list<…>}`（`body` は本文が同じなら `null`）／`RequestSnapshot::hasChanges(array $changes): bool`／`RequestSnapshot::editableFingerprint(array $snapshot): array`（`make()` の形の控えを、比べる値だけの決まった並びにする）。Task 3 の画面と Task 4 の `UndoTarget` が使う

**差分の大きさ:** 4 ファイル・+557 / −1 行（差分のファイル `0003-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Approval/LineDiffTest.php`（新規）

```php
<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\LineDiff;
use PHPUnit\Framework\TestCase;

/** 本文の行ごとの差（設計書 §5.13） */
class LineDiffTest extends TestCase
{
    /** @param list<array{type: string, line: string}> $diff */
    private static function compact(array $diff): array
    {
        $mark = [LineDiff::SAME => ' ', LineDiff::REMOVED => '-', LineDiff::ADDED => '+'];

        return array_map(fn (array $op) => $mark[$op['type']] . $op['line'], $diff);
    }

    public function test_the_same_text_has_only_same_lines(): void
    {
        $diff = LineDiff::text("■ なぜ\n・老朽化", "■ なぜ\n・老朽化");

        $this->assertSame([' ■ なぜ', ' ・老朽化'], self::compact($diff));
        $this->assertFalse(LineDiff::hasChanges($diff));
    }

    public function test_an_added_line_in_the_middle(): void
    {
        $diff = LineDiff::text("■ なぜ\n・老朽化\n■ 何を\n・車", "■ なぜ\n・老朽化\n・燃費\n■ 何を\n・車");

        $this->assertSame([' ■ なぜ', ' ・老朽化', '+・燃費', ' ■ 何を', ' ・車'], self::compact($diff));
        $this->assertTrue(LineDiff::hasChanges($diff));
    }

    public function test_a_removed_line_and_a_changed_line(): void
    {
        $diff = LineDiff::text("■ なぜ\n・老朽化\n・燃費\n■ 何を\n・軽自動車", "■ なぜ\n・老朽化\n■ 何を\n・普通車");

        // 変えた行は「消えた行 → 増えた行」の組で出る
        $this->assertSame([' ■ なぜ', ' ・老朽化', '-・燃費', ' ■ 何を', '-・軽自動車', '+・普通車'], self::compact($diff));
    }

    public function test_repeated_lines_are_matched_by_the_longest_common_subsequence(): void
    {
        $diff = LineDiff::lines(['・', 'A', '・', 'B'], ['・', 'B', '・', 'A']);

        // 同じ行は 2 行（最長）残し、消えた行と増えた行は 1 行ずつ
        $this->assertCount(2, array_filter($diff, fn (array $op) => $op['type'] === LineDiff::SAME));
        $this->assertCount(2, array_filter($diff, fn (array $op) => $op['type'] === LineDiff::REMOVED));
        $this->assertCount(2, array_filter($diff, fn (array $op) => $op['type'] === LineDiff::ADDED));
        // 差を当てると後の行になる
        $this->assertSame(['・', 'B', '・', 'A'], self::apply($diff));
        $this->assertSame(['・', 'A', '・', 'B'], self::reverse($diff));
    }

    public function test_empty_texts_have_no_lines(): void
    {
        $this->assertSame([], LineDiff::text(null, ''));
        $this->assertSame(['+・新しい'], self::compact(LineDiff::text(null, '・新しい')));
        $this->assertSame(['-・古い'], self::compact(LineDiff::text('・古い', '')));
    }

    public function test_newlines_are_unified_before_splitting(): void
    {
        $this->assertFalse(LineDiff::hasChanges(LineDiff::text("■ なぜ\r\n・老朽化", "■ なぜ\n・老朽化")));
        $this->assertFalse(LineDiff::hasChanges(LineDiff::text("■ なぜ\r・老朽化", "■ なぜ\n・老朽化")));
    }

    public function test_a_trailing_newline_is_a_change(): void
    {
        $this->assertSame([' ・老朽化', '+'], self::compact(LineDiff::text('・老朽化', "・老朽化\n")));
    }

    public function test_a_huge_middle_falls_back_to_removed_then_added(): void
    {
        $before = array_merge(['■ 同じ前'], array_map(fn (int $i) => "前の行{$i}", range(1, 600)), ['■ 同じ後ろ']);
        $after  = array_merge(['■ 同じ前'], array_map(fn (int $i) => "後の行{$i}", range(1, 600)), ['■ 同じ後ろ']);
        $this->assertGreaterThan(LineDiff::MAX_CELLS, 600 * 600, '前提: 残りの積が上限を超える');

        $diff = LineDiff::lines($before, $after);

        $this->assertSame(' ■ 同じ前', self::compact($diff)[0]);
        $this->assertSame(' ■ 同じ後ろ', self::compact($diff)[count($diff) - 1]);
        $types = array_column(array_slice($diff, 1, 1200), 'type');
        $this->assertSame(array_merge(array_fill(0, 600, LineDiff::REMOVED), array_fill(0, 600, LineDiff::ADDED)), $types);
        $this->assertSame($after, self::apply($diff));
    }

    public function test_twenty_thousand_one_character_lines_do_not_exhaust_memory(): void
    {
        // 本文の上限 20,000 文字をすべて 1 文字の行にした形（1 万行 × 1 万行でも表を作らない）
        $before = str_repeat("あ\n", 9999) . 'あ';
        $after  = str_repeat("い\n", 9999) . 'い';

        memory_reset_peak_usage();   // 全件テストでは前のテストの山が残るので、ここから測る
        $base = memory_get_usage();

        $diff = LineDiff::text($before, $after);

        $this->assertCount(20000, $diff);
        $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage() - $base, 'メモリを使いすぎた（表を作った）');
    }

    /** @param list<array{type: string, line: string}> $diff @return list<string> */
    private static function apply(array $diff): array
    {
        return array_values(array_map(fn (array $op) => $op['line'], array_filter($diff, fn (array $op) => $op['type'] !== LineDiff::REMOVED)));
    }

    /** @param list<array{type: string, line: string}> $diff @return list<string> */
    private static function reverse(array $diff): array
    {
        return array_values(array_map(fn (array $op) => $op['line'], array_filter($diff, fn (array $op) => $op['type'] !== LineDiff::ADDED)));
    }
}
```

`tests/Unit/Approval/RequestSnapshotTest.php`（新規）

```php
<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\LineDiff;
use App\Support\Approval\RequestSnapshot;
use PHPUnit\Framework\TestCase;

/** 控えどうしの比べ方（設計書 §5.13・§5.14 の D3） */
class RequestSnapshotTest extends TestCase
{
    /** make() と同じ形の控え */
    private static function snapshot(array $overrides = []): array
    {
        return array_replace([
            'type'            => ['id' => 1, 'name' => '購入・発注'],
            'department'      => ['id' => 10, 'name' => '住宅事業部'],
            'subject'         => '社用車の購入',
            'amount'          => 2850000,
            'schedule'        => '2026年10月',
            'body'            => "■ なぜ（目的・理由）\n・老朽化のため",
            'related_numbers' => ['R8-J-001', 'R7-J-015'],
            'attachments'     => [['id' => 5, 'name' => '見積書.pdf', 'size' => 1234], ['id' => 6, 'name' => '写真.jpg', 'size' => 99]],
        ], $overrides);
    }

    public function test_the_same_snapshots_have_no_changes(): void
    {
        $changes = RequestSnapshot::changes(self::snapshot(), self::snapshot());

        $this->assertSame([], $changes['fields']);
        $this->assertNull($changes['body']);
        $this->assertSame([], $changes['attachments_added']);
        $this->assertSame([], $changes['attachments_removed']);
        $this->assertFalse(RequestSnapshot::hasChanges($changes));
    }

    public function test_each_field_is_shown_from_before_to_after(): void
    {
        $after = self::snapshot([
            'type'            => ['id' => 2, 'name' => '契約'],
            'department'      => ['id' => 11, 'name' => '住宅（少額・追加工事）'],
            'subject'         => '社用車の購入（2 台）',
            'amount'          => null,
            'schedule'        => '',
            'related_numbers' => ['R8-J-001'],
        ]);

        $this->assertSame([
            ['label' => '申請の種類', 'before' => '購入・発注', 'after' => '契約'],
            ['label' => '申請部門', 'before' => '住宅事業部', 'after' => '住宅（少額・追加工事）'],
            ['label' => '件名', 'before' => '社用車の購入', 'after' => '社用車の購入（2 台）'],
            ['label' => '金額（税抜）', 'before' => '2,850,000円', 'after' => '（なし）'],
            ['label' => '実施時期', 'before' => '2026年10月', 'after' => '（なし）'],
            ['label' => '関連する決裁No', 'before' => 'R8-J-001・R7-J-015', 'after' => 'R8-J-001'],
        ], RequestSnapshot::changes(self::snapshot(), $after)['fields']);
    }

    public function test_a_renamed_type_or_department_is_not_a_change(): void
    {
        // 管理者があとで名前を変えただけ（id は同じ）なら、申請者は変えていない
        $after = self::snapshot(['type' => ['id' => 1, 'name' => '購入・発注（新）'], 'department' => ['id' => 10, 'name' => '住宅部']]);

        $this->assertSame([], RequestSnapshot::changes(self::snapshot(), $after)['fields']);
    }

    public function test_reordered_related_numbers_are_not_a_change(): void
    {
        $after = self::snapshot(['related_numbers' => ['R7-J-015', 'R8-J-001']]);

        $this->assertSame([], RequestSnapshot::changes(self::snapshot(), $after)['fields']);
    }

    public function test_the_body_is_compared_line_by_line(): void
    {
        $after = self::snapshot(['body' => "■ なぜ（目的・理由）\n・老朽化のため\n・燃費が悪い"]);

        $body = RequestSnapshot::changes(self::snapshot(), $after)['body'];

        $this->assertSame([
            ['type' => LineDiff::SAME, 'line' => '■ なぜ（目的・理由）'],
            ['type' => LineDiff::SAME, 'line' => '・老朽化のため'],
            ['type' => LineDiff::ADDED, 'line' => '・燃費が悪い'],
        ], $body);
    }

    public function test_attachments_are_compared_by_id(): void
    {
        $after = self::snapshot(['attachments' => [['id' => 6, 'name' => '写真.jpg', 'size' => 99], ['id' => 9, 'name' => '見積書（改）.pdf', 'size' => 2000]]]);

        $changes = RequestSnapshot::changes(self::snapshot(), $after);

        $this->assertSame([['id' => 9, 'name' => '見積書（改）.pdf', 'size' => 2000]], $changes['attachments_added']);
        $this->assertSame([['id' => 5, 'name' => '見積書.pdf', 'size' => 1234]], $changes['attachments_removed']);
        $this->assertTrue(RequestSnapshot::hasChanges($changes));
    }

    public function test_the_fingerprint_ignores_key_order_and_names(): void
    {
        // MySQL は JSON のキーを並べ替えて返す（キーの長さの順）。数も文字列で返ることがある
        $fromMysql = [
            'body'            => "■ なぜ（目的・理由）\n・老朽化のため",
            'type'            => ['name' => '購入・発注（新）', 'id' => '1'],
            'amount'          => '2850000',
            'subject'         => '社用車の購入',
            'schedule'        => '2026年10月',
            'department'      => ['name' => '住宅部', 'id' => 10],
            'attachments'     => [['size' => 99, 'name' => '別名.jpg', 'id' => 6], ['size' => 1234, 'name' => '見積書.pdf', 'id' => 5]],
            'related_numbers' => ['R8-J-001', 'R7-J-015'],
        ];

        $this->assertSame(RequestSnapshot::editableFingerprint(self::snapshot()), RequestSnapshot::editableFingerprint($fromMysql));
    }

    public function test_the_fingerprint_changes_with_any_editable_value(): void
    {
        $base = RequestSnapshot::editableFingerprint(self::snapshot());
        $edits = [
            '種類'             => ['type' => ['id' => 2, 'name' => '購入・発注']],
            '申請部門'         => ['department' => ['id' => 11, 'name' => '住宅事業部']],
            '件名'             => ['subject' => '社用車の購入 '],
            '金額'             => ['amount' => 2850001],
            '金額を空に'       => ['amount' => null],
            '実施時期'         => ['schedule' => '2026年11月'],
            '本文'             => ['body' => "■ なぜ（目的・理由）\n・老朽化のため\n"],
            '関連する決裁No の並び' => ['related_numbers' => ['R7-J-015', 'R8-J-001']],
            '添付を足す'       => ['attachments' => [['id' => 5, 'name' => 'a', 'size' => 1], ['id' => 6, 'name' => 'b', 'size' => 1], ['id' => 7, 'name' => 'c', 'size' => 1]]],
            '添付を外す'       => ['attachments' => [['id' => 5, 'name' => 'a', 'size' => 1]]],
        ];

        foreach ($edits as $label => $overrides) {
            $this->assertNotSame($base, RequestSnapshot::editableFingerprint(self::snapshot($overrides)), "{$label}を変えても同じ指紋になった");
        }
    }

    public function test_zero_and_null_amounts_are_different(): void
    {
        // `==` だと null == 0 が同じになる（§5.16）。金額 0 円と空は別
        $this->assertNotSame(
            RequestSnapshot::editableFingerprint(self::snapshot(['amount' => 0])),
            RequestSnapshot::editableFingerprint(self::snapshot(['amount' => null])),
        );
        // 変更点でも、空から 0 円にしたら変わったものとして出す（2b 計画 Task 8 の変異 S03）
        $this->assertSame(
            [['label' => '金額（税抜）', 'before' => '（なし）', 'after' => '0円']],
            RequestSnapshot::changes(self::snapshot(['amount' => null]), self::snapshot(['amount' => 0]))['fields'],
        );
    }
}
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2b && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Unit/Approval/LineDiffTest.php tests/Unit/Approval/RequestSnapshotTest.php
```

Expected: `ERRORS!` `Tests: 18, Assertions: 0, Errors: 18.`

- `LineDiffTest::test_the_same_text_has_only_same_lines`
- `LineDiffTest::test_an_added_line_in_the_middle`
- `LineDiffTest::test_a_removed_line_and_a_changed_line`
- `LineDiffTest::test_repeated_lines_are_matched_by_the_longest_common_subsequence`
- `LineDiffTest::test_empty_texts_have_no_lines`
- `LineDiffTest::test_newlines_are_unified_before_splitting`
- `LineDiffTest::test_a_trailing_newline_is_a_change`
- `LineDiffTest::test_a_huge_middle_falls_back_to_removed_then_added`
- `LineDiffTest::test_twenty_thousand_one_character_lines_do_not_exhaust_memory`
- `RequestSnapshotTest::test_the_same_snapshots_have_no_changes`
- `RequestSnapshotTest::test_each_field_is_shown_from_before_to_after`
- `RequestSnapshotTest::test_a_renamed_type_or_department_is_not_a_change`
- `RequestSnapshotTest::test_reordered_related_numbers_are_not_a_change`
- `RequestSnapshotTest::test_the_body_is_compared_line_by_line`
- `RequestSnapshotTest::test_attachments_are_compared_by_id`
- `RequestSnapshotTest::test_the_fingerprint_ignores_key_order_and_names`
- `RequestSnapshotTest::test_the_fingerprint_changes_with_any_editable_value`
- `RequestSnapshotTest::test_zero_and_null_amounts_are_different`

（`Class "App\Support\Approval\LineDiff" not found`・`Call to undefined method App\Support\Approval\RequestSnapshot::changes()` など）

- [ ] **Step 3: 行ごとの差を書く**

`app/Support/Approval/LineDiff.php`（新規）

```php
<?php

namespace App\Support\Approval;

/**
 * 本文の行ごとの差（出し直した申請の変更点・設計書 §5.13）。
 *
 * `sebastian/diff` は開発用の依存で本番の vendor に無いので、自前の小さな部品にする。
 * 前後の同じ行を除いた残りを、最長共通部分列（LCS）で「同じ・消えた・増えた」に分ける。
 *
 * ⚠ 残りの行数の積が MAX_CELLS を超えたら、LCS を作らず「消えた行 → 増えた行」の順に出す
 *   （本文は 20,000 文字まで。1 文字ずつの行が 1 万行あると表が 1 億マスになり、メモリが尽きるため。
 *   結果は正しい差のまま、最短ではなくなるだけ）。
 */
final class LineDiff
{
    public const SAME    = 'same';
    public const REMOVED = 'removed';
    public const ADDED   = 'added';

    /** LCS の表のマスの上限（前後の同じ行を除いた残りの行数の積） */
    public const MAX_CELLS = 250000;

    /**
     * 2 つの文章の行ごとの差。改行は \r\n・\r を \n にそろえて分ける。空（null・''）は 0 行
     *
     * @return list<array{type: string, line: string}>
     */
    public static function text(?string $before, ?string $after): array
    {
        return self::lines(self::split($before), self::split($after));
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     * @return list<array{type: string, line: string}>
     */
    public static function lines(array $before, array $after): array
    {
        $before = array_values($before);
        $after  = array_values($after);

        // 前の同じ行
        $head = 0;
        $max  = min(count($before), count($after));
        while ($head < $max && $before[$head] === $after[$head]) {
            $head++;
        }

        // 後ろの同じ行（前で使った行とは重ねない）
        $tail = 0;
        while ($tail < $max - $head && $before[count($before) - 1 - $tail] === $after[count($after) - 1 - $tail]) {
            $tail++;
        }

        $middleBefore = array_slice($before, $head, count($before) - $head - $tail);
        $middleAfter  = array_slice($after, $head, count($after) - $head - $tail);

        $out = [];
        foreach (array_slice($before, 0, $head) as $line) {
            $out[] = ['type' => self::SAME, 'line' => $line];
        }
        foreach (self::middle($middleBefore, $middleAfter) as $op) {
            $out[] = $op;
        }
        foreach (array_slice($before, count($before) - $tail) as $line) {
            $out[] = ['type' => self::SAME, 'line' => $line];
        }

        return $out;
    }

    /** 変わった行があるか（同じ行だけなら false） @param list<array{type: string, line: string}> $diff */
    public static function hasChanges(array $diff): bool
    {
        foreach ($diff as $op) {
            if ($op['type'] !== self::SAME) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function split(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        return explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * 前後の同じ行を除いた残りの差
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{type: string, line: string}>
     */
    private static function middle(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 || $m === 0 || $n * $m > self::MAX_CELLS) {
            return array_merge(
                array_map(fn (string $line) => ['type' => self::REMOVED, 'line' => $line], $a),
                array_map(fn (string $line) => ['type' => self::ADDED, 'line' => $line], $b),
            );
        }

        // $lcs[$i][$j] = a[i..] と b[j..] の最長共通部分列の長さ（後ろから埋める）
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        // 前からたどる（同じ長さなら消えた行を先に出す）
        $out = [];
        $i   = 0;
        $j   = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['type' => self::SAME, 'line' => $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['type' => self::REMOVED, 'line' => $a[$i]];
                $i++;
            } else {
                $out[] = ['type' => self::ADDED, 'line' => $b[$j]];
                $j++;
            }
        }
        for (; $i < $n; $i++) {
            $out[] = ['type' => self::REMOVED, 'line' => $a[$i]];
        }
        for (; $j < $m; $j++) {
            $out[] = ['type' => self::ADDED, 'line' => $b[$j]];
        }

        return $out;
    }
}
```

- [ ] **Step 4: 控えの比べ方を足す**

`app/Support/Approval/RequestSnapshot.php`（変更）

```diff
--- a/app/Support/Approval/RequestSnapshot.php
+++ b/app/Support/Approval/RequestSnapshot.php
@@ -6,10 +6,12 @@
 use App\Models\ApprovalRequest;
 
 /**
- * 提出ごとの中身の控え（設計書 §5.11）。2b の変更点と履歴がこれを比べる。
+ * 提出ごとの中身の控え（設計書 §5.11）と、控えどうしの比べ方（2b の変更点と履歴・差戻しの取り消し。§5.13・§5.14）。
  *
  * ⚠ 名前（種類名・部門名・ファイル名）も一緒に控える。あとで種類や部門の名前が変わっても、
  *   その回に出した中身のまま見せるため。段階5 で明細表の行と追加の入力欄を足す。
+ * ⚠ 控えは JSON の列。MySQL は JSON のオブジェクトのキーを並べ替えて返す（キーの長さの順）ので、
+ *   控えを丸ごと `==`・`===` で比べない。決まったキーを取り出して比べる（§5.16）。
  */
 final class RequestSnapshot
 {
@@ -31,4 +33,143 @@ public static function make(ApprovalRequest $request): array
                 ->all(),
         ];
     }
+
+    /**
+     * 直前の回の控えと今の回の控えの違い（出し直した申請の変更点。§5.13）。変わったものだけを返す。
+     *
+     * - 種類・申請部門は id で比べ、名前を出す（管理者があとで名前を変えただけなら変わっていない）
+     * - 関連する決裁No は並びを無視して比べる（並べ替えただけなら変わっていない）
+     * - 本文は行ごとの差（LineDiff）。添付は id で足したもの・外したもの
+     *
+     * @param array<string, mixed> $before
+     * @param array<string, mixed> $after
+     * @return array{
+     *     fields: list<array{label: string, before: string, after: string}>,
+     *     body: list<array{type: string, line: string}>|null,
+     *     attachments_added: list<array{id: int, name: string, size: int}>,
+     *     attachments_removed: list<array{id: int, name: string, size: int}>
+     * }
+     */
+    public static function changes(array $before, array $after): array
+    {
+        $fields = [];
+
+        if (self::idOf($before, 'type') !== self::idOf($after, 'type')) {
+            $fields[] = ['label' => '申請の種類', 'before' => self::text($before['type']['name'] ?? null), 'after' => self::text($after['type']['name'] ?? null)];
+        }
+        if (self::idOf($before, 'department') !== self::idOf($after, 'department')) {
+            $fields[] = ['label' => '申請部門', 'before' => self::text($before['department']['name'] ?? null), 'after' => self::text($after['department']['name'] ?? null)];
+        }
+        if (($before['subject'] ?? null) !== ($after['subject'] ?? null)) {
+            $fields[] = ['label' => '件名', 'before' => self::text($before['subject'] ?? null), 'after' => self::text($after['subject'] ?? null)];
+        }
+        if (self::intOrNull($before['amount'] ?? null) !== self::intOrNull($after['amount'] ?? null)) {
+            $fields[] = ['label' => '金額（税抜）', 'before' => self::amount($before['amount'] ?? null), 'after' => self::amount($after['amount'] ?? null)];
+        }
+        if (($before['schedule'] ?? null) !== ($after['schedule'] ?? null)) {
+            $fields[] = ['label' => '実施時期', 'before' => self::text($before['schedule'] ?? null), 'after' => self::text($after['schedule'] ?? null)];
+        }
+        $numbersBefore = self::numbers($before);
+        $numbersAfter  = self::numbers($after);
+        if (self::sorted($numbersBefore) !== self::sorted($numbersAfter)) {
+            $fields[] = ['label' => '関連する決裁No', 'before' => self::text(implode('・', $numbersBefore)), 'after' => self::text(implode('・', $numbersAfter))];
+        }
+
+        $body = LineDiff::text($before['body'] ?? null, $after['body'] ?? null);
+
+        $attachmentsBefore = self::attachments($before);
+        $attachmentsAfter  = self::attachments($after);
+
+        return [
+            'fields'              => $fields,
+            'body'                => LineDiff::hasChanges($body) ? $body : null,
+            'attachments_added'   => array_values(array_diff_key($attachmentsAfter, $attachmentsBefore)),
+            'attachments_removed' => array_values(array_diff_key($attachmentsBefore, $attachmentsAfter)),
+        ];
+    }
+
+    /** changes() の結果に変わったものがあるか */
+    public static function hasChanges(array $changes): bool
+    {
+        return $changes['fields'] !== [] || $changes['body'] !== null
+            || $changes['attachments_added'] !== [] || $changes['attachments_removed'] !== [];
+    }
+
+    /**
+     * 申請者が直せる中身の指紋（差戻しの取り消し D3 で「差戻しのあと中身か添付を直し始めたか」を比べる。§5.14・§5.16）。
+     *
+     * 名前（種類名・部門名・ファイル名）は入れない（管理者があとで名前を変えても「直した」にしない）。
+     * 決まった並びの配列に詰め直すので、MySQL が JSON のキーを並べ替えて返しても `===` で比べられる。
+     * 関連する決裁No の並べ替えは直したことに数える（申請者が画面で動かしたため）。
+     *
+     * @param array<string, mixed> $snapshot make() の形（控えの JSON を読んだものも同じ）
+     * @return array<string, mixed>
+     */
+    public static function editableFingerprint(array $snapshot): array
+    {
+        $attachmentIds = array_keys(self::attachments($snapshot));
+        sort($attachmentIds);
+
+        return [
+            'type_id'         => self::idOf($snapshot, 'type'),
+            'department_id'   => self::idOf($snapshot, 'department'),
+            'subject'         => self::stringOrNull($snapshot['subject'] ?? null),
+            'amount'          => self::intOrNull($snapshot['amount'] ?? null),
+            'schedule'        => self::stringOrNull($snapshot['schedule'] ?? null),
+            'body'            => self::stringOrNull($snapshot['body'] ?? null),
+            'related_numbers' => self::numbers($snapshot),
+            'attachment_ids'  => $attachmentIds,
+        ];
+    }
+
+    private static function idOf(array $snapshot, string $key): ?int
+    {
+        return self::intOrNull($snapshot[$key]['id'] ?? null);
+    }
+
+    private static function intOrNull(mixed $value): ?int
+    {
+        return $value === null ? null : (int) $value;
+    }
+
+    private static function stringOrNull(mixed $value): ?string
+    {
+        return $value === null ? null : (string) $value;
+    }
+
+    /** @return list<string> */
+    private static function numbers(array $snapshot): array
+    {
+        return array_values(array_map('strval', $snapshot['related_numbers'] ?? []));
+    }
+
+    /** @param list<string> $numbers @return list<string> */
+    private static function sorted(array $numbers): array
+    {
+        sort($numbers);
+
+        return $numbers;
+    }
+
+    /** 添付（id => {id, name, size}） @return array<int, array{id: int, name: string, size: int}> */
+    private static function attachments(array $snapshot): array
+    {
+        $out = [];
+        foreach ($snapshot['attachments'] ?? [] as $attachment) {
+            $id       = (int) $attachment['id'];
+            $out[$id] = ['id' => $id, 'name' => (string) ($attachment['name'] ?? ''), 'size' => (int) ($attachment['size'] ?? 0)];
+        }
+
+        return $out;
+    }
+
+    private static function text(?string $value): string
+    {
+        return ($value === null || $value === '') ? '（なし）' : $value;
+    }
+
+    private static function amount(mixed $value): string
+    {
+        return $value === null ? '（なし）' : number_format((int) $value) . '円';
+    }
 }
```

- [ ] **Step 5: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (18 tests, …)`

- [ ] **Step 6: 全件を流す**

Expected: `OK (2799 tests, 19387 assertions)`

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b add app/Support/Approval/LineDiff.php app/Support/Approval/RequestSnapshot.php tests/Unit/Approval/LineDiffTest.php tests/Unit/Approval/RequestSnapshotTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b commit -m "$(cat <<'MSG'
feat(approval): 控えどうしの変更点を出す LineDiff と RequestSnapshot::changes を足す

本文は行ごとの差（前後の同じ行を除いて LCS。大きすぎれば消えた行→増えた行）、
ほかの項目は前→後、添付は id で足したもの・外したものを出す。差戻しの取り消しの
確かめに使う editableFingerprint も足す（設計書 §5.13・D3）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 3: 詳細の前回からの変更点と提出の履歴（§5.13）

出し直した申請（`round` が 2 以上）の詳細に「前回からの変更点」、すべての提出済みの申請の詳細の最後に「提出の履歴」を出す（§0.3）。どちらも**提出した控え**だけを使うので、差戻し中の直しかけは申請者以外に出ない（D26）。

⚠ **2a のテストを 3 本直す**（弱めるのではなく、見る場所を絞る）: 詳細に前の回の中身（変更点・履歴）が出るようになったので、ページ全体に「1 回目の件名が無い」「外した添付が無い」と見ていた 2a のテストは、見る場所を**申請の中身の部分**（`RequestFormTest`: 「前回からの変更点」より上）と**添付の節**（`RequestAttachmentTest` の `attachmentSection()`）に絞る。あわせて「1 回目の件名は履歴にある」「外した添付は変更点から開ける」を足す。`RequestActionTest` のエスケープの数は、履歴にもコメントと名前が出るので 1 つずつ増える（出る場所が増えただけで、どれもエスケープされている）。

**Files:**
- Create: `resources/views/approvals/requests/_changes.blade.php`・`resources/views/approvals/requests/_history.blade.php`
- Modify: `app/Http/Controllers/Approval/RequestController.php`（`show()` が控えと変更点を渡す）・`resources/views/approvals/requests/show.blade.php`（2 つを差し込む）
- Test: Create `tests/Feature/Approval/Phase2/RequestChangesTest.php`・Modify `RequestActionTest.php`・`RequestAttachmentTest.php`・`RequestFormTest.php`（上の ⚠）

**Interfaces:**
- Consumes: `RequestSnapshot::changes()`・`hasChanges()`・`LineDiff::ADDED`・`REMOVED`（Task 2）・`RequestContent::mayOpen()`（2a。外した添付を開く）
- Produces: 詳細のビューの変数 `$revisions`（`Collection<int, ApprovalRevision>`。提出の回の昇順）・`$changes`（`?array`。`changes()` の形。出し直していなければ `null`）

**差分の大きさ:** 8 ファイル・+369 / −15 行（差分のファイル `0004-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/RequestChangesTest.php`（新規）

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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 出し直した申請の変更点と、提出の回ごとの履歴（詳細の画面③。段階2 設計書 §5.13・2b 計画 Task 3）。
 *
 * ⚠ どちらも提出した控えどうしで作る（差戻し中の直しかけは、出し直すまで申請者だけ。D26）。
 */
class RequestChangesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const AJAX = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function upload(User $user, ApprovalRequest $request, string $name): TestResponse
    {
        return $this->actingAs($user)->post(route('approvals.requests.attachments.store', $request), [
            'file' => UploadedFile::fake()->create($name, 10, 'application/pdf'),
        ], self::AJAX);
    }

    /** 部門長が差し戻す */
    private function returnByHead(array $w, ApprovalRequest $request): ApprovalRequest
    {
        app(Workflow::class)->judgeHead($request->refresh(), $w['head'], $request->lock_version, ApprovalStepResult::Return, '直してください');

        return $request->refresh();
    }

    /** 添付を 1 つ付けて提出し、差し戻されたあと、件名・金額・本文を直して添付を差し替え、出し直した申請 */
    private function resubmittedWithChanges(array $w): ApprovalRequest
    {
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, '見積書.pdf')->assertOk();
        app(Workflow::class)->submit($draft->refresh(), $w['applicant']);
        $request = $this->returnByHead($w, $draft);

        $request->update(['subject' => '社用車の購入（2 台）', 'amount' => 5700000, 'body' => "■ なぜ（目的・理由）\n・老朽化のため\n・台数を増やす"]);
        $this->actingAs($w['applicant'])->delete(route('approvals.attachments.destroy', ApprovalAttachment::where('original_name', '見積書.pdf')->sole()), [], self::AJAX)->assertOk();
        $this->upload($w['applicant'], $request, '見積書（改）.pdf')->assertOk();
        app(Workflow::class)->submit($request->refresh(), $w['applicant']);

        return $request->refresh();
    }

    private function showHtml(User $user, ApprovalRequest $request): string
    {
        return (string) $this->actingAs($user)->get(route('approvals.requests.show', $request))->assertOk()->getContent();
    }

    public function test_a_resubmitted_request_shows_what_changed_from_the_previous_round(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->resubmittedWithChanges($w);

        $html = $this->showHtml($w['head'], $request);

        $this->assertStringContainsString('前回からの変更点', $html);
        $this->assertStringContainsString('（1 回目 → 2 回目の提出）', $html);
        $this->assertStringContainsString('<span class="text-red-700 line-through"><span class="sr-only">前: </span>社用車の購入</span>', $html);
        $this->assertStringContainsString('<span class="font-semibold text-emerald-800"><span class="sr-only">後: </span>社用車の購入（2 台）</span>', $html);
        $this->assertStringContainsString('<span class="sr-only">前: </span>2,850,000円</span>', $html);
        $this->assertStringContainsString('<span class="sr-only">後: </span>5,700,000円</span>', $html);
        $this->assertStringContainsString('<span class="sr-only">増えた行: </span><span class="whitespace-pre-wrap break-words min-w-0">・台数を増やす</span>', $html);
        $added   = ApprovalAttachment::where('original_name', '見積書（改）.pdf')->sole();
        $removed = ApprovalAttachment::where('original_name', '見積書.pdf')->sole();
        $this->assertStringContainsString('<span class="sr-only">足した添付: </span><a href="' . route('approvals.attachments.show', $added) . '"', $html);
        $this->assertStringContainsString('<span class="sr-only">外した添付: </span><a href="' . route('approvals.attachments.show', $removed) . '"', $html);
        // 変わっていない項目（実施時期・種類・申請部門）は出さない
        $section = substr($html, strpos($html, '前回からの変更点'));
        $section = substr($section, 0, strpos($section, '</section>'));
        foreach (['実施時期', '申請の種類', '申請部門', '関連する決裁No'] as $unchanged) {
            $this->assertStringNotContainsString($unchanged, $section, "変わっていない「{$unchanged}」が出た");
        }
    }

    public function test_the_applicant_also_sees_the_changes(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->resubmittedWithChanges($w);

        $this->assertStringContainsString('<span class="sr-only">後: </span>社用車の購入（2 台）</span>', $this->showHtml($w['applicant'], $request));
    }

    public function test_a_first_submission_has_no_changes_section(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $html = $this->showHtml($w['head'], $request);

        $this->assertStringNotContainsString('前回からの変更点', $html);
        $this->assertStringContainsString('1 回目の提出', $html, '履歴は 1 回目から出す');
    }

    public function test_a_resubmission_without_changes_says_nothing_changed(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->returnByHead($w, $this->submittedFor($w));
        app(Workflow::class)->submit($request, $w['applicant']);

        $html = $this->showHtml($w['head'], $request->refresh());

        $this->assertStringContainsString('前回の提出から、中身と添付は変わっていません。', $html);
    }

    public function test_the_history_shows_each_round_with_its_own_content(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->resubmittedWithChanges($w);

        $html = $this->showHtml($w['head'], $request);

        // 新しい回から順に並び、最後に提出した回に印を付ける
        $this->assertSame(1, preg_match('/<summary[^>]*>\s*2 回目の提出.*?（最後に提出した中身）.*?<\/summary>.*?<summary[^>]*>\s*1 回目の提出/su', $html));
        // 1 回目の控えの中身（直す前の件名・外した添付・部門長の差戻しとコメント）
        $first = substr($html, strpos($html, '1 回目の提出'));
        $this->assertStringContainsString('<dd class="text-gray-900 break-words">社用車の購入</dd>', $first);
        $this->assertStringContainsString('>見積書.pdf</a>', $first);
        $this->assertStringContainsString('差戻し', $first);
        $this->assertStringContainsString('直してください', $first);
        // その回の判断は、その回の段階だけ（2 回目の部門長の段階〈待ち〉を 1 回目に混ぜない。2b 計画 Task 8 の変異 C03）
        $firstRound = substr($first, 0, strpos($first, '</details>'));
        $this->assertSame(1, substr_count(substr($firstRound, strpos($firstRound, 'この回の判断')), '>部門長</span>'));
    }

    public function test_a_removed_attachment_can_be_opened_from_the_history_by_others(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->resubmittedWithChanges($w);
        $removed = ApprovalAttachment::where('original_name', '見積書.pdf')->sole();
        $this->assertNotNull($removed->removed_at, '前提: 外した添付');

        $this->actingAs($w['head'])->get(route('approvals.attachments.show', $removed))->assertOk();

        $this->assertSame(1, ApprovalDownloadLog::where('attachment_id', $removed->id)->where('user_id', $w['head']->id)->count(), '開いた記録を残す（§5.7）');
    }

    public function test_the_in_progress_edits_are_not_in_the_changes_or_the_history_for_others(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->returnByHead($w, $this->resubmittedWithChanges($w));
        $request->update(['subject' => '直しかけの件名', 'body' => "■ なぜ（目的・理由）\n・直しかけの本文"]);
        $this->upload($w['applicant'], $request, '直しかけの添付.pdf')->assertOk();

        foreach (['部門長' => $w['head'], '管理者' => $this->approvalAdmin(), '全件閲覧者' => $this->viewAllUser()] as $who => $other) {
            $html = $this->showHtml($other, $request);
            foreach (['直しかけの件名', '直しかけの本文', '直しかけの添付.pdf'] as $secret) {
                $this->assertStringNotContainsString($secret, $html, "{$who}に「{$secret}」が見えた");
            }
            $this->assertStringContainsString('（1 回目 → 2 回目の提出）', $html);
        }
    }
}
```

`tests/Feature/Approval/Phase2/RequestActionTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/RequestActionTest.php
+++ b/tests/Feature/Approval/Phase2/RequestActionTest.php
@@ -643,9 +643,9 @@ public function test_comments_and_names_are_escaped(): void
         $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
         $this->assertStringNotContainsString('<img src=x', $html);
         $this->assertStringNotContainsString('<b>部門</b>', $html);
-        $this->assertSame(2, substr_count($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));   // 回る順番・記録
-        $this->assertSame(3, substr_count($html, '&lt;img src=x onerror=alert(2)&gt;'));     // 条件・回る順番・記録
-        $this->assertSame(2, substr_count($html, '&lt;b&gt;部門&lt;/b&gt;長'));              // 回る順番・記録
+        $this->assertSame(3, substr_count($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));   // 回る順番・記録・提出の履歴（2b）
+        $this->assertSame(4, substr_count($html, '&lt;img src=x onerror=alert(2)&gt;'));     // 条件・回る順番・記録・提出の履歴（2b）
+        $this->assertSame(3, substr_count($html, '&lt;b&gt;部門&lt;/b&gt;長'));              // 回る順番・記録・提出の履歴（2b）
     }
 
     /** 画面の版は 0 以上の整数の形だけを受け取る（配列・'1abc'・'1.0' は、先を越されたものとして断る。前後の空白は TrimStrings が外す。Task 15 の点検の m-1） */
```

`tests/Feature/Approval/Phase2/RequestAttachmentTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/RequestAttachmentTest.php
+++ b/tests/Feature/Approval/Phase2/RequestAttachmentTest.php
@@ -47,6 +47,15 @@ private function pdf(string $name = '見積書.pdf'): UploadedFile
         return UploadedFile::fake()->create($name, 120, 'application/pdf');
     }
 
+    /** 詳細の「添付」の節（今の中身の添付。2b の変更点と提出の履歴には前の回の添付も出るので、節に絞って見る） */
+    private static function attachmentSection(string $html): string
+    {
+        $start = strpos($html, '>添付</h2>');
+        self::assertNotFalse($start, '添付の節が無い');
+
+        return substr($html, $start, strpos($html, '</section>', $start) - $start);
+    }
+
     /** 下書きを提出して、部門長が差し戻す（一度提出した申請にする） */
     private function submitAndReturn(ApprovalRequest $draft, array $w): ApprovalRequest
     {
@@ -321,19 +330,20 @@ public function test_the_detail_lists_the_attachments_of_the_shown_content(): vo
         $this->remove($w['applicant'], $removed)->assertOk();
 
         $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->assertOk()->getContent();
-        $this->assertStringContainsString('見積書.pdf', $html);
-        $this->assertStringNotContainsString('古い図面.pdf', $html);
+        $this->assertStringContainsString('見積書.pdf', self::attachmentSection($html));
+        $this->assertStringNotContainsString('古い図面.pdf', self::attachmentSection($html));
 
         // 部門長には、まだ最後に提出した中身（外したことは出し直すまで申請者だけ）
         $html = $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()->getContent();
-        $this->assertStringContainsString('見積書.pdf', $html);
-        $this->assertStringContainsString('古い図面.pdf', $html);
+        $this->assertStringContainsString('見積書.pdf', self::attachmentSection($html));
+        $this->assertStringContainsString('古い図面.pdf', self::attachmentSection($html));
 
-        // 出し直すと一覧から消えるが、1 回目の控えに入っているので開ける（2b の履歴から開く）
+        // 出し直すと今の添付から消えるが、1 回目の控えに入っているので、変更点（外した添付）と提出の履歴から開ける（2b 計画 Task 3）
         app(Workflow::class)->submit($request->refresh(), $w['applicant']);
         $html = $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()->getContent();
-        $this->assertStringContainsString('見積書.pdf', $html);
-        $this->assertStringNotContainsString('古い図面.pdf', $html);
+        $this->assertStringContainsString('見積書.pdf', self::attachmentSection($html));
+        $this->assertStringNotContainsString('古い図面.pdf', self::attachmentSection($html));
+        $this->assertStringContainsString('<span class="sr-only">外した添付: </span><a href="' . route('approvals.attachments.show', $removed) . '"', $html);
         $this->actingAs($w['head'])->get(route('approvals.attachments.show', $removed))->assertOk();
     }
```

`tests/Feature/Approval/Phase2/RequestFormTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/RequestFormTest.php
+++ b/tests/Feature/Approval/Phase2/RequestFormTest.php
@@ -514,11 +514,15 @@ public function test_others_see_the_latest_round_that_was_submitted(): void
         $this->actingAs($w['applicant'])->post($form['action'], array_merge($form['fields'], ['subject' => '直しかけの件名', 'intent' => 'save']))
             ->assertRedirect(route('approvals.requests.edit', $request));
 
-        $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()
-            ->assertSee('2 回目の件名')
-            ->assertSee('最後に提出した中身（2 回目の提出）')
-            ->assertDontSee('1 回目の件名')
-            ->assertDontSee('直しかけの件名');
+        $html = (string) $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()->getContent();
+        // 件名の見出しと申請の中身は最後に提出した回（2 回目）。1 回目の件名は「前回からの変更点」と「提出の履歴」にだけ出る
+        // （2b 計画 Task 3・設計書 §5.13）
+        $top = substr($html, 0, strpos($html, '前回からの変更点'));
+        $this->assertStringContainsString('2 回目の件名', $top);
+        $this->assertStringNotContainsString('1 回目の件名', $top);
+        $this->assertStringContainsString('最後に提出した中身（2 回目の提出）', $html);
+        $this->assertStringContainsString('1 回目の件名', substr($html, strpos($html, '提出の履歴')), '1 回目の件名は履歴から見られる');
+        $this->assertStringNotContainsString('直しかけの件名', $html);
     }
 
     /** 差戻し中に直して保存してから取り下げても、提出していない中身は申請者だけに残る（要件 4.5・4.8） */
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2b && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase2/RequestChangesTest.php tests/Feature/Approval/Phase2/RequestActionTest.php tests/Feature/Approval/Phase2/RequestAttachmentTest.php tests/Feature/Approval/Phase2/RequestFormTest.php
```

Expected: `FAILURES!` `Tests: 119, Assertions: 1170, Failures: 9.`

- `RequestActionTest::test_comments_and_names_are_escaped`
- `RequestAttachmentTest::test_the_detail_lists_the_attachments_of_the_shown_content`
- `RequestChangesTest::test_a_resubmitted_request_shows_what_changed_from_the_previous_round`
- `RequestChangesTest::test_the_applicant_also_sees_the_changes`
- `RequestChangesTest::test_a_first_submission_has_no_changes_section`
- `RequestChangesTest::test_a_resubmission_without_changes_says_nothing_changed`
- `RequestChangesTest::test_the_history_shows_each_round_with_its_own_content`
- `RequestChangesTest::test_the_in_progress_edits_are_not_in_the_changes_or_the_history_for_others`
- `RequestFormTest::test_others_see_the_latest_round_that_was_submitted`

`RequestChangesTest` の残りの 1 本（外した添付を開ける）は今でも緑（`RequestContent::mayOpen()` が 2a から通している）。

- [ ] **Step 3: 詳細のコントローラが控えと変更点を渡す**

`app/Http/Controllers/Approval/RequestController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/RequestController.php
+++ b/app/Http/Controllers/Approval/RequestController.php
@@ -7,6 +7,7 @@
 use App\Http\Controllers\Controller;
 use App\Models\ApprovalHistory;
 use App\Models\ApprovalRequest;
+use App\Models\ApprovalRevision;
 use App\Models\ApprovalStep;
 use App\Models\ApprovalType;
 use App\Models\User;
@@ -14,6 +15,7 @@
 use App\Support\Approval\RelatedNumbers;
 use App\Support\Approval\RequestContent;
 use App\Support\Approval\RequestPermissions;
+use App\Support\Approval\RequestSnapshot;
 use App\Support\Approval\RequestVisibility;
 use App\Support\Approval\Workflow;
 use App\Support\Approval\WorkflowConflict;
@@ -121,6 +123,9 @@ public function show(Request $request, ApprovalRequest $approvalRequest): View
         $content = RequestContent::for($user, $approvalRequest);
         // 操作の記録（新しい順。§5.12）
         $histories = ApprovalHistory::with('actor')->where('request_id', $approvalRequest->id)->orderByDesc('id')->get();
+        // 提出の回ごとの控え（履歴）と、直前の回からの変更点（出し直した申請。§5.13）。どちらも提出した控えだけを使う
+        // （差戻し中の直しかけは入らない。申請者以外に最後に提出した中身だけを見せる D26 とそろう）
+        $revisions = ApprovalRevision::where('request_id', $approvalRequest->id)->orderBy('round')->get();
 
         return view('approvals.requests.show', [
             'approvalRequest' => $approvalRequest,
@@ -129,6 +134,8 @@ public function show(Request $request, ApprovalRequest $approvalRequest): View
             'relatedLinks'    => $this->relatedLinks($content->relatedNumbers, $user),
             'histories'       => $histories,
             'newHeadNames'    => $this->newHeadNames($histories),
+            'revisions'       => $revisions,
+            'changes'         => $this->changesFromPreviousRound($approvalRequest, $revisions),
         ]);
     }
 
@@ -386,6 +393,22 @@ private function newHeadNames(Collection $histories): array
         return $ids === [] ? [] : User::withTrashed()->whereKey($ids)->pluck('name', 'id')->all();
     }
 
+    /**
+     * 直前の回の控えと今の回の控えの違い（出し直した申請＝今の回が 2 以上のときだけ。設計書 §5.13）
+     *
+     * @param Collection<int, ApprovalRevision> $revisions
+     * @return array<string, mixed>|null
+     */
+    private function changesFromPreviousRound(ApprovalRequest $approvalRequest, Collection $revisions): ?array
+    {
+        $current  = $revisions->firstWhere('round', $approvalRequest->round);
+        $previous = $revisions->firstWhere('round', $approvalRequest->round - 1);
+
+        return ($approvalRequest->round >= 2 && $current !== null && $previous !== null)
+            ? RequestSnapshot::changes($previous->snapshot, $current->snapshot)
+            : null;
+    }
+
     /** 見られない申請は 404（在るかどうかを漏らさない。設計書 §5.10） */
     private function assertVisible(User $user, ApprovalRequest $approvalRequest): void
     {
```

- [ ] **Step 4: 変更点と履歴の部品を書き、詳細に差し込む**（変更点は中身の下・添付の上、履歴は操作の記録の下）

`resources/views/approvals/requests/_changes.blade.php`（新規）

```blade
{{-- 前回からの変更点（出し直した申請。直前の回の控えと今の回の控えを比べる。設計書 §5.13）。
     どちらも提出した控えなので、差戻し中の直しかけは入らない（D26）。色だけに頼らず「＋」「−」と読み上げの言葉を付ける。 --}}
@if($changes !== null)
    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">
            前回からの変更点
            <span class="ml-1 text-[12px] font-normal text-gray-500">（{{ $approvalRequest->round - 1 }} 回目 → {{ $approvalRequest->round }} 回目の提出）</span>
        </h2>
        @if(! \App\Support\Approval\RequestSnapshot::hasChanges($changes))
            <p class="px-5 py-4 text-[13px] text-gray-500">前回の提出から、中身と添付は変わっていません。</p>
        @else
            <div class="px-5 py-4 space-y-4 text-[13px]">
                @if($changes['fields'] !== [])
                    <dl class="grid grid-cols-1 sm:grid-cols-[9em_1fr] gap-x-4 gap-y-2">
                        @foreach($changes['fields'] as $field)
                            <dt class="text-gray-500">{{ $field['label'] }}</dt>
                            <dd class="break-words">
                                <span class="text-red-700 line-through"><span class="sr-only">前: </span>{{ $field['before'] }}</span>
                                <span class="mx-1 text-gray-400" aria-hidden="true">→</span>
                                <span class="font-semibold text-emerald-800"><span class="sr-only">後: </span>{{ $field['after'] }}</span>
                            </dd>
                        @endforeach
                    </dl>
                @endif

                @if($changes['body'] !== null)
                    <div>
                        <p class="text-[12px] font-semibold text-gray-500 mb-1.5">重点ポイント（5W2H）の変わった行</p>
                        <ol class="rounded-md border border-gray-200 overflow-hidden leading-relaxed">
                            @foreach($changes['body'] as $op)
                                @switch($op['type'])
                                    @case(\App\Support\Approval\LineDiff::ADDED)
                                        <li class="flex gap-2 px-3 py-0.5 bg-emerald-50 text-emerald-800"><span class="shrink-0 font-mono" aria-hidden="true">＋</span><span class="sr-only">増えた行: </span><span class="whitespace-pre-wrap break-words min-w-0">{{ $op['line'] }}</span></li>
                                        @break
                                    @case(\App\Support\Approval\LineDiff::REMOVED)
                                        <li class="flex gap-2 px-3 py-0.5 bg-red-50 text-red-700"><span class="shrink-0 font-mono" aria-hidden="true">−</span><span class="sr-only">消えた行: </span><span class="whitespace-pre-wrap break-words min-w-0 line-through">{{ $op['line'] }}</span></li>
                                        @break
                                    @default
                                        <li class="flex gap-2 px-3 py-0.5 text-gray-700"><span class="shrink-0 font-mono text-gray-300" aria-hidden="true">&nbsp;</span><span class="whitespace-pre-wrap break-words min-w-0">{{ $op['line'] }}</span></li>
                                @endswitch
                            @endforeach
                        </ol>
                    </div>
                @endif

                @if($changes['attachments_added'] !== [] || $changes['attachments_removed'] !== [])
                    <div>
                        <p class="text-[12px] font-semibold text-gray-500 mb-1.5">添付</p>
                        <ul class="space-y-1">
                            @foreach($changes['attachments_added'] as $attachment)
                                <li class="text-emerald-800"><span aria-hidden="true">＋ </span><span class="sr-only">足した添付: </span><a href="{{ route('approvals.attachments.show', $attachment['id']) }}" target="_blank" rel="noopener" class="hover:underline break-all">{{ $attachment['name'] }}</a></li>
                            @endforeach
                            @foreach($changes['attachments_removed'] as $attachment)
                                {{-- 外した添付も控えに入っているので開ける（見られる範囲の確認と記録は §5.7 と同じ） --}}
                                <li class="text-red-700"><span aria-hidden="true">− </span><span class="sr-only">外した添付: </span><a href="{{ route('approvals.attachments.show', $attachment['id']) }}" target="_blank" rel="noopener" class="line-through hover:underline break-all">{{ $attachment['name'] }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    </section>
@endif
```

`resources/views/approvals/requests/_history.blade.php`（新規）

```blade
{{-- 提出の履歴（提出の回ごとに、その回の控えの中身・添付と、その回の段階の判断。設計書 §5.13）。
     控えは提出した中身なので、差戻し中の直しかけは入らない（D26）。外した添付もここから開ける（見られる範囲の確認と記録は §5.7 と同じ）。
     ⚠ 保存した日時は JapanTime::format で出す（Bug #61）。金額は「28,500,000円」の形（規約: ¥ を付けない） --}}
@if($revisions->isNotEmpty())
    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">提出の履歴</h2>
        <div class="divide-y divide-gray-100">
            @foreach($revisions->sortByDesc('round') as $revision)
                @php
                    $snapshot   = $revision->snapshot;
                    $roundSteps = $approvalRequest->steps->where('round', $revision->round);
                @endphp
                <details class="px-5 py-3 text-[13px]">
                    <summary class="cursor-pointer font-semibold text-gray-900">
                        {{ $revision->round }} 回目の提出
                        <span class="ml-1 font-normal text-[12px] text-gray-500">{{ \App\Support\JapanTime::format($revision->created_at) }}</span>
                        @if($revision->round === $approvalRequest->round)
                            <span class="ml-1 font-normal text-[12px] text-gray-500">（最後に提出した中身）</span>
                        @endif
                    </summary>
                    <dl class="mt-3 grid grid-cols-1 sm:grid-cols-[9em_1fr] gap-x-4 gap-y-2">
                        <dt class="text-gray-500">申請部門</dt>
                        <dd class="text-gray-900">{{ $snapshot['department']['name'] ?? '—' }}</dd>
                        <dt class="text-gray-500">申請の種類</dt>
                        <dd class="text-gray-900">{{ $snapshot['type']['name'] ?? '—' }}</dd>
                        <dt class="text-gray-500">件名</dt>
                        <dd class="text-gray-900 break-words">{{ $snapshot['subject'] ?? '—' }}</dd>
                        <dt class="text-gray-500">金額（税抜）</dt>
                        <dd class="text-gray-900">{{ isset($snapshot['amount']) ? number_format((int) $snapshot['amount']) . '円' : '—' }}</dd>
                        <dt class="text-gray-500">実施時期</dt>
                        <dd class="text-gray-900 break-words">{{ ($snapshot['schedule'] ?? '') !== '' ? $snapshot['schedule'] : '—' }}</dd>
                        <dt class="text-gray-500">関連する決裁No</dt>
                        <dd class="text-gray-900 font-mono">{{ ($snapshot['related_numbers'] ?? []) === [] ? '—' : implode('・', $snapshot['related_numbers']) }}</dd>
                    </dl>
                    <p class="mt-3 text-[12px] font-semibold text-gray-500 mb-1.5">重点ポイント（5W2H）</p>
                    <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-gray-900 leading-relaxed whitespace-pre-wrap break-words">{{ $snapshot['body'] ?? '' }}</div>
                    <p class="mt-3 text-[12px] font-semibold text-gray-500 mb-1.5">添付</p>
                    <ul class="space-y-1">
                        @forelse($snapshot['attachments'] ?? [] as $attachment)
                            <li><a href="{{ route('approvals.attachments.show', $attachment['id']) }}" target="_blank" rel="noopener" class="text-emerald-600 hover:underline break-all">{{ $attachment['name'] }}</a></li>
                        @empty
                            <li class="text-gray-400">添付はありません。</li>
                        @endforelse
                    </ul>
                    <p class="mt-3 text-[12px] font-semibold text-gray-500 mb-1.5">この回の判断</p>
                    <ul class="space-y-1.5">
                        @foreach($roundSteps as $step)
                            <li>
                                <span class="font-semibold text-gray-900">{{ $step->kind->label() }}</span>
                                <span class="inline-block ml-1 px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $step->status->badgeStyle() }}">{{ $step->status->label() }}</span>
                                @if($step->status === \App\Enums\ApprovalStepStatus::Done)
                                    <span class="ml-1 font-semibold text-gray-900">{{ $step->result->labelFor($step->kind) }}</span>
                                    <span class="ml-1 text-gray-700">{{ $step->actor?->name }}</span>
                                    <span class="ml-1 text-[12px] text-gray-400">{{ \App\Support\JapanTime::format($step->acted_at) }}</span>
                                @elseif($step->status === \App\Enums\ApprovalStepStatus::Skipped)
                                    <span class="ml-1 text-gray-500">申請者が部門長のため省略</span>
                                @endif
                                @if($step->comment)
                                    <p class="mt-1 rounded-md bg-gray-50 px-3 py-2 text-gray-800 whitespace-pre-wrap break-words">{{ $step->comment }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endforeach
        </div>
    </section>
@endif
```

`resources/views/approvals/requests/show.blade.php`（変更）

```diff
--- a/resources/views/approvals/requests/show.blade.php
+++ b/resources/views/approvals/requests/show.blade.php
@@ -79,6 +79,8 @@
         </div>
     </section>
 
+    @include('approvals.requests._changes')
+
     <section class="bg-white rounded-lg border border-gray-200 mb-5">
         <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">添付</h2>
         <ul class="px-5 py-3 space-y-1.5 text-[13px]">
@@ -124,5 +126,7 @@
         </ol>
     </section>
 
+    @include('approvals.requests._history')
+
 </div>
 @endsection
```

- [ ] **Step 5: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (119 tests, …)`

- [ ] **Step 6: 全件を流す**

Expected: `OK (2806 tests, 19463 assertions)`

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b add app/Http/Controllers/Approval/RequestController.php resources/views/approvals/requests/_changes.blade.php resources/views/approvals/requests/_history.blade.php resources/views/approvals/requests/show.blade.php tests/Feature/Approval/Phase2/RequestChangesTest.php tests/Feature/Approval/Phase2/RequestActionTest.php tests/Feature/Approval/Phase2/RequestAttachmentTest.php tests/Feature/Approval/Phase2/RequestFormTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b commit -m "$(cat <<'MSG'
feat(approval): 詳細に前回からの変更点と提出の履歴を出す

出し直した申請は直前の回と今の回の控えを比べて色付きで出し、提出の回ごとに
その回の中身・添付・判断を開けるようにする（設計書 §5.13）。どちらも提出した
控えだけを使うので、差戻し中の直しかけは申請者以外に出ない（D26）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 4: `Workflow` の付け替え・取り消し・代理の取り下げ（§5.14・§5.16）

状態を変える 3 つの操作を `Workflow` に足し、できるかどうか（`RequestPermissions`）・見られる範囲（`RequestVisibility`）・記録の名前（`ApprovalHistory`）をそろえる（§0.4〜§0.7）。画面（Task 5）はこれを呼ぶだけにする。

**Files:**
- Create: `app/Support/Approval/UndoTarget.php`
- Modify: `app/Support/Approval/Workflow.php`・`RequestPermissions.php`・`RequestVisibility.php`・`app/Models/ApprovalHistory.php`
- Test: Create `tests/Feature/Approval/Phase2/WorkflowAdminTest.php`・Modify `tests/Feature/Approval/Phase2/WorkflowTest.php`（`ALLOWED` の表）・`tests/Feature/ClockReadScanTest.php`（`Workflow.php` 7 → 9 件）

**Interfaces:**
- Consumes: `RequestSnapshot::make()`（2a）・`RequestSnapshot::editableFingerprint()`（Task 2）
- Produces: `Workflow::reassignHead(ApprovalRequest $request, User $admin, int $lockVersion, User $to, ?string $reason): void`／`Workflow::undo(ApprovalRequest $request, User $admin, int $lockVersion, ?string $reason): void`／`Workflow::withdrawByAdmin(ApprovalRequest $request, User $admin, int $lockVersion, ?string $reason): void`（断るときは `WorkflowRefused`（`$e->reasons` は文の配列）、先を越されたら `WorkflowConflict`。2a の判断と同じ）
- Produces: `UndoTarget::find(ApprovalRequest $request): ?ApprovalHistory`／`UndoTarget::editedSinceReturn(ApprovalRequest $request): bool`／定数 `UndoTarget::UNDOABLE`・`RETURNS`・`EDITED_SINCE_RETURN`
- Produces: `RequestPermissions::for($user, $request)` の `isAdminOperator(): bool`・`adminRefusal(): ?string`（管理者なのに自分の申請＝D25 の文。管理者でなければ `null`）・`canReassign(): bool`・`undoTarget(): ?ApprovalHistory`（1 回だけ読む）・`canUndo(): bool`・`undoRefusal(): ?string`（D3 の文）・`canWithdrawByAdmin(): bool`
- Produces: 記録の `action` の `reassigned`「部門長の確認を付け替え」・`undone`「押し間違いの取り消し」（`label()` は「押し間違いの取り消し（元の操作の名前）」）・`withdrawn_by_admin`「決裁の管理者が代理で取り下げ」

**差分の大きさ:** 8 ファイル・+887 / −3 行（差分のファイル `0005-…`）

- [ ] **Step 1: 失敗するテストを書く**（Review Focus 4 の `test_undoing_the_head_approval_after_a_head_change_goes_to_the_new_head` を含む）

`tests/Feature/Approval/Phase2/WorkflowAdminTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalAttachment;
use App\Models\ApprovalHistory;
use App\Models\ApprovalMember;
use App\Models\ApprovalNumberSequence;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\Approval\PendingWork;
use App\Support\Approval\RequestVisibility;
use App\Support\Approval\UndoTarget;
use App\Support\Approval\Workflow;
use App\Support\Approval\WorkflowConflict;
use App\Support\Approval\WorkflowRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 決裁の管理者の操作: 部門長の確認の付け替え・押し間違いの取り消し・代理の取り下げ
 * （要件 4.3 のケース 8・4.7・設計書 §5.14・§5.16・D2・D3・D21〜D25・2b 計画 Task 4）。
 */
class WorkflowAdminTest extends TestCase
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

    /** 申請を本物の操作で進める（submit・head・return・review・approve・conditional・reject・preturn・confirm） */
    private function advance(array $w, ApprovalRequest $r, string ...$steps): ApprovalRequest
    {
        foreach ($steps as $step) {
            $r->refresh();
            match ($step) {
                'submit'      => $this->workflow->submit($r, $w['applicant']),
                'head'        => $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null),
                'return'      => $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Return, '直してください'),
                'review'      => $this->workflow->judgeReview($r, $w['reviewer'], $r->lock_version, ApprovalStepResult::Ok, null),
                'approve'     => $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Approve, null),
                'conditional' => $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Conditional, '条件'),
                'reject'      => $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Reject, '見送り'),
                'preturn'     => $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Return, '差し戻します'),
                'confirm'     => $this->workflow->confirmCondition($r, $w['applicant'], $r->lock_version, null),
            };
        }

        return $r->refresh();
    }

    /** @return array<string, string> 今の回の 段階 => 状態 */
    private function stepsOf(ApprovalRequest $request): array
    {
        return ApprovalStep::where('request_id', $request->id)->where('round', $request->round)->orderBy('id')->get()
            ->mapWithKeys(fn (ApprovalStep $s) => [$s->kind->value => $s->status->value])->all();
    }

    private function stepOf(ApprovalRequest $request, string $kind): ApprovalStep
    {
        return ApprovalStep::where('request_id', $request->id)->where('round', $request->round)->where('kind', $kind)->sole();
    }

    /** @param callable(): void $action */
    private function assertRefused(callable $action, string $message, ApprovalRequest $r, string $why): void
    {
        $before = $r->fresh();

        try {
            $action();
            $this->fail("通った: {$why}");
        } catch (WorkflowRefused $e) {
            $this->assertSame($message, $e->getMessage(), $why);
        }

        $after = $r->fresh();
        $this->assertSame($before->status, $after->status, "状態が動いた: {$why}");
        $this->assertSame($before->lock_version, $after->lock_version, "lock_version が動いた: {$why}");
    }

    /** 申請者を決裁の管理者にもする（D25 の場面） */
    private function makeApplicantAdmin(array $w): User
    {
        ApprovalMember::create(['user_id' => $w['applicant']->id, 'is_admin' => true]);

        return $w['applicant']->fresh();
    }

    // ---------------------------------------------------------------- 付け替え

    public function test_reassigning_moves_the_head_step_to_the_new_assignee(): void
    {
        $w       = $this->approvalWorld();
        $admin   = $this->approvalAdmin();
        $deputy  = $this->baseUser(['name' => '代理 部長']);
        $r       = $this->submittedFor($w);
        $version = $r->lock_version;
        $changed = $r->status_changed_at;
        Carbon::setTestNow(Carbon::parse('2026-09-26 02:00:00', 'UTC'));

        $this->workflow->reassignHead($r, $admin, $version, $deputy, '部長が休職のため');

        $r->refresh();
        $this->assertSame(ApprovalStatus::HeadReview, $r->status);
        $this->assertSame($version + 1, $r->lock_version, '版を進める（付け替えの前の画面から押した判断を断る）');
        $this->assertEquals($changed, $r->status_changed_at, '状態が変わった日時は変えない');
        $this->assertSame($deputy->id, $this->stepOf($r, 'head')->assignee_user_id);

        $history = ApprovalHistory::where('action', 'reassigned')->sole();
        $this->assertSame($admin->id, $history->actor_user_id);
        $this->assertSame('部長が休職のため', $history->reason);
        $this->assertSame($this->stepOf($r, 'head')->id, $history->step_id);
        $this->assertEquals(['from_user_id' => $w['head']->id, 'to_user_id' => $deputy->id], $history->meta);

        // 付け替えた担当が判断し、元の部門長はできない
        $this->assertRefused(
            fn () => $this->workflow->judgeHead($r->fresh(), $w['head'], $r->lock_version, ApprovalStepResult::Approve, null),
            'この申請を判断する権限がありません。', $r, '元の部門長が判断できた',
        );
        $this->workflow->judgeHead($r->fresh(), $deputy, $r->lock_version, ApprovalStepResult::Approve, null);
        $this->assertSame(ApprovalStatus::Review, $r->fresh()->status);
    }

    public function test_a_screen_drawn_before_the_reassignment_is_a_conflict(): void
    {
        $w     = $this->approvalWorld();
        $r     = $this->submittedFor($w);
        $stale = $r->lock_version;
        $this->workflow->reassignHead($r, $this->approvalAdmin(), $stale, $this->baseUser(), '休職のため');

        $this->expectException(WorkflowConflict::class);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $stale, ApprovalStepResult::Approve, null);
    }

    public function test_reassignment_refusals(): void
    {
        $w      = $this->approvalWorld();
        $admin  = $this->approvalAdmin();
        $r      = $this->submittedFor($w);
        $deputy = $this->baseUser();
        $inactive = $this->baseUser();
        $inactive->forceFill(['status' => 'inactive'])->save();
        $noMail  = $this->approvalOnlyUser();
        $deleted = $this->baseUser();
        $deleted->delete();
        $deleted = User::withTrashed()->find($deleted->id);
        $wrongPerson = '付け替え先は、有効でメールアドレスのある人から選んでください。';

        $cases = [
            '理由が空'         => [$admin, $deputy, '', '理由を入力してください。'],
            '理由が空白だけ'   => [$admin, $deputy, "\u{3000} \n", '理由を入力してください。'],
            '申請者本人へ'     => [$admin, $w['applicant'], '休職のため', '申請者本人には付け替えられません。'],
            'いまの担当へ'     => [$admin, $w['head'], '休職のため', 'いまの担当と同じ人です。'],
            '無効の人へ'       => [$admin, $inactive, '休職のため', $wrongPerson],
            'メールの無い人へ' => [$admin, $noMail, '休職のため', $wrongPerson],
            '削除した人へ'     => [$admin, $deleted, '休職のため', $wrongPerson],
            '管理者でない人'   => [$w['head'], $deputy, '休職のため', '部門長の確認を待っている申請だけ付け替えられます。'],
        ];
        foreach ($cases as $why => [$actor, $to, $reason, $message]) {
            $this->assertRefused(fn () => $this->workflow->reassignHead($r->fresh(), $actor, $r->fresh()->lock_version, $to, $reason), $message, $r, $why);
        }
        $this->assertSame(0, ApprovalHistory::where('action', 'reassigned')->count());

        // 審査中の申請は付け替えない（部門長の確認だけ。D2）
        $inReview = $this->advance($w, $this->draftFor($w), 'submit', 'head');
        $this->assertRefused(fn () => $this->workflow->reassignHead($inReview, $admin, $inReview->lock_version, $deputy, '休職のため'),
            '部門長の確認を待っている申請だけ付け替えられます。', $inReview, '審査中の申請を付け替えた');
    }

    public function test_an_admin_cannot_reassign_their_own_request(): void
    {
        $w     = $this->approvalWorld();
        $r     = $this->submittedFor($w);
        $admin = $this->makeApplicantAdmin($w);

        $this->assertRefused(fn () => $this->workflow->reassignHead($r, $admin, $r->lock_version, $this->baseUser(), '休職のため'),
            '自分が申請者の申請には、付け替え・取り消し・代理の取り下げはできません。', $r, '自分の申請を付け替えた（D25）');
    }

    /** 付け替えた申請も、部門の設定で部門長を変えると新しい部門長へ移る（D23） */
    public function test_a_head_change_after_the_reassignment_moves_it_to_the_new_head(): void
    {
        $w       = $this->approvalWorld();
        $admin   = $this->approvalAdmin();
        $deputy  = $this->baseUser();
        $newHead = $this->baseUser();
        $r       = $this->submittedFor($w);
        $this->workflow->reassignHead($r, $admin, $r->lock_version, $deputy, '休職のため');

        $this->workflow->headChanged($w['dept'], $w['head']->id, $newHead->id, $admin);
        $w['dept']->update(['head_user_id' => $newHead->id]);

        $this->assertNull($this->stepOf($r, 'head')->assignee_user_id);
        $this->workflow->judgeHead($r->fresh(), $newHead, $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->assertSame(ApprovalStatus::Review, $r->fresh()->status);
    }

    public function test_the_pending_work_follows_the_reassignment(): void
    {
        $w      = $this->approvalWorld();
        $deputy = $this->baseUser();
        $r      = $this->submittedFor($w);

        $this->workflow->reassignHead($r, $this->approvalAdmin(), $r->lock_version, $deputy, '休職のため');

        $this->assertSame([$r->id], PendingWork::for($deputy)->map(fn (array $item) => $item['request']->id)->all());
        $this->assertSame([], PendingWork::for($w['head'])->all());
    }

    // ---------------------------------------------------------------- 取り消し

    /** 取り消す操作ごとに、戻る状態と今の回の段階（部門長・審査・社長） */
    public static function undoCases(): array
    {
        return [
            '部門長の承認'   => [['submit', 'head'], 'head_approved', ApprovalStatus::HeadReview, ['head' => 'waiting', 'review' => 'pending', 'president' => 'pending']],
            '部門長の差戻し' => [['submit', 'return'], 'head_returned', ApprovalStatus::HeadReview, ['head' => 'waiting', 'review' => 'pending', 'president' => 'pending']],
            '審査の意見'     => [['submit', 'head', 'review'], 'reviewed', ApprovalStatus::Review, ['head' => 'done', 'review' => 'waiting', 'president' => 'pending']],
            '社長の差戻し'   => [['submit', 'head', 'review', 'preturn'], 'president_returned', ApprovalStatus::President, ['head' => 'done', 'review' => 'done', 'president' => 'waiting']],
            '社長の可'       => [['submit', 'head', 'review', 'approve'], 'president_approved', ApprovalStatus::President, ['head' => 'done', 'review' => 'done', 'president' => 'waiting']],
            '社長の条可'     => [['submit', 'head', 'review', 'conditional'], 'president_conditional', ApprovalStatus::President, ['head' => 'done', 'review' => 'done', 'president' => 'waiting']],
            '社長の否'       => [['submit', 'head', 'review', 'reject'], 'president_rejected', ApprovalStatus::President, ['head' => 'done', 'review' => 'done', 'president' => 'waiting']],
            '条件の確認'     => [['submit', 'head', 'review', 'conditional', 'confirm'], 'condition_confirmed', ApprovalStatus::Condition, ['head' => 'done', 'review' => 'done', 'president' => 'done']],
        ];
    }

    #[DataProvider('undoCases')]
    public function test_undo_restores_the_state_before_the_operation(array $path, string $action, ApprovalStatus $to, array $steps): void
    {
        $w       = $this->approvalWorld();
        $admin   = $this->approvalAdmin();
        $r       = $this->advance($w, $this->draftFor($w), ...$path);
        $target  = ApprovalHistory::where('request_id', $r->id)->orderByDesc('id')->first();
        $this->assertSame($action, $target->action, '前提: 最後の操作');
        $arrived = ApprovalStep::where('request_id', $r->id)->pluck('arrived_at', 'kind')->all();
        $number  = $r->number;
        $version = $r->lock_version;

        $this->workflow->undo($r, $admin, $version, '押し間違い');

        $r->refresh();
        $this->assertSame($to, $r->status);
        $this->assertSame($version + 1, $r->lock_version);
        $this->assertSame($steps, $this->stepsOf($r));
        $this->assertSame($number, $r->number, '番号は残す（6.5・D21）');
        $this->assertEquals($arrived, ApprovalStep::where('request_id', $r->id)->pluck('arrived_at', 'kind')->all(), '届いた日時は空にしない（§5.16）');

        if ($action !== 'condition_confirmed') {
            $reopened = ApprovalStep::find($target->step_id);
            $this->assertSame('waiting', $reopened->status->value);
            $this->assertNull($reopened->actor_user_id);
            $this->assertNull($reopened->result);
            $this->assertNull($reopened->comment);
            $this->assertNull($reopened->acted_at);
        }
        if ($to === ApprovalStatus::President) {
            $this->assertNull($r->decision, '社長の判断を空に戻す');
            $this->assertNull($r->decided_at);
            $this->assertNull($r->finished_at);
        }
        if ($action === 'condition_confirmed') {
            $this->assertSame(ApprovalDecision::Conditional, $r->decision, '条件確認の取り消しは条可のまま');
            $this->assertNull($r->finished_at);
        }

        $undone = ApprovalHistory::where('action', 'undone')->sole();
        $this->assertSame($admin->id, $undone->actor_user_id);
        $this->assertSame('押し間違い', $undone->reason);
        $this->assertSame($to->value, $undone->to_status);
        $this->assertEquals(['undone_history_id' => $target->id, 'undone_action' => $action], $undone->meta);
        $this->assertSame(1, ApprovalHistory::where('id', $target->id)->count(), '元の記録は消さない');
    }

    /** 続けて取り消せば 1 つずつさかのぼり、提出の手前で止まる（D24） */
    public function test_undo_walks_back_one_operation_at_a_time_to_the_submission(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $r     = $this->advance($w, $this->draftFor($w), 'submit', 'head', 'review', 'approve');

        foreach ([ApprovalStatus::President, ApprovalStatus::Review, ApprovalStatus::HeadReview] as $expected) {
            $this->workflow->undo($r->fresh(), $admin, $r->fresh()->lock_version, '押し間違い');
            $this->assertSame($expected, $r->fresh()->status);
        }

        $this->assertRefused(fn () => $this->workflow->undo($r->fresh(), $admin, $r->fresh()->lock_version, '押し間違い'),
            '取り消せる操作がありません（提出の手前まで戻っています）。', $r, '提出を取り消した');

        // 取り消したあと判断し直せば、その判断をまた取り消せる
        $this->advance($w, $r, 'head');
        $this->workflow->undo($r->fresh(), $admin, $r->fresh()->lock_version, 'もう一度押し間違い');
        $this->assertSame(ApprovalStatus::HeadReview, $r->fresh()->status);
    }

    /** 部門長の省略・部門長の交代・付け替えは、取り消す操作を探すときに飛ばす（§5.16） */
    public function test_undo_skips_the_skip_the_head_change_and_the_reassignment(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();

        // 申請者が部門長（部門長の確認を省略）→ 審査の意見 → 取り消し → 審査中。もう 1 回は提出の手前
        $w['head']->approvalDepartments()->attach($w['dept']->id);
        $own = $this->draftFor($w, ['user_id' => $w['head']->id]);
        $this->workflow->submit($own, $w['head']);
        $own->refresh();
        $this->workflow->judgeReview($own, $w['reviewer'], $own->lock_version, ApprovalStepResult::Ok, null);
        $this->workflow->undo($own->fresh(), $admin, $own->fresh()->lock_version, '押し間違い');
        $this->assertSame(ApprovalStatus::Review, $own->fresh()->status);
        $this->assertNull(UndoTarget::find($own->fresh()), '部門長の省略を取り消し対象にした');

        // 付け替え → 付け替えた担当の承認 → 取り消し → 部門長確認中（担当はそのまま）。もう 1 回は提出の手前
        $deputy = $this->baseUser();
        $r      = $this->submittedFor($w);
        $this->workflow->reassignHead($r, $admin, $r->lock_version, $deputy, '休職のため');
        $this->workflow->judgeHead($r->fresh(), $deputy, $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->undo($r->fresh(), $admin, $r->fresh()->lock_version, '押し間違い');
        $this->assertSame(ApprovalStatus::HeadReview, $r->fresh()->status);
        $this->assertSame($deputy->id, $this->stepOf($r->fresh(), 'head')->assignee_user_id, '付け替えた担当はそのまま');
        $this->assertNull(UndoTarget::find($r->fresh()), '付け替えを取り消し対象にした');

        // 部門長の交代 → 新しい部門長の承認 → 取り消し → 部門長確認中。もう 1 回は提出の手前
        $newHead = $this->baseUser();
        $moved   = $this->submittedFor($w);
        $this->workflow->headChanged($w['dept'], $w['head']->id, $newHead->id, $admin);
        $w['dept']->update(['head_user_id' => $newHead->id]);
        $this->workflow->judgeHead($moved->fresh(), $newHead, $moved->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->undo($moved->fresh(), $admin, $moved->fresh()->lock_version, '押し間違い');
        $this->assertSame(ApprovalStatus::HeadReview, $moved->fresh()->status);
        $this->assertNull(UndoTarget::find($moved->fresh()), '部門長の交代を取り消し対象にした');
    }

    /** 差戻しの取り消しは、差戻しのあと申請者が中身か添付を変えていたら断る（D3） */
    public function test_undoing_a_return_is_refused_once_the_applicant_edits(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();

        $edits = [
            '件名を直した（保存で版が進む）' => function (ApprovalRequest $r): void {
                ApprovalRequest::whereKey($r->id)->update(['subject' => '直した件名', 'lock_version' => DB::raw('lock_version + 1')]);
            },
            '添付を足した（版は進まない）' => function (ApprovalRequest $r) use ($w): void {
                ApprovalAttachment::create(['request_id' => $r->id, 'original_name' => '追加.pdf', 'stored_path' => "approvals/{$r->id}/x.pdf",
                    'mime' => 'application/pdf', 'size' => 10, 'uploaded_by' => $w['applicant']->id, 'added_round' => $r->round + 1]);
            },
            '社長の差戻しのあと本文を直した' => function (ApprovalRequest $r): void {
                ApprovalRequest::whereKey($r->id)->update(['body' => "■ なぜ（目的・理由）\n・直した", 'lock_version' => DB::raw('lock_version + 1')]);
            },
        ];

        foreach ($edits as $why => $edit) {
            $path = str_starts_with($why, '社長') ? ['submit', 'head', 'review', 'preturn'] : ['submit', 'return'];
            $r    = $this->advance($w, $this->draftFor($w), ...$path);
            $edit($r);
            $this->assertRefused(fn () => $this->workflow->undo($r->fresh(), $admin, $r->fresh()->lock_version, '押し間違い'),
                UndoTarget::EDITED_SINCE_RETURN, $r, $why);
        }

        // 同じ中身のまま保存し直しただけ（版は進む）なら、今の版で取り消せる
        $same = $this->advance($w, $this->draftFor($w), 'submit', 'return');
        ApprovalRequest::whereKey($same->id)->update(['lock_version' => DB::raw('lock_version + 1')]);
        $this->workflow->undo($same->fresh(), $admin, $same->fresh()->lock_version, '押し間違い');
        $this->assertSame(ApprovalStatus::HeadReview, $same->fresh()->status);
    }

    /** 取り消しで残った番号は、年度をまたいで判断し直しても同じ番号（6.5・D21） */
    public function test_the_number_stays_through_the_undo_and_is_reused_in_the_next_fiscal_year(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $r     = $this->advance($w, $this->draftFor($w), 'submit', 'head', 'review', 'approve');
        $this->assertSame('R8-J-001', $r->number);

        $this->workflow->undo($r, $admin, $r->lock_version, '押し間違い');
        Carbon::setTestNow(Carbon::parse('2027-05-02 01:00:00', 'UTC'));   // ミツワの期で R9
        $this->advance($w, $r, 'reject');

        $r->refresh();
        $this->assertSame('R8-J-001', $r->number);
        $this->assertSame(ApprovalDecision::Reject, $r->decision);
        $this->assertSame(1, (int) ApprovalNumberSequence::sum('last_issued'), '新しい番号を採っていない');
    }

    /** 判断を取り消された人は、担当を外れていても見続けられる（記録の判断した人。2b 計画 §0.5） */
    public function test_someone_whose_judgement_was_undone_can_still_see_the_request(): void
    {
        $w        = $this->approvalWorld();
        $admin    = $this->approvalAdmin();
        $outsider = $this->baseUser();
        $r        = $this->advance($w, $this->draftFor($w), 'submit', 'head');
        $w['dept']->update(['head_user_id' => $this->baseUser()->id]);   // 部門長が交代した（前の部門長は担当を外れた）

        $this->workflow->undo($r, $admin, $r->lock_version, '押し間違い');

        $this->assertNull($this->stepOf($r->fresh(), 'head')->actor_user_id, '前提: 段階の判断した人は空に戻った');
        $this->assertTrue(RequestVisibility::canView($w['head'], $r->fresh()));
        $this->assertSame([$r->id], ApprovalRequest::query()->visibleTo($w['head'])->pluck('id')->all(), '一覧の絞り込みも同じ');
        $this->assertFalse(RequestVisibility::canView($outsider, $r->fresh()));
    }

    /** 部門長が交代したあとに部門長の承認を取り消すと、戻した段階は今の部門長の対応待ちに入る（担当は部門の設定から読む。D23。Review Focus 4） */
    public function test_undoing_the_head_approval_after_a_head_change_goes_to_the_new_head(): void
    {
        $w       = $this->approvalWorld();
        $admin   = $this->approvalAdmin();
        $newHead = $this->baseUser();
        $r       = $this->advance($w, $this->draftFor($w), 'submit', 'head');
        $w['dept']->update(['head_user_id' => $newHead->id]);   // 承認のあとで部門長が交代した
        $ids = fn (User $user) => PendingWork::for($user)->map(fn (array $item) => $item['request']->id)->all();

        $this->workflow->undo($r, $admin, $r->lock_version, '押し間違い');

        $this->assertSame([$r->id], $ids($newHead));
        $this->assertSame([], $ids($w['head']), '前の部門長の対応待ちに戻した');
    }

    public function test_undo_refusals(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $none  = '取り消せる操作がありません（提出の手前まで戻っています）。';

        $submitted = $this->submittedFor($w);
        $this->assertRefused(fn () => $this->workflow->undo($submitted, $admin, $submitted->lock_version, '押し間違い'), $none, $submitted, '提出を取り消した');

        $withdrawn = $this->advance($w, $this->draftFor($w), 'submit', 'head');
        $this->workflow->withdraw($withdrawn, $w['applicant'], $withdrawn->lock_version, null);
        $this->assertRefused(fn () => $this->workflow->undo($withdrawn->fresh(), $admin, $withdrawn->fresh()->lock_version, '押し間違い'), $none, $withdrawn, '取り下げを取り消した');

        $r = $this->advance($w, $this->draftFor($w), 'submit', 'head');
        $this->assertRefused(fn () => $this->workflow->undo($r, $admin, $r->lock_version, ' '), '理由を入力してください。', $r, '理由なしで取り消した');
        $this->assertRefused(fn () => $this->workflow->undo($r, $w['head'], $r->lock_version, '押し間違い'), $none, $r, '管理者でない人が取り消した');

        $own = $this->makeApplicantAdmin($w);
        $this->assertRefused(fn () => $this->workflow->undo($r, $own, $r->lock_version, '押し間違い'),
            '自分が申請者の申請には、付け替え・取り消し・代理の取り下げはできません。', $r, '自分の申請を取り消した（D25）');

        $this->expectException(WorkflowConflict::class);
        $this->workflow->undo($r, $admin, $r->lock_version - 1, '押し間違い');
    }

    /** 取り消しで待ちに戻した段階は、その担当の対応待ちに戻る（今の回だけ。§5.16） */
    public function test_the_pending_work_follows_the_undo(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $r     = $this->advance($w, $this->draftFor($w), 'submit', 'head', 'review');
        $ids   = fn (User $user) => PendingWork::for($user)->map(fn (array $item) => $item['request']->id)->all();
        $this->assertSame([$r->id], $ids($w['president']), '前提');

        $this->workflow->undo($r, $admin, $r->lock_version, '押し間違い');
        $this->assertSame([$r->id], $ids($w['reviewer']));
        $this->assertSame([], $ids($w['president']));

        $this->workflow->undo($r->fresh(), $admin, $r->fresh()->lock_version, '押し間違い');
        $this->assertSame([$r->id], $ids($w['head']));
        $this->assertSame([], $ids($w['reviewer']));
    }

    // ---------------------------------------------------------------- 代理の取り下げ

    public function test_an_admin_withdraws_for_the_applicant(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $r     = $this->advance($w, $this->draftFor($w), 'submit', 'head');

        $this->workflow->withdrawByAdmin($r, $admin, $r->lock_version, '退職のため');

        $r->refresh();
        $this->assertSame(ApprovalStatus::Withdrawn, $r->status);
        $this->assertSame(['head' => 'done', 'review' => 'cancelled', 'president' => 'cancelled'], $this->stepsOf($r));
        $history = ApprovalHistory::where('action', 'withdrawn_by_admin')->sole();
        $this->assertSame($admin->id, $history->actor_user_id);
        $this->assertSame('退職のため', $history->reason);
        $this->assertSame('review', $history->from_status);
        $this->assertNull($history->comment);
        $this->assertSame([], PendingWork::for($w['reviewer'])->all());
    }

    public function test_withdrawal_by_an_admin_refusals(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $r     = $this->submittedFor($w);

        $this->assertRefused(fn () => $this->workflow->withdrawByAdmin($r, $admin, $r->lock_version, ''), '理由を入力してください。', $r, '理由なしで取り下げた');
        $own = $this->makeApplicantAdmin($w);
        $this->assertRefused(fn () => $this->workflow->withdrawByAdmin($r, $own, $r->lock_version, '退職のため'),
            '自分が申請者の申請には、付け替え・取り消し・代理の取り下げはできません。', $r, '自分の申請を代理で取り下げた（D25）');

        $approved = $this->advance($w, $this->draftFor($w), 'submit', 'head', 'review', 'approve');
        $this->assertRefused(fn () => $this->workflow->withdrawByAdmin($approved, $admin, $approved->lock_version, '退職のため'),
            'この申請は取り下げられる状態ではありません。', $approved, '決裁済みを取り下げた');

        $this->expectException(WorkflowConflict::class);
        $this->workflow->withdrawByAdmin($r, $admin, $r->lock_version + 1, '退職のため');
    }
}
```

`tests/Feature/Approval/Phase2/WorkflowTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/WorkflowTest.php
+++ b/tests/Feature/Approval/Phase2/WorkflowTest.php
@@ -631,6 +631,10 @@ public function test_a_submission_after_another_save_is_refused(): void
         'judgePresident'   => [ApprovalStatus::President],
         'confirmCondition' => [ApprovalStatus::Condition],
         'withdraw'         => [ApprovalStatus::HeadReview, ApprovalStatus::Review, ApprovalStatus::President, ApprovalStatus::Returned],
+        // 決裁の管理者の操作（2b・設計書 §5.14）。取り消しは「今の回に取り消せる判断がある」状態だけ（部門長確認中は提出の直後）
+        'reassignHead'     => [ApprovalStatus::HeadReview],
+        'undo'             => [ApprovalStatus::Review, ApprovalStatus::President, ApprovalStatus::Returned, ApprovalStatus::Condition, ApprovalStatus::Approved, ApprovalStatus::Rejected],
+        'withdrawByAdmin'  => [ApprovalStatus::HeadReview, ApprovalStatus::Review, ApprovalStatus::President, ApprovalStatus::Returned],
     ];
 
     /** その状態の申請を、本物の操作を順にたどって作る（状態を直接書き込まない） */
@@ -669,10 +673,11 @@ private function requestIn(ApprovalStatus $status, array $w): ApprovalRequest
         return $r;
     }
 
-    /** 表のすべての組み合わせ（9 状態 × 6 操作）で、通るものは通り、それ以外は WorkflowRefused で断る */
+    /** 表のすべての組み合わせ（9 状態 × 9 操作）で、通るものは通り、それ以外は WorkflowRefused で断る */
     public function test_every_state_accepts_only_the_operations_in_the_table(): void
     {
         $w        = $this->approvalWorld();
+        $admin    = $this->approvalAdmin();
         $problems = [];
 
         foreach (ApprovalStatus::cases() as $status) {
@@ -688,6 +693,9 @@ public function test_every_state_accepts_only_the_operations_in_the_table(): voi
                         'judgePresident'   => $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Approve, null),
                         'confirmCondition' => $this->workflow->confirmCondition($r, $w['applicant'], $r->lock_version, null),
                         'withdraw'         => $this->workflow->withdraw($r, $w['applicant'], $r->lock_version, null),
+                        'reassignHead'     => $this->workflow->reassignHead($r, $admin, $r->lock_version, $w['reviewer'], '休職のため'),
+                        'undo'             => $this->workflow->undo($r, $admin, $r->lock_version, '押し間違い'),
+                        'withdrawByAdmin'  => $this->workflow->withdrawByAdmin($r, $admin, $r->lock_version, '退職のため'),
                     };
                     if (! $allowed) {
                         $problems[] = "{$status->value} で {$operation} が通った（表では断る）";
```

`tests/Feature/ClockReadScanTest.php`（変更）

```diff
--- a/tests/Feature/ClockReadScanTest.php
+++ b/tests/Feature/ClockReadScanTest.php
@@ -54,7 +54,7 @@ class ClockReadScanTest extends TestCase
         'app/Support/Approval/PasswordReissuer.php'           => [1, '再発行の瞬間（通知メールへ渡し、PasswordReissuedMail が JapanTime で日本時間に直して出す）'],
         'app/Support/OneTimeAction.php'                       => [1, '期限: 1 回限りの鍵のキャッシュの有効期限（瞬間でよい）'],
         'app/Support/Approval/ApprovalNumber.php'             => [1, '連番の行を作った瞬間（created_at・updated_at は TIMESTAMP 列。upsert は Eloquent を通らないので手で入れる）'],
-        'app/Support/Approval/Workflow.php'                   => [7, '提出・届いた・判断・完了・状態が変わった瞬間と updated_at（すべて TIMESTAMP 列。画面では JapanTime で日本時間に直して出す）'],
+        'app/Support/Approval/Workflow.php'                   => [9, '提出・届いた・判断・完了・状態が変わった瞬間と updated_at（付け替えの版の繰り上げ・取り消しで段階を戻したときを含む。すべて TIMESTAMP 列。画面では JapanTime で日本時間に直して出す）'],
         'app/Http/Controllers/Approval/RequestAttachmentController.php' => [1, 'approval_attachments.removed_at は TIMESTAMP 列（外した瞬間を UTC で保存する）'],
     ];
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2b && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase2/WorkflowAdminTest.php tests/Feature/Approval/Phase2/WorkflowTest.php tests/Feature/ClockReadScanTest.php
```

Expected: `ERRORS!` `Tests: 79, Assertions: 256, Errors: 25, Failures: 1.`

- `WorkflowAdminTest::test_reassigning_moves_the_head_step_to_the_new_assignee`
- `WorkflowAdminTest::test_a_screen_drawn_before_the_reassignment_is_a_conflict`
- `WorkflowAdminTest::test_reassignment_refusals`
- `WorkflowAdminTest::test_an_admin_cannot_reassign_their_own_request`
- `WorkflowAdminTest::test_a_head_change_after_the_reassignment_moves_it_to_the_new_head`
- `WorkflowAdminTest::test_the_pending_work_follows_the_reassignment`
- `WorkflowAdminTest::test_undo_restores_the_state_before_the_operation`（with data set "部門長の承認" (['submit', 'head'], 'head_approved', App\Enums\ApprovalStatus Enum (HeadReview, 'head_review'), ['waiting', 'pending', 'pending'])）
- `WorkflowAdminTest::test_undo_restores_the_state_before_the_operation`（with data set "部門長の差戻し" (['submit', 'return'], 'head_returned', App\Enums\ApprovalStatus Enum (HeadReview, 'head_review'), ['waiting', 'pending', 'pending'])）
- `WorkflowAdminTest::test_undo_restores_the_state_before_the_operation`（with data set "審査の意見" (['submit', 'head', 'review'], 'reviewed', App\Enums\ApprovalStatus Enum (Review, 'review'), ['done', 'waiting', 'pending'])）
- `WorkflowAdminTest::test_undo_restores_the_state_before_the_operation`（with data set "社長の差戻し" (['submit', 'head', 'review', 'preturn'], 'president_returned', App\Enums\ApprovalStatus Enum (President, 'president'), ['done', 'done', 'waiting'])）
- `WorkflowAdminTest::test_undo_restores_the_state_before_the_operation`（with data set "社長の可" (['submit', 'head', 'review', 'approve'], 'president_approved', App\Enums\ApprovalStatus Enum (President, 'president'), ['done', 'done', 'waiting'])）
- `WorkflowAdminTest::test_undo_restores_the_state_before_the_operation`（with data set "社長の条可" (['submit', 'head', 'review', 'conditional'], 'president_conditional', App\Enums\ApprovalStatus Enum (President, 'president'), ['done', 'done', 'waiting'])）
- `WorkflowAdminTest::test_undo_restores_the_state_before_the_operation`（with data set "社長の否" (['submit', 'head', 'review', 'reject'], 'president_rejected', App\Enums\ApprovalStatus Enum (President, 'president'), ['done', 'done', 'waiting'])）
- `WorkflowAdminTest::test_undo_restores_the_state_before_the_operation`（with data set "条件の確認" (['submit', 'head', 'review', 'conditional', 'confirm'], 'condition_confirmed', App\Enums\ApprovalStatus Enum (Condition, 'condition'), ['done', 'done', 'done'])）
- `WorkflowAdminTest::test_undo_walks_back_one_operation_at_a_time_to_the_submission`
- `WorkflowAdminTest::test_undo_skips_the_skip_the_head_change_and_the_reassignment`
- `WorkflowAdminTest::test_undoing_a_return_is_refused_once_the_applicant_edits`
- `WorkflowAdminTest::test_the_number_stays_through_the_undo_and_is_reused_in_the_next_fiscal_year`
- `WorkflowAdminTest::test_someone_whose_judgement_was_undone_can_still_see_the_request`
- `WorkflowAdminTest::test_undoing_the_head_approval_after_a_head_change_goes_to_the_new_head`
- `WorkflowAdminTest::test_undo_refusals`
- `WorkflowAdminTest::test_the_pending_work_follows_the_undo`
- `WorkflowAdminTest::test_an_admin_withdraws_for_the_applicant`
- `WorkflowAdminTest::test_withdrawal_by_an_admin_refusals`
- `WorkflowTest::test_every_state_accepts_only_the_operations_in_the_table`
- `ClockReadScanTest::test_php_clock_reads_are_classified`

（`WorkflowAdminTest` と `WorkflowTest` の表は `Call to undefined method App\Support\Approval\Workflow::reassignHead()` など、`ClockReadScanTest` は `Workflow.php` の件数が 7 のまま）

- [ ] **Step 3: 記録の名前を足す**

`app/Models/ApprovalHistory.php`（変更）

```diff
--- a/app/Models/ApprovalHistory.php
+++ b/app/Models/ApprovalHistory.php
@@ -34,6 +34,9 @@ class ApprovalHistory extends Model
         'condition_confirmed' => '条件を確認',
         'withdrawn'           => '取り下げ',
         'head_changed'        => '部門長の交代で担当が移った',
+        'reassigned'          => '部門長の確認を付け替え',
+        'undone'              => '押し間違いの取り消し',
+        'withdrawn_by_admin'  => '決裁の管理者が代理で取り下げ',
     ];
 
     protected $fillable = [
@@ -60,6 +63,11 @@ public function label(): string
             $label .= '（' . ApprovalStepResult::from($this->result)->labelFor(ApprovalStepKind::Review) . '）';
         }
 
+        // 取り消しは、取り消した操作の名前を添える（2b・設計書 §5.14）
+        if ($this->action === 'undone' && isset(self::LABELS[$this->meta['undone_action'] ?? ''])) {
+            $label .= '（' . self::LABELS[$this->meta['undone_action']] . '）';
+        }
+
         return $label;
     }
 }
```

- [ ] **Step 4: 次に取り消す操作と D3 の確かめを書く**

`app/Support/Approval/UndoTarget.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Models\ApprovalHistory;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;

/**
 * 押し間違いの取り消しで、次に取り消す操作（要件 4.7・設計書 §5.14・D24）。
 *
 * 今の回の記録を新しい順に見て、人の判断でないもの（部門長の省略・部門長の交代）・管理者の操作（付け替え・取り消し）と、
 * すでに取り消した記録を飛ばし、最初に当たったものが取り消せる操作ならそれを返す。提出・出し直し・取り下げに当たったら
 * 無し（提出の手前まで。取り下げは取り消せない）。続けて取り消せば 1 つずつさかのぼる（D24）。
 *
 * ⚠ 元の記録は消さない（記録は追記のみ）。取り消した記録（undone）の meta.undone_history_id で「取り消し済み」を見分ける。
 */
final class UndoTarget
{
    /** 取り消せる操作（判断と条件確認。提出・取り下げ・部門長の省略は対象外。§5.14） */
    public const UNDOABLE = [
        'head_approved', 'head_returned', 'reviewed',
        'president_approved', 'president_conditional', 'president_rejected', 'president_returned',
        'condition_confirmed',
    ];

    /** 差戻し（取り消すときに D3 を確かめる） */
    public const RETURNS = ['head_returned', 'president_returned'];

    /** 探すときに飛ばす記録（人の判断でないもの・管理者の操作。§5.16） */
    private const SKIPPED = ['head_skipped', 'head_changed', 'reassigned', 'undone'];

    public const EDITED_SINCE_RETURN = '差戻しのあと、申請者が中身か添付を直し始めているため、差戻しは取り消せません。申請者に出し直してもらってください。';

    /** 次に取り消す操作の記録。無ければ null */
    public static function find(ApprovalRequest $request): ?ApprovalHistory
    {
        if ($request->round < 1) {
            return null;
        }

        $histories = ApprovalHistory::where('request_id', $request->id)
            ->where('round', $request->round)
            ->orderByDesc('id')
            ->get();

        $undone = $histories->where('action', 'undone')
            ->map(fn (ApprovalHistory $history) => (int) ($history->meta['undone_history_id'] ?? 0))
            ->all();

        foreach ($histories as $history) {
            if (in_array($history->id, $undone, true) || in_array($history->action, self::SKIPPED, true)) {
                continue;
            }

            return in_array($history->action, self::UNDOABLE, true) ? $history : null;
        }

        return null;
    }

    /**
     * 差戻しのあと、申請者が中身か添付を変えたか（D3）。今の中身（申請の行と今の添付）を今の回の控えと比べる。
     *
     * ⚠ 添付の追加・外すは lock_version を進めないので、版ではなく中身で比べる。取り消すときは申請の行をロックしてから
     *   呼ぶ（添付の追加・外すも申請の行をロックしてから書く）。控えが見つからなければ「変えた」とみなす（分からないときは取り消さない）
     */
    public static function editedSinceReturn(ApprovalRequest $request): bool
    {
        $revision = ApprovalRevision::where('request_id', $request->id)->where('round', $request->round)->first();

        if ($revision === null) {
            return true;
        }

        return RequestSnapshot::editableFingerprint(RequestSnapshot::make($request))
            !== RequestSnapshot::editableFingerprint($revision->snapshot);
    }
}
```

- [ ] **Step 5: 権限と断りの理由を足す**

`app/Support/Approval/RequestPermissions.php`（変更）

```diff
--- a/app/Support/Approval/RequestPermissions.php
+++ b/app/Support/Approval/RequestPermissions.php
@@ -5,6 +5,7 @@
 use App\Enums\ApprovalStatus;
 use App\Enums\ApprovalStepKind;
 use App\Enums\ApprovalStepStatus;
+use App\Models\ApprovalHistory;
 use App\Models\ApprovalRequest;
 use App\Models\ApprovalStep;
 use App\Models\User;
@@ -15,11 +16,14 @@
  *
  * **画面のボタンの出し分けと、POST の受け付けの両方がこれを使う**（2 か所で判定しない）。
  * 見てよいかどうか（`RequestVisibility`）は別。ここは「見られる」前提で操作だけを見る。
+ * 決裁の管理者の操作（付け替え・取り消し・代理の取り下げ。2b・設計書 §5.14）も同じ形で持つ。
  */
 final class RequestPermissions
 {
     private ?ApprovalStep $waiting = null;
     private bool $waitingLoaded = false;
+    private ?ApprovalHistory $undoTarget = null;
+    private bool $undoTargetLoaded = false;
 
     private function __construct(private readonly User $user, private readonly ApprovalRequest $request)
     {
@@ -111,4 +115,59 @@ public function judgeRefusal(): ?string
             ? '自分の申請には判断できません。'
             : null;
     }
+
+    /** 決裁の管理者として、この申請に管理の操作（付け替え・取り消し・代理の取り下げ）ができる立場か（自分の申請は不可。D25） */
+    public function isAdminOperator(): bool
+    {
+        return $this->user->isApprovalAdmin() && ! $this->isApplicant();
+    }
+
+    /** 決裁の管理者なのに管理の操作ができない理由（自分が申請者。D25）。管理者でなければ null */
+    public function adminRefusal(): ?string
+    {
+        return ($this->user->isApprovalAdmin() && $this->isApplicant())
+            ? '自分が申請者の申請には、付け替え・取り消し・代理の取り下げはできません。'
+            : null;
+    }
+
+    /** 部門長の確認を付け替えられる（部門長確認中の申請だけ。社長の段階は付け替えない。D2） */
+    public function canReassign(): bool
+    {
+        return $this->isAdminOperator()
+            && $this->request->status === ApprovalStatus::HeadReview
+            && $this->waitingStep()?->kind === ApprovalStepKind::Head;
+    }
+
+    /** 次に取り消す操作の記録（今の回の最後の操作。UndoTarget）。無ければ null */
+    public function undoTarget(): ?ApprovalHistory
+    {
+        if (! $this->undoTargetLoaded) {
+            $this->undoTarget       = UndoTarget::find($this->request);
+            $this->undoTargetLoaded = true;
+        }
+
+        return $this->undoTarget;
+    }
+
+    /** 押し間違いを取り消せる（取り消せる操作がある。差戻しのあとの直しは undoRefusal() が見る） */
+    public function canUndo(): bool
+    {
+        return $this->isAdminOperator() && $this->undoTarget() !== null;
+    }
+
+    /** 取り消しを断る理由（差戻しのあと申請者が中身か添付を直し始めていた。D3）。断らなければ null */
+    public function undoRefusal(): ?string
+    {
+        $target = $this->undoTarget();
+
+        return ($target !== null && in_array($target->action, UndoTarget::RETURNS, true) && UndoTarget::editedSinceReturn($this->request))
+            ? UndoTarget::EDITED_SINCE_RETURN
+            : null;
+    }
+
+    /** 申請者に代わって取り下げられる（申請者の取り下げと同じ状態。要件 4.3 のケース 8） */
+    public function canWithdrawByAdmin(): bool
+    {
+        return $this->isAdminOperator() && $this->request->status->isWithdrawable();
+    }
 }
```

- [ ] **Step 6: 判断を取り消された人も見続けられるようにする**（記録の判断した人の行を足す。段階の条件は残す）

`app/Support/Approval/RequestVisibility.php`（変更）

```diff
--- a/app/Support/Approval/RequestVisibility.php
+++ b/app/Support/Approval/RequestVisibility.php
@@ -22,6 +22,12 @@
  */
 final class RequestVisibility
 {
+    /** 判断の記録（取り消された判断をした人も見られるように、記録からも「判断した人」を引く。2b・設計書 §5.14） */
+    private const JUDGEMENT_ACTIONS = [
+        'head_approved', 'head_returned', 'reviewed',
+        'president_approved', 'president_conditional', 'president_rejected', 'president_returned',
+    ];
+
     public static function canView(User $user, ApprovalRequest $request): bool
     {
         return self::apply(ApprovalRequest::query()->whereKey($request->getKey()), $user)->exists();
@@ -109,6 +115,15 @@ private static function othersRules(Builder $q, User $user): void
         $q->orWhereExists(function (QueryBuilder $s) use ($user): void {
             self::stepsOfThisRequest($s)->where('approval_steps.actor_user_id', $user->id);
         });
+
+        // 判断を取り消された人も（取り消しで段階の判断した人は空に戻るが、判断の記録は残る。取り消されたことを知らせる
+        // 段階3 の通知〈要件 4.7〉の先で 404 にしない。2b 計画 §0.5）
+        $q->orWhereExists(function (QueryBuilder $s) use ($user): void {
+            $s->selectRaw('1')->from('approval_histories')
+                ->whereColumn('approval_histories.request_id', 'approval_requests.id')
+                ->where('approval_histories.actor_user_id', $user->id)
+                ->whereIn('approval_histories.action', self::JUDGEMENT_ACTIONS);
+        });
     }
 
     private static function stepsOfThisRequest(QueryBuilder $s): QueryBuilder
```

- [ ] **Step 7: 3 つの操作を `Workflow` に足す**

⚠ ロックは申請の行 → 段階の行の順（`headChanged()` と同じ）。取り消しは申請の行をロックしてから D3 を比べる（添付の変更は版を進めないため）。段階の行は消さず、`arrived_at` を空にしない（§5.16）。

`app/Support/Approval/Workflow.php`（変更）

```diff
--- a/app/Support/Approval/Workflow.php
+++ b/app/Support/Approval/Workflow.php
@@ -7,6 +7,7 @@
 use App\Enums\ApprovalStepKind;
 use App\Enums\ApprovalStepResult;
 use App\Enums\ApprovalStepStatus;
+use App\Enums\UserStatus;
 use App\Models\ApprovalDepartment;
 use App\Models\ApprovalRequest;
 use App\Models\ApprovalRevision;
@@ -17,7 +18,8 @@
 /**
  * 状態の移り変わり（要件 4 章・設計書 §5.8）。**申請の状態を変えるのはここだけ。**
  *
- * 流れは 提出 → 部門長 → 審査 → 社長 →（条可なら）条件確認。操作はすべて
+ * 流れは 提出 → 部門長 → 審査 → 社長 →（条可なら）条件確認。決裁の管理者の操作（付け替え・取り消し・代理の取り下げ。
+ * 2b・設計書 §5.14）もここに置く。操作はすべて
  *   1. トランザクションの中で申請を読み直す
  *   2. 画面の `lock_version` と比べる（古い画面から押した操作を断る）
  *   3. `RequestPermissions` で権限を確かめる
@@ -236,6 +238,145 @@ public function headChanged(ApprovalDepartment $department, ?int $oldHeadId, ?in
         });
     }
 
+    /**
+     * 部門長の確認の付け替え（要件 4.7・設計書 §5.14・D2・D22・D25）。部門長確認中の申請の、部門長の段階の担当を別の人にする。
+     *
+     * 付け替えた担当は、部門の設定で部門長を変えると新しい部門長へ移る（D23。headChanged() が担当を空に戻す）。
+     * 状態は変えないが lock_version を進める（付け替えの前に開いた画面から押した判断・取り下げを断る。計画 2a §0.3）。
+     * ⚠ ロックは headChanged() と同じ「申請の行 → 段階の行」の順に主キーで取る（§5.16）。
+     */
+    public function reassignHead(ApprovalRequest $request, User $admin, int $lockVersion, User $to, ?string $reason): void
+    {
+        DB::transaction(function () use ($request, $admin, $lockVersion, $to, $reason): void {
+            ApprovalRequest::whereKey($request->id)->lockForUpdate()->first();
+            $request->refresh();
+            $this->assertFresh($request, $lockVersion);
+
+            $permissions = RequestPermissions::for($admin, $request);
+            if (! $permissions->canReassign()) {
+                throw new WorkflowRefused([$permissions->adminRefusal() ?? '部門長の確認を待っている申請だけ付け替えられます。']);
+            }
+            $reason = self::requireReason($reason);
+
+            $step = ApprovalStep::with('department')->whereKey($permissions->waitingStep()->id)->lockForUpdate()->first();
+            if ($step === null || $step->status !== ApprovalStepStatus::Waiting || $step->kind !== ApprovalStepKind::Head) {
+                throw new WorkflowConflict();
+            }
+
+            $before  = $step->assignee_user_id ?? $step->department?->head_user_id;
+            $refusal = match (true) {
+                $to->id === $request->user_id => '申請者本人には付け替えられません。',
+                $to->trashed() || $to->status !== UserStatus::Active || $to->email === null => '付け替え先は、有効でメールアドレスのある人から選んでください。',
+                $to->id === $before => 'いまの担当と同じ人です。',
+                default => null,
+            };
+            if ($refusal !== null) {
+                throw new WorkflowRefused([$refusal]);
+            }
+
+            $step->update(['assignee_user_id' => $to->id]);
+            $this->bump($request, $lockVersion);
+
+            HistoryRecorder::record($request, 'reassigned', $admin, [
+                'step_id' => $step->id,
+                'reason'  => $reason,
+                'meta'    => ['from_user_id' => $before, 'to_user_id' => $to->id],
+            ]);
+        });
+    }
+
+    /**
+     * 押し間違いの取り消し（要件 4.7・設計書 §5.14・D3・D21・D24・D25）。今の回の最後の操作を 1 つ取り消し、
+     * その操作の直前の状態へ戻す（取り消す操作は UndoTarget。続けて行えば提出の手前までさかのぼれる）。
+     *
+     * - 取り消した判断の段階を「待ち」に戻し（判断した人・結果・コメント・日時を空に。元の判断は記録に残る）、
+     *   後ろの段階を「まだ届いていない」に戻す。段階の行は消さない（記録の step_id が RESTRICT。§5.16）
+     * - 届いた日時（arrived_at）は空にしない（一度届いた人が見られなくならない D19・待ち日数は元の届いた日から C11。§5.16）
+     * - 社長の判断の取り消しは、番号を残して判断・決裁日・完了日を空に戻す（6.5・D21。次の判断で同じ番号を使う）
+     * - 差戻しの取り消しは、差戻しのあと申請者が中身か添付を変えていたら断る（D3）
+     * - 元の記録は消さず、取り消した記録（undone。meta に取り消した記録の id と操作）を足す
+     * ⚠ 申請の行をロックしてから読む（D3 を比べる途中に添付が変わらない。添付の変更は lock_version を進めないため。§5.16）
+     */
+    public function undo(ApprovalRequest $request, User $admin, int $lockVersion, ?string $reason): void
+    {
+        DB::transaction(function () use ($request, $admin, $lockVersion, $reason): void {
+            ApprovalRequest::whereKey($request->id)->lockForUpdate()->first();
+            $request->refresh();
+            $this->assertFresh($request, $lockVersion);
+
+            $permissions = RequestPermissions::for($admin, $request);
+            $target      = $permissions->undoTarget();
+            if (! $permissions->canUndo() || $target === null) {
+                throw new WorkflowRefused([$permissions->adminRefusal() ?? '取り消せる操作がありません（提出の手前まで戻っています）。']);
+            }
+            $reason = self::requireReason($reason);
+
+            $refusal = $permissions->undoRefusal();
+            if ($refusal !== null) {
+                throw new WorkflowRefused([$refusal]);
+            }
+
+            // 記録の「後」の状態が今の状態と食い違うなら取り消さない（ここに来ることは無い見込み。分からないときは動かさない）
+            if ($target->to_status !== $request->status->value) {
+                throw new WorkflowRefused(['記録と今の状態が食い違うため取り消せません。']);
+            }
+
+            [$to, $extra] = match ($target->action) {
+                'head_approved', 'head_returned' => [ApprovalStatus::HeadReview, []],
+                'reviewed'                       => [ApprovalStatus::Review, []],
+                'president_returned'             => [ApprovalStatus::President, []],
+                'president_approved', 'president_conditional', 'president_rejected'
+                                                 => [ApprovalStatus::President, ['decision' => null, 'decided_at' => null, 'finished_at' => null]],
+                'condition_confirmed'            => [ApprovalStatus::Condition, ['finished_at' => null]],
+            };
+
+            // ⚠ 申請の行 → 段階の行の順にロックする（ほかの操作と同じ順）
+            $steps = ApprovalStep::where('request_id', $request->id)->where('round', $request->round)->pluck('id')->all();
+            ApprovalStep::whereKey($steps)->orderBy('id')->lockForUpdate()->get();
+
+            $from = $request->status;
+            $this->move($request, $lockVersion, $to, $extra);
+
+            // 条件確認の取り消しは段階を動かさない（社長の段階は条可のまま）
+            if ($target->action !== 'condition_confirmed') {
+                $this->reopenStep($request, (int) $target->step_id);
+            }
+
+            HistoryRecorder::record($request, 'undone', $admin, [
+                'from_status' => $from->value,
+                'to_status'   => $to->value,
+                'step_id'     => $target->step_id,
+                'reason'      => $reason,
+                'meta'        => ['undone_history_id' => $target->id, 'undone_action' => $target->action],
+            ]);
+        });
+    }
+
+    /** 代理の取り下げ（要件 4.3 のケース 8・4.7・設計書 §5.14・D25）。申請者の取り下げと同じ状態のとき。理由は必須 */
+    public function withdrawByAdmin(ApprovalRequest $request, User $admin, int $lockVersion, ?string $reason): void
+    {
+        DB::transaction(function () use ($request, $admin, $lockVersion, $reason): void {
+            $request->refresh();
+            $this->assertFresh($request, $lockVersion);
+
+            $permissions = RequestPermissions::for($admin, $request);
+            if (! $permissions->canWithdrawByAdmin()) {
+                throw new WorkflowRefused([$permissions->adminRefusal() ?? 'この申請は取り下げられる状態ではありません。']);
+            }
+            $reason = self::requireReason($reason);
+
+            $from = $request->status;
+            $this->move($request, $lockVersion, ApprovalStatus::Withdrawn);
+            $this->cancelRest($request);
+
+            HistoryRecorder::record($request, 'withdrawn_by_admin', $admin, [
+                'from_status' => $from->value,
+                'to_status'   => ApprovalStatus::Withdrawn->value,
+                'reason'      => $reason,
+            ]);
+        });
+    }
+
     private function judge(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStepKind $kind, ApprovalStepResult $result, ?string $comment): void
     {
         DB::transaction(function () use ($request, $actor, $lockVersion, $kind, $result, $comment): void {
@@ -374,6 +515,48 @@ private function move(ApprovalRequest $request, int $lockVersion, ApprovalStatus
         $request->refresh();
     }
 
+    /**
+     * 状態は変えずに lock_version だけを進める（付け替え）。当たらなければ先を越された。
+     * ⚠ 状態が変わった日時（status_changed_at）は変えない（申請者の番の待ち日数に使うため）
+     */
+    private function bump(ApprovalRequest $request, int $lockVersion): void
+    {
+        $affected = ApprovalRequest::whereKey($request->id)
+            ->where('lock_version', $lockVersion)
+            ->update(['lock_version' => $lockVersion + 1, 'updated_at' => now()]);
+
+        if ($affected !== 1) {
+            throw new WorkflowConflict();
+        }
+
+        $request->refresh();
+    }
+
+    /**
+     * 取り消した判断の段階を「待ち」に戻し、今の回のそれより後ろの段階を「まだ届いていない」に戻す（取り消し）。
+     * 届いた日時（arrived_at）は空にしない（§5.16）。担当の付け替え（assignee_user_id）はそのまま
+     */
+    private function reopenStep(ApprovalRequest $request, int $stepId): void
+    {
+        $now = now();
+
+        ApprovalStep::whereKey($stepId)->update([
+            'status'        => ApprovalStepStatus::Waiting->value,
+            'acted_at'      => null,
+            'actor_user_id' => null,
+            'result'        => null,
+            'comment'       => null,
+            'updated_at'    => $now,
+        ]);
+
+        // 段階は部門長 → 審査 → 社長の順に作るので、id が大きいものが後ろの段階
+        ApprovalStep::where('request_id', $request->id)
+            ->where('round', $request->round)
+            ->where('id', '>', $stepId)
+            ->whereIn('status', [ApprovalStepStatus::Waiting->value, ApprovalStepStatus::Cancelled->value])
+            ->update(['status' => ApprovalStepStatus::Pending->value, 'updated_at' => $now]);
+    }
+
     private function finishStep(ApprovalStep $step, User $actor, ApprovalStepResult $result, ?string $comment): void
     {
         $step->update([
@@ -416,6 +599,18 @@ private function recordJudgement(ApprovalRequest $request, string $action, User
         ]);
     }
 
+    /** 管理者の操作の理由（必須。前後の空白〈全角を含む〉を除いて空なら断る。設計書 §5.14・D22） */
+    private static function requireReason(?string $reason): string
+    {
+        $reason = self::cleanComment($reason);
+
+        if ($reason === null) {
+            throw new WorkflowRefused(['理由を入力してください。']);
+        }
+
+        return $reason;
+    }
+
     private static function cleanComment(?string $comment): ?string
     {
         $comment = $comment === null ? null : preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $comment);
```

- [ ] **Step 8: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (79 tests, …)`

- [ ] **Step 9: 全件を流す**

Expected: `OK (2830 tests, 19748 assertions)`

- [ ] **Step 10: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b add app/Support/Approval/UndoTarget.php app/Support/Approval/Workflow.php app/Support/Approval/RequestPermissions.php app/Support/Approval/RequestVisibility.php app/Models/ApprovalHistory.php tests/Feature/Approval/Phase2/WorkflowAdminTest.php tests/Feature/Approval/Phase2/WorkflowTest.php tests/Feature/ClockReadScanTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b commit -m "$(cat <<'MSG'
feat(approval): 付け替え・押し間違いの取り消し・代理の取り下げを Workflow に足す

部門長の確認の付け替え（D2・D22）、今の回の最後の操作を 1 つずつさかのぼる
取り消し（D24。差戻しは申請者が直し始めていたら断る D3・番号は残す D21）、
理由つきの代理の取り下げを足す。自分が申請者の申請にはできない（D25）。
判断を取り消された人も、記録の判断した人として申請を見続けられる。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 5: ⑩ と詳細の管理者の操作（§5.14・§5.16）

決裁の管理者の画面を作る（§0.8）。コミットは 3 つ（5-A・5-B・5-C）。5-A と 5-B は 5-C の土台（担当に選べる人の決まり・送り先で小窓を決める作り）で、どちらも単独で意味のある直し。

### 5-A: 担当に選べる人の決まりを `Assignees` にまとめる

部門長・審査担当者の選択肢と入力の検査（有効でメールアドレスのある人。D7）は部門の管理にあった。付け替え先（5-C）も同じ決まりなので、部品に出して 2 か所に書かない。**振る舞いは変えない**（テストを足さない。部門の管理の既存のテストが守る）。

**Files:**
- Create: `app/Support/Approval/Assignees.php`
- Modify: `app/Http/Controllers/Approval/OrganizationController.php`

**Interfaces:**
- Produces: `Assignees::rule(): Illuminate\Validation\Rules\Exists`（削除していない・有効・メールあり）／`Assignees::candidates(): Collection<int, User>`（名前の順。許可していないドメインの人は `mail_allowed` が `false`）

**差分の大きさ:** 2 ファイル・+51 / −14 行（差分のファイル `0006-…`。テストのファイルは無い）

- [ ] **Step 1: 部品を書く**

`app/Support/Approval/Assignees.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Enums\UserStatus;
use App\Models\ApprovalMailDomain;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * 担当に選べる人（部門長・審査担当者・部門長の確認の付け替え先。設計書 D7・§5.4・§5.14）。
 *
 * 有効でメールアドレスのある人（基幹を使う人・決裁のみ利用者のどちらも。所属は問わない。D6）。
 * 許可していないドメインの人も選べるが、通知メールが届かないので画面で注意を出す（mail_allowed）。
 * ⚠ 部門の管理と付け替えで同じ決まりを使う（2 か所に書かない）。
 */
final class Assignees
{
    /** 入力の検査の規則（削除した人・無効の人・メールの無い人を断る） */
    public static function rule(): Exists
    {
        return Rule::exists('users', 'id')
            ->whereNull('deleted_at')
            ->where('status', UserStatus::Active->value)
            ->whereNotNull('email');
    }

    /**
     * 選択肢（名前の順）。許可していないドメインの人は mail_allowed が false
     *
     * @return Collection<int, User>
     */
    public static function candidates(): Collection
    {
        $allowed = ApprovalMailDomain::pluck('domain')->all();

        return User::where('status', UserStatus::Active->value)->whereNotNull('email')
            ->orderBy('name')->get(['id', 'name', 'email', 'employee_number'])
            ->map(function (User $user) use ($allowed) {
                $user->mail_allowed = in_array(mb_strtolower(substr((string) $user->email, strrpos((string) $user->email, '@') + 1), 'UTF-8'), $allowed, true);

                return $user;
            });
    }
}
```

- [ ] **Step 2: 部門の管理を部品に寄せる**

`app/Http/Controllers/Approval/OrganizationController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/OrganizationController.php
+++ b/app/Http/Controllers/Approval/OrganizationController.php
@@ -4,7 +4,6 @@
 
 use App\Enums\ApprovalStepKind;
 use App\Enums\ApprovalStepStatus;
-use App\Enums\UserStatus;
 use App\Http\Controllers\Controller;
 use App\Models\ApprovalCompany;
 use App\Models\ApprovalDepartment;
@@ -15,6 +14,7 @@
 use App\Models\ApprovalType;
 use App\Models\User;
 use App\Support\Approval\ApprovalNumber;
+use App\Support\Approval\Assignees;
 use App\Support\Approval\SettingLogger;
 use App\Support\Approval\Workflow;
 use Illuminate\Http\Request;
@@ -59,15 +59,8 @@ function (ApprovalDepartment $department) use ($company): void {
                 return $domain;
             });
 
-        // 部門長・審査担当者の選択肢（D7）。許可していないドメインの人は選べるが注意を出す
-        $allowed    = ApprovalMailDomain::pluck('domain')->all();
-        $candidates = User::where('status', UserStatus::Active->value)->whereNotNull('email')
-            ->orderBy('name')->get(['id', 'name', 'email', 'employee_number'])
-            ->map(function (User $user) use ($allowed) {
-                $user->mail_allowed = in_array(mb_strtolower(substr((string) $user->email, strrpos((string) $user->email, '@') + 1), 'UTF-8'), $allowed, true);
-
-                return $user;
-            });
+        // 部門長・審査担当者の選択肢（D7）。許可していないドメインの人は選べるが注意を出す（付け替えと同じ決まり。Assignees）
+        $candidates = Assignees::candidates();
 
         return view('approvals.admin.organization', compact('companies', 'mailDomains', 'candidates'));
     }
@@ -296,10 +289,7 @@ private function validateDepartment(Request $request, ?ApprovalDepartment $curre
     /** 部門長・審査担当者に選べる人（有効・メールあり・削除されていない。D7） */
     private function assignableRule(): Exists
     {
-        return Rule::exists('users', 'id')
-            ->whereNull('deleted_at')
-            ->where('status', UserStatus::Active->value)
-            ->whereNotNull('email');
+        return Assignees::rule();
     }
 
     /** @return array{0: array<string, mixed>, 1: ?int, 2: list<int>, 3: ?int, 4: ?int} */
```

- [ ] **Step 3: 部門の管理のテストを流す**（振る舞いが変わっていないこと）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2b && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/OrganizationManagementTest.php tests/Feature/Approval/Phase2/OrganizationPhase2Test.php tests/Feature/Approval/Phase2/AssignmentGuardTest.php
```

Expected: `OK`

- [ ] **Step 4: 全件を流す**

Expected: `OK (2830 tests, 19749 assertions)`

- [ ] **Step 5: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b add app/Support/Approval/Assignees.php app/Http/Controllers/Approval/OrganizationController.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b commit -m "$(cat <<'MSG'
refactor(approval): 担当に選べる人の決まりを Assignees にまとめる

部門長・審査担当者の選択肢と入力の検査（有効でメールアドレスのある人）を部品に
出し、部門長の確認の付け替え先でも同じ決まりを使えるようにする。振る舞いは
変えない。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

### 5-B: 断られた操作の小窓だけを開き直す（m-6）

2a では、断られたあと開き直す小窓を「打ったコメントがどの小窓のものか」で推し量っていたので、状態が進んだあと（条件確認待ち）に古い画面の取り下げが入力の誤りで断られると、条件確認の小窓が取り下げのコメントで開いた（2a の軽微 m-6。版が古いので押しても断られ、害は無い）。**送り先のルートで決める**形に変える（§0.9）。5-C の管理者の小窓も同じ作りにする。

**Files:**
- Modify: `app/Http/Controllers/Approval/RequestActionController.php`（`approval_reopen` を入れる）・`resources/views/approvals/requests/_actions.blade.php`（それを読む）
- Test: Modify `tests/Feature/Approval/Phase2/RequestActionTest.php`（1 本足す）

**Interfaces:**
- Produces: セッションのフラッシュ `approval_reopen`（`'judge'`・`'condition'`・`'withdraw'`。5-C で `'reassign'`・`'undo'`・`'admin_withdraw'` を足す）。小窓は `old('lock_version')` があって、この値が自分の名前のときだけ開き直す

**差分の大きさ:** 3 ファイル・+46 / −20 行（差分のファイル `0007-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/RequestActionTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/RequestActionTest.php
+++ b/tests/Feature/Approval/Phase2/RequestActionTest.php
@@ -944,6 +944,27 @@ public function test_a_refused_judgement_reopens_with_the_choice_and_the_comment
         $this->assertSame((string) ($request->lock_version + 1), $form['fields']['lock_version']);
     }
 
+    /**
+     * 断られて開き直すのは、送った小窓だけ（2a の Task 19 の点検 m-6・2b 計画 §0.9）。状態が進んだあと（条件確認待ち）に古い画面の
+     * 取り下げが入力の誤りで断られても、条件確認の小窓は取り下げのコメントで開かない
+     */
+    public function test_only_the_refused_modal_reopens(): void
+    {
+        $w = $this->approvalWorld();
+        $this->launchApprovals();
+        $request = $this->toPresident($w);
+        $this->act($w['president'], $request, 'approvals.requests.decide', ['result' => 'conditional', 'comment' => '見積りを 2 社から取ること']);
+
+        // 条件確認待ちになった申請に、社長の判断の前に開いていた画面から、長すぎるコメントで取り下げを送った
+        $this->act($w['applicant'], $request, 'approvals.requests.withdraw', ['comment' => str_repeat('え', 2001)])
+            ->assertRedirect(route('approvals.requests.show', $request));
+
+        $html = $this->showHtml($w['applicant'], $request);
+        $this->assertStringContainsString('社長の条件を確認してください', $html, '前提: 条件確認の欄が出ている');
+        $this->assertStringContainsString('confirmCondition: false', $html);
+        $this->assertSame('', $this->parseForm($html, 'action="' . route('approvals.requests.confirmCondition', $request) . '"')['fields']['comment']);
+    }
+
     /** 取り下げ・条件確認も、入力の誤りで断られたら打ったコメントで小窓を開き直す。先を越されたときは開き直さない（Task 19 の C8） */
     public function test_a_refused_withdrawal_or_condition_reopens_with_the_comment(): void
     {
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2b && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase2/RequestActionTest.php
```

Expected: `FAILURES!` `Tests: 43, Assertions: 480, Failures: 1.`

- `RequestActionTest::test_only_the_refused_modal_reopens`

- [ ] **Step 3: 送り先で小窓を決める**

`app/Http/Controllers/Approval/RequestActionController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/RequestActionController.php
+++ b/app/Http/Controllers/Approval/RequestActionController.php
@@ -24,6 +24,8 @@
  * ⚠ 画面が描いたときの lock_version を渡す（古い画面から押した操作を「すでに処理されています」で断る。計画 §0.3）。
  *   送られてこない・0 以上の整数の形でないときは -1 にする（必ず断られる。FormInput::lockVersion()）。
  * ⚠ 戻り先はいつも詳細の画面（Bug #64）。
+ * ⚠ 断られたとき詳細の画面が開き直す小窓は、送り先で決めてセッションに残す（approval_reopen。フォームの値で受け取らないので、
+ *   状態が進んだあとに古い画面の小窓が断られても、別の小窓は開かない。2a の Task 19 の点検 m-6・2b 計画 §0.9）。
  */
 class RequestActionController extends Controller
 {
@@ -52,6 +54,7 @@ public function decide(Request $request, ApprovalRequest $approvalRequest): Redi
     /** 条件の確認（申請者。要件 4.6） */
     public function confirmCondition(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
     {
+        $request->session()->flash('approval_reopen', 'condition');
         $comment = $this->optionalComment($request, $approvalRequest);
 
         return $this->run(
@@ -64,6 +67,7 @@ public function confirmCondition(Request $request, ApprovalRequest $approvalRequ
     /** 取り下げ（申請者。コメントは任意。D17） */
     public function withdraw(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
     {
+        $request->session()->flash('approval_reopen', 'withdraw');
         $comment = $this->optionalComment($request, $approvalRequest);
 
         return $this->run(
@@ -76,6 +80,7 @@ public function withdraw(Request $request, ApprovalRequest $approvalRequest): Re
     private function judge(Request $request, ApprovalRequest $approvalRequest, ApprovalStepKind $kind): RedirectResponse
     {
         $this->assertVisible($request, $approvalRequest);
+        $request->session()->flash('approval_reopen', 'judge');
 
         $allowed = array_map(fn (ApprovalStepResult $result) => $result->value, ApprovalStepResult::allowedFor($kind));
         FormInput::unifyNewlines($request, 'comment');
```

`resources/views/approvals/requests/_actions.blade.php`（変更）

```diff
--- a/resources/views/approvals/requests/_actions.blade.php
+++ b/resources/views/approvals/requests/_actions.blade.php
@@ -9,19 +9,19 @@
     $refusal   = $permissions->judgeRefusal();
     $waiting   = $permissions->waitingStep();
     // 判断・取り下げ・条件確認が、入力の誤りかコメントの不足で断られて戻ったとき（RequestActionController が入力を戻す）は、
-    // 打った中身で小窓を開き直し、断られた理由を小窓の中にも出す（ページの上の理由は小窓に隠れる。Task 19 の C8）。
-    // 先を越されたとき（すでに処理されています）は入力を戻さないので開かない。
-    // ⚠ 3 つの小窓は同時には出ない（判断は申請者でない担当・取り下げと条件確認は申請者で、状態も重ならない）
-    // ⚠ 版は断られる前の版のまま（古い画面から送って断られたなら、直して送っても「すでに処理されています」で断る）
-    $reopen        = is_string(old('lock_version'));
-    $lockVersion   = $reopen ? old('lock_version') : $approvalRequest->lock_version;
-    $oldComment    = $reopen && is_string(old('comment')) ? old('comment') : '';
-    $refusedReason = $reopen ? ($errors->any() ? implode(' ', $errors->all()) : (string) session('error')) : '';
+    // 送った小窓だけを打った中身で開き直し、断られた理由を小窓の中にも出す（ページの上の理由は小窓に隠れる。Task 19 の C8）。
+    // どの小窓かは送り先がセッションに残す（approval_reopen。状態が進んだあとに古い画面の取り下げが断られても、条件確認の
+    // 小窓は開かない＝2a の Task 19 の点検 m-6。2b 計画 §0.9）。先を越されたとき（すでに処理されています）は入力を戻さないので開かない。
+    // ⚠ 版は断られる前の版のまま（古い画面から送って断られたなら、直して送っても「すでに処理されています」で断る）。開き直す小窓だけ
+    $reopenModal   = is_string(old('lock_version')) && is_string(session('approval_reopen')) ? session('approval_reopen') : null;
+    $versionFor    = fn (string $modal) => $reopenModal === $modal ? old('lock_version') : $approvalRequest->lock_version;
+    $commentFor    = fn (string $modal) => $reopenModal === $modal && is_string(old('comment')) ? old('comment') : '';
+    $refusedReason = $reopenModal !== null ? ($errors->any() ? implode(' ', $errors->all()) : (string) session('error')) : '';
 @endphp
 
 {{-- 申請者の操作 --}}
 @if($permissions->isApplicant())
-    <div class="flex flex-wrap items-center gap-2 mb-5" x-data="{ confirmDelete: false, confirmWithdraw: {{ $reopen && $permissions->canWithdraw() ? 'true' : 'false' }} }">
+    <div class="flex flex-wrap items-center gap-2 mb-5" x-data="{ confirmDelete: false, confirmWithdraw: {{ $reopenModal === 'withdraw' && $permissions->canWithdraw() ? 'true' : 'false' }} }">
         @if($permissions->canEdit())
             <a href="{{ route('approvals.requests.edit', $approvalRequest) }}" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold">{{ $approvalRequest->status === \App\Enums\ApprovalStatus::Returned ? '直して出し直す' : '編集する' }}</a>
         @endif
@@ -58,15 +58,15 @@
                     <form method="POST" action="{{ route('approvals.requests.withdraw', $approvalRequest) }}"
                           x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                         @csrf
-                        <input type="hidden" name="lock_version" value="{{ $lockVersion }}">
+                        <input type="hidden" name="lock_version" value="{{ $versionFor('withdraw') }}">
                         <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">この申請を取り下げますか？</div>
-                        @if($refusedReason !== '')
+                        @if($reopenModal === 'withdraw' && $refusedReason !== '')
                             <p class="mx-6 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-700">{{ $refusedReason }}</p>
                         @endif
                         <div class="px-6 py-4">
                             <p class="text-[12px] text-gray-500 mb-3">取り下げると回覧が止まり、元に戻せません。{{ $approvalRequest->number ? '決裁No はそのまま残ります。' : '' }}</p>
                             <label for="withdraw-comment" class="block text-[12px] font-semibold text-gray-700 mb-1">コメント<span class="text-gray-400 font-normal ml-1">（任意）</span></label>
-                            <textarea id="withdraw-comment" name="comment" rows="3" maxlength="2000" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ $oldComment }}</textarea>
+                            <textarea id="withdraw-comment" name="comment" rows="3" maxlength="2000" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ $commentFor('withdraw') }}</textarea>
                         </div>
                         <div class="px-6 pb-5 flex flex-wrap items-center justify-end gap-2">
                             <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
@@ -83,7 +83,7 @@
 {{-- 条件の確認（申請者。要件 4.6。条件は社長の段階のコメント） --}}
 @if($permissions->canConfirmCondition())
     @php $conditionStep = $approvalRequest->currentSteps()->firstWhere('kind', \App\Enums\ApprovalStepKind::President); @endphp
-    <section class="bg-amber-50 rounded-lg border border-amber-200 mb-5 px-5 py-4" x-data="{ confirmCondition: {{ $reopen ? 'true' : 'false' }} }">
+    <section class="bg-amber-50 rounded-lg border border-amber-200 mb-5 px-5 py-4" x-data="{ confirmCondition: {{ $reopenModal === 'condition' ? 'true' : 'false' }} }">
         <h2 class="text-[14px] font-bold text-amber-900 mb-1">社長の条件を確認してください</h2>
         <p class="text-[13px] text-amber-900 whitespace-pre-wrap break-words mb-3">{{ $conditionStep?->comment }}</p>
         <button type="button" @click="confirmCondition = true" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">条件を確認しました</button>
@@ -93,15 +93,15 @@
                 <form method="POST" action="{{ route('approvals.requests.confirmCondition', $approvalRequest) }}"
                       x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                     @csrf
-                    <input type="hidden" name="lock_version" value="{{ $lockVersion }}">
+                    <input type="hidden" name="lock_version" value="{{ $versionFor('condition') }}">
                     <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">条件を確認したことを記録しますか？</div>
-                    @if($refusedReason !== '')
+                    @if($reopenModal === 'condition' && $refusedReason !== '')
                         <p class="mx-6 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-700">{{ $refusedReason }}</p>
                     @endif
                     <div class="px-6 py-4">
                         <p class="text-[12px] text-gray-500 mb-3">記録すると決裁済み（条可）になります。</p>
                         <label for="condition-comment" class="block text-[12px] font-semibold text-gray-700 mb-1">コメント<span class="text-gray-400 font-normal ml-1">（任意）</span></label>
-                        <textarea id="condition-comment" name="comment" rows="3" maxlength="2000" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ $oldComment }}</textarea>
+                        <textarea id="condition-comment" name="comment" rows="3" maxlength="2000" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ $commentFor('condition') }}</textarea>
                     </div>
                     <div class="px-6 pb-5 flex flex-wrap items-center justify-end gap-2">
                         <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
@@ -134,7 +134,7 @@
             $choiceData[$choice->value] = ['label' => $choice->labelFor($kind), 'comment' => $choice->requiresComment()];
         }
         // 断られて戻ったら、選んでいた判断で小窓を開き直す（C8。この段階で選べる判断のときだけ）
-        $reopenChoice = $reopen && is_string(old('result')) && isset($choiceData[old('result')]) ? old('result') : null;
+        $reopenChoice = $reopenModal === 'judge' && is_string(old('result')) && isset($choiceData[old('result')]) ? old('result') : null;
     @endphp
     <section class="bg-white rounded-lg border-2 border-emerald-200 mb-5 px-5 py-4" x-data="approvalJudge({{ \Illuminate\Support\Js::from($choiceData) }})" @if($reopenChoice !== null) x-init="open({{ \Illuminate\Support\Js::from($reopenChoice) }})" @endif>
         <h2 class="text-[14px] font-bold text-gray-900 mb-1">{{ $kind->label() }}としての判断</h2>
@@ -151,12 +151,12 @@ class="px-4 py-2 rounded-md text-[13px] font-semibold cursor-pointer border {{ i
                 <form method="POST" action="{{ route($judgeRoute, $approvalRequest) }}"
                       x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                     @csrf
-                    <input type="hidden" name="lock_version" value="{{ $lockVersion }}">
+                    <input type="hidden" name="lock_version" value="{{ $versionFor('judge') }}">
                     {{-- 選んだ判断（選ぶボタンの open() が決める）。選べる判断はサーバーが描く（選ぶボタンの open('…') と approvalJudge に
                          渡す Js::from。Bug #47）。⚠ 確定のボタンに name・value を持たせない（Task 19 の C2） --}}
                     <input type="hidden" name="result" :value="choice">
                     <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">「<span x-text="label()"></span>」で確定しますか？</div>
-                    @if($refusedReason !== '')
+                    @if($reopenModal === 'judge' && $refusedReason !== '')
                         {{-- 断られた判断を選んでいるあいだだけ出す（小窓を閉じて別の判断を選び直すと、前の断りの理由は当てはまらない） --}}
                         <p x-show="choice === {{ \Illuminate\Support\Js::from($reopenChoice) }}" class="mx-6 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-700">{{ $refusedReason }}</p>
                     @endif
@@ -167,7 +167,7 @@ class="px-4 py-2 rounded-md text-[13px] font-semibold cursor-pointer border {{ i
                             <span x-show="!needsComment()" class="text-gray-400 font-normal">（任意）</span>
                         </label>
                         <textarea id="judge-comment" name="comment" rows="5" maxlength="2000" :required="needsComment()"
-                                  class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ $oldComment }}</textarea>
+                                  class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ $commentFor('judge') }}</textarea>
                     </div>
                     <div class="px-6 pb-5 flex flex-wrap items-center justify-end gap-2">
                         <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
```

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (43 tests, …)`（2a の小窓の開き直しのテスト〈C8〉も変えずに緑）

- [ ] **Step 5: 全件を流す**

Expected: `OK (2831 tests, 19759 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b add app/Http/Controllers/Approval/RequestActionController.php resources/views/approvals/requests/_actions.blade.php tests/Feature/Approval/Phase2/RequestActionTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b commit -m "$(cat <<'MSG'
fix(approval): 断られた操作の小窓だけを開き直す

開き直す小窓を打ったコメントから推し量らず、送り先のルートで決める（2a の
点検 m-6）。状態が進んだあとに古い画面の取り下げが断られても、条件確認の
小窓が取り下げのコメントで開かない。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

### 5-C: 進行中の申請の管理（⑩）と詳細の管理者の操作

⑩ の一覧（タブ 2 つ）と、詳細の「決裁の管理者の操作」（付け替え・取り消し・代理の取り下げの 3 つの小窓）と、その送り先を作る（§0.8）。あわせて、押せない判断の理由の 2 行目に付け替えを案内し（m-2）、回る順番の「届きました」を待ちの段階だけに出し（§0.4）、サイドバーに ⑩ のリンクを足す。門番は管理と稼働の両方（§5.16）。

**Files:**
- Create: `app/Http/Controllers/Approval/AdminRequestController.php`・`resources/views/approvals/admin/requests.blade.php`・`resources/views/approvals/requests/_admin_actions.blade.php`
- Modify: `routes/approval.php`（4 本）・`lang/ja/validation.php`（和名 2 つ）・`app/Http/Controllers/Approval/RequestController.php`（詳細に権限と付け替えの候補を渡す）・`app/Models/ApprovalSetting.php`（`launchedForMenu()`）・`resources/views/approvals/requests/show.blade.php`・`_actions.blade.php`（m-2）・`_steps.blade.php`・`resources/views/layouts/partials/sidebar.blade.php`・`sidebar_approval.blade.php`
- Test: Create `tests/Feature/Approval/Phase2/AdminRequestsTest.php`・Modify `tests/Feature/Approval/ApprovalAdminGateTest.php`・`tests/Feature/Approval/Phase2/LaunchGateTest.php`・`RequestActionTest.php`（m-2 の文）

**Interfaces:**
- Consumes: `Workflow::reassignHead()`・`undo()`・`withdrawByAdmin()`・`RequestPermissions` の管理者の権限・`UndoTarget::EDITED_SINCE_RETURN`（Task 4）・`Assignees`（5-A）・`approval_reopen`（5-B）・`FormInput`（Task 1）・`CurrentHandler::label()`・`PendingWork::waitingDays()`（2a）
- Produces: ルート `approvals.admin.requests.index`（GET・`?tab=decided`）・`approvals.admin.requests.reassign`・`.undo`・`.withdraw`（POST・`{approvalRequest}`・入力は `lock_version`・`admin_reason`、付け替えは `assignee_user_id`）／`ApprovalSetting::launchedForMenu(): bool`（Task 7 が使う）

**差分の大きさ:** 16 ファイル・+1166 / −20 行（差分のファイル `0008-…`）

- [ ] **Step 1: 失敗するテストを書く**（Review Focus 1・2・3・5 の 4 本と、計画の段階の変異で見つけた穴を塞ぐ 1 本〈理由の 2,000 文字の上限。Task 8 の A03〉を含む）

`tests/Feature/Approval/Phase2/AdminRequestsTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalHistory;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalMember;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\UndoTarget;
use App\Support\Approval\Workflow;
use App\Support\Approval\WorkflowConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 進行中の申請の管理（画面⑩）と、詳細の画面の「決裁の管理者の操作」（段階2 設計書 §5.14・2b 計画 Task 5）。
 */
class AdminRequestsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ParsesForms;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-26 01:00:00', 'UTC'));   // 日本時間 9/26 10:00
        $this->workflow = app(Workflow::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function html(User $user, string $url): string
    {
        return (string) $this->actingAs($user)->get($url)->assertOk()->getContent();
    }

    private function showHtml(User $user, ApprovalRequest $request): string
    {
        return $this->html($user, route('approvals.requests.show', $request));
    }

    /** そのフォームの HTML（action で探す） */
    private function formOf(string $html, string $action): string
    {
        $at = strpos($html, 'action="' . $action . '"');
        $this->assertNotFalse($at, "{$action} のフォームが無い");
        $open = strrpos(substr($html, 0, $at), '<form');

        return substr($html, $open, strpos($html, '</form>', $at) - $open);
    }

    // ---------------------------------------------------------------- 一覧（⑩）

    public function test_the_list_shows_requests_in_progress_by_waiting_days(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();

        $old = $this->submittedFor($w, ['subject' => '先週の申請']);                 // 9/26 に届いた
        Carbon::setTestNow(Carbon::parse('2026-09-29 01:00:00', 'UTC'));           // 日本時間 9/29
        $new = $this->submittedFor($w, ['subject' => '今日の申請']);
        $this->draftFor($w, ['subject' => '下書きは出さない']);
        $done = $this->submittedFor($w, ['subject' => '決裁済みは出さない']);
        foreach ([[$w['head'], 'judgeHead', ApprovalStepResult::Approve], [$w['reviewer'], 'judgeReview', ApprovalStepResult::Ok], [$w['president'], 'judgePresident', ApprovalStepResult::Approve]] as [$who, $method, $result]) {
            $done->refresh();
            $this->workflow->{$method}($done, $who, $done->lock_version, $result, null);
        }

        $html = $this->html($admin, route('approvals.admin.requests.index'));

        $this->assertSame(1, preg_match('/3 日.*?先週の申請.*?0 日.*?今日の申請/su', substr($html, strpos($html, '<table'))), '待ち日数の長い順でない');
        $this->assertStringNotContainsString('下書きは出さない', $html);
        $this->assertStringNotContainsString('決裁済みは出さない', $html);
        $this->assertStringContainsString('部門長（' . $w['head']->name . '）', $html, 'いま誰の番か');
        $this->assertStringContainsString('href="' . route('approvals.requests.show', $old) . '"', $html);
        $this->assertStringContainsString('href="' . route('approvals.requests.show', $new) . '"', $html);
    }

    /** 件名・申請部門は最後に提出した控えのもの（差戻し中の直しかけを出さない。D26） */
    public function test_the_list_shows_the_last_submitted_subject(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w, ['subject' => '提出した件名']);
        $this->workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, '直してください');
        $request->refresh()->update(['subject' => '直しかけの件名']);

        $html = $this->html($admin, route('approvals.admin.requests.index'));

        $this->assertStringContainsString('提出した件名', $html);
        $this->assertStringNotContainsString('直しかけの件名', $html);
        $this->assertStringContainsString('申請者（差戻しの対応）', $html);
    }

    public function test_the_list_flags_requests_stuck_on_the_applicant(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();

        // 申請のあとで申請者が部門長になった（自分の申請には判断できない。D16）
        $headStuck = $this->submittedFor($w, ['subject' => '部門長が本人']);
        $w['dept']->update(['head_user_id' => $w['applicant']->id]);
        $html = $this->html($admin, route('approvals.admin.requests.index'));
        $this->assertSame(1, preg_match('/部門長が本人.*?担当が申請者本人/su', $html));
        // 付け替えたら印は消える（担当が申請者本人でなくなった。2b 計画 Task 8 の変異 A08）
        $this->workflow->reassignHead($headStuck->fresh(), $admin, $headStuck->fresh()->lock_version, $this->baseUser(), '申請者が部門長になったため');
        $this->assertSame(0, preg_match('/部門長が本人.*?担当が申請者本人/su', $this->html($admin, route('approvals.admin.requests.index'))), '付け替えたのに印が残った');

        // 審査担当者が申請者本人しかいない（ほかの審査担当者を無効にした）
        $w['dept']->update(['head_user_id' => $w['head']->id]);
        $w['reviewDept']->reviewers()->attach($w['applicant']->id);
        $reviewStuck = $this->submittedFor($w, ['subject' => '審査が本人だけ']);
        $this->workflow->judgeHead($reviewStuck, $w['head'], $reviewStuck->lock_version, ApprovalStepResult::Approve, null);
        $w['reviewer']->forceFill(['status' => 'inactive'])->save();
        $html = $this->html($admin, route('approvals.admin.requests.index'));
        $this->assertSame(1, preg_match('/審査が本人だけ.*?審査担当者が申請者本人しかいない/su', $html));
        $this->assertSame(0, preg_match('/部門長が本人<\/a>.*?担当が申請者本人.*?審査が本人だけ/su', $html), '部門長を戻したのに印が残った');
    }

    public function test_the_decided_tab_lists_approved_and_rejected_requests_newest_first(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $decide = function (string $subject, ApprovalStepResult $result) use ($w): ApprovalRequest {
            $r = $this->submittedFor($w, ['subject' => $subject]);
            foreach ([[$w['head'], 'judgeHead', ApprovalStepResult::Approve, null], [$w['reviewer'], 'judgeReview', ApprovalStepResult::Ok, null], [$w['president'], 'judgePresident', $result, $result === ApprovalStepResult::Approve ? null : '見送り']] as [$who, $method, $res, $comment]) {
                $r->refresh();
                $this->workflow->{$method}($r, $who, $r->lock_version, $res, $comment);
            }

            return $r->refresh();
        };
        $first = $decide('先に可', ApprovalStepResult::Approve);
        Carbon::setTestNow(Carbon::parse('2026-09-27 01:00:00', 'UTC'));
        $second = $decide('あとで否', ApprovalStepResult::Reject);

        $html = $this->html($admin, route('approvals.admin.requests.index', ['tab' => 'decided']));

        $this->assertSame(1, preg_match('/' . preg_quote($second->number, '/') . '.*?あとで否.*?否決.*?' . preg_quote($first->number, '/') . '.*?先に可.*?決裁済み（可）/su', substr($html, strpos($html, '<table'))));
        $this->assertStringContainsString('aria-current="page"', $this->formOrNav($html));
    }

    /** 「決裁済み・否決」は 20 件ずつ。次のページへ進んでもこのタブのまま（タブの指定を落とさない。Review Focus 5） */
    public function test_the_decided_tab_pages_by_twenty_and_keeps_the_tab(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        for ($i = 1; $i <= 21; $i++) {
            Carbon::setTestNow(Carbon::parse('2026-09-26 01:00:00', 'UTC')->addMinutes($i));
            $r = $this->submittedFor($w, ['subject' => sprintf('決裁%02d', $i)]);
            $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);
            $this->workflow->judgeReview($r->refresh(), $w['reviewer'], $r->lock_version, ApprovalStepResult::Ok, null);
            $this->workflow->judgePresident($r->refresh(), $w['president'], $r->lock_version, ApprovalStepResult::Approve, null);
        }

        $first = $this->html($admin, route('approvals.admin.requests.index', ['tab' => 'decided']));
        $this->assertStringContainsString('決裁21', $first);
        $this->assertStringNotContainsString('決裁01', $first, 'いちばん古い 21 件目は次のページ');
        $this->assertStringContainsString(e(route('approvals.admin.requests.index', ['tab' => 'decided', 'page' => 2])), $first, '次のページへのリンクがタブを落とした');

        $second = $this->html($admin, route('approvals.admin.requests.index', ['tab' => 'decided', 'page' => 2]));
        $this->assertStringContainsString('決裁01', $second);
        $this->assertStringNotContainsString('決裁21', $second);
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>決裁済み・否決</', preg_replace('/\s+/', ' ', $this->formOrNav($second)));
    }

    /** 表示の切り替えの部分（aria-current の付いたタブ） */
    private function formOrNav(string $html): string
    {
        $at = strpos($html, 'aria-label="表示の切り替え"');

        return substr($html, $at, strpos($html, '</nav>', $at) - $at);
    }

    public function test_the_list_is_hidden_before_launch(): void
    {
        $this->approvalWorld();

        $this->actingAs($this->approvalAdmin())->get(route('approvals.admin.requests.index'))->assertRedirect(route('approvals.home'));
    }

    /** サイドバーの「進行中の申請の管理」は、決裁の管理者に・使い始めてから出す */
    public function test_the_sidebar_links_to_the_list_for_admins_after_launch(): void
    {
        $w      = $this->approvalWorld();
        $admin  = $this->approvalAdmin();
        $link   = 'href="' . route('approvals.admin.requests.index') . '"';

        $this->assertStringNotContainsString($link, $this->html($admin, route('approvals.home')), '使い始める前に出た');
        $this->launchApprovals();
        $this->assertSame(2, substr_count($this->html($admin, route('approvals.home')), $link), '基幹のサイドバーの展開・ドロワーの 2 か所');
        $this->assertStringNotContainsString($link, $this->html($w['head'], route('approvals.home')), '管理者でない人に出た');

        $approvalOnlyAdmin = $this->approvalOnlyUser();
        ApprovalMember::create(['user_id' => $approvalOnlyAdmin->id, 'is_admin' => true]);
        $this->assertSame(2, substr_count($this->html($approvalOnlyAdmin->fresh(), route('approvals.home')), $link), '決裁のみ利用者のサイドバーの展開・ドロワーの 2 か所');
    }

    // ---------------------------------------------------------------- 詳細の「決裁の管理者の操作」

    public function test_the_detail_offers_the_operations_that_are_possible_now(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $html = $this->showHtml($admin, $request);
        $this->assertStringContainsString('決裁の管理者の操作', $html);
        $this->assertStringContainsString('部門長の確認を付け替える', $html);
        $this->assertStringContainsString('申請者に代わって取り下げる', $html);
        $this->assertStringNotContainsString('直前の操作を取り消す', $html, '提出の直後は取り消せる操作が無い');

        $this->workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);
        $html = $this->showHtml($admin, $request);
        $this->assertStringNotContainsString('部門長の確認を付け替える', $html, '審査中は付け替えない（D2）');
        $this->assertStringContainsString('直前の操作を取り消す', $html);
        $form = $this->formOf($html, route('approvals.admin.requests.undo', $request));
        $this->assertStringContainsString('部門長が承認', $form);
        $this->assertStringContainsString($w['head']->name, $form);

        // 管理者でない人には出さない
        $this->assertStringNotContainsString('決裁の管理者の操作', $this->showHtml($w['head'], $request));
    }

    public function test_the_reassign_choices_exclude_the_applicant_and_mark_unreachable_mail(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);
        $outside = $this->baseUser(['name' => '社外 太郎', 'email' => 'taro@example.org']);
        $inside  = $this->baseUser(['name' => '社内 花子', 'email' => 'hanako@mitsuwat.co.jp']);
        $w['applicant']->forceFill(['email' => 'applicant@mitsuwat.co.jp'])->save();   // 申請者もメールあり（選択肢の条件に当たる）
        $request = $this->submittedFor($w);

        $form = $this->formOf($this->showHtml($admin, $request), route('approvals.admin.requests.reassign', $request));

        $this->assertStringContainsString('<option value="' . $outside->id . '" >社外 太郎 ※通知メールが届きません</option>', $form);
        $this->assertStringContainsString('<option value="' . $inside->id . '" >社内 花子</option>', $form);
        $this->assertStringNotContainsString('value="' . $w['applicant']->id . '"', $form, '申請者本人を選べた');
    }

    public function test_an_admin_sees_why_they_cannot_operate_on_their_own_request(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        ApprovalMember::create(['user_id' => $w['applicant']->id, 'is_admin' => true]);
        $request = $this->submittedFor($w);

        $html = $this->showHtml($w['applicant']->fresh(), $request);

        $this->assertStringContainsString('自分が申請者の申請には、付け替え・取り消し・代理の取り下げはできません。', $html);
        $this->assertStringNotContainsString('部門長の確認を付け替える', $html);
    }

    /** 差戻しのあと申請者が直し始めていたら、取り消しのボタンは押せず理由を出す（D3。Bug #43） */
    public function test_the_undo_button_explains_the_refusal_after_the_applicant_edits(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $this->workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, '直してください');
        $request->refresh()->update(['subject' => '直した件名']);

        $html = $this->showHtml($admin, $request);

        $this->assertStringContainsString('<button type="button" disabled aria-describedby="undo-refusal"', $html);
        $this->assertStringContainsString('<p id="undo-refusal" class="mt-2 text-[12px] text-amber-800">' . UndoTarget::EDITED_SINCE_RETURN . '</p>', $html);
        $this->assertStringNotContainsString('action="' . route('approvals.admin.requests.undo', $request) . '"', $html);
    }

    // ---------------------------------------------------------------- 送信

    public function test_reassigning_from_the_detail(): void
    {
        $w      = $this->approvalWorld();
        $admin  = $this->approvalAdmin();
        $deputy = $this->baseUser(['name' => '代理 部長']);
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $form    = $this->parseForm($this->showHtml($admin, $request), 'action="' . route('approvals.admin.requests.reassign', $request) . '"');

        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], ['assignee_user_id' => (string) $deputy->id, 'admin_reason' => '部長が休職のため']))
            ->assertRedirect(route('approvals.requests.show', $request));

        $this->assertSame('部門長の確認を代理 部長さんに付け替えました。', session('success'));
        $this->assertSame($deputy->id, ApprovalHistory::where('action', 'reassigned')->sole()->meta['to_user_id']);
        $html = $this->showHtml($admin, $request);
        $this->assertStringContainsString('部門長の確認を付け替え', $html, '記録に出る');
        $this->assertStringContainsString('新しい担当: 代理 部長', $html);
        $this->assertStringContainsString('部長が休職のため', $html, '理由が記録に出る');
    }

    /** 断られたら付け替えの小窓を打った中身で開き直し、理由を小窓の中に出す（2b 計画 §0.9） */
    public function test_a_refused_reassignment_reopens_its_modal(): void
    {
        $w        = $this->approvalWorld();
        $admin    = $this->approvalAdmin();
        $deputy   = $this->baseUser();
        $inactive = $this->baseUser();
        $inactive->forceFill(['status' => 'inactive'])->save();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $action  = route('approvals.admin.requests.reassign', $request);
        $version = (string) $request->lock_version;

        // 理由が無い（入力の検査で断る）
        $this->actingAs($admin)->post($action, ['assignee_user_id' => (string) $deputy->id, 'admin_reason' => '', 'lock_version' => $version])
            ->assertRedirect(route('approvals.requests.show', $request));
        $html = $this->showHtml($admin, $request);
        $this->assertStringContainsString("x-data=\"{ adminModal: 'reassign' }\"", $html);
        $this->assertStringContainsString('理由を入力してください。', $this->formOf($html, $action));
        $this->assertStringContainsString('<option value="' . $deputy->id . '" selected>', $this->formOf($html, $action));

        // 無効の人（入力の検査で断る）
        $this->actingAs($admin)->post($action, ['assignee_user_id' => (string) $inactive->id, 'admin_reason' => '休職のため', 'lock_version' => $version]);
        $this->assertStringContainsString('付け替え先は、有効でメールアドレスのある人から選んでください。', $this->formOf($this->showHtml($admin, $request), $action));

        // いまの担当と同じ（Workflow が断る）
        $this->actingAs($admin)->post($action, ['assignee_user_id' => (string) $w['head']->id, 'admin_reason' => '休職のため', 'lock_version' => $version]);
        $html = $this->showHtml($admin, $request);
        $this->assertStringContainsString("x-data=\"{ adminModal: 'reassign' }\"", $html);
        $this->assertStringContainsString('いまの担当と同じ人です。', $this->formOf($html, $action));
        $this->assertSame(0, ApprovalHistory::where('action', 'reassigned')->count());

        // 先を越された（版が進んだ）ときは開き直さない
        DB::table('approval_requests')->where('id', $request->id)->increment('lock_version');
        $this->actingAs($admin)->post($action, ['assignee_user_id' => (string) $deputy->id, 'admin_reason' => '休職のため', 'lock_version' => $version])
            ->assertSessionHas('error', WorkflowConflict::MESSAGE);
        $this->assertStringContainsString('x-data="{ adminModal: null }"', $this->showHtml($admin, $request));
    }

    public function test_undoing_from_the_detail(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $this->workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->judgeReview($request->refresh(), $w['reviewer'], $request->lock_version, ApprovalStepResult::Ok, null);
        $form = $this->parseForm($this->showHtml($admin, $request), 'action="' . route('approvals.admin.requests.undo', $request) . '"');

        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], ['admin_reason' => "押し間違い\r\nの連絡があったため"]))
            ->assertRedirect(route('approvals.requests.show', $request));

        $this->assertSame('「審査の意見（可）」を取り消しました。', session('success'));
        $this->assertSame(ApprovalStatus::Review, $request->fresh()->status);
        $this->assertSame("押し間違い\nの連絡があったため", ApprovalHistory::where('action', 'undone')->sole()->reason, '改行はそろえて保存する（B1）');
        $html = $this->showHtml($admin, $request);
        $this->assertStringContainsString('押し間違いの取り消し（審査の意見）', $html);
        // 取り消して「まだ届いていない」に戻した社長の段階に、届いた日時を出さない（届いた日時は残っている。§5.16）
        $steps = substr($html, strpos($html, '回る順番'));
        $steps = substr($steps, 0, strpos($steps, '</section>'));
        $this->assertSame(1, substr_count($steps, 'に届きました'), '待ちの審査の段階だけに出す');
    }

    public function test_withdrawing_for_the_applicant_from_the_detail(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $form    = $this->parseForm($this->showHtml($admin, $request), 'action="' . route('approvals.admin.requests.withdraw', $request) . '"');

        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], ['admin_reason' => '退職のため']))
            ->assertRedirect(route('approvals.requests.show', $request));

        $this->assertSame('申請者に代わって取り下げました。', session('success'));
        $this->assertSame(ApprovalStatus::Withdrawn, $request->fresh()->status);
        $this->assertStringContainsString('決裁の管理者が代理で取り下げ', $this->showHtml($admin, $request));
    }

    /** 退職して削除した申請者の申請も、⑩ に名前つきで出て、詳細から代理で取り下げられる（2b 計画の Review Focus 1） */
    public function test_a_request_of_an_applicant_who_left_can_be_withdrawn_for_them(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w, ['subject' => '退職した人の申請']);
        $name    = $w['applicant']->name;
        $w['applicant']->delete();

        $list = $this->html($admin, route('approvals.admin.requests.index'));
        $this->assertStringContainsString('退職した人の申請', $list);
        $this->assertStringContainsString(e($name), $list);

        $form = $this->parseForm($this->showHtml($admin, $request), 'action="' . route('approvals.admin.requests.withdraw', $request) . '"');
        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], ['admin_reason' => '退職のため']))
            ->assertRedirect(route('approvals.requests.show', $request));

        $this->assertSame(ApprovalStatus::Withdrawn, $request->fresh()->status);
    }

    /** 同じ取り消しを 2 回送っても（二度押し・2 つのタブ）、さかのぼるのは 1 つだけ。2 回目は先を越された扱い（D24。Review Focus 2） */
    public function test_sending_the_same_undo_twice_goes_back_only_one_step(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $this->workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->judgeReview($request->refresh(), $w['reviewer'], $request->lock_version, ApprovalStepResult::Ok, null);
        $form = $this->parseForm($this->showHtml($admin, $request), 'action="' . route('approvals.admin.requests.undo', $request) . '"');
        $send = fn () => $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], ['admin_reason' => '押し間違い']));

        $send()->assertRedirect(route('approvals.requests.show', $request));
        $send()->assertRedirect(route('approvals.requests.show', $request));

        $this->assertSame(WorkflowConflict::MESSAGE, session('error'));
        $this->assertSame(ApprovalStatus::Review, $request->fresh()->status, '審査の意見だけを取り消した（部門長の承認は残る）');
        $this->assertSame(1, ApprovalHistory::where('action', 'undone')->count());
    }

    /** 差戻しを取り消したあと、申請者が開いたままの編集画面から保存しても直らない（部門長の確認中に中身が変わらない。D3 の裏側。Review Focus 3） */
    public function test_the_open_edit_form_does_not_save_after_the_return_is_undone(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w, ['subject' => '出した件名']);
        $this->workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, '見積りを添付してください');
        $edit = $this->parseForm($this->html($w['applicant'], route('approvals.requests.edit', $request)), 'action="' . route('approvals.requests.update', $request) . '"');
        unset($edit['fields']['related_numbers[]']);   // JS が描く欄（空の hidden は送らない）
        $undo = $this->parseForm($this->showHtml($admin, $request), 'action="' . route('approvals.admin.requests.undo', $request) . '"');
        $this->actingAs($admin)->post($undo['action'], array_merge($undo['fields'], ['admin_reason' => '押し間違い']));
        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status, '前提: 差戻しを取り消した');

        $this->actingAs($w['applicant'])->post($edit['action'], array_merge($edit['fields'], ['subject' => '直した件名', 'intent' => 'save']))
            ->assertRedirect(route('approvals.requests.show', $request));

        $this->assertSame('出した件名', $request->fresh()->subject);
        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status);
    }

    /** 理由は改行をそろえてから 2,000 文字を数える（改行の多い理由が上限の手前で断られない。B1） */
    public function test_a_reason_with_many_newlines_fits_in_two_thousand_characters(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $reason  = implode("\r\n", array_fill(0, 1000, 'あ'));   // そろえると 1,999 文字（CR+LF のままだと 2,998 文字）

        $this->actingAs($admin)->post(route('approvals.admin.requests.withdraw', $request), ['admin_reason' => $reason, 'lock_version' => (string) $request->lock_version])
            ->assertSessionHasNoErrors();

        $this->assertSame(ApprovalStatus::Withdrawn, $request->fresh()->status);
    }

    /** 理由は 2,000 文字まで（D22）。2,001 文字は入力の検査で断り、小窓を開き直す（2b 計画 Task 8 の変異 A03） */
    public function test_a_reason_over_two_thousand_characters_is_refused(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $this->actingAs($admin)->post(route('approvals.admin.requests.withdraw', $request), ['admin_reason' => str_repeat('あ', 2001), 'lock_version' => (string) $request->lock_version])
            ->assertRedirect(route('approvals.requests.show', $request));

        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status);
        $this->assertSame(0, ApprovalHistory::where('action', 'withdrawn_by_admin')->count());
        $this->assertStringContainsString("adminModal: 'admin_withdraw'", $this->showHtml($admin, $request), '断られた代理の取り下げの小窓を開き直す');
    }

    /** 見られない申請（他人の下書き）は 404（在るかどうかを漏らさない） */
    public function test_a_draft_of_someone_else_is_not_found(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $draft = $this->draftFor($w);

        foreach (['reassign', 'undo', 'withdraw'] as $name) {
            $this->actingAs($admin)->post(route("approvals.admin.requests.{$name}", $draft), ['admin_reason' => '理由', 'lock_version' => '0'])->assertNotFound();
        }
    }
}
```

`tests/Feature/Approval/ApprovalAdminGateTest.php`（変更）

```diff
--- a/tests/Feature/Approval/ApprovalAdminGateTest.php
+++ b/tests/Feature/Approval/ApprovalAdminGateTest.php
@@ -8,6 +8,7 @@
 use App\Models\ApprovalDepartment;
 use App\Models\ApprovalMailDomain;
 use App\Models\ApprovalMember;
+use App\Models\ApprovalRequest;
 use App\Models\ApprovalSetting;
 use App\Models\ApprovalType;
 use App\Models\User;
@@ -182,8 +183,8 @@ public function test_every_approvals_route_is_classified(): void
         //   出ている本当の理由（分類漏れ・門番の欠落・逆方向の見落とし）が隠れる。
         $this->assertSame([], $problems, "分類漏れ・門番の欠落・逆方向の見落とし:\n" . implode("\n", $problems));
 
-        // 走査が空振りして緑になる事故を防ぐ（段階2 の 2a で 39 本 = 決裁の管理 22 本 + ホーム 1 本 + 申請を回す画面 16 本）
-        $this->assertGreaterThanOrEqual(39, $found, 'approvals. のルートの走査に失敗している');
+        // 走査が空振りして緑になる事故を防ぐ（2b で 43 本 = 決裁の管理 22 本 + 進行中の申請の管理 4 本 + ホーム 1 本 + 申請を回す画面 16 本）
+        $this->assertGreaterThanOrEqual(43, $found, 'approvals. のルートの走査に失敗している');
     }
 
     /**
@@ -358,6 +359,10 @@ private function tableCounts(): array
             'approval_types' => DB::table('approval_types')->count(),
             'approval_reviewers' => DB::table('approval_reviewers')->count(),
             'approval_number_sequences' => DB::table('approval_number_sequences')->count(),
+            // 進行中の申請の管理（2b）。付け替え・取り消し・代理の取り下げは記録と段階を書く
+            'approval_requests' => DB::table('approval_requests')->count(),
+            'approval_steps' => DB::table('approval_steps')->count(),
+            'approval_histories' => DB::table('approval_histories')->count(),
         ];
     }
 
@@ -494,12 +499,19 @@ public function test_every_admin_route_refuses_outsiders_without_revealing_ids()
             'review_department_id' => $reviewDepartment->id, 'sort_order' => 1, 'is_active' => true,
         ]);
 
+        // 進行中の申請の管理（2b）の相手の申請。部門は審査部門にする（走査で消す部門に申請を付けると、部門の削除が
+        // 「申請がある部門は削除できない」の歯止めで止まり、網が細る。上の種類と同じ理由）
+        $approvalRequest = ApprovalRequest::create([
+            'user_id' => $manageableUser->id, 'department_id' => $reviewDepartment->id, 'type_id' => $type->id, 'subject' => '門番の確かめ',
+        ]);
+
         $existingValues = [
             'user' => (string) $manageableUser->id,
             'approvalCompany' => (string) $company->id,
             'approvalDepartment' => (string) $department->id,
             'mailDomain' => (string) $mailDomain->id,
             'approvalType' => (string) $type->id,
+            'approvalRequest' => (string) $approvalRequest->id,
         ];
 
         $outsiders = $this->outsiders();
@@ -529,10 +541,10 @@ public function test_every_admin_route_refuses_outsiders_without_revealing_ids()
         //   に出ている本当の理由（どのルート・どの変種・どの相手で止まらなかったか）が隠れる。
         $this->assertSame([], $problems, "権限の無い利用者を止められていないルート:\n" . implode("\n", $problems));
 
-        // 走査が空振りして緑になる事故を防ぐ（実測 18 ルート。パラメータなし 10 本 × 1 変種
-        // ＋ パラメータあり 8 本 × 2 変種 ＝ 26 通り、現状はいずれもメソッド 1 つずつ、× 6 人 ＝ 156 件）
-        $this->assertGreaterThanOrEqual(18, $adminRoutesChecked, 'approvals.admin. のルートの走査に失敗している');
-        $this->assertGreaterThanOrEqual(156, $requestsMade, '要求した件数が想定より少ない（走査が空振りしている）');
+        // 走査が空振りして緑になる事故を防ぐ（2b の実測 26 ルート＝2a の 22 本＋進行中の申請の管理 4 本。
+        // パラメータの有無で 1 変種・2 変種、いずれもメソッド 1 つずつ、× 6 人 ＝ 234 件）
+        $this->assertGreaterThanOrEqual(26, $adminRoutesChecked, 'approvals.admin. のルートの走査に失敗している');
+        $this->assertGreaterThanOrEqual(234, $requestsMade, '要求した件数が想定より少ない（走査が空振りしている）');
 
         // ⚠ これが唯一、門番の判定を $next() の後ろへ動かす変異（クライアントには 403 の
         //   まま返るが、コントローラの副作用は既に実行済み）を検出できる。⚠ ただし検出できる
```

`tests/Feature/Approval/Phase2/LaunchGateTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/LaunchGateTest.php
+++ b/tests/Feature/Approval/Phase2/LaunchGateTest.php
@@ -32,9 +32,9 @@ class LaunchGateTest extends TestCase
 
     /**
      * 門番の付いたルートの数の下限（空振りで緑にならないように）。
-     * 2a の実数 16 本 = 候補の検索 1・申請書と詳細 7・添付 3・判断など 5（2b で増える）。
+     * 実数 20 本 = 候補の検索 1・申請書と詳細 7・添付 3・判断など 5（2a）＋ 進行中の申請の管理 4（2b。管理の門番も持つ）。
      */
-    private const MIN_GATED = 16;
+    private const MIN_GATED = 20;
 
     private function isOpenBeforeLaunch(string $name): bool
     {
```

`tests/Feature/Approval/Phase2/RequestActionTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/RequestActionTest.php
+++ b/tests/Feature/Approval/Phase2/RequestActionTest.php
@@ -753,8 +753,8 @@ public function test_a_withdrawn_request_does_not_promise_a_number(): void
 
     /**
      * 押せない判断の理由の 2 行目は、段階ごとに次の手を言う（Task 19 の C7。利用者の決定 2026-09-28。Task 15 の点検の m-2）。
-     * 部門長の段階は取り下げて出し直すか決裁の管理者に相談・審査の段階はほかの審査担当者・社長の段階は社長の指定を変えられる
-     * 基幹の管理者（決裁の管理者には替えられない。要件 3.2・4.7）
+     * 部門長の段階は取り下げて出し直すか決裁の管理者に付け替えを頼む（2b で付け替えができたので案内する。設計書 §5.16）・
+     * 審査の段階はほかの審査担当者・社長の段階は社長の指定を変えられる基幹の管理者（決裁の管理者には替えられない。要件 3.2・4.7）
      */
     public function test_the_refusal_says_how_to_move_on_at_each_stage(): void
     {
@@ -766,7 +766,7 @@ public function test_the_refusal_says_how_to_move_on_at_each_stage(): void
         // 部門長の段階（申請のあとで申請者が部門長になった）
         $w['dept']->update(['head_user_id' => $w['applicant']->id]);
         $html = $this->showHtml($w['applicant'], $request);
-        $this->assertStringContainsString('取り下げて出し直すか、決裁の管理者に相談してください。', $html);
+        $this->assertStringContainsString('取り下げて出し直すか、決裁の管理者に部門長の確認の付け替えを頼んでください。', $html);
         $this->assertStringNotContainsString('担当を替えるには', $html);
 
         // 審査の段階
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2b && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase2/AdminRequestsTest.php tests/Feature/Approval/Phase2/LaunchGateTest.php tests/Feature/Approval/Phase2/RequestActionTest.php
```

Expected: `ERRORS!` `Tests: 72, Assertions: 547, Errors: 18, Failures: 7.`

- `AdminRequestsTest::test_the_list_shows_requests_in_progress_by_waiting_days`
- `AdminRequestsTest::test_the_list_shows_the_last_submitted_subject`
- `AdminRequestsTest::test_the_list_flags_requests_stuck_on_the_applicant`
- `AdminRequestsTest::test_the_decided_tab_lists_approved_and_rejected_requests_newest_first`
- `AdminRequestsTest::test_the_decided_tab_pages_by_twenty_and_keeps_the_tab`
- `AdminRequestsTest::test_the_list_is_hidden_before_launch`
- `AdminRequestsTest::test_the_sidebar_links_to_the_list_for_admins_after_launch`
- `AdminRequestsTest::test_the_reassign_choices_exclude_the_applicant_and_mark_unreachable_mail`
- `AdminRequestsTest::test_reassigning_from_the_detail`
- `AdminRequestsTest::test_a_refused_reassignment_reopens_its_modal`
- `AdminRequestsTest::test_undoing_from_the_detail`
- `AdminRequestsTest::test_withdrawing_for_the_applicant_from_the_detail`
- `AdminRequestsTest::test_a_request_of_an_applicant_who_left_can_be_withdrawn_for_them`
- `AdminRequestsTest::test_sending_the_same_undo_twice_goes_back_only_one_step`
- `AdminRequestsTest::test_the_open_edit_form_does_not_save_after_the_return_is_undone`
- `AdminRequestsTest::test_a_reason_with_many_newlines_fits_in_two_thousand_characters`
- `AdminRequestsTest::test_a_reason_over_two_thousand_characters_is_refused`
- `AdminRequestsTest::test_a_draft_of_someone_else_is_not_found`
- `ApprovalAdminGateTest::test_every_approvals_route_is_classified`
- `ApprovalAdminGateTest::test_every_admin_route_refuses_outsiders_without_revealing_ids`
- `AdminRequestsTest::test_the_detail_offers_the_operations_that_are_possible_now`
- `AdminRequestsTest::test_an_admin_sees_why_they_cannot_operate_on_their_own_request`
- `AdminRequestsTest::test_the_undo_button_explains_the_refusal_after_the_applicant_edits`
- `LaunchGateTest::test_every_approval_route_is_classified`
- `RequestActionTest::test_the_refusal_says_how_to_move_on_at_each_stage`

（Error は `Route [approvals.admin.requests.…] not defined.`。Failure は、詳細に管理者の操作が無い・走査テストの分類と下限・m-2 の文）

- [ ] **Step 3: ルートと和名を足す**

`routes/approval.php`（変更）

```diff
--- a/routes/approval.php
+++ b/routes/approval.php
@@ -1,5 +1,6 @@
 <?php
 
+use App\Http\Controllers\Approval\AdminRequestController;
 use App\Http\Controllers\Approval\HomeController;
 use App\Http\Controllers\Approval\OrganizationController;
 use App\Http\Controllers\Approval\RelatedNumberController;
@@ -81,6 +82,23 @@
     Route::delete('/types/{approvalType}', [TypeController::class, 'destroy'])->name('types.destroy');
 });
 
+/*
+|--------------------------------------------------------------------------
+| 進行中の申請の管理（2b・画面⑩・決裁の管理者。段階2 設計書 §5.14）
+|--------------------------------------------------------------------------
+|
+| ⚠ 管理（approval.admin）と稼働（approval.launched）の両方の門番を持つ。並びは bootstrap/app.php の優先順で
+|   「管理 → 稼働」（権限の無い人には、使い始める前でも 403 が先に返る）。
+| ⚠ 詳細の画面（③）の「決裁の管理者の操作」（付け替え・取り消し・代理の取り下げ）の送り先もここ（戻り先は詳細の画面）。
+|
+*/
+Route::middleware(['approval.admin', 'approval.launched'])->prefix('approvals/admin/requests')->name('approvals.admin.requests.')->group(function () {
+    Route::get('/', [AdminRequestController::class, 'index'])->name('index');
+    Route::post('/{approvalRequest}/reassign', [AdminRequestController::class, 'reassign'])->name('reassign');
+    Route::post('/{approvalRequest}/undo', [AdminRequestController::class, 'undo'])->name('undo');
+    Route::post('/{approvalRequest}/withdraw', [AdminRequestController::class, 'withdraw'])->name('withdraw');
+});
+
 /*
 |--------------------------------------------------------------------------
 | 申請を回す画面（段階2。使い始めるまで誰にも見せない）
```

`lang/ja/validation.php`（変更）

```diff
--- a/lang/ja/validation.php
+++ b/lang/ja/validation.php
@@ -607,6 +607,9 @@
         // 判断・条件確認・取り下げ（§5.8）
         'result'            => '判断',
         'comment'           => 'コメント',
+        // 決裁の管理者の操作（2b・§5.14）。⚠ 'reason' は既存の和名（改定理由）なので使わない
+        'admin_reason'      => '理由',
+        'assignee_user_id'  => '付け替え先',
 
     ],
```

- [ ] **Step 4: ⑩ と管理者の操作の送り先を書く**

⚠ 権限と状態は `Workflow`（`RequestPermissions`）が確かめる。ここは入力の形を見て渡すだけ。見られない申請は 404（在るかどうかを漏らさない）。戻り先はいつも詳細（Bug #64）。

`app/Http/Controllers/Approval/AdminRequestController.php`（新規）

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\Approval\Assignees;
use App\Support\Approval\CurrentHandler;
use App\Support\Approval\FormInput;
use App\Support\Approval\PendingWork;
use App\Support\Approval\RequestPermissions;
use App\Support\Approval\RequestVisibility;
use App\Support\Approval\Workflow;
use App\Support\Approval\WorkflowConflict;
use App\Support\Approval\WorkflowRefused;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 進行中の申請の管理（画面⑩・決裁の管理者。段階2 設計書 §5.14）と、詳細の画面（③）の「決裁の管理者の操作」の送り先
 * （部門長の確認の付け替え・押し間違いの取り消し・代理の取り下げ）。
 *
 * ⚠ 門番は approval.admin（403）→ approval.launched（使い始める前は転送・404）の順（bootstrap/app.php の優先順）。
 * ⚠ 権限と状態は Workflow（RequestPermissions）が確かめる。ここは入力の形を見て渡すだけ（判断の操作と同じ）。
 * ⚠ 一覧の件名・申請部門は、最後に提出した控えのもの（差戻し中の直しかけを出さない。D26）。
 * ⚠ 操作の戻り先はいつも詳細の画面（Bug #64）。断られたら、送った小窓を打った中身で開き直す（どの小窓かは送り先で決める。
 *   2b 計画 §0.9）。
 */
class AdminRequestController extends Controller
{
    /** 「決裁済み・否決」の 1 ページの件数 */
    private const DECIDED_PER_PAGE = 20;

    /** 「進行中」に出す状態（§5.14） */
    private const IN_PROGRESS = [
        ApprovalStatus::HeadReview, ApprovalStatus::Review, ApprovalStatus::President,
        ApprovalStatus::Returned, ApprovalStatus::Condition,
    ];

    public function __construct(private readonly Workflow $workflow)
    {
    }

    /** 一覧（タブ: 進行中＝待ち日数の長い順・全件／決裁済み・否決＝決裁日の新しい順。取り消しの入口） */
    public function index(Request $request): View
    {
        $tab = $request->query('tab') === 'decided' ? 'decided' : 'progress';

        // いま誰の番かと印を出すので、段階の担当（部門長・審査担当者・付け替え）と控えを先に読む（N+1。CurrentHandler）
        $with = ['applicant', 'revisions', 'steps.department.head', 'steps.department.reviewers', 'steps.assignee'];

        if ($tab === 'decided') {
            $requests = ApprovalRequest::with($with)
                ->whereIn('status', [ApprovalStatus::Approved->value, ApprovalStatus::Rejected->value])
                ->orderByDesc('decided_at')
                ->orderByDesc('id')
                ->paginate(self::DECIDED_PER_PAGE)
                ->withQueryString();

            return view('approvals.admin.requests', ['tab' => $tab, 'requests' => $requests, 'rows' => null]);
        }

        $presidentId = ApprovalSetting::current()->president_user_id;
        $rows = ApprovalRequest::with($with)
            ->whereIn('status', array_map(fn (ApprovalStatus $status) => $status->value, self::IN_PROGRESS))
            ->get()
            ->map(fn (ApprovalRequest $r) => $this->row($r, $presidentId))
            // 待ち日数の長い順（同じ日数なら先に届いた順）
            ->sort(fn (array $a, array $b) => [$b['days'], $a['since']?->getTimestamp() ?? PHP_INT_MAX, $a['request']->id]
                <=> [$a['days'], $b['since']?->getTimestamp() ?? PHP_INT_MAX, $b['request']->id])
            ->values();

        return view('approvals.admin.requests', ['tab' => $tab, 'requests' => null, 'rows' => $rows]);
    }

    /** 部門長の確認の付け替え（D2・D22・D25） */
    public function reassign(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $this->prepare($request, $approvalRequest, 'reassign');
        $validated = $this->validated($request, $approvalRequest, [
            'assignee_user_id' => ['required', 'integer', Assignees::rule()],
        ], [
            'assignee_user_id.required' => '付け替え先を選んでください。',
            'assignee_user_id.exists'   => '付け替え先は、有効でメールアドレスのある人から選んでください。',
        ]);
        $to = User::findOrFail((int) $validated['assignee_user_id']);

        return $this->run(
            $approvalRequest,
            fn () => $this->workflow->reassignHead($approvalRequest, $request->user(), FormInput::lockVersion($request), $to, $validated['admin_reason']),
            "部門長の確認を{$to->name}さんに付け替えました。",
        );
    }

    /** 押し間違いの取り消し（D3・D21・D24・D25） */
    public function undo(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $this->prepare($request, $approvalRequest, 'undo');
        $validated = $this->validated($request, $approvalRequest);
        // 取り消す前に名前を読んでおく（取り消したあとは次の操作が「最後」になる）
        $target = RequestPermissions::for($request->user(), $approvalRequest)->undoTarget();
        $label  = $target?->label() ?? '直前の操作';

        return $this->run(
            $approvalRequest,
            fn () => $this->workflow->undo($approvalRequest, $request->user(), FormInput::lockVersion($request), $validated['admin_reason']),
            "「{$label}」を取り消しました。",
        );
    }

    /** 代理の取り下げ（要件 4.3 のケース 8・D25） */
    public function withdraw(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $this->prepare($request, $approvalRequest, 'admin_withdraw');
        $validated = $this->validated($request, $approvalRequest);

        return $this->run(
            $approvalRequest,
            fn () => $this->workflow->withdrawByAdmin($approvalRequest, $request->user(), FormInput::lockVersion($request), $validated['admin_reason']),
            '申請者に代わって取り下げました。',
        );
    }

    /**
     * 一覧の 1 行（件名と申請部門は最後に提出した控えのもの。D26）
     *
     * @return array{request: ApprovalRequest, subject: ?string, department: ?string, handler: string, since: ?\DateTimeInterface, days: int, flags: list<string>}
     */
    private function row(ApprovalRequest $r, ?int $presidentId): array
    {
        $snapshot = $r->revisions->firstWhere('round', $r->round)?->snapshot ?? [];
        $waiting  = $r->steps->where('round', $r->round)->firstWhere('status', ApprovalStepStatus::Waiting);
        // 待ち日数の起点は、ホームの対応待ちと同じ（段階が届いた日時・申請者の番は状態が変わった日時。PendingWork・D20）
        $since    = $waiting?->arrived_at ?? $r->status_changed_at;

        return [
            'request'    => $r,
            'subject'    => $snapshot['subject'] ?? null,
            'department' => $snapshot['department']['name'] ?? null,
            'handler'    => CurrentHandler::label($r),
            'since'      => $since,
            'days'       => PendingWork::waitingDays($since),
            'flags'      => $waiting === null ? [] : self::flags($r, $waiting, $presidentId),
        ];
    }

    /**
     * 止まっている理由の印（§5.14）: 担当が申請者本人（自分の申請には判断できない＝D16）・審査担当者が申請者本人しかいない
     *
     * @return list<string>
     */
    private static function flags(ApprovalRequest $r, ApprovalStep $step, ?int $presidentId): array
    {
        return match ($step->kind) {
            ApprovalStepKind::Head => ($step->assignee_user_id ?? $step->department?->head_user_id) === $r->user_id
                ? ['担当が申請者本人'] : [],
            ApprovalStepKind::President => $presidentId === $r->user_id ? ['担当が申請者本人'] : [],
            ApprovalStepKind::Review => self::onlyApplicantReviews($r, $step) ? ['審査担当者が申請者本人しかいない'] : [],
        };
    }

    /** 審査担当者（有効な人）が申請者本人しかいない */
    private static function onlyApplicantReviews(ApprovalRequest $r, ApprovalStep $step): bool
    {
        /** @var Collection<int, User> $active */
        $active = ($step->department?->reviewers ?? collect())->filter(fn (User $u) => $u->status === UserStatus::Active);

        return $active->contains('id', $r->user_id) && $active->where('id', '!=', $r->user_id)->isEmpty();
    }

    /**
     * 見られない申請は 404（在るかどうかを漏らさない）。断られたら開き直す小窓を、送り先で決めて残す（2b 計画 §0.9。
     * 小窓の名前をフォームの値で受け取らないので、状態が進んだあとに古い画面の小窓が断られても、別の小窓は開かない）
     */
    private function prepare(Request $request, ApprovalRequest $approvalRequest, string $modal): void
    {
        abort_unless(RequestVisibility::canView($request->user(), $approvalRequest), 404);
        $request->session()->flash('approval_reopen', $modal);
        FormInput::unifyNewlines($request, 'admin_reason');
    }

    /**
     * 理由（必須・2,000 文字まで。改行はそろえてから数える）と、操作ごとの項目の検査
     *
     * @param array<string, mixed> $rules
     * @param array<string, string> $messages
     * @return array<string, mixed>
     */
    private function validated(Request $request, ApprovalRequest $approvalRequest, array $rules = [], array $messages = []): array
    {
        try {
            return $request->validate(array_merge([
                'admin_reason' => ['required', 'string', 'max:2000'],
            ], $rules), array_merge([
                'admin_reason.required' => '理由を入力してください。',
            ], $messages));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('approvals.requests.show', $approvalRequest));
        }
    }

    /**
     * Workflow を呼び、詳細の画面へ戻す（断られたら理由と打った中身、先を越されたら「すでに処理されています」）
     *
     * @param Closure(): void $action
     */
    private function run(ApprovalRequest $approvalRequest, Closure $action, string $success): RedirectResponse
    {
        $show = redirect()->route('approvals.requests.show', $approvalRequest);

        try {
            $action();
        } catch (WorkflowConflict) {
            return $show->with('error', WorkflowConflict::MESSAGE);
        } catch (WorkflowRefused $e) {
            return $show->withInput()->with('error', implode(' ', $e->reasons));
        }

        return $show->with('success', $success);
    }
}
```

- [ ] **Step 5: 詳細に権限と付け替えの候補を渡す**

`app/Http/Controllers/Approval/RequestController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/RequestController.php
+++ b/app/Http/Controllers/Approval/RequestController.php
@@ -11,6 +11,7 @@
 use App\Models\ApprovalStep;
 use App\Models\ApprovalType;
 use App\Models\User;
+use App\Support\Approval\Assignees;
 use App\Support\Approval\FormInput;
 use App\Support\Approval\RelatedNumbers;
 use App\Support\Approval\RequestContent;
@@ -125,12 +126,17 @@ public function show(Request $request, ApprovalRequest $approvalRequest): View
         $histories = ApprovalHistory::with('actor')->where('request_id', $approvalRequest->id)->orderByDesc('id')->get();
         // 提出の回ごとの控え（履歴）と、直前の回からの変更点（出し直した申請。§5.13）。どちらも提出した控えだけを使う
         // （差戻し中の直しかけは入らない。申請者以外に最後に提出した中身だけを見せる D26 とそろう）
-        $revisions = ApprovalRevision::where('request_id', $approvalRequest->id)->orderBy('round')->get();
+        $revisions   = ApprovalRevision::where('request_id', $approvalRequest->id)->orderBy('round')->get();
+        $permissions = RequestPermissions::for($user, $approvalRequest);
 
         return view('approvals.requests.show', [
             'approvalRequest' => $approvalRequest,
             'content'         => $content,
-            'permissions'     => RequestPermissions::for($user, $approvalRequest),
+            'permissions'     => $permissions,
+            // 部門長の確認の付け替え先の選択肢（付け替えられるときだけ読む。申請者本人は選べない。D2・D7。Assignees）
+            'assigneeCandidates' => $permissions->canReassign()
+                ? Assignees::candidates()->reject(fn (User $candidate) => $candidate->id === $approvalRequest->user_id)->values()
+                : collect(),
             'relatedLinks'    => $this->relatedLinks($content->relatedNumbers, $user),
             'histories'       => $histories,
             'newHeadNames'    => $this->newHeadNames($histories),
@@ -374,8 +380,8 @@ private function relatedLinks(array $numbers, User $user): array
     }
 
     /**
-     * 部門長の交代の記録（head_changed）で担当が移った先の人の名前（id => 名前。Task 19 の C9）。
-     * 記録の横の名前は交代を操作した管理者なので、移った先を別に添える。
+     * 部門長の交代（head_changed）と付け替え（reassigned。2b）の記録で担当が移った先の人の名前（id => 名前。Task 19 の C9）。
+     * 記録の横の名前は操作した管理者なので、移った先を別に添える。
      * ⚠ 論理削除した人も名前を出す（記録は残る）。記録ごとに読まず、1 回の問い合わせで読む
      *
      * @param Collection<int, ApprovalHistory> $histories
@@ -383,7 +389,7 @@ private function relatedLinks(array $numbers, User $user): array
      */
     private function newHeadNames(Collection $histories): array
     {
-        $ids = $histories->where('action', 'head_changed')
+        $ids = $histories->whereIn('action', ['head_changed', 'reassigned'])
             ->map(fn (ApprovalHistory $history) => $history->meta['to_user_id'] ?? null)
             ->filter()
             ->unique()
```

- [ ] **Step 6: ⑩ の画面を書く**（スマホはカード・PC は `scroll-hint` の中の表。ページ送りは `->links()` を使わない）

`resources/views/approvals/admin/requests.blade.php`（新規）

```blade
@extends('layouts.app')

@section('title', '進行中の申請の管理')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">進行中の申請の管理</span>
@endsection

{{-- 進行中の申請の管理（画面⑩・決裁の管理者。段階2 設計書 §5.14）。
     操作（付け替え・押し間違いの取り消し・代理の取り下げ）は各申請の詳細の画面で行う（2b 計画 §0.8）。
     ⚠ 件名・申請部門は最後に提出した控えのもの（差戻し中の直しかけを出さない。D26）。保存した日時は JapanTime::format（Bug #61） --}}
@section('content')
<div>
    <h1 class="text-lg font-bold text-gray-900 mb-2">進行中の申請の管理</h1>
    <p class="text-[12px] text-gray-500 mb-4 max-w-[720px]">止まっている申請を見つけて、詳細の画面で部門長の確認の付け替え・押し間違いの取り消し・申請者に代わっての取り下げを行います。決裁したあとの押し間違いは「決裁済み・否決」から開きます。</p>

    <nav class="flex flex-wrap gap-1.5 mb-4" aria-label="表示の切り替え">
        <a href="{{ route('approvals.admin.requests.index') }}" @if($tab === 'progress') aria-current="page" @endif
           class="px-3 py-1.5 rounded-full text-[12px] font-semibold border {{ $tab === 'progress' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">進行中</a>
        <a href="{{ route('approvals.admin.requests.index', ['tab' => 'decided']) }}" @if($tab === 'decided') aria-current="page" @endif
           class="px-3 py-1.5 rounded-full text-[12px] font-semibold border {{ $tab === 'decided' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">決裁済み・否決</a>
    </nav>

    <div class="bg-white rounded-lg border border-gray-200">
        @if($tab === 'progress')
            @if($rows->isEmpty())
                <p class="px-4 py-8 text-center text-[13px] text-gray-400">進行中の申請はありません。</p>
            @else
                {{-- スマホの幅では 1 件 1 枚のカード（要件 14.4。横スクロールにしない） --}}
                <ul class="md:hidden divide-y divide-gray-100">
                    @foreach($rows as $row)
                        <li>
                            <a href="{{ route('approvals.requests.show', $row['request']) }}" class="block px-4 py-3 hover:bg-gray-50">
                                <div class="flex flex-wrap items-center gap-2 mb-1">
                                    <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $row['request']->status->badgeStyle() }}">{{ $row['request']->statusLabel() }}</span>
                                    <span class="text-[12px] font-semibold text-gray-700">{{ $row['days'] }} 日</span>
                                    @foreach($row['flags'] as $flag)
                                        <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold bg-red-50 text-red-700">{{ $flag }}</span>
                                    @endforeach
                                </div>
                                <p class="text-[14px] font-semibold text-gray-900 break-words">{{ $row['subject'] ?? '（件名なし）' }}</p>
                                <p class="text-[12px] text-gray-500 mt-0.5">{{ $row['request']->applicant->name }}・{{ $row['department'] ?? '—' }}{{ $row['request']->number ? '・' . $row['request']->number : '' }}</p>
                                <p class="text-[12px] text-gray-700 mt-0.5">いま: {{ $row['handler'] }}</p>
                            </a>
                        </li>
                    @endforeach
                </ul>

                <div class="hidden md:block">
                    <div class="scroll-hint at-start">
                        <div class="scroll-hint-inner">
                    <table class="w-full min-w-[900px] border-collapse">
                        <thead>
                            <tr>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">待ち日数</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">決裁No</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">件名</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">申請者</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">申請部門</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">状態</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">いま誰の番か</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-semibold text-gray-900 whitespace-nowrap tabular-nums">{{ $row['days'] }} 日</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-mono text-gray-700 whitespace-nowrap">{{ $row['request']->number ?? '—' }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px]">
                                        <a href="{{ route('approvals.requests.show', $row['request']) }}" class="text-emerald-600 hover:underline break-words">{{ $row['subject'] ?? '（件名なし）' }}</a>
                                    </td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $row['request']->applicant->name }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $row['department'] ?? '—' }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 whitespace-nowrap">
                                        <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $row['request']->status->badgeStyle() }}">{{ $row['request']->statusLabel() }}</span>
                                    </td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">
                                        {{ $row['handler'] }}
                                        @foreach($row['flags'] as $flag)
                                            <span class="ml-1 inline-block px-2 py-0.5 rounded text-[11px] font-semibold bg-red-50 text-red-700">{{ $flag }}</span>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                        </div>{{-- /scroll-hint-inner --}}
                        <div class="scroll-hint-text">← スクロールできます →</div>
                    </div>{{-- /scroll-hint --}}
                </div>
            @endif
        @else
            @if($requests->isEmpty())
                <p class="px-4 py-8 text-center text-[13px] text-gray-400">決裁済み・否決の申請はありません。</p>
            @else
                <ul class="md:hidden divide-y divide-gray-100">
                    @foreach($requests as $item)
                        @php $snapshot = $item->revisions->firstWhere('round', $item->round)?->snapshot ?? []; @endphp
                        <li>
                            <a href="{{ route('approvals.requests.show', $item) }}" class="block px-4 py-3 hover:bg-gray-50">
                                <div class="flex flex-wrap items-center gap-2 mb-1">
                                    <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $item->status->badgeStyle() }}">{{ $item->statusLabel() }}</span>
                                    <span class="text-[12px] font-mono text-gray-600">{{ $item->number }}</span>
                                </div>
                                <p class="text-[14px] font-semibold text-gray-900 break-words">{{ $snapshot['subject'] ?? '（件名なし）' }}</p>
                                <p class="text-[12px] text-gray-500 mt-0.5">{{ $item->applicant->name }}・{{ $snapshot['department']['name'] ?? '—' }}・決裁日 {{ \App\Support\JapanTime::format($item->decided_at, 'Y/m/d') ?? '—' }}</p>
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
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">申請者</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">申請部門</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">状態</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">決裁日</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($requests as $item)
                                @php $snapshot = $item->revisions->firstWhere('round', $item->round)?->snapshot ?? []; @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-mono text-gray-700 whitespace-nowrap">{{ $item->number }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px]">
                                        <a href="{{ route('approvals.requests.show', $item) }}" class="text-emerald-600 hover:underline break-words">{{ $snapshot['subject'] ?? '（件名なし）' }}</a>
                                    </td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $item->applicant->name }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $snapshot['department']['name'] ?? '—' }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 whitespace-nowrap">
                                        <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $item->status->badgeStyle() }}">{{ $item->statusLabel() }}</span>
                                    </td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ \App\Support\JapanTime::format($item->decided_at, 'Y/m/d') ?? '—' }}</td>
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
        @endif
    </div>
</div>
@endsection
```

- [ ] **Step 7: 詳細の「決裁の管理者の操作」を書き、差し込む**（小窓は 3 つ。自分の申請には理由だけ・D3 で押せない取り消しは理由を `aria-describedby` で）

`resources/views/approvals/requests/_admin_actions.blade.php`（新規）

```blade
{{-- 決裁の管理者の操作（部門長の確認の付け替え・押し間違いの取り消し・代理の取り下げ。段階2 設計書 §5.14・2b 計画 §0.8）。
     出すのは RequestPermissions がこの人に「今できる」と判定したものだけ。自分が申請者の申請には出さず、理由を出す（D25）。
     ⚠ 3 つの小窓は描いたときの lock_version を送る（古い画面から押した操作を断る）。確定のボタンは 1 回押したら押せなくする
       （approvalSubmitOnce。定義は _actions が読み込む _submit_once）。理由は必須（D22）
     ⚠ 断られて戻ったら、送った小窓だけを打った中身で開き直す（どの小窓かは送り先がセッションに残す approval_reopen。2b 計画 §0.9） --}}
@php
    $adminRefusal  = $permissions->adminRefusal();
    $canReassign   = $permissions->canReassign();
    $canUndo       = $permissions->canUndo();
    $undoTarget    = $canUndo ? $permissions->undoTarget() : null;
    $undoRefusal   = $canUndo ? $permissions->undoRefusal() : null;
    $canWithdraw   = $permissions->canWithdrawByAdmin();
    $adminModals   = ['reassign', 'undo', 'admin_withdraw'];
    $reopenAdmin   = is_string(old('lock_version')) && in_array(session('approval_reopen'), $adminModals, true) ? session('approval_reopen') : null;
    $adminVersion  = fn (string $modal) => $reopenAdmin === $modal ? old('lock_version') : $approvalRequest->lock_version;
    $adminReason   = fn (string $modal) => $reopenAdmin === $modal && is_string(old('admin_reason')) ? old('admin_reason') : '';
    $adminRefused  = $reopenAdmin !== null ? ($errors->any() ? implode(' ', $errors->all()) : (string) session('error')) : '';
    $showsAdminRow = in_array($approvalRequest->status, [
        \App\Enums\ApprovalStatus::HeadReview, \App\Enums\ApprovalStatus::Review, \App\Enums\ApprovalStatus::President,
        \App\Enums\ApprovalStatus::Returned, \App\Enums\ApprovalStatus::Condition,
        \App\Enums\ApprovalStatus::Approved, \App\Enums\ApprovalStatus::Rejected,
    ], true);
@endphp

@if($adminRefusal !== null && $showsAdminRow)
    <p class="mb-5 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-[12px] text-gray-600">{{ $adminRefusal }}</p>
@elseif($canReassign || $canUndo || $canWithdraw)
    <section class="bg-white rounded-lg border border-gray-200 mb-5 px-5 py-4" x-data="{ adminModal: {{ \Illuminate\Support\Js::from($reopenAdmin) }} }">
        <h2 class="text-[14px] font-bold text-gray-900 mb-1">決裁の管理者の操作</h2>
        <p class="text-[12px] text-gray-500 mb-3">どの操作も理由が必要で、記録に残ります。</p>
        <div class="flex flex-wrap items-center gap-2">
            @if($canReassign)
                <button type="button" @click="adminModal = 'reassign'" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-800 hover:bg-gray-50 cursor-pointer">部門長の確認を付け替える</button>
            @endif
            @if($canUndo)
                @if($undoRefusal !== null)
                    {{-- 差戻しのあと申請者が直し始めていたら取り消せない（D3）。押せないボタンは span で包んで理由を付ける（Bug #43） --}}
                    <span title="{{ $undoRefusal }}" style="display: inline-flex;">
                        <button type="button" disabled aria-describedby="undo-refusal" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-400 cursor-not-allowed">直前の操作を取り消す</button>
                    </span>
                @else
                    <button type="button" @click="adminModal = 'undo'" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-800 hover:bg-gray-50 cursor-pointer">直前の操作を取り消す</button>
                @endif
            @endif
            @if($canWithdraw)
                <button type="button" @click="adminModal = 'admin_withdraw'" class="px-4 py-2 bg-white border border-red-200 rounded-md text-[13px] font-semibold text-red-600 hover:bg-red-50 cursor-pointer">申請者に代わって取り下げる</button>
            @endif
        </div>
        @if($canUndo && $undoRefusal !== null)
            <p id="undo-refusal" class="mt-2 text-[12px] text-amber-800">{{ $undoRefusal }}</p>
        @endif

        @if($canReassign)
            <div x-show="adminModal === 'reassign'" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
                <div @click.outside="adminModal = null" class="bg-white rounded-xl w-full max-w-[480px] shadow-xl mx-4">
                    <form method="POST" action="{{ route('approvals.admin.requests.reassign', $approvalRequest) }}"
                          x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                        @csrf
                        <input type="hidden" name="lock_version" value="{{ $adminVersion('reassign') }}">
                        <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">部門長の確認を付け替えますか？</div>
                        @if($reopenAdmin === 'reassign' && $adminRefused !== '')
                            <p class="mx-6 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-700">{{ $adminRefused }}</p>
                        @endif
                        <div class="px-6 py-4 space-y-3">
                            <p class="text-[12px] text-gray-500">いまの担当: {{ \App\Support\Approval\CurrentHandler::describe($permissions->waitingStep()) }}。付け替えた人が承認・差戻しをします。あとで部門の管理で部門長を変えると、新しい部門長へ移ります。</p>
                            <div>
                                <label for="reassign-to" class="block text-[12px] font-semibold text-gray-700 mb-1">付け替え先<span class="text-red-600 ml-0.5">*</span></label>
                                <select id="reassign-to" name="assignee_user_id" required class="w-full h-9 px-2.5 border border-gray-300 rounded-md text-[13px] bg-white">
                                    <option value="">選んでください</option>
                                    @foreach($assigneeCandidates as $candidate)
                                        <option value="{{ $candidate->id }}" @selected($reopenAdmin === 'reassign' && (string) old('assignee_user_id') === (string) $candidate->id)>{{ $candidate->name }}{{ $candidate->employee_number ? '（' . $candidate->employee_number . '）' : '' }}{{ $candidate->mail_allowed ? '' : ' ※通知メールが届きません' }}</option>
                                    @endforeach
                                </select>
                                <p class="text-[11px] text-gray-400 mt-1">有効でメールアドレスのある人から選びます（申請者本人は選べません）。</p>
                            </div>
                            <div>
                                <label for="reassign-reason" class="block text-[12px] font-semibold text-gray-700 mb-1">理由<span class="text-red-600 ml-0.5">*</span></label>
                                <textarea id="reassign-reason" name="admin_reason" rows="3" maxlength="2000" required class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ $adminReason('reassign') }}</textarea>
                            </div>
                        </div>
                        <div class="px-6 pb-5 flex flex-wrap items-center justify-end gap-2">
                            <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                            <button type="button" @click="adminModal = null" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                            <button type="submit" :disabled="submitting" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">付け替える</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        @if($canUndo && $undoRefusal === null)
            <div x-show="adminModal === 'undo'" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
                <div @click.outside="adminModal = null" class="bg-white rounded-xl w-full max-w-[480px] shadow-xl mx-4">
                    <form method="POST" action="{{ route('approvals.admin.requests.undo', $approvalRequest) }}"
                          x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                        @csrf
                        <input type="hidden" name="lock_version" value="{{ $adminVersion('undo') }}">
                        <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">直前の操作を取り消しますか？</div>
                        @if($reopenAdmin === 'undo' && $adminRefused !== '')
                            <p class="mx-6 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-700">{{ $adminRefused }}</p>
                        @endif
                        <div class="px-6 py-4 space-y-3">
                            <p class="text-[13px] text-gray-900">
                                取り消す操作: <span class="font-semibold">{{ $undoTarget->label() }}</span>
                                <span class="text-gray-600">{{ $undoTarget->actor?->name }}</span>
                                <span class="text-[12px] text-gray-400">{{ \App\Support\JapanTime::format($undoTarget->created_at) }}</span>
                            </p>
                            <p class="text-[12px] text-gray-500">
                                その操作の前の状態に戻します。元の記録は消えず、取り消したことが記録に残ります。続けて取り消すと、もう 1 つ前の操作にさかのぼります（提出の手前まで）。
                                @if($approvalRequest->number)
                                    決裁No（{{ $approvalRequest->number }}）はこの申請に残り、次に社長が判断したときにそのまま使います。
                                @endif
                            </p>
                            <div>
                                <label for="undo-reason" class="block text-[12px] font-semibold text-gray-700 mb-1">理由<span class="text-red-600 ml-0.5">*</span></label>
                                <textarea id="undo-reason" name="admin_reason" rows="3" maxlength="2000" required class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ $adminReason('undo') }}</textarea>
                            </div>
                        </div>
                        <div class="px-6 pb-5 flex flex-wrap items-center justify-end gap-2">
                            <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                            <button type="button" @click="adminModal = null" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                            <button type="submit" :disabled="submitting" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">取り消す</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        @if($canWithdraw)
            <div x-show="adminModal === 'admin_withdraw'" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
                <div @click.outside="adminModal = null" class="bg-white rounded-xl w-full max-w-[480px] shadow-xl mx-4">
                    <form method="POST" action="{{ route('approvals.admin.requests.withdraw', $approvalRequest) }}"
                          x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                        @csrf
                        <input type="hidden" name="lock_version" value="{{ $adminVersion('admin_withdraw') }}">
                        <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">申請者に代わって取り下げますか？</div>
                        @if($reopenAdmin === 'admin_withdraw' && $adminRefused !== '')
                            <p class="mx-6 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-700">{{ $adminRefused }}</p>
                        @endif
                        <div class="px-6 py-4 space-y-3">
                            <p class="text-[12px] text-gray-500">申請者の退職などで、申請者が取り下げられないときに使います。取り下げると回覧が止まり、元に戻せません。{{ $approvalRequest->number ? '決裁No はそのまま残ります。' : '' }}</p>
                            <div>
                                <label for="admin-withdraw-reason" class="block text-[12px] font-semibold text-gray-700 mb-1">理由<span class="text-red-600 ml-0.5">*</span></label>
                                <textarea id="admin-withdraw-reason" name="admin_reason" rows="3" maxlength="2000" required class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ $adminReason('admin_withdraw') }}</textarea>
                            </div>
                        </div>
                        <div class="px-6 pb-5 flex flex-wrap items-center justify-end gap-2">
                            <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                            <button type="button" @click="adminModal = null" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                            <button type="submit" :disabled="submitting" class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">取り下げる</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </section>

    {{-- 確定の二度押し止めの部品（定義は 1 か所。@once なので _actions と重ねて読み込んでも 1 回だけ出る） --}}
    @include('approvals._submit_once')
@endif
```

`resources/views/approvals/requests/show.blade.php`（変更）

```diff
--- a/resources/views/approvals/requests/show.blade.php
+++ b/resources/views/approvals/requests/show.blade.php
@@ -42,6 +42,8 @@
 
     @include('approvals.requests._actions')
 
+    @include('approvals.requests._admin_actions')
+
     <section class="bg-white rounded-lg border border-gray-200 mb-5">
         <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">申請の中身</h2>
         <dl class="px-5 py-4 grid grid-cols-1 sm:grid-cols-[9em_1fr] gap-x-4 gap-y-2 text-[13px]">
@@ -111,14 +113,18 @@
                         @if($history->actor)
                             <span class="text-gray-600">{{ $history->actor->name }}</span>
                         @endif
-                        {{-- 部門長の交代は、担当が移った先の人を添える（横の名前は交代を操作した管理者。Task 19 の C9） --}}
-                        @if($history->action === 'head_changed' && isset($newHeadNames[$history->meta['to_user_id'] ?? 0]))
+                        {{-- 部門長の交代・付け替えは、担当が移った先の人を添える（横の名前は操作した管理者。Task 19 の C9・2b） --}}
+                        @if(in_array($history->action, ['head_changed', 'reassigned'], true) && isset($newHeadNames[$history->meta['to_user_id'] ?? 0]))
                             <span class="text-gray-600">新しい担当: {{ $newHeadNames[$history->meta['to_user_id']] }}</span>
                         @endif
                     </div>
                     @if($history->comment)
                         <p class="mt-0.5 text-gray-700 whitespace-pre-wrap break-words">{{ $history->comment }}</p>
                     @endif
+                    {{-- 決裁の管理者の操作（付け替え・取り消し・代理の取り下げ）の理由（2b・設計書 §5.14） --}}
+                    @if($history->reason)
+                        <p class="mt-0.5 text-gray-700 whitespace-pre-wrap break-words"><span class="text-gray-500">理由:</span> {{ $history->reason }}</p>
+                    @endif
                 </li>
             @empty
                 <li class="text-gray-400">まだ記録はありません（提出から記録します）。</li>
```

- [ ] **Step 8: m-2 の文と、届いた日時を待ちの段階だけに出す**

`resources/views/approvals/requests/_actions.blade.php`（変更）

```diff
--- a/resources/views/approvals/requests/_actions.blade.php
+++ b/resources/views/approvals/requests/_actions.blade.php
@@ -200,7 +200,7 @@ function approvalJudge(choices) {
         {{-- 2 行目は段階ごとの次の手（Task 19 の C7。社長の指定を変えられるのは基幹の管理者だけ。要件 3.2・4.7） --}}
         <p class="text-[12px] text-amber-800 mb-3">{{ match ($waiting->kind) {
             \App\Enums\ApprovalStepKind::Review    => 'ほかの審査担当者が判断します。',
-            \App\Enums\ApprovalStepKind::Head      => '取り下げて出し直すか、決裁の管理者に相談してください。',
+            \App\Enums\ApprovalStepKind::Head      => '取り下げて出し直すか、決裁の管理者に部門長の確認の付け替えを頼んでください。',
             \App\Enums\ApprovalStepKind::President => '社長の指定を変えられるのは基幹の管理者です。急ぐときは取り下げてください。',
         } }}</p>
         <div class="flex flex-wrap gap-2">
```

`resources/views/approvals/requests/_steps.blade.php`（変更）

```diff
--- a/resources/views/approvals/requests/_steps.blade.php
+++ b/resources/views/approvals/requests/_steps.blade.php
@@ -31,7 +31,8 @@
                                 @break
                             @default
                                 <span class="text-gray-700">担当: {{ \App\Support\Approval\CurrentHandler::describe($step) }}</span>
-                                @if($step->arrived_at)
+                                {{-- 届いた日時は待ちの段階だけに出す（取り消しで「まだ届いていない」に戻した段階は届いた日時が残る。2b 計画 §0.4） --}}
+                                @if($step->status === \App\Enums\ApprovalStepStatus::Waiting && $step->arrived_at)
                                     <span class="text-[12px] text-gray-400">{{ \App\Support\JapanTime::format($step->arrived_at) }} に届きました</span>
                                 @endif
                         @endswitch
```

- [ ] **Step 9: サイドバーに ⑩ のリンクを足す**（使い始めてから・決裁の管理者に。基幹の全画面で読むので、設定の行を作らない `launchedForMenu()` を使う）

`app/Models/ApprovalSetting.php`（変更）

```diff
--- a/app/Models/ApprovalSetting.php
+++ b/app/Models/ApprovalSetting.php
@@ -60,6 +60,22 @@ public static function current(): self
         return $row;
     }
 
+    /**
+     * 使い始めたか（基幹の画面のサイドバー・ダッシュボード用。2b）。覚えた設定があればそれを使い、無ければ行を作らずに読むだけ
+     * （覚えもしない）。
+     *
+     * ⚠ 基幹の画面ではこちらを使う。current() は行が無ければ作ってコンテナに覚えるので、1 本のテストで画面を 2 回開くと
+     *   1 回目だけ問い合わせが増え、「件数によらず問い合わせの本数が同じ」を見るテスト（PropertyListSortTest）が食い違う（2b で実測）
+     */
+    public static function launchedForMenu(): bool
+    {
+        if (app()->bound(self::CONTAINER_KEY)) {
+            return app(self::CONTAINER_KEY)->isLaunched();
+        }
+
+        return static::whereKey(self::SINGLETON_ID)->whereNotNull('launched_at')->exists();
+    }
+
     /** 覚えておいた設定を捨てる（インスタンスを通さずに書き換えたとき用） */
     public static function forget(): void
     {
```

`resources/views/layouts/partials/sidebar.blade.php`（変更）

```diff
--- a/resources/views/layouts/partials/sidebar.blade.php
+++ b/resources/views/layouts/partials/sidebar.blade.php
@@ -25,6 +25,9 @@ class="fixed inset-0 bg-black/50 z-20 lg:hidden"
     // 決裁の管理者に指定された人だけ「決裁の管理」を出す（設計書 §5.15・D2）。
     // 指定されていない人の画面は変わらない（段階1 で一般の利用者に見える変化はログイン画面だけ）。
     $isApprovalAdmin = $user->isApprovalAdmin();
+    // 進行中の申請の管理へのリンクは、決裁の管理者に・使い始めてから（段階2 設計書 §5.2・D1）。
+    // ⚠ 基幹の画面は launchedForMenu()（行を作らず読むだけ。問い合わせは決裁の管理者の画面だけ）
+    $approvalsLaunched = $isApprovalAdmin && \App\Models\ApprovalSetting::launchedForMenu();
 @endphp
 
 {{-- ========== PC用: 展開サイドバー ========== --}}
@@ -152,6 +155,9 @@ class="inline-flex items-center gap-1 px-2 py-1 rounded-md border border-gray-30
             <x-sidebar-item :href="route('approvals.admin.users.index')" label="利用者の管理" :active="request()->routeIs('approvals.admin.users.*')" />
             <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
             <x-sidebar-item :href="route('approvals.admin.types.index')" label="申請種類の管理" :active="request()->routeIs('approvals.admin.types.*')" />
+            @if($approvalsLaunched)
+                <x-sidebar-item :href="route('approvals.admin.requests.index')" label="進行中の申請の管理" :active="request()->routeIs('approvals.admin.requests.*')" />
+            @endif
         </x-sidebar-group>
     @endif
 
@@ -448,6 +454,9 @@ class="fixed inset-y-0 left-0 z-30 w-[260px] bg-white overflow-y-auto pt-4 pb-6
             <x-sidebar-item :href="route('approvals.admin.users.index')" label="利用者の管理" :active="request()->routeIs('approvals.admin.users.*')" />
             <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
             <x-sidebar-item :href="route('approvals.admin.types.index')" label="申請種類の管理" :active="request()->routeIs('approvals.admin.types.*')" />
+            @if($approvalsLaunched)
+                <x-sidebar-item :href="route('approvals.admin.requests.index')" label="進行中の申請の管理" :active="request()->routeIs('approvals.admin.requests.*')" />
+            @endif
         </x-sidebar-group>
     @endif
```

`resources/views/layouts/partials/sidebar_approval.blade.php`（変更）

```diff
--- a/resources/views/layouts/partials/sidebar_approval.blade.php
+++ b/resources/views/layouts/partials/sidebar_approval.blade.php
@@ -37,6 +37,9 @@ class="inline-flex items-center gap-1 px-2 py-1 rounded-md border border-gray-30
             <x-sidebar-item :href="route('approvals.admin.users.index')" label="利用者の管理" :active="request()->routeIs('approvals.admin.users.*')" />
             <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
             <x-sidebar-item :href="route('approvals.admin.types.index')" label="申請種類の管理" :active="request()->routeIs('approvals.admin.types.*')" />
+            @if($approvalsLaunched)
+                <x-sidebar-item :href="route('approvals.admin.requests.index')" label="進行中の申請の管理" :active="request()->routeIs('approvals.admin.requests.*')" />
+            @endif
         @endif
     </div>
 </aside>
@@ -79,5 +82,8 @@ class="p-1 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transi
         <x-sidebar-item :href="route('approvals.admin.users.index')" label="利用者の管理" :active="request()->routeIs('approvals.admin.users.*')" />
         <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
         <x-sidebar-item :href="route('approvals.admin.types.index')" label="申請種類の管理" :active="request()->routeIs('approvals.admin.types.*')" />
+        @if($approvalsLaunched)
+            <x-sidebar-item :href="route('approvals.admin.requests.index')" label="進行中の申請の管理" :active="request()->routeIs('approvals.admin.requests.*')" />
+        @endif
     @endif
 </aside>
```

- [ ] **Step 10: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (72 tests, …)`

- [ ] **Step 11: 全件を流す**

Expected: `OK (2852 tests, 19935 assertions)`（`PropertyListSortTest` の問い合わせの数・`LayoutSidebarCloakTest`・`MobileLayoutTest`・`StoredTimestampDisplayScanTest`・`JapaneseValidationMessagesTest` も緑）

- [ ] **Step 12: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b add app/Http/Controllers/Approval/AdminRequestController.php app/Http/Controllers/Approval/RequestController.php app/Models/ApprovalSetting.php lang/ja/validation.php resources/views/approvals/admin/requests.blade.php resources/views/approvals/requests/_actions.blade.php resources/views/approvals/requests/_admin_actions.blade.php resources/views/approvals/requests/_steps.blade.php resources/views/approvals/requests/show.blade.php resources/views/layouts/partials/sidebar.blade.php resources/views/layouts/partials/sidebar_approval.blade.php routes/approval.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase2/AdminRequestsTest.php tests/Feature/Approval/Phase2/LaunchGateTest.php tests/Feature/Approval/Phase2/RequestActionTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b commit -m "$(cat <<'MSG'
feat(approval): 進行中の申請の管理（⑩）と詳細の管理者の操作を足す

⑩ は進行中（待ち日数の長い順・止まっている理由の印）と決裁済み・否決の 2 つの
タブ。付け替え・押し間違いの取り消し・代理の取り下げは詳細の画面から、理由を
付けて行う（設計書 §5.14）。管理と稼働の両方の門番の内側に置く。部門長の段階の
押せない理由に付け替えを案内する（2a の点検 m-2）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 6: 関連する決裁No の候補（D26・§5.16）

社長の判断（可・条可・否）を取り消すと番号が残る（D21）ので、そのあと社長が差し戻すと、番号を持ったまま差戻し中になる。候補の検索は件名で当てて件名を返すので、**差戻し中の申請は最後に提出した控えの件名**で当てて返す（直しかけの件名を申請者以外に漏らさない。§5.16 の最後の項目）。ほかの状態は今の件名（2a のまま）。

**Files:**
- Modify: `app/Http/Controllers/Approval/RelatedNumberController.php`
- Test: Modify `tests/Feature/Approval/Phase2/RelatedNumberSearchTest.php`（1 本足す）

**Interfaces:** なし（応答の形は 2a と同じ `{"items": [{"number", "subject"}]}`）

**差分の大きさ:** 2 ファイル・+58 / −4 行（差分のファイル `0009-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/RelatedNumberSearchTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/RelatedNumberSearchTest.php
+++ b/tests/Feature/Approval/Phase2/RelatedNumberSearchTest.php
@@ -138,6 +138,35 @@ public function test_a_request_without_a_number_is_not_a_candidate_even_when_vis
         }
     }
 
+    /**
+     * 取り消しで番号を残したまま差戻しに戻った申請は、最後に提出した控えの件名で当て、控えの件名を返す（直しかけを漏らさない。
+     * D26・設計書 §5.16・2b 計画 Task 6）。社長の可 → 管理者の取り消し → 社長の差戻し → 申請者が件名を直す
+     */
+    public function test_a_returned_request_with_a_number_shows_its_submitted_subject(): void
+    {
+        $w = $this->approvalWorld();
+        $this->launchApprovals();
+        $workflow = app(Workflow::class);
+        $admin    = $this->approvalAdmin();
+        $r        = $this->submittedFor($w, ['subject' => '提出した件名']);
+        foreach ([[$w['head'], 'judgeHead', ApprovalStepResult::Approve], [$w['reviewer'], 'judgeReview', ApprovalStepResult::Ok], [$w['president'], 'judgePresident', ApprovalStepResult::Approve]] as [$who, $method, $result]) {
+            $r->refresh();
+            $workflow->{$method}($r, $who, $r->lock_version, $result, null);
+        }
+        $r->refresh();
+        $workflow->undo($r, $admin, $r->lock_version, '押し間違い');
+        $r->refresh();
+        $workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Return, '直してください');
+        DB::table('approval_requests')->where('id', $r->id)->update(['subject' => '直しかけの件名']);   // 申請者が保存した直しかけ
+        $this->assertSame('R8-J-001', $r->fresh()->number, '前提: 番号が残ったまま差戻し中');
+
+        foreach ([$w['head'], $this->viewAllUser(), $admin] as $user) {
+            $this->search($user, '直しかけ')->assertOk()->assertExactJson(['items' => []]);
+            $this->search($user, '提出した')->assertOk()->assertExactJson(['items' => [['number' => 'R8-J-001', 'subject' => '提出した件名']]]);
+            $this->search($user, 'R8-J-001')->assertOk()->assertExactJson(['items' => [['number' => 'R8-J-001', 'subject' => '提出した件名']]]);
+        }
+    }
+
     /** 件名は入力のまま探す（全角・途中の空白を含む件名にも当たる。番号のそろえ方を件名に使わない） */
     public function test_the_subject_is_matched_as_typed(): void
     {
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2b && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase2/RelatedNumberSearchTest.php
```

Expected: `FAILURES!` `Tests: 9, Assertions: 39, Failures: 1.`

- `RelatedNumberSearchTest::test_a_returned_request_with_a_number_shows_its_submitted_subject`

- [ ] **Step 3: 差戻し中は控えの件名で当てて返す**

`app/Http/Controllers/Approval/RelatedNumberController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/RelatedNumberController.php
+++ b/app/Http/Controllers/Approval/RelatedNumberController.php
@@ -2,11 +2,14 @@
 
 namespace App\Http\Controllers\Approval;
 
+use App\Enums\ApprovalStatus;
 use App\Http\Controllers\Controller;
 use App\Models\ApprovalRequest;
+use App\Models\ApprovalRevision;
 use App\Support\Approval\RelatedNumbers;
 use App\Support\Approval\RequestVisibility;
 use Illuminate\Database\Eloquent\Builder;
+use Illuminate\Database\Query\Builder as QueryBuilder;
 use Illuminate\Http\JsonResponse;
 use Illuminate\Http\Request;
 
@@ -17,6 +20,9 @@
  * ⚠ 呼ぶ側の fetch は X-Requested-With を付ける（Bug #35。付けないとセッションの直前 URL が
  *   この JSON で上書きされる）。
  * ⚠ LIKE の % と _ は逃がさない（アプリのほかの検索と同じ。見られる範囲の中で候補が広がるだけ）。
+ * ⚠ 差戻し中の申請は、最後に提出した控えの件名で当て、控えの件名を返す（取り消しで番号を残したまま差戻しに戻った申請の
+ *   直しかけを、ほかの人に漏らさない。D26・設計書 §5.16・2b 計画 Task 6）。ほかの状態は中身を直せないので、今の件名が
+ *   最後に提出した件名と同じ。
  */
 class RelatedNumberController extends Controller
 {
@@ -38,14 +44,33 @@ public function search(Request $request): JsonResponse
         // 番号は全角・小文字でも当たるようにそろえる。件名は入力のまま探す
         $number = RelatedNumbers::normalize($text);
 
-        $items = RequestVisibility::apply(ApprovalRequest::query(), $request->user())
+        $returned = ApprovalStatus::Returned->value;
+        $found    = RequestVisibility::apply(ApprovalRequest::query(), $request->user())
             ->whereNotNull('number')
-            ->where(fn (Builder $q) => $q->where('number', 'like', "%{$number}%")->orWhere('subject', 'like', "%{$text}%"))
+            ->where(fn (Builder $q) => $q
+                ->where('number', 'like', "%{$number}%")
+                ->orWhere(fn (Builder $q) => $q->where('status', '!=', $returned)->where('subject', 'like', "%{$text}%"))
+                ->orWhere(fn (Builder $q) => $q->where('status', $returned)->whereExists(fn (QueryBuilder $s) => $s
+                    ->selectRaw('1')->from('approval_revisions')
+                    ->whereColumn('approval_revisions.request_id', 'approval_requests.id')
+                    ->whereColumn('approval_revisions.round', 'approval_requests.round')
+                    ->where('approval_revisions.snapshot->subject', 'like', "%{$text}%"))))
             ->orderByDesc('decided_at')
             ->orderByDesc('id')
             ->limit(self::LIMIT)
-            ->get(['number', 'subject'])
-            ->map(fn (ApprovalRequest $found) => ['number' => $found->number, 'subject' => $found->subject])
+            ->get(['id', 'number', 'subject', 'status', 'round']);
+
+        // 差戻し中の申請は、最後に提出した控えの件名を返す（1 回の問い合わせで読む）
+        $submitted = ApprovalRevision::whereIn('request_id', $found->where('status', ApprovalStatus::Returned)->pluck('id')->all())
+            ->get(['request_id', 'round', 'snapshot'])
+            ->mapWithKeys(fn (ApprovalRevision $revision) => ["{$revision->request_id}:{$revision->round}" => $revision->snapshot['subject'] ?? null])
+            ->all();
+
+        $items = $found
+            ->map(fn (ApprovalRequest $r) => [
+                'number'  => $r->number,
+                'subject' => $r->status === ApprovalStatus::Returned ? ($submitted["{$r->id}:{$r->round}"] ?? null) : $r->subject,
+            ])
             ->all();
 
         return response()->json(['items' => $items]);
```

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (9 tests, …)`

- [ ] **Step 5: 全件を流す**

Expected: `OK (2853 tests, 19954 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b add app/Http/Controllers/Approval/RelatedNumberController.php tests/Feature/Approval/Phase2/RelatedNumberSearchTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b commit -m "$(cat <<'MSG'
fix(approval): 差戻し中の申請の候補は最後に提出した件名で出す

取り消しで番号を残したまま差戻しに戻った申請は、関連する決裁No の候補が
直しかけの件名を返していた。差戻し中は最後に提出した控えの件名で当てて返す
（D26・設計書 §5.16）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 7: 基幹のメニューとダッシュボードの件数（§5.15）

使い始めたあと、基幹の画面から決裁の対応待ちに気づけるようにする（§0.10）。件数は `PendingWork` と同じ条件を 1 リクエストに 1 回だけ数える。

**Files:**
- Create: `app/Support/Approval/ApprovalMenu.php`・`resources/views/approvals/_pending_card.blade.php`
- Modify: `app/Support/Approval/PendingWork.php`（条件を 2 つの問い合わせに分け、`countFor()` を足す）・`resources/views/components/sidebar-item.blade.php`（件数の丸）・`resources/views/layouts/partials/sidebar.blade.php`・`sidebar_approval.blade.php`・`resources/views/dashboard/executive.blade.php`・`tenant.blade.php`
- Test: Create `tests/Feature/Approval/Phase2/ApprovalMenuTest.php`・Modify `tests/Feature/Approval/Phase2/PendingWorkTest.php`（突き合わせに `countFor()` を足し、管理者の操作のあとの場面を 1 本）

**Interfaces:**
- Consumes: `ApprovalSetting::launchedForMenu()`（Task 5）・2b の管理者の操作（Task 4。突き合わせのテスト）
- Produces: `PendingWork::countFor(User $user): int`／`ApprovalMenu::pendingCount(User $user): ?int`（使い始める前は `null`。リクエストの attributes の `approval.pending_count.{id}` に置く）／`<x-sidebar-item :badge="…">`（`null`・0 は出さない）

**差分の大きさ:** 10 ファイル・+316 / −31 行（差分のファイル `0010-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase2/ApprovalMenuTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\CreatesMansionSchema;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 基幹のメニューとダッシュボードの「決裁」と対応待ちの件数（段階2 設計書 §5.15・要件 12.2・15.2・2b 計画 Task 7）。
 *
 * ⚠ サイドバーは 3 か所（展開・折りたたみ・スマホのドロワー）を別々に見る（ページ全体を 1 回見るだけだと、
 *   1 か所を丸ごと消しても緑のまま通る。ApprovalSidebarTest と同じ理由）。
 */
class ApprovalMenuTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use CreatesMansionSchema;
    use CreatesRealEstateSchema;

    /** @return array{expanded: string, rail: string, drawer: string} */
    private function sidebars(string $html): array
    {
        $out = [];
        foreach (['expanded' => 'sidebarExpanded', 'rail' => '!sidebarExpanded', 'drawer' => 'sidebarOpen'] as $key => $xShow) {
            $this->assertSame(1, preg_match('#<aside\b[^>]*\bx-show="' . preg_quote($xShow, '#') . '"[^>]*>(.*?)</aside>#s', $html, $m), "{$key} の <aside> が 1 本に定まらない");
            $out[$key] = $m[1];
        }

        return $out;
    }

    private function html(User $user, string $url): string
    {
        return (string) $this->actingAs($user)->get($url)->assertOk()->getContent();
    }

    /** 部門長に 2 件の対応待ちがある組織 */
    private function worldWithTwoWaiting(): array
    {
        $w = $this->approvalWorld();
        $this->submittedFor($w);
        $this->submittedFor($w);

        return $w;
    }

    public function test_nothing_is_added_before_launch(): void
    {
        $w = $this->worldWithTwoWaiting();

        $html = $this->html($w['head'], '/dashboard/tenant');

        $this->assertStringNotContainsString('決裁', $html, '使い始める前に基幹の画面へ決裁が出た（D1）');
    }

    public function test_the_base_sidebar_shows_the_count_in_all_three_places(): void
    {
        $w = $this->worldWithTwoWaiting();
        $this->launchApprovals();

        $sidebars = $this->sidebars($this->html($w['head'], '/dashboard/tenant'));

        $badge = '<span class="sr-only">対応待ち </span>2<span class="sr-only"> 件</span>';
        foreach (['expanded', 'drawer'] as $key) {
            $this->assertMatchesRegularExpression('#<a\s+href="' . preg_quote(route('approvals.home'), '#') . '"[^>]*>\s*決裁\s*<span[^>]*>' . preg_quote($badge, '#') . '</span>#u', $sidebars[$key], "{$key} に「決裁」と件数が無い");
        }
        $this->assertStringContainsString('title="決裁（対応待ち 2 件）"', $sidebars['rail']);
        $this->assertMatchesRegularExpression('#<span class="absolute[^"]*"[^>]*aria-hidden="true">2</span>#', $sidebars['rail'], '折りたたみ版に件数の丸印が無い');
    }

    public function test_no_badge_when_nothing_is_waiting(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $html     = $this->html($w['head'], '/dashboard/tenant');
        $sidebars = $this->sidebars($html);

        $this->assertMatchesRegularExpression('#<a\s+href="' . preg_quote(route('approvals.home'), '#') . '"[^>]*>\s*決裁\s*</a>#u', $sidebars['expanded'], '件数が 0 でも「決裁」は出す（丸印は出さない）');
        $this->assertStringNotContainsString('対応待ち ', $sidebars['expanded']);
        $this->assertStringContainsString('決裁の対応待ち</span> <span class="font-bold tabular-nums">0</span> 件', $html, 'ダッシュボードには 0 件と出す');
    }

    public function test_both_dashboards_link_to_the_approval_home_with_the_count(): void
    {
        // 経営ダッシュボードは賃貸マンション（ms_*）・不動産と住宅（re_*・hs_*）も読む（本番は raw SQL の表）
        $this->createMansionSchema();
        $this->createRealEstateSchema();
        $w         = $this->worldWithTwoWaiting();
        $executive = $this->baseUser(['name' => '経営 太郎', 'role' => UserRole::Executive->value]);
        $w['dept']->update(['head_user_id' => $executive->id]);   // 経営層も部門長として対応待ちを持つ
        $this->launchApprovals();

        foreach (['/dashboard/executive' => $executive, '/dashboard/tenant' => $w['head']] as $url => $user) {
            $html = $this->html($user, $url);
            $card = substr($html, strpos($html, 'href="' . route('approvals.home') . '" class="mb-4'));
            $card = substr($card, 0, strpos($card, '</a>'));
            $expected = $user->is($executive) ? '2' : '0';
            $this->assertStringContainsString('決裁の対応待ち</span> <span class="font-bold tabular-nums">' . $expected . '</span> 件', $card, "{$url} のカード");
            $this->assertStringContainsString('決裁のホームへ', $card);
        }
    }

    /** 件数は 1 リクエストに 1 回だけ数える（サイドバー 3 か所とダッシュボードで数え直さない。§5.15） */
    public function test_the_count_is_computed_once_per_request(): void
    {
        $w = $this->worldWithTwoWaiting();
        $this->launchApprovals();
        $counts = 0;
        DB::listen(function ($query) use (&$counts): void {
            if (str_contains($query->sql, 'count(*)') && str_contains($query->sql, 'approval_steps')) {
                $counts++;
            }
        });

        $this->html($w['head'], '/dashboard/tenant');

        $this->assertSame(1, $counts, '対応待ちの段階を数える問い合わせが 1 回でない');
    }

    public function test_the_approval_only_sidebar_shows_the_count_on_the_home_link(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        app(\App\Support\Approval\Workflow::class)->judgeHead($request, $w['head'], $request->lock_version, \App\Enums\ApprovalStepResult::Return, '直してください');

        $sidebars = $this->sidebars($this->html($w['applicant'], route('approvals.home')));

        foreach (['expanded', 'drawer'] as $key) {
            $this->assertStringContainsString('決裁のホーム', $sidebars[$key]);
            $this->assertStringContainsString('<span class="sr-only">対応待ち </span>1<span class="sr-only"> 件</span>', $sidebars[$key], "{$key} に件数が無い（申請者の差戻しの対応）");
        }
        $this->assertMatchesRegularExpression('#title="決裁のホーム"[^>]*>決<span[^>]*aria-hidden="true">1</span></a>#u', $sidebars['rail']);
    }
}
```

`tests/Feature/Approval/Phase2/PendingWorkTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/PendingWorkTest.php
+++ b/tests/Feature/Approval/Phase2/PendingWorkTest.php
@@ -91,6 +91,8 @@ private function assertPendingWorkMatchesPermissions(array $people, array $reque
 
             // 出るのに押せない・押せるのに出ない、のどちらも止める
             $this->assertSame($expected, $shown, "{$when}: {$label}の対応待ちが、いま判断・対応できる申請と違う");
+            // 基幹のメニューの件数（2b・設計書 §5.15）は、ホームの対応待ちと同じ数（数えるだけの問い合わせでも食い違わない）
+            $this->assertSame(count($shown), PendingWork::countFor($person), "{$when}: {$label}の件数が対応待ちの数と違う");
 
             foreach ($pending as $item) {
                 $this->assertTrue(
@@ -283,6 +285,52 @@ public function test_pending_work_matches_what_each_person_can_do_now(): void
         $this->assertSame([], $shown['社長室の申請者'], '自分の申請には部門長としても判断できない（D16）');
     }
 
+    /**
+     * 決裁の管理者の操作（付け替え・押し間違いの取り消し・代理の取り下げ。2b・設計書 §5.14）のあとも、対応待ちと件数は
+     * 「いま判断・対応できる申請」と一致する（段階を「待ち」に戻す・残すのは今の回だけ。§5.16）
+     */
+    public function test_pending_work_matches_after_the_admin_operations(): void
+    {
+        $w        = $this->approvalWorld();
+        $workflow = app(Workflow::class);
+        $admin    = $this->approvalAdmin();
+        $deputy   = $this->baseUser(['name' => '代理 部長']);
+
+        $reassigned = $this->submittedFor($w, ['subject' => '付け替えた']);
+        $workflow->reassignHead($reassigned, $admin, $reassigned->lock_version, $deputy, '休職のため');
+
+        $undoneReview = $this->atPresident($w, ['subject' => '意見を取り消した']);
+        $workflow->undo($undoneReview, $admin, $undoneReview->lock_version, '押し間違い');
+
+        $undoneReturn = $this->submittedFor($w, ['subject' => '差戻しを取り消した']);
+        $workflow->judgeHead($undoneReturn, $w['head'], $undoneReturn->lock_version, ApprovalStepResult::Return, '直してください');
+        $undoneReturn->refresh();
+        $workflow->undo($undoneReturn, $admin, $undoneReturn->lock_version, '押し間違い');
+
+        $undoneDecision = $this->atPresident($w, ['subject' => '決裁を取り消した']);
+        $workflow->judgePresident($undoneDecision, $w['president'], $undoneDecision->lock_version, ApprovalStepResult::Conditional, '条件');
+        $undoneDecision->refresh();
+        $workflow->confirmCondition($undoneDecision, $w['applicant'], $undoneDecision->lock_version, null);
+        $undoneDecision->refresh();
+        $workflow->undo($undoneDecision, $admin, $undoneDecision->lock_version, '条件確認の押し間違い');
+
+        $withdrawn = $this->atReview($w, ['subject' => '代理で取り下げた']);
+        $workflow->withdrawByAdmin($withdrawn, $admin, $withdrawn->lock_version, '退職のため');
+
+        $requests = [$reassigned, $undoneReview, $undoneReturn, $undoneDecision, $withdrawn];
+        $people   = [
+            '申請者' => $w['applicant'], '部門長' => $w['head'], '審査担当者' => $w['reviewer'], '社長' => $w['president'],
+            '付け替えの担当' => $deputy, '決裁の管理者' => $admin,
+        ];
+
+        $shown = $this->assertPendingWorkMatchesPermissions($people, $requests, '管理者の操作のあと');
+        $this->assertSame(['付け替えた / 部門長'], $shown['付け替えの担当']);
+        $this->assertSame(['差戻しを取り消した / 部門長'], $shown['部門長'], '付け替えた申請は部門長に出ない・差戻しの取り消しで部門長の番に戻る');
+        $this->assertSame(['意見を取り消した / 審査'], $shown['審査担当者']);
+        $this->assertSame([], $shown['社長'], '意見の取り消しで社長の番から外れる');
+        $this->assertSame(['決裁を取り消した / 申請者'], $shown['申請者'], '条件確認の取り消しで申請者の番に戻る');
+    }
+
     /**
      * 並びと待ち日数は、その人の番が来た日時から（段階は届いた日時、申請者の番は状態が変わった日時。§5.12・D20）。
      * 申請を作った順・提出した順ではない（審査の番は、部門長が承認した順に届く）。
```

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2b && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase2/ApprovalMenuTest.php tests/Feature/Approval/Phase2/PendingWorkTest.php
```

Expected: `ERRORS!` `Tests: 15, Assertions: 43, Errors: 2, Failures: 5.`

- `PendingWorkTest::test_pending_work_matches_what_each_person_can_do_now`
- `PendingWorkTest::test_pending_work_matches_after_the_admin_operations`
- `ApprovalMenuTest::test_the_base_sidebar_shows_the_count_in_all_three_places`
- `ApprovalMenuTest::test_no_badge_when_nothing_is_waiting`
- `ApprovalMenuTest::test_both_dashboards_link_to_the_approval_home_with_the_count`
- `ApprovalMenuTest::test_the_count_is_computed_once_per_request`
- `ApprovalMenuTest::test_the_approval_only_sidebar_shows_the_count_on_the_home_link`

`ApprovalMenuTest::test_nothing_is_added_before_launch` は今でも緑（使い始める前は何も足さない＝今と同じ）。

- [ ] **Step 3: 数える部品を書く**

`app/Support/Approval/PendingWork.php`（変更）

```diff
--- a/app/Support/Approval/PendingWork.php
+++ b/app/Support/Approval/PendingWork.php
@@ -12,6 +12,7 @@
 use App\Support\JapanTime;
 use Carbon\CarbonImmutable;
 use DateTimeInterface;
+use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Support\Collection;
 use Illuminate\Support\Facades\DB;
 
@@ -27,27 +28,7 @@ final class PendingWork
      */
     public static function for(User $user): Collection
     {
-        $headDeptIds   = ApprovalDepartment::where('head_user_id', $user->id)->pluck('id');
-        $reviewDeptIds = DB::table('approval_reviewers')->where('user_id', $user->id)->pluck('department_id');
-        $isPresident   = $user->isApprovalPresident();
-
-        $steps = ApprovalStep::with(['request.applicant', 'request.department', 'request.type'])
-            ->where('status', ApprovalStepStatus::Waiting->value)
-            ->whereHas('request', fn ($q) => $q->where('user_id', '!=', $user->id))
-            ->where(function ($q) use ($user, $headDeptIds, $reviewDeptIds, $isPresident): void {
-                $q->where(function ($q) use ($user, $headDeptIds): void {
-                    $q->where('kind', ApprovalStepKind::Head->value)
-                      ->where(function ($q) use ($user, $headDeptIds): void {
-                          $q->where('assignee_user_id', $user->id)
-                            ->orWhere(fn ($q) => $q->whereNull('assignee_user_id')->whereIn('department_id', $headDeptIds));
-                      });
-                })->orWhere(fn ($q) => $q->where('kind', ApprovalStepKind::Review->value)->whereIn('department_id', $reviewDeptIds));
-
-                if ($isPresident) {
-                    $q->orWhere('kind', ApprovalStepKind::President->value);
-                }
-            })
-            ->get();
+        $steps = self::waitingSteps($user)->with(['request.applicant', 'request.department', 'request.type'])->get();
 
         $items = $steps->map(fn (ApprovalStep $step) => [
             'request' => $step->request,
@@ -60,9 +41,7 @@ public static function for(User $user): Collection
             'since'   => $step->arrived_at,
         ]);
 
-        $own = ApprovalRequest::with(['department', 'type', 'applicant'])
-            ->where('user_id', $user->id)
-            ->whereIn('status', [ApprovalStatus::Returned->value, ApprovalStatus::Condition->value])
+        $own = self::ownTurns($user)->with(['department', 'type', 'applicant'])
             ->get()
             ->map(fn (ApprovalRequest $request) => [
                 'request' => $request,
@@ -76,6 +55,48 @@ public static function for(User $user): Collection
             ->values();
     }
 
+    /**
+     * 対応待ちの数（基幹のメニュー・ダッシュボードの件数。設計書 §5.15）。for() と同じ条件を数えるだけ
+     * （中身を読まない）。for() と数が同じことは PendingWorkTest の突き合わせが見る。
+     */
+    public static function countFor(User $user): int
+    {
+        return self::waitingSteps($user)->count() + self::ownTurns($user)->count();
+    }
+
+    /** 自分が判断する番の段階（待ち・自分の申請を除く。D16） */
+    private static function waitingSteps(User $user): Builder
+    {
+        $headDeptIds   = ApprovalDepartment::where('head_user_id', $user->id)->pluck('id');
+        $reviewDeptIds = DB::table('approval_reviewers')->where('user_id', $user->id)->pluck('department_id');
+        $isPresident   = $user->isApprovalPresident();
+
+        return ApprovalStep::query()
+            ->where('status', ApprovalStepStatus::Waiting->value)
+            ->whereHas('request', fn ($q) => $q->where('user_id', '!=', $user->id))
+            ->where(function ($q) use ($user, $headDeptIds, $reviewDeptIds, $isPresident): void {
+                $q->where(function ($q) use ($user, $headDeptIds): void {
+                    $q->where('kind', ApprovalStepKind::Head->value)
+                      ->where(function ($q) use ($user, $headDeptIds): void {
+                          $q->where('assignee_user_id', $user->id)
+                            ->orWhere(fn ($q) => $q->whereNull('assignee_user_id')->whereIn('department_id', $headDeptIds));
+                      });
+                })->orWhere(fn ($q) => $q->where('kind', ApprovalStepKind::Review->value)->whereIn('department_id', $reviewDeptIds));
+
+                if ($isPresident) {
+                    $q->orWhere('kind', ApprovalStepKind::President->value);
+                }
+            });
+    }
+
+    /** 申請者の番（自分の申請の差戻し中・条件確認待ち） */
+    private static function ownTurns(User $user): Builder
+    {
+        return ApprovalRequest::query()
+            ->where('user_id', $user->id)
+            ->whereIn('status', [ApprovalStatus::Returned->value, ApprovalStatus::Condition->value]);
+    }
+
     /** 待ち日数（自分の番が来た日から数えた暦の日数・日本時間。D20） */
     public static function waitingDays(?DateTimeInterface $since): int
     {
```

`app/Support/Approval/ApprovalMenu.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Models\ApprovalSetting;
use App\Models\User;

/**
 * 基幹のメニューとダッシュボードに出す「決裁の対応待ち」の件数（段階2 設計書 §5.15・要件 12.2・15.2）。
 *
 * - 使い始める前は null（何も出さない。D1。一般の利用者に見える変化を増やさない）
 * - 1 リクエストに 1 回だけ数える（サイドバー 3 か所とダッシュボードで数え直さない。§5.15）。覚えるのはリクエストの
 *   attributes（リクエストごとに新しいので、テストで画面を 2 回開いても前の数を使わない）
 * - 数え方は ホーム①の対応待ちと同じ（PendingWork::countFor()）
 */
final class ApprovalMenu
{
    private const ATTRIBUTE = 'approval.pending_count';

    public static function pendingCount(User $user): ?int
    {
        $attributes = request()->attributes;
        $key        = self::ATTRIBUTE . '.' . $user->id;

        if (! $attributes->has($key)) {
            $attributes->set($key, ApprovalSetting::launchedForMenu() ? PendingWork::countFor($user) : null);
        }

        return $attributes->get($key);
    }
}
```

- [ ] **Step 4: サイドバーに件数を出す**（基幹の 3 か所と決裁のみのホーム。0 件は丸を出さない。展開のサイドバーに `x-cloak` を付けない）

`resources/views/components/sidebar-item.blade.php`（変更）

```diff
--- a/resources/views/components/sidebar-item.blade.php
+++ b/resources/views/components/sidebar-item.blade.php
@@ -1,4 +1,4 @@
-@props(['href', 'label', 'active' => false])
+@props(['href', 'label', 'active' => false, 'badge' => null])
 
 <a
     href="{{ $href }}"
@@ -8,4 +8,8 @@ class="block px-5 py-2 text-[13px] transition-colors duration-150 border-l-[3px]
             : 'text-gray-700 hover:text-[#065F46] hover:bg-gray-50 border-transparent' }}"
 >
     {{ $label }}
+    @if($badge)
+        {{-- 件数の丸印（決裁の対応待ち。段階2 設計書 §5.15）。0 と null は出さない --}}
+        <span class="ml-1.5 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10px] font-bold tabular-nums"><span class="sr-only">対応待ち </span>{{ $badge }}<span class="sr-only"> 件</span></span>
+    @endif
 </a>
```

`resources/views/layouts/partials/sidebar.blade.php`（変更）

```diff
--- a/resources/views/layouts/partials/sidebar.blade.php
+++ b/resources/views/layouts/partials/sidebar.blade.php
@@ -25,9 +25,10 @@ class="fixed inset-0 bg-black/50 z-20 lg:hidden"
     // 決裁の管理者に指定された人だけ「決裁の管理」を出す（設計書 §5.15・D2）。
     // 指定されていない人の画面は変わらない（段階1 で一般の利用者に見える変化はログイン画面だけ）。
     $isApprovalAdmin = $user->isApprovalAdmin();
-    // 進行中の申請の管理へのリンクは、決裁の管理者に・使い始めてから（段階2 設計書 §5.2・D1）。
-    // ⚠ 基幹の画面は launchedForMenu()（行を作らず読むだけ。問い合わせは決裁の管理者の画面だけ）
-    $approvalsLaunched = $isApprovalAdmin && \App\Models\ApprovalSetting::launchedForMenu();
+    // 決裁の対応待ちの件数（使い始める前は null で何も出さない。1 リクエストに 1 回だけ数える。段階2 設計書 §5.15）
+    $approvalPending = \App\Support\Approval\ApprovalMenu::pendingCount($user);
+    // 進行中の申請の管理へのリンクは、決裁の管理者に・使い始めてから（段階2 設計書 §5.2・D1）
+    $approvalsLaunched = $approvalPending !== null;
 @endphp
 
 {{-- ========== PC用: 展開サイドバー ========== --}}
@@ -64,6 +65,10 @@ class="inline-flex items-center gap-1 px-2 py-1 rounded-md border border-gray-30
         @if($hasMansionAccess)
             <x-sidebar-item :href="url('/mansion/dashboard')" label="賃貸Mダッシュボード" :active="request()->is('mansion/dashboard')" />
         @endif
+        {{-- 決裁と対応待ちの件数（使い始めてから。段階2 設計書 §5.15） --}}
+        @if($approvalPending !== null)
+            <x-sidebar-item :href="route('approvals.home')" label="決裁" :badge="$approvalPending" :active="request()->routeIs('approvals.home', 'approvals.requests.*')" />
+        @endif
     </div>
 
     {{-- テナント管理 --}}
@@ -244,6 +249,21 @@ class="w-9 h-8 mb-3 rounded-md bg-gray-100 flex items-center justify-center text
         </svg>
     </a>
 
+    {{-- 決裁と対応待ちの件数（使い始めてから。§5.15） --}}
+    @if($approvalPending !== null)
+        <a href="{{ route('approvals.home') }}" title="決裁（対応待ち {{ $approvalPending }} 件）" class="relative w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->routeIs('approvals.home', 'approvals.requests.*') ? 'bg-emerald-50' : 'hover:bg-gray-100' }} transition-colors">
+            <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="{{ request()->routeIs('approvals.home', 'approvals.requests.*') ? '#059669' : '#6B7280' }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
+                {{-- クリップボード＋チェック（決裁の管理と同じ形） --}}
+                <path d="M9 2h6a1 1 0 0 1 1 1v2H8V3a1 1 0 0 1 1-1z" />
+                <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" />
+                <polyline points="9 14 11 16 15 12" />
+            </svg>
+            @if($approvalPending > 0)
+                <span class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 rounded-full bg-red-600 text-white text-[10px] font-bold leading-4 text-center tabular-nums" aria-hidden="true">{{ $approvalPending }}</span>
+            @endif
+        </a>
+    @endif
+
     {{-- テナント管理 --}}
     @if($hasTenantAccess)
         <a href="{{ url('/tenant/properties') }}" title="テナント管理" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->is('tenant/*') ? 'bg-emerald-50' : 'hover:bg-gray-100' }} transition-colors">
@@ -370,6 +390,11 @@ class="fixed inset-y-0 left-0 z-30 w-[260px] bg-white overflow-y-auto pt-4 pb-6
         @endif
     </x-sidebar-group>
 
+    {{-- 決裁と対応待ちの件数（使い始めてから。§5.15）。開閉するグループの中に入れない（閉じているあいだ件数が見えない） --}}
+    @if($approvalPending !== null)
+        <x-sidebar-item :href="route('approvals.home')" label="決裁" :badge="$approvalPending" :active="request()->routeIs('approvals.home', 'approvals.requests.*')" />
+    @endif
+
     @if($hasTenantAccess)
         <x-sidebar-group label="テナント管理" section="tenant">
             <x-sidebar-item :href="url('/tenant/properties')" label="物件一覧" :active="request()->is('tenant/properties*')" />
```

`resources/views/layouts/partials/sidebar_approval.blade.php`（変更）

```diff
--- a/resources/views/layouts/partials/sidebar_approval.blade.php
+++ b/resources/views/layouts/partials/sidebar_approval.blade.php
@@ -15,6 +15,8 @@
     $isApprovalAdmin = Auth::user()->isApprovalAdmin();
     // 申請を回す画面へのリンクは使い始めてから（準備中は誰にも見せない。段階2 設計書 §5.2・D1）
     $approvalsLaunched = \App\Models\ApprovalSetting::current()->isLaunched();
+    // 対応待ちの件数（使い始めてから。1 リクエストに 1 回だけ数える。段階2 設計書 §5.15）
+    $approvalPending = \App\Support\Approval\ApprovalMenu::pendingCount(Auth::user());
 @endphp
 
 {{-- ========== PC用: 展開サイドバー ========== --}}
@@ -28,7 +30,7 @@
             <button @click="sidebarExpanded = false" title="サイドバーを閉じる"
                     class="inline-flex items-center gap-1 px-2 py-1 rounded-md border border-gray-300 bg-gray-50 text-[10px] text-gray-500 hover:bg-gray-100 cursor-pointer">閉じる</button>
         </div>
-        <x-sidebar-item :href="route('approvals.home')" label="決裁のホーム" :active="request()->routeIs('approvals.home')" />
+        <x-sidebar-item :href="route('approvals.home')" label="決裁のホーム" :badge="$approvalPending" :active="request()->routeIs('approvals.home')" />
         @if($approvalsLaunched)
             <x-sidebar-item :href="route('approvals.requests.create')" label="新しい申請" :active="request()->routeIs('approvals.requests.create')" />
             <x-sidebar-item :href="route('approvals.requests.index')" label="自分の申請" :active="request()->routeIs('approvals.requests.index', 'approvals.requests.show', 'approvals.requests.edit')" />
@@ -47,7 +49,7 @@ class="inline-flex items-center gap-1 px-2 py-1 rounded-md border border-gray-30
 {{-- ========== PC用: 折りたたみサイドバー ========== --}}
 <aside x-show="!sidebarExpanded" x-cloak class="hidden lg:flex flex-col items-center w-[56px] min-w-[56px] bg-white border-r border-gray-200 overflow-y-auto pt-4 pb-6">
     <button @click="sidebarExpanded = true" title="サイドバーを開く" class="w-9 h-9 mb-3 rounded-lg flex items-center justify-center hover:bg-gray-100 cursor-pointer">›</button>
-    <a href="{{ route('approvals.home') }}" title="決裁のホーム" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->routeIs('approvals.home') ? 'bg-emerald-50' : 'hover:bg-gray-100' }}">決</a>
+    <a href="{{ route('approvals.home') }}" title="決裁のホーム" class="relative w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->routeIs('approvals.home') ? 'bg-emerald-50' : 'hover:bg-gray-100' }}">決@if($approvalPending)<span class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 rounded-full bg-red-600 text-white text-[10px] font-bold leading-4 text-center tabular-nums" aria-hidden="true">{{ $approvalPending }}</span>@endif</a>
     @if($approvalsLaunched)
         <a href="{{ route('approvals.requests.index') }}" title="自分の申請" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->routeIs('approvals.requests.*') ? 'bg-emerald-50' : 'hover:bg-gray-100' }}">申</a>
     @endif
@@ -73,7 +75,7 @@ class="p-1 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transi
             </svg>
         </button>
     </div>
-    <x-sidebar-item :href="route('approvals.home')" label="決裁のホーム" :active="request()->routeIs('approvals.home')" />
+    <x-sidebar-item :href="route('approvals.home')" label="決裁のホーム" :badge="$approvalPending" :active="request()->routeIs('approvals.home')" />
     @if($approvalsLaunched)
         <x-sidebar-item :href="route('approvals.requests.create')" label="新しい申請" :active="request()->routeIs('approvals.requests.create')" />
         <x-sidebar-item :href="route('approvals.requests.index')" label="自分の申請" :active="request()->routeIs('approvals.requests.index', 'approvals.requests.show', 'approvals.requests.edit')" />
```

- [ ] **Step 5: ダッシュボードにカードを出す**（経営層・テナントの先頭。0 件でも出す）

`resources/views/approvals/_pending_card.blade.php`（新規）

```blade
{{-- 基幹のダッシュボードの「決裁の対応待ち N 件」とホームへのリンク（段階2 設計書 §5.15・要件 12.2）。
     使い始める前は出さない（ApprovalMenu が null）。件数は 1 リクエストに 1 回だけ数える（サイドバーと同じ数を使う） --}}
@php $approvalPending = \App\Support\Approval\ApprovalMenu::pendingCount(auth()->user()); @endphp
@if($approvalPending !== null)
    <a href="{{ route('approvals.home') }}" class="mb-4 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-lg border px-4 py-3 text-[13px] {{ $approvalPending > 0 ? 'border-amber-200 bg-amber-50 text-amber-900 hover:bg-amber-100' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50' }}">
        <span><span class="font-semibold">決裁の対応待ち</span> <span class="font-bold tabular-nums">{{ $approvalPending }}</span> 件</span>
        <span class="font-semibold text-emerald-700">決裁のホームへ →</span>
    </a>
@endif
```

`resources/views/dashboard/executive.blade.php`（変更）

```diff
--- a/resources/views/dashboard/executive.blade.php
+++ b/resources/views/dashboard/executive.blade.php
@@ -197,6 +197,7 @@
 </style>
 
 <div class="exec-dashboard">
+    @include('approvals._pending_card')
     @include('dashboard._executive_filter')
     @include('dashboard._executive_tenant')
     @include('dashboard._executive_mansion')
```

`resources/views/dashboard/tenant.blade.php`（変更）

```diff
--- a/resources/views/dashboard/tenant.blade.php
+++ b/resources/views/dashboard/tenant.blade.php
@@ -281,6 +281,7 @@
 </style>
 
 <div class="tenant-dashboard">
+    @include('approvals._pending_card')
     <h1 class="page-title">テナントダッシュボード</h1>
 
     <div class="section">
```

- [ ] **Step 6: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (15 tests, …)`

- [ ] **Step 7: 全件を流す**

Expected: `OK (2860 tests, 20038 assertions)`（`ApprovalSidebarTest`・`LayoutSidebarCloakTest`・`PropertyListSortTest` も緑）

- [ ] **Step 8: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b add app/Support/Approval/ApprovalMenu.php app/Support/Approval/PendingWork.php resources/views/approvals/_pending_card.blade.php resources/views/components/sidebar-item.blade.php resources/views/dashboard/executive.blade.php resources/views/dashboard/tenant.blade.php resources/views/layouts/partials/sidebar.blade.php resources/views/layouts/partials/sidebar_approval.blade.php tests/Feature/Approval/Phase2/ApprovalMenuTest.php tests/Feature/Approval/Phase2/PendingWorkTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b commit -m "$(cat <<'MSG'
feat(approval): 基幹のメニューとダッシュボードに決裁の対応待ちの件数を出す

使い始めたあと、基幹のサイドバー 3 か所に「決裁」と対応待ちの件数、決裁のみの
サイドバーのホームに件数、経営層とテナントのダッシュボードにカードを出す
（設計書 §5.15）。件数は PendingWork と同じ条件を 1 リクエストに 1 回だけ数える。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---


## Task 8: 全件テストと変異テスト（Bug #44 の作法）

**Files:** なし（測るだけ。穴が見つかったらテストを足してコミットする）

計画を書く段階で、試作（この計画のコードと同じ中身）に下の表の変異を 1 つずつ当てて測った（2026-09-28 夜〜29 朝。決裁のテスト〈`tests/Feature/Approval`・`tests/Unit/Approval`〉と走査テスト 6 本・`PropertyListSortTest` を流した）。最初の測定で見逃した 4 通り（S03・C03・A03・A08）には、持ち主の Task（2・3・5）にテストを足してある（下の表は足したあとの測り直し）。この Task では、実装したコードで**同じ結果になること**を確かめる。

⚠ WT のファイルを一時的に壊す変異は、自動の許可の判定に断られる。**WT の HEAD の写しを scratchpad に作ってそこで当てる**（WT は読むだけ）。以下の `<scratchpad>` は、その会話の scratchpad のパス（Mac を再起動すると消える。残したい結果は `~/.claude/plans/approval-phase2b-tasks/` へ写す）:

```bash
SCR=<scratchpad>/p2b-mutation && mkdir -p "$SCR" && git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b archive HEAD | tar -x -C "$SCR" && cp -Rc /Users/masanori/site/manage/.claude/worktrees/approval-phase2b/vendor "$SCR/vendor" && ! test -L "$SCR/vendor" && echo "写し OK"
```

- [ ] **Step 1: 全件が緑の状態から始める**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase2b && git status --porcelain && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `git status --porcelain` が空・`OK (2860 tests, 20038 assertions)`

- [ ] **Step 2: 変異を当てて表と突き合わせる**

道具は `~/.claude/plans/approval-phase2b-tasks/mutate.py`（変異の一覧入り。1 つ当てて流し、必ず元に戻して、戻ったことを確かめる。書き換える前の文字列が 1 回だけ現れないものは当てずに SKIP と記録する）。写しで流す（1 通り約 30 秒・全部で約 40 分）:

```bash
python3 ~/.claude/plans/approval-phase2b-tasks/mutate.py <scratchpad>/p2b-mutation <scratchpad>/mutations.jsonl
```

⚠ **最初の `CANARY`（履歴の部品に未定義の変数）が赤になること**を確かめてから結果を読む（測定が写しのコードを読んでいることの証明）。
⚠ **赤/緑ではなく「落ちたテストの集合」と「落ちた理由の文言」まで突き合わせる。** 意図と別の機構が落としているなら、その変異の測定は無効。当て方を変えて測り直す。

計画の時点の実測（試作 `split3`。「落ちたテスト」はクラス名を省いたテストの名前）:

| # | 変異（ファイル） | 結果 | 落ちたテスト（実測） | 判定 |
|---|---|---|---|---|
| CANARY | カナリア: 履歴の部品に未定義の変数（`_history.blade.php`） | Tests: 799, Assertions: 5482, Failures: 67. | 67 本（AdminRequestsTest, RequestActionTest, RequestAttachmentTest, RequestChangesTest ほか） | カナリア（赤が正しい） |
| L01 | 大きすぎる差の出し方を「増えた行→消えた行」に（`LineDiff.php`） | Tests: 799, Assertions: 6134, Failures: 1. | `test_a_huge_middle_falls_back_to_removed_then_added` | 検出 |
| L02 | LCS の同じ長さのとき増えた行を先に（`LineDiff.php`） | Tests: 799, Assertions: 6135, Failures: 1. | `test_a_removed_line_and_a_changed_line` | 検出 |
| L03 | 行に分ける前に改行をそろえない（`LineDiff.php`） | Tests: 799, Assertions: 6134, Failures: 1. | `test_newlines_are_unified_before_splitting` | 検出 |
| S01 | 種類を名前で比べる（`RequestSnapshot.php`） | Tests: 799, Assertions: 6135, Failures: 1. | `test_a_renamed_type_or_department_is_not_a_change` | 検出 |
| S02 | 関連する決裁No を並びも込みで比べる（`RequestSnapshot.php`） | Tests: 799, Assertions: 6135, Failures: 1. | `test_reordered_related_numbers_are_not_a_change` | 検出 |
| S03 | 変更点の金額で 0 と空を同じにする（`RequestSnapshot.php`） | Tests: 799, Assertions: 6135, Failures: 1. | `test_zero_and_null_amounts_are_different` | 検出（計画を書く段階では見逃し → Task 2 の `test_zero_and_null_amounts_are_different` に変更点の確かめを足した） |
| S04 | 指紋の金額で 0 と空を同じにする（`RequestSnapshot.php`） | Tests: 799, Assertions: 6134, Failures: 1. | `test_zero_and_null_amounts_are_different` | 検出 |
| S05 | 指紋から添付を外す（`RequestSnapshot.php`） | Tests: 799, Assertions: 6128, Failures: 2. | `test_the_fingerprint_changes_with_any_editable_value`、`test_undoing_a_return_is_refused_once_the_applicant_edits` | 検出 |
| S06 | 指紋に種類の名前を入れる（`RequestSnapshot.php`） | Tests: 799, Assertions: 6135, Failures: 1. | `test_the_fingerprint_ignores_key_order_and_names` | 検出 |
| C01 | 変更点を今の中身（直しかけ）と比べる（`RequestController.php`） | Tests: 799, Assertions: 6122, Failures: 2. | `test_others_see_the_latest_round_that_was_submitted`、`test_the_in_progress_edits_are_not_in_the_changes_or_the_history_for_others` | 検出 |
| C02 | 履歴の添付を今の添付にする（`_history.blade.php`） | Tests: 799, Assertions: 6114, Failures: 3. | `test_a_file_added_while_returned_stays_with_the_applicant_until_resubmitted`、`test_the_history_shows_each_round_with_its_own_content`、`test_the_in_progress_edits_are_not_in_the_changes_or_the_history_for_others` | 検出 |
| C03 | 履歴の判断を回で絞らない（`_history.blade.php`） | Tests: 799, Assertions: 6135, Failures: 1. | `test_the_history_shows_each_round_with_its_own_content` | 検出（計画を書く段階では見逃し → Task 3 の `test_the_history_shows_each_round_with_its_own_content` に回の判断の確かめを足した） |
| C04 | 変更点の「外した添付」の読み上げを消す（`_changes.blade.php`） | Tests: 799, Assertions: 6130, Failures: 2. | `test_a_resubmitted_request_shows_what_changed_from_the_previous_round`、`test_the_detail_lists_the_attachments_of_the_shown_content` | 検出 |
| C05 | 付け替えの記録に新しい担当を添えない（`RequestController.php`） | Tests: 799, Assertions: 6134, Failures: 1. | `test_reassigning_from_the_detail` | 検出 |
| U01 | 省略・交代・付け替えを飛ばさない（`UndoTarget.php`） | OK (799 tests, 6135 assertions) | — | 等価: 省略・交代・付け替えの記録は、同じ回の取り消していない判断より新しくならない（交代と付け替えは部門長の段階が待ちのときだけ・省略は提出の直後）。守りとして残す |
| U02 | 取り消し済みの記録を飛ばさない（`UndoTarget.php`） | Tests: 799, Assertions: 6122, Errors: 2, Failures: 1. | `test_the_pending_work_follows_the_undo`、`test_undo_skips_the_skip_the_head_change_and_the_reassignment`、`test_undo_walks_back_one_operation_at_a_time_to_the_submission` | 検出 |
| U03 | 取り消せない操作を越えて探し続ける（`UndoTarget.php`） | Tests: 799, Assertions: 6123, Failures: 1. | `test_undo_refusals` | 検出 |
| U04 | 前の回の記録も探す（`UndoTarget.php`） | OK (799 tests, 6135 assertions) | — | 等価: 提出・出し直しの記録で探すのが止まるので、前の回の記録に届かない |
| U05 | D3: いつも「変えていない」（`UndoTarget.php`） | Tests: 799, Assertions: 6124, Failures: 2. | `test_the_undo_button_explains_the_refusal_after_the_applicant_edits`、`test_undoing_a_return_is_refused_once_the_applicant_edits` | 検出 |
| U06 | D3: 控えが無ければ「変えていない」（`UndoTarget.php`） | OK (799 tests, 6135 assertions) | — | 等価（守り）: 今の回の控えは提出と同じ取引で作るので、控えの無い提出済みの申請はできない |
| U07 | D3: 社長の差戻しを確かめない（`UndoTarget.php`） | Tests: 799, Assertions: 6132, Failures: 1. | `test_undoing_a_return_is_refused_once_the_applicant_edits` | 検出 |
| U08 | D3: 差戻し以外の取り消しでも確かめる（`RequestPermissions.php`） | OK (799 tests, 6135 assertions) | — | 等価: 申請者が中身を直せるのは差戻し中だけなので、差戻し以外の取り消しでは今の中身が控えと同じ |
| W01 | 社長の判断の取り消しで decision を残す（`Workflow.php`） | Tests: 799, Assertions: 6114, Failures: 3. | `test_undo_restores_the_state_before_the_operation` | 検出 |
| W02 | 社長の判断の取り消しで番号も消す（`Workflow.php`） | Tests: 799, Assertions: 6073, Failures: 5. | `test_a_returned_request_with_a_number_shows_its_submitted_subject`、`test_the_number_stays_through_the_undo_and_is_reused_in_the_next_fiscal_year`、`test_undo_restores_the_state_before_the_operation` | 検出 |
| W03 | 条件の確認の取り消しで finished_at を残す（`Workflow.php`） | Tests: 799, Assertions: 6130, Failures: 1. | `test_undo_restores_the_state_before_the_operation` | 検出 |
| W04 | 条件の確認の取り消しでも段階を戻す（`Workflow.php`） | OK (799 tests, 6135 assertions) | — | 等価: 条件の確認の記録には段階が付かず（`step_id` が空）、その回の段階はすべて済みなので、戻す段階が無い |
| W05 | 取り消しで arrived_at を空にする（`Workflow.php`） | Tests: 799, Assertions: 6053, Failures: 8. | `test_undo_restores_the_state_before_the_operation`、`test_undoing_from_the_detail` | 検出 |
| W06 | 取り消しで段階の判断した人を残す（`Workflow.php`） | Tests: 799, Assertions: 6064, Failures: 8. | `test_someone_whose_judgement_was_undone_can_still_see_the_request`、`test_undo_restores_the_state_before_the_operation` | 検出 |
| W07 | 後ろの待ちの段階を戻さない（`Workflow.php`） | Tests: 799, Assertions: 6098, Failures: 5. | `test_pending_work_matches_after_the_admin_operations`、`test_the_pending_work_follows_the_undo`、`test_undo_restores_the_state_before_the_operation`、`test_undoing_from_the_detail` | 検出 |
| W08 | 取り消しで D3 を確かめない（`Workflow.php`） | Tests: 799, Assertions: 6126, Failures: 1. | `test_undoing_a_return_is_refused_once_the_applicant_edits` | 検出 |
| W09 | 取り消した記録の id を残さない（`Workflow.php`） | Tests: 799, Assertions: 6114, Errors: 2, Failures: 9. | `test_the_pending_work_follows_the_undo`、`test_undo_restores_the_state_before_the_operation`、`test_undo_skips_the_skip_the_head_change_and_the_reassignment`、`test_undo_walks_back_one_operation_at_a_time_to_the_submission` | 検出 |
| W10 | 申請者本人へ付け替えられる（`Workflow.php`） | Tests: 799, Assertions: 6114, Failures: 1. | `test_reassignment_refusals` | 検出 |
| W11 | いまの担当へ付け替えられる（`Workflow.php`） | Tests: 799, Assertions: 6111, Failures: 2. | `test_a_refused_reassignment_reopens_its_modal`、`test_reassignment_refusals` | 検出 |
| W12 | 付け替えで版を進めない（`Workflow.php`） | Tests: 799, Assertions: 6125, Failures: 2. | `test_a_screen_drawn_before_the_reassignment_is_a_conflict`、`test_reassigning_moves_the_head_step_to_the_new_assignee` | 検出 |
| W13 | 無効の人へ付け替えられる（`Workflow.php`） | Tests: 799, Assertions: 6120, Failures: 1. | `test_reassignment_refusals` | 検出 |
| W14 | 代理の取り下げの理由をコメントに入れる（`Workflow.php`） | Tests: 799, Assertions: 6132, Failures: 1. | `test_an_admin_withdraws_for_the_applicant` | 検出 |
| P01 | D25: 自分の申請にも操作できる（`RequestPermissions.php`） | Tests: 799, Assertions: 6124, Failures: 3. | `test_an_admin_cannot_reassign_their_own_request`、`test_undo_refusals`、`test_withdrawal_by_an_admin_refusals` | 検出 |
| P02 | 審査中・社長決裁待ちも付け替えられる（`RequestPermissions.php`） | Tests: 799, Assertions: 6069, Errors: 2, Failures: 1. | `test_every_state_accepts_only_the_operations_in_the_table`、`test_reassignment_refusals`、`test_the_detail_offers_the_operations_that_are_possible_now` | 検出 |
| P03 | 決裁済みも代理で取り下げられる（`RequestPermissions.php`） | Tests: 799, Assertions: 6132, Failures: 2. | `test_every_state_accepts_only_the_operations_in_the_table`、`test_withdrawal_by_an_admin_refusals` | 検出 |
| V01 | 判断を取り消された人が見られない（`RequestVisibility.php`） | Tests: 799, Assertions: 6133, Failures: 1. | `test_someone_whose_judgement_was_undone_can_still_see_the_request` | 検出 |
| A01 | 見られない申請を 404 にしない（`AdminRequestController.php`） | Tests: 799, Assertions: 6133, Errors: 1. | `test_a_draft_of_someone_else_is_not_found` | 検出 |
| A02 | 理由の改行をそろえない（`AdminRequestController.php`） | Tests: 799, Assertions: 6131, Failures: 2. | `test_a_reason_with_many_newlines_fits_in_two_thousand_characters`、`test_undoing_from_the_detail` | 検出 |
| A03 | 理由の 2,000 文字の上限を外す（`AdminRequestController.php`） | Tests: 799, Assertions: 6132, Failures: 1. | `test_a_reason_over_two_thousand_characters_is_refused` | 検出（計画を書く段階では見逃し → Task 5 に `test_a_reason_over_two_thousand_characters_is_refused` を足した） |
| A04 | 理由の必須を入力の検査から外す（Workflow が断る）（`AdminRequestController.php`） | OK (799 tests, 6135 assertions) | — | 等価（守りの二重）: `Workflow::requireReason()` が同じ文で断り、小窓も同じく開き直す |
| A05 | ⑩ の件名を今の件名にする（D26）（`AdminRequestController.php`） | Tests: 799, Assertions: 6133, Failures: 1. | `test_the_list_shows_the_last_submitted_subject` | 検出 |
| A06 | ⑩ を待ち日数の短い順にする（`AdminRequestController.php`） | Tests: 799, Assertions: 6130, Failures: 1. | `test_the_list_shows_requests_in_progress_by_waiting_days` | 検出 |
| A07 | 審査担当者の印で無効の人も数える（`AdminRequestController.php`） | Tests: 799, Assertions: 6134, Failures: 1. | `test_the_list_flags_requests_stuck_on_the_applicant` | 検出 |
| A08 | 部門長の印で付け替えを見ない（`AdminRequestController.php`） | Tests: 799, Assertions: 6132, Failures: 1. | `test_the_list_flags_requests_stuck_on_the_applicant` | 検出（計画を書く段階では見逃し → Task 5 の `test_the_list_flags_requests_stuck_on_the_applicant` に付け替えのあとを足した） |
| A09 | 決裁済みのページ送りでタブを落とす（`AdminRequestController.php`） | Tests: 799, Assertions: 6131, Failures: 1. | `test_the_decided_tab_pages_by_twenty_and_keeps_the_tab` | 検出 |
| A10 | ⑩ の門番から管理を外す（`approval.php`） | Tests: 799, Assertions: 6128, Failures: 2. | `test_every_admin_route_refuses_outsiders_without_revealing_ids`、`test_every_approvals_route_is_classified` | 検出 |
| A11 | ⑩ の門番から稼働を外す（`approval.php`） | Tests: 799, Assertions: 6132, Failures: 2. | `test_every_approval_route_is_classified`、`test_the_list_is_hidden_before_launch` | 検出 |
| A12 | 断られたら取り消しの小窓を開く（送り先を見ない）（`_admin_actions.blade.php`） | Tests: 799, Assertions: 6120, Failures: 2. | `test_a_reason_over_two_thousand_characters_is_refused`、`test_a_refused_reassignment_reopens_its_modal` | 検出 |
| A13 | 取り消しの小窓が版を送らない（`_admin_actions.blade.php`） | Tests: 799, Assertions: 6125, Failures: 3. | `test_sending_the_same_undo_twice_goes_back_only_one_step`、`test_the_open_edit_form_does_not_save_after_the_return_is_undone`、`test_undoing_from_the_detail` | 検出 |
| A14 | 付け替え先の選択肢に申請者を出す（`RequestController.php`） | Tests: 799, Assertions: 6135, Failures: 1. | `test_the_reassign_choices_exclude_the_applicant_and_mark_unreachable_mail` | 検出 |
| A15 | 取り消しの記録に元の操作の名前を添えない（`ApprovalHistory.php`） | Tests: 799, Assertions: 6134, Failures: 1. | `test_undoing_from_the_detail` | 検出 |
| A16 | 届いた日時をどの段階にも出す（`_steps.blade.php`） | Tests: 799, Assertions: 6135, Failures: 1. | `test_undoing_from_the_detail` | 検出 |
| A17 | m-2 の文を 2a に戻す（`_actions.blade.php`） | Tests: 799, Assertions: 6129, Failures: 1. | `test_the_refusal_says_how_to_move_on_at_each_stage` | 検出 |
| A18 | 取り下げの断りで条件確認の小窓を開く（m-6）（`RequestActionController.php`） | Tests: 799, Assertions: 6094, Failures: 3. | `test_a_refused_withdrawal_or_condition_reopens_with_the_comment`、`test_a_reopened_modal_keeps_the_version_of_the_refused_page`、`test_only_the_refused_modal_reopens` | 検出 |
| A19 | 判断の断りで小窓を開き直さない（`RequestActionController.php`） | Tests: 799, Assertions: 6110, Failures: 2. | `test_a_refused_judgement_reopens_with_the_choice_and_the_comment`、`test_the_reason_in_the_reopened_modal_belongs_to_the_refused_choice` | 検出 |
| A20 | 担当に選べる人の検査でメールを見ない（`Assignees.php`） | Tests: 799, Assertions: 6133, Failures: 1. | `test_only_active_users_with_mail_can_be_chosen` | 検出 |
| R01 | 差戻し中も今の件名を返す（`RelatedNumberController.php`） | Tests: 799, Assertions: 6121, Failures: 1. | `test_a_returned_request_with_a_number_shows_its_submitted_subject` | 検出 |
| R02 | 差戻し中も今の件名で当てる（`RelatedNumberController.php`） | Tests: 799, Assertions: 6119, Failures: 1. | `test_a_returned_request_with_a_number_shows_its_submitted_subject` | 検出 |
| N01 | 件数に申請者の番を数えない（`PendingWork.php`） | Tests: 799, Assertions: 6032, Failures: 3. | `test_pending_work_matches_after_the_admin_operations`、`test_pending_work_matches_what_each_person_can_do_now`、`test_the_approval_only_sidebar_shows_the_count_on_the_home_link` | 検出 |
| N02 | 件数を 1 リクエストで覚えない（`ApprovalMenu.php`） | Tests: 799, Assertions: 6135, Failures: 1. | `test_the_count_is_computed_once_per_request` | 検出 |
| N03 | 使い始める前も件数を出す（`ApprovalMenu.php`） | Tests: 799, Assertions: 6110, Failures: 6. | `test_a_view_all_member_does_not_get_the_management_links`、`test_an_executive_without_the_flag_gets_nothing_either`、`test_nothing_changes_for_everyone_else`、`test_nothing_is_added_before_launch`、`test_the_query_count_does_not_grow_with_the_number_of_properties`、`test_the_sidebar_links_to_the_list_for_admins_after_launch` | 検出 |
| N04 | 0 件でも丸を出す（`sidebar-item.blade.php`） | Tests: 799, Assertions: 6133, Failures: 1. | `test_no_badge_when_nothing_is_waiting` | 検出 |
| N05 | メニューの読み取りで設定の行を作る（`ApprovalSetting.php`） | Tests: 799, Assertions: 6135, Failures: 1. | `test_the_query_count_does_not_grow_with_the_number_of_properties` | 検出 |
| F01 | 版の桁の上限を外す（`FormInput.php`） | Tests: 799, Assertions: 6135, Failures: 1. | `test_lock_version_is_read_only_in_the_shape_of_a_whole_number` | 検出 |
| F02 | 版が無いとき与えた値を使わない（`FormInput.php`） | Tests: 799, Assertions: 6134, Failures: 2. | `test_a_missing_lock_version_falls_back_to_the_given_value`、`test_the_refusal_reasons_are_shown_on_the_edit_page` | 検出 |
| F03 | N-1: 無効の審査担当者も数える（`TypeController.php`） | Tests: 799, Assertions: 6135, Failures: 1. | `test_a_review_department_whose_only_reviewer_is_inactive_is_flagged` | 検出 |

- [ ] **Step 3: 表と違ったものを調べ、検出できなかった変異にテストを足す**

⚠ **等価**（緑が正しい）と書いたものは、なぜ等価かを 1 行で確かめる（読み違えて「守られていない」としない）。等価でないのに緑のものは、その Task のテストに 1 本足し、足したテストが変異で赤・元に戻して緑になることを確かめてからコミットする。

- [ ] **Step 4: 結果をこの計画に追記してコミット**

検出／当初検出漏れ→追加で検出／等価を区別して、この計画の末尾に「Task 8 の実測記録」として書き足す。

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b add docs/superpowers/plans/2026-09-28-approval-phase2b.md tests/
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b commit -m "$(cat <<'MSG'
test(approval): 段階2b の変異テストの結果を記録する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

写し（`<scratchpad>/p2b-mutation`）は、この Task が済んだら消してよい（`rm -rf` の前に中身が写しであることを確かめる）。


---

## Task 9: 手元のブラウザでの確認と、利用者に見せる画面の写真（設計書 §6・D1）

**Files:** なし（測るだけ）

テストが原理的に測れない領域（JS の動き・見た目・スマホの幅）を見る。**本番では使い始めるまで 2b の画面を誰にも見せないので、利用者に見てもらうのはここで撮る写真**（D1）。WT の HEAD の写し（scratchpad）＋使い捨ての SQLite ＋ `artisan serve`。ブラウザは Playwright（利用者の決まり）。`<scratchpad>` は Task 8 と同じ（その会話の scratchpad のパス）。⚠ Playwright がファイルを扱えるのは会話の作業フォルダの下だけ（2a Task 19 の注意）。

- [ ] **Step 1: 写しと使い捨ての環境を作る**

⚠ Bash の呼び出しごとにシェルが新しくなるので、使い捨ての設定は 1 つのファイルにまとめ、以降のコマンドの先頭で `source` する。⚠ WT にも写しにも `.env` を作らない。⚠ `APP_LOCALE=ja`・`APP_FALLBACK_LOCALE=ja` を入れる（無いと検査の文が英語になる。2a Task 19 の注意）。⚠ 試しのパスワードの値はチャット・報告・写真に出さない。

```bash
SCR=<scratchpad>/p2b-browser && mkdir -p "$SCR" && git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b archive HEAD | tar -x -C "$SCR" && cp -Rc /Users/masanori/site/manage/.claude/worktrees/approval-phase2b/vendor "$SCR/vendor"
LOCAL=<scratchpad>/approval-phase2b-local.sh
cat > "$LOCAL" <<SH
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
export APP_LOCALE=ja
export APP_FALLBACK_LOCALE=ja
export DB_CONNECTION=sqlite
export DB_DATABASE="<scratchpad>/approval-phase2b.sqlite"
export TRIAL_PASSWORD="$(php -r 'echo bin2hex(random_bytes(6));')"
SH
source "$LOCAL" && touch "$DB_DATABASE" && cd "$SCR" && php artisan migrate --force && ln -s /Users/masanori/site/manage/node_modules node_modules && ./node_modules/.bin/vite build
```

⚠ 2b は新しい CSS のクラス（件数の丸など）を足したので、見る前に `vite build` が要る（写しに main repo の `node_modules` のシンボリックリンクを置く）。

- [ ] **Step 2: 試しのデータを入れて、画面を出す**

テストの土台（`BuildsApprovalFixtures`）をそのまま使う（写しの vendor は dev 依存ありなので `Tests\` を読める）。経営層のダッシュボードが読む表（賃貸マンション・不動産と住宅）もテストと同じ trait で作る。⚠ ログイン用の使い捨てルートを作らない。

```bash
source <scratchpad>/approval-phase2b-local.sh && cd <scratchpad>/p2b-browser && php artisan tinker --execute='
use App\Enums\ApprovalStepResult as R;
use Illuminate\Support\Carbon;
$f = new class {
    use Tests\Concerns\BuildsApprovalFixtures { approvalWorld as public; submittedFor as public; approvalAdmin as public; baseUser as public; launchApprovals as public; }
    use Tests\Concerns\CreatesMansionSchema { createMansionSchema as public; }
    use Tests\Concerns\CreatesRealEstateSchema { createRealEstateSchema as public; }
};
$f->createMansionSchema(); $f->createRealEstateSchema();
$wf = app(App\Support\Approval\Workflow::class);
$w = $f->approvalWorld();
$admin = $f->approvalAdmin(["name" => "決裁 管理者"]);
$f->baseUser(["name" => "代理 部長"]);
$w["president"]->forceFill(["role" => "executive"])->save();
App\Models\ApprovalMailDomain::create(["domain" => substr(strrchr($w["head"]->email, "@"), 1)]);
$f->launchApprovals();
Carbon::setTestNow(now()->subDays(5));
$f->submittedFor($w, ["subject" => "社用車の購入（5 日前から部門長の確認待ち）"]);
Carbon::setTestNow();
$re = $f->submittedFor($w, ["subject" => "事務所の改装", "amount" => 1200000]);
$wf->judgeHead($re, $w["head"], $re->lock_version, R::Return, "見積りを 2 社から取ってください");
$re->refresh()->update(["subject" => "事務所の改装（見積り 2 社）", "amount" => 1350000, "body" => $re->body . "\n・見積りを 2 社から取った"]);
$wf->submit($re->refresh(), $w["applicant"], $re->lock_version);
$done = $f->submittedFor($w, ["subject" => "コピー機の入れ替え"]);
$wf->judgeHead($done, $w["head"], $done->lock_version, R::Approve, null);
$wf->judgeReview($done->refresh(), $w["reviewer"], $done->lock_version, R::Ok, null);
$wf->judgePresident($done->refresh(), $w["president"], $done->lock_version, R::Approve, null);
$ret = $f->submittedFor($w, ["subject" => "倉庫の賃借"]);
$wf->judgeHead($ret, $w["head"], $ret->lock_version, R::Return, "契約期間を書いてください");
$ret->refresh()->update(["subject" => "倉庫の賃借（直しかけ）"]);
$cond = $f->submittedFor($w, ["subject" => "展示会の出展"]);
$wf->judgeHead($cond, $w["head"], $cond->lock_version, R::Approve, null);
$wf->judgeReview($cond->refresh(), $w["reviewer"], $cond->lock_version, R::Ok, null);
$wf->judgePresident($cond->refresh(), $w["president"], $cond->lock_version, R::Conditional, "見積りを 2 社から取ること");
$stuck = $f->submittedFor($w, ["subject" => "部門長が申請者本人"]);
App\Models\ApprovalStep::where("request_id", $stuck->id)->where("kind", "head")->update(["assignee_user_id" => $w["applicant"]->id]);
DB::table("users")->update(["password" => Hash::make(getenv("TRIAL_PASSWORD")), "must_change_password" => false]);
foreach (["applicant", "head", "reviewer", "president"] as $k) { echo $k, " ", $w[$k]->email ?? $w[$k]->employee_number, PHP_EOL; }
echo "admin ", $admin->email, PHP_EOL;
'
```

Expected: 5 人のログイン名（メールアドレスか社員番号）が出る。パスワードは `source` したシェルの `$TRIAL_PASSWORD`（値を出さない）。

画面を出す（**バックグラウンドで**。止めるまで動き続ける）:

```bash
source <scratchpad>/approval-phase2b-local.sh && cd <scratchpad>/p2b-browser && php artisan serve --port=8766
```

- [ ] **Step 3: 見ること**（1440px と 375px の両方）

| # | 画面（ログインする人） | 見ること |
|---|---|---|
| 1 | ⑩ 進行中（管理者） | 待ち日数の長い順（5 日前の申請が先頭）・「いま誰の番か」・「部門長が申請者本人」の行に印「担当が申請者本人」・差戻し中の「倉庫の賃借」は**提出した件名**（直しかけの件名が出ない）・375px は 1 件 1 枚のカード・1440px は横スクロールの案内の中の表 |
| 2 | ⑩ 決裁済み・否決（管理者） | 「コピー機の入れ替え」に決裁No と「決裁済み（可）」・タブの色と `aria-current` |
| 3 | 詳細（管理者・5 日前の申請） | 「決裁の管理者の操作」に 3 つのボタン。付け替えの小窓: 申請者が選べない・許可していないドメインの人に「※通知メールが届きません」・理由を空で送ると小窓が開き直り、理由と選んだ人が残る・送ると「部門長の確認を代理 部長さんに付け替えました。」と記録の「新しい担当: 代理 部長」と理由 |
| 4 | 取り消し（管理者・コピー機の入れ替え） | 小窓に「取り消す操作: 社長の判断（可）」と判断した人・日時・「決裁No（…）はこの申請に残り…」。取り消すと社長決裁待ち・番号はそのまま・記録に「押し間違いの取り消し（社長の可）」。続けて 2 回取り消すと部門長確認中まで戻り、取り消しのボタンが消える |
| 5 | D3（管理者・倉庫の賃借） | 取り消しのボタンが押せず、理由（申請者が直し始めている）が下に出る・ボタンに `title` |
| 6 | 代理の取り下げ（管理者・展示会の出展以外のどれか） | 理由が必須・取り下げると「申請者に代わって取り下げました。」・記録に「決裁の管理者が代理で取り下げ」 |
| 7 | 変更点と履歴（部門長・事務所の改装） | 「前回からの変更点（1 回目 → 2 回目の提出）」に件名・金額（前 → 後）と本文の増えた行（＋・緑）・「提出の履歴」の 2 回分を開ける。申請者でログインしても同じ |
| 8 | m-2（申請者・部門長が申請者本人） | 押せない判断の理由の 2 行目が「取り下げて出し直すか、決裁の管理者に部門長の確認の付け替えを頼んでください。」 |
| 9 | 基幹のサイドバー（部門長） | 展開・折りたたみ（アイコンの丸）・スマホのドロワーの 3 か所に「決裁」と件数。読み上げ（`sr-only`）が「対応待ち N 件」。件数の無い人（代理 部長）には丸が出ない |
| 10 | 決裁のみのサイドバー（申請者） | 「決裁のホーム」に件数（差戻し中・条件確認待ちの分） |
| 11 | ダッシュボード（社長＝経営層・部門長＝テナント） | 先頭のカード「決裁の対応待ち N 件」と「決裁のホームへ」 |
| 12 | サイドバーの ⑩ のリンク | 管理者には基幹と決裁のみのサイドバーの展開・ドロワーに「進行中の申請の管理」・管理者でない人には出ない |
| 13 | 使い始める前（tinker で `launched_at` を空に戻す） | 基幹のサイドバーとダッシュボードに「決裁」・件数・カードが出ない・`/approvals/admin/requests` はホーム（準備中）へ・サイドバーに ⑩ のリンクが無い。確かめたら `launched_at` を入れ直す |
| 14 | 全画面 | `main.scrollWidth === main.clientWidth` を 1800 / 1200 / 375px で（Bug #29）・コンソールのエラーと警告が 0 件 |
| 15 | 二度押し | 付け替え・取り消し・代理の取り下げの確定を、返事を遅らせて 2 回押しても POST は 1 回（2a Task 19 の注意の測り方） |

- [ ] **Step 4: 利用者に見せる写真を撮る**

375px と 1440px で、⑩ の 2 つのタブ・詳細の「決裁の管理者の操作」と 3 つの小窓・D3 で押せない取り消し・前回からの変更点と提出の履歴・基幹のサイドバーの件数（3 か所）・決裁のみのサイドバーの件数・2 つのダッシュボードのカードを撮り、scratchpad に保存して利用者に送る（`SendUserFile`。まとめのページを作るなら 2a の写真のページと同じ形）。写真には試しのデータしか写らないことを確かめる。

- [ ] **Step 5: コンパイル済みビューを lint する**

⚠ `view:cache` の成功表示だけでは足りない（Bug #21 / #26 / #30）。

```bash
source <scratchpad>/approval-phase2b-local.sh && cd <scratchpad>/p2b-browser && php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done; php artisan view:clear
```

Expected: INVALID 0 件。

- [ ] **Step 6: 片付けて結果を記録する**

`artisan serve` を止めたことを確かめてから、写しと使い捨てのファイルを消す（`rm -rf` の前に、消すのが scratchpad の写しであることを `pwd` と `ls` で確かめる）。WT の `git status --porcelain` が空であることも確かめる。見たことと見つけた不具合を、この計画の末尾に「Task 9 の実測記録」として書き足してコミットする（不具合は直してから。直すときは Task 1〜7 のテストに再現を足し、「テストを先に入れると落ち、直しを入れると緑」を確かめる）。


---

## Task 10: ドキュメント

**Files:**
- Modify: `docs/superpowers/specs/2026-09-25-approval-phase2-design.md`（計画で決めた細部の書き戻し）
- Modify: `CLAUDE.md`（Completed modules の決裁の行）・`docs/ARCHITECTURE.md`（ルートの本数・門番）・`routes/web.php`（`require …approval.php` の真上の見出しの本数）・`docs/BACKLOG.md`（段階2 の節）

- [ ] **Step 1: 設計書に書き戻す**

- 冒頭の「実装計画:」の行の最後に足す: `2b は @docs/superpowers/plans/2026-09-28-approval-phase2b.md（この設計書から変えた細部は §0.13、受け入れた隙間は §0.14）`
- §5.13 の最後に足す: `- 2b の計画で決めた細部（計画 §0.3）: 行ごとの差は前後の同じ行を除いて LCS（残りの行数の積が 250,000 を超えたら消えた行→増えた行）・種類と部門は id で比べて名前を出す・関連する決裁No は並びを無視・変更点は中身の下と添付の上・履歴は詳細の最後`
- §5.14 の最後に足す: `- 2b の計画で決めた細部（計画 §0.4〜§0.8・§0.13）: 取り消す操作の探し方と戻し方の表（計画 §0.4）・D3 は比べる値だけを決まった並びに詰め直して比べる（名前は入れない）・取り消された判断をした人は記録の判断した人として見続けられる（計画 §0.5）・付け替えは lock_version だけ進める（待ち日数は元の届いた日から）・操作は詳細（③）に置き、⑩ は見つけるための一覧（タブ「進行中」「決裁済み・否決」）`
- §5.15 の最後に足す: `- 2b の計画で決めた細部（計画 §0.10）: 件数は PendingWork::countFor()（for() と同じ条件）を ApprovalMenu が 1 リクエストに 1 回だけ数える・0 件はサイドバーに丸を出さず、ダッシュボードのカードは 0 件でも出す`
- §5.16 の 14 の項目の最後に、どう扱ったかを足す（項目の頭の言葉 → 足す文）:

| §5.16 の項目 | 足す文 |
|---|---|
| **取り消し（D24）**: `approval_histories.step_id` は… | `→ 2b: 段階の行は消さずに状態を戻した（計画 §0.4）` |
| **取り消し**: 「今の回の最後の操作」を探すときは… | `→ 2b: 付け替え（reassigned）と取り消し（undone）の記録も飛ばす（計画 §0.4）` |
| **取り消し**: 段階を「待ち」「まだ届いていない」に戻すとき… | `→ 2b: 空にしない。待ち日数は元の届いた日から数え、回る順番の「届きました」は待ちの段階だけに出す（計画 §0.4）` |
| **取り消し**: 取り消した判断の `actor_user_id` を消すと… | `→ 2b: 段階の判断した人は空に戻し、見られる範囲は記録の判断した人で保った（計画 §0.5）` |
| **差戻しの取り消し（D3）**: 最後の控えと今の中身を比べるときは… | `→ 2b: 比べる値だけを決まった並びに詰め直して === で比べた（RequestSnapshot::editableFingerprint()。計画 §0.4・§0.13 の 1）` |
| **差戻しの取り消し（D3）**: 添付の追加・外すでは… | `→ 2b: そのとおり（申請の行をロックしてから、今の添付も控えと比べる。計画 §0.4）` |
| **付け替え（D2）**: 部門長の交代（`Workflow::headChanged()`）と同じ形にする… | `→ 2b: そのとおり（計画 §0.6）` |
| **付け替え**: 付け替えた担当の行（`RequestVisibility` の担当の条件）は… | `→ 2b: 付け替えるのは部門長の段階だけなので、条件は足していない（計画 §0.6）` |
| **付け替え**: 付け替えを作ったら、部門長の段階の押せない理由の 2 行目… | `→ 2b: 済み（計画 §0.11 の m-2）` |
| **対応待ち**: `PendingWork` は段階の「待ち」だけを見て… | `→ 2b: 突き合わせのテストに管理者の操作の場面を足し、メニューの件数も PendingWork::countFor()（for() と同じ条件）にした（計画 §0.10）` |
| **改行**: 管理者の操作の理由など 2b で増える入力欄も… | `→ 2b: FormInput の 1 か所に寄せた（計画 §0.11 の m-7）` |
| **門番（⑩）**: `approvals.admin.requests.*` は… | `→ 2b: そのとおり（計画 §0.12）` |
| **削除した利用者**: 添付の `uploaded_by`・… | `→ 2b: 画面に出す名前は既存の関係（withTrashed() 付き）から読み、新しい関係は足していない（計画 §0.8）` |
| **中身の出し分け（D26）**: ⑩ の一覧と履歴で件名などの中身を出すときも… | `→ 2b: ⑩ の一覧・変更点・履歴は控えから出し、関連する決裁No の候補は差戻し中なら控えの件名で当てて返す（計画 §0.3・§0.8・Task 6）` |

- §9 の「取り消し（2b）の戻し方の細部…と、D3 の「変えていた」の比べ方」の行の最後に `→ 2b の計画 §0.4 で決めた`、「行ごとの差の部品の作り方（2b）」の行の最後に `→ 2b の計画 §0.3 で決めた` を足す

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b add docs/superpowers/specs/2026-09-25-approval-phase2-design.md
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b commit -m "$(cat <<'MSG'
docs(approval): 設計書に 2b の計画で決めた細部を書き戻す

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

- [ ] **Step 2: 画面・ルート・門番を書き換える**

`CLAUDE.md` の Completed modules の表の決裁の行を置き換える:

```markdown
| 決裁申請 段階1・2a・2b | `/approvals/*` | `Approval\*Controller`（門番 3 本・CSV 一括登録・ログイン案内・申請の回覧と進行中の申請の管理。状態を変えるのは `App\Support\Approval\Workflow` だけ）|
```

`docs/ARCHITECTURE.md`

- `routes/approval.php` の行: `# 決裁申請 段階1・2a・2b (43 ルート。管理系は approval.admin、申請を回す画面は approval.launched、進行中の申請の管理は両方)`
- 決裁（段階2）の行の最後に足す: `。進行中の申請の管理（⑩ `approvals.admin.requests.*`）は `approval.admin` と `approval.launched` の両方の門番の内側。付け替え・押し間違いの取り消し・代理の取り下げも `Workflow` が行う`

`routes/web.php` の `require __DIR__ . '/approval.php';` の真上の見出しの「決裁申請（39ルート）」を「決裁申請（43ルート）」にする。

`docs/BACKLOG.md` の「🚧 決裁申請 段階2（決裁の本体）」の節の見出しを「🚧 決裁申請 段階2（決裁の本体）— 2a 本番反映済み・2b 実装済み・本番反映の前」にし、2a の節の後（「⚠ `origin/13.x` への push はしていない。」の後）に足す:

```markdown
### 2b（出し直しの変更点と履歴・進行中の申請の管理・基幹のメニューの件数）

実装計画: @docs/superpowers/plans/2026-09-28-approval-phase2b.md（Task 0〜11）。worktree `.claude/worktrees/approval-phase2b`（ブランチ `approval-phase2b`）。

- 表の変更なし（2a の表に 2b の列がある）。本番反映は `composer dump-autoload` と `./deploy.sh` だけ
- 計画で決めた細部: 取り消しの探し方と戻し方（計画 §0.4）・取り消された判断をした人も見続けられる（§0.5）・管理者の操作は詳細に置き、⑩ は「進行中」「決裁済み・否決」の 2 つのタブ（§0.8）・断られた小窓は送り先で決めて開き直す（§0.9。2a の m-6）・2a の軽微 m-2・m-6・m-7・保存の版・N-1 を片付けた（§0.11）
- 使い始める前は、基幹のメニューとダッシュボードに何も足さない（件数とカードは `launched_at` が入ってから）
```

- [ ] **Step 3: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b add CLAUDE.md docs/ARCHITECTURE.md routes/web.php docs/BACKLOG.md
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase2b commit -m "$(cat <<'MSG'
docs: 決裁 段階2b の画面・ルート・門番をドキュメントに反映する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```


---

## Task 11: 本番反映（利用者の了承を取ってから・親の会話が行う）

> ⚠ **サブエージェントに任せない。** 本番への `ssh`・`scp`・`./deploy.sh` は、それぞれ利用者の了承を取ってから行う。本番のファイルの削除は利用者が行う（実行する 1 行を渡す）。`.env` は読まない。
> ⚠ 本番のシェルは csh なので、`/bin/sh` の heredoc を `ssh` に流す（段階1・2a と同じ作法）。PHP は `/usr/local/php/8.3/bin/php` を明示する（既定の `php` は 7.4）。

- [ ] **Step 1: 了承を求める（選択式）**

伝えること: ①**表の変更は無い**（本番の DB は触らない。新しいクラスがあるので読み込みの表を作り直してから `./deploy.sh`）②**使い始める前なので、利用者の画面で見た目が変わるところはほぼ無い**（⑩・件数・カード・詳細の管理者の操作は `launched_at` が入るまで出ない。変わるのは、決裁の管理者の申請種類の一覧の「審査担当者がいません」が無効の人を数えなくなることだけ）③戻すときは `13.x` の前のコミットで `./deploy.sh`（DB の戻しは要らない）。

- [ ] **Step 2: 反映前に本番を読み取る**（読み取りだけ）

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage || exit 1
/usr/local/php/8.3/bin/php artisan route:list --name=approvals. --json | /usr/local/php/8.3/bin/php -r '$r = json_decode(stream_get_contents(STDIN), true); echo "approvals=", count($r), PHP_EOL;'
/usr/local/php/8.3/bin/php artisan tinker --execute='
echo "launched_at=", var_export(App\Models\ApprovalSetting::current()->launched_at, true), PHP_EOL;
$db = app("db");
foreach (["approval_requests", "approval_steps", "approval_histories", "approval_revisions"] as $t) { echo $t, "=", $db->table($t)->count(), PHP_EOL; }
'
ls -la storage/logs/laravel.log
SH
```

Expected: `approvals=39`（2a のまま）・`launched_at=NULL`・4 表とも 0 行（使い始める前）。違えば止まり、利用者に伝える。

- [ ] **Step 3: `13.x` へ早送りで取り込む**（手元）

```bash
cd /Users/masanori/site/manage && git status --short && git merge-base --is-ancestor 13.x approval-phase2b && echo "FF できる" || echo "13.x が進んでいる"
```

「FF できる」なら:

```bash
git -C /Users/masanori/site/manage merge --ff-only approval-phase2b && git -C /Users/masanori/site/manage log --oneline -3
```

「13.x が進んでいる」なら止まり、取り込み方（WT で `13.x` をマージしてから全件を流し直す、など）を利用者に選んでもらう（ほかの会話の作業が入っている）。

- [ ] **Step 4: 新しいクラスを読み込めるようにする**（手元の main repo で）

```bash
cd /Users/masanori/site/manage && test ! -e vendor/bin/phpunit && composer dump-autoload --no-dev --optimize && git status --short
```

Expected: `vendor/bin/phpunit` が無い（dev の部品が混ざっていない）・`git status` に何も出ない（`composer.lock` は変わらない）。⚠ **main repo の cwd で行う**（worktree から行うと autoloader に worktree のパスが焼き込まれる）。

- [ ] **Step 5: 反映**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

Expected: exit 0・6 段すべて成功（CSS が変わるので旧バンドルの掃除が出る）。

- [ ] **Step 6: 本番で確かめる**（すべて読み取り）

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage || exit 1
n=0; bad=0
for f in storage/framework/views/*.php; do n=$((n+1)); /usr/local/php/8.3/bin/php -l "$f" >/dev/null 2>&1 || { bad=$((bad+1)); echo "INVALID: $f"; }; done
echo "views=$n invalid=$bad"
/usr/local/php/8.3/bin/php artisan route:list --name=approvals. --json | /usr/local/php/8.3/bin/php -r '$r = json_decode(stream_get_contents(STDIN), true); echo "approvals=", count($r), PHP_EOL;'
/usr/local/php/8.3/bin/php artisan tinker --execute='
echo "launched_at=", var_export(App\Models\ApprovalSetting::current()->launched_at, true), PHP_EOL;
foreach (["App\\Support\\Approval\\FormInput", "App\\Support\\Approval\\LineDiff", "App\\Support\\Approval\\UndoTarget", "App\\Support\\Approval\\Assignees", "App\\Support\\Approval\\ApprovalMenu", "App\\Http\\Controllers\\Approval\\AdminRequestController"] as $c) {
    echo $c, "=", class_exists($c) ? "ok" : "NG", PHP_EOL;
}
'
ls -la storage/logs/laravel.log
SH
curl -s https://www.mitsuwat.co.jp/system/manage/index.php/login | grep -c "社員番号またはメールアドレス"
```

Expected: `invalid=0`・**`approvals=43`**（`--json` で数える。テキストの `grep -c` は長い行を省いて少なく数える）・`launched_at=NULL`・6 つとも `ok`・`laravel.log` の日時が反映の前から変わっていない（反映のあとのエラー 0 件）・ログイン画面の文字が 1 以上。

ログインした画面の確認は、利用者の了承を取ってから、利用者のブラウザ（ログイン済み。**フォームは送らない**）で行う:

| # | 見ること |
|---|---|
| 1 | 基幹のダッシュボード（経営層・テナント）が開き、「決裁」のカードが**無い**（使い始める前） |
| 2 | 基幹のサイドバーに「決裁」の項目も件数も**無い** |
| 3 | `/approvals` が「決裁の機能は準備中です。…」のまま |
| 4 | `/approvals/admin/requests` を URL で開くと、決裁の管理者ならホーム（準備中）へ送られ、管理者でなければ 403 |
| 5 | 決裁の管理者がいれば、部門の管理と申請種類の管理が開く |
| 6 | 基幹の画面の `main` のはみ出し 0・コンソールのエラー 0 件 |

- [ ] **Step 7: 記録する**

`docs/BACKLOG.md` の段階2 の節の見出しを「🚧 決裁申請 段階2（決裁の本体）— 2a・2b 本番反映済み・使い始める前」にし、2b の節に反映日・`13.x` のコミット・Step 2〜6 で見たことの表を書き足してコミットする（`docs:` 1 本）。

⚠ `origin/13.x` への push は利用者の明示の指示があったときだけ。⚠ worktree `approval-phase2b` とブランチの片付けは利用者に聞いてから（dev の vendor の置き場なので、次の段階の worktree へ `cp -Rc` してから消す）。


---

## Task 8 の実測記録（2026-09-29）

測ったのは WT の HEAD `136bdc7a`（Task 1〜7 と、点検の手直し〈Task 2・4・6〉まで）。**変異は WT に当てず、HEAD の写しに当てた**（scratchpad の `p2b-mutation`。`git archive` と vendor の複製）。道具は `~/.claude/plans/approval-phase2b-tasks/mutate.py`（W07・R01・R02 はコントローラが手直しのあとのコードに合わせた版。この Task で「点検で見つけた変異」27 通りを後ろに足した。`REVIEW` と書くとそれとカナリアだけを流す）。1 つの変異で流したのは mutate.py の TARGET（`tests/Feature/Approval`・`tests/Unit/Approval`・走査テスト 6 本・`PropertyListSortTest`）。出力（jsonl・ログ）と道具の写しは `~/.claude/plans/approval-phase2b-tasks/work/rf/t08/`。

### 基準とカナリア

| 項目 | 結果 |
|---|---|
| 全件（Step 1・WT の `136bdc7a`） | `git status --porcelain` が空・`OK (2866 tests, 20092 assertions)`（計画の 2,860 本に、点検の手直しで足した 6 本〈Task 2 +1・Task 4 +1・Task 6 +4〉） |
| 1 つの変異で流す範囲（写し・変異なし） | `OK (805 tests, 6189 assertions)`（表の 799 本 + 手直しの 6 本） |
| カナリア（`_history.blade.php` に未定義の変数） | `Tests: 805, Assertions: 5536, Failures: 67.`（表と同じ 67 本が 500＝未定義の変数。測る仕掛けは写しのコードを読んでいる）。足したあとの写し（`fc67eff8`）では 72 本（足したテストのうち詳細を開く 5 本が加わった） |
| 変異の文字列 | 98 通りとも、写しで 1 回だけ現れる（`mutate.py --check`） |

### 結果のまとめ

| 区分 | 通り | 変異 |
|---|---|---|
| 計画の表: 検出 | 60 | CANARY と、下の 2 行（当初検出漏れ・等価）を除くすべて |
| 計画の表: 当初検出漏れ→追加で検出 | 4 | S03・C03・A03・A08（計画を書く段階で足したテストが、今の実装でも落とす） |
| 計画の表: 等価（緑が正しい） | 6 | U01・U04・U06・U08・W04・A04（下の「等価の確かめ」） |
| 点検で見つけた変異: 検出済みだった（点検が流した範囲の外のテストが落としていた） | 5 | S10・C08・C13・C14・P04（足したテストでも落ちる） |
| 点検で見つけた変異: 別の機構でだけ検出されていた→追加で検出 | 1 | N08（`PropertyListSortTest` の問い合わせの本数でだけ落ちていた。表の N05 も同じ） |
| 点検で見つけた変異: 当初検出漏れ（緑）→追加で検出 | 21 | F04・S07・S08・S09・S11・C06・C07・C09・C10・C11・C12・V02・W15・W16・A21・A22・A23・A24・A25・N06・N07 |
| 点検で見つけた変異: 等価 | 0 | — |

- 計画の表の 71 通りは、区分（検出・等価・カナリア）がすべて表と同じ。落ちたテストの集合は、表のテストがすべて落ち、増えたのは点検の手直しで足したテストだけ（下の「表と違ったもの」）。落ちた理由の文言も 1 つずつ読み、狙いどおりだった。別の機構でだけ落ちていたのは N05 の 1 つ（下の「表と違ったもの」）
- W07・R01・R02 は、手直しのあとのコード（`reopenStep()` を主キーで書く・控えの件名を差戻し中と取り下げで使う）で測り直した。どれも検出

### 計画の表と違ったもの

区分（検出・等価・カナリア）が表と違う変異は無い。表の「落ちたテスト」は 71 通りとも今回もすべて落ちた。違いは次の 4 通りで、どれも**落ちるテストが増えただけ**（増えたのは点検の手直しで足したテスト）。

| # | 増えたテスト | わけ |
|---|---|---|
| L02 | `LineDiffTest::test_two_edits_keep_the_unchanged_lines_between_them` | Task 2 の点検 I-1 の手直し（`96d87e99`）で足した LCS のテスト |
| W02 | `RelatedNumberSearchTest::test_a_withdrawn_request_with_a_number_shows_its_submitted_subject`（2 データ）・`test_a_resubmitted_request_uses_the_revision_of_the_last_submission` | Task 6 の手直し（`136bdc7a`）で足したテスト。どれも前提「番号が残ったまま」で止まる（番号を消す変異なので狙いどおり） |
| R01・R02 | 同上の 3 本 | 同上。R01・R02 は手直しのあとのコード（控えの件名を使うのは差戻し中と取り下げ）に合わせた変異で測った |

- W07 は手直しのあとの `reopenStep()`（後ろの段階を主キーだけで書く）に合わせた変異で測り、落ちたテストの集合は表と同じ
- 落ちた理由の文言も 1 つずつ読んだ。書き残すのは次の 2 つ（別の機構でだけ落ちていたのは N05 だけ）
  - **A01**（表と同じ `Errors: 1`・`Call to a member function all() on array`）: `assertNotFound()` が失敗したあと、Laravel の `TestResponseAssert::injectResponseContext()` が失敗の文にセッションの `errors` を添えようとして落ちたもの（写しで再現）。失敗そのものは「404 にならない」＝狙いどおりなので、測定は有効
  - **N05**（表と同じ 1 本）: 落とすのは `PropertyListSortTest` の問い合わせの本数（「物件が増えるとクエリが増える（N+1）: 5 件で 7 本 / 25 件で 5 本」）で、「設定の行を作らない」を見るテストではなかった（Task 7 の点検 Minor 2 と同じ）。この Task で `ApprovalMenuTest::test_a_base_page_does_not_create_the_settings_row` を足し、狙いの文言（「基幹の画面が設定の行を作った」）でも落ちるようにした（下の N08）

### 計画の表の測り直し（71 通り。写しは `136bdc7a`・805 本）

| # | 変異（ファイル） | 今回 | 落ちたテストの集合（表と比べて） | 判定 |
|---|---|---|---|---|
| CANARY | カナリア: 履歴の部品に未定義の変数（`_history.blade.php`） | 赤（Failures 67） | 同じ本数（どれも 500＝未定義の変数） | カナリア（赤が正しい） |
| L01 | 大きすぎる差の出し方を「増えた行→消えた行」に（`LineDiff.php`） | 赤（Failures 1） | 同じ | 検出 |
| L02 | LCS の同じ長さのとき増えた行を先に（`LineDiff.php`） | 赤（Failures 2） | 同じ＋`test_two_edits_keep_the_unchanged_lines_between_them` | 検出 |
| L03 | 行に分ける前に改行をそろえない（`LineDiff.php`） | 赤（Failures 1） | 同じ | 検出 |
| S01 | 種類を名前で比べる（`RequestSnapshot.php`） | 赤（Failures 1） | 同じ | 検出 |
| S02 | 関連する決裁No を並びも込みで比べる（`RequestSnapshot.php`） | 赤（Failures 1） | 同じ | 検出 |
| S03 | 変更点の金額で 0 と空を同じにする（`RequestSnapshot.php`） | 赤（Failures 1） | 同じ | 検出（当初検出漏れ→追加で検出） |
| S04 | 指紋の金額で 0 と空を同じにする（`RequestSnapshot.php`） | 赤（Failures 1） | 同じ | 検出 |
| S05 | 指紋から添付を外す（`RequestSnapshot.php`） | 赤（Failures 2） | 同じ | 検出 |
| S06 | 指紋に種類の名前を入れる（`RequestSnapshot.php`） | 赤（Failures 1） | 同じ | 検出 |
| C01 | 変更点を今の中身（直しかけ）と比べる（`RequestController.php`） | 赤（Failures 2） | 同じ | 検出 |
| C02 | 履歴の添付を今の添付にする（`_history.blade.php`） | 赤（Failures 3） | 同じ | 検出 |
| C03 | 履歴の判断を回で絞らない（`_history.blade.php`） | 赤（Failures 1） | 同じ | 検出（当初検出漏れ→追加で検出） |
| C04 | 変更点の「外した添付」の読み上げを消す（`_changes.blade.php`） | 赤（Failures 2） | 同じ | 検出 |
| C05 | 付け替えの記録に新しい担当を添えない（`RequestController.php`） | 赤（Failures 1） | 同じ | 検出 |
| U01 | 省略・交代・付け替えを飛ばさない（`UndoTarget.php`） | 緑 | 同じ（緑） | 等価 |
| U02 | 取り消し済みの記録を飛ばさない（`UndoTarget.php`） | 赤（Errors 2・Failures 1） | 同じ | 検出 |
| U03 | 取り消せない操作を越えて探し続ける（`UndoTarget.php`） | 赤（Failures 1） | 同じ | 検出 |
| U04 | 前の回の記録も探す（`UndoTarget.php`） | 緑 | 同じ（緑） | 等価 |
| U05 | D3: いつも「変えていない」（`UndoTarget.php`） | 赤（Failures 2） | 同じ | 検出 |
| U06 | D3: 控えが無ければ「変えていない」（`UndoTarget.php`） | 緑 | 同じ（緑） | 等価（守り） |
| U07 | D3: 社長の差戻しを確かめない（`UndoTarget.php`） | 赤（Failures 1） | 同じ | 検出 |
| U08 | D3: 差戻し以外の取り消しでも確かめる（`RequestPermissions.php`） | 緑 | 同じ（緑） | 等価 |
| W01 | 社長の判断の取り消しで decision を残す（`Workflow.php`） | 赤（Failures 3） | 同じ | 検出 |
| W02 | 社長の判断の取り消しで番号も消す（`Workflow.php`） | 赤（Failures 8） | 同じ＋`test_a_resubmitted_request_uses_the_revision_of_the_last_submission`、`test_a_withdrawn_request_with_a_number_shows_its_submitted_subject` | 検出 |
| W03 | 条件の確認の取り消しで finished_at を残す（`Workflow.php`） | 赤（Failures 1） | 同じ | 検出 |
| W04 | 条件の確認の取り消しでも段階を戻す（`Workflow.php`） | 緑 | 同じ（緑） | 等価 |
| W05 | 取り消しで arrived_at を空にする（`Workflow.php`） | 赤（Failures 8） | 同じ | 検出 |
| W06 | 取り消しで段階の判断した人を残す（`Workflow.php`） | 赤（Failures 8） | 同じ | 検出 |
| W07 | 後ろの待ちの段階を戻さない（`Workflow.php`） | 赤（Failures 5） | 同じ | 検出 |
| W08 | 取り消しで D3 を確かめない（`Workflow.php`） | 赤（Failures 1） | 同じ | 検出 |
| W09 | 取り消した記録の id を残さない（`Workflow.php`） | 赤（Errors 2・Failures 9） | 同じ | 検出 |
| W10 | 申請者本人へ付け替えられる（`Workflow.php`） | 赤（Failures 1） | 同じ | 検出 |
| W11 | いまの担当へ付け替えられる（`Workflow.php`） | 赤（Failures 2） | 同じ | 検出 |
| W12 | 付け替えで版を進めない（`Workflow.php`） | 赤（Failures 2） | 同じ | 検出 |
| W13 | 無効の人へ付け替えられる（`Workflow.php`） | 赤（Failures 1） | 同じ | 検出 |
| W14 | 代理の取り下げの理由をコメントに入れる（`Workflow.php`） | 赤（Failures 1） | 同じ | 検出 |
| P01 | D25: 自分の申請にも操作できる（`RequestPermissions.php`） | 赤（Failures 3） | 同じ | 検出 |
| P02 | 審査中・社長決裁待ちも付け替えられる（`RequestPermissions.php`） | 赤（Errors 2・Failures 1） | 同じ | 検出 |
| P03 | 決裁済みも代理で取り下げられる（`RequestPermissions.php`） | 赤（Failures 2） | 同じ | 検出 |
| V01 | 判断を取り消された人が見られない（`RequestVisibility.php`） | 赤（Failures 1） | 同じ | 検出 |
| A01 | 見られない申請を 404 にしない（`AdminRequestController.php`） | 赤（Errors 1） | 同じ | 検出 |
| A02 | 理由の改行をそろえない（`AdminRequestController.php`） | 赤（Failures 2） | 同じ | 検出 |
| A03 | 理由の 2,000 文字の上限を外す（`AdminRequestController.php`） | 赤（Failures 1） | 同じ | 検出（当初検出漏れ→追加で検出） |
| A04 | 理由の必須を入力の検査から外す（Workflow が断る）（`AdminRequestController.php`） | 緑 | 同じ（緑） | 等価（守りの二重） |
| A05 | ⑩ の件名を今の件名にする（D26）（`AdminRequestController.php`） | 赤（Failures 1） | 同じ | 検出 |
| A06 | ⑩ を待ち日数の短い順にする（`AdminRequestController.php`） | 赤（Failures 1） | 同じ | 検出 |
| A07 | 審査担当者の印で無効の人も数える（`AdminRequestController.php`） | 赤（Failures 1） | 同じ | 検出 |
| A08 | 部門長の印で付け替えを見ない（`AdminRequestController.php`） | 赤（Failures 1） | 同じ | 検出（当初検出漏れ→追加で検出） |
| A09 | 決裁済みのページ送りでタブを落とす（`AdminRequestController.php`） | 赤（Failures 1） | 同じ | 検出 |
| A10 | ⑩ の門番から管理を外す（`approval.php`） | 赤（Failures 2） | 同じ | 検出 |
| A11 | ⑩ の門番から稼働を外す（`approval.php`） | 赤（Failures 2） | 同じ | 検出 |
| A12 | 断られたら取り消しの小窓を開く（送り先を見ない）（`_admin_actions.blade.php`） | 赤（Failures 2） | 同じ | 検出 |
| A13 | 取り消しの小窓が版を送らない（`_admin_actions.blade.php`） | 赤（Failures 3） | 同じ | 検出 |
| A14 | 付け替え先の選択肢に申請者を出す（`RequestController.php`） | 赤（Failures 1） | 同じ | 検出 |
| A15 | 取り消しの記録に元の操作の名前を添えない（`ApprovalHistory.php`） | 赤（Failures 1） | 同じ | 検出 |
| A16 | 届いた日時をどの段階にも出す（`_steps.blade.php`） | 赤（Failures 1） | 同じ | 検出 |
| A17 | m-2 の文を 2a に戻す（`_actions.blade.php`） | 赤（Failures 1） | 同じ | 検出 |
| A18 | 取り下げの断りで条件確認の小窓を開く（m-6）（`RequestActionController.php`） | 赤（Failures 3） | 同じ | 検出 |
| A19 | 判断の断りで小窓を開き直さない（`RequestActionController.php`） | 赤（Failures 2） | 同じ | 検出 |
| A20 | 担当に選べる人の検査でメールを見ない（`Assignees.php`） | 赤（Failures 1） | 同じ | 検出 |
| R01 | 差戻し中も今の件名を返す（`RelatedNumberController.php`） | 赤（Failures 4） | 同じ＋`test_a_resubmitted_request_uses_the_revision_of_the_last_submission`、`test_a_withdrawn_request_with_a_number_shows_its_submitted_subject` | 検出 |
| R02 | 差戻し中も今の件名で当てる（`RelatedNumberController.php`） | 赤（Failures 4） | 同じ＋`test_a_resubmitted_request_uses_the_revision_of_the_last_submission`、`test_a_withdrawn_request_with_a_number_shows_its_submitted_subject` | 検出 |
| N01 | 件数に申請者の番を数えない（`PendingWork.php`） | 赤（Failures 3） | 同じ | 検出 |
| N02 | 件数を 1 リクエストで覚えない（`ApprovalMenu.php`） | 赤（Failures 1） | 同じ | 検出 |
| N03 | 使い始める前も件数を出す（`ApprovalMenu.php`） | 赤（Failures 6） | 同じ | 検出 |
| N04 | 0 件でも丸を出す（`sidebar-item.blade.php`） | 赤（Failures 1） | 同じ | 検出 |
| N05 | メニューの読み取りで設定の行を作る（`ApprovalSetting.php`） | 赤（Failures 1） | 同じ | 検出（ただし `PropertyListSortTest` の問い合わせの本数でだけ。Task 8 で直のテストを足した＝下の N08） |
| F01 | 版の桁の上限を外す（`FormInput.php`） | 赤（Failures 1） | 同じ | 検出 |
| F02 | 版が無いとき与えた値を使わない（`FormInput.php`） | 赤（Failures 2） | 同じ | 検出 |
| F03 | N-1: 無効の審査担当者も数える（`TypeController.php`） | 赤（Failures 1） | 同じ | 検出 |

### 等価の確かめ（計画の表が「等価」とした 6 通り。今のコードで 1 行ずつ読み直した）

| # | 緑のままでよい理由（今のコード） |
|---|---|
| U01 | 省略（提出のとき）・交代（部門長の段階が待ちのときだけ）・付け替え（部門長確認中で待ちのときだけ）の記録の下には、その回の取り消していない判断が無い。飛ばさなくても `UNDOABLE` でないので null を返し、飛ばしたときと同じく「取り消せる操作なし」になる |
| U04 | その回は必ず提出・出し直しの記録で始まり、探すのはそこで止まる（取り消せない操作に当たったら null）。回の条件を外しても前の回の記録まで届かない |
| U06 | 控えは提出と同じ取引で作る（`submit()`）。`round >= 1` の申請に今の回の控えが無いことは起きない（守り） |
| U08 | 申請者が中身・添付を直せるのは下書きと差戻し中だけ。差戻し以外の取り消しの対象（審査中・社長決裁待ち・決裁済み・否決・条件確認待ちからの取り消し）では今の中身が今の回の控えと同じなので、確かめても断らない |
| W04 | 条件の確認の記録には段階が付かない（`step_id` が空 → `reopenStep()` の主キー 0 は 0 行）。その回の段階は済みだけで「待ち・打ち切り」が無いので、後ろの段階も書かない（Task 4 の手直しの `reopenStep()` でも同じ） |
| A04 | 付け替え・取り消し・代理の取り下げの 3 つとも `Workflow::requireReason()` が同じ文（「理由を入力してください。」）で断り、送り先がフラッシュに残した小窓が同じく開き直す（守りの二重） |

### 点検で見つけた変異（Task 8 で足した 27 通り）

各 Task の点検の報告（`task-N-review.md`）で「実装をこう壊してもテストが緑のまま」とされたものを、新しい ID で mutate.py の後ろに足した（既存の 71 通りと表は変えていない）。「足す前」は `136bdc7a` の写し、「足したあと」は下のテストのコミットを積んだ `fc67eff8` の写しで、どちらも TARGET 全体で流した。27 通りとも、足したあとは**足したテストが狙いの文言で落とす**（例: C11 は `エスケープせずに出した: <b>前の件名</b>`・N08 は「基幹の画面が設定の行を作った」）。表の N05 も、足したテストが「基幹の画面が設定の行を作った」で落とすようになった。等価と判断したものは無い。

| # | 点検の指摘 | 変異（ファイル） | 足す前（136bdc7a の写し・805 本） | 判断（1 行） | 足したテスト | 足したあと（fc67eff8 の写し・823 本） |
|---|---|---|---|---|---|---|
| F04 | Task 1 M-1 | 版の形の終わりを `\z` でなく `$` で見る（`FormInput.php`） | 緑 | 等価でない: `lockVersion("1\n")` が 1 を返す（部品の約束「整数の形だけ」が崩れる。画面からは TrimStrings が先に落とすので起きない） | `FormInputTest::test_lock_version_is_read_only_in_the_shape_of_a_whole_number` にデータ「末尾の改行」「末尾の空白」 | 赤（Failures 1） `test_lock_version_is_read_only_in_the_shape_of_a_whole_number` |
| S07 | Task 2 m-1 | 変わったものの有無で本文を見ない（`RequestSnapshot.php`） | 緑 | 等価でない: 本文だけ直した出し直しに「前回の提出から、中身と添付は変わっていません。」と出る | `RequestSnapshotTest::test_a_single_kind_of_change_is_a_change`（本文だけ・項目だけ・添付を足すだけ・外すだけ） | 赤（Failures 2） `test_a_removed_body_line_is_struck_through_and_read_out`、`test_a_single_kind_of_change_is_a_change` |
| S08 | Task 2 m-1 | 変わったものの有無で項目を見ない（`RequestSnapshot.php`） | 緑 | 等価でない: 項目だけ直した出し直しに「変わっていません」と出る | 同上 | 赤（Failures 2） `test_the_third_round_is_compared_with_the_second`、`test_a_single_kind_of_change_is_a_change` |
| S09 | Task 2 m-1 | 変わったものの有無で足した添付を見ない（`RequestSnapshot.php`） | 緑 | 等価でない: 添付を足すだけの出し直しに「変わっていません」と出る | 同上 | 赤（Failures 1） `test_a_single_kind_of_change_is_a_change` |
| S10 | Task 2 m-1 | 変わったものの有無で外した添付を見ない（`RequestSnapshot.php`） | 赤（Failures 1） `test_the_detail_lists_the_attachments_of_the_shown_content` | 検出済み（点検が流した範囲の外の `RequestAttachmentTest` が落とす）。単体でも同上のテストで押さえた | 同上 | 赤（Failures 2） `test_the_detail_lists_the_attachments_of_the_shown_content`、`test_a_single_kind_of_change_is_a_change` |
| S11 | Task 2 m-2 | 指紋の添付を id でなく数にする（`RequestSnapshot.php`） | 緑 | 等価でない: 同じ数のまま添付を差し替えても D3 が「直していない」とみなし、差戻しを取り消せる | `RequestSnapshotTest::test_the_fingerprint_changes_with_any_editable_value` に「添付を差し替える」 | 赤（Failures 1） `test_the_fingerprint_changes_with_any_editable_value` |
| C06 | Task 3 m-1 | 変更点をいつも 1 回目と比べる（`RequestController.php`） | 緑 | 等価でない: 3 回目の出し直しの変更点が 1 回目 → 3 回目の差になる（見出しは「2 回目 → 3 回目」のまま） | `RequestChangesTest::test_the_third_round_is_compared_with_the_second` | 赤（Failures 1） `test_the_third_round_is_compared_with_the_second` |
| C07 | Task 3 m-2 | 全部の回に「最後に提出した中身」の印を付ける（`_history.blade.php`） | 緑 | 等価でない: どの回も最後に提出した中身に見える | 同上（印は 1 つだけ） | 赤（Failures 1） `test_the_third_round_is_compared_with_the_second` |
| C08 | Task 3 m-3 | 詳細から提出の履歴を外す（`show.blade.php`） | 赤（Failures 3） `test_comments_and_names_are_escaped`、`test_a_first_submission_has_no_changes_section`、`test_the_history_shows_each_round_with_its_own_content` | 検出済み（`RequestChangesTest` ほかが落とす）。ただし `RequestFormTest:524` は履歴が無いとページ全体を見て通っていた | `RequestFormTest::test_others_see_the_latest_round_that_was_submitted` の「提出の履歴」を `assertNotFalse` で確かめてから切り出す | 赤（Failures 6） `test_comments_and_names_are_escaped`、`test_a_first_submission_has_no_changes_section`、`test_the_history_shows_each_round_with_its_own_content`、`test_the_third_round_is_compared_with_the_second`、`test_the_changes_and_the_history_escape_the_submitted_values`、`test_others_see_the_latest_round_that_was_submitted` |
| C09 | Task 3 m-4 (a) | 変更点の本文の「消えた行:」の読み上げを消す（`_changes.blade.php`） | 緑 | 等価でない: 読み上げで消えた行と分からない（色だけに頼らない。要件 14.4） | `RequestChangesTest::test_a_removed_body_line_is_struck_through_and_read_out` | 赤（Failures 1） `test_a_removed_body_line_is_struck_through_and_read_out` |
| C10 | Task 3 m-4 (b) | 履歴の添付のリンク先をずらす（`_history.blade.php`） | 緑 | 等価でない: 履歴の添付のリンクが別の添付を開く | `RequestChangesTest::test_the_history_shows_each_round_with_its_own_content` に 1 回目のリンク先 | 赤（Failures 1） `test_the_history_shows_each_round_with_its_own_content` |
| C11 | Task 3 m-5 | 変更点の項目の「前」をエスケープしない（`_changes.blade.php`） | 緑 | 等価でない: 申請者の打った件名がそのまま HTML になる | `RequestChangesTest::test_the_changes_and_the_history_escape_the_submitted_values` | 赤（Failures 1） `test_the_changes_and_the_history_escape_the_submitted_values` |
| C12 | Task 3 m-5 | 変更点の本文の増えた行をエスケープしない（`_changes.blade.php`） | 緑 | 等価でない: 同上（本文の行） | 同上 | 赤（Failures 1） `test_the_changes_and_the_history_escape_the_submitted_values` |
| C13 | Task 3 m-5 | 履歴の件名をエスケープしない（`_history.blade.php`） | 赤（Failures 1） `test_user_written_strings_are_escaped` | 検出済み（`RequestFormTest::test_user_written_strings_are_escaped`）。同上のテストでも押さえた | 同上 | 赤（Failures 2） `test_the_changes_and_the_history_escape_the_submitted_values`、`test_user_written_strings_are_escaped` |
| C14 | Task 3 m-5 | 履歴の本文をエスケープしない（`_history.blade.php`） | 赤（Failures 1） `test_user_written_strings_are_escaped` | 検出済み（同上） | 同上 | 赤（Failures 2） `test_the_changes_and_the_history_escape_the_submitted_values`、`test_user_written_strings_are_escaped` |
| P04 | Task 4 m-1 | 代理の取り下げで管理者かを見ない（`RequestPermissions.php`） | 赤（Failures 2） `test_the_detail_offers_the_operations_that_are_possible_now`、`test_a_view_all_user_sees_no_actions` | 検出済み（Task 5 の画面のテスト 2 本。点検は Task 4 の 4 ファイルだけで測った）。`Workflow` の守りを直に確かめる行を足した | `WorkflowAdminTest::test_withdrawal_by_an_admin_refusals` に管理者でない人（部門長） | 赤（Failures 3） `test_the_detail_offers_the_operations_that_are_possible_now`、`test_a_view_all_user_sees_no_actions`、`test_withdrawal_by_an_admin_refusals` |
| V02 | Task 4 m-3 | 取り消された判断の人の見られる範囲を部門長の判断だけにする（`RequestVisibility.php`） | 緑 | 等価でない: 担当を外れた審査担当者・前の社長が、取り消された自分の判断の申請を開けない（段階3 の通知の先で 404） | `WorkflowAdminTest::test_every_judge_whose_judgement_was_undone_can_still_see_the_request`（部門長の差戻し・審査の意見・社長の可／条可／否／差戻しの 6 通り） | 赤（Failures 5） `test_every_judge_whose_judgement_was_undone_can_still_see_the_request` ×5 |
| W15 | Task 4 m-4 | 取り消しの記録の「前」の状態を戻り先で書く（`Workflow.php`） | 緑 | 等価でない: 追記のみの記録に誤った状態の前後が残る | `WorkflowAdminTest::test_undo_restores_the_state_before_the_operation` に `from_status` | 赤（Failures 8） `test_undo_restores_the_state_before_the_operation` ×8 |
| W16 | Task 4 m-4 | 代理の取り下げの記録の「後」の状態を前の状態で書く（`Workflow.php`） | 緑 | 等価でない: 同上 | `WorkflowAdminTest::test_an_admin_withdraws_for_the_applicant` に `to_status` | 赤（Failures 1） `test_an_admin_withdraws_for_the_applicant` |
| A21 | Task 5 M-2 A | 取り消しの送り先が残す小窓の名前を `reassign` にする（`AdminRequestController.php`） | 緑 | 等価でない: 断られた取り消しの小窓が開き直さない | `AdminRequestsTest::test_a_refused_undo_reopens_its_modal` | 赤（Failures 1） `test_a_refused_undo_reopens_its_modal` |
| A22 | Task 5 M-2 B | ⑩ の申請部門を今の部門にする（`AdminRequestController.php`） | 緑 | 等価でない: 差戻し中の直しかけの申請部門が ⑩ に出る（D26） | `AdminRequestsTest::test_the_list_shows_the_last_submitted_subject` に申請部門 | 赤（Failures 1） `test_the_list_shows_the_last_submitted_subject` |
| A23 | Task 5 M-2 C | ⑩ の社長の段階の「担当が申請者本人」の印を出さない（`AdminRequestController.php`） | 緑 | 等価でない: 社長が申請者本人になって止まった申請に印が付かない | `AdminRequestsTest::test_the_list_flags_a_request_whose_president_is_the_applicant` | 赤（Failures 1） `test_the_list_flags_a_request_whose_president_is_the_applicant` |
| A24 | Task 5 M-2 D | `launchedForMenu()` の読むだけの経路がいつも false（`ApprovalSetting.php`） | 緑 | 等価でない: 本番の基幹の画面（設定を覚えていない）で「決裁」「進行中の申請の管理」が出ない。テストは `launchApprovals()` が設定を覚えるので覚えた経路しか通らなかった | `AdminRequestsTest::test_a_base_page_reads_the_launch_without_the_remembered_setting` | 赤（Failures 1） `test_a_base_page_reads_the_launch_without_the_remembered_setting` |
| A25 | Task 5 M-2 F | 開き直した管理者の小窓がいつも今の版を送る（`_admin_actions.blade.php`） | 緑 | 等価でない: 古い画面から断られたあと、直して送り直すと先を越されたことに気づかずに通る | `AdminRequestsTest::test_a_reopened_admin_modal_keeps_the_version_of_the_refused_page` | 赤（Failures 1） `test_a_reopened_admin_modal_keeps_the_version_of_the_refused_page` |
| N06 | Task 7 Minor 1 | 基幹の折りたたみで 0 件でも丸を出す（`sidebar.blade.php`） | 緑 | 等価でない: 0 件の赤い丸が出る | `ApprovalMenuTest::test_no_badge_when_nothing_is_waiting` に折りたたみとドロワー | 赤（Failures 1） `test_no_badge_when_nothing_is_waiting` |
| N07 | Task 7 Minor 1 | 決裁のみのサイドバーの折りたたみで 0 件でも丸を出す（`sidebar_approval.blade.php`） | 緑 | 等価でない: 同上 | `ApprovalMenuTest::test_the_approval_only_sidebar_has_no_badge_when_nothing_is_waiting` | 赤（Failures 1） `test_the_approval_only_sidebar_has_no_badge_when_nothing_is_waiting` |
| N08 | Task 7 Minor 2 | メニューの件数で `current()` を使う＝設定の行を作る（`ApprovalMenu.php`） | 赤（Failures 1） `test_the_query_count_does_not_grow_with_the_number_of_properties` | 意図と別の機構でだけ検出（`PropertyListSortTest` が問い合わせの本数の違いで落とす）。計画 §0.10 の「読むだけ」を直に確かめるテストを足した | `ApprovalMenuTest::test_a_base_page_does_not_create_the_settings_row`（表の N05 も落とす） | 赤（Failures 2） `test_a_base_page_does_not_create_the_settings_row`、`test_the_query_count_does_not_grow_with_the_number_of_properties` |

- Task 6 の変異（M1・M2・M6 など）は Task 6 の再点検で測り済み（`136bdc7a` の写しで 1 つずつ当て、どれも `RelatedNumberSearchTest` が落とした）なので、mutate.py とこの表には入れていない

### 足したテスト（持ち主の Task ごとのコミット）

どのテストも、先に写し（`p2b-t08-tests`）で「変異を当てると赤・元に戻すと緑」を確かめてから WT に入れた（道具は `work/rf/t08/t08-redgreen.py`。変異は mutate.py と同じ文字列で当てた。出力は `work/rf/t08/rg-*.txt`）。WT ではコミットの前ごとに、そのファイルと全件を流した。実装（`app/`・`resources/`・`routes/`）は変えていない。

| コミット | 持ち主 | ファイル | 足したもの | 全件（コミットの前） |
|---|---|---|---|---|
| `523b1280` | Task 1 | `tests/Unit/Approval/FormInputTest.php` | データ「末尾の改行」「末尾の空白」（F04） | OK (2868 tests, 20094 assertions) |
| `3b902c8c` | Task 2 | `tests/Unit/Approval/RequestSnapshotTest.php` | `test_a_single_kind_of_change_is_a_change`（S07〜S10）・指紋の「添付を差し替える」（S11） | OK (2869 tests, 20099 assertions) |
| `55bec696` | Task 3 | `tests/Feature/Approval/Phase2/RequestChangesTest.php`・`RequestFormTest.php` | 3 回の提出（C06・C07）・消えた行（C09）・履歴のリンク先（C10）・エスケープ（C11〜C14）・`RequestFormTest:524` の空振りを直す（C08） | OK (2872 tests, 20129 assertions) |
| `eee3f744` | Task 4 | `tests/Feature/Approval/Phase2/WorkflowAdminTest.php` | 管理者でない人の代理の取り下げ（P04）・審査と社長の判断を取り消されたあとも見られる（V02・6 通り）・記録の状態の前後（W15・W16） | OK (2878 tests, 20159 assertions) |
| `44327521` | Task 5 | `tests/Feature/Approval/Phase2/AdminRequestsTest.php` | 断られた取り消しの小窓（A21）・⑩ の申請部門（A22）・社長の段階の印（A23）・読むだけの経路（A24）・開き直した小窓の版（A25） | OK (2882 tests, 20187 assertions) |
| `fc67eff8` | Task 7 | `tests/Feature/Approval/Phase2/ApprovalMenuTest.php` | 0 件の折りたたみとドロワー（N06）・決裁のみ利用者の 0 件（N07）・設定の行を作らない（N05・N08） | OK (2884 tests, 20203 assertions) |

### 本番と同じ MySQL での確かめ（関連する決裁No の候補）

関連する決裁No の候補（`RelatedNumberController`）は、この基幹で初めて JSON の中身（`approval_revisions.snapshot->subject`）を LIKE で探す。テストは SQLite でしか流していなかったので、使い捨ての MySQL で 1 回流した。

- 立て方: brew の mysql@8.4（**8.4.11**）を scratchpad の別の datadir（`t08-mysql/data`）・**ポート 34418**・`--socket=m.sock`（相対。scratchpad の絶対パスはソケットの長さの上限を超える）で立て、`t08`（utf8mb4・utf8mb4_unicode_ci）を作った。常駐の MySQL（datadir `/opt/homebrew/var/mysql`・3306）には触っていない
- 流し方: phpunit.xml の `<env>` は `force` が無いので、プロセスの環境変数が勝つ（PHPUnit の `PhpHandler::handleEnvVariables()`）。WT で `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=34418 DB_DATABASE=t08 DB_USERNAME=root DB_PASSWORD= DB_SOCKET= … ./vendor/bin/phpunit tests/Feature/Approval/Phase2/RelatedNumberSearchTest.php`（WT の phpunit.xml は変えていない）
- 結果: **`OK (13 tests, 104 assertions)`**（SQLite と同じ本数）
- MySQL で流れたことの証拠: `RefreshDatabase` の migration で `t08` に 46 表（`migrations` 37 行・`approval_revisions.snapshot` は `json` 型・`approval_requests.subject` は `utf8mb4_unicode_ci`）。general log に `` json_unquote(json_extract(`approval_revisions`.`snapshot`, '$."subject"')) like '%提出した%' ``（9 回）・`'%直しかけ%'`（12 回）・`'%二回目%'`・`'%他部門だけの秘密%'` など 15 通りの式が実際に流れていた
- 計画 §0.14 の 4（受け入れた隙間）も実機で確かめた: JSON から取り出した文字列の照合順序は `utf8mb4_bin`（`'abc'` は `LIKE '%ABC%'` に当たらない。ふだんの件名の列は当たる）
- 止めた: `kill -TERM`（プロセスの datadir が t08-mysql であることを確かめてから）→ 使い捨ての mysqld は 0 件・ポート 34418 は空き・常駐の mysqld（pid 1062）だけが残っていることを確かめた

## Task 9 の実測記録（2026-09-29）

- 形: 136bdc7a の写し（scratchpad）＋使い捨ての SQLite＋`php artisan serve`＋Playwright（Chromium）。WT には書いていない。試しのパスワードは値を出さずに渡した
- Step 3 の 15 行: すべて期待どおり（1440px と 375px）。14 は見られる画面のべ 345 回で `main` のはみ出し 0・見られる画面のコンソールのエラーと警告 0。15 は付け替え・取り消し・代理の取り下げとも、返事を遅らせた 3 回押しで POST 1 回
- brief の読み替え: #3 の 3 つのボタンは同時には出ない（部門長確認中では取り消しが出ない作り）・#4 の名前は「社長が可」「押し間違いの取り消し（社長が可）」（§0.4 どおり）
- Step 5: view:cache のあと 287 件すべて php -l OK
- 点検の指摘の確かめ: m-6・m-7・M-1（500）・M-4・M-5・M-8・Task 7 Minor 5 を画面で再現。⑩ のページ送りは 375px で 22 ページ（421 件）から はみ出し、31 ページで左端が切れる（B1）
- 見つけた不具合 B2〜B7（Task 9 の報告 §5。B1 は上の行）: 点検の既知の指摘（Task 3 m-6・Task 5 M-1／M-4／M-5／M-8・Task 7 Minor 5）を画面で確かめたもの。このあと本番の前の最後の手直しで、B2・B7 を直し、B3・B4・B5 と B1 は利用者に確かめて直した（B3＝C3・B4＝C4・B5＝C2・B1＝C5。下の利用者の決定）。B6 は後回し（BACKLOG の 2b の節）
  - B2 軽微（細工した人に 1 回だけ）・詳細の付け替えの小窓: 付け替え先を選び理由を書く → 開発者の道具で `<select id="reassign-to">` の `name` を `assignee_user_id[]` に書き換える → 「付け替える」。期待＝ほかの入力の誤りと同じく、詳細へ戻って小窓が開き直り断りの文が出る／実際＝詳細が 500。開き直すと 200。写真 08
  - B3 軽微（文が当たらない）・詳細の取り消しの小窓（条件の確認を取り消すとき）: 条件の確認のあと、管理者が「直前の操作を取り消す」を開く。期待＝条件確認待ちに戻ることを言う／実際＝「次に社長が判断したときにそのまま使います」と出る（社長はもう判断しない）。写真 44
  - B4 軽微・付け替えの小窓の一覧: いまの担当（部門 長）を選んで送る。期待＝一覧に出ない（計画 §0.6「今の担当でない」）／実際＝一覧に出て、送ると「いまの担当と同じ人です。」で断られる（理由は残る）。写真 06
  - B5 軽微（見た目・パソコンだけ）・前回からの変更点の外した添付: マウスを乗せる。期待＝取り消し線のまま／実際＝取り消し線が消えて下線になる。写真 18
  - B6 軽微（アクセシビリティ・既存の型）・3 つの小窓（2a の判断・取り下げの小窓も同じ）: キーボードで開く。期待＝フォーカスが小窓へ移る・Esc で閉じる・`role="dialog"`／実際＝どれも無い
  - B7 軽微（アクセシビリティ）・決裁のみのサイドバーの折りたたみ: 読み上げで「決」のリンクを聞く。期待＝件数も読む（基幹の折りたたみと同じ）／実際＝「決裁のホーム」だけ。写真 22b
- 利用者に確かめること: C1〜C6（見出し・外した添付・条件の確認の取り消しの文・付け替え先の一覧・ページ番号・折りたたみの絵）
- 利用者の決定（2026-09-29・写真のページで）: C1〜C6 はすべておすすめ（A）を選び、本番の前の最後の手直しで直した。C5 は ⑩ の「決裁済み・否決」だけ（ページ番号を全部並べるほかの一覧は BACKLOG の 2b の節に後回し）
- 写真 68 枚: `~/.claude/plans/approval-phase2b-tasks/work/t09/shots/`
