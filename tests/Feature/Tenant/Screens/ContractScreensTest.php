<?php

namespace Tests\Feature\Tenant\Screens;

use App\Models\Contract;
use App\Models\Unit;

/**
 * テナント契約の登録・編集・解約（tenant.contracts.create / store / edit / update / terminate / terminate.execute）を、
 * 描いた画面の JS（契約の登録は物件を選ぶと空き区画と問合せを fetch し、区画の選択肢を JS で足す）から送る往復で見る。
 */
class ContractScreensTest extends TenantScreenTestCase
{
    // ============================================================
    // 登録
    // ============================================================

    public function test_a_contract_is_registered_from_the_screen(): void
    {
        $unit = $this->unit(2, 'A');
        $customer = $this->customer('大街道商事', 'CU-0010');
        $url = route('tenant.contracts.create');
        $index = $this->htmlOf(route('tenant.contracts.index'));
        $this->assertStringContainsString('href="' . $url . '"', $index, '契約一覧に新規登録の入口が無い');
        $html = $this->htmlOf($url);

        // 物件を選ぶ → JS が空き区画と問合せを取りに行く（要求は JS が組んだものをそのまま送る）
        $choose = "data.propertyId = '{$this->building->id}'; data.onPropertyChange();";
        $captured = $this->driveAlpine($html, 'contractCreateForm', $this->xData($html, 'contractCreateForm'), $choose);
        $this->assertCount(2, $captured['requests'], '物件を選んでも空き区画と問合せを取りに行かない');
        $responses = array_map(fn (array $request) => $this->asFetchResponse($this->actingAs($this->user)->sendCaptured($request)->assertOk()), $captured['requests']);

        // 顧客は検索して選ぶ（検索の打鍵は JS の setTimeout を待つので、同じ検索をここで送り、候補を選ぶ）
        $found = $this->actingAs($this->user)->getJson('/api/tenant/customers/search?q=' . urlencode('大街道'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->json();
        $this->assertSame($customer->id, $found[0]['id']);

        $form = $this->browserForm($html, 'action="' . route('tenant.contracts.store') . '"', 'contractCreateForm', $choose . '
            setImmediate(function () {
                data.unitId = "' . $unit->id . '"; data.onUnitChange();
                data.selectCustomer(' . json_encode($found[0], JSON_UNESCAPED_UNICODE) . ');
                data.rentStartDate = "2026-10-16"; data.initialMonthType = "prorated";
            });', $responses);
        $form = $this->fill($form, ['contract_date' => '2026-10-01', 'store_name' => '大街道カフェ']);
        $this->assertSame((string) $unit->id, $form['fields']['unit_id']);
        $this->assertSame('80000', $form['fields']['rent'], '区画を選んでも募集家賃が入らない');

        $html = $this->landed($this->submit($form, $url));

        $contract = Contract::where('unit_id', $unit->id)->firstOrFail();
        $this->assertFlash($html, 'success', "契約「{$contract->contract_number}」を登録しました。");
        $this->assertSame($customer->id, $contract->customer_id);
        $this->assertSame('大街道カフェ', $contract->store_name);
        $this->assertSame(80000, $contract->rent);
        $this->assertSame(8000, $contract->common_fee);
        $this->assertSame('prorated', $contract->initial_month_type->value);
        // 10/16〜10/31 の 16 日分（月額 88,000 円 × 16 / 31 の四捨五入）
        $this->assertSame(45419, $contract->initial_month_amount);
        $this->assertSame('occupied', $unit->fresh()->status->value);
    }

    // ============================================================
    // 編集
    // ============================================================

    private function editForm(Contract $contract, string $steps): array
    {
        $html = $this->htmlOf(route('tenant.contracts.edit', $contract));

        return $this->browserForm($html, 'action="' . route('tenant.contracts.update', $contract) . '"', 'contractEditForm', $steps);
    }

    public function test_the_edit_screen_saves_what_it_shows(): void
    {
        $contract = $this->activeContract($this->unit(1, 'A'));
        $form = $this->editForm($contract, 'data.rent = 105000;');
        $this->assertSame('10000', $form['fields']['common_fee'], '編集画面に今の共益費が入っていない');
        $this->assertSame('full', $form['fields']['initial_month_type'], '編集画面に今の初月の請求方法が入っていない');

        $html = $this->landed($this->submit($form, route('tenant.contracts.edit', $contract)));

        $this->assertFlash($html, 'success', '契約「C-2025-001」を更新しました。');
        $contract->refresh();
        $this->assertSame(105000, $contract->rent);
        $this->assertSame(10000, $contract->common_fee);
    }

    /** 編集画面の JS（contractEditForm）が組み立てられ、家賃などの欄の値が入る */
    private function editState(string $html): array
    {
        return $this->driveAlpine($html, 'contractEditForm', $this->xData($html, 'contractEditForm'), '')['state'];
    }

    public function test_the_edit_screen_keeps_working_after_an_input_error_with_an_empty_rent(): void
    {
        $contract = $this->activeContract($this->unit(1, 'A'));
        $form = $this->editForm($contract, "data.rent = '';");

        $html = $this->landed($this->submit($form, route('tenant.contracts.edit', $contract)));

        $this->assertInputError($html, '月額家賃は必須です。');
        $state = $this->editState($html);
        $this->assertSame(null, $state['rent'], '空にした家賃が戻った画面で空になっていない');
        $this->assertSame('10000', (string) $state['commonFee'], '戻った画面で共益費が消えた');
        $this->assertSame('full', $state['initialMonthType']);
        $this->assertSame(100000, $contract->fresh()->rent);
    }

    public function test_the_edit_screen_works_for_a_contract_without_a_common_fee(): void
    {
        $contract = $this->activeContract($this->unit(1, 'A'));
        $contract->forceFill(['common_fee' => null, 'garbage_fee' => null, 'pest_control_fee' => null])->save();

        $state = $this->editState($this->htmlOf(route('tenant.contracts.edit', $contract)));

        $this->assertSame(100000, $state['rent']);
        $this->assertNull($state['commonFee']);
    }

    // ============================================================
    // 解約
    // ============================================================

    private function terminateForm(Contract $contract, string $steps): array
    {
        $show = $this->htmlOf(route('tenant.contracts.show', $contract));
        $this->assertStringContainsString('href="' . route('tenant.contracts.terminate', $contract) . '"', $show, '契約の詳細に解約の入口が無い');
        $html = $this->htmlOf(route('tenant.contracts.terminate', $contract));

        return $this->browserForm($html, 'action="' . route('tenant.contracts.terminate.execute', $contract) . '"', 'contractTerminateForm', $steps);
    }

    public function test_a_contract_is_terminated_from_the_screen(): void
    {
        $unit = $this->unit(1, 'A');
        $contract = $this->activeContract($unit);
        $form = $this->terminateForm($contract, "data.contractEndDate = '2026-11-15'; data.finalMonthType = 'prorated';");
        $form = $this->fill($form, ['termination_reason' => '移転のため']);
        $this->assertSame('PUT', $form['method']);

        $html = $this->landed($this->submit($form, route('tenant.contracts.terminate', $contract)));

        $this->assertFlash($html, 'success', '契約「C-2025-001」の解約処理を完了しました。');
        $contract->refresh();
        $this->assertSame('terminated', $contract->status->value);
        $this->assertSame('2026-11-15', $contract->contract_end_date->format('Y-m-d'));
        $this->assertSame('prorated', $contract->final_month_type->value);
        // 11/1〜11/15 の 15 日分（月額 113,000 円 × 15 / 30）
        $this->assertSame(56500, $contract->final_month_amount);
        $this->assertSame('移転のため', $contract->termination_reason);
        $this->assertSame('vacant', $unit->fresh()->status->value);
    }

    public function test_an_end_date_before_the_contract_date_is_refused(): void
    {
        $contract = $this->activeContract($this->unit(1, 'A'));
        $form = $this->terminateForm($contract, "data.contractEndDate = '2025-03-31';");

        $html = $this->landed($this->submit($form, route('tenant.contracts.terminate', $contract)));

        $this->assertInputError($html, '契約終了日は契約日（2025/04/01）以降の日付を指定してください。');
        $this->assertSame('active', $contract->fresh()->status->value);
    }

    public function test_an_end_date_on_the_contract_date_is_accepted(): void
    {
        $contract = $this->activeContract($this->unit(1, 'A'));
        $form = $this->terminateForm($contract, "data.contractEndDate = '2025-04-01';");

        $this->landed($this->submit($form, route('tenant.contracts.terminate', $contract)));

        $this->assertSame('terminated', $contract->fresh()->status->value);
    }
}
