<?php

namespace Tests\Feature\Approval\Phase3;

use App\Models\ApprovalHoliday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 送らない日の期間の比べ方（段階3 設計書 §5.10・D19）。
 *
 * ⚠ 渡す日は日本の暦の日付（JapanTime::today() と同じ「UTC の 0:00」の形）。
 */
class ApprovalHolidayTest extends TestCase
{
    use RefreshDatabase;

    private function holiday(string $from, string $to, bool $yearly): ApprovalHoliday
    {
        return ApprovalHoliday::create(['start_date' => $from, 'end_date' => $to, 'repeats_yearly' => $yearly, 'description' => '休み'])->refresh();
    }

    /** @param list<string> $days @return array<string, bool> */
    private function covered(ApprovalHoliday $holiday, array $days): array
    {
        $result = [];
        foreach ($days as $day) {
            $result[$day] = $holiday->covers(Carbon::parse($day));
        }

        return $result;
    }

    public function test_a_one_time_period_covers_its_dates_inclusively(): void
    {
        $this->assertSame(
            ['2026-08-12' => false, '2026-08-13' => true, '2026-08-15' => true, '2026-08-16' => false, '2027-08-14' => false],
            $this->covered($this->holiday('2026-08-13', '2026-08-15', false), ['2026-08-12', '2026-08-13', '2026-08-15', '2026-08-16', '2027-08-14']),
        );
    }

    public function test_a_yearly_period_is_compared_by_month_and_day(): void
    {
        $this->assertSame(
            ['2025-08-14' => true, '2031-08-13' => true, '2031-08-16' => false, '2031-08-12' => false],
            $this->covered($this->holiday('2026-08-13', '2026-08-15', true), ['2025-08-14', '2031-08-13', '2031-08-16', '2031-08-12']),
        );
    }

    /** 年をまたぐ期間（12/29〜1/3）は、開始の月日が終了の月日より後なのでまたぐとみなす（D19） */
    public function test_a_yearly_period_can_cross_the_new_year(): void
    {
        $this->assertSame(
            ['2027-12-28' => false, '2027-12-29' => true, '2027-12-31' => true, '2028-01-01' => true, '2028-01-03' => true, '2028-01-04' => false, '2028-06-30' => false],
            $this->covered($this->holiday('2026-12-29', '2027-01-03', true), ['2027-12-28', '2027-12-29', '2027-12-31', '2028-01-01', '2028-01-03', '2028-01-04', '2028-06-30']),
        );
    }

    /** 2/29 を含む毎年の期間は、うるう年でない年は 2/28 と 3/1 で区切られる */
    public function test_february_the_twenty_ninth_in_a_yearly_period(): void
    {
        $this->assertSame(
            ['2028-02-29' => true, '2029-02-28' => false, '2029-03-01' => false],
            $this->covered($this->holiday('2028-02-29', '2028-02-29', true), ['2028-02-29', '2029-02-28', '2029-03-01']),
        );
        $this->assertSame(
            ['2028-02-29' => true, '2029-02-28' => true, '2029-03-01' => true, '2029-03-02' => false],
            $this->covered($this->holiday('2028-02-28', '2028-03-01', true), ['2028-02-29', '2029-02-28', '2029-03-01', '2029-03-02']),
        );
    }

    public function test_a_single_day(): void
    {
        $this->assertSame(
            ['2026-11-01' => false, '2026-11-02' => true, '2026-11-03' => false],
            $this->covered($this->holiday('2026-11-02', '2026-11-02', false), ['2026-11-01', '2026-11-02', '2026-11-03']),
        );
    }
}
