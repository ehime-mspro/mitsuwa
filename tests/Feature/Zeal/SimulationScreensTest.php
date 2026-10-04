<?php

namespace Tests\Feature\Zeal;

use App\Models\ZealSimulation;
use App\Models\ZealSimulationValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesZealSimulationSchema;

/**
 * 経営試算表の一覧・作成・削除と、本部 Sheet の URL 設定（Zeal\SimulationController・SheetImportController::editUrls / updateUrls）。
 *
 * ⚠ 今日を 2026-10-04（日本時間）に固定する。今年度（6 月始まり）は 2026 年度。
 */
class SimulationScreensTest extends MemberScreenTestCase
{
    use CreatesZealSimulationSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-04 03:00:00', 'UTC'));   // 日本時間 12:00
        $this->createZealSimulationSchema();
        $this->seedZealSimulationCategories();
    }

    private function simulation(int $fiscalYear, string $name = ''): ZealSimulation
    {
        return ZealSimulation::create(['fiscal_year' => $fiscalYear, 'name' => $name ?: "{$fiscalYear}年度"]);
    }

    private function listHtml(): string
    {
        return $this->actingAs($this->user)->get(route('zeal.simulations.index', ['list' => 1]))->assertOk()->getContent();
    }

    public function test_without_this_years_table_the_list_is_shown(): void
    {
        $this->simulation(2025);

        $html = $this->actingAs($this->user)->get(route('zeal.simulations.index'))->assertOk()->getContent();

        $this->assertStringContainsString('href="' . route('zeal.simulations.show', ZealSimulation::first()) . '"', $html);
    }

    public function test_with_this_years_table_the_menu_opens_it_and_the_list_is_still_reachable(): void
    {
        $current = $this->simulation(2026);
        $past = $this->simulation(2025);

        $this->actingAs($this->user)->get(route('zeal.simulations.index'))->assertRedirect(route('zeal.simulations.show', $current));

        $html = $this->listHtml();
        $this->assertStringContainsString('href="' . route('zeal.simulations.show', $current) . '"', $html);
        $this->assertStringContainsString('href="' . route('zeal.simulations.show', $past) . '"', $html);
    }

    /** 一覧の「+ 新規作成」から作成画面を開き、描いたフォームで作ると、12 か月 × 有効な項目のセルができる */
    public function test_a_table_is_created_from_the_create_screen(): void
    {
        $this->simulation(2025);
        DB::table('zeal_simulation_categories')->insert([
            'code' => 'retired', 'name' => '廃止した項目', 'group_type' => 'expense', 'calc_type' => 'fixed', 'default_amount' => 999,
            'sort_order' => 300, 'is_system' => 0, 'is_active' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $create = route('zeal.simulations.create');
        $this->assertStringContainsString('href="' . $create . '"', $this->listHtml(), '一覧に作成画面へのリンクが無い');
        $html = $this->actingAs($this->user)->get($create)->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('zeal.simulations.store') . '"');

        // 年度の選択肢は今年度の前後 3 年から作成済みの年度を除いたもの。最初に選ばれているのは今年度
        $this->assertSame(1, preg_match('/<select name="fiscal_year".*?<\/select>/s', $html, $select));
        preg_match_all('/<option value="(\d+)"/', $select[0], $years);
        $this->assertSame(['2023', '2024', '2026', '2027', '2028', '2029'], $years[1]);
        $this->assertSame('2026', $form['fields']['fiscal_year']);

        $response = $this->actingAs($this->user)->from($create)->post($form['action'], array_merge($form['fields'], [
            'name' => '令和8年度 試算', 'notes' => '新店舗の開業を見込む',
        ]));

        $simulation = ZealSimulation::where('fiscal_year', 2026)->firstOrFail();
        $response->assertRedirect(route('zeal.simulations.show', $simulation));
        $this->assertSame('令和8年度 試算', $simulation->name);
        $this->assertSame('新店舗の開業を見込む', $simulation->notes);
        $this->assertSame($this->user->id, (int) $simulation->created_by);

        $values = ZealSimulationValue::where('simulation_id', $simulation->id)->get();
        $this->assertCount(12 * 5, $values, '12 か月 × 有効な 5 項目のセルができていない（廃止した項目は作らない）');
        $rentId = DB::table('zeal_simulation_categories')->where('code', 'rent')->value('id');
        $rent = $values->where('category_id', $rentId);
        $this->assertSame(['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04', '2027-05'],
            $rent->pluck('year_month')->sort()->values()->all());
        $this->assertSame([200000], $rent->pluck('amount')->unique()->values()->all(), '固定額の項目に既定額が入っていない');
        $this->assertSame([200000], $rent->pluck('budget_amount')->unique()->values()->all());
        $this->assertSame([null], $values->where('category_id', '!=', $rentId)->pluck('amount')->unique()->values()->all());

        $this->actingAs($this->user)->get(route('zeal.simulations.show', $simulation))->assertOk()
            ->assertSee('2026年度の試算表を作成しました（12 ヶ月分のセルを生成）。');
    }

    /** 一覧の削除フォームから消すと、一覧に戻って「削除しました」が出る（今年度の試算表があっても） */
    public function test_a_past_table_is_deleted_from_the_list(): void
    {
        $current = $this->simulation(2026);
        $past = $this->simulation(2025);

        $form = $this->parseForm($this->listHtml(), 'action="' . route('zeal.simulations.destroy', $past) . '"');
        $this->assertSame('DELETE', $form['method']);
        $response = $this->actingAs($this->user)->from(route('zeal.simulations.index', ['list' => 1]))->post($form['action'], $form['fields']);

        $this->assertNull(ZealSimulation::find($past->id));
        $this->assertNotNull(ZealSimulation::find($current->id));

        $landed = $this->followRedirects($response)->assertOk();
        $this->assertSame(route('zeal.simulations.index', ['list' => 1]), $response->headers->get('Location'), '削除のあと一覧に戻らない');
        $landed->assertSee('2025年度の試算表を削除しました。');
    }

    /** 詳細画面のリンクから URL 設定を開き、描いたフォームで保存・空にできる */
    public function test_the_sheet_urls_are_saved_from_the_settings_screen(): void
    {
        $simulation = $this->simulation(2026);
        $edit = route('zeal.simulations.sheet-urls.edit', $simulation);
        $show = $this->actingAs($this->user)->get(route('zeal.simulations.show', $simulation))->assertOk()->getContent();
        $this->assertStringContainsString('href="' . $edit . '"', $show, '詳細画面に URL 設定へのリンクが無い');

        $sales = 'https://docs.google.com/spreadsheets/d/abc/export?format=csv&gid=1';
        $form = $this->parseForm($this->actingAs($this->user)->get($edit)->assertOk()->getContent(),
            'action="' . route('zeal.simulations.sheet-urls.update', $simulation) . '"');
        $this->assertSame('PUT', $form['method']);
        $response = $this->actingAs($this->user)->from($edit)->post($form['action'], array_merge($form['fields'], [
            'sales_sheet_url' => $sales, 'expense_sheet_url' => '',
        ]));

        $response->assertRedirect(route('zeal.simulations.show', $simulation));
        $this->assertSame($sales, $simulation->fresh()->sales_sheet_url);
        $this->assertNull($simulation->fresh()->expense_sheet_url);
        $this->assertSame($this->user->id, (int) $simulation->fresh()->updated_by);
        $this->actingAs($this->user)->get(route('zeal.simulations.show', $simulation))->assertSee('本部 Sheet URL を保存しました。');

        // 保存した値が設定画面に描かれ、空にして保存すると消える
        $form = $this->parseForm($this->actingAs($this->user)->get($edit)->assertOk()->getContent(),
            'action="' . route('zeal.simulations.sheet-urls.update', $simulation) . '"');
        $this->assertSame($sales, $form['fields']['sales_sheet_url']);
        $this->actingAs($this->user)->from($edit)->post($form['action'], array_merge($form['fields'], ['sales_sheet_url' => '']));
        $this->assertNull($simulation->fresh()->sales_sheet_url);
    }

    /** Google のスプレッドシート以外の URL は保存せず、理由を設定画面に出す */
    public function test_a_url_outside_google_sheets_is_refused_with_the_reason(): void
    {
        $simulation = $this->simulation(2026, '');
        $edit = route('zeal.simulations.sheet-urls.edit', $simulation);
        $form = $this->parseForm($this->actingAs($this->user)->get($edit)->assertOk()->getContent(),
            'action="' . route('zeal.simulations.sheet-urls.update', $simulation) . '"');

        $response = $this->actingAs($this->user)->from($edit)->post($form['action'], array_merge($form['fields'], [
            'sales_sheet_url' => 'https://example.com/sales.csv',
        ]));

        $response->assertRedirect($edit);
        $this->assertNull($simulation->fresh()->sales_sheet_url);
        $this->actingAs($this->user)->get($edit)->assertSee('Google Sheets の公開リンク（docs.google.com）のみ指定できます。');
    }
}
