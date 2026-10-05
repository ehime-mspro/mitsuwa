<?php

namespace Tests\Feature\Zeal;

use App\Models\ZealSimulation;
use App\Models\ZealSimulationValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesZealSimulationSchema;
use Tests\Concerns\DrivesAlpineFetch;

/**
 * 経営試算表の「実績を反映」（Zeal\SimulationController::syncActualsPreview / syncActuals）。
 *
 * 詳細画面の JS（zealActualsSync）を node で動かし、確認（GET の JSON）は JS が組んだ要求をそのまま送る。
 * 反映は画面の反映フォームから送る。⚠ フォームの送り先と当月を含めるかの値は Alpine のバインド
 * （`:action` / `:value`）なので、描いた式を JS の状態で評価して使う。
 *
 * ⚠ 今日を 2026-10-04（日本時間）に固定する。会員は土台の 1 人（2025-10-17 入会・税抜 9,800 円）。
 *   2025 年度（2025-06〜2026-05）は全部が過去の月。2026 年度は 6〜9 月が過去・10 月が当月・11 月以降が未来。
 */
class SimulationSyncActualsTest extends MemberScreenTestCase
{
    use CreatesZealSimulationSchema;
    use DrivesAlpineFetch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-04 03:00:00', 'UTC'));   // 日本時間 12:00
        $this->createZealSimulationSchema();
        $this->seedZealSimulationCategories();
    }

    private function showHtmlOf(ZealSimulation $simulation): string
    {
        return $this->actingAs($this->user)->get(route('zeal.simulations.show', $simulation))->assertOk()->getContent();
    }

    /** 確認を開く: JS が組んだ要求を送り、応答を JS に戻した後の状態を返す */
    private function preview(ZealSimulation $simulation): array
    {
        $html = $this->showHtmlOf($simulation);
        $factory = $this->xData($html, 'zealActualsSync');

        $sent = $this->driveAlpine($html, 'zealActualsSync', $factory, 'data.openPreview();');
        $this->assertCount(1, $sent['requests'], '確認を開いても要求を 1 つ組まなかった');
        $request = $sent['requests'][0];
        $response = $this->actingAs($this->user)->sendCaptured($request)->assertOk();
        $after = $this->driveAlpine($html, 'zealActualsSync', $factory, 'data.openPreview();', [$this->asFetchResponse($response)]);

        return ['request' => $request, 'state' => $after['state']];
    }

    /** 反映フォームを、ブラウザで当月を含める／含めないを選んだときの値で送る */
    private function apply(ZealSimulation $simulation, bool $includeCurrent)
    {
        $html = $this->showHtmlOf($simulation);
        $form = $this->parseForm($html, ':action="applyUrl"');
        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('include_current_month', $form['fields'], '反映フォームに「当月を含めるか」が無い');

        $this->assertSame(1, preg_match('/<form :action="([^"]*)"/', $html, $action));
        $this->assertSame(1, preg_match('/name="include_current_month" :value="([^"]*)"/', $html, $value));
        $bound = $this->driveAlpine($html, 'zealActualsSync', $this->xData($html, 'zealActualsSync'),
            'data.includeCurrent = ' . json_encode($includeCurrent) . ';'
            . ' var bind = function (expr) { return (new Function("data", "with (data) { return (" + expr + "); }"))(data); };'
            . ' data.__action = bind(' . json_encode(html_entity_decode($action[1], ENT_QUOTES)) . ');'
            . ' data.__include = bind(' . json_encode(html_entity_decode($value[1], ENT_QUOTES)) . ');')['state'];

        return $this->actingAs($this->user)->from(route('zeal.simulations.show', $simulation))->post($bound['__action'], array_merge(
            $form['fields'],
            ['include_current_month' => $bound['__include']],
        ));
    }

    /** [code => [year_month => amount]] */
    private function cells(ZealSimulation $simulation): array
    {
        $codes = DB::table('zeal_simulation_categories')->pluck('code', 'id');
        $cells = [];
        foreach (ZealSimulationValue::where('simulation_id', $simulation->id)->orderBy('year_month')->get() as $value) {
            $cells[$codes[$value->category_id]][$value->year_month] = $value->amount === null ? null : (int) $value->amount;
        }

        return $cells;
    }

    private const REVENUE_FY2025 = [
        '2025-06' => 0, '2025-07' => 0, '2025-08' => 0, '2025-09' => 0,
        '2025-10' => 4742,   // 10/17 入会 → 9,800 × 15 / 31 を四捨五入（入会月は日割り）
        '2025-11' => 9800, '2025-12' => 9800, '2026-01' => 9800, '2026-02' => 9800, '2026-03' => 9800, '2026-04' => 9800, '2026-05' => 9800,
    ];

    private const MEMBERS_FY2025 = [
        '2025-06' => 0, '2025-07' => 0, '2025-08' => 0, '2025-09' => 0,
        '2025-10' => 1, '2025-11' => 1, '2025-12' => 1, '2026-01' => 1, '2026-02' => 1, '2026-03' => 1, '2026-04' => 1, '2026-05' => 1,
    ];

    public function test_the_preview_hands_the_monthly_actuals_to_the_screen(): void
    {
        $simulation = ZealSimulation::create(['fiscal_year' => 2025]);

        $preview = $this->preview($simulation);

        $this->assertSame('GET', $preview['request']['method']);
        $this->assertSame(route('zeal.simulations.sync-actuals.preview', $simulation), $preview['request']['url']);
        $this->assertSame('application/json', $preview['request']['headers']['Accept'] ?? null);
        $this->assertSame('XMLHttpRequest', $preview['request']['headers']['X-Requested-With'] ?? null,
            '確認の取得が X-Requested-With を送っていない（Top trap #9）');

        $state = $preview['state'];
        $this->assertFalse($state['loading']);
        $this->assertSame('', $state['errorMsg']);
        $this->assertSame(12, $state['pastMonthsCount']);
        $this->assertSame(['売上', '会員数'], array_column($state['groups'], 'label'));
        $this->assertSame(self::REVENUE_FY2025, array_column($state['rows']['revenue'], 'actual', 'ym'));
        $this->assertSame(self::MEMBERS_FY2025, array_column($state['rows']['member'], 'actual', 'ym'));
        $this->assertSame(['past'], array_values(array_unique(array_column($state['rows']['revenue'], 'period'))));
    }

    public function test_applying_writes_the_actuals_of_settled_months(): void
    {
        $simulation = ZealSimulation::create(['fiscal_year' => 2025]);

        $response = $this->apply($simulation, false);

        $response->assertRedirect(route('zeal.simulations.show', $simulation));
        $cells = $this->cells($simulation);
        $this->assertSame(self::REVENUE_FY2025, $cells['revenue']);
        $this->assertSame(self::MEMBERS_FY2025, $cells['member_count']);
        $this->assertSame($this->user->id, (int) $simulation->fresh()->updated_by);
        $this->actingAs($this->user)->get(route('zeal.simulations.show', $simulation))
            ->assertSee('実績を反映しました。24 セル更新／0 セル手動上書き保持／0 セル除外（未確定月）。');
    }

    /** 手で直したセル（is_manual_override）は上書きしない */
    public function test_a_cell_edited_by_hand_is_kept(): void
    {
        $simulation = ZealSimulation::create(['fiscal_year' => 2025]);
        ZealSimulationValue::create([
            'simulation_id' => $simulation->id, 'category_id' => DB::table('zeal_simulation_categories')->where('code', 'revenue')->value('id'),
            'year_month' => '2025-12', 'amount' => 12345, 'is_manual_override' => true,
        ]);

        $this->apply($simulation, false);

        $this->assertSame(array_replace(self::REVENUE_FY2025, ['2025-12' => 12345]), $this->cells($simulation)['revenue']);
        $this->actingAs($this->user)->get(route('zeal.simulations.show', $simulation))
            ->assertSee('実績を反映しました。23 セル更新／1 セル手動上書き保持／0 セル除外（未確定月）。');
    }

    /** 当月は「当月も反映する」を選んだときだけ・未来の月はいつも書かない */
    public function test_this_month_is_written_only_when_asked_and_future_months_never(): void
    {
        $simulation = ZealSimulation::create(['fiscal_year' => 2026]);

        $periods = array_column($this->preview($simulation)['state']['rows']['revenue'], 'period', 'ym');
        $this->assertSame('past', $periods['2026-09']);
        $this->assertSame('current', $periods['2026-10']);
        $this->assertSame('future', $periods['2026-11']);

        $this->apply($simulation, false);
        $this->assertSame(['2026-06' => 9800, '2026-07' => 9800, '2026-08' => 9800, '2026-09' => 9800], $this->cells($simulation)['revenue']);
        $this->actingAs($this->user)->get(route('zeal.simulations.show', $simulation))
            ->assertSee('実績を反映しました。8 セル更新／0 セル手動上書き保持／16 セル除外（未確定月）。');

        $this->apply($simulation, true);
        $cells = $this->cells($simulation);
        $this->assertSame(['2026-06' => 9800, '2026-07' => 9800, '2026-08' => 9800, '2026-09' => 9800, '2026-10' => 9800], $cells['revenue']);
        $this->assertSame(1, $cells['member_count']['2026-10']);
        $this->actingAs($this->user)->get(route('zeal.simulations.show', $simulation))
            ->assertSee('実績を反映しました。10 セル更新／0 セル手動上書き保持／14 セル除外（未確定月）。 ※当月の暫定値を含む。');
    }

    /**
     * 確認を開いても、そこが「直前の画面」にならない（Top trap #9）。
     * ⚠ ヘッダーが無いと、確認の JSON の URL がセッションの直前の画面になり、そのあと開いたパスワード変更の
     *   「キャンセル」が生の JSON を指していた。
     */
    public function test_opening_the_preview_does_not_become_the_page_to_go_back_to(): void
    {
        $simulation = ZealSimulation::create(['fiscal_year' => 2025]);
        $this->preview($simulation);

        $html = $this->actingAs($this->user)->get(route('password.change'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/href="([^"]*)"[^>]*>\s*キャンセル/u', $html, $cancel), 'パスワード変更にキャンセルのリンクが無い');
        $this->assertSame(route('zeal.simulations.show', $simulation), html_entity_decode($cancel[1], ENT_QUOTES));
    }
}
