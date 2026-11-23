<?php

namespace Tests\Feature\Housing\Screens;

use App\Models\HsProperty;
use App\Models\ReProcurementLot;

/**
 * 契約のある建売を削除したら、その区画（土地の区画・分譲地の区画）を販売中に戻す
 * （設計書 docs/superpowers/specs/2026-11-23-lot-link-followups-design.md の A）。
 *
 * 建売の削除は契約をデータベースの連鎖削除で消すので、契約の削除（Housing\ContractController::destroy）の
 * 区画を戻す処理が動かず、区画が「成約」のまま残っていた（注文住宅の削除は戻していた）。
 *
 * ⚠ 削除は建売の詳細画面が描いた削除のフォーム（DELETE）を送る（Bug #47）。
 */
class PropertyDeleteLotReleaseTest extends HousingScreenTestCase
{
    /** 詳細画面の建売の削除のフォームを送る（契約の削除のフォームとは送り先で分ける） */
    private function deleteFromDetail(HsProperty $property): \Illuminate\Testing\TestResponse
    {
        $show = route('housing.properties.show', $property);
        $form = $this->parseForm($this->htmlOf($show), 'action="' . route('housing.properties.destroy', $property) . '"');
        $this->assertSame('DELETE', $form['method']);

        return $this->submit($form, $show);
    }

    /** 区画 1 つが成約の土地（販売済）と、その区画の建売 ＋ 建売の契約 */
    private function soldLandProperty(): array
    {
        $land = $this->landProcurement(['status' => 'sold']);
        $lot = $this->procurementLot($land, ['status' => 'sold']);
        $property = $this->property(['land_source_type' => 'procurement', 're_procurement_id' => $land->id, 're_procurement_lot_id' => $lot->id]);
        $this->contract($property, $this->buyer());

        return [$land, $lot, $property];
    }

    public function test_deleting_a_sold_property_puts_its_land_lot_back_on_sale(): void
    {
        [$land, $lot, $property] = $this->soldLandProperty();

        $this->deleteFromDetail($property)->assertRedirect(route('housing.properties.index'));

        $this->assertNull(HsProperty::find($property->id));
        $this->assertSame(['on_sale', 'selling'], [$lot->fresh()->status->value, $land->fresh()->status->value]);
    }

    public function test_deleting_a_sold_property_puts_its_project_lot_back_on_sale(): void
    {
        $project = $this->project(['status' => 'sold_out']);
        $lot = $this->lot($project, 1, 12000000, 'sold');
        $property = $this->property(['land_source_type' => 'project_lot', 're_project_lot_id' => $lot->id]);
        $this->contract($property, $this->buyer());

        $this->deleteFromDetail($property)->assertRedirect(route('housing.properties.index'));

        $this->assertSame(['on_sale', 'selling'], [$lot->fresh()->status->value, $project->fresh()->status->value]);
    }

    /** 契約の無い建売の削除は今のまま（区画のステータスは動かさない） */
    public function test_deleting_a_property_without_a_contract_leaves_the_lot_status(): void
    {
        $land = $this->landProcurement(['status' => 'sold']);
        $lot = $this->procurementLot($land, ['status' => 'sold']);
        $property = $this->property(['land_source_type' => 'procurement', 're_procurement_id' => $land->id, 're_procurement_lot_id' => $lot->id]);

        $this->deleteFromDetail($property)->assertRedirect(route('housing.properties.index'));

        $this->assertSame(['sold', 'sold'], [$lot->fresh()->status->value, $land->fresh()->status->value]);
    }

    /** 削除の途中で落ちたら、区画も販売中に戻らない（区画を戻すことと削除は 1 つのトランザクション） */
    public function test_a_failed_delete_leaves_the_lot_sold(): void
    {
        [$land, $lot, $property] = $this->soldLandProperty();
        HsProperty::deleting(fn () => throw new \RuntimeException('削除の途中で落ちた'));

        $this->withoutExceptionHandling();
        try {
            $this->deleteFromDetail($property);
            $this->fail('削除の途中の例外が投げられていない');
        } catch (\RuntimeException $e) {
            $this->assertSame('削除の途中で落ちた', $e->getMessage());
        }

        $this->assertNotNull(HsProperty::find($property->id));
        $this->assertSame(['sold', 'sold'], [$lot->fresh()->status->value, $land->fresh()->status->value]);
        $this->assertSame(1, ReProcurementLot::count());
    }
}
