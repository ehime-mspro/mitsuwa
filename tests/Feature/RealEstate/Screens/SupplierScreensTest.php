<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\ReSupplier;

/**
 * 仕入れ先の一覧・登録・詳細・編集・削除と、仕入れ案件の画面の「仕入先として登録」（簡易登録の API）を、
 * 描いた画面から送る往復で見る。
 */
class SupplierScreensTest extends RealEstateScreenTestCase
{
    private function supplier(string $name = '松山不動産'): ReSupplier
    {
        return ReSupplier::create(['supplier_code' => 'SUP-001', 'type' => 'corporation', 'name' => $name]);
    }

    public function test_the_list_shows_suppliers(): void
    {
        $this->supplier();

        $supplier = ReSupplier::firstOrFail();
        $html = $this->htmlOf(route('realestate.suppliers.index'));

        // 行の名前が詳細へのリンクになっている（失敗しても画面の HTML を丸ごと出さない）
        $pattern = '/<a href="' . preg_quote(route('realestate.suppliers.show', $supplier), '/') . '"[^>]*>松山不動産<\/a>/u';
        $this->assertSame(1, preg_match($pattern, $html), '一覧に仕入れ先の行が出ていない');
    }

    public function test_a_supplier_is_registered_from_the_screen(): void
    {
        $url = route('realestate.suppliers.create');
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('realestate.suppliers.store') . '"');
        $form = $this->fill($form, ['type' => 'realtor', 'name' => '道後ホーム', 'phone' => '089-000-0000']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '仕入れ先「道後ホーム」を登録しました。');
        $supplier = ReSupplier::where('name', '道後ホーム')->firstOrFail();
        $this->assertSame('SUP-001', $supplier->supplier_code);
        $this->assertSame('realtor', $supplier->type->value);
        $this->assertSame('089-000-0000', $supplier->phone);
    }

    public function test_a_supplier_without_a_name_is_refused(): void
    {
        $url = route('realestate.suppliers.create');
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('realestate.suppliers.store') . '"'), ['type' => 'corporation', 'name' => '']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '名前']));
        $this->assertSame(0, ReSupplier::count());
    }

    public function test_the_detail_screen_shows_the_supplier(): void
    {
        $supplier = $this->supplier();

        $html = $this->htmlOf(route('realestate.suppliers.show', $supplier));

        $this->assertSame(1, preg_match('/<h1[^>]*>\s*松山不動産\s*<\/h1>/u', $html), '詳細の見出しに仕入れ先の名前が出ていない');
    }

    public function test_a_supplier_is_edited_from_the_screen(): void
    {
        $supplier = $this->supplier();
        $url = route('realestate.suppliers.edit', $supplier);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('realestate.suppliers.update', $supplier) . '"');
        $this->assertSame('PUT', $form['fields']['_method']);
        $this->assertSame('松山不動産', $form['fields']['name'], '編集画面に今の名前が入っていない');
        $form = $this->fill($form, ['contact_person' => '佐伯']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '仕入れ先「松山不動産」を更新しました。');
        $this->assertSame('佐伯', $supplier->fresh()->contact_person);
    }

    public function test_a_supplier_is_deleted_from_the_detail_screen(): void
    {
        $supplier = $this->supplier();
        $url = route('realestate.suppliers.show', $supplier);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('realestate.suppliers.destroy', $supplier) . '"');
        $this->assertSame('DELETE', $form['fields']['_method']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '仕入れ先「松山不動産」を削除しました。');
        $this->assertTrue($supplier->fresh()->trashed());
    }

    public function test_a_supplier_used_by_a_procurement_is_not_deleted(): void
    {
        $supplier = $this->supplier();
        $this->procurement(['supplier_id' => $supplier->id]);
        $url = route('realestate.suppliers.show', $supplier);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('realestate.suppliers.destroy', $supplier) . '"');

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'error', 'この仕入れ先は仕入れ案件で使用されているため削除できません。');
        $this->assertFalse($supplier->fresh()->trashed());
    }

    // ============================================================
    // 仕入れ案件の画面の「仕入先として登録」（POST /api/realestate/suppliers/quick）
    // ============================================================

    private const QUICK = 'data.supplierQuery = "  新居浜土地  "; data.openQuickRegister(); data.quickType = "individual"; data.submitQuickRegister();';

    public function test_a_supplier_is_registered_from_the_procurement_screen_and_selected(): void
    {
        $html = $this->htmlOf(route('realestate.procurements.create'));
        $withPicker = ['function supplierPicker('];

        $run = $this->driveAlpine($html, 'procurementForm', 'procurementForm()', self::QUICK, [], true, [], $withPicker);
        $this->assertCount(1, $run['requests']);
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertStatus(201);

        $supplier = ReSupplier::where('name', '新居浜土地')->firstOrFail();
        $this->assertSame('individual', $supplier->type->value);
        $after = $this->driveAlpine($html, 'procurementForm', 'procurementForm()', self::QUICK, [$this->asFetchResponse($response)], true, ['supplierId', 'supplierDisplay', 'quickModalOpen'], $withPicker);
        $this->assertSame([$supplier->id, '新居浜土地', false], $after['evaluated'], '登録した仕入れ先が選ばれていない');
    }

    public function test_a_supplier_with_the_same_name_is_offered_instead_of_registered_again(): void
    {
        $this->supplier('新居浜土地');
        $html = $this->htmlOf(route('realestate.procurements.create'));
        $withPicker = ['function supplierPicker('];

        $run = $this->driveAlpine($html, 'procurementForm', 'procurementForm()', self::QUICK, [], true, [], $withPicker);
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertOk();

        $this->assertSame(1, ReSupplier::count(), '同じ名前の仕入れ先がもう一度登録された');
        $after = $this->driveAlpine($html, 'procurementForm', 'procurementForm()', self::QUICK, [$this->asFetchResponse($response)], true, ['quickDuplicates.map(function (d) { return d.code; })', 'supplierId'], $withPicker);
        $this->assertSame([['SUP-001'], null], $after['evaluated'], '同じ名前の候補が出ていない');
    }
}
