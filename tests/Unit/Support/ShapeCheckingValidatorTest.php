<?php

namespace Tests\Unit\Support;

use App\Support\ShapeCheckingValidator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * 入力チェックの「配列の形」の判定（Bug #121）。分かれ目ごとに 1 つ。
 *
 * ⚠ アプリを起動する（和名と文言の置き換えに翻訳を使う）。ここでは resolver を通さず、クラスを直接作る
 */
class ShapeCheckingValidatorTest extends TestCase
{
    private function validator(array $data, array $rules, array $attributes = [], array $messages = []): ShapeCheckingValidator
    {
        return new ShapeCheckingValidator(app('translator'), $data, $rules, $messages, $attributes);
    }

    private function shapeMessage(string $label): string
    {
        return "{$label}の形が正しくありません。画面を開き直して、もう一度入力してください。";
    }

    // ---------- 配列を求めない欄に配列 ----------

    public function test_an_array_for_a_field_whose_rules_let_arrays_through_is_refused(): void
    {
        $v = $this->validator(['label' => ['a']], ['label' => 'required|max:255']);

        $this->assertTrue($v->fails());
        $this->assertSame(['label' => [$this->shapeMessage('設問文')]], $v->errors()->messages());
    }

    public function test_the_message_uses_the_attribute_name_given_by_the_caller(): void
    {
        $v = $this->validator(['unit_id' => ['1']], ['unit_id' => ['nullable']], ['unit_id' => '希望区画']);

        $this->assertSame([$this->shapeMessage('希望区画')], $v->errors()->get('unit_id'));
    }

    public function test_a_position_in_the_attribute_name_is_filled_in(): void
    {
        $v = $this->validator(
            ['costs' => [['item' => ['3']]]],
            ['costs' => 'array', 'costs.*.item' => 'required'],
            ['costs.*.item' => '原価明細 :position 行目の項目'],
        );

        $this->assertSame(['costs.0.item' => [$this->shapeMessage('原価明細 1 行目の項目')]], $v->errors()->messages());
    }

    public function test_a_field_added_by_sometimes_is_also_seen(): void
    {
        $v = $this->validator(['label' => ['a']], []);
        $v->sometimes('label', 'required', fn () => true);

        $this->assertSame([$this->shapeMessage('設問文')], $v->errors()->get('label'));
    }

    /** 型の規則がある欄は、その規則の今の文のまま（決裁の付け替え・ログインの既存のテストの文言を変えない） */
    public static function typeRules(): array
    {
        return [
            'string'      => ['required|string', ['a'], 'は文字列で'],
            'integer'     => ['required|integer', ['1'], 'は整数で'],
            'numeric'     => ['nullable|numeric', ['1'], 'は数値で'],
            'decimal'     => ['nullable|decimal:0,2', ['1'], 'は小数第'],
            'boolean'     => ['nullable|boolean', ['1'], 'はtrueかfalse'],
            'date'        => ['nullable|date', ['2026-01-01'], 'は正しい日付'],
            'date_format' => ['nullable|date_format:Y-m', ['2026-01'], 'は「Y-m」の形式'],
            'email'       => ['nullable|email', ['a@example.com'], 'は正しいメールアドレス'],
            'url'         => ['nullable|url', ['https://example.com'], 'は正しいURL'],
            'in'          => ['nullable|in:a,b', ['a'], 'は正しくありません'],
            'regex'       => ['nullable|regex:/\A[A-Z]+\z/', ['A'], 'の形式が正しくありません'],
            'array'       => ['nullable|array', 'x', 'は配列で'],
            'list'        => ['nullable|list', 'x', 'はリスト'],
        ];
    }

    #[DataProvider('typeRules')]
    public function test_a_type_rule_keeps_its_own_message(string $rule, mixed $value, string $expected): void
    {
        $v = $this->validator(['label' => $value], ['label' => $rule]);

        $this->assertTrue($v->fails());
        $this->assertCount(1, $v->errors()->get('label'));
        $this->assertStringContainsString('設問文' . $expected, $v->errors()->first('label'));
        $this->assertStringNotContainsString('の形が正しくありません', $v->errors()->first('label'));
    }

    // ---------- 形が合わない欄があれば、ほかの規則は見ない ----------

    public function test_no_other_rule_runs_when_a_field_has_the_wrong_shape(): void
    {
        // 自作の規則が request の値を文字のつもりで読むと、配列で落ちる（買主の元号の確かめ・Sheet の URL の確かめ）
        $throws = function (string $attribute, mixed $value, \Closure $fail): void {
            throw new RuntimeException('ほかの規則が動いた');
        };
        $v = $this->validator(
            ['birth_date' => ['1980-01-02'], 'birth_era' => 'S', 'name' => ''],
            ['birth_date' => 'nullable|date', 'birth_era' => ['nullable', $throws], 'name' => 'required'],
        );

        $this->assertTrue($v->fails());
        $this->assertSame(['birth_date'], array_keys($v->errors()->messages()));
    }

    public function test_after_hooks_do_not_run_when_a_field_has_the_wrong_shape(): void
    {
        $v = $this->validator(['label' => ['a']], ['label' => 'required']);
        $v->after(fn () => throw new RuntimeException('after が動いた'));

        $this->assertTrue($v->fails());
    }

    public function test_without_a_wrong_shape_every_rule_and_hook_runs_as_before(): void
    {
        $v = $this->validator(['label' => 'a', 'name' => ''], ['label' => 'required', 'name' => 'required']);
        $v->after(fn ($v) => $v->errors()->add('extra', 'あとの確かめ'));

        $this->assertSame(['name', 'extra'], array_keys($v->errors()->messages()));
    }

    // ---------- 配列を求める欄 ----------

    public static function arrayRules(): array
    {
        return [
            'array'               => ['required|array'],
            'list'                => [['list']],
            'required_array_keys' => ['required_array_keys:a'],
            'Rule::array()'       => [[Rule::array()]],
        ];
    }

    #[DataProvider('arrayRules')]
    public function test_an_array_for_a_field_that_asks_for_an_array_passes(mixed $rule): void
    {
        $value = $rule === ['list'] ? ['1'] : ['a' => '1'];

        $this->assertTrue($this->validator(['ids' => $value], ['ids' => $rule])->passes());
    }

    public function test_a_field_with_rules_below_it_asks_for_an_array(): void
    {
        $this->assertTrue($this->validator(['ids' => ['1', '2']], ['ids' => 'required', 'ids.*' => 'integer'])->passes());
    }

    public function test_a_string_for_a_parent_that_has_only_rules_below_it_is_refused(): void
    {
        $v = $this->validator(['ids' => 'x'], ['ids.*' => 'integer'], ['ids' => '番号']);

        $this->assertSame(['ids' => [$this->shapeMessage('番号')]], $v->errors()->messages());
    }

    public function test_a_string_row_inside_an_array_whose_rows_have_rules_is_refused(): void
    {
        // details.*.amount の規則は、行が文字だと展開されない（初めの規則を見ないと分からない）
        $v = $this->validator(
            ['details' => ['x', ['amount' => '1']]],
            ['details' => 'required|array', 'details.*.amount' => 'required|integer'],
        );

        $this->assertSame(['details.0'], array_keys($v->errors()->messages()));
    }

    // ---------- 見ないもの ----------

    public function test_a_missing_or_empty_value_is_not_seen(): void
    {
        $this->assertTrue($this->validator(['note' => null], ['note' => 'nullable', 'ids.*' => 'integer'])->passes());
    }

    public function test_an_uploaded_file_is_not_an_array(): void
    {
        $file = UploadedFile::fake()->create('a.pdf', 1, 'application/pdf');

        $this->assertTrue($this->validator(['file' => $file], ['file' => 'nullable'])->passes());
    }

    public function test_a_field_with_an_exclude_rule_is_not_seen(): void
    {
        $v = $this->validator(['mode' => 'off', 'x' => ['a']], ['mode' => 'required', 'x' => 'exclude_if:mode,off|required']);

        $this->assertTrue($v->passes());
    }

    public function test_a_key_with_a_literal_dot_is_not_seen(): void
    {
        $this->assertTrue($this->validator(['a.b' => ['x']], ['a\.b' => 'required'])->passes());
    }

    public function test_a_validator_without_rules_adds_nothing(): void
    {
        $this->assertTrue($this->validator(['x' => ['a'], 'y' => 'b'], [])->passes());
    }

    public function test_a_field_without_rules_is_not_seen(): void
    {
        // アンケートの survey のように規則を持たない欄は、今までどおり（after フックなどが見る）
        $this->assertTrue($this->validator(['survey' => 'x', 'name' => 'a'], ['name' => 'required'])->passes());
    }

    // ---------- 断ったときの控え ----------

    public function test_mismatched_input_is_forgotten_from_the_request_both_ways(): void
    {
        $request = Request::create('/x?q=a', 'POST', ['name' => ['a'], 'keep' => 'v', 'ids' => 'x', 'rows' => [['n' => ['1']]]]);
        $v = $this->validator($request->all(), ['name' => 'required', 'keep' => 'required', 'ids.*' => 'integer', 'rows.*.n' => 'required']);

        $v->forgetMismatchedInput($request);

        $this->assertSame(['keep' => 'v', 'rows' => [[]], 'q' => 'a'], $request->input());
    }

    public function test_the_request_is_judged_by_its_own_values(): void
    {
        // CSV の行など request でないデータの入力チェックは、同じ名前の欄を巻き込まない
        $request = Request::create('/x', 'POST', ['name' => 'ok']);
        $v = $this->validator(['name' => ['from', 'csv']], ['name' => 'required']);

        $v->forgetMismatchedInput($request);

        $this->assertSame(['name' => 'ok'], $request->input());
    }

    public function test_a_query_string_value_is_forgotten_too(): void
    {
        $request = Request::create('/x?unit_id[]=1&page=2', 'GET');
        $v = $this->validator($request->all(), ['unit_id' => 'nullable']);

        $v->forgetMismatchedInput($request);

        $this->assertSame(['page' => '2'], $request->query());
    }
}
