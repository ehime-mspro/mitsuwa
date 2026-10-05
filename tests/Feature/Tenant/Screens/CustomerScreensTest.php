<?php

namespace Tests\Feature\Tenant\Screens;

use App\Models\Customer;

/**
 * テナント顧客の登録・編集・削除（tenant.customers.create / store / edit / update / destroy）を、
 * 描いた画面から送る往復で見る。
 */
class CustomerScreensTest extends TenantScreenTestCase
{
    public function test_a_customer_is_registered_from_the_screen(): void
    {
        $url = route('tenant.customers.create');
        $this->assertStringContainsString('href="' . $url . '"', $this->htmlOf(route('tenant.customers.index')), '顧客一覧に新規登録の入口が無い');
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('tenant.customers.store') . '"'), [
            'name' => '一番町商店', 'name_kana' => 'イチバンチョウショウテン', 'customer_type' => 'sole_proprietor',
            'representative' => '一番 一郎', 'phone' => '089-000-0000', 'email' => 'ichiban@example.com',
        ]);

        $html = $this->landed($this->submit($form, $url));

        $customer = Customer::where('name', '一番町商店')->firstOrFail();
        $this->assertSame('CUS-001', $customer->code);
        $this->assertFlash($html, 'success', '顧客「CUS-001 一番町商店」を登録しました。');
        $this->assertSame('sole_proprietor', $customer->customer_type->value);
        $this->assertSame('ichiban@example.com', $customer->email);
    }

    public function test_a_missing_customer_type_is_refused_on_the_screen(): void
    {
        $url = route('tenant.customers.create');
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('tenant.customers.store') . '"'), ['name' => '種別なし商事']);
        $this->assertSame('', $form['fields']['customer_type'], '種別の既定が「— 選択 —」でない');

        $html = $this->landed($this->submit($form, $url));

        $this->assertInputError($html, '顧客種別は必須です。');
        $this->assertFalse(Customer::where('name', '種別なし商事')->exists());
    }

    public function test_the_edit_screen_saves_what_it_shows(): void
    {
        $customer = Customer::create(['code' => 'CU-0099', 'name' => '旧商号', 'customer_type' => 'individual', 'phone' => '089-111-1111']);
        $show = $this->htmlOf(route('tenant.customers.show', $customer));
        $this->assertStringContainsString('href="' . route('tenant.customers.edit', $customer) . '"', $show, '顧客の詳細に編集の入口が無い');
        $form = $this->parseForm($this->htmlOf(route('tenant.customers.edit', $customer)), 'action="' . route('tenant.customers.update', $customer) . '"');
        $this->assertSame('individual', $form['fields']['customer_type'], '編集画面に今の種別が選ばれていない');
        $form = $this->fill($form, ['name' => '新商号']);

        $html = $this->landed($this->submit($form, route('tenant.customers.edit', $customer)));

        $this->assertFlash($html, 'success', '顧客「CU-0099 新商号」を更新しました。');
        $customer->refresh();
        $this->assertSame('新商号', $customer->name);
        $this->assertSame('089-111-1111', $customer->phone);
    }

    public function test_a_customer_without_contracts_is_deleted_from_the_confirmation(): void
    {
        $customer = $this->customer('消す商事', 'CU-0050');
        $url = route('tenant.customers.show', $customer);
        $html = $this->htmlOf($url);
        $this->assertStringContainsString('@click="showDeleteModal = true"', $html, '契約の無い顧客に削除のボタンが無い');
        $form = $this->parseForm($html, 'action="' . route('tenant.customers.destroy', $customer) . '"');
        $this->assertSame('DELETE', $form['method']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '顧客「CU-0050 消す商事」を削除しました。');
        $this->assertSoftDeleted($customer);
    }

    public function test_a_customer_with_a_contract_is_not_deleted(): void
    {
        $customer = $this->customer('契約あり商事', 'CU-0051');
        $this->activeContract($this->unit(1, 'A'), $customer);
        $url = route('tenant.customers.show', $customer);
        $html = $this->htmlOf($url);
        $this->assertStringNotContainsString('@click="showDeleteModal = true"', $html, '契約のある顧客に削除のボタンが出ている');

        // ボタンは出ないが、古い画面などから送られても断る
        $form = $this->parseForm($html, 'action="' . route('tenant.customers.destroy', $customer) . '"');
        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'error', 'この顧客には契約履歴があるため削除できません。');
        $this->assertNotSoftDeleted($customer);
    }
}
