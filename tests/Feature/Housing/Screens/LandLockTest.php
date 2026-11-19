<?php

namespace Tests\Feature\Housing\Screens;

use App\Models\HsCustomOrder;

/**
 * 契約のある建売・注文住宅の土地の紐付け（種別・区画・仕入れ案件）は変えさせない（判断待ち ④・案 B）。
 *
 * 契約のあとで区画を変えると、前の区画は販売済のまま・新しい区画は販売中のまま＝同じ区画を 2 回売れる形になる。
 * ⚠ 今と同じ紐付けなら通す（ほかの欄だけ直す保存・何も変えない保存は通る。Bug #130 の形を作らない）。
 * ⚠ 判定はモデルの部品 LocksLandAfterContract の 1 か所。建売は契約の行があるか・注文住宅は保存済みのステータスが「契約」以降か。
 */
class LandLockTest extends HousingScreenTestCase
{
    // ============================================================
    // 判定の部品
    // ============================================================

    public function test_a_property_without_a_contract_never_refuses(): void
    {
        $lot = $this->lot($this->project(), 2);
        $property = $this->property(['land_source_type' => 'project_lot', 're_project_lot_id' => $lot->id]);

        $this->assertFalse($property->refusesLandChange(['land_source_type' => 'procurement', 're_procurement_id' => '9']));
    }

    public function test_a_property_with_a_contract_refuses_only_a_different_link(): void
    {
        $project = $this->project();
        $a = $this->lot($project, 2, 12000000, 'sold');
        $b = $this->lot($project, 4);
        $property = $this->property(['land_source_type' => 'project_lot', 're_project_lot_id' => $a->id]);
        $this->contract($property, $this->buyer());
        $procurement = $this->procurement();

        foreach ([
            ['land_source_type' => 'project_lot', 're_project_lot_id' => (string) $a->id],
            ['land_source_type' => 'project_lot', 're_project_lot_id' => $a->id],
            ['land_source_type' => 'project_lot', 're_project_lot_id' => '0' . $a->id],
            // 使わない欄は見ない
            ['land_source_type' => 'project_lot', 're_project_lot_id' => (string) $a->id, 're_procurement_id' => '9'],
        ] as $same) {
            $this->assertFalse($property->refusesLandChange($same), '今と同じ紐付けを断った: ' . json_encode($same));
        }

        foreach ([
            ['land_source_type' => 'project_lot', 're_project_lot_id' => (string) $b->id],
            ['land_source_type' => 'procurement', 're_procurement_id' => (string) $procurement->id],
            [],
            ['land_source_type' => ''],
            ['land_source_type' => 'customer_land'],
        ] as $other) {
            $this->assertTrue($property->refusesLandChange($other), '違う紐付けを通した: ' . json_encode($other));
        }
    }

    public function test_leftover_ids_of_the_saved_link_are_ignored(): void
    {
        $lot = $this->lot($this->project(), 2);
        $order = $this->customOrder(['status' => 'contracted', 'land_source_type' => 'customer_land', 're_project_lot_id' => $lot->id]);

        $this->assertFalse($order->refusesLandChange(['land_source_type' => 'customer_land']));
    }

    public function test_a_custom_order_is_locked_by_its_saved_status(): void
    {
        $project = $this->project();
        $a = $this->lot($project, 2);
        $b = $this->lot($project, 4);
        $other = ['land_source_type' => 'project_lot', 're_project_lot_id' => (string) $b->id];

        foreach (['consultation', 'design', 'estimation'] as $status) {
            $order = $this->customOrder(['order_code' => 'CO-' . $status, 'status' => $status, 'land_source_type' => 'project_lot', 're_project_lot_id' => $a->id]);
            $this->assertFalse($order->refusesLandChange($other), "{$status} の注文住宅の土地を固定した");
        }
        foreach (['contracted', 'construction', 'completed', 'delivered'] as $status) {
            $order = $this->customOrder(['order_code' => 'CO-' . $status, 'status' => $status, 'land_source_type' => 'project_lot', 're_project_lot_id' => $a->id]);
            $this->assertTrue($order->refusesLandChange($other), "{$status} の注文住宅の土地を固定していない");
        }

        // 保存済みのステータスで見る（保存していない値では固定しない）
        $order = HsCustomOrder::where('order_code', 'CO-estimation')->firstOrFail();
        $order->status = 'contracted';
        $this->assertFalse($order->refusesLandChange($other), '保存していないステータスで土地を固定した');
    }
}
