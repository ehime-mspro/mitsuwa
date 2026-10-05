<?php

namespace Tests\Feature\Tenant\Screens;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Investment;
use App\Models\Unit;

/**
 * テナントの金額・面積の入力が、本番の列（金額は符号付き INT・希望面積は DECIMAL(8,2)。2026-10-05 に読み取りで確認）に
 * 入らない値を入力エラーで断る（Bug #73 と同じ形）。
 *
 * ⚠ テストの SQLite は INT の範囲を見ないので、上限を外すと「入力エラーにならず保存される」で落ちる。本番の MySQL（strict）
 *   では保存の時点で 500 になる。
 * ⚠ 期待する文言は trans() で組む（項目名は画面ごとの和名。Bug #49）。
 */
class AmountLimitTest extends TenantScreenTestCase
{
    private const INT_MAX = 2147483647;

    private function tooLarge(string $attribute, string $max = '2147483647'): string
    {
        return trans('validation.max.numeric', ['attribute' => $attribute, 'max' => $max]);
    }

    public function test_the_limit_is_the_signed_int_column(): void
    {
        $this->assertSame(self::INT_MAX, (new \ReflectionClassConstant(Controller::class, 'MAX_INT_COLUMN'))->getValue());
    }

    // ============================================================
    // 区画
    // ============================================================

    private function unitStoreForm(): array
    {
        return $this->parseForm($this->htmlOf(route('tenant.units.create', $this->building)), 'action="' . route('tenant.units.store', $this->building) . '"');
    }

    public function test_a_unit_rent_up_to_the_limit_is_accepted(): void
    {
        $form = $this->fill($this->unitStoreForm(), ['floor' => '1', 'room_number' => 'A', 'rent' => (string) self::INT_MAX]);

        $this->landed($this->submit($form, route('tenant.units.create', $this->building)));

        $this->assertSame(self::INT_MAX, Unit::where('display_name', '1A')->firstOrFail()->rent);
    }

    public function test_a_unit_rent_over_the_limit_is_refused(): void
    {
        $form = $this->fill($this->unitStoreForm(), ['floor' => '1', 'room_number' => 'A', 'deposit' => (string) (self::INT_MAX + 1)]);

        $html = $this->landed($this->submit($form, route('tenant.units.create', $this->building)));

        $this->assertInputError($html, $this->tooLarge('敷金'));
        $this->assertFalse(Unit::where('display_name', '1A')->exists());
    }

    public function test_a_unit_deposit_over_the_limit_is_refused_when_editing(): void
    {
        $unit = $this->unit(1, 'A');
        $form = $this->fill($this->parseForm($this->htmlOf(route('tenant.units.edit', $unit)), 'action="' . route('tenant.units.update', $unit) . '"'), ['deposit' => (string) (self::INT_MAX + 1)]);

        $html = $this->landed($this->submit($form, route('tenant.units.edit', $unit)));

        $this->assertInputError($html, $this->tooLarge('敷金'));
    }

    public function test_a_revised_unit_rent_over_the_limit_is_refused(): void
    {
        $unit = $this->unit(1, 'A');
        $url = route('tenant.units.revise', $unit);
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('tenant.units.revise.execute', $unit) . '"'), ['new_rent' => (string) (self::INT_MAX + 1)]);

        $html = $this->landed($this->submit($form, $url));

        $this->assertInputError($html, $this->tooLarge('新・月額家賃'));
        $this->assertSame(80000, $unit->fresh()->rent);
    }

    // ============================================================
    // 契約
    // ============================================================

    public function test_a_contract_rent_over_the_limit_is_refused_when_editing(): void
    {
        $contract = $this->activeContract($this->unit(1, 'A'));
        $url = route('tenant.contracts.edit', $contract);
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('tenant.contracts.update', $contract) . '"', 'contractEditForm', 'data.rent = ' . (self::INT_MAX + 1) . ';');

        $html = $this->landed($this->submit($form, $url));

        $this->assertInputError($html, $this->tooLarge('月額家賃'));
        $this->assertSame(100000, $contract->fresh()->rent);
    }

    public function test_a_manual_final_month_amount_over_the_limit_is_refused(): void
    {
        $contract = $this->activeContract($this->unit(1, 'A'));
        $url = route('tenant.contracts.terminate', $contract);
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('tenant.contracts.terminate.execute', $contract) . '"', 'contractTerminateForm',
            "data.contractEndDate = '2026-11-15'; data.finalMonthType = 'manual'; data.manualFinalAmount = " . (self::INT_MAX + 1) . ';');

        $html = $this->landed($this->submit($form, $url));

        $this->assertInputError($html, $this->tooLarge('最終月家賃'));
        $this->assertSame('active', $contract->fresh()->status->value);
    }

    public function test_a_revised_contract_rent_over_the_limit_is_refused(): void
    {
        $contract = $this->activeContract($this->unit(1, 'A'));
        $url = route('tenant.contracts.revise', $contract);
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('tenant.contracts.revise.execute', $contract) . '"'), [
            'revision_date' => '2026-11-01', 'new_rent' => (string) (self::INT_MAX + 1),
        ]);

        $html = $this->landed($this->submit($form, $url));

        $this->assertInputError($html, $this->tooLarge('新・月額家賃'));
        $this->assertSame(100000, $contract->fresh()->rent);
    }

    // ============================================================
    // 投資・修繕・問合せ
    // ============================================================

    private function investmentForm(string $amounts): array
    {
        $unit = $this->unit(1, 'A');
        $url = route('tenant.investments.create');

        return $this->browserForm($this->htmlOf($url), 'action="' . route('tenant.investments.store') . '"', 'investmentForm',
            "data.propertyId = '{$this->building->id}'; data.filterUnits(); data.unitId = '{$unit->id}'; {$amounts}");
    }

    public function test_an_investment_detail_over_the_limit_is_refused(): void
    {
        $form = $this->investmentForm('data.details[0].amount = ' . (self::INT_MAX + 1) . ';');

        $html = $this->landed($this->submit($form, route('tenant.investments.create')));

        $this->assertInputError($html, $this->tooLarge('金額'));
        $this->assertSame(0, Investment::count());
    }

    public function test_investment_details_whose_total_is_over_the_limit_are_refused(): void
    {
        // 1 行ずつは上限の内側でも、投資総額（INT の列）に入らない
        $form = $this->investmentForm('data.details[0].amount = 1500000000; data.addDetail(); data.details[1].amount = 1500000000;');

        $html = $this->landed($this->submit($form, route('tenant.investments.create')));

        $this->assertInputError($html, '明細の金額の合計は2,147,483,647円以下にしてください。');
        $this->assertSame(0, Investment::count());
    }

    public function test_a_repair_cost_over_the_limit_is_refused(): void
    {
        $url = route('tenant.repairs.create');
        $html = $this->htmlOf($url);
        $needle = 'action="' . route('tenant.repairs.store') . '"';
        $form = $this->browserForm($html, $needle, null, "data.propertyId = '{$this->building->id}';", [], $this->inlineXDataAround($html, $needle));
        $form = $this->fill($form, ['description' => '外壁', 'cost' => (string) (self::INT_MAX + 1)]);

        $html = $this->landed($this->submit($form, $url));

        $this->assertInputError($html, $this->tooLarge('費用'));
    }

    public function test_an_inquiry_budget_and_area_over_the_limits_are_refused(): void
    {
        $url = route('tenant.inquiries.create');
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('tenant.inquiries.store') . '"', 'inquiryCreateForm', "data.propertyId = '{$this->building->id}';");
        $form = $this->fill($form, ['contact_name' => '問合 太郎', 'budget_max' => (string) (self::INT_MAX + 1), 'desired_area_min' => '1000000']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertInputError($html, $this->tooLarge('予算上限'));
        $this->assertInputError($html, $this->tooLarge('希望面積の下限', '999999.99'));
    }

    // ============================================================
    // 走査: 新しく足した整数・数値の入力にも上限がある（全件。Top trap #13）
    // ============================================================

    public function test_every_integer_and_numeric_rule_in_the_tenant_controllers_has_an_upper_bound(): void
    {
        $files = glob(app_path('Http/Controllers/Tenant/*.php'));
        $this->assertGreaterThanOrEqual(10, count($files), 'テナントのコントローラを拾えていない');
        $checked = 0;
        $unbounded = [];
        foreach ($files as $file) {
            foreach (token_get_all(file_get_contents($file)) as $token) {
                if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $rule = substr($token[1], 1, -1);
                if (! preg_match('/(^|\|)(integer|numeric)(\||$)/', $rule)) {
                    continue;
                }
                $checked++;
                // 上限は max（定数を連結するので文字列は `max:` で終わる）・between・既存の行（exists）のどれか
                if (! preg_match('/(^|\|)(max:|between:|exists:)/', $rule)) {
                    $unbounded[] = basename($file) . ':' . $token[2] . ' ' . $rule;
                }
            }
        }
        $this->assertGreaterThanOrEqual(40, $checked, '整数・数値の入力チェックを拾えていない');
        $this->assertSame([], $unbounded, '上限の無い整数・数値の入力がある（本番の MySQL では列に入らない値で 500）');
    }
}
