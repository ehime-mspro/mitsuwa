# CSV 取込の確認画面から断られると 405 になる件（基幹の取込）— 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (inline execution recommended). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 基幹の CSV 取込で、確認画面（POST の応答）から送って断られたときに `back()`（と入力チェックの既定の戻り先）が POST 専用の URL へ戻って 405 になる経路を、取込の画面へ戻るよう直す。

**Architecture:** 断るときの戻り先を、`back()` ではなく**取込の画面（タブがあればそのタブ）に固定**する。決裁の取込（`Approval\UserImportController`・approval-phase1 の `3ff938db`）と同じ直し方。入力チェックは `try { $request->validate([...]); } catch (ValidationException $e) { throw $e->redirectTo(…); }`、そのほかは `redirect()->route(…)`。

**Tech Stack:** Laravel 12 / PHP 8.3 / PHPUnit（SQLite）

---

## Context

- 決裁 段階1 の修正中に、決裁の CSV 取込で同じ形の 405 を直した（docs/RULES.md Bug #64。approval-phase1 にだけある）。
  そのレビューが「基幹の取込にも同じ形がありうる」と指摘し、別の作業として切り出した（利用者がチップから開始・2026-09-24）
- `url()->previous()`（`back()`・入力チェックの既定の戻り先も同じ）は**リファラーを優先**する。確認画面は POST の応答で、
  その URL は POST 専用。そこに載ったフォームから送ると、リファラーが POST 専用の URL になり、戻り先を GET で開いて 405
- ⚠ テストの HTTP クライアントはリファラーを送らない（`from()` を付けたときだけ）ので、既存のテストでは原理的に見えなかった

## 実測（2026-09-24。いまのコードのまま、scratchpad の使い捨てテストで `from(確認画面の URL)` を付けて送った）

| 取込 | 確認画面の URL | 経路 | 戻り先を GET で開くと |
|---|---|---|---|
| テナント（5 タブ）| POST 専用 `/admin/tenant-import/{tab}` | 送り直し: ファイルなし（入力チェック）／ 見出しだけ（`CsvImportException`）／ 確定時の DB エラー（`catch`）| **3 経路とも 405** |
| 賃貸マンション（6 タブ）| POST 専用 `/admin/mansion-import/{tab}` | 同上 | **3 経路とも 405** |
| ZEAL 会員 | POST 専用 `…/zeal/member-import/preview` | 確定: CSV の読み取りエラー（hidden を書き換えたとき）／ 有効な店舗が無い（確認と確定の間に止めたとき）| **2 経路とも 405** |
| 工程表 | POST 専用 `…/schedule-import/preview` | 送り直し: ファイルなし ／ **ガント形式**（選び間違いで普通に踏む）／ 確定: `rows_json` なし・空・壊れた行（書き換えたとき）| **5 経路とも 405** |
| 顧客 | `/admin/customers/import`（**GET と同じ URL**）| 送り直し: ファイルなし・見出しだけ | 200 ＝ **直さない** |
| 周辺ビル | 確認は画面の中（SheetJS）。送信元は GET の取込画面 | 確定の入力チェック | 200 ＝ **直さない** |

- 一覧に無かった `Zeal\SheetImportController`（経営試算表のシート取込）は、断るときの戻り先をすべて `route('zeal.simulations.show')` に固定済み（`back()` も入力チェックも無い）＝ 対象外
- 取込の画面は、戻った先でフラッシュの `error`（レイアウトが描く）と `$errors`（各画面が描く）の両方を出す（確認済み）

## 決めること（推薦つき）

| # | 事柄 | 推薦 | 理由 |
|---|---|---|---|
| 1 | ブランチの元 | **approval-phase1 の上に積む**（`import-preview-back`・元 `6e9b59fc`。approval-followups と同じ形）| RULES の Bug #64 は approval-phase1 にだけあり、その注記（「基幹の取込にも…別の作業」）をその場で直せる。approval-phase1 は本番反映の承認待ちで、先に単独で反映できる（このブランチはその後に早送りする）。⚠ approval-phase1 を当面出さない場合は、13.x から分けて作り直す（その場合 RULES の追記は approval-phase1 が入った後）|
| 2 | 書き換えたときにしか届かない経路（ZEAL の確定・工程表の確定）も直すか | **直す** | 実測で 405 を再現した（タスクの「再現した経路だけ直す」に当たる）。決裁の取込でも確定側の入力チェックまで直した。直し方は同じ 1 行 |
| 3 | 構造の守り | **走査テストを 1 本足す**（取込のコントローラの `back()` を全件分類）| 確定時の DB エラーの `catch` はテナント 5・賃貸マンション 6 の計 11 か所。挙動のテストは代表のタブだけなので、ほかのタブを `back()` に戻す変異は走査でしか止まらない（Top trap #13）|

## 変えるファイル

| 区分 | ファイル |
|---|---|
| アプリ | `app/Http/Controllers/Admin/TenantImportController.php` ／ `Admin/MansionImportController.php` ／ `Admin/ZealMemberImportController.php` ／ `Housing/ScheduleImportController.php` |
| テスト（新規）| `tests/Feature/Admin/TenantImportRejectionTest.php` ／ `tests/Feature/Admin/MansionImportRejectionTest.php` ／ `tests/Feature/ImportControllerReturnPathScanTest.php` |
| テスト（足す・直す）| `tests/Feature/Admin/ZealMemberImportControllerTest.php`（3 ケース）／ `tests/Feature/Housing/ScheduleImportTest.php`（5 ケース）／ `tests/Feature/Admin/ImportValidationFeedbackTest.php`（期待する戻り先にタブの指定が付く）|
| 記録 | この計画（`docs/superpowers/plans/2026-09-24-import-preview-back.md`）／ `docs/BACKLOG.md` ／ `docs/RULES.md`（Bug #64 の注記）／ `CLAUDE.md`（一言）|

**ルート・DB・ビュー・依存の変更は無し。**

## 共通の決まり

- 作業場所: 新しい worktree `/Users/masanori/site/manage/.claude/worktrees/import-preview-back`（`git worktree add … -b import-preview-back approval-phase1`。vendor は approval-phase1 の worktree から `cp -Rc` で実体コピー。`.env` は作らない）
- 全件テスト: `APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`
- `.env` は読まない・`git stash` / `--no-verify` は使わない・push しない。コミットは Conventional Commits、末尾に
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`
- 画面の文言を見るテストでは `assertSessionHas*()` を呼ばない（Bug #49）。検証エラーの応答に `assertRedirect()` を使わず、
  ステータスと `Location` を `assertSame` で見る（決裁の取込のテストで実測した罠: 外れると失敗文の組み立てで fatal になる）

---

## Task 1: worktree と計画

- [ ] worktree を作り、vendor をコピーし、`git merge-base --is-ancestor approval-phase1 HEAD` を確かめる
- [ ] この計画を `docs/superpowers/plans/2026-09-24-import-preview-back.md` に置いてコミット（`docs: CSV 取込の確認画面から断られると 405 になる件を直す計画を書く`）

## Task 2: 再現するテストを書く（先に赤を確かめる）

リファラーは**確認画面の URL**（ブラウザは確認画面に載ったフォームから送るので、Referer = その POST 専用の URL）。
行き先は `Location` を `assertSame` で見て、**たどって 200 と理由の文言**まで見る（Bug #47 の往復）。

- [ ] `TenantImportRejectionTest`（`SubmitsImportPreview` ＋ `ParsesForms`。`importBasePath()` = `/admin/tenant-import`）
  - 送り直し 2 ケース（データプロバイダ）: 区画タブ（既定でないタブで、タブの指定が効くことも見る）の確認画面の URL から
    ①ファイルなし → `route('admin.tenant-import', ['tab' => 'unit'])` へ・`trans('validation.required', ['attribute' => …])` が出る
    ②見出しだけの CSV → 同じ行き先・「CSVファイルにデータがありません。」が出る
  - 確定時の DB エラー: 物件タブのテンプレート（見本の行が通る）でプレビュー → **描画された「インポート実行」フォーム**を分解 →
    `Property::creating()` で例外を投げるようにしてから、確認画面の URL をリファラーにして送る →
    `route('admin.tenant-import', ['tab' => 'property'])` へ・「インポートに失敗しました: …」が出る・物件は 0 件（巻き戻っている）
- [ ] `MansionImportRejectionTest`（同じ形。`/admin/mansion-import`・`CreatesMansionSchema`。送り直しは部屋タブ →
  `['selected_tab' => 'room']`、確定の DB エラーは物件タブ ＋ `MsProperty::creating()`）
- [ ] `ZealMemberImportControllerTest` に 3 ケース（データプロバイダ）: 確認画面の URL（`…/member-import/preview`）をリファラーにして
  ①`csv_data` が空の確定（`loadCsv()` の 1 か所目）②必須の列（名前・入会日・状態）が無い CSV の確定（2 か所目）
  ③有効な店舗が無い状態での確定（CSV は必須の列を持つ 1 行。`makeMapper()`）→ どれも `route('admin.zeal.member-import')` へ・理由が出る
- [ ] `ScheduleImportTest` に 5 ケース（確認画面の URL をリファラーに）: 送り直しのファイルなし・ガント形式（既存の `save()` で作る）、
  確定の `rows_json` なし・空の配列・壊れた行 → どれも `route('housing.properties.schedule-import.form', $property)` へ・理由が出る
- [ ] `ImportValidationFeedbackTest`: テナント・賃貸マンションの期待する戻り先を `?tab=property` / `?selected_tab=property` 付きに
  （直したあと、GET の取込画面から送っても行き先は「そのタブ」になる。顧客は変わらない）。データプロバイダに「期待する行き先」を足す
- [ ] 全件テストを流し、**足したテストだけが 405 / 行き先違いで落ちる**ことを確かめる（落ちる理由の文言まで）。
  ⚠ 赤のテストだけのコミットは作らない — ここではコミットせず、Task 3 で取込ごとに「修正＋そのテスト」を 1 コミットにする

## Task 3: 直す

- [ ] **テナント**: `loadCsv()` に `string $tab` を足し（5 か所の呼び出しは `$tab` を先に決めている＝実測済み）、
  入力チェックを try で包んで `throw $e->redirectTo(route('admin.tenant-import', ['tab' => $tab]))`、
  `CsvImportException` は `redirect()->route('admin.tenant-import', ['tab' => $tab])->with('error', …)`。
  確定の `catch` 5 か所も同じ行き先へ（文言は今のまま）
- [ ] **賃貸マンション**: 同じ形（`route('admin.mansion-import', ['selected_tab' => $tab])`・`catch` 6 か所）
- [ ] **ZEAL 会員**: `loadCsv()` の 2 か所と `makeMapper()` の 1 か所を `redirect()->route('admin.zeal.member-import')->with('error', …)` へ。
  入力チェック（プレビューだけ）は GET の取込画面から送られるので変えない
- [ ] **工程表**: プレビューと確定の入力チェックを try で包んで `redirectTo(route('housing.properties.schedule-import.form', $property))`、
  ガント形式・`rows_json` の 2 か所は `redirect()->route(…form…)->withErrors([...])`
- [ ] 4 本とも、クラスの docblock に「断るときの戻り先は取込の画面に固定する（確認画面は POST の応答。`back()` や入力チェックの
  既定の戻り先はリファラー＝ POST 専用の URL へ戻って 405。Bug #64）」を書く（決裁の取込と同じ書き方）
- [ ] ⚠ 入力チェックは `$request->validate([` の形のまま try で包む（`JapaneseValidationMessagesTest` の走査が literal を見る）
- [ ] 取込ごとに「修正＋そのテスト」を 1 コミット（4 コミット。`fix(tenant): …` など）。各コミットの前に全件テストが緑。
  `ImportValidationFeedbackTest` の期待値の変更は、テナントの行はテナントのコミット・賃貸マンションの行は賃貸マンションのコミットに入れる
  （途中のコミットでも全件が緑になるように）

## Task 4: 走査テスト（全件分類）

- [ ] `ImportControllerReturnPathScanTest`: `app/Http/Controllers/**/*ImportController.php` を機械的に列挙し（下限 8 本）、
  コメントを落としてから（`token_get_all()`。Bug #42 ②）`back(`・`->back(`・`::back(`・`url()->previous(` を数える。
  - 許す 2 本（理由つき）: `Admin/CustomerImportController`（確認画面の URL が GET の取込画面と同じ・実測 200）／
    `Tenant/AreaBuildingImportController`（確認は画面の中・送信元は GET の取込画面・実測 200）
  - それ以外はすべて 0 本（新しい取込が `back()` を使うと落ちる）
  - 検出器の正規表現の全部の枝を、見本で通す自己テスト（Bug #61 ③）
  - ⚠ 死角（docblock に書く）: 入力チェックの既定の戻り先（`validate()` を try で包まない形）は走査で見えない → 挙動のテストが守る
- [ ] 全件テスト → コミット（`test: 取込のコントローラの戻り先を全件分類で守る`）

## Task 5: 変異テスト（Bug #44 の作法）

隔離コピー（`git worktree add --detach` ＋ vendor を `cp -Rc`。Bug #50）で、先にカナリアを当ててから流す。
作法: コミット済みのコードに当てる・各変異の前後で `git status --porcelain` が空・置換は出現がちょうど 1 回のときだけ・
`git diff --stat` で着弾を確認・全件を `--log-junit` で流す・落ちたテストと**理由の文言**まで記録（実行役は scratchpad の `mutate.py`）。

| 変異 | 期待（落ちるテスト）|
|---|---|
| カナリア | 取込の画面を描く全テスト |
| テナント: `CsvImportException` を `back()` に戻す | 見出しだけの送り直し ＋ 走査 |
| テナント: 入力チェックを try で包まない形に戻す | ファイルなしの送り直し（走査は見えない＝期待どおり）|
| テナント: 確定の `catch` を `back()` に戻す（物件タブ）| 確定時の DB エラー ＋ 走査 |
| テナント: 確定の `catch` を `back()` に戻す（区画・顧客・契約・過去契約タブ、1 つずつ）| 走査だけ（挙動のテストは物件タブだけ）|
| 賃貸マンション: 同じ組み合わせ（6 タブ）| 同上 |
| ZEAL: `loadCsv()` の 2 か所・`makeMapper()` を `back()` に戻す（1 つずつ）| それぞれのケースだけ（空の `csv_data` ／ 必須の列なし ／ 店舗なし）＋ 走査 |
| 工程表: 5 か所を戻す（1 つずつ）| それぞれのケース（＋ `back()` の 3 か所は走査）|
| 走査: 許す 2 本の件数を変える／実在しないファイルを足す | 走査 |
| 行き先のタブを取り違える（テナントの区画を `property` に）| 区画タブの送り直しのテスト |

期待と違った変異は、テストを足して赤になるまで測り直す。全表をこの計画の末尾「実測記録」に書いてコミット。

## Task 6: ローカルの実ブラウザ確認（リファラーは本物のブラウザが付ける）

使い捨ての SQLite ＋ `artisan serve` ＋ 使い捨てのログイン用ルート（コミットしない・確認後に戻す）・Playwright（画面が見えている状態）。
- [ ] テナントの区画タブ: プレビュー → 確認画面から見出しだけの CSV を送り直す → 取込の画面の区画タブに着き、理由の帯が出る（405 でない）
- [ ] 工程表: プレビュー → 確認画面からガント形式のファイルを送り直す → 取込の画面に着き、理由が出る
- [ ] コンソールのエラー 0・`main` の横スクロール 0（変えたのはコントローラだけだが、戻った画面を見る）
- [ ] コンパイル済みビューの lint（`view:cache` → `php -l` → `view:clear`）

## Task 7: 記録

- [ ] `docs/RULES.md` Bug #64 の末尾の「⚠ 基幹の取込にも…（未確認・別の作業として提案済み）」を、測定と直した結果に置き換える
  （4 本で再現・顧客と周辺ビルは 200 で直さない・走査テストの名前）
- [ ] `docs/BACKLOG.md` に節を足す（直したもの・実測・変異・ブラウザ・範囲外）
- [ ] `CLAUDE.md` の Bug #64 の注意（Laravel-specific quirks の User の行）に「コントローラの `back()` も同じ。取込は
  `ImportControllerReturnPathScanTest` が止める」を一言足す
- [ ] この計画の末尾に実測記録 → コミット

## 本番反映（この計画の承認とは別に、その時点で本文で確認してから）

- approval-phase1 を先に出す（①〜④の承認待ち）。このブランチはその後に `13.x` へ早送りして `./deploy.sh`
  （**DB 変更・新規 PHP クラス・依存の変更は無し**＝ `composer dump-autoload` も不要）
- 本番の確認: 読み取りだけ（取込の画面が開くこと。本番のデータは作らない）

## 範囲外（気づいたが直さない）

- ZEAL 会員の確定で DB の例外が出たとき、`DB::transaction()` の外で受け止めていないので 500 になる（`back()` の問題ではない）
- 顧客の取込は、確認画面の URL が GET の取込画面と同じなので 405 にはならないが、断られると確認画面の内容は消えて取込の画面に戻る（今と同じ）

## 検証のまとめ

- 足したテストが直す前は 405 / 行き先違いで落ち、直した後は全件テストが緑
- 変異がすべて期待どおりの集合と理由で落ちる
- 実ブラウザで 2 経路が取込の画面へ戻る

---

## 実測記録（2026-09-24）

### Task 3（直す）

| コミット | 取込 | 直した箇所 |
|---|---|---|
| `b6985223` | テナント | 入力チェック（try で包む）・`CsvImportException`・確定の `catch` 5 か所 → `?tab=` |
| `00340c88` | 賃貸マンション | 同じ形・確定の `catch` 6 か所 → `?selected_tab=` |
| `6b23c3fe` | ZEAL 会員 | `loadCsv()` の 2 か所・`makeMapper()` → 取込の画面。**入力チェックも try で包んだ**（下の「計画との違い」）|
| `be3a9c0c` | 工程表 | 入力チェック 2 か所（try）・ガント形式・`rows_json` が読めない・壊れた行 → 取込の画面 |

- ⚠ **計画との違い（1 つ）**: ZEAL 会員の入力チェックは「プレビューは GET の取込画面から送られるので変えない」としていたが、
  `loadCsv()` は `confirmed` を見て分岐するので、確定の送信から hidden の `confirmed` が抜けると**確定（`execute()`）が
  この入力チェックに落ちる**。その既定の戻り先も確認画面の URL（POST 専用）＝ 405 の形。テストを足して赤（`Location` が
  `…/member-import/preview`）を確かめてから、ほかの 3 本と同じ形で try に包んだ。ここへ来るのは hidden を書き換えたときだけ
  （決めること 2 と同じ扱い）
- 全件テスト: ZEAL を直した時点で 2165 本中、落ちたのは工程表の未修正の 5 ケースだけ（理由はどれも「確認画面の URL へ
  戻っている」）→ 工程表を直して 2165 本すべて緑

### Task 4（走査テスト）

- `*ImportController.php` は **8 本**（Admin: 顧客・賃貸マンション・テナント・ZEAL 会員 ／ Approval: 社員 ／ Housing: 工程表 ／
  Tenant: 周辺ビル ／ Zeal: シート）。Approval の 1 本は approval-phase1 にだけある
- 検出（コメントを落としてから）: `back(`（`\back(`・`->back(`・`::back(` を含む）／ `->previous(`・`::previous(` ／
  `referer`・`referrer` ／ `redirect()->refresh(`・`Redirect::refresh(` ／ `$request->url(`・`request()->fullUrl(` など。
  直した後の件数は顧客 2・周辺ビル 2・ほか 0
- ⚠ 計画より検出を広げた: リファラーのヘッダーを直接読む形（`b8ebb2bd`）と、今の URL へ戻す形（`ad673f7b`。レビューの M4）。
  どちらも POST 専用の URL を GET で開く同じ 405
- 取込でないので対象外だが確かめたこと: `Admin/UserController` の `url()->previous()` 2 か所は、決裁 段階1 の F2 で入れた
  「GET の画面から送る入口はリファラーへ戻す」もの（意図どおり）
- 全件テスト 2169 本すべて緑（`b8ebb2bd`）
- **修正前のコードで赤になるか**（レビューの後に直したテストで測り直した。`ad673f7b` の隔離コピーで 4 本のコントローラだけを `6e9b59fc` に戻す）:
  関係する 6 本 50 件のうち 18 件が赤（新しい 15 ケースすべて・`ImportValidationFeedbackTest` 2・走査 1）。理由はどれも「確認画面の URL へ戻っている」。
  走査は `TenantImportController.php` 6 か所・`MansionImportController.php` 7 か所・`ZealMemberImportController.php` 3 か所・
  `ScheduleImportController.php` 3 か所（計 19 か所）を行番号ごと名指しした

### Task 5（変異テスト）

隔離コピー（`git worktree add --detach` ＋ vendor を `cp -Rc`）を 3 つ並べ、どのコピーも最初にカナリアを当てた
（3 つとも赤＝コピー側のコードが読まれている）。実行役は前の作業の `mutate.py`（各変異の前後で作業ツリーが空・
置換はちょうど 1 回・着弾を確認・全件を `--log-junit`）。

**1 回目（`b8ebb2bd`・36 通り）: すべて期待どおり**（期待したテストの集合が、期待した理由の文言で落ちた。緑 0 件）。
走査の失敗文は、どれも変異を当てたファイルと行を名指しした。

| ID | 変異 | 落ちたテスト（理由） |
|---|---|---|
| C0 | カナリア（ZEAL 会員の取込の画面に未定義の変数）| ZEAL 会員の取込の画面を描く 5 本（500）|
| T01 | テナント: `CsvImportException` を `back()` に | 送り直し（見出しだけ）＋ 走査（`TenantImportController.php :1281 back(`）|
| T02 | テナント: 入力チェックを包まない形に（`throw $e;`）| 送り直し（ファイルなし）＋ `ImportValidationFeedbackTest`（テナント）。走査は見えない（期待どおり）|
| T03 | テナント: 確定の `catch`（物件）を `back()` に | 確定時の DB エラー ＋ 走査（`:290`）|
| T04〜T07 | テナント: 確定の `catch`（区画・顧客・契約・過去契約）を `back()` に | 走査だけ（`:518` `:636` `:860` `:1157`。挙動のテストは物件タブだけ）|
| T08 | テナント: 入力チェックの戻り先のタブを `property` に取り違える | 送り直し（ファイルなし・区画タブへ戻る）だけ |
| M01〜M03 | 賃貸マンション: T01〜T03 と同じ | 同じ形（走査は `:1408` `:348`）|
| M04〜M08 | 賃貸マンション: 確定の `catch`（部屋・駐車場・入居者・部屋契約・駐車場契約）| 走査だけ（`:533` `:683` `:811` `:1032` `:1273`）|
| M09 | 賃貸マンション: タブの取り違え | 送り直し（ファイルなし・部屋タブへ戻る）だけ |
| Z01・Z02 | ZEAL 会員: `loadCsv()` の 2 か所を `back()` に | それぞれのケース（データが空 ／ 必須の列が無い）＋ 走査（`:255` ／ `:262`）|
| Z03 | ZEAL 会員: `makeMapper()` を `back()` に | 店舗を止めたケース ＋ 走査（`:196`）|
| Z04 | ZEAL 会員: 入力チェックを包まない形に | `confirmed` が抜けたケースだけ（走査は見えない＝期待どおり）|
| S01・S03 | 工程表: プレビュー・確定の入力チェックを包まない形に | それぞれのケースだけ（ファイルなし ／ `rows_json` なし）|
| S02・S04・S05 | 工程表: ガント形式・読めない・壊れた行を `back()` に | それぞれのケース ＋ 走査（`:69` `:101` `:113`）|
| X01 | 走査: 許した件数を変える（顧客 2 → 3）| 走査（「件数が 2（分類は 3）」）|
| X02 | 走査: 実在しないファイルを許可に足す | 走査（「分類にあるのに、そのファイルが無い」）|
| X03 | 走査: `back` の枝を消す | 自己テスト（「拾えていない: return back();」）＋ 分類（許した 2 本が 0 件）＋ コメントの除去 |
| X04・X05 | 走査: `previous` ／ `referer` の枝を消す | 自己テストだけ |
| X06 | 走査: コメントを落とさない | コメントの除去 ＋ 分類（docblock の `back()` と `url()->previous()` を数える）|
| X07 | 走査: 列挙を `Admin` だけに狭める | 下限（4 本 < 8）＋ 分類（周辺ビルが古い項目になる）|
| SH01 | 経営試算表のシート取込を `back()` に | 走査（`Zeal/SheetImportController.php :142`）|
| AP01 | 決裁の社員の取込を `back()` に | 走査（`Approval/UserImportController.php :136`）|

**2 回目（`ad673f7b`・19 通り。レビューを受けてテストを直した後）: すべて期待どおり**。
直す前のテストでは緑だった変異（下の ★）が、直した後のテストで赤になった。

| ID | 変異 | 落ちたテスト（理由） |
|---|---|---|
| C0 | カナリア（3 つのコピーそれぞれ）| 5 本（500）|
| T01・M01 | `CsvImportException` を `back()` に | 別のタブからの送り直し（見出しだけ）＋ 走査 |
| T02・M02 | 入力チェックを包まない形に | 別のタブからの送り直し（ファイルなし）＋ `ImportValidationFeedbackTest` |
| T03・M03 | 確定の `catch`（物件）を `back()` に | 確定時の DB エラー ＋ 走査 |
| T08・M09 | 入力チェックの戻り先のタブを既定の `property` に取り違える | 別のタブからの送り直し（ファイルなし）だけ（「区画（部屋）タブへ戻っていない」）|
| T10・M10 | 入力チェックの戻り先からタブの指定を落とす | 別のタブからの送り直し（ファイルなし）＋ `ImportValidationFeedbackTest` |
| ★ T11・M11 | 確定の `catch`（物件）から `DB::rollBack()` を消す | 確定時の DB エラー（「失敗した取込が巻き戻っていない（1 行目が残っている）」＝ 1 件残る）。⚠ 後続の 1500 余りのテストが「There is already an active transaction」で巻き込まれる（変異が開いたままにしたトランザクションのため。変異の性質で、テストの不具合ではない）|
| ★ G1 | ガント形式の断りの文言を差し替える（戻り先はそのまま）| ガント形式のケース（「断られた理由が画面に出ていない」）＋ 既存の `test_a_file_that_is_not_the_list_format_is_rejected` |
| ★ V1 | テナントの取込の画面から理由の一覧（`<li>{{ $error }}</li>`）を消す | `ImportValidationFeedbackTest`（テナント・「具体的な理由が並んでいない」）＋ 別のタブからの送り直し（ファイルなし）|
| X03 | 走査: `back` の枝を消す | 自己テスト ＋ 分類 ＋ コメントの除去 |
| X08 | 走査: `refresh` の枝を消す | 自己テストだけ（「拾えていない: return redirect()->refresh();」）|
| X09 | 走査: 今の URL の枝を消す | 自己テストだけ（「拾えていない: return redirect($request->url());」）|
| X10 | 走査: `previous` の枝の空白を許さない | 自己テストだけ（「拾えていない: return redirect(url() -> previous ());」）|

### Task 6（実ブラウザ）

- 環境: 使い捨ての SQLite（migration のあと、`hs_*` と `schedule_steps` はテスト用の trait を投入スクリプトから呼んで作った）・
  `artisan serve`・コミットしない使い捨てのログイン用ルート（確認後に `git checkout` で戻した）・Playwright・
  main repo の node_modules で `vite build`（確認後に `public/build` を消した）
- テナント: 物件タブで確認画面（`POST /admin/tenant-import/property` → 200）→ そのまま区画タブへ切り替え、見出しだけの CSV を送る
  → `POST /admin/tenant-import/unit`（**referer: `/admin/tenant-import/property`**）→ 302 → `GET /admin/tenant-import?tab=unit` → 200。
  「CSVファイルにデータがありません。」の帯・区画タブが開いている・`main` の横スクロール 0
- 工程表: 一覧形式のファイルで確認画面（65 行・アップロード欄と確定のフォームの両方がある）→ 確認画面のアップロード欄から
  ガント形式を送る → `POST …/schedule-import/preview`（**referer: `…/schedule-import/preview`**）→ 302 → `GET …/schedule-import`
  → 200。理由の帯・`main` の横スクロール 0
- コンソールのエラーは、ログイン直後の経営ダッシュボードの 500（使い捨て DB に `ms_rooms` が無い。今回の変更と無関係）の 1 件だけ。
  確認の時間帯にアプリが記録したエラーもこれだけ
- コンパイル済みビュー **274 本 / INVALID 0 件**
- ⚠ **分かったこと**: テナント・賃貸マンションの確認画面は、開いているタブの枠だけが確認に置き換わり、そのタブのアップロードの
  フォームは無い（ほかのタブのフォームは残る）。実際に踏むのは「確認画面から**別のタブ**でアップロードし直して断られる」経路だった
  → テストもその形に直した（下のレビューの M3）

### レビュー（独立・1 回）と対応

Critical・Important は 0 件。Minor 7 件:

| # | 指摘 | 対応 |
|---|---|---|
| M1 | 工程表のガント形式のケースは、期待する文言が取込の画面の案内文に常に出ているので素通りする | 直した（`ab284e53`）。こちらでも独立に気づき、断りの文言を差し替える変異で 5 ケースとも緑を実測していた |
| M2 | 確定時の DB エラーのテストは、テンプレートの見本が 1 行だけなので最初の INSERT より前に落ち、`DB::rollBack()` を消しても緑 | 直した（`481fc215`。2 行目で失敗させ、`creating` が 2 回呼ばれたことも見る）|
| M3 | 送り直しのテストが、画面からは起こせない「同じタブの確認画面から同じタブへ」を送っている | 直した（`481fc215`。物件タブの確認画面に載っている区画・部屋タブのフォームを分解して送り、送ったタブへ戻ることを見る）|
| M4 | 走査の死角が docblock の記載より広い（今の URL へ戻す形・トレイトへの切り出し・`Validator::make()->validate()`・`ValidationException::withMessages()`）| 今の URL へ戻す形は検出に足し、残りは死角として書いた（`ad673f7b`）。**入力チェックを try で包んでいるかを機械的に見る形は、この作業では作らない**（範囲外へ）|
| M5 | `back` の枝の任意のバックスラッシュと、`previous` の枝の空白が自己テストで固定されていない | バックスラッシュは効いていなかったので外し、空白は見本を足した（`ad673f7b`）|
| M6 | `ImportValidationFeedbackTest` の理由の正規表現が案内文（`<li>CSVの「物件名」…</li>`）に一致する（今回の変更より前からの穴）| 直した（`7097d6f0`。`mimes` の断りの全文を `<li>` 込みで見る）|
| M7 | 本数の下限 8 が approval-phase1 の取込を含む | 定数のコメントに書いた（`ad673f7b`）|

### 範囲外（気づいたが直していない）

- 入力チェック（`validate()`・`Validator::make()`・`ValidationException::withMessages()`）を try で包んで `redirectTo()` を渡して
  いるかを、走査で機械的に見る形（新しい取込には守り手がいない。レビューの M4 の提案）
- （計画の「範囲外」のとおり）ZEAL 会員の確定で DB の例外が出ると 500 ／ 顧客の取込は断られると確認画面の内容が消えて取込の画面に戻る
