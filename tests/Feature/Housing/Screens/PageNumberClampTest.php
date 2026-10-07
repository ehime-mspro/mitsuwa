<?php

namespace Tests\Feature\Housing\Screens;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 住宅事業の手でページングする一覧で、`?page=` が範囲の外・数字でないとき（Bug #100 の残り。2026-10-07）。
 *
 * ⚠ 契約一覧は `?page=` をそのまま計算に使っていたので、`abc` で TypeError・大きな番号（9223372036854775807）で
 *   位置が float になり `slice()` が TypeError の 500。ダッシュボードは大きな番号だけで同じ 500。
 *   行の無いページは、件数は出るのに表が空になる。→ 最後のページまでに抑える（tests/Feature/ListPageNumberClampTest.php と同じ）。
 * ⚠ 契約は 21 件（1 ページ 20 件）。最後のページ（2 ページ目・1 行）と 1 ページ目を見分けられるようにする。
 */
class PageNumberClampTest extends HousingScreenTestCase
{
    /** ページ番号 → 出るページと行の数（3 ページ目が無い・大きな番号・PHP の整数の最大は最後のページ、数字でない番号は 1 ページ目） */
    public static function pages(): array
    {
        return [
            '3 ページ目が無い' => ['3', 2, 1],
            '大きな番号' => ['99999', 2, 1],
            '整数の最大' => ['9223372036854775807', 2, 1],
            '数字でない' => ['abc', 1, 20],
        ];
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

    /** 今年度の建売の契約を 21 件（物件ごとに 1 件） */
    private function twentyOneContracts(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $property = $this->property(['property_code' => sprintf('HS-%03d', $i), 'property_name' => "平井 建売 {$i}"]);
            $this->contract($property, $this->buyer(), ['contract_date' => '2026-09-01']);
        }
    }

    private function assertPage(string $url, string $viewKey, string $page, int $expectedPage, int $expectedRows): void
    {
        $paginator = $this->actingAs($this->user)->get($url)->assertOk()->viewData($viewKey);

        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertSame([$expectedPage, $expectedRows], [$paginator->currentPage(), $paginator->count()], "page={$page} で {$expectedPage} ページ目の行を出す");
    }

    #[DataProvider('pages')]
    public function test_the_contract_list_shows_the_last_page_for_a_page_out_of_range(string $page, int $expectedPage, int $expectedRows): void
    {
        $this->twentyOneContracts();

        $this->assertPage(route('housing.contracts.index', ['fiscal_year' => 'all', 'page' => $page]), 'contracts', $page, $expectedPage, $expectedRows);
    }

    #[DataProvider('pages')]
    public function test_the_dashboard_shows_the_last_page_for_a_page_out_of_range(string $page, int $expectedPage, int $expectedRows): void
    {
        $this->twentyOneContracts();

        $this->assertPage(route('housing.dashboard', ['page' => $page]), 'paginated', $page, $expectedPage, $expectedRows);
    }
}
