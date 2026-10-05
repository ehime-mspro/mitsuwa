# 決裁申請 段階4（4a: 電子印・PDF・印に使う文字）実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 段階4 の前半（4a）— 部門長・審査担当者・社長の判断に日付入りの印（データ印）を付けて申請の詳細に出し、紙の決裁申請書に近い A4 縦の PDF を見られる人が出力でき、決裁の管理者が利用者ごとの「印に使う文字」を直せるようにして、使い始めるまで（⑦ の印に使う文字を除き）何も見えないまま本番へ出す。

**Architecture:** 判断を書く 1 か所（`Workflow::finishStep()`）で、印の上段（部門の略称か「社長」）と下段（印に使う文字。空なら氏名の最初の空白より前）を段階の行に控え（`approval_steps.stamp_label`・`stamp_text`）、取り消しの 1 か所（`reopenStep()`）で消す。印は `Stamp`（控え＋判断の日の和暦）を `StampSvg` が SVG に描き、申請の詳細・⑦ の見本・PDF で同じものを使う。PDF は `PdfSheet`（紙面に載せる値。中身は `RequestContent::for` と同じ出し分け・今の回だけ）を Blade の紙面に流し、`ApprovalPdf`（mPDF ＋ 同梱の IPAex フォント）で作る。出力は `Approval\RequestPdfController` が見られる範囲と下書きを確かめ、`approval_download_logs` に `kind = pdf` で記録してブラウザで開く。

**Tech Stack:** Laravel 12（12.69.3。2026-10-03 に既知の弱点のある部品を更新した後）/ PHP 8.3（本番 8.3.32・手元 8.3.35）/ MySQL 8（本番）・SQLite（テスト）/ Blade + Alpine.js 3 + Tailwind v4 / PHPUnit 11 / **mpdf/mpdf ^8.3（新規。8.3.1・GPL-2.0-only・ext-gd と ext-mbstring が要る）** / **IPAex ゴシック・明朝 004.01（新規・同梱・IPA フォントライセンス v1.0）**

**Spec:** 設計書 @docs/superpowers/specs/2026-10-03-approval-phase4-design.md（この計画は §1.2・§4・§5.2〜§5.7・§6・§7 の **4a** の部分。§9 の「計画で決めること」の 4a の分は §0 で決めた）。要件定義書 @docs/決裁申請_要件定義書_v1.md（v1.13 の 3.2・9 章・13 章の ③⑦・14.2・14.5・15.4・15.5・15.7）。3b の計画 @docs/superpowers/plans/2026-10-02-approval-phase3b.md（§0.1 作り方・Task 9 の本番反映の作法は 4a でもそのまま効く）。

## Global Constraints

- 使い始める前（`approval_settings.launched_at` が空）は、PDF のルートと申請の詳細は開かない（`approval.launched` のグループに置く。設計書 §5.2）。⑦ の「印に使う文字」は使い始める前から直せる（⑦ は `approval.admin` だけ。D13）
- 印の文字は**判断した瞬間に控え**、あとで部門の略称・印に使う文字・氏名を変えても押した印は変わらない。取り消しで判断と一緒に消える（D3）。控えの無い判断（4a より前。本番には無い）は今の設定から描く
- 印の上段は、部門長＝段階の部門（申請部門の控え）の略称・審査＝段階の部門（審査部門）の略称・社長＝「社長」。中段は判断の日の**日本の暦の和暦**（R8.10.5。年度ではない）。下段は印に使う文字（4 文字まで）か、氏名の最初の空白（全角・半角）より前（D6〜D9）
- 「印に使う文字」は決裁の管理者が**全員について**直せる（所属部門と同じ。氏名・社員番号を直せない人も。D10）。変えたら設定の記録（`user.stamp_changed`）
- PDF は見られる人（`RequestVisibility::canView`）だけ・**下書きは 404**（申請者本人にも。D17）。中身は申請の詳細と同じ（`RequestContent::for`）・判断と印は**今の回だけ**（D4）。出力のたびに `approval_download_logs` に 1 行（`kind = pdf`・`attachment_id` は空）。作れなかったときは記録せず、`Log::error` を残して詳細へ戻す（D18）
- 文字はすべてエスケープする（紙面は `{{ }}`・コメントは `nl2br(e())`・印は `StampSvg` が `e()`）。印の色は CSS の変数を使わない（mPDF は CSS の変数を読めない。`StampSvg::COLOR`）
- PDF の部品とフォントは手元で入れてから `vendor` とリポジトリのフォントごと本番へ送る（本番では `composer install` をしない）。WT では Task 5 で `composer install`（lock どおり）だけを打つ。**main repo では Task 10 まで打たない**
- テストは worktree で `APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`。main repo では作業もテストもしない。worktree に `.env` を作らない（`.env*` は読まない・grep しない）
- コミットは Conventional Commits・日本語の件名（72 文字以内・句点なし）・1 コミット 1 関心事・本文の最後に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`。`--no-verify`・`--amend`・`git reset` でのやり直し・`git stash` は使わない。push は利用者が指示したときだけ

## Review Focus

設計書が触れていないが、使う人がいちばん出会いそうな場面（どの場面のテストも見ていなかったもの）。計画を書く段階で 5 つとも試作にテストを足し、コードを 1 か所壊すとそのテストが落ちることを確かめた（変異。Task 7 の表の ID）。

1. **本文がとても長い申請（2 万字）** → PDF は文字を縮めずに次のページへ続く（mPDF は表の 1 行をページの途中で切れず、1 つの枠に入れると縮める）（Task 5 の `test_the_pdf_is_made_with_the_bundled_fonts_and_flows_onto_more_pages`。変異 P06・P15）
2. **空白の無い氏名・全角の空白の氏名・前後に空白のある氏名** → 印の下段は氏名全体・最初の空白より前・空白を外したもの（Task 2 の `test_the_default_text_is_the_name_before_the_first_space`。変異 S01・S02）
3. **日本時間の 0:00 の前後に判断した申請** → 印の日付は日本の暦（UTC の 10/4 15:00 は R8.10.5）（Task 2 の `test_the_date_is_in_the_japanese_era_of_the_calendar_day_in_japan`・`test_each_judgement_records_its_stamp`。変異 S11）
4. **氏名やコメントに「<」「&」を含む** → 申請の詳細・⑦・PDF の印と紙面に打ったとおりの文字で出て、タグとして効かない（Task 3 の `test_the_text_is_escaped`・Task 5 の `test_the_sheet_escapes_what_people_typed`・既存の `RequestActionTest::test_comments_and_names_are_escaped`。変異 V01・V06・P16・P19）
5. **4 文字の印に使う文字・6 文字の部門の略称** → 印からはみ出さないよう小さく描く（Task 3 の `test_the_stamp_is_drawn_with_the_fitted_sizes`・`test_longer_text_is_drawn_smaller_to_stay_inside_the_stamp`。変異 V02・V03・V04）

---

## 0. 計画を書く段階で決めたこと（設計書 §9 の宿題のうち 4a の分）

### 0.1 作り方（試作を先に作って確かめた）

- この計画のコードは、scratchpad の試作（`approval-phase3` = `17ce20fb` の写し。`4efc25b6`〈要件定義書 v1.13〉に `13.x` の部品の更新〈`f6891312`〉を merge したもの）で Task ごとにコミットし、**各段で全件のテストが緑**であることと、**テストだけを先に入れると何が落ちるか**を測ってから書き写したもの（2b・3a・3b と同じ作り方）。各 Step の Expected の本数と落ちるテストの名前は実測（2026-10-04。はじめ 2026-10-03 に `4efc25b6` の上で作り、部品の更新を取り込んだあとで作り直して測り直した）
- 同じ中身の差分のファイルを `~/.claude/plans/approval-phase3-tasks/4a/patches/`（`0001`〜`0006`。この計画のコミット 1 つに 1 ファイル）に置いた。担当は計画のコードを打ち直さず、この差分を当ててよい（**テストを先に** `git apply --include='tests/*'`、実装を**あとで** `git apply --exclude='tests/*'`）。当てたら `git diff --stat` が各 Task の「差分の大きさ」と同じことを確かめる。**差分のファイルと計画のコードが食い違ったら、計画を正として止まり、報告する**
- ⚠ **`0005` にはフォントの本体（`ipaexg.ttf`・`ipaexm.ttf`）が入っていない**（14MB の本体を差分の文字にしない）。Task 5 の Step で、`~/.claude/plans/approval-phase3-tasks/4a/fonts/` の配布元の zip（SHA-256 を確かめる）から解いて置く
- 各 Task のコードの塊のうち、新しいファイルは全文、既存のファイルは差分（`diff` の形）で示す
- **使い捨ての MySQL 8.4.11 で確かめた**（2026-10-03・ポート 34419・確かめたあと止めた。常駐の 3306 はそのまま）: 4a の前まで migration で作り 4a を本番と同じ SQL ファイルで足した表と、migration だけで作った表の `SHOW CREATE TABLE` が `approval_members`・`approval_steps` とも完全に一致・4a のテスト（`tests/Feature/Approval/Phase4`・44 tests）が MySQL でも通る・本番と同じ分け方（`--` の行を消して `;` で分ける）で文が 2 つ
- 進め方は 3b と同じ（担当の決まりは `~/.claude/plans/approval-phase3-tasks/4a/rules.md`。3b の `rules.md` に、Task 5 の `composer install` とフォントの置き方だけを足したもの）: 実装の担当は 1 度に 1 人（Task ごと）・コードの点検は別の担当が WT の HEAD の写しで行う・変異の確かめは写しで・Task 10（本番反映）は担当に任せず親の会話が行う

### 0.2 表と本番の SQL（§5.3・§9 の 4）

- **`approval_members.stamp_text`**（VARCHAR(4)・NULL 可・`is_admin` の後）: 印に使う文字。NULL なら氏名から（D8・D9）。行の無い利用者は、⑦ で直したときに `updateOrCreate` で行を作る
- **`approval_steps.stamp_label`**（VARCHAR(6)・NULL 可・`comment` の後。部門の略称が 6 文字まで）・**`stamp_text`**（VARCHAR(100)・NULL 可。空白の無い氏名は氏名全体が入るので氏名と同じ長さ）: 判断したときの印の控え（D3）
- 本番の SQL は `database/sql/2026-10-03-approval-phase4a.sql`（`ALTER TABLE` 2 文）、テストの鏡は `database/migrations/2026_10_03_000001_add_approval_phase4a_columns.php`。新しい `Phase4aTablesTest` で突き合わせる（変える表・足す列・NULL・文字数を両方向に。SQLite の `Schema::getColumns()` は varchar の長さを返さないので、文字数は migration の書き方から読む）。`Phase2TablesTest` の「あとの段階が足した列」に `approval_steps` の 2 列を足す
- 本番の 4 表は 0 行（使い始める前）＝控えの無い判断を埋め直す SQL は要らない

### 0.3 印の文字・日付・描き方（§5.4・§9 の 3・D6〜D9・D12）

- `StampText`（規則の 1 か所）: `defaultFor(string $name)`（前後の空白〈全角を含む〉を外し、最初の空白の前。空白が無ければ全体）・`for(User $user)`（`approvalMember->stamp_text` が空でなければそれ）・`labelFor(ApprovalStep $step)`（社長の段階は「社長」・ほかは段階の `department_id` の部門の `short_name`）
- `Stamp`（1 つの印）: `forStep(ApprovalStep)`（判断した段階＝`Done` で `acted_at` があるものだけ。控えが無ければ今の設定から）・`preview(User)`（⑦ の見本。上段は最初の所属部門の略称・日付は今日）・`eraDate(DateTimeInterface)`（日本時間の暦の日付の和暦。2019-05-01 から R、前は H）
- `StampSvg::render(Stamp, font, size)`: 120 × 120 の箱に丸・横線 2 本・文字 3 つ。上段は幅 66・上限 20、下段は幅 62・上限 24 で文字数に合わせて縮める（`fit()`・下限 8）。中段は 16。書体は画面が端末の明朝（`SCREEN_FONT`）、PDF が `ipaexm`（`PDF_FONT`）
- **mPDF は SVG を描けることを試しで確かめた**（2026-10-03）: `<svg>` をそのまま HTML に入れ、`font-family="ipaexm"` の文字も IPAex 明朝で出る。PDF だけ別の描き方にする必要は無かった

### 0.4 PDF の部品・フォント・紙面（§5.6・§9 の 1・2・5・D5・D14〜D16）

- **部品は `mpdf/mpdf ^8.3`**（8.3.1。`composer require` で `composer.lock` に増えるのは mpdf と `mpdf/psr-http-message-shim`・`mpdf/psr-log-aware-trait`・`setasign/fpdi`・`paragonie/random_compat` の 5 つだけで、既にある部品の版は変わらない。2026-10-04 に新しい土台で実測）。⚠ 開発用だった `myclabs/deep-copy`（1.13.4・版は同じ）は mPDF が要るので本番用の側へ移る＝**本番用（`--no-dev`）の vendor には 6 つ入る**（main repo の vendor の写しで `composer install --no-dev --dry-run` を流して確かめた。Task 10 の Step 5）。**使用条件は GPL-2.0-only**（利用者の決定 2026-10-03: 社外へ配らない社内のシステムなので mPDF のまま使う）
- ⚠ **`vendor/mpdf/mpdf/ttfonts` が 87MB**（mPDF が同梱するフォント。使わない）。`deploy.sh` は `vendor` ごと送るので、初回の反映で約 93MB 増える（本番の空きは 1.9TB）。`deploy.sh` は変えない（除くと、mPDF が既定の書体を読みに行ったときに壊れる恐れがある）
- **フォントは IPAex ゴシック・明朝 004.01**（配布元 moji.or.jp の `ipaexg00401.zip`・`ipaexm00401.zip`）。`resources/fonts/ipaex/` に ttf 2 本と、ライセンスの文書（`IPA_Font_License_Agreement_v1.0.txt`・2 つの zip で同じもの）と Readme 2 本を置く。⚠ ライセンスの文書は BOM 付き・CRLF なので、`.gitattributes` に `/resources/fonts/** -text` を足して配布元のまま保存する（`* text=auto eol=lf` が改行を書き換えないように）
- mPDF の設定（`ApprovalPdf::render()`）: A4・余白 12mm（下 16mm・ページの下は 6mm）・`fontDir` に既定と `config('approval.pdf.font_dir')`・`fontdata` に `ipaexg`・`ipaexm`（R だけ。IPAex に太字は無い）・`default_font` は `ipaexg`・`tempDir` は `config('approval.pdf.temp_dir')`（`storage/framework/cache/mpdf`。`storage/framework/cache/.gitignore` が `*` なので git に入らない・本番の storage は書ける）
- **本文は罫線の 1 行を表の 1 行にする**（`PdfSheet::bodyRows()`。改行で分け、300 文字を超える行は分け、紙の 11 行に満たなければ空の行で埋める。末尾の空行は落とす）。⚠ 試しで、長い本文を 1 つの枠に入れたら mPDF が 444 行を 1 ページに縮めた（mPDF は表の 1 行をページの途中で切れない）
- **「可・条可・差戻・否」「可・保留・否」の欄は小さな表で組む**（文中の `<span>` の枠は、mPDF が一部を描き損なった。2026-10-03 の試し）
- 紙面の並び（`approvals/requests/pdf.blade.php`）: 題・状態と回数 → 決裁No・決裁日・発信日（最後の提出）・受付日（今の回の審査に届いた日） → 決裁の欄（○・社長の印・決裁者コメント〈条可は「（条件）」〉・条件確認の日時）／申請部門・申請者名／承認（部門長の印か「申請者が部門長のため省略」・部門長のコメント） → 件名 → 金額・実施時期・関連する決裁No・重点ポイントの見出し → 本文の罫線 → 添付の件数と名前 → 審査部門（部門名・コメント・○・印）。各ページの下（`_pdf_footer.blade.php`）に「決裁申請システムから出力 日時（出力者: 氏名）」と `{PAGENO} / {nbpg}`
- **時間とメモリの実測**（2026-10-03・手元の Apple Silicon）: 試し（Laravel を除く）で重い申請（本文 2 万字・添付 20 件・コメント 2 千字）は 12 ページ・0.52 秒・ピーク 30MB／Laravel 込みのテストで 20 ページ・0.43 秒・ピーク 68.5MB（PHPUnit の分を含む）。**本番の `memory_limit` は 128M**（2026-10-03 に利用者の了承のあと読み取った。CLI の php.ini・`~/www/php.ini` はアップロードの上限だけを変えている）。要件 14.5 の 5 秒・128M に収まる

### 0.5 出力の記録とファイル名（§5.7・§9 の 6・9・D18）

- `approval_download_logs` に `request_id`・`attachment_id = null`・`user_id`・`kind = 'pdf'`・`ip_address`・`user_agent`（255 文字まで）。表の変更は無い（`request_id` を空にできるようにするのは 4b の Excel）
- 応答: `Content-Type: application/pdf`・`X-Content-Type-Options: nosniff`・`Content-Disposition: inline; filename=approval-R8-J-001.pdf; filename*=utf-8''決裁申請書_R8-J-001.pdf`（番号の前は `approval-123.pdf`・`決裁申請書_申請123.pdf`）。Symfony の `HeaderUtils::makeDisposition()` で組み、ASCII の代わりの名前は Laravel に任せない（添付と同じ考え。日本語だけの名前で例外になる）
- 申請の詳細の見出しの行に「PDF を出力」（下書きでは出さない・別のタブで開く）

### 0.6 ⑦ の「印に使う文字」（§5.5・D9〜D11）

- `Approval\UserController::update()` に `stamp_text`（`nullable|string|max:4`・和名「印に使う文字」）。保存は所属部門と同じトランザクションの中で、**氏名を直せない相手でも**行う（`saveStampText()`）。**欄が送られてきたときだけ**保存する（`array_key_exists('stamp_text', $validated)`。欄の無い更新で印の文字を消さない）。変わったときだけ `SettingLogger::record('user.stamp_changed', 'user', id, ['stamp_text' => 前], ['stamp_text' => 後])`
- 前後の空白（**全角を含む**）はアプリ全体の前処理 `TrimStrings`（`Str::trim`）が外し、空は `ConvertEmptyStringsToNull` が null にする（2026-10-03 に「　山田　」で確かめた。はじめ「全角は外さない」と思い込んで自前で外したが、要らなかった＝変異 U02 が生き残って分かった）
- 一覧（`approvals/admin/users/index.blade.php`）の氏名の左に印の見本（48px。申請の詳細と同じ。34px では下段の文字が読めなかった＝2026-10-03 の画面の確かめ）。編集の小窓に「印に使う文字」の欄（4 文字まで・説明つき）。氏名を直せない人への案内を「決裁の所属部門と印に使う文字だけ変えられます。」に

### 0.7 走査テスト（全件分類）に登録するもの

3b §0.7 と同じく、**各 Task が足したものは、その Task の中で登録する**（途中の各段でも全件が緑）。

| 走査テスト | 登録するもの | Task |
|---|---|---|
| `tests/Feature/Approval/Phase2/Phase2TablesTest.php` | `LATER_COLUMNS` に `approval_steps` の `stamp_label`・`stamp_text`（4a） | 1 |
| `tests/Feature/ClockReadScanTest.php` | `PdfSheet.php` 1 件（PDF を出力した瞬間。`JapanTime::format(now())`） | 5 |
| `tests/Feature/MobileLayoutTest.php` | 新しい `TABLE_SCROLL_EXEMPT` に PDF の紙面 2 本（`approvals/requests/pdf.blade.php`・`_pdf_footer.blade.php`。理由: mPDF に渡す紙面で画面に出さない）。「すべての `<table>` に横スクロールの祖先」の走査はこの 2 本を飛ばす（ほかの表は今までどおり）。登録しないと紙面の表 9 か所で赤（2026-10-04 に確かめた） | 5 |
| `tests/Feature/Approval/ApprovalAdminGateTest.php` | `OPEN_TO_EVERY_USER` に `approvals.requests.pdf`（理由つき）・下限を 50 → **51**（ルート） | 6 |

- `LaunchGateTest`: PDF のルートは `approval.launched` のグループの中に置く（登録は要らない）
- `ApprovalOnlyLockoutTest`: PDF は `approvals.` の名前で `App\Http\Controllers\Approval\` に置く（登録は要らない）
- `ValidationErrorFeedbackTest`・`JapaneseValidationMessagesTest`: ⑦ の小窓は既存の `$errors` の帯が出す。和名は `validate()` の第 3 引数で渡す（`lang/ja/validation.php` は変えない）
- `StoredTimestampDisplayScanTest`: 新しい日時の表示はすべて `JapanTime::format()`（登録は要らない）
- `LoginGuideTest` の 1 ファイル 1 宣言: 新しいファイルはどれも 1 つ

### 0.8 既存のコードを変えるもの（振る舞いを変えない片付け）

無い（`Workflow::finishStep()`・`reopenStep()` は印の控えを足すだけ。申請の詳細の「回る順番」は右に印の列を足すだけ）。

### 0.9 既存のテストを変えるもの

| テスト | 変えること | Task |
|---|---|---|
| `Phase2TablesTest`・`ClockReadScanTest`・`MobileLayoutTest`・`ApprovalAdminGateTest` | §0.7 のとおり足すだけ（意味は変えない。`MobileLayoutTest` は対象外の一覧を足し、ほかのファイルの判定は変えない） | 1・5・6 |
| `RequestActionTest::test_comments_and_names_are_escaped` | エスケープした氏名 `&lt;b&gt;部門&lt;/b&gt;長` の数を 3 → **5**（空白の無い氏名は氏名全体が印の下段になり、印の文字と読み上げの名前の 2 か所に増える。エスケープされていることの確かめは変わらない） | 3 |

### 0.10 設計書から変えた細部（計画を書いていて分かったこと）

| # | 設計書 | この計画 | 理由 |
|---|---|---|---|
| 1 | §5.6「紙面（見本は設計の対話で利用者に見せた形）」 | 本文は罫線の 1 行＝表の 1 行・判断の欄は小さな表 | mPDF の実測（§0.4）。見た目は見本と同じ |
| 2 | §5.4「申請の詳細: 判断した段階の横に印」 | 「回る順番」の各行の右端に 48px の印 | 行の左の文字（判断・氏名・日時・コメント）の並びを変えないため |
| 3 | §5.5「一覧に各人の印の見本」 | 氏名の欄の左に 48px | 列を足すと表が横に長くなる（⑦ はすでに 9 列）。34px では下段の文字が読めなかった |
| 4 | §9 の 6「ファイル名は添付の `AttachmentDelivery` に倣う」 | PDF の名前は自前で作るので、ASCII の代わりは「approval-番号.pdf」に決め打ち | 名前の元が利用者の打った文字ではない（番号と固定の文字だけ） |

### 0.11 受け入れた隙間

| # | 隙間 | 起きたとき | 塞ぐなら |
|---|---|---|---|
| 1 | 画面の印の書体は端末の明朝体で、PDF の IPAex 明朝と字形が少し違う | 同じ印でも画面と PDF で字の形が少し違って見える | 画面にもフォントを配る（Web フォント） |
| 2 | ⑦ の見本の上段は「最初の所属部門の略称」で、実際に押す印の上段（判断する段階の部門）と違うことがある | 兼務の人や、所属と違う部門の部門長の見本の上段が実際と違う | 見本を部門ごとに出す |
| 3 | `vendor/mpdf/mpdf/ttfonts`（87MB）を本番へ送る | 初回の反映が数分長い・本番の容量が 93MB 増える | `deploy.sh` で除く（mPDF の既定の書体の読み込みを確かめてから） |
| 4 | 本番の Web の PHP のメモリの上限は、CLI の php.ini と `~/www/php.ini` から 128M と見たが、Web の実際の値は読んでいない（`phpinfo` を本番に置かない） | 128M より小さければ大きな申請の PDF で作れないことがある（そのときは「PDF を作れませんでした。」と laravel.log） | 使い始める前の受け入れ確認（段階6）で、重い申請の PDF を本番で 1 回出す |
| 5 | 印に使う文字の 4 文字は、ブラウザの `maxlength` が UTF-16 の数で数える（𠮷 などは 2 と数える）。サーバーは文字で数える | 4 文字の中に 𠮷 などがあると、ブラウザで 4 文字目が打てない | 小窓の `maxlength` を外して、サーバーの検証だけにする |

### 0.12 テストの土台

- 3b と同じ（`Tests\Concerns\BuildsApprovalFixtures`・`approvalWorld()` の部門長「部門 長」・審査担当者「審査 担当」・社長「社長 太郎」・申請者「申請 花子」→ 印の下段は「部門」「審査」「社長」・「申請」）
- 時計は `Carbon::setTestNow(Carbon::parse('… UTC'))`（日本時間の Carbon を渡すと保存した日時が 9 時間ずれる）
- PDF の中身は、紙面の HTML（`view('approvals.requests.pdf')`）で確かめる。PDF そのものは、先頭が `%PDF-`・フォントの名前（`IPAexGothic`・`IPAexMincho`）が入っている・ページの数（`/Type /Page`）で確かめる（文字は CID のグリフで入るので、PDF のバイト列から文字は探せない）

---

## 1. 触るファイル

### 新規

| ファイル | 役目 | Task |
|---|---|---|
| `database/sql/2026-10-03-approval-phase4a.sql` | 本番の DDL（ALTER 2 文） | 1 |
| `database/migrations/2026_10_03_000001_add_approval_phase4a_columns.php` | テストの鏡 | 1 |
| `app/Support/Approval/StampText.php` | 印の上段・下段の規則 | 2 |
| `app/Support/Approval/Stamp.php` | 1 つの印（控え・和暦の日付・見本） | 2 |
| `app/Support/Approval/StampSvg.php` | 印を SVG に描く | 3 |
| `app/Support/Approval/ApprovalPdf.php` | mPDF で PDF を作る | 5 |
| `app/Support/Approval/PdfSheet.php` | 紙面に載せる値 | 5 |
| `resources/views/approvals/requests/pdf.blade.php`・`_pdf_footer.blade.php` | 紙面・各ページの下 | 5 |
| `resources/fonts/ipaex/`（ttf 2・ライセンス・Readme 2） | 同梱のフォント | 5 |
| `app/Http/Controllers/Approval/RequestPdfController.php` | PDF の出力・記録 | 6 |
| `tests/Feature/Approval/Phase4/`（`Phase4aTablesTest`・`StampTest`・`StampDisplayTest`・`StampTextSettingTest`・`PdfSheetTest`・`RequestPdfTest`） | テスト | 1〜6 |

### 変更

| ファイル | 変えること | Task |
|---|---|---|
| `app/Models/ApprovalMember.php`・`app/Models/ApprovalStep.php` | `$fillable` に印の列 | 1 |
| `app/Support/Approval/Workflow.php` | `finishStep()` で控える・`reopenStep()` で消す | 2 |
| `resources/views/approvals/requests/_steps.blade.php` | 回る順番に印 | 3 |
| `app/Http/Controllers/Approval/UserController.php`・`resources/views/approvals/admin/users/index.blade.php` | 印に使う文字・見本 | 4 |
| `composer.json`・`composer.lock`・`config/approval.php`・`.gitattributes` | mPDF・PDF の設定・フォントを書き換えない | 5 |
| `routes/approval.php`・`resources/views/approvals/requests/show.blade.php` | PDF のルート・ボタン | 6 |
| 走査テストと既存のテスト | §0.7・§0.9 | 1・3・5・6 |

## 2. 作業の順番

```
Task 0 準備 → Task 1 表 → Task 2 印の文字と控え → Task 3 印の SVG と詳細 → Task 4 ⑦
→ Task 5 PDF の部品・フォント・紙面 → Task 6 PDF の出力 → Task 7 全件・変異 → Task 8 ブラウザと写真
→ Task 9 ドキュメント → Task 10 本番反映（親の会話）
```

各 Task のコミットのあとで全件が緑（途中の段でも）。Task 7・8 は記録だけをコミットする。

---

## Task 0: 作業場所の準備

**作業場所**: worktree `/Users/masanori/site/manage/.claude/worktrees/approval-phase3`（ブランチ `approval-phase3`。3a・3b と同じ worktree を使う＝利用者の決定・dev の vendor の置き場。この計画のコミットの親は `17ce20fb`＝`13.x`〈`27635bb0`〉に要件定義書 v1.12・段階4 の設計書・要件定義書 v1.13 の 3 コミットを載せた `4efc25b6` に、`13.x`〈`f6891312`。既知の弱点のある部品の更新・Laravel 12.69.3〉を merge したもの）。**main repo では作業もテストもしない**（main repo の vendor は `--no-dev` で phpunit が無い。dev 依存を入れると `./deploy.sh` が本番へ送る）。

- [ ] **Step 1: 並行の作業を確かめる**（ほかの会話が同じ課題を進めていないか）

```bash
cd /Users/masanori/site/manage && git status --short --branch && git log --oneline -5 && git worktree list && git for-each-ref --sort=-committerdate --format='%(refname:short) %(committerdate:short) %(subject)' refs/heads | head && git log --oneline approval-phase3..13.x
```

Expected: worktree `approval-phase3` の先頭がこの計画のコミット。最後のコマンド（`13.x` にあって `approval-phase3` に無いコミット）が空。ほかの worktree があれば別の会話のもの（触らない）。段階4 の名前の付いた枝や worktree があれば、中身を読み、消さずに利用者へ報告して止まる。`13.x` が進んでいたら止まり、利用者に取り込むかを聞く（取り込むなら WT で `git merge 13.x`。rebase しない）。

- [ ] **Step 2: vendor を確かめる**（dev 依存あり・実体）

⚠ **symlink にしない**（autoload が symlink の先を読み、別の場所のコードでテストが流れる。Bug #50）。

```bash
WT=/Users/masanori/site/manage/.claude/worktrees/approval-phase3; test -x "$WT/vendor/bin/phpunit" && ! test -L "$WT/vendor" && echo "vendor OK"
```

Expected: `vendor OK`

- [ ] **Step 3: 差分のファイルとフォントの zip を確かめる**

```bash
ls ~/.claude/plans/approval-phase3-tasks/4a/patches/ && (cd ~/.claude/plans/approval-phase3-tasks/4a/fonts && shasum -a 256 -c SHA256SUMS) && cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --check ~/.claude/plans/approval-phase3-tasks/4a/patches/0001-*.patch && echo "0001 を当てられる"
```

Expected: `0001-…` 〜 `0006-…` の 6 本・`ipaexg00401.zip: OK`・`ipaexm00401.zip: OK`・`0001 を当てられる`。差分のファイルが無ければ計画のコードを打ち込む（中身は同じ）。zip が無いか SHA-256 が合わなければ止まって報告する（ネットから取り直さない）。

- [ ] **Step 4: 全件テストが通る状態から始める**

**テストの流し方**（以下すべての Task で同じ。`APP_KEY` は 32 バイトの本物の鍵を渡す。worktree に `.env` を作らない）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `OK (3334 tests, 23750 assertions)`（2026-10-04 の実測。約 3 分）。赤があれば 4a の作業の前に利用者へ報告して止まる。

⚠ 以下の Task の「テストを流す」は、すべてこの形でファイルを並べたもの。`cd` はコマンドごとに書く（ターンをまたぐと cwd が main repo へ戻る）。git は `git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 …` で呼ぶ。

⚠ コミットのたびに `git status --porcelain` が空であることを確かめる。差分のファイルを当てたときは、`git diff --stat` が各 Task の「差分の大きさ」と同じことも確かめる。

---

## Task 1: 表（印に使う文字・判断の印の控え）

利用者ごとの「印に使う文字」と、判断したときの印の上段・下段の控えの列を、本番の SQL とテストの migration の対で足す（設計書 §5.3・§0.2）。

**Files:**
- Create: `database/sql/2026-10-03-approval-phase4a.sql`・`database/migrations/2026_10_03_000001_add_approval_phase4a_columns.php`
- Modify: `app/Models/ApprovalMember.php`・`app/Models/ApprovalStep.php`（`$fillable`）
- Test: Create `tests/Feature/Approval/Phase4/Phase4aTablesTest.php`・Modify `tests/Feature/Approval/Phase2/Phase2TablesTest.php`

**Interfaces:**
- Produces: `approval_members.stamp_text`（`ApprovalMember` の `$fillable`）・`approval_steps.stamp_label`・`stamp_text`（`ApprovalStep` の `$fillable`）。Task 2・4 が書く

**差分の大きさ:** 6 ファイル・+164 / −3 行（差分のファイル `0001-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase4/Phase4aTablesTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase4;

use App\Models\ApprovalMember;
use App\Models\ApprovalStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 段階4（4a）の列（段階4 設計書 §5.3）。
 *
 * ⚠ 本番は SQL を手で流し、テストは migration で作る。**両方が食い違わないこと**をここで固定する（Phase3TablesTest と同じ考え）。
 *   比べるのは、変える表・足す列の名前・NULL を許すか・文字数の上限。**両方向に比べる**。
 */
class Phase4aTablesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const SQL = 'database/sql/2026-10-03-approval-phase4a.sql';

    private const MIGRATION = 'database/migrations/2026_10_03_000001_add_approval_phase4a_columns.php';

    /** @return array<string, array<string, array{nullable: bool, length: int}>> 表 => [列 => NULL を許すか・文字数] */
    private function sqlColumns(): array
    {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(base_path(self::SQL)));
        preg_match_all('/ALTER TABLE `(\w+)`(.*?);/s', $sql, $alters, PREG_SET_ORDER);

        $tables = [];
        foreach ($alters as [, $table, $body]) {
            preg_match_all('/ADD COLUMN `(\w+)` VARCHAR\((\d+)\)([^,]*)/', $body, $columns, PREG_SET_ORDER);
            foreach ($columns as [, $column, $length, $definition]) {
                $tables[$table][$column] = ['nullable' => ! str_contains($definition, 'NOT NULL'), 'length' => (int) $length];
            }
        }

        return $tables;
    }

    public function test_the_sql_and_the_migration_touch_the_same_tables(): void
    {
        preg_match_all("/Schema::table\\('(\\w+)'/", file_get_contents(base_path(self::MIGRATION)), $matches);

        $this->assertEqualsCanonicalizing(['approval_members', 'approval_steps'], array_keys($this->sqlColumns()));
        $this->assertEqualsCanonicalizing(array_keys($this->sqlColumns()), array_values(array_unique($matches[1])));
    }

    public function test_the_sql_and_the_migration_add_the_same_columns(): void
    {
        $sql = $this->sqlColumns();

        // 空振りで緑にならないように（利用者に 1 列・段階に 2 列）
        $this->assertSame(['stamp_text'], array_keys($sql['approval_members'] ?? []));
        $this->assertSame(['stamp_label', 'stamp_text'], array_keys($sql['approval_steps'] ?? []));

        // 文字数は migration の書き方から読む（SQLite の Schema::getColumns() は varchar の長さを返さない）
        preg_match_all("/->string\\('(\\w+)', (\\d+)\\)/", file_get_contents(base_path(self::MIGRATION)), $declared, PREG_SET_ORDER);
        $lengths = [];
        foreach ($declared as [, $column, $length]) {
            $lengths[] = [$column, (int) $length];
        }

        $expected = [];
        foreach ($sql as $table => $columns) {
            $migrated = [];
            foreach (Schema::getColumns($table) as $column) {
                if (isset($columns[$column['name']])) {
                    $migrated[$column['name']] = (bool) $column['nullable'];
                }
            }

            $this->assertSame(array_map(fn (array $c) => $c['nullable'], $columns), $migrated, "{$table} の列（名前と NULL を許すか）が SQL と migration で違う");
            foreach ($columns as $column => $c) {
                $expected[] = [$column, $c['length']];
            }
        }

        $this->assertSame($expected, $lengths, '文字数の上限が SQL と migration で違う');
    }

    public function test_the_stamp_columns_are_fillable(): void
    {
        $world = $this->approvalWorld();
        $member = ApprovalMember::create(['user_id' => $world['head']->id, 'stamp_text' => '長谷川']);
        $this->assertSame('長谷川', $member->fresh()->stamp_text);

        $request = $this->submittedFor($world);
        $step = $request->steps()->where('kind', 'head')->first();
        $step->update(['stamp_label' => '住宅', 'stamp_text' => '部門']);

        $this->assertSame(['住宅', '部門'], [$step->fresh()->stamp_label, $step->fresh()->stamp_text]);
        $this->assertInstanceOf(ApprovalStep::class, $step);
    }
}
```

`tests/Feature/Approval/Phase2/Phase2TablesTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/Phase2TablesTest.php
+++ b/tests/Feature/Approval/Phase2/Phase2TablesTest.php
@@ -34,6 +34,7 @@ class Phase2TablesTest extends TestCase
     /** あとの段階が 2a の表に足した列（その段階の表のテストが見る。ここでは比べない）。表 => 列 */
     private const LATER_COLUMNS = [
         'approval_settings' => ['mail_last_sent_at', 'mail_last_failed_at', 'mail_last_failed_to'],   // 3a（Phase3TablesTest）
+        'approval_steps'    => ['stamp_label', 'stamp_text'],                                         // 4a（Phase4aTablesTest）
     ];
 
     /** @return list<array{string, string, bool}> [表, CREATE の括弧の中か ALTER の中身, ALTER か] */
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/4a/patches/0001-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase4/Phase4aTablesTest.php tests/Feature/Approval/Phase2/Phase2TablesTest.php
```

Expected: `ERRORS!` `Tests: 8, Assertions: 45, Errors: 2, Failures: 1.`

- `Phase4aTablesTest::test_the_sql_and_the_migration_touch_the_same_tables` — `ErrorException: file_get_contents(/Users/masanori/site/manage/.claude/worktrees/approval-phase3/database/migratio`
- `Phase4aTablesTest::test_the_sql_and_the_migration_add_the_same_columns` — `ErrorException: file_get_contents(/Users/masanori/site/manage/.claude/worktrees/approval-phase3/database/sql/2026`
- `Phase4aTablesTest::test_the_stamp_columns_are_fillable` — `Failed asserting that null is identical to '長谷川'.`

- [ ] **Step 3: 列を足す**（本番の SQL とテストの鏡・モデル）

`database/sql/2026-10-03-approval-phase4a.sql`（新規）

```sql
-- 決裁申請 段階4（4a）— 2026-10-03
--
-- 設計書: docs/superpowers/specs/2026-10-03-approval-phase4-design.md §5.3
--
-- ⚠ database/migrations/2026_10_03_000001_add_approval_phase4a_columns.php と
--   対で維持すること（あちらは SQLite のテストのための鏡。Phase4aTablesTest が見る）。
--
-- ⚠ **この DDL が先・./deploy.sh が後。** 新しいコードは approval_members.stamp_text と approval_steps.stamp_* を読み書きするので、
--   コードを先に送ると、判断の操作と利用者の管理（⑦）が Unknown column で 500 になる。
--
-- 適用: 段階1〜3 と同じく php artisan tinker --execute で DB::statement() に **1 文ずつ**流す。
--   先頭で「approval_members に stamp_text があるか、approval_steps に stamp_label があれば 1 文も流さずに止まる」確認をする（計画 Task 8）。

-- 1. 利用者ごとの「印に使う文字」（D9。空なら氏名の最初の空白より前。D8）
ALTER TABLE `approval_members`
  ADD COLUMN `stamp_text` VARCHAR(4) NULL COMMENT '印に使う文字（空なら氏名の最初の空白より前）' AFTER `is_admin`;

-- 2. 判断したときの印の控え（D3。押したあとで設定を変えても変わらない。取り消しで空に戻す）
ALTER TABLE `approval_steps`
  ADD COLUMN `stamp_label` VARCHAR(6) NULL COMMENT '押したときの印の上段（部門の略称か「社長」）' AFTER `comment`,
  ADD COLUMN `stamp_text` VARCHAR(100) NULL COMMENT '押したときの印の下段（印に使う文字）' AFTER `stamp_label`;
```

`database/migrations/2026_10_03_000001_add_approval_phase4a_columns.php`（新規）

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 決裁申請 段階4（4a）の列（段階4 設計書 §5.3）。
 *
 * ⚠ **これは SQLite のテストのための鏡**。本番は `database/sql/2026-10-03-approval-phase4a.sql` を
 *   手で流す（このプロジェクトは migration で本番を管理していない）。**両方を対で維持すること**（Phase4aTablesTest が見る）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_members', function (Blueprint $table) {
            $table->string('stamp_text', 4)->nullable()->after('is_admin')
                  ->comment('印に使う文字（空なら氏名の最初の空白より前）');
        });

        Schema::table('approval_steps', function (Blueprint $table) {
            $table->string('stamp_label', 6)->nullable()->after('comment')
                  ->comment('押したときの印の上段（部門の略称か「社長」）');
            $table->string('stamp_text', 100)->nullable()->after('stamp_label')
                  ->comment('押したときの印の下段（印に使う文字）');
        });
    }

    public function down(): void
    {
        Schema::table('approval_steps', function (Blueprint $table) {
            $table->dropColumn(['stamp_label', 'stamp_text']);
        });

        Schema::table('approval_members', function (Blueprint $table) {
            $table->dropColumn('stamp_text');
        });
    }
};
```

`app/Models/ApprovalMember.php`（変更）

```diff
--- a/app/Models/ApprovalMember.php
+++ b/app/Models/ApprovalMember.php
@@ -8,11 +8,11 @@
 /**
  * 利用者ごとの決裁の印（設計書 §5.16）。
  *
- * ⚠ 印に使う文字の列は段階4 で足す（D17）。
+ * 印に使う文字（`stamp_text`）は段階4 で足した（段階4 設計書 D9）。空なら氏名から決める（`StampText`）。
  */
 class ApprovalMember extends Model
 {
-    protected $fillable = ['user_id', 'can_view_all', 'is_admin'];
+    protected $fillable = ['user_id', 'can_view_all', 'is_admin', 'stamp_text'];
 
     protected function casts(): array
     {
```

`app/Models/ApprovalStep.php`（変更）

```diff
--- a/app/Models/ApprovalStep.php
+++ b/app/Models/ApprovalStep.php
@@ -13,12 +13,13 @@
  *
  * ⚠ 担当者の列は「付け替え」のときだけ入る（`assignee_user_id`）。空なら部門長は申請部門の
  *   **今の**部門長、社長は**今の**社長、審査はその部門の**今の**審査担当者（要件 4.3 のケース 5〜7）。
+ * ⚠ 印の控え（`stamp_label`・`stamp_text`）は判断したときに `Workflow::finishStep()` が書き、取り消しで空に戻す（段階4 設計書 D3）。
  */
 class ApprovalStep extends Model
 {
     protected $fillable = [
         'request_id', 'round', 'kind', 'department_id', 'assignee_user_id', 'status',
-        'arrived_at', 'acted_at', 'actor_user_id', 'result', 'comment',
+        'arrived_at', 'acted_at', 'actor_user_id', 'result', 'comment', 'stamp_label', 'stamp_text',
     ];
 
     protected function casts(): array
```

（差分のファイルを使うなら: `cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/4a/patches/0001-*.patch`）

- [ ] **Step 4: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (8 tests, …)`

- [ ] **Step 5: 全件を流す**

Expected: `OK (3337 tests, 23760 assertions)`

- [ ] **Step 6: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add database/sql/2026-10-03-approval-phase4a.sql database/migrations/2026_10_03_000001_add_approval_phase4a_columns.php app/Models/ApprovalMember.php app/Models/ApprovalStep.php tests/Feature/Approval/Phase4/Phase4aTablesTest.php tests/Feature/Approval/Phase2/Phase2TablesTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 印に使う文字と判断の印の控えの列を足す

approval_members.stamp_text（利用者ごとの印に使う文字。空なら氏名から）と
approval_steps.stamp_label・stamp_text（判断したときの印の上段と下段の控え）を、
本番の SQL とテストの migration の対で足す（段階4 設計書 §5.3・D3・D9）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 2: 印の文字を決めて、判断のときに控える

印の上段（部門の略称か「社長」）と下段（印に使う文字。空なら氏名の最初の空白より前）を 1 か所（`StampText`）で決め、判断を書く `Workflow::finishStep()` で段階に控え、取り消しの `reopenStep()` で消す。1 つの印（`Stamp`）は、控えた文字と判断の日の和暦（日本の暦）から作る（設計書 §5.4・§0.3・D3・D6〜D8）。

**Files:**
- Create: `app/Support/Approval/StampText.php`・`app/Support/Approval/Stamp.php`
- Modify: `app/Support/Approval/Workflow.php`（`finishStep()`・`reopenStep()`）
- Test: Create `tests/Feature/Approval/Phase4/StampTest.php`

**Interfaces:**
- Consumes: Task 1 の列
- Produces: `StampText::defaultFor(string $name): string`・`StampText::for(User $user): string`・`StampText::labelFor(ApprovalStep $step): string`・`StampText::PRESIDENT_LABEL`
- Produces: `Stamp`（`readonly string $label`・`$date`・`$text`）: `Stamp::forStep(ApprovalStep $step): ?Stamp`（判断していない段階は null）・`Stamp::preview(User $user): Stamp`（⑦ の見本）・`Stamp::eraDate(DateTimeInterface $at): string`。Task 3・4・5 が使う

**差分の大きさ:** 4 ファイル・+277 / −1 行（差分のファイル `0002-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase4/StampTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalMember;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Support\Approval\Stamp;
use App\Support\Approval\StampText;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 印の文字と控え（要件 9.1・段階4 設計書 §5.4・D3・D6〜D8） */
class StampTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-04 16:30:00', 'UTC'));   // 日本時間 10/5 1:30（UTC では 10/4）
        $this->workflow = app(Workflow::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function step(ApprovalRequest $request, ApprovalStepKind $kind): ApprovalStep
    {
        return ApprovalStep::where('request_id', $request->id)->where('round', $request->fresh()->round)->where('kind', $kind->value)->firstOrFail();
    }

    /** @return array{string, string, string} 上段・日付・下段 */
    private function stampOf(ApprovalRequest $request, ApprovalStepKind $kind): array
    {
        $stamp = Stamp::forStep($this->step($request, $kind));
        $this->assertNotNull($stamp, "{$kind->value} の印が無い");

        return [$stamp->label, $stamp->date, $stamp->text];
    }

    public function test_the_default_text_is_the_name_before_the_first_space(): void
    {
        $this->assertSame('山田', StampText::defaultFor('山田 太郎'));
        $this->assertSame('山田', StampText::defaultFor('山田　太郎'), '全角の空白でも区切る');
        $this->assertSame('鈴木', StampText::defaultFor('　 鈴木 一郎 '), '前後の空白は先に除く');
        $this->assertSame('長谷川', StampText::defaultFor('長谷川  三郎'), '空白が続いても最初の区切りまで');
        $this->assertSame('山田太郎', StampText::defaultFor('山田太郎'), '空白の無い氏名は氏名全体');
    }

    public function test_the_text_set_by_the_admin_wins_over_the_name(): void
    {
        $user = $this->baseUser(['name' => '山田太郎']);
        $this->assertSame('山田太郎', StampText::for($user));

        ApprovalMember::create(['user_id' => $user->id, 'stamp_text' => '山田']);
        $this->assertSame('山田', StampText::for($user->fresh()));

        ApprovalMember::where('user_id', $user->id)->update(['stamp_text' => '']);
        $this->assertSame('山田太郎', StampText::for($user->fresh()), '空の文字は氏名からの既定に戻る');
    }

    public function test_each_judgement_records_its_stamp(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->judgeReview($r->fresh(), $w['reviewer'], $r->fresh()->lock_version, ApprovalStepResult::Hold, '確認中');
        $this->workflow->judgePresident($r->fresh(), $w['president'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);

        // 部門長は申請部門・審査は審査部門の略称・社長は「社長」。日付は日本時間の暦（UTC の 10/4 は日本の 10/5）
        $this->assertSame(['住宅', 'R8.10.5', '部門'], $this->stampOf($r, ApprovalStepKind::Head));
        $this->assertSame(['総務', 'R8.10.5', '審査'], $this->stampOf($r, ApprovalStepKind::Review));
        $this->assertSame(['社長', 'R8.10.5', '社長'], $this->stampOf($r, ApprovalStepKind::President));
        $this->assertSame(['住宅', '部門'], [$this->step($r, ApprovalStepKind::Head)->stamp_label, $this->step($r, ApprovalStepKind::Head)->stamp_text], '判断したときに控える');
    }

    public function test_a_return_is_stamped_too(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Return, '金額を見直してください');

        $this->assertSame(['住宅', 'R8.10.5', '部門'], $this->stampOf($r, ApprovalStepKind::Head));
    }

    public function test_changing_the_settings_later_does_not_change_the_stamp(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);

        $w['dept']->update(['short_name' => '住宅事業']);
        ApprovalMember::create(['user_id' => $w['head']->id, 'stamp_text' => '別名']);
        $w['head']->update(['name' => '改姓 長']);

        $this->assertSame(['住宅', 'R8.10.5', '部門'], $this->stampOf($r, ApprovalStepKind::Head));
    }

    public function test_the_stamp_uses_the_text_set_at_the_time_of_the_judgement(): void
    {
        $w = $this->approvalWorld();
        ApprovalMember::create(['user_id' => $w['reviewer']->id, 'stamp_text' => '高橋']);
        $r = $this->submittedFor($w);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->judgeReview($r->fresh(), $w['reviewer'], $r->fresh()->lock_version, ApprovalStepResult::Ok, null);

        $this->assertSame(['総務', 'R8.10.5', '高橋'], $this->stampOf($r, ApprovalStepKind::Review));
    }

    public function test_undo_removes_the_stamp_with_the_judgement(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $r     = $this->submittedFor($w);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);

        $this->workflow->undo($r->fresh(), $admin, $r->fresh()->lock_version, '押し間違い');

        $head = $this->step($r, ApprovalStepKind::Head);
        $this->assertNull(Stamp::forStep($head));
        $this->assertSame([null, null], [$head->stamp_label, $head->stamp_text], '控えも消える');
    }

    public function test_steps_that_are_not_judged_have_no_stamp(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->assertNull(Stamp::forStep($this->step($r, ApprovalStepKind::Head)), '待ち');
        $this->assertNull(Stamp::forStep($this->step($r, ApprovalStepKind::President)), 'まだ届いていない');
    }

    public function test_a_judgement_without_a_recorded_stamp_is_drawn_from_the_current_settings(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        ApprovalStep::where('request_id', $r->id)->update(['stamp_label' => null, 'stamp_text' => null]);

        $this->assertSame(['住宅', 'R8.10.5', '部門'], $this->stampOf($r, ApprovalStepKind::Head));
    }

    public function test_the_date_is_in_the_japanese_era_of_the_calendar_day_in_japan(): void
    {
        $this->assertSame('R8.10.4', Stamp::eraDate(Carbon::parse('2026-10-04 14:59:59', 'UTC')), '日本時間 10/4 23:59');
        $this->assertSame('R8.10.5', Stamp::eraDate(Carbon::parse('2026-10-04 15:00:00', 'UTC')), '日本時間 10/5 0:00');
        $this->assertSame('R1.5.1', Stamp::eraDate(Carbon::parse('2019-04-30 15:00:00', 'UTC')), '令和の初日');
        $this->assertSame('H31.4.30', Stamp::eraDate(Carbon::parse('2019-04-30 14:59:59', 'UTC')), '平成の最後の日');
        $this->assertSame('R9.1.1', Stamp::eraDate(Carbon::parse('2026-12-31 15:00:00', 'UTC')), '年度ではなく暦の年');
    }
}
```

（差分のファイルを使うなら: `… git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/4a/patches/0002-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase4/StampTest.php
```

Expected: `ERRORS!` `Tests: 10, Assertions: 0, Errors: 10.`

- `StampTest::test_the_default_text_is_the_name_before_the_first_space` — `Error: Class "App\Support\Approval\StampText" not found`
- `StampTest::test_the_text_set_by_the_admin_wins_over_the_name` — `Error: Class "App\Support\Approval\StampText" not found`
- `StampTest::test_each_judgement_records_its_stamp` — `Error: Class "App\Support\Approval\Stamp" not found`
- `StampTest::test_a_return_is_stamped_too` — `Error: Class "App\Support\Approval\Stamp" not found`
- `StampTest::test_changing_the_settings_later_does_not_change_the_stamp` — `Error: Class "App\Support\Approval\Stamp" not found`
- `StampTest::test_the_stamp_uses_the_text_set_at_the_time_of_the_judgement` — `Error: Class "App\Support\Approval\Stamp" not found`
- `StampTest::test_undo_removes_the_stamp_with_the_judgement` — `Error: Class "App\Support\Approval\Stamp" not found`
- `StampTest::test_steps_that_are_not_judged_have_no_stamp` — `Error: Class "App\Support\Approval\Stamp" not found`
- `StampTest::test_a_judgement_without_a_recorded_stamp_is_drawn_from_the_current_settings` — `Error: Class "App\Support\Approval\Stamp" not found`
- `StampTest::test_the_date_is_in_the_japanese_era_of_the_calendar_day_in_japan` — `Error: Class "App\Support\Approval\Stamp" not found`

- [ ] **Step 3: 印の文字の規則と 1 つの印**

`app/Support/Approval/StampText.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStepKind;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalStep;
use App\Models\User;

/**
 * 印の上段と下段の文字を決める（要件 9.1・段階4 設計書 D6・D8）。**規則はここ 1 か所。**
 *
 * - 上段: 部門長は段階の部門（申請部門の控え）の略称・審査担当者は段階の部門（審査部門）の略称・社長は「社長」
 * - 下段: 利用者の「印に使う文字」。空なら氏名の最初の空白（全角・半角）より前。空白の無い氏名は氏名全体
 *
 * ⚠ 判断したときに `Workflow::finishStep()` がここで決めた文字を段階に控える（D3）。控えたあとは設定を変えても印は変わらない。
 */
final class StampText
{
    public const PRESIDENT_LABEL = '社長';

    /** 氏名から決める既定の下段 */
    public static function defaultFor(string $name): string
    {
        $trimmed = preg_replace('/^[\s　]+|[\s　]+$/u', '', $name) ?? $name;

        return preg_split('/[\s　]+/u', $trimmed, 2)[0];
    }

    /** その人の下段（「印に使う文字」が空なら氏名から） */
    public static function for(User $user): string
    {
        $text = $user->approvalMember?->stamp_text;

        return $text !== null && $text !== '' ? $text : self::defaultFor($user->name);
    }

    /** その段階の上段（今の部門の略称。控える前に呼ぶ） */
    public static function labelFor(ApprovalStep $step): string
    {
        if ($step->kind === ApprovalStepKind::President) {
            return self::PRESIDENT_LABEL;
        }

        return (string) ApprovalDepartment::whereKey($step->department_id)->value('short_name');
    }
}
```

`app/Support/Approval/Stamp.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\JapanTime;
use DateTimeInterface;

/**
 * 押された 1 つの印（上段・中段の日付・下段。要件 9.1・段階4 設計書 §5.4）。
 *
 * 判断した段階の印は、段階に控えた文字（D3）と判断の日時（日本時間の暦の和暦。D7）から作る。
 * ⚠ 控えの無い判断（4a より前の判断。本番には無い）は、今の設定から作る（設計書 §5.3）。
 */
final class Stamp
{
    private const REIWA_START = '2019-05-01';

    private function __construct(
        public readonly string $label,
        public readonly string $date,
        public readonly string $text,
    ) {
    }

    /** 判断した段階の印。判断していない段階（待ち・省略・打ち切り）は null */
    public static function forStep(ApprovalStep $step): ?self
    {
        if ($step->status !== ApprovalStepStatus::Done || $step->acted_at === null) {
            return null;
        }

        $label = $step->stamp_label ?? StampText::labelFor($step);
        $text  = $step->stamp_text ?? ($step->actor !== null ? StampText::for($step->actor) : '');

        return new self($label, self::eraDate($step->acted_at), $text);
    }

    /** 利用者の管理（⑦）の見本の印（上段は最初の所属部門の略称・日付は今日。D11） */
    public static function preview(User $user): self
    {
        $label = (string) $user->approvalDepartments->sortBy('sort_order')->first()?->short_name;

        return new self($label, self::eraDate(JapanTime::today()), StampText::for($user));
    }

    /** 日本時間の暦の日付を和暦の短い形にする（例 R8.10.5。令和より前は H） */
    public static function eraDate(DateTimeInterface $at): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', JapanTime::format($at, 'Y-n-j')));
        $isReiwa = JapanTime::format($at, 'Y-m-d') >= self::REIWA_START;

        return sprintf('%s%d.%d.%d', $isReiwa ? 'R' : 'H', $isReiwa ? $y - 2018 : $y - 1988, $m, $d);
    }
}
```

- [ ] **Step 4: 判断のときに控え、取り消しで消す**

`app/Support/Approval/Workflow.php`（変更）

```diff
--- a/app/Support/Approval/Workflow.php
+++ b/app/Support/Approval/Workflow.php
@@ -569,7 +569,7 @@ private function bump(ApprovalRequest $request, int $lockVersion): void
 
     /**
      * 取り消した判断の段階を「待ち」に戻し、今の回のそれより後ろの段階を「まだ届いていない」に戻す（取り消し）。
-     * 届いた日時（arrived_at）は空にしない（§5.16）。担当の付け替え（assignee_user_id）はそのまま
+     * 届いた日時（arrived_at）は空にしない（§5.16）。担当の付け替え（assignee_user_id）はそのまま。印の控えは判断と一緒に消す（段階4 D3）
      *
      * ⚠ 後ろの段階は、ロックした今の回の段階の行から id を選び、主キーだけで書く。`request_id`・`round` と
      *   `id > ?` の範囲の条件で UPDATE すると、MySQL は索引の次の項目＝隣の申請の段階の行までロックし、
@@ -587,6 +587,8 @@ private function reopenStep(Collection $steps, int $stepId): void
             'actor_user_id' => null,
             'result'        => null,
             'comment'       => null,
+            'stamp_label'   => null,
+            'stamp_text'    => null,
             'updated_at'    => $now,
         ]);
 
@@ -601,6 +603,9 @@ private function reopenStep(Collection $steps, int $stepId): void
         }
     }
 
+    /**
+     * 判断を書く。印の上段と下段もここで控える（段階4 設計書 D3。あとで部門の略称や印に使う文字を変えても、押した印は変わらない）
+     */
     private function finishStep(ApprovalStep $step, User $actor, ApprovalStepResult $result, ?string $comment): void
     {
         $step->update([
@@ -609,6 +614,8 @@ private function finishStep(ApprovalStep $step, User $actor, ApprovalStepResult
             'actor_user_id' => $actor->id,
             'result'        => $result,
             'comment'       => $comment,
+            'stamp_label'   => StampText::labelFor($step),
+            'stamp_text'    => StampText::for($actor),
         ]);
     }
```

（差分のファイルを使うなら: `… git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/4a/patches/0002-*.patch`）

- [ ] **Step 5: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (10 tests, …)`

- [ ] **Step 6: 全件を流す**

Expected: `OK (3347 tests, 23794 assertions)`

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/StampText.php app/Support/Approval/Stamp.php app/Support/Approval/Workflow.php tests/Feature/Approval/Phase4/StampTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 判断したときに印の部署と文字を控える

印の上段（部門長は申請部門・審査は審査部門の略称・社長は「社長」）と下段
（印に使う文字。空なら氏名の最初の空白より前）を StampText の 1 か所で決め、
判断のときに段階へ控え、押し間違いの取り消しで消す。日付は日本の暦の和暦
（段階4 設計書 §5.4・D3・D6〜D8）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 3: 印を描く部品と、申請の詳細の印

印を SVG に描く部品（`StampSvg`）を 1 つ作り、申請の詳細の「回る順番」の判断した段階の右端に出す。文字が多いときは段の幅に合わせて小さくする。文字は必ずエスケープする（設計書 §5.4・§0.3・D8・D12）。

**Files:**
- Create: `app/Support/Approval/StampSvg.php`
- Modify: `resources/views/approvals/requests/_steps.blade.php`
- Test: Create `tests/Feature/Approval/Phase4/StampDisplayTest.php`・Modify `tests/Feature/Approval/Phase2/RequestActionTest.php`（§0.9）

**Interfaces:**
- Consumes: Task 2 の `Stamp`
- Produces: `StampSvg::render(Stamp $stamp, string $font = StampSvg::SCREEN_FONT, string $size = '48'): string`（エスケープ済みの SVG）・`StampSvg::fit(string $text, int $width, int $max): int`・`StampSvg::COLOR`・`SCREEN_FONT`・`PDF_FONT`（= `'ipaexm'`）。Task 4・5 が使う

**差分の大きさ:** 4 ファイル・+208 / −27 行（差分のファイル `0003-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase4/StampDisplayTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalMember;
use App\Models\ApprovalStep;
use App\Support\Approval\Stamp;
use App\Support\Approval\StampSvg;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 印の描き方と申請の詳細の印（要件 9.1・段階4 設計書 §5.4・D8・D12） */
class StampDisplayTest extends TestCase
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

    private function judgedStamp(string $text): Stamp
    {
        $w = $this->approvalWorld();
        ApprovalMember::create(['user_id' => $w['head']->id, 'stamp_text' => $text]);
        $r = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);

        return Stamp::forStep(ApprovalStep::where('request_id', $r->id)->where('kind', 'head')->firstOrFail());
    }

    public function test_the_stamp_has_the_three_rows_in_the_stamp_color(): void
    {
        $svg = StampSvg::render($this->judgedStamp('山田'));

        $this->assertStringContainsString('aria-label="住宅 R8.10.5 山田 の印"', $svg);
        $this->assertSame(3, substr_count($svg, '<text '), '上段・中段・下段');
        $this->assertSame(1, substr_count($svg, '<circle '));
        $this->assertSame(2, substr_count($svg, '<line '));
        $this->assertStringContainsString('>住宅</text>', $svg);
        $this->assertStringContainsString('>R8.10.5</text>', $svg);
        $this->assertStringContainsString('>山田</text>', $svg);
        $this->assertSame(6, substr_count($svg, '"' . StampSvg::COLOR . '"'), '丸・線 2 本・文字 3 つは朱の色');
    }

    public function test_the_text_is_escaped(): void
    {
        $svg = StampSvg::render($this->judgedStamp('<b>'));

        $this->assertStringNotContainsString('<b>', $svg);
        $this->assertStringContainsString('>&lt;b&gt;</text>', $svg);
    }

    public function test_longer_text_is_drawn_smaller_to_stay_inside_the_stamp(): void
    {
        $this->assertSame(24, StampSvg::fit('山田', 62, 24));
        $this->assertSame(20, StampSvg::fit('長谷川', 62, 24));
        $this->assertSame(15, StampSvg::fit('勅使河原', 62, 24));
        $this->assertSame(8, StampSvg::fit(str_repeat('長', 20), 62, 24), '下限は 8');
        $this->assertSame(11, StampSvg::fit('住宅事業部長', 66, 20), '上段の 6 文字');
    }

    public function test_the_stamp_is_drawn_with_the_fitted_sizes(): void
    {
        $svg = StampSvg::render($this->judgedStamp('勅使河原'));

        $this->assertStringContainsString('font-size="20" fill="' . StampSvg::COLOR . '">住宅</text>', $svg, '上段の 2 文字は上限の 20');
        $this->assertStringContainsString('font-size="15" fill="' . StampSvg::COLOR . '">勅使河原</text>', $svg, '下段の 4 文字は 15 に縮める');
    }

    public function test_a_long_department_name_is_drawn_smaller_in_the_top_row(): void
    {
        $w = $this->approvalWorld();
        $w['dept']->update(['short_name' => '住宅事業部長']);
        $r = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);

        $svg = StampSvg::render(Stamp::forStep(ApprovalStep::where('request_id', $r->id)->where('kind', 'head')->firstOrFail()));

        $this->assertStringContainsString('font-size="11" fill="' . StampSvg::COLOR . '">住宅事業部長</text>', $svg, '上段の 6 文字は 11 に縮める');
    }

    public function test_the_detail_shows_the_stamp_beside_each_judged_step(): void
    {
        $this->launchApprovals();
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $r))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-stamp'), '判断した段階は部門長だけ');
        $this->assertStringContainsString('aria-label="住宅 R8.10.5 部門 の印"', $html);
    }

    public function test_a_skipped_head_step_shows_the_reason_instead_of_a_stamp(): void
    {
        $this->launchApprovals();
        $w = $this->approvalWorld();
        $w['head']->approvalDepartments()->attach($w['dept']->id);
        $r = $this->submittedFor(array_merge($w, ['applicant' => $w['head']->fresh()]));

        $html = $this->actingAs($w['head'])->get(route('approvals.requests.show', $r))->assertOk()->getContent();

        $this->assertStringContainsString('申請者が部門長のため省略', $html);
        $this->assertStringNotContainsString('data-stamp', $html);
    }
}
```

既存のテストの意味の変わる 1 行（§0.9。エスケープした氏名が印の文字と読み上げの名前の 2 か所に増える）:

`tests/Feature/Approval/Phase2/RequestActionTest.php`（変更）

```diff
--- a/tests/Feature/Approval/Phase2/RequestActionTest.php
+++ b/tests/Feature/Approval/Phase2/RequestActionTest.php
@@ -663,7 +663,7 @@ public function test_comments_and_names_are_escaped(): void
         $this->assertStringNotContainsString('<b>部門</b>', $html);
         $this->assertSame(3, substr_count($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));   // 回る順番・記録・提出の履歴（2b）
         $this->assertSame(4, substr_count($html, '&lt;img src=x onerror=alert(2)&gt;'));     // 条件・回る順番・記録・提出の履歴（2b）
-        $this->assertSame(3, substr_count($html, '&lt;b&gt;部門&lt;/b&gt;長'));              // 回る順番・記録・提出の履歴（2b）
+        $this->assertSame(5, substr_count($html, '&lt;b&gt;部門&lt;/b&gt;長'));              // 回る順番・記録・提出の履歴（2b）・印の文字と読み上げの名前（4a。空白の無い氏名は氏名全体が印の文字）
     }
 
     /** 画面の版は 0 以上の整数の形だけを受け取る（配列・'1abc'・'1.0' は、先を越されたものとして断る。前後の空白は TrimStrings が外す。Task 15 の点検の m-1） */
```

（差分のファイルを使うなら: `… git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/4a/patches/0003-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase4/StampDisplayTest.php tests/Feature/Approval/Phase2/RequestActionTest.php
```

Expected: `ERRORS!` `Tests: 51, Assertions: 500, Errors: 5, Failures: 2.`

- `StampDisplayTest::test_the_stamp_has_the_three_rows_in_the_stamp_color` — `Error: Class "App\Support\Approval\StampSvg" not found`
- `StampDisplayTest::test_the_text_is_escaped` — `Error: Class "App\Support\Approval\StampSvg" not found`
- `StampDisplayTest::test_longer_text_is_drawn_smaller_to_stay_inside_the_stamp` — `Error: Class "App\Support\Approval\StampSvg" not found`
- `StampDisplayTest::test_the_stamp_is_drawn_with_the_fitted_sizes` — `Error: Class "App\Support\Approval\StampSvg" not found`
- `StampDisplayTest::test_a_long_department_name_is_drawn_smaller_in_the_top_row` — `Error: Class "App\Support\Approval\StampSvg" not found`
- `RequestActionTest::test_comments_and_names_are_escaped` — `Failed asserting that 3 is identical to 5.`
- `StampDisplayTest::test_the_detail_shows_the_stamp_beside_each_judged_step` — `判断した段階は部門長だけ`

- [ ] **Step 3: 印を描く部品**

`app/Support/Approval/StampSvg.php`（新規）

```php
<?php

namespace App\Support\Approval;

/**
 * 印を SVG で描く（要件 9.1 の図・段階4 設計書 D12）。**申請の詳細・利用者の管理（⑦）・PDF で同じものを使う。**
 *
 * 赤い丸の中を 2 本の横線で 3 段に分け、上段＝部署・中段＝日付・下段＝印に使う文字。
 * 文字が多いときは段の幅に収まるよう小さくする（D8）。座標は 120 × 120 の箱で決め、大きさは `$size` で変える。
 *
 * ⚠ 文字は必ず e() を通す（下段は利用者の氏名から来る）。
 * ⚠ 色は画面の配色（CSS の変数）を使わない。PDF（mPDF）は CSS の変数を読めず、印は紙の朱肉と同じ色で出す。
 */
final class StampSvg
{
    public const COLOR = '#C4382E';

    /** 画面の書体（端末の明朝体） */
    public const SCREEN_FONT = "'Hiragino Mincho ProN','Yu Mincho','YuMincho','Noto Serif JP',serif";

    /** PDF の書体（mPDF に登録した IPAex 明朝の名前。ApprovalPdf の fontdata と同じ） */
    public const PDF_FONT = 'ipaexm';

    public static function render(Stamp $stamp, string $font = self::SCREEN_FONT, string $size = '48'): string
    {
        $label = e($stamp->label);
        $date  = e($stamp->date);
        $text  = e($stamp->text);
        $font  = e($font);
        $color = self::COLOR;
        $aria  = e(trim("{$stamp->label} {$stamp->date} {$stamp->text}") . ' の印');

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" width="' . e($size) . '" height="' . e($size) . '" role="img" aria-label="' . $aria . '">'
            . '<circle cx="60" cy="60" r="54" fill="none" stroke="' . $color . '" stroke-width="3"/>'
            . '<line x1="8" y1="42" x2="112" y2="42" stroke="' . $color . '" stroke-width="2.5"/>'
            . '<line x1="8" y1="78" x2="112" y2="78" stroke="' . $color . '" stroke-width="2.5"/>'
            . self::text(60, 34, self::fit($stamp->label, 66, 20), $label, $font, $color)
            . self::text(60, 67, 16, $date, $font, $color)
            . self::text(60, 101, self::fit($stamp->text, 62, 24), $text, $font, $color)
            . '</svg>';
    }

    /** 段の幅（120 の箱の単位）に収まる文字の大きさ（上限 $max） */
    public static function fit(string $text, int $width, int $max): int
    {
        return max(8, min($max, intdiv($width, max(1, mb_strlen($text)))));
    }

    private static function text(int $x, int $y, int $fontSize, string $escaped, string $font, string $color): string
    {
        return '<text x="' . $x . '" y="' . $y . '" text-anchor="middle" font-family="' . $font . '" font-size="' . $fontSize . '" fill="' . $color . '">' . $escaped . '</text>';
    }
}
```

- [ ] **Step 4: 回る順番に印を出す**（左の文字の並びはそのまま。右端に印の列を足すので、行の中身を 1 段深く入れ子にする）

`resources/views/approvals/requests/_steps.blade.php`（変更）

```diff
--- a/resources/views/approvals/requests/_steps.blade.php
+++ b/resources/views/approvals/requests/_steps.blade.php
@@ -1,5 +1,5 @@
 {{-- 回る順番と各人の判断（今の回。設計書 §5.12）。担当は今の設定から引く（CurrentHandler）。
-     前の回の判断は「操作の記録」に出る。データ印は段階4。 --}}
+     前の回の判断は「操作の記録」に出る。判断した段階には、判断したときに控えた印を出す（段階4 設計書 §5.4）。 --}}
 @php $steps = $approvalRequest->currentSteps(); @endphp
 <section class="bg-white rounded-lg border border-gray-200 mb-5">
     <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">
@@ -14,32 +14,40 @@
         <ol class="divide-y divide-gray-100">
             @foreach($steps as $step)
                 <li class="px-5 py-3 text-[13px]">
-                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
-                        <span class="font-semibold text-gray-900 min-w-[4em]">{{ $step->kind->label() }}</span>
-                        <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $step->status->badgeStyle() }}">{{ $step->status->label() }}</span>
-                        @switch($step->status)
-                            @case(\App\Enums\ApprovalStepStatus::Done)
-                                <span class="font-semibold text-gray-900">{{ $step->result->labelFor($step->kind) }}</span>
-                                <span class="text-gray-700">{{ $step->actor?->name }}</span>
-                                <span class="text-[12px] text-gray-400">{{ \App\Support\JapanTime::format($step->acted_at) }}</span>
-                                @break
-                            @case(\App\Enums\ApprovalStepStatus::Skipped)
-                                <span class="text-gray-500">申請者が部門長のため省略</span>
-                                @break
-                            @case(\App\Enums\ApprovalStepStatus::Cancelled)
-                                <span class="text-gray-500">差戻し・取り下げのため打ち切り</span>
-                                @break
-                            @default
-                                <span class="text-gray-700">担当: {{ \App\Support\Approval\CurrentHandler::describe($step) }}</span>
-                                {{-- 届いた日時は待ちの段階だけに出す（取り消しで「まだ届いていない」に戻した段階は届いた日時が残る。2b 計画 §0.4） --}}
-                                @if($step->status === \App\Enums\ApprovalStepStatus::Waiting && $step->arrived_at)
-                                    <span class="text-[12px] text-gray-400">{{ \App\Support\JapanTime::format($step->arrived_at) }} に届きました</span>
-                                @endif
-                        @endswitch
+                    <div class="flex items-start justify-between gap-3">
+                        <div class="min-w-0 flex-1">
+                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
+                                <span class="font-semibold text-gray-900 min-w-[4em]">{{ $step->kind->label() }}</span>
+                                <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $step->status->badgeStyle() }}">{{ $step->status->label() }}</span>
+                                @switch($step->status)
+                                    @case(\App\Enums\ApprovalStepStatus::Done)
+                                        <span class="font-semibold text-gray-900">{{ $step->result->labelFor($step->kind) }}</span>
+                                        <span class="text-gray-700">{{ $step->actor?->name }}</span>
+                                        <span class="text-[12px] text-gray-400">{{ \App\Support\JapanTime::format($step->acted_at) }}</span>
+                                        @break
+                                    @case(\App\Enums\ApprovalStepStatus::Skipped)
+                                        <span class="text-gray-500">申請者が部門長のため省略</span>
+                                        @break
+                                    @case(\App\Enums\ApprovalStepStatus::Cancelled)
+                                        <span class="text-gray-500">差戻し・取り下げのため打ち切り</span>
+                                        @break
+                                    @default
+                                        <span class="text-gray-700">担当: {{ \App\Support\Approval\CurrentHandler::describe($step) }}</span>
+                                        {{-- 届いた日時は待ちの段階だけに出す（取り消しで「まだ届いていない」に戻した段階は届いた日時が残る。2b 計画 §0.4） --}}
+                                        @if($step->status === \App\Enums\ApprovalStepStatus::Waiting && $step->arrived_at)
+                                            <span class="text-[12px] text-gray-400">{{ \App\Support\JapanTime::format($step->arrived_at) }} に届きました</span>
+                                        @endif
+                                @endswitch
+                            </div>
+                            @if($step->comment)
+                                <p class="mt-1.5 rounded-md bg-gray-50 px-3 py-2 text-gray-800 whitespace-pre-wrap break-words">{{ $step->comment }}</p>
+                            @endif
+                        </div>
+                        @if($stamp = \App\Support\Approval\Stamp::forStep($step))
+                            {{-- StampSvg は文字を e() で包んだ SVG を返す --}}
+                            <span class="shrink-0" data-stamp>{!! \App\Support\Approval\StampSvg::render($stamp) !!}</span>
+                        @endif
                     </div>
-                    @if($step->comment)
-                        <p class="mt-1.5 rounded-md bg-gray-50 px-3 py-2 text-gray-800 whitespace-pre-wrap break-words">{{ $step->comment }}</p>
-                    @endif
                 </li>
             @endforeach
         </ol>
```

（差分のファイルを使うなら: `… git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/4a/patches/0003-*.patch`）

- [ ] **Step 5: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (51 tests, …)`

- [ ] **Step 6: 全件を流す**

Expected: `OK (3354 tests, 23819 assertions)`

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Support/Approval/StampSvg.php resources/views/approvals/requests/_steps.blade.php tests/Feature/Approval/Phase4/StampDisplayTest.php tests/Feature/Approval/Phase2/RequestActionTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 申請の詳細の回る順番に印を出す

赤い丸を 3 段に分けた印（上段＝部署・中段＝日付・下段＝印に使う文字）を
SVG で描く StampSvg を足し、申請の詳細の判断した段階の右端に出す。文字が
多いときは段の幅に合わせて小さくし、文字は必ずエスケープする
（段階4 設計書 §5.4・D8・D12）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 4: 利用者の管理（⑦）で印に使う文字を直す

⑦ の編集の小窓に「印に使う文字」（4 文字まで・空なら氏名から）を足し、所属部門と同じく**全員について**直せるようにする（氏名を直せない人も）。変えたら設定の記録に残す。一覧の氏名の左に印の見本を出す（設計書 §5.5・§0.6・D9〜D11・D13）。

**Files:**
- Modify: `app/Http/Controllers/Approval/UserController.php`・`resources/views/approvals/admin/users/index.blade.php`
- Test: Create `tests/Feature/Approval/Phase4/StampTextSettingTest.php`

**Interfaces:**
- Consumes: Task 1 の `approval_members.stamp_text`・Task 2 の `Stamp::preview()`・Task 3 の `StampSvg::render()`
- Produces: `PUT approvals.admin.users.update` の入力 `stamp_text`・設定の記録 `user.stamp_changed`（`old_values` / `new_values` は `['stamp_text' => …]`）

**差分の大きさ:** 3 ファイル・+197 / −8 行（差分のファイル `0004-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase4/StampTextSettingTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase4;

use App\Models\ApprovalMember;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 利用者の管理（⑦）の「印に使う文字」（要件 3.2・9.1・段階4 設計書 §5.5・D9〜D11・D13） */
class StampTextSettingTest extends TestCase
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

    private function saveStamp(User $admin, User $target, ?string $stamp): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($admin)->put(route('approvals.admin.users.update', $target), [
            'name'                 => $target->name,
            'employee_number'      => $target->employee_number,
            'approval_departments' => $target->approvalDepartments->pluck('id')->all(),
            'stamp_text'           => $stamp,
        ]);
    }

    /** @return list<array{mixed, mixed}> */
    private function stampLogs(User $target): array
    {
        return ApprovalSettingLog::where('action', 'user.stamp_changed')->where('target_id', $target->id)->orderBy('id')->get()
            ->map(fn (ApprovalSettingLog $log) => [$log->old_values['stamp_text'], $log->new_values['stamp_text']])->all();
    }

    public function test_the_admin_sets_the_stamp_text_before_launch(): void
    {
        $admin  = $this->approvalAdmin();
        $member = $this->approvalOnlyUser(['name' => '山田太郎', 'employee_number' => 'E001']);

        $this->saveStamp($admin, $member, '山田')->assertRedirect(route('approvals.admin.users.index'));

        $this->assertSame('山田', $member->fresh()->approvalMember->stamp_text);
        $this->assertSame([[null, '山田']], $this->stampLogs($member), '前と後を設定の記録に残す');
    }

    /** 氏名を直せない人（基幹を使う人・社長に指定された人）でも、印の文字は直せる（D10） */
    public function test_anyone_can_get_a_stamp_text_even_when_the_name_cannot_be_edited(): void
    {
        $admin     = $this->approvalAdmin();
        $base      = $this->baseUser(['name' => '基幹 太郎']);
        $president = $this->makePresident();

        $this->saveStamp($admin, $base, '基幹')->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('approvals.admin.users.update', $president), ['stamp_text' => '佐藤'])->assertSessionHasNoErrors();

        $this->assertSame('基幹', $base->fresh()->approvalMember->stamp_text);
        $this->assertSame('佐藤', $president->fresh()->approvalMember->stamp_text);
        $this->assertSame('社長 太郎', $president->fresh()->name, '氏名は変わらない');
    }

    public function test_clearing_the_text_goes_back_to_the_name(): void
    {
        $admin  = $this->approvalAdmin();
        $member = $this->approvalOnlyUser(['name' => '山田 太郎', 'employee_number' => 'E001']);
        ApprovalMember::create(['user_id' => $member->id, 'stamp_text' => '山']);

        $this->saveStamp($admin, $member->fresh(), '')->assertSessionHasNoErrors();

        $this->assertNull($member->fresh()->approvalMember->stamp_text);
        $this->assertSame([['山', null]], $this->stampLogs($member));
    }

    public function test_spaces_around_the_text_are_removed_including_full_width_ones(): void
    {
        $admin  = $this->approvalAdmin();
        $member = $this->approvalOnlyUser(['name' => '山田太郎', 'employee_number' => 'E001']);

        $this->saveStamp($admin, $member, '　山田　')->assertSessionHasNoErrors();

        $this->assertSame('山田', $member->fresh()->approvalMember->stamp_text);
    }

    public function test_more_than_four_characters_are_refused(): void
    {
        $admin  = $this->approvalAdmin();
        $member = $this->approvalOnlyUser(['name' => '山田太郎', 'employee_number' => 'E001']);

        $this->saveStamp($admin, $member, '勅使河原三')->assertSessionHasErrors(['stamp_text' => '印に使う文字は4文字以下で入力してください。']);

        $this->assertNull($member->fresh()->approvalMember);
        $this->assertSame([], $this->stampLogs($member));
    }

    public function test_saving_the_same_text_again_records_nothing(): void
    {
        $admin  = $this->approvalAdmin();
        $member = $this->approvalOnlyUser(['name' => '山田太郎', 'employee_number' => 'E001']);
        ApprovalMember::create(['user_id' => $member->id, 'stamp_text' => '山田']);

        $this->saveStamp($admin, $member->fresh(), '山田')->assertSessionHasNoErrors();

        $this->assertSame([], $this->stampLogs($member));
    }

    public function test_an_update_without_the_field_keeps_the_stamp_text(): void
    {
        $admin  = $this->approvalAdmin();
        $member = $this->approvalOnlyUser(['name' => '山田太郎', 'employee_number' => 'E001']);
        ApprovalMember::create(['user_id' => $member->id, 'stamp_text' => '山田']);

        $this->actingAs($admin)->put(route('approvals.admin.users.update', $member), [
            'name' => '山田太郎', 'employee_number' => 'E001', 'approval_departments' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame('山田', $member->fresh()->approvalMember->stamp_text);
        $this->assertSame([], $this->stampLogs($member));
    }

    public function test_the_list_shows_a_preview_of_each_stamp_and_the_edit_form_has_the_field(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        ApprovalMember::create(['user_id' => $w['applicant']->id, 'stamp_text' => '花子']);

        $html = $this->actingAs($admin)->get(route('approvals.admin.users.index'))->assertOk()->getContent();

        // 上段は最初の所属部門の略称・日付は今日（日本時間）・下段は印に使う文字
        $this->assertStringContainsString('aria-label="住宅 R8.10.5 花子 の印"', $html);
        $this->assertStringContainsString('aria-label="R8.10.5 部門 の印"', $html, '所属の無い人は上段が空・下段は氏名の空白より前');
        $this->assertStringContainsString('name="stamp_text"', $html);
        $this->assertStringContainsString('決裁の所属部門と印に使う文字だけ変えられます。', $html);
        $this->assertStringContainsString('決裁の所属部門と印に使う文字は全員について変えられます。', $html, '画面の説明');
    }
}
```

（差分のファイルを使うなら: `… git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/4a/patches/0004-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase4/StampTextSettingTest.php
```

Expected: `ERRORS!` `Tests: 8, Assertions: 15, Errors: 3, Failures: 3.`

- `StampTextSettingTest::test_the_admin_sets_the_stamp_text_before_launch` — `ErrorException: Attempt to read property "stamp_text" on null`
- `StampTextSettingTest::test_anyone_can_get_a_stamp_text_even_when_the_name_cannot_be_edited` — `ErrorException: Attempt to read property "stamp_text" on null`
- `StampTextSettingTest::test_spaces_around_the_text_are_removed_including_full_width_ones` — `ErrorException: Attempt to read property "stamp_text" on null`
- `StampTextSettingTest::test_clearing_the_text_goes_back_to_the_name` — `Failed asserting that '山' is null.`
- `StampTextSettingTest::test_more_than_four_characters_are_refused` — `Session is missing expected key [errors].`
- `StampTextSettingTest::test_the_list_shows_a_preview_of_each_stamp_and_the_edit_form_has_the_field` — `Failed asserting that '<!DOCTYPE html>\n`

- [ ] **Step 3: 保存と検証**

`app/Http/Controllers/Approval/UserController.php`（変更）

```diff
--- a/app/Http/Controllers/Approval/UserController.php
+++ b/app/Http/Controllers/Approval/UserController.php
@@ -6,6 +6,7 @@
 use App\Enums\UserStatus;
 use App\Http\Controllers\Controller;
 use App\Models\ApprovalCompany;
+use App\Models\ApprovalMember;
 use App\Models\ApprovalSetting;
 use App\Models\User;
 use App\Support\Approval\PasswordReissuer;
@@ -145,7 +146,7 @@ private function scalarInput(Request $request, string $key): string
     }
 
     /**
-     * 所属部門は全員、氏名と社員番号は「決裁のみ かつ 指定されていない人」だけ。
+     * 所属部門と印に使う文字は全員、氏名と社員番号は「決裁のみ かつ 指定されていない人」だけ。
      * Route: PUT /approvals/admin/users/{user}
      */
     public function update(Request $request, User $user)
@@ -170,12 +171,18 @@ public function update(Request $request, User $user)
         ], [
             'name' => '氏名',
             'approval_departments' => '決裁の所属部門',
+            'stamp_text' => '印に使う文字',
         ]);
 
         // ⚠ 所属の付け替えと氏名・社員番号の保存、その記録をまとめて 1 つにする。
         //   囲まないと、`save()` が DB 側の一意制約に当たったときに**所属だけ変わった状態**が
         //   残り、画面には「更新しました」も出ない（`PasswordReissuer` も同じ理由で囲っている）。
         DB::transaction(function () use ($user, $validated, $editable) {
+            // 欄が送られてきたときだけ（送られていない更新で印の文字を消さない）
+            if (array_key_exists('stamp_text', $validated)) {
+                $this->saveStampText($user, $validated['stamp_text']);
+            }
+
             // 所属部門は全員について編集できる（要件 3.2・D16 の例外）
             $before = $user->approvalDepartments->pluck('id')->sort()->values()->all();
             $after  = collect($validated['approval_departments'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all();
@@ -201,6 +208,25 @@ public function update(Request $request, User $user)
         return redirect()->route('approvals.admin.users.index')->with('success', "「{$user->name}」さんの情報を更新しました。");
     }
 
+    /**
+     * 印に使う文字（要件 3.2・9.1・段階4 設計書 D9・D10）。所属部門と同じく、指定された人や基幹を使う人も直せる
+     * （ログインやなりすましに使えず、変えたことは記録に残り、押した印は変わらないため）。空にすると氏名からの既定に戻る。
+     *
+     * 前後の空白（全角を含む）は TrimStrings（Str::trim）が外し、空は ConvertEmptyStringsToNull が null にする
+     * （どちらもアプリ全体の前処理。2026-10-03 に全角の空白でも確かめた）。
+     */
+    private function saveStampText(User $user, ?string $after): void
+    {
+        $before = $user->approvalMember?->stamp_text;
+
+        if ($before === $after) {
+            return;
+        }
+
+        ApprovalMember::updateOrCreate(['user_id' => $user->id], ['stamp_text' => $after]);
+        SettingLogger::record('user.stamp_changed', 'user', $user->id, ['stamp_text' => $before], ['stamp_text' => $after]);
+    }
+
     /**
      * 編集の検証ルール。
      *
@@ -219,6 +245,7 @@ private function updateRules(User $user, bool $editable): array
             //   （`approval_departments.*` の `exists` は要素ごとに 1 回問い合わせる）
             'approval_departments'   => ['array', 'max:100'],
             'approval_departments.*' => [Rule::exists('approval_departments', 'id')],
+            'stamp_text'             => ['nullable', 'string', 'max:4'],
         ];
 
         if ($editable) {
```

- [ ] **Step 4: 一覧の見本と小窓の欄**

`resources/views/approvals/admin/users/index.blade.php`（変更）

```diff
--- a/resources/views/approvals/admin/users/index.blade.php
+++ b/resources/views/approvals/admin/users/index.blade.php
@@ -40,7 +40,7 @@
            class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-semibold rounded-md cursor-pointer whitespace-nowrap">社員の一括登録（CSV）</a>
     </div>
     <p class="text-[12px] text-gray-500 mb-5">
-        決裁の所属部門は全員について変えられます。氏名・社員番号の修正、無効化・有効化、パスワードの再発行は、決裁だけを使う利用者に対してのみ行えます。
+        決裁の所属部門と印に使う文字は全員について変えられます。氏名・社員番号の修正、無効化・有効化、パスワードの再発行は、決裁だけを使う利用者に対してのみ行えます。
         メールアドレスの変更と利用者の削除は、基幹の管理者に依頼してください。
         決裁だけを使う利用者を新しく登録するときは「社員の一括登録（CSV）」を使ってください（1 人だけでも使えます）。
     </p>
@@ -197,11 +197,17 @@ class="px-5 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-md text-[13p
                         </td>
                         <td class="px-3.5 py-2.5 border-b border-gray-100 whitespace-nowrap font-mono text-[12px] text-gray-700">{{ $u->employee_number ?? '—' }}</td>
                         <td class="px-3.5 py-2.5 border-b border-gray-100">
-                            <span class="text-[13px] font-medium text-gray-900">{{ $u->name }}</span>
-                            @if($privilegeLabel !== null)
-                                {{-- ⚠ 理由は title だけでなく画面の本文にも出す（tooltip はキーボード・読み上げに届かない。Bug #43） --}}
-                                <span class="block text-[11px] text-gray-500">{{ $privilegeLabel }}に指定されています</span>
-                            @endif
+                            <div class="flex items-center gap-2.5">
+                                {{-- 印の見本（段階4 設計書 D11。日付は今日。StampSvg は文字を e() で包んだ SVG を返す） --}}
+                                <span class="shrink-0" data-stamp-preview>{!! \App\Support\Approval\StampSvg::render(\App\Support\Approval\Stamp::preview($u)) !!}</span>
+                                <div class="min-w-0">
+                                    <span class="text-[13px] font-medium text-gray-900">{{ $u->name }}</span>
+                                    @if($privilegeLabel !== null)
+                                        {{-- ⚠ 理由は title だけでなく画面の本文にも出す（tooltip はキーボード・読み上げに届かない。Bug #43） --}}
+                                        <span class="block text-[11px] text-gray-500">{{ $privilegeLabel }}に指定されています</span>
+                                    @endif
+                                </div>
+                            </div>
                         </td>
                         <td class="px-3.5 py-2.5 border-b border-gray-100 text-center whitespace-nowrap">
                             <span class="inline-block px-2 rounded text-[11px] font-medium {{ $u->isApprovalOnly() ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-600' }}" style="padding-top:2px; padding-bottom:2px;">{{ $u->isApprovalOnly() ? '決裁のみ' : '基幹も使う' }}</span>
@@ -221,6 +227,7 @@ class="px-5 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-md text-[13p
                                         'id'          => $u->id,
                                         'name'        => $u->name,
                                         'number'      => $u->employee_number ?? '',
+                                        'stamp'       => $u->approvalMember?->stamp_text ?? '',
                                         'email'       => $u->email ?? '',
                                         'departments' => $u->approvalDepartments->pluck('id')->map(fn ($id) => (string) $id)->values(),
                                         'editable'    => $manageable,
@@ -290,7 +297,7 @@ class="text-[12px] text-blue-600 hover:underline cursor-pointer bg-transparent b
                 <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">利用者の編集</div>
                 <div class="px-6 py-4 space-y-3.5">
                     <template x-if="!editEditable">
-                        <p class="rounded-md bg-amber-50 border border-amber-200 px-3 py-2 text-[12px] text-amber-800" x-text="editReason + ' 決裁の所属部門だけ変えられます。'"></p>
+                        <p class="rounded-md bg-amber-50 border border-amber-200 px-3 py-2 text-[12px] text-amber-800" x-text="editReason + ' 決裁の所属部門と印に使う文字だけ変えられます。'"></p>
                     </template>
 
                     <div>
@@ -307,6 +314,12 @@ class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] font
                                :class="editEditable ? '' : 'bg-gray-50 text-gray-500'">
                         <p class="text-[11px] text-gray-400 mt-1">英数字とハイフン。ログイン ID になります</p>
                     </div>
+                    <div>
+                        <label for="approval-edit-stamp" class="block text-[12px] font-semibold text-gray-700 mb-1">印に使う文字</label>
+                        <input type="text" id="approval-edit-stamp" name="stamp_text" x-model="editStamp" maxlength="4"
+                               class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
+                        <p class="text-[11px] text-gray-400 mt-1">4 文字まで。空なら氏名の最初の空白より前を使います（例: 山田 太郎 → 山田）。すでに押した印は変わりません</p>
+                    </div>
                     <div>
                         <label class="block text-[12px] font-semibold text-gray-700 mb-1">メールアドレス</label>
                         {{-- 送信しない（この画面からは変えられないので、入力欄そのものを置かない） --}}
@@ -397,6 +410,7 @@ function approvalUsers() {
         editUserId: null,
         editName: '',
         editNumber: '',
+        editStamp: '',
         editEmail: '',
         editDepartments: [],
         editEditable: false,
@@ -406,6 +420,7 @@ function approvalUsers() {
             this.editUserId = row.id;
             this.editName = row.name;
             this.editNumber = row.number;
+            this.editStamp = row.stamp;
             this.editEmail = row.email;
             this.editDepartments = row.departments.map(String);
             this.editEditable = row.editable;
```

（差分のファイルを使うなら: `… git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/4a/patches/0004-*.patch`）

- [ ] **Step 5: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (8 tests, …)`

- [ ] **Step 6: 全件を流す**

Expected: `OK (3362 tests, 23848 assertions)`

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Http/Controllers/Approval/UserController.php resources/views/approvals/admin/users/index.blade.php tests/Feature/Approval/Phase4/StampTextSettingTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 利用者の管理で印に使う文字を直せるようにする

利用者の編集の小窓に「印に使う文字」（4 文字まで・空なら氏名から）を足し、
所属部門と同じく決裁の管理者が全員について直せるようにする。変えたら設定の
記録に残し、一覧の氏名の左に印の見本を出す（段階4 設計書 §5.5・D9〜D11）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 5: PDF の部品・フォント・決裁申請書の紙面

部品 mPDF（`composer.lock` どおりに入れる）と同梱の IPAex フォントで、紙の決裁申請書に近い A4 縦の紙面を作る。紙面に載せる値は `PdfSheet` にまとめ（中身の出し分けは申請の詳細と同じ・判断は今の回だけ）、`ApprovalPdf::sheet()` が紙面と各ページの下を描いて PDF にする（設計書 §5.6・§0.4・D4・D5・D14〜D16）。

**Files:**
- Create: `app/Support/Approval/ApprovalPdf.php`・`app/Support/Approval/PdfSheet.php`・`resources/views/approvals/requests/pdf.blade.php`・`resources/views/approvals/requests/_pdf_footer.blade.php`・`resources/fonts/ipaex/`（ttf 2 本・ライセンスの文書・Readme 2 本）
- Modify: `composer.json`・`composer.lock`・`config/approval.php`・`.gitattributes`
- Test: Create `tests/Feature/Approval/Phase4/PdfSheetTest.php`・Modify `tests/Feature/ClockReadScanTest.php`・`tests/Feature/MobileLayoutTest.php`（PDF の紙面の 2 本を表の走査の対象外に。§0.7）

**Interfaces:**
- Consumes: Task 2 の `Stamp`・Task 3 の `StampSvg`・既存の `RequestContent::for()`・`ApprovalRequest::currentSteps()`・`statusLabel()`
- Produces: `PdfSheet::for(User $viewer, ApprovalRequest $request): PdfSheet`（`readonly` の項目は §0.4 の紙面の並びのとおり。`fileName` を含む）・`PdfSheet::bodyRows(?string $body): list<string>`・`PdfSheet::MIN_BODY_ROWS`（11）・`BODY_ROW_CHARS`（300）
- Produces: `ApprovalPdf::sheet(PdfSheet $sheet): string`（PDF のバイト列）・`ApprovalPdf::render(string $html, string $footerHtml, string $title): string`・`ApprovalPdf::FONT_GOTHIC`・`FONT_MINCHO`。Task 6 が使う
- Produces: 設定 `approval.pdf.font_dir`（`resource_path('fonts/ipaex')`）・`approval.pdf.temp_dir`（`storage_path('framework/cache/mpdf')`）

**差分の大きさ:** 16 ファイル・+1168 / −61 行（差分のファイル `0005-…`。⚠ フォントの本体 2 本は差分のファイルに入っていない＝Step 3 で置く）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase4/PdfSheetTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Support\Approval\ApprovalPdf;
use App\Support\Approval\PdfSheet;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 決裁申請書の PDF の紙面（要件 9.2・段階4 設計書 §5.6・D4・D14〜D17） */
class PdfSheetTest extends TestCase
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

    /** 部門長・審査を通して社長の決裁待ちまで */
    private function toPresident(array $w, array $attributes = []): ApprovalRequest
    {
        $r = $this->submittedFor($w, $attributes);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, '急ぎでお願いします');
        $this->workflow->judgeReview($r->fresh(), $w['reviewer'], $r->fresh()->lock_version, ApprovalStepResult::Hold, '見積を 2 社取ってください');

        return $r->fresh();
    }

    public function test_a_decided_request_has_the_number_the_marks_and_the_three_stamps(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Approve, null);

        $sheet = PdfSheet::for($w['applicant'], $r->fresh());

        $this->assertSame('決裁済み（可）', $sheet->statusLabel);
        $this->assertSame(1, $sheet->round);
        $this->assertSame('R8-J-001', $sheet->number);
        $this->assertSame(['2026年10月5日', '2026年10月5日', '2026年10月5日'], [$sheet->decidedOn, $sheet->submittedOn, $sheet->receivedOn], '日本時間の日付');
        $this->assertSame(['可', '保留'], [$sheet->decisionMark, $sheet->reviewMark]);
        $this->assertSame(['社長', '住宅', '総務'], [$sheet->presidentStamp?->label, $sheet->headStamp?->label, $sheet->reviewStamp?->label]);
        $this->assertSame(['急ぎでお願いします', '見積を 2 社取ってください'], [$sheet->headComment, $sheet->reviewComment]);
        $this->assertSame(['住宅事業部', '申請 花子', '総務部'], [$sheet->departmentName, $sheet->applicantName, $sheet->reviewDepartmentName]);
        $this->assertSame(['社用車の購入', '2,850,000円', '2026年10月'], [$sheet->subject, $sheet->amountLabel, $sheet->schedule]);
        $this->assertSame(['2026/10/05 01:12', '申請 花子'], [$sheet->outputAt, $sheet->outputBy], '出力の日時は日本時間・出力者は開いた人');
        $this->assertSame('決裁申請書_R8-J-001.pdf', $sheet->fileName);
        $this->assertFalse($sheet->headSkipped);
    }

    public function test_the_issue_date_and_the_receipt_date_come_from_different_moments(): void
    {
        $w = $this->approvalWorld();
        Carbon::setTestNow(Carbon::parse('2026-10-02 23:00:00', 'UTC'));   // 日本時間 10/3 8:00 に提出
        $r = $this->submittedFor($w);
        Carbon::setTestNow(Carbon::parse('2026-10-04 15:30:00', 'UTC'));   // 日本時間 10/5 0:30 に部門長が承認＝審査部門に届く
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);

        $sheet = PdfSheet::for($w['applicant'], $r->fresh());

        $this->assertSame(['2026年10月3日', '2026年10月5日'], [$sheet->submittedOn, $sheet->receivedOn], '発信日は提出・受付日は審査部門に届いた日（日本時間）');
    }

    public function test_before_the_decision_the_number_date_and_marks_are_blank(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $sheet = PdfSheet::for($w['applicant'], $r);

        $this->assertSame('部門長確認中', $sheet->statusLabel);
        $this->assertSame([null, null, null, null], [$sheet->number, $sheet->decidedOn, $sheet->receivedOn, $sheet->decisionMark]);
        $this->assertSame([null, null, null], [$sheet->presidentStamp, $sheet->headStamp, $sheet->reviewStamp]);
        $this->assertSame('決裁申請書_申請' . $r->id . '.pdf', $sheet->fileName, '番号の前は申請の番号');
    }

    public function test_a_conditional_approval_shows_the_condition_and_when_it_was_confirmed(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Conditional, '月次で報告すること');

        $before = PdfSheet::for($w['applicant'], $r->fresh());
        $this->assertSame(['条可', '月次で報告すること', null], [$before->decisionMark, $before->presidentComment, $before->conditionConfirmedAt]);

        Carbon::setTestNow(Carbon::parse('2026-10-05 02:30:00', 'UTC'));
        $this->workflow->confirmCondition($r->fresh(), $w['applicant'], $r->fresh()->lock_version, null);

        $this->assertSame('2026/10/05 11:30', PdfSheet::for($w['applicant'], $r->fresh())->conditionConfirmedAt);
    }

    public function test_only_the_current_round_is_shown_after_a_return(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Return, '金額を見直してください');

        $returned = PdfSheet::for($w['reviewer'], $r->fresh());
        $this->assertSame(['差戻し中', '差戻', '金額を見直してください'], [$returned->statusLabel, $returned->decisionMark, $returned->presidentComment], '差戻しの回の社長の判断と印');
        $this->assertNotNull($returned->presidentStamp);

        $this->workflow->submit($r->fresh(), $w['applicant']);
        $second = PdfSheet::for($w['applicant'], $r->fresh());

        $this->assertSame(2, $second->round);
        $this->assertSame([null, null, null, null], [$second->decisionMark, $second->presidentStamp, $second->headStamp, $second->headComment], '前の回の判断と印は載せない');
    }

    public function test_others_see_the_last_submitted_content_while_the_applicant_edits(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w, ['subject' => '提出した件名']);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Return, '直してください');
        $r->fresh()->update(['subject' => '直しかけの件名']);

        $this->assertSame('提出した件名', PdfSheet::for($w['head'], $r->fresh())->subject);
        $this->assertSame('直しかけの件名', PdfSheet::for($w['applicant'], $r->fresh())->subject);
    }

    public function test_a_skipped_head_step_is_marked_as_skipped(): void
    {
        $w = $this->approvalWorld();
        $w['head']->approvalDepartments()->attach($w['dept']->id);
        $r = $this->submittedFor(array_merge($w, ['applicant' => $w['head']->fresh()]));

        $sheet = PdfSheet::for($w['head'], $r);

        $this->assertTrue($sheet->headSkipped);
        $this->assertNull($sheet->headStamp);
    }

    public function test_the_body_is_split_into_ruled_rows(): void
    {
        $this->assertSame(array_pad(['■ なぜ', '・老朽化のため'], PdfSheet::MIN_BODY_ROWS, ''), PdfSheet::bodyRows("■ なぜ\r\n・老朽化のため\n\n"), '改行で分け、末尾の空行は落とし、紙の行数まで空の行で埋める');
        $this->assertSame(array_fill(0, PdfSheet::MIN_BODY_ROWS, ''), PdfSheet::bodyRows(null));

        $long = PdfSheet::bodyRows(str_repeat('あ', PdfSheet::BODY_ROW_CHARS * 2 + 5));
        $this->assertSame([PdfSheet::BODY_ROW_CHARS, PdfSheet::BODY_ROW_CHARS, 5], array_map('mb_strlen', array_slice($long, 0, 3)), '長い行は分ける（表の 1 行がページより高くならないように）');

        $many = PdfSheet::bodyRows(implode("\n", range(1, 30)));
        $this->assertCount(30, $many, '紙の行数より多ければそのまま');
        $this->assertCount(12, PdfSheet::bodyRows(implode("\n", range(1, 12)) . "\n\n\n"), '末尾の空行は罫線にしない');
    }

    public function test_the_pdf_is_made_with_the_bundled_fonts_and_flows_onto_more_pages(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w, ['body' => str_repeat("・決裁申請システムの本文の試しです。\n", 120)]);

        $pdf = ApprovalPdf::sheet(PdfSheet::for($w['applicant'], $r));

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('IPAexGothic', $pdf, 'ゴシックを埋め込む');
        $this->assertStringContainsString('IPAexMincho', $pdf, '明朝を埋め込む（題・枠・印）');
        $this->assertGreaterThan(1, preg_match_all('#/Type /Page\b#', $pdf), '本文が長ければ次のページへ続く（縮めない）');
    }

    public function test_the_sheet_draws_the_marks_the_stamps_and_the_footer(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Conditional, '月次で報告すること');
        $sheet = PdfSheet::for($w['applicant'], $r->fresh());

        $html = view('approvals.requests.pdf', ['sheet' => $sheet])->render();

        // 判断したものだけ朱の枠（決裁は条可・審査は保留）
        $this->assertStringContainsString('<td class="mark on">条可</td>', $html);
        $this->assertStringContainsString('<td class="mark">可</td>', $html);
        $this->assertStringContainsString('<td class="mark on">保留</td>', $html);
        $this->assertSame(2, substr_count($html, 'class="mark on"'));
        // 3 つの印と、条可の条件
        foreach (['社長 R8.10.5 社長 の印', '住宅 R8.10.5 部門 の印', '総務 R8.10.5 審査 の印'] as $aria) {
            $this->assertStringContainsString('aria-label="' . $aria . '"', $html);
        }
        $this->assertStringContainsString('決裁者コメント（条件）', $html);
        $this->assertStringContainsString('月次で報告すること', $html);
        $this->assertSame(PdfSheet::MIN_BODY_ROWS, substr_count($html, '<td class="line">'), '本文は罫線の行ごと');

        $footer = view('approvals.requests._pdf_footer', ['sheet' => $sheet])->render();
        $this->assertStringContainsString('決裁申請システムから出力 2026/10/05 01:12（出力者: 申請 花子）', $footer);
        $this->assertStringContainsString('{PAGENO} / {nbpg}', $footer);
    }

    public function test_the_sheet_says_the_head_step_was_skipped(): void
    {
        $w = $this->approvalWorld();
        $w['head']->approvalDepartments()->attach($w['dept']->id);
        $r = $this->submittedFor(array_merge($w, ['applicant' => $w['head']->fresh()]));

        $html = view('approvals.requests.pdf', ['sheet' => PdfSheet::for($w['head'], $r)])->render();

        $this->assertStringContainsString('申請者が部門長のため省略', $html);
        $this->assertStringNotContainsString('aria-label=', $html, '印はまだ無い');
    }

    public function test_the_sheet_escapes_what_people_typed(): void
    {
        $w = $this->approvalWorld();
        $w['head']->update(['name' => '<b>部門</b>長']);
        $r = $this->toPresident($w, ['subject' => '<img src=x onerror=alert(1)>', 'body' => "<script>alert(2)</script>"]);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Return, "<i>見直し</i>\n2 行目");

        $html = view('approvals.requests.pdf', ['sheet' => PdfSheet::for($w['applicant'], $r->fresh())])->render();

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<script>alert(2)', $html);
        $this->assertStringNotContainsString('<b>部門</b>', $html);
        $this->assertStringNotContainsString('<i>見直し</i>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('&lt;i&gt;見直し&lt;/i&gt;<br />', $html, 'コメントはエスケープしてから改行を <br> にする');
    }
}
```

`tests/Feature/ClockReadScanTest.php`（変更）

```diff
--- a/tests/Feature/ClockReadScanTest.php
+++ b/tests/Feature/ClockReadScanTest.php
@@ -59,6 +59,7 @@ class ClockReadScanTest extends TestCase
         'app/Models/ApprovalNotice.php'                       => [1, 'notifications.read_at は TIMESTAMP 列（既読にした瞬間を UTC で保存する）'],
         'app/Support/Approval/MailDelivery.php'               => [2, '決裁のメールが送れた・送れなかった瞬間（approval_settings の TIMESTAMP 列。帯では JapanTime で日本時間に直して出す）'],
         'app/Support/Approval/ReminderCalendar.php'           => [1, '今の瞬間が日本時間の 9:05（催促を送る時刻の終わり）より前か（瞬間どうしを比べる。日付は JapanTime::today()）'],
+        'app/Support/Approval/PdfSheet.php'                   => [1, '決裁申請書の PDF を出力した瞬間（各ページの下に JapanTime で日本時間に直して出す）'],
     ];
 
     /** @return list<array{int, string}> [行, 呼び出し] */
```

`MobileLayoutTest` は、Step 6 で足す紙面の 2 本（mPDF に渡す HTML。画面に出さない）の表を「横スクロールの祖先が無い」と数えないよう、対象外の一覧に理由つきで載せる（§0.7。この段ではまだ紙面が無いので、Step 2 では緑のまま）:

`tests/Feature/MobileLayoutTest.php`（変更）

```diff
--- a/tests/Feature/MobileLayoutTest.php
+++ b/tests/Feature/MobileLayoutTest.php
@@ -44,6 +44,16 @@ class MobileLayoutTest extends TestCase
      */
     private const SCROLLABLE_ANCESTOR = '/scroll-hint-inner|scroll-area|overflow-x:\s*auto|overflow-x-auto|overflow-y:\s*auto|overflow:\s*auto/';
 
+    /**
+     * 横スクロールの祖先が無くてよい <table> を持つファイル。
+     * 追加するときは「なぜ 375px の画面で崩れないか」を必ず書くこと。
+     */
+    private const TABLE_SCROLL_EXEMPT = [
+        // mPDF に渡す A4 の紙面。ブラウザの画面には出さない（段階4a の PDF）
+        'approvals/requests/pdf.blade.php'         => 'mPDF に渡す紙面で画面に出さない',
+        'approvals/requests/_pdf_footer.blade.php' => 'mPDF に渡す紙面で画面に出さない',
+    ];
+
     /**
      * インラインの多列グリッドのうち、モバイル用クラスが無くてよいもの。
      * 追加するときは「なぜ 375px で壊れないか」を必ず書くこと。
@@ -116,6 +126,10 @@ public function test_every_table_has_a_horizontally_scrollable_ancestor(): void
                 continue;
             }
 
+            if (isset(self::TABLE_SCROLL_EXEMPT[$this->relative($path)])) {
+                continue;
+            }
+
             $stack = [];
             preg_match_all('/<(\/?)(div|table)\b([^>]*)>/i', $src, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
```

（差分のファイルを使うなら: `… git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/4a/patches/0005-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase4/PdfSheetTest.php tests/Feature/ClockReadScanTest.php tests/Feature/MobileLayoutTest.php
```

Expected: `ERRORS!` `Tests: 23, Assertions: 83, Errors: 12, Failures: 1.`

- `PdfSheetTest::test_a_decided_request_has_the_number_the_marks_and_the_three_stamps` — `Error: Class "App\Support\Approval\PdfSheet" not found`
- `PdfSheetTest::test_the_issue_date_and_the_receipt_date_come_from_different_moments` — `Error: Class "App\Support\Approval\PdfSheet" not found`
- `PdfSheetTest::test_before_the_decision_the_number_date_and_marks_are_blank` — `Error: Class "App\Support\Approval\PdfSheet" not found`
- `PdfSheetTest::test_a_conditional_approval_shows_the_condition_and_when_it_was_confirmed` — `Error: Class "App\Support\Approval\PdfSheet" not found`
- `PdfSheetTest::test_only_the_current_round_is_shown_after_a_return` — `Error: Class "App\Support\Approval\PdfSheet" not found`
- `PdfSheetTest::test_others_see_the_last_submitted_content_while_the_applicant_edits` — `Error: Class "App\Support\Approval\PdfSheet" not found`
- `PdfSheetTest::test_a_skipped_head_step_is_marked_as_skipped` — `Error: Class "App\Support\Approval\PdfSheet" not found`
- `PdfSheetTest::test_the_body_is_split_into_ruled_rows` — `Error: Class "App\Support\Approval\PdfSheet" not found`
- `PdfSheetTest::test_the_pdf_is_made_with_the_bundled_fonts_and_flows_onto_more_pages` — `Error: Class "App\Support\Approval\ApprovalPdf" not found`
- `PdfSheetTest::test_the_sheet_draws_the_marks_the_stamps_and_the_footer` — `Error: Class "App\Support\Approval\PdfSheet" not found`
- `PdfSheetTest::test_the_sheet_says_the_head_step_was_skipped` — `Error: Class "App\Support\Approval\PdfSheet" not found`
- `PdfSheetTest::test_the_sheet_escapes_what_people_typed` — `Error: Class "App\Support\Approval\PdfSheet" not found`
- `ClockReadScanTest::test_php_clock_reads_are_classified` — `時計の読み取りが分類と合わない:`

- [ ] **Step 3: 部品とフォントを入れる**

`composer.json` に 1 行（`composer.lock` は mPDF と付属の 4 つが増え、開発用だった `myclabs/deep-copy` が本番用の側へ移り、content-hash が変わる。差分は長いので差分のファイルから当てる）:

`composer.json`（変更）

```diff
--- a/composer.json
+++ b/composer.json
@@ -12,6 +12,7 @@
         "chillerlan/php-qrcode": "^6.0",
         "laravel/framework": "^12.0",
         "laravel/tinker": "^2.10.1",
+        "mpdf/mpdf": "^8.3",
         "phpoffice/phpspreadsheet": "^5.9"
     },
     "require-dev": {
```

`.gitattributes`（ライセンスの文書を配布元のまま保存する。§0.4）:

`.gitattributes`（変更）

```diff
--- a/.gitattributes
+++ b/.gitattributes
@@ -9,3 +9,6 @@
 /.github export-ignore
 CHANGELOG.md export-ignore
 .styleci.yml export-ignore
+
+# 同梱のフォントとライセンスの文書は配布元のまま保存する（改行を書き換えない。段階4a）
+/resources/fonts/** -text
```

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/4a/patches/0005-*.patch && pwd && composer install --no-interaction 2>&1 | tail -3 && ls vendor/mpdf/mpdf/src/Mpdf.php
```

Expected: `pwd` が WT・`Installing mpdf/mpdf (v8.3.1)` などが出て最後に `Generating optimized autoload files` 以降の出力・`vendor/mpdf/mpdf/src/Mpdf.php`。⚠ **`composer require`・`composer update` は打たない**（lock どおりに入れるだけ）。

フォントの本体を、保存してある配布元の zip から置く（ライセンスの文書と Readme は差分のファイルで入っている）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && F=~/.claude/plans/approval-phase3-tasks/4a/fonts && (cd "$F" && shasum -a 256 -c SHA256SUMS) && T=$(mktemp -d) && unzip -q "$F/ipaexg00401.zip" -d "$T" && unzip -q "$F/ipaexm00401.zip" -d "$T" && cp "$T/ipaexg00401/ipaexg.ttf" "$T/ipaexm00401/ipaexm.ttf" resources/fonts/ipaex/ && chmod 644 resources/fonts/ipaex/*.ttf && shasum -a 256 resources/fonts/ipaex/*.ttf
```

Expected: zip が 2 つとも `OK`・ttf の SHA-256 が次の 2 行と同じ:

- `resources/fonts/ipaex/ipaexg.ttf`（新規・中身は示さない）: 6,099,900 バイト・SHA-256 `3b9955a5e437ffb24c41548e79296fa724822da21ee75dc1b94f6ccdb8f400dd`
- `resources/fonts/ipaex/ipaexm.ttf`（新規・中身は示さない）: 7,835,672 バイト・SHA-256 `7a306386f930fee80922f71eebf4ffe0f1ff2817da8e619230953487673d71c7`

差分のファイルで入る文書（中身は配布元のまま。BOM 付き・CRLF）:

- `resources/fonts/ipaex/IPA_Font_License_Agreement_v1.0.txt`（新規・中身は示さない）: 20,564 バイト・SHA-256 `4c84dd528ec3044638ec346fc1ee27cd1eb95dfc04cbc6a881b3ca7a7f517e54`
- `resources/fonts/ipaex/Readme_ipaexg00401.txt`（新規・中身は示さない）: 1,710 バイト・SHA-256 `567d253f2adb5bc6424fd2592550d225a39f919131e4a0cc0caa4d788e259a60`
- `resources/fonts/ipaex/Readme_ipaexm00401.txt`（新規・中身は示さない）: 1,693 バイト・SHA-256 `783ec2703d860575f5eab15bb2b3168da2a8cca76505a1b4fce3cc1ce58694b3`

- `composer.lock`（変更・中身は示さない）: 358,320 バイト・SHA-256 `47804d269d3060d2567309f369865859eb05a13b086e99952d1a4e0d9bd41d02`（mPDF と付属の 4 つが増え、`myclabs/deep-copy` が本番用の側へ移り、content-hash が変わる。差分のファイルで入る）

- [ ] **Step 4: PDF の設定と、PDF を作る部品**

`config/approval.php`（変更）

```diff
--- a/config/approval.php
+++ b/config/approval.php
@@ -46,4 +46,23 @@
 
     'mail_link_root' => env('APPROVAL_MAIL_LINK_ROOT', rtrim((string) env('APP_URL', 'http://localhost'), '/') . '/index.php'),
 
+    /*
+    |--------------------------------------------------------------------------
+    | 決裁申請書の PDF（段階4 設計書 §5.6・D5）
+    |--------------------------------------------------------------------------
+    |
+    | 部品は mPDF（使用条件は GPL-2.0。社外へ配らない社内のシステムなので使う。利用者の決定 2026-10-03）。
+    | フォントは IPAex ゴシック・明朝（resources/fonts/ipaex。IPA フォントのライセンスの文書を一緒に置く）。
+    | 一時ファイル（フォントの下ごしらえの控え）は storage の中に置く（vendor の中は本番で書けるとは限らない）。
+    |
+    | 実測（2026-10-03・試し）: 本文 20,000 文字・添付 20 件・コメント 2,000 文字で 12 ページ・0.5 秒・メモリ 30MB
+    | （Laravel を除く）。本番の PHP のメモリの上限は 128M（2026-10-03 に読み取った）。
+    |
+    */
+
+    'pdf' => [
+        'font_dir' => resource_path('fonts/ipaex'),
+        'temp_dir' => storage_path('framework/cache/mpdf'),
+    ],
+
 ];
```

`app/Support/Approval/ApprovalPdf.php`（新規）

```php
<?php

namespace App\Support\Approval;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * 決裁申請書の HTML から PDF を作る（mPDF。段階4 設計書 §5.6・D5・D16）。
 *
 * ⚠ 本文は表の行を 1 行ずつに分けて渡す（`PdfSheet::bodyRows()`）。mPDF は表の 1 つの行をページの途中で切れず、
 *   1 つの枠に長い本文を入れると、ページに収めようとして文字を縮めてしまう（2026-10-03 の試しで 444 行が 1 ページになった）。
 * ⚠ 書体の名前（ipaexg・ipaexm）は紙面の CSS と `StampSvg::PDF_FONT` が使う。
 */
final class ApprovalPdf
{
    public const FONT_GOTHIC = 'ipaexg';

    public const FONT_MINCHO = 'ipaexm';

    /** 決裁申請書の PDF（紙面 approvals.requests.pdf と各ページの下 approvals.requests._pdf_footer）。@return string PDF のバイト列 */
    public static function sheet(PdfSheet $sheet): string
    {
        return self::render(
            view('approvals.requests.pdf', ['sheet' => $sheet])->render(),
            view('approvals.requests._pdf_footer', ['sheet' => $sheet])->render(),
            basename($sheet->fileName, '.pdf'),
        );
    }

    /** @return string PDF のバイト列 */
    public static function render(string $html, string $footerHtml, string $title): string
    {
        $defaults = (new ConfigVariables())->getDefaults();
        $fonts    = (new FontVariables())->getDefaults();

        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'tempDir'       => config('approval.pdf.temp_dir'),
            'fontDir'       => array_merge($defaults['fontDir'], [config('approval.pdf.font_dir')]),
            'fontdata'      => $fonts['fontdata'] + [
                self::FONT_GOTHIC => ['R' => 'ipaexg.ttf'],
                self::FONT_MINCHO => ['R' => 'ipaexm.ttf'],
            ],
            'default_font'  => self::FONT_GOTHIC,
            'margin_left'   => 12,
            'margin_right'  => 12,
            'margin_top'    => 12,
            'margin_bottom' => 16,
            'margin_footer' => 6,
        ]);

        $mpdf->SetTitle($title);
        $mpdf->SetCreator('決裁申請システム');
        $mpdf->SetHTMLFooter($footerHtml);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
```

- [ ] **Step 5: 紙面に載せる値**

`app/Support/Approval/PdfSheet.php`（新規）

```php
<?php

namespace App\Support\Approval;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\JapanTime;
use Illuminate\Support\Collection;

/**
 * 決裁申請書の PDF の紙面に載せるもの（要件 9.2・段階4 設計書 §5.6・D4・D14〜D17）。
 *
 * - 中身は申請の詳細と同じ（`RequestContent::for`。申請者以外には最後に提出した控え）
 * - 判断と印は**今の回だけ**（D4）。前の回のやり取りは画面の履歴で見る
 * - 決裁の前は決裁No・決裁日・判断の○が空欄
 *
 * ⚠ 下書きは出さない（D17）。呼ぶ側（`RequestPdfController`）が先に断る。
 */
final class PdfSheet
{
    /** 紙の本文の罫線の行数（短い本文でも紙の様式と同じ高さにする） */
    public const MIN_BODY_ROWS = 11;

    /** 本文の 1 行に入れる文字数の上限（mPDF は表の 1 行をページの途中で切れないので、長い行は分ける） */
    public const BODY_ROW_CHARS = 300;

    /** 社長の判断 → 紙の決裁の欄の言葉 */
    private const DECISION_MARKS = ['approve' => '可', 'conditional' => '条可', 'return' => '差戻', 'reject' => '否'];

    /** 審査の意見 → 紙の審査部門の欄の言葉 */
    private const REVIEW_MARKS = ['ok' => '可', 'hold' => '保留', 'ng' => '否'];

    /**
     * @param list<string> $relatedNumbers
     * @param list<string> $bodyRows
     * @param list<string> $attachmentNames
     */
    private function __construct(
        public readonly string $statusLabel,
        public readonly int $round,
        public readonly ?string $number,
        public readonly ?string $decidedOn,
        public readonly ?string $submittedOn,
        public readonly ?string $receivedOn,
        public readonly ?string $decisionMark,
        public readonly ?Stamp $presidentStamp,
        public readonly ?string $presidentComment,
        public readonly ?string $conditionConfirmedAt,
        public readonly ?string $departmentName,
        public readonly ?string $applicantName,
        public readonly ?Stamp $headStamp,
        public readonly ?string $headComment,
        public readonly bool $headSkipped,
        public readonly ?string $subject,
        public readonly ?string $amountLabel,
        public readonly ?string $schedule,
        public readonly array $relatedNumbers,
        public readonly array $bodyRows,
        public readonly array $attachmentNames,
        public readonly ?string $reviewDepartmentName,
        public readonly ?string $reviewMark,
        public readonly ?Stamp $reviewStamp,
        public readonly ?string $reviewComment,
        public readonly string $outputAt,
        public readonly string $outputBy,
        public readonly string $fileName,
    ) {
    }

    public static function for(User $viewer, ApprovalRequest $request): self
    {
        $content = RequestContent::for($viewer, $request);
        /** @var Collection<int, ApprovalStep> $steps */
        $steps     = $request->currentSteps()->keyBy(fn (ApprovalStep $s) => $s->kind->value);
        $head      = $steps->get(ApprovalStepKind::Head->value);
        $review    = $steps->get(ApprovalStepKind::Review->value);
        $president = $steps->get(ApprovalStepKind::President->value);

        return new self(
            statusLabel: $request->statusLabel(),
            round: $request->round,
            number: $request->number,
            decidedOn: JapanTime::format($request->decided_at, 'Y年n月j日'),
            submittedOn: JapanTime::format($request->last_submitted_at, 'Y年n月j日'),
            receivedOn: JapanTime::format($review?->arrived_at, 'Y年n月j日'),
            decisionMark: self::mark($president, self::DECISION_MARKS),
            presidentStamp: $president ? Stamp::forStep($president) : null,
            presidentComment: self::doneComment($president),
            conditionConfirmedAt: $request->decision === ApprovalDecision::Conditional && $request->status === ApprovalStatus::Approved
                ? JapanTime::format($request->finished_at) : null,
            departmentName: $content->departmentName,
            applicantName: $request->applicant?->name,
            headStamp: $head ? Stamp::forStep($head) : null,
            headComment: self::doneComment($head),
            headSkipped: $head?->status === ApprovalStepStatus::Skipped,
            subject: $content->subject,
            amountLabel: $content->amountLabel(),
            schedule: $content->schedule,
            relatedNumbers: array_values($content->relatedNumbers),
            bodyRows: self::bodyRows($content->body),
            attachmentNames: $content->attachments->pluck('original_name')->values()->all(),
            reviewDepartmentName: $review?->department?->name,
            reviewMark: self::mark($review, self::REVIEW_MARKS),
            reviewStamp: $review ? Stamp::forStep($review) : null,
            reviewComment: self::doneComment($review),
            outputAt: JapanTime::format(now()),
            outputBy: $viewer->name,
            fileName: '決裁申請書_' . ($request->number ?? '申請' . $request->id) . '.pdf',
        );
    }

    /**
     * 本文を罫線の行に分ける。改行で分け、長い行は BODY_ROW_CHARS 文字ずつに分け、MIN_BODY_ROWS 行に満たなければ空の行で埋める。
     *
     * @return list<string>
     */
    public static function bodyRows(?string $body): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', rtrim((string) $body)) as $line) {
            foreach ($line === '' ? [''] : mb_str_split($line, self::BODY_ROW_CHARS) as $chunk) {
                $rows[] = $chunk;
            }
        }

        if ($rows === ['']) {
            $rows = [];
        }

        return array_pad($rows, self::MIN_BODY_ROWS, '');
    }

    /** @param array<string, string> $marks */
    private static function mark(?ApprovalStep $step, array $marks): ?string
    {
        if ($step?->status !== ApprovalStepStatus::Done || ! $step->result instanceof ApprovalStepResult) {
            return null;
        }

        return $marks[$step->result->value] ?? null;
    }

    private static function doneComment(?ApprovalStep $step): ?string
    {
        return $step?->status === ApprovalStepStatus::Done ? $step->comment : null;
    }
}
```

- [ ] **Step 6: 紙面と各ページの下**

`resources/views/approvals/requests/pdf.blade.php`（新規）

```blade
{{-- 決裁申請書の PDF の紙面（mPDF に渡す HTML。要件 9.2・段階4 設計書 §5.6・D14〜D16）。
     紙の様式（決裁申請書.xls）の枠を使い、使わない欄（決裁区分・項目番号・関連部門）は外す。
     ⚠ mPDF は CSS の一部しか読まない（flex・grid・CSS の変数は使えない）。枠は表で組む。
     ⚠ 本文は罫線の 1 行を表の 1 行にする（mPDF は表の 1 行をページの途中で切れない。PdfSheet::bodyRows()）。
     ⚠ 文字はすべて {{ }} で包む（印は StampSvg が e() で包んだ SVG を返す）。 --}}
@php
    // 紙の「可・条可・差戻・否」「可・保留・否」の欄。判断したものだけ朱の枠にする
    // ⚠ 文中の <span> の枠は mPDF が描き損なうことがある（2026-10-03 の試しで一部の枠が消えた）ので、小さな表で組む
    $mark = fn (?string $current, string $label): string => $current === $label ? 'mark on' : 'mark';
@endphp
<style>
    body { font-family: {{ \App\Support\Approval\ApprovalPdf::FONT_GOTHIC }}; font-size: 10pt; color: #000; }
    .mincho { font-family: {{ \App\Support\Approval\ApprovalPdf::FONT_MINCHO }}; }
    .title { font-family: {{ \App\Support\Approval\ApprovalPdf::FONT_MINCHO }}; font-size: 20pt; text-align: center; letter-spacing: 8pt; }
    .status { text-align: center; font-size: 9pt; color: #444; margin-bottom: 3mm; }
    table { border-collapse: collapse; width: 100%; }
    td { border: 0.6pt solid #000; padding: 1.5mm 2mm; vertical-align: top; }
    td.label { font-family: {{ \App\Support\Approval\ApprovalPdf::FONT_MINCHO }}; background-color: #f2f2f2; text-align: center; vertical-align: middle; }
    .gap { height: 3mm; }
    table.marks { width: auto; border-collapse: separate; border-spacing: 1mm 0; }
    td.mark { border: 0.6pt solid #000; padding: 0.3mm 1.5mm; text-align: center; vertical-align: middle; }
    .on { border: 1.2pt solid {{ \App\Support\Approval\StampSvg::COLOR }}; color: {{ \App\Support\Approval\StampSvg::COLOR }}; }
    .small { font-size: 8.5pt; }
    .note { font-size: 8.5pt; color: #444; }
    td.line { border-top: none; border-bottom: 0.4pt dashed #999; height: 6mm; }
</style>

<div class="title">決裁申請書</div>
<div class="status">状態: {{ $sheet->statusLabel }} ・ {{ $sheet->round }} 回目の提出</div>

<table>
    <tr>
        <td class="label" style="width: 18mm;">決裁No</td>
        <td>{{ $sheet->number ?? '' }}</td>
        <td class="label" style="width: 18mm;">決裁日</td>
        <td>{{ $sheet->decidedOn ?? '' }}</td>
        <td class="label" style="width: 18mm;">発信日</td>
        <td>{{ $sheet->submittedOn ?? '' }}</td>
        <td class="label" style="width: 18mm;">受付日</td>
        <td>{{ $sheet->receivedOn ?? '' }}</td>
    </tr>
</table>
<div class="gap"></div>

<table>
    <tr>
        <td style="width: 45%;">
            <table class="marks">
                <tr>
                    <td style="border: none; padding: 0 1mm 0 0;" class="mincho">決裁</td>
                    @foreach(['可', '条可', '差戻', '否'] as $label)
                        <td class="{{ $mark($sheet->decisionMark, $label) }}">{{ $label }}</td>
                    @endforeach
                </tr>
            </table>
            <div class="mincho" style="margin-top: 2mm;">決裁者コメント{{ $sheet->decisionMark === '条可' ? '（条件）' : '' }}</div>
            <div>{!! nl2br(e($sheet->presidentComment ?? '')) !!}</div>
            @if($sheet->conditionConfirmedAt)
                <div class="note">条件確認: {{ $sheet->conditionConfirmedAt }}</div>
            @endif
            @if($sheet->presidentStamp)
                <div>{!! \App\Support\Approval\StampSvg::render($sheet->presidentStamp, \App\Support\Approval\StampSvg::PDF_FONT, '80') !!}</div>
            @endif
        </td>
        <td style="width: 35%;">
            <div class="mincho">申請部門</div>
            <div>{{ $sheet->departmentName ?? '' }}</div>
            <div class="mincho" style="margin-top: 2mm;">申請者名</div>
            <div>{{ $sheet->applicantName ?? '' }}</div>
        </td>
        <td style="width: 20%; text-align: center;">
            <div class="mincho">承認</div>
            @if($sheet->headSkipped)
                <div class="small" style="margin-top: 4mm;">申請者が部門長のため省略</div>
            @elseif($sheet->headStamp)
                <div>{!! \App\Support\Approval\StampSvg::render($sheet->headStamp, \App\Support\Approval\StampSvg::PDF_FONT, '80') !!}</div>
            @endif
            @if($sheet->headComment)
                <div class="small" style="text-align: left;">{!! nl2br(e($sheet->headComment)) !!}</div>
            @endif
        </td>
    </tr>
</table>
<div class="gap"></div>

<table>
    <tr>
        <td class="label" style="width: 18mm;">件名</td>
        <td>{{ $sheet->subject ?? '' }}</td>
    </tr>
    <tr>
        <td colspan="2">
            金額 {{ $sheet->amountLabel ?? '—' }}（税抜）&nbsp;&nbsp;
            実施時期 {{ $sheet->schedule ?? '—' }}&nbsp;&nbsp;
            関連する決裁No {{ $sheet->relatedNumbers === [] ? '—' : implode('・', $sheet->relatedNumbers) }}
            <div class="mincho" style="margin-top: 2mm;">重点ポイント箇条書（5W2H）</div>
        </td>
    </tr>
</table>
<table>
    @foreach($sheet->bodyRows as $row)
        <tr><td class="line">{{ $row }}</td></tr>
    @endforeach
</table>
<table>
    <tr>
        <td style="border-top: none; text-align: right;" class="small">
            （添付ファイル {{ count($sheet->attachmentNames) }} 件{{ $sheet->attachmentNames === [] ? '' : ': ' . implode(' ／ ', $sheet->attachmentNames) }}）
        </td>
    </tr>
</table>
<div class="gap"></div>

<table>
    <tr>
        <td class="label" style="width: 22mm;">審査部門</td>
        <td style="width: 60%;">
            <div class="note">{{ $sheet->reviewDepartmentName ?? '' }}</div>
            <div>{!! nl2br(e($sheet->reviewComment ?? '')) !!}</div>
        </td>
        <td style="text-align: center;">
            <table class="marks" style="margin: 0 auto;">
                <tr>
                    @foreach(['可', '保留', '否'] as $label)
                        <td class="{{ $mark($sheet->reviewMark, $label) }}">{{ $label }}</td>
                    @endforeach
                </tr>
            </table>
            @if($sheet->reviewStamp)
                <div>{!! \App\Support\Approval\StampSvg::render($sheet->reviewStamp, \App\Support\Approval\StampSvg::PDF_FONT, '80') !!}</div>
            @endif
        </td>
    </tr>
</table>
```

`resources/views/approvals/requests/_pdf_footer.blade.php`（新規）

```blade
{{-- 決裁申請書の PDF の各ページの下（要件 9.2）。{PAGENO}・{nbpg} は mPDF がページ番号に置き換える --}}
<table style="width: 100%; border-collapse: collapse;">
    <tr>
        <td style="border: none; font-size: 8pt; color: #444;">決裁申請システムから出力 {{ $sheet->outputAt }}（出力者: {{ $sheet->outputBy }}）</td>
        <td style="border: none; font-size: 8pt; color: #444; text-align: right;">{PAGENO} / {nbpg}</td>
    </tr>
</table>
```

- [ ] **Step 7: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (23 tests, …)`

- [ ] **Step 8: 全件を流す**

Expected: `OK (3374 tests, 23912 assertions)`。`git status --porcelain` に `storage/framework/cache/mpdf` が出ないこと（`storage/framework/cache/.gitignore` が外す）。

- [ ] **Step 9: コミット**（`vendor/` は git に入らない）

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add composer.json composer.lock .gitattributes config/approval.php app/Support/Approval/ApprovalPdf.php app/Support/Approval/PdfSheet.php resources/views/approvals/requests/pdf.blade.php resources/views/approvals/requests/_pdf_footer.blade.php resources/fonts/ipaex tests/Feature/Approval/Phase4/PdfSheetTest.php tests/Feature/ClockReadScanTest.php tests/Feature/MobileLayoutTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 決裁申請書の PDF の紙面と mPDF・IPAex フォントを足す

紙の決裁申請書の枠に寄せた A4 縦の紙面（決裁No・日付・判断の欄と 3 つの印・
件名・本文の罫線・審査部門）を、部品 mPDF（GPL-2.0。社外へ配らない社内の
システムとして使う）と同梱の IPAex ゴシック・明朝で PDF にする。中身は申請の
詳細と同じ出し分けで、判断は今の回だけ。本文は罫線の 1 行ずつにして、長い
本文は縮めずに次のページへ続ける（段階4 設計書 §5.6・D4・D5・D14〜D16）。
紙面は画面に出さないので、スマホの表の走査（MobileLayoutTest）から外す。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 6: 申請の詳細から PDF を出力して記録する

申請の詳細の見出しの行に「PDF を出力」を置き、見られる人だけに（下書きは 404）PDF をブラウザで開く形で渡し、出力のたびに記録する。作れなかったときは記録せず、詳細へ戻して laravel.log に残す（設計書 §5.6・§5.7・§0.5・D17・D18）。

**Files:**
- Create: `app/Http/Controllers/Approval/RequestPdfController.php`
- Modify: `routes/approval.php`・`resources/views/approvals/requests/show.blade.php`
- Test: Create `tests/Feature/Approval/Phase4/RequestPdfTest.php`・Modify `tests/Feature/Approval/ApprovalAdminGateTest.php`

**Interfaces:**
- Consumes: Task 5 の `PdfSheet::for()`・`ApprovalPdf::sheet()`・既存の `RequestVisibility::canView()`・`ApprovalDownloadLog`
- Produces: ルート `approvals.requests.pdf`（GET `/approvals/requests/{approvalRequest}/pdf`・`approval.launched` のグループ）

**差分の大きさ:** 5 ファイル・+207 / −1 行（差分のファイル `0006-…`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Approval/Phase4/RequestPdfTest.php`（新規）

```php
<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalDownloadLog;
use App\Models\ApprovalRequest;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 決裁申請書の PDF の出力（要件 9.2・14.2・段階4 設計書 §5.6・§5.7・D17・D18） */
class RequestPdfTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 01:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function decided(array $w): ApprovalRequest
    {
        $workflow = app(Workflow::class);
        $r = $this->submittedFor($w);
        $workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $workflow->judgeReview($r->fresh(), $w['reviewer'], $r->fresh()->lock_version, ApprovalStepResult::Ok, null);
        $workflow->judgePresident($r->fresh(), $w['president'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);

        return $r->fresh();
    }

    public function test_a_viewer_gets_the_pdf_inline_and_it_is_recorded(): void
    {
        $this->launchApprovals();
        $w = $this->approvalWorld();
        $r = $this->decided($w);

        $response = $this->actingAs($w['reviewer'])->get(route('approvals.requests.pdf', $r))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame(
            "inline; filename=approval-R8-J-001.pdf; filename*=utf-8''" . rawurlencode('決裁申請書_R8-J-001.pdf'),
            $response->headers->get('Content-Disposition'),
            'ブラウザでそのまま開く・日本語の名前と ASCII の代わりの名前'
        );
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        $this->assertSame(
            [[$r->id, null, $w['reviewer']->id, 'pdf']],
            ApprovalDownloadLog::all()->map(fn (ApprovalDownloadLog $l) => [$l->request_id, $l->attachment_id, $l->user_id, $l->kind])->all(),
        );
    }

    public function test_someone_who_cannot_see_the_request_gets_404_and_nothing_is_recorded(): void
    {
        $this->launchApprovals();
        $w = $this->approvalWorld();
        $r = $this->decided($w);
        $stranger = $this->baseUser(['name' => '無関係 太郎']);

        $this->actingAs($stranger)->get(route('approvals.requests.pdf', $r))->assertNotFound();

        $this->assertSame(0, ApprovalDownloadLog::count());
    }

    public function test_a_draft_is_not_printed_even_for_the_applicant(): void
    {
        $this->launchApprovals();
        $w = $this->approvalWorld();
        $draft = $this->draftFor($w);

        $this->actingAs($w['applicant'])->get(route('approvals.requests.pdf', $draft))->assertNotFound();

        $this->assertSame(0, ApprovalDownloadLog::count());
    }

    public function test_before_launch_the_pdf_is_not_offered(): void
    {
        $w = $this->approvalWorld();
        $r = $this->decided($w);

        $this->actingAs($w['applicant'])->get(route('approvals.requests.pdf', $r))->assertRedirect(route('approvals.home'));

        $this->assertSame(0, ApprovalDownloadLog::count());
    }

    public function test_the_detail_has_the_link_once_the_request_is_submitted(): void
    {
        $this->launchApprovals();
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $r))->assertOk()->getContent();

        $this->assertStringContainsString('href="' . route('approvals.requests.pdf', $r) . '"', $html);
        $this->assertStringContainsString('PDF を出力', $html);

        $draft = $this->draftFor($w);
        $draftHtml = $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $draft))->assertOk()->getContent();
        $this->assertStringNotContainsString('PDF を出力', $draftHtml, '下書きの詳細にはボタンを出さない');
    }

    public function test_when_the_pdf_cannot_be_made_the_detail_says_so_and_the_log_has_the_error(): void
    {
        $this->launchApprovals();
        $w = $this->approvalWorld();
        $r = $this->decided($w);
        config(['approval.pdf.font_dir' => storage_path('framework/testing/no-fonts-here')]);
        Log::spy();

        $this->actingAs($w['applicant'])->get(route('approvals.requests.pdf', $r))
            ->assertRedirect(route('approvals.requests.show', $r))
            ->assertSessionHas('error', 'PDF を作れませんでした。時間をおいてもう一度お試しください。');

        Log::shouldHaveReceived('error')->with('決裁申請書の PDF を作れませんでした', Mockery::on(fn (array $context) => $context['request_id'] === $r->id))->once();
        $this->assertSame(0, ApprovalDownloadLog::count(), '作れなかったときは記録しない');
    }
}
```

`tests/Feature/Approval/ApprovalAdminGateTest.php`（変更）

```diff
--- a/tests/Feature/Approval/ApprovalAdminGateTest.php
+++ b/tests/Feature/Approval/ApprovalAdminGateTest.php
@@ -95,6 +95,7 @@ class ApprovalAdminGateTest extends TestCase
         'approvals.requests.edit'    => '下書き・差戻し中の編集（申請者だけ。RequestPermissions）',
         'approvals.requests.update'  => '同じく保存・提出',
         'approvals.requests.destroy' => '一度も提出していない下書きの削除（申請者だけ）',
+        'approvals.requests.pdf'     => '決裁申請書の PDF（見られる範囲を毎回確かめ、記録する。下書きは 404。段階4 設計書 §5.6）',
         'approvals.requests.attachments.store' => '添付の追加（申請者だけ。下書き・差戻し中。段階2 設計書 §5.7）',
         'approvals.attachments.show'           => '添付を開く（見られる範囲を毎回確かめ、記録する）',
         'approvals.attachments.destroy'        => '添付を外す（申請者だけ。下書き・差戻し中）',
@@ -190,7 +191,7 @@ public function test_every_approvals_route_is_classified(): void
 
         // 走査が空振りして緑になる事故を防ぐ（3b で 50 本 = 決裁の管理 22 本 + 進行中の申請の管理 4 本 + ホーム 1 本 + 申請を回す画面 16 本 + お知らせ 3 本
         // + 催促の設定 4 本）
-        $this->assertGreaterThanOrEqual(50, $found, 'approvals. のルートの走査に失敗している');
+        $this->assertGreaterThanOrEqual(51, $found, 'approvals. のルートの走査に失敗している');
     }
 
     /**
```

（差分のファイルを使うなら: `… git apply --include='tests/*' ~/.claude/plans/approval-phase3-tasks/4a/patches/0006-*.patch`）

- [ ] **Step 2: テストを流して失敗することを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/Phase4/RequestPdfTest.php tests/Feature/Approval/ApprovalAdminGateTest.php
```

Expected: `ERRORS!` `Tests: 8, Assertions: 28, Errors: 6, Failures: 1.`

- `RequestPdfTest::test_a_viewer_gets_the_pdf_inline_and_it_is_recorded` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.requests.pdf] not defined.`
- `RequestPdfTest::test_someone_who_cannot_see_the_request_gets_404_and_nothing_is_recorded` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.requests.pdf] not defined.`
- `RequestPdfTest::test_a_draft_is_not_printed_even_for_the_applicant` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.requests.pdf] not defined.`
- `RequestPdfTest::test_before_launch_the_pdf_is_not_offered` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.requests.pdf] not defined.`
- `RequestPdfTest::test_the_detail_has_the_link_once_the_request_is_submitted` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.requests.pdf] not defined.`
- `RequestPdfTest::test_when_the_pdf_cannot_be_made_the_detail_says_so_and_the_log_has_the_error` — `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [approvals.requests.pdf] not defined.`
- `ApprovalAdminGateTest::test_every_approvals_route_is_classified` — `分類漏れ・門番の欠落・逆方向の見落とし:`

- [ ] **Step 3: 出力の処理とルート**

`app/Http/Controllers/Approval/RequestPdfController.php`（新規）

```php
<?php

namespace App\Http\Controllers\Approval;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalDownloadLog;
use App\Models\ApprovalRequest;
use App\Support\Approval\ApprovalPdf;
use App\Support\Approval\PdfSheet;
use App\Support\Approval\RequestVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Throwable;

/**
 * 決裁申請書の PDF（画面③ の「PDF を出力」。要件 9.2・14.2・段階4 設計書 §5.6・§5.7・D17・D18）。
 */
class RequestPdfController extends Controller
{
    /** Route: GET /approvals/requests/{approvalRequest}/pdf */
    public function show(Request $request, ApprovalRequest $approvalRequest): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless(RequestVisibility::canView($user, $approvalRequest), 404);
        // 下書きは出さない（まだ誰にも回っていない。D17）。申請者本人にも
        abort_if($approvalRequest->status === ApprovalStatus::Draft, 404);

        $approvalRequest->load(['applicant', 'department', 'type', 'attachments', 'steps.department', 'steps.actor']);
        $sheet = PdfSheet::for($user, $approvalRequest);

        try {
            $pdf = ApprovalPdf::sheet($sheet);
        } catch (Throwable $e) {
            // 作れなかったときは記録しない（D18・§5.7）。本番の laravel.log は error だけ残すので error で書く
            Log::error('決裁申請書の PDF を作れませんでした', ['request_id' => $approvalRequest->id, 'exception' => $e]);

            return redirect()->route('approvals.requests.show', $approvalRequest)
                ->with('error', 'PDF を作れませんでした。時間をおいてもう一度お試しください。');
        }

        // 出力のたびに記録する（14.2・§5.7）
        ApprovalDownloadLog::create([
            'request_id'    => $approvalRequest->id,
            'attachment_id' => null,
            'user_id'       => $user->id,
            'kind'          => 'pdf',
            'ip_address'    => $request->ip(),
            'user_agent'    => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        // ブラウザでそのまま開く（D18）。日本語の名前は filename*（UTF-8）、古いブラウザ用の代わりは ASCII だけの名前
        $fallback = 'approval-' . ($approvalRequest->number ?? $approvalRequest->id) . '.pdf';

        return response($pdf, 200, [
            'Content-Type'           => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $sheet->fileName, $fallback),
        ]);
    }
}
```

`routes/approval.php`（変更）

```diff
--- a/routes/approval.php
+++ b/routes/approval.php
@@ -9,6 +9,7 @@
 use App\Http\Controllers\Approval\RequestActionController;
 use App\Http\Controllers\Approval\RequestAttachmentController;
 use App\Http\Controllers\Approval\RequestController;
+use App\Http\Controllers\Approval\RequestPdfController;
 use App\Http\Controllers\Approval\TypeController;
 use App\Http\Controllers\Approval\UserController;
 use App\Http\Controllers\Approval\UserImportController;
@@ -136,6 +137,9 @@
     Route::put('/requests/{approvalRequest}', [RequestController::class, 'update'])->name('requests.update');
     Route::delete('/requests/{approvalRequest}', [RequestController::class, 'destroy'])->name('requests.destroy');
 
+    // 決裁申請書の PDF（段階4 設計書 §5.6）。見られる人なら提出したことのある申請をいつでも（下書きは 404）
+    Route::get('/requests/{approvalRequest}/pdf', [RequestPdfController::class, 'show'])->name('requests.pdf');
+
     // 添付（段階2 設計書 §5.7・計画 §0.5）。追加と外すのは Ajax・JSON
     Route::post('/requests/{approvalRequest}/attachments', [RequestAttachmentController::class, 'store'])->name('requests.attachments.store');
     Route::get('/attachments/{approvalAttachment}', [RequestAttachmentController::class, 'show'])->name('attachments.show');
```

- [ ] **Step 4: 詳細のボタン**

`resources/views/approvals/requests/show.blade.php`（変更）

```diff
--- a/resources/views/approvals/requests/show.blade.php
+++ b/resources/views/approvals/requests/show.blade.php
@@ -30,6 +30,11 @@
             {{-- 取り下げた申請には番号が付かないので言わない（取り下げは社長の判断の前だけ。Task 19 の B7） --}}
             <span class="text-[12px] text-gray-400">決裁No は社長の判断のときに付きます</span>
         @endif
+        {{-- 決裁申請書の PDF（段階4 設計書 §5.6）。下書きは出さない（D17）。ブラウザの別のタブで開く（D18） --}}
+        @if($approvalRequest->status !== \App\Enums\ApprovalStatus::Draft)
+            <a href="{{ route('approvals.requests.pdf', $approvalRequest) }}" target="_blank" rel="noopener"
+               class="ml-auto inline-flex items-center gap-1 px-3 py-1.5 border border-gray-300 rounded-md bg-white text-[12px] text-gray-700 hover:bg-gray-50">PDF を出力</a>
+        @endif
     </div>
     <h1 class="text-lg font-bold text-gray-900 mb-4 break-words">{{ $content->subject ?? '（件名なし）' }}</h1>
```

（差分のファイルを使うなら: `… git apply --exclude='tests/*' ~/.claude/plans/approval-phase3-tasks/4a/patches/0006-*.patch`）

- [ ] **Step 5: テストを流して通ることを確かめる**（Step 2 と同じコマンド）

Expected: `OK (8 tests, …)`

- [ ] **Step 6: 全件を流す**

Expected: `OK (3380 tests, 23936 assertions)`

- [ ] **Step 7: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add app/Http/Controllers/Approval/RequestPdfController.php routes/approval.php resources/views/approvals/requests/show.blade.php tests/Feature/Approval/Phase4/RequestPdfTest.php tests/Feature/Approval/ApprovalAdminGateTest.php
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
feat(approval): 申請の詳細から決裁申請書の PDF を出力して記録する

申請の詳細に「PDF を出力」を置き、見られる人だけに（下書きは出さない）PDF を
ブラウザで開く形で渡す。出力のたびに approval_download_logs に kind=pdf で
記録し、作れなかったときは記録せずに詳細へ戻して laravel.log に残す
（段階4 設計書 §5.6・§5.7・D17・D18）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

---

## Task 7: 全件テストと変異テスト（Bug #44 の作法）

**Files:** なし（測るだけ。穴が見つかったらテストを足してコミットする）

計画を書く段階で、試作（この計画のコードと同じ中身）に下の表の変異を 1 つずつ当てて測った（2026-10-04。部品の更新を取り込んで作り直した試作で測り直した。決裁のテスト〈`tests/Feature/Approval`・`tests/Unit/Approval`〉と走査テスト 4 本を流した）。測る前に変異の一覧を作りながら、テストが気づけない壊し方（紙面の HTML の○・印・省略・ページの下・コメントのエスケープ、受付日、末尾の空行、印の文字の大きさ、下書きの詳細のボタン）を見つけ、先に Task 3・5・6 のテストを足してから測った。

⚠ WT のファイルを一時的に壊す変異は、自動の許可の判定に断られる。**WT の HEAD の写しを scratchpad に作ってそこで当てる**（WT は読むだけ）。以下の `<scratchpad>` は、その会話の scratchpad のパス（Mac を再起動すると消える。残したい結果は `~/.claude/plans/approval-phase3-tasks/4a/` へ写す）:

```bash
SCR=<scratchpad>/p4a-mutation && mkdir -p "$SCR" && git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 archive HEAD | tar -x -C "$SCR" && cp -Rc /Users/masanori/site/manage/.claude/worktrees/approval-phase3/vendor "$SCR/vendor" && ! test -L "$SCR/vendor" && test -f "$SCR/vendor/mpdf/mpdf/src/Mpdf.php" && test -f "$SCR/resources/fonts/ipaex/ipaexm.ttf" && echo "写し OK"
```

⚠ `git archive` は `.gitattributes` の `export-ignore` を外すだけで、フォントはそのまま写る。⚠ 写しの `storage/framework/views/*.php` は流す前に消す（道具が毎回消す。古いコンパイル済みのビューが残ると、ビューの変異が効かない）。

- [ ] **Step 1: 全件が緑の状態から始める**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/approval-phase3 && git status --porcelain && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `git status --porcelain` が空・`OK (3380 tests, 23936 assertions)`

- [ ] **Step 2: 変異を当てて表と突き合わせる**

道具は `~/.claude/plans/approval-phase3-tasks/4a/mutate.py`（3b の道具の写しで、変異の一覧だけ 4a にしたもの。1 つ当てて流し、必ず元に戻して、戻ったことを確かめる。書き換える前の文字列が 1 回だけ現れないものは当てずに SKIP と記録する）。先に `--check` で、当てる場所がちょうど 1 回ずつ見つかることを確かめる:

```bash
python3 ~/.claude/plans/approval-phase3-tasks/4a/mutate.py <scratchpad>/p4a-mutation /dev/null --check
python3 ~/.claude/plans/approval-phase3-tasks/4a/mutate.py <scratchpad>/p4a-mutation <scratchpad>/mutations.jsonl
```

Expected（1 行目）: `NG` の行が無く `checked 60`。途中で止めるときは python に SIGINT（写しのファイルが元に戻る）。同じ出力のファイルを渡して流し直すと、記録済みの変異を飛ばして続きから流す。

⚠ **最初の `CANARY`（申請の詳細の見出しに未定義の変数）が赤になること**を確かめてから結果を読む（測定が写しのコードを読んでいることの証明）。
⚠ **赤/緑ではなく「落ちたテストの集合」と「落ちた理由の文言」まで突き合わせる。** 意図と別の機構が落としているなら、その変異の測定は無効。当て方を変えて測り直す。

計画の時点の実測（試作。「落ちたテスト」はクラス名を省いたテストの名前）:

| # | 変異（ファイル） | 結果 | 落ちたテスト（実測） | 判定 |
|---|---|---|---|---|
| CANARY | カナリア: 申請の詳細の見出しに未定義の変数（`show.blade.php`） | Tests: 958, Assertions: 6421, Failures: 80. | 80 本（AdminRequestsTest, NoticeScreenTest, RequestActionTest, RequestAttachmentTest ほか） | カナリア（赤が正しい） |
| T01 | SQL の印に使う文字を 5 文字に（`2026-10-03-approval-phase4a.sql`） | Tests: 958, Assertions: 7152, Failures: 1. | `test_the_sql_and_the_migration_add_the_same_columns` | 検出 |
| T02 | migration の段階の印の下段を NOT NULL に（`2026_10_03_000001_add_approval_phase4a_columns.php`） | Tests: 958, Assertions: 3241, Errors: 339, Failures: 2. | `test_a_circulating_request_stays_with_its_review_department`、`test_a_colleague_in_the_same_department_does_not_see`、`test_a_complete_request_can_be_submitted_from_the_form`、`test_a_conditional_approval_asks_the_applicant_to_confirm`、`test_a_conditional_approval_is_confirmed_by_the_applicant`、`test_a_conditional_approval_shows_the_condition_and_when_it_was_confirmed` ほか 313 本 | 検出 |
| T03 | 段階の印の控えを書けなくする（fillable から外す）（`ApprovalStep.php`） | Tests: 958, Assertions: 7151, Failures: 3. | `test_changing_the_settings_later_does_not_change_the_stamp`、`test_each_judgement_records_its_stamp`、`test_the_stamp_columns_are_fillable` | 検出 |
| T04 | 利用者の印に使う文字を書けなくする（fillable から外す）（`ApprovalMember.php`） | Tests: 958, Assertions: 7134, Failures: 13. | `test_an_update_without_the_field_keeps_the_stamp_text`、`test_anyone_can_get_a_stamp_text_even_when_the_name_cannot_be_edited`、`test_clearing_the_text_goes_back_to_the_name`、`test_saving_the_same_text_again_records_nothing`、`test_spaces_around_the_text_are_removed_including_full_width_ones`、`test_the_admin_sets_the_stamp_text_before_launch` ほか 7 本 | 検出 |
| S01 | 全角の空白で区切らない（`StampText.php`） | Tests: 958, Assertions: 7149, Failures: 1. | `test_the_default_text_is_the_name_before_the_first_space` | 検出 |
| S02 | 氏名の前後の空白を外さない（`StampText.php`） | Tests: 958, Assertions: 7150, Failures: 1. | `test_the_default_text_is_the_name_before_the_first_space` | 検出 |
| S03 | 印に使う文字を見ない（`StampText.php`） | Tests: 958, Assertions: 7140, Failures: 6. | `test_the_list_shows_a_preview_of_each_stamp_and_the_edit_form_has_the_field`、`test_the_stamp_has_the_three_rows_in_the_stamp_color`、`test_the_stamp_is_drawn_with_the_fitted_sizes`、`test_the_stamp_uses_the_text_set_at_the_time_of_the_judgement`、`test_the_text_is_escaped`、`test_the_text_set_by_the_admin_wins_over_the_name` | 検出 |
| S04 | 空の印に使う文字を既定に戻さない（`StampText.php`） | Tests: 958, Assertions: 7152, Failures: 1. | `test_the_text_set_by_the_admin_wins_over_the_name` | 検出 |
| S05 | 社長の上段を「社長」にしない（`StampText.php`） | Tests: 958, Assertions: 7138, Failures: 3. | `test_a_decided_request_has_the_number_the_marks_and_the_three_stamps`、`test_each_judgement_records_its_stamp`、`test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | 検出 |
| S06 | 判断のときに下段を控えない（`Workflow.php`） | Tests: 958, Assertions: 7152, Failures: 2. | `test_changing_the_settings_later_does_not_change_the_stamp`、`test_each_judgement_records_its_stamp` | 検出 |
| S07 | 判断のときに上段を控えない（`Workflow.php`） | Tests: 958, Assertions: 7152, Failures: 2. | `test_changing_the_settings_later_does_not_change_the_stamp`、`test_each_judgement_records_its_stamp` | 検出 |
| S08 | 取り消しで印の控えを消さない（`Workflow.php`） | Tests: 958, Assertions: 7152, Failures: 1. | `test_undo_removes_the_stamp_with_the_judgement` | 検出 |
| S09 | 控えた上段でなく今の部門の略称を出す（`Stamp.php`） | Tests: 958, Assertions: 7152, Failures: 1. | `test_changing_the_settings_later_does_not_change_the_stamp` | 検出 |
| S10 | 控えた下段でなく今の印に使う文字を出す（`Stamp.php`） | Tests: 958, Assertions: 7152, Failures: 1. | `test_changing_the_settings_later_does_not_change_the_stamp` | 検出 |
| S11 | 和暦の日付を UTC で作る（`Stamp.php`） | Tests: 958, Assertions: 7137, Failures: 7. | `test_a_judgement_without_a_recorded_stamp_is_drawn_from_the_current_settings`、`test_a_return_is_stamped_too`、`test_changing_the_settings_later_does_not_change_the_stamp`、`test_each_judgement_records_its_stamp`、`test_the_date_is_in_the_japanese_era_of_the_calendar_day_in_japan`、`test_the_sheet_draws_the_marks_the_stamps_and_the_footer` ほか 1 本 | 検出 |
| S12 | 令和の初日を平成にする（`Stamp.php`） | Tests: 958, Assertions: 7150, Failures: 1. | `test_the_date_is_in_the_japanese_era_of_the_calendar_day_in_japan` | 検出 |
| S13 | 令和の年を 1 つずらす（`Stamp.php`） | Tests: 958, Assertions: 7125, Failures: 10. | `test_a_judgement_without_a_recorded_stamp_is_drawn_from_the_current_settings`、`test_a_return_is_stamped_too`、`test_changing_the_settings_later_does_not_change_the_stamp`、`test_each_judgement_records_its_stamp`、`test_the_date_is_in_the_japanese_era_of_the_calendar_day_in_japan`、`test_the_detail_shows_the_stamp_beside_each_judged_step` ほか 4 本 | 検出 |
| S14 | ⑦ の見本の上段を空にする（`Stamp.php`） | Tests: 958, Assertions: 7148, Failures: 1. | `test_the_list_shows_a_preview_of_each_stamp_and_the_edit_form_has_the_field` | 検出 |
| S15 | 判断していない段階も印を作る（状態を見ない）（`Stamp.php`） | OK (958 tests, 7152 assertions) | — | 等価（`acted_at` を入れるのは `finishStep()`〈`Done`〉だけ。`reopenStep()` は待ちに戻すときに空にし、省略〈`Skipped`〉・取り消し〈`Cancelled`〉・まだ届いていない段階は空のまま＝`acted_at` があれば必ず `Done`） |
| V01 | 下段をエスケープしない（`StampSvg.php`） | Tests: 958, Assertions: 7145, Failures: 3. | `test_comments_and_names_are_escaped`、`test_the_sheet_escapes_what_people_typed`、`test_the_text_is_escaped` | 検出 |
| V02 | 上段を縮めない（`StampSvg.php`） | Tests: 958, Assertions: 7152, Failures: 1. | `test_a_long_department_name_is_drawn_smaller_in_the_top_row` | 検出 |
| V03 | 下段を縮めない（`StampSvg.php`） | Tests: 958, Assertions: 7152, Failures: 1. | `test_the_stamp_is_drawn_with_the_fitted_sizes` | 検出 |
| V04 | 文字の大きさの下限を外す（`StampSvg.php`） | Tests: 958, Assertions: 7151, Failures: 1. | `test_longer_text_is_drawn_smaller_to_stay_inside_the_stamp` | 検出 |
| V05 | 申請の詳細に印を出さない（`_steps.blade.php`） | Tests: 958, Assertions: 7151, Failures: 2. | `test_comments_and_names_are_escaped`、`test_the_detail_shows_the_stamp_beside_each_judged_step` | 検出 |
| V06 | 読み上げの名前をエスケープしない（`StampSvg.php`） | Tests: 958, Assertions: 7145, Failures: 3. | `test_comments_and_names_are_escaped`、`test_the_sheet_escapes_what_people_typed`、`test_the_text_is_escaped` | 検出 |
| U01 | 5 文字を受け付ける（`UserController.php`） | Tests: 958, Assertions: 7149, Failures: 1. | `test_more_than_four_characters_are_refused` | 検出 |
| U03 | 設定の記録に残さない（`UserController.php`） | Tests: 958, Assertions: 7152, Failures: 2. | `test_clearing_the_text_goes_back_to_the_name`、`test_the_admin_sets_the_stamp_text_before_launch` | 検出 |
| U05 | 変わっていなくても記録する（`UserController.php`） | Tests: 958, Assertions: 7152, Failures: 1. | `test_saving_the_same_text_again_records_nothing` | 検出 |
| U06 | 氏名を直せる人だけ印に使う文字を直せる（`UserController.php`） | Tests: 958, Assertions: 7149, Errors: 1. | `test_anyone_can_get_a_stamp_text_even_when_the_name_cannot_be_edited` | 検出 |
| U09 | 欄が送られていない更新で印に使う文字を消す（`UserController.php`） | Tests: 958, Assertions: 7151, Failures: 1. | `test_an_update_without_the_field_keeps_the_stamp_text` | 検出 |
| U07 | ⑦ に印の見本を出さない（`index.blade.php`） | Tests: 958, Assertions: 7148, Failures: 1. | `test_the_list_shows_a_preview_of_each_stamp_and_the_edit_form_has_the_field` | 検出 |
| U08 | 編集できない人への案内に印を入れない（`index.blade.php`） | Tests: 958, Assertions: 7151, Failures: 1. | `test_the_list_shows_a_preview_of_each_stamp_and_the_edit_form_has_the_field` | 検出 |
| P01 | 条可の欄を可にする（`PdfSheet.php`） | Tests: 958, Assertions: 7140, Failures: 2. | `test_a_conditional_approval_shows_the_condition_and_when_it_was_confirmed`、`test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | 検出 |
| P02 | 保留の意見を否の欄にする（`PdfSheet.php`） | Tests: 958, Assertions: 7136, Failures: 2. | `test_a_decided_request_has_the_number_the_marks_and_the_three_stamps`、`test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | 検出 |
| P03 | 受付日を発信日にする（`PdfSheet.php`） | Tests: 958, Assertions: 7150, Failures: 2. | `test_before_the_decision_the_number_date_and_marks_are_blank`、`test_the_issue_date_and_the_receipt_date_come_from_different_moments` | 検出 |
| P04 | 条件確認の日時を決裁の日時にする（`PdfSheet.php`） | Tests: 958, Assertions: 7152, Failures: 1. | `test_a_conditional_approval_shows_the_condition_and_when_it_was_confirmed` | 検出 |
| P05 | 件名を申請の今の値から出す（最後に提出した控えを使わない）（`PdfSheet.php`） | Tests: 958, Assertions: 7151, Failures: 1. | `test_others_see_the_last_submitted_content_while_the_applicant_edits` | 検出 |
| P06 | 長い行を分けない（`PdfSheet.php`） | Tests: 958, Assertions: 7150, Failures: 1. | `test_the_body_is_split_into_ruled_rows` | 検出 |
| P07 | 紙の行数まで埋めない（`PdfSheet.php`） | Tests: 958, Assertions: 7146, Failures: 2. | `test_the_body_is_split_into_ruled_rows`、`test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | 検出 |
| P08 | 末尾の空行を落とさない（`PdfSheet.php`） | Tests: 958, Assertions: 7152, Failures: 1. | `test_the_body_is_split_into_ruled_rows` | 検出 |
| P09 | 部門長確認の省略を出さない（`PdfSheet.php`） | Tests: 958, Assertions: 7150, Failures: 2. | `test_a_skipped_head_step_is_marked_as_skipped`、`test_the_sheet_says_the_head_step_was_skipped` | 検出 |
| P10 | 出力の日時を UTC で出す（`PdfSheet.php`） | Tests: 958, Assertions: 7149, Failures: 2. | `test_a_decided_request_has_the_number_the_marks_and_the_three_stamps`、`test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | 検出 |
| P11 | 番号の前のファイル名に申請の番号を入れない（`PdfSheet.php`） | Tests: 958, Assertions: 7152, Failures: 1. | `test_before_the_decision_the_number_date_and_marks_are_blank` | 検出 |
| P12 | 判断の欄に朱の枠を付けない（`pdf.blade.php`） | Tests: 958, Assertions: 7141, Failures: 1. | `test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | 検出 |
| P13 | 紙面に社長の印を出さない（`pdf.blade.php`） | Tests: 958, Assertions: 7145, Failures: 1. | `test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | 検出 |
| P14 | 紙面に部門長確認の省略を出さない（`pdf.blade.php`） | Tests: 958, Assertions: 7151, Failures: 1. | `test_the_sheet_says_the_head_step_was_skipped` | 検出 |
| P15 | 本文を 1 つの枠に入れる（mPDF が縮める）（`pdf.blade.php`） | Tests: 958, Assertions: 7150, Failures: 1. | `test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | 検出 |
| P16 | 決裁者コメントをエスケープしない（`pdf.blade.php`） | Tests: 958, Assertions: 7150, Failures: 1. | `test_the_sheet_escapes_what_people_typed` | 検出 |
| P17 | ページの下に出力者を出さない（`_pdf_footer.blade.php`） | Tests: 958, Assertions: 7151, Failures: 1. | `test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | 検出 |
| P18 | 同梱のフォントの置き場所を渡さない（`ApprovalPdf.php`） | Tests: 958, Assertions: 7143, Errors: 1, Failures: 1. | `test_a_viewer_gets_the_pdf_inline_and_it_is_recorded`、`test_the_pdf_is_made_with_the_bundled_fonts_and_flows_onto_more_pages` | 検出 |
| P19 | 件名をエスケープしない（`pdf.blade.php`） | Tests: 958, Assertions: 7147, Failures: 1. | `test_the_sheet_escapes_what_people_typed` | 検出 |
| R01 | 見られる範囲を確かめない（`RequestPdfController.php`） | Tests: 958, Assertions: 7151, Failures: 1. | `test_someone_who_cannot_see_the_request_gets_404_and_nothing_is_recorded` | 検出 |
| R02 | 下書きも出す（`RequestPdfController.php`） | Tests: 958, Assertions: 7151, Failures: 1. | `test_a_draft_is_not_printed_even_for_the_applicant` | 検出 |
| R03 | 記録の種類を添付にする（`RequestPdfController.php`） | Tests: 958, Assertions: 7152, Failures: 1. | `test_a_viewer_gets_the_pdf_inline_and_it_is_recorded` | 検出 |
| R04 | 作れなかったことを warning で書く（本番の laravel.log に残らない）（`RequestPdfController.php`） | Tests: 958, Assertions: 7151, Errors: 1. | `test_when_the_pdf_cannot_be_made_the_detail_says_so_and_the_log_has_the_error` | 検出 |
| R05 | ブラウザで開かずダウンロードにする（`RequestPdfController.php`） | Tests: 958, Assertions: 7150, Failures: 1. | `test_a_viewer_gets_the_pdf_inline_and_it_is_recorded` | 検出 |
| R06 | nosniff を付けない（`RequestPdfController.php`） | Tests: 958, Assertions: 7149, Failures: 1. | `test_a_viewer_gets_the_pdf_inline_and_it_is_recorded` | 検出 |
| R07 | 下書きの詳細にもボタンを出す（`show.blade.php`） | Tests: 958, Assertions: 7152, Failures: 1. | `test_the_detail_has_the_link_once_the_request_is_submitted` | 検出 |
| R08 | ASCII の代わりの名前に番号を入れない（`RequestPdfController.php`） | Tests: 958, Assertions: 7150, Failures: 1. | `test_a_viewer_gets_the_pdf_inline_and_it_is_recorded` | 検出 |

- [ ] **Step 3: 表と違ったものを調べ、検出できなかった変異にテストを足す**

⚠ **等価**（緑が正しい）と書いたものは、なぜ等価かを 1 行で確かめる（読み違えて「守られていない」としない）。等価でないのに緑のものは、その Task のテストに 1 本足し、足したテストが変異で赤・元に戻して緑になることを確かめてからコミットする。

- [ ] **Step 4: 結果をこの計画に追記してコミット**

検出／当初検出漏れ→追加で検出／等価を区別して、この計画の末尾に「Task 7 の実測記録」として書き足す。

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add docs/superpowers/plans/2026-10-03-approval-phase4a.md tests/
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
test(approval): 段階4a の変異テストの結果を記録する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

写し（`<scratchpad>/p4a-mutation`）は、この Task が済んだら消してよい（`rm -rf` の前に中身が写しであることを確かめる）。

---

## Task 8: 手元のブラウザでの確認と、利用者に見せる写真と PDF（設計書 §6）

**Files:** なし（測るだけ）

テストが原理的に測れない領域（印の見た目・スマホの幅・⑦ の小窓・PDF の紙面）を見る。**本番では使い始めるまで申請の画面も PDF も出ないので、利用者に見てもらうのはここで撮る写真と PDF**。WT の HEAD の写し（scratchpad）＋使い捨ての SQLite ＋ `artisan serve`。ブラウザは Playwright（ログインは利用者の決まり「手元の画面にログイン」のとおり、試しのパスワードを画面にも記録にも出さない）。

- [ ] **Step 1: 写しと使い捨ての環境を作る**

⚠ Bash の呼び出しごとにシェルが新しくなるので、使い捨ての設定は 1 つのファイルにまとめ、以降のコマンドの先頭で `source` する。⚠ WT にも写しにも `.env` を作らない。⚠ `APP_LOCALE=ja`・`APP_FALLBACK_LOCALE=ja` を入れる。

```bash
SCR=<scratchpad>/p4a-browser && mkdir -p "$SCR" && git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 archive HEAD | tar -x -C "$SCR" && cp -Rc /Users/masanori/site/manage/.claude/worktrees/approval-phase3/vendor "$SCR/vendor"
LOCAL=<scratchpad>/approval-phase4a-local.sh
cat > "$LOCAL" <<SH
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
export APP_LOCALE=ja
export APP_FALLBACK_LOCALE=ja
export APP_URL=http://127.0.0.1:8769
export DB_CONNECTION=sqlite
export DB_DATABASE="<scratchpad>/approval-phase4a.sqlite"
export MAIL_MAILER=log
export QUEUE_CONNECTION=sync
export TRIAL_PASSWORD="$(php -r 'echo bin2hex(random_bytes(6));')"
SH
source "$LOCAL" && touch "$DB_DATABASE" && cd "$SCR" && php artisan migrate --force && ln -s /Users/masanori/site/manage/node_modules node_modules && ./node_modules/.bin/vite build
```

- [ ] **Step 2: 試しのデータを入れる**（テストの土台をそのまま使う。⚠ ログイン用の使い捨てルートを作らない）

```bash
source <scratchpad>/approval-phase4a-local.sh && cd <scratchpad>/p4a-browser && php artisan tinker --execute='
use App\Enums\ApprovalStepResult as R;
use App\Models\ApprovalMember;
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
ApprovalMember::create(["user_id" => $w["head"]->id, "stamp_text" => "長谷川"]);
$w["reviewer"]->update(["name" => "高橋一郎"]);
$f->launchApprovals();
Carbon::setTestNow(Carbon::parse("2026-10-05 10:00:00", "Asia/Tokyo")->utc());
$short = $f->submittedFor($w, ["subject" => "分譲地 A 区画の造成工事の発注の件", "amount" => 28500000, "schedule" => "2026年11月〜2027年2月", "body" => "■ なぜ（目的・理由）\n・A 区画の販売開始を来年 4 月に合わせるため\n■ 何を（内容）\n・造成工事一式（擁壁・排水・道路）"]);
$wf->judgeHead($short->fresh(), $w["head"], $short->fresh()->lock_version, R::Approve, "急ぎでお願いします");
$wf->judgeReview($short->fresh(), $w["reviewer"], $short->fresh()->lock_version, R::Ok, "契約書の雛形は最新版を使ってください。");
$wf->judgePresident($short->fresh(), $w["president"], $short->fresh()->lock_version, R::Conditional, "工期の遅れが出ないよう、月次で報告してください。");
$long = $f->submittedFor($w, ["subject" => "長い本文の申請", "body" => str_repeat("・決裁申請システムの本文の試しです。工事の内容と目的、費用の内訳を箇条書きで書いています。\n", 300)]);
$wf->judgeHead($long->fresh(), $w["head"], $long->fresh()->lock_version, R::Approve, null);
Carbon::setTestNow();
DB::table("users")->update(["password" => Hash::make(getenv("TRIAL_PASSWORD")), "must_change_password" => false]);
echo "short ", $short->id, " long ", $long->id, PHP_EOL, "admin ", $admin->email, PHP_EOL, "applicant ", $w["applicant"]->email ?? $w["applicant"]->employee_number, PHP_EOL;
'
```

Expected: 2 件の申請の番号とログイン名。パスワードは `source` したシェルの `$TRIAL_PASSWORD`（値を出さない）。

画面を出す（**バックグラウンドで**。止めるまで動き続ける）:

```bash
source <scratchpad>/approval-phase4a-local.sh && cd <scratchpad>/p4a-browser && php artisan serve --port=8769
```

- [ ] **Step 3: 見ること**（1440px と 375px の両方）

| # | 画面 | 見ること |
|---|---|---|
| 1 | 申請の詳細（短い申請・申請者でログイン） | 回る順番の 3 段の右端に赤い丸の印（上段「住宅」「総務」「社長」・中段「R8.10.5」・下段「長谷川」「高橋一郎」〈小さく収まる〉「社長」）・左の文字の並びは前のまま・375px で印が文字を押しつぶさない |
| 2 | 申請の詳細の見出しの行 | 「PDF を出力」が右端にある（375px で折り返しても押せる）・下書きの詳細には無い |
| 3 | PDF（短い申請） | 別のタブで開く・A4 縦・題「決裁申請書」・状態「条件確認待ち ・ 1 回目の提出」・決裁No・日付・○が「条可」・決裁者コメント（条件）・3 つの印・申請部門と申請者名・件名・金額・本文の罫線 11 行・添付・審査部門（○が「可」）・ページの下に「決裁申請システムから出力 日時（出力者: 氏名）」と「1 / 1」 |
| 4 | PDF（長い本文の申請） | 文字が縮まずに複数のページに続く・2 ページ目以降の罫線と左右の線・各ページの下のページ番号 |
| 5 | ⑦（決裁の管理者でログイン） | 氏名の左に小さな印の見本（上段は所属部門の略称・下段は印に使う文字か氏名の空白より前・日付は今日）・空白の無い氏名は氏名全体が小さく入る |
| 6 | ⑦ の編集の小窓 | 「印に使う文字」の欄（4 文字まで・説明）・「部門 長」の欄に「長谷川」が入っている・5 文字目が打てない・空にして保存すると見本が「部門」に戻る・社長に指定された人の小窓でも欄が打てる（案内「決裁の所属部門と印に使う文字だけ変えられます。」） |
| 7 | 全画面 | `main.scrollWidth === main.clientWidth` を 1800 / 1200 / 375px で（Bug #29）・コンソールのエラーと警告が 0 件 |

- [ ] **Step 4: 利用者に見せる写真と PDF**

375px と 1440px で、申請の詳細（印のある回る順番と「PDF を出力」）・⑦（見本と小窓）を撮り、Step 3 の 3・4 の PDF と一緒に scratchpad に保存して利用者に送る（`SendUserFile`）。

- [ ] **Step 5: コンパイル済みビューを lint する**

⚠ `view:cache` の成功表示だけでは足りない（Bug #21 / #26 / #30）。

```bash
source <scratchpad>/approval-phase4a-local.sh && cd <scratchpad>/p4a-browser && php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done; php artisan view:clear
```

Expected: INVALID 0 件。

- [ ] **Step 6: 片付けて結果を記録する**

`artisan serve` を止めたことを確かめてから、写しと使い捨てのファイルを消す（`rm -rf` の前に、消すのが scratchpad の写しであることを `pwd` と `ls` で確かめる）。WT の `git status --porcelain` が空であることも確かめる。見たことと見つけた不具合を、この計画の末尾に「Task 8 の実測記録」として書き足してコミットする（`docs(approval): 段階4a の画面と PDF の確認の結果を記録する`）。

---

## Task 9: ドキュメント

**Files:**
- Modify: `docs/BACKLOG.md`（決裁申請の節の後ろに「段階4（電子印・PDF・決裁台帳と Excel）」の節を作り、4a の小見出しを書く。⚠ ファイルの末尾に足さない＝別の会話の記録とぶつかりやすい）
- Modify: `CLAUDE.md`（PDF の部品とフォントの注意を、既存の「決裁」の節の中に 3 行）

- [ ] **Step 1: BACKLOG に書く**（段階3 の節の直後に、段階3 の節と同じ形で）

書くこと: 設計書と計画のパス／4a・4b の分け方／表（`approval_members.stamp_text`・`approval_steps.stamp_label`・`stamp_text`）と「DB が先・`./deploy.sh` が後」／新しい部品（mPDF 8.3.1・GPL-2.0 は利用者の決定で使う）と同梱のフォント（IPAex。`resources/fonts/ipaex`・`.gitattributes` の `-text`）／`vendor/mpdf/mpdf/ttfonts` 87MB を本番へ送る（初回の反映が長い）／計画で決めた細部（§0.10）と受け入れた隙間（§0.11）／本番の Web のメモリの上限は段階6 の受け入れ確認で重い申請の PDF を 1 回出して確かめる

- [ ] **Step 2: CLAUDE.md に足す**（既存の決裁の注意の並びの中に）

- `mPDF は表の 1 行をページの途中で切れない。長い文を 1 つのセルに入れると縮めて 1 ページに押し込むので、罫線の 1 行＝表の 1 行にする（PdfSheet::bodyRows()）。文中の <span> の枠は描き損なうことがあるので小さな表で組む`
- `印の文字は判断したときに approval_steps に控える（Workflow::finishStep()）。表示は Stamp::forStep() を通す（今の設定から描かない）`
- `同梱のフォント（resources/fonts/ipaex）は .gitattributes の -text で配布元のまま保存する。ライセンスの文書を消さない`

- [ ] **Step 3: コミット**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 add docs/BACKLOG.md CLAUDE.md
git -C /Users/masanori/site/manage/.claude/worktrees/approval-phase3 commit -m "$(cat <<'MSG'
docs(approval): 段階4a の作り方と注意を BACKLOG と CLAUDE.md に書く

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
MSG
)"
```

コミットのあとで `git merge-tree --write-tree approval-phase3 13.x` が衝突を出さないことを確かめる（BACKLOG は別の会話も書く）。

---

## Task 10: 本番反映（利用者の了承を取ってから・親の会話が行う）

> ⚠ **サブエージェントに任せない。** 本番への `ssh`・`scp`・`./deploy.sh`、main repo での `composer install` は、それぞれ利用者の了承を取ってから行う。本番のファイルの削除は利用者が行う（実行する 1 行を渡す）。`.env` は読まない。
> ⚠ 本番のシェルは csh なので、`/bin/sh` の heredoc を `ssh` に流す（段階1〜3 と同じ作法）。PHP は `/usr/local/php/8.3/bin/php` を明示する（既定の `php` は 7.4）。

- [ ] **Step 1: 了承を求める（選択式）**

伝えること: ①**DB が先・`./deploy.sh` が後**（新しいコードが印の列を読み書きするので、逆だと判断の操作と利用者の管理が止まる）②本番の表に列が 3 つ増える（データは変えない）③新しい部品（PDF の mPDF。同梱の 87MB のフォントを含め約 93MB）と IPAex フォント（14MB）を本番へ送る（本番で `composer install` はしない。初回の反映が数分長い）④**使い始める前なので、利用者に見える変化は ⑦ の「印に使う文字」の欄と印の見本だけ**（申請の画面・印・PDF は使い始めるまで出ない）⑤戻すときは、`13.x` の前のコミットで `./deploy.sh`（足した列は残しても害は無い）。

- [ ] **Step 2: 反映前に本番を読み取る**（読み取りだけ）

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage || exit 1
/usr/local/php/8.3/bin/php artisan route:list --name=approvals. --json | /usr/local/php/8.3/bin/php -r '$r = json_decode(stream_get_contents(STDIN), true); echo "approvals=", count($r), PHP_EOL;'
/usr/local/php/8.3/bin/php artisan tinker --execute='
$schema = app("db")->getSchemaBuilder();
echo "members.stamp_text=", $schema->hasColumn("approval_members", "stamp_text") ? "ある" : "ない", " steps.stamp_label=", $schema->hasColumn("approval_steps", "stamp_label") ? "ある" : "ない", " steps.stamp_text=", $schema->hasColumn("approval_steps", "stamp_text") ? "ある" : "ない", PHP_EOL;
echo "launched_at=", var_export(App\Models\ApprovalSetting::current()->launched_at, true), PHP_EOL;
echo "requests=", app("db")->table("approval_requests")->count(), " steps=", app("db")->table("approval_steps")->count(), " members=", app("db")->table("approval_members")->count(), " download_logs=", app("db")->table("approval_download_logs")->count(), PHP_EOL;
echo "memory_limit=", ini_get("memory_limit"), PHP_EOL;
'
/usr/local/php/8.3/bin/php -m | grep -i -E '^(gd|mbstring|zlib)$' | tr '\n' ' '; echo
ls -d vendor/mpdf resources/fonts 2>/dev/null || echo "vendor/mpdf・resources/fonts=ない"
ls -la storage/logs/laravel.log
SH
```

Expected: `approvals=50`（3b のまま）／ 3 列とも「ない」／ `launched_at=NULL` ／ 申請・段階は 0（使い始める前）／ `memory_limit=128M`（計画を書いたときと同じ）／ `gd mbstring zlib` ／ `vendor/mpdf・resources/fonts=ない` ／ `laravel.log` の日付。**1 つでも違えば止まり、利用者に伝える**。

- [ ] **Step 3: `13.x` へ早送りで取り込む**（手元）

```bash
cd /Users/masanori/site/manage && git status --short && git merge-base --is-ancestor 13.x approval-phase3 && echo "FF できる" || echo "13.x が進んでいる"
```

「FF できる」なら:

```bash
git -C /Users/masanori/site/manage merge --ff-only approval-phase3 && git -C /Users/masanori/site/manage log --oneline -3 && ls -la /Users/masanori/site/manage/resources/fonts/ipaex
```

Expected: ttf 2 本（6,099,900 と 7,835,672 バイト）とライセンスの文書・Readme 2 本。「13.x が進んでいる」なら止まり、取り込み方（WT で `git merge 13.x` をしてから全件を流し直す、など。rebase しない）を利用者に選んでもらう（ほかの会話の作業が入っている）。

- [ ] **Step 4: DB を先に変える**

(a) SQL を本番の置き場所へ送る（`./deploy.sh` もあとで同じ場所へ同じものを送る）:

```bash
scp /Users/masanori/site/manage/database/sql/2026-10-03-approval-phase4a.sql mitsuwa-ud@www3586.sakura.ne.jp:apps/manage/database/sql/
```

(b) 流す前に、文の数と頭を見る（まだ何も変えない）:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$sql = preg_replace("/^--.*$/m", "", file_get_contents(base_path("database/sql/2026-10-03-approval-phase4a.sql")));
$statements = array_values(array_filter(array_map("trim", explode(";", $sql))));
echo "statements=", count($statements), PHP_EOL;
foreach ($statements as $i => $s) { echo $i + 1, ": ", strtok($s, "\n"), PHP_EOL; }
'
SH
```

Expected: `statements=2`（1 が `ALTER TABLE \`approval_members\``、2 が `ALTER TABLE \`approval_steps\``）。

(c) 1 文ずつ流す（**すでに流した形跡があれば 1 文も流さずに止まる**）:

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage && /usr/local/php/8.3/bin/php artisan tinker --execute='
$db = app("db");
$schema = $db->getSchemaBuilder();
$traces = array_keys(array_filter([
    "approval_members.stamp_text" => $schema->hasColumn("approval_members", "stamp_text"),
    "approval_steps.stamp_label"  => $schema->hasColumn("approval_steps", "stamp_label"),
    "approval_steps.stamp_text"   => $schema->hasColumn("approval_steps", "stamp_text"),
]));
if ($traces !== []) {
    echo "STOP: すでに流した形跡がある: ", implode(", ", $traces), PHP_EOL;
} else {
    $sql = preg_replace("/^--.*$/m", "", file_get_contents(base_path("database/sql/2026-10-03-approval-phase4a.sql")));
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
foreach (["approval_members", "approval_steps"] as $t) {
    foreach ($db->select("SHOW FULL COLUMNS FROM `" . $t . "` WHERE Field LIKE ?", ["stamp%"]) as $c) {
        echo $t, ".", $c->Field, " ", $c->Type, " null=", $c->Null, " collation=", $c->Collation, " comment=", $c->Comment, PHP_EOL;
    }
}
echo "members=", $db->table("approval_members")->count(), " steps=", $db->table("approval_steps")->count(), PHP_EOL;
'
SH
```

Expected: `approval_members.stamp_text varchar(4) null=YES collation=utf8mb4_unicode_ci comment=印に使う文字（空なら氏名の最初の空白より前）`・`approval_steps.stamp_label varchar(6) null=YES …`・`approval_steps.stamp_text varchar(100) null=YES …`／ 行の数は Step 2 と同じ。

- [ ] **Step 5: PDF の部品と新しいクラスを読み込めるようにする**（手元の main repo で）

```bash
cd /Users/masanori/site/manage && test ! -e vendor/bin/phpunit && composer install --no-dev --no-interaction 2>&1 | grep -E 'Package operations|mpdf|fpdi|random_compat|deep-copy|Nothing' && test ! -e vendor/bin/phpunit && composer dump-autoload --no-dev --optimize && git status --short && ls vendor/mpdf/mpdf/src/Mpdf.php
```

Expected: `Package operations: 6 installs, 0 updates, 0 removals`（`mpdf/mpdf (v8.3.1)`・`mpdf/psr-http-message-shim`・`mpdf/psr-log-aware-trait`・`setasign/fpdi`・`paragonie/random_compat`・`myclabs/deep-copy (1.13.4)`。deep-copy は手元の開発用には前からあるが、本番用の vendor には無かった＝§0.4。2026-10-04 に main repo の vendor の写しで `--dry-run` して確かめた）・`vendor/bin/phpunit` が無いまま（dev の部品が混ざっていない）・`git status` に何も出ない・`vendor/mpdf/mpdf/src/Mpdf.php` がある。⚠ **main repo の cwd で行う**（worktree から行うと autoloader に worktree のパスが焼き込まれる）。ほかの部品が入れ替わったら（`updates` か `removals` が 0 でない）止まって利用者に伝える。

- [ ] **Step 6: 反映**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

Expected: exit 0・6 段すべて成功（`vendor/mpdf`・`vendor/setasign` などと `resources/fonts/ipaex` が送られる。初回は数分長い）。

- [ ] **Step 7: 本番で確かめる**（すべて読み取り。PDF は申請が無いので、紙面を作らずにフォントを読めるかだけを試す）

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage || exit 1
n=0; bad=0
for f in storage/framework/views/*.php; do n=$((n+1)); /usr/local/php/8.3/bin/php -l "$f" >/dev/null 2>&1 || { bad=$((bad+1)); echo "INVALID: $f"; }; done
echo "views=$n invalid=$bad"
/usr/local/php/8.3/bin/php artisan route:list --name=approvals. --json | /usr/local/php/8.3/bin/php -r '$r = json_decode(stream_get_contents(STDIN), true); echo "approvals=", count($r), PHP_EOL;'
/usr/local/php/8.3/bin/php artisan tinker --execute='
echo "launched_at=", var_export(App\Models\ApprovalSetting::current()->launched_at, true), PHP_EOL;
foreach (["App\\Support\\Approval\\StampText", "App\\Support\\Approval\\Stamp", "App\\Support\\Approval\\StampSvg", "App\\Support\\Approval\\ApprovalPdf", "App\\Support\\Approval\\PdfSheet", "App\\Http\\Controllers\\Approval\\RequestPdfController", "Mpdf\\Mpdf"] as $c) {
    echo $c, "=", class_exists($c) ? "ok" : "NG", PHP_EOL;
}
echo "fonts=", implode(",", array_map("basename", glob(config("approval.pdf.font_dir") . "/*.ttf"))), PHP_EOL;
$t = microtime(true);
$pdf = App\Support\Approval\ApprovalPdf::render("<p style=\"font-family: ipaexm\">決裁申請書の試し</p><p>ゴシックの試し</p>", "", "試し");
echo "pdf=", substr($pdf, 0, 5), " bytes=", strlen($pdf), " mincho=", str_contains($pdf, "IPAexMincho") ? "ok" : "NG", " gothic=", str_contains($pdf, "IPAexGothic") ? "ok" : "NG", " seconds=", round(microtime(true) - $t, 2), " peak_mb=", round(memory_get_peak_usage(true) / 1048576, 1), PHP_EOL;
echo "download_logs=", app("db")->table("approval_download_logs")->count(), PHP_EOL;
'
ls -la storage/logs/laravel.log
ls -d storage/framework/cache/mpdf
SH
```

Expected: `invalid=0` ／ `approvals=51`（PDF の 1 本が増えた）／ `launched_at=NULL` ／ 7 クラスとも `ok` ／ `fonts=ipaexg.ttf,ipaexm.ttf` ／ `pdf=%PDF-`・`mincho=ok`・`gothic=ok`・数秒以内 ／ `download_logs` は Step 2 と同じ（試しの PDF は記録しない）／ `laravel.log` の日付が Step 2 と同じ＝反映のあとのエラーは 0 件 ／ `storage/framework/cache/mpdf` ができている。

- [ ] **Step 8: 利用者の Chrome で見るだけ**（決裁の管理者でログイン済みの画面。フォームは送らない。URL に `index.php/` が要る）

⑦（`…/index.php/approvals/admin/users`）: 氏名の左に印の見本・編集の小窓に「印に使う文字」の欄（**保存はしない**）・`main` のはみ出し 0・コンソールのエラー 0。決裁のホームは「準備中」のまま。

- [ ] **Step 9: 記録**

BACKLOG の 4a の小見出しに「本番反映（日付）」の表（3b と同じ形）を足してコミットする（`docs: 決裁 段階4a を本番に出した記録を残す`）。push は利用者の指示があってから。

---

## 計画を書く段階の実測（2026-10-03。部品の更新を取り込んで 2026-10-04 に作り直し・測り直した）

### 部品の更新を取り込んで作り直した（2026-10-04）

- 2026-10-03 夜に `13.x` へ既知の弱点のある部品の更新が入った（`96888b80`〈Laravel 12.55.0 → 12.69.3 ほか 53 の部品・Carbon の回避策〈`TestCase` の片付けと `CarbonTranslatorTrimTest`〉を外した〉・`e451262f`・`f6891312`。本番へ反映済み）。WT `approval-phase3` に `git merge 13.x`（`17ce20fb`・衝突なし）→ WT で `composer install`（lock どおり）→ 全件 `OK (3334 tests, 23750 assertions)`（前の土台より 2 本少ない＝`CarbonTranslatorTrimTest` の分）
- 試作を `17ce20fb` の写しで作り直した。差分の `0001`〜`0004`・`0006` は前のまま当たった。`0005` は `composer.lock` だけが古い土台のものだったので、新しい土台で `composer require "mpdf/mpdf:^8.3"` をやり直して lock を作り直した（増えた部品は前と同じ 5 つ・既にある部品の版は変わらない・`composer audit` は 0 件）
- 前の土台の全件で、task05・06 が 1 本ずつ赤だった（`MobileLayoutTest::test_every_table_has_a_horizontally_scrollable_ancestor`。画面に出さない紙面の表 9 か所を数えていた）→ §0.7 の対象外の一覧を足した。一覧を外すと 9 か所で赤・足すと緑になることを試作で確かめた
- ⚠ 開発用だった `myclabs/deep-copy` が mPDF の要る部品として本番用の側へ移る（前の lock でも同じだった）。main repo の vendor（`--no-dev`）の写しで `composer install --no-dev --dry-run` を流すと **6 installs**（deep-copy を含む）・0 updates・0 removals → Task 10 の Step 5 の Expected を 5 から 6 に直した（直さないと本番反映の途中で止まるところだった）
- ⚠ 測り方の注意: 測定用の写しの vendor の読み込み表（autoload の classmap）を後の段（task05）で作ると、テストを先に入れたときの赤が「クラスが無い」ではなく「ファイルを開けない」（`include(…)`）になる。土台の時点で `composer dump-autoload --optimize` をした写しで測った（WT も土台の時点の読み込み表なので、計画の文言どおりに落ちる）
- 測り直した: 全件 7 段（base 3334 → Task 6 で 3380 本。どの段も前の土台より 2 本少ないだけ）・テストを先に入れたときの赤 6 段（6 段とも赤・落ちるテストは前と同じ）・変異 60 通り（Task 7 の表）
- 変異 60 通り: **59 通りを検出・1 通り（S15）は等価・SKIP 0**。前の土台の途中までの結果（47 通り）とは、落ちたテストの集合が 47 通りとも同じ。残りの 13 通り（P15〜P19・R01〜R08）は、落ちた理由の文言まで意図どおり（例: P18 は `Cannot find TTF TrueType font file "ipaexg.ttf"`・P16 と P19 は打ったタグが紙面にそのまま出た）

### 本番の読み取り（利用者の了承のあと・読むだけ）

| 見たこと | 結果 |
|---|---|
| PHP | `/usr/local/php/8.3/bin/php` は 8.3.32（CLI） |
| メモリと時間の上限（CLI の php.ini `/usr/local/php/8.3/etc/php.ini`） | `memory_limit=128M`・`max_execution_time=0`（CLI）・`upload_max_filesize=5M`・`post_max_size=8M` |
| Web の php.ini（`~/www/php.ini`・48 バイト・2023-02-13） | `upload_max_filesize = 512M`・`post_max_size = 512M` の 2 行だけ（メモリは変えていない） |
| PHP の拡張 | `bcmath gd iconv mbstring xml zip zlib`（mPDF が要る gd・mbstring と、フォントを圧縮して埋め込む zlib がある） |
| 置き場所 | `~/apps/manage/storage/framework`・`storage/framework/cache`・`storage/app` は利用者が書ける（mPDF の一時ファイルの置き場） |
| 容量 | `/home` の空き 1.9T |

### 部品とフォント

- `composer show mpdf/mpdf --all`: 最新 v8.3.1・`php ^5.6 || … || ~8.3.0 || ~8.4.0 || ~8.5.0`・`ext-gd`・`ext-mbstring`・**license GPL-2.0-only**
- 試作で `composer require "mpdf/mpdf:^8.3"`: 増えたのは `mpdf/mpdf v8.3.1`・`mpdf/psr-http-message-shim v2.0.1`・`mpdf/psr-log-aware-trait v3.0.0`・`setasign/fpdi v2.6.8`・`paragonie/random_compat v9.99.100` の 5 つだけ（既にある部品の版は 1 つも変わらない。開発用だった `myclabs/deep-copy` は本番用の側へ移る）。`vendor/mpdf` は 93MB（うち同梱のフォント `ttfonts` が 87MB）
- `composer audit` は既にある 12 の部品（`laravel/framework`・`symfony/*`・`guzzlehttp/*`・`league/*` など）に既知の弱点の知らせ 42 件を出した。mPDF と付属の部品には無い。段階4 とは別件（利用者に伝える）
- フォント: moji.or.jp の `ipaexg00401.zip`（4,166,255 バイト）・`ipaexm00401.zip`（5,580,442 バイト）。どちらも 2020-01-23 の版 004.01。中身は ttf・`IPA_Font_License_Agreement_v1.0.txt`（2 つで同じ）・Readme。zip の SHA-256 は `~/.claude/plans/approval-phase3-tasks/4a/fonts/SHA256SUMS`

### 試し（Laravel を通さず mPDF だけ。`~/.claude/plans/approval-phase3-tasks/4a/spike/spike.php`）

| 申請 | 印の描き方 | 時間 | ピークのメモリ | 大きさ | ページ |
|---|---|---|---|---|---|
| 軽い（本文 600 字） | `<svg>` をそのまま | 0.17 秒 | 26MB | 79KB | 1 |
| 軽い | `<img src="data:image/svg+xml;…">` | 0.10 秒 | 26MB | 79KB | 1 |
| 重い（本文 2 万字・添付 20・コメント 2 千字）・本文を 1 つの枠 | `<svg>` | 0.81 秒 | 34MB | 82KB | **3（本文の 444 行が 1 ページに縮んだ）** |
| 重い・**本文を罫線の 1 行＝表の 1 行** | `<svg>` | 0.52 秒 | 30MB | 97KB | 12 |

- `<svg>` の `font-family="ipaexm"` の文字は IPAex 明朝で描かれた（画像にして確かめた）。`pdftotext` で日本語の文字を取り出せる（テストでは使わない。手元にしか無い道具のため）
- Laravel を通した試し（テストの中で `ApprovalPdf::sheet()`）: 軽い 0.13〜0.20 秒・重い（本文 2 万字）0.43 秒・20 ページ・ピーク 62.5〜68.5MB（PHPUnit とテストの準備の分を含む）

### 試作で見つけて直したこと（計画の形に入っている）

- 紙面の「可・条可・差戻・否」を文中の `<span>` の枠で描いたら、mPDF が「可」「差戻」の枠を描かなかった → 小さな表（`table.marks`）で組んで、すべての枠が出ることを画像で確かめた
- 「審査部門」の見出しの欄が 18mm では「審査部」「門」と折り返した → 22mm
- 既存の `RequestActionTest::test_comments_and_names_are_escaped` が、空白の無い氏名 `<b>部門</b>長` のエスケープした形を 3 → 5 と数えた（印の文字と読み上げの名前）→ §0.9 のとおり直した
- 変異を流している途中で、生き残りから 3 つ直した: ①⑦ の自前の全角の空白の外し方は、前処理（`TrimStrings`）がすでに外していて要らなかった（U02）→ 外した ②部門の略称が長いときに上段が縮むことを見るテストが無かった（V02）→ Task 3 の `test_a_long_department_name_is_drawn_smaller_in_the_top_row` ③（読み直して）欄の無い更新で印の文字が消えた → 欄が送られたときだけ保存し、Task 4 の `test_an_update_without_the_field_keeps_the_stamp_text`（変異 U09）。直したあとの最終の試作で、全件・redfirst・変異をすべて測り直した（はじめの測定は `4a/measure/mutations-before-rebuild.jsonl`）
- 変異の一覧を作りながら、テストが気づけない壊し方を見つけて先にテストを足した: 紙面の HTML（○・3 つの印・条件・罫線の行の数）とページの下（Task 5 の `test_the_sheet_draws_the_marks_the_stamps_and_the_footer`）・部門長確認の省略（`test_the_sheet_says_the_head_step_was_skipped`）・コメントのエスケープと改行（`test_the_sheet_escapes_what_people_typed`）・発信日と受付日が別の瞬間から来る（`test_the_issue_date_and_the_receipt_date_come_from_different_moments`）・末尾の空行（`test_the_body_is_split_into_ruled_rows`）・印の文字の大きさ（Task 3 の `test_the_stamp_is_drawn_with_the_fitted_sizes`）・下書きの詳細にボタンを出さない（Task 6 の `test_the_detail_has_the_link_once_the_request_is_submitted`）

### 手元のブラウザ（計画を書く段階・試作の写し・使い捨ての SQLite・Playwright）

試しのデータは Task 8 の Step 2 と同じ（部門長の印に使う文字「長谷川」・審査担当者の氏名を「高橋一郎」・短い申請は条可まで・長い本文の申請は部門長の承認まで・下書き 1 件）。写真と PDF は `/Users/masanori/site/approval/screenshots-4a/`（写しは `~/.claude/plans/approval-phase3-tasks/4a/screens/`）。

| # | 見たこと | 結果 |
|---|---|---|
| 1 | 申請の詳細の回る順番（1440・375px） | 3 段の右端に印（「住宅 / R8.10.5 / 長谷川」「総務 / R8.10.5 / 高橋一郎」「社長 / R8.10.5 / 社長」）。4 文字の「高橋一郎」は小さく収まる。左の文字の並びは前のまま。`main` のはみ出し 0 |
| 2 | 見出しの行の「PDF を出力」 | 右端にある・下書きの詳細には無い |
| 3 | PDF の取り出し（決裁の管理者のブラウザから `page.request`） | 短い申請と長い申請は `200`・`application/pdf`・`inline; filename=approval-R8-J-001.pdf; filename*=utf-8''決裁申請書_R8-J-001.pdf`（番号の前は `approval-2.pdf`・`決裁申請書_申請2.pdf`）。下書きは `404`。記録は 2 行（`kind = pdf`） |
| 4 | PDF（短い申請・1 ページ） | 紙面の並びのとおり・○は「条可」と「可」・3 つの印・部門長のコメントは承認の印の下・本文の罫線 11 行・ページの下に出力者と「1 / 1」 |
| 5 | PDF（長い本文・9 ページ） | 文字を縮めずに次のページへ続く・続きのページにも左右の線と罫線・各ページの下にページ番号 |
| 6 | ⑦ の一覧（1440・375px） | 氏名の左に見本。**はじめ 34px にしたら下段の文字が読めなかった → 48px にした**（§0.10 の 3）。所属の無い人は上段が空。画面の説明を「決裁の所属部門と印に使う文字は全員について変えられます。」に直した（はじめは所属部門だけだった）。`main` のはみ出し 0 |
| 7 | ⑦ の編集の小窓（1440・375px） | 「印に使う文字」に今の値「長谷川」・5 文字目は打てない（`maxlength`）・氏名を直せない人への案内「決裁の所属部門と印に使う文字だけ変えられます。」・375px で小窓が画面に収まる |
| 8 | コンソール | エラーと警告 0 件（ログイン画面の `autocomplete` の VERBOSE の知らせだけ。以前から） |
| 9 | コンパイル済みビューの `php -l` | 298 本・INVALID 0 件 |

### 使い捨ての MySQL 8.4.11 の `SHOW CREATE TABLE`（足した列の行だけ。SQL ファイルで足した形と migration で足した形が同じ）

```sql
-- approval_members
  `stamp_text` varchar(4) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '印に使う文字（空なら氏名の最初の空白より前）',
-- approval_steps
  `stamp_label` varchar(6) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '押したときの印の上段（部門の略称か「社長」）',
  `stamp_text` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '押したときの印の下段（印に使う文字）',
```

全文は `~/.claude/plans/approval-phase3-tasks/4a/measure/mysql-show-create.txt`。4a のテスト（`tests/Feature/Approval/Phase4`）は MySQL で `OK (44 tests, 170 assertions)`。

## Task 7 の実測記録（2026-10-05・実装のあと）

実装したコードに、計画の表の 60 行（カナリア 1 + 変異 59）と、実装の段の点検で足した 7 通り（V07・U10・P20〜P24）を合わせた 67 通り（カナリア 1 + 変異 66）を、`~/.claude/plans/approval-phase3-tasks/4a/mutate.py` で 1 つずつ当てて測った。変異を当てたのは WT の `HEAD` の写し（`<scratchpad>/p4a-mutation`）だけで、WT のファイルには当てていない。

### 測った条件

| 項目 | 内容 |
|---|---|
| 測った HEAD | **`b94ece7d`**（`fix(approval): 決裁申請書の PDF の件名とコメントも長い URL で縮まないようにする`）の写し（`git archive HEAD` + `vendor` の複製）。載っている点検の手直し: Task 3 の `a7220226`、Task 5 の `2a3f941b`・`100bf0c1`・`3a308425`・`013dfc42`・`b94ece7d` |
| 流した範囲 | `mutate.py` の `TARGET`（決裁のテスト〈`tests/Feature/Approval`・`tests/Unit/Approval`〉・走査テスト 4 本）= **961 本**（計画の試作は 958 本。3 本多い理由は下の「表との違い」の 1）。変異なしの写しで `OK (961 tests, 7172 assertions)`（試作は 7152） |
| 全件（Step 1・`b94ece7d`） | はじめの `git status --porcelain` は空・**`OK (3383 tests, 23956 assertions)`**（2 分 38 秒） |
| 全件（最終・U10 のテストを足した `b5c68f20`） | **`OK (3383 tests, 23958 assertions)`**（本数は同じ。既存のテストの中に確かめを足したので assertions だけ +2） |
| `--check` | `NG` の行なし・**`checked 67`**（計画の「60」より 7 多い。V07・U10・P20〜P24） |
| カナリア | **赤になった**: `Tests: 961, Assertions: 6441, Failures: 80.`（計画の試作と同じ 80 本。80 本とも `Expected response status code [200] but received 500.`。落ちた理由は、当て直して確かめたところ `Undefined variable $canaryUndefinedVariable`〈`show.blade.php`〉＝測定は写しのコードを読んでいる） |
| 所要 | 67 通りで約 51 分（1 通り 38〜82 秒。いちばん長いのは P15 の 82 秒）。同じ時に Task 8 の担当の画面の確かめと WT の全件が動いていたので、秒数は目安。結果の jsonl: `~/.claude/plans/approval-phase3-tasks/4a/measure/mutations-impl.jsonl`（U10 のテストを足したあとの再測定は `mutations-impl-u10-after.jsonl`） |

### 結果

**67 行 = 検出 64・カナリア 1（赤が正しい）・等価 1（S15）・当初検出漏れ→追加で検出 1（U10）・SKIP 0・見逃し 0（等価でないのに緑 0）。**

- **検出（64）**: T01〜T04・S01〜S14・V01〜V07・U01・U03・U05〜U09・P01〜P24・R01〜R08。計画の表の 58 通りは、どれも表と同じく赤。表に無かった 6 通り（V07・P20〜P24）も赤
- **当初検出漏れ→追加で検出（1）**: **U10**（編集の小窓を印に使う文字が空のまま開く）。はじめは緑（`OK (961 tests, 7172 assertions)`）。計画の見込みどおりの穴で、下の「U10 を足したわけ」のとおりテストを 1 か所足して塞いだ
- **等価（1）**: **S15**（判断していない段階も印を作る）= `OK (961 tests, 7172 assertions)`。計画の見込みどおり。理由は下の「S15 が等価なわけ」
- **SKIP（0）**: 無し（`mutate.py` は 67 通りの全部で、当てる文字がちょうど 1 回見つかった）

### 67 通りの実測（`b94ece7d` の写し）

「落ちた行」は、落ちたテストだけを当て直して取った、失敗した assertion の行（`テストのファイル:行`。同じ行は 1 つにまとめた）。T02 は落ちた 322 本のうち先頭の 6 本だけ当て直した。

| # | 変異（ファイル） | 結果（流した 961 本） | 落ちたテスト（実測） | 落ちた行 | 判定 |
|---|---|---|---|---|---|
| CANARY | カナリア: 申請の詳細の見出しに未定義の変数（`show.blade.php`） | Tests: 961, Assertions: 6441, Failures: 80. | 80 本（RequestActionTest 37・AdminRequestsTest 16・RequestChangesTest 11・RequestFormTest 9・RequestAttachmentTest 3 ほか） | `AdminRequestsTest.php:49 ほか（画面を開く行）` | カナリア（赤が正しい） |
| T01 | SQL の印に使う文字を 5 文字に（`2026-10-03-approval-phase4a.sql`） | Tests: 961, Assertions: 7172, Failures: 1. | `test_the_sql_and_the_migration_add_the_same_columns` | `Phase4aTablesTest.php:82` | 検出 |
| T02 | migration の段階の印の下段を NOT NULL に（`2026_10_03_000001_add_approval_phase4a_columns.php`） | Tests: 961, Assertions: 3241, Errors: 342, Failures: 2. | `test_a_circulating_request_stays_with_its_review_department`、`test_a_colleague_in_the_same_department_does_not_see`、`test_a_complete_request_can_be_submitted_from_the_form`、`test_a_conditional_approval_asks_the_applicant_to_confirm`、`test_a_conditional_approval_is_confirmed_by_the_applicant`、`test_a_conditional_approval_shows_the_condition_and_when_it_was_confirmed` ほか 316 本 | `BuildsApprovalFixtures.php:157`・`RequestFormTest.php:172` | 検出 |
| T03 | 段階の印の控えを書けなくする（fillable から外す）（`ApprovalStep.php`） | Tests: 961, Assertions: 7171, Failures: 3. | `test_changing_the_settings_later_does_not_change_the_stamp`、`test_each_judgement_records_its_stamp`、`test_the_stamp_columns_are_fillable` | `Phase4aTablesTest.php:95`・`StampTest.php:87`・`StampTest.php:110` | 検出 |
| T04 | 利用者の印に使う文字を書けなくする（fillable から外す）（`ApprovalMember.php`） | Tests: 961, Assertions: 7154, Failures: 13. | `test_an_update_without_the_field_keeps_the_stamp_text`、`test_anyone_can_get_a_stamp_text_even_when_the_name_cannot_be_edited`、`test_clearing_the_text_goes_back_to_the_name`、`test_saving_the_same_text_again_records_nothing`、`test_spaces_around_the_text_are_removed_including_full_width_ones`、`test_the_admin_sets_the_stamp_text_before_launch` ほか 7 本 | `Phase4aTablesTest.php:89`・`StampDisplayTest.php:48`・`StampDisplayTest.php:63` ほか 10 か所 | 検出 |
| S01 | 全角の空白で区切らない（`StampText.php`） | Tests: 961, Assertions: 7169, Failures: 1. | `test_the_default_text_is_the_name_before_the_first_space` | `StampTest.php:56` | 検出 |
| S02 | 氏名の前後の空白を外さない（`StampText.php`） | Tests: 961, Assertions: 7170, Failures: 1. | `test_the_default_text_is_the_name_before_the_first_space` | `StampTest.php:57` | 検出 |
| S03 | 印に使う文字を見ない（`StampText.php`） | Tests: 961, Assertions: 7160, Failures: 6. | `test_the_list_shows_a_preview_of_each_stamp_and_the_edit_form_has_the_field`、`test_the_stamp_has_the_three_rows_in_the_stamp_color`、`test_the_stamp_is_drawn_with_the_fitted_sizes`、`test_the_stamp_uses_the_text_set_at_the_time_of_the_judgement`、`test_the_text_is_escaped`、`test_the_text_set_by_the_admin_wins_over_the_name` | `StampDisplayTest.php:48`・`StampDisplayTest.php:63`・`StampDisplayTest.php:94` ほか 3 か所 | 検出 |
| S04 | 空の印に使う文字を既定に戻さない（`StampText.php`） | Tests: 961, Assertions: 7172, Failures: 1. | `test_the_text_set_by_the_admin_wins_over_the_name` | `StampTest.php:71` | 検出 |
| S05 | 社長の上段を「社長」にしない（`StampText.php`） | Tests: 961, Assertions: 7157, Failures: 3. | `test_a_decided_request_has_the_number_the_marks_and_the_three_stamps`、`test_each_judgement_records_its_stamp`、`test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | `PdfSheetTest.php:97`・`PdfSheetTest.php:270`・`StampTest.php:86` | 検出 |
| S06 | 判断のときに下段を控えない（`Workflow.php`） | Tests: 961, Assertions: 7172, Failures: 2. | `test_changing_the_settings_later_does_not_change_the_stamp`、`test_each_judgement_records_its_stamp` | `StampTest.php:87`・`StampTest.php:110` | 検出 |
| S07 | 判断のときに上段を控えない（`Workflow.php`） | Tests: 961, Assertions: 7172, Failures: 2. | `test_changing_the_settings_later_does_not_change_the_stamp`、`test_each_judgement_records_its_stamp` | `StampTest.php:87`・`StampTest.php:110` | 検出 |
| S08 | 取り消しで印の控えを消さない（`Workflow.php`） | Tests: 961, Assertions: 7172, Failures: 1. | `test_undo_removes_the_stamp_with_the_judgement` | `StampTest.php:135` | 検出 |
| S09 | 控えた上段でなく今の部門の略称を出す（`Stamp.php`） | Tests: 961, Assertions: 7172, Failures: 1. | `test_changing_the_settings_later_does_not_change_the_stamp` | `StampTest.php:110` | 検出 |
| S10 | 控えた下段でなく今の印に使う文字を出す（`Stamp.php`） | Tests: 961, Assertions: 7172, Failures: 1. | `test_changing_the_settings_later_does_not_change_the_stamp` | `StampTest.php:110` | 検出 |
| S11 | 和暦の日付を UTC で作る（`Stamp.php`） | Tests: 961, Assertions: 7156, Failures: 7. | `test_a_judgement_without_a_recorded_stamp_is_drawn_from_the_current_settings`、`test_a_return_is_stamped_too`、`test_changing_the_settings_later_does_not_change_the_stamp`、`test_each_judgement_records_its_stamp`、`test_the_date_is_in_the_japanese_era_of_the_calendar_day_in_japan`、`test_the_sheet_draws_the_marks_the_stamps_and_the_footer` ほか 1 本 | `PdfSheetTest.php:270`・`StampTest.php:84`・`StampTest.php:97` ほか 4 か所 | 検出 |
| S12 | 令和の初日を平成にする（`Stamp.php`） | Tests: 961, Assertions: 7170, Failures: 1. | `test_the_date_is_in_the_japanese_era_of_the_calendar_day_in_japan` | `StampTest.php:161` | 検出 |
| S13 | 令和の年を 1 つずらす（`Stamp.php`） | Tests: 961, Assertions: 7144, Failures: 11. | `test_a_judgement_without_a_recorded_stamp_is_drawn_from_the_current_settings`、`test_a_return_is_stamped_too`、`test_changing_the_settings_later_does_not_change_the_stamp`、`test_each_judgement_records_its_stamp`、`test_the_date_is_in_the_japanese_era_of_the_calendar_day_in_japan`、`test_the_department_short_name_in_the_top_row_is_escaped` ほか 5 本 | `PdfSheetTest.php:270`・`StampDisplayTest.php:48`・`StampDisplayTest.php:77` ほか 8 か所 | 検出 |
| S14 | ⑦ の見本の上段を空にする（`Stamp.php`） | Tests: 961, Assertions: 7168, Failures: 1. | `test_the_list_shows_a_preview_of_each_stamp_and_the_edit_form_has_the_field` | `StampTextSettingTest.php:141` | 検出 |
| S15 | 判断していない段階も印を作る（状態を見ない）（`Stamp.php`） | OK (961 tests, 7172 assertions) | — | — | 等価（下の「S15 が等価なわけ」） |
| V01 | 下段をエスケープしない（`StampSvg.php`） | Tests: 961, Assertions: 7161, Failures: 3. | `test_comments_and_names_are_escaped`、`test_the_sheet_escapes_what_people_typed`、`test_the_text_is_escaped` | `RequestActionTest.php:663`・`PdfSheetTest.php:306`・`StampDisplayTest.php:62` | 検出 |
| V02 | 上段を縮めない（`StampSvg.php`） | Tests: 961, Assertions: 7172, Failures: 1. | `test_a_long_department_name_is_drawn_smaller_in_the_top_row` | `StampDisplayTest.php:106` | 検出 |
| V03 | 下段を縮めない（`StampSvg.php`） | Tests: 961, Assertions: 7172, Failures: 1. | `test_the_stamp_is_drawn_with_the_fitted_sizes` | `StampDisplayTest.php:94` | 検出 |
| V04 | 文字の大きさの下限を外す（`StampSvg.php`） | Tests: 961, Assertions: 7171, Failures: 1. | `test_longer_text_is_drawn_smaller_to_stay_inside_the_stamp` | `StampDisplayTest.php:85` | 検出 |
| V05 | 申請の詳細に印を出さない（`_steps.blade.php`） | Tests: 961, Assertions: 7171, Failures: 2. | `test_comments_and_names_are_escaped`、`test_the_detail_shows_the_stamp_beside_each_judged_step` | `RequestActionTest.php:666`・`StampDisplayTest.php:118` | 検出 |
| V06 | 読み上げの名前をエスケープしない（`StampSvg.php`） | Tests: 961, Assertions: 7159, Failures: 4. | `test_comments_and_names_are_escaped`、`test_the_department_short_name_in_the_top_row_is_escaped`、`test_the_sheet_escapes_what_people_typed`、`test_the_text_is_escaped` | `RequestActionTest.php:663`・`PdfSheetTest.php:306`・`StampDisplayTest.php:62` ほか 1 か所 | 検出 |
| V07 | 上段（部門の略称）をエスケープしない（Task 3 の点検で足した）（`StampSvg.php`） | Tests: 961, Assertions: 7170, Failures: 1. | `test_the_department_short_name_in_the_top_row_is_escaped` | `StampDisplayTest.php:75` | 検出 |
| U01 | 5 文字を受け付ける（`UserController.php`） | Tests: 961, Assertions: 7169, Failures: 1. | `test_more_than_four_characters_are_refused` | `StampTextSettingTest.php:101` | 検出 |
| U03 | 設定の記録に残さない（`UserController.php`） | Tests: 961, Assertions: 7172, Failures: 2. | `test_clearing_the_text_goes_back_to_the_name`、`test_the_admin_sets_the_stamp_text_before_launch` | `StampTextSettingTest.php:56`・`StampTextSettingTest.php:83` | 検出 |
| U05 | 変わっていなくても記録する（`UserController.php`） | Tests: 961, Assertions: 7172, Failures: 1. | `test_saving_the_same_text_again_records_nothing` | `StampTextSettingTest.php:115` | 検出 |
| U06 | 氏名を直せる人だけ印に使う文字を直せる（`UserController.php`） | Tests: 961, Assertions: 7169, Errors: 1. | `test_anyone_can_get_a_stamp_text_even_when_the_name_cannot_be_edited` | `StampTextSettingTest.php:69` | 検出 |
| U09 | 欄が送られていない更新で印に使う文字を消す（`UserController.php`） | Tests: 961, Assertions: 7171, Failures: 1. | `test_an_update_without_the_field_keeps_the_stamp_text` | `StampTextSettingTest.php:128` | 検出 |
| U07 | ⑦ に印の見本を出さない（`index.blade.php`） | Tests: 961, Assertions: 7168, Failures: 1. | `test_the_list_shows_a_preview_of_each_stamp_and_the_edit_form_has_the_field` | `StampTextSettingTest.php:141` | 検出 |
| U08 | 編集できない人への案内に印を入れない（`index.blade.php`） | Tests: 961, Assertions: 7171, Failures: 1. | `test_the_list_shows_a_preview_of_each_stamp_and_the_edit_form_has_the_field` | `StampTextSettingTest.php:144` | 検出 |
| U10 | 編集の小窓を印に使う文字が空のまま開く（保存すると消える。Task 4 の点検で足した）（`index.blade.php`） | OK (961 tests, 7172 assertions) | — | — | 当初検出漏れ→追加で検出（下の「U10 を足したわけ」） |
| P01 | 条可の欄を可にする（`PdfSheet.php`） | Tests: 961, Assertions: 7158, Failures: 2. | `test_a_conditional_approval_shows_the_condition_and_when_it_was_confirmed`、`test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | `PdfSheetTest.php:139`・`PdfSheetTest.php:263` | 検出 |
| P02 | 保留の意見を否の欄にする（`PdfSheet.php`） | Tests: 961, Assertions: 7154, Failures: 2. | `test_a_decided_request_has_the_number_the_marks_and_the_three_stamps`、`test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | `PdfSheetTest.php:96`・`PdfSheetTest.php:265` | 検出 |
| P03 | 受付日を発信日にする（`PdfSheet.php`） | Tests: 961, Assertions: 7170, Failures: 2. | `test_before_the_decision_the_number_date_and_marks_are_blank`、`test_the_issue_date_and_the_receipt_date_come_from_different_moments` | `PdfSheetTest.php:116`・`PdfSheetTest.php:127` | 検出 |
| P04 | 条件確認の日時を決裁の日時にする（`PdfSheet.php`） | Tests: 961, Assertions: 7172, Failures: 1. | `test_a_conditional_approval_shows_the_condition_and_when_it_was_confirmed` | `PdfSheetTest.php:144` | 検出 |
| P05 | 件名を申請の今の値から出す（最後に提出した控えを使わない）（`PdfSheet.php`） | Tests: 961, Assertions: 7171, Failures: 1. | `test_others_see_the_last_submitted_content_while_the_applicant_edits` | `PdfSheetTest.php:171` | 検出 |
| P06 | 長い行を分けない（`PdfSheet.php`） | Tests: 961, Assertions: 7170, Failures: 1. | `test_the_body_is_split_into_ruled_rows` | `PdfSheetTest.php:193` | 検出 |
| P07 | 紙の行数まで埋めない（`PdfSheet.php`） | Tests: 961, Assertions: 7166, Failures: 2. | `test_the_body_is_split_into_ruled_rows`、`test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | `PdfSheetTest.php:189`・`PdfSheetTest.php:276` | 検出 |
| P08 | 末尾の空行を落とさない（`PdfSheet.php`） | Tests: 961, Assertions: 7172, Failures: 1. | `test_the_body_is_split_into_ruled_rows` | `PdfSheetTest.php:197` | 検出 |
| P09 | 部門長確認の省略を出さない（`PdfSheet.php`） | Tests: 961, Assertions: 7170, Failures: 2. | `test_a_skipped_head_step_is_marked_as_skipped`、`test_the_sheet_says_the_head_step_was_skipped` | `PdfSheetTest.php:183`・`PdfSheetTest.php:291` | 検出 |
| P10 | 出力の日時を UTC で出す（`PdfSheet.php`） | Tests: 961, Assertions: 7169, Failures: 2. | `test_a_decided_request_has_the_number_the_marks_and_the_three_stamps`、`test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | `PdfSheetTest.php:101`・`PdfSheetTest.php:279` | 検出 |
| P11 | 番号の前のファイル名に申請の番号を入れない（`PdfSheet.php`） | Tests: 961, Assertions: 7172, Failures: 1. | `test_before_the_decision_the_number_date_and_marks_are_blank` | `PdfSheetTest.php:129` | 検出 |
| P12 | 判断の欄に朱の枠を付けない（`pdf.blade.php`） | Tests: 961, Assertions: 7159, Failures: 1. | `test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | `PdfSheetTest.php:263` | 検出 |
| P13 | 紙面に社長の印を出さない（`pdf.blade.php`） | Tests: 961, Assertions: 7164, Failures: 1. | `test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | `PdfSheetTest.php:270` | 検出 |
| P14 | 紙面に部門長確認の省略を出さない（`pdf.blade.php`） | Tests: 961, Assertions: 7171, Failures: 1. | `test_the_sheet_says_the_head_step_was_skipped` | `PdfSheetTest.php:291` | 検出 |
| P15 | 本文を 1 つの枠に入れる（mPDF が縮める）（`pdf.blade.php`） | Tests: 961, Assertions: 7170, Failures: 2. | `test_the_pdf_is_made_with_the_bundled_fonts_and_flows_onto_more_pages`、`test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | `PdfSheetTest.php:210`・`PdfSheetTest.php:276` | 検出 |
| P16 | 決裁者コメントをエスケープしない（`pdf.blade.php`） | Tests: 961, Assertions: 7166, Failures: 1. | `test_the_sheet_escapes_what_people_typed` | `PdfSheetTest.php:307` | 検出 |
| P17 | ページの下に出力者を出さない（`_pdf_footer.blade.php`） | Tests: 961, Assertions: 7171, Failures: 1. | `test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | `PdfSheetTest.php:279` | 検出 |
| P18 | 同梱のフォントの置き場所を渡さない（`ApprovalPdf.php`） | Tests: 961, Assertions: 7152, Errors: 3, Failures: 1. | `test_a_long_word_without_spaces_does_not_shrink_the_subject_the_comments_or_the_attachments`、`test_a_long_word_without_spaces_in_the_body_does_not_shrink_the_body`、`test_a_viewer_gets_the_pdf_inline_and_it_is_recorded`、`test_the_pdf_is_made_with_the_bundled_fonts_and_flows_onto_more_pages` | `PdfSheetTest.php:205`・`PdfSheetTest.php:221`・`PdfSheetTest.php:230` ほか 1 か所 | 検出 |
| P19 | 件名をエスケープしない（`pdf.blade.php`） | Tests: 961, Assertions: 7163, Failures: 1. | `test_the_sheet_escapes_what_people_typed` | `PdfSheetTest.php:304` | 検出 |
| P20 | 部門長のコメントをエスケープしない（Task 5 の点検で足した）（`pdf.blade.php`） | Tests: 961, Assertions: 7167, Failures: 1. | `test_the_sheet_escapes_what_people_typed` | `PdfSheetTest.php:308` | 検出 |
| P21 | 審査のコメントをエスケープしない（Task 5 の点検で足した）（`pdf.blade.php`） | Tests: 961, Assertions: 7168, Failures: 1. | `test_the_sheet_escapes_what_people_typed` | `PdfSheetTest.php:309` | 検出 |
| P22 | 人が打つ文字の表の長い語を折り返さない（mPDF が表ごと縮める。Task 5 の点検で足し、手直し 2 回目で table.body から table.wrap に）（`pdf.blade.php`） | Tests: 961, Assertions: 7166, Failures: 2. | `test_a_long_word_without_spaces_does_not_shrink_the_subject_the_comments_or_the_attachments`、`test_a_long_word_without_spaces_in_the_body_does_not_shrink_the_body` | `PdfSheetTest.php:223`・`PdfSheetTest.php:249` | 検出 |
| P24 | 決裁の欄の表に折り返しを付けない（社長のコメントの長い URL で決裁の欄ごと縮む。Task 5 の手直し 2 回目で足した）（`pdf.blade.php`） | Tests: 961, Assertions: 7166, Failures: 1. | `test_a_long_word_without_spaces_does_not_shrink_the_subject_the_comments_or_the_attachments` | `PdfSheetTest.php:249` | 検出 |
| P23 | 朱の枠を詳細度の低い .on で書く（黒の枠が勝つ。Task 5 の点検で足した）（`pdf.blade.php`） | Tests: 961, Assertions: 7163, Failures: 1. | `test_the_sheet_draws_the_marks_the_stamps_and_the_footer` | `PdfSheetTest.php:267` | 検出 |
| R01 | 見られる範囲を確かめない（`RequestPdfController.php`） | Tests: 961, Assertions: 7171, Failures: 1. | `test_someone_who_cannot_see_the_request_gets_404_and_nothing_is_recorded` | `RequestPdfTest.php:75` | 検出 |
| R02 | 下書きも出す（`RequestPdfController.php`） | Tests: 961, Assertions: 7171, Failures: 1. | `test_a_draft_is_not_printed_even_for_the_applicant` | `RequestPdfTest.php:86` | 検出 |
| R03 | 記録の種類を添付にする（`RequestPdfController.php`） | Tests: 961, Assertions: 7172, Failures: 1. | `test_a_viewer_gets_the_pdf_inline_and_it_is_recorded` | `RequestPdfTest.php:62` | 検出 |
| R04 | 作れなかったことを warning で書く（本番の laravel.log に残らない）（`RequestPdfController.php`） | Tests: 961, Assertions: 7171, Errors: 1. | `test_when_the_pdf_cannot_be_made_the_detail_says_so_and_the_log_has_the_error` | `RequestPdfTest.php:129` | 検出 |
| R05 | ブラウザで開かずダウンロードにする（`RequestPdfController.php`） | Tests: 961, Assertions: 7170, Failures: 1. | `test_a_viewer_gets_the_pdf_inline_and_it_is_recorded` | `RequestPdfTest.php:55` | 検出 |
| R06 | nosniff を付けない（`RequestPdfController.php`） | Tests: 961, Assertions: 7169, Failures: 1. | `test_a_viewer_gets_the_pdf_inline_and_it_is_recorded` | `RequestPdfTest.php:54` | 検出 |
| R07 | 下書きの詳細にもボタンを出す（`show.blade.php`） | Tests: 961, Assertions: 7172, Failures: 1. | `test_the_detail_has_the_link_once_the_request_is_submitted` | `RequestPdfTest.php:114` | 検出 |
| R08 | ASCII の代わりの名前に番号を入れない（`RequestPdfController.php`） | Tests: 961, Assertions: 7170, Failures: 1. | `test_a_viewer_gets_the_pdf_inline_and_it_is_recorded` | `RequestPdfTest.php:55` | 検出 |

### 表（計画の試作の実測）との違い

計画の表の「落ちたテスト」の名前を機械で読み、実測の集合と照合した（60 行）。**55 行は集合も本数も表と同じ**（CANARY の 80 本、緑の S15 を含む）。違う 5 行（T02・S13・V06・P15・P18）は、**表のテストを全部含み、表に無いテストが増えただけ**（表のテストが落ちなくなったものは無い）。増えたのは、試作のあとに点検の手直しで足されたテストのため。

1. **本数が 3 本多い（958 → 961）**。足された 3 本は、Task 3 の `a7220226` の `StampDisplayTest::test_the_department_short_name_in_the_top_row_is_escaped`、Task 5 の `100bf0c1` の `PdfSheetTest::test_a_long_word_without_spaces_in_the_body_does_not_shrink_the_body`、`b94ece7d` の `PdfSheetTest::test_a_long_word_without_spaces_does_not_shrink_the_subject_the_comments_or_the_attachments`。assertions も +20（変異なしの写しで 7152 → 7172）。`TARGET` に入っているので全部の変異で流れる
2. **T02 は 319 → 322 本**: 増えた 3 本は上の 3 本（どれも段階を作るので、`NOT NULL constraint failed: approval_steps.stamp_text` で落ちる）
3. **S13 は 10 → 11 本**: 増えたのは `test_the_department_short_name_in_the_top_row_is_escaped`（`aria-label="…… R8.10.5 部門 の印"` の日付を見る）
4. **V06 は 3 → 4 本**: 増えたのは同じ `test_the_department_short_name_in_the_top_row_is_escaped`（読み上げの名前は `e(trim(上段 日付 下段 の印))` なので、上段の `<i>` がエスケープされないと落ちる。V07 の確かめと重なる）
5. **P15 は 1 → 2 本**: 増えたのは `test_the_pdf_is_made_with_the_bundled_fonts_and_flows_onto_more_pages`。`100bf0c1` で下限を「2 ページ以上」から「4 ページ以上」にした（`task-5-review.md` の 7）ので、本文を 1 つの枠に入れて mPDF が縮めると 4 ページに届かず落ちる。**守りが強くなった**
6. **P18 は 2 → 4 本**: 増えたのは、本物の PDF を作る新しい長い語の 2 本（`MpdfException: Cannot find TTF TrueType font file "ipaexg.ttf" in configured font directories.`）。P18 の機構（同梱のフォントの置き場所を渡さない）が、本物の PDF を作るテストの全部に効いている
7. **表に無い 7 通り**（V07・U10・P20〜P24）は、計画の時点の見込み（コントローラの補足）どおり: V07・P20〜P24 は赤（落ちたのは上の表のとおり）、U10 だけ緑（穴。塞いだ）
8. 「結果」の列の `Failures`・`Errors` の数も、増えた分だけ表と違う（T02 の Errors 339 → 342 ＝ 上の 3 本、P18 の Errors 1 → 3 ＝ 上の 2 本）。「落ちたテスト」の本数は、計画の表と同じく**クラス名を省いた名前の数**（データセットのあるテストや、別のクラスの同じ名前は 1 本に数える。T02 は 344 件の失敗が 322 本）
9. P12〜P16 などの紙面（`pdf.blade.php`）の変異は、Task 5 の手直しのあとの形に合わせて `mutate.py` の当てる文字を直してある（`--check` で全部 1 回ずつ当たる）。落ちたテストの集合は、P12・P13・P14・P16 は表と同じ、P15 は表のテストに 1 本増えただけ（上の 5）で、手直しで紙面の形が変わっても守りは弱くなっていない

### 落ちたテストの集合と理由の文言が狙いと合うか

- 上のとおり、計画の表の名前は 60 行の全部が実測に含まれる。
- 理由の文言まで確かめるため、**変異ごとに、落ちたテストだけを当て直して「落ちた行」を取った**（上の表の列）。意図と別の機構が落としているものは無かった。例: T01 `文字数の上限が SQL と migration で違う`／T02 `NOT NULL constraint failed: approval_steps.stamp_text`（段階を作る行で落ちる）／S01・S02 の全角の空白と前後の空白の確かめ／S11・S13 の日付（`日本時間 10/5 0:00`・`R8.10.5`）／V01・V06・V07 の `<b>`・`<i>` がそのまま出ない確かめ／V02〜V04 の文字の大きさ（`font-size="11"`・`font-size="15"`・下限 8）／U01 `Session is missing expected key [errors]`／U06 `Attempt to read property "stamp_text" on null`（氏名を直せない人の印に使う文字が保存されない）／P01・P02 の `<td class="mark on">条可</td>`・`保留`／P06〜P08 の罫線の行（長い行を分ける・空行を落とす・紙の行数まで埋める）／P12 `<td class="mark on">条可</td>`／P13 3 つの印／P18 `Cannot find TTF TrueType font file "ipaexg.ttf"`／P20・P21・P16 の `<u>部門長</u>`・`<u>審査</u>`・`<i>見直し</i>` がエスケープされる確かめ／P22・P24 `表の文字は縮まない（折り返す）`／P23 `朱の枠は td.mark の黒の枠より強い選び方で書く`／R04 `Log::shouldHaveReceived('error')`（warning で書くと呼ばれない）／R06 `nosniff` が付かない
- 「落ちた行」は、どの変異でも、その変異が壊す値を見る assertion の行だった。生のメッセージ（`Failed asserting that two arrays are identical.` など）だけでは機構が分からない変異（S05・S09〜S11・T03・T04・U03・U05・P01・P02・P10）は、落ちた行の assertion が狙いの値（印の上段・下段・日付・設定の記録・○の欄・出力の日時）を見ているものだった

### S15 が等価なわけ

`Stamp::forStep()` の `$step->status !== ApprovalStepStatus::Done || $step->acted_at === null` から状態の確かめを外しても、`acted_at` が入っているのは必ず `Done` の段階だけなので、結果が変わらない。`acted_at` を入れるのは `Workflow::finishStep()`（`Done`・`Workflow.php:613`）だけで、`reopenStep()`（待ちに戻す・`Workflow.php:586`）は空に戻し、省略（`Skipped`・`Workflow.php:98` で段階を作るとき）・取り消し（`Cancelled`・`Workflow.php:639` は待ち・まだ届いていない段階だけを取り消す）・まだ届いていない段階は `acted_at` が空のまま。確かめは二重の守りになっていて、片方を外しても振る舞いは変わらない。

### U10 を足したわけ

U10 は、⑦ の一覧の「編集」のボタンが小窓へ渡す値のうち、今の印に使う文字（`'stamp' => $u->approvalMember?->stamp_text ?? ''`）を `''` にする変異。小窓の「印に使う文字」の欄が空のまま開き、そのまま保存すると、設定した印に使う文字が消えて氏名からの既定に戻る（Task 4 の点検で足した変異。計画の時点から、生き残る見込みと分かっていた穴）。既存の `test_the_list_shows_a_preview_of_each_stamp_and_the_edit_form_has_the_field` は、一覧の見本の `aria-label`・欄の名前・案内の文だけを見ていて、`openEdit(…)` に渡る値を見ていなかった。手元の画面の確かめ（Task 8）では見えるが、自動のテストが無かった。

足したテスト（`tests/Feature/Approval/Phase4/StampTextSettingTest.php`・既存のテストの中に確かめ 2 つ + 取り出す補助の関数 1 つ・+20 行。コミット `b5c68f20`）:

```php
        // 編集の小窓へ渡る値にも今の印に使う文字が入っている（空で開くと、そのまま保存して印に使う文字が消える）
        $rows = $this->editRows($html);
        $this->assertSame('花子', $rows[$w['applicant']->id]['stamp'], '編集の小窓は今の印に使う文字を入れて開く');
        $this->assertSame('', $rows[$w['head']->id]['stamp'], '印に使う文字を決めていない人は空');
```

`editRows()` は、一覧の HTML の `openEdit(JSON.parse('…'))` を取り出し、`Js::from()` の書き方（引用符は `\u0022`・バックスラッシュは二重）を戻して JSON として読む。

| 場面 | 結果 |
|---|---|
| 元のテスト + U10（`b94ece7d` の写し） | `OK (961 tests, 7172 assertions)` ＝ **緑（穴）** |
| 足したテスト（変異なしの写し・`StampTextSettingTest`） | 緑: `OK (8 tests, 31 assertions)` |
| 足したテスト + U10（写し・`StampTextSettingTest`） | **赤**: `Tests: 8, Assertions: 30, Failures: 1.`・落ちたのは `test_the_list_shows_a_preview_of_each_stamp_and_the_edit_form_has_the_field` の 1 本だけ。理由は `編集の小窓は今の印に使う文字を入れて開く` `Failed asserting that two strings are identical.`（期待 `'花子'`・実際 `''`・`StampTextSettingTest.php:149`） |
| 足したテスト + U10（`mutate.py`・`TARGET` の 961 本） | **赤**: `Tests: 961, Assertions: 7173, Failures: 1.`・落ちたのは同じ 1 本だけ（`mutations-impl-u10-after.jsonl`） |
| 元に戻した写し（`StampTextSettingTest`） | 緑: `OK (8 tests, 31 assertions)`（ビューは元と `cmp` で一致） |
| WT の全件（足したあと） | `OK (3383 tests, 23958 assertions)` |

### Task 5・Task 3 の点検の手直しで、計画のコードと最後のコードが違うところ

計画（Task 5・Task 3）のコードは、手直しの**前**の形。差分のファイル `~/.claude/plans/approval-phase3-tasks/4a/patches/0005-task05-PDF.patch`（と `0003-task03.patch`）も手直しの前の形のまま。最後のコードは、次の 6 コミットが足した分だけ違う（`git show --stat` で確かめた。コードの変更は `pdf.blade.php` だけで、`PdfSheet.php`・`ApprovalPdf.php` などは計画のまま）:

| コミット | 内容 | ファイル |
|---|---|---|
| `a7220226`（Task 3） | 印の上段の部門の略称（`<`・`&` を含む）がエスケープされることを確かめるテスト（V07 の守り）。コードの変更なし | `StampDisplayTest.php` +14 |
| `2a3f941b`（Task 5） | 社長の印が欄の枠からはみ出さないようにする。決裁の欄を 2 段に分け（上の段: 判断の欄・申請部門・承認、下の段: コメント・印。`td.upper`・`td.lower`・段の間の線は消す）、判断の欄の小さな表を印・コメントと同じセルに入れ子にしない。表に `page-break-inside: avoid` | `pdf.blade.php` +15 −6・`PdfSheetTest.php` +2 |
| `100bf0c1`（Task 5） | 本文が長い URL で縮まないようにする。本文の表に `overflow: wrap`（CSS `table.wrap { overflow: wrap; }`）。長い語のテストと、120 行の本文が 4 ページ以上になるテスト | `pdf.blade.php` +4 −1・`PdfSheetTest.php` +19 −1 |
| `3a308425`（Task 5） | 判断した欄の朱の枠。選んだ欄の CSS を `.on` から `td.mark.on` に（詳細度の高い `td.mark` の黒の枠に負けていた） | `pdf.blade.php` +2 −1・`PdfSheetTest.php` +2 |
| `013dfc42`（Task 5） | 部門長と審査のコメントのエスケープのテスト（P20・P21 の守り）。コードの変更なし | `PdfSheetTest.php` +8 −4 |
| `b94ece7d`（Task 5） | 件名・コメント・添付・審査の表も、人が打つ文字の入る表すべてに `class="wrap"`（決裁の欄の表を含む）を付け、長い語を折り返す。決裁No・日付の表と、入れ子の判断の欄 `table.marks` には付けない（短い決まった文字だけ） | `pdf.blade.php` +10 −9・`PdfSheetTest.php` +59 |

この 6 つのうち、変異テスト（上の 67 通り）で新しく守りを確かめたのは V07（`a7220226`）・P20・P21（`013dfc42`）・P22・P24（`100bf0c1`・`b94ece7d`）・P23（`3a308425`）。`2a3f941b` の 2 段の組み方は、決裁の欄が長いコメントで枠をはみ出さない見た目の確かめ（Task 8 の PDF）で見る。

### 後始末

写し（`<scratchpad>/p4a-mutation`・`<scratchpad>/impl/t07-u10-copy`）は、この記録のコミットのあとで、中身が写しであること（`pwd`・`ls`）を確かめて消す。結果の jsonl は消す前に `~/.claude/plans/approval-phase3-tasks/4a/measure/` に写す。WT の変更は、U10 のテスト（`b5c68f20`）とこの節だけ。

## Task 8 の実測記録（2026-10-05・実装のあと）

- 手元のブラウザでの確認と、利用者に見せる写真と PDF（設計書 §6）。日付は 2026-10-05
- 対象: WT（ブランチ approval-phase3・HEAD `b94ece7d`）の `git archive` の写し（scratchpad の `p4a-browser`）＋使い捨ての SQLite ＋ `artisan serve`（127.0.0.1:8769）。測っている間、WT は読むだけで変えていない（`git status --porcelain` は 0 行）
- ブラウザ: Playwright（Chromium）。ログインは試しのパスワードを画面・記録・引数に出さずに行った（public に一時ファイル → ブラウザ側で読む → 直後に削除。終了時に public に残っていないことを確認）
- 写真と PDF の置き場所: `/Users/masanori/site/approval/screenshots-4a/impl/`（計画の段の `screenshots-4a/*.png` は上書きしていない）。写真は 2 倍の解像度（DPR 2）で撮った。詳細・⑦ の長い画面は、画面の高さを内容に合わせて 1 枚に収めた

### 試しのデータ（Step 2 + 補足）

| 番号 | 中身 | 状態 |
|---|---|---|
| 1 | 短い申請（R8-J-001・部門長 承認 → 審査 可 → 社長 条可） | 条件確認待ち |
| 2 | 長い本文の申請（300 行） | 審査中 |
| 3 | 社長が 8 行の理由で差戻す申請（B 区画の外構工事） | 差戻し中 |
| 4 | 本文の 60 行目に `https://example.com/` ＋ 英数字 200 文字の 1 行がある申請（120 行） | 審査中 |
| 5 | 審査が保留の申請（C 区画の測量。`ApprovalStepResult::Hold` を使用） | 社長決裁待ち |
| 6 | 下書き | 下書き |

ログイン名: 申請者 `A8417`（社員番号）・決裁の管理者・社長・部門長・審査担当者はメール（ファクトリが作った値）。

### Step 3 の結果

幅は 1440 / 375（⑦ と詳細）。PDF は申請者でログインして取得。

| # | 結果 | 見たこと |
|---|---|---|
| 1 | 見た・問題なし | 回る順番の 3 段の右端に赤い丸の印。上段「住宅」「総務」「社長」・中段「R8.10.5」・下段「長谷川」「高橋一郎」（4 文字なので小さく収まる）「社長」。左の並び（段の名前・済み・判断・氏名・日時・コメント）は前のまま。375px でも印は 48px のまま右端に収まり、コメントの箱が押しつぶされない（コメントが 2 行に折り返すだけ）。`detail-1440.png`・`detail-375.png`・`detail-steps-1440.png`・`detail-steps-375.png` |
| 2 | 見た・問題なし | 「PDF を出力」は見出しの行の右端（1440px で右端 1132px＝カードの右端。375px で右端 359px）。`<a target="_blank" rel="noopener" href=".../requests/1/pdf">` で別タブに開く。幅 320px・件名が 4 行に折り返す状態でも、ボタンは右上に残り、`elementFromPoint` で押せることを確認（`detail-header-320-longtitle.png`）。下書き（番号 6）の詳細には「PDF」の文字のボタンが 0 個（`detail-draft-1440.png`） |
| 3 | 見た・問題なし（下の「気づいたこと」の印の位置の件を除く） | 別タブで開く・A4 縦（595.28 × 841.89 pt）・題「決裁申請書」・状態「条件確認待ち ・ 1 回目の提出」・決裁No R8-J-001・決裁日/発信日/受付日 2026年10月5日・決裁欄は「可 / 条可 / 差戻 / 否」の 2 段で、「条可」が朱の枠・決裁者コメント（条件）・社長の印は決裁の欄の枠の中・承認の印（住宅・R8.10.5・長谷川＋コメント「急ぎでお願いします」）・申請部門と申請者名・件名・金額 28,500,000円（税抜）・実施時期・本文の罫線 11 行（文 4 行＋空 7 行）・「（添付ファイル 0 件）」・審査部門（「可」が朱の枠・印は総務／R8.10.5／高橋一郎・コメント）・ページの下「決裁申請システムから出力 2026/10/05 09:15（出力者: 申請 花子）」と「1 / 1」。Content-Type は `application/pdf`・`inline`・ファイル名 `決裁申請書_R8-J-001.pdf`。`pdf-short.pdf`・`pdf-short-1.png` |
| 4 | 見た・問題なし | 長い本文は 9 ページ。文字は縮まず（本文の高さは 10.76pt のまま）、2 ページ目以降も罫線と左右の線が続き、各ページの下に「n / 9」。最後のページは本文の下に添付の行と審査部門の欄。`pdf-long.pdf`・`pdf-long-1〜9.png` |
| 5 | 見た・問題なし | 氏名の左に小さな印の見本。「申請 花子」＝住宅／R8.10.5／申請、「部門 長」（印に使う文字 長谷川）＝（空）／R8.10.5／長谷川、「決裁 管理者」＝（空）／R8.10.5／決裁、「社長 太郎」＝（空）／R8.10.5／社長、空白の無い「高橋一郎」＝氏名全体（4 文字）が小さく入る。日付は今日（R8.10.5）。上段は所属部門が無い人は空。`users-1440.png`・`users-375.png` |
| 6 | 見た・問題なし | 編集の小窓に「印に使う文字」の欄（`maxlength=4`・説明「4 文字まで。空なら氏名の最初の空白より前を使います（例: 山田 太郎 → 山田）。すでに押した印は変わりません」）。「部門 長」の小窓は欄に「長谷川」。キーボードで `abcde` を打つと `abcd`、`あいうえお` を打つと `あいうえ` で止まる（5 文字目が打てない）。欄を空にして保存すると「更新しました。」で、見本が `R8.10.5 部門 の印` に戻る（確認のあと `長谷川` に戻した）。社長に指定された「社長 太郎」の小窓でも欄が打てる（`isEditable`・`isEnabled` とも true・`ab` が入る）。案内「決裁の所属部門と印に使う文字だけ変えられます。」あり。375px の小窓は高さ 731px で画面（812px）に収まり、「保存する」が見える。`users-edit-1440.png`・`users-edit-375.png`・`users-edit-typed5-1440.png`・`users-edit-president-1440.png`・`users-after-clear-1440.png` |
| 7 | 見た・問題なし | `main.scrollWidth === main.clientWidth`（かつ `document` も）を 1800 / 1200 / 375px で、申請者で 11 画面（ホーム・一覧・新規・詳細 1〜6・下書きの編集・お知らせ）、決裁の管理者で 9 画面（ホーム・利用者の管理・CSV の取り込み・部門・種類・催促の設定・進行中の申請・申請の詳細・お知らせ）を測り、**すべて一致**（OVERFLOW 0 件。計 60 画面×幅）。コンソールの error / warning と pageerror・requestfailed は **0 件**（ログイン画面に verbose の「autocomplete 属性」の案内と info の「Autofocus」が出るだけ） |

### PDF の手直しの確認（点検のあとの変更）

- 決裁の欄を 2 段に分けて、社長の印が枠からはみ出さない: 差戻し（8 行の理由・番号 3）で、理由が 10 行に折り返しても社長の印は決裁の欄の枠の中（枠の下辺と印の間にすき間がある）。`pdf-ret8.pdf`・`pdf-ret8-1.png`
- 判断した欄は朱の太い枠: 条可（決裁・番号 1）・差戻（決裁・番号 3）・可（審査・番号 1/3）・保留（審査・番号 5）を確認。`pdf-hold.pdf`・`pdf-hold-1.png`
- 人が打つ文字の表は長い URL を折り返して縮まない: 番号 4 は 2 ページ目の下で URL が 3 行に折り返し、セルの右端（約 550pt）の内側に収まる。本文の文字の高さは URL の行も他の行も 10.76pt で同じ（縮んでいない）。`pdf-url.pdf`・`pdf-url-1〜4.png`（4 ページ）

### 審査の欄の「可」の枠と印（補足で頼まれた確認）

- `pdftoppm -r 200 / 300 / 600` で審査の欄を切り出して見た（`pdf-short-review-zoom-1.png`＝300dpi・`pdf-short-review-gap600-1.png`＝600dpi）。**重なっていない**。「可」の枠の下辺と印の円の上端のすき間は約 2〜3pt（600dpi で約 20px）。円の頂点は中央の「保留」の枠の真下にあるので、「保留」の枠とは特に近い。100dpi の画像では 1px 程度に近づいて接して見える（点検の担当が見たのはこれと思われる）。文字（「可」）は枠の中で読める。**直していない**

### 気づいたこと（直していない・どれも軽微）

1. **PDF の印の文字が円の中心より右に寄っている**（中心から約 3〜4.5pt）。600dpi で、円の中心 x≈278 に対し、日付 R8.10.5 の中心 x≈315・「長谷川」の中心 x≈316・「住宅」の中心 x≈303。そのため 3〜4 文字の名前の最後の字が円の線に触れる（承認の印「長谷川」の「川」・審査の印「高橋一郎」の「郎」。後者は円の線にかかって欠けて見える）。画面の印は中央に収まっている。原因は mPDF が SVG の `text-anchor: middle` を使っていない可能性が高い（未確認）。計画の段の前の紙面（`screenshots-4a/pdf1-1.png`）でも同じで、点検の手直しで起きたものではない。`pdf-short-name-zoom600-1.png`（高橋一郎）・`pdf-short-headstamp-zoom-1.png`（長谷川）・`pdf-short-presstamp-zoom-1.png`・`pdf-short-decision-zoom-1.png`
   - コントローラが直すと決めた（このあとの手直しのコミット）
2. **375px の利用者の管理（⑦）の表で、氏名の列が 1 文字幅に潰れて縦に 1 文字ずつ並ぶ**。表は `min-w-[980px]` で横にスクロールでき（「← スクロールできます →」の案内あり）、ページ全体のはみ出しは無い（上の 7）。計画の段の写真 `screenshots-4a/users-375.png` でも同じ見え方。印を足す前からの可能性が高い（未確認）。印の見本は氏名の左に残る
   - コントローラが直すと決めた（このあとの手直しのコミット）

不具合（機能が壊れている・計画の約束を満たさない）は **無し**。

### Step 5: コンパイル済みビューの lint

`php artisan view:clear && php artisan view:cache` → 成功。`storage/framework/views/*.php` 298 件を `php -l` → **INVALID 0 件**。そのあと `view:clear`。

### 撮った写真と PDF（`/Users/masanori/site/approval/screenshots-4a/impl/`）

- 申請の詳細: `detail-1440.png`・`detail-375.png`（全体）・`detail-steps-1440.png`・`detail-steps-375.png`（回る順番の拡大）・`detail-header-1440.png`・`detail-header-375.png`・`detail-header-320-longtitle.png`（見出しの行）・`detail-draft-1440.png`（下書き・「PDF を出力」なし）
- ⑦: `users-1440.png`・`users-375.png`・`users-edit-1440.png`・`users-edit-375.png`・`users-edit-president-1440.png`・`users-edit-typed5-1440.png`（5 文字打った後）・`users-after-clear-1440.png`（空で保存した後）
- PDF（`pdftoppm -r 100 -png` で全ページを画像にした）: `pdf-short.pdf`（+`-1.png`）・`pdf-long.pdf`（+`-1〜9.png`）・`pdf-ret8.pdf`（+`-1.png`）・`pdf-url.pdf`（+`-1〜4.png`）・`pdf-hold.pdf`（+`-1.png`）
- PDF の拡大: `pdf-short-decision-zoom-1.png`・`pdf-short-review-zoom-1.png`・`pdf-short-review-gap600-1.png`・`pdf-short-name-zoom600-1.png`・`pdf-short-headstamp-zoom-1.png`・`pdf-short-presstamp-zoom-1.png`

### 片付け

- `artisan serve` は止めた（タスクを停止 → ポート 8769 に待ち受けなし・`artisan serve` のプロセスなし・`curl` が接続拒否で確認）
- WT の `git status --porcelain` は空（HEAD は `b94ece7d` のまま）
- 写し（`p4a-browser`）・`approval-phase4a-local.sh`・`approval-phase4a.sqlite`・ログインの状態を書いた一時ファイルは、この記録を書いたあとに消した。`screenshots-4a/impl/` は残している
- この節の書き足しとコミットは、Task 7 の担当のコミット（`b5c68f20`・`fc3be999`）のあとにコントローラの依頼で行った

### 13.x への取り込みの確かめ

`git merge-tree --write-tree --name-only approval-phase3 13.x`（作業ツリーは変えない）。`13.x` は `c5d3fdd5`（`origin/13.x` と同じ）・共通の祖先は `f6891312`・`approval-phase3` は `fc3be999`（この節を足す前）。結果は **終了コード 0・衝突のファイル名の出力なし**（マージ後の tree だけが出た）＝衝突なし。この節のコミットは計画書 1 ファイルだけを変えるが、そのファイルは `13.x` に無いので結果は変わらない（コミットのあとにもう一度流して終了コード 0 を確かめた）。

