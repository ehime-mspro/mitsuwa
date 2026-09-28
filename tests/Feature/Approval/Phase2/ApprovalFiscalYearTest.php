<?php

namespace Tests\Feature\Approval\Phase2;

use App\Support\Approval\ApprovalFiscalYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 会社ごとの期と和暦（要件 6.1・6.2）。
 *
 * ⚠ 期の式の走査（JapanBusinessDayTest）は、開始月が変数の式を拾わない（計画 §0.9）。境目はここで固定する。
 * ⚠ Laravel を起動する TestCase にする（起動しない Unit テストは php.ini の timezone に支配される。Bug #54）。
 */
class ApprovalFiscalYearTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function boundaries(): array
    {
        return [
            '5月始まり・4/30 は前の年度' => ['2026-04-30', 5, 2025],
            '5月始まり・5/1 から新しい年度' => ['2026-05-01', 5, 2026],
            '6月始まり・5/31 は前の年度' => ['2026-05-31', 6, 2025],
            '6月始まり・6/1 から新しい年度' => ['2026-06-01', 6, 2026],
            '1月始まり・1/1' => ['2026-01-01', 1, 2026],
            '4月始まり・翌年の 3/31' => ['2027-03-31', 4, 2026],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_the_fiscal_year_of_a_japanese_date(string $date, int $startMonth, int $expected): void
    {
        $this->assertSame($expected, ApprovalFiscalYear::of(CarbonImmutable::parse($date), $startMonth));
    }

    /** 保存した瞬間（UTC）は日本時間に直してから見る（Bug #61） */
    public function test_a_moment_is_read_in_japan_time(): void
    {
        // UTC 4/30 15:30 = 日本時間 5/1 0:30 → 新しい年度
        $this->assertSame(2026, ApprovalFiscalYear::ofMoment(CarbonImmutable::parse('2026-04-30 15:30:00', 'UTC'), 5));
        // UTC 4/30 14:59 = 日本時間 4/30 23:59 → 前の年度
        $this->assertSame(2025, ApprovalFiscalYear::ofMoment(CarbonImmutable::parse('2026-04-30 14:59:59', 'UTC'), 5));
    }

    public function test_the_current_fiscal_year_uses_the_japanese_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 16:00:00', 'UTC'));   // 日本時間 5/1 1:00

        $this->assertSame(2026, ApprovalFiscalYear::current(5));
    }

    public static function eras(): array
    {
        return [
            '令和8年度（5月始まり）' => [2026, 5, 'R8'],
            '令和元年度（令和の初日に始まる期）' => [2019, 5, 'R1'],
            '令和元年度（6月始まり）' => [2019, 6, 'R1'],
            '平成31年度（4月始まりは令和の前に始まる）' => [2019, 4, 'H31'],
            '平成30年度' => [2018, 5, 'H30'],
        ];
    }

    #[DataProvider('eras')]
    public function test_the_era_label_follows_the_first_day_of_the_fiscal_year(int $fiscalYear, int $startMonth, string $expected): void
    {
        $this->assertSame($expected, ApprovalFiscalYear::eraLabel($fiscalYear, $startMonth));
    }
}
