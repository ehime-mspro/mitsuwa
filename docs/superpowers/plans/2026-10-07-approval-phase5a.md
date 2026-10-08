# 決裁申請 段階5（5a: 申請の種類ごとの作り込み）実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 段階5 の前半（5a）— ⑨ で住宅の契約用の種類（本文の形「金額の明細表」・明細表の行・件名の決まり文句・追加の入力欄・定型文・使える部門）を設定でき、② 申請の画面（明細表のその場の計算・件名の組み立て・スマホは 1 行 1 枚のカード）→ 保存と提出の条件 → ③ 詳細・控え・変更点・コピー → PDF → 台帳の行と Excel の 4 列まで明細表と追加の欄が通るようにし、⑦ の検索の直し（D25）とあわせて、使い始める前のまま本番へ出す。

**Architecture:** 明細表は申請の JSON の 1 列（`approval_requests.amount_table`。D5）で持ち、形と計算は `App\Support\Approval\AmountTable` の 1 か所（画面の JS も同じ式。粗利率は整数で計算する）。種類の行の設定は `approval_types.table_layout`、追加の入力欄は申請の 4 列（並び・表示・「使う」の判定は `RequestExtras`）、使える部門は `approval_type_department`。保存は `RequestFields`（金額はサーバーが明細表から計算・種類が使わない欄は空・定型文は種類から写す）、提出の条件は `SubmitChecker`（D2・D13）。控え（`RequestSnapshot`）に明細表・追加の欄・定型文を足し、申請者以外の詳細・PDF・台帳・Excel・変更点はすべて控えから読む（1 つの申請の控えの入口は `ApprovalRequest::submittedRevision()`）。数の入力のそろえ方と前後の空白の落とし方は `FormInput` の 1 か所。

**Tech Stack:** Laravel 12 / PHP 8.3（本番 8.3.32・手元のテストは 8.3.35 を名指し）/ MySQL 8（本番 8.0.40）・SQLite（テスト）/ Blade + Alpine.js 3 + Tailwind v4 / PHPUnit 11 / mPDF・PhpSpreadsheet（どちらも既にある。**部品の追加・更新は無い**）/ node（申請の画面の JS をサーバーと同じ入力で動かして比べるテスト。無ければ飛ばす）

**Spec:** 設計書 @docs/superpowers/specs/2026-10-07-approval-phase5-design.md（この計画は **5a** の §5.3〜§5.8・§8・D1〜D19・D25。§10 の「計画で決めること」の 1〜4・9・11 と、5 のうち 5a の分を §0 で決めた。5b〈過去の取り込み〉は別の計画）。要件定義書 @docs/決裁申請_要件定義書_v1.md（v1.13 の 4.4・5.1・5.5・9.2・10）。4b の計画 @docs/superpowers/plans/2026-10-05-approval-phase4b.md（本番反映の作法はそのまま効く）。

## Global Constraints

- ⑨ の新しい欄は、使い始める前（`approval_settings.launched_at` が空）から決裁の管理者が使える（D11。ルートは今のまま）。申請の画面・詳細・PDF・台帳・Excel は今と同じく `approval.launched` の中（**新しいルートは無い**）
- 申請（下書きを含む）が 1 件でもある種類は、本文の形を切り替えられない（D4。画面は押せなくし、送られても断る）
- 提出したあとは提出したときのまま（D14）: 行の名前は申請の明細表に、定型文は申請の列に、件名は組み立てた文字列で持つ。**申請者以外に見せる中身は、すべて最後に提出した控え**（`ApprovalRequest::submittedRevision()`）。控えが無いときに今の中身へ落とさない
- 明細表の種類の金額（台帳・検索・PDF の「金額」）は、保存のときにサーバーが合計金額の販売金額から計算する（D15。画面から送られた金額は使わない）。0 円以下なら空
- 書いたものを黙って消さない（D16）: 種類を選び直して保存で消えるものがあれば知らせて元の種類に戻せる・⑨ で名前が変わった行は、金額が入っていれば自由行として残す
- 明細表の数は −999,999,999,999〜999,999,999,999（マイナス可。D3）・合計も 12 桁まで・坪数は小数第 2 位まで（99,999.99 まで）・坪単価は 0 以上の円（12 桁まで）・担当者は 50 文字まで・契約予定日は 2000〜2099 年の日付。粗利率は小数第 1 位を 0 から遠い方へ四捨五入し、**整数で計算する**（PHP と JS が同じ答え）。販売金額 0 は「—」（Excel は空欄。D19）
- 文字はすべてエスケープする（`{{ }}`・`x-text`・`Js::from`。定型文の改行だけ `nl2br(e(…))`）
- MySQL は JSON のオブジェクトのキーを並べ替えて返す。並びに意味のあるもの（行）は配列にし、比べるときは決まったキーを取り出す。テストで JSON の列を丸ごと比べるときは `Tests\Concerns\ComparesJsonColumns::assertSameIgnoringKeyOrder()`（`assertEquals` は 0 と null を同じとみなすので使わない）
- PDF の落とし穴 4 つ（`pdf.blade.php` の先頭の注記）を戻さない。表の 1 行＝罫線の 1 行
- テストは worktree で `APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit`（**この Mac の `php` は 8.5**。8.3 を名指しする）。main repo では作業もテストもしない。worktree に `.env` を作らない（`.env*` は読まない・grep しない）
- コミットは Conventional Commits・日本語の件名（72 文字以内・句点なし）・1 コミット 1 関心事・本文の最後に空行を 1 つあけて `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`。`--no-verify`・`--amend`・`git reset` でのやり直し・`git stash` は使わない。push は利用者が指示したときだけ

## Review Focus

設計書が触れていないが、使う人がいちばん出会いそうな場面。計画を書く段階で、別の担当（opus）の点検がこれらを見つけ、5 つとも試作を直してテストを足し、コードを 1 か所壊すとそのテストが落ちることを確かめた（変異。Task 10 の表の ID）。

1. **粗利率の小数第 2 位がちょうど 5 になる申請**（販売金額 8,000,000 円・粗利益 5,100,000 円＝63.75%）→ 申請の画面・詳細・PDF・Excel のどれも 63.8%（浮動小数で割ると画面の JS は 63.7%、PHP 8.3 は 63.8%、PHP 8.4 からは 63.7% と答えが割れ、本番の PHP の版を上げると決裁済みの数が黙って変わっていた）（Task 3 の `test_the_rate_is_rounded_away_from_zero_with_integers`・Task 6 の `test_the_script_calculates_like_the_server`〈販売金額 1〜200 円 × 粗利益 −50〜200 円の 50,200 組を PHP と JS で比べる〉。変異 A01・J01）
2. **差戻しのあと、真ん中の自由行を消すか空にして出し直す** → 変更点で、変わっていない「紹介料」などの行が「消えた」と出ない（行の位置で合わせると、1 つずれた行どうしを比べてしまう）（Task 7 の `test_the_changes_match_the_rows_by_their_names`。変異 C03）
3. **明細表の種類で金額を入れたあと、5W2H の種類に選び直す** → 「明細表・担当者を使いません。このまま保存すると、入れた内容は消えます」と知らせ、「元の種類に戻す」で書いたまま戻れる（黙って保存すると明細表と担当者・契約予定日が消えていた）（Task 6 の `test_the_script_calculates_like_the_server` の後半。変異 J04・J05・J07）
4. **件名の決まり文句のある種類で、前半（施主名）を入れずに提出** → 「件名の前半（「様請負新築工事契約の件」の前）を入力してください。」で断る（台帳と PDF に施主名の無い件名が載っていた）（Task 5 の `test_a_table_request_needs_the_total_the_staff_and_the_contract_date`・Task 6 の `composedEmpty`。変異 Q11・J03）
5. **差戻しのあと、決裁の管理者が種類の設定（使う欄・使える部門）を変える** → 申請者が何も直していなければ差戻しを取り消せる・種類と部門を変えていなければそのまま出し直せる・本人の詳細に出る欄はほかの人と同じ（Task 7 の `test_a_change_of_the_type_settings_is_not_an_edit_since_the_return`・`test_the_applicant_sees_the_submitted_extras_after_the_submission`・Task 5 の `test_a_returned_request_is_not_stranded_by_a_change_of_the_usable_departments`。変異 C06・C08・C09・Q10）

---

## 0. 計画を書く段階で決めたこと（設計書 §10 の宿題のうち 5a の分）

### 0.1 作り方（試作を先に作って確かめた）

- この計画のコードは、scratchpad の試作（WT `approval-phase3` = `c335cd26` の写し。設計書のコミット `d875ef2a` に、別の会話〈manage〉が DAD の画面の直しを本番に出したあとの `13.x` = `ca50193f` を merge したもの。決裁のファイル・`composer`・`routes`・`database` の変更は無い）で Task ごとにコミットし、**各段で全件のテストが緑**であることと、**テストだけを先に入れると何が落ちるか**を測ってから書き写したもの（2b〜4b と同じ作り方）。各 Step の Expected の本数と落ちるテストの名前は実測（2026-10-07）
- 試作は 2 回作った。1 回目（前の会話）の 9 コミットを別の担当（opus）が点検し（Critical 0・Important 5・Minor 11。§ 計画を書く段階の実測）、指摘を各 Task のコミットへ畳み込んで作り直し、測り直した
- 同じ中身の差分のファイルを `~/.claude/plans/approval-phase3-tasks/5a/patches/`（`0001`〜`0009`。この計画のコミット 1 つに 1 ファイル）に置いた。担当は計画のコードを打ち直さず、この差分を当ててよい（**テストを先に** `git apply --include='tests/*'`、実装を**あとで** `git apply --exclude='tests/*'`）。当てたら `git diff --stat` が各 Task の「差分の大きさ」と同じことを確かめる。**差分のファイルと計画のコードが食い違ったら、計画を正として止まり、報告する**
- 各 Task のコードの塊のうち、新しいファイルは全文、既存のファイルは差分（`diff` の形）で示す
- **使い捨ての MySQL 8.4.11 で確かめた**（2026-10-07・ポート 34419・確かめたあと止めた。常駐の 3306 はそのまま）: migration だけで作った表と、5a の migration を戻して（`down()`）から本番と同じ SQL ファイルで変えた表の `SHOW CREATE TABLE` が、中間表の外部キーの名前を除いて一致（段階2 からの書き方と同じ）・決裁のテスト（`tests/Feature/Approval`・`tests/Unit/Approval`）は 5a の前からある SQLite 前提の 4 本だけが赤で、5a のテストはすべて緑
- 進め方は 4b と同じ（担当の決まりは `~/.claude/plans/approval-phase3-tasks/5a/rules.md`）: 実装の担当は 1 度に 1 人（Task ごと）・コードの点検は別の担当が WT の HEAD の写しで行う・変異の確かめは写しで・Task 13（本番反映）は担当に任せず親の会話が行う

### 0.2 表と本番の SQL（§5.3・D4・D5・D12）

- `approval_types`（`headings` の後ろ）: `body_form`（VARCHAR(20)・既定 `points`。`points`＝5W2H の見出し／`table`＝金額の明細表。enum `App\Enums\ApprovalBodyForm`）・`table_layout`（JSON・NULL 可）・`subject_suffix`（VARCHAR(50)・NULL 可）・`uses_tsubo`・`uses_tsubo_price`・`uses_staff`・`uses_contract_date`（TINYINT(1)・既定 0）・`fixed_text`（TEXT・NULL 可）
- `approval_type_department`（新規。主キー `(type_id, department_id)`・`department_id` の索引・どちらも外部キー `ON DELETE CASCADE`・`created_at`）。1 行も無い種類は全部門で使える
- `approval_requests`（`body` の後ろ）: `amount_table`（JSON・NULL 可）・`tsubo`（DECIMAL(7,2)）・`tsubo_price`（BIGINT UNSIGNED）・`staff`（VARCHAR(50)）・`contract_date`（DATE）・`fixed_text`（TEXT）。どれも NULL 可
- 本番の SQL は `database/sql/2026-10-07-approval-phase5a.sql`（`ALTER TABLE approval_types`・`CREATE TABLE approval_type_department`・`ALTER TABLE approval_requests` の 3 文）、テストの鏡は `database/migrations/2026_10_07_000001_add_approval_phase5a_columns.php`。`Phase5aTablesTest` が両方を読んで同じ表・同じ列（型・NULL・既定・並び・コメント）であることを見る。`Phase2TablesTest` の `LATER_COLUMNS` に足した列を登録する
- 本番は使い始める前で申請は 0 行（2026-10-07 の時点）。今の種類は本文の形の既定（5W2H）になるだけ＝埋め直す SQL は要らない（Task 13 の Step 2 で読み取って確かめる）
- モデル: `ApprovalType` に `departments()`（BelongsToMany）・`scopeUsableIn(list<int>)`（使える部門の無い種類と、渡した部門のどれかを使える部門に持つ種類）・`isUsableIn(?int)`・`usesTable()`・新しく作った種類の既定（`$attributes`）。`ApprovalRequest` に 6 列の `$fillable` と `casts()`（`amount_table` は array・`tsubo` は `decimal:2`・`contract_date` は date）
- **最後に提出した控えの入口を 1 つに**（設計書 §8 の持ち越し。5a で控えを読む所が 1 つ増えるので先に片付ける）: `ApprovalRequest::submittedRevision(): ?ApprovalRevision`（今の回の控え。`lastRevision` を先に読んでいれば〈台帳〉それを使って問い合わせない）。`RequestContent`・`UndoTarget`・`Notifier`・`LedgerRow`（Task 2）と `SubmitChecker`（Task 5）が使う。問い合わせの中で控えを当てる所（`Ledger::whereContent()`・`RelatedNumberController`）は SQL で同じ条件（`round` が同じ）を書いたまま

### 0.3 明細表の形と計算（§10 の 1・2）

- **種類の行の設定**（`table_layout`）: `{subtotal: bool, upper: list<?string>, lower: list<?string>}`。名前が null の行は自由行。⑨ の入力は `AmountTable::layout()` が名前の前後の空白（全角を含む）を落とし、空は null にそろえる
- **申請の明細表**（`amount_table`）: `{subtotal: bool, upper: list<Row>, lower: list<Row>}`・Row は `{name: ?string, fixed: bool, sale: ?int, cost: ?int}`（`fixed` は種類に名前を設定した行＝名前は種類のもの・消せない）。「計」の有無も申請が持つ（あとで種類の設定を変えても、提出した申請の見た目は変わらない。D14）
- 部品 `App\Support\Approval\AmountTable`（画面・保存・控え・詳細・変更点・PDF・Excel が共有。**形と計算はここ 1 か所**）:
  - `forForm(layout, ?table)`（D16）: 種類の今の行の並びに申請の行を当てる。名前を設定した行は同じ名前の行の金額を当て、種類の自由行の位置には申請の自由行を順に当て（足りなければ空の自由行）、残った自由行は後ろへ。種類から名前が消えた行は、金額があれば自由行として残す（同じ名前の行が 2 つ来たら 2 つ目は自由行）
  - `cleanInput(mixed)`: 画面から送られた形をそろえる（配列でない値は捨て、金額は `FormInput::digits()`）
  - `fromInput(layout, clean)`: **どの行が名前を設定した行かはサーバーが種類の設定で決める**（画面を信用しない。送られなかった名前の行は空で後ろに足す）
  - `totals()`（「計」＝前半の合計・「合計金額」＝前半と後半の合計。空の金額は 0）・`amount()`（合計金額の販売金額。0 より大きいときだけ）・`shownRows()`（名前も金額も無い自由行は出さない。粗利益金額と粗利率を添える）・`hasUnnamedAmount()`・`rate()`・`rateLabel()`・`yen()`・`rowsOf()`（形の崩れた行は捨てる＝古い控えや手で組んだ JSON でも落ちない）
- **粗利率**: `rate(sale, profit)` は `|粗利益| × 2,000 + |販売金額|` を `|販売金額| × 2` で割った整数（＝粗利率の 10 倍を四捨五入）に符号を付けて 10 で割る。|粗利益| は合計の上限で 2 兆円まで（×2,000 でも int に収まる）。画面の JS は同じ式を BigInt で計算する。表示は「12.3%」（3 桁の区切りは付けない＝JS の `toFixed(1)` と同じ）
- **入力の上限**（§10 の 2）: 種類の行は前半・後半それぞれ 20 行まで（前半は 1 行以上・同じ側に同じ名前の行は作れない）・行の名前 30 文字。申請の行は前半・後半それぞれ 30 行まで（種類の行と「行を足す」で足した行を合わせて）・項目名 30 文字・金額は ±12 桁で合計も 12 桁まで（`RequestFields::totalError()`。合計が金額の列〈BIGINT〉に入る）
- **追加の入力欄**（`App\Support\Approval\RequestExtras`）: キー（申請の列名）と名前の並び `FIELDS`（坪数・坪単価・担当者・契約予定日）・提出に必須の `REQUIRED`（担当者・契約予定日。D2）・`usedBy(?type)`・`valuesOf(request, type)`（種類が使う欄だけ。控えの形）・`columnsOf(request)`（4 列すべて）・`ordered()`・`allOf()`（控えを 4 つの欄すべての形に）・`display()`（「38.5坪」「1,083,000円」「2026/10/20」）
- **数の入力のそろえ方**（`FormInput::digits(value, units)`・`FormInput::YEN`）: 全角→半角・マイナスの記号を `-` に・カンマと空白と単位を落とす・整数の形（`+12`・`0012`）は先頭の `+` と 0 を落とす（Laravel の integer の検査は「0012」を断るが、画面の JS は 12 と読む）。2a の金額の欄・明細表の金額・坪数・坪単価が使う。前後の空白（全角を含む）の落とし方は `FormInput::trim()`

### 0.4 ⑨ 申請の種類の設定（§5.4・D4・D11・D12）

- 登録・編集の小窓（`approvals/admin/types.blade.php` と、足した部品 `_type_body_form.blade.php`〈本文の形・見出し・明細表の行〉・`_type_options.blade.php`〈決まり文句・追加の欄・定型文・使える部門〉）。本文の形は 2 つのラジオ。明細表の行は前半・後半それぞれ「足す・消す・上へ・下へ」と「計」の行のチェック。使える部門は部門のチェックボックス（会社・部門の名前）。`<option>` を x-for で作らない（Bug #16。使える部門はチェックボックス）
- 申請（下書きを含む）がある種類は、本文の形のラジオを押せなくし「この種類の申請が N 件あるため変えられません」と出す。送られてきても `TypeController::refuseLayout()` が断る（記録も残さない）
- 明細表の種類は見出しを使わない: 見出しの欄は隠し、検査もせず、今の見出しのまま持つ（列は空にできない）。5W2H の種類は明細表の行を持たない（送られても `table_layout` は null）
- 一覧に、種類ごとの本文の形と使える部門を小さく出す（「5W2H の見出し 全部門」）
- 設定の記録（`SettingLogger`）の前と後に、本文の形（値の文字）・明細表の行（`AmountTable::layout()` でキーの並びをそろえる＝MySQL が並べ替えて返しても「変わった」にしない）・使える部門（id の小さい順）も残す
- 要件 5.5.7 の 2 つの設定例（請負新築工事契約・追加・少額工事契約）がこの欄で組めることを `TypeSettingsTest` が見る

### 0.5 ② 保存と提出の条件（§5.5・D2・D3・D13・D15）

- 選べる種類は、自分の所属部門のどれかで使える有効な種類と、この申請が今使っている種類（停止していても、使える部門から外れていても保存はできる。D10・D13）。コピーで写すときは、自分の部門で使えない種類は空にして選び直してもらう
- 保存（下書き）は形だけを確かめる（`RequestController::validated()`）。明細表の行の各欄・坪数（`decimal:0,2`）・坪単価・担当者・契約予定日（`date_format:Y-m-d`・2000〜2099 年）。エラー文はすべて和名つき。明細表の種類の本文のエラー文は「補足」（5W2H は今までどおり「重点ポイント（5W2H）」）
- 保存する列は `RequestFields::columns()`: 明細表の種類だけ明細表を持ち、金額は `AmountTable::amount()`（D15）・種類が使わない追加の欄は空・定型文は保存のたびに種類から写す（下書き・差戻し中は次に保存したときに今の種類の文。提出したあとは直せないので変わらない。D14）
- 提出の条件（`SubmitChecker::reasons()`。理由をすべて集めて返す）に足すもの: 明細表の種類は合計金額の販売金額が 0 円より大きい・名前のない行に金額があれば項目名が要る（補足は空でよい・坪数と坪単価は空でよい）・種類が使う担当者と契約予定日（5W2H の種類でも使えば要る。D12）・**決まり文句だけの件名（前半が空）は断る**・使える部門（D13。差戻し中は、種類と申請部門を最後に提出した控えから変えていなければ従わない）

### 0.6 ② 申請の画面（§5.5・D16・要件 5.5.3・5.5.6）

- 画面に渡す値（`RequestController::formData()`）: `typeConfigs`（種類の id => `{form, layout, suffix, uses, fixedText}`）・`tableRows`（断られて戻ったときは打った値のまま、そうでなければ `AmountTable::forForm()` で今の行の設定に並べ直した行。金額はカンマ付きの文字）
- 明細表（`_amount_table_input.blade.php`・合計の行 `_amount_table_sum.blade.php`）: 項目・販売金額・工事原価・粗利益金額（自動）・粗利率（自動）。「計」と「合計金額」の行・「＋ 前半に行を足す」「＋ 後半に行を足す」（30 行まで）。名前を設定した行は名前を文字で出して hidden の `fixed` で送り、消せない。自由行は項目名を打てて「消す」。金額は欄を離れたらカンマ付きに整える。**スマホ（`md` 未満）は 1 行 1 枚のカード**（表の要素を block にして各セルに名前を添える。横スクロールにしない。同じ欄を 2 回描かない）
- 明細表の種類では金額の欄を押せなくして送らず、「（明細表の合計金額の販売金額）」を出す。実施時期はどちらの本文の形でも今までどおり出す（任意。D17）。並びは「明細表 → 坪数・坪単価・担当者・契約予定日 → 定型文 → 補足」、5W2H の種類は「重点ポイント → 追加の欄 → 定型文」（CSS の `order`）
- 件名（要件 5.5.3）: 決まり文句のある種類は「前半の入力欄＋決まり文句」と組み立てた件名の見本（「台帳と PDF に載る件名: …」）。「件名を直接書く」で 1 行に、「決まり文句（…）を使う」で戻す。送るのはいつも組み立てた件名。開き直したときは、件名が決まり文句で終わっていれば分けて出す。**前半が空なら件名も空**（決まり文句だけの件名を作らない）
- 種類を選び直したとき（D16）: 明細表の行を新しい種類の行に並べ直し（`mergeRows`＝`forForm` と同じ規則）、件名を組み直す。**明細表の種類から 5W2H の種類へ移るとき明細表に何か入っている、または新しい種類が使わない追加の欄に値があれば、「選んだ種類は明細表・担当者を使いません。このまま保存すると、入れた内容は消えます。」と「元の種類に戻す」「この種類で進める」を出す**（`lostOnChange`・`restoreType`。保存するまでは画面に残っているので戻せる。前半と決まり文句で組み立てていた件名も組み立て直す）。5W2H の種類では明細表の欄を押せなくして送らない（隠れた欄の値で断らない）
- 画面の計算（`rate`・`parseAmount`・`total` など）は `AmountTable`・`FormInput::digits()` と同じ答えを出す。`RequestFormTableTest::test_the_script_calculates_like_the_server` が node で画面の JS を動かして、同じ入力でサーバーと比べる（粗利率は境目と 50,200 組・金額の読み方 20 通り・並べ直しの境目）

### 0.7 ③ 詳細・控え・変更点・コピー（§5.6・§10 の 3・D14）

- 控え（`RequestSnapshot::make()`）に `amount_table`・`extras`（提出したときに種類が使っていた欄だけ。`RequestExtras::valuesOf()`）・`fixed_text` を足す。段階5 より前の控えにはこのキーが無い（無ければ空として読む＝5W2H の形で出す）
- 詳細（`_body_section.blade.php`・明細表の表示 `_amount_table_show.blade.php`）: 明細表の種類は「明細表 → 坪数・坪単価・担当者・契約予定日 → 定型文 → 補足」、5W2H の種類は「重点ポイント → 追加の欄 → 定型文」。名前も金額も無い自由行は出さない。スマホはカード
- 出し分け（`RequestContent`）: 申請者以外には最後に提出した控え。申請者本人には今の中身で、追加の欄は、直せる間（下書き・差戻し中）は種類が今使う欄、提出したあとは控えと同じ欄（どちらも値の入った欄は出す）。提出したあとで管理者が種類に欄を足しても、本人とほかの人で出る欄が同じ
- **変更点の明細表の行の合わせ方**（§10 の 3）: 前半・後半ごとに、詳細に出す行を**行の鍵（名前を設定した行か・項目名）で**最長共通部分列（本文の `LineDiff` と同じ部品）で合わせる。同じ鍵の行は販売金額と工事原価を比べ、合わない行は増えた行・消えた行。真ん中の自由行を消しても、ほかの行は「消えた」と出ない。項目名を直した自由行は「消えた行＋増えた行」で出る。追加の欄は件名や金額と同じ並び（前 → 後）。補足は本文と同じ行ごとの差。変更点の明細表もスマホではカード
- 提出の履歴（`_history.blade.php`）: 各回の控えの明細表と追加の欄を出す
- 差戻しの取り消しの指紋（`RequestSnapshot::editableFingerprint()`）に明細表（`rowsOf()` でキーの並びをそろえる）と追加の欄（**4 つとも**。無い欄は空）を足す。今の中身の指紋は `RequestSnapshot::currentFingerprint()`（追加の欄は種類の設定で絞らずに 4 つの列）。管理者が種類の「使う」を変えただけなら「直した」にしない
- コピーして作成（要件 5.4）: 明細表・追加の欄も写す（定型文は保存のときに種類から写すので写さない。添付は今までどおり写さない）。行は画面を描くときに種類の今の行の設定に並べ直す（D16）

### 0.8 PDF（§5.7・§10 の 4）

- 明細表の種類（`PdfSheet::amountRows()`）: 「（記）」の下に、項目・販売金額（30mm）・工事原価（30mm）・粗利益金額（30mm）・粗利率（18mm）の表。前半の行 → 「計」→ 後半の行 → 「合計金額」（合計の行は網掛け `tr.sum`）。名前も金額も無い自由行は載せない。明細表の 1 行＝表の 1 行（行の切れ目で次のページへ）。**見出しの行は `<thead>`**（mPDF は次のページの頭で繰り返す。60 行の PDF の 2 ページ目の頭に見出しが出ることを pdftotext で確かめた）
- その下は紙の住宅の様式の並び: **坪数・坪単価 → 定型文 → 担当者・契約予定日 → 補足**（`PdfSheet::EXTRAS_BEFORE_FIXED_TEXT`・`EXTRAS_AFTER_FIXED_TEXT`・`extraPairs()`）。追加の欄は 1 行に 2 組まで（見出し 22mm で「契約予定日」が 1 行に収まる）。補足は紙の 2 行（`MIN_SUPPLEMENT_ROWS`）。5W2H の種類で追加の欄や定型文を使うときも、本文の下に同じ並びで載せる
- 金額の欄（金額・実施時期・関連する決裁No の行）は今の形のまま（金額は合計金額の販売金額）
- 落とし穴 4 つは戻していない（足した表はすべて `class="wrap"`・印の表と朱の枠は触らない・SVG は足さない）。`PdfTableTest` が、`wrap` の無い表は決裁No の表だけ・空白の無い 30 文字の項目名と 60 行の明細表で文字の大きさが変わらないことを見る。紙面は pdftoppm の画像で確かめた（§ 計画を書く段階の実測）

### 0.9 台帳と Excel（§5.8・§10 の 9・D18・D19・§8）

- 台帳の画面は列も絞り込みも今のまま（D18）。明細表の種類の金額は合計金額の販売金額（金額の列）
- 1 行の中身（`LedgerRow`）に `cost`・`profit`・`rate`（合計金額の行の数。明細表の種類だけ）と `contractDate`（種類が使えば。5W2H の種類でも）を足す。申請者以外は最後に提出した控えから
- **Excel の列を 1 つの定義から作る**（4b の BACKLOG の注意）: `LedgerExcel::columns()` が見出し・幅・値の種類（文字・折り返す文字・日付・金額・率）・行から値を取る関数の並びを持ち、見出し・書き込み・書式・オートフィルタの範囲はすべてここから作る。並びは要件 10 の 19 列（決裁No／決裁日／判断／件名／申請の種類／申請部門／申請者／金額（税抜）／**工事原価／粗利益金額／粗利率**／実施時期／**契約予定日**／関連する決裁No／提出日／審査の意見／審査のコメント／条件／状態）。粗利率は割合の数（書式 `0.0%`）・販売金額 0 は空欄（D19）。契約予定日は Excel の日付（書式 `yyyy/mm/dd`）
- 件数の上限は 1,000 のまま（入力の上限の中身〈明細表 60 行〉で測り直した。§ 計画を書く段階の実測）
- §8 の小さな指摘も片付けた: 状態の絞り込みの知らないキーを「すべて」にしない（`'all'` を明示）・決裁日の年は 1900〜2099 年だけ読む・申請者の名前の列に表の名前（`users.name`）・テストの `tempnam()` の空のファイルを消す。最後に提出した控えの引き方は §0.2 のとおり Task 2 で 1 つにした

### 0.10 ⑦ 利用者の管理の検索（D25）

- 氏名は登録名の空白（半角・全角）を無視し、空白で分けた語のどれも含む人に当てる。決裁台帳の申請者と同じ部品 `App\Support\Approval\NameSearch`（`terms()`・`whereNameHasAll()`）を両方が使う（`LedgerFilter::terms()` も同じ分け方）。社員番号とメールアドレスは今までどおり打った文字のまま

### 0.11 走査テスト（全件分類）に登録するもの

3b 以降と同じく、**各 Task が足したものは、その Task の中で登録する**（途中の各段でも全件が緑）。

| 走査テスト | 登録するもの | Task |
|---|---|---|
| `tests/Feature/Approval/Phase2/Phase2TablesTest.php` | `LATER_COLUMNS` に `approval_types` の 8 列と `approval_requests` の 6 列 | 2 |
| `tests/Feature/Approval/Phase2/Phase2ModelsTest.php` | 申請者が直す中身の `$fillable` に 6 列 | 2 |

- 新しいルートは無い（`ApprovalAdminGateTest`・`LaunchGateTest`・`ApprovalOnlyLockoutTest` の登録は要らない）
- `JapaneseValidationMessagesTest`（2026-10-07 に manage の会話が広げた走査）: 新しい入力のキーは、どれも呼び出しの第 3 引数で和名を付けた（明細表の `amount_table.*.*.sale` などのワイルドカードを含む）。`lang/ja/validation.php` は変えない
- `MobileLayoutTest`: 明細表・変更点は `md` 未満でカードにした（横スクロールの枠の中に置くが、はみ出さない）。登録は要らない
- `LoginGuideTest` の 1 ファイル 1 宣言: 新しいクラスはどれも 1 ファイル 1 つ
- 13.x で増えた走査（`DeleteZoneBelowSaveBarTest`・`MobileLayoutTest` の `flex: 1` の入力欄）にも、試作の新しい画面は引っかからない（全件で確かめた）

### 0.12 既存のコードを変えるもの（振る舞いを変えない片付け）

| ファイル | 変えること | Task |
|---|---|---|
| `app/Http/Controllers/Approval/UserController.php` | 氏名の検索を `NameSearch` で（D25。振る舞いは変わる＝登録名の空白を無視する） | 1 |
| `app/Support/Approval/Ledger.php`・`LedgerFilter.php` | 申請者の検索と語の分け方を `NameSearch` で（中身は同じ） | 1 |
| `app/Support/Approval/RequestContent.php`・`UndoTarget.php`・`Notifier.php`・`LedgerRow.php` | 控えを `ApprovalRequest::submittedRevision()` で引く（中身は同じ。`LedgerRow` は今の回の控えだけを使う形になる） | 2 |
| `app/Http/Controllers/Approval/RequestController.php` | 金額の欄のそろえ方を `FormInput::digits()` に（`normalizeAmount()` を消す。「＋1,000」「0100」も読めるようになる） | 5 |

### 0.13 既存のテストを変えるもの

| テスト | 変えること | Task |
|---|---|---|
| `Phase2TablesTest`・`Phase2ModelsTest` | §0.11 のとおり足すだけ | 2 |
| `TypeManagementTest` | 一覧の行に本文の形と使える部門の列が増えた・保存と記録と小窓に渡す値に 5a の欄が増えた（意味は変えない） | 4 |
| `LedgerExcelTest`（4b） | 列が 19 になったので列の記号を直す・5W2H の種類の行の 4 列が空であること・`tempnam()` の空のファイルを消す | 9 |
| `ApprovalUserManagementTest` | 氏名の検索のテストを 1 本足す（既存のテストは変えない） | 1 |

### 0.14 設計書から変えた細部（計画を書いていて分かったこと）

| # | 設計書 | この計画 | 理由 |
|---|---|---|---|
| 1 | §5.8「5W2H の種類の行では 4 列とも空欄」 | 5W2H の種類でも、契約予定日を使う設定なら Excel の契約予定日に入る（工事原価・粗利益金額・粗利率は空） | D12 で 5W2H の種類も契約予定日を使えるようにしたので、要件 10（「契約予定日を使う種類の申請だけ値が入る」）に合わせた。**2026-10-07 に利用者が「入れる」を選んだ**（設計書 §5.8 も直した） |
| 2 | §5.3「控えに本文の形…を足す」 | 控えに本文の形は入れない（明細表の有無で分かる） | 明細表の種類は保存のたびに必ず明細表を持ち、5W2H の種類は持たない。同じことを 2 か所に書くと食い違いうる |
| 3 | §5.5（件名の決まり文句） | 前半が空なら件名を組み立てない・決まり文句だけの件名は提出で断る | 点検で、前半を入れずに提出すると台帳と PDF に施主名の無い件名が載ると分かった |
| 4 | D16「確かめてから変える」 | 種類は選んだときに変え、保存で消えるものを知らせて「元の種類に戻す」を出す | 今の「本文を入れ替えますか」と同じ形（種類はすぐ変わり、消すかどうかを後で選ぶ）。保存するまでは画面に残っているので、戻せば何も消えない |
| 5 | §5.7 の並び（坪数・坪単価 → 定型文 → 担当者・契約予定日） | そのとおりにした（1 回目の試作は 4 つを 1 行に並べていた） | 点検の指摘。1 行に 2 組にしたので「契約予定日」の見出しも折れない |
| 6 | §8「最後に提出した控えの引き方を 1 通りに（5a か 5b）」 | 5a の Task 2 で `submittedRevision()` に寄せた（5 か所） | 5a で控えを読む所が増える（提出の条件の D13）。5b は台帳に紙を足すので、先に寄せておく |

### 0.15 受け入れた隙間

| # | 隙間 | 起きたとき | 塞ぐなら |
|---|---|---|---|
| 1 | 申請の行は前半・後半それぞれ 30 行まで。⑨ で行の名前を全部付け替えると、金額の入った古い行が自由行として残る | 種類の行が 20 行の種類で、自由行を 11 行足した申請の名前を全部付け替えると 51 行になり、金額の入った行を消さないと保存できない（極端な例） | 上限を「種類の行の数＋足した行」で数える |
| 2 | 変更点で、項目名を直した自由行は「消えた行＋増えた行」で出る | 名前を直しただけでも 2 行出る（読めば分かる） | 自由行どうしを位置でも合わせる |
| 3 | 明細表の行の名前では台帳を探せない（D5 の弱み。要件に無い） | 「紹介料」で探しても当たらない（件名・補足では当たる） | 行を別の表に持つ |
| 4 | 本番の Web の PHP のメモリの上限は CLI と同じ 128M と見ている（4b の隙間 3 と同じ） | 小さければ、入力の上限の中身の 1,000 件の Excel を作れない | 段階6 の受け入れ確認で多めの台帳の Excel を本番で 1 回出す |
| 5 | MySQL の中間表 `approval_type_department` の外部キーの名前が、SQL（`fk_…`）と migration（Laravel の既定の名前）で違う | テストの表と本番の表で、外部キーの名前だけが違う（振る舞いは同じ） | migration に名前を書く（段階2 からの書き方に合わせて変えない） |

### 0.16 テストの土台

- 4a・4b と同じ（`Tests\Concerns\BuildsApprovalFixtures`・`approvalWorld()`・申請者「申請 花子」・申請部門「住宅事業部」J・審査部門「総務部」S・会社は 5 月始まり）
- **住宅の契約用の種類**は `BuildsApprovalFixtures::housingContractType(world, attributes)`（要件 5.5.7 の請負新築工事契約の設定。前半: 工事請負金額・自由行・紹介料／「計」あり／後半: 土地契約金額・自由行／決まり文句「様請負新築工事契約の件」／追加の欄 4 つ／定型文「上記の内容に基づき、販売をおこないます。」）。Task 5 で足し、以降のテストが使う
- 9/17 に利用者が見た申請の画面の見本の数（工事請負金額 28,500,000 / 22,000,000・オプション工事 1,200,000 / 850,000・紹介料 0 / 300,000・土地契約金額 12,000,000 / 10,500,000 → 計 29,700,000・合計金額 41,700,000・粗利率 19.3%）を各テストが使う
- JSON の列を丸ごと比べるときは `Tests\Concerns\ComparesJsonColumns::assertSameIgnoringKeyOrder()`（Task 4 で足す）
- 申請の画面の JS は、描いた画面から `function approvalRequestForm()` を取り出して node の `vm` で動かす（顧客の画面の `RunsBuyerFormScript` と同じ考え。node が無ければ飛ばす）
- PDF は `ApprovalPdf::sheet()` のバイト列の文字の大きさ（縮んでいないか）とページの数、紙面の文字は `pdf.blade.php` を描いた HTML で見る。Excel は応答をファイルに書いて `IOFactory::load()` で読み戻す

---

## 1. 触るファイル

### 新規

| ファイル | 役目 | Task |
|---|---|---|
| `app/Support/Approval/NameSearch.php` | 人の名前の探し方（⑦ と台帳） | 1 |
| `database/sql/2026-10-07-approval-phase5a.sql` | 本番の DDL（3 文） | 2 |
| `database/migrations/2026_10_07_000001_add_approval_phase5a_columns.php` | テストの鏡 | 2 |
| `app/Enums/ApprovalBodyForm.php` | 本文の形 | 2 |
| `app/Support/Approval/AmountTable.php` | 明細表の形と計算 | 3 |
| `app/Support/Approval/RequestExtras.php` | 追加の入力欄 | 3 |
| `resources/views/approvals/admin/_type_body_form.blade.php`・`_type_options.blade.php` | ⑨ の小窓の部品 | 4 |
| `app/Support/Approval/RequestFields.php` | 保存する中身の列 | 5 |
| `resources/views/approvals/requests/_amount_table_input.blade.php`・`_amount_table_sum.blade.php` | ② の明細表 | 6 |
| `resources/views/approvals/requests/_body_section.blade.php`・`_amount_table_show.blade.php` | ③ の本文の欄と明細表 | 7 |
| `tests/Concerns/ComparesJsonColumns.php` | JSON の列をキーの並びに頼らずに比べる | 4 |
| `tests/Feature/Approval/Phase5/`（`Phase5aTablesTest`・`TypeSettingsTest`・`RequestTableSaveTest`・`RequestFormTableTest`・`RequestTableShowTest`・`PdfTableTest`・`LedgerExcelTableTest`）・`tests/Unit/Approval/`（`AmountTableTest`・`RequestExtrasTest`） | テスト | 2〜9 |

### 変更

| ファイル | 変えること | Task |
|---|---|---|
| `app/Http/Controllers/Approval/UserController.php`・`app/Support/Approval/Ledger.php`・`LedgerFilter.php` | §0.10・§0.12 | 1 |
| `app/Models/ApprovalType.php`・`ApprovalRequest.php` | 列・使える部門・`submittedRevision()` | 2 |
| `app/Support/Approval/RequestContent.php`・`UndoTarget.php`・`Notifier.php`・`LedgerRow.php` | 控えの入口（§0.12） | 2 |
| `app/Support/Approval/FormInput.php` | `trim()`・`digits()`・`YEN` | 3 |
| `app/Http/Controllers/Approval/TypeController.php`・`resources/views/approvals/admin/types.blade.php` | ⑨ | 4 |
| `app/Http/Controllers/Approval/RequestController.php`・`app/Support/Approval/SubmitChecker.php` | 保存と提出の条件 | 5 |
| `RequestController.php`・`resources/views/approvals/requests/form.blade.php` | ② の画面 | 6 |
| `RequestSnapshot.php`・`RequestContent.php`・`RequestExtras.php`・`UndoTarget.php`・`_changes.blade.php`・`_history.blade.php`・`show.blade.php` | ③・控え・変更点 | 7 |
| `app/Support/Approval/PdfSheet.php`・`resources/views/approvals/requests/pdf.blade.php` | PDF | 8 |
| `app/Support/Approval/LedgerExcel.php`・`LedgerRow.php`・`Ledger.php`・`LedgerFilter.php` | 台帳と Excel | 9 |
| 走査テストと既存のテスト | §0.11・§0.13 | 1・2・4・9 |

## 2. 作業の順番

```
Task 0 準備 → Task 1 ⑦ の検索 → Task 2 表とモデル → Task 3 明細表と追加の欄の部品 → Task 4 ⑨
→ Task 5 ② の保存と提出の条件 → Task 6 ② の画面 → Task 7 ③・控え・変更点 → Task 8 PDF → Task 9 台帳と Excel
→ Task 10 全件・変異 → Task 11 ブラウザと写真と PDF・Excel → Task 12 ドキュメント → Task 13 本番反映（親の会話）
```

各 Task のコミットのあとで全件が緑（途中の段でも）。Task 10・11 は記録だけをコミットする。

---

## Task 0: 作業場所の準備

**作業場所**: worktree `/Users/masanori/site/manage/.claude/worktrees/approval-phase3`（ブランチ `approval-phase3`。3a〜4b と同じ worktree を使う＝利用者の決定・dev の vendor の置き場）。この計画のコミットの親は、設計書のコミット `d875ef2a` に `13.x` を merge したもの（`c335cd26`＝`ca50193f` の取り込み、とその後の BACKLOG だけの取り込み。決裁のファイル・`composer`・`routes`・`database` の変更は無い）。**main repo では作業もテストもしない**（main repo の vendor は `--no-dev` で phpunit が無い。dev 依存を入れると `./deploy.sh` が本番へ送る）。

- [ ] **Step 1: 並行の作業を確かめる**（ほかの会話が同じ課題を進めていないか）

```bash
cd /Users/masanori/site/manage && git status --short --branch && git log --oneline -5 && git worktree list && git for-each-ref --sort=-committerdate --format='%(refname:short) %(committerdate:short) %(subject)' refs/heads | head && git log --oneline approval-phase3..13.x
```

Expected: worktree `approval-phase3` の先頭がこの計画のコミット。最後のコマンド（`13.x` にあって `approval-phase3` に無いコミット）が空。ほかの worktree があれば別の会話のもの（触らない）。明細表・種類の作り込みの名前の付いた枝や worktree があれば、中身を読み、消さずに利用者へ報告して止まる。`13.x` が進んでいたら止まり、利用者に取り込むかを聞く（取り込むなら WT で `git merge 13.x`。rebase しない）。

- [ ] **Step 2: vendor と道具を確かめる**（dev 依存あり・実体・PHP 8.3・node）

⚠ **symlink にしない**（autoload が symlink の先を読み、別の場所のコードでテストが流れる。Bug #50）。⚠ **この Mac の `php` は 8.5**。テストは `/opt/homebrew/opt/php@8.3/bin/php` を名指しする。

```bash
WT=/Users/masanori/site/manage/.claude/worktrees/approval-phase3; test -x "$WT/vendor/bin/phpunit" && ! test -L "$WT/vendor" && test -f "$WT/vendor/mpdf/mpdf/src/Mpdf.php" && test -f "$WT/vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Writer/Xlsx.php" && echo "vendor OK"; /opt/homebrew/opt/php@8.3/bin/php -v | head -1; command -v node && node -e 'console.log(typeof BigInt)'
```

Expected: `vendor OK`・`PHP 8.3.…`・node のパスと `function`（5a は部品を足さない。`composer` は打たない。node が無ければ `RequestFormTableTest` の 1 本が飛ばされる＝報告する）

- [ ] **Step 3: 差分のファイルを確かめる**

```bash
ls ~/.claude/plans/approval-phase3-tasks/5a/patches/ && cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --check ~/.claude/plans/approval-phase3-tasks/5a/patches/0001-*.patch && echo "0001 を当てられる"
```

Expected: `0001-…` 〜 `0009-…` の 9 本・`0001 を当てられる`。差分のファイルが無ければ計画のコードを打ち込む（中身は同じ）。

- [ ] **Step 4: 全件テストが通る状態から始める**

**テストの流し方**（以下すべての Task で同じ。`APP_KEY` は 32 バイトの本物の鍵を渡す。worktree に `.env` を作らない）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `OK (3934 tests, 37440 assertions)`（2026-10-07 の実測。約 4 分）。赤があれば 5a の作業の前に利用者へ報告して止まる。

⚠ 以下の Task の「テストを流す」は、すべてこの形でファイルを並べたもの。`cd` はコマンドごとに書く（ターンをまたぐと cwd が main repo へ戻る）。git は `git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 …` で呼ぶ。

⚠ コミットのたびに `git status --porcelain` が空であることを確かめる。差分のファイルを当てたときは、`git diff --stat` が各 Task の「差分の大きさ」と同じことも確かめる。

---

## Task 1: ⑦ 利用者の管理の検索で登録名の空白を無視する

⑦ の氏名の検索も、決裁台帳の申請者と同じく登録名の半角・全角の空白を無視し、空白で分けた語のどれも含む人に当てる。2 つの画面の探し方を `NameSearch` の 1 か所にまとめる（設計書 D25・§8・§0.10）。

**Files:**
- Create: `app/Support/Approval/NameSearch.php`
- Modify: `app/Http/Controllers/Approval/UserController.php`・`app/Support/Approval/Ledger.php`・`app/Support/Approval/LedgerFilter.php`
- Test: Modify `tests/Feature/Approval/ApprovalUserManagementTest.php`

**Interfaces:**
- Consumes: なし
- Produces: `NameSearch::terms(?string $text): list<string>`（空白〈全角を含む〉で分けた語）・`NameSearch::whereNameHasAll(Illuminate\Database\Eloquent\Builder $users, list<string> $terms): void`（`users.name` の空白を除いた名前が、どの語も含む）。`LedgerFilter::terms()` は `NameSearch::terms()` を呼ぶ（呼び出す側は変えない）

**差分の大きさ:** 5 ファイル・+73 / −11 行（差分のファイル `0001-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/ApprovalUserManagementTest.php`（変更）

```diff
--- a/tests/Feature/Approval/ApprovalUserManagementTest.php
+++ b/tests/Feature/Approval/ApprovalUserManagementTest.php
@@ -979,6 +979,28 @@ public function test_the_search_box_looks_at_the_name_the_number_and_the_email()
         $this->assertTrue($find('needle@')->contains($byEmail->id), 'メールアドレスで検索できない');
     }
 
+    /**
+     * 氏名は登録名の空白（半角・全角）を無視して当て、空白で分けた語のどれも含む人に当たる（決裁台帳の申請者と同じ。段階5 設計書 D25）。
+     * 社員番号とメールアドレスは今までどおり打った文字のまま当てる。
+     */
+    public function test_the_name_search_ignores_the_spaces_in_the_registered_name(): void
+    {
+        $yamada = $this->member(['name' => '山田 太郎', 'employee_number' => 'AAA1']);
+        $sato   = $this->member(['name' => '佐藤　次郎', 'employee_number' => 'BBB2']);
+        $other  = $this->member(['name' => '田中 一郎', 'employee_number' => 'CCC3']);
+
+        $find = fn (string $query) => $this->actingAs($this->admin())
+            ->get(route('approvals.admin.users.index', ['search' => $query]))
+            ->assertOk()->viewData('users')->pluck('id')->sort()->values()->all();
+
+        $this->assertSame([$yamada->id], $find('山田太郎'), '登録名の半角の空白を無視して当てる');
+        $this->assertSame([$sato->id], $find('佐藤次郎'), '登録名の全角の空白を無視して当てる');
+        $this->assertSame([$yamada->id], $find('山田 太郎'), '空白を入れて打っても当たる');
+        $this->assertSame([$yamada->id], $find('田　太'), '全角の空白で分けた語のどれも含む人に当たる');
+        $this->assertSame([], $find('田中次郎'), '語がそろわなければ当たらない');
+        $this->assertSame([$other->id], $find('CCC3'), '社員番号は今までどおり当たる');
+    }
+
     /** 検索語が配列で届いても落とさない（`"%{$search}%"` が `Array to string conversion` で 500 になる） */
     public function test_an_array_shaped_filter_does_not_break_the_page(): void
     {
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0001-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/ApprovalUserManagementTest.php
```

Expected: `FAILURES!` `Tests: 62, Assertions: 314, Failures: 1.`

- `ApprovalUserManagementTest::test_the_name_search_ignores_the_spaces_in_the_registered_name` — `登録名の半角の空白を無視して当てる`

- [ ] **Step 3: 名前の探し方を 1 か所にして、⑦ と台帳が使う**

`app/Support/Approval/NameSearch.php`（新規）

```php
<?php

namespace App\Support\Approval;

use Illuminate\Database\Eloquent\Builder;

/**
 * 人の名前の探し方（決裁台帳の申請者〈段階4 D21〉・⑦ 利用者の管理〈段階5 D25〉）。2 つの画面が同じ規則で探すよう 1 か所に置く。
 *
 * 空白（全角を含む）で分けた語の**どれも**含む人に当て、登録名の半角・全角の空白は無視する
 * （「山田太郎」で「山田 太郎」も、「田　郎」でも当たる）。`LIKE` の `%` と `_` は逃がさない（アプリのほかの検索と同じ）。
 */
final class NameSearch
{
    /**
     * 空白（全角を含む）で分けた語（空の語は除く）
     *
     * @return list<string>
     */
    public static function terms(?string $text): array
    {
        return $text === null ? [] : (preg_split('/[\s\x{3000}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * users の問い合わせに「登録名の空白を除いた名前が、どの語も含む」を足す（語が無ければ何も足さない）。
     * ⚠ 列は表の名前を付けて書く（JOIN を足しても名前の列が曖昧にならない）
     *
     * @param list<string> $terms
     */
    public static function whereNameHasAll(Builder $users, array $terms): void
    {
        foreach ($terms as $term) {
            $users->whereRaw("replace(replace(users.name, ' ', ''), '　', '') like ?", ["%{$term}%"]);
        }
    }
}
```

`app/Http/Controllers/Approval/UserController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/UserController.php
+++ b/app/Http/Controllers/Approval/UserController.php
@@ -9,6 +9,7 @@
 use App\Models\ApprovalMember;
 use App\Models\ApprovalSetting;
 use App\Models\User;
+use App\Support\Approval\NameSearch;
 use App\Support\Approval\PasswordReissuer;
 use App\Support\Approval\SettingLogger;
 use App\Support\LoginId;
@@ -102,9 +103,14 @@ private function filteredQuery(Request $request): Builder
         }
 
         if ($search !== '') {
-            $query->where(function ($q) use ($search) {
-                $q->where('name', 'like', "%{$search}%")
-                  ->orWhere('employee_number', 'like', "%{$search}%")
+            // 氏名は登録名の空白（半角・全角）を無視し、空白で分けた語のどれも含む人に当てる（「山田太郎」で「山田 太郎」も探せる。
+            // 決裁台帳の申請者と同じ部品。段階5 設計書 D25）。語が無い（空白だけ）ときは氏名では当てない
+            $terms = NameSearch::terms($search);
+            $query->where(function ($q) use ($search, $terms) {
+                if ($terms !== []) {
+                    $q->where(fn ($q) => NameSearch::whereNameHasAll($q, $terms));
+                }
+                $q->orWhere('employee_number', 'like', "%{$search}%")
                   ->orWhere('email', 'like', "%{$search}%");
             });
         }
```

`app/Support/Approval/Ledger.php`（変更）

```diff
--- a/app/Support/Approval/Ledger.php
+++ b/app/Support/Approval/Ledger.php
@@ -76,13 +76,10 @@ public static function query(User $viewer, LedgerFilter $filter): Builder
 
         // 申請者は名前の一部（空白で分けた語のどれも含む。D21）。登録名の半角・全角の空白は無視して当てる（「申請 花子」を「申請花子」で探せる）。
         // 退職して消した人の申請も探せる（applicant は withTrashed）
-        $names = LedgerFilter::terms($filter->applicant);
+        // （⑦ 利用者の管理の検索と同じ部品。段階5 D25）
+        $names = NameSearch::terms($filter->applicant);
         if ($names !== []) {
-            $query->whereHas('applicant', function (Builder $q) use ($names): void {
-                foreach ($names as $name) {
-                    $q->whereRaw("replace(replace(name, ' ', ''), '　', '') like ?", ["%{$name}%"]);
-                }
-            });
+            $query->whereHas('applicant', fn (Builder $q) => NameSearch::whereNameHasAll($q, $names));
         }
 
         $words = LedgerFilter::terms($filter->keyword);
```

`app/Support/Approval/LedgerFilter.php`（変更）

```diff
--- a/app/Support/Approval/LedgerFilter.php
+++ b/app/Support/Approval/LedgerFilter.php
@@ -165,13 +165,13 @@ public function toLog(): array
     }
 
     /**
-     * 空白（全角を含む）で分けた語。どの語も含むものに当てる（「山田 太郎」で「山田太郎」も探せる）
+     * 空白（全角を含む）で分けた語。どの語も含むものに当てる（「山田 太郎」で「山田太郎」も探せる）。分け方は NameSearch と同じ
      *
      * @return list<string>
      */
     public static function terms(?string $text): array
     {
-        return $text === null ? [] : array_values(array_filter(preg_split('/[\s\x{3000}]+/u', $text) ?: [], fn (string $t) => $t !== ''));
+        return NameSearch::terms($text);
     }
 
     private static function positiveInt(string $value): ?int
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0001-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 のテストと、台帳の申請者の検索のテスト）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/ApprovalUserManagementTest.php tests/Feature/Approval/Phase4/LedgerQueryTest.php tests/Feature/Approval/Phase4/LedgerFilterTest.php
```

Expected: `OK`（赤なし。台帳のテストは変えずに通る＝台帳の探し方は同じ）

- [ ] **Step 5: 全件を流す**

Expected: `OK (3935 tests, 37453 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/NameSearch.php app/Http/Controllers/Approval/UserController.php app/Support/Approval/Ledger.php app/Support/Approval/LedgerFilter.php tests/Feature/Approval/ApprovalUserManagementTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 利用者の管理の検索で登録名の空白を無視する

⑦ の氏名の検索も決裁台帳の申請者と同じく、登録名の半角・全角の空白を
無視し、空白で分けた語のどれも含む人に当てる（段階5 設計書 D25）。
2 つの画面の探し方を NameSearch の 1 か所にまとめた。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 2: 明細表と追加の欄・使える部門の表と、控えの引き方を 1 つに

`approval_types` に本文の形・明細表の行・決まり文句・追加の欄の使う／使わない・定型文、`approval_requests` に明細表・坪数・坪単価・担当者・契約予定日・定型文の列を、本番の SQL とテストの migration の対で足し、使える部門の表を作る。あわせて、1 つの申請の最後に提出した控えの引き方を `ApprovalRequest::submittedRevision()` の 1 か所にする（設計書 §5.3・D4・D5・D12・D13・§8・§0.2）。

**Files:**
- Create: `database/sql/2026-10-07-approval-phase5a.sql`・`database/migrations/2026_10_07_000001_add_approval_phase5a_columns.php`・`app/Enums/ApprovalBodyForm.php`
- Modify: `app/Models/ApprovalType.php`・`app/Models/ApprovalRequest.php`・`app/Support/Approval/RequestContent.php`・`app/Support/Approval/UndoTarget.php`・`app/Support/Approval/Notifier.php`・`app/Support/Approval/LedgerRow.php`
- Test: Create `tests/Feature/Approval/Phase5/Phase5aTablesTest.php`／Modify `tests/Feature/Approval/Phase2/Phase2TablesTest.php`・`tests/Feature/Approval/Phase2/Phase2ModelsTest.php`

**Interfaces:**
- Consumes: なし（Task 1 と独立）
- Produces: §0.2 の列と表・`ApprovalBodyForm::Points`（`'points'`）・`ApprovalBodyForm::Table`（`'table'`）・`label()`
- Produces: `ApprovalType::departments(): BelongsToMany`・`ApprovalType::scopeUsableIn(Builder $query, list<int> $departmentIds): Builder`（`ApprovalType::usableIn($ids)`）・`ApprovalType::isUsableIn(?int $departmentId): bool`・`ApprovalType::usesTable(): bool`・`casts()` の `body_form => ApprovalBodyForm`・`table_layout => array`・`uses_* => boolean`
- Produces: `ApprovalRequest` の `casts()` の `amount_table => array`・`tsubo => decimal:2`・`tsubo_price => integer`・`contract_date => date`・`ApprovalRequest::submittedRevision(): ?ApprovalRevision`。Task 5（`SubmitChecker`）が使う

**差分の大きさ:** 12 ファイル・+468 / −14 行（差分のファイル `0002-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase5/Phase5aTablesTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase5;

use App\Enums\ApprovalBodyForm;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\ApprovalType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 段階5（5a）の列と表（段階5 設計書 §5.3）。
 *
 * ⚠ 本番は SQL を手で流し、テストは migration で作る。**両方が食い違わないこと**をここで固定する（Phase4aTablesTest と同じ考え）。
 *   比べるのは、触る表・足す列の名前・NULL を許すか・NOT NULL の列の既定・作る表の列と外部キー。**両方向に比べる**。
 */
class Phase5aTablesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const SQL = 'database/sql/2026-10-07-approval-phase5a.sql';

    private const MIGRATION = 'database/migrations/2026_10_07_000001_add_approval_phase5a_columns.php';

    private const TYPES = 'BIGINT|INT|SMALLINT|TINYINT|CHAR|VARCHAR|DECIMAL|MEDIUMTEXT|TEXT|JSON|TIMESTAMP|DATE';

    private function sql(): string
    {
        return preg_replace('/^--.*$/m', '', file_get_contents(base_path(self::SQL)));
    }

    /** @return array<string, array<string, array{nullable: bool, definition: string}>> 表 => [足す列 => NULL を許すか・定義] */
    private function sqlAddedColumns(): array
    {
        preg_match_all('/ALTER TABLE `(\w+)`(.*?);/s', $this->sql(), $alters, PREG_SET_ORDER);

        $tables = [];
        foreach ($alters as [, $table, $body]) {
            preg_match_all('/ADD COLUMN `(\w+)` (?:' . self::TYPES . ')\b([^\n]*)/', $body, $columns, PREG_SET_ORDER);
            foreach ($columns as [, $column, $definition]) {
                $tables[$table][$column] = ['nullable' => ! str_contains($definition, 'NOT NULL'), 'definition' => $definition];
            }
        }

        return $tables;
    }

    /** @return array<string, string> 表 => CREATE の括弧の中 */
    private function sqlCreates(): array
    {
        preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\n\) ENGINE/s', $this->sql(), $creates, PREG_SET_ORDER);

        $tables = [];
        foreach ($creates as [, $table, $body]) {
            $tables[$table] = $body;
        }

        return $tables;
    }

    public function test_the_sql_and_the_migration_touch_the_same_tables(): void
    {
        $migration = file_get_contents(base_path(self::MIGRATION));
        preg_match_all("/Schema::table\\('(\\w+)'/", $migration, $altered);
        preg_match_all("/Schema::create\\('(\\w+)'/", $migration, $created);

        $this->assertEqualsCanonicalizing(['approval_types', 'approval_requests'], array_keys($this->sqlAddedColumns()));
        $this->assertEqualsCanonicalizing(array_keys($this->sqlAddedColumns()), array_values(array_unique($altered[1])));
        $this->assertSame(['approval_type_department'], array_keys($this->sqlCreates()));
        $this->assertSame(['approval_type_department'], $created[1]);
    }

    public function test_the_sql_and_the_migration_add_the_same_columns(): void
    {
        $sql = $this->sqlAddedColumns();

        // 空振りで緑にならないように（種類に 8 列・申請に 6 列）
        $this->assertSame(
            ['body_form', 'table_layout', 'subject_suffix', 'uses_tsubo', 'uses_tsubo_price', 'uses_staff', 'uses_contract_date', 'fixed_text'],
            array_keys($sql['approval_types'] ?? [])
        );
        $this->assertSame(['amount_table', 'tsubo', 'tsubo_price', 'staff', 'contract_date', 'fixed_text'], array_keys($sql['approval_requests'] ?? []));

        foreach ($sql as $table => $columns) {
            $migrated = [];
            foreach (Schema::getColumns($table) as $column) {
                if (isset($columns[$column['name']])) {
                    $migrated[$column['name']] = ['nullable' => (bool) $column['nullable'], 'default' => $column['default']];
                }
            }

            $this->assertSame(array_keys($columns), array_keys($migrated), "{$table} の足す列が SQL と migration で違う");
            foreach ($columns as $name => $column) {
                $this->assertSame($column['nullable'], $migrated[$name]['nullable'], "{$table}.{$name} の NULL を許すかが SQL と migration で違う");

                // NOT NULL の列は既定が同じ（今の種類が 5W2H の種類のまま・追加の欄は使わない）
                if (! $column['nullable']) {
                    preg_match("/DEFAULT '?([^' ]+)'?/", $column['definition'], $default);
                    $this->assertSame($default[1] ?? null, trim((string) $migrated[$name]['default'], "'"), "{$table}.{$name} の既定が SQL と migration で違う");
                }
            }
        }
    }

    public function test_the_usable_departments_table_is_the_same_in_the_sql_and_the_migration(): void
    {
        $body = $this->sqlCreates()['approval_type_department'];

        preg_match_all('/`(\w+)` (?:' . self::TYPES . ')\b([^,]*)/', $body, $columns, PREG_SET_ORDER);
        $sql = [];
        foreach ($columns as [, $column, $definition]) {
            $sql[$column] = ! str_contains($definition, 'NOT NULL');
        }
        $migrated = [];
        foreach (Schema::getColumns('approval_type_department') as $column) {
            $migrated[$column['name']] = (bool) $column['nullable'];
        }
        $this->assertSame(['type_id' => false, 'department_id' => false, 'created_at' => true], $sql);
        $this->assertEquals($sql, $migrated);

        // 主キーは種類と部門の組・外部キーはどちらも親を消すと消える
        $this->assertStringContainsString('PRIMARY KEY (`type_id`, `department_id`)', $body);
        $primary = array_values(array_filter(Schema::getIndexes('approval_type_department'), fn (array $index) => $index['primary']));
        $this->assertSame(['type_id', 'department_id'], $primary[0]['columns'] ?? null);

        preg_match_all('/FOREIGN KEY \(`(\w+)`\) REFERENCES `(\w+)` \(`id`\) ON DELETE (\w+)/', $body, $keys, PREG_SET_ORDER);
        $sqlKeys = [];
        foreach ($keys as [, $column, $parent, $onDelete]) {
            $sqlKeys[$column] = [$parent, strtolower($onDelete)];
        }
        $migratedKeys = [];
        foreach (Schema::getForeignKeys('approval_type_department') as $key) {
            $migratedKeys[$key['columns'][0]] = [$key['foreign_table'], strtolower($key['on_delete'])];
        }
        ksort($sqlKeys);
        ksort($migratedKeys);
        $this->assertSame(['department_id' => ['approval_departments', 'cascade'], 'type_id' => ['approval_types', 'cascade']], $sqlKeys);
        $this->assertSame($sqlKeys, $migratedKeys);
    }

    /** 今の種類（列を足す前に作った種類）は 5W2H の種類のまま・追加の欄は使わない */
    public function test_a_type_without_the_new_columns_is_a_points_type(): void
    {
        $w = $this->approvalWorld();

        DB::table('approval_types')->insert([
            'name' => '前からある種類', 'headings' => '■ なぜ', 'review_department_id' => $w['reviewDept']->id, 'sort_order' => 2, 'is_active' => true,
        ]);
        $old = ApprovalType::where('name', '前からある種類')->firstOrFail();

        $this->assertSame(ApprovalBodyForm::Points, $old->body_form);
        $this->assertFalse($old->usesTable());
        $this->assertNull($old->table_layout);
        $this->assertFalse($old->uses_tsubo || $old->uses_tsubo_price || $old->uses_staff || $old->uses_contract_date);

        // 保存して読み直す前の新しい種類も 5W2H の種類
        $this->assertSame(ApprovalBodyForm::Points, (new ApprovalType())->body_form);
    }

    public function test_the_usable_departments_and_the_scope(): void
    {
        $w      = $this->approvalWorld();
        $other  = $this->approvalDepartment($w['company']);
        $open   = $w['type'];
        $closed = $this->approvalType($w['reviewDept'], ['name' => '住宅の契約用']);
        $closed->departments()->attach($w['dept']->id);
        $elsewhere = $this->approvalType($w['reviewDept'], ['name' => 'ほかの部門の種類']);
        $elsewhere->departments()->attach($other->id);

        $this->assertSame([$open->id, $closed->id], ApprovalType::usableIn([$w['dept']->id])->orderBy('id')->pluck('id')->all());
        $this->assertSame([$open->id, $elsewhere->id], ApprovalType::usableIn([$other->id])->orderBy('id')->pluck('id')->all());
        $this->assertSame([$open->id], ApprovalType::usableIn([])->orderBy('id')->pluck('id')->all(), '所属部門の無い人には使える部門の無い種類だけ');

        $this->assertTrue($open->fresh()->isUsableIn($other->id));
        $this->assertTrue($closed->fresh()->isUsableIn($w['dept']->id));
        $this->assertFalse($closed->fresh()->isUsableIn($other->id));
        $this->assertFalse($closed->fresh()->isUsableIn(null));
        $this->assertNotNull(DB::table('approval_type_department')->where('type_id', $closed->id)->value('created_at'), '足した日時を残す');

        // 種類を消すと使える部門の行も消える
        $elsewhere->delete();
        $this->assertSame(0, DB::table('approval_type_department')->where('type_id', $elsewhere->id)->count());
    }

    public function test_the_request_keeps_the_table_and_the_extra_fields(): void
    {
        $w       = $this->approvalWorld();
        $request = $this->draftFor($w, [
            'amount_table'  => ['subtotal' => true, 'upper' => [['name' => '工事請負金額', 'fixed' => true, 'sale' => 28500000, 'cost' => 22000000]], 'lower' => []],
            'tsubo'         => '38.5',
            'tsubo_price'   => 1083000,
            'staff'         => '佐藤 健一',
            'contract_date' => '2026-10-20',
            'fixed_text'    => '上記の内容に基づき、販売をおこないます。',
        ]);

        $fresh = ApprovalRequest::findOrFail($request->id);
        $this->assertSame(28500000, $fresh->amount_table['upper'][0]['sale']);
        $this->assertTrue($fresh->amount_table['subtotal']);
        $this->assertSame('38.50', $fresh->tsubo);
        $this->assertSame(1083000, $fresh->tsubo_price);
        $this->assertSame('佐藤 健一', $fresh->staff);
        $this->assertSame('2026-10-20', $fresh->contract_date->format('Y-m-d'));
        $this->assertSame('上記の内容に基づき、販売をおこないます。', $fresh->fixed_text);
    }

    /** 最後に提出した控えの入口（段階5 設計書 §8）。今の回の控えを返し、先に読んだ lastRevision があれば問い合わせない */
    public function test_the_submitted_revision_is_the_one_of_the_current_round(): void
    {
        $w = $this->approvalWorld();
        $this->assertNull($this->draftFor($w)->submittedRevision(), '一度も提出していない下書きには無い');

        $request = $this->submittedFor($w);
        $first   = $request->submittedRevision();
        $this->assertSame([$request->round, '社用車の購入'], [$first?->round, $first?->snapshot['subject'] ?? null]);

        // 出し直した（回が進んだ）申請は今の回の控え
        DB::table('approval_requests')->where('id', $request->id)->update(['round' => $request->round + 1]);
        $second = ApprovalRevision::create(['request_id' => $request->id, 'round' => $request->round + 1, 'snapshot' => ['subject' => '出し直した件名'], 'submitted_by' => $w['applicant']->id]);
        $this->assertSame($second->id, $request->fresh()->submittedRevision()?->id);

        // 台帳のように lastRevision を先に読んでいれば、問い合わせない
        $loaded = ApprovalRequest::with('lastRevision')->findOrFail($request->id);
        DB::enableQueryLog();
        $this->assertSame($second->id, $loaded->submittedRevision()?->id);
        $this->assertSame([], DB::getQueryLog(), '先に読んだ控えを使っていない');
        DB::disableQueryLog();

        // 今の回の控えが無ければ（前の回の控えしか無ければ）無い。どちらの読み方でも同じ
        DB::table('approval_requests')->where('id', $request->id)->update(['round' => $request->round + 2]);
        $this->assertNull($request->fresh()->submittedRevision());
        $this->assertNull(ApprovalRequest::with('lastRevision')->findOrFail($request->id)->submittedRevision());
    }
}
```

`tests/Feature/Approval/Phase2/Phase2TablesTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/Phase2TablesTest.php
+++ b/tests/Feature/Approval/Phase2/Phase2TablesTest.php
@@ -36,6 +36,8 @@ class Phase2TablesTest extends TestCase
         'approval_settings' => ['mail_last_sent_at', 'mail_last_failed_at', 'mail_last_failed_to'],   // 3a（Phase3TablesTest）
         'approval_steps'    => ['stamp_label', 'stamp_text'],                                         // 4a（Phase4aTablesTest）
         'approval_download_logs' => ['filters', 'request_count'],                                    // 4b（Phase4bTablesTest）
+        'approval_types'    => ['body_form', 'table_layout', 'subject_suffix', 'uses_tsubo', 'uses_tsubo_price', 'uses_staff', 'uses_contract_date', 'fixed_text'],   // 5a（Phase5aTablesTest）
+        'approval_requests' => ['amount_table', 'tsubo', 'tsubo_price', 'staff', 'contract_date', 'fixed_text'],   // 5a（Phase5aTablesTest）
     ];
 
     /** あとの段階が NULL を許すように変えた 2a の列（その段階の表のテストが見る。ここでは NULL を許すものとして比べる）。表 => 列 */
```

`tests/Feature/Approval/Phase2/Phase2ModelsTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/Phase2ModelsTest.php
+++ b/tests/Feature/Approval/Phase2/Phase2ModelsTest.php
@@ -183,7 +183,11 @@ public function test_attachment_helpers(): void
     public function test_state_columns_are_not_mass_assignable(): void
     {
         $this->assertSame(
-            ['user_id', 'department_id', 'type_id', 'subject', 'amount', 'schedule', 'body', 'related_numbers'],
+            [
+                'user_id', 'department_id', 'type_id', 'subject', 'amount', 'schedule', 'body', 'related_numbers',
+                // 段階5 の中身（明細表と追加の欄・定型文。下書きと差戻し中に申請者が直す中身。状態の列ではない）
+                'amount_table', 'tsubo', 'tsubo_price', 'staff', 'contract_date', 'fixed_text',
+            ],
             (new ApprovalRequest())->getFillable()
         );
     }
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0002-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase2/Phase2ModelsTest.php tests/Feature/Approval/Phase2/Phase2TablesTest.php tests/Feature/Approval/Phase5/Phase5aTablesTest.php
```

Expected: `ERRORS!` `Tests: 27, Assertions: 83, Errors: 7, Failures: 1.`

- `Phase5aTablesTest::test_the_sql_and_the_migration_touch_the_same_tables` — `ErrorException: file_get_contents(/Users/masanori/site/manage/.claude/worktrees/approval-phase3/database/migratio`
- `Phase5aTablesTest::test_the_sql_and_the_migration_add_the_same_columns` — `ErrorException: file_get_contents(/Users/masanori/site/manage/.claude/worktrees/approval-phase3/database/sql/2026`
- `Phase5aTablesTest::test_the_usable_departments_table_is_the_same_in_the_sql_and_the_migration` — `ErrorException: file_get_contents(/Users/masanori/site/manage/.claude/worktrees/approval-phase3/database/sql/2026`
- `Phase5aTablesTest::test_a_type_without_the_new_columns_is_a_points_type` — `Error: Class "App\Enums\ApprovalBodyForm" not found`
- `Phase5aTablesTest::test_the_usable_departments_and_the_scope` — `BadMethodCallException: Call to undefined method App\Models\ApprovalType::departments()`
- `Phase5aTablesTest::test_the_request_keeps_the_table_and_the_extra_fields` — `ErrorException: Trying to access array offset on null`
- `Phase5aTablesTest::test_the_submitted_revision_is_the_one_of_the_current_round` — `BadMethodCallException: Call to undefined method App\Models\ApprovalRequest::submittedRevision()`
- `Phase2ModelsTest::test_state_columns_are_not_mass_assignable` — `Failed asserting that two arrays are identical.`

- [ ] **Step 3: 表とモデルを足し、控えの引き方を 1 つにする**

`database/sql/2026-10-07-approval-phase5a.sql`（新規）

```sql
-- 決裁申請 段階5（5a）— 2026-10-07
--
-- 設計書: docs/superpowers/specs/2026-10-07-approval-phase5-design.md §5.3
--
-- ⚠ database/migrations/2026_10_07_000001_add_approval_phase5a_columns.php と
--   対で維持すること（あちらは SQLite のテストのための鏡。Phase5aTablesTest が見る）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** 新しいコードは approval_types.body_form などと approval_requests.amount_table などを
--   読み書きするので、コードを先に送ると、申請種類の管理（⑨）・申請の画面・詳細・台帳が Unknown column で 500 になる。
--
-- ⚠ 今の種類は本文の形の既定（points＝5W2H の見出し）になるだけ（埋め直す SQL は要らない）。
--
-- 適用: 段階1〜4 と同じく php artisan tinker --execute で DB::statement() に **1 文ずつ**流す。
--   先頭で「approval_types に body_form があるか、approval_type_department があれば 1 文も流さずに止まる」確認をする（計画 Task 13）。

-- 1. 申請の種類ごとの作り込み（要件 5.5.1。D4・D12）
ALTER TABLE `approval_types`
  ADD COLUMN `body_form` VARCHAR(20) NOT NULL DEFAULT 'points' COMMENT '本文の形（points＝5W2H の見出し／table＝金額の明細表）' AFTER `headings`,
  ADD COLUMN `table_layout` JSON NULL COMMENT '明細表の行（前半・後半の行の名前。名前が空なら自由行）と「計」の行の有無' AFTER `body_form`,
  ADD COLUMN `subject_suffix` VARCHAR(50) NULL COMMENT '件名の決まり文句' AFTER `table_layout`,
  ADD COLUMN `uses_tsubo` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '坪数を使う' AFTER `subject_suffix`,
  ADD COLUMN `uses_tsubo_price` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '坪単価を使う' AFTER `uses_tsubo`,
  ADD COLUMN `uses_staff` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '担当者を使う（使う種類では提出に必須）' AFTER `uses_tsubo_price`,
  ADD COLUMN `uses_contract_date` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '契約予定日を使う（使う種類では提出に必須）' AFTER `uses_staff`,
  ADD COLUMN `fixed_text` TEXT NULL COMMENT '定型文（本文の下に出す固定の文）' AFTER `uses_contract_date`;

-- 2. 種類を使える部門（1 行も無ければ全部門。要件 5.5.1・D13）
CREATE TABLE `approval_type_department` (
  `type_id` BIGINT UNSIGNED NOT NULL,
  `department_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`type_id`, `department_id`),
  KEY `idx_approval_type_department_dept` (`department_id`),
  CONSTRAINT `fk_approval_type_department_type` FOREIGN KEY (`type_id`) REFERENCES `approval_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_approval_type_department_dept` FOREIGN KEY (`department_id`) REFERENCES `approval_departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 申請の明細表と追加の入力欄（要件 5.5.4・5.5.5。D5・D14）
ALTER TABLE `approval_requests`
  ADD COLUMN `amount_table` JSON NULL COMMENT '金額の明細表（前半・後半の行と「計」の行の有無。明細表の種類だけ）' AFTER `body`,
  ADD COLUMN `tsubo` DECIMAL(7,2) NULL COMMENT '坪数' AFTER `amount_table`,
  ADD COLUMN `tsubo_price` BIGINT UNSIGNED NULL COMMENT '坪単価（円）' AFTER `tsubo`,
  ADD COLUMN `staff` VARCHAR(50) NULL COMMENT '担当者' AFTER `tsubo_price`,
  ADD COLUMN `contract_date` DATE NULL COMMENT '契約予定日' AFTER `staff`,
  ADD COLUMN `fixed_text` TEXT NULL COMMENT '定型文（保存したときに種類から写す。提出したあとは変わらない）' AFTER `contract_date`;
```

`database/migrations/2026_10_07_000001_add_approval_phase5a_columns.php`（新規）

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 決裁申請 段階5（5a）の列と表（段階5 設計書 §5.3）。
 *
 * ⚠ **これは SQLite のテストのための鏡**。本番は `database/sql/2026-10-07-approval-phase5a.sql` を
 *   手で流す（このプロジェクトは migration で本番を管理していない）。**両方を対で維持すること**（Phase5aTablesTest が見る）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_types', function (Blueprint $table) {
            $table->string('body_form', 20)->default('points')->after('headings')
                  ->comment('本文の形（points＝5W2H の見出し／table＝金額の明細表）');
            $table->json('table_layout')->nullable()->after('body_form')
                  ->comment('明細表の行（前半・後半の行の名前。名前が空なら自由行）と「計」の行の有無');
            $table->string('subject_suffix', 50)->nullable()->after('table_layout')->comment('件名の決まり文句');
            $table->boolean('uses_tsubo')->default(false)->after('subject_suffix')->comment('坪数を使う');
            $table->boolean('uses_tsubo_price')->default(false)->after('uses_tsubo')->comment('坪単価を使う');
            $table->boolean('uses_staff')->default(false)->after('uses_tsubo_price')->comment('担当者を使う（使う種類では提出に必須）');
            $table->boolean('uses_contract_date')->default(false)->after('uses_staff')->comment('契約予定日を使う（使う種類では提出に必須）');
            $table->text('fixed_text')->nullable()->after('uses_contract_date')->comment('定型文（本文の下に出す固定の文）');
        });

        Schema::create('approval_type_department', function (Blueprint $table) {
            $table->foreignId('type_id')->constrained('approval_types')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('approval_departments')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['type_id', 'department_id']);
            $table->index('department_id', 'idx_approval_type_department_dept');
        });

        Schema::table('approval_requests', function (Blueprint $table) {
            $table->json('amount_table')->nullable()->after('body')
                  ->comment('金額の明細表（前半・後半の行と「計」の行の有無。明細表の種類だけ）');
            $table->decimal('tsubo', 7, 2)->nullable()->after('amount_table')->comment('坪数');
            $table->unsignedBigInteger('tsubo_price')->nullable()->after('tsubo')->comment('坪単価（円）');
            $table->string('staff', 50)->nullable()->after('tsubo_price')->comment('担当者');
            $table->date('contract_date')->nullable()->after('staff')->comment('契約予定日');
            $table->text('fixed_text')->nullable()->after('contract_date')->comment('定型文（保存したときに種類から写す。提出したあとは変わらない）');
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropColumn(['amount_table', 'tsubo', 'tsubo_price', 'staff', 'contract_date', 'fixed_text']);
        });

        Schema::dropIfExists('approval_type_department');

        Schema::table('approval_types', function (Blueprint $table) {
            $table->dropColumn([
                'body_form', 'table_layout', 'subject_suffix',
                'uses_tsubo', 'uses_tsubo_price', 'uses_staff', 'uses_contract_date', 'fixed_text',
            ]);
        });
    }
};
```

`app/Enums/ApprovalBodyForm.php`（新規）

```php
<?php

namespace App\Enums;

/** 申請の種類の本文の形（要件 5.5.2）。申請（下書きを含む）がある種類では切り替えられない（段階5 設計書 D4） */
enum ApprovalBodyForm: string
{
    case Points = 'points';
    case Table  = 'table';

    public function label(): string
    {
        return match ($this) {
            self::Points => '5W2H の見出し',
            self::Table  => '金額の明細表',
        };
    }
}
```

`app/Models/ApprovalType.php`（変更）

```diff
--- a/app/Models/ApprovalType.php
+++ b/app/Models/ApprovalType.php
@@ -2,24 +2,51 @@
 
 namespace App\Models;
 
+use App\Enums\ApprovalBodyForm;
 use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Database\Eloquent\Model;
 use Illuminate\Database\Eloquent\Relations\BelongsTo;
+use Illuminate\Database\Eloquent\Relations\BelongsToMany;
 use Illuminate\Database\Eloquent\Relations\HasMany;
 
 /**
- * 申請の種類（要件 5.5.1 のうち段階2 の分・設計書 §5.5）。
+ * 申請の種類（要件 5.5.1・段階2 設計書 §5.5・段階5 設計書 §5.4）。
  *
- * ⚠ 本文の形（金額の明細表）・件名の決まり文句・明細表の行・追加の入力欄・定型文・使える部門は
- *   段階5 で列を足す。ここでは 5W2H の見出しの形だけ。
+ * - 本文の形（`body_form`）: 5W2H の見出し／金額の明細表。申請（下書きを含む）がある種類では切り替えられない（D4。⑨ が断る）
+ * - 明細表の行（`table_layout`）: `{subtotal: bool, upper: list<?string>, lower: list<?string>}`（名前が null の行は自由行。
+ *   形は `App\Support\Approval\AmountTable::layout()` がそろえる）。明細表の種類だけ
+ * - 件名の決まり文句・追加の入力欄（`uses_*`）・定型文・使える部門（`departments`）は、どちらの本文の形でも使える（D12）
+ * ⚠ 行の名前・定型文・決まり文句を変えても、提出済みの申請は変わらない（申請が自分の行・定型文・組み立てた件名を持つ。D14）
  */
 class ApprovalType extends Model
 {
-    protected $fillable = ['name', 'headings', 'review_department_id', 'sort_order', 'is_active'];
+    protected $fillable = [
+        'name', 'headings', 'review_department_id', 'sort_order', 'is_active',
+        'body_form', 'table_layout', 'subject_suffix', 'uses_tsubo', 'uses_tsubo_price', 'uses_staff', 'uses_contract_date', 'fixed_text',
+    ];
+
+    /** 新しく作った種類の既定（DB の既定と同じ。保存して読み直す前でも 5W2H の種類として扱う） */
+    protected $attributes = [
+        'body_form'          => 'points',
+        'uses_tsubo'         => false,
+        'uses_tsubo_price'   => false,
+        'uses_staff'         => false,
+        'uses_contract_date' => false,
+    ];
 
     protected function casts(): array
     {
-        return ['review_department_id' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];
+        return [
+            'review_department_id' => 'integer',
+            'sort_order'           => 'integer',
+            'is_active'            => 'boolean',
+            'body_form'            => ApprovalBodyForm::class,
+            'table_layout'         => 'array',
+            'uses_tsubo'           => 'boolean',
+            'uses_tsubo_price'     => 'boolean',
+            'uses_staff'           => 'boolean',
+            'uses_contract_date'   => 'boolean',
+        ];
     }
 
     public function reviewDepartment(): BelongsTo
@@ -32,11 +59,44 @@ public function requests(): HasMany
         return $this->hasMany(ApprovalRequest::class, 'type_id');
     }
 
+    /** 使える部門（1 つも無ければ全部門。要件 5.5.1・D13） */
+    public function departments(): BelongsToMany
+    {
+        return $this->belongsToMany(ApprovalDepartment::class, 'approval_type_department', 'type_id', 'department_id')
+                    ->withTimestamps('created_at', false);
+    }
+
     public function scopeActive(Builder $query): Builder
     {
         return $query->where('is_active', true);
     }
 
+    /**
+     * 渡した部門のどれかで使える種類（使える部門の無い種類と、渡した部門のどれかを使える部門に持つ種類。D13）
+     *
+     * @param list<int> $departmentIds
+     */
+    public function scopeUsableIn(Builder $query, array $departmentIds): Builder
+    {
+        return $query->where(fn (Builder $q) => $q
+            ->whereDoesntHave('departments')
+            ->orWhereHas('departments', fn (Builder $q) => $q->whereIn('approval_departments.id', $departmentIds)));
+    }
+
+    /** この部門で使えるか（使える部門は先に読んでおく。読んでいなければ読む） */
+    public function isUsableIn(?int $departmentId): bool
+    {
+        $ids = $this->departments->pluck('id');
+
+        return $ids->isEmpty() || ($departmentId !== null && $ids->contains($departmentId));
+    }
+
+    /** 金額の明細表の種類か */
+    public function usesTable(): bool
+    {
+        return $this->body_form === ApprovalBodyForm::Table;
+    }
+
     public function scopeOrdered(Builder $query): Builder
     {
         return $query->orderBy('sort_order')->orderBy('id');
```

`app/Models/ApprovalRequest.php`（変更）

```diff
--- a/app/Models/ApprovalRequest.php
+++ b/app/Models/ApprovalRequest.php
@@ -16,13 +16,16 @@
  *
  * ⚠ **状態を `update()` で直接変えない。** 状態の移り変わりは `App\Support\Approval\Workflow` だけが行う
  *   （同時操作の見張り `lock_version` と記録を一緒に書くため。設計書 §5.1）。
- * ⚠ 中身（件名・金額・実施時期・本文・関連する決裁No・種類・申請部門）を書き換えてよいのは
- *   下書きと差戻し中だけ（`RequestController` が `RequestPermissions::canEdit()` で確かめる）。
+ * ⚠ 中身（件名・金額・実施時期・本文・関連する決裁No・種類・申請部門・明細表・坪数・坪単価・担当者・契約予定日・定型文）を
+ *   書き換えてよいのは下書きと差戻し中だけ（`RequestController` が `RequestPermissions::canEdit()` で確かめる）。
+ * ⚠ 明細表の種類では、金額（`amount`）は保存のときに明細表の合計金額の販売金額から計算する（画面の数を使わない。段階5 設計書 D15）。
+ *   本文（`body`）は補足（自由記入）。定型文（`fixed_text`）は保存したときに種類から写す（提出したあとは変わらない。D14）
  */
 class ApprovalRequest extends Model
 {
     protected $fillable = [
         'user_id', 'department_id', 'type_id', 'subject', 'amount', 'schedule', 'body', 'related_numbers',
+        'amount_table', 'tsubo', 'tsubo_price', 'staff', 'contract_date', 'fixed_text',
     ];
 
     protected function casts(): array
@@ -35,6 +38,10 @@ protected function casts(): array
             'decision'           => ApprovalDecision::class,
             'amount'             => 'integer',
             'related_numbers'    => 'array',
+            'amount_table'       => 'array',
+            'tsubo'              => 'decimal:2',
+            'tsubo_price'        => 'integer',
+            'contract_date'      => 'date',
             'round'              => 'integer',
             'number_seq'         => 'integer',
             'number_fiscal_year' => 'integer',
@@ -83,6 +90,21 @@ public function lastRevision(): HasOne
         return $this->hasOne(ApprovalRevision::class, 'request_id')->latestOfMany('round');
     }
 
+    /**
+     * 最後に提出した控え（今の回の控え）。申請者以外に見せる中身・メールの件名・差戻しの取り消しの比べ・提出の条件が読む
+     * （**1 つの申請の控えはここから引く**。段階5 設計書 §8。一度も提出していない下書きには無い）。
+     * `lastRevision` を先に読んでいれば（台帳）それを使い、問い合わせない。
+     * ⚠ 問い合わせの中で控えを当てる所（台帳の絞り込み・関連する決裁No の候補）は、SQL で同じ条件（`round` が同じ）を書く
+     */
+    public function submittedRevision(): ?ApprovalRevision
+    {
+        $revision = $this->relationLoaded('lastRevision')
+            ? $this->lastRevision
+            : ApprovalRevision::where('request_id', $this->id)->where('round', $this->round)->first();
+
+        return $revision !== null && $revision->round === $this->round ? $revision : null;
+    }
+
     public function histories(): HasMany
     {
         return $this->hasMany(ApprovalHistory::class, 'request_id')->orderBy('id');
```

控えを読む所を `submittedRevision()` に替える（中身は同じ。`LedgerRow` は先に読んだ `lastRevision` を使い、今の回の控えだけを使う形になる）:

`app/Support/Approval/RequestContent.php`（変更）

```diff
--- a/app/Support/Approval/RequestContent.php
+++ b/app/Support/Approval/RequestContent.php
@@ -6,6 +6,7 @@
 use App\Models\ApprovalRequest;
 use App\Models\ApprovalRevision;
 use App\Models\User;
+use Illuminate\Database\Eloquent\ModelNotFoundException;
 use Illuminate\Support\Collection;
 
 /**
@@ -51,7 +52,7 @@ public static function for(User $viewer, ApprovalRequest $request): self
 
         // 控えは提出と同じトランザクションで作るので、提出した申請には必ずある（一度も提出していない下書きは
         // 申請者しか見られない）。見つからなければ今の中身へ落とさずに 404 にする（分からないときは見せない）
-        $revision = ApprovalRevision::where('request_id', $request->id)->where('round', $request->round)->firstOrFail();
+        $revision = $request->submittedRevision() ?? throw (new ModelNotFoundException())->setModel(ApprovalRevision::class);
 
         return self::fromRevision($request, $revision);
     }
```

`app/Support/Approval/UndoTarget.php`（変更）

```diff
--- a/app/Support/Approval/UndoTarget.php
+++ b/app/Support/Approval/UndoTarget.php
@@ -4,7 +4,6 @@
 
 use App\Models\ApprovalHistory;
 use App\Models\ApprovalRequest;
-use App\Models\ApprovalRevision;
 
 /**
  * 押し間違いの取り消しで、次に取り消す操作（要件 4.7・設計書 §5.14・D24）。
@@ -67,7 +66,7 @@ public static function find(ApprovalRequest $request): ?ApprovalHistory
      */
     public static function editedSinceReturn(ApprovalRequest $request): bool
     {
-        $revision = ApprovalRevision::where('request_id', $request->id)->where('round', $request->round)->first();
+        $revision = $request->submittedRevision();
 
         if ($revision === null) {
             return true;
```

`app/Support/Approval/Notifier.php`（変更）

```diff
--- a/app/Support/Approval/Notifier.php
+++ b/app/Support/Approval/Notifier.php
@@ -14,7 +14,6 @@
 use App\Models\ApprovalMailDomain;
 use App\Models\ApprovalNotice;
 use App\Models\ApprovalRequest;
-use App\Models\ApprovalRevision;
 use App\Models\ApprovalSetting;
 use App\Models\ApprovalStep;
 use App\Models\User;
@@ -254,7 +253,7 @@ private function receives(User $user, ApprovalRequest $request, Notice $notice):
      */
     private static function context(ApprovalRequest $request): array
     {
-        $snapshot = ApprovalRevision::where('request_id', $request->id)->where('round', $request->round)->first()?->snapshot ?? [];
+        $snapshot = $request->submittedRevision()?->snapshot ?? [];
 
         return [
             'subject'    => ApprovalMailable::oneLine($snapshot['subject'] ?? $request->subject),
```

`app/Support/Approval/LedgerRow.php`（変更）

```diff
--- a/app/Support/Approval/LedgerRow.php
+++ b/app/Support/Approval/LedgerRow.php
@@ -46,7 +46,7 @@ private function __construct(
     public static function for(User $viewer, ApprovalRequest $request): self
     {
         $own      = $request->user_id === $viewer->id;
-        $snapshot = $own ? [] : ($request->lastRevision?->snapshot ?? []);
+        $snapshot = $own ? [] : ($request->submittedRevision()?->snapshot ?? []);
         $steps    = $request->currentSteps()->keyBy(fn (ApprovalStep $s) => $s->kind->value);
         $review    = self::done($steps->get(ApprovalStepKind::Review->value));
         $president = self::done($steps->get(ApprovalStepKind::President->value));
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0002-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（控えを読む所を替えたので、決裁のテストをすべて流す）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval tests/Unit/Approval 2>&1 | tail -3
```

Expected: `OK`（赤なし。詳細・差戻しの取り消し・メール・台帳のテストは変えずに通る＝振る舞いが同じ）

- [ ] **Step 5: 全件を流す**

Expected: `OK (3942 tests, 37515 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add database/sql/2026-10-07-approval-phase5a.sql database/migrations/2026_10_07_000001_add_approval_phase5a_columns.php app/Enums/ApprovalBodyForm.php app/Models/ApprovalType.php app/Models/ApprovalRequest.php app/Support/Approval/RequestContent.php app/Support/Approval/UndoTarget.php app/Support/Approval/Notifier.php app/Support/Approval/LedgerRow.php tests/Feature/Approval/Phase5/Phase5aTablesTest.php tests/Feature/Approval/Phase2/Phase2TablesTest.php tests/Feature/Approval/Phase2/Phase2ModelsTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 明細表と追加の欄・使える部門の表を足し控えの引き方を 1 つにする

approval_types に本文の形・明細表の行・件名の決まり文句・追加の欄の
使う／使わない・定型文、approval_requests に明細表・坪数・坪単価・担当者・
契約予定日・定型文の列を、本番の SQL とテストの migration の対で足し、
使える部門の表 approval_type_department を作る（段階5 設計書 §5.3・D5）。
最後に提出した控えは ApprovalRequest::submittedRevision() から引く
（詳細・差戻しの取り消し・メール・台帳。設計書 §8）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 3: 明細表の計算と形・追加の欄・数の入力のそろえ方の部品

明細表の形と計算（`AmountTable`）・追加の入力欄（`RequestExtras`）・数の入力のそろえ方と前後の空白の落とし方（`FormInput`）を、画面・保存・控え・PDF・Excel が共有する部品として足す。粗利率は整数で計算する（設計書 §5.5〜§5.8・D2・D3・D15・D16・D19・§0.3）。

**Files:**
- Create: `app/Support/Approval/AmountTable.php`・`app/Support/Approval/RequestExtras.php`
- Modify: `app/Support/Approval/FormInput.php`
- Test: Create `tests/Unit/Approval/AmountTableTest.php`・`tests/Unit/Approval/RequestExtrasTest.php`／Modify `tests/Unit/Approval/FormInputTest.php`

**Interfaces:**
- Consumes: なし（Task 2 の列は使わない純粋な部品。`RequestExtras::usedBy()`・`valuesOf()` は `ApprovalType`・`ApprovalRequest` のモデルを受け取る）
- Produces: `AmountTable::SECTIONS`（`['upper', 'lower']`）・`LAYOUT_MAX_ROWS`（20）・`MAX_ROWS`（30）・`NAME_MAX`（30）・`AMOUNT_MAX`（999,999,999,999）・`layout(bool $subtotal, array $upper, array $lower): array`・`forForm(array $layout, ?array $table): array`・`cleanInput(mixed $input): array`・`fromInput(array $layout, array $clean): array`・`totals(array $table): array{subtotal: ?array, total: array}`（各 `{sale, cost, profit, rate}`）・`amount(array $table): ?int`・`shownRows(array $table, string $section): list<array>`（行に `profit`・`rate` を添える）・`hasUnnamedAmount(array $table): bool`・`rate(int $sale, int $profit): ?float`・`rateLabel(?float $rate): string`・`yen(?int $value): string`・`rowsOf(?array $table, string $section): list<array{name: ?string, fixed: bool, sale: ?int, cost: ?int}>`
- Produces: `RequestExtras::FIELDS`（`tsubo`・`tsubo_price`・`staff`・`contract_date` => 名前）・`REQUIRED`・`TSUBO_MAX`・`TSUBO_PRICE_MAX`・`STAFF_MAX`・`usedBy(?ApprovalType): list<string>`・`valuesOf(ApprovalRequest, ?ApprovalType): array`・`ordered(mixed $extras): array`・`display(string $key, mixed $value): ?string`
- Produces: `FormInput::YEN`（`['円', '¥', '￥']`）・`FormInput::trim(?string $value): string`・`FormInput::digits(mixed $value, list<string> $units = []): mixed`

**差分の大きさ:** 6 ファイル・+789 / −0 行（差分のファイル `0003-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Approval/AmountTableTest.php`（新規）

```php
<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\AmountTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 金額の明細表の形と計算（要件 5.5.4・段階5 設計書 §5.5〜§5.8・D2・D3・D15・D16） */
class AmountTableTest extends TestCase
{
    /** 要件 5.5.7 の請負新築工事契約の設定（前半: 工事請負金額・自由行・紹介料／「計」あり／後半: 土地契約金額・自由行） */
    private const LAYOUT = ['subtotal' => true, 'upper' => ['工事請負金額', null, '紹介料'], 'lower' => ['土地契約金額', null]];

    private static function row(?string $name, bool $fixed, ?int $sale, ?int $cost): array
    {
        return ['name' => $name, 'fixed' => $fixed, 'sale' => $sale, 'cost' => $cost];
    }

    /** 9/17 に利用者が見た申請の画面の見本の数 */
    private static function sample(bool $subtotal = true): array
    {
        return [
            'subtotal' => $subtotal,
            'upper'    => [
                self::row('工事請負金額', true, 28500000, 22000000),
                self::row('オプション工事', false, 1200000, 850000),
                self::row('紹介料', true, 0, 300000),
            ],
            'lower' => [
                self::row('土地契約金額', true, 12000000, 10500000),
                self::row(null, false, null, null),
            ],
        ];
    }

    public function test_the_layout_trims_the_names_and_an_empty_name_is_a_free_row(): void
    {
        $this->assertSame(
            ['subtotal' => true, 'upper' => ['工事請負金額', null, '紹介料'], 'lower' => [null]],
            AmountTable::layout(true, [' 工事請負金額　', '', '紹介料'], ['　'])
        );
        $this->assertSame(['subtotal' => false, 'upper' => [], 'lower' => []], AmountTable::layout(false, [], []));
    }

    public function test_the_subtotal_and_the_total_follow_the_paper_form(): void
    {
        $totals = AmountTable::totals(self::sample());

        // 計＝前半の合計・合計金額＝計＋後半。粗利益金額＝販売金額−工事原価・粗利率は小数第 1 位
        $this->assertSame(['sale' => 29700000, 'cost' => 23150000, 'profit' => 6550000, 'rate' => 22.1], $totals['subtotal']);
        $this->assertSame(['sale' => 41700000, 'cost' => 33650000, 'profit' => 8050000, 'rate' => 19.3], $totals['total']);

        // 「計」を使わない種類は計が無い
        $this->assertNull(AmountTable::totals(self::sample(false))['subtotal']);
        $this->assertSame(41700000, AmountTable::totals(self::sample(false))['total']['sale']);
    }

    public function test_the_rate_of_a_zero_sale_is_a_dash_and_minus_amounts_are_added(): void
    {
        $this->assertNull(AmountTable::rate(0, -300000));
        $this->assertSame('—', AmountTable::rateLabel(null));
        $this->assertSame('22.1%', AmountTable::rateLabel(22.1));
        $this->assertSame('-5.0%', AmountTable::rateLabel(-5.0));
        $this->assertSame('-5000.0%', AmountTable::rateLabel(AmountTable::rate(1, -50)), '3 桁の区切りは付けない（画面の JS と同じ）');

        // 値引きの行（マイナス。D3）も足す
        $table = ['subtotal' => false, 'upper' => [self::row('工事請負金額', true, 10000000, 8000000), self::row('値引き', false, -300000, null)], 'lower' => []];
        $this->assertSame(['sale' => 9700000, 'cost' => 8000000, 'profit' => 1700000, 'rate' => 17.5], AmountTable::totals($table)['total']);
        $this->assertSame('-300,000円', AmountTable::yen(-300000));
        $this->assertSame('', AmountTable::yen(null));
    }

    /**
     * 粗利率の四捨五入の境目（点検の I-1。浮動小数の割り算では 63.75% が 63.7499…% になり、画面の JS は 63.7・PHP 8.3 は 63.8・
     * PHP 8.4 からは 63.7 と答えが割れていた）。整数で計算し、0 から遠い方へ丸める
     *
     * @return array<string, array{int, int, float}>
     */
    public static function rateEdges(): array
    {
        return [
            '63.75% は 63.8'              => [8_000_000, 5_100_000, 63.8],
            '-63.75% は -63.8'            => [8_000_000, -5_100_000, -63.8],
            '12.25% は 12.3'              => [400, 49, 12.3],
            '12.2499…% は 12.2'           => [400_000_001, 49_000_000, 12.2],
            '3 分の 1 は 33.3'            => [3, 1, 33.3],
            '3 分の 2 は 66.7'            => [3, 2, 66.7],
            '販売金額もマイナス'          => [-1_000, -300, 30.0],
            '販売金額だけマイナス'        => [-1_000, 300, -30.0],
            '粗利益 0'                    => [-1_000, 0, 0.0],
            '12 桁どうし'                 => [999_999_999_999, 999_999_999_999, 100.0],
            '粗利益がいちばん大きいとき'  => [999_999_999_999, 1_999_999_999_998, 200.0],
        ];
    }

    #[DataProvider('rateEdges')]
    public function test_the_rate_is_rounded_away_from_zero_with_integers(int $sale, int $profit, float $rate): void
    {
        $this->assertSame($rate, AmountTable::rate($sale, $profit));
    }

    public function test_the_amount_is_the_total_sale_only_when_it_is_more_than_zero(): void
    {
        $this->assertSame(41700000, AmountTable::amount(self::sample()));

        $zero = ['subtotal' => false, 'upper' => [self::row('工事請負金額', true, null, 5000)], 'lower' => []];
        $this->assertNull(AmountTable::amount($zero));

        $minus = ['subtotal' => false, 'upper' => [self::row('値引き', false, -1, null)], 'lower' => []];
        $this->assertNull(AmountTable::amount($minus));
        $this->assertNull(AmountTable::amount([]));
    }

    public function test_the_shown_rows_hide_empty_free_rows_and_carry_the_profit_and_the_rate(): void
    {
        $upper = AmountTable::shownRows(self::sample(), 'upper');
        $lower = AmountTable::shownRows(self::sample(), 'lower');

        $this->assertSame(['工事請負金額', 'オプション工事', '紹介料'], array_column($upper, 'name'));
        $this->assertSame([6500000, 350000, -300000], array_column($upper, 'profit'));
        $this->assertSame([22.8, 29.2, null], array_column($upper, 'rate'));
        $this->assertSame(['土地契約金額'], array_column($lower, 'name'), '名前も金額も無い自由行は出さない');

        // 名前を設定した行は金額が空でも出す（粗利益金額も粗利率も無い）
        $empty = AmountTable::shownRows(['upper' => [self::row('紹介料', true, null, null)]], 'upper');
        $this->assertSame([['name' => '紹介料', 'fixed' => true, 'sale' => null, 'cost' => null, 'profit' => null, 'rate' => null]], $empty);

        // 名前だけの自由行・金額だけの自由行は出す
        $this->assertCount(2, AmountTable::shownRows(['upper' => [self::row('追加工事', false, null, null), self::row(null, false, null, 1)]], 'upper'));
    }

    public function test_an_amount_without_a_name_is_found(): void
    {
        $this->assertFalse(AmountTable::hasUnnamedAmount(self::sample()));
        $this->assertTrue(AmountTable::hasUnnamedAmount(['lower' => [self::row(null, false, null, 1)]]));
        $this->assertTrue(AmountTable::hasUnnamedAmount(['upper' => [self::row(null, false, 0, null)]]), '0 円も金額');
        $this->assertFalse(AmountTable::hasUnnamedAmount(['upper' => [self::row(null, false, null, null)]]));
    }

    public function test_the_input_is_cleaned_before_the_check(): void
    {
        $clean = AmountTable::cleanInput([
            'upper' => [
                ['fixed' => '工事請負金額', 'sale' => '28,500,000円', 'cost' => '２２，０００，０００'],
                ['name' => '値引き', 'sale' => '−300,000', 'cost' => ''],
                'こわれた行',
                ['name' => '文字', 'sale' => 'abc', 'fixed' => ['x']],
            ],
            'lower' => 'こわれた側',
        ]);

        $this->assertSame([
            ['name' => null, 'fixed' => '工事請負金額', 'sale' => '28500000', 'cost' => '22000000'],
            ['name' => '値引き', 'fixed' => null, 'sale' => '-300000', 'cost' => null],
            ['name' => '文字', 'fixed' => null, 'sale' => 'abc', 'cost' => null],
        ], $clean['upper']);
        $this->assertSame([], $clean['lower']);
        $this->assertSame(['upper' => [], 'lower' => []], AmountTable::cleanInput('こわれた表'));
    }

    public function test_the_server_decides_which_rows_have_a_fixed_name(): void
    {
        $table = AmountTable::fromInput(self::LAYOUT, AmountTable::cleanInput([
            'upper' => [
                ['fixed' => '工事請負金額', 'sale' => '1000', 'cost' => '600'],
                ['name' => ' オプション工事 ', 'sale' => '200', 'cost' => '100'],
                ['fixed' => '工事請負金額', 'name' => '二度目', 'sale' => '5', 'cost' => null],
                ['fixed' => '設定に無い名前', 'name' => '自由行', 'sale' => '7', 'cost' => null],
            ],
            'lower' => [['name' => '', 'sale' => null, 'cost' => null]],
        ]));

        $this->assertTrue($table['subtotal']);
        $this->assertSame([
            self::row('工事請負金額', true, 1000, 600),
            self::row('オプション工事', false, 200, 100),
            self::row('二度目', false, 5, null),           // 同じ名前を設定した行は 1 回だけ
            self::row('自由行', false, 7, null),           // 設定に無い名前は自由行
            self::row('紹介料', true, null, null),         // 送られてこなかった名前を設定した行は後ろに空で足す
        ], $table['upper']);
        $this->assertSame([self::row(null, false, null, null), self::row('土地契約金額', true, null, null)], $table['lower']);
    }

    public function test_a_new_request_shows_the_rows_of_the_layout(): void
    {
        $this->assertSame([
            'subtotal' => true,
            'upper'    => [self::row('工事請負金額', true, null, null), self::row(null, false, null, null), self::row('紹介料', true, null, null)],
            'lower'    => [self::row('土地契約金額', true, null, null), self::row(null, false, null, null)],
        ], AmountTable::forForm(self::LAYOUT, null));
    }

    public function test_the_form_keeps_what_was_written_when_the_layout_changed(): void
    {
        $stored = [
            'subtotal' => true,
            'upper'    => [
                self::row('工事請負金額', true, 1000, 600),
                self::row('外構工事', false, 300, 200),
                self::row('紹介料', true, 50, null),
                self::row('追加の行', false, 10, null),
            ],
            'lower' => [self::row('土地契約金額', true, null, null)],
        ];
        // 管理者が「紹介料」を「紹介手数料」に変え、前半の自由行を 1 つ減らし、「計」をやめた
        $layout = ['subtotal' => false, 'upper' => ['工事請負金額', '紹介手数料', null], 'lower' => ['土地契約金額']];

        $this->assertSame([
            'subtotal' => false,
            'upper'    => [
                self::row('工事請負金額', true, 1000, 600),
                self::row('紹介手数料', true, null, null),
                self::row('外構工事', false, 300, 200),     // 自由行の位置に、申請の自由行を順に当てる
                self::row('紹介料', false, 50, null),       // 名前が設定から消えた行は、金額があれば自由行として残す（D16）
                self::row('追加の行', false, 10, null),     // 残った自由行は申請の並びのまま後ろへ
            ],
            'lower' => [self::row('土地契約金額', true, null, null)],
        ], AmountTable::forForm($layout, $stored));

        // 名前が設定から消えた行に金額が無ければ残さない
        $renamed = AmountTable::forForm(['subtotal' => false, 'upper' => ['新しい名前'], 'lower' => []], ['upper' => [self::row('古い名前', true, null, null)]]);
        $this->assertSame([self::row('新しい名前', true, null, null)], $renamed['upper']);
    }

    public function test_broken_rows_are_ignored(): void
    {
        $this->assertSame([], AmountTable::rowsOf(null, 'upper'));
        $this->assertSame([], AmountTable::rowsOf(['upper' => 'こわれた'], 'upper'));
        $this->assertSame([self::row(null, false, 5, null)], AmountTable::rowsOf(['upper' => ['こわれた行', ['sale' => '5']]], 'upper'));
    }
}
```

`tests/Unit/Approval/RequestExtrasTest.php`（新規）

```php
<?php

namespace Tests\Unit\Approval;

use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Support\Approval\RequestExtras;
use Tests\TestCase;

/** 追加の入力欄（坪数・坪単価・担当者・契約予定日。要件 5.5.5・段階5 設計書 D2・D12） */
class RequestExtrasTest extends TestCase
{
    public function test_a_type_uses_only_the_fields_it_turned_on_in_the_fixed_order(): void
    {
        $this->assertSame([], RequestExtras::usedBy(null));
        $this->assertSame([], RequestExtras::usedBy(new ApprovalType()));
        $this->assertSame(
            ['tsubo', 'staff', 'contract_date'],
            RequestExtras::usedBy(new ApprovalType(['uses_contract_date' => true, 'uses_tsubo' => true, 'uses_staff' => true]))
        );
        $this->assertSame(['staff', 'contract_date'], RequestExtras::REQUIRED);
    }

    public function test_the_values_of_a_request_are_those_of_the_used_fields(): void
    {
        $type    = new ApprovalType(['uses_tsubo' => true, 'uses_tsubo_price' => true, 'uses_contract_date' => true]);
        $request = new ApprovalRequest(['tsubo' => '38.5', 'tsubo_price' => 1083000, 'staff' => '使わない欄', 'contract_date' => '2026-10-20']);

        $this->assertSame(['tsubo' => '38.50', 'tsubo_price' => 1083000, 'contract_date' => '2026-10-20'], RequestExtras::valuesOf($request, $type));
        $this->assertSame([], RequestExtras::valuesOf($request, null));
    }

    public function test_the_values_from_a_snapshot_follow_the_fixed_order(): void
    {
        // MySQL は JSON のキーを並べ替えて返す（キーの長さの順）
        $this->assertSame(
            ['tsubo' => '38.50', 'staff' => '佐藤', 'contract_date' => null],
            RequestExtras::ordered(['contract_date' => null, 'staff' => '佐藤', 'tsubo' => '38.50', 'unknown' => 'x'])
        );
        $this->assertSame([], RequestExtras::ordered(null));
    }

    public function test_the_display_of_each_field(): void
    {
        $this->assertSame('38.5坪', RequestExtras::display('tsubo', '38.50'));
        $this->assertSame('40坪', RequestExtras::display('tsubo', '40.00'));
        $this->assertSame('1,234.25坪', RequestExtras::display('tsubo', '1234.25'));
        $this->assertSame('1,083,000円', RequestExtras::display('tsubo_price', 1083000));
        $this->assertSame('佐藤 健一', RequestExtras::display('staff', '佐藤 健一'));
        $this->assertSame('2026/10/20', RequestExtras::display('contract_date', '2026-10-20'));
        $this->assertNull(RequestExtras::display('staff', ''));
        $this->assertNull(RequestExtras::display('contract_date', null));
    }
}
```

`tests/Unit/Approval/FormInputTest.php`（変更）

```diff
--- a/tests/Unit/Approval/FormInputTest.php
+++ b/tests/Unit/Approval/FormInputTest.php
@@ -56,6 +56,46 @@ public function test_lock_version_is_read_only_in_the_shape_of_a_whole_number(mi
         $this->assertSame($expected, FormInput::lockVersion(Request::create('/', 'POST', ['lock_version' => $sent])));
     }
 
+    /** @return array<string, array{mixed, list<string>, mixed}> */
+    public static function numbers(): array
+    {
+        return [
+            'カンマ'                        => ['1,234', [], '1234'],
+            '全角の数とカンマ'              => ['２２，０００，０００', [], '22000000'],
+            '円'                            => ['300,000円', FormInput::YEN, '300000'],
+            '¥ と全角の ￥'                 => ['¥1,000', FormInput::YEN, '1000'],
+            '全角の ￥'                     => ['￥1,000', FormInput::YEN, '1000'],
+            'マイナスの記号'                => ['−300,000', [], '-300000'],
+            '全角のマイナス'                => ['－５', [], '-5'],
+            '先頭の +'                      => ['+1000', [], '1000'],
+            '全角の ＋'                     => ['＋1000', [], '1000'],
+            '先頭の 0'                      => ['0100', [], '100'],
+            '0 だけ'                        => ['000', [], '0'],
+            'マイナスの 0'                  => ['-0', [], '0'],
+            'タブと空白'                    => ["1\t000 ", [], '1000'],
+            '小数はそのまま'                => ['38.50坪', ['坪'], '38.50'],
+            '数でない文字はそのまま'        => ['abc', [], 'abc'],
+            '空'                            => ['', [], null],
+            '空白だけ'                      => ['　 ', [], null],
+            '文字でない値はそのまま'        => [['1'], [], ['1']],
+            'null'                          => [null, [], null],
+        ];
+    }
+
+    /** 数の入力のそろえ方（段階5。金額・明細表の金額・坪数・坪単価が共有する。画面の JS の parseAmount と同じ数になる） */
+    #[DataProvider('numbers')]
+    public function test_numbers_are_put_in_shape_before_the_validation(mixed $sent, array $units, mixed $expected): void
+    {
+        $this->assertSame($expected, FormInput::digits($sent, $units));
+    }
+
+    public function test_the_spaces_around_are_trimmed_including_the_wide_ones(): void
+    {
+        $this->assertSame('山田 太郎', FormInput::trim("　山田 太郎 \n"));
+        $this->assertSame('', FormInput::trim('　 '));
+        $this->assertSame('', FormInput::trim(null));
+    }
+
     public function test_a_missing_lock_version_falls_back_to_the_given_value(): void
     {
         $this->assertSame(-1, FormInput::lockVersion(Request::create('/', 'POST', [])), '既定は必ず断る -1');
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0003-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Unit/Approval/AmountTableTest.php tests/Unit/Approval/FormInputTest.php tests/Unit/Approval/RequestExtrasTest.php
```

Expected: `ERRORS!` `Tests: 42, Assertions: 20, Errors: 28.`

- `FormInputTest::test_numbers_are_put_in_shape_before_the_validation` — `The data provider specified for Tests\Unit\Approval\FormInputTest::test_numbers_are_put_in_shape_before_the_validation is invalid`
- `AmountTableTest::test_the_layout_trims_the_names_and_an_empty_name_is_a_free_row` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_subtotal_and_the_total_follow_the_paper_form` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_rate_of_a_zero_sale_is_a_dash_and_minus_amounts_are_added` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_rate_is_rounded_away_from_zero_with_integers` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_rate_is_rounded_away_from_zero_with_integers` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_rate_is_rounded_away_from_zero_with_integers` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_rate_is_rounded_away_from_zero_with_integers` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_rate_is_rounded_away_from_zero_with_integers` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_rate_is_rounded_away_from_zero_with_integers` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_rate_is_rounded_away_from_zero_with_integers` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_rate_is_rounded_away_from_zero_with_integers` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_rate_is_rounded_away_from_zero_with_integers` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_rate_is_rounded_away_from_zero_with_integers` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_rate_is_rounded_away_from_zero_with_integers` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_amount_is_the_total_sale_only_when_it_is_more_than_zero` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_shown_rows_hide_empty_free_rows_and_carry_the_profit_and_the_rate` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_an_amount_without_a_name_is_found` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_input_is_cleaned_before_the_check` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_server_decides_which_rows_have_a_fixed_name` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_a_new_request_shows_the_rows_of_the_layout` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_the_form_keeps_what_was_written_when_the_layout_changed` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `AmountTableTest::test_broken_rows_are_ignored` — `Error: Class "App\Support\Approval\AmountTable" not found`
- `FormInputTest::test_the_spaces_around_are_trimmed_including_the_wide_ones` — `Error: Call to undefined method App\Support\Approval\FormInput::trim()`
- `RequestExtrasTest::test_a_type_uses_only_the_fields_it_turned_on_in_the_fixed_order` — `Error: Class "App\Support\Approval\RequestExtras" not found`
- `RequestExtrasTest::test_the_values_of_a_request_are_those_of_the_used_fields` — `Error: Class "App\Support\Approval\RequestExtras" not found`
- `RequestExtrasTest::test_the_values_from_a_snapshot_follow_the_fixed_order` — `Error: Class "App\Support\Approval\RequestExtras" not found`
- `RequestExtrasTest::test_the_display_of_each_field` — `Error: Class "App\Support\Approval\RequestExtras" not found`

- [ ] **Step 3: 部品を足す**

`app/Support/Approval/AmountTable.php`（新規）

```php
<?php

namespace App\Support\Approval;

/**
 * 金額の明細表（要件 5.5.4・段階5 設計書 §5.4〜§5.8・D2・D3・D5・D14〜D16）。**形と計算はここ 1 か所**
 * （申請の画面・保存・控え・詳細・変更点・PDF・Excel が共有する。画面の JS の計算も同じ式）。
 *
 * - 種類の行の設定（layout）: `['subtotal' => bool, 'upper' => list<?string>, 'lower' => list<?string>]`。名前が null の行は自由行
 * - 申請の明細表（table）: `['subtotal' => bool, 'upper' => list<Row>, 'lower' => list<Row>]`。
 *   Row は `['name' => ?string, 'fixed' => bool, 'sale' => ?int, 'cost' => ?int]`（fixed は種類に名前を設定した行＝名前は種類のもの・消せない）
 * - 「計」＝前半の行の合計（`subtotal` の種類だけ）、「合計金額」＝前半と後半の行の合計。粗利益金額＝販売金額−工事原価、
 *   粗利率＝粗利益金額÷販売金額×100（小数第 1 位）。販売金額が 0 なら粗利率は無い（画面と PDF は「—」）
 * ⚠ 申請は「計」の有無も自分の明細表に持つ（あとで種類の設定を変えても、提出した申請の見た目は変わらない。D14）
 * ⚠ JSON はオブジェクトのキーを並べ替えて返す（MySQL。RequestSnapshot の注意）ので、行は配列にし、キーで取り出す
 */
final class AmountTable
{
    /** 前半（建物側）・後半（土地側） */
    public const SECTIONS = ['upper', 'lower'];

    /** 種類に設定できる行の数（前半・後半それぞれ） */
    public const LAYOUT_MAX_ROWS = 20;

    /** 申請の行の数の上限（前半・後半それぞれ。種類の行に「行を足す」で足した行を合わせて） */
    public const MAX_ROWS = 30;

    /** 行の名前の文字数 */
    public const NAME_MAX = 30;

    /** 販売金額・工事原価の上限（12 桁。マイナスも同じ桁まで。D3） */
    public const AMOUNT_MAX = 999_999_999_999;

    /**
     * 種類の行の設定をそろえる（⑨ の入力から。名前の前後の空白〈全角を含む〉を落とし、空は自由行）
     *
     * @param array<int, mixed> $upper
     * @param array<int, mixed> $lower
     * @return array{subtotal: bool, upper: list<?string>, lower: list<?string>}
     */
    public static function layout(bool $subtotal, array $upper, array $lower): array
    {
        $names = fn (array $rows): array => array_values(array_map(fn (mixed $name): ?string => self::name($name), $rows));

        return ['subtotal' => $subtotal, 'upper' => $names($upper), 'lower' => $names($lower)];
    }

    /**
     * 申請の画面に出す行（D16）。種類の今の設定の並びに、申請が持つ行を当てる。
     *
     * - 名前を設定した行は、申請の同じ名前の名前を設定した行の金額を当てる
     * - 種類の自由行の位置には、申請の自由行を順に当てる（足りなければ空の自由行。要件 5.5.4「最初から空欄で出す」）
     * - 残った申請の自由行は、その側の後ろへ。種類から名前が消えた行は、金額が入っていれば自由行として残す（黙って消さない）
     *
     * @param array{subtotal?: bool, upper?: list<?string>, lower?: list<?string>} $layout
     * @param array<string, mixed>|null $table 申請の明細表（無ければ種類の設定のとおりの空の表）
     * @return array{subtotal: bool, upper: list<array{name: ?string, fixed: bool, sale: ?int, cost: ?int}>, lower: list<array{name: ?string, fixed: bool, sale: ?int, cost: ?int}>}
     */
    public static function forForm(array $layout, ?array $table): array
    {
        $result = ['subtotal' => (bool) ($layout['subtotal'] ?? false)];

        foreach (self::SECTIONS as $section) {
            $names = $layout[$section] ?? [];
            $fixed = [];
            $free  = [];
            foreach (self::rowsOf($table, $section) as $row) {
                if ($row['fixed'] && in_array($row['name'], $names, true) && ! isset($fixed[$row['name']])) {
                    $fixed[$row['name']] = $row;
                } elseif (! $row['fixed'] || $row['sale'] !== null || $row['cost'] !== null) {
                    $free[] = ['name' => $row['name'], 'fixed' => false, 'sale' => $row['sale'], 'cost' => $row['cost']];
                }
            }

            $rows = [];
            foreach ($names as $name) {
                $rows[] = $name !== null
                    ? ['name' => $name, 'fixed' => true, 'sale' => $fixed[$name]['sale'] ?? null, 'cost' => $fixed[$name]['cost'] ?? null]
                    : (array_shift($free) ?? self::emptyRow());
            }
            $result[$section] = array_merge($rows, $free);
        }

        return $result;
    }

    /**
     * 画面から送られた明細表の形をそろえる（入力の検査の前）。
     *
     * `amount_table[upper][0][name]`・`[fixed]`（名前を設定した行はその名前）・`[sale]`・`[cost]` を読み、配列でない値は捨て、
     * 金額は数字の形にそろえる（FormInput::digits。全角・カンマ・「円」・空白を落とし、マイナスの記号を `-` に）。数でない値はそのまま残して検査で断る
     *
     * @return array{upper: list<array{name: mixed, fixed: ?string, sale: mixed, cost: mixed}>, lower: list<array{name: mixed, fixed: ?string, sale: mixed, cost: mixed}>}
     */
    public static function cleanInput(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $clean = [];

        foreach (self::SECTIONS as $section) {
            $rows = is_array($input[$section] ?? null) ? array_values($input[$section]) : [];
            $clean[$section] = array_values(array_map(fn (array $row): array => [
                'name'  => $row['name'] ?? null,
                'fixed' => is_string($row['fixed'] ?? null) ? $row['fixed'] : null,
                'sale'  => FormInput::digits($row['sale'] ?? null, FormInput::YEN),
                'cost'  => FormInput::digits($row['cost'] ?? null, FormInput::YEN),
            ], array_filter($rows, 'is_array')));
        }

        return $clean;
    }

    /**
     * 検査を通った入力から、保存する明細表を作る（どの行が名前を設定した行かはサーバーが種類の設定で決める。画面を信用しない）。
     *
     * - `fixed` に種類の名前を設定した行の名前が来た行（同じ名前は 1 回だけ）→ 名前を設定した行（名前は種類のもの）
     * - ほかの行 → 自由行（名前は打ったもの）
     * - 送られてこなかった名前を設定した行は、その側の後ろに空で足す（古い画面から送ったときなど）
     *
     * @param array{subtotal?: bool, upper?: list<?string>, lower?: list<?string>} $layout
     * @param array{upper?: list<array<string, mixed>>, lower?: list<array<string, mixed>>} $clean cleanInput() の形（検査済み）
     * @return array{subtotal: bool, upper: list<array{name: ?string, fixed: bool, sale: ?int, cost: ?int}>, lower: list<array{name: ?string, fixed: bool, sale: ?int, cost: ?int}>}
     */
    public static function fromInput(array $layout, array $clean): array
    {
        $table = ['subtotal' => (bool) ($layout['subtotal'] ?? false)];

        foreach (self::SECTIONS as $section) {
            $remaining = array_values(array_filter($layout[$section] ?? [], fn (?string $name) => $name !== null));
            $rows      = [];

            foreach ($clean[$section] ?? [] as $row) {
                $at   = $row['fixed'] === null ? false : array_search($row['fixed'], $remaining, true);
                $sale = self::intOrNull($row['sale'] ?? null);
                $cost = self::intOrNull($row['cost'] ?? null);

                if ($at !== false) {
                    unset($remaining[$at]);
                    $rows[] = ['name' => $row['fixed'], 'fixed' => true, 'sale' => $sale, 'cost' => $cost];
                } else {
                    $rows[] = ['name' => self::name($row['name'] ?? null), 'fixed' => false, 'sale' => $sale, 'cost' => $cost];
                }
            }

            foreach ($remaining as $name) {
                $rows[] = ['name' => $name, 'fixed' => true, 'sale' => null, 'cost' => null];
            }

            $table[$section] = $rows;
        }

        return $table;
    }

    /**
     * 「計」（前半の合計。使う種類だけ）と「合計金額」（前半と後半の合計）。空の金額は 0 として足す
     *
     * @param array<string, mixed> $table
     * @return array{subtotal: ?array{sale: int, cost: int, profit: int, rate: ?float}, total: array{sale: int, cost: int, profit: int, rate: ?float}}
     */
    public static function totals(array $table): array
    {
        [$upperSale, $upperCost] = self::sums(self::rowsOf($table, 'upper'));
        [$lowerSale, $lowerCost] = self::sums(self::rowsOf($table, 'lower'));

        return [
            'subtotal' => ($table['subtotal'] ?? false) ? self::line($upperSale, $upperCost) : null,
            'total'    => self::line($upperSale + $lowerSale, $upperCost + $lowerCost),
        ];
    }

    /**
     * 申請の金額（合計金額の販売金額。0 円より大きいときだけ。D15）。台帳・検索・PDF の「金額」はこれ
     *
     * @param array<string, mixed> $table
     */
    public static function amount(array $table): ?int
    {
        $sale = self::totals($table)['total']['sale'];

        return $sale > 0 ? $sale : null;
    }

    /**
     * 詳細と PDF に出す行（名前も金額も無い自由行は出さない。要件 5.5.4）。粗利益金額と粗利率を添える
     *
     * @param array<string, mixed> $table
     * @return list<array{name: ?string, fixed: bool, sale: ?int, cost: ?int, profit: ?int, rate: ?float}>
     */
    public static function shownRows(array $table, string $section): array
    {
        $rows = [];
        foreach (self::rowsOf($table, $section) as $row) {
            if ($row['fixed'] || $row['name'] !== null || $row['sale'] !== null || $row['cost'] !== null) {
                $rows[] = $row + self::rowLine($row);
            }
        }

        return $rows;
    }

    /**
     * 名前のない行に金額が入っているか（提出できない。D2）
     *
     * @param array<string, mixed> $table
     */
    public static function hasUnnamedAmount(array $table): bool
    {
        foreach (self::SECTIONS as $section) {
            foreach (self::rowsOf($table, $section) as $row) {
                if ($row['name'] === null && ($row['sale'] !== null || $row['cost'] !== null)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 粗利率（％・小数第 1 位。四捨五入は 0 から遠い方へ）。販売金額が 0 なら無い。
     * ⚠ 整数で計算する（画面の JS も同じ式）。浮動小数の割り算は 63.75% を 63.7499…% にし、PHP の `round()` は版で丸め方が違う
     *   （8.3 は 63.8・8.4 からは 63.7。粗利率は保存せず表示のたびに計算するので、版を上げると決裁済みの数が変わる）。
     *   |粗利益| は 2 兆円まで（合計の 12 桁の上限。RequestFields::totalError）なので、2,000 倍しても int に収まる
     */
    public static function rate(int $sale, int $profit): ?float
    {
        if ($sale === 0) {
            return null;
        }
        // |粗利益| ÷ |販売金額| × 1,000（＝粗利率の 10 倍）を四捨五入した整数
        $tenths = intdiv(abs($profit) * 2000 + abs($sale), abs($sale) * 2);

        return (($profit < 0) !== ($sale < 0) ? -$tenths : $tenths) / 10;
    }

    /** 粗利率の表示（「12.3%」。3 桁の区切りは付けない＝画面の JS と同じ。無ければ「—」） */
    public static function rateLabel(?float $rate): string
    {
        return $rate === null ? '—' : number_format($rate, 1, '.', '') . '%';
    }

    /** 金額の表示（「1,200,000円」「-300,000円」。無ければ空。規約: `¥` を付けない） */
    public static function yen(?int $value): string
    {
        return $value === null ? '' : number_format($value) . '円';
    }

    /**
     * 申請の明細表の 1 つの側の行（形の崩れた行は捨てる＝古い控えや手で組んだ JSON でも落ちない）
     *
     * @param array<string, mixed>|null $table
     * @return list<array{name: ?string, fixed: bool, sale: ?int, cost: ?int}>
     */
    public static function rowsOf(?array $table, string $section): array
    {
        $rows = [];
        foreach (is_array($table[$section] ?? null) ? $table[$section] : [] as $row) {
            if (is_array($row)) {
                $rows[] = [
                    'name'  => self::name($row['name'] ?? null),
                    'fixed' => (bool) ($row['fixed'] ?? false),
                    'sale'  => self::intOrNull($row['sale'] ?? null),
                    'cost'  => self::intOrNull($row['cost'] ?? null),
                ];
            }
        }

        return $rows;
    }

    /** @return array{name: null, fixed: false, sale: null, cost: null} */
    private static function emptyRow(): array
    {
        return ['name' => null, 'fixed' => false, 'sale' => null, 'cost' => null];
    }

    /**
     * 1 行の粗利益金額と粗利率（販売金額も工事原価も空なら、どちらも無い）
     *
     * @param array{sale: ?int, cost: ?int} $row
     * @return array{profit: ?int, rate: ?float}
     */
    private static function rowLine(array $row): array
    {
        if ($row['sale'] === null && $row['cost'] === null) {
            return ['profit' => null, 'rate' => null];
        }
        $line = self::line($row['sale'] ?? 0, $row['cost'] ?? 0);

        return ['profit' => $line['profit'], 'rate' => $line['rate']];
    }

    /** @return array{sale: int, cost: int, profit: int, rate: ?float} */
    private static function line(int $sale, int $cost): array
    {
        return ['sale' => $sale, 'cost' => $cost, 'profit' => $sale - $cost, 'rate' => self::rate($sale, $sale - $cost)];
    }

    /**
     * @param list<array{sale: ?int, cost: ?int}> $rows
     * @return array{0: int, 1: int}
     */
    private static function sums(array $rows): array
    {
        $sale = 0;
        $cost = 0;
        foreach ($rows as $row) {
            $sale += $row['sale'] ?? 0;
            $cost += $row['cost'] ?? 0;
        }

        return [$sale, $cost];
    }

    /** 行の名前（前後の空白〈全角を含む〉を落とし、空は null） */
    private static function name(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $name = FormInput::trim($value);

        return $name === '' ? null : $name;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}
```

`app/Support/Approval/RequestExtras.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use Carbon\CarbonInterface;

/**
 * 追加の入力欄（坪数・坪単価・担当者・契約予定日。要件 5.5.5・段階5 設計書 §5.4〜§5.6・D2・D12）。並びと表示はここ 1 か所。
 *
 * - 種類の `uses_{キー}` が「使う」の欄だけを申請の画面に出し、保存する（使わない欄は保存のときに空にする）
 * - 担当者と契約予定日は、使う種類では提出に必須（D2）。坪数・坪単価は空でもよい
 * - 控え（`RequestSnapshot`）には、提出したときに使っていた欄だけを入れる（あとで種類の設定を変えても、提出した申請の見た目は変わらない）
 */
final class RequestExtras
{
    /** キー（申請の列名。種類の列は `uses_` を付けたもの）=> 名前。この並びで出す */
    public const FIELDS = [
        'tsubo'         => '坪数',
        'tsubo_price'   => '坪単価',
        'staff'         => '担当者',
        'contract_date' => '契約予定日',
    ];

    /** 使う種類では提出に必須の欄（D2） */
    public const REQUIRED = ['staff', 'contract_date'];

    /** 坪数の上限（小数第 2 位まで）・坪単価の上限（円・12 桁）・担当者の文字数 */
    public const TSUBO_MAX = '99999.99';

    public const TSUBO_PRICE_MAX = 999_999_999_999;

    public const STAFF_MAX = 50;

    /**
     * この種類が使う欄（並びは FIELDS のとおり）
     *
     * @return list<string>
     */
    public static function usedBy(?ApprovalType $type): array
    {
        if ($type === null) {
            return [];
        }

        return array_values(array_filter(array_keys(self::FIELDS), fn (string $key) => (bool) $type->getAttribute("uses_{$key}")));
    }

    /**
     * 申請の今の値（この種類が使う欄だけ。控えと同じ形＝日付は Y-m-d・坪数は文字・坪単価は数）
     *
     * @return array<string, string|int|null>
     */
    public static function valuesOf(ApprovalRequest $request, ?ApprovalType $type): array
    {
        $values = [];
        foreach (self::usedBy($type) as $key) {
            $value = $request->getAttribute($key);
            $values[$key] = $value instanceof CarbonInterface ? $value->format('Y-m-d') : $value;
        }

        return $values;
    }

    /**
     * 控えの値を、FIELDS の並びにそろえる（MySQL は JSON のキーを並べ替えて返すので、並びに頼らない）
     *
     * @param mixed $extras 控えの `extras`（無ければ空）
     * @return array<string, string|int|null>
     */
    public static function ordered(mixed $extras): array
    {
        $extras = is_array($extras) ? $extras : [];
        $values = [];
        foreach (array_keys(self::FIELDS) as $key) {
            if (array_key_exists($key, $extras)) {
                $values[$key] = $extras[$key];
            }
        }

        return $values;
    }

    /** 表示（坪数「38.5坪」・坪単価「1,083,000円」・担当者はそのまま・契約予定日「2026/10/20」。空なら null） */
    public static function display(string $key, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($key) {
            'tsubo'         => rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.') . '坪',
            'tsubo_price'   => number_format((int) $value) . '円',
            // 日付（Y-m-d の文字。時刻を持たないので日本時間に直さない）
            'contract_date' => str_replace('-', '/', substr((string) $value, 0, 10)),
            default         => (string) $value,
        };
    }
}
```

`app/Support/Approval/FormInput.php`（変更）

```diff
--- a/app/Support/Approval/FormInput.php
+++ b/app/Support/Approval/FormInput.php
@@ -10,6 +10,38 @@
  */
 final class FormInput
 {
+    /** 金額の入力で落とす単位（規約: 表示に `¥` は付けないが、打たれたら落とす） */
+    public const YEN = ['円', '¥', '￥'];
+
+    /** 前後の空白（全角を含む）を落とす（PHP の `trim()` は全角の空白を落とさない） */
+    public static function trim(?string $value): string
+    {
+        return preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', (string) $value) ?? '';
+    }
+
+    /**
+     * 数の入力を検査の前にそろえる（金額・明細表の金額・坪数・坪単価。段階5 設計書 §5.5）。全角→半角・マイナスの記号を `-` に・
+     * カンマと空白と単位を落とす。整数の形（`+12`・`0012`）は先頭の `+` と 0 を落とす（Laravel の integer の検査は「0012」を断るが、
+     * 画面の JS は 12 と読む。画面に見えている数と保存する数を同じにする）。文字でなければそのまま（配列などは検査で断る）。空は null
+     *
+     * @param list<string> $units 落とす単位（self::YEN・「坪」など）
+     */
+    public static function digits(mixed $value, array $units = []): mixed
+    {
+        if (! is_string($value)) {
+            return $value;
+        }
+
+        $text = str_replace(['−', 'ー', '‐', '―', '–', '—'], '-', mb_convert_kana($value, 'as'));
+        $text = preg_replace('/[\s,]+/u', '', str_replace($units, '', $text)) ?? '';
+
+        if (preg_match('/^([+-]?)0*(\d+)$/', $text, $m) === 1) {
+            $text = ($m[1] === '-' && $m[2] !== '0' ? '-' : '') . $m[2];
+        }
+
+        return $text === '' ? null : $text;
+    }
+
     /**
      * 改行を \n にそろえる（Task 19 の B1）。ブラウザの maxlength は改行を 1 文字と数えるが、送るときは \r\n にするので、
      * そろえずに数えると改行の多い入力が上限の手前で断られる。検査の前に呼ぶ（保存する値もそろえた形になる）。
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0003-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK`

- [ ] **Step 5: 全件を流す**

Expected: `OK (3988 tests, 37606 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/AmountTable.php app/Support/Approval/RequestExtras.php app/Support/Approval/FormInput.php tests/Unit/Approval/AmountTableTest.php tests/Unit/Approval/RequestExtrasTest.php tests/Unit/Approval/FormInputTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 明細表の計算と形・追加の欄・数の入力のそろえ方の部品を足す

明細表の形と計算（AmountTable）・坪数などの追加の入力欄（RequestExtras）・
数の入力のそろえ方と前後の空白の落とし方（FormInput）を、画面・保存・控え・
PDF・Excel が共有する部品として足す（段階5 設計書 §5.5〜§5.8）。粗利率は
整数で計算し、浮動小数の丸めや PHP の版で答えが変わらないようにする。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 4: ⑨ に本文の形・明細表の行・決まり文句・追加の欄・定型文・使える部門

申請種類の管理（⑨）の登録・編集の小窓に新しい欄を足し、申請がある種類の本文の形の切り替えを断り、使える部門を保存して設定の記録に残す（設計書 §5.4・D4・D11・D12・D13・§0.4）。

**Files:**
- Create: `resources/views/approvals/admin/_type_body_form.blade.php`・`resources/views/approvals/admin/_type_options.blade.php`
- Modify: `app/Http/Controllers/Approval/TypeController.php`・`resources/views/approvals/admin/types.blade.php`
- Test: Create `tests/Concerns/ComparesJsonColumns.php`・`tests/Feature/Approval/Phase5/TypeSettingsTest.php`／Modify `tests/Feature/Approval/Phase2/TypeManagementTest.php`

**Interfaces:**
- Consumes: Task 2 の `ApprovalBodyForm`・`ApprovalType::departments()`、Task 3 の `AmountTable::layout()`・`LAYOUT_MAX_ROWS`・`NAME_MAX`・`RequestExtras::FIELDS`
- Produces: ⑨ の入力 `body_form`・`layout_upper[]`・`layout_lower[]`・`layout_subtotal`・`subject_suffix`・`uses_tsubo`・`uses_tsubo_price`・`uses_staff`・`uses_contract_date`・`fixed_text`・`department_ids[]`（ルートは今のまま）
- Produces: `Tests\Concerns\ComparesJsonColumns::assertSameIgnoringKeyOrder(array $expected, mixed $actual, string $message = ''): void`（以降のテストが使う）

**差分の大きさ:** 7 ファイル・+643 / −36 行（差分のファイル `0004-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Concerns/ComparesJsonColumns.php`（新規）

```php
<?php

namespace Tests\Concerns;

/**
 * JSON の列の値を、キーの並びに頼らずに比べる。
 *
 * MySQL の JSON 列はキーを並べ替えて返す（短い順・同じ長さは辞書順）。SQLite は保存した順のまま返すので、
 * assertSame でそのまま比べるとテストは SQLite でだけ通る。assertEquals は 0 と null を同じとみなすので使わない。
 */
trait ComparesJsonColumns
{
    /** 連想配列のキーを（入れ子まで）並べ替えてから assertSame で比べる。リスト（0, 1, 2 …）の並びはそのまま比べる */
    protected function assertSameIgnoringKeyOrder(array $expected, mixed $actual, string $message = ''): void
    {
        $this->assertIsArray($actual, $message);
        $this->assertSame($this->sortKeysDeep($expected), $this->sortKeysDeep($actual), $message);
    }

    private function sortKeysDeep(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? $this->sortKeysDeep($item) : $item, $value);
    }
}
```

`tests/Feature/Approval/Phase5/TypeSettingsTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase5;

use App\Enums\ApprovalBodyForm;
use App\Models\ApprovalSettingLog;
use App\Models\ApprovalType;
use App\Models\User;
use App\Support\Approval\BodyTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ComparesJsonColumns;
use Tests\TestCase;

/** ⑨ 申請の種類の段階5 の欄（要件 5.5.1・5.5.7・段階5 設計書 §5.4・D4・D12・D13） */
class TypeSettingsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ComparesJsonColumns;

    /** 要件 5.5.7 の「請負新築工事契約」の設定 */
    private function contractFields(array $w, array $override = []): array
    {
        return array_merge([
            'name'                 => '住宅の契約用（請負新築工事契約）',
            'review_department_id' => (string) $w['reviewDept']->id,
            'sort_order'           => '2',
            'is_active'            => '1',
            'body_form'            => 'table',
            'headings'             => BodyTemplate::DEFAULT,
            'layout_upper'         => ['工事請負金額', '', '紹介料'],
            'layout_lower'         => ['土地契約金額', ''],
            'layout_subtotal'      => '1',
            'subject_suffix'       => '様請負新築工事契約の件',
            'uses_tsubo'           => '1',
            'uses_tsubo_price'     => '1',
            'uses_staff'           => '1',
            'uses_contract_date'   => '1',
            'fixed_text'           => '上記の内容に基づき、販売をおこないます。',
            'department_ids'       => [(string) $w['dept']->id],
        ], $override);
    }

    private function store(User $admin, array $fields)
    {
        return $this->actingAs($admin)->from(route('approvals.admin.types.index'))->post(route('approvals.admin.types.store'), $fields);
    }

    public function test_the_two_settings_of_the_requirements_can_be_made(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $other = $this->approvalDepartment($w['company'], ['name' => 'ミツワ不動産', 'code' => 'M']);

        // 請負新築工事契約（要件 5.5.7 の 1 つ目）
        $this->store($admin, $this->contractFields($w, ['department_ids' => [(string) $w['dept']->id, (string) $other->id]]))
            ->assertSessionHasNoErrors()->assertRedirect(route('approvals.admin.types.index'));

        $type = ApprovalType::where('name', '住宅の契約用（請負新築工事契約）')->sole();
        $this->assertSame(ApprovalBodyForm::Table, $type->body_form);
        $this->assertTrue($type->usesTable());
        $this->assertSameIgnoringKeyOrder(['subtotal' => true, 'upper' => ['工事請負金額', null, '紹介料'], 'lower' => ['土地契約金額', null]], $type->table_layout);
        $this->assertSame('様請負新築工事契約の件', $type->subject_suffix);
        $this->assertTrue($type->uses_tsubo && $type->uses_tsubo_price && $type->uses_staff && $type->uses_contract_date);
        $this->assertSame('上記の内容に基づき、販売をおこないます。', $type->fixed_text);
        $this->assertEqualsCanonicalizing([$w['dept']->id, $other->id], $type->departments->pluck('id')->all());

        // 追加・少額工事契約（2 つ目。「計」なし・後半なし・間の 5 行は自由行）
        $this->store($admin, $this->contractFields($w, [
            'name' => '住宅の契約用（追加・少額工事契約）', 'layout_upper' => ['工事請負金額', '', '', '', '', ''],
            'layout_lower' => [], 'layout_subtotal' => null, 'subject_suffix' => '様追加・少額工事契約の件',
        ]))->assertSessionHasNoErrors();

        $small = ApprovalType::where('name', '住宅の契約用（追加・少額工事契約）')->sole();
        $this->assertSameIgnoringKeyOrder(['subtotal' => false, 'upper' => ['工事請負金額', null, null, null, null, null], 'lower' => []], $small->table_layout);
    }

    public function test_a_points_type_keeps_no_layout_and_needs_the_headings(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();

        // 5W2H の種類は行を送っても持たない
        $this->store($admin, $this->contractFields($w, ['name' => '購入', 'body_form' => 'points']))->assertSessionHasNoErrors();
        $this->assertNull(ApprovalType::where('name', '購入')->sole()->table_layout);

        // 5W2H の種類は見出しが要る・明細表の種類は要らない（今の見出しのまま持つ）
        $this->store($admin, $this->contractFields($w, ['name' => '人事', 'body_form' => 'points', 'headings' => '']))
            ->assertSessionHasErrors(['headings' => '見出しを入力してください。']);
        $this->store($admin, $this->contractFields($w, ['headings' => '']))->assertSessionHasNoErrors();
        $this->assertSame(BodyTemplate::DEFAULT, ApprovalType::where('name', '住宅の契約用（請負新築工事契約）')->sole()->headings);

        // 明細表の種類では、隠れた見出しの欄に「■」の形でない文が残っていても断らず、見出しも変えない（点検の M-4）
        $this->store($admin, $this->contractFields($w, ['name' => '住宅の契約用（追加）', 'headings' => '自由な文']))->assertSessionHasNoErrors();
        $this->assertSame(BodyTemplate::DEFAULT, ApprovalType::where('name', '住宅の契約用（追加）')->sole()->headings);
        $this->store($admin, $this->contractFields($w, ['name' => '人事', 'body_form' => 'points', 'headings' => '自由な文']))
            ->assertSessionHasErrors(['headings' => '見出しは「■」で始まる行と、中身の無い「・」の行だけで書いてください。']);
    }

    public function test_the_layout_is_checked(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();

        foreach ([
            [['layout_upper' => []], 'layout_upper', '明細表の前半の行を 1 行以上入れてください（名前を空にした行は自由行）。'],
            [['layout_upper' => ['', '　']], 'layout_upper', null],   // 自由行だけでもよい
            [['layout_upper' => ['紹介料', '', '紹介料']], 'layout_upper', '明細表の前半に同じ名前の行があります（紹介料）。'],
            [['layout_lower' => ['土地', ' 土地 ']], 'layout_lower', '明細表の後半に同じ名前の行があります（土地）。'],
            [['layout_upper' => [str_repeat('あ', 31)]], 'layout_upper.0', '明細表の行の名前は30文字以内で入力してください。'],
            [['layout_upper' => array_fill(0, 21, '')], 'layout_upper', '明細表の前半の行は20行までです。'],
            [['subject_suffix' => str_repeat('あ', 51)], 'subject_suffix', null],
            [['fixed_text' => str_repeat('あ', 501)], 'fixed_text', null],
            [['department_ids' => ['999999']], 'department_ids.0', null],
            [['body_form' => 'other'], 'body_form', null],
        ] as $i => [$override, $key, $message]) {
            $response = $this->store($admin, $this->contractFields($w, ['name' => "種類{$i}"] + $override));
            if ($override === ['layout_upper' => ['', '　']]) {
                $response->assertSessionHasNoErrors();

                continue;
            }
            $message === null ? $response->assertSessionHasErrors($key) : $response->assertSessionHasErrors([$key => $message]);
        }

        // 20 行ちょうど・名前 30 文字ちょうど・決まり文句 50 文字・定型文 500 文字は通る
        $this->store($admin, $this->contractFields($w, [
            'name' => 'ちょうど', 'layout_upper' => array_merge([str_repeat('あ', 30)], array_fill(0, 19, '')),
            'subject_suffix' => str_repeat('あ', 50), 'fixed_text' => str_repeat('あ', 500),
        ]))->assertSessionHasNoErrors();
    }

    /** 申請（下書きを含む）がある種類は本文の形を切り替えられない（D4）。無ければ切り替えられ、明細表をやめると行は持たない */
    public function test_the_body_form_cannot_change_once_the_type_has_a_request(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $put   = fn (array $fields) => $this->actingAs($admin)->from(route('approvals.admin.types.index'))
            ->put(route('approvals.admin.types.update', $w['type']), $this->contractFields($w, ['name' => $w['type']->name] + $fields));

        $draft = $this->draftFor($w);
        $put([])->assertSessionHasErrors(['body_form' => 'この種類の申請が 1 件あるため、本文の形を変えられません。変えたいときは新しい種類を作り、この種類を「停止」にしてください。']);
        $this->assertSame(ApprovalBodyForm::Points, $w['type']->fresh()->body_form);
        $this->assertSame(0, ApprovalSettingLog::count(), '断ったら何も記録しない');

        // 押せない欄は送られない＝今の形のまま（ほかの欄は保存できる）
        $fields = $this->contractFields($w, ['name' => $w['type']->name]);
        unset($fields['body_form']);
        $this->actingAs($admin)->put(route('approvals.admin.types.update', $w['type']), $fields)->assertSessionHasNoErrors();
        $this->assertSame(ApprovalBodyForm::Points, $w['type']->fresh()->body_form);
        $this->assertSame('様請負新築工事契約の件', $w['type']->fresh()->subject_suffix);

        // 申請が無くなれば切り替えられる
        $draft->delete();
        $put([])->assertSessionHasNoErrors();
        $this->assertSame(ApprovalBodyForm::Table, $w['type']->fresh()->body_form);

        // 明細表の種類でも、押せない本文の形が送られなければ今の形のまま（5W2H として扱うと、申請のある明細表の種類を保存できない）
        $tableDraft = $this->draftFor($w);
        $this->actingAs($admin)->put(route('approvals.admin.types.update', $w['type']), $fields)->assertSessionHasNoErrors();
        $this->assertSame(ApprovalBodyForm::Table, $w['type']->fresh()->body_form);
        $tableDraft->delete();
        $put(['body_form' => 'points'])->assertSessionHasNoErrors();
        $this->assertNull($w['type']->fresh()->table_layout, '5W2H に戻すと行は持たない');
    }

    public function test_the_changes_are_logged_with_the_usable_departments(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $base  = [
            'name' => $w['type']->name, 'headings' => BodyTemplate::DEFAULT,
            'review_department_id' => (string) $w['reviewDept']->id, 'sort_order' => '1', 'is_active' => '1',
        ];

        $this->actingAs($admin)->put(route('approvals.admin.types.update', $w['type']), $base + [
            'uses_staff' => '1', 'subject_suffix' => '　', 'department_ids' => [(string) $w['dept']->id, (string) $w['dept']->id],
        ])->assertSessionHasNoErrors();

        $log = ApprovalSettingLog::where('action', 'type.updated')->sole();
        // 変わった項目だけ（空白だけの決まり文句は空のまま・同じ部門を 2 回送っても 1 つ）
        $this->assertEquals(['uses_staff' => true, 'department_ids' => [$w['dept']->id]], $log->new_values);
        $this->assertEquals(['uses_staff' => false, 'department_ids' => []], $log->old_values);
        $this->assertNull($w['type']->fresh()->subject_suffix);

        // 同じ中身で保存し直しても記録は増えない（明細表の行の並びなども比べる）
        $this->actingAs($admin)->put(route('approvals.admin.types.update', $w['type']), $base + ['uses_staff' => '1', 'department_ids' => [(string) $w['dept']->id]]);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'type.updated')->count());
    }

    public function test_the_page_shows_the_form_and_the_departments_and_passes_the_values_to_the_modal(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->store($admin, $this->contractFields($w))->assertSessionHasNoErrors();
        $type = ApprovalType::where('name', '住宅の契約用（請負新築工事契約）')->sole();
        $this->draftFor($w, ['type_id' => $type->id]);

        $html = $this->actingAs($admin)->get(route('approvals.admin.types.index'))->assertOk()->getContent();

        // 一覧: 本文の形と使える部門
        $this->assertMatchesRegularExpression('/金額の明細表\s*<span class="block text-\[11px\] text-gray-500">住宅事業部<\/span>/u', $html);
        $this->assertMatchesRegularExpression('/5W2H の見出し\s*<span class="block text-\[11px\] text-gray-500">全部門<\/span>/u', $html);

        // 編集の小窓に渡す値（申請が 1 件ある＝本文の形を押せない）
        preg_match_all("/openEdit\\(JSON\\.parse\\('([^']*)'\\)\\)/", $html, $m);
        $rows = [];
        foreach ($m[1] as $inner) {
            $row = json_decode(json_decode('"' . $inner . '"'), true);
            $rows[$row['id']] = $row;
        }
        $this->assertSame('table', $rows[$type->id]['body_form']);
        $this->assertSameIgnoringKeyOrder(['subtotal' => true, 'upper' => ['工事請負金額', null, '紹介料'], 'lower' => ['土地契約金額', null]], $rows[$type->id]['table_layout']);
        $this->assertSame([$w['dept']->id], $rows[$type->id]['department_ids']);
        $this->assertSame(1, $rows[$type->id]['requests_count']);
        $this->assertTrue($rows[$type->id]['uses_contract_date']);

        // 追加と編集の小窓の欄（同じ部品を 2 回。x-model と送る名前を対で固定する）
        foreach (['create', 'edit'] as $state) {
            foreach ([
                'name="body_form" value="points" x-model="' . $state . '.bodyForm" :disabled="' . $state . '.locked"',
                'name="body_form" value="table" x-model="' . $state . '.bodyForm" :disabled="' . $state . '.locked"',
                '<template x-for="(row, index) in ' . $state . '.upper" :key="row.key">',
                '<template x-for="(row, index) in ' . $state . '.lower" :key="row.key">',
                'name="layout_subtotal" value="1" x-model="' . $state . '.subtotal"',
                'name="subject_suffix" x-model="' . $state . '.suffix"',
                'name="uses_tsubo" value="1" x-model="' . $state . '.uses.tsubo"',
                'name="uses_contract_date" value="1" x-model="' . $state . '.uses.contract_date"',
                'name="fixed_text" x-model="' . $state . '.fixedText"',
                'name="department_ids[]" value="' . $w['dept']->id . '" x-model="' . $state . '.departmentIds"',
                '<div x-show="' . $state . '.bodyForm === \'points\'">',
            ] as $expected) {
                $this->assertStringContainsString($expected, $html);
            }
        }
        $this->assertSame(2, substr_count($html, 'name="layout_upper[]" x-model="row.name"'));
        // 追加の小窓は 5W2H の種類から始まる（フォームの往復で送られる）
        $this->assertStringContainsString('name="body_form" value="points" x-model="create.bodyForm" :disabled="create.locked" checked>', $html);
        $this->assertStringContainsString('this.edit = stateFrom(row);', $html);
    }
}
```

`tests/Feature/Approval/Phase2/TypeManagementTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/TypeManagementTest.php
+++ b/tests/Feature/Approval/Phase2/TypeManagementTest.php
@@ -185,8 +185,8 @@ public function test_the_table_cells_show_each_type_in_display_order(): void
         $rows = $this->tableRows($this->indexHtml($this->approvalAdmin()));
 
         $this->assertSame([
-            ['R&D <試行>', "{$company}・住宅事業部 審査担当者がいません", '停止', '0 件', '0', '編集 | 削除'],
-            [$w['type']->name, "{$company}・総務部", '利用中', '1 件', '7', '編集 | 削除'],
+            ['R&D <試行>', '5W2H の見出し 全部門', "{$company}・住宅事業部 審査担当者がいません", '停止', '0 件', '0', '編集 | 削除'],
+            [$w['type']->name, '5W2H の見出し 全部門', "{$company}・総務部", '利用中', '1 件', '7', '編集 | 削除'],
         ], array_column($rows, 'cells'));
 
         // バッジの色（停止は 6.87:1。#6b7280 だと 4.39:1 で基準の 4.5:1 に届かない）
@@ -218,6 +218,10 @@ public function test_the_edit_modal_is_filled_from_the_current_values(): void
         $this->assertSame([
             'id' => $w['type']->id, 'name' => $w['type']->name, 'headings' => "■ 目的\r\n・",
             'review_department_id' => $w['reviewDept']->id, 'sort_order' => 3, 'is_active' => false,
+            // 段階5 の欄（Phase5/TypeSettingsTest が中身を見る）
+            'body_form' => 'points', 'table_layout' => null, 'subject_suffix' => '',
+            'uses_tsubo' => false, 'uses_tsubo_price' => false, 'uses_staff' => false, 'uses_contract_date' => false,
+            'fixed_text' => '', 'department_ids' => [], 'requests_count' => 0,
         ], $this->editRows($html)[$w['type']->id]);
 
         foreach ([
@@ -427,7 +431,13 @@ public function test_the_logs_keep_the_created_and_deleted_values(): void
         ])->assertRedirect();
         $type = ApprovalType::where('name', '人事')->sole();
 
-        $expected = ['name' => '人事', 'headings' => BodyTemplate::DEFAULT, 'review_department_id' => $w['reviewDept']->id, 'sort_order' => 4, 'is_active' => true];
+        $expected = [
+            'name' => '人事', 'headings' => BodyTemplate::DEFAULT, 'review_department_id' => $w['reviewDept']->id, 'sort_order' => 4, 'is_active' => true,
+            // 段階5 の欄（送らなければ 5W2H の種類・追加の欄は使わない・使える部門は無し＝全部門）
+            'body_form' => 'points', 'table_layout' => null, 'subject_suffix' => null,
+            'uses_tsubo' => false, 'uses_tsubo_price' => false, 'uses_staff' => false, 'uses_contract_date' => false,
+            'fixed_text' => null, 'department_ids' => [],
+        ];
         $this->assertEquals($expected, ApprovalSettingLog::where('action', 'type.created')->sole()->new_values);
 
         $this->actingAs($admin)->delete(route('approvals.admin.types.destroy', $type))->assertRedirect();
@@ -569,7 +579,12 @@ public function test_a_refused_edit_reopens_the_edit_modal_with_what_was_typed()
         $html = $this->indexHtml($admin);
         $this->assertStringContainsString('<li>' . e('見出しは「■」で始まる行と、中身の無い「・」の行だけで書いてください。') . '</li>', $html);
         $this->assertStringContainsString('createModal: false,', $html);
-        $this->assertStringContainsString('var refused = ' . Js::from(['id' => $w['type']->id] + $typed + ['is_active' => false])->toHtml() . ';', $html);
+        $this->assertStringContainsString('var refused = ' . Js::from(['id' => $w['type']->id] + $typed + ['is_active' => false] + [
+            // 段階5 の欄（送らなかったので空・使わない。この種類の申請は無い）
+            'body_form' => 'points', 'table_layout' => ['subtotal' => false, 'upper' => [], 'lower' => []], 'subject_suffix' => '',
+            'uses_tsubo' => false, 'uses_tsubo_price' => false, 'uses_staff' => false, 'uses_contract_date' => false,
+            'fixed_text' => '', 'department_ids' => [], 'requests_count' => 0,
+        ])->toHtml() . ';', $html);
         $fields = $this->parseForm($html, 'action="' . route('approvals.admin.types.store') . '"')['fields'];
         $this->assertSame(['', BodyTemplate::DEFAULT, '0'], [$fields['name'], $fields['headings'], $fields['sort_order']], '追加の小窓に編集の中身が入った');
         // 断られた理由は開き直した小窓の中にも出す（Task 19 の F-1）。編集の小窓は種類ごとに使い回すので、断られた種類を
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0004-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase2/TypeManagementTest.php tests/Feature/Approval/Phase5/TypeSettingsTest.php
```

Expected: `ERRORS!` `Tests: 36, Assertions: 213, Errors: 1, Failures: 9.`

- `TypeSettingsTest::test_the_changes_are_logged_with_the_usable_departments` — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\ApprovalSettingLog].`
- `TypeManagementTest::test_the_table_cells_show_each_type_in_display_order` — `Failed asserting that two arrays are identical.`
- `TypeManagementTest::test_the_edit_modal_is_filled_from_the_current_values` — `Failed asserting that two arrays are identical.`
- `TypeManagementTest::test_the_logs_keep_the_created_and_deleted_values` — `Failed asserting that two arrays are equal.`
- `TypeManagementTest::test_a_refused_edit_reopens_the_edit_modal_with_what_was_typed` — `Failed asserting that '<!DOCTYPE html>\n`
- `TypeSettingsTest::test_the_two_settings_of_the_requirements_can_be_made` — `Failed asserting that two variables reference the same object.`
- `TypeSettingsTest::test_a_points_type_keeps_no_layout_and_needs_the_headings` — `Session has unexpected errors:`
- `TypeSettingsTest::test_the_layout_is_checked` — `Session is missing expected key [errors].`
- `TypeSettingsTest::test_the_body_form_cannot_change_once_the_type_has_a_request` — `Session is missing expected key [errors].`
- `TypeSettingsTest::test_the_page_shows_the_form_and_the_departments_and_passes_the_values_to_the_modal` — `Failed asserting that '<!DOCTYPE html>\n`

- [ ] **Step 3: ⑨ に欄を足す**

`app/Http/Controllers/Approval/TypeController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/TypeController.php
+++ b/app/Http/Controllers/Approval/TypeController.php
@@ -2,33 +2,47 @@
 
 namespace App\Http\Controllers\Approval;
 
+use App\Enums\ApprovalBodyForm;
 use App\Http\Controllers\Controller;
 use App\Models\ApprovalDepartment;
 use App\Models\ApprovalType;
+use App\Support\Approval\AmountTable;
 use App\Support\Approval\BodyTemplate;
 use App\Support\Approval\FormInput;
+use App\Support\Approval\RequestExtras;
 use App\Support\Approval\SettingLogger;
 use Illuminate\Http\Request;
+use Illuminate\Support\Facades\DB;
 use Illuminate\Validation\Rule;
+use Illuminate\Validation\ValidationException;
 
 /**
- * 申請種類の管理（画面⑨・段階2 設計書 §5.5）。
+ * 申請種類の管理（画面⑨・段階2 設計書 §5.5・段階5 設計書 §5.4）。
  *
- * ⚠ 段階2 は 5W2H の見出しの形だけ。金額の明細表・件名の決まり文句・使える部門などは段階5 で足す。
  * ⚠ 申請が 1 件でもある種類は削除できない（停止を使う）。審査部門を変えても回覧中の申請は
  *   提出したときの審査部門のまま（D11。段階の行が控えを持つ）。
+ * ⚠ 申請（下書きを含む）が 1 件でもある種類は、本文の形を切り替えられない（段階5 D4）。画面は欄を押せなくし（押せない欄は送られない＝
+ *   今の形のまま）、送られてきても断る。
+ * ⚠ 明細表の行・決まり文句・定型文を変えても、提出済みの申請は変わらない（申請が自分の行・組み立てた件名・定型文を持つ。D14）。
+ * ⚠ 送られてこない欄（今までの画面・手で組んだ送信）は、本文の形は今のまま、ほかは空・使わない として扱う。
  */
 class TypeController extends Controller
 {
+    /** 記録に残す種類の項目（設定の記録の前と後。使える部門は department_ids として足す） */
+    private const LOGGED = [
+        'name', 'headings', 'review_department_id', 'sort_order', 'is_active',
+        'body_form', 'table_layout', 'subject_suffix', 'uses_tsubo', 'uses_tsubo_price', 'uses_staff', 'uses_contract_date', 'fixed_text',
+    ];
+
     public function index()
     {
         // 審査部門の審査担当者の人数も読む（いない部門は一覧で知らせる。Task 11 の点検の軽微）。
         // 提出の条件（SubmitChecker）と同じく有効な人だけ数える（無効の人しかいないのに注意が出ない食い違いを無くす。
         // 削除した人は User の SoftDeletes で数えない。2a の Task 11 の再点検 N-1。条件は activeReviewers() の 1 か所）
-        $types = ApprovalType::with(['reviewDepartment' => fn ($q) => $q
-                ->withCount('activeReviewers')
-                ->with('company')])
-            ->withCount('requests')->ordered()->get();
+        $types = ApprovalType::with([
+            'reviewDepartment' => fn ($q) => $q->withCount('activeReviewers')->with('company'),
+            'departments.company',
+        ])->withCount('requests')->ordered()->get();
 
         $departments = ApprovalDepartment::with('company')->get()
             ->sortBy(fn (ApprovalDepartment $d) => [$d->company->sort_order, $d->company_id, $d->sort_order, $d->id])
@@ -39,21 +53,29 @@ public function index()
 
     public function store(Request $request)
     {
-        $validated = $this->validateType($request);
+        [$columns, $departmentIds] = $this->validateType($request);
+
+        $type = DB::transaction(function () use ($columns, $departmentIds): ApprovalType {
+            $type = ApprovalType::create($columns);
+            $type->departments()->sync($departmentIds);
 
-        $type = ApprovalType::create($validated);
-        SettingLogger::record('type.created', 'approval_type', $type->id, [], $validated);
+            return $type;
+        });
+        SettingLogger::record('type.created', 'approval_type', $type->id, [], $this->logValues($type->fresh()));
 
         return $this->back('申請の種類を登録しました。');
     }
 
     public function update(Request $request, ApprovalType $approvalType)
     {
-        $validated = $this->validateType($request, $approvalType);
-        $before    = $approvalType->only(array_keys($validated));
+        [$columns, $departmentIds] = $this->validateType($request, $approvalType);
+        $before = $this->logValues($approvalType);
 
-        $approvalType->update($validated);
-        SettingLogger::recordChange('type.updated', 'approval_type', $approvalType->id, $before, $validated);
+        DB::transaction(function () use ($approvalType, $columns, $departmentIds): void {
+            $approvalType->update($columns);
+            $approvalType->departments()->sync($departmentIds);
+        });
+        SettingLogger::recordChange('type.updated', 'approval_type', $approvalType->id, $before, $this->logValues($approvalType->fresh()));
 
         return $this->back('申請の種類を更新しました。');
     }
@@ -66,30 +88,76 @@ public function destroy(ApprovalType $approvalType)
             return $this->back(null, "この種類の申請が {$count} 件あるため削除できません。使わなくなった種類は「停止」にしてください。");
         }
 
-        $before = $approvalType->only(['name', 'headings', 'review_department_id', 'sort_order', 'is_active']);
+        $before = $this->logValues($approvalType);
         $id     = $approvalType->id;
-        $approvalType->delete();
+        $approvalType->delete();   // 使える部門の行は外部キーの CASCADE で消える
 
         SettingLogger::record('type.deleted', 'approval_type', $id, $before, []);
 
         return $this->back('申請の種類を削除しました。');
     }
 
+    /**
+     * 記録に残す値（本文の形は値の文字・明細表の行はキーの並びをそろえる＝MySQL が JSON のキーを並べ替えて返しても、
+     * 変わっていない項目を「変わった」にしない・使える部門は id の小さい順）
+     *
+     * @return array<string, mixed>
+     */
+    private function logValues(ApprovalType $type): array
+    {
+        $values = $type->only(self::LOGGED);
+        $values['body_form']    = $type->body_form->value;
+        $values['table_layout'] = $type->table_layout === null
+            ? null
+            : AmountTable::layout((bool) ($type->table_layout['subtotal'] ?? false), $type->table_layout['upper'] ?? [], $type->table_layout['lower'] ?? []);
+        $values['department_ids'] = $type->departments()->pluck('approval_departments.id')->map(fn (mixed $id) => (int) $id)->sort()->values()->all();
+
+        return $values;
+    }
+
+    /**
+     * @return array{0: array<string, mixed>, 1: list<int>} [種類の列, 使える部門]
+     */
     private function validateType(Request $request, ?ApprovalType $current = null): array
     {
-        // チェックボックスは外すと送られない（送られなければ停止）
-        $request->merge(['is_active' => $request->boolean('is_active')]);
-        FormInput::unifyNewlines($request, 'headings');
+        // チェックボックスは外すと送られない（送られなければ停止・使わない）。本文の形は送られなければ今の形
+        // （申請がある種類は画面が欄を押せなくするので送られない）
+        $request->merge([
+            'is_active'          => $request->boolean('is_active'),
+            'layout_subtotal'    => $request->boolean('layout_subtotal'),
+            'uses_tsubo'         => $request->boolean('uses_tsubo'),
+            'uses_tsubo_price'   => $request->boolean('uses_tsubo_price'),
+            'uses_staff'         => $request->boolean('uses_staff'),
+            'uses_contract_date' => $request->boolean('uses_contract_date'),
+            'body_form'          => $request->input('body_form', $current?->body_form->value ?? ApprovalBodyForm::Points->value),
+        ]);
+        FormInput::unifyNewlines($request, 'headings', 'fixed_text');
+        $points = $request->input('body_form') === ApprovalBodyForm::Points->value;
 
-        return $request->validate([
+        $validated = $request->validate([
             'name'                 => ['required', 'string', 'max:50', Rule::unique('approval_types', 'name')->ignore($current?->id)],
-            'headings'             => ['required', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail): void {
+            'body_form'            => ['required', 'string', Rule::enum(ApprovalBodyForm::class)],
+            'headings'             => [Rule::requiredIf($points), 'nullable', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail) use ($points): void {
                 // 見出しは「■」の行・中身の無い「・」の行・空の行だけで書く。ほかの形だと、本文が見出しのままでも
-                // 「見出しのまま」と判定されず提出できてしまう（D12。判定は BodyTemplate::isBlank() の 1 か所）
-                if (is_string($value) && ! BodyTemplate::isBlank($value)) {
+                // 「見出しのまま」と判定されず提出できてしまう（D12。判定は BodyTemplate::isBlank() の 1 か所）。
+                // 明細表の種類では見出しを使わない（小窓で隠れている欄の形では断らない。点検の M-4）
+                if ($points && is_string($value) && ! BodyTemplate::isBlank($value)) {
                     $fail('見出しは「■」で始まる行と、中身の無い「・」の行だけで書いてください。');
                 }
             }],
+            'layout_upper'         => ['array', 'max:' . AmountTable::LAYOUT_MAX_ROWS],
+            'layout_upper.*'       => ['nullable', 'string', 'max:' . AmountTable::NAME_MAX],
+            'layout_lower'         => ['array', 'max:' . AmountTable::LAYOUT_MAX_ROWS],
+            'layout_lower.*'       => ['nullable', 'string', 'max:' . AmountTable::NAME_MAX],
+            'layout_subtotal'      => ['boolean'],
+            'subject_suffix'       => ['nullable', 'string', 'max:50'],
+            'uses_tsubo'           => ['boolean'],
+            'uses_tsubo_price'     => ['boolean'],
+            'uses_staff'           => ['boolean'],
+            'uses_contract_date'   => ['boolean'],
+            'fixed_text'           => ['nullable', 'string', 'max:500'],
+            'department_ids'       => ['array'],
+            'department_ids.*'     => ['integer', Rule::exists('approval_departments', 'id')],
             'review_department_id' => ['required', 'integer', Rule::exists('approval_departments', 'id')],
             'sort_order'           => ['required', 'integer', 'min:0', 'max:9999'],
             'is_active'            => ['boolean'],
@@ -99,11 +167,96 @@ private function validateType(Request $request, ?ApprovalType $current = null):
             'name.unique' => 'この種類名は既に登録されています。',
             'headings.required' => '見出しを入力してください。',
             'headings.max' => '見出しは2000文字以内で入力してください。',
+            'layout_upper.max' => '明細表の前半の行は' . AmountTable::LAYOUT_MAX_ROWS . '行までです。',
+            'layout_lower.max' => '明細表の後半の行は' . AmountTable::LAYOUT_MAX_ROWS . '行までです。',
+            'layout_upper.*.max' => '明細表の行の名前は' . AmountTable::NAME_MAX . '文字以内で入力してください。',
+            'layout_lower.*.max' => '明細表の行の名前は' . AmountTable::NAME_MAX . '文字以内で入力してください。',
             'review_department_id.required' => '審査部門を選択してください。',
             'sort_order.required' => '表示順を入力してください。',
         ], [
-            'name' => '種類名',
+            'name'               => '種類名',
+            'body_form'          => '本文の形',
+            'layout_upper'       => '明細表の前半の行',
+            'layout_upper.*'     => '明細表の前半の行の名前',
+            'layout_lower'       => '明細表の後半の行',
+            'layout_lower.*'     => '明細表の後半の行の名前',
+            'layout_subtotal'    => '「計」の行',
+            'subject_suffix'     => '件名の決まり文句',
+            'uses_tsubo'         => '坪数を使う',
+            'uses_tsubo_price'   => '坪単価を使う',
+            'uses_staff'         => '担当者を使う',
+            'uses_contract_date' => '契約予定日を使う',
+            'fixed_text'         => '定型文',
+            'department_ids'     => '使える部門',
+            'department_ids.*'   => '使える部門',
         ]);
+
+        $form   = ApprovalBodyForm::from($validated['body_form']);
+        $layout = AmountTable::layout((bool) $validated['layout_subtotal'], $validated['layout_upper'] ?? [], $validated['layout_lower'] ?? []);
+        $this->refuseLayout($request, $current, $form, $layout);
+
+        $columns = [
+            'name'                 => $validated['name'],
+            // 明細表の種類は見出しを使わない（列は空にできないので、今の見出しのまま持つ。隠れた欄から送られても変えない）
+            'headings'             => $form === ApprovalBodyForm::Points ? $validated['headings'] : ($current?->headings ?? BodyTemplate::DEFAULT),
+            'review_department_id' => $validated['review_department_id'],
+            'sort_order'           => $validated['sort_order'],
+            'is_active'            => $validated['is_active'],
+            'body_form'            => $form->value,
+            'table_layout'         => $form === ApprovalBodyForm::Table ? $layout : null,
+            'subject_suffix'       => self::textOrNull($validated['subject_suffix'] ?? null),
+            'fixed_text'           => self::textOrNull($validated['fixed_text'] ?? null),
+        ];
+        foreach (array_keys(RequestExtras::FIELDS) as $key) {
+            $columns["uses_{$key}"] = (bool) $validated["uses_{$key}"];
+        }
+
+        $departmentIds = array_values(array_unique(array_map('intval', $validated['department_ids'] ?? [])));
+        sort($departmentIds);
+
+        return [$columns, $departmentIds];
+    }
+
+    /**
+     * 本文の形と明細表の行の、入力の検査で見られない決まり（断るときは、ほかの検査と同じく前の画面へ戻す）
+     *
+     * @param array{subtotal: bool, upper: list<?string>, lower: list<?string>} $layout
+     */
+    private function refuseLayout(Request $request, ?ApprovalType $current, ApprovalBodyForm $form, array $layout): void
+    {
+        $errors = [];
+
+        // 申請（下書きを含む）がある種類は本文の形を切り替えられない（D4。書きかけの中身が消えないように）
+        if ($current !== null && $current->body_form !== $form) {
+            $count = $current->requests()->count();
+            if ($count > 0) {
+                $errors['body_form'] = "この種類の申請が {$count} 件あるため、本文の形を変えられません。変えたいときは新しい種類を作り、この種類を「停止」にしてください。";
+            }
+        }
+
+        if ($form === ApprovalBodyForm::Table) {
+            if ($layout['upper'] === []) {
+                $errors['layout_upper'] = '明細表の前半の行を 1 行以上入れてください（名前を空にした行は自由行）。';
+            }
+            // 同じ名前の行が 2 つあると、申請の金額をどちらの行に当てるか決まらない（AmountTable::forForm）
+            foreach (['upper' => '前半', 'lower' => '後半'] as $section => $label) {
+                $named = array_filter($layout[$section], fn (?string $name) => $name !== null);
+                $twice = array_keys(array_filter(array_count_values($named), fn (int $n) => $n > 1));
+                if ($twice !== []) {
+                    $errors["layout_{$section}"] = "明細表の{$label}に同じ名前の行があります（" . implode('・', $twice) . '）。';
+                }
+            }
+        }
+
+        if ($errors !== []) {
+            throw ValidationException::withMessages($errors);
+        }
+    }
+
+    /** 空の文字（前後の空白だけを含む）は null */
+    private static function textOrNull(?string $value): ?string
+    {
+        return ($value === null || trim($value) === '') ? null : $value;
     }
 
     private function back(?string $success, ?string $error = null)
```

`resources/views/approvals/admin/_type_body_form.blade.php`（新規）

```blade
{{-- 申請の種類の小窓（追加・編集で共有）: 本文の形と、金額の明細表の行（段階5 設計書 §5.4・D4）。
     $state は Alpine の状態の名前（'create' か 'edit'。approvalTypes() の中の同じ形の入れ物）。$checked は描いたときの本文の形（追加の小窓だけ。
     フォームの往復のテストが読む checked のため。Alpine は x-model で選び直す）。
     ⚠ 申請（下書きを含む）がある種類は本文の形を押せなくする（押せない欄は送られない＝サーバーは今の形のまま。送られてきても断る。D4）。
     ⚠ 行の欄は Alpine が描く（<template x-for>）。名前を空にした行は自由行（申請者が名前を入れる）。同じ側に同じ名前の行は置けない（サーバーが断る） --}}
<fieldset>
    <legend class="block text-[12px] font-semibold text-gray-700 mb-1">本文の形<span class="text-red-600 ml-0.5">*</span></legend>
    <div class="flex flex-wrap gap-x-5 gap-y-1">
        @foreach(\App\Enums\ApprovalBodyForm::cases() as $form)
            <label class="inline-flex items-center gap-1.5 text-[13px] text-gray-800 cursor-pointer">
                <input type="radio" name="body_form" value="{{ $form->value }}" x-model="{{ $state }}.bodyForm" :disabled="{{ $state }}.locked"{{ ($checked ?? null) === $form->value ? ' checked' : '' }}>
                {{ $form->label() }}
            </label>
        @endforeach
    </div>
    <p x-show="{{ $state }}.locked" class="text-[11px] text-gray-500 mt-1">この種類の申請が <span x-text="{{ $state }}.requestCount"></span> 件あるため、本文の形は変えられません（変えたいときは新しい種類を作り、この種類を「停止」にします）。</p>
</fieldset>

<div x-show="{{ $state }}.bodyForm === 'table'" class="space-y-3">
    @foreach(['upper' => ['前半の行（建物側）', '「計」の上に並ぶ行'], 'lower' => ['後半の行（土地側）', '「計」と「合計金額」の間に並ぶ行']] as $section => [$title, $hint])
        <fieldset>
            <legend class="block text-[12px] font-semibold text-gray-700 mb-0.5">明細表の{{ $title }}</legend>
            <p class="text-[11px] text-gray-400 mb-1.5">{{ $hint }}。名前を空にした行は自由行（申請者が名前を入れる）。{{ \App\Support\Approval\AmountTable::LAYOUT_MAX_ROWS }} 行まで</p>
            <template x-for="(row, index) in {{ $state }}.{{ $section }}" :key="row.key">
                <div class="flex items-center gap-1.5 mb-1.5">
                    <input type="text" name="layout_{{ $section }}[]" x-model="row.name" maxlength="{{ \App\Support\Approval\AmountTable::NAME_MAX }}" placeholder="（自由行）"
                           :aria-label="'{{ $title }}の ' + (index + 1) + ' 行目の名前'"
                           class="flex-1 min-w-0 h-[34px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    <button type="button" @click="moveRow({{ $state }}.{{ $section }}, index, -1)" :disabled="index === 0" :aria-label="(index + 1) + ' 行目を上へ'"
                            class="h-[34px] px-2 border border-gray-300 rounded-md text-[12px] text-gray-600 bg-white cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed">↑</button>
                    <button type="button" @click="moveRow({{ $state }}.{{ $section }}, index, 1)" :disabled="index === {{ $state }}.{{ $section }}.length - 1" :aria-label="(index + 1) + ' 行目を下へ'"
                            class="h-[34px] px-2 border border-gray-300 rounded-md text-[12px] text-gray-600 bg-white cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed">↓</button>
                    <button type="button" @click="{{ $state }}.{{ $section }}.splice(index, 1)" :aria-label="(index + 1) + ' 行目を消す'"
                            class="h-[34px] px-2 border border-gray-300 rounded-md text-[12px] text-red-600 bg-white cursor-pointer">消す</button>
                </div>
            </template>
            <button type="button" @click="addRow({{ $state }}.{{ $section }})" :disabled="{{ $state }}.{{ $section }}.length >= {{ \App\Support\Approval\AmountTable::LAYOUT_MAX_ROWS }}"
                    class="px-3 py-1 text-[12px] font-semibold text-emerald-700 bg-white border border-emerald-600 rounded-md cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed">＋ 行を足す</button>
        </fieldset>
    @endforeach
    <label class="flex items-center gap-2 text-[13px] text-gray-800 cursor-pointer">
        <input type="checkbox" name="layout_subtotal" value="1" x-model="{{ $state }}.subtotal">
        「計」の行を使う（前半の行の合計。「合計金額」は前半と後半の合計でいつも出る）
    </label>
</div>
```

`resources/views/approvals/admin/_type_options.blade.php`（新規）

```blade
{{-- 申請の種類の小窓（追加・編集で共有）: 件名の決まり文句・追加の入力欄・定型文・使える部門（どちらの本文の形でも使える。段階5 設計書 §5.4・D12・D13）。
     $state は Alpine の状態の名前（'create' か 'edit'）。⚠ <option> は使わない（使える部門はチェックボックス。Bug #16 の形にしない） --}}
<div>
    <label for="{{ $state }}-subject-suffix" class="block text-[12px] font-semibold text-gray-700 mb-1">件名の決まり文句</label>
    <input type="text" id="{{ $state }}-subject-suffix" name="subject_suffix" x-model="{{ $state }}.suffix" maxlength="50" placeholder="例: 様請負新築工事契約の件"
           class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
    <p class="text-[11px] text-gray-400 mt-1">件名の後ろに付く文字。申請者は前半（例: 施主名）だけを入れる（「件名を直接書く」にも切り替えられる）。空なら件名は 1 行で書く</p>
</div>

<fieldset>
    <legend class="block text-[12px] font-semibold text-gray-700 mb-1">追加の入力欄</legend>
    <div class="flex flex-wrap gap-x-5 gap-y-1">
        @foreach(\App\Support\Approval\RequestExtras::FIELDS as $key => $label)
            <label class="inline-flex items-center gap-1.5 text-[13px] text-gray-800 cursor-pointer">
                <input type="checkbox" name="uses_{{ $key }}" value="1" x-model="{{ $state }}.uses.{{ $key }}">
                {{ $label }}
            </label>
        @endforeach
    </div>
    <p class="text-[11px] text-gray-400 mt-1">担当者と契約予定日は、使う種類では提出に必須（坪数・坪単価は空でも出せる）</p>
</fieldset>

<div>
    <label for="{{ $state }}-fixed-text" class="block text-[12px] font-semibold text-gray-700 mb-1">定型文</label>
    <textarea id="{{ $state }}-fixed-text" name="fixed_text" x-model="{{ $state }}.fixedText" maxlength="500" rows="2" placeholder="例: 上記の内容に基づき、販売をおこないます。"
              class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed"></textarea>
    <p class="text-[11px] text-gray-400 mt-1">本文の下に出す固定の文（申請者は直さない）。空なら出さない</p>
</div>

<fieldset>
    <legend class="block text-[12px] font-semibold text-gray-700 mb-1">使える部門</legend>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1">
        @foreach($departments as $department)
            <label class="inline-flex items-center gap-1.5 text-[13px] text-gray-800 cursor-pointer">
                <input type="checkbox" name="department_ids[]" value="{{ $department->id }}" x-model="{{ $state }}.departmentIds">
                {{ $department->company->name }}・{{ $department->name }}
            </label>
        @endforeach
    </div>
    <p class="text-[11px] text-gray-400 mt-1">選んだ部門の人の申請の画面にだけ出す。1 つも選ばなければ全部門で使える</p>
</fieldset>
```

`resources/views/approvals/admin/types.blade.php`（変更）

```diff
--- a/resources/views/approvals/admin/types.blade.php
+++ b/resources/views/approvals/admin/types.blade.php
@@ -17,6 +17,19 @@
     $oldText = fn (string $key): string => is_string(old($key)) ? old($key) : '';
     $refused = $errors->any();
     $editId  = is_string(old('edit_id')) ? (int) old('edit_id') : 0;
+    // 段階5 の欄（本文の形・明細表の行・決まり文句・追加の欄・定型文・使える部門）。小窓の JS（stateFrom）が読む形。
+    // 断られたときは送った値、そうでなければ種類の今の値（追加の小窓は既定）。⚠ 配列でない値・文字でない値は空として扱う
+    $oldList  = fn (string $key): array => array_values(array_map(fn ($v) => is_string($v) ? $v : '', is_array(old($key)) ? old($key) : []));
+    $oldRow   = fn (int $requestCount): array => [
+        'body_form'      => in_array(old('body_form'), ['points', 'table'], true) ? old('body_form') : 'points',
+        'table_layout'   => ['subtotal' => (bool) old('layout_subtotal'), 'upper' => $oldList('layout_upper'), 'lower' => $oldList('layout_lower')],
+        'subject_suffix' => $oldText('subject_suffix'),
+        'uses_tsubo' => (bool) old('uses_tsubo'), 'uses_tsubo_price' => (bool) old('uses_tsubo_price'),
+        'uses_staff' => (bool) old('uses_staff'), 'uses_contract_date' => (bool) old('uses_contract_date'),
+        'fixed_text'     => $oldText('fixed_text'),
+        'department_ids' => array_map('intval', array_filter($oldList('department_ids'), 'ctype_digit')),
+        'requests_count' => $requestCount,
+    ];
     $refusedEdit = $refused && $types->contains('id', $editId) ? [
         'id'                   => $editId,
         'name'                 => $oldText('name'),
@@ -24,8 +37,24 @@
         'review_department_id' => $oldText('review_department_id'),
         'sort_order'           => $oldText('sort_order'),
         'is_active'            => (bool) old('is_active'),
-    ] : null;
+    ] + $oldRow((int) $types->firstWhere('id', $editId)->requests_count) : null;
     $refusedCreate = $refused && old('edit_id') === null && old('_method') === null;
+    $createRow = $refusedCreate ? $oldRow(0) : [
+        'body_form' => 'points', 'table_layout' => null, 'subject_suffix' => '',
+        'uses_tsubo' => false, 'uses_tsubo_price' => false, 'uses_staff' => false, 'uses_contract_date' => false,
+        'fixed_text' => '', 'department_ids' => [], 'requests_count' => 0,
+    ];
+    // 編集のボタンが小窓へ渡す値（今の値。種類ごと）
+    $editRow = fn (\App\Models\ApprovalType $type): array => $type->only(['id', 'name', 'headings', 'review_department_id', 'sort_order', 'is_active']) + [
+        'body_form'      => $type->body_form->value,
+        'table_layout'   => $type->table_layout,
+        'subject_suffix' => $type->subject_suffix ?? '',
+        'uses_tsubo' => $type->uses_tsubo, 'uses_tsubo_price' => $type->uses_tsubo_price,
+        'uses_staff' => $type->uses_staff, 'uses_contract_date' => $type->uses_contract_date,
+        'fixed_text'     => $type->fixed_text ?? '',
+        'department_ids' => $type->departments->pluck('id')->all(),
+        'requests_count' => (int) $type->requests_count,
+    ];
 @endphp
 <div x-data="approvalTypes()" x-cloak>
     @include('approvals._mail_failure')
@@ -42,7 +71,7 @@
 
     <h1 class="text-lg font-bold text-gray-900 mb-2">申請種類の管理</h1>
     <p class="text-[12px] text-gray-500 mb-5 max-w-[720px]">
-        申請の画面で選ぶ種類です。種類ごとに、本文に最初から入る見出しと、回る審査部門を決めます。
+        申請の画面で選ぶ種類です。種類ごとに、本文の形（5W2H の見出し／金額の明細表）と、回る審査部門を決めます。件名の決まり文句・坪数などの追加の欄・定型文・使える部門も種類ごとに決められます。
         使わなくなった種類は「停止」にします。新しい申請で選べなくなり、この種類の下書きは、提出の前に種類を選び直してもらいます（差戻し中の申請はそのまま出し直せます。過去の申請もそのまま）。
     </p>
 
@@ -56,10 +85,11 @@
         </div>
         <div class="scroll-hint at-start">
             <div class="scroll-hint-inner">
-        <table class="w-full min-w-[760px] border-collapse">
+        <table class="w-full min-w-[880px] border-collapse">
             <thead>
                 <tr>
                     <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">種類名</th>
+                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">本文の形・使える部門</th>
                     <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">審査部門</th>
                     <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">状態</th>
                     <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">申請</th>
@@ -71,6 +101,10 @@
                 @forelse($types as $type)
                     <tr class="hover:bg-gray-50">
                         <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-900">{{ $type->name }}</td>
+                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">
+                            {{ $type->body_form->label() }}
+                            <span class="block text-[11px] text-gray-500">{{ $type->departments->isEmpty() ? '全部門' : $type->departments->map(fn ($d) => $d->name)->implode('・') }}</span>
+                        </td>
                         <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">
                             {{ $type->reviewDepartment->company->name }}・{{ $type->reviewDepartment->name }}
                             {{-- 審査担当者のいない審査部門は、申請者の提出が断られて初めて分かるので、ここで知らせる（Task 11 の点検の軽微） --}}
@@ -84,7 +118,7 @@
                         <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $type->requests_count }} 件</td>
                         <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $type->sort_order }}</td>
                         <td class="px-4 py-2.5 border-b border-gray-100 text-right whitespace-nowrap">
-                            <button type="button" @click="openEdit({{ \Illuminate\Support\Js::from($type->only(['id', 'name', 'headings', 'review_department_id', 'sort_order', 'is_active'])) }})" class="text-[12px] text-blue-600 hover:underline cursor-pointer bg-transparent border-none p-0">編集</button>
+                            <button type="button" @click="openEdit({{ \Illuminate\Support\Js::from($editRow($type)) }})" class="text-[12px] text-blue-600 hover:underline cursor-pointer bg-transparent border-none p-0">編集</button>
                             <span class="text-gray-200 mx-1">|</span>
                             <form method="POST" action="{{ route('approvals.admin.types.destroy', $type) }}" class="inline" onsubmit="return confirm('この種類を削除しますか。');">
                                 @csrf
@@ -94,7 +128,7 @@
                         </td>
                     </tr>
                 @empty
-                    <tr><td colspan="6" class="px-4 py-8 text-center text-[13px] text-gray-400">申請の種類が登録されていません。</td></tr>
+                    <tr><td colspan="7" class="px-4 py-8 text-center text-[13px] text-gray-400">申請の種類が登録されていません。</td></tr>
                 @endforelse
             </tbody>
         </table>
@@ -133,11 +167,14 @@
                             @endforeach
                         </select>
                     </div>
-                    <div>
+                    @include('approvals.admin._type_body_form', ['state' => 'create', 'checked' => $createRow['body_form']])
+                    {{-- 見出しは 5W2H の種類だけ（明細表の種類では隠し、必須にしない。隠しても送る＝サーバーは使わない） --}}
+                    <div x-show="create.bodyForm === 'points'">
                         <label class="block text-[12px] font-semibold text-gray-700 mb-1">5W2H の見出し<span class="text-red-600 ml-0.5">*</span></label>
-                        <textarea name="headings" required maxlength="2000" rows="12" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] font-mono leading-relaxed">{{ $refusedCreate ? $oldText('headings') : \App\Support\Approval\BodyTemplate::DEFAULT }}</textarea>
+                        <textarea name="headings" :required="create.bodyForm === 'points'" maxlength="2000" rows="12" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] font-mono leading-relaxed">{{ $refusedCreate ? $oldText('headings') : \App\Support\Approval\BodyTemplate::DEFAULT }}</textarea>
                         <p class="text-[11px] text-gray-400 mt-1">申請の本文に最初から入る見出し。見出しは「■」で始まる行に、その下は「・」だけの行にする。「いつ」「いくら」は実施時期・金額の欄で書くので入れない（要件 5.2）</p>
                     </div>
+                    @include('approvals.admin._type_options', ['state' => 'create'])
                     <div>
                         <label class="block text-[12px] font-semibold text-gray-700 mb-1">表示順<span class="text-red-600 ml-0.5">*</span></label>
                         <input type="number" name="sort_order" value="{{ $refusedCreate ? $oldText('sort_order') : '0' }}" required inputmode="numeric" min="0" max="9999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
@@ -187,11 +224,14 @@
                         </select>
                         <p class="text-[11px] text-gray-400 mt-1">変えても、回覧中の申請は提出したときの審査部門のまま回ります（出し直すと新しい設定）</p>
                     </div>
-                    <div>
+                    @include('approvals.admin._type_body_form', ['state' => 'edit'])
+                    <div x-show="edit.bodyForm === 'points'">
                         <label class="block text-[12px] font-semibold text-gray-700 mb-1">5W2H の見出し<span class="text-red-600 ml-0.5">*</span></label>
-                        <textarea name="headings" x-model="editHeadings" required maxlength="2000" rows="12" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] font-mono leading-relaxed"></textarea>
+                        <textarea name="headings" x-model="editHeadings" :required="edit.bodyForm === 'points'" maxlength="2000" rows="12" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] font-mono leading-relaxed"></textarea>
                         <p class="text-[11px] text-gray-400 mt-1">見出しは「■」で始まる行に、その下は「・」だけの行にする</p>
                     </div>
+                    @include('approvals.admin._type_options', ['state' => 'edit'])
+                    <p class="text-[11px] text-gray-400">明細表の行・決まり文句・定型文を変えても、提出済みの申請は提出したときのまま（これから作る申請と、まだ出していない下書きが変わる）</p>
                     <div>
                         <label class="block text-[12px] font-semibold text-gray-700 mb-1">表示順<span class="text-red-600 ml-0.5">*</span></label>
                         <input type="number" name="sort_order" x-model="editSort" required inputmode="numeric" min="0" max="9999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
@@ -220,6 +260,32 @@
 @push('scripts')
 <script>
 function approvalTypes() {
+    // 明細表の行の x-for の鍵（小窓を開き直しても重ならない）
+    var rowSeq = 0;
+
+    // 小窓の状態（本文の形・明細表の行・決まり文句・追加の欄・定型文・使える部門〈チェックボックスの値は文字〉）。
+    // ⚠ 返す前に作る（x-model が読む create・edit を、描く前から在るようにする）
+    function stateFrom(row) {
+        var layout = row.table_layout || {};
+        var rows = function (names) {
+            return (names || []).map(function (name) { return { key: ++rowSeq, name: name === null ? '' : name }; });
+        };
+        return {
+            bodyForm: row.body_form,
+            locked: row.requests_count > 0,
+            requestCount: row.requests_count,
+            upper: rows(layout.upper),
+            lower: rows(layout.lower),
+            subtotal: !!layout.subtotal,
+            suffix: row.subject_suffix || '',
+            uses: { tsubo: !!row.uses_tsubo, tsubo_price: !!row.uses_tsubo_price, staff: !!row.uses_staff, contract_date: !!row.uses_contract_date },
+            fixedText: row.fixed_text || '',
+            departmentIds: (row.department_ids || []).map(String)
+        };
+    }
+
+    var createRow = {{ \Illuminate\Support\Js::from($createRow) }};
+
     return {
         // 断られた入力で開き直す（Task 19 の C4）。追加の小窓はサーバーが打った中身を描き、編集の小窓は init で打った中身を入れる
         createModal: {{ $refusedCreate ? 'true' : 'false' }},
@@ -230,6 +296,9 @@ function approvalTypes() {
         editHeadings: '',
         editSort: '0',
         editActive: true,
+        // 段階5 の欄（_type_body_form・_type_options が読む。追加と編集で同じ形）
+        create: stateFrom(createRow),
+        edit: stateFrom(createRow),
 
         init() {
             var refused = {{ \Illuminate\Support\Js::from($refusedEdit) }};
@@ -245,7 +314,21 @@ function approvalTypes() {
             this.editHeadings = row.headings;
             this.editSort = String(row.sort_order);
             this.editActive = row.is_active;
+            this.edit = stateFrom(row);
             this.editModal = true;
+        },
+
+        addRow(rows) {
+            rows.push({ key: ++rowSeq, name: '' });
+        },
+
+        moveRow(rows, index, step) {
+            var to = index + step;
+            if (to < 0 || to >= rows.length) {
+                return;
+            }
+            var moved = rows.splice(index, 1)[0];
+            rows.splice(to, 0, moved);
         }
     };
 }
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0004-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 と同じコマンドに、管理の画面の入口と入力のエラーの走査を足して）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase2/TypeManagementTest.php tests/Feature/Approval/Phase5/TypeSettingsTest.php tests/Feature/Approval/ApprovalAdminGateTest.php tests/Feature/JapaneseValidationMessagesTest.php tests/Feature/ValidationErrorFeedbackTest.php
```

Expected: `OK`

- [ ] **Step 5: 全件を流す**

Expected: `OK (3994 tests, 37709 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Http/Controllers/Approval/TypeController.php resources/views/approvals/admin/_type_body_form.blade.php resources/views/approvals/admin/_type_options.blade.php resources/views/approvals/admin/types.blade.php tests/Concerns/ComparesJsonColumns.php tests/Feature/Approval/Phase5/TypeSettingsTest.php tests/Feature/Approval/Phase2/TypeManagementTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 申請種類の管理に本文の形・明細表の行・追加の欄・使える部門を足す

⑨ の登録・編集の小窓で、本文の形（5W2H の見出し／金額の明細表）・明細表の
行と「計」の行・件名の決まり文句・坪数などの追加の欄の使う／使わない・
定型文・使える部門を設定できるようにする（段階5 設計書 §5.4・D12）。
申請がある種類は本文の形を切り替えられない（D4）。使える部門も設定の
記録に残す。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 5: ② の明細表と追加の欄の保存・提出の条件・使える部門

申請の保存で明細表と追加の欄を受け取り（金額はサーバーが明細表から計算・種類が使わない欄は空・定型文は種類から写す）、提出の条件に明細表の種類の決まり（D2）と使える部門（D13）と決まり文句だけの件名を足す。選べる種類を使える部門で絞り、コピーで写す（設計書 §5.5・§5.6・D2・D3・D13・D14・D15・§0.5）。

**Files:**
- Create: `app/Support/Approval/RequestFields.php`
- Modify: `app/Http/Controllers/Approval/RequestController.php`・`app/Support/Approval/SubmitChecker.php`
- Test: Create `tests/Feature/Approval/Phase5/RequestTableSaveTest.php`／Modify `tests/Concerns/BuildsApprovalFixtures.php`

**Interfaces:**
- Consumes: Task 2 の列・`ApprovalType::usableIn()`・`isUsableIn()`・`usesTable()`・`ApprovalRequest::submittedRevision()`、Task 3 の `AmountTable::cleanInput()`・`fromInput()`・`amount()`・`totals()`・`hasUnnamedAmount()`・`MAX_ROWS`・`NAME_MAX`・`AMOUNT_MAX`・`RequestExtras::FIELDS`・`REQUIRED`・`usedBy()`・`FormInput::digits()`・`trim()`・`YEN`
- Produces: `RequestFields::prepare(Request $request): void`（検査の前にそろえる）・`RequestFields::columns(array $validated, ?ApprovalType $type): array`（保存する中身の列）・`RequestFields::totalError(?array $table): ?string`
- Produces: 申請の保存の入力 `amount_table[upper|lower][n][fixed|name|sale|cost]`・`tsubo`・`tsubo_price`・`staff`・`contract_date`。Task 6 の画面がこの形で送る
- Produces: `Tests\Concerns\BuildsApprovalFixtures::housingContractType(array $world, array $attributes = []): ApprovalType`（以降のテストが使う）

**差分の大きさ:** 5 ファイル・+571 / −31 行（差分のファイル `0005-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Concerns/BuildsApprovalFixtures.php`（変更）

```diff
--- a/tests/Concerns/BuildsApprovalFixtures.php
+++ b/tests/Concerns/BuildsApprovalFixtures.php
@@ -158,4 +158,23 @@ protected function submittedFor(array $world, array $attributes = []): ApprovalR
 
         return $request->refresh();
     }
+
+    /**
+     * 要件 5.5.7 の住宅の契約用（請負新築工事契約）の種類（段階5。前半: 工事請負金額・自由行・紹介料／「計」あり／
+     * 後半: 土地契約金額・自由行／件名の決まり文句・追加の欄 4 つ・定型文）
+     */
+    protected function housingContractType(array $world, array $attributes = []): ApprovalType
+    {
+        return $this->approvalType($world['reviewDept'], array_merge([
+            'name'               => '住宅の契約用（請負新築工事契約）',
+            'body_form'          => 'table',
+            'table_layout'       => ['subtotal' => true, 'upper' => ['工事請負金額', null, '紹介料'], 'lower' => ['土地契約金額', null]],
+            'subject_suffix'     => '様請負新築工事契約の件',
+            'uses_tsubo'         => true,
+            'uses_tsubo_price'   => true,
+            'uses_staff'         => true,
+            'uses_contract_date' => true,
+            'fixed_text'         => '上記の内容に基づき、販売をおこないます。',
+        ], $attributes));
+    }
 }
```

`tests/Feature/Approval/Phase5/RequestTableSaveTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase5;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Support\Approval\SubmitChecker;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ComparesJsonColumns;
use Tests\TestCase;

/** ② の保存と提出の条件（明細表・追加の欄・使える部門。要件 5.5・段階5 設計書 §5.5・D2・D3・D13・D15） */
class RequestTableSaveTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ComparesJsonColumns;

    /** 9/17 に利用者が見た申請の画面の見本の中身（画面が送る形） */
    private function contractInput(array $w, ApprovalType $type, array $override = []): array
    {
        return array_merge([
            'type_id'       => (string) $type->id,
            'department_id' => (string) $w['dept']->id,
            'subject'       => '山田様請負新築工事契約の件',
            'amount'        => '1',   // 明細表の種類では使わない（サーバーが明細表から計算する。D15）
            'schedule'      => '',
            'body'          => '仕様変更によるオプション工事を含む。',
            'amount_table'  => [
                'upper' => [
                    ['fixed' => '工事請負金額', 'sale' => '28,500,000', 'cost' => '22,000,000'],
                    ['name' => 'オプション工事', 'sale' => '1,200,000', 'cost' => '850,000'],
                    ['fixed' => '紹介料', 'sale' => '0', 'cost' => '300,000'],
                ],
                'lower' => [
                    ['fixed' => '土地契約金額', 'sale' => '12,000,000', 'cost' => '10,500,000'],
                    ['name' => '', 'sale' => '', 'cost' => ''],
                ],
            ],
            'tsubo'         => '３８．５坪',
            'tsubo_price'   => '1,083,000円',
            'staff'         => '佐藤 健一',
            'contract_date' => '2026-10-20',
            'intent'        => 'save',
        ], $override);
    }

    private function row(?string $name, bool $fixed, ?int $sale, ?int $cost): array
    {
        return ['name' => $name, 'fixed' => $fixed, 'sale' => $sale, 'cost' => $cost];
    }

    public function test_a_table_type_saves_the_rows_the_extras_and_the_fixed_text(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type = $this->housingContractType($w);

        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type))->assertSessionHasNoErrors();

        $request = ApprovalRequest::sole();
        // 金額は合計金額の販売金額（送った「1」は使わない）
        $this->assertSame(41700000, $request->amount);
        $this->assertSameIgnoringKeyOrder([
            'subtotal' => true,
            'upper'    => [
                $this->row('工事請負金額', true, 28500000, 22000000),
                $this->row('オプション工事', false, 1200000, 850000),
                $this->row('紹介料', true, 0, 300000),
            ],
            'lower' => [$this->row('土地契約金額', true, 12000000, 10500000), $this->row(null, false, null, null)],
        ], $request->amount_table);
        $this->assertSame(['38.50', 1083000, '佐藤 健一', '2026-10-20'], [$request->tsubo, $request->tsubo_price, $request->staff, $request->contract_date->format('Y-m-d')]);
        $this->assertSame('上記の内容に基づき、販売をおこないます。', $request->fixed_text, '定型文は保存したときに種類から写す');
        $this->assertSame('仕様変更によるオプション工事を含む。', $request->body, '本文は補足');

        // 定型文を変えると、まだ出していない下書きは次に保存したときに変わる（D14）
        $type->update(['fixed_text' => '新しい定型文']);
        $this->actingAs($w['applicant'])->put(route('approvals.requests.update', $request), $this->contractInput($w, $type, ['lock_version' => '0']))->assertSessionHasNoErrors();
        $this->assertSame('新しい定型文', $request->fresh()->fixed_text);
    }

    public function test_the_unused_fields_are_not_kept(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type = $this->housingContractType($w, ['uses_tsubo' => false, 'uses_staff' => false, 'fixed_text' => null]);

        // 5W2H の種類は明細表と追加の欄を持たない（送られても入れない）。金額は打ったもの
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $w['type'], ['amount' => '2,850,000']))->assertSessionHasNoErrors();
        $points = ApprovalRequest::sole();
        $this->assertNull($points->amount_table);
        $this->assertSame([2850000, null, null, null, null, null], [$points->amount, $points->tsubo, $points->tsubo_price, $points->staff, $points->contract_date, $points->fixed_text]);

        // 明細表の種類でも、使わない欄は入れない
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type))->assertSessionHasNoErrors();
        $table = ApprovalRequest::where('type_id', $type->id)->sole();
        $this->assertSame([null, 1083000, null, '2026-10-20', null], [$table->tsubo, $table->tsubo_price, $table->staff, $table->contract_date?->format('Y-m-d'), $table->fixed_text]);
    }

    public function test_the_rows_and_the_extras_are_checked_even_for_a_draft(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type = $this->housingContractType($w);
        $with = fn (array $row) => ['amount_table' => ['upper' => [$row], 'lower' => []]];

        foreach ([
            [$with(['name' => '値引き', 'sale' => 'abc']), 'amount_table.upper.0.sale', '明細表の販売金額「abc」は数で入力してください（マイナスも入れられます）。'],
            [$with(['name' => '値引き', 'cost' => '1,000,000,000,000']), 'amount_table.upper.0.cost', '明細表の工事原価は 12 桁までで入力してください。'],
            [$with(['name' => str_repeat('あ', 31), 'sale' => '1']), 'amount_table.upper.0.name', '明細表の項目名は 30 文字以内で入力してください。'],
            [['amount_table' => ['upper' => array_fill(0, 31, ['name' => 'x']), 'lower' => []]], 'amount_table.upper', '明細表の前半の行は 30 行までです。'],
            [['tsubo' => '38.555'], 'tsubo', '坪数は小数第 2 位までで入力してください。'],
            [['tsubo' => '広い'], 'tsubo', '坪数は数で入力してください（例: 38.5）。'],
            [['tsubo' => '100000'], 'tsubo', '坪数は 99,999.99 以下で入力してください。'],
            [['tsubo_price' => '-1'], 'tsubo_price', '坪単価は 0 以上で入力してください。'],
            [['staff' => str_repeat('あ', 51)], 'staff', '担当者は 50 文字以内で入力してください。'],
            [['contract_date' => '2026-02-30'], 'contract_date', '契約予定日は日付で入力してください。'],
            [['contract_date' => '1999-12-31'], 'contract_date', '契約予定日は 2000 年から 2099 年の日付で入力してください。'],
        ] as [$override, $key, $message]) {
            $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type, $override))
                ->assertSessionHasErrors([$key => $message]);
        }
        $this->assertSame(0, ApprovalRequest::count());

        // マイナス（D3）・12 桁ちょうど・小数第 2 位までの坪数・全角のマイナスは通る
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type, [
            'amount_table' => ['upper' => [['fixed' => '工事請負金額', 'sale' => '999,999,999,999', 'cost' => '－３００，０００']], 'lower' => []],
            'tsubo'        => '99999.99',
        ]))->assertSessionHasNoErrors();
        $this->assertSame(-300000, ApprovalRequest::sole()->amount_table['upper'][0]['cost']);
    }

    /** 行ごとには上限の中でも、合計が 12 桁を超えると断る（金額の列に入らない・本番の MySQL では 500 になる） */
    public function test_a_total_beyond_twelve_digits_is_refused(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type = $this->housingContractType($w);

        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type, [
            'amount_table' => ['upper' => [['fixed' => '工事請負金額', 'sale' => '999999999999'], ['name' => '追加', 'sale' => '1']], 'lower' => []],
        ]))->assertSessionHasErrors(['amount_table' => '明細表の合計が大きすぎます（999,999,999,999 円まで）。']);

        // 工事原価の合計も同じ（点検の T-6）
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type, [
            'amount_table' => ['upper' => [['fixed' => '工事請負金額', 'sale' => '1', 'cost' => '-999999999999'], ['name' => '値引き', 'cost' => '-1']], 'lower' => []],
        ]))->assertSessionHasErrors(['amount_table' => '明細表の合計が大きすぎます（999,999,999,999 円まで）。']);

        $this->assertSame(0, ApprovalRequest::count());
    }

    /** 数の入力は画面と同じ数に読む（先頭の + と 0 を落とす。点検の M-2）。明細表の種類の補足のエラー文は「補足」（M-7） */
    public function test_the_numbers_are_read_like_the_screen_and_the_note_is_named_so(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type = $this->housingContractType($w);

        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type, [
            'amount_table' => ['upper' => [['fixed' => '工事請負金額', 'sale' => '+1,000', 'cost' => '0100']], 'lower' => []],
            'tsubo_price'  => '＋1,000円',
        ]))->assertSessionHasNoErrors();
        $request = ApprovalRequest::sole();
        $this->assertSame([1000, 100, 1000], [$request->amount_table['upper'][0]['sale'], $request->amount_table['upper'][0]['cost'], $request->tsubo_price]);

        // 5W2H の種類の金額も同じそろえ方（2a の金額の欄）
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $w['type'], ['amount' => '０２,８５０,０００円']))
            ->assertSessionHasNoErrors();
        $this->assertSame(2850000, ApprovalRequest::where('type_id', $w['type']->id)->sole()->amount);

        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type, ['body' => str_repeat('あ', 20001)]))
            ->assertSessionHasErrors('body');
        $this->assertStringStartsWith('補足', session('errors')->first('body'));
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $w['type'], ['body' => str_repeat('あ', 20001)]))
            ->assertSessionHasErrors('body');
        $this->assertStringStartsWith('重点ポイント（5W2H）', session('errors')->first('body'));
    }

    /** 明細表の種類の提出に要るもの（D2）: 合計金額の販売金額（0 円より大きい）・担当者・契約予定日・名前のない行の金額には項目名 */
    public function test_a_table_request_needs_the_total_the_staff_and_the_contract_date(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type = $this->housingContractType($w);

        $empty = $this->draftFor($w, ['type_id' => $type->id, 'body' => null, 'amount' => null]);
        $this->assertSame([
            '明細表の合計金額の販売金額を入れてください（0 円より大きい金額）。',
            '担当者を入力してください。',
            '契約予定日を入力してください。',
        ], SubmitChecker::reasons($empty, $w['applicant']), '補足（本文）は空でもよい・坪数と坪単価も空でもよい');

        $unnamed = $this->draftFor($w, ['type_id' => $type->id, 'staff' => '　', 'contract_date' => '2026-10-20', 'amount_table' => [
            'subtotal' => true,
            'upper'    => [$this->row('工事請負金額', true, 1000, null), $this->row(null, false, null, -50)],
            'lower'    => [],
        ]]);
        $this->assertSame([
            '明細表の名前のない行に金額が入っています。項目名を入れてください。',
            '担当者を入力してください。',
        ], SubmitChecker::reasons($unnamed, $w['applicant']), '空白だけの担当者は空');

        $suffixOnly = $this->draftFor($w, ['type_id' => $type->id, 'subject' => '様請負新築工事契約の件', 'staff' => '佐藤', 'contract_date' => '2026-10-20', 'amount_table' => [
            'subtotal' => false, 'upper' => [$this->row('工事請負金額', true, 1000, null)], 'lower' => [],
        ]]);
        $this->assertSame(
            ['件名の前半（「様請負新築工事契約の件」の前）を入力してください。'],
            SubmitChecker::reasons($suffixOnly, $w['applicant']),
            '決まり文句だけの件名は件名が無いのと同じ（点検の I-4）'
        );

        // そろえば画面から提出できる
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type, ['intent' => 'submit']))
            ->assertSessionHasNoErrors();
        $this->assertSame(ApprovalStatus::HeadReview, ApprovalRequest::where('subject', '山田様請負新築工事契約の件')->sole()->status);
    }

    /** 5W2H の種類でも、担当者・契約予定日を使う設定なら提出に要る（D2 × D12。点検の T-5） */
    public function test_a_points_type_that_uses_the_staff_and_the_date_needs_them(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $w['type']->update(['uses_staff' => true, 'uses_contract_date' => true]);

        $draft = $this->draftFor($w);
        $this->assertSame(['担当者を入力してください。', '契約予定日を入力してください。'], SubmitChecker::reasons($draft, $w['applicant']));

        $draft->update(['staff' => '佐藤 健一', 'contract_date' => '2026-10-20']);
        $this->assertSame([], SubmitChecker::reasons($draft->fresh(), $w['applicant']));
    }

    public function test_the_types_offered_are_those_usable_in_my_departments(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $other    = $this->approvalDepartment($w['company'], ['name' => 'ミツワ不動産']);
        $mine     = $this->housingContractType($w, ['name' => '住宅の契約用']);
        $mine->departments()->attach($w['dept']->id);
        $elsewhere = $this->housingContractType($w, ['name' => '不動産だけの種類']);
        $elsewhere->departments()->attach($other->id);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.create'))->assertOk()->getContent();
        $this->assertStringContainsString('>' . e($w['type']->name) . '</option>', $html, '使える部門の無い種類は全部門');
        $this->assertStringContainsString('>住宅の契約用</option>', $html);
        $this->assertStringNotContainsString('不動産だけの種類', $html);

        // 自分の部門で使えない種類は保存でも断る
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $elsewhere))
            ->assertSessionHasErrors(['type_id' => '選んだ申請の種類は使えません。選び直してください。']);

        // あとで使える部門から外れた種類の下書きは、編集の画面でその種類を選択肢に残す（保存で消えない。§5.5。点検の T-4）
        $draft = $this->draftFor($w, ['type_id' => $elsewhere->id]);
        $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $draft))->assertOk()->assertSee('>不動産だけの種類</option>', false);
        $this->actingAs($w['applicant'])->put(route('approvals.requests.update', $draft), $this->contractInput($w, $elsewhere, ['lock_version' => '0']))
            ->assertSessionHasNoErrors();
        $this->assertSame($elsewhere->id, $draft->fresh()->type_id);
    }

    /** 兼務の人が、種類を使えない部門で出そうとしたら断る（D13） */
    public function test_a_type_must_be_usable_in_the_chosen_department(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $other = $this->approvalDepartment($w['company'], ['name' => 'ミツワ不動産', 'head_user_id' => $w['head']->id]);
        $w['applicant']->approvalDepartments()->attach($other->id);
        $type = $this->housingContractType($w);
        $type->departments()->attach($other->id);

        $draft = $this->draftFor($w, ['type_id' => $type->id, 'staff' => '佐藤', 'contract_date' => '2026-10-20', 'amount_table' => [
            'subtotal' => false, 'upper' => [$this->row('工事請負金額', true, 1000, null)], 'lower' => [],
        ]]);

        $this->assertSame(
            ['申請の種類「住宅の契約用（請負新築工事契約）」は申請部門「住宅事業部」では使えません。種類か申請部門を選び直してください。'],
            SubmitChecker::reasons($draft, $w['applicant'])
        );
        $draft->update(['department_id' => $other->id]);
        $this->assertSame([], SubmitChecker::reasons($draft->fresh(), $w['applicant']));
    }

    /** 差戻し中は、種類と申請部門を前の提出から変えていなければ、使える部門が変わってもそのまま出し直せる（D13） */
    public function test_a_returned_request_is_not_stranded_by_a_change_of_the_usable_departments(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $other = $this->approvalDepartment($w['company'], ['name' => 'ミツワ不動産', 'head_user_id' => $w['head']->id]);
        $w['applicant']->approvalDepartments()->attach($other->id);

        $request = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, '金額の根拠を足してください');
        $w['type']->departments()->attach($other->id);   // 回っている途中で、管理者が使える部門をミツワ不動産だけにした

        $returned = $request->fresh();
        $this->assertSame(ApprovalStatus::Returned, $returned->status);
        $this->assertSame([], SubmitChecker::reasons($returned, $w['applicant']), '種類も部門も変えていなければ出し直せる');

        // 種類か部門を変えたら、今の決まりに従う
        $returned->update(['department_id' => $other->id]);
        $this->assertSame([], SubmitChecker::reasons($returned->fresh(), $w['applicant']), 'ミツワ不動産では使える');
        $back = $this->approvalDepartment($w['company'], ['name' => '賃貸事業部', 'head_user_id' => $w['head']->id]);
        $w['applicant']->approvalDepartments()->attach($back->id);
        $returned->update(['department_id' => $back->id]);
        $this->assertStringContainsString('では使えません。', implode('', SubmitChecker::reasons($returned->fresh(), $w['applicant'])));

        // 部門を戻しても、種類を変えたら今の決まりに従う（点検の T-4）
        $elsewhere = $this->housingContractType($w, ['name' => '不動産だけの種類']);
        $elsewhere->departments()->attach($other->id);
        $returned->update(['department_id' => $w['dept']->id, 'type_id' => $elsewhere->id]);
        $this->assertContains(
            '申請の種類「不動産だけの種類」は申請部門「住宅事業部」では使えません。種類か申請部門を選び直してください。',
            SubmitChecker::reasons($returned->fresh(), $w['applicant'])
        );
    }

    public function test_copying_a_table_request_copies_the_rows_and_the_extras(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type = $this->housingContractType($w);
        $this->actingAs($w['applicant'])->post(route('approvals.requests.store'), $this->contractInput($w, $type))->assertSessionHasNoErrors();
        $source = ApprovalRequest::sole();

        $copy = $this->actingAs($w['applicant'])->get(route('approvals.requests.create', ['copy' => $source->id]))->assertOk()->viewData('approvalRequest');

        $this->assertSame($type->id, $copy->type_id);
        $this->assertSame($source->amount_table, $copy->amount_table);
        $this->assertSame(['38.50', 1083000, '佐藤 健一', '2026-10-20'], [$copy->tsubo, $copy->tsubo_price, $copy->staff, $copy->contract_date->format('Y-m-d')]);
        $this->assertNull($copy->fixed_text, '定型文は保存のときに種類から写す');
        $this->assertSame(1, ApprovalRequest::count(), '保存するまで下書きはできない');

        // あとで種類が自分の部門で使えなくなったら、写すときに種類を空にして選び直してもらう（点検の T-4）
        $type->departments()->attach($this->approvalDepartment($w['company'], ['name' => 'ミツワ不動産'])->id);
        $later = $this->actingAs($w['applicant'])->get(route('approvals.requests.create', ['copy' => $source->id]))->assertOk()->viewData('approvalRequest');
        $this->assertNull($later->type_id);
        $this->assertSame($source->amount_table, $later->amount_table, '明細表は写す（選び直した種類の行に並べ直す）');
    }
}
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0005-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/RequestTableSaveTest.php
```

Expected: `ERRORS!` `Tests: 11, Assertions: 25, Errors: 2, Failures: 9.`

- `RequestTableSaveTest::test_the_numbers_are_read_like_the_screen_and_the_note_is_named_so` — `ErrorException: Trying to access array offset on null`
- `RequestTableSaveTest::test_copying_a_table_request_copies_the_rows_and_the_extras` — `Error: Call to a member function format() on null`
- `RequestTableSaveTest::test_a_table_type_saves_the_rows_the_extras_and_the_fixed_text` — `Failed asserting that 1 is identical to 41700000.`
- `RequestTableSaveTest::test_the_unused_fields_are_not_kept` — `Failed asserting that two arrays are identical.`
- `RequestTableSaveTest::test_the_rows_and_the_extras_are_checked_even_for_a_draft` — `Session is missing expected key [errors].`
- `RequestTableSaveTest::test_a_total_beyond_twelve_digits_is_refused` — `Session is missing expected key [errors].`
- `RequestTableSaveTest::test_a_table_request_needs_the_total_the_staff_and_the_contract_date` — `補足（本文）は空でもよい・坪数と坪単価も空でもよい`
- `RequestTableSaveTest::test_a_points_type_that_uses_the_staff_and_the_date_needs_them` — `Failed asserting that two arrays are identical.`
- `RequestTableSaveTest::test_the_types_offered_are_those_usable_in_my_departments` — `Failed asserting that '<!DOCTYPE html>\n`
- `RequestTableSaveTest::test_a_type_must_be_usable_in_the_chosen_department` — `Failed asserting that two arrays are identical.`
- `RequestTableSaveTest::test_a_returned_request_is_not_stranded_by_a_change_of_the_usable_departments` — `Failed asserting that '' [ASCII](length: 0) contains "では使えません。" [UTF-8](length: 24).`

- [ ] **Step 3: 保存する列と提出の条件を足す**

`app/Support/Approval/RequestFields.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Models\ApprovalType;
use Illuminate\Http\Request;

/**
 * 申請の画面から送られた中身を、種類に合わせて保存する列にする（段階5 設計書 §5.5・D2・D5・D14・D15）。
 *
 * - 明細表の種類: 明細表は種類の行の設定でそろえ（どの行が名前を設定した行かはサーバーが決める）、金額は合計金額の販売金額を
 *   **サーバーで計算する**（画面から送られた金額は使わない。D15）。5W2H の種類は明細表を持たない（送られても捨てる）
 * - 追加の入力欄: 種類が使う欄だけを保存し、使わない欄は空にする（画面から送られても入れない）
 * - 定型文: 保存したときに種類から写す（下書き・差戻し中は保存のたびに今の種類の文。提出したあとは直せないので変わらない。D14）
 */
final class RequestFields
{
    /** 申請の行が持つ中身の列（申請者が直す 2a からの列。状態の列は入れない） */
    private const CONTENT = ['type_id', 'department_id', 'subject', 'amount', 'schedule', 'body', 'related_numbers'];

    /** 検査の前にそろえる（明細表の金額・坪数・坪単価。FormInput::digits。数でない値はそのまま残して検査で断る） */
    public static function prepare(Request $request): void
    {
        $request->merge([
            'amount_table' => AmountTable::cleanInput($request->input('amount_table')),
            'tsubo'        => FormInput::digits($request->input('tsubo'), ['坪']),
            'tsubo_price'  => FormInput::digits($request->input('tsubo_price'), FormInput::YEN),
        ]);
    }

    /**
     * 検査を通った値から、保存する中身の列
     *
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    public static function columns(array $validated, ?ApprovalType $type): array
    {
        $columns = array_intersect_key($validated, array_flip(self::CONTENT));

        $table = null;
        if ($type?->usesTable()) {
            $table             = AmountTable::fromInput($type->table_layout ?? [], $validated['amount_table'] ?? []);
            $columns['amount'] = AmountTable::amount($table);
        }
        $columns['amount_table'] = $table;

        $used = RequestExtras::usedBy($type);
        foreach (array_keys(RequestExtras::FIELDS) as $key) {
            $columns[$key] = in_array($key, $used, true) ? ($validated[$key] ?? null) : null;
        }
        $columns['fixed_text'] = $type?->fixed_text;

        return $columns;
    }

    /**
     * 明細表の合計が 12 桁を超えるとき（行ごとの上限は検査で見る。何行も足すと超えうる）の理由。合計金額の販売金額は金額の列
     * （BIGINT。台帳・Excel の数）に入る。粗利益金額は保存しない計算の数なので見ない
     *
     * @param array<string, mixed>|null $table
     */
    public static function totalError(?array $table): ?string
    {
        if ($table === null) {
            return null;
        }

        $total = AmountTable::totals($table)['total'];
        foreach ([$total['sale'], $total['cost']] as $value) {
            if (abs($value) > AmountTable::AMOUNT_MAX) {
                return '明細表の合計が大きすぎます（' . number_format(AmountTable::AMOUNT_MAX) . ' 円まで）。';
            }
        }

        return null;
    }
}
```

`app/Support/Approval/SubmitChecker.php`（変更）

```diff
--- a/app/Support/Approval/SubmitChecker.php
+++ b/app/Support/Approval/SubmitChecker.php
@@ -9,7 +9,7 @@
 use App\Models\User;
 
 /**
- * 提出（出し直し）の条件（要件 4.3 のケース 4・5.1・設計書 D4・D10・D12・§5.8）。
+ * 提出（出し直し）の条件（要件 4.3 のケース 4・5.1・5.5・設計書 D4・D10・D12・§5.8・段階5 設計書 D2・D13）。
  *
  * 理由をすべて集めて返す（1 つずつ直して出し直させない）。画面は編集画面の上にまとめて出す。
  * 形の検査（長さ・金額の範囲・関連する決裁No の形と数）は、保存のときに RequestController の入力の検査が見る
@@ -50,25 +50,67 @@ public static function reasons(ApprovalRequest $request, User $user): array
             } elseif (! self::hasOtherReviewer($type, $user)) {
                 $reasons[] = "審査部門「{$type->reviewDepartment->name}」に、申請者本人以外の審査担当者がいません。管理者に連絡してください。";
             }
+
+            // 使える部門（段階5 D13）。差戻し中は、種類と申請部門を前の提出から変えていなければそのまま出し直せる（停止した種類と同じ考え）
+            if ($department !== null && ! $type->isUsableIn($department->id) && self::followsUsableDepartments($request)) {
+                $reasons[] = "申請の種類「{$type->name}」は申請部門「{$department->name}」では使えません。種類か申請部門を選び直してください。";
+            }
         }
 
         if (ApprovalSetting::current()->president_user_id === null) {
             $reasons[] = '社長が未設定です。管理者に連絡してください。';
         }
 
-        // ⚠ 全角の空白も落とす（trim() は落とさない。画面からの値はミドルウェアが先に落とすが、ここはそれに頼らない）
-        if (preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', (string) $request->subject) === '') {
+        // ⚠ 全角の空白も落とす（FormInput::trim。画面からの値はミドルウェアが先に落とすが、ここはそれに頼らない）。
+        // 決まり文句だけの件名（前半を入れていない）は、台帳と PDF に施主名の無い件名が載るので断る（段階5。点検の I-4）
+        $subject = FormInput::trim($request->subject);
+        $suffix  = FormInput::trim($type?->subject_suffix);
+        if ($subject === '') {
             $reasons[] = '件名を入力してください。';
+        } elseif ($suffix !== '' && $subject === $suffix) {
+            $reasons[] = "件名の前半（「{$suffix}」の前）を入力してください。";
         }
 
-        // 「■」の行は後ろに書き足しても見出しとして扱う（D12）ので、どこに書けばよいかを添える（Task 19 の C6）
-        if (BodyTemplate::isBlank($request->body)) {
+        if ($type?->usesTable()) {
+            // 明細表の種類（段階5 D2）: 台帳の金額（合計金額の販売金額）が必ず入る・名前のない行の金額は項目名が要る。補足（本文）は任意
+            $table = $request->amount_table ?? [];
+            if (AmountTable::amount($table) === null) {
+                $reasons[] = '明細表の合計金額の販売金額を入れてください（0 円より大きい金額）。';
+            }
+            if (AmountTable::hasUnnamedAmount($table)) {
+                $reasons[] = '明細表の名前のない行に金額が入っています。項目名を入れてください。';
+            }
+        } elseif (BodyTemplate::isBlank($request->body)) {
+            // 「■」の行は後ろに書き足しても見出しとして扱う（D12）ので、どこに書けばよいかを添える（Task 19 の C6）
             $reasons[] = '重点ポイント（5W2H）が見出しのままです。中身は「■」の行の後ろではなく、下の「・」の行に書いてください。';
         }
 
+        // 種類が使う欄のうち、提出に必須の欄（担当者・契約予定日。段階5 D2）
+        foreach (array_intersect(RequestExtras::REQUIRED, RequestExtras::usedBy($type)) as $key) {
+            if (FormInput::trim((string) ($request->getAttributes()[$key] ?? null)) === '') {
+                $reasons[] = RequestExtras::FIELDS[$key] . 'を入力してください。';
+            }
+        }
+
         return $reasons;
     }
 
+    /**
+     * 使える部門の決まりに従うか（段階5 D13）。下書きは従う。差戻し中は、種類か申請部門を前の提出（最後に提出した控え）から
+     * 変えたときだけ従う（回っている途中で管理者が使える部門を変えても、行き止まりにしない）
+     */
+    private static function followsUsableDepartments(ApprovalRequest $request): bool
+    {
+        if ($request->status !== ApprovalStatus::Returned) {
+            return true;
+        }
+
+        $snapshot = $request->submittedRevision()?->snapshot ?? [];
+
+        return (int) ($snapshot['type']['id'] ?? 0) !== $request->type_id
+            || (int) ($snapshot['department']['id'] ?? 0) !== $request->department_id;
+    }
+
     /** 審査部門に、申請者本人以外の有効な審査担当者がいるか（4.3 のケース 4） */
     private static function hasOtherReviewer(ApprovalType $type, User $user): bool
     {
```

`app/Http/Controllers/Approval/RequestController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/RequestController.php
+++ b/app/Http/Controllers/Approval/RequestController.php
@@ -12,10 +12,12 @@
 use App\Models\ApprovalStep;
 use App\Models\ApprovalType;
 use App\Models\User;
+use App\Support\Approval\AmountTable;
 use App\Support\Approval\Assignees;
 use App\Support\Approval\FormInput;
 use App\Support\Approval\RelatedNumbers;
 use App\Support\Approval\RequestContent;
+use App\Support\Approval\RequestFields;
 use App\Support\Approval\RequestPermissions;
 use App\Support\Approval\RequestSnapshot;
 use App\Support\Approval\RequestVisibility;
@@ -250,6 +252,7 @@ private function afterSave(Request $request, ApprovalRequest $approvalRequest, i
 
     /**
      * 形の検査（下書きは途中でも保存できる。D13）。そろっているかは提出のとき SubmitChecker が見る。
+     * 返すのは保存する中身の列（明細表の種類は明細表から金額を計算し、使わない追加の欄は空・定型文は種類から写す。RequestFields）。
      *
      * ⚠ ルールは literal の配列で書く（JapaneseValidationMessagesTest の走査が和名の漏れを見る）。
      *
@@ -260,18 +263,24 @@ private function validated(Request $request, ?ApprovalRequest $current, string $
         $user = $request->user();
 
         $request->merge([
-            'amount'          => self::normalizeAmount($request->input('amount')),
+            'amount'          => FormInput::digits($request->input('amount'), FormInput::YEN),
             'related_numbers' => RelatedNumbers::clean(is_array($request->input('related_numbers')) ? $request->input('related_numbers') : []),
         ]);
+        RequestFields::prepare($request);
         FormInput::unifyNewlines($request, 'body');
 
-        // 選べる種類は利用中の種類と、この申請が今使っている種類（停止していても保存はできる。D10）
-        $typeIds = ApprovalType::active()->pluck('id')->push($current?->type_id)->filter()->all();
+        $memberOf = $user->approvalDepartments()->pluck('approval_departments.id')->all();
+        // 選べる種類は、自分の所属部門のどれかで使える利用中の種類と、この申請が今使っている種類（停止していても、使える部門から
+        // 外れても保存はできる。D10・段階5 D13。提出のとき SubmitChecker が見る）
+        $typeIds = ApprovalType::active()->usableIn($memberOf)->pluck('id')->push($current?->type_id)->filter()->all();
         // 選べる申請部門は自分の所属部門と、この申請が今使っている部門（提出のとき SubmitChecker が所属を見る）
-        $departmentIds = $user->approvalDepartments()->pluck('approval_departments.id')->push($current?->department_id)->filter()->all();
+        $departmentIds = collect($memberOf)->push($current?->department_id)->filter()->all();
+        // 明細表の種類の本文は補足（エラー文の名前を画面の見出しに合わせる。点検の M-7）
+        $typeId    = $request->input('type_id');
+        $bodyLabel = is_string($typeId) && ctype_digit($typeId) && ApprovalType::find((int) $typeId)?->usesTable() ? '補足' : '重点ポイント（5W2H）';
 
         try {
-            return $request->validate([
+            $validated = $request->validate([
                 'type_id'           => ['nullable', 'integer', Rule::in($typeIds)],
                 'department_id'     => ['nullable', 'integer', Rule::in($departmentIds)],
                 'subject'           => ['nullable', 'string', 'max:100'],
@@ -280,6 +289,18 @@ private function validated(Request $request, ?ApprovalRequest $current, string $
                 'body'              => ['nullable', 'string', 'max:20000'],
                 'related_numbers'   => ['array', 'max:' . RelatedNumbers::MAX],
                 'related_numbers.*' => ['string', 'regex:' . RelatedNumbers::PATTERN],
+                // 明細表（明細表の種類だけ使う。形は AmountTable::cleanInput() でそろえてある）・追加の入力欄（種類が使う欄だけ保存する）
+                'amount_table'          => ['array'],
+                'amount_table.upper'    => ['array', 'max:' . AmountTable::MAX_ROWS],
+                'amount_table.lower'    => ['array', 'max:' . AmountTable::MAX_ROWS],
+                'amount_table.*.*.name' => ['nullable', 'string', 'max:' . AmountTable::NAME_MAX],
+                'amount_table.*.*.fixed' => ['nullable', 'string'],
+                'amount_table.*.*.sale' => ['nullable', 'integer', 'min:-999999999999', 'max:999999999999'],
+                'amount_table.*.*.cost' => ['nullable', 'integer', 'min:-999999999999', 'max:999999999999'],
+                'tsubo'                 => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999.99'],
+                'tsubo_price'           => ['nullable', 'integer', 'min:0', 'max:999999999999'],
+                'staff'                 => ['nullable', 'string', 'max:50'],
+                'contract_date'         => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2099-12-31'],
             ], [
                 'type_id.in'              => '選んだ申請の種類は使えません。選び直してください。',
                 'department_id.in'        => '申請部門は、自分の所属部門から選んでください。',
@@ -287,36 +308,67 @@ private function validated(Request $request, ?ApprovalRequest $current, string $
                 'amount.max'              => '金額は 999,999,999,999 円以下で入力してください。',
                 'related_numbers.max'     => '関連する決裁No は ' . RelatedNumbers::MAX . ' 個までです。',
                 'related_numbers.*.regex' => '関連する決裁No「:input」の形が違います（例: R8-J-001）。',
+                'amount_table.upper.max'  => '明細表の前半の行は ' . AmountTable::MAX_ROWS . ' 行までです。',
+                'amount_table.lower.max'  => '明細表の後半の行は ' . AmountTable::MAX_ROWS . ' 行までです。',
+                'amount_table.*.*.name.max'   => '明細表の項目名は ' . AmountTable::NAME_MAX . ' 文字以内で入力してください。',
+                'amount_table.*.*.sale.integer' => '明細表の販売金額「:input」は数で入力してください（マイナスも入れられます）。',
+                'amount_table.*.*.sale.min'   => '明細表の販売金額は 12 桁までで入力してください。',
+                'amount_table.*.*.sale.max'   => '明細表の販売金額は 12 桁までで入力してください。',
+                'amount_table.*.*.cost.integer' => '明細表の工事原価「:input」は数で入力してください（マイナスも入れられます）。',
+                'amount_table.*.*.cost.min'   => '明細表の工事原価は 12 桁までで入力してください。',
+                'amount_table.*.*.cost.max'   => '明細表の工事原価は 12 桁までで入力してください。',
+                'tsubo.numeric'           => '坪数は数で入力してください（例: 38.5）。',
+                'tsubo.decimal'           => '坪数は小数第 2 位までで入力してください。',
+                'tsubo.min'               => '坪数は 0 以上で入力してください。',
+                'tsubo.max'               => '坪数は 99,999.99 以下で入力してください。',
+                'tsubo_price.integer'     => '坪単価は円の数で入力してください。',
+                'tsubo_price.min'         => '坪単価は 0 以上で入力してください。',
+                'tsubo_price.max'         => '坪単価は 999,999,999,999 円以下で入力してください。',
+                'staff.max'               => '担当者は 50 文字以内で入力してください。',
+                'contract_date.date_format'     => '契約予定日は日付で入力してください。',
+                'contract_date.after_or_equal'  => '契約予定日は 2000 年から 2099 年の日付で入力してください。',
+                'contract_date.before_or_equal' => '契約予定日は 2000 年から 2099 年の日付で入力してください。',
             ], [
                 'type_id'       => '申請の種類',
                 'department_id' => '申請部門',
-                'body'          => '重点ポイント（5W2H）',
+                'body'          => $bodyLabel,
+                'amount_table'          => '明細表',
+                'amount_table.upper'    => '明細表の前半の行',
+                'amount_table.lower'    => '明細表の後半の行',
+                'amount_table.*.*.name' => '明細表の項目名',
+                'amount_table.*.*.fixed' => '明細表の行',
+                'amount_table.*.*.sale' => '販売金額',
+                'amount_table.*.*.cost' => '工事原価',
+                'tsubo'                 => '坪数',
+                'tsubo_price'           => '坪単価',
+                'staff'                 => '担当者',
+                'contract_date'         => '契約予定日',
             ]);
+
+            $columns = RequestFields::columns($validated, isset($validated['type_id']) ? ApprovalType::find($validated['type_id']) : null);
+            $error   = RequestFields::totalError($columns['amount_table']);
+            if ($error !== null) {
+                throw ValidationException::withMessages(['amount_table' => $error]);
+            }
+
+            return $columns;
         } catch (ValidationException $e) {
             throw $e->redirectTo($redirectTo);
         }
     }
 
-    /** 金額の入力を数字だけにそろえる（全角・カンマ・「円」・「¥」・空白を落とす）。数字でなければ検査で断る */
-    private static function normalizeAmount(mixed $value): mixed
-    {
-        if (! is_string($value)) {
-            return $value;
-        }
-
-        $digits = str_replace([',', '円', '¥', '￥', ' '], '', mb_convert_kana($value, 'as'));
-
-        return $digits === '' ? null : $digits;
-    }
-
-    /** コピーして作成で写す中身（設計書 §5.6。添付は写さない） */
+    /**
+     * コピーして作成で写す中身（設計書 §5.6。添付は写さない）。明細表・追加の欄も写す（段階5 §5.6。明細表は画面を描くときに
+     * 種類の今の行の設定に合わせて並べ直す＝AmountTable::forForm。定型文は保存のときに種類から写すので写さない）
+     */
     private function copiedFields(ApprovalRequest $source, User $user): array
     {
-        $typeUsable       = $source->type_id !== null && ApprovalType::active()->whereKey($source->type_id)->exists();
-        $departmentUsable = $source->department_id !== null && $user->approvalDepartments()->whereKey($source->department_id)->exists();
+        $memberOf         = $user->approvalDepartments()->pluck('approval_departments.id')->all();
+        $typeUsable       = $source->type_id !== null && ApprovalType::active()->usableIn($memberOf)->whereKey($source->type_id)->exists();
+        $departmentUsable = $source->department_id !== null && in_array($source->department_id, $memberOf, true);
 
         return [
-            // 停止した種類・今は所属していない部門は空にして選び直してもらう
+            // 停止した種類・自分の部門で使えない種類・今は所属していない部門は空にして選び直してもらう
             'type_id'         => $typeUsable ? $source->type_id : null,
             'department_id'   => $departmentUsable ? $source->department_id : null,
             'subject'         => $source->subject,
@@ -324,20 +376,27 @@ private function copiedFields(ApprovalRequest $source, User $user): array
             'schedule'        => $source->schedule,
             'body'            => $source->body,
             'related_numbers' => $source->related_numbers ?? [],
+            'amount_table'    => $source->amount_table,
+            'tsubo'           => $source->tsubo,
+            'tsubo_price'     => $source->tsubo_price,
+            'staff'           => $source->staff,
+            'contract_date'   => $source->contract_date?->format('Y-m-d'),
         ];
     }
 
     /** @return array<string, mixed> */
     private function formData(ApprovalRequest $approvalRequest, User $user): array
     {
-        $types = ApprovalType::active()->ordered()->get();
-        // 停止した種類を使っている申請は、その種類も選択肢に残す（保存で消えないように。D10）
+        $departments = $user->approvalDepartments()->with('company')->orderBy('approval_departments.sort_order')->get();
+        $memberOf    = $departments->pluck('id')->all();
+
+        // 種類は、自分の所属部門のどれかで使える利用中の種類（段階5 D13）
+        $types = ApprovalType::active()->usableIn($memberOf)->ordered()->get();
+        // 停止した種類・使える部門から外れた種類を使っている申請は、その種類も選択肢に残す（保存で消えないように。D10）
         if ($approvalRequest->type_id !== null && ! $types->contains('id', $approvalRequest->type_id)) {
             $types->push($approvalRequest->type()->firstOrFail());
         }
 
-        $departments = $user->approvalDepartments()->with('company')->orderBy('approval_departments.sort_order')->get();
-        $memberOf    = $departments->pluck('id')->all();
         // 今は所属していない部門を使っている申請も同じ（提出のときに選び直してもらう）
         if ($approvalRequest->department_id !== null && ! in_array($approvalRequest->department_id, $memberOf, true)) {
             $departments->push($approvalRequest->department()->with('company')->firstOrFail());
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0005-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 のテストと、2a からの申請の保存と提出のテスト・入力の項目名の走査）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/RequestTableSaveTest.php tests/Feature/Approval/Phase2 tests/Feature/JapaneseValidationMessagesTest.php tests/Feature/ValidationErrorFeedbackTest.php 2>&1 | tail -3
```

Expected: `OK`（2a の金額の欄は「＋1,000」「0100」も読めるようになるが、既存のテストは変えずに通る）

- [ ] **Step 5: 全件を流す**

Expected: `OK (4005 tests, 37798 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/RequestFields.php app/Support/Approval/SubmitChecker.php app/Http/Controllers/Approval/RequestController.php tests/Concerns/BuildsApprovalFixtures.php tests/Feature/Approval/Phase5/RequestTableSaveTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 申請の明細表と追加の欄を保存し提出の条件を足す

明細表の種類の金額は保存のときに合計金額の販売金額から計算し、種類が
使わない追加の欄は空に、定型文は種類から写す（段階5 設計書 D14・D15）。
提出には合計金額の販売金額・担当者・契約予定日・名前のない行の項目名が
要り、決まり文句だけの件名は断る（D2）。選べる種類と提出は使える部門に
従い、差戻し中は種類と部門を変えていなければそのまま出し直せる（D13）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 6: ② の画面に明細表・件名の組み立て・追加の欄・定型文

申請の画面に、明細表（その場の計算・行を足す・スマホはカード）・件名の組み立て・追加の欄・定型文を足す。種類を選び直したときは行を並べ直し、保存で消えるものがあれば知らせて元の種類に戻せるようにする（設計書 §5.5・D15・D16・要件 5.5.3・5.5.6・§0.6）。

**Files:**
- Create: `resources/views/approvals/requests/_amount_table_input.blade.php`・`resources/views/approvals/requests/_amount_table_sum.blade.php`
- Modify: `app/Http/Controllers/Approval/RequestController.php`（`formData()` に `typeConfigs`・`tableRows`）・`resources/views/approvals/requests/form.blade.php`
- Test: Create `tests/Feature/Approval/Phase5/RequestFormTableTest.php`

**Interfaces:**
- Consumes: Task 3 の `AmountTable::forForm()`・`cleanInput()`・`MAX_ROWS`・`NAME_MAX`・`RequestExtras::FIELDS`・`usedBy()`、Task 5 の保存の入力の形・`housingContractType()`
- Produces: 画面の `typeConfigs`（種類の id => `{form, layout, suffix, uses, fixedText}`）・`tableRows`（`{upper, lower}` の各行 `{fixed: ?string, name: string, sale: string, cost: string}`）・`approvalRequestForm()` の `rate`・`rateLabel`・`parseAmount`・`total`・`subtotal`・`rowLine`・`mergeRows`・`composeSubject`・`lostOnChange`・`restoreType`（テストが node で呼ぶ）

**差分の大きさ:** 5 ファイル・+799 / −9 行（差分のファイル `0006-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase5/RequestFormTableTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Support\Approval\AmountTable;
use App\Support\Approval\BodyTemplate;
use App\Support\Approval\FormInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ComparesJsonColumns;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;
use Tests\Unit\Approval\AmountTableTest;

/**
 * ② の画面の明細表・件名の組み立て・追加の欄（要件 5.5.3〜5.5.6・段階5 設計書 §5.5・D16）。
 *
 * ⚠ 行は Alpine が描く（<template x-for>）ので、HTML の往復では送られない。画面に渡す値（typeConfigs・tableRows）と、
 *   JS の計算・並べ直し・件名の組み立てを node で動かして固定する（顧客の画面の RunsBuyerFormScript と同じ考え。node が無ければ飛ばす）。
 */
class RequestFormTableTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ComparesJsonColumns;
    use ParsesForms;

    private function row(?string $name, bool $fixed, ?int $sale, ?int $cost): array
    {
        return ['name' => $name, 'fixed' => $fixed, 'sale' => $sale, 'cost' => $cost];
    }

    /** script の中の Js::from の値（JSON.parse('…') の中身）を、名前のすぐ後ろから読む */
    private function jsValue(string $html, string $prefix): mixed
    {
        $at = strpos($html, $prefix . 'JSON.parse(\'');
        $this->assertNotFalse($at, "{$prefix} の値が画面に無い");
        $start = $at + strlen($prefix . 'JSON.parse(\'');
        $end   = strpos($html, '\')', $start);

        return json_decode(json_decode('"' . substr($html, $start, $end - $start) . '"'), true);
    }

    public function test_the_page_passes_the_type_settings_and_the_rows(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type = $this->housingContractType($w);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.create'))->assertOk()->getContent();

        $this->assertSameIgnoringKeyOrder([
            'form' => 'table', 'layout' => ['subtotal' => true, 'upper' => ['工事請負金額', null, '紹介料'], 'lower' => ['土地契約金額', null]],
            'suffix' => '様請負新築工事契約の件', 'uses' => ['tsubo', 'tsubo_price', 'staff', 'contract_date'], 'fixedText' => '上記の内容に基づき、販売をおこないます。',
        ], $this->jsValue($html, 'types: ')[$type->id]);
        $this->assertSame(['form' => 'points', 'layout' => ['subtotal' => false, 'upper' => [], 'lower' => []], 'suffix' => '', 'uses' => [], 'fixedText' => ''], $this->jsValue($html, 'types: ')[$w['type']->id]);
        $this->assertSame(['upper' => [], 'lower' => []], $this->jsValue($html, 'var tableRows = '), '種類を選ぶ前は行が無い（選んだときに JS が種類の行で作る）');

        // 明細表・追加の欄・定型文の部品と、明細表の種類では金額を押せなくする（送らない）こと
        foreach ([
            '<div x-show="isTable()" x-cloak class="space-y-2">',
            '<template x-for="(row, index) in rows.upper" :key="row.key">',
            '<template x-for="(row, index) in rows.lower" :key="row.key">',
            ":name=\"'amount_table[upper][' + index + '][fixed]'\" :value=\"row.fixed\"",
            ":name=\"'amount_table[lower][' + index + '][sale]'\" x-model=\"row.sale\"",
            'x-show="!isTable()" :disabled="isTable()"',
            'name="tsubo" inputmode="decimal" placeholder="例: 38.5" :disabled="!uses(\'tsubo\')"',
            'name="contract_date" :disabled="!uses(\'contract_date\')"',
            'name="staff" maxlength="50" :disabled="!uses(\'staff\')"',
            'x-text="fixedText()"',
            'id="subject" name="subject" value="" x-model="subjectText" maxlength="100"',
            'x-model="subjectPrefix" @input="composeSubject()"',
            // 5W2H の種類に選び直したら、明細表の欄は押せなくして送らない（点検の M-4）・選び直しで消えるものを知らせて戻せる（I-3）
            ':value="row.fixed" :disabled="!isTable()"',
            '@blur="formatAmount(row, \'sale\')" :disabled="!isTable()"',
            'x-ref="typeSelect"',
            'x-ref="extra_staff"',
            '@click="restoreType()"',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        // スマホでは 1 行 1 枚のカード（表の要素を block に。横スクロールにしない。要件 5.5.6）
        $this->assertStringContainsString('<table class="block md:table w-full border-collapse text-[13px]">', $html);
        $this->assertStringContainsString('<thead class="hidden md:table-header-group">', $html);
    }

    public function test_the_edit_page_shows_the_saved_rows_in_the_layout_of_today(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type    = $this->housingContractType($w);
        $request = $this->draftFor($w, ['type_id' => $type->id, 'subject' => '山田様請負新築工事契約の件', 'staff' => '佐藤', 'tsubo' => '38.5', 'tsubo_price' => 1083000, 'contract_date' => '2026-10-20', 'amount_table' => [
            'subtotal' => true,
            'upper'    => [$this->row('工事請負金額', true, 28500000, 22000000), $this->row('外構', false, -300000, null), $this->row('紹介料', true, null, 300000)],
            'lower'    => [$this->row('土地契約金額', true, 12000000, null)],
        ]]);
        // 管理者が「紹介料」を「紹介手数料」に変えた（金額の入った古い行は自由行として残す。D16）
        $type->update(['table_layout' => ['subtotal' => true, 'upper' => ['工事請負金額', null, '紹介手数料'], 'lower' => ['土地契約金額', null]]]);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $request))->assertOk()->getContent();

        $this->assertSame([
            'upper' => [
                ['fixed' => '工事請負金額', 'name' => '', 'sale' => '28,500,000', 'cost' => '22,000,000'],
                ['fixed' => null, 'name' => '外構', 'sale' => '-300,000', 'cost' => ''],
                ['fixed' => '紹介手数料', 'name' => '', 'sale' => '', 'cost' => ''],
                ['fixed' => null, 'name' => '紹介料', 'sale' => '', 'cost' => '300,000'],
            ],
            'lower' => [
                ['fixed' => '土地契約金額', 'name' => '', 'sale' => '12,000,000', 'cost' => ''],
                ['fixed' => null, 'name' => '', 'sale' => '', 'cost' => ''],
            ],
        ], $this->jsValue($html, 'var tableRows = '));

        // 追加の欄は保存した値で開く・件名は組み立てたまま送る
        $form = $this->parseForm($html, 'action="' . route('approvals.requests.update', $request) . '"');
        $this->assertSame(['38.50', '1,083,000', '佐藤', '2026-10-20', '山田様請負新築工事契約の件'], [
            $form['fields']['tsubo'], $form['fields']['tsubo_price'], $form['fields']['staff'], $form['fields']['contract_date'], $form['fields']['subject'],
        ]);
        $this->assertStringContainsString('subjectText: ' . Js::from('山田様請負新築工事契約の件')->toHtml() . ',', $html);
    }

    /** 断られて戻ったときは、打った値のまま出す（読めない値も消さない） */
    public function test_a_refused_save_shows_what_was_typed(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type    = $this->housingContractType($w);
        $request = $this->draftFor($w, ['type_id' => $type->id]);

        $this->actingAs($w['applicant'])->from(route('approvals.requests.edit', $request))->put(route('approvals.requests.update', $request), [
            'type_id' => (string) $type->id, 'department_id' => (string) $w['dept']->id, 'subject' => 'x', 'lock_version' => '0', 'intent' => 'save',
            'amount_table' => ['upper' => [['fixed' => '工事請負金額', 'sale' => '２千万', 'cost' => '1,000']], 'lower' => []],
        ])->assertRedirect(route('approvals.requests.edit', $request));

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $request))->assertOk()->getContent();
        $this->assertSame(['upper' => [['fixed' => '工事請負金額', 'name' => '', 'sale' => '2千万', 'cost' => '1000']], 'lower' => []], $this->jsValue($html, 'var tableRows = '));
        $this->assertStringContainsString(e('明細表の販売金額「2千万」は数で入力してください（マイナスも入れられます）。'), $html);
    }

    /** 画面の JS の計算・並べ直し・件名の組み立てが、サーバーの AmountTable と同じ答えを出す */
    public function test_the_script_calculates_like_the_server(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので申請書の JavaScript を動かせない');
        }

        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type  = $this->housingContractType($w);
        $plain = $this->approvalType($w['reviewDept'], ['name' => '購入']);
        $html  = $this->actingAs($w['applicant'])->get(route('approvals.requests.create'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<script>\s*(function approvalRequestForm\(\) \{.*?)<\/script>/su', $html, $m), 'function approvalRequestForm() の script が描かれていない');

        $rows = [
            'upper' => [
                ['fixed' => '工事請負金額', 'name' => '', 'sale' => '28,500,000', 'cost' => '２２，０００，０００'],
                ['fixed' => null, 'name' => '値引き', 'sale' => '−300,000', 'cost' => ''],
                ['fixed' => '紹介料', 'name' => '', 'sale' => '0', 'cost' => '300,000円'],
            ],
            'lower' => [['fixed' => '土地契約金額', 'name' => '', 'sale' => '12,000,000', 'cost' => '10,500,000'], ['fixed' => null, 'name' => '', 'sale' => '', 'cost' => '']],
        ];
        $harness = <<<'JS'
            const fs = require('fs');
            const vm = require('vm');
            const input = JSON.parse(fs.readFileSync(process.argv[1], 'utf8'));
            const context = vm.createContext({});
            vm.runInContext(input.script, context);
            const data = vm.runInContext('approvalRequestForm()', context);
            const key = (rows) => rows.map((row, i) => Object.assign({ key: 1000 + i }, row));
            data.$refs = { body: { value: input.body }, typeSelect: { value: '' }, extra_staff: { value: '佐藤' }, extra_tsubo: { value: '' } };
            data.typeChanged(String(input.tableType));
            data.rows = { upper: key(input.rows.upper), lower: key(input.rows.lower) };
            const out = {
                isTable: data.isTable(),
                total: data.total(),
                subtotal: data.subtotal(),
                rowLines: data.rows.upper.map((row) => data.rowLine(row)),
                labels: [data.rateLabel(data.total().rate), data.rateLabel(null), data.yen(-300000)],
                parse: input.parses.map((v) => data.parseAmount(v)),
                rateLabels: input.rates.map((pair) => data.rateLabel(data.rate(pair[0], pair[1]))),
                merged: data.mergeRows(input.layout, { upper: key(input.merge.upper), lower: key(input.merge.lower) }),
                mergedCase: data.mergeRows(input.caseLayout, { upper: key(input.caseRows.upper), lower: key(input.caseRows.lower) }),
                bodyAfterTable: data.$refs.body.value,
            };
            // 件名: 決まり文句のある種類で前半を入れる → 決まり文句の無い種類に選び直すと前半だけ残る
            data.subjectPrefix = '山田';
            data.composeSubject();
            out.composed = data.subjectText;
            data.subjectPrefix = '　';
            data.composeSubject();
            out.composedEmpty = data.subjectText;
            data.subjectPrefix = '山田';
            data.composeSubject();
            data.typeChanged(String(input.plainType));
            out.subjectAfterPlain = [data.subjectMode, data.subjectText];
            out.bodyAfterPlain = data.$refs.body.value;
            out.leaving = data.leaving;
            data.restoreType();
            out.afterRestore = { typeId: data.typeId, select: data.$refs.typeSelect.value, leaving: data.leaving, sale: data.total().sale, isTable: data.isTable(), subject: [data.subjectMode, data.subjectText] };
            process.stdout.write(JSON.stringify(out));
            JS;
        $merge = [
            'upper' => [['fixed' => '工事請負金額', 'name' => '', 'sale' => '1', 'cost' => ''], ['fixed' => '旧い名前', 'name' => '', 'sale' => '', 'cost' => '5'], ['fixed' => null, 'name' => '足した行', 'sale' => '', 'cost' => '']],
            'lower' => [['fixed' => '消えた名前', 'name' => '', 'sale' => '', 'cost' => '']],
        ];
        $layout = ['subtotal' => false, 'upper' => ['工事請負金額', '新しい名前', null], 'lower' => []];

        // 並べ直しの境目（AmountTable::forForm と同じ入力を JS にも渡して比べる。点検の R-5）
        $caseLayout = ['subtotal' => false, 'upper' => ['工事請負金額', null, '紹介料'], 'lower' => [null]];
        $caseTable  = ['subtotal' => false, 'upper' => [
            $this->row('工事請負金額', true, 1, null),
            $this->row('工事請負金額', true, 2, null),    // 同じ名前の 2 つ目（金額があるので自由行で残る）
            $this->row('消えた名前', true, null, null),   // 設定から消えた名前で金額が無い（残さない）
            $this->row('紹介料', true, 0, null),          // 0 円も金額
            $this->row(null, false, null, null),          // 空の自由行
            $this->row('足した行', false, null, 5),
            $this->row(null, false, null, null),          // 設定の自由行より多い空の自由行
        ], 'lower' => [$this->row('土地', false, 3, null), $this->row(null, false, null, null)]];
        $toJs = fn (array $rows): array => array_map(fn (array $r): array => [
            'fixed' => $r['fixed'] ? $r['name'] : null, 'name' => $r['fixed'] ? '' : (string) $r['name'],
            'sale'  => $r['sale'] === null ? '' : (string) $r['sale'], 'cost' => $r['cost'] === null ? '' : (string) $r['cost'],
        ], $rows);

        // 金額の読み方（FormInput::digits と同じ数。点検の M-2）
        $parses = ['1,234', '－５', 'abc', '', '12.5', '２２，０００，０００', '300,000円', '¥1,000', '￥1,000', '−300,000', '+1000', '＋1000', '0100', '000', '-0', "1\t000 ", '　 ', '1e3', '--5', '1234567890123456'];

        // 粗利率（境目と、販売金額 1〜200 円 × 粗利益 −50〜200 円のすべての組。点検の I-1）
        $rates = array_map(fn (array $edge) => [$edge[0], $edge[1]], array_values(AmountTableTest::rateEdges()));
        for ($sale = 1; $sale <= 200; $sale++) {
            for ($profit = -50; $profit <= 200; $profit++) {
                $rates[] = [$sale, $profit];
            }
        }

        $file = tempnam(sys_get_temp_dir(), 'approval-form-');
        try {
            file_put_contents($file, json_encode([
                'script' => $m[1], 'tableType' => $type->id, 'plainType' => $plain->id, 'rows' => $rows, 'merge' => $merge, 'layout' => $layout,
                'caseLayout' => $caseLayout, 'caseRows' => ['upper' => $toJs($caseTable['upper']), 'lower' => $toJs($caseTable['lower'])],
                'parses' => $parses, 'rates' => $rates,
                'body' => BodyTemplate::DEFAULT,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $output = shell_exec(sprintf('%s -e %s %s 2>&1', escapeshellarg($node), escapeshellarg($harness), escapeshellarg($file)));
        } finally {
            unlink($file);
        }
        $js = json_decode((string) $output, true);
        $this->assertIsArray($js, "node で申請書の JavaScript を動かせなかった:\n" . $output);

        // サーバーの計算（同じ行）と同じ
        $server = AmountTable::totals(AmountTable::fromInput($type->table_layout, AmountTable::cleanInput($rows)));
        $this->assertTrue($js['isTable']);
        $this->assertEquals($server['total'], $js['total']);
        $this->assertEquals($server['subtotal'], $js['subtotal']);
        $this->assertEquals([['sale' => 28500000, 'cost' => 22000000, 'profit' => 6500000, 'rate' => 22.8], ['sale' => -300000, 'cost' => 0, 'profit' => -300000, 'rate' => 100], ['sale' => 0, 'cost' => 300000, 'profit' => -300000, 'rate' => null]], $js['rowLines']);
        $this->assertSame([AmountTable::rateLabel($server['total']['rate']), '—', '-300,000円'], $js['labels']);
        $this->assertSame(array_map(function (string $value): ?int {
            $digits = FormInput::digits($value, FormInput::YEN);

            return is_string($digits) && preg_match('/^-?\d{1,15}$/', $digits) === 1 ? (int) $digits : null;
        }, $parses), $js['parse']);

        $mismatch = [];
        foreach ($rates as $i => [$sale, $profit]) {
            $label = AmountTable::rateLabel(AmountTable::rate($sale, $profit));
            if ($label !== $js['rateLabels'][$i]) {
                $mismatch[] = "{$sale} 円・粗利益 {$profit} 円: サーバー {$label}・画面 {$js['rateLabels'][$i]}";
            }
        }
        $this->assertSame([], $mismatch, '粗利率が画面とサーバーで違う');

        $expected = AmountTable::forForm($caseLayout, $caseTable);
        $this->assertSame(
            ['upper' => $toJs($expected['upper']), 'lower' => $toJs($expected['lower'])],
            array_map(fn (array $rows) => array_map(fn (array $row) => array_diff_key($row, ['key' => true]), $rows), $js['mergedCase'])
        );

        // 並べ直し（AmountTable::forForm と同じ規則。D16）
        $this->assertSame([
            ['fixed' => '工事請負金額', 'name' => '', 'sale' => '1', 'cost' => ''],
            ['fixed' => '新しい名前', 'name' => '', 'sale' => '', 'cost' => ''],
            ['fixed' => null, 'name' => '旧い名前', 'sale' => '', 'cost' => '5'],   // 自由行の位置に、申請の並びの順で当てる
            ['fixed' => null, 'name' => '足した行', 'sale' => '', 'cost' => ''],
        ], array_map(fn (array $row) => array_diff_key($row, ['key' => true]), $js['merged']['upper']));
        $this->assertSame([], $js['merged']['lower'], '名前が設定から消えた行は、金額が無ければ残さない');

        // 明細表の種類を選ぶと見出しのままの本文は空になり、5W2H の種類に戻すと見出しが入る・件名は打った前半だけが残る
        $this->assertSame('', $js['bodyAfterTable']);
        $this->assertSame('山田様請負新築工事契約の件', $js['composed']);
        $this->assertSame('', $js['composedEmpty'], '前半が空なら決まり文句だけの件名を作らない（点検の I-4）');
        $this->assertSame(['direct', '山田'], $js['subjectAfterPlain']);
        $this->assertSame(BodyTemplate::DEFAULT, $js['bodyAfterPlain']);

        // 明細表の種類から 5W2H の種類に選び直すと、保存で消えるもの（明細表・新しい種類が使わない担当者）を知らせ、元の種類に戻せる
        // （戻すと明細表は入れたまま。段階5 D16。点検の I-3）
        $this->assertSame(['from' => (string) $type->id, 'labels' => ['明細表', '担当者'], 'subjectMode' => 'split'], $js['leaving']);
        $this->assertSame([
            'typeId' => (string) $type->id, 'select' => (string) $type->id, 'leaving' => null, 'sale' => $server['total']['sale'], 'isTable' => true,
            'subject' => ['split', '山田様請負新築工事契約の件'],   // 件名も前半と決まり文句の組み立てに戻る
        ], $js['afterRestore']);
    }
}
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0006-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/RequestFormTableTest.php
```

Expected: `FAILURES!` `Tests: 4, Assertions: 11, Failures: 4.`

- `RequestFormTableTest::test_the_page_passes_the_type_settings_and_the_rows` — `types:  の値が画面に無い`
- `RequestFormTableTest::test_the_edit_page_shows_the_saved_rows_in_the_layout_of_today` — `var tableRows =  の値が画面に無い`
- `RequestFormTableTest::test_a_refused_save_shows_what_was_typed` — `var tableRows =  の値が画面に無い`
- `RequestFormTableTest::test_the_script_calculates_like_the_server` — `node で申請書の JavaScript を動かせなかった:`

- [ ] **Step 3: 画面に明細表と欄を足す**

`app/Http/Controllers/Approval/RequestController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/RequestController.php
+++ b/app/Http/Controllers/Approval/RequestController.php
@@ -17,6 +17,7 @@
 use App\Support\Approval\FormInput;
 use App\Support\Approval\RelatedNumbers;
 use App\Support\Approval\RequestContent;
+use App\Support\Approval\RequestExtras;
 use App\Support\Approval\RequestFields;
 use App\Support\Approval\RequestPermissions;
 use App\Support\Approval\RequestSnapshot;
@@ -406,6 +407,15 @@ private function formData(ApprovalRequest $approvalRequest, User $user): array
             'approvalRequest' => $approvalRequest,
             'types'           => $types,
             'typeHeadings'    => $types->mapWithKeys(fn (ApprovalType $type) => [$type->id => $type->headings])->all(),
+            // 種類ごとの本文の形・明細表の行の設定・件名の決まり文句・使う追加の欄・定型文（画面の JS が種類を選び直したときに使う。段階5 §5.5）
+            'typeConfigs'     => $types->mapWithKeys(fn (ApprovalType $type) => [$type->id => [
+                'form'      => $type->body_form->value,
+                'layout'    => $type->table_layout ?? ['subtotal' => false, 'upper' => [], 'lower' => []],
+                'suffix'    => $type->subject_suffix ?? '',
+                'uses'      => RequestExtras::usedBy($type),
+                'fixedText' => $type->fixed_text ?? '',
+            ]])->all(),
+            'tableRows'       => $this->tableRows($approvalRequest),
             'departments'     => $departments,
             'memberOf'        => $memberOf,
             'isPresident'     => $user->isApprovalPresident(),
@@ -424,6 +434,36 @@ private function formData(ApprovalRequest $approvalRequest, User $user): array
         ];
     }
 
+    /**
+     * 画面の明細表の行（form 用。名前を設定した行は fixed に名前・金額はカンマ付きの文字）。断られて戻ったときは送った値のまま
+     * （打った文字を出す）。そうでなければ、種類の今の行の設定に合わせて並べ直す（AmountTable::forForm。段階5 D16）
+     *
+     * @return array{upper: list<array{fixed: ?string, name: string, sale: string, cost: string}>, lower: list<array{fixed: ?string, name: string, sale: string, cost: string}>}
+     */
+    private function tableRows(ApprovalRequest $approvalRequest): array
+    {
+        $old = old('amount_table');
+        if (is_array($old)) {
+            $text = fn (mixed $value): string => is_string($value) || is_int($value) ? (string) $value : '';
+
+            return array_map(fn (array $rows) => array_map(fn (array $row) => [
+                'fixed' => $row['fixed'], 'name' => $text($row['name']), 'sale' => $text($row['sale']), 'cost' => $text($row['cost']),
+            ], $rows), AmountTable::cleanInput($old));
+        }
+
+        $type   = $approvalRequest->type;
+        $layout = $type?->usesTable() ? ($type->table_layout ?? []) : [];
+        $table  = AmountTable::forForm($layout, $approvalRequest->amount_table);
+        $amount = fn (?int $value): string => $value === null ? '' : number_format($value);
+
+        return array_map(fn (array $rows) => array_map(fn (array $row) => [
+            'fixed' => $row['fixed'] ? $row['name'] : null,
+            'name'  => $row['fixed'] ? '' : (string) $row['name'],
+            'sale'  => $amount($row['sale']),
+            'cost'  => $amount($row['cost']),
+        ], $rows), ['upper' => $table['upper'], 'lower' => $table['lower']]);
+    }
+
     /**
      * 関連する決裁No のうち、この人が見られる申請（番号 => 申請の id）
      *
```

`resources/views/approvals/requests/_amount_table_input.blade.php`（新規）

```blade
{{-- 申請書の金額の明細表（明細表の種類だけ。要件 5.5.4・5.5.6・段階5 設計書 §5.5・9/17 に利用者が見た画面の見本のとおり）。
     approvalRequestForm() の rows・計算の関数を読む。行は Alpine が描く（名前を設定した行は名前を文字で出し、hidden の fixed で送る。
     どの行が名前を設定した行かは、サーバーが種類の設定で決め直す＝AmountTable::fromInput）。
     ⚠ スマホ（md 未満）は 1 行 1 枚のカードにする（横スクロールを使わない。要件 5.5.6）。表の要素を block にして、各セルに名前を添える。
       同じ欄を 2 回描かない（同じ name が 2 回送られる）。
     ⚠ 金額の計算は画面の見た目だけ。保存する金額はサーバーが計算し直す（D15）。式は AmountTable と同じ
     ⚠ 5W2H の種類に選び直したら、行は JS に残したまま欄を押せなくして送らない（隠れた欄の値で断らない。元の種類に戻せる。点検の M-4） --}}
<div x-show="isTable()" x-cloak class="space-y-2">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <p class="text-[12px] font-semibold text-gray-700">金額の明細<span class="text-red-600 ml-0.5">*</span></p>
        <p class="flex flex-wrap gap-x-3 text-[11px] text-gray-500">
            <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 border border-gray-300 bg-white" aria-hidden="true"></span>入力する欄</span>
            <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 border border-gray-300 bg-slate-100" aria-hidden="true"></span>自動で出す欄</span>
        </p>
    </div>
    <div class="overflow-x-auto">
        <table class="block md:table w-full border-collapse text-[13px]">
            <thead class="hidden md:table-header-group">
                <tr>
                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300">項目</th>
                    <th scope="col" class="px-2 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300 w-[9.5rem]">販売金額</th>
                    <th scope="col" class="px-2 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300 w-[9.5rem]">工事原価</th>
                    <th scope="col" class="px-2 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300">粗利益金額</th>
                    <th scope="col" class="px-2 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300">粗利率</th>
                    <th scope="col" class="px-2 py-2 bg-gray-50 border-b border-gray-300"><span class="sr-only">操作</span></th>
                </tr>
            </thead>
            @foreach(['upper', 'lower'] as $section)
                <tbody class="block md:table-row-group">
                    <template x-for="(row, index) in rows.{{ $section }}" :key="row.key">
                        <tr class="block md:table-row border border-gray-300 rounded-md md:border-0 md:rounded-none mb-2 md:mb-0 px-2 py-1 md:p-0 bg-white">
                            <td class="block md:table-cell px-1 md:px-2 py-1.5 md:border-b md:border-gray-200 align-middle">
                                <template x-if="row.fixed !== null">
                                    <span class="font-semibold text-gray-900">
                                        <span x-text="row.fixed"></span>
                                        <input type="hidden" :name="'amount_table[{{ $section }}][' + index + '][fixed]'" :value="row.fixed" :disabled="!isTable()">
                                    </span>
                                </template>
                                <template x-if="row.fixed === null">
                                    <input type="text" :name="'amount_table[{{ $section }}][' + index + '][name]'" x-model="row.name" maxlength="{{ \App\Support\Approval\AmountTable::NAME_MAX }}" :disabled="!isTable()"
                                           placeholder="項目名を入れてください" :aria-label="'項目名（' + (index + 1) + ' 行目）'"
                                           class="w-full h-[34px] px-2 border border-gray-300 rounded-md text-[13px]">
                                </template>
                            </td>
                            @foreach(['sale' => '販売金額', 'cost' => '工事原価'] as $field => $label)
                                <td class="flex md:table-cell items-center justify-between gap-2 px-1 md:px-2 py-1 md:py-1.5 md:border-b md:border-gray-200 align-middle">
                                    <span class="md:hidden text-[11px] font-semibold text-gray-500 shrink-0">{{ $label }}</span>
                                    <input type="text" inputmode="numeric" :name="'amount_table[{{ $section }}][' + index + '][{{ $field }}]'" x-model="row.{{ $field }}"
                                           @blur="formatAmount(row, '{{ $field }}')" :disabled="!isTable()" :aria-label="'{{ $label }}（' + (row.fixed || row.name || (index + 1) + ' 行目') + '）'"
                                           class="w-full max-w-[11rem] md:max-w-none h-[34px] px-2 border border-gray-300 rounded-md text-[13px] text-right tabular-nums">
                                </td>
                            @endforeach
                            <td class="flex md:table-cell items-center justify-between gap-2 px-1 md:px-2 py-1 md:py-1.5 md:border-b md:border-gray-200 bg-slate-100 text-right tabular-nums text-gray-700">
                                <span class="md:hidden text-[11px] font-semibold text-gray-500">粗利益金額</span>
                                <span x-text="yen(rowLine(row).profit)"></span>
                            </td>
                            <td class="flex md:table-cell items-center justify-between gap-2 px-1 md:px-2 py-1 md:py-1.5 md:border-b md:border-gray-200 bg-slate-100 text-right tabular-nums text-gray-700">
                                <span class="md:hidden text-[11px] font-semibold text-gray-500">粗利率</span>
                                <span x-text="rowLine(row).profit === null ? '' : rateLabel(rowLine(row).rate)"></span>
                            </td>
                            <td class="block md:table-cell px-1 md:px-2 py-1 md:py-1.5 md:border-b md:border-gray-200 text-right">
                                <button type="button" x-show="row.fixed === null" @click="removeRow('{{ $section }}', index)" :aria-label="'この行を消す（' + (index + 1) + ' 行目）'"
                                        class="px-2 py-1 text-[12px] text-gray-500 border border-gray-300 rounded-md bg-white hover:text-red-600 hover:border-red-400 cursor-pointer">消す</button>
                            </td>
                        </tr>
                    </template>
                    @if($section === 'upper')
                        {{-- 「計」（前半の合計。使う種類だけ） --}}
                        <tr x-show="hasSubtotal()" class="block md:table-row border border-gray-300 rounded-md md:border-0 md:rounded-none mb-2 md:mb-0 px-2 py-1 md:p-0 bg-slate-100 font-semibold">
                            @include('approvals.requests._amount_table_sum', ['label' => '計', 'line' => 'subtotal()'])
                        </tr>
                    @else
                        <tr class="block md:table-row border-2 border-emerald-600 rounded-md md:border-0 md:border-y-2 md:rounded-none px-2 py-1 md:p-0 bg-slate-100 font-semibold">
                            @include('approvals.requests._amount_table_sum', ['label' => '合計金額', 'line' => 'total()'])
                        </tr>
                    @endif
                </tbody>
            @endforeach
        </table>
    </div>
    <div class="flex flex-wrap gap-2">
        <button type="button" @click="addRow('upper')" :disabled="rows.upper.length >= {{ \App\Support\Approval\AmountTable::MAX_ROWS }}"
                class="px-3 py-1.5 text-[12px] font-semibold text-emerald-700 bg-white border border-emerald-600 rounded-md cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed">＋ 前半に行を足す</button>
        <button type="button" @click="addRow('lower')" :disabled="rows.lower.length >= {{ \App\Support\Approval\AmountTable::MAX_ROWS }}"
                class="px-3 py-1.5 text-[12px] font-semibold text-emerald-700 bg-white border border-emerald-600 rounded-md cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed">＋ 後半に行を足す</button>
    </div>
    <p class="text-[11px] text-gray-400">
        マイナスも入れられます（値引きなど）。粗利益金額＝販売金額−工事原価、粗利率＝粗利益金額÷販売金額（販売金額が 0 なら「—」）。
        台帳と PDF の金額は「合計金額」の販売金額です（<span x-text="yen(total().sale) || '0円'"></span>）。
    </p>
</div>
```

`resources/views/approvals/requests/_amount_table_sum.blade.php`（新規）

```blade
{{-- 申請書の明細表の合計の行（「計」と「合計金額」）のセル。$label は行の名前、$line は合計を返す JS の式（subtotal()・total()）。
     _amount_table_input が使う（スマホではカードの中に「名前: 値」で並ぶ） --}}
<td class="block md:table-cell px-1 md:px-2 py-1.5 md:border-b md:border-gray-300">{{ $label }}</td>
@foreach(['sale' => '販売金額', 'cost' => '工事原価', 'profit' => '粗利益金額'] as $field => $name)
    <td class="flex md:table-cell items-center justify-between gap-2 px-1 md:px-2 py-1 md:py-1.5 md:border-b md:border-gray-300 text-right tabular-nums">
        <span class="md:hidden text-[11px] text-gray-500">{{ $name }}</span>
        <span x-text="{{ $line }} ? yen({{ $line }}.{{ $field }}) : ''"></span>
    </td>
@endforeach
<td class="flex md:table-cell items-center justify-between gap-2 px-1 md:px-2 py-1 md:py-1.5 md:border-b md:border-gray-300 text-right tabular-nums">
    <span class="md:hidden text-[11px] text-gray-500">粗利率</span>
    <span x-text="{{ $line }} ? rateLabel({{ $line }}.rate) : ''"></span>
</td>
<td class="hidden md:table-cell md:border-b md:border-gray-300"></td>
```

`resources/views/approvals/requests/form.blade.php`（変更）

```diff
--- a/resources/views/approvals/requests/form.blade.php
+++ b/resources/views/approvals/requests/form.blade.php
@@ -81,7 +81,7 @@ class="bg-white rounded-lg border border-gray-200 px-5 py-5 space-y-5">
             <div>
                 <label for="type_id" class="block text-[12px] font-semibold text-gray-700 mb-1">申請の種類<span class="text-red-600 ml-0.5">*</span></label>
                 {{-- ⚠ <option> は @@foreach で静的に出す（Bug #16）。選び直しは @@change で拾う（初期値は selected） --}}
-                <select id="type_id" name="type_id" @change="typeChanged($event.target.value)"
+                <select id="type_id" name="type_id" x-ref="typeSelect" @change="typeChanged($event.target.value)"
                         class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                     <option value="">選んでください</option>
                     @foreach($types as $type)
@@ -104,6 +104,16 @@ class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-w
             </div>
         </div>
 
+        {{-- 種類を選び直して、保存すると消えるものがあるとき（明細表の種類から 5W2H の種類へ・新しい種類が使わない追加の欄）。
+             保存するまでは画面に残っているので、元の種類に戻せる（段階5 D16「黙って消さない」。点検の I-3） --}}
+        <div x-show="leaving !== null" x-cloak role="alert" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-[13px] text-amber-800">
+            <p class="mb-2">選んだ種類は<span class="font-semibold" x-text="leaving ? leaving.labels.join('・') : ''"></span>を使いません。このまま保存すると、入れた内容は消えます。</p>
+            <div class="flex flex-wrap gap-2">
+                <button type="button" @click="restoreType()" class="px-3 py-1.5 text-[12px] font-semibold text-white bg-amber-600 rounded-md hover:bg-amber-700 cursor-pointer">元の種類に戻す</button>
+                <button type="button" @click="leaving = null" class="px-3 py-1.5 text-[12px] font-semibold text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50 cursor-pointer">この種類で進める</button>
+            </div>
+        </div>
+
         {{-- 種類を選び直したとき、本文を書き始めていれば入れ替えるか確かめる（設計書 §5.6） --}}
         <div x-show="pendingTypeId !== null" x-cloak class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-[13px] text-amber-800">
             <p class="mb-2">本文を、選んだ種類の見出しに入れ替えますか？（いま書いてある本文は消えます）</p>
@@ -113,19 +123,39 @@ class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-w
             </div>
         </div>
 
+        {{-- 件名（要件 5.5.3）。種類に決まり文句があれば「前半＋決まり文句」で組み立て、「件名を直接書く」で 1 行に切り替える。
+             送るのはいつも組み立てた件名（下の name="subject"。組み立てるときは隠して送る。段階5 設計書 §5.5） --}}
         <div>
             <label for="subject" class="block text-[12px] font-semibold text-gray-700 mb-1">件名<span class="text-red-600 ml-0.5">*</span></label>
-            <input type="text" id="subject" name="subject" value="{{ old('subject', $approvalRequest->subject) }}" maxlength="100"
-                   class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
+            <div x-show="subjectMode === 'split' && suffix() !== ''" x-cloak class="space-y-1.5">
+                <div class="flex flex-wrap items-center gap-2">
+                    <input type="text" id="subject-prefix" x-model="subjectPrefix" @input="composeSubject()" :maxlength="100 - suffix().length"
+                           aria-label="件名の前半（決まり文句の前）" placeholder="例: 山田"
+                           class="flex-1 min-w-[10rem] h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
+                    <span class="px-2.5 py-2 rounded-md border border-dashed border-gray-300 bg-gray-50 text-[13px] text-gray-700" x-text="suffix()"></span>
+                </div>
+                <p class="text-[12px] text-gray-700 bg-emerald-50 border-l-2 border-emerald-500 px-2.5 py-1.5">台帳と PDF に載る件名: <span class="font-semibold" x-text="subjectText || '（前半を入れてください）'"></span></p>
+                <button type="button" @click="writeSubjectDirectly()" class="text-[12px] text-emerald-700 hover:underline cursor-pointer">件名を直接書く</button>
+            </div>
+            <div x-show="subjectMode === 'direct' || suffix() === ''">
+                <input type="text" id="subject" name="subject" value="{{ old('subject', $approvalRequest->subject) }}" x-model="subjectText" maxlength="100"
+                       class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
+                <button type="button" x-show="suffix() !== ''" x-cloak @click="useSuffix()" class="mt-1 text-[12px] text-emerald-700 hover:underline cursor-pointer">決まり文句（<span x-text="suffix()"></span>）を使う</button>
+            </div>
         </div>
 
         <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
             <div>
                 <label for="amount" class="block text-[12px] font-semibold text-gray-700 mb-1">金額（円・税抜）</label>
-                {{-- ⚠ value="0" の既定値を入れない（設計書 §5.6）。カンマ入りでも受け付ける --}}
+                {{-- ⚠ value="0" の既定値を入れない（設計書 §5.6）。カンマ入りでも受け付ける。
+                     明細表の種類では入れない（合計金額の販売金額をサーバーが計算する。段階5 D15）。押せなくして送らない --}}
                 <input type="text" id="amount" name="amount" inputmode="numeric" placeholder="例: 28,500,000"
                        value="{{ old('amount', $approvalRequest->amount === null ? '' : number_format($approvalRequest->amount)) }}"
+                       x-show="!isTable()" :disabled="isTable()"
                        class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
+                <p x-show="isTable()" x-cloak class="h-[38px] flex items-center px-2.5 rounded-md bg-slate-100 text-[13px] text-gray-700">
+                    <span x-text="yen(total().sale) || '—'"></span><span class="ml-2 text-[11px] text-gray-500">（明細表の合計金額の販売金額）</span>
+                </p>
             </div>
             <div>
                 <label for="schedule" class="block text-[12px] font-semibold text-gray-700 mb-1">実施時期</label>
@@ -134,10 +164,58 @@ class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
             </div>
         </div>
 
-        <div>
-            <label for="body" class="block text-[12px] font-semibold text-gray-700 mb-1">重点ポイント（5W2H）<span class="text-red-600 ml-0.5">*</span></label>
-            <textarea id="body" name="body" x-ref="body" rows="16" maxlength="20000" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ old('body', $approvalRequest->body) }}</textarea>
-            <p class="text-[11px] text-gray-400 mt-1">種類を選ぶと見出しが入ります。見出しのままでは提出できません。「いつ」「いくら」は実施時期・金額の欄に書きます。</p>
+        {{-- 本文の欄（種類の本文の形で並びを変える。明細表の種類は「明細表 → 坪数・坪単価・担当者・契約予定日 → 定型文 → 補足」で、
+             紙の住宅の様式の「（記）」の並び。5W2H の種類は「重点ポイント → 追加の欄 → 定型文」。段階5 設計書 §5.5） --}}
+        <div class="flex flex-col gap-5">
+            <div class="order-1">
+                @include('approvals.requests._amount_table_input')
+            </div>
+
+            {{-- 追加の入力欄（種類が使う欄だけ。使わない欄は押せなくして送らない。担当者・契約予定日は提出に必須。D2・D12） --}}
+            <div x-show="usesAny()" x-cloak class="order-2 grid grid-cols-1 sm:grid-cols-2 gap-4">
+                <div x-show="uses('tsubo')">
+                    <label for="tsubo" class="block text-[12px] font-semibold text-gray-700 mb-1">坪数</label>
+                    <div class="flex items-center gap-1.5">
+                        <input type="text" id="tsubo" name="tsubo" inputmode="decimal" placeholder="例: 38.5" :disabled="!uses('tsubo')" x-ref="extra_tsubo"
+                               value="{{ old('tsubo', $approvalRequest->tsubo) }}" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] text-right tabular-nums">
+                        <span class="text-[12px] text-gray-500">坪</span>
+                    </div>
+                </div>
+                <div x-show="uses('tsubo_price')">
+                    <label for="tsubo_price" class="block text-[12px] font-semibold text-gray-700 mb-1">坪単価</label>
+                    <div class="flex items-center gap-1.5">
+                        <input type="text" id="tsubo_price" name="tsubo_price" inputmode="numeric" placeholder="例: 1,083,000" :disabled="!uses('tsubo_price')" x-ref="extra_tsubo_price"
+                               value="{{ old('tsubo_price', $approvalRequest->tsubo_price === null ? '' : number_format($approvalRequest->tsubo_price)) }}" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] text-right tabular-nums">
+                        <span class="text-[12px] text-gray-500">円</span>
+                    </div>
+                </div>
+                <div x-show="uses('staff')">
+                    <label for="staff" class="block text-[12px] font-semibold text-gray-700 mb-1">担当者<span class="text-red-600 ml-0.5">*</span></label>
+                    <input type="text" id="staff" name="staff" maxlength="50" :disabled="!uses('staff')" x-ref="extra_staff"
+                           value="{{ old('staff', $approvalRequest->staff) }}" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
+                </div>
+                <div x-show="uses('contract_date')">
+                    <label for="contract_date" class="block text-[12px] font-semibold text-gray-700 mb-1">契約予定日<span class="text-red-600 ml-0.5">*</span></label>
+                    <input type="date" id="contract_date" name="contract_date" :disabled="!uses('contract_date')" x-ref="extra_contract_date"
+                           value="{{ old('contract_date', $approvalRequest->contract_date?->format('Y-m-d')) }}" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
+                </div>
+            </div>
+
+            {{-- 定型文（種類の固定の文。申請者は直さない。保存したときに申請に写す。D14） --}}
+            <div x-show="fixedText() !== ''" x-cloak class="order-3">
+                <p class="text-[12px] font-semibold text-gray-700 mb-1">定型文</p>
+                <p class="rounded-md border border-dashed border-gray-300 bg-gray-50 px-2.5 py-2 text-[13px] text-gray-700 whitespace-pre-wrap break-words" x-text="fixedText()"></p>
+            </div>
+
+            <div :class="isTable() ? 'order-4' : 'order-first'">
+                <label for="body" class="block text-[12px] font-semibold text-gray-700 mb-1">
+                    <span x-show="!isTable()">重点ポイント（5W2H）<span class="text-red-600 ml-0.5">*</span></span>
+                    <span x-show="isTable()" x-cloak>補足（自由記入）</span>
+                </label>
+                <textarea id="body" name="body" x-ref="body" rows="16" :rows="isTable() ? 4 : 16" maxlength="20000" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ old('body', $approvalRequest->body) }}</textarea>
+                <p x-show="!isTable()" class="text-[11px] text-gray-400 mt-1">種類を選ぶと見出しが入ります。見出しのままでは提出できません。「いつ」「いくら」は実施時期・金額の欄に書きます。</p>
+                <p x-show="isTable()" x-cloak class="text-[11px] text-gray-400 mt-1">任意。長さの制限はありません（紙の様式の 2 行の欄）。</p>
+            </div>
         </div>
 
         {{-- 関連する決裁No（10 個まで。候補は見られる申請の番号と件名。設計書 §5.6・D15） --}}
@@ -253,8 +331,25 @@ class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] disa
 <script>
 {{-- ⚠ Js::from を使う（@@json は構造の " を素のまま出す。Bug #23）。x-data にアロー関数を書かない（Top trap #4） --}}
 function approvalRequestForm() {
+    // 明細表の行の x-for の鍵
+    var rowSeq = 0;
+    var keyed = function (rows) {
+        return (rows || []).map(function (row) {
+            return { key: ++rowSeq, fixed: row.fixed, name: row.name, sale: row.sale, cost: row.cost };
+        });
+    };
+    var tableRows = {{ \Illuminate\Support\Js::from($tableRows) }};
+
     return {
         headings: {{ \Illuminate\Support\Js::from($typeHeadings) }},
+        // 種類ごとの本文の形・明細表の行の設定・件名の決まり文句・使う追加の欄・定型文（段階5。RequestController::formData）
+        types: {{ \Illuminate\Support\Js::from($typeConfigs) }},
+        typeId: {{ \Illuminate\Support\Js::from((string) old('type_id', $approvalRequest->type_id)) }},
+        rows: { upper: keyed(tableRows.upper), lower: keyed(tableRows.lower) },
+        // 件名（split＝前半＋決まり文句・direct＝1 行。送るのはいつも subjectText）
+        subjectText: {{ \Illuminate\Support\Js::from((string) old('subject', $approvalRequest->subject)) }},
+        subjectPrefix: '',
+        subjectMode: 'direct',
         numbers: {{ \Illuminate\Support\Js::from(array_values(old('related_numbers', $approvalRequest->related_numbers ?? []))) }},
         maxNumbers: {{ \App\Support\Approval\RelatedNumbers::MAX }},
         numberInput: '',
@@ -266,6 +361,9 @@ function approvalRequestForm() {
         // 関連する決裁No の形の誤り・数の上限（addNumber）
         numberError: '',
         pendingTypeId: null,
+        // 種類を選び直して保存すると消えるもの（{ from: 前の種類, labels: 名前の並び }。lostOnChange）
+        leaving: null,
+        extraLabels: {{ \Illuminate\Support\Js::from(\App\Support\Approval\RequestExtras::FIELDS) }},
         confirmSubmit: false,
         // 保存・提出の二度押し止め（Task 19 の C2）。1 回目で印を立て、2 回目からは送信を取り消す
         submitting: false,
@@ -284,6 +382,194 @@ function approvalRequestForm() {
             this.submitting = true;
         },
 
+        init: function () {
+            this.initSubject();
+        },
+
+        // ---- 種類の設定（段階5） ----
+        config: function () {
+            return this.types[this.typeId] || null;
+        },
+        isTable: function () {
+            var config = this.config();
+            return config !== null && config.form === 'table';
+        },
+        hasSubtotal: function () {
+            var config = this.config();
+            return config !== null && !!config.layout.subtotal;
+        },
+        suffix: function () {
+            var config = this.config();
+            return config === null ? '' : config.suffix;
+        },
+        uses: function (key) {
+            var config = this.config();
+            return config !== null && config.uses.indexOf(key) !== -1;
+        },
+        usesAny: function () {
+            var config = this.config();
+            return config !== null && config.uses.length > 0;
+        },
+        fixedText: function () {
+            var config = this.config();
+            return config === null ? '' : config.fixedText;
+        },
+
+        // ---- 件名の組み立て（要件 5.5.3） ----
+        // 決まり文句のある種類で、件名が空か決まり文句で終わっていれば前半と決まり文句に分けて出す（そうでなければ直接書く）
+        initSubject: function () {
+            var suffix = this.suffix();
+            if (suffix !== '' && (this.subjectText === '' || this.endsWith(this.subjectText, suffix))) {
+                this.subjectMode = 'split';
+                this.subjectPrefix = this.subjectText.slice(0, this.subjectText.length - suffix.length);
+                if (this.subjectText === '') {
+                    this.subjectPrefix = '';
+                }
+            } else {
+                this.subjectMode = 'direct';
+            }
+        },
+        endsWith: function (text, suffix) {
+            return text.length >= suffix.length && text.slice(text.length - suffix.length) === suffix;
+        },
+        // 前半が空なら件名も空（決まり文句だけの件名は作らない。台帳と PDF に施主名の無い件名が載る。提出は SubmitChecker も断る。点検の I-4）
+        composeSubject: function () {
+            this.subjectText = this.subjectPrefix.trim() === '' ? '' : this.subjectPrefix + this.suffix();
+        },
+        writeSubjectDirectly: function () {
+            this.subjectMode = 'direct';
+        },
+        useSuffix: function () {
+            var suffix = this.suffix();
+            this.subjectPrefix = this.endsWith(this.subjectText, suffix) ? this.subjectText.slice(0, this.subjectText.length - suffix.length) : this.subjectText;
+            this.subjectMode = 'split';
+            this.composeSubject();
+        },
+        // 種類を選び直したとき: 組み立てていれば新しい決まり文句で組み直す（決まり文句の無い種類なら、打った前半だけを残す）
+        subjectTypeChanged: function () {
+            var suffix = this.suffix();
+            if (this.subjectMode === 'split') {
+                if (suffix === '') {
+                    this.subjectMode = 'direct';
+                    this.subjectText = this.subjectPrefix;
+                } else {
+                    this.composeSubject();
+                }
+            } else if (suffix !== '' && this.subjectText === '') {
+                this.subjectMode = 'split';
+                this.subjectPrefix = '';
+                this.composeSubject();
+            }
+        },
+
+        // ---- 金額の明細表（App\Support\Approval\AmountTable と同じ式。保存する金額はサーバーが計算し直す） ----
+        // 数の入力を読む（App\Support\Approval\FormInput::digits と同じそろえ方。全角・カンマ・「円」・空白を落とし、マイナスの記号を - に、
+        // 先頭の + と 0 を落とす。数でなければ null）。15 桁を超える数は読まない（サーバーが「12 桁まで」で断る。BigInt に渡せない数を作らない）
+        parseAmount: function (value) {
+            var text = String(value === null || value === undefined ? '' : value).replace(/[０-９]/g, function (c) {
+                return String.fromCharCode(c.charCodeAt(0) - 0xFEE0);
+            });
+            text = text.replace(/[−ー‐―–—－]/g, '-').replace(/＋/g, '+').replace(/[,，円¥￥\s]/g, '');
+            var m = /^([+-]?)0*(\d{1,15})$/.exec(text);
+            if (m === null) {
+                return null;
+            }
+            var number = parseInt(m[2], 10);
+            return m[1] === '-' && number !== 0 ? -number : number;
+        },
+        yen: function (value) {
+            return value === null || value === undefined ? '' : value.toLocaleString('ja-JP') + '円';
+        },
+        // 粗利率（％・小数第 1 位。四捨五入は 0 から遠い方へ）。販売金額が 0 なら無い。
+        // ⚠ AmountTable::rate と同じく整数で計算する（浮動小数の割り算は 63.75% を 63.7499…% にする。点検の I-1）。
+        //   |粗利益| × 2,000 は Number の正確な整数の範囲を超えうるので BigInt で割る
+        rate: function (sale, profit) {
+            if (sale === 0) {
+                return null;
+            }
+            var s = BigInt(Math.abs(sale));
+            var tenths = Number((BigInt(Math.abs(profit)) * 2000n + s) / (s * 2n));
+            return ((profit < 0) !== (sale < 0) && tenths !== 0 ? -tenths : tenths) / 10;
+        },
+        // 「12.3%」（3 桁の区切りは付けない＝AmountTable::rateLabel と同じ）
+        rateLabel: function (rate) {
+            return rate === null ? '—' : rate.toFixed(1) + '%';
+        },
+        line: function (sale, cost) {
+            return { sale: sale, cost: cost, profit: sale - cost, rate: this.rate(sale, sale - cost) };
+        },
+        rowLine: function (row) {
+            var sale = this.parseAmount(row.sale);
+            var cost = this.parseAmount(row.cost);
+            if (sale === null && cost === null) {
+                return { profit: null, rate: null };
+            }
+            return this.line(sale || 0, cost || 0);
+        },
+        sums: function (section) {
+            var self = this;
+            var sale = 0;
+            var cost = 0;
+            this.rows[section].forEach(function (row) {
+                sale += self.parseAmount(row.sale) || 0;
+                cost += self.parseAmount(row.cost) || 0;
+            });
+            return [sale, cost];
+        },
+        subtotal: function () {
+            if (!this.hasSubtotal()) {
+                return null;
+            }
+            var upper = this.sums('upper');
+            return this.line(upper[0], upper[1]);
+        },
+        total: function () {
+            var upper = this.sums('upper');
+            var lower = this.sums('lower');
+            return this.line(upper[0] + lower[0], upper[1] + lower[1]);
+        },
+        // 欄を離れたらカンマ付きに整える（読めない値はそのまま。サーバーが理由を出す）
+        formatAmount: function (row, field) {
+            var value = this.parseAmount(row[field]);
+            if (value !== null) {
+                row[field] = value.toLocaleString('ja-JP');
+            }
+        },
+        addRow: function (section) {
+            this.rows[section].push({ key: ++rowSeq, fixed: null, name: '', sale: '', cost: '' });
+        },
+        removeRow: function (section, index) {
+            this.rows[section].splice(index, 1);
+        },
+        // 種類の行の設定に合わせて並べ直す（AmountTable::forForm と同じ規則。名前を設定した行は同じ名前の行の金額を当て、自由行の位置には
+        // 自由行を順に当て、残りは後ろへ。設定から名前が消えた行は、金額があれば自由行として残す＝黙って消さない。段階5 D16）
+        mergeRows: function (layout, rows) {
+            var merged = {};
+            ['upper', 'lower'].forEach(function (section) {
+                var names = layout[section] || [];
+                var fixed = {};
+                var free = [];
+                rows[section].forEach(function (row) {
+                    if (row.fixed !== null && names.indexOf(row.fixed) !== -1 && !fixed.hasOwnProperty(row.fixed)) {
+                        fixed[row.fixed] = row;
+                    } else if (row.fixed === null || String(row.sale).trim() !== '' || String(row.cost).trim() !== '') {
+                        free.push({ key: ++rowSeq, fixed: null, name: row.fixed !== null ? row.fixed : row.name, sale: row.sale, cost: row.cost });
+                    }
+                });
+                var out = [];
+                names.forEach(function (name) {
+                    if (name !== null) {
+                        var old = fixed[name];
+                        out.push({ key: ++rowSeq, fixed: name, name: '', sale: old ? old.sale : '', cost: old ? old.cost : '' });
+                    } else {
+                        out.push(free.length > 0 ? free.shift() : { key: ++rowSeq, fixed: null, name: '', sale: '', cost: '' });
+                    }
+                });
+                merged[section] = out.concat(free);
+            });
+            return merged;
+        },
+
         // 「戻る」で画面がそのまま戻ったとき（bfcache）に印を下ろす（Bug #65 と同じく persisted で絞らない）。
         // 編集の画面は画面の版（lock_version）が古くなっているので、押してもサーバーが断る（新しい申請の画面では、もう 1 件の下書きになる）
         resetSubmit: function () {
@@ -303,10 +589,25 @@ function approvalRequestForm() {
             return true;
         },
 
-        // 種類を選んだ: 本文が見出しのままなら入れ替え、書き始めていれば確かめる
+        // 種類を選んだ: 明細表と件名を新しい種類に合わせる。本文は、5W2H の種類なら見出しのままなら入れ替え、書き始めていれば確かめる。
+        // 明細表の種類なら、見出しのままの本文は空にする（補足になる。書いた本文は黙って消さずに補足に残す。段階5 D16）
         typeChanged: function (typeId) {
+            var lost = this.lostOnChange(typeId);
+            this.leaving = lost.length > 0 ? { from: this.typeId, labels: lost, subjectMode: this.subjectMode } : null;
+            this.typeId = typeId;
+            if (this.isTable()) {
+                this.rows = this.mergeRows(this.config().layout, this.rows);
+            }
+            this.subjectTypeChanged();
+
             var headings = this.headings[typeId];
             this.pendingTypeId = null;
+            if (this.isTable()) {
+                if (this.isBlankBody(this.$refs.body.value)) {
+                    this.$refs.body.value = '';
+                }
+                return;
+            }
             if (!headings) {
                 return;
             }
@@ -317,6 +618,43 @@ function approvalRequestForm() {
             this.pendingTypeId = typeId;
         },
 
+        // 種類を選び直すと保存で消えるもの（明細表の種類から 5W2H の種類へ移るときの明細表・新しい種類が使わない追加の欄の値。
+        // 保存のとき RequestFields が空にする。段階5 D16。点検の I-3）
+        lostOnChange: function (typeId) {
+            var self = this;
+            var from = this.config();
+            var to = this.types[typeId] || null;
+            var lost = [];
+            if (from === null) {
+                return lost;
+            }
+            var filled = function (row) {
+                return String(row.name || '').trim() !== '' || String(row.sale).trim() !== '' || String(row.cost).trim() !== '';
+            };
+            if (from.form === 'table' && (to === null || to.form !== 'table') && (this.rows.upper.some(filled) || this.rows.lower.some(filled))) {
+                lost.push('明細表');
+            }
+            from.uses.forEach(function (key) {
+                var input = self.$refs['extra_' + key];
+                if ((to === null || to.uses.indexOf(key) === -1) && input && String(input.value).trim() !== '') {
+                    lost.push(self.extraLabels[key]);
+                }
+            });
+            return lost;
+        },
+        // 選び直す前の種類に戻す（書いた明細表と欄は、保存するまで画面に残っている）。件名を前半と決まり文句で組み立てていたら、
+        // 決まり文句の無い種類で 1 行になった件名を組み立て直す
+        restoreType: function () {
+            var previous = this.leaving.from;
+            var composed = this.leaving.subjectMode === 'split';
+            this.$refs.typeSelect.value = previous;
+            this.typeChanged(previous);
+            if (composed) {
+                this.useSuffix();
+            }
+            this.leaving = null;
+        },
+
         replaceBody: function () {
             this.$refs.body.value = this.headings[this.pendingTypeId] || '';
             this.pendingTypeId = null;
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0006-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 のテストと、2a からの申請の画面のテスト・スマホの幅とはみ出しの走査）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/RequestFormTableTest.php tests/Feature/Approval/Phase2 tests/Feature/MobileLayoutTest.php 2>&1 | tail -3
```

Expected: `OK`（`test_the_script_calculates_like_the_server` が `skipped` なら node が無い。報告する）

- [ ] **Step 5: 全件を流す**

Expected: `OK (4009 tests, 37866 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Http/Controllers/Approval/RequestController.php resources/views/approvals/requests/_amount_table_input.blade.php resources/views/approvals/requests/_amount_table_sum.blade.php resources/views/approvals/requests/form.blade.php tests/Feature/Approval/Phase5/RequestFormTableTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 申請の画面に明細表・件名の組み立て・追加の欄・定型文を足す

明細表の種類では、明細表（その場で粗利益金額・粗利率・計・合計金額を
計算する。スマホは 1 行 1 枚のカード）・件名の前半と決まり文句の組み立て・
坪数などの追加の欄・定型文・補足を出す（段階5 設計書 §5.5・要件 5.5.6）。
種類を選び直したら行を並べ直し、保存で消えるものがあれば知らせて元の
種類に戻せる（D16）。計算はサーバーの AmountTable と同じ式。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 7: ③ 詳細・控え・変更点・提出の履歴に明細表と追加の欄

控えに明細表・追加の欄・定型文を足し、詳細・変更点・提出の履歴に出す。申請者以外には控えから出し、変更点の明細表は行の鍵で合わせる。差戻しの取り消しの指紋にも足す（設計書 §5.6・§10 の 3・D14・§0.7）。

**Files:**
- Create: `resources/views/approvals/requests/_body_section.blade.php`・`resources/views/approvals/requests/_amount_table_show.blade.php`
- Modify: `app/Support/Approval/RequestSnapshot.php`・`app/Support/Approval/RequestContent.php`・`app/Support/Approval/RequestExtras.php`・`app/Support/Approval/UndoTarget.php`・`resources/views/approvals/requests/_changes.blade.php`・`resources/views/approvals/requests/_history.blade.php`・`resources/views/approvals/requests/show.blade.php`
- Test: Create `tests/Feature/Approval/Phase5/RequestTableShowTest.php`

**Interfaces:**
- Consumes: Task 2 の `submittedRevision()`、Task 3 の `AmountTable::shownRows()`・`totals()`・`rowsOf()`・`rateLabel()`・`yen()`・`RequestExtras::valuesOf()`・`ordered()`・`display()`、2b の `LineDiff::lines()`
- Produces: 控えの `amount_table`・`extras`・`fixed_text`・`RequestSnapshot::changes()` の `table`（`list<{section, kind: changed|added|removed, before, after, changed: list<'sale'|'cost'>}>|null`）・`RequestSnapshot::currentFingerprint(ApprovalRequest): array`・`RequestContent` の `amountTable`・`extras`・`fixedText`・`usesTable()`・`RequestExtras::columnsOf(ApprovalRequest): array`・`RequestExtras::allOf(mixed): array`。Task 8 の PDF が `RequestContent` を読む

**差分の大きさ:** 10 ファイル・+606 / −11 行（差分のファイル `0007-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase5/RequestTableShowTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase5;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\ApprovalType;
use App\Support\Approval\RequestSnapshot;
use App\Support\Approval\UndoTarget;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** ③ 詳細・控え・変更点・提出の履歴の明細表と追加の欄（要件 4.4・5.5.4・5.5.5・段階5 設計書 §5.6・D14） */
class RequestTableShowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private function row(?string $name, bool $fixed, ?int $sale, ?int $cost): array
    {
        return ['name' => $name, 'fixed' => $fixed, 'sale' => $sale, 'cost' => $cost];
    }

    /** 9/17 の見本の中身で提出した申請（部門長確認中） */
    private function submittedContract(array $w, ApprovalType $type, array $attributes = []): ApprovalRequest
    {
        return $this->submittedFor($w, array_merge([
            'type_id' => $type->id, 'subject' => '山田様請負新築工事契約の件', 'body' => '仕様変更によるオプション工事を含む。',
            'amount' => 41700000, 'tsubo' => '38.5', 'tsubo_price' => 1083000, 'staff' => '佐藤 健一', 'contract_date' => '2026-10-20',
            'fixed_text' => '上記の内容に基づき、販売をおこないます。',
            'amount_table' => [
                'subtotal' => true,
                'upper'    => [
                    $this->row('工事請負金額', true, 28500000, 22000000),
                    $this->row('オプション工事', false, 1200000, 850000),
                    $this->row('紹介料', true, 0, 300000),
                ],
                'lower' => [$this->row('土地契約金額', true, 12000000, 10500000), $this->row(null, false, null, null)],
            ],
        ], $attributes));
    }

    /** 申請の中身の欄の文字（タグの境目を空白にしてタグを除き、空白を詰めたもの） */
    private function contentText(string $html): string
    {
        $start = strpos($html, '>申請の中身</h2>');
        $end   = strpos($html, '</section>', $start);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', substr($html, $start, $end - $start))), ENT_QUOTES, 'UTF-8')));
    }

    public function test_the_detail_shows_the_table_the_extras_the_fixed_text_and_the_supplement(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedContract($w, $this->housingContractType($w));

        foreach ([$w['applicant'], $w['head']] as $viewer) {
            $text = $this->contentText($this->actingAs($viewer)->get(route('approvals.requests.show', $request))->assertOk()->getContent());

            $this->assertStringContainsString('金額（税抜） 41,700,000円', $text);
            $this->assertStringContainsString(
                '金額の明細 項目 販売金額 工事原価 粗利益金額 粗利率'
                . ' 工事請負金額 販売金額 28,500,000円 工事原価 22,000,000円 粗利益金額 6,500,000円 粗利率 22.8%'
                . ' オプション工事 販売金額 1,200,000円 工事原価 850,000円 粗利益金額 350,000円 粗利率 29.2%'
                . ' 紹介料 販売金額 0円 工事原価 300,000円 粗利益金額 -300,000円 粗利率 —'
                . ' 計 販売金額 29,700,000円 工事原価 23,150,000円 粗利益金額 6,550,000円 粗利率 22.1%'
                . ' 土地契約金額 販売金額 12,000,000円 工事原価 10,500,000円 粗利益金額 1,500,000円 粗利率 12.5%'
                . ' 合計金額 販売金額 41,700,000円 工事原価 33,650,000円 粗利益金額 8,050,000円 粗利率 19.3%'
                . ' 坪数 38.5坪 坪単価 1,083,000円 担当者 佐藤 健一 契約予定日 2026/10/20'
                . ' 上記の内容に基づき、販売をおこないます。'
                . ' 補足 仕様変更によるオプション工事を含む。',
                $text,
                '明細表 → 追加の欄 → 定型文 → 補足の順（名前も金額も無い自由行は出さない）'
            );
            $this->assertStringNotContainsString('重点ポイント', $text);
        }
    }

    /** 控えには、明細表・提出したときに使っていた追加の欄・定型文が入る。あとで種類の設定を変えても、ほかの人の見る中身は変わらない（D14） */
    public function test_the_snapshot_keeps_what_was_submitted(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type    = $this->housingContractType($w, ['uses_tsubo' => false]);
        $request = $this->submittedContract($w, $type);

        $snapshot = ApprovalRevision::where('request_id', $request->id)->sole()->snapshot;
        $this->assertSame([28500000, 22000000], [$snapshot['amount_table']['upper'][0]['sale'], $snapshot['amount_table']['upper'][0]['cost']]);
        $this->assertTrue($snapshot['amount_table']['subtotal']);
        $this->assertEqualsCanonicalizing(['tsubo_price', 'staff', 'contract_date'], array_keys($snapshot['extras']), '使っていない坪数は控えない');
        $this->assertSame('2026-10-20', $snapshot['extras']['contract_date']);
        $this->assertSame('上記の内容に基づき、販売をおこないます。', $snapshot['fixed_text']);

        $type->update(['fixed_text' => '新しい定型文', 'uses_staff' => false, 'table_layout' => ['subtotal' => false, 'upper' => ['別の名前'], 'lower' => []]]);
        $text = $this->contentText($this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->getContent());
        $this->assertStringContainsString('担当者 佐藤 健一', $text);
        $this->assertStringContainsString('上記の内容に基づき、販売をおこないます。', $text);
        $this->assertStringContainsString(' 計 販売金額 29,700,000円', $text, '提出した申請は「計」の有無も提出したときのまま');
        $this->assertStringNotContainsString('新しい定型文', $text);
    }

    /** 差戻し中に申請者が明細表を直していても、ほかの人には最後に提出した明細表を出す */
    public function test_others_see_the_submitted_table_while_the_applicant_edits_it(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedContract($w, $this->housingContractType($w));
        app(Workflow::class)->judgeHead($request, $w['head'], $request->refresh()->lock_version, ApprovalStepResult::Return, '工事原価の根拠を');
        $edited = $request->fresh()->amount_table;
        $edited['upper'][0]['sale'] = 30000000;
        $request->fresh()->update(['amount_table' => $edited, 'staff' => '田中 次郎']);

        $others = $this->contentText($this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->getContent());
        $this->assertStringContainsString('工事請負金額 販売金額 28,500,000円', $others);
        $this->assertStringContainsString('担当者 佐藤 健一', $others);

        $own = $this->contentText($this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->getContent());
        $this->assertStringContainsString('工事請負金額 販売金額 30,000,000円', $own);
        $this->assertStringContainsString('担当者 田中 次郎', $own);
    }

    /** 出し直した申請の変更点に、明細表の行（変わった欄・増えた行）と追加の欄が出る（要件 4.4） */
    public function test_the_changes_show_the_rows_and_the_extras_that_changed(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedContract($w, $this->housingContractType($w));
        app(Workflow::class)->judgeHead($request, $w['head'], $request->refresh()->lock_version, ApprovalStepResult::Return, '工事原価の根拠を');

        $table = $request->fresh()->amount_table;
        $table['upper'][0]['sale'] = 29000000;
        $table['upper'][]          = $this->row('追加工事', false, 500000, 400000);
        $request->fresh()->update(['amount_table' => $table, 'staff' => '田中 次郎', 'amount' => 42700000]);
        app(Workflow::class)->submit($request->fresh(), $w['applicant']);

        $revisions = ApprovalRevision::where('request_id', $request->id)->orderBy('round')->get();
        $changes   = RequestSnapshot::changes($revisions[0]->snapshot, $revisions[1]->snapshot);
        $this->assertSame([
            ['label' => '金額（税抜）', 'before' => '41,700,000円', 'after' => '42,700,000円'],
            ['label' => '担当者', 'before' => '佐藤 健一', 'after' => '田中 次郎'],
        ], $changes['fields']);
        $this->assertSame([['upper', 'changed', ['sale']], ['upper', 'added', []]], array_map(fn (array $r) => [$r['section'], $r['kind'], $r['changed']], $changes['table']));
        $this->assertTrue(RequestSnapshot::hasChanges($changes));

        $html = $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()->getContent();
        $this->assertStringContainsString('明細表の前回との違い（＋ 増えた行・− 消えた行・変わった欄は前 → 後）', $html);
        // スマホでは 1 行 1 枚のカード（横スクロールにしない。要件 5.5.6。点検の M-9）
        $this->assertStringContainsString('<table class="block md:table w-full border-collapse text-[12px]">', $html);
        $this->assertStringNotContainsString('min-w-[560px]', $html);
        $this->assertMatchesRegularExpression('/<span class="sr-only">前: <\/span>28,500,000円<\/span>\s*<span[^>]*>→<\/span>\s*<span class="font-semibold text-emerald-800"><span class="sr-only">後: <\/span>29,000,000円<\/span>/u', $html);
        $this->assertMatchesRegularExpression('/<span class="sr-only">増えた行<\/span>.*?追加工事.*?500,000円.*?400,000円/su', $html);

        // 明細表の行が同じなら明細表の違いは出ない
        $same = RequestSnapshot::changes($revisions[1]->snapshot, $revisions[1]->snapshot);
        $this->assertNull($same['table']);
        $this->assertFalse(RequestSnapshot::hasChanges($same));
    }

    /** 真ん中の自由行を空にして出し直しても、変わっていない行は「消えた」と出ない（行の鍵〈名前を設定した行か・項目名〉で合わせる。点検の I-2） */
    public function test_the_changes_match_the_rows_by_their_names(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedContract($w, $this->housingContractType($w));
        app(Workflow::class)->judgeHead($request, $w['head'], $request->refresh()->lock_version, ApprovalStepResult::Return, '直してください');

        $table = $request->fresh()->amount_table;
        $table['upper'][1]         = $this->row(null, false, null, null);        // オプション工事の行を空にした（詳細に出ない行になる）
        $table['upper'][2]['cost'] = 350000;                                     // 紹介料の工事原価を直した
        $table['lower'][1]         = $this->row('造成', false, 800000, 700000);  // 後半の空の自由行に書いた
        $request->fresh()->update(['amount_table' => $table, 'amount' => 41300000]);
        app(Workflow::class)->submit($request->fresh(), $w['applicant']);

        $revisions = ApprovalRevision::where('request_id', $request->id)->orderBy('round')->get();
        $summary   = fn (array $changes): array => array_map(fn (array $r) => [$r['section'], $r['kind'], ($r['after'] ?? $r['before'])['name'], $r['changed']], $changes['table'] ?? []);
        $this->assertSame([
            ['upper', 'removed', 'オプション工事', []],
            ['upper', 'changed', '紹介料', ['cost']],
            ['lower', 'added', '造成', []],
        ], $summary(RequestSnapshot::changes($revisions[0]->snapshot, $revisions[1]->snapshot)));

        // 項目名を直した自由行は、消えた行と増えた行で出る
        $renamed = $revisions[1]->snapshot;
        $renamed['amount_table']['lower'][1]['name'] = '造成工事';
        $this->assertSame([['lower', 'removed', '造成', []], ['lower', 'added', '造成工事', []]], $summary(RequestSnapshot::changes($revisions[1]->snapshot, $renamed)));
    }

    /** 明細表の工事原価だけを直して出し直しても（金額は変わらない）、変更点が出る（要件 4.4） */
    public function test_a_change_of_only_the_table_is_shown_as_a_change(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedContract($w, $this->housingContractType($w));
        app(Workflow::class)->judgeHead($request, $w['head'], $request->refresh()->lock_version, ApprovalStepResult::Return, '土地の原価を確かめてください');

        $table = $request->fresh()->amount_table;
        $table['lower'][0]['cost'] = 10000000;
        $request->fresh()->update(['amount_table' => $table]);
        app(Workflow::class)->submit($request->fresh(), $w['applicant']);

        $revisions = ApprovalRevision::where('request_id', $request->id)->orderBy('round')->get();
        $changes   = RequestSnapshot::changes($revisions[0]->snapshot, $revisions[1]->snapshot);
        $this->assertSame([[], null], [$changes['fields'], $changes['body']], '金額・追加の欄・補足は変わっていない');
        $this->assertTrue(RequestSnapshot::hasChanges($changes));
        $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()->assertSee('明細表の前回との違い');
    }

    public function test_the_history_shows_the_table_of_each_round(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedContract($w, $this->housingContractType($w));
        app(Workflow::class)->judgeHead($request, $w['head'], $request->refresh()->lock_version, ApprovalStepResult::Return, '直してください');
        $table = $request->fresh()->amount_table;
        $table['upper'][0]['sale'] = 29000000;
        $request->fresh()->update(['amount_table' => $table, 'amount' => 42200000]);
        app(Workflow::class)->submit($request->fresh(), $w['applicant']);

        $html    = $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->getContent();
        $history = substr($html, strpos($html, '>提出の履歴</h2>'));
        $this->assertSame(1, substr_count($history, '28,500,000円'), '1 回目の明細表');
        $this->assertGreaterThanOrEqual(1, substr_count($history, '29,000,000円'), '2 回目の明細表');
    }

    /** 差戻しのあと申請者が明細表か追加の欄だけを直しても「直し始めた」に数える（差戻しの取り消しを断る。2b の D3） */
    public function test_an_edit_of_only_the_table_or_the_extras_counts_as_editing_since_the_return(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedContract($w, $this->housingContractType($w));
        app(Workflow::class)->judgeHead($request, $w['head'], $request->refresh()->lock_version, ApprovalStepResult::Return, '直してください');

        $this->assertFalse(UndoTarget::editedSinceReturn($request->fresh()), '何も直していない');

        $table = $request->fresh()->amount_table;
        $table['lower'][0]['cost'] = 10000000;
        $request->fresh()->update(['amount_table' => $table]);
        $this->assertTrue(UndoTarget::editedSinceReturn($request->fresh()), '明細表を直した');

        $table['lower'][0]['cost'] = 10500000;
        $request->fresh()->update(['amount_table' => $table]);
        $this->assertFalse(UndoTarget::editedSinceReturn($request->fresh()), '元に戻した');

        $request->fresh()->update(['contract_date' => '2026-11-01']);
        $this->assertTrue(UndoTarget::editedSinceReturn($request->fresh()), '契約予定日を直した');
    }

    /** 差戻しのあと管理者が種類の「使う」を変えただけなら、申請者が何も直していないので「直し始めた」にしない（取り消せる。点検の M-1） */
    public function test_a_change_of_the_type_settings_is_not_an_edit_since_the_return(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type    = $this->housingContractType($w, ['uses_tsubo' => false]);
        $request = $this->submittedContract($w, $type, ['tsubo' => null]);
        app(Workflow::class)->judgeHead($request, $w['head'], $request->refresh()->lock_version, ApprovalStepResult::Return, '直してください');

        $type->update(['uses_tsubo' => true]);
        $this->assertFalse(UndoTarget::editedSinceReturn($request->fresh()), '使う欄を足しただけ');
        $type->update(['uses_tsubo' => false, 'uses_tsubo_price' => false]);
        $this->assertFalse(UndoTarget::editedSinceReturn($request->fresh()), '使う欄を外しただけ');

        $request->fresh()->update(['tsubo_price' => 2000000]);
        $this->assertTrue(UndoTarget::editedSinceReturn($request->fresh()), '値を直せば直した');
    }

    /** 提出したあとで種類に欄を足しても、申請者本人の詳細にもほかの人と同じ欄だけが出る（D14。点検の M-5） */
    public function test_the_applicant_sees_the_submitted_extras_after_the_submission(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type    = $this->housingContractType($w, ['uses_tsubo' => false]);
        $request = $this->submittedContract($w, $type, ['tsubo' => null]);
        $type->update(['uses_tsubo' => true]);

        $own    = $this->contentText($this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->assertOk()->getContent());
        $others = $this->contentText($this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()->getContent());
        $this->assertStringContainsString('坪単価 1,083,000円', $own);
        $this->assertStringNotContainsString('坪数', $own, '回覧中は提出したときの欄');
        $this->assertStringNotContainsString('坪数', $others);

        // 差戻しで直せるようになったら、本人には種類が今使う欄が出る（これから入れる欄）
        app(Workflow::class)->judgeHead($request, $w['head'], $request->refresh()->lock_version, ApprovalStepResult::Return, '坪数も入れてください');
        $this->assertStringContainsString('坪数', $this->contentText($this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->getContent()));
    }

    /** 段階5 より前の控え（明細表・追加の欄・定型文のキーが無い）は今までどおり 5W2H の形で出す */
    public function test_an_old_snapshot_is_shown_as_before(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request  = $this->submittedFor($w);
        $revision = ApprovalRevision::where('request_id', $request->id)->sole();
        $old      = array_diff_key($revision->snapshot, array_flip(['amount_table', 'extras', 'fixed_text']));
        DB::table('approval_revisions')->where('id', $revision->id)->update(['snapshot' => json_encode($old, JSON_UNESCAPED_UNICODE)]);

        $text = $this->contentText($this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()->getContent());
        $this->assertStringContainsString('重点ポイント（5W2H） ■ なぜ（目的・理由） ・老朽化のため', $text);
        $this->assertStringNotContainsString('金額の明細', $text);
    }

    public function test_the_names_in_the_table_are_escaped(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $type    = $this->housingContractType($w, ['fixed_text' => '<b>定型</b>']);
        $request = $this->submittedFor($w, [
            'type_id' => $type->id, 'staff' => '<i>担当</i>', 'contract_date' => '2026-10-20', 'fixed_text' => '<b>定型</b>',
            'amount_table' => ['subtotal' => false, 'upper' => [$this->row('<script>alert(1)</script>', false, 1000, null)], 'lower' => []],
        ]);

        $html = $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString(e('<script>alert(1)</script>'), $html);
        $this->assertStringContainsString(e('<i>担当</i>'), $html);
        $this->assertStringContainsString(e('<b>定型</b>'), $html);
    }
}
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0007-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/RequestTableShowTest.php
```

Expected: `ERRORS!` `Tests: 12, Assertions: 23, Errors: 1, Failures: 10.`

- `RequestTableShowTest::test_the_snapshot_keeps_what_was_submitted` — `ErrorException: Undefined array key "amount_table"`
- `RequestTableShowTest::test_the_detail_shows_the_table_the_extras_the_fixed_text_and_the_supplement` — `明細表 → 追加の欄 → 定型文 → 補足の順（名前も金額も無い自由行は出さない）`
- `RequestTableShowTest::test_others_see_the_submitted_table_while_the_applicant_edits_it` — `Failed asserting that '>申請の中身 申請者 申請 花子 申請部門 住宅事業部 申請の種類 住宅の契約用（請負新築工事契約） 発信日 2026/10/07 決裁日 — 金額（税抜） 41,700,000円 実施時期 2026年10月 関連する決裁No — 重点ポイント（5W2H） 仕様変更によるオ`
- `RequestTableShowTest::test_the_changes_show_the_rows_and_the_extras_that_changed` — `Failed asserting that two arrays are identical.`
- `RequestTableShowTest::test_the_changes_match_the_rows_by_their_names` — `Failed asserting that two arrays are identical.`
- `RequestTableShowTest::test_a_change_of_only_the_table_is_shown_as_a_change` — `Failed asserting that false is true.`
- `RequestTableShowTest::test_the_history_shows_the_table_of_each_round` — `1 回目の明細表`
- `RequestTableShowTest::test_an_edit_of_only_the_table_or_the_extras_counts_as_editing_since_the_return` — `明細表を直した`
- `RequestTableShowTest::test_a_change_of_the_type_settings_is_not_an_edit_since_the_return` — `値を直せば直した`
- `RequestTableShowTest::test_the_applicant_sees_the_submitted_extras_after_the_submission` — `Failed asserting that '>申請の中身 申請者 申請 花子 申請部門 住宅事業部 申請の種類 住宅の契約用（請負新築工事契約） 発信日 2026/10/07 決裁日 — 金額（税抜） 41,700,000円 実施時期 2026年10月 関連する決裁No — 重点ポイント（5W2H） 仕様変更によるオ`
- `RequestTableShowTest::test_the_names_in_the_table_are_escaped` — `Failed asserting that '<!DOCTYPE html>\n`

- [ ] **Step 3: 控え・詳細・変更点・履歴に足す**

`app/Support/Approval/RequestExtras.php`（変更）

```diff
--- a/app/Support/Approval/RequestExtras.php
+++ b/app/Support/Approval/RequestExtras.php
@@ -53,10 +53,20 @@ public static function usedBy(?ApprovalType $type): array
      * @return array<string, string|int|null>
      */
     public static function valuesOf(ApprovalRequest $request, ?ApprovalType $type): array
+    {
+        return array_intersect_key(self::columnsOf($request), array_flip(self::usedBy($type)));
+    }
+
+    /**
+     * 申請の 4 つの列の今の値（種類の設定で絞らない。控えと同じ形＝日付は Y-m-d・坪数は文字・坪単価は数）
+     *
+     * @return array<string, string|int|null>
+     */
+    public static function columnsOf(ApprovalRequest $request): array
     {
         $values = [];
-        foreach (self::usedBy($type) as $key) {
-            $value = $request->getAttribute($key);
+        foreach (array_keys(self::FIELDS) as $key) {
+            $value        = $request->getAttribute($key);
             $values[$key] = $value instanceof CarbonInterface ? $value->format('Y-m-d') : $value;
         }
 
@@ -82,6 +92,17 @@ public static function ordered(mixed $extras): array
         return $values;
     }
 
+    /**
+     * 控えの値を 4 つの欄すべての形にする（控えに無い欄は空。差戻しの取り消しの指紋）
+     *
+     * @param mixed $extras 控えの `extras`（無ければ空）
+     * @return array<string, string|int|null>
+     */
+    public static function allOf(mixed $extras): array
+    {
+        return array_merge(array_fill_keys(array_keys(self::FIELDS), null), self::ordered($extras));
+    }
+
     /** 表示（坪数「38.5坪」・坪単価「1,083,000円」・担当者はそのまま・契約予定日「2026/10/20」。空なら null） */
     public static function display(string $key, mixed $value): ?string
     {
```

`app/Support/Approval/RequestSnapshot.php`（変更）

```diff
--- a/app/Support/Approval/RequestSnapshot.php
+++ b/app/Support/Approval/RequestSnapshot.php
@@ -9,7 +9,9 @@
  * 提出ごとの中身の控え（設計書 §5.11）と、控えどうしの比べ方（2b の変更点と履歴・差戻しの取り消し。§5.13・§5.14）。
  *
  * ⚠ 名前（種類名・部門名・ファイル名）も一緒に控える。あとで種類や部門の名前が変わっても、
- *   その回に出した中身のまま見せるため。段階5 で明細表の行と追加の入力欄を足す。
+ *   その回に出した中身のまま見せるため。
+ * ⚠ 段階5: 明細表（`amount_table`。明細表の種類だけ・5W2H の種類は null）・追加の入力欄（`extras`。提出したときに種類が使っていた
+ *   欄だけ。キーは RequestExtras::FIELDS）・定型文（`fixed_text`）も控える。段階5 より前の控えにはこれらのキーが無い（無ければ空として読む）。
  * ⚠ 控えは JSON の列。MySQL は JSON のオブジェクトのキーを並べ替えて返す（キーの長さの順）ので、
  *   控えを丸ごと `==`・`===` で比べない。決まったキーを取り出して比べる（§5.16）。
  */
@@ -31,6 +33,9 @@ public static function make(ApprovalRequest $request): array
             'attachments'     => $request->attachments()->get()
                 ->map(fn (ApprovalAttachment $a) => ['id' => $a->id, 'name' => $a->original_name, 'size' => $a->size])
                 ->all(),
+            'amount_table'    => $request->amount_table,
+            'extras'          => RequestExtras::valuesOf($request, $request->type),
+            'fixed_text'      => $request->fixed_text,
         ];
     }
 
@@ -40,12 +45,14 @@ public static function make(ApprovalRequest $request): array
      * - 種類・申請部門は id で比べ、名前を出す（管理者があとで名前を変えただけなら変わっていない）
      * - 関連する決裁No は並びを無視して比べる（並べ替えただけなら変わっていない）
      * - 本文は行ごとの差（LineDiff）。添付は id で足したもの・外したもの
+     * - 追加の入力欄（坪数・坪単価・担当者・契約予定日）は件名などと同じ並び。明細表は行ごと（tableChanges。段階5 §5.6）
      *
      * @param array<string, mixed> $before
      * @param array<string, mixed> $after
      * @return array{
      *     fields: list<array{label: string, before: string, after: string}>,
      *     body: list<array{type: string, line: string}>|null,
+     *     table: list<array{section: string, kind: string, before: ?array, after: ?array, changed: list<string>}>|null,
      *     attachments_added: list<array{id: int, name: string, size: int}>,
      *     attachments_removed: list<array{id: int, name: string, size: int}>
      * }
@@ -74,6 +81,15 @@ public static function changes(array $before, array $after): array
         if (self::sorted($numbersBefore) !== self::sorted($numbersAfter)) {
             $fields[] = ['label' => '関連する決裁No', 'before' => self::text(implode('・', $numbersBefore)), 'after' => self::text(implode('・', $numbersAfter))];
         }
+        $extrasBefore = RequestExtras::ordered($before['extras'] ?? null);
+        $extrasAfter  = RequestExtras::ordered($after['extras'] ?? null);
+        foreach (RequestExtras::FIELDS as $key => $label) {
+            $was = RequestExtras::display($key, $extrasBefore[$key] ?? null);
+            $now = RequestExtras::display($key, $extrasAfter[$key] ?? null);
+            if ($was !== $now) {
+                $fields[] = ['label' => $label, 'before' => self::text($was), 'after' => self::text($now)];
+            }
+        }
 
         $body = LineDiff::text($before['body'] ?? null, $after['body'] ?? null);
 
@@ -83,6 +99,7 @@ public static function changes(array $before, array $after): array
         return [
             'fields'              => $fields,
             'body'                => LineDiff::hasChanges($body) ? $body : null,
+            'table'               => self::tableChanges($before['amount_table'] ?? null, $after['amount_table'] ?? null),
             'attachments_added'   => array_values(array_diff_key($attachmentsAfter, $attachmentsBefore)),
             'attachments_removed' => array_values(array_diff_key($attachmentsBefore, $attachmentsAfter)),
         ];
@@ -91,10 +108,49 @@ public static function changes(array $before, array $after): array
     /** changes() の結果に変わったものがあるか */
     public static function hasChanges(array $changes): bool
     {
-        return $changes['fields'] !== [] || $changes['body'] !== null
+        return $changes['fields'] !== [] || $changes['body'] !== null || ($changes['table'] ?? null) !== null
             || $changes['attachments_added'] !== [] || $changes['attachments_removed'] !== [];
     }
 
+    /**
+     * 明細表の行ごとの違い（要件 4.4。項目・販売金額・工事原価）。前半・後半ごとに、詳細に出す行（名前も金額も無い自由行を除く）を
+     * **行の鍵（名前を設定した行か・項目名）で合わせる**（本文と同じ最長共通部分列＝LineDiff。真ん中の自由行を消したり空にしたりしても、
+     * 変わっていないほかの行は「消えた」と出ない。点検の I-2）。同じ鍵の行は販売金額と工事原価を比べ、合わない行は増えた行・消えた行。
+     * 項目名を直した自由行は、消えた行と増えた行で出る。変わった行・増えた行・消えた行だけを返す
+     *
+     * @param mixed $before 前の回の明細表（無ければ空）
+     * @param mixed $after  今の回の明細表
+     * @return list<array{section: string, kind: string, before: ?array, after: ?array, changed: list<string>}>|null
+     */
+    private static function tableChanges(mixed $before, mixed $after): ?array
+    {
+        $key  = fn (array $row): string => ($row['fixed'] ? 'F:' : 'N:') . ($row['name'] ?? '');
+        $rows = [];
+        foreach (AmountTable::SECTIONS as $section) {
+            $was = AmountTable::shownRows(is_array($before) ? $before : [], $section);
+            $now = AmountTable::shownRows(is_array($after) ? $after : [], $section);
+            $i   = 0;
+            $j   = 0;
+
+            foreach (LineDiff::lines(array_map($key, $was), array_map($key, $now)) as $op) {
+                if ($op['type'] === LineDiff::REMOVED) {
+                    $rows[] = ['section' => $section, 'kind' => 'removed', 'before' => $was[$i++], 'after' => null, 'changed' => []];
+                } elseif ($op['type'] === LineDiff::ADDED) {
+                    $rows[] = ['section' => $section, 'kind' => 'added', 'before' => null, 'after' => $now[$j++], 'changed' => []];
+                } else {
+                    $old     = $was[$i++];
+                    $new     = $now[$j++];
+                    $changed = array_values(array_filter(['sale', 'cost'], fn (string $field) => $old[$field] !== $new[$field]));
+                    if ($changed !== []) {
+                        $rows[] = ['section' => $section, 'kind' => 'changed', 'before' => $old, 'after' => $new, 'changed' => $changed];
+                    }
+                }
+            }
+        }
+
+        return $rows === [] ? null : $rows;
+    }
+
     /**
      * 申請者が直せる中身の指紋（差戻しの取り消し D3 で「差戻しのあと中身か添付を直し始めたか」を比べる。§5.14・§5.16）。
      *
@@ -119,9 +175,26 @@ public static function editableFingerprint(array $snapshot): array
             'body'            => self::stringOrNull($snapshot['body'] ?? null),
             'related_numbers' => self::numbers($snapshot),
             'attachment_ids'  => $attachmentIds,
+            // 段階5: 明細表（行の並びと中身。キーの並びは rowsOf でそろえる）と追加の入力欄（値は文字にそろえる）。
+            // 定型文は申請者が直すものではない（保存のときに種類から写す）ので入れない
+            'amount_table'    => array_map(fn (string $section) => AmountTable::rowsOf(is_array($snapshot['amount_table'] ?? null) ? $snapshot['amount_table'] : null, $section), AmountTable::SECTIONS),
+            // 追加の欄は 4 つとも（無い欄は空）。種類の「使う」の設定で欄が増えたり減ったりしても、値が同じなら同じ指紋（点検の M-1）
+            'extras'          => array_map(fn (mixed $value) => $value === null || $value === '' ? null : (string) $value, RequestExtras::allOf($snapshot['extras'] ?? null)),
         ];
     }
 
+    /**
+     * 今の中身（申請の行と今の添付）の指紋（差戻しの取り消しで、控えの指紋と比べる）。追加の欄は種類の今の設定で絞らずに 4 つの列を
+     * そのまま入れる（差戻しのあと管理者が種類の「使う」を変えても、申請者が何も直していなければ控えと同じ指紋になる。使わない欄は
+     * 保存のときに空になっているので、控えに無い欄は空どうしで合う。点検の M-1）
+     *
+     * @return array<string, mixed>
+     */
+    public static function currentFingerprint(ApprovalRequest $request): array
+    {
+        return self::editableFingerprint(['extras' => RequestExtras::columnsOf($request)] + self::make($request));
+    }
+
     private static function idOf(array $snapshot, string $key): ?int
     {
         return self::intOrNull($snapshot[$key]['id'] ?? null);
```

`app/Support/Approval/UndoTarget.php`（変更）

```diff
--- a/app/Support/Approval/UndoTarget.php
+++ b/app/Support/Approval/UndoTarget.php
@@ -72,7 +72,7 @@ public static function editedSinceReturn(ApprovalRequest $request): bool
             return true;
         }
 
-        return RequestSnapshot::editableFingerprint(RequestSnapshot::make($request))
+        return RequestSnapshot::currentFingerprint($request)
             !== RequestSnapshot::editableFingerprint($revision->snapshot);
     }
 }
```

`app/Support/Approval/RequestContent.php`（変更）

```diff
--- a/app/Support/Approval/RequestContent.php
+++ b/app/Support/Approval/RequestContent.php
@@ -10,7 +10,7 @@
 use Illuminate\Support\Collection;
 
 /**
- * 詳細の画面に出す申請の中身（種類・申請部門・件名・金額・実施時期・本文・関連する決裁No・添付）。
+ * 詳細の画面に出す申請の中身（種類・申請部門・件名・金額・実施時期・本文・関連する決裁No・添付・段階5 の明細表・追加の入力欄・定型文）。
  *
  * **申請者以外には、状態を問わず最後に提出した控え（`approval_revisions` の最新の回）を出す**
  * （利用者の決定 2026-09-27。要件 4.8「作成中は申請者だけが見える」）。
@@ -20,6 +20,9 @@
  *   種類と部門の名前だけは、提出したときの名前が出る（控えは名前も残す。RequestSnapshot）
  * 申請者本人は、いつも今の中身（編集中のもの）を見る。
  *
+ * 明細表があれば明細表の種類の申請（本文は補足）。追加の入力欄は、ほかの人には提出したときに種類が使っていた欄（控えの extras）、
+ * 申請者本人には直せる間は種類が今使う欄・提出したあとは控えと同じ欄（どちらも値の入った欄は出す。currentExtras）。
+ *
  * ⚠ 見てよいかどうか（RequestVisibility）は別。ここは「見られる」前提で、どの版の中身を見せるかだけを決める。
  * ⚠ 添付を開く・ダウンロードする経路も mayOpen() で同じ出し分けをする（Task 14）。
  */
@@ -29,6 +32,8 @@ final class RequestContent
      * @param list<string>                        $relatedNumbers
      * @param Collection<int, ApprovalAttachment> $attachments
      * @param bool                                $isLastSubmission 最後に提出した控えから作った（申請者以外が見るとき）
+     * @param array<string, mixed>|null           $amountTable      明細表（AmountTable の形。明細表の種類だけ）
+     * @param array<string, string|int|null>      $extras           追加の入力欄（キー => 値。RequestExtras::FIELDS の並び）
      */
     private function __construct(
         public readonly ?string $typeName,
@@ -40,6 +45,9 @@ private function __construct(
         public readonly array $relatedNumbers,
         public readonly Collection $attachments,
         public readonly bool $isLastSubmission,
+        public readonly ?array $amountTable = null,
+        public readonly array $extras = [],
+        public readonly ?string $fixedText = null,
     ) {
     }
 
@@ -70,9 +78,28 @@ private static function current(ApprovalRequest $request): self
             relatedNumbers: $request->related_numbers ?? [],
             attachments: $request->attachments,
             isLastSubmission: false,
+            amountTable: $request->amount_table,
+            extras: self::currentExtras($request),
+            fixedText: $request->fixed_text,
         );
     }
 
+    /**
+     * 申請者本人に出す追加の入力欄。直せる間（下書き・差戻し中）は種類が今使う欄（これから入れる欄）、提出したあとは提出したときに
+     * 種類が使っていた欄（控えの extras のキー。あとで管理者が種類の「使う」を変えても、本人とほかの人で出る欄が同じ。D14。点検の M-5）。
+     * どちらも値の入った欄は出す（入れた値は見える）
+     *
+     * @return array<string, string|int|null>
+     */
+    private static function currentExtras(ApprovalRequest $request): array
+    {
+        $shown = $request->status->isEditable()
+            ? RequestExtras::usedBy($request->type)
+            : array_keys(RequestExtras::ordered($request->submittedRevision()?->snapshot['extras'] ?? null));
+
+        return array_filter(RequestExtras::columnsOf($request), fn (mixed $value, string $key) => $value !== null || in_array($key, $shown, true), ARRAY_FILTER_USE_BOTH);
+    }
+
     /** 最後に提出した控えの中身。添付は控えに入った行を引く（外したあとも行とファイルは残る。計画 §0.5。並びは控えと同じ id の順） */
     private static function fromRevision(ApprovalRequest $request, ApprovalRevision $revision): self
     {
@@ -89,9 +116,18 @@ private static function fromRevision(ApprovalRequest $request, ApprovalRevision
             relatedNumbers: $snapshot['related_numbers'] ?? [],
             attachments: ApprovalAttachment::where('request_id', $request->id)->whereKey($ids)->orderBy('id')->get(),
             isLastSubmission: true,
+            amountTable: is_array($snapshot['amount_table'] ?? null) ? $snapshot['amount_table'] : null,
+            extras: RequestExtras::ordered($snapshot['extras'] ?? null),
+            fixedText: $snapshot['fixed_text'] ?? null,
         );
     }
 
+    /** 明細表の種類の申請か（明細表があれば。本文は補足） */
+    public function usesTable(): bool
+    {
+        return $this->amountTable !== null;
+    }
+
     /** 金額の表示（税抜・末尾に「円」。ApprovalRequest::amountLabel() と同じ形。規約: `¥` 接頭辞 NG） */
     public function amountLabel(): ?string
     {
```

`resources/views/approvals/requests/_amount_table_show.blade.php`（新規）

```blade
{{-- 金額の明細表の表示（申請の詳細・提出の履歴。要件 5.5.4・5.5.6・段階5 設計書 §5.6）。$table は AmountTable の形（申請の列か控え）。
     名前も金額も無い自由行は出さない（AmountTable::shownRows）。スマホ（md 未満）は 1 行 1 枚のカード（表の要素を block に。横スクロールにしない）。
     金額は「28,500,000円」の形（規約: ¥ を付けない）。粗利率は小数第 1 位・販売金額が 0 なら「—」 --}}
@php
    $amountTable = \App\Support\Approval\AmountTable::class;
    $totals      = $amountTable::totals($table);
    $cell        = 'flex md:table-cell items-center justify-between gap-2 px-1 md:px-2 py-1 md:py-1.5 md:border-b md:border-gray-200 text-right tabular-nums';
@endphp
<div class="overflow-x-auto">
    <table class="block md:table w-full border-collapse text-[13px]">
        <thead class="hidden md:table-header-group">
            <tr>
                <th scope="col" class="px-2 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300">項目</th>
                <th scope="col" class="px-2 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300">販売金額</th>
                <th scope="col" class="px-2 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300">工事原価</th>
                <th scope="col" class="px-2 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300">粗利益金額</th>
                <th scope="col" class="px-2 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300">粗利率</th>
            </tr>
        </thead>
        @foreach(['upper', 'lower'] as $section)
            <tbody class="block md:table-row-group">
                @foreach($amountTable::shownRows($table, $section) as $row)
                    <tr class="block md:table-row border border-gray-300 rounded-md md:border-0 md:rounded-none mb-2 md:mb-0 px-2 py-1 md:p-0">
                        <td class="block md:table-cell px-1 md:px-2 py-1.5 md:border-b md:border-gray-200 {{ $row['fixed'] ? 'font-semibold text-gray-900' : 'text-gray-900' }} break-words">{{ $row['name'] ?? '（項目名なし）' }}</td>
                        <td class="{{ $cell }}"><span class="md:hidden text-[11px] text-gray-500">販売金額</span><span>{{ $amountTable::yen($row['sale']) }}</span></td>
                        <td class="{{ $cell }}"><span class="md:hidden text-[11px] text-gray-500">工事原価</span><span>{{ $amountTable::yen($row['cost']) }}</span></td>
                        <td class="{{ $cell }} bg-slate-50"><span class="md:hidden text-[11px] text-gray-500">粗利益金額</span><span>{{ $amountTable::yen($row['profit']) }}</span></td>
                        <td class="{{ $cell }} bg-slate-50"><span class="md:hidden text-[11px] text-gray-500">粗利率</span><span>{{ $row['profit'] === null ? '' : $amountTable::rateLabel($row['rate']) }}</span></td>
                    </tr>
                @endforeach
                @php $sum = $section === 'upper' ? $totals['subtotal'] : $totals['total']; @endphp
                @if($sum !== null)
                    <tr class="block md:table-row border-2 {{ $section === 'upper' ? 'border-gray-300' : 'border-emerald-600' }} rounded-md md:border-0 md:rounded-none mb-2 md:mb-0 px-2 py-1 md:p-0 bg-slate-100 font-semibold">
                        <td class="block md:table-cell px-1 md:px-2 py-1.5 md:border-b md:border-gray-300">{{ $section === 'upper' ? '計' : '合計金額' }}</td>
                        <td class="{{ $cell }}"><span class="md:hidden text-[11px] text-gray-500">販売金額</span><span>{{ $amountTable::yen($sum['sale']) }}</span></td>
                        <td class="{{ $cell }}"><span class="md:hidden text-[11px] text-gray-500">工事原価</span><span>{{ $amountTable::yen($sum['cost']) }}</span></td>
                        <td class="{{ $cell }}"><span class="md:hidden text-[11px] text-gray-500">粗利益金額</span><span>{{ $amountTable::yen($sum['profit']) }}</span></td>
                        <td class="{{ $cell }}"><span class="md:hidden text-[11px] text-gray-500">粗利率</span><span>{{ $amountTable::rateLabel($sum['rate']) }}</span></td>
                    </tr>
                @endif
            </tbody>
        @endforeach
    </table>
</div>
```

`resources/views/approvals/requests/_body_section.blade.php`（新規）

```blade
{{-- 申請の本文の欄（申請の詳細・提出の履歴で共有。段階5 設計書 §5.6）。
     明細表の種類（$table が在る）: 明細表 → 追加の入力欄 → 定型文 → 補足（紙の住宅の様式の「（記）」の並び）。
     5W2H の種類: 重点ポイント → 追加の入力欄 → 定型文（定型文と追加の欄は、種類で使うときだけ）。
     $table: 明細表（AmountTable の形）か null ／ $extras: 追加の入力欄（キー => 値。RequestExtras::FIELDS の並び）／ $fixedText ／ $body --}}
<div class="space-y-4">
    @if($table !== null)
        <div>
            <p class="text-[12px] font-semibold text-gray-500 mb-1.5">金額の明細</p>
            @include('approvals.requests._amount_table_show', ['table' => $table])
        </div>
    @else
        <div>
            <p class="text-[12px] font-semibold text-gray-500 mb-1.5">重点ポイント（5W2H）</p>
            <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-[13px] text-gray-900 leading-relaxed whitespace-pre-wrap break-words">{{ $body }}</div>
        </div>
    @endif

    @if($extras !== [])
        <dl class="grid grid-cols-1 sm:grid-cols-[9em_1fr] gap-x-4 gap-y-2 text-[13px]">
            @foreach($extras as $key => $value)
                <dt class="text-gray-500">{{ \App\Support\Approval\RequestExtras::FIELDS[$key] }}</dt>
                <dd class="text-gray-900 break-words">{{ \App\Support\Approval\RequestExtras::display($key, $value) ?? '—' }}</dd>
            @endforeach
        </dl>
    @endif

    @if(($fixedText ?? '') !== '')
        <p class="rounded-md border border-dashed border-gray-300 bg-gray-50 px-4 py-2 text-[13px] text-gray-700 whitespace-pre-wrap break-words">{{ $fixedText }}</p>
    @endif

    @if($table !== null)
        <div>
            <p class="text-[12px] font-semibold text-gray-500 mb-1.5">補足</p>
            <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-[13px] text-gray-900 leading-relaxed whitespace-pre-wrap break-words">{{ ($body ?? '') !== '' ? $body : '—' }}</div>
        </div>
    @endif
</div>
```

`resources/views/approvals/requests/show.blade.php`（変更）

```diff
--- a/resources/views/approvals/requests/show.blade.php
+++ b/resources/views/approvals/requests/show.blade.php
@@ -80,9 +80,9 @@ class="ml-auto inline-flex items-center gap-1 px-3 py-1.5 border border-gray-300
                 @endforelse
             </dd>
         </dl>
+        {{-- 本文の欄（明細表の種類は明細表・追加の欄・定型文・補足。段階5 §5.6。申請者以外には控えの中身＝RequestContent） --}}
         <div class="px-5 pb-5">
-            <p class="text-[12px] font-semibold text-gray-500 mb-1.5">重点ポイント（5W2H）</p>
-            <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-[13px] text-gray-900 leading-relaxed whitespace-pre-wrap break-words">{{ $content->body }}</div>
+            @include('approvals.requests._body_section', ['table' => $content->amountTable, 'extras' => $content->extras, 'fixedText' => $content->fixedText, 'body' => $content->body])
         </div>
     </section>
```

`resources/views/approvals/requests/_changes.blade.php`（変更）

```diff
--- a/resources/views/approvals/requests/_changes.blade.php
+++ b/resources/views/approvals/requests/_changes.blade.php
@@ -25,7 +25,8 @@
 
                 @if($changes['body'] !== null)
                     <div>
-                        <p class="text-[12px] font-semibold text-gray-500 mb-1.5">重点ポイント（5W2H）の前回との違い（＋ 増えた行・− 消えた行）</p>
+                        {{-- 明細表の種類の本文は補足（段階5） --}}
+                        <p class="text-[12px] font-semibold text-gray-500 mb-1.5">{{ $content->usesTable() ? '補足' : '重点ポイント（5W2H）' }}の前回との違い（＋ 増えた行・− 消えた行）</p>
                         <ol class="rounded-md border border-gray-200 overflow-hidden leading-relaxed">
                             @foreach($changes['body'] as $op)
                                 @switch($op['type'])
@@ -43,6 +44,60 @@
                     </div>
                 @endif
 
+                {{-- 明細表の行ごとの違い（要件 4.4。項目・販売金額・工事原価。行の鍵〈名前を設定した行か・項目名〉で合わせる＝RequestSnapshot::tableChanges。
+                     段階5 §5.6）。スマホ（md 未満）は明細表と同じく 1 行 1 枚のカード（横スクロールにしない。要件 5.5.6。点検の M-9） --}}
+                @if(($changes['table'] ?? null) !== null)
+                    <div>
+                        <p class="text-[12px] font-semibold text-gray-500 mb-1.5">明細表の前回との違い（＋ 増えた行・− 消えた行・変わった欄は前 → 後）</p>
+                        <div class="overflow-x-auto">
+                            <table class="block md:table w-full border-collapse text-[12px]">
+                                <thead class="hidden md:table-header-group">
+                                    <tr>
+                                        @foreach(['', '項目', '販売金額', '工事原価'] as $heading)
+                                            <th scope="col" class="px-2 py-1.5 text-left font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">{{ $heading }}</th>
+                                        @endforeach
+                                    </tr>
+                                </thead>
+                                <tbody class="block md:table-row-group">
+                                    @foreach($changes['table'] as $tableRow)
+                                        @php
+                                            $values = fn (?array $row, string $key): string => $row === null ? '' : ($key === 'name' ? ($row['name'] ?? '（項目名なし）') : \App\Support\Approval\AmountTable::yen($row[$key]));
+                                        @endphp
+                                        <tr class="block md:table-row border border-gray-200 rounded-md md:border-0 md:rounded-none mb-2 md:mb-0 px-2 py-1 md:p-0 {{ $tableRow['kind'] === 'added' ? 'bg-emerald-50 text-emerald-800' : ($tableRow['kind'] === 'removed' ? 'bg-red-50 text-red-700' : 'text-gray-800') }}">
+                                            <td class="block md:table-cell px-1 md:px-2 py-1 md:border-b md:border-gray-100 whitespace-nowrap">
+                                                @if($tableRow['kind'] === 'added')
+                                                    <span aria-hidden="true">＋</span><span class="sr-only">増えた行</span>
+                                                @elseif($tableRow['kind'] === 'removed')
+                                                    <span aria-hidden="true">−</span><span class="sr-only">消えた行</span>
+                                                @else
+                                                    <span class="sr-only">変わった行</span>
+                                                @endif
+                                                <span class="text-[11px] text-gray-500">{{ $tableRow['section'] === 'upper' ? '前半' : '後半' }}</span>
+                                            </td>
+                                            @foreach(['name' => '項目', 'sale' => '販売金額', 'cost' => '工事原価'] as $key => $label)
+                                                <td class="flex md:table-cell items-baseline justify-between gap-2 px-1 md:px-2 py-1 md:border-b md:border-gray-100 break-words {{ $key === 'name' ? '' : 'tabular-nums' }}">
+                                                    <span class="md:hidden text-[11px] text-gray-500 shrink-0">{{ $label }}</span>
+                                                    <span class="text-right md:text-left">
+                                                    @if($tableRow['kind'] === 'removed')
+                                                        <span class="line-through">{{ $values($tableRow['before'], $key) }}</span>
+                                                    @elseif($tableRow['kind'] === 'changed' && in_array($key, $tableRow['changed'], true))
+                                                        <span class="text-red-700 line-through"><span class="sr-only">前: </span>{{ $values($tableRow['before'], $key) ?: '（なし）' }}</span>
+                                                        <span class="mx-0.5 text-gray-400" aria-hidden="true">→</span>
+                                                        <span class="font-semibold text-emerald-800"><span class="sr-only">後: </span>{{ $values($tableRow['after'], $key) ?: '（なし）' }}</span>
+                                                    @else
+                                                        {{ $values($tableRow['after'], $key) }}
+                                                    @endif
+                                                    </span>
+                                                </td>
+                                            @endforeach
+                                        </tr>
+                                    @endforeach
+                                </tbody>
+                            </table>
+                        </div>
+                    </div>
+                @endif
+
                 @if($changes['attachments_added'] !== [] || $changes['attachments_removed'] !== [])
                     <div>
                         <p class="text-[12px] font-semibold text-gray-500 mb-1.5">添付</p>
```

`resources/views/approvals/requests/_history.blade.php`（変更）

```diff
--- a/resources/views/approvals/requests/_history.blade.php
+++ b/resources/views/approvals/requests/_history.blade.php
@@ -32,8 +32,15 @@
                         <dt class="text-gray-500">関連する決裁No</dt>
                         <dd class="text-gray-900 font-mono">{{ ($snapshot['related_numbers'] ?? []) === [] ? '—' : implode('・', $snapshot['related_numbers']) }}</dd>
                     </dl>
-                    <p class="mt-3 text-[12px] font-semibold text-gray-500 mb-1.5">重点ポイント（5W2H）</p>
-                    <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-gray-900 leading-relaxed whitespace-pre-wrap break-words">{{ $snapshot['body'] ?? '' }}</div>
+                    {{-- 本文の欄（その回の控えの明細表・追加の欄・定型文。段階5 より前の控えには無い＝5W2H の形） --}}
+                    <div class="mt-3">
+                        @include('approvals.requests._body_section', [
+                            'table'     => is_array($snapshot['amount_table'] ?? null) ? $snapshot['amount_table'] : null,
+                            'extras'    => \App\Support\Approval\RequestExtras::ordered($snapshot['extras'] ?? null),
+                            'fixedText' => $snapshot['fixed_text'] ?? null,
+                            'body'      => $snapshot['body'] ?? '',
+                        ])
+                    </div>
                     <p class="mt-3 text-[12px] font-semibold text-gray-500 mb-1.5">添付</p>
                     <ul class="space-y-1">
                         @forelse($snapshot['attachments'] ?? [] as $attachment)
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0007-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 のテストと、2b からの詳細・変更点・履歴・差戻しの取り消しのテスト・スマホの走査）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/RequestTableShowTest.php tests/Feature/Approval/Phase2 tests/Feature/Approval/Phase3 tests/Feature/MobileLayoutTest.php 2>&1 | tail -3
```

Expected: `OK`（5W2H の種類の詳細・変更点・履歴のテストは変えずに通る）

- [ ] **Step 5: 全件を流す**

Expected: `OK (4021 tests, 37934 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/RequestExtras.php app/Support/Approval/RequestSnapshot.php app/Support/Approval/UndoTarget.php app/Support/Approval/RequestContent.php resources/views/approvals/requests/_amount_table_show.blade.php resources/views/approvals/requests/_body_section.blade.php resources/views/approvals/requests/show.blade.php resources/views/approvals/requests/_changes.blade.php resources/views/approvals/requests/_history.blade.php tests/Feature/Approval/Phase5/RequestTableShowTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 詳細・控え・変更点・提出の履歴に明細表と追加の欄を出す

提出の控えに明細表・追加の欄・定型文を足し、申請者以外には控えから出す
（段階5 設計書 §5.6・D14）。変更点の明細表は行の鍵（名前を設定した行か・
項目名）で合わせ、真ん中の行を消してもほかの行を「消えた」にしない。
差戻しの取り消しの比べにも明細表と追加の欄を入れ、管理者が種類の設定を
変えただけなら直したことにしない。スマホでは明細表も変更点もカード。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 8: PDF に明細表・追加の欄・定型文

明細表の種類の PDF は、重点ポイントの欄の代わりに紙の住宅の様式の「（記）」の並び（明細表 → 坪数・坪単価 → 定型文 → 担当者・契約予定日 → 補足）で載せる。5W2H の種類で追加の欄や定型文を使うときは本文の下に同じ並びで載せる（設計書 §5.7・§10 の 4・§0.8）。

**Files:**
- Modify: `app/Support/Approval/PdfSheet.php`・`resources/views/approvals/requests/pdf.blade.php`
- Test: Create `tests/Feature/Approval/Phase5/PdfTableTest.php`

**Interfaces:**
- Consumes: Task 7 の `RequestContent`（`amountTable`・`extras`・`fixedText`・`usesTable()`）、Task 3 の `AmountTable`・`RequestExtras::FIELDS`・`display()`
- Produces: `PdfSheet` の `amountRows`（`list<{kind: row|subtotal|total, name, sale, cost, profit, rate}>|null`）・`extras`（キー => `[名前, 表示]`）・`fixedText`・`EXTRAS_BEFORE_FIXED_TEXT`・`EXTRAS_AFTER_FIXED_TEXT`・`extraPairs(list<string> $keys): list<array{0: string, 1: string}>`・`amountRows(array $table): list<array>`・`MIN_SUPPLEMENT_ROWS`

**差分の大きさ:** 3 ファイル・+322 / −11 行（差分のファイル `0008-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase5/PdfTableTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Support\Approval\ApprovalPdf;
use App\Support\Approval\PdfSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * PDF の明細表・追加の欄・定型文（要件 9.2・5.6・段階5 設計書 §5.7）。
 *
 * ⚠ 4a の落とし穴（BACKLOG の 4a の節・pdf.blade.php の先頭の注記）を戻さない: 人が打つ文字の入る表には class="wrap"
 *   （無いと空白の無い長い語で表ごと文字が縮む）・表の 1 行＝罫線の 1 行（行の切れ目で次のページへ）。縮んだかは PDF の文字の大きさで見る
 */
class PdfTableTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    /** 1 本のテストの中で使い回す種類（種類名は一意） */
    private ?ApprovalType $contract = null;

    private function row(?string $name, bool $fixed, ?int $sale, ?int $cost): array
    {
        return ['name' => $name, 'fixed' => $fixed, 'sale' => $sale, 'cost' => $cost];
    }

    /** 9/17 の見本の中身で提出した申請（行を差し替えられる） */
    private function submittedContract(array $w, ?array $upper = null, ?array $lower = null): ApprovalRequest
    {
        return $this->submittedFor($w, [
            'type_id' => ($this->contract ??= $this->housingContractType($w, ['subject_suffix' => null]))->id, 'subject' => '山田様請負新築工事契約の件', 'body' => '仕様変更によるオプション工事を含む。',
            'amount' => 41700000, 'tsubo' => '38.5', 'tsubo_price' => 1083000, 'staff' => '佐藤 健一', 'contract_date' => '2026-10-20',
            'fixed_text' => '上記の内容に基づき、販売をおこないます。',
            'amount_table' => [
                'subtotal' => true,
                'upper'    => $upper ?? [$this->row('工事請負金額', true, 28500000, 22000000), $this->row('オプション工事', false, 1200000, 850000), $this->row('紹介料', true, 0, 300000)],
                'lower'    => $lower ?? [$this->row('土地契約金額', true, 12000000, 10500000), $this->row(null, false, null, null)],
            ],
        ]);
    }

    private static function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type /Page\b#', $pdf);
    }

    /** PDF の中で使われている文字の大きさ（pt。小さい順）。mPDF は表を縮めるとき、その表の文字の大きさを小さくして描く（PdfSheetTest と同じ読み方） */
    private static function fontSizes(string $pdf): array
    {
        preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $pdf, $streams);
        $sizes = [];
        foreach ($streams[1] as $stream) {
            $content = @gzuncompress($stream);
            if ($content !== false && preg_match_all('#/F\d+ ([\d.]+) Tf#', $content, $found)) {
                $sizes = array_merge($sizes, array_map('floatval', $found[1]));
            }
        }
        $sizes = array_values(array_unique($sizes));
        sort($sizes);

        return $sizes;
    }

    /** 紙面の文字（タグの境目を空白にしてタグを除き、空白を詰めたもの） */
    private static function text(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', preg_replace('#<style>.*?</style>#s', '', $html))), ENT_QUOTES, 'UTF-8')));
    }

    public function test_the_sheet_carries_the_rows_the_extras_and_the_fixed_text(): void
    {
        $w     = $this->approvalWorld();
        $sheet = PdfSheet::for($w['head'], $this->submittedContract($w));

        $this->assertSame([
            ['kind' => 'row', 'name' => '工事請負金額', 'sale' => '28,500,000円', 'cost' => '22,000,000円', 'profit' => '6,500,000円', 'rate' => '22.8%'],
            ['kind' => 'row', 'name' => 'オプション工事', 'sale' => '1,200,000円', 'cost' => '850,000円', 'profit' => '350,000円', 'rate' => '29.2%'],
            ['kind' => 'row', 'name' => '紹介料', 'sale' => '0円', 'cost' => '300,000円', 'profit' => '-300,000円', 'rate' => '—'],
            ['kind' => 'subtotal', 'name' => '計', 'sale' => '29,700,000円', 'cost' => '23,150,000円', 'profit' => '6,550,000円', 'rate' => '22.1%'],
            ['kind' => 'row', 'name' => '土地契約金額', 'sale' => '12,000,000円', 'cost' => '10,500,000円', 'profit' => '1,500,000円', 'rate' => '12.5%'],
            ['kind' => 'total', 'name' => '合計金額', 'sale' => '41,700,000円', 'cost' => '33,650,000円', 'profit' => '8,050,000円', 'rate' => '19.3%'],
        ], $sheet->amountRows, '名前も金額も無い自由行は載せない');
        $this->assertSame(['tsubo' => ['坪数', '38.5坪'], 'tsubo_price' => ['坪単価', '1,083,000円'], 'staff' => ['担当者', '佐藤 健一'], 'contract_date' => ['契約予定日', '2026/10/20']], $sheet->extras);
        $this->assertSame([['坪数', '38.5坪'], ['坪単価', '1,083,000円']], $sheet->extraPairs(PdfSheet::EXTRAS_BEFORE_FIXED_TEXT));
        $this->assertSame([['担当者', '佐藤 健一'], ['契約予定日', '2026/10/20']], $sheet->extraPairs(PdfSheet::EXTRAS_AFTER_FIXED_TEXT));
        $this->assertSame('上記の内容に基づき、販売をおこないます。', $sheet->fixedText);
        $this->assertSame(['仕様変更によるオプション工事を含む。', ''], $sheet->bodyRows, '補足は紙の 2 行');
        $this->assertSame('41,700,000円', $sheet->amountLabel);
    }

    /** 紙の住宅の様式の「（記）」の並び: 明細表 → 坪数・坪単価 → 定型文 → 担当者・契約予定日 → 補足（重点ポイントの欄は出さない。設計書 §5.7） */
    public function test_the_paper_follows_the_housing_form(): void
    {
        $w    = $this->approvalWorld();
        $html = view('approvals.requests.pdf', ['sheet' => PdfSheet::for($w['head'], $this->submittedContract($w))])->render();
        $text = self::text($html);

        $this->assertStringContainsString(
            '（記） 項目 販売金額 工事原価 粗利益金額 粗利率 工事請負金額 28,500,000円 22,000,000円 6,500,000円 22.8%'
            . ' オプション工事 1,200,000円 850,000円 350,000円 29.2% 紹介料 0円 300,000円 -300,000円 —'
            . ' 計 29,700,000円 23,150,000円 6,550,000円 22.1% 土地契約金額 12,000,000円 10,500,000円 1,500,000円 12.5%'
            . ' 合計金額 41,700,000円 33,650,000円 8,050,000円 19.3%'
            . ' 坪数 38.5坪 坪単価 1,083,000円 上記の内容に基づき、販売をおこないます。'
            . ' 担当者 佐藤 健一 契約予定日 2026/10/20 補足 仕様変更によるオプション工事を含む。',
            $text
        );
        $this->assertStringNotContainsString('重点ポイント箇条書', $text);
        // 人が打つ文字の入る表はすべて折り返す（落とし穴 ②）。合計の行は網掛け
        $this->assertSame(0, preg_match('/<table>(?![^<]*<tr>\s*<td class="label" style="width: 18mm;">決裁No)/', $html), 'class="wrap" の無い表がある（決裁No・日付の表を除く）');
        $this->assertSame(2, substr_count($html, '<tr class="sum">'));
        // 明細表の見出しの行は thead（行が多くて次のページへ続いても、ページの頭に見出しが出る。点検の M-8）
        $this->assertMatchesRegularExpression('#<thead>\s*<tr>\s*<td class="label">項目</td>#u', $html);
    }

    public function test_a_points_request_shows_the_extras_and_the_fixed_text_below_the_body(): void
    {
        $w = $this->approvalWorld();
        $w['type']->update(['uses_staff' => true, 'fixed_text' => '本件は社内規程に基づく。']);
        $request = $this->submittedFor($w, ['staff' => '佐藤', 'fixed_text' => '本件は社内規程に基づく。']);

        $sheet = PdfSheet::for($w['head'], $request);
        $this->assertNull($sheet->amountRows);
        $this->assertCount(PdfSheet::MIN_BODY_ROWS, $sheet->bodyRows);

        $text = self::text(view('approvals.requests.pdf', ['sheet' => $sheet])->render());
        $this->assertStringContainsString('重点ポイント箇条書（5W2H） ■ なぜ（目的・理由） ・老朽化のため', $text);
        $this->assertStringContainsString('・老朽化のため 本件は社内規程に基づく。 担当者 佐藤 （添付ファイル 0 件）', $text, '定型文 → 担当者・契約予定日（明細表の種類と同じ並び）');
        $this->assertStringNotContainsString('（記）', $text);
    }

    /** 長い項目名（空白の無い 30 文字）や行の多い明細表でも、文字を縮めずに折り返し・次のページへ続ける（落とし穴 ②・表の 1 行＝罫線の 1 行） */
    public function test_a_long_item_name_or_many_rows_do_not_shrink_the_sheet(): void
    {
        $w     = $this->approvalWorld();
        $plain = self::fontSizes(ApprovalPdf::sheet(PdfSheet::for($w['head'], $this->submittedContract($w))));
        $this->assertContains(10.0, $plain, '文字の大きさを読み取れている（読み取れないと、下の比べが空振りする）');

        $long = $this->submittedContract($w, [$this->row(substr('https://example.com/' . str_repeat('abcdefghij', 3), 0, 30), false, 1000, 500)]);
        $this->assertSame($plain, self::fontSizes(ApprovalPdf::sheet(PdfSheet::for($w['head'], $long))), '空白の無い長い項目名は折り返す（表の文字は縮まない）');

        $rows = fn (string $label) => array_map(fn (int $i) => $this->row("{$label} {$i}", false, 1000000 * $i, 900000 * $i), range(1, 30));
        $many = $this->submittedContract($w, $rows('追加工事'), $rows('土地'));
        $pdf  = ApprovalPdf::sheet(PdfSheet::for($w['head'], $many));
        $this->assertSame($plain, self::fontSizes($pdf), '60 行の明細表でも文字を縮めない');
        $this->assertGreaterThanOrEqual(2, self::pageCount($pdf), '行が多ければ次のページへ続く');
    }

    public function test_the_sheet_escapes_the_table_the_extras_and_the_fixed_text(): void
    {
        $w       = $this->approvalWorld();
        $request = $this->submittedContract($w, [$this->row('<b>項目</b>', false, 1000, null)]);
        $request->update(['fixed_text' => '<i>定型</i>']);
        DB::table('approval_revisions')->where('request_id', $request->id)->update(['snapshot' => json_encode(array_merge(
            $request->revisions()->sole()->snapshot,
            ['fixed_text' => "<i>定型</i>\n2 行目", 'extras' => ['staff' => '<u>担当</u>']]
        ), JSON_UNESCAPED_UNICODE)]);

        $html = view('approvals.requests.pdf', ['sheet' => PdfSheet::for($w['head'], $request->fresh())])->render();
        $this->assertStringContainsString(e('<b>項目</b>'), $html);
        $this->assertStringContainsString(e('<u>担当</u>'), $html);
        $this->assertStringContainsString(e('<i>定型</i>') . '<br />' . "\n" . '2 行目', $html, '定型文は改行を保って逃がす');
        $this->assertStringNotContainsString('<b>項目</b>', $html);
    }
}
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0008-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/PdfTableTest.php
```

Expected: `ERRORS!` `Tests: 5, Assertions: 7, Errors: 2, Failures: 3.`

- `PdfTableTest::test_the_sheet_carries_the_rows_the_extras_and_the_fixed_text` — `ErrorException: Undefined property: App\Support\Approval\PdfSheet::$amountRows`
- `PdfTableTest::test_a_points_request_shows_the_extras_and_the_fixed_text_below_the_body` — `ErrorException: Undefined property: App\Support\Approval\PdfSheet::$amountRows`
- `PdfTableTest::test_the_paper_follows_the_housing_form` — `Failed asserting that '決裁申請書 状態: 部門長確認中 ・ 1 回目の提出 決裁No 決裁日 発信日 2026年10月7日 受付日 決裁 可 条可 差戻 否 申請部門 承認 決裁者コメント 住宅事業部 申請者名 申請 花子 件名 山田様請負新築工事契約の件 金額 41,700,000円（税抜）`
- `PdfTableTest::test_a_long_item_name_or_many_rows_do_not_shrink_the_sheet` — `行が多ければ次のページへ続く`
- `PdfTableTest::test_the_sheet_escapes_the_table_the_extras_and_the_fixed_text` — `Failed asserting that '<style>\n`

- [ ] **Step 3: PDF に足す**

⚠ **4a の落とし穴 4 つを戻さない**（`pdf.blade.php` の先頭の注記）: 足す表にはすべて `class="wrap"`・判断の欄の小さな表と朱の枠（`td.mark.on`）は触らない・SVG は足さない・表の 1 行＝罫線の 1 行。

`app/Support/Approval/PdfSheet.php`（変更）

```diff
--- a/app/Support/Approval/PdfSheet.php
+++ b/app/Support/Approval/PdfSheet.php
@@ -19,6 +19,8 @@
  * - 中身は申請の詳細と同じ（`RequestContent::for`。申請者以外には最後に提出した控え）
  * - 判断と印は**今の回だけ**（D4）。前の回のやり取りは画面の履歴で見る
  * - 決裁の前は決裁No・決裁日・判断の○が空欄
+ * - 明細表の種類（段階5 §5.7）: 重点ポイントの欄の代わりに、紙の住宅の様式の「（記）」の並び（明細表 → 追加の入力欄 → 定型文 → 補足）。
+ *   5W2H の種類も、種類で使えば追加の入力欄と定型文を本文の下に載せる
  *
  * ⚠ 下書きは出さない（D17）。呼ぶ側（`RequestPdfController`）が先に断る。
  */
@@ -30,16 +32,29 @@ final class PdfSheet
     /** 本文の 1 行に入れる文字数の上限（mPDF は表の 1 行をページの途中で切れないので、長い行は分ける） */
     public const BODY_ROW_CHARS = 300;
 
+    /** 明細表の種類の補足の罫線の行数（紙の住宅の様式は自由記入 2 行） */
+    public const MIN_SUPPLEMENT_ROWS = 2;
+
     /** 社長の判断 → 紙の決裁の欄の言葉 */
     private const DECISION_MARKS = ['approve' => '可', 'conditional' => '条可', 'return' => '差戻', 'reject' => '否'];
 
     /** 審査の意見 → 紙の審査部門の欄の言葉 */
     private const REVIEW_MARKS = ['ok' => '可', 'hold' => '保留', 'ng' => '否'];
 
+    /**
+     * 紙の住宅の様式の「（記）」で、定型文の前に載せる追加の欄（坪数・坪単価）と後に載せる欄（担当者・契約予定日）（段階5 設計書 §5.7。
+     * 5W2H の種類で使うときも、本文の下に同じ並びで載せる）
+     */
+    public const EXTRAS_BEFORE_FIXED_TEXT = ['tsubo', 'tsubo_price'];
+
+    public const EXTRAS_AFTER_FIXED_TEXT = ['staff', 'contract_date'];
+
     /**
      * @param list<string> $relatedNumbers
      * @param list<string> $bodyRows
      * @param list<string> $attachmentNames
+     * @param list<array{kind: string, name: string, sale: string, cost: string, profit: string, rate: string}>|null $amountRows 明細表の種類だけ
+     * @param array<string, array{0: string, 1: string}> $extras 追加の入力欄（キー => 名前・表示。RequestExtras::FIELDS の並び）
      */
     private function __construct(
         public readonly string $statusLabel,
@@ -70,6 +85,9 @@ private function __construct(
         public readonly string $outputAt,
         public readonly string $outputBy,
         public readonly string $fileName,
+        public readonly ?array $amountRows = null,
+        public readonly array $extras = [],
+        public readonly ?string $fixedText = null,
     ) {
     }
 
@@ -103,7 +121,7 @@ public static function for(User $viewer, ApprovalRequest $request): self
             amountLabel: $content->amountLabel(),
             schedule: $content->schedule,
             relatedNumbers: array_values($content->relatedNumbers),
-            bodyRows: self::bodyRows($content->body),
+            bodyRows: self::bodyRows($content->body, $content->usesTable() ? self::MIN_SUPPLEMENT_ROWS : self::MIN_BODY_ROWS),
             attachmentNames: $content->attachments->pluck('original_name')->values()->all(),
             reviewDepartmentName: $review?->department?->name,
             reviewMark: self::mark($review, self::REVIEW_MARKS),
@@ -112,15 +130,84 @@ public static function for(User $viewer, ApprovalRequest $request): self
             outputAt: JapanTime::format(now()),
             outputBy: $viewer->name,
             fileName: '決裁申請書_' . ($request->number ?? '申請' . $request->id) . '.pdf',
+            amountRows: $content->amountTable === null ? null : self::amountRows($content->amountTable),
+            extras: self::extrasOf($content->extras),
+            fixedText: $content->fixedText,
         );
     }
 
     /**
-     * 本文を罫線の行に分ける。改行で分け、長い行は BODY_ROW_CHARS 文字ずつに分け、MIN_BODY_ROWS 行に満たなければ空の行で埋める。
+     * 追加の入力欄のうち、名指しした欄（名前・表示。空は「—」。並びは RequestExtras::FIELDS のとおり）
+     *
+     * @param list<string> $keys
+     * @return list<array{0: string, 1: string}>
+     */
+    public function extraPairs(array $keys): array
+    {
+        return array_values(array_intersect_key($this->extras, array_flip($keys)));
+    }
+
+    /**
+     * @param array<string, string|int|null> $extras RequestContent の extras（キー => 値）
+     * @return array<string, array{0: string, 1: string}>
+     */
+    private static function extrasOf(array $extras): array
+    {
+        $pairs = [];
+        foreach ($extras as $key => $value) {
+            $pairs[$key] = [RequestExtras::FIELDS[$key], RequestExtras::display($key, $value) ?? '—'];
+        }
+
+        return $pairs;
+    }
+
+    /**
+     * 明細表の紙面の行（前半の行 → 「計」→ 後半の行 → 「合計金額」。名前も金額も無い自由行は載せない。金額は「28,500,000円」）
+     *
+     * @param array<string, mixed> $table
+     * @return list<array{kind: string, name: string, sale: string, cost: string, profit: string, rate: string}>
+     */
+    public static function amountRows(array $table): array
+    {
+        $totals = AmountTable::totals($table);
+        $rows   = [];
+        foreach (AmountTable::shownRows($table, 'upper') as $row) {
+            $rows[] = self::amountRow('row', $row['name'] ?? '（項目名なし）', $row);
+        }
+        if ($totals['subtotal'] !== null) {
+            $rows[] = self::amountRow('subtotal', '計', $totals['subtotal']);
+        }
+        foreach (AmountTable::shownRows($table, 'lower') as $row) {
+            $rows[] = self::amountRow('row', $row['name'] ?? '（項目名なし）', $row);
+        }
+        $rows[] = self::amountRow('total', '合計金額', $totals['total']);
+
+        return $rows;
+    }
+
+    /**
+     * @param array{sale: ?int, cost: ?int, profit: ?int, rate: ?float} $line
+     * @return array{kind: string, name: string, sale: string, cost: string, profit: string, rate: string}
+     */
+    private static function amountRow(string $kind, string $name, array $line): array
+    {
+        return [
+            'kind'   => $kind,
+            'name'   => $name,
+            'sale'   => AmountTable::yen($line['sale']),
+            'cost'   => AmountTable::yen($line['cost']),
+            'profit' => AmountTable::yen($line['profit']),
+            'rate'   => $line['profit'] === null ? '' : AmountTable::rateLabel($line['rate']),
+        ];
+    }
+
+    /**
+     * 本文を罫線の行に分ける。改行で分け、長い行は BODY_ROW_CHARS 文字ずつに分け、$minRows 行に満たなければ空の行で埋める
+     * （5W2H の種類は紙の 11 行・明細表の種類の補足は紙の 2 行）。
      *
      * @return list<string>
      */
-    public static function bodyRows(?string $body): array
+    public static function bodyRows(?string $body, int $minRows = self::MIN_BODY_ROWS): array
     {
         $rows = [];
         foreach (preg_split('/\R/u', rtrim((string) $body)) as $line) {
@@ -133,7 +220,7 @@ public static function bodyRows(?string $body): array
             $rows = [];
         }
 
-        return array_pad($rows, self::MIN_BODY_ROWS, '');
+        return array_pad($rows, $minRows, '');
     }
 
     /** @param array<string, string> $marks */
```

`resources/views/approvals/requests/pdf.blade.php`（変更）

```diff
--- a/resources/views/approvals/requests/pdf.blade.php
+++ b/resources/views/approvals/requests/pdf.blade.php
@@ -3,7 +3,7 @@
      ⚠ mPDF は CSS の一部しか読まない（flex・grid・CSS の変数は使えない）。枠は表で組む。
      ⚠ 本文は罫線の 1 行を表の 1 行にする（mPDF は表の 1 行をページの途中で切れない。PdfSheet::bodyRows()）。
      ⚠ 文字はすべて {{ }} で包む（印は StampSvg が e() で包んだ SVG を返す）。
-     ⚠ 人が打った文字の入る表（決裁の欄・件名・本文・添付・審査）には class="wrap"（mPDF の表の CSS overflow: wrap）を付ける。
+     ⚠ 人が打った文字の入る表（決裁の欄・件名・本文・明細表・追加の欄・定型文・添付・審査）には class="wrap"（mPDF の表の CSS overflow: wrap）を付ける。
         空白の無い長い語（URL・ファイル名）をセルの中で折り返す。無いと、その語が収まるまで表全体の文字が縮む（word-wrap・overflow-wrap は効かない）。
         決裁No・日付の表（付けると列の幅が少し変わる）と入れ子の判断の欄 table.marks は、短い決まった文字だけなので付けない --}}
 @php
@@ -30,6 +30,8 @@
     .note { font-size: 8.5pt; color: #444; }
     td.line { border-top: none; border-bottom: 0.4pt dashed #999; height: 6mm; }
     table.wrap { overflow: wrap; }
+    td.num { text-align: right; }
+    tr.sum td { background-color: #f2f2f2; }
 </style>
 
 <div class="title">決裁申請書</div>
@@ -107,15 +109,67 @@
             金額 {{ $sheet->amountLabel ?? '—' }}（税抜）&nbsp;&nbsp;
             実施時期 {{ $sheet->schedule ?? '—' }}&nbsp;&nbsp;
             関連する決裁No {{ $sheet->relatedNumbers === [] ? '—' : implode('・', $sheet->relatedNumbers) }}
-            <div class="mincho" style="margin-top: 2mm;">重点ポイント箇条書（5W2H）</div>
+            <div class="mincho" style="margin-top: 2mm;">{{ $sheet->amountRows === null ? '重点ポイント箇条書（5W2H）' : '（記）' }}</div>
         </td>
     </tr>
 </table>
-<table class="wrap">
-    @foreach($sheet->bodyRows as $row)
-        <tr><td class="line">{{ $row }}</td></tr>
-    @endforeach
-</table>
+@if($sheet->amountRows !== null)
+    {{-- 明細表の種類（段階5 §5.7）: 紙の住宅の様式の「（記）」の並び。明細表の 1 行＝表の 1 行（行の切れ目で次のページへ）。
+         見出しの行は thead に入れる（mPDF は次のページの頭で繰り返す。点検の M-8） --}}
+    <table class="wrap">
+        <thead>
+            <tr>
+                <td class="label">項目</td>
+                <td class="label" style="width: 30mm;">販売金額</td>
+                <td class="label" style="width: 30mm;">工事原価</td>
+                <td class="label" style="width: 30mm;">粗利益金額</td>
+                <td class="label" style="width: 18mm;">粗利率</td>
+            </tr>
+        </thead>
+        @foreach($sheet->amountRows as $row)
+            <tr class="{{ $row['kind'] === 'row' ? '' : 'sum' }}">
+                <td>{{ $row['name'] }}</td>
+                <td class="num">{{ $row['sale'] }}</td>
+                <td class="num">{{ $row['cost'] }}</td>
+                <td class="num">{{ $row['profit'] }}</td>
+                <td class="num">{{ $row['rate'] }}</td>
+            </tr>
+        @endforeach
+    </table>
+@else
+    <table class="wrap">
+        @foreach($sheet->bodyRows as $row)
+            <tr><td class="line">{{ $row }}</td></tr>
+        @endforeach
+    </table>
+@endif
+{{-- 追加の入力欄（種類が使う欄）と定型文。紙の住宅の様式の「（記）」の並び: 坪数・坪単価 → 定型文 → 担当者・契約予定日（段階5 §5.7。
+     5W2H の種類で使うときも本文の下に同じ並び。点検の I-5）。1 行に 2 組まで（見出しの幅 22mm で「契約予定日」が 1 行に収まる） --}}
+@foreach([\App\Support\Approval\PdfSheet::EXTRAS_BEFORE_FIXED_TEXT, \App\Support\Approval\PdfSheet::EXTRAS_AFTER_FIXED_TEXT] as $group => $keys)
+    @if($sheet->extraPairs($keys) !== [])
+        <table class="wrap">
+            <tr>
+                @foreach($sheet->extraPairs($keys) as [$label, $value])
+                    <td class="label" style="width: 22mm;">{{ $label }}</td>
+                    <td>{{ $value }}</td>
+                @endforeach
+            </tr>
+        </table>
+    @endif
+    @if($group === 0 && ($sheet->fixedText ?? '') !== '')
+        <table class="wrap">
+            <tr><td>{!! nl2br(e($sheet->fixedText)) !!}</td></tr>
+        </table>
+    @endif
+@endforeach
+@if($sheet->amountRows !== null)
+    <table class="wrap">
+        <tr><td class="mincho" style="border-bottom: none;">補足</td></tr>
+        @foreach($sheet->bodyRows as $row)
+            <tr><td class="line">{{ $row }}</td></tr>
+        @endforeach
+    </table>
+@endif
 <table class="wrap">
     <tr>
         <td style="border-top: none; text-align: right;" class="small">
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0008-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 のテストと、4a からの PDF のテスト）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase5/PdfTableTest.php tests/Feature/Approval/Phase4 2>&1 | tail -3
```

Expected: `OK`（5W2H の種類の PDF のテストは変えずに通る）

- [ ] **Step 5: 全件を流す**

Expected: `OK (4026 tests, 37960 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/PdfSheet.php resources/views/approvals/requests/pdf.blade.php tests/Feature/Approval/Phase5/PdfTableTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): PDF に明細表・追加の欄・定型文を載せる

明細表の種類の PDF は、重点ポイントの欄の代わりに紙の住宅の様式の
「（記）」の並び（明細表 → 坪数・坪単価 → 定型文 → 担当者・契約予定日 →
補足）で載せる（段階5 設計書 §5.7）。明細表の見出しは次のページの頭でも
繰り返す。5W2H の種類で追加の欄や定型文を使うときは本文の下に載せる。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 9: 台帳の行と Excel に工事原価・粗利益金額・粗利率・契約予定日

Excel の列を 1 つの定義から作る形に直してから、要件 10 の並びで 4 列を足して 19 列にする。台帳の行（`LedgerRow`）に合計金額の行の数と契約予定日を足す。あわせて 4b の小さな指摘（設計書 §8）を片付ける（設計書 §5.8・§10 の 9・D18・D19・§0.9）。

**Files:**
- Modify: `app/Support/Approval/LedgerExcel.php`・`app/Support/Approval/LedgerRow.php`・`app/Support/Approval/Ledger.php`・`app/Support/Approval/LedgerFilter.php`
- Test: Create `tests/Feature/Approval/Phase5/LedgerExcelTableTest.php`／Modify `tests/Feature/Approval/Phase4/LedgerExcelTest.php`

**Interfaces:**
- Consumes: Task 2 の `submittedRevision()`（`Ledger::rows()` が `lastRevision` を先に読む）、Task 3 の `AmountTable::totals()`・`RequestExtras::ordered()`
- Produces: `LedgerRow` の `cost: ?int`・`profit: ?int`・`rate: ?float`・`contractDate: ?CarbonInterface`・Excel の 19 列（`LedgerExcel::columns()`）。5b が「区分」の列をここに足す

**差分の大きさ:** 6 ファイル・+333 / −93 行（差分のファイル `0009-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase5/LedgerExcelTableTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase5;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Models\User;
use App\Support\Approval\Ledger;
use App\Support\Approval\LedgerFilter;
use App\Support\Approval\LedgerRow;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 台帳の行と Excel の 4 列（工事原価・粗利益金額・粗利率・契約予定日。要件 10・段階5 設計書 §5.8・D18・D19）と、
 * 台帳を触るついでに片付けた 4b の小さな指摘（設計書 §8）。
 */
class LedgerExcelTableTest extends TestCase
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

    private function row(?string $name, bool $fixed, ?int $sale, ?int $cost): array
    {
        return ['name' => $name, 'fixed' => $fixed, 'sale' => $sale, 'cost' => $cost];
    }

    /** 9/17 の見本の中身で提出し、社長が可と決裁した申請 */
    private function decidedContract(array $w, ApprovalType $type): ApprovalRequest
    {
        $r = $this->submittedFor($w, [
            'type_id' => $type->id, 'subject' => '山田様請負新築工事契約の件', 'amount' => 41700000, 'staff' => '佐藤', 'contract_date' => '2026-10-20',
            'amount_table' => [
                'subtotal' => true,
                'upper'    => [$this->row('工事請負金額', true, 28500000, 22000000), $this->row('オプション工事', false, 1200000, 850000), $this->row('紹介料', true, 0, 300000)],
                'lower'    => [$this->row('土地契約金額', true, 12000000, 10500000)],
            ],
        ]);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->judgeReview($r->fresh(), $w['reviewer'], $r->fresh()->lock_version, ApprovalStepResult::Ok, null);
        $this->workflow->judgePresident($r->fresh(), $w['president'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);

        return $r->fresh();
    }

    private function sheet(User $viewer, array $query = []): Worksheet
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

    private function rowFor(User $viewer, ApprovalRequest $request): LedgerRow
    {
        return Ledger::rows($viewer, [$request->id])->sole();
    }

    public function test_the_four_columns_of_a_table_request(): void
    {
        $w = $this->approvalWorld();
        $this->decidedContract($w, $this->housingContractType($w));

        $sheet = $this->sheet($this->viewAllUser());

        $this->assertSame(['金額（税抜）', '工事原価', '粗利益金額', '粗利率', '実施時期', '契約予定日'], $sheet->rangeToArray('H1:M1')[0]);
        foreach (['H2' => 41700000, 'I2' => 33650000, 'J2' => 8050000] as $cell => $value) {
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($cell)->getDataType(), "{$cell} が数でない");
            $this->assertEquals($value, $sheet->getCell($cell)->getValue());
            $this->assertSame('#,##0', $sheet->getStyle($cell)->getNumberFormat()->getFormatCode());
        }
        // 粗利率は割合の数（Excel で 19.3% と出る。並べ替え・計算ができる。D19）
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('K2')->getDataType());
        $this->assertEquals(0.193, $sheet->getCell('K2')->getValue());
        $this->assertSame('0.0%', $sheet->getStyle('K2')->getNumberFormat()->getFormatCode());
        $this->assertSame('19.3%', $sheet->getCell('K2')->getFormattedValue());
        // 契約予定日は Excel の日付（その日。時刻を入れない）
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('M2')->getDataType());
        $this->assertSame('2026-10-20 00:00:00', Date::excelToDateTimeObject($sheet->getCell('M2')->getValue())->format('Y-m-d H:i:s'));
        $this->assertSame('yyyy/mm/dd', $sheet->getStyle('M2')->getNumberFormat()->getFormatCode());
        $this->assertSame('A1:S2', $sheet->getAutoFilter()->getRange());
    }

    /** 5W2H の種類でも契約予定日を使う設定なら、Excel の契約予定日に入る（要件 10・D12。工事原価・粗利益金額・粗利率は空。点検の M-6） */
    public function test_a_points_type_that_uses_the_contract_date_fills_only_that_column(): void
    {
        $w = $this->approvalWorld();
        $w['type']->update(['uses_contract_date' => true]);
        $this->submittedFor($w, ['contract_date' => '2026-10-20']);

        $sheet = $this->sheet($this->viewAllUser(), ['status' => 'all']);
        $this->assertSame([null, null, null], [$sheet->getCell('I2')->getValue(), $sheet->getCell('J2')->getValue(), $sheet->getCell('K2')->getValue()]);
        $this->assertSame('2026-10-20 00:00:00', Date::excelToDateTimeObject($sheet->getCell('M2')->getValue())->format('Y-m-d H:i:s'));
    }

    /** 販売金額の合計が 0 の明細表（申請者本人の直しかけ）は粗利率を空にする（「—」の文字を入れると列で並べ替えや計算ができない。D19） */
    public function test_the_rate_is_empty_when_the_total_sale_is_zero(): void
    {
        $w       = $this->approvalWorld();
        $request = $this->submittedFor($w, [
            'type_id' => $this->housingContractType($w)->id, 'staff' => '佐藤', 'contract_date' => '2026-10-20',
            'amount_table' => ['subtotal' => false, 'upper' => [$this->row('工事請負金額', true, 1000, 900)], 'lower' => []],
        ]);
        $this->workflow->judgeHead($request->fresh(), $w['head'], $request->fresh()->lock_version, ApprovalStepResult::Return, '直してください');
        $request->fresh()->update(['amount_table' => ['subtotal' => false, 'upper' => [$this->row('工事請負金額', true, 0, 900)], 'lower' => []]]);

        $own = $this->rowFor($w['applicant'], $request);
        $this->assertSame([900, -900, null], [$own->cost, $own->profit, $own->rate], '申請者本人には今の中身');

        $sheet = $this->sheet($w['applicant'], ['status' => 'all']);
        $this->assertEquals(900, $sheet->getCell('I2')->getValue());
        $this->assertNull($sheet->getCell('K2')->getValue());

        // ほかの人には最後に提出した中身（販売金額 1,000・粗利率 10%）
        $others = $this->rowFor($this->viewAllUser(), $request);
        $this->assertSame([900, 100, 10.0], [$others->cost, $others->profit, $others->rate]);
        $this->assertSame('2026-10-20', $others->contractDate?->format('Y-m-d'));
    }

    /** 控えは今の回のものだけを使う（詳細の RequestContent と同じ。今の回の控えが欠けていれば今の中身へ落とさずに空。4b の Task 2 の軽微） */
    public function test_a_row_uses_only_the_revision_of_the_current_round(): void
    {
        $w       = $this->approvalWorld();
        $request = $this->submittedFor($w);
        DB::table('approval_requests')->where('id', $request->id)->update(['round' => 2]);   // 2 回目の控えが欠けた

        $row = $this->rowFor($this->viewAllUser(), $request);
        $this->assertNull($row->subject, '前の回の控えを今の回として出さない');
        $this->assertSame('社用車の購入', $this->rowFor($w['applicant'], $request)->subject, '申請者本人には今の中身');
    }

    /** 決裁日の期間は 1900〜2099 年だけを読む（0000-01-01 は UTC に直すと年が -1 になり、MySQL の TIMESTAMP と比べられない） */
    public function test_a_decided_date_outside_the_years_is_left_out(): void
    {
        $filter = LedgerFilter::fromRequest(Request::create('/', 'GET', ['from' => '0000-01-01', 'to' => '2100-01-01']));
        $this->assertSame([null, null, ['決裁日（から）', '決裁日（まで）']], [$filter->decidedFrom, $filter->decidedTo, $filter->ignored]);

        $kept = LedgerFilter::fromRequest(Request::create('/', 'GET', ['from' => '1900-01-01', 'to' => '2099-12-31']));
        $this->assertSame(['1900-01-01', '2099-12-31'], [$kept->decidedFrom?->format('Y-m-d'), $kept->decidedTo?->format('Y-m-d')]);
    }

    /** 状態の「すべて」は下書き以外すべて（知らないキーを「すべて」と扱わない形に直した。4b の Task 2 の軽微） */
    public function test_every_status_choice_still_works(): void
    {
        $w        = $this->approvalWorld();
        $decided  = $this->decidedContract($w, $this->housingContractType($w));
        $progress = $this->submittedFor($w);
        $viewer   = $this->viewAllUser();
        $ids      = fn (string $status) => Ledger::sortedIds($viewer, LedgerFilter::fromRequest(Request::create('/', 'GET', ['status' => $status])));

        $this->assertSame([$decided->id], $ids('numbered'));
        $this->assertSame([$progress->id], $ids('progress'));
        $this->assertSame([], $ids('withdrawn'));
        $this->assertEqualsCanonicalizing([$decided->id, $progress->id], $ids('all'));
    }
}
```

`tests/Feature/Approval/Phase4/LedgerExcelTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase4/LedgerExcelTest.php
+++ b/tests/Feature/Approval/Phase4/LedgerExcelTest.php
@@ -63,19 +63,29 @@ private function download(User $viewer, array $query = []): TestResponse
         return $this->actingAs($viewer)->get(route('approvals.ledger.excel', $query));
     }
 
-    /** 応答の xlsx をファイルに書いて読み戻す（読み戻しも、ZIP の中の XML も見る） */
+    /**
+     * 応答の xlsx をファイルに書いて読み戻す（読み戻しも、ZIP の中の XML も見る）。
+     * ⚠ tempnam() が作る拡張子の無い空のファイルも消す（.xlsx を付けた名前に書くので、元の名前のファイルが残っていた。4b の Task 4 の軽微）
+     */
     private function sheet(TestResponse $response): Worksheet
     {
-        $path = tempnam(sys_get_temp_dir(), 'ledger') . '.xlsx';
+        $base = tempnam(sys_get_temp_dir(), 'ledger');
+        $path = $base . '.xlsx';
         file_put_contents($path, $response->getContent());
-        $this->beforeApplicationDestroyed(fn () => @unlink($path));
+        // ⚠ 2 つの文に分ける（`@unlink($path) && @unlink($base)` は前が失敗すると後ろを消さない。点検の T-8）
+        $this->beforeApplicationDestroyed(function () use ($path, $base): void {
+            @unlink($path);
+            @unlink($base);
+        });
 
         return IOFactory::load($path)->getActiveSheet();
     }
 
     private function sheetXml(TestResponse $response): string
     {
-        $path = tempnam(sys_get_temp_dir(), 'ledger') . '.xlsx';
+        $base = tempnam(sys_get_temp_dir(), 'ledger');
+        @unlink($base);
+        $path = $base . '.xlsx';
         file_put_contents($path, $response->getContent());
         $zip = new ZipArchive();
         $this->assertTrue($zip->open($path) === true);
@@ -94,14 +104,16 @@ public function test_the_columns_and_the_values_of_a_row(): void
         $response = $this->download($this->viewAllUser())->assertOk();
         $sheet    = $this->sheet($response);
 
+        // 段階5 で工事原価・粗利益金額・粗利率・契約予定日を足した（要件 10 の並び。5W2H の種類では空。Phase5/LedgerExcelTableTest が中身を見る）
         $this->assertSame(
-            ['決裁No', '決裁日', '判断', '件名', '申請の種類', '申請部門', '申請者', '金額（税抜）', '実施時期', '関連する決裁No', '提出日', '審査の意見', '審査のコメント', '条件', '状態'],
-            $sheet->rangeToArray('A1:O1')[0]
+            ['決裁No', '決裁日', '判断', '件名', '申請の種類', '申請部門', '申請者', '金額（税抜）', '工事原価', '粗利益金額', '粗利率', '実施時期', '契約予定日', '関連する決裁No', '提出日', '審査の意見', '審査のコメント', '条件', '状態'],
+            $sheet->rangeToArray('A1:S1')[0]
         );
         $this->assertSame(
             ['R8-J-001', '条可', '社用車の購入', $w['type']->name, '住宅事業部', '申請 花子', '2026年10月', 'R7-J-003・R7-J-010', '保留', '見積を 2 社取ってください', '納期を確かめること', '条件確認待ち'],
-            array_map(fn (string $c) => $sheet->getCell("{$c}2")->getValue(), ['A', 'C', 'D', 'E', 'F', 'G', 'I', 'J', 'L', 'M', 'N', 'O'])
+            array_map(fn (string $c) => $sheet->getCell("{$c}2")->getValue(), ['A', 'C', 'D', 'E', 'F', 'G', 'L', 'N', 'P', 'Q', 'R', 'S'])
         );
+        $this->assertSame([null, null, null, null], array_map(fn (string $c) => $sheet->getCell("{$c}2")->getValue(), ['I', 'J', 'K', 'M']), '5W2H の種類では明細表と契約予定日の列は空');
         $this->assertSame(2, $sheet->getHighestRow(), '1 件なので見出しと 1 行');
     }
 
@@ -112,7 +124,7 @@ public function test_dates_are_excel_dates_of_the_japanese_day_and_the_amount_is
 
         $sheet = $this->sheet($this->download($this->viewAllUser())->assertOk());
 
-        foreach (['B2', 'K2'] as $cell) {
+        foreach (['B2', 'O2'] as $cell) {
             $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($cell)->getDataType(), "{$cell} が数（日付）でない");
             $this->assertSame('2026-10-05 00:00:00', Date::excelToDateTimeObject($sheet->getCell($cell)->getValue())->format('Y-m-d H:i:s'), "{$cell} が日本の暦の日でない（時刻を入れない）");
             $this->assertSame('yyyy/mm/dd', $sheet->getStyle($cell)->getNumberFormat()->getFormatCode());
@@ -131,7 +143,7 @@ public function test_text_that_looks_like_a_formula_stays_text(): void
         $response = $this->download($this->viewAllUser())->assertOk();
         $sheet    = $this->sheet($response);
 
-        foreach (['D2' => '=HYPERLINK("https://example.com","開く")', 'G2' => '+81 花子', 'I2' => '@SUM(A1)'] as $cell => $text) {
+        foreach (['D2' => '=HYPERLINK("https://example.com","開く")', 'G2' => '+81 花子', 'L2' => '@SUM(A1)'] as $cell => $text) {
             $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($cell)->getDataType(), "{$cell} が文字でない");
             $this->assertSame($text, $sheet->getCell($cell)->getValue());
         }
@@ -157,7 +169,7 @@ public function test_the_heading_is_frozen_and_has_filter_buttons(): void
         $sheet = $this->sheet($this->download($this->viewAllUser())->assertOk());
 
         $this->assertSame('A2', $sheet->getFreezePane());
-        $this->assertSame('A1:O3', $sheet->getAutoFilter()->getRange());
+        $this->assertSame('A1:S3', $sheet->getAutoFilter()->getRange());
         $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
         $this->assertSame('決裁台帳', $sheet->getTitle());
     }
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0009-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase4/LedgerExcelTest.php tests/Feature/Approval/Phase5/LedgerExcelTableTest.php
```

Expected: `ERRORS!` `Tests: 22, Assertions: 69, Errors: 1, Failures: 7.`

- `LedgerExcelTableTest::test_the_rate_is_empty_when_the_total_sale_is_zero` — `ErrorException: Undefined property: App\Support\Approval\LedgerRow::$cost`
- `LedgerExcelTest::test_the_columns_and_the_values_of_a_row` — `Failed asserting that two arrays are identical.`
- `LedgerExcelTest::test_dates_are_excel_dates_of_the_japanese_day_and_the_amount_is_a_number` — `O2 が数（日付）でない`
- `LedgerExcelTest::test_text_that_looks_like_a_formula_stays_text` — `Failed asserting that two strings are identical.`
- `LedgerExcelTest::test_the_heading_is_frozen_and_has_filter_buttons` — `Failed asserting that two strings are identical.`
- `LedgerExcelTableTest::test_the_four_columns_of_a_table_request` — `Failed asserting that two arrays are identical.`
- `LedgerExcelTableTest::test_a_points_type_that_uses_the_contract_date_fills_only_that_column` — `Failed asserting that two arrays are identical.`
- `LedgerExcelTableTest::test_a_decided_date_outside_the_years_is_left_out` — `Failed asserting that two arrays are identical.`

- [ ] **Step 3: 列を 1 つの定義から作り、4 列を足す**

`app/Support/Approval/LedgerExcel.php`（変更）

```diff
--- a/app/Support/Approval/LedgerExcel.php
+++ b/app/Support/Approval/LedgerExcel.php
@@ -18,10 +18,12 @@
 /**
  * 決裁台帳の Excel（要件 10・段階4 設計書 §5.9・D23・D24）。絞り込んだ結果を台帳と同じ並びで全件。
  *
- * - 日付は Excel の日付（日本の暦の日。時刻は入れない）、金額は数（税抜）
+ * - 日付は Excel の日付（日本の暦の日。時刻は入れない）、金額は数（税抜）、粗利率は数（12.3% と出る書式。販売金額が 0 なら空。段階5 D19）
  * - 文字の欄は**すべて文字として入れる**（setCellValueExplicit）。件名などが「=」「+」「-」「@」で始まっても式にしない
  * - 見出しの行を固定し、見出しに絞り込みのボタン（オートフィルタ）
  * - 中身は台帳と同じ出し分け（LedgerRow。申請者本人以外には最後に提出した中身。D19）
+ * ⚠ 列の並び・見出し・幅・種類（文字・折り返す文字・日付・金額・率）・値の取り方は columns() の 1 つの定義から作る（列の記号を手で書かない。
+ *   段階4 の BACKLOG の注意「段階5 で列を足すときは 1 つの定義から作る形に直す」）
  * ⚠ 行の関係は CHUNK 件ずつ読む（全件の申請を一度に読まない）。件数の上限は config('approval.ledger.excel_limit')
  */
 final class LedgerExcel
@@ -29,34 +31,51 @@ final class LedgerExcel
     /** 1 回に読む申請の数 */
     private const CHUNK = 200;
 
-    /** 列（見出し => 幅）。D23・§5.9 の順。段階5 の列（工事原価など）は段階5 で足す */
-    private const COLUMNS = [
-        '決裁No'       => 12,
-        '決裁日'       => 11,
-        '判断'         => 6,
-        '件名'         => 40,
-        '申請の種類'   => 16,
-        '申請部門'     => 16,
-        '申請者'       => 14,
-        '金額（税抜）' => 14,
-        '実施時期'     => 18,
-        '関連する決裁No' => 18,
-        '提出日'       => 11,
-        '審査の意見'   => 10,
-        '審査のコメント' => 40,
-        '条件'         => 40,
-        '状態'         => 16,
-    ];
-
-    /** 日付の列・金額の列・折り返す列（A から数えた位置） */
-    private const DATE_COLUMNS = ['B', 'K'];
-
-    private const AMOUNT_COLUMN = 'H';
-
-    private const WRAPPED_COLUMNS = ['D', 'M', 'N'];
+    /** 列の値の種類（書き方と書式） */
+    private const TEXT = 'text';
+
+    private const WRAPPED = 'wrapped';
+
+    private const DATE = 'date';
+
+    private const AMOUNT = 'amount';
+
+    private const RATE = 'rate';
 
     private const SHEET_TITLE = '決裁台帳';
 
+    /**
+     * 列（要件 10 の並び。D23 で審査部門の意見を 2 列に分けた。段階5 で工事原価・粗利益金額・粗利率・契約予定日を足した）。
+     * 見出し・幅・種類・行から値を取る関数。並びを変えるときもここだけを変える
+     *
+     * @return list<array{0: string, 1: int, 2: string, 3: \Closure(LedgerRow): mixed}>
+     */
+    private static function columns(): array
+    {
+        return [
+            ['決裁No', 12, self::TEXT, fn (LedgerRow $r) => $r->number],
+            ['決裁日', 11, self::DATE, fn (LedgerRow $r) => $r->decidedAt],
+            ['判断', 6, self::TEXT, fn (LedgerRow $r) => $r->decisionLabel],
+            ['件名', 40, self::WRAPPED, fn (LedgerRow $r) => $r->subject],
+            ['申請の種類', 16, self::TEXT, fn (LedgerRow $r) => $r->typeName],
+            ['申請部門', 16, self::TEXT, fn (LedgerRow $r) => $r->departmentName],
+            ['申請者', 14, self::TEXT, fn (LedgerRow $r) => $r->applicantName],
+            ['金額（税抜）', 14, self::AMOUNT, fn (LedgerRow $r) => $r->amount],
+            ['工事原価', 14, self::AMOUNT, fn (LedgerRow $r) => $r->cost],
+            ['粗利益金額', 14, self::AMOUNT, fn (LedgerRow $r) => $r->profit],
+            ['粗利率', 8, self::RATE, fn (LedgerRow $r) => $r->rate],
+            // 実施時期は「2026年11月〜2027年2月」が右隣に値があっても欠けない幅（4b の画面の確かめの指摘）
+            ['実施時期', 22, self::TEXT, fn (LedgerRow $r) => $r->schedule],
+            ['契約予定日', 11, self::DATE, fn (LedgerRow $r) => $r->contractDate],
+            ['関連する決裁No', 18, self::TEXT, fn (LedgerRow $r) => $r->relatedNumbers === [] ? null : implode('・', $r->relatedNumbers)],
+            ['提出日', 11, self::DATE, fn (LedgerRow $r) => $r->submittedAt],
+            ['審査の意見', 10, self::TEXT, fn (LedgerRow $r) => $r->reviewResult],
+            ['審査のコメント', 40, self::WRAPPED, fn (LedgerRow $r) => $r->reviewComment],
+            ['条件', 40, self::WRAPPED, fn (LedgerRow $r) => $r->condition],
+            ['状態', 16, self::TEXT, fn (LedgerRow $r) => $r->statusLabel],
+        ];
+    }
+
     /**
      * xlsx の中身（バイト列）
      *
@@ -64,31 +83,37 @@ final class LedgerExcel
      */
     public static function build(User $viewer, array $ids): string
     {
-        $book  = new Spreadsheet();
-        $sheet = $book->getActiveSheet();
+        $book    = new Spreadsheet();
+        $sheet   = $book->getActiveSheet();
+        $columns = self::columns();
         $sheet->setTitle(self::SHEET_TITLE);
 
-        self::heading($sheet);
+        self::heading($sheet, $columns);
 
         $line = 2;
         foreach (array_chunk($ids, self::CHUNK) as $chunk) {
             foreach (Ledger::rows($viewer, $chunk) as $row) {
-                self::write($sheet, $line++, $row);
+                self::write($sheet, $line++, $row, $columns);
             }
         }
 
         $last = $line - 1;
         if ($last >= 2) {
-            self::formatBody($sheet, $last);
+            self::formatBody($sheet, $last, $columns);
         }
 
         $sheet->freezePane('A2');
-        $sheet->setAutoFilter('A1:' . self::lastColumn() . $last);
+        $sheet->setAutoFilter('A1:' . self::letter(count($columns)) . $last);
 
+        // ⚠ 書き出しに失敗しても、開いた出力のバッファを残さない（finally で閉じる）
         ob_start();
-        (new Xlsx($book))->save('php://output');
+        try {
+            (new Xlsx($book))->save('php://output');
+        } finally {
+            $bytes = (string) ob_get_clean();
+        }
 
-        return (string) ob_get_clean();
+        return $bytes;
     }
 
     /**
@@ -103,68 +128,65 @@ public static function fileNames(): array
         return [self::SHEET_TITLE . "_{$today}.xlsx", "ledger_{$today}.xlsx"];
     }
 
-    private static function heading(Worksheet $sheet): void
+    /** @param list<array{0: string, 1: int, 2: string, 3: \Closure}> $columns */
+    private static function heading(Worksheet $sheet, array $columns): void
     {
-        $column = 1;
-        foreach (self::COLUMNS as $label => $width) {
-            $sheet->setCellValueExplicit([$column, 1], $label, DataType::TYPE_STRING);
-            $sheet->getColumnDimensionByColumn($column)->setWidth($width);
-            $column++;
+        foreach ($columns as $index => [$label, $width]) {
+            $sheet->setCellValueExplicit([$index + 1, 1], $label, DataType::TYPE_STRING);
+            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
         }
-        $heading = 'A1:' . self::lastColumn() . '1';
+        $heading = 'A1:' . self::letter(count($columns)) . '1';
         $sheet->getStyle($heading)->getFont()->setBold(true);
         $sheet->getStyle($heading)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
     }
 
-    /** いちばん右の列（O） */
-    private static function lastColumn(): string
+    /** 列の記号（1 → A・19 → S） */
+    private static function letter(int $index): string
     {
-        return Coordinate::stringFromColumnIndex(count(self::COLUMNS));
+        return Coordinate::stringFromColumnIndex($index);
     }
 
-    /** 2 行目から $last 行目までの書式（日付・金額・折り返し・上ぞろえ）。行が無ければ呼ばない（書式だけの空の行を作らない） */
-    private static function formatBody(Worksheet $sheet, int $last): void
+    /**
+     * 2 行目から $last 行目までの書式（日付・金額・率・折り返し・上ぞろえ）。行が無ければ呼ばない（書式だけの空の行を作らない）
+     *
+     * @param list<array{0: string, 1: int, 2: string, 3: \Closure}> $columns
+     */
+    private static function formatBody(Worksheet $sheet, int $last, array $columns): void
     {
-        foreach (self::DATE_COLUMNS as $column) {
-            $sheet->getStyle("{$column}2:{$column}{$last}")->getNumberFormat()->setFormatCode('yyyy/mm/dd');
-        }
-        $sheet->getStyle(self::AMOUNT_COLUMN . '2:' . self::AMOUNT_COLUMN . $last)->getNumberFormat()->setFormatCode('#,##0');
-        foreach (self::WRAPPED_COLUMNS as $column) {
-            $sheet->getStyle("{$column}2:{$column}{$last}")->getAlignment()->setWrapText(true);
+        $formats = [self::DATE => 'yyyy/mm/dd', self::AMOUNT => '#,##0', self::RATE => '0.0%'];
+
+        foreach ($columns as $index => [, , $kind]) {
+            $range = self::letter($index + 1) . '2:' . self::letter($index + 1) . $last;
+            if (isset($formats[$kind])) {
+                $sheet->getStyle($range)->getNumberFormat()->setFormatCode($formats[$kind]);
+            }
+            if ($kind === self::WRAPPED) {
+                $sheet->getStyle($range)->getAlignment()->setWrapText(true);
+            }
         }
-        $sheet->getStyle('A2:' . self::lastColumn() . $last)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
+        $sheet->getStyle('A2:' . self::letter(count($columns)) . $last)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
     }
 
-    private static function write(Worksheet $sheet, int $line, LedgerRow $row): void
+    /**
+     * 1 行を書く（空の値は書かない）。文字は文字として（式にしない）・日付は日本の暦の日の Excel の日付・金額は数・率は割合の数（12.3% → 0.123）
+     *
+     * @param list<array{0: string, 1: int, 2: string, 3: \Closure(LedgerRow): mixed}> $columns
+     */
+    private static function write(Worksheet $sheet, int $line, LedgerRow $row, array $columns): void
     {
-        $texts = [
-            'A' => $row->number,
-            'C' => $row->decisionLabel,
-            'D' => $row->subject,
-            'E' => $row->typeName,
-            'F' => $row->departmentName,
-            'G' => $row->applicantName,
-            'I' => $row->schedule,
-            'J' => $row->relatedNumbers === [] ? null : implode('・', $row->relatedNumbers),
-            'L' => $row->reviewResult,
-            'M' => $row->reviewComment,
-            'N' => $row->condition,
-            'O' => $row->statusLabel,
-        ];
-        foreach ($texts as $column => $text) {
-            if ($text !== null && $text !== '') {
-                $sheet->setCellValueExplicit("{$column}{$line}", $text, DataType::TYPE_STRING);
+        foreach ($columns as $index => [, , $kind, $value]) {
+            $cell = self::letter($index + 1) . $line;
+            $v    = $value($row);
+            if ($v === null || $v === '') {
+                continue;
             }
-        }
 
-        if ($row->decidedAt !== null) {
-            $sheet->setCellValueExplicit("B{$line}", self::excelDate($row->decidedAt), DataType::TYPE_NUMERIC);
-        }
-        if ($row->submittedAt !== null) {
-            $sheet->setCellValueExplicit("K{$line}", self::excelDate($row->submittedAt), DataType::TYPE_NUMERIC);
-        }
-        if ($row->amount !== null) {
-            $sheet->setCellValueExplicit(self::AMOUNT_COLUMN . $line, $row->amount, DataType::TYPE_NUMERIC);
+            match ($kind) {
+                self::DATE   => $sheet->setCellValueExplicit($cell, self::excelDate($v), DataType::TYPE_NUMERIC),
+                self::AMOUNT => $sheet->setCellValueExplicit($cell, $v, DataType::TYPE_NUMERIC),
+                self::RATE   => $sheet->setCellValueExplicit($cell, round($v / 100, 3), DataType::TYPE_NUMERIC),
+                default      => $sheet->setCellValueExplicit($cell, (string) $v, DataType::TYPE_STRING),
+            };
         }
     }
```

`app/Support/Approval/LedgerRow.php`（変更）

```diff
--- a/app/Support/Approval/LedgerRow.php
+++ b/app/Support/Approval/LedgerRow.php
@@ -8,6 +8,7 @@
 use App\Models\ApprovalRequest;
 use App\Models\ApprovalStep;
 use App\Models\User;
+use Carbon\CarbonImmutable;
 use Carbon\CarbonInterface;
 
 /**
@@ -17,6 +18,8 @@
  *   控え**（RequestContent と同じ出し分け。名前も提出したときのもの）。台帳は一覧なので控えは先に読んだもの（lastRevision）を使う。
  *   控えが無いとき（提出した申請には必ずある）は今の中身へ落とさずに空にする（分からないときは見せない）。
  * ⚠ 審査の意見・コメントと条件（条可のコメント）は今の回の段階から（PDF と同じく今の回だけ。D4）。
+ * ⚠ 控えは今の回（`round` が申請の回と同じ）のものだけを使う（詳細の RequestContent と同じ引き方。今の回の控えが欠けていれば空にする）。
+ * ⚠ 段階5: 工事原価・粗利益金額・粗利率は明細表の「合計金額」の行（5W2H の種類は空）、契約予定日は追加の入力欄（使わない種類は空）。
  */
 final class LedgerRow
 {
@@ -39,6 +42,10 @@ private function __construct(
         public readonly ?string $condition,
         public readonly string $statusLabel,
         public readonly string $statusStyle,
+        public readonly ?int $cost = null,
+        public readonly ?int $profit = null,
+        public readonly ?float $rate = null,
+        public readonly ?CarbonInterface $contractDate = null,
     ) {
     }
 
@@ -47,6 +54,9 @@ public static function for(User $viewer, ApprovalRequest $request): self
     {
         $own      = $request->user_id === $viewer->id;
         $snapshot = $own ? [] : ($request->submittedRevision()?->snapshot ?? []);
+        $table    = $own ? $request->amount_table : ($snapshot['amount_table'] ?? null);
+        $total    = is_array($table) ? AmountTable::totals($table)['total'] : null;
+        $contract = $own ? $request->contract_date : RequestExtras::ordered($snapshot['extras'] ?? null)['contract_date'] ?? null;
         $steps    = $request->currentSteps()->keyBy(fn (ApprovalStep $s) => $s->kind->value);
         $review    = self::done($steps->get(ApprovalStepKind::Review->value));
         $president = self::done($steps->get(ApprovalStepKind::President->value));
@@ -69,6 +79,10 @@ public static function for(User $viewer, ApprovalRequest $request): self
             condition: $request->decision === ApprovalDecision::Conditional ? $president?->comment : null,
             statusLabel: $request->statusLabel(),
             statusStyle: $request->status->badgeStyle(),
+            cost: $total['cost'] ?? null,
+            profit: $total['profit'] ?? null,
+            rate: $total['rate'] ?? null,
+            contractDate: is_string($contract) ? CarbonImmutable::createFromFormat('!Y-m-d', $contract, 'UTC') ?: null : $contract,
         );
     }
```

4b の小さな指摘（状態の知らないキーを「すべて」にしない・決裁日の年の下限）:

`app/Support/Approval/Ledger.php`（変更）

```diff
--- a/app/Support/Approval/Ledger.php
+++ b/app/Support/Approval/Ledger.php
@@ -184,13 +184,14 @@ private static function whereStatus(Builder $query, string $status): void
     {
         $values = fn (array $statuses) => array_map(fn (ApprovalStatus $s) => $s->value, $statuses);
 
+        // ⚠ 知らないキーを「すべて」と扱わない（LedgerFilter が選択肢のキーだけを通す。通らないキーが来たら作りの誤り。4b の Task 2 の軽微）
         match ($status) {
             'numbered'  => $query->where(fn (Builder $q) => $q
                 ->whereIn('approval_requests.status', $values(self::DECIDED))
                 ->orWhere(fn (Builder $q) => $q->where('approval_requests.status', ApprovalStatus::Withdrawn->value)->whereNotNull('approval_requests.number'))),
             'progress'  => $query->whereIn('approval_requests.status', $values(self::IN_PROGRESS)),
             'withdrawn' => $query->where('approval_requests.status', ApprovalStatus::Withdrawn->value),
-            default     => null,
+            'all'       => null,
         };
     }
```

`app/Support/Approval/LedgerFilter.php`（変更）

```diff
--- a/app/Support/Approval/LedgerFilter.php
+++ b/app/Support/Approval/LedgerFilter.php
@@ -179,10 +179,13 @@ private static function positiveInt(string $value): ?int
         return preg_match('/^[1-9]\d{0,18}$/', $value) ? (int) $value : null;
     }
 
-    /** 日本の暦の日付（YYYY-MM-DD・在る日付だけ。2026-02-30 は外す） */
+    /**
+     * 日本の暦の日付（YYYY-MM-DD・在る日付だけ。2026-02-30 は外す）。年は 1900〜2099 だけ（0000-01-01 を UTC に直すと年が -1 になり、
+     * MySQL の TIMESTAMP と比べられない。4b の Task 2 の軽微。紙の台帳は平成の年度も取り込むので 2000 年より前も通す）
+     */
     private static function calendarDay(string $value): ?CarbonImmutable
     {
-        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
+        if (! preg_match('/^(19|20)\d{2}-\d{2}-\d{2}$/', $value)) {
             return null;
         }
         $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, JapanTime::ZONE);
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/5a/patches/0009-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 のテストと、4b からの台帳のテスト）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit tests/Feature/Approval/Phase4 tests/Feature/Approval/Phase5 2>&1 | tail -3
```

Expected: `OK`

- [ ] **Step 5: 全件を流す**

Expected: `OK (4032 tests, 37997 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/LedgerExcel.php app/Support/Approval/LedgerRow.php app/Support/Approval/Ledger.php app/Support/Approval/LedgerFilter.php tests/Feature/Approval/Phase5/LedgerExcelTableTest.php tests/Feature/Approval/Phase4/LedgerExcelTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 台帳の Excel に工事原価・粗利益金額・粗利率・契約予定日を足す

Excel の列を見出し・幅・値の種類・値の取り方の 1 つの定義から作る形に
直し、要件 10 の並びで 4 列を足して 19 列にする（段階5 設計書 §5.8）。
粗利率は割合の数で、販売金額 0 なら空欄（D19）。あわせて 4b の小さな
指摘（状態の知らないキー・決裁日の年の下限）を片付ける（設計書 §8）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 10: 全件テストと変異テスト（Bug #44 の作法）

**Files:** なし（測るだけ。穴が見つかったらテストを足してコミットする）

計画を書く段階で、試作（この計画のコードと同じ中身）に下の表の変異を 1 つずつ当てて測った（2026-10-07。決裁のテスト〈`tests/Feature/Approval`・`tests/Unit/Approval`〉と走査テスト 4 本を流した）。変異の一覧は、設計書の決定（D2〜D19・D25）と点検で直した所（§ 計画を書く段階の実測）を 1 つ以上ずつ壊すように作った。結果は **68 個（カナリアを含む）のすべてが検出・等価なし**。S03・C05 ははじめ緑だった（テストの穴。§ 計画を書く段階の実測の 19）ので、Task 4・Task 7 にテストを足してから測り直した（下の表の S03・C05 はその値。ほかはテストを足す前の写しで測った。コードは同じ）。

⚠ WT のファイルを一時的に壊す変異は、自動の許可の判定に断られる。**WT の HEAD の写しを scratchpad に作ってそこで当てる**（WT は読むだけ）。以下の `<scratchpad>` は、その会話の scratchpad のパス（Mac を再起動すると消える。残したい結果は `~/.claude/plans/approval-phase3-tasks/5a/` へ写す）:

```bash
SCR=<scratchpad>/p5a-mutation && mkdir -p "$SCR" && git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 archive HEAD | tar -x -C "$SCR" && cp -Rc /Users/masanori/site/manage/.claude/worktrees/approval-phase3/vendor "$SCR/vendor" && ! test -L "$SCR/vendor" && test -f "$SCR/app/Support/Approval/AmountTable.php" && echo "写し OK"
```

⚠ 写しの `storage/framework/views/*.php` は流す前に消す（道具が毎回消す。古いコンパイル済みのビューが残ると、ビューの変異が効かない）。

- [ ] **Step 1: 全件が緑の状態から始める**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git status --porcelain && APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')" /opt/homebrew/opt/php@8.3/bin/php ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `git status --porcelain` が空・`OK (4032 tests, 37997 assertions)`

- [ ] **Step 2: 変異を当てて表と突き合わせる**

道具は `~/.claude/plans/approval-phase3-tasks/5a/mutate.py`（4b の道具の写しで、変異の一覧だけ 5a にしたもの。1 つ当てて流し、必ず元に戻して、戻ったことを確かめる。書き換える前の文字列が 1 回だけ現れないものは当てずに SKIP と記録する。PHP は 8.3 を名指し）。先に `--check` で、当てる場所がちょうど 1 回ずつ見つかることを確かめる:

```bash
python3 ~/.claude/plans/approval-phase3-tasks/5a/mutate.py <scratchpad>/p5a-mutation /dev/null --check
python3 ~/.claude/plans/approval-phase3-tasks/5a/mutate.py <scratchpad>/p5a-mutation <scratchpad>/mutations.jsonl
```

Expected（1 行目）: `NG` の行が無く `checked 68`。1 つ流すのに 1 分ほど。写しを 3 つ作って ID を分けて並べて流してよい（引数の最後に ID を並べると、その ID だけを流す）。途中で止めるときは python に SIGINT（写しのファイルが元に戻る）。⚠ シェルの `&` で裏に回した python は SIGINT を受け付けない（非対話のシェルの決まり）。止めるときは SIGTERM で止め、写しは捨てて作り直す（当てた変異が写しに残るため）。同じ出力のファイルを渡して流し直すと、記録済みの変異を飛ばして続きから流す。

⚠ **最初の `CANARY`（申請の詳細の見出しに未定義の変数）が赤になること**を確かめてから結果を読む（測定が写しのコードを読んでいることの証明）。
⚠ **赤/緑ではなく「落ちたテストの集合」と「落ちた理由の文言」まで突き合わせる。** 意図と別の機構が落としているなら、その変異の測定は無効。当て方を変えて測り直す。

計画の時点の実測（試作。「落ちたテスト」はクラス名を省いたテストの名前）:

| # | 変異（ファイル） | 結果 | 落ちたテスト（実測） | 判定 |
|---|---|---|---|---|
| CANARY | カナリア: 申請の詳細の見出しに未定義の変数（`show.blade.php`） | Tests: 1134, Assertions: 7286, Failures: 88. | 88 本（AdminRequestsTest, NoticeScreenTest, RequestActionTest, RequestAttachmentTest ほか） | カナリア（赤が正しい） |
| N01 | ⑦ の氏名で登録名の空白を無視しない（D25）（`NameSearch.php`） | Tests: 1134, Assertions: 8035, Failures: 2. | `test_the_applicant_is_found_by_part_of_the_name_and_by_every_word`、`test_the_name_search_ignores_the_spaces_in_the_registered_name` | 検出 |
| N02 | 登録名の全角の空白を無視しない（半角だけ落とす）（`NameSearch.php`） | Tests: 1134, Assertions: 8038, Failures: 2. | `test_the_applicant_is_found_by_part_of_the_name_and_by_every_word`、`test_the_name_search_ignores_the_spaces_in_the_registered_name` | 検出 |
| N03 | 全角の空白・タブで語を分けない（⑦ と台帳の申請者が共有する部品。R-4）（`NameSearch.php`） | Tests: 1134, Assertions: 8041, Failures: 3. | `test_the_applicant_is_found_by_part_of_the_name_and_by_every_word`、`test_the_name_search_ignores_the_spaces_in_the_registered_name`、`test_words_are_split_on_half_and_full_width_spaces` | 検出 |
| N04 | ⑦ の検索語を空白で分けない（「山田 太郎」で「山田太郎」に当たらない）（`UserController.php`） | Tests: 1134, Assertions: 8038, Failures: 2. | `test_the_name_search_ignores_the_spaces_in_the_registered_name`、`test_the_search_box_looks_at_the_name_the_number_and_the_email` | 検出 |
| T01 | SQL の本文の形の既定を明細表に（本番の今の種類が明細表の種類になる）（`2026-10-07-approval-phase5a.sql`） | Tests: 1134, Assertions: 8032, Failures: 1. | `test_the_sql_and_the_migration_add_the_same_columns` | 検出 |
| T02 | migration の明細表の列を NOT NULL に（SQL と食い違う）（`2026_10_07_000001_add_approval_phase5a_columns.php`） | Tests: 1134, Assertions: 3070, Errors: 498, Failures: 1. | `test_a_blank_or_malformed_query_returns_nothing`、`test_a_circulating_request_stays_with_its_review_department`、`test_a_colleague_in_the_same_department_does_not_see`、`test_a_complete_draft_has_no_reasons`、`test_a_complete_request_can_be_submitted_from_the_form`、`test_a_conditional_approval_asks_the_applicant_to_confirm` ほか 469 本 | 検出 |
| T03 | SQL に担当者の列を足さない（本番で Unknown column）（`2026-10-07-approval-phase5a.sql`） | Tests: 1134, Assertions: 8029, Failures: 1. | `test_the_sql_and_the_migration_add_the_same_columns` | 検出 |
| T04 | 保存して読み直す前の種類の本文の形を決めない（$attributes の既定）（`ApprovalType.php`） | Tests: 1134, Assertions: 8050, Failures: 1. | `test_a_type_without_the_new_columns_is_a_points_type` | 検出 |
| T05 | 使える部門の無い種類を全部門で使えるにしない（D13）（`ApprovalType.php`） | Tests: 1134, Assertions: 7899, Errors: 1, Failures: 15. | `test_a_complete_request_can_be_submitted_from_the_form`、`test_a_table_request_needs_the_total_the_staff_and_the_contract_date`、`test_a_table_type_saves_the_rows_the_extras_and_the_fixed_text`、`test_a_total_beyond_twelve_digits_is_refused`、`test_copying_a_table_request_copies_the_rows_and_the_extras`、`test_inputs_are_normalized_before_saving` ほか 10 本 | 検出 |
| T06 | 選んだ申請部門で種類を使えるかを見ない（D13）（`ApprovalType.php`） | Tests: 1134, Assertions: 8045, Failures: 3. | `test_a_returned_request_is_not_stranded_by_a_change_of_the_usable_departments`、`test_a_type_must_be_usable_in_the_chosen_department`、`test_the_usable_departments_and_the_scope` | 検出 |
| T07 | 先に読んだ lastRevision が前の回の控えでも今の回として使う（R-1）（`ApprovalRequest.php`） | Tests: 1134, Assertions: 8049, Failures: 2. | `test_a_row_uses_only_the_revision_of_the_current_round`、`test_the_submitted_revision_is_the_one_of_the_current_round` | 検出 |
| A01 | 粗利率を四捨五入せずに切り捨てる（I-1。63.75% が 63.7%）（`AmountTable.php`） | Tests: 1134, Assertions: 8019, Failures: 10. | `test_the_detail_shows_the_table_the_extras_the_fixed_text_and_the_supplement`、`test_the_paper_follows_the_housing_form`、`test_the_rate_is_rounded_away_from_zero_with_integers`、`test_the_script_calculates_like_the_server`、`test_the_sheet_carries_the_rows_the_extras_and_the_fixed_text`、`test_the_shown_rows_hide_empty_free_rows_and_carry_the_profit_and_the_rate` ほか 1 本 | 検出 |
| A02 | 販売金額がマイナスの行の粗利率の符号を見ない（D3）（`AmountTable.php`） | Tests: 1134, Assertions: 8040, Failures: 3. | `test_the_rate_is_rounded_away_from_zero_with_integers`、`test_the_script_calculates_like_the_server` | 検出 |
| A03 | 「計」を使わない明細表にも計を出す（申請が持つ計の有無を見ない。D14）（`AmountTable.php`） | Tests: 1134, Assertions: 8049, Failures: 1. | `test_the_subtotal_and_the_total_follow_the_paper_form` | 検出 |
| A04 | 合計金額の販売金額 0 円も金額にする（0 円で提出できる。D2）（`AmountTable.php`） | Tests: 1134, Assertions: 8044, Failures: 2. | `test_a_table_request_needs_the_total_the_staff_and_the_contract_date`、`test_the_amount_is_the_total_sale_only_when_it_is_more_than_zero` | 検出 |
| A05 | 種類から名前が消えた行の金額を黙って捨てる（D16）（`AmountTable.php`） | Tests: 1134, Assertions: 8034, Failures: 3. | `test_the_edit_page_shows_the_saved_rows_in_the_layout_of_today`、`test_the_form_keeps_what_was_written_when_the_layout_changed`、`test_the_script_calculates_like_the_server` | 検出 |
| A06 | 同じ名前を設定した行を何度でも受け付ける（どの行が名前を設定した行かをサーバーが決め直さない。D15）（`AmountTable.php`） | Tests: 1134, Assertions: 8044, Failures: 2. | `test_a_table_type_saves_the_rows_the_extras_and_the_fixed_text`、`test_the_server_decides_which_rows_have_a_fixed_name` | 検出 |
| A07 | 名前のない行の工事原価だけの金額を見逃す（D2）（`AmountTable.php`） | Tests: 1134, Assertions: 8045, Failures: 2. | `test_a_table_request_needs_the_total_the_staff_and_the_contract_date`、`test_an_amount_without_a_name_is_found` | 検出 |
| A08 | 先頭の 0 を落とさない（画面は「0100」を 100 と読むのにサーバーが断る。M-2）（`FormInput.php`） | Tests: 1134, Assertions: 8041, Failures: 3. | `test_numbers_are_put_in_shape_before_the_validation`、`test_the_numbers_are_read_like_the_screen_and_the_note_is_named_so` | 検出 |
| A09 | 控えの追加の欄を FIELDS の並びにそろえない（MySQL が並べ替えた JSON のキーの並びのまま詳細・PDF に出す）（`RequestExtras.php`） | Tests: 1134, Assertions: 8049, Failures: 1. | `test_the_values_from_a_snapshot_follow_the_fixed_order` | 検出 |
| S01 | 申請が 1 件の種類は本文の形を切り替えられる（D4）（`TypeController.php`） | Tests: 1134, Assertions: 8040, Failures: 1. | `test_the_body_form_cannot_change_once_the_type_has_a_request` | 検出 |
| S02 | 本文の形の切り替えで下書きを数えない（D4「下書きを含む」）（`TypeController.php`） | Tests: 1134, Assertions: 8040, Failures: 1. | `test_the_body_form_cannot_change_once_the_type_has_a_request` | 検出 |
| S03 | 本文の形が送られてこない（押せない）ときに今の形でなく 5W2H として扱う（申請のある明細表の種類を保存できなくなる）（`TypeController.php`） | Tests: 1135, Assertions: 8053, Failures: 1. | `test_the_body_form_cannot_change_once_the_type_has_a_request` | 検出 |
| S04 | 5W2H の種類も明細表の行を持つ（D12）（`TypeController.php`） | Tests: 1134, Assertions: 8035, Failures: 6. | `test_a_points_type_keeps_no_layout_and_needs_the_headings`、`test_a_type_can_be_stopped`、`test_every_field_is_saved_and_logged_by_the_update`、`test_the_body_form_cannot_change_once_the_type_has_a_request`、`test_the_changes_are_logged_with_the_usable_departments`、`test_the_logs_keep_the_created_and_deleted_values` | 検出 |
| S05 | 明細表の種類でも隠れた見出しの欄の形で断る（M-4）（`TypeController.php`） | Tests: 1134, Assertions: 8047, Failures: 1. | `test_a_points_type_keeps_no_layout_and_needs_the_headings` | 検出 |
| S06 | 申請のある種類でも小窓で本文の形を押せる（D4 の画面）（`_type_body_form.blade.php`） | Tests: 1134, Assertions: 8026, Failures: 1. | `test_the_page_shows_the_form_and_the_departments_and_passes_the_values_to_the_modal` | 検出 |
| S07 | 編集の小窓に今の使える部門を渡さない（保存すると全部門に戻る）（`types.blade.php`） | Tests: 1134, Assertions: 8023, Failures: 1. | `test_the_page_shows_the_form_and_the_departments_and_passes_the_values_to_the_modal` | 検出 |
| Q01 | 明細表の種類で画面から送られた金額を使う（D15）（`RequestFields.php`） | Tests: 1134, Assertions: 8043, Failures: 1. | `test_a_table_type_saves_the_rows_the_extras_and_the_fixed_text` | 検出 |
| Q02 | 種類が使わない追加の欄も保存する（§5.5）（`RequestFields.php`） | Tests: 1134, Assertions: 8048, Failures: 1. | `test_the_unused_fields_are_not_kept` | 検出 |
| Q03 | 定型文を保存のときに種類から写さない（D14）（`RequestFields.php`） | Tests: 1134, Assertions: 8047, Failures: 1. | `test_a_table_type_saves_the_rows_the_extras_and_the_fixed_text` | 検出 |
| Q04 | マイナスの合計の 12 桁超えを見ない（T-6）（`RequestFields.php`） | Tests: 1134, Assertions: 8048, Failures: 1. | `test_a_total_beyond_twelve_digits_is_refused` | 検出 |
| Q05 | 工事原価のマイナスを断る（D3）（`RequestController.php`） | Tests: 1134, Assertions: 8048, Failures: 2. | `test_a_total_beyond_twelve_digits_is_refused`、`test_the_rows_and_the_extras_are_checked_even_for_a_draft` | 検出 |
| Q06 | 保存で自分の部門で使えない種類も通す（D13）（`RequestController.php`） | Tests: 1134, Assertions: 8045, Failures: 1. | `test_the_types_offered_are_those_usable_in_my_departments` | 検出 |
| Q07 | 明細表の種類の補足のエラー文を「重点ポイント（5W2H）は…」にする（M-7）（`RequestController.php`） | Tests: 1134, Assertions: 8047, Failures: 1. | `test_the_numbers_are_read_like_the_screen_and_the_note_is_named_so` | 検出 |
| Q08 | 合計金額の販売金額が無くても提出できる（D2）（`SubmitChecker.php`） | Tests: 1134, Assertions: 8046, Failures: 1. | `test_a_table_request_needs_the_total_the_staff_and_the_contract_date` | 検出 |
| Q09 | 空白だけの担当者・契約予定日で提出できる（D2）（`SubmitChecker.php`） | Tests: 1134, Assertions: 8047, Failures: 1. | `test_a_table_request_needs_the_total_the_staff_and_the_contract_date` | 検出 |
| Q10 | 差戻し中も、種類と部門を変えていないのに使える部門で止める（D13 の例外）（`SubmitChecker.php`） | Tests: 1134, Assertions: 8047, Failures: 1. | `test_a_returned_request_is_not_stranded_by_a_change_of_the_usable_departments` | 検出 |
| Q11 | 決まり文句だけの件名で提出できる（I-4）（`SubmitChecker.php`） | Tests: 1134, Assertions: 8048, Failures: 1. | `test_a_table_request_needs_the_total_the_staff_and_the_contract_date` | 検出 |
| J01 | 画面の粗利率を浮動小数で計算する（I-1。63.75% が画面だけ 63.7%）（`form.blade.php`） | Tests: 1134, Assertions: 8040, Failures: 1. | `test_the_script_calculates_like_the_server` | 検出 |
| J02 | 画面が「+1000」を読まない（サーバーは 1,000 円で保存する。M-2）（`form.blade.php`） | Tests: 1134, Assertions: 8039, Failures: 1. | `test_the_script_calculates_like_the_server` | 検出 |
| J03 | 前半が空でも決まり文句だけの件名を組み立てる（I-4）（`form.blade.php`） | Tests: 1134, Assertions: 8046, Failures: 1. | `test_the_script_calculates_like_the_server` | 検出 |
| J04 | 明細表の種類から 5W2H の種類へ選び直しても明細表が消えることを知らせない（I-3・D16）（`form.blade.php`） | Tests: 1134, Assertions: 8049, Failures: 1. | `test_the_script_calculates_like_the_server` | 検出 |
| J05 | 「元の種類に戻す」で種類の選択を戻さない（画面と送る種類が食い違う。I-3）（`form.blade.php`） | Tests: 1134, Assertions: 8050, Failures: 1. | `test_the_script_calculates_like_the_server` | 検出 |
| J06 | 画面で種類を選び直したとき、名前の消えた行の金額を黙って捨てる（D16）（`form.blade.php`） | Tests: 1134, Assertions: 8041, Failures: 1. | `test_the_script_calculates_like_the_server` | 検出 |
| J07 | 5W2H の種類に選び直しても、隠れた明細表の金額の欄を送る（M-4）（`_amount_table_input.blade.php`） | Tests: 1134, Assertions: 8045, Failures: 1. | `test_the_page_passes_the_type_settings_and_the_rows` | 検出 |
| J08 | 画面の定型文を HTML として描く（XSS）（`form.blade.php`） | Tests: 1134, Assertions: 8041, Failures: 1. | `test_the_page_passes_the_type_settings_and_the_rows` | 検出 |
| C01 | 控えに種類が使っていない追加の欄も入れる（D14）（`RequestSnapshot.php`） | Tests: 1134, Assertions: 8041, Failures: 3. | `test_a_points_request_shows_the_extras_and_the_fixed_text_below_the_body`、`test_the_applicant_sees_the_submitted_extras_after_the_submission`、`test_the_snapshot_keeps_what_was_submitted` | 検出 |
| C02 | 控えに明細表を入れない（ほかの人の詳細・PDF・台帳に明細表が出ない。D14）（`RequestSnapshot.php`） | Tests: 1134, Assertions: 7993, Errors: 2, Failures: 12. | `test_a_long_item_name_or_many_rows_do_not_shrink_the_sheet`、`test_an_edit_of_only_the_table_or_the_extras_counts_as_editing_since_the_return`、`test_others_see_the_submitted_table_while_the_applicant_edits_it`、`test_the_changes_match_the_rows_by_their_names`、`test_the_changes_show_the_rows_and_the_extras_that_changed`、`test_the_detail_shows_the_table_the_extras_the_fixed_text_and_the_supplement` ほか 8 本 | 検出 |
| C03 | 変更点の明細表を行の位置で合わせる（I-2）（`RequestSnapshot.php`） | Tests: 1134, Assertions: 8049, Failures: 1. | `test_the_changes_match_the_rows_by_their_names` | 検出 |
| C04 | 変更点に追加の欄（坪数・担当者など）を出さない（要件 4.4）（`RequestSnapshot.php`） | Tests: 1134, Assertions: 8040, Failures: 1. | `test_the_changes_show_the_rows_and_the_extras_that_changed` | 検出 |
| C05 | 明細表だけを直した出し直しを「変わっていない」にする（要件 4.4）（`RequestSnapshot.php`） | Tests: 1135, Assertions: 8054, Failures: 1. | `test_a_change_of_only_the_table_is_shown_as_a_change` | 検出 |
| C06 | 提出したあとも申請者本人の追加の欄を種類の今の設定で出す（M-5・D14）（`RequestContent.php`） | Tests: 1134, Assertions: 8048, Failures: 1. | `test_the_applicant_sees_the_submitted_extras_after_the_submission` | 検出 |
| C07 | ほかの人に差戻し中の直しかけの明細表を出す（漏れ）（`RequestContent.php`） | Tests: 1134, Assertions: 8047, Failures: 1. | `test_others_see_the_submitted_table_while_the_applicant_edits_it` | 検出 |
| C08 | 今の中身の指紋の追加の欄を種類の今の設定で絞る（M-1）（`UndoTarget.php`） | Tests: 1134, Assertions: 8049, Failures: 1. | `test_a_change_of_the_type_settings_is_not_an_edit_since_the_return` | 検出 |
| C09 | 指紋の追加の欄を 4 つにそろえない（種類の「使う」を変えただけで差戻しの取り消しを断る。M-1）（`RequestExtras.php`） | Tests: 1134, Assertions: 7970, Errors: 8, Failures: 3. | `test_a_change_of_the_type_settings_is_not_an_edit_since_the_return`、`test_every_judge_whose_judgement_was_undone_can_still_see_the_request`、`test_every_state_accepts_only_the_operations_in_the_table`、`test_pending_work_matches_after_the_admin_operations`、`test_the_open_edit_form_does_not_save_after_the_return_is_undone`、`test_undo_restores_the_state_before_the_operation` ほか 3 本 | 検出 |
| C10 | 詳細の明細表の項目名をエスケープしない（XSS）（`_amount_table_show.blade.php`） | Tests: 1134, Assertions: 8047, Failures: 1. | `test_the_names_in_the_table_are_escaped` | 検出 |
| C11 | 変更点の明細表をスマホでもカードにせず横スクロールにする（M-9）（`_changes.blade.php`） | Tests: 1134, Assertions: 8045, Failures: 1. | `test_the_changes_show_the_rows_and_the_extras_that_changed` | 検出 |
| P01 | PDF の定型文を担当者・契約予定日の後に載せる（§5.7 の並び。I-5）（`pdf.blade.php`） | Tests: 1134, Assertions: 8045, Failures: 2. | `test_a_points_request_shows_the_extras_and_the_fixed_text_below_the_body`、`test_the_paper_follows_the_housing_form` | 検出 |
| P02 | PDF の明細表の見出しの行を thead に入れない（次のページの頭に見出しが出ない。M-8）（`pdf.blade.php`） | Tests: 1134, Assertions: 8050, Failures: 1. | `test_the_paper_follows_the_housing_form` | 検出 |
| P03 | PDF の明細表の表に wrap を付けない（空白の無い長い項目名で表の文字が縮む。落とし穴 ②）（`pdf.blade.php`） | Tests: 1134, Assertions: 8048, Failures: 1. | `test_the_paper_follows_the_housing_form` | 検出 |
| P04 | PDF の明細表の項目名をエスケープしない（XSS）（`pdf.blade.php`） | Tests: 1134, Assertions: 8047, Failures: 1. | `test_the_sheet_escapes_the_table_the_extras_and_the_fixed_text` | 検出 |
| P05 | PDF の定型文を逃がさずに改行だけ <br> にする（XSS）（`pdf.blade.php`） | Tests: 1134, Assertions: 8049, Failures: 1. | `test_the_sheet_escapes_the_table_the_extras_and_the_fixed_text` | 検出 |
| E01 | ほかの人の台帳の行・Excel に直しかけの明細表の工事原価などを出す（漏れ）（`LedgerRow.php`） | Tests: 1134, Assertions: 8049, Failures: 1. | `test_the_rate_is_empty_when_the_total_sale_is_zero` | 検出 |
| E02 | Excel の粗利率を 19.3 のまま入れる（0.0% の書式で 1930.0% と出る。D19）（`LedgerExcel.php`） | Tests: 1134, Assertions: 8044, Failures: 1. | `test_the_four_columns_of_a_table_request` | 検出 |
| E03 | 販売金額 0 の粗利率を空欄でなく 0.0% にする（D19）（`LedgerRow.php`） | Tests: 1134, Assertions: 8043, Failures: 3. | `test_a_points_type_that_uses_the_contract_date_fills_only_that_column`、`test_the_columns_and_the_values_of_a_row`、`test_the_rate_is_empty_when_the_total_sale_is_zero` | 検出 |
| E04 | Excel の契約予定日を日付でなく文字で入れる（`LedgerExcel.php`） | Tests: 1134, Assertions: 8046, Errors: 1, Failures: 1. | `test_a_points_type_that_uses_the_contract_date_fills_only_that_column`、`test_the_four_columns_of_a_table_request` | 検出 |
| E05 | 決裁日の年の下限を外す（0000-01-01 が MySQL の TIMESTAMP と比べられない。§8）（`LedgerFilter.php`） | Tests: 1134, Assertions: 8049, Failures: 1. | `test_a_decided_date_outside_the_years_is_left_out` | 検出 |

- [ ] **Step 3: 表と違ったものを調べ、検出できなかった変異にテストを足す**

⚠ **等価**（緑が正しい）と書いたものは、なぜ等価かを 1 行で確かめる（読み違えて「守られていない」としない）。等価でないのに緑のものは、その Task のテストに 1 本足し、足したテストが変異で赤・元に戻して緑になることを確かめてからコミットする。

- [ ] **Step 4: 結果をこの計画に追記してコミット**

検出／当初検出漏れ→追加で検出／等価を区別して、この計画の末尾に「Task 10 の実測記録」として書き足す。

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add docs/superpowers/plans/2026-10-07-approval-phase5a.md tests/
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
test(approval): 段階5a の変異テストの結果を記録する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

写し（`<scratchpad>/p5a-mutation`）は、この Task が済んだら消してよい（`rm -rf` の前に中身が写しであることを確かめる）。

---

## Task 11: 手元のブラウザでの確認と、利用者に見せる写真・PDF・Excel（設計書 §6）

**Files:** なし（測るだけ）

テストが原理的に測れない領域（⑨ の小窓の見た目・② の明細表のその場の計算と打ち心地・スマホの幅のカード・種類の選び直しの知らせ・③ の詳細と変更点の見た目・PDF の紙面・Excel を開いたときの見た目）を見る。**本番では使い始めるまで ⑨ 以外は出ないので、利用者に見てもらうのはここで撮る写真と PDF・Excel の画像**。WT の HEAD の写し（scratchpad）＋使い捨ての SQLite ＋ `artisan serve`。ブラウザは Playwright（ログインは利用者の決まり「手元の画面にログイン」のとおり、試しのパスワードを画面にも記録にも出さない）。Excel は開かずに確かめる（利用者の決まり。クイックルックと読み戻し）。

- [ ] **Step 1: 写しと使い捨ての環境を作る**

⚠ Bash の呼び出しごとにシェルが新しくなるので、使い捨ての設定は 1 つのファイルにまとめ、以降のコマンドの先頭で `source` する。⚠ WT にも写しにも `.env` を作らない。⚠ `APP_LOCALE=ja`・`APP_FALLBACK_LOCALE=ja` を入れる。⚠ `php` は 8.3 を名指しする。

```bash
SCR=<scratchpad>/p5a-browser && mkdir -p "$SCR" && git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 archive HEAD | tar -x -C "$SCR" && cp -Rc /Users/masanori/site/manage/.claude/worktrees/approval-phase3/vendor "$SCR/vendor"
LOCAL=<scratchpad>/approval-phase5a-local.sh
cat > "$LOCAL" <<SH
export PHP=/opt/homebrew/opt/php@8.3/bin/php
export APP_KEY="base64:$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo base64_encode(random_bytes(32));')"
export APP_LOCALE=ja
export APP_FALLBACK_LOCALE=ja
export APP_URL=http://127.0.0.1:8771
export DB_CONNECTION=sqlite
export DB_DATABASE="<scratchpad>/approval-phase5a.sqlite"
export MAIL_MAILER=log
export QUEUE_CONNECTION=sync
export TRIAL_PASSWORD="$(/opt/homebrew/opt/php@8.3/bin/php -r 'echo bin2hex(random_bytes(6));')"
SH
source "$LOCAL" && touch "$DB_DATABASE" && cd "$SCR" && $PHP artisan migrate --force && ln -s /Users/masanori/site/manage/node_modules node_modules && ./node_modules/.bin/vite build
```

- [ ] **Step 2: 試しのデータを入れる**（テストの土台と同じ形。⚠ ログイン用の使い捨てルートを作らない）

計画を書く段階に使った入れ方（`~/.claude/plans/approval-phase3-tasks/5a/seed.php`。会社・部門〈住宅事業部 J・ミツワ不動産 M・総務部 S〉・決裁の管理者・申請者・部門長・審査担当者・社長、要件 5.5.7 の 2 つの種類〈請負新築工事契約・追加・少額工事契約。使える部門は住宅事業部とミツワ不動産〉と 5W2H の種類、見本の数で提出した明細表の申請・差戻しのあと直して出し直した申請・下書き）:

```bash
source <scratchpad>/approval-phase5a-local.sh && cd <scratchpad>/p5a-browser && $PHP artisan tinker --execute="$(cat ~/.claude/plans/approval-phase3-tasks/5a/seed.php)"
```

Expected: 種類と申請の件数と、決裁の管理者・申請者のログイン名。パスワードは `source` したシェルの `$TRIAL_PASSWORD`（値を出さない）。

画面を出す（**バックグラウンドで**。止めるまで動き続ける）:

```bash
source <scratchpad>/approval-phase5a-local.sh && cd <scratchpad>/p5a-browser && $PHP artisan serve --port=8771
```

- [ ] **Step 3: 見ること**（1440px と 375px の両方）

| # | 画面 | 見ること |
|---|---|---|
| 1 | ⑨ 一覧と小窓（決裁の管理者） | 一覧の「金額の明細表 住宅事業部・ミツワ不動産」・小窓の本文の形のラジオ（申請がある種類は押せない注記）・明細表の行の「足す・消す・上へ・下へ」・「計」の行・決まり文句・追加の欄・定型文・使える部門のチェック。保存して開き直すと同じ値 |
| 2 | ② 新しい申請（申請者・1440px） | 請負新築工事契約を選ぶと、明細表（見本の行と空の自由行）・件名の前半と決まり文句・追加の欄・定型文・補足。見本の数を打つと計 29,700,000 円・合計金額 41,700,000 円・粗利率 19.3%・紹介料の粗利率は「—」・欄を離れるとカンマ付き。前半を空にすると見本が「（前半を入れてください）」 |
| 3 | ② の 375px | 明細表が 1 行 1 枚のカード・横のはみ出し 0・「＋ 前半に行を足す」 |
| 4 | ② 種類の選び直し | 明細表に金額を入れてから 5W2H の種類を選ぶと「選んだ種類は明細表・担当者…を使いません。このまま保存すると、入れた内容は消えます。」と「元の種類に戻す」。戻すと金額が残っている |
| 5 | ② 提出の断り | 担当者・契約予定日を空・前半を空で提出すると理由がまとめて出る。そろえると提出できる |
| 6 | ③ 詳細（申請者以外・1440px と 375px） | 明細表（空の自由行は出ない）・計・合計金額・坪数・坪単価・担当者・契約予定日・定型文・補足。375px はカード |
| 7 | ③ 変更点（出し直した申請） | 明細表の行の違い（変わった欄は前 → 後・増えた行・消えた行）と追加の欄。375px はカード |
| 8 | PDF（明細表の種類・行の多い申請） | 「（記）」の下の明細表 → 坪数・坪単価 → 定型文 → 担当者・契約予定日 → 補足。2 ページ目の頭に明細表の見出し。`pdftoppm -r 80` の画像で見る |
| 9 | Excel（台帳から。`page.request` で取り出す） | 19 列・工事原価・粗利益金額・粗利率（19.3%）・契約予定日（日付）。クイックルック（`qlmanage -t -s 1600`）か、読み戻して HTML にした画像（`~/.claude/plans/approval-phase3-tasks/5a/xlsx2html.php`） |
| 10 | 全画面 | `main.scrollWidth === main.clientWidth` を 1440 / 375px で（Bug #29）・コンソールのエラーと警告が 0 件 |

- [ ] **Step 4: 利用者に見せる写真・PDF・Excel**

1440px と 375px の ⑨・②（打ったあと・選び直しの知らせ）・③（詳細・変更点）と、PDF と Excel の画像を撮って scratchpad に保存し、利用者に送る（`SendUserFile`）。

- [ ] **Step 5: コンパイル済みビューを lint する**

⚠ `view:cache` の成功表示だけでは足りない（Bug #21 / #26 / #30）。

```bash
source <scratchpad>/approval-phase5a-local.sh && cd <scratchpad>/p5a-browser && $PHP artisan view:cache && for f in storage/framework/views/*.php; do $PHP -l "$f" >/dev/null || echo "INVALID: $f"; done; $PHP artisan view:clear
```

Expected: INVALID 0 件。

- [ ] **Step 6: 片付けて結果を記録する**

`artisan serve` を止めたことを確かめてから、写しと使い捨てのファイルを消す（`rm -rf` の前に、消すのが scratchpad の写しであることを `pwd` と `ls` で確かめる）。WT の `git status --porcelain` が空であることも確かめる。見たことと見つけた不具合を、この計画の末尾に「Task 11 の実測記録」として書き足してコミットする（`docs(approval): 段階5a の画面と PDF・Excel の確認の結果を記録する`）。

---

## Task 12: ドキュメント

**Files:**
- Modify: `docs/決裁申請_要件定義書_v1.md`（v1.13 → **v1.14**。5a の分だけ。5b の分〈11 章・6.4・13 章の ⑪ など〉は 5b の計画で v1.15 にする）・`docs/BACKLOG.md`（段階4 の節の「段階5 で Excel の列を足すときの注意」と、新しい段階5 の節）・`CLAUDE.md`（決裁の注意の行と、モジュールの表の決裁の行）

- [ ] **Step 1: 要件定義書を v1.14 にする**（設計書 §3 のうち 5a の分。⚠ 文の書き換えは下のとおりに。ほかの行は変えない）

1. 1 行目の題を `# 決裁申請システム 要件定義書 v1.14` にし、変更履歴の表の最後に 1 行足す:

```markdown
| v1.14 | 2026/10/07 | 段階 5 の設計の対話（2026/10/07）で決まった変更のうち、前半（5a: 申請の種類ごとの作り込み）の分を反映した（設計書は `docs/superpowers/specs/2026-10-07-approval-phase5-design.md`。後半〈5b: 過去の取り込み〉の分は 5b を作るときに反映する）。(1) **申請（下書きを含む）がある種類は、本文の形を切り替えられない**（変えたいときは新しい種類を作り、古い種類を停止する。5.5.1）。(2) 件名の決まり文句・追加の入力欄・定型文・使える部門は、どちらの本文の形でも使える。行の名前などを変えても提出済みの申請は変わらない。使える部門は提出のときに確かめ、差戻し中は種類と部門を変えていなければそのまま出し直せる（5.5.1）。(3) 明細表の販売金額・工事原価は**マイナスも入れられる**。提出には合計金額の販売金額が 0 円より大きいことと、金額の入った自由行の項目名が要る。担当者と契約予定日は使う種類では提出に必須（5.5.4・5.5.5）。(4) 明細表の種類の金額は保存のときに合計金額の販売金額から計算する（5.1）。(5) 決裁台帳の画面の列は段階 4 のまま（工事原価などは Excel で見る）。粗利率は Excel の数で、販売金額 0 は空欄（10）。(6) 明細表は申請の中にまとめて持つ（15.5）。段階 5 を 2 回に分ける（16.1） |
```

2. 5.1 の表の「金額」の行の備考の最後の文「**金額の明細表を使う種類では入力せず、明細表の「合計金額」行の販売金額を自動で使う**（5.5.4）」を「**金額の明細表を使う種類では入力せず、保存のときに明細表の「合計金額」行の販売金額から計算する**（画面から送られた数は使わない。5.5.4）」にする
3. 5.5.1 の表の下の箇条（「申請部門は 5.1 のとおり…」の前）に 4 つ足す:

```markdown
- **申請（下書きを含む）が 1 件でもある種類は、本文の形を切り替えられない**。変えたいときは新しい種類を作り、古い種類を停止する（書きかけの中身が消えることが無い）。
- 件名の決まり文句・追加の入力欄・定型文・使える部門は、「5W2H の見出し」の種類でも「金額の明細表」の種類でも使える。見出しは 5W2H の種類だけ、明細表の行と「計」の行は明細表の種類だけ。
- 行の名前・定型文・件名の決まり文句を変えても、変わるのはこれから作る申請と、まだ出していない下書き（次に保存したとき）だけ。提出済みの申請は提出したときのまま。
- 使える部門は、申請の画面の種類の選択肢（自分の所属部門のどれかで使える種類）と、提出のとき（選んだ申請部門でその種類を使えるか）に確かめる。**差戻し中の申請は、種類と申請部門を前の提出から変えていなければそのまま出し直せる**（停止した種類と同じ考え）。
```

4. 5.5.4 の 5 列の表の下（「合計の行は 2 つ。…」の前）に足す:

```markdown
- 販売金額・工事原価は**マイナスも入れられる**（値引きの行など。−999,999,999,999〜999,999,999,999 円。合計も 12 桁まで）。提出には「合計金額」行の販売金額が 0 円より大きいことが要る。名前のない自由行に金額を入れたら、項目名も入れないと提出できない。下書きは形が合っていれば空でも保存できる。
- 粗利率は小数第 2 位で四捨五入する（0 から遠い方へ。画面・詳細・PDF・Excel で同じ数になるよう、整数で計算する）。
```

5. 5.5.5 の表の「坪数」の形式を「数字（小数第 2 位まで）」に、「契約予定日」の扱いを「**実施時期（5.1）とは別の欄**。下記のとおり意味が違う。担当者とともに、使う種類では**提出に必須**（坪数・坪単価は空でもよい）」にし、「担当者」の扱いの最後に「。使う種類では提出に必須」を足す
6. 10 章の 2 つ目の箇条「段階 4 では、システムの申請（5W2H の種類）で台帳と Excel 出力を作る。…段階 5 で足す（16.1）。」を次に置き換える:

```markdown
- 段階 4 で、システムの申請（5W2H の種類）で台帳と Excel 出力を作り、段階 5 の前半（5a）で金額の明細表の種類の列（工事原価・粗利益金額・粗利率・契約予定日）を Excel に足した。取り込んだ紙の決裁（区分・備考・判断の「不明」）は段階 5 の後半（5b）で足す（16.1）。
- **台帳の画面の列は段階 4 のまま**（決裁No・決裁日・判断・件名・申請部門・申請者・金額・状態）。工事原価・粗利益金額・粗利率・契約予定日は Excel で見る（パソコンの画面で 11 列になると件名が細くなるため）。キーワードは件名と本文（明細表の種類では補足）で当て、明細表の行の名前では当てない。
- Excel の粗利率は数（12.3% と出る書式。並べ替え・計算ができる）。販売金額が 0 のときは空欄（「—」の文字を入れると並べ替えや計算ができない）。
```

7. 15.5 の表の `approval_types` の行を「申請の種類（本文の形・見出し・件名の決まり文句・定型文・**明細表の行と「計」の行の有無（JSON）**・使う追加の入力欄・審査部門・並び順・利用中/停止）」にし、`approval_type_rows` の行を消す。`approval_requests` の行の最後を「…各日時、**金額の明細表（JSON。前半・後半の行の名前・名前を設定した行か・販売金額・工事原価。粗利益金額と粗利率は計算で出す）**、および使う種類のみ 坪数・坪単価・担当者・契約予定日、定型文（保存したときに種類から写す）」にし、`approval_request_rows` の行を消す。表の下に「明細表を別の表にせず申請の中に持つのは、保存・控え・変更点・台帳・PDF が同じ 1 つの形を読み、読み違いが起きにくいため（段階 5 の設計 D5）。明細表の行の名前では探せない」を足す
8. 16.1 の表の段階 5 の行を「過去の決裁の取り込み、申請の種類ごとの作り込み（5.5。部門専用の様式は住宅・不動産の契約用だけで、確定済み）。**2 回に分けて作る**（5a: 申請の種類ごとの作り込み〈⑨ の設定・申請の画面・詳細と変更点・PDF・台帳の Excel の列〉／ 5b: 過去の紙の決裁の取り込み〈⑪・台帳に紙・関連する決裁No〉）。作り込みを先にする」にする。段階 4 の行の「金額の明細表などの列と紙の決裁は段階 5 で足す」は「…段階 5 で足す（5a で明細表の列、5b で紙の決裁）」にする

- [ ] **Step 2: BACKLOG に段階5 の節を足す**

(a) 段階4 の節の 4b の小見出しの「⚠ **段階5 で Excel の列を足すときの注意**: …列を足すときは 1 つの定義から作る形に直す」の行の頭に「✅（段階5a で `LedgerExcel::columns()` の 1 つの定義から作る形に直した）」を足す（消さない）。

(b) 段階4 の節（`## 🚧 決裁申請 段階4（…）`）の後ろ、次の `## ` の前に、次の節を足す（⚠ ファイルの末尾に足さない）:

````markdown
## 🚧 決裁申請 段階5（申請の種類ごとの作り込み・過去の決裁の取り込み）— 5a 実装済み・本番反映前

設計書: @docs/superpowers/specs/2026-10-07-approval-phase5-design.md（D1〜D25）。2 回に分ける（5a: 申請の種類ごとの作り込み ／ 5b: 過去の取り込み）。

### 5a（申請の種類ごとの作り込み）

実装計画: @docs/superpowers/plans/2026-10-07-approval-phase5a.md（Task 0〜13）。worktree `.claude/worktrees/approval-phase3`（4b と同じ）。

- 表: `approval_types` に本文の形（`body_form`）・明細表の行（`table_layout`。JSON）・件名の決まり文句・追加の欄の使う／使わない 4 つ・定型文、`approval_requests` に明細表（`amount_table`。JSON）・坪数・坪単価・担当者・契約予定日・定型文、使える部門の表 `approval_type_department`。本番の SQL は `database/sql/2026-10-07-approval-phase5a.sql`（3 文）。本番反映は **DB が先・`./deploy.sh` が後**
- 明細表の形と計算は `AmountTable` の 1 か所（画面の JS も同じ式。**粗利率は整数で計算**＝浮動小数の丸めと PHP の版で答えが変わらない）。追加の欄は `RequestExtras`、保存する列は `RequestFields`（明細表の種類の金額はサーバーが合計金額の販売金額から計算・使わない欄は空・定型文は種類から写す）、提出の条件は `SubmitChecker`（合計金額・担当者・契約予定日・名前のない行の項目名・決まり文句だけの件名・使える部門〈差戻し中は種類と部門を変えていなければ従わない〉）
- 申請者以外に見せる中身は最後に提出した控えから（`ApprovalRequest::submittedRevision()` が 1 つの入口。詳細・PDF・台帳・Excel・メールの件名・差戻しの取り消し・提出の条件）。変更点の明細表は行の鍵（名前を設定した行か・項目名）で合わせる
- 数の入力のそろえ方と前後の空白の落とし方は `FormInput::digits()`・`FormInput::trim()`。⑦ と台帳の氏名の探し方は `NameSearch`
- MySQL は JSON のキーを並べ替えて返す: テストで JSON の列を丸ごと比べるときは `assertSameIgnoringKeyOrder()`（`ComparesJsonColumns`。`assertEquals` は 0 と null を同じとみなす）
- 計画で決めた細部（計画 §0.14）: 5W2H の種類でも契約予定日を使えば Excel に入る（設計書 §5.8 から変えた・利用者に確認済み）／控えに本文の形は入れない（明細表の有無で分かる）／決まり文句だけの件名は提出で断る／種類の選び直しは、保存で消えるものを知らせて「元の種類に戻す」
- 受け入れた隙間（計画 §0.15）: 申請の行は各 30 行まで（⑨ で名前を全部付け替えると超えることがある）／項目名を直した自由行は変更点で「消えた行＋増えた行」／明細表の行の名前では探せない／Web のメモリの上限は CLI と同じ 128M と見ている／中間表の外部キーの名前が SQL と migration で違う
- 実装のコミット（計画のコミットの次から。古い順）: Task 1〜9 の 9 本と記録（Task 10・11）。`git log` で見る

### 5b（過去の決裁の取り込み）

未着手。5a を本番に出したあとで計画を書く（設計書 §5.9〜§5.15）。
````

- [ ] **Step 3: CLAUDE.md を直す**

`## 主要モジュール`（モジュールの表）の決裁の行の頭の「決裁申請 段階1〜4b」を「決裁申請 段階1〜5a」にし、「決裁台帳と Excel（⑤。…）」の後ろに「・申請の種類ごとの作り込み（明細表の形と計算は `AmountTable` だけ）」を足す。決裁の注意の行（決裁台帳の行）の後ろに 1 行足す:

```markdown
- 決裁の金額の明細表は申請の JSON の 1 列（`amount_table`）。形と計算は `App\Support\Approval\AmountTable` の 1 か所（画面の JS も同じ式。粗利率は整数で計算する）。申請者以外に見せる中身は最後に提出した控え（`ApprovalRequest::submittedRevision()`）から読む。MySQL は JSON のキーを並べ替えて返すので、並びに意味のあるもの（行）は配列にし、テストで JSON の列を丸ごと比べるときは `assertSameIgnoringKeyOrder()`
```

- [ ] **Step 4: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add docs/決裁申請_要件定義書_v1.md docs/BACKLOG.md CLAUDE.md
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
docs(approval): 段階5a の決まりを要件定義書 v1.14・BACKLOG・CLAUDE.md に書く

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

コミットのあとで `git merge-tree --write-tree approval-phase3 13.x` が衝突を出さないことを確かめる（BACKLOG は別の会話も書く）。

---

## Task 13: 本番反映（利用者の了承を取ってから・親の会話が行う）

> ⚠ **サブエージェントに任せない。** 本番への `ssh`・`scp`・`./deploy.sh` は、それぞれ利用者の了承を取ってから行う。本番のファイルの削除は利用者が行う（実行する 1 行を渡す）。`.env` は読まない。
> ⚠ 本番のシェルは csh なので、`/bin/sh` の heredoc を `ssh` に流す（段階1〜4b と同じ作法）。PHP は `/usr/local/php/8.3/bin/php` を明示する（既定の `php` は 7.4）。
> ⚠ 別の会話（manage）が `13.x` を頻繁に進めて本番に出している。反映の前に `SendMessage` で、反映が済むまで `13.x` の早送りと `./deploy.sh` を待ってもらう（4b と同じ）。

- [ ] **Step 1: 了承を求める（選択式）**

伝えること: ①**DB が先・`./deploy.sh` が後**（新しいコードが種類と申請の新しい列を読むので、逆だと ⑨ と申請の画面が 500 になる）②本番の表 `approval_types` と `approval_requests` に列を足し、`approval_type_department` を作る（今の種類は「5W2H の見出し」になるだけ。データは変えない）③**部品の追加は無い**（`composer install` は要らない）④**使い始める前なので、利用者に見える変化は ⑨（申請種類の管理）の新しい欄だけ**（申請の画面・PDF・台帳は使い始めるまで出ない）⑤戻すときは、`13.x` の前のコミットで `./deploy.sh`（足した列と表は残しても害は無い）。

- [ ] **Step 2: 反映前に本番を読み取る**（読み取りだけ）

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage || exit 1
/usr/local/php/8.3/bin/php artisan route:list --name=approvals. --json | /usr/local/php/8.3/bin/php -r '$r = json_decode(stream_get_contents(STDIN), true); echo "approvals=", count($r), PHP_EOL;'
/usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
$schema = $db->getSchemaBuilder();
echo "body_form=", $schema->hasColumn("approval_types", "body_form") ? "ある" : "ない", " amount_table=", $schema->hasColumn("approval_requests", "amount_table") ? "ある" : "ない", " type_department=", $schema->hasTable("approval_type_department") ? "ある" : "ない", PHP_EOL;
echo "launched_at=", var_export(App\Models\ApprovalSetting::current()->launched_at, true), PHP_EOL;
echo "types=", $db->table("approval_types")->count(), " requests=", $db->table("approval_requests")->count(), " revisions=", $db->table("approval_revisions")->count(), PHP_EOL;
echo "memory_limit=", ini_get("memory_limit"), PHP_EOL;
'
ls -la storage/logs/laravel.log
SH
```

Expected: `approvals=` の数（控えておく。5a はルートを足さない）／ `body_form=ない amount_table=ない type_department=ない` ／ `launched_at=NULL` ／ 申請 0・控え 0（使い始める前。種類の数は控えておく）／ `memory_limit=128M` ／ `laravel.log` の日付。**1 つでも違えば止まり、利用者に伝える**（申請が 1 件でもあれば、⑨ で本文の形を切り替えられない種類がある＝想定どおりだが、利用者に伝える）。

- [ ] **Step 3: `13.x` へ早送りで取り込む**（手元）

```bash
cd /Users/masanori/site/manage && git status --short && git merge-base --is-ancestor 13.x approval-phase3 && echo "FF できる" || echo "13.x が進んでいる"
```

「FF できる」なら:

```bash
git -C /Users/masanori/site/manage merge --ff-only approval-phase3 && git -C /Users/masanori/site/manage log --oneline -3 && ls /Users/masanori/site/manage/database/sql/2026-10-07-approval-phase5a.sql
```

「13.x が進んでいる」なら止まり、取り込み方（WT で `git merge 13.x` をしてから全件を流し直す、など。rebase しない）を利用者に選んでもらう（ほかの会話の作業が入っている）。

- [ ] **Step 4: DB を先に変える**

(a) SQL を本番の置き場所へ送る（`./deploy.sh` もあとで同じ場所へ同じものを送る）:

```bash
scp /Users/masanori/site/manage/database/sql/2026-10-07-approval-phase5a.sql mitsuwa-ud@www3586.sakura.ne.jp:apps/manage/database/sql/
```

(b) 流す前に、文の数と頭を見る（まだ何も変えない）:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$sql = preg_replace("/^--.*$/m", "", file_get_contents(base_path("database/sql/2026-10-07-approval-phase5a.sql")));
$statements = array_values(array_filter(array_map("trim", explode(";", $sql))));
echo "statements=", count($statements), PHP_EOL;
foreach ($statements as $i => $s) { echo $i + 1, ": ", strtok($s, "\n"), PHP_EOL; }
'
SH
```

Expected: `statements=3`（`ALTER TABLE \`approval_types\``・`CREATE TABLE \`approval_type_department\` (`・`ALTER TABLE \`approval_requests\``）。

(c) 流す（**すでに流した形跡があれば流さずに止まる**）:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
$schema = $db->getSchemaBuilder();
$traces = array_keys(array_filter([
    "approval_types.body_form"        => $schema->hasColumn("approval_types", "body_form"),
    "approval_type_department"        => $schema->hasTable("approval_type_department"),
    "approval_requests.amount_table"  => $schema->hasColumn("approval_requests", "amount_table"),
]));
if ($traces !== []) {
    echo "STOP: すでに流した形跡がある: ", implode(", ", $traces), PHP_EOL;
} else {
    $sql = preg_replace("/^--.*$/m", "", file_get_contents(base_path("database/sql/2026-10-07-approval-phase5a.sql")));
    foreach (array_values(array_filter(array_map("trim", explode(";", $sql)))) as $i => $statement) {
        $db->statement($statement);
        echo "OK ", $i + 1, PHP_EOL;
    }
}
'
SH
```

Expected: `OK 1`・`OK 2`・`OK 3`。⚠ 例外が出たら、その文言を利用者に伝えて止まる。流し直さない（途中の文まで流れていれば、どこまで流れたかを読み取ってから利用者と決める）。

(d) 流した後を読み取る:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
foreach (["approval_types" => ["body_form", "table_layout", "subject_suffix", "uses_tsubo", "uses_tsubo_price", "uses_staff", "uses_contract_date", "fixed_text"], "approval_requests" => ["amount_table", "tsubo", "tsubo_price", "staff", "contract_date", "fixed_text"]] as $table => $columns) {
    foreach ($columns as $c) {
        $col = $db->selectOne("SHOW FULL COLUMNS FROM {$table} WHERE Field = ?", [$c]);
        echo $table, ".", $col->Field, " ", $col->Type, " null=", $col->Null, " default=", var_export($col->Default, true), PHP_EOL;
    }
}
foreach ($db->select("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY CONSTRAINT_NAME", ["approval_type_department"]) as $k) {
    echo "constraint ", $k->CONSTRAINT_NAME, PHP_EOL;
}
echo "types=", $db->table("approval_types")->count(), " points=", $db->table("approval_types")->where("body_form", "points")->count(), " requests=", $db->table("approval_requests")->count(), PHP_EOL;
'
SH
```

Expected: §0.2 の型（`body_form varchar(20) null=NO default='points'`・`uses_* tinyint(1) null=NO default='0'`・`table_layout json`・`amount_table json`・`tsubo decimal(7,2)`・`tsubo_price bigint unsigned`・`staff varchar(50)`・`contract_date date`・`fixed_text text`。どれも新しい列は `null=YES`〈`body_form` と `uses_*` を除く〉）／ 制約 `PRIMARY`・`fk_approval_type_department_dept`・`fk_approval_type_department_type` ／ 種類はすべて `points`（`types` と `points` が同じ数）・申請の数は Step 2 と同じ。

- [ ] **Step 5: 読み込みの表を作り直す**（手元の main repo で。部品の追加は無いので `composer install` は打たない）

```bash
cd /Users/masanori/site/manage && test ! -e vendor/bin/phpunit && /opt/homebrew/opt/php@8.3/bin/php "$(command -v composer)" dump-autoload --no-dev --optimize && test ! -e vendor/bin/phpunit && git status --short && grep -cE 'App\\\\(Support\\\\Approval\\\\(AmountTable|RequestExtras|RequestFields|NameSearch)|Enums\\\\ApprovalBodyForm)' vendor/composer/autoload_classmap.php
```

Expected: `vendor/bin/phpunit` が無いまま（dev の部品が混ざっていない）・`git status` に何も出ない・最後の数が `5`（新しい 5 つのクラスが読み込みの表に入った。今は `0`）。⚠ **main repo の cwd で行う**（worktree から行うと autoloader に worktree のパスが焼き込まれる）。⚠ `composer` も PHP 8.3 で動かす（この Mac の `php` は 8.5。本番は 8.3）。

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
foreach (["App\\Support\\Approval\\AmountTable", "App\\Support\\Approval\\RequestExtras", "App\\Support\\Approval\\RequestFields", "App\\Support\\Approval\\NameSearch", "App\\Enums\\ApprovalBodyForm"] as $c) {
    echo $c, "=", class_exists($c) || enum_exists($c) ? "ok" : "NG", PHP_EOL;
}
echo "rate=", App\Support\Approval\AmountTable::rateLabel(App\Support\Approval\AmountTable::rate(8000000, 5100000)), PHP_EOL;
$type = App\Models\ApprovalType::query()->first();
echo "first_type_form=", $type?->body_form?->value ?? "（種類なし）", " usable_everywhere=", $type === null ? "-" : ($type->departments()->count() === 0 ? "yes" : "no"), PHP_EOL;
'
ls -la storage/logs/laravel.log
SH
```

Expected: `invalid=0` ／ `approvals=` が Step 2 と同じ ／ `launched_at=NULL` ／ 5 つとも `ok` ／ `rate=63.8%` ／ `first_type_form=points usable_everywhere=yes`（種類が無ければ「（種類なし）」）／ `laravel.log` の日付が Step 2 と同じ＝反映のあとのエラーは 0 件。

- [ ] **Step 8: 利用者の Chrome で見るだけ**（決裁の管理者でログイン済みの画面。フォームは送らない。URL に `index.php/` が要る）

申請種類の管理（`…/index.php/approvals/admin/types`）の一覧に「5W2H の見出し 全部門」が出る・「編集」の小窓に本文の形・明細表の行・決まり文句・追加の欄・定型文・使える部門が出る（**保存は押さない**。閉じる）・決裁のホームは「準備中」のまま・コンソールのエラー 0。

- [ ] **Step 9: 記録**

BACKLOG の段階5 の 5a の小見出しに「本番反映（日付）」の表（4b と同じ形）を足し、節の見出しを「5a 本番反映済み・5b 未着手」にしてコミットする（`docs: 決裁 段階5a を本番に出した記録を残す`）。別の会話（manage）に済んだと伝える。push は利用者の指示があってから。

---

## 計画を書く段階の実測（2026-10-07）

### 土台と試作

- 土台は WT `approval-phase3` = `c335cd26`（設計書のコミット `d875ef2a` に、`13.x` = `ca50193f`〈manage の会話が DAD の画面の直しを本番に出したあとの BACKLOG〉を merge したもの。決裁のファイル・`composer`・`routes`・`database` の変更は無い）。計画のコミットの前に、BACKLOG だけの `a2f43bbc` も取り込んだ
- 試作: scratchpad の写し（`git archive`）を `git init` して base `77f5279`・枝 `proto` に Task ごとに 1 コミット（task01〜09）。前の会話（2026-10-07 夕）で作った 9 コミット（土台は `d875ef2a`）を、点検の指摘を直しながら新しい土台へ載せ替えた（手直しはその Task のコミットへ畳み込んだ。最後の形がこの計画のコード）。差分のファイルは `~/.claude/plans/approval-phase3-tasks/5a/patches/`（土台の写しに 9 本を `--include='tests/*'` → `--exclude='tests/*'` の順に当てると、試作の最後の木と同じになることを確かめた。点検の前の差分は `patches/wip/`）
- 道具は 4b の写し（`~/.claude/plans/approval-phase3-tasks/5a/`: `fullsuite.sh`・`run-full.sh`・`redfirst.py`・`mutate.py`〈一覧だけ 5a〉・`plan/build.py`〈SUBJECTS と VERDICTS を 5a〉・`rules.md`・`seed.php`・`mysql-compare.sh`・`xlsx2html.php`）。⚠ この Mac の php は 8.5 になっていたので、道具はすべて 8.3 を名指しにした
- 全件（各段）: base 3,934 本 → task01 3,935 本 → task02 3,942 本 → task03 3,988 本 → task04 3,994 本 → task05 4,005 本 → task06 4,009 本 → task07 4,021 本 → task08 4,026 本 → task09 4,032 本（どの段も OK・1 段およそ 4 分）
- redfirst（テストだけを先に入れる）: 9 段すべて赤（各 Task の Step 2 の Expected）

### 点検（別の担当〈opus〉・2026-10-07）で見つけて直したこと（計画の形に入っている）

Critical 0・Important 5・Minor 11・読みやすさ 6・テストの穴 8（報告は `~/.claude/plans/approval-phase3-tasks/5a/review.md`）。見られない人への漏れ・差戻し中の直しかけの漏れ・XSS・500 になる入力・PDF の落とし穴の戻りは見つからなかった。

1. **[Important] 粗利率の丸めが画面とサーバーで違った**（63.75% を画面は 63.7%・PHP 8.3 は 63.8%・PHP 8.4 からは 63.7%。粗利率は保存せず表示のたびに計算するので、本番の PHP を上げると決裁済みの数が変わる）→ PHP も JS も整数で計算（§0.3）。境目の 11 組と 50,200 組を PHP と JS で比べるテスト（Task 3・6）
2. **[Important] 変更点の明細表を行の位置で合わせていた**（真ん中の自由行を空にすると、変わっていない「紹介料」が「消えた」と出る）→ 行の鍵で最長共通部分列（§0.7。Task 7）
3. **[Important] 明細表の種類から 5W2H の種類へ選び直すと、確かめずに明細表と担当者などを捨てていた**（D16）→ 保存で消えるものを知らせて「元の種類に戻す」・隠れた明細表の欄は送らない（§0.6。Task 6）
4. **[Important] 決まり文句の種類で前半を空のまま、件名＝決まり文句だけで提出できた** → 画面は組み立てない・提出で断る（§0.5・§0.6。Task 5・6）
5. **[Important] PDF の並びが設計書 §5.7 と違った**（担当者・契約予定日が定型文より前）→ 設計書どおりに分け、1 行 2 組にした。前の会話で見つけていた「契約予定日」の見出しの折れも直った（§0.8。Task 8）
6. 差戻しの取り消しで、管理者が種類の「使う」を変えただけで「申請者が直し始めた」になっていた → 指紋の追加の欄を 4 つとも・今の中身は種類で絞らない（Task 7）
7. 金額の読み方が画面とサーバーで違った（「+1000」「0100」）・粗利率の 3 桁の区切りが違った → `FormInput::digits()`・区切りを付けない（Task 3・5・6）
8. 5W2H の種類に選び直すと、隠れた明細表の欄の値で断られた・⑨ でも隠れた見出しの欄の形で断られた → 送らない・明細表の種類は見出しを検査しない（Task 4・6）
9. 提出したあとで管理者が種類に欄を足すと、申請者本人の詳細と PDF にだけ欄が増えた（D14）→ 提出したあとは控えと同じ欄（Task 7）
10. 明細表の種類の補足のエラー文が「重点ポイント（5W2H）は…」だった → 「補足」（Task 5）
11. PDF の明細表が次のページに続くと見出しが出なかった → `<thead>`（Task 8）
12. 変更点の明細表がスマホで横スクロールだった → カード（Task 7）
13. 最後に提出した控えの引き方が 4 か所（5a で 1 か所増えていた）→ `submittedRevision()`（Task 2。§8 の持ち越しも片付いた）
14. 全角を含む空白の落とし方・数の入力のそろえ方・氏名の探し方が 2〜3 か所に → `FormInput::trim()`・`digits()`・`NameSearch`（Task 1・3・5）
15. テストの「住宅の契約用の種類」が 5 ファイルに同じ形で → `housingContractType()`（Task 5〜9）
16. 足りなかったテスト: 差戻し中に種類を変えたとき・コピーで使えない種類が空になる・編集の画面で外れた種類が選択肢に残る・5W2H の種類の担当者と契約予定日・工事原価の合計の 12 桁・テストの一時ファイルの消し忘れ（`&&` の短絡）
- 直さずに計画に書いたもの: 控えに本文の形を入れない（§0.14 の 2）・行の上限（§0.15 の 1）・5W2H の種類の契約予定日を Excel に入れる（§0.14 の 1。利用者が確認した）
- 前の会話の試作で見つけて直していたもの: MySQL で 5a のテスト 4 本が JSON のキーの並びで赤 → `assertSameIgnoringKeyOrder()`（`assertEquals` にすると 0 と null の違いを見逃すので、キーの並びをそろえてから `assertSame` で比べる部品にした）

### 使い捨ての MySQL 8.4.11（ポート 34419・確かめたあと止めた。常駐の 3306 はそのまま）

- DB `a`: migration だけで作った表 ／ DB `b`: migration で作ったあと 5a の migration を戻し（`down()` が通る）、本番と同じ SQL ファイルを流した表 → `SHOW CREATE TABLE` が `approval_types`・`approval_requests` は**完全に一致**、`approval_type_department` は外部キーの名前だけが違う（SQL は `fk_…`、migration は Laravel の既定の名前。段階2 からの書き方と同じ。§0.15 の 5）。全文は `~/.claude/plans/approval-phase3-tasks/5a/measure/mysql5a/`
- 決裁のテスト（`tests/Feature/Approval`・`tests/Unit/Approval`）を MySQL で流すと 1,110 本のうち 4 本だけが赤（`UsersSchemaTest` の 2 本〈SQLite の `sqlite_master` を読む〉・`MailFailureBannerTest` の 1 本〈ログの見張りの数〉・`TypeManagementTest::test_every_field_is_saved_and_logged_by_the_update`〈設定の記録の JSON のキーの並び。266 行目〉）。4b の前からある SQLite 前提のテストで、5a のテストはすべて緑

### Excel のメモリ（前の会話で測った・列は 19）

入力の上限の中身（件名 100 文字・実施時期 50 文字・関連する決裁No 10 個・審査のコメントと条件 2,000 文字・明細表 60 行）の 1,000 件で、Excel を作る分の増えは 74.2MB（4b の 15 列は 68.2MB）。Laravel の起動の分（約 25MB）を足しても本番の上限 128M に収まる → 件数の上限は 1,000 のまま。測る道具は `~/.claude/plans/approval-phase3-tasks/5a/measure/Measure/ExcelMemoryTest.php`（コミットしない）

### 手元のブラウザ（計画を書く段階・試作の写し・使い捨ての SQLite・Playwright）

写真は `/Users/masanori/site/approval/screenshots-5a/plan/`。試しのデータは Task 11 の Step 2 と同じ（`seed.php`）。

前の会話（1 回目の試作）で ⑨・②・③・PDF・Excel を一通り見て、今回（点検の指摘を直したあと）は変えた画面（② の選び直し・③ の変更点・PDF）を撮り直した。

| # | 見たこと | 結果 |
|---|---|---|
| 1 | ⑨ 一覧と小窓（決裁の管理者・1440px） | 一覧に「金額の明細表 住宅事業部・ミツワ不動産」。小窓で本文の形・明細表の行の足し・並べ替え・「計」・決まり文句・追加の欄・定型文・使える部門を保存し、開き直すと同じ値（`01`〜`03`） |
| 2 | ② 新しい申請（申請者・1440px） | 見本の数で 計 29,700,000円・合計金額 41,700,000円・粗利率 19.3%・紹介料の粗利率「—」・欄を離れるとカンマ付き・件名の見本「山田様請負新築工事契約の件」（`04`） |
| 3 | ② 375px | 明細表は 1 行 1 枚のカード・「＋ 前半に行を足す」・`main` の横のはみ出し 0（`05`） |
| 4 | ② 提出の断り | 担当者・契約予定日が空の申請を提出すると、理由が画面の上にまとめて出る（`06`） |
| 5 | ② 種類の選び直し（今回） | 金額を入れてから 5W2H の種類を選ぶと「選んだ種類は明細表・坪数・坪単価・担当者・契約予定日を使いません。このまま保存すると、入れた内容は消えます。」（`11`）。「元の種類に戻す」で金額・担当者と件名「山田様請負新築工事契約の件」が戻る（`12`）。⚠ はじめ件名が「山田」のまま戻らなかった → 直した（下の 18） |
| 6 | ③ 出し直した申請（今回・1440px と 375px） | 変更点に金額・担当者・補足の行ごとの差と、明細表の違い（工事請負金額の販売金額 28,500,000円 → 29,000,000円・＋ 外構工事）。375px は明細表も変更点もカード・はみ出し 0（`07`・`08`） |
| 7 | PDF（今回） | 「（記）」の下に明細表 → 坪数・坪単価 → 定型文 → 担当者・契約予定日 → 補足。印・朱の枠・判断の欄は 4a のまま（`09`）。60 行の申請は 3 ページで、2 ページ目の頭に明細表の見出し（`13`） |
| 8 | Excel（今回。`page.request` で取り出し、同じ写しで作ったものを読み戻した） | `200`・`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`・`attachment; filename=ledger_2026-10-07.xlsx; filename*=utf-8''…決裁台帳_2026-10-07.xlsx`・先頭 `504b0304`・19 列。明細表の申請は工事原価 33,650,000・粗利益金額 8,050,000・粗利率 19.3%・契約予定日 2026/10/20、5W2H の申請は 4 列とも空（画像は前の会話の `10`。Excel の作り方は今回変えていない） |
| 9 | 全画面 | コンソールのエラーと警告 0 件・`main` の横のはみ出し 0（1440・375px） |

18. **計画を書く段階のブラウザで見つけて直したこと**: 「元の種類に戻す」で、明細表と欄は戻るのに、件名が決まり文句の無い種類で 1 行になったまま（「山田」）戻らなかった → 戻すときに、前半と決まり文句の組み立てをやり直す（`restoreType` が `useSuffix()` を呼ぶ。Task 6 のテストが件名まで見る）
19. **変異の一覧を作っていて見つけたテストの穴**（別の担当〈opus〉が一覧の下書きで指摘）: 申請のある明細表の種類を、押せない本文の形を送らずに保存する場面（`TypeController` が送られなかった本文の形を 5W2H として扱っても緑だった。変異 S03）と、明細表の工事原価だけを直した出し直し（変更点の有無を明細表で見なくても緑だった。変異 C05）のテストが無かった → Task 4・Task 7 にテストを足した（S03・C05 は足したあとのテストで測った）

## Task 10 の実測記録（2026-10-08・実装の会話 d95f3f84）

- 測った木: `72dd304f`（Task 1〜9・Task 6／8／9 の手直し・まとめの点検のあとの手直しのあと）。全件 **OK (4049 tests, 38114 assertions)**（計画の 4032 から、手直しで足したテスト 17 本の分だけ増えた）
- 変異: `~/.claude/plans/approval-phase3-tasks/5a/mutate.py` の 68 個を、HEAD の写し 3 つで並べて流した（決裁のテストと走査テスト 4 本）。**68 個すべて検出・SKIP 0・カナリア赤（Failures 90）**。Q05 だけ、まとめの手直しで規則の先頭に `bail` が入ったので、書き換える前の文字列を合わせた（意味は同じ）
- 計画の段の結果（上の表）と落ちたテストの集合を比べた: **減った変異は 0**。増えたのは 10 個（CANARY・T02・T05・A02・A05・A06・J06・C02・P03・E02）で、どれも手直しで足したテストが加わった分（同じ機構で落ちている）
- 実装の点検で見つけた「変異が生き残る所」（計画の表に無かったもの）は、まとめの手直しの `974e0b07` でテストを足し、写しで変異を当てて赤・戻して緑を確かめた: `forForm` の 2 つ目の同じ名前の名前の行・名前の消えた工事原価だけの行・`rowLine` の販売金額だけ／工事原価だけの行・画面の販売金額 0 の粗利率「—」・開き直したときの件名の分け方・PDF の長い項目名の wrap・追加の欄の表の `autosize`・Excel の 0 と負の値・ほかの人の詳細の定型文・本人に出す使わなくなった欄
- 使い捨ての MySQL 8.4.11（ポート 34419・確かめたあと止めた）: DB a＝migration だけ／DB b＝migration → 5a の migration を戻し（`down()` が通る）→ 本番の SQL → `SHOW CREATE TABLE` が `approval_types`・`approval_requests` は完全に一致、`approval_type_department` は外部キーの名前だけが違う（§0.15 の 5）。計画の段の DB b の結果とも 3 表とも同じ。決裁のテストを MySQL で流すと 1,128 本のうち SQLite 前提の古い 4 本だけが赤（計画の段と同じ 4 本）で、5a のテストはすべて緑
- 結果のファイル: `~/.claude/plans/approval-phase3-tasks/5a/measure/final/`（`mutations.jsonl`・`mutations-compare.txt`・`mysql5a-final/`・`mysql-approval-tests.log`）

## Task 11 の実測記録（2026-10-08・実装の会話 d95f3f84）

`72dd304f` の写し・使い捨ての SQLite・`artisan serve`（8771）・Playwright。試しのデータは `seed.php`。写真は `/Users/masanori/site/approval/screenshots-5a/final/`（01〜18）。

| # | 画面 | 見たこと |
|---|---|---|
| 1 | ⑨ 一覧と小窓（決裁の管理者） | 一覧に「金額の明細表 住宅事業部・ミツワ不動産」（`01`）。申請 3 件の種類はラジオが押せず「この種類の申請が 3 件あるため…」（`02`）。行の足す・消す・上へ・下へ・「計」・決まり文句・追加の欄・定型文・使える部門（`03`）。保存して開き直すと同じ値。375px もはみ出し 0（`04`・`05`） |
| 2 | ② 新しい申請（1440px） | 見本の数で計 29,700,000／23,150,000／6,550,000／22.1%・合計金額 41,700,000／33,650,000／8,050,000／19.3%・紹介料の粗利率「—」・欄を離れるとカンマ付き・「台帳と PDF に載る件名: 山田様請負新築工事契約の件」（`06`）。前半を空にすると「（前半を入れてください）」で件名も空 |
| 3 | ② 375px | 明細表が 1 行 1 枚のカード・はみ出し 0（`07`） |
| 4 | ② 種類の選び直し | 5W2H の種類を選ぶと「選んだ種類は明細表・坪数・坪単価・担当者・契約予定日を使いません。…」（`08`）。「元の種類に戻す」で種類・金額・追加の欄・件名が戻り、行は二重にならない（`09`。Task 6 の手直しのあと） |
| 5 | ② 提出の断り | 件名の前半・担当者・契約予定日を空で提出 → 下書きに保存して「件名・担当者・契約予定日を入力してください。」をまとめて出す（`10`）。そろえると提出できる |
| 6 | ③ 詳細（部門長・1440px と 375px） | 明細表 → 坪数・坪単価・担当者・契約予定日 → 定型文 → 補足（`11`）。375px はカード（`13`） |
| 7 | ③ 変更点 | 金額・担当者・補足の行ごとの差・明細表は名前で合わせて「工事請負金額 28,500,000円 → 29,000,000円」「＋ 外構工事」（紹介料は消えたと出ない）（`12`）。375px はカード（`14`） |
| 8 | PDF | 紙の住宅の様式の並び・縮みなし（`17`・`18`）。60 行・ページの下の端の表は Task 8 の点検と再点検が描いて確かめた |
| 9 | Excel | 19 列・粗利率 0.193（書式 `0.0%`）・契約予定日は Excel の日付（`yyyy/mm/dd`）・オートフィルタ A1:S2（`16`。読み戻した HTML の画像） |
| 10 | 全画面 | `main` の横のはみ出し 0（1440・375px）・コンソールのエラーと警告 0・`view:cache` の 305 本 INVALID 0 |

気づき（BACKLOG の「利用者に伝えること」に書いた）: 坪単価の欄は欄を離れてもカンマ付きにならない（明細表の金額だけ整える。§0.6 のとおり）／Excel の見出しの右端のフィルターのボタンが「契約予定日」（幅 11）の最後の字を少し隠すかもしれない（4b の「審査の意見」と同じ幅の決め方。Excel を開かずには確かめられない）
