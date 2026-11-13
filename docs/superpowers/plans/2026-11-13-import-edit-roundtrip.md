# 取込で入った値を編集画面からそのまま保存できるようにする 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 取込（CSV）が入れうる値のレコードを編集画面で開いて何も変えずに保存すると、そのまま保存できる。取込で入れるべきでない値は、取込のときに理由つきで断る。

**Architecture:** 画面の側は、賃貸マンションの金額の `step` を外し、`min`/`max` をサーバーの入力チェックにそろえ、選択肢に無い保存済みの値を「（選択肢に無い値）」として選んだまま描き、買主のメールの欄を `type="text" inputmode="email"` にする。取込の側は、築年月の正規化を `CsvDate::normalizeYearMonth()` の 1 か所に置いてテナントと賃貸マンションの物件の取込が使い、テナントの物件の階数を 99 まで、買主の生年月日を日本の今日までに限る。PHP のテストはブラウザの入力欄の制約を実行しないので、制約を描いた属性と値から確かめる道具（`ChecksBrowserConstraints`）を先に作り、往復のテストが直す前に赤になるようにする。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade + Alpine.js / PHPUnit（SQLite のテスト用スキーマ）

**Spec:** `docs/superpowers/specs/2026-11-13-import-edit-roundtrip-design.md`（前提の調べは `docs/BACKLOG.md` の「取込で入ったデータが編集画面で保存できないか（Bug #130 の形）を本番で数えた」の節）

## Global Constraints

- 作業場所: `/Users/masanori/site/manage/.claude/worktrees/nostalgic-jennings-a2186c`（枝 `edit-saved-values-audit`）。着手の前に `git -C /Users/masanori/site/manage log -1 --format=%h 13.x` が `7bf1c4d5` のままかを確かめ、進んでいたら枝へ merge する（rebase しない）
- テストの流し方（作業場所の cwd で。`.env` は作らない）: `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit`（1 本だけは末尾にパスか `--filter`）。全件は裏で流し、ほかのテストと同時に流さない
- 選択肢に無い値の表示: `{{ $値 }}（選択肢に無い値）`（全角の括弧）。`<option value="{{ $値 }}" selected>` を「選択してください」「選択」の空の option の直後に 1 つだけ足す。値が空・選択肢にある・文字列でないときは足さない（形はテナントの物件の構造 `tenant/properties/edit.blade.php` 98〜101 行・Bug #82）
- 金額の欄は `step` を外すだけ（`min="0"` は残す）。17 欄のほかの欄・ほかの画面の `step` は変えない
- 取込の誤りの文（全文。テストもこの文で見る）:
  - 築年月（テナント・賃貸マンション共通）: `築年月「{セル}」は年月として正しくありません（例: 2010-04 か 2010/4）`
  - テナントの物件の階数: `階数「{セル}」は1〜99の整数で入力してください`
  - 買主の生年月日: `生年月日「{セル}」は今日（{Y-m-d}）より後の日付です`
- 「日本の今日」は `App\Support\JapanTime::today()->toDateString()`（Bug #61。`now()`・`today()` は使わない）
- アプリに新しい PHP クラスを作らない（`CsvDate` にメソッドを足す）。本番の DB は変えない。ルートは変えない
- 決裁の画面とテスト（`resources/views/approvals/`・`tests/Feature/Approval/`）は触らない（「社内決裁申請」の会話の担当）
- コミットは Conventional Commits・日本語・本文の末尾に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`（自分の文脈の Co-Authored-By 行を使う）。`--no-verify` は使わない
- 13.x への早送り・push・本番への反映はこの計画に入れない（利用者の指示があったときに別に行う）

## Review Focus

1. **入力エラーで戻った画面でも、選択肢に無い値が選ばれたまま残る**（`old()` で描く。保存済みの値で描くと、ほかの欄の誤りで戻った画面から保存し直したときに値が黙って変わる）→ Task 3・Task 4 のテスト
2. **選択肢に無い値に `"` `<` `&` が入っていても、属性と文字が逃がされ、1 字も変わらずに往復する** → Task 3 のテスト（物件の構造）
3. **値が選択肢にある・空のときは「（選択肢に無い値）」の option を足さない**（足すと selected が 2 つになり、ブラウザは後ろの option を選ぶ。空の値で足すと空の選択肢が 2 つ出る）→ Task 3・Task 4 のテスト
4. **年月の崩れた形**（`2010-00`・`2010-13`・`2010-4-1`・全角の数字・2 桁の年）は誤りの行になり、保存されない → Task 5 の `CsvDateTest`
5. **生年月日は日本の今日で比べる**（日本時間の 0:00〜8:59 は UTC ではまだ前日。UTC の今日で比べると、日本の今日生まれを断る）→ Task 6 の Feature テスト（時計を 2026-11-13 00:30 JST に止める）

---

### Task 1: ブラウザの入力欄の制約を PHP のテストで確かめる道具

**Files:**
- Modify: `tests/Concerns/ParsesForms.php`（`parseForm()` のフォームを切り出す部分を `formSlice()` に移す。振る舞いは変えない）
- Create: `tests/Concerns/ChecksBrowserConstraints.php`
- Test: `tests/Feature/BrowserConstraintCheckTest.php`

**Interfaces:**
- Produces:
  - `ParsesForms::formSlice(string $html, string $needle): string`（protected）— `<form` の開始から `</form>` の手前までの HTML。needle が開始タグの中にあることを今と同じ文でアサートする。`parseForm()` はこれを使う
  - `ChecksBrowserConstraints::browserConstraintViolations(string $html, string $needle, array $fields): array`（protected。`list<string>`）— `formSlice()` のフォームの `<input>` を、属性（type・min・max・step・pattern・required）と送る値で確かめ、ブラウザが送信を止める・値を空にする欄を `"{name}: {理由}"` で返す
  - `ChecksBrowserConstraints::assertBrowserSubmits(string $html, string $needle, array $fields): void` — 上が空であること（失敗の文に違反を全部並べる）
  - このトレイトは `ParsesForms` の `formSlice()` と `htmlAttr()` を使う（使う側は両方を `use` する）

規則（HTML の仕様のうち、この計画が使う分だけ）:
- 確かめる値は `$fields[$name]`。name が `x[]` なら `$fields['x']`（配列）の要素ごとに確かめ、違反の名前は `x[0]` のように番号を付ける。`$fields` に無い名前の欄は確かめない（送られない欄＝Alpine で `:disabled`・未チェック）。name の無い欄・`type` が hidden / checkbox / radio / submit / button / reset / image / file の欄・素の `disabled` 属性を持つ欄は確かめない。空の値は `required` だけを見る
- `type="number"`: 数の形でない → `「{値}」は数の形でない（空で送られる）`／`min` より小さい → `「{値}」は min={min} より小さい（送信が止まる）`／`max` より大きい → `「{値}」は max={max} より大きい（送信が止まる）`／`step`（無ければ 1・`any` は見ない）に乗らない → `「{値}」は step={step} に乗らない（送信が止まる）`。刻みの基準は `min`、`min` が無ければ `value` 属性、どちらも無ければ 0（仕様どおり）
- `type="email"`: WHATWG の妥当なメールアドレスの形でない → `「{値}」はメールアドレスの形でない（送信が止まる）`
- `type="month"`: `^\d{4,}-(0[1-9]|1[0-2])$` でない → `「{値}」は年月の形でない（空で送られる）`
- `pattern` を持つ文字の欄: `^(?:{pattern})$` に全体が合わない → `「{値}」は pattern={pattern} に合わない（送信が止まる）`
- `required` で空 → `空（required。送信が止まる）`
- ⚠ docblock に死角を書く: `maxlength` は利用者が触った欄にしか効かないので見ない／name の無い欄（例: 改定画面の `depositMonths`）は見ない／`<select>`・`<textarea>` は見ない（select の値は `parseForm()` の `selectedValue()` がブラウザと同じに決める）

- [ ] **Step 1: 失敗するテストを書く** — `tests/Feature/BrowserConstraintCheckTest.php`（`Tests\TestCase` を継承・DB を使わない・`use ParsesForms, ChecksBrowserConstraints;`）。フォームは `<form action="/x">…</form>`、needle は `'action="/x"'`。分かれ目ごとに 1 つずつ（Bug #61 ③: 見本の無い枝は消しても緑）:

```php
// [欄の HTML, $fields, 期待する違反] をデータで並べ、1 本のテストで assertSame する
['<input type="number" name="rent" min="0" step="1000">', ['rent' => '65300'], ['rent: 「65300」は step=1000 に乗らない（送信が止まる）']],
['<input type="number" name="rent" min="0">', ['rent' => '65300'], []],
['<input type="number" name="rent" min="0">', ['rent' => '65300.5'], ['rent: 「65300.5」は step=1 に乗らない（送信が止まる）']],
['<input type="number" name="v" step="any">', ['v' => '0.25'], []],
['<input type="number" name="v" value="7" step="5">', ['v' => '7'], []],            // 基準は value 属性
['<input type="number" name="floor" min="1" max="50">', ['floor' => '0'], ['floor: 「0」は min=1 より小さい（送信が止まる）']],
['<input type="number" name="floor" min="1" max="50">', ['floor' => '51'], ['floor: 「51」は max=50 より大きい（送信が止まる）']],
['<input type="number" name="floor" min="1" max="50">', ['floor' => '50'], []],
['<input type="number" name="n">', ['n' => 'abc'], ['n: 「abc」は数の形でない（空で送られる）']],
['<input type="email" name="email">', ['email' => 'yamada@'], ['email: 「yamada@」はメールアドレスの形でない（送信が止まる）']],
['<input type="email" name="email">', ['email' => 'a@b.jp'], []],
['<input type="text" inputmode="email" name="email">', ['email' => 'yamada@'], []],
['<input type="month" name="built_date">', ['built_date' => '2010-4'], ['built_date: 「2010-4」は年月の形でない（空で送られる）']],
['<input type="month" name="built_date">', ['built_date' => '2010-04'], []],
['<input type="text" name="area" pattern="[0-9.]*">', ['area' => '35,5'], ['area: 「35,5」は pattern=[0-9.]* に合わない（送信が止まる）']],
['<input type="text" name="area" pattern="[0-9.]*">', ['area' => '35.5'], []],
['<input type="number" name="x[]" min="0" step="1000">', ['x' => ['15000', '2500']], ['x[1]: 「2500」は step=1000 に乗らない（送信が止まる）']],
['<input type="number" name="d" min="0" step="1000" disabled>', ['d' => '1'], []],
['<input type="number" name="g" min="0" step="1000" :disabled="skip">', [], []],   // 送られない欄
['<input type="number" name="g" min="0" step="1000" :disabled="skip">', ['g' => '2500'], ['g: 「2500」は step=1000 に乗らない（送信が止まる）']],   // :disabled は素の disabled ではない
['<input type="hidden" name="h" min="1">', ['h' => '0'], []],
['<input type="number" name="s" data-step="3" min="0">', ['s' => '4'], []],         // data-step は step ではない
['<input type="text" name="r" required>', ['r' => ''], ['r: 空（required。送信が止まる）']],
```

- [ ] **Step 2: 赤を確かめる** — `…/phpunit tests/Feature/BrowserConstraintCheckTest.php` → `Trait "Tests\Concerns\ChecksBrowserConstraints" not found` で落ちる
- [ ] **Step 3: `formSlice()` を `ParsesForms` に移し、`ChecksBrowserConstraints` を書く**（上の Interfaces と規則のとおり。メールの形は WHATWG の正規表現、数の形は `^-?(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][-+]?\d+)?$`、刻みは浮動小数の誤差を許して比べる）
- [ ] **Step 4: 緑を確かめる** — 同じコマンドで OK。続けて `ParsesForms` を使う既存のテストの代表 `tests/Feature/Mansion/Screens tests/Feature/Tenant/AreaBuildingTenantCrudTest.php tests/Feature/Admin/TenantImportCellSpaceTest.php` を流して OK（`formSlice()` への移し替えで振る舞いが変わっていない）
- [ ] **Step 5: コミット** — `test: ブラウザの入力欄の制約をテストで確かめる道具を足す`

---

### Task 2: 賃貸マンションの金額の欄から刻み（step）を外す

**Files:**
- Test (Create): `tests/Feature/NumberInputStepScanTest.php`
- Test (Create): `tests/Feature/Mansion/Screens/SavedValuesRoundTripTest.php`（`MansionScreenTestCase` を継承）
- Modify: `tests/Feature/Mansion/Screens/MansionScreenTestCase.php`（`use ChecksBrowserConstraints;` を足す）
- Modify（`step="…"` だけを消す。`min="0"` は残す）:
  - `resources/views/mansion/rooms/_form.blade.php`（rent・common_fee・deposit・key_money）
  - `resources/views/mansion/parkings/_form.blade.php`（monthly_fee）
  - `resources/views/mansion/contracts/_form.blade.php`（rent・common_fee・deposit・key_money）
  - `resources/views/mansion/parking-contracts/_form.blade.php`（monthly_fee・deposit）
  - `resources/views/mansion/contracts/revise.blade.php`（new_rent・new_common_fee）
  - `resources/views/mansion/parking-contracts/revise.blade.php`（new_monthly_fee）
  - `resources/views/mansion/contracts/terminate.blade.php`（restoration_cost・cleaning_cost・other_deduction_amount[]）

**Interfaces:**
- Consumes: Task 1 の `assertBrowserSubmits(string $html, string $needle, array $fields)`
- Produces: `SavedValuesRoundTripTest`（Task 3 が同じファイルにテストを足す）

刻みの走査（`NumberInputStepScanTest`）:
- `resources/views` の Blade（`approvals/` の下は除く）から、Blade のコメント `{{-- --}}` を落としてから `step="…"`・`:step="…"`・`x-bind:step="…"` を拾う（`data-step` などの語の続きは拾わない）。1 つごとに、その `<input` のタグの `name` か `x-model`（修飾子付きを含む）の値を「欄」とする
- 鍵は `"{ビューの相対パス}|{欄}|{step の値}"`、分類表は `鍵 => [件数, 理由]`。見つけた鍵と分類表を両方向で突き合わせ、分類表に無い鍵（新しい刻み）と、分類表にあって見つからない鍵（古い分類）の両方を名指しして落とす（Top trap #13）
- 分類表（直したあとの全部。9 件）:

| 鍵 | 件数 | 理由 |
|---|---|---|
| `tenant/contracts/revise.blade.php\|depositMonths\|0.1` | 1 | 敷金の月数を打つ計算用の欄（name が無く送らない・初期値は空） |
| `tenant/units/revise.blade.php\|depositMonths\|0.1` | 1 | 同上 |
| `realestate/projects/_form.blade.php\|building_coverage\|10` と `…\|floor_area_ratio\|10` | 各 1 | 建蔽率・容積率は 10% 刻み。⚠ 刻みに乗らない保存済みの値は送信を止める（2026-11-13 の設計で範囲外にした） |
| `realestate/procurements/_form.blade.php\|building_coverage\|10` と `…\|floor_area_ratio\|10` | 各 1 | 同上 |
| `buyers/_survey_form.blade.php\|survey[{{ $qId }}]\|any` | 1 | 数値入力の設問（小数も可。範囲はサーバーが見る） |
| `buyers/_survey_form.blade.php\|survey[{{ $qId }}][sub][{{ $sf['key'] }}]\|any` | 1 | 条件分岐の付随の数の欄 |
| `buyers/_survey_form.blade.php\|sliderVal\|{{ $slider['step'] }}` | 1 | スライダー（name を持たず、送るのは hidden。Bug #120） |

- [ ] **Step 1: 刻みの走査のテストを書く** — `test_every_step_attribute_in_the_views_is_classified`（上の分類表）と `test_the_scanner_reads_only_step_attributes`（見本: `step="5"`・`:step="n"`・`x-bind:step="n"` は拾う／`data-step="3"`・`{{-- step="9" --}}`・`<script>` の中の `step: 1` は拾わない／欄は `name` が無ければ `x-model.number` の値）
- [ ] **Step 2: 赤を確かめる** — 走査のテストが、賃貸マンションの 17 か所を「分類されていない」と名指しして落ちる（見本のテストは緑）
- [ ] **Step 3: 往復のテストを書く** — `SavedValuesRoundTripTest` に次の 7 本。どれも編集画面（改定・解約は各画面）を描き、各ファイルの既存の `test_the_edit_screen_saves_what_it_shows` / `reviseForm()` / `terminateForm()` と同じ組み方でフォームを作り、**送る前に `assertBrowserSubmits()`**、送ったあとに保存された値が変わっていないことを見る。金額は旧い刻み（1000・500）のどちらにも乗らない値を使う:
  - `test_a_room_with_amounts_off_the_old_steps_is_saved_as_it_is` — 部屋 rent 65300・common_fee 3300・deposit 130600・key_money 65300。notes だけ変えて保存 → 4 つとも同じ
  - `test_a_parking_with_a_fee_off_the_old_step_is_saved_as_it_is` — 駐車場 monthly_fee 3300
  - `test_a_contract_with_amounts_off_the_old_steps_is_saved_as_it_is` — 部屋契約 rent 65300・common_fee 3300・deposit 130600・key_money 65300。memo だけ変えて保存
  - `test_a_parking_contract_with_amounts_off_the_old_steps_is_saved_as_it_is` — 駐車場契約 monthly_fee 3300・deposit 3300
  - `test_only_the_common_fee_is_revised_when_the_rent_is_off_the_old_step` — 部屋契約 rent 65300・common_fee 3300 → 改定画面で `data.newFee = 3500; data.reason = "…";` だけ → 改定履歴の new_rent 65300・new_common_fee 3500、契約の rent 65300
  - `test_a_parking_fee_is_revised_to_an_amount_off_the_old_step` — 駐車場契約 3300 → 3350
  - `test_termination_deductions_off_the_old_step_are_saved` — 解約画面で restorationCost 12345・cleaningCost 5500・差引の行 `{name: "鍵交換費", amount: 2500}` → 契約の restoration_cost 12345・cleaning_cost 5500・差引 `[['鍵交換費', 2500]]`
- [ ] **Step 4: 赤を確かめる** — 7 本とも `assertBrowserSubmits()` が `「…」は step=1000 に乗らない（送信が止まる）`（改定は `step=500`）で落ちる
- [ ] **Step 5: 17 か所の `step="…"` を消す**
- [ ] **Step 6: 緑を確かめる** — `…/phpunit tests/Feature/NumberInputStepScanTest.php tests/Feature/Mansion` が OK
- [ ] **Step 7: コミット** — `fix: 賃貸マンションの金額の欄の刻みを外し、取込で入った金額のまま保存できるようにする`

---

### Task 3: 賃貸マンションの部屋の階・物件の総戸数と階数の範囲、構造と間取りの選択肢に無い値

**Files:**
- Modify: `resources/views/mansion/rooms/_form.blade.php`（階 `min="0" max="255"`・間取りの選択肢に無い値）
- Modify: `resources/views/mansion/properties/_form.blade.php`（総戸数 `max="65535"`・階数 `max="255"`・構造の選択肢に無い値）
- Test: `tests/Feature/Mansion/Screens/SavedValuesRoundTripTest.php`（Task 2 のファイルに足す）

**Interfaces:**
- Consumes: Task 1 の `assertBrowserSubmits()`・Task 2 の `SavedValuesRoundTripTest`
- サーバーの範囲（変えない）: 部屋 `floor` 0〜255・`room_type` 20 文字・物件 `total_units` 0〜65535・`total_floors` 0〜255・`structure` 50 文字

- [ ] **Step 1: 失敗するテストを書く**（`SavedValuesRoundTripTest` に足す）:
  - `test_a_room_on_the_lowest_or_highest_floor_is_saved_as_it_is`（データ `0`・`255`）— 編集画面 → `assertBrowserSubmits()` → notes だけ変えて保存 → floor が同じ
  - `test_a_room_type_off_the_list_stays_selected_and_is_saved` — room_type `メゾネット` → 編集画面の HTML に `<option value="メゾネット" selected>メゾネット（選択肢に無い値）</option>`・`$form['fields']['room_type'] === 'メゾネット'` → 保存 → 同じ
  - `test_a_property_at_the_upper_limits_with_a_structure_off_the_list_is_saved_as_it_is` — total_units 65535・total_floors 255・structure `軽量鉄骨造` → 既存の `test_the_edit_screen_saves_what_it_shows` と同じ `composedForm(…, 'propertyForm', null, ['monthPicker' => ''])` → `assertBrowserSubmits()` → property_name だけ変えて保存 → 3 つとも同じ
  - `test_a_structure_off_the_list_is_escaped_and_survives_an_input_error`（Review Focus 1・2）— structure `RC造"<b>&` → 編集画面の HTML に `RC造&quot;&lt;b&gt;&amp;（選択肢に無い値）` があり生の `<b>` は無い → property_name を空にして送る → 戻った画面のフォームで structure が `RC造"<b>&` のまま → property_name を入れて送る → structure が 1 字も変わっていない
  - `test_a_value_in_the_list_or_blank_adds_no_extra_option`（Review Focus 3）— room_type `1LDK` と null の部屋・structure `S造` と null の物件の編集画面、部屋と物件の登録画面のどれにも `（選択肢に無い値）` が無く、`selected` な option は 1 つ以下
- [ ] **Step 2: 赤を確かめる** — 階は `「0」は min=1 より小さい`・`「255」は max=50 より大きい`、物件は `max=999`・`max=50`、選択肢に無い値は field が `''` で落ちる（エスケープのテストは option が無いことで落ちる）。最後のテストは緑のまま（直しすぎを止める守り）
- [ ] **Step 3: 2 つの `_form` を直す** — `@php` で `$roomTypeExtra`・`$structureExtra`（`is_string($v) && $v !== '' && ! in_array($v, $options, true)` のときだけ値、ほかは null）を作り、空の option の直後に Global Constraints の形で描く。値は今ある `$valRoomType`・`$valStructure`（`old()` → 保存済み）を使う。階・総戸数・階数の `min`/`max` を上の範囲に
- [ ] **Step 4: 緑を確かめる** — `…/phpunit tests/Feature/Mansion` が OK
- [ ] **Step 5: コミット** — `fix: 賃貸マンションの部屋と物件の編集で、範囲の端と選択肢に無い値をそのまま保存できるようにする`

---

### Task 4: 買主の都道府県・職業の選択肢に無い値とメールの欄

**Files:**
- Modify: `resources/views/buyers/_form.blade.php`（都道府県 187 行・職業 246 行・メール 235 行。住宅事業・不動産の買主の登録と編集が共用）
- Modify: `tests/Feature/RealEstate/Screens/RealEstateScreenTestCase.php`（`use ChecksBrowserConstraints;`）
- Test: `tests/Feature/RealEstate/Screens/CustomerScreensTest.php`

**Interfaces:**
- Consumes: Task 1 の `assertBrowserSubmits()`。生年月日は既存の `jsBirthDate($form)` で組む（`test_the_detail_and_edit_screens_save_what_they_show` と同じ）
- 職業の選択肢は `['会社員','公務員','自営業','医師','自由業','その他']`（`@php` の変数 `$occupations` にしてから描く）、都道府県はコントローラが渡す `$prefectures`

- [ ] **Step 1: 失敗するテストを書く**（`CustomerScreensTest` に足す）:
  - `test_a_customer_with_values_off_the_lists_and_a_loose_email_is_saved_as_it_is` — 買主 prefecture `東京`（選択肢は `東京都`）・occupation `パート`・email `yamada@` → 編集画面 → `assertBrowserSubmits()` → `fields` の prefecture・occupation がそのまま → employer だけ変えて保存 → 3 つとも同じ
  - `test_values_off_the_lists_survive_an_input_error`（Review Focus 1）— 同じ買主で phone を 21 文字にして送る → 電話番号の誤り → 戻った画面のフォームで prefecture `東京`・occupation `パート` が選ばれたまま → phone を直して送る → 3 つとも同じ
  - `test_a_prefecture_in_the_list_adds_no_extra_option`（Review Focus 3）— 買主 prefecture `愛媛県`・occupation null の編集画面と登録画面に `（選択肢に無い値）` が無い（登録画面の都道府県は今どおり `愛媛県` が選ばれている）
  - `test_the_email_field_does_not_ask_the_browser_to_check_the_form` — 登録画面と編集画面の `name="email"` のタグが `type="text"` と `inputmode="email"` を持ち、フォームに `type="email"` が無い（Bug #89 で買主のメールの形は見ないと決めている）
- [ ] **Step 2: 赤を確かめる** — 1 本目は `email: 「yamada@」はメールアドレスの形でない`、2 本目は field が `''`、4 本目は `type="email"` で落ちる。3 本目は緑のまま
- [ ] **Step 3: `buyers/_form.blade.php` を直す** — 都道府県・職業に Task 3 と同じ形で選択肢に無い値を足す（値は今の `old('prefecture', $isEdit ? $buyer->prefecture : '愛媛県')`・`old('occupation', …)`）。メールは `type="text" inputmode="email" autocomplete="email"` にし、理由を Blade のコメントに書く（ZEAL 会員の `zeal/members/_form.blade.php` 153 行と同じ書き方。Bug #89・#131）
- [ ] **Step 4: 緑を確かめる** — `…/phpunit tests/Feature/RealEstate/Screens/CustomerScreensTest.php tests/Feature/Housing tests/Feature/LongUnbrokenValueWrapTest.php` が OK（`LongUnbrokenValueWrapTest` の `type="email"` は見本の 1 行だけで、実物の欄の型は見ていない）
- [ ] **Step 5: コミット** — `fix: 買主の編集で選択肢に無い都道府県・職業と形の崩れたメールをそのまま保存できるようにする`

---

### Task 5: 築年月の正規化を 1 か所にし、テナントの物件の取込の築年月と階数を断る

**Files:**
- Modify: `app/Support/CsvDate.php`（`normalizeYearMonth()` を足す）
- Modify: `app/Http/Controllers/Admin/TenantImportController.php` 256〜273 行（物件の築年月・階数）
- Modify: `app/Http/Controllers/Admin/MansionImportController.php` 306〜316 行（物件の築年月）
- Test: `tests/Unit/Support/CsvDateTest.php`
- Test (Create): `tests/Feature/Admin/TenantPropertyImportValuesTest.php`（`ParsesForms`・`SubmitsImportPreview`・`ChecksBrowserConstraints`。`importBasePath()` は `/admin/tenant-import`）
- Test (Create): `tests/Feature/Admin/MansionPropertyImportValuesTest.php`（`CreatesMansionSchema`・`ParsesForms`・`SubmitsImportPreview`。`importBasePath()` は `/admin/mansion-import`）

**Interfaces:**
- Produces: `CsvDate::normalizeYearMonth(string $value): ?string` — `/` を `-` にし、`^(\d{4})-(\d{1,2})$`（ASCII の数字だけ）で月が 1〜12 なら `sprintf('%04d-%02d', …)`、ほかは null。前後の空白は落とさない（取込の読み手が `Str::trim()` 済み）

⚠ 設計との違い: 設計は「月が 1〜12 でなければエラー（賃貸マンションの取込と同じ）」としていたが、賃貸マンションの取込も月を 2 桁にそろえるだけで範囲は見ていない（`2010-13` が入り、編集画面の年月の部品が `2011-01` に作り直す）。同じ部品を両方の取込に使い、賃貸マンションも直す。

- [ ] **Step 1: 失敗するテストを書く**
  - `CsvDateTest::test_a_year_and_month_is_normalized`（データ: `2010-4`→`2010-04`・`2010/4`→`2010-04`・`2010-04`→`2010-04`・`2010/12`→`2010-12`）と `test_a_year_and_month_that_cannot_be_read_is_null`（Review Focus 4。データ: `2010-13`・`2010-00`・`2010-4-1`・`2010`・`10-04`・`２０１０-04`・`2010-1a`）
  - `TenantPropertyImportValuesTest`:
    - `test_a_built_month_is_saved_with_two_digits_and_the_edit_screen_keeps_it` — 物件の CSV の築年月 `2010/4` を確定 → `built_date` が `2010-04` → `tenant.properties.edit` を開き、`parseForm()` のフォームで `assertBrowserSubmits()` → notes だけ変えて PUT → `built_date` が `2010-04` のまま
    - `test_a_month_that_does_not_exist_is_an_error_row` — 行 2 `2010-13`・行 3 `2010-00`・行 4 `2010-04` → プレビューの `rowErrors` が 2 行（Global Constraints の文の全文）・`validCount` 1 → 確定で行 4 の物件だけが入る
    - `test_floors_up_to_99_are_imported_and_more_is_an_error_row` — `99` は入り、`100`・`0` は `階数「100」は1〜99の整数で入力してください`・`階数「0」は1〜99の整数で入力してください`
  - `MansionPropertyImportValuesTest::test_a_month_that_does_not_exist_is_an_error_row` — 物件の CSV の築年月 `2010-13` → `rowErrors` に同じ文・`2010/4` の行は `2010-04` で入る
  - CSV の見出しと 1 行の形は `TenantImportCellSpaceTest::PROPERTY_HEADER`・`MansionImportTest` の物件の行に合わせる（行の終わりは CRLF）
- [ ] **Step 2: 赤を確かめる** — `CsvDateTest` はメソッドが無い、テナントの往復は `built_date` が `2010-4`、誤りの行のテストは `validCount` が 3・`rowErrors` が空、階数は `100` が入って落ちる
- [ ] **Step 3: `CsvDate::normalizeYearMonth()` を書き、2 つの取込が使う** — テナントは保存する値を `normalizeYearMonth()` の結果にし、null なら上の文で誤りの行（`continue`）。階数は `ctype_digit` でないか 1 未満か 99 より大きければ上の文。賃貸マンションの築年月の塊も同じ部品と同じ文に置き換える（総戸数・階数の扱いは変えない）
- [ ] **Step 4: 緑を確かめる** — `…/phpunit tests/Unit/Support/CsvDateTest.php tests/Feature/Admin` が OK
- [ ] **Step 5: コミット** — `fix: 取込の築年月を 2 桁の月にそろえて無い月を断り、テナントの物件の階数を 99 までにする`

---

### Task 6: 顧客の取込で日本の今日より後の生年月日を断る

**Files:**
- Modify: `app/Support/BuyerCsvRow.php`（`from()` に今日を渡す・`check()` の生年月日）
- Modify: `app/Http/Controllers/Admin/CustomerImportController.php` 185 行（`BuyerCsvRow::from($values, $today)`。`$today` は行の繰り返しの前で 1 回作る）
- Test: `tests/Unit/Support/BuyerCsvRowTest.php`
- Test: `tests/Feature/Admin/CustomerImportTest.php`

**Interfaces:**
- Produces: `BuyerCsvRow::from(array $values, string $today): self` — `$today` は日本の今日の `Y-m-d`。生年月日が読めて `$today` より後なら Global Constraints の文で誤り（列の順は今どおり）。取得日は見ない（画面の取得日も今日より後を断らない）。`BuyerCsvRow` は今日を自分で読まない（docblock の「Laravel を起動しない Unit テストで使うので、時刻や timezone に頼る処理を入れない」を守る。docblock に引数の説明を足す）

- [ ] **Step 1: 失敗するテストを書く**
  - `BuyerCsvRowTest`: 定数 `private const TODAY = '2026-11-13';` を置き、`row()` と直接の `BuyerCsvRow::from(` の 4 か所に渡す。足すテスト:
    - `test_a_birth_date_after_today_is_an_error` — `2026-11-14` → `['生年月日「2026-11-14」は今日（2026-11-13）より後の日付です']`、`buyer` に birth_date が無い
    - `test_a_birth_date_of_today_is_kept` — `2026-11-13` → 誤り無し・`buyer['birth_date'] === '2026-11-13'`
    - `test_the_acquired_date_may_be_after_today` — 取得日 `2026-12-01` → 誤り無し
  - `CustomerImportTest::test_a_birth_date_after_the_japanese_today_is_an_error_row`（Review Focus 5）— `Carbon::setTestNow(Carbon::parse('2026-11-13 00:30', 'Asia/Tokyo'))`（UTC ではまだ 11/12）→ 生年月日 `2026-11-13` の行と `2026-11-14` の行 → `rowErrors` は 2 行目の 1 件だけ（全文）・`validCount` 1 → 確定で `2026-11-13` の人だけが入る。`tearDown` か `finally` で `Carbon::setTestNow()` を戻す
- [ ] **Step 2: 赤を確かめる** — Unit は `2026-11-14` が誤りにならずに落ちる（PHP は余った引数を黙って受けるので、引数の数では落ちない）、Feature は `2026-11-14` の行も入って落ちる
- [ ] **Step 3: `from()` と `check()` を直し、コントローラが `JapanTime::today()->toDateString()` を渡す**
- [ ] **Step 4: 緑を確かめる** — `…/phpunit tests/Unit/Support/BuyerCsvRowTest.php tests/Feature/Admin/CustomerImportTest.php` が OK
- [ ] **Step 5: コミット** — `fix: 顧客の取込で日本の今日より後の生年月日をその行の誤りにする`

---

### Task 7: 変異テスト・全件・実ブラウザ・記録

**Files:**
- Create（git の管理外）: `.claude/route-coverage-tools/mutate/specs.import-edit-roundtrip.py`（`specs.py` に写して流す。前回の表は `specs.zeal-member-edit-saved-values.py`）
- Modify: `docs/RULES.md`（Bug #131 の行）・`docs/BACKLOG.md`（節）・`CLAUDE.md`（「全 130 件」「Bug #1–130」を 131 に）

- [ ] **Step 1: 変異の表を書く** — 流す前に、どのテストが・どの理由の 1 行目で落ちるかを書く（`expect`）。当てる変異（カナリアを含む）:

| id | 当てる所 | 変異 | 落ちるはずのテスト |
|---|---|---|---|
| C0 | rooms/_form | 「（選択肢に無い値）」の文を変える（カナリア） | 部屋の間取りのテスト |
| S1〜S4 | rooms/_form rent・contracts/revise new_rent・terminate other_deduction_amount[]・parking-contracts/_form monthly_fee | `step` を元に戻す | 走査 ＋ その画面の往復 |
| M1〜M4 | rooms/_form floor min・max／properties/_form total_units max・total_floors max | 元の値に戻す | 階のデータ 1 つ／物件の上限のテスト |
| X1〜X4 | 間取り・構造の足す option | 消す／`! in_array` を外す／`selected` を外す／`$valStructure` でなく保存済みの値で描く | 間取り・構造のテスト／足さない守り／入力エラーのテスト |
| B1〜B4 | buyers/_form | 都道府県・職業の足す option を消す／メールを `type="email"` に戻す／保存済みの値で描く | 買主の 4 本のうち該当するもの |
| I1〜I6 | CsvDate・2 つの取込 | 月の範囲を外す／2 桁にそろえない／テナント・賃貸マンションを元の塊に戻す／階数の上限を外す／`> 99` を `> 100` に | `CsvDateTest`・取込の 3 ファイル |
| D1〜D3 | BuyerCsvRow・コントローラ | 生年月日の確かめを外す／`>` を `>=` に／コントローラが `now()->toDateString()` を渡す | Unit 2 本・Feature（0:30 JST） |
| T1〜T4 | 道具 | 刻みを見ない／min・max を見ない／email を見ない／走査が `data-step` も拾う | 道具の見本 ＋ 使う往復／走査の見本と実物（`housing/custom-orders/index` の `data-step`） |

- [ ] **Step 2: 先にコミットして作業ツリーを空にし、流す** — `cp .claude/route-coverage-tools/mutate/specs.import-edit-roundtrip.py .claude/route-coverage-tools/mutate/specs.py` → `WT=/Users/masanori/site/manage/.claude/worktrees/nostalgic-jennings-a2186c python3 .claude/route-coverage-tools/mutate/run.py`。期待どおり（落ちたテストの組と理由の 1 行目まで）でない変異は、表の誤りか穴かを見分け、穴ならテストを足してから流し直す。流したあと `git status --porcelain` が空
- [ ] **Step 3: 全件とビューの lint** — 全件が OK（4818 本＋足した分）。`php artisan view:cache` のあと `storage/framework/views/*.php` を 1 本ずつ `php -l` して INVALID 0（318 本の見込み・新しいビューは無い）、`php artisan view:clear`（どれも `APP_KEY` を渡す）
- [ ] **Step 4: 実ブラウザ**（`.claude/route-coverage-tools/browser/README.md` の使い捨ての環境・Playwright・画面が見えている状態）— 次をそれぞれ「更新する」で保存し、帯が成功で値が変わらないことを見る: 賃貸マンションの部屋（rent 65300・floor 0・間取り `メゾネット`）／物件（総戸数 65535・構造 `軽量鉄骨造`）／部屋契約の改定（rent 65300 で共益費だけ変える）／買主（`東京`・`パート`・`yamada@`）／テナントの物件の取込で `2010/4` を入れたあとの編集画面。3 つの編集画面は 1440px・375px で `main` の横スクロール 0。コンソールのエラー 0
- [ ] **Step 5: 記録** — `docs/RULES.md` に Bug #131 の行（症状＝取込で入った値が編集画面で保存できない・黙って変わる形が賃貸マンション・買主・テナントの物件にあった（本番 0 件）／原因／直し方＝上の 6 つと、ブラウザの制約を確かめる道具と刻みの走査／検証の数）。`docs/BACKLOG.md` に節（直したこと・範囲外・検証）。`CLAUDE.md` の件数
- [ ] **Step 6: コミット** — `docs: 取込で入った値を編集画面からそのまま保存できるようにした直しを Bug #131 と BACKLOG に記録する`

## 範囲外（設計の「しないこと」と、計画の段で気づいたこと）

- 部屋契約の入居者が駐車場のみ利用者のとき（D5）・テナントの顧客と賃貸マンションの入居者のメール・不動産の建蔽率／容積率の `step="10"`・本番のデータの書き換え
- 賃貸マンションの取込は総戸数・階数・部屋の階・構造・間取りの上限を見ない（列を超えると確定で DB が断り、取込全体が取り消されて理由が出る＝Bug #89 の形。保存はされないので編集画面の往復の問題ではない）
- 道具の死角（`ChecksBrowserConstraints` の docblock）: `maxlength`・name の無い欄・select と textarea
