<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProcurementCost;
use App\Models\ReProject;
use App\Models\ReProjectLot;

/**
 * 不動産の金額・号地番号の入力が、本番の列（どれも符号付き INT。2026-10-05 に読み取りで確認）に入らない値を
 * 入力エラーで断る（Bug #73 と同じ形）。区画の坪単価（販売価格 ÷ 坪数。列は INT）も上限を見る。
 *
 * ⚠ テストの SQLite は INT の範囲を見ないので、上限を外すと「入力エラーにならず保存される」で落ちる。本番の MySQL（strict）
 *   では保存の時点で 500 になる。
 * ⚠ 期待する文言は trans() で組む（項目名は画面ごとの和名。Bug #49）。Ajax の画面は JS の alert に出る文で見る。
 */
class AmountLimitTest extends RealEstateScreenTestCase
{
    private const INT_MAX = 2147483647;

    private function tooLarge(string $attribute): string
    {
        return trans('validation.max.numeric', ['attribute' => $attribute, 'max' => (string) self::INT_MAX]);
    }

    // ============================================================
    // 仕入れ案件・分譲地・契約（画面のフォーム）
    // ============================================================

    private function procurementEditForm(ReProcurement $procurement, string $steps): array
    {
        $url = route('realestate.procurements.edit', $procurement);

        return $this->browserForm($this->htmlOf($url), 'action="' . route('realestate.procurements.update', $procurement) . '"', 'procurementForm', $steps, [], null, ['function supplierPicker(']);
    }

    public function test_a_procurement_price_up_to_the_limit_is_saved(): void
    {
        $procurement = $this->procurement();
        $form = $this->procurementEditForm($procurement, 'data.purchaseLand = "' . self::INT_MAX . '";');

        $html = $this->landed($this->submit($form, route('realestate.procurements.edit', $procurement)));

        $this->assertFlash($html, 'success', '仕入れ案件「RE-PRC-001」を更新しました。');
        $this->assertSame(self::INT_MAX, $procurement->fresh()->purchase_price_land);
    }

    public function test_a_procurement_price_over_the_limit_is_refused(): void
    {
        $procurement = $this->procurement();
        $form = $this->procurementEditForm($procurement, 'data.assessmentLand = "' . (self::INT_MAX + 1) . '";');

        $html = $this->landed($this->submit($form, route('realestate.procurements.edit', $procurement)));

        $this->assertErrorItem($html, $this->tooLarge('査定価格（土地）'));
        $this->assertNull($procurement->fresh()->assessment_price_land);
    }

    public function test_a_cost_row_over_the_limit_is_refused_on_the_registration_screen(): void
    {
        $url = route('realestate.procurements.create');
        $form = $this->formWithCostSection($this->htmlOf($url), route('realestate.procurements.store'), 'procurementForm', 'data.propertyType = "used_house";',
            'data.newCost = { cost_item_id: "' . $this->surveyItem->id . '", estimated_amount: "' . (self::INT_MAX + 1) . '", actual_amount: "", notes: "" }; data.addCost();');
        $form = $this->fill($form, ['transaction_type' => 'purchase', 'status' => 'info_obtained', 'property_name' => '道後の家', 'address' => '松山市']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, $this->tooLarge('見込み額'));
        $this->assertSame(0, ReProcurement::count());
    }

    public function test_a_project_price_over_the_limit_is_refused(): void
    {
        $url = route('realestate.projects.create');
        $form = $this->formWithCostSection($this->htmlOf($url), route('realestate.projects.store'), 'projectForm', '');
        $form = $this->fill($form, ['project_name' => '北条分譲地', 'status' => 'info_obtained', 'address' => '松山市北条1', 'target_selling_price' => (string) (self::INT_MAX + 1)]);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, $this->tooLarge('想定総販売価格'));
        $this->assertSame(0, ReProject::count());
    }

    public function test_a_contract_amount_over_the_limit_is_refused(): void
    {
        $procurement = $this->procurement(['property_type' => 'brokerage_land']);
        $buyer = $this->buyer();
        $url = route('realestate.contracts.create');
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('realestate.contracts.store') . '"', 'contractForm',
            'data.contractType = "procurement_land"; data.onTypeChange(); data.procurementId = "' . $procurement->id . '"; data.onProcurementChange();
             setImmediate(function () { data.amountLand = "' . (self::INT_MAX + 1) . '"; });',
            [$this->apiResponse(route('api.realestate.procurement-cost', $procurement))]);
        $form = $this->fill($form, ['buyer_id' => (string) $buyer->id]);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, $this->tooLarge('契約額（土地）'));
        $this->assertSame(0, ReContract::count());
    }

    public function test_a_brokerage_fee_over_the_limit_is_refused_when_closing(): void
    {
        $contract = ReContract::create(['department' => 'realestate', 'contract_type' => 'brokerage', 'status' => 'listing', 'property_name' => '仲介A', 'created_by' => $this->user->id]);
        $url = route('realestate.contracts.show', $contract);
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('realestate.contracts.close', $contract) . '"'), ['brokerage_fee' => (string) (self::INT_MAX + 1)]);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, $this->tooLarge('仲介手数料'));
        $this->assertSame('listing', $contract->fresh()->status->value);
    }

    // ============================================================
    // 詳細の原価・試算表の取込・区画（Ajax。JS の alert に出る）
    // ============================================================

    /** 画面の JS を 2 回動かす: 要求を取り出して送り、応答を戻して alert を返す */
    private function alertsAfter(string $html, string $function, string $steps, array $withScripts = []): array
    {
        $run = $this->driveAlpine($html, $function, $function . '()', $steps, [], true, [], $withScripts);
        $this->assertCount(1, $run['requests'], '要求が送られていない');
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertStatus(422);

        return $this->driveAlpine($html, $function, $function . '()', $steps, [$this->asFetchResponse($response)], true, [], $withScripts)['alerts'];
    }

    public function test_a_detail_cost_over_the_limit_is_refused(): void
    {
        $procurement = $this->procurement();
        $html = $this->htmlOf(route('realestate.procurements.show', $procurement));

        $alerts = $this->alertsAfter($html, 'procurementDetail',
            'data.newCost = { cost_item_id: "' . $this->surveyItem->id . '", estimated_amount: 1000, actual_amount: ' . (self::INT_MAX + 1) . ', notes: "" }; data.addCost();',
            ['function costExcelImporterFactory(']);

        $this->assertCount(1, $alerts);
        $this->assertTrue(str_contains($alerts[0], trans('validation.max.numeric', ['attribute' => '確定額', 'max' => (string) self::INT_MAX])), '理由が知らされていない: ' . $alerts[0]);
        $this->assertSame(0, ReProcurementCost::count());
    }

    public function test_an_imported_cost_over_the_limit_is_refused(): void
    {
        $project = $this->project();
        $html = $this->htmlOf(route('realestate.projects.show', $project));

        $alerts = $this->alertsAfter($html, 'projectDetail',
            'data.costExcelImport.previewRows = [{ skip: false, costItemId: ' . $this->surveyItem->id . ', estimated: ' . (self::INT_MAX + 1) . ', actual: "", notes: "" }]; data.commitCostImport();',
            ['function costExcelImporterFactory(']);

        $this->assertCount(1, $alerts);
        $this->assertTrue(str_contains($alerts[0], '取込に失敗しました'), $alerts[0]);
        $this->assertTrue(str_contains($alerts[0], trans('validation.max.numeric', ['attribute' => '見込み額', 'max' => (string) self::INT_MAX])), '理由が知らされていない: ' . $alerts[0]);
    }

    private function lotEditAlerts(ReProjectLot $lot, string $changes): array
    {
        $html = $this->htmlOf(route('realestate.projects.lots', $lot->project_id));

        return $this->alertsAfter($html, 'lotManager', 'data.startEditLot(data.lots[0]); ' . $changes . ' data.saveLot();');
    }

    public function test_a_lot_price_or_number_over_the_limit_is_refused(): void
    {
        $lot = $this->lot($this->project(), 1, 12000000);

        $alerts = $this->lotEditAlerts($lot, 'data.editLotData.selling_price = ' . (self::INT_MAX + 1) . '; data.editLotData.lot_number = ' . (self::INT_MAX + 1) . ';');

        $this->assertTrue(str_contains($alerts[0], $this->tooLarge('販売価格')), $alerts[0]);
        $this->assertTrue(str_contains($alerts[0], $this->tooLarge('号地番号')), $alerts[0]);
        $this->assertSame([1, 12000000], [$lot->fresh()->lot_number, $lot->fresh()->selling_price]);
    }

    // ============================================================
    // 区画の坪単価（販売価格 ÷ 坪数）が列に入らない（R6）
    // ============================================================

    private const PER_TSUBO_TOO_LARGE = '販売価格と面積から求めた坪単価が大きすぎます。面積を確かめてください。';

    public function test_a_lot_whose_price_per_tsubo_does_not_fit_is_refused_when_editing(): void
    {
        $lot = $this->lot($this->project(), 1, 12000000);

        // 0.04㎡ は 0.01 坪。30,000,000 円 ÷ 0.01 坪 ＝ 3,000,000,000 円（INT に入らない）
        $alerts = $this->lotEditAlerts($lot, 'data.editLotData.area_sqm = "0.04"; data.editLotData.selling_price = 30000000;');

        $this->assertTrue(str_contains($alerts[0], self::PER_TSUBO_TOO_LARGE), $alerts[0]);
        $this->assertSame('200.00', (string) $lot->fresh()->area_sqm);
    }

    public function test_a_lot_whose_price_per_tsubo_does_not_fit_is_refused_when_adding(): void
    {
        $project = $this->project();

        // 追加は画面の JS が欄を document.getElementById で読むので、JS が送る形の本文を組んで送る
        $response = $this->actingAs($this->user)->postJson(route('realestate.projects.lots.store', $project),
            ['lot_number' => 1, 'area_sqm' => 0.04, 'selling_price' => 30000000, 'status' => 'on_sale', 'notes' => null]);

        $response->assertStatus(422)->assertJsonPath('errors.selling_price.0', self::PER_TSUBO_TOO_LARGE);
        $this->assertSame(0, ReProjectLot::count());
    }

    public function test_a_lot_whose_price_per_tsubo_just_fits_is_saved(): void
    {
        $project = $this->project();

        // 21,474,836 円 ÷ 0.01 坪 ＝ 2,147,483,600 円（INT の上限 2,147,483,647 以下）
        $response = $this->actingAs($this->user)->postJson(route('realestate.projects.lots.store', $project),
            ['lot_number' => 1, 'area_sqm' => 0.04, 'selling_price' => 21474836, 'status' => 'on_sale', 'notes' => null]);

        $response->assertOk();
        $this->assertSame(2147483600, ReProjectLot::firstOrFail()->selling_price_per_tsubo);
    }

    // ============================================================
    // 走査: 新しく足した整数・数値の入力にも上限がある（全件。Top trap #13）
    // ============================================================

    public function test_every_integer_and_numeric_rule_in_the_realestate_and_customer_controllers_has_an_upper_bound(): void
    {
        $files = array_merge(glob(app_path('Http/Controllers/RealEstate/*.php')), [
            app_path('Http/Controllers/CustomerController.php'),
            app_path('Http/Controllers/CustomerSurveyController.php'),
        ]);
        $this->assertGreaterThanOrEqual(7, count($files), '不動産・顧客のコントローラを拾えていない');
        $checked = 0;
        $unbounded = [];
        foreach ($files as $file) {
            foreach (token_get_all(file_get_contents($file)) as $token) {
                if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $rule = substr($token[1], 1, -1);
                if (! preg_match('/(^|\|)(integer|numeric)(\||$)/', $rule)) {
                    continue;
                }
                $checked++;
                // 上限は max（定数を連結するので文字列は `max:` で終わる）・between・既存の行（exists）のどれか
                if (! preg_match('/(^|\|)(max:|between:|exists:)/', $rule)) {
                    $unbounded[] = basename($file) . ':' . $token[2] . ' ' . $rule;
                }
            }
        }
        $this->assertGreaterThanOrEqual(55, $checked, '整数・数値の入力チェックを拾えていない');
        $this->assertSame([], $unbounded, '上限の無い整数・数値の入力がある（本番の MySQL では列に入らない値で 500）');
    }
}
