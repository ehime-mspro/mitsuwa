# アンケートの回答の検査と編集画面での読み戻し — 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 買主のご来場アンケートの回答を、保存のときに検査し（日本語の理由つき・一度に出す）、編集画面へ正しく読み戻す。そのまま保存しても回答が変わったり消えたりしないようにする。CSV 取込の回答も同じ形・同じ基準にする。

**Architecture:** 回答の検査、保存の形への変換、読み戻し、部品が描く状態は、新しいクラス `App\Support\SurveyAnswers` の 1 か所に置く。4 つの入口（アンケートの登録・編集、顧客の新規登録、CSV 取込）はこのクラスを使う。画面の 3 入口では、規則をリテラルで書いた `Validator::make(...)` の `after()` からこのクラスを呼ぶ。こうすると、今ある項目の誤りとアンケートの誤りが一度に出て、和名の走査（`JapaneseValidationMessagesTest`）も読める形のまま残る。部品 `buyers/_survey_form.blade.php` は `SurveyAnswers::formState()` の結果を描くだけにする。

**Tech Stack:** Laravel 12・PHP 8.3（本番も 8.3）・Blade と Alpine 3・PHPUnit（`vendor/bin/phpunit`）・node の vm（`DrivesAlpineFetch`）

**Spec:** `docs/superpowers/specs/2026-10-27-survey-answer-validation-design.md`（枝 `survey-answer-validation` にだけある。絶対パスは `/Users/masanori/site/manage/.claude/worktrees/route-coverage/docs/superpowers/specs/2026-10-27-survey-answer-validation-design.md`）。設計書の章番号を「設計 6 章」のように書く。

## Context（なぜやるか）

- 保存した回答は `BuyerSurveyAnswer::decoded_value` が `json_decode()` を通すので、`3500` `123` `3` が数になって戻る。部品は値が文字のときしか入れないので、編集画面では数値入力・テキスト入力・単一選択の回答が空で出て、スライダーは真ん中の値に戻る。`update()` は回答を全部消してから作り直すので、編集画面をそのまま保存すると、回答が消えるか書き換わる。本番では、問 6（予算）の 5 件が編集画面を開くと 4000 万円に戻る（設計 1.1・1.3）
- 4 つの入口のどこも、回答を検査していない。選択肢に無い値も、そのまま保存される
- 利用者の決定（設計 5 章）と、設計の担当が決めたこと（設計 9 章。利用者に表で見せた）を実装する

## 作業場所・流し方

- 作業場所: `/Users/masanori/site/manage/.claude/worktrees/route-coverage`（枝 `survey-answer-validation`。親は `13.x` = `69c43b0c`）。コマンドはこのフォルダで流す
- テスト: `APP_KEY="base64:$(openssl rand -base64 32)" /opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit <パス>`。`.env` は作らない（実 DB に届かないようにする安全策）
- 使い捨てのテストは scratchpad に置き、`namespace Tests\Probe` で作業場所から流す。`VIEW_COMPILED_PATH` を scratchpad に向け、`--do-not-cache-result` を付ける
- 変異の道具: `/Users/masanori/site/manage/.claude/route-coverage-tools/mutate/run.py`。`run.py` は `specs.py` を読む。今の `specs.py` は `specs.js-db-value-embedding.py` と同じ中身なので、上書きしてよい
- 実ブラウザの使い捨ての環境: `/Users/masanori/site/manage/.claude/route-coverage-tools/browser/README.md`
- 「社内決裁申請」の会話（決裁の担当）の画面とテストには触らない。`13.x` へ早送りする前と deploy の前には、その会話に知らせる

## Global Constraints（全タスク共通）

- DB の変更はしない（`answer_value` は TEXT のまま）。本番の回答も書き換えない（設計 1.3・3 章）
- 新しい PHP クラスが 1 つ増える（`app/Support/SurveyAnswers.php`）。本番へ反映するときは、main repo の cwd で `composer dump-autoload --no-dev --optimize` を流す（この計画では反映しない）
- JS に書く値は `{{ \Illuminate\Support\Js::from(…) }}` で書く。`old()` は `Js::from((string) old(…))` の形にする（`JsEchoClassificationTest`・`JsStringEchoScanTest`）
- `<option>` は `@foreach` で静的に描く（Top trap #3）。`x-data` には名前つきの関数を使う（Top trap #4）。`style=` と `:style=` を同じ要素に置かない（Top trap #5）
- 同じ `name` の欄を `x-show` で隠すときは、`:disabled` で送らないようにする（CLAUDE.md「Form」・Bug #3）
- 入力エラーの文に英字の項目名（`survey.3` など）を出さない。項目名には設問の文（`$question->label`）を使う
- テストは、描いた画面のフォームを送り返す往復で書く（Bug #47）。入力エラーで戻った画面は `assertSessionHas*()` を呼ばずに描いて見て、1 件の全文で確かめる（Bug #49）
- 新しく足すテストは、直す前のコードで赤になることを確かめてから直す
- `resources/views/approvals/` と決裁のテストには触らない
- コミットは Conventional Commits（件名は日本語・72 文字以内・句点なし）、1 コミット 1 関心事。本文の最後に `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` を付ける。モデルを指定したサブエージェントは、自分の文脈にある Co-Authored-By の行を使う。`git stash` と `--no-verify` は使わない

## Review Focus（どのタスクのテストもまだ通らない入力。持ち主のタスクにテストを足した）

1. **本番の空の形の回答**: 問 1 は `[]`（4 件）、問 2 は付随の欄がすべて空の形（5 件）。編集画面を開いても 500 にならず「回答なし」で描き、保存するとその行は消える（設計 6 章）→ Task 2 `test_production_shaped_empty_answers_open_and_disappear_on_save`
2. **本番のスライダーの 5 件**（2000〜6000 万円・100 刻みの中の値）: 編集画面に保存した値で出て、そのまま保存しても同じ値のまま → Task 2 の往復テストのスライダー `'3500'`
3. **壊れやすい文字を含む選択肢・保存済みの値**（`'` `"` `&` `<` と末尾の `\`）: 「（選択肢に無い値）」として描いたとき、画面の JS が止まらず元の文字のまま戻る → Task 3 `test_a_renamed_option_keeps_the_saved_answer_and_shows_it_as_outside_the_options`
4. **設定や選択肢が無い設問**（`options` / `settings` が null）: 検査が 500 にならない。スライダーは部品と同じ既定値（0〜100・1 刻み）を使う → Task 1 `test_questions_without_options_or_settings_do_not_break`
5. **CSV のセルの前後の空白・全角の空白**: 画面の登録と同じく落としてから読む（`Str::trim`） → Task 5 `test_csv_cells_are_read_like_the_screen`

---

## Task 0: 測っていない項目を確かめる（コードは変えない）

設計 1.2 章の項目はコードと HTML から読んだだけで、まだ測っていない。直す前のコードで測る。

**Files:**
- Create（コミットしない）: `<scratchpad>/probe/SurveyProbeTest.php`（`namespace Tests\Probe`・`RealEstateScreenTestCase` を継承）
- Create（コミットしない）: `<scratchpad>/survey-env.php`（実ブラウザ用。下の Step 3）
- Create（コミットする）: `docs/superpowers/plans/2026-10-27-survey-answer-validation.md`（この計画を写したもの。後で実測記録を足す）

- [ ] **Step 1: この計画を `docs/superpowers/plans/2026-10-27-survey-answer-validation.md` に写してコミットする**（`docs: アンケートの回答の検査の実装計画を足す`）

- [ ] **Step 2: 使い捨てのテストで P1〜P6 を測る**。どれも、描いた画面のフォームを `composedForm()` / `parseForm()` で組んで送る（手で組んだ送信にしない）

| # | 測ること | 設計の見込み |
|---|---|---|
| P1 | 無効にした設問の回答が残っている編集画面を、そのまま保存する | 無効の設問の回答の行が消える |
| P2 | 単一選択の回答 `A` を保存し、設問の選択肢を `A2` に変えてから、編集画面をそのまま保存する | 回答の行が消える |
| P3 | スライダーを触らずにアンケートの登録画面から保存する | 真ん中の値の行ができる |
| P4 | 選択肢＋付随テキスト・条件分岐付き選択・スライダーの設問がある部署で、何も答えずに顧客を新規登録する | アンケートが 1 件でき、行は `[]`・空の形・真ん中の値 |
| P5 | 条件分岐付き選択で、付随の欄の `key` が選択肢をまたいで重なる設問（Task 2 の土台の `conditional` と同じ形）の 1 つ目の選択肢を選び、欄を埋めて保存する | 付随の欄が後ろの空の欄で消える |
| P6 | 来場日を空にして、テキストの回答を入れて送る | 戻った画面のテキストの欄が空 |

- [ ] **Step 3: P5 を実ブラウザで再現する**。README の手順で使い捨ての環境を起動する。ただし、DB は scratchpad に写した `demo.sqlite` を使い、`survey-env.php` で `survey_questions`（`CreatesSurveyQuestionSchema` と同じ列）と住宅事業の 6 問を足す。6 問は本番と同じ構成（設計 1.3）にし、問 2 は下の重なる形にする:
  `建売住宅の購入`[city 文字・area 文字・tsubo 数] ／ `土地を購入して新築`[city・land_tsubo 数] ／ `中古住宅の購入`[city・area・tsubo] ／ `建て替え`[land_tsubo] ／ `リフォーム` ／ `まだ決めていない`。
  問 6 はスライダー（2000〜6000・100 刻み・単位 万円）。住宅事業の買主がいなければ 1 人足す。Playwright で住宅事業の買主のアンケートの登録画面を開き、「建売住宅の購入」を選んで市・エリア・坪数を入れて保存する。保存された `answer_value` を sqlite3 で読み、sub が空になったことを確かめる。「土地を購入して新築」でも同じように確かめる

- [ ] **Step 4: 結果を計画の「実測記録 Task 0」に書いてコミットする**（`docs: アンケートの回答の直す前の実測を記録する`）。**どれかが見込みと違えば、そこで止めて利用者に知らせる**（直し方が変わりうるため）。見込みどおりなら、P1〜P6 は Task 2〜4 の回帰テストに入れる

---

## Task 1: `SurveyAnswers` — 検査・保存の形・読み戻し

**Files:**
- Create: `app/Support/SurveyAnswers.php`
- Test: `tests/Unit/Support/SurveyAnswersTest.php`（`Tests\TestCase` を継承する。DB は使わない。設問は `new SurveyQuestion([...])` で作る）

**Interfaces（Produces）:**

```php
namespace App\Support;

final class SurveyAnswers
{
    public const TEXT_MAX = 255;          // テキスト入力
    public const SUB_TEXT_MAX = 100;      // 付随の文字・付随の欄の文字
    public const SUB_NUMBER_MAX = 99999;  // 付随の欄の数（0 以上・小数 2 桁まで）

    /**
     * 1 問の回答（画面の形）を検査し、保存の形（answer_value）にする。
     * $saved はその設問に保存済みの回答を decode() したもの（登録・顧客の新規登録・CSV は null）。
     * $subject は誤りの文の主語（画面「「{label}」」・CSV「Q{n}（{label}）」）。誤りの文の末尾に「。」は付けない。
     * @return array{0: ?string, 1: list<string>}  [answer_value（回答なしは null）, 誤り]
     */
    public static function check(SurveyQuestion $question, mixed $value, mixed $saved, string $subject): array;

    /**
     * 画面の `survey` をすべて検査する。誤りは `survey.{id}` のキーで $errors に足し、文の末尾を「。」で終える。
     * @param iterable<SurveyQuestion> $questions  画面に出した設問
     * @param array<int, mixed> $saved  設問 id => decode() した保存済みの回答
     * @return array<int, string>  回答した設問の id => answer_value
     */
    public static function checkScreen(iterable $questions, mixed $submitted, array $saved, \Illuminate\Support\MessageBag $errors): array;

    /** 保存された answer_value を、check() の $saved と formState() が読む形にする（形が合わなければ null） */
    public static function decode(SurveyQuestion $question, ?string $answerValue): mixed;

    /** 選択肢の値（文字の選択肢はそのまま、配列の選択肢は 'value'）。options が null なら [] */
    public static function optionValues(SurveyQuestion $question): array;
}
```

- 種類は `$question->question_type`（enum `SurveyQuestionType`）から読む。`getRawOriginal()` は使わない（保存前のモデルでは null になる）
- decode() の返す形: 単一選択・テキスト・数値・スライダー → 文字（JSON として読まない）。複数選択 → `list<string>`。選択肢＋付随テキスト → `list<array{value: string, text?: string}>`（中の文字は `['value' => 文字]` に直す）。条件分岐付き選択 → `array{value: string, sub?: array<string, string>}`。JSON として読めない・形が合わないときは null（CSV 取込の古い形 `A,B` も null になる。設計 12 章）
- 検査と保存の形は設計 6 章の表のとおり。「保存済みと同じ値は、どの検査も通す」は、比べる単位ごとに見る（設計 6 章の 2 つ目の点）
- 条件分岐付き選択の付随の欄で受け取るキー: 選んだ選択肢の今の `sub_fields` のキー。加えて、選んだ値が保存済みと同じなら、保存済みの sub のキーも受け取る。ほかのキーは誤りにせず、保存しない
- 選択肢＋付随テキスト: 値が空の要素は落とす（チェックを外した欄の文字だけが届く形）。文字を保存するのは、その選択肢が `has_text` のとき、または同じ値の保存済みの要素が文字を持っていたとき
- 複数選択・選択肢＋付随テキストは、同じ値が 2 回あれば最初の 1 つだけを残す
- 回答の形が違う値（単一選択に配列が届く、など）は誤りにする

**誤りの文（主語のあと。画面は末尾に「。」を足す）:**

| 場面 | 文 |
|---|---|
| 選択肢に無い | `{主語}の「{値}」は選択肢にありません` |
| 形が違う | `{主語}の回答の形が正しくありません` |
| テキストが長い | `{主語}は255文字以内で入力してください（{n}文字）` |
| 数でない | `{主語}は数で入力してください` |
| 数が範囲の外 | `{主語}は{min}〜{max}{単位}の範囲で入力してください`（下限だけなら `{min}{単位}以上で`、上限だけなら `{max}{単位}以下で`） |
| スライダー | `{主語}は{min}〜{max}{単位}の{step}{単位}刻みで選んでください` |
| 付随の文字が長い | `{主語}の「{値}」に添える文字は100文字以内で入力してください（{n}文字）` |
| 付随の欄の文字が長い | `{主語}の{欄のラベル}は100文字以内で入力してください（{n}文字）` |
| 付随の欄の数 | `{主語}の{欄のラベル}は0〜99999の数（小数2桁まで）で入力してください` |

数は設定の値をそのまま出す（桁区切りは付けない。設計 6 章の例「2000〜6000万円の100万円刻み」）。

- [ ] **Step 1: 失敗するテストを書く**（設問の作り方: `new SurveyQuestion(['label' => 'ご予算', 'question_type' => 'slider', 'settings' => ['min' => 2000, 'max' => 6000, 'step' => 100, 'unit' => '万円']])`）

```php
test_each_type_is_stored_in_the_saved_form  // データ: 単一 '3' => '3'／複数 ['A','B'] => '["A","B"]'／テキスト '123' => '123'／数値 '3500' => '3500'／スライダー '3500' => '3500'
    // 選択肢＋付随 [['value'=>'ご紹介','text'=>'山田様'],['text'=>'x']] => '[{"value":"ご紹介","text":"山田様"}]'
    // 条件 ['value'=>'建売住宅の購入','sub'=>['city'=>'松山市','area'=>'','tsubo'=>'40']] => '{"value":"建売住宅の購入","sub":{"city":"松山市","tsubo":"40"}}'
test_nothing_answered_is_not_stored         // null・''・[]・選択肢＋付随で文字だけ・条件で value が空 → [null, []]
test_a_value_outside_the_options_is_refused // 単一 '社宅' → ['「住まい」の「社宅」は選択肢にありません']（複数・付随・条件も同じ文）
test_a_saved_value_passes_even_when_it_is_no_longer_valid
    // 選択肢の名前を変えた単一・複数／範囲の外の数値・スライダー／256 文字のテキスト／条件の今の欄に無い保存済みのキー
    // → 誤り 0、保存の形は保存済みと同じ
test_a_changed_value_is_checked_by_the_current_rules  // 保存済みと違う値は今の基準で断る
test_text_is_limited_to_255_characters      // 全角 255 文字は通る・256 文字は '「希望の間取り」は255文字以内で入力してください（256文字）'
test_numbers_and_sliders_are_checked_against_the_settings
    // スライダー 2000・6000 は通る（境目）・1900／6100／3550 → '「ご予算」は2000〜6000万円の100万円刻みで選んでください'
    // 数値 'abc' → '…は数で入力してください'・下限 10 上限 500 の 9 と 501 → '…は10〜500坪の範囲で入力してください'
test_select_with_text_keeps_text_only_for_options_that_take_it  // has_text でない選択肢の文字は落とす・101 文字は誤り
test_conditional_select_keeps_only_the_chosen_options_fields    // ほかの選択肢のキーは保存しない・100 文字超・-1・100000・1.234 は誤り・40.25 は通る
test_multi_select_keeps_the_first_of_duplicates                 // ['A','A','B'] => '["A","B"]'
test_a_value_of_the_wrong_shape_is_refused                      // 単一に ['A']・複数に 'A'・条件に 'x'
test_questions_without_options_or_settings_do_not_break         // options null の単一 'A' は選択肢に無い・settings null のスライダーは 0〜100・1 刻み
test_decode_reads_back_each_type   // '3500'（数値・スライダー）・'123'（テキスト）・'3'（単一）は文字のまま／'["A","ZZZ"]' は配列／'A,B'（複数）は null
```

- [ ] **Step 2: 流して赤を確かめる**（`SurveyAnswers` が無いので全部 Error になる）
- [ ] **Step 3: `app/Support/SurveyAnswers.php` を書く**（Interfaces と上の表のとおり）。docblock に設計書のパスと「保存済みと同じ値は通す」理由（Bug #82・#113 の考え方）を書く
- [ ] **Step 4: 流して緑を確かめる**
- [ ] **Step 5: コミット**（`feat: アンケートの回答の検査と保存の形を 1 か所にまとめる`）

---

## Task 2: アンケートの登録・編集の画面（サーバ側）

**Files:**
- Modify: `app/Http/Controllers/CustomerSurveyController.php`（store・edit・update、`normalizeAnswerValue()` を消す）
- Modify: `app/Models/BuyerSurveyAnswer.php`（`getDecodedValueAttribute()` を消す。使っているのは `edit()` だけ。消す前に `grep -rn decoded_value app resources tests` でほかに無いことを確かめる）
- Create: `tests/Feature/RealEstate/Screens/SurveyScreensTest.php`（`RealEstateScreenTestCase` を継承する。Task 3・4 もこのファイルに足す）
- Modify: `tests/Feature/RealEstate/Screens/SurveyAnswerJsTest.php`（下の Step 6）

**Interfaces:**
- Consumes: `SurveyAnswers::checkScreen()`・`decode()`（Task 1）
- Produces: `edit()` がビューへ渡す `$existingAnswers` は「設問 id => `decode()` の値」（今出している設問の分だけ）。Task 3 の部品はこれを `$saved` として読む

**作り:**
- store / update の検査は、規則をリテラルで書いた `Validator::make($request->all(), [ …今の 3 項目… ])->after(function ($v) use (…, &$answers) { $answers = SurveyAnswers::checkScreen(…, $v->errors()); })->validate()` の形にする。⚠ アロー関数（`fn`）は外の変数を値で受け取るので、`$answers` に入れられない。`after()` を使うと、今の項目の誤りとアンケートの誤りが一度に出る（Bug #112）。和名の走査が読むのは `Validator::make(` の第 2 引数のリテラルだけで、引数の無い `->validate()` は読み飛ばす（`CollectsValidationKeys`）
- update(): 消すのは、画面に出した設問（その部署の有効な設問）の回答だけにする（`whereIn('question_id', …)`）。ほかの設問の回答は残す（設計 9 章）。保存済みの回答（`$saved`）は、フォームから受け取らず DB から読む
- edit(): `$existingAnswers[$q->id] = SurveyAnswers::decode($q, $answer->answer_value)`

**テストの土台（`SurveyScreensTest` の私有メソッド。Task 3〜5 も使う）:** `questions(): array` が realestate の設問を 7 問作る（種類ごとに 1 問。sort_order 1〜7）。

| キー | 種類 | 中身 |
|---|---|---|
| `withText` | 選択肢＋付随テキスト | 来場のきっかけ: `チラシ`・`ご紹介`(has_text・「ご紹介者」)・`その他`(has_text) |
| `conditional` | 条件分岐付き選択 | ご計画: Task 0 Step 3 と同じ 6 つ（キーが重なる） |
| `single` | 単一選択 | 家族の人数: `1`・`2`・`3` |
| `multi` | 複数選択 | 気になる設備: `A`・`B`・`C` |
| `text` | テキスト入力 | 希望の間取り |
| `number` | 数値入力 | 希望の広さ: min 10・max 500・unit 坪 |
| `slider` | スライダー | ご予算: min 2000・max 6000・step 100・unit 万円 |

フォームは `composedForm($html, $action, null, null, ['sliderComponent{id}' => …, 'condSelect{id}' => …])` で組む（入れ子のコンポーネントは名前ごとに別に評価する）。
- 素の欄（テキスト・数値・ラジオ・チェックボックス・付随の欄）に答えるときは、組んだフォームの `fields` を書き換える（利用者が打ち込んだり押したりするのと同じ）。入れ子のコンポーネントの欄は入れ子の配列（`$form['fields']['survey'][$id]['sub']['land_tsubo']`）で入っている。`fill()` は平らな名前しか見ないので、書き換える前に、その `name` の欄が描いた HTML にあることを確かめる私有の道具を作る（無い欄に値を足すと、画面から欄が消えても緑になる）
- スライダーを動かす手順は `data.sliderVal = '3500'; data.skip = false;` と書く（Task 3 の前の部品は `skip` を持たないが、足しても害は無い。こう書いておけば Task 3 のあとも同じテストが使える）
- Task 2 の往復では、条件分岐付き選択は「建て替え」（`land_tsubo` を最後に持つ選択肢）を選ぶ。直す前の部品でも、同じ名前の欄は最後のものが残るので通る。キーが重なって消える形（「建売住宅の購入」）は Task 3 で見る

- [ ] **Step 0: テストの道具 `composedForm()`（`tests/Concerns/ComposesScreenForms.php`）の入れ子の欄の足し方を直す**。今は `$nestedFields += …` で、2 つの部品の欄がどちらも同じ一番上のキー（`survey`）の下にあると、2 つ目の欄が黙って捨てられる（Task 0 の P4 で見つけた）。`array_replace_recursive($nestedFields, …)` に変える（同じ名前の欄は後ろが残る＝ PHP がブラウザの送信を読むのと同じ）。docblock に理由を書き、`tests/Feature/Housing/Screens`・`tests/Feature/Mansion/Screens`・`tests/Feature/Dad/Screens` を流して緑のままであることを確かめてから、単独でコミットする（`test: 入れ子の部品の欄を同じキーの下でも捨てずに合わせる`）。⚠ 名前が `[]` で終わる欄が 2 つの部品にまたがると、ブラウザは足し、この足し方は番号で上書きする（今の画面には無い。docblock に書く）

- [ ] **Step 1: 失敗するテストを書く**

```php
test_every_type_survives_opening_and_saving_the_edit_screen
    // 登録画面から 7 問に答えて保存 → 編集画面をそのまま composedForm で送る → 7 行の answer_value が登録時と同じ
    // 単一 '3'・数値 '3500'・テキスト '123'・スライダー '3500' を含む（設計 1.1 の消え方）
test_production_shaped_empty_answers_open_and_disappear_on_save
    // DB に withText '[]'・conditional '{"value":"","sub":{"city":"","area":"","tsubo":""}}' を直接入れる → 編集画面 200 → そのまま保存で 2 行とも消える
test_answers_to_questions_not_on_the_screen_are_kept_on_save   // P1。無効にした設問の回答の行が残る
test_values_outside_the_options_are_refused_with_the_question_label
    // 描いた登録フォームの単一を '社宅' に、スライダーを '3550' に書き換えて送る → 戻った画面の箱に 2 件の <li> が全文で出る、行は 0
test_survey_errors_come_with_the_other_errors_at_once           // 来場日を空・単一 '社宅' → 「来場日は必須です。」と単一の文が同じ箱に出る
```

- [ ] **Step 2: 流して赤を確かめる**（往復は数値・テキスト・単一・スライダーが消えるか変わる、P1 は行が消える、検査の 2 本は保存されてしまう、という理由で落ちること）
- [ ] **Step 3: `CustomerSurveyController` を直す**（上の「作り」。`normalizeAnswerValue()` を消す）
- [ ] **Step 4: `BuyerSurveyAnswer::getDecodedValueAttribute()` を消す**
- [ ] **Step 5: 流して緑を確かめる**。`test_values_outside_the_options…` のスライダーは、部品が値を送る形のまま（Task 3 の前）でも検査で断れることを見る
- [ ] **Step 6: `SurveyAnswerJsTest` を直す**。2 本は手で組んだ値を `store()` へ送っていたが、今は検査が断るので、回答の行を DB に直接入れる形に変える
  - `test_a_saved_answer_reaches_the_edit_screen_as_it_is`: 選択肢に無い保存済みの値として入れる（`answer_value` = `{"value":"回答'\"&<\\"}`）
  - `test_an_answer_saved_as_an_array_does_not_break_the_edit_screen`: `{"value":["a"],"sub":{"area":["b"]}}` を入れる
  - 期待（JS の値・200）は変えない
- [ ] **Step 7: `tests/Feature/RealEstate/Screens`・`tests/Feature/Housing/Screens`・`tests/Unit/Support/SurveyAnswersTest.php`・`tests/Feature/JapaneseValidationMessagesTest.php` を流して緑を確かめる**
- [ ] **Step 8: コミット**（`fix: アンケートの回答を検査し、編集画面へ正しく読み戻す`）

---

## Task 3: 部品 `buyers/_survey_form.blade.php`

**Files:**
- Modify: `app/Support/SurveyAnswers.php`（`formState()` を足す）
- Modify: `resources/views/buyers/_survey_form.blade.php`
- Modify: `tests/Unit/Support/SurveyAnswersTest.php`・`tests/Feature/RealEstate/Screens/SurveyScreensTest.php`
- Modify: `tests/Feature/JsEchoClassificationTest.php`（部品の行。新しい値はすべて `Js::from` で書き、分類表の行は必要なものだけにする。使わなくなった行は消す）

**Interfaces（Produces）:**

```php
/**
 * 部品が 1 問を描く状態。$shown は描く値（入力エラーで戻ったなら old() の画面の形、でなければ保存済みの decode() の値か null）。
 * $saved は保存済みの decode() の値（編集画面だけ。ほかは null）。
 * @return array{
 *   choices: list<array{value: string, label: string, checked: bool, extra: bool, hasText: bool, textLabel: string, text: string,
 *                       subFields: list<array{key: string, label: string, type: 'text'|'number', value: string, extra: bool}>}>,
 *   selected: string,   // 単一選択・条件分岐付き選択で選んでいる値（無ければ ''）
 *   savedSelected: string, // 条件分岐付き選択の保存済みの値（今の欄に無いキーの欄の :disabled に使う）
 *   value: string,      // テキスト・数値の欄の値
 *   slider: array{min: int, max: int, step: int, unit: string, value: string, answered: bool}
 * }
 */
public static function formState(SurveyQuestion $question, mixed $shown, mixed $saved): array;
```

- `extra` は、描く値が今の選択肢に無いときに足す選択肢を表す。ラベルは `値（選択肢に無い値）`。条件分岐付き選択の `subFields[].extra` は、保存済みの sub にあって今の欄に無いキー（ラベルはキー。保存済みの値が空のキーは描かない）
- スライダーの `answered`: `$shown` が null か `''` なら false（登録画面・保存済みの回答が無い編集画面・入力エラーで戻って送られていなかったとき）。`value` は、答えていれば `$shown`、答えていなければ真ん中の値（今の `(int)(($min+$max)/2)`）
- 部品の Alpine の状態の名前（テストが使う）: スライダー `sliderComponent{id}()` → `{ sliderVal: string|int, skip: bool }`（`skip` が true なら「回答しない」）。条件分岐付き選択 `condSelect{id}()` → `{ selectedOption: string, savedOption: string }`

**部品の作り（設計 7 章）:**
- 各問の最初で次のように決める: `$saved = $existingAnswers[$qId] ?? null; $shown = session()->hasOldInput() ? old("survey.{$qId}") : $saved; $state = SurveyAnswers::formState($q, $shown, $saved);`
- 単一・複数・付随・条件は、`$state['choices']` を `@foreach` で描く。付随の欄の `name` の番号は `$loop->index` のまま（足した選択肢も続きの番号）
- 条件分岐付き選択: ラジオは `x-on:change` をやめて `x-model="selectedOption"` にする（静的な `checked` も残す）。こうすると、テストの `browserForm()` がラジオの選択を JS の状態で組める。選択肢の文字は静的な `value` 属性に入れる（HTML として逃がされる）。付随の欄すべてに `:disabled="selectedOption !== {{ Js::from(その選択肢の値) }}"` を付ける。今の欄に無いキー（`extra`）は、`savedOption` と同じ選択肢を選んでいるときだけ送る。値は、描く値の選択肢の欄にだけ入れる
- スライダー: 範囲の入力欄（`type="range"`）は `name` を持たず、`x-model="sliderVal"`・`x-on:input="skip = false"` にする。送るのは `<input type="hidden" name="survey[{id}]" :value="sliderVal" :disabled="skip">` の 1 つだけ。「回答しない」のチェック（`name` を持たない）は `x-model="skip"`。値の表示は `x-text="skip ? '—' : Number(sliderVal).toLocaleString()"` にする。初期値は `Js::from($state['slider']['value'])`・`skip: Js::from(! $state['slider']['answered'])`
- テキスト・数値の欄の `value` は `$state['value']`

- [ ] **Step 1: 失敗するテストを書く**（Unit は `formState`、Feature は `SurveyScreensTest`）

```php
// Unit
test_form_state_marks_values_outside_the_options            // 単一の保存済み 'A' で選択肢が 'A2' → choices の最後が value 'A'・label 'A（選択肢に無い値）'・checked・extra
test_form_state_slider_is_unanswered_until_a_value_is_given  // null → answered false・value 4000／'3500' → true・'3500'
test_form_state_reads_both_saved_and_submitted_shapes        // 付随: old の形 [0=>['text'=>'x'],1=>['value'=>'ご紹介','text'=>'山田様']] と保存の形が同じ choices になる
test_form_state_shows_saved_fields_that_the_option_no_longer_has  // 条件の保存済み sub 'old_key' が subFields に extra で出る・空の保存済みキーは出ない
// Feature
test_conditional_fields_keep_their_values_when_keys_overlap  // P5。1 つ目の選択肢を選んで欄を埋めて保存 → sub が全部残る（composedForm の condSelect の手順で selectedOption を変える）
test_a_new_survey_does_not_store_the_slider_until_it_is_moved // P3。登録画面をそのまま保存 → スライダーの行 0／手順で sliderVal='3500'・skip=false → '3500'
test_choosing_not_to_answer_removes_a_saved_slider_answer    // 編集で skip=true にして保存 → 行が消える
test_a_saved_slider_outside_the_current_range_is_kept         // 保存済み '1500'（今は 2000〜6000）→ 編集画面をそのまま保存しても '1500'
test_the_range_input_sends_nothing_itself                      // 描いた HTML の type="range" に name が無く、hidden の survey[id] がちょうど 1 つ
test_a_renamed_option_keeps_the_saved_answer_and_shows_it_as_outside_the_options
    // 単一・複数・付随・条件の保存済みの値を "旧'\"&<\\" にしてから選択肢を変える → 編集画面に「（選択肢に無い値）」で選ばれて出る → そのまま保存しても 4 行とも同じ（P2）
test_answers_come_back_after_an_input_error                    // P6。来場日を空にして 7 問に答えて送る → 戻った画面を composedForm で組むと送った値と同じ（スライダーを回答しないにしたものは回答しないのまま）
```

  あわせて `SurveyAnswerJsTest::test_choosing_an_option_keeps_its_text_and_does_not_run_it_as_code` を書き換える（`x-on:change` が無くなるため）。見ることは同じで、`selectedOption` を各選択肢の値にしたとき、`x-show` と付随の欄の `:disabled` の式が、その選択肢でだけ開くこと・`globalThis.__ran` が立たないこと・ラジオの `value` 属性が元の文字に戻ることを見る

- [ ] **Step 2: 流して赤を確かめる**（P3・P5・P6 は Task 0 の見込みどおりの理由で落ち、formState の 4 本は Error になること）
- [ ] **Step 3: `formState()` を足し、部品を書き換える**
- [ ] **Step 4: 流して緑を確かめる**: `SurveyScreensTest`・`SurveyAnswerJsTest`・`tests/Unit/Support/SurveyAnswersTest.php`・`JsEchoClassificationTest`・`JsStringEchoScanTest`
- [ ] **Step 5: コミット**（`fix: アンケートの部品が保存済みの値と入力エラーの値を描き、隠れた欄を送らないようにする`）

---

## Task 4: 顧客の新規登録の画面のアンケート

**Files:**
- Modify: `app/Http/Controllers/CustomerController.php`（`store()`・`validateBuyer()`・`saveSurvey()`、`normalizeAnswerValue()` を消す）
- Modify: `tests/Feature/RealEstate/Screens/SurveyScreensTest.php`

**Interfaces:**
- Consumes: `SurveyAnswers::checkScreen()`（Task 1）
- `validateBuyer(Request $request, iterable $surveyQuestions = []): array<int, string>` は、今の `$request->validate([...])` を、同じリテラルの規則を持つ `Validator::make($request->all(), [...])->after(...)->validate()` に変える。返り値はアンケートの保存の形。`update()` は今どおり引数 1 つで呼ぶ（アンケートは無い）
- `saveSurvey(Request $request, Buyer $buyer, string $department, iterable $questions, array $answers): void` は、`$answers === []` なら何も作らない（設計 9 章）

- [ ] **Step 1: 失敗するテストを書く**

```php
test_a_new_customer_without_answers_gets_no_survey     // P4。7 問の部署で何も答えずに新規登録 → buyer_surveys 0 件
test_a_new_customer_survey_is_checked_with_the_customer_fields  // 姓を空・単一 '社宅' → 2 件が同じ箱・顧客は 0 件
test_a_new_customer_survey_is_stored_in_the_saved_form // 登録画面（buyerForm と入れ子 2 つを composedForm）から 7 問に答える → Task 2 の往復と同じ answer_value
```

- [ ] **Step 2: 流して赤を確かめる**
- [ ] **Step 3: `CustomerController` を直す**
- [ ] **Step 4: 流して緑を確かめる**: `SurveyScreensTest`・RealEstate と Housing の `CustomerScreensTest`・`JapaneseValidationMessagesTest`・`ValidationKeyScannerTest`
- [ ] **Step 5: コミット**（`fix: 顧客の新規登録でアンケートの回答を検査し、答えが無ければ作らない`）

---

## Task 5: 顧客の CSV 取込のアンケートの列

**Files:**
- Modify: `app/Support/SurveyAnswers.php`（`fromCsv()` を足す）
- Modify: `app/Http/Controllers/Admin/CustomerImportController.php`
- Modify: `tests/Unit/Support/SurveyAnswersTest.php`・`tests/Feature/Admin/CustomerImportTest.php`

**Interfaces（Produces）:**

```php
/**
 * CSV のセル 1 つを画面と同じ形にして check() にかける（$saved は null）。セルは Str::trim() してから読み、空なら回答なし。
 * @return array{0: ?string, 1: list<string>}
 */
public static function fromCsv(SurveyQuestion $question, string $cell, string $subject): array;
```

- セルの書き方は設計 8 章の表のとおり。複数選択と付随テキストは「、」か「,」で区切る。ただし、セル全体がちょうど 1 つの選択肢の名前と同じなら区切らない。区切ったものは 1 つずつ trim し、空は落とす
- 数値・スライダーは、全角の数字を `mb_convert_kana($cell, 'n')` で半角にし、`,` と `，` を除いてから読む
- 条件分岐付き選択は `{"value": 名前}`、付随テキストは `[{"value": 名前}, …]` の形にする（付随の文字・欄は入れない）
- コントローラ: 行ごとに `BuyerCsvRow::from()` の誤りと `fromCsv()` の誤りを合わせ、どちらかがあれば `implode('／', …)` で 1 件の行のエラーにする。主語は `Q{n}（{label}）` で、n は見出しの番号。通った行は回答（設問 id => answer_value）を `validRows` に持ち、確定のときはそれを書く。回答が 1 つも無い行はアンケートを作らない。確定でも同じ検査をやり直す（今どおり）

- [ ] **Step 1: 失敗するテストを書く**

```php
// Unit
test_csv_cells_are_read_like_the_screen
    // 'A、B'・'A,B'・' A , B '（全角空白も） → '["A","B"]'／付随 'チラシ、ご紹介' → '[{"value":"チラシ"},{"value":"ご紹介"}]'
    // 条件 '建売住宅の購入' → '{"value":"建売住宅の購入"}'／スライダー '３，５００' → '3500'
    // 選択肢 'A,B' がある複数のセル 'A,B' → '["A,B"]'
test_csv_errors_name_the_column   // 単一 '社宅' → 'Q3（家族の人数）の「社宅」は選択肢にありません'
// Feature（CustomerImportTest）
test_survey_columns_are_checked_in_the_preview_and_stored_in_the_saved_form
    // 単一・複数・スライダーの列。誤りの行は「⚠ 行N: 姓が未入力です／Q1（…）の「社宅」は選択肢にありません」の形
    // 通る行は確定で '["A","B"]'・'3500' のように保存される（viewData('rowErrors') と画面の表示を別々に見る。Bug #54 ④）
```

  あわせて、既存の `test_a_survey_and_its_answers_are_imported_with_the_row`（テキスト '看板'）は変えずに緑のままであることを見る
- [ ] **Step 2: 流して赤を確かめる**
- [ ] **Step 3: `fromCsv()` を足し、コントローラを直す**
- [ ] **Step 4: 流して緑を確かめる**: `tests/Feature/Admin/CustomerImportTest.php`・`tests/Unit/Support`
- [ ] **Step 5: コミット**（`fix: 顧客の CSV 取込のアンケートの列を画面と同じ形で検査して保存する`）

---

## Task 6: 全件テスト・ビューの lint・変異テスト

- [ ] **Step 1: 全件を流す**。`OK` になることと、メモリのピーク（前回 452.50 MB・`phpunit.xml` の上限は 1G）を記録する
- [ ] **Step 2: コンパイル済みビューを lint する**: `php artisan view:cache` のあと、`storage/framework/views/*.php` を全部 `php -l` して INVALID 0 件（前回 312 本）。終わったら `view:clear` する（VIEW_COMPILED_PATH を scratchpad に向けてもよい）
- [ ] **Step 3: 変異の表を `specs.py` に書く**（流す前に期待を書く）。写しを `specs.survey-answer.py` に置く。各変異の `tests` は `tests/Unit/Support/SurveyAnswersTest.php`・`tests/Feature/RealEstate/Screens`・`tests/Feature/Housing/Screens/CustomerScreensTest.php`・`tests/Feature/Admin/CustomerImportTest.php`・`tests/Feature/JsEchoClassificationTest.php`・`tests/Feature/JsStringEchoScanTest.php`・`tests/Feature/JapaneseValidationMessagesTest.php`
- [ ] **Step 4: 流す**。期待と違ったものは、理由の文言まで見てから扱いを決める（テストの穴なら、テストを足して赤を確かめる。等価なら表に理由を書く）。結果を計画の「実測記録 Task 6」に書いてコミットする

**変異の表（期待は「落ちるテスト: 理由の 1 行目の一部」。実装でテストの失敗文が決まったら、流す前に合わせる）:**

| id | 変異 | 期待 |
|---|---|---|
| K00 | カナリア: 部品の `function sliderComponent{{ $qId }}(` を `sliderComponentX` に | SurveyScreensTest の往復の各本: 「を定義する <script> がちょうど 1 つ描かれていない」 |
| S01 | `decode()` が単一・テキスト・数値・スライダーも JSON として読む（元の形） | `test_decode_reads_back_each_type`・`test_every_type_survives_…` |
| S02 | 選択肢の検査で、保存済みの値を通さない | `test_a_saved_value_passes_…`・`test_a_renamed_option_…` |
| S03 | スライダーの刻みを見ない | `test_numbers_and_sliders_…` |
| S04 | スライダーの下限を `<` から `<=`（境目の 2000 を断る） | `test_numbers_and_sliders_…` |
| S05 | テキストの上限 255 → 256 | `test_text_is_limited_to_255_characters` |
| S06 | 付随テキストで has_text でない選択肢の文字も残す | `test_select_with_text_keeps_text_…` |
| S07 | 条件分岐で、ほかの選択肢のキーも受け取る | `test_conditional_select_keeps_only_…` |
| S08 | 付随の欄の数の上限 99999 を外す | `test_conditional_select_keeps_only_…` |
| S09 | 複数選択の重なりを残す | `test_multi_select_keeps_the_first_of_duplicates` |
| S10 | 範囲の外の数値で、保存済みの値を通さない | `test_a_saved_value_passes_…` |
| C01 | update() が回答を全部消す（元の形） | `test_answers_to_questions_not_on_the_screen_are_kept_on_save` |
| C02 | store() の `after()` を外す | `test_values_outside_the_options_are_refused_…` |
| C03 | update() で保存済みの回答を渡さない（`[]`） | `test_a_renamed_option_…`・`test_a_saved_slider_outside_…` |
| C04 | アンケートの検査を、今の項目の `validate()` が通ったあとに別にする | `test_survey_errors_come_with_the_other_errors_at_once` |
| C05 | 顧客の新規登録で、答えが無くてもアンケートを作る | `test_a_new_customer_without_answers_gets_no_survey` |
| C06 | `validateBuyer()` に設問を渡さない | `test_a_new_customer_survey_is_checked_…` |
| B01 | スライダーの hidden から `:disabled="skip"` を外す | `test_a_new_survey_does_not_store_the_slider_…`・`test_choosing_not_to_answer_…` |
| B02 | 登録画面のスライダーを最初から答えた状態にする（`skip` の初期値を false） | `test_a_new_survey_does_not_store_the_slider_…` |
| B03 | 範囲の入力欄に `name="survey[{{ $qId }}]"` を戻す | `test_the_range_input_sends_nothing_itself` |
| B04 | 条件分岐の付随の欄から `:disabled` を外す（元の形） | `test_conditional_fields_keep_their_values_…` |
| B05 | `session()->hasOldInput()` を見ない（元の形） | `test_answers_come_back_after_an_input_error` |
| B06 | `formState()` が選択肢に無い値を足さない | `test_form_state_marks_values_outside_…`・`test_a_renamed_option_…` |
| B07 | 今の欄に無い保存済みのキーを描かない | `test_form_state_shows_saved_fields_…` |
| B09 | 条件分岐のラジオから `x-model="selectedOption"` を外す | `test_conditional_fields_keep_their_values_…`（選んだ値が送られない） |
| B08 | スライダーの初期値を `Js::from` でなく `'{{ … }}'` で書く | `JsEchoClassificationTest`（分類していない値）・`JsStringEchoScanTest` は対象外なので緑の見込み |
| V01 | CSV がセルをそのまま保存する（元の形） | `test_survey_columns_are_checked_…` |
| V02 | CSV の区切りを「,」だけにする | `test_csv_cells_are_read_like_the_screen` |
| V03 | CSV でセル全体が選択肢の名前のときも区切る | `test_csv_cells_are_read_like_the_screen` |
| V04 | CSV の誤りを行のエラーに足さない | `test_survey_columns_are_checked_…` |
| V05 | CSV の全角数字を半角にしない | `test_csv_cells_are_read_like_the_screen` |
| Q01 | 等価の見込み: `checkScreen()` が `$submitted` の配列でない値を `[]` にする代わりに `(array)` にする | 緑（理由を表に書く） |

---

## Task 7: 実ブラウザで確かめる（設計 11 章）

Task 0 Step 3 の環境を、直したコードで起動し直す（同じ写しの DB・`survey-env.php`）。Playwright で、画面を見えている状態にして測る。

- [ ] **Step 1: 条件分岐付き選択**: 「建売住宅の購入」を選び、市・エリア・坪数を入れて保存すると、3 つとも残る。ほかの選択肢へ切り替えて戻しても、選んだ選択肢の欄だけが送られる（直す前は Task 0 で消えていた）
- [ ] **Step 2: スライダー**: 登録画面では「回答しない」にチェックが付いていて、値の表示は「—」。スライダーを動かすとチェックが外れる。チェックを付けたまま保存すると、スライダーの行はできない。次に、スライダーを 2500 にして保存したあと、sqlite3 で設定を 3000〜6000 に変え、その編集画面を開く（範囲の入力欄は 3000 に丸まって見える）。触らずに保存しても 2500 のまま
- [ ] **Step 3: 「（選択肢に無い値）」**: sqlite3 で単一選択の選択肢の名前を変え、編集画面に「旧名（選択肢に無い値）」が選ばれて出て、保存しても変わらないこと
- [ ] **Step 4: 入力エラーで戻る**: 来場日を空にして送ると、入れた回答が残っている
- [ ] **Step 5: 375px**: アンケートの登録・編集と顧客の新規登録の 3 画面で、`main` の横スクロールが 0。値の欄が枠から切れていない
- [ ] **Step 6: コンソールのエラーが 0**。結果を計画の「実測記録 Task 7」に書いてコミットする。使い捨てのログイン用ルートは外し、作業ツリーを空に戻す

---

## Task 8: 記録

- [ ] **Step 1: 最新の `13.x` で RULES の番号を数え直す**: `git -C /Users/masanori/site/manage log -1 13.x` と `git show 13.x:docs/RULES.md | grep -c '^| 1[0-9][0-9] |'`。ほかの会話が #120 を使っていれば、次の空き番号にする
- [ ] **Step 2: `docs/RULES.md` に Bug #120 を足す**: 症状（設計 1.1 の表と本番の問 6 の 5 件）・原因（`json_decode()` の読み戻し・全部消して作り直す・検査なし・同じ名前の隠れた欄・`filled()` の配列・`old()` を読まない）・直し方（`SurveyAnswers` の 1 か所・`after()` で一度に・`formState()`・`:disabled`・スライダーの hidden と「回答しない」）・回帰テスト・変異の結果・範囲外（設計 12 章）
- [ ] **Step 3: `docs/BACKLOG.md` に節を足す**: 「✅ アンケートの回答の検査と編集画面での読み戻し — 本番未反映」。表の形はこれまでの節にそろえる。本番反映には `composer dump-autoload` が要ることも書く
- [ ] **Step 4: `CLAUDE.md`**: 「全 119 件」「Bug #1–119」を新しい番号に直す
- [ ] **Step 5: コミット**（`docs: アンケートの回答の検査と読み戻しを記録する`）

`13.x` への早送り・push・本番への反映は、利用者の指示があったときだけ行う（この計画には含めない）。

---

## 実行方法（推薦）

**Subagent-driven を推薦する。** Task 1〜5 は 1 つのクラスの interface を順に使い、部品は Alpine の状態の名前をテストと共有するので、Task ごとに独立の点検をはさむ価値が高い。本番の回答を消すと取り返しがつかない。
Task 0（直す前の実測と実ブラウザの再現）・Task 6 の変異・Task 7 の実ブラウザは、判断が要るのでこの会話で行う。

## 実測記録

### Task 0（2026-10-27・直す前のコード `ff499114`）

使い捨てのテスト（`Tests\Probe\SurveyProbeTest`・PHP 8.3.35・scratchpad に置きコミットしない）で、描いた画面のフォームを組んで送った。6 つとも設計 1.2 章の見込みどおりだった。

| # | 測ったこと | 結果 |
|---|---|---|
| P1 | 無効にした設問の回答が残る編集画面を、そのまま保存 | 無効の設問の回答の行が消えた（有効な設問の `3LDK` だけ残る） |
| P2 | 単一選択 `A` を保存し、選択肢を `A2`・`B` に変えて編集画面をそのまま保存 | 編集画面のフォームに単一選択の項目が無く（どのラジオも選ばれていない）、保存で行が消えた |
| P3 | スライダーを触らずにアンケートの登録画面から保存 | `4000`（2000〜6000 の真ん中）の行ができた |
| P4 | 選択肢＋付随テキスト・条件分岐・スライダーの部署で、何も答えずに顧客を新規登録 | アンケートが 1 件でき、行は `[]`・`{"sub":{"city":null,"area":null,"tsubo":null,"land_tsubo":null}}`・`4000` |
| P5 | 付随の欄のキーが重なる条件分岐で 1 つ目の選択肢を選び、欄を埋めて保存 | 画面に `sub[city]` の欄が 3 つ・`area`/`tsubo`/`land_tsubo` が 2 つずつ、`:disabled` は 0。保存は `{"value":"建売住宅の購入","sub":{"city":null,"area":null,"tsubo":null,"land_tsubo":null}}` |
| P6 | 来場日を空にし、テキストに `4LDK` を入れて送る | 戻った画面に「来場日は必須です」が出て、テキストの欄は `value=""` |

- ⚠ 空の付随の欄は `ConvertEmptyStringsToNull` で null になって保存される（設計 1.3 の「付随の欄がすべて空の形」はこの形の可能性が高い）
- ⚠ **テストの道具の欠け**: P4 を `composedForm()` に部品を 2 つ渡して組んだら、条件分岐の欄が送られなかった。`$nestedFields += …` が、同じ一番上のキー（`survey`）の 2 つ目を捨てていた。画面ではなく道具の欠けなので、Task 2 Step 0 で直す。部品ごとに組んで深く合わせ直すと、P4 は上の表のとおりだった（スライダーの行は、1 回目の測り方で `4000` を確かめた。2 回目は平らな名前の空の欄と重なって抜けた＝これも道具の都合）

**実ブラウザ（P5 の再現）**: scratchpad に写した `demo.sqlite` に住宅事業の 6 問（本番と同じ構成。問 2 は付随の欄のキーが重なる形）を入れ、使い捨てのサーバ（127.0.0.1:8031）と Playwright で確かめた。

| 操作 | 保存された問 2 |
|---|---|
| 「建売住宅の購入」を押し、見えている欄に 松山市・道後・40 を入れて登録 | `{"value":"建売住宅の購入","sub":{"city":null,"area":null,"tsubo":null,"land_tsubo":null}}` |
| 「土地を購入して新築」を押し、見えている欄に 伊予市・60 を入れて登録 | `{"value":"土地を購入して新築","sub":{"city":null,"area":null,"tsubo":null,"land_tsubo":null}}` |

- 「建売住宅の購入」を押した直後の画面: `sub` の欄は 9 個で、見えているのは最初の 3 個。どれも `disabled` ではない（隠れた 6 個も送られ、後ろの空の欄が残る）
- 2 回とも、触っていない問 1 は `[]`、問 6（スライダー）は `4000` で保存された（P3・P4 と同じ）
- 終わったあと、サーバを止め、ログイン用ルートを外し、Playwright の写し（`.playwright-mcp/page-2026-10-27T03-0*.yml`）を消した

### Task 6（2026-10-27・`0d0483bb`）

- 全件: **OK (4430 tests, 43388 assertions)**（PHP 8.3.35・4 分 56 秒・メモリのピーク 458.50 MB。上限は 1G）
- コンパイル済みビュー: **312 本 / INVALID 0**
- 変異: 表は `/Users/masanori/site/manage/.claude/route-coverage-tools/mutate/specs.survey-answer.py`（`specs.py` と同じ）。期待は流す前に、実際のテストを読んで書いた（計画の表は実装の前の見込みなので、落ちるテストの組を読み直して広げた行がある）。**35 通りすべて期待どおり**（落ちたテストの組と、理由の 1 行目まで一致）。作業ツリーは毎回空に戻った

| id | 変異 | 落ちた本数 |
|---|---|---|
| K00 | カナリア: スライダーの部品の関数の名前を変える | 16（部品を組む 16 本） |
| S01 | `decode()` が単一・テキスト・数値・スライダーも JSON として読む（元の形） | 2 |
| S02 | 単一選択で保存済みの値を通さない | 4 |
| S03 | スライダーの刻みを見ない | 6 |
| S04 | スライダーの下限ちょうどを断る（コードは受け付ける側が `>=` なので `>` にした） | 2 |
| S05 | テキストの上限 255 → 256 | 3 |
| S06 | has_text でない選択肢の文字も残す | 1 |
| S07 | 条件分岐でほかの選択肢のキーも受け取る | 2 |
| S08 | 付随の欄の数の上限 99999 を外す | 1 |
| S09 | 複数選択の重なりを残す | 2 |
| S10 | 数値で保存済みと同じ値を通さない | 2 |
| C01 | update() が回答を全部消す（元の形） | 1 |
| C02 | アンケートの store() の after() を外す | 6 |
| C03 | update() に保存済みの回答を渡さない | 3 |
| C04 | 今の項目に誤りがあればアンケートを検査しない（一度に出ない） | 1 |
| C05 | 答えが無くても顧客の新規登録でアンケートを作る | 1 |
| C06 | validateBuyer() に設問を渡さない | 5 |
| B01 | スライダーの hidden から `:disabled="skip"` を外す | 10 |
| B02 | スライダーを最初から答えた状態にする | 8 |
| B03 | 範囲の入力欄に `name` を戻す（元の形） | 10 |
| B04 | 条件分岐の付随の欄から `:disabled` を外す（元の形） | 3 |
| B05 | `session()->hasOldInput()` を見ない（元の形） | 1 |
| B06 | 「（選択肢に無い値）」を足さない | 3 |
| B07 | 今の欄に無い保存済みのキーを描かない | 2 |
| B08 | スライダーの初期値を `'{{ … }}'` で書く | 1（`JsEchoClassificationTest`） |
| B09 | 条件分岐のラジオから `x-model` を外す | 1（`SurveyAnswerJsTest` の構造のアサートだけ。Feature の往復はラジオの値を項目に直接書くので緑） |
| V01 | CSV がセルをそのまま保存する（元の形） | 3 |
| V02 | CSV の区切りを「,」だけにする | 8 |
| V03 | セル全体が選択肢の名前でも区切る | 3 |
| V04 | CSV のアンケートの列の誤りで行を止めない | 3 |
| V05 | CSV の全角数字を半角にしない | 4 |
| Q01 | `checkScreen()` が配列でない `survey` を断らず `(array)` にする | 1（計画の「等価の見込み」は外れた。Task 1 で、配列でない `survey` を断ることにしたため） |
| X01 | （足した）アンケートの update() の after() を外す | 7 |
| X02 | （足した）CSV の確定でアンケートの列を検査し直さない | 3 |
| X03 | （足した）条件分岐で、保存済みの付随の欄のキーを別の選択肢でも受け取る | 1 |

- ⚠ B09 を止めるのは構造のアサート 1 つだけ。ラジオの選択が送られることそのものは、Task 7 の実ブラウザで見る
- ⚠ 範囲の外の保存済みスライダー（`test_a_saved_slider_outside_the_current_range_is_kept`）は、直す前から緑だった（テストの道具は範囲の入力欄の値を丸めない）。B03 は「範囲の入力欄が `name` を持たない」構造のテストが止める。ブラウザでの丸めは Task 7 で見る
