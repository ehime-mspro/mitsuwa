<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Enums\UserRole;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProcurementLot;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 土地（自社買取）の仕入れ案件の詳細画面から区画を登録・編集・削除する（設計書 §5。1 案件に 1 区画まで）。
 *
 * ⚠ 区画の 3 つのフォーム（登録・編集・削除）は送り先の URL が同じなので、フォームは `id` で掴む（Bug #47）。
 * ⚠ 入力エラーの期待文は trans() で組み、1 件の全文で見る（Bug #49）。
 */
class ProcurementLotScreensTest extends RealEstateScreenTestCase
{
    private const CREATE = 'id="lot-create-form"';

    private const EDIT = 'id="lot-edit-form"';

    private const DELETE = 'id="lot-delete-form"';

    private function detailUrl(ReProcurement $procurement): string
    {
        return route('realestate.procurements.show', $procurement);
    }

    private function contractFor(ReProcurement $procurement): ReContract
    {
        return ReContract::create([
            'department' => 'realestate', 'contract_type' => 'procurement_house', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => '勝山の土地', 'procurement_id' => $procurement->id, 'buyer_id' => $this->buyer()->id,
            'contract_amount_land' => 10000000, 'contract_amount_building' => 0, 'cost_amount' => 9000000, 'created_by' => $this->user->id,
        ]);
    }

    public function test_a_lot_is_registered_from_the_detail_screen_with_the_land_values(): void
    {
        $procurement = $this->landProcurement();
        $url = $this->detailUrl($procurement);

        $detail = $this->htmlOf($url);
        $this->assertLotSubmitOnce($detail, self::CREATE, '登録しています…');
        $form = $this->parseForm($detail, self::CREATE);
        $this->assertSame('1', $form['fields']['lot_number']);
        $this->assertSame('132.69', $form['fields']['area_sqm']);
        $this->assertSame('25000000', $form['fields']['selling_price']);
        $this->assertSame('on_sale', $form['fields']['status']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertLotNotice($html, 'success', '区画を登録しました。');
        $this->assertSame(1, ReProcurementLot::where('procurement_id', $procurement->id)->count());
        $this->assertStringContainsString('132.69㎡（40.13坪）', $html);
        $this->assertStringContainsString('@62.3', $html);
        $this->assertStringContainsString('badge-lot-onsale', $html);
    }

    public function test_the_registration_form_starts_sold_when_the_land_is_already_sold(): void
    {
        $procurement = $this->landProcurement(['status' => 'sold']);
        $this->contractFor($procurement);

        $form = $this->parseForm($this->htmlOf($this->detailUrl($procurement)), self::CREATE);

        $this->assertSame('sold', $form['fields']['status']);
    }

    public function test_an_empty_land_area_opens_an_empty_field_and_is_required(): void
    {
        $procurement = $this->landProcurement(['land_area_sqm' => null, 'target_selling_price_land' => null]);
        $url = $this->detailUrl($procurement);

        $form = $this->parseForm($this->htmlOf($url), self::CREATE);
        $this->assertSame('', $form['fields']['area_sqm']);
        $this->assertSame('', $form['fields']['selling_price']);

        $response = $this->submit($this->fill($form, ['lot_number' => '7']), $url);
        $response->assertRedirect($url . '#lot');
        $html = $this->landed($response);

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '面積（㎡）']));
        $this->assertSame(0, ReProcurementLot::count());
        $again = $this->parseForm($html, self::CREATE);
        $this->assertSame('7', $again['fields']['lot_number']);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>}> */
    public static function limits(): array
    {
        return [
            'lot number 0'        => ['lot_number', '0', 'validation.min.numeric', ['attribute' => '号地番号', 'min' => 1]],
            'lot number too big'  => ['lot_number', '2147483648', 'validation.max.numeric', ['attribute' => '号地番号', 'max' => 2147483647]],
            'area 0'              => ['area_sqm', '0', 'validation.min.numeric', ['attribute' => '面積（㎡）', 'min' => 0.01]],
            'price negative'      => ['selling_price', '-1', 'validation.min.numeric', ['attribute' => '販売価格', 'min' => 0]],
            'notes too long'      => ['notes', str_repeat('あ', 201), 'validation.max.string', ['attribute' => '備考', 'max' => 200]],
            'status off the list' => ['status', 'x', 'validation.in', ['attribute' => 'ステータス']],
        ];
    }

    #[DataProvider('limits')]
    public function test_the_limits_are_those_of_project_lots(string $field, string $value, string $rule, array $params): void
    {
        $procurement = $this->landProcurement();
        $url = $this->detailUrl($procurement);

        $form = $this->fill($this->parseForm($this->htmlOf($url), self::CREATE), [$field => $value]);
        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans($rule, $params));
        $this->assertSame(0, ReProcurementLot::count());
    }

    public function test_a_tsubo_price_too_large_for_the_column_is_refused(): void
    {
        $procurement = $this->landProcurement();
        $url = $this->detailUrl($procurement);

        $form = $this->fill($this->parseForm($this->htmlOf($url), self::CREATE), ['area_sqm' => '0.04', 'selling_price' => '30000000']);
        $response = $this->submit($form, $url);
        $response->assertRedirect($url . '#lot');
        $html = $this->landed($response);

        $this->assertErrorItem($html, '販売価格と面積から求めた坪単価が大きすぎます。面積を確かめてください。');
        $this->assertSame(0, ReProcurementLot::count());
    }

    public function test_a_second_lot_is_refused(): void
    {
        $procurement = $this->landProcurement();
        $url = $this->detailUrl($procurement);
        $form = $this->parseForm($this->htmlOf($url), self::CREATE);
        $this->procurementLot($procurement);

        $html = $this->landed($this->submit($form, $url));

        $this->assertLotNotice($html, 'error', '区画は 1 つまでです。2 つ目を足すときは「分譲地に変える」を使ってください。');
        $this->assertSame(1, ReProcurementLot::count());
    }

    public function test_a_lot_cannot_be_added_to_a_procurement_that_is_not_land(): void
    {
        $procurement = $this->procurement();
        $url = $this->detailUrl($procurement);
        $this->assertStringNotContainsString('id="lot"', $this->htmlOf($url));

        $response = $this->actingAs($this->user)->from($url)->post(route('realestate.procurements.lot.store', $procurement), [
            'lot_number' => '1', 'area_sqm' => '100', 'selling_price' => '1000000', 'status' => 'on_sale',
        ]);
        $html = $this->landed($response);

        // 土地でない案件の詳細には区画の欄が無いので、断りは上の帯に出し、#lot へは戻さない
        $response->assertRedirect($url);
        $this->assertFlash($html, 'error', '区画を登録できるのは、物件種別が土地の仕入れ案件だけです。');
        $this->assertSame(0, ReProcurementLot::count());
    }

    public function test_updating_or_deleting_without_a_lot_says_why(): void
    {
        $procurement = $this->landProcurement();
        $url = $this->detailUrl($procurement);
        $message = '区画は登録されていません（ほかの画面で削除された可能性があります）。';

        $html = $this->landed($this->actingAs($this->user)->from($url)->put(route('realestate.procurements.lot.update', $procurement), [
            'lot_number' => '1', 'area_sqm' => '100', 'status' => 'on_sale',
        ]));
        $this->assertLotNotice($html, 'error', $message);

        $html = $this->landed($this->actingAs($this->user)->from($url)->delete(route('realestate.procurements.lot.destroy', $procurement)));
        $this->assertLotNotice($html, 'error', $message);
    }

    public function test_the_lot_is_edited_from_the_detail_screen(): void
    {
        $procurement = $this->landProcurement();
        $lot = $this->procurementLot($procurement);
        $url = $this->detailUrl($procurement);

        $detail = $this->htmlOf($url);
        $this->assertLotSubmitOnce($detail, self::EDIT, '更新しています…');
        $form = $this->parseForm($detail, self::EDIT);
        $this->assertSame('1', $form['fields']['lot_number']);
        $this->assertSame('132.69', $form['fields']['area_sqm']);
        $this->assertSame('25000000', $form['fields']['selling_price']);
        $this->assertSame('on_sale', $form['fields']['status']);

        $html = $this->landed($this->submit($this->fill($form, ['area_sqm' => '150.00', 'status' => 'negotiating']), $url));

        $this->assertLotNotice($html, 'success', '区画を更新しました。');
        $lot->refresh();
        $this->assertSame(['150.00', 'negotiating'], [$lot->area_sqm, $lot->status->value]);
    }

    public function test_saving_the_edit_form_unchanged_keeps_everything(): void
    {
        $procurement = $this->landProcurement();
        $lot = $this->procurementLot($procurement, ['notes' => '角地']);
        $url = $this->detailUrl($procurement);

        $html = $this->htmlOf($url);
        $form = $this->parseForm($html, self::EDIT);
        $this->assertBrowserSubmits($html, self::EDIT, $form['fields']);

        $landed = $this->landed($this->submit($form, $url));

        $this->assertLotNotice($landed, 'success', '区画を更新しました。');
        $lot->refresh();
        $this->assertSame([1, '132.69', 25000000, 'on_sale', '角地'], [$lot->lot_number, $lot->area_sqm, $lot->selling_price, $lot->status->value, $lot->notes]);
    }

    public function test_an_input_error_reopens_the_edit_form_with_the_input(): void
    {
        $procurement = $this->landProcurement();
        $this->procurementLot($procurement);
        $url = $this->detailUrl($procurement);
        $this->assertSame(0, preg_match('/<details\b[^>]*\bopen\b/', $this->htmlOf($url)), '入力エラーの前から開いている');

        $form = $this->fill($this->parseForm($this->htmlOf($url), self::EDIT), ['area_sqm' => '', 'notes' => '角地になりました']);
        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '面積（㎡）']));
        $this->assertSame(1, preg_match('/<details\b[^>]*\bopen\b/', $html), '編集のフォームが開いていない');
        $again = $this->parseForm($html, self::EDIT);
        $this->assertSame('角地になりました', $again['fields']['notes']);
        $this->assertSame('132.69', ReProcurementLot::first()->area_sqm);
    }

    public function test_an_executive_deletes_the_lot_even_with_links(): void
    {
        $procurement = $this->landProcurement();
        $this->procurementLot($procurement);
        $contract = $this->contractFor($procurement);
        $url = $this->detailUrl($procurement);

        $html = $this->htmlOf($url);
        $this->assertStringContainsString(
            "onsubmit=\"return confirm('区画を削除しますか？ 登録した面積・販売価格などが消えます（契約・建売・注文住宅の紐付けは消えません）。')\"",
            $html
        );
        $landed = $this->landed($this->submit($this->parseForm($html, self::DELETE), $url));

        $this->assertLotNotice($landed, 'success', '区画を削除しました。');
        $this->assertSame(0, ReProcurementLot::count());
        $this->assertNotNull(ReContract::find($contract->id));
    }

    public function test_a_manager_edits_but_cannot_delete(): void
    {
        $procurement = $this->landProcurement();
        $this->procurementLot($procurement);
        $this->user = $this->userWithRole(UserRole::Manager);
        $url = $this->detailUrl($procurement);

        $html = $this->htmlOf($url);
        $this->assertStringContainsString(self::EDIT, $html);
        $this->assertStringNotContainsString(self::DELETE, $html);
        $this->assertSame(0, preg_match('/<form\b[^>]*action="' . preg_quote(route('realestate.procurements.lot.destroy', $procurement), '/') . '"[^>]*>\s*(?:@csrf|<input[^>]*>\s*)*<input[^>]*name="_method"[^>]*value="DELETE"/s', $html), '削除のフォームが出ている');

        $this->actingAs($this->user)->delete(route('realestate.procurements.lot.destroy', $procurement))->assertForbidden();
        $this->assertSame(1, ReProcurementLot::count());
    }

    public function test_staff_only_sees_the_lot(): void
    {
        $procurement = $this->landProcurement();
        $this->procurementLot($procurement);
        $this->user = $this->userWithRole(UserRole::Staff);
        $url = $this->detailUrl($procurement);

        $html = $this->htmlOf($url);
        $this->assertStringContainsString('id="lot"', $html);
        $this->assertStringContainsString('132.69㎡（40.13坪）', $html);
        foreach ([self::CREATE, self::EDIT, self::DELETE] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }

        $this->actingAs($this->user)->post(route('realestate.procurements.lot.store', $procurement), [
            'lot_number' => '2', 'area_sqm' => '100', 'status' => 'on_sale',
        ])->assertForbidden();
        $this->assertSame(1, ReProcurementLot::count());
    }

    public function test_staff_sees_that_no_lot_is_registered(): void
    {
        $procurement = $this->landProcurement();
        $this->user = $this->userWithRole(UserRole::Staff);

        $html = $this->htmlOf($this->detailUrl($procurement));

        $this->assertStringContainsString('区画は登録されていません。', $html);
        $this->assertStringNotContainsString(self::CREATE, $html);
    }

    private function procurementEditForm(ReProcurement $procurement): array
    {
        $html = $this->htmlOf(route('realestate.procurements.edit', $procurement));

        return $this->browserForm($html, 'action="' . route('realestate.procurements.update', $procurement) . '"', 'procurementForm', '', [], null, ['function supplierPicker(']);
    }

    public function test_the_property_type_cannot_leave_land_while_a_lot_exists(): void
    {
        $procurement = $this->landProcurement();
        $this->procurementLot($procurement);
        $url = route('realestate.procurements.edit', $procurement);

        $landed = $this->landed($this->submit($this->procurementEditForm($procurement), $url));
        $this->assertFlash($landed, 'success', '仕入れ案件「RE-PRC-001」を更新しました。');

        $html = $this->landed($this->submit($this->fill($this->procurementEditForm($procurement), ['property_type' => 'used_house']), $url));

        $this->assertErrorItem($html, ReProcurement::TYPE_LOCKED_BY_LOT);
        $this->assertSame('brokerage_land', $procurement->fresh()->property_type->value);
    }

    public function test_the_property_type_can_change_when_there_is_no_lot(): void
    {
        $procurement = $this->landProcurement();
        $url = route('realestate.procurements.edit', $procurement);

        $html = $this->landed($this->submit($this->fill($this->procurementEditForm($procurement), ['property_type' => 'used_house']), $url));

        $this->assertFlash($html, 'success', '仕入れ案件「RE-PRC-001」を更新しました。');
        $this->assertSame('used_house', $procurement->fresh()->property_type->value);
    }
}
