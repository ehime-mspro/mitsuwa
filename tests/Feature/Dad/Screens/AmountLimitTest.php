<?php

namespace Tests\Feature\Dad\Screens;

use App\Models\DadProject;

/**
 * DAD の金額の入力は本番の列（INT UNSIGNED。2026-10-07 に読み取りで確認）に入る値まで（A3。Bug #73 の形）。
 * テストの SQLite は列の大きさを見ないので、入力チェックで止まっていることを「行が作られない」で見る。
 */
class AmountLimitTest extends DadScreenTestCase
{
    private function tooLarge(string $attribute): string
    {
        return trans('validation.max.numeric', ['attribute' => $attribute, 'max' => self::UINT_MAX]);
    }

    private function form(string $costJs): array
    {
        return $this->projectForm($this->htmlOf(route('dad.projects.create')), route('dad.projects.store'), 'data.addCostRow(); var r = data.costRows[0]; r.cost_category = "material"; ' . $costJs);
    }

    public function test_amounts_up_to_the_column_limit_are_saved(): void
    {
        $max = (string) self::UINT_MAX;
        $form = $this->fill($this->form("r.estimated_amount = '{$max}'; r.actual_amount = '{$max}';"), ['project_name' => '工事A', 'estimate_amount' => $max, 'contract_amount' => $max]);

        $this->assertFlash($this->landed($this->submit($form, route('dad.projects.create'))), 'success', '工事案件「工事A」を登録しました。');
        $project = DadProject::sole();
        $cost = $project->costs()->sole();
        $this->assertSame([self::UINT_MAX, self::UINT_MAX, self::UINT_MAX, self::UINT_MAX], [$project->estimate_amount, $project->contract_amount, $cost->estimated_amount, $cost->actual_amount]);
    }

    public function test_amounts_over_the_column_limit_are_refused(): void
    {
        $over = (string) (self::UINT_MAX + 1);
        $form = $this->fill($this->form("r.estimated_amount = '{$over}'; r.actual_amount = '{$over}';"), ['project_name' => '工事A', 'estimate_amount' => $over, 'contract_amount' => $over]);

        $html = $this->landed($this->submit($form, route('dad.projects.create')));

        $this->assertErrorItem($html, $this->tooLarge('見積金額'));
        $this->assertErrorItem($html, $this->tooLarge('受注金額'));
        $this->assertErrorItem($html, $this->tooLarge('原価明細 1 行目の見積額'));
        $this->assertErrorItem($html, $this->tooLarge('原価明細 1 行目の実績額'));
        $this->assertSame(0, DadProject::count());
    }

    // ============================================================
    // 走査: DAD のコントローラの整数・数値の入力にはどれも上限がある（全件。Top trap #13）
    // ============================================================

    public function test_every_integer_and_numeric_rule_in_the_dad_controllers_has_an_upper_bound(): void
    {
        $files = glob(app_path('Http/Controllers/Dad/*.php'));
        $this->assertGreaterThanOrEqual(4, count($files), 'DAD のコントローラを拾えていない');
        $bounded = fn (string $rule) => (bool) preg_match('/(^|\|)(max:|between:|exists:|in:)/', $rule);
        $checked = 0;
        $unbounded = [];
        foreach ($files as $file) {
            $tokens = token_get_all(file_get_contents($file));
            foreach ($tokens as $i => $token) {
                if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $rule = substr($token[1], 1, -1);
                if (! preg_match('/(^|\|)(integer|numeric)(\||$)/', $rule)) {
                    continue;
                }
                $checked++;
                $ok = $bounded($rule);
                // 配列で書いた規則（`['integer', Rule::in(…)]`）は、同じ配列の残りの要素に上限（max・between・exists・in の文字列か Rule::exists / Rule::in）があるかを見る。
                // ⚠ `|` でつないだ規則の文字列は自分の中だけで見る（後ろを見ると、次の項目の上限に当たって素通りする）
                for ($j = $i + 1, $depth = 0; ! $ok && ! str_contains($rule, '|') && $j < count($tokens); $j++) {
                    $t = $tokens[$j];
                    if ($t === '[' || $t === '(') {
                        $depth++;
                    } elseif ($t === ']' || $t === ')') {
                        if ($depth-- === 0) {
                            break;
                        }
                    } elseif ($depth === 0 && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $ok = $bounded(substr($t[1], 1, -1));
                    } elseif ($depth === 0 && is_array($t) && $t[0] === T_STRING && in_array($t[1], ['exists', 'in'], true) && ($tokens[$j - 1][0] ?? null) === T_DOUBLE_COLON) {
                        $ok = true;
                    } elseif ($depth === 0 && $t === ';') {
                        break;
                    }
                }
                if (! $ok) {
                    $unbounded[] = basename($file) . ':' . $token[2] . ' ' . $rule;
                }
            }
        }
        $this->assertGreaterThanOrEqual(8, $checked, '整数・数値の入力チェックを拾えていない');
        $this->assertSame([], $unbounded, '上限の無い整数・数値の入力がある（本番の MySQL では列に入らない値で 500）');
    }
}
