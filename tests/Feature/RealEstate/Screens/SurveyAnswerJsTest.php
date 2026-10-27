<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\SurveyQuestion;

/**
 * アンケートの「条件分岐付き選択」の画面（`buyers/_survey_form.blade.php`。住宅事業・不動産で共用）が、
 * 設問の選択肢と保存された回答を、画面の JavaScript に元の文字のまま渡す（Bug #76 と同じ形）。
 *
 * ⚠ 選択肢は経営層が設問の管理で入れる文字、回答は DB に保存された値（検査を足す前に保存された値・設問の選択肢を直したあとに残る値）。
 *   どちらも DB に残るので、壊れると**その画面を開く人全員**に効く（手で組んだ送信の本人だけではない）。
 * ⚠ 選択肢は `x-on:change` / `x-show` の属性の中に書く（ブラウザが実体参照を戻すので、`'` で文字列が閉じてコードとして動く）。
 *   回答は `<script>` の中に書く（末尾の `\` で画面の JS が止まる）。
 * ⚠ 回答が文字でない（配列で保存されている）と、`{{ }}` が配列を受け取れず画面ごと 500 だった。
 * ⚠ 画面から保存する値は検査が断る（選択肢に無い値・配列）ので、2 本の編集画面のテストは回答の行を DB に直接入れる。
 */
class SurveyAnswerJsTest extends RealEstateScreenTestCase
{
    /** 属性の中で JS のコードとして動いたら `globalThis.__ran` が立つ選択肢 */
    private const RUNS_CODE = "x' + (globalThis.__ran = 1) + '";

    private const RAN = 'typeof globalThis.__ran';

    /** `'` と末尾の `\` を含む選択肢 */
    private const QUOTED = "O'Neil 店\\";

    private function question(): SurveyQuestion
    {
        return SurveyQuestion::create([
            'department' => 'realestate', 'label' => '希望の地域', 'question_type' => 'conditional_select', 'sort_order' => 1, 'is_active' => true,
            'options' => [
                self::QUOTED,
                ['value' => self::RUNS_CODE, 'sub_fields' => [['key' => 'area', 'label' => '希望エリア', 'type' => 'text']]],
            ],
        ]);
    }

    /**
     * 保存済みの回答（$answerValue をそのまま DB に入れる）がある、そのアンケートの編集画面の HTML を返す。
     * ⚠ 画面から送ると検査が断る値（選択肢に無い値・配列）を、本番に残っている形として DB に直接入れる。
     */
    private function editScreenWith(Buyer $buyer, SurveyQuestion $question, string $answerValue): string
    {
        $survey = BuyerSurvey::create(['buyer_id' => $buyer->id, 'department' => 'realestate', 'survey_date' => '2026-10-01']);
        $survey->answers()->create(['question_id' => $question->id, 'answer_value' => $answerValue, 'question_snapshot' => $question->toSnapshot()]);

        return $this->htmlOf(route('realestate.customers.surveys.edit', [$buyer, $survey]));
    }

    /** 画面の $name 属性の値（実体参照を戻したもの）を描いた順に */
    private function attributes(string $html, string $name): array
    {
        preg_match_all('/\s' . preg_quote($name, '/') . '="([^"]*)"/', $html, $m);

        return array_map(fn ($v) => html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m[1]);
    }

    public function test_choosing_an_option_keeps_its_text_and_does_not_run_it_as_code(): void
    {
        $question = $this->question();
        $html = $this->htmlOf(route('realestate.customers.surveys.create', $this->buyer()));

        $changes = $this->attributes($html, 'x-on:change');
        $shows = array_values(array_filter($this->attributes($html, 'x-show'), fn ($v) => str_contains($v, 'selectedOption')));
        $this->assertCount(2, $changes, '選択肢ごとの x-on:change が描かれていない');
        $this->assertCount(1, $shows, '付随の欄を開く x-show が描かれていない');

        $function = 'condSelect' . $question->id;
        $run = $this->driveAlpine($html, $function, $function . '()', '', [], true, [$changes[0], 'selectedOption', $changes[1], 'selectedOption', $shows[0], self::RAN]);

        $this->assertSame([self::QUOTED, self::QUOTED, self::RUNS_CODE, self::RUNS_CODE, true, 'undefined'], $run['evaluated'],
            '選んだ選択肢の文字が変わるか、選択肢が JS のコードとして動いた');
    }

    public function test_a_saved_answer_reaches_the_edit_screen_as_it_is(): void
    {
        $question = $this->question();
        $saved = "回答'\"&<\\";

        // 選択肢に無い保存済みの値（設問の管理で選択肢を直したあとに残る形）
        $html = $this->editScreenWith($this->buyer(), $question, json_encode(['value' => $saved], JSON_UNESCAPED_UNICODE));

        $function = 'condSelect' . $question->id;
        $run = $this->driveAlpine($html, $function, $function . '()', '', [], true, ['selectedOption']);
        $this->assertSame([$saved], $run['evaluated'], '保存された回答が画面の JS に元の文字のまま渡らないか、画面の JS が止まった');
    }

    public function test_an_answer_saved_as_an_array_does_not_break_the_edit_screen(): void
    {
        $question = $this->question();

        // 回答と付随の欄が配列の形で保存されている（今の画面からは送れない形）
        $html = $this->editScreenWith($this->buyer(), $question, '{"value":["a"],"sub":{"area":["b"]}}');

        $function = 'condSelect' . $question->id;
        $run = $this->driveAlpine($html, $function, $function . '()', '', [], true, ['selectedOption']);
        $this->assertSame([''], $run['evaluated'], '文字でない回答は空として描く');
        $this->assertSame(1, preg_match('/<input\b[^>]*name="survey\[' . $question->id . '\]\[sub\]\[area\]"[^>]*value=""/', $html), '付随の欄の文字でない値は空として描く');
    }
}
