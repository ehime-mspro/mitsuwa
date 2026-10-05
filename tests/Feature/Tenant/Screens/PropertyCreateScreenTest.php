<?php

namespace Tests\Feature\Tenant\Screens;

use App\Models\Property;
use App\Models\StructureType;

/**
 * 物件の登録（tenant.properties.create / store）を、登録画面から送る往復で見る。
 */
class PropertyCreateScreenTest extends TenantScreenTestCase
{
    private function storeForm(string $steps): array
    {
        StructureType::create(['name' => 'RC造', 'sort_order' => 1]);
        $url = route('tenant.properties.create');
        $html = $this->htmlOf($url);
        $needle = 'action="' . route('tenant.properties.store') . '"';
        $this->assertStringContainsString('<option value="RC造"', $html, '構造マスターの値が選択肢に出ていない');

        return $this->browserForm($html, $needle, null, $steps, [], $this->inlineXDataAround($html, $needle));
    }

    public function test_an_owned_property_is_registered_from_the_screen(): void
    {
        $form = $this->fill($this->storeForm("data.ownerType = 'owner';"), [
            'name' => '大街道ビル', 'address' => '愛媛県松山市大街道2-2', 'structure' => 'RC造',
            'total_floors' => '6', 'owner_name' => '大街道 太郎',
        ]);
        $this->assertSame('owner', $form['fields']['owner_type'], '画面の所有区分（x-model）が送られていない');
        $this->assertSame('active', $form['fields']['operation_status'], '稼働状態の既定（checked）が送られていない');

        $html = $this->landed($this->submit($form, route('tenant.properties.create')));

        $property = Property::where('name', '大街道ビル')->firstOrFail();
        $this->assertFlash($html, 'success', '物件「大街道ビル」を登録しました。');
        $this->assertSame('RC造', $property->structure);
        $this->assertSame(6, $property->total_floors);
        $this->assertSame('owner', $property->owner_type?->value);
        $this->assertSame('大街道 太郎', $property->owner_name);
        $this->assertSame('tenant', $property->department->value);
        $this->assertNotSame('T-001', $property->code, '物件コードが既存と重なった');
    }

    public function test_the_owner_name_is_dropped_for_a_self_owned_property(): void
    {
        $form = $this->fill($this->storeForm(''), [
            'name' => '自社ビル', 'address' => '愛媛県松山市一番町1-1', 'owner_name' => '残らない名前',
        ]);
        $this->assertSame('self_owned', $form['fields']['owner_type']);

        $this->landed($this->submit($form, route('tenant.properties.create')));

        $this->assertNull(Property::where('name', '自社ビル')->firstOrFail()->owner_name);
    }

    public function test_a_missing_name_is_refused_on_the_screen(): void
    {
        $form = $this->fill($this->storeForm(''), ['address' => '愛媛県松山市一番町1-1']);

        $html = $this->landed($this->submit($form, route('tenant.properties.create')));

        $this->assertInputError($html, '物件名は必須です。');
        $this->assertSame(1, Property::count());
    }
}
