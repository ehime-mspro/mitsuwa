<?php

namespace Tests\Feature\Housing\Screens;

use App\Models\HsProperty;
use App\Models\ReProcurement;
use App\Models\ReProcurementLot;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 土地を変えられない編集画面（建売の編集・契約済みの注文住宅の編集・契約一覧から開く契約済みの注文住宅の契約の編集）は、
 * 画面を開いたあとで区画を足してつないだ（Bug #146）ときも、その画面からそのまま保存できる
 * （設計書 docs/superpowers/specs/2026-11-23-lot-link-followups-design.md の B3・利用者の決定 案 1）。
 *
 * 直す前は、開いた画面が区画を空で送るので「区画を選んでください。」「土地の紐付けは変えられません」で断られていた
 * （利用者は何もしていない。開き直せば保存できた）。
 *
 * ⚠ どのテストも「区画の無い土地に区画を選んでいない持ち主 → 編集画面のフォームを先に組む → 不動産の詳細の追加のフォームで
 *   区画を足す（その持ち主につながる）→ 先に組んだフォームを送る」の順（送る値は描いた画面から。Bug #47）。
 */
class StaleLotLinkEditTest extends HousingScreenTestCase
{
    /** 不動産の詳細の区画の追加のフォームをそのまま送る（候補が 1 件なら自動でつながる）。足した区画を返す */
    private function addLotFromDetail(ReProcurement $land): ReProcurementLot
    {
        $url = route('realestate.procurements.show', $land);
        $this->submit($this->parseForm($this->htmlOf($url), 'id="lot-create-form"'), $url)->assertRedirect($url . '#lot');

        return ReProcurementLot::where('procurement_id', $land->id)->sole();
    }

    private function propertyEditForm(HsProperty $property): array
    {
        return $this->browserForm($this->htmlOf(route('housing.properties.edit', $property)),
            'action="' . route('housing.properties.update', $property) . '"', 'housingPropertyForm');
    }

    /** 区画を選んでいない建売（土地原価は自動で 30,000,000 円） */
    private function lotlessProperty(ReProcurement $land): HsProperty
    {
        return $this->property([
            'land_source_type' => 'procurement', 're_procurement_id' => $land->id,
            'is_land_cost_manual' => false, 'land_cost' => 30000000,
        ]);
    }

    public function test_a_stale_property_edit_saves_and_keeps_the_linked_lot(): void
    {
        $land = $this->landProcurement();
        $property = $this->lotlessProperty($land);
        $url = route('housing.properties.edit', $property);
        $form = $this->fill($this->propertyEditForm($property), ['notes' => '外構は別途']);

        $lot = $this->addLotFromDetail($land);
        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '建売物件「HS-001」を更新しました。');
        $property->refresh();
        $this->assertSame([$lot->id, '外構は別途', 30000000], [(int) $property->re_procurement_lot_id, $property->notes, $property->land_cost]);
    }

    public function test_a_stale_edit_of_a_sold_property_saves(): void
    {
        $land = $this->landProcurement();
        $property = $this->lotlessProperty($land);
        $this->contract($property, $this->buyer());
        $url = route('housing.properties.edit', $property);
        $form = $this->fill($this->propertyEditForm($property), ['notes' => '外構は別途']);

        $lot = $this->addLotFromDetail($land);
        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '建売物件「HS-001」を更新しました。');
        $this->assertSame([$lot->id, '外構は別途'], [(int) $property->fresh()->re_procurement_lot_id, $property->fresh()->notes]);
        $this->assertSame('sold', $lot->fresh()->status->value);
    }

    public function test_a_stale_edit_of_a_contracted_order_saves(): void
    {
        $land = $this->landProcurement();
        $order = $this->customOrder(['status' => 'contracted', 'land_source_type' => 'procurement', 're_procurement_id' => $land->id]);
        $url = route('housing.custom-orders.edit', $order);
        $form = $this->fill($this->customOrderEditForm($order), ['notes' => '外構は別途']);

        $lot = $this->addLotFromDetail($land);
        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を更新しました。');
        $this->assertSame([$lot->id, '外構は別途'], [(int) $order->fresh()->re_procurement_lot_id, $order->fresh()->notes]);
        $this->assertSame('sold', $lot->fresh()->status->value);
    }

    public function test_a_stale_contract_edit_of_a_contracted_order_saves(): void
    {
        $land = $this->landProcurement();
        $order = $this->customOrder([
            'status' => 'contracted', 'land_source_type' => 'procurement', 're_procurement_id' => $land->id,
            'contract_date' => '2026-09-10', 'building_contract_price' => 25000000, 'building_cost' => 20000000,
        ]);
        $url = route('housing.contracts.edit-custom-order', $order);
        $form = $this->fill($this->composedForm($this->htmlOf($url), route('housing.contracts.update-custom-order', $order), 'customOrderEditForm', null,
            ['datePicker' => '', 'buyerSelect' => '']), ['building_contract_price' => '26000000']);

        $lot = $this->addLotFromDetail($land);
        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約を更新しました。');
        $this->assertSame([$lot->id, 26000000], [(int) $order->fresh()->re_procurement_lot_id, $order->fresh()->building_contract_price]);
    }

    /** 見積りの注文住宅は区画を選べる画面なので補わない（区画を空にすると今どおり断る） */
    public function test_an_estimate_order_that_clears_its_lot_is_still_refused(): void
    {
        $land = $this->landProcurement();
        $lot = $this->procurementLot($land);
        $order = $this->customOrder(['status' => 'estimation', 'land_source_type' => 'procurement', 're_procurement_id' => $land->id, 're_procurement_lot_id' => $lot->id]);
        $url = route('housing.custom-orders.edit', $order);
        $form = $this->fill($this->customOrderEditForm($order), ['re_procurement_lot_id' => '', 'notes' => '外構は別途']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, '区画を選んでください。');
        $this->assertSame([$lot->id, null], [(int) $order->fresh()->re_procurement_lot_id, $order->fresh()->notes]);
    }

    public static function keptCases(): array
    {
        return [
            '同じ土地・区画が null'     => [['procurement', 'A', null], true],
            '同じ土地・区画が空の文字'  => [['procurement', 'A', ''], true],
            '同じ土地（先頭の 0）'       => [['procurement', '0A', null], true],
            'ほかの土地'                 => [['procurement', 'B', null], false],
            '区画を送った'               => [['procurement', 'A', 'L2'], false],
            '区画が配列'                 => [['procurement', 'A', ['x']], false],
            '分譲地区画の種別'           => [['project_lot', 'A', null], false],
            'お客様の土地の種別'         => [['customer_land', 'A', null], false],
            '種別が空'                   => [[null, 'A', null], false],
        ];
    }

    /** 補うのは、送られた区画が空で、種別が仕入れ案件・仕入れ案件が保存済みと同じときだけ（Review Focus 3） */
    #[DataProvider('keptCases')]
    public function test_lot_kept_for_only_fills_an_empty_lot_on_the_same_land(array $sent, bool $kept): void
    {
        $land = $this->landProcurement();
        $other = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '別の土地']);
        $lot = $this->procurementLot($land);
        $second = $this->procurementLot($land, ['lot_number' => 2]);
        $property = $this->property(['land_source_type' => 'procurement', 're_procurement_id' => $land->id, 're_procurement_lot_id' => $lot->id]);

        [$type, $procurement, $lotId] = $sent;
        $input = [
            'land_source_type' => $type,
            're_procurement_id' => match ($procurement) { 'A' => (string) $land->id, '0A' => '0' . $land->id, 'B' => (string) $other->id },
            're_procurement_lot_id' => $lotId === 'L2' ? (string) $second->id : $lotId,
        ];

        $this->assertSame($kept ? $lot->id : null, $property->fresh()->lotKeptFor($input));
    }

    /** 保存済みの種別が仕入れ案件でない（区画の id が残っているだけ）なら補わない（種別を仕入れ案件へ替える送信に区画を足さない） */
    public function test_lot_kept_for_is_null_when_the_saved_type_is_not_procurement(): void
    {
        $land = $this->landProcurement();
        $lot = $this->procurementLot($land);
        $property = $this->property(['land_source_type' => 'customer_land', 're_procurement_id' => $land->id, 're_procurement_lot_id' => $lot->id]);

        $this->assertNull($property->lotKeptFor(['land_source_type' => 'procurement', 're_procurement_id' => (string) $land->id, 're_procurement_lot_id' => null]));
    }

    /** 保存済みの区画が無い（区画の無い土地の建売＝#21 の形）なら、何を送っても補わない */
    public function test_lot_kept_for_is_null_without_a_saved_lot(): void
    {
        $land = $this->landProcurement();
        $property = $this->property(['land_source_type' => 'procurement', 're_procurement_id' => $land->id]);

        $this->assertNull($property->lotKeptFor(['land_source_type' => 'procurement', 're_procurement_id' => (string) $land->id, 're_procurement_lot_id' => null]));
    }
}
