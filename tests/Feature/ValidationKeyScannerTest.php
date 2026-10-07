<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Tests\Concerns\CollectsValidationKeys;

/**
 * 入力チェックのキーの集め方（CollectsValidationKeys）の見本。
 *
 * ⚠ 分かれ目ごとに見本を置く。実物のコードに出てこない書き方の分かれ目は、消しても走査の本番（JapaneseValidationMessagesTest）が
 *   緑のまま通る（Bug #61 ③）。「読めない」と返す見本も置く（推測で通していないことを固定する）。
 */
class ValidationKeyScannerTest extends TestCase
{
    use CollectsValidationKeys;

    private const PATH = 'app/Http/Controllers/SampleController.php';

    /** クラスの中身だけを書いて走査する */
    private function scan(string $body, array $more = []): array
    {
        $sources = [self::PATH => "<?php\nnamespace App\\Http\\Controllers;\nclass SampleController\n{\n{$body}\n}\n"] + $more;

        return $this->collectValidationKeys($sources);
    }

    /** 見つかった呼び出しを [キー, 名前] の並びにする（場所は見ない） */
    private function sites(array $found): array
    {
        return array_map(fn ($site) => [$site['keys'], $site['named']], $found['sites']);
    }

    private function assertSites(array $expected, array $found): void
    {
        $this->assertSame([], $found['unreadable'], '読めないものがある: ' . implode(' / ', $found['unreadable']));
        $this->assertSame($expected, $this->sites($found));
    }

    private function assertUnreadable(string $reason, array $found): void
    {
        $this->assertSame([], $found['sites'], '読めないはずの呼び出しからキーを集めた');
        $this->assertCount(1, $found['unreadable']);
        $this->assertStringContainsString($reason, $found['unreadable'][0]);
    }

    public function test_a_literal_array_and_its_third_argument_names(): void
    {
        $this->assertSites([[['a', 'b.*'], []], [['c'], ['c']]], $this->scan('
            public function store($request) {
                $request->validate([\'a\' => \'required\', \'b.*\' => [\'string\', \'max:5\']]);
                $request->validate([\'c\' => \'x\'], [\'c.required\' => \'…\'], [\'c\' => \'名\']);
            }'));
    }

    public function test_validator_make_reads_the_second_and_fourth_arguments(): void
    {
        $this->assertSites([[['d'], ['d']], [['e'], []], [['f'], []]], $this->scan('
            public function store($request) {
                Validator::make($request->all(), [\'d\' => \'x\'], [], [\'d\' => \'名\'])->validate();
                \Illuminate\Support\Facades\Validator::make($data, [\'e\' => \'x\']);
                Validator::validate($data, [\'f\' => \'x\']);
            }'));
    }

    public function test_validate_with_bag_reads_the_second_argument(): void
    {
        $this->assertSites([[['g'], ['g']]], $this->scan('
            public function store($request) {
                $request->validateWithBag(\'bag\', [\'g\' => \'x\'], [], [\'g\' => \'名\']);
            }'));
    }

    public function test_a_variable_built_up_in_the_method(): void
    {
        $this->assertSites([[['h', 'i', 'j'], []]], $this->scan('
            public function store($request) {
                $rules = [\'h\' => \'x\'];
                if ($request->boolean(\'more\')) {
                    $rules[\'i\'] = \'y\';
                }
                $rules += [\'j\' => \'z\'];
                $request->validate($rules);
            }'));
    }

    public function test_a_variable_merged_with_itself(): void
    {
        $this->assertSites([[['k', 'l'], []]], $this->scan('
            public function store($request) {
                $rules = [\'k\' => \'x\'];
                $rules = array_merge($rules, [\'l\' => \'y\']);
                $request->validate($rules);
            }'));
    }

    public function test_a_method_and_its_returns_but_not_a_closure_inside(): void
    {
        $this->assertSites([[['m', 'n', 'o'], ['m']]], $this->scan('
            public function store($request) {
                $request->validate($this->rules(), [], $this->names());
            }
            private function rules(): array {
                $r = [\'m\' => Rule::unique(\'t\')->where(function ($q) { return [\'nope\' => 1]; })];
                $r[\'n\'] = \'x\';
                if (true) { return array_merge($r, [\'o\' => \'x\']); }
                return $r;
            }
            private function names(): array { return [\'m\' => \'名\']; }'));
    }

    public function test_class_constants_and_a_spread(): void
    {
        $this->assertSites([[['p', 'q'], []], [['r'], []]], $this->scan('
            private const BASE = [\'p\' => \'x\'];
            public const array MORE = [\'r\' => \'x\'];
            public function store($request) {
                $request->validate([...self::BASE, \'q\' => \'x\']);
                $request->validate((static::MORE));
            }'));
    }

    public function test_a_method_from_a_trait(): void
    {
        $trait = "<?php\nnamespace App\\Http\\Controllers\\Concerns;\ntrait HasRules\n{\n    private function traitRules(): array { return ['s' => 'x']; }\n}\n";
        $this->assertSites([[['s'], []]], $this->scan('
            use Concerns\HasRules;
            public function store($request) {
                $request->validate($this->traitRules());
            }', ['app/Http/Controllers/Concerns/HasRules.php' => $trait]));
    }

    public function test_a_forwarding_helper_is_read_at_its_callers(): void
    {
        // ⚠ 呼び出す側を先に書く — 部品をさらに渡すだけの部品（outer）は 1 回目には覚えておらず、2 回目に読む（回し続ける仕組みの見本）
        $found = $this->scan('
            public function store($request) {
                $this->check($request, [\'t\' => \'x\'], [\'t\' => \'名\']);
                $this->outer($request, [\'u\' => \'x\']);
            }
            private function outer($request, array $rules) {
                return $this->check($request, $rules);
            }
            private function check($request, array $rules, array $names = []) {
                return $request->validate($rules, [], $names);
            }');
        $this->assertSites([[['t'], ['t']], [['u'], []]], $found);
        // 場所は呼び出し元（部品の中の validate の行ではない）
        $this->assertStringEndsWith(':7', $found['sites'][0]['where']);
    }

    public function test_a_form_request_reads_rules_and_attributes(): void
    {
        $request = "<?php\nnamespace App\\Http\\Requests;\nuse Illuminate\\Foundation\\Http\\FormRequest;\nclass SampleRequest extends FormRequest\n{\n    public function rules(): array { return ['v' => 'x']; }\n    public function attributes(): array { return ['v' => '名']; }\n}\n";
        $found = $this->collectValidationKeys(['app/Http/Requests/SampleRequest.php' => $request]);
        $this->assertSites([[['v'], ['v']]], $found);
    }

    public function test_classes_with_the_same_short_name_are_kept_apart(): void
    {
        $other = "<?php\nnamespace App\\Http\\Controllers\\Other;\nclass SampleController\n{\n    public function store(\$request) { \$request->validate(['w' => 'x']); }\n}\n";
        $found = $this->scan('public function store($request) { $request->validate([\'x\' => \'y\']); }', ['app/Http/Controllers/Other/SampleController.php' => $other]);
        $this->assertSites([[['x'], []], [['w'], []]], $found);
    }

    public function test_brackets_inside_strings_attributes_and_class_constants_do_not_confuse_it(): void
    {
        $this->assertSites([[['y'], []]], $this->scan('
            #[\Deprecated]
            public function store($request) {
                $sql = "({$expression} IS NULL) [";
                $name = Foo::class;
                $request->validate([\'y\' => \'x\']);
            }'));
    }

    public function test_validate_without_arguments_is_not_a_site(): void
    {
        $this->assertSites([], $this->scan('
            public function store($validator) { $validator->validate(); }'));
    }

    // ---------- 読めないものは推測せずに返す ----------

    public function test_a_key_that_is_not_a_string_literal_is_unreadable(): void
    {
        $this->assertUnreadable('文字列でないキー', $this->scan('
            public function store($request, $i) { $request->validate([\'a.\' . $i => \'x\']); }'));
        $this->assertUnreadable('文字列でないキー', $this->scan('
            public function store($request) { $request->validate([0 => \'x\']); }'));
    }

    public function test_an_element_without_a_key_is_unreadable(): void
    {
        $this->assertUnreadable('キーの無い要素', $this->scan('
            public function store($request) { $request->validate([\'required\', \'string\']); }'));
    }

    public function test_assigning_with_a_dynamic_key_is_unreadable(): void
    {
        $this->assertUnreadable('文字列でないキーで代入', $this->scan('
            public function store($request) { $rules = []; foreach ([1] as $k) { $rules[$k] = \'x\'; } $request->validate($rules); }'));
    }

    public function test_an_expression_it_cannot_follow_is_unreadable(): void
    {
        $this->assertUnreadable('たどれない式', $this->scan('
            public function store($request) { $request->validate(Rules::forContract()); }'));
    }

    public function test_a_variable_without_an_assignment_is_unreadable(): void
    {
        $this->assertUnreadable('の代入が見つからない', $this->scan('
            public function store($request) { $request->validate($rules); }'));
    }

    public function test_a_missing_method_constant_or_return_is_unreadable(): void
    {
        $this->assertUnreadable('メソッドが見つからない', $this->scan('
            public function store($request) { $request->validate($this->nope()); }'));
        $this->assertUnreadable('定数が見つからない', $this->scan('
            public function store($request) { $request->validate(self::NOPE); }'));
        $this->assertUnreadable('return が無い', $this->scan('
            public function store($request) { $request->validate($this->rules()); }
            private function rules(): array { $x = [\'a\' => 1]; }'));
    }

    public function test_unreadable_names_make_the_call_unreadable(): void
    {
        $this->assertUnreadable('メソッドが見つからない', $this->scan('
            public function store($request) { $request->validate([\'a\' => \'x\'], [], $this->nope()); }'));
    }

    public function test_a_forwarding_helper_called_with_an_unreadable_argument_is_unreadable(): void
    {
        $this->assertUnreadable('たどれない式', $this->scan('
            private function check($request, array $rules) { return $request->validate($rules); }
            public function store($request) { $this->check($request, Rules::forContract()); }'));
    }

    public function test_a_file_whose_brackets_do_not_balance_is_unreadable_by_name(): void
    {
        $found = $this->collectValidationKeys(['app/Http/Controllers/BrokenController.php' => "<?php\nclass BrokenController\n{\n    public function store(\$request) { \$request->validate(['a' => 'x']);\n"]);
        $this->assertSame([], $found['sites']);
        $this->assertSame(['app/Http/Controllers/BrokenController.php: 括弧の対応が取れない'], $found['unreadable']);
    }

    public function test_a_trait_name_shared_by_two_traits_is_not_guessed(): void
    {
        $one = "<?php\nnamespace A;\ntrait HasRules { private function traitRules(): array { return ['a' => 'x']; } }\n";
        $two = "<?php\nnamespace B;\ntrait HasRules { private function traitRules(): array { return ['b' => 'x']; } }\n";
        $this->assertUnreadable('メソッドが見つからない', $this->scan('
            use \A\HasRules;
            public function store($request) { $request->validate($this->traitRules()); }', ['a/HasRules.php' => $one, 'b/HasRules.php' => $two]));
    }
}
