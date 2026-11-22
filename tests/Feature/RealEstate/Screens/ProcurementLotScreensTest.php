<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Enums\UserRole;
use App\Http\Controllers\RealEstate\ProcurementLotController;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProcurementLot;
use App\Services\RealEstate\ProcurementToProjectConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\TampersForms;

/**
 * 土地の仕入れ案件の詳細画面から区画を登録・編集・削除する（1 案件に何区画でも。設計書 §5.1・§5.2・§5.4）。
 *
 * ⚠ 区画の編集と削除のフォームは送り先の URL が同じ（PUT と DELETE）なので、フォームは `id` で掴む（Bug #47）。
 *   行ごとに `lot-edit-form-{id}`・`lot-delete-form-{id}`、追加は `lot-create-form`。
 * ⚠ 入力エラーの期待文は trans() で組み、1 件の全文で見る（Bug #49）。
 * ⚠ 区画の表の「紐づいているもの」のリンクは、その区画の行の中で見る（ページのどこかにあるだけでは足りない。Bug #43）。
 */
class ProcurementLotScreensTest extends RealEstateScreenTestCase
{
    use TampersForms;

    private const CREATE = 'id="lot-create-form"';

    private const NOT_FOUND = '区画は登録されていません（ほかの画面で削除された可能性があります）。';

    private const DUPLICATE = 'この号地番号はこの土地にすでにあります。別の番号にしてください。';

    private const DELETE_CONFIRM = "onsubmit=\"return confirm('区画を削除しますか？ 登録した面積・販売価格などが消えます。')\"";

    private function editForm(ReProcurementLot $lot): string
    {
        return 'id="lot-edit-form-' . $lot->id . '"';
    }

    private function deleteForm(ReProcurementLot $lot): string
    {
        return 'id="lot-delete-form-' . $lot->id . '"';
    }

    private function detailUrl(ReProcurement $procurement): string
    {
        return route('realestate.procurements.show', $procurement);
    }

    private function contractFor(ReProcurement $procurement, ?ReProcurementLot $lot = null): ReContract
    {
        return ReContract::create([
            'department' => 'realestate', 'contract_type' => 'procurement_land', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => '勝山の土地', 'procurement_id' => $procurement->id, 'procurement_lot_id' => $lot?->id,
            'buyer_id' => $this->buyer()->id,
            'contract_amount_land' => 10000000, 'contract_amount_building' => 0, 'cost_amount' => 9000000, 'created_by' => $this->user->id,
        ]);
    }

    private function building(ReProcurement $procurement, ReProcurementLot $lot): HsProperty
    {
        return HsProperty::create([
            'property_code' => 'HS-001', 'property_name' => '勝山の建売', 'status' => 'construction',
            'land_source_type' => 'procurement', 're_procurement_id' => $procurement->id, 're_procurement_lot_id' => $lot->id,
            'address' => '愛媛県松山市勝山町2-2', 'created_by' => $this->user->id,
        ]);
    }

    private function order(ReProcurement $procurement, ReProcurementLot $lot, string $status = 'estimation'): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code' => 'CO-001', 'order_name' => '勝山の注文住宅', 'status' => $status, 'customer_name' => '大西 花子',
            'land_source_type' => 'procurement', 're_procurement_id' => $procurement->id, 're_procurement_lot_id' => $lot->id,
            'address' => '愛媛県松山市勝山町3-3', 'created_by' => $this->user->id,
        ]);
    }

    /** 区画の表のその区画の行（<tr>…</tr>） */
    private function row(string $html, ReProcurementLot $lot): string
    {
        $start = strpos($html, '<tr id="lot-row-' . $lot->id . '"');
        $this->assertNotFalse($start, "{$lot->lot_number}号地の行が区画の表に無い");

        return $this->balancedElement($html, $start, 'tr');
    }

    /** 区画の表の号地を、上から並んだ順に */
    private function tableLotNumbers(string $html): array
    {
        preg_match_all('/<tr id="lot-row-\d+"[^>]*>\s*<td[^>]*>\s*(\d+)号地\s*<\/td>/u', $html, $m);

        return $m[1];
    }

    /**
     * その区画の行の編集のフォームが開いているか（Alpine の editing）。閉じている行は Alpine の起動前も隠す（x-cloak）、
     * 開いている行は最初から見える（x-cloak が無い）。
     */
    private function assertEditOpen(string $html, ReProcurementLot $lot, bool $open): void
    {
        $start = strpos($html, '<tbody id="lot-body-' . $lot->id . '"');
        $this->assertNotFalse($start, "{$lot->lot_number}号地の区画のまとまり（tbody）が無い");
        $body = $this->balancedElement($html, $start, 'tbody');
        $openTag = substr($body, 0, strpos($body, '>') + 1);
        $this->assertStringContainsString('x-data="{ editing: ' . ($open ? 'true' : 'false') . ' }"', $openTag, "{$lot->lot_number}号地の編集のフォームの開き方が違う");

        $rowStart = strpos($body, '<tr id="lot-edit-row-' . $lot->id . '"');
        $this->assertNotFalse($rowStart, "{$lot->lot_number}号地の編集の行が無い");
        $rowTag = substr($body, $rowStart, strpos($body, '>', $rowStart) - $rowStart + 1);
        $this->assertStringContainsString('x-show="editing"', $rowTag);
        $open
            ? $this->assertStringNotContainsString('x-cloak', $rowTag, '開いている編集の行が Alpine の起動前に隠れる')
            : $this->assertStringContainsString('x-cloak', $rowTag, '閉じている編集の行が Alpine の起動前に見える');
    }

    // ================================================================
    // 区画の登録
    // ================================================================

    public function test_a_lot_is_registered_from_the_detail_screen_with_the_land_values(): void
    {
        $procurement = $this->landProcurement();
        $url = $this->detailUrl($procurement);

        $detail = $this->htmlOf($url);
        $this->assertLotSubmitOnce($detail, self::CREATE, '登録しています…');
        $form = $this->parseForm($detail, self::CREATE);
        $this->assertSame('new', $form['fields']['lot_form']);
        $this->assertSame('1', $form['fields']['lot_number']);
        $this->assertSame('132.69', $form['fields']['area_sqm']);
        $this->assertSame('25000000', $form['fields']['selling_price']);
        $this->assertSame('on_sale', $form['fields']['status']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertLotNotice($html, 'success', '区画を登録しました。');
        $lot = ReProcurementLot::where('procurement_id', $procurement->id)->sole();
        $row = $this->row($html, $lot);
        $this->assertStringContainsString('132.69㎡（40.13坪）', $row);
        $this->assertStringContainsString('25,000,000円', $row);
        $this->assertStringContainsString('@62.3', $row);
        $this->assertStringContainsString('badge-lot-onsale', $row);
    }

    public function test_the_create_form_defaults(): void
    {
        // 区画 0: 号地 1・土地面積・想定販売価格（土地）・販売中
        $first = $this->landProcurement();
        $form = $this->parseForm($this->htmlOf($this->detailUrl($first)), self::CREATE);
        $this->assertSame(
            ['1', '132.69', '25000000', 'on_sale'],
            [$form['fields']['lot_number'], $form['fields']['area_sqm'], $form['fields']['selling_price'], $form['fields']['status']]
        );

        // 区画 2 つ（号地 1・5）: いちばん大きい号地＋1・面積と販売価格は空・販売中
        // （区画を選んでいない契約などがある土地はステータスの欄を出さない＝ProcurementLotLinkScreensTest）
        $second = $this->landProcurement(['procurement_code' => 'RE-PRC-002']);
        $this->procurementLot($second, ['lot_number' => 5]);
        $this->procurementLot($second, ['lot_number' => 1]);

        $form = $this->parseForm($this->htmlOf($this->detailUrl($second)), self::CREATE);

        $this->assertSame(
            ['6', '', '', 'on_sale'],
            [$form['fields']['lot_number'], $form['fields']['area_sqm'], $form['fields']['selling_price'], $form['fields']['status']]
        );
    }

    public function test_a_second_and_third_lot_can_be_added(): void
    {
        $procurement = $this->landProcurement();
        $this->procurementLot($procurement, ['lot_number' => 5]);
        $url = $this->detailUrl($procurement);

        $form = $this->fill($this->parseForm($this->htmlOf($url), self::CREATE), [
            'lot_number' => '2', 'area_sqm' => '100.00', 'selling_price' => '20000000',
        ]);
        $html = $this->landed($this->submit($form, $url));
        $this->assertLotNotice($html, 'success', '区画を登録しました。');

        $form = $this->parseForm($html, self::CREATE);
        $this->assertSame('6', $form['fields']['lot_number']);
        $html = $this->landed($this->submit($this->fill($form, ['area_sqm' => '80.50']), $url));

        $this->assertLotNotice($html, 'success', '区画を登録しました。');
        $this->assertSame(3, ReProcurementLot::where('procurement_id', $procurement->id)->count());
        $this->assertSame(['2', '5', '6'], $this->tableLotNumbers($html));
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

    public function test_a_duplicate_lot_number_is_refused_on_create_and_update(): void
    {
        $procurement = $this->landProcurement();
        $lot1 = $this->procurementLot($procurement, ['lot_number' => 1]);
        $lot2 = $this->procurementLot($procurement, ['lot_number' => 2]);
        // ほかの土地の 3 号地は重なりに数えない
        $other = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '別の土地']);
        $this->procurementLot($other, ['lot_number' => 3]);
        $url = $this->detailUrl($procurement);

        // 登録: 2 号地を足す → 断る・入力が残る
        $form = $this->fill($this->parseForm($this->htmlOf($url), self::CREATE), ['lot_number' => '2', 'area_sqm' => '50.00', 'notes' => '重複']);
        $response = $this->submit($form, $url);
        $response->assertRedirect($url . '#lot');
        $html = $this->landed($response);

        $this->assertErrorItem($html, self::DUPLICATE);
        $this->assertSame(2, ReProcurementLot::where('procurement_id', $procurement->id)->count());
        $again = $this->parseForm($html, self::CREATE);
        $this->assertSame(['2', '50.00', '重複'], [$again['fields']['lot_number'], $again['fields']['area_sqm'], $again['fields']['notes']]);
        $this->assertEditOpen($html, $lot1, false);
        $this->assertEditOpen($html, $lot2, false);

        // 更新: 1 号地を 2 号地にする → 断る・1 号地の行のフォームが開いたまま・入力が残る
        $form = $this->fill($this->parseForm($html, $this->editForm($lot1)), ['lot_number' => '2', 'notes' => '角地']);
        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, self::DUPLICATE);
        $this->assertEditOpen($html, $lot1, true);
        $this->assertEditOpen($html, $lot2, false);
        $again = $this->parseForm($html, $this->editForm($lot1));
        $this->assertSame(['2', '角地'], [$again['fields']['lot_number'], $again['fields']['notes']]);
        // ほかの行・追加のフォームには、この入力を描かない
        $this->assertSame('2', $this->parseForm($html, $this->editForm($lot2))['fields']['lot_number']);
        $this->assertSame('3', $this->parseForm($html, self::CREATE)['fields']['lot_number']);
        $this->assertSame(1, $lot1->fresh()->lot_number);

        // ほかの土地にある号地なら登録できる
        $form = $this->fill($this->parseForm($this->htmlOf($url), self::CREATE), ['area_sqm' => '50.00']);
        $this->assertSame('3', $form['fields']['lot_number']);
        $this->assertLotNotice($this->landed($this->submit($form, $url)), 'success', '区画を登録しました。');
        $this->assertSame(3, ReProcurementLot::where('procurement_id', $procurement->id)->count());
    }

    /** 手で組んだ配列の送信（Bug #121）: 断って何も保存せず、戻った画面も開く（lot_form も入力チェックに入れてある） */
    public function test_array_values_are_refused_without_breaking_the_screen(): void
    {
        $procurement = $this->landProcurement();
        $lot = $this->procurementLot($procurement);
        $url = $this->detailUrl($procurement);

        $html = $this->tamper($url, route('realestate.procurements.lots.store', $procurement), ['lot_form' => ['new']]);
        $this->assertErrorItem($html, trans('validation.string', ['attribute' => '区画のフォーム']));
        $this->assertSame(1, ReProcurementLot::count());

        $html = $this->tamper($url, route('realestate.procurements.lots.update', [$procurement, $lot]), ['lot_number' => ['2']]);
        $this->assertErrorItem($html, trans('validation.integer', ['attribute' => '号地番号']));
        $this->assertEditOpen($html, $lot, true);
        $this->assertSame(1, $lot->fresh()->lot_number);
    }

    public function test_a_lot_cannot_be_added_to_a_procurement_that_is_not_land(): void
    {
        $procurement = $this->procurement();
        $url = $this->detailUrl($procurement);
        $this->assertStringNotContainsString('id="lot"', $this->htmlOf($url));

        $response = $this->actingAs($this->user)->from($url)->post(route('realestate.procurements.lots.store', $procurement), [
            'lot_form' => 'new', 'lot_number' => '1', 'area_sqm' => '100', 'selling_price' => '1000000', 'status' => 'on_sale',
        ]);
        $html = $this->landed($response);

        // 土地でない案件の詳細には区画の欄が無いので、断りは上の帯に出し、#lot へは戻さない
        $response->assertRedirect($url);
        $this->assertFlash($html, 'error', '区画を登録できるのは、物件種別が土地の仕入れ案件だけです。');
        $this->assertSame(0, ReProcurementLot::count());
    }

    public function test_the_only_one_message_is_gone(): void
    {
        $this->assertFalse(defined(ProcurementLotController::class . '::ONLY_ONE'), '「1 つまで」の断りの文が残っている');

        $procurement = $this->landProcurement();
        $this->procurementLot($procurement);

        $html = $this->htmlOf($this->detailUrl($procurement));

        // 1 つ目があっても追加のフォームを出す
        $this->assertStringContainsString(self::CREATE, $html);
        foreach (['1 つまで', '2 つ目', '1 案件に 1 区画'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $html);
        }
    }

    // ================================================================
    // 区画の編集
    // ================================================================

    public function test_the_lot_is_edited_from_the_detail_screen(): void
    {
        $procurement = $this->landProcurement();
        $lot = $this->procurementLot($procurement);
        $url = $this->detailUrl($procurement);

        $detail = $this->htmlOf($url);
        $this->assertLotSubmitOnce($detail, $this->editForm($lot), '更新しています…');
        $form = $this->parseForm($detail, $this->editForm($lot));
        $this->assertSame((string) $lot->id, $form['fields']['lot_form']);
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
        $form = $this->parseForm($html, $this->editForm($lot));
        $this->assertBrowserSubmits($html, $this->editForm($lot), $form['fields']);

        $landed = $this->landed($this->submit($form, $url));

        $this->assertLotNotice($landed, 'success', '区画を更新しました。');
        $lot->refresh();
        $this->assertSame([1, '132.69', 25000000, 'on_sale', '角地'], [$lot->lot_number, $lot->area_sqm, $lot->selling_price, $lot->status->value, $lot->notes]);
    }

    public function test_an_input_error_reopens_the_edit_form_with_the_input(): void
    {
        $procurement = $this->landProcurement();
        $lot = $this->procurementLot($procurement);
        $url = $this->detailUrl($procurement);
        $this->assertEditOpen($this->htmlOf($url), $lot, false);

        $form = $this->fill($this->parseForm($this->htmlOf($url), $this->editForm($lot)), ['area_sqm' => '', 'notes' => '角地になりました']);
        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '面積（㎡）']));
        $this->assertEditOpen($html, $lot, true);
        $again = $this->parseForm($html, $this->editForm($lot));
        $this->assertSame('角地になりました', $again['fields']['notes']);
        $this->assertSame('132.69', ReProcurementLot::first()->area_sqm);
    }

    public function test_lot_changes_resync_the_procurement_status(): void
    {
        $procurement = $this->landProcurement(['status' => 'selling']);
        $lot1 = $this->procurementLot($procurement, ['lot_number' => 1, 'status' => 'on_sale']);
        $lot2 = $this->procurementLot($procurement, ['lot_number' => 2, 'status' => 'sold']);
        $url = $this->detailUrl($procurement);

        // 全区画を成約にする → 販売済
        $html = $this->landed($this->submit($this->fill($this->parseForm($this->htmlOf($url), $this->editForm($lot1)), ['status' => 'sold']), $url));
        $this->assertLotNotice($html, 'success', '区画を更新しました。');
        $this->assertSame('sold', $procurement->fresh()->status->value);

        // 1 つを販売中に戻す → 販売中
        $html = $this->landed($this->submit($this->fill($this->parseForm($html, $this->editForm($lot2)), ['status' => 'on_sale']), $url));
        $this->assertSame('selling', $procurement->fresh()->status->value);

        // 成約でない区画を削除する → 残りが全部成約なので販売済
        $html = $this->landed($this->submit($this->parseForm($html, $this->deleteForm($lot2)), $url));
        $this->assertLotNotice($html, 'success', '区画を削除しました。');
        $this->assertNull(ReProcurementLot::find($lot2->id));
        $this->assertSame('sold', $procurement->fresh()->status->value);

        // 販売中の区画を足す → 販売中
        $form = $this->fill($this->parseForm($html, self::CREATE), ['area_sqm' => '60.00']);
        $this->assertSame('on_sale', $form['fields']['status']);
        $html = $this->landed($this->submit($form, $url));
        $this->assertLotNotice($html, 'success', '区画を登録しました。');
        $this->assertSame('selling', $procurement->fresh()->status->value);
    }

    // ================================================================
    // 区画の削除・紐づいているもの
    // ================================================================

    public function test_a_linked_lot_cannot_be_deleted(): void
    {
        $procurement = $this->landProcurement();
        $lot = $this->procurementLot($procurement);
        $contract = $this->contractFor($procurement, $lot);
        $url = $this->detailUrl($procurement);

        $html = $this->htmlOf($url);
        $this->assertStringContainsString(self::DELETE_CONFIRM, $this->formSlice($html, $this->deleteForm($lot)));
        $response = $this->submit($this->parseForm($html, $this->deleteForm($lot)), $url);
        $response->assertRedirect($url . '#lot');
        $landed = $this->landed($response);

        $this->assertLotNotice($landed, 'error', '契約 1 件が紐づいているため、この区画は削除できません。');
        $this->assertNotNull(ReProcurementLot::find($lot->id));
        $this->assertSame($lot->id, $contract->fresh()->procurement_lot_id);
    }

    public function test_a_lot_held_only_by_an_estimate_order_cannot_be_deleted(): void
    {
        $procurement = $this->landProcurement();
        $lot = $this->procurementLot($procurement);
        $order = $this->order($procurement, $lot, 'estimation');
        $url = $this->detailUrl($procurement);

        $landed = $this->landed($this->submit($this->parseForm($this->htmlOf($url), $this->deleteForm($lot)), $url));

        $this->assertLotNotice($landed, 'error', '注文住宅 1 件が紐づいているため、この区画は削除できません。');
        $this->assertNotNull(ReProcurementLot::find($lot->id));
        $this->assertSame($lot->id, $order->fresh()->re_procurement_lot_id);
    }

    public function test_the_table_shows_what_is_linked(): void
    {
        $procurement = $this->landProcurement();
        $lot1 = $this->procurementLot($procurement, ['lot_number' => 1]);
        $lot2 = $this->procurementLot($procurement, ['lot_number' => 2]);
        $lot3 = $this->procurementLot($procurement, ['lot_number' => 3]);
        $lot4 = $this->procurementLot($procurement, ['lot_number' => 4]);
        $contract = $this->contractFor($procurement, $lot1);
        $building = $this->building($procurement, $lot2);
        $order = $this->order($procurement, $lot3, 'estimation');

        $html = $this->htmlOf($this->detailUrl($procurement));

        $links = [
            $lot1->id => ['url' => route('realestate.contracts.show', $contract), 'name' => '勝山の土地（山田 太郎 様）', 'label' => '契約'],
            $lot2->id => ['url' => route('housing.properties.show', $building), 'name' => 'HS-001 勝山の建売', 'label' => '建売物件'],
            $lot3->id => ['url' => route('housing.custom-orders.show', $order), 'name' => 'CO-001 勝山の注文住宅', 'label' => '注文住宅'],
        ];
        foreach ([$lot1, $lot2, $lot3, $lot4] as $lot) {
            $row = $this->row($html, $lot);
            foreach ($links as $lotId => $link) {
                $anchor = '/<a\b[^>]*href="' . preg_quote(e($link['url']), '/') . '"[^>]*>\s*' . preg_quote(e($link['name']), '/') . '\s*<\/a>/u';
                $this->assertSame(
                    $lotId === $lot->id ? 1 : 0,
                    preg_match($anchor, $row),
                    "{$lot->lot_number}号地の行の「{$link['name']}」へのリンクが違う"
                );
            }
            if (isset($links[$lot->id])) {
                $this->assertStringContainsString($links[$lot->id]['label'], $row);
            }
        }
        // 何も紐づいていない区画の行にはリンクが無い
        $this->assertSame(0, preg_match('/<a\b/', $this->row($html, $lot4)));
    }

    public function test_a_lot_of_another_procurement_is_not_found(): void
    {
        $procurement = $this->landProcurement();
        $mine = $this->procurementLot($procurement, ['notes' => 'この土地の区画']);
        $other = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '別の土地']);
        $theirs = $this->procurementLot($other, ['notes' => 'ほかの土地の区画']);
        $url = $this->detailUrl($procurement);
        $html = $this->htmlOf($url);

        // 更新: 自分の画面のフォームの送り先だけ、ほかの土地の区画の id にする
        $form = $this->fill($this->parseForm($html, $this->editForm($mine)), ['notes' => '書き換え']);
        $form['action'] = route('realestate.procurements.lots.update', [$procurement, $theirs]);
        $response = $this->submit($form, $url);
        $response->assertRedirect($url . '#lot');
        $this->assertLotNotice($this->landed($response), 'error', self::NOT_FOUND);

        // 削除: 同じく
        $form = $this->parseForm($html, $this->deleteForm($mine));
        $form['action'] = route('realestate.procurements.lots.destroy', [$procurement, $theirs]);
        $response = $this->submit($form, $url);
        $response->assertRedirect($url . '#lot');
        $this->assertLotNotice($this->landed($response), 'error', self::NOT_FOUND);

        // 無い区画・数でない区画
        foreach (['999999', 'abc'] as $lotId) {
            $response = $this->actingAs($this->user)->from($url)->put(
                route('realestate.procurements.lots.update', ['procurement' => $procurement->id, 'lot' => $lotId]),
                ['lot_form' => $lotId, 'lot_number' => '9', 'area_sqm' => '10', 'status' => 'on_sale']
            );
            $response->assertRedirect($url . '#lot');
            $this->assertLotNotice($this->landed($response), 'error', self::NOT_FOUND);
        }

        $this->assertSame(['この土地の区画', 'ほかの土地の区画'], [$mine->fresh()->notes, $theirs->fresh()->notes]);
        $this->assertSame(2, ReProcurementLot::count());

        // 仕入れ案件そのものが無い → 一覧へ
        foreach (['999999', 'abc'] as $procurementId) {
            $response = $this->actingAs($this->user)->from($url)->delete(
                route('realestate.procurements.lots.destroy', ['procurement' => $procurementId, 'lot' => $mine->id])
            );
            $response->assertRedirect(route('realestate.procurements.index'));
            $this->assertFlash($this->landed($response), 'error', ProcurementToProjectConverter::GONE);
        }
        $response = $this->actingAs($this->user)->from($url)->post(
            route('realestate.procurements.lots.store', ['procurement' => '999999']),
            ['lot_form' => 'new', 'lot_number' => '9', 'area_sqm' => '10', 'status' => 'on_sale']
        );
        $response->assertRedirect(route('realestate.procurements.index'));
        $this->assertSame(2, ReProcurementLot::count());

        // 土地でない案件には区画の欄が無いので、断りは上の帯に出す
        $house = $this->procurement(['procurement_code' => 'RE-PRC-003']);
        $houseUrl = $this->detailUrl($house);
        $response = $this->actingAs($this->user)->from($houseUrl)->delete(
            route('realestate.procurements.lots.destroy', [$house->id, $mine->id])
        );
        $response->assertRedirect($houseUrl);
        $this->assertFlash($this->landed($response), 'error', self::NOT_FOUND);
        $this->assertNotNull(ReProcurementLot::find($mine->id));
    }

    // ================================================================
    // 権限
    // ================================================================

    public function test_a_manager_edits_but_cannot_delete(): void
    {
        $procurement = $this->landProcurement();
        $lot = $this->procurementLot($procurement);
        $this->user = $this->userWithRole(UserRole::Manager);
        $url = $this->detailUrl($procurement);

        $html = $this->htmlOf($url);
        $this->assertStringContainsString($this->editForm($lot), $html);
        $this->assertStringContainsString(self::CREATE, $html);
        $this->assertStringNotContainsString($this->deleteForm($lot), $html);
        $this->assertSame(0, preg_match('/<form\b[^>]*action="' . preg_quote(route('realestate.procurements.lots.destroy', [$procurement, $lot]), '/') . '"[^>]*>\s*(?:<input[^>]*>\s*)*<input[^>]*name="_method"[^>]*value="DELETE"/s', $html), '削除のフォームが出ている');

        $this->actingAs($this->user)->delete(route('realestate.procurements.lots.destroy', [$procurement, $lot]))->assertForbidden();
        $this->assertSame(1, ReProcurementLot::count());
    }

    public function test_staff_only_sees_the_lot(): void
    {
        $procurement = $this->landProcurement();
        $lot = $this->procurementLot($procurement);
        $this->user = $this->userWithRole(UserRole::Staff);
        $url = $this->detailUrl($procurement);

        $html = $this->htmlOf($url);
        $this->assertStringContainsString('id="lot"', $html);
        $this->assertStringContainsString('132.69㎡（40.13坪）', $this->row($html, $lot));
        foreach ([self::CREATE, $this->editForm($lot), $this->deleteForm($lot), 'id="lot-edit-row-' . $lot->id . '"'] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }

        $this->actingAs($this->user)->post(route('realestate.procurements.lots.store', $procurement), [
            'lot_form' => 'new', 'lot_number' => '2', 'area_sqm' => '100', 'status' => 'on_sale',
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

    // ================================================================
    // 物件種別の固定
    // ================================================================

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
