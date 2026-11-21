<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReContract;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 土地の仕入れ案件の区画を、一覧・契約（登録・編集・詳細）・住宅事業（建売・注文住宅の詳細）に出す。
 */
class LandLotDisplayTest extends RealEstateScreenTestCase
{
    private const LOT_LABEL = '1号地 132.69㎡（40.13坪） 販売価格 25,000,000円';

    /** @return array<string, array{0: ?string, 1: string, 2: string}> */
    public static function landRows(): array
    {
        return [
            '区画なし' => [null, '0', '0'],
            '販売中' => ['on_sale', '0', '1'],
            '成約' => ['sold', '1', '1'],
        ];
    }

    #[DataProvider('landRows')]
    public function test_a_land_row_shows_lot_counts_and_the_lot_button(?string $lotStatus, string $sold, string $total): void
    {
        $land = $this->landProcurement();
        if ($lotStatus !== null) {
            $this->procurementLot($land, ['status' => $lotStatus]);
        }

        $html = $this->htmlOf('/realestate/procurements');

        $this->assertMatchesRegularExpression(
            '/data-lot-count[^>]*>\s*区画\s*<span[^>]*>' . $sold . '<\/span>\s*\/\s*' . $total . '/u',
            $html
        );
        $this->assertStringContainsString('href="' . route('realestate.procurements.show', $land->id) . '#lot"', $html);
    }

    public function test_only_land_and_project_rows_have_the_lot_subline(): void
    {
        $this->landProcurement();
        $this->procurement(['procurement_code' => 'RE-PRC-002', 'property_type' => 'used_house', 'property_name' => '中古の家']);
        $this->project();

        $html = $this->htmlOf('/realestate/procurements');

        $this->assertSame(2, substr_count($html, 'data-lot-count'));
    }

    /** 契約の登録画面: 選んだ土地の区画を区画の選択欄に出す（区画の無い土地では欄ごと隠れている） */
    public function test_the_contract_registration_shows_the_lot_of_the_chosen_land(): void
    {
        $withLot = $this->landProcurement();
        $this->procurementLot($withLot);
        $noLot = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '区画なしの土地']);
        $html = $this->htmlOf(route('realestate.contracts.create'));

        $run = $this->driveAlpine($html, 'contractForm', 'contractForm()', "data.procurementId = '{$withLot->id}';", evaluate: ['data.lotOptions().map(function (o) { return o.label; })']);
        $this->assertSame([[self::LOT_LABEL . ' 販売中']], $run['evaluated']);

        $run = $this->driveAlpine($html, 'contractForm', 'contractForm()', "data.procurementId = '{$noLot->id}';", evaluate: ['data.hasLotOptions()']);
        $this->assertSame([false], $run['evaluated']);
        $this->assertSame(1, preg_match('/<div[^>]*x-show="hasLotOptions\(\)"[^>]*style="display: none;"/u', $html), '区画の欄が最初から隠れていない');
    }

    /** 契約の編集・詳細は契約の区画を出す（仕入れ案件の区画ではない。区画の無い契約は区画を出さない） */
    public function test_the_contract_edit_and_detail_show_the_lot(): void
    {
        $land = $this->landProcurement();
        $lot = $this->procurementLot($land);
        $contract = ReContract::create([
            'department' => 'realestate', 'contract_type' => 'procurement_land', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => '勝山の土地', 'procurement_id' => $land->id, 'procurement_lot_id' => $lot->id, 'buyer_id' => $this->buyer()->id,
            'contract_amount_land' => 25000000, 'cost_amount' => 20000000, 'created_by' => $this->user->id,
        ]);

        $edit = $this->htmlOf(route('realestate.contracts.edit', $contract));
        $run = $this->driveAlpine($edit, 'contractEditForm', 'contractEditForm()', '', evaluate: ['data.procurementLotId', 'data.lotOptions().map(function (o) { return o.label; })']);
        $this->assertSame([(string) $lot->id, [self::LOT_LABEL . ' 販売中']], $run['evaluated']);

        $show = $this->htmlOf(route('realestate.contracts.show', $contract));
        $this->assertStringContainsString('区画 ' . self::LOT_LABEL, $show);

        // 区画のある土地でも、区画の無い契約（#21 の形）は区画を出さない
        $contract->update(['procurement_lot_id' => null]);
        $this->assertStringNotContainsString('区画 ' . self::LOT_LABEL, $this->htmlOf(route('realestate.contracts.show', $contract)));
    }

    public function test_the_housing_screens_show_the_lot_area_of_the_land(): void
    {
        $land = $this->landProcurement();
        $lot = $this->procurementLot($land);
        $noLot = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '区画なしの土地']);

        // 建売・注文住宅はそれぞれの区画を出す（仕入れ案件の区画ではない。区画の無い建売・注文住宅は区画を出さない）
        $property = HsProperty::create([
            'property_code' => 'HS-001', 'property_name' => '平井 建売 1', 'status' => 'design', 'address' => '愛媛県松山市平井町1-3',
            'land_source_type' => 'procurement', 're_procurement_id' => $land->id, 're_procurement_lot_id' => $lot->id, 'created_by' => $this->user->id,
        ]);
        $order = HsCustomOrder::create([
            'order_code' => 'CO-001', 'order_name' => '山田邸 新築工事', 'status' => 'consultation', 'customer_name' => '山田 太郎',
            'address' => '愛媛県松山市平井町1-2', 'tax_rate' => 10,
            'land_source_type' => 'procurement', 're_procurement_id' => $land->id, 're_procurement_lot_id' => $lot->id, 'created_by' => $this->user->id,
        ]);

        $this->assertStringContainsString('区画 1号地 132.69㎡（40.13坪）', $this->htmlOf(route('housing.properties.show', $property)));
        $this->assertStringContainsString('区画 1号地 132.69㎡（40.13坪）', $this->htmlOf(route('housing.custom-orders.show', $order)));

        $property->update(['re_procurement_lot_id' => null]);
        $this->assertStringNotContainsString('区画 1号地', $this->htmlOf(route('housing.properties.show', $property)), '区画のある土地の区画の無い建売に区画を出している');
        $order->update(['re_procurement_lot_id' => null]);
        $this->assertStringNotContainsString('区画 1号地', $this->htmlOf(route('housing.custom-orders.show', $order)), '区画のある土地の区画の無い注文住宅に区画を出している');
        $property->update(['re_procurement_id' => $noLot->id]);
        $order->update(['re_procurement_id' => $noLot->id]);
        $this->assertStringNotContainsString('区画 1号地', $this->htmlOf(route('housing.properties.show', $property)));
        $this->assertStringNotContainsString('区画 1号地', $this->htmlOf(route('housing.custom-orders.show', $order)));
    }
}
