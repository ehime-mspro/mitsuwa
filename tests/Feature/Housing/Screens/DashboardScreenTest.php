<?php

namespace Tests\Feature\Housing\Screens;

use Carbon\Carbon;

/**
 * 住宅事業ダッシュボード（`/housing`）を HTTP で開き、年度・期の絞り込みのフォームを描いた画面から送る。
 *
 * ⚠ 既存の HousingDashboardFiscalYearSelectTest はコントローラのメソッドを直接呼んでビューだけ描いていた（集計は通らない）。
 *   ここはルートを通して、成約の集計まで見る。
 */
class DashboardScreenTest extends HousingScreenTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 日本の今日 2026-10-06（2026 年度＝2026-05-01〜2027-04-30）
        Carbon::setTestNow('2026-10-06 03:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_dashboard_counts_the_contracts_of_the_selected_fiscal_year(): void
    {
        $this->contract($this->property(), $this->buyer(), ['contract_date' => '2026-09-01']);
        $this->customOrder(['order_code' => 'CO-002', 'status' => 'contracted', 'contract_date' => '2025-10-01', 'building_contract_price' => 20000000]);
        $url = route('housing.dashboard');

        $response = $this->actingAs($this->user)->get($url)->assertOk();
        $this->assertSame([1, 1, 0], [$response->viewData('kpi')['count_total'], $response->viewData('kpi')['count_building'], $response->viewData('kpi')['count_custom']], '今年度の成約が数えられていない');

        $form = $this->fill($this->parseForm($response->getContent(), 'action="' . $url . '"'), ['fiscal_year' => '2025']);
        $this->assertSame(['GET', 'all'], [$form['method'], $form['fields']['period']]);
        $response = $this->submit($form, $url)->assertOk();

        $this->assertSame([1, 0, 1], [$response->viewData('kpi')['count_total'], $response->viewData('kpi')['count_building'], $response->viewData('kpi')['count_custom']], '選んだ年度の成約が数えられていない');
        $this->assertSame(1, preg_match('/<option value="2025" selected>2025年度<\/option>/', $response->getContent()), '選んだ年度が選択欄に残っていない');
    }

    public static function brokenFiscalYears(): array
    {
        return [
            '空' => ['fiscal_year='],
            '配列' => ['fiscal_year[]=2026'],
            '数字でない' => ['fiscal_year=abc'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenFiscalYears')]
    public function test_a_broken_fiscal_year_falls_back_to_the_current_one(string $query): void
    {
        $this->contract($this->property(), $this->buyer(), ['contract_date' => '2026-09-01']);

        $response = $this->actingAs($this->user)->get(route('housing.dashboard') . '?' . $query)->assertOk();

        $this->assertSame(['2026', 1], [$response->viewData('fiscalYear'), $response->viewData('kpi')['count_total']]);
    }
}
