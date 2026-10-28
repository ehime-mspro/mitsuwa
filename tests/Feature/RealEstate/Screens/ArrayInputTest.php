<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\ReContract;
use Tests\Concerns\TampersForms;

/**
 * 不動産の画面で、手で組んだ配列の送信が 500 にならないこと（Bug #121）。
 *
 * ⚠ 描いた画面のフォームの欄を書き換えて送り、転送をたどって着いた画面で見る（TampersForms::tamper()）
 * ⚠ 誤りの文は `<li>` の全文で見る（assertErrorItem()。Bug #49）
 */
class ArrayInputTest extends RealEstateScreenTestCase
{
    use TampersForms;

    private const SHAPE = 'の形が正しくありません。画面を開き直して、もう一度入力してください。';

    /** 仕入れ案件の契約の、ほかの欄はすべて正しい送信 */
    private function procurementContract(array $overrides): array
    {
        return array_merge([
            'contract_type' => 'procurement_house', 'procurement_id' => (string) $this->procurement()->id,
            'contract_date' => '2026-10-01', 'buyer_id' => (string) $this->buyer()->id,
            'contract_amount_land' => '10000000', 'cost_amount' => '8000000', 'property_name' => '勝山の家',
        ], $overrides);
    }

    // ---------- 保存の 500（配列を素通りさせる規則だけの欄） ----------

    public function test_a_contract_with_an_array_procurement_is_refused_without_saving(): void
    {
        $values = $this->procurementContract([]);
        $values['procurement_id'] = [$values['procurement_id']];

        $html = $this->tamper(route('realestate.contracts.create'), route('realestate.contracts.store'), $values);

        $this->assertErrorItem($html, '仕入れ案件' . self::SHAPE);
        $this->assertSame(0, ReContract::count());
    }

    /** 入力チェックの前に契約の種別を読むところ（`ReContractType::tryFrom()`）で落ちていた */
    public function test_a_contract_with_an_array_type_is_turned_back(): void
    {
        $values = $this->procurementContract(['contract_type' => ['procurement_house']]);
        $values['buyer_id'] = [$values['buyer_id']];

        $html = $this->tamper(route('realestate.contracts.create'), route('realestate.contracts.store'), $values);

        $this->assertErrorItem($html, '契約種別を選択してください。');
        $this->assertMatchesRegularExpression('/name="contract_date" value="2026-10-01"/', $html, 'ほかの欄の入力が残っていない');
        $this->assertSame(0, ReContract::count());
    }

    /** 入力チェックの中の自作の規則（元号と生年月日の突き合わせ）が request の配列を読んで落ちていた */
    public function test_a_customer_with_an_array_birth_date_is_turned_back(): void
    {
        $html = $this->tamper(route('realestate.customers.create'), route('realestate.customers.store'), [
            'last_name' => '佐藤', 'first_name' => '一郎', 'acquired_date' => '2026-10-01',
            'birth_era' => 'S', 'birth_date' => ['1980-01-02'],
        ]);

        $this->assertErrorItem($html, '生年月日は正しい日付形式で入力してください。');
        $this->assertSame(0, Buyer::where('last_name', '佐藤')->count());
    }

    // ---------- 規則の無かった欄 ----------

    /** アンケートの備考（survey_memo）は規則が無く、配列のまま保存しようとして 500 になっていた */
    public function test_an_array_survey_memo_is_refused_without_saving(): void
    {
        $buyer = $this->buyer();

        $html = $this->tamper(route('realestate.customers.surveys.create', $buyer), route('realestate.customers.surveys.store', $buyer), [
            'survey_date' => '2026-10-01', 'survey_memo' => ['メモ'],
        ]);

        $this->assertErrorItem($html, '備考は文字列で入力してください。');
        $this->assertSame(0, BuyerSurvey::count());
    }

    /** 生年月日の年（birth_year。JS が birth_date を組むための欄）は規則が無く、配列のまま控えて戻った画面が 500 になっていた */
    public function test_the_customer_screen_opens_after_an_array_birth_year(): void
    {
        $html = $this->tamper(route('realestate.customers.create'), route('realestate.customers.store'), [
            'last_name' => '佐藤', 'first_name' => '一郎', 'acquired_date' => '', 'birth_year' => ['1980'],
        ]);

        $this->assertErrorItem($html, '生年月日（年）は文字列で入力してください。');
        $this->assertSame(0, Buyer::where('last_name', '佐藤')->count());
    }

    public function test_an_array_survey_memo_is_refused_on_the_edit_screen_too(): void
    {
        $buyer = $this->buyer();
        $survey = BuyerSurvey::create(['buyer_id' => $buyer->id, 'department' => 'realestate', 'survey_date' => '2026-09-01', 'memo' => '前のメモ']);

        $html = $this->tamper(route('realestate.customers.surveys.edit', [$buyer, $survey]), route('realestate.customers.surveys.update', [$buyer, $survey]), [
            'survey_date' => '2026-10-01', 'survey_memo' => ['メモ'],
        ]);

        $this->assertErrorItem($html, '備考は文字列で入力してください。');
        $this->assertSame('前のメモ', $survey->fresh()->memo);
    }

    // ---------- 入力チェックが 2 回に分かれていた・種別で規則が変わる ----------

    /** 原価の入力チェックは、以前はほかの欄のあとの 2 回目だった（1 回目で断ると原価の行の配列が控えに残った） */
    public function test_the_procurement_screen_opens_after_an_array_cost_note_and_another_error(): void
    {
        $html = $this->tamper(route('realestate.procurements.create'), route('realestate.procurements.store'), [
            'property_name' => '',
            'costs' => [['cost_item_id' => (string) $this->surveyItem->id, 'estimated_amount' => '1000', 'notes' => ['x']]],
        ]);

        $this->assertErrorItem($html, '備考は文字列で入力してください。');
        $this->assertSame(0, \App\Models\ReProcurement::count());
    }

    public function test_the_project_screen_opens_after_an_array_cost_note_and_another_error(): void
    {
        $html = $this->tamper(route('realestate.projects.create'), route('realestate.projects.store'), [
            'project_name' => '',
            'costs' => [['cost_item_id' => (string) $this->surveyItem->id, 'estimated_amount' => '1000', 'notes' => ['x']]],
        ]);

        $this->assertErrorItem($html, '備考は文字列で入力してください。');
        $this->assertSame(0, \App\Models\ReProject::count());
    }

    /** 分譲地の契約には建物の金額の規則が無く、手で組んだ配列が控えに残って編集画面が 500 になっていた */
    public function test_the_subdivision_contract_edit_screen_opens_after_an_array_building_amount(): void
    {
        $project = $this->project();
        $lot = $this->lot($project, 3, 12000000, 'sold');
        $contract = ReContract::create([
            'department' => 'realestate', 'contract_type' => 'subdivision_lot', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => '平井分譲地', 'project_id' => $project->id, 'lot_id' => $lot->id, 'buyer_id' => $this->buyer()->id,
            'contract_amount_land' => 12000000, 'cost_amount' => 4500000, 'created_by' => $this->user->id,
        ]);

        $html = $this->tamper(route('realestate.contracts.edit', $contract), route('realestate.contracts.update', $contract), [
            'contract_amount_building' => ['1'], 'property_name' => '',
        ]);

        $this->assertErrorItem($html, '物件名は必須です。');
        $this->assertSame('平井分譲地', $contract->fresh()->property_name);
    }

    /** 買主の備考（memo）は規則が無く、配列のまま保存しようとして失敗し、戻り先の画面が 500 になっていた */
    public function test_an_array_customer_memo_is_refused_without_saving(): void
    {
        $html = $this->tamper(route('realestate.customers.create'), route('realestate.customers.store'), [
            'last_name' => '佐藤', 'first_name' => '一郎', 'acquired_date' => '2026-10-01', 'memo' => ['メモ'],
        ]);

        $this->assertErrorItem($html, '備考は文字列で入力してください。');
        $this->assertSame(0, Buyer::where('last_name', '佐藤')->count());
    }
}
