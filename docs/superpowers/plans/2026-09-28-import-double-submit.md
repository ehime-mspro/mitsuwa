# ほかの取込の二重送信を止める 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 顧客の取込を除く 6 本の取込（テナント 5 タブ・賃貸マンション 6 タブ・ZEAL 会員・工程表（建売）・ZEAL の本部 Sheet 取込・周辺ビル 2 種＝16 経路）の確定を「確認画面 1 つにつき 1 回」だけ通し、同じ画面からの 2 回目で契約・テナント明細・履歴が二重になる事故と、2 回目に成功の帯が出て取り込み直したように見えることをなくす。シート取込は、確認画面で見せた内容と同じものだけを書く。

**Architecture:** 設計書の案 A。サーバは顧客の取込と同じ `OneTimeAction` の鍵を使う: プレビュー（周辺ビルは取込の画面の `form()`）で `OneTimeAction::issue()` を hidden `import_token` に載せ、確定の入口（テナント・賃貸マンションは `loadCsv()` の確定の分岐の最初、ほかは確定のメソッドの最初）で `OneTimeAction::claimFrom($request, 'import_token')` を使う。使えなければ何も書かずに取込の画面（シート取込は試算表の画面）へ戻して赤帯に案内を出す。シート取込は、鍵のあとで「どの試算表の・どの月に・どの項目を・いくらにするか」の指紋（hidden `plan_digest`）を比べ、違えば断る。画面は共通の部品 `_partials/_submit_once`（関数 `submitOnce()` の唯一の定義）で、送信中のボタンを押せなくし、横の `role="status"` に「取り込んでいます…」を出す。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade + Alpine.js 3 / Tailwind v4 / PHPUnit 11（worktree の `./vendor/bin/phpunit`）/ node（部品の `<script>` を vm で動かす）/ Playwright MCP（ローカルの実ブラウザ）

**Spec:** `docs/superpowers/specs/2026-09-28-import-double-submit-design.md`（2026-09-28 に利用者が 5 節とも承認。以下「設計書」）

## Global Constraints

- 範囲は 6 本の取込（16 経路）。顧客の取込の画面（独自の `csvImport()`）は変えない。同じファイルを上げ直したときの二重は範囲外（設計書 §6・案 C）
- 本番の DB 変更・新しい PHP クラス・依存の変更は無い（`composer dump-autoload` は要らない。設計書 §4.1）。CSS も変わらない（試作で `vite build` すると `app-D-wd4D2y.css`＝本番と同じ名前）
- 鍵は `OneTimeAction`。hidden の名前は `import_token`、使うのは `OneTimeAction::claimFrom($request, 'import_token')`。`OneTimeAction` の処理は変えない（docblock だけ）。覚えておく時間は `config('approval.guide_token_ttl_hours')`（既定 12 時間）を共用する（設計書 §4.2）
- 鍵は確定の入口で、CSV の読み直し・入力チェック・行の検査より前に使う。使えなければ何も書き込まずに断る。シート取込は鍵を使ってから指紋を比べる（設計書 §4.2 の決まりごと 1・2・4）
- 断りは `->with('error', …)`（レイアウトの赤帯 `layouts/app.blade.php:83-90` が出す）。戻り先: テナント `route('admin.tenant-import', ['tab' => $tab])`・賃貸マンション `route('admin.mansion-import', ['selected_tab' => $tab])`・ZEAL 会員 `route('admin.zeal.member-import')`・工程表 `route('housing.properties.schedule-import.form', $property)`・シート取込 `route('zeal.simulations.show', $simulation)`・周辺ビル `route('tenant.area-buildings.import')`（設計書 §4.2）
- 断りの文言は設計書 §4.4 の全文をそのまま使う（テストもこの全文で見る）。シート取込の指紋が違うとき: 「プレビューのあとで反映する内容が変わりました（本部 Sheet か試算表の値が変わっています）。もう一度プレビューしてください。」（設計書 §4.3）
- 指紋は private メソッド `planDigest(ZealSimulation $simulation, string $yearMonth, array $plan): string` の 1 か所だけで作る: `will_update` の行の `[category_id, new_amount]` を `buildApplyPlan()` の順に並べ、`hash('sha256', json_encode([$simulation->id, $yearMonth, $writes]))`。いまの値は入れない・HMAC にしない。比べるときは `is_string()` を先に見てから `hash_equals()`（設計書 §4.3）
- 二度押し止めは `resources/views/_partials/_submit_once.blade.php` の `function submitOnce(options)` の 1 か所だけ（`@once` ＋ `@push('scripts')`）。確定のフォームは `x-data="submitOnce()"`（周辺ビルは `submitOnce({ reloadOnReturn: true })`）・`x-on:submit="onSubmit($event)"`・`x-on:pageshow.window="onPageShow($event)"`。部品の `@include` はフォームと同じ `@if` の中に置く（設計書 §4.5）
- ボタンは `:disabled="submitting"`（周辺ビルは `submitting || submitBlockedReason() !== null`）とクラス `cursor-pointer disabled:cursor-not-allowed disabled:opacity-60`（周辺ビルは `disabled:opacity-50` のまま）。`style` に cursor を書かない・`name` を付けない・文言は変えない（設計書 §4.5）
- 送信中の文字は `<span role="status" x-text="submitting ? '取り込んでいます…' : ''">`（シート取込は「反映しています…」）で `display: inline-block`。いつも置いて文字だけ変える。押せない理由を `title` で出さない（Top trap #12）
- `pageshow` は `window` で受け、`event.persisted` で絞らない（Bug #65）
- `<script>` の中のコメントに `@` で始まる語や `<x-` を書かない（Bug #30。説明は Blade コメントに書く）

## Review Focus

- **入れ子の Alpine（テナント・賃貸マンションの確認画面は `tenantImportTabs()` / `mansionImportTabs()`、周辺ビルは `areaImportForm()` の中に `submitOnce()` を置く）** — 送信中に押せなくしても、親の値（周辺ビルの hidden の `kind`・`surveyedMonth`・`payload()`、押せない理由の `submitBlockedReason()`）が今までどおり入り、押せない条件も効くのが期待。PHP のテストは HTML の属性しか見られないので、Task 13 の 4（周辺ビルの hidden に親の値が入ること・押せない理由の切り替え）と 1・2・5（テナント・賃貸マンションの入れ子で押せなくなること）で見る（試作ではテナントと周辺ビルで確かめた）
- **周辺ビルで送ったあと「戻る」** — bfcache から戻っても、控えから描き直しても、読み込み直して新しい鍵になるのが期待（戻った画面の鍵は使用済み）。作業の途中で別の画面へ移って戻ったときは、読み込んだファイルを残すのが期待。部品の分かれ目 6 場面は Task 1 の node の実駆動（`SubmitOnceTest`）が固定し、Chromium がどの経路を通るかは Task 13 の 4 で測る
- **狭い画面（375px）で押す** — 送信中の文字が文字の途中で割れず、横にはみ出さないのが期待（ZEAL 会員はボタンの並びを折り返す）。PHP のテストは `inline-block` までしか見られない（ZEAL 会員の `flex-wrap` は PHP から見えない＝変異 UZ04 は緑）ので、Task 13 の 6 で見る
- **Alpine の起動前・JS が動かないときのダブルクリック** — 画面の歯止めは効かないが、サーバの鍵が 2 回目を断り、書き込みは 1 回だけなのが期待。順に届く 2 回は各取込の「同じフォームを 2 回送る」テスト（Task 2〜7）が固定する。ほぼ同時の 2 回の排他は PHPUnit では測れない（テストのキャッシュは `array`）ので、`LoginGuideTest::test_the_token_is_claimed_atomically`（`Cache::add` を使う構造）と、本番の file ドライバの排他ロック（2026-09-27 に同じ鍵を 24 プロセスで使って 1 本だけ通ることを実測。Bug #67）に頼る
- **デプロイの前に開いた確認画面（鍵の hidden が無い）から押す** — 断られ、何も書かず、500 にならないのが期待。取込ごとの「使えない鍵 3 通り」の「鍵が無い」（Task 2〜7）と、シート取込の「指紋が無い」（Task 7）が固定する

---

## Context

- 顧客CSVインポートの二重送信（docs/RULES.md Bug #67）を 2026-09-28 に本番へ出した。そのとき範囲外に残した「ほかの取込の二重送信」を直す。設計書 §2.1 の実測で、同じ確認画面の確定を 2 回送ると、テナントの契約・過去契約、賃貸マンションの部屋契約・駐車場契約、周辺ビルのテナント明細が二重に入り、シート取込は履歴だけが二重になる。残りの 10 経路は二重にならないが、2 回目にも成功の帯が出る
- 作業場所: worktree `/Users/masanori/site/manage/.claude/worktrees/import-double-submit`（ブランチ `import-double-submit`）。この計画を書いた時点は、`13.x`（`9e2df5b8`）の上に設計書（`e039a0da`）と、この計画のコミット。`origin/13.x` は `ccd687fe`（push は利用者の指示のとき）
- worktree `approval-phase2a`（`8d2cf8f2`）は別の会話「社内決裁申請」が作業中。触らない・借りない。差分はこの作業のコード・テストのファイルに触れていないが、記録の `CLAUDE.md`（完了済みモジュールの行）と `docs/BACKLOG.md` を触る。先に `13.x` へ入ったら、Task 15 で `docs/BACKLOG.md` がぶつかることがある（両方の段落を残す）
- 利用者の決まり（この計画を実行する人にも適用）: 応答は日本語・選択肢は本文の表で出す（AskUserQuestion のウィジェットは使わない）・推薦と実測を先に書く・質問は 1 回に 1 つ・`.env` / `.env.*` は読まない（`ls` で存在を確かめることもしない）・`git stash` と `--no-verify` は使わない・push は指示があったときだけ・**本番への反映（Task 16）と本番の読み取りは、承認をもらってから**
- 設計書 §7 の「`LoginGuideTest::test_the_token_is_claimed_atomically` の docblock が本番のキャッシュを database と書いている」は、利用者の判断（2026-09-28・案 A）で、この作業の別のコミットで直す（Task 9。動きは変えない）

## 試作で確かめたこと（2026-09-28）

scratchpad に `git worktree add --detach … e039a0da` で作った写し（vendor は `cp -Rc` で実体コピー。Bug #50）にこの計画のコードを入れて測った。表の数字は、そのまま各 Task の「期待」に使っている。

| 見たこと | 結果 |
|---|---|
| 着手前の全件（`e039a0da`）| `OK (2362 tests, 15396 assertions)`・node v24.11.1 |
| 入口で必ず断る一時変更（設計のとき・実装の前。関係するテスト 10 本 165 本）| 86 本が赤・79 本が緑。緑はどれもプレビュー・構造・権限だけを見るテスト（確定へ送るテストは全部赤）|
| 各 Task のテストを直す前のコードで流す | Task の Step の「期待」に書いた（どれも、鍵の hidden が無い画面では「同じファイルを上げ直す」テストが `Undefined array key "import_token"` の 1 本だけエラー、残りは失敗）|
| `ParsesForms::htmlAttr()` が private のまま | 工程表・周辺ビルの構造テストが `Call to private method Tests\Feature\Schedule\ScheduleTestCase::htmlAttr() from scope Tests\Feature\Housing\ScheduleImportTest` で落ちた → protected にした（設計書との違い 3）|
| 最終形 | 全件 **`OK (2431 tests, 16299 assertions)`**（+69 本）／ コンパイル済みビュー `views=275 invalid=0`（部品の 1 本が増える）|
| 実装のあとの「入口で必ず断る」カナリア（全件。入口に来たテストの名前を記録）| 入口に来た 136 本がすべて赤・**入口に来たのに緑のまま 0 本**・入口に来ずに赤 0 本（Task 11 の期待）|
| CSS | 試作で `vite build` → `app-D-wd4D2y.css`（本番と同じ名前）。使うクラスはどれも既にビルドに入っている |
| 変異 71 通り＋カナリア（関係するテスト 26 本に絞って先測り）| 期待と違ったものは無い。緑は 4 通りで、どれも理由がある（等価 K20・D07・T02、PHP から見えない UZ04）。Task 12 の表の「期待」はこの実測 |
| 実ブラウザ: テナント（入れ子）| 確定のフォームの親は `tenantImportTabs()`・応答を 3 秒遅らせたダブルクリックで確定の POST は 1 回・押した直後に `disabled`・`not-allowed`・`0.6`・「取り込んでいます…」。完了のあと「戻る」は手元の控えから描き直し（サーバに POST は届かない）、押すと鍵の案内 |
| 実ブラウザ: 周辺ビル（入れ子）| 行が無いうちは押せず、包む span の `title` に理由。プレビュー後は hidden に親の `kind`・`surveyed_month`・`payload()`（2 行）が入る。送ったあと「戻る」は **bfcache ではなく**サーバから取り直し、部品がもう 1 回読み込み直す（`GET` が 2 回・最後の種類は `reload`・鍵は新しい）|
| 実ブラウザ: 375px | テナント・周辺ビル・ZEAL 会員・本部 Sheet とも、送信中の文字はボタンの下の行に 1 行・`main` の横スクロール無し。ZEAL 会員は `flex-wrap` を外すと文字が 69px に縮んで 2 行に割れた（設計書との違い 1）。コンソールの警告・エラー 0 件。賃貸マンション・工程表は見ていない（Task 13 で初めて見る）|

## 設計書との違い（試作で決めたこと）

| # | 設計書 | この計画 | 理由 |
|---|---|---|---|
| 1 | §4.5「ZEAL 会員: ボタンは『キャンセル』と並ぶ flex の中」| ボタンの並びの `div` に `flex-wrap: wrap` を足す | 折り返さないと、375px で送信中の文字が 69px に縮んで 2 行に割れる（試作の実ブラウザで実測。折り返すとボタンの下の行に 1 行）。PHP から見えない（変異 UZ04 は緑）ので Task 13 の 7 でも見る |
| 2 | §4.5 の形の見本（ボタンの直後に `role="status"`）| 周辺ビルは、ボタンを包む `span`（押せない理由の `title` を持つ）の**外**の直後に置く | 包む `span` の中に置くと、送信中の文字にも押せない理由の tooltip が出る。`title` はいまのまま包む `span` に置く（Top trap #12）|
| 3 | （無し）| `tests/Concerns/ParsesForms.php` の `htmlAttr()` を private → protected | 新しい測りの道具 `ChecksDoubleSubmit` を、`ScheduleTestCase`・`AreaBuildingTestCase`（`ParsesForms` を使う基底クラス）の子から使う。private だと子から呼べない（試作で実測）|
| 4 | §5.1「同じファイルを上げ直すと、新しい鍵で取り込める」| 同じファイルで 2 回プレビューし、鍵が違うことを見てから、2 回目のプレビューの鍵で確定する | 鍵をファイルの中身から作る書き換え（上げ直しても同じ鍵になり 12 時間断られる）を `assertNotSame` が捕まえ、新しい鍵で通ることも見られる。1 回目の確定を失敗させる手段は取込ごとにまちまちなので使わない |
| 5 | §5.2「テスト用スキーマに、正本の DDL から列・表・一意の索引 3 本を足す」| それに加えて、本部 Sheet 取込が書く経費の 5 項目を足す `seedZealSheetImportCategories()` を別に作る | 既存の `seedZealSimulationCategories()`（`MonthEndOverflowTest`・`SimulationValidationFeedbackTest` が使う最小の項目）を変えない |
| 6 | §5.4「hidden の `import_token`」| 構造のテストは「値が空でない」まで見る（長さは見ない）| 長さは `OneTimeAction::issue()` の細部で、ここで固定する理由が無い |
| 7 | §2.6「工程表: `importRows()`（5 回呼ばれる）」| `importRows()` の docblock「画面が描いたフォームどおりに往復する」を「確定を直接送る」に直す | 実際はプレビューを通さずに確定を直接送っている（数え直しで気づいた）|
| 8 | §5.4「`reloadOnReturn` の 4 場面」| node で 6 場面（送ったあと bfcache ／ 送らずに bfcache ／ bfcache で元も back_forward ／ bfcache なしの back_forward ／ reload ／ navigate）| 「読み込み直したあと繰り返さない」と「ふつうに開いた」を分け、`persisted` と種類の組み合わせを全部通す |
| 9 | §5.6「一覧は計画で決める」| 71 通り＋カナリア（Task 12 の表）。うち 3 通りは等価（K20・D07・T02）、1 通りは PHP から見えない（UZ04）と先測りで分かったので、表に理由を書いた | 下の「実測記録」 |
| 10 | §7「LoginGuideTest の docblock … 計画のときに利用者に確かめる」| 案 A: この作業の別のコミットで直す（Task 9）| 利用者の判断（2026-09-28）|
| 11 | §4.5「bfcache を使うかどうかは未実測（§5.8 で測る）」| 実測: Chromium は周辺ビルの取込の画面を、送ったあとの「戻る」で bfcache に入れず、サーバから取り直した（その時点で新しい鍵）。部品は種類 `back_forward` を見てもう 1 回読み込み直す（害は無い）。部品の形は変えない | HTTP のキャッシュから描くブラウザでは、読み込み直しが無いと使用済みの鍵のまま残る |
| 12 | §5.3「確定へ送る既存のテストを全件洗い出し…狙った文言まで見ているか」| 入口で必ず断る一時変更を全件に当て、入口に来たテストの名前を記録して、全部が赤になることで確かめる（Task 11）| 洗い出しを手でやると漏れる（設計のときの数え間違いの教訓）|

## 変えるファイル

| ファイル | 変更 | Task |
|---|---|---|
| `resources/views/_partials/_submit_once.blade.php`（新規）| 二度押し止めの関数 `submitOnce()` の唯一の定義 | 1 |
| `tests/Feature/SubmitOnceTest.php`（新規）| 部品を node の vm で動かす・1 回だけ定義される（Task 1）／ 鍵を描く画面の全件分類（Task 8）| 1・8 |
| `tests/Concerns/ParsesForms.php` | `htmlAttr()` を protected に | 2 |
| `tests/Concerns/ChecksDoubleSubmit.php`（新規）| 書き込みの数・断り・確定のフォームの形を測る道具 | 2 |
| `app/Http/Controllers/Admin/TenantImportController.php`・`resources/views/admin/tenant-import/_preview.blade.php` | 鍵・断り・二度押し止め | 2 |
| `tests/Feature/Admin/TenantImportDoubleSubmitTest.php`（新規）| 5 タブ | 2 |
| `app/Http/Controllers/Admin/MansionImportController.php`・`resources/views/admin/mansion-import/_preview.blade.php` | 同じ | 3 |
| `tests/Feature/Admin/MansionImportDoubleSubmitTest.php`（新規）| 6 タブ | 3 |
| `app/Http/Controllers/Admin/ZealMemberImportController.php`・`resources/views/admin/zeal-member-import/preview.blade.php` | 同じ | 4 |
| `tests/Feature/Admin/ZealMemberImportControllerTest.php` | 手で組んだ確定 4 か所に鍵・新しいテスト 6 本 | 4 |
| `app/Http/Controllers/Housing/ScheduleImportController.php`・`resources/views/housing/properties/schedule-import.blade.php` | 同じ | 5 |
| `tests/Feature/Housing/ScheduleImportTest.php`・`tests/Feature/Housing/HousingConstructionStartDateTest.php` | 新しいテスト 6 本 ／ 手で組んだ確定 2 か所に鍵 | 5 |
| `app/Http/Controllers/Tenant/AreaBuildingImportController.php`・`resources/views/tenant/area-buildings/import.blade.php` | 同じ（鍵は `form()` で出す）| 6 |
| `tests/Feature/Tenant/AreaBuildingImportTest.php` | `importBuildings()`・`importTenants()` を画面の鍵で送る形に・手で組んだ確定 10 か所に鍵・既存の 1 行・新しいテスト 7 本 | 6 |
| `tests/Concerns/CreatesZealSimulationSchema.php` | 正本の DDL の列・表・一意の索引・経費の 5 項目 | 7 |
| `app/Http/Controllers/Zeal/SheetImportController.php`・`resources/views/zeal/simulations/sheet-import/preview.blade.php` | 鍵・指紋・二度押し止め | 7 |
| `tests/Feature/Zeal/SheetImportTest.php`（新規）| この取込の初めての Feature テスト 16 本 | 7 |
| `tests/Feature/ImportControllerOneTimeKeyScanTest.php`（新規）| 取込のコントローラの全件分類 | 8 |
| `tests/Feature/Approval/LoginGuideTest.php` | `claimFrom()` の件数の下限 6 → 12（Task 8）／ docblock の本番のキャッシュ（Task 9）| 8・9 |
| `app/Support/OneTimeAction.php` | docblock の「使っている所」 | 8 |
| `docs/RULES.md`・`CLAUDE.md`・`docs/BACKLOG.md`・この計画 | 記録 | 15・16 |

ルート・DB・テンプレート・依存・`config/approval.php`・顧客の取込の 3 ファイルは変えない。

## 共通の決まり

- 作業は worktree で行う。⚠ ハーネスの cwd が main repo に戻っていることがあるので、コマンドには `cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit &&` を付けるか `git -C <worktree>` にする
- 全件テスト（約 110 秒）: `cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`
- 決まったテストだけ: 上の末尾にファイルを並べる（PHPUnit 11 は複数のパスを受け付ける。例 `./vendor/bin/phpunit tests/Feature/SubmitOnceTest.php tests/Feature/Admin/TenantImportDoubleSubmitTest.php`）
- ⚠ `.env` は読まない（worktree に `.env` は無い・作らない。テストは `APP_KEY` の環境変数だけで起動する。渡し忘れると Feature テストが大量に `MissingAppKeyException` で落ちる）
- コミット: Conventional Commits・件名は日本語で 72 文字以内・句点なし・末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`（サブエージェントは自分の文脈の Co-Authored-By 行を使う）。HEREDOC で書く。`--no-verify` は使わない。コミットの後に `git status --porcelain` が空であることを見る
- サブエージェントに任せるときは 1 つの worktree に 1 つのエージェント。書いたらすぐコミットする（未コミットの編集が残っていると、変異の実行役が「作業ツリーが空でない」で止まる）
- zsh: 引用なしの `$VAR` は単語分割されない（複数のパスは配列で）・`=` で始まる語は展開される（区切りの `echo '-----'` は引用する）・`grep` は ugrep（`-` で始まるパターンは `-e`・`$` を含む文字列は `-F`）・`php -r "…"` の `$` はシェルが展開する（`'…'` で囲む）
- 編集は「置き換える前」がファイルにちょうど決めた数だけあることを確かめてから当てる（Edit ツールは 1 か所でないと失敗する。同じ行が 5・6 か所ある所は `replace_all` を使い、先に `grep -cF` で数を確かめる）
- 行番号は `e039a0da` の時点のもの。ずれていたら「置き換える前」の文字列で探す

---

## Task 0: 前提の確認

**Files:** なし

- [ ] **Step 1: worktree とブランチ**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && git status --porcelain && git rev-parse --abbrev-ref HEAD && git log --oneline -2 && git merge-base --is-ancestor 13.x HEAD && echo 'ahead-of-13.x'
```

期待: `status` は何も出さない・`import-double-submit`・この計画のコミット（`docs: ほかの取込の二重送信を止める実装計画を書く`）と設計書のコミット（`e039a0da`）・`ahead-of-13.x`。

⚠ `ahead-of-13.x` が出ない（`13.x` がこの後に進んだ）ときは、作業を始める前に取り込む（リベースしない＝記録のコミット番号を保つ）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && git log --stat --format='%h %s' HEAD..13.x && git merge --no-edit -m "$(cat <<'EOF'
Merge branch '13.x' into import-double-submit

作業中に 13.x へ入ったコミットを取り込む。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" 13.x
```

取り込んだコミットが、この計画で触るファイル（上の「変えるファイル」）を変えていたら**止めて**、この計画の「置き換える前」がまだ合うかを確かめる。`composer.lock` が変わっていたら worktree で `composer install`（開発用の依存も入れる）。アプリのコードやテストが変わっていたら Step 2 の本数が変わるので測り直して記録する。

- [ ] **Step 2: この時点の全件テスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

期待: `OK (2362 tests, 15396 assertions)`

- [ ] **Step 3: node**

```bash
command -v node && node --version
```

期待: node のパスと版（v24 系）。無ければ `SubmitOnceTest` の実駆動は飛ばされる（`markTestSkipped`）ので、Task 13 のブラウザで必ず見る。

---

## Task 1: 二度押し止めの部品（`_partials/_submit_once`）

**Files:**
- Create: `resources/views/_partials/_submit_once.blade.php`
- Create: `tests/Feature/SubmitOnceTest.php`

**Interfaces:**
- Consumes: レイアウトの `@stack('scripts')`（`resources/views/layouts/app.blade.php:182`。Alpine の `@vite` より後で、読み込みの途中に動く）
- Produces: Blade の部品 `@include('_partials._submit_once')`（何度読み込んでも `<script>` は 1 回だけ）と、JS の `function submitOnce(options)` が返す Alpine のデータ `{ submitting: boolean, onSubmit(event), onPageShow(event) }`。`options.reloadOnReturn === true` のときだけ「戻る」で読み込み直す。確定のフォームは `x-data="submitOnce()"`（周辺ビルは `submitOnce({ reloadOnReturn: true })`）・`x-on:submit="onSubmit($event)"`・`x-on:pageshow.window="onPageShow($event)"` と書く（Task 2〜7）

- [ ] **Step 1: 部品のテストを書く**（`tests/Feature/SubmitOnceTest.php` を新しく作る。鍵を描く画面の全件分類は、6 つの画面ができてから Task 8 で足す）

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 確定のボタンの二度押し止めの部品（resources/views/_partials/_submit_once.blade.php。
 * 設計書 2026-09-28-import-double-submit-design.md §4.5・§5.4）。
 *
 * ⚠ PHP のテストからブラウザの JavaScript は動かせないので、部品が描いた script をそのまま node の vm で読み込み、
 *   window（location.reload と performance）だけを偽物で渡して動かす（CustomerImportTest::csvImportInNode() と同じ流儀。
 *   Bug #47 の「振る舞いの正本は実駆動」）。Alpine との結びつき（x-data・x-on・:disabled）は、
 *   各画面のテストが構造で見る（Tests\Concerns\ChecksDoubleSubmit::assertSubmitOnceForm()）。
 */
class SubmitOnceTest extends TestCase
{
    /** 部品を読み込んで、レイアウトと同じく scripts のスタックを最後に出したページ */
    private function renderedPage(string $includes = "@include('_partials._submit_once')"): string
    {
        return Blade::render($includes . "\n@stack('scripts')");
    }

    /**
     * 部品が描いた script を node の vm で読み込み、$steps（JavaScript）を走らせて、result に入れた値を返す。
     *
     * @param  string  $steps  make('submitOnce(…)') で作り、press(data) で送信を 1 回押し、返したい値を result に入れる。
     *                         偽の window は nav.type（ナビゲーションの種類）と reloads.count（reload() の回数）で操る
     */
    private function runInNode(string $steps): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので二度押し止めの JavaScript の実駆動を飛ばす');
        }

        $found = preg_match('/<script>\s*(function submitOnce\(options\) \{.*?)<\/script>/su', $this->renderedPage(), $m);
        $this->assertSame(1, $found, '部品の script（function submitOnce）が描かれていない');

        $prelude = <<<'JS'
            const fs = require('fs');
            const vm = require('vm');
            const nav = { type: 'navigate' };
            const reloads = { count: 0 };
            const context = vm.createContext({
                window: {
                    location: { reload() { reloads.count++; } },
                    performance: { getEntriesByType(kind) { return kind === 'navigation' ? [{ type: nav.type }] : []; } },
                },
            });
            vm.runInContext(fs.readFileSync(process.argv[1], 'utf8'), context, { filename: '_partials/_submit_once.blade.php' });
            const make = (code) => vm.runInContext(code, context);
            const press = (data) => {
                const event = { cancelled: false, preventDefault() { this.cancelled = true; } };
                data.onSubmit(event);
                return event.cancelled;
            };
            let result;
            JS;
        $harness = $prelude . "\n" . $steps . "\nprocess.stdout.write(JSON.stringify(result));\n";

        $file = tempnam(sys_get_temp_dir(), 'submit-once-js-');
        try {
            file_put_contents($file, $m[1]);
            $output = shell_exec(sprintf('%s -e %s %s 2>&1', escapeshellarg($node), escapeshellarg($harness), escapeshellarg($file)));
        } finally {
            unlink($file);
        }

        $result = json_decode((string) $output, true);
        $this->assertIsArray($result, "node で submitOnce() を動かせなかった:\n" . $output);

        return $result;
    }

    public function test_the_first_submit_goes_through_and_the_rest_are_cancelled_until_the_page_is_shown_again(): void
    {
        // 確認画面（reloadOnReturn なし）は、「戻る」で戻っても読み込み直さない（押すとサーバの鍵が断る）
        $this->assertSame(
            [
                'before' => false, 'first' => false, 'afterFirst' => true, 'second' => true, 'third' => true,
                'afterBfcache' => false, 'again' => false, 'afterAgain' => true, 'afterBack' => false, 'reloads' => 0,
            ],
            $this->runInNode(<<<'JS'
                const data = make('submitOnce()');
                result = { before: data.submitting };
                result.first = press(data);
                result.afterFirst = data.submitting;
                result.second = press(data);
                result.third = press(data);
                nav.type = 'back_forward';
                data.onPageShow({ persisted: true });
                result.afterBfcache = data.submitting;
                result.again = press(data);
                result.afterAgain = data.submitting;
                data.onPageShow({ persisted: false });
                result.afterBack = data.submitting;
                result.reloads = reloads.count;
                JS)
        );
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: string, 3: int, 4: bool}>
     *   [pageshow の persisted, 送ったあとか, ナビゲーションの種類, 期待する reload() の回数, 期待する submitting]
     */
    public static function returnsToTheImportArea(): array
    {
        return [
            '送ったあと bfcache から戻った'               => [true,  true,  'navigate',     1, true],
            '送らずに別の画面へ移り bfcache から戻った'    => [true,  false, 'navigate',     0, false],
            'bfcache から戻った（元の表示も「戻る」だった）' => [true,  false, 'back_forward', 0, false],
            'bfcache を使わずに「戻る」で表示した'         => [false, false, 'back_forward', 1, false],
            '読み込み直したあと（繰り返さない）'           => [false, false, 'reload',       0, false],
            'ふつうに開いた'                               => [false, false, 'navigate',     0, false],
        ];
    }

    /**
     * 周辺ビル（reloadOnReturn: true）は、送ったあと bfcache から戻ったとき・bfcache を使わずに「戻る」で表示したときだけ
     * 読み込み直して新しい鍵にする（設計書 §4.5）。作業の途中で別の画面へ移って bfcache から戻ったときは、
     * 読み込んだファイルと列の対応づけを残す（鍵もまだ使える）。
     */
    #[DataProvider('returnsToTheImportArea')]
    public function test_the_import_area_reloads_only_when_it_comes_back_after_sending_or_without_its_state(
        bool $persisted,
        bool $submitted,
        string $navigationType,
        int $reloads,
        bool $submittingAfter
    ): void {
        $steps = sprintf(
            <<<'JS'
                nav.type = %s;
                const data = make('submitOnce({ reloadOnReturn: true })');
                if (%s) { press(data); }
                data.onPageShow({ persisted: %s });
                result = { reloads: reloads.count, submitting: data.submitting };
                JS,
            json_encode($navigationType),
            $submitted ? 'true' : 'false',
            $persisted ? 'true' : 'false'
        );

        $this->assertSame(['reloads' => $reloads, 'submitting' => $submittingAfter], $this->runInNode($steps));
    }

    public function test_the_script_is_defined_once_in_the_scripts_stack(): void
    {
        // 部品を読み込んだ場所には何も出さない（レイアウトの scripts のスタックへ積む）
        $this->assertStringNotContainsString('function submitOnce(', Blade::render("@include('_partials._submit_once')"));

        // 1 つのページで 2 回読み込んでも、定義は 1 回だけ
        $page = $this->renderedPage("@include('_partials._submit_once')\n@include('_partials._submit_once')");
        $this->assertSame(1, substr_count($page, 'function submitOnce('), '部品を 2 回読み込むと、関数が 2 回定義される');
        $this->assertSame(1, substr_count($page, 'function submitOnceNavigationType('));
    }
}
```

- [ ] **Step 2: 部品が無いので落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/SubmitOnceTest.php 2>&1 | grep -e 'not found' -e '^Tests:' | sort | uniq -c
```

期待: `Tests: 8, Assertions: 0, Errors: 8.` と、どれも `View [_partials._submit_once] not found.`

- [ ] **Step 3: 部品を作る**（`resources/views/_partials/_submit_once.blade.php`）

```blade
{{--
    確定のボタンの二度押し止め（設計書 2026-09-28-import-double-submit-design.md §4.5）。関数 submitOnce() の唯一の定義。
    確定のフォームを持つ画面が、フォームと同じ if の中でこの部品を読み込み、フォームにこう書く:
      x-data="submitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="onPageShow($event)"
      ボタンは :disabled="submitting"、横の role="status" に「取り込んでいます…」（x-text で文字だけ変える）

    ・1 回目の送信は通して submitting を立て、2 回目以降は送信を取り消す（最後の歯止めはサーバの 1 回限りの鍵）
    ・pageshow（window にだけ届く）で印を下ろす。event.persisted で絞らない（Bug #65）
    ・reloadOnReturn: true（周辺ビル。確認画面が無く、GET の取込の画面で鍵を出す）は、次の 2 つのときだけ読み込み直して
      新しい鍵にする: ① bfcache から戻り、かつ送ったあと ② bfcache を使わずに「戻る」で表示した（ナビゲーションの種類が
      back_forward）。それ以外（作業の途中で別の画面へ移って戻った）は、読み込んだファイルと列の対応づけを残して印を下ろすだけ。
      読み込み直したあとは種類が reload になるので、繰り返さない
    ⚠ 説明はこの Blade コメントに書く。script の中のコメントにアットマークで始まる語やコンポーネントのタグを書くと、
      Blade が展開して壊す（Bug #30）
    ⚠ once で 1 ページに 1 回だけ出し、レイアウトの scripts のスタック（Alpine の起動より前に動く）へ積む
--}}
@once
    @push('scripts')
        <script>
        function submitOnce(options) {
            var reloadOnReturn = !!(options && options.reloadOnReturn);
            return {
                submitting: false,
                onSubmit: function (event) {
                    if (this.submitting) {
                        event.preventDefault();
                        return;
                    }
                    this.submitting = true;
                },
                onPageShow: function (event) {
                    if (reloadOnReturn && (event.persisted ? this.submitting : submitOnceNavigationType() === 'back_forward')) {
                        window.location.reload();
                        return;
                    }
                    this.submitting = false;
                }
            };
        }
        function submitOnceNavigationType() {
            var entries = window.performance && window.performance.getEntriesByType
                ? window.performance.getEntriesByType('navigation') : [];
            return entries.length > 0 ? entries[0].type : '';
        }
        </script>
    @endpush
@endonce
```

⚠ `<script>` の中にコメントを足さない（Bug #30）。部品の説明を変えるときは上の Blade コメントを直す。

- [ ] **Step 4: 通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/SubmitOnceTest.php tests/Feature/LayoutScriptStackTest.php tests/Feature/LayoutMeasuringScriptTest.php 2>&1 | tail -3
```

期待: `OK (14 tests, 252 assertions)`（うち `SubmitOnceTest` が 8 本・24。`LayoutScriptStackTest`・`LayoutMeasuringScriptTest` は部品の script を拾っても落ちない＝幅もスクロールも測らない）

- [ ] **Step 5: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && git add resources/views/_partials/_submit_once.blade.php tests/Feature/SubmitOnceTest.php && git commit -m "$(cat <<'EOF'
feat: 確定のボタンの二度押し止めの部品を足す

関数 submitOnce() を 1 か所だけで定義し（@once・scripts のスタック）、6 つの取込の確定の画面から使う。
1 回目の送信だけを通し、pageshow で印を下ろす。周辺ビル（reloadOnReturn）は送ったあとの「戻る」で
読み込み直す。振る舞いは node の vm で固定する。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 2: テナントの取込（5 タブ）と測りの道具

**Files:**
- Modify: `tests/Concerns/ParsesForms.php`（`htmlAttr()` を protected に）
- Create: `tests/Concerns/ChecksDoubleSubmit.php`
- Create: `tests/Feature/Admin/TenantImportDoubleSubmitTest.php`
- Modify: `app/Http/Controllers/Admin/TenantImportController.php`（`use`・クラスの docblock・断りの表・5 タブのプレビューで鍵・`loadCsv()` の docblock と確定の分岐）
- Modify: `resources/views/admin/tenant-import/_preview.blade.php`（必要変数の注記・確定のフォーム）

**Interfaces:**
- Consumes: Task 1 の `@include('_partials._submit_once')` と `submitOnce()`・`Tests\Concerns\SubmitsImportPreview` の `preview($tab, $csv)`・`parseImportForm($html, $tab)`・`executive()`（既存。`MansionImportTest` から切り出したもの）・`Tests\Concerns\ParsesForms::parseForm($html, $needle)`
- Produces（Task 3〜7 が使う）: トレイト `Tests\Concerns\ChecksDoubleSubmit` の 3 つ —
  - `countingWrites(callable $send): array` → `[$send の戻り値, INSERT / UPDATE / DELETE / REPLACE の SQL の数]`
  - `assertRefused(TestResponse $response, string $location, string $message, User $user): void` — 302・`Location` が `$location`・`session('error')` が `$message`・`$location` を `$user` で開くと赤帯 `<span class="text-sm text-red-800">` の中に `$message`。⚠ 要求の直後（次の要求の前）に呼ぶ
  - `assertSubmitOnceForm(string $html, string $action, string $xData = 'submitOnce()', string $statusText = '取り込んでいます…', string $opacityClass = 'disabled:opacity-60'): void`
  - 使う側は `ParsesForms` も use する（`htmlAttr()` を借りる）

- [ ] **Step 1: `htmlAttr()` を protected にする**（`tests/Concerns/ParsesForms.php`。Edit）

置き換える前:

```php
     *   空なので、`:` `.` `@` の直後も除外して空を返させる。
     */
    private function htmlAttr(string $tag, string $name): ?string
```

置き換えた後:

```php
     *   空なので、`:` `.` `@` の直後も除外して空を返させる。
     * ⚠ protected（2026-09-28）。`Tests\Concerns\ChecksDoubleSubmit` は、このトレイトを使う基底クラス
     *   （ScheduleTestCase・AreaBuildingTestCase）の子からもこれを呼ぶ。private だと子から呼べない。
     */
    protected function htmlAttr(string $tag, string $name): ?string
```

⚠ `htmlAttr` の定義は `tests/` にこの 1 か所だけ（`grep -rn "function htmlAttr" tests/` で確かめる）。

- [ ] **Step 2: 測りの道具を作る**（`tests/Concerns/ChecksDoubleSubmit.php`）

```php
<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * 確定を 2 回送る測りの道具（設計書 2026-09-28-import-double-submit-design.md §5.1・§5.4）。
 *
 * 使う側は `ParsesForms`（htmlAttr() を借りる）と一緒に use する。
 *
 * ⚠ 断りは、戻り先・役割（`error` のフラッシュ）・表示（戻り先の画面の赤帯）を分けて見る。
 *   `assertSessionHas*()` は使わない（そのあと描いた画面から表示が消えることがある。Bug #49）。
 * ⚠ 2 回目で何も書かないことは、件数に加えて SQL の書き込みの数で見る（工程表の入れ替えは消してから同じ数を入れ、
 *   シート取込は同じ値のセルを飛ばして履歴だけを足すので、件数だけでは見落とす）。
 */
trait ChecksDoubleSubmit
{
    /** 数えている間の書き込みの SQL の数（null のあいだは数えない） */
    private ?int $writeCount = null;

    private bool $listeningForWrites = false;

    /**
     * $send のあいだに走った INSERT / UPDATE / DELETE の数。
     *
     * @return array{0: mixed, 1: int} [$send の戻り値, 書き込みの SQL の数]
     */
    private function countingWrites(callable $send): array
    {
        if (! $this->listeningForWrites) {
            DB::listen(function ($query): void {
                if ($this->writeCount !== null && preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
                    $this->writeCount++;
                }
            });
            $this->listeningForWrites = true;
        }

        $this->writeCount = 0;
        try {
            $result = $send();
        } finally {
            $count = $this->writeCount;
            $this->writeCount = null;
        }

        return [$result, $count];
    }

    /**
     * 断られたこと。⚠ 要求の直後（ほかの要求を送る前）に呼ぶ（`error` のフラッシュは次の要求で消える）。
     */
    private function assertRefused(TestResponse $response, string $location, string $message, User $user): void
    {
        $this->assertSame(
            302,
            $response->getStatusCode(),
            '断りが転送になっていない' . ($response->exception ? '（' . get_class($response->exception) . ': ' . $response->exception->getMessage() . '）' : '')
        );
        $this->assertSame($location, $response->headers->get('Location'), '断ったあとの戻り先が違う');
        // 役割: error のフラッシュに全文が入っている
        $this->assertSame($message, session('error'), '断りの文言（error のフラッシュ）が違う');

        // 表示: 戻り先を開くと、レイアウトの赤帯（layouts/app.blade.php）の中に全文が出る
        $html = $this->actingAs($user)->get($location)->getContent();
        $this->assertStringContainsString(
            '<span class="text-sm text-red-800">' . e($message) . '</span>',
            $html,
            '戻り先の画面の赤帯に、断りの文言が出ていない'
        );
    }

    /**
     * 確定のフォームが二度押し止めの部品（_partials/_submit_once）につながっていること（設計書 §4.5・§5.4）。
     * どれも**その要素に**付いていることを見る（ページのどこかに在るだけでは足りない。Bug #47）。
     *
     * @param  string  $action        確定のフォームの action（`action="…"` ごと探す。ParsesForms と同じ理由）
     * @param  string  $xData         フォームの x-data の値
     * @param  string  $statusText    送信中の文字
     * @param  string  $opacityClass  送信中の薄さのクラス（周辺ビルはいまの disabled:opacity-50 のまま）
     */
    private function assertSubmitOnceForm(
        string $html,
        string $action,
        string $xData = 'submitOnce()',
        string $statusText = '取り込んでいます…',
        string $opacityClass = 'disabled:opacity-60'
    ): void {
        $needle = 'action="' . $action . '"';
        $pos    = strpos($html, $needle);
        $this->assertNotFalse($pos, "確定のフォームが無い: {$needle}");

        $open  = strrpos(substr($html, 0, $pos), '<form');
        $close = strpos($html, '</form>', $pos);
        $this->assertNotFalse($open, "{$needle} を含む <form> の開始タグが無い");
        $this->assertNotFalse($close, "{$needle} を含む <form> が閉じていない");

        $form    = substr($html, $open, $close - $open);
        $openTag = substr($form, 0, strpos($form, '>') + 1);

        // 部品を使う印は、確定のフォームそのものに付ける
        $this->assertSame($xData, $this->htmlAttr($openTag, 'x-data'), '確定のフォームの x-data が二度押し止めの部品でない');
        $this->assertStringContainsString('x-on:submit="onSubmit($event)"', $openTag, '確定のフォームが送信を部品に渡していない');
        // pageshow は window にしか届かない（Bug #65）
        $this->assertStringContainsString('x-on:pageshow.window="onPageShow($event)"', $openTag, '確定のフォームが pageshow を部品に渡していない');

        // 1 回限りの鍵
        $this->assertSame(
            1,
            preg_match('/<input\b[^>]*(?<![\w:.@-])name="import_token"[^>]*>/u', $form, $token),
            '確定のフォームに 1 回限りの鍵（import_token）の hidden が無い'
        );
        $this->assertSame('hidden', $this->htmlAttr($token[0], 'type'));
        $this->assertNotSame('', (string) $this->htmlAttr($token[0], 'value'), '1 回限りの鍵（import_token）の値が描かれていない');

        // 送信ボタン（1 つ）
        $this->assertSame(1, preg_match_all('/<button\b[^>]*\btype="submit"[^>]*>/u', $form, $buttons), '確定のフォームの送信ボタンが 1 つでない');
        $button = $buttons[0][0];
        $this->assertMatchesRegularExpression('/\s:disabled="[^"]*\bsubmitting\b[^"]*"/', $button, '送信中にボタンを押せなくしていない');
        $classes = preg_split('/\s+/', (string) $this->htmlAttr($button, 'class'));
        foreach (['cursor-pointer', 'disabled:cursor-not-allowed', $opacityClass] as $class) {
            $this->assertContains($class, $classes, "送信ボタンに {$class} が無い");
        }
        // style の cursor はクラスより強い（送信中もカーソルが変わらない）
        $this->assertStringNotContainsString('cursor', (string) $this->htmlAttr($button, 'style'), '送信ボタンの style に cursor がある');
        // 押せなくしたボタンは送る中身から落ちる（name を付けると、その値が届かなくなる）
        $this->assertNull($this->htmlAttr($button, 'name'), '送信ボタンに name がある');

        // 送信中の文字は、フォームの中にいつも置いて、文字だけ変える
        $this->assertSame(1, preg_match('/<span\b[^>]*\brole="status"[^>]*>/u', $form, $status), '確定のフォームに role="status" が無い');
        $this->assertStringContainsString('x-text="submitting ? \'' . $statusText . '\' : \'\'"', $status[0]);
        // 375px で文字の途中で折り返さず、まとまって次の行へ移る（顧客の取込で実測）
        $this->assertStringContainsString('display: inline-block', (string) $this->htmlAttr($status[0], 'style'), '送信中の文字が inline-block でない');
    }
}
```

- [ ] **Step 3: テナントのテストを書く**（`tests/Feature/Admin/TenantImportDoubleSubmitTest.php`）

```php
<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\TenantImportController;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\Concerns\ChecksDoubleSubmit;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * テナントの CSV 取込（5 タブ）の確定を、確認画面 1 つにつき 1 回だけ通す（設計書 2026-09-28-import-double-submit-design.md）。
 *
 * ⚠ 直す前（2026-09-28 に実測）: 同じ確認画面の確定を 2 回送ると、契約・過去契約は 2 件が 4 件になった。
 *   物件・区画・顧客は重複の確認で 2 回目が「0件を登録しました」になり、取り込み直したように見えた。
 * ⚠ タブは TenantImportController の public な execute{X} を機械的に列挙し、下の TABS と突き合わせる
 *   （新しいタブが増えたら落ちる。コントローラの断りの表にタブが無いと、そのタブの 2 回目が 500 になる）。
 *   タブのキーからは列挙しない（区切りの文字が取込ごとにそろっていない。テナントは past-contract）。
 * ⚠ 同じ利用者で、画面が描いた確定のフォームを 1 回だけ分解して 2 回送る（SubmitsImportPreview::confirm() は
 *   呼ぶたびにプレビューからやり直すので使わない）。
 */
class TenantImportDoubleSubmitTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use SubmitsImportPreview;
    use ChecksDoubleSubmit;

    /** execute{X} => [URL のタブ, 数える表, 2 回目の断りの全文（設計書 §4.4）] */
    private const TABS = [
        'executeProperty' => [
            'property', 'properties',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「物件一覧」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executeUnit' => [
            'unit', 'units',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「部屋一覧」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executeCustomer' => [
            'customer', 'customers',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「顧客一覧」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executeContract' => [
            'contract', 'contracts',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「契約一覧」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executePastContract' => [
            'past-contract', 'contracts',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「契約一覧」でステータスを「解約済み」にして確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
    ];

    private function importBasePath(): string
    {
        return '/admin/tenant-import';
    }

    /** @return array<string, array{0: string}> */
    public static function tabs(): array
    {
        $methods = array_keys(self::TABS);

        return array_combine($methods, array_map(fn (string $method) => [$method], $methods));
    }

    /** @return array<string, array{0: string|list<string>|null}> [import_token に入れる値（null なら送らない）] */
    public static function unusableTokens(): array
    {
        return [
            '鍵が無い' => [null],
            '鍵が空'   => [''],
            '鍵が配列' => [['a', 'b']],
        ];
    }

    public function test_every_tab_is_classified(): void
    {
        $methods = [];
        foreach ((new ReflectionClass(TenantImportController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class === TenantImportController::class && preg_match('/^execute[A-Z]/', $method->name) === 1) {
                $methods[] = $method->name;
            }
        }
        sort($methods);

        $classified = array_keys(self::TABS);
        sort($classified);

        $this->assertSame($classified, $methods, 'TABS と TenantImportController の execute{X} がそろっていない（新しいタブは、コントローラの断りの表とこのテストの両方に足す）');
    }

    #[DataProvider('tabs')]
    public function test_sending_the_same_confirmation_twice_imports_once(string $method): void
    {
        [$tab, $table, $message] = self::TABS[$method];
        $csv  = $this->arrange($method);
        $user = $this->executive();
        $form = $this->parseImportForm($this->preview($tab, $csv)->getContent(), $tab);

        $before = DB::table($table)->count();
        $this->send($user, $tab, $form);
        $this->assertSame($before + 2, DB::table($table)->count(), '1 回目で 2 件が入っていない（測定が無効）');

        [$second, $writes] = $this->countingWrites(fn () => $this->send($user, $tab, $form));

        $this->assertSame(0, $writes, '2 回目の送信で書き込みが走った');
        $this->assertSame($before + 2, DB::table($table)->count(), '2 回目の送信で、もう一度入った');
        $this->assertRefused($second, route('admin.tenant-import', ['tab' => $tab]), $message, $user);
    }

    #[DataProvider('unusableTokens')]
    public function test_a_confirmation_without_a_usable_token_imports_nothing(string|array|null $token): void
    {
        $user = $this->executive();
        $form = $this->parseImportForm($this->preview('property', $this->arrange('executeProperty'))->getContent(), 'property');

        if ($token === null) {
            unset($form['fields']['import_token']);
        } else {
            $form['fields']['import_token'] = $token;
        }

        // 500 にならない（配列の鍵は OneTimeAction::claimFrom() が is_string で断る）
        [$response, $writes] = $this->countingWrites(fn () => $this->send($user, 'property', $form));

        $this->assertSame(0, $writes, '鍵が使えないのに書き込みが走った');
        $this->assertSame(0, Property::count());
        $this->assertRefused($response, route('admin.tenant-import', ['tab' => 'property']), self::TABS['executeProperty'][2], $user);
    }

    public function test_uploading_the_same_file_again_issues_a_new_token(): void
    {
        $csv    = $this->arrange('executeProperty');
        $first  = $this->parseImportForm($this->preview('property', $csv)->getContent(), 'property');
        $second = $this->parseImportForm($this->preview('property', $csv)->getContent(), 'property');

        // ⚠ 別のファイルで 2 回プレビューする形では、鍵をファイルの中身から作る書き換え（上げ直しても同じ鍵になり、
        //   12 時間断られる）を見逃す（2026-09-27 の顧客の取込のレビューで実測）
        $this->assertNotSame($first['fields']['import_token'], $second['fields']['import_token'], 'プレビューごとに鍵が変わっていない');

        $this->send($this->executive(), 'property', $second);
        $this->assertSame(2, Property::count(), '上げ直したプレビューの鍵で取り込めない');
    }

    public function test_the_confirmation_form_guards_against_a_second_press(): void
    {
        $html = $this->preview('property', $this->arrange('executeProperty'))->getContent();

        $this->assertSubmitOnceForm($html, url('/admin/tenant-import/property'));
    }

    // ================================================================
    // 部品
    // ================================================================

    /** 確認画面から確定を送る（ブラウザと同じく、リファラーは確認画面の URL） */
    private function send($user, string $tab, array $form): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)
            ->from(url($this->importBasePath() . "/{$tab}"))
            ->post($form['action'], $form['fields']);
    }

    /** タブごとの前提のデータを入れ、2 行の CSV を返す（2026-09-28 の実測と同じ中身） */
    private function arrange(string $method): string
    {
        switch ($method) {
            case 'executeProperty':
                return "物件名,郵便番号,住所,構造,築年月,階数,所有区分,オーナー名,稼働状態\n"
                    . "二重ビルA,790-0001,愛媛県松山市一番町1-1,RC造,2000-03,3,自社,,稼働中\n"
                    . "二重ビルB,790-0002,愛媛県松山市二番町2-2,S造,,5,オーナー,大家太郎,稼働中\n";

            case 'executeUnit':
                $this->property();

                return "物件名,階,部屋番号,面積(坪),用途,状態,募集家賃,募集共益費,募集敷金,募集ゴミ代,募集駆除代\n"
                    . "二重ビル,1,A,15.5,,空室,80000,5000,160000,1000,500\n"
                    . "二重ビル,2,B,20.0,,空室,100000,8000,200000,1500,500\n";

            case 'executeCustomer':
                return "テナント名,テナントカナ,種別,代表者名,担当者,電話番号,メールアドレス,郵便番号,住所\n"
                    . "二重商事,ニジュウショウジ,法人,山田太郎,鈴木花子,089-999-9999,info@nijuu.example.jp,790-0002,愛媛県松山市二番町2-2\n"
                    . "二重個人店,,個人事業主,,,,,,\n";

            case 'executeContract':
                $property = $this->property();
                $this->unit($property, 1, 'A');
                $this->unit($property, 2, 'B');
                Customer::create(['code' => 'CU-DS-1', 'name' => '二重商事', 'customer_type' => 'corporation']);
                Customer::create(['code' => 'CU-DS-2', 'name' => '二重個人店', 'customer_type' => 'sole_proprietor']);

                // ⚠ テナント名と賃料開始日を埋める（テスト用スキーマの contracts.customer_id / rent_start_date が NOT NULL。Bug #60）
                return "物件名,階,部屋番号,テナント名,契約日,賃料開始日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考\n"
                    . "二重ビル,1,A,二重商事,2026-04-01,2026-04-01,95000,8000,190000,1500,500,二重商事 松山支店,\n"
                    . "二重ビル,2,B,二重個人店,2026-05-01,2026-05-01,100000,,,,,,\n";

            case 'executePastContract':
                $property = $this->property();
                $this->unit($property, 1, 'A');
                $this->unit($property, 2, 'B');
                // 1 行目の顧客は無い（自動作成される）・2 行目の顧客は既存
                Customer::create(['code' => 'CU-DS-1', 'name' => '二重商事', 'customer_type' => 'corporation']);

                return "物件名,階,部屋番号,テナント名,契約日,賃料開始日,解約日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考\n"
                    . "二重ビル,1,A,過去商事,2020-04-01,2020-04-01,2023-03-31,95000,8000,190000,1500,500,過去商事 松山支店,期間満了で解約\n"
                    . "二重ビル,2,B,二重商事,2019-04-01,2019-04-01,2021-03-31,90000,,,,,,\n";
        }

        $this->fail("前提のデータが無いタブ: {$method}");
    }

    private function property(): Property
    {
        return Property::create([
            'code' => 'T-DS-1', 'name' => '二重ビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 5,
        ]);
    }

    private function unit(Property $property, int $floor, string $room): Unit
    {
        return Unit::create([
            'property_id' => $property->id, 'floor' => $floor, 'room_number' => $room,
            'display_name' => Unit::generateDisplayName($floor, $room), 'status' => 'vacant', 'area_tsubo' => 10,
        ]);
    }
}
```

- [ ] **Step 4: 直す前のコードで落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/TenantImportDoubleSubmitTest.php 2>&1 | grep -E -A1 -e '^[0-9]+\) ' -e '^Tests:' | grep -v -e '^--$'
```

期待: `Tests: 11, Assertions: 100, Errors: 1, Failures: 9.`（`test_every_tab_is_classified` だけ緑）。落ちたテストと理由（各テストの次の行）:
- エラー `test_uploading_the_same_file_again_issues_a_new_token` — `ErrorException: Undefined array key "import_token"`（確認画面に鍵が無い）
- `test_sending_the_same_confirmation_twice_imports_once` の物件・区画・顧客 — `断りの文言（error のフラッシュ）が違う`（2 回目は重複の確認で 0 件になり、成功の帯が出る）
- 同じテストの契約・過去契約 — `2 回目の送信で書き込みが走った`（2 件が 4 件になる）
- `test_a_confirmation_without_a_usable_token_imports_nothing` の 3 本 — `鍵が使えないのに書き込みが走った`
- `test_the_confirmation_form_guards_against_a_second_press` — `確定のフォームの x-data が二度押し止めの部品でない`

- [ ] **Step 5: コントローラ — `use` とクラスの docblock**（`app/Http/Controllers/Admin/TenantImportController.php`。Edit を 2 回）

1 回目 — 置き換える前:

```php
use App\Support\JapanTime;
use Illuminate\Http\Request;
```

置き換えた後:

```php
use App\Support\JapanTime;
use App\Support\OneTimeAction;
use Illuminate\Http\Request;
```

2 回目 — 置き換える前:

```php
 */
class TenantImportController extends Controller
```

置き換えた後:

```php
 * ⚠ 確定は確認画面 1 つにつき 1 回だけ（hidden の `import_token`・`OneTimeAction`。
 *   設計書 2026-09-28-import-double-submit-design.md §4.2）。鍵は 5 タブのプレビューで出し、`loadCsv()` の確定の分岐の
 *   最初（CSV を読み直す前）で使う。直す前は、同じ確認画面の確定を 2 回送ると契約・過去契約が二重に入った
 *   （物件・区画・顧客は重複の確認で 2 回目が「0件を登録しました」になった）。
 */
class TenantImportController extends Controller
```

- [ ] **Step 6: コントローラ — 断りの表**（Edit。「画面表示」の見出しの前に足す。見出しはファイルに 1 か所）

置き換える前:

```php
    // ================================================================
    // 画面表示
    // ================================================================
```

置き換えた後:

```php
    /**
     * 同じ確認画面から 2 回目を送ったときの案内の、「確かめられます」の前の句（タブのキー => 句。設計書 §4.4）。
     * ⚠ タブを足したら、ここにも足す（無いと、そのタブの 2 回目が 500 になる。
     *   TenantImportDoubleSubmitTest が execute{X} を列挙して、タブごとに 2 回目の断りを確かめる）
     * ⚠ 絞り込みを案内するのは過去契約だけ（「契約一覧」は何も選ばないと契約中だけを出すので、過去契約が見えず
     *   「入っていない」と読んで上げ直しやすい。過去契約は上げ直すと注意も出ないまま二重になる）
     */
    private const WHERE_TO_CHECK = [
        'property'      => '「物件一覧」で',
        'unit'          => '「部屋一覧」で',
        'customer'      => '「顧客一覧」で',
        'contract'      => '「契約一覧」で',
        'past-contract' => '「契約一覧」でステータスを「解約済み」にして',
    ];

    // ================================================================
    // 画面表示
    // ================================================================
```

- [ ] **Step 7: コントローラ — 5 タブのプレビューで鍵を出す**（Edit・`replace_all`。先に 5 か所あることを確かめる）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && grep -cF "                'csvData'      => base64_encode(\$content)," app/Http/Controllers/Admin/TenantImportController.php
```

期待: `5`（5 タブのプレビューの `view()` の中だけ）

置き換える前（5 か所とも同じ）:

```php
                'csvData'      => base64_encode($content),
```

置き換えた後:

```php
                'csvData'      => base64_encode($content),
                // 確定を 1 回だけ通す鍵（クラスの docblock）
                'importToken'  => OneTimeAction::issue(),
```

- [ ] **Step 8: コントローラ — `loadCsv()` の docblock と確定の分岐**（Edit を 2 回）

1 回目 — 置き換える前:

```php
     * HTTP 依存の 3 つだけ: ファイル取得 / 確定時の base64 復元 / 差し戻し。
```

置き換えた後:

```php
     * HTTP 依存の 4 つだけ: ファイル取得 / 確定時の 1 回限りの鍵 / 確定時の base64 復元 / 差し戻し。
```

2 回目 — 置き換える前:

```php
        if ($request->boolean('confirmed')) {
            // 確認画面が持ち回った base64 から復元（既に UTF-8・BOM 除去済み）
```

置き換えた後:

```php
        if ($request->boolean('confirmed')) {
            // 確定は確認画面 1 つにつき 1 回だけ（クラスの docblock）。⚠ CSV を読み直す前に使う
            if (! OneTimeAction::claimFrom($request, 'import_token')) {
                return redirect()->route('admin.tenant-import', ['tab' => $tab])
                    ->with('error', 'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは'
                        . self::WHERE_TO_CHECK[$tab] . '確かめられます。取り込み直すときは、CSVをアップロードし直してください。');
            }

            // 確認画面が持ち回った base64 から復元（既に UTF-8・BOM 除去済み）
```

⚠ 5 タブとも `loadCsv()` の差し戻しをそのまま返す（設計書 §2.3。`loadCsv()` の戻り値が転送なら、そのタブは何もしないで返す）ので、ここ 1 か所で全タブに効く。

- [ ] **Step 9: 確認画面**（`resources/views/admin/tenant-import/_preview.blade.php`。Edit を 2 回）

1 回目 — 置き換える前:

```blade
{{-- 必要変数: $tab, $routeName, $entityLabel, $totalRows, $validCount, $rowErrors, $skippedRows, $warnings(任意), $summary, $csvData --}}
```

置き換えた後:

```blade
{{-- 必要変数: $tab, $routeName, $entityLabel, $totalRows, $validCount, $rowErrors, $skippedRows, $warnings(任意), $summary, $csvData, $importToken --}}
```

2 回目 — 置き換える前:

```blade
        @if($validCount > 0)
            <form method="POST" action="{{ route($routeName) }}">
                @csrf
                <input type="hidden" name="confirmed" value="1">
                <input type="hidden" name="csv_data" value="{{ $csvData }}">

                <button type="submit"
                        style="background: #059669; color: #fff; padding: 10px 28px; border-radius: 6px; font-size: 15px; font-weight: 600; border: none; cursor: pointer;">
                    インポート実行（{{ $validCount }}件）
                </button>
```

置き換えた後:

```blade
        @if($validCount > 0)
            {{-- ⚠ 確定は確認画面 1 つにつき 1 回だけ（hidden の import_token。JS が動かないときも、サーバが 2 回目を断る）。
                 送信中はボタンを押せなくする（二度押し止めの部品。設計書 2026-09-28-import-double-submit-design.md §4.5） --}}
            @include('_partials._submit_once')
            <form method="POST" action="{{ route($routeName) }}"
                  x-data="submitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="onPageShow($event)">
                @csrf
                <input type="hidden" name="confirmed" value="1">
                <input type="hidden" name="csv_data" value="{{ $csvData }}">
                <input type="hidden" name="import_token" value="{{ $importToken }}">

                <button type="submit" :disabled="submitting"
                        class="cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"
                        style="background: #059669; color: #fff; padding: 10px 28px; border-radius: 6px; font-size: 15px; font-weight: 600; border: none;">
                    インポート実行（{{ $validCount }}件）
                </button>
                <span role="status" x-text="submitting ? '取り込んでいます…' : ''" style="display: inline-block; margin-left: 12px; font-size: 13px; color: #374151;"></span>
```

⚠ このフォームは `tenantImportTabs()` の `x-data` の中にある（入れ子）。`submitting`・`onSubmit`・`onPageShow` は親の画面のどこにも無い名前（設計書 §2.5）。

- [ ] **Step 10: 通ることと、関係するテストが緑のままであることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/TenantImportDoubleSubmitTest.php 2>&1 | tail -1 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/TenantUnitImportTest.php tests/Feature/Admin/TenantImportRejectionTest.php tests/Feature/Admin/ImportPreviewRenderTest.php tests/Feature/Admin/ImportValidationFeedbackTest.php tests/Feature/ImportControllerReturnPathScanTest.php tests/Feature/ImportControllerValidationRedirectScanTest.php 2>&1 | tail -1
```

期待: `OK (11 tests, 145 assertions)` ／ `OK (125 tests, 455 assertions)`（既存の取込のテストは、画面が描いた確定のフォームを分解して送るので、鍵も一緒に運ぶ。取込の走査テスト 2 本は、新しい断りが `route()` の転送で、`back(` も包まない入力チェックも増やさないので変えずに通る）

- [ ] **Step 11: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && git add tests/Concerns/ParsesForms.php tests/Concerns/ChecksDoubleSubmit.php tests/Feature/Admin/TenantImportDoubleSubmitTest.php app/Http/Controllers/Admin/TenantImportController.php resources/views/admin/tenant-import/_preview.blade.php && git commit -m "$(cat <<'EOF'
fix: テナントのCSV取込の確定を確認画面 1 つにつき 1 回だけにする

同じ確認画面から 2 回送ると契約・過去契約が二重に入っていた。5 タブのプレビューで 1 回限りの鍵を出し、
loadCsv() の確定の分岐の最初で使う。使えなければ同じタブへ戻し、タブごとに確かめる画面を案内する。
確認画面は二度押し止めの部品を使う。測りの道具 ChecksDoubleSubmit を足す。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 3: 賃貸マンションの取込（6 タブ）

**Files:**
- Create: `tests/Feature/Admin/MansionImportDoubleSubmitTest.php`
- Modify: `app/Http/Controllers/Admin/MansionImportController.php`（`use`・クラスの docblock・断りの表・6 タブのプレビューで鍵・`loadCsv()` の docblock と確定の分岐）
- Modify: `resources/views/admin/mansion-import/_preview.blade.php`（必要変数の注記・確定のフォーム）

**Interfaces:**
- Consumes: Task 1 の部品・Task 2 の `ChecksDoubleSubmit`（`countingWrites()`・`assertRefused()`・`assertSubmitOnceForm()`）・既存の `Tests\Concerns\CreatesMansionSchema::createMansionSchema()`・`SubmitsImportPreview`
- Produces: 無し（Task 8 の全件分類が、この画面と `loadCsv()` の鍵も数える）

⚠ テナントとの違い: URL のタブは `room-contract`（ハイフン）、戻り先のタブのキーは `room_contract`（下線）で、そろっていない（設計書 §2.3）。断りの表のキーは戻り先のタブ（`loadCsv()` が受け取る `$tab`）。戻り先のクエリは `selected_tab`。

- [ ] **Step 1: テストを書く**（`tests/Feature/Admin/MansionImportDoubleSubmitTest.php`）

```php
<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\MansionImportController;
use App\Models\MsContract;
use App\Models\MsParking;
use App\Models\MsProperty;
use App\Models\MsRoom;
use App\Models\MsTenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\Concerns\ChecksDoubleSubmit;
use Tests\Concerns\CreatesMansionSchema;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * 賃貸マンションの CSV 取込（6 タブ）の確定を、確認画面 1 つにつき 1 回だけ通す（設計書 2026-09-28-import-double-submit-design.md）。
 *
 * ⚠ 直す前（2026-09-28 に実測）: 同じ確認画面の確定を 2 回送ると、部屋契約・駐車場契約は 2 件が 4 件になった。
 *   物件・部屋・駐車場・入居者は重複の確認で 2 回目が「0件を登録しました」になり、取り込み直したように見えた。
 * ⚠ タブは MansionImportController の public な execute{X} を機械的に列挙し、下の TABS と突き合わせる
 *   （TenantImportDoubleSubmitTest と同じ理由）。⚠ URL の区切りは room-contract（ハイフン）、
 *   戻り先のタブのキーは room_contract（下線）で、そろっていない。表は両方を持つ。
 */
class MansionImportDoubleSubmitTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMansionSchema;
    use ParsesForms;
    use SubmitsImportPreview;
    use ChecksDoubleSubmit;

    private const PROPERTY_NAME = '二重送信レジデンス';

    /** execute{X} => [URL のタブ, 戻り先のタブ, 数える表, 2 回目の断りの全文（設計書 §4.4）] */
    private const TABS = [
        'executeProperty' => [
            'property', 'property', 'ms_properties',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「物件一覧」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executeRoom' => [
            'room', 'room', 'ms_rooms',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「物件一覧」から開く物件の詳細で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executeParking' => [
            'parking', 'parking', 'ms_parkings',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「物件一覧」から開く物件の詳細で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executeTenant' => [
            'tenant', 'tenant', 'ms_tenants',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「入居者管理」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executeRoomContract' => [
            'room-contract', 'room_contract', 'ms_contracts',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「部屋契約一覧」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
        'executeParkingContract' => [
            'parking-contract', 'parking_contract', 'ms_parking_contracts',
            'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「駐車場契約一覧」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。',
        ],
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMansionSchema();
        $this->user = $this->executive();
    }

    private function importBasePath(): string
    {
        return '/admin/mansion-import';
    }

    /** @return array<string, array{0: string}> */
    public static function tabs(): array
    {
        $methods = array_keys(self::TABS);

        return array_combine($methods, array_map(fn (string $method) => [$method], $methods));
    }

    /** @return array<string, array{0: string|list<string>|null}> [import_token に入れる値（null なら送らない）] */
    public static function unusableTokens(): array
    {
        return [
            '鍵が無い' => [null],
            '鍵が空'   => [''],
            '鍵が配列' => [['a', 'b']],
        ];
    }

    public function test_every_tab_is_classified(): void
    {
        $methods = [];
        foreach ((new ReflectionClass(MansionImportController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class === MansionImportController::class && preg_match('/^execute[A-Z]/', $method->name) === 1) {
                $methods[] = $method->name;
            }
        }
        sort($methods);

        $classified = array_keys(self::TABS);
        sort($classified);

        $this->assertSame($classified, $methods, 'TABS と MansionImportController の execute{X} がそろっていない（新しいタブは、コントローラの断りの表とこのテストの両方に足す）');
    }

    #[DataProvider('tabs')]
    public function test_sending_the_same_confirmation_twice_imports_once(string $method): void
    {
        [$tab, $returnTab, $table, $message] = self::TABS[$method];
        $csv  = $this->arrange($method);
        $form = $this->parseImportForm($this->preview($tab, $csv)->getContent(), $tab);

        $before = DB::table($table)->count();
        $this->send($tab, $form);
        $this->assertSame($before + 2, DB::table($table)->count(), '1 回目で 2 件が入っていない（測定が無効）');

        [$second, $writes] = $this->countingWrites(fn () => $this->send($tab, $form));

        $this->assertSame(0, $writes, '2 回目の送信で書き込みが走った');
        $this->assertSame($before + 2, DB::table($table)->count(), '2 回目の送信で、もう一度入った');
        $this->assertRefused($second, route('admin.mansion-import', ['selected_tab' => $returnTab]), $message, $this->user);
    }

    #[DataProvider('unusableTokens')]
    public function test_a_confirmation_without_a_usable_token_imports_nothing(string|array|null $token): void
    {
        $form = $this->parseImportForm($this->preview('property', $this->arrange('executeProperty'))->getContent(), 'property');

        if ($token === null) {
            unset($form['fields']['import_token']);
        } else {
            $form['fields']['import_token'] = $token;
        }

        // 500 にならない（配列の鍵は OneTimeAction::claimFrom() が is_string で断る）
        [$response, $writes] = $this->countingWrites(fn () => $this->send('property', $form));

        $this->assertSame(0, $writes, '鍵が使えないのに書き込みが走った');
        $this->assertSame(0, MsProperty::count());
        $this->assertRefused($response, route('admin.mansion-import', ['selected_tab' => 'property']), self::TABS['executeProperty'][3], $this->user);
    }

    public function test_uploading_the_same_file_again_issues_a_new_token(): void
    {
        $csv    = $this->arrange('executeProperty');
        $first  = $this->parseImportForm($this->preview('property', $csv)->getContent(), 'property');
        $second = $this->parseImportForm($this->preview('property', $csv)->getContent(), 'property');

        // ⚠ 同じファイルで確かめる（TenantImportDoubleSubmitTest と同じ理由）
        $this->assertNotSame($first['fields']['import_token'], $second['fields']['import_token'], 'プレビューごとに鍵が変わっていない');

        $this->send('property', $second);
        $this->assertSame(2, MsProperty::count(), '上げ直したプレビューの鍵で取り込めない');
    }

    public function test_the_confirmation_form_guards_against_a_second_press(): void
    {
        $html = $this->preview('property', $this->arrange('executeProperty'))->getContent();

        $this->assertSubmitOnceForm($html, url('/admin/mansion-import/property'));
    }

    // ================================================================
    // 部品
    // ================================================================

    /** 確認画面から確定を送る（ブラウザと同じく、リファラーは確認画面の URL） */
    private function send(string $tab, array $form): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->from(url($this->importBasePath() . "/{$tab}"))
            ->post($form['action'], $form['fields']);
    }

    /** タブごとの前提のデータを入れ、2 行の CSV を返す（2026-09-28 の実測と同じ中身） */
    private function arrange(string $method): string
    {
        switch ($method) {
            case 'executeProperty':
                return "物件名,所有区分,オーナー名,郵便番号,住所,総戸数,階数,構造,築年月,備考\n"
                    . "二重送信A棟,自社所有,,790-0001,愛媛県松山市一番町1-1,20,5,RC造,2010-04,\n"
                    . "二重送信B棟,管理受託,オーナー甲,790-0002,愛媛県松山市一番町1-2,,,,,\n";

            case 'executeRoom':
                $this->property(99);

                return "物件名,部屋番号,階,間取り,面積(㎡),状態,家賃,共益費,敷金,礼金,備考\n"
                    . self::PROPERTY_NAME . ",101,1,1K,25.50,空室,55000,3000,55000,55000,\n"
                    . self::PROPERTY_NAME . ",102,1,1K,25.50,入居中,56000,3000,,,\n";

            case 'executeParking':
                $this->property();

                return "物件名,駐車場番号,月額料金,状態,屋根あり,備考\n"
                    . self::PROPERTY_NAME . ",P-1,8000,空き,有,\n"
                    . self::PROPERTY_NAME . ",P-2,9000,使用中,無,\n";

            case 'executeTenant':
                return "区分,氏名,電話番号,メールアドレス,勤務先,緊急連絡先氏名,緊急連絡先電話,続柄,備考\n"
                    . "入居者,二重太郎,090-0000-0001,taro@example.com,,,,,\n"
                    . "駐車場利用のみ,二重花子,,,,,,,\n";

            case 'executeRoomContract':
                $property = $this->property();
                $this->room($property, '101', 'vacant');
                $this->room($property, '102', 'vacant');
                $this->tenant('二重太郎', 'resident');
                $this->tenant('二重次郎', 'resident');

                // 101 は退去日なし＝契約中、102 は退去日あり＝解約済み
                return "物件名,部屋番号,入居者名,契約日,入居日,退去日,家賃,共益費,敷金,礼金,担当者ユーザー名,メモ\n"
                    . self::PROPERTY_NAME . ",101,二重太郎,2026-04-01,2026-04-15,,55000,3000,55000,55000,,\n"
                    . self::PROPERTY_NAME . ",102,二重次郎,2024-04-01,2024-04-15,2025-03-31,56000,3000,,,,\n";

            case 'executeParkingContract':
                $property = $this->property();
                $room     = $this->room($property, '101', 'occupied');
                $taro     = $this->tenant('二重太郎', 'resident');
                $this->tenant('二重花子', 'parking_only');
                // 紐付部屋番号 101 の契約中の部屋契約（紐付けの経路も通すため）
                MsContract::create(['room_id' => $room->id, 'tenant_id' => $taro->id, 'status' => 'active', 'created_by' => $this->user->id]);
                $this->parking($property, 'P-1', 8000);
                $this->parking($property, 'P-2', 9000);

                // P-1 は終了日なし＝契約中、P-2 は終了日あり＝解約済み
                return "物件名,駐車場番号,入居者名,紐付部屋番号,契約日,開始日,終了日,月額料金,敷金,担当者ユーザー名,メモ\n"
                    . self::PROPERTY_NAME . ",P-1,二重太郎,101,2026-04-01,2026-04-15,,8000,8000,,\n"
                    . self::PROPERTY_NAME . ",P-2,二重花子,,2024-04-01,2024-04-15,2025-03-31,9000,,,\n";
        }

        $this->fail("前提のデータが無いタブ: {$method}");
    }

    private function property(?int $totalUnits = null): MsProperty
    {
        return MsProperty::create([
            'property_code' => 'MS-001', 'property_name' => self::PROPERTY_NAME, 'ownership_type' => 'self_owned',
            'address' => '愛媛県松山市一番町1-1', 'total_units' => $totalUnits, 'created_by' => $this->user->id,
        ]);
    }

    private function room(MsProperty $property, string $number, string $status): MsRoom
    {
        return MsRoom::create(['property_id' => $property->id, 'room_number' => $number, 'status' => $status]);
    }

    private function tenant(string $name, string $type): MsTenant
    {
        return MsTenant::create(['tenant_type' => $type, 'name' => $name]);
    }

    private function parking(MsProperty $property, string $number, int $fee): MsParking
    {
        return MsParking::create([
            'property_id' => $property->id, 'parking_number' => $number, 'monthly_fee' => $fee,
            'status' => 'vacant', 'has_roof' => false,
        ]);
    }
}
```

- [ ] **Step 2: 直す前のコードで落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/MansionImportDoubleSubmitTest.php 2>&1 | grep -E -A1 -e '^[0-9]+\) ' -e '^Tests:' | grep -v -e '^--$'
```

期待: `Tests: 12, Assertions: 113, Errors: 1, Failures: 10.`（`test_every_tab_is_classified` だけ緑）。落ちたテストと理由:
- エラー `test_uploading_the_same_file_again_issues_a_new_token` — `ErrorException: Undefined array key "import_token"`
- `test_sending_the_same_confirmation_twice_imports_once` の物件・部屋・駐車場・入居者 — `断りの文言（error のフラッシュ）が違う`
- 同じテストの部屋契約・駐車場契約 — `2 回目の送信で書き込みが走った`（2 件が 4 件になる）
- `test_a_confirmation_without_a_usable_token_imports_nothing` の 3 本 — `鍵が使えないのに書き込みが走った`
- `test_the_confirmation_form_guards_against_a_second_press` — `確定のフォームの x-data が二度押し止めの部品でない`

- [ ] **Step 3: コントローラ — `use` とクラスの docblock**（`app/Http/Controllers/Admin/MansionImportController.php`。Edit を 2 回）

1 回目 — 置き換える前:

```php
use App\Support\CsvImportTemplate;
use Illuminate\Http\Request;
```

置き換えた後:

```php
use App\Support\CsvImportTemplate;
use App\Support\OneTimeAction;
use Illuminate\Http\Request;
```

2 回目 — 置き換える前:

```php
 */
class MansionImportController extends Controller
```

置き換えた後:

```php
 * ⚠ 確定は確認画面 1 つにつき 1 回だけ（hidden の `import_token`・`OneTimeAction`。
 *   設計書 2026-09-28-import-double-submit-design.md §4.2）。鍵は 6 タブのプレビューで出し、`loadCsv()` の確定の分岐の
 *   最初（CSV を読み直す前）で使う。直す前は、同じ確認画面の確定を 2 回送ると部屋契約・駐車場契約が二重に入った
 *   （物件・部屋・駐車場・入居者は重複の確認で 2 回目が「0件を登録しました」になった）。
 */
class MansionImportController extends Controller
```

- [ ] **Step 4: コントローラ — 断りの表**（Edit。「画面表示」の見出しの前に足す。見出しはファイルに 1 か所）

置き換える前:

```php
    // ================================================================
    // 画面表示
    // ================================================================
```

置き換えた後:

```php
    /**
     * 同じ確認画面から 2 回目を送ったときの案内の、「確かめられます」の前の句（タブのキー => 句。設計書 §4.4）。
     * ⚠ タブを足したら、ここにも足す（無いと、そのタブの 2 回目が 500 になる。
     *   MansionImportDoubleSubmitTest が execute{X} を列挙して、タブごとに 2 回目の断りを確かめる）
     * ⚠ キーは戻り先のタブ（room_contract。URL の room-contract ではない）
     */
    private const WHERE_TO_CHECK = [
        'property'         => '「物件一覧」で',
        'room'             => '「物件一覧」から開く物件の詳細で',
        'parking'          => '「物件一覧」から開く物件の詳細で',
        'tenant'           => '「入居者管理」で',
        'room_contract'    => '「部屋契約一覧」で',
        'parking_contract' => '「駐車場契約一覧」で',
    ];

    // ================================================================
    // 画面表示
    // ================================================================
```

- [ ] **Step 5: コントローラ — 6 タブのプレビューで鍵を出す**（Edit・`replace_all`。先に 6 か所あることを確かめる。⚠ テナントと違い、`'csvData'` の後ろの空白は 5 つ）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && grep -cF "                'csvData'     => base64_encode(\$content)," app/Http/Controllers/Admin/MansionImportController.php
```

期待: `6`

置き換える前（6 か所とも同じ）:

```php
                'csvData'     => base64_encode($content),
```

置き換えた後:

```php
                'csvData'     => base64_encode($content),
                // 確定を 1 回だけ通す鍵（クラスの docblock）
                'importToken' => OneTimeAction::issue(),
```

- [ ] **Step 6: コントローラ — `loadCsv()` の docblock と確定の分岐**（Edit を 2 回）

1 回目 — 置き換える前:

```php
     * HTTP 依存の 3 つだけ: ファイル取得 / 確定時の base64 復元 / 差し戻し。
```

置き換えた後:

```php
     * HTTP 依存の 4 つだけ: ファイル取得 / 確定時の 1 回限りの鍵 / 確定時の base64 復元 / 差し戻し。
```

2 回目 — 置き換える前:

```php
        if ($request->boolean('confirmed')) {
            // 確認画面が持ち回った base64 から復元（既に UTF-8・BOM 除去済み）
```

置き換えた後:

```php
        if ($request->boolean('confirmed')) {
            // 確定は確認画面 1 つにつき 1 回だけ（クラスの docblock）。⚠ CSV を読み直す前に使う
            if (! OneTimeAction::claimFrom($request, 'import_token')) {
                return redirect()->route('admin.mansion-import', ['selected_tab' => $tab])
                    ->with('error', 'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは'
                        . self::WHERE_TO_CHECK[$tab] . '確かめられます。取り込み直すときは、CSVをアップロードし直してください。');
            }

            // 確認画面が持ち回った base64 から復元（既に UTF-8・BOM 除去済み）
```

- [ ] **Step 7: 確認画面**（`resources/views/admin/mansion-import/_preview.blade.php`。Edit を 2 回）

1 回目 — 置き換える前:

```blade
{{-- 必要変数: $tab, $actionUrl, $entityLabel, $totalRows, $validCount, $rowErrors, $skippedRows, $warnings(任意), $summary, $csvData --}}
```

置き換えた後:

```blade
{{-- 必要変数: $tab, $actionUrl, $entityLabel, $totalRows, $validCount, $rowErrors, $skippedRows, $warnings(任意), $summary, $csvData, $importToken --}}
```

2 回目 — 置き換える前:

```blade
        @if($validCount > 0)
            <form method="POST" action="{{ $actionUrl }}">
                @csrf
                <input type="hidden" name="confirmed" value="1">
                <input type="hidden" name="csv_data" value="{{ $csvData }}">

                <button type="submit"
                        style="background: #059669; color: #fff; padding: 10px 28px; border-radius: 6px; font-size: 15px; font-weight: 600; border: none; cursor: pointer;">
                    インポート実行（{{ $validCount }}件）
                </button>
```

置き換えた後:

```blade
        @if($validCount > 0)
            {{-- ⚠ 確定は確認画面 1 つにつき 1 回だけ（hidden の import_token。JS が動かないときも、サーバが 2 回目を断る）。
                 送信中はボタンを押せなくする（二度押し止めの部品。設計書 2026-09-28-import-double-submit-design.md §4.5） --}}
            @include('_partials._submit_once')
            <form method="POST" action="{{ $actionUrl }}"
                  x-data="submitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="onPageShow($event)">
                @csrf
                <input type="hidden" name="confirmed" value="1">
                <input type="hidden" name="csv_data" value="{{ $csvData }}">
                <input type="hidden" name="import_token" value="{{ $importToken }}">

                <button type="submit" :disabled="submitting"
                        class="cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"
                        style="background: #059669; color: #fff; padding: 10px 28px; border-radius: 6px; font-size: 15px; font-weight: 600; border: none;">
                    インポート実行（{{ $validCount }}件）
                </button>
                <span role="status" x-text="submitting ? '取り込んでいます…' : ''" style="display: inline-block; margin-left: 12px; font-size: 13px; color: #374151;"></span>
```

⚠ このフォームは `mansionImportTabs()` の `x-data` の中にある（入れ子）。

- [ ] **Step 8: 通ることと、関係するテストが緑のままであることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/MansionImportDoubleSubmitTest.php 2>&1 | tail -1 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/MansionImportTest.php tests/Feature/Admin/MansionImportRejectionTest.php 2>&1 | tail -1
```

期待: `OK (12 tests, 159 assertions)` ／ `OK (24 tests, 560 assertions)`

- [ ] **Step 9: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && git add tests/Feature/Admin/MansionImportDoubleSubmitTest.php app/Http/Controllers/Admin/MansionImportController.php resources/views/admin/mansion-import/_preview.blade.php && git commit -m "$(cat <<'EOF'
fix: 賃貸マンションのCSV取込の確定を確認画面 1 つにつき 1 回だけにする

同じ確認画面から 2 回送ると部屋契約・駐車場契約が二重に入っていた。6 タブのプレビューで 1 回限りの鍵を出し、
loadCsv() の確定の分岐の最初で使う。使えなければ同じタブへ戻し、タブごとに確かめる画面を案内する。
確認画面は二度押し止めの部品を使う。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 4: ZEAL 会員の取込

**Files:**
- Modify: `tests/Feature/Admin/ZealMemberImportControllerTest.php`（`use`・docblock・手で組んだ確定 4 か所に鍵・新しいテスト 6 本）
- Modify: `app/Http/Controllers/Admin/ZealMemberImportController.php`（`use`・クラスの docblock・`preview()` で鍵・`execute()` の最初で使う）
- Modify: `resources/views/admin/zeal-member-import/preview.blade.php`（確定のフォーム）

**Interfaces:**
- Consumes: Task 1 の部品・Task 2 の `ChecksDoubleSubmit`・既存の `seedMasters()`・`fixtureRows()`・`csvContent()`・`uploadFrom()`・`executive()`（このテストファイルの private メソッド）・`OneTimeAction::issue()`
- Produces: 無し

⚠ 確定を手で組んで送る既存の 4 か所（117・193・232・296 行）は、鍵が無いと断られる。戻り先が同じ取込の画面なので、件数 0 しか見ていないテストは断られても緑のまま狙った経路を通らなくなる（設計書 §5.3）→ 1 送信ごとに新しい鍵（`OneTimeAction::issue()`。発行を記録しないので、新しい値なら 1 回は通る）を足す。

- [ ] **Step 1: テストの `use`・docblock・トレイト・定数**（Edit を 3 回）

1 回目 — 置き換える前:

```php
use App\Models\ZealStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesZealSchema;
```

置き換えた後:

```php
use App\Models\ZealStore;
use App\Support\OneTimeAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ChecksDoubleSubmit;
use Tests\Concerns\CreatesZealSchema;
```

2 回目 — 置き換える前:

```php
 * zeal_* テーブルは migration 管理外のため CreatesZealSchema trait で構築する。
 */
class ZealMemberImportControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesZealSchema;
    use ParsesForms;
```

置き換えた後:

```php
 * zeal_* テーブルは migration 管理外のため CreatesZealSchema trait で構築する。
 *
 * ⚠ 確定は確認画面 1 つにつき 1 回だけ（hidden の import_token。設計書 2026-09-28-import-double-submit-design.md）。
 *   確定を手で組んで送るテストは、1 送信ごとに新しい鍵（OneTimeAction::issue()）を足す（発行を記録しないので、
 *   新しい値なら 1 回は通る）。
 */
class ZealMemberImportControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesZealSchema;
    use ParsesForms;
    use ChecksDoubleSubmit;
```

3 回目 — 置き換える前:

```php
    /** password.change を通過する経営層ユーザー */
    private function executive(): \App\Models\User
```

置き換えた後:

```php
    /** 同じ確認画面から 2 回目を送ったとき（1 回限りの鍵が使えないとき）の案内（設計書 §4.4） */
    private const USED_TOKEN = 'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「会員管理」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。';

    /** password.change を通過する経営層ユーザー */
    private function executive(): \App\Models\User
```

- [ ] **Step 2: 手で組んだ確定 4 か所に鍵を足す**（Edit を 3 回。2 か所が同じ 1 行なので、その 1 回は `replace_all`）

1 回目（117 行）— 置き換える前:

```php
                'confirmed' => '1',
                'csv_data'  => base64_encode($content),
            ]);
```

置き換えた後:

```php
                'confirmed'    => '1',
                'csv_data'     => base64_encode($content),
                'import_token' => OneTimeAction::issue(),
            ]);
```

2 回目（193・232 行。`replace_all`。先に `grep -cF "'confirmed' => '1', 'csv_data' => base64_encode(\$content)," tests/Feature/Admin/ZealMemberImportControllerTest.php` が `2` であることを確かめる）— 置き換える前:

```php
                'confirmed' => '1', 'csv_data' => base64_encode($content),
```

置き換えた後:

```php
                'confirmed' => '1', 'csv_data' => base64_encode($content), 'import_token' => OneTimeAction::issue(),
```

3 回目（296 行）— 置き換える前:

```php
                'confirmed' => '1',
                'csv_data'  => $csv === '' ? '' : base64_encode($csv),
            ]);
```

置き換えた後:

```php
                'confirmed'    => '1',
                'csv_data'     => $csv === '' ? '' : base64_encode($csv),
                'import_token' => OneTimeAction::issue(),
            ]);
```

- [ ] **Step 3: 新しいテスト 6 本**（Edit。`test_non_executive_is_forbidden()` の前に足す）

置き換える前:

```php
    public function test_non_executive_is_forbidden(): void
```

置き換えた後:

```php
    // ================================================================
    // 確定は確認画面 1 つにつき 1 回だけ（設計書 2026-09-28-import-double-submit-design.md）
    // ================================================================

    /** プレビューを描かせて、画面が描いた確定のフォームを分解する */
    private function previewForm(\App\Models\User $user, string $content): array
    {
        $preview = $this->actingAs($user)->post(route('admin.zeal.member-import.preview'), [
            'csv_file' => $this->uploadFrom($content),
        ])->assertOk();

        return $this->parseForm($preview->getContent(), 'action="' . route('admin.zeal.member-import.execute') . '"');
    }

    /** 確認画面から確定を送る（ブラウザと同じく、リファラーは確認画面の URL） */
    private function sendConfirmation(\App\Models\User $user, array $form): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->from(route('admin.zeal.member-import.preview'))->post($form['action'], $form['fields']);
    }

    /** @return array<string, array{0: string|list<string>|null}> [import_token に入れる値（null なら送らない）] */
    public static function unusableTokens(): array
    {
        return [
            '鍵が無い' => [null],
            '鍵が空'   => [''],
            '鍵が配列' => [['a', 'b']],
        ];
    }

    public function test_sending_the_same_confirmation_twice_imports_once(): void
    {
        $this->seedMasters();
        $user = $this->executive();
        $form = $this->previewForm($user, $this->csvContent($this->fixtureRows()));

        $this->sendConfirmation($user, $form);
        $this->assertDatabaseCount('zeal_members', 5);

        // 直す前: 2 回目は氏名＋入会日の重複の確認で全員を飛ばし、「登録 0件 / スキップ 5件…」の成功の帯が出た
        [$second, $writes] = $this->countingWrites(fn () => $this->sendConfirmation($user, $form));

        $this->assertSame(0, $writes, '2 回目の送信で書き込みが走った');
        $this->assertDatabaseCount('zeal_members', 5);
        $this->assertDatabaseCount('zeal_member_contracts', 4);
        $this->assertRefused($second, route('admin.zeal.member-import'), self::USED_TOKEN, $user);
    }

    #[DataProvider('unusableTokens')]
    public function test_a_confirmation_without_a_usable_token_imports_nothing(string|array|null $token): void
    {
        $this->seedMasters();
        $user = $this->executive();
        $form = $this->previewForm($user, $this->csvContent($this->fixtureRows()));

        if ($token === null) {
            unset($form['fields']['import_token']);
        } else {
            $form['fields']['import_token'] = $token;
        }

        // 500 にならない（配列の鍵は OneTimeAction::claimFrom() が is_string で断る）
        [$response, $writes] = $this->countingWrites(fn () => $this->sendConfirmation($user, $form));

        $this->assertSame(0, $writes, '鍵が使えないのに書き込みが走った');
        $this->assertDatabaseCount('zeal_members', 0);
        $this->assertRefused($response, route('admin.zeal.member-import'), self::USED_TOKEN, $user);
    }

    public function test_uploading_the_same_file_again_issues_a_new_token(): void
    {
        $this->seedMasters();
        $user    = $this->executive();
        $content = $this->csvContent($this->fixtureRows());
        $first   = $this->previewForm($user, $content);
        $second  = $this->previewForm($user, $content);

        // ⚠ 同じファイルで確かめる（別のファイルだと、鍵をファイルの中身から作る書き換えを見逃す）
        $this->assertNotSame($first['fields']['import_token'], $second['fields']['import_token'], 'プレビューごとに鍵が変わっていない');

        $this->sendConfirmation($user, $second);
        $this->assertDatabaseCount('zeal_members', 5);
    }

    public function test_the_confirmation_form_guards_against_a_second_press(): void
    {
        $this->seedMasters();
        $html = $this->actingAs($this->executive())->post(route('admin.zeal.member-import.preview'), [
            'csv_file' => $this->uploadFrom($this->csvContent($this->fixtureRows())),
        ])->assertOk()->getContent();

        $this->assertSubmitOnceForm($html, route('admin.zeal.member-import.execute'));
    }

    public function test_non_executive_is_forbidden(): void
```

- [ ] **Step 4: 直す前のコードで落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/ZealMemberImportControllerTest.php 2>&1 | grep -E -A1 -e '^[0-9]+\) ' -e '^Tests:' | grep -v -e '^--$'
```

期待: `Tests: 20, Assertions: 125, Errors: 1, Failures: 5.`（既存の 14 本は緑＝手で足した鍵は、直す前のコードでは読まれないだけ）。落ちたテストと理由:
- エラー `test_uploading_the_same_file_again_issues_a_new_token` — `ErrorException: Undefined array key "import_token"`
- `test_sending_the_same_confirmation_twice_imports_once` — `断りの文言（error のフラッシュ）が違う`（2 回目は「登録 0件 / スキップ 5件…」の成功の帯）
- `test_a_confirmation_without_a_usable_token_imports_nothing` の 3 本 — `鍵が使えないのに書き込みが走った`
- `test_the_confirmation_form_guards_against_a_second_press` — `確定のフォームの x-data が二度押し止めの部品でない`

- [ ] **Step 5: コントローラ**（`app/Http/Controllers/Admin/ZealMemberImportController.php`。Edit を 4 回）

1 回目 — 置き換える前:

```php
use App\Models\ZealStore;
use App\Support\Settings;
```

置き換えた後:

```php
use App\Models\ZealStore;
use App\Support\OneTimeAction;
use App\Support\Settings;
```

2 回目 — 置き換える前:

```php
 *   GET で 405 になる（docs/RULES.md Bug #64）。
 */
class ZealMemberImportController extends Controller
```

置き換えた後:

```php
 *   GET で 405 になる（docs/RULES.md Bug #64）。
 * ⚠ 確定は確認画面 1 つにつき 1 回だけ（hidden の `import_token`・`OneTimeAction`。
 *   設計書 2026-09-28-import-double-submit-design.md §4.2）。鍵は preview() で出し、execute() の最初
 *   （CSV を読み直す前）で使う。直す前は、同じ確認画面の確定を 2 回送ると、2 回目が氏名＋入会日の重複の確認で
 *   全員を飛ばし、「登録 0件 / スキップ 5件…」の成功の帯が出て取り込み直したように見えた。
 */
class ZealMemberImportController extends Controller
```

3 回目 — 置き換える前:

```php
        return view('admin.zeal-member-import.preview', compact(
            'toImport', 'skipped', 'errored', 'excluded', 'content'
        ));
```

置き換えた後:

```php
        return view('admin.zeal-member-import.preview', compact(
            'toImport', 'skipped', 'errored', 'excluded', 'content'
        ) + [
            // 確定を 1 回だけ通す鍵（クラスの docblock）
            'importToken' => OneTimeAction::issue(),
        ]);
```

4 回目 — 置き換える前:

```php
    public function execute(Request $request)
    {
        $result = $this->loadCsv($request);
```

置き換えた後:

```php
    public function execute(Request $request)
    {
        // 確定は確認画面 1 つにつき 1 回だけ（クラスの docblock）。⚠ CSV を読み直す前に使う
        if (! OneTimeAction::claimFrom($request, 'import_token')) {
            return redirect()->route('admin.zeal.member-import')
                ->with('error', 'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「会員管理」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。');
        }

        $result = $this->loadCsv($request);
```

- [ ] **Step 6: 確認画面**（`resources/views/admin/zeal-member-import/preview.blade.php`。Edit）

置き換える前:

```blade
@if(count($toImport) > 0)
    <form method="POST" action="{{ route('admin.zeal.member-import.execute') }}">
        @csrf
        <input type="hidden" name="confirmed" value="1">
        <input type="hidden" name="csv_data" value="{{ base64_encode($content) }}">

        <div style="display: flex; gap: 12px; align-items: center;">
            <a href="{{ route('admin.zeal.member-import') }}"
               style="display: inline-flex; align-items: center; padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 14px; font-weight: 600; color: #374151; text-decoration: none;">
                キャンセル
            </a>
            <button type="submit"
                    style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 28px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer;">
                <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                {{ count($toImport) }}件をインポート実行する
            </button>
        </div>
```

置き換えた後:

```blade
@if(count($toImport) > 0)
    {{-- ⚠ 確定は確認画面 1 つにつき 1 回だけ（hidden の import_token。JS が動かないときも、サーバが 2 回目を断る）。
         送信中はボタンを押せなくする（二度押し止めの部品。設計書 2026-09-28-import-double-submit-design.md §4.5）。
         ⚠ ボタンの並びは折り返してよい（flex-wrap）。折り返さないと、狭い画面で送信中の文字が縮んで文字の途中で割れる --}}
    @include('_partials._submit_once')
    <form method="POST" action="{{ route('admin.zeal.member-import.execute') }}"
          x-data="submitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="onPageShow($event)">
        @csrf
        <input type="hidden" name="confirmed" value="1">
        <input type="hidden" name="csv_data" value="{{ base64_encode($content) }}">
        <input type="hidden" name="import_token" value="{{ $importToken }}">

        <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
            <a href="{{ route('admin.zeal.member-import') }}"
               style="display: inline-flex; align-items: center; padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 14px; font-weight: 600; color: #374151; text-decoration: none;">
                キャンセル
            </a>
            <button type="submit" :disabled="submitting"
                    class="cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"
                    style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 28px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 700;">
                <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                {{ count($toImport) }}件をインポート実行する
            </button>
            <span role="status" x-text="submitting ? '取り込んでいます…' : ''" style="display: inline-block; font-size: 13px; color: #374151;"></span>
        </div>
```

⚠ `flex-wrap` は PHP のテストから見えない（変異 UZ04 は緑）。Task 13 の 6・7 の 375px で見る。

- [ ] **Step 7: 通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Admin/ZealMemberImportControllerTest.php 2>&1 | tail -1 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/ValidationErrorFeedbackTest.php 2>&1 | tail -1
```

期待: `OK (20 tests, 158 assertions)` ／ `OK (4 tests, 6 assertions)`

- [ ] **Step 8: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && git add tests/Feature/Admin/ZealMemberImportControllerTest.php app/Http/Controllers/Admin/ZealMemberImportController.php resources/views/admin/zeal-member-import/preview.blade.php && git commit -m "$(cat <<'EOF'
fix: ZEAL会員のCSV取込の確定を確認画面 1 つにつき 1 回だけにする

同じ確認画面から 2 回送ると、2 回目が重複の確認で全員を飛ばして成功の帯を出し、取り込み直したように
見えていた。preview() で 1 回限りの鍵を出し、execute() の最初で使う。手で組んだ確定のテストには鍵を足す。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 5: 工程表（建売）の取込

**Files:**
- Modify: `tests/Feature/Housing/ScheduleImportTest.php`（`use`・トレイト・定数・新しいテスト 6 本・送る部品）
- Modify: `tests/Feature/Housing/HousingConstructionStartDateTest.php`（手で組んだ確定 2 か所に鍵・docblock）
- Modify: `app/Http/Controllers/Housing/ScheduleImportController.php`（`use`・クラスの docblock・`preview()` で鍵・`execute()` の最初で使う）
- Modify: `resources/views/housing/properties/schedule-import.blade.php`（⑥ 確定のフォーム）

**Interfaces:**
- Consumes: Task 1 の部品・Task 2 の `ChecksDoubleSubmit`（`htmlAttr()` が protected なので `ScheduleTestCase` の子から使える）・既存の `confirmForm($html, $property)`・`previewFixture($property)`・`makeParent('property')`・`manager()`（`ScheduleTestCase`）
- Produces: 無し

⚠ いまの差し戻し（`withErrors`）は画面の中の枠に出るが、鍵の断りはほかの取込とそろえてレイアウトの赤帯に出す（設計書 §4.4）。
⚠ 直す前は、2 回目も取込由来の 65 件を消して入れ直す（工程の id が作り直される）ので件数は変わらない。書き込みの数と id で見る。

- [ ] **Step 1: `ScheduleImportTest` の `use`・トレイト・定数**（Edit を 2 回）

1 回目 — 置き換える前:

```php
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Feature\Schedule\ScheduleTestCase;
```

置き換えた後:

```php
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ChecksDoubleSubmit;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Feature\Schedule\ScheduleTestCase;
```

2 回目 — 置き換える前:

```php
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    private const FIXTURE = __DIR__ . '/../../fixtures/schedule-import/list-format.xlsx';
```

置き換えた後:

```php
    use RefreshDatabase;
    use CreatesRealEstateSchema;
    use ChecksDoubleSubmit;

    private const FIXTURE = __DIR__ . '/../../fixtures/schedule-import/list-format.xlsx';

    /** 同じ確認画面から 2 回目を送ったとき（1 回限りの鍵が使えないとき）の案内（設計書 2026-09-28-import-double-submit-design.md §4.4） */
    private const USED_TOKEN = 'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは、この物件の詳細の「工程表」で確かめられます。取り込み直すときは、ファイルを選び直してください。';
```

- [ ] **Step 2: 新しいテスト 6 本と送る部品**（Edit。「ヘルパ」の見出しの前に足す）

置き換える前:

```php
    // ============================================================
    // ヘルパ
    // ============================================================

    private function previewFixture(HsProperty $property)
```

置き換えた後:

```php
    // ============================================================
    // 確定は確認画面 1 つにつき 1 回だけ（設計書 2026-09-28-import-double-submit-design.md）
    // ============================================================

    /** @return array<string, array{0: string|list<string>|null}> [import_token に入れる値（null なら送らない）] */
    public static function unusableTokens(): array
    {
        return [
            '鍵が無い' => [null],
            '鍵が空'   => [''],
            '鍵が配列' => [['a', 'b']],
        ];
    }

    public function test_sending_the_same_confirmation_twice_imports_once(): void
    {
        $property = $this->makeParent('property');
        $user     = $this->manager();
        $form     = $this->confirmForm($this->previewFixture($property)->getContent(), $property);

        $this->sendConfirmation($user, $property, $form);
        $this->assertSame(65, $property->scheduleSteps()->count(), '1 回目で 65 件が入っていない（測定が無効）');
        $ids = $property->scheduleSteps()->orderBy('id')->pluck('id')->all();

        // 直す前: 2 回目は取込由来の 65 件を消して入れ直し（工程の id が作り直される）、
        // 「既存の 65 件を入れ替えて 65 件を登録」の成功の帯が出た。件数は変わらないので、書き込みの数と id で見る
        [$second, $writes] = $this->countingWrites(fn () => $this->sendConfirmation($user, $property, $form));

        $this->assertSame(0, $writes, '2 回目の送信で書き込みが走った（工程の入れ替え）');
        $this->assertSame($ids, $property->scheduleSteps()->orderBy('id')->pluck('id')->all(), '2 回目の送信で工程が作り直された');
        $this->assertRefused($second, route('housing.properties.schedule-import.form', $property), self::USED_TOKEN, $user);
    }

    #[DataProvider('unusableTokens')]
    public function test_a_confirmation_without_a_usable_token_imports_nothing(string|array|null $token): void
    {
        $property = $this->makeParent('property');
        $user     = $this->manager();
        $form     = $this->confirmForm($this->previewFixture($property)->getContent(), $property);

        if ($token === null) {
            unset($form['fields']['import_token']);
        } else {
            $form['fields']['import_token'] = $token;
        }

        // 500 にならない（配列の鍵は OneTimeAction::claimFrom() が is_string で断る）
        [$response, $writes] = $this->countingWrites(fn () => $this->sendConfirmation($user, $property, $form));

        $this->assertSame(0, $writes, '鍵が使えないのに書き込みが走った');
        $this->assertSame(0, ScheduleStep::count());
        $this->assertRefused($response, route('housing.properties.schedule-import.form', $property), self::USED_TOKEN, $user);
    }

    public function test_choosing_the_same_file_again_issues_a_new_token(): void
    {
        $property = $this->makeParent('property');
        $first    = $this->confirmForm($this->previewFixture($property)->getContent(), $property);
        $second   = $this->confirmForm($this->previewFixture($property)->getContent(), $property);

        // ⚠ 同じファイルで確かめる（別のファイルだと、鍵をファイルの中身から作る書き換えを見逃す）
        $this->assertNotSame($first['fields']['import_token'], $second['fields']['import_token'], 'プレビューごとに鍵が変わっていない');

        $this->sendConfirmation($this->manager(), $property, $second);
        $this->assertSame(65, $property->scheduleSteps()->count(), '選び直したプレビューの鍵で取り込めない');
    }

    public function test_the_confirmation_form_guards_against_a_second_press(): void
    {
        $property = $this->makeParent('property');

        $this->assertSubmitOnceForm(
            $this->previewFixture($property)->getContent(),
            route('housing.properties.schedule-import.execute', $property)
        );
    }

    // ============================================================
    // ヘルパ
    // ============================================================

    /** 確認画面から確定を送る（ブラウザと同じく、リファラーは確認画面の URL） */
    private function sendConfirmation($user, HsProperty $property, array $form): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)
            ->from(route('housing.properties.schedule-import.preview', $property))
            ->post($form['action'], $form['fields']);
    }

    private function previewFixture(HsProperty $property)
```

- [ ] **Step 3: `HousingConstructionStartDateTest` の手で組んだ確定 2 か所に鍵を足す**（Edit を 3 回）

1 回目 — 置き換える前:

```php
use App\Models\HsProperty;
use Illuminate\Foundation\Testing\RefreshDatabase;
```

置き換えた後:

```php
use App\Models\HsProperty;
use App\Support\OneTimeAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
```

2 回目（`importRows()`。docblock も実物に合わせる＝設計書との違い 7）— 置き換える前:

```php
    /** 取込のプレビュー → 確定を、画面が描いたフォームどおりに往復する */
    private function importRows(HsProperty $property, array $rows): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->manager())->post(
            route('housing.properties.schedule-import.execute', $property),
            ['rows_json' => json_encode($rows, JSON_UNESCAPED_UNICODE)]
        );
    }
```

置き換えた後:

```php
    /**
     * 取込の確定を直接送る（プレビューを通さない）。
     * ⚠ 確定は確認画面 1 つにつき 1 回だけなので、送るたびに新しい鍵を足す（OneTimeAction::issue() は発行を記録しないので、
     *   新しい値なら 1 回は通る。設計書 2026-09-28-import-double-submit-design.md §5.3）
     */
    private function importRows(HsProperty $property, array $rows): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->manager())->post(
            route('housing.properties.schedule-import.execute', $property),
            ['rows_json' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'import_token' => OneTimeAction::issue()]
        );
    }
```

3 回目（246 行）— 置き換える前:

```php
            ['rows_json' => json_encode($this->importableRows(), JSON_UNESCAPED_UNICODE)]
```

置き換えた後:

```php
            ['rows_json' => json_encode($this->importableRows(), JSON_UNESCAPED_UNICODE), 'import_token' => OneTimeAction::issue()]
```

- [ ] **Step 4: 直す前のコードで落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Housing/ScheduleImportTest.php 2>&1 | grep -E -A1 -e '^[0-9]+\) ' -e '^Tests:' | grep -v -e '^--$' && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Housing/HousingConstructionStartDateTest.php 2>&1 | tail -1
```

期待: `Tests: 29, Assertions: 177, Errors: 1, Failures: 5.`（既存の 23 本は緑）。落ちたテストと理由:
- エラー `test_choosing_the_same_file_again_issues_a_new_token` — `ErrorException: Undefined array key "import_token"`
- `test_sending_the_same_confirmation_twice_imports_once` — `2 回目の送信で書き込みが走った（工程の入れ替え）`
- `test_a_confirmation_without_a_usable_token_imports_nothing` の 3 本 — `鍵が使えないのに書き込みが走った`
- `test_the_confirmation_form_guards_against_a_second_press` — `確定のフォームの x-data が二度押し止めの部品でない`

`HousingConstructionStartDateTest` は `OK (24 tests, 92 assertions)`（足した鍵は、直す前のコードでは読まれないだけ）。

⚠ ここで `Call to private method … htmlAttr() from scope Tests\Feature\Housing\ScheduleImportTest` が出たら、Task 2 の Step 1（protected）が抜けている。

- [ ] **Step 5: コントローラ**（`app/Http/Controllers/Housing/ScheduleImportController.php`。Edit を 4 回）

1 回目 — 置き換える前:

```php
use App\Models\ScheduleStep;
use App\Support\ScheduleImportSheet;
```

置き換えた後:

```php
use App\Models\ScheduleStep;
use App\Support\OneTimeAction;
use App\Support\ScheduleImportSheet;
```

2 回目 — 置き換える前:

```php
 *   （ガント形式の選び間違いは普通の操作で踏む。docs/RULES.md Bug #64）。
 */
class ScheduleImportController extends Controller
```

置き換えた後:

```php
 *   （ガント形式の選び間違いは普通の操作で踏む。docs/RULES.md Bug #64）。
 *
 * ⚠ 確定は確認画面 1 つにつき 1 回だけ（hidden の `import_token`・`OneTimeAction`。
 *   設計書 2026-09-28-import-double-submit-design.md §4.2）。鍵は preview() で出し、execute() の最初（入力チェックの前）
 *   で使う。直す前は、同じ確認画面の確定を 2 回送ると、取込由来の工程を消して入れ直し（工程の id が作り直される）、
 *   「既存の N 件を入れ替えて…」の成功の帯が出た。取り込んだ工程を画面で直したあとに古い確認画面から送ると、
 *   その修正が消えうる。断りは、ほかの取込とそろえてレイアウトの赤帯に出す（いまの差し戻しは画面の中の枠）。
 */
class ScheduleImportController extends Controller
```

3 回目 — 置き換える前:

```php
            'dateChanges' => $this->dateChanges($property, $result['rows']),
        ]);
```

置き換えた後:

```php
            'dateChanges' => $this->dateChanges($property, $result['rows']),
            // 確定を 1 回だけ通す鍵（クラスの docblock）
            'importToken' => OneTimeAction::issue(),
        ]);
```

4 回目 — 置き換える前:

```php
    public function execute(Request $request, HsProperty $property)
    {
        // 確定のフォームは確認画面に載っているので、断るときは取込の画面へ戻す（クラスの docblock。Bug #64）
```

置き換えた後:

```php
    public function execute(Request $request, HsProperty $property)
    {
        // 確定は確認画面 1 つにつき 1 回だけ（クラスの docblock）。⚠ 入力チェックより先に使う
        if (! OneTimeAction::claimFrom($request, 'import_token')) {
            return redirect()->route('housing.properties.schedule-import.form', $property)
                ->with('error', 'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは、この物件の詳細の「工程表」で確かめられます。取り込み直すときは、ファイルを選び直してください。');
        }

        // 確定のフォームは確認画面に載っているので、断るときは取込の画面へ戻す（クラスの docblock。Bug #64）
```

- [ ] **Step 6: 確認画面の ⑥ 確定**（`resources/views/housing/properties/schedule-import.blade.php`。Edit）

置き換える前:

```blade
        {{-- ⑥ 確定 --}}
        @if(count($result['rows']) > 0)
            <form method="POST" action="{{ route('housing.properties.schedule-import.execute', $property) }}">
                @csrf
                <input type="hidden" name="rows_json" value="{{ json_encode($result['rows'], JSON_UNESCAPED_UNICODE) }}">
                <button type="submit"
                        class="h-10 px-5 rounded-md bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 transition-colors">
                    この内容で取り込む
                </button>
            </form>
```

置き換えた後:

```blade
        {{-- ⑥ 確定
             ⚠ 確認画面 1 つにつき 1 回だけ（hidden の import_token。JS が動かないときも、サーバが 2 回目を断る）。
             送信中はボタンを押せなくする（二度押し止めの部品。設計書 2026-09-28-import-double-submit-design.md §4.5） --}}
        @if(count($result['rows']) > 0)
            @include('_partials._submit_once')
            <form method="POST" action="{{ route('housing.properties.schedule-import.execute', $property) }}"
                  x-data="submitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="onPageShow($event)">
                @csrf
                <input type="hidden" name="rows_json" value="{{ json_encode($result['rows'], JSON_UNESCAPED_UNICODE) }}">
                <input type="hidden" name="import_token" value="{{ $importToken }}">
                <button type="submit" :disabled="submitting"
                        class="h-10 px-5 rounded-md bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 transition-colors cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">
                    この内容で取り込む
                </button>
                <span role="status" x-text="submitting ? '取り込んでいます…' : ''" style="display: inline-block; margin-left: 12px; font-size: 13px; color: #374151;"></span>
            </form>
```

⚠ `@include` はフォームと同じ `@if` の中（取り込める工程が 0 件の画面には関数も出さない）。

- [ ] **Step 7: 通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Housing/ScheduleImportTest.php 2>&1 | tail -1 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Housing/HousingConstructionStartDateTest.php 2>&1 | tail -1
```

期待: `OK (29 tests, 214 assertions)` ／ `OK (24 tests, 92 assertions)`

- [ ] **Step 8: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && git add tests/Feature/Housing/ScheduleImportTest.php tests/Feature/Housing/HousingConstructionStartDateTest.php app/Http/Controllers/Housing/ScheduleImportController.php resources/views/housing/properties/schedule-import.blade.php && git commit -m "$(cat <<'EOF'
fix: 工程表の取込の確定を確認画面 1 つにつき 1 回だけにする

同じ確認画面から 2 回送ると、取込由来の工程を消して入れ直し（工程の id が作り直される）、成功の帯を出していた。
preview() で 1 回限りの鍵を出し、execute() の最初で使う。断りはレイアウトの赤帯に出す。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 6: 周辺ビルの取込（ビル＋調査・テナント明細）

**Files:**
- Modify: `tests/Feature/Tenant/AreaBuildingImportTest.php`（`use`・docblock・トレイト・`importBuildings()` / `importTenants()` を画面の鍵で送る形に・手で組んだ確定 10 か所に鍵・既存の 1 行・新しいテスト 7 本）
- Modify: `app/Http/Controllers/Tenant/AreaBuildingImportController.php`（`use`・クラスの docblock・`form()` で鍵・`execute()` の最初で使う）
- Modify: `resources/views/tenant/area-buildings/import.blade.php`（確定のフォーム）

**Interfaces:**
- Consumes: Task 1 の部品（`submitOnce({ reloadOnReturn: true })`）・Task 2 の `ChecksDoubleSubmit`（`htmlAttr()` が protected なので `AreaBuildingTestCase` の子から使える）・既存の `manager()`・`staff()`・`makeBuilding($name)`（`AreaBuildingTestCase`）・`ParsesForms::parseForm()`
- Produces: 無し

⚠ 確認画面が無い。確かめるのは画面の中の SheetJS で、取込の画面（GET の `form()`）に確定のフォームがある。鍵は `form()` を開いたときに出し、断ったら取込の画面へ戻す（開き直すので新しい鍵が出る。設計書 §4.2）。
⚠ `importBuildings()`（17 回呼ばれる）と `importTenants()`（9 回）は、取込の画面を GET して画面が描いた鍵を使う形に直す（画面が鍵を描くことも一緒に固まる。設計書 §5.3）。直接送る 10 か所（137・288・443・465・501・540・722・739・753・761 行）は 1 送信ごとに新しい鍵を足す。権限の検査の 1 か所（107 行）は門番で止まるので変えない。

- [ ] **Step 1: テストの `use`・docblock・トレイト**（Edit を 2 回）

1 回目 — 置き換える前:

```php
use App\Support\FloorNumber;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
```

置き換えた後:

```php
use App\Support\FloorNumber;
use App\Support\OneTimeAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ChecksDoubleSubmit;
```

2 回目 — 置き換える前:

```php
 *   を構造で固定し、実挙動はブラウザで確かめる（プラン Step 10）。
 */
class AreaBuildingImportTest extends AreaBuildingTestCase
{
    use RefreshDatabase;
```

置き換えた後:

```php
 *   を構造で固定し、実挙動はブラウザで確かめる（プラン Step 10）。
 *
 * ⚠ 取込は取込の画面 1 つにつき 1 回だけ（hidden の import_token。設計書 2026-09-28-import-double-submit-design.md）。
 *   importBuildings() / importTenants() は取込の画面を開いて画面が描いた鍵を使う。確定を手で組んで送るテストは、
 *   1 送信ごとに新しい鍵（OneTimeAction::issue()）を足す（発行を記録しないので、新しい値なら 1 回は通る）。
 */
class AreaBuildingImportTest extends AreaBuildingTestCase
{
    use RefreshDatabase;
    use ChecksDoubleSubmit;
```

- [ ] **Step 2: `importBuildings()` / `importTenants()` を画面の鍵で送る形に**（Edit）

置き換える前:

```php
    private const IMPORT_URL = '/tenant/area-buildings/import';

    private function importBuildings(array $rows, string $month = '2026-08')
    {
        return $this->actingAs($this->manager())->post(self::IMPORT_URL, [
            'kind'           => 'buildings',
            'surveyed_month' => $month,
            'rows'           => json_encode($rows),
        ]);
    }

    private function importTenants(array $rows)
    {
        return $this->actingAs($this->manager())->post(self::IMPORT_URL, [
            'kind' => 'tenants',
            'rows' => json_encode($rows),
        ]);
    }
```

置き換えた後:

```php
    private const IMPORT_URL = '/tenant/area-buildings/import';

    /** 同じ取込の画面から 2 回目を送ったとき（1 回限りの鍵が使えないとき）の案内（設計書 2026-09-28-import-double-submit-design.md §4.4） */
    private const USED_TOKEN = 'この取込画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「周辺ビル調査」で確かめられます。取り込み直すときは、ファイルを選び直してください。';

    /** 取込の画面を開き、画面が描いたフォームを分解する */
    private function importForm($user): array
    {
        $html = $this->actingAs($user)->get(self::IMPORT_URL)->assertOk()->getContent();

        return $this->parseForm($html, 'action="' . route('tenant.area-buildings.import.execute') . '"');
    }

    /**
     * 取込の画面を開き、画面が描いたフォームに Alpine が入れる 3 つ（kind・surveyed_month・rows）だけを埋めて送る。
     * ⚠ 鍵（import_token）は画面が描いたものを使う。手で組んで送ると鍵が無くて断られ、断られても戻り先が
     *   取込の画面なので、戻り先だけを見るテストは緑のまま狙った経路を通らない（設計書 §5.3）
     */
    private function sendImport(array $fields)
    {
        $manager = $this->manager();
        $form    = $this->importForm($manager);

        return $this->actingAs($manager)->post($form['action'], array_merge($form['fields'], $fields));
    }

    private function importBuildings(array $rows, string $month = '2026-08')
    {
        return $this->sendImport(['kind' => 'buildings', 'surveyed_month' => $month, 'rows' => json_encode($rows)]);
    }

    /** テナント明細のとき、画面の surveyed_month の hidden は ''（`kind === 'buildings' ? surveyedMonth : ''`） */
    private function importTenants(array $rows)
    {
        return $this->sendImport(['kind' => 'tenants', 'surveyed_month' => '', 'rows' => json_encode($rows)]);
    }
```

- [ ] **Step 3: 直接送る 10 か所に鍵を足す**（Edit を 9 回。443・465 行は同じ 2 行なので、その 1 回は `replace_all`。先に下の Python で「置き換える前」の数を確かめる）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && python3 - tests/Feature/Tenant/AreaBuildingImportTest.php <<'PY'
import sys
s = open(sys.argv[1], encoding='utf-8').read()
for want, a in [
    (1, "                'rows'           => json_encode([['building_name' => 'X', 'name' => 'Y']]),\n"),
    (1, "                'rows' => json_encode([['name' => 'アルファビル', 'operating' => '1']]),\n            ]);\n"),
    (2, "                'kind' => 'buildings',\n                'rows' => json_encode([['name' => 'X', 'operating' => '1']]),\n"),
    (1, "            'kind' => 'buildings', 'surveyed_month' => '2026-08', 'rows' => json_encode($rows),\n"),
    (1, "            'kind' => 'tenants', 'rows' => json_encode($rows),\n"),
    (1, "                'kind' => 'buildings', 'surveyed_month' => '2026-08', 'rows' => 'これはJSONではない',\n"),
    (1, "                'rows' => str_repeat('a', 3_000_001),\n"),
    (1, "                'kind' => 'buildings', 'surveyed_month' => '2026-08', 'rows' => json_encode(array_fill(0, 2000, $row)),\n"),
    (1, "                'kind' => 'buildings', 'surveyed_month' => '2026-08', 'rows' => json_encode(array_fill(0, 2001, $row)),\n"),
]:
    n = s.count(a)
    print(('OK ' if n == want else 'NG ') + str(n), repr(a[:60]))
PY
```

期待: 9 行とも `OK`（3 行目だけ `OK 2`）

置き換え（どれも「置き換える前」の行の後ろに鍵の 1 行を足す。字下げは前の行にそろえる）:

| 行 | 置き換える前 | 置き換えた後に足す行 |
|---|---|---|
| 137 | `                'rows'           => json_encode([['building_name' => 'X', 'name' => 'Y']]),` | `                'import_token'   => OneTimeAction::issue(),` |
| 288 | `                'rows' => json_encode([['name' => 'アルファビル', 'operating' => '1']]),`（直後が `            ]);`）| `                'import_token' => OneTimeAction::issue(),` |
| 443・465（`replace_all`）| `                'kind' => 'buildings',` と `                'rows' => json_encode([['name' => 'X', 'operating' => '1']]),` の 2 行 | `                'import_token' => OneTimeAction::issue(),` |
| 501 | `            'kind' => 'buildings', 'surveyed_month' => '2026-08', 'rows' => json_encode($rows),` | `            'import_token' => OneTimeAction::issue(),` |
| 540 | `            'kind' => 'tenants', 'rows' => json_encode($rows),` | `            'import_token' => OneTimeAction::issue(),` |
| 722 | `                'kind' => 'buildings', 'surveyed_month' => '2026-08', 'rows' => 'これはJSONではない',` | `                'import_token' => OneTimeAction::issue(),` |
| 739 | `                'rows' => str_repeat('a', 3_000_001),` | `                'import_token' => OneTimeAction::issue(),` |
| 753 | `                'kind' => 'buildings', 'surveyed_month' => '2026-08', 'rows' => json_encode(array_fill(0, 2000, $row)),` | `                'import_token' => OneTimeAction::issue(),` |
| 761 | `                'kind' => 'buildings', 'surveyed_month' => '2026-08', 'rows' => json_encode(array_fill(0, 2001, $row)),` | `                'import_token' => OneTimeAction::issue(),` |

例（137 行）— 置き換える前:

```php
                'rows'           => json_encode([['building_name' => 'X', 'name' => 'Y']]),
```

置き換えた後:

```php
                'rows'           => json_encode([['building_name' => 'X', 'name' => 'Y']]),
                'import_token'   => OneTimeAction::issue(),
```

⚠ 当てたあと `grep -c "'import_token'" tests/Feature/Tenant/AreaBuildingImportTest.php` が `10` であること（10 か所）。

- [ ] **Step 4: 既存のテストの 1 行を直す**（Edit。押せない条件に `submitting` が入る）

置き換える前:

```php
        $this->assertStringContainsString(':disabled="submitBlockedReason() !== null"', $html);
```

置き換えた後:

```php
        $this->assertStringContainsString(':disabled="submitting || submitBlockedReason() !== null"', $html);
```

- [ ] **Step 5: 新しいテスト 7 本**（Edit。「ヘルパー」の見出しの前に足す）

置き換える前:

```php
    // ============================================================
    // ヘルパー
    // ============================================================
```

置き換えた後:

```php
    // ============================================================
    // 取込は取込の画面 1 つにつき 1 回だけ（設計書 2026-09-28-import-double-submit-design.md）
    // ============================================================

    /** @return array<string, array{0: string}> */
    public static function kinds(): array
    {
        return ['ビル＋調査' => ['buildings'], 'テナント明細' => ['tenants']];
    }

    /** @return array<string, array{0: string|list<string>|null}> [import_token に入れる値（null なら送らない）] */
    public static function unusableTokens(): array
    {
        return [
            '鍵が無い' => [null],
            '鍵が空'   => [''],
            '鍵が配列' => [['a', 'b']],
        ];
    }

    /**
     * @return array{0: array<string, string>, 1: string, 2: int} [Alpine が入れる 3 つ, 数える表, 1 回目で増える数]
     */
    private function arrangeKind(string $kind): array
    {
        if ($kind === 'buildings') {
            return [[
                'kind' => 'buildings', 'surveyed_month' => '2026-08',
                'rows' => json_encode([
                    ['name' => 'アルファビル', 'address' => '松山市1-1', 'total_floors' => '5', 'operating' => '4', 'vacant' => '1', 'unknown' => '0'],
                    ['name' => 'ベータビル', 'total_floors' => '3', 'operating' => '3', 'vacant' => '0'],
                ]),
            ], 'area_building_surveys', 2];
        }

        $this->makeBuilding('アルファビル');
        $this->makeBuilding('ベータビル');

        return [[
            'kind' => 'tenants', 'surveyed_month' => '',
            'rows' => json_encode([
                ['building_name' => 'アルファビル', 'floor' => '3', 'room_number' => '301', 'name' => '大街道珈琲', 'industry' => '飲食', 'status' => '営業中'],
                ['building_name' => 'アルファビル', 'floor' => 'B1', 'room_number' => 'B101', 'status' => '空室'],
                ['building_name' => 'ベータビル', 'floor' => '2', 'room_number' => '201', 'name' => '花屋', 'industry' => '小売', 'status' => '営業'],
            ]),
        ], 'area_building_tenants', 3];
    }

    #[DataProvider('kinds')]
    public function test_sending_the_same_screen_twice_imports_once(string $kind): void
    {
        [$fields, $table, $added] = $this->arrangeKind($kind);
        $manager = $this->manager();
        $form    = $this->importForm($manager);
        $send    = fn () => $this->actingAs($manager)->from(self::IMPORT_URL)->post($form['action'], array_merge($form['fields'], $fields));

        $before = DB::table($table)->count();
        $send();
        $this->assertSame($before + $added, DB::table($table)->count(), '1 回目で取り込まれていない（測定が無効）');

        // 直す前: テナント明細は 3 件が 6 件になった。ビル＋調査は「同一年月のためスキップ」になり、取り込み直したように見えた
        [$second, $writes] = $this->countingWrites($send);

        $this->assertSame(0, $writes, '2 回目の送信で書き込みが走った');
        $this->assertSame($before + $added, DB::table($table)->count(), '2 回目の送信で、もう一度入った');
        $this->assertRefused($second, route('tenant.area-buildings.import'), self::USED_TOKEN, $manager);
    }

    #[DataProvider('unusableTokens')]
    public function test_a_submission_without_a_usable_token_imports_nothing(string|array|null $token): void
    {
        [$fields] = $this->arrangeKind('buildings');
        $manager  = $this->manager();
        $form     = $this->importForm($manager);
        $fields   = array_merge($form['fields'], $fields);

        if ($token === null) {
            unset($fields['import_token']);
        } else {
            $fields['import_token'] = $token;
        }

        // 500 にならない（配列の鍵は OneTimeAction::claimFrom() が is_string で断る）
        [$response, $writes] = $this->countingWrites(fn () => $this->actingAs($manager)->from(self::IMPORT_URL)->post($form['action'], $fields));

        $this->assertSame(0, $writes, '鍵が使えないのに書き込みが走った');
        $this->assertSame(0, AreaBuilding::count());
        $this->assertRefused($response, route('tenant.area-buildings.import'), self::USED_TOKEN, $manager);
    }

    public function test_opening_the_import_screen_again_issues_a_new_token(): void
    {
        [$fields] = $this->arrangeKind('buildings');
        $manager  = $this->manager();
        $first    = $this->importForm($manager);
        $second   = $this->importForm($manager);

        $this->assertNotSame($first['fields']['import_token'], $second['fields']['import_token'], '取込の画面を開くたびに鍵が変わっていない');

        $this->actingAs($manager)->post($second['action'], array_merge($second['fields'], $fields));
        $this->assertSame(2, AreaBuildingSurvey::count(), '開き直した画面の鍵で取り込めない');
    }

    public function test_the_import_form_guards_against_a_second_press(): void
    {
        $html = $this->actingAs($this->manager())->get(self::IMPORT_URL)->getContent();

        // 周辺ビルは、送ったあと「戻る」で戻ったら読み込み直して新しい鍵にする（設計書 §4.5）。薄さはいまのまま
        $this->assertSubmitOnceForm(
            $html,
            route('tenant.area-buildings.import.execute'),
            'submitOnce({ reloadOnReturn: true })',
            '取り込んでいます…',
            'disabled:opacity-50'
        );
    }

    // ============================================================
    // ヘルパー
    // ============================================================
```

- [ ] **Step 6: 直す前のコードで落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Tenant/AreaBuildingImportTest.php 2>&1 | grep -E -A1 -e '^[0-9]+\) ' -e '^Tests:' | grep -v -e '^--$'
```

期待: `Tests: 60, Assertions: 526, Errors: 1, Failures: 7.`（`importBuildings()` などを画面経由にした既存のテストは緑＝直す前の画面にも確定のフォームはある）。落ちたテストと理由:
- エラー `test_opening_the_import_screen_again_issues_a_new_token` — `ErrorException: Undefined array key "import_token"`
- `test_the_month_defaults_to_the_current_month_and_blocks_submission` — `Failed asserting that '<!DOCTYPE html>…` contains `:disabled="submitting || submitBlockedReason() !== null"`（Step 4 で直した既存の 1 行）
- `test_sending_the_same_screen_twice_imports_once` の「ビル＋調査」 — `断ったあとの戻り先が違う`（2 回目は「同一年月のためスキップ」で一覧へ）
- 同じテストの「テナント明細」 — `2 回目の送信で書き込みが走った`（3 件が 6 件になる）
- `test_a_submission_without_a_usable_token_imports_nothing` の 3 本 — `鍵が使えないのに書き込みが走った`
- `test_the_import_form_guards_against_a_second_press` — `確定のフォームの x-data が二度押し止めの部品でない`

- [ ] **Step 7: コントローラ**（`app/Http/Controllers/Tenant/AreaBuildingImportController.php`。Edit を 4 回）

1 回目 — 置き換える前:

```php
use App\Support\FloorNumber;
use Illuminate\Database\UniqueConstraintViolationException;
```

置き換えた後:

```php
use App\Support\FloorNumber;
use App\Support\OneTimeAction;
use Illuminate\Database\UniqueConstraintViolationException;
```

2 回目 — 置き換える前:

```php
 *   ⚠ 逆に「ビル行だけ作られて調査回が入らない」孤児は起こりうる。②の再実行で埋まる。
 */
class AreaBuildingImportController extends Controller
```

置き換えた後:

```php
 *   ⚠ 逆に「ビル行だけ作られて調査回が入らない」孤児は起こりうる。②の再実行で埋まる。
 *
 * ⚠ 取込は取込の画面 1 つにつき 1 回だけ（hidden の `import_token`・`OneTimeAction`。
 *   設計書 2026-09-28-import-double-submit-design.md §4.2）。確認画面が無いので、鍵は form()（取込の画面を開いたとき）で
 *   出し、execute() の最初（入力チェックの前）で使う。直す前は、同じ画面から 2 回送るとテナント明細が二重に入った
 *   （既存の行と突き合わせる手がかりが無い作り。ビル＋調査は同一年月のスキップで 2 回目が「調査追加 0 件」になった）。
 *   断ったら取込の画面へ戻す（開き直すので新しい鍵が出る）。送ったあと「戻る」で戻った画面は、画面の部品が読み込み直す
 *   （_partials/_submit_once の reloadOnReturn）。
 */
class AreaBuildingImportController extends Controller
```

3 回目 — 置き換える前:

```php
    public function form()
    {
        return view('tenant.area-buildings.import');
    }
```

置き換えた後:

```php
    public function form()
    {
        return view('tenant.area-buildings.import', [
            // 取込を 1 回だけ通す鍵（クラスの docblock）
            'importToken' => OneTimeAction::issue(),
        ]);
    }
```

4 回目 — 置き換える前:

```php
    public function execute(Request $request)
    {
        // ⚠ ルールは literal 配列で直書きする。$this->rules() のような間接参照にすると
```

置き換えた後:

```php
    public function execute(Request $request)
    {
        // 取込は取込の画面 1 つにつき 1 回だけ（クラスの docblock）。⚠ 入力チェックより先に使う
        if (! OneTimeAction::claimFrom($request, 'import_token')) {
            return redirect()->route('tenant.area-buildings.import')
                ->with('error', 'この取込画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「周辺ビル調査」で確かめられます。取り込み直すときは、ファイルを選び直してください。');
        }

        // ⚠ ルールは literal 配列で直書きする。$this->rules() のような間接参照にすると
```

⚠ 入力チェックの `$request->validate([` の形は変えない（`JapaneseValidationMessagesTest` の走査が literal 配列を探す。取込の走査テスト 2 本の例外リストにある「包んでいない入力チェック」の 1 か所もこのまま）。

- [ ] **Step 8: 取込の画面の確定のフォーム**（`resources/views/tenant/area-buildings/import.blade.php`。Edit を 2 回）

1 回目 — 置き換える前:

```blade
        <form method="POST" action="{{ route('tenant.area-buildings.import.execute') }}" class="mt-4">
            @csrf
            <input type="hidden" name="kind" :value="kind">
```

置き換えた後:

```blade
        {{-- ⚠ 取込は取込の画面 1 つにつき 1 回だけ（hidden の import_token。JS が動かないときも、サーバが 2 回目を断る）。
             送信中はボタンを押せなくする（二度押し止めの部品。設計書 2026-09-28-import-double-submit-design.md §4.5）。
             reloadOnReturn: 送ったあと「戻る」で戻ったら読み込み直して新しい鍵にする（確認画面が無いので、戻った画面の鍵は使用済み）。
             ⚠ このフォームは areaImportForm() の中に入れ子の x-data を持つ。hidden の :value（kind・surveyedMonth・payload()）と
               押せない理由（submitBlockedReason()）は、入れ子の中から親の値を読む --}}
        @include('_partials._submit_once')
        <form method="POST" action="{{ route('tenant.area-buildings.import.execute') }}" class="mt-4"
              x-data="submitOnce({ reloadOnReturn: true })" x-on:submit="onSubmit($event)" x-on:pageshow.window="onPageShow($event)">
            @csrf
            <input type="hidden" name="import_token" value="{{ $importToken }}">
            <input type="hidden" name="kind" :value="kind">
```

2 回目 — 置き換える前:

```blade
                    <button type="submit" :disabled="submitBlockedReason() !== null"
                            aria-describedby="area-import-submit-reason"
                            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors disabled:opacity-50">
                        この内容で取り込む
                    </button>
                </span>
```

置き換えた後:

```blade
                    <button type="submit" :disabled="submitting || submitBlockedReason() !== null"
                            aria-describedby="area-import-submit-reason"
                            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors cursor-pointer disabled:cursor-not-allowed disabled:opacity-50">
                        この内容で取り込む
                    </button>
                </span>
                <span role="status" x-text="submitting ? '取り込んでいます…' : ''" style="display: inline-block; font-size: 13px; color: #374151;"></span>
```

⚠ 送信中の文字は、押せない理由の `title` を持つ `span` の**外**に置く（設計書との違い 2）。
⚠ 部品の `@include` は、周辺ビルではフォームの直前（この画面の確定のフォームは条件で消えないので、`@if` は無い）。

- [ ] **Step 9: 通ることと、関係するテストが緑のままであることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Tenant/AreaBuildingImportTest.php 2>&1 | tail -1 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Tenant/AreaBuildingGeocodeTest.php tests/Feature/JapaneseValidationMessagesTest.php tests/Feature/ImportControllerReturnPathScanTest.php tests/Feature/ImportControllerValidationRedirectScanTest.php 2>&1 | tail -1
```

期待: `OK (60 tests, 571 assertions)` ／ `OK (139 tests, 423 assertions)`

- [ ] **Step 10: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && git add tests/Feature/Tenant/AreaBuildingImportTest.php app/Http/Controllers/Tenant/AreaBuildingImportController.php resources/views/tenant/area-buildings/import.blade.php && git commit -m "$(cat <<'EOF'
fix: 周辺ビル調査の取込を取込の画面 1 つにつき 1 回だけにする

同じ画面から 2 回送るとテナント明細が二重に入っていた。取込の画面を開いたときに 1 回限りの鍵を出し、
execute() の最初で使う。送ったあと「戻る」で戻った画面は読み込み直して新しい鍵にする。
テストの取込の関数は、画面が描いた鍵で送る形にする。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 7: ZEAL の本部 Sheet 取込（鍵と指紋）

**Files:**
- Modify: `tests/Concerns/CreatesZealSimulationSchema.php`（正本の DDL の列・表・一意の索引・経費の 5 項目）
- Create: `tests/Feature/Zeal/SheetImportTest.php`（この取込の初めての Feature テスト 16 本）
- Modify: `app/Http/Controllers/Zeal/SheetImportController.php`（`use`・クラスの docblock・`preview()` で鍵と指紋・`apply()` で鍵を使ってから指紋を比べる・`planDigest()`）
- Modify: `resources/views/zeal/simulations/sheet-import/preview.blade.php`（確定のフォーム）

**Interfaces:**
- Consumes: Task 1 の部品・Task 2 の `ChecksDoubleSubmit`・`App\Support\ZealSheetClient`（`final` でなく、`fetchCsv(string $url): string` は public＝子クラスで差し替えられる）・`Database\Seeders\DepartmentSeeder`
- Produces: `CreatesZealSimulationSchema::seedZealSheetImportCategories(): void`（経費の 5 項目 `outsourcing`・`session_fee`・`training_system`・`web_operation`・`store_supplies` を入れる）・`SheetImportController::planDigest(ZealSimulation $simulation, string $yearMonth, array $plan): string`（private）

⚠ シート取込は、確定（`apply()`）が本部 Sheet を読み直して反映の内容を作り直す。鍵だけでは「確認画面で見せていない値を書く」余地が残るので、指紋で断る（設計書 §4.3）。**鍵を先に使ってから指紋を比べる**（逆だと、ダブルクリックの 2 回目が、1 回目で値が書かれたあとなので「内容が変わりました」という別の理由で断られる）。

- [ ] **Step 1: テスト用スキーマを正本の DDL に合わせる**（`tests/Concerns/CreatesZealSimulationSchema.php`。Edit を 6 回）

1 回目 — 置き換える前:

```php
 * ZEAL 経営試算表の 3 テーブルは本番では raw SQL DDL
 * （database/sql/create_zeal_simulation_tables.sql）で管理され Laravel マイグレーションに無い。
```

置き換えた後:

```php
 * ZEAL 経営試算表の 3 テーブルと本部 Sheet 取込の履歴は本番では raw SQL DDL
 * （database/sql/create_zeal_simulation_tables.sql・alter_zeal_simulations_add_sheet_urls.sql・
 * create_zeal_sheet_imports_table.sql）で管理され Laravel マイグレーションに無い。
```

2 回目 — 置き換える前:

```php
 *   - ENUM は SQLite に無いので string で持つ（値の妥当性はアプリ側の責務）
 */
```

置き換えた後:

```php
 *   - ENUM は SQLite に無いので string で持つ（値の妥当性はアプリ側の責務）
 *   - 一意の索引は DDL と同じ名前で張る（2026-09-28 に足した。無いと、同じセルの 2 重書きが SQLite では黙って通る）。
 *     ⚠ 今のテストはどれも頼っていない（変異で実測。将来の偽の緑を止めるために持つ。Bug #54 ⑤）
 */
```

3 回目 — 置き換える前:

```php
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('zeal_simulations', function (Blueprint $t) {
```

置き換えた後:

```php
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique('code', 'uq_zeal_sim_cat_code');
        });

        Schema::create('zeal_simulations', function (Blueprint $t) {
```

4 回目 — 置き換える前:

```php
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
```

置き換えた後:

```php
            $t->text('notes')->nullable();
            // alter_zeal_simulations_add_sheet_urls.sql
            $t->string('sales_sheet_url', 500)->nullable();
            $t->string('expense_sheet_url', 500)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->unique('fiscal_year', 'uq_zeal_sim_fiscal_year');
        });
```

5 回目 — 置き換える前:

```php
            $t->boolean('is_manual_override')->default(false);
            $t->timestamps();
        });
    }
```

置き換えた後:

```php
            $t->boolean('is_manual_override')->default(false);
            $t->timestamps();
            $t->unique(['simulation_id', 'category_id', 'year_month'], 'uq_zeal_sim_val');
        });

        // create_zeal_sheet_imports_table.sql（一意の索引は無い）
        Schema::create('zeal_sheet_imports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('simulation_id');
            $t->string('import_type', 10);   // sales / expense
            $t->char('year_month', 7);
            $t->mediumText('raw_csv')->nullable();
            $t->json('parsed_data')->nullable();
            $t->unsignedBigInteger('imported_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['simulation_id', 'year_month'], 'idx_zeal_sheet_imports_sim_month');
            $t->index('import_type', 'idx_zeal_sheet_imports_type');
        });
    }
```

6 回目（ファイルの末尾。`seedZealSimulationCategories()` の閉じ括弧とクラスの閉じ括弧）— 置き換える前:

```php
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }
}
```

置き換えた後:

```php
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }

    /**
     * 本部 Sheet 取込が書く経費の項目（ZealExpenseMapper::WRITABLE_CODES。売上 revenue は上の最小限の項目にある）。
     * 値は DDL の seed（create_zeal_simulation_tables.sql）と insert_zeal_simulation_categories_sheet_import.sql から写した。
     */
    protected function seedZealSheetImportCategories(): void
    {
        $rows = [
            ['code' => 'outsourcing',     'name' => '委託費',           'calc_type' => 'fixed',  'default_amount' => 400000, 'sort_order' => 50],
            ['code' => 'session_fee',     'name' => '時間帯業務委託費', 'calc_type' => 'manual', 'default_amount' => null,   'sort_order' => 65],
            ['code' => 'training_system', 'name' => '研修システム',     'calc_type' => 'fixed',  'default_amount' => 15000,  'sort_order' => 80],
            ['code' => 'web_operation',   'name' => 'web運用',          'calc_type' => 'fixed',  'default_amount' => 15000,  'sort_order' => 90],
            ['code' => 'store_supplies',  'name' => '店舗備品費',       'calc_type' => 'manual', 'default_amount' => null,   'sort_order' => 145],
        ];

        foreach ($rows as $row) {
            DB::table('zeal_simulation_categories')->insert($row + [
                'group_type'   => 'expense',
                'rate_percent' => null,
                'is_system'    => 0,
                'is_active'    => 1,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }
}
```

⚠ 5 項目のコードと名前は `app/Support/ZealExpenseMapper.php` の `WRITABLE_CODES` と `database/sql/insert_zeal_simulation_categories_sheet_import.sql` で確かめる（`grep -n -e 'WRITABLE_CODES' -A8 app/Support/ZealExpenseMapper.php`）。

- [ ] **Step 2: このスキーマを使う既存のテストが緑のままであることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/MonthEndOverflowTest.php tests/Feature/Zeal/SimulationValidationFeedbackTest.php 2>&1 | tail -1
```

期待: `OK (7 tests, 41 assertions)`

- [ ] **Step 3: コミット**（スキーマだけ。1 コミット 1 関心事）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && git add tests/Concerns/CreatesZealSimulationSchema.php && git commit -m "$(cat <<'EOF'
test: 経営試算表のテスト用スキーマを本部 Sheet 取込の DDL に合わせる

正本の DDL にある Sheet の URL の 2 列・取込の履歴の表・一意の索引 3 本を足し、
本部 Sheet 取込が書く経費の 5 項目を入れる部品を足す（既存の最小の項目は変えない）。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

- [ ] **Step 4: テストを書く**（`tests/Feature/Zeal/SheetImportTest.php` を新しく作る）

```php
<?php

namespace Tests\Feature\Zeal;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\User;
use App\Models\ZealSimulation;
use App\Support\ZealSheetClient;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ChecksDoubleSubmit;
use Tests\Concerns\CreatesZealSimulationSchema;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * ZEAL 経営試算表の本部 Sheet 取込（`Zeal\SheetImportController`）のプレビュー → 反映の往復（この取込の初めての Feature テスト）。
 *
 * ⚠ 反映は確認画面 1 つにつき 1 回だけ（hidden の import_token）で、確認画面で見せた内容と同じものだけを書く
 *   （hidden の plan_digest。設計書 2026-09-28-import-double-submit-design.md §4.3）。
 *   直す前（2026-09-28 に実測）: 同じ確認画面から 2 回送ると、セルは同じ値を飛ばして書かないが、履歴（zeal_sheet_imports）は
 *   2 行が 4 行になり、「取り込みました (0 セル更新)」の成功の帯が出た。プレビューのあとで本部 Sheet が変わると、
 *   古い確認画面から、見せていない値（310,000）が書かれた。
 * ⚠ 本部 Sheet の読み先（ZealSheetClient::fetchCsv）は、決まった CSV を返す子クラスへコンテナごと差し替える
 *   （パーサ・経費の集約・buildApplyPlan は本物）。Http::preventStrayRequests() で、差し替えが効いていなければ外へ出ずに落ちる。
 */
class SheetImportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesZealSimulationSchema;
    use ParsesForms;
    use ChecksDoubleSubmit;

    private const YEAR_MONTH  = '2026-07';
    private const SALES_URL   = 'https://docs.google.com/spreadsheets/d/TEST-SALES/export?format=csv&gid=0';
    private const EXPENSE_URL = 'https://docs.google.com/spreadsheets/d/TEST-EXPENSE/export?format=csv&gid=0';

    /** 売上 Sheet（A）: 3 式とも整合。当月売上合計 304,638 */
    private const SALES_A = "項目,金額\n"
        . "当月日割売上金,200000\n"
        . "前月時点会費預り金,100000\n"
        . "調整金,4638\n"
        . "当月売上合計,304638\n"
        . "ロイヤリティ額,9139\n"
        . "差し引き精算額,295499\n";

    /** 売上 Sheet（B）: プレビューのあとに本部が直した想定。当月売上合計 310,000 */
    private const SALES_B = "項目,金額\n"
        . "当月日割売上金,205362\n"
        . "前月時点会費預り金,100000\n"
        . "調整金,4638\n"
        . "当月売上合計,310000\n"
        . "ロイヤリティ額,9300\n"
        . "差し引き精算額,300700\n";

    /** 売上 Sheet（A の書き込みに使わない行だけを直したもの）: 当月売上合計は 304,638 のまま */
    private const SALES_A_NOTE_ONLY = "項目,金額\n"
        . "当月日割売上金,200000\n"
        . "前月時点会費預り金,100000\n"
        . "調整金,4638\n"
        . "当月売上合計,304638\n"
        . "ロイヤリティ額,9139\n"
        . "差し引き精算額,295499\n"
        . "備考,1\n";

    /** 経費 Sheet: 委託費 440,000・時間帯業務委託費 33,000・研修システム 16,500・WEB運用費 16,500・店舗備品費 5,500 */
    private const EXPENSE = "項目,金額(税込)\n"
        . "運営費,516053\n"
        . "店舗備品費,5500\n"
        . "総計,521553\n"
        . "\n"
        . "項目,納品月,品目,個数,金額(税込)\n"
        . "運営費,2026-07,店舗運営委託費,1,440000\n"
        . "運営費,2026-07,時間帯業務委託費,3,33000\n"
        . "運営費,2026-07,研修システム,1,16500\n"
        . "運営費,2026-07,WEB運用費,1,16500\n"
        . "運営費,2026-07,hacomono決済手数料,1,10053\n"
        . "店舗備品費,2026-07,トイレットペーパー,2,5500\n";

    /** 同じ確認画面から 2 回目を送ったとき（1 回限りの鍵が使えないとき）の案内（設計書 §4.4） */
    private const USED_TOKEN = 'この確認画面からは反映できません（すでに送信したか、画面が古くなっています）。反映されたかは、この試算表で確かめられます。反映し直すときは、「本部 Sheet を取り込む」からもう一度プレビューしてください。';

    /** プレビューのあとで反映する内容が変わったときの案内（設計書 §4.3） */
    private const PLAN_CHANGED = 'プレビューのあとで反映する内容が変わりました（本部 Sheet か試算表の値が変わっています）。もう一度プレビューしてください。';

    /** 本部 Sheet の読み先の差し替え（URL => CSV） */
    private object $sheets;

    private User $user;

    private ZealSimulation $simulation;

    protected function setUp(): void
    {
        parent::setUp();

        // 差し替えが効いていなければ外へ出ずに落ちる
        Http::preventStrayRequests();

        $this->createZealSimulationSchema();
        $this->seedZealSimulationCategories();
        $this->seedZealSheetImportCategories();
        $this->seed(DepartmentSeeder::class);

        $this->sheets = new class extends ZealSheetClient
        {
            /** @var array<string, string> */
            public array $csv = [];

            public function fetchCsv(string $url): string
            {
                if (! array_key_exists($url, $this->csv)) {
                    throw new \RuntimeException("テストで用意していない URL: {$url}");
                }

                return $this->csv[$url];
            }
        };
        $this->sheets->csv = [self::SALES_URL => self::SALES_A, self::EXPENSE_URL => self::EXPENSE];
        $this->app->instance(ZealSheetClient::class, $this->sheets);

        // zeal 部門の管理者（role:executive,manager と department.access:zeal を通る）
        $this->user = User::factory()->create(['role' => UserRole::Manager->value, 'must_change_password' => false]);
        $this->user->departments()->attach(Department::where('code', 'zeal')->value('id'));

        $this->simulation = ZealSimulation::create([
            'fiscal_year'       => 2026,
            'name'              => '2026年度',
            'sales_sheet_url'   => self::SALES_URL,
            'expense_sheet_url' => self::EXPENSE_URL,
        ]);

        // いまのセル: 売上は「実績を反映」で入った値、委託費は固定額の既定値の想定。
        // 1 回目で UPDATE（売上・委託費）と INSERT（残り 4 項目）の両方を通す
        $this->setCell('revenue', 250000);
        $this->setCell('outsourcing', 400000);
    }

    // ================================================================
    // 往復
    // ================================================================

    public function test_applying_the_preview_writes_the_cells_and_the_history(): void
    {
        $landed = $this->apply($this->previewForm());

        $this->assertSame(302, $landed->getStatusCode());
        $this->assertSame(route('zeal.simulations.show', $this->simulation), $landed->headers->get('Location'));
        $this->assertSame('2026-07 の本部 Sheet を取り込みました (6 セル更新)。', session('success'));
        $this->assertSame(
            ['outsourcing' => 440000, 'revenue' => 304638, 'session_fee' => 33000, 'store_supplies' => 5500, 'training_system' => 16500, 'web_operation' => 16500],
            $this->cells()
        );
        $this->assertSame(['sales', 'expense'], DB::table('zeal_sheet_imports')->orderBy('id')->pluck('import_type')->all());
    }

    // ================================================================
    // 反映は確認画面 1 つにつき 1 回だけ
    // ================================================================

    public function test_sending_the_same_confirmation_twice_applies_once(): void
    {
        $form = $this->previewForm();
        $this->apply($form);
        $this->assertSame(2, DB::table('zeal_sheet_imports')->count(), '1 回目で履歴が 2 行できていない（測定が無効）');

        [$second, $writes] = $this->countingWrites(fn () => $this->apply($form));

        // 鍵を先に使う（指紋を先に比べると、1 回目で値が書かれたあとなので「内容が変わりました」という別の理由で断る）
        $this->assertSame(0, $writes, '2 回目の送信で書き込みが走った（履歴が増える）');
        $this->assertSame(2, DB::table('zeal_sheet_imports')->count());
        $this->assertRefused($second, route('zeal.simulations.show', $this->simulation), self::USED_TOKEN, $this->user);
    }

    /** @return array<string, array{0: string|list<string>|null}> [import_token に入れる値（null なら送らない）] */
    public static function unusableTokens(): array
    {
        return [
            '鍵が無い' => [null],
            '鍵が空'   => [''],
            '鍵が配列' => [['a', 'b']],
        ];
    }

    #[DataProvider('unusableTokens')]
    public function test_a_confirmation_without_a_usable_token_applies_nothing(string|array|null $token): void
    {
        $form = $this->withField($this->previewForm(), 'import_token', $token);

        // 500 にならない（配列の鍵は OneTimeAction::claimFrom() が is_string で断る）
        [$response, $writes] = $this->countingWrites(fn () => $this->apply($form));

        $this->assertSame(0, $writes, '鍵が使えないのに書き込みが走った');
        $this->assertSame(0, DB::table('zeal_sheet_imports')->count());
        $this->assertRefused($response, route('zeal.simulations.show', $this->simulation), self::USED_TOKEN, $this->user);
    }

    public function test_previewing_again_issues_a_new_token(): void
    {
        $first  = $this->previewForm();
        $second = $this->previewForm();

        $this->assertNotSame($first['fields']['import_token'], $second['fields']['import_token'], 'プレビューごとに鍵が変わっていない');

        $this->apply($second);
        $this->assertSame(304638, $this->cells()['revenue'], 'プレビューし直した確認画面の鍵で反映できない');
    }

    // ================================================================
    // 確認画面で見せた内容と同じものだけを書く（指紋。設計書 §4.3 の表）
    // ================================================================

    public function test_a_changed_amount_in_the_sheet_turns_the_confirmation_back(): void
    {
        $form = $this->previewForm();

        // プレビューのあとで、本部が売上 Sheet を直した（直す前は 310,000 が書かれた）
        $this->sheets->csv[self::SALES_URL] = self::SALES_B;

        $this->assertTurnedBack($form);
        $this->assertSame(250000, $this->cells()['revenue']);
    }

    public function test_a_cell_that_now_needs_writing_turns_the_confirmation_back(): void
    {
        // 研修システムはプレビューのとき本部 Sheet と同じ値（書かない行）
        $this->setCell('training_system', 16500);
        $form = $this->previewForm();

        // プレビューのあとで手で直した（書く行になった）
        $this->setCell('training_system', 15000);

        $this->assertTurnedBack($form);
        $this->assertSame(15000, $this->cells()['training_system']);
    }

    public function test_a_cell_that_no_longer_needs_writing_turns_the_confirmation_back(): void
    {
        $form = $this->previewForm();

        // プレビューのあとで、委託費を本部 Sheet と同じ値に手で直した（書く行でなくなった）
        $this->setCell('outsourcing', 440000);

        $this->assertTurnedBack($form);
    }

    public function test_applying_the_same_month_from_another_tab_turns_the_later_confirmation_back(): void
    {
        $firstTab  = $this->previewForm();
        $secondTab = $this->previewForm();

        $this->apply($firstTab);

        // 後のタブの鍵は使えるが、書く行がもう無い（順に届く 2 つのタブの重複もこれで止まる）
        $this->assertTurnedBack($secondTab);
        $this->assertSame(2, DB::table('zeal_sheet_imports')->count());
    }

    public function test_a_changed_current_value_of_a_written_cell_still_applies(): void
    {
        $form = $this->previewForm();

        // 書く行（委託費）のいまの値だけが変わった（書く値 440,000 は同じ）
        $this->setCell('outsourcing', 410000);

        $this->apply($form);
        $this->assertSame(440000, $this->cells()['outsourcing'], 'プレビューで見せた値が書かれていない');
    }

    public function test_changes_that_do_not_touch_the_plan_still_apply_and_the_history_keeps_what_was_read(): void
    {
        $form = $this->previewForm();

        // 反映に関係の無いセル（賃料）と、本部 Sheet の書き込みに使わない行だけが変わった
        $this->setCell('rent', 180000);
        $this->sheets->csv[self::SALES_URL] = self::SALES_A_NOTE_ONLY;

        $this->apply($form);
        $this->assertSame(304638, $this->cells()['revenue']);
        // 履歴の raw_csv は、反映のときに読んだ中身で残す
        $this->assertSame(self::SALES_A_NOTE_ONLY, DB::table('zeal_sheet_imports')->where('import_type', 'sales')->value('raw_csv'));
    }

    /** @return array<string, array{0: string|list<string>|null}> [plan_digest に入れる値（null なら送らない）] */
    public static function unusableDigests(): array
    {
        return [
            '指紋が無い' => [null],
            '指紋が空'   => [''],
            '指紋が配列' => [['a', 'b']],
        ];
    }

    #[DataProvider('unusableDigests')]
    public function test_a_confirmation_without_a_usable_digest_applies_nothing(string|array|null $digest): void
    {
        // 500 にならない（配列を hash_equals() に渡すと TypeError になるので、is_string で先に断る）
        $this->assertTurnedBack($this->withField($this->previewForm(), 'plan_digest', $digest));
    }

    public function test_the_confirmation_form_guards_against_a_second_press(): void
    {
        $html = $this->preview()->getContent();
        $action = route('zeal.simulations.sheet-import.apply', $this->simulation);

        $this->assertSubmitOnceForm($html, $action, 'submitOnce()', '反映しています…');

        $form = $this->parseForm($html, 'action="' . $action . '"');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $form['fields']['plan_digest'] ?? '', '確定のフォームに指紋（plan_digest）が無い');
    }

    // ================================================================
    // 部品
    // ================================================================

    /** 試算表の画面の月選択から「プレビューを表示」を押す（フォームが送るのは year_month だけ） */
    private function preview(): TestResponse
    {
        return $this->actingAs($this->user)
            ->post(route('zeal.simulations.sheet-import.preview', $this->simulation), ['year_month' => self::YEAR_MONTH])
            ->assertOk();
    }

    /** プレビューが描いた「試算表に反映する」のフォームを分解する */
    private function previewForm(): array
    {
        return $this->parseForm(
            $this->preview()->getContent(),
            'action="' . route('zeal.simulations.sheet-import.apply', $this->simulation) . '"'
        );
    }

    /** 確認画面から反映を送る（ブラウザと同じく、リファラーは確認画面の URL） */
    private function apply(array $form): TestResponse
    {
        return $this->actingAs($this->user)
            ->from(route('zeal.simulations.sheet-import.preview', $this->simulation))
            ->post($form['action'], $form['fields']);
    }

    /** フォームの 1 つの値を差し替える（null なら送らない） */
    private function withField(array $form, string $name, string|array|null $value): array
    {
        if ($value === null) {
            unset($form['fields'][$name]);
        } else {
            $form['fields'][$name] = $value;
        }

        return $form;
    }

    /** 反映が「内容が変わりました」で断られ、セルにも履歴にも何も書かないこと */
    private function assertTurnedBack(array $form): void
    {
        $cells = $this->cells();
        $history = DB::table('zeal_sheet_imports')->count();

        [$response, $writes] = $this->countingWrites(fn () => $this->apply($form));

        $this->assertSame(0, $writes, '断るのに書き込みが走った');
        $this->assertSame($cells, $this->cells());
        $this->assertSame($history, DB::table('zeal_sheet_imports')->count());
        $this->assertRefused($response, route('zeal.simulations.show', $this->simulation), self::PLAN_CHANGED, $this->user);
    }

    private function setCell(string $code, int $amount): void
    {
        DB::table('zeal_simulation_values')->updateOrInsert(
            [
                'simulation_id' => $this->simulation->id,
                'category_id'   => DB::table('zeal_simulation_categories')->where('code', $code)->value('id'),
                'year_month'    => self::YEAR_MONTH,
            ],
            ['amount' => $amount, 'is_manual_override' => 0, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    /** @return array<string, int> その月のセル（項目のコード => 金額。コードの順） */
    private function cells(): array
    {
        return DB::table('zeal_simulation_values as v')
            ->join('zeal_simulation_categories as c', 'c.id', '=', 'v.category_id')
            ->where('v.simulation_id', $this->simulation->id)
            ->where('v.year_month', self::YEAR_MONTH)
            ->orderBy('c.code')
            ->pluck('v.amount', 'c.code')
            ->map(fn ($amount) => (int) $amount)
            ->all();
    }
}
```

- [ ] **Step 5: 直す前のコードで落ちることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Zeal/SheetImportTest.php 2>&1 | grep -E -A1 -e '^[0-9]+\) ' -e '^Tests:' | grep -v -e '^--$'
```

期待: `Tests: 16, Assertions: 110, Errors: 1, Failures: 12.`（緑は 3 本＝往復・「書く行のいまの値だけが変わった」・「反映に関係の無い変更」。この 3 本は直す前から正しく反映する）。落ちたテストと理由:
- エラー `test_previewing_again_issues_a_new_token` — `ErrorException: Undefined array key "import_token"`
- `test_sending_the_same_confirmation_twice_applies_once` — `2 回目の送信で書き込みが走った（履歴が増える）`
- `test_a_confirmation_without_a_usable_token_applies_nothing` の 3 本 — `鍵が使えないのに書き込みが走った`
- 指紋の 4 場面（金額が変わった・書く行になった・書く行でなくなった・別のタブが先に反映）と `test_a_confirmation_without_a_usable_digest_applies_nothing` の 3 本 — `断るのに書き込みが走った`（直す前は、見せていない値を書く）
- `test_the_confirmation_form_guards_against_a_second_press` — `確定のフォームの x-data が二度押し止めの部品でない`

- [ ] **Step 6: コントローラ — `use`・クラスの docblock・`preview()`**（`app/Http/Controllers/Zeal/SheetImportController.php`。Edit を 3 回）

1 回目 — 置き換える前:

```php
use App\Models\ZealSimulationValue;
use App\Support\ZealExpenseMapper;
```

置き換えた後:

```php
use App\Models\ZealSimulationValue;
use App\Support\OneTimeAction;
use App\Support\ZealExpenseMapper;
```

2 回目 — 置き換える前:

```php
 * 上書きされない。本部 Sheet を売上の「正」、syncActuals を予測・認識として併存させる設計。
 */
class SheetImportController extends Controller
```

置き換えた後:

```php
 * 上書きされない。本部 Sheet を売上の「正」、syncActuals を予測・認識として併存させる設計。
 *
 * ⚠ 反映は確認画面 1 つにつき 1 回だけ（hidden の `import_token`・`OneTimeAction`）で、確認画面で見せた内容と
 *   同じものだけを書く（hidden の `plan_digest`。設計書 2026-09-28-import-double-submit-design.md §4.2・§4.3）。
 *   apply() は本部 Sheet を読み直して反映の内容を作り直すので、鍵だけでは「見せていない値を書く」余地が残る。
 *   直す前は、同じ確認画面から 2 回送ると履歴が二重になり、プレビューのあとで本部 Sheet が変わると見せていない値を書いた。
 *   ⚠ 鍵を先に使ってから指紋を比べる（逆だと、ダブルクリックの 2 回目が、1 回目で値が書かれたあとなので
 *     「内容が変わりました」という別の理由で断られる）。
 */
class SheetImportController extends Controller
```

3 回目 — 置き換える前:

```php
            'applyPlan'       => $applyPlan,
        ]);
```

置き換えた後:

```php
            'applyPlan'       => $applyPlan,
            // 反映を 1 回だけ通す鍵と、見せた内容の指紋（クラスの docblock）
            'importToken'     => OneTimeAction::issue(),
            'planDigest'      => $this->planDigest($simulation, $yearMonth, $applyPlan),
        ]);
```

- [ ] **Step 7: コントローラ — `apply()` で鍵を使ってから指紋を比べる**（Edit を 2 回）

1 回目 — 置き換える前:

```php
    public function apply(Request $request, ZealSimulation $simulation)
    {
        $yearMonth = (string) $request->input('year_month', '');
```

置き換えた後:

```php
    public function apply(Request $request, ZealSimulation $simulation)
    {
        // 反映は確認画面 1 つにつき 1 回だけ（クラスの docblock）。⚠ 指紋より先に使う
        if (! OneTimeAction::claimFrom($request, 'import_token')) {
            return redirect()
                ->route('zeal.simulations.show', $simulation)
                ->with('error', 'この確認画面からは反映できません（すでに送信したか、画面が古くなっています）。反映されたかは、この試算表で確かめられます。反映し直すときは、「本部 Sheet を取り込む」からもう一度プレビューしてください。');
        }

        $yearMonth = (string) $request->input('year_month', '');
```

2 回目（反映の内容を作り直した直後・書く前。`$appliedCount = 0;` はファイルに 1 か所）— 置き換える前:

```php
        $appliedCount = 0;
```

置き換えた後:

```php
        // 確認画面で見せた内容と同じものだけを書く（クラスの docblock）。
        // ⚠ 配列が送られると hash_equals() が TypeError（500）になるので、文字列かを先に見る
        $given = $request->input('plan_digest');
        if (! is_string($given) || ! hash_equals($this->planDigest($simulation, $yearMonth, $applyPlan), $given)) {
            return redirect()
                ->route('zeal.simulations.show', $simulation)
                ->with('error', 'プレビューのあとで反映する内容が変わりました（本部 Sheet か試算表の値が変わっています）。もう一度プレビューしてください。');
        }

        $appliedCount = 0;
```

⚠ 空の `plan_digest` は `ConvertEmptyStringsToNull` で `null` になって届く（変異 D02 で、`is_string` を外すと「指紋が空」も `TypeError … null given` で 500 になった）。

- [ ] **Step 8: コントローラ — 指紋を作るメソッド**（Edit。`planRow()` の docblock の前に足す）

置き換える前:

```php
    /**
     * plan の 1 行分。category_code を ID 解決できなければ null。
     */
```

置き換えた後:

```php
    /**
     * 反映の内容の指紋: どの試算表の・どの月に・どの項目を・いくらにするか（書く行だけ。並びは buildApplyPlan() の順）。
     * プレビューと反映の両方がここだけで作る（設計書 §4.3）。
     *
     * ⚠ 古い画面を見分けるためのもので、改ざんを防ぐ署名ではない（書き換えても、本人がもう一度プレビューするのと
     *   同じことしかできない）。⚠ いまの値は入れない（書く値が同じなら、プレビューし直しても最後に書く値は同じ）。
     */
    private function planDigest(ZealSimulation $simulation, string $yearMonth, array $plan): string
    {
        $writes = [];
        foreach ($plan as $row) {
            if ($row['will_update']) {
                $writes[] = [$row['category_id'], $row['new_amount']];
            }
        }

        return hash('sha256', json_encode([$simulation->id, $yearMonth, $writes]));
    }

    /**
     * plan の 1 行分。category_code を ID 解決できなければ null。
     */
```

- [ ] **Step 9: 確認画面**（`resources/views/zeal/simulations/sheet-import/preview.blade.php`。Edit）

置き換える前:

```blade
        @if($hasAnyUpdates)
            <form method="POST" action="{{ route('zeal.simulations.sheet-import.apply', $simulation) }}" style="display:inline;">
                @csrf
                <input type="hidden" name="year_month" value="{{ $yearMonth }}">
                <button type="submit"
                        style="padding:8px 20px; font-size:13px; font-weight:700; color:#fff; border:1px solid #7c3aed; border-radius:6px; background:#7c3aed; cursor:pointer;">
                    試算表に反映する
                </button>
            </form>
```

置き換えた後:

```blade
        @if($hasAnyUpdates)
            {{-- ⚠ 反映は確認画面 1 つにつき 1 回だけ（hidden の import_token）で、見せた内容と同じものだけを書く（hidden の plan_digest）。
                 送信中はボタンを押せなくする（二度押し止めの部品。設計書 2026-09-28-import-double-submit-design.md §4.3・§4.5） --}}
            @include('_partials._submit_once')
            <form method="POST" action="{{ route('zeal.simulations.sheet-import.apply', $simulation) }}" style="display:inline;"
                  x-data="submitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="onPageShow($event)">
                @csrf
                <input type="hidden" name="year_month" value="{{ $yearMonth }}">
                <input type="hidden" name="plan_digest" value="{{ $planDigest }}">
                <input type="hidden" name="import_token" value="{{ $importToken }}">
                <button type="submit" :disabled="submitting"
                        class="cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"
                        style="padding:8px 20px; font-size:13px; font-weight:700; color:#fff; border:1px solid #7c3aed; border-radius:6px; background:#7c3aed;">
                    試算表に反映する
                </button>
                <span role="status" x-text="submitting ? '反映しています…' : ''" style="display: inline-block; margin-left: 12px; font-size: 13px; color: #374151;"></span>
            </form>
```

⚠ `@else` の「反映する変更がありません」の押せないボタン（`cursor:not-allowed` を style に持つ）は変えない。

- [ ] **Step 10: 通ることを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Zeal/SheetImportTest.php 2>&1 | tail -1 && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/MonthEndOverflowTest.php tests/Feature/Zeal/SimulationValidationFeedbackTest.php tests/Feature/ImportControllerReturnPathScanTest.php tests/Feature/ImportControllerValidationRedirectScanTest.php 2>&1 | tail -1
```

期待: `OK (16 tests, 197 assertions)` ／ `OK (105 tests, 278 assertions)`

- [ ] **Step 11: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && git add tests/Feature/Zeal/SheetImportTest.php app/Http/Controllers/Zeal/SheetImportController.php resources/views/zeal/simulations/sheet-import/preview.blade.php && git commit -m "$(cat <<'EOF'
fix: 本部 Sheet 取込の反映を確認画面 1 つにつき 1 回だけにし、見せた内容だけを書く

同じ確認画面から 2 回送ると履歴が二重になり、プレビューのあとで本部 Sheet が変わると見せていない値を書いていた。
preview() で 1 回限りの鍵と指紋（どの試算表のどの月にどの項目をいくらにするか）を出し、apply() は鍵を使ってから
指紋を比べる。違えば何も書かずに試算表の画面へ戻す。この取込の初めての Feature テストを足す。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 8: 全件分類と件数の下限

**Files:**
- Modify: `tests/Feature/SubmitOnceTest.php`（鍵を描く画面の全件分類）
- Create: `tests/Feature/ImportControllerOneTimeKeyScanTest.php`
- Modify: `tests/Feature/Approval/LoginGuideTest.php`（`claimFrom()` の件数の下限 6 → 12 と注記。⚠ 261-270 行の docblock は Task 9）
- Modify: `app/Support/OneTimeAction.php`（docblock の「使っている所」3 か所。処理は変えない）

**Interfaces:**
- Consumes: Task 2〜7 の 6 画面の `name="import_token"` と 6 本のコントローラの `OneTimeAction::claimFrom($request, 'import_token')`・既存の `Tests\Concerns\ScansImportControllers::importControllerFiles(): array`（`*ImportController.php` の相対パス => 絶対パス。下限 8 本を自分で確かめる）
- Produces: 無し

⚠ ここで足す走査は、Task 2〜7 が済んでいれば初めから緑。赤になることは Task 12 の変異（鍵を外す K01〜K06・部品の `@include` を消す UT02 ほか・鍵の hidden の名前を変える UT01 ほか）で確かめる。
⚠ 全件分類（Top trap #13）。**直したファイルを並べる形にしない**。対象を機械的に列挙し、どちらにも分類されていなければ落とす。

- [ ] **Step 1: `SubmitOnceTest` に鍵を描く画面の全件分類を足す**（Edit を 3 回）

1 回目 — 置き換える前:

```php
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
```

置き換えた後:

```php
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
```

2 回目 — 置き換える前:

```php
class SubmitOnceTest extends TestCase
{
    /** 部品を読み込んで、レイアウトと同じく scripts のスタックを最後に出したページ */
```

置き換えた後:

```php
class SubmitOnceTest extends TestCase
{
    /**
     * 1 回限りの鍵（import_token）を描くのに、この部品を使わない画面 => 理由（全件分類。Top trap #13）。
     * ⚠ 足すときは理由を書く。鍵を描く画面は、ここか部品のどちらかに必ず分類される
     */
    private const OWN_GUARD = [
        'admin/customers/import.blade.php' => '独自の csvImport() が二度押し止めを持つ（2026-09-27。共通の部品へのそろえは範囲外。設計書 §6）',
    ];

    /** 鍵を描く画面の数の下限（2026-09-28 実測 7。空振りして緑になる事故を防ぐ） */
    private const MIN_TOKEN_VIEWS = 7;

    /** 部品を読み込んで、レイアウトと同じく scripts のスタックを最後に出したページ */
```

3 回目 — 置き換える前:

```php
    public function test_the_script_is_defined_once_in_the_scripts_stack(): void
```

置き換えた後:

```php
    /**
     * 1 回限りの鍵（name="import_token"）を描く画面を機械的に列挙し、どれも部品を使っていること。
     * ⚠ Blade コメントを落としてから見る（注意書きに同じ文字列を書くと、実体を消しても緑になる。Bug #42 ②）
     */
    public function test_every_view_that_carries_an_import_token_uses_submit_once(): void
    {
        $found    = [];
        $problems = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($file->getPathname()));
            if (! str_contains($source, 'name="import_token"')) {
                continue;
            }

            $relative = str_replace(resource_path('views') . '/', '', $file->getPathname());
            $found[]  = $relative;
            if (array_key_exists($relative, self::OWN_GUARD)) {
                continue;
            }
            if (! str_contains($source, "@include('_partials._submit_once')")) {
                $problems[] = "{$relative}（二度押し止めの部品を読み込んでいない）";
            }
            if (preg_match('/\sx-data="submitOnce\(/', $source) !== 1) {
                $problems[] = "{$relative}（確定のフォームの x-data が submitOnce() でない）";
            }
        }
        sort($found);

        $this->assertSame([], $problems, "鍵を描くのに二度押し止めの部品を使っていない画面がある:\n" . implode("\n", $problems));
        foreach (array_keys(self::OWN_GUARD) as $view) {
            $this->assertContains($view, $found, "OWN_GUARD の {$view} が鍵を描いていない（分類が古い）");
        }
        $this->assertGreaterThanOrEqual(self::MIN_TOKEN_VIEWS, count($found), '鍵を描く画面の列挙が痩せている（走査の空振り）');
    }

    public function test_the_script_is_defined_once_in_the_scripts_stack(): void
```

- [ ] **Step 2: 取込のコントローラの全件分類**（`tests/Feature/ImportControllerOneTimeKeyScanTest.php` を新しく作る）

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\Concerns\ScansImportControllers;
use Tests\TestCase;

/**
 * 取込のコントローラ（`*ImportController.php`）は、どれも確定で 1 回限りの鍵を使うこと
 * （`OneTimeAction::claimFrom(`。設計書 2026-09-28-import-double-submit-design.md §5.5）。
 *
 * ⚠ 全件分類（Top trap #13）。列挙は ImportControllerReturnPathScanTest・ImportControllerValidationRedirectScanTest と
 *   同じ `ScansImportControllers` を共用する（別々に列挙すると、片方だけ範囲が変わって見落としが生まれる）。
 *   新しい取込を足したら、鍵を使うか、下の WITHOUT_KEY に理由つきで足す。
 * ⚠ コメントを落としてから数える（docblock に `OneTimeAction::claimFrom()` と書いてあると、実体を消しても緑になる。Bug #42 ②）。
 * ⚠ 見るのは呼び出しがあることだけ。確定の入口で、ほかの検査より先に使っていることは、取込ごとの 2 回送るテストが見る
 *   （2 回目がほかの案内に着かない・書き込みが 0）。
 */
class ImportControllerOneTimeKeyScanTest extends TestCase
{
    use ScansImportControllers;

    /** 鍵を使わない取込 => 理由（2026-09-28 時点で 0 件） */
    private const WITHOUT_KEY = [];

    public function test_every_import_controller_claims_a_one_time_key(): void
    {
        $missing = [];

        foreach ($this->importControllerFiles() as $relative => $path) {
            if (array_key_exists($relative, self::WITHOUT_KEY)) {
                continue;
            }
            if (! str_contains($this->withoutComments($path), 'OneTimeAction::claimFrom(')) {
                $missing[] = $relative;
            }
        }

        $this->assertSame([], $missing, "確定で 1 回限りの鍵を使っていない取込がある:\n" . implode("\n", $missing));
    }

    public function test_the_exceptions_are_real_files(): void
    {
        $files = $this->importControllerFiles();

        foreach (array_keys(self::WITHOUT_KEY) as $relative) {
            $this->assertArrayHasKey($relative, $files, "WITHOUT_KEY の {$relative} が無い（分類が古い）");
        }
    }

    private function withoutComments(string $path): string
    {
        $code = '';

        foreach (token_get_all(File::get($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
```

- [ ] **Step 3: `LoginGuideTest` の件数の下限を 12 にする**（Edit を 2 回。本物は 決裁 5 か所＋取込 7 か所＝12）

1 回目 — 置き換える前:

```php
     *   決裁の 1 か所を消してもこのテストは緑のままだった（同日のレビューで実測）。
```

置き換えた後:

```php
     *   決裁の 1 か所を消してもこのテストは緑のままだった（同日のレビューで実測）。
     *   2026-09-28 にほかの取込の確定 6 か所（テナント・賃貸マンションの `loadCsv()`・ZEAL 会員・工程表・
     *   ZEAL の本部 Sheet・周辺ビル）を足して 6 → 12 にした（設計書 2026-09-28-import-double-submit-design.md §5.3）。
```

2 回目 — 置き換える前:

```php
        $this->assertGreaterThanOrEqual(
            6,
            $claimFromCallSites,
            'claimFrom() の呼び出しが減っている（決裁の store / resetPassword / execute / reissue / reissueBulk の 5 箇所と、顧客 CSV の取込の execute の 1 箇所が既定）'
        );
```

置き換えた後:

```php
        $this->assertGreaterThanOrEqual(
            12,
            $claimFromCallSites,
            'claimFrom() の呼び出しが減っている（決裁の store / resetPassword / execute / reissue / reissueBulk の 5 箇所と、'
            . '取込の確定 7 箇所（顧客・テナント・賃貸マンション・ZEAL 会員・工程表・ZEAL の本部 Sheet・周辺ビル）が既定）'
        );
```

⚠ 呼び出しを足したら下限も上げる（Bug #67 のレビューで、下限を据え置くと 1 か所消しても緑だったことを実測）。

- [ ] **Step 4: 3 本を流す**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/SubmitOnceTest.php tests/Feature/ImportControllerOneTimeKeyScanTest.php tests/Feature/Approval/LoginGuideTest.php 2>&1 | tail -1
```

期待: `OK (33 tests, 158 assertions)`（`SubmitOnceTest` 9 本・28、`ImportControllerOneTimeKeyScanTest` 2 本・5、`LoginGuideTest` 22 本・125）

- [ ] **Step 5: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && git add tests/Feature/SubmitOnceTest.php tests/Feature/ImportControllerOneTimeKeyScanTest.php tests/Feature/Approval/LoginGuideTest.php && git commit -m "$(cat <<'EOF'
test: 取込の確定の鍵と二度押し止めの部品を全件分類で守る

鍵を描く画面は部品を使うこと（顧客の取込だけ理由つきの例外）、取込のコントローラは確定で鍵を使うことを、
機械的に列挙して確かめる。LoginGuideTest の claimFrom() の件数の下限を 6 から 12 に上げる。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

- [ ] **Step 6: `OneTimeAction` の docblock の「使っている所」**（`app/Support/OneTimeAction.php`。Edit を 4 回。処理は変えない）

1 回目 — 置き換える前:

```php
 * 顧客 CSV の取込の確定（`Admin\CustomerImportController`）も同じ鍵で 1 回だけにする
 * （2 回目を通すと、チェック済みなら全員がもう一度入る。設計書 2026-09-27-customer-import-double-submit-design.md）。
```

置き換えた後:

```php
 * 取込の確定も同じ鍵で 1 回だけにする（hidden の `import_token`）: 顧客 CSV（`Admin\CustomerImportController`。
 * 2 回目を通すと、チェック済みなら全員がもう一度入る。設計書 2026-09-27-customer-import-double-submit-design.md）と、
 * テナント・賃貸マンション・ZEAL 会員・工程表・ZEAL の本部 Sheet・周辺ビルの 6 本（2 回目を通すと契約・テナント明細が
 * 二重に入り、シート取込は履歴が二重になる。設計書 2026-09-28-import-double-submit-design.md）。
```

2 回目 — 置き換える前:

```php
        // 覚えておく時間は決裁の設定を顧客の取込でも共用する（既定 12 時間。二度押しと「戻る」には足りる）。
```

置き換えた後:

```php
        // 覚えておく時間は決裁の設定を取込でも共用する（既定 12 時間。二度押しと「戻る」には足りる）。
```

3 回目（`claimFrom()` の docblock）— 置き換える前:

```php
     * リクエストの hidden（既定は `guide_token`。顧客の取込は `import_token`）を受け取って鍵を使う。**入口はここを通す。**
```

置き換えた後:

```php
     * リクエストの hidden（既定は `guide_token`。取込は `import_token`）を受け取って鍵を使う。**入口はここを通す。**
```

4 回目 — 置き換える前:

```php
     *   配列にならない——実測 `name="guide_token"` 5 箇所・`name="import_token"` 1 箇所とも単数形）。
```

置き換えた後:

```php
     *   配列にならない——実測 `name="guide_token"` 5 箇所・`name="import_token"` 7 箇所とも単数形）。
```

⚠ 当てたあと、描いている数を確かめる:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && grep -rhoF 'name="import_token"' resources/views | wc -l && grep -rhoF 'name="guide_token"' resources/views | wc -l
```

期待: `7` と `5`（描いている所の数。`import_token` は 7 ファイルに 1 か所ずつ、`guide_token` は 3 ファイルに 5 か所）

- [ ] **Step 7: 鍵を使う画面のテストをまとめて流してコミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/LoginGuideTest.php tests/Feature/Admin/CustomerImportTest.php 2>&1 | tail -1 && git add app/Support/OneTimeAction.php && git commit -m "$(cat <<'EOF'
docs: OneTimeAction の注記に取込の確定の 7 か所を書く

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

期待: `OK (51 tests, 531 assertions)`（`LoginGuideTest` 22・`CustomerImportTest` 29。docblock だけなので変わらない）

---

## Task 9: `LoginGuideTest` の注記を本番のキャッシュに合わせる（案 A・動きは変えない）

**Files:**
- Modify: `tests/Feature/Approval/LoginGuideTest.php`（`test_the_token_is_claimed_atomically` の docblock。261-270 行）

利用者の判断（2026-09-28・案 A）: 設計書 §7 で見つけた docblock の誤り（本番のキャッシュを database と書いている。本番は file）を、この作業の別のコミットで直す。

- [ ] **Step 1: docblock を直す**（Edit）

置き換える前:

```php
     *   本番の `database` ドライバは `key` の主キー制約で本当に排他的なので、
     *   「`add` を使っていること」だけを構造で固定する（Bug #41 / #42 と同じ流儀）。
```

置き換えた後:

```php
     *   本番のキャッシュは `file` ドライバで、`FileStore::add()` がファイルの排他ロックを取ってから書くので
     *   本当に排他的（2026-09-25 に本番の `config('cache.default')` で確かめた。`OneTimeAction::claim()` のコメント）。
     *   よって「`add` を使っていること」だけを構造で固定する（Bug #41 / #42 と同じ流儀）。
```

- [ ] **Step 2: 流してコミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit tests/Feature/Approval/LoginGuideTest.php 2>&1 | tail -1 && git add tests/Feature/Approval/LoginGuideTest.php && git commit -m "$(cat <<'EOF'
docs: LoginGuideTest の注記の本番のキャッシュを file に直す

本番は database ドライバでなく file ドライバで、FileStore::add() が排他ロックを取ってから書く
（2026-09-25 に本番で確かめた）。動きは変えない。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

期待: `OK (22 tests, 125 assertions)`

---

## Task 10: 全件テストとコンパイル済みビューの lint

**Files:** なし（確かめるだけ）

- [ ] **Step 1: 全件テスト**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit 2>&1 | tail -3
```

期待: `OK (2431 tests, 16299 assertions)`（着手前 2362 / 15396 から +69 本: 部品 9・テナント 11・賃貸マンション 12・ZEAL 会員 6・工程表 6・周辺ビル 7・シート取込 16・コントローラの走査 2。Task 0 で `13.x` を取り込んでテストが増えたなどで本数が変わったら、その理由と本数を記録する）

- [ ] **Step 2: コンパイル済みビューの lint**（⚠ `view:cache` の成功表示だけでは足りない。Bug #21 / #26 / #30）

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && K="base64:$(php -r 'echo base64_encode(random_bytes(32));')" && APP_KEY="$K" php artisan view:cache 2>&1 | tail -2 && n=0; bad=0; for f in storage/framework/views/*.php; do n=$((n+1)); php -l "$f" >/dev/null 2>&1 || { bad=$((bad+1)); echo "INVALID: $f"; }; done; echo "views=$n invalid=$bad"; APP_KEY="$K" php artisan view:clear 2>&1 | tail -2; git status --porcelain
```

期待: `views=275 invalid=0`（部品の 1 本が増える）／ `status` は何も出さない。

---

## Task 11: 確定の入口で必ず断るカナリア

**Files:** なし（worktree の 6 本のコントローラを一時的に変えて戻す。記録はこの計画の末尾の「実測記録」）

確定の入口 6 か所（1 回限りの鍵を使う行の直前）に「どのテストが来たかを記録して、必ず断る」一時変更を入れ、全件を流す。**入口に来たテストは、どれも赤にならなければおかしい**（入口で断られても緑のままのテストは、確定の経路を測っていない＝鍵に断られても気づけない。設計書 §5.3 の「件数 0 しか見ていないテスト」の型）。設計のとき（実装の前）に同じ一時変更を入れたときは、関係するテスト 165 本のうち 86 本が赤・79 本が緑で、緑はどれもプレビュー・構造・権限だけを見るテストだった。

`SP` はこのセッションの scratchpad（システムプロンプトの「Scratchpad directory」の絶対パス）。

- [ ] **Step 1: 実行役を置く**（`$SP/ids-refuse-canary.py`。この内容そのまま。コミットしない）

```python
#!/usr/bin/env python3
"""ほかの取込の二重送信を止める改修（2026-09-28）の「確定の入口で必ず断る」カナリア。

確定の入口 6 か所（1 回限りの鍵を使う行の直前）に「どのテストが来たかを記録して、必ず断る」一時変更を入れ、
全件テストを流す。入口に来たテストは、どれも赤にならなければおかしい（入口で断られても緑のままのテストは、
確定の経路を測っていない＝鍵に断られても気づけない）。

使い方（作業ツリーが空の worktree で。docs/RULES.md Bug #44 の作法）:
    python3 ids-refuse-canary.py --iso <worktree>

1. 作業ツリーが空であることを確かめる
2. 6 本のコントローラの `OneTimeAction::claimFrom($request, 'import_token')` の行（各 1 か所）の直前に一時変更を入れる
3. git diff --stat で 6 本に当たったことを確かめる
4. 全件テストを --log-junit で流す（入口に来たテストの名前は記録のファイルへ書く）
5. git checkout で戻し、作業ツリーが空に戻ったことを確かめる
6. 「入口に来たのに緑のまま」のテストを出す（0 件が期待）
"""
import argparse, base64, os, subprocess, sys, xml.etree.ElementTree as ET

CONTROLLERS = [
    "app/Http/Controllers/Admin/TenantImportController.php",
    "app/Http/Controllers/Admin/MansionImportController.php",
    "app/Http/Controllers/Admin/ZealMemberImportController.php",
    "app/Http/Controllers/Housing/ScheduleImportController.php",
    "app/Http/Controllers/Zeal/SheetImportController.php",
    "app/Http/Controllers/Tenant/AreaBuildingImportController.php",
]
ANCHOR = "OneTimeAction::claimFrom($request, 'import_token')"


def git(iso, *args):
    return subprocess.run(["git", "-C", iso, *args], capture_output=True, text=True, errors="replace").stdout


def canary_lines(indent, log):
    # 入口に来たテストの名前（PHPUnit のテストの object を呼び出し履歴から探す）を記録して、必ず断る
    return (
        f"{indent}// カナリア（戻す）: 確定の入口で必ず断る\n"
        f"{indent}\\file_put_contents({log!r}, (static function (): string {{ "
        "foreach (\\debug_backtrace(\\DEBUG_BACKTRACE_PROVIDE_OBJECT) as $f) { "
        "if (($f['object'] ?? null) instanceof \\PHPUnit\\Framework\\TestCase) { "
        "return \\get_class($f['object']) . '::' . $f['object']->nameWithDataSet(); } } "
        "return '?'; })() . \"\\n\", \\FILE_APPEND);\n"
        f"{indent}return redirect('/__refuse-canary')->with('error', 'CANARY');\n"
    )


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--iso", required=True)
    args = ap.parse_args()
    iso = os.path.abspath(args.iso)
    log = os.path.join(os.path.dirname(iso), os.path.basename(iso) + "-canary-hits.log")
    junit = os.path.join(os.path.dirname(iso), os.path.basename(iso) + "-canary-junit.xml")

    if git(iso, "status", "--porcelain").strip():
        sys.exit("始める前に作業ツリーが空でない。止める")
    for path in (log, junit):
        if os.path.exists(path):
            os.remove(path)

    try:
        for rel in CONTROLLERS:
            full = os.path.join(iso, rel)
            lines = open(full, encoding="utf-8").read().splitlines(keepends=True)
            hits = [i for i, line in enumerate(lines) if ANCHOR in line]
            if len(hits) != 1:
                sys.exit(f"{rel}: 鍵を使う行が {len(hits)} か所（1 でない）。止める")
            i = hits[0]
            indent = lines[i][: len(lines[i]) - len(lines[i].lstrip())]
            lines.insert(i, canary_lines(indent, log))
            open(full, "w", encoding="utf-8").write("".join(lines))

        stat = git(iso, "diff", "--stat").strip().splitlines()
        print("着弾: " + (stat[-1] if stat else "（無し）"), flush=True)
        if not stat or "6 files changed" not in stat[-1]:
            sys.exit("6 本に当たっていない。止める")

        env = dict(os.environ, APP_KEY="base64:" + base64.b64encode(os.urandom(32)).decode())
        run = subprocess.run(["./vendor/bin/phpunit", "--log-junit", junit], cwd=iso, env=env,
                             capture_output=True, text=True, errors="replace")
        print("phpunit: " + " / ".join(l for l in run.stdout.strip().splitlines()[-2:]), flush=True)
    finally:
        git(iso, "checkout", "--", *CONTROLLERS)
    if git(iso, "status", "--porcelain").strip():
        sys.exit("戻したのに作業ツリーが空でない。止める")

    # JUnit は、このスクリプトが手元で起動した PHPUnit の書いたものだけを読む（外から来た XML は読まない）
    failed, total = set(), 0
    for tc in ET.parse(junit).getroot().iter("testcase"):
        total += 1
        if tc.find("failure") is not None or tc.find("error") is not None:
            failed.add(f"{tc.get('class')}::{tc.get('name')}")
    hits = set(l.strip() for l in open(log, encoding="utf-8")) if os.path.exists(log) else set()

    green_hits = sorted(hits - failed)
    red_misses = sorted(failed - hits)
    print(f"テスト {total} 本 ／ 入口に来た {len(hits)} 本 ／ 赤 {len(failed)} 本")
    print(f"入口に来たのに緑のまま: {len(green_hits)} 本（0 本が期待）")
    for name in green_hits:
        print("   " + name)
    print(f"入口に来ずに赤: {len(red_misses)} 本（0 本が期待。来ていれば記録に名前が残る）")
    for name in red_misses:
        print("   " + name)
    by_class = {}
    for name in hits:
        cls = name.split("::")[0].split("\\")[-1]
        by_class[cls] = by_class.get(cls, 0) + 1
    for cls in sorted(by_class):
        print(f"   {cls}: {by_class[cls]} 本")
    os.remove(junit)
    sys.exit(1 if green_hits or red_misses else 0)


if __name__ == "__main__":
    main()
```

⚠ 読む XML は、この実行役が手元で起動した PHPUnit の `--log-junit` が書いたものだけ（外から来た XML は読まない。`defusedxml` は入っていない）。

- [ ] **Step 2: 流す**（worktree の作業ツリーが空であること。全件 1 回で約 2 分。Bash の `run_in_background` で起動して終わりを待つ。途中で作業ツリーを覗いて判断しない）

```bash
SP=<Scratchpad directory>; python3 "$SP/ids-refuse-canary.py" --iso /Users/masanori/site/manage/.claude/worktrees/import-double-submit > "$SP/ids-refuse-canary.out" 2>&1; echo "exit=$?" >> "$SP/ids-refuse-canary.out"; cat "$SP/ids-refuse-canary.out"; git -C /Users/masanori/site/manage/.claude/worktrees/import-double-submit status --porcelain
```

期待（試作の実測）:

```text
着弾:  6 files changed, 18 insertions(+)
phpunit: ERRORS! / Tests: 2431, Assertions: 15636, Errors: 23, Failures: 113.
テスト 2431 本 ／ 入口に来た 136 本 ／ 赤 136 本
入口に来たのに緑のまま: 0 本（0 本が期待）
入口に来ずに赤: 0 本（0 本が期待。来ていれば記録に名前が残る）
   AreaBuildingImportTest: 40 本
   HousingConstructionStartDateTest: 7 本
   MansionImportDoubleSubmitTest: 10 本
   MansionImportRejectionTest: 1 本
   MansionImportTest: 19 本
   ScheduleImportTest: 14 本
   SheetImportTest: 15 本
   TenantImportDoubleSubmitTest: 9 本
   TenantImportRejectionTest: 1 本
   TenantUnitImportTest: 8 本
   ZealMemberImportControllerTest: 12 本
exit=0
```

最後の `status` は何も出さない（実行役が戻したことの確かめ）。

- **「入口に来たのに緑のまま」が 1 本でも出たら止める**。そのテストは確定を送っているのに、断られても気づかない。狙った文言（成功の帯・件数・`error` の全文）まで見る形に直し、コミットしてから流し直す
- 「入口に来ずに赤」は、入口の前で落ちるテスト（一時変更が別の所を壊した）。理由を調べる

---

## Task 12: 変異テスト（docs/RULES.md Bug #44 / #50 の作法）

**Files:** なし（隔離した worktree の中だけで変え、戻す。記録はこの計画の末尾の「実測記録」）

作法: 先にコミット（Task 11 まで済み）→ 隔離した worktree（`git worktree add --detach`）に vendor を**実体コピー**（`cp -Rc`。symlink は不可＝ Bug #50）→ 最初にカナリア → 変異 1 つごとに、前後で作業ツリーが空・置き換えがそれぞれ決めた数だけ当たる・着弾を確認・`--log-junit` で全件を流す・落ちたテストと**理由の文言**まで記録・戻したあと空を再確認。実行役 `ids-mutate.py` がこの確認を全部行い、1 つでも食い違えば止まる。1 つの変異が複数の置き換え（切り取って別の場所へ貼る＝移す）を持てる。

`SP` はこのセッションの scratchpad（システムプロンプトの「Scratchpad directory」の絶対パス）。

- [ ] **Step 1: 隔離した worktree を 3 つ作る**（名前は用途とコミットで一意にする＝ Bug #50 の衝突を避ける。コミットは `$SP/ids-mut-commit` に控える）

```bash
SP=<Scratchpad directory>; WT=/Users/masanori/site/manage/.claude/worktrees/import-double-submit; C=$(git -C "$WT" rev-parse --short HEAD); echo "$C" > "$SP/ids-mut-commit"; for s in a b c; do git -C "$WT" worktree add --detach "$SP/ids-mut-$C-$s" HEAD && cp -Rc "$WT/vendor" "$SP/ids-mut-$C-$s/vendor"; done; git -C "$WT" worktree list
```

⚠ `vendor` は `.gitignore` 済みなので、コピーしても作業ツリーは空のまま（Step 3 で確かめる）。

- [ ] **Step 2: 実行役を置く**（`$SP/ids-mutate.py`。この内容そのまま。コミットしない）

```python
#!/usr/bin/env python3
"""ほかの取込の二重送信を止める改修（2026-09-28）の変異テストの実行役。

使い方（隔離した worktree の中で。docs/RULES.md Bug #44 / #50 の作法）:
    python3 ids-mutate.py --iso <隔離した worktree> [ID ...]           # ID を省くと全部。全件テストで流す
    python3 ids-mutate.py --iso <…> --narrow [ID ...]                  # 関係するテスト（NARROW）だけで流す（先測り・当て直しの確認用）
    python3 ids-mutate.py --iso <…> --check                            # どの変異も決めた数だけ当たるかだけ見る
    python3 ids-mutate.py --list                                       # 定義の一覧だけ

1 つごとに: 前に作業ツリーが空 → 置き換えがそれぞれ決めた数（既定 1）だけ当たる → git status で着弾を確認 →
phpunit を --log-junit で流す → 落ちたテストと理由の 1 行目を記録 → git checkout で戻す → 後に作業ツリーが空。
どこかで食い違えば止まる（無効な測定を集めない）。
1 つの変異が複数の置き換え（切り取って別の場所へ貼る＝移す）を持てる。置き換えは書いた順に当てる。
"""
import argparse, base64, json, os, subprocess, sys, xml.etree.ElementTree as ET

TC = "app/Http/Controllers/Admin/TenantImportController.php"
MC = "app/Http/Controllers/Admin/MansionImportController.php"
ZC = "app/Http/Controllers/Admin/ZealMemberImportController.php"
SC = "app/Http/Controllers/Housing/ScheduleImportController.php"
HC = "app/Http/Controllers/Zeal/SheetImportController.php"
AC = "app/Http/Controllers/Tenant/AreaBuildingImportController.php"
P = "resources/views/_partials/_submit_once.blade.php"
VT = "resources/views/admin/tenant-import/_preview.blade.php"
VM = "resources/views/admin/mansion-import/_preview.blade.php"
VZ = "resources/views/admin/zeal-member-import/preview.blade.php"
VS = "resources/views/housing/properties/schedule-import.blade.php"
VH = "resources/views/zeal/simulations/sheet-import/preview.blade.php"
VA = "resources/views/tenant/area-buildings/import.blade.php"
PF = "tests/Concerns/ParsesForms.php"
ZS = "tests/Concerns/CreatesZealSimulationSchema.php"

# 関係するテスト（--narrow のとき）。表の「期待」はこれで測った値
NARROW = [
    "tests/Feature/SubmitOnceTest.php",
    "tests/Feature/Admin/TenantImportDoubleSubmitTest.php",
    "tests/Feature/Admin/MansionImportDoubleSubmitTest.php",
    "tests/Feature/Admin/ZealMemberImportControllerTest.php",
    "tests/Feature/Housing/ScheduleImportTest.php",
    "tests/Feature/Housing/HousingConstructionStartDateTest.php",
    "tests/Feature/Tenant/AreaBuildingImportTest.php",
    "tests/Feature/Zeal/SheetImportTest.php",
    "tests/Feature/ImportControllerOneTimeKeyScanTest.php",
    "tests/Feature/Approval/LoginGuideTest.php",
    "tests/Feature/Admin/TenantUnitImportTest.php",
    "tests/Feature/Admin/TenantImportRejectionTest.php",
    "tests/Feature/Admin/MansionImportTest.php",
    "tests/Feature/Admin/MansionImportRejectionTest.php",
    "tests/Feature/Admin/ImportPreviewRenderTest.php",
    "tests/Feature/Admin/ImportValidationFeedbackTest.php",
    "tests/Feature/ImportControllerReturnPathScanTest.php",
    "tests/Feature/ImportControllerValidationRedirectScanTest.php",
    "tests/Feature/ValidationErrorFeedbackTest.php",
    "tests/Feature/AlpineXShowDisplayConflictTest.php",
    "tests/Feature/LayoutMeasuringScriptTest.php",
    "tests/Feature/LayoutScriptStackTest.php",
    "tests/Feature/AjaxErrorFeedbackTest.php",
    "tests/Feature/Zeal/SimulationValidationFeedbackTest.php",
    "tests/Feature/MonthEndOverflowTest.php",
    "tests/Feature/JapaneseValidationMessagesTest.php",
]

# ---- コントローラの鍵のブロック（置き換える前の全文。後ろの空行まで）----
TENANT_CLAIM = (
    "            // 確定は確認画面 1 つにつき 1 回だけ（クラスの docblock）。⚠ CSV を読み直す前に使う\n"
    "            if (! OneTimeAction::claimFrom($request, 'import_token')) {\n"
    "                return redirect()->route('admin.tenant-import', ['tab' => $tab])\n"
    "                    ->with('error', 'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは'\n"
    "                        . self::WHERE_TO_CHECK[$tab] . '確かめられます。取り込み直すときは、CSVをアップロードし直してください。');\n"
    "            }\n"
    "\n"
)
MANSION_CLAIM = TENANT_CLAIM.replace(
    "route('admin.tenant-import', ['tab' => $tab])", "route('admin.mansion-import', ['selected_tab' => $tab])"
)
ZEAL_CLAIM = (
    "        // 確定は確認画面 1 つにつき 1 回だけ（クラスの docblock）。⚠ CSV を読み直す前に使う\n"
    "        if (! OneTimeAction::claimFrom($request, 'import_token')) {\n"
    "            return redirect()->route('admin.zeal.member-import')\n"
    "                ->with('error', 'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「会員管理」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。');\n"
    "        }\n"
    "\n"
)
SCHEDULE_CLAIM = (
    "        // 確定は確認画面 1 つにつき 1 回だけ（クラスの docblock）。⚠ 入力チェックより先に使う\n"
    "        if (! OneTimeAction::claimFrom($request, 'import_token')) {\n"
    "            return redirect()->route('housing.properties.schedule-import.form', $property)\n"
    "                ->with('error', 'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは、この物件の詳細の「工程表」で確かめられます。取り込み直すときは、ファイルを選び直してください。');\n"
    "        }\n"
    "\n"
)
SHEET_CLAIM = (
    "        // 反映は確認画面 1 つにつき 1 回だけ（クラスの docblock）。⚠ 指紋より先に使う\n"
    "        if (! OneTimeAction::claimFrom($request, 'import_token')) {\n"
    "            return redirect()\n"
    "                ->route('zeal.simulations.show', $simulation)\n"
    "                ->with('error', 'この確認画面からは反映できません（すでに送信したか、画面が古くなっています）。反映されたかは、この試算表で確かめられます。反映し直すときは、「本部 Sheet を取り込む」からもう一度プレビューしてください。');\n"
    "        }\n"
    "\n"
)
AREA_CLAIM = (
    "        // 取込は取込の画面 1 つにつき 1 回だけ（クラスの docblock）。⚠ 入力チェックより先に使う\n"
    "        if (! OneTimeAction::claimFrom($request, 'import_token')) {\n"
    "            return redirect()->route('tenant.area-buildings.import')\n"
    "                ->with('error', 'この取込画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「周辺ビル調査」で確かめられます。取り込み直すときは、ファイルを選び直してください。');\n"
    "        }\n"
    "\n"
)
SHEET_DIGEST = (
    "        // 確認画面で見せた内容と同じものだけを書く（クラスの docblock）。\n"
    "        // ⚠ 配列が送られると hash_equals() が TypeError（500）になるので、文字列かを先に見る\n"
    "        $given = $request->input('plan_digest');\n"
    "        if (! is_string($given) || ! hash_equals($this->planDigest($simulation, $yearMonth, $applyPlan), $given)) {\n"
    "            return redirect()\n"
    "                ->route('zeal.simulations.show', $simulation)\n"
    "                ->with('error', 'プレビューのあとで反映する内容が変わりました（本部 Sheet か試算表の値が変わっています）。もう一度プレビューしてください。');\n"
    "        }\n"
    "\n"
)
ZEAL_SUCCESS = "        return redirect()\n            ->route('admin.zeal.member-import')\n            ->with('success', "
SCHEDULE_COUNT = "        $count = count($sanitized['rows']);\n"
SCHEDULE_DECODE = "        $decoded = json_decode($validated['rows_json'], true);\n"
FIXED = "'fixed-token-for-mutation',\n"
STATUS_TEXT = "x-text=\"submitting ? '取り込んでいます…' : ''\""
BUTTON_OPEN = '<button type="submit" :disabled="submitting"\n'

# (ID, [(ファイル, 置き換える前, 置き換えた後[, 当たる数]), ...])。当たる数の既定は 1
MUTATIONS = [
    # ---- カナリア（隔離が効いていれば、コピー側のコードが読まれて赤になる）----
    ("C0", [(TC, "\n        return view('admin.tenant-import.index', [\n", "\n        return view('admin.tenant-import.index-canary', [\n")]),
    # ---- サーバの鍵（コントローラ）----
    ("K01", [(TC, TENANT_CLAIM, "")]),
    ("K02", [(MC, MANSION_CLAIM, "")]),
    ("K03", [(ZC, ZEAL_CLAIM, "")]),
    ("K04", [(SC, SCHEDULE_CLAIM, "")]),
    ("K05", [(HC, SHEET_CLAIM, "")]),
    ("K06", [(AC, AREA_CLAIM, "")]),
    ("K07", [(TC, "                'importToken'  => OneTimeAction::issue(),\n", "                'importToken'  => " + FIXED, 5)]),
    ("K08", [(MC, "                'importToken' => OneTimeAction::issue(),\n", "                'importToken' => " + FIXED, 6)]),
    ("K09", [(ZC, "            'importToken' => OneTimeAction::issue(),\n", "            'importToken' => " + FIXED)]),
    ("K10", [(SC, "            'importToken' => OneTimeAction::issue(),\n", "            'importToken' => " + FIXED)]),
    ("K11", [(HC, "            'importToken'     => OneTimeAction::issue(),\n", "            'importToken'     => " + FIXED)]),
    ("K12", [(AC, "            'importToken' => OneTimeAction::issue(),\n", "            'importToken' => " + FIXED)]),
    ("K13", [(TC, "        'past-contract' => '「契約一覧」でステータスを「解約済み」にして',\n", "")]),
    ("K14", [(MC, "        'parking_contract' => '「駐車場契約一覧」で',\n", "")]),
    ("K15", [(TC, "route('admin.tenant-import', ['tab' => $tab])\n                    ->with('error', 'この確認画面から",
                  "route('admin.tenant-import')\n                    ->with('error', 'この確認画面から")]),
    ("K16", [(MC, "route('admin.mansion-import', ['selected_tab' => $tab])\n                    ->with('error', 'この確認画面から",
                  "route('admin.mansion-import', ['tab' => $tab])\n                    ->with('error', 'この確認画面から")]),
    ("K17", [(TC, "self::WHERE_TO_CHECK[$tab] . '確かめられます。取り込み直すときは、CSVをアップロードし直してください。'",
                  "self::WHERE_TO_CHECK[$tab] . '確かめられます。'")]),
    ("K18", [(ZC, ZEAL_CLAIM, ""), (ZC, ZEAL_SUCCESS, ZEAL_CLAIM + ZEAL_SUCCESS)]),                  # 書いたあとへ
    ("K19", [(SC, SCHEDULE_CLAIM, ""), (SC, SCHEDULE_COUNT, SCHEDULE_CLAIM + SCHEDULE_COUNT)]),    # 書いたあとへ
    ("K20", [(SC, SCHEDULE_CLAIM, ""), (SC, SCHEDULE_DECODE, SCHEDULE_CLAIM + SCHEDULE_DECODE)]),  # 入力チェックのあとへ（等価の見込み）
    # ---- シート取込の指紋 ----
    ("D01", [(HC, SHEET_CLAIM, ""), (HC, SHEET_DIGEST, SHEET_DIGEST + SHEET_CLAIM)]),              # 指紋を鍵より先に比べる
    ("D02", [(HC, "        if (! is_string($given) || ! hash_equals(", "        if (! hash_equals(")]),
    ("D03", [(HC, SHEET_DIGEST, "")]),
    ("D04", [(HC, "$writes[] = [$row['category_id'], $row['new_amount']];", "$writes[] = [$row['category_id']];")]),
    ("D05", [(HC, "$writes[] = [$row['category_id'], $row['new_amount']];", "$writes[] = [$row['category_id'], $row['new_amount'], $row['current_amount']];")]),
    ("D06", [(HC, "            if ($row['will_update']) {\n                $writes[] = [$row['category_id'], $row['new_amount']];\n            }\n",
                  "            $writes[] = [$row['category_id'], $row['new_amount']];\n")]),
    ("D07", [(HC, "json_encode([$simulation->id, $yearMonth, $writes])", "json_encode([$writes])")]),
    # ---- 画面の部品（_partials/_submit_once）----
    ("J01", [(P, "                        event.preventDefault();\n", "")]),
    ("J02", [(P, "                    this.submitting = true;\n", "")]),
    ("J03", [(P, "                    this.submitting = false;\n", "")]),
    ("J04", [(P, "                submitting: false,\n", "                submitting: true,\n")]),
    ("J05", [(P, "                    this.submitting = false;\n",
                 "                    if (event.persisted) {\n                        this.submitting = false;\n                    }\n")]),
    ("J06", [(P, "if (reloadOnReturn && (event.persisted ? this.submitting : submitOnceNavigationType() === 'back_forward')) {",
                 "if (reloadOnReturn) {")]),
    ("J07", [(P, "submitOnceNavigationType() === 'back_forward'", "false")]),
    ("J08", [(P, "event.persisted ? this.submitting :", "event.persisted ? true :")]),
    ("J09", [(P, "            var reloadOnReturn = !!(options && options.reloadOnReturn);\n", "            var reloadOnReturn = false;\n")]),
    ("J10", [(P, "@once\n", ""), (P, "@endonce\n", "")]),
    ("J11", [(P, "    @push('scripts')\n", ""), (P, "    @endpush\n", "")]),
    # ---- 画面（テナントの確認画面。形を 1 つずつ）----
    ("UT01", [(VT, 'name="import_token"', 'name="guide_token"')]),
    ("UT02", [(VT, "            @include('_partials._submit_once')\n", "")]),
    ("UT03", [(VT, ' x-on:submit="onSubmit($event)"', "")]),
    ("UT04", [(VT, 'x-on:pageshow.window="onPageShow($event)"', 'x-on:pageshow="onPageShow($event)"')]),
    ("UT05", [(VT, BUTTON_OPEN, '<button type="submit"\n')]),
    ("UT06", [(VT, 'class="cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"', 'class="cursor-pointer disabled:cursor-not-allowed"')]),
    ("UT07", [(VT, 'font-weight: 600; border: none;">', 'font-weight: 600; border: none; cursor: pointer;">')]),
    ("UT08", [(VT, BUTTON_OPEN, '<button type="submit" name="go" value="1" :disabled="submitting"\n')]),
    ("UT09", [(VT, '<span role="status" x-text=', "<span x-text=")]),
    ("UT10", [(VT, STATUS_TEXT, "x-text=\"submitting ? '送信中…' : ''\"")]),
    ("UT11", [(VT, 'style="display: inline-block; margin-left: 12px;', 'style="margin-left: 12px;')]),
    ("UT12", [(VT, 'x-data="submitOnce()"', 'x-data="{}"')]),
    # ---- 画面（ほかの 5 つ。どの画面のテストも部品の形を見ていること）----
    ("UM01", [(VM, 'name="import_token"', 'name="guide_token"')]),
    ("UM02", [(VM, "            @include('_partials._submit_once')\n", "")]),
    ("UM03", [(VM, BUTTON_OPEN, '<button type="submit"\n')]),
    ("UZ01", [(VZ, 'name="import_token"', 'name="guide_token"')]),
    ("UZ02", [(VZ, "    @include('_partials._submit_once')\n", "")]),
    ("UZ03", [(VZ, ' x-on:pageshow.window="onPageShow($event)"', "")]),
    ("UZ04", [(VZ, "display: flex; flex-wrap: wrap; gap: 12px;", "display: flex; gap: 12px;")]),
    ("US01", [(VS, 'name="import_token"', 'name="guide_token"')]),
    ("US02", [(VS, "            @include('_partials._submit_once')\n", "")]),
    ("US03", [(VS, "transition-colors cursor-pointer disabled:cursor-not-allowed disabled:opacity-60", "transition-colors disabled:opacity-60")]),
    ("UH01", [(VH, 'name="import_token"', 'name="guide_token"')]),
    ("UH02", [(VH, '                <input type="hidden" name="plan_digest" value="{{ $planDigest }}">\n', "")]),
    ("UH03", [(VH, "            @include('_partials._submit_once')\n", "")]),
    ("UH04", [(VH, "x-text=\"submitting ? '反映しています…' : ''\"", STATUS_TEXT)]),
    ("UH05", [(VH, 'background:#7c3aed;">', 'background:#7c3aed; cursor:pointer;">')]),
    ("UA01", [(VA, 'name="import_token"', 'name="guide_token"')]),
    ("UA02", [(VA, "        @include('_partials._submit_once')\n", "")]),
    ("UA03", [(VA, 'x-data="submitOnce({ reloadOnReturn: true })"', 'x-data="submitOnce()"')]),
    ("UA04", [(VA, ':disabled="submitting || submitBlockedReason() !== null"', ':disabled="submitBlockedReason() !== null"')]),
    # ---- テストの道具 ----
    ("T01", [(PF, "    protected function htmlAttr(string $tag, string $name): ?string\n", "    private function htmlAttr(string $tag, string $name): ?string\n")]),
    ("T02", [(ZS, "            $t->unique(['simulation_id', 'category_id', 'year_month'], 'uq_zeal_sim_val');\n", "")]),
]


def git(iso, *args):
    return subprocess.run(["git", "-C", iso, *args], capture_output=True, text=True, errors="replace").stdout


def run_phpunit(iso, junit, targets):
    # JUnit は、このスクリプトが手元で起動した PHPUnit の書いたものだけを読む（外から来た XML は読まない）
    env = dict(os.environ, APP_KEY="base64:" + base64.b64encode(os.urandom(32)).decode())
    subprocess.run(["./vendor/bin/phpunit", "--log-junit", junit] + targets, cwd=iso, env=env,
                   capture_output=True, text=True, errors="replace")
    if not os.path.exists(junit):
        return None
    failures = []
    for tc in ET.parse(junit).getroot().iter("testcase"):
        for kind in ("failure", "error"):
            el = tc.find(kind)
            if el is not None:
                lines = [l for l in (el.text or "").strip().splitlines() if l.strip()]
                reason = next((l for l in lines if not l.startswith("Tests\\")), lines[0] if lines else "")
                failures.append({"test": f"{tc.get('class', '').split(chr(92))[-1]}::{tc.get('name')}", "reason": reason[:200]})
    os.remove(junit)
    return failures


def edits_of(mid):
    for m, edits in MUTATIONS:
        if m == mid:
            return edits
    sys.exit(f"{mid}: 定義に無い")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--iso")
    ap.add_argument("--list", action="store_true")
    ap.add_argument("--check", action="store_true")
    ap.add_argument("--narrow", action="store_true")
    ap.add_argument("ids", nargs="*")
    args = ap.parse_args()

    if args.list:
        for mid, edits in MUTATIONS:
            print(f"{mid}\t" + ", ".join(sorted({e[0] for e in edits})))
        return

    iso = os.path.abspath(args.iso)
    if args.check:
        bad = 0
        for mid, edits in MUTATIONS:
            for e in edits:
                path, old = e[0], e[1]
                want = e[3] if len(e) > 3 else 1
                n = open(os.path.join(iso, path), encoding="utf-8").read().count(old)
                if n != want:
                    bad += 1
                    print(f"{mid}: {path} の置き換えが {n} か所（{want} でない）")
        print(f"変異 {len(MUTATIONS)} 通り・当たらないもの {bad} 件")
        sys.exit(1 if bad else 0)

    targets = NARROW if args.narrow else []
    out = os.path.join(iso, "..", os.path.basename(iso) + ("-narrow" if args.narrow else "") + "-results.jsonl")
    ids = args.ids or [mid for mid, _ in MUTATIONS]
    for mid in ids:
        edits = edits_of(mid)
        if git(iso, "status", "--porcelain").strip():
            sys.exit(f"{mid}: 始める前に作業ツリーが空でない（前の変異の残骸）。止める")
        paths = sorted({e[0] for e in edits})
        try:
            for e in edits:
                path, old, new = e[0], e[1], e[2]
                want = e[3] if len(e) > 3 else 1
                full = os.path.join(iso, path)
                src = open(full, encoding="utf-8").read()
                n = src.count(old)
                if n != want:
                    sys.exit(f"{mid}: {path} の置き換えが {n} か所（{want} でない）。止める")
                open(full, "w", encoding="utf-8").write(src.replace(old, new))
            landed = git(iso, "status", "--porcelain").strip()
            stat = git(iso, "diff", "--stat").strip().splitlines()
            if not landed:
                sys.exit(f"{mid}: 置き換えたのに作業ツリーが空（当たっていない）。止める")
            junit = os.path.join(iso, "..", f"junit-{os.path.basename(iso)}-{mid}.xml")
            failures = run_phpunit(iso, junit, targets)
        finally:
            git(iso, "checkout", "--", *paths)
        if git(iso, "status", "--porcelain").strip():
            sys.exit(f"{mid}: 戻したのに作業ツリーが空でない。止める")
        record = {"id": mid, "files": paths, "diff": stat[-1] if stat else landed, "failures": failures}
        with open(out, "a", encoding="utf-8") as f:
            f.write(json.dumps(record, ensure_ascii=False) + "\n")
        count = "junit なし（phpunit が起動しなかった）" if failures is None else f"{len(failures)} 件"
        print(f"== {mid} {', '.join(paths)}  落ちたテスト {count}", flush=True)
        for fl in (failures or [])[:40]:
            print(f"   {fl['test']} :: {fl['reason']}", flush=True)
        if failures and len(failures) > 40:
            print(f"   …ほか {len(failures) - 40} 件（記録の jsonl を見る）", flush=True)
    print(f"記録: {out}")


if __name__ == "__main__":
    main()
```

⚠ 読む XML は、この実行役が手元で起動した PHPUnit の `--log-junit` が書いたものだけ（外から来た XML は読まない。`defusedxml` は入っていない）。
⚠ `--narrow` は関係するテスト 26 本だけで流す（下の表の「期待」はこれで測った）。この Task は**全件**で流す（`--narrow` を付けない）。

- [ ] **Step 3: どの変異も決めた数だけ当たることと、作業ツリーが空であることを確かめる**

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/ids-mut-commit"); for s in a b c; do git -C "$SP/ids-mut-$C-$s" status --porcelain | head -3; done; python3 "$SP/ids-mutate.py" --iso "$SP/ids-mut-$C-a" --check
```

期待: `status` はどれも何も出さない・`変異 72 通り・当たらないもの 0 件`（カナリア C0 を含めて 72 通り＝変異 71 通り＋カナリア）

- [ ] **Step 4: カナリアを 3 つのコピーで通す**（隔離が効いていれば、コピー側のコードが読まれて赤になる。3 つを Bash の `run_in_background` で同時に起動し、終わりの知らせを待つ。5 分ほど）

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/ids-mut-commit"); python3 "$SP/ids-mutate.py" --iso "$SP/ids-mut-$C-a" C0 > "$SP/ids-mut-$C-a-canary.log" 2>&1
```

（`-a` を `-b`・`-c` に替えた 2 本も同時に起動する）

期待: 3 つとも `== C0 app/Http/Controllers/Admin/TenantImportController.php  落ちたテスト 12 件` — `TenantImportDoubleSubmitTest` の 8 本（2 回送る 5・使えない鍵 3。どれも `戻り先の画面の赤帯に、断りの文言が出ていない`）・`TenantImportRejectionTest` の 3 本（`Expected response status code [200] but received 500.`）・`ImportValidationFeedbackTest` の「テナントCSV」（`/admin/tenant-import/property に不正なファイルを投げたのに、差し戻し先 /admin/tenant-import?tab=property に理由が出ていない`）。試作で全件で流した値。**どれか 1 つでも赤にならなければ止める**（コピーでなく元の worktree のコードが読まれている＝ Bug #50）。

- [ ] **Step 5: 変異を流す**（下の 3 つを Bash の `run_in_background` で**同時に**起動し、3 つとも終わりの知らせを待つ。途中で作業ツリーやログを覗いて判断しない＝変異の途中を拾うと偽の赤になる。それぞれ全件 23〜25 回で 60〜80 分）

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/ids-mut-commit"); python3 "$SP/ids-mutate.py" --iso "$SP/ids-mut-$C-a" K01 K02 K03 K04 K05 K06 K07 K08 K09 K10 K11 K12 K13 K14 K15 K16 K17 K18 K19 K20 D01 D02 D03 > "$SP/ids-mut-$C-a.log" 2>&1
```

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/ids-mut-commit"); python3 "$SP/ids-mutate.py" --iso "$SP/ids-mut-$C-b" D04 D05 D06 D07 J01 J02 J03 J04 J05 J06 J07 J08 J09 J10 J11 UT01 UT02 UT03 UT04 UT05 UT06 UT07 UT08 > "$SP/ids-mut-$C-b.log" 2>&1
```

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/ids-mut-commit"); python3 "$SP/ids-mutate.py" --iso "$SP/ids-mut-$C-c" UT09 UT10 UT11 UT12 UM01 UM02 UM03 UZ01 UZ02 UZ03 UZ04 US01 US02 US03 UH01 UH02 UH03 UH04 UH05 UA01 UA02 UA03 UA04 T01 T02 > "$SP/ids-mut-$C-c.log" 2>&1
```

- [ ] **Step 6: 期待と突き合わせる**（下の表。**落ちたテストの集合と理由の文言まで**。記録は `$SP/ids-mut-$C-{a,b,c}-results.jsonl`）
  - 表の「期待」は、試作で関係するテスト 26 本（実行役の `NARROW`）に絞って測った値。全件で流して表に無いテストが落ちたら、理由を調べる
  - 期待と違ったら（緑のまま・別のテストが落ちた・理由が違う）、理由を調べる。テストの穴ならテストを足してコミットし、その変異を当て直す。当て直す前に、隔離した worktree を新しいコミットへ進める（名前は控えの `C` のまま）:

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/ids-mut-commit"); NEW=$(git -C /Users/masanori/site/manage/.claude/worktrees/import-double-submit rev-parse HEAD); for s in a b c; do git -C "$SP/ids-mut-$C-$s" status --porcelain && git -C "$SP/ids-mut-$C-$s" checkout --detach "$NEW"; done
```

  - 1 つだけ当て直すときは `--narrow` で流すテストを絞れる（例 `python3 "$SP/ids-mutate.py" --iso "$SP/ids-mut-$C-a" --narrow K18`）。表の記録は全件で取り直す

**カナリア:**

| ID | 変異 | 期待（落ちるテスト・理由の 1 行目）|
|---|---|---|
| C0 | テナントの取込の画面のビュー名を無いものに（カナリア） | 12 本: TenantImportDoubleSubmitTest::test_sending_the_same_confirmation_twice_imports_once ×5 — `戻り先の画面の赤帯に、断りの文言が出ていない`<br>TenantImportDoubleSubmitTest::test_a_confirmation_without_a_usable_token_imports_nothing ×3 — `戻り先の画面の赤帯に、断りの文言が出ていない`<br>TenantImportRejectionTest::test_a_failed_reupload_from_another_tab_on_the_preview_goes_back_to_that_tab ×2 — `Expected response status code [200] but received 500.`<br>TenantImportRejectionTest::test_a_failed_import_after_confirming_goes_back_to_the_same_tab — `Expected response status code [200] but received 500.`<br>ImportValidationFeedbackTest::test_an_invalid_file_shows_the_reason_on_screen — `/admin/tenant-import/property に不正なファイルを投げたのに、差し戻し先 /admin/tenant-import?tab=property に理由が出ていない` |

**サーバの鍵（コントローラ）:**

| ID | 変異 | 期待（落ちるテスト・理由の 1 行目）|
|---|---|---|
| K01 | テナントの鍵を使わない（`loadCsv()` の鍵のブロックを消す） | 10 本: TenantImportDoubleSubmitTest::test_sending_the_same_confirmation_twice_imports_once ×5 — `断りの文言（error のフラッシュ）が違う`（executeProperty・executeUnit・executeCustomer） ／ `2 回目の送信で書き込みが走った`（executeContract・executePastContract）<br>TenantImportDoubleSubmitTest::test_a_confirmation_without_a_usable_token_imports_nothing ×3 — `鍵が使えないのに書き込みが走った`<br>ImportControllerOneTimeKeyScanTest::test_every_import_controller_claims_a_one_time_key — `確定で 1 回限りの鍵を使っていない取込がある:`<br>LoginGuideTest::test_nothing_outside_one_time_action_calls_the_raw_claim — `claimFrom() の呼び出しが減っている（決裁の store / resetPassword / execute / reissue / reissueBulk の 5 箇所と、取込の確定 7 箇所（顧客・テナント…` |
| K02 | 賃貸マンションの鍵を使わない | 11 本: MansionImportDoubleSubmitTest::test_sending_the_same_confirmation_twice_imports_once ×6 — `断りの文言（error のフラッシュ）が違う`（executeProperty・executeRoom・executeParking・executeTenant） ／ `2 回目の送信で書き込みが走った`（executeRoomContract・executeParkingContract）<br>MansionImportDoubleSubmitTest::test_a_confirmation_without_a_usable_token_imports_nothing ×3 — `鍵が使えないのに書き込みが走った`<br>ImportControllerOneTimeKeyScanTest::test_every_import_controller_claims_a_one_time_key — `確定で 1 回限りの鍵を使っていない取込がある:`<br>LoginGuideTest::test_nothing_outside_one_time_action_calls_the_raw_claim — `claimFrom() の呼び出しが減っている（決裁の store / resetPassword / execute / reissue / reissueBulk の 5 箇所と、取込の確定 7 箇所（顧客・テナント…` |
| K03 | ZEAL 会員の鍵を使わない | 6 本: ZealMemberImportControllerTest::test_sending_the_same_confirmation_twice_imports_once — `断りの文言（error のフラッシュ）が違う`<br>ZealMemberImportControllerTest::test_a_confirmation_without_a_usable_token_imports_nothing ×3 — `鍵が使えないのに書き込みが走った`<br>ImportControllerOneTimeKeyScanTest::test_every_import_controller_claims_a_one_time_key — `確定で 1 回限りの鍵を使っていない取込がある:`<br>LoginGuideTest::test_nothing_outside_one_time_action_calls_the_raw_claim — `claimFrom() の呼び出しが減っている（決裁の store / resetPassword / execute / reissue / reissueBulk の 5 箇所と、取込の確定 7 箇所（顧客・テナント…` |
| K04 | 工程表の鍵を使わない | 6 本: ScheduleImportTest::test_sending_the_same_confirmation_twice_imports_once — `2 回目の送信で書き込みが走った（工程の入れ替え）`<br>ScheduleImportTest::test_a_confirmation_without_a_usable_token_imports_nothing ×3 — `鍵が使えないのに書き込みが走った`<br>ImportControllerOneTimeKeyScanTest::test_every_import_controller_claims_a_one_time_key — `確定で 1 回限りの鍵を使っていない取込がある:`<br>LoginGuideTest::test_nothing_outside_one_time_action_calls_the_raw_claim — `claimFrom() の呼び出しが減っている（決裁の store / resetPassword / execute / reissue / reissueBulk の 5 箇所と、取込の確定 7 箇所（顧客・テナント…` |
| K05 | シート取込の鍵を使わない | 6 本: SheetImportTest::test_sending_the_same_confirmation_twice_applies_once — `断りの文言（error のフラッシュ）が違う`<br>SheetImportTest::test_a_confirmation_without_a_usable_token_applies_nothing ×3 — `鍵が使えないのに書き込みが走った`<br>ImportControllerOneTimeKeyScanTest::test_every_import_controller_claims_a_one_time_key — `確定で 1 回限りの鍵を使っていない取込がある:`<br>LoginGuideTest::test_nothing_outside_one_time_action_calls_the_raw_claim — `claimFrom() の呼び出しが減っている（決裁の store / resetPassword / execute / reissue / reissueBulk の 5 箇所と、取込の確定 7 箇所（顧客・テナント…` |
| K06 | 周辺ビルの鍵を使わない | 7 本: AreaBuildingImportTest::test_sending_the_same_screen_twice_imports_once ×2 — `断ったあとの戻り先が違う`（ビル＋調査） ／ `2 回目の送信で書き込みが走った`（テナント明細）<br>AreaBuildingImportTest::test_a_submission_without_a_usable_token_imports_nothing ×3 — `鍵が使えないのに書き込みが走った`<br>ImportControllerOneTimeKeyScanTest::test_every_import_controller_claims_a_one_time_key — `確定で 1 回限りの鍵を使っていない取込がある:`<br>LoginGuideTest::test_nothing_outside_one_time_action_calls_the_raw_claim — `claimFrom() の呼び出しが減っている（決裁の store / resetPassword / execute / reissue / reissueBulk の 5 箇所と、取込の確定 7 箇所（顧客・テナント…` |
| K07 | テナントの 5 タブの鍵を固定値に | 2 本: TenantImportDoubleSubmitTest::test_uploading_the_same_file_again_issues_a_new_token — `プレビューごとに鍵が変わっていない`<br>TenantUnitImportTest::test_restoring_the_unit_by_the_unit_import_lets_the_contract_import_through — `Failed asserting that 0 is identical to 1.` |
| K08 | 賃貸マンションの 6 タブの鍵を固定値に | 15 本: MansionImportDoubleSubmitTest::test_uploading_the_same_file_again_issues_a_new_token — `プレビューごとに鍵が変わっていない`<br>MansionImportTest::test_property_codes_continue_from_the_existing_maximum — `Failed asserting that two arrays are identical.`<br>MansionImportTest::test_an_existing_name_is_skipped_not_errored — `Failed asserting that null matches expected '物件インポート完了: 1件を登録しました'.`<br>MansionImportTest::test_room_round_trip_creates_the_row — `Failed asserting that null matches expected '部屋インポート完了: 1件を登録しました'.`<br>MansionImportTest::test_a_room_number_already_in_the_database_is_an_error_row — `Failed asserting that '<!DOCTYPE html>…`<br>MansionImportTest::test_total_units_is_recalculated_after_importing_rooms — `Failed asserting that 99 is identical to 2.`<br>MansionImportTest::test_parking_round_trip_creates_the_row — `Failed asserting that null matches expected '駐車場インポート完了: 1件を登録しました'.`<br>MansionImportTest::test_the_roof_flag_accepts_common_spellings — `Failed asserting that two arrays are identical.`<br>MansionImportTest::test_an_active_room_contract_marks_the_room_occupied — `プレビュー画面に「インポート実行」ボタンが無い（tab=room-contract）`<br>MansionImportTest::test_a_terminated_room_contract_leaves_the_room_alone — `プレビュー画面に「インポート実行」ボタンが無い（tab=room-contract）`<br>MansionImportTest::test_an_impossible_contract_date_drops_only_that_row — `Failed asserting that '<!DOCTYPE html>…`<br>MansionImportTest::test_a_second_active_contract_warns_but_still_imports — `プレビュー画面に「インポート実行」ボタンが無い（tab=room-contract）`<br>MansionImportTest::test_an_active_parking_contract_marks_the_parking_occupied — `プレビュー画面に「インポート実行」ボタンが無い（tab=parking-contract）`<br>MansionImportTest::test_a_linked_room_number_attaches_the_active_room_contract — `プレビュー画面に「インポート実行」ボタンが無い（tab=room-contract）`<br>MansionImportTest::test_a_terminated_parking_contract_leaves_the_parking_vacant — `プレビュー画面に「インポート実行」ボタンが無い（tab=parking-contract）` |
| K09 | ZEAL 会員の鍵を固定値に | 1 本: ZealMemberImportControllerTest::test_uploading_the_same_file_again_issues_a_new_token — `プレビューごとに鍵が変わっていない` |
| K10 | 工程表の鍵を固定値に | 1 本: ScheduleImportTest::test_choosing_the_same_file_again_issues_a_new_token — `プレビューごとに鍵が変わっていない` |
| K11 | シート取込の鍵を固定値に | 2 本: SheetImportTest::test_previewing_again_issues_a_new_token — `プレビューごとに鍵が変わっていない`<br>SheetImportTest::test_applying_the_same_month_from_another_tab_turns_the_later_confirmation_back — `断りの文言（error のフラッシュ）が違う` |
| K12 | 周辺ビルの鍵を固定値に | 3 本: AreaBuildingImportTest::test_repeated_tenant_import_reports_the_current_total — `Failed asserting that 1 is identical to 2.`<br>AreaBuildingImportTest::test_non_scalar_cell_values_are_rejected_without_crashing — `Failed asserting that 0 is identical to 1.`<br>AreaBuildingImportTest::test_opening_the_import_screen_again_issues_a_new_token — `取込の画面を開くたびに鍵が変わっていない` |
| K13 | テナントの断りの表から過去契約（`past-contract`）を抜く | 1 本: TenantImportDoubleSubmitTest::test_sending_the_same_confirmation_twice_imports_once — `断りが転送になっていない（ErrorException: Undefined array key "past-contract"）` |
| K14 | 賃貸マンションの断りの表から駐車場契約（`parking_contract`）を抜く | 1 本: MansionImportDoubleSubmitTest::test_sending_the_same_confirmation_twice_imports_once — `断りが転送になっていない（ErrorException: Undefined array key "parking_contract"）` |
| K15 | テナントの断りの戻り先からタブを落とす | 8 本: TenantImportDoubleSubmitTest::test_sending_the_same_confirmation_twice_imports_once ×5 — `断ったあとの戻り先が違う`<br>TenantImportDoubleSubmitTest::test_a_confirmation_without_a_usable_token_imports_nothing ×3 — `断ったあとの戻り先が違う` |
| K16 | 賃貸マンションの戻り先のクエリを `tab` に | 9 本: MansionImportDoubleSubmitTest::test_sending_the_same_confirmation_twice_imports_once ×6 — `断ったあとの戻り先が違う`<br>MansionImportDoubleSubmitTest::test_a_confirmation_without_a_usable_token_imports_nothing ×3 — `断ったあとの戻り先が違う` |
| K17 | テナントの断りの文言の最後の文を消す | 8 本: TenantImportDoubleSubmitTest::test_sending_the_same_confirmation_twice_imports_once ×5 — `断りの文言（error のフラッシュ）が違う`<br>TenantImportDoubleSubmitTest::test_a_confirmation_without_a_usable_token_imports_nothing ×3 — `断りの文言（error のフラッシュ）が違う` |
| K18 | ZEAL 会員の鍵を書き込みのあと（完了の転送の直前）へ | 3 本: ZealMemberImportControllerTest::test_a_confirmation_without_a_usable_token_imports_nothing ×3 — `鍵が使えないのに書き込みが走った` — ⚠ 2 回送るテストは緑のまま（2 回目は全員が重複で飛ばされ書き込みが 0）。鍵が書き込みより前にあることは「使えない鍵」の 3 本が固定する |
| K19 | 工程表の鍵を書き込みのあとへ | 4 本: ScheduleImportTest::test_sending_the_same_confirmation_twice_imports_once — `2 回目の送信で書き込みが走った（工程の入れ替え）`<br>ScheduleImportTest::test_a_confirmation_without_a_usable_token_imports_nothing ×3 — `鍵が使えないのに書き込みが走った` |
| K20 | 工程表の鍵を入力チェックのあとへ | **0 本** — **等価**。2 回目も同じ行が入力チェックを通って鍵に着くので、断りは同じ |

**シート取込の指紋（`app/Http/Controllers/Zeal/SheetImportController.php`）:**

| ID | 変異 | 期待（落ちるテスト・理由の 1 行目）|
|---|---|---|
| D01 | シート取込で指紋を鍵より先に比べる（鍵を指紋のあとへ） | 1 本: SheetImportTest::test_sending_the_same_confirmation_twice_applies_once — `断りの文言（error のフラッシュ）が違う` |
| D02 | 指紋の `is_string()` を外す | 3 本: SheetImportTest::test_a_confirmation_without_a_usable_digest_applies_nothing ×3 — `断りが転送になっていない（TypeError: hash_equals(): Argument #2 ($user_string) must be of type string, null given）`（指紋が無い・指紋が空） ／ `断りが転送になっていない（TypeError: hash_equals(): Argument #2 ($user_string) must be of type string, array given）`（指紋が配列） |
| D03 | 指紋を比べない | 7 本: SheetImportTest::test_a_changed_amount_in_the_sheet_turns_the_confirmation_back — `断るのに書き込みが走った`<br>SheetImportTest::test_a_cell_that_now_needs_writing_turns_the_confirmation_back — `断るのに書き込みが走った`<br>SheetImportTest::test_a_cell_that_no_longer_needs_writing_turns_the_confirmation_back — `断るのに書き込みが走った`<br>SheetImportTest::test_applying_the_same_month_from_another_tab_turns_the_later_confirmation_back — `断るのに書き込みが走った`<br>SheetImportTest::test_a_confirmation_without_a_usable_digest_applies_nothing ×3 — `断るのに書き込みが走った` |
| D04 | 指紋から金額（`new_amount`）を抜く | 1 本: SheetImportTest::test_a_changed_amount_in_the_sheet_turns_the_confirmation_back — `断るのに書き込みが走った` |
| D05 | 指紋にいまの値（`current_amount`）を入れる | 1 本: SheetImportTest::test_a_changed_current_value_of_a_written_cell_still_applies — `プレビューで見せた値が書かれていない` |
| D06 | 指紋に書かない行も入れる（`will_update` で絞らない） | 3 本: SheetImportTest::test_a_cell_that_now_needs_writing_turns_the_confirmation_back — `断るのに書き込みが走った`<br>SheetImportTest::test_a_cell_that_no_longer_needs_writing_turns_the_confirmation_back — `断るのに書き込みが走った`<br>SheetImportTest::test_applying_the_same_month_from_another_tab_turns_the_later_confirmation_back — `断るのに書き込みが走った` |
| D07 | 指紋から試算表の id と月を抜く | **0 本** — **等価**。月が違えば書く行も変わるので、試算表の id と月は念のため（テストはどちらも 1 つ） |

**二度押し止めの部品（`resources/views/_partials/_submit_once.blade.php`）:** すべて `SubmitOnceTest`

| ID | 変異 | 期待（落ちるテスト・理由の 1 行目）|
|---|---|---|
| J01 | 2 回目を取り消さない（`event.preventDefault()` を消す） | 1 本: SubmitOnceTest::test_the_first_submit_goes_through_and_the_rest_are_cancelled_until_the_page_is_shown_again — `Failed asserting that two arrays are identical.` |
| J02 | 1 回目で印を立てない | 2 本: SubmitOnceTest::test_the_first_submit_goes_through_and_the_rest_are_cancelled_until_the_page_is_shown_again — `Failed asserting that two arrays are identical.`<br>SubmitOnceTest::test_the_import_area_reloads_only_when_it_comes_back_after_sending_or_without_its_state — `Failed asserting that two arrays are identical.` |
| J03 | `pageshow` で印を下ろさない | 1 本: SubmitOnceTest::test_the_first_submit_goes_through_and_the_rest_are_cancelled_until_the_page_is_shown_again — `Failed asserting that two arrays are identical.` |
| J04 | 印の最初の値を `true` に | 4 本: SubmitOnceTest::test_the_first_submit_goes_through_and_the_rest_are_cancelled_until_the_page_is_shown_again — `Failed asserting that two arrays are identical.`<br>SubmitOnceTest::test_the_import_area_reloads_only_when_it_comes_back_after_sending_or_without_its_state ×3 — `Failed asserting that two arrays are identical.` |
| J05 | `persisted` のときだけ印を下ろす | 1 本: SubmitOnceTest::test_the_first_submit_goes_through_and_the_rest_are_cancelled_until_the_page_is_shown_again — `Failed asserting that two arrays are identical.` |
| J06 | 周辺ビルの読み込み直しの絞り込みを外す（いつも読み込み直す） | 4 本: SubmitOnceTest::test_the_import_area_reloads_only_when_it_comes_back_after_sending_or_without_its_state ×4 — `Failed asserting that two arrays are identical.` |
| J07 | ナビゲーションの種類を見ない（bfcache なしの「戻る」で読み込み直さない） | 1 本: SubmitOnceTest::test_the_import_area_reloads_only_when_it_comes_back_after_sending_or_without_its_state — `Failed asserting that two arrays are identical.` |
| J08 | bfcache から戻ったら、送ったかどうかに関係なく読み込み直す | 2 本: SubmitOnceTest::test_the_import_area_reloads_only_when_it_comes_back_after_sending_or_without_its_state ×2 — `Failed asserting that two arrays are identical.` |
| J09 | `reloadOnReturn` を無視する | 2 本: SubmitOnceTest::test_the_import_area_reloads_only_when_it_comes_back_after_sending_or_without_its_state ×2 — `Failed asserting that two arrays are identical.` |
| J10 | `@once` を外す | 1 本: SubmitOnceTest::test_the_script_is_defined_once_in_the_scripts_stack — `部品を 2 回読み込むと、関数が 2 回定義される` |
| J11 | `@push('scripts')` を外す（読み込んだ場所に script を出す） | 1 本: SubmitOnceTest::test_the_script_is_defined_once_in_the_scripts_stack — `Failed asserting that '<script>…' does not contain "function submitOnce("` |

**画面（テナントの確認画面で形を 1 つずつ）:**

| ID | 変異 | 期待（落ちるテスト・理由の 1 行目）|
|---|---|---|
| UT01 | テナントの鍵の hidden の名前を `guide_token` に | 17 本: SubmitOnceTest::test_every_view_that_carries_an_import_token_uses_submit_once — `鍵を描く画面の列挙が痩せている（走査の空振り）`<br>TenantImportDoubleSubmitTest::test_sending_the_same_confirmation_twice_imports_once ×5 — `1 回目で 2 件が入っていない（測定が無効）`<br>TenantImportDoubleSubmitTest::test_uploading_the_same_file_again_issues_a_new_token — `ErrorException: Undefined array key "import_token"`<br>TenantImportDoubleSubmitTest::test_the_confirmation_form_guards_against_a_second_press — `確定のフォームに 1 回限りの鍵（import_token）の hidden が無い`<br>TenantUnitImportTest::test_unit_round_trip_creates_the_rows — `Failed asserting that null matches expected '区画インポート完了: 2件を登録しました'.`<br>TenantUnitImportTest::test_rows_with_the_same_display_name_inside_the_csv_keep_only_the_first — `Failed asserting that null matches expected '区画インポート完了: 2件を登録しました'.`<br>TenantUnitImportTest::test_a_row_with_a_non_numeric_floor_is_an_error_row_and_the_others_import — `Failed asserting that null matches expected '区画インポート完了: 1件を登録しました'.`<br>TenantUnitImportTest::test_confirming_restores_the_deleted_unit_and_overwrites_it_with_the_row — `Failed asserting that null matches expected '区画インポート完了: 2件を登録しました（うち削除済みから復元 1件）'.`<br>TenantUnitImportTest::test_a_row_matching_a_live_unit_is_still_an_error_row — `Failed asserting that null matches expected '区画インポート完了: 1件を登録しました'.`<br>TenantUnitImportTest::test_total_units_counts_the_restored_unit_but_not_other_deleted_units — `Failed asserting that null matches expected '区画インポート完了: 1件を登録しました（うち削除済みから復元 1件）'.`<br>TenantUnitImportTest::test_restoring_the_unit_by_the_unit_import_lets_the_contract_import_through — `Failed asserting that null matches expected '区画インポート完了: 1件を登録しました（うち削除済みから復元 1件）'.`<br>TenantUnitImportTest::test_a_past_contract_row_for_a_deleted_unit_is_attached_to_the_deleted_unit — `Failed asserting that null matches expected '過去契約インポート完了: 契約 1件を登録、顧客 1件を自動作成'.`<br>TenantImportRejectionTest::test_a_failed_import_after_confirming_goes_back_to_the_same_tab — `断られた理由が画面に出ていない` |
| UT02 | テナントの確認画面から部品の `@include` を消す | 1 本: SubmitOnceTest::test_every_view_that_carries_an_import_token_uses_submit_once — `鍵を描くのに二度押し止めの部品を使っていない画面がある:` — 部品の読み込みを守るのは全件分類だけ（ブラウザでは `submitOnce is not defined` で二度押し止めが効かなくなる） |
| UT03 | `x-on:submit` を外す | 1 本: TenantImportDoubleSubmitTest::test_the_confirmation_form_guards_against_a_second_press — `確定のフォームが送信を部品に渡していない` |
| UT04 | `pageshow` の `.window` を外す | 1 本: TenantImportDoubleSubmitTest::test_the_confirmation_form_guards_against_a_second_press — `確定のフォームが pageshow を部品に渡していない` |
| UT05 | ボタンの `:disabled` を外す | 1 本: TenantImportDoubleSubmitTest::test_the_confirmation_form_guards_against_a_second_press — `送信中にボタンを押せなくしていない` |
| UT06 | `disabled:opacity-60` を外す | 1 本: TenantImportDoubleSubmitTest::test_the_confirmation_form_guards_against_a_second_press — `送信ボタンに disabled:opacity-60 が無い` |
| UT07 | ボタンの style に `cursor: pointer` を戻す | 1 本: TenantImportDoubleSubmitTest::test_the_confirmation_form_guards_against_a_second_press — `送信ボタンの style に cursor がある` |
| UT08 | ボタンに `name` を付ける | 1 本: TenantImportDoubleSubmitTest::test_the_confirmation_form_guards_against_a_second_press — `送信ボタンに name がある` |
| UT09 | `role="status"` を外す | 1 本: TenantImportDoubleSubmitTest::test_the_confirmation_form_guards_against_a_second_press — `確定のフォームに role="status" が無い` |
| UT10 | 送信中の文字を変える | 1 本: TenantImportDoubleSubmitTest::test_the_confirmation_form_guards_against_a_second_press — `Failed asserting that '<span role="status" …' contains "x-text=…"` |
| UT11 | 送信中の文字の `display: inline-block` を外す | 1 本: TenantImportDoubleSubmitTest::test_the_confirmation_form_guards_against_a_second_press — `送信中の文字が inline-block でない` |
| UT12 | フォームの `x-data` を `{}` に | 2 本: SubmitOnceTest::test_every_view_that_carries_an_import_token_uses_submit_once — `鍵を描くのに二度押し止めの部品を使っていない画面がある:`<br>TenantImportDoubleSubmitTest::test_the_confirmation_form_guards_against_a_second_press — `確定のフォームの x-data が二度押し止めの部品でない` |

**画面（ほかの 5 つ）:**

| ID | 変異 | 期待（落ちるテスト・理由の 1 行目）|
|---|---|---|
| UM01 | 賃貸マンションの鍵の hidden の名前を `guide_token` に | 29 本: SubmitOnceTest::test_every_view_that_carries_an_import_token_uses_submit_once — `鍵を描く画面の列挙が痩せている（走査の空振り）`<br>MansionImportDoubleSubmitTest::test_sending_the_same_confirmation_twice_imports_once ×6 — `1 回目で 2 件が入っていない（測定が無効）`<br>MansionImportDoubleSubmitTest::test_uploading_the_same_file_again_issues_a_new_token — `ErrorException: Undefined array key "import_token"`<br>MansionImportDoubleSubmitTest::test_the_confirmation_form_guards_against_a_second_press — `確定のフォームに 1 回限りの鍵（import_token）の hidden が無い`<br>MansionImportTest::test_property_round_trip_creates_the_row — `Failed asserting that null matches expected '物件インポート完了: 1件を登録しました'.`<br>MansionImportTest::test_property_codes_are_numbered_sequentially — `Failed asserting that null matches expected '物件インポート完了: 3件を登録しました'.`<br>MansionImportTest::test_property_codes_continue_from_the_existing_maximum — `Failed asserting that two arrays are identical.`<br>MansionImportTest::test_a_duplicate_name_inside_the_csv_drops_only_that_row — `Failed asserting that null matches expected '物件インポート完了: 2件を登録しました'.`<br>MansionImportTest::test_an_existing_name_is_skipped_not_errored — `Failed asserting that '<!DOCTYPE html>…`<br>MansionImportTest::test_room_round_trip_creates_the_row — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\MsProperty].`<br>MansionImportTest::test_a_room_number_already_in_the_database_is_an_error_row — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\MsProperty].`<br>MansionImportTest::test_total_units_is_recalculated_after_importing_rooms — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\MsProperty].`<br>MansionImportTest::test_parking_round_trip_creates_the_row — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\MsProperty].`<br>MansionImportTest::test_the_roof_flag_accepts_common_spellings — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\MsProperty].`<br>MansionImportTest::test_tenant_round_trip_creates_the_row — `Failed asserting that null matches expected '入居者インポート完了: 1件を登録しました'.`<br>MansionImportTest::test_an_invalid_email_drops_only_that_row — `Failed asserting that null matches expected '入居者インポート完了: 1件を登録しました'.`<br>MansionImportTest::test_an_active_room_contract_marks_the_room_occupied — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\MsProperty].`<br>MansionImportTest::test_a_terminated_room_contract_leaves_the_room_alone — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\MsProperty].`<br>MansionImportTest::test_an_impossible_contract_date_drops_only_that_row — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\MsProperty].`<br>MansionImportTest::test_a_second_active_contract_warns_but_still_imports — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\MsProperty].`<br>MansionImportTest::test_an_active_parking_contract_marks_the_parking_occupied — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\MsProperty].`<br>MansionImportTest::test_a_linked_room_number_attaches_the_active_room_contract — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\MsProperty].`<br>MansionImportTest::test_a_terminated_parking_contract_leaves_the_parking_vacant — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\MsProperty].`<br>MansionImportRejectionTest::test_a_failed_import_after_confirming_goes_back_to_the_same_tab — `断られた理由が画面に出ていない` |
| UM02 | 賃貸マンションの確認画面から部品の `@include` を消す | 1 本: SubmitOnceTest::test_every_view_that_carries_an_import_token_uses_submit_once — `鍵を描くのに二度押し止めの部品を使っていない画面がある:` |
| UM03 | 賃貸マンションのボタンの `:disabled` を外す | 1 本: MansionImportDoubleSubmitTest::test_the_confirmation_form_guards_against_a_second_press — `送信中にボタンを押せなくしていない` |
| UZ01 | ZEAL 会員の鍵の hidden の名前を `guide_token` に | 6 本: SubmitOnceTest::test_every_view_that_carries_an_import_token_uses_submit_once — `鍵を描く画面の列挙が痩せている（走査の空振り）`<br>ZealMemberImportControllerTest::test_a_store_stopped_before_confirming_sends_the_user_back_to_the_import_screen — `断られた理由が画面に出ていない`<br>ZealMemberImportControllerTest::test_a_confirmation_without_the_confirmed_flag_goes_back_to_the_import_screen — `断られた理由が画面に出ていない`<br>ZealMemberImportControllerTest::test_sending_the_same_confirmation_twice_imports_once — `Failed asserting that table [zeal_members] matches expected entries count of 5. Entries found: 0.`<br>ZealMemberImportControllerTest::test_uploading_the_same_file_again_issues_a_new_token — `ErrorException: Undefined array key "import_token"`<br>ZealMemberImportControllerTest::test_the_confirmation_form_guards_against_a_second_press — `確定のフォームに 1 回限りの鍵（import_token）の hidden が無い` |
| UZ02 | ZEAL 会員の確認画面から部品の `@include` を消す | 1 本: SubmitOnceTest::test_every_view_that_carries_an_import_token_uses_submit_once — `鍵を描くのに二度押し止めの部品を使っていない画面がある:` |
| UZ03 | ZEAL 会員の `x-on:pageshow.window` を外す | 1 本: ZealMemberImportControllerTest::test_the_confirmation_form_guards_against_a_second_press — `確定のフォームが pageshow を部品に渡していない` |
| UZ04 | ZEAL 会員のボタンの並びの `flex-wrap: wrap` を外す | **0 本** — **PHP から見えない**。Task 13 の 6・7 で見る（試作のブラウザで、外すと 375px で送信中の文字が 69px に縮んで 2 行に割れた） |
| US01 | 工程表の鍵の hidden の名前を `guide_token` に | 14 本: SubmitOnceTest::test_every_view_that_carries_an_import_token_uses_submit_once — `鍵を描く画面の列挙が痩せている（走査の空振り）`<br>ScheduleImportTest::test_a_rejection_from_the_preview_goes_back_to_the_import_form ×3 — `断られた理由が画面に出ていない`<br>ScheduleImportTest::test_submitting_the_rendered_form_imports_every_step — `Failed asserting that two strings are equal.`<br>ScheduleImportTest::test_a_tampered_payload_is_rejected_and_changes_nothing — `Session is missing expected key [errors].`<br>ScheduleImportTest::test_the_category_is_derived_again_on_the_server — `Failed asserting that 0 is identical to 55.`<br>ScheduleImportTest::test_reimporting_replaces_only_the_imported_steps — `手入力 3 件 + 取込 65 件`<br>ScheduleImportTest::test_reimporting_does_not_touch_another_owners_steps — `Failed asserting that 0 is identical to 65.`<br>ScheduleImportTest::test_imported_steps_are_ordered_after_the_hand_written_ones — `Failed asserting that two arrays are identical.`<br>ScheduleImportTest::test_sending_the_same_confirmation_twice_imports_once — `1 回目で 65 件が入っていない（測定が無効）`<br>ScheduleImportTest::test_choosing_the_same_file_again_issues_a_new_token — `ErrorException: Undefined array key "import_token"`<br>ScheduleImportTest::test_the_confirmation_form_guards_against_a_second_press — `確定のフォームに 1 回限りの鍵（import_token）の hidden が無い`<br>HousingConstructionStartDateTest::test_submitting_the_rendered_form_writes_the_dates_it_announced — `Failed asserting that two strings are equal.` |
| US02 | 工程表の確認画面から部品の `@include` を消す | 1 本: SubmitOnceTest::test_every_view_that_carries_an_import_token_uses_submit_once — `鍵を描くのに二度押し止めの部品を使っていない画面がある:` |
| US03 | 工程表のボタンの `cursor-pointer disabled:cursor-not-allowed` を外す | 1 本: ScheduleImportTest::test_the_confirmation_form_guards_against_a_second_press — `送信ボタンに cursor-pointer が無い` |
| UH01 | シート取込の鍵の hidden の名前を `guide_token` に | 14 本: SubmitOnceTest::test_every_view_that_carries_an_import_token_uses_submit_once — `鍵を描く画面の列挙が痩せている（走査の空振り）`<br>SheetImportTest::test_applying_the_preview_writes_the_cells_and_the_history — `Failed asserting that null is identical to '2026-07 の本部 Sheet を取り込みました (6 セル更新)。'.`<br>SheetImportTest::test_sending_the_same_confirmation_twice_applies_once — `1 回目で履歴が 2 行できていない（測定が無効）`<br>SheetImportTest::test_previewing_again_issues_a_new_token — `ErrorException: Undefined array key "import_token"`<br>SheetImportTest::test_a_changed_amount_in_the_sheet_turns_the_confirmation_back — `断りの文言（error のフラッシュ）が違う`<br>SheetImportTest::test_a_cell_that_now_needs_writing_turns_the_confirmation_back — `断りの文言（error のフラッシュ）が違う`<br>SheetImportTest::test_a_cell_that_no_longer_needs_writing_turns_the_confirmation_back — `断りの文言（error のフラッシュ）が違う`<br>SheetImportTest::test_applying_the_same_month_from_another_tab_turns_the_later_confirmation_back — `断りの文言（error のフラッシュ）が違う`<br>SheetImportTest::test_a_changed_current_value_of_a_written_cell_still_applies — `プレビューで見せた値が書かれていない`<br>SheetImportTest::test_changes_that_do_not_touch_the_plan_still_apply_and_the_history_keeps_what_was_read — `Failed asserting that 250000 is identical to 304638.`<br>SheetImportTest::test_a_confirmation_without_a_usable_digest_applies_nothing ×3 — `断りの文言（error のフラッシュ）が違う`<br>SheetImportTest::test_the_confirmation_form_guards_against_a_second_press — `確定のフォームに 1 回限りの鍵（import_token）の hidden が無い` |
| UH02 | シート取込の指紋の hidden を消す | 7 本: SheetImportTest::test_applying_the_preview_writes_the_cells_and_the_history — `Failed asserting that null is identical to '2026-07 の本部 Sheet を取り込みました (6 セル更新)。'.`<br>SheetImportTest::test_sending_the_same_confirmation_twice_applies_once — `1 回目で履歴が 2 行できていない（測定が無効）`<br>SheetImportTest::test_previewing_again_issues_a_new_token — `プレビューし直した確認画面の鍵で反映できない`<br>SheetImportTest::test_applying_the_same_month_from_another_tab_turns_the_later_confirmation_back — `Failed asserting that 0 is identical to 2.`<br>SheetImportTest::test_a_changed_current_value_of_a_written_cell_still_applies — `プレビューで見せた値が書かれていない`<br>SheetImportTest::test_changes_that_do_not_touch_the_plan_still_apply_and_the_history_keeps_what_was_read — `Failed asserting that 250000 is identical to 304638.`<br>SheetImportTest::test_the_confirmation_form_guards_against_a_second_press — `確定のフォームに指紋（plan_digest）が無い` |
| UH03 | シート取込の確認画面から部品の `@include` を消す | 1 本: SubmitOnceTest::test_every_view_that_carries_an_import_token_uses_submit_once — `鍵を描くのに二度押し止めの部品を使っていない画面がある:` |
| UH04 | シート取込の送信中の文字を「取り込んでいます…」に | 1 本: SheetImportTest::test_the_confirmation_form_guards_against_a_second_press — `Failed asserting that '<span role="status" …' contains "x-text=…"` |
| UH05 | シート取込のボタンの style に `cursor:pointer` を戻す | 1 本: SheetImportTest::test_the_confirmation_form_guards_against_a_second_press — `送信ボタンの style に cursor がある` |
| UA01 | 周辺ビルの鍵の hidden の名前を `guide_token` に | 30 本: SubmitOnceTest::test_every_view_that_carries_an_import_token_uses_submit_once — `鍵を描く画面の列挙が痩せている（走査の空振り）`<br>AreaBuildingImportTest::test_creates_new_buildings_with_a_survey — `Failed asserting that two strings are equal.`<br>AreaBuildingImportTest::test_matches_an_existing_building_by_name — `Failed asserting that 0 is identical to 1.`<br>AreaBuildingImportTest::test_duplicate_names_in_the_ledger_resolve_to_the_lowest_id — `id の小さいビルに付いていない`<br>AreaBuildingImportTest::test_a_new_name_repeated_in_the_same_file_creates_one_building — `同じビルが二重に作られている`<br>AreaBuildingImportTest::test_fills_only_the_blank_fields_of_an_existing_building — `Failed asserting that null is identical to 'Excel の住所'.`<br>AreaBuildingImportTest::test_skips_a_survey_that_already_exists_for_the_same_month — `TypeError: …assertStringContainsString(): Argument #2 ($haystack) must be of type string, null given`<br>AreaBuildingImportTest::test_the_same_month_twice_in_one_file_inserts_once — `Failed asserting that 0 is identical to 1.`<br>AreaBuildingImportTest::test_row_level_month_wins_over_the_screen_default — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\AreaBuilding].`<br>AreaBuildingImportTest::test_row_level_month_accepts_an_excel_date_cell — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\AreaBuilding].`<br>AreaBuildingImportTest::test_unreadable_row_level_months_reject_the_row — `Failed asserting that two arrays are identical.`<br>AreaBuildingImportTest::test_normalizes_full_width_digits_and_separators — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\AreaBuilding].`<br>AreaBuildingImportTest::test_rows_with_non_numeric_counts_are_skipped_and_reported — `Failed asserting that two arrays are identical.`<br>AreaBuildingImportTest::test_counts_out_of_range_are_rejected — `Failed asserting that two arrays are identical.`<br>AreaBuildingImportTest::test_total_floors_accepts_japanese_notation — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\AreaBuilding].`<br>AreaBuildingImportTest::test_unreadable_total_floors_reject_the_row — `Failed asserting that two arrays are identical.`<br>AreaBuildingImportTest::test_imports_tenant_rows_into_an_existing_building — `Failed asserting that 0 is identical to 3.`<br>AreaBuildingImportTest::test_tenant_floor_accepts_japanese_notation — `Failed asserting that two arrays are identical.`<br>AreaBuildingImportTest::test_tenant_rows_for_unknown_buildings_are_reported_not_created — `Failed asserting that 0 is identical to 1.`<br>AreaBuildingImportTest::test_unmatched_rows_are_counted_by_row_not_by_distinct_name — `TypeError: …assertStringContainsString(): Argument #2 ($haystack) must be of type string, null given`<br>AreaBuildingImportTest::test_unreadable_tenant_floor_rejects_the_row — `Failed asserting that two arrays are identical.`<br>AreaBuildingImportTest::test_repeated_tenant_import_reports_the_current_total — `TypeError: …assertStringContainsString(): Argument #2 ($haystack) must be of type string, null given`<br>AreaBuildingImportTest::test_over_long_strings_are_truncated_to_the_column_length — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\AreaBuildingTenant…`<br>AreaBuildingImportTest::test_over_long_building_strings_are_truncated — `Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\Models\AreaBuilding].`<br>AreaBuildingImportTest::test_non_scalar_cell_values_are_rejected_without_crashing — `Failed asserting that 0 is identical to 1.`<br>AreaBuildingImportTest::test_the_confirm_form_round_trips_to_the_execute_route — `Failed asserting that two strings are equal.`<br>AreaBuildingImportTest::test_sending_the_same_screen_twice_imports_once ×2 — `1 回目で取り込まれていない（測定が無効）`<br>AreaBuildingImportTest::test_opening_the_import_screen_again_issues_a_new_token — `ErrorException: Undefined array key "import_token"`<br>AreaBuildingImportTest::test_the_import_form_guards_against_a_second_press — `確定のフォームに 1 回限りの鍵（import_token）の hidden が無い` |
| UA02 | 周辺ビルの画面から部品の `@include` を消す | 1 本: SubmitOnceTest::test_every_view_that_carries_an_import_token_uses_submit_once — `鍵を描くのに二度押し止めの部品を使っていない画面がある:` |
| UA03 | 周辺ビルの `x-data` から `reloadOnReturn` を落とす | 1 本: AreaBuildingImportTest::test_the_import_form_guards_against_a_second_press — `確定のフォームの x-data が二度押し止めの部品でない` |
| UA04 | 周辺ビルの `:disabled` から `submitting ||` を落とす | 2 本: AreaBuildingImportTest::test_the_month_defaults_to_the_current_month_and_blocks_submission — `Failed asserting that '<!DOCTYPE html>…`<br>AreaBuildingImportTest::test_the_import_form_guards_against_a_second_press — `送信中にボタンを押せなくしていない` |

**テストの道具:**

| ID | 変異 | 期待（落ちるテスト・理由の 1 行目）|
|---|---|---|
| T01 | `ParsesForms::htmlAttr()` を private に戻す | 2 本: ScheduleImportTest::test_the_confirmation_form_guards_against_a_second_press — `Error: Call to private method Tests\Feature\Schedule\ScheduleTestCase::htmlAttr() from scope Tests\Feature\Hou…`<br>AreaBuildingImportTest::test_the_import_form_guards_against_a_second_press — `Error: Call to private method Tests\Feature\Tenant\AreaBuildingTestCase::htmlAttr() from scope Tests\Feature\T…` |
| T02 | テスト用スキーマの一意の索引 `uq_zeal_sim_val` を消す | **0 本** — **等価**。今のテストはどれも頼っていない（`updateOrCreate` で書くので重複が作られない）。将来の偽の緑を止めるために持つ（Bug #54 ⑤） |

- [ ] **Step 7: 片づけて記録をコミット**

```bash
SP=<Scratchpad directory>; C=$(cat "$SP/ids-mut-commit"); for s in a b c; do git -C /Users/masanori/site/manage/.claude/worktrees/import-double-submit worktree remove --force "$SP/ids-mut-$C-$s"; done; git -C /Users/masanori/site/manage/.claude/worktrees/import-double-submit worktree list
```

期待: 一覧に `ids-mut-…` が残っていない（ほかの会話の worktree と main repo とこの worktree だけ）。

この計画の末尾の「実測記録」に、表の結果（ID・落ちたテストの数と名前・理由）と、期待と違ったもの・その対応を書いてコミットする:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && git add docs/superpowers/plans/2026-09-28-import-double-submit.md && git commit -m "$(cat <<'EOF'
docs: ほかの取込の二重送信を止める改修の変異テストの結果を記録する

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 13: ローカルの実ブラウザ確認

**Files:** なし（使い捨てのログイン用ルートと本部 Sheet の読み先の差し替えは**コミットしない**。確認のあと必ず戻す）

使い捨ての SQLite ＋ `artisan serve` ＋ Playwright MCP（画面が見えている状態。⚠ Alpine のタイミングは画面が見えているブラウザで測る）。
⚠ `preview_start` は使わない（main repo の launch.json を解決して実 MySQL に当たる）。
⚠ ブラウザでパスワードを入力しない。ログインは使い捨てのログイン用ルートで入る。
⚠ Playwright MCP が読み書きできるのは `/Users/masanori/site/manage` の下だけ（scratchpad は拒否される）。CSV・xlsx・スクリーンショットは `.playwright-mcp/`（gitignore 済み）に置き、確認のあと消す。
⚠ Playwright の癖: `page.evaluate` は画面の移り変わりの途中は答えない → 送信中の様子は画面の中の見張り（MutationObserver → localStorage）で記録し、移ったあとで読む。`browser_run_code_unsafe` の中では `setTimeout`・`Buffer` が無い（`page.waitForTimeout`・`page.route`・`locator.setInputFiles(<パス>)` は使える）。
⚠ キャッシュは本番と同じ file にする（`CACHE_STORE=file`）。`array` だと `artisan serve` は要求ごとに別のプロセスなので鍵を覚えず、2 回目も通ってしまう。
⚠ 試作（2026-09-28）で 1〜6・8・9 を同じ手順で測った。表の「試作の実測」はその値（工程表と賃貸マンションは試作では見ていない＝7 と 5 の 375px は初めて測る）。

- [ ] **Step 1: 使い捨ての環境**（`SP` は scratchpad。環境変数は毎回 `source` する＝シェルの状態は次の呼び出しに残らない）

```bash
SP=<Scratchpad directory>; DB="$SP/ids-browser.sqlite"; rm -f "$DB"; : > "$DB"; cat > "$SP/ids-env.sh" <<EOF
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" APP_ENV=local APP_DEBUG=true DB_CONNECTION=sqlite DB_DATABASE="$DB" QUEUE_CONNECTION=sync MAIL_MAILER=log CACHE_STORE=file SESSION_DRIVER=file
EOF
sed 's/APP_KEY="[^"]*"/APP_KEY="…"/' "$SP/ids-env.sh"; ls /Users/masanori/site/manage/.claude/worktrees/import-double-submit/bootstrap/cache/; lsof -nP -iTCP:8767 -sTCP:LISTEN | head -2
```

期待: env の 1 行（APP_KEY は伏せる）・`bootstrap/cache/` に `config.php` が無い（あると環境変数が効かない。`packages.php`・`services.php` はあってよい）・8767 番を掴んでいるプロセスが無い（あれば別の番号にして、下の URL も替える）。

`$SP/seed-ids.php`（scratchpad に置く・コミットしない。**接続先が scratchpad の SQLite でなければ止まる**安全装置つき。第 1 引数にアプリの根を渡す）:

```php
<?php
// 使い捨ての SQLite に、ほかの取込の二重送信の確認用のデータを入れる（scratchpad に置く・コミットしない）
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\HsProperty;
use App\Models\User;
use App\Models\ZealPlan;
use App\Models\ZealSimulation;
use App\Models\ZealStore;
use Illuminate\Support\Facades\DB;

$root = $argv[1] ?? '';
if ($root === '' || ! is_file($root . '/artisan')) {
    fwrite(STDERR, "中断: 第 1 引数にアプリの根（artisan のあるフォルダ）を渡す\n");
    exit(1);
}
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$path = (string) config('database.connections.sqlite.database');
if (config('database.default') !== 'sqlite' || ! str_starts_with($path, '/private/tmp/claude-501/')) {
    fwrite(STDERR, '中断: 接続先が使い捨ての SQLite ではない（' . config('database.default') . " {$path}）\n");
    exit(1);
}

// ms_*・zeal_*・hs_* などは本番も raw SQL 管理でマイグレーションに無い → テスト用スキーマの trait で作る
$schema = new class {
    use Tests\Concerns\CreatesMansionSchema;
    use Tests\Concerns\CreatesRealEstateSchema;
    use Tests\Concerns\CreatesZealSchema;
    use Tests\Concerns\CreatesZealSimulationSchema;

    public function run(): void
    {
        $this->createMansionSchema();
        $this->createRealEstateSchema();
        $this->createZealSchema();
        $this->createZealSimulationSchema();
        $this->seedZealSimulationCategories();
        $this->seedZealSheetImportCategories();
    }
};
$schema->run();

$user = new User();
$user->forceFill([
    'name' => '経営 一郎', 'email' => 'e0001@example.invalid',
    'role' => UserRole::Executive->value, 'status' => UserStatus::Active->value,
    // ブラウザでは使わない（使い捨てのログイン用ルートで入る）
    'password' => bin2hex(random_bytes(16)), 'must_change_password' => false,
])->save();

// ZEAL 会員の取込: 店舗とプラン、テストの標準 fixture（5 区分 + ビジター + 氏名空）の 77 列の CSV
ZealStore::create(['name' => '松山市駅前店', 'display_order' => 1, 'active' => true]);
ZealPlan::create(['name' => 'セミパーソナル通い放題', 'regular_price_excl' => 9800]);
ZealPlan::create(['name' => 'パーソナル&セミパーソナル月4回', 'regular_price_excl' => 13000]);
$ref  = new ReflectionClass(Tests\Feature\Admin\ZealMemberImportControllerTest::class);
$test = $ref->newInstanceWithoutConstructor();
$csv  = $ref->getMethod('csvContent')->invoke($test, $ref->getMethod('fixtureRows')->invoke($test));
file_put_contents('/Users/masanori/site/manage/.playwright-mcp/ids-zeal-members.csv', "\u{FEFF}" . $csv);

// 本部 Sheet 取込: 試算表（読み先は routes/web.php の一時の Http::fake が手元の CSV に差し替える）
$sim = ZealSimulation::create([
    'fiscal_year' => 2026, 'name' => '2026年度',
    'sales_sheet_url'   => 'https://docs.google.com/spreadsheets/d/TEST-SALES/export?format=csv&gid=0',
    'expense_sheet_url' => 'https://docs.google.com/spreadsheets/d/TEST-EXPENSE/export?format=csv&gid=0',
]);
foreach (['revenue' => 250000, 'outsourcing' => 400000] as $code => $amount) {
    DB::table('zeal_simulation_values')->insert([
        'simulation_id' => $sim->id, 'category_id' => DB::table('zeal_simulation_categories')->where('code', $code)->value('id'),
        'year_month' => '2026-07', 'amount' => $amount, 'is_manual_override' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

// 工程表の取込: 建売物件
$property = HsProperty::create([
    'property_code' => 'HS-BR-1', 'property_name' => 'ブラウザ 1号地', 'status' => 'construction',
    'address' => '愛媛県松山市3-3-3', 'created_by' => $user->id,
]);

echo "user id: {$user->id} / simulation id: {$sim->id} / hs property id: {$property->id} / cache: " . config('cache.default') . "\n";
```

確認用のファイル（BOM つき UTF-8 の CSV 5 つと、工程表の xlsx の写し）:

```bash
SP=<Scratchpad directory>; python3 - "$SP" <<'PY'
import os, sys
sp = sys.argv[1]
d = '/Users/masanori/site/manage/.playwright-mcp'
os.makedirs(d, exist_ok=True)
bom = {
    'ids-tenant-property.csv': "物件名,郵便番号,住所,構造,築年月,階数,所有区分,オーナー名,稼働状態\n二重ビルA,790-0001,愛媛県松山市一番町1-1,RC造,2000-03,3,自社,,稼働中\n二重ビルB,790-0002,愛媛県松山市二番町2-2,S造,,5,オーナー,大家太郎,稼働中\n",
    'ids-tenant-narrow.csv': "物件名,郵便番号,住所,構造,築年月,階数,所有区分,オーナー名,稼働状態\n狭い画面ビルA,790-0001,愛媛県松山市一番町1-1,RC造,2000-03,3,自社,,稼働中\n",
    'ids-mansion-property.csv': "物件名,所有区分,オーナー名,郵便番号,住所,総戸数,階数,構造,築年月,備考\n二重送信A棟,自社所有,,790-0001,愛媛県松山市一番町1-1,20,5,RC造,2010-04,\n",
    'ids-area-buildings.csv': "ビル名,総階数,営業,空き,不明\nアルファビル,5,4,1,0\nベータビル,3,3,0,0\n",
}
for name, body in bom.items():
    with open(os.path.join(d, name), 'w', encoding='utf-8') as f:
        f.write('﻿' + body)
sales = "項目,金額\n当月日割売上金,200000\n前月時点会費預り金,100000\n調整金,4638\n当月売上合計,304638\nロイヤリティ額,9139\n差し引き精算額,295499\n"
expense = ("項目,金額(税込)\n運営費,516053\n店舗備品費,5500\n総計,521553\n\n項目,納品月,品目,個数,金額(税込)\n"
           "運営費,2026-07,店舗運営委託費,1,440000\n運営費,2026-07,時間帯業務委託費,3,33000\n運営費,2026-07,研修システム,1,16500\n"
           "運営費,2026-07,WEB運用費,1,16500\n運営費,2026-07,hacomono決済手数料,1,10053\n店舗備品費,2026-07,トイレットペーパー,2,5500\n")
open(os.path.join(sp, 'ids-sales.csv'), 'w', encoding='utf-8').write(sales)
open(os.path.join(sp, 'ids-expense.csv'), 'w', encoding='utf-8').write(expense)
print('ok')
PY
cp /Users/masanori/site/manage/.claude/worktrees/import-double-submit/tests/fixtures/schedule-import/list-format.xlsx /Users/masanori/site/manage/.playwright-mcp/ids-schedule.xlsx
```

```bash
SP=<Scratchpad directory>; WT=/Users/masanori/site/manage/.claude/worktrees/import-double-submit; cd "$WT" && source "$SP/ids-env.sh" && php artisan migrate --force 2>&1 | tail -2 && php "$SP/seed-ids.php" "$WT" && /Users/masanori/site/manage/node_modules/.bin/vite build 2>&1 | tail -3
```

期待: `user id: 1 / simulation id: 1 / hs property id: 1 / cache: file`。`vite build` は worktree の `public/build`（gitignore 済み）に `app-D-wd4D2y.css` を書く（本番と同じ名前＝CSS は変わらない）。

- [ ] **Step 2: 使い捨てのログイン用ルートと本部 Sheet の読み先の差し替え**（`routes/web.php` の末尾に一時的に足す。**コミットしない**。`<SP>` は scratchpad の絶対パスに置き換える）

```php
// ⚠ 使い捨て（ローカルの実ブラウザ確認だけ。コミットしない）
Route::get('/_dev/login-as/{id}', function (string $id) {
    abort_unless(app()->environment('local'), 404);
    \Illuminate\Support\Facades\Auth::loginUsingId((int) $id);
    return redirect(request('to', '/admin/tenant-import'));
});

// ⚠ 使い捨て: 本部 Sheet の読み先を手元の CSV にする（要求ごとに routes/web.php が読まれるので、ここで効く）
\Illuminate\Support\Facades\Http::fake([
    'docs.google.com/spreadsheets/d/TEST-SALES/*'   => fn () => \Illuminate\Support\Facades\Http::response(file_get_contents('<SP>/ids-sales.csv')),
    'docs.google.com/spreadsheets/d/TEST-EXPENSE/*' => fn () => \Illuminate\Support\Facades\Http::response(file_get_contents('<SP>/ids-expense.csv')),
]);
```

開発サーバを Bash の `run_in_background` で起動する（出力ファイルのパスを控える。3・4 で読む）:

```bash
SP=<Scratchpad directory>; cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && source "$SP/ids-env.sh" && php artisan serve --host=127.0.0.1 --port=8767
```

- [ ] **Step 3: 見ること**（Playwright で `http://127.0.0.1:8767/_dev/login-as/1?to=/admin/tenant-import` を開くとテナントの取込の画面に着く。下の JS は `browser_run_code_unsafe` に渡す）

| # | 操作 | 見ること（試作の実測）|
|---|---|---|
| 1 | JS T1（テナントの物件タブで `ids-tenant-property.csv` をプレビュー）| 確定のフォームの `x-data` は `submitOnce()`、親は `tenantImportTabs()`・`submitting: false`・鍵 40 文字・ボタンは押せて `cursor: pointer`・`opacity: 1`・送信中の文字は空 |
| 2 | JS T2（確定の応答を 3 秒遅らせてダブルクリック。画面の中の見張りで記録）| 確定の POST は **1 回**・見張りの `click-listener` の直後が `disabled: true`・`not-allowed`・`0.6`・`取り込んでいます…` ／ 「物件インポート完了: 2件を登録しました」・鍵の案内なし |
| 3 | JS T3（「戻る」→ そのまま押す）→ 開発サーバの出力の末尾 | 戻った画面は `navType: back_forward`・押せる・`submitting: false`。**戻った時刻の行に `/admin/tenant-import/property` の POST が無い**（手元の控えから描き直した。Playwright の `request` には出るが、控えから返している）。押すと「この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「物件一覧」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。」 |
| 4 | JS A1（周辺ビルの取込の画面を開く → `ids-area-buildings.csv` → 「プレビュー」）→ JS A2（送る → 「戻る」）→ 開発サーバの出力の末尾 | 開いた直後: `x-data` は `submitOnce({ reloadOnReturn: true })`、親は `areaImportForm()`・押せない（`not-allowed`・`0.5`）・包む span の `title` が「取り込める行がありません。」 ／ プレビュー後: hidden の `kind` が `buildings`・`surveyed_month` が当月・`rows` が 2 行（入れ子の中から親の `payload()` が読める）・押せる・`title` が消える ／ 送ると「取込が完了しました。ビル新規 2 件 / 調査追加 2 件 …」 ／ 「戻る」: **bfcache は使われない**（見張りの `pageshow` が記録されない）・サーバに `GET /tenant/area-buildings/import` が **2 回**（戻ったときと、部品の読み込み直し）・最後の `navType` は `reload`・鍵は送る前と違う・`submitting: false` |
| 5 | JS M（賃貸マンションの物件タブで `ids-mansion-property.csv` をプレビュー → 送信を止めて押す）| 親は `mansionImportTabs()`・押す前は `submitting: false`・押すと `disabled: true`・`not-allowed`・`0.6`・`取り込んでいます…`（試作では見ていない。テナントと同じ形の見込み）|
| 6 | JS N（375px で、テナント・周辺ビル・ZEAL 会員・本部 Sheet・賃貸マンション・工程表の確認画面を開き、送信を止めて押す）＋ スクリーンショット | どの画面も `main` が `[375, 375]`・送信中の文字が 1 行・文字の上端がボタンの下端より下（ボタンの下の行にまとまって出る）。試作: テナント（ボタン下端 513 / 文字上端 516）・周辺ビル（677 / 685）・ZEAL 会員（796 / 808）・本部 Sheet（756 / 759）。工程表と賃貸マンションは初めて測る |
| 7 | JS Z（375px の ZEAL 会員で、ボタンの並びの `flex-wrap` を一時的に `nowrap` にして測り、戻す）| `wrap` のとき 1 行・`nowrap` にすると送信中の文字が 69px に縮んで **2 行に割れる**（試作の実測＝`flex-wrap` を足した理由。変異 UZ04 は PHP から見えない）|
| 8 | JS W（1440px の ZEAL 会員と本部 Sheet で押す）| 送信中の文字はボタンの右に 1 行（試作: ZEAL 会員 ボタン右端 618 / 文字左端 630・本部 Sheet 1111 / 1129）・`main` の横スクロール無し |
| 9 | ここまでの全部 | `browser_console_messages`（`level: warning`・`all: true`）が 0 件（試作: 0 件）|

JS T1:

```js
async (page) => {
  const upload = page.locator('form[action$="/admin/tenant-import/property"]').first();
  await upload.locator('input[type="file"]').setInputFiles('/Users/masanori/site/manage/.playwright-mcp/ids-tenant-property.csv');
  await Promise.all([page.waitForNavigation(), upload.locator('button[type="submit"]').first().click()]);
  return await page.evaluate(() => {
    const token = document.querySelector('input[name="import_token"]');
    const form = token.closest('form');
    const button = form.querySelector('button[type="submit"]');
    const cs = getComputedStyle(button);
    return {
      formXData: form.getAttribute('x-data'), parentXData: form.parentElement.closest('[x-data]').getAttribute('x-data'),
      submitting: window.Alpine.$data(form).submitting, tokenLength: token.value.length,
      button: { disabled: button.disabled, cursor: cs.cursor, opacity: cs.opacity, text: button.textContent.replace(/\s+/g, '') },
      status: form.querySelector('[role="status"]').textContent,
    };
  });
}
```

JS T2:

```js
async (page) => {
  await page.evaluate(() => {
    localStorage.removeItem('ids-watch');
    const form = document.querySelector('input[name="import_token"]').closest('form');
    const b = form.querySelector('button[type="submit"]');
    const s = form.querySelector('[role="status"]');
    const log = [];
    const snap = (why) => {
      const cs = getComputedStyle(b);
      log.push({ why, disabled: b.disabled, cursor: cs.cursor, opacity: cs.opacity, status: s.textContent });
      localStorage.setItem('ids-watch', JSON.stringify(log));
    };
    snap('before');
    new MutationObserver(() => snap('button')).observe(b, { attributes: true });
    new MutationObserver(() => snap('status')).observe(s, { childList: true, characterData: true, subtree: true });
    b.addEventListener('click', () => snap('click-listener'));
  });
  const posts = [];
  await page.route('**/*', async (route) => {
    const req = route.request();
    if (req.method() === 'POST' && (req.postData() || '').includes('confirmed=1')) {
      posts.push('confirm');
      await page.waitForTimeout(3000);
    }
    await route.continue();
  });
  await page.locator('form:has(input[name="import_token"]) button[type="submit"]').dblclick({ noWaitAfter: true });
  await page.waitForTimeout(5000);
  await page.unrouteAll({ behavior: 'ignoreErrors' });
  const watch = await page.evaluate(() => JSON.parse(localStorage.getItem('ids-watch') || '[]'));
  const text = await page.locator('body').innerText();
  return { posts, watch, done: (text.match(/物件インポート完了[^\n]*/) || [null])[0], refused: (text.match(/この確認画面からは取り込めません[^\n]*/) || [null])[0] };
}
```

JS T3:

```js
async (page) => {
  await page.goBack();
  await page.waitForTimeout(1500);
  const back = await page.evaluate(() => {
    const form = document.querySelector('input[name="import_token"]').closest('form');
    return { navType: performance.getEntriesByType('navigation')[0].type, disabled: form.querySelector('button[type="submit"]').disabled, submitting: window.Alpine.$data(form).submitting };
  });
  const posts = [];
  const onPost = r => { if (r.method() === 'POST') posts.push((r.postData() || '').includes('confirmed=1') ? 'confirm' : 'other'); };
  page.on('request', onPost);
  await Promise.all([page.waitForNavigation(), page.locator('form:has(input[name="import_token"]) button[type="submit"]').click()]);
  page.off('request', onPost);
  const text = await page.locator('body').innerText();
  return { back, posts, done: (text.match(/物件インポート完了[^\n]*/) || [null])[0], refused: (text.match(/この確認画面からは取り込めません[^\n]*/) || [null])[0] };
}
```

JS A1:

```js
async (page) => {
  const read = () => page.evaluate(() => {
    const token = document.querySelector('input[name="import_token"]');
    const form = token.closest('form');
    const b = form.querySelector('button[type="submit"]');
    const val = n => form.querySelector('input[name="' + n + '"]').value;
    let rows; try { rows = JSON.parse(val('rows')).length; } catch (e) { rows = 'unparsable:' + val('rows'); }
    const cs = getComputedStyle(b);
    return {
      xData: form.getAttribute('x-data'), parent: form.parentElement.closest('[x-data]').getAttribute('x-data'),
      tokenLength: token.value.length, kind: val('kind'), month: val('surveyed_month'), rows,
      disabled: b.disabled, cursor: cs.cursor, opacity: cs.opacity, wrapTitle: b.closest('span').getAttribute('title'),
      status: form.querySelector('[role="status"]').textContent, submitting: window.Alpine.$data(form).submitting,
    };
  });
  await page.goto('http://127.0.0.1:8767/tenant/area-buildings/import');
  await page.waitForTimeout(800);
  const initial = await read();
  await page.locator('input[type="file"]').first().setInputFiles('/Users/masanori/site/manage/.playwright-mcp/ids-area-buildings.csv');
  await page.waitForTimeout(1200);
  await page.locator('button:has-text("プレビュー")').first().click();
  await page.waitForTimeout(800);
  return { initial, step3: await read() };
}
```

JS A2:

```js
async (page) => {
  const tokenBefore = await page.evaluate(() => document.querySelector('input[name="import_token"]').value);
  await page.evaluate(() => {
    sessionStorage.setItem('ids-pageshow', '[]');
    window.addEventListener('pageshow', (e) => {
      const log = JSON.parse(sessionStorage.getItem('ids-pageshow') || '[]');
      log.push({ persisted: e.persisted });
      sessionStorage.setItem('ids-pageshow', JSON.stringify(log));
    });
  });
  await Promise.all([page.waitForNavigation(), page.locator('form:has(input[name="import_token"]) button[type="submit"]').click()]);
  const text = await page.locator('body').innerText();
  const success = (text.match(/取込が完了しました[^\n]*/) || [null])[0];
  await page.goBack();
  await page.waitForTimeout(3000);
  const afterBack = await page.evaluate(() => {
    const token = document.querySelector('input[name="import_token"]');
    return {
      url: location.href, navType: performance.getEntriesByType('navigation')[0].type,
      token: token.value, submitting: window.Alpine.$data(token.closest('form')).submitting,
      pageshowLog: JSON.parse(sessionStorage.getItem('ids-pageshow') || '[]'),
    };
  });
  return { success, afterBack, tokenChanged: afterBack.token !== tokenBefore };
}
```

JS M:

```js
async (page) => {
  await page.goto('http://127.0.0.1:8767/admin/mansion-import');
  const upload = page.locator('form[action$="/admin/mansion-import/property"]').first();
  await upload.locator('input[type="file"]').setInputFiles('/Users/masanori/site/manage/.playwright-mcp/ids-mansion-property.csv');
  await Promise.all([page.waitForNavigation(), upload.locator('button[type="submit"]').first().click()]);
  const read = () => page.evaluate(() => {
    const form = document.querySelector('input[name="import_token"]').closest('form');
    const b = form.querySelector('button[type="submit"]');
    const cs = getComputedStyle(b);
    return { parent: form.parentElement.closest('[x-data]').getAttribute('x-data'), submitting: window.Alpine.$data(form).submitting,
             disabled: b.disabled, cursor: cs.cursor, opacity: cs.opacity, status: form.querySelector('[role="status"]').textContent };
  });
  const before = await read();
  await page.evaluate(() => { document.querySelector('input[name="import_token"]').closest('form').addEventListener('submit', e => e.preventDefault()); });
  await page.locator('form:has(input[name="import_token"]) button[type="submit"]').click();
  await page.waitForTimeout(300);
  return { before, after: await read() };
}
```

JS N（375px。6 画面を開いて、送信を止めて押し、測る。スクリーンショットは `.playwright-mcp/ids-<名前>-375.png`）:

```js
async (page) => {
  const base = 'http://127.0.0.1:8767';
  const measure = () => page.evaluate(() => {
    const main = document.querySelector('main');
    const form = document.querySelector('input[name="import_token"]').closest('form');
    const b = form.querySelector('button[type="submit"]');
    const s = form.querySelector('[role="status"]');
    const br = b.getBoundingClientRect(), sr = s.getBoundingClientRect();
    const range = document.createRange(); range.selectNodeContents(s);
    return { main: [main.scrollWidth, main.clientWidth], status: s.textContent, textLines: new Set([...range.getClientRects()].map(x => Math.round(x.top))).size,
             buttonBottom: Math.round(br.bottom), statusTop: Math.round(sr.top) };
  });
  const press = async () => {
    await page.evaluate(() => { document.querySelector('input[name="import_token"]').closest('form').addEventListener('submit', e => e.preventDefault()); });
    await page.locator('form:has(input[name="import_token"]) button[type="submit"]').click();
    await page.waitForTimeout(300);
  };
  const uploadTo = async (url, action, file) => {
    await page.goto(base + url);
    const upload = page.locator(`form[action$="${action}"]`).first();
    await upload.locator('input[type="file"]').setInputFiles('/Users/masanori/site/manage/.playwright-mcp/' + file);
    await Promise.all([page.waitForNavigation(), upload.locator('button[type="submit"]').first().click()]);
  };
  const out = {};
  await page.setViewportSize({ width: 375, height: 812 });
  const screens = {
    tenant: () => uploadTo('/admin/tenant-import', '/admin/tenant-import/property', 'ids-tenant-narrow.csv'),
    mansion: () => uploadTo('/admin/mansion-import', '/admin/mansion-import/property', 'ids-mansion-property.csv'),
    zeal: () => uploadTo('/admin/zeal/member-import', '/admin/zeal/member-import/preview', 'ids-zeal-members.csv'),
    schedule: () => uploadTo('/housing/properties/1/schedule-import', '/housing/properties/1/schedule-import/preview', 'ids-schedule.xlsx'),
    sheet: async () => {
      await page.goto(base + '/zeal/simulations/1');
      await Promise.all([page.waitForNavigation(), page.evaluate(() => {
        const form = document.querySelector('form[action$="/zeal/simulations/1/sheet-import/preview"]');
        form.querySelector('select[name="year_month"]').value = '2026-07';
        form.requestSubmit();
      })]);
    },
    area: async () => {
      await page.goto(base + '/tenant/area-buildings/import');
      await page.waitForTimeout(800);
      await page.locator('input[type="file"]').first().setInputFiles('/Users/masanori/site/manage/.playwright-mcp/ids-area-buildings.csv');
      await page.waitForTimeout(1200);
      await page.locator('button:has-text("プレビュー")').first().click();
      await page.waitForTimeout(800);
    },
  };
  for (const [name, open] of Object.entries(screens)) {
    await open();
    await press();
    out[name] = await measure();
    await page.screenshot({ path: `/Users/masanori/site/manage/.playwright-mcp/ids-${name}-375.png` });
  }
  await page.setViewportSize({ width: 1440, height: 900 });
  return out;
}
```

⚠ 賃貸マンションとテナントの物件の CSV は、1 回目（1・2・5）で取り込んだ名前と重なると確認画面に確定のフォームが出ない（重複で 0 件）。JS N は `ids-tenant-narrow.csv`（別の名前）を使い、賃貸マンションは 5 で送信を止めたので取り込まれていない。

JS Z（JS N のあと、375px の ZEAL 会員の確認画面を開き直して押してから）:

```js
async (page) => {
  await page.setViewportSize({ width: 375, height: 812 });
  const r = await page.evaluate(() => {
    const form = document.querySelector('input[name="import_token"]').closest('form');
    const row = form.querySelector('div[style*="display: flex"]');
    const s = form.querySelector('[role="status"]');
    const lines = () => { const range = document.createRange(); range.selectNodeContents(s); return new Set([...range.getClientRects()].map(x => Math.round(x.top))).size; };
    const wrap = { flexWrap: getComputedStyle(row).flexWrap, width: Math.round(s.getBoundingClientRect().width), textLines: lines() };
    row.style.flexWrap = 'nowrap';
    const nowrap = { flexWrap: getComputedStyle(row).flexWrap, width: Math.round(s.getBoundingClientRect().width), textLines: lines() };
    row.style.flexWrap = '';
    return { status: s.textContent, wrap, nowrap };
  });
  await page.setViewportSize({ width: 1440, height: 900 });
  return r;
}
```

期待: `wrap` は `textLines: 1`・`nowrap` は `width: 69` 前後・`textLines: 2`（試作の実測）。⚠ `status` が「取り込んでいます…」でなければ、先に確認画面で送信を止めて押す（JS N の `press` と同じ）。

JS W（1440px。ZEAL 会員と本部 Sheet。JS N と同じ `uploadTo`・`sheet` の開き方で開いて、送信を止めて押す）: 送信中の文字の左端（`statusBox` の left）がボタンの右端より右・同じ行・`textLines: 1`・`main` が `[1220, 1220]`。

スクリーンショットは scratchpad へ写してから Read で見る:

```bash
SP=<Scratchpad directory>; mkdir -p "$SP/ids-shots" && cp /Users/masanori/site/manage/.playwright-mcp/ids-*-375.png "$SP/ids-shots/"
```

- [ ] **Step 4: 片付け**

開発サーバは起動したバックグラウンドの作業を止める（`TaskStop`）。Playwright は `browser_close`。そのあと:

```bash
SP=<Scratchpad directory>; WT=/Users/masanori/site/manage/.claude/worktrees/import-double-submit; D=/Users/masanori/site/manage/.playwright-mcp; cd "$WT" && git checkout -- routes/web.php && rm -rf public/build && find storage/framework/cache/data -mindepth 1 ! -name .gitignore -delete && find storage/framework/sessions -mindepth 1 ! -name .gitignore -delete && rm -f "$SP/ids-browser.sqlite" && for f in ids-tenant-property.csv ids-tenant-narrow.csv ids-mansion-property.csv ids-area-buildings.csv ids-zeal-members.csv ids-schedule.xlsx ids-tenant-375.png ids-mansion-375.png ids-zeal-375.png ids-schedule-375.png ids-sheet-375.png ids-area-375.png; do [ -e "$D/$f" ] && rm -f "${D:?}/${f:?}"; done; git status --porcelain; lsof -nP -iTCP:8767 -sTCP:LISTEN | head -2; echo done
```

`browser_*` のたびに `.playwright-mcp/` へ溜まる `page-<UTC 時刻>.yml`・`console-<UTC 時刻>.log` は、**今回の時刻の分だけ**消す（ほかのセッションのものは消さない。変数のパスの `rm` は `rm -f "${D:?}/${f:?}"` の形で書く）。`status` は何も出さない・8767 番を掴んでいるものが無いこと。結果を「実測記録」に書く（Task 15 でコミット）。

---

## Task 14: 独立レビュー

**Files:** 指摘に応じて

- [ ] **Step 1: レビューを頼む**（Agent・`general-purpose`。コードは変えさせない。指摘には再現の手順を付けさせる）

頼む内容（そのまま渡す）:

> ほかの取込の二重送信を止める改修を、欠陥を見つける目でレビューしてください（説明ではなく欠陥の指摘がほしい）。
> 対象: worktree `/Users/masanori/site/manage/.claude/worktrees/import-double-submit` の `git diff 13.x...HEAD`（アプリとテスト）。
> 設計書 `docs/superpowers/specs/2026-09-28-import-double-submit-design.md` と計画 `docs/superpowers/plans/2026-09-28-import-double-submit.md` を読んでから見てください。
> 見てほしいこと: ①設計書との食い違い ②鍵を使う位置（6 本のどれかで、2 回目が鍵より先に別の検査・別の文言・書き込みに着く経路が残っていないか。テナント・賃貸マンションは `loadCsv()` を通らない確定の経路が無いか）と、顧客・決裁の `claimFrom()` を壊していないか ③シート取込の指紋（鍵との順番・`is_string`・指紋に入れるもの・`preview()` と `apply()` で同じメソッドを使っているか）④画面の二度押し止め（部品 `_partials/_submit_once` の `@once`・scripts のスタック・入れ子の Alpine・`x-on:submit`・`:disabled`・`pageshow`・`role="status"`・周辺ビルの `reloadOnReturn`）と CLAUDE.md の Top trap #4・#5・#12・#13、docs/RULES.md Bug #30・#32・#65 ⑤送信中に固まる・二度と押せなくなる経路（「戻る」・送信の中止・通信の失敗・周辺ビルの読み込み直しの繰り返し）⑥テストが緑のまま壊せる形が残っていないか（Bug #43・#47・#48・#54 の型。計画の Task 12 の表で緑の 4 通り（K20・D07・T02・UZ04）の扱いも）⑦文書の誤り。
> 決まり: コードは変えない（探りは scratchpad のコピーか、テストを一時的に足して流し、元に戻す）。`.env` は読まない。全件テストは `cd <worktree> && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" ./vendor/bin/phpunit`。
> 報告: 指摘ごとに 重さ（Critical / Important / Minor）・場所・再現の手順（落ちるテストか、実行した探りとその出力）・直し方の案。推測だけの指摘は「未実測」と書いてください。

- [ ] **Step 2: 指摘を 1 つずつ実測してから直す**（実測で再現しないものは理由を書いて見送る）
  - 直すたびに、落ちるテストを先に足し（直す前に赤・直した後に緑を確かめる）、関係するテストを流してコミットする
  - 本番のコードを変えたら、その場所に当たる変異を `ids-mutate.py` で当て直す（隔離した worktree を作り直すか、Task 12 の手順で進める）
  - 最後に全件テストとコンパイル済みビューの lint（Task 10）と、入口で必ず断るカナリア（Task 11）をやり直す
  - 指摘・実測・対応を「実測記録」に書く

---

## Task 15: 記録

**Files:**
- Modify: `docs/RULES.md`（Bug の行を 1 つ足す）
- Modify: `CLAUDE.md`（Bug の件数 2 か所）
- Modify: `docs/BACKLOG.md`（この作業の節・顧客の二重送信の節の範囲外の 1 行に矢印・完了状況）
- Modify: この計画（実測記録）

数字（本数・変異・カナリア・ブラウザ）は Task 10〜14 の実測に合わせる。下の文は試作の実測で書いてあるので、違えば直す。日付は記録を書く日に合わせる。

- [ ] **Step 1: Bug の番号を最新の `13.x` で数え直す**（別の会話が先に RULES へ足していることがある）

```bash
git -C /Users/masanori/site/manage log --oneline -1 13.x && git -C /Users/masanori/site/manage show 13.x:docs/RULES.md | grep -c '^| [0-9]' && cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && grep -c '^| [0-9]' docs/RULES.md && git merge-base --is-ancestor 13.x HEAD && echo 'ahead-of-13.x'
```

期待（2026-09-28 時点）: `13.x` の最後のコミット・`68`・`68`・`ahead-of-13.x` → **この作業は Bug #69**。
⚠ `13.x` の数が 68 より大きい、または `ahead-of-13.x` が出ないときは、先に Task 0 Step 1 の手順で `13.x` を取り込み（全件テストを流し直す）、次の番号を使う。下の文の「69」をその番号に、`CLAUDE.md` の件数もそれに合わせて読み替える。⚠ `approval-phase2a` が先に入っていたら `docs/BACKLOG.md` がぶつかることがある（両方の段落を残す）。

- [ ] **Step 2: `docs/RULES.md` に Bug #69 を足す**（Edit。表の最後の行（#68）の後ろ）

置き換える前:

```markdown
横展開: ファイル名を `download`/`response` に渡すのは `AttachmentDelivery` だけ（2026-09-28 実測）。本番反映は `./deploy.sh`（DB 変更・新しいクラスなし） |

## Postal Code APIs
```

置き換えた後:

```markdown
横展開: ファイル名を `download`/`response` に渡すのは `AttachmentDelivery` だけ（2026-09-28 実測）。本番反映は `./deploy.sh`（DB 変更・新しいクラスなし） |
| 69 | 顧客の取込を除く 6 本の取込（テナント 5 タブ・賃貸マンション 6 タブ・ZEAL 会員・工程表（建売）・ZEAL の本部 Sheet 取込・周辺ビル 2 種＝16 経路）で、**同じ確認画面の確定を 2 回**送ると（ダブルクリック・「戻る」で出る確認画面からの押し直し）、テナントの契約・過去契約と賃貸マンションの部屋契約・駐車場契約は 2 件が 4 件に、周辺ビルのテナント明細は 3 件が 6 件になり、シート取込は履歴（`zeal_sheet_imports`）が二重になった。残りの 10 経路は重複の確認で二重にはならないが、2 回目にも成功の帯（「0件を登録しました」「スキップ 5件」など）が出て、取り込み直したように見えた（2026-09-28 に使い捨てのテストで 16 経路を実測）。シート取込はさらに、確定が本部 Sheet を読み直して反映の内容を作り直すので、プレビューのあとで本部 Sheet が変わると**確認画面で見せていない値を書いた**（2026-09-28 修正） | 1 回だけ送らせる仕組みが画面にもサーバにも無かった（顧客の取込だけ Bug #67 で対策済み）。二重になる経路は、二重契約・期間の重なりの確認が警告だけ（テナントの契約・過去契約）、契約中の行に注意を出すだけ（賃貸マンションの契約）、既存の行と突き合わせる手がかりが無い（周辺ビルのテナント明細）、同じ値のセルは書かないが履歴は毎回作る（シート取込）| **確定は確認画面 1 つにつき 1 回だけ**（顧客の取込と同じ `OneTimeAction`・hidden `import_token`）: プレビュー（周辺ビルは確認画面が無いので取込の画面の `form()`）で鍵を出し、確定の入口（テナント・賃貸マンションは `loadCsv()` の確定の分岐の最初、ほかは確定のメソッドの最初）で、CSV の読み直しや入力チェックより前に使う。使えなければ何も書かずに取込の画面（シート取込は試算表の画面）へ戻し、レイアウトの赤帯に「この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは〈確かめる画面〉で確かめられます。…」（確かめる画面はタブごと。過去契約は「契約一覧」でステータスを「解約済み」にして）。シート取込は、鍵のあとで指紋（`planDigest()`＝どの試算表の・どの月に・どの項目を・いくらにするかの sha256。書く行だけ・いまの値は入れない）を比べ、違えば「プレビューのあとで反映する内容が変わりました…」で断る（⚠ 鍵を先に使う。逆だとダブルクリックの 2 回目が別の理由で断られる。⚠ `hash_equals()` の前に `is_string()`＝空の指紋は `ConvertEmptyStringsToNull` で null になって届き、配列と同じく TypeError で 500 になる）。画面は共通の部品 `_partials/_submit_once`（`function submitOnce()` の唯一の定義・`@once` と scripts のスタック）で 2 回目の送信を取り消し、送信中はボタンを押せなくして横の `role="status"` に「取り込んでいます…」（`inline-block`。ZEAL 会員はボタンの並びを `flex-wrap: wrap`＝外すと 375px で文字が 69px に縮んで 2 行に割れた）。周辺ビル（`submitOnce({ reloadOnReturn: true })`）は、送ったあと「戻る」で戻った画面を読み込み直して新しい鍵にする（Chromium は bfcache を使わず、戻ったときと読み込み直しでサーバへ 2 回 GET が行くことを実測）。⚠ **入れ子の Alpine**（テナント・賃貸マンションは `tenantImportTabs()` / `mansionImportTabs()`、周辺ビルは `areaImportForm()` の中）でも、親の値（`payload()` など）と押せない理由は入れ子の中から読める（実ブラウザで確かめた）。⚠ **2 回送るテストだけでは「鍵が書き込みより前」を守れない経路がある** — ZEAL 会員は 2 回目が全員を重複で飛ばして書き込みが 0 なので、鍵を書き込みのあとへ動かす変異は 2 回送るテストでは緑。「使えない鍵」の 3 本が捕まえる。⚠ `ParsesForms::htmlAttr()` を protected にした（基底クラスが使うトレイトのメソッドを子から呼ぶため）。回帰テスト: `TenantImportDoubleSubmitTest`・`MansionImportDoubleSubmitTest`（タブは `execute{X}` を Reflection で全件分類）・`SheetImportTest`（この取込の初めての Feature テスト。指紋の 6 場面）・ZEAL 会員・工程表・周辺ビルの既存のテストに各 6〜7 本・`SubmitOnceTest`（部品を node の vm で動かす・鍵を描く画面の全件分類）・`ImportControllerOneTimeKeyScanTest`（取込のコントローラの全件分類）・`LoginGuideTest` の `claimFrom()` の件数の下限 6 → 12。確定の入口で必ず断る一時変更を全件に当て、入口に来たテスト 136 本がすべて赤（緑のまま 0 本）。変異 71 通り＋カナリア（計画書 `docs/superpowers/plans/2026-09-28-import-double-submit.md` の実測記録）。本番反映は `./deploy.sh`（DB 変更・新しい PHP クラス・依存の変更なし。CSS も変わらない） |

## Postal Code APIs
```

- [ ] **Step 3: `CLAUDE.md` の件数**（Edit を 2 回）

```text
全 68 件の詳細バグカタログ   →   全 69 件の詳細バグカタログ
Bug #1–68                    →   Bug #1–69
```

- [ ] **Step 4: `docs/BACKLOG.md`**（Edit を 3 回）

1 回目（「顧客CSVインポートの二重送信を止める」の範囲外の行）— 置き換える前:

```text
- ほかの取込（テナント・賃貸マンション・ZEAL 会員・工程表・周辺ビル）の二重送信（決裁の取込は対策済み。ほかは未実測）
```

置き換えた後:

```text
- ほかの取込（テナント・賃貸マンション・ZEAL 会員・工程表・周辺ビル）の二重送信（決裁の取込は対策済み。ほかは未実測）（→ 2026-09-28 に直した。ZEAL の本部 Sheet 取込も含む。下の「ほかの取込の二重送信を止める」の節）
```

2 回目（この作業の節を足す。添付の節の後ろ・完了状況の前）— 置き換える前:

```markdown
③ `deploy.sh` は main repo の `.superpowers/`（作業のメモ）を除外していないので、本番の `~/apps/manage` に送られる（Web からは見えない）

---

## バックログ完了状況
```

置き換えた後:

```markdown
③ `deploy.sh` は main repo の `.superpowers/`（作業のメモ）を除外していないので、本番の `~/apps/manage` に送られる（Web からは見えない）

---

## ✅ ほかの取込の二重送信を止める — 本番反映待ち

詳細仕様: @docs/superpowers/specs/2026-09-28-import-double-submit-design.md
実装計画（試作・カナリア・変異テスト・ブラウザ確認の記録つき）: @docs/superpowers/plans/2026-09-28-import-double-submit.md

上の「顧客CSVインポートの二重送信を止める」の範囲外に残した「ほかの取込の二重送信」を直した（docs/RULES.md Bug #69）。
利用者の判断（2026-09-28）: 16 経路すべてを顧客の取込と同じ形にする（案 A）・シート取込は確認画面で見せた内容だけを書く（案 a）・画面の二度押し止めは共通の部品 1 つ（案 1）・同じファイルの上げ直しは範囲外（案 C）・`LoginGuideTest` の docblock の誤りはこの作業の別のコミットで直す（案 A）。
**DB 変更・ルート変更・新しい PHP クラス・依存の変更は無し**（`composer dump-autoload` は要らない。CSS も変わらない）。

| 区分 | 実装内容 |
|------|---------|
| Controller | テナント・賃貸マンション（`loadCsv()` の確定の分岐の最初で鍵・タブごとの確かめる画面の表）・ZEAL 会員・工程表・周辺ビル（鍵は取込の画面の `form()` で出す）・シート取込（鍵のあとで指紋 `planDigest()`）|
| Blade | 新しい部品 `_partials/_submit_once`（`submitOnce()` の唯一の定義）＋ 6 つの確定の画面に hidden の `import_token`（シート取込は `plan_digest` も）と二度押し止め |
| Support | `OneTimeAction` は docblock だけ（処理は変えない）|
| テスト | 新規 `TenantImportDoubleSubmitTest`・`MansionImportDoubleSubmitTest`・`SheetImportTest`・`SubmitOnceTest`・`ImportControllerOneTimeKeyScanTest`・`Tests\Concerns\ChecksDoubleSubmit` ／ ZEAL 会員・工程表・周辺ビルの既存のテストに追加・手で組んだ確定に鍵 ／ テスト用スキーマを本部 Sheet 取込の DDL に合わせた。2362 → **2431 tests / 16299 assertions green** |
| ルート / DB | **どちらも変更なし** |

### 直したこと

| 取込 | 直す前（2026-09-28 に使い捨てのテストで実測）| 直した後 |
|---|---|---|
| テナントの契約・過去契約／賃貸マンションの部屋契約・駐車場契約 | 同じ確認画面から 2 回送ると 2 件が 4 件 | 2 回目は「この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは〈タブごとの画面〉で確かめられます。…」。取り込みは 1 回 |
| 周辺ビルのテナント明細 | 3 件が 6 件 | 2 回目は「この取込画面からは取り込めません（…）」。送ったあと「戻る」で戻った画面は読み込み直して新しい鍵になる |
| シート取込 | 履歴が二重・プレビューのあとで本部 Sheet が変わると見せていない値を書く | 2 回目は鍵の案内・内容が変わっていたら「プレビューのあとで反映する内容が変わりました（…）。もう一度プレビューしてください。」 |
| そのほかの 10 経路 | 二重にはならないが、2 回目にも成功の帯（「0件を登録しました」など）| 2 回目は鍵の案内 |
| 送信中 | 何も変わらない | ボタンが押せなくなり（不透明度 0.6・周辺ビルは 0.5・カーソル not-allowed）、横に「取り込んでいます…」（シート取込は「反映しています…」）|

### 要点

- 鍵は確定の入口で、CSV の読み直し・入力チェック・行の検査より前に使う（2 回目がどの検査にも別の文言にも届かない）。シート取込は鍵を先に使ってから指紋を比べる
- 周辺ビルは確認画面が無いので、取込の画面を開いたときに鍵を出す
- 戻り先と文言は取込ごと（テナントの過去契約は「契約一覧」でステータスを「解約済み」にして確かめる、と案内する）
- 画面の歯止め（Alpine）が効かないとき（JS が動かない・Alpine の起動前）も、サーバの鍵が 2 回目を断る。本番のキャッシュは file ドライバで、`FileStore::add()` が排他ロックを取ってから書く

### 範囲外（設計書 §6・§7 ＋ 試作で気づいたもの）

- 同じファイルを上げ直したときの二重（契約系の 4 経路。テナントの過去契約は注意すら出ない。案 C）
- まだ送っていない古い確認画面で、取り込める行が 0 件なのに「0件を登録しました」と成功の帯が出ること（テナント・賃貸マンション）
- 2 つのタブで別々にプレビューした同じファイルを、ほぼ同時に確定すること（鍵が別なので両方通る。シート取込は、順に届けば指紋で止まる）
- 画面の歯止めが効かないときの 2 回目の応答で、1 回目の完了の表示が見えないこと
- 送信を中止（Esc など）すると、押せないボタンと送信中の文字が残ること（取込の画面を開き直せば抜けられる）
- 12 時間より古い確認画面（鍵の記録が消えたあとは、それぞれの重複の確認に頼る）
- 顧客の取込の画面の書き方を、共通の部品にそろえること
- アップロード（プレビュー）の二度押し（DB に書き込まないので害が無い）
- 設計書 §7 の推測（テナントで同じ区画に契約中が 2 本並ぶと区画の収支が 2 倍・ほぼ同時の 2 回・ZEAL 会員の同姓同名＋同じ入会日・工程表の修正の消失）と、ZEAL 会員のテストのスキーマに `settings` 表が無いこと
- 周辺ビルで、Chromium は「戻る」で bfcache を使わず画面をサーバから取り直すので、部品の読み込み直しは 1 回余計になる（害は無い。HTTP のキャッシュから描くブラウザのための備え）

### 検証

- 全件テスト 2362 → **2431 tests / 16299 assertions green** ／ コンパイル済みビュー **275 本 / INVALID 0 件**
- 確定の入口で必ず断る一時変更（計画の Task 11）: （未記入）
- 変異 71 通り＋カナリア（計画の Task 12。隔離した worktree 3 つ・全件で流す）: （未記入）
- ローカルの実ブラウザ（計画の Task 13。使い捨て SQLite ＋ `artisan serve` ＋ Playwright）: （未記入）
- 独立レビュー（計画の Task 14）: （未記入）

---

## バックログ完了状況
```

3 回目（「バックログ完了状況」の末尾の段落）— 置き換える前:

```text
その他の新規要件は別途追記する。
```

置き換えた後（Task 16 のあとで「本番反映済み（`13.x` = …）」に直す）:

```text
**ほかの取込の二重送信を止める（2026-09-28）**は本番反映待ち（上の節）。

その他の新規要件は別途追記する。
```

- [ ] **Step 5: この計画の「実測記録」を埋める**（Task 0・10〜14 の結果。Task 16 の小節は Task 16 で埋める）。BACKLOG の節の「検証」の（未記入）4 か所も実測で埋める

- [ ] **Step 6: 書き残しが無いことを確かめる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && grep -n -e '（未記入）' docs/RULES.md CLAUDE.md docs/BACKLOG.md; sed -n '/^## 実測記録/,$p' docs/superpowers/plans/2026-09-28-import-double-submit.md | grep -c -e '（未記入）'
```

期待: 1 つ目の `grep` は何も出さない。2 つ目は Task 16 の小節の 1 か所だけ（`1`）。Task 16 の後は `0`。

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && git add docs/RULES.md CLAUDE.md docs/BACKLOG.md docs/superpowers/plans/2026-09-28-import-double-submit.md && git commit -m "$(cat <<'EOF'
docs: ほかの取込の二重送信を止めた記録を残す

RULES に Bug #69（16 経路の 2 回目・シート取込の見せていない値・入れ子の Alpine と周辺ビルの「戻る」）を足し、
BACKLOG にこの作業の節と範囲外を足す。

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain
```

---

## Task 16: 本番反映（⚠ 承認をもらってから）

**Files:** なし（`13.x` の早送り・本番への反映・読み取りの確認）

⚠ 実行の前に、利用者の承認を本文でもらう（①main repo で `13.x` を早送り ②`./deploy.sh` ③読み取りだけの確認（ssh での md5 とコンパイル済みビューの lint・ログイン済みの実 Chrome で 6 つの取込の画面を開く）。DB 変更・ルート変更・新しい PHP クラス・依存の変更は無い＝`composer dump-autoload` は要らない。push はしない）。

- [ ] **Step 1: 早送り**（main repo で）

```bash
cd /Users/masanori/site/manage && git status --porcelain && git checkout 13.x && git merge-base --is-ancestor 13.x import-double-submit && git merge --ff-only import-double-submit && git log --oneline -1
```

⚠ `is-ancestor` が失敗したら（`13.x` が進んだ）止める。worktree で `13.x` をマージし（Task 0 の手順）、全件テスト（Task 10）を流してからやり直す。取り込むコミットに本番へまだ出ていない別の作業（`approval-phase2a` の DB の SQL など）が含まれるときは、それも一緒に出てよいか利用者に確かめる（`deploy.sh` は `13.x` の全体を送る）。

- [ ] **Step 2: 本番へ送る vendor に dev の部品が無いこと**

```bash
cd /Users/masanori/site/manage && ls vendor/bin/phpunit 2>/dev/null; echo "phpunit-check-done"
```

期待: `ls` は何も出さない（dev の部品が無い）。出たら止める（`deploy.sh` が本番へ送ってしまう）。

- [ ] **Step 3: 反映**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

期待: exit 0・6 段すべて・3 つのキャッシュとも成功。送るアプリのファイルはコントローラ 6 本・`app/Support/OneTimeAction.php`・ビュー 7 本（部品を含む）。CSS・JS の名前は変わらない（`app-D-wd4D2y.css`・`app-NiVQbl_Q.js`）＝旧バンドルの削除は 0 件。

- [ ] **Step 4: 読み取りだけの確認**

```bash
F="app/Http/Controllers/Admin/TenantImportController.php app/Http/Controllers/Admin/MansionImportController.php app/Http/Controllers/Admin/ZealMemberImportController.php app/Http/Controllers/Housing/ScheduleImportController.php app/Http/Controllers/Zeal/SheetImportController.php app/Http/Controllers/Tenant/AreaBuildingImportController.php app/Support/OneTimeAction.php resources/views/_partials/_submit_once.blade.php resources/views/admin/tenant-import/_preview.blade.php resources/views/admin/mansion-import/_preview.blade.php resources/views/admin/zeal-member-import/preview.blade.php resources/views/housing/properties/schedule-import.blade.php resources/views/zeal/simulations/sheet-import/preview.blade.php resources/views/tenant/area-buildings/import.blade.php"; ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<SH
cd ~/apps/manage && md5 -q $F && n=0; bad=0; for f in storage/framework/views/*.php; do n=\$((n+1)); /usr/local/php/8.3/bin/php -l "\$f" >/dev/null 2>&1 || { bad=\$((bad+1)); echo "INVALID: \$f"; }; done; echo "views=\$n invalid=\$bad"
SH
cd /Users/masanori/site/manage && md5 -q $(echo "$F")
```

期待: 本番と手元の md5 が 14 本とも一致・`views=275 invalid=0`。⚠ zsh は引用なしの `$F` を単語分割しない（手元の `md5 -q` は `$(echo "$F")` で分けて渡す。ssh の側は本番の `/bin/sh` が分ける）。

- ログイン済みの実 Chrome（`claude-in-chrome`・読み取りだけ。ファイルは上げない・フォームは送らない）で、6 つの取込の画面を開き、どれも 200・初期フォームが出る・コンソールのエラー 0 件を見る（⚠ URL は `/index.php/` を挟む）:
  - `https://www.mitsuwat.co.jp/system/manage/index.php/admin/tenant-import`
  - `…/index.php/admin/mansion-import`
  - `…/index.php/admin/zeal/member-import`
  - `…/index.php/housing/properties`（一覧から建売物件の詳細を開き、「工程表を取り込む」の画面）
  - `…/index.php/zeal/simulations`（試算表の画面。「本部 Sheet を取り込む」は開かない＝プレビューは本部 Sheet を読みにいく）
  - `…/index.php/tenant/area-buildings/import` — **この画面だけは鍵と部品が初期表示に出る**: 確定のフォームの `x-data` が `submitOnce({ reloadOnReturn: true })`・hidden の `import_token` が 40 文字（`javascript_tool` で読む。値そのものは伏せて長さだけ）
  - 確認画面の二度押し止めと鍵は、プレビュー（ファイルを上げる）のあとにしか出ないので本番では見ない（テストとローカルの実ブラウザで確かめてある）。本番で実際に取り込むのは利用者が行う

- [ ] **Step 5: 記録**（worktree で BACKLOG の節の見出しを「本番反映済み」にし、本番反映の小節（日時・`13.x` のコミット・Step 2〜4 の結果）を足し、完了状況の段落を「本番反映済み（`13.x` = …）」に直し、この計画の「実測記録」の Task 16 を埋めてコミットする。そのあと main repo で `git merge --ff-only import-double-submit`（文書だけなので `deploy.sh` は要らない。`docs/` は rsync しない））

```bash
cd /Users/masanori/site/manage/.claude/worktrees/import-double-submit && git add docs/BACKLOG.md docs/superpowers/plans/2026-09-28-import-double-submit.md && git commit -m "$(cat <<'EOF'
docs: ほかの取込の二重送信を止めた改修を本番に出した記録を残す

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)" && git status --porcelain && cd /Users/masanori/site/manage && git merge --ff-only import-double-submit && git log --oneline -1
```

⚠ push はしない（利用者の指示があったときだけ）。

---

## 範囲外（BACKLOG に書く。設計書 §6・§7 ＋ 試作で気づいたもの）

- 同じファイルを上げ直したときの二重（契約系の 4 経路。テナントの過去契約は注意すら出ない。案 C。業務の判断が要る）
- まだ送っていない古い確認画面で、取り込める行が 0 件なのに「0件を登録しました」と成功の帯が出ること（テナント・賃貸マンション。Bug #66 の 6 と同じ形。鍵によって「2 回目」は断られるようになる）
- 2 つのタブで別々にプレビューした同じファイルを、ほぼ同時に確定すること（鍵が別なので両方通る。シート取込は、順に届けば指紋で止まるが、ほぼ同時だと両方が書き、履歴も 2 回できうる）
- 画面の歯止めが効かないとき（JS が動かない・Alpine の起動前）の 2 回目の応答で、1 回目の完了の表示が見えないこと（顧客の取込と同じ）
- 送信を中止（Esc など）すると、押せないボタンと送信中の文字が残ること（顧客の取込と同じ。取込の画面を開き直せば抜けられる）
- 12 時間より古い確認画面（鍵の記録が消えたあとは、それぞれの重複の確認に頼る）
- 顧客の取込の画面の書き方（独自の `csvImport()`）を、共通の部品にそろえること
- アップロード（プレビュー）の二度押し（DB に書き込まないので害が無い）
- 設計書 §7 の推測（テナントで同じ区画に契約中が 2 本並ぶと区画の収支が 2 倍になる・ほぼ同時の 2 回・ZEAL 会員の重複の判定は氏名＋入会日だけ・取り込んだ工程を直したあとの古い確認画面）と、ZEAL 会員のテストのスキーマに `settings` 表が無いこと
- 周辺ビルで、Chromium は「戻る」で bfcache を使わず画面をサーバから取り直すので、部品の読み込み直しは 1 回余計になる（試作で実測。害は無い。HTTP のキャッシュから描くブラウザのための備え）
- 賃貸マンションの取込のタブのキーの区切りがそろっていないこと（URL は `room-contract`、戻り先は `room_contract`。今回は表で吸収した）

## 完了の条件

- 全件テストが緑（`OK (2431 tests, 16299 assertions)` 前後。違えば理由を記録）・コンパイル済みビュー `views=275 invalid=0`
- 確定の入口で必ず断るカナリアで、入口に来たテストがすべて赤（緑のまま 0 本）
- 変異 71 通り＋カナリアがすべて期待どおり（等価の K20・D07・T02 と、PHP から見えない UZ04 は緑。違ったものは理由を調べて対応し、記録した）
- ローカルの実ブラウザの 9 項目がすべて期待どおり
- 独立レビューの指摘に、実測のうえで対応した
- RULES・CLAUDE.md・BACKLOG・この計画に記録した
- （承認のあと）本番に反映し、読み取りで確かめた

---

## 実測記録

### 試作の先測り（2026-09-28。この計画を書くときに測った）

写し: `git worktree add --detach … e039a0da` に vendor を `cp -Rc`。計画のコードを入れて `b26d6f6e`（写しの中だけのコミット）にしてから測った。

- 各 Task の「直す前に落ちる」「直すと通る」: 写しで、その Task のアプリのファイルだけを `e039a0da` に戻して流した値（各 Task の Step の「期待」）。試作の途中で測った値と、最終形の測りの道具で測り直した値は、すべて同じだった
- 全件 `OK (2431 tests, 16299 assertions)`・コンパイル済みビュー `views=275 invalid=0`
- 入口で必ず断るカナリア（全件）: 入口に来た 136 本がすべて赤・緑のまま 0 本・入口に来ずに赤 0 本（Task 11 の期待）
- 変異 71 通り＋カナリア（関係するテスト 26 本に絞って、隔離した写し 3 つで流した）: カナリアは 3 つとも同じ 12 本が赤（全件でも同じ 12 本）。**期待と違ったものは無い**。緑は 4 通りで、どれも理由がある: K20（工程表の鍵を入力チェックのあとへ＝等価）・D07（指紋から試算表の id と月を抜く＝等価）・T02（テスト用スキーマの一意の索引を消す＝今のテストは頼っていない）・UZ04（ZEAL 会員の `flex-wrap` を外す＝PHP から見えない。ブラウザで割れることを実測）。気づいたこと: K18（ZEAL 会員の鍵を書き込みのあとへ）は 2 回送るテストでは緑で、「使えない鍵」の 3 本だけが捕まえる。K07・K08・K11・K12（鍵を固定値に）は、新しいテストのほかに、1 つのテストで 2 回確定する既存のテスト（`TenantUnitImportTest`・`MansionImportTest`・シート取込の「別のタブ」・周辺ビルの 2 本）も落とす。UA01（周辺ビルの鍵の hidden の名前を変える）は 30 本が落ちる（`importBuildings()` / `importTenants()` が画面の鍵で送るので、画面が鍵を描くことも固まった）
- CSS: 写しで `vite build` → `app-D-wd4D2y.css`（本番と同じ名前）
- 実ブラウザ（写しを使い捨ての SQLite ＋ `artisan serve` で動かし Playwright で見た）:
  - テナント（入れ子）: 確定のフォームの親は `tenantImportTabs()`・`submitting: false`・鍵 40 文字。応答を 3 秒遅らせたダブルクリックで確定の POST は 1 回・押した直後に `disabled`・`not-allowed`・`0.6`・「取り込んでいます…」・「物件インポート完了: 2件を登録しました」。「戻る」は `back_forward` で、サーバに POST は届かない（手元の控えから描き直す。Playwright の `request` には出る）・押せる → 押すと「この確認画面からは取り込めません（…）。取り込まれたかは「物件一覧」で確かめられます。…」
  - 周辺ビル（入れ子）: 開いた直後は押せない（`not-allowed`・`0.5`・包む span の `title` が「取り込める行がありません。」）→ ファイルとプレビューのあと、hidden の `kind`・`surveyed_month`・`rows`（2 行）に親の値が入り押せる。送ると「取込が完了しました。ビル新規 2 件 / 調査追加 2 件 …」。「戻る」は bfcache ではなく（見張りの `pageshow` が記録されない）、サーバへ `GET /tenant/area-buildings/import` が 2 回（戻ったときと部品の読み込み直し）・最後の `navType` は `reload`・鍵は新しい
  - ZEAL 会員: 1440px は送信中の文字がボタンの右に 1 行・375px はボタンの下の行に 1 行。`flex-wrap` を `nowrap` にすると 375px で 69px に縮んで 2 行に割れる
  - 本部 Sheet: 確定のフォームに指紋 64 文字・鍵 40 文字。1440px はボタンの右に 1 行・375px はボタンの下に 1 行
  - 375px のテナント・周辺ビル: 送信中の文字はボタンの下の行に 1 行・`main` の横スクロール無し
  - コンソールの警告・エラー 0 件
  - 見ていないもの: 賃貸マンション・工程表の確認画面（Task 13 の 5・6 で初めて見る）

### Task 0: 前提の確認

2026-09-28。着手の時点で `13.x` が `9e2df5b8` → `dfdf95c4`（107 コミット＝決裁 段階2a とその記録）へ進んでいたので、Step 1 の手順でこのブランチへ**マージ**した（`0148dfe0`。rebase しない・push しない）。`git merge-tree` で衝突なし・`composer.lock` は変わらない・この計画のコードとテストのファイルとの重なりは無い（重なるのは Task 15 の `CLAUDE.md`・`docs/BACKLOG.md` だけ）。

- 全件: マージの前 `OK (2362 tests, 15396 assertions)`（計画の期待どおり）→ マージの後 `OK (2765 tests, 19311 assertions)`。node は v24.11.1
- ⚠ 計画の本数（全件 2362 → 2431・ビュー 275）はマージの前の値なので、**差**（+69 本・+903 アサーション・+1 ビュー）で突き合わせることにした（各 Task のテストのファイルの本数はマージの影響を受けない）
- `13.x` から `resources/views/approvals/_submit_once.blade.php`（`function approvalSubmitOnce`・`x-data="approvalSubmitOnce()"`）が入った。この計画のテストが探すのは `function submitOnce(` と `@include('_partials._submit_once')` なので、ぶつからない（二度押し止めの部品が 2 つある状態になった。BACKLOG の範囲外に書く）
- 作業の途中で `13.x` がさらに文書だけ進んだ（`dfdf95c4` → `071f028c`。決裁の要件定義書と設計書の 3 コミット）。最後の確認の前にもう一度マージした（`420079bf`。`git merge-tree` で衝突なし。下の Task 14）

### Task 1〜9: 実装

計画のコードをそのまま入れた（ブリーフから抜き出して貼り、出現の数を数えてから置き換えた）。各 Task の「直す前に落ちる」（本数と理由）と「直すと通る」は、**すべて計画の期待どおり**だった。

| Task | コミット | 通ったテスト（その Task の対象）|
|---|---|---|
| 1 部品 | `96f404a3` | OK (14 tests, 252 assertions) |
| 2 テナント | `13a9ebed` | OK (136 tests, 600 assertions) |
| 3 賃貸マンション | `fb317475` | OK (36 tests, 719 assertions) |
| 4 ZEAL 会員 | `237e6ff0` | OK (24 tests, 164 assertions) |
| 5 工程表 | `5d901fc9` | OK (53 tests, 306 assertions) |
| 6 周辺ビル | `b1803a13` | OK (199 tests, 994 assertions) |
| 7 本部 Sheet | `3af32d21`（テスト用スキーマ）・`74361fb9` | OK (121 tests, 475 assertions) |
| 8 全件分類 | `00b53396`・`ce68f85e` | OK (62 tests, 564 assertions)。新しい 2 本の走査は、Task 2〜7 が済んでいるので最初から緑（赤は Task 12 の K01〜K06・UT01 などで確かめる） |
| 9 注記 | `60a96ad0` | OK (22 tests, 125 assertions) |

### Task 10: 全件テストと lint

`60a96ad0`（Task 9 のあと）で:

- 全件 `OK (2834 tests, 20214 assertions)`。計画の期待（2431 / 16299）とは違うが、Task 0 のマージで増えた分（+403 本・+3915 アサーション＝決裁 段階2a）を除くと **2834 = 2765 + 69・20214 = 19311 + 903** で、計画の差（+69・+903）と一致する
- コンパイル済みビュー **283 本 / INVALID 0 件**（計画は 275。`13.x` の決裁 段階2a が本番と同じ 282 本にし、この作業の部品で +1）
- ⚠ Task 14 の修正のあと、最終のコミットで流し直した（下の Task 14）

### Task 11: 確定の入口で必ず断るカナリア

`60a96ad0` で、6 本のコントローラの `OneTimeAction::claimFrom($request, 'import_token')` の直前に「来たテストを記録して必ず断る」一時変更を入れ、全件を流した（実行役は scratchpad の `ids-refuse-canary.py`。当てる前と戻した後に作業ツリーが空）。

- 着弾 `6 files changed, 18 insertions(+)` ／ `Tests: 2834, Assertions: 19551, Errors: 23, Failures: 113`
- **入口に来た 136 本・赤 136 本・入口に来たのに緑のまま 0 本・入口に来ずに赤 0 本**（計画の期待どおり）。クラスごとの本数も計画と同じ（周辺ビル 40・工程表の着工予定日 7・賃貸マンションの二重送信 10・同じく差し戻し 1・同じく取込 19・工程表 14・本部 Sheet 15・テナントの二重送信 9・同じく差し戻し 1・テナントの区画 8・ZEAL 会員 12）
- ⚠ Task 14 の修正のあと、最終のコミットで流し直した（下の Task 14）

### Task 12: 変異テスト

1 回目（`60a96ad0`。`git worktree add --detach` の写し 3 つ・vendor は `cp -Rc`・全件で流す）:

- カナリア（C0）は 3 つの写しとも表どおりの 12 本が赤（隔離が効いている）
- 変異 71 通りは、**すべて表どおり**（落ちたテストの集合・本数・理由の 1 行目）。緑は表の 4 通りだけ（K20・D07・T02 は等価・UZ04 は PHP から見えない＝Task 13 の 7 で見た）
- ⚠ J11 だけ、突き合わせの道具が「理由が違う」と出した。実行役は理由の 1 行目しか記録せず、この失敗の文は PHPUnit が最初の `\n` で改行する（`Failed asserting that '<script>\n` で切れる）ため。使い捨ての写し（`60a96ad0`。見たあと消した）で J11 だけを当てて全文を見ると `Failed asserting that '<script>\n … ' [ASCII](length: 1091) does not contain "function submitOnce(" [ASCII](length: 20).` で、表の期待どおり
- 落ちたテストの本数: K01 10・K02 11・K03 6・K04 6・K05 6・K06 7・K07 2・K08 15・K09 1・K10 1・K11 2・K12 3・K13 1・K14 1・K15 8・K16 9・K17 8・K18 3・K19 4・K20 0 ／ D01 1・D02 3・D03 7・D04 1・D05 1・D06 3・D07 0 ／ J01 1・J02 2・J03 1・J04 4・J05 1・J06 4・J07 1・J08 2・J09 2・J10 1・J11 1 ／ UT01 17・UT02〜UT11 各 1・UT12 2・UM01 29・UM02 1・UM03 1・UZ01 6・UZ02 1・UZ03 1・UZ04 0・US01 14・US02 1・US03 1・UH01 14・UH02 7・UH03〜UH05 各 1・UA01 30・UA02 1・UA03 1・UA04 2 ／ T01 2・T02 0（テストの名前と理由は Task 12 の表のとおり）
- 2 回目（独立レビューの修正のあと）は、下の Task 14 に書いた

### Task 13: ローカルの実ブラウザ

使い捨ての SQLite ＋ `artisan serve`（8767 番・`CACHE_STORE=file`）＋ Playwright（画面が見えているブラウザ）。Task 12 の変異（隔離した 3 つの写し）と同時に、この worktree で行った（CPU を取り合うだけで、ファイルは重ならない）。9 項目すべて期待どおり:

1. テナント（入れ子）: 確定のフォームの `x-data` は `submitOnce()`・親は `tenantImportTabs()`・`submitting: false`・鍵 40 文字・カーソル `pointer`・不透明度 1・送信中の文字は空
2. テナント: ダブルクリックで確定の POST は 1 回。押した直後に `disabled`・`not-allowed`・`0.6`・「取り込んでいます…」。「物件インポート完了: 2件を登録しました」
3. テナント: 「戻る」は `back_forward`・押せる・`submitting: false`。サーバに届いたのはクリックの POST だけ（戻ったときに送り直さない）。押すと断りの全文が赤帯に出る
4. 周辺ビル（入れ子）: 開いた直後は押せない（`not-allowed`・`0.5`・包む span の `title` が「取り込める行がありません。」）→ プレビューのあと hidden の `kind`=buildings・`surveyed_month`=2026-09・`rows`=2 行で押せる（`title` は無し）→「ビル新規 2 件 / 調査追加 2 件…」。「戻る」でサーバへ `GET /tenant/area-buildings/import` が 2 回・最後の `navType` は `reload`・鍵は新しい・`submitting: false`・`pageshow` の記録なし（bfcache を使わない）
5. 賃貸マンション: 親は `mansionImportTabs()`。押すと `disabled`・`not-allowed`・`0.6`・「取り込んでいます…」
6. 375px: 6 画面とも `main` の横スクロール無し（[375,375]）・送信中の文字は割れずに 1 行（テナント 513/516・賃貸マンション 553/556・ZEAL 会員 796/808・本部 Sheet 756/759・周辺ビル 677/685。数字は上端/下端の目安）。工程表だけはボタンと同じ行に収まった（ボタンの下端 796 ／ 文字の上端 767）。求めているのは「1 行・割れない・横スクロールなし」なので、これで良いと判断した
7. ZEAL 会員: `flex-wrap: wrap` で 1 行（116px）・`nowrap` にすると 69px に縮んで 2 行に割れる（試作と同じ）
8. 1440px: ZEAL 会員 618/630・本部 Sheet 1111/1129 で、送信中の文字はボタンの右の同じ行・`main` の横スクロール無し（[1220,1220]）
9. コンソールの警告・エラー 0 件

- CSS は `app-DW1DvPK7.css`（計画の `app-D-wd4D2y.css` と違う）。`13.x` だけを `git archive` してビルドしても同じ名前・同じ md5（51569daf…）なので、この作業は CSS を変えていない（`13.x` の決裁 段階2a が変えた）
- 片づけ: 一時のルートを戻し・`public/build` を消し・キャッシュとセッションを空に・使い捨ての DB と固定資産を消し・8767 番が空いていること・作業ツリーが空であることを確かめた

### Task 14: 独立レビュー

Agent（`general-purpose`・モデル fable。haiku は使わない）に `dfdf95c4..60a96ad0` を頼んだ（Task 12 の変異と同時。探りは自分の写しの中だけ・`ids-*` には触らない）。判定「**マージしてよい**」・Critical 0・Important 0・**Minor 3**。Step 2 のとおり、Minor も 1 つずつ実測で再現してから直した:

| # | 指摘 | 実測（直す前）| 対応 |
|---|---|---|---|
| 1 | シート取込で、反映のときに本部 Sheet を読み直せない（一時的な障害）と、指紋が合わず「プレビューのあとで反映する内容が変わりました（本部 Sheet か試算表の値が変わっています）」と事実と違う理由で断る | 新しいテスト 2 本（売上・経費を読み直せない）が `断りの文言（error のフラッシュ）が違う` で赤 | `planChangedMessage()`: 指紋が合わなかったとき、URL があるのに読めなかった Sheet があれば「プレビューのあとで売上 Sheet を読み直せませんでした（理由）。時間をおいて、もう一度プレビューしてください。」（`3d1322f6`）。⚠ レビュー役の案（読めない Sheet があれば断る）は**採らなかった** — 片方の Sheet が壊れているあいだ、もう片方も反映できなくなる（プレビューのときも読めなかった Sheet は、見せた内容と同じなので通す＝設計書 §4.3）。それを固定するテストと、URL の無い Sheet を「読み直せなかった」と言わないテストも足した（`20ef89dc`）|
| 2 | 部品の定義（script）が画面に描かれているかを、どのテストも見ていない | テナントの `@include` を `@if(false)` で包むと、関係するテスト 20 本がすべて緑（ブラウザでは `submitOnce is not defined`）| `assertSubmitOnceForm()` が `function submitOnce(` も見る（`60f940f8`。呼び出し側の `x-data` と定義側を対で見る＝Bug #28）|
| 3 | ボタンの `:disabled` を語の有無（`\bsubmitting\b`）で見ていたので、逆の式を通す | `:disabled="!submitting"` にしても緑 | 式の先頭を `submitting` に固定（周辺ビルの `submitting \|\| 押せない理由` も通す形。`05f9e222`）|

判断を控えた 7 件は、設計書 §6 の範囲外か Task 13 で測ったものとして見送った: 送信の中止（Esc）で押せないまま残る・2 つのタブで同じファイルをほぼ同時に確定・12 時間より古い画面・JS が動かないときの 2 回目の応答・確認画面（POST の応答）の読み込み直しで新しい鍵が出る（同じファイルの上げ直しと同じ形＝案 C）・一時的な障害でも鍵は使ったことになる（設計書 §4.2 の決まり 3。文言は 1 で事実どおりにした）・ブラウザだけの振る舞い（Task 13）

**当て直し**（`20ef89dc`。Task 12 の 3 つの写しを進め、1 回目の記録は別の名前に残した）: 3 つともカナリアは 12 本が赤。修正の場所に当たる 20 通り（K05・K11・D01〜D07・UH01・UH02・6 画面の `@include` を消す UT02・UM02・UZ02・US02・UH03・UA02・`:disabled` の UT05・UM03・UA04）と新しい 5 通りを全件で流し、**25 通りすべて期待どおり**（期待の表は ledger の `task-14-rerun.md`）:

- 増えたもの: D03（指紋を比べない）7 → 10 本（読み直せなかった 2 本と URL の無い Sheet の 1 本）・D04（指紋から金額を抜く）1 → 2 本（URL の無い Sheet のテストも金額だけが変わる）・UH01 14 → 18 本・UH02 7 → 8 本（読めなかった Sheet を通すテスト）・`@include` を消す 6 通りは 1 → 2 本（その画面のテストが「二度押し止めの部品の script が無い」で落ちる＝指摘 2 の効き目）
- 新しい 5 通り: D08（レビュー役の案＝読めない Sheet があれば断る）→ 読めなかった Sheet を通すテストだけが赤 ／ D09（URL の条件を外す）→ URL の無い Sheet のテストだけが赤 ／ D10（文言を元に戻す）→ 読み直せなかった 2 本だけが赤 ／ UT13（`:disabled="!submitting"`）→ テナントの画面のテストが `送信中にボタンを押せなくしていない` で赤（`60a96ad0` では緑）／ UT14（`@include` を `@if(false)` で包む）→ テナントの画面のテストが `…二度押し止めの部品の script が無い` で赤（`60a96ad0` では緑）
- そのほか（K05 6・K11 2・D01 1・D02 3・D05 1・D06 3・D07 0・UT05 1・UM03 1・UA04 2）は 1 回目と同じ

**最終の確認**（`420079bf`＝`20ef89dc` に `13.x` の文書だけの 3 コミット（決裁の要件定義書・設計書）を取り込んだもの。コードは `20ef89dc` と同じ）:

- 全件 `OK (2838 tests, 20265 assertions)`（2834 ＋ 新しいテスト 4 本）
- コンパイル済みビュー 283 本 / INVALID 0 件
- 入口で必ず断るカナリア: 着弾 6 本・`Tests: 2838, Assertions: 19593, Errors: 23, Failures: 117` ／ **入口に来た 140 本・赤 140 本・緑のまま 0 本・入口に来ずに赤 0 本**（本部 Sheet のテストが 15 → 19 本。ほかのクラスは同じ）

### Task 16: 本番反映

2026-09-29。利用者の承認のあと（案 A）、手順どおりに流した。着手の時点で `13.x` は `071f028c` のまま（このブランチの祖先）・main repo の作業ツリーは空・`vendor/bin/phpunit` は無い。本番（`b28881d8`＝決裁 段階2a）から変わるアプリのファイルは計画の 14 本だけ（`b28881d8..071f028c` は文書と `deploy.sh` の除外 1 行で、`deploy.sh` 自身は rsync の対象外）。DB 変更・ルート変更・新しい PHP クラス・依存の変更は無いので、`composer dump-autoload` も SQL も流していない。

- Step 1 早送り: `13.x` を `071f028c` → `b5576d7e`・作業ツリーは空
- Step 2: `vendor/bin/phpunit` は無い
- Step 3 `./deploy.sh`: 8:48:40〜8:48:52（日本時間）・exit 0・6 段すべて。送ったアプリのファイルは 14 本とビルドだけ。`.superpowers` の除外（`dfdf95c4`）を足してから初めて流した `deploy.sh` で、`.superpowers` は送られていない。CSS・JS の名前は `app-DW1DvPK7.css`・`app-NiVQbl_Q.js`（計画の `app-D-wd4D2y.css` と違うのは Task 13 のとおり `13.x` の決裁 段階2a のため。本番と同じ名前）で、旧バンドルの削除 0 件。部品の名簿の作り直しと 3 つのキャッシュとも成功
- Step 4 ssh（読み取りだけ。手元に書いたスクリプトを本番の `/bin/sh` に流した）: 14 本の md5 が手元と**すべて一致**・コンパイル済みビュー **283 本 / INVALID 0 件**（計画の 275 と違うのは Task 10 のとおり）・`laravel.log` は 6/18 から更新なし（反映のあとのエラー 0 件）・`config.php`・`routes-v7.php` は 600、部品の名簿 2 つは 700（8:48 に作り直し）・本番の `~/apps/manage` に `.superpowers` は無い
- Step 4 ログイン済みの実 Chrome（8:51〜8:53。見るだけ・ファイルは上げない・フォームは送らない）: 6 画面とも 200・初期フォームどおり・`main` の横スクロール無し（[1220,1220]）・コンソールは自分で出した目印（`[probe] …`）だけでエラー 0 件（最初の画面で先に読んでから開き直し、読み取りが効いていることを確かめた）
  - テナント: アップロードのフォーム 5 つ（物件・区画・顧客・契約・過去契約）
  - 賃貸マンション: 6 つ（物件・部屋・駐車場・入居者・部屋契約・駐車場契約）
  - ZEAL 会員: 1 つ（「プレビューを確認する」）
  - 工程表: 建売物件 9（HS-008）の詳細の「工程表を取り込む」→ `/housing/properties/9/schedule-import`（「内容を確認する」）
  - 試算表: `/zeal/simulations` → 2026 年度（`/zeal/simulations/2`）。「実績を反映」「Sheet URL 設定」は出るが、本部 Sheet の URL が未設定なので「本部 Sheet を取り込む」のボタンは出ない（URL があるときだけ出る作り）
  - 周辺ビル: 確定のフォームの `x-data` が `submitOnce({ reloadOnReturn: true })`・hidden の `import_token` が 40 文字・`submitOnce` が定義済み・ボタン「この内容で取り込む」は押せない状態（`not-allowed`・不透明度 0.5・包む span の `title` が「取り込める行がありません。」）・送信中の文字は空
- 確認画面の二度押し止めと鍵はプレビューのあとにしか出ないので、本番では見ていない（テストとローカルの実ブラウザで確かめてある）。本番で実際に取り込むのは利用者
- push はしていない
