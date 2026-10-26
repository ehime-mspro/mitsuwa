<?php

namespace Tests\Feature\RealEstate\Screens;

/**
 * 仕入れ案件の登録・編集の画面が、入力エラーで戻った値を画面の JavaScript に正しく渡す（Bug #76 と同じ形）。
 *
 * ⚠ `'{{ old(…) }}'` と JS の文字列に書くと、`{{ }}` が `'` を `&#039;`・`&` を `&amp;` にする。<script> の中では実体参照は戻らないので、
 *   入力欄に `10&#039;` と出る。末尾が `\` だと、次の `'` を飲み込んで画面の JS が丸ごと止まり、消費税率・金額の欄が空で描かれる。
 * ⚠ 消費税率は文字の欄（type="text"）なので、打ち間違いでそのまま入力エラーになる。金額の欄（type="number"）と物件種別（選択欄）は
 *   ブラウザが数・選択肢しか送らないので、手で組んだ送信で戻したときだけ。
 * ⚠ ここは画面の JS を node で実際に組み立て、入力欄に入る値（＝送る値）が元の文字のままかを見る。
 */
class ProcurementFormJsTest extends RealEstateScreenTestCase
{
    private const TRICKY_TAX_RATE = "10'\"&<\\";

    private const SUPPLIER_PICKER = ['function supplierPicker('];

    private function form(string $html, string $action, string $steps = ''): array
    {
        return $this->browserForm($html, 'action="' . $action . '"', 'procurementForm', $steps, [], null, self::SUPPLIER_PICKER);
    }

    public function test_the_registration_screen_keeps_a_typed_tax_rate_after_an_input_error(): void
    {
        $url = route('realestate.procurements.create');
        $steps = 'data.propertyType = "used_house"; data.taxRate = ' . json_encode(self::TRICKY_TAX_RATE) . ';'
            . ' data.purchaseLand = "18000000"; data.purchaseBuildingExcl = "2000000";';
        $form = $this->formWithCostSection($this->htmlOf($url), route('realestate.procurements.store'), 'procurementForm', $steps);
        $form = $this->fill($form, ['transaction_type' => 'purchase', 'status' => 'info_obtained', 'property_name' => '道後の家', 'address' => '愛媛県松山市道後町1-1']);
        $this->assertSame(self::TRICKY_TAX_RATE, $form['fields']['tax_rate']);

        $html = $this->landed($this->submit($form, $url));
        $this->assertErrorItem($html, trans('validation.numeric', ['attribute' => '消費税率']));

        $again = $this->formWithCostSection($html, route('realestate.procurements.store'), 'procurementForm', '');
        $this->assertSame(
            [self::TRICKY_TAX_RATE, 'used_house', '18000000', '2000000'],
            [$again['fields']['tax_rate'], $again['fields']['property_type'], $again['fields']['purchase_price_land'], $again['fields']['purchase_price_building']],
            '入力エラーで戻ると、消費税率が変わるか、画面の JS が止まって金額の欄が空になる',
        );
    }

    public function test_the_edit_screen_keeps_a_typed_tax_rate_after_an_input_error(): void
    {
        $procurement = $this->procurement(['purchase_price_land' => 18000000, 'purchase_price_building' => 2000000]);
        $url = route('realestate.procurements.edit', $procurement);
        $action = route('realestate.procurements.update', $procurement);
        $form = $this->form($this->htmlOf($url), $action, 'data.taxRate = ' . json_encode(self::TRICKY_TAX_RATE) . ';');

        $html = $this->landed($this->submit($form, $url));
        $this->assertErrorItem($html, trans('validation.numeric', ['attribute' => '消費税率']));

        $again = $this->form($html, $action);
        $this->assertSame(
            [self::TRICKY_TAX_RATE, '18000000', '2000000'],
            [$again['fields']['tax_rate'], $again['fields']['purchase_price_land'], $again['fields']['purchase_price_building']],
            '入力エラーで戻ると、消費税率が変わるか、画面の JS が止まって金額の欄が空になる',
        );
    }

    public function test_values_sent_back_by_a_hand_made_request_are_not_run_as_code(): void
    {
        $procurement = $this->procurement();
        $url = route('realestate.procurements.edit', $procurement);
        $action = route('realestate.procurements.update', $procurement);
        // 物件種別と金額の欄（JS に書く 7 つ。消費税率のほか）に `'` と `\` の入った値を送り、入力エラーで戻す
        // （画面からは送れない。手で組んだ送信と同じ）。値は欄ごとに変え、どの欄の書き方を戻しても落ちるようにする
        $sent = [
            'property_type'                 => ['propertyType', "used_house'\\"],
            'assessment_price_land'         => ['assessmentLand', "1, taxRate: 'injected'"],
            'assessment_price_building'     => ['assessmentBuildingExcl', "2'\\"],
            'purchase_price_land'           => ['purchaseLand', "3&<'\\"],
            'purchase_price_building'       => ['purchaseBuildingExcl', "4\"'\\"],
            'target_selling_price_land'     => ['targetSellingLand', "5'\\"],
            'target_selling_price_building' => ['targetSellingBuildingExcl', "6'\\"],
        ];
        $form = $this->form($this->htmlOf($url), $action);
        foreach ($sent as $name => [, $value]) {
            $form['fields'][$name] = $value;
        }

        $html = $this->landed($this->submit($form, $url));

        $run = $this->driveAlpine($html, 'procurementForm', 'procurementForm()', '', [], true,
            [...array_column($sent, 0), 'taxRate'], self::SUPPLIER_PICKER);
        $this->assertSame(
            [...array_column($sent, 1), '10.00'],
            $run['evaluated'],
            '戻した値が画面の JS に元の文字のまま渡らないか、JS のコードとして動いた',
        );
    }
}
