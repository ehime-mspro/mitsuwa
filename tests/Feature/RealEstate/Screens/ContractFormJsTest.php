<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\ReContract;
use Tests\Concerns\SendsValuesBack;

/**
 * 不動産の契約の登録・編集の画面が、入力エラーで戻った値（old()）を画面の JavaScript に元の文字のまま渡す（Bug #76 と同じ形）。
 *
 * ⚠ `'{{ old(…) }}'` と JS の文字列に書くと、`{{ }}` が `'` を `&#039;`・`&` を `&amp;` にする。<script> の中では実体参照は戻らないので
 *   文字が変わり、末尾が `\` だと次の `'` を飲み込んで画面の JS が丸ごと止まる。
 * ⚠ どの欄も画面からは選択肢・数しか送れない。手で組んだ送信で戻す（送り方と値の決まりは SendsValuesBack）。
 *   値は欄ごとに変え、どの欄の書き方を戻しても落ちるようにする。
 */
class ContractFormJsTest extends RealEstateScreenTestCase
{
    use SendsValuesBack;

    private const CHANGED = '入力エラーで戻った値が画面の JS に元の文字のまま渡らないか、画面の JS が止まった';

    public function test_the_registration_screen_keeps_the_returned_values(): void
    {
        $sent = [
            'contract_type'            => ['contractType', self::tricky('種別')],
            'procurement_id'           => ['procurementId', self::tricky('仕入れ')],
            'project_id'               => ['projectId', self::tricky('分譲地')],
            'lot_id'                   => ['lotId', self::tricky('区画')],
            'contract_amount_land'     => ['amountLand', self::tricky('土地')],
            'contract_amount_building' => ['amountBuildingExcl', self::tricky('建物')],
            'tax_amount'               => ['taxAmount', self::tricky('消費税')],
            'cost_amount'              => ['costAmount', self::tricky('原価')],
        ];
        $url = route('realestate.contracts.create');

        $html = $this->sendBack($url, route('realestate.contracts.store'), array_map(fn ($s) => $s[1], $sent));

        $this->assertSame(array_column($sent, 1), $this->evaluateJs($html, 'contractForm', array_column($sent, 0)), self::CHANGED);
    }

    /** 編集画面を開き、$sent の欄を書き換えて送り、戻った画面の JS の値が送った文字のままか */
    private function assertEditScreenKeeps(ReContract $contract, array $sent): void
    {
        $url = route('realestate.contracts.edit', $contract);

        $html = $this->sendBack($url, route('realestate.contracts.update', $contract), array_map(fn ($s) => $s[1], $sent));

        $this->assertSame(array_column($sent, 1), $this->evaluateJs($html, 'contractEditForm', array_column($sent, 0)), self::CHANGED);
    }

    /** 仕入れの契約の編集画面: 仕入れ案件・税率・金額（分譲地・区画の欄は分譲地の契約にだけ出る） */
    public function test_the_procurement_contract_edit_screen_keeps_the_returned_values(): void
    {
        $contract = ReContract::create([
            'department' => 'realestate', 'contract_type' => 'procurement_house', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => '勝山の家', 'procurement_id' => $this->procurement()->id, 'buyer_id' => $this->buyer()->id,
            'contract_amount_land' => 10000000, 'contract_amount_building' => 5000000, 'cost_amount' => 9000000, 'created_by' => $this->user->id,
        ]);

        $this->assertEditScreenKeeps($contract, [
            'procurement_id'           => ['procurementId', self::tricky('仕入れ')],
            'tax_rate'                 => ['taxRate', self::tricky('税率')],
            'contract_amount_land'     => ['amountLand', self::tricky('土地')],
            'contract_amount_building' => ['amountBuildingExcl', self::tricky('建物')],
            'tax_amount'               => ['taxAmount', self::tricky('消費税')],
            'cost_amount'              => ['costAmount', self::tricky('原価')],
        ]);
    }

    /** 分譲地の契約の編集画面: 分譲地・区画 */
    public function test_the_subdivision_contract_edit_screen_keeps_the_returned_values(): void
    {
        $project = $this->project();
        $contract = ReContract::create([
            'department' => 'realestate', 'contract_type' => 'subdivision_lot', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => '平井分譲地', 'project_id' => $project->id, 'lot_id' => $this->lot($project, 3, 12000000, 'sold')->id,
            'buyer_id' => $this->buyer()->id, 'contract_amount_land' => 12000000, 'cost_amount' => 9000000, 'created_by' => $this->user->id,
        ]);

        $this->assertEditScreenKeeps($contract, [
            'project_id' => ['projectId', self::tricky('分譲地')],
            'lot_id'     => ['lotId', self::tricky('区画')],
        ]);
    }
}
