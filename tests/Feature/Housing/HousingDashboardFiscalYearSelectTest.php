<?php

namespace Tests\Feature\Housing;

use App\Enums\UserRole;
use App\Http\Controllers\Housing\HousingDashboardController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use ReflectionClass;
use Tests\TestCase;

/**
 * 住宅事業ダッシュボードの年度セレクトが、絞り込み中の年度を selected で表示すること。
 *
 * buildFiscalYearOptions() のキーは (string) キャストしても PHP が int に戻すため、
 * Blade 側で (string) にそろえないと selected が一切付かず、ブラウザが先頭 option
 * （＝最新年度）を表示してしまう。
 *
 * hs_* は migration 管理外でテストDBに無く、ルート経由だと集計クエリで 500 になる
 * （DepartmentAccessMiddlewareTest と同じ制約）ため、集計結果はスタブを渡して
 * ビューだけを描画し、option の selected 属性を検証する。
 */
class HousingDashboardFiscalYearSelectTest extends TestCase
{
    use RefreshDatabase;

    /** HousingDashboardController の protected メソッドを呼ぶ */
    private function invokeController(string $method): mixed
    {
        $controller = new HousingDashboardController();
        $ref = (new ReflectionClass($controller))->getMethod($method);
        $ref->setAccessible(true);

        return $ref->invoke($controller);
    }

    /** 指定年度で絞り込んだ状態のダッシュボードを描画し、年度セレクト部分だけを返す */
    private function renderFiscalYearSelect(string $fiscalYear): string
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Executive->value]));

        $html = view('housing.dashboard', [
            'fiscalYear'        => $fiscalYear,
            'period'            => 'all',
            'fiscalYearOptions' => $this->invokeController('buildFiscalYearOptions'),
            'kpi'               => [
                'count_total'    => 0,
                'count_building' => 0,
                'count_custom'   => 0,
                'selling_total'  => 0,
                'cost_total'     => 0,
                'profit_total'   => 0,
                'profit_rate'    => null,
            ],
            'monthly'   => null,
            'paginated' => new LengthAwarePaginator([], 0, 20, 1),
            'request'   => request(),
        ])->render();

        // 期セレクトの selected と混ざらないよう年度セレクトだけを切り出す
        preg_match('/<select name="fiscal_year".*?<\/select>/s', $html, $m);

        return $m[0] ?? '';
    }

    public function test_selected_fiscal_year_option_is_marked_selected(): void
    {
        $select = $this->renderFiscalYearSelect('2025');

        $this->assertStringContainsString('<option value="2025" selected>', $select);
    }

    public function test_exactly_one_fiscal_year_option_is_selected(): void
    {
        $select = $this->renderFiscalYearSelect('2025');

        // 0 件だとブラウザが先頭 option（最新年度）を表示してしまう
        $this->assertSame(1, substr_count($select, 'selected'));
    }

    public function test_all_period_option_is_still_selectable(): void
    {
        $select = $this->renderFiscalYearSelect('all');

        // (int) キャストで直すと 'all' が 0 になり全期間が壊れるため、その方向の修正を禁じる
        $this->assertStringContainsString('<option value="all" selected>', $select);
        $this->assertSame(1, substr_count($select, 'selected'));
    }

    public function test_current_fiscal_year_is_shown_instead_of_the_latest_option(): void
    {
        $this->travelTo(Carbon::create(2025, 8, 15)); // 年度=2025（5月始まり）。実行日の年度とは別年にする

        $this->assertSame(2025, $this->invokeController('getCurrentFiscalYear'));

        $options = $this->invokeController('buildFiscalYearOptions');
        $this->assertSame('2026', (string) array_key_first($options), '先頭 option は最新年度＝既定ではない');

        // 既定（当年度）で描画したとき、先頭の最新年度ではなく当年度が selected になる
        $select = $this->renderFiscalYearSelect('2025');
        $this->assertStringContainsString('<option value="2025" selected>', $select);
        $this->assertStringNotContainsString('<option value="2026" selected>', $select);
    }
}
