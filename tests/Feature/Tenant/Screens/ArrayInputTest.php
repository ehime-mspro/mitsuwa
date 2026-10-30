<?php

namespace Tests\Feature\Tenant\Screens;

use App\Enums\InquiryStatus;
use App\Models\Contract;
use App\Models\Inquiry;
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

    // ---------- 規則の無かった欄 ----------

    /** 戻り先の hidden（return_to）は規則が無く、配列のまま控えて戻った画面が 500 になっていた */
    public function test_the_rent_revision_screen_opens_after_an_array_return_target(): void
    {
        $contract = $this->activeContract($this->unit(1, 'A'));

        $html = $this->tamper(route('tenant.contracts.revise', $contract), route('tenant.contracts.revise.execute', $contract), [
            'return_to' => ['unit'], 'new_rent' => '120000',
        ]);

        $this->assertInputError($html, '選択された戻り先は正しくありません。');
        $this->assertSame(100000, $contract->fresh()->rent);
    }

    /**
     * 控えは画面全体で共有される。状態の変更（updateStatus）に、同じ画面の対応履歴の欄（content）を配列で足して送ると、
     * updateStatus の規則に無い欄なので控えから外れず、戻った画面が対応履歴のフォームを old() で描いて 500 になっていた
     * （画面の欄と送り先の規則を突き合わせる走査 OldInputRuleCoverageTest で見つけた）。
     * ⚠ この詳細の画面は入力エラーを出さない（状態の値は画面のボタンが決めるので、断られるのは手で組んだ送信だけ）
     */
    public function test_the_inquiry_screen_opens_after_an_array_history_field_sent_with_the_status(): void
    {
        $inquiry = Inquiry::create([
            'inquiry_number' => 'INQ-2026-051', 'property_id' => $this->building->id, 'contact_name' => '問合 花子',
            'inquiry_date' => '2026-09-01', 'status' => InquiryStatus::Follow->value,
        ]);
        $url = route('tenant.inquiries.show', $inquiry);
        $html = $this->htmlOf($url);
        $this->assertStringContainsString('name="content"', $html, '画面に対応履歴の欄が無い');
        $form = $this->fill($this->parseForm($html, 'action="' . route('tenant.inquiries.updateStatus', $inquiry) . '"'), ['status' => 'zzz']);
        $form['fields']['content'] = ['x'];

        $html = $this->landed($this->submit($form, $url));

        $this->assertStringContainsString('placeholder="対応内容を記入"></textarea>', $html, '外した欄が空で描かれていない');
        $this->assertSame(InquiryStatus::Follow, $inquiry->fresh()->status);
    }
}
