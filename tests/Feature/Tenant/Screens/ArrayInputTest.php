<?php

namespace Tests\Feature\Tenant\Screens;

use App\Models\Contract;
use App\Models\Investment;
use App\Models\Repair;
use Tests\Concerns\TampersForms;

/**
 * 手で組んだ送信で配列の形が合わない欄（文字の欄に配列・配列の欄に文字）を、入力チェックが断り、
 * 戻った画面が開くこと（Bug #121。判定は App\Support\ShapeCheckingValidator の 1 か所）。
 *
 * ⚠ 描いた画面のフォームの欄を書き換えて送り、転送をたどって着いた画面で見る（TampersForms::tamper()）
 * ⚠ 誤りの文は `<li>` の全文で見る（assertInputError()。Bug #49）。assertSessionHas*() は呼ばない
 */
class ArrayInputTest extends TenantScreenTestCase
{
    use TampersForms;

    private const SHAPE = 'の形が正しくありません。画面を開き直して、もう一度入力してください。';

    // ---------- 保存の 500（配列を素通りさせる規則だけの欄） ----------

    public function test_a_repair_with_an_array_unit_is_refused_without_saving(): void
    {
        $unit = $this->unit(1, 'A');

        $html = $this->tamper(route('tenant.repairs.create'), route('tenant.repairs.store'), [
            'property_id' => (string) $this->building->id, 'unit_id' => [(string) $unit->id],
            'status' => 'planned', 'description' => '雨漏り',
        ]);

        $this->assertInputError($html, '区画' . self::SHAPE);
        $this->assertSame(0, Repair::count());
    }

    // ---------- 戻った画面の 500 ----------

    public function test_the_customer_edit_screen_opens_after_an_array_name(): void
    {
        $customer = $this->customer('旧商号', 'CU-0099');

        $html = $this->tamper(route('tenant.customers.edit', $customer), route('tenant.customers.update', $customer), [
            'name' => ['x'], 'notes' => 'メモを打った',
        ]);

        $this->assertInputError($html, '顧客名は文字列で入力してください。');
        $this->assertMatchesRegularExpression('/name="name"[^>]*value="旧商号"/', $html, '外した欄が保存済みの値で描かれていない');
        $this->assertStringContainsString('メモを打った</textarea>', $html, 'ほかの欄の入力が残っていない');
    }

    public function test_the_contract_registration_screen_opens_after_an_array_unit(): void
    {
        $unit = $this->unit(1, 'A');

        $html = $this->tamper(route('tenant.contracts.create'), route('tenant.contracts.store'), [
            'property_id' => (string) $this->building->id, 'unit_id' => [(string) $unit->id], 'rent' => '120000',
        ]);

        $this->assertInputError($html, '区画' . self::SHAPE);
        $this->assertSame(0, Contract::count());
    }

    public function test_the_investment_registration_screen_opens_after_a_string_for_the_rows(): void
    {
        $unit = $this->unit(1, 'A');

        $html = $this->tamper(route('tenant.investments.create'), route('tenant.investments.store'), [
            'property_id' => (string) $this->building->id, 'unit_id' => (string) $unit->id, 'details' => 'x',
        ]);

        $this->assertInputError($html, '内訳は配列で指定してください。');
        $this->assertSame(0, Investment::count());
    }
}
