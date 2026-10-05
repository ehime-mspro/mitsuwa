<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\ReProcurement;
use App\Models\ReProcurementCost;

/**
 * 仕入れ案件の登録（原価の行つき）・詳細の原価（追加・編集・削除・試算表の取込）・一覧のステータスの小窓を、
 * 描いた画面から送る往復で見る。Ajax は画面の JS が組んだ要求をそのまま送り、応答を JS に戻して画面の状態まで見る。
 */
class ProcurementScreensTest extends RealEstateScreenTestCase
{
    private const DETAIL_SCRIPTS = ['function costExcelImporterFactory('];

    public function test_a_procurement_is_registered_from_the_screen_with_a_cost_row(): void
    {
        $url = route('realestate.procurements.create');
        $html = $this->htmlOf($url);
        $form = $this->formWithCostSection($html, route('realestate.procurements.store'), 'procurementForm',
            'data.propertyType = "used_house"; data.purchaseLand = "18000000"; data.purchaseBuildingExcl = "2000000";',
            'data.showAddCost = true; data.newCost = { cost_item_id: "' . $this->surveyItem->id . '", estimated_amount: "300000", actual_amount: "280000", notes: "境界確定" }; data.addCost();');
        $form = $this->fill($form, ['transaction_type' => 'purchase', 'status' => 'info_obtained', 'property_name' => '道後の家', 'address' => '愛媛県松山市道後町1-1']);
        $this->assertSame('300000', $form['fields']['costs'][0]['estimated_amount'], '原価の行が送られていない');

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '仕入れ案件「RE-PRC-001」を登録しました（原価 1 件を含む）。');
        $procurement = ReProcurement::where('property_name', '道後の家')->firstOrFail();
        $this->assertSame('used_house', $procurement->property_type->value);
        $this->assertSame(18000000, $procurement->purchase_price_land);
        $this->assertSame(2000000, $procurement->purchase_price_building);
        $survey = ReProcurementCost::where('cost_item_id', $this->surveyItem->id)->firstOrFail();
        $this->assertSame([300000, 280000, '境界確定'], [$survey->estimated_amount, $survey->actual_amount, $survey->notes]);
        // 物件購入費は自動で写される（見込み＝査定価格の合計・確定＝購入価格の合計。ReProcurement::syncPropertyPurchaseCost()）
        $purchase = ReProcurementCost::where('cost_item_id', $this->purchaseItem->id)->firstOrFail();
        $this->assertSame([0, 20000000], [$purchase->estimated_amount, $purchase->actual_amount]);
    }

    // ============================================================
    // 詳細の原価（Ajax）
    // ============================================================

    /** 詳細画面の JS を動かす（$html は 1 度だけ描いた画面。応答を戻す 2 回目も同じ画面で動かす） */
    private function detail(string $html, string $steps, array $responses = [], array $evaluate = []): array
    {
        return $this->driveAlpine($html, 'procurementDetail', 'procurementDetail()', $steps, $responses, true, $evaluate, self::DETAIL_SCRIPTS);
    }

    private function detailHtml(ReProcurement $procurement): string
    {
        return $this->htmlOf(route('realestate.procurements.show', $procurement));
    }

    public function test_a_cost_is_added_from_the_detail_screen(): void
    {
        $procurement = $this->procurement();
        $steps = 'data.showAddCost = true; data.newCost = { cost_item_id: "' . $this->surveyItem->id . '", estimated_amount: 500000, actual_amount: null, notes: "現況測量" }; data.addCost();';

        $html = $this->detailHtml($procurement);
        $run = $this->detail($html, $steps);
        $this->assertCount(1, $run['requests']);
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertOk();

        $cost = ReProcurementCost::where('procurement_id', $procurement->id)->firstOrFail();
        $this->assertSame([500000, null, '現況測量'], [$cost->estimated_amount, $cost->actual_amount, $cost->notes]);
        $after = $this->detail($html, $steps, [$this->asFetchResponse($response)], ['costs.length', 'costMessage', 'showAddCost']);
        $this->assertSame([1, '費用を追加しました。', false], $after['evaluated']);
    }

    public function test_a_cost_is_edited_from_the_detail_screen(): void
    {
        $procurement = $this->procurement();
        $cost = ReProcurementCost::create(['procurement_id' => $procurement->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => 500000]);
        $steps = 'var c = data.costs[0]; data.startEditCost(c); data.editCost.actual_amount = 480000; data.saveCost(c);';

        $html = $this->detailHtml($procurement);
        $run = $this->detail($html, $steps);
        $this->assertSame('PUT', $run['requests'][0]['method']);
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertOk();

        $this->assertSame([500000, 480000], [$cost->fresh()->estimated_amount, $cost->fresh()->actual_amount]);
        $after = $this->detail($html, $steps, [$this->asFetchResponse($response)], ['costs[0].actual_amount', 'editingCostId']);
        $this->assertSame([480000, null], $after['evaluated']);
    }

    public function test_a_cost_is_deleted_from_the_detail_screen(): void
    {
        $procurement = $this->procurement();
        $cost = ReProcurementCost::create(['procurement_id' => $procurement->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => 500000]);
        $steps = 'data.deleteCost(data.costs[0]);';

        $html = $this->detailHtml($procurement);
        $run = $this->detail($html, $steps);
        $this->assertSame(['「測量費」を削除しますか？'], $run['confirms']);
        $this->assertSame('DELETE', $run['requests'][0]['method']);
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertOk();

        $this->assertNull($cost->fresh());
        $after = $this->detail($html, $steps, [$this->asFetchResponse($response)], ['costs.length']);
        $this->assertSame([0], $after['evaluated']);
    }

    public function test_costs_are_imported_from_the_spreadsheet_panel(): void
    {
        $procurement = $this->procurement();
        ReProcurementCost::create(['procurement_id' => $procurement->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => 1]);
        // 試算表を読んだあとのプレビューの行（ファイルの読み込みは SheetJS なので、読んだ結果から始める）
        $steps = 'data.costExcelImport.mode = "overwrite"; data.costExcelImport.previewRows = ['
            . '{ skip: false, costItemId: ' . $this->surveyItem->id . ', estimated: 650000, actual: "", notes: "確定測量" },'
            . '{ skip: true, costItemId: ' . $this->surveyItem->id . ', estimated: 9, actual: "", notes: "小計" }]; data.commitCostImport();';

        $html = $this->detailHtml($procurement);
        $run = $this->detail($html, $steps);
        $this->assertCount(1, $run['confirms'], '入れ替えの確認が出ていない');
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertOk();

        $costs = ReProcurementCost::where('procurement_id', $procurement->id)->get();
        $this->assertSame([[650000, null, '確定測量']], $costs->map(fn ($c) => [$c->estimated_amount, $c->actual_amount, $c->notes])->all());
        $after = $this->detail($html, $steps, [$this->asFetchResponse($response)], ['costs.length', 'costMessage']);
        $this->assertSame([1, '1 件の原価を取り込みました。'], $after['evaluated']);
    }

    // ============================================================
    // 一覧のステータスの小窓
    // ============================================================

    public function test_the_status_is_changed_from_the_list(): void
    {
        $procurement = $this->procurement(['status' => 'assessment']);
        $html = $this->htmlOf(route('realestate.procurements.index'));
        $steps = 'data.select(data.options.find(function (o) { return o.value === "negotiating"; }));';
        $factory = $this->xData($html, 'realestateStatusCell');

        $run = $this->driveAlpine($html, 'realestateStatusCell', $factory, $steps, [], true, [], ['window.__reStatusOptions']);
        $this->assertSame('PATCH', $run['requests'][0]['method']);
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertOk();

        $this->assertSame('negotiating', $procurement->fresh()->status->value);
        $after = $this->driveAlpine($html, 'realestateStatusCell', $factory, $steps, [$this->asFetchResponse($response)], true, ['value', 'label', 'open'], ['window.__reStatusOptions']);
        $this->assertSame(['negotiating', '交渉中', false], $after['evaluated']);
    }
}
