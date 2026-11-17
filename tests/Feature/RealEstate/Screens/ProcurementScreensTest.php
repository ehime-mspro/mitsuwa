<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Enums\UserRole;
use App\Models\ReProcurement;
use App\Models\ReProcurementCost;
use Illuminate\Support\Facades\Route;

/**
 * 仕入れ案件の登録（原価の行つき）・詳細の原価（削除・試算表の取込。追加・編集は CostGridScreenTest）・一覧のステータスの小窓を、
 * 描いた画面から送る往復で見る。Ajax は画面の JS が組んだ要求をそのまま送り、応答を JS に戻して画面の状態まで見る。
 */
class ProcurementScreensTest extends RealEstateScreenTestCase
{
    private const DETAIL_SCRIPTS = ['function costExcelImporterFactory(', 'function costGridFactory('];

    public function test_a_procurement_is_registered_from_the_screen_with_a_cost_row(): void
    {
        $url = route('realestate.procurements.create');
        $html = $this->htmlOf($url);
        $form = $this->formWithCostSection($html, route('realestate.procurements.store'), 'procurementForm',
            'data.propertyType = "used_house"; data.purchaseLand = "18000000"; data.purchaseBuildingExcl = "2000000";',
            // 原価欄: 見込み額の表で入れて閉じ、確定額の表で入れて閉じる（一覧の hidden で登録と一緒に送る）
            'data.openCostGrid("estimated"); var r = data.costGrid.rows.filter(function (x) { return x.name === "測量費"; })[0];'
            . ' r.draft.amount = "300,000"; r.draft.notes = "境界確定"; data.closeCostGrid();'
            . ' data.openCostGrid("actual"); var a = data.costGrid.rows.filter(function (x) { return x.name === "測量費"; })[0];'
            . ' a.draft.amount = "280000"; data.closeCostGrid();');
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

    /** 取り除いた「＋ 費用追加」「編集」と、表を開いているあいだ隠すもの（設計 7.1・7.2）を、描いた詳細画面で見る */
    public function test_the_detail_list_has_no_add_or_edit_controls(): void
    {
        $owner = $this->procurement();
        $executive = $this->htmlOf(route('realestate.procurements.show', $owner));

        foreach (['＋ 費用追加', 'startEditCost', 'addCost(', 'showAddCost', 'editingCostId', 'saveCost('] as $gone) {
            $this->assertStringNotContainsString($gone, $executive, "取り除いたはずの {$gone} が残っている");
        }
        $this->assertSame(1, preg_match('/class="text-gray-400">未定<\/span>/u', $executive), '確定額が空の表示が「未定」でない');
        $this->assertSame(0, preg_match('/cost\.actual_amount === null[^>]*>—</u', $executive), '確定額が空の表示が「—」のまま');
        $this->assertStringContainsString('原価データがありません。「見込み額を入力」から入れてください。', $executive);
        $this->assertStringContainsString('deleteCost(cost)', $executive, '経営層に「削除」が出ていない');

        $previous = $this->user;
        $this->user = $this->userWithRole(UserRole::Manager);
        try {
            $manager = $this->htmlOf(route('realestate.procurements.show', $owner));
        } finally {
            $this->user = $previous;
        }
        $this->assertStringNotContainsString('deleteCost(cost)', $manager, '管理者に「削除」が出ている');

        // 入力の表を開いているあいだは、カード右上の 3 つのボタンと見るだけの一覧を隠す（x-show の式を画面の状態で評価する）
        $guards = [];
        foreach (["openCostExcelImport()", "openCostGrid('estimated')", "openCostGrid('actual')"] as $click) {
            $this->assertSame(1, preg_match('/<button\b[^>]*@click="' . preg_quote($click, '/') . '"[^>]*\sx-show="([^"]*)"/', $executive, $m), "{$click} のボタンに x-show が無い");
            $guards[] = html_entity_decode($m[1]);
        }
        $this->assertSame(1, preg_match('/<div class="border border-gray-200 rounded-md overflow-hidden" x-show="([^"]*)">\s*<div class="scroll-hint/', $executive, $m), '見るだけの一覧の x-show が無い');
        $guards[] = html_entity_decode($m[1]);

        $closed = $this->driveAlpine($executive, 'procurementDetail', 'procurementDetail()', '', [], true, $guards, self::DETAIL_SCRIPTS);
        $this->assertSame([true, true, true, true], $closed['evaluated'], '表を開く前なのにボタン・一覧が隠れている');
        $open = $this->driveAlpine($executive, 'procurementDetail', 'procurementDetail()', 'data.openCostGrid("estimated");', [], true, $guards, self::DETAIL_SCRIPTS);
        $this->assertSame([false, false, false, false], $open['evaluated'], '表を開いているのに、ボタンか見るだけの一覧が出たまま');
    }

    public function test_the_old_add_and_update_routes_are_gone(): void
    {
        foreach (['procurements', 'projects'] as $plural) {
            foreach (['store', 'update'] as $action) {
                $this->assertFalse(Route::has("realestate.{$plural}.costs.{$action}"), "realestate.{$plural}.costs.{$action} が残っている");
            }
        }
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

        // 選択肢（window.__reStatusOptions）と送り先は関数と同じ <script> にある
        $run = $this->driveAlpine($html, 'realestateStatusCell', $factory, $steps);
        $this->assertSame('PATCH', $run['requests'][0]['method']);
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertOk();

        $this->assertSame('negotiating', $procurement->fresh()->status->value);
        $after = $this->driveAlpine($html, 'realestateStatusCell', $factory, $steps, [$this->asFetchResponse($response)], true, ['value', 'label', 'open']);
        $this->assertSame(['negotiating', '交渉中', false], $after['evaluated']);
    }

    /** 画面を開いたまま時間がたったか、ほかの画面でログアウトしたあとにステータスを変えると、理由が日本語で出る（Bug #137） */
    public function test_a_status_change_after_the_session_expired_says_why(): void
    {
        $procurement = $this->procurement(['status' => 'assessment']);
        $html = $this->htmlOf(route('realestate.procurements.index'));
        $steps = 'data.select(data.options.find(function (o) { return o.value === "negotiating"; }));';
        $factory = $this->xData($html, 'realestateStatusCell');

        $request = $this->driveAlpine($html, 'realestateStatusCell', $factory, $steps)['requests'][0];
        $this->enforceCsrfToken();
        $response = $this->sendCaptured($this->withExpiredToken($request))->assertStatus(419);
        $after = $this->driveAlpine($html, 'realestateStatusCell', $factory, $steps, [$this->asFetchResponse($response)], true, ['value']);

        $this->assertSame([self::EXPIRED_JSON_MESSAGE], $after['alerts']);
        $this->assertSame(['assessment'], $after['evaluated'], '断られたのに画面のステータスが変わった');
        $this->assertSame('assessment', $procurement->fresh()->status->value);
    }

    /** ログインと関係の無い断り（開いたあとで案件が消えた 404）は、番号つきの今の文のまま（サーバーの英語の文は出さない） */
    public function test_a_status_change_that_fails_otherwise_keeps_the_numbered_message(): void
    {
        $procurement = $this->procurement(['status' => 'assessment']);
        $html = $this->htmlOf(route('realestate.procurements.index'));
        $steps = 'data.select(data.options.find(function (o) { return o.value === "negotiating"; }));';
        $factory = $this->xData($html, 'realestateStatusCell');

        $request = $this->driveAlpine($html, 'realestateStatusCell', $factory, $steps)['requests'][0];
        $procurement->delete();
        $response = $this->sendCaptured($request)->assertNotFound();
        $after = $this->driveAlpine($html, 'realestateStatusCell', $factory, $steps, [$this->asFetchResponse($response)]);

        $this->assertSame(['ステータスの更新に失敗しました（404）'], $after['alerts']);
    }
}
