# 決裁申請 段階4（4b: 決裁台帳・Excel 出力・Excel の出力の記録）実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 段階4 の後半（4b）— 見られる人が、見られる申請だけを紙の受付簿の代わりに一覧できる決裁台帳（⑤。年度・部門・種類・申請者・決裁日の期間・状態・判断・キーワードで絞り込み、スマホではカード）を作り、絞り込んだ結果を Excel に全件出せて、出したことを「誰が・いつ・どんな条件で・何件」と記録し、使い始めるまで誰にも見えないまま本番へ出す。

**Architecture:** 台帳の問い合わせは `Ledger` の 1 か所（見られる範囲は `RequestVisibility::apply`・下書きは出さない）。中身（部門・種類・件名・本文）は、申請者本人以外には**最後に提出した控え**で当て（D19）、直しかけが残りうる差戻し中・取り下げの他人の申請だけを `approval_revisions` の今の回の JSON で当てる（関連する決裁No の候補と同じ考え）。並びは日本の暦の日で比べるので、軽い列だけを読んで PHP で並べた id の列を作り（`Ledger::sortedIds`）、画面は今のページの分・Excel は 200 件ずつ行（`LedgerRow`）を作る。条件は `LedgerFilter`（GET を読み、読めない値は外して知らせる）。Excel は `LedgerExcel`（PhpSpreadsheet。文字はすべて文字として入れる）。出力の記録は 3 つの出力とも `DownloadLogger` を通す（4a からの持ち越し）。

**Tech Stack:** Laravel 12（12.69.3）/ PHP 8.3（本番 8.3.32・手元 8.3.35）/ MySQL 8（本番 8.0.40）・SQLite（テスト）/ Blade + Alpine.js 3 + Tailwind v4 / PHPUnit 11 / **phpoffice/phpspreadsheet 5.9.0（既にある。書き出しに使うのは初めて。部品の追加・更新は無い）**

**Spec:** 設計書 @docs/superpowers/specs/2026-10-03-approval-phase4-design.md（この計画は §1.3・§4・§5.2・§5.3・§5.7〜§5.10・§6・§7 の **4b** の部分。§9 の「計画で決めること」の 7〜10 は §0 で決めた）。要件定義書 @docs/決裁申請_要件定義書_v1.md（v1.13 の 7 章・10 章・13 章の ⑤・14.2・14.4・14.5）。4a の計画 @docs/superpowers/plans/2026-10-03-approval-phase4a.md（§0.1 作り方・Task 10 の本番反映の作法は 4b でもそのまま効く）。

## Global Constraints

- 使い始める前（`approval_settings.launched_at` が空）は、台帳と Excel のルートは開かない（`approval.launched` のグループに置く。設計書 §5.2）。入口（サイドバーとホーム）も使い始めるまで出さない
- 台帳と Excel に出すのは**見られる申請だけ**（`RequestVisibility::apply`。規則は 1 か所・要件 7 章）・**下書きは出さない**（申請者本人にも）
- 中身（件名・申請の種類・申請部門・金額・実施時期・関連する決裁No）の表示と、キーワード・部門・種類・年度（番号の無い申請）の絞り込みは、**申請者本人以外には最後に提出した中身**（D19。差戻し中の直しかけを出さない・検索に掛けない）。控えが無いときに今の中身へ落とさない（分からないときは見せない）
- 年度は、決裁No の付いた申請はその番号の年度、付いていない申請は発信日（最後の提出）の年度で、どちらも申請部門の会社の期（D20）。日付はすべて**日本の暦の日**（UTC で保存した日時を日本時間に直してから日を見る。Bug #61）
- Excel は台帳と同じ条件・同じ並びで全件。日付は Excel の日付・金額は数・**文字の欄はすべて文字として入れる**（「=」などで始まっても式にしない）。件数が上限（`config('approval.ledger.excel_limit')`＝1,000）を超えたら作らずに台帳へ戻して知らせる（D24）
- 出力の記録は、添付・PDF・Excel のどれも `DownloadLogger` だけが書く（`kind` は `ApprovalDownloadLog::KIND_*`）。Excel は申請の欄が空で、絞り込みの条件（`LedgerFilter::toLog()`）と件数を控える（D25）。作らなかったとき（上限を超えた）は記録しない
- 文字はすべてエスケープする（`{{ }}`）。台帳の絞り込みは GET で、`validate()` で前の画面へ戻さない（戻り先がこの画面そのものになる）
- テストは worktree で `APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`。main repo では作業もテストもしない。worktree に `.env` を作らない（`.env*` は読まない・grep しない）
- コミットは Conventional Commits・日本語の件名（72 文字以内・句点なし）・1 コミット 1 関心事・本文の最後に空行を 1 つあけて `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`。`--no-verify`・`--amend`・`git reset` でのやり直し・`git stash` は使わない。push は利用者が指示したときだけ

## Review Focus

設計書が触れていないが、使う人がいちばん出会いそうな場面（どの場面のテストも見ていなかったもの）。計画を書く段階で 5 つとも試作にテストを足し、コードを 1 か所壊すとそのテストが落ちることを確かめた（変異。Task 5 の表の ID）。

1. **差戻し中に申請者が件名・部門・種類を直しかけている申請** → ほかの人の台帳・検索・Excel には最後に提出した中身で出て、直しかけの言葉では当たらない。申請者本人には今の中身で出る（Task 2 の `test_others_search_what_was_last_submitted_while_it_is_returned`・`test_others_see_what_was_last_submitted_and_the_applicant_sees_the_current_content`・Task 4 の `test_others_get_what_was_last_submitted`。変異 Q14・Q15・Q16・R01・R02）
2. **日本時間の 0:00 の前後に決裁・提出した申請** → 決裁日の期間・並び・年度・Excel の日付は日本の暦の日（UTC の 10/4 15:00 は 10/5）（Task 2 の `test_the_decided_dates_are_days_of_the_japanese_calendar`・`test_the_order_is_by_day_then_number_then_submission`・`test_the_year_of_an_unnumbered_request_follows_the_company_of_its_department`・Task 4 の `test_dates_are_excel_dates_of_the_japanese_day_and_the_amount_is_a_number`。変異 Q08〜Q10・Q21・Q26・X02）
3. **「=」「+」「@」で始まる件名・氏名・実施時期** → Excel でも打ったとおりの文字で、式として働かない（Task 4 の `test_text_that_looks_like_a_formula_stays_text`。変異 X01）
4. **台帳が多すぎる・多い**（1,000 件を超える・ちょうど・200 件を超える）→ 超えたら作らずに「絞り込んでから出してください」と出し記録しない／ちょうどなら出す／200 件ずつ読んでも全件を書く（Task 4 の `test_too_many_requests_are_not_made_and_not_recorded`・`test_more_than_one_batch_of_rows_is_written`・`test_the_ledger_offers_the_excel_only_within_the_limit`・`test_the_default_limit_is_a_thousand`。変異 X07・X09・X10・X17・V13）
5. **プルダウンを変えて（すぐ送る）からブラウザの「戻る」** → 絞り込みの欄が表と Excel のリンクに使った条件へ戻る（Bug #65 と同じ。Task 3 の `test_the_filter_form_is_reset_whenever_the_page_is_shown`。変異 V05）

---

## 0. 計画を書く段階で決めたこと（設計書 §9 の宿題のうち 4b の分）

### 0.1 作り方（試作を先に作って確かめた）

- この計画のコードは、scratchpad の試作（`13.x` = `99d5f1ed` の写し。4a を本番に出したあとで、別の会話の不動産のテストが進んだもの。決裁のファイルは 4a のまま）で Task ごとにコミットし、**各段で全件のテストが緑**であることと、**テストだけを先に入れると何が落ちるか**を測ってから書き写したもの（2b・3a・3b・4a と同じ作り方）。各 Step の Expected の本数と落ちるテストの名前は実測（2026-10-06。はじめ 2026-10-05 に作り、別の担当〈opus〉の点検の指摘を直して測り直した）
- 同じ中身の差分のファイルを `~/.claude/plans/approval-phase3-tasks/4b/patches/`（`0001`〜`0004`。この計画のコミット 1 つに 1 ファイル）に置いた。担当は計画のコードを打ち直さず、この差分を当ててよい（**テストを先に** `git apply --include='tests/*'`、実装を**あとで** `git apply --exclude='tests/*'`）。当てたら `git diff --stat` が各 Task の「差分の大きさ」と同じことを確かめる。**差分のファイルと計画のコードが食い違ったら、計画を正として止まり、報告する**
- 各 Task のコードの塊のうち、新しいファイルは全文、既存のファイルは差分（`diff` の形）で示す
- **使い捨ての MySQL 8.4.11 で確かめた**（2026-10-05・ポート 34419・確かめたあと止めた。常駐の 3306 はそのまま）: migration だけで作った `approval_download_logs` と、4b の migration を戻して（`down()`）から本番と同じ SQL ファイルで変えた表の `SHOW CREATE TABLE` が完全に一致・外部キーのある `request_id` を `MODIFY` で空にできる・**決裁のテスト全体（`tests/Feature/Approval`）が MySQL でも通る**（台帳の JSON の中を見る絞り込みを含む。§0.3）
- 進め方は 4a と同じ（担当の決まりは `~/.claude/plans/approval-phase3-tasks/4b/rules.md`。4a の `rules.md` から、mPDF とフォントの手順を外したもの）: 実装の担当は 1 度に 1 人（Task ごと）・コードの点検は別の担当が WT の HEAD の写しで行う・変異の確かめは写しで・Task 8（本番反映）は担当に任せず親の会話が行う

### 0.2 表と本番の SQL・出力の記録（§5.3・§5.7・§5.10・§9 の 9・D25）

- `approval_download_logs`: **`request_id` を NULL 可に**（Excel の行は申請が無い。外部キーは残す）・**`filters`**（JSON・NULL 可・`user_agent` の後。絞り込みの条件）・**`request_count`**（INT UNSIGNED・NULL 可。件数）。本番の SQL は `database/sql/2026-10-05-approval-phase4b.sql`（`ALTER TABLE` 1 文）、テストの鏡は `database/migrations/2026_10_05_000001_change_approval_download_logs_for_excel.php`（SQLite の `change()` は表を作り直す。外部キーと索引が残ることを `Phase4bTablesTest` が見る）。`Phase2TablesTest` に、あとの段階が足した列（`filters`・`request_count`）と NULL 可に変えた列（`request_id`）を登録する
- 本番は使い始める前で `approval_download_logs` は 0 行（2026-10-05 に読み取った）＝埋め直す SQL は要らない
- **出力の記録の書き方を 1 か所に**（4a の BACKLOG の持ち越し）: `App\Support\Approval\DownloadLogger` の `attachment()`・`pdf()`・`excel()` が、誰が（`$request->user()`）・IP・端末（255 文字まで）を同じ形で書く。`kind` は `ApprovalDownloadLog::KIND_ATTACHMENT`（`attachment`）・`KIND_PDF`（`pdf`）・`KIND_EXCEL`（`excel`）。添付と PDF の画面は振る舞いを変えずにこれを呼ぶ（§0.7）。ほかの場所が `ApprovalDownloadLog::create()` などを直接呼ばないことを `DownloadLoggerTest` が走査で見る
- **条件の JSON の形**（§9 の 9）: `LedgerFilter::toLog()` の 9 項目 `year`（期の始まりの年）・`department_id`・`type_id`・`applicant`・`decided_from`・`decided_to`（`YYYY-MM-DD`）・`status`（`numbered`/`progress`/`withdrawn`/`all`）・`decision`（`approve`/`conditional`/`reject`）・`keyword`。使っていない条件も null で必ず入れる。⚠ MySQL の JSON はキーを並べ替えて返す（キーの長さの順。`RequestSnapshot` の注意）ので、読むときもテストでも並びに頼らない（MySQL でテストを流して分かった）

### 0.3 台帳の問い合わせ（§5.8・§9 の 7・D19〜D22）

- `App\Support\Approval\Ledger::query(User, LedgerFilter): Builder` — `RequestVisibility::apply` ＋ 下書きを除く ＋ 条件。並びは付けない
- **中身の出し分け（D19）**: 中身の条件（部門・種類・キーワード・番号の無い申請の年度）は、`whereContent()` が 2 つの枝で当てる。①申請者本人の申請か、差戻し中・取り下げでない申請 → 申請の行の列（`department_id`・`type_id`・`subject`・`body`）②他人の差戻し中・取り下げの申請 → `approval_revisions` の今の回（`round` が同じ）の `snapshot->department->id`・`snapshot->type->id`・`snapshot->subject`・`snapshot->body`（Laravel の JSON の書き方。MySQL は `json_unquote(json_extract(…))`・SQLite は `json_extract(…)`）。ほかの状態は中身を直せないので、今の中身と控えが同じ（`RelatedNumberController` と同じ考え）。「直しかけが残りうる状態」は `ApprovalStatus::mayDifferFromSubmission()`（差戻し中・取り下げ）の 1 か所に置き、`RelatedNumberController` もそれを使う（2 か所に書くと、状態を足したときに片方だけ直す事故になる。点検の指摘）。2 回以上提出した申請は、最後の回の控えだけで当てる（点検の指摘でテストを足した）
- **状態**（既定は決裁No の付いたもの）: `numbered`＝決裁済み・条件確認待ち・否決・**番号のあと取り下げた欠番**（取り下げのうち `number` があるもの。要件 6.5）／`progress`＝部門長確認中・審査中・社長決裁待ち・差戻し中（取り消しで番号が残ったまま戻ったものも）／`withdrawn`＝取り下げ（欠番を含む）／`all`＝下書き以外すべて。画面は 1 つ選ぶプルダウン
- **判断**: `decision` が可・条可・否のどれか（1 つ選ぶ）
- **決裁日の期間**: 日本の暦の日の 0:00（から）と、次の日の 0:00 の前（まで）を UTC に直して `decided_at` と比べる。番号の無い申請・欠番（取り消しで `decided_at` が空に戻る）は期間を入れると出ない
- **申請者（D21）**: 名前の一部。空白（全角を含む）で分けた語の**どれも含む**（「山田 太郎」で「山田太郎」も、「田　郎」でも当たる）。退職して消した人の申請も探せる（`applicant` は `withTrashed`）
- **キーワード**: 件名か本文。空白で分けた語の**どれも含む**（語ごとに件名か本文のどちらかにあればよい）。`LIKE` の `%` と `_` は逃がさない（アプリのほかの検索と同じ）
- **年度（D20）**: `number_fiscal_year = 年度`、または番号が無く、申請部門（中身の出し分けのとおり）の会社の期（期の始まりの月ごとに、日本時間の期の始まり〜次の期の始まりの前を UTC に直した範囲）に `last_submitted_at` が入る。期の始まりの月ごとの部門は 1 回の問い合わせで読む（`periods()`）
- **並び（D22）**: 日本の暦の日で比べるため、SQL で並べない（UTC で保存した日時を日本の日付に直す書き方が MySQL と SQLite で違う）。`sortedIds()` が軽い列（id・番号・番号の部門・連番・決裁日時・発信日時）だけを読み、PHP で「決裁日（無ければ発信日）の日本の日の新しい順 → 同じ日は決裁No のあるものを先に、部門のアルファベットの順・連番の順 → 番号の無いものは発信の新しい順 → id の新しい順」に並べた id の列を返す。実測（§ 計画を書く段階の実測）: 5,000 件で台帳の画面が 0.12 秒
- **年度の選択肢**（`Ledger::years()`）: 今の年度（会社で違えば新しい方）から、いちばん古い申請の年度（番号の年度と、発信日の年度〈遅い期の会社で数える＝小さい方〉の小さい方）まで、新しい順。表示は「R8 年度（2026）」（和暦は期の始まりの月がいちばん早い会社で数える）
- **1 行の中身**（`App\Support\Approval\LedgerRow::for(User, ApprovalRequest)`）: 番号・決裁日時・判断・件名・種類・申請部門・申請者・金額・実施時期・関連する決裁No・提出日時（`last_submitted_at`）・審査の意見（今の回の審査が判断したときだけ。可・保留・否）・審査のコメント・条件（条可のときだけ社長のコメント）・状態（`statusLabel()`）。中身は申請者本人には今の中身、ほかの人には `lastRevision`（`ApprovalRequest::lastRevision()` を足す。`hasOne(...)->latestOfMany('round')`。控えは提出のたびに回の番号で 1 つ作るので、いちばん大きい回が今の回）の控え（名前も提出したときのもの。`RequestContent` と同じ）。`Ledger::rows(User, list<int>)` が関係（applicant・type・department・lastRevision・steps）をまとめて読み、id の並びのとおりに返す（N+1 にしない）

### 0.4 台帳の画面と入口（§5.8・§9 の 10・D22・要件 14.4）

- ルート: `GET /approvals/ledger`（`approvals.ledger.index`）・`GET /approvals/ledger/excel`（`approvals.ledger.excel`）。どちらも `approval.launched` のグループ（申請の画面と同じ）。コントローラは `App\Http\Controllers\Approval\LedgerController`（`index`・`excel`）
- **絞り込み**（`App\Support\Approval\LedgerFilter::fromRequest()`）: GET の `year`・`department`・`type`・`applicant`・`from`・`to`・`status`・`decision`・`q`。**読めない値は断らずに外し、外した項目の名前を画面に出す**（「読み取れない条件があったので、外して探しました（年度・決裁日（から））。」）。年度は 4 桁・部門と種類は 1 以上の数・日付は `YYYY-MM-DD` の在る日（2026-02-30 は外す）・申請者は 50 文字まで・キーワードは 100 文字まで（画面の `maxlength` と同じ）・状態と判断は決まった値・配列（`?q[]=`）と壊れた文字（不正な UTF-8。`?q=%FF`）は外す・空は条件にしない。さらにコントローラが、**画面の選択肢に無い年度・部門・種類**（`?department=99999` など。手で打った URL・古いリンク）を `LedgerFilter::within()` で外して同じく知らせる（外さないと、条件としては効いて 0 件なのにプルダウンは「すべて」に見え、プルダウンを 1 つ変えると黙って外れる）。画面と Excel は同じ形で条件を作る（`LedgerController::filter()`）。⚠ `validate()` で前の画面へ戻すと、GET の戻り先がこの画面そのもの（セッションの前の URL に今の GET が入る）で、同じ URL を開き直し続ける
- 画面（`resources/views/approvals/ledger/index.blade.php`）: 題と説明 → 絞り込みのフォーム（`id="filter-form"`。プルダウンは変えた瞬間に送る＝CLAUDE.md の即時フィルタ・文字と日付は「絞り込む」で送る・「条件を消す」）→ 件数と「Excel に出力」→ PC は表（列は D22 の「決裁No・決裁日・判断・件名・申請部門・申請者・金額・状態」・横スクロールの枠の中・件名から詳細へ）／スマホ（`md` 未満）は 1 件 1 枚のカード → ページ送り（`approvals._pager`・`PageNumbers::around`＝先頭・最後・今のページの前後）。1 ページ 50 件。0 件のときは「該当する申請はありません。」と、既定の状態で絞っていることの説明
- 絞り込みの欄は PC で 4 列、**スマホで 2 列**（1 列だと欄だけで 1 画面を使い、結果が見えなかった＝2026-10-05 の画面の確かめ）。並びは「年度・状態・判断・申請部門 / 申請の種類・申請者・決裁日の期間・キーワード」（2 列で欠けが出ない並び）
- 画面をもう一度見せたとき（ブラウザの「戻る」）は、`pageshow` で絞り込みのフォームを `reset()` する（Bug #65。プルダウンは変えた瞬間に送るので、戻ると、変えた後のプルダウンと、前の条件のままの表・ページ送り・Excel のリンクが食い違う）。⚠ `event.persisted` で絞らない
- **入口（4 か所＋ホーム）**: 決裁のみ利用者のサイドバー（`sidebar_approval.blade.php` の PC の展開版とスマホのドロワー。「自分の申請」の下）・基幹のサイドバー（`sidebar.blade.php` の PC の展開版とスマホのドロワー。「決裁」の下）に「決裁台帳」。どれも使い始めてから（`$approvalsLaunched`・`$approvalPending !== null` の中）。折りたたみ版のアイコンは足さない（設計書の 4 か所のとおり）。決裁のホーム（使い始めたあと）の上のリンクの行に「決裁台帳」（「新しい申請」「自分の申請」の隣。§0.9 の 1）

### 0.5 Excel（§5.9・§9 の 8・D23・D24）

- `App\Support\Approval\LedgerExcel::build(User, list<int>): string`（xlsx のバイト列）・`fileNames(): array{0: string, 1: string}`（「決裁台帳_2026-10-05.xlsx」と ASCII の代わり「ledger_2026-10-05.xlsx」。日本の今日）
- 列（15）: 決裁No・決裁日・判断・件名・申請の種類・申請部門・申請者・金額（税抜）・実施時期・関連する決裁No（「・」でつなぐ）・提出日・審査の意見・審査のコメント・条件・状態。段階5 の列（工事原価・粗利益金額・粗利率・契約予定日・区分）は段階5 で足す（D1）
- **型**: 文字の欄は `setCellValueExplicit(…, DataType::TYPE_STRING)`（「=」「+」「-」「@」で始まっても式にしない。シートの XML に `<f>` が無いことをテストが見る）。決裁日・提出日は**日本の暦の日**の Excel の日付（数。時刻は入れない。書式 `yyyy/mm/dd`）。金額は数（書式 `#,##0`）。空の値は書かない
- **見た目**: シート名「決裁台帳」・見出しの行は太字と薄い灰色・見出しを固定（`freezePane('A2')`）・見出しに絞り込みのボタン（`setAutoFilter('A1:O{最後の行}')`）・列の幅は決め打ち・件名とコメントの 3 列は折り返し・上ぞろえ。行が無いときは書式だけの空の行を作らない（見出しだけ）
- **件数の上限**（`config('approval.ledger.excel_limit')`・既定 **1,000**）: 超えたら作らず、台帳へ同じ条件で戻して「絞り込んでから出してください（Excel に出せるのは 1,000 件までです。いまは N 件）。」（`session('error')`。記録しない）。ちょうどなら出す。台帳の画面も、上限を超えたら「Excel に出力」を出さずに「Excel に出せるのは 1,000 件までです。絞り込んでください。」、0 件なら出さない。実測（§ 計画を書く段階の実測）: メモリをいちばん使うのは審査のコメントと条件の長さ。入力の上限の中身（件名 100 文字・実施時期 50 文字・関連する決裁No 10 個・コメント 2,000 文字）で、Excel を作る分は 500 件 46MB・1,000 件 68MB・1,500 件 96MB・2,000 件 119MB（PHPUnit の分を除く）。Laravel の起動の分（約 25MB）を足すと 2,000 件は 128M を超える → 1,000 件（95MB ほど）にした。⚠ はじめ、ふつうの中身（コメント 440 文字。2,000 件で 59MB）だけで測って 2,000 にしていた（点検の指摘で測り直した）。申請は年に数百件の見込みなので、年度で絞れば出せる
- 行は 200 件ずつ `Ledger::rows()` で読む（全件の申請と控えを一度に読まない）
- 応答: `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`・`X-Content-Type-Options: nosniff`・`Content-Disposition: attachment; filename=ledger_2026-10-05.xlsx; filename*=utf-8''決裁台帳_2026-10-05.xlsx`（`HeaderUtils::makeDisposition()`。ASCII の代わりの名前を Laravel の `Str::ascii` に任せない＝Bug #68）
- **作るのに失敗したときの知らせは作らない**（§0.9 の 2）
- 本番の PHP の拡張（PhpSpreadsheet が要る `zip`・`xmlwriter`・`gd` など 15 個）は 2026-10-05 に読み取って**すべてある**ことを確かめた（設計書 §4.1 の「無ければ Composer の起動時の確かめで止まっている」は誤り。`vendor/composer/platform_check.php` は PHP の版しか見ない）

### 0.6 走査テスト（全件分類）に登録するもの

3b §0.7・4a §0.7 と同じく、**各 Task が足したものは、その Task の中で登録する**（途中の各段でも全件が緑）。

| 走査テスト | 登録するもの | Task |
|---|---|---|
| `tests/Feature/Approval/Phase2/Phase2TablesTest.php` | `LATER_COLUMNS` に `approval_download_logs` の `filters`・`request_count`、新しい `LATER_NULLABLE` に `approval_download_logs` の `request_id`（4b で NULL 可にした。2a の SQL は NOT NULL） | 1 |
| `tests/Feature/Approval/ApprovalAdminGateTest.php` | `OPEN_TO_EVERY_USER` に `approvals.ledger.index`（理由つき）・下限を 51 → **52**（Task 3）／`approvals.ledger.excel`・下限を **53**（Task 4） | 3・4 |

- `LaunchGateTest`: 台帳と Excel のルートは `approval.launched` のグループの中に置く（登録は要らない。Task 3・4 のテストでも、使い始める前は決裁のホームへ送られることを見る）
- `ApprovalOnlyLockoutTest`: `approvals.` の名前で `App\Http\Controllers\Approval\` に置く（登録は要らない）
- `ClockReadScanTest`: 新しい時計の読み取りは無い（Excel のファイル名は `JapanTime::today()`）。⚠ 日付を読む自前の関数を `date()` と名付けると、走査が「時計の読み取り」と数える（試作で 1 度赤になった）→ `calendarDay()` と名付けた
- `MobileLayoutTest`: 台帳の表は横スクロールの枠（`scroll-hint`）の中に置く（登録は要らない）
- `ValidationErrorFeedbackTest`: 台帳のフォームは GET で `@csrf` を持たないので走査の対象外（読めない値の知らせは画面が自前で出す）
- `StoredTimestampDisplayScanTest`: 日時の表示は `JapanTime::format()`（登録は要らない）
- `LoginGuideTest` の 1 ファイル 1 宣言: 新しいファイルはどれも 1 つ

### 0.7 既存のコードを変えるもの（振る舞いを変えない片付け）

| ファイル | 変えること | Task |
|---|---|---|
| `app/Http/Controllers/Approval/RequestAttachmentController.php` | 添付を開いた記録を `DownloadLogger::attachment()` で書く（書く中身は同じ） | 1 |
| `app/Http/Controllers/Approval/RequestPdfController.php` | PDF の記録を `DownloadLogger::pdf()` で書く（書く中身は同じ） | 1 |
| `app/Models/ApprovalDownloadLog.php` | `KIND_*` の定数・`$fillable` と `casts()` に `filters`・`request_count`・説明を 3 つの出力に | 1 |
| `app/Models/ApprovalRequest.php` | `lastRevision()` を足す（ほかの呼び出しは変えない） | 2 |
| `app/Enums/ApprovalStatus.php`・`app/Http/Controllers/Approval/RelatedNumberController.php` | 「直しかけが残りうる状態」（差戻し中・取り下げ）を `ApprovalStatus::mayDifferFromSubmission()` に置き、関連する決裁No の候補もそれを使う（中身は同じ。`RelatedNumberSearchTest` は変えずに通る） | 2 |

### 0.8 既存のテストを変えるもの

| テスト | 変えること | Task |
|---|---|---|
| `Phase2TablesTest`・`ApprovalAdminGateTest` | §0.6 のとおり足すだけ（意味は変えない） | 1・3・4 |
| `ApprovalSidebarTest`・`ApprovalMenuTest`・`HomeAndListTest` | 入口のテストを 1 本ずつ足す（既存のテストは変えない） | 3 |

既存のテストで意味が変わるものは無い（添付と PDF の記録のテスト〈`RequestAttachmentTest`・`RequestPdfTest`〉は変えずに通る）。

### 0.9 設計書から変えた細部（計画を書いていて分かったこと）

| # | 設計書 | この計画 | 理由 |
|---|---|---|---|
| 1 | §5.8「入口: … ホームのリンクの行に『決裁台帳』」 | 決裁のホームの上の「新しい申請」「自分の申請」の行に「決裁台帳」 | 一覧へ行くリンクが並ぶ行。下の行（パスワードの変更・管理の画面）は設定の行で、台帳を探しにくい |
| 2 | §5.7・D18（PDF は作れなかったら知らせて laravel.log） | Excel は作れなかったときの知らせを作らない（ほかの画面と同じく Laravel の例外の扱いで laravel.log に残る） | PDF はフォントなど外の物に頼るので失敗の形があるが、Excel の失敗はメモリの不足くらいで、それは捕まえられない（件数の上限で防ぐ）。捕まえる形を作ってもテストで起こせず、確かめられない |
| 3 | §4.1「PhpSpreadsheet の拡張が無ければ Composer の起動時の確かめで止まっている」 | 本番を読み取って確かめた（すべてある） | 起動時の確かめは PHP の版しか見ていなかった |
| 4 | D22「同じ日は決裁No の順」 | 同じ日は、決裁No のあるものを部門のアルファベット・連番の順に先に、番号の無いもの（その日に提出した申請）を発信の新しい順にあとに | 「決裁No の順」を番号の文字の順にすると R8-J-1000 が R8-J-999 より前になる。番号の無いものの位置は設計書に無かった |

### 0.10 受け入れた隙間

| # | 隙間 | 起きたとき | 塞ぐなら |
|---|---|---|---|
| 1 | キーワードの探し方が、本番の MySQL では当てる列で少し違う。申請の行の列（`utf8mb4_unicode_ci`）は大文字と小文字・ひらがなとカタカナ・濁点の有無（「ハハ」と「パパ」）を区別せずに当て、控えの JSON の中（他人の差戻し中・取り下げの申請だけ）は区別して当てる（JSON から取り出した文字は `utf8mb4_bin`） | 他人の差戻し中・取り下げの申請を、大文字小文字やかなを変えた言葉で探すと当たらないことがある（ほかの申請は当たる）。テストの SQLite では見えない | 控えの側にも照合順序を付ける（MySQL だけの書き方になる） |
| 2 | 台帳の画面を開くたびに、条件に当たる申請の軽い列をすべて読んで並べる（§0.3） | 申請が数万件になると画面が遅くなる（5,000 件で 0.12 秒。申請は年に数百件の見込み） | 日本の日付の列を表に持つ |
| 3 | 本番の Web の PHP のメモリの上限は CLI と同じ 128M と見ている（4a の受け入れた隙間 4 と同じ） | 128M より小さければ、コメントの長い 1,000 件の Excel を作れないことがある（500 の画面と laravel.log） | 使い始める前の受け入れ確認（段階6）で、多めの台帳の Excel を本番で 1 回出す |
| 4 | 番号のあと取り下げた欠番は決裁日が空（取り消しで `decided_at` が空に戻る）なので、並びは発信日の日になり、決裁日の期間で絞ると出ない | 欠番が番号の近くでなく、最後に提出した日の位置に出る | 取り下げた日時（`finished_at` など）を欠番の日にする |
| 5 | 年度の選択肢の和暦は、期の始まりの月がいちばん早い会社で数える | 期の始まりが違う会社で、令和の始まり（2019-05-01）をまたぐ年度だけ表示が違いうる（今の会社は 5 月・6 月始まりで、どちらも R1） | 選択肢を会社ごとに出す |
| 6 | キーワード・申請者の `LIKE` の `%` と `_` を逃がさない（アプリのほかの検索と同じ） | `%` や `_` を打つと、どの文字にも当たる | 逃がす部品を作って、アプリのほかの検索と一緒に直す |

### 0.11 テストの土台

- 4a と同じ（`Tests\Concerns\BuildsApprovalFixtures`・`approvalWorld()` の部門長「部門 長」・審査担当者「審査 担当」・社長「社長 太郎」・申請者「申請 花子」・会社は 5 月始まり・申請部門「住宅事業部」J・審査部門「総務部」S）
- 時計は `Carbon::setTestNow(Carbon::parse('… UTC'))`（日本時間の Carbon を渡すと保存した日時が 9 時間ずれる）
- 状態の列（`status`・`number`・`decided_at`・`last_submitted_at` など）は `$fillable` に無いので `DB::table('approval_requests')->update()` で書く（並び・年度・件数のテスト）。判断の流れが要るテストは `Workflow` を通す
- 見られる人の既定は `viewAllUser()`（全件閲覧者。下書き以外すべて）
- Excel は応答のバイト列をファイルに書き、`PhpOffice\PhpSpreadsheet\IOFactory::load()` で読み戻して列・型・書式を見る。式でないことは、セルの型（`DataType::TYPE_STRING`）と、ZIP の中の `xl/worksheets/sheet1.xml` に `<f>` が無いことの両方で見る

---

## 1. 触るファイル

### 新規

| ファイル | 役目 | Task |
|---|---|---|
| `database/sql/2026-10-05-approval-phase4b.sql` | 本番の DDL（ALTER 1 文） | 1 |
| `database/migrations/2026_10_05_000001_change_approval_download_logs_for_excel.php` | テストの鏡 | 1 |
| `app/Support/Approval/DownloadLogger.php` | 出力の記録を書く 1 か所 | 1 |
| `app/Support/Approval/LedgerFilter.php` | 絞り込みの条件 | 2 |
| `app/Support/Approval/Ledger.php` | 台帳の問い合わせ・並び・行・年度の選択肢 | 2 |
| `app/Support/Approval/LedgerRow.php` | 1 行の中身 | 2 |
| `app/Http/Controllers/Approval/LedgerController.php` | 台帳の画面・Excel の出力 | 3・4 |
| `resources/views/approvals/ledger/index.blade.php` | 台帳の画面 | 3・4 |
| `app/Support/Approval/LedgerExcel.php` | Excel を作る | 4 |
| `tests/Feature/Approval/Phase4/`（`Phase4bTablesTest`・`DownloadLoggerTest`・`LedgerFilterTest`・`LedgerQueryTest`・`LedgerRowTest`・`LedgerScreenTest`・`LedgerExcelTest`） | テスト | 1〜4 |

### 変更

| ファイル | 変えること | Task |
|---|---|---|
| `app/Models/ApprovalDownloadLog.php`・`RequestAttachmentController.php`・`RequestPdfController.php` | §0.7 | 1 |
| `app/Models/ApprovalRequest.php` | `lastRevision()` | 2 |
| `app/Enums/ApprovalStatus.php`・`RelatedNumberController.php` | §0.7 | 2 |
| `routes/approval.php` | 台帳（Task 3）と Excel（Task 4）のルート | 3・4 |
| `resources/views/layouts/partials/sidebar_approval.blade.php`・`sidebar.blade.php`・`resources/views/approvals/home-launched.blade.php` | 入口 | 3 |
| `config/approval.php` | Excel の件数の上限 | 4 |
| 走査テストと既存のテスト | §0.6・§0.8 | 1・3・4 |

## 2. 作業の順番

```
Task 0 準備 → Task 1 表と出力の記録の書き方 → Task 2 台帳の問い合わせ → Task 3 台帳の画面と入口
→ Task 4 Excel と記録 → Task 5 全件・変異 → Task 6 ブラウザと写真と Excel
→ Task 7 ドキュメント → Task 8 本番反映（親の会話）
```

各 Task のコミットのあとで全件が緑（途中の段でも）。Task 5・6 は記録だけをコミットする。

---

## Task 0: 作業場所の準備

**作業場所**: worktree `/Users/masanori/site/manage/.claude/worktrees/approval-phase3`（ブランチ `approval-phase3`。3a〜4a と同じ worktree を使う＝利用者の決定・dev の vendor の置き場。この計画のコミットの親は `13.x` の `99d5f1ed`〈4a を本番に出した記録 `4ae494a6` のあと、別の会話の不動産のテストが進んだもの。決裁のファイル・`composer`・`routes`・`database` の変更は無い〉へ早送りしたもの）。**main repo では作業もテストもしない**（main repo の vendor は `--no-dev` で phpunit が無い。dev 依存を入れると `./deploy.sh` が本番へ送る）。

- [ ] **Step 1: 並行の作業を確かめる**（ほかの会話が同じ課題を進めていないか）

```bash
cd /Users/masanori/site/manage && git status --short --branch && git log --oneline -5 && git worktree list && git for-each-ref --sort=-committerdate --format='%(refname:short) %(committerdate:short) %(subject)' refs/heads | head && git log --oneline approval-phase3..13.x
```

Expected: worktree `approval-phase3` の先頭がこの計画のコミット。最後のコマンド（`13.x` にあって `approval-phase3` に無いコミット）が空。ほかの worktree があれば別の会話のもの（触らない）。台帳・Excel の名前の付いた枝や worktree があれば、中身を読み、消さずに利用者へ報告して止まる。`13.x` が進んでいたら止まり、利用者に取り込むかを聞く（取り込むなら WT で `git merge 13.x`。rebase しない）。

- [ ] **Step 2: vendor を確かめる**（dev 依存あり・実体・PhpSpreadsheet が入っている）

⚠ **symlink にしない**（autoload が symlink の先を読み、別の場所のコードでテストが流れる。Bug #50）。

```bash
WT=/Users/masanori/site/manage/.claude/worktrees/approval-phase3; test -x "$WT/vendor/bin/phpunit" && ! test -L "$WT/vendor" && test -f "$WT/vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Writer/Xlsx.php" && echo "vendor OK"
```

Expected: `vendor OK`（4b は部品を足さない。`composer` は打たない）

- [ ] **Step 3: 差分のファイルを確かめる**

```bash
ls ~/.claude/plans/approval-phase3-tasks/4b/patches/ && cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --check ~/.claude/plans/approval-phase3-tasks/4b/patches/0001-*.patch && echo "0001 を当てられる"
```

Expected: `0001-…` 〜 `0004-…` の 4 本・`0001 を当てられる`。差分のファイルが無ければ計画のコードを打ち込む（中身は同じ）。

- [ ] **Step 4: 全件テストが通る状態から始める**

**テストの流し方**（以下すべての Task で同じ。`APP_KEY` は 32 バイトの本物の鍵を渡す。worktree に `.env` を作らない）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `OK (3643 tests, 28332 assertions)`（2026-10-05 の実測。約 3 分）。赤があれば 4b の作業の前に利用者へ報告して止まる。

⚠ 以下の Task の「テストを流す」は、すべてこの形でファイルを並べたもの。`cd` はコマンドごとに書く（ターンをまたぐと cwd が main repo へ戻る）。git は `git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 …` で呼ぶ。

⚠ コミットのたびに `git status --porcelain` が空であることを確かめる。差分のファイルを当てたときは、`git diff --stat` が各 Task の「差分の大きさ」と同じことも確かめる。

---

## Task 1: 出力の記録の表と、記録の書き方を 1 か所に

`approval_download_logs` を Excel の記録にも使える形（申請の欄を空でもよく・絞り込みの条件と件数の列）にし、添付・PDF・Excel の 3 つの出力の記録を `DownloadLogger` の 1 か所で書く（設計書 §5.3・§5.7・§5.10・D25・§0.2。4a の BACKLOG の持ち越し）。

**Files:**
- Create: `database/sql/2026-10-05-approval-phase4b.sql`・`database/migrations/2026_10_05_000001_change_approval_download_logs_for_excel.php`・`app/Support/Approval/DownloadLogger.php`
- Modify: `app/Models/ApprovalDownloadLog.php`・`app/Http/Controllers/Approval/RequestAttachmentController.php`・`app/Http/Controllers/Approval/RequestPdfController.php`
- Test: Create `tests/Feature/Approval/Phase4/Phase4bTablesTest.php`・`tests/Feature/Approval/Phase4/DownloadLoggerTest.php`／Modify `tests/Feature/Approval/Phase2/Phase2TablesTest.php`

**Interfaces:**
- Produces: `approval_download_logs.request_id`（NULL 可）・`filters`（JSON）・`request_count`（INT UNSIGNED）
- Produces: `ApprovalDownloadLog::KIND_ATTACHMENT`・`KIND_PDF`・`KIND_EXCEL`（`'attachment'`・`'pdf'`・`'excel'`）・`casts()` の `filters => array`・`request_count => integer`
- Produces: `DownloadLogger::attachment(Request $request, ApprovalAttachment $attachment): ApprovalDownloadLog`・`DownloadLogger::pdf(Request $request, ApprovalRequest $approvalRequest): ApprovalDownloadLog`・`DownloadLogger::excel(Request $request, array $filters, int $count): ApprovalDownloadLog`（誰がは `$request->user()`）。Task 4 が `excel()` を使う

**差分の大きさ:** 9 ファイル・+329 / −20 行（差分のファイル `0001-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase4/Phase4bTablesTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase4;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 段階4（4b）の出力の記録の表（段階4 設計書 §5.3・D25）。
 *
 * ⚠ 本番は SQL を手で流し、テストは migration で作る。**両方が食い違わないこと**をここで固定する（Phase4aTablesTest と同じ考え）。
 *   比べるのは、変える表・変える列と足す列の名前・NULL を許すか。**両方向に比べる**。
 * ⚠ SQLite の `change()` は表を作り直すので、作り直したあとも外部キーと索引が残ることを見る（消えると、無い申請の id でも記録できてしまう）。
 */
class Phase4bTablesTest extends TestCase
{
    use RefreshDatabase;

    private const SQL = 'database/sql/2026-10-05-approval-phase4b.sql';

    private const MIGRATION = 'database/migrations/2026_10_05_000001_change_approval_download_logs_for_excel.php';

    /** @return array<string, array<string, bool>> 表 => [変える・足す列 => NULL を許すか] */
    private function sqlColumns(): array
    {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(base_path(self::SQL)));
        preg_match_all('/ALTER TABLE `(\w+)`(.*?);/s', $sql, $alters, PREG_SET_ORDER);

        $tables = [];
        foreach ($alters as [, $table, $body]) {
            preg_match_all('/(?:MODIFY|ADD) COLUMN `(\w+)` [A-Z]+[^,]*/', $body, $columns, PREG_SET_ORDER);
            foreach ($columns as [$definition, $column]) {
                $tables[$table][$column] = ! str_contains($definition, 'NOT NULL');
            }
        }

        return $tables;
    }

    public function test_the_sql_and_the_migration_touch_the_same_table(): void
    {
        preg_match_all("/Schema::table\\('(\\w+)'/", file_get_contents(base_path(self::MIGRATION)), $matches);

        $this->assertSame(['approval_download_logs'], array_keys($this->sqlColumns()));
        $this->assertSame(['approval_download_logs'], array_values(array_unique($matches[1])));
    }

    public function test_the_sql_and_the_migration_change_the_same_columns(): void
    {
        $sql = $this->sqlColumns()['approval_download_logs'] ?? [];

        // 空振りで緑にならないように（申請の欄を空でもよくし、条件と件数の 2 列を足す）
        $this->assertSame(['request_id' => true, 'filters' => true, 'request_count' => true], $sql);

        $migrated = [];
        foreach (Schema::getColumns('approval_download_logs') as $column) {
            if (isset($sql[$column['name']])) {
                $migrated[$column['name']] = (bool) $column['nullable'];
            }
        }
        ksort($migrated);
        ksort($sql);

        $this->assertSame($sql, $migrated, '変える列と足す列（名前と NULL を許すか）が SQL と migration で違う');
    }

    public function test_the_rebuilt_table_keeps_its_foreign_keys_and_indexes(): void
    {
        $keys = [];
        foreach (Schema::getForeignKeys('approval_download_logs') as $key) {
            $keys[implode(',', $key['columns'])] = $key['foreign_table'];
        }
        ksort($keys);

        $this->assertSame(['attachment_id' => 'approval_attachments', 'request_id' => 'approval_requests', 'user_id' => 'users'], $keys);

        $indexes = array_column(Schema::getIndexes('approval_download_logs'), 'name');
        $this->assertContains('idx_approval_download_logs_request', $indexes);
        $this->assertContains('idx_approval_download_logs_user', $indexes);
    }
}
```

`tests/Feature/Approval/Phase4/DownloadLoggerTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase4;

use App\Models\ApprovalAttachment;
use App\Models\ApprovalDownloadLog;
use App\Models\User;
use App\Support\Approval\DownloadLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 出力の記録の書き方（1 か所。段階4 設計書 §5.7・D25・4a の BACKLOG の持ち越し）。
 *
 * 添付と PDF の記録は、それぞれの画面のテスト（RequestAttachmentTest・RequestPdfTest）が出力を通して見る。
 * ここは 3 つの種類が同じ書き方（誰が・IP・端末を 255 文字まで）になることと、Excel の行の形を見る。
 */
class DownloadLoggerTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private function httpRequest(User $user): Request
    {
        $request = Request::create('/approvals/ledger', 'GET', server: [
            'REMOTE_ADDR'     => '203.0.113.9',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone) ' . str_repeat('端', 300),
        ]);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_the_kinds_are_the_values_kept_in_the_table(): void
    {
        // 本番の行がこの値で残る（4a までの添付と PDF の記録と同じ値）
        $this->assertSame(
            ['attachment', 'pdf', 'excel'],
            [ApprovalDownloadLog::KIND_ATTACHMENT, ApprovalDownloadLog::KIND_PDF, ApprovalDownloadLog::KIND_EXCEL]
        );
    }

    public function test_an_excel_row_has_no_request_and_keeps_the_filters_and_the_count(): void
    {
        $user    = $this->baseUser();
        $filters = ['status' => 'numbered', 'keyword' => '社用車', 'year' => 2026];

        DownloadLogger::excel($this->httpRequest($user), $filters, 12);

        $log  = ApprovalDownloadLog::sole();
        $kept = $log->fresh()->filters;
        // MySQL の JSON はキーを並べ替えて返す（RequestSnapshot の注意）。並びに頼らずに比べる
        ksort($filters);
        ksort($kept);
        $this->assertSame(
            [null, null, $user->id, 'excel', $filters, 12],
            [$log->request_id, $log->attachment_id, (int) $log->user_id, $log->kind, $kept, $log->fresh()->request_count]
        );
        $this->assertSame('203.0.113.9', $log->ip_address);
        $this->assertSame(255, mb_strlen($log->user_agent));
        $this->assertNotNull($log->created_at);
    }

    public function test_every_kind_is_written_the_same_way(): void
    {
        $world   = $this->approvalWorld();
        $request = $this->submittedFor($world);
        $attachment = ApprovalAttachment::create([
            'request_id' => $request->id, 'original_name' => '見積書.pdf', 'stored_path' => 'approvals/x.pdf',
            'mime' => 'application/pdf', 'size' => 10, 'uploaded_by' => $world['applicant']->id, 'added_round' => 1,
        ]);
        $http = $this->httpRequest($world['head']);

        DownloadLogger::attachment($http, $attachment);
        DownloadLogger::pdf($http, $request);
        DownloadLogger::excel($http, [], 0);

        $this->assertSame(
            [
                [$request->id, $attachment->id, 'attachment'],
                [$request->id, null, 'pdf'],
                [null, null, 'excel'],
            ],
            ApprovalDownloadLog::orderBy('id')->get()->map(fn (ApprovalDownloadLog $l) => [
                $l->request_id === null ? null : (int) $l->request_id,
                $l->attachment_id === null ? null : (int) $l->attachment_id,
                $l->kind,
            ])->all()
        );
        foreach (ApprovalDownloadLog::all() as $log) {
            $this->assertSame([$world['head']->id, '203.0.113.9', 255], [(int) $log->user_id, $log->ip_address, mb_strlen($log->user_agent)], $log->kind);
        }
    }

    /** 記録を書くのはこの部品だけ（出力ごとに写すと、端末を 255 文字で切る所などがまた散らばる） */
    public function test_only_the_logger_writes_the_records(): void
    {
        $writers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && preg_match('/ApprovalDownloadLog::(create|insert|forceCreate|query)\b/', file_get_contents($file->getPathname()))) {
                $writers[] = str_replace(base_path() . '/', '', $file->getPathname());
            }
        }

        $this->assertSame(['app/Support/Approval/DownloadLogger.php'], $writers);
    }
}
```

`tests/Feature/Approval/Phase2/Phase2TablesTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/Phase2TablesTest.php
+++ b/tests/Feature/Approval/Phase2/Phase2TablesTest.php
@@ -35,6 +35,12 @@ class Phase2TablesTest extends TestCase
     private const LATER_COLUMNS = [
         'approval_settings' => ['mail_last_sent_at', 'mail_last_failed_at', 'mail_last_failed_to'],   // 3a（Phase3TablesTest）
         'approval_steps'    => ['stamp_label', 'stamp_text'],                                         // 4a（Phase4aTablesTest）
+        'approval_download_logs' => ['filters', 'request_count'],                                    // 4b（Phase4bTablesTest）
+    ];
+
+    /** あとの段階が NULL を許すように変えた 2a の列（その段階の表のテストが見る。ここでは NULL を許すものとして比べる）。表 => 列 */
+    private const LATER_NULLABLE = [
+        'approval_download_logs' => ['request_id'],   // 4b（Excel の記録は申請の欄が空。Phase4bTablesTest）
     ];
 
     /** @return list<array{string, string, bool}> [表, CREATE の括弧の中か ALTER の中身, ALTER か] */
@@ -144,6 +150,9 @@ public function test_the_sql_and_the_migration_declare_the_same_columns(): void
                 $migrated[$column['name']] = (bool) $column['nullable'];
             }
             $migrated = array_diff_key($migrated, array_flip(self::LATER_COLUMNS[$table] ?? []));
+            foreach (self::LATER_NULLABLE[$table] ?? [] as $column) {
+                $columns[$column] = true;
+            }
 
             ksort($columns);
             ksort($migrated);
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/4b/patches/0001-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase2/Phase2TablesTest.php tests/Feature/Approval/Phase4/DownloadLoggerTest.php tests/Feature/Approval/Phase4/Phase4bTablesTest.php
```

Expected: `ERRORS!` `Tests: 12, Assertions: 40, Errors: 5, Failures: 2.`

- `DownloadLoggerTest::test_the_kinds_are_the_values_kept_in_the_table` — `Error: Undefined constant App\Models\ApprovalDownloadLog::KIND_ATTACHMENT`
- `DownloadLoggerTest::test_an_excel_row_has_no_request_and_keeps_the_filters_and_the_count` — `Error: Class "App\Support\Approval\DownloadLogger" not found`
- `DownloadLoggerTest::test_every_kind_is_written_the_same_way` — `Error: Class "App\Support\Approval\DownloadLogger" not found`
- `Phase4bTablesTest::test_the_sql_and_the_migration_touch_the_same_table` — `ErrorException: file_get_contents(/Users/masanori/site/manage/.claude/worktrees/approval-phase3/database/migrati`
- `Phase4bTablesTest::test_the_sql_and_the_migration_change_the_same_columns` — `ErrorException: file_get_contents(/Users/masanori/site/manage/.claude/worktrees/approval-phase3/database/sql/202`
- `Phase2TablesTest::test_the_sql_and_the_migration_declare_the_same_columns` — `approval_download_logs の列（名前と NULL を許すか）が SQL と migration で違う`
- `DownloadLoggerTest::test_only_the_logger_writes_the_records` — `Failed asserting that two arrays are identical.`

- [ ] **Step 3: 表を変え、記録の書き方を 1 か所にする**

`database/sql/2026-10-05-approval-phase4b.sql`（新規）

```sql
-- 決裁申請 段階4（4b）— 2026-10-05
--
-- 設計書: docs/superpowers/specs/2026-10-03-approval-phase4-design.md §5.3・§5.7・D25
--
-- ⚠ database/migrations/2026_10_05_000001_change_approval_download_logs_for_excel.php と
--   対で維持すること（あちらは SQLite のテストのための鏡。Phase4bTablesTest が見る）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** 新しいコードは Excel の出力を、申請の欄が空の行と filters・request_count で記録するので、
--   コードを先に送ると、台帳の Excel の出力が 500 になる（使い始める前は誰も開けないが、順番は守る）。
--
-- 適用: 段階1〜4a と同じく php artisan tinker --execute で DB::statement() に流す（1 文）。
--   先頭で「approval_download_logs に filters があれば流さずに止まる」確認をする（計画 Task 8）。

-- 出力の記録を Excel にも使える形に（D25。Excel の行は申請の欄が空で、絞り込みの条件と件数を控える）
ALTER TABLE `approval_download_logs`
  MODIFY COLUMN `request_id` BIGINT UNSIGNED NULL COMMENT '添付・PDF の申請（Excel は空）',
  ADD COLUMN `filters` JSON NULL COMMENT 'Excel の絞り込みの条件' AFTER `user_agent`,
  ADD COLUMN `request_count` INT UNSIGNED NULL COMMENT 'Excel に出した件数' AFTER `filters`;
```

`database/migrations/2026_10_05_000001_change_approval_download_logs_for_excel.php`（新規）

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 決裁申請 段階4（4b）の出力の記録（段階4 設計書 §5.3・§5.7・D25）。
 *
 * ⚠ **これは SQLite のテストのための鏡**。本番は `database/sql/2026-10-05-approval-phase4b.sql` を
 *   手で流す（このプロジェクトは migration で本番を管理していない）。**両方を対で維持すること**（Phase4bTablesTest が見る）。
 * ⚠ SQLite の `change()` は表を作り直す（Laravel 12）。外部キーと索引は残る（Phase4bTablesTest が見る）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_download_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('request_id')->nullable()->comment('添付・PDF の申請（Excel は空）')->change();
            $table->json('filters')->nullable()->after('user_agent')->comment('Excel の絞り込みの条件');
            $table->unsignedInteger('request_count')->nullable()->after('filters')->comment('Excel に出した件数');
        });
    }

    public function down(): void
    {
        Schema::table('approval_download_logs', function (Blueprint $table) {
            $table->dropColumn(['filters', 'request_count']);
        });

        Schema::table('approval_download_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('request_id')->nullable(false)->comment('')->change();
        });
    }
};
```

`app/Models/ApprovalDownloadLog.php`（変更）

```diff
--- a/app/Models/ApprovalDownloadLog.php
+++ b/app/Models/ApprovalDownloadLog.php
@@ -5,12 +5,29 @@
 use App\Models\Concerns\AppendOnly;
 use Illuminate\Database\Eloquent\Model;
 
-/** 添付を開いた記録（要件 14.2・設計書 §5.7）。**追記のみ。** 段階4 で PDF・Excel を足す */
+/**
+ * 添付・PDF・台帳の Excel を出力した記録（要件 14.2・段階4 設計書 §5.7・D25）。**追記のみ。**
+ *
+ * ⚠ 書くのは `App\Support\Approval\DownloadLogger` だけ（IP と端末の書き方を 1 か所にそろえる）。
+ * ⚠ Excel の行は申請の欄（request_id）が空で、絞り込みの条件（filters）と件数（request_count）を控える。
+ */
 class ApprovalDownloadLog extends Model
 {
     use AppendOnly;
 
     public const UPDATED_AT = null;
 
-    protected $fillable = ['request_id', 'attachment_id', 'user_id', 'kind', 'ip_address', 'user_agent'];
+    /** 記録の種類（DB の値。本番の行がこの値で残るので変えない） */
+    public const KIND_ATTACHMENT = 'attachment';
+
+    public const KIND_PDF = 'pdf';
+
+    public const KIND_EXCEL = 'excel';
+
+    protected $fillable = ['request_id', 'attachment_id', 'user_id', 'kind', 'ip_address', 'user_agent', 'filters', 'request_count'];
+
+    protected function casts(): array
+    {
+        return ['filters' => 'array', 'request_count' => 'integer'];
+    }
 }
```

`app/Support/Approval/DownloadLogger.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Models\ApprovalAttachment;
use App\Models\ApprovalDownloadLog;
use App\Models\ApprovalRequest;
use Illuminate\Http\Request;

/**
 * 出力を 1 行記録する（要件 14.2・段階4 設計書 §5.7・D25）。添付・PDF・台帳の Excel の 3 つの出力はここだけを通す。
 *
 * 誰が（ログインしている人）・IP・端末（255 文字まで）の書き方を 1 か所にそろえる（4a までは出力ごとに写していた）。
 * ⚠ 出力できなかったとき（PDF を作れなかった・件数が上限を超えた）は呼ばない。
 */
final class DownloadLogger
{
    /** 添付を開いた・ダウンロードした（一度でも提出した申請の添付だけ。呼ぶ側が決める） */
    public static function attachment(Request $request, ApprovalAttachment $attachment): ApprovalDownloadLog
    {
        return self::write($request, ApprovalDownloadLog::KIND_ATTACHMENT, [
            'request_id'    => $attachment->request_id,
            'attachment_id' => $attachment->id,
        ]);
    }

    /** 決裁申請書の PDF を出した */
    public static function pdf(Request $request, ApprovalRequest $approvalRequest): ApprovalDownloadLog
    {
        return self::write($request, ApprovalDownloadLog::KIND_PDF, ['request_id' => $approvalRequest->id]);
    }

    /**
     * 決裁台帳の Excel を出した（申請の欄は空。絞り込みの条件と件数を控える）
     *
     * @param array<string, mixed> $filters
     */
    public static function excel(Request $request, array $filters, int $count): ApprovalDownloadLog
    {
        return self::write($request, ApprovalDownloadLog::KIND_EXCEL, ['filters' => $filters, 'request_count' => $count]);
    }

    /** @param array<string, mixed> $columns */
    private static function write(Request $request, string $kind, array $columns): ApprovalDownloadLog
    {
        return ApprovalDownloadLog::create($columns + [
            'user_id'    => $request->user()->id,
            'kind'       => $kind,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
```

`app/Http/Controllers/Approval/RequestAttachmentController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/RequestAttachmentController.php
+++ b/app/Http/Controllers/Approval/RequestAttachmentController.php
@@ -4,8 +4,8 @@
 
 use App\Http\Controllers\Controller;
 use App\Models\ApprovalAttachment;
-use App\Models\ApprovalDownloadLog;
 use App\Models\ApprovalRequest;
+use App\Support\Approval\DownloadLogger;
 use App\Support\Approval\RequestContent;
 use App\Support\Approval\RequestPermissions;
 use App\Support\Approval\RequestVisibility;
@@ -107,14 +107,7 @@ public function show(Request $request, ApprovalAttachment $approvalAttachment):
 
         // 一度でも提出した申請の添付は、開くたびに記録する（ブラウザで開いた場合も。14.2・計画 §0.5）
         if ($approvalRequest->round >= 1) {
-            ApprovalDownloadLog::create([
-                'request_id'    => $approvalRequest->id,
-                'attachment_id' => $approvalAttachment->id,
-                'user_id'       => $user->id,
-                'kind'          => 'attachment',
-                'ip_address'    => $request->ip(),
-                'user_agent'    => mb_substr((string) $request->userAgent(), 0, 255),
-            ]);
+            DownloadLogger::attachment($request, $approvalAttachment);
         }
 
         // 日本語の名前は filename*（UTF-8）で渡し、古いブラウザ用に ASCII の代わりの名前も付ける。
```

`app/Http/Controllers/Approval/RequestPdfController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/RequestPdfController.php
+++ b/app/Http/Controllers/Approval/RequestPdfController.php
@@ -4,9 +4,9 @@
 
 use App\Enums\ApprovalStatus;
 use App\Http\Controllers\Controller;
-use App\Models\ApprovalDownloadLog;
 use App\Models\ApprovalRequest;
 use App\Support\Approval\ApprovalPdf;
+use App\Support\Approval\DownloadLogger;
 use App\Support\Approval\PdfSheet;
 use App\Support\Approval\RequestVisibility;
 use Illuminate\Http\RedirectResponse;
@@ -43,14 +43,7 @@ public function show(Request $request, ApprovalRequest $approvalRequest): Respon
         }
 
         // 出力のたびに記録する（14.2・§5.7）
-        ApprovalDownloadLog::create([
-            'request_id'    => $approvalRequest->id,
-            'attachment_id' => null,
-            'user_id'       => $user->id,
-            'kind'          => 'pdf',
-            'ip_address'    => $request->ip(),
-            'user_agent'    => mb_substr((string) $request->userAgent(), 0, 255),
-        ]);
+        DownloadLogger::pdf($request, $approvalRequest);
 
         // ブラウザでそのまま開く（D18）。日本語の名前は filename*（UTF-8）、古いブラウザ用の代わりは ASCII だけの名前
         $fallback = 'approval-' . ($approvalRequest->number ?? $approvalRequest->id) . '.pdf';
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/4b/patches/0001-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 と同じコマンドに、添付と PDF の記録のテストを足して）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase2/Phase2TablesTest.php tests/Feature/Approval/Phase4/DownloadLoggerTest.php tests/Feature/Approval/Phase4/Phase4bTablesTest.php tests/Feature/Approval/Phase2/RequestAttachmentTest.php tests/Feature/Approval/Phase4/RequestPdfTest.php
```

Expected: `OK`（赤なし。添付と PDF の記録のテストは変えずに通る＝振る舞いが同じ）

- [ ] **Step 5: 全件を流す**

Expected: `OK (3650 tests, 28350 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add database/sql/2026-10-05-approval-phase4b.sql database/migrations/2026_10_05_000001_change_approval_download_logs_for_excel.php app/Models/ApprovalDownloadLog.php app/Support/Approval/DownloadLogger.php app/Http/Controllers/Approval/RequestAttachmentController.php app/Http/Controllers/Approval/RequestPdfController.php tests/Feature/Approval/Phase4/Phase4bTablesTest.php tests/Feature/Approval/Phase4/DownloadLoggerTest.php tests/Feature/Approval/Phase2/Phase2TablesTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 出力の記録を Excel にも使える形にし書き方を 1 か所にまとめる

approval_download_logs の申請の欄を空でもよくし、絞り込みの条件（filters）と
件数（request_count）の列を、本番の SQL とテストの migration の対で足す
（段階4 設計書 §5.3・D25）。添付・PDF・Excel の記録は DownloadLogger だけが
書き、記録の種類は ApprovalDownloadLog::KIND_* にそろえる（4a の持ち越し）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 2: 決裁台帳の問い合わせ

見られる申請を絞り込む問い合わせ（`Ledger`）・絞り込みの条件（`LedgerFilter`）・1 行の中身（`LedgerRow`）を作る。中身は申請者本人以外には最後に提出した控えで出し・当て、並びは日本の暦の日で作る（設計書 §5.8・§5.9・D19〜D22・§0.3）。

**Files:**
- Create: `app/Support/Approval/LedgerFilter.php`・`app/Support/Approval/Ledger.php`・`app/Support/Approval/LedgerRow.php`
- Modify: `app/Models/ApprovalRequest.php`（`lastRevision()`）・`app/Enums/ApprovalStatus.php`（`mayDifferFromSubmission()`）・`app/Http/Controllers/Approval/RelatedNumberController.php`（それを使う。中身は同じ）
- Test: Create `tests/Feature/Approval/Phase4/LedgerFilterTest.php`・`LedgerQueryTest.php`・`LedgerRowTest.php`

**Interfaces:**
- Consumes: なし（Task 1 と独立。`RequestVisibility::apply()`・`ApprovalFiscalYear`・`JapanTime` は既存）
- Produces: `LedgerFilter::fromRequest(Request): LedgerFilter`・`LedgerFilter::defaults(): LedgerFilter`・`within(list<int> $years, list<int> $departmentIds, list<int> $typeIds): LedgerFilter`（選択肢に無い値を外して `ignored` に足した写し）・公開の読み取り専用 `?int $year`・`?int $departmentId`・`?int $typeId`・`?string $applicant`・`?CarbonImmutable $decidedFrom`・`?CarbonImmutable $decidedTo`・`string $status`・`?ApprovalDecision $decision`・`?string $keyword`・`list<string> $ignored`・`query(): array<string, string|int>`（リンクに付ける形）・`toLog(): array`（記録の 9 項目）・`terms(?string): list<string>`・定数 `STATUSES`・`DEFAULT_STATUS`・`KEYWORD_MAX`（100）・`APPLICANT_MAX`（50）
- Produces: `Ledger::PER_PAGE`（50）・`Ledger::query(User, LedgerFilter): Builder`・`Ledger::sortedIds(User, LedgerFilter): list<int>`・`Ledger::rows(User, list<int>): Collection<int, LedgerRow>`・`Ledger::years(): array<int, string>`。Task 3・4 が使う
- Produces: `LedgerRow`（公開の読み取り専用 `int $id`・`?string $number`・`?CarbonInterface $decidedAt`・`?string $decisionLabel`・`?string $subject`・`?string $typeName`・`?string $departmentName`・`?string $applicantName`・`?int $amount`・`?string $schedule`・`list<string> $relatedNumbers`・`?CarbonInterface $submittedAt`・`?string $reviewResult`・`?string $reviewComment`・`?string $condition`・`string $statusLabel`・`string $statusStyle`・`amountLabel(): ?string`）
- Produces: `ApprovalRequest::lastRevision(): HasOne`・`ApprovalStatus::mayDifferFromSubmission(): list<ApprovalStatus>`（差戻し中・取り下げ）

**差分の大きさ:** 9 ファイル・+1227 / −1 行（差分のファイル `0002-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase4/LedgerFilterTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalDecision;
use App\Support\Approval\LedgerFilter;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 決裁台帳の絞り込みの条件の読み取り（段階4 設計書 §5.8・§9 の 9）。読み取れない値は断らずに外し、外した項目の名前を残す。
 */
class LedgerFilterTest extends TestCase
{
    /** @param array<string, mixed> $query */
    private function filter(array $query): LedgerFilter
    {
        return LedgerFilter::fromRequest(Request::create('/approvals/ledger', 'GET', $query));
    }

    public function test_nothing_given_is_the_default(): void
    {
        $filter = $this->filter([]);

        $this->assertSame(
            [null, null, null, null, null, null, 'numbered', null, null, []],
            [$filter->year, $filter->departmentId, $filter->typeId, $filter->applicant, $filter->decidedFrom, $filter->decidedTo, $filter->status, $filter->decision, $filter->keyword, $filter->ignored]
        );
        $this->assertSame([], $filter->query());
        $this->assertEquals(LedgerFilter::defaults(), $filter);
    }

    public function test_empty_values_are_not_conditions(): void
    {
        $filter = $this->filter(['year' => '', 'q' => '', 'status' => '']);

        $this->assertSame([null, null, 'numbered', [], []], [$filter->year, $filter->keyword, $filter->status, $filter->ignored, $filter->query()]);
    }

    public function test_each_condition_is_read(): void
    {
        $filter = $this->filter([
            'year' => '2026', 'department' => '3', 'type' => '12', 'applicant' => '山田', 'from' => '2026-05-01', 'to' => '2026-10-05',
            'status' => 'all', 'decision' => 'conditional', 'q' => '社用車',
        ]);

        $this->assertSame(
            [2026, 3, 12, '山田', '2026-05-01 00:00 Asia/Tokyo', '2026-10-05 00:00 Asia/Tokyo', 'all', ApprovalDecision::Conditional, '社用車', []],
            [$filter->year, $filter->departmentId, $filter->typeId, $filter->applicant, $filter->decidedFrom?->format('Y-m-d H:i e'), $filter->decidedTo?->format('Y-m-d H:i e'), $filter->status, $filter->decision, $filter->keyword, $filter->ignored]
        );
        $this->assertSame(
            ['year' => 2026, 'department' => 3, 'type' => 12, 'applicant' => '山田', 'from' => '2026-05-01', 'to' => '2026-10-05', 'status' => 'all', 'decision' => 'conditional', 'q' => '社用車'],
            $filter->query()
        );
    }

    public function test_unreadable_values_are_left_out_and_named(): void
    {
        $filter = $this->filter([
            'year' => '26', 'department' => '0', 'type' => '-1', 'applicant' => str_repeat('山', LedgerFilter::APPLICANT_MAX + 1),
            'from' => '2026-02-30', 'to' => '2026/10/05', 'status' => 'draft', 'decision' => 'return', 'q' => str_repeat('車', LedgerFilter::KEYWORD_MAX + 1),
        ]);

        $this->assertSame(
            [null, null, null, null, null, null, 'numbered', null, null],
            [$filter->year, $filter->departmentId, $filter->typeId, $filter->applicant, $filter->decidedFrom, $filter->decidedTo, $filter->status, $filter->decision, $filter->keyword]
        );
        $this->assertSame(['年度', '申請部門', '申請の種類', '申請者', '決裁日（から）', '決裁日（まで）', '状態', '判断', 'キーワード'], $filter->ignored);
        $this->assertSame([], $filter->query());
    }

    public function test_the_longest_values_are_still_read(): void
    {
        $filter = $this->filter(['applicant' => str_repeat('山', LedgerFilter::APPLICANT_MAX), 'q' => str_repeat('車', LedgerFilter::KEYWORD_MAX)]);

        $this->assertSame([LedgerFilter::APPLICANT_MAX, LedgerFilter::KEYWORD_MAX, []], [mb_strlen($filter->applicant), mb_strlen($filter->keyword), $filter->ignored]);
    }

    public function test_arrays_are_left_out_rather_than_breaking_the_page(): void
    {
        $filter = $this->filter(['q' => ['車'], 'year' => ['2026'], 'status' => ['all']]);

        $this->assertSame([null, null, 'numbered', ['年度', '状態', 'キーワード']], [$filter->keyword, $filter->year, $filter->status, $filter->ignored]);
    }

    public function test_broken_text_is_left_out(): void
    {
        // 不正な UTF-8（手で打った URL）。そのままだと語に分けられず、記録の JSON にもできない
        $filter = $this->filter(['q' => "\xFF", 'applicant' => "山\xC3"]);

        $this->assertSame([null, null, ['申請者', 'キーワード']], [$filter->keyword, $filter->applicant, $filter->ignored]);
    }

    public function test_values_outside_the_choices_are_left_out(): void
    {
        $filter = $this->filter(['year' => '1990', 'department' => '99999', 'type' => '7', 'from' => '2026-02-30'])->within([2026, 2025], [1, 2], [7]);

        $this->assertSame([null, null, 7], [$filter->year, $filter->departmentId, $filter->typeId]);
        $this->assertSame(['決裁日（から）', '年度', '申請部門'], $filter->ignored, '読めなかった項目に足す');

        $kept = $this->filter(['year' => '2025', 'department' => '2'])->within([2026, 2025], [1, 2], []);
        $this->assertSame([2025, 2, []], [$kept->year, $kept->departmentId, $kept->ignored]);

        $type = $this->filter(['type' => '8'])->within([], [], [7]);
        $this->assertSame([null, ['申請の種類']], [$type->typeId, $type->ignored]);
    }

    public function test_the_log_keeps_every_key_in_the_same_order(): void
    {
        $this->assertSame(
            ['year' => null, 'department_id' => null, 'type_id' => null, 'applicant' => null, 'decided_from' => null, 'decided_to' => null, 'status' => 'numbered', 'decision' => null, 'keyword' => null],
            $this->filter([])->toLog()
        );
        $this->assertSame(
            ['year' => 2026, 'department_id' => 3, 'type_id' => 12, 'applicant' => '山田', 'decided_from' => '2026-05-01', 'decided_to' => '2026-10-05', 'status' => 'progress', 'decision' => 'reject', 'keyword' => '社用車'],
            $this->filter(['year' => '2026', 'department' => '3', 'type' => '12', 'applicant' => '山田', 'from' => '2026-05-01', 'to' => '2026-10-05', 'status' => 'progress', 'decision' => 'reject', 'q' => '社用車'])->toLog()
        );
    }

    public function test_words_are_split_on_half_and_full_width_spaces(): void
    {
        $this->assertSame(['山田', '太郎', '花子'], LedgerFilter::terms("山田 太郎　\t花子"));
        $this->assertSame([], LedgerFilter::terms(null));
        $this->assertSame(['車'], LedgerFilter::terms('車'));
    }
}
```

`tests/Feature/Approval/Phase4/LedgerQueryTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\Ledger;
use App\Support\Approval\LedgerFilter;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 決裁台帳の問い合わせ（要件 7 章・10 章・段階4 設計書 §5.8・D19〜D22）。
 *
 * ⚠ 見られる範囲の規則そのものは RequestVisibilityTest が見る。ここは台帳がその規則を通すこと・下書きを出さないこと・
 *   各絞り込み・中身の出し分け（D19）・年度（D20）・並び（D22）を見る。
 */
class LedgerQueryTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 01:00:00', 'UTC'));   // 日本時間 10/5 10:00
        $this->workflow = app(Workflow::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, string> $query */
    private function ids(User $viewer, array $query = []): array
    {
        return Ledger::sortedIds($viewer, LedgerFilter::fromRequest(Request::create('/approvals/ledger', 'GET', $query)));
    }

    /** 提出した申請の状態の列を書き換える（状態の列は $fillable に無い。BuildsApprovalFixtures の注意） */
    private function made(array $w, array $columns = [], array $attributes = []): int
    {
        $r = $this->submittedFor($w, $attributes);
        if ($columns !== []) {
            DB::table('approval_requests')->where('id', $r->id)->update($columns);
        }

        return $r->id;
    }

    /** 部門長・審査・社長と回して決裁する */
    private function decided(array $w, ApprovalStepResult $result = ApprovalStepResult::Approve, array $attributes = []): ApprovalRequest
    {
        $r = $this->submittedFor($w, $attributes);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->judgeReview($r->fresh(), $w['reviewer'], $r->fresh()->lock_version, ApprovalStepResult::Ok, null);
        $this->workflow->judgePresident($r->fresh(), $w['president'], $r->fresh()->lock_version, $result, $result === ApprovalStepResult::Approve ? null : '条件・理由');

        return $r->fresh();
    }

    /** 部門長が差し戻した申請 */
    private function returned(array $w, array $attributes = []): ApprovalRequest
    {
        $r = $this->submittedFor($w, $attributes);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Return, '見直してください');

        return $r->fresh();
    }

    public function test_drafts_are_never_listed_even_for_their_applicant(): void
    {
        $w     = $this->approvalWorld();
        $draft = $this->draftFor($w);
        $sent  = $this->made($w);

        $this->assertSame([$sent], $this->ids($w['applicant'], ['status' => 'all']));
        $this->assertNotContains($draft->id, $this->ids($this->viewAllUser(), ['status' => 'all']));
    }

    public function test_it_lists_only_what_the_viewer_may_see(): void
    {
        $w        = $this->approvalWorld();
        $mine     = $this->made($w);
        $other    = $this->approvalOnlyUser(['name' => '別部門 太郎']);
        $outside  = $this->approvalDepartment($w['company'], ['name' => '別部門', 'code' => 'B', 'head_user_id' => $this->baseUser()->id]);
        $other->approvalDepartments()->attach($outside->id);
        $elsewhere = $this->submittedFor(array_merge($w, ['applicant' => $other->fresh(), 'dept' => $outside]))->id;

        $this->assertSame([$elsewhere, $mine], $this->ids($this->viewAllUser(), ['status' => 'all']));
        $this->assertSame([$mine], $this->ids($w['applicant'], ['status' => 'all']), '申請者は自分の申請だけ');
        $this->assertSame([$mine], $this->ids($w['head'], ['status' => 'all']), '部門長は自分の部門の申請だけ');
        $this->assertSame([], $this->ids($this->baseUser(), ['status' => 'all']), '関わっていない人には何も出ない');
    }

    public function test_the_default_is_the_numbered_ones(): void
    {
        $w        = $this->approvalWorld();
        $progress = $this->made($w);
        $approved = $this->decided($w)->id;
        $rejected = $this->decided($w, ApprovalStepResult::Reject)->id;
        $waiting  = $this->decided($w, ApprovalStepResult::Conditional)->id;
        $missing  = $this->made($w, ['status' => ApprovalStatus::Withdrawn->value, 'number' => 'R8-J-099', 'number_department_id' => $w['dept']->id, 'number_fiscal_year' => 2026, 'number_seq' => 99]);
        $dropped  = $this->made($w, ['status' => ApprovalStatus::Withdrawn->value]);
        $viewer   = $this->viewAllUser();

        $default = $this->ids($viewer);
        sort($default);
        $this->assertSame([$approved, $rejected, $waiting, $missing], $default, '決裁済み・否決・条件確認待ち・番号のあと取り下げた欠番');
        $this->assertSame($default, $this->sorted($this->ids($viewer, ['status' => 'numbered'])));
        $this->assertSame([$progress], $this->ids($viewer, ['status' => 'progress']));
        $this->assertSame([$missing, $dropped], $this->sorted($this->ids($viewer, ['status' => 'withdrawn'])));
        $this->assertCount(6, $this->ids($viewer, ['status' => 'all']));
    }

    public function test_in_progress_includes_every_step_and_a_returned_request(): void
    {
        $w        = $this->approvalWorld();
        $head     = $this->made($w);
        $review   = $this->made($w, ['status' => ApprovalStatus::Review->value]);
        $president = $this->made($w, ['status' => ApprovalStatus::President->value, 'number' => 'R8-J-050', 'number_fiscal_year' => 2026]);
        $returned = $this->returned($w)->id;

        $this->assertSame([$head, $review, $president, $returned], $this->sorted($this->ids($this->viewAllUser(), ['status' => 'progress'])), '取り消しで番号が残ったまま戻った申請も進行中');
    }

    public function test_the_decision_filter(): void
    {
        $w           = $this->approvalWorld();
        $approved    = $this->decided($w)->id;
        $conditional = $this->decided($w, ApprovalStepResult::Conditional)->id;
        $rejected    = $this->decided($w, ApprovalStepResult::Reject)->id;
        $viewer      = $this->viewAllUser();

        $this->assertSame([$approved], $this->ids($viewer, ['decision' => 'approve']));
        $this->assertSame([$conditional], $this->ids($viewer, ['decision' => 'conditional']));
        $this->assertSame([$rejected], $this->ids($viewer, ['decision' => 'reject']));
    }

    public function test_the_decided_dates_are_days_of_the_japanese_calendar(): void
    {
        $w      = $this->approvalWorld();
        $before = $this->made($w, ['status' => ApprovalStatus::Approved->value, 'number' => 'R8-J-001', 'decided_at' => '2026-10-04 14:59:59']);   // 日本時間 10/4 23:59
        $first  = $this->made($w, ['status' => ApprovalStatus::Approved->value, 'number' => 'R8-J-002', 'decided_at' => '2026-10-04 15:00:00']);   // 日本時間 10/5 0:00
        $last   = $this->made($w, ['status' => ApprovalStatus::Approved->value, 'number' => 'R8-J-003', 'decided_at' => '2026-10-05 14:59:59']);   // 日本時間 10/5 23:59
        $after  = $this->made($w, ['status' => ApprovalStatus::Approved->value, 'number' => 'R8-J-004', 'decided_at' => '2026-10-05 15:00:00']);   // 日本時間 10/6 0:00
        $viewer = $this->viewAllUser();

        $this->assertSame([$first, $last], $this->sorted($this->ids($viewer, ['from' => '2026-10-05', 'to' => '2026-10-05'])));
        $this->assertSame([$first, $last, $after], $this->sorted($this->ids($viewer, ['from' => '2026-10-05'])));
        $this->assertSame([$before, $first, $last], $this->sorted($this->ids($viewer, ['to' => '2026-10-05'])));
    }

    public function test_the_applicant_is_found_by_part_of_the_name_and_by_every_word(): void
    {
        $w        = $this->approvalWorld();
        $hanako   = $this->decided($w)->id;   // 申請 花子
        $taro     = $this->approvalOnlyUser(['name' => '山田 太郎']);
        $taro->approvalDepartments()->attach($w['dept']->id);
        $yamada   = $this->decided(array_merge($w, ['applicant' => $taro->fresh()]))->id;
        $viewer   = $this->viewAllUser();

        $this->assertSame([$yamada], $this->ids($viewer, ['applicant' => '山田']));
        $this->assertSame([$yamada], $this->ids($viewer, ['applicant' => '田　郎']), '全角の空白で分けた語のどれも含む');
        $this->assertSame([], $this->ids($viewer, ['applicant' => '山田 花子']), 'どれも含まないと当たらない');
        $this->assertSame([$hanako], $this->ids($viewer, ['applicant' => '花子']));

        $taro->delete();
        $this->assertSame([$yamada], $this->ids($viewer, ['applicant' => '山田']), '退職して消した人の申請も探せる');
    }

    public function test_the_keyword_is_found_in_the_subject_or_the_body_with_every_word(): void
    {
        $w      = $this->approvalWorld();
        $car    = $this->decided($w, attributes: ['subject' => '社用車の購入', 'body' => '老朽化のため'])->id;
        $desk   = $this->decided($w, attributes: ['subject' => '机の購入', 'body' => "社用車の駐車場の横に置く\n"])->id;
        $viewer = $this->viewAllUser();

        $this->assertSame([$car, $desk], $this->sorted($this->ids($viewer, ['q' => '社用車'])), '件名か本文');
        $this->assertSame([$desk], $this->ids($viewer, ['q' => '駐車場 机']), 'どの語も含む（件名と本文にまたがってよい）');
        $this->assertSame([$car], $this->ids($viewer, ['q' => '老朽化']));
    }

    public function test_the_department_and_the_type_filters(): void
    {
        $w      = $this->approvalWorld();
        $mine   = $this->decided($w)->id;
        $other  = $this->approvalDepartment($w['company'], ['name' => '別部門', 'code' => 'B', 'head_user_id' => $w['head']->id]);
        $w['applicant']->approvalDepartments()->attach($other->id);
        $type   = $this->approvalType($w['reviewDept'], ['name' => '工事の発注']);
        $theirs = $this->decided(array_merge($w, ['dept' => $other, 'type' => $type]))->id;
        $viewer = $this->viewAllUser();

        $this->assertSame([$mine], $this->ids($viewer, ['department' => (string) $w['dept']->id]));
        $this->assertSame([$theirs], $this->ids($viewer, ['department' => (string) $other->id]));
        $this->assertSame([$theirs], $this->ids($viewer, ['type' => (string) $type->id]));
        $this->assertSame([$mine], $this->ids($viewer, ['type' => (string) $w['type']->id]));
    }

    public function test_others_search_what_was_last_submitted_while_it_is_returned(): void
    {
        $w     = $this->approvalWorld();
        $other = $this->approvalDepartment($w['company'], ['name' => '別部門', 'code' => 'B', 'head_user_id' => $this->baseUser()->id]);
        $type  = $this->approvalType($w['reviewDept'], ['name' => '工事の発注']);
        $r     = $this->returned($w, ['subject' => '出した件名', 'body' => '出した本文']);
        // 差戻しのあと、申請者が直しかけている（出し直していない）
        $r->update(['subject' => '直しかけの件名', 'body' => '直しかけの本文', 'department_id' => $other->id, 'type_id' => $type->id]);

        $others = $this->viewAllUser();
        foreach ([$others, $w['head']] as $viewer) {
            $this->assertSame([$r->id], $this->ids($viewer, ['status' => 'progress', 'q' => '出した件名']), '最後に提出した件名で当たる');
            $this->assertSame([$r->id], $this->ids($viewer, ['status' => 'progress', 'q' => '出した本文']));
            $this->assertSame([], $this->ids($viewer, ['status' => 'progress', 'q' => '直しかけ']), '直しかけの中身を検索に掛けない');
            $this->assertSame([$r->id], $this->ids($viewer, ['status' => 'progress', 'department' => (string) $w['dept']->id]));
            $this->assertSame([], $this->ids($viewer, ['status' => 'progress', 'department' => (string) $other->id]));
            $this->assertSame([$r->id], $this->ids($viewer, ['status' => 'progress', 'type' => (string) $w['type']->id]));
            $this->assertSame([], $this->ids($viewer, ['status' => 'progress', 'type' => (string) $type->id]));
        }

        // 申請者本人は今の中身で探す
        $me = $w['applicant'];
        $this->assertSame([$r->id], $this->ids($me, ['status' => 'progress', 'q' => '直しかけの件名']));
        $this->assertSame([$r->id], $this->ids($me, ['status' => 'progress', 'q' => '直しかけの本文']));
        $this->assertSame([], $this->ids($me, ['status' => 'progress', 'q' => '出した件名']));
        $this->assertSame([$r->id], $this->ids($me, ['status' => 'progress', 'department' => (string) $other->id]));
        $this->assertSame([$r->id], $this->ids($me, ['status' => 'progress', 'type' => (string) $type->id]));
    }

    public function test_a_request_withdrawn_after_edits_is_searched_by_what_was_last_submitted(): void
    {
        $w = $this->approvalWorld();
        $r = $this->returned($w, ['subject' => '出した件名']);
        $r->update(['subject' => '直しかけの件名']);
        $this->workflow->withdraw($r->fresh(), $w['applicant'], $r->fresh()->lock_version, null);

        $viewer = $this->viewAllUser();
        $this->assertSame([$r->id], $this->ids($viewer, ['status' => 'withdrawn', 'q' => '出した件名']));
        $this->assertSame([], $this->ids($viewer, ['status' => 'withdrawn', 'q' => '直しかけ']));
        $this->assertSame([$r->id], $this->ids($w['applicant'], ['status' => 'withdrawn', 'q' => '直しかけ']));
    }

    /** 2 回以上提出した申請は、最後に提出した回の控えで当てる（1 回目の控えや直しかけで当てない） */
    public function test_others_search_the_latest_of_several_submissions(): void
    {
        $w = $this->approvalWorld();
        $r = $this->returned($w, ['subject' => '件名いち']);
        $r->fresh()->update(['subject' => '件名に']);
        $this->workflow->submit($r->fresh(), $w['applicant'], $r->fresh()->lock_version);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Return, 'もう一度');
        $r->fresh()->update(['subject' => '件名さん']);

        $viewer = $this->viewAllUser();
        $this->assertSame([$r->id], $this->ids($viewer, ['status' => 'progress', 'q' => '件名に']));
        $this->assertSame([], $this->ids($viewer, ['status' => 'progress', 'q' => '件名いち']), '前の回の控えで当てない');
        $this->assertSame([], $this->ids($viewer, ['status' => 'progress', 'q' => '件名さん']), '直しかけで当てない');
    }

    /** 番号の無い申請の年度も、ほかの人には最後に提出した申請部門の会社の期で数える（直しかけの部門の期で数えない。D19・D20） */
    public function test_others_count_the_year_of_an_unnumbered_request_by_the_submitted_department(): void
    {
        $w       = $this->approvalWorld();   // 住宅事業部は 5 月始まりの会社
        $dad     = $this->approvalCompany(['name' => 'DAD', 'fiscal_start_month' => 6]);
        $dadDept = $this->approvalDepartment($dad, ['name' => '土木', 'code' => 'D', 'head_user_id' => $w['head']->id]);
        $r       = $this->returned($w);
        DB::table('approval_requests')->where('id', $r->id)->update(['last_submitted_at' => '2026-05-15 01:00:00']);   // 日本時間 5/15
        // 差戻し中に、6 月始まりの会社の部門へ直しかけている（5/15 は DAD では 2025 年度）
        $r->fresh()->update(['department_id' => $dadDept->id]);

        $viewer = $this->viewAllUser();
        $this->assertSame([$r->id], $this->ids($viewer, ['status' => 'progress', 'year' => '2026']));
        $this->assertSame([], $this->ids($viewer, ['status' => 'progress', 'year' => '2025']));
        $this->assertSame([$r->id], $this->ids($w['applicant'], ['status' => 'progress', 'year' => '2025']), '申請者本人は今の中身（直しかけの部門）で数える');
    }

    public function test_the_year_of_a_numbered_request_is_the_year_of_its_number(): void
    {
        $w = $this->approvalWorld();
        // 4/30 に付いた R7 の番号を取り消し、5/2 に判断し直しても R7 のまま（要件 6.5）
        $kept = $this->made($w, ['status' => ApprovalStatus::Approved->value, 'number' => 'R7-J-010', 'number_fiscal_year' => 2025, 'decided_at' => '2026-05-02 01:00:00', 'last_submitted_at' => '2026-05-01 01:00:00']);
        $viewer = $this->viewAllUser();

        $this->assertSame([$kept], $this->ids($viewer, ['year' => '2025']));
        $this->assertSame([], $this->ids($viewer, ['year' => '2026']));
    }

    public function test_the_year_of_an_unnumbered_request_follows_the_company_of_its_department(): void
    {
        $w   = $this->approvalWorld();   // 会社は 5 月始まり
        $dad = $this->approvalCompany(['name' => 'DAD', 'fiscal_start_month' => 6]);
        $dadDept = $this->approvalDepartment($dad, ['name' => '土木', 'code' => 'D', 'head_user_id' => $w['head']->id]);
        $w['applicant']->approvalDepartments()->attach($dadDept->id);

        $mayFirst = $this->made($w, ['last_submitted_at' => '2026-04-30 15:00:00']);   // 日本時間 5/1 0:00 → ミツワの 2026 年度
        $aprilEnd = $this->made($w, ['last_submitted_at' => '2026-04-30 14:59:59']);   // 日本時間 4/30 23:59 → 2025 年度
        $inMay    = $this->made(array_merge($w, ['dept' => $dadDept]), ['last_submitted_at' => '2026-05-15 01:00:00']);   // DAD は 6 月始まり → 2025 年度
        $viewer   = $this->viewAllUser();

        $this->assertSame([$mayFirst], $this->ids($viewer, ['status' => 'all', 'year' => '2026']));
        $this->assertSame([$aprilEnd, $inMay], $this->sorted($this->ids($viewer, ['status' => 'all', 'year' => '2025'])));
    }

    public function test_the_order_is_by_day_then_number_then_submission(): void
    {
        $w = $this->approvalWorld();
        $s = $w['reviewDept'];   // アルファベット S
        $j = $w['dept'];         // アルファベット J
        $number = fn (string $code, int $seq, int $deptId, string $decidedAt) => [
            'status' => ApprovalStatus::Approved->value, 'number' => "R8-{$code}-" . sprintf('%03d', $seq), 'number_department_id' => $deptId,
            'number_fiscal_year' => 2026, 'number_seq' => $seq, 'decided_at' => $decidedAt,
        ];

        // 日本時間の 10/5（UTC の 10/4 15:00〜10/5 14:59）に決裁したもの。時刻の順と番号の順をずらしてある
        $j10 = $this->made($w, $number('J', 10, $j->id, '2026-10-04 15:30:00'));
        $j2  = $this->made($w, $number('J', 2, $j->id, '2026-10-05 05:00:00'));
        $s1  = $this->made($w, $number('S', 1, $s->id, '2026-10-04 16:00:00'));
        // 同じ日に提出して番号の無いもの（発信の新しい順で、番号のあるものの後ろ）
        $older = $this->made($w, ['status' => ApprovalStatus::President->value, 'last_submitted_at' => '2026-10-05 00:10:00']);
        $newer = $this->made($w, ['status' => ApprovalStatus::President->value, 'last_submitted_at' => '2026-10-05 02:00:00']);
        // 前の日（日本時間 10/4 23:59）と次の日
        $yesterday = $this->made($w, $number('J', 1, $j->id, '2026-10-04 14:59:00'));
        $tomorrow  = $this->made($w, ['status' => ApprovalStatus::Review->value, 'last_submitted_at' => '2026-10-05 15:00:00']);

        $this->assertSame(
            [$tomorrow, $j2, $j10, $s1, $newer, $older, $yesterday],
            $this->ids($this->viewAllUser(), ['status' => 'all'])
        );
    }

    public function test_the_years_run_from_this_year_back_to_the_oldest_request(): void
    {
        $w = $this->approvalWorld();
        $this->assertSame([2026 => 'R8 年度（2026）'], Ledger::years(), '申請が無ければ今の年度だけ');

        $this->made($w, ['number' => 'R6-J-001', 'number_fiscal_year' => 2024]);
        $this->assertSame([2026, 2025, 2024], array_keys(Ledger::years()));

        $this->made($w, ['last_submitted_at' => '2023-05-31 01:00:00']);   // 6 月始まりの会社なら 2022 年度
        $this->approvalCompany(['name' => 'DAD', 'fiscal_start_month' => 6]);
        $this->assertSame([2026, 2025, 2024, 2023, 2022], array_keys(Ledger::years()));
    }

    /** @param list<int> $ids @return list<int> */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
```

`tests/Feature/Approval/Phase4/LedgerRowTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\User;
use App\Support\Approval\Ledger;
use App\Support\Approval\LedgerRow;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 決裁台帳の 1 行の中身（要件 10・段階4 設計書 §5.8・§5.9・D19・D23）。
 */
class LedgerRowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-04 16:12:00', 'UTC'));   // 日本時間 10/5 1:12
        $this->workflow = app(Workflow::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function row(User $viewer, ApprovalRequest $r): LedgerRow
    {
        return Ledger::rows($viewer, [$r->id])->sole();
    }

    private function toPresident(array $w, array $attributes = [], ApprovalStepResult $review = ApprovalStepResult::Hold, ?string $reviewComment = '見積を 2 社取ってください'): ApprovalRequest
    {
        $r = $this->submittedFor($w, $attributes);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->judgeReview($r->fresh(), $w['reviewer'], $r->fresh()->lock_version, $review, $reviewComment);

        return $r->fresh();
    }

    public function test_a_decided_row(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w, ['related_numbers' => ['R7-J-003', 'R7-J-010']]);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Conditional, '納期を確かめること');

        $row = $this->row($this->viewAllUser(), $r->fresh());

        $this->assertSame(
            [$r->id, 'R8-J-001', '2026-10-04 16:12:00', '条可', '社用車の購入', $w['type']->name, '住宅事業部', '申請 花子', 2850000, '2,850,000円', '2026年10月', ['R7-J-003', 'R7-J-010'], '2026-10-04 16:12:00'],
            [$row->id, $row->number, $row->decidedAt?->format('Y-m-d H:i:s'), $row->decisionLabel, $row->subject, $row->typeName, $row->departmentName, $row->applicantName, $row->amount, $row->amountLabel(), $row->schedule, $row->relatedNumbers, $row->submittedAt?->format('Y-m-d H:i:s')]
        );
        $this->assertSame(['保留', '見積を 2 社取ってください', '納期を確かめること', '条件確認待ち'], [$row->reviewResult, $row->reviewComment, $row->condition, $row->statusLabel]);
    }

    public function test_the_condition_is_only_for_a_conditional_decision(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w, review: ApprovalStepResult::Ok, reviewComment: null);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Approve, '了承');

        $row = $this->row($this->viewAllUser(), $r->fresh());

        $this->assertSame(['可', null, null, '可', '決裁済み（可）'], [$row->reviewResult, $row->reviewComment, $row->condition, $row->decisionLabel, $row->statusLabel]);
    }

    public function test_others_see_what_was_last_submitted_and_the_applicant_sees_the_current_content(): void
    {
        $w     = $this->approvalWorld();
        $other = $this->approvalDepartment($w['company'], ['name' => '別部門', 'code' => 'B']);
        $type  = $this->approvalType($w['reviewDept'], ['name' => '工事の発注']);
        $r     = $this->submittedFor($w, ['subject' => '出した件名', 'amount' => 100, 'schedule' => '来月', 'related_numbers' => ['R7-J-001']]);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Return, '見直してください');
        $r->fresh()->update(['subject' => '直しかけ', 'amount' => 999, 'schedule' => '再来月', 'related_numbers' => ['R7-J-002'], 'department_id' => $other->id, 'type_id' => $type->id]);
        // あとで部門の名前を変えても、ほかの人には提出したときの名前が出る
        $w['dept']->update(['name' => '住宅事業本部']);

        $theirs = $this->row($w['head'], $r->fresh());
        $this->assertSame(['出した件名', 100, '来月', ['R7-J-001'], '住宅事業部', $w['type']->name],
            [$theirs->subject, $theirs->amount, $theirs->schedule, $theirs->relatedNumbers, $theirs->departmentName, $theirs->typeName]);

        $mine = $this->row($w['applicant'], $r->fresh());
        $this->assertSame(['直しかけ', 999, '再来月', ['R7-J-002'], '別部門', '工事の発注'],
            [$mine->subject, $mine->amount, $mine->schedule, $mine->relatedNumbers, $mine->departmentName, $mine->typeName]);
    }

    public function test_others_see_the_latest_of_several_submissions(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w, ['subject' => '件名いち']);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Return, '見直してください');
        $r->fresh()->update(['subject' => '件名に']);
        $this->workflow->submit($r->fresh(), $w['applicant'], $r->fresh()->lock_version);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Return, 'もう一度');
        $r->fresh()->update(['subject' => '件名さん']);

        $this->assertSame('件名に', $this->row($w['head'], $r->fresh())->subject, '最後に提出した回（2 回目）の控え');
        $this->assertSame('件名さん', $this->row($w['applicant'], $r->fresh())->subject);
    }

    public function test_others_get_nothing_rather_than_the_current_content_when_the_revision_is_missing(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w, ['subject' => '今の件名']);
        // 控えは提出と同じトランザクションで作るので本来は必ずある。無いときに今の中身へ落とさない（直しかけを漏らさない）
        DB::table('approval_revisions')->where('request_id', $r->id)->delete();
        $this->assertSame(0, ApprovalRevision::count());

        $row = $this->row($w['head'], $r->fresh());

        $this->assertSame([null, null, null, null, []], [$row->subject, $row->amount, $row->departmentName, $row->typeName, $row->relatedNumbers]);
        $this->assertSame('申請 花子', $row->applicantName);
    }

    public function test_a_deleted_applicant_still_has_a_name(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $w['applicant']->delete();

        $this->assertSame('申請 花子', $this->row($this->viewAllUser(), $r->fresh())->applicantName, '退職して消した人の申請も名前が出る');
    }

    public function test_the_review_and_the_condition_come_from_the_current_round(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Return, '金額を見直して');
        $this->workflow->submit($r->fresh(), $w['applicant'], $r->fresh()->lock_version);

        $row = $this->row($this->viewAllUser(), $r->fresh());

        $this->assertSame([null, null, null, '部門長確認中'], [$row->reviewResult, $row->reviewComment, $row->condition, $row->statusLabel], '前の回の審査の意見を出さない');
    }

    public function test_rows_keep_the_given_order_and_read_everything_in_a_few_queries(): void
    {
        $w   = $this->approvalWorld();
        $ids = [];
        foreach (range(1, 3) as $i) {
            $ids[] = $this->submittedFor($w, ['subject' => "件名{$i}"])->id;
        }
        $ids = array_reverse($ids);

        $viewer  = $this->viewAllUser();
        $queries = 0;
        DB::listen(function () use (&$queries): void { $queries++; });
        $rows = Ledger::rows($viewer, $ids);

        $this->assertSame($ids, $rows->pluck('id')->all());
        $this->assertSame(['件名3', '件名2', '件名1'], $rows->pluck('subject')->all());
        $this->assertLessThanOrEqual(6, $queries, '申請ごとに問い合わせている（N+1）');
    }
}
```

（差分のファイルを使うなら: `… git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/4b/patches/0002-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase4/LedgerFilterTest.php tests/Feature/Approval/Phase4/LedgerQueryTest.php tests/Feature/Approval/Phase4/LedgerRowTest.php
```

Expected: `ERRORS!` `Tests: 35, Assertions: 1, Errors: 35.`

- `LedgerFilterTest::test_nothing_given_is_the_default` — `Error: Class "App\Support\Approval\LedgerFilter" not found`
- `LedgerFilterTest::test_empty_values_are_not_conditions` — `Error: Class "App\Support\Approval\LedgerFilter" not found`
- `LedgerFilterTest::test_each_condition_is_read` — `Error: Class "App\Support\Approval\LedgerFilter" not found`
- `LedgerFilterTest::test_unreadable_values_are_left_out_and_named` — `Error: Class "App\Support\Approval\LedgerFilter" not found`
- `LedgerFilterTest::test_the_longest_values_are_still_read` — `Error: Class "App\Support\Approval\LedgerFilter" not found`
- `LedgerFilterTest::test_arrays_are_left_out_rather_than_breaking_the_page` — `Error: Class "App\Support\Approval\LedgerFilter" not found`
- `LedgerFilterTest::test_broken_text_is_left_out` — `Error: Class "App\Support\Approval\LedgerFilter" not found`
- `LedgerFilterTest::test_values_outside_the_choices_are_left_out` — `Error: Class "App\Support\Approval\LedgerFilter" not found`
- `LedgerFilterTest::test_the_log_keeps_every_key_in_the_same_order` — `Error: Class "App\Support\Approval\LedgerFilter" not found`
- `LedgerFilterTest::test_words_are_split_on_half_and_full_width_spaces` — `Error: Class "App\Support\Approval\LedgerFilter" not found`
- `LedgerQueryTest::test_drafts_are_never_listed_even_for_their_applicant` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerQueryTest::test_it_lists_only_what_the_viewer_may_see` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerQueryTest::test_the_default_is_the_numbered_ones` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerQueryTest::test_in_progress_includes_every_step_and_a_returned_request` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerQueryTest::test_the_decision_filter` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerQueryTest::test_the_decided_dates_are_days_of_the_japanese_calendar` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerQueryTest::test_the_applicant_is_found_by_part_of_the_name_and_by_every_word` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerQueryTest::test_the_keyword_is_found_in_the_subject_or_the_body_with_every_word` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerQueryTest::test_the_department_and_the_type_filters` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerQueryTest::test_others_search_what_was_last_submitted_while_it_is_returned` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerQueryTest::test_a_request_withdrawn_after_edits_is_searched_by_what_was_last_submitted` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerQueryTest::test_others_search_the_latest_of_several_submissions` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerQueryTest::test_others_count_the_year_of_an_unnumbered_request_by_the_submitted_department` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerQueryTest::test_the_year_of_a_numbered_request_is_the_year_of_its_number` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerQueryTest::test_the_year_of_an_unnumbered_request_follows_the_company_of_its_department` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerQueryTest::test_the_order_is_by_day_then_number_then_submission` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerQueryTest::test_the_years_run_from_this_year_back_to_the_oldest_request` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerRowTest::test_a_decided_row` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerRowTest::test_the_condition_is_only_for_a_conditional_decision` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerRowTest::test_others_see_what_was_last_submitted_and_the_applicant_sees_the_current_content` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerRowTest::test_others_see_the_latest_of_several_submissions` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerRowTest::test_others_get_nothing_rather_than_the_current_content_when_the_revision_is_missing` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerRowTest::test_a_deleted_applicant_still_has_a_name` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerRowTest::test_the_review_and_the_condition_come_from_the_current_round` — `Error: Class "App\Support\Approval\Ledger" not found`
- `LedgerRowTest::test_rows_keep_the_given_order_and_read_everything_in_a_few_queries` — `Error: Class "App\Support\Approval\Ledger" not found`

- [ ] **Step 3: 絞り込みの条件・問い合わせ・1 行の中身を作る**

`app/Support/Approval/LedgerFilter.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Enums\ApprovalDecision;
use App\Support\JapanTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * 決裁台帳（⑤）の絞り込みの条件（要件 10・段階4 設計書 §5.8・D19〜D21）。画面・Excel・出力の記録が同じものを使う。
 *
 * ⚠ 台帳は GET のフォーム。読み取れない値（形の違う日付・長すぎる文字・配列など）は**断らずに外し**、外した項目の名前を
 *   `ignored` に残して画面で知らせる。入力の検査（validate()）で前の画面へ戻すと、GET の戻り先がこの画面そのものになり、
 *   同じ URL を開き直し続ける（前の URL はセッションに今の GET が入る）。
 * ⚠ 前後の空白（全角を含む）はアプリ全体の前処理 TrimStrings が外し、空は ConvertEmptyStringsToNull が null にする。
 */
final class LedgerFilter
{
    /** 状態の絞り込み（キーは URL の `?status=`。既定は決裁No の付いたもの。§5.8） */
    public const STATUSES = [
        'numbered'  => '決裁No の付いたもの',
        'progress'  => '進行中',
        'withdrawn' => '取り下げ',
        'all'       => 'すべて',
    ];

    public const DEFAULT_STATUS = 'numbered';

    /** キーワード・申請者の文字数の上限（超えたら外して知らせる。画面の maxlength と同じ） */
    public const KEYWORD_MAX = 100;

    public const APPLICANT_MAX = 50;

    /** 外した項目の名前（画面の知らせに出す） */
    private const LABELS = [
        'year'       => '年度',
        'department' => '申請部門',
        'type'       => '申請の種類',
        'applicant'  => '申請者',
        'from'       => '決裁日（から）',
        'to'         => '決裁日（まで）',
        'status'     => '状態',
        'decision'   => '判断',
        'q'          => 'キーワード',
    ];

    /** @param list<string> $ignored 読み取れずに外した項目の名前 */
    private function __construct(
        public readonly ?int $year,
        public readonly ?int $departmentId,
        public readonly ?int $typeId,
        public readonly ?string $applicant,
        public readonly ?CarbonImmutable $decidedFrom,
        public readonly ?CarbonImmutable $decidedTo,
        public readonly string $status,
        public readonly ?ApprovalDecision $decision,
        public readonly ?string $keyword,
        public readonly array $ignored,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $ignored = [];
        $read    = function (string $key, callable $parse) use ($request, &$ignored): mixed {
            $raw = $request->query($key);
            if ($raw === null || $raw === '') {
                return null;
            }
            // 壊れた文字（不正な UTF-8。手で打った URL）も外す。そのままだと語に分けられず黙って外れ、記録の JSON にもできない
            $value = is_string($raw) && mb_check_encoding($raw, 'UTF-8') ? $parse($raw) : null;
            if ($value === null) {
                $ignored[] = self::LABELS[$key];
            }

            return $value;
        };

        $year       = $read('year', fn (string $v) => preg_match('/^\d{4}$/', $v) ? (int) $v : null);
        $department = $read('department', self::positiveInt(...));
        $type       = $read('type', self::positiveInt(...));
        $applicant  = $read('applicant', fn (string $v) => mb_strlen($v) <= self::APPLICANT_MAX ? $v : null);
        $from       = $read('from', self::calendarDay(...));
        $to         = $read('to', self::calendarDay(...));
        $status     = $read('status', fn (string $v) => array_key_exists($v, self::STATUSES) ? $v : null);
        $decision   = $read('decision', fn (string $v) => ApprovalDecision::tryFrom($v));
        $keyword    = $read('q', fn (string $v) => mb_strlen($v) <= self::KEYWORD_MAX ? $v : null);

        return new self($year, $department, $type, $applicant, $from, $to, $status ?? self::DEFAULT_STATUS, $decision, $keyword, $ignored);
    }

    /** 既定のまま（何も絞り込んでいない。状態は決裁No の付いたもの） */
    public static function defaults(): self
    {
        return new self(null, null, null, null, null, null, self::DEFAULT_STATUS, null, null, []);
    }

    /**
     * 画面の選択肢に無い年度・部門・種類を外す（手で打った URL や古いリンク）。外さないと、条件としては効いて 0 件になるのに
     * プルダウンは「すべて」に見え、プルダウンを 1 つ変えるとその条件が黙って外れる
     *
     * @param list<int> $years
     * @param list<int> $departmentIds
     * @param list<int> $typeIds
     */
    public function within(array $years, array $departmentIds, array $typeIds): self
    {
        $ignored = $this->ignored;
        $keep    = function (?int $value, array $choices, string $key) use (&$ignored): ?int {
            if ($value === null || in_array($value, $choices, true)) {
                return $value;
            }
            $ignored[] = self::LABELS[$key];

            return null;
        };

        $year       = $keep($this->year, $years, 'year');
        $department = $keep($this->departmentId, $departmentIds, 'department');
        $type       = $keep($this->typeId, $typeIds, 'type');

        return new self($year, $department, $type, $this->applicant, $this->decidedFrom, $this->decidedTo, $this->status, $this->decision, $this->keyword, $ignored);
    }

    /**
     * 画面のリンク（ページ送り・Excel）に付ける形。読み取れた条件だけで、空と既定の状態は付けない
     *
     * @return array<string, string|int>
     */
    public function query(): array
    {
        return array_filter([
            'year'       => $this->year,
            'department' => $this->departmentId,
            'type'       => $this->typeId,
            'applicant'  => $this->applicant,
            'from'       => $this->decidedFrom?->format('Y-m-d'),
            'to'         => $this->decidedTo?->format('Y-m-d'),
            'status'     => $this->status === self::DEFAULT_STATUS ? null : $this->status,
            'decision'   => $this->decision?->value,
            'q'          => $this->keyword,
        ], fn (mixed $value) => $value !== null);
    }

    /**
     * 出力の記録に控える形（D25・§9 の 9）。項目はいつも同じで、使っていない条件は null（あとで読むときに迷わない）。
     * ⚠ MySQL の JSON はキーを並べ替えて返す（キーの長さの順。RequestSnapshot の注意）ので、読むときに並びに頼らない
     *
     * @return array{year: ?int, department_id: ?int, type_id: ?int, applicant: ?string, decided_from: ?string, decided_to: ?string, status: string, decision: ?string, keyword: ?string}
     */
    public function toLog(): array
    {
        return [
            'year'          => $this->year,
            'department_id' => $this->departmentId,
            'type_id'       => $this->typeId,
            'applicant'     => $this->applicant,
            'decided_from'  => $this->decidedFrom?->format('Y-m-d'),
            'decided_to'    => $this->decidedTo?->format('Y-m-d'),
            'status'        => $this->status,
            'decision'      => $this->decision?->value,
            'keyword'       => $this->keyword,
        ];
    }

    /**
     * 空白（全角を含む）で分けた語。どの語も含むものに当てる（「山田 太郎」で「山田太郎」も探せる）
     *
     * @return list<string>
     */
    public static function terms(?string $text): array
    {
        return $text === null ? [] : array_values(array_filter(preg_split('/[\s\x{3000}]+/u', $text) ?: [], fn (string $t) => $t !== ''));
    }

    private static function positiveInt(string $value): ?int
    {
        return preg_match('/^[1-9]\d{0,18}$/', $value) ? (int) $value : null;
    }

    /** 日本の暦の日付（YYYY-MM-DD・在る日付だけ。2026-02-30 は外す） */
    private static function calendarDay(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, JapanTime::ZONE);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
```

`app/Support/Approval/Ledger.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStatus;
use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\JapanTime;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * 決裁台帳（⑤）の問い合わせ（要件 10・段階4 設計書 §5.8・§5.9・D19〜D22）。画面と Excel が同じものを使う。
 *
 * - 見られる範囲は RequestVisibility（規則は 1 か所）。下書きは出さない（申請者本人にも）
 * - 中身（申請部門・申請の種類・件名・本文）は、申請者本人以外には**最後に提出した中身**で当てる（D19）。直しかけが今の中身に
 *   残りうるのは差戻し中と取り下げだけなので、その 2 つの状態の他人の申請だけを控え（approval_revisions の今の回）で当て、
 *   ほかは申請の行で当てる（関連する決裁No の候補 RelatedNumberController と同じ考え。ほかの状態は今の中身と控えが同じ）
 * - 並びは id の並びとして PHP で作る（sortedIds）。「決裁日の新しい順・同じ日は決裁No の順」の「日」は日本の暦なので、
 *   UTC で保存した日時を SQL で日本の日付に直す必要があり、MySQL と SQLite で書き方が違う（D22）
 */
final class Ledger
{
    /** 1 ページの件数（D22） */
    public const PER_PAGE = 50;

    /** 行を描くのに要る関係（N+1 にしない。steps は今の回の審査と社長の判断を読む） */
    private const WITH = ['applicant', 'type', 'department', 'lastRevision', 'steps'];

    /** 「決裁No の付いたもの」の状態（番号のあと取り下げた欠番は、取り下げのうち番号のあるもの。要件 6.5） */
    private const DECIDED = [ApprovalStatus::Approved, ApprovalStatus::Condition, ApprovalStatus::Rejected];

    /** 「進行中」の状態（取り消しで番号が残ったまま戻ったものも含む） */
    private const IN_PROGRESS = [ApprovalStatus::HeadReview, ApprovalStatus::Review, ApprovalStatus::President, ApprovalStatus::Returned];

    /** 中身の列（申請の行） */
    private const CURRENT = [
        'department' => 'approval_requests.department_id',
        'type'       => 'approval_requests.type_id',
        'subject'    => 'approval_requests.subject',
        'body'       => 'approval_requests.body',
    ];

    /** 中身の列（最後に提出した控え。JSON の中を指す） */
    private const SUBMITTED = [
        'department' => 'approval_revisions.snapshot->department->id',
        'type'       => 'approval_revisions.snapshot->type->id',
        'subject'    => 'approval_revisions.snapshot->subject',
        'body'       => 'approval_revisions.snapshot->body',
    ];

    /** 見られる申請を条件で絞った問い合わせ（並びは付けない。sortedIds() が並べる） */
    public static function query(User $viewer, LedgerFilter $filter): Builder
    {
        $query = RequestVisibility::apply(ApprovalRequest::query(), $viewer)
            ->where('approval_requests.status', '!=', ApprovalStatus::Draft->value);

        self::whereStatus($query, $filter->status);

        if ($filter->decision !== null) {
            $query->where('approval_requests.decision', $filter->decision->value);
        }

        // 決裁日の期間は日本の暦の日（その日の 0:00 から次の日の 0:00 の前まで）
        if ($filter->decidedFrom !== null) {
            $query->where('approval_requests.decided_at', '>=', $filter->decidedFrom->startOfDay()->utc());
        }
        if ($filter->decidedTo !== null) {
            $query->where('approval_requests.decided_at', '<', $filter->decidedTo->startOfDay()->addDay()->utc());
        }

        // 申請者は名前の一部（空白で分けた語のどれも含む。D21）。退職して消した人の申請も探せる（applicant は withTrashed）
        $names = LedgerFilter::terms($filter->applicant);
        if ($names !== []) {
            $query->whereHas('applicant', function (Builder $q) use ($names): void {
                foreach ($names as $name) {
                    $q->where('name', 'like', "%{$name}%");
                }
            });
        }

        $words = LedgerFilter::terms($filter->keyword);
        if ($filter->year !== null || $filter->departmentId !== null || $filter->typeId !== null || $words !== []) {
            $periods = $filter->year === null ? [] : self::periods($filter->year);
            self::whereContent($query, $viewer, function (Builder|QueryBuilder $q, array $column) use ($filter, $words, $periods): void {
                if ($filter->year !== null) {
                    self::whereYear($q, $filter->year, $periods, $column['department']);
                }
                if ($filter->departmentId !== null) {
                    $q->where($column['department'], $filter->departmentId);
                }
                if ($filter->typeId !== null) {
                    $q->where($column['type'], $filter->typeId);
                }
                // キーワードは件名か本文（空白で分けた語のどれも含む）。LIKE の % と _ は逃がさない（アプリのほかの検索と同じ）
                foreach ($words as $word) {
                    $q->where(fn (Builder|QueryBuilder $q) => $q->where($column['subject'], 'like', "%{$word}%")->orWhere($column['body'], 'like', "%{$word}%"));
                }
            });
        }

        return $query;
    }

    /**
     * 並べた id（D22）。決裁日（番号の無い申請は発信日）の日本の暦の日の新しい順 → 同じ日は決裁No のあるものを
     * 部門のアルファベットと連番の順 → 番号の無いものは発信の新しい順 → id の新しい順
     *
     * @return list<int>
     */
    public static function sortedIds(User $viewer, LedgerFilter $filter): array
    {
        $codes = ApprovalDepartment::query()->pluck('code', 'id');
        $keys  = self::query($viewer, $filter)->toBase()
            ->get(['approval_requests.id', 'approval_requests.number', 'approval_requests.number_department_id', 'approval_requests.number_seq', 'approval_requests.decided_at', 'approval_requests.last_submitted_at'])
            ->map(fn (object $r) => [
                'id'         => (int) $r->id,
                'day'        => self::japanDay($r->decided_at ?? $r->last_submitted_at),
                'unnumbered' => $r->number === null ? 1 : 0,
                'code'       => (string) ($codes[$r->number_department_id] ?? ''),
                'seq'        => (int) $r->number_seq,
                'submitted'  => (string) $r->last_submitted_at,
            ])
            ->all();

        usort($keys, fn (array $a, array $b) => [$b['day'], $a['unnumbered'], $a['code'], $a['seq'], $b['submitted'], $b['id']]
            <=> [$a['day'], $b['unnumbered'], $b['code'], $b['seq'], $a['submitted'], $a['id']]);

        return array_column($keys, 'id');
    }

    /**
     * id の並びのとおりに行を作る（行に要る関係を先にまとめて読む）
     *
     * @param list<int> $ids
     * @return Collection<int, LedgerRow>
     */
    public static function rows(User $viewer, array $ids): Collection
    {
        $position = array_flip($ids);

        return ApprovalRequest::with(self::WITH)->whereKey($ids)->get()
            ->sortBy(fn (ApprovalRequest $r) => $position[$r->id])
            ->map(fn (ApprovalRequest $r) => LedgerRow::for($viewer, $r))
            ->values();
    }

    /**
     * 年度の選択肢（新しい順。今の年度から、いちばん古い申請の年度まで。値は期の始まりの年）
     *
     * @return array<int, string> 年度 => 表示（「R8 年度（2026）」）
     */
    public static function years(): array
    {
        $months = ApprovalCompany::query()->pluck('fiscal_start_month')->unique()->values();
        if ($months->isEmpty()) {
            return [];
        }

        // 今の年度は会社で違うことがある（5 月はミツワだけ新しい年度）ので、いちばん新しいもの。古い方は番号の年度と発信日の年度
        $newest   = $months->map(fn (mixed $m) => ApprovalFiscalYear::current((int) $m))->max();
        $oldest   = $newest;
        $numbered = ApprovalRequest::query()->min('number_fiscal_year');
        if ($numbered !== null) {
            $oldest = min($oldest, (int) $numbered);
        }
        $submitted = ApprovalRequest::query()->min('last_submitted_at');
        if ($submitted !== null) {
            $oldest = min($oldest, ApprovalFiscalYear::ofMoment(CarbonImmutable::parse($submitted, 'UTC'), (int) $months->max()));
        }

        $years = [];
        for ($year = $newest; $year >= $oldest; $year--) {
            $years[$year] = ApprovalFiscalYear::eraLabel($year, (int) $months->min()) . " 年度（{$year}）";
        }

        return $years;
    }

    private static function whereStatus(Builder $query, string $status): void
    {
        $values = fn (array $statuses) => array_map(fn (ApprovalStatus $s) => $s->value, $statuses);

        match ($status) {
            'numbered'  => $query->where(fn (Builder $q) => $q
                ->whereIn('approval_requests.status', $values(self::DECIDED))
                ->orWhere(fn (Builder $q) => $q->where('approval_requests.status', ApprovalStatus::Withdrawn->value)->whereNotNull('approval_requests.number'))),
            'progress'  => $query->whereIn('approval_requests.status', $values(self::IN_PROGRESS)),
            'withdrawn' => $query->where('approval_requests.status', ApprovalStatus::Withdrawn->value),
            default     => null,
        };
    }

    /**
     * 中身で絞る（D19）。申請者本人の申請と、直しかけの残らない状態の申請は申請の行で、他人の差戻し中・取り下げの申請は
     * 最後に提出した控えで当てる
     *
     * @param Closure(Builder|QueryBuilder, array{department: string, type: string, subject: string, body: string}): void $where
     */
    private static function whereContent(Builder $query, User $viewer, Closure $where): void
    {
        // 直しかけが今の中身に残りうる状態（他人の申請はこの状態だけ控えで当てる。D19）
        $fromRevision = array_map(fn (ApprovalStatus $s) => $s->value, ApprovalStatus::mayDifferFromSubmission());

        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('approval_requests.user_id', $viewer->id)->orWhereNotIn('approval_requests.status', $fromRevision))
                ->where(fn (Builder $q) => $where($q, self::CURRENT)))
            ->orWhere(fn (Builder $q) => $q
                ->where('approval_requests.user_id', '!=', $viewer->id)
                ->whereIn('approval_requests.status', $fromRevision)
                ->whereExists(function (QueryBuilder $s) use ($where): void {
                    $s->selectRaw('1')->from('approval_revisions')
                        ->whereColumn('approval_revisions.request_id', 'approval_requests.id')
                        ->whereColumn('approval_revisions.round', 'approval_requests.round');
                    $where($s, self::SUBMITTED);
                })));
    }

    /**
     * その年度の期間（会社の期の始まりの月ごと。日本時間の期の始まりから次の期の始まりの前まで）と、その期の部門
     *
     * @return list<array{departments: list<int>, from: CarbonImmutable, until: CarbonImmutable}>
     */
    private static function periods(int $year): array
    {
        return ApprovalDepartment::query()->join('approval_companies', 'approval_companies.id', '=', 'approval_departments.company_id')
            ->get(['approval_departments.id', 'approval_companies.fiscal_start_month'])
            ->groupBy('fiscal_start_month')
            ->map(function (Collection $departments, int|string $startMonth) use ($year): array {
                $start = CarbonImmutable::create($year, (int) $startMonth, 1, 0, 0, 0, JapanTime::ZONE);

                return ['departments' => $departments->pluck('id')->all(), 'from' => $start->utc(), 'until' => $start->addYear()->utc()];
            })
            ->values()
            ->all();
    }

    /**
     * 年度（D20）。決裁No の付いた申請はその番号の年度、付いていない申請は発信日（最後の提出）の年度。どちらも申請部門の会社の期
     *
     * @param list<array{departments: list<int>, from: CarbonImmutable, until: CarbonImmutable}> $periods
     */
    private static function whereYear(Builder|QueryBuilder $q, int $year, array $periods, string $departmentColumn): void
    {
        $q->where(fn (Builder|QueryBuilder $q) => $q
            ->where('approval_requests.number_fiscal_year', $year)
            ->orWhere(fn (Builder|QueryBuilder $q) => $q
                ->whereNull('approval_requests.number')
                ->where(function (Builder|QueryBuilder $q) use ($periods, $departmentColumn): void {
                    // 部門が 1 つも無いときは何にも当てない（空の括弧は Laravel が落とし、番号の無い申請すべてに当たるため）
                    $q->whereRaw('1 = 0');
                    foreach ($periods as $period) {
                        $q->orWhere(fn (Builder|QueryBuilder $q) => $q
                            ->whereIn($departmentColumn, $period['departments'])
                            ->where('approval_requests.last_submitted_at', '>=', $period['from'])
                            ->where('approval_requests.last_submitted_at', '<', $period['until']));
                    }
                })));
    }

    /** 保存した日時（UTC の文字列）の日本の暦の日 */
    private static function japanDay(?string $at): string
    {
        return $at === null ? '' : CarbonImmutable::parse($at, 'UTC')->setTimezone(JapanTime::ZONE)->format('Y-m-d');
    }
}
```

`app/Support/Approval/LedgerRow.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * 決裁台帳の 1 行（画面の行とカード・Excel の 1 行。要件 10・段階4 設計書 §5.8・§5.9・D19・D23）。
 *
 * ⚠ 中身（件名・種類・申請部門・金額・実施時期・関連する決裁No）は、申請者本人には今の中身、ほかの人には**最後に提出した
 *   控え**（RequestContent と同じ出し分け。名前も提出したときのもの）。台帳は一覧なので控えは先に読んだもの（lastRevision）を使う。
 *   控えが無いとき（提出した申請には必ずある）は今の中身へ落とさずに空にする（分からないときは見せない）。
 * ⚠ 審査の意見・コメントと条件（条可のコメント）は今の回の段階から（PDF と同じく今の回だけ。D4）。
 */
final class LedgerRow
{
    /** @param list<string> $relatedNumbers */
    private function __construct(
        public readonly int $id,
        public readonly ?string $number,
        public readonly ?CarbonInterface $decidedAt,
        public readonly ?string $decisionLabel,
        public readonly ?string $subject,
        public readonly ?string $typeName,
        public readonly ?string $departmentName,
        public readonly ?string $applicantName,
        public readonly ?int $amount,
        public readonly ?string $schedule,
        public readonly array $relatedNumbers,
        public readonly ?CarbonInterface $submittedAt,
        public readonly ?string $reviewResult,
        public readonly ?string $reviewComment,
        public readonly ?string $condition,
        public readonly string $statusLabel,
        public readonly string $statusStyle,
    ) {
    }

    /** 関係（applicant・type・department・lastRevision・steps）は先に読んでおく（Ledger::rows()） */
    public static function for(User $viewer, ApprovalRequest $request): self
    {
        $own      = $request->user_id === $viewer->id;
        $snapshot = $own ? [] : ($request->lastRevision?->snapshot ?? []);
        $steps    = $request->currentSteps()->keyBy(fn (ApprovalStep $s) => $s->kind->value);
        $review    = self::done($steps->get(ApprovalStepKind::Review->value));
        $president = self::done($steps->get(ApprovalStepKind::President->value));

        return new self(
            id: $request->id,
            number: $request->number,
            decidedAt: $request->decided_at,
            decisionLabel: $request->decision?->label(),
            subject: $own ? $request->subject : ($snapshot['subject'] ?? null),
            typeName: $own ? $request->type?->name : ($snapshot['type']['name'] ?? null),
            departmentName: $own ? $request->department?->name : ($snapshot['department']['name'] ?? null),
            applicantName: $request->applicant?->name,
            amount: $own ? $request->amount : (isset($snapshot['amount']) ? (int) $snapshot['amount'] : null),
            schedule: $own ? $request->schedule : ($snapshot['schedule'] ?? null),
            relatedNumbers: array_values(array_map('strval', $own ? ($request->related_numbers ?? []) : ($snapshot['related_numbers'] ?? []))),
            submittedAt: $request->last_submitted_at,
            reviewResult: $review?->result?->labelFor(ApprovalStepKind::Review),
            reviewComment: $review?->comment,
            condition: $request->decision === ApprovalDecision::Conditional ? $president?->comment : null,
            statusLabel: $request->statusLabel(),
            statusStyle: $request->status->badgeStyle(),
        );
    }

    /** 金額の表示（税抜・末尾に「円」。規約: `¥` 接頭辞 NG） */
    public function amountLabel(): ?string
    {
        return $this->amount === null ? null : number_format($this->amount) . '円';
    }

    /** 判断した段階だけ（待ち・省略・取り消しは意見もコメントも無い） */
    private static function done(?ApprovalStep $step): ?ApprovalStep
    {
        return $step?->status === ApprovalStepStatus::Done ? $step : null;
    }
}
```

`app/Models/ApprovalRequest.php`（変更）

```diff
--- a/app/Models/ApprovalRequest.php
+++ b/app/Models/ApprovalRequest.php
@@ -9,6 +9,7 @@
 use Illuminate\Database\Eloquent\Model;
 use Illuminate\Database\Eloquent\Relations\BelongsTo;
 use Illuminate\Database\Eloquent\Relations\HasMany;
+use Illuminate\Database\Eloquent\Relations\HasOne;
 
 /**
  * 申請（設計書 §5.3・§5.8）。
@@ -73,6 +74,15 @@ public function revisions(): HasMany
         return $this->hasMany(ApprovalRevision::class, 'request_id')->orderBy('round');
     }
 
+    /**
+     * 最後に提出した控え（今の回。控えは提出のたびに回の番号で 1 つ作るので、いちばん大きい回が今の回）。
+     * 一覧で申請者以外に見せる中身を、行ごとに問い合わせずに読むため（決裁台帳。段階4 設計書 §5.8・D19）
+     */
+    public function lastRevision(): HasOne
+    {
+        return $this->hasOne(ApprovalRevision::class, 'request_id')->latestOfMany('round');
+    }
+
     public function histories(): HasMany
     {
         return $this->hasMany(ApprovalHistory::class, 'request_id')->orderBy('id');
```

`app/Enums/ApprovalStatus.php`（変更）

```diff
--- a/app/Enums/ApprovalStatus.php
+++ b/app/Enums/ApprovalStatus.php
@@ -60,6 +60,18 @@ public function isEditable(): bool
         return in_array($this, [self::Draft, self::Returned], true);
     }
 
+    /**
+     * 今の中身が最後に提出した控えと違いうる状態（差戻し中の直しかけと、直しかけのまま取り下げた申請。要件 4.4・4.5）。
+     * 申請者以外に中身を見せる・当てるときは、この状態の申請だけ控えを使う（ほかの状態は中身を直せないので今の中身と同じ）。
+     * 関連する決裁No の候補（RelatedNumberController）と決裁台帳（Ledger）が使う
+     *
+     * @return list<self>
+     */
+    public static function mayDifferFromSubmission(): array
+    {
+        return [self::Returned, self::Withdrawn];
+    }
+
     /** 申請者が取り下げられる（要件 4.5） */
     public function isWithdrawable(): bool
     {
```

`app/Http/Controllers/Approval/RelatedNumberController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/RelatedNumberController.php
+++ b/app/Http/Controllers/Approval/RelatedNumberController.php
@@ -45,7 +45,7 @@ public function search(Request $request): JsonResponse
         $number = RelatedNumbers::normalize($text);
 
         // 直しかけが今の中身に残りうる状態（差戻し中と、差戻し中に直して保存してから取り下げた申請）
-        $fromRevision = [ApprovalStatus::Returned, ApprovalStatus::Withdrawn];
+        $fromRevision = ApprovalStatus::mayDifferFromSubmission();
         $values       = array_map(fn (ApprovalStatus $status) => $status->value, $fromRevision);
 
         $found = RequestVisibility::apply(ApprovalRequest::query(), $request->user())
```

（差分のファイルを使うなら: `… git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/4b/patches/0002-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 と同じコマンド。関連する決裁No の候補のテストも流す）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase4/LedgerFilterTest.php tests/Feature/Approval/Phase4/LedgerQueryTest.php tests/Feature/Approval/Phase4/LedgerRowTest.php tests/Feature/Approval/Phase2/RelatedNumberSearchTest.php
```

Expected: `OK`（赤なし。`RelatedNumberSearchTest` は変えずに通る）

- [ ] **Step 5: 全件を流す**

Expected: `OK (3685 tests, 28456 assertions)`（⚠ `ClockReadScanTest` が赤なら、日付を読む関数の名前が `date(` になっていないかを見る。§0.6）

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/LedgerFilter.php app/Support/Approval/Ledger.php app/Support/Approval/LedgerRow.php app/Models/ApprovalRequest.php app/Enums/ApprovalStatus.php app/Http/Controllers/Approval/RelatedNumberController.php tests/Feature/Approval/Phase4/LedgerFilterTest.php tests/Feature/Approval/Phase4/LedgerQueryTest.php tests/Feature/Approval/Phase4/LedgerRowTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 決裁台帳の問い合わせを足す

見られる申請（RequestVisibility・下書きを除く）を年度・部門・種類・申請者・
決裁日の期間・状態・判断・キーワードで絞り、決裁日の日本の暦の日の新しい順に
並べる（段階4 設計書 §5.8・D19〜D22）。中身は申請者本人以外には最後に提出した
控えで出し、差戻し中の直しかけを検索に掛けない。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 3: 決裁台帳の画面と入口

台帳の画面（絞り込み・PC の表・スマホのカード・50 件ずつのページ送り）と、サイドバー 4 か所とホームの入口を足す（設計書 §5.8・D22・要件 14.4・§0.4）。

**Files:**
- Create: `app/Http/Controllers/Approval/LedgerController.php`・`resources/views/approvals/ledger/index.blade.php`
- Modify: `routes/approval.php`・`resources/views/layouts/partials/sidebar_approval.blade.php`・`resources/views/layouts/partials/sidebar.blade.php`・`resources/views/approvals/home-launched.blade.php`
- Test: Create `tests/Feature/Approval/Phase4/LedgerScreenTest.php`／Modify `tests/Feature/Approval/ApprovalAdminGateTest.php`・`ApprovalSidebarTest.php`・`Phase2/ApprovalMenuTest.php`・`Phase2/HomeAndListTest.php`

**Interfaces:**
- Consumes: Task 2 の `LedgerFilter`・`Ledger`・`LedgerRow`
- Produces: ルート `approvals.ledger.index`（`GET /approvals/ledger`）・`LedgerController::index(Request): View`・private `choices(): array{years, departments, types}`・private `filter(Request, array $choices): LedgerFilter`（`fromRequest()->within()`。Task 4 の Excel も使う）。ビューは `filter`・`rows`（`LengthAwarePaginator<LedgerRow>`）・`pages`・`years`・`departments`・`types` を受け取る。Task 4 がコントローラとビューに Excel を足す

**差分の大きさ:** 11 ファイル・+525 / −4 行（差分のファイル `0003-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase4/LedgerScreenTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalStatus;
use App\Models\User;
use App\Support\Approval\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 決裁台帳の画面（画面⑤。要件 10・14.4・段階4 設計書 §5.8・D22）。問い合わせの中身は LedgerQueryTest・LedgerRowTest が見る。
 */
class LedgerScreenTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 01:00:00', 'UTC'));   // 日本時間 10/5 10:00
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 決裁済み（可）の申請を、状態の列を書いて作る（並びと件数を見るテスト用） */
    private function approved(array $w, int $seq, array $attributes = []): int
    {
        $r = $this->submittedFor($w, $attributes);
        DB::table('approval_requests')->where('id', $r->id)->update([
            'status' => ApprovalStatus::Approved->value, 'decision' => 'approve', 'number' => 'R8-J-' . sprintf('%03d', $seq),
            'number_department_id' => $w['dept']->id, 'number_fiscal_year' => 2026, 'number_seq' => $seq, 'decided_at' => '2026-10-04 23:00:00',
        ]);

        return $r->id;
    }

    private function page(User $viewer, array $query = []): string
    {
        return (string) $this->actingAs($viewer)->get(route('approvals.ledger.index', $query))->assertOk()->getContent();
    }

    public function test_before_launch_the_ledger_is_not_open(): void
    {
        $w = $this->approvalWorld();

        $this->actingAs($w['applicant'])->get(route('approvals.ledger.index'))->assertRedirect(route('approvals.home'));
    }

    public function test_each_request_is_a_card_on_a_phone_and_a_row_on_a_pc(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $id = $this->approved($w, 7, ['subject' => '社用車の購入', 'amount' => 2850000]);

        $html = $this->page($this->viewAllUser());

        $this->assertSame(1, preg_match('#<ul class="md:hidden[^"]*">(.*?)</ul>#s', $html, $cards), 'スマホのカードが無い');
        $this->assertSame(1, preg_match('#<div class="hidden md:block">\s*<div class="scroll-hint at-start">.*?(<table.*?</table>)#s', $html, $table), 'PC の表（横スクロールの枠の中）が無い');
        foreach (['card' => $cards[1], 'table' => $table[1]] as $where => $block) {
            $this->assertStringContainsString('href="' . route('approvals.requests.show', $id) . '"', $block, "{$where} から詳細へ行けない");
            foreach (['R8-J-007', '2026/10/05', '社用車の購入', '住宅事業部', '申請 花子', '2,850,000円', '決裁済み（可）'] as $text) {
                $this->assertStringContainsString($text, $block, "{$where} に「{$text}」が無い");
            }
        }
        $this->assertStringContainsString('>可</td>', $table[1], '表に判断が無い');
        $this->assertStringContainsString('<span class="font-semibold tabular-nums">1</span> 件', $html);
    }

    public function test_what_people_typed_is_escaped(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->approved($w, 1, ['subject' => '<b>太字</b>の件名']);
        $w['applicant']->update(['name' => '<i>申請</i> 花子']);

        $html = $this->page($this->viewAllUser());

        $this->assertStringNotContainsString('<b>太字</b>', $html);
        $this->assertStringNotContainsString('<i>申請</i>', $html);
        // カードと表の両方で、打ったとおりの文字で出る（どちらか片方だけエスケープを外しても落ちるように数える）
        $this->assertSame(2, substr_count($html, '&lt;b&gt;太字&lt;/b&gt;の件名'), '件名がカードと表に 1 つずつ');
        $this->assertSame(2, substr_count($html, '&lt;i&gt;申請&lt;/i&gt; 花子'), '申請者がカードと表に 1 つずつ');

        // ⚠ キーワードの欄は別の画面で見る（「<script>」で絞ると行が 0 件になり、上の行の確かめが空振りする）
        $search = $this->page($this->viewAllUser(), ['q' => '<script>']);
        $this->assertStringContainsString('value="&lt;script&gt;"', $search, 'キーワードの欄も打ったとおり（タグにしない）');
    }

    public function test_the_form_shows_the_conditions_in_use(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $html = $this->page($this->viewAllUser(), [
            'year' => '2026', 'department' => (string) $w['dept']->id, 'type' => (string) $w['type']->id, 'status' => 'progress',
            'decision' => 'reject', 'from' => '2026-05-01', 'to' => '2026-10-05', 'applicant' => '花子', 'q' => '社用車',
        ]);

        $this->assertMatchesRegularExpression('#<option value="2026" selected>R8 年度（2026）</option>#u', $html);
        $this->assertMatchesRegularExpression('#<option value="' . $w['dept']->id . '" selected>[^<]*住宅事業部</option>#u', $html);
        $this->assertMatchesRegularExpression('#<option value="' . $w['type']->id . '" selected>#', $html);
        $this->assertStringContainsString('<option value="progress" selected>進行中</option>', $html);
        $this->assertStringContainsString('<option value="reject" selected>否</option>', $html);
        foreach (['name="from" value="2026-05-01"', 'name="to" value="2026-10-05"', 'name="applicant" value="花子"', 'name="q" value="社用車"'] as $field) {
            $this->assertStringContainsString($field, $html);
        }
        $this->assertStringContainsString('>条件を消す</a>', $html);
    }

    public function test_the_default_shows_the_numbered_ones_and_says_so_when_empty(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->submittedFor($w);   // 進行中（既定では出ない）

        $html = $this->page($this->viewAllUser());

        $this->assertStringContainsString('<option value="numbered" selected>決裁No の付いたもの</option>', $html);
        $this->assertStringContainsString('該当する申請はありません。', $html);
        $this->assertStringContainsString('状態は「決裁No の付いたもの」で絞っています', $html);
        $this->assertStringNotContainsString('>条件を消す</a>', $html, '既定のままなら消す条件は無い');
    }

    public function test_unreadable_conditions_are_named(): void
    {
        $this->approvalWorld();
        $this->launchApprovals();

        $html = $this->page($this->viewAllUser(), ['from' => '2026-02-30', 'year' => 'abc']);

        $this->assertStringContainsString('読み取れない条件があったので、外して探しました（年度・決裁日（から））。', $html);
    }

    public function test_conditions_outside_the_choices_are_named_and_not_used(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->approved($w, 1);

        $html = $this->page($this->viewAllUser(), ['department' => '99999', 'year' => '1990']);

        $this->assertStringContainsString('読み取れない条件があったので、外して探しました（年度・申請部門）。', $html);
        $this->assertStringContainsString('<span class="font-semibold tabular-nums">1</span> 件', $html, '外した条件で 0 件にしない');
    }

    public function test_pages_of_fifty_keep_the_conditions(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        foreach (range(1, Ledger::PER_PAGE + 1) as $seq) {
            $this->approved($w, $seq, ['subject' => "社用車 {$seq}"]);
        }
        $viewer = $this->viewAllUser();

        $first = $this->page($viewer, ['q' => '社用車']);
        $this->assertSame(Ledger::PER_PAGE, substr_count($first, 'class="text-emerald-600 hover:underline break-words"'), '1 ページは 50 件');
        $this->assertStringContainsString('<span class="font-semibold tabular-nums">51</span> 件', $first);
        $this->assertStringContainsString(e(route('approvals.ledger.index', ['q' => '社用車', 'page' => 2])), $first, 'ページ送りが条件を運ぶ');

        $second = $this->page($viewer, ['q' => '社用車', 'page' => 2]);
        $this->assertSame(1, substr_count($second, 'class="text-emerald-600 hover:underline break-words"'));
        $this->assertStringContainsString('>R8-J-051<', $second, '2 ページ目は 51 番目（同じ日なので番号の順）');
    }

    public function test_the_page_does_not_query_per_request(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $viewer = $this->viewAllUser();
        $this->approved($w, 1);
        $this->approved($w, 2);

        $count = function () use ($viewer): int {
            $queries = 0;
            DB::listen(function () use (&$queries): void { $queries++; });
            $this->page($viewer);

            return $queries;
        };

        $this->page($viewer);   // 見る人の決裁の印（approval_members）は 1 回目だけ読むので、先に 1 回開いておく
        $few = $count();
        foreach (range(3, 12) as $seq) {
            $this->approved($w, $seq);
        }
        $this->assertSame($few, $count(), '申請の数で問い合わせの数が増えた（N+1）');
    }

    public function test_the_filter_form_is_reset_whenever_the_page_is_shown(): void
    {
        $this->approvalWorld();
        $this->launchApprovals();

        $html = $this->page($this->viewAllUser());

        $this->assertSame(1, substr_count($html, "'pageshow'"), 'pageshow のリスナーがちょうど 1 つでない');
        $this->assertMatchesRegularExpression(
            "#window\\.addEventListener\\('pageshow', function \\(\\) \\{\\s*document\\.getElementById\\('filter-form'\\)\\.reset\\(\\);\\s*\\}\\);#",
            $html,
            '戻ったときに絞り込みのフォームを表に使った条件へ戻していない（Bug #65）'
        );
        $this->assertStringContainsString('<form id="filter-form" method="GET"', $html);
    }
}
```

`tests/Feature/Approval/ApprovalAdminGateTest.php`（変更）

```diff
--- a/tests/Feature/Approval/ApprovalAdminGateTest.php
+++ b/tests/Feature/Approval/ApprovalAdminGateTest.php
@@ -96,6 +96,7 @@ class ApprovalAdminGateTest extends TestCase
         'approvals.requests.update'  => '同じく保存・提出',
         'approvals.requests.destroy' => '一度も提出していない下書きの削除（申請者だけ）',
         'approvals.requests.pdf'     => '決裁申請書の PDF（見られる範囲を毎回確かめ、記録する。下書きは 404。段階4 設計書 §5.6）',
+        'approvals.ledger.index'     => '決裁台帳（見られる申請だけ。下書きは出さない。段階4 設計書 §5.8）',
         'approvals.requests.attachments.store' => '添付の追加（申請者だけ。下書き・差戻し中。段階2 設計書 §5.7）',
         'approvals.attachments.show'           => '添付を開く（見られる範囲を毎回確かめ、記録する）',
         'approvals.attachments.destroy'        => '添付を外す（申請者だけ。下書き・差戻し中）',
@@ -191,7 +192,7 @@ public function test_every_approvals_route_is_classified(): void
 
         // 走査が空振りして緑になる事故を防ぐ（3b で 50 本 = 決裁の管理 22 本 + 進行中の申請の管理 4 本 + ホーム 1 本 + 申請を回す画面 16 本 + お知らせ 3 本
         // + 催促の設定 4 本）
-        $this->assertGreaterThanOrEqual(51, $found, 'approvals. のルートの走査に失敗している');
+        $this->assertGreaterThanOrEqual(52, $found, 'approvals. のルートの走査に失敗している');
     }
 
     /**
```

`tests/Feature/Approval/ApprovalSidebarTest.php`（変更）

```diff
--- a/tests/Feature/Approval/ApprovalSidebarTest.php
+++ b/tests/Feature/Approval/ApprovalSidebarTest.php
@@ -367,4 +367,22 @@ public function test_the_request_links_appear_only_after_launch(): void
         }
         $this->assertStringContainsString('title="自分の申請"', $after['rail'], 'rail に自分の申請のアイコンリンクが無い');
     }
+
+    /** 決裁台帳（段階4 設計書 §5.8）も使い始めてから。入口は展開版とドロワー（折りたたみ版のアイコンは足さない） */
+    public function test_the_ledger_link_appears_only_after_launch(): void
+    {
+        $user = User::factory()->approvalOnly()->create(['must_change_password' => false]);
+
+        $before = $this->sidebars($this->actingAs($user)->get(route('approvals.home'))->assertOk()->getContent());
+        foreach ($before as $key => $aside) {
+            $this->assertStringNotContainsString(route('approvals.ledger.index'), $aside, "{$key} に準備中の決裁台帳へのリンクが出ている");
+        }
+
+        ApprovalSetting::current()->update(['launched_at' => now()]);
+
+        $after = $this->sidebars($this->actingAs($user)->get(route('approvals.home'))->assertOk()->getContent());
+        foreach (['expanded', 'drawer'] as $key) {
+            $this->assertHasLink($after[$key], route('approvals.ledger.index'), '決裁台帳', $key);
+        }
+    }
 }
```

`tests/Feature/Approval/Phase2/ApprovalMenuTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/ApprovalMenuTest.php
+++ b/tests/Feature/Approval/Phase2/ApprovalMenuTest.php
@@ -76,6 +76,23 @@ public function test_the_base_sidebar_shows_the_count_in_all_three_places(): voi
         $this->assertMatchesRegularExpression('#<span class="absolute[^"]*"[^>]*aria-hidden="true">2</span>#', $sidebars['rail'], '折りたたみ版に件数の丸印が無い');
     }
 
+    /** 基幹のサイドバーにも決裁台帳（段階4 設計書 §5.8。使い始めてから。展開版とドロワーの「決裁」の下） */
+    public function test_the_base_sidebar_offers_the_ledger_after_launch(): void
+    {
+        $w = $this->approvalWorld();
+
+        foreach ($this->sidebars($this->html($w['head'], '/dashboard/tenant')) as $key => $aside) {
+            $this->assertStringNotContainsString(route('approvals.ledger.index'), $aside, "{$key} に使い始める前の決裁台帳が出た");
+        }
+
+        $this->launchApprovals();
+        $sidebars = $this->sidebars($this->html($w['head'], '/dashboard/tenant'));
+
+        foreach (['expanded', 'drawer'] as $key) {
+            $this->assertMatchesRegularExpression('#<a\s+href="' . preg_quote(route('approvals.ledger.index'), '#') . '"[^>]*>\s*決裁台帳\s*</a>#u', $sidebars[$key], "{$key} に「決裁台帳」が無い");
+        }
+    }
+
     public function test_no_badge_when_nothing_is_waiting(): void
     {
         $w = $this->approvalWorld();
```

`tests/Feature/Approval/Phase2/HomeAndListTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/HomeAndListTest.php
+++ b/tests/Feature/Approval/Phase2/HomeAndListTest.php
@@ -318,6 +318,17 @@ public function test_the_admin_links_are_only_for_approval_admins(): void
         }
     }
 
+    /** ホームの上のリンクの行に「決裁台帳」（誰にでも。段階4 設計書 §5.8） */
+    public function test_the_home_links_to_the_ledger(): void
+    {
+        $w = $this->approvalWorld();
+        $this->launchApprovals();
+
+        $main = $this->mainOf($this->actingAs($w['applicant'])->get(route('approvals.home'))->assertOk()->getContent());
+
+        $this->assertMatchesRegularExpression('#<a href="' . preg_quote(route('approvals.ledger.index'), '#') . '"[^>]*>決裁台帳</a>#u', $main);
+    }
+
     /**
      * 使い始めたあとも、基幹の画面から跳ね返された決裁のみ利用者にはホームに理由が出る。ホームの入口から来たときは出さない
      * （Bug #63 の流れ。ApprovalOnlyLockoutTest は準備中のホームで固定している）。
```

（差分のファイルを使うなら: `… git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/4b/patches/0003-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/ApprovalSidebarTest.php tests/Feature/Approval/Phase2/ApprovalMenuTest.php tests/Feature/Approval/Phase2/HomeAndListTest.php tests/Feature/Approval/Phase4/LedgerScreenTest.php
```

Expected: `ERRORS!` `Tests: 53, Assertions: 437, Errors: 13, Failures: 1.`

- `ApprovalSidebarTest::test_the_ledger_link_appears_only_after_launch` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.index] not defined.`
- `ApprovalMenuTest::test_the_base_sidebar_offers_the_ledger_after_launch` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.index] not defined.`
- `HomeAndListTest::test_the_home_links_to_the_ledger` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.index] not defined.`
- `LedgerScreenTest::test_before_launch_the_ledger_is_not_open` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.index] not defined.`
- `LedgerScreenTest::test_each_request_is_a_card_on_a_phone_and_a_row_on_a_pc` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.index] not defined.`
- `LedgerScreenTest::test_what_people_typed_is_escaped` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.index] not defined.`
- `LedgerScreenTest::test_the_form_shows_the_conditions_in_use` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.index] not defined.`
- `LedgerScreenTest::test_the_default_shows_the_numbered_ones_and_says_so_when_empty` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.index] not defined.`
- `LedgerScreenTest::test_unreadable_conditions_are_named` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.index] not defined.`
- `LedgerScreenTest::test_conditions_outside_the_choices_are_named_and_not_used` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.index] not defined.`
- `LedgerScreenTest::test_pages_of_fifty_keep_the_conditions` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.index] not defined.`
- `LedgerScreenTest::test_the_page_does_not_query_per_request` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.index] not defined.`
- `LedgerScreenTest::test_the_filter_form_is_reset_whenever_the_page_is_shown` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.index] not defined.`
- `ApprovalAdminGateTest::test_every_approvals_route_is_classified` — `分類漏れ・門番の欠落・逆方向の見落とし:`（`ApprovalAdminGateTest` は、ルートが無いのに `OPEN_TO_EVERY_USER` に載っている＝「そのルートがもう存在しない」で赤）

- [ ] **Step 3: ルート・コントローラ・画面・入口を足す**

`routes/approval.php`（変更）

```diff
--- a/routes/approval.php
+++ b/routes/approval.php
@@ -3,6 +3,7 @@
 use App\Http\Controllers\Approval\AdminRequestController;
 use App\Http\Controllers\Approval\HolidayController;
 use App\Http\Controllers\Approval\HomeController;
+use App\Http\Controllers\Approval\LedgerController;
 use App\Http\Controllers\Approval\NoticeController;
 use App\Http\Controllers\Approval\OrganizationController;
 use App\Http\Controllers\Approval\RelatedNumberController;
@@ -140,6 +141,9 @@
     // 決裁申請書の PDF（段階4 設計書 §5.6）。見られる人なら提出したことのある申請をいつでも（下書きは 404）
     Route::get('/requests/{approvalRequest}/pdf', [RequestPdfController::class, 'show'])->name('requests.pdf');
 
+    // 決裁台帳（画面⑤。段階4 設計書 §5.8）。見られる申請だけ（下書きは出さない）
+    Route::get('/ledger', [LedgerController::class, 'index'])->name('ledger.index');
+
     // 添付（段階2 設計書 §5.7・計画 §0.5）。追加と外すのは Ajax・JSON
     Route::post('/requests/{approvalRequest}/attachments', [RequestAttachmentController::class, 'store'])->name('requests.attachments.store');
     Route::get('/attachments/{approvalAttachment}', [RequestAttachmentController::class, 'show'])->name('attachments.show');
```

`app/Http/Controllers/Approval/LedgerController.php`（新規）

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalType;
use App\Support\Approval\Ledger;
use App\Support\Approval\LedgerFilter;
use App\Support\Approval\PageNumbers;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * 決裁台帳（画面⑤。要件 10・段階4 設計書 §5.8・D19〜D22）。見られる人なら誰でも開ける（見られる申請だけが出る）。
 *
 * ⚠ 絞り込みは GET。読み取れない条件は断らずに外して知らせる（LedgerFilter。validate() で戻すと同じ URL を開き直し続ける）。
 * ⚠ 並びは id の並びとして作り（Ledger::sortedIds）、今のページの分だけ行を読む（N+1 にしない）。
 */
class LedgerController extends Controller
{
    /** Route: GET /approvals/ledger */
    public function index(Request $request): View
    {
        $viewer  = $request->user();
        $choices = $this->choices();
        $filter  = $this->filter($request, $choices);
        $ids     = Ledger::sortedIds($viewer, $filter);
        $page    = LengthAwarePaginator::resolveCurrentPage();

        $rows = new LengthAwarePaginator(
            Ledger::rows($viewer, array_slice($ids, ($page - 1) * Ledger::PER_PAGE, Ledger::PER_PAGE)),
            count($ids),
            Ledger::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $filter->query()],
        );

        return view('approvals.ledger.index', $choices + [
            'filter' => $filter,
            'rows'   => $rows,
            'pages'  => PageNumbers::around($rows->currentPage(), $rows->lastPage()),
        ]);
    }

    /**
     * 絞り込みの選択肢（年度・申請部門・申請の種類）
     *
     * @return array{years: array<int, string>, departments: Collection<int, ApprovalDepartment>, types: Collection<int, ApprovalType>}
     */
    private function choices(): array
    {
        return [
            'years'       => Ledger::years(),
            'departments' => ApprovalDepartment::with('company')->get()
                ->sortBy(fn (ApprovalDepartment $d) => [$d->company->sort_order, $d->company_id, $d->sort_order, $d->id])
                ->values(),
            'types'       => ApprovalType::query()->ordered()->get(),
        ];
    }

    /**
     * 条件（選択肢に無い年度・部門・種類は外して知らせる）
     *
     * @param array{years: array<int, string>, departments: Collection<int, ApprovalDepartment>, types: Collection<int, ApprovalType>} $choices
     */
    private function filter(Request $request, array $choices): LedgerFilter
    {
        return LedgerFilter::fromRequest($request)->within(
            array_keys($choices['years']),
            $choices['departments']->pluck('id')->all(),
            $choices['types']->pluck('id')->all(),
        );
    }
}
```

`resources/views/approvals/ledger/index.blade.php`（新規）

```blade
@extends('layouts.app')

@section('title', '決裁台帳')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">決裁台帳</span>
@endsection

{{-- 決裁台帳（画面⑤。要件 10・段階4 設計書 §5.8・D19〜D22）。見られる申請だけ・下書きは出さない。
     ⚠ 件名・申請部門・金額は、申請者本人以外には最後に提出した中身（LedgerRow）。保存した日時は JapanTime::format（Bug #61）。
     ⚠ プルダウンは変えた瞬間に送る（CLAUDE.md の即時フィルタ）。文字と日付は「絞り込む」で送る。
     ⚠ 絞り込みの欄はスマホの幅でも 2 列（1 列だと欄だけで 1 画面を使い、結果が見えない）。 --}}
@section('content')
<div>
    <h1 class="text-lg font-bold text-gray-900 mb-2">決裁台帳</h1>
    <p class="text-[12px] text-gray-500 mb-4 max-w-[720px]">見られる申請を、決裁日の新しい順に並べます（決裁No の無い申請は発信日の順）。件名を押すと申請の詳細を開きます。</p>

    @if($filter->ignored !== [])
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-800" role="status">
            読み取れない条件があったので、外して探しました（{{ implode('・', $filter->ignored) }}）。
        </div>
    @endif

    <form id="filter-form" method="GET" action="{{ route('approvals.ledger.index') }}"
          class="grid grid-cols-2 lg:grid-cols-4 gap-x-3 gap-y-2.5 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-3">
        <label class="block text-[12px] text-gray-600">年度
            <select name="year" onchange="document.getElementById('filter-form').submit()" class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full">
                <option value="">すべて</option>
                @foreach($years as $year => $label)
                    <option value="{{ $year }}" {{ $filter->year === $year ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-[12px] text-gray-600">状態
            <select name="status" onchange="document.getElementById('filter-form').submit()" class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full">
                @foreach(\App\Support\Approval\LedgerFilter::STATUSES as $key => $label)
                    <option value="{{ $key }}" {{ $filter->status === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-[12px] text-gray-600">判断
            <select name="decision" onchange="document.getElementById('filter-form').submit()" class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full">
                <option value="">すべて</option>
                @foreach(\App\Enums\ApprovalDecision::cases() as $decision)
                    <option value="{{ $decision->value }}" {{ $filter->decision === $decision ? 'selected' : '' }}>{{ $decision->label() }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-[12px] text-gray-600">申請部門
            <select name="department" onchange="document.getElementById('filter-form').submit()" class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full">
                <option value="">すべて</option>
                @foreach($departments as $department)
                    <option value="{{ $department->id }}" {{ $filter->departmentId === $department->id ? 'selected' : '' }}>{{ $department->company->name }} / {{ $department->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-[12px] text-gray-600">申請の種類
            <select name="type" onchange="document.getElementById('filter-form').submit()" class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full">
                <option value="">すべて</option>
                @foreach($types as $type)
                    <option value="{{ $type->id }}" {{ $filter->typeId === $type->id ? 'selected' : '' }}>{{ $type->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-[12px] text-gray-600">申請者
            <input type="text" name="applicant" value="{{ $filter->applicant }}" maxlength="{{ \App\Support\Approval\LedgerFilter::APPLICANT_MAX }}" placeholder="名前の一部"
                   class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none w-full">
        </label>
        <div class="block text-[12px] text-gray-600 col-span-2 lg:col-span-1">決裁日の期間
            <div class="mt-1 flex items-center gap-1.5">
                <input type="date" name="from" value="{{ $filter->decidedFrom?->format('Y-m-d') }}" aria-label="決裁日（から）"
                       class="h-8 px-2 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none w-full min-w-0">
                <span aria-hidden="true">〜</span>
                <input type="date" name="to" value="{{ $filter->decidedTo?->format('Y-m-d') }}" aria-label="決裁日（まで）"
                       class="h-8 px-2 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none w-full min-w-0">
            </div>
        </div>
        <label class="block text-[12px] text-gray-600 col-span-2 lg:col-span-1">キーワード
            <input type="text" name="q" value="{{ $filter->keyword }}" maxlength="{{ \App\Support\Approval\LedgerFilter::KEYWORD_MAX }}" placeholder="件名・本文"
                   class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none w-full">
        </label>
        <div class="flex items-end gap-3 col-span-2 lg:col-span-4">
            <button type="submit" class="h-8 px-4 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[12px] font-semibold cursor-pointer">絞り込む</button>
            @if($filter->query() !== [])
                <a href="{{ route('approvals.ledger.index') }}" class="text-[12px] text-gray-500 hover:text-emerald-600 transition-colors pb-1.5">条件を消す</a>
            @endif
        </div>
    </form>

    <div class="bg-white rounded-lg border border-gray-200">
        <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 border-b border-gray-200">
            <p class="text-[13px] text-gray-700"><span class="font-semibold tabular-nums">{{ number_format($rows->total()) }}</span> 件</p>
        </div>

        @if($rows->isEmpty())
            <p class="px-4 py-8 text-center text-[13px] text-gray-400">該当する申請はありません。@if($filter->status === \App\Support\Approval\LedgerFilter::DEFAULT_STATUS)<br>状態は「{{ \App\Support\Approval\LedgerFilter::STATUSES[\App\Support\Approval\LedgerFilter::DEFAULT_STATUS] }}」で絞っています（進行中・取り下げは状態を変えると出ます）。@endif</p>
        @else
            {{-- スマホの幅では 1 件 1 枚のカード（要件 14.4。横スクロールにしない） --}}
            <ul class="md:hidden divide-y divide-gray-100">
                @foreach($rows as $row)
                    <li>
                        <a href="{{ route('approvals.requests.show', $row->id) }}" class="block px-4 py-3 hover:bg-gray-50">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $row->statusStyle }}">{{ $row->statusLabel }}</span>
                                @if($row->number)
                                    <span class="text-[12px] font-mono text-gray-600">{{ $row->number }}</span>
                                @endif
                            </div>
                            <p class="text-[14px] font-semibold text-gray-900 break-words">{{ $row->subject ?? '（件名なし）' }}</p>
                            <p class="text-[12px] text-gray-500 mt-0.5">{{ $row->departmentName ?? '—' }}・{{ $row->applicantName ?? '—' }}</p>
                            <p class="text-[12px] text-gray-700 mt-0.5">決裁日 {{ \App\Support\JapanTime::format($row->decidedAt, 'Y/m/d') ?? '—' }}{{ $row->decisionLabel ? '・' . $row->decisionLabel : '' }}{{ $row->amountLabel() ? '・' . $row->amountLabel() : '' }}</p>
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="hidden md:block">
                <div class="scroll-hint at-start">
                    <div class="scroll-hint-inner">
                <table class="w-full min-w-[960px] border-collapse">
                    <thead>
                        <tr>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">決裁No</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">決裁日</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">判断</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">件名</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 min-w-[8rem]">申請部門</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">申請者</th>
                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">金額（税抜）</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">状態</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-mono text-gray-700 whitespace-nowrap">{{ $row->number ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ \App\Support\JapanTime::format($row->decidedAt, 'Y/m/d') ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $row->decisionLabel ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px]">
                                    <a href="{{ route('approvals.requests.show', $row->id) }}" class="text-emerald-600 hover:underline break-words">{{ $row->subject ?? '（件名なし）' }}</a>
                                </td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $row->departmentName ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $row->applicantName ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums whitespace-nowrap">{{ $row->amountLabel() ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 whitespace-nowrap">
                                    <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $row->statusStyle }}">{{ $row->statusLabel }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                    </div>{{-- /scroll-hint-inner --}}
                    <div class="scroll-hint-text">← スクロールできます →</div>
                </div>{{-- /scroll-hint --}}
            </div>

            @include('approvals._pager', ['paginator' => $rows, 'pages' => $pages])
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
// 画面をもう一度見せたとき（ブラウザの「戻る」など）は、絞り込みの入力をサーバーが描いた状態＝表に使った条件へ戻す
// （docs/RULES.md Bug #65。戻ると、変えた後のプルダウンと前の条件のままの表・ページ送りが食い違う）。
// ⚠ event.persisted で絞らない（読み込み直して入力欄の値を戻したときも食い違う）
window.addEventListener('pageshow', function () {
    document.getElementById('filter-form').reset();
});
</script>
@endpush
```

`resources/views/layouts/partials/sidebar_approval.blade.php`（変更）

```diff
--- a/resources/views/layouts/partials/sidebar_approval.blade.php
+++ b/resources/views/layouts/partials/sidebar_approval.blade.php
@@ -1,6 +1,6 @@
 {{-- 決裁のみ利用者（UserRole::ApprovalOnly）のサイドバー（設計書 §5.15）。
      基幹のサイドバー（sidebar.blade.php）の代わりに layouts/app.blade.php が出し分ける。
-     ⚠ 中身は「決裁のホーム」、使い始めてから「新しい申請」「自分の申請」（段階2 設計書 §5.12）、
+     ⚠ 中身は「決裁のホーム」、使い始めてから「新しい申請」「自分の申請」（段階2 設計書 §5.12）と「決裁台帳」（段階4 設計書 §5.8）、
        決裁の管理者なら「利用者の管理」「部門の管理」「申請種類の管理」。
        社員の CSV 一括登録は「利用者の管理」の下位の画面なのでここには出さない。
      回帰テスト tests/Feature/Approval/ApprovalSidebarTest.php --}}
@@ -34,6 +34,7 @@ class="inline-flex items-center gap-1 px-2 py-1 rounded-md border border-gray-30
         @if($approvalsLaunched)
             <x-sidebar-item :href="route('approvals.requests.create')" label="新しい申請" :active="request()->routeIs('approvals.requests.create')" />
             <x-sidebar-item :href="route('approvals.requests.index')" label="自分の申請" :active="request()->routeIs('approvals.requests.index', 'approvals.requests.show', 'approvals.requests.edit')" />
+            <x-sidebar-item :href="route('approvals.ledger.index')" label="決裁台帳" :active="request()->routeIs('approvals.ledger.*')" />
         @endif
         @if($isApprovalAdmin)
             <x-sidebar-item :href="route('approvals.admin.users.index')" label="利用者の管理" :active="request()->routeIs('approvals.admin.users.*')" />
@@ -81,6 +82,7 @@ class="p-1 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transi
     @if($approvalsLaunched)
         <x-sidebar-item :href="route('approvals.requests.create')" label="新しい申請" :active="request()->routeIs('approvals.requests.create')" />
         <x-sidebar-item :href="route('approvals.requests.index')" label="自分の申請" :active="request()->routeIs('approvals.requests.index', 'approvals.requests.show', 'approvals.requests.edit')" />
+        <x-sidebar-item :href="route('approvals.ledger.index')" label="決裁台帳" :active="request()->routeIs('approvals.ledger.*')" />
     @endif
     @if($isApprovalAdmin)
         <x-sidebar-item :href="route('approvals.admin.users.index')" label="利用者の管理" :active="request()->routeIs('approvals.admin.users.*')" />
```

`resources/views/layouts/partials/sidebar.blade.php`（変更）

```diff
--- a/resources/views/layouts/partials/sidebar.blade.php
+++ b/resources/views/layouts/partials/sidebar.blade.php
@@ -65,9 +65,10 @@ class="inline-flex items-center gap-1 px-2 py-1 rounded-md border border-gray-30
         @if($hasMansionAccess)
             <x-sidebar-item :href="url('/mansion/dashboard')" label="賃貸Mダッシュボード" :active="request()->is('mansion/dashboard')" />
         @endif
-        {{-- 決裁と対応待ちの件数（使い始めてから。段階2 設計書 §5.15） --}}
+        {{-- 決裁と対応待ちの件数（使い始めてから。段階2 設計書 §5.15）・決裁台帳（段階4 設計書 §5.8） --}}
         @if($approvalPending !== null)
             <x-sidebar-item :href="route('approvals.home')" label="決裁" :badge="$approvalPending" :active="request()->routeIs('approvals.home', 'approvals.requests.*')" />
+            <x-sidebar-item :href="route('approvals.ledger.index')" label="決裁台帳" :active="request()->routeIs('approvals.ledger.*')" />
         @endif
     </div>
 
@@ -390,9 +391,10 @@ class="fixed inset-y-0 left-0 z-30 w-[260px] bg-white overflow-y-auto pt-4 pb-6
         @endif
     </x-sidebar-group>
 
-    {{-- 決裁と対応待ちの件数（使い始めてから。§5.15）。開閉するグループの中に入れない（閉じているあいだ件数が見えない） --}}
+    {{-- 決裁と対応待ちの件数（使い始めてから。§5.15）・決裁台帳（段階4 設計書 §5.8）。開閉するグループの中に入れない（閉じているあいだ件数が見えない） --}}
     @if($approvalPending !== null)
         <x-sidebar-item :href="route('approvals.home')" label="決裁" :badge="$approvalPending" :active="request()->routeIs('approvals.home', 'approvals.requests.*')" />
+        <x-sidebar-item :href="route('approvals.ledger.index')" label="決裁台帳" :active="request()->routeIs('approvals.ledger.*')" />
     @endif
 
     @if($hasTenantAccess)
```

`resources/views/approvals/home-launched.blade.php`（変更）

```diff
--- a/resources/views/approvals/home-launched.blade.php
+++ b/resources/views/approvals/home-launched.blade.php
@@ -23,6 +23,7 @@
         <div class="flex flex-wrap gap-2">
             <a href="{{ route('approvals.requests.create') }}" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold">新しい申請</a>
             <a href="{{ route('approvals.requests.index') }}" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-700 hover:bg-gray-50">自分の申請</a>
+            <a href="{{ route('approvals.ledger.index') }}" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-700 hover:bg-gray-50">決裁台帳</a>
         </div>
     </div>
```

（差分のファイルを使うなら: `… git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/4b/patches/0003-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (53 tests, …)`

- [ ] **Step 5: 全件を流す**

Expected: `OK (3698 tests, 28559 assertions)`（`MobileLayoutTest`・`LaunchGateTest`・`ApprovalOnlyLockoutTest` も緑）

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add routes/approval.php app/Http/Controllers/Approval/LedgerController.php resources/views/approvals/ledger/index.blade.php resources/views/layouts/partials/sidebar_approval.blade.php resources/views/layouts/partials/sidebar.blade.php resources/views/approvals/home-launched.blade.php tests/Feature/Approval/Phase4/LedgerScreenTest.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/ApprovalSidebarTest.php tests/Feature/Approval/Phase2/ApprovalMenuTest.php tests/Feature/Approval/Phase2/HomeAndListTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 決裁台帳の画面と入口を足す

決裁台帳（画面⑤）を、絞り込み・PC の表・スマホのカード・50 件ずつのページ送りで
足し、決裁のみ利用者と基幹のサイドバー（PC とスマホ）とホームに入口を置く
（段階4 設計書 §5.8・D22）。使い始めるまでは開かず、入口も出さない。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 4: 決裁台帳の Excel の出力と記録

台帳と同じ条件・同じ並びで全件を Excel に出し（文字は式にしない・日付は日本の暦の日・金額は数・見出しを固定して絞り込みのボタン）、件数の上限を超えたら作らずに知らせ、出すたびに条件と件数を記録する（設計書 §5.9・§5.10・D23〜D25・§0.5）。

**Files:**
- Create: `app/Support/Approval/LedgerExcel.php`
- Modify: `app/Http/Controllers/Approval/LedgerController.php`（`excel()`）・`resources/views/approvals/ledger/index.blade.php`（「Excel に出力」）・`routes/approval.php`・`config/approval.php`（件数の上限）
- Test: Create `tests/Feature/Approval/Phase4/LedgerExcelTest.php`／Modify `tests/Feature/Approval/ApprovalAdminGateTest.php`

**Interfaces:**
- Consumes: Task 1 の `DownloadLogger::excel()`・Task 2 の `Ledger::sortedIds()`・`Ledger::rows()`・`LedgerFilter::toLog()`・`LedgerFilter::query()`・Task 3 の `LedgerController`・台帳の画面
- Produces: ルート `approvals.ledger.excel`（`GET /approvals/ledger/excel`）・`LedgerExcel::build(User, list<int>): string`・`LedgerExcel::fileNames(): array{0: string, 1: string}`・`config('approval.ledger.excel_limit')`（1,000）

**差分の大きさ:** 7 ファイル・+578 / −5 行（差分のファイル `0004-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase4/LedgerExcelTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalDownloadLog;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\User;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * 決裁台帳の Excel と出力の記録（要件 10・14.2・段階4 設計書 §5.9・§5.10・D23〜D25）。
 *
 * ⚠ 出した xlsx を PhpSpreadsheet で読み戻して、列・型・書式を見る。式にしないことは、セルの型と、シートの XML に `<f>`
 *   （式）が無いことの両方で見る。
 */
class LedgerExcelTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-04 16:12:00', 'UTC'));   // 日本時間 10/5 1:12
        $this->workflow = app(Workflow::class);
        $this->launchApprovals();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 審査が保留の意見を付け、社長が条可と決裁した申請 */
    private function conditional(array $w, array $attributes = []): ApprovalRequest
    {
        $r = $this->submittedFor($w, $attributes);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->judgeReview($r->fresh(), $w['reviewer'], $r->fresh()->lock_version, ApprovalStepResult::Hold, '見積を 2 社取ってください');
        $this->workflow->judgePresident($r->fresh(), $w['president'], $r->fresh()->lock_version, ApprovalStepResult::Conditional, '納期を確かめること');

        return $r->fresh();
    }

    private function download(User $viewer, array $query = []): TestResponse
    {
        return $this->actingAs($viewer)->get(route('approvals.ledger.excel', $query));
    }

    /** 応答の xlsx をファイルに書いて読み戻す（読み戻しも、ZIP の中の XML も見る） */
    private function sheet(TestResponse $response): Worksheet
    {
        $path = tempnam(sys_get_temp_dir(), 'ledger') . '.xlsx';
        file_put_contents($path, $response->getContent());
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return IOFactory::load($path)->getActiveSheet();
    }

    private function sheetXml(TestResponse $response): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ledger') . '.xlsx';
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        return $xml;
    }

    public function test_the_columns_and_the_values_of_a_row(): void
    {
        $w = $this->approvalWorld();
        $this->conditional($w, ['related_numbers' => ['R7-J-003', 'R7-J-010']]);

        $response = $this->download($this->viewAllUser())->assertOk();
        $sheet    = $this->sheet($response);

        $this->assertSame(
            ['決裁No', '決裁日', '判断', '件名', '申請の種類', '申請部門', '申請者', '金額（税抜）', '実施時期', '関連する決裁No', '提出日', '審査の意見', '審査のコメント', '条件', '状態'],
            $sheet->rangeToArray('A1:O1')[0]
        );
        $this->assertSame(
            ['R8-J-001', '条可', '社用車の購入', $w['type']->name, '住宅事業部', '申請 花子', '2026年10月', 'R7-J-003・R7-J-010', '保留', '見積を 2 社取ってください', '納期を確かめること', '条件確認待ち'],
            array_map(fn (string $c) => $sheet->getCell("{$c}2")->getValue(), ['A', 'C', 'D', 'E', 'F', 'G', 'I', 'J', 'L', 'M', 'N', 'O'])
        );
        $this->assertSame(2, $sheet->getHighestRow(), '1 件なので見出しと 1 行');
    }

    public function test_dates_are_excel_dates_of_the_japanese_day_and_the_amount_is_a_number(): void
    {
        $w = $this->approvalWorld();
        $this->conditional($w);   // 決裁と提出は UTC の 10/4 16:12 ＝ 日本時間 10/5

        $sheet = $this->sheet($this->download($this->viewAllUser())->assertOk());

        foreach (['B2', 'K2'] as $cell) {
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($cell)->getDataType(), "{$cell} が数（日付）でない");
            $this->assertSame('2026-10-05 00:00:00', Date::excelToDateTimeObject($sheet->getCell($cell)->getValue())->format('Y-m-d H:i:s'), "{$cell} が日本の暦の日でない（時刻を入れない）");
            $this->assertSame('yyyy/mm/dd', $sheet->getStyle($cell)->getNumberFormat()->getFormatCode());
        }
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('H2')->getDataType());
        $this->assertEquals(2850000, $sheet->getCell('H2')->getValue());
        $this->assertSame('#,##0', $sheet->getStyle('H2')->getNumberFormat()->getFormatCode());
    }

    public function test_text_that_looks_like_a_formula_stays_text(): void
    {
        $w = $this->approvalWorld();
        $w['applicant']->update(['name' => '+81 花子']);
        $this->conditional($w, ['subject' => '=HYPERLINK("https://example.com","開く")', 'schedule' => '@SUM(A1)', 'related_numbers' => []]);

        $response = $this->download($this->viewAllUser())->assertOk();
        $sheet    = $this->sheet($response);

        foreach (['D2' => '=HYPERLINK("https://example.com","開く")', 'G2' => '+81 花子', 'I2' => '@SUM(A1)'] as $cell => $text) {
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($cell)->getDataType(), "{$cell} が文字でない");
            $this->assertSame($text, $sheet->getCell($cell)->getValue());
        }
        $this->assertStringNotContainsString('<f>', $this->sheetXml($response), 'シートに式が入っている');
    }

    public function test_control_characters_do_not_break_the_file(): void
    {
        $w = $this->approvalWorld();
        $this->conditional($w, ['subject' => "縦タブ\x0B入りの件名"]);

        $sheet = $this->sheet($this->download($this->viewAllUser())->assertOk());

        $this->assertSame("縦タブ\x0B入りの件名", $sheet->getCell('D2')->getValue());
    }

    public function test_the_heading_is_frozen_and_has_filter_buttons(): void
    {
        $w = $this->approvalWorld();
        $this->conditional($w);
        $this->conditional($w);

        $sheet = $this->sheet($this->download($this->viewAllUser())->assertOk());

        $this->assertSame('A2', $sheet->getFreezePane());
        $this->assertSame('A1:O3', $sheet->getAutoFilter()->getRange());
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        $this->assertSame('決裁台帳', $sheet->getTitle());
    }

    public function test_the_rows_are_the_ledger_with_the_same_conditions_and_order(): void
    {
        $w = $this->approvalWorld();
        $first  = $this->conditional($w, ['subject' => '社用車の購入']);
        $second = $this->conditional($w, ['subject' => '社用車の修理']);
        $this->conditional($w, ['subject' => '机の購入']);
        $this->submittedFor($w, ['subject' => '社用車の売却']);   // 進行中（既定の状態では出ない）

        $sheet = $this->sheet($this->download($this->viewAllUser(), ['q' => '社用車'])->assertOk());

        // 同じ日の決裁は決裁No の順（D22）
        $this->assertSame([[$first->number, '社用車の購入'], [$second->number, '社用車の修理']], [
            [$sheet->getCell('A2')->getValue(), $sheet->getCell('D2')->getValue()],
            [$sheet->getCell('A3')->getValue(), $sheet->getCell('D3')->getValue()],
        ]);
        $this->assertSame(3, $sheet->getHighestRow());
        $this->assertSame(1, $this->sheet($this->download($this->baseUser()))->getHighestRow(), '関わっていない人には見出しだけ');
    }

    public function test_others_get_what_was_last_submitted(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w, ['subject' => '出した件名']);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Return, '見直してください');
        $r->fresh()->update(['subject' => '直しかけ']);

        $this->assertSame('出した件名', $this->sheet($this->download($w['head'], ['status' => 'progress']))->getCell('D2')->getValue());
        $this->assertSame('直しかけ', $this->sheet($this->download($w['applicant'], ['status' => 'progress']))->getCell('D2')->getValue());
    }

    /** 行は 200 件ずつ読む。200 件を超えても全件を書く（状態の列を書いた控えの写しで件数だけ増やす） */
    public function test_more_than_one_batch_of_rows_is_written(): void
    {
        $w        = $this->approvalWorld();
        $template = $this->conditional($w);
        $request  = (array) DB::table('approval_requests')->where('id', $template->id)->first();
        $revision = (array) DB::table('approval_revisions')->where('request_id', $template->id)->first();
        foreach (range(2, 205) as $seq) {
            $id = DB::table('approval_requests')->insertGetId(array_merge($request, ['id' => null, 'number' => sprintf('R8-J-%03d', $seq), 'number_seq' => $seq]));
            DB::table('approval_revisions')->insert(array_merge($revision, ['id' => null, 'request_id' => $id]));
        }

        $sheet = $this->sheet($this->download($this->viewAllUser())->assertOk());

        $this->assertSame(206, $sheet->getHighestRow(), '見出しと 205 件');
        $this->assertSame('R8-J-205', $sheet->getCell('A206')->getValue());
    }

    public function test_the_file_name_and_the_headers(): void
    {
        $w = $this->approvalWorld();
        $this->conditional($w);

        $response = $this->download($this->viewAllUser())->assertOk();

        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame(
            "attachment; filename=ledger_2026-10-05.xlsx; filename*=utf-8''" . rawurlencode('決裁台帳_2026-10-05.xlsx'),
            $response->headers->get('Content-Disposition'),
            '日本の今日の日付で、日本語の名前と ASCII の代わりの名前'
        );
        $this->assertStringStartsWith("PK\x03\x04", $response->getContent(), 'xlsx（ZIP）でない');
    }

    public function test_each_download_is_recorded_with_the_conditions_and_the_count(): void
    {
        $w = $this->approvalWorld();
        $this->conditional($w, ['subject' => '社用車の購入']);
        $this->conditional($w, ['subject' => '社用車の修理']);
        $viewer = $this->viewAllUser();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->download($viewer, ['q' => '社用車', 'decision' => 'conditional', 'year' => '2026'])->assertOk();

        $log = ApprovalDownloadLog::sole();
        $this->assertSame(
            [null, null, $viewer->id, 'excel', 2, '203.0.113.9'],
            [$log->request_id, $log->attachment_id, (int) $log->user_id, $log->kind, $log->fresh()->request_count, $log->ip_address]
        );
        // MySQL の JSON はキーを並べ替えて返す（RequestSnapshot の注意）。並びに頼らずに比べる
        $expected = ['year' => 2026, 'department_id' => null, 'type_id' => null, 'applicant' => null, 'decided_from' => null, 'decided_to' => null, 'status' => 'numbered', 'decision' => 'conditional', 'keyword' => '社用車'];
        $kept     = $log->fresh()->filters;
        ksort($expected);
        ksort($kept);
        $this->assertSame($expected, $kept);
    }

    public function test_too_many_requests_are_not_made_and_not_recorded(): void
    {
        $w = $this->approvalWorld();
        foreach (range(1, 3) as $i) {
            $this->conditional($w, ['subject' => "社用車 {$i}"]);
        }
        config(['approval.ledger.excel_limit' => 2]);

        $this->download($this->viewAllUser(), ['q' => '社用車'])
            ->assertRedirect(route('approvals.ledger.index', ['q' => '社用車']))
            ->assertSessionHas('error', '絞り込んでから出してください（Excel に出せるのは 2 件までです。いまは 3 件）。');
        $this->assertSame(0, ApprovalDownloadLog::count(), '出さなかったときは記録しない');

        config(['approval.ledger.excel_limit' => 3]);
        $this->download($this->viewAllUser(), ['q' => '社用車'])->assertOk();
        $this->assertSame(3, ApprovalDownloadLog::sole()->fresh()->request_count, '上限ちょうどなら出す');
    }

    public function test_conditions_outside_the_choices_are_left_out_as_on_the_ledger(): void
    {
        $w = $this->approvalWorld();
        $this->conditional($w);

        $sheet = $this->sheet($this->download($this->viewAllUser(), ['department' => '99999'])->assertOk());

        $this->assertSame(2, $sheet->getHighestRow(), '台帳の画面と同じく、選択肢に無い部門は外して出す');
        $this->assertNull(ApprovalDownloadLog::sole()->fresh()->filters['department_id']);
    }

    public function test_the_default_limit_is_a_thousand(): void
    {
        // 入力の上限の中身で測って決めた数（config/approval.php の注記・計画 §0.5）。変えるときは測り直す
        $this->assertSame(1000, config('approval.ledger.excel_limit'));
    }

    public function test_broken_text_in_the_conditions_is_left_out_and_the_excel_is_made(): void
    {
        $w = $this->approvalWorld();
        $this->conditional($w);

        // 手で打った URL の壊れた文字（不正な UTF-8）。外して作り、記録の条件（JSON）にも入れない
        $this->download($this->viewAllUser(), ['q' => "\xFF"])->assertOk();

        $this->assertNull(ApprovalDownloadLog::sole()->fresh()->filters['keyword']);
    }

    public function test_the_ledger_offers_the_excel_only_within_the_limit(): void
    {
        $w = $this->approvalWorld();
        $viewer = $this->viewAllUser();
        $link = 'href="' . e(route('approvals.ledger.excel', ['q' => '社用車'])) . '"';

        $empty = $this->actingAs($viewer)->get(route('approvals.ledger.index', ['q' => '社用車']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Excel に出力', $empty, '0 件なら出さない');

        $this->conditional($w, ['subject' => '社用車 1']);
        $this->conditional($w, ['subject' => '社用車 2']);
        config(['approval.ledger.excel_limit' => 2]);
        $within = $this->actingAs($viewer)->get(route('approvals.ledger.index', ['q' => '社用車']))->assertOk()->getContent();
        $this->assertStringContainsString($link, $within, '表と同じ条件で出す');

        config(['approval.ledger.excel_limit' => 1]);
        $over = $this->actingAs($viewer)->get(route('approvals.ledger.index', ['q' => '社用車']))->assertOk()->getContent();
        $this->assertStringNotContainsString($link, $over);
        $this->assertStringContainsString('Excel に出せるのは 1 件までです。絞り込んでください。', $over);
    }

    public function test_before_launch_it_is_not_open(): void
    {
        ApprovalSetting::current()->update(['launched_at' => null]);
        $w = $this->approvalWorld();

        $this->download($w['applicant'])->assertRedirect(route('approvals.home'));
        $this->assertSame(0, ApprovalDownloadLog::count());
    }
}
```

`tests/Feature/Approval/ApprovalAdminGateTest.php`（変更）

```diff
--- a/tests/Feature/Approval/ApprovalAdminGateTest.php
+++ b/tests/Feature/Approval/ApprovalAdminGateTest.php
@@ -97,6 +97,7 @@ class ApprovalAdminGateTest extends TestCase
         'approvals.requests.destroy' => '一度も提出していない下書きの削除（申請者だけ）',
         'approvals.requests.pdf'     => '決裁申請書の PDF（見られる範囲を毎回確かめ、記録する。下書きは 404。段階4 設計書 §5.6）',
         'approvals.ledger.index'     => '決裁台帳（見られる申請だけ。下書きは出さない。段階4 設計書 §5.8）',
+        'approvals.ledger.excel'     => '決裁台帳の Excel（台帳と同じ範囲・条件。出力のたびに記録する。段階4 設計書 §5.9・§5.10）',
         'approvals.requests.attachments.store' => '添付の追加（申請者だけ。下書き・差戻し中。段階2 設計書 §5.7）',
         'approvals.attachments.show'           => '添付を開く（見られる範囲を毎回確かめ、記録する）',
         'approvals.attachments.destroy'        => '添付を外す（申請者だけ。下書き・差戻し中）',
@@ -192,7 +193,7 @@ public function test_every_approvals_route_is_classified(): void
 
         // 走査が空振りして緑になる事故を防ぐ（3b で 50 本 = 決裁の管理 22 本 + 進行中の申請の管理 4 本 + ホーム 1 本 + 申請を回す画面 16 本 + お知らせ 3 本
         // + 催促の設定 4 本）
-        $this->assertGreaterThanOrEqual(52, $found, 'approvals. のルートの走査に失敗している');
+        $this->assertGreaterThanOrEqual(53, $found, 'approvals. のルートの走査に失敗している');
     }
 
     /**
```

（差分のファイルを使うなら: `… git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/4b/patches/0004-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/Approval/Phase4/LedgerExcelTest.php
```

Expected: `ERRORS!` `Tests: 18, Assertions: 28, Errors: 15, Failures: 2.`

- `LedgerExcelTest::test_the_columns_and_the_values_of_a_row` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.excel] not defined.`
- `LedgerExcelTest::test_dates_are_excel_dates_of_the_japanese_day_and_the_amount_is_a_number` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.excel] not defined.`
- `LedgerExcelTest::test_text_that_looks_like_a_formula_stays_text` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.excel] not defined.`
- `LedgerExcelTest::test_control_characters_do_not_break_the_file` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.excel] not defined.`
- `LedgerExcelTest::test_the_heading_is_frozen_and_has_filter_buttons` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.excel] not defined.`
- `LedgerExcelTest::test_the_rows_are_the_ledger_with_the_same_conditions_and_order` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.excel] not defined.`
- `LedgerExcelTest::test_others_get_what_was_last_submitted` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.excel] not defined.`
- `LedgerExcelTest::test_more_than_one_batch_of_rows_is_written` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.excel] not defined.`
- `LedgerExcelTest::test_the_file_name_and_the_headers` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.excel] not defined.`
- `LedgerExcelTest::test_each_download_is_recorded_with_the_conditions_and_the_count` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.excel] not defined.`
- `LedgerExcelTest::test_too_many_requests_are_not_made_and_not_recorded` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.excel] not defined.`
- `LedgerExcelTest::test_conditions_outside_the_choices_are_left_out_as_on_the_ledger` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.excel] not defined.`
- `LedgerExcelTest::test_broken_text_in_the_conditions_is_left_out_and_the_excel_is_made` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.excel] not defined.`
- `LedgerExcelTest::test_the_ledger_offers_the_excel_only_within_the_limit` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.excel] not defined.`
- `LedgerExcelTest::test_before_launch_it_is_not_open` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.ledger.excel] not defined.`
- `ApprovalAdminGateTest::test_every_approvals_route_is_classified` — `分類漏れ・門番の欠落・逆方向の見落とし:`
- `LedgerExcelTest::test_the_default_limit_is_a_thousand` — `Failed asserting that null is identical to 1000.`

- [ ] **Step 3: Excel を作り、出力と記録を足す**

`app/Support/Approval/LedgerExcel.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Models\User;
use App\Support\JapanTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 決裁台帳の Excel（要件 10・段階4 設計書 §5.9・D23・D24）。絞り込んだ結果を台帳と同じ並びで全件。
 *
 * - 日付は Excel の日付（日本の暦の日。時刻は入れない）、金額は数（税抜）
 * - 文字の欄は**すべて文字として入れる**（setCellValueExplicit）。件名などが「=」「+」「-」「@」で始まっても式にしない
 * - 見出しの行を固定し、見出しに絞り込みのボタン（オートフィルタ）
 * - 中身は台帳と同じ出し分け（LedgerRow。申請者本人以外には最後に提出した中身。D19）
 * ⚠ 行の関係は CHUNK 件ずつ読む（全件の申請を一度に読まない）。件数の上限は config('approval.ledger.excel_limit')
 */
final class LedgerExcel
{
    /** 1 回に読む申請の数 */
    private const CHUNK = 200;

    /** 列（見出し => 幅）。D23・§5.9 の順。段階5 の列（工事原価など）は段階5 で足す */
    private const COLUMNS = [
        '決裁No'       => 12,
        '決裁日'       => 11,
        '判断'         => 6,
        '件名'         => 40,
        '申請の種類'   => 16,
        '申請部門'     => 16,
        '申請者'       => 14,
        '金額（税抜）' => 14,
        '実施時期'     => 18,
        '関連する決裁No' => 18,
        '提出日'       => 11,
        '審査の意見'   => 10,
        '審査のコメント' => 40,
        '条件'         => 40,
        '状態'         => 16,
    ];

    /** 日付の列・金額の列・折り返す列（A から数えた位置） */
    private const DATE_COLUMNS = ['B', 'K'];

    private const AMOUNT_COLUMN = 'H';

    private const WRAPPED_COLUMNS = ['D', 'M', 'N'];

    private const SHEET_TITLE = '決裁台帳';

    /**
     * xlsx の中身（バイト列）
     *
     * @param list<int> $ids 台帳の並び（Ledger::sortedIds）
     */
    public static function build(User $viewer, array $ids): string
    {
        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(self::SHEET_TITLE);

        self::heading($sheet);

        $line = 2;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (Ledger::rows($viewer, $chunk) as $row) {
                self::write($sheet, $line++, $row);
            }
        }

        $last = $line - 1;
        if ($last >= 2) {
            self::formatBody($sheet, $last);
        }

        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:' . self::lastColumn() . $last);

        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * ファイル名（日本の今日）と、古いブラウザ用の ASCII だけの代わりの名前
     *
     * @return array{0: string, 1: string} ['決裁台帳_2026-10-05.xlsx', 'ledger_2026-10-05.xlsx']
     */
    public static function fileNames(): array
    {
        $today = JapanTime::today()->format('Y-m-d');

        return [self::SHEET_TITLE . "_{$today}.xlsx", "ledger_{$today}.xlsx"];
    }

    private static function heading(Worksheet $sheet): void
    {
        $column = 1;
        foreach (self::COLUMNS as $label => $width) {
            $sheet->setCellValueExplicit([$column, 1], $label, DataType::TYPE_STRING);
            $sheet->getColumnDimensionByColumn($column)->setWidth($width);
            $column++;
        }
        $heading = 'A1:' . self::lastColumn() . '1';
        $sheet->getStyle($heading)->getFont()->setBold(true);
        $sheet->getStyle($heading)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
    }

    /** いちばん右の列（O） */
    private static function lastColumn(): string
    {
        return Coordinate::stringFromColumnIndex(count(self::COLUMNS));
    }

    /** 2 行目から $last 行目までの書式（日付・金額・折り返し・上ぞろえ）。行が無ければ呼ばない（書式だけの空の行を作らない） */
    private static function formatBody(Worksheet $sheet, int $last): void
    {
        foreach (self::DATE_COLUMNS as $column) {
            $sheet->getStyle("{$column}2:{$column}{$last}")->getNumberFormat()->setFormatCode('yyyy/mm/dd');
        }
        $sheet->getStyle(self::AMOUNT_COLUMN . '2:' . self::AMOUNT_COLUMN . $last)->getNumberFormat()->setFormatCode('#,##0');
        foreach (self::WRAPPED_COLUMNS as $column) {
            $sheet->getStyle("{$column}2:{$column}{$last}")->getAlignment()->setWrapText(true);
        }
        $sheet->getStyle('A2:' . self::lastColumn() . $last)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    }

    private static function write(Worksheet $sheet, int $line, LedgerRow $row): void
    {
        $texts = [
            'A' => $row->number,
            'C' => $row->decisionLabel,
            'D' => $row->subject,
            'E' => $row->typeName,
            'F' => $row->departmentName,
            'G' => $row->applicantName,
            'I' => $row->schedule,
            'J' => $row->relatedNumbers === [] ? null : implode('・', $row->relatedNumbers),
            'L' => $row->reviewResult,
            'M' => $row->reviewComment,
            'N' => $row->condition,
            'O' => $row->statusLabel,
        ];
        foreach ($texts as $column => $text) {
            if ($text !== null && $text !== '') {
                $sheet->setCellValueExplicit("{$column}{$line}", $text, DataType::TYPE_STRING);
            }
        }

        if ($row->decidedAt !== null) {
            $sheet->setCellValueExplicit("B{$line}", self::excelDate($row->decidedAt), DataType::TYPE_NUMERIC);
        }
        if ($row->submittedAt !== null) {
            $sheet->setCellValueExplicit("K{$line}", self::excelDate($row->submittedAt), DataType::TYPE_NUMERIC);
        }
        if ($row->amount !== null) {
            $sheet->setCellValueExplicit(self::AMOUNT_COLUMN . $line, $row->amount, DataType::TYPE_NUMERIC);
        }
    }

    /** 保存した瞬間（UTC）の日本の暦の日を、Excel の日付の数にする（時刻は入れない） */
    private static function excelDate(DateTimeInterface $at): float
    {
        $day = CarbonImmutable::instance($at)->setTimezone(JapanTime::ZONE);

        return (float) Date::formattedPHPToExcel((int) $day->format('Y'), (int) $day->format('m'), (int) $day->format('d'));
    }
}
```

`app/Http/Controllers/Approval/LedgerController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/LedgerController.php
+++ b/app/Http/Controllers/Approval/LedgerController.php
@@ -5,16 +5,22 @@
 use App\Http\Controllers\Controller;
 use App\Models\ApprovalDepartment;
 use App\Models\ApprovalType;
+use App\Support\Approval\DownloadLogger;
 use App\Support\Approval\Ledger;
+use App\Support\Approval\LedgerExcel;
 use App\Support\Approval\LedgerFilter;
 use App\Support\Approval\PageNumbers;
+use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
+use Illuminate\Http\Response;
 use Illuminate\Pagination\LengthAwarePaginator;
 use Illuminate\Support\Collection;
 use Illuminate\View\View;
+use Symfony\Component\HttpFoundation\HeaderUtils;
 
 /**
- * 決裁台帳（画面⑤。要件 10・段階4 設計書 §5.8・D19〜D22）。見られる人なら誰でも開ける（見られる申請だけが出る）。
+ * 決裁台帳（画面⑤。要件 10・段階4 設計書 §5.8・D19〜D22）と Excel の出力（§5.9・§5.10・D23〜D25）。
+ * 見られる人なら誰でも開ける（見られる申請だけが出る）。
  *
  * ⚠ 絞り込みは GET。読み取れない条件は断らずに外して知らせる（LedgerFilter。validate() で戻すと同じ URL を開き直し続ける）。
  * ⚠ 並びは id の並びとして作り（Ledger::sortedIds）、今のページの分だけ行を読む（N+1 にしない）。
@@ -39,9 +45,40 @@ public function index(Request $request): View
         );
 
         return view('approvals.ledger.index', $choices + [
-            'filter' => $filter,
-            'rows'   => $rows,
-            'pages'  => PageNumbers::around($rows->currentPage(), $rows->lastPage()),
+            'filter'     => $filter,
+            'rows'       => $rows,
+            'pages'      => PageNumbers::around($rows->currentPage(), $rows->lastPage()),
+            'excelLimit' => (int) config('approval.ledger.excel_limit'),
+        ]);
+    }
+
+    /** Route: GET /approvals/ledger/excel（台帳と同じ条件・同じ並びで全件。件数の上限を超えたら作らない） */
+    public function excel(Request $request): Response|RedirectResponse
+    {
+        $viewer = $request->user();
+        $filter = $this->filter($request, $this->choices());
+        $ids    = Ledger::sortedIds($viewer, $filter);
+        $limit  = (int) config('approval.ledger.excel_limit');
+
+        if (count($ids) > $limit) {
+            return redirect()->route('approvals.ledger.index', $filter->query())
+                ->with('error', '絞り込んでから出してください（Excel に出せるのは ' . number_format($limit) . ' 件までです。いまは ' . number_format(count($ids)) . ' 件）。');
+        }
+
+        // ⚠ 作るのに失敗したときの知らせは PDF のようには作らない（PDF はフォントなど外の物に頼るが、Excel の失敗はメモリの
+        //   不足くらいで、それは捕まえられない。件数の上限で防ぐ）。例外は Laravel がほかの画面と同じく laravel.log に残す
+        $excel = LedgerExcel::build($viewer, $ids);
+
+        // 出力のたびに、絞り込みの条件と件数を記録する（14.2・D25）。作ったあとに書く（作れなかったら記録しない。§5.7）
+        DownloadLogger::excel($request, $filter->toLog(), count($ids));
+
+        // 日本語の名前は filename*（UTF-8）、古いブラウザ用の代わりは ASCII だけの名前（Laravel の Str::ascii に任せない。Bug #68）
+        [$fileName, $fallback] = LedgerExcel::fileNames();
+
+        return response($excel, 200, [
+            'Content-Type'           => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
+            'X-Content-Type-Options' => 'nosniff',
+            'Content-Disposition'    => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName, $fallback),
         ]);
     }
```

`resources/views/approvals/ledger/index.blade.php`（変更）

```diff
--- a/resources/views/approvals/ledger/index.blade.php
+++ b/resources/views/approvals/ledger/index.blade.php
@@ -93,6 +93,12 @@ class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-7
     <div class="bg-white rounded-lg border border-gray-200">
         <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 border-b border-gray-200">
             <p class="text-[13px] text-gray-700"><span class="font-semibold tabular-nums">{{ number_format($rows->total()) }}</span> 件</p>
+            {{-- Excel は表に使った条件で全件（§5.9）。上限を超えたら出さずに理由を書く（押せないボタンには理由を出す。要件 14.4） --}}
+            @if($rows->total() > $excelLimit)
+                <p class="text-[12px] text-gray-500">Excel に出せるのは {{ number_format($excelLimit) }} 件までです。絞り込んでください。</p>
+            @elseif($rows->total() > 0)
+                <a href="{{ route('approvals.ledger.excel', $filter->query()) }}" class="inline-flex items-center h-8 px-3.5 bg-white border border-gray-300 rounded-md text-[12px] font-semibold text-gray-700 hover:bg-gray-50">Excel に出力</a>
+            @endif
         </div>
 
         @if($rows->isEmpty())
```

`routes/approval.php`（変更）

```diff
--- a/routes/approval.php
+++ b/routes/approval.php
@@ -143,6 +143,8 @@
 
     // 決裁台帳（画面⑤。段階4 設計書 §5.8）。見られる申請だけ（下書きは出さない）
     Route::get('/ledger', [LedgerController::class, 'index'])->name('ledger.index');
+    // 決裁台帳の Excel（§5.9）。台帳と同じ条件で全件・出力のたびに記録（§5.10）
+    Route::get('/ledger/excel', [LedgerController::class, 'excel'])->name('ledger.excel');
 
     // 添付（段階2 設計書 §5.7・計画 §0.5）。追加と外すのは Ajax・JSON
     Route::post('/requests/{approvalRequest}/attachments', [RequestAttachmentController::class, 'store'])->name('requests.attachments.store');
```

`config/approval.php`（変更）

```diff
--- a/config/approval.php
+++ b/config/approval.php
@@ -65,4 +65,26 @@
         'temp_dir' => storage_path('framework/cache/mpdf'),
     ],
 
+    /*
+    |--------------------------------------------------------------------------
+    | 決裁台帳の Excel（段階4 設計書 §5.9・D24）
+    |--------------------------------------------------------------------------
+    |
+    | 1 回に出せる件数の上限。超えたら台帳の画面に「絞り込んでから出してください」と出す（Excel は作らず、記録もしない）。
+    | Excel の部品（PhpSpreadsheet）はセルを全部メモリに持つので、件数に比例してメモリが増える。
+    |
+    | メモリをいちばん使うのは審査のコメントと条件（社長のコメント）の長さ。実測（2026-10-06・手元・試作。Laravel の起動の分を除く）:
+    | - 入力の上限の中身（件名 100 文字・実施時期 50 文字・関連する決裁No 10 個・審査のコメントと条件 2,000 文字）:
+    |   500 件で 46MB・1,000 件で 68MB・1,500 件で 96MB・2,000 件で 119MB（時間は 1,000 件で 1.9 秒）
+    | - ふつうの中身（件名 90 文字・コメント 440 文字）: 1,000 件で 46MB・2,000 件で 59MB
+    | 本番の PHP のメモリの上限は 128M（2026-10-05 に読み取った）。Laravel の起動の分（約 25MB）を足しても、上限の中身の
+    | 1,000 件で 95MB ほどに収まる **1,000** にした（2,000 件は上限の中身で 128M を超える）。申請は年に数百件の見込み（要件 14.5）
+    | なので、年度で絞れば出せる。
+    |
+    */
+
+    'ledger' => [
+        'excel_limit' => env('APPROVAL_LEDGER_EXCEL_LIMIT', 1000),
+    ],
+
 ];
```

（差分のファイルを使うなら: `… git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/4b/patches/0004-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (18 tests, …)`

- [ ] **Step 5: 全件を流す**

Expected: `OK (3714 tests, 28629 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/LedgerExcel.php app/Http/Controllers/Approval/LedgerController.php resources/views/approvals/ledger/index.blade.php routes/approval.php config/approval.php tests/Feature/Approval/Phase4/LedgerExcelTest.php tests/Feature/Approval/ApprovalAdminGateTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 決裁台帳の Excel の出力と記録を足す

台帳と同じ条件・同じ並びで全件を xlsx に出す（段階4 設計書 §5.9・D23・D24）。
文字の欄はすべて文字として入れて式にせず、日付は日本の暦の日の Excel の日付、
金額は数にする。1,000 件を超えたら作らずに絞り込みを促し、出すたびに条件と
件数を記録する（§5.10・D25）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 5: 全件テストと変異テスト（Bug #44 の作法）

**Files:** なし（測るだけ。穴が見つかったらテストを足してコミットする）

計画を書く段階で、試作（この計画のコードと同じ中身）に下の表の変異を 1 つずつ当てて測った（2026-10-06。決裁のテスト〈`tests/Feature/Approval`・`tests/Unit/Approval`〉と走査テスト 4 本を流した）。測る前に変異の一覧を作りながら、また別の担当の点検の指摘から、テストが気づけない壊し方（200 件を超える Excel の行・2 回以上提出した申請・上限ちょうど・選択肢に無い条件など）を見つけ、先にテストを足してから測った。測った結果、表のエスケープのテストが空振りしていた（V03）ので直し、V03・V04 は直したあとのテストで測り直した（§ 計画を書く段階の実測の 18）。

⚠ WT のファイルを一時的に壊す変異は、自動の許可の判定に断られる。**WT の HEAD の写しを scratchpad に作ってそこで当てる**（WT は読むだけ）。以下の `<scratchpad>` は、その会話の scratchpad のパス（Mac を再起動すると消える。残したい結果は `~/.claude/plans/approval-phase3-tasks/4b/` へ写す）:

```bash
SCR=<scratchpad>/p4b-mutation && mkdir -p "$SCR" && git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 archive HEAD | tar -x -C "$SCR" && cp -Rc /Users/masanori/site/manage/.claude/worktrees/approval-phase3/vendor "$SCR/vendor" && ! test -L "$SCR/vendor" && test -f "$SCR/app/Support/Approval/LedgerExcel.php" && echo "写し OK"
```

⚠ 写しの `storage/framework/views/*.php` は流す前に消す（道具が毎回消す。古いコンパイル済みのビューが残ると、ビューの変異が効かない）。

- [ ] **Step 1: 全件が緑の状態から始める**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git status --porcelain && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `git status --porcelain` が空・`OK (3714 tests, 28629 assertions)`

- [ ] **Step 2: 変異を当てて表と突き合わせる**

道具は `~/.claude/plans/approval-phase3-tasks/4b/mutate.py`（4a の道具の写しで、変異の一覧だけ 4b にしたもの。1 つ当てて流し、必ず元に戻して、戻ったことを確かめる。書き換える前の文字列が 1 回だけ現れないものは当てずに SKIP と記録する）。先に `--check` で、当てる場所がちょうど 1 回ずつ見つかることを確かめる:

```bash
python3 ~/.claude/plans/approval-phase3-tasks/4b/mutate.py <scratchpad>/p4b-mutation /dev/null --check
python3 ~/.claude/plans/approval-phase3-tasks/4b/mutate.py <scratchpad>/p4b-mutation <scratchpad>/mutations.jsonl
```

Expected（1 行目）: `NG` の行が無く `checked 100`。1 つ流すのに 1 分ほど（全部で 1 時間半ほど）。写しを 3 つ作って ID を分けて並べて流してよい（引数の最後に ID を並べると、その ID だけを流す）。途中で止めるときは python に SIGINT（写しのファイルが元に戻る）。⚠ シェルの `&` で裏に回した python は SIGINT を受け付けない（非対話のシェルの決まり）。止めるときは SIGTERM で止め、写しは捨てて作り直す（当てた変異が写しに残るため。2026-10-06 に実測）。同じ出力のファイルを渡して流し直すと、記録済みの変異を飛ばして続きから流す。

⚠ **最初の `CANARY`（申請の詳細の見出しに未定義の変数）が赤になること**を確かめてから結果を読む（測定が写しのコードを読んでいることの証明）。
⚠ **赤/緑ではなく「落ちたテストの集合」と「落ちた理由の文言」まで突き合わせる。** 意図と別の機構が落としているなら、その変異の測定は無効。当て方を変えて測り直す。

計画の時点の実測（試作。「落ちたテスト」はクラス名を省いたテストの名前）:

| # | 変異（ファイル） | 結果 | 落ちたテスト（実測） | 判定 |
|---|---|---|---|---|
| CANARY | カナリア: 申請の詳細の見出しに未定義の変数（`show.blade.php`） | Tests: 1035, Assertions: 6751, Failures: 80. | 80 本（AdminRequestsTest, NoticeScreenTest, RequestActionTest, RequestAttachmentTest ほか） | カナリア（赤が正しい） |
| T01 | SQL で申請の欄を空にできないまま（`2026-10-05-approval-phase4b.sql`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_the_sql_and_the_migration_change_the_same_columns` | 検出 |
| T02 | migration の条件の列を NOT NULL に（`2026_10_05_000001_change_approval_download_logs_for_excel.php`） | Tests: 1035, Assertions: 7443, Errors: 2, Failures: 10. | `test_a_file_added_and_removed_before_resubmitting_is_never_shown_to_others`、`test_a_file_added_while_returned_stays_with_the_applicant_until_resubmitted`、`test_a_name_without_ascii_letters_still_opens`、`test_a_removed_attachment_can_be_opened_from_the_history_by_others`、`test_a_viewer_gets_the_pdf_inline_and_it_is_recorded`、`test_append_only_records_cannot_be_updated_or_deleted` ほか 6 本 | 検出 |
| T03 | migration で申請の欄を空にできるようにしない（`2026_10_05_000001_change_approval_download_logs_for_excel.php`） | Tests: 1035, Assertions: 7424, Errors: 3, Failures: 14. | `test_an_excel_row_has_no_request_and_keeps_the_filters_and_the_count`、`test_broken_text_in_the_conditions_is_left_out_and_the_excel_is_made`、`test_conditions_outside_the_choices_are_left_out_as_on_the_ledger`、`test_control_characters_do_not_break_the_file`、`test_dates_are_excel_dates_of_the_japanese_day_and_the_amount_is_a_number`、`test_each_download_is_recorded_with_the_conditions_and_the_count` ほか 11 本 | 検出 |
| T04 | SQL に件数の列を足さない（`2026-10-05-approval-phase4b.sql`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_the_sql_and_the_migration_change_the_same_columns` | 検出 |
| T05 | 条件と件数を書けなくする（fillable から外す）（`ApprovalDownloadLog.php`） | Tests: 1035, Assertions: 7475, Errors: 3, Failures: 2. | `test_an_excel_row_has_no_request_and_keeps_the_filters_and_the_count`、`test_broken_text_in_the_conditions_is_left_out_and_the_excel_is_made`、`test_conditions_outside_the_choices_are_left_out_as_on_the_ledger`、`test_each_download_is_recorded_with_the_conditions_and_the_count`、`test_too_many_requests_are_not_made_and_not_recorded` | 検出 |
| T06 | 条件を配列として読み書きしない（`ApprovalDownloadLog.php`） | Tests: 1035, Assertions: 7432, Errors: 3, Failures: 12. | `test_an_excel_row_has_no_request_and_keeps_the_filters_and_the_count`、`test_broken_text_in_the_conditions_is_left_out_and_the_excel_is_made`、`test_conditions_outside_the_choices_are_left_out_as_on_the_ledger`、`test_control_characters_do_not_break_the_file`、`test_dates_are_excel_dates_of_the_japanese_day_and_the_amount_is_a_number`、`test_each_download_is_recorded_with_the_conditions_and_the_count` ほか 9 本 | 検出 |
| L01 | 端末を 255 文字で切らない（`DownloadLogger.php`） | Tests: 1035, Assertions: 7477, Failures: 3. | `test_an_excel_row_has_no_request_and_keeps_the_filters_and_the_count`、`test_every_kind_is_written_the_same_way`、`test_opening_records_the_address_and_the_device` | 検出 |
| L02 | 添付の記録の種類を pdf に（`DownloadLogger.php`） | Tests: 1035, Assertions: 7479, Failures: 2. | `test_every_kind_is_written_the_same_way`、`test_opening_is_logged_once_the_request_was_submitted` | 検出 |
| L03 | Excel の件数を控えない（`DownloadLogger.php`） | Tests: 1035, Assertions: 7478, Failures: 3. | `test_an_excel_row_has_no_request_and_keeps_the_filters_and_the_count`、`test_each_download_is_recorded_with_the_conditions_and_the_count`、`test_too_many_requests_are_not_made_and_not_recorded` | 検出 |
| L04 | IP を控えない（`DownloadLogger.php`） | Tests: 1035, Assertions: 7474, Failures: 4. | `test_an_excel_row_has_no_request_and_keeps_the_filters_and_the_count`、`test_each_download_is_recorded_with_the_conditions_and_the_count`、`test_every_kind_is_written_the_same_way`、`test_opening_records_the_address_and_the_device` | 検出 |
| L05 | 添付を開いても記録しない（`RequestAttachmentController.php`） | Tests: 1035, Assertions: 7474, Errors: 2, Failures: 3. | `test_a_file_added_while_returned_stays_with_the_applicant_until_resubmitted`、`test_a_removed_attachment_can_be_opened_from_the_history_by_others`、`test_opening_is_logged_once_the_request_was_submitted`、`test_opening_records_the_address_and_the_device`、`test_removing_from_a_returned_request_keeps_the_record` | 検出 |
| L06 | PDF を出しても記録しない（`RequestPdfController.php`） | Tests: 1035, Assertions: 7482, Failures: 1. | `test_a_viewer_gets_the_pdf_inline_and_it_is_recorded` | 検出 |
| L07 | Excel の記録の種類の値を変える（`ApprovalDownloadLog.php`） | Tests: 1035, Assertions: 7475, Failures: 4. | `test_an_excel_row_has_no_request_and_keeps_the_filters_and_the_count`、`test_each_download_is_recorded_with_the_conditions_and_the_count`、`test_every_kind_is_written_the_same_way`、`test_the_kinds_are_the_values_kept_in_the_table` | 検出 |
| F01 | 年度に 4 桁でない数を通す（`LedgerFilter.php`） | Tests: 1035, Assertions: 7480, Failures: 1. | `test_unreadable_values_are_left_out_and_named` | 検出 |
| F02 | 部門・種類に 0 を通す（`LedgerFilter.php`） | Tests: 1035, Assertions: 7480, Failures: 1. | `test_unreadable_values_are_left_out_and_named` | 検出 |
| F03 | 申請者の文字数の上限を見ない（`LedgerFilter.php`） | Tests: 1035, Assertions: 7480, Failures: 1. | `test_unreadable_values_are_left_out_and_named` | 検出 |
| F04 | キーワードの上限ちょうどを外す（`LedgerFilter.php`） | Tests: 1035, Assertions: 7482, Failures: 1. | `test_the_longest_values_are_still_read` | 検出 |
| F05 | 在らない日付（2/30）を繰り上げて使う（`LedgerFilter.php`） | Tests: 1035, Assertions: 7478, Failures: 3. | `test_unreadable_conditions_are_named`、`test_unreadable_values_are_left_out_and_named`、`test_values_outside_the_choices_are_left_out` | 検出 |
| F06 | 知らない状態を通す（`LedgerFilter.php`） | Tests: 1035, Assertions: 7480, Failures: 1. | `test_unreadable_values_are_left_out_and_named` | 検出 |
| F07 | 空の値を外した条件として数える（`LedgerFilter.php`） | Tests: 1035, Assertions: 7482, Failures: 1. | `test_empty_values_are_not_conditions` | 検出 |
| F08 | リンクに既定の状態も付ける（`LedgerFilter.php`） | Tests: 1035, Assertions: 7471, Failures: 7. | `test_empty_values_are_not_conditions`、`test_nothing_given_is_the_default`、`test_pages_of_fifty_keep_the_conditions`、`test_the_default_shows_the_numbered_ones_and_says_so_when_empty`、`test_the_ledger_offers_the_excel_only_within_the_limit`、`test_too_many_requests_are_not_made_and_not_recorded` ほか 1 本 | 検出 |
| F09 | 全角の空白とタブで語を分けない（`LedgerFilter.php`） | Tests: 1035, Assertions: 7477, Failures: 2. | `test_the_applicant_is_found_by_part_of_the_name_and_by_every_word`、`test_words_are_split_on_half_and_full_width_spaces` | 検出 |
| F10 | 記録に決裁日（まで）を控えない（`LedgerFilter.php`） | Tests: 1035, Assertions: 7482, Failures: 1. | `test_the_log_keeps_every_key_in_the_same_order` | 検出 |
| F11 | 外した項目の名前を残さない（`LedgerFilter.php`） | Tests: 1035, Assertions: 7479, Failures: 5. | `test_arrays_are_left_out_rather_than_breaking_the_page`、`test_broken_text_is_left_out`、`test_unreadable_conditions_are_named`、`test_unreadable_values_are_left_out_and_named`、`test_values_outside_the_choices_are_left_out` | 検出 |
| F12 | 壊れた文字（不正な UTF-8）を外さない（`LedgerFilter.php`） | Tests: 1035, Assertions: 7481, Failures: 2. | `test_broken_text_in_the_conditions_is_left_out_and_the_excel_is_made`、`test_broken_text_is_left_out` | 検出 |
| F13 | 選択肢に無い年度を外さない（`LedgerFilter.php`） | Tests: 1035, Assertions: 7478, Failures: 2. | `test_conditions_outside_the_choices_are_named_and_not_used`、`test_values_outside_the_choices_are_left_out` | 検出 |
| F14 | 選択肢に無い部門を外さない（`LedgerFilter.php`） | Tests: 1035, Assertions: 7477, Failures: 3. | `test_conditions_outside_the_choices_are_left_out_as_on_the_ledger`、`test_conditions_outside_the_choices_are_named_and_not_used`、`test_values_outside_the_choices_are_left_out` | 検出 |
| F15 | 選択肢に無い種類を外さない（`LedgerFilter.php`） | Tests: 1035, Assertions: 7482, Failures: 1. | `test_values_outside_the_choices_are_left_out` | 検出 |
| Q01 | 下書きも出す（`Ledger.php`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_drafts_are_never_listed_even_for_their_applicant` | 検出 |
| Q02 | 見られる範囲で絞らない（`Ledger.php`） | Tests: 1035, Assertions: 7480, Failures: 2. | `test_it_lists_only_what_the_viewer_may_see`、`test_the_rows_are_the_ledger_with_the_same_conditions_and_order` | 検出 |
| Q03 | 既定に欠番を入れない（`Ledger.php`） | Tests: 1035, Assertions: 7478, Failures: 1. | `test_the_default_is_the_numbered_ones` | 検出 |
| Q04 | 既定に番号の無い取り下げも入れる（`Ledger.php`） | Tests: 1035, Assertions: 7478, Failures: 1. | `test_the_default_is_the_numbered_ones` | 検出 |
| Q05 | 進行中に差戻し中を入れない（`Ledger.php`） | Tests: 1035, Assertions: 7459, Failures: 5. | `test_in_progress_includes_every_step_and_a_returned_request`、`test_others_count_the_year_of_an_unnumbered_request_by_the_submitted_department`、`test_others_get_what_was_last_submitted`、`test_others_search_the_latest_of_several_submissions`、`test_others_search_what_was_last_submitted_while_it_is_returned` | 検出 |
| Q06 | 取り下げで絞らない（`Ledger.php`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_the_default_is_the_numbered_ones` | 検出 |
| Q07 | 判断の種類で絞らない（`Ledger.php`） | Tests: 1035, Assertions: 7480, Failures: 1. | `test_the_decision_filter` | 検出 |
| Q08 | 決裁日（から）を日本時間のまま UTC の列と比べる（`Ledger.php`） | Tests: 1035, Assertions: 7480, Failures: 1. | `test_the_decided_dates_are_days_of_the_japanese_calendar` | 検出 |
| Q09 | 決裁日（まで）に次の日の 0:00 を入れる（`Ledger.php`） | Tests: 1035, Assertions: 7480, Failures: 1. | `test_the_decided_dates_are_days_of_the_japanese_calendar` | 検出 |
| Q10 | 決裁日（まで）の日を入れない（`Ledger.php`） | Tests: 1035, Assertions: 7480, Failures: 1. | `test_the_decided_dates_are_days_of_the_japanese_calendar` | 検出 |
| Q11 | 申請者の 2 つ目からの語を見ない（`Ledger.php`） | Tests: 1035, Assertions: 7480, Failures: 1. | `test_the_applicant_is_found_by_part_of_the_name_and_by_every_word` | 検出 |
| Q12 | キーワードで本文を探さない（`Ledger.php`） | Tests: 1035, Assertions: 7463, Failures: 2. | `test_others_search_what_was_last_submitted_while_it_is_returned`、`test_the_keyword_is_found_in_the_subject_or_the_body_with_every_word` | 検出 |
| Q13 | キーワードの語のどれかでよい（OR）にする（`Ledger.php`） | Tests: 1035, Assertions: 7464, Failures: 3. | `test_a_request_withdrawn_after_edits_is_searched_by_what_was_last_submitted`、`test_others_search_the_latest_of_several_submissions`、`test_others_search_what_was_last_submitted_while_it_is_returned` | 検出 |
| Q14 | 他人の差戻し中・取り下げを今の中身で当てる（D19）（`Ledger.php`） | Tests: 1035, Assertions: 7458, Failures: 4. | `test_a_request_withdrawn_after_edits_is_searched_by_what_was_last_submitted`、`test_others_count_the_year_of_an_unnumbered_request_by_the_submitted_department`、`test_others_search_the_latest_of_several_submissions`、`test_others_search_what_was_last_submitted_while_it_is_returned` | 検出 |
| Q15 | 申請者本人の差戻し中も控えで当てる（`Ledger.php`） | Tests: 1035, Assertions: 7478, Failures: 3. | `test_a_request_withdrawn_after_edits_is_searched_by_what_was_last_submitted`、`test_others_count_the_year_of_an_unnumbered_request_by_the_submitted_department`、`test_others_search_what_was_last_submitted_while_it_is_returned` | 検出 |
| Q16 | 取り下げを控えで当てない（直しかけが残りうる状態から取り下げを外す）（`ApprovalStatus.php`） | Tests: 1035, Assertions: 7448, Failures: 3. | `test_a_request_withdrawn_after_edits_is_searched_by_what_was_last_submitted`、`test_a_withdrawn_request_with_a_number_shows_its_submitted_subject` | 検出 |
| Q17 | 部門で絞らない（`Ledger.php`） | Tests: 1035, Assertions: 7465, Failures: 2. | `test_others_search_what_was_last_submitted_while_it_is_returned`、`test_the_department_and_the_type_filters` | 検出 |
| Q18 | 種類で絞らない（`Ledger.php`） | Tests: 1035, Assertions: 7469, Failures: 2. | `test_others_search_what_was_last_submitted_while_it_is_returned`、`test_the_department_and_the_type_filters` | 検出 |
| Q19 | 番号の付いた申請を番号の年度で数えない（`Ledger.php`） | Tests: 1035, Assertions: 7480, Failures: 2. | `test_each_download_is_recorded_with_the_conditions_and_the_count`、`test_the_year_of_a_numbered_request_is_the_year_of_its_number` | 検出 |
| Q20 | 会社の期の始まりの月を見ない（5 月に決め打ち）（`Ledger.php`） | Tests: 1035, Assertions: 7481, Failures: 2. | `test_others_count_the_year_of_an_unnumbered_request_by_the_submitted_department`、`test_the_year_of_an_unnumbered_request_follows_the_company_of_its_department` | 検出 |
| Q21 | 期の始まりを UTC の 0:00 にする（`Ledger.php`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_the_year_of_an_unnumbered_request_follows_the_company_of_its_department` | 検出 |
| Q22 | 部門が無いときの守りを外す（`Ledger.php`） | OK (1035 tests, 7482 assertions) | — | 等価（部門が 1 つも無いときだけ効く守り。番号の無い申請には必ず申請部門があり、部門があれば期間の条件が 1 つ以上ある＝外しても SQL の結果は同じ） |
| Q23 | 日の古い順にする（`Ledger.php`） | Tests: 1035, Assertions: 7482, Failures: 1. | `test_the_order_is_by_day_then_number_then_submission` | 検出 |
| Q24 | 同じ日に番号のあるものを先にしない（`Ledger.php`） | Tests: 1035, Assertions: 7482, Failures: 1. | `test_the_order_is_by_day_then_number_then_submission` | 検出 |
| Q25 | 部門のアルファベットの順を見ない（`Ledger.php`） | Tests: 1035, Assertions: 7482, Failures: 1. | `test_the_order_is_by_day_then_number_then_submission` | 検出 |
| Q26 | 並びの日を UTC の日にする（`Ledger.php`） | Tests: 1035, Assertions: 7482, Failures: 1. | `test_the_order_is_by_day_then_number_then_submission` | 検出 |
| Q27 | 番号の無いものを発信の古い順にする（`Ledger.php`） | Tests: 1035, Assertions: 7482, Failures: 1. | `test_the_order_is_by_day_then_number_then_submission` | 検出 |
| Q28 | 行を id の並びのとおりにしない（`Ledger.php`） | Tests: 1035, Assertions: 7479, Failures: 1. | `test_rows_keep_the_given_order_and_read_everything_in_a_few_queries` | 検出 |
| Q29 | 年度の選択肢に番号の年度を入れない（`Ledger.php`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_the_years_run_from_this_year_back_to_the_oldest_request` | 検出 |
| Q30 | 発信日の年度を早い期の会社で数える（`Ledger.php`） | Tests: 1035, Assertions: 7482, Failures: 1. | `test_the_years_run_from_this_year_back_to_the_oldest_request` | 検出 |
| Q31 | 最初に提出した控えを読む（`ApprovalRequest.php`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_others_see_the_latest_of_several_submissions` | 検出 |
| Q32 | 他人の差戻し中・取り下げをどの回の控えでも当てる（`Ledger.php`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_others_search_the_latest_of_several_submissions` | 検出 |
| Q33 | 番号の無い申請の年度を他人にも直しかけの部門の期で数える（`Ledger.php`） | Tests: 1035, Assertions: 7480, Failures: 1. | `test_others_count_the_year_of_an_unnumbered_request_by_the_submitted_department` | 検出 |
| R01 | 申請者本人にも控えの中身を出す（`LedgerRow.php`） | Tests: 1035, Assertions: 7482, Failures: 3. | `test_others_get_what_was_last_submitted`、`test_others_see_the_latest_of_several_submissions`、`test_others_see_what_was_last_submitted_and_the_applicant_sees_the_current_content` | 検出 |
| R02 | ほかの人にも今の中身を出す（D19）（`LedgerRow.php`） | Tests: 1035, Assertions: 7478, Failures: 4. | `test_others_get_nothing_rather_than_the_current_content_when_the_revision_is_missing`、`test_others_get_what_was_last_submitted`、`test_others_see_the_latest_of_several_submissions`、`test_others_see_what_was_last_submitted_and_the_applicant_sees_the_current_content` | 検出 |
| R03 | 判断していない段階の意見・コメントも出す（`LedgerRow.php`） | OK (1035 tests, 7482 assertions) | — | 等価（判断していない段階は意見もコメントも空。取り消し〈`reopenStep()`〉が判断と一緒に消し、打ち切り〈`cancelRest()`〉は待ちとまだ届いていない段階だけ） |
| R04 | 可・否の社長のコメントも条件に出す（`LedgerRow.php`） | Tests: 1035, Assertions: 7482, Failures: 1. | `test_the_condition_is_only_for_a_conditional_decision` | 検出 |
| R05 | 前の回の段階も見る（`LedgerRow.php`） | OK (1035 tests, 7482 assertions) | — | 等価（段階は回の順・id の順に並び、どの回も 3 行あるので、`keyBy` の最後に残るのは今の回の段階） |
| V01 | ページの始まりを 1 ページずらす（`LedgerController.php`） | Tests: 1035, Assertions: 7458, Failures: 2. | `test_each_request_is_a_card_on_a_phone_and_a_row_on_a_pc`、`test_pages_of_fifty_keep_the_conditions` | 検出 |
| V02 | ページ送りが条件を運ばない（`LedgerController.php`） | Tests: 1035, Assertions: 7479, Failures: 1. | `test_pages_of_fifty_keep_the_conditions` | 検出 |
| V03 | 表の件名をエスケープしない（`index.blade.php`） | Tests: 1035, Assertions: 7480, Failures: 1. | `test_what_people_typed_is_escaped` | 検出 |
| V04 | カードの申請者をエスケープしない（`index.blade.php`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_what_people_typed_is_escaped` | 検出 |
| V05 | 戻ったときに絞り込みを元に戻さない（Bug #65）（`index.blade.php`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_the_filter_form_is_reset_whenever_the_page_is_shown` | 検出 |
| V06 | 外した条件の名前を出さない（`index.blade.php`） | Tests: 1035, Assertions: 7481, Failures: 2. | `test_conditions_outside_the_choices_are_named_and_not_used`、`test_unreadable_conditions_are_named` | 検出 |
| V07 | 状態の選んだ値を出さない（`index.blade.php`） | Tests: 1035, Assertions: 7473, Failures: 2. | `test_the_default_shows_the_numbered_ones_and_says_so_when_empty`、`test_the_form_shows_the_conditions_in_use` | 検出 |
| V08 | 部門の選んだ値を出さない（`index.blade.php`） | Tests: 1035, Assertions: 7474, Failures: 1. | `test_the_form_shows_the_conditions_in_use` | 検出 |
| V09 | 決裁のみ利用者のドロワーに台帳が無い（`sidebar_approval.blade.php`） | Tests: 1035, Assertions: 7480, Failures: 1. | `test_the_ledger_link_appears_only_after_launch` | 検出 |
| V10 | 基幹の展開版に台帳が無い（`sidebar.blade.php`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_the_base_sidebar_offers_the_ledger_after_launch` | 検出 |
| V11 | ホームに台帳のリンクが無い（`home-launched.blade.php`） | Tests: 1035, Assertions: 7482, Failures: 1. | `test_the_home_links_to_the_ledger` | 検出 |
| V12 | Excel のリンクが条件を運ばない（`index.blade.php`） | Tests: 1035, Assertions: 7479, Failures: 1. | `test_the_ledger_offers_the_excel_only_within_the_limit` | 検出 |
| V13 | 上限を超えても Excel のリンクを出す（`index.blade.php`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_the_ledger_offers_the_excel_only_within_the_limit` | 検出 |
| V14 | 0 件でも Excel のリンクを出す（`index.blade.php`） | Tests: 1035, Assertions: 7477, Failures: 1. | `test_the_ledger_offers_the_excel_only_within_the_limit` | 検出 |
| V15 | カードを PC にも出す（`index.blade.php`） | Tests: 1035, Assertions: 7463, Failures: 1. | `test_each_request_is_a_card_on_a_phone_and_a_row_on_a_pc` | 検出 |
| V16 | 画面で選択肢に無い条件を外さない（`LedgerController.php`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_conditions_outside_the_choices_are_named_and_not_used` | 検出 |
| X01 | 文字の欄を式として入れうる形にする（`LedgerExcel.php`） | Tests: 1035, Assertions: 7475, Failures: 1. | `test_text_that_looks_like_a_formula_stays_text` | 検出 |
| X02 | Excel の日付を UTC の日にする（`LedgerExcel.php`） | Tests: 1035, Assertions: 7475, Failures: 1. | `test_dates_are_excel_dates_of_the_japanese_day_and_the_amount_is_a_number` | 検出 |
| X03 | 日付の書式を付けない（`LedgerExcel.php`） | Tests: 1035, Assertions: 7476, Failures: 1. | `test_dates_are_excel_dates_of_the_japanese_day_and_the_amount_is_a_number` | 検出 |
| X04 | 金額を文字で入れる（`LedgerExcel.php`） | Tests: 1035, Assertions: 7480, Failures: 1. | `test_dates_are_excel_dates_of_the_japanese_day_and_the_amount_is_a_number` | 検出 |
| X05 | 見出しを固定しない（`LedgerExcel.php`） | Tests: 1035, Assertions: 7479, Failures: 1. | `test_the_heading_is_frozen_and_has_filter_buttons` | 検出 |
| X06 | 見出しに絞り込みのボタンを付けない（`LedgerExcel.php`） | Tests: 1035, Assertions: 7480, Failures: 1. | `test_the_heading_is_frozen_and_has_filter_buttons` | 検出 |
| X07 | 最初の 200 件しか書かない（`LedgerExcel.php`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_more_than_one_batch_of_rows_is_written` | 検出 |
| X08 | 行が無くても書式だけの行を作る（`LedgerExcel.php`） | Tests: 1035, Assertions: 7482, Failures: 1. | `test_the_rows_are_the_ledger_with_the_same_conditions_and_order` | 検出 |
| X09 | 上限ちょうどを断る（`LedgerController.php`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_too_many_requests_are_not_made_and_not_recorded` | 検出 |
| X10 | 件数の上限を見ない（`LedgerController.php`） | Tests: 1035, Assertions: 7477, Failures: 1. | `test_too_many_requests_are_not_made_and_not_recorded` | 検出 |
| X11 | Excel を出しても記録しない（`LedgerController.php`） | Tests: 1035, Assertions: 7477, Errors: 4. | `test_broken_text_in_the_conditions_is_left_out_and_the_excel_is_made`、`test_conditions_outside_the_choices_are_left_out_as_on_the_ledger`、`test_each_download_is_recorded_with_the_conditions_and_the_count`、`test_too_many_requests_are_not_made_and_not_recorded` | 検出 |
| X12 | 記録に条件を控えない（`LedgerController.php`） | Tests: 1035, Assertions: 7480, Errors: 2, Failures: 1. | `test_broken_text_in_the_conditions_is_left_out_and_the_excel_is_made`、`test_conditions_outside_the_choices_are_left_out_as_on_the_ledger`、`test_each_download_is_recorded_with_the_conditions_and_the_count` | 検出 |
| X13 | ファイルとして保存させない（inline）（`LedgerController.php`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_the_file_name_and_the_headers` | 検出 |
| X14 | ファイル名に日付を付けない（`LedgerExcel.php`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_the_file_name_and_the_headers` | 検出 |
| X15 | 断ったときに台帳の条件を戻さない（`LedgerController.php`） | Tests: 1035, Assertions: 7478, Failures: 1. | `test_too_many_requests_are_not_made_and_not_recorded` | 検出 |
| X16 | Excel で選択肢に無い条件を外さない（`LedgerController.php`） | Tests: 1035, Assertions: 7481, Failures: 1. | `test_conditions_outside_the_choices_are_left_out_as_on_the_ledger` | 検出 |
| X17 | 件数の上限を測る前の 2,000 に戻す（`approval.php`） | Tests: 1035, Assertions: 7482, Failures: 1. | `test_the_default_limit_is_a_thousand` | 検出 |

- [ ] **Step 3: 表と違ったものを調べ、検出できなかった変異にテストを足す**

⚠ **等価**（緑が正しい）と書いたものは、なぜ等価かを 1 行で確かめる（読み違えて「守られていない」としない）。等価でないのに緑のものは、その Task のテストに 1 本足し、足したテストが変異で赤・元に戻して緑になることを確かめてからコミットする。

- [ ] **Step 4: 結果をこの計画に追記してコミット**

検出／当初検出漏れ→追加で検出／等価を区別して、この計画の末尾に「Task 5 の実測記録」として書き足す。

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add docs/superpowers/plans/2026-10-05-approval-phase4b.md tests/
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
test(approval): 段階4b の変異テストの結果を記録する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

写し（`<scratchpad>/p4b-mutation`）は、この Task が済んだら消してよい（`rm -rf` の前に中身が写しであることを確かめる）。

---

## Task 6: 手元のブラウザでの確認と、利用者に見せる写真と Excel（設計書 §6）

**Files:** なし（測るだけ）

テストが原理的に測れない領域（台帳の見た目・スマホの幅・絞り込みの欄の並び・ページ送り・Excel を開いたときの見た目）を見る。**本番では使い始めるまで台帳も Excel も出ないので、利用者に見てもらうのはここで撮る写真と Excel のクイックルック**。WT の HEAD の写し（scratchpad）＋使い捨ての SQLite ＋ `artisan serve`。ブラウザは Playwright（ログインは利用者の決まり「手元の画面にログイン」のとおり、試しのパスワードを画面にも記録にも出さない）。Excel は開かずに確かめる（利用者の決まり。クイックルックと読み戻し）。

- [ ] **Step 1: 写しと使い捨ての環境を作る**

⚠ Bash の呼び出しごとにシェルが新しくなるので、使い捨ての設定は 1 つのファイルにまとめ、以降のコマンドの先頭で `source` する。⚠ WT にも写しにも `.env` を作らない。⚠ `APP_LOCALE=ja`・`APP_FALLBACK_LOCALE=ja` を入れる。

```bash
SCR=<scratchpad>/p4b-browser && mkdir -p "$SCR" && git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 archive HEAD | tar -x -C "$SCR" && cp -Rc /Users/masanori/site/manage/.claude/worktrees/approval-phase3/vendor "$SCR/vendor"
LOCAL=<scratchpad>/approval-phase4b-local.sh
cat > "$LOCAL" <<SH
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
export APP_LOCALE=ja
export APP_FALLBACK_LOCALE=ja
export APP_URL=http://127.0.0.1:8771
export DB_CONNECTION=sqlite
export DB_DATABASE="<scratchpad>/approval-phase4b.sqlite"
export MAIL_MAILER=log
export QUEUE_CONNECTION=sync
export TRIAL_PASSWORD="$(php -r 'echo bin2hex(random_bytes(6));')"
SH
source "$LOCAL" && touch "$DB_DATABASE" && cd "$SCR" && php artisan migrate --force && ln -s /Users/masanori/site/manage/node_modules node_modules && ./node_modules/.bin/vite build
```

- [ ] **Step 2: 試しのデータを入れる**（テストの土台をそのまま使う。⚠ ログイン用の使い捨てルートを作らない）

計画を書く段階に使った入れ方（`~/.claude/plans/approval-phase3-tasks/4b/seed.php`。61 件: 2026 年 5 月〜8 月の決裁 52 件〈ページ送りが出る〉・DAD の部門の決裁・否決・長い件名・条可〈審査は保留とコメント〉・可〈種類「工事の発注」・関連する決裁No〉・番号のあと取り下げた欠番・進行中・差戻しのあと直しかけ・番号の無い取り下げ。部門は住宅事業部 J・不動産事業部 M・DAD の土木事業部 D。申請者は「申請 花子」「山田 太郎」）:

```bash
source <scratchpad>/approval-phase4b-local.sh && cd <scratchpad>/p4b-browser && php artisan tinker --execute="$(cat ~/.claude/plans/approval-phase3-tasks/4b/seed.php)"
```

Expected: `requests=61` と、決裁の管理者・申請者のログイン名。パスワードは `source` したシェルの `$TRIAL_PASSWORD`（値を出さない）。

画面を出す（**バックグラウンドで**。止めるまで動き続ける）:

```bash
source <scratchpad>/approval-phase4b-local.sh && cd <scratchpad>/p4b-browser && php artisan serve --port=8771
```

- [ ] **Step 3: 見ること**（1440px と 375px の両方。決裁の管理者でログイン）

| # | 画面 | 見ること |
|---|---|---|
| 1 | 台帳（既定） | 件数 58（決裁No の付いたもの）・「Excel に出力」・表の 8 列（申請部門の列が潰れない）・同じ日（10/5）は R8-J-038 → R8-J-039 の順・欠番（R8-J-040）は「取り下げ」で決裁日が「—」・長い件名が折り返す・ページ送り（1・2） |
| 2 | 台帳（375px） | 絞り込みの欄が 2 列・結果の件数とカードが 1 画面目に見える・カード（状態・番号・件名・部門と申請者・決裁日と判断と金額）・`main` の横のはみ出し 0 |
| 3 | 状態「進行中」（管理者） | 「出した件名：外構工事の追加 700,000円」（最後に提出した中身）・「会議室のプロジェクターの購入」 |
| 4 | 状態「進行中」（申請者「申請 花子」でログインし直す） | 「直しかけ：外構工事の追加（見積 2 社）650,000円」（申請者本人は今の中身） |
| 5 | 絞り込み（年度・部門・キーワード・決裁日の期間） | プルダウンを変えるとすぐ絞り込む・文字と日付は「絞り込む」で・選んだ値が欄に残る・「条件を消す」・プルダウンを変えてから「戻る」で欄が表の条件に戻る |
| 6 | Excel（`page.request` で取り出して `Content-Type`・`Content-Disposition` を見る。中身は同じ写しで `LedgerExcel::build()` をファイルに書いてクイックルック `qlmanage -t -s 1600`） | 見出しの 15 列・日付・金額の桁区切り・長い件名の折り返し・条可の行の審査の意見とコメントと条件・記録が 1 行増える（`kind = excel`・条件と件数） |
| 7 | 全画面 | `main.scrollWidth === main.clientWidth` を 1440 / 1200 / 375px で（Bug #29）・コンソールのエラーと警告が 0 件 |

- [ ] **Step 4: 利用者に見せる写真と Excel**

1440px と 375px の台帳（既定・進行中）と、Excel のクイックルックの画像を撮って scratchpad に保存し、利用者に送る（`SendUserFile`）。

- [ ] **Step 5: コンパイル済みビューを lint する**

⚠ `view:cache` の成功表示だけでは足りない（Bug #21 / #26 / #30）。

```bash
source <scratchpad>/approval-phase4b-local.sh && cd <scratchpad>/p4b-browser && php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done; php artisan view:clear
```

Expected: INVALID 0 件。

- [ ] **Step 6: 片付けて結果を記録する**

`artisan serve` を止めたことを確かめてから、写しと使い捨てのファイルを消す（`rm -rf` の前に、消すのが scratchpad の写しであることを `pwd` と `ls` で確かめる）。WT の `git status --porcelain` が空であることも確かめる。見たことと見つけた不具合を、この計画の末尾に「Task 6 の実測記録」として書き足してコミットする（`docs(approval): 段階4b の画面と Excel の確認の結果を記録する`）。

---

## Task 7: ドキュメント

**Files:**
- Modify: `docs/BACKLOG.md`（「決裁申請 段階4」の節の見出しと 4b の小見出し）・`CLAUDE.md`（決裁の注意の行と、モジュールの表の決裁の行）

- [ ] **Step 1: BACKLOG の 4b の小見出しを書き換える**（今の「未着手。…」と「4a から持ち越し」の 2 段落を、下の中身に置き換える。節の見出しの「4a 本番反映済み・4b 未着手」は「4a 本番反映済み・4b 実装済み・本番反映前」にする。⚠ 末尾に足さない）

````markdown
### 4b（決裁台帳 ⑤・Excel 出力・Excel の出力の記録）

実装計画: @docs/superpowers/plans/2026-10-05-approval-phase4b.md（Task 0〜8）。worktree `.claude/worktrees/approval-phase3`（4a と同じ）。

- 表: `approval_download_logs.request_id` を NULL 可に（Excel の行は申請が無い）・`filters`（JSON。絞り込みの条件）・`request_count`（件数）を足した。本番の SQL は `database/sql/2026-10-05-approval-phase4b.sql`（ALTER 1 文）。本番反映は **DB が先・`./deploy.sh` が後**。本番の表は 0 行
- 出力の記録は、添付・PDF・Excel のどれも `DownloadLogger` だけが書く（`kind` は `ApprovalDownloadLog::KIND_*`。4a からの持ち越しを片付けた）。ほかの場所が直接書かないことを `DownloadLoggerTest` が走査で見る
- 台帳の問い合わせは `Ledger` の 1 か所（画面と Excel が同じものを使う）。見られる範囲は `RequestVisibility::apply`・下書きは出さない。**中身（部門・種類・件名・本文）は申請者本人以外には最後に提出した控えで出し・当てる**（D19。他人の差戻し中・取り下げの申請だけを `approval_revisions` の今の回の JSON で当てる。この 2 つの状態は `ApprovalStatus::mayDifferFromSubmission()` の 1 か所に置き、関連する決裁No の候補〈`RelatedNumberController`〉も使う）。1 行の中身は `LedgerRow`（`ApprovalRequest::lastRevision()` を先に読む）
- 並び（D22）は日本の暦の日で比べるので SQL で並べず、`Ledger::sortedIds()` が軽い列を読んで PHP で並べる（同じ日は番号のあるものを部門のアルファベット・連番の順、番号の無いものは発信の新しい順）。5,000 件で画面 0.12 秒
- 絞り込みは GET。読めない値は断らずに外して画面で知らせる（`LedgerFilter`。`validate()` で前の画面へ戻すと同じ URL を開き直し続ける）。キーワードと申請者は空白で分けた語のどれも含むもの。プルダウンは変えた瞬間に送り、`pageshow` で絞り込みのフォームを元に戻す（Bug #65）
- Excel は `LedgerExcel`（PhpSpreadsheet の書き出しの初めての例）。文字の欄はすべて `setCellValueExplicit(…, TYPE_STRING)`（式にしない）・日付は日本の暦の日の Excel の日付・金額は数・見出しを固定して絞り込みのボタン。**件数の上限は 1,000**（`config('approval.ledger.excel_limit')`。メモリをいちばん使うのは審査のコメントと条件の長さで、入力の上限の中身〈コメント 2,000 文字〉の 1,000 件は Laravel 込みで 95MB ほど・2,000 件は本番の上限 128M を超える）。超えたら作らず、記録もしない。選択肢に無い年度・部門・種類と壊れた文字（不正な UTF-8）の条件は、画面と Excel の両方で外して知らせる
- 計画で決めた細部（計画 §0.9）: ホームの入口は上の「新しい申請」「自分の申請」の行／Excel は作れなかったときの知らせを作らない（失敗はメモリの不足くらいで捕まえられない）／設計書 §4.1 の「拡張が無ければ起動時に止まる」は誤り（起動時の確かめは PHP の版だけ。本番を読み取って拡張がそろっていることを確かめた）
- 受け入れた隙間（計画 §0.10）:
  - キーワードの探し方が本番の MySQL では当てる列で少し違う（申請の行の列は大文字小文字・かなの違いを区別せず、控えの JSON の中〈他人の差戻し中・取り下げ〉は区別する）— 起きたとき: 他人の差戻し中・取り下げの申請を、大文字小文字やかなを変えた言葉で探すと当たらないことがある ／ 塞ぐなら: 控えの側にも照合順序を付ける
  - 台帳を開くたびに、当たる申請の軽い列をすべて読んで並べる — 起きたとき: 数万件で遅くなる（5,000 件で 0.12 秒・申請は年に数百件）／ 塞ぐなら: 日本の日付の列を表に持つ
  - 本番の Web の PHP のメモリの上限は CLI と同じ 128M と見ている（4a と同じ）— 起きたとき: 小さければ、コメントの長い 1,000 件の Excel を作れない（500 と laravel.log）／ 塞ぐなら: 段階6 の受け入れ確認で多めの台帳の Excel を本番で 1 回出す
  - 番号のあと取り下げた欠番は決裁日が空で、並びは発信日の日・決裁日の期間で絞ると出ない ／ 塞ぐなら: 取り下げた日時を欠番の日にする
  - 年度の選択肢の和暦は期の始まりの月がいちばん早い会社で数える（今の会社はどれも R1 で同じ）
  - キーワード・申請者の `%` と `_` を逃がさない（アプリのほかの検索と同じ）
- 実装のコミット（計画のコミットの次から。古い順）: Task 1〜4 の 4 本と記録（Task 5・6）。`git log` で見る
````

- [ ] **Step 2: CLAUDE.md を直す**

`## 主要モジュール`（モジュールの表）の決裁の行の「申請の回覧と進行中の申請の管理」の後ろに「・決裁台帳と Excel（⑤。問い合わせは `Ledger` だけ・出力の記録は `DownloadLogger` だけ）」を足し、行の頭の「決裁申請 段階1・2a・2b・3a・3b」を「決裁申請 段階1〜4b」にする。決裁の注意の 3 行（PDF・印・フォント）の後ろに 1 行足す:

```markdown
- 決裁台帳（⑤）と Excel は `App\Support\Approval\Ledger` の 1 か所で問い合わせる（見られる範囲は `RequestVisibility`）。中身は申請者本人以外には最後に提出した控えで出し・当てる（`LedgerRow`・`Ledger::whereContent()`）。並びは日本の暦の日で比べるので SQL で並べない（`Ledger::sortedIds()`）。Excel の文字の欄は `setCellValueExplicit(…, TYPE_STRING)` で入れる（式にしない）。出力の記録（添付・PDF・Excel）は `DownloadLogger` だけが書く
```

- [ ] **Step 3: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add docs/BACKLOG.md CLAUDE.md
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
docs(approval): 段階4b の作り方と注意を BACKLOG と CLAUDE.md に書く

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

コミットのあとで `git merge-tree --write-tree approval-phase3 13.x` が衝突を出さないことを確かめる（BACKLOG は別の会話も書く）。

---

## Task 8: 本番反映（利用者の了承を取ってから・親の会話が行う）

> ⚠ **サブエージェントに任せない。** 本番への `ssh`・`scp`・`./deploy.sh` は、それぞれ利用者の了承を取ってから行う。本番のファイルの削除は利用者が行う（実行する 1 行を渡す）。`.env` は読まない。
> ⚠ 本番のシェルは csh なので、`/bin/sh` の heredoc を `ssh` に流す（段階1〜4a と同じ作法）。PHP は `/usr/local/php/8.3/bin/php` を明示する（既定の `php` は 7.4）。
> ⚠ 別の会話（manage）が `13.x` を頻繁に進めて本番に出している。反映の前に `SendMessage` で、反映が済むまで `13.x` の早送りと `./deploy.sh` を待ってもらう（4a と同じ）。

- [ ] **Step 1: 了承を求める（選択式）**

伝えること: ①**DB が先・`./deploy.sh` が後**（新しいコードが Excel の記録を申請の欄が空の行で書くので、逆だと Excel の出力が止まる。使い始める前なので誰も開けないが、順番は守る）②本番の表 `approval_download_logs` の申請の欄を空でもよくし、列を 2 つ足す（行は 0。データは変えない）③**部品の追加は無い**（PhpSpreadsheet は前からある。`composer install` は要らない）④**使い始める前なので、利用者に見える変化は無い**（台帳・Excel・入口は使い始めるまで出ない）⑤戻すときは、`13.x` の前のコミットで `./deploy.sh`（足した列と NULL 可は残しても害は無い）。

- [ ] **Step 2: 反映前に本番を読み取る**（読み取りだけ）

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage || exit 1
/usr/local/php/8.3/bin/php artisan route:list --name=approvals. --json | /usr/local/php/8.3/bin/php -r '$r = json_decode(stream_get_contents(STDIN), true); echo "approvals=", count($r), PHP_EOL;'
/usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
$schema = $db->getSchemaBuilder();
echo "filters=", $schema->hasColumn("approval_download_logs", "filters") ? "ある" : "ない", " request_count=", $schema->hasColumn("approval_download_logs", "request_count") ? "ある" : "ない", PHP_EOL;
$c = $db->selectOne("SHOW FULL COLUMNS FROM approval_download_logs WHERE Field = ?", ["request_id"]);
echo "request_id null=", $c->Null, PHP_EOL;
echo "launched_at=", var_export(App\Models\ApprovalSetting::current()->launched_at, true), PHP_EOL;
echo "requests=", $db->table("approval_requests")->count(), " download_logs=", $db->table("approval_download_logs")->count(), PHP_EOL;
echo "memory_limit=", ini_get("memory_limit"), PHP_EOL;
'
/usr/local/php/8.3/bin/php -m | grep -i -E '^(zip|xmlwriter|gd)$' | tr '\n' ' '; echo
ls -la storage/logs/laravel.log
SH
```

Expected: `approvals=51`（4a のまま）／ `filters=ない request_count=ない` ／ `request_id null=NO` ／ `launched_at=NULL` ／ 申請 0・出力の記録 0（使い始める前）／ `memory_limit=128M` ／ `gd xmlwriter zip`（並びは php -m のまま）／ `laravel.log` の日付。**1 つでも違えば止まり、利用者に伝える**。

- [ ] **Step 3: `13.x` へ早送りで取り込む**（手元）

```bash
cd /Users/masanori/site/manage && git status --short && git merge-base --is-ancestor 13.x approval-phase3 && echo "FF できる" || echo "13.x が進んでいる"
```

「FF できる」なら:

```bash
git -C /Users/masanori/site/manage merge --ff-only approval-phase3 && git -C /Users/masanori/site/manage log --oneline -3 && ls /Users/masanori/site/manage/database/sql/2026-10-05-approval-phase4b.sql
```

「13.x が進んでいる」なら止まり、取り込み方（WT で `git merge 13.x` をしてから全件を流し直す、など。rebase しない）を利用者に選んでもらう（ほかの会話の作業が入っている）。

- [ ] **Step 4: DB を先に変える**

(a) SQL を本番の置き場所へ送る（`./deploy.sh` もあとで同じ場所へ同じものを送る）:

```bash
scp /Users/masanori/site/manage/database/sql/2026-10-05-approval-phase4b.sql mitsuwa-ud@www3586.sakura.ne.jp:apps/manage/database/sql/
```

(b) 流す前に、文の数と頭を見る（まだ何も変えない）:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$sql = preg_replace("/^--.*$/m", "", file_get_contents(base_path("database/sql/2026-10-05-approval-phase4b.sql")));
$statements = array_values(array_filter(array_map("trim", explode(";", $sql))));
echo "statements=", count($statements), PHP_EOL;
foreach ($statements as $i => $s) { echo $i + 1, ": ", strtok($s, "\n"), PHP_EOL; }
'
SH
```

Expected: `statements=1`（`ALTER TABLE \`approval_download_logs\``）。

(c) 流す（**すでに流した形跡があれば流さずに止まる**）:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
$schema = $db->getSchemaBuilder();
$traces = array_keys(array_filter([
    "approval_download_logs.filters"       => $schema->hasColumn("approval_download_logs", "filters"),
    "approval_download_logs.request_count" => $schema->hasColumn("approval_download_logs", "request_count"),
]));
if ($traces !== []) {
    echo "STOP: すでに流した形跡がある: ", implode(", ", $traces), PHP_EOL;
} else {
    $sql = preg_replace("/^--.*$/m", "", file_get_contents(base_path("database/sql/2026-10-05-approval-phase4b.sql")));
    foreach (array_values(array_filter(array_map("trim", explode(";", $sql)))) as $i => $statement) {
        $db->statement($statement);
        echo "OK ", $i + 1, PHP_EOL;
    }
}
'
SH
```

Expected: `OK 1`。⚠ 例外が出たら、その文言を利用者に伝えて止まる。流し直さない。

(d) 流した後を読み取る:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
foreach ($db->select("SHOW FULL COLUMNS FROM approval_download_logs WHERE Field IN (?, ?, ?)", ["request_id", "filters", "request_count"]) as $c) {
    echo $c->Field, " ", $c->Type, " null=", $c->Null, " comment=", $c->Comment, PHP_EOL;
}
foreach ($db->select("SELECT CONSTRAINT_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY COLUMN_NAME", ["approval_download_logs"]) as $k) {
    echo "fk ", $k->CONSTRAINT_NAME, " ", $k->COLUMN_NAME, PHP_EOL;
}
echo "download_logs=", $db->table("approval_download_logs")->count(), PHP_EOL;
'
SH
```

Expected: `request_id bigint unsigned null=YES comment=添付・PDF の申請（Excel は空）`・`filters json null=YES comment=Excel の絞り込みの条件`・`request_count int unsigned null=YES comment=Excel に出した件数` ／ 外部キー 3 つ（`fk_approval_download_logs_attachment`・`fk_approval_download_logs_request`・`fk_approval_download_logs_user`）が残っている ／ 行の数は Step 2 と同じ。

- [ ] **Step 5: 読み込みの表を作り直す**（手元の main repo で。部品の追加は無いので `composer install` は打たない）

```bash
cd /Users/masanori/site/manage && test ! -e vendor/bin/phpunit && composer dump-autoload --no-dev --optimize && test ! -e vendor/bin/phpunit && git status --short && grep -cF 'App\\Support\\Approval\\Ledger' vendor/composer/autoload_classmap.php
```

Expected: `vendor/bin/phpunit` が無いまま（dev の部品が混ざっていない）・`git status` に何も出ない・最後の数が `4`（`Ledger`・`LedgerExcel`・`LedgerFilter`・`LedgerRow` が読み込みの表に入った。今は `0`）。⚠ **main repo の cwd で行う**（worktree から行うと autoloader に worktree のパスが焼き込まれる）。⚠ 表を作り直さなくても新しいクラスは PSR-4 で読めるが（今の表は「表に無いものは PSR-4 へ回す」形）、4a と同じく表を今のコードにそろえておく（設計書 §7 の 3）。

- [ ] **Step 6: 反映**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

Expected: exit 0・6 段すべて成功。

- [ ] **Step 7: 本番で確かめる**（すべて読み取り。台帳は申請が無いので、問い合わせと Excel を空のまま作れるかだけを試す）

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage || exit 1
n=0; bad=0
for f in storage/framework/views/*.php; do n=$((n+1)); /usr/local/php/8.3/bin/php -l "$f" >/dev/null 2>&1 || { bad=$((bad+1)); echo "INVALID: $f"; }; done
echo "views=$n invalid=$bad"
/usr/local/php/8.3/bin/php artisan route:list --name=approvals. --json | /usr/local/php/8.3/bin/php -r '$r = json_decode(stream_get_contents(STDIN), true); echo "approvals=", count($r), PHP_EOL;'
/usr/local/php/8.3/bin/php artisan tinker --execute='
echo "launched_at=", var_export(App\Models\ApprovalSetting::current()->launched_at, true), PHP_EOL;
foreach (["App\\Support\\Approval\\DownloadLogger", "App\\Support\\Approval\\Ledger", "App\\Support\\Approval\\LedgerFilter", "App\\Support\\Approval\\LedgerRow", "App\\Support\\Approval\\LedgerExcel", "App\\Http\\Controllers\\Approval\\LedgerController"] as $c) {
    echo $c, "=", class_exists($c) ? "ok" : "NG", PHP_EOL;
}
echo "excel_limit=", config("approval.ledger.excel_limit"), PHP_EOL;
$admin = App\Models\User::whereHas("approvalMember", fn ($q) => $q->where("is_admin", true))->first();
$ids = App\Support\Approval\Ledger::sortedIds($admin, App\Support\Approval\LedgerFilter::defaults());
$t = microtime(true);
$xlsx = App\Support\Approval\LedgerExcel::build($admin, $ids);
echo "ids=", count($ids), " xlsx=", bin2hex(substr($xlsx, 0, 4)), " bytes=", strlen($xlsx), " seconds=", round(microtime(true) - $t, 2), " peak_mb=", round(memory_get_peak_usage(true) / 1048576, 1), PHP_EOL;
echo "download_logs=", app("db")->table("approval_download_logs")->count(), PHP_EOL;
'
ls -la storage/logs/laravel.log
SH
```

Expected: `invalid=0` ／ `approvals=53`（台帳と Excel の 2 本が増えた）／ `launched_at=NULL` ／ 6 クラスとも `ok` ／ `excel_limit=1000` ／ `ids=0`・`xlsx=504b0304`（ZIP の頭）・数秒以内 ／ `download_logs` は Step 2 と同じ（試しの Excel は記録しない）／ `laravel.log` の日付が Step 2 と同じ＝反映のあとのエラーは 0 件。⚠ 決裁の管理者がいなければ（`$admin` が null）、そこで止まって利用者に伝える（4a の確かめでは利用者は決裁の管理者）。

- [ ] **Step 8: 利用者の Chrome で見るだけ**（決裁の管理者でログイン済みの画面。フォームは送らない。URL に `index.php/` が要る）

決裁のホーム（`…/index.php/approvals`）が「準備中」のまま・サイドバーに「決裁台帳」が無い（使い始める前）・`…/index.php/approvals/ledger` を開くと決裁のホームへ戻される・コンソールのエラー 0。

- [ ] **Step 9: 記録**

BACKLOG の 4b の小見出しに「本番反映（日付）」の表（4a と同じ形）を足してコミットする（`docs: 決裁 段階4b を本番に出した記録を残す`）。別の会話（manage）に済んだと伝える。push は利用者の指示があってから。

---

## 計画を書く段階の実測（2026-10-05〜06）

### 土台と試作

- 土台は `13.x` = `99d5f1ed`（4a を本番に出した記録 `4ae494a6` のあと、別の会話〈manage〉が不動産のテストを足して本番に出したもの。決裁のファイル・`composer`・`routes`・`database` の変更は無い。はじめ `e5a2406d` で作り、その上に BACKLOG の記録が 1 つ足された `99d5f1ed` で作り直した）。WT `approval-phase3`（`4ae494a6`）は `13.x` の祖先なので、計画のコミットの前に早送りした
- 試作: scratchpad の写し（`git archive 13.x`）を `git init` して base `63e7eaa`・枝 `proto` に Task ごとに 1 コミット（task01〜04）。手直しはその Task のコミットへ畳み込んで組み直した（最後の形がこの計画のコード）。差分のファイルは `~/.claude/plans/approval-phase3-tasks/4b/patches/`（土台の写しに 4 本を `--include='tests/*'` → `--exclude='tests/*'` の順に当てると、試作の最後の木と同じになることを確かめた。点検の前の差分は `patches/old-before-review/`）
- ⚠ 2026-10-05 20:30 に Mac が再起動して scratchpad（試作・変異の写し・使い捨ての MySQL・測定の途中）が消えた。差分のファイルから作り直して測り直した（記録の道具と差分を `~/.claude/plans/` に置いていたので失わなかった）
- 道具は 4a の写し（`~/.claude/plans/approval-phase3-tasks/4b/`: `fullsuite.sh`・`run-full.sh`・`redfirst.py`・`mutate.py`〈一覧だけ 4b〉・`plan/build.py`〈SUBJECTS と VERDICTS を 4b〉・`rules.md`）
- 全件（各段）: base 3,643 本 → task01 3,650 本 → task02 3,685 本 → task03 3,698 本 → task04 3,714 本（どの段も OK・1 段およそ 3 分）

### 本番の読み取り（利用者の了承のあと・読むだけ。2026-10-05）

| 見たこと | 結果 |
|---|---|
| PHP（CLI 8.3） | 8.3.32・`memory_limit=128M`・`~/www/php.ini` は `upload_max_filesize`・`post_max_size` だけ |
| PhpSpreadsheet が要る拡張 | `ctype dom fileinfo filter gd iconv json libxml mbstring SimpleXML xml xmlreader xmlwriter zip zlib` がすべてある・`ZipArchive`・`XMLWriter` のクラスがある |
| MySQL | 8.0.40 |
| `approval_download_logs` | 2a の形のまま（`request_id` は NOT NULL・外部キー 3 つ〈`fk_approval_download_logs_attachment`・`_request`・`_user`〉）・0 行 |
| 決裁の行の数・設定 | 申請 0・控え 0・`launched_at=NULL` |
| `laravel.log` | Jun 18 17:41 のまま |
| 本番のコード | git は無い（`./deploy.sh` が送ったもの） |

### Excel のメモリと時間（PhpSpreadsheet 5.9.0）

Laravel を通さない試し（`~/.claude/plans/approval-phase3-tasks/4b/spike-xlsx.php`・15 列・件名 90 文字・コメント 400 文字ほど・行ごとに違う文字）: 1,000 件でピーク 44MB・0.4 秒／2,000 件で 57MB・0.8 秒／3,000 件で 70MB・1.2 秒／5,000 件で 101MB・2.1 秒（PHP の起動の分を含む）。

試作の台帳の Excel（PHPUnit の中で、決裁済みの申請を N 件作って `approvals.ledger.excel` を開く。本文 1,500 文字・コメント 440 文字・審査と社長のコメントあり）。「増えた分」は開く前からのピークの増え（PHPUnit とテストのデータの分を除く）:

| 件数 | 増えた分 | 時間 | ファイル |
|---|---|---|---|
| 500 | 36.0MB | 0.43 秒 | 42KB |
| 1,000 | 46.0MB | 0.79 秒 | 76KB |
| 2,000 | 59.2MB | 1.55 秒 | 145KB |
| 3,000 | 76.5MB | 2.39 秒 | 213KB |

はじめこの表から上限を 2,000 にしたが、点検の担当が「メモリを決めるのはコメントの長さで、入力の上限の中身では 2,000 件で 128M を超える」と指摘した。**入力の上限の中身**（件名 100 文字・実施時期 50 文字・関連する決裁No 10 個・審査のコメントと条件〈社長のコメント〉2,000 文字。2026-10-06 に測り直した）:

| 件数 | 増えた分 | 時間 | ファイル |
|---|---|---|---|
| 500 | 46.1MB | 1.88 秒 | 52KB |
| 1,000 | 68.2MB | 1.90 秒 | 98KB |
| 1,500 | 95.5MB | 2.70 秒 | 143KB |
| 2,000 | 118.7MB | 4.71 秒 | 187KB |

（点検の担当の測り方〈`LedgerExcel::build()` の前後の差〉では 1,000 件で 81.7MB・1,500 件で 106.8MB・2,000 件で 128.9MB。本文は 20,000 文字にしてもほぼ変わらない）

→ 上限を **1,000**（Laravel の起動の分 約 25MB を足して 95〜107MB ほど・本番の上限 128M）。測る道具は `~/.claude/plans/approval-phase3-tasks/4b/measure/Measure/ExcelMemoryTest.php`（`LEDGER_N=件数`・`LEDGER_MAX=1` で上限の中身。コミットしない）。台帳の画面（50 件・並べる id は全件）は 1,000 件で 0.08 秒・3,000 件で 0.10 秒・5,000 件で 0.12 秒（増えた分 14〜18MB）。

### 試作で見つけて直したこと（計画の形に入っている）

1. 日付を読む自前の関数を `date()` と名付けたら、`ClockReadScanTest` が「時計の読み取り」と数えて赤 → `calendarDay()`（§0.6）
2. 問い合わせの数のテストで、1 回目だけ見る人の決裁の印（`approval_members`）を読むので数が 1 つ違った → 先に 1 回開いてから数える（N+1 ではない）
3. 0 件の Excel で、書式だけの空の行ができていた（`getHighestRow()` が 2）→ 行があるときだけ書式を付ける
4. PC の幅で申請部門の列が 1 文字幅に潰れた（件名の列が広がる）→ 列に最小の幅（`min-w-[8rem]`）
5. スマホの幅で、絞り込みの欄だけで 1 画面を使い、結果が見えなかった → 欄を 2 列・2 列で欠けの出ない並び（§0.4）
6. MySQL で流すと、記録の条件（JSON）のキーの並びが変わってテストが赤（MySQL は JSON のキーを並べ替えて返す）→ テストは並びに頼らずに比べる（§0.2）
7. Excel のクイックルックで「審査の意見」の見出しと「実施時期」が切れていた → 列の幅を 10・18 に
8. 変異の一覧を作っていて、200 件ずつ読む所を「最初の 200 件だけ」にしても気づくテストが無かった → 205 件の Excel のテスト（Review Focus の 4）
9. 空の値（`?q=`）を条件として数えないこと（ふだんは `ConvertEmptyStringsToNull` が null にするが、部品として空も外す）

別の担当（opus）に試作の差分を点検してもらい（2026-10-06・Mac の再起動で 1 度止まったので再開した）、指摘を直した:

10. **[Important] Excel の上限 2,000 件は、コメントが入力の上限の申請ばかりだと 128M を超える** → 入力の上限の中身で測り直して 1,000 件に（上の表・§0.5）。上限の数を見張るテスト（`test_the_default_limit_is_a_thousand`）を足した
11. **[Important] 2 回以上提出した申請で「最後に提出した控え」を確かめるテストが無かった**（`latestOfMany` を `oldestOfMany` にしても、控えの回の条件を外しても緑）→ 3 回目を直しかけている申請で、表示（`LedgerRowTest`）と検索（`LedgerQueryTest`）のテストを足した（変異 Q31・Q32）
12. 件数の上限ちょうどを見ていなかった（`>` を `>=` にしても緑）→ 上限ちょうどで出すことを見る（変異 X09）
13. 番号の無い申請の年度を、ほかの人には提出した部門の会社の期で数えることを見ていなかった → 差戻し中に 6 月始まりの会社の部門へ直しかけた申請のテスト（変異 Q33）
14. 壊れた文字（`?q=%FF`）で、台帳は黙って条件を外し、Excel は記録の JSON にできずに 500 → 読むときに外して知らせる（`mb_check_encoding`。変異 F12）
15. 選択肢に無い年度・部門・種類（`?department=99999`）で、0 件なのにプルダウンは「すべて」に見えた → `LedgerFilter::within()` で外して知らせる（画面と Excel の両方。変異 F13〜F15・V16・X16）
16. 「直しかけが残りうる状態」が `Ledger` と `RelatedNumberController` に別々に書いてあった → `ApprovalStatus::mayDifferFromSubmission()` の 1 か所に（§0.7）
17. 使い始める前の Excel のテストに、必ず真になる行（申請を 1 件も作っていないのに「下書き以外は 0 件」）があった → 外した
18. 変異テストで、表の件名のエスケープを外しても（V03）緑だった → エスケープのテストが「キーワード <script>」で絞った画面を見ていて、行が 0 件で空振りしていた。行のある画面で、件名と申請者がカードと表の両方でエスケープされて出る（数が 2 つずつ）ことを見る形に直し、キーワードの欄は別の画面で見る（V03・V04 はこの直しのあとのテストで測り直した）
- 点検の担当が「問題ない」とした主な観点: 見られる範囲（`RequestVisibility::apply` を最初に掛け、残りはすべて AND・OR はどれも括弧の中）・D19 の表示と検索・年度と期間と並びの日本時間・式の注入・記録の書き方・Bug #54・#61・#64・#65・#68 の書き方。点検の担当の写しで全件 3,705 本・決裁 930 本が緑、使い捨ての MySQL 8.4.11 で Phase4 の 111 本と点検用の 5 本（上の 11・13 のテストを含む）が緑

### 手元のブラウザ（計画を書く段階・試作の写し・使い捨ての SQLite・Playwright）

試しのデータは Task 6 の Step 2 と同じ（`seed.php`・61 件）。写真と Excel のクイックルックは `/Users/masanori/site/approval/screenshots-4b/plan/`。

| # | 見たこと | 結果 |
|---|---|---|
| 1 | 台帳（1440px・決裁の管理者・既定） | 58 件・「Excel に出力」・8 列・10/5 は R8-J-038 → R8-J-039・欠番 R8-J-040 は「取り下げ」で決裁日「—」・長い件名は折り返す・同じ 6/10 は R8-D-001（土木）→ R8-J-014・ページ送り「1 2 >」・`main` のはみ出し 0 |
| 2 | 台帳（375px） | 絞り込みの欄は 2 列・件数とカードが 1 画面目に見える・カード（状態・番号・件名・部門と申請者・決裁日と判断と金額）・はみ出し 0 |
| 3 | 状態「進行中」（管理者） | 「出した件名：外構工事の追加 700,000円」（直しかけの「直しかけ：…（見積 2 社）650,000円」ではなく最後に提出した中身）・「会議室のプロジェクターの購入」 |
| 4 | Excel（`page.request`） | `200`・`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`・`attachment; filename=ledger_2026-10-05.xlsx; filename*=utf-8''%E6%B1%BA%E8%A3%81%E5%8F%B0%E5%B8%B3_2026-10-05.xlsx`・先頭が `504b0304`・記録が 1 行増えた（`kind=excel`・`request_id` 空・件数 61・条件 9 項目） |
| 5 | Excel のクイックルック | 見出しの 15 列・日付・金額の桁区切り・長い件名の折り返し・条可の行の「保留」と審査のコメント・番号の無い行は決裁日が空 |
| 6 | コンソール | エラーと警告 0 件 |

### 使い捨ての MySQL 8.4.11（ポート 34419・確かめたあと止めた。常駐の 3306 はそのまま）

- DB `a`: migration だけで作った表 ／ DB `b`: migration で作ったあと 4b の migration を戻し（`down()` が通る）、本番と同じ SQL ファイルを流した表 → `SHOW CREATE TABLE approval_download_logs` が**完全に一致**（外部キーのある `request_id` を `MODIFY` で NULL 可にできた）:

```sql
  `request_id` bigint unsigned DEFAULT NULL COMMENT '添付・PDF の申請（Excel は空）',
  `filters` json DEFAULT NULL COMMENT 'Excel の絞り込みの条件',
  `request_count` int unsigned DEFAULT NULL COMMENT 'Excel に出した件数',
```

（全文は `~/.claude/plans/approval-phase3-tasks/4b/measure/mysql-b.txt`）

- 4b のテスト（`tests/Feature/Approval/Phase4`・台帳の JSON の中を見る絞り込みを含む）は MySQL で `OK (111 tests, 450 assertions)`（はじめ 2 本が JSON のキーの並びで赤 → 直した。§0.2）。点検の指摘を直した最後のコードでも、Mac の再起動のあとに作り直した使い捨ての MySQL で `tests/Feature/Approval/Phase4` と `RelatedNumberSearchTest` が `OK (133 tests, 575 assertions)`（2026-10-06）
- 決裁のテスト全体（`tests/Feature/Approval`）を MySQL で流すと、試作も**土台（4b の前）も同じ 4 本だけ**が赤（`UsersSchemaTest` の 2 本〈SQLite の `sqlite_master` を読むテスト〉・`MailFailureBannerTest` の 1 本〈ログの見張りの数〉・`TypeManagementTest` の 1 本〈設定の記録の JSON のキーの並び〉）。4b の前からある、SQLite の前提のテストで、4b で増えた 62 本はすべて緑（`measure/mysql-approval-base.txt`・`mysql-approval-proto.txt`）
