<?php

namespace Tests\Feature\Housing\Screens;

use App\Models\HsContract;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;

/**
 * 住宅事業の契約の横断画面（建売・注文住宅の契約の詳細と編集）を、描いた画面から送る往復で見る。
 *
 * ⚠ 編集の 2 画面はフォームの中に日付（datePicker）・買主の選択（buyerSelect）が入れ子のコンポーネントとしてある。
 *   composedForm() で入れ子ごとに評価して足す（ブラウザは 1 つのフォームとして送る）。
 */
class ContractScreensTest extends HousingScreenTestCase
{
    /** 建売の契約の編集画面のフォームの x-data（フォームのタグに書いたインラインの式） */
    private function formFactory(string $html, string $action): string
    {
        $found = preg_match('/action="' . preg_quote($action, '/') . '"[^>]*\bx-data="(\{[^"]*\})"/', $html, $m);
        $this->assertSame(1, $found, 'フォームのインラインの x-data が無い');

        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    private function soldProperty(): array
    {
        $project = $this->project();
        $lot = $this->lot($project, 3, 9000000, 'sold');
        $property = $this->property(['land_source_type' => 'project_lot', 're_project_lot_id' => $lot->id, 'building_cost' => 15000000]);
        $buyer = $this->buyer();

        return [$property, $this->contract($property, $buyer), $buyer];
    }

    public function test_a_building_contract_is_shown_and_edited_from_the_screens(): void
    {
        [$property, $contract] = $this->soldProperty();
        $other = $this->buyer('佐藤', '花子');
        $show = $this->htmlOf(route('housing.contracts.show-building', $contract));
        $this->assertTrue(str_contains($show, 'href="' . route('housing.contracts.edit-building', $contract) . '"'), '詳細に編集の入口が無い');

        $url = route('housing.contracts.edit-building', $contract);
        $action = route('housing.contracts.update-building', $contract);
        $html = $this->htmlOf($url);
        $form = $this->composedForm($html, $action, null, $this->formFactory($html, $action),
            ['datePicker' => '', 'buyerSelect' => $this->chooseBuyer($other)]);
        $this->assertSame(['PUT', '2026-09-01', (string) $other->id, '佐藤 花子', '10000000', '0'],
            [$form['fields']['_method'], $form['fields']['contract_date'], $form['fields']['customer_id'], $form['fields']['customer_name'], $form['fields']['selling_price_land'], $form['fields']['is_land_cost_manual']],
            '編集画面に今の値が入っていない');
        $form = $this->fill($form, ['selling_price_building' => '22000000', 'building_cost' => '16000000']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約を更新しました。');
        $contract->refresh();
        $this->assertSame([$other->id, '佐藤 花子', 22000000, $this->user->id], [$contract->customer_id, $contract->customer_name, $contract->selling_price_building, $contract->updated_by]);
        $this->assertSame(16000000, $property->fresh()->building_cost);
    }

    public function test_a_custom_order_contract_is_shown_and_edited_from_the_screens(): void
    {
        $project = $this->project();
        $lot = $this->lot($project, 2, 9000000, 'sold');
        $buyer = $this->buyer();
        $order = $this->customOrder([
            'status' => 'contracted', 'contract_date' => '2026-09-10', 'customer_id' => $buyer->id,
            'land_source_type' => 'project_lot', 're_project_lot_id' => $lot->id,
            'building_contract_price' => 25000000, 'building_cost' => 20000000,
        ]);
        $show = $this->htmlOf(route('housing.contracts.show-custom-order', $order));
        $this->assertTrue(str_contains($show, 'href="' . route('housing.contracts.edit-custom-order', $order) . '"'), '詳細に編集の入口が無い');

        $url = route('housing.contracts.edit-custom-order', $order);
        $form = $this->composedForm($this->htmlOf($url), route('housing.contracts.update-custom-order', $order), 'customOrderEditForm', null,
            ['datePicker' => '', 'buyerSelect' => '']);
        $this->assertSame(['PUT', 'project_lot', (string) $lot->id, '2026-09-10', (string) $buyer->id, '山田 太郎'],
            [$form['fields']['_method'], $form['fields']['land_source_type'], $form['fields']['re_project_lot_id'], $form['fields']['contract_date'], $form['fields']['customer_id'], $form['fields']['customer_name']],
            '編集画面に今の値が入っていない');
        $form = $this->fill($form, ['building_contract_price' => '26000000']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約を更新しました。');
        $order->refresh();
        $this->assertSame([26000000, $lot->id, $this->user->id], [$order->building_contract_price, $order->re_project_lot_id, $order->updated_by]);
    }

    public function test_a_contract_input_error_lists_the_reasons(): void
    {
        [, $contract] = $this->soldProperty();
        $url = route('housing.contracts.edit-building', $contract);
        $action = route('housing.contracts.update-building', $contract);
        $html = $this->htmlOf($url);
        $form = $this->composedForm($html, $action, null, $this->formFactory($html, $action), ['datePicker' => '', 'buyerSelect' => '']);
        $form = $this->fill($form, ['selling_price_land' => '']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '土地販売価格']));
        $this->assertSame(10000000, $contract->fresh()->selling_price_land);
    }
}
