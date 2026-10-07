<?php

namespace Tests\Feature\Housing\Screens;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 住宅事業の手でページングする一覧で、`?page=` が範囲の外・数字でないとき（Bug #100 の残り。2026-10-07）。
 *
 * ⚠ 契約一覧は `?page=` をそのまま計算に使っていたので、`abc` で TypeError・大きな番号（9223372036854775807）で
 *   位置が float になり `slice()` が TypeError の 500。ダッシュボードは大きな番号だけで同じ 500。
 *   行の無いページは、件数は出るのに表が空になる。→ 最後のページまでに抑える（tests/Feature/ListPageNumberClampTest.php と同じ）。
 */
class PageNumberClampTest extends HousingScreenTestCase
{
    /** 範囲の外のページ番号（2 ページ目が無い・大きな番号・PHP の整数の最大）と、数字でない番号 */
    public static function pages(): array
    {
        return ['2 ページ目が無い' => ['2'], '大きな番号' => ['99999'], '整数の最大' => ['9223372036854775807'], '数字でない' => ['abc']];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // 日本の今日 2026-10-06（2026 年度＝2026-05-01〜2027-04-30。ダッシュボードの既定の年度）
        Carbon::setTestNow('2026-10-06 03:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function assertLastPage(string $url, string $viewKey, string $page): void
    {
        $response = $this->actingAs($this->user)->get($url . 'page=' . $page)->assertOk();
        $paginator = $response->viewData($viewKey);

        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertSame([1, 1], [$paginator->currentPage(), $paginator->count()], "page={$page} でも最後のページ（1 ページ目）の行を出す");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_the_contract_list_shows_the_last_page_for_a_page_out_of_range(string $page): void
    {
        $this->contract($this->property(), $this->buyer());

        $this->assertLastPage(route('housing.contracts.index') . '?fiscal_year=all&', 'contracts', $page);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_the_dashboard_shows_the_last_page_for_a_page_out_of_range(string $page): void
    {
        $this->contract($this->property(), $this->buyer(), ['contract_date' => '2026-09-01']);

        $this->assertLastPage(route('housing.dashboard') . '?', 'paginated', $page);
    }
}
