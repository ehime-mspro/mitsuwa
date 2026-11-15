# テナントの取込で列に入らない値をその行の誤りにする Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** テナントの CSV 取込で、本番の列（と画面の上限）に入らない値（33 列＋備考）と小数の金額を、その行の誤りにする。

**Architecture:** 賃貸マンションの取込の部品 2 つを `App\Support\CsvImportLimits`（static）へ移し、`MansionImportController` と `TenantImportController` の両方で使う。テナントの 5 タブは今ある検査の位置で呼ぶ。テストは新しい 1 本（`TenantImportColumnLimitsTest`）と部品の Unit テスト。

**Tech Stack:** Laravel 12 / PHP 8.3 / PHPUnit（SQLite）

**Spec:** `docs/superpowers/specs/2026-11-15-tenant-import-column-limits-design.md`

## Global Constraints

- 文: 長すぎる「{見出し}は{上限}文字以内で入力してください」／上限を超える「{見出し}「{セルの値}」は{上限}以下の値にしてください」／形の誤りは今の文のまま（金額・面積「…「x」は不正な値です」）
- 見出しは CSV の見出し。上限: 物件（物件名 200・郵便番号 10・住所 500・構造 50・オーナー名 200）／区画（部屋番号 20）／顧客（テナント名 200・テナントカナ 200・代表者名 100・担当者 100・電話番号 20・メールアドレス 255・郵便番号 10・住所 500）／契約（屋号 200・備考 5000）／過去契約（テナント名 200・屋号 200・備考 5000）
- 金額: カンマを除いて `ctype_digit`・上限は `self::MAX_INT_COLUMN`（2147483647）。面積: 今の `is_numeric` のあとで `(float) > 9999.99` を断る
- 検査の順は変えない（設計の「方針」）
- `#[DataProvider]` 属性・`git stash` は使わない・テストは `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit --display-phpunit-deprecations`（警告なしの OK）

## Review Focus

1. 登録済みの契約・過去契約と同じ行で、屋号・備考・金額が長すぎる／大きすぎる → スキップのまま（Task 3）
2. 登録済みの物件・顧客の行で長すぎる値 → スキップのまま（Task 2）
3. 備考に改行（LF）を含む 5,000 文字ちょうど → 取り込まれる（Task 3）
4. 過去契約で、まだ無い顧客のテナント名が 201 文字 → その行の誤り（自動で顧客を作らない）（Task 3）
5. 部品の移動で賃貸マンションの動きが変わらない（Task 1。`MansionImportColumnLimitsTest` が緑のまま）

---

### Task 1: 部品を `App\Support\CsvImportLimits` へ移す

**Files:**
- Create: `app/Support/CsvImportLimits.php`・`tests/Unit/Support/CsvImportLimitsTest.php`
- Modify: `app/Http/Controllers/Admin/MansionImportController.php`（私的な 2 つを消し、新しいクラスを呼ぶ）

**Interfaces:**
- Produces: `CsvImportLimits::tooLongMessage(array $row, array $limits): ?string`（`$limits` は「内部キー => [見出し, 上限]」・並びの順に最初の 1 つ）／`CsvImportLimits::readUnsignedInt(string $raw, string $label, int $max, string $formatError, bool $allowCommas = true): array`（`[int, null]` か `[null, 文]`）。中身は賃貸マンションの私的メソッドと同じ

- [ ] **Step 1: Unit テストを書く**（`test_too_long_message_*`: 全角で上限ちょうどは null・1 つ超えで文・2 列とも長ければ最初の列／`test_read_unsigned_int_*`: `'4,294,967,295'`→`[4294967295, null]`・`'4294967296'`→上限の文・`'99999999999999999999'`→上限の文・`'0004294967295'`→`[4294967295, null]`・`'55000.9'` `'1e10'` `'-1'` `'+5'` `''`→`$formatError`・`$allowCommas = false` で `'2,5'`→`$formatError`）
- [ ] **Step 2: 流して赤（クラスが無い）**
- [ ] **Step 3: クラスを作り、`MansionImportController` の 8 か所を新しいクラスの呼び出しに置き換え、私的メソッドを消す**
- [ ] **Step 4: 流して緑**（Unit ＋ `tests/Feature/Admin/ --filter Mansion`）
- [ ] **Step 5: コミット** — `refactor: 取込の長さと整数の検査を CsvImportLimits へ移す`

### Task 2: 物件・顧客のタブ

**Files:**
- Modify: `app/Http/Controllers/Admin/TenantImportController.php`（物件 ~246〜274 行・顧客 ~623〜632 行・定数）
- Create: `tests/Feature/Admin/TenantImportColumnLimitsTest.php`（土台: `RefreshDatabase`・`ParsesForms`・`SubmitsImportPreview`・`importBasePath()` は `/admin/tenant-import`。setUp で物件「上限ビル」・区画 1A・顧客「上限商事」。CSV の行は見出し => 値の配列から組む＝カンマを含む値は引用符）

**Interfaces:**
- Consumes: Task 1 の `CsvImportLimits::tooLongMessage()`
- Produces: 定数 `PROPERTY_TEXT_LIMITS`・`CUSTOMER_TEXT_LIMITS`

- [ ] **Step 1: テストを書く**

```php
#[DataProvider('propertyTextLimits')]   // 物件名 name 200・郵便番号 postal_code 10・住所 address 500・構造 structure 50・オーナー名 owner_name 200
public function test_property_text_up_to_the_column_size_is_imported(string $label, string $column, int $max): void
// 行 2 = 上限ちょうど（全角）・所有区分「オーナー」／行 3 = 1 つ超え・所有区分「自社」
// 物件名の列は行 2・3 の名前そのものが上限の長さ（ほかの列は名前「上限一号館」「上限二号館」）
// rowErrors === [['row' => 3, 'message' => "{$label}は{$max}文字以内で入力してください"]]・validCount 1・確定で行 2 だけ

#[DataProvider('customerTextLimits')]   // テナント名 name 200・テナントカナ name_kana 200・代表者名 representative 100・担当者 contact_person 100・電話番号 phone 20・メールアドレス email 255・郵便番号 postal_code 10・住所 address 500
public function test_customer_text_up_to_the_column_size_is_imported(string $label, string $column, int $max): void
// メールアドレスは形の検査があるので、上限ちょうどは filter_var が通る半角の 255 文字（ローカル部 64 文字・ドメインのラベルは 63 文字まで）、1 つ超えは 256 文字（長さの検査は形の検査より前）

#[DataProvider('registeredRows')]       // 物件「上限ビル」で構造 51 文字 → '物件「上限ビル」は既に登録済みのためスキップ'／顧客「上限商事」で担当者 101 文字 → '顧客「上限商事」は既に登録済みのためスキップ'
public function test_a_registered_row_with_a_long_value_is_still_skipped(...)
```

- [ ] **Step 2: 流して赤**（長さのテストは validCount 2。スキップは緑＝守り）
- [ ] **Step 3: 定数を足し、物件は所有区分・稼働状態の検査の後・築年月の前、顧客は種別の検査の後・メールの形の検査の前で `tooLongMessage()` を呼ぶ**
- [ ] **Step 4: 流して緑**（`tests/Feature/Admin/ --filter Tenant`）
- [ ] **Step 5: コミット** — `fix: テナントの物件・顧客の取込で、列に入らない長さをその行の誤りにする`

### Task 3: 区画・契約・過去契約のタブ

**Files:**
- Modify: `app/Http/Controllers/Admin/TenantImportController.php`（区画 ~451〜478 行・契約 ~849〜866 行・過去契約 ~1134〜1151 行）
- Modify: `tests/Feature/Admin/TenantImportColumnLimitsTest.php`

**Interfaces:**
- Consumes: Task 1 の 2 つ。Produces: `UNIT_TEXT_LIMITS`・`CONTRACT_TEXT_LIMITS`・`PAST_CONTRACT_TEXT_LIMITS`・`MAX_AREA_TSUBO = 9999.99`

- [ ] **Step 1: テストを書く**（区画は部屋番号を行ごとに変える・契約と過去契約は契約日を行ごとに変える）

```php
public function test_room_number_up_to_20_characters_is_imported(): void
// 'あ'×20 → 取り込む／'い'×21 → '部屋番号は20文字以内で入力してください'

#[DataProvider('unitAmounts')]  // 募集家賃 rent・募集共益費 common_fee・募集敷金 deposit・募集ゴミ代 garbage_fee・募集駆除代 pest_control_fee
public function test_unit_amounts_up_to_the_column_range_are_imported(string $header, string $column): void
// '2,147,483,647' → 2147483647 で保存／'2147483648' → '{h}「2147483648」は2147483647以下の値にしてください'／'55000.9' → '{h}「55000.9」は不正な値です'

public function test_area_up_to_9999_99_is_imported(): void
// '9999.99' → '9999.99' で保存／'10000' → '面積「10000」は9999.99以下の値にしてください'／'9999.995' → 同じ形

#[DataProvider('contractAmounts')]      // 契約: 家賃 rent・共益費・敷金・ゴミ代・駆除代
public function test_contract_amounts_up_to_the_column_range_are_imported(string $header, string $column): void
#[DataProvider('pastContractAmounts')]  // 過去契約: 同じ 5 つ
public function test_past_contract_amounts_up_to_the_column_range_are_imported(string $header, string $column): void
// 期待はどちらも区画と同じ形。確定で Contract::sole()->$column === 2147483647

#[DataProvider('contractTextLimits')]   // [タブ, 見出し, 列, 上限]: 契約 屋号 store_name 200・備考 notes 5000／過去契約 屋号 200・備考 5000・テナント名 → customer の name 200
public function test_contract_text_up_to_the_column_size_is_imported(string $tab, string $label, string $column, int $max): void
// 備考の上限ちょうどは「あ」と改行（LF）を混ぜた 5000 文字（引用符で囲む）。過去契約のテナント名は、まだ無い顧客（行 3 の 201 文字の顧客は作られない）

#[DataProvider('registeredContracts')]  // 契約・過去契約: 登録済みと同じ行で、屋号 201 文字＋家賃 2147483648 → rowErrors []・skippedRows 1
public function test_a_registered_contract_with_a_long_or_large_value_is_still_skipped(string $tab): void
```

- [ ] **Step 2: 流して赤**（スキップは緑＝守り）
- [ ] **Step 3: 区画は区画の重複の検査の後で部屋番号の長さ、面積は今の検査のあとで上限、5 金額は `readUnsignedInt()`。契約・過去契約は照合の後・金額の前で `tooLongMessage()`、5 金額は `readUnsignedInt()`**
- [ ] **Step 4: 流して緑**（`--filter Tenant`）
- [ ] **Step 5: コミット** — `fix: テナントの区画・契約・過去契約の取込で、列に入らない値と小数の金額をその行の誤りにする`

### Task 4: 全件・変異・記録・点検

- [ ] **Step 1: 全件**（Global Constraints のコマンド。警告なしの OK）
- [ ] **Step 2: 変異**（この作業場所の `.claude/route-coverage-tools/mutate/`。流す前に期待を書く。少なくとも: 定数から 1 列ずつ外す・`MAX_INT_COLUMN` を 1 増やす・面積の比べを外す・金額を `is_numeric` に戻す・照合／スキップの前へ動かす・部品の `mb_strlen`→`strlen`）
- [ ] **Step 3: 記録** — RULES（書く直前に最新の 13.x で番号を数える。次は #133 の見込み）・BACKLOG（本番未反映・`composer dump-autoload` が要る）・CLAUDE.md の件数・この計画の実測記録
- [ ] **Step 4: 最後の点検**（別の担当〈fable〉）
- [ ] **Step 5: コミット** — `docs: テナントの取込で列に入らない値を断った直しを記録する`

## 実測記録

実行は Native（この会話で実装・最後に別の担当が枝全体を点検）。

| Task | コミット | 直す前の赤 | 直したあと |
|---|---|---|---|
| 1 | `1b88b939` | Unit テスト 14 本がクラスが無いことで赤 | Unit OK (14)・賃貸マンションの取込のテスト OK (150)＝動きは変わらない |
| 2 | `4638d391` | 15 本中 13 本が rowErrors の食い違いで赤（物件 5・顧客 7・メール 1）。スキップの順の 2 本は緑（守り） | テナントの取込のテスト OK (160 tests) |
| 3 | `a3a2b54a` | 24 本中 22 本が赤（部屋番号・区画の金額 5・面積・契約と過去契約の金額 10・文字 5）。スキップの順の 2 本は緑（守り） | OK (184 tests) |

- 全件: **OK (4975 tests, 50898 assertions)**（PHP 8.3・警告なし・3 分 22 秒・ピーク 171.50 MB）。4922 本から +53
- 変異 35 通り（カナリアを含む）: **すべて期待どおり**（この作業場所の `.claude/route-coverage-tools/mutate/specs.tenant-import-column-limits.py`・結果は `results-tenant-import-column-limits.*`）
- Ruling: メールアドレスの上限ちょうどのテストは 255 でなく 254 文字にした — `filter_var` が 254 文字までしか通さない（実測）ので 255 文字の正しいメールは作れない。長さの文は 256 文字の行で見る
