<?php

namespace Tests\Feature\Tenant\Screens;

use App\Enums\RepairStatus;
use App\Models\Repair;

/**
 * 修繕の登録・削除（tenant.repairs.store / destroy）を、描いた画面から送る往復で見る。
 * 登録画面の区画の選択肢は JS（インラインの x-data）が物件で絞って描く（`<template x-for>`）。
 */
class RepairScreensTest extends TenantScreenTestCase
{
    private function storeForm(string $html, string $steps): array
    {
        $needle = 'action="' . route('tenant.repairs.store') . '"';

        return $this->browserForm($html, $needle, null, $steps, [], $this->inlineXDataAround($html, $needle));
    }

    /** 画面の区画の選択肢（JS が物件で絞ったもの）に $unitId が出ている */
    private function assertUnitOffered(array $form, int $unitId): void
    {
        $this->assertContains($unitId, array_column($form['run']['state']['filteredUnits'], 'id'), '区画が選択肢に出ていない');
    }

    public function test_a_repair_of_a_unit_is_registered_from_the_screen(): void
    {
        $unit = $this->unit(1, 'A');
        $url = route('tenant.repairs.create');
        $html = $this->htmlOf($url);
        $form = $this->storeForm($html, "data.propertyId = '{$this->building->id}';");
        $this->assertUnitOffered($form, $unit->id);
        $form = $this->fill($form, [
            'unit_id' => (string) $unit->id, 'status' => 'in_progress', 'category' => 'aircon',
            'description' => 'エアコンの水漏れ', 'cost' => '45000', 'started_at' => '2026-10-01',
        ]);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '修繕を登録しました。');
        $repair = Repair::where('description', 'エアコンの水漏れ')->firstOrFail();
        $this->assertSame($unit->id, $repair->unit_id);
        $this->assertSame('in_progress', $repair->status->value);
        $this->assertSame('aircon', $repair->category);
        $this->assertSame(45000, $repair->cost);
    }

    public function test_a_common_area_repair_is_registered_from_the_screen(): void
    {
        $url = route('tenant.repairs.create');
        $form = $this->storeForm($this->htmlOf($url), "data.propertyId = '{$this->building->id}';");
        $this->assertSame('', $form['fields']['unit_id'], '区画の既定が「共用部」でない');
        $form = $this->fill($form, ['description' => '外壁の塗装']);

        $this->landed($this->submit($form, $url));

        $this->assertNull(Repair::where('description', '外壁の塗装')->firstOrFail()->unit_id);
    }

    public function test_the_chosen_unit_stays_selected_after_an_input_error(): void
    {
        $unit = $this->unit(1, 'A');
        $url = route('tenant.repairs.create');
        $html = $this->htmlOf($url);
        // 修繕内容を書き忘れて送る
        $form = $this->storeForm($html, "data.propertyId = '{$this->building->id}';");
        $this->assertUnitOffered($form, $unit->id);
        $form = $this->fill($form, ['unit_id' => (string) $unit->id]);

        $html = $this->landed($this->submit($form, $url));

        $this->assertInputError($html, '修繕内容は必須です。');
        $again = $this->storeForm($html, '');
        $this->assertSame((string) $this->building->id, $again['fields']['property_id']);
        $this->assertSame((string) $unit->id, $again['fields']['unit_id'], '入力エラーで戻ると区画が「共用部」に戻る');
    }

    public function test_a_repair_is_deleted_from_the_confirmation(): void
    {
        $repair = Repair::create(['property_id' => $this->building->id, 'unit_id' => null, 'status' => RepairStatus::Planned->value, 'description' => '雨どい']);
        $url = route('tenant.repairs.show', $repair);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('tenant.repairs.destroy', $repair) . '"');
        $this->assertSame('DELETE', $form['method']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '修繕を削除しました。');
        $this->assertSoftDeleted($repair);
    }
}
