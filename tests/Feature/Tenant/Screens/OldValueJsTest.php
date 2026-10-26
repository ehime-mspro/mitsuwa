<?php

namespace Tests\Feature\Tenant\Screens;

use App\Models\Inquiry;
use App\Models\Investment;
use App\Models\Repair;
use Tests\Concerns\SendsValuesBack;

/**
 * テナント管理の画面が、入力エラーで戻った値（old()）を画面の JavaScript に元の文字のまま渡す（Bug #76 と同じ形）。
 *
 * ⚠ `'{{ old(…) }}'` と JS の文字列に書くと、`{{ }}` が `'` を `&#039;`・`&` を `&amp;` にする。
 *   <script> の中では実体参照が戻らないので、文字が変わる。末尾が `\` だと次の `'` を飲み込み、画面の JS が丸ごと止まる。
 *   HTML の属性（`x-data`・`:selected`）の中ではブラウザが実体参照を戻すので、`'` で文字列が閉じ、送った値が JS のコードとして動く。
 * ⚠ どの欄も画面からは選択肢・数・日付しか送れない。手で組んだ送信（描いた画面のフォームの欄を書き換えて送る）で戻す
 *   （送り方と値の決まりは SendsValuesBack）。
 */
class OldValueJsTest extends TenantScreenTestCase
{
    use SendsValuesBack;

    /** 属性の中で JS のコードとして動いたら `globalThis.__ran` が立つ値 */
    private const RUNS_CODE = "x' + (globalThis.__ran = 1) + '";

    private const RAN = 'typeof globalThis.__ran';

    private const CHANGED = '入力エラーで戻った値が画面の JS に元の文字のまま渡らないか、画面の JS が止まった';

    /** 戻った画面の JS を node で組み立て、$expressions を評価する（$function が null ならインラインの x-data の式 $factory で組む） */
    private function evaluate(string $html, ?string $function, array $expressions, ?string $factory = null): array
    {
        return $this->evaluateJs($html, $function, $expressions, $factory);
    }

    /** 画面の区画の選択肢の `:selected` の式（実体参照を戻したもの・ちょうど 1 つ）を、区画 $unitId の選択肢として評価する式にする */
    private function selectedFor(string $html, int $unitId): string
    {
        $this->assertSame(1, preg_match_all('/<option\b[^>]*\s:selected="([^"]*)"/', $html, $m), '区画の選択肢の :selected がちょうど 1 つ描かれていない');

        return '(function (u) { return (' . html_entity_decode($m[1][0], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '); })({ id: ' . $unitId . ' })';
    }

    // ============================================================
    // 契約（登録・編集・解約）
    // ============================================================

    public function test_the_contract_registration_screen_keeps_the_returned_values(): void
    {
        $sent = [
            'property_id'        => ['propertyId', self::tricky('物件')],
            'unit_id'            => ['unitIdOld', self::tricky('区画')],
            'rent_start_date'    => ['rentStartDate', self::tricky('開始日')],
            'initial_month_type' => ['initialMonthType', self::tricky('初月')],
            'inquiry_id'         => ['inquiryId', self::tricky('問合せ')],
            'customer_id'        => ['customerId', self::tricky('顧客')],
        ];
        $url = route('tenant.contracts.create');

        $html = $this->sendBack($url, route('tenant.contracts.store'), array_map(fn ($s) => $s[1], $sent));

        $this->assertSame(array_column($sent, 1), $this->evaluate($html, 'contractCreateForm', array_column($sent, 0)), self::CHANGED);
    }

    public function test_the_contract_edit_screen_keeps_the_returned_values(): void
    {
        $contract = $this->activeContract($this->unit(1, 'A'));
        $sent = [
            'customer_id'        => ['customerId', self::tricky('顧客')],
            'rent_start_date'    => ['rentStartDate', self::tricky('開始日')],
            'initial_month_type' => ['initialMonthType', self::tricky('初月')],
        ];
        $url = route('tenant.contracts.edit', $contract);

        $html = $this->sendBack($url, route('tenant.contracts.update', $contract), array_map(fn ($s) => $s[1], $sent));

        $this->assertSame(array_column($sent, 1), $this->evaluate($html, 'contractEditForm', array_column($sent, 0)), self::CHANGED);
    }

    public function test_the_termination_screen_keeps_the_returned_values(): void
    {
        $contract = $this->activeContract($this->unit(1, 'A'));
        $sent = [
            'contract_end_date' => ['contractEndDate', self::tricky('終了日')],
            'final_month_type'  => ['finalMonthType', self::tricky('最終月')],
        ];
        $url = route('tenant.contracts.terminate', $contract);

        $html = $this->sendBack($url, route('tenant.contracts.terminate.execute', $contract), array_map(fn ($s) => $s[1], $sent));

        $this->assertSame(array_column($sent, 1), $this->evaluate($html, 'contractTerminateForm', array_column($sent, 0)), self::CHANGED);
    }

    // ============================================================
    // 問合せ（登録・編集）
    // ============================================================

    public function test_the_inquiry_registration_screen_keeps_the_returned_values(): void
    {
        $sent = [
            'property_id' => ['propertyId', self::tricky('物件')],
            'customer_id' => ['customerId', self::tricky('顧客')],
        ];
        $url = route('tenant.inquiries.create');

        $html = $this->sendBack($url, route('tenant.inquiries.store'), array_map(fn ($s) => $s[1], $sent), ['unit_ids']);

        $this->assertSame(array_column($sent, 1), $this->evaluate($html, 'inquiryCreateForm', array_column($sent, 0)), self::CHANGED);
    }

    public function test_the_inquiry_edit_screen_keeps_the_returned_values(): void
    {
        $inquiry = Inquiry::create([
            'inquiry_number' => 'INQ-2026-050', 'property_id' => $this->building->id, 'contact_name' => '問合 花子',
            'inquiry_date' => '2026-09-01', 'status' => 'follow',
        ]);
        $url = route('tenant.inquiries.edit', $inquiry);

        $html = $this->sendBack($url, route('tenant.inquiries.update', $inquiry), ['customer_id' => self::tricky('顧客')], ['unit_ids']);

        $this->assertSame([self::tricky('顧客')], $this->evaluate($html, 'inquiryEditForm', ['customerId']), self::CHANGED);
    }

    // ============================================================
    // 投資（登録・編集）
    // ============================================================

    public function test_the_investment_registration_screen_keeps_the_returned_values(): void
    {
        $sent = [
            'property_id' => ['propertyId', self::tricky('物件')],
            'unit_id'     => ['unitId', self::tricky('区画')],
        ];
        $url = route('tenant.investments.create');

        $html = $this->sendBack($url, route('tenant.investments.store'), array_map(fn ($s) => $s[1], $sent), ['details']);

        $this->assertSame(array_column($sent, 1), $this->evaluate($html, 'investmentForm', array_column($sent, 0)), self::CHANGED);
    }

    public function test_the_investment_registration_screen_selects_the_returned_unit_without_running_it_as_code(): void
    {
        $unit = $this->unit(1, 'A');
        $url = route('tenant.investments.create');
        $action = route('tenant.investments.store');

        // 明細を送らずに入力エラーで戻す。戻した区画が選ばれている
        $html = $this->sendBack($url, $action, ['property_id' => (string) $this->building->id, 'unit_id' => (string) $unit->id], ['details']);
        $this->assertSame([true, false], $this->evaluate($html, 'investmentForm', [$this->selectedFor($html, $unit->id), $this->selectedFor($html, $unit->id + 1)]),
            '戻した区画が選ばれていない（ほかの区画が選ばれている）');

        $html = $this->sendBack($url, $action, ['property_id' => (string) $this->building->id, 'unit_id' => self::RUNS_CODE], ['details']);
        $this->assertSame([false, 'undefined'], $this->evaluate($html, 'investmentForm', [$this->selectedFor($html, $unit->id), self::RAN]),
            '戻した区画の値が区画の選択肢の :selected の中で JS のコードとして動いた');
    }

    public function test_the_investment_edit_screen_keeps_the_returned_values(): void
    {
        $investment = Investment::create([
            'investment_number' => 'INV-2026-002', 'property_id' => $this->building->id, 'unit_id' => $this->unit(1, 'A')->id,
            'pattern' => 'renovation', 'status' => 'planning', 'description' => '内装', 'total_amount' => 100000,
        ]);
        $investment->details()->create(['cost_item' => 'interior', 'amount' => 100000]);
        $sent = [
            'property_id' => ['propertyId', self::tricky('物件')],
            'unit_id'     => ['unitId', self::tricky('区画')],
        ];
        $url = route('tenant.investments.edit', $investment);

        $html = $this->sendBack($url, route('tenant.investments.update', $investment), array_map(fn ($s) => $s[1], $sent), ['details']);

        $this->assertSame(array_column($sent, 1), $this->evaluate($html, 'investmentEditForm', array_column($sent, 0)), self::CHANGED);
    }

    // ============================================================
    // 物件（登録・編集）: 所有区分は x-data の属性の中
    // ============================================================

    public function test_the_property_registration_screen_does_not_run_the_returned_owner_type_as_code(): void
    {
        $url = route('tenant.properties.create');
        $action = route('tenant.properties.store');

        $html = $this->sendBack($url, $action, ['owner_type' => self::RUNS_CODE]);

        $needle = 'action="' . $action . '"';
        $this->assertSame([self::RUNS_CODE, 'undefined'], $this->evaluate($html, null, ['ownerType', self::RAN], $this->inlineXDataAround($html, $needle)),
            '戻した所有区分が x-data の中で JS のコードとして動いた（値が変わった）');
    }

    public function test_the_property_edit_screen_does_not_run_the_returned_owner_type_as_code(): void
    {
        $url = route('tenant.properties.edit', $this->building);
        $action = route('tenant.properties.update', $this->building);

        $html = $this->sendBack($url, $action, ['owner_type' => self::RUNS_CODE]);

        $needle = 'action="' . $action . '"';
        $this->assertSame([self::RUNS_CODE, 'undefined'], $this->evaluate($html, null, ['ownerType', self::RAN], $this->inlineXDataAround($html, $needle)),
            '戻した所有区分が x-data の中で JS のコードとして動いた（値が変わった）');
    }

    // ============================================================
    // 修繕（登録・編集）: 物件は x-data・区画は :selected の属性の中
    // ============================================================

    private function repairEvaluate(string $html, string $action, array $expressions): array
    {
        return $this->evaluate($html, null, $expressions, $this->inlineXDataAround($html, 'action="' . $action . '"'));
    }

    public function test_the_repair_registration_screen_does_not_run_the_returned_property_as_code(): void
    {
        $action = route('tenant.repairs.store');

        $html = $this->sendBack(route('tenant.repairs.create'), $action, ['property_id' => self::RUNS_CODE]);

        $this->assertSame([self::RUNS_CODE, 'undefined'], $this->repairEvaluate($html, $action, ['propertyId', self::RAN]),
            '戻した物件が x-data の中で JS のコードとして動いた（値が変わった）');
    }

    public function test_the_repair_registration_screen_selects_the_returned_unit_without_running_it_as_code(): void
    {
        $unit = $this->unit(1, 'A');
        $url = route('tenant.repairs.create');
        $action = route('tenant.repairs.store');

        // 内容を書かずに入力エラーで戻す。戻した区画が選ばれている
        $html = $this->sendBack($url, $action, ['property_id' => (string) $this->building->id, 'unit_id' => (string) $unit->id, 'description' => '']);
        $this->assertSame([true, false], $this->repairEvaluate($html, $action, [$this->selectedFor($html, $unit->id), $this->selectedFor($html, $unit->id + 1)]),
            '戻した区画が選ばれていない（ほかの区画が選ばれている）');

        $html = $this->sendBack($url, $action, ['property_id' => (string) $this->building->id, 'unit_id' => self::RUNS_CODE]);
        $this->assertSame([false, 'undefined'], $this->repairEvaluate($html, $action, [$this->selectedFor($html, $unit->id), self::RAN]),
            '戻した区画の値が区画の選択肢の :selected の中で JS のコードとして動いた');
    }

    private function repair(): Repair
    {
        return Repair::create(['property_id' => $this->building->id, 'unit_id' => null, 'status' => 'planned', 'description' => '雨どい']);
    }

    public function test_the_repair_edit_screen_does_not_run_the_returned_property_as_code(): void
    {
        $repair = $this->repair();
        $action = route('tenant.repairs.update', $repair);

        $html = $this->sendBack(route('tenant.repairs.edit', $repair), $action, ['property_id' => self::RUNS_CODE]);

        $this->assertSame([self::RUNS_CODE, 'undefined'], $this->repairEvaluate($html, $action, ['propertyId', self::RAN]),
            '戻した物件が x-data の中で JS のコードとして動いた（値が変わった）');
    }

    public function test_the_repair_edit_screen_selects_the_returned_unit_without_running_it_as_code(): void
    {
        $unit = $this->unit(1, 'A');
        $repair = $this->repair();
        $url = route('tenant.repairs.edit', $repair);
        $action = route('tenant.repairs.update', $repair);

        $html = $this->sendBack($url, $action, ['unit_id' => (string) $unit->id, 'description' => '']);
        $this->assertSame([true, false], $this->repairEvaluate($html, $action, [$this->selectedFor($html, $unit->id), $this->selectedFor($html, $unit->id + 1)]),
            '戻した区画が選ばれていない（ほかの区画が選ばれている）');

        $html = $this->sendBack($url, $action, ['unit_id' => self::RUNS_CODE]);
        $this->assertSame([false, 'undefined'], $this->repairEvaluate($html, $action, [$this->selectedFor($html, $unit->id), self::RAN]),
            '戻した区画の値が区画の選択肢の :selected の中で JS のコードとして動いた');
    }
}
