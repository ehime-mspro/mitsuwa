# 賃貸マンションの取込で列に入らない値をその行の誤りにする Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 賃貸マンションの CSV 取込で、本番の列に入らない値（24 列）と小数の金額を、その行の誤りにする（確定で全行が取り消されない・黙って切り捨てない）。

**Architecture:** `Admin\MansionImportController` に 2 つの非公開の部品を足す — 文字の長さ（`tooLongMessage()`）と 0〜上限の整数（`readUnsignedInt()`）。6 タブの行の検査が、今ある検査の位置でそれを呼ぶ。テストは新しい 1 本（`MansionImportColumnLimitsTest`）。

**Tech Stack:** Laravel 12 / PHP 8.3 / PHPUnit（SQLite）

**Spec:** `docs/superpowers/specs/2026-11-15-mansion-import-column-limits-design.md`

## Global Constraints

- 長さは `mb_strlen`。文は「{見出し}は{上限}文字以内で入力してください」（既存の形）
- 数の上限を超えたら「{見出し}「{セルの値}」は{上限}以下の値にしてください」（上限は桁区切りなしの数。画面の `max` の文と同じ言い方）
- 形の誤り（整数でない・小数・負・指数）は**今の文のまま**: 部屋と部屋契約の 4 金額・駐車場契約の敷金・面積「…「x」は不正な値です」／駐車場と駐車場契約の月額料金・総戸数・階数・階「…「x」は0以上の整数で入力してください」
- 上限: 文字（オーナー名 100・郵便番号 10・構造 50・間取り 20・電話番号 20・勤務先 100・緊急連絡先氏名 100・緊急連絡先電話 20・続柄 50）／整数（総戸数 65535・階数 255・階 255・金額 `self::MAX_UNSIGNED_INT_COLUMN`＝4294967295）／面積 999999.99
- 金額・数の読み方: カンマを除いて `ctype_digit`。上限との比べは `(float)`（PHP の int のあふれに頼らない）。面積は今どおり `is_numeric` で、`(float) > 999999.99` を断る（画面の `max:999999.99` と同じ）
- 部屋契約・駐車場契約の検査の順「日付 → CSV 内の重複 → 照合 → 金額」は変えない
- 物件・入居者の新しい長さの検査は、登録済みのスキップより後ろ
- オーナー名は自社所有の行でも長さを見る
- テストのデータプロバイダは `#[DataProvider]` 属性。`git stash` は使わない
- テストの流し方: `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit --display-phpunit-deprecations`（警告なしの OK）

## Review Focus

1. カンマ入りで上限ちょうど（`4,294,967,295`）→ 取り込まれ 4294967295 で保存（Task 2 の家賃のデータ）
2. PHP の int を超える桁（`99999999999999999999`）→ 「…以下の値にしてください」の誤り（Task 1 の総戸数・Task 2 の家賃のデータ）
3. 面積の小数第 3 位で上限を超える（`999999.995`）→ 誤り（Task 2）
4. 登録済みの契約と同じ行で金額が上限を超える → 今どおりスキップ（誤りにしない）（Task 3）
5. 自社所有の行で長すぎるオーナー名 → 保存しない値でも誤り（Task 1）

---

### Task 1: 部品 2 つと、物件・入居者のタブ

**Files:**
- Modify: `app/Http/Controllers/Admin/MansionImportController.php`（物件の行の検査 ~298〜331 行・入居者 ~797〜817 行・末尾に部品）
- Create: `tests/Feature/Admin/MansionImportColumnLimitsTest.php`

**Interfaces:**
- Produces（Task 2・3 が使う）:
  - `private function tooLongMessage(array $row, array $limits): ?string` — `$limits` は「内部キー => [見出し, 上限]」。並びの順に見て、最初に長すぎた列の文を返す（無ければ null）
  - `private function readUnsignedInt(string $raw, string $label, int $max, string $formatError): array` — 返り値は `[int, null]` か `[null, 誤りの文]`。カンマを除いて `ctype_digit` でなければ `$formatError`、`(float)` が `$max` を超えれば上限の文
  - 定数 `PROPERTY_TEXT_LIMITS`・`ROOM_TEXT_LIMITS`・`TENANT_TEXT_LIMITS`（Global Constraints の値・CSV の列の順）、`MAX_SMALLINT_UNSIGNED = 65535`・`MAX_TINYINT_UNSIGNED = 255`・`MAX_AREA_SQM = 999999.99`
- テストの土台（Task 2・3 が足す）: `setUp()` で `createMansionSchema()`・物件「上限ハイツ」（`MansionContractReimportTest::setUp` と同じ形）・部屋 101・駐車場 P-1（8000）・入居者 山田太郎。`importBasePath()` は `/admin/mansion-import`

- [ ] **Step 1: 失敗するテストを書く**（すべて「行 2 は上限ちょうど・行 3 以降は誤り」の CSV でプレビュー → 確定）

```php
#[DataProvider('propertyTextLimits')]
public function test_property_text_up_to_the_column_size_is_imported(string $label, string $column, int $max): void
// データ: 'オーナー名' => ['オーナー名', 'owner_name', 100], '郵便番号' => [..., 'postal_code', 10], '構造' => [..., 'structure', 50]
// 行 2「上限一号館」管理受託・その列に str_repeat('あ', $max) ／ 行 3「上限二号館」自社所有・str_repeat('あ', $max + 1)
// プレビュー: rowErrors === [['row' => 3, 'message' => "{$label}は{$max}文字以内で入力してください"]]・validCount === 1
// 確定: 上限一号館の $column === str_repeat('あ', $max)・上限二号館は無い

#[DataProvider('propertyCountLimits')]
public function test_property_counts_up_to_the_column_range_are_imported(string $label, string $column, int $max): void
// データ: '総戸数' => ['総戸数', 'total_units', 65535], '階数' => ['階数', 'total_floors', 255]
// 行 2 = (string) $max ／ 行 3 = $max + 1 ／ 行 4 = '99999999999999999999' ／ 行 5 = '12.5'
// rowErrors: 行 3「{$label}「{$max+1}」は{$max}以下の値にしてください」・行 4 同じ形・行 5「{$label}「12.5」は0以上の整数で入力してください」
// 確定: 行 2 の物件の $column === $max（ほかは無い）

#[DataProvider('tenantTextLimits')]
public function test_tenant_text_up_to_the_column_size_is_imported(string $label, string $column, int $max): void
// データ: 電話番号 phone 20・勤務先 workplace 100・緊急連絡先氏名 emergency_contact_name 100・緊急連絡先電話 emergency_contact_phone 20・続柄 emergency_contact_relation 50
// 行 2「上限一郎」・行 3「上限二郎」。期待は物件と同じ形

#[DataProvider('registeredRows')]
public function test_a_registered_row_with_a_long_value_is_still_skipped(string $tab, string $csv, string $skip): void
// 物件: 「上限ハイツ」の行で構造が 51 文字 → skippedRows === [['row' => 2, 'message' => '物件「上限ハイツ」は既に登録済みのためスキップ']]・rowErrors === []
// 入居者: 「山田太郎」の行で続柄が 51 文字 → '入居者「山田太郎」は既に登録済みのためスキップ'
```

- [ ] **Step 2: 流して赤を確かめる** — `…phpunit --display-phpunit-deprecations tests/Feature/Admin/MansionImportColumnLimitsTest.php`。期待: 長さのテストは validCount が 2（行 3 が通る）、数のテストは行 3・4 が通る（rowErrors が行 5 だけ）。スキップのテストは緑（直す前から緑＝順の守り）

- [ ] **Step 3: 部品 2 つと定数を足し、物件・入居者で呼ぶ**
  - 物件: `total_units`・`total_floors` の `ctype_digit` の検査を `readUnsignedInt()`（上限は 2 つの定数・形の誤りの文は今の文）に置き換える。`tooLongMessage($row, self::PROPERTY_TEXT_LIMITS)` を所有区分の検査の後・築年月の前に置く
  - 入居者: `tooLongMessage($row, self::TENANT_TEXT_LIMITS)` を登録済みのスキップの後・メールの検査の前に置く

- [ ] **Step 4: 流して緑を確かめる**（Step 2 のコマンド・Mansion の取込のテスト全部も: `tests/Feature/Admin/Mansion*`）

- [ ] **Step 5: コミット** — `fix: 賃貸マンションの物件・入居者の取込で、列に入らない長さと数をその行の誤りにする`

### Task 2: 部屋・駐車場のタブ（金額は整数だけ）

**Files:**
- Modify: `app/Http/Controllers/Admin/MansionImportController.php`（部屋 ~466〜520 行・駐車場 ~664〜670 行）
- Modify: `tests/Feature/Admin/MansionImportColumnLimitsTest.php`

**Interfaces:**
- Consumes: Task 1 の `tooLongMessage()`・`readUnsignedInt()`・`ROOM_TEXT_LIMITS`・`MAX_TINYINT_UNSIGNED`・`MAX_AREA_SQM`

- [ ] **Step 1: 失敗するテストを書く**（部屋番号は 201 から 1 行ずつ。対象の列のほかは空）

```php
public function test_room_type_up_to_20_characters_is_imported(): void
// 201 = str_repeat('あ', 20) ／ 202 = 21 文字 → rowErrors [['row' => 3, 'message' => '間取りは20文字以内で入力してください']]・確定で 201 だけ

#[DataProvider('roomNumberLimits')]
public function test_room_numbers_up_to_the_column_range_are_imported(string $header, string $column, string $okRaw, int $okStored, array $bad): void
// データ（$bad は「セルの値 => 文」）:
//   '階'   => ['階', 'floor', '255', 255, ['256' => '階「256」は255以下の値にしてください', '2.5' => '階「2.5」は0以上の整数で入力してください']]
//   '家賃' => ['家賃', 'rent', '4,294,967,295', 4294967295, [
//               '4294967296' => '家賃「4294967296」は4294967295以下の値にしてください',
//               '99999999999999999999' => '家賃「99999999999999999999」は4294967295以下の値にしてください',
//               '55000.9' => '家賃「55000.9」は不正な値です', '1e10' => '家賃「1e10」は不正な値です']]
//   '共益費'・'敷金'・'礼金' => 同じ形（'4294967295' => 4294967295 と、'4294967296'・'55000.9' の 2 つ）
// プレビュー: rowErrors が $bad の順に行 3〜・validCount === 1。確定: 部屋 201 の $column === $okStored・ほかの部屋は無い

public function test_area_up_to_999999_99_is_imported(): void
// 201 = '999999.99'（保存 '999999.99'）／ 202 = '1000000' ／ 203 = '999999.995'
// rowErrors: '面積「1000000」は999999.99以下の値にしてください'・'面積「999999.995」は999999.99以下の値にしてください'

public function test_parking_monthly_fee_up_to_the_column_range_is_imported(): void
// P-2 = '4294967295' ／ P-3 = '4294967296' → '月額料金「4294967296」は4294967295以下の値にしてください' ／ P-4 = '8000.5' → '月額料金「8000.5」は0以上の整数で入力してください'
```

- [ ] **Step 2: 流して赤を確かめる** — 期待: 上限を超えた行・小数・`1e10` が通る（validCount が多い）、または家賃の小数が 55000 で保存される
- [ ] **Step 3: 部屋・駐車場で呼ぶ** — 部屋: 部屋番号の長さの検査の後に `tooLongMessage($row, self::ROOM_TEXT_LIMITS)`、階は `readUnsignedInt()`、面積は今の検査の後に `(float) $val > self::MAX_AREA_SQM` で上限の文、4 金額の繰り返しの中を `readUnsignedInt()`。駐車場: 月額料金を `readUnsignedInt()`
- [ ] **Step 4: 流して緑を確かめる**（`tests/Feature/Admin/Mansion*` も）
- [ ] **Step 5: コミット** — `fix: 賃貸マンションの部屋・駐車場の取込で、列に入らない値と小数の金額をその行の誤りにする`

### Task 3: 部屋契約・駐車場契約のタブ

**Files:**
- Modify: `app/Http/Controllers/Admin/MansionImportController.php`（部屋契約 ~1020〜1041 行・駐車場契約 ~1292〜1310 行）
- Modify: `tests/Feature/Admin/MansionImportColumnLimitsTest.php`

**Interfaces:**
- Consumes: Task 1 の `readUnsignedInt()`

- [ ] **Step 1: 失敗するテストを書く**（部屋 101・P-1・山田太郎。行ごとに契約日を変える＝CSV 内の重複にしない）

```php
#[DataProvider('roomContractAmounts')]
public function test_room_contract_amounts_up_to_the_column_range_are_imported(string $header, string $column): void
// 4 金額それぞれ: 行 2 = '4294967295'（2024-04-01）／ 行 3 = '4294967296'（2024-05-01）／ 行 4 = '55000.9'（2024-06-01）
// rowErrors: 「{$header}「4294967296」は4294967295以下の値にしてください」・「{$header}「55000.9」は不正な値です」。確定: MsContract::sole()->$column === 4294967295

#[DataProvider('parkingContractAmounts')]
public function test_parking_contract_amounts_up_to_the_column_range_are_imported(string $header, string $column, string $formatError): void
// 月額料金（形の誤り「…は0以上の整数で入力してください」）・敷金（「…は不正な値です」。月額料金は 8000）。期待は部屋契約と同じ形

#[DataProvider('registeredContracts')]
public function test_a_registered_contract_with_an_amount_over_the_limit_is_still_skipped(string $tab, string $csv): void
// 同じ部屋（駐車場）・入居者・契約日・入居日（開始日）の契約を先に作り、家賃（月額料金）'4294967296' の行を上げる
// → rowErrors === []・count(skippedRows) === 1
```

- [ ] **Step 2: 流して赤を確かめる**（スキップのテストは直す前から緑）
- [ ] **Step 3: 部屋契約の 4 金額・駐車場契約の月額料金と敷金を `readUnsignedInt()` に置き換える**（位置は変えない）
- [ ] **Step 4: 流して緑を確かめる**（`tests/Feature/Admin/Mansion*` も）
- [ ] **Step 5: コミット** — `fix: 賃貸マンションの契約の取込で、列に入らない金額と小数をその行の誤りにする`

### Task 4: 全件・変異・記録

**Files:**
- Modify: `docs/RULES.md`（Bug #132 の行を足す・Bug #131 の範囲外に「→ 直した（Bug #132）」）・`CLAUDE.md`（「全 131 件」「Bug #1–131」→ 132）・`docs/BACKLOG.md`（新しい節と、Bug #131 の節の範囲外・完了状況の 1 行）・この計画（実測記録）
- 変異の表: main repo の `.claude/route-coverage-tools/mutate/specs.py`（前の表は `specs.import-edit-roundtrip.py` の名で残す）

- [ ] **Step 1: 全件** — Global Constraints のコマンド。期待: 警告なしの OK（4894 本＋足した本数）
- [ ] **Step 2: 変異** — `WT=<この作業場所> python3 run.py`。流す前に期待を書く。少なくとも: カナリア／`mb_strlen`→`strlen`／`>`→`>=`（長さ・数）／3 つの定数から 1 列ずつ外す（9 通り）／カンマを除かない／`ctype_digit`→`is_numeric`／総戸数・階数・階の上限を 1 増やす／面積の上限の比べを外す／部屋契約・駐車場契約の金額の検査を照合の前へ動かす（スキップのテストが落ちる）。期待と違えば理由まで見て、テストを足すか表を直す
- [ ] **Step 3: 記録** — RULES の Bug 番号は書く直前に最新の 13.x で数える。BACKLOG は「本番未反映」
- [ ] **Step 4: 最後の点検** — 別の担当（fable）に枝全体を読んでもらい、指摘を直す
- [ ] **Step 5: コミット** — `docs: 賃貸マンションの取込で列に入らない値を断った直しを Bug #132 と BACKLOG に記録する`

## 実測記録

実行は Native（この会話で実装・最後に別の担当が枝全体を点検）。台帳は `.superpowers/sdd/2026-11-15-mansion-import-column-limits/progress.md`。

| Task | コミット | 直す前の赤 | 直したあと |
|---|---|---|---|
| 1 | `f59edba6` | 12 本中 10 本が rowErrors の食い違いで赤（物件の文字 3・数 2・入居者 5）。スキップの順の 2 本は緑（守り） | Mansion の取込のテスト OK (134 tests) |
| 2 | `c7e6395e` | 8 本が赤（間取り・階・4 金額・面積・駐車場の月額料金） | OK (142 tests) |
| 3 | `c60db949` | 6 本が赤（部屋契約の 4 金額・駐車場契約の 2 金額）。スキップの順の 2 本は緑（守り） | OK (150 tests) |

- 全件: **OK (4922 tests, 50422 assertions)**（PHP 8.3・警告なし・3 分 10 秒・ピーク 171.50 MB）。4894 本から +28
- 変異 25 通り（カナリアを含む）: **すべて期待どおり**（この作業場所の `.claude/route-coverage-tools/mutate/specs.mansion-import-column-limits.py`・結果は `results-mansion-import-column-limits.*`）。「照合・スキップの前へ動かす」4 通り（M19〜M22）は `at_least` で、狙ったテストのほかに、動かした検査の文（`early`）が上限を超えた行の文と食い違うテストと、`MansionContractReimportTest` の「金額に誤りがあってもスキップ」の 2 本が一緒に落ちた（期待どおりの組）
- Ruling: 変異の実行役は main repo の `.claude/route-coverage-tools/mutate/` の写しがこの作業場所にもある（git の管理外。前の作業もここに表を置いた）ので、表と結果はこの作業場所の写しに置いた（main repo の `.claude/` へはこの作業場所の会話から書けない＝フックが止める）— この作業場所を消すと表も消える（記録は RULES・BACKLOG とこの節に残した）
- 最後の点検（fable）: マージしてよい・Critical・Important 0・Minor 5。M1（総戸数・階数・階がカンマ入りを通し、`2,5` が黙って 25 になる）を効き目で Important とみなして直した（`c94ff529`。`readUnsignedInt()` に `$allowCommas`。テストの `2,5` の行が直す前に 3 本赤 → 直して緑）。全件 OK (4922 tests, 50422 assertions)・警告なし。残りの Minor は BACKLOG の節に後回しとして書いた
