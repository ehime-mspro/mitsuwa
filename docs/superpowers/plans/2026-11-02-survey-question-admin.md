# アンケート設問の管理の編集と並び替え — 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `/admin/survey-questions` で、経営層が JSON を書かずに、設問の追加・編集（文・選択肢・種類ごとの設定・有効／無効）・並び替え・削除を画面で行えるようにする。直しても過去の回答は消えない。顧客の CSV 取込は、並びや文が変わった古いテンプレートを断る。

**Architecture:** 追加・編集は別の画面（ふつうのフォームの送信）にし、選択肢の行は Alpine の `<template x-for>`（条件分岐の付随の欄は行の中の行）で足す・外す・並べる。入力を保存の形（設計 5 章）へ組み立てる処理・画面の最初の状態・中身の検査・選択肢ごとの回答の件数は、新しいクラス `App\Support\SurveyQuestionForm` の 1 か所に置く。入力チェックの規則と和名はコントローラにリテラルで書く（和名の走査が読めるように）。一覧の ↑↓ は押すたびにフォームを送る。

**Tech Stack:** Laravel 12・PHP 8.3（本番も 8.3）・Blade と Alpine 3・PHPUnit・node の vm（`DrivesAlpineFetch`）

**Spec:** `docs/superpowers/specs/2026-11-02-survey-question-admin-design.md`（枝 `survey-question-admin` のコミット `323a6af9` 以降。絶対パス `/Users/masanori/site/manage/.claude/worktrees/route-coverage/docs/superpowers/specs/2026-11-02-survey-question-admin-design.md`）。設計書の章を「設計 4.2」のように書く。

## Context（なぜやるか）

- 今の画面は追加と削除しかできない。「編集」は一覧を開き直すだけ、並び替えは案内だけで画面から呼べない（設計 1 章）
- 選択肢を 1 つ足すにも、回答がある設問は無効にして別の設問として足し直すしかなく、回答が分かれる。無効にした設問は戻せない
- 選択肢は JSON の欄でしか入れられず、スライダーの範囲を入れる欄が無い
- 利用者の決定 D1〜D8 と画面の見本（設計 3・4 章）を実装する

## 作業場所・流し方

- 作業場所: `/Users/masanori/site/manage/.claude/worktrees/route-coverage`（枝 `survey-question-admin`。親は `13.x` = `6ba8ed07`）。コマンドはこのフォルダで流す
- テスト: `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php -d opcache.enable_cli=1 -d opcache.memory_consumption=256 vendor/bin/phpunit <パス>`（全件は約 3 分なので run_in_background）。`.env` は作らない
- 使い捨てのテストは scratchpad に置き、`namespace Tests\Probe` で作業場所から流す
- 変異の道具: `/Users/masanori/site/manage/.claude/route-coverage-tools/mutate/run.py`（`specs.py` を読む。表は `specs.survey-question-admin.py` に書いて `specs.py` へ写す）
- 実ブラウザの使い捨ての環境: `/Users/masanori/site/manage/.claude/route-coverage-tools/browser/README.md`（`demo.sqlite` に `survey_questions` は無い → Task 0 で scratchpad の写しに足す）
- 「社内決裁申請」の会話の画面とテスト（`approvals/`）には触らない

## Global Constraints（全タスク共通）

- DB の変更はしない（`survey_questions` の列はそのまま。`options`・`settings` の JSON の形は設計 5 章）。本番の回答を書き換えない
- 新しい PHP クラスが 1 つ増える（`app/Support/SurveyQuestionForm.php`）。本番反映では main repo の cwd で `composer dump-autoload --no-dev --optimize`（この計画では反映しない）
- 上限（`SurveyQuestionForm` の定数。コントローラの規則もこれを使う）: 選択肢の名前 100 文字・選択肢 50 行・付随の欄 10 行（1 選択肢あたり）・付随の欄の見出し 100 文字・付随テキストの見出し 100 文字・単位 10 文字・見本の文字 100 文字・付随の欄の見出し（設問の `sub_heading`）50 文字・設定の数は整数で −999,999,999〜999,999,999・設問文 255 文字（今と同じ）
- 和名（コントローラの `Validator::make` の第 4 引数。キーは規則と文字列で完全一致）: `label`=設問文（全体の和名と同じ。`Admin/Master/ArrayInputTest` が頼る）・`question_type`=種類・`is_active`=有効・`department`=部署・`options.*.value`=「選択肢 :position 行目の名前」・`options.*.sub_fields.*.label`=「選択肢 :position 行目の欄 :second-position 行目の見出し」（Laravel 12 の 2 段目は `:second-position`）・`settings.min`=下限・`settings.max`=上限・`settings.step`=刻み・`settings.unit`=単位・`settings.placeholder`=入力欄の見本の文字・`settings.sub_heading`=付随の欄の見出し。ほかの行の欄も同じ形で全部書く
- JS に渡す値は `{{ \Illuminate\Support\Js::from(…) }}`。画面の最初の状態は配列をコントローラで組み、ビューでは `Js::from($formState)` の 1 つだけにする（Bug #26）。新しいビューに `old(` を書かない（最初の状態はコントローラが `$request->old()` から組む）
- `<option>` は静的に描く（Top trap #3）・`x-data` は名前つきの関数（#4）・`style` と `:style` を同じ要素に置かない（#5・Bug #32）・押せないボタンの理由は包む `<span title>` で（#12）・view データに `errors` を渡さない（#14）
- 種類で出し分ける欄は `x-show` で隠し、`:disabled` で送らない（同じ `name` の `settings[min]` が数値入力とスライダーにある）
- 入力エラーの箱は各画面の上に `@if($errors->any())` で `<p class="text-sm text-red-800">` を 1 件ずつ（`dad-specialties/create.blade.php` と同じ形）。一覧にも置く（↑↓ のフォームを足すので `ValidationErrorFeedbackTest` の対象になる）
- テストは描いた画面のフォームから送る（Bug #47）。Alpine の行は `browserForm()` で組む。入力エラーで戻った画面は `assertSessionHas*()` を呼ばずに描いて全文で見る（Bug #49）。成功の帯は `assertFlash()`
- 新しく足すテストは、直す前のコードで赤を確かめてから直す（道具・新しいクラスのテストは、無い状態での赤＝クラス・メソッドが無い）
- コミットは Conventional Commits（件名は日本語・72 文字以内・句点なし）、1 コミット 1 関心事、本文の最後に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`（モデルを指定したサブエージェントは自分の文脈の行）。`git stash` と `--no-verify` は使わない

## Review Focus（どのタスクのテストもまだ通らない入力。持ち主のタスクにテストを足した）

1. **本番と同じ形の設問**（問 1: 選択肢＋付随テキストで「ご紹介」「その他」だけ文字あり・問 2: 条件分岐で付随の欄の `key` が選択肢をまたいで重なる・`type` の無い欄）を編集画面からそのまま保存しても、`options`・`settings` が同じ中身のまま → Task 5 `test_saving_a_production_shaped_question_unchanged_keeps_its_definition`
2. **壊れやすい文字**（`'` `"` `&` `<` 末尾の `\`・カンマ）を含む設問文と選択肢: 編集画面の JS が止まらず元の文字のまま戻る・CSV テンプレートの見出しが割れない → Task 5 `test_tricky_characters_round_trip_through_the_edit_screen`・Task 7 `test_a_label_with_a_comma_survives_the_template`
3. **回答がある選択肢を × で外す・名前を変える**: 回答の行は残り、アンケートの編集画面で「（選択肢に無い値）」として選ばれたまま → Task 5 `test_removing_or_renaming_an_answered_option_keeps_the_answers`
4. **無効の設問をまたぐ並び替えと番号**: 無効の設問は番号が無く、↑↓ は無効の設問とも入れ替わる・端の設問の手組みの ↑ は理由つきで断る → Task 6 `test_moving_passes_over_inactive_questions_and_numbers_only_active_ones`・`test_moving_past_the_edge_is_refused`
5. **手で組んだ送信**（`options` が文字・`sub_fields` の `key` が規則に合わない・押せない種類の欄を送る・`direction=sideways`）で 500 にならない → Task 5 `test_a_locked_type_sent_by_hand_is_refused`・Task 6 `test_tampered_move_and_form_posts_do_not_crash`

---

## Task 0: 計画を写し、本番の設問の形と測っていない項目を確かめる（コードは変えない）

**Files:**
- Create（コミットする）: `docs/superpowers/plans/2026-11-02-survey-question-admin.md`（この計画を写したもの。後で実測記録を足す）
- Create（コミットしない）: `<scratchpad>/probe/SurveyQuestionProbeTest.php`（`namespace Tests\Probe`・`Tests\Feature\Admin\Master\MasterScreenTestCase` を継承）、`<scratchpad>/survey-env.php`（実ブラウザ用）

- [ ] **Step 1: この計画を `docs/superpowers/plans/2026-11-02-survey-question-admin.md` に写してコミットする**（`docs: アンケート設問の管理の実装計画を足す`）
- [ ] **Step 2: 本番の設問の定義を読む（利用者の承認を取ってから・読むだけ）**。`ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh < 手順ファイル` で `artisan tinker --execute`（php は `/usr/local/php/8.3/bin/php`）。読むのは `survey_questions` の `id, department, sort_order, is_active, question_type, options, settings` と設問ごとの回答の件数だけ（買主の情報は読まない）。**`options` の要素に設計 5 章に無いキー（`value` `has_text` `text_label` `sub_fields`・欄の `key` `label` `type` 以外）があれば、そこで止めて利用者に知らせる**（保存で落ちるため）。設問文・選択肢の中身はリポジトリに写さず、形（キーの組・数）だけを記録する
- [ ] **Step 3: 使い捨てのテストで M1〜M3 を測る**

| # | 測ること | 見込み（引き継ぎ） |
|---|---|---|
| M1 | 今の画面の追加の XHR（`roundTrip()` と同じく JS が組んだ要求を `sendCapturedLikeBrowser()` で送る）のあと、一覧を開き直したときに「設問を追加しました。」が帯に出るか | 出ない（XHR の転送の GET でフラッシュを使い切る） |
| M2 | `PUT` で `options` に壊れた JSON（`'["A",'`）を送る | 今の選択肢が null になる |
| M3 | 回答がある設問に `PUT` で `question_type` を別の種類にして送る | 種類が変わる |

- [ ] **Step 4: M4（追加の小窓が中央にそろうか）を実ブラウザで測る**。README の手順で使い捨ての環境を起動し（DB は scratchpad に写した `demo.sqlite`。`survey-env.php` で `CreatesSurveyQuestionSchema` と同じ列の `survey_questions` を作り、住宅事業に本番と同じ構成の 6 問を足す。問 2 は付随の欄の `key` が選択肢をまたいで重なる形・問 6 はスライダー 2000〜6000・100 刻み・万円）、Playwright（画面が見えている状態）で一覧の「設問を追加」を押し、小窓の箱の `getBoundingClientRect()` の左右の余白と、小窓の外枠の `display` を見る。見込み: `x-show` が静的な `display:flex` を消して `block` になり、中央にそろわない（Bug #32）。環境はこのあとの Task 9 でも使う
- [ ] **Step 5: 結果を計画の「実測記録 Task 0」に書いてコミットする**（`docs: アンケート設問の管理の直す前の実測を記録する`）。見込みと違っても作り直しで消える項目なので止めずに記録する。ただし Step 2 で止まったときは利用者の返事を待つ

---

## Task 1: 行の中の行（入れ子の `x-for`）を `browserForm()` で送れるようにする

条件分岐の付随の欄は「選択肢の行の中の行」になる。今の道具は入れ子の `x-for` の中の入力を「扱えない」で止める（`DrivesAlpineFetch.php:259-260`）。

**Files:**
- Modify: `tests/Concerns/DrivesAlpineFetch.php`（`formModel()` と JS の `ALPINE_HARNESS` の送る項目の組み立て）
- Create: `tests/Feature/BrowserFormNestedRowsTest.php`

**Interfaces:**
- Produces: `browserForm()` の振る舞いの広がり（シグネチャは変えない）。`formModel()` の戻り値の `loops` の要素が `['for' => string, 'controls' => array, 'loops' => array]`（同じ形の入れ子）になる。`x-for` でない `<template>` の中の入力は今までどおり止める

- [ ] **Step 1: 失敗するテストを書く**（HTML は文字列で組む。`x-data="nestedForm()"` と `<script>function nestedForm() { return { rows: [{ name: 'A', subs: [{ label: 'a1' }, { label: 'a2' }] }, { name: 'B', subs: [] }] }; }</script>`、フォームの中に `<template x-for="(row, i) in rows"><div><input :name="'rows[' + i + '][name]'" x-model="row.name"><template x-for="(sub, j) in row.subs"><input :name="'rows[' + i + '][subs][' + j + '][label]'" x-model="sub.label" :disabled="sub.label === 'off'"></template></div></template>`）
  - `test_inputs_in_rows_inside_rows_are_sent`: `fields['rows']` が `[['name' => 'A', 'subs' => [['label' => 'a1'], ['label' => 'a2']]], ['name' => 'B']]`
  - `test_inner_rows_follow_the_state_after_the_steps`: steps `data.rows[1].subs.push({ label: 'b1' }); data.rows[0].subs.splice(0, 1);` で `rows.0.subs` が `[['label' => 'a2']]`・`rows.1.subs` が `[['label' => 'b1']]`
  - `test_disabled_inner_inputs_are_not_sent`: steps `data.rows[0].subs[1].label = 'off';` で `rows.0.subs` が `[['label' => 'a1']]`
  - `test_inputs_in_a_template_without_x_for_still_stop`: `<template x-if="true"><input name="x"></template>` のフォームで `browserForm()` が `x-for でない <template>` の文で失敗する（`expectException(\PHPUnit\Framework\AssertionFailedError::class)` と `expectExceptionMessage('x-for でない <template> の中の入力は扱えない')`）
- [ ] **Step 2: 流して、最初の 3 本が「入れ子の x-for の中の入力は扱えない」で赤・4 本目が緑を確かめる**
- [ ] **Step 3: 直す**。`formModel()` は内側の `loops` を止めずに `['for', 'controls', 'loops']` として持つ（`assertSame([], $inner['loops'], …)` を消す）。JS は一番外の行を回す所を、行のスコープを渡して内側の `loops` を同じ関数で回す再帰（`walkLoop(loop, scope)`）にする。`eachRow()` は外のスコープを受け取るので、内側の `x-for` の式（`row.subs`）は外の行の変数で評価される
- [ ] **Step 4: 4 本が緑・道具を使う既存のテスト（`tests/Feature/Dad/Screens` `tests/Feature/Housing/Screens` `tests/Feature/Mansion/Screens` `tests/Feature/RealEstate/Screens` `tests/Feature/Tenant/Screens` `tests/Feature/Admin/Master`）が緑を確かめる**
- [ ] **Step 5: コミット**（`test: 画面のフォームの道具で行の中の行を送れるようにする`）

---

## Task 2: アンケートの画面の側（スライダーの最初の位置・付随の欄の見出し・数の欄）と、選んだ値の読み出し

**Files:**
- Modify: `app/Support/SurveyAnswers.php`（`sliderState()`・新しい `chosenValues()`）
- Modify: `resources/views/buyers/_survey_form.blade.php`（157 行目の見出し・71〜74 行目の数の欄）
- Test: `tests/Unit/Support/SurveyAnswersTest.php`、`tests/Feature/RealEstate/Screens/SurveyScreensTest.php`

**Interfaces:**
- Produces: `public static function chosenValues(SurveyQuestion $question, ?string $answerValue): array`（`list<string>`。保存された回答が選んだ選択肢の値。単一選択は `[値]`・複数選択はその並び・選択肢＋付随テキストは要素の `value` の並び・条件分岐は `[value]`・空や読めない形・テキスト／数値／スライダーは `[]`。`decode()` の結果から作る）
- Produces: `formState()` のスライダーの未回答の `value` が刻みの上（下限から数えて、真ん中以下で最も近い刻み）になる

- [ ] **Step 1: 失敗するテストを書く**
  - `SurveyAnswersTest::test_chosen_values_read_each_saved_shape`（データ提供で 7 種類＋空＋読めない形）: 単一選択 `'B'` → `['B']`・複数選択 `'["A","C"]'` → `['A', 'C']`・選択肢＋付随テキスト `'[{"value":"ご紹介","text":"山田様"},{"value":"チラシ"}]'` → `['ご紹介', 'チラシ']`・条件分岐 `'{"value":"建売住宅の購入","sub":{"city":"松山市"}}'` → `['建売住宅の購入']`・テキスト `'123'` → `[]`・null → `[]`・複数選択 `'A,B'` → `[]`・条件分岐 `'{"value":null,"sub":{"city":null}}'` → `[]`
  - `SurveyAnswersTest::test_the_unanswered_slider_starts_on_a_step`: 0〜300・刻み 100 の未回答の `value` が `'100'`（今は `'150'`）・1〜10・刻み 3 が `'4'`・2000〜6000・刻み 100 が `'4000'`（今と同じ）
  - `SurveyScreensTest::test_the_sub_field_heading_comes_from_the_question`: 条件分岐の設問の `settings` に `['sub_heading' => '希望エリア']` があると `>▼ 建売住宅の購入 — 希望エリア</div>`、`sub_heading` が無い設問では `>▼ 建売住宅の購入</div>`（「希望エリア」の文字が見出しに出ない）
  - `SurveyScreensTest::test_number_fields_leave_the_range_to_the_server`: `settings` に `min`・`max` がある数値入力の欄の開始タグに `min=` `max=` が無い（保存済みの範囲の外の回答の送信をブラウザが止めないように。設計 9 章）
- [ ] **Step 2: 流して 4 本が赤を確かめる**（`chosenValues` は未定義・`150`・固定の「希望エリア」・`min="10"`）
- [ ] **Step 3: 直す**。`sliderState()` の未回答の値は `$min + intdiv(intdiv(max(0, $max - $min), 2), $step) * $step`。見出しは `$settings['sub_heading']` が空でない文字のときだけ ` — 見出し` を付ける（`@if` の直後に `<` を置かない。Bug #6 → 三項演算の `{{ }}` で）。数の欄の `min`・`max` 属性を外す（`step="any"` は残す）
- [ ] **Step 4: 既存のテストの見出しを直す**: `SurveyScreensTest` の土台の条件分岐の設問（51〜56 行目の形）に `settings => ['sub_heading' => '希望エリア']` を足し、538 行目の `' — 希望エリア</div>'` のアサートがそのまま通ることを確かめる
- [ ] **Step 5: 4 本と `SurveyAnswersTest`・`SurveyScreensTest`・`SurveyAnswerJsTest`・`CustomerScreensTest` が緑を確かめる**
- [ ] **Step 6: コミット**（3 つに分ける: `fix: スライダーの最初の位置を刻みの上に寄せる` ／ `feat: 条件分岐の付随の欄の見出しを設問ごとに出す` ／ `fix: アンケートの数の欄の範囲をサーバーの検査に任せる`。`chosenValues()` は最初のコミットに入れず、Task 3 で使うときに `feat: 保存された回答が選んだ選択肢の値を読み出す` として別に入れてもよい）

---

## Task 3: `App\Support\SurveyQuestionForm`（保存の形の組み立て・最初の状態・中身の検査・回答の件数）

**Files:**
- Create: `app/Support/SurveyQuestionForm.php`
- Create: `tests/Unit/Support/SurveyQuestionFormTest.php`（`Tests\TestCase` を継承。`CreatesRealEstateSchema`・`CreatesSurveyQuestionSchema` で回答の件数を見る）

**Interfaces:**
- Consumes: `SurveyAnswers::chosenValues()`（Task 2）、`SurveyQuestionType`
- Produces（どれも `public static`）:
  - 定数 `OPTION_NAME_MAX = 100`・`OPTIONS_MAX = 50`・`SUB_FIELDS_MAX = 10`・`SUB_LABEL_MAX = 100`・`TEXT_LABEL_MAX = 100`・`UNIT_MAX = 10`・`PLACEHOLDER_MAX = 100`・`SUB_HEADING_MAX = 50`・`SETTING_LIMIT = 999999999`
  - `isChoice(SurveyQuestionType $type): bool`（単一・複数・付随テキスト・条件分岐）
  - `state(?SurveyQuestion $question, string $department, ?array $old): array` — 画面の最初の状態。キーは `department` `label` `questionType` `isActive` `typeLocked` `answerCount` `options`（各行 `value` `original` `hasText` `textLabel` `subFields`（各 `key` `label` `type`）`answerCount`）`settings`（`min` `max` `step` `unit` `placeholder` `subHeading`）。値は文字（`isActive`・`hasText`・`typeLocked` は bool、件数は int）。`$old` があればそこから組み、形の合わない値は空として扱う（配列でない `options` は `[]`）。`$old` の `question_type` が無いときは設問の今の種類。新しい設問の既定: 単一選択・有効・選択肢 1 行（空）
  - `definition(SurveyQuestionType $type, array $data, ?SurveyQuestion $current): array` — 検査を通った入力から `['options' => ?array, 'settings' => ?array]`（設計 5 章の形。選択肢＋付随テキストは `has_text` をどの選択肢にも bool で書き（false も）、`text_label` は `has_text` が真で空でないときだけ。条件分岐はどの選択肢にも `sub_fields` を書く（欄が無ければ `[]`）。本番の書き方に合わせる＝実測記録 Task 0「本番の設問の形」）。全部が空の行・欄は落とす。新しい付随の欄（`key` が空）には、その設問のどの選択肢でも使っていない `f` ＋英小文字と数字 8 文字のキーを付ける。今ある `key` は変えない
  - `problems(SurveyQuestionType $type, array $data): array` — `[項目のキー => 文]`。規則では書けない検査（下の表）
  - `answerCounts(SurveyQuestion $question): array` — `['total' => int, 'options' => [選択肢の値 => int]]`

`problems()` の検査と文（キーの数字は 0 始まり・文の行番号は 1 始まり）:

| 場合 | キー | 文 |
|---|---|---|
| 選択肢の種類で、名前のある行が 0 | `options` | `選択肢を 1 つ以上入れてください。` |
| 名前が空で、ほかの欄（文字も入れる・見出し・付随の欄）に何かある行 | `options.N.value` | `選択肢 N 行目の名前を入力してください。` |
| 同じ名前の行（2 つ目以降） | `options.N.value` | `選択肢 N 行目の名前「X」は M 行目と同じです。` |
| 見出しが空でキーのある付随の欄 | `options.N.sub_fields.K.label` | `選択肢 N 行目の欄 K 行目の見出しを入力してください。` |
| 1 つの選択肢の中で同じキーの欄 | `options.N.sub_fields.K.key` | `選択肢 N 行目の欄 K 行目は、同じ欄が 2 つあります。画面を開き直してください。` |
| スライダーで 下限 ≥ 上限 | `settings.max` | `上限は下限より大きくしてください。` |
| スライダーで（上限 − 下限）が刻みで割り切れない | `settings.step` | `上限と下限の差（D）が刻み（S）で割り切れません。` |
| 数値入力で 下限 > 上限 | `settings.max` | `上限は下限以上にしてください。` |

- [ ] **Step 1: 失敗するテストを書く**（主なもの。どれも `assertSame` で形まで見る）
  - `test_definition_writes_the_saved_shape_for_each_type`（データ提供 7 種類。入力と期待は設計 5 章の表の例。数値入力は入れたキーだけ・テキストは `placeholder` が空なら `settings` が null）
  - `test_definition_drops_blank_rows_and_keeps_existing_keys`（空の行・空の欄は落ちる・`key` `city` の欄は見出しを変えても `city`）
  - `test_new_sub_fields_get_unused_keys`（新しい欄 2 つのキーが `/^f[a-z0-9]{8}$/` に合い、互いにも、ほかの選択肢の欄のキーとも重ならない）
  - `test_definition_ignores_fields_of_other_types`（単一選択の入力に `settings` や `sub_fields` があっても保存しない・スライダーの入力に `options` があっても `options` は null）
  - `test_problems_name_the_row`（上の表の 8 行をデータ提供で。文を全文で）
  - `test_problems_accept_a_valid_form`（各種類の正しい入力で `[]`）
  - `test_state_of_a_new_question` ／ `test_state_of_a_saved_question`（本番と同じ形の問 1・問 2 を含む）／ `test_state_comes_back_from_old_input`（`$old` の値・空の行も同じ並び・`is_active` が無ければ false）／ `test_state_survives_old_input_of_the_wrong_shape`（`options` が文字・行が文字・`sub_fields` が文字でも例外にならず空として描く）
  - `test_answer_counts_follow_each_saved_shape`（4 種類の回答を入れて `options` の件数・`total`・選択肢に無い値も数える）
- [ ] **Step 2: 流して赤（クラスが無い）を確かめる**
- [ ] **Step 3: 実装する**（`SurveyAnswers` と同じく `final class`・`public static`。`state()` の行は `answerCounts()` の `options[original]` を `answerCount` に入れる）
- [ ] **Step 4: 緑を確かめる**
- [ ] **Step 5: コミット**（`feat: アンケートの設問の入力を保存の形に組み立てる部品を足す`）

---

## Task 4: 追加の画面（`create`・`store`）と、一覧の追加の小窓を外す

**Files:**
- Modify: `routes/web.php`（1690〜1707 行目のグループ。`GET /survey-questions/create` → `create`・名前 `admin.survey-questions.create`。見出しの「5ルート」を直す）
- Modify: `app/Http/Controllers/Admin/SurveyQuestionController.php`（`create()`・`store()`・private `validateQuestion()`）
- Create: `resources/views/admin/master/survey-questions/create.blade.php`、`_form.blade.php`（フォームの中身と `function surveyQuestionForm()`）
- Modify: `resources/views/admin/master/survey-questions/index.blade.php`（小窓と `addQuestion()` を外し、タブごとに「＋ 設問を追加」のリンク `route('admin.survey-questions.create', ['department' => …])`）
- Create: `tests/Feature/Admin/Master/SurveyQuestionScreenTestCase.php`（`MasterScreenTestCase` を継承・`CreatesRealEstateSchema`・`CreatesSurveyQuestionSchema`）、`tests/Feature/Admin/Master/SurveyQuestionCreateTest.php`
- Modify: `tests/Feature/Admin/Master/SurveyQuestionMasterTest.php`（`test_a_question_is_added_from_the_screen`・`test_a_rejected_question_adds_nothing_and_says_so` を消す。`SurveyQuestionCreateTest` が受け継ぐ）、`tests/Feature/JsEchoClassificationTest.php`（78〜84 行目の survey の 2 つの分類は、式が消えた時点で消す）

**Interfaces:**
- Consumes: `SurveyQuestionForm::state()`・`definition()`・`problems()`・`isChoice()`（Task 3）、`browserForm()` の入れ子（Task 1）
- Produces:
  - コントローラ `create(Request $request)`（`?department=` は今の `index()` と同じく housing／realestate 以外は housing）・`store(Request $request)`（成功は `redirect(route('admin.survey-questions.index', ['department' => …]))->with('success', '設問を追加しました。')`）
  - `private function validateQuestion(array $data, ?SurveyQuestion $question): array` — `Validator::make($data, [規則のリテラル], [文], [和名のリテラル])->after(...)->validate()` を呼んで検査済みの値を返す。`after()` で `SurveyQuestionForm::problems()` の結果を `errors()->add()` する（Task 5 で種類の固定の検査を足す）。規則（キーとそのまま）:
    `department` `required|in:housing,realestate`・`label` `required|string|max:255|not_regex:/[\r\n]/`・`question_type` `required`＋`Rule::enum(SurveyQuestionType::class)`・`is_active` `nullable|boolean`・`options` `nullable|array|max:50`・`options.*` `array`・`options.*.value` `nullable|string|max:100`・`options.*.original` `nullable|string|max:100`・`options.*.has_text` `nullable|boolean`・`options.*.text_label` `nullable|string|max:100`・`options.*.sub_fields` `nullable|array|max:10`・`options.*.sub_fields.*` `array`・`options.*.sub_fields.*.key` `nullable|string|max:30|regex:/^[a-z][a-z0-9_]*$/`・`options.*.sub_fields.*.label` `nullable|string|max:100`・`options.*.sub_fields.*.type` `nullable|in:text,number`・`settings` `nullable|array`・`settings.min` と `settings.max` `nullable|integer|between:-999999999,999999999|required_if:question_type,slider`・`settings.step` `nullable|integer|min:1|max:999999999|required_if:question_type,slider`・`settings.unit` `nullable|string|max:10`・`settings.placeholder` `nullable|string|max:100`・`settings.sub_heading` `nullable|string|max:50`（数は `SurveyQuestionForm` の定数で書く）。`required_if` の文は第 3 引数で `スライダーの下限を入力してください。` など（`slider` の英字を出さない）
  - フォームの欄の名前: `department`（hidden・追加だけ）・`label`・`question_type`・`is_active`（チェック `value="1"`）・`options[i][value|original|has_text|text_label]`・`options[i][sub_fields][j][key|label|type]`・`settings[min|max|step|unit|placeholder|sub_heading]`
  - `surveyQuestionForm()` が返すメソッド: `isChoice()`・`addOption()`・`removeOption(i)`・`moveOption(i, d)`（d は −1／1。端では何もしない）・`addSubField(i)`・`removeSubField(i, j)`

- [ ] **Step 1: 失敗するテストを書く**（土台に `questionForm(string $html, string $steps = ''): array`（`browserForm($html, 'action="' . $action . '"', 'surveyQuestionForm', $steps)`）・`send(array $form, string $from): TestResponse`（`SubmitsScreenForms::submit()` と同じく `parse_str(http_build_query(...))` して POST）・`answer(SurveyQuestion $q, string $answerValue): void`（`buyer_surveys` と `buyer_survey_answers` に 1 行ずつ）・`errorItems(string $html): array`（`<p class="text-sm text-red-800">` の中身の並び））
  - `test_the_list_links_to_the_add_screen_of_each_department`（一覧の住宅事業・不動産事業のタブに、それぞれの部署の `create` へのリンク・小窓（`showAddModal`）が無い）
  - `test_each_type_is_added_from_the_screen`（データ提供 7 種類。追加の画面を開き、steps で `data.label = …; data.questionType = …;` と行・設定を入れて送る → 一覧に着き帯に「設問を追加しました。」・保存された `options`・`settings` が設計 5 章の形・`sort_order` は部署の最大＋1・`is_active` は true）
  - `test_only_the_fields_of_the_chosen_type_are_sent`（スライダーを選ぶと送る欄に `options` が無く `settings` は `min max step unit` だけ・数値入力では `settings` が `min max unit` だけ・単一選択では `has_text` `text_label` `sub_fields` `settings` が無い）
  - `test_rows_are_added_moved_and_removed_on_the_screen`（`addOption` で 3 行 → `moveOption(2, -1)` → `removeOption(0)` の並びが送る `options` に出る・条件分岐の `addSubField`／`removeSubField` も）
  - `test_a_rejected_form_comes_back_with_every_reason_and_the_rows`（スライダーで下限 6000・上限 2000・刻み 0 ＋ 設問文が空 → `errorItems()` に「設問文は必須です。」（全体の文の全文）・「刻みは1以上…」（`trans('validation.min.numeric', ['attribute' => '刻み', 'min' => 1])`）が一度に出る。条件分岐で 2 行目の名前が空・付随の欄だけある → 「選択肢 2 行目の名前を入力してください。」と、戻った画面をもう一度 `questionForm()` で組むと行と欄が同じ並びで戻る）
  - `test_slider_settings_are_required_in_japanese`（スライダーで設定を空 → 「スライダーの下限を入力してください。」ほか 3 件・英字の `slider` が出ない）
  - `test_a_newline_in_the_label_is_refused`（手で組んだ `label` に改行 → 理由が出て保存されない）
  - `test_tricky_characters_are_kept_on_the_add_screen`（`SendsValuesBack::tricky()` と同じ文字の設問文・選択肢で入力エラーにして戻ると、画面の JS が組めて元の文字のまま）
- [ ] **Step 2: 流して赤を確かめる**（ルートが無い・小窓がある）
- [ ] **Step 3: 実装する**（コントローラ・ルート・ビュー。画面の上の箱は `dad-specialties/create.blade.php` の形・パンくずは「システム管理 › アンケート設問管理 › 追加」・保存の帯は `<x-form-actions submit-label="登録する" :cancel-url="route('admin.survey-questions.index', ['department' => $department])" />`。種類ごとの欄は設計 4.3・見本 ③。スライダーの 4 欄の 4 列のグリッドには `grid-stack-sm`・`flex: 1` の入力欄には `min-width: 0`（`MobileLayoutTest`））
- [ ] **Step 4: 足したテストと `tests/Feature/Admin/Master`・`JsEchoClassificationTest`・`JsStringEchoScanTest`・`JapaneseValidationMessagesTest`・`OldInputRuleCoverageTest`・`ValidationErrorFeedbackTest`・`MobileLayoutTest`・`AlpineXShowDisplayConflictTest`・`ApprovalOnlyLockoutTest`・`Admin/Master/ArrayInputTest` が緑を確かめる**（`ArrayInputTest` は `label` の和名「設問文」に頼るので変えない）
- [ ] **Step 5: コミット**（`feat: アンケートの設問を別の画面で追加できるようにする`）

---

## Task 5: 編集の画面（`edit`・`update`）

**Files:**
- Modify: `routes/web.php`（`GET /survey-questions/{question}/edit` → `edit`・名前 `admin.survey-questions.edit`）
- Modify: `app/Http/Controllers/Admin/SurveyQuestionController.php`（`edit()`・`update()`・`validateQuestion()` に種類の固定）
- Create: `resources/views/admin/master/survey-questions/edit.blade.php`
- Modify: `_form.blade.php`（回答の件数の注意・押せない種類の欄）、`_question_item.blade.php`（「編集」を `route('admin.survey-questions.edit', $q)` へ）
- Create: `tests/Feature/Admin/Master/SurveyQuestionEditTest.php`
- Modify: `SurveyQuestionMasterTest::test_the_update_and_reorder_routes_work_though_no_screen_calls_them`（`update` の部分を消し、`reorder` の部分だけ残す。Task 6 で消す）

**Interfaces:**
- Consumes: Task 3・Task 4 の `validateQuestion()`
- Produces: `edit(Request $request, SurveyQuestion $question)`・`update(Request $request, SurveyQuestion $question)`（成功は一覧の設問の部署へ `->with('success', '設問を更新しました。')`）。`update()` は送られた `department` を使わず設問の部署で検査する。回答があり `question_type` が送られなければ今の種類で検査し、送られて今と違えば `question_type` に `回答が N件あるため、種類は変えられません。` を足す

- [ ] **Step 1: 失敗するテストを書く**
  - `test_the_edit_button_opens_the_edit_screen`（一覧の「編集」の href が `edit` の URL）
  - `test_saving_a_production_shaped_question_unchanged_keeps_its_definition`（Review Focus 1。Task 0 で読んだ形の問 1・問 2 とスライダーを作り、編集画面をそのまま送る → `options`・`settings` が前と同じ中身（キーの順は問わない・`ComparesJsonColumns::assertSameIgnoringKeyOrder()`）。`type` の無い欄は `type` が `text` で書かれることだけ違ってよい）
  - `test_the_label_options_and_settings_are_changed`（文・選択肢の名前・並び・スライダーの上限を変えて保存）
  - `test_an_inactive_question_is_turned_back_on`（無効の設問の編集画面で「有効」にして送る → `is_active` が true）
  - `test_the_type_of_an_answered_question_cannot_be_changed`（回答 1 件の設問: 種類の選択欄が `disabled` で `<span title>` に包まれ、「回答が 1件あるため、種類は変えられません。」が画面に出る。そのまま送ると種類は変わらず保存できる）
  - `test_a_locked_type_sent_by_hand_is_refused`（Review Focus 5。同じ設問に手で `question_type=text` を足して送る → 理由が出て種類も中身も変わらない）
  - `test_the_type_of_a_question_without_answers_can_be_changed`
  - `test_answered_options_show_how_many_answers_chose_them`（回答で選ばれた選択肢の行に「この選択肢を選んだ回答が 2件あります。名前を変えたり外したりしても、その回答は元の名前「建売住宅の購入」のまま残ります。」・選ばれていない行には出ない）
  - `test_removing_or_renaming_an_answered_option_keeps_the_answers`（Review Focus 3。名前を変えて 1 行を外して保存 → `buyer_survey_answers` の行は 1 文字も変わらない・アンケートの編集画面（`realestate.customers.surveys.edit` か `housing.…`）に「（選択肢に無い値）」で選ばれたまま出る）
  - `test_sub_field_keys_survive_renaming_and_new_ones_do_not_collide`（`city` の見出しを変えても `city`・足した欄は新しいキー）
  - `test_tricky_characters_round_trip_through_the_edit_screen`（Review Focus 2。設問文と選択肢に `tricky()` の文字・カンマ → 編集画面の JS が組めて、そのまま送ると同じ文字で保存される）
  - `test_tampered_rows_do_not_crash`（手で組んだ `options=abc`・`options[0]=abc`・`options[0][sub_fields][0][key]=Bad-Key` → 500 にならず理由が出る）
  - `test_the_department_is_not_changed_by_hand`（住宅事業の設問の編集に手で `department=realestate` を足して送る → 部署は住宅事業のまま）
- [ ] **Step 2: 流して赤を確かめる**
- [ ] **Step 3: 実装する**。押せない種類の欄は `<span title="回答が N件あるため、種類は変えられません。">` で包み、下に同じ文を `id` つきで出して `aria-describedby` で結ぶ（Top trap #12）
- [ ] **Step 4: 足したテストと Task 4 Step 4 の走査・`SurveyScreensTest` が緑を確かめる**
- [ ] **Step 5: コミット**（`feat: アンケートの設問を画面で編集できるようにする`）

---

## Task 6: 一覧（↑↓・削除のフォーム・番号・回答の件数・案内文）と、使わなくなった道を外す

**Files:**
- Modify: `routes/web.php`（`POST /survey-questions/{question}/move` → `move`・名前 `admin.survey-questions.move` を足す。`POST /survey-questions/reorder` を外す）
- Modify: `SurveyQuestionController.php`（`index()` に `withCount('answers')`・`move()`・`destroy()` をふつうのフォームの応答だけに・`store()`／`update()`／`destroy()` の `$request->ajax()` の JSON の分岐と `reorder()` を外す）
- Modify: `index.blade.php`、`_question_item.blade.php`
- Rewrite: `tests/Feature/Admin/Master/SurveyQuestionMasterTest.php`（一覧・↑↓・削除。今の XHR の前提のテストは置き換える）
- Modify: `tests/Feature/JsEchoClassificationTest.php`（survey の分類が残っていれば消す）

**Interfaces:**
- Produces: `move(Request $request, SurveyQuestion $question)` — `direction` を `required|in:up,down`（和名 `動かす向き`）で検査し、トランザクションの中で同じ部署の設問を `lockForUpdate()` で `sort_order`・`id` の順に読み、1 から振り直して隣と入れ替える。端なら `->with('error', '先頭の設問は上へ動かせません。')`／`'末尾の設問は下へ動かせません。'`。戻り先は `route('admin.survey-questions.index', ['department' => …]) . '#question-' . $id`（Bug #4）。帯は出さない
- 削除の文: 回答があれば `回答があるため、設問を無効にしました（編集画面で有効に戻せます）。`・無ければ `設問を削除しました。`

- [ ] **Step 1: 失敗するテストを書く**
  - `test_the_list_numbers_only_active_questions_and_shows_answer_counts`（有効・無効・有効の並びで「1. 」「（番号なし）」「2. 」・回答のある行に「回答 2件」・☰ と「ドラッグ＆ドロップ」の文が無い・新しい案内文の全文）
  - `test_the_arrows_move_a_question_and_keep_the_tab`（描いた ↓ のフォームを送ると並びが入れ替わり、`#question-` の付いた一覧に着く・不動産事業のタブで送ると不動産事業のタブに着く）
  - `test_the_edge_arrows_are_disabled_with_a_reason`（先頭の ↑・末尾の ↓ の `<button>` が `disabled` で、包む `<span title="先頭の設問です">`／`末尾の設問です`）
  - `test_moving_passes_over_inactive_questions_and_numbers_only_active_ones`（Review Focus 4）
  - `test_moving_past_the_edge_is_refused`（Review Focus 4。手で組んだ先頭の `up` → 帯に理由・並びは変わらない）
  - `test_moving_never_touches_the_other_department`（不動産事業の設問の `sort_order` が変わらない）
  - `test_a_question_without_answers_is_deleted_with_the_delete_form` ／ `test_a_question_with_answers_is_deactivated_instead`（描いた削除のフォームを送る・帯の全文）
  - `test_the_delete_confirmation_names_the_question`（`onsubmit` を `runInlineHandler()` で動かし、確認の文に設問文がそのまま出る・「いいえ」で `false`。`tricky()` の文字の設問文でも組める）
  - `test_the_reorder_route_is_gone`（`route('admin.survey-questions.reorder')` が無い・`POST /admin/survey-questions/reorder` が 404 か 405）
  - `test_tampered_move_and_form_posts_do_not_crash`（Review Focus 5。`direction=sideways`・`direction[]=up` → 理由が一覧の箱に出る）
  - 残す: `test_the_list_shows_each_departments_questions_and_opens_the_requested_tab`・`test_a_rewritten_department_in_the_url_opens_the_housing_tab`（新しい一覧の形に合わせて書き直す）
- [ ] **Step 2: 流して赤を確かめる**
- [ ] **Step 3: 実装する**（行に `id="question-{{ $q->id }}"`・無効の行は薄い地と灰色の文字（見本 ①）。タブは `x-data="surveyQuestionManager()"`・`activeTab: {{ Js::from($department) }}`）
- [ ] **Step 4: 足したテストと Task 4 Step 4 の走査が緑を確かめる**
- [ ] **Step 5: コミット**（2 つに分ける: `feat: アンケートの設問を一覧の ↑↓ で並び替える` ／ `refactor: アンケートの設問の画面から使わなくなった XHR の道を外す`）

---

## Task 7: 顧客の CSV 取込で、設問の列の文を今の設問と照らし合わせる（D8）

**Files:**
- Modify: `app/Http/Controllers/Admin/CustomerImportController.php`（87〜101 行目の `Q{n}:` の判定・275〜306 行目のテンプレート）
- Modify: `resources/views/admin/customers/import.blade.php`（テンプレートの案内に 1 行）
- Test: `tests/Feature/Admin/CustomerImportTest.php`

**Interfaces:**
- 見出しの `Q{n}:` の後ろ（`Str::trim`）が、有効な設問を並び順に並べた n 番目の `label`（`Str::trim`）と違う列・n 番目が無い列が 1 つでもあれば、プレビューでも確定でも、書き込む前に `redirect()->route('admin.customers.import')->withErrors(['csv_file' => [文…]])`。文は列ごとに `Q{n} の列の設問「{見出し}」が、今の Q{n}「{今の文}」と違います。`／`Q{n} の列に当たる設問がありません（今の設問は {件数} 問です）。`、最後に `設問の並びや文が変わっています。テンプレートをダウンロードし直してください。`
- テンプレートの見出しは、カンマ・`"` を含むセルを CSV の決まりで引用符で囲む（`fputcsv` を `php://temp` に書く）

- [ ] **Step 1: 失敗するテストを書く**
  - `test_a_template_downloaded_before_reordering_is_refused`（テンプレートを取ってから設問を並べ替え、そのテンプレートの見出しで CSV を作ってプレビュー → 取込の画面の箱の `<li>` に 2 つの列の文と最後の文が全文で出る・プレビューは描かれない）
  - `test_a_question_column_without_a_question_is_refused`（`Q9:` の列）
  - `test_the_label_check_runs_again_on_confirmation`（プレビューのあとで設問の文を変え、確認画面のフォームをそのまま送る → 断られ、何も入らない）
  - `test_a_label_with_a_comma_survives_the_template`（Review Focus 2。設問文 `ご予算、ローン` のテンプレートの見出しが `"Q1:ご予算、ローン"` ではなく 1 つのセルとして読め、そのテンプレートで取り込める）
  - 既存の `test_every_template_column_except_questions_is_a_known_column`・`test_two_columns_for_the_same_question_store_one_answer`・`ImportPreviewRenderTest` が緑のまま
- [ ] **Step 2: 流して赤を確かめる**
- [ ] **Step 3: 実装する**
- [ ] **Step 4: `tests/Feature/Admin` と `ImportController*ScanTest` が緑を確かめる**
- [ ] **Step 5: コミット**（2 つ: `fix: 顧客のCSV取込で設問の列の文を今の設問と照らし合わせる` ／ `fix: 顧客のCSVテンプレートの見出しを引用符で囲む`）

---

## Task 8: 全件テストと変異テスト

- [ ] **Step 1: 全件を流す**（run_in_background）。緑・本数・メモリのピークを記録する。コンパイル済みビューの lint（`view:cache` のあと `storage/framework/views/*.php` を `php -l`。INVALID 0）
- [ ] **Step 2: 変異の表を `specs.survey-question-admin.py` に書き、流す前に期待（落ちるテストと理由）を書く**。最低限の行:

| # | 変異 | 期待（落ちるテスト） |
|---|---|---|
| C0 | カナリア: 追加の成功の文を変える | `test_each_type_is_added_from_the_screen` |
| N1 | 道具の入れ子の行の再帰を外す（内側の `loops` を回さない） | `BrowserFormNestedRowsTest` 3 本・条件分岐の画面のテスト |
| S1 | `sliderState()` を元の式に戻す | `test_the_unanswered_slider_starts_on_a_step` |
| S2 | 見出しを固定の「希望エリア」に戻す | `test_the_sub_field_heading_comes_from_the_question` |
| S3 | 数の欄に `min`・`max` を戻す | `test_number_fields_leave_the_range_to_the_server` |
| F1〜F8 | `problems()` の 8 行をそれぞれ外す | `test_problems_name_the_row` のその行と、画面のテストの該当 1 本 |
| F9 | 新しい欄のキーをいつも `f00000000` にする | `test_new_sub_fields_get_unused_keys`・`test_sub_field_keys_survive…` |
| F10 | 今ある `key` を作り直す | `test_definition_drops_blank_rows_and_keeps_existing_keys`・`test_sub_field_keys_survive…`・`test_saving_a_production_shaped…` |
| F11 | `definition()` で他の種類の欄も保存する | `test_definition_ignores_fields_of_other_types` |
| V1 | 種類の欄の `:disabled` を外す（スライダーと数値の `settings[min]` が 2 つ送られる） | `test_only_the_fields_of_the_chosen_type_are_sent` |
| V2 | 回答がある設問の種類の固定を外す | `test_the_type_of_an_answered_question_cannot_be_changed`・`test_a_locked_type_sent_by_hand_is_refused` |
| V3 | `update()` で送られた `department` を使う | `test_the_department_is_not_changed_by_hand` |
| V4 | 和名の `:position` を外す | 文を全文で見る画面のテスト |
| M1 | `move()` で部署を絞らない | `test_moving_never_touches_the_other_department` |
| M2 | 端の断りを外す | `test_moving_past_the_edge_is_refused` |
| M3 | 端の `disabled` を外す | `test_the_edge_arrows_are_disabled_with_a_reason` |
| M4 | 番号を `sort_order` に戻す | `test_the_list_numbers_only_active_questions…` |
| D1 | 削除の確認の文から設問文を外す／`Js::from` をやめる | `test_the_delete_confirmation_names_the_question` |
| I1 | CSV の文の照らし合わせを外す | Task 7 の 3 本 |
| I2 | 確定のときだけ照らし合わせを飛ばす | `test_the_label_check_runs_again_on_confirmation` |
| I3 | テンプレートの引用符をやめる | `test_a_label_with_a_comma_survives_the_template` |

- [ ] **Step 3: 流す**（Bug #44 の作法: 先にコミット・前後で `git status --porcelain` が空・着弾を `git diff --stat` で確かめる・落ちたテストの組と理由の 1 行目まで突き合わせる）。期待と違えば、テストの穴ならテストを足して当て直す
- [ ] **Step 4: 結果を計画の「実測記録 Task 8」に書いてコミットする**

## Task 9: 実ブラウザ（Task 0 の使い捨ての環境・Playwright・画面が見えている状態）

- [ ] **Step 1: 確かめる**: ① 一覧の ↑↓（押して並びが変わり、同じ行の位置へ戻る）と端の押せないボタンの理由（ホバー） ② 追加の画面で 7 種類を順に選び、欄が切り替わる・選んだ種類の欄だけ送られる（ネットワークの要求で見る） ③ 条件分岐の行と欄を足す・並べる・外す ④ 入力エラーで戻ったとき行と欄が残る ⑤ 回答がある設問の編集画面（種類の欄が押せない・理由・件数の注意） ⑥ 本番と同じ問 2 の付随の欄の見出しを「希望エリア」にして保存し、アンケートの登録画面で「▼ 建売住宅の購入 — 希望エリア」 ⑦ 375px と 1440px で `main` の横スクロール 0・削除の確認の小窓 ⑧ コンソールのエラー 0
- [ ] **Step 2: 結果を計画の「実測記録 Task 9」に書いてコミットする**

## Task 10: 独立の点検

- [ ] **Step 1: 新しい担当（モデルは fable）に、枝の差分全体（`13.x..survey-question-admin`）と設計書を渡して点検を頼む**（設計との食い違い・テストが緑でも守っていない所・Top traps）
- [ ] **Step 2: 指摘を実測で確かめてから直す。直したら範囲を絞って点検し直す**
- [ ] **Step 3: 結果を計画の「実測記録 Task 10」に書いてコミットする**

## Task 11: 記録

- [ ] **Step 1: `docs/RULES.md` に Bug #122（この画面の行。症状・原因・直し方・検証）を足す**（足す直前に最新の `13.x` の RULES で番号の空きを確かめる）。`CLAUDE.md` の「全 121 件」を 122 件に
- [ ] **Step 2: `docs/BACKLOG.md` に節を足す**（本番反映は未。反映の手順に「`./deploy.sh` のすぐあと、本番の今の条件分岐の設問（問 2）の付随の欄の見出しに『希望エリア』を入れる（利用者が新しい編集画面で入れるか、利用者の承認を取ってこちらが入れる。設計 8 章）。入れるまでは見出しが「▼ 選択肢名」だけになる」と「新しい PHP クラスがあるので main repo の cwd で `composer dump-autoload --no-dev --optimize`」を書く）。あわせて「`old()` の走査を画面のフォームの送り先の規則と突き合わせる」の節の最後の行「⚠ `origin/13.x` への push はしていない。」を「→ 2026-10-30 に push 済み（`27330d0e..6ba8ed07`）。」に直す
- [ ] **Step 3: コミット**（`docs: アンケート設問の管理の編集と並び替えを記録する`）

---

## 実行の方法

サブエージェントで 1 タスクずつ（subagent-driven）を推薦する。Task 3 の部品・Task 4 のフォームの欄の名前・Task 1 の道具を後ろのタスクが使い、1 タスクの誤りが後ろへ広がりやすいので、タスクごとに新しい担当が点検するほうが安全なため。13.x への早送り・push・本番への反映は、この計画には含めない（利用者の指示を待つ）。

## 実測記録

（Task 0・8・9・10 の結果をここに書く）

### 実測記録 Task 0（M1〜M4）

2026-11-02・PHP 8.3.36・枝 `survey-question-admin`（`a232a983` の上）。直す前のコード（アプリ・テストは 1 行も変えていない）で測った。Step 2（本番の設問の定義の読み取り）はこの記録に含めない（別に記録する）。
M1〜M3 は使い捨てのテスト `<scratchpad>/task0/SurveyQuestionProbeTest.php`（`namespace Tests\Probe`・`MasterScreenTestCase` を継承・コミットしない）で、M4 は実ブラウザ（Playwright・画面が見えている状態・1440px）で測った。

| # | 見込み | 実測 | 一致 |
|---|---|---|---|
| M1 | 追加の XHR のあと一覧を開き直すと、「設問を追加しました。」は帯に出ない | 出ない（一覧の帯 0 件） | 一致 |
| M2 | `PUT` で `options` に `'["A",'` を送ると、今の選択肢が null になる | `options` の DB の生値が `["チラシ","Web"]` の JSON から `NULL` に（応答は 302） | 一致 |
| M3 | 回答がある設問に `PUT` で `question_type` を別の種類にして送ると、種類が変わる | `single_select` → `slider` に変わった（回答は 1 件のまま・`options` は残る・応答は 302） | 一致 |
| M4 | 追加の小窓の外枠が `display: block` になり、白い箱が中央にそろわない（Bug #32） | 外枠の `display` は `block`・白い箱は左 0px〜右 500px（左の余白 0px・右の余白 940px・画面の幅 1440px） | 一致 |

#### M1 の中身

- 画面の JS（`surveyQuestionManager`）に `data.showAddModal = true; data.newLabel = '重視する点'; data.newType = 'multi_select'; data.newOptions = '["駅近","学区"]'; data.addQuestion();` を実行させ、JS が組んだ要求を `sendCapturedLikeBrowser()`（POST の 302 は GET に変えて追う）で送った。設問そのものは追加される。
- XHR の最終応答は 200。その本文は、転送先の一覧の画面（HTML）で、帯（`<span class="text-sm text-emerald-800">`）に「設問を追加しました。」が**出ている**。つまり転送先の GET がフラッシュを使い切っている。
- JS は status 200 を見て `window.location.href = 一覧?department=realestate` を実行する（`navigations` に 1 件・`alerts` は 0 件）。同じセッションでその一覧を開き直すと、帯は **0 件**で、「設問を追加しました。」の文字もページのどこにも無い。
- 対照: 同じ要求を転送を追わずに送り（POST は 302・`Location` は `…/admin/survey-questions?department=realestate`）、そのまま一覧を開くと、帯に「設問を追加しました。」が**出る**。フラッシュの仕組みそのものは動いていて、原因は XHR が `X-Requested-With` を付けないため `store()` が JSON でなく転送（`$request->ajax()` が偽）を返し、XHR がその転送先を読んでフラッシュを消費することだ。

#### M2・M3 の中身

- `update()` は `$request->input('options') ? json_decode(…, true) : $question->options` と書いているので、空でない文字列なら `json_decode()` の結果をそのまま入れる。壊れた JSON は `json_decode()` が `null` を返すので、今の選択肢が消える（エラーにならない）。
- `update()` は `question_type` が許す種類のどれかであれば、回答の有無を見ずに更新する。回答がある設問でも種類が変わり、保存済みの回答（`answer_value`・`question_snapshot`）は変わらないまま残る。

#### M4 の中身（`getComputedStyle` と `getBoundingClientRect()`・「設問を追加」を押したあと）

- 外枠（`x-show` の要素）: 描かれた `style` 属性は `position: fixed; inset: 0px; background: rgba(0, 0, 0, 0.4); z-index: 9999; align-items: center; justify-content: center;`。**`display` が消えて**いて、計算された `display` は `block`（Alpine の `x-show` が、静的に書いた `display: flex` を表示のたびに消す）。`align-items` と `justify-content` は `center` のままだが、`display: flex` が無いので効かない。外枠の四角は 0,0〜1440×900（画面全体）。
- 白い箱: 500px × 412.5px・左上は (0, 0)。画面の左上に貼り付く（スクリーンショット: `<scratchpad>/task0/m4-add-modal-before.png`）。
- コンソールのエラー 0 件。一覧の 6 問（問 1 は選択肢 11 個・問 2 は条件分岐付き選択で 6 個・問 3〜5 は単一選択・問 6 はスライダー「2,000〜6,000万円（100万円刻み）」）は期待どおりに描かれた。

#### 使い捨ての環境（Task 9 でも使う。コミットしない）

- DB は `<scratchpad>/task0/demo.sqlite`（`.claude/route-coverage-tools/browser/demo.sqlite` の写し）と `gym.sqlite`。`survey-env.php` が、作業場所のコードに合わせて migrate を流し（決裁の 3 本の migration が流れた）、`survey_questions`（無ければ `CreatesSurveyQuestionSchema` と同じ列で作る）の全行を消して、住宅事業に本番と同じ構成の 6 問（問 1 選択肢＋付随テキスト 11 択で `has_text` は「ご紹介」「その他」だけ・問 2 条件分岐付き選択 6 択で付随の欄の `key` が選択肢をまたいで重なる・問 3〜5 単一選択・問 6 スライダー 2000〜6000・100 刻み・万円）を入れる。何度流しても同じ状態になる。設問文は一般的な言い方に置き換えてある。
- 起動（作業場所の cwd で。`routes/web.php` の末尾に `login-route.php.txt` を一時的に足しておく。確認が済んだら `git checkout -- routes/web.php`）:
  `env APP_KEY="$(cat <scratchpad>/task0/app-key.txt)" APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE=<scratchpad>/task0/demo.sqlite ZEAL_DB_URL=sqlite:///<scratchpad>/task0/gym.sqlite APP_URL=http://127.0.0.1:8032 CACHE_STORE=file SESSION_DRIVER=file APP_LOCALE=ja APP_FALLBACK_LOCALE=ja /opt/homebrew/opt/php@8.3/bin/php artisan serve --host=127.0.0.1 --port=8032`（Bash の `run_in_background`）。ログインは `http://127.0.0.1:8032/_dev/login-as/1?to=/admin/survey-questions`。
- ⚠ Playwright の「保存先」の起点は main repo（`/Users/masanori/site/manage`）で、相対パスで撮ったスクリーンショットは main repo の直下（`/*.png` は gitignore 済み）に置かれる。撮ったらすぐ scratchpad へ移した。
- 後片付け済み: サーバを止め、`routes/web.php` を戻した（`git status --porcelain` は空）。`public/build` のリンクは以前からあったので残した。

### 実測記録 Task 0（本番の設問の形・Step 2）

2026-11-02 に利用者が本番で読み取りの手順を流し、結果を貼ってくれた（読むだけ。設問の文と選択肢の名前は出していない）。設計 5 章に無いキーは無かったので止めずに進めた。

| 設問 | 種類 | 選択肢の形 | settings | 回答 |
|---|---|---|---|---|
| 問 1 | 選択肢＋付随テキスト | 11 個。全部オブジェクト `{has_text, value}`（文字を入れない 9 個にも `has_text` がある）・2 個だけ `{has_text, text_label, value}` | null | 1 |
| 問 2 | 条件分岐付き選択 | 6 個。全部 `{sub_fields, value}`（欄の無い 2 個にも `sub_fields` がある）・欄は全部 `{key, label, type}`。キーは `city` `area` `land_tsubo` `building_tsubo`（1・2 個目）／`city` `town` `land_tsubo`（3 個目）／`other_text`（6 個目）。`type` は全部ある | null | 0 |
| 問 3〜5 | 単一選択 | 文字の並び（4・5・5 個） | null | 0 |
| 問 6 | スライダー | null | `{"max": 6000, "min": 2000, "step": 100, "unit": "万円"}` | 0 |

- 不動産事業の設問は 0 問。`laravel.log` の更新はサーバーの時刻で 2026-06-18 08:41（読み取りのあとのエラーは無し）
- これに合わせて、保存の形を 2 つ決めた（設計 5 章・Task 3 の `definition()` に書き足した）: 選択肢＋付随テキストは `has_text` をどの選択肢にも書く ／ 条件分岐はどの選択肢にも `sub_fields` を書く。Review Focus 1 のテストは、この形の問 1・問 2 で作る

### 実測記録 Task 8（全件テストと変異テスト）

2026-11-02・PHP 8.3.36・枝 `survey-question-admin`。全件テストと変異の 1 回目は `4101015b`（Task 7 まで）、穴を塞いだテスト（`97e7b18f`）のあとに全件と変異の 2 回目を流した。

#### 全件テストとビュー

| | `4101015b` | `97e7b18f`（穴を塞いだテストのあと） |
|---|---|---|
| 全件（CLAUDE.md の流し方・OPcache あり） | OK (4701 tests, 47823 assertions)・2 分 54.983 秒・メモリのピーク 171.50 MB | OK (4703 tests, 47891 assertions)・2 分 54.663 秒・メモリのピーク 171.50 MB（足した 2 データのぶん +2 本） |
| コンパイル済みビュー（`view:cache` → 全部を `php -l` → `view:clear`） | 318 本 / INVALID 0 | （ビューは変えていない） |

- ビューは 13.x の 315 本から 3 本増えた（`admin/master/survey-questions/` の `create`・`edit`・`_form`）

#### 変異テスト

道具は `/Users/masanori/site/manage/.claude/route-coverage-tools/mutate/run.py`、表は同じフォルダの `specs.survey-question-admin.py`（`specs.py` へ写して流す）。結果は `results-survey-question-admin-run1.txt`（1 回目）・`results-survey-question-admin.txt`（2 回目）。
作法（docs/RULES.md Bug #44）: 流す前に期待（落ちるテストの組と理由の 1 行目）を書く ／ 変異の前後で `git status --porcelain` が空 ／ 置き換えはちょうど 1 か所（同じ行が 2 つある F10 は 1 つ目を名指し）で、`git diff --stat` が空なら止める ／ 落ちたテストの組と理由まで突き合わせる。
流すテストは変異ごとに関係するファイルに絞った（設問の管理の 3 本と部品の 1 本・アンケートの画面の 4 本・顧客の CSV 取込・走査の 2 本）。

- 1 回目（`4101015b`・52 通り）: 期待どおり 49 通り（K1・K1b は見込みどおり緑＝穴）・DIFF 3 通り（N1・J4・O1。下の表の「判定」と、表のあとの注記）
- 穴（K1・K1b）にテストを足した（`97e7b18f`）。DIFF の 3 通りと K1・K1b・K1c の期待を直し、2 回目（`97e7b18f`・52 通り）を全部流し直した: 52 通りすべて期待どおり（落ちたテストの組・理由の 1 行目・`contains` の全文まで一致。表に無いテストは 1 本も落ちなかった）

計画の表の名前は計画の段の名前なので、今のテストの名前に引き直した。計画の表に無い行（C1・V1b・V2b・V2c・V4b・R1・T1・H1・X2・X3・M2b・M3b・D1b・I1b・I3b・J1・J1b・J2〜J4・O1・K1〜K1c）は、修正の回の振る舞い（Shift_JIS の字のそろえ・断ったときの部署・同点の並び・Ruling 8 の regex）と、読んで守っていそうに見えた所を足した。
計画の見込みと違った所（どれも今のテストで引き直してから流した）: N1・F10・M4 は落ちるテストが計画より多い ／ I1 は 4 本（計画は「Task 7 の 3 本」）／ V1 は計画の「スライダーと数値で settings[min] が 2 つ送られる」では落ちない（同じ `x-model` なので 2 つの値が同じ）。テキスト入力・選択肢の形に `settings[min]` が混ざることで落ちる ／ F1・F4・F5・F7・F8 は画面のテストが無い（下の「穴と足したテスト」）。

| # | 変異 | 期待（落ちるテスト。理由の文は表のファイル） | 実測（2 回目） | 判定 |
|---|---|---|---|---|
| C0 | カナリア: 追加の成功の文を変える（7 種類の追加と、無効の設問の追加が帯の文で落ちる） | 追加 `test_each_type_is_added_from_the_screen`（7 データ）<br>追加 `test_an_inactive_question_can_be_added` | 8 本が赤 | 期待どおり |
| N1 | 道具の入れ子の行の再帰を外す（内側の loops を回さない） | 道具 `test_inputs_in_rows_inside_rows_are_sent`<br>道具 `test_inner_rows_follow_the_state_after_the_steps`<br>道具 `test_disabled_inner_inputs_are_not_sent`<br>追加 `test_each_type_is_added_from_the_screen`（conditional_select）<br>追加 `test_only_the_fields_of_the_chosen_type_are_sent`<br>追加 `test_rows_are_added_moved_and_removed_on_the_screen`<br>追加 `test_a_rejected_form_comes_back_with_every_reason_and_the_rows`<br>編集 `test_saving_a_production_shaped_question_unchanged_keeps_its_definition`<br>編集 `test_sub_field_keys_survive_renaming_and_new_ones_do_not_collide`<br>編集 `test_tricky_characters_round_trip_through_the_edit_screen`<br>編集 `test_tampered_rows_do_not_crash` | 11 本が赤 | 期待どおり（1 回目は期待の書き方の誤りで DIFF） |
| S1 | sliderState() の未回答の値を元の式（真ん中の整数）に戻す | 回答の部品 `test_the_unanswered_slider_starts_on_a_step`（真ん中が刻みの上にない（150 → 100）・下限が 0 でない（真ん中 5.5 → 下限から数えて 4）・刻みが範囲より大きい（下限のまま）） | 3 本が赤 | 期待どおり |
| S2 | 付随の欄の箱の見出しを固定の「— 希望エリア」に戻す | アンケートの画面 `test_the_sub_field_heading_comes_from_the_question` | 1 本が赤 | 期待どおり |
| S3 | 数値入力の欄に min・max を戻す | アンケートの画面 `test_number_fields_leave_the_range_to_the_server` | 1 本が赤 | 期待どおり |
| C1 | chosenValues() で条件分岐の選んだ値を読まない（設問の管理の選択肢ごとの回答の件数が 0 になる） | 回答の部品 `test_chosen_values_read_each_saved_shape`（条件分岐付き選択（付随の欄は選んだ値ではない））<br>部品 `test_answer_counts_follow_each_saved_shape`<br>編集 `test_answered_options_show_how_many_answers_chose_them` | 3 本が赤 | 期待どおり |
| F1 | problems(): 名前のある行が 0 の断りを外す | 部品 `test_problems_name_the_row`（名前のある選択肢が 0・選択肢の行が無い）<br>部品 `test_problems_survive_any_shape` | 3 本が赤 | 期待どおり |
| F2 | problems(): 名前が空でほかの欄に中身がある行の断りを外す | 部品 `test_problems_name_the_row`（名前が空で「文字も入れる」がある・名前が空で見出しがある・名前が空で付随の欄がある）<br>追加 `test_a_rejected_form_comes_back_with_every_reason_and_the_rows` | 4 本が赤 | 期待どおり |
| F3 | problems(): 同じ名前の行の断りを外す | 部品 `test_problems_name_the_row`（同じ名前の行）<br>追加 `test_tricky_characters_are_kept_on_the_add_screen` | 2 本が赤 | 期待どおり |
| F4 | problems(): 見出しが空でキーのある付随の欄の断りを外す | 部品 `test_problems_name_the_row`（見出しが空でキーのある付随の欄） | 1 本が赤 | 期待どおり |
| F5 | problems(): 1 つの選択肢の中で同じキーの欄の断りを外す | 部品 `test_problems_name_the_row`（1 つの選択肢の中で同じキーの欄） | 1 本が赤 | 期待どおり |
| F6 | problems(): スライダーの下限 ≥ 上限の断りを外す（差が 0 以下で刻みで割り切れる 2 つは何も出ない） | 部品 `test_problems_name_the_row`（スライダーで下限が上限より大きい・スライダーで下限と上限が同じ）<br>追加 `test_a_rejected_form_comes_back_with_every_reason_and_the_rows` | 3 本が赤 | 期待どおり |
| F7 | problems(): スライダーの差が刻みで割り切れない断りを外す | 部品 `test_problems_name_the_row`（スライダーで差が刻みで割り切れない） | 1 本が赤 | 期待どおり |
| F8 | problems(): 数値入力の下限 > 上限の断りを外す | 部品 `test_problems_name_the_row`（数値入力で下限が上限より大きい） | 1 本が赤 | 期待どおり |
| F9 | 新しい欄のキーをいつも f00000000 にする（重なりを避けない） | 部品 `test_new_sub_fields_get_unused_keys`<br>部品 `test_new_keys_skip_keys_already_in_use`<br>編集 `test_sub_field_keys_survive_renaming_and_new_ones_do_not_collide`<br>追加 `test_each_type_is_added_from_the_screen`（conditional_select） | 4 本が赤 | 期待どおり |
| F10 | 今ある key を捨てて作り直す（conditionalOptions の $key を空に） | 部品 `test_definition_writes_the_saved_shape_for_each_type`（条件分岐付き選択（sub_fields はどの選択肢にも・付随の欄の見出し）・条件分岐付き選択（見出しが空なら settings は null・種類の無い欄は文字））<br>部品 `test_definition_drops_blank_rows_and_keeps_existing_keys`<br>部品 `test_new_sub_fields_get_unused_keys`<br>部品 `test_new_keys_skip_keys_already_in_use`<br>部品 `test_definition_ignores_fields_of_other_types`<br>部品 `test_a_production_shaped_question_comes_back_unchanged`<br>編集 `test_saving_a_production_shaped_question_unchanged_keeps_its_definition`<br>編集 `test_sub_field_keys_survive_renaming_and_new_ones_do_not_collide`<br>編集 `test_tricky_characters_round_trip_through_the_edit_screen` | 10 本が赤 | 期待どおり |
| F11 | definition() でテキスト入力の設定にほかの種類の欄（単位）も入れる | 部品 `test_definition_ignores_fields_of_other_types` | 1 本が赤 | 期待どおり |
| V1 | 数値入力の下限の欄の :disabled を外す | 追加 `test_only_the_fields_of_the_chosen_type_are_sent` | 1 本が赤 | 期待どおり |
| V1b | スライダーの刻みの欄の :disabled を外す（数値入力の形に step が混ざる） | 追加 `test_only_the_fields_of_the_chosen_type_are_sent` | 1 本が赤 | 期待どおり |
| V2 | update() の回答がある設問の種類の固定を丸ごと外す（押せない種類の欄は送られないので、回答がある設問は種類が必須で断られ、手で送った種類は通る） | 編集 `test_the_type_of_an_answered_question_cannot_be_changed`<br>編集 `test_a_locked_type_sent_by_hand_is_refused`<br>編集 `test_saving_a_production_shaped_question_unchanged_keeps_its_definition`<br>編集 `test_removing_or_renaming_an_answered_option_keeps_the_answers` | 4 本が赤 | 期待どおり |
| V2b | update(): 種類が送られなければ今の種類・送られればそのまま使う（手で送った種類で変わる） | 編集 `test_a_locked_type_sent_by_hand_is_refused` | 1 本が赤 | 期待どおり |
| V2c | edit() で種類の欄を押せなくしない（理由を渡さない） | 編集 `test_the_type_of_an_answered_question_cannot_be_changed`<br>編集 `test_saving_a_production_shaped_question_unchanged_keeps_its_definition` | 2 本が赤 | 期待どおり |
| V3 | update() で送られた department を使う（merge の順を逆に） | 編集 `test_the_department_is_not_changed_by_hand` | 1 本が赤 | 期待どおり |
| V4 | 和名の :position を外す（選択肢の名前） | 追加 `test_one_reason_per_field` | 1 本が赤 | 期待どおり |
| V4b | 和名の :second-position を外す（付随の欄のキー） | 編集 `test_tampered_rows_do_not_crash` | 1 本が赤 | 期待どおり |
| R1 | 入力エラーで戻った画面を前の入力から組まない（formState() で old を読まない） | 追加 `test_a_rejected_form_comes_back_with_every_reason_and_the_rows`<br>追加 `test_tricky_characters_are_kept_on_the_add_screen`<br>追加 `test_switching_to_a_choice_type_with_no_rows_adds_a_blank_row`<br>編集 `test_a_locked_type_sent_by_hand_is_refused` | 4 本が赤 | 期待どおり |
| T1 | state(): 回答がある設問でも前の入力の種類を描く（種類の固定を画面の最初の状態で守らない） | 部品 `test_state_comes_back_from_old_input`<br>編集 `test_a_locked_type_sent_by_hand_is_refused` | 2 本が赤 | 期待どおり |
| H1 | 付随の欄の key の hidden を外す（今ある欄に新しいキーが付く） | 追加 `test_only_the_fields_of_the_chosen_type_are_sent`<br>追加 `test_rows_are_added_moved_and_removed_on_the_screen`<br>編集 `test_saving_a_production_shaped_question_unchanged_keeps_its_definition`<br>編集 `test_sub_field_keys_survive_renaming_and_new_ones_do_not_collide`<br>編集 `test_tricky_characters_round_trip_through_the_edit_screen` | 5 本が赤 | 期待どおり |
| X2 | destroy(): 回答がある設問も消す（無効にしない） | 一覧 `test_a_question_with_answers_is_deactivated_instead` | 1 本が赤 | 期待どおり |
| X3 | store(): 並び順をいつも 1 にする（部署の末尾に足さない） | 追加 `test_each_type_is_added_from_the_screen`（7 データ） | 7 本が赤 | 期待どおり |
| M1 | move() で部署を絞らない（全部署の並びで入れ替え・振り直す） | 一覧 `test_moving_never_touches_the_other_department`<br>一覧 `test_the_arrows_move_a_question_and_keep_the_tab` | 2 本が赤 | 期待どおり |
| M2 | move() の先頭の断りを外す（$ids[-1] で 500） | 一覧 `test_moving_past_the_edge_is_refused` | 1 本が赤 | 期待どおり |
| M2b | move() の末尾の断りを外す（$ids[count] で 500） | 一覧 `test_moving_past_the_edge_is_refused` | 1 本が赤 | 期待どおり |
| M3 | 先頭の ↑ の disabled を外す | 一覧 `test_the_edge_arrows_are_disabled_with_a_reason` | 1 本が赤 | 期待どおり |
| M3b | 末尾の ↓ の disabled を外す | 一覧 `test_the_edge_arrows_are_disabled_with_a_reason` | 1 本が赤 | 期待どおり |
| M4 | 一覧の番号を sort_order に戻す（無効の設問にも番号・飛んだ番号のまま） | 一覧 `test_the_list_numbers_only_active_questions_and_shows_answer_counts`<br>一覧 `test_moving_passes_over_inactive_questions_and_numbers_only_active_ones`<br>一覧 `test_a_question_with_answers_is_deactivated_instead` | 3 本が赤 | 期待どおり |
| D1 | 削除の確認の文から設問文を外す | 一覧 `test_the_delete_confirmation_names_the_question`（ふつうの設問文・壊れやすい文字・閉じて続ける） | 3 本が赤 | 期待どおり |
| D1b | 削除の確認の設問文を Js::from でなく引用符の中に直に書く（JS の分類の走査も落ちる） | 一覧 `test_the_delete_confirmation_names_the_question`（壊れやすい文字・閉じて続ける）<br>JS の分類の走査 `test_every_value_written_into_javascript_is_safe_or_classified` | 3 本が赤 | 期待どおり |
| I1 | CSV の設問の列の文の照らし合わせを外す（当たる設問の無い列の断りは残る） | CSV 取込 `test_a_template_downloaded_before_reordering_is_refused`<br>CSV 取込 `test_the_label_check_runs_again_on_confirmation`<br>CSV 取込 `test_a_template_saved_by_excel_as_shift_jis_is_not_refused`<br>CSV 取込 `test_a_realestate_file_refused_for_its_question_columns_keeps_the_department` | 4 本が赤 | 期待どおり |
| I1b | 当たる設問の無い列を断らずに黙って捨てる（直す前の形） | CSV 取込 `test_a_question_column_without_a_question_is_refused` | 1 本が赤 | 期待どおり |
| I2 | 確定のときだけ照らし合わせの断りを飛ばす | CSV 取込 `test_the_label_check_runs_again_on_confirmation` | 1 本が赤 | 期待どおり |
| I3 | テンプレートの見出しを引用符で囲まない（カンマでつなぐだけに戻す） | CSV 取込 `test_a_label_with_a_comma_survives_the_template` | 1 本が赤 | 期待どおり |
| I3b | 取込の見出しを既定の逃がしの文字（円記号）で読む（`\"` の続く設問の文が割れる） | CSV 取込 `test_a_label_with_a_comma_survives_the_template` | 1 本が赤 | 期待どおり |
| J1 | Shift_JIS の字のそろえを見出しの側だけにする（Mac で打った「〜」の設問の文が自分のテンプレートで断られる） | CSV 取込 `test_a_label_with_the_jis_wave_dash_matches_its_own_template` | 1 本が赤 | 期待どおり |
| J1b | Shift_JIS の字のそろえを設問の側だけにする（Excel の Shift_JIS も Mac の「〜」も断られる） | CSV 取込 `test_a_label_with_the_jis_wave_dash_matches_its_own_template`<br>CSV 取込 `test_a_template_saved_by_excel_as_shift_jis_is_not_refused` | 2 本が赤 | 期待どおり |
| J2 | Shift_JIS の字のそろえをやめる | CSV 取込 `test_a_template_saved_by_excel_as_shift_jis_is_not_refused` | 1 本が赤 | 期待どおり |
| J3 | 設問の列で断ったときの戻り先から ?department= を外す | CSV 取込 `test_a_template_downloaded_before_reordering_is_refused`<br>CSV 取込 `test_a_question_column_without_a_question_is_refused`<br>CSV 取込 `test_the_label_check_runs_again_on_confirmation`<br>CSV 取込 `test_a_template_saved_by_excel_as_shift_jis_is_not_refused`<br>CSV 取込 `test_a_realestate_file_refused_for_its_question_columns_keeps_the_department` | 5 本が赤 | 期待どおり |
| J4 | showForm() で文字ならどの部署の値でも渡す（tenant などを選ぶ） | CSV 取込 `test_the_import_page_chooses_the_department_only_from_known_values` | 1 本が赤 | 期待どおり（1 回目は期待の書き方の誤りで DIFF） |
| O1 | ordered() の同点を id で決めない | CSV 取込 `test_every_screen_numbers_questions_in_the_same_order` | 1 本が赤 | 期待どおり（1 回目は期待の書き方の誤りで DIFF） |
| K1 | 上限の regex を外す（`+6000` が integer を通り、保存の形で上限が落ちる） | 追加 `test_a_plus_signed_setting_is_refused`（上限） | 1 本が赤 | 穴 → テストを足して期待どおり（1 回目は見込みどおり緑） |
| K1b | 刻みの regex を外す | 追加 `test_a_plus_signed_setting_is_refused`（刻み） | 1 本が赤 | 穴 → テストを足して期待どおり（1 回目は見込みどおり緑） |
| K1c | 下限の regex を外す（今のテストが見ている） | 追加 `test_a_plus_signed_setting_is_refused`（下限） | 1 本が赤 | 期待どおり |

- 期待の書き方を直した 3 通り（どれも落ちたテストと仕組みは見込みどおり。1 回目の DIFF）:
  - N1: 落ちるテストが 1 本多かった（`test_tampered_rows_do_not_crash` の「欄のキーの形」）。道具が付随の欄を送らないので、手で key を書き込んだ欄は key だけの欄になり、見出しの断り（`problems()`）も並ぶ。期待の表が狭かった
  - J4: 理由の 1 行目は `assertSame()` の文（`?department=tenant`）で、`null is identical` はその下の行。全文に含まれることを `contains` で見る形にした
  - O1: `run.py` は理由を 200 字で切り、流れた SQL が長くて `ends with` が切れた。全文で見る形にした
- I3b（取込の見出しを円記号の逃がしで読む）は赤だが、理由の 1 行目は `Error: Call to a member function all() on array`（`$preview->assertStatus(200)` が、入力エラーを持つ転送で外れると失敗の文を組み立てる途中で落ちる。Bug #49 の形）。仕組みを確かめるため、作業ツリーで一時的に変異とテストの読み方を変えて（`getStatusCode()` と箱の文）流した: 「Q1 の列の設問「ご予算、ローン,"頭金\"'&<\"」が、今の Q1「ご予算、ローン,"頭金\"'&<\」と違います。」で断られていた＝見込みどおりの仕組み。戻して作業ツリーは空。テストは直していない（下の「残したこと」）

#### 穴と足したテスト

| # | 1 回目 | 塞いだテスト | 2 回目 |
|---|---|---|---|
| K1 | 上限の `regex` を外しても全部緑（`+6000` が `integer`・`between` を通り、保存の形 `SurveyQuestionForm::integer()` が読まずに上限が黙って落ちたまま保存される） | `test_a_plus_signed_setting_is_refused` を下限・上限・刻みの 3 つのデータにした（`97e7b18f`）。足す前の実測: 変異を当てると「上限」「刻み」のデータが `Failed asserting that two arrays are identical.`（入力エラーの箱が空＝保存された）で赤・当てなければ 3 つとも緑 | 赤（「上限」のデータ） |
| K1b | 刻みの `regex` を外しても全部緑（同じ形） | 同上 | 赤（「刻み」のデータ） |

- `problems()` の 8 行（F1〜F8）は、どれも部品のテスト（`test_problems_name_the_row` のそのデータ）が落とす。画面のテストが落とすのは 3 行（F2・F3・F6）だけで、F1（名前のある行が 0）・F4・F5（付随の欄の見出し・キー。手で組んだ送信だけの断り）・F7（スライダーの差が刻みで割り切れない）・F8（数値入力の下限 > 上限）は画面から送るテストが無い。`after()` が `problems()` の断りを足す道筋は F2・F3・F6 の画面のテストが通しているので、穴とはしなかった
- 等価の変異は無かった（緑のまま残った変異は K1・K1b だけで、どちらも穴だった）

#### 残したこと（直していない）

- `test_a_label_with_a_comma_survives_the_template` の `$preview->assertStatus(200)` は、断られたときに理由が読めない（I3b）。同じファイルのほかのテストは `getStatusCode()` と箱の文で見ている。直すかは Task 10 の点検で決める

### 実測記録 Task 9（実ブラウザ）

2026-11-02・PHP 8.3（`/opt/homebrew/opt/php@8.3/bin/php`）・枝 `survey-question-admin`（`9aa3e8d6`）。Task 0 の使い捨ての環境（`<scratchpad>/task0/demo.sqlite`・`gym.sqlite`・`artisan serve` の 8032 番）を Playwright（画面が見えている状態。`document.hidden` は false）で操作して測った。アプリ・テストは 1 行も変えていない。

#### 準備

- CSS: 新しいビューの Tailwind のクラス 34 個を、リンク先（main repo のビルド `app-DernpbB7.css`）で測ると `max-sm:p-3`（一覧の枠の 375px の余白）だけが無かった。README の手順で作業場所の `public/build` のリンクを外して作業場所でビルドし（`app-_7h98Lga.css`。`.max-sm\:p-3` あり）、確認のあと `rm -rf public/build` → `ln -sfn /Users/masanori/site/manage/public/build public/build` で戻した。375px の一覧の枠の余白は 12px（効いている）
- DB: `survey-env.php` を流し直して住宅事業の 6 問（id 7〜12）にし、`<scratchpad>/task9/answers.php`（コミットしない）で、問 4（ご家族の人数・id 10）を無効に・買主 1 のアンケート 2 件に回答 4 件（問 2〈id 8〉の「建売住宅の購入」2 件・問 3〈id 9〉の「半年以内」1 件・問 1〈id 7〉の「ご紹介」1 件）を入れた
- ログインは使い捨てのルート（`login-route.php.txt` を `routes/web.php` の末尾に足した）で利用者 1（経営層・有効）。終わったあと `git checkout -- routes/web.php`、`git status --porcelain` は空、サーバは止めた

#### 結果

| # | 確かめたこと | 測った内容 | 判定 |
|---|---|---|---|
| 1 | 一覧の ↑↓ | 問 3（id 9）の ↓ → 無効の問 4 と入れ替わり、`?department=housing#question-9` に着く・並び 7,8,10,9,11,12・その行の枠が緑（`:target`・`rgb(5, 150, 105)`）・帯は出ない。1440×500 で末尾近くの id 11 の ↓ → 着いた行は画面の 312〜395px（見える範囲 52〜500px の中）・`main` が 462px 下へ送られている。無効の id 10 の ↑ → 行の上端が 52px ＝ 上の見出しの帯（`header` の下端 52px）にちょうど接して見える（`main` がスクロールの箱で、見出しの帯は `main` の外なので重ならない）。番号は有効な設問だけ（1・2・3・（なし）・4・5 → 並べ替えたあとも振り直し） | OK |
| 1 | 端の押せないボタンの理由 | 先頭の ↑・末尾の ↓: `disabled`・ボタンの中心の `document.elementFromPoint()` はボタン自身、祖先を辿ると包む `<span>` の `title`「先頭の設問です」「末尾の設問です」。押せる ↑ は祖先に `title` なし。Playwright で span にホバーすると `:hover` の連なりに `SPAN[title=先頭の設問です]` → `BUTTON.sq-move` が入る（ネイティブのツールチップは OS が描くのでページのスクリーンショットには写らない） | OK |
| 1 | 無効の行の見分け | 無効の行: 地 `rgb(250, 250, 250)`・設問文 `rgb(107, 114, 128)`・番号なし・「無効」の灰色のピル。有効の行: 地 `rgb(255, 255, 255)`・設問文 `rgb(0, 0, 0)`・番号・緑の「有効」。地の差はコントラスト比 1.04:1 でほぼ見えない（Task 6 の後回しのとおり）が、設問文の灰色（地に対して 4.63:1）・番号の有無・ピルで見分けられる | OK（地の差は弱い・下の「気づいたこと」1） |
| 2 | 追加の画面で 7 種類を切り替える | 種類ごとに見える箱と送られる欄（`disabled` でない `name`）: 単一選択・複数選択 = 選択肢の箱・`options[0][original\|value]` ／ 選択肢＋付随テキスト = ＋「文字も入れる」・`options[0][has_text]` ／ 条件分岐付き選択 = ＋「付随の欄の見出し」と「この選択肢を選んだときに出す欄」・`settings[sub_heading]` ／ テキスト入力 = 見本の文字の箱だけ・`settings[placeholder]` ／ 数値入力 = `settings[min\|max\|unit]` ／ スライダー = `settings[min\|max\|step\|unit]`（どれにも `label`・`question_type`・`is_active`） | OK |
| 2 | 選んだ種類の欄だけ送られる（ネットワークの要求の本文） | 6 種類を画面から登録し、POST の本文を読んだ: スライダー `question_type=slider&is_active=1&settings[min]=20&settings[max]=60&settings[step]=5&settings[unit]=坪`（`options` なし）／ 数値 `settings[min]=10&settings[max]=200&settings[unit]=坪`（`step`・`options` なし）／ テキスト `settings[placeholder]=例: 4LDK` だけ ／ 付随テキスト `options[0][original]=&options[0][value]=チラシ&options[1][original]=&options[1][value]=その他&options[1][has_text]=1&options[1][text_label]=具体的に`（`settings`・`sub_fields` なし）／ 複数選択・単一選択 `options[i][original\|value]` だけ。条件分岐は 3 の行。どれも 302 → 一覧・帯「設問を追加しました。」・末尾に追加。保存された形は スライダー `{"min":20,"max":60,"step":5,"unit":"坪"}`・数値 `{"min":10,"max":200,"unit":"坪"}`・テキスト `{"placeholder":"例: 4LDK"}`・付随テキスト `[{"value":"チラシ","has_text":false},{"value":"その他","has_text":true,"text_label":"具体的に"}]`・複数／単一選択は文字の並び | OK |
| 3 | 条件分岐の行と欄を足す・並べる・外す | 追加の画面で選択肢を 4 行に・1 行目に欄 3 つ・2 行目に欄 1 つ（種類「数」）→ 1 行目の欄 2 行目を ×・3 行目を ↑・4 行目を × → 画面は「建売（市区町村/文字・ご予算/文字）」「リフォーム」「土地（土地の坪数/数）」。端の ↑↓ は `disabled` で包む span の `title`「先頭の選択肢です」「末尾の選択肢です」。送られた本文はこの並び（`options[0][sub_fields][0..1]`・`options[2][sub_fields][0]`・`key` は空）。保存は `[{"value":"建売","sub_fields":[{"key":"f3ck3xhcg",…},{"key":"fnwvhbpvj",…}]},{"value":"リフォーム","sub_fields":[]},{"value":"土地","sub_fields":[{"key":"fl7tfysqg","type":"number",…}]}]`・`{"sub_heading":"希望の内容"}`。編集の画面を開き直すと、同じ並び・見出し・種類と、hidden の `key` が保存のキーのまま・`original` は名前 | OK |
| 4 | 入力エラーで戻る | スライダーで設問文を空・下限 6000・上限 2000・刻み 0・単位 万円 → 追加の画面に戻り、赤い箱に「設問文は必須です。」「刻みは1以上の値にしてください。」「上限は下限より大きくしてください。」の 3 件が一度に出る・種類はスライダーのまま・4 欄とも入れた値のまま。条件分岐で 3 行（1 行目 A＋欄「市区町村」・2 行目は名前が空で欄「坪数/数」「備考」・3 行目 A）→ 「選択肢 2 行目の名前を入力してください。」「選択肢 3 行目の名前「A」は 1 行目と同じです。」・設問文・見出し・3 行と欄（種類「数」も）がそのまま戻る | OK |
| 5 | 回答がある設問の編集画面 | 問 2（id 8・回答 2 件）: 種類の選択欄は `disabled`・`aria-describedby="sq-type-locked"`。欄の中心の `elementFromPoint()` は選択欄、祖先を辿ると包む span の `title`「回答が 2件あるため、種類は変えられません。」・実ホバーの `:hover` の連なりにもその span。同じ文が欄の下に見えている。「建売住宅の購入」の行だけに「この選択肢を選んだ回答が 2件あります。名前を変えたり外したりしても、その回答は元の名前「建売住宅の購入」のまま残ります。」・ほかの 5 行には出ない。問 1（id 7・回答 1 件）も「回答が 1件あるため…」・「ご紹介」の行だけに「…回答が 1件あります…元の名前「ご紹介」…」・「ご紹介」「その他」の「文字も入れる」と見出しが保存どおり | OK |
| 6 | 問 2 の付随の欄の見出し | 見出しが空のまま、住宅事業の買主 1 のアンケートの登録画面（`/housing/customers/1/surveys/create`）で「①建売住宅の購入」を選ぶと、見えるのは「▼ 建売住宅の購入」だけ（「希望エリア」の文字なし）。問 2 の編集画面で見出しに「希望エリア」を入れて「更新する」→ 一覧・帯「設問を更新しました。」・`settings` が `{"sub_heading":"希望エリア"}`・種類は変わらない。`options` は欄のキー（`city` `area` `tsubo` `land_tsubo`）と見出し・種類が前と同じで、違いは欄の無い 2 つの選択肢に `"sub_fields":[]` が足されたことだけ（計画の保存の形どおり）・回答 4 件は 1 文字も変わらない。登録画面を開き直して同じ選択肢を選ぶと「▼ 建売住宅の購入 — 希望エリア」が見え、ほかの選択肢の見出しは隠れたまま | OK |
| 7 | 375px と 1440px | 一覧・追加（単一選択・付随テキスト・条件分岐・スライダー・数値・テキストを順に）・編集（問 2）で、`main.scrollWidth − main.clientWidth` = 0・文書の横スクロール 0・`overflow` が hidden／clip で中身が枠より広い要素 0・`main` の左右の外へ出る要素 0（入力欄も 0）。保存の帯（`data-form-actions-bar`・375px は左端 0）の上端は 1440px で 826px・375px で 738px、`main` を一番下まで送ったときの最後の欄の下端は 1440px で 479〜747px・375px で 525〜675px（どれも帯より上）で、最後の欄の中心の `elementFromPoint()` はその欄自身（帯に隠れない）。375px の一覧は 1 行が設問文 207px・編集／削除が右に縦並び、回答の件数の注意（275px）と種類の固定の文（1 行）も枠の中に折り返す | OK |
| 7 | 削除の確認の小窓 | 一覧の「削除」で `confirm` の文が「「ご希望の広さ（試し・スライダー）」を削除しますか？回答がある設問は無効にします。」（1440px）・「「ご希望の土地の広さ（試し・数値）」を…」（375px）。「キャンセル」で消えない。回答の無い試しの設問（id 18）で「OK」→ 帯「設問を削除しました。」・行が消える | OK |
| 8 | コンソール | 訪ねた全部の画面（一覧・追加の画面 12 回と入力エラーで戻った 2 回・編集の画面 6 回・アンケートの登録画面 2 回）で、`console_messages`（はじめから・warning 以上）は 0 件。最後に `console.error()` と捕まえない例外のカナリアを 1 つずつ出すと 2 件として出た（拾う仕組みは効いている）。各画面の `x-data` の要素はどれも Alpine が組み立てていた | OK |

#### 気づいたこと（直していない）

1. 無効の行の地 `#fafafa` は有効の行の `#fff` とのコントラスト比が 1.04:1 で、地だけでは見分けられない（Task 6 の後回し）。設問文の灰色・番号なし・「無効」のピルで見分けられるので欠陥とはしない
2. ↑↓ の二度押し（Task 6 の後回し）: 同じ ↓ を 60ms あけて 2 回押すと POST が 2 本届き、設問が 2 段動いた（id 13 が 8 番目 → 10 番目）。Playwright の `dblclick` では POST は 1 本だった
3. ネイティブの `title` のツールチップは OS が描くので、ページのスクリーンショットには写らない（`elementFromPoint()` の祖先と `:hover` の連なりで見た）
4. Playwright のスナップショット（`.yml`）とコンソールの記録は main repo の `.playwright-mcp/` に書かれる（git の管理外）。スクリーンショットは main repo の直下に撮ってすぐ scratchpad へ移した

#### スクリーンショット（`<scratchpad>/task9/`。コミットしない）

`task9-01-list-1440.png`（一覧・無効の行）／ `task9-02-move-up-target-1440x500.png`（↑ のあと着いた行）／ `task9-03-hover-first-up.png`（先頭の ↑ にホバー）／ `task9-04-add-select-with-text.png`（付随テキストの追加）／ `task9-05-add-conditional-before-submit.png`（条件分岐の行と欄を動かしたあと）／ `task9-06-edit-conditional-reopened.png`（保存して開き直した編集画面）／ `task9-07-error-slider.png`・`task9-08-error-conditional.png`（入力エラーで戻った画面）／ `task9-09-edit-answered-locked.png`（回答がある問 2 の編集画面）／ `task9-10-survey-create-heading-empty.png`（見出しが空のアンケートの登録画面）／ `task9-11-survey-create-heading-kibou-area.png`（「▼ 建売住宅の購入 — 希望エリア」の箱）／ `task9-12-list-375.png`・`task9-13-create-conditional-375.png`・`task9-14-edit-answered-375.png`（375px）。`<scratchpad>` は `/private/tmp/claude-501/-Users-masanori-site-manage/e80904fb-8117-421f-ae6b-b2c150cf4f0b/scratchpad`

### 実測記録 Task 10（全体の点検と、マージ前の直し）

2026-11-02・PHP 8.3.36・枝 `survey-question-admin`。全体の点検（モデルは fable。差分 `6ba8ed07..4a945234` と設計書を渡した。読み取りだけ）のあと、点検が「マージ前に入れる」と勧めた小さな直し 5 つを新しい担当 1 人が入れ、範囲を絞って点検し直した。

#### 全体の点検（`6ba8ed07..4a945234`）

- 判定: **Ready to merge: Yes**（Critical 0・Important 0・Minor 8）。控えの後回し（約 40 件）の仕分けも頼み、マージ前に直すと勧められたのは Minor 1〜3（二度押し・名前を空にする・削除の確認の文）。ほかに Minor 4（別のフォームの控え）・7（テストの失敗の理由）も数行で済むので一緒に入れた（台帳の Ruling 21）
- 後回しにしたのは Minor 5・6・8（台帳の Ruling 22）: ①データの行の読み方を RFC 4180（逃がしなし）にそろえること（見出しは逃がしなしに変えたが、データの行は前からの既定の逃がしのまま。PHP 8.4 へ上げる日に `CsvImportReader` と一緒に直すのが自然）②部署の一覧が 3 か所にあること（設問の管理の `DEPARTMENTS` と `in:housing,realestate`・顧客の取込の `DEPARTMENTS` など）③`validateQuestion()` が約 75 行・`CustomerImportController::execute()` の照らし合わせの切り出し（どちらも読みやすさの話）

#### マージ前の直し 5 つ（点検の後 `4a945234..4a2b9230`）

どれも直す前に赤を確かめてから直した（赤の文言は `.superpowers/sdd/…/final-fix-report.md`）。

| 点検の指摘 | 直し | コミット |
|---|---|---|
| F-1（Minor 1）一覧の ↑↓ に二度押しの止めが無い（実ブラウザで 60ms あけて 2 回押すと POST が 2 本届いて 2 段動いた） | 取込の画面と同じ共通の部品 `_partials/_submit_once`（`submitOnce()`）をフォームごとに付け、2 回目の submit を取り消してボタンを押せなくする。端のボタンの `disabled` と包む span の `title` は変えない。テスト `test_each_arrow_form_is_sent_only_once`（描いた ↑・↓ のフォームが部品につながっていること〈構造〉と、部品の script を node で動かして 1 回目は通し・送信中は押せず・2・3 回目は取り消し・`pageshow` のあと押せること。部品の `@include` を `@if(false)` で包むと「定義の `<script>` が描かれていない」で赤） | `b41d056c` |
| F-2（Minor 2）回答のある保存済みの選択肢の名前を空にして保存すると、黙って消える（D3 の「件数を見せてから直す」守りを、× でなく名前を消す操作がすり抜ける。直す前の実測: 302 で一覧に着き `options` は `["B"]`・理由 0 件） | 保存されている行（`original` がある行）の名前が空なら断る。文は「選択肢 N 行目の名前を入力してください（外すときは × を押してください）。」（N は 1 始まり）。新しい行（`original` が空）の、ほかの欄に何も無い空の行は今までどおり黙って落とす。回答の有無は問わない（文のとおり、外すときは × を押す）。テストは `SurveyQuestionFormTest` の problems（2 データ）・validForm（新しい空の行が落ちること）・`SurveyQuestionEditTest::test_blanking_the_name_of_an_answered_option_is_refused`（画面から送ると編集画面へ戻り、設問と回答が 1 文字も変わらない） | `520f77ff` |
| F-3（Minor 3）削除の確認の文が回答の有無を問わず「…を削除しますか？回答がある設問は無効にします。」 | 文をサーバーで組み、1 つの `Js::from` で渡す。回答あり「「…」は回答が N件あるため、削除せずに無効にします。よろしいですか？」・回答なし「「…」を削除しますか？」（N は `(int)`）。テストは 4 データで全文を見る | `0af2b14d` |
| F-4（Minor 4）別のフォームの控え（`_old_input` の `name`・`memo`）がセッションにあるとき、編集画面の「入力エラーで戻った」判定が反応し、設問文が空・選択肢 0 行・無効で描かれた | 控えにこのフォームの欄（`label`）があるときだけ入力を戻す（追加・編集の両方が通る `formState()`）。テスト 2 本（編集・追加の画面） | `32cdadf2` |
| F-7（Minor 7）`test_a_label_with_a_comma_survives_the_template` の `assertStatus(200)` は、断られると失敗の文を作る途中で `Call to a member function all() on array` で落ち、理由が読めない（Bug #49 の形・Task 8 の I3b） | ステータスを `getStatusCode()` で見て、200 でないときの失敗の文に転送先の取込の画面の箱の `<li>` を載せる | `4a2b9230` |

- 範囲を絞った再点検: 5 件すべて addressed・open 0
- 全件（`4a2b9230`・CLAUDE.md の流し方）: **OK (4711 tests, 48112 assertions)**・3 分 36 秒・メモリのピーク 171.50 MB（`97e7b18f` の 4703 本から +8＝F-1 1・F-2 4〈データ 3＋画面 1〉・F-3 データ +1・F-4 2）
- コンパイル済みビュー（`view:cache` → 全部を `php -l` → `view:clear`）: **318 本 / INVALID 0**
- 走査の分類の変更: なし（`SubmitOnceTest` は `name="import_token"` を描くビューだけを分類するので、鍵を持たない ↑↓ のフォームは対象外）
- 変異テストはこの 5 つについて流し直していない（Task 8 の 52 通りは `97e7b18f` の表。5 つはそれぞれ直す前の赤と、F-1 は部品の `@include` を外す変異で確かめた）

#### 残したこと（直していない）

1. F-1 の二度押し止めはフォームごと。同じ行の ↑ と ↓、別の行の ↑ をすばやく続けて押すと、別々のフォームなので POST は 2 本届く（同じボタンの 2 回目は止まる）。ページ全体で 1 回にするなら、部品の外で止める別の作りが要る
2. 一覧の削除のフォームに二度押し止めは付けていない（2 回目は、回答なしなら 404・回答ありなら無効にするだけで結果は同じ）
3. F-2 は保存されている行なら回答の有無を問わず断る。行を全部空にした単一選択などでは「選択肢を 1 つ以上入れてください。」と、この行の文の 2 つが並ぶ（別の欄の理由）
4. 手で組んだ送信で設問文を配列にすると、`ShapeCheckingValidator` が控えから `label` を外す（Bug #121）ので、戻った画面は前の入力でなく保存済み（追加は最初の状態）で描かれる（理由の文は出る。画面からは起きない）
5. 上の「後回しにしたもの」3 つと、台帳の Minor の控え（道具の入れ子の行のテストは最上位の `x-if` の 1 形だけで 3 段以上は未検証・選択肢 50 行／欄 10 行の上限は画面に案内も止め方も無く送ってから断られる・押せない ↑↓ の理由は span の `title` だけでキーボード・読み上げに届かず `aria-label` がどの行か言わない・無効の行の地 `#fafafa` は `#fff` と 1.04:1 でほぼ見分けられない・`problems()` の 8 行のうち画面のテストがあるのは 3 行・Task 8 の記録の「理由まで一致」は理由を指定していない 6 行がある、など）は、BACKLOG の範囲外に要約した
