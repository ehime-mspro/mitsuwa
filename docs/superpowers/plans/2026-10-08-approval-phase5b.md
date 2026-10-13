# 決裁申請 段階5（5b: 過去の紙の決裁の取り込み）実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 段階5 の後半（5b）— 紙で回していたころの決裁台帳（年度ごとの Excel）を ⑪ で取り込み（アップロード → 確認 → 確定。読めない行は飛ばし、すでにある決裁No は変えず、金額の控えを受付簿の行に結び付ける）、決裁の管理者が 1 件ずつの登録・直し・削除と取り込み 1 回分の取り消しをでき、取り込んだ紙の決裁を、見られる人に決裁台帳・Excel・紙の決裁の詳細・関連する決裁No で見せる。今年度の紙の番号を足すと、その部門の次の番号が進む（戻さない）。5a から持ち越した「控えの契約予定日の形が崩れていると台帳と Excel が 500」を最初に直す。使い始める前のまま本番へ出す。

**Architecture:** 紙の決裁は申請とは別の専用の表 `approval_paper_records`（D10）に持ち、取り込み 1 回分を `approval_paper_imports`（取り消しの単位。D9）に残す。台帳の読み取りは 3 段に分ける — `PaperLedgerFile`（Excel を読むだけ・DB に触らない）→ `PaperImportAnalysis`（部門・日付〈`PaperDate`〉・番号・すでにある記録・金額の控えの結び付け・次の番号。**確認の画面と確定が同じものを作る**）→ `PaperImporter`（1 つのトランザクション。連番の行のロック → システムの申請の番号の確かめ → 保存 → 設定の記録）。「次の番号を進めて、ロックのあとでシステムの番号を**ロック付きの読み**で確かめる」は `ApprovalNumber::reservePaperNumbers()` の 1 か所（取り込みと 1 件ずつが使う。次の番号は今年度だけ・戻さない）。見られる範囲は `RequestVisibility::constrainPaper()`（入口は `ApprovalPaperRecord::scopeVisibleTo()`）の 1 か所で、台帳・Excel・詳細・候補・リンクがすべてそれを使う。台帳は申請と紙を 1 つの並びにする（`Ledger::sortedKeys()` の鍵は申請 `r12`・紙 `p3`）。紙が当たる条件は `LedgerFilter::includesPapers()` の 1 か所、紙の行は `LedgerRow::forPaper()`。

**Tech Stack:** Laravel 12 / PHP 8.3（本番 8.3.32・手元のテストは 8.3.35 を名指し）/ MySQL 8（本番 8.0.40）・SQLite（テスト）/ Blade + Alpine.js 3 + Tailwind v4 / PHPUnit 11 / PhpSpreadsheet（既にある。`.xls` も `.xlsx` も読む。**部品の追加・更新は無い**）/ node（取り込みの画面の年度を選ぶ JS と二度押し止めを、描いた画面から取り出して動かすテスト。無ければ飛ばす）

**Spec:** 設計書 @docs/superpowers/specs/2026-10-07-approval-phase5-design.md（この計画は **5b** の §5.9〜§5.15・§6・§7・D6〜D11・D20〜D24。§10 の「計画で決めること」の 2・5〜8・10 と、9 のうち 5b の分を §0 で決めた。5a は本番に出してある）。要件定義書 @docs/決裁申請_要件定義書_v1.md（v1.14 の 11 章・7 章・10 章・6.4）。過去台帳の調査 @docs/決裁申請_過去台帳の調査.md。5a の計画 @docs/superpowers/plans/2026-10-07-approval-phase5a.md（本番反映の作法はそのまま効く）。

## Global Constraints

- ⑪（ルート名 `approvals.admin.papers.*`）は、使い始める前（`approval_settings.launched_at` が空）から決裁の管理者だけが使える（D11。門番は `approval.admin` だけ）。紙の決裁の詳細（`approvals.papers.show`）・台帳・Excel は今と同じく `approval.launched` の中
- **本物の台帳はリポジトリに入れない**（実在の人の名字と案件が入っている。D24）。テストは `Tests\Concerns\BuildsPaperLedgers` で同じ形の小さな `.xls` を作る。本物は手元で読むだけ（ファイルの名前もコミットに書かない）
- 取り込みは**足すだけ**（D6）: すでにある決裁No は中身を変えない（Excel と違えば注意を出す）・金額がすべて空の記録にだけ金額の控えから補う。読めない行は**その行だけ**取り込まない（D20）。確定ではファイルを**読み直して確かめ直す**（画面が送った hidden を信用しない）・1 回限りの鍵（`import_token`）と二度押し止めの部品（`_partials._submit_once`）
- 次の番号は**進めるだけで戻さない**（D7・D9）: 今年度の紙の番号を足す・直すと、その部門の次の番号を「紙の番号＋1」まで進める。過去の年度の紙の番号は採番に関わらない。⑧ の開始番号は今年度の紙の番号以下を断る。紙の番号はシステムの申請の番号と重ねない。**連番の行をロックしたあとの確かめは、ロック付きの読み**（`sharedLock()`。MySQL の REPEATABLE READ では普通の読みが古い中身を読み続ける）
- 決裁日は**今日まで**（取り込みはエラーの行・1 件ずつは断る）。1 件ずつの登録は、選んだ年度の期間（部門の会社の期）の外の決裁日も断る
- 台帳のファイルは**読む前に大きさを見る**（全シートで 6,000 行まで・A〜J 列だけを読む。本番の `memory_limit` は 128M）
- 見られる範囲（§5.12）: その部門に所属する人・その部門の今の部門長・全件閲覧者・決裁の管理者。審査担当者や社長だけでは見られない。見られない紙の決裁の詳細は 404
- 決裁日は日付（時刻なし）。表示は `->format('Y/m/d')`（`JapanTime::format` で時差を足さない）。**期間の終わりは「次の日より前」で比べる**（SQLite は日付の列に `2026-07-10 00:00:00` の文字で入る）
- 取り込みのコントローラ（`*ImportController.php`）は `back()` を使わず、入力の検査の断りも固定の画面へ返す（確認の画面は POST の応答。Bug #64。走査テストが見る）。行のエラーの view のキーは `rowErrors`（`$errors` を上書きしない。Bug #53）
- 文字はすべてエスケープする（`{{ }}`）。台帳の文字（件名・申請者・シートの名前）は利用者が書いたものとして扱う
- テストは worktree で `APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit`（**この Mac の `php` は 8.5**。8.3 を名指しする）。main repo では作業もテストもしない。worktree に `.env` を作らない（`.env*` は読まない・grep しない）
- コミットは Conventional Commits・日本語の件名（72 文字以内・句点なし）・1 コミット 1 関心事・本文の最後に空行を 1 つあけて `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`。`--no-verify`・`--amend`・`git reset` でのやり直し・`git stash` は使わない。push は利用者が指示したときだけ

## Review Focus

設計書が触れていないが、使う人がいちばん出会いそうな場面。計画を書く段階で、別の担当（opus）の点検がこのうち 1〜3 を見つけ、試作を直してテストを足し、コードを 1 か所壊すとそのテストが落ちることを確かめた（変異。Task 9 の表の ID）。

1. **今年度の台帳を取り込むのと同じ瞬間に、社長がその部門の申請を判断する**（開始番号を設定し忘れていて、紙の台帳にも同じ番号がある）→ 断って何も書かない（ロックの前に普通に読んだ時点の中身を読み続ける MySQL の REPEATABLE READ で、確かめ直しが社長の付けた番号を見られず、紙と申請が同じ決裁No を持っていた）（Task 5 の `test_a_system_number_taken_during_the_confirmation_is_refused`・計画の段に使い捨ての MySQL の 2 本の接続で確かめた〈`~/.claude/plans/approval-phase3-tasks/5b/race/`〉。変異 I01・K01〈SQLite では等価〉）
2. **金額の控えに、日付・申請者・件名だけを先に書いた行がある**（今も使っている今年度の台帳）→ その行は結び付けず、あとで金額を書いて取り込み直すと補える（粗利益の式が空のセルを 0 として計算し、「粗利益 0円」で記録が埋まり、本当の金額を二度と補えなかった）（Task 4 の `test_amount_rows_without_amounts_are_not_linked`・Task 5 の `test_an_amount_row_left_unlinked_can_be_linked_on_a_later_upload`。変異 F06・A08・V04）
3. **台帳と間違えて、ほかの大きな Excel を選ぶ** → 読む前に断る（1.75MB の .xlsx で本番の 128M を使い切り、捕まえられない白い 500 になっていた）（Task 4 の `test_a_huge_sheet_is_refused_before_it_is_read`。変異 F07）
4. **確認の画面を開いたまま、ほかの管理者が同じ台帳を取り込む・二度押しする・確認のあとで手で金額を入れる** → 足すだけで二重にならない・二度押しは 1 回だけ効く・手で入れた金額を上書きしない（Task 5 の `test_the_confirmation_reads_the_file_again`・`test_nothing_left_to_import_is_refused`・`test_a_second_submit_is_refused_without_writing`・`test_amounts_typed_during_the_confirmation_are_not_overwritten`。変異 C01・I02）
5. **1〜4 月の日付を年度の和暦と暦の和暦で取り違える・今日より先の日付・まだ始まっていない会社の年度で 1 件ずつ登録する** → 取り込まない・登録できない（次の番号を進められずに、あとでシステムの採番と重なるのを防ぐ）（Task 4 の `test_a_date_after_today_is_an_error`・Task 6 の `test_a_decided_date_outside_the_year_or_after_today_is_refused`。変異 D02・A09・R06・R07）

---

## 0. 計画を書く段階で決めたこと（設計書 §10 の宿題のうち 5b の分）

### 0.1 作り方（試作を先に作って確かめた）

- この計画のコードは、scratchpad の試作（WT `approval-phase3` = `2d0d54f9`〈13.x と同じ。5a の本番反映のあと、manage の会話の「長い値の折り返し・絞り込みの行」の反映まで〉の写し）で Task ごとにコミットし、**各段で全件のテストが緑**であることと、**テストだけを先に入れると何が落ちるか**を測ってから書き写したもの（2b〜5a と同じ作り方）。各 Step の Expected の本数と落ちるテストの名前は実測（2026-10-13）
- 試作の 8 コミットを別の担当（opus）が点検し（Critical 0・Important 4・Minor 10）、指摘を各 Task のコミットへ畳み込んで測り直した（§ 計画を書く段階の実測）
- 同じ中身の差分のファイルを `~/.claude/plans/approval-phase3-tasks/5b/patches/`（`0001`〜`0008`。この計画のコミット 1 つに 1 ファイル）に置いた。担当は計画のコードを打ち直さず、この差分を当ててよい（**テストを先に** `git apply --include='tests/*'`、実装を**あとで** `git apply --exclude='tests/*'`）。当てたら `git diff --stat` が各 Task の「差分の大きさ」と同じことを確かめる。**差分のファイルと計画のコードが食い違ったら、計画を正として止まり、報告する**
- 各 Task のコードの塊のうち、新しいファイルは全文、既存のファイルは差分（`diff` の形）で示す
- **使い捨ての MySQL 8.4.11 で確かめた**（2026-10-13・ポート 34419・確かめたあと止めた。常駐の 3306 はそのまま）: migration だけで作った 2 つの表と、5b の migration を戻して（`down()`）から本番と同じ SQL ファイルで作った表の `SHOW CREATE TABLE` が**外部キーの名前まで一致**・決裁のテスト（`tests/Feature/Approval`・`tests/Unit/Approval`）1,242 本は、5b の前からある SQLite 前提の 4 本だけが赤（5b の前の土台でも同じ 4 本）で、5b のテストはすべて緑（はじめ 3 本が MySQL の JSON のキーの並びで赤 → `assertSameIgnoringKeyOrder()` に直した）・**取り込みの確定と社長の判断を 2 本の処理で同時に走らせ**、直す前は紙と申請が同じ R8-J-001 を持ち、直したあとは断って何も書かないことを確かめた（`~/.claude/plans/approval-phase3-tasks/5b/race/`）
- **本物の 08 年度の台帳で確かめた**（手元・リポジトリに入れない。2026-10-08）: 読むのは 0.31 秒・メモリ +4MB（本番の 128M で足りる）。試作の部品で **204 件を足す・エラー 0・注意 0**・金額の控え 20 行すべてが自動で結び付く（住宅（少額）11・住宅 4・ミツワ不動産 5。粗利益＝契約金額−工事原価）・0.28 秒
- 進め方は 5a と同じ（担当の決まりは `~/.claude/plans/approval-phase3-tasks/5b/rules.md`）: 実装の担当は 1 度に 1 人（Task ごと）・コードの点検は別の担当が WT の HEAD の写しで行う・変異の確かめは写しで・Task 12（本番反映）は担当に任せず親の会話が行う

### 0.2 表と本番の SQL（§5.3・D9・D10）

- `approval_paper_imports`（取り込み 1 回分）: `file_name` VARCHAR(255)・`fiscal_year` SMALLINT UNSIGNED・`added_count`・`filled_count` INT UNSIGNED・`imported_by`（users・NOT NULL・RESTRICT）・`undone_at` TIMESTAMP NULL・`undone_by`（users・NULL・RESTRICT）・`undone_count` INT UNSIGNED NULL・timestamps。**取り消しても行は消さない**（いつ・誰が・何件を取り込み、取り消したかを残す）
- `approval_paper_records`（紙の決裁）: `department_id`（RESTRICT）・`fiscal_year` SMALLINT UNSIGNED・`seq` INT UNSIGNED・`number` VARCHAR(20) **UNIQUE**・`decided_on` DATE・`applicant_name` VARCHAR(50) NULL・`subject` VARCHAR(100)・`amount`・`cost`・`profit` BIGINT（符号あり・NULL 可）・`note` TEXT NULL・`import_id`（取り込み・NULL 可・RESTRICT）・`created_by`（NOT NULL）・`updated_by`（NULL。**手で直した人**。取り込みで金額を補っても変えない）・timestamps。**UNIQUE(`department_id`, `fiscal_year`, `seq`)**・`decided_on` の索引
- 利用者の列は NOT NULL・RESTRICT（利用者は SoftDeletes で消えない。誰が取り込んだかを空にしない）
- 本番の SQL は `database/sql/2026-10-08-approval-phase5b.sql`（`CREATE TABLE` 2 文）、テストの鏡は `database/migrations/2026_10_08_000001_create_approval_paper_tables.php`（索引と外部キーの名前を SQL と同じに書いた）。`Phase5bTablesTest` が両方を読んで同じ表・同じ列・同じ索引と外部キーであることを見る
- モデル: `ApprovalPaperRecord`（`department()`・`import()`・`creator()`・`editor()`〈利用者は `withTrashed()`〉・`scopeVisibleTo()`・`rate()`〈金額と粗利益から。申請の明細表と同じ整数の計算〉）・`ApprovalPaperImport`（`records()`・`importer()`・`undoer()`）
- 部門の歯止め（§5.11）: 紙の決裁のある部門は、会社とアルファベットを変えられない・消せない。紙の決裁のある会社は、期の始まりの月を変えられない（紙の決裁No の和暦が変わる）。断りの文は申請の番号のときと分ける（申請の番号の文は段階2 のテストが固定している）

### 0.3 台帳の読み取り（§5.9・§10 の 7）

- シートの名前の**先頭の英字 1〜3 文字**（全角は半角・大文字に。4 文字以上続けば部門の記号ではない）が部門のアルファベット。名前に「金額」を含むシートは金額の控え。どちらでもないシートは読まない（確認の画面に「読まない」と並べる）
- 受付簿は 1 枚の中が左右 2 段（左 B〜E・右 G〜J。ＮＯ・日付・申請者・件名）。日付・申請者・件名の**どれか**が入っている段だけを拾う（ＮＯ だけの段は使っていない行）。題名・見出しの行は ＮＯ も日付も数でない＝拾わない。拾った段に日付が無い・件名が無いなどは**エラーにして取り込まない**（取りこぼしを黙らせない。要件 11.1 の「日付の有無で判定」より 1 歩強い）
- 日付は `n.M.D`（全角・区切りの `/` `-` のゆれ・Excel の日付の数のセルも）。**年は月で決める**（期の始まりの月以上ならその年度の年・未満なら次の年）。**先頭の n は「年度の和暦の年」か「その日の和暦の年」のどちらかと合うこと**（1〜4 月を「9.01.15」とも「8.01.15」とも書ける台帳を読み、前の年度の書き残し「7.04.30」を黙って入れない）。数の日付のセルは年度の期間の中のときだけ読む
- **今日より先の日付はエラー**（年度の和暦と暦の和暦の取り違え・まだ始まっていない会社の年度）
- 決裁No は `ApprovalNumber::paperNumber()`（年度の和暦は部門の会社の期で決める。R8-J-021・999 の次は R8-J-1000）
- ＮＯ は 1〜99,999（関連する決裁No の形が 5 桁まで）・件名は 100 文字まで（申請と同じ）・申請者は 50 文字まで・金額は 12 桁まで（マイナスも）・1 回に拾う行は 3,000 まで（08 年度は 224）
- **読む前に大きさを見る**: `listWorksheetInfo()`（セルを作らずに行の数を返す）で全シートの行の数を足し、6,000 行を超えたら読まずに断る。読むのは A〜J 列だけ（`IReadFilter`）。点検の実測で、10 列 15,000 行の .xlsx は 116MB、30,000 行（1.75MB）は 128M を使い切った（読み込んでから数えると、捕まえられない 500 になる）
- 金額の控え: B=ＮＯ・C=日付・D=申請者・E=件名・F=契約金額・G=工事原価・H=粗利益（**式 `=+F5-G5` は計算した値を読む**。ただし**契約金額と工事原価がどちらも書いてあるときだけ**＝空のセルは 0 として計算されるので、金額を書く前の行が「粗利益 0」・工事原価が空の行が「粗利率 100%」になる）。日付・申請者・件名がそろう行だけを拾う（計算の跡だけの行を読まない）

### 0.4 金額の控えの結び付け（§5.9・要件 11.3）

- 控えの行を、**正しく読めた受付簿の行（すべてのシート）**に「日付の数の並び・空白と全角半角を無視した件名」で探す。**08 年度の「土地・建物契約・金額」の 9 件は、住宅 4 件とミツワ不動産 5 件に当たる**（要件 11.3 の「9 件中 4 件」は住宅だけと比べた数。Task 11 で直す）
- 当たる行がちょうど 1 つで、ほかの控えがその行に当たっていなければ自動で結び付ける。0 か 2 つ以上・2 つの控えが同じ行に当たるときは、確認の画面で選んでもらう（`links[シートの番号:行]` = 決裁No か `none`。先頭は「結び付けない」＝選ばなければ結び付けない）。選んだものは確定のフォームで送り、確定でも同じ形で確かめ直す
- 選び方の誤り（2 つの控えを同じ記録に・取り込めない先）は、確認の画面を新しい鍵でもう一度出して選び直してもらう（アップロードし直させない）
- **契約金額・工事原価・粗利益がどれも空の控えの行は結び付けない**（当たる行を探さない。確認の画面に「金額がありません（結び付けません）」。結び付けると「金額を補った」と数え、あとで本当の金額を補えなくなる）
- 結び付けの先がすでにある記録なら、金額がすべて空のときだけ補う（D6）。確定の瞬間にも空かを確かめる（手で入れた金額を上書きしない）。結び付けなかった控えは、同じ台帳を取り込み直したときに選んで結び付けられる（足す記録が 0 件でも、選べる行があれば確定のフォームを描く）

### 0.5 確認の画面から確定へ（§10 の 6）

- 社員の CSV と同じく、ファイルの中身を hidden の base64（`ledger_data`）で持ち回る（10MB まで）。確定で `PaperImportAnalysis` を同じ入力でもう一度作る
- 確定の順: 入力の検査 → **1 回限りの鍵を受け取る**（ファイルを読み直す前。二度押しの 2 回目に「取り込む記録がありません」と違う理由を出さない＝テナントの取込と同じ）→ 読み直す → 結び付けの誤りなら確認の画面をもう一度 → 足す・補う記録が無ければ断る → `PaperImporter::apply()`
- 確定のフォームは「足す・補う記録がある」か「選べる金額の控えの行がある」ときだけ描く。確認の画面に `confirm()` は付けない（二度押し止めの部品と並べると、取り消したあとボタンが押せなくなる）
- 年度の選択肢は、今の年度（会社の中でいちばん新しいもの）から平成元年度（1989）まで。ファイルの名前の「08年度」（全角の数字も）から JS で先に選ぶ（n 年度は令和〈2018＋n〉が選択肢にあればそれ、無ければ平成〈1988＋n〉）。違う年度を選んでも、日付の年が合わない行はエラーになる
- 確定のあとは紙の決裁の一覧（その年度で絞った）へ。「N 件を取り込みました（金額を補った記録 M 件）。次の番号: 住宅事業部 R8-J-035。」（次の番号は、確定で**実際に進めた値**。`PaperImporter::apply()` は `['import' => ApprovalPaperImport, 'next' => list<string>]` を返す）
- 年度の選択肢は `ApprovalPaperRecord::yearChoices()`（取り込み・1 件ずつ・一覧の絞り込みが使う）

### 0.6 次の番号（§5.11・§10 の 8）

- `ApprovalNumber::advancePast(department, fiscalYear, seq): ?int` — 今年度のときだけ、連番の行をロックして、次の番号が seq 以下なら seq＋1 にする（戻さない）
- `ApprovalNumber::reservePaperNumbers(array $maxSeqs, int $fiscalYear, array $numbers): array{next, taken}` — 部門の id の順に advancePast を呼び、**ロックのあとで**システムの申請がすでに使っている番号を**ロック付きの読み**（`sharedLock()`）で返す。取り込みの確定・1 件の登録・番号の直しが、保存と同じトランザクションの中で**保存の前に**呼ぶ（保存が断られたら番号も戻る）。⚠ 普通の読みにすると、MySQL の REPEATABLE READ では、ロックを待つあいだに社長の判断が付けた番号が見えない（点検 I-1。2 本の接続で再現・直したあとも確かめた）。紙どうしの重なりは一意の索引で止まる
- `ApprovalNumber::setNext()` は、システムで使った番号に加えて、今年度の紙のいちばん大きい番号以下も断る（「今年度は紙の決裁で R8-J-034 まで使っています。35 以上を入れてください。」。紙の番号もロック付きの読み）。部門の管理の編集の小窓の案内も、紙の番号のほうが大きければそちらを出す

### 0.7 1 件ずつの登録・直し・削除と取り消し（§5.10・D9）

- 登録・直しは別のページ（`approvals/admin/papers/form.blade.php`）。欄は年度・部門・ＮＯ・決裁日・申請者・件名・金額（契約金額）・工事原価・粗利益・備考（2,000 文字まで）。金額は全角・カンマ・円を落としてから整数で検査（`FormInput::digits()`。マイナス可・12 桁まで）
- 番号の重なり（システムの申請・ほかの紙の決裁・確かめたあとの一意の索引）は入れた中身を残してフォームへ戻す（`ValidationException`・ＮＯ の欄）。決裁日は**今日まで**で、**選んだ年度の期間（部門の会社の期）の中**だけ（外なら「決裁日 2026/04/30 は R8 年度の期間（2026/05/01〜2027/04/30）の外です。年度か決裁日を直してください。」。まだ始まっていない会社の年度の番号を入れると、次の番号を進められずに、あとでシステムの採番と重なる）
- 保存のフォームは二度押し止め（`approvalSubmitOnce`。⑨ と同じ。2 回押すと、1 回目で登録できたのに 2 回目が「すでにあります」で戻る）
- 設定の記録: `paper.created`・`paper.updated`（変わった項目だけ。**決裁日は保存する形の文字にそろえて比べる**）・`paper.deleted`・`paper.imported`・`paper.import_undone`
- 取り消しは、その回に足した記録を、そのあと手で直したものも含めて消す。金額を補っただけの記録は消さない（確かめの文に「金額を補った N 件は、記録も金額も残ります。」）。一覧のボタンに今の件数と手で直した件数を出し、送った件数（`count_shown`・`edited_shown`）と今が違えば断る・取り消した回はもう一度取り消せない。次の番号は戻さない（部門の管理の開始番号で戻す）
- 取り込みの記録はすべて出す（古い回も取り消せるように。年度ごとのファイルなので多くならない）

### 0.8 見られる範囲・詳細・関連する決裁No（§5.12・§5.15）

- 規則は `RequestVisibility::constrainPaper()` の 1 か所（入口は `ApprovalPaperRecord::scopeVisibleTo()`。ローカルスコープなので呼ぶ側の OR が漏れない）。1 件は `RequestVisibility::canViewPaper()`
- 紙の決裁の詳細（`PaperDetailController`）: 決裁No・決裁日・判断（不明）・部門・申請者・金額・工事原価・粗利益・粗利率・備考と「紙の決裁です。原本は紙で保管しています。」。決裁の管理者には「直す」。判断の「不明」の文字は `ApprovalPaperRecord::DECISION_LABEL` の 1 か所（詳細・台帳・Excel・絞り込み）
- 申請の詳細の関連する決裁No: 見られる申請と紙の決裁はリンク（番号 → URL）。候補（`RelatedNumberController`）は申請と紙を合わせて決裁日の新しい順に 10 件まで。紙の候補だけに `paper: true`（申請の候補の JSON は変えない）、画面は「紙」と添える

### 0.9 台帳と Excel（§5.13・§5.14・§10 の 5・9）

- 並べた鍵は申請 `r`＋id・紙 `p`＋id（`Ledger::sortedKeys()`）。紙の鍵: 日＝決裁日・番号あり・部門のアルファベット・ＮＯ。同じ日の申請と紙は決裁No の順に混ぜる。行は `Ledger::rows($viewer, $keys)` が申請と紙を 1 回ずつ読む（N+1 にしない）
- 絞り込み（`Ledger::paperQuery()`）: 年度＝紙の年度・部門・決裁日の期間・申請者（`NameSearch::whereNameHasAll($q, $terms, NameSearch::PAPER_APPLICANT)`。名前の列は決まったものだけ受け取る）・キーワード＝件名と備考。**紙が当たる条件は `LedgerFilter::includesPapers()` の 1 か所**: 区分が「システム」・申請の種類を選んだ・判断に可・条可・否を選んだ・状態が「進行中」「取り下げ」なら当たらない。判断「不明」（`?decision=unknown`）と区分「紙」は紙だけ
- `LedgerFilter` に `kind`（`all`・`system`・`paper`。既定 `all`）と `decisionUnknown`。URL・出力の記録（`toLog()` に `kind`。判断は `unknown`）・読み取れない値は外して知らせる（「区分」）
- 紙の行（`LedgerRow::forPaper()`）: 判断「不明」・状態は空・区分「紙」・申請者は台帳の文字・粗利率は `rate()`・行の先は紙の決裁の詳細（`url()`）・画面の状態の列は灰色の「紙」（`badgeLabel()`）
- Excel は「区分」の列を状態の前に（20 列・A1:T）。件数の上限（1,000 件）は紙とシステムを合わせて数える。**紙の行 1,000 件で +36MB・0.75 秒**（2026-10-13 の実測）＝上限は変えない
- 年度の選択肢は、紙のいちばん古い年度まで広げる

### 0.10 5a からの持ち越し（Task 1）

- 控えの契約予定日（`Y-m-d` の文字）が形の崩れた値だと、`Carbon::createFromFormat` が例外を投げ、台帳と Excel の全体が 500 になっていた（5a の点検の持ち越し。BACKLOG）。`LedgerRow::day()` で、在る日付だけを日付にし、ほかは空にする（`2026-02-30` も空。越えた日付を繰り上げない）

### 0.11 走査テスト（全件分類）に登録するもの

3b 以降と同じく、**各 Task が足したものは、その Task の中で登録する**（途中の各段でも全件が緑）。

| 走査テスト | 登録するもの | Task |
|---|---|---|
| `tests/Feature/Approval/Phase2/RequestVisibilityTest.php` | 規則の本体を呼ぶ所の走査に `constrainPaper` | 3 |
| `tests/Feature/Approval/Phase2/LaunchGateTest.php` | `OPEN_BEFORE_LAUNCH` に `approvals.admin.papers.`（Task 5）・`MIN_GATED` を 27 に（4b の PDF・台帳と Excel の 3 本の数え直し＋紙の決裁の詳細 1。Task 7） | 5・7 |
| `tests/Feature/Approval/ApprovalSidebarTest.php` | 過去の決裁の取り込みの入口（決裁のサイドバー・基幹のサイドバー・管理者でない人には出さない） | 5 |
| `tests/Feature/Approval/Phase3/MailFailureBannerTest.php` | 管理の画面に `papers.index`・`papers.import` | 5 |
| `tests/Feature/SubmitOnceTest.php` | `MIN_TOKEN_VIEWS` を 8 に（取り込みの確認の画面） | 5 |
| `tests/Feature/Approval/ApprovalAdminGateTest.php` | 既存値のマップに `approvalPaperRecord`・`approvalPaperImport`（Task 6）・`OPEN_TO_EVERY_USER` に `approvals.papers.show`（Task 7） | 6・7 |
| `tests/Feature/ClockReadScanTest.php` | `PaperImportController` の 1 か所（取り消した瞬間。`undone_at` は TIMESTAMP） | 6 |

- 取り込みのコントローラの走査（`ImportControllerReturnPathScanTest`・`ImportControllerValidationRedirectScanTest`・`ImportControllerOneTimeKeyScanTest`）は `PaperImportController` を自動で数える（`*ImportController.php`）。登録は要らない（`back()` を使わない・検査の断りは固定の画面へ・`claimFrom()` を呼ぶ）
- `JapaneseValidationMessagesTest`: 新しい入力のキーは、どれも呼び出しの第 3 引数で和名を付けた。`lang/ja/validation.php` は変えない
- `MobileLayoutTest`・`StoredTimestampDisplayScanTest`・`LoginGuideTest`（1 ファイル 1 宣言）: 新しい画面とクラスは引っかからない（全件で確かめた）

### 0.12 既存のコードを変えるもの

| ファイル | 変えること | Task |
|---|---|---|
| `app/Support/Approval/LedgerRow.php` | 契約予定日の読み方（§0.10） | 1 |
| `app/Http/Controllers/Approval/OrganizationController.php`・`resources/views/approvals/admin/organization.blade.php` | 紙の決裁のある部門・会社の歯止め（Task 2）・次の番号の案内（Task 5） | 2・5 |
| `app/Support/Approval/ApprovalNumber.php` | `departmentHasPaperNumbers()`・`paperNumber()`（Task 2）・`advancePast()`・`lastPaperSeq()`・`setNext()` の紙の検査（Task 5） | 2・5 |
| `app/Support/Approval/ApprovalFiscalYear.php` | `eraYearOf()`（その日の和暦の年） | 4 |
| `app/Http/Controllers/Approval/RelatedNumberController.php`・`RequestController.php`（関連する決裁No のリンクを番号 → URL に）・`resources/views/approvals/requests/show.blade.php`・`form.blade.php` | §0.8 | 7 |
| `app/Support/Approval/Ledger.php`・`LedgerFilter.php`・`LedgerRow.php`・`LedgerExcel.php`・`NameSearch.php`・`app/Http/Controllers/Approval/LedgerController.php`・`resources/views/approvals/ledger/index.blade.php` | §0.9（`Ledger::sortedIds()` は `sortedKeys()` に、`rows()` は鍵を受け取る） | 8 |

### 0.13 既存のテストを変えるもの

| テスト | 変えること | Task |
|---|---|---|
| `LedgerExcelTableTest`（5a） | 契約予定日の崩れた控えのテストを 1 本足す（Task 1）・`sortedKeys`・鍵・オートフィルタ A1:T（Task 8） | 1・8 |
| `LedgerQueryTest`・`LedgerRowTest`（4b） | `sortedIds` → `sortedKeys`・`rows` に鍵（`Ledger::requestKey()`）。中身の期待は変えない | 8 |
| `LedgerExcelTest`（4b） | 見出しと値の列に「区分」（S）・状態は T・オートフィルタ A1:T・出力の記録に `kind` | 8 |
| `LedgerFilterTest`（4b） | `toLog()` に `kind` | 8 |
| §0.11 の走査テスト | 足すだけ（`MIN_GATED`・`MIN_TOKEN_VIEWS` は下限を上げる） | 3・5・6・7 |

### 0.14 設計書から変えた細部（計画を書いていて分かったこと）

| # | 設計書 | この計画 | 理由 |
|---|---|---|---|
| 1 | §5.9「注意（取り込む）: 日付がその年度の期間の外」 | 日付の先頭の年が年度と合わない行は**エラー**（取り込まない）。期間の外になる日付は作れない（年は月で決める） | 台帳の 1〜4 月は「9.01.15」とも「8.01.15」とも書ける。どちらでも読み、前の年度の書き残しを黙って入れないため |
| 2 | §5.9「部門ごとの件数」 | シートごとの件数（受付簿は読んだ段・足す・すでにある・取り込まない、金額の控えは読んだ行・結び付いた行。読まないシートも並べる） | 台帳はシート＝部門で、読まないシートがあることも見せたい |
| 3 | §5.15 の詳細の欄 | 判断「不明」も出す | 台帳の行と同じ |
| 4 | §11 の順（台帳と Excel → 詳細と関連する決裁No） | 詳細と関連する決裁No（Task 7）を台帳と Excel（Task 8）の前に | 台帳の紙の行のリンクの先（詳細）を先に作る |
| 5 | 要件 11.1「日付の有無で実際に使われた行かを判定する」 | 日付・申請者・件名のどれかがある段を拾い、日付が無ければエラー | 件名だけ書いて日付を書き忘れた行を黙って落とさない |
| 6 | 計画の段のメモ（1 件ずつ「期間の外の決裁日は保存して知らせる」） | 1 件ずつの登録で、年度の期間の外の決裁日と今日より先の決裁日は断る。取り込みでも今日より先はエラーの行 | 点検 M-2。まだ始まっていない会社の年度（5 月の DAD の R8 年度など）の番号を入れると、次の番号を進められずに、6 月からの採番と重なる |
| 7 | 要件 11.3「突き合わなかった分は確認画面で結び付ける」 | 金額がどれも空の控えの行は結び付けない（選ぶ欄も出さない） | 点検 I-2。金額を書く前の行を結び付けると「補った」と数え、あとで本当の金額を補えなくなる |

### 0.15 受け入れた隙間

| # | 隙間 | 起きたとき | 塞ぐなら |
|---|---|---|---|
| 1 | 控えと受付簿の日付を、書いた文字の数の並びで比べる（点検 M-3） | 受付簿は「8.01.15」、控えは「9.01.15」のように書き分けた台帳・片方が Excel の日付の数のとき、同じ日でも自動では結び付かず、確認の画面で選んでもらう行に回る（黙って誤らない。08 年度は 20 件すべて自動で結び付いた） | 読み取った日付で比べる |
| 2 | 確定は 10MB までのファイルを base64 にした hidden（約 13.4MB の POST）で持ち回る | 本番の `post_max_size` が小さければ、大きな台帳の確定が断られる | Task 12 の Step 2 で読み取って利用者に伝える。一時ファイルで持ち回る形に変える |
| 3 | 1 件ずつの直しに版の確かめ（`lock_version`）が無い | 2 人の決裁の管理者が同じ紙の決裁を同時に直すと、あとの保存が勝つ（設定の記録には両方残る） | 申請と同じ `lock_version` |
| 4 | 部門の管理の一覧で、部門ごとに紙の問い合わせが 2 本ずつ増えた（点検 M-7。前からある `has_numbers` と同じ形） | 部門が十数件なので気にならない | `department_id` で GROUP BY した 1 本にまとめる |
| 5 | 備考に壊れた文字（不正な UTF-8）を手で組んで送ると、本番の MySQL で 500（点検 M-10） | 画面からは起きない（決裁のほかの欄と同じ形） | 決裁の画面の入力をまとめてそろえるとき |
| 6 | ロックのあとのロック付きの読み（§0.6）は SQLite のテストでは見えない | テストは断りの経路だけを見る。MySQL の振る舞いは計画の段の 2 本の接続の確かめ（`race/`）だけ | MySQL でテストを流す仕組み |
| 7 | 取り消しても次の番号は戻らない（D9 のとおり） | 間違えた台帳で次の番号が進んだら、部門の管理で開始番号を直す | —（設計どおり） |

### 0.16 テストの土台

- 4a〜5a と同じ（`Tests\Concerns\BuildsApprovalFixtures`・`approvalWorld()`・申請部門「住宅事業部」J・審査部門「総務部」S・会社は 5 月始まり・今日は 2026-10-08〈R8 年度〉）
- 紙の決裁は `BuildsApprovalFixtures::paperRecord(department, attributes)`（既定: 2026 年度・次のＮＯ・決裁日 2026-05-13・申請者「甲野」・件名「見本邸新築工事契約」）。Task 2 で足す
- 台帳は `Tests\Concerns\BuildsPaperLedgers::paperLedger(sheets, cells, format)`（受付簿: シートの名前 => [[ＮＯ, 日付, 申請者, 件名], ...] を ＮＯ の位置の段に書く・34 行ごとの題名と見出し・使っていない行の ＮＯ。金額の控え: ['amounts' => [[ＮＯ, 日付, 申請者, 件名, 契約金額, 工事原価], ...]]〈粗利益は式〉。`$cells` で形の誤りを作る。`.xls` と `.xlsx`）。Task 4 で足す。**人の名前と件名は作りもの**（甲野・乙山・見本邸など）
- 取り込みの画面は、描いた確定のフォームを分解して送り返す（`ParsesForms`。`links[…]` は `parse_str` でブラウザと同じ形にする）。二度押しは `ChecksDoubleSubmit`（書き込みの SQL の数・戻り先の赤帯）
- 年度を選ぶ JS は、描いた画面から `function paperLedgerYear` を取り出して node で動かす（無ければ飛ばす）

---

## 1. 触るファイル

### 新規

| ファイル | 役目 | Task |
|---|---|---|
| `database/sql/2026-10-08-approval-phase5b.sql`・`database/migrations/2026_10_08_000001_create_approval_paper_tables.php` | 本番の DDL とテストの鏡 | 2 |
| `app/Models/ApprovalPaperRecord.php`・`ApprovalPaperImport.php` | 紙の決裁と取り込み 1 回分 | 2 |
| `app/Support/Approval/PaperDate.php`・`PaperLedgerFile.php`・`PaperImportAnalysis.php`・`PaperImportException.php` | 台帳の読み取りと取り込みの中身 | 4 |
| `app/Support/Approval/PaperImporter.php`・`app/Http/Controllers/Approval/PaperImportController.php`・`PaperRecordController.php` | ⑪ の取り込みと一覧（Task 5）・1 件ずつと取り消し（Task 6） | 5・6 |
| `resources/views/approvals/admin/papers/`（`index`・`import`・`_import_preview`・`_import_amounts`・`form`） | ⑪ の画面 | 5・6 |
| `app/Http/Controllers/Approval/PaperDetailController.php`・`resources/views/approvals/papers/show.blade.php` | 紙の決裁の詳細 | 7 |
| `tests/Concerns/BuildsPaperLedgers.php` | 作りものの台帳 | 4 |
| `tests/Feature/Approval/Phase5/`（`Phase5bTablesTest`・`PaperDepartmentGuardTest`・`PaperVisibilityTest`・`PaperImportAnalysisTest`・`PaperImportScreenTest`・`PaperListTest`・`PaperNumberTest`・`PaperRecordCrudTest`・`PaperDetailTest`・`PaperLedgerTest`）・`tests/Unit/Approval/PaperDateTest.php` | テスト | 2〜8 |

### 変更

§0.12・§0.13 の表のとおり。ほかに `routes/approval.php`（Task 5・6・7）・サイドバー 2 つとホーム 2 つの入口（Task 5）・`tests/Concerns/BuildsApprovalFixtures.php`（Task 2）。

## 2. 作業の順番

```
Task 0 準備 → Task 1 控えの契約予定日 → Task 2 表とモデル・部門の歯止め → Task 3 見られる範囲 → Task 4 台帳の読み取り
→ Task 5 ⑪ の取り込みの画面と次の番号 → Task 6 1 件ずつと取り消し → Task 7 詳細と関連する決裁No → Task 8 台帳と Excel
→ Task 9 全件・変異 → Task 10 ブラウザと写真と Excel → Task 11 ドキュメント → Task 12 本番反映（親の会話）
```

各 Task のコミットのあとで全件が緑（途中の段でも）。Task 9・10 は記録だけをコミットする。

---

## Task 0: 作業場所の準備

**作業場所**: worktree `/Users/masanori/site/manage/.claude/worktrees/approval-phase3`（ブランチ `approval-phase3`。3a〜5a と同じ worktree を使う＝利用者の決定・dev の vendor の置き場）。この計画のコミットの親は `2d0d54f9`（13.x と同じ。5a の本番反映と、manage の会話の「長い値の折り返し・絞り込みの行」の反映まで）。**main repo では作業もテストもしない**（main repo の vendor は `--no-dev` で phpunit が無い。dev 依存を入れると `./deploy.sh` が本番へ送る）。

- [ ] **Step 1: 並行の作業を確かめる**（ほかの会話が同じ課題を進めていないか・13.x が進んでいないか）

```bash
cd /Users/masanori/site/manage && git status --short --branch && git log --oneline -5 && git worktree list && git for-each-ref --sort=-committerdate --format='%(refname:short) %(committerdate:short) %(subject)' refs/heads | head && git log --oneline approval-phase3..13.x
```

Expected: worktree `approval-phase3` の先頭がこの計画のコミット。最後のコマンド（`13.x` にあって `approval-phase3` に無いコミット）が空。紙の決裁・取り込みの名前の付いた枝や worktree があれば、中身を読み、消さずに利用者へ報告して止まる。`13.x` が進んでいたら止まり、利用者に取り込むかを聞く（取り込むなら WT で `git merge --ff-only 13.x` か `git merge 13.x`。rebase しない）。**manage の会話が決裁の `PageNumbers` を `App\Support\PageNumbers` へ移す作業（`<x-pager>`）を進めている**。13.x に入っていたら、取り込んだあと Step 3 の差分の当たり方を確かめる（`PaperRecordController`・`LedgerController` の `use App\Support\Approval\PageNumbers;` と `approvals/_pager` の読み方が変わりうる。当たらなければ止まって報告する）

- [ ] **Step 2: vendor と道具を確かめる**（dev 依存あり・実体・PHP 8.3・node）

⚠ **symlink にしない**（autoload が symlink の先を読み、別の場所のコードでテストが流れる。Bug #50）。⚠ **この Mac の `php` は 8.5**。テストは `/opt/homebrew/opt/php@8.3/bin/php` を名指しする。

```bash
WT=/Users/masanori/site/manage/.claude/worktrees/approval-phase3; test -x "$WT/vendor/bin/phpunit" && ! test -L "$WT/vendor" && test -f "$WT/vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Reader/Xls.php" && echo "vendor OK"; /opt/homebrew/opt/php@8.3/bin/php -v | head -1; command -v node
```

Expected: `vendor OK`・`PHP 8.3.…`・node のパス（5b は部品を足さない。`composer` は打たない。node が無ければ `PaperImportScreenTest` の年度の JS と `SubmitOnceTest` の実駆動が飛ばされる＝報告する）

- [ ] **Step 3: 差分のファイルを確かめる**

```bash
ls ~/.claude/plans/approval-phase3-tasks/5b/patches/ && cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --check ~/.claude/plans/approval-phase3-tasks/5b/patches/0001-*.patch && echo "0001 を当てられる"
```

Expected: `0001-…` 〜 `0008-…` の 8 本・`0001 を当てられる`。差分のファイルが無ければ計画のコードを打ち込む（中身は同じ）。

- [ ] **Step 4: 全件テストが通る状態から始める**

**テストの流し方**（以下すべての Task で同じ。`APP_KEY` は 32 バイトの本物の鍵を渡す。worktree に `.env` を作らない）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `OK (4064 tests, 38185 assertions)`（2026-10-13 の実測。約 4 分）。赤があれば 5b の作業の前に利用者へ報告して止まる。

⚠ 以下の Task の「テストを流す」は、すべてこの形でファイルを並べたもの。`cd` はコマンドごとに書く（ターンをまたぐと cwd が main repo へ戻る）。git は `git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 …` で呼ぶ。

⚠ コミットのたびに `git status --porcelain` が空であることを確かめる。差分のファイルを当てたときは、`git diff --stat` が各 Task の「差分の大きさ」と同じことも確かめる。

⚠ **本物の台帳（年度ごとの Excel）はリポジトリに入れない・テストに使わない**（D24）。

---

## Task 1: 控えの契約予定日の形が崩れていても台帳と Excel を開ける

5a の持ち越し。申請者以外に見せる契約予定日は控えの `Y-m-d` の文字から作るが、形の崩れた値（`2026-02-30`・`2026/10/20` など）だと `Carbon::createFromFormat` が例外を投げ、台帳と Excel の全体が 500 になっていた。在る日付だけを日付にし、ほかは空にする（§0.10）。

**Files:**
- Modify: `app/Support/Approval/LedgerRow.php`
- Test: Modify `tests/Feature/Approval/Phase5/LedgerExcelTableTest.php`

**Interfaces:**
- Consumes: なし
- Produces: `LedgerRow::day(mixed $value): ?CarbonInterface`（private。`CarbonInterface` はそのまま・`Y-m-d` の在る日付だけ日本の暦の日にし、ほかは null）

**差分の大きさ:** 2 ファイル・+51 / −1 行（差分のファイル `0001-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase5/LedgerExcelTableTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase5/LedgerExcelTableTest.php
+++ b/tests/Feature/Approval/Phase5/LedgerExcelTableTest.php
@@ -220,6 +220,39 @@ public function test_a_decided_date_outside_the_years_is_left_out(): void
         $this->assertSame(['1900-01-01', '2099-12-31'], [$kept->decidedFrom?->format('Y-m-d'), $kept->decidedTo?->format('Y-m-d')]);
     }
 
+    /**
+     * 控えの契約予定日の形が崩れていても、台帳と Excel は開ける（その欄だけ空にする）。今は控えが必ず Y-m-d で作られるので起きないが、
+     * Carbon の createFromFormat は形が合わないと例外を投げ、台帳と Excel の全体が 500 になっていた（5a の BACKLOG。5b で紙の日付を足す前に直す）
+     */
+    public function test_a_broken_contract_date_in_the_revision_leaves_only_that_cell_empty(): void
+    {
+        $w        = $this->approvalWorld();
+        $request  = $this->decidedContract($w, $this->housingContractType($w));
+        $viewer   = $this->viewAllUser();
+        $revision = $request->submittedRevision();
+        $put      = function (mixed $value) use ($revision): void {
+            $snapshot = $revision->fresh()->snapshot;
+            $snapshot['extras']['contract_date'] = $value;
+            DB::table('approval_revisions')->where('id', $revision->id)->update(['snapshot' => json_encode($snapshot)]);
+        };
+
+        foreach (['2026/10/20', 'abc', '2026-02-30', '2026-13-45', '2026-1-5', ' 2026-10-20', 20261020, ['2026-10-20'], ''] as $broken) {
+            $put($broken);
+            $label = json_encode($broken);
+
+            $this->assertNull($this->rowFor($viewer, $request)->contractDate, "{$label} を日付にした");
+            $this->actingAs($viewer)->get(route('approvals.ledger.index'))->assertOk()->assertSee('山田様請負新築工事契約の件');
+            $sheet = $this->sheet($viewer);
+            $this->assertNull($sheet->getCell('M2')->getValue(), "{$label} が Excel の契約予定日に入った");
+            $this->assertSame('山田様請負新築工事契約の件', $sheet->getCell('D2')->getValue(), "{$label} で行が欠けた");
+        }
+
+        // 正しい形に戻せば入る（空振りでない）
+        $put('2026-10-21');
+        $this->assertSame('2026-10-21', $this->rowFor($viewer, $request)->contractDate?->format('Y-m-d'));
+        $this->assertSame('2026-10-21', Date::excelToDateTimeObject($this->sheet($viewer)->getCell('M2')->getValue())->format('Y-m-d'));
+    }
+
     /** 状態の「すべて」は下書き以外すべて（知らないキーを「すべて」と扱わない形に直した。4b の Task 2 の軽微） */
     public function test_every_status_choice_still_works(): void
     {
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/5b/patches/0001-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/LedgerExcelTableTest.php
```

Expected: `ERRORS!` `Tests: 8, Assertions: 60, Errors: 1.`

- `LedgerExcelTableTest::test_a_broken_contract_date_in_the_revision_leaves_only_that_cell_empty` — `Carbon\Exceptions\InvalidFormatException: Unexpected data found.`

- [ ] **Step 3: 契約予定日を在る日付だけ読む**

`app/Support/Approval/LedgerRow.php`（変更）

```diff
--- a/app/Support/Approval/LedgerRow.php
+++ b/app/Support/Approval/LedgerRow.php
@@ -82,7 +82,7 @@ public static function for(User $viewer, ApprovalRequest $request): self
             cost: $total['cost'] ?? null,
             profit: $total['profit'] ?? null,
             rate: $total['rate'] ?? null,
-            contractDate: is_string($contract) ? CarbonImmutable::createFromFormat('!Y-m-d', $contract, 'UTC') ?: null : $contract,
+            contractDate: self::day($contract),
         );
     }
 
@@ -92,6 +92,23 @@ public function amountLabel(): ?string
         return $this->amount === null ? null : number_format($this->amount) . '円';
     }
 
+    /**
+     * 契約予定日（申請者本人は列の日付・ほかの人は控えの `Y-m-d` の文字）。**形の崩れた値は空にする**（在る日付だけ。`2026-02-30` も空）。
+     * ⚠ Carbon の createFromFormat は形が合わないと例外を投げる（`?: null` は効かない）。1 行の崩れで台帳と Excel の全体を 500 にしない
+     */
+    private static function day(mixed $value): ?CarbonInterface
+    {
+        if ($value instanceof CarbonInterface) {
+            return $value;
+        }
+        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
+            return null;
+        }
+        $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
+
+        return $day !== false && $day->format('Y-m-d') === $value ? $day : null;
+    }
+
     /** 判断した段階だけ（待ち・省略・取り消しは意見もコメントも無い） */
     private static function done(?ApprovalStep $step): ?ApprovalStep
     {
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/5b/patches/0001-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 と、台帳の行・Excel のテスト）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/LedgerExcelTableTest.php tests/Feature/Approval/Phase4
```

Expected: `OK`（赤なし）

- [ ] **Step 5: 全件を流す**

Expected: `OK (4065 tests, 38242 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/LedgerRow.php tests/Feature/Approval/Phase5/LedgerExcelTableTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
fix(approval): 控えの契約予定日の形が崩れていても台帳と Excel を開ける

申請者以外に見せる契約予定日は控えの文字から作るが、形の崩れた値で
Carbon が例外を投げ、台帳と Excel の全体が 500 になっていた（5a の
持ち越し）。在る日付だけを日付にし、ほかは空にする。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 2: 紙の決裁と取り込みの表・モデル・紙の決裁がある部門の歯止め

紙の決裁（`approval_paper_records`）と取り込み 1 回分（`approval_paper_imports`）の表を、本番の SQL とテストの migration の対で作り、モデルを足す。紙の決裁No にもアルファベットと年度の和暦が入るので、紙の決裁のある部門・会社は、決裁No を付けた申請のある部門と同じく変えられない・消せないようにする（設計書 §5.3・§5.11・D9・D10・§0.2）。

**Files:**
- Create: `database/sql/2026-10-08-approval-phase5b.sql`・`database/migrations/2026_10_08_000001_create_approval_paper_tables.php`・`app/Models/ApprovalPaperRecord.php`・`app/Models/ApprovalPaperImport.php`
- Modify: `app/Support/Approval/ApprovalNumber.php`・`app/Http/Controllers/Approval/OrganizationController.php`・`resources/views/approvals/admin/organization.blade.php`
- Test: Create `tests/Feature/Approval/Phase5/Phase5bTablesTest.php`・`tests/Feature/Approval/Phase5/PaperDepartmentGuardTest.php`／Modify `tests/Concerns/BuildsApprovalFixtures.php`

**Interfaces:**
- Consumes: なし
- Produces: §0.2 の表・`ApprovalPaperRecord`（`department()`・`import()`・`creator()`・`editor()`・`rate(): ?float`）・`ApprovalPaperImport`（`records()`・`importer()`・`undoer()`）
- Produces: `ApprovalNumber::departmentHasPaperNumbers(ApprovalDepartment): bool`・`ApprovalNumber::paperNumber(ApprovalDepartment $department, int $fiscalYear, int $seq): string`（`R8-J-021`）
- Produces: `BuildsApprovalFixtures::paperRecord(ApprovalDepartment $department, array $attributes = []): ApprovalPaperRecord`（以降のテストが使う）

**差分の大きさ:** 10 ファイル・+649 / −0 行（差分のファイル `0002-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase5/Phase5bTablesTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalPaperImport;
use App\Models\ApprovalPaperRecord;
use App\Support\Approval\ApprovalNumber;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 段階5（5b）の表（紙の決裁と取り込み 1 回分。段階5 設計書 §5.3・D9・D10）。
 *
 * ⚠ 本番は SQL を手で流し、テストは migration で作る。**両方が食い違わないこと**をここで固定する（Phase5aTablesTest と同じ考え）。
 *   比べるのは、作る表・列の並びと名前・NULL を許すか・NOT NULL の列の既定・一意と索引の名前と列・外部キー。**両方向に比べる**。
 */
class Phase5bTablesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const SQL = 'database/sql/2026-10-08-approval-phase5b.sql';

    private const MIGRATION = 'database/migrations/2026_10_08_000001_create_approval_paper_tables.php';

    private const TABLES = ['approval_paper_imports', 'approval_paper_records'];

    private const TYPES = 'BIGINT|INT|SMALLINT|TINYINT|CHAR|VARCHAR|DECIMAL|MEDIUMTEXT|TEXT|JSON|TIMESTAMP|DATE';

    /** @return array<string, string> 表 => CREATE の括弧の中 */
    private function sqlCreates(): array
    {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(base_path(self::SQL)));
        preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\n\) ENGINE/s', $sql, $creates, PREG_SET_ORDER);

        $tables = [];
        foreach ($creates as [, $table, $body]) {
            $tables[$table] = $body;
        }

        return $tables;
    }

    /** @return array<string, array{nullable: bool, default: ?string}> SQL の列（並びのまま） */
    private function sqlColumns(string $table): array
    {
        preg_match_all('/`(\w+)` (?:' . self::TYPES . ')\b([^,\n]*)/', $this->sqlCreates()[$table], $columns, PREG_SET_ORDER);

        $out = [];
        foreach ($columns as [, $column, $definition]) {
            preg_match("/DEFAULT '?([^' ]+)'?/", $definition, $default);
            $out[$column] = ['nullable' => ! str_contains($definition, 'NOT NULL') && ! str_contains($definition, 'AUTO_INCREMENT'), 'default' => $default[1] ?? null];
        }

        return $out;
    }

    public function test_the_sql_and_the_migration_create_the_same_tables(): void
    {
        preg_match_all("/Schema::create\\('(\\w+)'/", file_get_contents(base_path(self::MIGRATION)), $created);

        $this->assertSame(self::TABLES, array_keys($this->sqlCreates()));
        $this->assertSame(self::TABLES, $created[1]);
        preg_match_all('/ALTER TABLE/', file_get_contents(base_path(self::SQL)), $alters);
        $this->assertSame([], $alters[0], '5b は既存の表を変えない');
    }

    public function test_the_columns_are_the_same_in_the_sql_and_the_migration(): void
    {
        // 空振りで緑にならないように（取り込みに 11 列・紙の決裁に 17 列）
        $this->assertCount(11, $this->sqlColumns('approval_paper_imports'));
        $this->assertCount(17, $this->sqlColumns('approval_paper_records'));

        foreach (self::TABLES as $table) {
            $sql      = $this->sqlColumns($table);
            $migrated = [];
            foreach (Schema::getColumns($table) as $column) {
                $migrated[$column['name']] = ['nullable' => (bool) $column['nullable'], 'default' => $column['default'] === null ? null : trim((string) $column['default'], "'")];
            }

            $this->assertSame(array_keys($sql), array_keys($migrated), "{$table} の列（並び）が SQL と migration で違う");
            foreach ($sql as $name => $column) {
                $this->assertSame($column['nullable'], $migrated[$name]['nullable'], "{$table}.{$name} の NULL を許すかが SQL と migration で違う");
                if (! $column['nullable'] && $name !== 'id') {
                    $this->assertSame($column['default'], $migrated[$name]['default'], "{$table}.{$name} の既定が SQL と migration で違う");
                }
            }
        }

        // 金額は符号あり（工事原価がマイナス・粗利益がマイナスの紙もある）・件名は空にできない
        $this->assertStringContainsString('`amount` BIGINT NULL', $this->sqlCreates()['approval_paper_records']);
        $this->assertStringContainsString('`profit` BIGINT NULL', $this->sqlCreates()['approval_paper_records']);
        $this->assertFalse($this->sqlColumns('approval_paper_records')['subject']['nullable']);
    }

    public function test_the_keys_are_the_same_in_the_sql_and_the_migration(): void
    {
        foreach (self::TABLES as $table) {
            $body = $this->sqlCreates()[$table];

            preg_match_all('/(UNIQUE KEY|KEY) `(\w+)` \(([^)]*)\)/', $body, $keys, PREG_SET_ORDER);
            $sqlKeys = [];
            foreach ($keys as [, $kind, $name, $columns]) {
                $sqlKeys[$name] = [$kind === 'UNIQUE KEY', str_replace(['`', ' '], '', $columns)];
            }
            $migratedKeys = [];
            foreach (Schema::getIndexes($table) as $index) {
                if (! $index['primary']) {
                    $migratedKeys[$index['name']] = [(bool) $index['unique'], implode(',', $index['columns'])];
                }
            }
            ksort($sqlKeys);
            ksort($migratedKeys);
            $this->assertNotSame([], $sqlKeys);
            $this->assertSame($sqlKeys, $migratedKeys, "{$table} の一意と索引が SQL と migration で違う");

            preg_match_all('/FOREIGN KEY \(`(\w+)`\) REFERENCES `(\w+)` \(`id`\) ON DELETE (\w+)/', $body, $fks, PREG_SET_ORDER);
            $sqlFks = [];
            foreach ($fks as [, $column, $parent, $onDelete]) {
                $sqlFks[$column] = [$parent, strtolower($onDelete)];
            }
            $migratedFks = [];
            foreach (Schema::getForeignKeys($table) as $fk) {
                $migratedFks[$fk['columns'][0]] = [$fk['foreign_table'], strtolower($fk['on_delete'])];
            }
            ksort($sqlFks);
            ksort($migratedFks);
            $this->assertSame($sqlFks, $migratedFks, "{$table} の外部キーが SQL と migration で違う");
        }

        // 索引と外部キーの名前は SQL と同じ名前を migration にも書く（MySQL の SHOW CREATE TABLE を同じにする）
        $migration = file_get_contents(base_path(self::MIGRATION));
        preg_match_all('/CONSTRAINT `(\w+)`/', implode("\n", $this->sqlCreates()), $names);
        $this->assertCount(6, $names[1]);
        foreach ($names[1] as $name) {
            $this->assertStringContainsString("'{$name}'", $migration, "外部キー {$name} の名前が migration に無い");
        }
    }

    public function test_a_paper_record_keeps_its_values(): void
    {
        $w       = $this->approvalWorld();
        $admin   = $this->approvalAdmin();
        $import  = ApprovalPaperImport::create(['file_name' => '08年度・決裁申請書契約状況.xls', 'fiscal_year' => 2026, 'added_count' => 1, 'imported_by' => $admin->id]);
        $record  = $this->paperRecord($w['dept'], [
            'seq' => 21, 'amount' => 12000000, 'cost' => -300000, 'profit' => 12300000, 'note' => '原本は本部の書庫', 'import_id' => $import->id, 'created_by' => $admin->id,
        ])->fresh();

        $this->assertSame('R8-J-021', $record->number);
        $this->assertSame('2026-05-13', $record->decided_on->format('Y-m-d'));
        $this->assertSame([12000000, -300000, 12300000], [$record->amount, $record->cost, $record->profit]);
        $this->assertSame([2026, 21, $w['dept']->id], [$record->fiscal_year, $record->seq, $record->department_id]);
        $this->assertTrue($record->department->is($w['dept']));
        $this->assertTrue($record->import->is($import));
        $this->assertTrue($record->creator->is($admin));
        $this->assertNull($record->editor);
        $this->assertSame([$record->id], $import->records()->pluck('id')->all());
        $this->assertTrue($import->importer->is($admin));

        // 粗利率は申請の明細表と同じ計算（12,300,000 ÷ 12,000,000 = 102.5%）。金額が 0・空、粗利益が空なら空
        $this->assertSame(102.5, $record->rate());
        $this->assertNull($record->replicate()->fill(['amount' => 0])->rate());
        $this->assertNull($record->replicate()->fill(['amount' => null])->rate());
        $this->assertNull($record->replicate()->fill(['profit' => null])->rate());

        // 退職して消した人が登録した記録も読める
        $admin->delete();
        $this->assertSame($admin->id, $record->fresh()->creator?->id);
    }

    /** 決裁No は紙どうしで重ならない・同じ部門と年度の同じＮＯも 2 つ作れない（DB の一意で止まる） */
    public function test_the_number_and_the_seq_are_unique(): void
    {
        $w = $this->approvalWorld();
        $this->paperRecord($w['dept'], ['seq' => 3]);

        foreach ([['seq' => 3, 'number' => 'R8-J-903'], ['seq' => 4, 'number' => 'R8-J-003']] as $twin) {
            try {
                $this->paperRecord($w['dept'], $twin);
                $this->fail('重なった紙の決裁を保存できた: ' . json_encode($twin));
            } catch (QueryException) {
                $this->assertSame(1, ApprovalPaperRecord::count());
            }
        }

        // 年度が違えば同じＮＯを使える（R7-J-003）
        $this->assertSame('R7-J-003', $this->paperRecord($w['dept'], ['fiscal_year' => 2025, 'seq' => 3])->number);
    }

    /** 紙の決裁No の和暦は部門の会社の期で決める（令和の始まりより前に始まる期は平成） */
    public function test_the_paper_number_uses_the_era_of_the_fiscal_year(): void
    {
        $w   = $this->approvalWorld();
        $dad = $this->approvalDepartment($this->approvalCompany(['fiscal_start_month' => 6]), ['code' => 'D']);

        $this->assertSame('R8-J-001', ApprovalNumber::paperNumber($w['dept']->fresh('company'), 2026, 1));
        $this->assertSame('R1-J-120', ApprovalNumber::paperNumber($w['dept']->fresh('company'), 2019, 120));
        $this->assertSame('H30-J-1000', ApprovalNumber::paperNumber($w['dept']->fresh('company'), 2018, 1000));
        $this->assertSame('R1-D-007', ApprovalNumber::paperNumber($dad->fresh('company'), 2019, 7));
    }
}
```

`tests/Feature/Approval/Phase5/PaperDepartmentGuardTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalDepartment;
use App\Models\User;
use App\Support\Approval\ApprovalNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 取り込んだ紙の決裁がある部門・会社の歯止め（段階5 設計書 §5.11）。
 *
 * 紙の決裁No（R8-J-021）にも部門のアルファベットと、会社の期で決まる年度の和暦が入っている。決裁No を付けた申請と同じく、
 * 部門の会社とアルファベット・会社の期の始まりの月は変えられず、部門は削除できない（外部キー RESTRICT を 500 にしない）。
 * 断りの文は申請の番号のときと分ける（申請の番号の文は段階2 のテストが固定している）。
 */
class PaperDepartmentGuardTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    /** 部門の編集のモーダルが送るのと同じ形の中身（OrganizationPhase2Test と同じ） */
    private function updateDepartment(User $admin, ApprovalDepartment $dept, array $overrides = []): TestResponse
    {
        $dept  = $dept->fresh(['reviewers', 'company']);
        $state = ApprovalNumber::currentState($dept);

        return $this->actingAs($admin)->put(route('approvals.admin.organization.departments.update', $dept), array_merge([
            'company_id'        => (string) $dept->company_id,
            'name'              => $dept->name,
            'short_name'        => $dept->short_name,
            'code'              => $dept->code,
            'sort_order'        => (string) $dept->sort_order,
            'head_user_id'      => $dept->head_user_id === null ? '' : (string) $dept->head_user_id,
            'reviewer_ids'      => $dept->reviewers->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'next_number'       => (string) $state['next'],
            'next_number_shown' => (string) $state['next'],
        ], $overrides));
    }

    public function test_the_code_and_the_company_are_locked_once_paper_records_exist(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $other = $this->approvalCompany();

        // 紙の決裁が無いうちは変えられる
        $this->updateDepartment($admin, $w['dept'], ['code' => 'JX'])->assertSessionHas('success', '部門を更新しました。');
        $this->updateDepartment($admin, $w['dept'], ['code' => 'J'])->assertSessionHas('success', '部門を更新しました。');

        $this->paperRecord($w['dept'], ['fiscal_year' => 2018]);   // 過去の年度だけでも（H30-J-001）
        $this->assertTrue(ApprovalNumber::departmentHasPaperNumbers($w['dept']));
        $this->assertFalse(ApprovalNumber::departmentHasNumbers($w['dept']), '申請の番号とは別に聞く');
        $this->assertFalse(ApprovalNumber::departmentHasPaperNumbers($w['reviewDept']));

        foreach ([['code' => 'JX'], ['company_id' => (string) $other->id]] as $change) {
            $this->updateDepartment($admin, $w['dept'], $change)
                ->assertSessionHas('error', 'この部門には取り込んだ紙の決裁があるため、会社とアルファベットは変えられません。');
        }
        $this->assertSame(['J', $w['company']->id], [$w['dept']->fresh()->code, $w['dept']->fresh()->company_id]);

        // ほかの項目は変えられる
        $this->updateDepartment($admin, $w['dept'], ['short_name' => '住宅2'])->assertSessionHas('success', '部門を更新しました。');
        $this->assertSame('住宅2', $w['dept']->fresh()->short_name);
    }

    public function test_the_fiscal_start_month_is_locked_once_paper_records_exist(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $route = route('approvals.admin.organization.companies.update', $w['company']);
        $send  = fn (int $month) => ['name' => $w['company']->name, 'fiscal_start_month' => (string) $month, 'sort_order' => (string) $w['company']->sort_order];

        $this->actingAs($admin)->put($route, $send(6))->assertSessionHas('success', '会社を更新しました。');
        $this->actingAs($admin)->put($route, $send(5))->assertSessionHas('success', '会社を更新しました。');

        $this->paperRecord($w['dept']);

        $this->actingAs($admin)->put($route, $send(6))
            ->assertSessionHas('error', 'この会社には取り込んだ紙の決裁がある部門があるため、期の始まりの月は変えられません。');
        $this->assertSame(5, $w['company']->fresh()->fiscal_start_month);

        // ほかの会社の部門の紙の決裁では止まらない
        $other = $this->approvalCompany();
        $this->actingAs($admin)->put(route('approvals.admin.organization.companies.update', $other), ['name' => $other->name, 'fiscal_start_month' => '6', 'sort_order' => '1'])
            ->assertSessionHas('success', '会社を更新しました。');
    }

    public function test_a_department_with_paper_records_cannot_be_deleted(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $dept  = $this->approvalDepartment($w['company']);
        $this->paperRecord($dept);
        $this->paperRecord($dept, ['fiscal_year' => 2025]);

        $this->actingAs($admin)->delete(route('approvals.admin.organization.departments.destroy', $dept))
            ->assertSessionHas('error', 'この部門は取り込んだ紙の決裁 2 件に使われているため削除できません。');
        $this->assertNotNull($dept->fresh());

        // 紙の決裁の無い部門は消せる（空振りでない）
        $empty = $this->approvalDepartment($w['company']);
        $this->actingAs($admin)->delete(route('approvals.admin.organization.departments.destroy', $empty))->assertSessionHas('success', '部門を削除しました。');
    }

    /** 編集のモーダルに、紙の決裁があるときの注意（申請の番号の注意とは別の文）を出す */
    public function test_the_edit_modal_tells_why_the_code_is_locked(): void
    {
        $w = $this->approvalWorld();
        $this->paperRecord($w['dept']);

        $html = $this->actingAs($this->approvalAdmin())->get(route('approvals.admin.organization.index'))->assertOk()->getContent();

        preg_match_all("/openDepartmentEdit\\(JSON\\.parse\\('([^']*)'\\)\\)/", $html, $m);
        $rows = [];
        foreach ($m[1] as $inner) {
            $row             = json_decode(json_decode('"' . $inner . '"'), true);
            $rows[$row['id']] = $row;
        }
        $this->assertSame([true, false], [$rows[$w['dept']->id]['has_paper'], $rows[$w['dept']->id]['has_numbers']]);
        $this->assertFalse($rows[$w['reviewDept']->id]['has_paper']);
        $this->assertStringContainsString('<p x-show="editDepartmentHasPaper && !editDepartmentHasNumbers" x-cloak class="text-[11px] text-amber-700 mt-1">取り込んだ紙の決裁があるため、会社とアルファベットは変えられません。</p>', $html);
        $this->assertStringContainsString('this.editDepartmentHasPaper = row.has_paper;', $html);
    }
}
```

`tests/Concerns/BuildsApprovalFixtures.php`（変更）

```diff
--- a/tests/Concerns/BuildsApprovalFixtures.php
+++ b/tests/Concerns/BuildsApprovalFixtures.php
@@ -6,10 +6,12 @@
 use App\Models\ApprovalCompany;
 use App\Models\ApprovalDepartment;
 use App\Models\ApprovalMember;
+use App\Models\ApprovalPaperRecord;
 use App\Models\ApprovalRequest;
 use App\Models\ApprovalSetting;
 use App\Models\ApprovalType;
 use App\Models\User;
+use App\Support\Approval\ApprovalNumber;
 use App\Support\Approval\BodyTemplate;
 use App\Support\Approval\Workflow;
 
@@ -177,4 +179,25 @@ protected function housingContractType(array $world, array $attributes = []): Ap
             'fixed_text'         => '上記の内容に基づき、販売をおこないます。',
         ], $attributes));
     }
+
+    /**
+     * 取り込んだ過去の紙の決裁（段階5 5b）。既定は R8 年度（2026）・その部門の次のＮＯ・2026/05/13・申請者「甲野」。
+     * 決裁No は年度・部門・ＮＯから組み立てる（`ApprovalNumber::paperNumber`）
+     */
+    protected function paperRecord(ApprovalDepartment $department, array $attributes = []): ApprovalPaperRecord
+    {
+        $fiscalYear = $attributes['fiscal_year'] ?? 2026;
+        $seq        = $attributes['seq'] ?? (int) ApprovalPaperRecord::where('department_id', $department->id)->where('fiscal_year', $fiscalYear)->max('seq') + 1;
+
+        return ApprovalPaperRecord::create(array_merge([
+            'department_id'  => $department->id,
+            'fiscal_year'    => $fiscalYear,
+            'seq'            => $seq,
+            'number'         => ApprovalNumber::paperNumber($department->fresh('company'), $fiscalYear, $seq),
+            'decided_on'     => '2026-05-13',
+            'applicant_name' => '甲野',
+            'subject'        => '見本邸新築工事契約',
+            'created_by'     => $attributes['created_by'] ?? $this->baseUser()->id,
+        ], $attributes));
+    }
 }
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/5b/patches/0002-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/Phase5bTablesTest.php tests/Feature/Approval/Phase5/PaperDepartmentGuardTest.php
```

Expected: `ERRORS!` `Tests: 10, Assertions: 4, Errors: 10.`

- `PaperDepartmentGuardTest::test_the_code_and_the_company_are_locked_once_paper_records_exist` — `Error: Class "App\Models\ApprovalPaperRecord" not found`
- `PaperDepartmentGuardTest::test_the_fiscal_start_month_is_locked_once_paper_records_exist` — `Error: Class "App\Models\ApprovalPaperRecord" not found`
- `PaperDepartmentGuardTest::test_a_department_with_paper_records_cannot_be_deleted` — `Error: Class "App\Models\ApprovalPaperRecord" not found`
- `PaperDepartmentGuardTest::test_the_edit_modal_tells_why_the_code_is_locked` — `Error: Class "App\Models\ApprovalPaperRecord" not found`
- `Phase5bTablesTest::test_the_sql_and_the_migration_create_the_same_tables` — `ErrorException: file_get_contents(/Users/masanori/site/manage/.claude/worktrees/approval-phase3/database/migratio`
- `Phase5bTablesTest::test_the_columns_are_the_same_in_the_sql_and_the_migration` — `ErrorException: file_get_contents(/Users/masanori/site/manage/.claude/worktrees/approval-phase3/database/sql/2026`
- `Phase5bTablesTest::test_the_keys_are_the_same_in_the_sql_and_the_migration` — `ErrorException: file_get_contents(/Users/masanori/site/manage/.claude/worktrees/approval-phase3/database/sql/2026`
- `Phase5bTablesTest::test_a_paper_record_keeps_its_values` — `Error: Class "App\Models\ApprovalPaperImport" not found`
- `Phase5bTablesTest::test_the_number_and_the_seq_are_unique` — `Error: Class "App\Models\ApprovalPaperRecord" not found`
- `Phase5bTablesTest::test_the_paper_number_uses_the_era_of_the_fiscal_year` — `Error: Call to undefined method App\Support\Approval\ApprovalNumber::paperNumber()`

- [ ] **Step 3: 表とモデルを足し、部門の歯止めに紙の決裁を入れる**

`database/sql/2026-10-08-approval-phase5b.sql`（新規）

```sql
-- 決裁申請 段階5（5b）— 2026-10-08
--
-- 設計書: docs/superpowers/specs/2026-10-07-approval-phase5-design.md §5.3・§5.9〜§5.11・D6・D9・D10
--
-- ⚠ database/migrations/2026_10_08_000001_create_approval_paper_tables.php と
--   対で維持すること（あちらは SQLite のテストのための鏡。Phase5bTablesTest が見る）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** 新しいコードは決裁台帳・部門の管理・関連する決裁No の候補で approval_paper_records を読むので、
--   コードを先に送ると、部門の管理（⑧）が Table doesn't exist で 500 になる（台帳などは使い始める前なので誰も開けないが、順番は守る）。
--
-- 適用: 段階1〜5a と同じく php artisan tinker --execute で DB::statement() に **1 文ずつ**流す。
--   先頭で「approval_paper_imports か approval_paper_records があれば 1 文も流さずに止まる」確認をする（計画 Task 12）。
--   ⚠ approval_paper_records は approval_paper_imports を指すので、**1 → 2 の順**に流す。

-- 1. 過去の紙の決裁の取り込み 1 回分（取り消しの単位。D9）
CREATE TABLE `approval_paper_imports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_name` VARCHAR(255) NOT NULL COMMENT '取り込んだファイルの名前',
  `fiscal_year` SMALLINT UNSIGNED NOT NULL COMMENT '年度（期の始まりの年）',
  `added_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '足した記録の数',
  `filled_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '金額を補った、すでにある記録の数',
  `imported_by` BIGINT UNSIGNED NOT NULL,
  `undone_at` TIMESTAMP NULL COMMENT '取り消した日時',
  `undone_by` BIGINT UNSIGNED NULL,
  `undone_count` INT UNSIGNED NULL COMMENT '取り消しで消した記録の数',
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_approval_paper_imports_imported_by` (`imported_by`),
  KEY `idx_approval_paper_imports_undone_by` (`undone_by`),
  CONSTRAINT `fk_approval_paper_imports_imported_by` FOREIGN KEY (`imported_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_paper_imports_undone_by` FOREIGN KEY (`undone_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 取り込んだ過去の紙の決裁（要件 11.2・D10。申請の表とは別に持ち、台帳で合わせて並べる）
CREATE TABLE `approval_paper_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` BIGINT UNSIGNED NOT NULL,
  `fiscal_year` SMALLINT UNSIGNED NOT NULL COMMENT '年度（期の始まりの年）',
  `seq` INT UNSIGNED NOT NULL COMMENT '受付簿のＮＯ',
  `number` VARCHAR(20) NOT NULL COMMENT '決裁No（年度・部門・ＮＯから組み立てる。R8-J-021）',
  `decided_on` DATE NOT NULL COMMENT '決裁日（受付簿の日付）',
  `applicant_name` VARCHAR(50) NULL COMMENT '申請者（受付簿の文字のまま。利用者と結び付けない）',
  `subject` VARCHAR(100) NOT NULL COMMENT '件名',
  `amount` BIGINT NULL COMMENT '金額（金額の控えの契約金額）',
  `cost` BIGINT NULL COMMENT '工事原価',
  `profit` BIGINT NULL COMMENT '粗利益',
  `note` TEXT NULL COMMENT '備考',
  `import_id` BIGINT UNSIGNED NULL COMMENT '足した取り込み（手で登録したものは空）',
  `created_by` BIGINT UNSIGNED NOT NULL,
  `updated_by` BIGINT UNSIGNED NULL COMMENT '手で直した人（取り込みで金額を補っても変えない）',
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_approval_paper_records_number` (`number`),
  UNIQUE KEY `uq_approval_paper_records_seq` (`department_id`, `fiscal_year`, `seq`),
  KEY `idx_approval_paper_records_decided` (`decided_on`),
  KEY `idx_approval_paper_records_import` (`import_id`),
  KEY `idx_approval_paper_records_created_by` (`created_by`),
  KEY `idx_approval_paper_records_updated_by` (`updated_by`),
  CONSTRAINT `fk_approval_paper_records_dept` FOREIGN KEY (`department_id`) REFERENCES `approval_departments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_paper_records_import` FOREIGN KEY (`import_id`) REFERENCES `approval_paper_imports` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_paper_records_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_approval_paper_records_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`database/migrations/2026_10_08_000001_create_approval_paper_tables.php`（新規）

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 決裁申請 段階5（5b）— 過去の紙の決裁と、その取り込み 1 回分（段階5 設計書 §5.3・§5.9〜§5.11・D9・D10）。
 *
 * ⚠ 本番は database/sql/2026-10-08-approval-phase5b.sql を手で流す。これはテスト（SQLite）のための鏡で、
 *   Phase5bTablesTest が両方を読んで同じ表であることを見る。
 * ⚠ 索引と外部キーの名前は SQL と同じ名前を書く（MySQL の SHOW CREATE TABLE が同じになる。5a の中間表は Laravel の既定の名前で、
 *   SQL と名前だけ違った＝5a 計画 §0.15 の 5）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_paper_imports', function (Blueprint $table) {
            $table->id();
            $table->string('file_name', 255)->comment('取り込んだファイルの名前');
            $table->unsignedSmallInteger('fiscal_year')->comment('年度（期の始まりの年）');
            $table->unsignedInteger('added_count')->default(0)->comment('足した記録の数');
            $table->unsignedInteger('filled_count')->default(0)->comment('金額を補った、すでにある記録の数');
            $table->unsignedBigInteger('imported_by');
            $table->timestamp('undone_at')->nullable()->comment('取り消した日時');
            $table->unsignedBigInteger('undone_by')->nullable();
            $table->unsignedInteger('undone_count')->nullable()->comment('取り消しで消した記録の数');
            $table->timestamps();

            $table->index('imported_by', 'idx_approval_paper_imports_imported_by');
            $table->index('undone_by', 'idx_approval_paper_imports_undone_by');
            $table->foreign('imported_by', 'fk_approval_paper_imports_imported_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('undone_by', 'fk_approval_paper_imports_undone_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('approval_paper_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->unsignedSmallInteger('fiscal_year')->comment('年度（期の始まりの年）');
            $table->unsignedInteger('seq')->comment('受付簿のＮＯ');
            $table->string('number', 20)->comment('決裁No（年度・部門・ＮＯから組み立てる。R8-J-021）');
            $table->date('decided_on')->comment('決裁日（受付簿の日付）');
            $table->string('applicant_name', 50)->nullable()->comment('申請者（受付簿の文字のまま。利用者と結び付けない）');
            $table->string('subject', 100)->comment('件名');
            $table->bigInteger('amount')->nullable()->comment('金額（金額の控えの契約金額）');
            $table->bigInteger('cost')->nullable()->comment('工事原価');
            $table->bigInteger('profit')->nullable()->comment('粗利益');
            $table->text('note')->nullable()->comment('備考');
            $table->unsignedBigInteger('import_id')->nullable()->comment('足した取り込み（手で登録したものは空）');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('手で直した人（取り込みで金額を補っても変えない）');
            $table->timestamps();

            $table->unique('number', 'uq_approval_paper_records_number');
            $table->unique(['department_id', 'fiscal_year', 'seq'], 'uq_approval_paper_records_seq');
            $table->index('decided_on', 'idx_approval_paper_records_decided');
            $table->index('import_id', 'idx_approval_paper_records_import');
            $table->index('created_by', 'idx_approval_paper_records_created_by');
            $table->index('updated_by', 'idx_approval_paper_records_updated_by');
            $table->foreign('department_id', 'fk_approval_paper_records_dept')->references('id')->on('approval_departments')->restrictOnDelete();
            $table->foreign('import_id', 'fk_approval_paper_records_import')->references('id')->on('approval_paper_imports')->restrictOnDelete();
            $table->foreign('created_by', 'fk_approval_paper_records_created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by', 'fk_approval_paper_records_updated_by')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_paper_records');
        Schema::dropIfExists('approval_paper_imports');
    }
};
```

`app/Models/ApprovalPaperRecord.php`（新規）

```php
<?php

namespace App\Models;

use App\Support\Approval\AmountTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 取り込んだ過去の紙の決裁（要件 11.2・段階5 設計書 §5.9〜§5.15・D10）。
 *
 * ⚠ 申請（approval_requests）とは別の表。回覧の操作はできない。申請の表を読むホーム・自分の申請・⑩・お知らせ・催促には出ない
 *   （D10。台帳・Excel・関連する決裁No・紙の決裁の詳細だけが読む）。
 * ⚠ 決裁No（`number`）は年度・部門・ＮＯ から組み立てる（`ApprovalNumber::paperNumber()`）。紙の番号どうし・システムの申請の番号と
 *   重ならない（取り込みと 1 件ずつの登録が確かめる）。
 * ⚠ 決裁日（`decided_on`）は日付（時刻なし）。表示は `->format('Y/m/d')`（JapanTime::format で時差を足さない）。
 * ⚠ `updated_by` は**手で直した人**（取り込みで金額を補っても変えない）。取り込みの取り消しの「あとで手で直した記録」の数に使う。
 */
class ApprovalPaperRecord extends Model
{
    protected $fillable = [
        'department_id', 'fiscal_year', 'seq', 'number', 'decided_on', 'applicant_name', 'subject',
        'amount', 'cost', 'profit', 'note', 'import_id', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'department_id' => 'integer',
            'fiscal_year'   => 'integer',
            'seq'           => 'integer',
            'decided_on'    => 'date',
            'amount'        => 'integer',
            'cost'          => 'integer',
            'profit'        => 'integer',
            'import_id'     => 'integer',
            'created_by'    => 'integer',
            'updated_by'    => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ApprovalDepartment::class, 'department_id');
    }

    /** 足した取り込み（手で登録したものは空） */
    public function import(): BelongsTo
    {
        return $this->belongsTo(ApprovalPaperImport::class, 'import_id');
    }

    /** 登録した人（⚠ 利用者は SoftDeletes。Top trap #18） */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** 手で直した人 */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    /** 粗利率（金額と粗利益から。申請の明細表と同じ計算＝整数で四捨五入。金額が空か 0・粗利益が空なら空） */
    public function rate(): ?float
    {
        return $this->amount === null || $this->profit === null ? null : AmountTable::rate($this->amount, $this->profit);
    }
}
```

`app/Models/ApprovalPaperImport.php`（新規）

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 過去の紙の決裁の取り込み 1 回分（段階5 設計書 §5.9・§5.10・D9）。取り消しの単位。
 *
 * ⚠ 取り消しても行は消さない（`undone_at`・`undone_by`・`undone_count` を入れる）。いつ・誰が・何件を取り込み、取り消したかを残す。
 */
class ApprovalPaperImport extends Model
{
    protected $fillable = ['file_name', 'fiscal_year', 'added_count', 'filled_count', 'imported_by', 'undone_at', 'undone_by', 'undone_count'];

    protected function casts(): array
    {
        return [
            'fiscal_year'  => 'integer',
            'added_count'  => 'integer',
            'filled_count' => 'integer',
            'imported_by'  => 'integer',
            'undone_at'    => 'datetime',
            'undone_by'    => 'integer',
            'undone_count' => 'integer',
        ];
    }

    /** この取り込みで足した記録（そのあと手で直したものも含む。金額を補っただけの記録は入らない） */
    public function records(): HasMany
    {
        return $this->hasMany(ApprovalPaperRecord::class, 'import_id');
    }

    /** 取り込んだ人（⚠ 利用者は SoftDeletes。Top trap #18） */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by')->withTrashed();
    }

    public function undoer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by')->withTrashed();
    }
}
```

`app/Support/Approval/ApprovalNumber.php`（変更）

```diff
--- a/app/Support/Approval/ApprovalNumber.php
+++ b/app/Support/Approval/ApprovalNumber.php
@@ -4,6 +4,7 @@
 
 use App\Models\ApprovalDepartment;
 use App\Models\ApprovalNumberSequence;
+use App\Models\ApprovalPaperRecord;
 use App\Models\ApprovalRequest;
 use DateTimeInterface;
 use Illuminate\Support\Facades\DB;
@@ -95,6 +96,21 @@ public static function departmentHasNumbers(ApprovalDepartment $department): boo
         return ApprovalRequest::where('number_department_id', $department->id)->exists();
     }
 
+    /**
+     * 取り込んだ紙の決裁がある部門か（紙の決裁No にもアルファベットと年度の和暦が入るので、会社とアルファベットを変えられない。段階5 §5.11）。
+     * 申請の番号（departmentHasNumbers）とは別に聞く（部門の管理の断りの文を分ける）
+     */
+    public static function departmentHasPaperNumbers(ApprovalDepartment $department): bool
+    {
+        return ApprovalPaperRecord::where('department_id', $department->id)->exists();
+    }
+
+    /** 紙の決裁の決裁No（年度・部門・ＮＯから。和暦は部門の会社の期で決める。R8-J-021。要件 11.2） */
+    public static function paperNumber(ApprovalDepartment $department, int $fiscalYear, int $seq): string
+    {
+        return self::format(ApprovalFiscalYear::eraLabel($fiscalYear, $department->company->fiscal_start_month), $department->code, $seq);
+    }
+
     private static function lockedRow(ApprovalDepartment $department, int $fiscalYear): ApprovalNumberSequence
     {
         // ⚠ 時計は 1 回だけ読む（ClockReadScanTest はファイルごとの件数を見る）
```

`app/Http/Controllers/Approval/OrganizationController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/OrganizationController.php
+++ b/app/Http/Controllers/Approval/OrganizationController.php
@@ -9,6 +9,7 @@
 use App\Models\ApprovalDepartment;
 use App\Models\ApprovalMailDomain;
 use App\Models\ApprovalNumberSequence;
+use App\Models\ApprovalPaperRecord;
 use App\Models\ApprovalRequest;
 use App\Models\ApprovalStep;
 use App\Models\ApprovalType;
@@ -47,6 +48,7 @@ function (ApprovalDepartment $department) use ($company): void {
                 $department->setRelation('company', $company);
                 $department->number_state = ApprovalNumber::currentState($department);
                 $department->has_numbers  = ApprovalNumber::departmentHasNumbers($department);
+                $department->has_paper    = ApprovalNumber::departmentHasPaperNumbers($department);
             }
         ));
 
@@ -89,6 +91,12 @@ public function updateCompany(Request $request, ApprovalCompany $approvalCompany
             return $this->back(null, 'この会社には決裁No を付けた申請がある部門があるため、期の始まりの月は変えられません。');
         }
 
+        // 取り込んだ紙の決裁も同じ（紙の決裁No の和暦は年度の始まりの日で決まる。段階5 §5.11）
+        if ((int) $validated['fiscal_start_month'] !== $approvalCompany->fiscal_start_month
+            && ApprovalPaperRecord::whereIn('department_id', ApprovalDepartment::where('company_id', $approvalCompany->id)->select('id'))->exists()) {
+            return $this->back(null, 'この会社には取り込んだ紙の決裁がある部門があるため、期の始まりの月は変えられません。');
+        }
+
         $before = $approvalCompany->only(array_keys($validated));
 
         $approvalCompany->update($validated);
@@ -165,6 +173,12 @@ public function updateDepartment(Request $request, ApprovalDepartment $approvalD
             return $this->back(null, 'この部門には決裁No を付けた申請があるため、会社とアルファベットは変えられません。');
         }
 
+        // 取り込んだ紙の決裁も同じ（紙の決裁No にアルファベットと年度の和暦が入っている。段階5 §5.11）
+        if (ApprovalNumber::departmentHasPaperNumbers($approvalDepartment)
+            && ((int) $base['company_id'] !== $approvalDepartment->company_id || $base['code'] !== $approvalDepartment->code)) {
+            return $this->back(null, 'この部門には取り込んだ紙の決裁があるため、会社とアルファベットは変えられません。');
+        }
+
         $oldHeadId = $approvalDepartment->head_user_id;
 
         // 部門長の確認を待っている申請があるうちは、部門長を空にできない（後任を選ぶ）
@@ -232,6 +246,13 @@ public function destroyDepartment(ApprovalDepartment $approvalDepartment)
             return $this->back(null, "この部門は申請 {$used} 件に使われているため削除できません。");
         }
 
+        // 取り込んだ紙の決裁の部門も削除できない（外部キー RESTRICT。段階5 §5.10。消すなら過去の決裁の取り込みで先に紙の決裁を消す）
+        $papers = ApprovalPaperRecord::where('department_id', $approvalDepartment->id)->count();
+
+        if ($papers > 0) {
+            return $this->back(null, "この部門は取り込んだ紙の決裁 {$papers} 件に使われているため削除できません。");
+        }
+
         $types = ApprovalType::where('review_department_id', $approvalDepartment->id)->count();
 
         if ($types > 0) {
```

`resources/views/approvals/admin/organization.blade.php`（変更）

```diff
--- a/resources/views/approvals/admin/organization.blade.php
+++ b/resources/views/approvals/admin/organization.blade.php
@@ -119,6 +119,7 @@
                                 'next_number'  => $state['next'],
                                 'number_hint'  => $numberHint,
                                 'has_numbers'  => $dept->has_numbers,
+                                'has_paper'    => $dept->has_paper,
                             ]) }})" class="text-[12px] text-blue-600 hover:underline cursor-pointer bg-transparent border-none p-0">編集</button>
                             <span class="text-gray-200 mx-1">|</span>
                             <form method="POST" action="{{ route('approvals.admin.organization.departments.destroy', $dept) }}" class="inline" onsubmit="return confirm('この部門を削除しますか。');">
@@ -345,6 +346,7 @@ class="h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 w-
                         <input type="text" name="code" x-model="editDepartmentCode" required maxlength="3" autocapitalize="characters" spellcheck="false" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] font-mono uppercase">
                         <p class="text-[11px] text-gray-400 mt-1">申請番号に使う英大文字1〜3文字（グループ全体で重複不可）</p>
                         <p x-show="editDepartmentHasNumbers" class="text-[11px] text-amber-700 mt-1">決裁No を付けた申請があるため、会社とアルファベットは変えられません。</p>
+                        <p x-show="editDepartmentHasPaper && !editDepartmentHasNumbers" x-cloak class="text-[11px] text-amber-700 mt-1">取り込んだ紙の決裁があるため、会社とアルファベットは変えられません。</p>
                     </div>
                     <div>
                         <label class="block text-[12px] font-semibold text-gray-700 mb-1">表示順<span class="text-red-600 ml-0.5">*</span></label>
@@ -417,6 +419,7 @@ function approvalOrganization() {
         editDepartmentNextShown: '1',
         editDepartmentNumberHint: '',
         editDepartmentHasNumbers: false,
+        editDepartmentHasPaper: false,
         reviewerFilter: '',
 
         openCompanyEdit(row) {
@@ -441,6 +444,7 @@ function approvalOrganization() {
             this.editDepartmentNextShown = String(row.next_number);
             this.editDepartmentNumberHint = row.number_hint;
             this.editDepartmentHasNumbers = row.has_numbers;
+            this.editDepartmentHasPaper = row.has_paper;
             this.reviewerFilter = '';
             this.departmentEditModal = true;
         },
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/5b/patches/0002-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 と、部門の管理のテスト）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/Phase5bTablesTest.php tests/Feature/Approval/Phase5/PaperDepartmentGuardTest.php tests/Feature/Approval/OrganizationManagementTest.php tests/Feature/Approval/Phase2/OrganizationPhase2Test.php
```

Expected: `OK`（赤なし。部門の管理のテストは変えずに通る＝申請の番号の断りの文は同じ）

- [ ] **Step 5: 全件を流す**

Expected: `OK (4075 tests, 38354 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add database/sql/2026-10-08-approval-phase5b.sql database/migrations/2026_10_08_000001_create_approval_paper_tables.php app/Models/ApprovalPaperRecord.php app/Models/ApprovalPaperImport.php app/Support/Approval/ApprovalNumber.php app/Http/Controllers/Approval/OrganizationController.php resources/views/approvals/admin/organization.blade.php tests/Feature/Approval/Phase5/Phase5bTablesTest.php tests/Feature/Approval/Phase5/PaperDepartmentGuardTest.php tests/Concerns/BuildsApprovalFixtures.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 紙の決裁と取り込みの表を足し紙の決裁がある部門を守る

過去の紙の決裁（approval_paper_records）と取り込み 1 回分
（approval_paper_imports）の表を、本番の SQL とテストの migration の
対で作った。紙の決裁No にもアルファベットと年度の和暦が入るので、
紙の決裁のある部門は会社とアルファベットを変えられず消せない・会社は
期の始まりの月を変えられない（段階5 設計書 §5.11）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 3: 紙の決裁の見られる範囲

紙の決裁を見られるのは、その部門に所属する人・その部門の今の部門長・全件閲覧者・決裁の管理者（要件 7 章・設計書 §5.12）。規則は申請の見られる範囲と同じ `RequestVisibility` の 1 か所に書き、台帳・Excel・詳細・関連する決裁No の候補とリンクがすべてそれを使う（§0.8）。

**Files:**
- Modify: `app/Support/Approval/RequestVisibility.php`・`app/Models/ApprovalPaperRecord.php`
- Test: Create `tests/Feature/Approval/Phase5/PaperVisibilityTest.php`／Modify `tests/Feature/Approval/Phase2/RequestVisibilityTest.php`

**Interfaces:**
- Consumes: Task 2 の `ApprovalPaperRecord`・`paperRecord()`
- Produces: `RequestVisibility::constrainPaper(Builder $query, User $user): void`（`ApprovalPaperRecord::scopeVisibleTo()` だけが呼ぶ）・`RequestVisibility::canViewPaper(User $user, ApprovalPaperRecord $record): bool`・`ApprovalPaperRecord::query()->visibleTo($user)`

**差分の大きさ:** 4 ファイル・+163 / −6 行（差分のファイル `0003-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase5/PaperVisibilityTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalPaperRecord;
use App\Models\User;
use App\Support\Approval\RequestVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 取り込んだ紙の決裁の見られる範囲（要件 7 章の最後・段階5 設計書 §5.12）。
 *
 * その部門に所属する人・その部門の今の部門長・全件閲覧者・決裁の管理者だけ。紙の時代の申請者は利用者と結び付けないので、
 * 同じ名前の人でも「自分の申請」としては見えない。社長は全件閲覧者にも指定されていれば見られる。
 * 1 件の判定（canViewPaper）と一覧の絞り込み（visibleTo）は同じ規則から作る（どちらも確かめる）。
 */
class PaperVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    /** @return list<int> 見られる紙の決裁の id（小さい順） */
    private function visibleIds(User $user): array
    {
        return ApprovalPaperRecord::query()->visibleTo($user)->orderBy('id')->pluck('id')->all();
    }

    public function test_who_can_see_a_paper_record(): void
    {
        $w      = $this->approvalWorld();
        $other  = $this->approvalDepartment($w['company'], ['code' => 'M']);
        $ownJ   = $this->paperRecord($w['dept'], ['applicant_name' => '申請 花子']);
        $ownM   = $this->paperRecord($other);
        $both   = [$ownJ->id, $ownM->id];

        $memberOfM = $this->approvalOnlyUser(['name' => 'ミツワ 不動産']);
        $memberOfM->approvalDepartments()->attach($other->id);
        $twoDepts  = $this->approvalOnlyUser(['name' => '兼務 の人']);
        $twoDepts->approvalDepartments()->attach([$w['dept']->id, $other->id]);
        $headOfM   = $this->baseUser(['name' => 'M の 部門長']);
        $other->update(['head_user_id' => $headOfM->id]);
        $stranger  = $this->baseUser(['name' => '関係 ない人']);
        $viewAll   = $this->viewAllUser();
        $admin     = $this->approvalAdmin();

        $cases = [
            '申請部門に所属する人（同じ名前でも自分の申請としてではなく自部門の過去分として）' => [$w['applicant'], [$ownJ->id]],
            'ほかの部門に所属する人'              => [$memberOfM, [$ownM->id]],
            '2 つの部門に所属する人'              => [$twoDepts, $both],
            'その部門の今の部門長（所属していない）' => [$w['head'], [$ownJ->id]],
            'ほかの部門の部門長'                  => [$headOfM, [$ownM->id]],
            '審査担当者（所属していない）'          => [$w['reviewer'], []],
            '社長だけ'                            => [$w['president'], []],
            '関わりの無い人'                      => [$stranger, []],
            '全件閲覧者'                          => [$viewAll, $both],
            '決裁の管理者'                        => [$admin, $both],
        ];

        foreach ($cases as $label => [$user, $expected]) {
            $this->assertSame($expected, $this->visibleIds($user), $label);
            foreach ([$ownJ, $ownM] as $record) {
                $this->assertSame(in_array($record->id, $expected, true), RequestVisibility::canViewPaper($user, $record), "{$label}: {$record->number} の 1 件の判定が一覧と違う");
            }
        }

        // 社長を全件閲覧者にも指定すれば見られる（要件 7 章）
        $this->makePresident($viewAll);
        $this->assertSame($both, $this->visibleIds($viewAll->fresh()));

        // 部門長を外れた人は見られなくなる（今の担当で決める）
        $other->update(['head_user_id' => null]);
        $this->assertSame([], $this->visibleIds($headOfM->fresh()));

        // 保存していない利用者には何も見せない
        $this->assertSame([], $this->visibleIds(new User()));
    }

    /** 呼ぶ側が先に書いた OR から、見られない紙の決裁が漏れない（スコープが括弧に入る） */
    public function test_an_or_before_the_scope_does_not_leak(): void
    {
        $w      = $this->approvalWorld();
        $other  = $this->approvalDepartment($w['company']);
        $mine   = $this->paperRecord($w['dept'], ['subject' => '見える契約']);
        $hidden = $this->paperRecord($other, ['subject' => '見えない契約']);

        $ids = ApprovalPaperRecord::query()
            ->where('subject', '見える契約')->orWhere('subject', '見えない契約')
            ->visibleTo($w['applicant'])
            ->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);
        $this->assertNotContains($hidden->id, $ids);
    }
}
```

規則の本体を呼ぶ所の走査に `constrainPaper` を足す（⚠ 注記に `constrain()` の字を書くと走査に数えられる。紙のモデル・`RequestVisibility` の注記は「規則の本体」と書く）:

`tests/Feature/Approval/Phase2/RequestVisibilityTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/RequestVisibilityTest.php
+++ b/tests/Feature/Approval/Phase2/RequestVisibilityTest.php
@@ -542,8 +542,25 @@ public function test_an_unsaved_user_sees_nothing(): void
     /**
      * 規則の本体 `RequestVisibility::constrain()` を呼んでよいのは、モデルのローカルスコープだけ
      * （直接呼ぶと、呼ぶ側が先に書いた OR が括弧に入らず漏れる。Task 8 の再点検の軽微）。
+     * 取り込んだ紙の決裁の規則の本体 `constrainPaper()` も同じ（紙の決裁のモデルのスコープだけ。段階5 §5.12）
      */
     public function test_only_the_model_scope_calls_the_rule_body(): void
+    {
+        $callers = fn (string $call) => $this->filesCalling($call);
+
+        // 定義（RequestVisibility.php）と、スコープからの呼び出し（モデル）の 1 つずつだけ
+        $this->assertSame([
+            'app/Models/ApprovalRequest.php'             => 1,
+            'app/Support/Approval/RequestVisibility.php' => 1,
+        ], $callers('constrain('));
+        $this->assertSame([
+            'app/Models/ApprovalPaperRecord.php'         => 1,
+            'app/Support/Approval/RequestVisibility.php' => 1,
+        ], $callers('constrainPaper('));
+    }
+
+    /** @return array<string, int> その呼び出しの字を含むファイル（app・ビュー・ルート）=> 数 */
+    private function filesCalling(string $call): array
     {
         $callers = [];
         foreach (['app', 'resources/views', 'routes'] as $dir) {
@@ -552,7 +569,7 @@ public function test_only_the_model_scope_calls_the_rule_body(): void
                 if (! str_ends_with($file->getFilename(), '.php')) {
                     continue;
                 }
-                $count = substr_count((string) file_get_contents($file->getPathname()), 'constrain(');
+                $count = substr_count((string) file_get_contents($file->getPathname()), $call);
                 if ($count > 0) {
                     $callers[str_replace(base_path() . '/', '', $file->getPathname())] = $count;
                 }
@@ -560,10 +577,6 @@ public function test_only_the_model_scope_calls_the_rule_body(): void
         }
         ksort($callers);
 
-        // 定義（RequestVisibility.php）と、スコープからの呼び出し（ApprovalRequest.php）の 1 つずつだけ
-        $this->assertSame([
-            'app/Models/ApprovalRequest.php'             => 1,
-            'app/Support/Approval/RequestVisibility.php' => 1,
-        ], $callers);
+        return $callers;
     }
 }
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/5b/patches/0003-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/PaperVisibilityTest.php tests/Feature/Approval/Phase2/RequestVisibilityTest.php
```

Expected: `ERRORS!` `Tests: 22, Assertions: 1411, Errors: 2, Failures: 1.`

- `PaperVisibilityTest::test_who_can_see_a_paper_record` — `BadMethodCallException: Call to undefined method Illuminate\Database\Eloquent\Builder::visibleTo()`
- `PaperVisibilityTest::test_an_or_before_the_scope_does_not_leak` — `BadMethodCallException: Call to undefined method Illuminate\Database\Eloquent\Builder::visibleTo()`
- `RequestVisibilityTest::test_only_the_model_scope_calls_the_rule_body` — `Failed asserting that two arrays are identical.`

- [ ] **Step 3: 紙の決裁の規則を足す**

`app/Support/Approval/RequestVisibility.php`（変更）

```diff
--- a/app/Support/Approval/RequestVisibility.php
+++ b/app/Support/Approval/RequestVisibility.php
@@ -5,6 +5,7 @@
 use App\Enums\ApprovalStatus;
 use App\Enums\ApprovalStepKind;
 use App\Models\ApprovalDepartment;
+use App\Models\ApprovalPaperRecord;
 use App\Models\ApprovalRequest;
 use App\Models\User;
 use Illuminate\Database\Eloquent\Builder;
@@ -19,6 +20,8 @@
  *
  * ⚠ 他人の下書きは誰も見られない（全件閲覧者・決裁の管理者も）。
  * ⚠ 部門長・審査担当者・社長は「今の」担当で判定する。担当を外れた人は、自分が判断した申請だけ見られる。
+ * ⚠ 取り込んだ紙の決裁（approval_paper_records）の見られる範囲も**ここ**に書く（`constrainPaper`。段階5 設計書 §5.12）。
+ *   台帳・Excel・紙の決裁の詳細・関連する決裁No の候補とリンクがすべてそれを使う。
  */
 final class RequestVisibility
 {
@@ -126,6 +129,39 @@ private static function othersRules(Builder $q, User $user): void
         });
     }
 
+    /** 紙の決裁を見られるか（1 件の判定も一覧と同じ規則から作る） */
+    public static function canViewPaper(User $user, ApprovalPaperRecord $record): bool
+    {
+        return ApprovalPaperRecord::query()->whereKey($record->getKey())->visibleTo($user)->exists();
+    }
+
+    /**
+     * 紙の決裁の見られる範囲（要件 7 章・段階5 設計書 §5.12）。`ApprovalPaperRecord::scopeVisibleTo()` だけが呼ぶ
+     * （スコープを通すと、呼ぶ側が先に書いた最上位の OR を Laravel が括弧に入れる。申請の規則の本体と同じ理由）。
+     *
+     * その部門に所属する人・その部門の今の部門長・全件閲覧者・決裁の管理者。紙の時代の申請者は利用者と結び付けないので
+     * 「自分の申請」は無い（自部門の過去分としてなら見られる）。社長は全件閲覧者に指定されていれば見られる（社長だけでは見られない）。
+     * ⚠ 審査担当者は、その部門に所属していなければ見られない（紙の決裁は審査部門に届いていない）
+     */
+    public static function constrainPaper(Builder $query, User $user): void
+    {
+        // 保存していない利用者（id が空）には何も見せない（申請と同じ。分からないときは見せない）
+        if ($user->getKey() === null) {
+            $query->whereRaw('1 = 0');
+
+            return;
+        }
+
+        // 全件閲覧者・決裁の管理者はすべて
+        if ($user->canViewAllApprovals() || $user->isApprovalAdmin()) {
+            return;
+        }
+
+        $query->where(fn (Builder $q) => $q
+            ->whereIn('approval_paper_records.department_id', DB::table('approval_department_user')->select('department_id')->where('user_id', $user->id))
+            ->orWhereIn('approval_paper_records.department_id', ApprovalDepartment::query()->select('id')->where('head_user_id', $user->id)));
+    }
+
     private static function stepsOfThisRequest(QueryBuilder $s): QueryBuilder
     {
         return $s->selectRaw('1')->from('approval_steps')->whereColumn('approval_steps.request_id', 'approval_requests.id');
```

`app/Models/ApprovalPaperRecord.php`（変更）

```diff
--- a/app/Models/ApprovalPaperRecord.php
+++ b/app/Models/ApprovalPaperRecord.php
@@ -3,6 +3,8 @@
 namespace App\Models;
 
 use App\Support\Approval\AmountTable;
+use App\Support\Approval\RequestVisibility;
+use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Database\Eloquent\Model;
 use Illuminate\Database\Eloquent\Relations\BelongsTo;
 
@@ -13,6 +15,7 @@
  *   （D10。台帳・Excel・関連する決裁No・紙の決裁の詳細だけが読む）。
  * ⚠ 決裁No（`number`）は年度・部門・ＮＯ から組み立てる（`ApprovalNumber::paperNumber()`）。紙の番号どうし・システムの申請の番号と
  *   重ならない（取り込みと 1 件ずつの登録が確かめる）。
+ * ⚠ 見られる範囲は RequestVisibility の 1 か所（`visibleTo()`）。
  * ⚠ 決裁日（`decided_on`）は日付（時刻なし）。表示は `->format('Y/m/d')`（JapanTime::format で時差を足さない）。
  * ⚠ `updated_by` は**手で直した人**（取り込みで金額を補っても変えない）。取り込みの取り消しの「あとで手で直した記録」の数に使う。
  */
@@ -62,6 +65,15 @@ public function editor(): BelongsTo
         return $this->belongsTo(User::class, 'updated_by')->withTrashed();
     }
 
+    /**
+     * 見られる紙の決裁に絞る（要件 7 章・段階5 設計書 §5.12）。規則は RequestVisibility の紙の決裁の規則の 1 か所で、ここはその入口。
+     * ⚠ ローカルスコープなので、呼ぶ側が先に書いた最上位の OR を Laravel が括弧に入れる（見られる範囲の外へ漏れない）
+     */
+    public function scopeVisibleTo(Builder $query, User $user): void
+    {
+        RequestVisibility::constrainPaper($query, $user);
+    }
+
     /** 粗利率（金額と粗利益から。申請の明細表と同じ計算＝整数で四捨五入。金額が空か 0・粗利益が空なら空） */
     public function rate(): ?float
     {
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/5b/patches/0003-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 と、申請の見られる範囲のテスト）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/PaperVisibilityTest.php tests/Feature/Approval/Phase2
```

Expected: `OK`（赤なし）

- [ ] **Step 5: 全件を流す**

Expected: `OK (4077 tests, 38390 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/RequestVisibility.php app/Models/ApprovalPaperRecord.php tests/Feature/Approval/Phase5/PaperVisibilityTest.php tests/Feature/Approval/Phase2/RequestVisibilityTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 紙の決裁の見られる範囲を申請の規則と同じ所に書く

紙の決裁を見られるのは、その部門に所属する人・その部門の部門長・
全件閲覧者・決裁の管理者（要件 7 章・段階5 設計書 §5.12）。規則は
RequestVisibility の 1 か所に置き、紙のモデルの visibleTo() から呼ぶ。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 4: 紙の決裁台帳の読み取りと取り込みの中身

年度ごとの台帳（`.xls`・`.xlsx`）を読み（`PaperLedgerFile`。DB に触らない）、部門・日付（`PaperDate`）・番号・すでにある記録・金額の控えの結び付け・次の番号を確かめた中身を作る（`PaperImportAnalysis`。確認の画面と確定が同じものを作る）。テストは同じ形の作りものの台帳で行う（設計書 §5.9・D6・D7・D20・D24・§0.3・§0.4）。

**Files:**
- Create: `app/Support/Approval/PaperDate.php`・`app/Support/Approval/PaperLedgerFile.php`・`app/Support/Approval/PaperImportAnalysis.php`・`app/Support/Approval/PaperImportException.php`
- Modify: `app/Support/Approval/ApprovalFiscalYear.php`
- Test: Create `tests/Concerns/BuildsPaperLedgers.php`・`tests/Feature/Approval/Phase5/PaperImportAnalysisTest.php`・`tests/Unit/Approval/PaperDateTest.php`

**Interfaces:**
- Consumes: Task 2 の `ApprovalNumber::paperNumber()`・`ApprovalPaperRecord`。既存の `ApprovalNumber::currentState()`・`FormInput::trim()`・`FormInput::digits()`
- Produces: `ApprovalFiscalYear::eraYearOf(CarbonInterface $japanDate): int`・`PaperDate::read(mixed $raw, int $fiscalYear, int $startMonth): array{date: ?CarbonImmutable, error: ?string}`・`PaperDate::key(mixed $raw): ?string`
- Produces: `PaperLedgerFile::read(string $path): array{sheets, register, amounts}`（`REGISTER`・`AMOUNTS`・`IGNORED`・`MAX_ROWS`〈拾う行 3,000〉・`MAX_SHEET_ROWS`〈読む前に見る全シートの行 6,000〉）・`PaperLedgerFile::codeOf(string $title): ?string`・`PaperLedgerFile::isNumber(mixed): bool`
- Produces: `PaperImportAnalysis::analyze(string $path, int $fiscalYear, array $links = []): array{sheets, records, existing, rowErrors, warnings, amounts, linkErrors, nextNumbers, addCount, fillCount}`（形は docblock。`amounts` の各行に `empty`＝金額がどれも空で結び付けない行）・`NO_LINK = 'none'`・`SUBJECT_MAX`・`APPLICANT_MAX`・`SEQ_MAX`・`AMOUNT_MAX`。Task 5 の画面と `PaperImporter` が使う
- Produces: `PaperImportException`（メッセージはそのまま画面に出せる文）
- Produces: `BuildsPaperLedgers::paperLedger(array $sheets, array $cells = [], string $format = 'Xls', int $pages = 2): string`・`paperCellOf(int $no): array{0: int, 1: string}`

**差分の大きさ:** 8 ファイル・+1363 / −0 行（差分のファイル `0004-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Concerns/BuildsPaperLedgers.php`（新規）

```php
<?php

namespace Tests\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 紙の決裁台帳と同じ形の小さな Excel を作る（段階5 設計書 D24。**本物の台帳はリポジトリに入れない**＝実在の人の名字と案件が入っている）。
 *
 * 08 年度の台帳を手元で読んで写した形（計画 §0 の「実物の台帳で分かったこと」）:
 * - 受付簿: 34 行の塊が縦に繰り返す（1 行目 題名・2 行目 部門名と「ＮＯ．1」・4 行目 見出し・5〜34 行目 30 行）。左段 B〜E に 1〜30、
 *   右段 G〜J に 31〜60、次の塊は左 61〜90・右 91〜120。**ＮＯ は使っていない行にも印字済み**。日付は文字の「8.05.13」
 * - 金額の控え: 1 段（B=ＮＯ・C=日付・D=申請者・E=件名・F=契約金額・G=工事原価・H=粗利益〈式 =+F5-G5〉・I=粗利（累計）〈式〉）
 * ⚠ 人の名前と件名は作りもの（甲野・乙山・見本邸など）
 */
trait BuildsPaperLedgers
{
    /**
     * @param array<string, list<array{0: int, 1: mixed, 2: ?string, 3: ?string}>|array{amounts: list<array{0: int, 1: mixed, 2: string, 3: string, 4: mixed, 5: mixed}>}> $sheets
     *        受付簿: シートの名前 => [[ＮＯ, 日付, 申請者, 件名], ...]（ＮＯ の位置の段に書く）／
     *        金額の控え: シートの名前（「金額」を含む）=> ['amounts' => [[ＮＯ, 日付, 申請者, 件名, 契約金額, 工事原価], ...]]（粗利益は式）
     * @param array<string, array<string, mixed>> $cells 最後に上書きするセル（シートの名前 => [座標 => 値]）。ＮＯ の形の誤りなどを作る
     * @param string $format Xls（台帳と同じ古い形式）か Xlsx
     * @return string ファイルのパス（テストの終わりに消す）
     */
    protected function paperLedger(array $sheets, array $cells = [], string $format = 'Xls', int $pages = 2): string
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);

        foreach ($sheets as $title => $rows) {
            $sheet = $book->createSheet();
            $sheet->setTitle($title);
            if (isset($rows['amounts'])) {
                $this->writeAmountSheet($sheet, $title, $rows['amounts']);
            } else {
                $this->writeRegisterSheet($sheet, $title, $rows, $pages);
            }
            foreach ($cells[$title] ?? [] as $coordinate => $value) {
                is_string($value)
                    ? $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING)
                    : $sheet->setCellValue($coordinate, $value);
            }
        }

        $base = tempnam(sys_get_temp_dir(), 'paper');
        $path = $base . '.' . strtolower($format);
        @unlink($base);
        IOFactory::createWriter($book, $format)->save($path);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    /** 受付簿の段の場所（ＮＯ → [行, 左か右]） */
    protected function paperCellOf(int $no): array
    {
        $page  = intdiv($no - 1, 60);
        $index = ($no - 1) % 60;

        return [1 + $page * 34 + 4 + $index % 30, $index < 30 ? '左' : '右'];
    }

    private function writeRegisterSheet(Worksheet $sheet, string $title, array $rows, int $pages): void
    {
        $maxNo = max([0, ...array_map(fn (array $r) => is_int($r[0]) ? $r[0] : 0, $rows)]);
        $pages = max($pages, intdiv(max($maxNo, 1) - 1, 60) + 1);

        for ($page = 0; $page < $pages; $page++) {
            $top = 1 + $page * 34;
            $this->paperText($sheet, "B{$top}", '　決　裁　申　請　書　');
            $this->paperText($sheet, 'B' . ($top + 1), $title . '事業部');
            $this->paperText($sheet, 'J' . ($top + 1), 'ＮＯ．' . ($page + 1));
            foreach (['B' => 'ＮＯ', 'C' => '日　　付', 'D' => '申請者', 'E' => '件　　　名', 'G' => 'ＮＯ', 'H' => '日　　付', 'I' => '申請者', 'J' => '件　　　名'] as $column => $label) {
                $this->paperText($sheet, $column . ($top + 3), $label);
            }
            for ($i = 0; $i < 30; $i++) {
                $sheet->setCellValue('B' . ($top + 4 + $i), $page * 60 + $i + 1);
                $sheet->setCellValue('G' . ($top + 4 + $i), $page * 60 + 30 + $i + 1);
            }
        }

        foreach ($rows as [$no, $date, $applicant, $subject]) {
            [$line, $side] = $this->paperCellOf($no);
            [, $dateColumn, $applicantColumn, $subjectColumn] = $side === '左' ? ['B', 'C', 'D', 'E'] : ['G', 'H', 'I', 'J'];
            is_string($date) ? $this->paperText($sheet, $dateColumn . $line, $date) : $sheet->setCellValue($dateColumn . $line, $date);
            $this->paperText($sheet, $applicantColumn . $line, $applicant);
            $this->paperText($sheet, $subjectColumn . $line, $subject);
        }
    }

    private function writeAmountSheet(Worksheet $sheet, string $title, array $rows): void
    {
        $this->paperText($sheet, 'B1', '住宅・' . $title . '一覧');
        foreach (['B' => 'ＮＯ', 'C' => '日　　付', 'D' => '申請者', 'E' => '件　　　名', 'F' => '契約金額', 'G' => '工事原価', 'H' => '粗利益', 'I' => '粗利（累計）', 'J' => '入金予定日'] as $column => $label) {
            $this->paperText($sheet, "{$column}4", $label);
        }

        // 行の数より多く ＮＯ と式を入れておく（使っていない行にも式がある＝「門田契約 金額」の形）
        for ($i = 0; $i < max(count($rows), 3) + 2; $i++) {
            $line = 5 + $i;
            $sheet->setCellValue("B{$line}", $i + 1);
            $sheet->setCellValue("H{$line}", "=+F{$line}-G{$line}");
            $sheet->setCellValue("I{$line}", $line === 5 ? '=H5' : "=+I" . ($line - 1) . "+H{$line}");
        }
        foreach (array_values($rows) as $i => [$no, $date, $applicant, $subject, $amount, $cost]) {
            $line = 5 + $i;
            $sheet->setCellValue("B{$line}", $no);
            is_string($date) ? $this->paperText($sheet, "C{$line}", $date) : $sheet->setCellValue("C{$line}", $date);
            $this->paperText($sheet, "D{$line}", $applicant);
            $this->paperText($sheet, "E{$line}", $subject);
            is_string($amount) ? $this->paperText($sheet, "F{$line}", $amount) : $sheet->setCellValue("F{$line}", $amount);
            is_string($cost) ? $this->paperText($sheet, "G{$line}", $cost) : $sheet->setCellValue("G{$line}", $cost);
        }
    }

    private function paperText(Worksheet $sheet, string $coordinate, ?string $value): void
    {
        if ($value !== null) {
            $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
        }
    }
}
```

`tests/Unit/Approval/PaperDateTest.php`（新規）

```php
<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\PaperDate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 紙の台帳の日付の読み方（要件 11.1・段階5 設計書 §5.9）。年は月で決め、先頭の数は年度の和暦の年かその日の和暦の年と合うこと。
 * 1〜4 月を「9.01.15」（暦の年）と書く台帳も「8.01.15」（年度の年）と書く台帳も読める（08 年度の台帳にはまだ 1〜4 月が無く、どちらか分からない）。
 */
class PaperDateTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: int, 2: int, 3: string}> 台帳の日付・年度・期の始まりの月・読んだ日付 */
    public static function readable(): array
    {
        return [
            '5 月（期の始まり）'                 => ['8.05.07', 2026, 5, '2026-05-07'],
            '12 月'                             => ['8.12.28', 2026, 5, '2026-12-28'],
            '1 月を暦の年で'                     => ['9.01.15', 2026, 5, '2027-01-15'],
            '1 月を年度の年で'                   => ['8.01.15', 2026, 5, '2027-01-15'],
            '4 月 30 日（期の終わり）'           => ['9.04.30', 2026, 5, '2027-04-30'],
            '0 を付けない・全角・区切りのゆれ'    => ['８．５．７', 2026, 5, '2026-05-07'],
            'スラッシュ'                         => ['8/5/7', 2026, 5, '2026-05-07'],
            '前後の空白'                         => [' 8.05.07　', 2026, 5, '2026-05-07'],
            '平成の年度'                         => ['30.05.10', 2018, 5, '2018-05-10'],
            '平成の年度の 4 月を暦の年で'         => ['31.04.30', 2018, 5, '2019-04-30'],
            '平成の年度の 4 月を年度の年で'       => ['30.04.30', 2018, 5, '2019-04-30'],
            '令和元年度'                         => ['1.05.01', 2019, 5, '2019-05-01'],
            '令和元年度の 1 月を暦の年で'         => ['2.01.10', 2019, 5, '2020-01-10'],
            '6 月始まりの会社の平成の年度の 5 月（暦は令和）' => ['1.05.10', 2018, 6, '2019-05-10'],
            '6 月始まりの会社の平成の年度の 5 月（年度の年）' => ['30.05.10', 2018, 6, '2019-05-10'],
            'うるう日'                           => ['10.02.29', 2027, 5, '2028-02-29'],
            'Excel の日付の数'                   => [(float) Date::formattedPHPToExcel(2026, 6, 1), 2026, 5, '2026-06-01'],
        ];
    }

    #[DataProvider('readable')]
    public function test_a_date_is_read(mixed $raw, int $fiscalYear, int $startMonth, string $expected): void
    {
        $read = PaperDate::read($raw, $fiscalYear, $startMonth);

        $this->assertNull($read['error']);
        $this->assertSame($expected, $read['date']->format('Y-m-d'));
        $this->assertSame('Asia/Tokyo', $read['date']->getTimezone()->getName(), '日本の暦の日付');
        $this->assertSame('00:00:00', $read['date']->format('H:i:s'));
    }

    /** @return array<string, array{0: mixed, 1: int, 2: string}> 台帳の日付・年度・理由 */
    public static function unreadable(): array
    {
        return [
            '前の年度の書き残し'       => ['7.04.30', 2026, '日付「7.04.30」の年（7）が R8 年度と合いません'],
            '次の年度の日付'           => ['10.05.01', 2026, '日付「10.05.01」の年（10）が R8 年度と合いません'],
            'ない日'                   => ['8.02.30', 2026, '日付「8.02.30」が読めません（ない日付です）'],
            'ない月'                   => ['8.13.01', 2026, '日付「8.13.01」が読めません（ない日付です）'],
            '0 月'                     => ['8.00.10', 2026, '日付「8.00.10」が読めません（ない日付です）'],
            '形が違う'                 => ['8.0513', 2026, '日付「8.0513」が読めません（8.05.13 の形で書きます）'],
            '元号の文字つき'           => ['R8.05.13', 2026, '日付「R8.05.13」が読めません（8.05.13 の形で書きます）'],
            'うるう年でない 2/29'      => ['9.02.29', 2026, '日付「9.02.29」が読めません（ない日付です）'],
            '期間の外の Excel の日付'   => [(float) Date::formattedPHPToExcel(2026, 4, 30), 2026, '日付（2026/04/30）が R8 年度の期間の外です'],
            '日付でない数'             => [-5, 2026, '日付（-5）が読めません'],
        ];
    }

    #[DataProvider('unreadable')]
    public function test_a_wrong_date_is_not_read(mixed $raw, int $fiscalYear, string $error): void
    {
        $this->assertSame(['date' => null, 'error' => $error], PaperDate::read($raw, $fiscalYear, 5));
    }

    /** 結び付けの鍵は、0 の付け方・全角・区切りの違いを無視する（金額の控えと受付簿で書き方がゆれても結び付く） */
    public function test_the_key_ignores_how_the_date_is_written(): void
    {
        $this->assertSame('era:8.5.7', PaperDate::key('8.05.07'));
        $this->assertSame('era:8.5.7', PaperDate::key('８．５．７'));
        $this->assertSame('era:8.5.7', PaperDate::key('8/5/07'));
        $this->assertSame('day:2026-06-01', PaperDate::key((float) Date::formattedPHPToExcel(2026, 6, 1)));
        $this->assertNull(PaperDate::key('日　　付'));
        $this->assertNull(PaperDate::key(null));
        $this->assertNull(PaperDate::key(0));
    }
}
```

`tests/Feature/Approval/Phase5/PaperImportAnalysisTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalDepartment;
use App\Support\Approval\ApprovalNumber;
use App\Support\Approval\PaperImportAnalysis;
use App\Support\Approval\PaperImportException;
use App\Support\Approval\PaperLedgerFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\BuildsPaperLedgers;
use Tests\TestCase;

/**
 * 紙の決裁台帳の読み取りと、取り込みの中身（要件 11.1〜11.4・段階5 設計書 §5.9・D6・D7・D20）。
 *
 * ⚠ 台帳は作りもの（BuildsPaperLedgers。本物の台帳はリポジトリに入れない＝D24）。本物で気をつける点（2 段組み・見出しの繰り返し・
 *   ＮＯ だけの行・和暦のドット・.xls・金額の控えの式と結び付け）はどれも作りものに入れてある。
 */
class PaperImportAnalysisTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use BuildsPaperLedgers;

    private array $w;

    private ApprovalDepartment $small;

    private ApprovalDepartment $estate;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2027-04-20 03:00:00', 'UTC'));   // R8 年度の終わり近く（1〜4 月の日付も今日より前）
        $this->w      = $this->approvalWorld();                                 // 住宅事業部 J（5 月始まり）
        $this->small  = $this->approvalDepartment($this->w['company'], ['name' => '住宅（少額・追加工事）', 'code' => 'JB']);
        $this->estate = $this->approvalDepartment($this->w['company'], ['name' => 'ミツワ不動産', 'code' => 'M']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 左右 2 段・2 つ目の塊・ＮＯ だけの行・見出しの繰り返しを読み、.xls でも .xlsx でも同じ中身になる */
    public function test_the_two_sided_register_is_read(): void
    {
        $sheets = [
            'Ｊ・住宅' => [
                [1, '8.05.07', '甲野', '見本町　用地購入'],
                [2, '8.05.18', '乙山', '見本邸新築工事契約'],
                [30, '8.06.30', '丙川', '左の段の最後'],
                [31, '8.07.01', '丁田', '右の段の最初'],
                [61, '8.08.03', '甲野', '2 つ目の塊の左'],
                [120, '9.01.15', '乙山', '2 つ目の塊の右の最後'],
            ],
            'メモ' => [],
        ];

        foreach (['Xls', 'Xlsx'] as $format) {
            $a = PaperImportAnalysis::analyze($this->paperLedger($sheets, [], $format), 2026);

            $this->assertSame([], $a['rowErrors'], $format);
            $this->assertSame(['R8-J-001', 'R8-J-002', 'R8-J-030', 'R8-J-031', 'R8-J-061', 'R8-J-120'], array_column($a['records'], 'number'), "{$format}: シートの順・ＮＯ の順に並べる");
            $first = $a['records'][0];
            $this->assertSame([$this->w['dept']->id, '住宅事業部', 2026, 1, '2026-05-07', '甲野', '見本町　用地購入', null, null, null, 'Ｊ・住宅 5 行目・左（ＮＯ 1）'], [
                $first['department_id'], $first['department'], $first['fiscal_year'], $first['seq'], $first['decided_on'], $first['applicant_name'], $first['subject'],
                $first['amount'], $first['cost'], $first['profit'], $first['where'],
            ]);
            $this->assertSame('2027-01-15', $a['records'][5]['decided_on'], '1 月は年度の次の年');
            $this->assertSame('Ｊ・住宅 68 行目・右（ＮＯ 120）', $a['records'][5]['where']);
            $this->assertSame(6, $a['addCount']);

            // シートの件数（読まないシートも並べる）
            $this->assertSame([
                ['title' => 'Ｊ・住宅', 'kind' => PaperLedgerFile::REGISTER, 'code' => 'J', 'department' => '住宅事業部', 'rows' => 6, 'added' => 6, 'existing' => 0, 'errors' => 0, 'linked' => 0],
                ['title' => 'メモ', 'kind' => PaperLedgerFile::IGNORED, 'code' => null, 'department' => null, 'rows' => 0, 'added' => 0, 'existing' => 0, 'errors' => 0, 'linked' => 0],
            ], $a['sheets']);
        }
    }

    /** エラーの行は取り込まない・ほかの行は取り込む（D20）。どの行がなぜかを出す */
    public function test_rows_with_errors_are_left_out_and_the_rest_is_kept(): void
    {
        $this->paperRecord($this->w['dept'], ['seq' => 90, 'fiscal_year' => 2025]);   // 前の年度の同じＮＯ（R7-J-090）は関わらない
        $draft = $this->draftFor($this->w);
        DB::table('approval_requests')->where('id', $draft->id)->update(['number' => 'R8-J-009', 'number_department_id' => $this->w['dept']->id]);

        $path = $this->paperLedger([
            'Ｊ・住宅' => [
                [1, '8.05.07', '甲野', '取り込む行'],
                [2, '8.02.30', '甲野', 'ない日付'],
                [3, '7.04.30', '甲野', '前の年度の書き残し'],
                [4, null, '甲野', '日付が無い'],
                [5, '8.05.10', '甲野', null],
                [6, '8.05.10', '甲野', str_repeat('長', 101)],
                [7, '8.05.10', str_repeat('名', 51), '申請者が長い'],
                [8, '8.05.10', null, '申請者は空でもよい'],
                [9, '8.05.11', '甲野', 'システムの番号と同じ'],
                [90, '8.06.01', '甲野', '前の年度と同じＮＯ'],
            ],
            'ＣＢ・賃貸' => [[1, '8.05.20', '乙山', '部門の無いシート'], [2, '8.05.21', '乙山', '部門の無いシート 2']],
            'ＨＢ・本部' => [],
        ], [
            // ＮＯ の誤り・空のＮＯ・同じＮＯ が 2 つ
            'Ｊ・住宅' => ['B15' => 'ＸＸ', 'C15' => '8.05.12', 'D15' => '甲野', 'E15' => 'ＮＯ が文字', 'B16' => null, 'C16' => '8.05.12', 'D16' => '甲野', 'E16' => 'ＮＯ が空',
                'B17' => 1, 'C17' => '8.05.13', 'D17' => '乙山', 'E17' => 'ＮＯ 1 がもう 1 つ'],
        ]);

        $a = PaperImportAnalysis::analyze($path, 2026);

        $this->assertSame(['R8-J-008', 'R8-J-090'], array_column($a['records'], 'number'));
        $this->assertNull($a['records'][0]['applicant_name']);
        $this->assertSame([
            ['where' => 'Ｊ・住宅 6 行目・左（ＮＯ 2）', 'message' => '日付「8.02.30」が読めません（ない日付です）'],
            ['where' => 'Ｊ・住宅 7 行目・左（ＮＯ 3）', 'message' => '日付「7.04.30」の年（7）が R8 年度と合いません'],
            ['where' => 'Ｊ・住宅 8 行目・左（ＮＯ 4）', 'message' => '日付が空です（件名などが書いてあるのに日付が無い行は取り込みません）'],
            ['where' => 'Ｊ・住宅 9 行目・左（ＮＯ 5）', 'message' => '件名が空です'],
            ['where' => 'Ｊ・住宅 10 行目・左（ＮＯ 6）', 'message' => '件名が 100 文字を超えています'],
            ['where' => 'Ｊ・住宅 11 行目・左（ＮＯ 7）', 'message' => '申請者が 50 文字を超えています'],
            ['where' => 'Ｊ・住宅 15 行目・左（ＮＯ ＸＸ）', 'message' => 'ＮＯ「ＸＸ」が 1〜99,999 の数ではありません'],
            ['where' => 'Ｊ・住宅 16 行目・左', 'message' => 'ＮＯ が空です'],
            ['where' => 'ＣＢ・賃貸', 'message' => '部門のアルファベット CB の部門が部門の管理にありません（2 件を取り込みません。部門を登録してから取り込み直してください）'],
            ['where' => 'Ｊ・住宅 5 行目・左（ＮＯ 1）', 'message' => '同じファイルの中に R8-J-001 がもう 1 つあります（Ｊ・住宅 17 行目・左（ＮＯ 1））'],
            ['where' => 'Ｊ・住宅 17 行目・左（ＮＯ 1）', 'message' => '同じファイルの中に R8-J-001 がもう 1 つあります（Ｊ・住宅 5 行目・左（ＮＯ 1））'],
            ['where' => 'Ｊ・住宅 13 行目・左（ＮＯ 9）', 'message' => 'R8-J-009 はシステムの申請がすでに使っています'],
        ], $a['rowErrors']);
        $this->assertSame(['added' => 2, 'errors' => 11], array_intersect_key($a['sheets'][0], ['added' => 0, 'errors' => 0]));
        $this->assertSame([null, 2, 2], [$a['sheets'][1]['department'], $a['sheets'][1]['rows'], $a['sheets'][1]['errors']], '部門の無いシートの行は数えて「取り込まない」に入れるが、エラーの文は 1 つにまとめる');
        $this->assertSame(0, $a['sheets'][2]['rows'], '部門の無い空のシートは何も出さない');
    }

    /** 金額の控えは、すべての受付簿の行に日付と件名（空白・全角半角を無視）で結び付く。1 つに決まらない行は選んでもらう */
    public function test_amounts_are_linked_by_the_date_and_the_subject(): void
    {
        $path = $this->paperLedger([
            'ＪＢ・住宅' => [[1, '8.05.13', '甲野', '平井⑤　見本邸少額工事契約'], [2, '8.05.22', '乙山', '道後　見本邸少額工事契約']],
            'Ｊ・住宅' => [[2, '8.05.18', '丙川', '見本町土地建物契約'], [3, '8.05.26', '丁田', '同じ日の同じ件名'], [4, '8.05.26', '丁田', '同じ日の同じ件名'], [5, '8.06.30', '甲野', 'どの控えにも当たらない']],
            'Ｍ・ミツワ不動産' => [[2, '8.05.18', '戊井', '見本ビル売買契約']],
            '住宅少額・金額' => ['amounts' => [
                [1, '8.05.13', '甲野', '平井⑤ 見本邸少額工事契約', 1200000, 850000],      // 空白のゆれ
                [2, '８．５．２２', '乙山', '道後見本邸少額工事契約', 980000.0, '700,000'],   // 全角・0 の付け方・小数の形の整数・文字の金額
            ]],
            '土地・建物契約・金額' => ['amounts' => [
                [1, '8.05.18', '丙川', '見本町土地建物契約', 30000000, 27000000],          // Ｊ に当たる
                [2, '8.05.18', '戊井', '見本ビル売買契約', 50000000, 45500000],            // Ｍ に当たる（08 年度の控えと同じく Ｊ 以外にも）
                [3, '8.05.26', '丁田', '同じ日の同じ件名', 1000, 900],                     // 2 行に当たる → 選ぶ
                [4, '8.07.07', '己野', 'どこにも当たらない', 2000, 1000],                  // 当たらない → 選ぶ
                [5, '8.05.18', '庚原', '金額が文字', 'ABC', 100],                          // 金額の誤り → 入れない
                [6, '8.05.18', '庚原', '13 桁', 1000000000000, 0],                        // 12 桁を超える → 入れない
            ]],
            '門田契約 金額' => ['amounts' => []],                                           // 式だけの計算の跡
        ]);

        $a      = PaperImportAnalysis::analyze($path, 2026);
        $byKey  = collect($a['amounts'])->keyBy('where');
        $record = collect($a['records'])->keyBy('number');

        $this->assertSame([], $a['linkErrors']);
        $this->assertSame(['R8-JB-001', true], [$byKey['住宅少額・金額 5 行目（ＮＯ 1）']['linked'], $byKey['住宅少額・金額 5 行目（ＮＯ 1）']['auto']]);
        $this->assertSame([1200000, 850000, 350000], [$record['R8-JB-001']['amount'], $record['R8-JB-001']['cost'], $record['R8-JB-001']['profit']], '粗利益は式（=+F-G）を計算した値');
        $this->assertSame([980000, 700000, 280000], [$record['R8-JB-002']['amount'], $record['R8-JB-002']['cost'], $record['R8-JB-002']['profit']]);
        $this->assertSame([30000000, 50000000], [$record['R8-J-002']['amount'], $record['R8-M-002']['amount']]);
        $this->assertSame(4500000, $record['R8-M-002']['profit']);

        // 2 行に当たる行・当たらない行は結び付けない（選択肢は同じ日付の記録が先）
        $twin = $byKey['土地・建物契約・金額 7 行目（ＮＯ 3）'];
        $this->assertSame([null, false], [$twin['linked'], $twin['auto']]);
        $this->assertSame(['R8-J-003', 'R8-J-004'], array_slice(array_column($twin['candidates'], 'number'), 0, 2));
        $this->assertSame('R8-J-003　2026/05/26　丁田　同じ日の同じ件名（同じ日付）', $twin['candidates'][0]['label']);
        $this->assertCount(7, $twin['candidates'], '取り込める記録すべてを選べる');
        $this->assertSame([null, false], [$byKey['土地・建物契約・金額 8 行目（ＮＯ 4）']['linked'], $byKey['土地・建物契約・金額 8 行目（ＮＯ 4）']['auto']]);
        $this->assertNull($record['R8-J-003']['amount']);
        $this->assertNull($record['R8-J-005']['amount']);

        // 金額の誤りの行は出さずにエラー（その行の金額は入れない）
        $this->assertFalse($byKey->has('土地・建物契約・金額 9 行目（ＮＯ 5）'));
        $this->assertContains(['where' => '土地・建物契約・金額 9 行目（ＮＯ 5）', 'message' => '金額の控えの契約金額「ABC」が 12 桁までの数ではありません（この行の金額は入れません）'], $a['rowErrors']);
        $this->assertContains(['where' => '土地・建物契約・金額 10 行目（ＮＯ 6）', 'message' => '金額の控えの契約金額「1000000000000」が 12 桁までの数ではありません（この行の金額は入れません）'], $a['rowErrors']);
        $this->assertSame(0, collect($a['sheets'])->firstWhere('title', '門田契約 金額')['rows'], '式だけの行は読まない');
        $this->assertSame(['rows' => 6, 'linked' => 2], array_intersect_key(collect($a['sheets'])->firstWhere('title', '土地・建物契約・金額'), ['rows' => 0, 'linked' => 0]));

        // 選んだ結び付け（確定のフォームで送る形）。「結び付けない」も選べる
        $chosen = PaperImportAnalysis::analyze($path, 2026, [
            $twin['key'] => 'R8-J-004',
            $byKey['土地・建物契約・金額 8 行目（ＮＯ 4）']['key'] => 'R8-J-005',
            $byKey['住宅少額・金額 5 行目（ＮＯ 1）']['key'] => PaperImportAnalysis::NO_LINK,
        ]);
        $record = collect($chosen['records'])->keyBy('number');
        $this->assertSame([], $chosen['linkErrors']);
        $this->assertSame([null, 1000, 2000, null], [$record['R8-J-003']['amount'], $record['R8-J-004']['amount'], $record['R8-J-005']['amount'], $record['R8-JB-001']['amount']]);
        $this->assertFalse(collect($chosen['amounts'])->firstWhere('key', $twin['key'])['auto'], '選んだ行は自動ではない');
    }

    /**
     * 金額を書く前の控えの行（契約金額・工事原価・粗利益がどれも空）は結び付けない。工事原価が空の行は、式の粗利益（空のセルを 0 として
     * 計算した値）を入れない（点検 I-2。結び付けると「金額を補った」と数え、あとで本当の金額を補えなくなる）
     */
    public function test_amount_rows_without_amounts_are_not_linked(): void
    {
        $a = PaperImportAnalysis::analyze($this->paperLedger([
            'Ｊ・住宅' => [[1, '8.05.07', '甲野', '金額をまだ書いていない契約'], [2, '8.05.08', '乙山', '工事原価がまだの契約']],
            '土地・建物契約・金額' => ['amounts' => [
                [1, '8.05.07', '甲野', '金額をまだ書いていない契約', null, null],
                [2, '8.05.08', '乙山', '工事原価がまだの契約', 30000000, null],
            ]],
        ]), 2026);

        $empty = collect($a['amounts'])->firstWhere('where', '土地・建物契約・金額 5 行目（ＮＯ 1）');
        $this->assertSame([null, false, true, [], null], [$empty['linked'], $empty['auto'], $empty['empty'], $empty['candidates'], $empty['profit']], '粗利益の式の 0 を読まない');
        $records = collect($a['records'])->keyBy('number');
        $this->assertSame([null, null, null], [$records['R8-J-001']['amount'], $records['R8-J-001']['cost'], $records['R8-J-001']['profit']]);
        $this->assertSame([30000000, null, null], [$records['R8-J-002']['amount'], $records['R8-J-002']['cost'], $records['R8-J-002']['profit']], '工事原価が空なら粗利益（＝契約金額）を入れない');
        $this->assertSame(0, $a['fillCount']);

        // すでにある金額の空の記録にも、金額の無い行では「補う」としない
        $this->paperRecord($this->w['dept'], ['seq' => 1, 'decided_on' => '2026-05-07', 'applicant_name' => '甲野', 'subject' => '金額をまだ書いていない契約']);
        $again = PaperImportAnalysis::analyze($this->paperLedger([
            'Ｊ・住宅' => [[1, '8.05.07', '甲野', '金額をまだ書いていない契約']],
            '土地・建物契約・金額' => ['amounts' => [[1, '8.05.07', '甲野', '金額をまだ書いていない契約', null, null]]],
        ]), 2026);
        $this->assertSame([0, 0, null], [$again['addCount'], $again['fillCount'], $again['existing'][0]['fill']]);
    }

    /** 2 つの控えが同じ行に当たれば自動で結び付けない・選んだ先が同じ・取り込めない先は、確定できない誤りにする */
    public function test_two_amounts_for_one_record_are_not_linked(): void
    {
        $path = $this->paperLedger([
            'Ｊ・住宅' => [[1, '8.05.07', '甲野', '見本邸新築工事契約'], [2, '8.05.08', '乙山', '別の契約']],
            '土地・建物契約・金額' => ['amounts' => [
                [1, '8.05.07', '甲野', '見本邸新築工事契約', 1000, 500],
                [2, '8.5.7', '甲野', '見本邸 新築工事契約', 2000, 500],
            ]],
        ]);

        $a = PaperImportAnalysis::analyze($path, 2026);
        $this->assertSame([[null, false], [null, false]], array_map(fn (array $m) => [$m['linked'], $m['auto']], $a['amounts']));
        $this->assertNull($a['records'][0]['amount']);

        [$first, $second] = array_column($a['amounts'], 'key');
        $same = PaperImportAnalysis::analyze($path, 2026, [$first => 'R8-J-001', $second => 'R8-J-001']);
        $this->assertSame(['金額の控えの 土地・建物契約・金額 5 行目（ＮＯ 1）・土地・建物契約・金額 6 行目（ＮＯ 2） が同じ R8-J-001 に結び付いています。どちらかを「結び付けない」にしてください'], $same['linkErrors']);
        $this->assertNull($same['records'][0]['amount'], '同じ先に 2 つの金額を入れない');

        $gone = PaperImportAnalysis::analyze($path, 2026, [$first => 'R8-J-777', $second => PaperImportAnalysis::NO_LINK]);
        $this->assertSame(['土地・建物契約・金額 5 行目（ＮＯ 1） の結び付け先 R8-J-777 は、このファイルで取り込める記録にありません。選び直してください'], $gone['linkErrors']);
    }

    /** すでにある番号は中身を変えない（D6）。違えば注意・金額がすべて空の記録にだけ控えから補う */
    public function test_existing_numbers_are_kept_and_only_empty_amounts_are_filled(): void
    {
        $same    = $this->paperRecord($this->w['dept'], ['seq' => 1, 'decided_on' => '2026-05-07', 'applicant_name' => '甲野', 'subject' => '見本邸新築工事契約']);
        $changed = $this->paperRecord($this->w['dept'], ['seq' => 2, 'decided_on' => '2026-05-08', 'applicant_name' => '乙山', 'subject' => '手で直した件名']);
        $priced  = $this->paperRecord($this->w['dept'], ['seq' => 3, 'decided_on' => '2026-05-09', 'applicant_name' => '丙川', 'subject' => '金額のある契約', 'cost' => 5]);

        $a = PaperImportAnalysis::analyze($this->paperLedger([
            'Ｊ・住宅' => [[1, '8.05.07', '甲野', '見本邸新築工事契約'], [2, '8.05.08', '乙山', 'Excel の件名'], [3, '8.05.09', '丙川', '金額のある契約'], [4, '8.05.10', '丁田', '新しい契約']],
            '土地・建物契約・金額' => ['amounts' => [[1, '8.05.07', '甲野', '見本邸新築工事契約', 1000, 400], [2, '8.05.09', '丙川', '金額のある契約', 3000, 1000]]],
        ]), 2026);

        $this->assertSame(['R8-J-004'], array_column($a['records'], 'number'));
        $this->assertSame([
            ['number' => 'R8-J-001', 'id' => $same->id, 'where' => 'Ｊ・住宅 5 行目・左（ＮＯ 1）', 'fill' => ['amount' => 1000, 'cost' => 400, 'profit' => 600]],
            ['number' => 'R8-J-002', 'id' => $changed->id, 'where' => 'Ｊ・住宅 6 行目・左（ＮＯ 2）', 'fill' => null],
            ['number' => 'R8-J-003', 'id' => $priced->id, 'where' => 'Ｊ・住宅 7 行目・左（ＮＯ 3）', 'fill' => null],
        ], $a['existing']);
        $this->assertSame([1, 1], [$a['addCount'], $a['fillCount']]);
        $this->assertSame([
            ['where' => 'Ｊ・住宅 6 行目・左（ＮＯ 2）', 'message' => 'R8-J-002 はすでにあります。Excel と件名が違いますが、変えません（直すなら 1 件ずつ直してください）'],
            ['where' => 'Ｊ・住宅 7 行目・左（ＮＯ 3）', 'message' => 'R8-J-003 は金額がすでにあるので、金額の控えの金額を入れません'],
        ], $a['warnings']);
        $this->assertSame(['added' => 1, 'existing' => 3], array_intersect_key($a['sheets'][0], ['added' => 0, 'existing' => 0]));
    }

    /** 次の番号（D7）: 今年度の部門は、足すＮＯ のいちばん大きいもの＋1 まで進む（今の番号より小さければそのまま）。過去の年度は関わらない */
    public function test_the_next_numbers_of_this_year(): void
    {
        ApprovalNumber::setNext($this->estate->fresh('company'), 40);
        $sheets = [
            'Ｊ・住宅' => [[1, '8.05.07', '甲野', 'ア'], [34, '8.08.27', '乙山', 'イ']],
            'Ｍ・ミツワ不動産' => [[9, '8.07.01', '丙川', 'ウ']],
        ];

        $a = PaperImportAnalysis::analyze($this->paperLedger($sheets), 2026);
        $this->assertSame([
            ['department' => '住宅事業部', 'before' => 'R8-J-001', 'after' => 'R8-J-035'],
            ['department' => 'ミツワ不動産', 'before' => 'R8-M-040', 'after' => 'R8-M-040'],
        ], $a['nextNumbers']);

        $past = PaperImportAnalysis::analyze($this->paperLedger(['Ｊ・住宅' => [[1, '7.05.07', '甲野', 'ア']]]), 2025);
        $this->assertSame(['R7-J-001'], array_column($past['records'], 'number'));
        $this->assertSame([], $past['nextNumbers'], '過去の年度の番号は採番に関わらない');
    }

    public function test_a_file_that_is_not_a_ledger_is_refused(): void
    {
        $text = tempnam(sys_get_temp_dir(), 'paper');
        file_put_contents($text, "ＮＯ,日付,申請者,件名\n1,8.05.07,甲野,見本\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($text));

        try {
            PaperImportAnalysis::analyze($text, 2026);
            $this->fail('CSV を読んだ');
        } catch (PaperImportException $e) {
            $this->assertSame('Excel のファイル（.xls・.xlsx）として読めませんでした。台帳のファイルを選び直してください。', $e->getMessage());
        }
    }

    /** 大きなファイルは読む前に断る（読み込んでから数えると、本番の 128M を使い切って捕まえられない 500 になる。点検 I-3） */
    public function test_a_huge_sheet_is_refused_before_it_is_read(): void
    {
        $path = $this->paperLedger(['メモ' => []], ['メモ' => ['A' . (PaperLedgerFile::MAX_SHEET_ROWS + 1) => '最後の行']], 'Xlsx');

        try {
            PaperImportAnalysis::analyze($path, 2026);
            $this->fail('大きなファイルを読んだ');
        } catch (PaperImportException $e) {
            $this->assertSame('台帳の行が多すぎます（すべてのシートで 6,000 行まで）。年度ごとの台帳のファイルか確かめてください。', $e->getMessage());
        }
    }

    /** 今日より先の決裁日は取り込まない（年度の和暦と暦の和暦の取り違え。点検 M-2） */
    public function test_a_date_after_today_is_an_error(): void
    {
        $a = PaperImportAnalysis::analyze($this->paperLedger(['Ｊ・住宅' => [[1, '9.04.20', '甲野', '今日'], [2, '9.04.21', '乙山', '明日']]]), 2026);

        $this->assertSame(['R8-J-001'], array_column($a['records'], 'number'));
        $this->assertSame([['where' => 'Ｊ・住宅 6 行目・左（ＮＯ 2）', 'message' => '日付「9.04.21」が今日より先の日付（2027/04/21）になります']], $a['rowErrors']);
    }

    /** 行が多すぎるファイルは読まない（年度ごとの台帳でないものを取り込まない） */
    public function test_too_many_rows_are_refused(): void
    {
        $rows = [];
        for ($no = 1; $no <= PaperLedgerFile::MAX_ROWS + 1; $no++) {
            $rows[] = [$no, '8.05.07', '甲野', "契約{$no}"];
        }

        try {
            PaperImportAnalysis::analyze($this->paperLedger(['Ｊ・住宅' => $rows], [], 'Xlsx'), 2026);
            $this->fail('3,001 行を読んだ');
        } catch (PaperImportException $e) {
            $this->assertSame('1 回に読めるのは 3,000 行までです。年度ごとのファイルか確かめてください。', $e->getMessage());
        }
    }
}
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/5b/patches/0004-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Unit/Approval/PaperDateTest.php tests/Feature/Approval/Phase5/PaperImportAnalysisTest.php
```

Expected: `ERRORS!` `Tests: 39, Assertions: 0, Errors: 39.`

- `PaperImportAnalysisTest::test_the_two_sided_register_is_read` — `Error: Class "App\Support\Approval\PaperImportAnalysis" not found`
- `PaperImportAnalysisTest::test_rows_with_errors_are_left_out_and_the_rest_is_kept` — `Error: Class "App\Support\Approval\PaperImportAnalysis" not found`
- `PaperImportAnalysisTest::test_amounts_are_linked_by_the_date_and_the_subject` — `Error: Class "App\Support\Approval\PaperImportAnalysis" not found`
- `PaperImportAnalysisTest::test_amount_rows_without_amounts_are_not_linked` — `Error: Class "App\Support\Approval\PaperImportAnalysis" not found`
- `PaperImportAnalysisTest::test_two_amounts_for_one_record_are_not_linked` — `Error: Class "App\Support\Approval\PaperImportAnalysis" not found`
- `PaperImportAnalysisTest::test_existing_numbers_are_kept_and_only_empty_amounts_are_filled` — `Error: Class "App\Support\Approval\PaperImportAnalysis" not found`
- `PaperImportAnalysisTest::test_the_next_numbers_of_this_year` — `Error: Class "App\Support\Approval\PaperImportAnalysis" not found`
- `PaperImportAnalysisTest::test_a_file_that_is_not_a_ledger_is_refused` — `Error: Class "App\Support\Approval\PaperImportAnalysis" not found`
- `PaperImportAnalysisTest::test_a_huge_sheet_is_refused_before_it_is_read` — `Error: Class "App\Support\Approval\PaperLedgerFile" not found`
- `PaperImportAnalysisTest::test_a_date_after_today_is_an_error` — `Error: Class "App\Support\Approval\PaperImportAnalysis" not found`
- `PaperImportAnalysisTest::test_too_many_rows_are_refused` — `Error: Class "App\Support\Approval\PaperLedgerFile" not found`
- `PaperDateTest::test_a_date_is_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_date_is_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_date_is_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_date_is_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_date_is_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_date_is_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_date_is_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_date_is_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_date_is_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_date_is_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_date_is_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_date_is_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_date_is_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_date_is_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_date_is_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_date_is_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_date_is_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_wrong_date_is_not_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_wrong_date_is_not_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_wrong_date_is_not_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_wrong_date_is_not_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_wrong_date_is_not_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_wrong_date_is_not_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_wrong_date_is_not_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_wrong_date_is_not_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_wrong_date_is_not_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_a_wrong_date_is_not_read` — `Error: Class "App\Support\Approval\PaperDate" not found`
- `PaperDateTest::test_the_key_ignores_how_the_date_is_written` — `Error: Class "App\Support\Approval\PaperDate" not found`

- [ ] **Step 3: 日付の読み方を足す**

`app/Support/Approval/ApprovalFiscalYear.php`（変更）

```diff
--- a/app/Support/Approval/ApprovalFiscalYear.php
+++ b/app/Support/Approval/ApprovalFiscalYear.php
@@ -41,6 +41,12 @@ public static function current(int $startMonth): int
         return self::of(JapanTime::today(), $startMonth);
     }
 
+    /** その日の和暦の年（令和 8 年なら 8・平成 31 年なら 31。紙の台帳の日付「9.01.15」の先頭の数と比べる。段階5 の過去分の取り込み） */
+    public static function eraYearOf(CarbonInterface $japanDate): int
+    {
+        return $japanDate->format('Y-m-d') >= self::REIWA_START ? $japanDate->year - 2018 : $japanDate->year - 1988;
+    }
+
     /** 和暦の略号（R8・H30）。期の始まりの日が属する元号・年で決める（要件 6.1） */
     public static function eraLabel(int $fiscalYear, int $startMonth): string
     {
```

`app/Support/Approval/PaperDate.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Support\JapanTime;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * 紙の台帳の日付の読み方（要件 11.1・段階5 設計書 §5.9）。受付簿と金額の控えの「日付」の列。
 *
 * 台帳の日付は和暦のドットの文字（`8.05.07`＝令和 8 年 5 月 7 日）。年は**月で決める**（期の始まりの月より前の月は、年度の次の年）。
 * 先頭の数は、年度の和暦の年（`8.01.15`）か、その日の和暦の年（`9.01.15`）の**どちらかと合うこと**（1〜4 月をどちらで書く台帳でも読め、
 * 違う年度の日付〈前の年度の書き残し `7.04.30` など〉を黙って入れない。合わなければエラーにして取り込まない）。
 *
 * ⚠ Excel の日付の数のセル（書式を読まないので数として来る）は、その日がその年度の期間の中にあるときだけ読む（先頭の数が無いので）
 */
final class PaperDate
{
    /**
     * @return array{date: ?CarbonImmutable, error: ?string} 日本の暦の日付（時刻なし）か、読めない理由
     */
    public static function read(mixed $raw, int $fiscalYear, int $startMonth): array
    {
        $era = ApprovalFiscalYear::eraLabel($fiscalYear, $startMonth);

        if (is_int($raw) || is_float($raw)) {
            $day = self::serialDay($raw);
            if ($day === null) {
                return ['date' => null, 'error' => "日付（{$raw}）が読めません"];
            }

            return ApprovalFiscalYear::of($day, $startMonth) === $fiscalYear
                ? ['date' => $day, 'error' => null]
                : ['date' => null, 'error' => "日付（{$day->format('Y/m/d')}）が {$era} 年度の期間の外です"];
        }

        $text = FormInput::trim(mb_convert_kana((string) $raw, 'as'));
        if (! preg_match('/\A(\d{1,2})[.\/\-](\d{1,2})[.\/\-](\d{1,2})\z/', $text, $m)) {
            return ['date' => null, 'error' => "日付「{$raw}」が読めません（8.05.13 の形で書きます）"];
        }
        [, $eraYear, $month, $day] = array_map('intval', $m);

        $year = $month >= $startMonth ? $fiscalYear : $fiscalYear + 1;
        if ($month < 1 || $month > 12 || ! checkdate($month, $day, $year)) {
            return ['date' => null, 'error' => "日付「{$raw}」が読めません（ない日付です）"];
        }

        $date = CarbonImmutable::create($year, $month, $day, 0, 0, 0, JapanTime::ZONE);
        if ($eraYear !== (int) substr($era, 1) && $eraYear !== ApprovalFiscalYear::eraYearOf($date)) {
            return ['date' => null, 'error' => "日付「{$raw}」の年（{$eraYear}）が {$era} 年度と合いません"];
        }

        return ['date' => $date, 'error' => null];
    }

    /**
     * 結び付けに使う日付の鍵（金額の控えと受付簿の行を比べる）。文字の日付は数の並び（`8.5.13`。0 の付け方・全角・区切りの違いを無視）、
     * 数の日付はその日。読めなければ null
     */
    public static function key(mixed $raw): ?string
    {
        if (is_int($raw) || is_float($raw)) {
            $day = self::serialDay($raw);

            return $day === null ? null : 'day:' . $day->format('Y-m-d');
        }

        $text = FormInput::trim(mb_convert_kana((string) $raw, 'as'));

        return preg_match('/\A(\d{1,2})[.\/\-](\d{1,2})[.\/\-](\d{1,2})\z/', $text, $m)
            ? sprintf('era:%d.%d.%d', $m[1], $m[2], $m[3])
            : null;
    }

    /** Excel の日付の数（1900 年の 1 月 1 日が 1）のその日。日付の範囲の外は null */
    private static function serialDay(int|float $serial): ?CarbonImmutable
    {
        if ($serial < 1 || $serial >= 2958466) {   // 9999/12/31 の次
            return null;
        }
        $day = Date::excelToDateTimeObject($serial);

        return CarbonImmutable::create((int) $day->format('Y'), (int) $day->format('m'), (int) $day->format('d'), 0, 0, 0, JapanTime::ZONE);
    }
}
```

- [ ] **Step 4: 台帳のファイルを読む部品を足す**

`app/Support/Approval/PaperImportException.php`（新規）

```php
<?php

namespace App\Support\Approval;

use RuntimeException;

/**
 * 紙の決裁台帳を取り込めないとき（ファイルとして読めない・行が多すぎる。段階5 設計書 §5.9）。
 * メッセージはそのまま画面に出せる文にする（取り込みの画面の赤帯）。行ごとの誤りはこれにせず、確認の画面のエラーの行に出す（D20）
 */
final class PaperImportException extends RuntimeException
{
}
```

`app/Support/Approval/PaperLedgerFile.php`（新規）

```php
<?php

namespace App\Support\Approval;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * 紙の決裁台帳の Excel（年度ごとの .xls・.xlsx）を読む（要件 11.1・段階5 設計書 §5.9）。**DB には触らない**
 * （部門・番号との突き合わせと結び付けは PaperImportAnalysis）。
 *
 * - シートの名前の先頭の英字（全角は半角に）が部門のアルファベットの受付簿（「Ｊ・住宅」→ J・「ＪＢ・住宅」→ JB）。名前に「金額」を
 *   含むシートは金額の控え。どちらでもないシートは読まない
 * - 受付簿は 1 枚の中が左右 2 段（左 B〜E・右 G〜J。どちらも ＮＯ・日付・申請者・件名）。題名と見出しの塊が 34 行ごとに繰り返し、
 *   ＮＯ は使っていない行にも印字済み → ＮＯ が数か日付が日付の形で、日付・申請者・件名のどれかが入っている段だけを拾う
 *   （ＮＯ だけの段は使っていない行・題名と見出しの行は ＮＯ も日付も数でない）。拾った段に日付が無いなどの誤りは PaperImportAnalysis がエラーにする
 * - 金額の控えは 1 段（B=ＮＯ・C=日付・D=申請者・E=件名・F=契約金額・G=工事原価・H=粗利益）。日付・申請者・件名がそろう行だけを拾う
 *   （「門田契約 金額」のような、式だけが残った計算の跡は読まない）
 * ⚠ 式のセルは計算した値を読む（粗利益は `=+F5-G5`）。計算できなければ Excel が保存していた値。**粗利益の式は、契約金額と工事原価が
 *   どちらも書いてあるときだけ使う**（空のセルは 0 として計算され、金額をまだ書いていない行が「粗利益 0」や「粗利率 100%」になるため）
 * ⚠ 書式は読まない（readDataOnly）。日付のセルが Excel の日付の数で来ても PaperDate が読む
 * ⚠ **読む前に大きさを見る**（listWorksheetInfo はセルを作らずに行の数を返す。読み込んでから数えると、大きなファイルで本番の 128M を
 *   使い切り、捕まえられない 500 になる）。読むのは A〜J 列だけ（幅の広いシートのセルを作らない）
 */
final class PaperLedgerFile
{
    /** 受付簿の 2 段（段の名前 => ＮＯ・日付・申請者・件名の列） */
    public const SIDES = ['左' => ['B', 'C', 'D', 'E'], '右' => ['G', 'H', 'I', 'J']];

    /** 金額の控えの列 */
    private const AMOUNT_COLUMNS = ['no' => 'B', 'date' => 'C', 'applicant' => 'D', 'subject' => 'E', 'amount' => 'F', 'cost' => 'G', 'profit' => 'H'];

    /** 金額の控えのシートの名前の目印 */
    private const AMOUNT_MARK = '金額';

    /** 1 回に読む行の上限（受付簿の段と金額の控えの行を合わせて。08 年度は 224。年度ごとのファイルでないものを取り込まない） */
    public const MAX_ROWS = 3000;

    /**
     * 読む前に見る、すべてのシートの行の数の上限（使っていない行も数える。08 年度の台帳は 15 枚で 2,000 行ほど）。
     * 2026-10-13 の点検の実測で、10 列 15,000 行の .xlsx を読むと 116MB を使った（本番の上限は 128M）
     */
    public const MAX_SHEET_ROWS = 6000;

    /** 読む列（受付簿の 2 段と金額の控え。ほかの列のセルは作らない） */
    private const READ_COLUMNS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];

    /** シートの種類 */
    public const REGISTER = 'register';

    public const AMOUNTS = 'amounts';

    public const IGNORED = 'ignored';

    /**
     * @return array{
     *     sheets: list<array{index: int, title: string, kind: string, code: ?string}>,
     *     register: list<array{sheet: int, title: string, code: string, line: int, side: string, no: mixed, date: mixed, applicant: ?string, subject: ?string}>,
     *     amounts: list<array{sheet: int, title: string, line: int, no: mixed, date: mixed, applicant: string, subject: string, amount: mixed, cost: mixed, profit: mixed}>,
     * }
     *
     * @throws PaperImportException Excel として読めない・行が多すぎる
     */
    public static function read(string $path): array
    {
        try {
            $reader = IOFactory::createReader(IOFactory::identify($path, [IOFactory::READER_XLS, IOFactory::READER_XLSX]));
            $reader->setReadDataOnly(true);
            $rows = array_sum(array_column($reader->listWorksheetInfo($path), 'totalRows'));
        } catch (Throwable) {
            throw new PaperImportException('Excel のファイル（.xls・.xlsx）として読めませんでした。台帳のファイルを選び直してください。');
        }
        if ($rows > self::MAX_SHEET_ROWS) {
            throw new PaperImportException('台帳の行が多すぎます（すべてのシートで ' . number_format(self::MAX_SHEET_ROWS) . ' 行まで）。年度ごとの台帳のファイルか確かめてください。');
        }

        try {
            $reader->setReadFilter(new class (self::READ_COLUMNS) implements IReadFilter {
                /** @param list<string> $columns */
                public function __construct(private readonly array $columns)
                {
                }

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return in_array($columnAddress, $this->columns, true);
                }
            });
            $book = $reader->load($path);
        } catch (Throwable) {
            throw new PaperImportException('Excel のファイル（.xls・.xlsx）として読めませんでした。台帳のファイルを選び直してください。');
        }

        $sheets = $register = $amounts = [];

        foreach ($book->getAllSheets() as $index => $sheet) {
            $title = $sheet->getTitle();
            $kind  = self::kindOf($title);
            $code  = $kind === self::REGISTER ? self::codeOf($title) : null;
            $sheets[] = ['index' => $index, 'title' => $title, 'kind' => $kind, 'code' => $code];

            if ($kind === self::REGISTER) {
                foreach (self::registerRows($sheet, $index, $code) as $row) {
                    $register[] = $row;
                }
            } elseif ($kind === self::AMOUNTS) {
                foreach (self::amountRows($sheet, $index) as $row) {
                    $amounts[] = $row;
                }
            }

            if (count($register) + count($amounts) > self::MAX_ROWS) {
                $book->disconnectWorksheets();

                throw new PaperImportException('1 回に読めるのは ' . number_format(self::MAX_ROWS) . ' 行までです。年度ごとのファイルか確かめてください。');
            }
        }

        $book->disconnectWorksheets();

        return ['sheets' => $sheets, 'register' => $register, 'amounts' => $amounts];
    }

    /** シートの種類（名前に「金額」→ 金額の控え・先頭が英字 → 受付簿・ほかは読まない） */
    private static function kindOf(string $title): string
    {
        if (str_contains($title, self::AMOUNT_MARK)) {
            return self::AMOUNTS;
        }

        return self::codeOf($title) === null ? self::IGNORED : self::REGISTER;
    }

    /** シートの名前の先頭の英字 1〜3 文字（全角は半角・大文字に。4 文字以上続けば部門の記号ではない） */
    public static function codeOf(string $title): ?string
    {
        $text = mb_strtoupper(mb_convert_kana(FormInput::trim($title), 'a'), 'UTF-8');

        return preg_match('/\A([A-Z]{1,3})(?![A-Z])/', $text, $m) ? $m[1] : null;
    }

    /** @return iterable<array{sheet: int, title: string, code: string, line: int, side: string, no: mixed, date: mixed, applicant: ?string, subject: ?string}> */
    private static function registerRows(Worksheet $sheet, int $index, string $code): iterable
    {
        $last = $sheet->getHighestDataRow();

        for ($line = 1; $line <= $last; $line++) {
            foreach (self::SIDES as $side => [$noColumn, $dateColumn, $applicantColumn, $subjectColumn]) {
                $no        = self::value($sheet, $noColumn . $line);
                $date      = self::value($sheet, $dateColumn . $line);
                $applicant = self::value($sheet, $applicantColumn . $line);
                $subject   = self::value($sheet, $subjectColumn . $line);

                if ($date === null && $applicant === null && $subject === null) {
                    continue;   // 使っていない行（ＮＯ だけ）・空の行
                }
                if (! self::isNumber($no) && ! self::isDate($date)) {
                    continue;   // 題名・部門名・見出し（ＮＯ・日付の列に文字）の行
                }

                yield [
                    'sheet' => $index, 'title' => $sheet->getTitle(), 'code' => $code, 'line' => $line, 'side' => $side,
                    'no' => $no, 'date' => $date, 'applicant' => self::text($applicant), 'subject' => self::text($subject),
                ];
            }
        }
    }

    /** @return iterable<array{sheet: int, title: string, line: int, no: mixed, date: mixed, applicant: string, subject: string, amount: mixed, cost: mixed, profit: mixed}> */
    private static function amountRows(Worksheet $sheet, int $index): iterable
    {
        $last = $sheet->getHighestDataRow();

        for ($line = 1; $line <= $last; $line++) {
            $cells = [];
            foreach (self::AMOUNT_COLUMNS as $key => $column) {
                $cells[$key] = self::value($sheet, $column . $line);
            }

            // 日付・申請者・件名がそろう行だけ（計算の跡の行を読まない）。見出しの行（ＮＯ・日付の列に文字）も読まない
            if ($cells['date'] === null || $cells['applicant'] === null || $cells['subject'] === null) {
                continue;
            }
            if (! self::isNumber($cells['no']) && ! self::isDate($cells['date'])) {
                continue;
            }

            // 粗利益の式は、契約金額と工事原価がそろうときだけ使う（空のセルを 0 として計算した値を入れない）
            $profitCell = self::AMOUNT_COLUMNS['profit'] . $line;
            if (($cells['amount'] === null || $cells['cost'] === null) && $sheet->cellExists($profitCell) && $sheet->getCell($profitCell)->isFormula()) {
                $cells['profit'] = null;
            }

            yield ['sheet' => $index, 'title' => $sheet->getTitle(), 'line' => $line] + array_merge($cells, [
                'applicant' => (string) self::text($cells['applicant']),
                'subject'   => (string) self::text($cells['subject']),
            ]);
        }
    }

    /** セルの値（空と空白だけの文字は null・文字は前後の空白を落とす・式は計算した値・装飾つきの文字は文字だけ） */
    private static function value(Worksheet $sheet, string $coordinate): mixed
    {
        if (! $sheet->cellExists($coordinate)) {
            return null;
        }
        $cell = $sheet->getCell($coordinate);

        try {
            $value = $cell->isFormula() ? $cell->getCalculatedValue() : $cell->getValue();
        } catch (Throwable) {
            $value = $cell->getOldCalculatedValue();
        }
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if (is_string($value)) {
            $value = FormInput::trim($value);

            return $value === '' ? null : $value;
        }

        return is_int($value) || is_float($value) ? $value : null;
    }

    /** 文字にした値（数のセルの件名なども文字で持つ） */
    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /** ＮＯ の形（数のセル・数字だけの文字。全角も） */
    public static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && preg_match('/\A[0-9]+\z/', mb_convert_kana($value, 'n')) === 1);
    }

    /** 日付の形（`8.05.13` の文字か Excel の日付の数） */
    private static function isDate(mixed $value): bool
    {
        return PaperDate::key($value) !== null;
    }
}
```

- [ ] **Step 5: 取り込みの中身を作る部品を足す**

`app/Support/Approval/PaperImportAnalysis.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Models\ApprovalDepartment;
use App\Models\ApprovalPaperRecord;
use App\Models\ApprovalRequest;
use App\Support\JapanTime;
use Illuminate\Support\Collection;

/**
 * 紙の決裁台帳の取り込みの中身（要件 11.1〜11.4・段階5 設計書 §5.9・D6・D7・D20）。確認の画面と確定が**同じもの**を作る
 * （確定ではファイルを読み直してもう一度作る。画面が送ってきた hidden を信用しない）。
 *
 * - 受付簿の段ごとに、部門（シートの記号 → 部門の管理の部門）・ＮＯ・日付（PaperDate）・件名・申請者を確かめ、決裁No を組み立てる
 * - **エラーの行は取り込まない**（ほかの行は取り込む。D20）: 部門の管理に無い部門（シートごとにまとめて 1 つ）・ＮＯ が無いか数でない・
 *   日付が無い・読めない・年度と合わない・今日より先・件名が無い・長すぎる・システムの申請がすでに同じ番号を持つ・同じファイルの中で同じ番号が 2 回
 * - **すでにある番号は中身を変えない**（D6。手で直したものを消さない）。Excel と中身が違えば注意を出す（直すなら 1 件ずつ）。
 *   金額（金額・工事原価・粗利益）がすべて空の記録にだけ、金額の控えから補う
 * - 金額の控えの行は、正しく読めた受付簿の行（**すべてのシート**。08 年度の「土地・建物契約・金額」は Ｊ と Ｍ の両方に当たる）に、
 *   日付と件名（空白・全角半角の違いを無視）で結び付ける。1 つに決まらない行（当たる行が 0・2 つ以上・2 つの控えが同じ行に当たる）は
 *   結び付けずに、確認の画面で選んでもらう（`$links`。確定でも同じ形で確かめ直す）。契約金額・工事原価・粗利益がどれも空の控えの行は
 *   結び付けない（金額を書く前の行。結び付けると「金額を補った」と数え、あとで本当の金額を補えなくなる）
 * - 次の番号（D7）: 今年度の部門は、足すＮＯ のいちばん大きいもの＋1 まで進む（戻さない。進めるのは PaperImporter）。確認の画面に出す
 */
final class PaperImportAnalysis
{
    /** 件名・申請者の長さの上限（申請の件名と同じ 100 文字・申請者は台帳の姓の文字） */
    public const SUBJECT_MAX = 100;

    public const APPLICANT_MAX = 50;

    /** ＮＯ の上限（関連する決裁No の形が 5 桁まで。RelatedNumbers::PATTERN） */
    public const SEQ_MAX = 99999;

    /** 金額の上限（申請の明細表と同じ 12 桁。マイナスも） */
    public const AMOUNT_MAX = 999_999_999_999;

    /** 結び付けの選択の「結び付けない」 */
    public const NO_LINK = 'none';

    /**
     * @param array<string, string> $links 金額の控えの行の鍵（`シートの番号:行`）=> 結び付ける決裁No か NO_LINK。送られていない行は自動で結び付ける
     * @return array{
     *     sheets: list<array{title: string, kind: string, code: ?string, department: ?string, rows: int, added: int, existing: int, errors: int, linked: int}>,
     *     records: list<array{number: string, department_id: int, department: string, fiscal_year: int, seq: int, decided_on: string, applicant_name: ?string, subject: string, amount: ?int, cost: ?int, profit: ?int, where: string}>,
     *     existing: list<array{number: string, id: int, where: string, fill: ?array{amount: ?int, cost: ?int, profit: ?int}}>,
     *     rowErrors: list<array{where: string, message: string}>,
     *     warnings: list<array{where: string, message: string}>,
     *     amounts: list<array{key: string, where: string, date: string, applicant: string, subject: string, amount: ?int, cost: ?int, profit: ?int, linked: ?string, auto: bool, empty: bool, candidates: list<array{number: string, label: string}>}>,
     *     linkErrors: list<string>,
     *     nextNumbers: list<array{department: string, before: string, after: string}>,
     *     addCount: int,
     *     fillCount: int,
     * }
     *
     * @throws PaperImportException ファイルとして読めない・行が多すぎる
     */
    public static function analyze(string $path, int $fiscalYear, array $links = []): array
    {
        $file        = PaperLedgerFile::read($path);
        $departments = ApprovalDepartment::with('company')->get()->keyBy('code');
        $rowErrors   = $warnings = [];

        // 1. 受付簿の段（部門の無いシートはまとめて 1 つのエラー）
        $valid    = [];
        $unknown  = [];
        foreach ($file['register'] as $row) {
            $department = $departments->get($row['code']);
            if ($department === null) {
                $unknown[$row['title']] = ($unknown[$row['title']] ?? 0) + 1;
                continue;
            }
            $checked = self::checkRow($row, $department, $fiscalYear);
            if (isset($checked['error'])) {
                $rowErrors[] = ['where' => self::where($row), 'message' => $checked['error'], 'sheet' => $row['sheet']];
                continue;
            }
            $valid[] = $checked;
        }
        foreach ($unknown as $title => $count) {
            $code        = PaperLedgerFile::codeOf($title);
            $rowErrors[] = ['where' => $title, 'message' => "部門のアルファベット {$code} の部門が部門の管理にありません（{$count} 件を取り込みません。部門を登録してから取り込み直してください）", 'sheet' => null];
        }

        // 2. 同じファイルの中の同じ番号（どちらも取り込まない）・システムの申請の番号
        $valid = self::dropTwins($valid, $rowErrors);
        $taken = ApprovalRequest::whereIn('number', array_column($valid, 'number'))->pluck('number')->flip();
        $valid = array_values(array_filter($valid, function (array $r) use ($taken, &$rowErrors): bool {
            if ($taken->has($r['number'])) {
                $rowErrors[] = ['where' => $r['where'], 'message' => "{$r['number']} はシステムの申請がすでに使っています", 'sheet' => $r['sheet']];

                return false;
            }

            return true;
        }));

        // 確認の画面で見やすいように、シートの順・ＮＯ の順に並べる（読む順は上の行から左右）
        usort($valid, fn (array $a, array $b) => [$a['sheet'], $a['seq']] <=> [$b['sheet'], $b['seq']]);

        // 3. すでにある番号（中身は変えない。違えば注意。D6）
        $existing = ApprovalPaperRecord::whereIn('number', array_column($valid, 'number'))->get()->keyBy('number');
        foreach ($valid as $r) {
            $old = $existing->get($r['number']);
            if ($old !== null && ($differs = self::differences($old, $r)) !== []) {
                $warnings[] = ['where' => $r['where'], 'message' => "{$r['number']} はすでにあります。Excel と" . implode('・', $differs) . 'が違いますが、変えません（直すなら 1 件ずつ直してください）'];
            }
        }

        // 4. 金額の控えの結び付け
        [$amounts, $linkErrors, $fills] = self::linkAmounts($file['amounts'], $valid, $links, $rowErrors);

        // 5. 足す記録・金額を補う記録
        $records = $fillsOfExisting = [];
        foreach ($valid as $r) {
            $fill = $fills[$r['number']] ?? null;
            $old  = $existing->get($r['number']);
            if ($old === null) {
                $records[] = array_merge(self::publicRecord($r), $fill ?? ['amount' => null, 'cost' => null, 'profit' => null]);
                continue;
            }
            if ($fill !== null && $old->amount === null && $old->cost === null && $old->profit === null) {
                $fillsOfExisting[] = ['number' => $r['number'], 'id' => $old->id, 'where' => $r['where'], 'fill' => $fill];
            } else {
                if ($fill !== null) {
                    $warnings[] = ['where' => $r['where'], 'message' => "{$r['number']} は金額がすでにあるので、金額の控えの金額を入れません"];
                }
                $fillsOfExisting[] = ['number' => $r['number'], 'id' => $old->id, 'where' => $r['where'], 'fill' => null];
            }
        }

        return [
            'sheets'      => self::sheetSummary($file, $departments, $valid, $existing, $amounts, $rowErrors),
            'records'     => $records,
            'existing'    => $fillsOfExisting,
            'rowErrors'   => array_map(fn (array $e) => ['where' => $e['where'], 'message' => $e['message']], $rowErrors),
            'warnings'    => $warnings,
            'amounts'     => $amounts,
            'linkErrors'  => $linkErrors,
            'nextNumbers' => self::nextNumbers($records, $departments, $fiscalYear),
            'addCount'    => count($records),
            'fillCount'   => count(array_filter($fillsOfExisting, fn (array $e) => $e['fill'] !== null)),
        ];
    }

    /**
     * 受付簿の 1 段を確かめる
     *
     * @return array{error: string}|array{number: string, department_id: int, department: string, fiscal_year: int, seq: int, decided_on: string, applicant_name: ?string, subject: string, where: string, sheet: int, dateKey: string, subjectKey: string}
     */
    private static function checkRow(array $row, ApprovalDepartment $department, int $fiscalYear): array
    {
        if ($row['no'] === null) {
            return ['error' => 'ＮＯ が空です'];
        }
        $seq = self::seqOf($row['no']);
        if ($seq === null) {
            return ['error' => "ＮＯ「{$row['no']}」が 1〜" . number_format(self::SEQ_MAX) . ' の数ではありません'];
        }
        if ($row['date'] === null) {
            return ['error' => '日付が空です（件名などが書いてあるのに日付が無い行は取り込みません）'];
        }
        $date = PaperDate::read($row['date'], $fiscalYear, $department->company->fiscal_start_month);
        if ($date['error'] !== null) {
            return ['error' => $date['error']];
        }
        // 今日より先の決裁日は紙の記録としてありえない（年度の和暦と暦の和暦の取り違え・まだ始まっていない会社の年度。日付で比べる）
        if ($date['date']->format('Y-m-d') > JapanTime::today()->format('Y-m-d')) {
            return ['error' => "日付「{$row['date']}」が今日より先の日付（{$date['date']->format('Y/m/d')}）になります"];
        }
        if ($row['subject'] === null) {
            return ['error' => '件名が空です'];
        }
        if (mb_strlen($row['subject']) > self::SUBJECT_MAX) {
            return ['error' => '件名が ' . self::SUBJECT_MAX . ' 文字を超えています'];
        }
        if ($row['applicant'] !== null && mb_strlen($row['applicant']) > self::APPLICANT_MAX) {
            return ['error' => '申請者が ' . self::APPLICANT_MAX . ' 文字を超えています'];
        }

        return [
            'number'         => ApprovalNumber::paperNumber($department, $fiscalYear, $seq),
            'department_id'  => $department->id,
            'department'     => $department->name,
            'fiscal_year'    => $fiscalYear,
            'seq'            => $seq,
            'decided_on'     => $date['date']->format('Y-m-d'),
            'applicant_name' => $row['applicant'],
            'subject'        => $row['subject'],
            'where'          => self::where($row),
            'sheet'          => $row['sheet'],
            'dateKey'        => (string) PaperDate::key($row['date']),
            'subjectKey'     => self::matchKey($row['subject']),
        ];
    }

    /** 同じ番号が 2 回以上ある行はどれも取り込まない（どちらが正しいか分からない） */
    private static function dropTwins(array $valid, array &$rowErrors): array
    {
        $groups = [];
        foreach ($valid as $r) {
            $groups[$r['number']][] = $r;
        }

        $out = [];
        foreach ($groups as $number => $rows) {
            if (count($rows) === 1) {
                $out[] = $rows[0];
                continue;
            }
            foreach ($rows as $r) {
                $others      = implode('・', array_map(fn (array $o) => $o['where'], array_filter($rows, fn (array $o) => $o['where'] !== $r['where'])));
                $rowErrors[] = ['where' => $r['where'], 'message' => "同じファイルの中に {$number} がもう 1 つあります（{$others}）", 'sheet' => $r['sheet']];
            }
        }

        return $out;
    }

    /** すでにある記録と Excel の違い（項目の名前） */
    private static function differences(ApprovalPaperRecord $old, array $r): array
    {
        $differs = [];
        if ($old->decided_on->format('Y-m-d') !== $r['decided_on']) {
            $differs[] = '決裁日';
        }
        if ($old->subject !== $r['subject']) {
            $differs[] = '件名';
        }
        if ((string) $old->applicant_name !== (string) $r['applicant_name']) {
            $differs[] = '申請者';
        }

        return $differs;
    }

    /**
     * 金額の控えの行を受付簿の行に結び付ける
     *
     * @param list<array> $rows   金額の控えの行（PaperLedgerFile）
     * @param list<array> $valid  取り込める受付簿の行（足すものと、すでにあるもの）
     * @param array<string, string> $links 画面で選んだ結び付け
     * @return array{0: list<array>, 1: list<string>, 2: array<string, array{amount: ?int, cost: ?int, profit: ?int}>} 画面に出す行・結び付けの誤り・決裁No ごとの金額
     */
    private static function linkAmounts(array $rows, array $valid, array $links, array &$rowErrors): array
    {
        $targets = [];
        foreach ($valid as $r) {
            $targets[$r['number']] = $r;
        }

        // 金額を確かめ、自動の結び付けの先を探す
        $usable = [];
        foreach ($rows as $row) {
            $where  = self::amountWhere($row);
            $values = [];
            foreach (['amount' => '契約金額', 'cost' => '工事原価', 'profit' => '粗利益'] as $key => $label) {
                $value = self::amountOf($row[$key]);
                if ($value === false) {
                    $rowErrors[] = ['where' => $where, 'message' => "金額の控えの{$label}「{$row[$key]}」が 12 桁までの数ではありません（この行の金額は入れません）", 'sheet' => $row['sheet']];
                    continue 2;
                }
                $values[$key] = $value;
            }
            $dateKey    = PaperDate::key($row['date']);
            $subjectKey = self::matchKey($row['subject']);
            // 金額がどれも空の行は結び付けない（当たる行を探さない＝ほかの控えの自動の結び付けも邪魔しない）
            $empty      = $values['amount'] === null && $values['cost'] === null && $values['profit'] === null;
            $matches    = $empty ? [] : array_keys(array_filter($targets, fn (array $t) => $t['dateKey'] === $dateKey && $t['subjectKey'] === $subjectKey));
            $usable[]   = ['key' => "{$row['sheet']}:{$row['line']}", 'where' => $where, 'row' => $row, 'values' => $values, 'matches' => $matches, 'dateKey' => $dateKey, 'empty' => $empty];
        }

        // 自動で結び付くのは、当たる行がちょうど 1 つで、ほかの控えがその行に当たっていないものだけ
        $claims = array_count_values(array_merge(...array_map(fn (array $u) => count($u['matches']) === 1 ? $u['matches'] : [], $usable) ?: [[]]));

        $out = $linkErrors = $fills = $chosen = [];
        foreach ($usable as $u) {
            $auto   = count($u['matches']) === 1 && $claims[$u['matches'][0]] === 1;
            $choice = $u['empty'] ? null : ($links[$u['key']] ?? null);
            $linked = null;
            if (is_string($choice)) {
                if ($choice === self::NO_LINK) {
                    $linked = null;
                } elseif (isset($targets[$choice])) {
                    $linked = $choice;
                } else {
                    $linkErrors[] = "{$u['where']} の結び付け先 {$choice} は、このファイルで取り込める記録にありません。選び直してください";
                }
                $auto = false;
            } elseif ($auto) {
                $linked = $u['matches'][0];
            }

            if ($linked !== null) {
                $chosen[$linked][] = $u['where'];
                $fills[$linked]    = $u['values'];
            }

            $out[] = [
                'key'        => $u['key'],
                'where'      => $u['where'],
                'date'       => (string) $u['row']['date'],
                'applicant'  => $u['row']['applicant'],
                'subject'    => $u['row']['subject'],
                'linked'     => $linked,
                'auto'       => $auto,
                'empty'      => $u['empty'],
                'candidates' => $auto || $u['empty'] ? [] : self::candidates($targets, $u['dateKey']),
            ] + $u['values'];
        }

        foreach ($chosen as $number => $wheres) {
            if (count($wheres) > 1) {
                $linkErrors[] = '金額の控えの ' . implode('・', $wheres) . " が同じ {$number} に結び付いています。どちらかを「結び付けない」にしてください";
                unset($fills[$number]);
            }
        }

        return [$out, $linkErrors, $fills];
    }

    /**
     * 結び付け先の選択肢（同じ日付の記録を先に。あとは決裁No の順）
     *
     * @return list<array{number: string, label: string}>
     */
    private static function candidates(array $targets, ?string $dateKey): array
    {
        $list = array_values($targets);
        usort($list, fn (array $a, array $b) => [$a['dateKey'] !== $dateKey, $a['sheet'], $a['seq']] <=> [$b['dateKey'] !== $dateKey, $b['sheet'], $b['seq']]);

        return array_map(fn (array $t) => [
            'number' => $t['number'],
            'label'  => "{$t['number']}　" . str_replace('-', '/', $t['decided_on']) . "　{$t['applicant_name']}　{$t['subject']}" . ($t['dateKey'] === $dateKey ? '（同じ日付）' : ''),
        ], $list);
    }

    /**
     * 次の番号（D7）。今年度の部門だけ・足すＮＯ のいちばん大きいもの＋1（今の次の番号より小さければ変えない）。
     * 過去の年度の紙の番号は採番に関わらない（§5.11）
     *
     * @return list<array{department: string, before: string, after: string}>
     */
    private static function nextNumbers(array $records, Collection $departments, int $fiscalYear): array
    {
        $max = [];
        foreach ($records as $r) {
            $max[$r['department_id']] = max($max[$r['department_id']] ?? 0, $r['seq']);
        }

        $out = [];
        foreach ($departments->sortBy(['company_id', 'sort_order', 'id']) as $department) {
            if (! isset($max[$department->id])) {
                continue;
            }
            $state = ApprovalNumber::currentState($department);
            if ($state['fiscal_year'] !== $fiscalYear) {
                continue;
            }
            $out[] = [
                'department' => $department->name,
                'before'     => ApprovalNumber::format($state['era'], $department->code, $state['next']),
                'after'      => ApprovalNumber::format($state['era'], $department->code, max($state['next'], $max[$department->id] + 1)),
            ];
        }

        return $out;
    }

    /**
     * シートごとの件数（受付簿は読んだ段・足す・すでにある・エラー。金額の控えは読んだ行・結び付いた行。読まないシートも並べる）
     *
     * @param Collection<string, ApprovalPaperRecord> $existing すでにある記録（決裁No => 記録）
     * @return list<array{title: string, kind: string, code: ?string, department: ?string, rows: int, added: int, existing: int, errors: int, linked: int}>
     */
    private static function sheetSummary(array $file, Collection $departments, array $valid, Collection $existing, array $amounts, array $rowErrors): array
    {
        $rows     = array_count_values(array_merge(array_column($file['register'], 'sheet'), array_column($file['amounts'], 'sheet')));
        $added    = array_count_values(array_column(array_filter($valid, fn (array $r) => ! $existing->has($r['number'])), 'sheet'));
        $kept     = array_count_values(array_column(array_filter($valid, fn (array $r) => $existing->has($r['number'])), 'sheet'));
        $errors   = array_count_values(array_column(array_filter($rowErrors, fn (array $e) => $e['sheet'] !== null), 'sheet'));
        $linked   = array_count_values(array_map(fn (array $a) => (int) explode(':', $a['key'])[0], array_filter($amounts, fn (array $a) => $a['linked'] !== null)));

        return array_map(fn (array $s) => [
            'title'      => $s['title'],
            'kind'       => $s['kind'],
            'code'       => $s['code'],
            'department' => $s['code'] === null ? null : $departments->get($s['code'])?->name,
            'rows'       => $rows[$s['index']] ?? 0,
            'added'      => $added[$s['index']] ?? 0,
            'existing'   => $kept[$s['index']] ?? 0,
            // 部門の管理に無い部門のシートは、読んだ段をすべて取り込まない（エラーはシートごとに 1 つにまとめて出す）
            'errors'     => $s['kind'] === PaperLedgerFile::REGISTER && ! $departments->has($s['code']) ? ($rows[$s['index']] ?? 0) : ($errors[$s['index']] ?? 0),
            'linked'     => $linked[$s['index']] ?? 0,
        ], $file['sheets']);
    }

    /** 画面に出す・保存する形（結び付けの鍵などを外す） */
    private static function publicRecord(array $r): array
    {
        return array_intersect_key($r, array_flip(['number', 'department_id', 'department', 'fiscal_year', 'seq', 'decided_on', 'applicant_name', 'subject', 'where']));
    }

    /** ＮＯ（1〜99999 の整数。数のセルは小数の無いものだけ） */
    private static function seqOf(mixed $value): ?int
    {
        if (is_float($value)) {
            $value = floor($value) === $value ? (int) $value : null;
        } elseif (is_string($value)) {
            $value = PaperLedgerFile::isNumber($value) ? (int) mb_convert_kana($value, 'n') : null;
        }

        return is_int($value) && $value >= 1 && $value <= self::SEQ_MAX ? $value : null;
    }

    /** 金額（空は null・整数で 12 桁まで・文字の数も読む。読めなければ false） */
    private static function amountOf(mixed $value): int|false|null
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value)) {
            $value = floor($value) === $value && abs($value) <= self::AMOUNT_MAX ? (int) $value : false;
        } elseif (is_string($value)) {
            $text  = FormInput::digits($value, FormInput::YEN);
            $value = is_string($text) && preg_match('/\A-?\d{1,12}\z/', $text) ? (int) $text : false;
        }

        return is_int($value) && abs($value) <= self::AMOUNT_MAX ? $value : false;
    }

    /** 結び付けで比べる件名（空白をすべて落とし、英数字・記号を半角に・半角カナを全角に） */
    private static function matchKey(string $subject): string
    {
        return preg_replace('/[\s\x{3000}]+/u', '', mb_convert_kana($subject, 'asKV')) ?? $subject;
    }

    /** 受付簿の段の場所（「Ｊ・住宅 5 行目・左（ＮＯ 1）」） */
    private static function where(array $row): string
    {
        return "{$row['title']} {$row['line']} 行目・{$row['side']}" . ($row['no'] === null ? '' : "（ＮＯ {$row['no']}）");
    }

    /** 金額の控えの行の場所（「土地・建物契約・金額 5 行目（ＮＯ 1）」） */
    private static function amountWhere(array $row): string
    {
        return "{$row['title']} {$row['line']} 行目" . ($row['no'] === null ? '' : "（ＮＯ {$row['no']}）");
    }
}
```

（差分のファイルを使うなら、Step 3〜5 をまとめて: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/5b/patches/0004-*.patch`）

- [ ] **Step 6: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Unit/Approval/PaperDateTest.php tests/Feature/Approval/Phase5/PaperImportAnalysisTest.php
```

Expected: `OK (39 tests, …)`

- [ ] **Step 7: 全件を流す**

Expected: `OK (4116 tests, 38543 assertions)`

- [ ] **Step 8: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/ApprovalFiscalYear.php app/Support/Approval/PaperDate.php app/Support/Approval/PaperImportException.php app/Support/Approval/PaperLedgerFile.php app/Support/Approval/PaperImportAnalysis.php tests/Concerns/BuildsPaperLedgers.php tests/Unit/Approval/PaperDateTest.php tests/Feature/Approval/Phase5/PaperImportAnalysisTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 紙の決裁台帳を読み取り取り込む中身を作る

年度ごとの台帳（左右 2 段の受付簿・和暦のドットの日付・金額の控え）を
読み、部門・日付・番号・すでにある記録・金額の控えの結び付け・次の番号を
確かめた中身を作る（段階5 設計書 §5.9・D6・D7・D20）。確認の画面と
確定が同じ部品を使う。テストは同じ形の作りものの台帳で行う（D24）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 5: ⑪ の取り込みの画面・確認・確定と次の番号・紙の決裁の一覧・メニュー

決裁の管理者が、年度ごとの台帳をアップロードして確認の画面で中身（シートごとの件数・取り込まない行・注意・金額の控えの結び付け・足す記録・すでにある番号・進む次の番号）を見てから確定できるようにする。確定はファイルを読み直して確かめ直し、1 つのトランザクションで保存し、今年度の部門の次の番号を進める（戻さない）。取り込んだ紙の決裁の一覧と取り込みの記録の画面・決裁の管理のメニューの入口を足す（設計書 §5.9・§5.10・§5.11・D6・D7・D11・D20・§0.5・§0.6）。

**Files:**
- Create: `app/Support/Approval/PaperImporter.php`・`app/Http/Controllers/Approval/PaperImportController.php`・`app/Http/Controllers/Approval/PaperRecordController.php`・`resources/views/approvals/admin/papers/index.blade.php`・`resources/views/approvals/admin/papers/import.blade.php`・`resources/views/approvals/admin/papers/_import_preview.blade.php`・`resources/views/approvals/admin/papers/_import_amounts.blade.php`
- Modify: `app/Support/Approval/ApprovalNumber.php`・`app/Models/ApprovalPaperRecord.php`・`app/Http/Controllers/Approval/OrganizationController.php`・`resources/views/approvals/admin/organization.blade.php`・`routes/approval.php`・`resources/views/layouts/partials/sidebar.blade.php`・`resources/views/layouts/partials/sidebar_approval.blade.php`・`resources/views/approvals/home.blade.php`・`resources/views/approvals/home-launched.blade.php`
- Test: Create `tests/Feature/Approval/Phase5/PaperImportScreenTest.php`・`tests/Feature/Approval/Phase5/PaperNumberTest.php`・`tests/Feature/Approval/Phase5/PaperListTest.php`／Modify `tests/Feature/Approval/Phase2/LaunchGateTest.php`・`tests/Feature/Approval/ApprovalSidebarTest.php`・`tests/Feature/Approval/Phase3/MailFailureBannerTest.php`・`tests/Feature/SubmitOnceTest.php`

**Interfaces:**
- Consumes: Task 4 の `PaperImportAnalysis::analyze()`・`PaperImportException`・`BuildsPaperLedgers`。Task 2 の `paperRecord()`。既存の `OneTimeAction::issue()`・`OneTimeAction::claimFrom()`・`SettingLogger::record()`・`PageNumbers::around()`・`_partials._submit_once`（`submitOnce()`）
- Produces: `ApprovalNumber::advancePast(ApprovalDepartment $department, int $fiscalYear, int $seq): ?int`・`ApprovalNumber::reservePaperNumbers(array<int, int> $maxSeqs, int $fiscalYear, list<string> $numbers): array{next: array<int, int>, taken: list<string>}`（部門の id => 足すいちばん大きいＮＯ。保存と同じトランザクションの中で保存の前に呼ぶ。Task 6 も使う）・`ApprovalNumber::lastPaperSeq(ApprovalDepartment $department, int $fiscalYear, bool $lock = false): int`・`setNext()` の紙の検査
- Produces: `PaperImporter::apply(array $analysis, int $fiscalYear, string $fileName, User $user): array{import: ApprovalPaperImport, next: list<string>}`（`next` は実際に進めた次の番号「住宅事業部 R8-J-035」。`PaperImportException`・`UniqueConstraintViolationException` を投げうる）
- Produces: `ApprovalPaperRecord::yearChoices(): array<int, string>`（年度 => 「R8 年度（2026）」。今の年度から平成元年度まで）・`ApprovalPaperRecord::OLDEST_YEAR`
- Produces: ルート `approvals.admin.papers.index`（GET `/approvals/admin/papers`）・`papers.import`（GET `…/papers/import`）・`papers.import.preview`・`papers.import.execute`（POST）。`PaperImportController::ALREADY_SENT`・`NOTHING_TO_IMPORT`。`PaperRecordController::PER_PAGE`
- Produces: 取り込みの画面の JS `paperLedgerYear(fileName, choices)`・`paperLedgerFileChosen(input)`

**差分の大きさ:** 23 ファイル・+1803 / −7 行（差分のファイル `0005-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase5/PaperImportScreenTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase5;

use App\Http\Controllers\Approval\PaperImportController;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalNumberSequence;
use App\Models\ApprovalPaperImport;
use App\Models\ApprovalPaperRecord;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use App\Support\Approval\ApprovalNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\BuildsPaperLedgers;
use Tests\Concerns\ChecksDoubleSubmit;
use Tests\Concerns\ComparesJsonColumns;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 紙の決裁台帳の取り込みの画面（画面⑪。段階5 設計書 §5.9・§5.10・D6・D7・D20）。アップロード → 確認 → 確定の往復。
 *
 * ⚠ 確定は、確認の画面が描いたフォームをブラウザと同じように分解して送り返す（送り先も中身も自前で組まない。Bug #47）。
 *   `links[シートの番号:行]` の名前は parse_str でブラウザと同じ形の配列にする（SubmitsScreenForms と同じ）
 * ⚠ 台帳は作りもの（BuildsPaperLedgers。本物の台帳はリポジトリに入れない＝D24）
 */
class PaperImportScreenTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use BuildsPaperLedgers;
    use ChecksDoubleSubmit;
    use ComparesJsonColumns;
    use ParsesForms;

    private const FILE_NAME = '08年度・決裁台帳.xls';

    private array $w;

    private ApprovalDepartment $estate;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00', 'UTC'));   // R8 年度（5 月始まり）
        $this->w      = $this->approvalWorld();                                 // 住宅事業部 J
        $this->estate = $this->approvalDepartment($this->w['company'], ['name' => 'ミツワ不動産', 'code' => 'M']);
        $this->admin  = $this->approvalAdmin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 受付簿 3 行と、2 行目に結び付く金額の控え 1 行 */
    private function ledger(array $sheets = []): string
    {
        return $this->paperLedger($sheets + [
            'Ｊ・住宅' => [
                [1, '8.05.07', '甲野', '見本町　用地購入'],
                [2, '8.05.18', '乙山', '見本邸新築工事契約'],
                [3, '8.06.01', '丙川', '見本ビル改修'],
            ],
            '土地・建物契約・金額' => ['amounts' => [
                [1, '8.05.18', '乙山', '見本邸新築工事契約', 30000000, 24000000],
            ]],
        ]);
    }

    private function preview(string $path, int $year = 2026, string $name = self::FILE_NAME): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('approvals.admin.papers.import'))
            ->post(route('approvals.admin.papers.import.preview'), [
                'ledger_file' => new UploadedFile($path, $name, null, null, true),
                'year'        => $year,
            ]);
    }

    /** 確認の画面が描いた確定のフォーム */
    private function confirmForm(TestResponse $preview): array
    {
        $preview->assertOk();
        $form = $this->parseForm($preview->getContent(), 'action="' . route('approvals.admin.papers.import.execute') . '"');
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が無い');

        return $form;
    }

    /**
     * 確定のフォームを送る。⚠ リファラーは確認の画面（preview の POST の応答）＝ POST 専用の URL（Bug #64）
     *
     * @param array<string, string> $choices 選び直す select（name => value）
     */
    private function send(array $form, array $choices = []): TestResponse
    {
        foreach ($choices as $name => $value) {
            $this->assertArrayHasKey($name, $form['fields'], "確定のフォームに {$name} が無い");
        }
        parse_str(http_build_query(array_merge($form['fields'], $choices)), $fields);

        return $this->actingAs($this->admin)
            ->from(route('approvals.admin.papers.import.preview'))
            ->post($form['action'], $fields);
    }

    // --- 入口 ---

    public function test_the_import_screen_offers_the_years_and_the_list_links_to_it(): void
    {
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.import'))->assertOk()->getContent();

        $this->assertStringContainsString('<option value="2026" selected>R8 年度（2026）</option>', $html, '今の年度を先に選ぶ');
        $this->assertStringContainsString('<option value="1989" >H1 年度（1989）</option>', $html, '平成元年度まで選べる');
        $this->assertStringNotContainsString('value="2027"', $html, '先の年度は選べない');
        $this->assertSame(1, substr_count($html, 'action="' . route('approvals.admin.papers.import.preview') . '"'));

        // 一覧からの入口（入口と受け側を対で。Bug #47）
        $list = $this->actingAs($this->admin)->get(route('approvals.admin.papers.index'))->assertOk()->getContent();
        $this->assertStringContainsString('href="' . route('approvals.admin.papers.import') . '"', $list);
    }

    /**
     * ファイルの名前の「08年度」から年度を選ぶ（画面の script を node で動かす。振る舞いの正本は実駆動。Bug #47）
     */
    public function test_the_year_is_picked_from_the_file_name(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので年度の先選びの JavaScript の実駆動を飛ばす');
        }
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.import'))->getContent();
        $this->assertSame(1, preg_match('/<script>\s*(function paperLedgerYear\(.*?)<\/script>/su', $html, $m), '年度を選ぶ script が描かれていない');
        $this->assertStringContainsString('onchange="paperLedgerFileChosen(this)"', $html, 'ファイルの選択が script を呼んでいない');

        $choices = json_encode(array_map('strval', array_keys(ApprovalPaperRecord::yearChoices())));
        $cases   = [
            '08年度・決裁申請書.xls' => '2026',     // 令和
            '０８年度　台帳.xlsx'      => '2026',     // 全角の数字
            '7年度.xls'                => '2025',
            '30年度.xls'               => '2018',     // 令和 30 年度は先なので平成 30 年度
            '台帳.xls'                 => null,       // 年度が無い
            '99年度.xls'               => null,       // どちらも選択肢に無い
        ];
        $harness = $m[1] . "\nprocess.stdout.write(JSON.stringify(" . json_encode(array_keys($cases)) . ".map(function (n) { return paperLedgerYear(n, {$choices}); })));";
        $output  = shell_exec(sprintf('%s -e %s 2>&1', escapeshellarg($node), escapeshellarg($harness)));

        $this->assertSame(array_values($cases), json_decode((string) $output, true), "node の出力: {$output}");
    }

    // --- 確認 ---

    public function test_an_upload_shows_the_confirmation_without_writing(): void
    {
        $html = $this->preview($this->ledger())->assertOk()->getContent();

        $this->assertSame([0, 0], [ApprovalPaperRecord::count(), ApprovalPaperImport::count()], '確認では何も書かない');
        $this->assertStringContainsString('足す 3 件', $html);
        $this->assertStringContainsString('R8-J-003', $html);
        $this->assertStringContainsString('30,000,000円', $html, '金額の控えの金額を足す記録に付ける');
        $this->assertStringContainsString('<span class="font-mono">R8-J-002</span>（自動）', $html);
        $this->assertStringContainsString('住宅事業部: <span class="font-mono">R8-J-001</span> → <span class="font-mono font-semibold">R8-J-004</span>', $html, '次の番号が進むことを出す');
        $this->assertSubmitOnceForm($html, route('approvals.admin.papers.import.execute'));

        $form = $this->confirmForm($this->preview($this->ledger()));
        $this->assertSame([self::FILE_NAME, '2026'], [$form['fields']['file_name'], $form['fields']['year']]);
    }

    /** 取り込まない行（赤）と注意（黄）は別のブロックに別の言い方で（Bug #54 ④）。取り込まない行があっても、ほかは取り込める */
    public function test_rows_with_errors_and_warnings_are_shown_apart(): void
    {
        $this->paperRecord($this->w['dept'], ['seq' => 3, 'subject' => '手で直した件名', 'decided_on' => '2026-06-01', 'applicant_name' => '丙川']);

        $html = $this->preview($this->ledger([
            'Ｊ・住宅' => [
                [1, '8.05.07', '甲野', '見本町　用地購入'],
                [2, '8.02.30', '乙山', 'ない日付'],
                [3, '8.06.01', '丙川', '見本ビル改修'],
            ],
            '土地・建物契約・金額' => ['amounts' => [[1, '8.05.07', '甲野', '見本町　用地購入', null, null]]],
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('取り込まない Ｊ・住宅 6 行目・左（ＮＯ 2）: 日付「8.02.30」が読めません（ない日付です）', $html);
        $this->assertStringContainsString('⚠ Ｊ・住宅 7 行目・左（ＮＯ 3）: R8-J-003 はすでにあります。Excel と件名が違いますが、変えません', $html);
        $this->assertStringContainsString('<span class="font-mono">R8-J-003</span>（Ｊ・住宅 7 行目・左（ＮＯ 3））:', $html);
        $this->assertStringContainsString('金額がありません（結び付けません）', $html, '金額を書く前の控えの行は結び付けない');
        $this->assertSubmitOnceForm($html, route('approvals.admin.papers.import.execute'));
    }

    // --- 確定 ---

    public function test_confirming_imports_the_records_and_advances_the_next_number(): void
    {
        $response = $this->send($this->confirmForm($this->preview($this->ledger())));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('approvals.admin.papers.index', ['year' => 2026]), $response->headers->get('Location'));
        $this->actingAs($this->admin)->get($response->headers->get('Location'))->assertOk()
            ->assertSee('3 件を取り込みました（金額を補った記録 0 件）。次の番号: 住宅事業部 R8-J-004。');

        $import = ApprovalPaperImport::sole();
        $this->assertSame([self::FILE_NAME, 2026, 3, 0, $this->admin->id], [$import->file_name, $import->fiscal_year, $import->added_count, $import->filled_count, $import->imported_by]);

        $records = ApprovalPaperRecord::orderBy('seq')->get();
        $this->assertSame(['R8-J-001', 'R8-J-002', 'R8-J-003'], $records->pluck('number')->all());
        $this->assertSame([$import->id], $records->pluck('import_id')->unique()->values()->all());
        $this->assertSame([$this->admin->id], $records->pluck('created_by')->unique()->values()->all());
        $this->assertSame([30000000, 24000000, 6000000], [$records[1]->amount, $records[1]->cost, $records[1]->profit], '粗利益は控えの式の値');
        $this->assertSame('2026-05-18', $records[1]->decided_on->format('Y-m-d'));

        $this->assertSame(4, ApprovalNumber::currentState($this->w['dept']->fresh('company'))['next'], '今年度の次の番号が紙の番号の次まで進む（D7）');

        $log = ApprovalSettingLog::where('action', 'paper.imported')->sole();
        $this->assertSame([$this->admin->id, 'approval_paper_import', $import->id], [$log->actor_user_id, $log->target_type, $log->target_id]);
        // ⚠ MySQL は JSON のキーを並べ替えて返す（キーの並びに頼らずに比べる）
        $this->assertSameIgnoringKeyOrder(['file_name' => self::FILE_NAME, 'fiscal_year' => 2026, 'added' => 3, 'filled' => 0, 'next_numbers' => ['J' => 4]], $log->new_values);
    }

    /** 過去の年度の台帳は、次の番号に関わらない（§5.11） */
    public function test_a_past_year_does_not_touch_the_next_number(): void
    {
        $this->send($this->confirmForm($this->preview($this->paperLedger(['Ｊ・住宅' => [[5, '7.05.07', '甲野', '前の年度の決裁']]]), 2025, '07年度.xls')));

        $this->assertSame('R7-J-005', ApprovalPaperRecord::sole()->number);
        $this->assertSame(1, ApprovalNumber::currentState($this->w['dept']->fresh('company'))['next']);
        $this->assertSame([], ApprovalSettingLog::where('action', 'paper.imported')->sole()->new_values['next_numbers']);
    }

    /** すでにある記録は中身を変えず、金額がすべて空なら控えの金額だけを補う（D6） */
    public function test_an_existing_record_only_gets_its_empty_amounts_filled(): void
    {
        $kept = $this->paperRecord($this->w['dept'], ['seq' => 2, 'subject' => '見本邸新築工事契約', 'decided_on' => '2026-05-18', 'applicant_name' => '乙山']);

        $html = $this->preview($this->ledger())->getContent();
        $this->assertStringContainsString('金額を補います（契約金額 30,000,000円・工事原価 24,000,000円・粗利益 6,000,000円）', $html);
        $this->send($this->confirmForm($this->preview($this->ledger())));

        $kept->refresh();
        $this->assertSame([30000000, 24000000, 6000000, null], [$kept->amount, $kept->cost, $kept->profit, $kept->import_id], '金額だけを補い、取り込み 1 回分には入れない（取り消しで消さない）');
        $this->assertSame([2, 1], [ApprovalPaperImport::sole()->added_count, ApprovalPaperImport::sole()->filled_count]);
    }

    /**
     * 確定でファイルを読み直す（画面が送ってきた hidden を信用しない）。確認のあとでほかの人が同じ番号を登録していたら、それを足さない
     */
    public function test_the_confirmation_reads_the_file_again(): void
    {
        $form = $this->confirmForm($this->preview($this->ledger()));
        $this->paperRecord($this->w['dept'], ['seq' => 1, 'subject' => 'ほかの人が先に登録']);

        $this->send($form);

        $this->assertSame('ほかの人が先に登録', ApprovalPaperRecord::where('number', 'R8-J-001')->sole()->subject);
        $this->assertSame(2, ApprovalPaperImport::sole()->added_count);
    }

    /** 確認のあとでほかの人が全部取り込んでいたら、何もせずに断る */
    public function test_nothing_left_to_import_is_refused(): void
    {
        $form = $this->confirmForm($this->preview($this->ledger()));
        $this->send($this->confirmForm($this->preview($this->ledger())));   // ほかの画面から先に取り込んだ

        [$response, $writes] = $this->countingWrites(fn () => $this->send($form));

        $this->assertRefused($response, route('approvals.admin.papers.import'), PaperImportController::NOTHING_TO_IMPORT, $this->admin);
        $this->assertSame(0, $writes);
        $this->assertSame(1, ApprovalPaperImport::count());
    }

    /** 二度押し・再送信は 1 回だけ効く（1 回限りの鍵。ファイルを読み直す前に断る） */
    public function test_a_second_submit_is_refused_without_writing(): void
    {
        $form = $this->confirmForm($this->preview($this->ledger()));
        $this->send($form);

        [$response, $writes] = $this->countingWrites(fn () => $this->send($form));

        $this->assertRefused($response, route('approvals.admin.papers.import'), PaperImportController::ALREADY_SENT, $this->admin);
        $this->assertSame(0, $writes);
        $this->assertSame([1, 3], [ApprovalPaperImport::count(), ApprovalPaperRecord::count()]);
    }

    // --- 金額の控えの結び付けを選ぶ ---

    /** 受付簿の 2 つの行（J と M）に当たる控えの行 */
    private function ambiguousLedger(): string
    {
        return $this->ledger([
            'Ｍ・ミツワ不動産' => [[1, '8.06.10', '丁田', '見本倉庫購入']],
            'Ｊ・住宅'        => [[1, '8.06.10', '丁田', '見本倉庫購入'], [2, '8.05.18', '乙山', '見本邸新築工事契約']],
            '土地・建物契約・金額' => ['amounts' => [
                [1, '8.06.10', '丁田', '見本倉庫購入', 50000000, 42000000],
                [2, '8.05.18', '乙山', '見本邸新築工事契約', 30000000, 24000000],
            ]],
        ]);
    }

    public function test_an_ambiguous_amount_row_is_chosen_on_the_confirmation(): void
    {
        $preview = $this->preview($this->ambiguousLedger());
        $html    = $preview->getContent();
        $this->assertStringContainsString('結び付け先が 1 つに決まらない行が 1 行あります。', $html);
        $form = $this->confirmForm($preview);
        $key  = $this->linkField($form);
        $this->assertSame('none', $form['fields'][$key], '選ぶまでは結び付けない');

        // ⚠ 先頭（結び付けない）以外の値で往復させる（ParsesForms の select の注意）
        $this->send($form, [$key => 'R8-M-001']);

        $this->assertSame(50000000, ApprovalPaperRecord::where('number', 'R8-M-001')->sole()->amount);
        $this->assertNull(ApprovalPaperRecord::where('number', 'R8-J-001')->sole()->amount);
        $this->assertSame(30000000, ApprovalPaperRecord::where('number', 'R8-J-002')->sole()->amount, '自動の結び付けはそのまま');
    }

    /** 自動で結び付いた記録をもう一度選ぶと、確認の画面をもう一度出して選び直してもらう（何も書かない） */
    public function test_choosing_a_record_twice_shows_the_confirmation_again(): void
    {
        $form = $this->confirmForm($this->preview($this->ambiguousLedger()));
        $key  = $this->linkField($form);

        [$response, $writes] = $this->countingWrites(fn () => $this->send($form, [$key => 'R8-J-002']));

        $response->assertOk();
        $this->assertSame(0, $writes);
        $html = $response->getContent();
        $this->assertStringContainsString('金額の控えの結び付けを選び直してください。', $html);
        $this->assertStringContainsString('金額の控えの 土地・建物契約・金額 5 行目（ＮＯ 1）・土地・建物契約・金額 6 行目（ＮＯ 2） が同じ R8-J-002 に結び付いています。', $html);

        // 選び直した画面は新しい鍵で、選んだ値を残している。選び直して送れば取り込める
        $again = $this->confirmForm($response);
        $this->assertNotSame($form['fields']['import_token'], $again['fields']['import_token']);
        $this->assertSame('R8-J-002', $again['fields'][$key]);
        $this->send($again, [$key => 'R8-J-001']);
        $this->assertSame(50000000, ApprovalPaperRecord::where('number', 'R8-J-001')->sole()->amount);
    }

    /**
     * 結び付けなかった控えは、同じ台帳を取り込み直したときに結び付けられる（足す記録が 0 件でも、選べる行があれば確定のフォームを描く）
     */
    public function test_an_amount_row_left_unlinked_can_be_linked_on_a_later_upload(): void
    {
        $this->send($this->confirmForm($this->preview($this->ambiguousLedger())));   // 1 回目は結び付けない
        $this->assertNull(ApprovalPaperRecord::where('number', 'R8-M-001')->sole()->amount);

        $preview = $this->preview($this->ambiguousLedger());
        $this->assertStringContainsString('足す 0 件', $preview->getContent());
        $form = $this->confirmForm($preview);
        $this->send($form, [$this->linkField($form) => 'R8-M-001']);

        $this->assertSame(50000000, ApprovalPaperRecord::where('number', 'R8-M-001')->sole()->amount);
        $second = ApprovalPaperImport::latest('id')->first();
        $this->assertSame([0, 1], [$second->added_count, $second->filled_count]);
        $this->assertSame(3, ApprovalPaperRecord::count(), '足すものは無い');
    }

    /** 結び付けを選ぶ select の名前（選べる行は 1 つだけの台帳で使う） */
    private function linkField(array $form): string
    {
        $keys = array_values(array_filter(array_keys($form['fields']), fn (string $name) => str_starts_with($name, 'links[')));
        $this->assertCount(1, $keys, '選べる結び付けが 1 つでない');

        return $keys[0];
    }

    // --- 断りの戻り先（確認の画面は POST の応答。Bug #64） ---

    public function test_a_failed_upload_from_the_confirmation_goes_back_to_the_import_screen(): void
    {
        $bad = UploadedFile::fake()->createWithContent('台帳.csv', "a,b\n");
        foreach ([
            [[], '台帳のファイルを選んでください。'],
            [['ledger_file' => $bad, 'year' => 2026], '台帳のファイル（.xls・.xlsx）を選んでください。'],
            [['ledger_file' => new UploadedFile($this->ledger(), self::FILE_NAME, null, null, true), 'year' => 2027], '年度を選び直してください。'],
        ] as [$fields, $message]) {
            $response = $this->actingAs($this->admin)
                ->from(route('approvals.admin.papers.import.preview'))
                ->post(route('approvals.admin.papers.import.preview'), $fields);

            $this->assertSame(302, $response->getStatusCode(), $message);
            $this->assertSame(route('approvals.admin.papers.import'), $response->headers->get('Location'), '取り込みの画面へ戻っていない（preview へ戻ると GET で 405）');
            $this->actingAs($this->admin)->get(route('approvals.admin.papers.import'))->assertOk()->assertSee($message);
        }
    }

    public function test_a_file_that_is_not_excel_is_refused_on_the_import_screen(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'paper') . '.xls';
        file_put_contents($path, 'Excel ではない中身');
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $response = $this->preview($path);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('approvals.admin.papers.import'), $response->headers->get('Location'));
        $this->actingAs($this->admin)->get(route('approvals.admin.papers.import'))->assertSee('Excel のファイル（.xls・.xlsx）として読めませんでした。台帳のファイルを選び直してください。');
        $this->assertSame(0, ApprovalPaperRecord::count());
    }

    // --- 確定のあいだの競合（ロックのあとで確かめる。コントローラが断る経路を HTTP で通す。点検 I-4） ---

    /** 確認の画面のあとで社長の判断が同じ番号を付けたら、何も書かずに取り込みの画面へ戻す（次の番号も戻る） */
    public function test_a_system_number_taken_during_the_confirmation_is_refused(): void
    {
        $draft = $this->draftFor($this->w);
        $form  = $this->confirmForm($this->preview($this->ledger()));
        $done  = false;
        // 連番の行をロックして進めた瞬間に、ほかの人の判断が R8-J-001 を付けた（同じトランザクションの中なので、断られたら一緒に戻る）
        ApprovalNumberSequence::updated(function () use (&$done, $draft): void {
            if (! $done) {
                $done = true;
                DB::table('approval_requests')->where('id', $draft->id)->update(['number' => 'R8-J-001', 'number_department_id' => $this->w['dept']->id]);
            }
        });

        $response = $this->send($form);

        $this->assertRefused($response, route('approvals.admin.papers.import'), '確認の画面を出したあとで、システムの申請が R8-J-001 を使いました。もう一度アップロードして確かめてください。', $this->admin);
        $this->assertSame([0, 0, null], [ApprovalPaperRecord::count(), ApprovalPaperImport::count(), $draft->fresh()->number]);
        $this->assertSame(1, ApprovalNumber::currentState($this->w['dept']->fresh('company'))['next']);
    }

    /** 確認の画面のあとでほかの管理者が同じ番号の紙の決裁を登録していたら（一意の索引）、何も書かずに取り込みの画面へ戻す */
    public function test_a_paper_number_taken_during_the_confirmation_is_refused(): void
    {
        $form = $this->confirmForm($this->preview($this->ledger()));
        $done = false;
        ApprovalPaperImport::created(function () use (&$done): void {
            if (! $done) {
                $done = true;
                DB::table('approval_paper_records')->insert([
                    'department_id' => $this->w['dept']->id, 'fiscal_year' => 2026, 'seq' => 2, 'number' => 'R8-J-002', 'decided_on' => '2026-05-18',
                    'subject' => 'ほかの管理者が登録', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $response = $this->send($form);

        $this->assertRefused($response, route('approvals.admin.papers.import'), 'ほかの人が同じ番号の紙の決裁を登録しました。もう一度アップロードして確かめてください。', $this->admin);
        $this->assertSame([0, 0], [ApprovalPaperRecord::count(), ApprovalPaperImport::count()]);
    }

    /** 確認の画面のあとで手で金額を入れた記録には、控えの金額を入れない（確定の瞬間にも空かを確かめる。D6） */
    public function test_amounts_typed_during_the_confirmation_are_not_overwritten(): void
    {
        $kept = $this->paperRecord($this->w['dept'], ['seq' => 2, 'subject' => '見本邸新築工事契約', 'decided_on' => '2026-05-18', 'applicant_name' => '乙山']);
        $form = $this->confirmForm($this->preview($this->ledger()));
        $done = false;
        ApprovalPaperImport::created(function () use (&$done, $kept): void {
            if (! $done) {
                $done = true;
                DB::table('approval_paper_records')->where('id', $kept->id)->update(['amount' => 123]);
            }
        });

        $this->send($form);

        $this->assertSame([123, null, null], [$kept->fresh()->amount, $kept->fresh()->cost, $kept->fresh()->profit]);
        $this->assertSame([2, 0], [ApprovalPaperImport::sole()->added_count, ApprovalPaperImport::sole()->filled_count]);
    }

    /** 壊れた送信（hidden のファイルが base64 でない）は取り込みの画面へ戻す */
    public function test_a_broken_file_in_the_confirmation_is_refused(): void
    {
        $form = $this->confirmForm($this->preview($this->ledger()));
        $form['fields']['ledger_data'] = '***';

        $this->assertRefused($this->send($form), route('approvals.admin.papers.import'), '取り込むファイルを読み取れませんでした。もう一度アップロードしてください。', $this->admin);
        $this->assertSame(0, ApprovalPaperRecord::count());
    }

    /** 知らせる次の番号は、確定で実際に進めた値（確認の画面のあとで開始番号が変わっても合う。点検 M-6） */
    public function test_the_next_number_in_the_notice_is_the_one_actually_set(): void
    {
        $form = $this->confirmForm($this->preview($this->ledger()));
        ApprovalNumber::setNext($this->w['dept']->fresh('company'), 50);

        $response = $this->send($form);

        $this->actingAs($this->admin)->get($response->headers->get('Location'))->assertSee('3 件を取り込みました（金額を補った記録 0 件）。次の番号: 住宅事業部 R8-J-050。');
    }

    public function test_a_broken_confirmation_goes_back_to_the_import_screen(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('approvals.admin.papers.import.preview'))
            ->post(route('approvals.admin.papers.import.execute'), []);   // ledger_data が無い（壊れた・書き換えた送信）

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('approvals.admin.papers.import'), $response->headers->get('Location'));
        $this->actingAs($this->admin)->get(route('approvals.admin.papers.import'))->assertOk();
    }
}
```

`tests/Feature/Approval/Phase5/PaperNumberTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalNumberSequence;
use App\Models\ApprovalPaperImport;
use App\Models\ApprovalPaperRecord;
use App\Models\ApprovalRequest;
use App\Support\Approval\ApprovalNumber;
use App\Support\Approval\PaperImportAnalysis;
use App\Support\Approval\PaperImporter;
use App\Support\Approval\PaperImportException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\BuildsPaperLedgers;
use Tests\TestCase;

/**
 * 紙の決裁の番号と採番（段階5 設計書 D7・§5.11）。今年度の紙の番号を足すと次の番号が進み（戻さない）、開始番号は紙の番号以下にできない。
 * 過去の年度の紙の番号は採番に関わらない。取り込みの確定は、確認のあとで番号がふさがっていたら何も書かずに断る。
 */
class PaperNumberTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use BuildsPaperLedgers;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00', 'UTC'));   // R8 年度（5 月始まり）
        $this->w = $this->approvalWorld();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_paper_number_of_this_year_moves_the_next_number_forward_only(): void
    {
        $dept = $this->w['dept'];

        $this->assertSame(35, ApprovalNumber::advancePast($dept, 2026, 34));
        $this->assertSame(35, ApprovalNumber::currentState($dept)['next']);
        $this->assertSame(0, ApprovalNumber::currentState($dept)['last_issued'], '使った番号（システムの採番）は変えない');

        $this->assertSame(35, ApprovalNumber::advancePast($dept, 2026, 10), '小さい番号では戻さない');
        ApprovalNumber::setNext($dept, 50);
        $this->assertSame(50, ApprovalNumber::advancePast($dept, 2026, 34));

        $this->assertSame('R8-J-050', ApprovalNumber::issue($dept, now())['number']);
    }

    public function test_a_paper_number_of_a_past_year_does_not_touch_the_numbering(): void
    {
        $this->assertNull(ApprovalNumber::advancePast($this->w['dept'], 2025, 90));
        $this->assertSame(0, ApprovalNumberSequence::count(), '過去の年度の連番の行を作らない');
    }

    /** 開始番号は今年度の紙の番号以下にできない（過去の年度の紙は関わらない） */
    public function test_the_start_number_cannot_go_under_this_years_paper_numbers(): void
    {
        $dept = $this->w['dept'];
        $this->paperRecord($dept, ['fiscal_year' => 2025, 'seq' => 90]);
        ApprovalNumber::setNext($dept, 2);

        $this->paperRecord($dept, ['fiscal_year' => 2026, 'seq' => 34]);
        try {
            ApprovalNumber::setNext($dept, 34);
            $this->fail('紙の番号へ戻せた');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('今年度は紙の決裁で R8-J-034 まで使っています。35 以上を入れてください。', $e->getMessage());
        }

        ApprovalNumber::setNext($dept, 35);
        $this->assertSame(35, ApprovalNumber::currentState($dept)['next']);
        $this->assertSame(34, ApprovalNumber::lastPaperSeq($dept, 2026));
        $this->assertSame(0, ApprovalNumber::lastPaperSeq($this->w['reviewDept'], 2026));
    }

    /** 部門の管理の編集のモーダルの案内は、今年度の紙の番号のほうが大きければそちらを出す */
    public function test_the_organization_screen_names_the_last_paper_number(): void
    {
        $this->paperRecord($this->w['dept'], ['fiscal_year' => 2026, 'seq' => 34]);
        $this->paperRecord($this->w['reviewDept'], ['fiscal_year' => 2025, 'seq' => 7]);

        $html = $this->actingAs($this->approvalAdmin())->get(route('approvals.admin.organization.index'))->assertOk()->getContent();
        preg_match_all("/openDepartmentEdit\\(JSON\\.parse\\('([^']*)'\\)\\)/", $html, $m);
        $hints = [];
        foreach ($m[1] as $inner) {
            $row               = json_decode(json_decode('"' . $inner . '"'), true);
            $hints[$row['id']] = $row['number_hint'];
        }

        $this->assertSame('今年度（R8）は紙の決裁で R8-J-034 まで使っています。', $hints[$this->w['dept']->id]);
        $this->assertSame('今年度（R8）はまだ番号を使っていません。', $hints[$this->w['reviewDept']->id], '過去の年度の紙は案内に出さない');
    }

    // --- 取り込みの確定の歯止め（PaperImporter） ---

    private function analysis(): array
    {
        return PaperImportAnalysis::analyze($this->paperLedger(['Ｊ・住宅' => [
            [1, '8.05.07', '甲野', '見本町　用地購入'],
            [2, '8.05.18', '乙山', '見本邸新築工事契約'],
        ]]), 2026);
    }

    /** 確認のあとで社長の判断が同じ番号を付けていたら、何も書かずに断る（ロックのあとで確かめる） */
    public function test_a_system_number_taken_after_the_confirmation_stops_the_import(): void
    {
        $analysis = $this->analysis();
        $draft    = $this->draftFor($this->w);
        DB::table('approval_requests')->where('id', $draft->id)->update(['number' => 'R8-J-002', 'number_department_id' => $this->w['dept']->id]);

        try {
            PaperImporter::apply($analysis, 2026, '08年度.xls', $this->approvalAdmin());
            $this->fail('ふさがった番号で取り込めた');
        } catch (PaperImportException $e) {
            $this->assertSame('確認の画面を出したあとで、システムの申請が R8-J-002 を使いました。もう一度アップロードして確かめてください。', $e->getMessage());
        }

        $this->assertSame([0, 0], [ApprovalPaperRecord::count(), ApprovalPaperImport::count()]);
        $this->assertSame(1, ApprovalNumber::currentState($this->w['dept'])['next'], '次の番号も戻る');
        $this->assertSame(1, ApprovalRequest::whereNotNull('number')->count());
    }

    /** 確認のあとでほかの管理者が同じ番号の紙の決裁を登録していたら、一意の索引で止まり、何も残さない */
    public function test_a_paper_number_taken_after_the_confirmation_stops_the_import(): void
    {
        $analysis = $this->analysis();
        $this->paperRecord($this->w['dept'], ['seq' => 2]);

        try {
            PaperImporter::apply($analysis, 2026, '08年度.xls', $this->approvalAdmin());
            $this->fail('重なった番号で取り込めた');
        } catch (UniqueConstraintViolationException) {
        }

        $this->assertSame([1, 0], [ApprovalPaperRecord::count(), ApprovalPaperImport::count()]);
        $this->assertSame(1, ApprovalNumber::currentState($this->w['dept'])['next']);
    }
}
```

`tests/Feature/Approval/Phase5/PaperListTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase5;

use App\Http\Controllers\Approval\PaperRecordController;
use App\Models\ApprovalPaperImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 取り込んだ紙の決裁の一覧と取り込みの記録（画面⑪。段階5 設計書 §5.10）。決裁の管理者だけ・使い始める前から（D11）。
 */
class PaperListTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private array $w;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00', 'UTC'));
        $this->w     = $this->approvalWorld();
        $this->admin = $this->approvalAdmin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function listHtml(array $query = []): string
    {
        return $this->actingAs($this->admin)->get(route('approvals.admin.papers.index', $query))->assertOk()->getContent();
    }

    public function test_the_records_are_listed_newest_first(): void
    {
        $this->paperRecord($this->w['dept'], ['seq' => 1, 'decided_on' => '2026-05-07', 'subject' => '古い決裁']);
        $this->paperRecord($this->w['dept'], ['seq' => 2, 'decided_on' => '2026-06-01', 'subject' => '新しい決裁', 'amount' => 30000000, 'applicant_name' => null]);

        $html = $this->listHtml();

        $this->assertStringContainsString('<span class="font-semibold tabular-nums">2</span> 件', $html);
        $this->assertLessThan(strpos($html, '古い決裁'), strpos($html, '新しい決裁'), '決裁日の新しい順');
        $this->assertStringContainsString('2026/06/01', $html);
        $this->assertStringContainsString('30,000,000円', $html);
        $this->assertStringContainsString('まだ取り込んでいません。', $html);
    }

    public function test_the_list_is_narrowed_by_the_year_and_the_department(): void
    {
        $this->paperRecord($this->w['dept'], ['fiscal_year' => 2025, 'seq' => 1, 'subject' => '前の年度の住宅']);
        $this->paperRecord($this->w['dept'], ['fiscal_year' => 2026, 'seq' => 1, 'subject' => '今年度の住宅']);
        $this->paperRecord($this->w['reviewDept'], ['fiscal_year' => 2026, 'seq' => 1, 'subject' => '今年度の総務']);

        $html = $this->listHtml(['year' => 2026, 'department' => $this->w['dept']->id]);
        $this->assertStringContainsString('今年度の住宅', $html);
        $this->assertStringNotContainsString('前の年度の住宅', $html);
        $this->assertStringNotContainsString('今年度の総務', $html);
        $this->assertStringContainsString('<option value="2026" selected>R8 年度（2026）</option>', $html);

        // 年度の選択肢は紙の決裁のある年度だけ
        $this->assertStringContainsString('<option value="2025" >R7 年度（2025）</option>', $html);
        $this->assertStringNotContainsString('value="2024"', $html);
    }

    /** 選択肢に無い値は外す（手で打った URL。断らない） */
    public function test_values_outside_the_choices_are_ignored(): void
    {
        $this->paperRecord($this->w['dept'], ['subject' => '残る決裁']);

        foreach ([['year' => 'abc'], ['year' => '1900'], ['department' => '999999'], ['department' => ['x']], ['year' => '02026']] as $query) {
            $html = $this->listHtml($query);
            $this->assertStringContainsString('残る決裁', $html, json_encode($query));
            $this->assertStringNotContainsString('条件を消す', $html, json_encode($query));
        }
    }

    /** ページ番号は最後のページまで（手で打った大きな番号でも 500 にしない。Bug #100） */
    public function test_a_page_past_the_end_shows_the_last_page(): void
    {
        for ($seq = 1; $seq <= PaperRecordController::PER_PAGE + 1; $seq++) {
            $this->paperRecord($this->w['dept'], ['seq' => $seq, 'decided_on' => '2026-05-07', 'subject' => sprintf('決裁%03d', $seq)]);
        }

        $html = $this->listHtml(['page' => 99]);

        $this->assertSame(1, substr_count($html, '決裁0'), '最後のページの 1 件だけ');
        $this->assertStringContainsString('決裁051', $html);
    }

    public function test_the_imports_are_listed_with_their_undoing(): void
    {
        $other = $this->baseUser(['name' => '取り消した 人']);
        ApprovalPaperImport::create(['file_name' => '07年度.xls', 'fiscal_year' => 2025, 'added_count' => 120, 'filled_count' => 3, 'imported_by' => $this->admin->id,
            'undone_at' => '2026-10-07 01:00:00', 'undone_by' => $other->id, 'undone_count' => 118]);
        ApprovalPaperImport::create(['file_name' => '08年度.xls', 'fiscal_year' => 2026, 'added_count' => 204, 'filled_count' => 0, 'imported_by' => $this->admin->id]);

        $html = $this->listHtml();

        $this->assertLessThan(strpos($html, '07年度.xls'), strpos($html, '08年度.xls'), '新しい順');
        $this->assertStringContainsString('204 件', $html);
        $this->assertStringContainsString('2026/10/07 10:00 に 取り消した 人 が取り消し（118 件を消した）', $html, '保存した日時は日本時間（Bug #61）');
    }
}
```

走査テストに登録する（§0.11）:

`tests/Feature/Approval/Phase2/LaunchGateTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/LaunchGateTest.php
+++ b/tests/Feature/Approval/Phase2/LaunchGateTest.php
@@ -29,6 +29,7 @@ class LaunchGateTest extends TestCase
         'approvals.admin.organization.' => '部門の管理（準備の画面。本番で先に登録する。D1）',
         'approvals.admin.types.'        => '申請種類の管理（準備の画面。D1）',
         'approvals.admin.holidays.'     => '催促の設定（準備の画面。使い始める前から送らない日を登録できる。段階3 設計書 §5.9）',
+        'approvals.admin.papers.'       => '過去の紙の決裁の取り込み（準備の画面。使い始める日までに取り込む。段階5 設計書 D11）',
     ];
 
     /**
```

`tests/Feature/Approval/ApprovalSidebarTest.php`（変更）

```diff
--- a/tests/Feature/Approval/ApprovalSidebarTest.php
+++ b/tests/Feature/Approval/ApprovalSidebarTest.php
@@ -348,6 +348,30 @@ public function test_the_reminder_settings_link_is_offered_to_admins_before_laun
         }
     }
 
+    /** 過去の決裁の取り込みは、使い始める前から決裁の管理者に出る（使い始める日までに取り込む。段階5 設計書 D11） */
+    public function test_the_paper_import_link_is_offered_to_admins_before_launch(): void
+    {
+        // 決裁のみ利用者の管理者: 決裁のサイドバー
+        $sidebars = $this->sidebars(
+            $this->actingAs($this->approvalAdmin(UserRole::ApprovalOnly->value))->get(route('approvals.home'))->assertOk()->getContent()
+        );
+        foreach (['expanded', 'drawer'] as $key) {
+            $this->assertHasLink($sidebars[$key], route('approvals.admin.papers.index'), '過去の決裁の取り込み', $key);
+        }
+
+        // 基幹を使う管理者: 基幹のサイドバーの「決裁の管理」
+        $sidebars = $this->sidebars($this->actingAs($this->approvalAdmin())->get('/dashboard/tenant')->assertOk()->getContent());
+        foreach (['expanded', 'drawer'] as $key) {
+            $this->assertHasLink($sidebars[$key], route('approvals.admin.papers.index'), '過去の決裁の取り込み', $key);
+        }
+
+        // 管理者でない人には出さない
+        $plain = User::factory()->approvalOnly()->create(['must_change_password' => false]);
+        foreach ($this->sidebars($this->actingAs($plain)->get(route('approvals.home'))->assertOk()->getContent()) as $key => $aside) {
+            $this->assertStringNotContainsString(route('approvals.admin.papers.index'), $aside, "{$key} に管理者でない人の過去の決裁の取り込みが出ている");
+        }
+    }
+
     /** 申請の画面へのリンクは、使い始めてから出す（準備中は誰にも見せない。段階2 設計書 D1） */
     public function test_the_request_links_appear_only_after_launch(): void
     {
```

`tests/Feature/Approval/Phase3/MailFailureBannerTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase3/MailFailureBannerTest.php
+++ b/tests/Feature/Approval/Phase3/MailFailureBannerTest.php
@@ -35,7 +35,7 @@ protected function setUp(): void
         ApprovalSetting::current();   // 本番は SQL が入れた 1 行がある
     }
 
-    /** 決裁の管理者の画面（使い始める前）: ホーム（準備中）と管理の 4 画面（3b で催促の設定を足した） */
+    /** 決裁の管理者の画面（使い始める前）: ホーム（準備中）と管理の 6 画面（3b で催促の設定・5b で過去の決裁の取り込みと台帳の取り込みを足した） */
     private function adminPagesBeforeLaunch(): array
     {
         return [
@@ -44,6 +44,8 @@ private function adminPagesBeforeLaunch(): array
             route('approvals.admin.organization.index'),
             route('approvals.admin.types.index'),
             route('approvals.admin.holidays.index'),
+            route('approvals.admin.papers.index'),
+            route('approvals.admin.papers.import'),
         ];
     }
```

`tests/Feature/SubmitOnceTest.php`（変更）

```diff
--- a/tests/Feature/SubmitOnceTest.php
+++ b/tests/Feature/SubmitOnceTest.php
@@ -26,8 +26,8 @@ class SubmitOnceTest extends TestCase
         'admin/customers/import.blade.php' => '独自の csvImport() が二度押し止めを持つ（2026-09-27。共通の部品へのそろえは範囲外。設計書 §6）',
     ];
 
-    /** 鍵を描く画面の数の下限（2026-09-28 実測 7。空振りして緑になる事故を防ぐ） */
-    private const MIN_TOKEN_VIEWS = 7;
+    /** 鍵を描く画面の数の下限（2026-09-28 実測 7・決裁 5b の紙の決裁台帳の取り込みで 8。空振りして緑になる事故を防ぐ） */
+    private const MIN_TOKEN_VIEWS = 8;
 
     /** 部品を読み込んで、レイアウトと同じく scripts のスタックを最後に出したページ */
     private function renderedPage(string $includes = "@include('_partials._submit_once')"): string
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/5b/patches/0005-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/PaperImportScreenTest.php tests/Feature/Approval/Phase5/PaperNumberTest.php tests/Feature/Approval/Phase5/PaperListTest.php tests/Feature/Approval/Phase2/LaunchGateTest.php tests/Feature/Approval/ApprovalSidebarTest.php tests/Feature/Approval/Phase3/MailFailureBannerTest.php tests/Feature/SubmitOnceTest.php
```

Expected: `ERRORS!` `Tests: 66, Assertions: 272, Errors: 33, Failures: 3.`

- `ApprovalSidebarTest::test_the_paper_import_link_is_offered_to_admins_before_launch` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.index] not defined.`
- `MailFailureBannerTest::test_there_is_no_banner_while_mail_is_fine` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.index] not defined.`
- `MailFailureBannerTest::test_a_failure_shows_the_banner_on_the_admin_pages` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.index] not defined.`
- `PaperImportScreenTest::test_the_import_screen_offers_the_years_and_the_list_links_to_it` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_the_year_is_picked_from_the_file_name` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_an_upload_shows_the_confirmation_without_writing` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_rows_with_errors_and_warnings_are_shown_apart` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_confirming_imports_the_records_and_advances_the_next_number` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_a_past_year_does_not_touch_the_next_number` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_an_existing_record_only_gets_its_empty_amounts_filled` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_the_confirmation_reads_the_file_again` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_nothing_left_to_import_is_refused` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_a_second_submit_is_refused_without_writing` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_an_ambiguous_amount_row_is_chosen_on_the_confirmation` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_choosing_a_record_twice_shows_the_confirmation_again` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_an_amount_row_left_unlinked_can_be_linked_on_a_later_upload` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_a_failed_upload_from_the_confirmation_goes_back_to_the_import_screen` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import.preview] not defined.`
- `PaperImportScreenTest::test_a_file_that_is_not_excel_is_refused_on_the_import_screen` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_a_system_number_taken_during_the_confirmation_is_refused` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_a_paper_number_taken_during_the_confirmation_is_refused` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_amounts_typed_during_the_confirmation_are_not_overwritten` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_a_broken_file_in_the_confirmation_is_refused` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_the_next_number_in_the_notice_is_the_one_actually_set` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import] not defined.`
- `PaperImportScreenTest::test_a_broken_confirmation_goes_back_to_the_import_screen` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.import.preview] not defined.`
- `PaperListTest::test_the_records_are_listed_newest_first` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.index] not defined.`
- `PaperListTest::test_the_list_is_narrowed_by_the_year_and_the_department` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.index] not defined.`
- `PaperListTest::test_values_outside_the_choices_are_ignored` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.index] not defined.`
- `PaperListTest::test_a_page_past_the_end_shows_the_last_page` — `Error: Class "App\Http\Controllers\Approval\PaperRecordController" not found`
- `PaperListTest::test_the_imports_are_listed_with_their_undoing` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.index] not defined.`
- `PaperNumberTest::test_a_paper_number_of_this_year_moves_the_next_number_forward_only` — `Error: Call to undefined method App\Support\Approval\ApprovalNumber::advancePast()`
- `PaperNumberTest::test_a_paper_number_of_a_past_year_does_not_touch_the_numbering` — `Error: Call to undefined method App\Support\Approval\ApprovalNumber::advancePast()`
- `PaperNumberTest::test_a_system_number_taken_after_the_confirmation_stops_the_import` — `Error: Class "App\Support\Approval\PaperImporter" not found`
- `PaperNumberTest::test_a_paper_number_taken_after_the_confirmation_stops_the_import` — `Error: Class "App\Support\Approval\PaperImporter" not found`
- `PaperNumberTest::test_the_start_number_cannot_go_under_this_years_paper_numbers` — `紙の番号へ戻せた`
- `PaperNumberTest::test_the_organization_screen_names_the_last_paper_number` — `Failed asserting that two strings are identical.`
- `SubmitOnceTest::test_every_view_that_carries_an_import_token_uses_submit_once` — `鍵を描く画面の列挙が痩せている（走査の空振り）`

- [ ] **Step 3: 次の番号を進める**（今年度だけ・戻さない・連番の行をロックする。**ロックのあとの確かめはロック付きの読み**。開始番号は紙の番号以下を断る。部門の管理の案内）

⚠ `reservePaperNumbers()` のシステムの番号の確かめと `setNext()` の紙の番号は、**`sharedLock()` を外さない**（MySQL の REPEATABLE READ では普通の読みが古い中身を読み続け、紙と申請が同じ決裁No を持つ。SQLite のテストでは見えない。§0.6）。

`app/Support/Approval/ApprovalNumber.php`（変更）

```diff
--- a/app/Support/Approval/ApprovalNumber.php
+++ b/app/Support/Approval/ApprovalNumber.php
@@ -71,7 +71,7 @@ public static function currentState(ApprovalDepartment $department): array
     /**
      * 今年度の「次に付く番号」を設定する（要件 6.4）。
      *
-     * ⚠ その年度に使った番号より小さい数は断る（D9。番号が重なるのを防ぐ）。
+     * ⚠ その年度に使った番号より小さい数は断る（D9。番号が重なるのを防ぐ）。取り込んだ今年度の紙の決裁の番号以下も断る（段階5 §5.11）。
      *
      * @throws InvalidArgumentException 使った番号以下のとき（メッセージはそのまま画面に出せる）
      */
@@ -86,10 +86,82 @@ public static function setNext(ApprovalDepartment $department, int $next): void
                 throw new InvalidArgumentException("今年度はすでに {$used} まで使っています。" . ($row->last_issued + 1) . ' 以上を入れてください。');
             }
 
+            // ⚠ ロック付きの読み（連番の行のロックを待つあいだに取り込みが commit した紙の番号も見る。reservePaperNumbers と同じ理由）
+            $paper = self::lastPaperSeq($department, $fiscalYear, lock: true);
+            if ($next <= $paper) {
+                $used = self::format(ApprovalFiscalYear::eraLabel($fiscalYear, $department->company->fiscal_start_month), $department->code, $paper);
+                throw new InvalidArgumentException("今年度は紙の決裁で {$used} まで使っています。" . ($paper + 1) . ' 以上を入れてください。');
+            }
+
             $row->update(['next_number' => $next]);
         });
     }
 
+    /**
+     * 今年度の紙の決裁の番号を足したとき（取り込み・1 件の登録・番号の直し）、その部門の次の番号を「紙の番号＋1」まで進める（段階5 D7・§5.11）。
+     * **戻さない**（次の番号のほうが大きければそのまま）。過去の年度の紙の番号は採番に関わらない（何もしない）。
+     *
+     * ⚠ 連番の行をロックする（社長の判断の採番と重ならない）。紙の決裁を保存するトランザクションの中で、保存の前に呼ぶ
+     *   （保存が断られたら番号も戻る。ロックのあとでシステムの申請の番号を確かめれば、確認と確定のあいだの採番にも気づける）
+     *
+     * @return int|null 進めたあとの次の番号（今年度でなければ null）
+     */
+    public static function advancePast(ApprovalDepartment $department, int $fiscalYear, int $seq): ?int
+    {
+        return DB::transaction(function () use ($department, $fiscalYear, $seq): ?int {
+            if ($fiscalYear !== ApprovalFiscalYear::current($department->company->fiscal_start_month)) {
+                return null;
+            }
+
+            $row = self::lockedRow($department, $fiscalYear);
+            if ($row->next_number <= $seq) {
+                $row->update(['next_number' => $seq + 1]);
+            }
+
+            return $row->next_number;
+        });
+    }
+
+    /**
+     * 紙の決裁の番号を足す前の確かめ（取り込みの確定と 1 件ずつの登録・直しが、保存と同じトランザクションの中で**保存の前に**呼ぶ。段階5 D7・§5.11）
+     *
+     * 1. 部門の id の順に、今年度の部門の次の番号を進める（advancePast。連番の行をロックする＝社長の判断の採番と順番に並ぶ。戻さない）
+     * 2. **ロックのあとで**、システムの申請がすでに使っている番号を**ロック付きの読み**（共有ロック）で確かめる
+     *
+     * ⚠ 2 を普通の読みにしない。MySQL の REPEATABLE READ では、トランザクションで最初に普通に読んだ時点の中身を読み続けるので、
+     *   連番の行のロックを待つあいだに社長の判断が付けた番号が見えない（2026-10-13 の点検で、使い捨ての MySQL の 2 本の接続で再現した。
+     *   SQLite のテストでは見えない。Workflow の「ロックより前に読まない」と同じ罠）。`number` は一意の索引なので、共有ロックは当たる行と隙間だけ
+     *
+     * @param array<int, int> $maxSeqs 部門の id => 足すいちばん大きいＮＯ
+     * @param list<string> $numbers 足す決裁No
+     * @return array{next: array<int, int>, taken: list<string>} 進めたあとの次の番号（部門の id => 番号。今年度の部門だけ）と、システムの申請がすでに使っている番号
+     */
+    public static function reservePaperNumbers(array $maxSeqs, int $fiscalYear, array $numbers): array
+    {
+        ksort($maxSeqs);
+        $departments = ApprovalDepartment::with('company')->whereKey(array_keys($maxSeqs))->get()->keyBy('id');
+
+        $next = [];
+        foreach ($maxSeqs as $departmentId => $seq) {
+            $after = self::advancePast($departments[$departmentId], $fiscalYear, $seq);
+            if ($after !== null) {
+                $next[$departmentId] = $after;
+            }
+        }
+
+        $taken = $numbers === [] ? [] : ApprovalRequest::whereIn('number', $numbers)->sharedLock()->orderBy('number')->pluck('number')->all();
+
+        return ['next' => $next, 'taken' => $taken];
+    }
+
+    /** その年度の紙の決裁のいちばん大きいＮＯ（無ければ 0。部門の管理の案内と開始番号の検査。検査はロック付きの読み） */
+    public static function lastPaperSeq(ApprovalDepartment $department, int $fiscalYear, bool $lock = false): int
+    {
+        return (int) ApprovalPaperRecord::where('department_id', $department->id)->where('fiscal_year', $fiscalYear)
+            ->when($lock, fn ($q) => $q->sharedLock())
+            ->max('seq');
+    }
+
     /** 番号を付けた申請がある部門か（会社とアルファベットを変えられない。D8） */
     public static function departmentHasNumbers(ApprovalDepartment $department): bool
     {
```

`app/Http/Controllers/Approval/OrganizationController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/OrganizationController.php
+++ b/app/Http/Controllers/Approval/OrganizationController.php
@@ -49,6 +49,7 @@ function (ApprovalDepartment $department) use ($company): void {
                 $department->number_state = ApprovalNumber::currentState($department);
                 $department->has_numbers  = ApprovalNumber::departmentHasNumbers($department);
                 $department->has_paper    = ApprovalNumber::departmentHasPaperNumbers($department);
+                $department->last_paper   = ApprovalNumber::lastPaperSeq($department, $department->number_state['fiscal_year']);
             }
         ));
```

`resources/views/approvals/admin/organization.blade.php`（変更）

```diff
--- a/resources/views/approvals/admin/organization.blade.php
+++ b/resources/views/approvals/admin/organization.blade.php
@@ -100,9 +100,12 @@
                         // 今年度の次の番号（要件 6.4）。編集のモーダルの説明にも使う
                         $state      = $dept->number_state;
                         $nextLabel  = \App\Support\Approval\ApprovalNumber::format($state['era'], $dept->code, $state['next']);
-                        $numberHint = $state['last_issued'] > 0
-                            ? "今年度（{$state['era']}）は " . \App\Support\Approval\ApprovalNumber::format($state['era'], $dept->code, $state['last_issued']) . ' まで使っています。'
-                            : "今年度（{$state['era']}）はまだ番号を使っていません。";
+                        // 取り込んだ今年度の紙の決裁の番号のほうが大きければ、そちらを出す（開始番号はそれ以下にできない。段階5 §5.11）
+                        $numberHint = match (true) {
+                            $dept->last_paper > $state['last_issued'] => "今年度（{$state['era']}）は紙の決裁で " . \App\Support\Approval\ApprovalNumber::format($state['era'], $dept->code, $dept->last_paper) . ' まで使っています。',
+                            $state['last_issued'] > 0                 => "今年度（{$state['era']}）は " . \App\Support\Approval\ApprovalNumber::format($state['era'], $dept->code, $state['last_issued']) . ' まで使っています。',
+                            default                                   => "今年度（{$state['era']}）はまだ番号を使っていません。",
+                        };
                     @endphp
                     <tr class="hover:bg-gray-50">
                         <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $dept->company->name }}</td>
```

- [ ] **Step 4: 取り込みを保存する部品**（1 つのトランザクション。ロック → システムの番号 → 保存 → 金額を補う → 設定の記録）と、年度の選択肢

`app/Support/Approval/PaperImporter.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Models\ApprovalDepartment;
use App\Models\ApprovalPaperImport;
use App\Models\ApprovalPaperRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 確かめた取り込みの中身（PaperImportAnalysis）を保存する（段階5 設計書 §5.9・D6・D7・D9）。1 つのトランザクション。
 *
 * - 今年度の部門は、連番の行をロックして次の番号を進める（D7。戻さない）。**ロックのあとで**システムの申請の番号をロック付きの読みで
 *   もう一度確かめる（確認の画面を出してから確定までのあいだに、社長の判断で同じ番号が付いていたら断る。ApprovalNumber::reservePaperNumbers）
 * - 取り込み 1 回分（approval_paper_imports）を作り、足した記録に結び付ける（取り消しの単位。D9）
 * - すでにある記録は、金額がすべて空のときだけ金額の控えから補う（D6。確定の瞬間にも空かを確かめる＝手で入れた金額を上書きしない）
 * - 設定の記録に残す（何を・何件・次の番号）
 *
 * ⚠ 紙どうしの番号の重なり（ほかの管理者が同時に同じ番号を登録した）は一意の索引で止まる（呼ぶ側が UniqueConstraintViolationException を断る）
 */
final class PaperImporter
{
    /**
     * @param array{records: list<array>, existing: list<array>} $analysis
     * @return array{import: ApprovalPaperImport, next: list<string>} 取り込み 1 回分と、実際に進めた次の番号（「住宅事業部 R8-J-035」）
     * @throws PaperImportException 確認のあとでシステムの申請が同じ番号を使った
     */
    public static function apply(array $analysis, int $fiscalYear, string $fileName, User $user): array
    {
        return DB::transaction(function () use ($analysis, $fiscalYear, $fileName, $user): array {
            // 1. 次の番号を進め、ロックのあとでシステムの申請の番号をロック付きの読みで確かめる（ApprovalNumber::reservePaperNumbers）
            $max = [];
            foreach ($analysis['records'] as $r) {
                $max[$r['department_id']] = max($max[$r['department_id']] ?? 0, $r['seq']);
            }
            $reserved = ApprovalNumber::reservePaperNumbers($max, $fiscalYear, array_column($analysis['records'], 'number'));
            if ($reserved['taken'] !== []) {
                throw new PaperImportException('確認の画面を出したあとで、システムの申請が ' . implode('・', $reserved['taken']) . ' を使いました。もう一度アップロードして確かめてください。');
            }
            $departments = ApprovalDepartment::with('company')->whereKey(array_keys($reserved['next']))->get()->keyBy('id');
            $next        = $labels = [];
            foreach ($reserved['next'] as $departmentId => $after) {
                $department                = $departments[$departmentId];
                $next[$department->code]   = $after;
                $labels[]                  = "{$department->name} " . ApprovalNumber::paperNumber($department, $fiscalYear, $after);
            }

            // 3. 取り込み 1 回分と、足す記録
            $import = ApprovalPaperImport::create([
                'file_name'   => $fileName,
                'fiscal_year' => $fiscalYear,
                'added_count' => count($analysis['records']),
                'imported_by' => $user->id,
            ]);
            foreach ($analysis['records'] as $r) {
                ApprovalPaperRecord::create(array_intersect_key($r, array_flip([
                    'department_id', 'fiscal_year', 'seq', 'number', 'decided_on', 'applicant_name', 'subject', 'amount', 'cost', 'profit',
                ])) + ['import_id' => $import->id, 'created_by' => $user->id]);
            }

            // 4. すでにある記録の金額を補う（いまも金額がすべて空のものだけ）
            $filled = 0;
            foreach ($analysis['existing'] as $e) {
                if ($e['fill'] !== null) {
                    $filled += ApprovalPaperRecord::whereKey($e['id'])->whereNull('amount')->whereNull('cost')->whereNull('profit')->update($e['fill']);
                }
            }
            $import->update(['filled_count' => $filled]);

            SettingLogger::record('paper.imported', 'approval_paper_import', $import->id, [], [
                'file_name'    => $fileName,
                'fiscal_year'  => $fiscalYear,
                'added'        => $import->added_count,
                'filled'       => $filled,
                'next_numbers' => $next,
            ]);

            return ['import' => $import, 'next' => $labels];
        });
    }
}
```

`app/Models/ApprovalPaperRecord.php`（変更）

```diff
--- a/app/Models/ApprovalPaperRecord.php
+++ b/app/Models/ApprovalPaperRecord.php
@@ -3,6 +3,7 @@
 namespace App\Models;
 
 use App\Support\Approval\AmountTable;
+use App\Support\Approval\ApprovalFiscalYear;
 use App\Support\Approval\RequestVisibility;
 use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Database\Eloquent\Model;
@@ -42,6 +43,28 @@ protected function casts(): array
         ];
     }
 
+    /** 年度の選択肢のいちばん古い年度（平成元年度。紙の台帳がそれより前にあることは無い） */
+    public const OLDEST_YEAR = 1989;
+
+    /**
+     * 紙の決裁の年度の選択肢（新しい順。今の年度〈会社の中でいちばん新しいもの〉から平成元年度まで。値は期の始まりの年）。取り込みと 1 件ずつの登録・一覧の絞り込み
+     *
+     * @return array<int, string> 年度 => 表示（「R8 年度（2026）」）
+     */
+    public static function yearChoices(): array
+    {
+        $months = ApprovalCompany::query()->pluck('fiscal_start_month')->map(fn (mixed $m) => (int) $m);
+        $month  = $months->isEmpty() ? 5 : $months->min();
+        $newest = $months->isEmpty() ? ApprovalFiscalYear::current(5) : $months->map(fn (int $m) => ApprovalFiscalYear::current($m))->max();
+
+        $years = [];
+        for ($year = $newest; $year >= self::OLDEST_YEAR; $year--) {
+            $years[$year] = ApprovalFiscalYear::eraLabel($year, $month) . " 年度（{$year}）";
+        }
+
+        return $years;
+    }
+
     public function department(): BelongsTo
     {
         return $this->belongsTo(ApprovalDepartment::class, 'department_id');
```

- [ ] **Step 5: 取り込みの画面・確認・確定と、紙の決裁の一覧**

⚠ 断るときの戻り先は取り込みの画面に固定する（`back()` を使わない。Bug #64。走査テストが見る）。1 回限りの鍵はファイルを読み直す前に受け取る（§0.5）。

`app/Http/Controllers/Approval/PaperImportController.php`（新規）

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalPaperRecord;
use App\Support\Approval\PaperImportAnalysis;
use App\Support\Approval\PaperImporter;
use App\Support\Approval\PaperImportException;
use App\Support\OneTimeAction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 過去の紙の決裁の取り込み（画面⑪。要件 11 章・段階5 設計書 §5.9・D6・D7・D20）。決裁の管理者だけ・使い始める前から使える（D11）。
 *
 * 流れ（社員の CSV 一括登録と同じ形）: ファイル（.xls・.xlsx・10MB まで）と年度を選ぶ → 確認の画面（何も書かない）
 *   → 描いた「取り込む」フォーム（hidden のファイルと 1 回限りの鍵・金額の控えの結び付けの選択）で確定
 *   → **確定でファイルを読み直して確かめ直す**（hidden を信用しない）→ 紙の決裁の一覧へ
 *
 * ⚠ 断るときの戻り先は**取り込みの画面に固定する**（`back()` や検証エラーの既定の戻り先を使わない）。確認の画面は POST の応答で、
 *   そこに載ったフォームから送るとリファラーが POST 専用の preview になり、GET で 405 になる（docs/RULES.md Bug #64）。
 * ⚠ 結び付けの選び方の誤り（同じ記録に 2 つの控え・取り込めない先）は、確認の画面をもう一度出して選び直してもらう（アップロードし直させない）。
 * ⚠ 行のエラーの view のキーは `rowErrors`（`errors` にすると Blade の `$errors` を上書きして 500。Bug #53）。
 */
class PaperImportController extends Controller
{
    /** 確定の 2 回目（同じ確認の画面から送り直した）の断り */
    public const ALREADY_SENT = 'この確認の画面からは取り込めません（すでに送ったか、画面が古くなっています）。取り込まれたかは紙の決裁の一覧で確かめられます。取り込み直すときは、台帳をアップロードし直してください。';

    /** 確定で読み直したら、足す記録も金額を補う記録も無かった（ほかの人が先に取り込んだなど） */
    public const NOTHING_TO_IMPORT = '取り込む記録がありません（足す決裁No も、金額を補う記録もありません）。';

    /** Route: GET /approvals/admin/papers/import */
    public function form(): View
    {
        return view('approvals.admin.papers.import', ['years' => ApprovalPaperRecord::yearChoices()]);
    }

    /** Route: POST /approvals/admin/papers/import/preview */
    public function preview(Request $request): View|RedirectResponse
    {
        // ⚠ ルールの配列は行を分けて閉じる（JapaneseValidationMessagesTest の走査。UserImportController と同じ）
        try {
            $validated = $request->validate([
                'ledger_file' => ['required', 'file', 'extensions:xls,xlsx', 'max:10240'],
                'year'        => ['required', 'integer', Rule::in(array_keys(ApprovalPaperRecord::yearChoices()))],
            ], [
                'ledger_file.required'   => '台帳のファイルを選んでください。',
                'ledger_file.extensions' => '台帳のファイル（.xls・.xlsx）を選んでください。',
                'ledger_file.max'        => '台帳のファイルは 10MB までです。',
                'year.in'                => '年度を選び直してください。',
            ], [
                'ledger_file' => '台帳のファイル',
                'year'        => '年度',
            ]);
        } catch (ValidationException $e) {
            // 確認の画面からアップロードし直したときも取り込みの画面へ戻す（Bug #64）
            throw $e->redirectTo(route('approvals.admin.papers.import'));
        }

        $file = $request->file('ledger_file');

        return $this->confirmation((string) $file->get(), (string) $file->getClientOriginalName(), (int) $validated['year'], []);
    }

    /** Route: POST /approvals/admin/papers/import/execute */
    public function execute(Request $request): View|RedirectResponse
    {
        try {
            $validated = $request->validate([
                'ledger_data' => ['required', 'string'],
                'file_name'   => ['required', 'string', 'max:255'],
                'year'        => ['required', 'integer', Rule::in(array_keys(ApprovalPaperRecord::yearChoices()))],
                'links'       => ['nullable', 'array'],
                'links.*'     => ['string', 'max:20'],
            ], [], [
                'ledger_data' => '取り込むファイル',
                'file_name'   => 'ファイルの名前',
                'year'        => '年度',
                'links'       => '金額の控えの結び付け',
                'links.*'     => '金額の控えの結び付け',
            ]);
        } catch (ValidationException $e) {
            // 確定のフォームも確認の画面（POST の応答）に載っている。取り込みの画面へ戻す（Bug #64）
            throw $e->redirectTo(route('approvals.admin.papers.import'));
        }

        // 確定は確認の画面 1 つにつき 1 回だけ（二度押し・再送信）。⚠ ファイルを読み直す前に受け取る（2 回目に「取り込む記録がありません」と
        //   違う理由を出さない）。結び付けを選び直す画面をもう一度出すときは、新しい鍵を描く（confirmationView）
        if (! OneTimeAction::claimFrom($request, 'import_token')) {
            return redirect()->route('approvals.admin.papers.import')->with('error', self::ALREADY_SENT);
        }

        $content = base64_decode($validated['ledger_data'], true);
        if ($content === false) {
            return redirect()->route('approvals.admin.papers.import')->with('error', '取り込むファイルを読み取れませんでした。もう一度アップロードしてください。');
        }

        $year  = (int) $validated['year'];
        $links = $validated['links'] ?? [];

        try {
            // ⚠ 確定でもファイルを読み直して確かめ直す（画面が送ってきた hidden を信用しない）
            $analysis = $this->analyze($content, $year, $links);
        } catch (PaperImportException $e) {
            return redirect()->route('approvals.admin.papers.import')->with('error', $e->getMessage());
        }

        // 結び付けの選び方の誤りは、確認の画面をもう一度出して選び直してもらう
        if ($analysis['linkErrors'] !== []) {
            return $this->confirmationView($analysis, $content, $validated['file_name'], $year, $links);
        }

        if ($analysis['addCount'] === 0 && $analysis['fillCount'] === 0) {
            return redirect()->route('approvals.admin.papers.import')->with('error', self::NOTHING_TO_IMPORT);
        }

        try {
            $result = PaperImporter::apply($analysis, $year, $validated['file_name'], $request->user());
        } catch (PaperImportException $e) {
            return redirect()->route('approvals.admin.papers.import')->with('error', $e->getMessage());
        } catch (UniqueConstraintViolationException) {
            return redirect()->route('approvals.admin.papers.import')->with('error', 'ほかの人が同じ番号の紙の決裁を登録しました。もう一度アップロードして確かめてください。');
        }

        // 次の番号は、確定で実際に進めた値（確認の画面のあとで採番があっても合う）
        $import = $result['import'];
        $next   = $result['next'] === [] ? '' : '次の番号: ' . implode('・', $result['next']) . '。';

        return redirect()->route('approvals.admin.papers.index', ['year' => $year])
            ->with('success', "{$import->added_count} 件を取り込みました（金額を補った記録 {$import->filled_count} 件）。{$next}");
    }

    /** 確認の画面（中身を作って描く） */
    private function confirmation(string $content, string $fileName, int $year, array $links): View|RedirectResponse
    {
        try {
            $analysis = $this->analyze($content, $year, $links);
        } catch (PaperImportException $e) {
            return redirect()->route('approvals.admin.papers.import')->with('error', $e->getMessage());
        }

        return $this->confirmationView($analysis, $content, $fileName, $year, $links);
    }

    private function confirmationView(array $analysis, string $content, string $fileName, int $year, array $links): View
    {
        return view('approvals.admin.papers.import', [
            'years'       => ApprovalPaperRecord::yearChoices(),
            'analysis'    => $analysis,
            'ledgerData'  => base64_encode($content),
            'fileName'    => $fileName,
            'year'        => $year,
            'links'       => $links,
            'importToken' => OneTimeAction::issue(),
        ]);
    }

    /**
     * ファイルの中身を一時ファイルに書いて読む（PhpSpreadsheet はファイルの場所から読む）。読んだら消す
     *
     * @throws PaperImportException
     */
    private function analyze(string $content, int $year, array $links): array
    {
        $path = tempnam(sys_get_temp_dir(), 'paper-ledger');
        try {
            file_put_contents($path, $content);

            return PaperImportAnalysis::analyze($path, $year, $links);
        } finally {
            @unlink($path);
        }
    }
}
```

`app/Http/Controllers/Approval/PaperRecordController.php`（新規）

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalPaperImport;
use App\Models\ApprovalPaperRecord;
use App\Support\Approval\PageNumbers;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * 取り込んだ紙の決裁（画面⑪の一覧。段階5 設計書 §5.10）。決裁の管理者だけ・使い始める前から使える（D11）。
 *
 * 一覧（年度・部門で絞る）と、取り込みの記録（新しい順）を出す。Excel の台帳の取り込みは PaperImportController。
 *
 * ⚠ 絞り込みは GET。選択肢に無い値は外す（手で打った URL。断って前の画面へ戻すと同じ URL を開き直し続ける＝台帳の LedgerFilter と同じ考え）
 */
class PaperRecordController extends Controller
{
    /** 一覧の 1 ページの件数 */
    public const PER_PAGE = 50;

    /** 取り込みの記録を出す数（新しい順） */
    private const IMPORTS_SHOWN = 20;

    /** Route: GET /approvals/admin/papers */
    public function index(Request $request): View
    {
        $departments = ApprovalDepartment::with('company')->get()
            ->sortBy(fn (ApprovalDepartment $d) => [$d->company->sort_order, $d->company_id, $d->sort_order, $d->id])
            ->values();
        $years = ApprovalPaperRecord::yearChoices();

        // 選択肢に無い値は外す（数の形でない・無い年度・無い部門）
        $year       = $this->choice($request->query('year'), array_keys($years));
        $department = $this->choice($request->query('department'), $departments->pluck('id')->all());

        $query = ApprovalPaperRecord::with('department')
            ->when($year !== null, fn ($q) => $q->where('fiscal_year', $year))
            ->when($department !== null, fn ($q) => $q->where('department_id', $department))
            ->orderByDesc('decided_on')
            ->orderBy('number');

        // ページ番号は最後のページまで（Bug #100。手で打った大きな番号で PageNumbers::around() が TypeError の 500・行の無いページは件数と食い違う）
        $total   = $query->count();
        $page    = min(LengthAwarePaginator::resolveCurrentPage(), max(1, (int) ceil($total / self::PER_PAGE)));
        $records = $query->paginate(self::PER_PAGE, ['*'], 'page', $page, $total)->withQueryString();

        // 年度の選択肢は紙の決裁のある年度だけ（平成元年度からの全部を並べると長い）。選んでいる年度は残す
        $recordYears = ApprovalPaperRecord::query()->distinct()->pluck('fiscal_year')->map(fn (mixed $y) => (int) $y)->push($year)->filter()->all();

        return view('approvals.admin.papers.index', [
            'records'     => $records,
            'pages'       => PageNumbers::around($records->currentPage(), $records->lastPage()),
            'years'       => $years,
            'yearChoices' => array_intersect_key($years, array_flip($recordYears)),
            'departments' => $departments,
            'year'        => $year,
            'department'  => $department,
            'imports'     => ApprovalPaperImport::with(['importer', 'undoer'])->latest('id')->limit(self::IMPORTS_SHOWN)->get(),
        ]);
    }

    /**
     * 絞り込みの値（正の整数の形で、選択肢にあるものだけ）
     *
     * @param list<int> $choices
     */
    private function choice(mixed $raw, array $choices): ?int
    {
        if (! is_string($raw) || ! preg_match('/\A[1-9]\d{0,9}\z/', $raw)) {
            return null;
        }

        return in_array((int) $raw, $choices, true) ? (int) $raw : null;
    }
}
```

`resources/views/approvals/admin/papers/import.blade.php`（新規）

```blade
@extends('layouts.app')

@section('title', '紙の決裁台帳の取り込み')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.admin.papers.index') }}" class="hover:text-emerald-600 transition-colors">過去の決裁の取り込み</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">Excel の台帳から取り込む</span>
@endsection

@section('content')
{{--
    紙の決裁台帳（年度ごとの Excel）の取り込み（画面⑪。段階5 設計書 §5.9・§5.10）。決裁の管理者だけ・使い始める前から（D11）。

    流れ: アップロード → この画面に確認を描く（何も書かない）→ 描いた「取り込む」フォームで確定 → 紙の決裁の一覧。
    ⚠ 行のエラーは $analysis['rowErrors']。コントローラから $errors の名前で渡さない（Blade の予約変数。Bug #53）
    ⚠ 成功・失敗の帯はレイアウトが出す（ここで出すと画面に 2 回出る）。$errors だけ各ビューの責任
    ⚠ 年度はファイルの名前（「08年度」）から先に選ぶ（paperLedgerYear）。違う年度を選んでも、日付の年が合わない行はエラーになる
      （PaperDate。違う年度の記録を黙って入れない）
--}}
<div>
    @include('approvals._mail_failure')

    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <h1 class="text-lg font-bold text-gray-900 mb-1">紙の決裁台帳の取り込み</h1>
    <p class="text-[12px] text-gray-500 mb-5 max-w-[720px]">
        紙で回していたころの決裁台帳（年度ごとの Excel）から、決裁No・決裁日・申請者・件名と金額を取り込みます。
        アップロードすると取り込む内容を確認できます。実際に取り込むのは、確認したあとに「取り込む」を押したときだけです。
    </p>

    {{-- 1. 台帳の形 --}}
    <section class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3.5 mb-5">
        <h2 class="text-[13px] font-bold text-emerald-900 mb-1.5">読み取る台帳の形</h2>
        <ul class="list-disc list-outside pl-5 text-[12px] text-emerald-800 space-y-1">
            <li>シートの名前の<strong>先頭の英字</strong>が部門のアルファベットです（「Ｊ・住宅」は J、「ＪＢ・住宅」は JB）。部門の管理に無いアルファベットのシートは取り込みません。</li>
            <li>受付簿は 1 枚の中が<strong>左右 2 段</strong>（ＮＯ・日付・申請者・件名）です。ＮＯ だけの行（まだ使っていない行）は読みません。</li>
            <li>日付は「8.05.13」の形（和暦の年・月・日）です。年は、選んだ年度と月から決めます（期の始まりの月より前の月は次の年）。</li>
            <li>名前に「金額」を含むシートは<strong>金額の控え</strong>です。日付と件名が同じ受付簿の行に、契約金額・工事原価・粗利益を付けます。</li>
        </ul>
    </section>

    {{-- 2. 取り込むとどうなるか --}}
    <section class="rounded-lg border border-gray-200 bg-white px-4 py-3.5 mb-5">
        <h2 class="text-[13px] font-bold text-gray-900 mb-1.5">取り込むとどうなるか</h2>
        <ul class="list-disc list-outside pl-5 text-[12px] text-gray-600 space-y-1">
            <li>決裁No は「年度の和暦-部門のアルファベット-ＮＯ」（R8-J-001 など）になります。決裁台帳で「紙」として申請と一緒に並びます。</li>
            <li>読めない行（日付が無い・年度と合わないなど）は<strong>その行だけ取り込みません</strong>。ほかの行は取り込みます。確認の画面に、どの行がなぜかを出します。</li>
            <li><strong>すでにある決裁No は変えません</strong>（手で直した中身を消さないため）。金額が空の記録にだけ、金額の控えの金額を入れます。</li>
            <li>今年度の台帳を取り込むと、その部門の<strong>次に付く番号</strong>が、取り込んだいちばん大きい番号の次まで進みます（戻りません）。</li>
        </ul>
    </section>

    {{-- 3. アップロード --}}
    <section class="bg-white rounded-lg border border-gray-200 px-4 py-4 mb-5">
        <form method="POST" action="{{ route('approvals.admin.papers.import.preview') }}" enctype="multipart/form-data">
            @csrf
            <h2 class="text-[13px] font-bold text-gray-900 mb-1.5">① 台帳のファイルと年度を選んでアップロードする</h2>
            {{-- ⚠ `<input type="file">` に .form-input を当てない（枠が角張り、ブラウザの選択ボタンの意匠が消える。Bug #18）--}}
            <input type="file" name="ledger_file" accept=".xls,.xlsx" onchange="paperLedgerFileChosen(this)" aria-label="台帳のファイル"
                   style="display:block; width:100%; max-width:520px; padding:8px 12px; font-size:13px; color:#374151; background:white; border:1px solid #d1d5db; border-radius:6px; cursor:pointer; box-sizing:border-box;">
            <p class="text-[12px] text-gray-500 mt-1.5">.xls・.xlsx で 10MB までです。</p>

            <label for="paper-ledger-year" class="block text-[12px] font-semibold text-gray-700 mt-3 mb-1">年度</label>
            <select id="paper-ledger-year" name="year" class="h-9 px-2.5 border border-gray-300 rounded-md text-[13px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer">
                @foreach($years as $value => $label)
                    <option value="{{ $value }}" {{ (int) old('year', $year ?? array_key_first($years)) === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            <p class="text-[12px] text-gray-500 mt-1.5">ファイルを選ぶと、名前の「08年度」から年度を選びます。違うときは選び直してください。</p>

            <button type="submit" class="mt-3 px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">アップロードして内容を確認</button>
        </form>
    </section>

    {{-- 4. 確認（アップロードした直後だけ出る） --}}
    @isset($analysis)
        @include('approvals.admin.papers._import_preview')
    @endisset
</div>
@endsection

{{--
    ファイルの名前の「08年度」（全角の数字も）から年度を選ぶ。n 年度は令和（2018＋n）が選択肢にあればそれ、無ければ平成（1988＋n）。
    どちらも無いとき・名前に年度が無いときは選び直さない。
    ⚠ 説明はこの Blade コメントに書く（script の中のコメントにアットマークで始まる語を書くと Blade が展開して壊す。Bug #30）
--}}
@push('scripts')
    <script>
    function paperLedgerYear(fileName, choices) {
        var name = String(fileName).replace(/[０-９]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0xFEE0); });
        var m = name.match(/(\d{1,2})\s*年度/);
        if (!m) {
            return null;
        }
        var n = parseInt(m[1], 10);
        var reiwa = String(2018 + n);
        var heisei = String(1988 + n);
        if (choices.indexOf(reiwa) !== -1) {
            return reiwa;
        }
        return choices.indexOf(heisei) !== -1 ? heisei : null;
    }
    function paperLedgerFileChosen(input) {
        var select = document.getElementById('paper-ledger-year');
        var file = input.files && input.files[0];
        if (!select || !file) {
            return;
        }
        var choices = Array.prototype.map.call(select.options, function (o) { return o.value; });
        var year = paperLedgerYear(file.name, choices);
        if (year !== null) {
            select.value = year;
        }
    }
    </script>
@endpush
```

`resources/views/approvals/admin/papers/_import_preview.blade.php`（新規）

```blade
{{--
    紙の決裁台帳の取り込みの確認（画面⑪。段階5 設計書 §5.9・D6・D7・D20）。

    必要変数: $analysis（PaperImportAnalysis::analyze() の戻り値）/ $ledgerData / $fileName / $year / $years / $importToken

    ⚠ 取り込まない行（rowErrors・赤）と注意（warnings・黄）は**別のブロック**に、**別の言い方**で出す（Bug #54 ④）
    ⚠ 結び付けの誤り（linkErrors）があっても確定のフォームは描く（選び直して送る。確定はファイルを読み直して確かめ直し、
      まだ誤りがあればこの画面をもう一度出す＝PaperImportController::execute()）
    ⚠ 確定のフォームは「足す・補う記録がある」か「選べる金額の控えの行がある」ときだけ描く（画面が決して送らない POST をテストで
      作らない。Bug #54 ③）。足す・補う記録が無いまま送られたときは、コントローラが断る
    ⚠ 確定は 1 回限りの鍵（import_token）と二度押し止めの部品（_partials._submit_once）。押したボタンは押せなくなるので、
      送る値はボタンに持たせず hidden と select で持つ
--}}
@php
    $choosable = array_values(array_filter($analysis['amounts'], fn (array $a) => ! $a['auto'] && $a['candidates'] !== []));
    $canSubmit = $analysis['addCount'] + $analysis['fillCount'] > 0 || $choosable !== [];
    $kinds     = [
        \App\Support\Approval\PaperLedgerFile::REGISTER => '受付簿',
        \App\Support\Approval\PaperLedgerFile::AMOUNTS  => '金額の控え',
        \App\Support\Approval\PaperLedgerFile::IGNORED  => '読まない',
    ];
    $yen = fn (?int $value) => $value === null ? '—' : \App\Support\Approval\AmountTable::yen($value);
@endphp
<section class="bg-white rounded-lg border border-gray-200">
    <div class="px-4 py-3 border-b border-gray-200">
        <h2 class="text-[14px] font-bold text-gray-900 mb-1">② 取り込む内容の確認</h2>
        <p class="text-[12px] text-gray-500 mb-2 break-all">{{ $fileName }}（{{ $years[$year] ?? $year }}）</p>
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-700 text-[12px] font-semibold">足す {{ $analysis['addCount'] }} 件</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-blue-50 text-blue-700 text-[12px] font-semibold">金額を補う {{ $analysis['fillCount'] }} 件</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-gray-100 text-gray-700 text-[12px]">すでにある {{ count($analysis['existing']) }} 件</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-red-50 text-red-700 text-[12px] font-semibold">取り込まない行 {{ count($analysis['rowErrors']) }} 件</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-amber-50 text-amber-800 text-[12px] font-semibold">注意 {{ count($analysis['warnings']) }} 件</span>
        </div>
    </div>

    {{-- シートごとの件数 --}}
    <div class="scroll-hint at-start border-b border-gray-200">
        <div class="scroll-hint-inner">
            <table class="w-full min-w-[720px] border-collapse">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">シート</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">種類</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">部門</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">読んだ行</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">足す</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">すでにある</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">取り込まない</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">結び付いた</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($analysis['sheets'] as $sheet)
                        <tr>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-900">{{ $sheet['title'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $kinds[$sheet['kind']] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">
                                @if($sheet['kind'] === \App\Support\Approval\PaperLedgerFile::REGISTER)
                                    {{ $sheet['department'] ?? "（{$sheet['code']} の部門がありません）" }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums">{{ $sheet['rows'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums">{{ $sheet['added'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums">{{ $sheet['existing'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-right tabular-nums {{ $sheet['errors'] > 0 ? 'text-red-700 font-semibold' : 'text-gray-700' }}">{{ $sheet['errors'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums">{{ $sheet['kind'] === \App\Support\Approval\PaperLedgerFile::AMOUNTS ? $sheet['linked'] : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>{{-- /scroll-hint-inner --}}
        <div class="scroll-hint-text">← スクロールできます →</div>
    </div>{{-- /scroll-hint --}}

    {{-- 次の番号（今年度の部門だけ。D7） --}}
    @if($analysis['nextNumbers'] !== [])
        <div class="px-4 py-3 border-b border-gray-200">
            <p class="text-[13px] font-semibold text-gray-900 mb-1">取り込むと、今年度の次に付く番号が進みます</p>
            <ul class="space-y-0.5">
                @foreach($analysis['nextNumbers'] as $next)
                    <li class="text-[12px] text-gray-700">{{ $next['department'] }}: <span class="font-mono">{{ $next['before'] }}</span> → <span class="font-mono font-semibold">{{ $next['after'] }}</span></li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 取り込まない行（その行だけ。ほかの行は取り込む。D20） --}}
    @if($analysis['rowErrors'] !== [])
        <div class="px-4 py-3 border-b border-gray-200 bg-red-50">
            <p class="text-[13px] font-semibold text-red-800 mb-1.5">取り込まない行が {{ count($analysis['rowErrors']) }} 件あります。ほかの行は取り込みます。取り込むときは、Excel を直してもう一度アップロードしてください。</p>
            <ul class="space-y-0.5 max-h-[240px] overflow-y-auto">
                @foreach($analysis['rowErrors'] as $rowError)
                    <li class="text-[12px] text-red-700">取り込まない {{ $rowError['where'] }}: {{ $rowError['message'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 注意（取り込みは止めない） --}}
    @if($analysis['warnings'] !== [])
        <div class="px-4 py-3 border-b border-gray-200 bg-amber-50">
            <p class="text-[13px] font-semibold text-amber-900 mb-1.5">確認してください（この {{ count($analysis['warnings']) }} 件は取り込みを止めません）。</p>
            <ul class="space-y-0.5 max-h-[240px] overflow-y-auto">
                @foreach($analysis['warnings'] as $warning)
                    <li class="text-[12px] text-amber-800">⚠ {{ $warning['where'] }}: {{ $warning['message'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 結び付けの選び方の誤り（選び直す） --}}
    @if($analysis['linkErrors'] !== [])
        <div class="px-4 py-3 border-b border-gray-200 bg-red-50" role="alert">
            <p class="text-[13px] font-semibold text-red-800 mb-1.5">金額の控えの結び付けを選び直してください。</p>
            <ul class="space-y-0.5">
                @foreach($analysis['linkErrors'] as $linkError)
                    <li class="text-[12px] text-red-700">{{ $linkError }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 足す記録 --}}
    <div class="px-4 pt-3 pb-1.5">
        <h3 class="text-[13px] font-bold text-gray-900">足す記録（{{ $analysis['addCount'] }} 件）</h3>
    </div>
    <div class="scroll-hint at-start">
        <div class="scroll-hint-inner">
            <table class="w-full min-w-[960px] border-collapse">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">決裁No</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">決裁日</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">部門</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">申請者</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 min-w-[16rem]">件名</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">契約金額</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">工事原価</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">粗利益</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 min-w-[10rem]">台帳の場所</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($analysis['records'] as $record)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] font-mono text-gray-900 whitespace-nowrap">{{ $record['number'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ str_replace('-', '/', $record['decided_on']) }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $record['department'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $record['applicant_name'] ?? '—' }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-900 break-words">{{ $record['subject'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums whitespace-nowrap">{{ $yen($record['amount']) }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums whitespace-nowrap">{{ $yen($record['cost']) }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums whitespace-nowrap">{{ $yen($record['profit']) }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[12px] text-gray-500">{{ $record['where'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-6 text-center text-[13px] text-gray-500">足す記録はありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>{{-- /scroll-hint-inner --}}
        <div class="scroll-hint-text">← スクロールできます →</div>
    </div>{{-- /scroll-hint --}}

    {{-- すでにある記録（中身は変えない。金額が空なら補う。D6） --}}
    @if($analysis['existing'] !== [])
        <div class="px-4 py-3 border-t border-gray-200">
            <h3 class="text-[13px] font-bold text-gray-900 mb-1">すでにある決裁No（{{ count($analysis['existing']) }} 件。中身は変えません）</h3>
            <ul class="space-y-0.5 max-h-[240px] overflow-y-auto">
                @foreach($analysis['existing'] as $existing)
                    <li class="text-[12px] text-gray-700">
                        <span class="font-mono">{{ $existing['number'] }}</span>（{{ $existing['where'] }}）:
                        @if($existing['fill'] !== null)
                            <span class="text-blue-700 font-semibold">金額を補います（契約金額 {{ $yen($existing['fill']['amount']) }}・工事原価 {{ $yen($existing['fill']['cost']) }}・粗利益 {{ $yen($existing['fill']['profit']) }}）</span>
                        @else
                            変えません
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 確定（金額の控えの結び付けの選択も、このフォームで送る） --}}
    @if($canSubmit)
        @include('_partials._submit_once')
        <form method="POST" action="{{ route('approvals.admin.papers.import.execute') }}"
              x-data="submitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="onPageShow($event)">
            @csrf
            <input type="hidden" name="ledger_data" value="{{ $ledgerData }}">
            <input type="hidden" name="file_name" value="{{ $fileName }}">
            <input type="hidden" name="year" value="{{ $year }}">
            <input type="hidden" name="import_token" value="{{ $importToken }}">
            @include('approvals.admin.papers._import_amounts')
            <div class="px-4 py-4 border-t border-gray-200">
                <p class="text-[12px] text-gray-600 mb-2.5">{{ $analysis['addCount'] }} 件を足し、{{ $analysis['fillCount'] }} 件の金額を補います（金額の控えの選び直しも、このボタンで確かめます）。</p>
                <button type="submit" :disabled="submitting"
                        class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">取り込む</button>
                <span role="status" x-text="submitting ? '取り込んでいます…' : ''" style="display: inline-block; margin-left: 12px; font-size: 13px; color: #374151;"></span>
            </div>
        </form>
    @else
        @include('approvals.admin.papers._import_amounts')
        <div class="px-4 py-4 border-t border-gray-200">
            <p class="text-[13px] text-red-700">取り込む記録がありません（足す決裁No も、金額を補う記録もありません）。</p>
        </div>
    @endif
</section>
```

`resources/views/approvals/admin/papers/_import_amounts.blade.php`（新規）

```blade
{{--
    紙の決裁台帳の取り込みの確認のうち、金額の控えの行（画面⑪。段階5 設計書 §5.9）。_import_preview が読む。
    必要変数: $analysis / $choosable（結び付け先を選べる行）/ $yen（金額の表示）
    ⚠ 確定のフォームがあるときはその中で読む（選んだ結び付け links[シートの番号:行] を確定で送る）。無いときは外で読む（選べる行も無い）
--}}
@if($analysis['amounts'] !== [])
    <div class="px-4 pt-3 pb-1.5 border-t border-gray-200">
        <h3 class="text-[13px] font-bold text-gray-900">金額の控え（{{ count($analysis['amounts']) }} 行）</h3>
        @if($choosable !== [])
            <p class="text-[12px] text-amber-800 mt-0.5">結び付け先が 1 つに決まらない行が {{ count($choosable) }} 行あります。結び付ける決裁No を選んでください（選ばなければ結び付けません）。</p>
        @endif
    </div>
    <div class="scroll-hint at-start">
        <div class="scroll-hint-inner">
            <table class="w-full min-w-[960px] border-collapse">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 min-w-[10rem]">台帳の場所</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">日付</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">申請者</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 min-w-[14rem]">件名</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">契約金額</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">工事原価</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">粗利益</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">結び付け先</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($analysis['amounts'] as $amount)
                        <tr class="{{ ! $amount['auto'] && $amount['candidates'] !== [] ? 'bg-amber-50' : '' }}">
                            <td class="px-4 py-2 border-b border-gray-100 text-[12px] text-gray-500">{{ $amount['where'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $amount['date'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $amount['applicant'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-900 break-words">{{ $amount['subject'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums whitespace-nowrap">{{ $yen($amount['amount']) }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums whitespace-nowrap">{{ $yen($amount['cost']) }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums whitespace-nowrap">{{ $yen($amount['profit']) }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700">
                                @if($amount['auto'])
                                    <span class="font-mono">{{ $amount['linked'] }}</span>（自動）
                                @elseif($amount['empty'])
                                    <span class="text-gray-500">金額がありません（結び付けません）</span>
                                @elseif($amount['candidates'] === [])
                                    <span class="text-gray-500">結び付けられる記録がありません</span>
                                @else
                                    <select name="links[{{ $amount['key'] }}]" aria-label="{{ $amount['where'] }} の結び付け先"
                                            class="h-8 max-w-[360px] px-2 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer">
                                        <option value="{{ \App\Support\Approval\PaperImportAnalysis::NO_LINK }}">結び付けない</option>
                                        @foreach($amount['candidates'] as $candidate)
                                            <option value="{{ $candidate['number'] }}" {{ $amount['linked'] === $candidate['number'] ? 'selected' : '' }}>{{ $candidate['label'] }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>{{-- /scroll-hint-inner --}}
        <div class="scroll-hint-text">← スクロールできます →</div>
    </div>{{-- /scroll-hint --}}
@endif
```

`resources/views/approvals/admin/papers/index.blade.php`（新規）

```blade
@extends('layouts.app')

@section('title', '過去の決裁の取り込み')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">過去の決裁の取り込み</span>
@endsection

{{-- 取り込んだ紙の決裁の一覧（画面⑪。段階5 設計書 §5.10）。決裁の管理者だけ・使い始める前から（D11）。
     ⚠ 決裁日は日付（時刻なし）なので ->format() のまま。取り込んだ日時・取り消した日時は保存した日時なので JapanTime::format（Bug #61）
     ⚠ プルダウンは変えた瞬間に送る（CLAUDE.md の即時フィルタ） --}}
@section('content')
<div>
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

    <h1 class="text-lg font-bold text-gray-900 mb-1">過去の決裁の取り込み</h1>
    <p class="text-[12px] text-gray-500 mb-4 max-w-[720px]">紙の決裁台帳（年度ごとの Excel）から取り込んだ決裁です。決裁台帳で「紙」として申請と一緒に並び、関連する決裁No から開けます。回覧の操作はできません。</p>

    <div class="flex flex-wrap gap-2 mb-4">
        <a href="{{ route('approvals.admin.papers.import') }}" class="inline-flex items-center h-9 px-4 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold">Excel の台帳から取り込む</a>
    </div>

    <form id="paper-filter" method="GET" action="{{ route('approvals.admin.papers.index') }}"
          class="flex flex-wrap items-end gap-3 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-3">
        <label class="block text-[12px] text-gray-600">年度
            <select name="year" onchange="document.getElementById('paper-filter').submit()" class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer">
                <option value="">すべて</option>
                @foreach($yearChoices as $value => $label)
                    <option value="{{ $value }}" {{ $year === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-[12px] text-gray-600">部門
            <select name="department" onchange="document.getElementById('paper-filter').submit()" class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer">
                <option value="">すべて</option>
                @foreach($departments as $item)
                    <option value="{{ $item->id }}" {{ $department === $item->id ? 'selected' : '' }}>{{ $item->company->name }} / {{ $item->name }}（{{ $item->code }}）</option>
                @endforeach
            </select>
        </label>
        @if($year !== null || $department !== null)
            <a href="{{ route('approvals.admin.papers.index') }}" class="text-[12px] text-gray-500 hover:text-emerald-600 transition-colors pb-1.5">条件を消す</a>
        @endif
    </form>

    <section class="bg-white rounded-lg border border-gray-200 mb-6">
        <div class="px-4 py-2.5 border-b border-gray-200">
            <p class="text-[13px] text-gray-700"><span class="font-semibold tabular-nums">{{ number_format($records->total()) }}</span> 件</p>
        </div>
        @if($records->isEmpty())
            <p class="px-4 py-8 text-center text-[13px] text-gray-400">紙の決裁はありません。</p>
        @else
            <div class="scroll-hint at-start">
                <div class="scroll-hint-inner">
                    <table class="w-full min-w-[760px] border-collapse">
                        <thead>
                            <tr>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">決裁No</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">決裁日</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">部門</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">申請者</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">件名</th>
                                <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">金額</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($records as $record)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-mono text-gray-700 whitespace-nowrap">{{ $record->number }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $record->decided_on->format('Y/m/d') }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $record->department->name }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $record->applicant_name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-900 break-words">{{ $record->subject }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums whitespace-nowrap">{{ \App\Support\Approval\AmountTable::yen($record->amount) ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>{{-- /scroll-hint-inner --}}
                <div class="scroll-hint-text">← スクロールできます →</div>
            </div>{{-- /scroll-hint --}}
            @include('approvals._pager', ['paginator' => $records, 'pages' => $pages])
        @endif
    </section>

    {{-- 取り込みの記録（新しい順） --}}
    <section class="bg-white rounded-lg border border-gray-200">
        <h2 class="px-4 py-2.5 border-b border-gray-200 text-[13px] font-bold text-gray-900">取り込みの記録</h2>
        @if($imports->isEmpty())
            <p class="px-4 py-6 text-center text-[13px] text-gray-400">まだ取り込んでいません。</p>
        @else
            <div class="scroll-hint at-start">
                <div class="scroll-hint-inner">
                    <table class="w-full min-w-[760px] border-collapse">
                        <thead>
                            <tr>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">取り込んだ日時</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">ファイル</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">年度</th>
                                <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">足した</th>
                                <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">金額を補った</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">取り込んだ人</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">取り消し</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($imports as $import)
                                <tr>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ \App\Support\JapanTime::format($import->created_at, 'Y/m/d H:i') }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 break-all">{{ $import->file_name }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $years[$import->fiscal_year] ?? $import->fiscal_year }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums">{{ number_format($import->added_count) }} 件</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums">{{ number_format($import->filled_count) }} 件</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $import->importer?->name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">
                                        @if($import->undone_at !== null)
                                            {{ \App\Support\JapanTime::format($import->undone_at, 'Y/m/d H:i') }} に {{ $import->undoer?->name ?? '—' }} が取り消し（{{ number_format((int) $import->undone_count) }} 件を消した）
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>{{-- /scroll-hint-inner --}}
                <div class="scroll-hint-text">← スクロールできます →</div>
            </div>{{-- /scroll-hint --}}
        @endif
    </section>
</div>
@endsection
```

- [ ] **Step 6: ルートと入口**（使い始める前から・決裁の管理者だけ。`/papers/import*` を `/papers/{approvalPaperRecord}` より前に）

`routes/approval.php`（変更）

```diff
--- a/routes/approval.php
+++ b/routes/approval.php
@@ -6,6 +6,8 @@
 use App\Http\Controllers\Approval\LedgerController;
 use App\Http\Controllers\Approval\NoticeController;
 use App\Http\Controllers\Approval\OrganizationController;
+use App\Http\Controllers\Approval\PaperImportController;
+use App\Http\Controllers\Approval\PaperRecordController;
 use App\Http\Controllers\Approval\RelatedNumberController;
 use App\Http\Controllers\Approval\RequestActionController;
 use App\Http\Controllers\Approval\RequestAttachmentController;
@@ -91,6 +93,13 @@
     Route::post('/holidays', [HolidayController::class, 'store'])->name('holidays.store');
     Route::put('/holidays/{approvalHoliday}', [HolidayController::class, 'update'])->name('holidays.update');
     Route::delete('/holidays/{approvalHoliday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');
+
+    // 過去の紙の決裁の取り込み（画面⑪。段階5 設計書 §5.9・§5.10）。使い始める前から使える（使い始める日までに取り込む。D11）
+    // ⚠ `/papers/import*` を `/papers/{approvalPaperRecord}` より前に置く（登録順がマッチの優先順）
+    Route::get('/papers', [PaperRecordController::class, 'index'])->name('papers.index');
+    Route::get('/papers/import', [PaperImportController::class, 'form'])->name('papers.import');
+    Route::post('/papers/import/preview', [PaperImportController::class, 'preview'])->name('papers.import.preview');
+    Route::post('/papers/import/execute', [PaperImportController::class, 'execute'])->name('papers.import.execute');
 });
 
 /*
```

`resources/views/layouts/partials/sidebar.blade.php`（変更）

```diff
--- a/resources/views/layouts/partials/sidebar.blade.php
+++ b/resources/views/layouts/partials/sidebar.blade.php
@@ -162,6 +162,7 @@ class="inline-flex items-center gap-1 px-2 py-1 rounded-md border border-gray-30
             <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
             <x-sidebar-item :href="route('approvals.admin.types.index')" label="申請種類の管理" :active="request()->routeIs('approvals.admin.types.*')" />
             <x-sidebar-item :href="route('approvals.admin.holidays.index')" label="催促の設定" :active="request()->routeIs('approvals.admin.holidays.*')" />
+            <x-sidebar-item :href="route('approvals.admin.papers.index')" label="過去の決裁の取り込み" :active="request()->routeIs('approvals.admin.papers.*')" />
             @if($approvalsLaunched)
                 <x-sidebar-item :href="route('approvals.admin.requests.index')" label="進行中の申請の管理" :active="request()->routeIs('approvals.admin.requests.*')" />
             @endif
@@ -482,6 +483,7 @@ class="fixed inset-y-0 left-0 z-30 w-[260px] bg-white overflow-y-auto pt-4 pb-6
             <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
             <x-sidebar-item :href="route('approvals.admin.types.index')" label="申請種類の管理" :active="request()->routeIs('approvals.admin.types.*')" />
             <x-sidebar-item :href="route('approvals.admin.holidays.index')" label="催促の設定" :active="request()->routeIs('approvals.admin.holidays.*')" />
+            <x-sidebar-item :href="route('approvals.admin.papers.index')" label="過去の決裁の取り込み" :active="request()->routeIs('approvals.admin.papers.*')" />
             @if($approvalsLaunched)
                 <x-sidebar-item :href="route('approvals.admin.requests.index')" label="進行中の申請の管理" :active="request()->routeIs('approvals.admin.requests.*')" />
             @endif
```

`resources/views/layouts/partials/sidebar_approval.blade.php`（変更）

```diff
--- a/resources/views/layouts/partials/sidebar_approval.blade.php
+++ b/resources/views/layouts/partials/sidebar_approval.blade.php
@@ -41,6 +41,7 @@ class="inline-flex items-center gap-1 px-2 py-1 rounded-md border border-gray-30
             <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
             <x-sidebar-item :href="route('approvals.admin.types.index')" label="申請種類の管理" :active="request()->routeIs('approvals.admin.types.*')" />
             <x-sidebar-item :href="route('approvals.admin.holidays.index')" label="催促の設定" :active="request()->routeIs('approvals.admin.holidays.*')" />
+            <x-sidebar-item :href="route('approvals.admin.papers.index')" label="過去の決裁の取り込み" :active="request()->routeIs('approvals.admin.papers.*')" />
             @if($approvalsLaunched)
                 <x-sidebar-item :href="route('approvals.admin.requests.index')" label="進行中の申請の管理" :active="request()->routeIs('approvals.admin.requests.*')" />
             @endif
@@ -89,6 +90,7 @@ class="p-1 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transi
         <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
         <x-sidebar-item :href="route('approvals.admin.types.index')" label="申請種類の管理" :active="request()->routeIs('approvals.admin.types.*')" />
         <x-sidebar-item :href="route('approvals.admin.holidays.index')" label="催促の設定" :active="request()->routeIs('approvals.admin.holidays.*')" />
+        <x-sidebar-item :href="route('approvals.admin.papers.index')" label="過去の決裁の取り込み" :active="request()->routeIs('approvals.admin.papers.*')" />
         @if($approvalsLaunched)
             <x-sidebar-item :href="route('approvals.admin.requests.index')" label="進行中の申請の管理" :active="request()->routeIs('approvals.admin.requests.*')" />
         @endif
```

`resources/views/approvals/home.blade.php`（変更）

```diff
--- a/resources/views/approvals/home.blade.php
+++ b/resources/views/approvals/home.blade.php
@@ -43,6 +43,7 @@
                 <a href="{{ route('approvals.admin.organization.index') }}" class="text-emerald-600 hover:underline">部門の管理</a>
                 <a href="{{ route('approvals.admin.types.index') }}" class="text-emerald-600 hover:underline">申請種類の管理</a>
                 <a href="{{ route('approvals.admin.holidays.index') }}" class="text-emerald-600 hover:underline">催促の設定</a>
+                <a href="{{ route('approvals.admin.papers.index') }}" class="text-emerald-600 hover:underline">過去の決裁の取り込み</a>
             @endif
         </div>
     </div>
```

`resources/views/approvals/home-launched.blade.php`（変更）

```diff
--- a/resources/views/approvals/home-launched.blade.php
+++ b/resources/views/approvals/home-launched.blade.php
@@ -112,6 +112,7 @@
             <a href="{{ route('approvals.admin.organization.index') }}" class="text-emerald-600 hover:underline">部門の管理</a>
             <a href="{{ route('approvals.admin.types.index') }}" class="text-emerald-600 hover:underline">申請種類の管理</a>
             <a href="{{ route('approvals.admin.holidays.index') }}" class="text-emerald-600 hover:underline">催促の設定</a>
+            <a href="{{ route('approvals.admin.papers.index') }}" class="text-emerald-600 hover:underline">過去の決裁の取り込み</a>
         @endif
     </div>
 </div>
```

（差分のファイルを使うなら、Step 3〜6 をまとめて: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/5b/patches/0005-*.patch`）

- [ ] **Step 7: テストを流して通ることを確かめる**（Step 2 と、取り込みのコントローラの走査・部門の管理と採番のテスト）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5 tests/Feature/Approval/Phase2/LaunchGateTest.php tests/Feature/Approval/Phase2/ApprovalNumberTest.php tests/Feature/Approval/Phase2/OrganizationPhase2Test.php tests/Feature/Approval/ApprovalSidebarTest.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase3/MailFailureBannerTest.php tests/Feature/SubmitOnceTest.php tests/Feature/ImportControllerReturnPathScanTest.php tests/Feature/ImportControllerValidationRedirectScanTest.php tests/Feature/ImportControllerOneTimeKeyScanTest.php tests/Feature/JapaneseValidationMessagesTest.php
```

Expected: `OK`（赤なし）

- [ ] **Step 8: 全件を流す**

Expected: `OK (4149 tests, 38918 assertions)`

- [ ] **Step 9: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/ApprovalNumber.php app/Models/ApprovalPaperRecord.php app/Http/Controllers/Approval/OrganizationController.php resources/views/approvals/admin/organization.blade.php app/Support/Approval/PaperImporter.php app/Http/Controllers/Approval/PaperImportController.php app/Http/Controllers/Approval/PaperRecordController.php resources/views/approvals/admin/papers routes/approval.php resources/views/layouts/partials/sidebar.blade.php resources/views/layouts/partials/sidebar_approval.blade.php resources/views/approvals/home.blade.php resources/views/approvals/home-launched.blade.php tests/Feature/Approval/Phase5/PaperImportScreenTest.php tests/Feature/Approval/Phase5/PaperNumberTest.php tests/Feature/Approval/Phase5/PaperListTest.php tests/Feature/Approval/Phase2/LaunchGateTest.php tests/Feature/Approval/ApprovalSidebarTest.php tests/Feature/Approval/Phase3/MailFailureBannerTest.php tests/Feature/SubmitOnceTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 紙の決裁台帳を確認してから取り込み次の番号を進める

⑪ で年度ごとの台帳をアップロードし、確認の画面（シートごとの件数・
取り込まない行・注意・金額の控えの結び付け・進む次の番号）を見てから
確定する。確定はファイルを読み直して確かめ直し、1 回限りの鍵で二度押しを
止め、今年度の部門の次の番号を紙の番号の次まで進める（戻さない。段階5
設計書 §5.9〜§5.11・D6・D7・D20）。紙の決裁の一覧と入口も足した。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 6: 紙の決裁の 1 件ずつの登録・直し・削除と、取り込み 1 回分の取り消し

決裁の管理者が紙の決裁を 1 件ずつ登録・直し・削除でき、取り込みを間違えたときは 1 回分をまとめて取り消せる（その回に足した記録を、そのあと手で直したものも含めて消す。件数を見せて確かめる）。次の番号は進めるだけで戻さない（設計書 §5.10・§5.11・D9・§0.7）。

**Files:**
- Create: `resources/views/approvals/admin/papers/form.blade.php`
- Modify: `app/Http/Controllers/Approval/PaperRecordController.php`・`app/Http/Controllers/Approval/PaperImportController.php`・`resources/views/approvals/admin/papers/index.blade.php`・`resources/views/approvals/admin/papers/import.blade.php`・`routes/approval.php`
- Test: Create `tests/Feature/Approval/Phase5/PaperRecordCrudTest.php`／Modify `tests/Feature/Approval/ApprovalAdminGateTest.php`・`tests/Feature/ClockReadScanTest.php`

**Interfaces:**
- Consumes: Task 5 の `ApprovalNumber::reservePaperNumbers()`・`ApprovalPaperRecord::yearChoices()`・`PaperImportAnalysis::SEQ_MAX`・`APPLICANT_MAX`・`SUBJECT_MAX`。既存の `FormInput::digits()`・`FormInput::trim()`・`FormInput::unifyNewlines()`・`SettingLogger::recordChange()`・`JapanTime::today()`・`approvals._submit_once`（`approvalSubmitOnce()`）
- Produces: ルート `approvals.admin.papers.create`（GET `…/papers/create`）・`papers.store`（POST `…/papers`）・`papers.edit`（GET `…/papers/{approvalPaperRecord}/edit`）・`papers.update`（PUT）・`papers.destroy`（DELETE）・`papers.imports.undo`（POST `…/papers/imports/{approvalPaperImport}/undo`。`count_shown`・`edited_shown`）。`PaperRecordController::NOTE_MAX`（2,000）
- Produces: 設定の記録 `paper.created`・`paper.updated`・`paper.deleted`・`paper.import_undone`

**差分の大きさ:** 9 ファイル・+865 / −14 行（差分のファイル `0006-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase5/PaperRecordCrudTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalDepartment;
use App\Models\ApprovalPaperImport;
use App\Models\ApprovalPaperRecord;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use App\Support\Approval\ApprovalNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ComparesJsonColumns;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 紙の決裁の 1 件ずつの登録・直し・削除と、取り込み 1 回分の取り消し（画面⑪。段階5 設計書 §5.10・§5.11・D7・D9）。
 *
 * ⚠ 画面が描いたフォームを分解して送り返す（送り先・HTTP メソッド・選択肢を自前で組まない。Bug #47）。
 *   select は先頭以外の値で往復させる（ParsesForms の注意）
 */
class PaperRecordCrudTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ComparesJsonColumns;
    use ParsesForms;

    private array $w;

    private ApprovalDepartment $estate;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00', 'UTC'));   // R8 年度（5 月始まり）
        $this->w      = $this->approvalWorld();
        $this->estate = $this->approvalDepartment($this->w['company'], ['name' => 'ミツワ不動産', 'code' => 'M', 'sort_order' => 9]);
        $this->admin  = $this->approvalAdmin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function storeForm(): array
    {
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.create'))->assertOk()->getContent();

        return $this->parseForm($html, 'action="' . route('approvals.admin.papers.store') . '"');
    }

    private function editForm(ApprovalPaperRecord $record): array
    {
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.edit', $record))->assertOk()->getContent();

        return $this->parseForm($html, 'action="' . route('approvals.admin.papers.update', $record) . '"');
    }

    /** フォームを入れた値で送る（ブラウザと同じく、フォームの画面から） */
    private function send(array $form, array $fields, string $from): TestResponse
    {
        foreach (array_keys($fields) as $name) {
            $this->assertArrayHasKey($name, $form['fields'], "フォームに {$name} が無い");
        }

        return $this->actingAs($this->admin)->from($from)->post($form['action'], array_merge($form['fields'], $fields));
    }

    private function fields(array $overrides = []): array
    {
        return array_merge([
            'fiscal_year'    => '2026',
            'department_id'  => (string) $this->estate->id,
            'seq'            => '21',
            'decided_on'     => '2026-06-10',
            'applicant_name' => '丁田',
            'subject'        => '見本倉庫購入',
            'amount'         => '５０，０００，０００円',
            'cost'           => '42,000,000',
            'profit'         => '8000000',
            'note'           => "原本は総務の棚\r\n2 段目",
        ], $overrides);
    }

    // --- 登録 ---

    public function test_a_paper_record_is_registered_from_the_form(): void
    {
        $response = $this->send($this->storeForm(), $this->fields(), route('approvals.admin.papers.create'));

        $this->assertSame(route('approvals.admin.papers.index', ['year' => 2026]), $response->headers->get('Location'));
        $this->actingAs($this->admin)->get($response->headers->get('Location'))->assertSee('R8-M-021 を登録しました。');

        $record = ApprovalPaperRecord::sole();
        $this->assertSame(
            ['R8-M-021', $this->estate->id, 2026, 21, '2026-06-10', '丁田', '見本倉庫購入', 50000000, 42000000, 8000000, "原本は総務の棚\n2 段目", null, $this->admin->id, null],
            [$record->number, $record->department_id, $record->fiscal_year, $record->seq, $record->decided_on->format('Y-m-d'), $record->applicant_name, $record->subject,
                $record->amount, $record->cost, $record->profit, $record->note, $record->import_id, $record->created_by, $record->updated_by]
        );
        $this->assertSame(22, ApprovalNumber::currentState($this->estate->fresh('company'))['next'], '今年度の番号は次の番号を進める（D7）');

        $log = ApprovalSettingLog::where('action', 'paper.created')->sole();
        $this->assertSame([$this->admin->id, 'approval_paper_record', $record->id], [$log->actor_user_id, $log->target_type, $log->target_id]);
        $this->assertSame(['R8-M-021', '2026', $this->estate->id, 21, '2026-06-10'], [$log->new_values['number'], (string) $log->new_values['fiscal_year'], $log->new_values['department_id'], $log->new_values['seq'], $log->new_values['decided_on']]);
    }

    public function test_the_optional_fields_can_be_left_empty_and_a_past_year_keeps_the_numbering(): void
    {
        $this->send($this->storeForm(), $this->fields(['fiscal_year' => '2018', 'decided_on' => '2018-06-10', 'applicant_name' => '　', 'amount' => '', 'cost' => '', 'profit' => '-300,000', 'note' => '']), route('approvals.admin.papers.create'));

        $record = ApprovalPaperRecord::sole();
        $this->assertSame(['H30-M-021', null, null, null, -300000, null], [$record->number, $record->applicant_name, $record->amount, $record->cost, $record->profit, $record->note]);
        $this->assertSame(1, ApprovalNumber::currentState($this->estate->fresh('company'))['next'], '過去の年度は次の番号に関わらない');
    }

    /**
     * 決裁日は選んだ年度の期間の中で、今日より前の日だけ（今日より先・まだ始まっていない会社の年度の番号を入れると、次の番号を進められずに、
     * あとでシステムの採番と重なる。点検 M-2）
     */
    public function test_a_decided_date_outside_the_year_or_after_today_is_refused(): void
    {
        foreach (['2026-04-30' => '決裁日 2026/04/30 は R8 年度の期間（2026/05/01〜2027/04/30）の外です。年度か決裁日を直してください。', '2026-10-09' => '決裁日に今日より先の日付は入れられません。'] as $day => $message) {
            $response = $this->send($this->storeForm(), $this->fields(['decided_on' => $day]), route('approvals.admin.papers.create'));

            $this->assertSame(route('approvals.admin.papers.create'), $response->headers->get('Location'), $day);
            $this->actingAs($this->admin)->get(route('approvals.admin.papers.create'))->assertSee($message);
        }
        $this->assertSame(0, ApprovalPaperRecord::count());
        $this->assertSame(1, ApprovalNumber::currentState($this->estate->fresh('company'))['next']);
    }

    public function test_bad_values_are_refused_with_the_input_kept(): void
    {
        $response = $this->send($this->storeForm(), $this->fields(['seq' => '0', 'amount' => '１２ａ', 'decided_on' => '2026-02-30', 'subject' => '']), route('approvals.admin.papers.create'));

        $this->assertSame(route('approvals.admin.papers.create'), $response->headers->get('Location'));
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.create'))->getContent();
        foreach (['ＮＯ は 1 から 99,999 までで入力してください。', '金額「12a」は円の数で入力してください（マイナスも入れられます）。', '決裁日は正しい日付で入力してください。', '件名を入力してください。'] as $message) {
            $this->assertStringContainsString(e($message), $html);
        }
        $this->assertStringContainsString('value="丁田"', $html, '入れた中身を残す');
        $this->assertStringContainsString('<option value="' . $this->estate->id . '" selected>', $html, '選んだ部門を残す');
        $this->assertSame(0, ApprovalPaperRecord::count());
    }

    /** システムの申請の番号・ほかの紙の決裁の番号とは重ねられない。断ったら次の番号も進めない */
    public function test_a_number_already_in_use_is_refused(): void
    {
        $draft = $this->draftFor($this->w);
        DB::table('approval_requests')->where('id', $draft->id)->update(['number' => 'R8-M-021', 'number_department_id' => $this->estate->id]);
        $this->paperRecord($this->estate, ['seq' => 22]);
        ApprovalNumber::setNext($this->estate->fresh('company'), 23);

        foreach (['21' => 'R8-M-021 はシステムの申請がすでに使っています。ＮＯ を確かめてください。', '22' => 'R8-M-022 の紙の決裁はすでにあります。ＮＯ を確かめてください。'] as $seq => $message) {
            $response = $this->send($this->storeForm(), $this->fields(['seq' => (string) $seq]), route('approvals.admin.papers.create'));
            $this->assertSame(route('approvals.admin.papers.create'), $response->headers->get('Location'));
            $this->actingAs($this->admin)->get(route('approvals.admin.papers.create'))->assertSee($message);
        }
        $this->assertSame(1, ApprovalPaperRecord::count());

        // 次の番号より大きい番号で断られたときも、次の番号は戻る（ロックと同じトランザクション）
        $this->paperRecord($this->estate, ['seq' => 40]);
        DB::table('approval_requests')->where('id', $draft->id)->update(['number' => 'R8-M-030']);
        $this->send($this->storeForm(), $this->fields(['seq' => '30']), route('approvals.admin.papers.create'));
        $this->assertSame(23, ApprovalNumber::currentState($this->estate->fresh('company'))['next']);
    }

    // --- 直し ---

    public function test_a_record_is_edited_and_only_the_changes_are_logged(): void
    {
        $record = $this->paperRecord($this->estate, ['seq' => 5, 'decided_on' => '2026-05-20', 'amount' => 1000]);
        $form   = $this->editForm($record);
        $this->assertSame([(string) $this->estate->id, '5', '2026-05-20', '1000'], [$form['fields']['department_id'], $form['fields']['seq'], $form['fields']['decided_on'], $form['fields']['amount']], '今の値で埋まる');
        $this->assertSame('PUT', $form['method']);

        $response = $this->send($form, ['subject' => '直した件名', 'amount' => '2,000'], route('approvals.admin.papers.edit', $record));

        $this->assertSame(route('approvals.admin.papers.index', ['year' => 2026]), $response->headers->get('Location'));
        $record->refresh();
        $this->assertSame(['直した件名', 2000, $this->admin->id], [$record->subject, $record->amount, $record->updated_by]);
        $log = ApprovalSettingLog::where('action', 'paper.updated')->sole();
        // ⚠ MySQL は JSON のキーを並べ替えて返す（キーの並びに頼らずに比べる）
        $this->assertSameIgnoringKeyOrder(['subject' => '見本邸新築工事契約', 'amount' => 1000], $log->old_values);
        $this->assertSameIgnoringKeyOrder(['subject' => '直した件名', 'amount' => 2000], $log->new_values);
    }

    /** 番号を直すと組み立て直し、今年度なら次の番号を進める。重なる番号には直せない */
    public function test_the_number_can_be_changed_but_not_onto_another_record(): void
    {
        $record = $this->paperRecord($this->estate, ['seq' => 5]);
        $this->paperRecord($this->estate, ['seq' => 6]);

        $this->send($this->editForm($record), ['seq' => '6'], route('approvals.admin.papers.edit', $record));
        $this->actingAs($this->admin)->get(route('approvals.admin.papers.edit', $record))->assertSee('R8-M-006 の紙の決裁はすでにあります。ＮＯ を確かめてください。');
        $this->assertSame('R8-M-005', $record->fresh()->number);

        $this->send($this->editForm($record), ['seq' => '35', 'department_id' => (string) $this->w['dept']->id], route('approvals.admin.papers.edit', $record));
        $this->assertSame(['R8-J-035', $this->w['dept']->id], [$record->fresh()->number, $record->fresh()->department_id]);
        $this->assertSame(36, ApprovalNumber::currentState($this->w['dept']->fresh('company'))['next']);

        // 同じ番号のまま保存できる（自分とは重ならない）
        $this->send($this->editForm($record->fresh()), ['subject' => '同じ番号のまま'], route('approvals.admin.papers.edit', $record));
        $this->assertSame('同じ番号のまま', $record->fresh()->subject);
    }

    // --- 削除 ---

    public function test_a_record_is_deleted_without_moving_the_number_back(): void
    {
        $record = $this->paperRecord($this->estate, ['seq' => 21]);
        ApprovalNumber::advancePast($this->estate->fresh('company'), 2026, 21);
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.edit', $record))->getContent();
        $this->assertStringContainsString("return confirm('R8-M-021 を削除します。次に付く番号は戻りません。よろしいですか。');", $html);
        // ⚠ 直しと削除は同じ URL（PUT と DELETE）。直しのフォームの後ろから探す
        $needle = 'action="' . route('approvals.admin.papers.destroy', $record) . '"';
        $form   = $this->parseForm(substr($html, strpos($html, $needle) + 1), $needle);
        $this->assertSame('DELETE', $form['method']);

        $response = $this->actingAs($this->admin)->post($form['action'], $form['fields']);

        $this->assertSame(route('approvals.admin.papers.index', ['year' => 2026]), $response->headers->get('Location'));
        $this->assertSame(0, ApprovalPaperRecord::count());
        $this->assertSame(22, ApprovalNumber::currentState($this->estate->fresh('company'))['next'], '次の番号は戻さない（D9）');
        $this->assertSame('R8-M-021', ApprovalSettingLog::where('action', 'paper.deleted')->sole()->old_values['number']);
    }

    // --- 取り込み 1 回分の取り消し ---

    /** 取り込み 1 回分（足した 3 件。そのうち 1 件はあとで手で直した）と、その回に金額を補っただけの記録 */
    private function importedBatch(): array
    {
        $import = ApprovalPaperImport::create(['file_name' => '08年度.xls', 'fiscal_year' => 2026, 'added_count' => 3, 'filled_count' => 1, 'imported_by' => $this->admin->id]);
        foreach ([1, 2, 3] as $seq) {
            $this->paperRecord($this->w['dept'], ['seq' => $seq, 'import_id' => $import->id]);
        }
        ApprovalPaperRecord::where('seq', 2)->update(['updated_by' => $this->admin->id, 'subject' => '手で直した']);
        $filled = $this->paperRecord($this->w['dept'], ['seq' => 9, 'amount' => 500]);   // 前からあった記録（金額だけ補った）
        ApprovalNumber::advancePast($this->w['dept']->fresh('company'), 2026, 3);

        return [$import, $filled];
    }

    /** 一覧を開いて、描いた取り消しのフォームを分解する */
    private function undoForm(ApprovalPaperImport $import): array
    {
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.index'))->assertOk()->getContent();

        return $this->parseForm($html, 'action="' . route('approvals.admin.papers.imports.undo', $import) . '"');
    }

    public function test_an_import_is_undone_with_the_records_it_added(): void
    {
        [$import, $filled] = $this->importedBatch();
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.index'))->getContent();
        $this->assertStringContainsString('この取り込みで足した 3 件を消します。そのあと手で直した 1 件も消えます。金額を補った 1 件は、記録も金額も残ります。次に付く番号は戻りません。よろしいですか。', $html);
        $this->assertStringContainsString('取り消す（今 3 件・うち手で直した 1 件）', $html);

        $form     = $this->undoForm($import);
        $response = $this->actingAs($this->admin)->post($form['action'], $form['fields']);

        $this->assertSame(route('approvals.admin.papers.index'), $response->headers->get('Location'));
        $this->actingAs($this->admin)->get(route('approvals.admin.papers.index'))->assertSee('取り込みを取り消しました（3 件を消しました）。次の番号は戻していません。');
        $this->assertSame([$filled->id], ApprovalPaperRecord::pluck('id')->all(), '金額を補っただけの記録は消さない');
        $import->refresh();
        $this->assertSame([3, $this->admin->id], [$import->undone_count, $import->undone_by]);
        $this->assertNotNull($import->undone_at);
        $this->assertSame(4, ApprovalNumber::currentState($this->w['dept']->fresh('company'))['next'], '次の番号は戻さない（D9）');
        $this->assertSameIgnoringKeyOrder(['file_name' => '08年度.xls', 'fiscal_year' => 2026, 'deleted' => 3, 'edited' => 1], ApprovalSettingLog::where('action', 'paper.import_undone')->sole()->new_values);

        // 取り消した回にはボタンを出さない
        $this->assertStringNotContainsString('action="' . route('approvals.admin.papers.imports.undo', $import) . '"', $this->actingAs($this->admin)->get(route('approvals.admin.papers.index'))->getContent());
    }

    /** 一覧で見せた件数・手で直した件数と今が違えば消さない・取り消した回はもう一度取り消せない（どれも描いたフォームから送る） */
    public function test_an_undo_is_refused_when_the_records_have_changed_or_it_is_done(): void
    {
        [$import] = $this->importedBatch();
        $form = $this->undoForm($import);
        ApprovalPaperRecord::where('seq', 3)->update(['updated_by' => $this->admin->id]);   // 見せたあとでほかの管理者が 1 件直した

        $this->actingAs($this->admin)->post($form['action'], $form['fields']);
        $this->actingAs($this->admin)->get(route('approvals.admin.papers.index'))
            ->assertSee('この取り込みで足した記録が変わりました（一覧では 3 件・うち手で直した 1 件、今は 3 件・うち 2 件）。一覧で確かめてから、もう一度取り消してください。');
        $this->assertSame(4, ApprovalPaperRecord::count());
        $this->assertNull($import->fresh()->undone_at);

        ApprovalPaperRecord::where('seq', 3)->delete();   // ほかの管理者が 1 件消した
        $this->actingAs($this->admin)->post($form['action'], $form['fields']);
        $this->assertNull($import->fresh()->undone_at, '件数が変わっても断る');

        // 一覧を開き直して取り消す。開いたままの古い画面からもう一度送ると断る
        $fresh = $this->undoForm($import);
        $this->actingAs($this->admin)->post($fresh['action'], $fresh['fields']);
        $this->assertSame(2, $import->fresh()->undone_count);
        $this->actingAs($this->admin)->post($form['action'], $form['fields']);
        $this->actingAs($this->admin)->get(route('approvals.admin.papers.index'))->assertSee('この取り込みはすでに取り消しています。');
        $this->assertSame(2, $import->fresh()->undone_count);
    }

    /** 確かめたあとでほかの管理者が同じ番号を登録していたら（一意の索引）、入れた中身を残してフォームへ戻す（点検 I-4） */
    public function test_a_number_taken_while_saving_is_refused_with_the_input_kept(): void
    {
        $done = false;
        ApprovalPaperRecord::creating(function () use (&$done): void {
            if (! $done) {
                $done = true;
                DB::table('approval_paper_records')->insert([
                    'department_id' => $this->estate->id, 'fiscal_year' => 2026, 'seq' => 21, 'number' => 'R8-M-021', 'decided_on' => '2026-06-10',
                    'subject' => 'ほかの管理者が登録', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $response = $this->send($this->storeForm(), $this->fields(), route('approvals.admin.papers.create'));

        $this->assertSame(route('approvals.admin.papers.create'), $response->headers->get('Location'));
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.create'))->getContent();
        $this->assertStringContainsString(e('R8-M-021 の紙の決裁はすでにあります。ＮＯ を確かめてください。'), $html);
        $this->assertStringContainsString('value="丁田"', $html);
        $this->assertSame(0, ApprovalPaperRecord::count(), '差し込んだ行も一緒に戻る');
        $this->assertSame(1, ApprovalNumber::currentState($this->estate->fresh('company'))['next']);
    }

    /** 保存のフォームは二度押し止め（1 回目で登録できたのに、2 回目が「すでにあります」で戻らないように。点検 M-1） */
    public function test_the_save_form_is_sent_only_once(): void
    {
        $html = $this->actingAs($this->admin)->get(route('approvals.admin.papers.create'))->getContent();
        $form = substr($html, strrpos(substr($html, 0, strpos($html, 'action="' . route('approvals.admin.papers.store') . '"')), '<form'));
        $open = substr($form, 0, strpos($form, '>') + 1);
        foreach (['x-data="approvalSubmitOnce()"', 'x-on:submit="onSubmit($event)"', 'x-on:pageshow.window="resetSubmit()"'] as $attribute) {
            $this->assertStringContainsString($attribute, $open);
        }
        $this->assertMatchesRegularExpression('/<button type="submit" :disabled="submitting"/', $form);
        $this->assertSame(1, substr_count($html, 'function approvalSubmitOnce()'), '二度押し止めの部品がページに 1 回だけ描かれていない');
    }

    /** 壊れた送信は一覧へ戻す（取り込みのコントローラは back() を使わない） */
    public function test_a_broken_undo_goes_back_to_the_list(): void
    {
        [$import] = $this->importedBatch();

        $response = $this->actingAs($this->admin)->from(route('approvals.admin.papers.import.preview'))->post(route('approvals.admin.papers.imports.undo', $import), []);

        $this->assertSame(route('approvals.admin.papers.index'), $response->headers->get('Location'));
        $this->assertSame(4, ApprovalPaperRecord::count());
    }
}
```

走査テストに登録する（§0.11。管理の門番の走査が新しい URL の実在する ID を作れるように・取り消した瞬間の時計の読み取り）:

`tests/Feature/Approval/ApprovalAdminGateTest.php`（変更）

```diff
--- a/tests/Feature/Approval/ApprovalAdminGateTest.php
+++ b/tests/Feature/Approval/ApprovalAdminGateTest.php
@@ -9,6 +9,8 @@
 use App\Models\ApprovalHoliday;
 use App\Models\ApprovalMailDomain;
 use App\Models\ApprovalMember;
+use App\Models\ApprovalPaperImport;
+use App\Models\ApprovalPaperRecord;
 use App\Models\ApprovalRequest;
 use App\Models\ApprovalSetting;
 use App\Models\ApprovalType;
@@ -521,6 +523,14 @@ public function test_every_admin_route_refuses_outsiders_without_revealing_ids()
         // 催促の設定（3b）の相手の送らない日
         $holiday = ApprovalHoliday::create(['start_date' => '2026-12-29', 'end_date' => '2027-01-03', 'repeats_yearly' => true, 'description' => '年末年始']);
 
+        // 過去の紙の決裁（5b）の相手の取り込みと記録。部門は審査部門にする（上の申請と同じ理由。紙の決裁のある部門は消せない）
+        $importer    = User::factory()->create();
+        $paperImport = ApprovalPaperImport::create(['file_name' => '08年度.xls', 'fiscal_year' => 2026, 'added_count' => 1, 'filled_count' => 0, 'imported_by' => $importer->id]);
+        $paperRecord = ApprovalPaperRecord::create([
+            'department_id' => $reviewDepartment->id, 'fiscal_year' => 2026, 'seq' => 1, 'number' => 'R8-SO-001', 'decided_on' => '2026-05-13',
+            'subject' => '門番の確かめ', 'import_id' => $paperImport->id, 'created_by' => $importer->id,
+        ]);
+
         $existingValues = [
             'user' => (string) $manageableUser->id,
             'approvalCompany' => (string) $company->id,
@@ -529,6 +539,8 @@ public function test_every_admin_route_refuses_outsiders_without_revealing_ids()
             'approvalType' => (string) $type->id,
             'approvalRequest' => (string) $approvalRequest->id,
             'approvalHoliday' => (string) $holiday->id,
+            'approvalPaperRecord' => (string) $paperRecord->id,
+            'approvalPaperImport' => (string) $paperImport->id,
         ];
 
         $outsiders = $this->outsiders();
```

`tests/Feature/ClockReadScanTest.php`（変更）

```diff
--- a/tests/Feature/ClockReadScanTest.php
+++ b/tests/Feature/ClockReadScanTest.php
@@ -60,6 +60,7 @@ class ClockReadScanTest extends TestCase
         'app/Support/Approval/MailDelivery.php'               => [2, '決裁のメールが送れた・送れなかった瞬間（approval_settings の TIMESTAMP 列。帯では JapanTime で日本時間に直して出す）'],
         'app/Support/Approval/ReminderCalendar.php'           => [1, '今の瞬間が日本時間の 9:05（催促を送る時刻の終わり）より前か（瞬間どうしを比べる。日付は JapanTime::today()）'],
         'app/Support/Approval/PdfSheet.php'                   => [1, '決裁申請書の PDF を出力した瞬間（各ページの下に JapanTime で日本時間に直して出す）'],
+        'app/Http/Controllers/Approval/PaperImportController.php' => [1, '紙の決裁の取り込みを取り消した瞬間（approval_paper_imports.undone_at は TIMESTAMP 列。一覧では JapanTime で日本時間に直して出す）'],
     ];
 
     /** @return list<array{int, string}> [行, 呼び出し] */
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/5b/patches/0006-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/PaperRecordCrudTest.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/ClockReadScanTest.php
```

Expected: `ERRORS!` `Tests: 20, Assertions: 95, Errors: 12, Failures: 2.`

- `PaperRecordCrudTest::test_a_paper_record_is_registered_from_the_form` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.create] not defined.`
- `PaperRecordCrudTest::test_the_optional_fields_can_be_left_empty_and_a_past_year_keeps_the_numbering` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.create] not defined.`
- `PaperRecordCrudTest::test_a_decided_date_outside_the_year_or_after_today_is_refused` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.create] not defined.`
- `PaperRecordCrudTest::test_bad_values_are_refused_with_the_input_kept` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.create] not defined.`
- `PaperRecordCrudTest::test_a_number_already_in_use_is_refused` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.create] not defined.`
- `PaperRecordCrudTest::test_a_record_is_edited_and_only_the_changes_are_logged` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.edit] not defined.`
- `PaperRecordCrudTest::test_the_number_can_be_changed_but_not_onto_another_record` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.edit] not defined.`
- `PaperRecordCrudTest::test_a_record_is_deleted_without_moving_the_number_back` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.edit] not defined.`
- `PaperRecordCrudTest::test_an_undo_is_refused_when_the_records_have_changed_or_it_is_done` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.imports.undo] not defined.`
- `PaperRecordCrudTest::test_a_number_taken_while_saving_is_refused_with_the_input_kept` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.create] not defined.`
- `PaperRecordCrudTest::test_the_save_form_is_sent_only_once` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.create] not defined.`
- `PaperRecordCrudTest::test_a_broken_undo_goes_back_to_the_list` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.admin.papers.imports.undo] not defined.`
- `PaperRecordCrudTest::test_an_import_is_undone_with_the_records_it_added` — `Failed asserting that '<!DOCTYPE html>\n`
- `ClockReadScanTest::test_php_clock_reads_are_classified` — `時計の読み取りが分類と合わない:`

- [ ] **Step 3: 1 件ずつの登録・直し・削除**

`app/Http/Controllers/Approval/PaperRecordController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/PaperRecordController.php
+++ b/app/Http/Controllers/Approval/PaperRecordController.php
@@ -6,33 +6,55 @@
 use App\Models\ApprovalDepartment;
 use App\Models\ApprovalPaperImport;
 use App\Models\ApprovalPaperRecord;
+use App\Support\Approval\ApprovalFiscalYear;
+use App\Support\Approval\ApprovalNumber;
+use App\Support\Approval\FormInput;
 use App\Support\Approval\PageNumbers;
+use App\Support\Approval\PaperImportAnalysis;
+use App\Support\Approval\SettingLogger;
+use App\Support\JapanTime;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\UniqueConstraintViolationException;
+use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Pagination\LengthAwarePaginator;
+use Illuminate\Support\Collection;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Validation\Rule;
+use Illuminate\Validation\ValidationException;
 use Illuminate\View\View;
 
 /**
- * 取り込んだ紙の決裁（画面⑪の一覧。段階5 設計書 §5.10）。決裁の管理者だけ・使い始める前から使える（D11）。
+ * 取り込んだ紙の決裁（画面⑪の一覧と 1 件ずつの登録・直し・削除。段階5 設計書 §5.10・D9）。決裁の管理者だけ・使い始める前から使える（D11）。
  *
- * 一覧（年度・部門で絞る）と、取り込みの記録（新しい順）を出す。Excel の台帳の取り込みは PaperImportController。
+ * 一覧（年度・部門で絞る）と取り込みの記録（新しい順・取り消し）を出す。Excel の台帳の取り込みと取り消しは PaperImportController。
+ *
+ * - 決裁No は年度・部門・ＮＯ から組み立てる（`ApprovalNumber::paperNumber()`）。紙の番号どうし・システムの申請の番号と重なれば断る
+ * - 今年度の番号を足す・直すと、その部門の次の番号を進める（D7。戻さない）。連番の行をロックしてからシステムの申請の番号をロック付きの
+ *   読みで確かめる（社長の判断の採番と重ならない。取り込みと同じ部品 ApprovalNumber::reservePaperNumbers）
+ * - 決裁日は、選んだ年度の期間（部門の会社の期）の中で、今日より前の日だけ（今日より先・まだ始まっていない会社の年度の番号を入れると、
+ *   次の番号を進められずに、あとでシステムの採番と重なる）
+ * - 設定の記録に残す（paper.created・paper.updated〈変わった項目だけ〉・paper.deleted）
  *
  * ⚠ 絞り込みは GET。選択肢に無い値は外す（手で打った URL。断って前の画面へ戻すと同じ URL を開き直し続ける＝台帳の LedgerFilter と同じ考え）
+ * ⚠ `updated_by` は手で直した人（取り込みの取り消しの「あとで手で直した記録」の数に使う）。登録では入れない
  */
 class PaperRecordController extends Controller
 {
     /** 一覧の 1 ページの件数 */
     public const PER_PAGE = 50;
 
-    /** 取り込みの記録を出す数（新しい順） */
-    private const IMPORTS_SHOWN = 20;
+    /** 備考の上限 */
+    public const NOTE_MAX = 2000;
+
+    /** 設定の記録に残す項目（フォームの欄と、組み立てた決裁No） */
+    private const FIELDS = ['number', 'fiscal_year', 'department_id', 'seq', 'decided_on', 'applicant_name', 'subject', 'amount', 'cost', 'profit', 'note'];
 
     /** Route: GET /approvals/admin/papers */
     public function index(Request $request): View
     {
-        $departments = ApprovalDepartment::with('company')->get()
-            ->sortBy(fn (ApprovalDepartment $d) => [$d->company->sort_order, $d->company_id, $d->sort_order, $d->id])
-            ->values();
-        $years = ApprovalPaperRecord::yearChoices();
+        $departments = $this->departments();
+        $years       = ApprovalPaperRecord::yearChoices();
 
         // 選択肢に無い値は外す（数の形でない・無い年度・無い部門）
         $year       = $this->choice($request->query('year'), array_keys($years));
@@ -60,8 +82,232 @@ public function index(Request $request): View
             'departments' => $departments,
             'year'        => $year,
             'department'  => $department,
-            'imports'     => ApprovalPaperImport::with(['importer', 'undoer'])->latest('id')->limit(self::IMPORTS_SHOWN)->get(),
+            // 取り消しの確かめに、今その回の記録が何件あり、そのうち何件を手で直したかを出す（D9）
+            // 取り込みの記録はすべて出す（古い回も取り消せるように。年度ごとのファイルなので多くならない）
+            'imports'     => ApprovalPaperImport::with(['importer', 'undoer'])
+                ->withCount(['records', 'records as edited_count' => fn ($q) => $q->whereNotNull('updated_by')])
+                ->latest('id')->get(),
+        ]);
+    }
+
+    /** Route: GET /approvals/admin/papers/create */
+    public function create(): View
+    {
+        return view('approvals.admin.papers.form', ['record' => null] + $this->formChoices());
+    }
+
+    /** Route: POST /approvals/admin/papers */
+    public function store(Request $request): RedirectResponse
+    {
+        $values = $this->validated($request);
+
+        $record = $this->save($values, function (array $values) use ($request): ApprovalPaperRecord {
+            $record = ApprovalPaperRecord::create($values + ['created_by' => $request->user()->id]);
+            SettingLogger::record('paper.created', 'approval_paper_record', $record->id, [], $this->logValues($values));
+
+            return $record;
+        }, null);
+
+        return $this->saved($record, "{$record->number} を登録しました。");
+    }
+
+    /** Route: GET /approvals/admin/papers/{approvalPaperRecord}/edit */
+    public function edit(ApprovalPaperRecord $approvalPaperRecord): View
+    {
+        return view('approvals.admin.papers.form', ['record' => $approvalPaperRecord->load(['department', 'import', 'creator', 'editor'])] + $this->formChoices());
+    }
+
+    /** Route: PUT /approvals/admin/papers/{approvalPaperRecord} */
+    public function update(Request $request, ApprovalPaperRecord $approvalPaperRecord): RedirectResponse
+    {
+        $values = $this->validated($request);
+        $before = $this->storedValues($approvalPaperRecord);
+
+        $record = $this->save($values, function (array $values) use ($request, $approvalPaperRecord, $before): ApprovalPaperRecord {
+            $approvalPaperRecord->update($values + ['updated_by' => $request->user()->id]);
+            SettingLogger::recordChange('paper.updated', 'approval_paper_record', $approvalPaperRecord->id, $before, $this->logValues($values));
+
+            return $approvalPaperRecord;
+        }, $approvalPaperRecord);
+
+        return $this->saved($record, "{$record->number} を直しました。");
+    }
+
+    /** Route: DELETE /approvals/admin/papers/{approvalPaperRecord}。次の番号は戻さない（D9） */
+    public function destroy(ApprovalPaperRecord $approvalPaperRecord): RedirectResponse
+    {
+        $before = $this->storedValues($approvalPaperRecord);
+        $id     = $approvalPaperRecord->id;
+        $number = $approvalPaperRecord->number;
+        $year   = $approvalPaperRecord->fiscal_year;
+
+        DB::transaction(function () use ($approvalPaperRecord, $id, $before): void {
+            $approvalPaperRecord->delete();
+            SettingLogger::record('paper.deleted', 'approval_paper_record', $id, $before, []);
+        });
+
+        return redirect()->route('approvals.admin.papers.index', ['year' => $year])->with('success', "{$number} を削除しました。");
+    }
+
+    /**
+     * 欄の検査（金額は全角・カンマ・円を落としてから。マイナスも入れられる＝値引きの行のある台帳）。決裁No も組み立てて返す
+     *
+     * ⚠ ルールは literal の配列で書く（JapaneseValidationMessagesTest の走査が和名の漏れを見る）
+     *
+     * @return array<string, mixed> 保存する列
+     */
+    private function validated(Request $request): array
+    {
+        $request->merge([
+            'seq'            => FormInput::digits($request->input('seq')),
+            'amount'         => FormInput::digits($request->input('amount'), FormInput::YEN),
+            'cost'           => FormInput::digits($request->input('cost'), FormInput::YEN),
+            'profit'         => FormInput::digits($request->input('profit'), FormInput::YEN),
+            'applicant_name' => is_string($request->input('applicant_name')) ? FormInput::trim($request->input('applicant_name')) : $request->input('applicant_name'),
+            'subject'        => is_string($request->input('subject')) ? FormInput::trim($request->input('subject')) : $request->input('subject'),
+        ]);
+        FormInput::unifyNewlines($request, 'note');
+
+        $validated = $request->validate([
+            'fiscal_year'    => ['required', 'integer', Rule::in(array_keys(ApprovalPaperRecord::yearChoices()))],
+            'department_id'  => ['required', 'integer', Rule::in($this->departments()->pluck('id')->all())],
+            'seq'            => ['bail', 'required', 'integer', 'min:1', 'max:' . PaperImportAnalysis::SEQ_MAX],
+            'decided_on'     => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:1989-01-01', 'before_or_equal:' . JapanTime::today()->format('Y-m-d')],
+            'applicant_name' => ['nullable', 'string', 'max:' . PaperImportAnalysis::APPLICANT_MAX],
+            'subject'        => ['required', 'string', 'max:' . PaperImportAnalysis::SUBJECT_MAX],
+            'amount'         => ['bail', 'nullable', 'integer', 'min:-999999999999', 'max:999999999999'],
+            'cost'           => ['bail', 'nullable', 'integer', 'min:-999999999999', 'max:999999999999'],
+            'profit'         => ['bail', 'nullable', 'integer', 'min:-999999999999', 'max:999999999999'],
+            'note'           => ['nullable', 'string', 'max:' . self::NOTE_MAX],
+        ], [
+            'department_id.required'    => '部門を選んでください。',
+            'seq.required'              => 'ＮＯ を入力してください。',
+            'decided_on.required'       => '決裁日を入力してください。',
+            'subject.required'          => '件名を入力してください。',
+            'fiscal_year.in'            => '年度を選び直してください。',
+            'department_id.in'          => '部門を選び直してください。',
+            'seq.integer'               => 'ＮＯ は数で入力してください。',
+            'seq.min'                   => 'ＮＯ は 1 から 99,999 までで入力してください。',
+            'seq.max'                   => 'ＮＯ は 1 から 99,999 までで入力してください。',
+            'decided_on.date_format'    => '決裁日は正しい日付で入力してください。',
+            'decided_on.after_or_equal' => '決裁日は 1989 年からの日付で入力してください。',
+            'decided_on.before_or_equal' => '決裁日に今日より先の日付は入れられません。',
+            'applicant_name.max'        => '申請者は ' . PaperImportAnalysis::APPLICANT_MAX . ' 文字以内で入力してください。',
+            'subject.max'               => '件名は ' . PaperImportAnalysis::SUBJECT_MAX . ' 文字以内で入力してください。',
+            'amount.integer'            => '金額「:input」は円の数で入力してください（マイナスも入れられます）。',
+            'amount.min'                => '金額は 12 桁までで入力してください。',
+            'amount.max'                => '金額は 12 桁までで入力してください。',
+            'cost.integer'              => '工事原価「:input」は円の数で入力してください（マイナスも入れられます）。',
+            'cost.min'                  => '工事原価は 12 桁までで入力してください。',
+            'cost.max'                  => '工事原価は 12 桁までで入力してください。',
+            'profit.integer'            => '粗利益「:input」は円の数で入力してください（マイナスも入れられます）。',
+            'profit.min'                => '粗利益は 12 桁までで入力してください。',
+            'profit.max'                => '粗利益は 12 桁までで入力してください。',
+            'note.max'                  => '備考は ' . number_format(self::NOTE_MAX) . ' 文字以内で入力してください。',
+        ], [
+            'fiscal_year'    => '年度',
+            'department_id'  => '部門',
+            'seq'            => 'ＮＯ',
+            'decided_on'     => '決裁日',
+            'applicant_name' => '申請者',
+            'subject'        => '件名',
+            'amount'         => '金額',
+            'cost'           => '工事原価',
+            'profit'         => '粗利益',
+            'note'           => '備考',
         ]);
+
+        $department = ApprovalDepartment::with('company')->findOrFail((int) $validated['department_id']);
+
+        // 決裁日は選んだ年度の期間の中だけ（部門の会社の期）
+        $month = $department->company->fiscal_start_month;
+        $day   = CarbonImmutable::createFromFormat('!Y-m-d', $validated['decided_on']);
+        if (ApprovalFiscalYear::of($day, $month) !== (int) $validated['fiscal_year']) {
+            $start = CarbonImmutable::create((int) $validated['fiscal_year'], $month, 1);
+            throw ValidationException::withMessages(['decided_on' => sprintf(
+                '決裁日 %s は %s 年度の期間（%s〜%s）の外です。年度か決裁日を直してください。',
+                $day->format('Y/m/d'),
+                ApprovalFiscalYear::eraLabel((int) $validated['fiscal_year'], $month),
+                $start->format('Y/m/d'),
+                $start->addYear()->subDay()->format('Y/m/d')
+            )]);
+        }
+
+        $values     = [
+            'fiscal_year'    => (int) $validated['fiscal_year'],
+            'department_id'  => $department->id,
+            'seq'            => (int) $validated['seq'],
+            'decided_on'     => $validated['decided_on'],
+            'applicant_name' => ($validated['applicant_name'] ?? '') === '' ? null : $validated['applicant_name'],
+            'subject'        => $validated['subject'],
+            'amount'         => isset($validated['amount']) ? (int) $validated['amount'] : null,
+            'cost'           => isset($validated['cost']) ? (int) $validated['cost'] : null,
+            'profit'         => isset($validated['profit']) ? (int) $validated['profit'] : null,
+            'note'           => ($validated['note'] ?? '') === '' ? null : $validated['note'],
+        ];
+
+        return $values + ['number' => ApprovalNumber::paperNumber($department, $values['fiscal_year'], $values['seq'])];
+    }
+
+    /**
+     * 番号を確かめて保存する（1 つのトランザクション）。今年度の番号なら次の番号を進め、ロックのあとでシステムの申請の番号を確かめる
+     *
+     * @param callable(array): ApprovalPaperRecord $write
+     * @throws ValidationException 番号が重なる（入れた中身を残してフォームへ戻す）
+     */
+    private function save(array $values, callable $write, ?ApprovalPaperRecord $current): ApprovalPaperRecord
+    {
+        $refuse = fn (string $message) => ValidationException::withMessages(['seq' => $message]);
+
+        try {
+            return DB::transaction(function () use ($values, $write, $current, $refuse): ApprovalPaperRecord {
+                // 次の番号を進め、ロックのあとでシステムの申請の番号をロック付きの読みで確かめる（取り込みと同じ部品）
+                $reserved = ApprovalNumber::reservePaperNumbers([$values['department_id'] => $values['seq']], $values['fiscal_year'], [$values['number']]);
+                if ($reserved['taken'] !== []) {
+                    throw $refuse("{$values['number']} はシステムの申請がすでに使っています。ＮＯ を確かめてください。");
+                }
+                if (ApprovalPaperRecord::where('number', $values['number'])->when($current !== null, fn ($q) => $q->whereKeyNot($current->id))->exists()) {
+                    throw $refuse("{$values['number']} の紙の決裁はすでにあります。ＮＯ を確かめてください。");
+                }
+
+                return $write($values);
+            });
+        } catch (UniqueConstraintViolationException) {
+            // 確かめたあとで、ほかの管理者が同じ番号を登録した
+            throw $refuse("{$values['number']} の紙の決裁はすでにあります。ＮＯ を確かめてください。");
+        }
+    }
+
+    /** 保存したあと（その年度の一覧へ） */
+    private function saved(ApprovalPaperRecord $record, string $message): RedirectResponse
+    {
+        return redirect()->route('approvals.admin.papers.index', ['year' => $record->fiscal_year])->with('success', $message);
+    }
+
+    /** 今の記録の値（設定の記録の「前」。決裁日は保存する形の文字にそろえる＝変えていない日付を「変わった」と記録しない） */
+    private function storedValues(ApprovalPaperRecord $record): array
+    {
+        return array_merge($record->only(self::FIELDS), ['decided_on' => $record->decided_on->format('Y-m-d')]);
+    }
+
+    /** 設定の記録の値（決裁日は文字のまま） */
+    private function logValues(array $values): array
+    {
+        return array_intersect_key($values, array_flip(self::FIELDS));
+    }
+
+    /** フォームの選択肢 */
+    private function formChoices(): array
+    {
+        return ['years' => ApprovalPaperRecord::yearChoices(), 'departments' => $this->departments()];
+    }
+
+    /** 部門（会社の並び・部門の並び） @return Collection<int, ApprovalDepartment> */
+    private function departments(): Collection
+    {
+        return ApprovalDepartment::with('company')->get()
+            ->sortBy(fn (ApprovalDepartment $d) => [$d->company->sort_order, $d->company_id, $d->sort_order, $d->id])
+            ->values();
     }
 
     /**
```

`resources/views/approvals/admin/papers/form.blade.php`（新規）

```blade
@extends('layouts.app')

@section('title', $record === null ? '紙の決裁を登録' : "{$record->number} を直す")

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.admin.papers.index') }}" class="hover:text-emerald-600 transition-colors">過去の決裁の取り込み</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $record === null ? '1 件ずつ登録' : '直す' }}</span>
@endsection

{{--
    紙の決裁の 1 件ずつの登録・直し・削除（画面⑪。段階5 設計書 §5.10・D9）。決裁の管理者だけ・使い始める前から（D11）。
    必要変数: $record（登録は null）/ $years / $departments

    ⚠ 断られた入力は old() から戻す。手で組んだ送信の配列などは文字として扱わない（$value）
    ⚠ 決裁日は日付（時刻なし）なので ->format() のまま。登録・直した日時は保存した日時なので JapanTime::format（Bug #61）
    ⚠ 削除のフォームは保存のフォームの外（フォームを入れ子にしない）
    ⚠ 保存は二度押し止め（approvalSubmitOnce。2 回押すと、1 回目で登録できたのに 2 回目が「すでにあります」で戻る。⑨ と同じ）
--}}
@php
    $refused = old('_token') !== null;
    $value   = function (string $key, mixed $stored) use ($refused): string {
        if ($refused) {
            return is_string(old($key)) ? old($key) : '';
        }

        return $stored === null ? '' : (string) $stored;
    };
    $year       = $value('fiscal_year', $record?->fiscal_year ?? array_key_first($years));
    $department = $value('department_id', $record?->department_id);
    $input      = 'w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] focus:border-emerald-500 focus:outline-none';
    $label      = 'block text-[12px] font-semibold text-gray-700 mb-1';
@endphp

@section('content')
<div class="max-w-[720px]">
    @include('approvals._mail_failure')

    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <h1 class="text-lg font-bold text-gray-900 mb-1">{{ $record === null ? '紙の決裁を 1 件ずつ登録' : "{$record->number} を直す" }}</h1>
    <p class="text-[12px] text-gray-500 mb-5">
        決裁No は年度・部門・ＮＯ から組み立てます（R8-J-021 など）。ほかの紙の決裁やシステムの申請と同じ番号にはできません。
        今年度の番号を入れると、その部門の次に付く番号が、その番号の次まで進みます（戻りません）。
    </p>

    @if($record !== null)
        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-[12px] text-gray-600 bg-white border border-gray-200 rounded-lg px-4 py-3 mb-5">
            <dt class="text-gray-500">登録</dt>
            <dd>
                @if($record->import !== null)
                    取り込み（{{ $record->import->file_name }}・{{ \App\Support\JapanTime::format($record->import->created_at, 'Y/m/d H:i') }}）
                @else
                    1 件ずつ登録（{{ $record->creator?->name ?? '—' }}・{{ \App\Support\JapanTime::format($record->created_at, 'Y/m/d H:i') }}）
                @endif
            </dd>
            @if($record->updated_by !== null)
                <dt class="text-gray-500">直した人</dt>
                <dd>{{ $record->editor?->name ?? '—' }}（{{ \App\Support\JapanTime::format($record->updated_at, 'Y/m/d H:i') }}）</dd>
            @endif
        </dl>
    @endif

    <form method="POST" action="{{ $record === null ? route('approvals.admin.papers.store') : route('approvals.admin.papers.update', $record) }}"
          x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()"
          class="bg-white rounded-lg border border-gray-200 px-4 py-4 space-y-4">
        @csrf
        @if($record !== null)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <div>
                <label for="paper-fiscal-year" class="{{ $label }}">年度<span class="text-red-600 ml-0.5">*</span></label>
                <select id="paper-fiscal-year" name="fiscal_year" class="{{ $input }} bg-white cursor-pointer">
                    @foreach($years as $choice => $text)
                        <option value="{{ $choice }}" {{ $year === (string) $choice ? 'selected' : '' }}>{{ $text }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <label for="paper-department" class="{{ $label }}">部門<span class="text-red-600 ml-0.5">*</span></label>
                <select id="paper-department" name="department_id" class="{{ $input }} bg-white cursor-pointer">
                    <option value="">選んでください</option>
                    @foreach($departments as $item)
                        <option value="{{ $item->id }}" {{ $department === (string) $item->id ? 'selected' : '' }}>{{ $item->company->name }} / {{ $item->name }}（{{ $item->code }}）</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="paper-seq" class="{{ $label }}">ＮＯ<span class="text-red-600 ml-0.5">*</span></label>
                <input id="paper-seq" type="text" inputmode="numeric" name="seq" value="{{ $value('seq', $record?->seq) }}" required maxlength="10" placeholder="例: 21" class="{{ $input }}">
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
                <label for="paper-decided-on" class="{{ $label }}">決裁日<span class="text-red-600 ml-0.5">*</span></label>
                <input id="paper-decided-on" type="date" name="decided_on" value="{{ $value('decided_on', $record?->decided_on?->format('Y-m-d')) }}" required class="{{ $input }}">
            </div>
            <div class="sm:col-span-2">
                <label for="paper-applicant" class="{{ $label }}">申請者</label>
                <input id="paper-applicant" type="text" name="applicant_name" value="{{ $value('applicant_name', $record?->applicant_name) }}" maxlength="{{ \App\Support\Approval\PaperImportAnalysis::APPLICANT_MAX }}" placeholder="台帳の書き方のまま（例: 甲野）" class="{{ $input }}">
            </div>
        </div>

        <div>
            <label for="paper-subject" class="{{ $label }}">件名<span class="text-red-600 ml-0.5">*</span></label>
            <input id="paper-subject" type="text" name="subject" value="{{ $value('subject', $record?->subject) }}" required maxlength="{{ \App\Support\Approval\PaperImportAnalysis::SUBJECT_MAX }}" class="{{ $input }}">
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            @foreach(['amount' => '金額（契約金額）', 'cost' => '工事原価', 'profit' => '粗利益'] as $key => $text)
                <div>
                    <label for="paper-{{ $key }}" class="{{ $label }}">{{ $text }}</label>
                    <input id="paper-{{ $key }}" type="text" inputmode="numeric" name="{{ $key }}" value="{{ $value($key, $record?->{$key}) }}" maxlength="20" placeholder="円" class="{{ $input }} text-right tabular-nums">
                </div>
            @endforeach
        </div>
        <p class="text-[11px] text-gray-400 -mt-2">金額は円の数で入れます（カンマは付けても構いません。値引きなどはマイナスも入れられます）。粗利率は金額と粗利益から計算します。</p>

        <div>
            <label for="paper-note" class="{{ $label }}">備考</label>
            <textarea id="paper-note" name="note" rows="3" maxlength="{{ \App\Http\Controllers\Approval\PaperRecordController::NOTE_MAX }}" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] focus:border-emerald-500 focus:outline-none">{{ $value('note', $record?->note) }}</textarea>
        </div>

        <div class="flex flex-wrap items-center gap-3 pt-1">
            <button type="submit" :disabled="submitting" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">{{ $record === null ? '登録する' : '直す' }}</button>
            <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
            <a href="{{ route('approvals.admin.papers.index') }}" class="text-[13px] text-gray-500 hover:text-emerald-600 transition-colors">一覧へ戻る</a>
        </div>
    </form>

    @if($record !== null)
        <form method="POST" action="{{ route('approvals.admin.papers.destroy', $record) }}" class="mt-6 text-right"
              onsubmit="return confirm('{{ $record->number }} を削除します。次に付く番号は戻りません。よろしいですか。');">
            @csrf
            @method('DELETE')
            <button type="submit" class="px-4 py-1.5 bg-white border border-red-300 text-red-700 hover:bg-red-50 rounded-md text-[12px] font-semibold cursor-pointer">この紙の決裁を削除する</button>
        </form>
    @endif
</div>
@include('approvals._submit_once')
@endsection
```

- [ ] **Step 4: 取り込み 1 回分の取り消しと、一覧の入口**

`app/Http/Controllers/Approval/PaperImportController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/PaperImportController.php
+++ b/app/Http/Controllers/Approval/PaperImportController.php
@@ -3,20 +3,23 @@
 namespace App\Http\Controllers\Approval;
 
 use App\Http\Controllers\Controller;
+use App\Models\ApprovalPaperImport;
 use App\Models\ApprovalPaperRecord;
 use App\Support\Approval\PaperImportAnalysis;
 use App\Support\Approval\PaperImporter;
 use App\Support\Approval\PaperImportException;
+use App\Support\Approval\SettingLogger;
 use App\Support\OneTimeAction;
 use Illuminate\Database\UniqueConstraintViolationException;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
+use Illuminate\Support\Facades\DB;
 use Illuminate\Validation\Rule;
 use Illuminate\Validation\ValidationException;
 use Illuminate\View\View;
 
 /**
- * 過去の紙の決裁の取り込み（画面⑪。要件 11 章・段階5 設計書 §5.9・D6・D7・D20）。決裁の管理者だけ・使い始める前から使える（D11）。
+ * 過去の紙の決裁の取り込みと、取り込み 1 回分の取り消し（画面⑪。要件 11 章・段階5 設計書 §5.9・§5.10・D6・D7・D9・D20）。決裁の管理者だけ・使い始める前から使える（D11）。
  *
  * 流れ（社員の CSV 一括登録と同じ形）: ファイル（.xls・.xlsx・10MB まで）と年度を選ぶ → 確認の画面（何も書かない）
  *   → 描いた「取り込む」フォーム（hidden のファイルと 1 回限りの鍵・金額の控えの結び付けの選択）で確定
@@ -136,6 +139,63 @@ public function execute(Request $request): View|RedirectResponse
             ->with('success', "{$import->added_count} 件を取り込みました（金額を補った記録 {$import->filled_count} 件）。{$next}");
     }
 
+    /**
+     * Route: POST /approvals/admin/papers/imports/{approvalPaperImport}/undo
+     *
+     * 取り込み 1 回分の取り消し（D9）: その回に足した記録を、そのあと手で直したものも含めて消す。金額を補っただけの記録は消さない
+     * （その回に足したものではない）。次の番号は戻さない（部門の管理の開始番号で戻す）。取り込みの行は消さずに、取り消した日時・人・件数を残す。
+     * ⚠ 一覧で見せた件数（`count_shown`）と、そのうち手で直した件数（`edited_shown`）が今と違えば断る（見せたあとで足し消し・直しがあった
+     *   ＝確かめた中身と違うものを消さない）
+     */
+    public function undo(Request $request, ApprovalPaperImport $approvalPaperImport): RedirectResponse
+    {
+        try {
+            $validated = $request->validate([
+                'count_shown'  => ['required', 'integer', 'min:0'],
+                'edited_shown' => ['required', 'integer', 'min:0'],
+            ], [], [
+                'count_shown'  => '消す件数',
+                'edited_shown' => '手で直した件数',
+            ]);
+        } catch (ValidationException $e) {
+            // 一覧の画面へ戻す（取り込みのコントローラは back() を使わない。Bug #64）
+            throw $e->redirectTo(route('approvals.admin.papers.index'));
+        }
+
+        $result = DB::transaction(function () use ($approvalPaperImport, $validated, $request): string|int {
+            $import = ApprovalPaperImport::whereKey($approvalPaperImport->id)->lockForUpdate()->firstOrFail();
+            if ($import->undone_at !== null) {
+                return 'この取り込みはすでに取り消しています。';
+            }
+
+            $count  = $import->records()->count();
+            $edited = $import->records()->whereNotNull('updated_by')->count();
+            $shown  = (int) $validated['count_shown'];
+            $shownEdited = (int) $validated['edited_shown'];
+            if ($count !== $shown || $edited !== $shownEdited) {
+                return "この取り込みで足した記録が変わりました（一覧では {$shown} 件・うち手で直した {$shownEdited} 件、今は {$count} 件・うち {$edited} 件）。一覧で確かめてから、もう一度取り消してください。";
+            }
+
+            $import->records()->delete();
+            $import->update(['undone_at' => now(), 'undone_by' => $request->user()->id, 'undone_count' => $count]);
+            SettingLogger::record('paper.import_undone', 'approval_paper_import', $import->id, [], [
+                'file_name'   => $import->file_name,
+                'fiscal_year' => $import->fiscal_year,
+                'deleted'     => $count,
+                'edited'      => $edited,
+            ]);
+
+            return $count;
+        });
+
+        if (is_string($result)) {
+            return redirect()->route('approvals.admin.papers.index')->with('error', $result);
+        }
+
+        return redirect()->route('approvals.admin.papers.index')
+            ->with('success', "取り込みを取り消しました（{$result} 件を消しました）。次の番号は戻していません。戻すときは部門の管理で開始番号を直してください。");
+    }
+
     /** 確認の画面（中身を作って描く） */
     private function confirmation(string $content, string $fileName, int $year, array $links): View|RedirectResponse
     {
```

`resources/views/approvals/admin/papers/index.blade.php`（変更）

```diff
--- a/resources/views/approvals/admin/papers/index.blade.php
+++ b/resources/views/approvals/admin/papers/index.blade.php
@@ -9,7 +9,8 @@
     <span class="text-gray-600">過去の決裁の取り込み</span>
 @endsection
 
-{{-- 取り込んだ紙の決裁の一覧（画面⑪。段階5 設計書 §5.10）。決裁の管理者だけ・使い始める前から（D11）。
+{{-- 取り込んだ紙の決裁の一覧と、取り込みの記録・取り消し（画面⑪。段階5 設計書 §5.10・D9）。決裁の管理者だけ・使い始める前から（D11）。
+     決裁No から 1 件ずつの直し・削除の画面へ
      ⚠ 決裁日は日付（時刻なし）なので ->format() のまま。取り込んだ日時・取り消した日時は保存した日時なので JapanTime::format（Bug #61）
      ⚠ プルダウンは変えた瞬間に送る（CLAUDE.md の即時フィルタ） --}}
 @section('content')
@@ -31,6 +32,7 @@
 
     <div class="flex flex-wrap gap-2 mb-4">
         <a href="{{ route('approvals.admin.papers.import') }}" class="inline-flex items-center h-9 px-4 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold">Excel の台帳から取り込む</a>
+        <a href="{{ route('approvals.admin.papers.create') }}" class="inline-flex items-center h-9 px-4 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-md text-[13px] font-semibold">1 件ずつ登録</a>
     </div>
 
     <form id="paper-filter" method="GET" action="{{ route('approvals.admin.papers.index') }}"
@@ -79,7 +81,9 @@ class="flex flex-wrap items-end gap-3 mb-4 bg-white border border-gray-200 round
                         <tbody>
                             @foreach($records as $record)
                                 <tr class="hover:bg-gray-50">
-                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-mono text-gray-700 whitespace-nowrap">{{ $record->number }}</td>
+                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-mono whitespace-nowrap">
+                                        <a href="{{ route('approvals.admin.papers.edit', $record) }}" class="text-emerald-700 hover:underline" title="直す">{{ $record->number }}</a>
+                                    </td>
                                     <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $record->decided_on->format('Y/m/d') }}</td>
                                     <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $record->department->name }}</td>
                                     <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $record->applicant_name ?? '—' }}</td>
@@ -128,8 +132,17 @@ class="flex flex-wrap items-end gap-3 mb-4 bg-white border border-gray-200 round
                                     <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">
                                         @if($import->undone_at !== null)
                                             {{ \App\Support\JapanTime::format($import->undone_at, 'Y/m/d H:i') }} に {{ $import->undoer?->name ?? '—' }} が取り消し（{{ number_format((int) $import->undone_count) }} 件を消した）
+                                        @elseif($import->records_count === 0)
+                                            —（この回に足した記録は残っていません）
                                         @else
-                                            —
+                                            {{-- 取り消し（D9）: 今の件数と、そのあと手で直した件数を見せて確かめる。見せた件数を送り、変わっていれば断る --}}
+                                            <form method="POST" action="{{ route('approvals.admin.papers.imports.undo', $import) }}" class="inline"
+                                                  onsubmit="return confirm('この取り込みで足した {{ $import->records_count }} 件を消します。{{ $import->edited_count > 0 ? "そのあと手で直した {$import->edited_count} 件も消えます。" : '' }}{{ $import->filled_count > 0 ? "金額を補った {$import->filled_count} 件は、記録も金額も残ります。" : '' }}次に付く番号は戻りません。よろしいですか。');">
+                                                @csrf
+                                                <input type="hidden" name="count_shown" value="{{ $import->records_count }}">
+                                                <input type="hidden" name="edited_shown" value="{{ $import->edited_count }}">
+                                                <button type="submit" class="px-2.5 py-1 bg-white border border-red-300 text-red-700 hover:bg-red-50 rounded-md text-[12px] font-semibold cursor-pointer">取り消す（今 {{ $import->records_count }} 件{{ $import->edited_count > 0 ? "・うち手で直した {$import->edited_count} 件" : '' }}）</button>
+                                            </form>
                                         @endif
                                     </td>
                                 </tr>
```

`resources/views/approvals/admin/papers/import.blade.php`（変更）

```diff
--- a/resources/views/approvals/admin/papers/import.blade.php
+++ b/resources/views/approvals/admin/papers/import.blade.php
@@ -58,6 +58,7 @@
             <li>読めない行（日付が無い・年度と合わないなど）は<strong>その行だけ取り込みません</strong>。ほかの行は取り込みます。確認の画面に、どの行がなぜかを出します。</li>
             <li><strong>すでにある決裁No は変えません</strong>（手で直した中身を消さないため）。金額が空の記録にだけ、金額の控えの金額を入れます。</li>
             <li>今年度の台帳を取り込むと、その部門の<strong>次に付く番号</strong>が、取り込んだいちばん大きい番号の次まで進みます（戻りません）。</li>
+            <li>取り込みを間違えたときは、紙の決裁の一覧の「取り込みの記録」から、<strong>1 回分をまとめて取り消せます</strong>（そのあと手で直した記録も消えます。進んだ次の番号は戻りません）。</li>
         </ul>
     </section>
```

`routes/approval.php`（変更）

```diff
--- a/routes/approval.php
+++ b/routes/approval.php
@@ -95,11 +95,18 @@
     Route::delete('/holidays/{approvalHoliday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');
 
     // 過去の紙の決裁の取り込み（画面⑪。段階5 設計書 §5.9・§5.10）。使い始める前から使える（使い始める日までに取り込む。D11）
-    // ⚠ `/papers/import*` を `/papers/{approvalPaperRecord}` より前に置く（登録順がマッチの優先順）
+    // ⚠ `/papers/import*`・`/papers/create` を `/papers/{approvalPaperRecord}` より前に置く（登録順がマッチの優先順）
     Route::get('/papers', [PaperRecordController::class, 'index'])->name('papers.index');
     Route::get('/papers/import', [PaperImportController::class, 'form'])->name('papers.import');
     Route::post('/papers/import/preview', [PaperImportController::class, 'preview'])->name('papers.import.preview');
     Route::post('/papers/import/execute', [PaperImportController::class, 'execute'])->name('papers.import.execute');
+    Route::post('/papers/imports/{approvalPaperImport}/undo', [PaperImportController::class, 'undo'])->name('papers.imports.undo');
+    // 1 件ずつの登録・直し・削除（§5.10）。⚠ パラメータ名は `{approvalPaperRecord}`
+    Route::get('/papers/create', [PaperRecordController::class, 'create'])->name('papers.create');
+    Route::post('/papers', [PaperRecordController::class, 'store'])->name('papers.store');
+    Route::get('/papers/{approvalPaperRecord}/edit', [PaperRecordController::class, 'edit'])->name('papers.edit');
+    Route::put('/papers/{approvalPaperRecord}', [PaperRecordController::class, 'update'])->name('papers.update');
+    Route::delete('/papers/{approvalPaperRecord}', [PaperRecordController::class, 'destroy'])->name('papers.destroy');
 });
 
 /*
```

（差分のファイルを使うなら、Step 3〜4 をまとめて: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/5b/patches/0006-*.patch`）

- [ ] **Step 5: テストを流して通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5 tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase2/LaunchGateTest.php tests/Feature/ClockReadScanTest.php tests/Feature/ImportControllerReturnPathScanTest.php tests/Feature/ImportControllerValidationRedirectScanTest.php tests/Feature/JapaneseValidationMessagesTest.php tests/Feature/ValidationErrorFeedbackTest.php
```

Expected: `OK`（赤なし）

- [ ] **Step 6: 全件を流す**

Expected: `OK (4162 tests, 39178 assertions)`

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Http/Controllers/Approval/PaperRecordController.php resources/views/approvals/admin/papers/form.blade.php app/Http/Controllers/Approval/PaperImportController.php resources/views/approvals/admin/papers/index.blade.php resources/views/approvals/admin/papers/import.blade.php routes/approval.php tests/Feature/Approval/Phase5/PaperRecordCrudTest.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/ClockReadScanTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 紙の決裁を 1 件ずつ直せるようにし取り込みを取り消せる

決裁の管理者が紙の決裁を 1 件ずつ登録・直し・削除できる（番号の
重なりは断る・今年度なら次の番号を進める）。取り込みを間違えたときは、
今の件数と手で直した件数を見せてから 1 回分をまとめて取り消せる。次の
番号は戻さない（段階5 設計書 §5.10・D9）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 7: 紙の決裁の詳細と、関連する決裁No の候補・リンクに紙の決裁を足す

見られる人が開ける紙の決裁の詳細の画面を作り（台帳の行と関連する決裁No のリンクの先）、申請の画面の関連する決裁No の候補に見られる紙の決裁も出し、申請の詳細の関連する決裁No を紙の決裁の詳細へのリンクにする（手で入れていた紙の番号も、取り込んだあとはリンクになる。設計書 §5.15・D21・§0.8）。

**Files:**
- Create: `app/Http/Controllers/Approval/PaperDetailController.php`・`resources/views/approvals/papers/show.blade.php`
- Modify: `app/Models/ApprovalPaperRecord.php`・`app/Http/Controllers/Approval/RelatedNumberController.php`・`app/Http/Controllers/Approval/RequestController.php`・`resources/views/approvals/requests/show.blade.php`・`resources/views/approvals/requests/form.blade.php`・`routes/approval.php`
- Test: Create `tests/Feature/Approval/Phase5/PaperDetailTest.php`／Modify `tests/Feature/Approval/ApprovalAdminGateTest.php`・`tests/Feature/Approval/Phase2/LaunchGateTest.php`

**Interfaces:**
- Consumes: Task 3 の `RequestVisibility::canViewPaper()`・`visibleTo()`
- Produces: ルート `approvals.papers.show`（GET `/approvals/papers/{approvalPaperRecord}`。`approval.launched` の中・見られない記録は 404）・`ApprovalPaperRecord::DECISION_LABEL`（`'不明'`。Task 8 の台帳と Excel も使う）
- Produces: 関連する決裁No の候補の JSON（紙の候補だけに `paper: true`）・`RequestController::relatedLinks()` の戻り値は 番号 => 詳細の URL

**差分の大きさ:** 11 ファイル・+285 / −15 行（差分のファイル `0007-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase5/PaperDetailTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalPaperRecord;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 紙の決裁の詳細と、関連する決裁No の候補・リンク（段階5 設計書 §5.12・§5.15・D21）。
 * 見られるのは、その部門に所属する人・その部門の部門長・全件閲覧者・決裁の管理者だけ（申請の審査担当者や社長だけでは見られない）
 */
class PaperDetailTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private array $w;

    private ApprovalPaperRecord $paper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->launchApprovals();
        $this->w     = $this->approvalWorld();   // 住宅事業部 J（部門長・所属の申請者）・総務部 S（審査担当者）・社長
        $this->paper = $this->paperRecord($this->w['dept'], ['seq' => 21, 'subject' => '見本倉庫購入', 'amount' => 50000000, 'cost' => 42000000, 'profit' => 8000000, 'note' => "原本は総務の棚\n2 段目"]);
    }

    private function show(User $user): TestResponse
    {
        return $this->actingAs($user)->get(route('approvals.papers.show', $this->paper));
    }

    public function test_the_people_who_may_see_it_can_open_it(): void
    {
        foreach (['所属する人' => $this->w['applicant'], '部門長' => $this->w['head'], '全件閲覧者' => $this->viewAllUser(), '決裁の管理者' => $this->approvalAdmin()] as $who => $user) {
            $this->show($user)->assertOk()->assertSee('見本倉庫購入');
        }
        foreach (['ほかの部門の審査担当者' => $this->w['reviewer'], '社長（全件閲覧者でない）' => $this->w['president'], '決裁のみ利用者' => $this->approvalOnlyUser()] as $who => $user) {
            $this->show($user)->assertNotFound();
        }
    }

    public function test_the_detail_shows_the_record_and_offers_the_edit_to_admins(): void
    {
        $html = $this->show($this->w['applicant'])->getContent();

        foreach (['R8-J-021', '紙の決裁です。原本は紙で保管しています。', '2026/05/13', '甲野', '50,000,000円', '42,000,000円', '8,000,000円', '16.0%', "原本は総務の棚\n2 段目"] as $text) {
            $this->assertStringContainsString(e($text), $html);
        }
        $this->assertStringNotContainsString(route('approvals.admin.papers.edit', $this->paper), $html, '管理者でない人に「直す」を出さない');

        $this->assertStringContainsString('href="' . route('approvals.admin.papers.edit', $this->paper) . '"', $this->show($this->approvalAdmin())->getContent());

        // 金額の無い記録は「—」（0 円と分ける）
        $empty = $this->paperRecord($this->w['dept'], ['seq' => 22]);
        $html  = $this->actingAs($this->w['applicant'])->get(route('approvals.papers.show', $empty))->getContent();
        $this->assertSame(4, substr_count($html, '<dd class="text-gray-900 tabular-nums">—</dd>'), '金額・工事原価・粗利益・粗利率');
    }

    /** 使い始める前は誰にも見せない（D11） */
    public function test_it_is_closed_before_launch(): void
    {
        ApprovalSetting::current()->update(['launched_at' => null]);

        $this->assertSame(302, $this->show($this->approvalAdmin())->getStatusCode());
    }

    // --- 関連する決裁No ---

    /** 申請の詳細の関連する決裁No は、見られる紙の決裁ならその詳細へのリンクにする */
    public function test_a_related_paper_number_links_to_the_paper(): void
    {
        $this->paperRecord($this->w['reviewDept'], ['seq' => 5]);   // 総務部の紙（住宅事業部の部門長は見られない）
        $request = $this->submittedFor($this->w, ['related_numbers' => ['R8-J-021', 'R8-S-005', 'R7-J-001']]);

        $html = $this->actingAs($this->w['head'])->get(route('approvals.requests.show', $request))->assertOk()->getContent();
        $this->assertStringContainsString('<a href="' . route('approvals.papers.show', $this->paper) . '" class="font-mono text-emerald-600 hover:underline mr-2">R8-J-021</a>', $html);
        $this->assertStringContainsString('<span class="font-mono mr-2">R8-S-005</span>', $html, '見られない紙の決裁はリンクにしない');
        $this->assertStringContainsString('<span class="font-mono mr-2">R7-J-001</span>', $html, '取り込んでいない紙の番号は文字だけ');
    }

    /** 候補に見られる紙の決裁も出す（`paper: true`）。申請と合わせて決裁日の新しい順 */
    public function test_visible_papers_are_offered_as_candidates(): void
    {
        $request = $this->draftFor($this->w, ['subject' => '見本倉庫の改修']);
        DB::table('approval_requests')->where('id', $request->id)->update(['status' => 'approved', 'decision' => 'approve', 'number' => 'R8-J-030', 'round' => 1, 'decided_at' => '2026-05-20 01:00:00']);
        $this->paperRecord($this->w['reviewDept'], ['seq' => 3, 'subject' => '見本倉庫の見積り']);   // 総務部の紙（申請者は見られない）

        $search = fn (User $user) => $this->actingAs($user)->getJson(route('approvals.numbers.search') . '?q=' . rawurlencode('見本倉庫'), ['X-Requested-With' => 'XMLHttpRequest']);

        $search($this->w['applicant'])->assertOk()->assertExactJson(['items' => [
            ['number' => 'R8-J-030', 'subject' => '見本倉庫の改修'],
            ['number' => 'R8-J-021', 'subject' => '見本倉庫購入', 'paper' => true],
        ]]);
        $this->assertSame(['R8-J-030', 'R8-S-003', 'R8-J-021'], array_column($search($this->approvalAdmin())->json('items'), 'number'), '決裁日の新しい順');

        // 番号でも当たる
        $this->actingAs($this->w['applicant'])->getJson(route('approvals.numbers.search') . '?q=r8-j-021', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertExactJson(['items' => [['number' => 'R8-J-021', 'subject' => '見本倉庫購入', 'paper' => true]]]);

        // 申請書の画面は候補に「紙」と添える
        $this->assertStringContainsString('<span x-show="item.paper"', $this->actingAs($this->w['applicant'])->get(route('approvals.requests.create'))->getContent());
    }

    /** 紙の決裁のほうが新しければ、申請より先に並べる（申請と紙を別々に読んでから、決裁日の新しい順に並べ直す） */
    public function test_a_newer_paper_comes_before_an_older_request(): void
    {
        $request = $this->draftFor($this->w, ['subject' => '見本倉庫の改修']);
        DB::table('approval_requests')->where('id', $request->id)->update(['status' => 'approved', 'decision' => 'approve', 'number' => 'R8-J-030', 'round' => 1, 'decided_at' => '2026-05-01 01:00:00']);

        $items = $this->actingAs($this->w['applicant'])->getJson(route('approvals.numbers.search') . '?q=' . rawurlencode('見本倉庫'), ['X-Requested-With' => 'XMLHttpRequest'])->json('items');

        $this->assertSame(['R8-J-021', 'R8-J-030'], array_column($items, 'number'), '紙 5/13 → 申請 5/1');
    }

    /** 同じ決裁日の申請と紙の決裁は、申請を先に並べる */
    public function test_a_request_comes_before_a_paper_of_the_same_day(): void
    {
        $request = $this->draftFor($this->w, ['subject' => '見本倉庫の改修']);
        DB::table('approval_requests')->where('id', $request->id)->update(['status' => 'approved', 'decision' => 'approve', 'number' => 'R8-J-030', 'round' => 1, 'decided_at' => '2026-05-13 01:00:00']);

        $items = $this->actingAs($this->w['applicant'])->getJson(route('approvals.numbers.search') . '?q=' . rawurlencode('見本倉庫'), ['X-Requested-With' => 'XMLHttpRequest'])->json('items');

        $this->assertSame(['R8-J-030', 'R8-J-021'], array_column($items, 'number'), '日本時間で同じ 5/13');
    }

    /** 候補の数は申請と紙を合わせて上限まで */
    public function test_candidates_are_capped_across_requests_and_papers(): void
    {
        for ($seq = 1; $seq <= 12; $seq++) {
            $this->paperRecord($this->w['dept'], ['seq' => 100 + $seq, 'subject' => "見本倉庫 {$seq}", 'decided_on' => sprintf('2026-06-%02d', $seq)]);
        }

        $items = $this->actingAs($this->w['applicant'])->getJson(route('approvals.numbers.search') . '?q=' . rawurlencode('見本倉庫'), ['X-Requested-With' => 'XMLHttpRequest'])->json('items');

        $this->assertCount(10, $items);
        $this->assertSame('R8-J-112', $items[0]['number']);
        $this->assertSame(0, ApprovalRequest::count());
    }
}
```

走査テストに登録する（§0.11）:

`tests/Feature/Approval/ApprovalAdminGateTest.php`（変更）

```diff
--- a/tests/Feature/Approval/ApprovalAdminGateTest.php
+++ b/tests/Feature/Approval/ApprovalAdminGateTest.php
@@ -112,6 +112,8 @@ class ApprovalAdminGateTest extends TestCase
         'approvals.notices.index'   => 'お知らせ一覧（自分のお知らせだけ。段階3 設計書 §5.6）',
         'approvals.notices.open'    => 'お知らせを開く（自分のお知らせだけ。ほかの人のものは 404）',
         'approvals.notices.readAll' => '自分のお知らせをすべて既読にする',
+        // 段階5（使い始めてから）
+        'approvals.papers.show' => '紙の決裁の詳細（見られる範囲だけ。見られない記録は 404。段階5 設計書 §5.15）',
     ];
 
     /** ラベル用: HEAD を除いた先頭の HTTP メソッド（1 つで十分な場所） */
```

`tests/Feature/Approval/Phase2/LaunchGateTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/LaunchGateTest.php
+++ b/tests/Feature/Approval/Phase2/LaunchGateTest.php
@@ -34,10 +34,10 @@ class LaunchGateTest extends TestCase
 
     /**
      * 門番の付いたルートの数の下限（空振りで緑にならないように）。
-     * 実数 23 本 = 候補の検索 1・申請書と詳細 7・添付 3・判断など 5（2a）＋ 進行中の申請の管理 4（2b。管理の門番も持つ）
-     * ＋ お知らせ 3（3a）。
+     * 実数 27 本 = 候補の検索 1・申請書と詳細 7・添付 3・判断など 5（2a）＋ 進行中の申請の管理 4（2b。管理の門番も持つ）
+     * ＋ お知らせ 3（3a）＋ PDF 1・台帳と Excel 2（4b）＋ 紙の決裁の詳細 1（5b）。
      */
-    private const MIN_GATED = 23;
+    private const MIN_GATED = 27;
 
     private function isOpenBeforeLaunch(string $name): bool
     {
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/5b/patches/0007-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/PaperDetailTest.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase2/LaunchGateTest.php
```

Expected: `ERRORS!` `Tests: 16, Assertions: 58, Errors: 4, Failures: 6.`

- `PaperDetailTest::test_the_people_who_may_see_it_can_open_it` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.papers.show] not defined.`
- `PaperDetailTest::test_the_detail_shows_the_record_and_offers_the_edit_to_admins` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.papers.show] not defined.`
- `PaperDetailTest::test_it_is_closed_before_launch` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.papers.show] not defined.`
- `PaperDetailTest::test_a_related_paper_number_links_to_the_paper` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.papers.show] not defined.`
- `ApprovalAdminGateTest::test_every_approvals_route_is_classified` — `分類漏れ・門番の欠落・逆方向の見落とし:`
- `LaunchGateTest::test_every_approval_route_is_classified` — `門番の付いたルートが少なすぎる（分類が空振りしていないか）`
- `PaperDetailTest::test_visible_papers_are_offered_as_candidates` — `Failed asserting that two strings are equal.`
- `PaperDetailTest::test_a_newer_paper_comes_before_an_older_request` — `紙 5/13 → 申請 5/1`
- `PaperDetailTest::test_a_request_comes_before_a_paper_of_the_same_day` — `日本時間で同じ 5/13`
- `PaperDetailTest::test_candidates_are_capped_across_requests_and_papers` — `Failed asserting that actual size 0 matches expected size 10.`

- [ ] **Step 3: 紙の決裁の詳細**

`app/Models/ApprovalPaperRecord.php`（変更）

```diff
--- a/app/Models/ApprovalPaperRecord.php
+++ b/app/Models/ApprovalPaperRecord.php
@@ -43,6 +43,9 @@ protected function casts(): array
         ];
     }
 
+    /** 判断（紙の台帳に記録が無い。詳細・台帳・Excel・台帳の絞り込みで同じ文字。D22・D23） */
+    public const DECISION_LABEL = '不明';
+
     /** 年度の選択肢のいちばん古い年度（平成元年度。紙の台帳がそれより前にあることは無い） */
     public const OLDEST_YEAR = 1989;
```

`app/Http/Controllers/Approval/PaperDetailController.php`（新規）

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalPaperRecord;
use App\Support\Approval\RequestVisibility;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 紙の決裁の詳細（段階5 設計書 §5.15・D21）。台帳の行と関連する決裁No のリンクの先。
 *
 * ⚠ 見られる人だけ（RequestVisibility の紙の決裁の規則）。見られない記録は 404（申請の詳細と同じ。在ることも知らせない）
 * ⚠ 使い始めてから（approval.launched の中）。決裁の管理者の登録・直し・削除は PaperRecordController（使い始める前から）
 * ⚠ 回覧の操作は無い。あとでスキャンした PDF を付けるときもこの画面に足す（D8）
 */
class PaperDetailController extends Controller
{
    /** Route: GET /approvals/papers/{approvalPaperRecord} */
    public function show(Request $request, ApprovalPaperRecord $approvalPaperRecord): View
    {
        abort_unless(RequestVisibility::canViewPaper($request->user(), $approvalPaperRecord), 404);

        return view('approvals.papers.show', ['record' => $approvalPaperRecord->load('department.company')]);
    }
}
```

`resources/views/approvals/papers/show.blade.php`（新規）

```blade
@extends('layouts.app')

@section('title', $record->number)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.ledger.index') }}" class="hover:text-emerald-600 transition-colors">決裁台帳</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">紙の決裁</span>
@endsection

{{-- 紙の決裁の詳細（段階5 設計書 §5.15・D21）。見られる人だけ。
     ⚠ 決裁日は日付（時刻なし）なので ->format() のまま（JapanTime::format で時差を足さない）
     ⚠ 金額が空の欄は「—」（0 円と分ける） --}}
@section('content')
@php
    $yen = fn (?int $value) => $value === null ? '—' : \App\Support\Approval\AmountTable::yen($value);
@endphp
<div class="max-w-[880px]">
    <div class="flex flex-wrap items-center gap-2 mb-1">
        <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold bg-gray-100 text-gray-600">紙</span>
        <span class="text-[13px] font-mono font-semibold text-gray-800">{{ $record->number }}</span>
        @if(Auth::user()->isApprovalAdmin())
            <a href="{{ route('approvals.admin.papers.edit', $record) }}" class="ml-auto inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md bg-white text-[12px] text-gray-700 hover:bg-gray-50">直す</a>
        @endif
    </div>
    <h1 class="text-lg font-bold text-gray-900 mb-2 break-words">{{ $record->subject }}</h1>
    <p class="mb-5 text-[13px] text-gray-600">紙の決裁です。原本は紙で保管しています。</p>

    <section class="bg-white rounded-lg border border-gray-200">
        <dl class="px-5 py-4 grid grid-cols-1 sm:grid-cols-[9em_1fr] gap-x-4 gap-y-2 text-[13px]">
            <dt class="text-gray-500">決裁No</dt>
            <dd class="text-gray-900 font-mono">{{ $record->number }}</dd>
            <dt class="text-gray-500">決裁日</dt>
            <dd class="text-gray-900">{{ $record->decided_on->format('Y/m/d') }}</dd>
            <dt class="text-gray-500">判断</dt>
            <dd class="text-gray-900">{{ \App\Models\ApprovalPaperRecord::DECISION_LABEL }}（紙の台帳に記録が無い）</dd>
            <dt class="text-gray-500">部門</dt>
            <dd class="text-gray-900">{{ $record->department->company->name }} / {{ $record->department->name }}</dd>
            <dt class="text-gray-500">申請者</dt>
            <dd class="text-gray-900">{{ $record->applicant_name ?? '—' }}</dd>
            <dt class="text-gray-500">金額</dt>
            <dd class="text-gray-900 tabular-nums">{{ $yen($record->amount) }}</dd>
            <dt class="text-gray-500">工事原価</dt>
            <dd class="text-gray-900 tabular-nums">{{ $yen($record->cost) }}</dd>
            <dt class="text-gray-500">粗利益</dt>
            <dd class="text-gray-900 tabular-nums">{{ $yen($record->profit) }}</dd>
            <dt class="text-gray-500">粗利率</dt>
            <dd class="text-gray-900 tabular-nums">{{ \App\Support\Approval\AmountTable::rateLabel($record->rate()) }}</dd>
            <dt class="text-gray-500">備考</dt>
            <dd class="text-gray-900 whitespace-pre-line break-words">{{ $record->note ?? '—' }}</dd>
        </dl>
    </section>
</div>
@endsection
```

`routes/approval.php`（変更）

```diff
--- a/routes/approval.php
+++ b/routes/approval.php
@@ -6,6 +6,7 @@
 use App\Http\Controllers\Approval\LedgerController;
 use App\Http\Controllers\Approval\NoticeController;
 use App\Http\Controllers\Approval\OrganizationController;
+use App\Http\Controllers\Approval\PaperDetailController;
 use App\Http\Controllers\Approval\PaperImportController;
 use App\Http\Controllers\Approval\PaperRecordController;
 use App\Http\Controllers\Approval\RelatedNumberController;
@@ -162,6 +163,9 @@
     // 決裁台帳の Excel（§5.9）。台帳と同じ条件で全件・出力のたびに記録（§5.10）
     Route::get('/ledger/excel', [LedgerController::class, 'excel'])->name('ledger.excel');
 
+    // 紙の決裁の詳細（段階5 設計書 §5.15・D21）。見られる人だけ（見られない記録は 404）。⚠ パラメータ名は `{approvalPaperRecord}`
+    Route::get('/papers/{approvalPaperRecord}', [PaperDetailController::class, 'show'])->name('papers.show');
+
     // 添付（段階2 設計書 §5.7・計画 §0.5）。追加と外すのは Ajax・JSON
     Route::post('/requests/{approvalRequest}/attachments', [RequestAttachmentController::class, 'store'])->name('requests.attachments.store');
     Route::get('/attachments/{approvalAttachment}', [RequestAttachmentController::class, 'show'])->name('attachments.show');
```

- [ ] **Step 4: 関連する決裁No の候補とリンク**

`app/Http/Controllers/Approval/RelatedNumberController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/RelatedNumberController.php
+++ b/app/Http/Controllers/Approval/RelatedNumberController.php
@@ -4,10 +4,12 @@
 
 use App\Enums\ApprovalStatus;
 use App\Http\Controllers\Controller;
+use App\Models\ApprovalPaperRecord;
 use App\Models\ApprovalRequest;
 use App\Models\ApprovalRevision;
 use App\Support\Approval\RelatedNumbers;
 use App\Support\Approval\RequestVisibility;
+use App\Support\JapanTime;
 use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Database\Query\Builder as QueryBuilder;
 use Illuminate\Http\JsonResponse;
@@ -23,6 +25,8 @@
  * ⚠ 差戻し中の申請と、差戻し中に直して保存してから取り下げた申請は、最後に提出した控えの件名で当て、控えの件名を返す
  *   （取り消しで番号を残したまま差戻しに戻った申請の直しかけを、ほかの人に漏らさない。D26・設計書 §5.16・2b 計画 Task 6）。
  *   ほかの状態は中身を直せないので、今の件名が最後に提出した件名と同じ。
+ * ⚠ 見られる紙の決裁も候補に出す（段階5 §5.15。`paper: true` を付け、画面は「紙」と添える。申請の候補には付けない）。
+ *   申請と紙を合わせて決裁日の新しい順に並べ、上限で切る（同じ日は申請が先）
  */
 class RelatedNumberController extends Controller
 {
@@ -61,7 +65,7 @@ public function search(Request $request): JsonResponse
             ->orderByDesc('decided_at')
             ->orderByDesc('id')
             ->limit(self::LIMIT)
-            ->get(['id', 'number', 'subject', 'status', 'round']);
+            ->get(['id', 'number', 'subject', 'status', 'round', 'decided_at']);
 
         // 差戻し中と取り下げの申請は、最後に提出した控えの件名を返す（1 回の問い合わせで読む）
         $submitted = ApprovalRevision::whereIn('request_id', $found->filter(fn (ApprovalRequest $r) => in_array($r->status, $fromRevision, true))->pluck('id')->all())
@@ -71,11 +75,27 @@ public function search(Request $request): JsonResponse
 
         $items = $found
             ->map(fn (ApprovalRequest $r) => [
-                'number'  => $r->number,
-                'subject' => in_array($r->status, $fromRevision, true) ? ($submitted["{$r->id}:{$r->round}"] ?? null) : $r->subject,
-            ])
-            ->all();
+                'day'  => JapanTime::format($r->decided_at, 'Y-m-d') ?? '',
+                'item' => [
+                    'number'  => $r->number,
+                    'subject' => in_array($r->status, $fromRevision, true) ? ($submitted["{$r->id}:{$r->round}"] ?? null) : $r->subject,
+                ],
+            ]);
+
+        $papers = ApprovalPaperRecord::query()->visibleTo($request->user())
+            ->where(fn (Builder $q) => $q->where('number', 'like', "%{$number}%")->orWhere('subject', 'like', "%{$text}%"))
+            ->orderByDesc('decided_on')
+            ->orderByDesc('id')
+            ->limit(self::LIMIT)
+            ->get(['id', 'number', 'subject', 'decided_on'])
+            ->map(fn (ApprovalPaperRecord $p) => [
+                'day'  => $p->decided_on->format('Y-m-d'),
+                'item' => ['number' => $p->number, 'subject' => $p->subject, 'paper' => true],
+            ]);
+
+        // 決裁日の新しい順（同じ日は申請が先。sortBy は同じ値の順を保つ）
+        $merged = $items->concat($papers)->sortByDesc('day')->take(self::LIMIT)->pluck('item')->values()->all();
 
-        return response()->json(['items' => $items]);
+        return response()->json(['items' => $merged]);
     }
 }
```

`app/Http/Controllers/Approval/RequestController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/RequestController.php
+++ b/app/Http/Controllers/Approval/RequestController.php
@@ -7,6 +7,7 @@
 use App\Http\Controllers\Controller;
 use App\Models\ApprovalHistory;
 use App\Models\ApprovalNotice;
+use App\Models\ApprovalPaperRecord;
 use App\Models\ApprovalRequest;
 use App\Models\ApprovalRevision;
 use App\Models\ApprovalStep;
@@ -470,10 +471,11 @@ private function tableRows(ApprovalRequest $approvalRequest): array
     }
 
     /**
-     * 関連する決裁No のうち、この人が見られる申請（番号 => 申請の id）
+     * 関連する決裁No のうち、この人が見られる申請と紙の決裁（番号 => 詳細の URL。段階5 §5.15。手で入れていた紙の番号も、
+     * 取り込んだあとはリンクになる）。番号は申請と紙で重ならない（取り込みと 1 件ずつの登録が断る）
      *
      * @param list<string> $numbers 画面に出す中身の関連する決裁No（申請者以外には最後に提出した控えのもの。RequestContent）
-     * @return array<string, int>
+     * @return array<string, string>
      */
     private function relatedLinks(array $numbers, User $user): array
     {
@@ -481,10 +483,16 @@ private function relatedLinks(array $numbers, User $user): array
             return [];
         }
 
-        return RequestVisibility::apply(ApprovalRequest::query(), $user)
+        $requests = RequestVisibility::apply(ApprovalRequest::query(), $user)
             ->whereIn('number', $numbers)
             ->pluck('id', 'number')
-            ->all();
+            ->map(fn (int $id) => route('approvals.requests.show', $id));
+        $papers   = ApprovalPaperRecord::query()->visibleTo($user)
+            ->whereIn('number', $numbers)
+            ->pluck('id', 'number')
+            ->map(fn (int $id) => route('approvals.papers.show', $id));
+
+        return $papers->merge($requests)->all();
     }
 
     /**
```

`resources/views/approvals/requests/show.blade.php`（変更）

```diff
--- a/resources/views/approvals/requests/show.blade.php
+++ b/resources/views/approvals/requests/show.blade.php
@@ -69,9 +69,9 @@ class="ml-auto inline-flex items-center gap-1 px-3 py-1.5 border border-gray-300
             <dt class="text-gray-500">関連する決裁No</dt>
             <dd class="text-gray-900">
                 @forelse($content->relatedNumbers as $number)
-                    {{-- 見られる申請はリンクにする（紙の時代の番号・見られない申請は文字だけ。設計書 §5.6） --}}
+                    {{-- 見られる申請と取り込んだ紙の決裁はリンクにする（取り込んでいない紙の番号・見られない申請は文字だけ。設計書 §5.6・段階5 §5.15） --}}
                     @if(isset($relatedLinks[$number]))
-                        <a href="{{ route('approvals.requests.show', $relatedLinks[$number]) }}" class="font-mono text-emerald-600 hover:underline mr-2">{{ $number }}</a>
+                        <a href="{{ $relatedLinks[$number] }}" class="font-mono text-emerald-600 hover:underline mr-2">{{ $number }}</a>
                     @else
                         <span class="font-mono mr-2">{{ $number }}</span>
                     @endif
```

`resources/views/approvals/requests/form.blade.php`（変更）

```diff
--- a/resources/views/approvals/requests/form.blade.php
+++ b/resources/views/approvals/requests/form.blade.php
@@ -244,6 +244,7 @@ class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] disa
                             <button type="button" @click="addNumber(item.number)" class="w-full text-left px-3 py-2 text-[12px] hover:bg-emerald-50 cursor-pointer">
                                 <span class="font-mono text-gray-900" x-text="item.number"></span>
                                 <span class="text-gray-500 ml-1" x-text="item.subject"></span>
+                                <span x-show="item.paper" class="ml-1 inline-block px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 text-[10px] font-semibold">紙</span>
                             </button>
                         </li>
                     </template>
```

（差分のファイルを使うなら、Step 3〜4 をまとめて: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/5b/patches/0007-*.patch`）

- [ ] **Step 5: テストを流して通ることを確かめる**（Step 2 と、関連する決裁No・申請の詳細のテスト）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/PaperDetailTest.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase2
```

Expected: `OK`（赤なし。`RelatedNumberSearchTest` の申請の候補の JSON は変わらない）

- [ ] **Step 6: 全件を流す**

Expected: `OK (4170 tests, 39220 assertions)`

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Models/ApprovalPaperRecord.php app/Http/Controllers/Approval/PaperDetailController.php resources/views/approvals/papers/show.blade.php routes/approval.php app/Http/Controllers/Approval/RelatedNumberController.php app/Http/Controllers/Approval/RequestController.php resources/views/approvals/requests/show.blade.php resources/views/approvals/requests/form.blade.php tests/Feature/Approval/Phase5/PaperDetailTest.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase2/LaunchGateTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 紙の決裁の詳細を足し関連する決裁No から開けるようにする

見られる人が開ける紙の決裁の詳細の画面を作り、申請の画面の関連する
決裁No の候補に見られる紙の決裁も出す。申請の詳細の関連する決裁No は、
取り込んだ紙の決裁ならその詳細へのリンクにする（段階5 設計書 §5.15・
D21）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 8: 決裁台帳と Excel に紙の決裁を並べる（判断「不明」・区分の絞り込み・20 列）

見られる紙の決裁を、決裁台帳と Excel に申請と同じ並び（決裁日の新しい順・同じ日は決裁No の順）で出す。絞り込みは設計書 §5.13 の表のとおり（判断「不明」と区分は新しく足す）。Excel は「区分」の列を状態の前に足して 20 列にする（設計書 §5.13・§5.14・D22・D23・§0.9）。

**Files:**
- Modify: `app/Support/Approval/Ledger.php`・`app/Support/Approval/LedgerFilter.php`・`app/Support/Approval/LedgerRow.php`・`app/Support/Approval/LedgerExcel.php`・`app/Support/Approval/NameSearch.php`・`app/Http/Controllers/Approval/LedgerController.php`・`resources/views/approvals/ledger/index.blade.php`
- Test: Create `tests/Feature/Approval/Phase5/PaperLedgerTest.php`／Modify `tests/Feature/Approval/Phase4/LedgerQueryTest.php`・`tests/Feature/Approval/Phase4/LedgerRowTest.php`・`tests/Feature/Approval/Phase4/LedgerExcelTest.php`・`tests/Feature/Approval/Phase4/LedgerFilterTest.php`・`tests/Feature/Approval/Phase5/LedgerExcelTableTest.php`

**Interfaces:**
- Consumes: Task 3 の `visibleTo()`・Task 7 の `approvals.papers.show`・`ApprovalPaperRecord::DECISION_LABEL`・Task 2 の `ApprovalPaperRecord::rate()`
- Produces: `Ledger::sortedKeys(User $viewer, LedgerFilter $filter): list<string>`（`sortedIds()` を置き換える）・`Ledger::rows(User $viewer, list<string> $keys): Collection<int, LedgerRow>`・`Ledger::requestKey(int): string`・`Ledger::paperKey(int): string`・`Ledger::paperQuery(User, LedgerFilter): Builder`
- Produces: `LedgerFilter::KINDS`（`all`・`system`・`paper`）・`DEFAULT_KIND`・`DECISION_UNKNOWN`（`'unknown'`）・`$kind`・`$decisionUnknown`・`includesRequests(): bool`・`includesPapers(): bool`・`decisionKey(): ?string`・`toLog()` に `kind`
- Produces: `LedgerRow::forPaper(ApprovalPaperRecord): self`・`$isPaper`・`url(): string`・`kindLabel(): string`・`badgeLabel(): string`（判断の文字は Task 7 の `ApprovalPaperRecord::DECISION_LABEL`）
- Produces: `NameSearch::whereNameHasAll(Builder $query, list<string> $terms, string $column = 'users.name')`・`NameSearch::PAPER_APPLICANT`

**差分の大きさ:** 13 ファイル・+533 / −82 行（差分のファイル `0008-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase5/PaperLedgerTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalDownloadLog;
use App\Models\ApprovalPaperRecord;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\Ledger;
use App\Support\Approval\LedgerFilter;
use App\Support\Approval\LedgerRow;
use App\Support\Approval\NameSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 決裁台帳と Excel に取り込んだ紙の決裁を並べる（要件 10・段階5 設計書 §5.13・§5.14・D10・D22・D23）。
 *
 * 紙の行: 同じ並び（決裁日の新しい順・同じ日は決裁No の順）・判断「不明」・状態の列に灰色の「紙」・行の先は紙の決裁の詳細・
 * Excel は区分「紙」で状態は空。絞り込みは表（§5.13）のとおり。見られる範囲は紙の規則（その部門の人・部門長・全件閲覧者・管理者）
 */
class PaperLedgerTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 03:00:00', 'UTC'));   // R8 年度（5 月始まり）
        $this->launchApprovals();
        $this->w = $this->approvalWorld();   // 住宅事業部 J・総務部 S
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 番号の付いた申請（並びと絞り込みだけを見るので、状態と番号は直接入れる） */
    private function decided(string $number, int $seq, string $decidedAt, string $subject = '社用車の購入'): ApprovalRequest
    {
        $request = $this->draftFor($this->w, ['subject' => $subject]);
        DB::table('approval_requests')->where('id', $request->id)->update([
            'status' => 'approved', 'decision' => 'approve', 'number' => $number, 'number_department_id' => $this->w['dept']->id,
            'number_fiscal_year' => 2026, 'number_seq' => $seq, 'round' => 1, 'decided_at' => $decidedAt, 'last_submitted_at' => $decidedAt,
        ]);

        return $request->refresh();
    }

    /** @return list<string> */
    private function keys(User $viewer, array $query = []): array
    {
        return Ledger::sortedKeys($viewer, LedgerFilter::fromRequest(Request::create('/approvals/ledger', 'GET', $query)));
    }

    private function paper(ApprovalPaperRecord $record): string
    {
        return Ledger::paperKey($record->id);
    }

    private function request(ApprovalRequest $request): string
    {
        return Ledger::requestKey($request->id);
    }

    public function test_papers_are_sorted_together_with_requests(): void
    {
        $r5  = $this->decided('R8-J-005', 5, '2026-06-01 01:00:00');                          // 日本時間 6/1
        $p4  = $this->paperRecord($this->w['dept'], ['seq' => 4, 'decided_on' => '2026-06-01']);   // 同じ日・小さい番号
        $p6  = $this->paperRecord($this->w['dept'], ['seq' => 6, 'decided_on' => '2026-06-01']);
        $old = $this->paperRecord($this->w['dept'], ['seq' => 1, 'decided_on' => '2026-05-07']);
        $new = $this->paperRecord($this->w['reviewDept'], ['seq' => 1, 'decided_on' => '2026-06-02']);

        $this->assertSame(
            [$this->paper($new), $this->paper($p4), $this->request($r5), $this->paper($p6), $this->paper($old)],
            $this->keys($this->viewAllUser())
        );
    }

    /** 見られる紙の決裁だけ（その部門の人・部門長。ほかの部門の紙は出さない） */
    public function test_only_visible_papers_are_listed(): void
    {
        $mine  = $this->paperRecord($this->w['dept']);
        $other = $this->paperRecord($this->w['reviewDept']);

        $this->assertSame([$this->paper($mine)], $this->keys($this->w['applicant']));
        $this->assertSame([$this->paper($mine)], $this->keys($this->w['head']));
        $this->assertSame([], $this->keys($this->w['reviewer']), '審査担当者は所属していない部門（審査部門）の紙を見られない');
        $this->assertEqualsCanonicalizing([$this->paper($mine), $this->paper($other)], $this->keys($this->approvalAdmin()));
    }

    public function test_the_filters_apply_to_papers(): void
    {
        $viewer = $this->viewAllUser();
        $r      = $this->decided('R8-J-009', 9, '2026-06-01 01:00:00', '見本倉庫購入');
        $a      = $this->paperRecord($this->w['dept'], ['seq' => 1, 'decided_on' => '2026-05-10', 'applicant_name' => '甲野 一', 'subject' => '見本倉庫購入', 'note' => null]);
        $b      = $this->paperRecord($this->w['reviewDept'], ['seq' => 2, 'decided_on' => '2026-07-10', 'applicant_name' => '乙山', 'subject' => '事務所の改装', 'note' => '見本倉庫の隣']);
        $c      = $this->paperRecord($this->w['dept'], ['fiscal_year' => 2025, 'seq' => 3, 'decided_on' => '2025-06-10', 'applicant_name' => null, 'subject' => '前の年度']);

        $this->assertEqualsCanonicalizing([$this->paper($a), $this->paper($b)], $this->keys($viewer, ['year' => '2026', 'kind' => 'paper']), '年度は紙の年度');
        $this->assertEqualsCanonicalizing([$this->paper($a), $this->paper($c), $this->request($r)], $this->keys($viewer, ['department' => (string) $this->w['dept']->id]));
        $this->assertSame([$this->paper($b)], $this->keys($viewer, ['from' => '2026-07-10', 'to' => '2026-07-10']), '決裁日の期間は日付で（その日を含む）');
        $this->assertSame([$this->paper($a)], $this->keys($viewer, ['applicant' => '甲野一']), '申請者は台帳の文字（空白は無視）');
        $this->assertEqualsCanonicalizing([$this->request($r), $this->paper($a), $this->paper($b)], $this->keys($viewer, ['q' => '見本倉庫']), 'キーワードは件名と備考');

        // 紙が出ない条件（申請の種類・可／条可／否・進行中・取り下げ・区分「システム」）
        foreach ([['type' => (string) $this->w['type']->id], ['decision' => 'approve'], ['status' => 'progress'], ['status' => 'withdrawn'], ['kind' => 'system']] as $query) {
            $this->assertSame([], array_values(array_filter($this->keys($viewer, $query), fn (string $k) => str_starts_with($k, 'p'))), json_encode($query));
        }
        // 紙だけの条件（判断「不明」・区分「紙」）と、紙も出る状態（すべて）
        $this->assertEqualsCanonicalizing([$this->paper($a), $this->paper($b), $this->paper($c)], $this->keys($viewer, ['decision' => 'unknown']));
        $this->assertEqualsCanonicalizing([$this->paper($a), $this->paper($b), $this->paper($c)], $this->keys($viewer, ['kind' => 'paper']));
        $this->assertCount(4, $this->keys($viewer, ['status' => 'all']));
    }

    public function test_the_years_reach_back_to_the_oldest_paper(): void
    {
        $this->paperRecord($this->w['dept'], ['fiscal_year' => 2018, 'decided_on' => '2018-06-01']);

        $this->assertSame(range(2026, 2018), array_keys(Ledger::years()));
        $this->assertSame('H30 年度（2018）', Ledger::years()[2018]);
    }

    /** 紙の行（D23）。部門は先に読む（行ごとに問い合わせない） */
    public function test_a_paper_row_reads_as_a_paper(): void
    {
        $record = $this->paperRecord($this->w['dept'], ['seq' => 21, 'decided_on' => '2026-06-01', 'applicant_name' => '甲野', 'amount' => 50000000, 'cost' => 42000000, 'profit' => 8000000]);
        for ($seq = 22; $seq <= 30; $seq++) {
            $this->paperRecord($this->w['dept'], ['seq' => $seq]);
        }
        $keys    = array_map(fn (ApprovalPaperRecord $p) => Ledger::paperKey($p->id), ApprovalPaperRecord::orderBy('seq')->get()->all());
        $viewer  = $this->viewAllUser();
        $queries = 0;
        DB::listen(function () use (&$queries): void { $queries++; });
        $rows = Ledger::rows($viewer, $keys);

        $this->assertLessThanOrEqual(2, $queries, '紙の決裁ごとに問い合わせている（N+1）');
        /** @var LedgerRow $row */
        $row = $rows->first();
        $this->assertSame(
            [true, 'R8-J-021', '2026/06/01', '不明', '見本邸新築工事契約', null, '住宅事業部', '甲野', 50000000, 42000000, 8000000, 16.0, '', '紙', '紙', route('approvals.papers.show', $record)],
            [$row->isPaper, $row->number, \App\Support\JapanTime::format($row->decidedAt, 'Y/m/d'), $row->decisionLabel, $row->subject, $row->typeName, $row->departmentName,
                $row->applicantName, $row->amount, $row->cost, $row->profit, $row->rate, $row->statusLabel, $row->kindLabel(), $row->badgeLabel(), $row->url()]
        );
    }

    public function test_the_screen_shows_papers_with_the_new_filters(): void
    {
        $record = $this->paperRecord($this->w['dept'], ['subject' => '見本倉庫購入']);

        $html = $this->actingAs($this->w['applicant'])->get(route('approvals.ledger.index', ['decision' => 'unknown', 'kind' => 'paper']))->assertOk()->getContent();

        $this->assertStringContainsString('<a href="' . route('approvals.papers.show', $record) . '" class="text-emerald-600 hover:underline break-words">見本倉庫購入</a>', $html);
        $this->assertStringContainsString('>紙</span>', $html);
        $this->assertStringContainsString('<option value="unknown" selected>不明</option>', $html);
        $this->assertStringContainsString('<option value="paper" selected>紙</option>', $html);
        // ページ送りと Excel のリンクは条件を持ち回る
        $this->assertStringContainsString('href="' . e(route('approvals.ledger.excel', ['decision' => 'unknown', 'kind' => 'paper'])) . '"', $html);

        // 読み取れない区分は外して知らせる
        $this->actingAs($this->w['applicant'])->get(route('approvals.ledger.index', ['kind' => 'scan']))->assertOk()->assertSee('読み取れない条件があったので、外して探しました（区分）。');
    }

    public function test_the_excel_has_the_paper_rows_and_logs_the_new_filters(): void
    {
        $this->paperRecord($this->w['dept'], ['seq' => 21, 'decided_on' => '2026-06-01', 'amount' => 50000000, 'cost' => 42000000, 'profit' => 8000000]);
        $viewer = $this->viewAllUser();

        $sheet = $this->sheet($viewer, ['decision' => 'unknown']);

        $this->assertSame(['区分', '状態'], [$sheet->getCell('S1')->getValue(), $sheet->getCell('T1')->getValue()]);
        $this->assertSame(['R8-J-021', '不明', '見本邸新築工事契約', null, '住宅事業部', '甲野', 50000000, 42000000, 8000000, '紙', null], array_map(
            fn (string $c) => $sheet->getCell("{$c}2")->getValue(),
            ['A', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'S', 'T']
        ));
        $this->assertSame('2026-06-01', Date::excelToDateTimeObject($sheet->getCell('B2')->getValue())->format('Y-m-d'));
        $this->assertSame('16.0%', $sheet->getCell('K2')->getFormattedValue());
        $this->assertSame('A1:T2', $sheet->getAutoFilter()->getRange());

        $log = ApprovalDownloadLog::sole();
        $this->assertSame(['unknown', 'all', 1], [$log->filters['decision'], $log->filters['kind'], $log->request_count]);
    }

    /** 名前の列は決まったものだけを受け取る（SQL に埋め込むので、ほかの列を渡すと断る） */
    public function test_the_name_search_takes_only_known_columns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NameSearch::whereNameHasAll(User::query(), ['甲野'], 'users.password');
    }

    /** Excel の件数の上限は申請と紙を合わせて数える */
    public function test_the_excel_limit_counts_papers_too(): void
    {
        config(['approval.ledger.excel_limit' => 2]);
        $this->decided('R8-J-009', 9, '2026-06-01 01:00:00');
        $this->paperRecord($this->w['dept'], ['seq' => 1]);
        $this->paperRecord($this->w['dept'], ['seq' => 2]);

        $response = $this->actingAs($this->viewAllUser())->get(route('approvals.ledger.excel'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, ApprovalDownloadLog::count());
    }

    private function sheet(User $viewer, array $query): Worksheet
    {
        $response = $this->actingAs($viewer)->get(route('approvals.ledger.excel', $query))->assertOk();
        $base     = tempnam(sys_get_temp_dir(), 'ledger');
        $path     = $base . '.xlsx';
        file_put_contents($path, $response->getContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);
        @unlink($base);

        return $sheet;
    }
}
```

既存のテストを新しい鍵と 20 列に合わせる（§0.13。中身の期待は変えない）:

`tests/Feature/Approval/Phase4/LedgerQueryTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase4/LedgerQueryTest.php
+++ b/tests/Feature/Approval/Phase4/LedgerQueryTest.php
@@ -42,10 +42,16 @@ protected function tearDown(): void
         parent::tearDown();
     }
 
-    /** @param array<string, string> $query */
+    /**
+     * 並べた申請の id（このテストには紙の決裁が無いので、鍵はどれも申請。紙と合わせた並びは Phase5/PaperLedgerTest）
+     *
+     * @param array<string, string> $query
+     */
     private function ids(User $viewer, array $query = []): array
     {
-        return Ledger::sortedIds($viewer, LedgerFilter::fromRequest(Request::create('/approvals/ledger', 'GET', $query)));
+        $keys = Ledger::sortedKeys($viewer, LedgerFilter::fromRequest(Request::create('/approvals/ledger', 'GET', $query)));
+
+        return array_map(fn (string $key) => (int) substr($key, 1), $keys);   // 申請の鍵は r＋id
     }
 
     /** 提出した申請の状態の列を書き換える（状態の列は $fillable に無い。BuildsApprovalFixtures の注意） */
```

`tests/Feature/Approval/Phase4/LedgerRowTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase4/LedgerRowTest.php
+++ b/tests/Feature/Approval/Phase4/LedgerRowTest.php
@@ -40,7 +40,7 @@ protected function tearDown(): void
 
     private function row(User $viewer, ApprovalRequest $r): LedgerRow
     {
-        return Ledger::rows($viewer, [$r->id])->sole();
+        return Ledger::rows($viewer, [Ledger::requestKey($r->id)])->sole();
     }
 
     private function toPresident(array $w, array $attributes = [], ApprovalStepResult $review = ApprovalStepResult::Hold, ?string $reviewComment = '見積を 2 社取ってください'): ApprovalRequest
@@ -159,7 +159,7 @@ public function test_rows_keep_the_given_order_and_read_everything_in_a_few_quer
         $viewer  = $this->viewAllUser();
         $queries = 0;
         DB::listen(function () use (&$queries): void { $queries++; });
-        $rows = Ledger::rows($viewer, $ids);
+        $rows = Ledger::rows($viewer, array_map(Ledger::requestKey(...), $ids));
 
         $this->assertSame($ids, $rows->pluck('id')->all());
         $this->assertSame(['件名3', '件名2', '件名1'], $rows->pluck('subject')->all());
```

`tests/Feature/Approval/Phase4/LedgerExcelTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase4/LedgerExcelTest.php
+++ b/tests/Feature/Approval/Phase4/LedgerExcelTest.php
@@ -104,14 +104,15 @@ public function test_the_columns_and_the_values_of_a_row(): void
         $response = $this->download($this->viewAllUser())->assertOk();
         $sheet    = $this->sheet($response);
 
-        // 段階5 で工事原価・粗利益金額・粗利率・契約予定日を足した（要件 10 の並び。5W2H の種類では空。Phase5/LedgerExcelTableTest が中身を見る）
+        // 段階5 で工事原価・粗利益金額・粗利率・契約予定日と、状態の前に区分を足した（要件 10 の並び。5W2H の種類では空。
+        // Phase5/LedgerExcelTableTest・PaperLedgerTest が中身を見る）
         $this->assertSame(
-            ['決裁No', '決裁日', '判断', '件名', '申請の種類', '申請部門', '申請者', '金額（税抜）', '工事原価', '粗利益金額', '粗利率', '実施時期', '契約予定日', '関連する決裁No', '提出日', '審査の意見', '審査のコメント', '条件', '状態'],
-            $sheet->rangeToArray('A1:S1')[0]
+            ['決裁No', '決裁日', '判断', '件名', '申請の種類', '申請部門', '申請者', '金額（税抜）', '工事原価', '粗利益金額', '粗利率', '実施時期', '契約予定日', '関連する決裁No', '提出日', '審査の意見', '審査のコメント', '条件', '区分', '状態'],
+            $sheet->rangeToArray('A1:T1')[0]
         );
         $this->assertSame(
-            ['R8-J-001', '条可', '社用車の購入', $w['type']->name, '住宅事業部', '申請 花子', '2026年10月', 'R7-J-003・R7-J-010', '保留', '見積を 2 社取ってください', '納期を確かめること', '条件確認待ち'],
-            array_map(fn (string $c) => $sheet->getCell("{$c}2")->getValue(), ['A', 'C', 'D', 'E', 'F', 'G', 'L', 'N', 'P', 'Q', 'R', 'S'])
+            ['R8-J-001', '条可', '社用車の購入', $w['type']->name, '住宅事業部', '申請 花子', '2026年10月', 'R7-J-003・R7-J-010', '保留', '見積を 2 社取ってください', '納期を確かめること', 'システム', '条件確認待ち'],
+            array_map(fn (string $c) => $sheet->getCell("{$c}2")->getValue(), ['A', 'C', 'D', 'E', 'F', 'G', 'L', 'N', 'P', 'Q', 'R', 'S', 'T'])
         );
         $this->assertSame([null, null, null, null], array_map(fn (string $c) => $sheet->getCell("{$c}2")->getValue(), ['I', 'J', 'K', 'M']), '5W2H の種類では明細表と契約予定日の列は空');
         $this->assertSame(2, $sheet->getHighestRow(), '1 件なので見出しと 1 行');
@@ -169,7 +170,7 @@ public function test_the_heading_is_frozen_and_has_filter_buttons(): void
         $sheet = $this->sheet($this->download($this->viewAllUser())->assertOk());
 
         $this->assertSame('A2', $sheet->getFreezePane());
-        $this->assertSame('A1:S3', $sheet->getAutoFilter()->getRange());
+        $this->assertSame('A1:T3', $sheet->getAutoFilter()->getRange());
         $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
         $this->assertSame('決裁台帳', $sheet->getTitle());
     }
@@ -254,7 +255,7 @@ public function test_each_download_is_recorded_with_the_conditions_and_the_count
             [$log->request_id, $log->attachment_id, (int) $log->user_id, $log->kind, $log->fresh()->request_count, $log->ip_address]
         );
         // MySQL の JSON はキーを並べ替えて返す（RequestSnapshot の注意）。並びに頼らずに比べる
-        $expected = ['year' => 2026, 'department_id' => null, 'type_id' => null, 'applicant' => null, 'decided_from' => null, 'decided_to' => null, 'status' => 'numbered', 'decision' => 'conditional', 'keyword' => '社用車'];
+        $expected = ['year' => 2026, 'department_id' => null, 'type_id' => null, 'applicant' => null, 'decided_from' => null, 'decided_to' => null, 'status' => 'numbered', 'decision' => 'conditional', 'keyword' => '社用車', 'kind' => 'all'];
         $kept     = $log->fresh()->filters;
         ksort($expected);
         ksort($kept);
```

`tests/Feature/Approval/Phase4/LedgerFilterTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase4/LedgerFilterTest.php
+++ b/tests/Feature/Approval/Phase4/LedgerFilterTest.php
@@ -108,11 +108,11 @@ public function test_values_outside_the_choices_are_left_out(): void
     public function test_the_log_keeps_every_key_in_the_same_order(): void
     {
         $this->assertSame(
-            ['year' => null, 'department_id' => null, 'type_id' => null, 'applicant' => null, 'decided_from' => null, 'decided_to' => null, 'status' => 'numbered', 'decision' => null, 'keyword' => null],
+            ['year' => null, 'department_id' => null, 'type_id' => null, 'applicant' => null, 'decided_from' => null, 'decided_to' => null, 'status' => 'numbered', 'decision' => null, 'keyword' => null, 'kind' => 'all'],
             $this->filter([])->toLog()
         );
         $this->assertSame(
-            ['year' => 2026, 'department_id' => 3, 'type_id' => 12, 'applicant' => '山田', 'decided_from' => '2026-05-01', 'decided_to' => '2026-10-05', 'status' => 'progress', 'decision' => 'reject', 'keyword' => '社用車'],
+            ['year' => 2026, 'department_id' => 3, 'type_id' => 12, 'applicant' => '山田', 'decided_from' => '2026-05-01', 'decided_to' => '2026-10-05', 'status' => 'progress', 'decision' => 'reject', 'keyword' => '社用車', 'kind' => 'all'],
             $this->filter(['year' => '2026', 'department' => '3', 'type' => '12', 'applicant' => '山田', 'from' => '2026-05-01', 'to' => '2026-10-05', 'status' => 'progress', 'decision' => 'reject', 'q' => '社用車'])->toLog()
         );
     }
```

`tests/Feature/Approval/Phase5/LedgerExcelTableTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase5/LedgerExcelTableTest.php
+++ b/tests/Feature/Approval/Phase5/LedgerExcelTableTest.php
@@ -85,7 +85,7 @@ private function sheet(User $viewer, array $query = []): Worksheet
 
     private function rowFor(User $viewer, ApprovalRequest $request): LedgerRow
     {
-        return Ledger::rows($viewer, [$request->id])->sole();
+        return Ledger::rows($viewer, [Ledger::requestKey($request->id)])->sole();
     }
 
     public function test_the_four_columns_of_a_table_request(): void
@@ -110,7 +110,7 @@ public function test_the_four_columns_of_a_table_request(): void
         $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('M2')->getDataType());
         $this->assertSame('2026-10-20 00:00:00', Date::excelToDateTimeObject($sheet->getCell('M2')->getValue())->format('Y-m-d H:i:s'));
         $this->assertSame('yyyy/mm/dd', $sheet->getStyle('M2')->getNumberFormat()->getFormatCode());
-        $this->assertSame('A1:S2', $sheet->getAutoFilter()->getRange());
+        $this->assertSame('A1:T2', $sheet->getAutoFilter()->getRange());
     }
 
     /** 5W2H の種類でも契約予定日を使う設定なら、Excel の契約予定日に入る（要件 10・D12。工事原価・粗利益金額・粗利率は空。点検の M-6） */
@@ -260,11 +260,11 @@ public function test_every_status_choice_still_works(): void
         $decided  = $this->decidedContract($w, $this->housingContractType($w));
         $progress = $this->submittedFor($w);
         $viewer   = $this->viewAllUser();
-        $ids      = fn (string $status) => Ledger::sortedIds($viewer, LedgerFilter::fromRequest(Request::create('/', 'GET', ['status' => $status])));
+        $keys     = fn (string $status) => Ledger::sortedKeys($viewer, LedgerFilter::fromRequest(Request::create('/', 'GET', ['status' => $status])));
 
-        $this->assertSame([$decided->id], $ids('numbered'));
-        $this->assertSame([$progress->id], $ids('progress'));
-        $this->assertSame([], $ids('withdrawn'));
-        $this->assertEqualsCanonicalizing([$decided->id, $progress->id], $ids('all'));
+        $this->assertSame([Ledger::requestKey($decided->id)], $keys('numbered'));
+        $this->assertSame([Ledger::requestKey($progress->id)], $keys('progress'));
+        $this->assertSame([], $keys('withdrawn'));
+        $this->assertEqualsCanonicalizing([Ledger::requestKey($decided->id), Ledger::requestKey($progress->id)], $keys('all'));
     }
 }
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/5b/patches/0008-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/PaperLedgerTest.php tests/Feature/Approval/Phase4 tests/Feature/Approval/Phase5/LedgerExcelTableTest.php
```

Expected: `ERRORS!` `Tests: 68, Assertions: 143, Errors: 32, Failures: 10.`

- `LedgerQueryTest::test_drafts_are_never_listed_even_for_their_applicant` — `Error: Call to undefined method App\Support\Approval\Ledger::sortedKeys()`
- `LedgerQueryTest::test_it_lists_only_what_the_viewer_may_see` — `Error: Call to undefined method App\Support\Approval\Ledger::sortedKeys()`
- `LedgerQueryTest::test_the_default_is_the_numbered_ones` — `Error: Call to undefined method App\Support\Approval\Ledger::sortedKeys()`
- `LedgerQueryTest::test_in_progress_includes_every_step_and_a_returned_request` — `Error: Call to undefined method App\Support\Approval\Ledger::sortedKeys()`
- `LedgerQueryTest::test_the_decision_filter` — `Error: Call to undefined method App\Support\Approval\Ledger::sortedKeys()`
- `LedgerQueryTest::test_the_decided_dates_are_days_of_the_japanese_calendar` — `Error: Call to undefined method App\Support\Approval\Ledger::sortedKeys()`
- `LedgerQueryTest::test_the_applicant_is_found_by_part_of_the_name_and_by_every_word` — `Error: Call to undefined method App\Support\Approval\Ledger::sortedKeys()`
- `LedgerQueryTest::test_the_keyword_is_found_in_the_subject_or_the_body_with_every_word` — `Error: Call to undefined method App\Support\Approval\Ledger::sortedKeys()`
- `LedgerQueryTest::test_the_department_and_the_type_filters` — `Error: Call to undefined method App\Support\Approval\Ledger::sortedKeys()`
- `LedgerQueryTest::test_others_search_what_was_last_submitted_while_it_is_returned` — `Error: Call to undefined method App\Support\Approval\Ledger::sortedKeys()`
- `LedgerQueryTest::test_a_request_withdrawn_after_edits_is_searched_by_what_was_last_submitted` — `Error: Call to undefined method App\Support\Approval\Ledger::sortedKeys()`
- `LedgerQueryTest::test_others_search_the_latest_of_several_submissions` — `Error: Call to undefined method App\Support\Approval\Ledger::sortedKeys()`
- `LedgerQueryTest::test_others_count_the_year_of_an_unnumbered_request_by_the_submitted_department` — `Error: Call to undefined method App\Support\Approval\Ledger::sortedKeys()`
- `LedgerQueryTest::test_the_year_of_a_numbered_request_is_the_year_of_its_number` — `Error: Call to undefined method App\Support\Approval\Ledger::sortedKeys()`
- `LedgerQueryTest::test_the_year_of_an_unnumbered_request_follows_the_company_of_its_department` — `Error: Call to undefined method App\Support\Approval\Ledger::sortedKeys()`
- `LedgerQueryTest::test_the_order_is_by_day_then_number_then_submission` — `Error: Call to undefined method App\Support\Approval\Ledger::sortedKeys()`
- `LedgerRowTest::test_a_decided_row` — `Error: Call to undefined method App\Support\Approval\Ledger::requestKey()`
- `LedgerRowTest::test_the_condition_is_only_for_a_conditional_decision` — `Error: Call to undefined method App\Support\Approval\Ledger::requestKey()`
- `LedgerRowTest::test_others_see_what_was_last_submitted_and_the_applicant_sees_the_current_content` — `Error: Call to undefined method App\Support\Approval\Ledger::requestKey()`
- `LedgerRowTest::test_others_see_the_latest_of_several_submissions` — `Error: Call to undefined method App\Support\Approval\Ledger::requestKey()`
- `LedgerRowTest::test_others_get_nothing_rather_than_the_current_content_when_the_revision_is_missing` — `Error: Call to undefined method App\Support\Approval\Ledger::requestKey()`
- `LedgerRowTest::test_a_deleted_applicant_still_has_a_name` — `Error: Call to undefined method App\Support\Approval\Ledger::requestKey()`
- `LedgerRowTest::test_the_review_and_the_condition_come_from_the_current_round` — `Error: Call to undefined method App\Support\Approval\Ledger::requestKey()`
- `LedgerRowTest::test_rows_keep_the_given_order_and_read_everything_in_a_few_queries` — `Error: Call to undefined method App\Support\Approval\Ledger::requestKey()`
- `LedgerExcelTableTest::test_the_rate_is_empty_when_the_total_sale_is_zero` — `Error: Call to undefined method App\Support\Approval\Ledger::requestKey()`
- `LedgerExcelTableTest::test_a_row_uses_only_the_revision_of_the_current_round` — `Error: Call to undefined method App\Support\Approval\Ledger::requestKey()`
- `LedgerExcelTableTest::test_a_broken_contract_date_in_the_revision_leaves_only_that_cell_empty` — `Error: Call to undefined method App\Support\Approval\Ledger::requestKey()`
- `LedgerExcelTableTest::test_every_status_choice_still_works` — `Error: Call to undefined method App\Support\Approval\Ledger::requestKey()`
- `PaperLedgerTest::test_papers_are_sorted_together_with_requests` — `Error: Call to undefined method App\Support\Approval\Ledger::paperKey()`
- `PaperLedgerTest::test_only_visible_papers_are_listed` — `Error: Call to undefined method App\Support\Approval\Ledger::paperKey()`
- `PaperLedgerTest::test_the_filters_apply_to_papers` — `Error: Call to undefined method App\Support\Approval\Ledger::paperKey()`
- `PaperLedgerTest::test_a_paper_row_reads_as_a_paper` — `Error: Call to undefined method App\Support\Approval\Ledger::paperKey()`
- `LedgerExcelTest::test_the_columns_and_the_values_of_a_row` — `Failed asserting that two arrays are identical.`
- `LedgerExcelTest::test_the_heading_is_frozen_and_has_filter_buttons` — `Failed asserting that two strings are identical.`
- `LedgerExcelTest::test_each_download_is_recorded_with_the_conditions_and_the_count` — `Failed asserting that two arrays are identical.`
- `LedgerFilterTest::test_the_log_keeps_every_key_in_the_same_order` — `Failed asserting that two arrays are identical.`
- `LedgerExcelTableTest::test_the_four_columns_of_a_table_request` — `Failed asserting that two strings are identical.`
- `PaperLedgerTest::test_the_years_reach_back_to_the_oldest_paper` — `Failed asserting that two arrays are identical.`
- `PaperLedgerTest::test_the_screen_shows_papers_with_the_new_filters` — `Failed asserting that '<!DOCTYPE html>\n`
- `PaperLedgerTest::test_the_excel_has_the_paper_rows_and_logs_the_new_filters` — `Failed asserting that two arrays are identical.`
- `PaperLedgerTest::test_the_name_search_takes_only_known_columns` — `Failed asserting that exception of type "InvalidArgumentException" is thrown.`
- `PaperLedgerTest::test_the_excel_limit_counts_papers_too` — `Failed asserting that 200 is identical to 302.`

- [ ] **Step 3: 絞り込みに区分と判断「不明」を足し、紙が当たる条件を 1 か所にする**

`app/Support/Approval/LedgerFilter.php`（変更）

```diff
--- a/app/Support/Approval/LedgerFilter.php
+++ b/app/Support/Approval/LedgerFilter.php
@@ -8,7 +8,10 @@
 use Illuminate\Http\Request;
 
 /**
- * 決裁台帳（⑤）の絞り込みの条件（要件 10・段階4 設計書 §5.8・D19〜D21）。画面・Excel・出力の記録が同じものを使う。
+ * 決裁台帳（⑤）の絞り込みの条件（要件 10・段階4 設計書 §5.8・D19〜D21・段階5 §5.13・D22）。画面・Excel・出力の記録が同じものを使う。
+ *
+ * 段階5 で取り込んだ紙の決裁も並ぶ。紙が当たるかは includesPapers()・申請が当たるかは includesRequests() の 1 か所で決める
+ * （判断の「不明」は紙だけ・申請の種類や「進行中」「取り下げ」や可・条可・否を選ぶと紙は出ない・区分で片方だけにできる）。
  *
  * ⚠ 台帳は GET のフォーム。読み取れない値（形の違う日付・長すぎる文字・配列など）は**断らずに外し**、外した項目の名前を
  *   `ignored` に残して画面で知らせる。入力の検査（validate()）で前の画面へ戻すと、GET の戻り先がこの画面そのものになり、
@@ -27,6 +30,21 @@ final class LedgerFilter
 
     public const DEFAULT_STATUS = 'numbered';
 
+    /** 紙の決裁も出る状態（決裁No の付いたもの・すべて。§5.13） */
+    private const STATUSES_WITH_PAPERS = ['numbered', 'all'];
+
+    /** 判断の「不明」（紙の決裁だけが当たる。D22）。URL の `?decision=unknown` */
+    public const DECISION_UNKNOWN = 'unknown';
+
+    /** 区分（キーは URL の `?kind=`。既定はすべて。D22） */
+    public const KINDS = [
+        'all'    => 'すべて',
+        'system' => 'システム',
+        'paper'  => '紙',
+    ];
+
+    public const DEFAULT_KIND = 'all';
+
     /** キーワード・申請者の文字数の上限（超えたら外して知らせる。画面の maxlength と同じ） */
     public const KEYWORD_MAX = 100;
 
@@ -43,6 +61,7 @@ final class LedgerFilter
         'status'     => '状態',
         'decision'   => '判断',
         'q'          => 'キーワード',
+        'kind'       => '区分',
     ];
 
     /** @param list<string> $ignored 読み取れずに外した項目の名前 */
@@ -57,6 +76,8 @@ private function __construct(
         public readonly ?ApprovalDecision $decision,
         public readonly ?string $keyword,
         public readonly array $ignored,
+        public readonly bool $decisionUnknown = false,
+        public readonly string $kind = self::DEFAULT_KIND,
     ) {
     }
 
@@ -84,16 +105,21 @@ public static function fromRequest(Request $request): self
         $from       = $read('from', self::calendarDay(...));
         $to         = $read('to', self::calendarDay(...));
         $status     = $read('status', fn (string $v) => array_key_exists($v, self::STATUSES) ? $v : null);
-        $decision   = $read('decision', fn (string $v) => ApprovalDecision::tryFrom($v));
+        $decision   = $read('decision', fn (string $v) => $v === self::DECISION_UNKNOWN ? $v : ApprovalDecision::tryFrom($v));
         $keyword    = $read('q', fn (string $v) => mb_strlen($v) <= self::KEYWORD_MAX ? $v : null);
+        $kind       = $read('kind', fn (string $v) => array_key_exists($v, self::KINDS) ? $v : null);
 
-        return new self($year, $department, $type, $applicant, $from, $to, $status ?? self::DEFAULT_STATUS, $decision, $keyword, $ignored);
+        return new self(
+            $year, $department, $type, $applicant, $from, $to, $status ?? self::DEFAULT_STATUS,
+            $decision instanceof ApprovalDecision ? $decision : null, $keyword, $ignored,
+            $decision === self::DECISION_UNKNOWN, $kind ?? self::DEFAULT_KIND,
+        );
     }
 
     /** 既定のまま（何も絞り込んでいない。状態は決裁No の付いたもの） */
     public static function defaults(): self
     {
-        return new self(null, null, null, null, null, null, self::DEFAULT_STATUS, null, null, []);
+        return new self(null, null, null, null, null, null, self::DEFAULT_STATUS, null, null, [], false, self::DEFAULT_KIND);
     }
 
     /**
@@ -120,7 +146,28 @@ public function within(array $years, array $departmentIds, array $typeIds): self
         $department = $keep($this->departmentId, $departmentIds, 'department');
         $type       = $keep($this->typeId, $typeIds, 'type');
 
-        return new self($year, $department, $type, $this->applicant, $this->decidedFrom, $this->decidedTo, $this->status, $this->decision, $this->keyword, $ignored);
+        return new self($year, $department, $type, $this->applicant, $this->decidedFrom, $this->decidedTo, $this->status, $this->decision, $this->keyword, $ignored, $this->decisionUnknown, $this->kind);
+    }
+
+    /** システムの申請が当たりうるか（区分が紙・判断が「不明」なら当たらない） */
+    public function includesRequests(): bool
+    {
+        return $this->kind !== 'paper' && ! $this->decisionUnknown;
+    }
+
+    /**
+     * 紙の決裁が当たりうるか（§5.13）。区分がシステム・申請の種類を選んだ・判断に可・条可・否を選んだ・状態が「進行中」「取り下げ」なら当たらない
+     */
+    public function includesPapers(): bool
+    {
+        return $this->kind !== 'system' && $this->typeId === null && $this->decision === null
+            && in_array($this->status, self::STATUSES_WITH_PAPERS, true);
+    }
+
+    /** 判断の選択の値（画面の選択と URL。「不明」も） */
+    public function decisionKey(): ?string
+    {
+        return $this->decisionUnknown ? self::DECISION_UNKNOWN : $this->decision?->value;
     }
 
     /**
@@ -138,8 +185,9 @@ public function query(): array
             'from'       => $this->decidedFrom?->format('Y-m-d'),
             'to'         => $this->decidedTo?->format('Y-m-d'),
             'status'     => $this->status === self::DEFAULT_STATUS ? null : $this->status,
-            'decision'   => $this->decision?->value,
+            'decision'   => $this->decisionKey(),
             'q'          => $this->keyword,
+            'kind'       => $this->kind === self::DEFAULT_KIND ? null : $this->kind,
         ], fn (mixed $value) => $value !== null);
     }
 
@@ -147,7 +195,7 @@ public function query(): array
      * 出力の記録に控える形（D25・§9 の 9）。項目はいつも同じで、使っていない条件は null（あとで読むときに迷わない）。
      * ⚠ MySQL の JSON はキーを並べ替えて返す（キーの長さの順。RequestSnapshot の注意）ので、読むときに並びに頼らない
      *
-     * @return array{year: ?int, department_id: ?int, type_id: ?int, applicant: ?string, decided_from: ?string, decided_to: ?string, status: string, decision: ?string, keyword: ?string}
+     * @return array{year: ?int, department_id: ?int, type_id: ?int, applicant: ?string, decided_from: ?string, decided_to: ?string, status: string, decision: ?string, keyword: ?string, kind: string}
      */
     public function toLog(): array
     {
@@ -159,8 +207,9 @@ public function toLog(): array
             'decided_from'  => $this->decidedFrom?->format('Y-m-d'),
             'decided_to'    => $this->decidedTo?->format('Y-m-d'),
             'status'        => $this->status,
-            'decision'      => $this->decision?->value,
+            'decision'      => $this->decisionKey(),
             'keyword'       => $this->keyword,
+            'kind'          => $this->kind,
         ];
     }
```

`app/Support/Approval/NameSearch.php`（変更）

```diff
--- a/app/Support/Approval/NameSearch.php
+++ b/app/Support/Approval/NameSearch.php
@@ -3,9 +3,10 @@
 namespace App\Support\Approval;
 
 use Illuminate\Database\Eloquent\Builder;
+use InvalidArgumentException;
 
 /**
- * 人の名前の探し方（決裁台帳の申請者〈段階4 D21〉・⑦ 利用者の管理〈段階5 D25〉）。2 つの画面が同じ規則で探すよう 1 か所に置く。
+ * 人の名前の探し方（決裁台帳の申請者〈段階4 D21〉・⑦ 利用者の管理〈段階5 D25〉・台帳の紙の決裁の申請者〈段階5 §5.13〉）。同じ規則で探すよう 1 か所に置く。
  *
  * 空白（全角を含む）で分けた語の**どれも**含む人に当て、登録名の半角・全角の空白は無視する
  * （「山田太郎」で「山田 太郎」も、「田　郎」でも当たる）。`LIKE` の `%` と `_` は逃がさない（アプリのほかの検索と同じ）。
@@ -22,16 +23,26 @@ public static function terms(?string $text): array
         return $text === null ? [] : (preg_split('/[\s\x{3000}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
     }
 
+    /** 紙の決裁の申請者の列（台帳の文字のまま） */
+    public const PAPER_APPLICANT = 'approval_paper_records.applicant_name';
+
+    /** 名前の列として渡せるもの（SQL にそのまま書くので、決まった列だけ） */
+    private const COLUMNS = ['users.name', self::PAPER_APPLICANT];
+
     /**
-     * users の問い合わせに「登録名の空白を除いた名前が、どの語も含む」を足す（語が無ければ何も足さない）。
-     * ⚠ 列は表の名前を付けて書く（JOIN を足しても名前の列が曖昧にならない）
+     * 問い合わせに「名前の列の空白を除いた文字が、どの語も含む」を足す（語が無ければ何も足さない）。既定は users の登録名
+     * ⚠ 列は表の名前を付けて書く（JOIN を足しても名前の列が曖昧にならない）。決まった列だけを受け取る（SQL に埋め込むため）
      *
      * @param list<string> $terms
      */
-    public static function whereNameHasAll(Builder $users, array $terms): void
+    public static function whereNameHasAll(Builder $query, array $terms, string $column = 'users.name'): void
     {
+        if (! in_array($column, self::COLUMNS, true)) {
+            throw new InvalidArgumentException("名前の列として使えない列です: {$column}");
+        }
+
         foreach ($terms as $term) {
-            $users->whereRaw("replace(replace(users.name, ' ', ''), '　', '') like ?", ["%{$term}%"]);
+            $query->whereRaw("replace(replace({$column}, ' ', ''), '　', '') like ?", ["%{$term}%"]);
         }
     }
 }
```

- [ ] **Step 4: 申請と紙を 1 つの並びにする**

`app/Support/Approval/Ledger.php`（変更）

```diff
--- a/app/Support/Approval/Ledger.php
+++ b/app/Support/Approval/Ledger.php
@@ -5,6 +5,7 @@
 use App\Enums\ApprovalStatus;
 use App\Models\ApprovalCompany;
 use App\Models\ApprovalDepartment;
+use App\Models\ApprovalPaperRecord;
 use App\Models\ApprovalRequest;
 use App\Models\User;
 use App\Support\JapanTime;
@@ -21,14 +22,21 @@
  * - 中身（申請部門・申請の種類・件名・本文）は、申請者本人以外には**最後に提出した中身**で当てる（D19）。直しかけが今の中身に
  *   残りうるのは差戻し中と取り下げだけなので、その 2 つの状態の他人の申請だけを控え（approval_revisions の今の回）で当て、
  *   ほかは申請の行で当てる（関連する決裁No の候補 RelatedNumberController と同じ考え。ほかの状態は今の中身と控えが同じ）
- * - 並びは id の並びとして PHP で作る（sortedIds）。「決裁日の新しい順・同じ日は決裁No の順」の「日」は日本の暦なので、
+ * - 並びは鍵の並びとして PHP で作る（sortedKeys）。「決裁日の新しい順・同じ日は決裁No の順」の「日」は日本の暦なので、
  *   UTC で保存した日時を SQL で日本の日付に直す必要があり、MySQL と SQLite で書き方が違う（D22）
+ * - 段階5: 取り込んだ紙の決裁も同じ並びに入れる（§5.13・D10。紙は専用の表なので、鍵は申請 `r12`・紙 `p3` の文字）。
+ *   紙の見られる範囲は RequestVisibility の紙の規則。どの条件で紙が当たるかは LedgerFilter::includesPapers()
  */
 final class Ledger
 {
     /** 1 ページの件数（D22） */
     public const PER_PAGE = 50;
 
+    /** 並びの鍵の頭（申請と紙の決裁。id は表ごとなので重なる） */
+    private const REQUEST_KEY = 'r';
+
+    private const PAPER_KEY = 'p';
+
     /** 行を描くのに要る関係（N+1 にしない。steps は今の回の審査と社長の判断を読む） */
     private const WITH = ['applicant', 'type', 'department', 'lastRevision', 'steps'];
 
@@ -106,50 +114,123 @@ public static function query(User $viewer, LedgerFilter $filter): Builder
     }
 
     /**
-     * 並べた id（D22）。決裁日（番号の無い申請は発信日）の日本の暦の日の新しい順 → 同じ日は決裁No のあるものを
-     * 部門のアルファベットと連番の順 → 番号の無いものは発信の新しい順 → id の新しい順
+     * 並べた鍵（D22。申請は `r` ＋ id・紙の決裁は `p` ＋ id）。決裁日（番号の無い申請は発信日。紙は決裁日）の日本の暦の日の新しい順
+     * → 同じ日は決裁No のあるものを部門のアルファベットと連番の順 → 番号の無いものは発信の新しい順 → 申請・紙の順 → id の新しい順
      *
-     * @return list<int>
+     * @return list<string>
      */
-    public static function sortedIds(User $viewer, LedgerFilter $filter): array
+    public static function sortedKeys(User $viewer, LedgerFilter $filter): array
     {
         $codes = ApprovalDepartment::query()->pluck('code', 'id');
-        $keys  = self::query($viewer, $filter)->toBase()
-            ->get(['approval_requests.id', 'approval_requests.number', 'approval_requests.number_department_id', 'approval_requests.number_seq', 'approval_requests.decided_at', 'approval_requests.last_submitted_at'])
-            ->map(fn (object $r) => [
-                'id'         => (int) $r->id,
-                'day'        => self::japanDay($r->decided_at ?? $r->last_submitted_at),
-                'unnumbered' => $r->number === null ? 1 : 0,
-                'code'       => (string) ($codes[$r->number_department_id] ?? ''),
-                'seq'        => (int) $r->number_seq,
-                'submitted'  => (string) $r->last_submitted_at,
-            ])
-            ->all();
+        $keys  = [];
+
+        if ($filter->includesRequests()) {
+            foreach (self::query($viewer, $filter)->toBase()
+                ->get(['approval_requests.id', 'approval_requests.number', 'approval_requests.number_department_id', 'approval_requests.number_seq', 'approval_requests.decided_at', 'approval_requests.last_submitted_at']) as $r) {
+                $keys[] = [
+                    'key'        => self::REQUEST_KEY . $r->id,
+                    'kind'       => 0,
+                    'id'         => (int) $r->id,
+                    'day'        => self::japanDay($r->decided_at ?? $r->last_submitted_at),
+                    'unnumbered' => $r->number === null ? 1 : 0,
+                    'code'       => (string) ($codes[$r->number_department_id] ?? ''),
+                    'seq'        => (int) $r->number_seq,
+                    'submitted'  => (string) $r->last_submitted_at,
+                ];
+            }
+        }
 
-        usort($keys, fn (array $a, array $b) => [$b['day'], $a['unnumbered'], $a['code'], $a['seq'], $b['submitted'], $b['id']]
-            <=> [$a['day'], $b['unnumbered'], $b['code'], $b['seq'], $a['submitted'], $a['id']]);
+        if ($filter->includesPapers()) {
+            foreach (self::paperQuery($viewer, $filter)->toBase()
+                ->get(['approval_paper_records.id', 'approval_paper_records.department_id', 'approval_paper_records.seq', 'approval_paper_records.decided_on']) as $p) {
+                $keys[] = [
+                    'key'        => self::PAPER_KEY . $p->id,
+                    'kind'       => 1,
+                    'id'         => (int) $p->id,
+                    // 決裁日は日付の列（日本の暦の日をそのまま持つ）。MySQL と SQLite で時刻の付き方が違っても日付だけを見る
+                    'day'        => substr((string) $p->decided_on, 0, 10),
+                    'unnumbered' => 0,
+                    'code'       => (string) ($codes[$p->department_id] ?? ''),
+                    'seq'        => (int) $p->seq,
+                    'submitted'  => '',
+                ];
+            }
+        }
+
+        usort($keys, fn (array $a, array $b) => [$b['day'], $a['unnumbered'], $a['code'], $a['seq'], $b['submitted'], $a['kind'], $b['id']]
+            <=> [$a['day'], $b['unnumbered'], $b['code'], $b['seq'], $a['submitted'], $b['kind'], $a['id']]);
 
-        return array_column($keys, 'id');
+        return array_column($keys, 'key');
+    }
+
+    /** 申請の並びの鍵（テストと呼ぶ側が行を作るとき） */
+    public static function requestKey(int $id): string
+    {
+        return self::REQUEST_KEY . $id;
+    }
+
+    public static function paperKey(int $id): string
+    {
+        return self::PAPER_KEY . $id;
     }
 
     /**
-     * id の並びのとおりに行を作る（行に要る関係を先にまとめて読む）
+     * 鍵の並びのとおりに行を作る（行に要る関係を先にまとめて読む。申請と紙の決裁を 1 回ずつ）
      *
-     * @param list<int> $ids
+     * @param list<string> $keys
      * @return Collection<int, LedgerRow>
      */
-    public static function rows(User $viewer, array $ids): Collection
+    public static function rows(User $viewer, array $keys): Collection
     {
-        $position = array_flip($ids);
+        $position = array_flip($keys);
+        $ids      = [self::REQUEST_KEY => [], self::PAPER_KEY => []];
+        foreach ($keys as $key) {
+            $ids[$key[0]][] = (int) substr($key, 1);
+        }
+
+        $requests = $ids[self::REQUEST_KEY] === [] ? collect() : ApprovalRequest::with(self::WITH)->whereKey($ids[self::REQUEST_KEY])->get()
+            ->map(fn (ApprovalRequest $r) => LedgerRow::for($viewer, $r));
+        $papers   = $ids[self::PAPER_KEY] === [] ? collect() : ApprovalPaperRecord::with('department')->whereKey($ids[self::PAPER_KEY])->get()
+            ->map(fn (ApprovalPaperRecord $p) => LedgerRow::forPaper($p));
 
-        return ApprovalRequest::with(self::WITH)->whereKey($ids)->get()
-            ->sortBy(fn (ApprovalRequest $r) => $position[$r->id])
-            ->map(fn (ApprovalRequest $r) => LedgerRow::for($viewer, $r))
+        return $requests->concat($papers)
+            ->sortBy(fn (LedgerRow $row) => $position[($row->isPaper ? self::PAPER_KEY : self::REQUEST_KEY) . $row->id])
             ->values();
     }
 
     /**
-     * 年度の選択肢（新しい順。今の年度から、いちばん古い申請の年度まで。値は期の始まりの年）
+     * 見られる紙の決裁を条件で絞った問い合わせ（§5.13。並びは付けない。紙が当たる条件かは呼ぶ側が includesPapers() で見る）
+     */
+    public static function paperQuery(User $viewer, LedgerFilter $filter): Builder
+    {
+        $query = ApprovalPaperRecord::query()->visibleTo($viewer);
+
+        if ($filter->year !== null) {
+            $query->where('approval_paper_records.fiscal_year', $filter->year);
+        }
+        if ($filter->departmentId !== null) {
+            $query->where('approval_paper_records.department_id', $filter->departmentId);
+        }
+        // 決裁日は日付の列なので、日本の暦の日をそのまま比べる。⚠ 終わりは「次の日より前」（SQLite は日付の列に「2026-07-10 00:00:00」の
+        //   文字で入り、「<= '2026-07-10'」だとその日が外れる。MySQL の DATE はどちらでも同じ）
+        if ($filter->decidedFrom !== null) {
+            $query->where('approval_paper_records.decided_on', '>=', $filter->decidedFrom->format('Y-m-d'));
+        }
+        if ($filter->decidedTo !== null) {
+            $query->where('approval_paper_records.decided_on', '<', $filter->decidedTo->addDay()->format('Y-m-d'));
+        }
+        // 申請者は台帳の文字（申請の申請者と同じ探し方。空白は無視）
+        NameSearch::whereNameHasAll($query, NameSearch::terms($filter->applicant), NameSearch::PAPER_APPLICANT);
+        // キーワードは件名か備考
+        foreach (LedgerFilter::terms($filter->keyword) as $word) {
+            $query->where(fn (Builder $q) => $q->where('approval_paper_records.subject', 'like', "%{$word}%")->orWhere('approval_paper_records.note', 'like', "%{$word}%"));
+        }
+
+        return $query;
+    }
+
+    /**
+     * 年度の選択肢（新しい順。今の年度から、いちばん古い申請・紙の決裁の年度まで。値は期の始まりの年）
      *
      * @return array<int, string> 年度 => 表示（「R8 年度（2026）」）
      */
@@ -171,6 +252,10 @@ public static function years(): array
         if ($submitted !== null) {
             $oldest = min($oldest, ApprovalFiscalYear::ofMoment(CarbonImmutable::parse($submitted, 'UTC'), (int) $months->max()));
         }
+        $paper = ApprovalPaperRecord::query()->min('fiscal_year');
+        if ($paper !== null) {
+            $oldest = min($oldest, (int) $paper);
+        }
 
         $years = [];
         for ($year = $newest; $year >= $oldest; $year--) {
```

`app/Support/Approval/LedgerRow.php`（変更）

```diff
--- a/app/Support/Approval/LedgerRow.php
+++ b/app/Support/Approval/LedgerRow.php
@@ -5,9 +5,11 @@
 use App\Enums\ApprovalDecision;
 use App\Enums\ApprovalStepKind;
 use App\Enums\ApprovalStepStatus;
+use App\Models\ApprovalPaperRecord;
 use App\Models\ApprovalRequest;
 use App\Models\ApprovalStep;
 use App\Models\User;
+use App\Support\JapanTime;
 use Carbon\CarbonImmutable;
 use Carbon\CarbonInterface;
 
@@ -20,9 +22,14 @@
  * ⚠ 審査の意見・コメントと条件（条可のコメント）は今の回の段階から（PDF と同じく今の回だけ。D4）。
  * ⚠ 控えは今の回（`round` が申請の回と同じ）のものだけを使う（詳細の RequestContent と同じ引き方。今の回の控えが欠けていれば空にする）。
  * ⚠ 段階5: 工事原価・粗利益金額・粗利率は明細表の「合計金額」の行（5W2H の種類は空）、契約予定日は追加の入力欄（使わない種類は空）。
+ * ⚠ 段階5: 取り込んだ紙の決裁の行は forPaper()（判断「不明」・状態は空・区分「紙」・申請者は台帳の文字・粗利率は金額と粗利益から。D23）。
+ *   行の先（url()）は紙の決裁の詳細。画面の状態の列には灰色の「紙」（badgeLabel()）
  */
 final class LedgerRow
 {
+    /** 画面の状態の列の紙の決裁の色（灰色） */
+    private const PAPER_STYLE = 'background-color:#f3f4f6;color:#4b5563;';
+
     /** @param list<string> $relatedNumbers */
     private function __construct(
         public readonly int $id,
@@ -46,9 +53,58 @@ private function __construct(
         public readonly ?int $profit = null,
         public readonly ?float $rate = null,
         public readonly ?CarbonInterface $contractDate = null,
+        public readonly bool $isPaper = false,
     ) {
     }
 
+    /** 取り込んだ紙の決裁の行（部門は先に読んでおく。Ledger::rows()） */
+    public static function forPaper(ApprovalPaperRecord $record): self
+    {
+        return new self(
+            id: $record->id,
+            number: $record->number,
+            // 決裁日は日付（時刻なし）。日本の暦のその日の 0:00 にする（画面と Excel は日本の暦の日で出す）
+            decidedAt: CarbonImmutable::createFromFormat('!Y-m-d', $record->decided_on->format('Y-m-d'), JapanTime::ZONE),
+            decisionLabel: ApprovalPaperRecord::DECISION_LABEL,
+            subject: $record->subject,
+            typeName: null,
+            departmentName: $record->department?->name,
+            applicantName: $record->applicant_name,
+            amount: $record->amount,
+            schedule: null,
+            relatedNumbers: [],
+            submittedAt: null,
+            reviewResult: null,
+            reviewComment: null,
+            condition: null,
+            statusLabel: '',
+            statusStyle: self::PAPER_STYLE,
+            cost: $record->cost,
+            profit: $record->profit,
+            rate: $record->rate(),
+            contractDate: null,
+            isPaper: true,
+        );
+    }
+
+    /** 行の先（申請の詳細か、紙の決裁の詳細） */
+    public function url(): string
+    {
+        return $this->isPaper ? route('approvals.papers.show', $this->id) : route('approvals.requests.show', $this->id);
+    }
+
+    /** 区分（Excel の列。D23） */
+    public function kindLabel(): string
+    {
+        return LedgerFilter::KINDS[$this->isPaper ? 'paper' : 'system'];
+    }
+
+    /** 画面の状態の列の文字（紙の決裁は「紙」。Excel の状態の列は空） */
+    public function badgeLabel(): string
+    {
+        return $this->isPaper ? LedgerFilter::KINDS['paper'] : $this->statusLabel;
+    }
+
     /** 関係（applicant・type・department・lastRevision・steps）は先に読んでおく（Ledger::rows()） */
     public static function for(User $viewer, ApprovalRequest $request): self
     {
```

- [ ] **Step 5: 画面と Excel**

`app/Http/Controllers/Approval/LedgerController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/LedgerController.php
+++ b/app/Http/Controllers/Approval/LedgerController.php
@@ -23,7 +23,7 @@
  * 見られる人なら誰でも開ける（見られる申請だけが出る）。
  *
  * ⚠ 絞り込みは GET。読み取れない条件は断らずに外して知らせる（LedgerFilter。validate() で戻すと同じ URL を開き直し続ける）。
- * ⚠ 並びは id の並びとして作り（Ledger::sortedIds）、今のページの分だけ行を読む（N+1 にしない）。
+ * ⚠ 並びは鍵の並びとして作り（Ledger::sortedKeys。申請と取り込んだ紙の決裁）、今のページの分だけ行を読む（N+1 にしない）。
  */
 class LedgerController extends Controller
 {
@@ -33,14 +33,14 @@ public function index(Request $request): View
         $viewer  = $request->user();
         $choices = $this->choices();
         $filter  = $this->filter($request, $choices);
-        $ids     = Ledger::sortedIds($viewer, $filter);
+        $keys    = Ledger::sortedKeys($viewer, $filter);
         // ページ番号は最後のページまで（手で打った大きな番号で array_slice に float が渡ると 500・行の無いページは件数と食い違う）
-        $last    = max(1, (int) ceil(count($ids) / Ledger::PER_PAGE));
+        $last    = max(1, (int) ceil(count($keys) / Ledger::PER_PAGE));
         $page    = min(LengthAwarePaginator::resolveCurrentPage(), $last);
 
         $rows = new LengthAwarePaginator(
-            Ledger::rows($viewer, array_slice($ids, ($page - 1) * Ledger::PER_PAGE, Ledger::PER_PAGE)),
-            count($ids),
+            Ledger::rows($viewer, array_slice($keys, ($page - 1) * Ledger::PER_PAGE, Ledger::PER_PAGE)),
+            count($keys),
             Ledger::PER_PAGE,
             $page,
             ['path' => $request->url(), 'query' => $filter->query()],
@@ -59,20 +59,20 @@ public function excel(Request $request): Response|RedirectResponse
     {
         $viewer = $request->user();
         $filter = $this->filter($request, $this->choices());
-        $ids    = Ledger::sortedIds($viewer, $filter);
+        $keys   = Ledger::sortedKeys($viewer, $filter);
         $limit  = (int) config('approval.ledger.excel_limit');
 
-        if (count($ids) > $limit) {
+        if (count($keys) > $limit) {
             return redirect()->route('approvals.ledger.index', $filter->query())
-                ->with('error', '絞り込んでから出してください（Excel に出せるのは ' . number_format($limit) . ' 件までです。いまは ' . number_format(count($ids)) . ' 件）。');
+                ->with('error', '絞り込んでから出してください（Excel に出せるのは ' . number_format($limit) . ' 件までです。いまは ' . number_format(count($keys)) . ' 件）。');
         }
 
         // ⚠ 作るのに失敗したときの知らせは PDF のようには作らない（PDF はフォントなど外の物に頼るが、Excel の失敗はメモリの
         //   不足くらいで、それは捕まえられない。件数の上限で防ぐ）。例外は Laravel がほかの画面と同じく laravel.log に残す
-        $excel = LedgerExcel::build($viewer, $ids);
+        $excel = LedgerExcel::build($viewer, $keys);
 
         // 出力のたびに、絞り込みの条件と件数を記録する（14.2・D25）。作ったあとに書く（作れなかったら記録しない。§5.7）
-        DownloadLogger::excel($request, $filter->toLog(), count($ids));
+        DownloadLogger::excel($request, $filter->toLog(), count($keys));
 
         // 日本語の名前は filename*（UTF-8）、古いブラウザ用の代わりは ASCII だけの名前（Laravel の Str::ascii に任せない。Bug #68）
         [$fileName, $fallback] = LedgerExcel::fileNames();
```

`app/Support/Approval/LedgerExcel.php`（変更）

```diff
--- a/app/Support/Approval/LedgerExcel.php
+++ b/app/Support/Approval/LedgerExcel.php
@@ -24,7 +24,7 @@
  * - 中身は台帳と同じ出し分け（LedgerRow。申請者本人以外には最後に提出した中身。D19）
  * ⚠ 列の並び・見出し・幅・種類（文字・折り返す文字・日付・金額・率）・値の取り方は columns() の 1 つの定義から作る（列の記号を手で書かない。
  *   段階4 の BACKLOG の注意「段階5 で列を足すときは 1 つの定義から作る形に直す」）
- * ⚠ 行の関係は CHUNK 件ずつ読む（全件の申請を一度に読まない）。件数の上限は config('approval.ledger.excel_limit')
+ * ⚠ 行の関係は CHUNK 件ずつ読む（全件の申請を一度に読まない）。件数の上限は config('approval.ledger.excel_limit')（申請と紙の決裁を合わせて数える）
  */
 final class LedgerExcel
 {
@@ -45,7 +45,7 @@ final class LedgerExcel
     private const SHEET_TITLE = '決裁台帳';
 
     /**
-     * 列（要件 10 の並び。D23 で審査部門の意見を 2 列に分けた。段階5 で工事原価・粗利益金額・粗利率・契約予定日を足した）。
+     * 列（要件 10 の並び。D23 で審査部門の意見を 2 列に分けた。段階5 で工事原価・粗利益金額・粗利率・契約予定日と、状態の前に区分を足した＝20 列）。
      * 見出し・幅・種類・行から値を取る関数。並びを変えるときもここだけを変える
      *
      * @return list<array{0: string, 1: int, 2: string, 3: \Closure(LedgerRow): mixed}>
@@ -72,6 +72,8 @@ private static function columns(): array
             ['審査の意見', 10, self::TEXT, fn (LedgerRow $r) => $r->reviewResult],
             ['審査のコメント', 40, self::WRAPPED, fn (LedgerRow $r) => $r->reviewComment],
             ['条件', 40, self::WRAPPED, fn (LedgerRow $r) => $r->condition],
+            // 区分（システム／紙）。紙の決裁は状態を空にする（段階5 D23）
+            ['区分', 8, self::TEXT, fn (LedgerRow $r) => $r->kindLabel()],
             ['状態', 16, self::TEXT, fn (LedgerRow $r) => $r->statusLabel],
         ];
     }
@@ -79,9 +81,9 @@ private static function columns(): array
     /**
      * xlsx の中身（バイト列）
      *
-     * @param list<int> $ids 台帳の並び（Ledger::sortedIds）
+     * @param list<string> $keys 台帳の並び（Ledger::sortedKeys。申請と紙の決裁）
      */
-    public static function build(User $viewer, array $ids): string
+    public static function build(User $viewer, array $keys): string
     {
         $book    = new Spreadsheet();
         $sheet   = $book->getActiveSheet();
@@ -91,7 +93,7 @@ public static function build(User $viewer, array $ids): string
         self::heading($sheet, $columns);
 
         $line = 2;
-        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
+        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
             foreach (Ledger::rows($viewer, $chunk) as $row) {
                 self::write($sheet, $line++, $row, $columns);
             }
@@ -140,7 +142,7 @@ private static function heading(Worksheet $sheet, array $columns): void
         $sheet->getStyle($heading)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
     }
 
-    /** 列の記号（1 → A・19 → S） */
+    /** 列の記号（1 → A・20 → T） */
     private static function letter(int $index): string
     {
         return Coordinate::stringFromColumnIndex($index);
```

`resources/views/approvals/ledger/index.blade.php`（変更）

```diff
--- a/resources/views/approvals/ledger/index.blade.php
+++ b/resources/views/approvals/ledger/index.blade.php
@@ -10,13 +10,14 @@
 @endsection
 
 {{-- 決裁台帳（画面⑤。要件 10・段階4 設計書 §5.8・D19〜D22）。見られる申請だけ・下書きは出さない。
+     段階5: 見られる取り込んだ紙の決裁も同じ並びに出す（§5.13。状態の列に灰色の「紙」・行の先は紙の決裁の詳細＝LedgerRow::url()）。
      ⚠ 件名・申請部門・金額は、申請者本人以外には最後に提出した中身（LedgerRow）。保存した日時は JapanTime::format（Bug #61）。
      ⚠ プルダウンは変えた瞬間に送る（CLAUDE.md の即時フィルタ）。文字と日付は「絞り込む」で送る。
      ⚠ 絞り込みの欄はスマホの幅でも 2 列（1 列だと欄だけで 1 画面を使い、結果が見えない）。 --}}
 @section('content')
 <div>
     <h1 class="text-lg font-bold text-gray-900 mb-2">決裁台帳</h1>
-    <p class="text-[12px] text-gray-500 mb-4 max-w-[720px]">見られる申請を、決裁日の新しい順に並べます（決裁No の無い申請は発信日の順）。件名を押すと申請の詳細を開きます。</p>
+    <p class="text-[12px] text-gray-500 mb-4 max-w-[720px]">見られる申請と取り込んだ紙の決裁を、決裁日の新しい順に並べます（決裁No の無い申請は発信日の順）。件名を押すと詳細を開きます。</p>
 
     @if($filter->ignored !== [])
         <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-800" role="status">
@@ -47,6 +48,15 @@ class="grid grid-cols-2 lg:grid-cols-4 gap-x-3 gap-y-2.5 mb-4 bg-white border bo
                 @foreach(\App\Enums\ApprovalDecision::cases() as $decision)
                     <option value="{{ $decision->value }}" {{ $filter->decision === $decision ? 'selected' : '' }}>{{ $decision->label() }}</option>
                 @endforeach
+                {{-- 紙の決裁だけが当たる（D22） --}}
+                <option value="{{ \App\Support\Approval\LedgerFilter::DECISION_UNKNOWN }}" {{ $filter->decisionUnknown ? 'selected' : '' }}>{{ \App\Models\ApprovalPaperRecord::DECISION_LABEL }}</option>
+            </select>
+        </label>
+        <label class="block text-[12px] text-gray-600">区分
+            <select name="kind" onchange="document.getElementById('filter-form').submit()" class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full">
+                @foreach(\App\Support\Approval\LedgerFilter::KINDS as $key => $label)
+                    <option value="{{ $key }}" {{ $filter->kind === $key ? 'selected' : '' }}>{{ $label }}</option>
+                @endforeach
             </select>
         </label>
         <label class="block text-[12px] text-gray-600">申請部門
@@ -79,7 +89,7 @@ class="h-8 px-2 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-w
             </div>
         </div>
         <label class="block text-[12px] text-gray-600 col-span-2 lg:col-span-1">キーワード
-            <input type="text" name="q" value="{{ $filter->keyword }}" maxlength="{{ \App\Support\Approval\LedgerFilter::KEYWORD_MAX }}" placeholder="件名・本文"
+            <input type="text" name="q" value="{{ $filter->keyword }}" maxlength="{{ \App\Support\Approval\LedgerFilter::KEYWORD_MAX }}" placeholder="件名・本文・備考"
                    class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none w-full">
         </label>
         <div class="flex items-end gap-3 col-span-2 lg:col-span-4">
@@ -108,9 +118,9 @@ class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-7
             <ul class="md:hidden divide-y divide-gray-100">
                 @foreach($rows as $row)
                     <li>
-                        <a href="{{ route('approvals.requests.show', $row->id) }}" class="block px-4 py-3 hover:bg-gray-50">
+                        <a href="{{ $row->url() }}" class="block px-4 py-3 hover:bg-gray-50">
                             <div class="flex flex-wrap items-center gap-2 mb-1">
-                                <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $row->statusStyle }}">{{ $row->statusLabel }}</span>
+                                <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $row->statusStyle }}">{{ $row->badgeLabel() }}</span>
                                 @if($row->number)
                                     <span class="text-[12px] font-mono text-gray-600">{{ $row->number }}</span>
                                 @endif
@@ -146,13 +156,13 @@ class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-7
                                 <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ \App\Support\JapanTime::format($row->decidedAt, 'Y/m/d') ?? '—' }}</td>
                                 <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $row->decisionLabel ?? '—' }}</td>
                                 <td class="px-4 py-2.5 border-b border-gray-100 text-[13px]">
-                                    <a href="{{ route('approvals.requests.show', $row->id) }}" class="text-emerald-600 hover:underline break-words">{{ $row->subject ?? '（件名なし）' }}</a>
+                                    <a href="{{ $row->url() }}" class="text-emerald-600 hover:underline break-words">{{ $row->subject ?? '（件名なし）' }}</a>
                                 </td>
                                 <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $row->departmentName ?? '—' }}</td>
                                 <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $row->applicantName ?? '—' }}</td>
                                 <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums whitespace-nowrap">{{ $row->amountLabel() ?? '—' }}</td>
                                 <td class="px-4 py-2.5 border-b border-gray-100 whitespace-nowrap">
-                                    <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $row->statusStyle }}">{{ $row->statusLabel }}</span>
+                                    <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $row->statusStyle }}">{{ $row->badgeLabel() }}</span>
                                 </td>
                             </tr>
                         @endforeach
```

（差分のファイルを使うなら、Step 3〜5 をまとめて: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/5b/patches/0008-*.patch`）

- [ ] **Step 6: テストを流して通ることを確かめる**（Step 2 と、⑦ の検索〈`NameSearch` を共有〉のテスト）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5 tests/Feature/Approval/Phase4 tests/Feature/Approval/ApprovalUserManagementTest.php
```

Expected: `OK`（赤なし）

- [ ] **Step 7: 全件を流す**

Expected: `OK (4179 tests, 39261 assertions)`

- [ ] **Step 8: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/Ledger.php app/Support/Approval/LedgerFilter.php app/Support/Approval/LedgerRow.php app/Support/Approval/LedgerExcel.php app/Support/Approval/NameSearch.php app/Http/Controllers/Approval/LedgerController.php resources/views/approvals/ledger/index.blade.php tests/Feature/Approval/Phase5/PaperLedgerTest.php tests/Feature/Approval/Phase4/LedgerQueryTest.php tests/Feature/Approval/Phase4/LedgerRowTest.php tests/Feature/Approval/Phase4/LedgerExcelTest.php tests/Feature/Approval/Phase4/LedgerFilterTest.php tests/Feature/Approval/Phase5/LedgerExcelTableTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 決裁台帳と Excel に取り込んだ紙の決裁を並べる

見られる紙の決裁を、申請と同じ並び（決裁日の新しい順・同じ日は決裁No の
順）で台帳と Excel に出す。判断に「不明」（紙だけ）・区分（すべて／
システム／紙）の絞り込みを足し、Excel は区分の列を状態の前に足して
20 列にした（段階5 設計書 §5.13・§5.14・D22・D23）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 9: 全件テストと変異テスト（Bug #44 の作法）

**Files:** なし（測るだけ。穴が見つかったらテストを足してコミットする）

計画を書く段階で、試作（この計画のコードと同じ中身）に下の表の変異を 1 つずつ当てて測った（2026-10-13。決裁のテスト〈`tests/Feature/Approval`・`tests/Unit/Approval`〉と走査テスト 8 本を流した）。変異の一覧は、設計書の決定（D6〜D11・D20〜D24）と点検で直した所（§ 計画を書く段階の実測）を 1 つ以上ずつ壊すように作った。結果は **85 個（カナリアを含む）のうち、82 個を検出・2 個は SQLite では等価**（K01・K02。ロック付きの読みを普通の読みにする変異で、SQLite のテストでは差が出ない。MySQL の振る舞いは `~/.claude/plans/approval-phase3-tasks/5b/race/` の 2 本の接続で確かめた）。検出できなかった変異は無い。

⚠ WT のファイルを一時的に壊す変異は、自動の許可の判定に断られる。**WT の HEAD の写しを scratchpad に作ってそこで当てる**（WT は読むだけ）。以下の `<scratchpad>` は、その会話の scratchpad のパス（Mac を再起動すると消える。残したい結果は `~/.claude/plans/approval-phase3-tasks/5b/` へ写す）:

```bash
SCR=<scratchpad>/p5b-mutation && mkdir -p "$SCR" && git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 archive HEAD | tar -x -C "$SCR" && cp -Rc /Users/masanori/site/manage/.claude/worktrees/approval-phase3/vendor "$SCR/vendor" && ! test -L "$SCR/vendor" && test -f "$SCR/app/Support/Approval/PaperImportAnalysis.php" && echo "写し OK"
```

⚠ 写しの `storage/framework/views/*.php` は流す前に消す（道具が毎回消す。古いコンパイル済みのビューが残ると、ビューの変異が効かない）。

- [ ] **Step 1: 全件が緑の状態から始める**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git status --porcelain && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `git status --porcelain` が空・`OK (4179 tests, 39261 assertions)`

- [ ] **Step 2: 変異を当てて表と突き合わせる**

道具は `~/.claude/plans/approval-phase3-tasks/5b/mutate.py`（5a の道具の写しで、変異の一覧だけ 5b にしたもの。1 つ当てて流し、必ず元に戻して、戻ったことを確かめる。書き換える前の文字列が 1 回だけ現れないものは当てずに SKIP と記録する。PHP は 8.3 を名指し）。先に `--check` で、当てる場所がちょうど 1 回ずつ見つかることを確かめる:

```bash
python3 ~/.claude/plans/approval-phase3-tasks/5b/mutate.py <scratchpad>/p5b-mutation /dev/null --check
python3 ~/.claude/plans/approval-phase3-tasks/5b/mutate.py <scratchpad>/p5b-mutation <scratchpad>/mutations.jsonl
```

Expected（1 行目）: `NG` の行が無く `checked 85`。1 つ流すのに 1〜2 分。写しを 2 つ作って ID を分けて並べて流してよい（引数の最後に ID を並べると、その ID だけを流す。計画の段は `~/.claude/plans/approval-phase3-tasks/5b/run-mut.sh` の形で半分ずつ流した）。途中で止めるときは python に SIGINT（写しのファイルが元に戻る）。⚠ シェルの `&` で裏に回した python は SIGINT を受け付けない（非対話のシェルの決まり）。止めるときは SIGTERM で止め、写しは捨てて作り直す（当てた変異が写しに残るため）。同じ出力のファイルを渡して流し直すと、記録済みの変異を飛ばして続きから流す。

⚠ **最初の `CANARY`（紙の決裁の詳細に未定義の変数）が赤になること**を確かめてから結果を読む（測定が写しのコードを読んでいることの証明）。
⚠ **赤/緑ではなく「落ちたテストの集合」と「落ちた理由の文言」まで突き合わせる。** 意図と別の機構が落としているなら、その変異の測定は無効。当て方を変えて測り直す。

計画の時点の実測（試作。「落ちたテスト」はクラス名を省いたテストの名前）:

| # | 変異（ファイル） | 結果 | 落ちたテスト（実測） | 判定 |
|---|---|---|---|---|
| CANARY | カナリア: 紙の決裁の詳細に未定義の変数（`show.blade.php`） | Tests: 1374, Assertions: 9466, Failures: 2. | 2 本（PaperDetailTest ほか） | カナリア（赤が正しい） |
| L01 | 在らない日付（2026-02-30）を繰り上げて出す（`LedgerRow.php`） | Tests: 1374, Assertions: 9443, Failures: 1. | `test_a_broken_contract_date_in_the_revision_leaves_only_that_cell_empty` | 検出 |
| L02 | 形の崩れた契約予定日で台帳と Excel が 500（`LedgerRow.php`） | Tests: 1374, Assertions: 9430, Errors: 1. | `test_a_broken_contract_date_in_the_revision_leaves_only_that_cell_empty` | 検出 |
| T01 | SQL の決裁No の一意を外す（`2026-10-08-approval-phase5b.sql`） | Tests: 1374, Assertions: 9479, Failures: 1. | `test_the_keys_are_the_same_in_the_sql_and_the_migration` | 検出 |
| T02 | migration の決裁No の一意を外す（SQL と食い違う）（`2026_10_08_000001_create_approval_paper_tables.php`） | Tests: 1374, Assertions: 9478, Failures: 2. | `test_the_keys_are_the_same_in_the_sql_and_the_migration`、`test_the_number_and_the_seq_are_unique` | 検出 |
| T03 | 紙の決裁のある部門の会社とアルファベットを変えられる（§5.11）（`OrganizationController.php`） | Tests: 1374, Assertions: 9483, Failures: 1. | `test_the_code_and_the_company_are_locked_once_paper_records_exist` | 検出 |
| T04 | 紙の決裁のある部門を消そうとして外部キーで 500（`OrganizationController.php`） | Tests: 1374, Assertions: 9485, Failures: 1. | `test_a_department_with_paper_records_cannot_be_deleted` | 検出 |
| T05 | 紙の決裁のある会社の期の始まりの月を変えられる（紙の決裁No の和暦が変わる）（`OrganizationController.php`） | Tests: 1374, Assertions: 9485, Failures: 1. | `test_the_fiscal_start_month_is_locked_once_paper_records_exist` | 検出 |
| V01 | 社長（全件閲覧者でない）にもすべての紙を見せる（`RequestVisibility.php`） | Tests: 1374, Assertions: 9472, Failures: 2. | `test_the_people_who_may_see_it_can_open_it`、`test_who_can_see_a_paper_record` | 検出 |
| V02 | 部門長が所属していない自部門の紙を見られない（`RequestVisibility.php`） | Tests: 1374, Assertions: 9452, Failures: 4. | `test_a_related_paper_number_links_to_the_paper`、`test_only_visible_papers_are_listed`、`test_the_people_who_may_see_it_can_open_it`、`test_who_can_see_a_paper_record` | 検出 |
| V03 | 所属する人が自部門の紙を見られない（`RequestVisibility.php`） | Tests: 1374, Assertions: 9419, Failures: 9. | `test_a_request_comes_before_a_paper_of_the_same_day`、`test_an_or_before_the_scope_does_not_leak`、`test_candidates_are_capped_across_requests_and_papers`、`test_only_visible_papers_are_listed`、`test_the_detail_shows_the_record_and_offers_the_edit_to_admins`、`test_the_people_who_may_see_it_can_open_it` ほか 3 本 | 検出 |
| D01 | 期の始まりより前の月（1〜4 月）を年度の年にする（`PaperDate.php`） | Tests: 1374, Assertions: 9449, Failures: 11. | `test_a_date_after_today_is_an_error`、`test_a_date_is_read`、`test_the_two_sided_register_is_read` | 検出 |
| D02 | 前の年度の書き残し（7.04.30）を黙って入れる（`PaperDate.php`） | Tests: 1374, Assertions: 9484, Failures: 3. | `test_a_wrong_date_is_not_read`、`test_rows_with_errors_are_left_out_and_the_rest_is_kept` | 検出 |
| D03 | ない日付（2.30）を繰り上げて入れる（`PaperDate.php`） | Tests: 1374, Assertions: 9459, Failures: 4. | `test_a_wrong_date_is_not_read`、`test_rows_with_errors_and_warnings_are_shown_apart`、`test_rows_with_errors_are_left_out_and_the_rest_is_kept` | 検出 |
| D04 | Excel の日付の数のセルを年度の外でも入れる（`PaperDate.php`） | Tests: 1374, Assertions: 9487, Failures: 1. | `test_a_wrong_date_is_not_read` | 検出 |
| F01 | 受付簿の右の段を読まない（`PaperLedgerFile.php`） | Tests: 1374, Assertions: 9473, Failures: 3. | `test_the_next_numbers_of_this_year`、`test_the_two_sided_register_is_read`、`test_too_many_rows_are_refused` | 検出 |
| F02 | 金額の控えのシートを受付簿として読む（`PaperLedgerFile.php`） | Tests: 1374, Assertions: 9357, Errors: 2, Failures: 9. | `test_amount_rows_without_amounts_are_not_linked`、`test_amounts_are_linked_by_the_date_and_the_subject`、`test_an_ambiguous_amount_row_is_chosen_on_the_confirmation`、`test_an_amount_row_left_unlinked_can_be_linked_on_a_later_upload`、`test_an_existing_record_only_gets_its_empty_amounts_filled`、`test_an_upload_shows_the_confirmation_without_writing` ほか 5 本 | 検出 |
| F03 | 粗利益の式（=+F5-G5）を計算せずに式の文字を読む（`PaperLedgerFile.php`） | Tests: 1374, Assertions: 9382, Errors: 1, Failures: 8. | `test_amounts_are_linked_by_the_date_and_the_subject`、`test_an_ambiguous_amount_row_is_chosen_on_the_confirmation`、`test_an_amount_row_left_unlinked_can_be_linked_on_a_later_upload`、`test_an_existing_record_only_gets_its_empty_amounts_filled`、`test_an_upload_shows_the_confirmation_without_writing`、`test_choosing_a_record_twice_shows_the_confirmation_again` ほか 3 本 | 検出 |
| F04 | 行の上限を見ない（壊れたファイルで時間とメモリを使い切る）（`PaperLedgerFile.php`） | Tests: 1374, Assertions: 9487, Failures: 1. | `test_too_many_rows_are_refused` | 検出 |
| F06 | 金額を書く前の控えの行の粗利益の式の 0 を読む（点検 I-2）（`PaperLedgerFile.php`） | Tests: 1374, Assertions: 9463, Failures: 2. | `test_amount_rows_without_amounts_are_not_linked`、`test_rows_with_errors_and_warnings_are_shown_apart` | 検出 |
| F07 | 大きなファイルを読む前に断らない（本番のメモリを使い切る。点検 I-3）（`PaperLedgerFile.php`） | Tests: 1374, Assertions: 9487, Failures: 1. | `test_a_huge_sheet_is_refused_before_it_is_read` | 検出 |
| F05 | 英字が 4 文字以上続く名前（MEMO）も部門の記号にする（`PaperLedgerFile.php`） | Tests: 1374, Assertions: 9484, Failures: 1. | `test_a_long_item_name_or_many_rows_do_not_shrink_the_sheet` | 検出 |
| A01 | 同じファイルの中の同じ番号を両方とも入れようとする（`PaperImportAnalysis.php`） | Tests: 1374, Assertions: 9482, Failures: 1. | `test_rows_with_errors_are_left_out_and_the_rest_is_kept` | 検出 |
| A02 | システムの申請の番号と同じ紙の番号を入れようとする（D7）（`PaperImportAnalysis.php`） | Tests: 1374, Assertions: 9482, Failures: 1. | `test_rows_with_errors_are_left_out_and_the_rest_is_kept` | 検出 |
| A03 | 金額のある既存の記録の金額を控えで上書きする（D6）（`PaperImportAnalysis.php`） | Tests: 1374, Assertions: 9480, Failures: 2. | `test_existing_numbers_are_kept_and_only_empty_amounts_are_filled`、`test_nothing_left_to_import_is_refused` | 検出 |
| A04 | 2 つに当たる控えを自動で結び付ける（`PaperImportAnalysis.php`） | Tests: 1374, Assertions: 9430, Failures: 5. | `test_amounts_are_linked_by_the_date_and_the_subject`、`test_an_ambiguous_amount_row_is_chosen_on_the_confirmation`、`test_an_amount_row_left_unlinked_can_be_linked_on_a_later_upload`、`test_choosing_a_record_twice_shows_the_confirmation_again`、`test_two_amounts_for_one_record_are_not_linked` | 検出 |
| A05 | 件名の空白・全角半角の違いで結び付かない（`PaperImportAnalysis.php`） | Tests: 1374, Assertions: 9464, Failures: 2. | `test_amounts_are_linked_by_the_date_and_the_subject`、`test_two_amounts_for_one_record_are_not_linked` | 検出 |
| A06 | 2 つの控えを同じ記録に結び付けても誤りにしない（`PaperImportAnalysis.php`） | Tests: 1374, Assertions: 9472, Failures: 2. | `test_choosing_a_record_twice_shows_the_confirmation_again`、`test_two_amounts_for_one_record_are_not_linked` | 検出 |
| A08 | 金額の無い控えの行を結び付ける（「補った」と数え、あとで本当の金額を補えなくなる。点検 I-2）（`PaperImportAnalysis.php`） | Tests: 1374, Assertions: 9463, Failures: 2. | `test_amount_rows_without_amounts_are_not_linked`、`test_rows_with_errors_and_warnings_are_shown_apart` | 検出 |
| A09 | 今日より先の日付の行を取り込む（点検 M-2）（`PaperImportAnalysis.php`） | Tests: 1374, Assertions: 9486, Failures: 1. | `test_a_date_after_today_is_an_error` | 検出 |
| A07 | 過去の年度の取り込みでも次の番号が進むと見せる（`PaperImportAnalysis.php`） | Tests: 1374, Assertions: 9487, Failures: 1. | `test_the_next_numbers_of_this_year` | 検出 |
| N01 | 次の番号を戻す（紙の番号が今の次の番号より小さいとき。D7）（`ApprovalNumber.php`） | Tests: 1374, Assertions: 9485, Failures: 2. | `test_a_paper_number_of_this_year_moves_the_next_number_forward_only`、`test_the_next_number_in_the_notice_is_the_one_actually_set` | 検出 |
| N02 | 過去の年度の紙の番号で今年度の次の番号を進める（§5.11）（`ApprovalNumber.php`） | Tests: 1374, Assertions: 9486, Failures: 2. | `test_a_paper_number_of_a_past_year_does_not_touch_the_numbering`、`test_a_past_year_does_not_touch_the_next_number` | 検出 |
| N03 | 開始番号を今年度の紙の番号以下にできる（`ApprovalNumber.php`） | Tests: 1374, Assertions: 9484, Failures: 1. | `test_the_start_number_cannot_go_under_this_years_paper_numbers` | 検出 |
| I01 | 確認のあとでシステムの申請が使った番号でも取り込む（`PaperImporter.php`） | Tests: 1374, Assertions: 9479, Errors: 1, Failures: 1. | `test_a_system_number_taken_after_the_confirmation_stops_the_import`、`test_a_system_number_taken_during_the_confirmation_is_refused` | 検出 |
| I02 | 確定の瞬間に手で入れた金額を上書きする（`PaperImporter.php`） | Tests: 1374, Assertions: 9486, Failures: 1. | `test_amounts_typed_during_the_confirmation_are_not_overwritten` | 検出 |
| I03 | 紙の番号を足しても次の番号を進めない（取り込み・1 件ずつ）（`ApprovalNumber.php`） | Tests: 1374, Assertions: 9464, Failures: 5. | `test_a_paper_record_is_registered_from_the_form`、`test_a_system_number_taken_during_the_confirmation_is_refused`、`test_confirming_imports_the_records_and_advances_the_next_number`、`test_the_next_number_in_the_notice_is_the_one_actually_set`、`test_the_number_can_be_changed_but_not_onto_another_record` | 検出 |
| K01 | ロックのあとのシステムの番号の確かめを普通の読みにする（MySQL の REPEATABLE READ で古い中身を読む。点検 I-1）（`ApprovalNumber.php`） | OK (1374 tests, 9487 assertions) | — | **SQLite では等価**（MySQL の 2 本の接続で確かめた） |
| K02 | 開始番号の検査の紙の番号を普通の読みにする（点検 I-1）（`ApprovalNumber.php`） | OK (1374 tests, 9487 assertions) | — | **SQLite では等価**（同じ理由） |
| C01 | 確定の二度押しで二重に取り込む（`PaperImportController.php`） | Tests: 1374, Assertions: 9484, Failures: 2. | `test_a_second_submit_is_refused_without_writing`、`test_every_import_controller_claims_a_one_time_key` | 検出 |
| C02 | 確定で選んだ結び付けを使わない（`PaperImportController.php`） | Tests: 1374, Assertions: 9470, Failures: 3. | `test_an_ambiguous_amount_row_is_chosen_on_the_confirmation`、`test_an_amount_row_left_unlinked_can_be_linked_on_a_later_upload`、`test_choosing_a_record_twice_shows_the_confirmation_again` | 検出 |
| C03 | 結び付けの誤りがあっても取り込む（`PaperImportController.php`） | Tests: 1374, Assertions: 9474, Failures: 1. | `test_choosing_a_record_twice_shows_the_confirmation_again` | 検出 |
| C04 | 確認の画面からのアップロードし直しの断りが preview へ戻って 405（Bug #64）（`PaperImportController.php`） | Tests: 1374, Assertions: 9477, Failures: 2. | `test_a_failed_upload_from_the_confirmation_goes_back_to_the_import_screen`、`test_import_controllers_send_every_validation_failure_to_a_fixed_page` | 検出 |
| C06 | 確定で実際に進めた次の番号を知らせない（点検 M-6）（`PaperImportController.php`） | Tests: 1374, Assertions: 9477, Failures: 2. | `test_confirming_imports_the_records_and_advances_the_next_number`、`test_the_next_number_in_the_notice_is_the_one_actually_set` | 検出 |
| C07 | 取り込みの確定で一意の索引の競合が 500 になる（点検 I-4）（`PaperImportController.php`） | Tests: 1374, Assertions: 9483, Failures: 1. | `test_a_paper_number_taken_during_the_confirmation_is_refused` | 検出 |
| C08 | 壊れた hidden のファイルを読もうとする（点検 I-4）（`PaperImportController.php`） | Tests: 1374, Assertions: 9485, Failures: 1. | `test_a_broken_file_in_the_confirmation_is_refused` | 検出 |
| C05 | 選択肢に無い年度（先の年度）で取り込める（`PaperImportController.php`） | Tests: 1374, Assertions: 9484, Failures: 1. | `test_a_failed_upload_from_the_confirmation_goes_back_to_the_import_screen` | 検出 |
| V04 | 選べる控えだけの確認の画面に確定のフォームを描かない（`_import_preview.blade.php`） | Tests: 1374, Assertions: 9478, Failures: 1. | `test_an_amount_row_left_unlinked_can_be_linked_on_a_later_upload` | 検出 |
| V05 | 選び直す画面で選んだ結び付け先を残さない（`_import_amounts.blade.php`） | Tests: 1374, Assertions: 9485, Failures: 1. | `test_choosing_a_record_twice_shows_the_confirmation_again` | 検出 |
| V06 | 結び付けの select を確定のフォームの外に出す（選んでも送られない）（`_import_preview.blade.php`） | Tests: 1374, Assertions: 9408, Failures: 5. | `test_an_ambiguous_amount_row_is_chosen_on_the_confirmation`、`test_an_amount_row_left_unlinked_can_be_linked_on_a_later_upload`、`test_an_upload_shows_the_confirmation_without_writing`、`test_choosing_a_record_twice_shows_the_confirmation_again`、`test_rows_with_errors_and_warnings_are_shown_apart` | 検出 |
| V07 | ファイル名の「08年度」を平成として選ぶ（`import.blade.php`） | Tests: 1374, Assertions: 9487, Failures: 1. | `test_the_year_is_picked_from_the_file_name` | 検出 |
| V08 | 今の年度を先に選ばない（いちばん古い年度にならない限り同じ＝先頭は今の年度なので生き残りうる）（`import.blade.php`） | Tests: 1374, Assertions: 9482, Failures: 1. | `test_the_import_screen_offers_the_years_and_the_list_links_to_it` | 検出 |
| R01 | システムの申請の番号と同じ紙の決裁を 1 件ずつ登録できる（`PaperRecordController.php`） | Tests: 1374, Assertions: 9452, Failures: 1. | `test_a_number_already_in_use_is_refused` | 検出 |
| R02 | 直すときに自分の番号と重なると断る（番号を変えずに直せない）（`PaperRecordController.php`） | Tests: 1374, Assertions: 9482, Failures: 2. | `test_a_record_is_edited_and_only_the_changes_are_logged`、`test_the_number_can_be_changed_but_not_onto_another_record` | 検出 |
| R03 | 1 件ずつの登録で次の番号を進めない（`PaperRecordController.php`） | Tests: 1374, Assertions: 9478, Failures: 2. | `test_a_paper_record_is_registered_from_the_form`、`test_the_number_can_be_changed_but_not_onto_another_record` | 検出 |
| R04 | 直した人を残さない（取り消しの「手で直した」の数が合わない）（`PaperRecordController.php`） | Tests: 1374, Assertions: 9483, Failures: 1. | `test_a_record_is_edited_and_only_the_changes_are_logged` | 検出 |
| R05 | 直していない決裁日を「変わった」と記録する（`PaperRecordController.php`） | Tests: 1374, Assertions: 9485, Failures: 1. | `test_a_record_is_edited_and_only_the_changes_are_logged` | 検出 |
| R06 | 年度の期間の外の決裁日を入れられる（次の番号を進められずに採番と重なる。点検 M-2）（`PaperRecordController.php`） | Tests: 1374, Assertions: 9467, Failures: 1. | `test_a_decided_date_outside_the_year_or_after_today_is_refused` | 検出 |
| R07 | 今日より先の決裁日を 1 件ずつ入れられる（点検 M-2）（`PaperRecordController.php`） | Tests: 1374, Assertions: 9484, Failures: 1. | `test_a_decided_date_outside_the_year_or_after_today_is_refused` | 検出 |
| R08 | 1 件ずつの登録で一意の索引の競合が 500 になる（点検 I-4）（`PaperRecordController.php`） | Tests: 1374, Assertions: 9483, Failures: 1. | `test_a_number_taken_while_saving_is_refused_with_the_input_kept` | 検出 |
| R09 | 保存のフォームの二度押し止めを外す（点検 M-1）（`form.blade.php`） | Tests: 1374, Assertions: 9483, Failures: 1. | `test_the_save_form_is_sent_only_once` | 検出 |
| U01 | 見せた件数と今の件数が違っても消す（`PaperImportController.php`） | Tests: 1374, Assertions: 9479, Failures: 1. | `test_an_undo_is_refused_when_the_records_have_changed_or_it_is_done` | 検出 |
| U04 | 見せたあとで手で直した記録が増えても消す（点検 M-4）（`PaperImportController.php`） | Tests: 1374, Assertions: 9476, Failures: 1. | `test_an_undo_is_refused_when_the_records_have_changed_or_it_is_done` | 検出 |
| U02 | 取り消した回をもう一度取り消せる（`PaperImportController.php`） | Tests: 1374, Assertions: 9486, Failures: 1. | `test_an_undo_is_refused_when_the_records_have_changed_or_it_is_done` | 検出 |
| U03 | 記録の残っていない回にも取り消しのボタンを出す（`index.blade.php`） | Tests: 1374, Assertions: 9484, Failures: 1. | `test_a_long_item_name_or_many_rows_do_not_shrink_the_sheet` | 検出 |
| S01 | 見られない人に紙の決裁の詳細を見せる（`PaperDetailController.php`） | Tests: 1374, Assertions: 9485, Failures: 1. | `test_the_people_who_may_see_it_can_open_it` | 検出 |
| S02 | 管理者でない人に「直す」を出す（`show.blade.php`） | Tests: 1374, Assertions: 9485, Failures: 1. | `test_the_detail_shows_the_record_and_offers_the_edit_to_admins` | 検出 |
| S03 | 見られない紙の決裁も関連する決裁No のリンクにする（`RequestController.php`） | Tests: 1374, Assertions: 9486, Failures: 1. | `test_a_related_paper_number_links_to_the_paper` | 検出 |
| S04 | 見られない紙の決裁を候補に出す（`RelatedNumberController.php`） | Tests: 1374, Assertions: 9484, Failures: 1. | `test_visible_papers_are_offered_as_candidates` | 検出 |
| S05 | 候補を決裁日の順に並べない（申請が多いと紙が出ない）（`RelatedNumberController.php`） | Tests: 1375, Assertions: 9488, Failures: 1. | `test_a_newer_paper_comes_before_an_older_request` | 検出 |
| S06 | 紙の決裁の詳細のルートが無い（`approval.php`） | Tests: 1374, Assertions: 9450, Errors: 4, Failures: 4. | `test_a_paper_row_reads_as_a_paper`、`test_a_related_paper_number_links_to_the_paper`、`test_every_approval_route_is_classified`、`test_every_approvals_route_is_classified`、`test_it_is_closed_before_launch`、`test_the_detail_shows_the_record_and_offers_the_edit_to_admins` ほか 2 本 | 検出 |
| G01 | 申請の種類を選んでも紙が出る（`LedgerFilter.php`） | Tests: 1374, Assertions: 9480, Failures: 1. | `test_the_filters_apply_to_papers` | 検出 |
| G02 | 判断「不明」でも申請が出る（`LedgerFilter.php`） | Tests: 1374, Assertions: 9485, Failures: 1. | `test_the_filters_apply_to_papers` | 検出 |
| G03 | 「進行中」に紙が出る（`LedgerFilter.php`） | Tests: 1374, Assertions: 9482, Failures: 1. | `test_the_filters_apply_to_papers` | 検出 |
| G04 | キーワードで紙の備考を探さない（`Ledger.php`） | Tests: 1374, Assertions: 9479, Failures: 1. | `test_the_filters_apply_to_papers` | 検出 |
| G05 | 決裁日の「まで」の日の紙が外れる（SQLite）（`Ledger.php`） | Tests: 1374, Assertions: 9477, Failures: 1. | `test_the_filters_apply_to_papers` | 検出 |
| G06 | 台帳に見られない紙の決裁を出す（`Ledger.php`） | Tests: 1374, Assertions: 9484, Failures: 1. | `test_only_visible_papers_are_listed` | 検出 |
| G07 | 申請者で紙を絞らない（`Ledger.php`） | Tests: 1374, Assertions: 9478, Failures: 1. | `test_the_filters_apply_to_papers` | 検出 |
| G08 | 年度の選択肢が紙のいちばん古い年度まで届かない（`Ledger.php`） | Tests: 1374, Assertions: 9486, Failures: 1. | `test_the_years_reach_back_to_the_oldest_paper` | 検出 |
| G09 | 申請を全部並べてから紙を並べる（決裁日の順に混ぜない）（`Ledger.php`） | Tests: 1374, Assertions: 9487, Failures: 1. | `test_papers_are_sorted_together_with_requests` | 検出 |
| G10 | 紙の行から申請の詳細（同じ id の別の申請）を開く（`LedgerRow.php`） | Tests: 1374, Assertions: 9481, Failures: 2. | `test_a_paper_row_reads_as_a_paper`、`test_the_screen_shows_papers_with_the_new_filters` | 検出 |
| G11 | Excel に区分の列が無い（19 列のまま）（`LedgerExcel.php`） | Tests: 1374, Assertions: 9477, Failures: 4. | `test_the_columns_and_the_values_of_a_row`、`test_the_excel_has_the_paper_rows_and_logs_the_new_filters`、`test_the_four_columns_of_a_table_request`、`test_the_heading_is_frozen_and_has_filter_buttons` | 検出 |
| G12 | 名前の列に決まっていない列（SQL に埋め込む）を受け取る（`NameSearch.php`） | Tests: 1376, Assertions: 9489, Failures: 1. | `test_the_name_search_takes_only_known_columns` | 検出 |
| G13 | 判断「不明」で絞った画面で選択が外れて見える（`index.blade.php`） | Tests: 1374, Assertions: 9483, Failures: 1. | `test_the_screen_shows_papers_with_the_new_filters` | 検出 |
| G14 | 出力の記録に区分を残さない（`LedgerFilter.php`） | Tests: 1374, Assertions: 9485, Errors: 1, Failures: 2. | `test_each_download_is_recorded_with_the_conditions_and_the_count`、`test_the_excel_has_the_paper_rows_and_logs_the_new_filters`、`test_the_log_keeps_every_key_in_the_same_order` | 検出 |

- [ ] **Step 3: 表と違ったものを調べ、検出できなかった変異にテストを足す**

⚠ **等価**（緑が正しい）と書いたものは、なぜ等価かを 1 行で確かめる（読み違えて「守られていない」としない）。等価でないのに緑のものは、その Task のテストに 1 本足し、足したテストが変異で赤・元に戻して緑になることを確かめてからコミットする。

- [ ] **Step 4: 結果をこの計画に追記してコミット**

検出／当初検出漏れ→追加で検出／等価を区別して、この計画の末尾に「Task 9 の実測記録」として書き足す。

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add docs/superpowers/plans/2026-10-08-approval-phase5b.md tests/
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
test(approval): 段階5b の変異テストの結果を記録する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

写し（`<scratchpad>/p5b-mutation`）は、この Task が済んだら消してよい（`rm -rf` の前に中身が写しであることを確かめる）。

---

## Task 10: 手元のブラウザでの確認と、利用者に見せる写真・Excel（設計書 §6）

**Files:** なし（測るだけ）

テストが原理的に測れない領域（⑪ のアップロードと確認の画面の見た目・ファイルを選んだときの年度の先選び・金額の控えの選び直し・二度押し止め・1 件ずつの登録の画面・紙の決裁の詳細・台帳の紙の行と新しい絞り込み・Excel を開いたときの見た目）を見る。**本番では使い始めるまで ⑪ 以外は出ないので、利用者に見てもらうのはここで撮る写真と Excel の画像**。WT の HEAD の写し（scratchpad）＋使い捨ての SQLite ＋ `artisan serve`。ブラウザは Playwright（ログインは利用者の決まり「手元の画面にログイン」のとおり、試しのパスワードを画面にも記録にも出さない）。Excel は開かずに確かめる（利用者の決まり。クイックルックと読み戻し）。**アップロードするのは作りものの台帳**（本物は使わない。D24）。

- [ ] **Step 1: 写しと使い捨ての環境を作る**

⚠ Bash の呼び出しごとにシェルが新しくなるので、使い捨ての設定は 1 つのファイルにまとめ、以降のコマンドの先頭で `source` する。⚠ WT にも写しにも `.env` を作らない。⚠ `APP_LOCALE=ja`・`APP_FALLBACK_LOCALE=ja` を入れる。⚠ `php` は 8.3 を名指しする。

```bash
SCR=<scratchpad>/p5b-browser && mkdir -p "$SCR" && git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 archive HEAD | tar -x -C "$SCR" && cp -Rc /Users/masanori/site/manage/.claude/worktrees/approval-phase3/vendor "$SCR/vendor"
LOCAL=<scratchpad>/approval-phase5b-local.sh
cat > "$LOCAL" <<SH
export PHP=/opt/homebrew/opt/php@8.3/bin/php
export APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')"
export APP_LOCALE=ja
export APP_FALLBACK_LOCALE=ja
export APP_URL=http://127.0.0.1:8772
export DB_CONNECTION=sqlite
export DB_DATABASE="<scratchpad>/approval-phase5b.sqlite"
export MAIL_MAILER=log
export QUEUE_CONNECTION=sync
export TRIAL_PASSWORD="$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo bin2hex(random_bytes(6));')"
SH
source "$LOCAL" && touch "$DB_DATABASE" && cd "$SCR" && $PHP artisan migrate --force && ln -s /Users/masanori/site/manage/node_modules node_modules && ./node_modules/.bin/vite build
```

- [ ] **Step 2: 試しのデータと作りものの台帳を作る**（テストの土台と同じ形。⚠ ログイン用の使い捨てルートを作らない）

計画を書く段階に使った入れ方（`~/.claude/plans/approval-phase3-tasks/5b/seed.php`。会社・部門〈住宅事業部 J・住宅（少額・追加工事）JB・ミツワ不動産 M・総務部 S〉・決裁の管理者・住宅事業部の申請者・部門長・審査担当者・社長、決裁済みの申請 2 件、作りものの台帳 `<scratchpad>/08年度・見本台帳.xls`〈受付簿 J・JB・M・部門の無いシート・金額の控え 2 枚。結び付けを選ぶ行・取り込まない行・前の年度の書き残しを含む〉）:

```bash
source <scratchpad>/approval-phase5b-local.sh && cd <scratchpad>/p5b-browser && $PHP artisan tinker --execute="$(cat ~/.claude/plans/approval-phase3-tasks/5b/seed.php)"
```

Expected: 部門と申請の件数・台帳のパス・決裁の管理者と申請者のログイン名。パスワードは `source` したシェルの `$TRIAL_PASSWORD`（値を出さない）。

画面を出す（**バックグラウンドで**。止めるまで動き続ける）:

```bash
source <scratchpad>/approval-phase5b-local.sh && cd <scratchpad>/p5b-browser && $PHP artisan serve --port=8772
```

- [ ] **Step 3: 見ること**（1440px と 375px の両方）

| # | 画面 | 見ること |
|---|---|---|
| 1 | ⑪ 取り込みの画面（決裁の管理者・使い始める前） | 台帳の形と「取り込むとどうなるか」の説明・ファイルを選ぶと年度が「R8 年度（2026）」に変わる（`08年度` の名前から）・サイドバーとホームの入口 |
| 2 | ⑪ 確認の画面 | 件数のチップ・シートの表（読まないシートも）・進む次の番号・取り込まない行（赤）・注意（黄）・足す記録・金額の控え（自動は文字・選ぶ行は select）。375px で横のはみ出しが無い（表は枠の中でスクロール） |
| 3 | ⑪ 確定 | 選ぶ行で 2 つの控えを同じ記録に選ぶと選び直しの画面（新しい鍵）・選び直して「取り込む」で一覧へ。ボタンは押した瞬間に押せなくなり「取り込んでいます…」・「戻る」で戻ってもう一度押すと断られる |
| 4 | ⑪ 一覧と取り込みの記録 | 年度・部門の絞り込み（変えた瞬間に送る）・取り込みの記録の「取り消す（今 N 件・うち手で直した M 件）」と確かめの文・取り消したあとの記録の文 |
| 5 | 1 件ずつの登録・直し | 欄の並び（375px は 1 列）・番号の重なりで断られて中身が残る・期間の外の決裁日の知らせ・削除の確かめの文 |
| 6 | 部門の管理 | 編集の小窓の「今年度（R8）は紙の決裁で R8-J-0NN まで使っています。」 |
| 7 | 紙の決裁の詳細（申請者・使い始めてから） | 灰色の「紙」・「紙の決裁です。原本は紙で保管しています。」・金額と粗利率・管理者だけに「直す」 |
| 8 | 台帳（使い始めてから） | 紙の行の灰色の「紙」・行の先が紙の決裁の詳細・判断「不明」と区分の絞り込み・375px のカード |
| 9 | 申請の画面と詳細 | 関連する決裁No の候補に「紙」と添えて出る・詳細の関連する決裁No が紙の決裁の詳細へのリンク |
| 10 | Excel（台帳から。`page.request` で取り出す） | 20 列・区分（システム／紙）・紙の行の判断「不明」と状態の空欄・金額と粗利率。クイックルック（`qlmanage -t -s 1600`）か、読み戻して HTML にした画像（`~/.claude/plans/approval-phase3-tasks/5b/xlsx2html.php`） |
| 11 | 全画面 | `main.scrollWidth === main.clientWidth` を 1440 / 375px で（Bug #29）・コンソールのエラーと警告が 0 件 |

- [ ] **Step 4: 利用者に見せる写真と Excel**

1440px と 375px の ⑪（取り込みの画面・確認の画面・一覧）・1 件ずつの登録・紙の決裁の詳細・台帳と、Excel の画像を撮って scratchpad に保存し、利用者に送る（`SendUserFile`）。

- [ ] **Step 5: コンパイル済みビューを lint する**

⚠ `view:cache` の成功表示だけでは足りない（Bug #21 / #26 / #30）。

```bash
source <scratchpad>/approval-phase5b-local.sh && cd <scratchpad>/p5b-browser && $PHP artisan view:cache && for f in storage/framework/views/*.php; do $PHP -l "$f" >/dev/null || echo "INVALID: $f"; done; $PHP artisan view:clear
```

Expected: INVALID 0 件。

- [ ] **Step 6: 片付けて結果を記録する**

`artisan serve` を止めたことを確かめてから、写しと使い捨てのファイルを消す（`rm -rf` の前に、消すのが scratchpad の写しであることを `pwd` と `ls` で確かめる）。WT の `git status --porcelain` が空であることも確かめる。見たことと見つけた不具合を、この計画の末尾に「Task 10 の実測記録」として書き足してコミットする（`docs(approval): 段階5b の画面と Excel の確認の結果を記録する`）。

---

## Task 11: ドキュメント

**Files:**
- Modify: `docs/決裁申請_要件定義書_v1.md`（v1.14 → **v1.15**。5b の分）・`docs/決裁申請_過去台帳の調査.md`（金額の控えの結び付き）・`docs/BACKLOG.md`（段階5 の節の 5b）・`CLAUDE.md`（決裁の注意の行と、モジュールの表の決裁の行）

- [ ] **Step 1: 要件定義書を v1.15 にする**（設計書 §3 のうち 5b の分と、計画の段で実物の台帳から分かったこと。⚠ 文の書き換えは下のとおりに。ほかの行は変えない）

1. 1 行目の題を `# 決裁申請システム 要件定義書 v1.15` にし、変更履歴の表の最後に 1 行足す:

```markdown
| v1.15 | 2026/10/13 | 段階 5 の後半（5b: 過去の紙の決裁の取り込み）の設計と計画で決まったことを反映した（設計書は `docs/superpowers/specs/2026-10-07-approval-phase5-design.md`）。(1) 取り込みは**足すだけ**（すでにある決裁No は変えず、金額が空の記録にだけ金額の控えから補う）・**読めない行はその行だけ取り込まない**・**取り込み 1 回分をまとめて取り消せる**（11.4）。(2) 金額の控えは**すべての部門のシート**から探す。08 年度は 20 件すべてが結び付いた（v1.14 までの「9 件中 4 件」は住宅のシートだけと比べた数だった。11.3）。(3) 使われた行は日付・申請者・件名のどれかで判定し、日付の年は月で決める（11.1）。(4) **今年度の紙の番号を取り込むと、その部門の次の番号が進む**（戻らない。6.4）。(5) スキャンした PDF の一括の紐づけは、スキャンすると決まってから作る（11.5・13 章の⑪）。(6) 取り込み 1 回分の表を足した（15.5） |
```

2. 6.4 の箇条の 2 つ目（「開始番号は、その年度にすでに使った番号より小さくできない（番号の重なりを防ぐため）。」）を「開始番号は、その年度にすでに使った番号と、取り込んだ今年度の紙の決裁の番号より小さくできない（番号の重なりを防ぐため）。」にし、その後ろに足す:

```markdown
- 今年度の紙の決裁を取り込む（1 件ずつ登録する・番号を直す）と、その部門の次の番号が「紙のいちばん大きい番号＋1」まで自動で進む（戻らない。取り込みを取り消しても戻らないので、戻すときは開始番号を設定し直す）。過去の年度の紙の番号は採番に関わらない。システムの申請がすでに持っている番号と同じ紙の番号は取り込まない。
- 取り込んだ紙の決裁がある部門も、会社とアルファベットを変えられない（紙の決裁No にも入っているため）。
```

3. 11.1 の箇条の「**ＮＯ は空の行にも印字済み**（1〜120）。ＮＯ の有無ではなく、**日付の有無**で実際に使われた行かを判定する。」を「**ＮＯ は空の行にも印字済み**（1〜120）。ＮＯ の有無ではなく、**日付・申請者・件名のどれかが書いてあるか**で実際に使われた行かを判定する。日付が無いのに件名などがある行は、書き忘れとして取り込まずに確認の画面で知らせる。」にし、「日付は**和暦のドット形式**…」の箇条の最後に「年は月で決める（期の始まりの月より前の月は、年度の次の年）。1〜4 月は「9.01.15」とも「8.01.15」とも書けるので、先頭の数は年度の和暦の年か、その日の和暦の年のどちらかと合えばよい（どちらとも合わない日付は前の年度の書き残しとして取り込まない）。」を足す
4. 11.3 の箇条の「突き合わせは完全にはできない（08 年度では 9 件中 4 件）。書き方のゆれがあるため。**突き合わなかった分は取り込みの確認画面に出し、決裁の管理者が結び付ける**。」を次に置き換える:

```markdown
- 控えは**住宅のシートだけでなく、ほかの部門のシートの行にも当たる**（08 年度の「土地・建物契約・金額」の 9 件は、住宅 4 件とミツワ不動産 5 件）。すべてのシートから、空白と全角・半角の違いを無視した件名と日付で探すと、08 年度は控えの 20 件すべてが 1 件に結び付いた（2026/10/08 に実物で確認）。
- **1 件に決まらない行（当たる行が無い・2 つ以上ある・2 つの控えが同じ行に当たる）は取り込みの確認画面に出し、決裁の管理者が結び付ける**（結び付けないこともできる。結び付けなかった行は、同じ台帳を取り込み直したときに結び付けられる）。
- 金額をまだ書いていない控えの行（契約金額・工事原価・粗利益がどれも空）は結び付けない。粗利益の式は、契約金額と工事原価がどちらも書いてあるときだけ使う（空のセルを 0 として計算した値を入れない）。
```

5. 11.4 の箇条の最後（「何年分を取り込むかは…」の前）に足す:

```markdown
- 読めない行（日付が無い・年度と合わない・部門が登録されていない・システムの申請と同じ番号など）は**その行だけ取り込まず**、ほかの行は取り込む。確認の画面に、どの行がなぜかを出す。直すときは Excel を直して取り込み直す。
- 同じ年度のファイルを取り込み直すと**足すだけ**になる。すでにある決裁No は変えない（Excel と違えば確認の画面で知らせる。直すときは 1 件ずつ）。金額が空の記録にだけ、金額の控えから補う。
- 取り込みを間違えたときは、**取り込み 1 回分をまとめて取り消せる**（その回に足した記録を、そのあと手で直したものも含めて消す。消す件数を見せて確かめる）。
- 決裁日は**今日まで**（今日より先の日付の行は取り込まない）。1 件ずつの登録・修正では、決裁日は選んだ年度の期間の中で、今日までの日付にする。決裁No（年度・部門・ＮＯ）がほかの紙の決裁やシステムの申請と重なれば断る。
```

6. 11.5 の 2 つ目の段落の「…紐づく仕組みは用意しておく（あとから足すほうが手間がかかるため）。紐づかなかったファイルは一覧で知らせる。」を「…紐づく仕組みは、**スキャンすると決まってから作る**（ファイルの大きさと枚数が分かってから上限を決められるため。段階 5 の設計の判断）。取り込んだ紙の決裁の詳細の画面と表は、あとで PDF を付けられる形にしてある。」にする
7. 13 章の表の ⑪ の行の「11 章の取り込み・PDF の一括紐づけ・1 件ずつの登録と修正」を「11 章の取り込み（確認の画面）・1 件ずつの登録と修正と削除・取り込み 1 回分の取り消し（PDF の一括紐づけはスキャンすると決まってから。11.5）」にする
8. 10 章の 2 つ目の箇条の最後の文「取り込んだ紙の決裁（区分・備考・判断の「不明」）は段階 5 の後半（5b）で足す（16.1）。」を「段階 5 の後半（5b）で取り込んだ紙の決裁を足した（判断の「不明」・区分の絞り込み。Excel は区分の列を状態の前に足して 20 列）。」にする
9. 15.5 の表の `approval_paper_records` の行を「取り込んだ過去の紙の決裁（決裁No・年度・部門・ＮＯ・決裁日・申請者の文字・件名・契約金額・工事原価・粗利益・備考・取り込み 1 回分。11 章）」にし、その下に 1 行足す: `| approval_paper_imports | 紙の決裁の取り込み 1 回分（ファイル名・年度・足した件数・補った件数・取り込んだ人。取り消しの単位で、取り消しても行は残す） |`

- [ ] **Step 2: 過去台帳の調査を直す**

`docs/決裁申請_過去台帳の調査.md` の「控えと受付簿の対応」の表の「土地・建物契約・金額」の行の「9 件中 4 件」を「9 件中 4 件（**Ｍ・ミツワ不動産の 5 件と合わせて 9 件すべて**。2026/10/08 に追記）」にし、「実装で注意が要る点」の 5 を次に置き換える:

```markdown
5. **金額の控えは、すべての部門のシートから探す**（土地・建物契約の控えは住宅とミツワ不動産の両方の受付簿に当たる。すべてのシートから、空白と全角・半角の違いを無視した件名と日付で探すと、08 年度は 20 件すべてが 1 件に結び付いた。2026/10/08 に追記）。1 件に決まらない分は
   **取り込みの確認画面で人が結び付ける**
```

- [ ] **Step 3: BACKLOG の段階5 の節を直す**

段階5 の節（`## 🚧 決裁申請 段階5（…）`）の見出しを「— 5a 本番反映済み・5b 実装済み・本番反映前」にし、「### 5b（過去の決裁の取り込み）」の「未着手。…」を次に置き換える（⚠ ファイルの末尾に足さない）:

````markdown
実装計画: @docs/superpowers/plans/2026-10-08-approval-phase5b.md（Task 0〜12）。worktree `.claude/worktrees/approval-phase3`（5a と同じ）。

- 表: `approval_paper_records`（紙の決裁。`number` は一意・(部門, 年度, ＮＯ) も一意）・`approval_paper_imports`（取り込み 1 回分。取り消しても残す）。本番の SQL は `database/sql/2026-10-08-approval-phase5b.sql`（2 文）。本番反映は **DB が先・`./deploy.sh` が後**
- 読み取りは `PaperLedgerFile`（読むだけ）→ `PaperImportAnalysis`（確認と確定が同じものを作る）→ `PaperImporter`（ロック → システムの番号 → 保存）。日付は `PaperDate`（年は月で決める・先頭の年は年度かその日の和暦と合うこと）。**本物の台帳はリポジトリに入れない**（テストは `BuildsPaperLedgers`）
- 次の番号は `ApprovalNumber::advancePast()`（今年度だけ・戻さない）。開始番号は今年度の紙の番号以下を断る
- 見られる範囲は `RequestVisibility::constrainPaper()` の 1 か所。台帳は `Ledger::sortedKeys()`（申請 `r`・紙 `p` の鍵）と `paperQuery()`、紙が当たる条件は `LedgerFilter::includesPapers()`、紙の行は `LedgerRow::forPaper()`
- 紙の決裁日の期間の終わりは「次の日より前」で比べる（SQLite は日付の列に時刻の付いた文字で入る）
- 計画で決めた細部（計画 §0.14）と受け入れた隙間（計画 §0.15）は計画のとおり
- 実装のコミット（計画のコミットの次から。古い順）: Task 1〜8 の 8 本と記録（Task 9・10）。`git log` で見る
````

- [ ] **Step 4: CLAUDE.md を直す**

`## 主要モジュール`（モジュールの表）の決裁の行の頭の「決裁申請 段階1〜5a」を「決裁申請 段階1〜5b」にし、「申請の種類ごとの作り込み（…）」の後ろに「・過去の紙の決裁の取り込み（⑪。紙は専用の表 `approval_paper_records`）」を足す。決裁の注意の行（金額の明細表の行）の後ろに 1 行足す:

```markdown
- 決裁の過去の紙の決裁は専用の表（`approval_paper_records`）。申請の表を読むホーム・自分の申請・⑩・お知らせ・催促には出さない（台帳・Excel・関連する決裁No・紙の決裁の詳細だけが読む）。見られる範囲は `RequestVisibility::constrainPaper()`、台帳で紙が当たる条件は `LedgerFilter::includesPapers()` の 1 か所。**本物の紙の台帳（実在の人の名字と案件）はリポジトリに入れない**
```

- [ ] **Step 5: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add docs/決裁申請_要件定義書_v1.md docs/決裁申請_過去台帳の調査.md docs/BACKLOG.md CLAUDE.md
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
docs(approval): 段階5b の決まりを要件定義書 v1.15・BACKLOG・CLAUDE.md に書く

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

コミットのあとで `git merge-tree --write-tree approval-phase3 13.x` が衝突を出さないことを確かめる（BACKLOG は別の会話も書く）。要件定義書の読みやすい版（artifact）は、親の会話が同じ URL で更新する（利用者の決まり）。

---

## Task 12: 本番反映（利用者の了承を取ってから・親の会話が行う）

> ⚠ **サブエージェントに任せない。** 本番への `ssh`・`scp`・`./deploy.sh` は、それぞれ利用者の了承を取ってから行う。本番のファイルの削除は利用者が行う（実行する 1 行を渡す）。`.env` は読まない。
> ⚠ 本番のシェルは csh なので、`/bin/sh` の heredoc を `ssh` に流す（段階1〜5a と同じ作法）。PHP は `/usr/local/php/8.3/bin/php` を明示する（既定の `php` は 7.4）。
> ⚠ 別の会話（manage）が `13.x` を頻繁に進めて本番に出している。反映の前に `SendMessage` で、反映が済むまで `13.x` の早送りと `./deploy.sh` を待ってもらう（5a と同じ）。
> ⚠ **本物の台帳の取り込みはこの Task ではしない**（使い始める日までに、決裁の管理者が ⑪ で行う。利用者が決める）。

- [ ] **Step 1: 了承を求める（選択式）**

伝えること: ①**DB が先・`./deploy.sh` が後**（新しいコードが紙の決裁の表を読むので、逆だと台帳・部門の管理・⑪ が 500 になる）②本番に表 `approval_paper_imports` と `approval_paper_records` を作る（今の表とデータは変えない）③**部品の追加は無い**（`composer install` は要らない）④**使い始める前なので、利用者に見える変化は ⑪（過去の決裁の取り込み）の入口と画面だけ**（台帳・紙の決裁の詳細は使い始めるまで出ない）⑤戻すときは、`13.x` の前のコミットで `./deploy.sh`（作った表は残しても害は無い）。

- [ ] **Step 2: 反映前に本番を読み取る**（読み取りだけ）

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage || exit 1
/usr/local/php/8.3/bin/php artisan route:list --name=approvals. --json | /usr/local/php/8.3/bin/php -r '$r = json_decode(stream_get_contents(STDIN), true); echo "approvals=", count($r), PHP_EOL;'
/usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
$schema = $db->getSchemaBuilder();
echo "paper_records=", $schema->hasTable("approval_paper_records") ? "ある" : "ない", " paper_imports=", $schema->hasTable("approval_paper_imports") ? "ある" : "ない", PHP_EOL;
echo "launched_at=", var_export(App\Models\ApprovalSetting::current()->launched_at, true), PHP_EOL;
echo "departments=", $db->table("approval_departments")->count(), " requests=", $db->table("approval_requests")->count(), " sequences=", $db->table("approval_number_sequences")->count(), PHP_EOL;
echo "memory_limit=", ini_get("memory_limit"), " upload_max_filesize=", ini_get("upload_max_filesize"), " post_max_size=", ini_get("post_max_size"), PHP_EOL;
'
ls -la storage/logs/laravel.log
SH
```

Expected: `approvals=` の数（控えておく。5b で 11 本増える＝⑪ の 10 本と紙の決裁の詳細）／ `paper_records=ない paper_imports=ない` ／ `launched_at=NULL` ／ 申請 0・連番 0（使い始める前。部門の数は控えておく）／ `memory_limit=128M` ／ `upload_max_filesize` と `post_max_size`（**控えておき、Task 12 の後に利用者へ伝える**。確定は 10MB のファイルを base64 にした約 13.4MB の POST になる＝§0.15）／ `laravel.log` の日付。**1 つでも違えば止まり、利用者に伝える**。

- [ ] **Step 3: `13.x` へ早送りで取り込む**（手元）

```bash
cd /Users/masanori/site/manage && git status --short && git merge-base --is-ancestor 13.x approval-phase3 && echo "FF できる" || echo "13.x が進んでいる"
```

「FF できる」なら:

```bash
git -C /Users/masanori/site/manage merge --ff-only approval-phase3 && git -C /Users/masanori/site/manage log --oneline -3 && ls /Users/masanori/site/manage/database/sql/2026-10-08-approval-phase5b.sql
```

「13.x が進んでいる」なら止まり、取り込み方（WT で `git merge 13.x` をしてから全件を流し直す、など。rebase しない）を利用者に選んでもらう（ほかの会話の作業が入っている）。

- [ ] **Step 4: DB を先に変える**

(a) SQL を本番の置き場所へ送る（`./deploy.sh` もあとで同じ場所へ同じものを送る）:

```bash
scp /Users/masanori/site/manage/database/sql/2026-10-08-approval-phase5b.sql mitsuwa-ud@www3586.sakura.ne.jp:apps/manage/database/sql/
```

(b) 流す前に、文の数と頭を見る（まだ何も変えない）:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$sql = preg_replace("/^--.*$/m", "", file_get_contents(base_path("database/sql/2026-10-08-approval-phase5b.sql")));
$statements = array_values(array_filter(array_map("trim", explode(";", $sql))));
echo "statements=", count($statements), PHP_EOL;
foreach ($statements as $i => $s) { echo $i + 1, ": ", strtok($s, "\n"), PHP_EOL; }
'
SH
```

Expected: `statements=2`（`CREATE TABLE \`approval_paper_imports\` (`・`CREATE TABLE \`approval_paper_records\` (`）。

(c) 流す（**すでに流した形跡があれば流さずに止まる**）:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
$schema = $db->getSchemaBuilder();
$traces = array_keys(array_filter([
    "approval_paper_imports" => $schema->hasTable("approval_paper_imports"),
    "approval_paper_records" => $schema->hasTable("approval_paper_records"),
]));
if ($traces !== []) {
    echo "STOP: すでに流した形跡がある: ", implode(", ", $traces), PHP_EOL;
} else {
    $sql = preg_replace("/^--.*$/m", "", file_get_contents(base_path("database/sql/2026-10-08-approval-phase5b.sql")));
    foreach (array_values(array_filter(array_map("trim", explode(";", $sql)))) as $i => $statement) {
        $db->statement($statement);
        echo "OK ", $i + 1, PHP_EOL;
    }
}
'
SH
```

Expected: `OK 1`・`OK 2`。⚠ 例外が出たら、その文言を利用者に伝えて止まる。流し直さない（1 つ目の表だけできていれば、それを読み取ってから利用者と決める）。

(d) 流した後を読み取る:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
foreach (["approval_paper_imports", "approval_paper_records"] as $table) {
    foreach ($db->select("SHOW FULL COLUMNS FROM {$table}") as $col) {
        echo $table, ".", $col->Field, " ", $col->Type, " null=", $col->Null, PHP_EOL;
    }
    foreach ($db->select("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY CONSTRAINT_NAME", [$table]) as $k) {
        echo "constraint ", $k->CONSTRAINT_NAME, PHP_EOL;
    }
}
echo "paper_records=", $db->table("approval_paper_records")->count(), " imports=", $db->table("approval_paper_imports")->count(), PHP_EOL;
'
SH
```

Expected: §0.2 の型（`fiscal_year smallint unsigned`・`seq int unsigned`・`number varchar(20)`・`decided_on date`・`amount`・`cost`・`profit` は `bigint null=YES`・`imported_by`・`created_by` は `null=NO`）／ 制約 `PRIMARY`・`uq_approval_paper_records_number`・`uq_approval_paper_records_seq`・外部キー `fk_approval_paper_*` ／ 件数はどちらも 0。

- [ ] **Step 5: 読み込みの表を作り直す**（手元の main repo で。部品の追加は無いので `composer install` は打たない）

```bash
cd /Users/masanori/site/manage && test ! -e vendor/bin/phpunit && /opt/homebrew/opt/php@8.3/bin/php "$(command -v composer)" dump-autoload --no-dev --optimize && test ! -e vendor/bin/phpunit && git status --short && grep -cE 'App\\\\(Support\\\\Approval\\\\(PaperDate|PaperLedgerFile|PaperImportAnalysis|PaperImportException|PaperImporter)|Models\\\\ApprovalPaper(Record|Import)|Http\\\\Controllers\\\\Approval\\\\Paper(Import|Record|Detail)Controller)' vendor/composer/autoload_classmap.php
```

Expected: `vendor/bin/phpunit` が無いまま（dev の部品が混ざっていない）・`git status` に何も出ない・最後の数が `10`（新しい 10 のクラスが読み込みの表に入った。今は `0`）。⚠ **main repo の cwd で行う**（worktree から行うと autoloader に worktree のパスが焼き込まれる）。⚠ `composer` も PHP 8.3 で動かす。

- [ ] **Step 6: 反映**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

Expected: exit 0・6 段すべて成功。

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
foreach (["App\\Support\\Approval\\PaperDate", "App\\Support\\Approval\\PaperLedgerFile", "App\\Support\\Approval\\PaperImportAnalysis", "App\\Support\\Approval\\PaperImporter", "App\\Models\\ApprovalPaperRecord", "App\\Models\\ApprovalPaperImport"] as $c) {
    echo $c, "=", class_exists($c) ? "ok" : "NG", PHP_EOL;
}
echo "reader=", class_exists("PhpOffice\\PhpSpreadsheet\\Reader\\Xls") ? "ok" : "NG", PHP_EOL;
echo "date=", App\Support\Approval\PaperDate::read("8.05.13", 2026, 5)["date"]?->format("Y-m-d") ?? "NG", PHP_EOL;
'
ls -la storage/logs/laravel.log
SH
```

Expected: `invalid=0` ／ `approvals=` が Step 2 より 11 多い ／ `launched_at=NULL` ／ 6 つとも `ok`・`reader=ok` ／ `date=2026-05-13` ／ `laravel.log` の日付が Step 2 と同じ＝反映のあとのエラーは 0 件。

- [ ] **Step 8: 利用者の Chrome で見るだけ**（決裁の管理者でログイン済みの画面。フォームは送らない。URL に `index.php/` が要る）

サイドバーの「過去の決裁の取り込み」（`…/index.php/approvals/admin/papers`）に「紙の決裁はありません」「まだ取り込んでいません」が出る・「Excel の台帳から取り込む」の画面に年度の選択肢（R8 年度から平成元年度まで）が出る（**アップロードはしない**）・「1 件ずつ登録」の画面が開く（**登録は押さない**）・部門の管理の編集の小窓の案内は今のまま・決裁のホームは「準備中」のまま・コンソールのエラー 0。

- [ ] **Step 9: 記録**

BACKLOG の段階5 の 5b の小見出しに「本番反映（日付）」の表（5a と同じ形）を足し、節の見出しを「5a・5b 本番反映済み（使い始める前）」にしてコミットする（`docs: 決裁 段階5b を本番に出した記録を残す`）。別の会話（manage）に済んだと伝える。push は利用者の指示があってから。本物の台帳の取り込みは、利用者が決めた日に、決裁の管理者が ⑪ で行う（計画の外）。

---

## 計画を書く段階の実測（2026-10-13）

### 土台と試作

- 土台は WT `approval-phase3` = `2d0d54f9`（13.x と同じ。5a の本番反映〈`caea86f8`〉と、manage の会話の「長い値の折り返し・絞り込みの行」の反映まで。計画を書く前に早送りした）
- 試作: scratchpad の写し（`git archive`）を `git init` して base `68b661a`・枝 `proto` に Task ごとに 1 コミット（task01〜08）。前の会話（2026-10-08）で task01〜04 と task05 の途中を作り、この会話（2026-10-13）で task05〜08 を作った。点検の指摘はその Task のコミットへ畳み込んだ（最後の形がこの計画のコード）。差分のファイルは `~/.claude/plans/approval-phase3-tasks/5b/patches/`（土台の写しに 8 本を `--include='tests/*'` → `--exclude='tests/*'` の順に当てると、試作の最後の木〈`a79c1cc2`〉と同じになる）
- 道具は 5a の写し（`~/.claude/plans/approval-phase3-tasks/5b/`: `fullsuite.sh`・`run-full.sh`・`redfirst.py`・`mutate.py`〈一覧を 5b に〉・`plan/build.py`〈SUBJECTS を 5b に〉・`rules.md`・`seed.php`〈5b の試しのデータと作りものの台帳〉・`xlsx2html.php`・`race/`〈2 本の接続で競合を作る〉）。PHP はすべて 8.3 を名指し
- 全件（各段）: base 4,064 本 → task01 4,065 本 → task02 4,075 本 → task03 4,077 本 → task04 4,116 本 → task05 4,149 本 → task06 4,162 本 → task07 4,170 本 → task08 4,179 本（どの段も OK・1 段およそ 4 分）
- redfirst（テストだけを先に入れる）: 8 段すべて赤（各 Task の Step 2 の Expected）

### 本物の 08 年度の台帳（手元で読んだだけ・リポジトリに入れない。2026-10-08）

- PhpSpreadsheet 5.9.0 の `Reader\Xls`。読むのは 0.31 秒・メモリ +4MB。シート 15 枚（受付簿 12 枚・金額の控え 3 枚）
- 受付簿は 34 行の塊が縦に 3〜4 つ（左段 1〜30・右段 31〜60、次の塊は 61〜120）。ＮＯ は数・**日付は文字**「8.05.13」・申請者 2〜3 文字・件名 7〜24 文字。日付のある行で件名・申請者の空は 0 件
- 金額の控えは粗利益が式（`=+F5-G5`）。**すべてのシートから探すと 20 件すべてが 1 件に結び付く**（住宅（少額）11・住宅 4・ミツワ不動産 5）
- 試作の部品で読むと **204 件を足す・エラー 0・注意 0**・次の番号は J → R8-J-035・JB → R8-JB-013・H → R8-H-019・C → R8-C-069・S → R8-S-064・M → R8-M-010（使い捨ての SQLite に部門を作って。決裁日 2026-05-07〜2026-08-27）

### 点検（別の担当〈opus〉・2026-10-13）で見つけて直したこと（計画の形に入っている）

Critical 0・Important 4・Minor 10（報告は `~/.claude/plans/approval-phase3-tasks/5b/review.md`）。見られない人への漏れ・§5.13 の絞り込み・20 列・並び・戻り先・ロックの順・走査テストへの登録は問題なし。

1. **[Important] ロックしたあとのシステムの番号の確かめが、MySQL の REPEATABLE READ では古い中身を読む**（取り込みと同じ瞬間の社長の判断が付けた番号が見えず、紙と申請が同じ決裁No を持つ）→ ロック付きの読み（`sharedLock()`）にし、`ApprovalNumber::reservePaperNumbers()` の 1 か所にまとめた。開始番号の紙の番号もロック付き（§0.6。Task 5・6）。**使い捨ての MySQL で 2 本の処理を同時に走らせ、直す前は重なり・直したあとは断ることを確かめた**（`race/`）
2. **[Important] 金額の無い控えの行の粗利益の式が 0 を書き込む**（「粗利益 0円」で記録が埋まり、あとで本当の金額を補えない）→ 粗利益の式は契約金額と工事原価がそろうときだけ・金額がどれも空の行は結び付けない（§0.3・§0.4。Task 4・5）
3. **[Important] 1.75MB の .xlsx で 128M を使い切り、捕まえられない 500**（行の数の歯止めが読み込んだあとで効いていた）→ 読む前に `listWorksheetInfo()` で行の数を見る・A〜J 列だけを読む（§0.3。Task 4）
4. **[Important] 確定のあいだの競合を断る所（catch）が HTTP の経路で一度も通っていない** → モデルのイベントで競合を作るテストを 5 本足した（Task 5・6）
5. [Minor] 1 件ずつの保存に二度押し止め（Task 6）／今日より先の決裁日を断る・1 件ずつは年度の期間の外も断る（Task 4・6）／取り消しは手で直した件数も比べる（Task 6）／取り込みの記録をすべて出す（Task 6）／知らせる次の番号は実際に進めた値（Task 5）／テストの細部 5 つ／「不明」と年度の選択肢を 1 か所に（Task 5・7）
6. 直さずに受け入れたもの: §0.15 の 1・4・5

### 手元のブラウザ（2026-10-13・写しと使い捨ての SQLite・port 8772・作りものの台帳）

- ⑪ 取り込みの画面: ファイルを選ぶと年度が「R8 年度（2026）」になる（名前の「08年度」から）。確認の画面: 足す 9 件・取り込まない 4 件（今日より先・前の年度の書き残し・日付の書き忘れ・部門の無いシート）・進む次の番号・選ぶ控えの行（同じ日付の記録が先）。確定 → 一覧へ「9 件を取り込みました（金額を補った記録 0 件）。次の番号: 住宅事業部 R8-J-032・…」
- 1 件ずつの登録: 番号の重なりで入れた中身を残して断る。部門の管理の案内「今年度（R8）は紙の決裁で R8-J-040 まで使っています。」
- 台帳: 申請と紙が決裁日の順に混ざる（同じ日は決裁No の順）・灰色の「紙」・判断「不明」と区分の絞り込み。紙の決裁の詳細・関連する決裁No の候補（「紙」の印）。Excel は 20 列・区分「紙」・状態は空・粗利率 10.3%
- 見つけて直したこと: 確認の画面の件名の列が狭く 4〜5 文字ごとに折れていた（幅を決めた）・部門の無いシートの「取り込まない」が 0 と出ていた（読んだ段の数を出す）・登録の画面の部門の欄が狭く名前が切れていた（2 列分に）。Task 4〜6 に畳み込んだ
- 1440px と 375px で `main` の横のはみ出し 0・コンソールのエラーと警告 0 件・コンパイル済みビュー 311 本の `php -l` がすべて OK
- 写真: `~/.claude/plans/approval-phase3-tasks/5b/photos/`

### 変異（2026-10-13）

- 点検の直しを入れた形（task08 の段）に 85 通りを当てた（決裁のテストと走査テスト 8 本。2 つの写しで半分ずつ）。**S05（関連する決裁No の候補を決裁日の順に並べ直さない）と G12（名前の列の歯止めを外す）がはじめ緑**だった（テストの穴）ので、Task 7・Task 8 にテストを 1 本ずつ足し、その 2 つは足したあとの形で測り直した（Task 9 の表はその値）。K01・K02 は SQLite では等価（ロック付きの読みを普通の読みにする変異。MySQL の 2 本の接続で確かめた）
- 変異を当てたあとに、見た目の直し 3 つ（ブラウザの節）を畳み込んだ。変異の当たる所は変えていない

### 測ったもの

- Excel のメモリ: 紙の行 1,000 件で +36MB・0.75 秒（件数の上限 1,000 件は変えない）
- 使い捨ての MySQL 8.4.11: §0.1 のとおり

## Task 9 の実測記録（2026-10-13・会話 ae55d5c0）

- Step 1: 全件 `OK (4179 tests, 39263 assertions)`（`a72bc514`。assertions が計画の 39261 より 2 多いのは Task 4 の点検の直し〈読めなかった理由を `Log::error` に〉の確かめの分）
- Step 2（1 回目）: `a72bc514` の写し 2 つで 85 個を半分ずつ（`mutate.py`。決裁のテストと走査テスト 1,376 本）。**検出 81・生き残り 2（F05・U03）・SQLite では等価 2（K01・K02）**。カナリアは赤
- ⚠ 計画の段の表は F05・U03 を「検出」としていたが、落ちていたのは時刻で揺れる `PdfTableTest::test_a_long_item_name_or_many_rows_do_not_shrink_the_sheet` 1 本だけだった（Bug #118。テストの読み方の欠陥で、PDF のフッターの時刻により 1 日の約 2.6% の分で赤。`18718723` で直した）。2 つを見分けるテストを足した（`abcbe9ad`: `PaperImportAnalysisTest::test_a_sheet_whose_name_starts_with_four_letters_is_not_read`・`PaperListTest::test_an_import_without_remaining_records_has_no_undo_form`。写しで変異が赤・戻して緑）
- Step 2（流し直し）: 点検の直し（`d9cff2a3`〈確認の画面のあとでシステムの申請が台帳の番号を使ったら確定を断る〉・`abcbe9ad`）が入ったファイルの変異を `abcbe9ad` の写しで流し直した（CANARY F05 I01 I02 C01 C02 C09 C03 C04 C06 C07 C08 C05 V04 V05 V06 U03）。`mutate.py` の C02 の目印を読み直しの前の注記に直し、最後の点検 I-1 の直しを壊す **C09** を足した（全 86 個）

| # | 変異（ファイル） | 結果（1 回目 `a72bc514`） | 落ちたテスト（1 回目） | 流し直し（`abcbe9ad`） | 判定 |
|---|---|---|---|---|---|
| CANARY | カナリア: 紙の決裁の詳細に未定義の変数（`show.blade.php`） | Tests: 1376, Assertions: 9470, Failures: 2. | `PaperDetailTest::test_the_detail_shows_the_record_and_offers_the_edit_to_admins`、`PaperDetailTest::test_the_people_who_may_see_it_can_open_it` | Tests: 1379, Assertions: 9519, Failures: 2.（`PaperDetailTest::test_the_detail_shows_the_record_and_offers_the_edit_to_admins`、`PaperDetailTest::test_the_people_who_may_see_it_can_open_it`） | カナリア（赤が正しい） |
| L01 | 在らない日付（2026-02-30）を繰り上げて出す（`LedgerRow.php`） | Tests: 1376, Assertions: 9447, Failures: 1. | `LedgerExcelTableTest::test_a_broken_contract_date_in_the_revision_leaves_only_that_cell_empty` | — | 検出 |
| L02 | 形の崩れた契約予定日で台帳と Excel が 500（`LedgerRow.php`） | Tests: 1376, Assertions: 9434, Errors: 1. | `LedgerExcelTableTest::test_a_broken_contract_date_in_the_revision_leaves_only_that_cell_empty` | — | 検出 |
| T01 | SQL の決裁No の一意を外す（`2026-10-08-approval-phase5b.sql`） | Tests: 1376, Assertions: 9483, Failures: 1. | `Phase5bTablesTest::test_the_keys_are_the_same_in_the_sql_and_the_migration` | — | 検出 |
| T02 | migration の決裁No の一意を外す（SQL と食い違う）（`2026_10_08_000001_create_approval_paper_tables.php`） | Tests: 1376, Assertions: 9482, Failures: 2. | `Phase5bTablesTest::test_the_keys_are_the_same_in_the_sql_and_the_migration`、`Phase5bTablesTest::test_the_number_and_the_seq_are_unique` | — | 検出 |
| T03 | 紙の決裁のある部門の会社とアルファベットを変えられる（§5.11）（`OrganizationController.php`） | Tests: 1376, Assertions: 9487, Failures: 1. | `PaperDepartmentGuardTest::test_the_code_and_the_company_are_locked_once_paper_records_exist` | — | 検出 |
| T04 | 紙の決裁のある部門を消そうとして外部キーで 500（`OrganizationController.php`） | Tests: 1376, Assertions: 9489, Failures: 1. | `PaperDepartmentGuardTest::test_a_department_with_paper_records_cannot_be_deleted` | — | 検出 |
| T05 | 紙の決裁のある会社の期の始まりの月を変えられる（紙の決裁No の和暦が変わる）（`OrganizationController.php`） | Tests: 1376, Assertions: 9489, Failures: 1. | `PaperDepartmentGuardTest::test_the_fiscal_start_month_is_locked_once_paper_records_exist` | — | 検出 |
| V01 | 社長（全件閲覧者でない）にもすべての紙を見せる（`RequestVisibility.php`） | Tests: 1376, Assertions: 9476, Failures: 2. | `PaperDetailTest::test_the_people_who_may_see_it_can_open_it`、`PaperVisibilityTest::test_who_can_see_a_paper_record` | — | 検出 |
| V02 | 部門長が所属していない自部門の紙を見られない（`RequestVisibility.php`） | Tests: 1376, Assertions: 9456, Failures: 4. | `PaperDetailTest::test_a_related_paper_number_links_to_the_paper`、`PaperDetailTest::test_the_people_who_may_see_it_can_open_it`、`PaperLedgerTest::test_only_visible_papers_are_listed`、`PaperVisibilityTest::test_who_can_see_a_paper_record` | — | 検出 |
| V03 | 所属する人が自部門の紙を見られない（`RequestVisibility.php`） | Tests: 1376, Assertions: 9423, Failures: 10. | `PaperDetailTest::test_a_newer_paper_comes_before_an_older_request`、`PaperDetailTest::test_a_request_comes_before_a_paper_of_the_same_day`、`PaperDetailTest::test_candidates_are_capped_across_requests_and_papers`、`PaperDetailTest::test_the_detail_shows_the_record_and_offers_the_edit_to_admins` ほか 6 本 | — | 検出 |
| D01 | 期の始まりより前の月（1〜4 月）を年度の年にする（`PaperDate.php`） | Tests: 1376, Assertions: 9453, Failures: 11. | `PaperDateTest::test_a_date_is_read`、`PaperImportAnalysisTest::test_a_date_after_today_is_an_error`、`PaperImportAnalysisTest::test_the_two_sided_register_is_read` | — | 検出 |
| D02 | 前の年度の書き残し（7.04.30）を黙って入れる（`PaperDate.php`） | Tests: 1376, Assertions: 9488, Failures: 3. | `PaperDateTest::test_a_wrong_date_is_not_read`、`PaperImportAnalysisTest::test_rows_with_errors_are_left_out_and_the_rest_is_kept` | — | 検出 |
| D03 | ない日付（2.30）を繰り上げて入れる（`PaperDate.php`） | Tests: 1376, Assertions: 9463, Failures: 4. | `PaperDateTest::test_a_wrong_date_is_not_read`、`PaperImportAnalysisTest::test_rows_with_errors_are_left_out_and_the_rest_is_kept`、`PaperImportScreenTest::test_rows_with_errors_and_warnings_are_shown_apart` | — | 検出 |
| D04 | Excel の日付の数のセルを年度の外でも入れる（`PaperDate.php`） | Tests: 1376, Assertions: 9491, Failures: 1. | `PaperDateTest::test_a_wrong_date_is_not_read` | — | 検出 |
| F01 | 受付簿の右の段を読まない（`PaperLedgerFile.php`） | Tests: 1376, Assertions: 9477, Failures: 3. | `PaperImportAnalysisTest::test_the_next_numbers_of_this_year`、`PaperImportAnalysisTest::test_the_two_sided_register_is_read`、`PaperImportAnalysisTest::test_too_many_rows_are_refused` | — | 検出 |
| F02 | 金額の控えのシートを受付簿として読む（`PaperLedgerFile.php`） | Tests: 1376, Assertions: 9361, Errors: 2, Failures: 9. | `PaperImportAnalysisTest::test_amount_rows_without_amounts_are_not_linked`、`PaperImportAnalysisTest::test_amounts_are_linked_by_the_date_and_the_subject`、`PaperImportAnalysisTest::test_existing_numbers_are_kept_and_only_empty_amounts_are_filled`、`PaperImportAnalysisTest::test_two_amounts_for_one_record_are_not_linked` ほか 7 本 | — | 検出 |
| F03 | 粗利益の式（=+F5-G5）を計算せずに式の文字を読む（`PaperLedgerFile.php`） | Tests: 1376, Assertions: 9386, Errors: 1, Failures: 8. | `PaperImportAnalysisTest::test_amounts_are_linked_by_the_date_and_the_subject`、`PaperImportAnalysisTest::test_existing_numbers_are_kept_and_only_empty_amounts_are_filled`、`PaperImportAnalysisTest::test_two_amounts_for_one_record_are_not_linked`、`PaperImportScreenTest::test_an_ambiguous_amount_row_is_chosen_on_the_confirmation` ほか 5 本 | — | 検出 |
| F04 | 行の上限を見ない（壊れたファイルで時間とメモリを使い切る）（`PaperLedgerFile.php`） | Tests: 1376, Assertions: 9491, Failures: 1. | `PaperImportAnalysisTest::test_too_many_rows_are_refused` | — | 検出 |
| F06 | 金額を書く前の控えの行の粗利益の式の 0 を読む（点検 I-2）（`PaperLedgerFile.php`） | Tests: 1376, Assertions: 9467, Failures: 2. | `PaperImportAnalysisTest::test_amount_rows_without_amounts_are_not_linked`、`PaperImportScreenTest::test_rows_with_errors_and_warnings_are_shown_apart` | — | 検出 |
| F07 | 大きなファイルを読む前に断らない（本番のメモリを使い切る。点検 I-3）（`PaperLedgerFile.php`） | Tests: 1376, Assertions: 9491, Failures: 1. | `PaperImportAnalysisTest::test_a_huge_sheet_is_refused_before_it_is_read` | — | 検出 |
| F05 | 英字が 4 文字以上続く名前（MEMO）も部門の記号にする（`PaperLedgerFile.php`） | OK (1376 tests, 9491 assertions) | — | Tests: 1379, Assertions: 9530, Failures: 1.（`PaperImportAnalysisTest::test_a_sheet_whose_name_starts_with_four_letters_is_not_read`） | **当初生き残り → テストを足して検出**（`abcbe9ad`） |
| A01 | 同じファイルの中の同じ番号を両方とも入れようとする（`PaperImportAnalysis.php`） | Tests: 1376, Assertions: 9486, Failures: 1. | `PaperImportAnalysisTest::test_rows_with_errors_are_left_out_and_the_rest_is_kept` | — | 検出 |
| A02 | システムの申請の番号と同じ紙の番号を入れようとする（D7）（`PaperImportAnalysis.php`） | Tests: 1376, Assertions: 9486, Failures: 1. | `PaperImportAnalysisTest::test_rows_with_errors_are_left_out_and_the_rest_is_kept` | — | 検出 |
| A03 | 金額のある既存の記録の金額を控えで上書きする（D6）（`PaperImportAnalysis.php`） | Tests: 1376, Assertions: 9484, Failures: 2. | `PaperImportAnalysisTest::test_existing_numbers_are_kept_and_only_empty_amounts_are_filled`、`PaperImportScreenTest::test_nothing_left_to_import_is_refused` | — | 検出 |
| A04 | 2 つに当たる控えを自動で結び付ける（`PaperImportAnalysis.php`） | Tests: 1376, Assertions: 9434, Failures: 5. | `PaperImportAnalysisTest::test_amounts_are_linked_by_the_date_and_the_subject`、`PaperImportAnalysisTest::test_two_amounts_for_one_record_are_not_linked`、`PaperImportScreenTest::test_an_ambiguous_amount_row_is_chosen_on_the_confirmation`、`PaperImportScreenTest::test_an_amount_row_left_unlinked_can_be_linked_on_a_later_upload` ほか 1 本 | — | 検出 |
| A05 | 件名の空白・全角半角の違いで結び付かない（`PaperImportAnalysis.php`） | Tests: 1376, Assertions: 9468, Failures: 2. | `PaperImportAnalysisTest::test_amounts_are_linked_by_the_date_and_the_subject`、`PaperImportAnalysisTest::test_two_amounts_for_one_record_are_not_linked` | — | 検出 |
| A06 | 2 つの控えを同じ記録に結び付けても誤りにしない（`PaperImportAnalysis.php`） | Tests: 1376, Assertions: 9476, Failures: 2. | `PaperImportAnalysisTest::test_two_amounts_for_one_record_are_not_linked`、`PaperImportScreenTest::test_choosing_a_record_twice_shows_the_confirmation_again` | — | 検出 |
| A08 | 金額の無い控えの行を結び付ける（「補った」と数え、あとで本当の金額を補えなくなる。点検 I-2）（`PaperImportAnalysis.php`） | Tests: 1376, Assertions: 9467, Failures: 2. | `PaperImportAnalysisTest::test_amount_rows_without_amounts_are_not_linked`、`PaperImportScreenTest::test_rows_with_errors_and_warnings_are_shown_apart` | — | 検出 |
| A09 | 今日より先の日付の行を取り込む（点検 M-2）（`PaperImportAnalysis.php`） | Tests: 1376, Assertions: 9490, Failures: 1. | `PaperImportAnalysisTest::test_a_date_after_today_is_an_error` | — | 検出 |
| A07 | 過去の年度の取り込みでも次の番号が進むと見せる（`PaperImportAnalysis.php`） | Tests: 1376, Assertions: 9491, Failures: 1. | `PaperImportAnalysisTest::test_the_next_numbers_of_this_year` | — | 検出 |
| N01 | 次の番号を戻す（紙の番号が今の次の番号より小さいとき。D7）（`ApprovalNumber.php`） | Tests: 1376, Assertions: 9489, Failures: 2. | `PaperImportScreenTest::test_the_next_number_in_the_notice_is_the_one_actually_set`、`PaperNumberTest::test_a_paper_number_of_this_year_moves_the_next_number_forward_only` | — | 検出 |
| N02 | 過去の年度の紙の番号で今年度の次の番号を進める（§5.11）（`ApprovalNumber.php`） | Tests: 1376, Assertions: 9490, Failures: 2. | `PaperImportScreenTest::test_a_past_year_does_not_touch_the_next_number`、`PaperNumberTest::test_a_paper_number_of_a_past_year_does_not_touch_the_numbering` | — | 検出 |
| N03 | 開始番号を今年度の紙の番号以下にできる（`ApprovalNumber.php`） | Tests: 1376, Assertions: 9488, Failures: 1. | `PaperNumberTest::test_the_start_number_cannot_go_under_this_years_paper_numbers` | — | 検出 |
| I01 | 確認のあとでシステムの申請が使った番号でも取り込む（`PaperImporter.php`） | Tests: 1376, Assertions: 9483, Errors: 1, Failures: 1. | `PaperImportScreenTest::test_a_system_number_taken_during_the_confirmation_is_refused`、`PaperNumberTest::test_a_system_number_taken_after_the_confirmation_stops_the_import` | Tests: 1379, Assertions: 9532, Errors: 1, Failures: 1.（`PaperImportScreenTest::test_a_system_number_taken_during_the_confirmation_is_refused`、`PaperNumberTest::test_a_system_number_taken_after_the_confirmation_stops_the_import`） | 検出 |
| I02 | 確定の瞬間に手で入れた金額を上書きする（`PaperImporter.php`） | Tests: 1376, Assertions: 9490, Failures: 1. | `PaperImportScreenTest::test_amounts_typed_during_the_confirmation_are_not_overwritten` | Tests: 1379, Assertions: 9539, Failures: 1.（`PaperImportScreenTest::test_amounts_typed_during_the_confirmation_are_not_overwritten`） | 検出 |
| I03 | 紙の番号を足しても次の番号を進めない（取り込み・1 件ずつ）（`ApprovalNumber.php`） | Tests: 1376, Assertions: 9468, Failures: 5. | `PaperImportScreenTest::test_a_system_number_taken_during_the_confirmation_is_refused`、`PaperImportScreenTest::test_confirming_imports_the_records_and_advances_the_next_number`、`PaperImportScreenTest::test_the_next_number_in_the_notice_is_the_one_actually_set`、`PaperRecordCrudTest::test_a_paper_record_is_registered_from_the_form` ほか 1 本 | — | 検出 |
| K01 | ロックのあとのシステムの番号の確かめを普通の読みにする（MySQL の REPEATABLE READ で古い中身を読む。点検 I-1）（`ApprovalNumber.php`） | OK (1376 tests, 9491 assertions) | — | — | **SQLite では等価**（計画の段に MySQL の 2 本の接続で確かめた） |
| K02 | 開始番号の検査の紙の番号を普通の読みにする（点検 I-1）（`ApprovalNumber.php`） | OK (1376 tests, 9491 assertions) | — | — | **SQLite では等価**（計画の段に MySQL の 2 本の接続で確かめた） |
| C01 | 確定の二度押しで二重に取り込む（`PaperImportController.php`） | Tests: 1376, Assertions: 9488, Failures: 2. | `ImportControllerOneTimeKeyScanTest::test_every_import_controller_claims_a_one_time_key`、`PaperImportScreenTest::test_a_second_submit_is_refused_without_writing` | Tests: 1379, Assertions: 9537, Failures: 2.（`ImportControllerOneTimeKeyScanTest::test_every_import_controller_claims_a_one_time_key`、`PaperImportScreenTest::test_a_second_submit_is_refused_without_writing`） | 検出 |
| C02 | 確定で選んだ結び付けを使わない（`PaperImportController.php`） | Tests: 1376, Assertions: 9474, Failures: 3. | `PaperImportScreenTest::test_an_ambiguous_amount_row_is_chosen_on_the_confirmation`、`PaperImportScreenTest::test_an_amount_row_left_unlinked_can_be_linked_on_a_later_upload`、`PaperImportScreenTest::test_choosing_a_record_twice_shows_the_confirmation_again` | Tests: 1379, Assertions: 9523, Failures: 3.（`PaperImportScreenTest::test_an_ambiguous_amount_row_is_chosen_on_the_confirmation`、`PaperImportScreenTest::test_an_amount_row_left_unlinked_can_be_linked_on_a_later_upload`、`PaperImportScreenTest::test_choosing_a_record_twice_shows_the_confirmation_again`） | 検出 |
| C03 | 結び付けの誤りがあっても取り込む（`PaperImportController.php`） | Tests: 1376, Assertions: 9478, Failures: 1. | `PaperImportScreenTest::test_choosing_a_record_twice_shows_the_confirmation_again` | Tests: 1379, Assertions: 9527, Failures: 1.（`PaperImportScreenTest::test_choosing_a_record_twice_shows_the_confirmation_again`） | 検出 |
| C04 | 確認の画面からのアップロードし直しの断りが preview へ戻って 405（Bug #64）（`PaperImportController.php`） | Tests: 1376, Assertions: 9481, Failures: 2. | `ImportControllerValidationRedirectScanTest::test_import_controllers_send_every_validation_failure_to_a_fixed_page`、`PaperImportScreenTest::test_a_failed_upload_from_the_confirmation_goes_back_to_the_import_screen` | Tests: 1379, Assertions: 9530, Failures: 2.（`ImportControllerValidationRedirectScanTest::test_import_controllers_send_every_validation_failure_to_a_fixed_page`、`PaperImportScreenTest::test_a_failed_upload_from_the_confirmation_goes_back_to_the_import_screen`） | 検出 |
| C06 | 確定で実際に進めた次の番号を知らせない（点検 M-6）（`PaperImportController.php`） | Tests: 1376, Assertions: 9481, Failures: 2. | `PaperImportScreenTest::test_confirming_imports_the_records_and_advances_the_next_number`、`PaperImportScreenTest::test_the_next_number_in_the_notice_is_the_one_actually_set` | Tests: 1379, Assertions: 9530, Failures: 2.（`PaperImportScreenTest::test_confirming_imports_the_records_and_advances_the_next_number`、`PaperImportScreenTest::test_the_next_number_in_the_notice_is_the_one_actually_set`） | 検出 |
| C07 | 取り込みの確定で一意の索引の競合が 500 になる（点検 I-4）（`PaperImportController.php`） | Tests: 1376, Assertions: 9487, Failures: 1. | `PaperImportScreenTest::test_a_paper_number_taken_during_the_confirmation_is_refused` | Tests: 1379, Assertions: 9536, Failures: 1.（`PaperImportScreenTest::test_a_paper_number_taken_during_the_confirmation_is_refused`） | 検出 |
| C08 | 壊れた hidden のファイルを読もうとする（点検 I-4）（`PaperImportController.php`） | Tests: 1376, Assertions: 9489, Failures: 1. | `PaperImportScreenTest::test_a_broken_file_in_the_confirmation_is_refused` | Tests: 1379, Assertions: 9538, Failures: 1.（`PaperImportScreenTest::test_a_broken_file_in_the_confirmation_is_refused`） | 検出 |
| C05 | 選択肢に無い年度（先の年度）で取り込める（`PaperImportController.php`） | Tests: 1376, Assertions: 9488, Failures: 1. | `PaperImportScreenTest::test_a_failed_upload_from_the_confirmation_goes_back_to_the_import_screen` | Tests: 1379, Assertions: 9537, Failures: 1.（`PaperImportScreenTest::test_a_failed_upload_from_the_confirmation_goes_back_to_the_import_screen`） | 検出 |
| V04 | 選べる控えだけの確認の画面に確定のフォームを描かない（`_import_preview.blade.php`） | Tests: 1376, Assertions: 9482, Failures: 1. | `PaperImportScreenTest::test_an_amount_row_left_unlinked_can_be_linked_on_a_later_upload` | Tests: 1379, Assertions: 9531, Failures: 1.（`PaperImportScreenTest::test_an_amount_row_left_unlinked_can_be_linked_on_a_later_upload`） | 検出 |
| V05 | 選び直す画面で選んだ結び付け先を残さない（`_import_amounts.blade.php`） | Tests: 1376, Assertions: 9489, Failures: 1. | `PaperImportScreenTest::test_choosing_a_record_twice_shows_the_confirmation_again` | Tests: 1379, Assertions: 9538, Failures: 1.（`PaperImportScreenTest::test_choosing_a_record_twice_shows_the_confirmation_again`） | 検出 |
| V06 | 結び付けの select を確定のフォームの外に出す（選んでも送られない）（`_import_preview.blade.php`） | Tests: 1376, Assertions: 9412, Failures: 5. | `PaperImportScreenTest::test_an_ambiguous_amount_row_is_chosen_on_the_confirmation`、`PaperImportScreenTest::test_an_amount_row_left_unlinked_can_be_linked_on_a_later_upload`、`PaperImportScreenTest::test_an_upload_shows_the_confirmation_without_writing`、`PaperImportScreenTest::test_choosing_a_record_twice_shows_the_confirmation_again` ほか 1 本 | Tests: 1379, Assertions: 9461, Failures: 5.（`PaperImportScreenTest::test_an_ambiguous_amount_row_is_chosen_on_the_confirmation`、`PaperImportScreenTest::test_an_amount_row_left_unlinked_can_be_linked_on_a_later_upload`、`PaperImportScreenTest::test_an_upload_shows_the_confirmation_without_writing`、`PaperImportScreenTest::test_choosing_a_record_twice_shows_the_confirmation_again` ほか 1 本） | 検出 |
| V07 | ファイル名の「08年度」を平成として選ぶ（`import.blade.php`） | Tests: 1376, Assertions: 9491, Failures: 1. | `PaperImportScreenTest::test_the_year_is_picked_from_the_file_name` | — | 検出 |
| V08 | 今の年度を先に選ばない（いちばん古い年度にならない限り同じ＝先頭は今の年度なので生き残りうる）（`import.blade.php`） | Tests: 1376, Assertions: 9486, Failures: 1. | `PaperImportScreenTest::test_the_import_screen_offers_the_years_and_the_list_links_to_it` | — | 検出 |
| R01 | システムの申請の番号と同じ紙の決裁を 1 件ずつ登録できる（`PaperRecordController.php`） | Tests: 1376, Assertions: 9456, Failures: 1. | `PaperRecordCrudTest::test_a_number_already_in_use_is_refused` | — | 検出 |
| R02 | 直すときに自分の番号と重なると断る（番号を変えずに直せない）（`PaperRecordController.php`） | Tests: 1376, Assertions: 9486, Failures: 2. | `PaperRecordCrudTest::test_a_record_is_edited_and_only_the_changes_are_logged`、`PaperRecordCrudTest::test_the_number_can_be_changed_but_not_onto_another_record` | — | 検出 |
| R03 | 1 件ずつの登録で次の番号を進めない（`PaperRecordController.php`） | Tests: 1376, Assertions: 9482, Failures: 2. | `PaperRecordCrudTest::test_a_paper_record_is_registered_from_the_form`、`PaperRecordCrudTest::test_the_number_can_be_changed_but_not_onto_another_record` | — | 検出 |
| R04 | 直した人を残さない（取り消しの「手で直した」の数が合わない）（`PaperRecordController.php`） | Tests: 1376, Assertions: 9487, Failures: 1. | `PaperRecordCrudTest::test_a_record_is_edited_and_only_the_changes_are_logged` | — | 検出 |
| R05 | 直していない決裁日を「変わった」と記録する（`PaperRecordController.php`） | Tests: 1376, Assertions: 9489, Failures: 1. | `PaperRecordCrudTest::test_a_record_is_edited_and_only_the_changes_are_logged` | — | 検出 |
| R06 | 年度の期間の外の決裁日を入れられる（次の番号を進められずに採番と重なる。点検 M-2）（`PaperRecordController.php`） | Tests: 1376, Assertions: 9471, Failures: 1. | `PaperRecordCrudTest::test_a_decided_date_outside_the_year_or_after_today_is_refused` | — | 検出 |
| R07 | 今日より先の決裁日を 1 件ずつ入れられる（点検 M-2）（`PaperRecordController.php`） | Tests: 1376, Assertions: 9488, Failures: 1. | `PaperRecordCrudTest::test_a_decided_date_outside_the_year_or_after_today_is_refused` | — | 検出 |
| R08 | 1 件ずつの登録で一意の索引の競合が 500 になる（点検 I-4）（`PaperRecordController.php`） | Tests: 1376, Assertions: 9487, Failures: 1. | `PaperRecordCrudTest::test_a_number_taken_while_saving_is_refused_with_the_input_kept` | — | 検出 |
| R09 | 保存のフォームの二度押し止めを外す（点検 M-1）（`form.blade.php`） | Tests: 1376, Assertions: 9487, Failures: 1. | `PaperRecordCrudTest::test_the_save_form_is_sent_only_once` | — | 検出 |
| U01 | 見せた件数と今の件数が違っても消す（`PaperImportController.php`） | Tests: 1376, Assertions: 9483, Failures: 1. | `PaperRecordCrudTest::test_an_undo_is_refused_when_the_records_have_changed_or_it_is_done` | — | 検出 |
| U04 | 見せたあとで手で直した記録が増えても消す（点検 M-4）（`PaperImportController.php`） | Tests: 1376, Assertions: 9480, Failures: 1. | `PaperRecordCrudTest::test_an_undo_is_refused_when_the_records_have_changed_or_it_is_done` | — | 検出 |
| U02 | 取り消した回をもう一度取り消せる（`PaperImportController.php`） | Tests: 1376, Assertions: 9490, Failures: 1. | `PaperRecordCrudTest::test_an_undo_is_refused_when_the_records_have_changed_or_it_is_done` | — | 検出 |
| U03 | 記録の残っていない回にも取り消しのボタンを出す（`index.blade.php`） | OK (1376 tests, 9491 assertions) | — | Tests: 1379, Assertions: 9534, Failures: 1.（`PaperListTest::test_an_import_without_remaining_records_has_no_undo_form`） | **当初生き残り → テストを足して検出**（`abcbe9ad`） |
| S01 | 見られない人に紙の決裁の詳細を見せる（`PaperDetailController.php`） | Tests: 1376, Assertions: 9489, Failures: 1. | `PaperDetailTest::test_the_people_who_may_see_it_can_open_it` | — | 検出 |
| S02 | 管理者でない人に「直す」を出す（`show.blade.php`） | Tests: 1376, Assertions: 9489, Failures: 1. | `PaperDetailTest::test_the_detail_shows_the_record_and_offers_the_edit_to_admins` | — | 検出 |
| S03 | 見られない紙の決裁も関連する決裁No のリンクにする（`RequestController.php`） | Tests: 1376, Assertions: 9490, Failures: 1. | `PaperDetailTest::test_a_related_paper_number_links_to_the_paper` | — | 検出 |
| S04 | 見られない紙の決裁を候補に出す（`RelatedNumberController.php`） | Tests: 1376, Assertions: 9488, Failures: 1. | `PaperDetailTest::test_visible_papers_are_offered_as_candidates` | — | 検出 |
| S05 | 候補を決裁日の順に並べない（申請が多いと紙が出ない）（`RelatedNumberController.php`） | Tests: 1376, Assertions: 9491, Failures: 1. | `PaperDetailTest::test_a_newer_paper_comes_before_an_older_request` | — | 検出 |
| S06 | 紙の決裁の詳細のルートが無い（`approval.php`） | Tests: 1376, Assertions: 9454, Errors: 4, Failures: 4. | `ApprovalAdminGateTest::test_every_approvals_route_is_classified`、`LaunchGateTest::test_every_approval_route_is_classified`、`PaperDetailTest::test_a_related_paper_number_links_to_the_paper`、`PaperDetailTest::test_it_is_closed_before_launch` ほか 4 本 | — | 検出 |
| G01 | 申請の種類を選んでも紙が出る（`LedgerFilter.php`） | Tests: 1376, Assertions: 9484, Failures: 1. | `PaperLedgerTest::test_the_filters_apply_to_papers` | — | 検出 |
| G02 | 判断「不明」でも申請が出る（`LedgerFilter.php`） | Tests: 1376, Assertions: 9489, Failures: 1. | `PaperLedgerTest::test_the_filters_apply_to_papers` | — | 検出 |
| G03 | 「進行中」に紙が出る（`LedgerFilter.php`） | Tests: 1376, Assertions: 9486, Failures: 1. | `PaperLedgerTest::test_the_filters_apply_to_papers` | — | 検出 |
| G04 | キーワードで紙の備考を探さない（`Ledger.php`） | Tests: 1376, Assertions: 9483, Failures: 1. | `PaperLedgerTest::test_the_filters_apply_to_papers` | — | 検出 |
| G05 | 決裁日の「まで」の日の紙が外れる（SQLite）（`Ledger.php`） | Tests: 1376, Assertions: 9481, Failures: 1. | `PaperLedgerTest::test_the_filters_apply_to_papers` | — | 検出 |
| G06 | 台帳に見られない紙の決裁を出す（`Ledger.php`） | Tests: 1376, Assertions: 9488, Failures: 1. | `PaperLedgerTest::test_only_visible_papers_are_listed` | — | 検出 |
| G07 | 申請者で紙を絞らない（`Ledger.php`） | Tests: 1376, Assertions: 9482, Failures: 1. | `PaperLedgerTest::test_the_filters_apply_to_papers` | — | 検出 |
| G08 | 年度の選択肢が紙のいちばん古い年度まで届かない（`Ledger.php`） | Tests: 1376, Assertions: 9490, Failures: 1. | `PaperLedgerTest::test_the_years_reach_back_to_the_oldest_paper` | — | 検出 |
| G09 | 申請を全部並べてから紙を並べる（決裁日の順に混ぜない）（`Ledger.php`） | Tests: 1376, Assertions: 9491, Failures: 1. | `PaperLedgerTest::test_papers_are_sorted_together_with_requests` | — | 検出 |
| G10 | 紙の行から申請の詳細（同じ id の別の申請）を開く（`LedgerRow.php`） | Tests: 1376, Assertions: 9485, Failures: 2. | `PaperLedgerTest::test_a_paper_row_reads_as_a_paper`、`PaperLedgerTest::test_the_screen_shows_papers_with_the_new_filters` | — | 検出 |
| G11 | Excel に区分の列が無い（19 列のまま）（`LedgerExcel.php`） | Tests: 1376, Assertions: 9481, Failures: 4. | `LedgerExcelTableTest::test_the_four_columns_of_a_table_request`、`LedgerExcelTest::test_the_columns_and_the_values_of_a_row`、`LedgerExcelTest::test_the_heading_is_frozen_and_has_filter_buttons`、`PaperLedgerTest::test_the_excel_has_the_paper_rows_and_logs_the_new_filters` | — | 検出 |
| G12 | 名前の列に決まっていない列（SQL に埋め込む）を受け取る（`NameSearch.php`） | Tests: 1376, Assertions: 9491, Failures: 1. | `PaperLedgerTest::test_the_name_search_takes_only_known_columns` | — | 検出 |
| G13 | 判断「不明」で絞った画面で選択が外れて見える（`index.blade.php`） | Tests: 1376, Assertions: 9487, Failures: 1. | `PaperLedgerTest::test_the_screen_shows_papers_with_the_new_filters` | — | 検出 |
| G14 | 出力の記録に区分を残さない（`LedgerFilter.php`） | Tests: 1376, Assertions: 9489, Errors: 1, Failures: 2. | `LedgerExcelTest::test_each_download_is_recorded_with_the_conditions_and_the_count`、`LedgerFilterTest::test_the_log_keeps_every_key_in_the_same_order`、`PaperLedgerTest::test_the_excel_has_the_paper_rows_and_logs_the_new_filters` | — | 検出 |
| C09 | 確認の画面のあとでシステムの申請が使った番号を、確定の読み直しで黙って落とす（最後の点検 I-1）（`PaperImportController.php`） | —（`abcbe9ad` で足した） | — | Tests: 1379, Assertions: 9535, Failures: 1.（`PaperImportScreenTest::test_a_system_number_taken_while_the_confirmation_is_open_is_refused`） | 検出 |
