<?php

namespace Tests\Feature\Housing\Screens;

use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReProcurementLot;

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

    // ============================================================
    // 建売の編集（画面はもとから土地を読み取り専用で出す。サーバーが送られた値を受け付けていた）
    // ============================================================

    /** 区画 A（販売済）に建つ契約のある建売と、別の区画 B（販売中） */
    private function soldProperty(): array
    {
        $project = $this->project();
        $a = $this->lot($project, 2, 12000000, 'sold');
        $b = $this->lot($project, 4);
        $property = $this->property(['land_source_type' => 'project_lot', 're_project_lot_id' => $a->id]);
        $this->contract($property, $this->buyer());

        return [$property, $a, $b];
    }

    private function propertyEditForm(HsProperty $property): array
    {
        return $this->browserForm($this->htmlOf(route('housing.properties.edit', $property)),
            'action="' . route('housing.properties.update', $property) . '"', 'housingPropertyForm');
    }

    public function test_a_sold_property_is_saved_as_shown(): void
    {
        [$property, $a] = $this->soldProperty();
        $url = route('housing.properties.edit', $property);
        $form = $this->fill($this->propertyEditForm($property), ['notes' => '外構は別途']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '建売物件「HS-001」を更新しました。');
        $property->refresh();
        $this->assertSame([$a->id, '外構は別途'], [$property->re_project_lot_id, $property->notes]);
    }

    public function test_a_sold_property_refuses_another_lot(): void
    {
        [$property, $a, $b] = $this->soldProperty();
        $url = route('housing.properties.edit', $property);
        $form = $this->propertyEditForm($property);
        // 画面には区画の選択が無い（読み取り専用）。手で組んだ送信・古い画面からの送信で区画を変える
        $form['fields']['re_project_lot_id'] = (string) $b->id;

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, HsProperty::LAND_LOCKED_BY_CONTRACT);
        $this->assertSame($a->id, $property->fresh()->re_project_lot_id);
        $this->assertSame(['sold', 'on_sale'], [$a->fresh()->status->value, $b->fresh()->status->value]);
    }

    public function test_a_sold_property_refuses_a_request_without_the_land_fields(): void
    {
        [$property, $a] = $this->soldProperty();
        $url = route('housing.properties.edit', $property);
        $form = $this->propertyEditForm($property);
        // 手で組んだ送信: 土地の欄が丸ごと無い（直す前は区画・仕入れ案件を空にして保存していた）
        unset($form['fields']['land_source_type'], $form['fields']['re_project_lot_id']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, HsProperty::LAND_LOCKED_BY_CONTRACT);
        $this->assertSame($a->id, $property->fresh()->re_project_lot_id);
    }

    public function test_a_sold_property_refuses_another_land_type(): void
    {
        [$property, $a] = $this->soldProperty();
        $procurement = $this->procurement();
        $url = route('housing.properties.edit', $property);
        $form = $this->propertyEditForm($property);
        // 手で組んだ送信: 種別を仕入れ案件に変える
        $form['fields']['land_source_type'] = 'procurement';
        $form['fields']['re_procurement_id'] = (string) $procurement->id;

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, HsProperty::LAND_LOCKED_BY_CONTRACT);
        $property->refresh();
        $this->assertSame(['project_lot', $a->id, null], [$property->land_source_type->value, $property->re_project_lot_id, $property->re_procurement_id]);
    }

    public function test_changing_only_the_procurement_lot_is_a_link_change(): void
    {
        $procurement = $this->procurement();
        $one = ReProcurementLot::create(['procurement_id' => $procurement->id, 'lot_number' => 1, 'area_sqm' => 100, 'selling_price' => 10000000, 'status' => 'sold']);
        $two = ReProcurementLot::create(['procurement_id' => $procurement->id, 'lot_number' => 2, 'area_sqm' => 100, 'selling_price' => 10000000, 'status' => 'on_sale']);
        $property = $this->property(['land_source_type' => 'procurement', 're_procurement_id' => $procurement->id, 're_procurement_lot_id' => $one->id]);
        $this->contract($property, $this->buyer());
        $same = ['land_source_type' => 'procurement', 're_procurement_id' => (string) $procurement->id, 're_procurement_lot_id' => (string) $one->id];

        $this->assertFalse($property->refusesLandChange($same));
        $this->assertTrue($property->refusesLandChange(array_merge($same, ['re_procurement_lot_id' => (string) $two->id])));
        $this->assertTrue($property->refusesLandChange(array_merge($same, ['re_procurement_lot_id' => ''])));

        $url = route('housing.properties.edit', $property);
        $form = $this->propertyEditForm($property);
        $form['fields']['re_procurement_lot_id'] = (string) $two->id;

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, HsProperty::LAND_LOCKED_BY_CONTRACT);
        $this->assertSame($one->id, $property->fresh()->re_procurement_lot_id);
    }

    public function test_the_lock_error_comes_with_the_other_errors(): void
    {
        [$property, , $b] = $this->soldProperty();
        $url = route('housing.properties.edit', $property);
        $form = $this->fill($this->propertyEditForm($property), ['property_name' => '']);
        $form['fields']['re_project_lot_id'] = (string) $b->id;

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '物件名']));
        $this->assertErrorItem($html, HsProperty::LAND_LOCKED_BY_CONTRACT);
    }

    public function test_a_property_without_a_contract_can_still_change_its_land_by_request(): void
    {
        $project = $this->project();
        $a = $this->lot($project, 2);
        $b = $this->lot($project, 4);
        $property = $this->property(['land_source_type' => 'project_lot', 're_project_lot_id' => $a->id]);
        $url = route('housing.properties.edit', $property);
        $form = $this->propertyEditForm($property);
        // 契約の無い建売は今どおり（画面は読み取り専用だが、サーバーは固定しない）
        $form['fields']['re_project_lot_id'] = (string) $b->id;

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '建売物件「HS-001」を更新しました。');
        $this->assertSame($b->id, $property->fresh()->re_project_lot_id);
    }

    // ============================================================
    // 注文住宅の編集（契約済みなら土地を読み取り専用で出し、保存でも断る）
    // ============================================================

    private const ORDER_NOTICE = '契約済みのため、土地の紐付け（種別・区画・仕入れ案件）は変えられません。変えるときは、ステータスを「見積り」以前に戻して保存してから変えてください。';

    /** 区画 A に建つ注文住宅と、同じ分譲地の区画 B（販売中） */
    private function orderOnLot(string $status): array
    {
        $project = $this->project();
        $a = $this->lot($project, 2, 12000000, in_array($status, ['consultation', 'design', 'estimation'], true) ? 'on_sale' : 'sold');
        $b = $this->lot($project, 4);
        $order = $this->customOrder(['status' => $status, 'land_source_type' => 'project_lot', 're_project_lot_id' => $a->id]);

        return [$order, $a, $b];
    }

    private function orderEditForm(HsCustomOrder $order, ?string $html = null, string $js = ''): array
    {
        return $this->customOrderEditForm($order, $js, $html);
    }

    public function test_a_contracted_order_shows_its_land_read_only_and_saves_as_shown(): void
    {
        [$order, $a] = $this->orderOnLot('contracted');
        $url = route('housing.custom-orders.edit', $order);
        $html = $this->htmlOf($url);

        $this->assertFalse(str_contains($html, 'type="radio" name="land_source_type"'), '契約済みなのに土地種別を選べる');
        $this->assertFalse(str_contains($html, 'name="re_project_lot_id" x-model'), '契約済みなのに区画を選べる');
        $this->assertTrue(str_contains($html, e(self::ORDER_NOTICE)), '土地を変えられない理由の案内が無い');
        $form = $this->orderEditForm($order, $html);
        $this->assertSame(['project_lot', (string) $a->id], [$form['fields']['land_source_type'], $form['fields']['re_project_lot_id'] ?? null]);
        $form = $this->fill($form, ['notes' => '外構は別途']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を更新しました。');
        $order->refresh();
        $this->assertSame([$a->id, '外構は別途', 'sold'], [$order->re_project_lot_id, $order->notes, $a->fresh()->status->value]);
    }

    public function test_a_contracted_order_refuses_another_lot_or_customer_land(): void
    {
        [$order, $a, $b] = $this->orderOnLot('contracted');
        $url = route('housing.custom-orders.edit', $order);

        // 手で組んだ送信・古い画面からの送信: 1) 区画を B に 2) お客様の土地に
        foreach ([['re_project_lot_id' => (string) $b->id], ['land_source_type' => 'customer_land']] as $tampered) {
            $form = $this->orderEditForm($order);
            $form['fields'] = array_merge($form['fields'], $tampered);

            $html = $this->landed($this->submit($form, $url));

            $this->assertErrorItem($html, HsCustomOrder::LAND_LOCKED_BY_CONTRACT);
            $order->refresh();
            $this->assertSame(['project_lot', $a->id], [$order->land_source_type->value, $order->re_project_lot_id], json_encode($tampered));
            $this->assertSame(['sold', 'on_sale'], [$a->fresh()->status->value, $b->fresh()->status->value], json_encode($tampered));
            $this->flushSession();
        }
    }

    public function test_turning_the_status_back_and_changing_the_land_at_once_is_refused(): void
    {
        [$order, $a, $b] = $this->orderOnLot('contracted');
        $url = route('housing.custom-orders.edit', $order);
        $form = $this->fill($this->orderEditForm($order), ['status' => 'estimation']);
        // 手で組んだ送信: 同じ保存でステータスを戻しながら区画を変える（区画の連動は新しい区画を販売中にし、前の区画が販売済のまま残る）
        $form['fields']['re_project_lot_id'] = (string) $b->id;

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, HsCustomOrder::LAND_LOCKED_BY_CONTRACT);
        $order->refresh();
        $this->assertSame(['contracted', $a->id], [$order->status->value, $order->re_project_lot_id]);
    }

    public function test_turning_the_status_back_releases_the_lot_and_unlocks_the_land(): void
    {
        [$order, $a, $b] = $this->orderOnLot('contracted');
        $url = route('housing.custom-orders.edit', $order);
        $form = $this->fill($this->orderEditForm($order), ['status' => 'estimation']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を更新しました。');
        $this->assertSame('on_sale', $a->fresh()->status->value, 'ステータスを戻しても区画が販売中に戻らない');

        $html = $this->htmlOf($url);
        $this->assertTrue(str_contains($html, 'type="radio" name="land_source_type"'), '契約前に戻したのに土地を選べない');
        $form = $this->orderEditForm($order, $html, 'data.selectedLotId = "' . $b->id . '"; data.onLotChange();');

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を更新しました。');
        $this->assertSame($b->id, $order->fresh()->re_project_lot_id);
    }

    public function test_an_order_before_the_contract_still_changes_its_land(): void
    {
        [$order, , $b] = $this->orderOnLot('estimation');
        $url = route('housing.custom-orders.edit', $order);
        $form = $this->orderEditForm($order, null, 'data.selectedLotId = "' . $b->id . '"; data.onLotChange();');

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を更新しました。');
        $this->assertSame($b->id, $order->fresh()->re_project_lot_id);
    }

    // ============================================================
    // 契約一覧から開く注文住宅の契約の編集（同じく固定。区画の販売済をまったく動かさない画面）
    // ============================================================

    private const CONTRACT_NOTICE = '契約済みのため、土地の紐付け（種別・区画・仕入れ案件）は変えられません。変えるときは、注文住宅の画面でステータスを「見積り」以前に戻して保存してから変えてください。';

    /** 契約の編集の画面が保存に要る項目（契約日・買主・金額）を持つ注文住宅 */
    private function orderForContractEdit(array $overrides): HsCustomOrder
    {
        return $this->customOrder(array_merge([
            'contract_date' => '2026-09-10', 'customer_id' => $this->buyer()->id,
            'building_contract_price' => 25000000, 'building_cost' => 20000000,
        ], $overrides));
    }

    private function contractEditForm(HsCustomOrder $order, string $html, string $js = ''): array
    {
        return $this->composedForm($html, route('housing.contracts.update-custom-order', $order), 'customOrderEditForm', null,
            ['datePicker' => '', 'buyerSelect' => ''], $js);
    }

    public function test_the_contract_edit_shows_the_land_read_only_and_saves_as_shown(): void
    {
        $project = $this->project();
        $a = $this->lot($project, 2, 9000000, 'sold');
        $order = $this->orderForContractEdit(['status' => 'contracted', 'land_source_type' => 'project_lot', 're_project_lot_id' => $a->id]);
        $url = route('housing.contracts.edit-custom-order', $order);
        $html = $this->htmlOf($url);

        $this->assertFalse(str_contains($html, 'type="radio" name="land_source_type"'), '契約済みなのに土地種別を選べる');
        $this->assertFalse(str_contains($html, 'name="re_project_lot_id" class="hc-select"'), '契約済みなのに区画を選べる');
        $this->assertTrue(str_contains($html, e(self::CONTRACT_NOTICE)), '土地を変えられない理由の案内が無い');
        $form = $this->contractEditForm($order, $html);
        $this->assertSame(['project_lot', (string) $a->id], [$form['fields']['land_source_type'], $form['fields']['re_project_lot_id'] ?? null]);
        $form = $this->fill($form, ['building_contract_price' => '26000000']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約を更新しました。');
        $order->refresh();
        $this->assertSame([26000000, $a->id], [$order->building_contract_price, $order->re_project_lot_id]);
    }

    public function test_the_contract_edit_refuses_another_land(): void
    {
        $project = $this->project();
        $a = $this->lot($project, 2, 9000000, 'sold');
        $b = $this->lot($project, 4);
        $order = $this->orderForContractEdit(['status' => 'contracted', 'land_source_type' => 'project_lot', 're_project_lot_id' => $a->id]);
        $url = route('housing.contracts.edit-custom-order', $order);
        $form = $this->contractEditForm($order, $this->htmlOf($url));
        // 手で組んだ送信・古い画面からの送信で区画を変える
        $form['fields']['re_project_lot_id'] = (string) $b->id;

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, HsCustomOrder::LAND_LOCKED_BY_CONTRACT);
        $this->assertSame($a->id, $order->fresh()->re_project_lot_id);
    }

    public function test_the_contract_edit_saves_a_contracted_order_without_a_land_type(): void
    {
        $order = $this->orderForContractEdit(['status' => 'contracted']);
        $url = route('housing.contracts.edit-custom-order', $order);
        $form = $this->contractEditForm($order, $this->htmlOf($url));
        $this->assertSame('', $form['fields']['land_source_type'] ?? null, '種別が空の注文住宅の画面が種別を送っていない');

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約を更新しました。');
        $this->assertNull($order->fresh()->land_source_type);
    }

    public function test_the_contract_edit_of_an_order_without_a_land_type_survives_a_request_without_the_field(): void
    {
        $order = $this->orderForContractEdit(['status' => 'contracted']);
        $url = route('housing.contracts.edit-custom-order', $order);
        $form = $this->contractEditForm($order, $this->htmlOf($url));
        // 手で組んだ送信: 種別の欄が丸ごと無い（保存済みも空なので固定の判定は通る。読み方が欄の有無を前提にすると 500）
        unset($form['fields']['land_source_type']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約を更新しました。');
        $this->assertNull($order->fresh()->land_source_type);
    }

    public function test_the_contract_edit_does_not_write_a_link_for_an_order_without_a_land_type(): void
    {
        $order = $this->orderForContractEdit(['status' => 'contracted']);
        $procurement = $this->procurement();
        $url = route('housing.contracts.edit-custom-order', $order);
        $form = $this->contractEditForm($order, $this->htmlOf($url));
        // 手で組んだ送信: 種別を送らずに仕入れ案件の紐付けだけを送る（種別が空どうしなので固定の判定は通る）
        unset($form['fields']['land_source_type']);
        $form['fields']['re_procurement_id'] = (string) $procurement->id;

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約を更新しました。');
        $order->refresh();
        $this->assertSame([null, null], [$order->land_source_type, $order->re_procurement_id], '契約済みの注文住宅に、種別が空のまま仕入れ案件の紐付けが書かれた');
    }

    public function test_the_contract_edit_of_an_order_before_the_contract_can_change_the_land(): void
    {
        $order = $this->orderForContractEdit(['status' => 'estimation', 'land_source_type' => 'procurement', 're_procurement_id' => $this->procurement()->id]);
        $url = route('housing.contracts.edit-custom-order', $order);
        $form = $this->contractEditForm($order, $this->htmlOf($url), 'data.landSourceType = "customer_land";');

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約を更新しました。');
        $order->refresh();
        $this->assertSame(['customer_land', null], [$order->land_source_type->value, $order->re_procurement_id]);
    }
}
