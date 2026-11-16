# 過去契約の取込の「顧客 N件を自動作成」を取り込む行から数える — 実装計画

> 実行のしかた: この会話で私が Task 1 → 2 を順に行う（Native）。

**Goal:** テナントの過去契約の取込の確認画面で、「顧客 N件を自動作成: 名前…」に誤りで取り込まれない行のテナント名を載せない（確定の完了の文と同じ数にする）。

**Architecture:** 行の検査の途中で積んでいた `$customerCreateList` をやめ、ループのあとで取り込む行（`$validRows`）のうち `_customer_will_be_created` が true の行のテナント名を、出てきた順に重複なしで並べる。区画タブの「復元」の件数（`$restoreCount`）と同じ作り。

**Tech Stack:** Laravel 12 / PHPUnit 11（PHP 8.3）

**Spec:** `docs/superpowers/specs/2026-11-16-past-contract-create-list-design.md`（コミット `b3e265ce`）

**作業場所:** worktree `nostalgic-jennings-a2186c`・枝 `tenant-import-column-limits`（13.x = origin/13.x = `5a10f511` から）

## Global Constraints

- 文言（`過去契約 N件を新規作成（顧客 M件を自動作成: 名前、名前 ...）`）・5 件まで並べる形・確定の完了の文は変えない
- DB・ルート・画面・新しいクラスの変更なし（本番への反映は `./deploy.sh` だけ）
- テストの流し方: `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit --display-phpunit-deprecations [ファイル]`（worktree の cwd）。データプロバイダは `#[DataProvider]`
- コミットは Conventional Commits・日本語・末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## Review Focus

1. 先の行が誤りで、あとの行が同じ名前で取り込まれる — その名前は載る（確定でも作られる）。Task 1 のテスト 2
2. 同じまだ無い顧客の行が 2 つ取り込まれる — 名前は 1 回だけ・数も 1。Task 1 のテスト 2
3. 登録済みの顧客の行が取り込まれる — 載らない。Task 1 のテスト 1
4. 6 件以上 — 先頭の 5 件と ` ...`。誤りの行の名前が 5 件の中に入り込まない。Task 1 のテスト 3
5. 取り込む行が 0 件 — 「過去契約 0件を新規作成」だけ（既存の `TenantContractReimportTest::test_a_past_contract_for_a_customer_created_by_the_first_import_is_skipped_when_uploaded_again` が守る。足さない）

---

### Task 1: 自動作成の予定を取り込む行から作る

**Files:**
- Create: `tests/Feature/Admin/TenantPastContractCustomerListTest.php`
- Modify: `app/Http/Controllers/Admin/TenantImportController.php`（`executePastContract()`。今 1059 行の初期化・1168〜1181 行の顧客の確かめ・1271 行の要約）

**Interfaces:**
- Consumes: `Tests\Concerns\SubmitsImportPreview`（`preview()`・`confirm()`）・`Tests\Concerns\ParsesForms`。取込の URL は `/admin/tenant-import`
- Produces: なし

- [ ] **Step 1: 失敗するテストを書く** — setUp: 物件「自動作成ビル」（tenant）に 1 階の区画 A〜G・顧客「既存商事」。見出しは `物件名,階,部屋番号,テナント名,契約日,賃料開始日,解約日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考`、行は契約日 `2020-04-01`・解約日 `2023-03-31`・家賃 `90000`
  - `test_names_of_rows_that_fail_later_checks_are_not_counted`: 行 2 作られる商事（A・正常）／3 金額誤り商事（B・家賃 `abc`）／4 `str_repeat('い', 201)`（C）／5 屋号長い商事（D・屋号 `str_repeat('う', 201)`）／6 既存商事（E・正常）
    - 役割: `viewData('summary') === '過去契約 2件を新規作成（顧客 1件を自動作成: 作られる商事）'`・rowErrors の行が `[3, 4, 5]`
    - 表示: HTML に要約の全文がある・`金額誤り商事`・`屋号長い商事`・201 文字の名前が HTML に無い
    - 確定: `assertSessionHas('success', '過去契約インポート完了: 契約 2件を登録、顧客 1件を自動作成')`・顧客は「既存商事」「作られる商事」の 2 件だけ
  - `test_a_name_whose_later_row_is_imported_is_counted_once`: 行 2 後で入る商事（A・家賃 `abc`）／3 後で入る商事（B・正常）／4 後で入る商事（C・正常）／5 二つ目商事（D・正常）
    - `summary === '過去契約 3件を新規作成（顧客 2件を自動作成: 後で入る商事、二つ目商事）'`・HTML に全文
    - 確定: success `'過去契約インポート完了: 契約 3件を登録、顧客 2件を自動作成'`・`Customer::where('name', '後で入る商事')->count() === 1`
  - `test_only_the_first_five_imported_names_are_listed`: 行 2 あ商事（A）／3 誤り商事（G・家賃 `abc`）／4〜8 い・う・え・お・か商事（B〜F）。すべて正常（3 を除く）
    - `summary === '過去契約 6件を新規作成（顧客 6件を自動作成: あ商事、い商事、う商事、え商事、お商事 ...）'`・HTML に全文・`誤り商事` が HTML に無い
- [ ] **Step 2: 落ちることを確かめる** — 新しいファイルだけ流す。期待: テスト 1 は「顧客 4件を自動作成: 作られる商事、金額誤り商事、…」、テスト 3 は「顧客 7件を自動作成: あ商事、誤り商事、…」で summary が食い違って落ちる。テスト 2 は直す前も緑の見込み（先の誤りの行で名前が先に入るだけで、並びも数も同じ）＝直しすぎを止める守り
- [ ] **Step 3: 実装** — `$customerCreateList = [];` の初期化と、顧客の確かめの中の `in_array` で足す 4 行をなくす（`$customerWillBeCreated` は残す）。プレビューの分岐の前で
  `$customerCreateList = array_values(array_unique(array_column(array_filter($validRows, fn (array $row) => $row['_customer_will_be_created']), 'customer_name')));`
  とし、なぜそうするかを 1〜2 行のコメントに書く（途中で積むと、あとで誤りになる行の名前が残る。確定と同じ `$validRows` から数える）。顧客の確かめのコメント「（なければ自動作成予定リストに追加）」を今の動きに直す
- [ ] **Step 4: 通ることを確かめる** — 新しいファイルと `TenantContractReimportTest`・`TenantUnitImportTest` が OK・警告なし
- [ ] **Step 5: コミット** — `fix: 過去契約の取込の自動作成の予定を、取り込む行から数える`

### Task 2: 全件・変異・記録

**Files:**
- Create: `.claude/route-coverage-tools/mutate/specs.past-contract-create-list.py`（worktree の写し。git の管理外）
- Modify: `docs/RULES.md`（Bug #70 の行の末尾に「⚠ 同じ形（2026-11-16）」）
- Modify: `docs/BACKLOG.md`（Bug #70 の節の範囲外の行と Bug #133 の節の後回しの Minor に「→ 2026-11-16 に直した」・新しい節・完了状況の段落）
- Modify: この計画書の末尾（実測記録）

- [ ] **Step 1: 全件テスト** — 期待: `OK (5003 + 3 tests, …)`・警告なし
- [ ] **Step 2: 変異の表を書いて流す**（先に期待を書く。Bug #44 の作法・`WT` をこの worktree に。対象のテスト: 新しいファイル・`TenantContractReimportTest`・`TenantUnitImportTest`・`TenantImportColumnLimitsTest`・`TenantImportDoubleSubmitTest`・`TenantContractRentStartImportTest`）
  - C0 カナリア（要約の `件を自動作成: ` を `件を自動作成：` に）— 新しい 3 本
  - M1 `array_unique` を外す — テスト 2
  - M2 `array_filter` を外す（取り込む行すべての名前）— テスト 1（既存商事が載る）
  - M3 条件を反転（`! $row['_customer_will_be_created']`）— 新しい 3 本
  - M4 `array_slice(…, 0, 5)` を 6 に — テスト 3
  - M5 ` ...` を外す — テスト 3
  - M6 要約の件数を `count($validRows)` に — テスト 1・2（テスト 3 は 6 件どうしで緑の見込み）
  - 期待どおりでなければ、テストを足して流し直す
- [ ] **Step 3: 記録を書く** — RULES・BACKLOG（新しい節に: 何を・直す前の実測・直し方・テスト・全件の数・変異の結果・範囲の外）。計画書の末尾に実測記録
- [ ] **Step 4: コミット** — `docs: 過去契約の取込の自動作成の予定の直しを Bug #70 と BACKLOG に記録する`

## 確かめ方（まとめ）

- Feature テスト（上）と全件テスト・変異テスト
- 画面は変えていないので、ビューの lint と実ブラウザの確認はしない（確認画面の描画は Feature テストが見る）
- 13.x への早送り・push・本番への反映は、利用者の指示があったときだけ（別々のコマンドで）

## 実測記録（2026-11-16）

- Task 1（`5d9a0d16`）: 直す前の赤 = テスト 1 は「顧客 4件を自動作成: 作られる商事、金額誤り商事、いいい…、屋号長い商事」、テスト 3 は「顧客 7件を自動作成: あ商事、誤り商事、い商事、う商事、え商事 ...」で summary が食い違った。テスト 2 は見込みどおり直す前も緑。直したあと新しいファイル・`TenantContractReimportTest`・`TenantUnitImportTest` が OK (99 tests, 784 assertions)・警告なし
- 全件: OK (5006 tests, 50992 assertions)・警告なし（PHP 8.3・3 分 05 秒・ピーク 171.50 MB）
- 変異 7 通り（`.claude/route-coverage-tools/mutate/specs.past-contract-create-list.py`・結果は `results-past-contract-create-list.{txt,json}`）: すべて MATCH（C0 3 本・M1 1 本・M2 1 本・M3 3 本・M4 1 本・M5 1 本・M6 2 本）。流したあと作業ツリーは空
