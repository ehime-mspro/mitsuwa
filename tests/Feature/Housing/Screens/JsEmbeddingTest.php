<?php

namespace Tests\Feature\Housing\Screens;

/**
 * 住宅事業の画面が保存した値・入力エラーで戻った値を、画面の JavaScript に正しく渡す（H3。Bug #76 と同じ形）。
 *
 * ⚠ `'{{ $値 }}'` と JS の文字列に書くと、`{{ }}` が `'` を `&#039;`・`&` を `&amp;` にする。<script> の中では実体参照は戻らないので、
 *   入力欄に `M&amp;M&#039;ビル` と出て、保存するとその文字のまま DB に入る。属性の中ではブラウザが実体参照を戻すので `'` で文字列が閉じる。
 *   どちらも末尾が `\` だと、次の `'` を飲み込んで画面の JS が丸ごと止まる。
 * ⚠ ここは画面の JS を node で実際に組み立て、入力欄に入る値（＝送る値）が元の文字のままかを見る。
 */
class JsEmbeddingTest extends HousingScreenTestCase
{
    private const TRICKY = "松山市平井町1-2 M&M'ビル \"東\"\\";

    public function test_the_property_form_keeps_the_saved_address_as_it_is(): void
    {
        $property = $this->property(['address' => self::TRICKY, 'land_cost' => 5000000, 'is_land_cost_manual' => true]);
        $url = route('housing.properties.edit', $property);

        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('housing.properties.update', $property) . '"', 'housingPropertyForm');
        $this->assertSame([self::TRICKY, '5000000', '1'], [$form['fields']['address'], $form['fields']['land_cost'], $form['fields']['is_land_cost_manual']]);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '建売物件「HS-001」を更新しました。');
        $this->assertSame(self::TRICKY, $property->fresh()->address, '保存すると所在地が実体参照の文字で書き換わる');
    }

    public function test_the_property_form_keeps_the_typed_address_after_an_input_error(): void
    {
        $url = route('housing.properties.create');
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('housing.properties.store') . '"', 'housingPropertyForm', 'data.address = ' . json_encode(self::TRICKY) . ';');

        // 物件名を書き忘れて送る
        $html = $this->landed($this->submit($form, $url));

        $again = $this->browserForm($html, 'action="' . route('housing.properties.store') . '"', 'housingPropertyForm');
        $this->assertSame(self::TRICKY, $again['fields']['address'], '入力エラーで戻ると所在地が変わる');
    }

    public function test_the_custom_order_form_keeps_the_saved_customer_name_and_address(): void
    {
        $order = $this->customOrder(['address' => self::TRICKY, 'customer_name' => "O'Brien & 山田\\"]);
        $url = route('housing.custom-orders.edit', $order);

        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('housing.custom-orders.update', $order) . '"', 'customOrderForm');
        $this->assertSame([self::TRICKY, "O'Brien & 山田\\"], [$form['fields']['address'], $form['fields']['customer_name']]);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を更新しました。');
        $this->assertSame([self::TRICKY, "O'Brien & 山田\\"], [$order->fresh()->address, $order->fresh()->customer_name]);
    }

    public function test_the_contract_edit_screens_survive_a_typed_value_after_an_input_error(): void
    {
        $contract = $this->contract($this->property(), $this->buyer());
        $url = route('housing.contracts.edit-building', $contract);
        // 契約日に `'` と `\` の入った値を送り、入力エラーで戻す（手で組んだ送信と同じ）
        $this->actingAs($this->user)->from($url)->put(route('housing.contracts.update-building', $contract), ['contract_date' => "2026-09-01'\\"]);
        $html = $this->htmlOf($url);

        $run = $this->driveAlpine($html, 'datePicker', $this->xData($html, 'datePicker'), '', [], true, ['selected === null']);
        $this->assertSame([false], $run['evaluated'], '入力エラーで戻った契約日が画面の JS を壊す');

        $order = $this->customOrder(['status' => 'contracted', 'contract_date' => '2026-09-10', 'customer_id' => $this->buyer('佐藤', '花子')->id]);
        $url = route('housing.contracts.edit-custom-order', $order);
        $this->actingAs($this->user)->from($url)->put(route('housing.contracts.update-custom-order', $order), ['land_source_type' => 'project_lot', 're_project_lot_id' => "1'\\"]);
        $html = $this->htmlOf($url);

        $run = $this->driveAlpine($html, 'customOrderEditForm', $this->xData($html, 'customOrderEditForm'), '', [], true, ['landSourceType', 'reProjectLotId']);
        $this->assertSame(['project_lot', "1'\\"], $run['evaluated'], '入力エラーで戻った値が画面の JS に元の文字のまま渡らない');
    }
}
