<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\ReContract;
use App\Models\ReProject;
use App\Models\ReProjectLot;

/**
 * 不動産の契約の登録（分譲地。区画の一覧と原価は画面の JS が API から取る）・編集・仲介の成約／不成約を、
 * 描いた画面から送る往復で見る。
 *
 * ⚠ 登録画面は契約の種別で同じ name の欄（担当者・備考）を出し分け、隠した方を `:disabled` で送らない。
 *   browserForm() は `:disabled` を JS の状態で評価して落とす（ブラウザと同じ）。
 */
class ContractScreensTest extends RealEstateScreenTestCase
{
    /** 登録画面で分譲地の契約を選び、分譲地と区画を選ぶ（区画の一覧と原価は JS が API から取る。その応答をそのまま返す） */
    private function subdivisionForm(string $html, ReProject $project, ReProjectLot $lot): array
    {
        $responses = [
            $this->apiResponse(route('api.realestate.project-lots', $project)),
            $this->apiResponse(route('api.realestate.project-lot-cost', $project)),
        ];

        return $this->browserForm($html, 'action="' . route('realestate.contracts.store') . '"', 'contractForm',
            'data.contractType = "subdivision_lot"; data.onTypeChange(); data.projectId = "' . $project->id . '"; data.onProjectChange();
             setImmediate(function () { data.lotId = "' . $lot->id . '"; data.onLotChange(); });', $responses);
    }

    public function test_a_subdivision_contract_is_registered_from_the_screen(): void
    {
        $project = $this->project();
        $lot = $this->lot($project, 3, 12000000);
        $this->lot($project, 4, 11000000);
        \App\Models\ReProjectCost::create(['project_id' => $project->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => 9000000]);
        $buyer = $this->buyer();
        $url = route('realestate.contracts.create');

        $form = $this->subdivisionForm($this->htmlOf($url), $project, $lot);
        $this->assertSame([(string) $lot->id, '12000000', '4500000', '平井分譲地'],
            [$form['fields']['lot_id'], $form['fields']['contract_amount_land'], $form['fields']['cost_amount'], $form['fields']['property_name']],
            '区画を選んでも販売価格・区画あたりの原価・物件名が入らない');
        // 仲介の欄・建物の欄は :disabled で送らない（同じ name の担当者・備考は分譲地の側が 1 つだけ送られる）
        $this->assertSame([], array_values(array_intersect(['brokerage_selling_price', 'brokerage_fee', 'contract_amount_building', 'tax_amount', 'procurement_id'], array_keys($form['fields']))), '隠れている欄が送られている');
        $form = $this->fill($form, ['buyer_id' => (string) $buyer->id, 'contract_date' => '2026-10-01']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約を登録しました。');
        $contract = ReContract::where('lot_id', $lot->id)->firstOrFail();
        $this->assertSame([12000000, 4500000, 7500000], [$contract->contract_amount_land, $contract->cost_amount, $contract->gross_profit]);
        $this->assertSame('sold', $lot->fresh()->status->value);
    }

    public function test_a_procurement_contract_is_edited_from_the_screen(): void
    {
        $procurement = $this->procurement();
        $buyer = $this->buyer();
        $contract = ReContract::create([
            'department' => 'realestate', 'contract_type' => 'procurement_house', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => '勝山の家', 'procurement_id' => $procurement->id, 'buyer_id' => $buyer->id,
            'contract_amount_land' => 10000000, 'contract_amount_building' => 5000000, 'cost_amount' => 9000000, 'created_by' => $this->user->id,
        ]);
        $url = route('realestate.contracts.edit', $contract);

        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('realestate.contracts.update', $contract) . '"', 'contractEditForm',
            'data.onBuildingExclInput("6000000");');
        $this->assertSame(['10000000', '6000000', (string) $buyer->id], [$form['fields']['contract_amount_land'], $form['fields']['contract_amount_building'], $form['fields']['buyer_id']]);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約情報を更新しました。');
        $contract->refresh();
        $this->assertSame([10000000, 6000000, 7000000], [$contract->contract_amount_land, $contract->contract_amount_building, $contract->gross_profit]);
    }

    // ============================================================
    // 仲介の成約・不成約（詳細画面）
    // ============================================================

    private function listing(string $name = '仲介A', ?int $fee = 300000): ReContract
    {
        return ReContract::create([
            'department' => 'realestate', 'contract_type' => 'brokerage', 'status' => 'listing',
            'property_name' => $name, 'brokerage_fee' => $fee, 'created_by' => $this->user->id,
        ]);
    }

    public function test_a_brokerage_is_closed_from_the_detail_screen(): void
    {
        $contract = $this->listing();
        $url = route('realestate.contracts.show', $contract);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('realestate.contracts.close', $contract) . '"');
        $this->assertSame(['PATCH', '300000'], [$form['fields']['_method'], $form['fields']['brokerage_fee']], '成約の小窓に登録時の手数料が入っていない');
        $form = $this->fill($form, ['contract_date' => '2026-10-02', 'buyer_name' => '高橋 花子', 'brokerage_fee' => '330000']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '仲介案件を成約にしました。');
        $contract->refresh();
        $this->assertSame(['closed', '2026-10-02', '高橋 花子', 330000, 330000],
            [$contract->status->value, $contract->contract_date->toDateString(), $contract->buyer_name, $contract->brokerage_fee, $contract->gross_profit]);
    }

    public function test_a_brokerage_close_without_a_fee_reopens_the_dialog_with_the_reason(): void
    {
        $contract = $this->listing('仲介B', null);
        $url = route('realestate.contracts.show', $contract);
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('realestate.contracts.close', $contract) . '"'), ['brokerage_fee' => '']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '仲介手数料']));
        $this->assertSame(1, preg_match('/showCloseModal: true\b/', $html), '入力エラーで戻ったのに成約の小窓が開いていない');
        $this->assertSame('listing', $contract->fresh()->status->value);
    }

    public function test_a_brokerage_is_marked_lost_from_the_detail_screen(): void
    {
        $contract = $this->listing();
        $url = route('realestate.contracts.show', $contract);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('realestate.contracts.lost', $contract) . '"');
        $this->assertSame('PATCH', $form['fields']['_method']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '仲介案件を不成約にしました。');
        $this->assertSame('lost', $contract->fresh()->status->value);
    }
}
