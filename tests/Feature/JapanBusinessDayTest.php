<?php

namespace Tests\Feature;

use App\Http\Controllers\Dad\ProjectController as DadProjectController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Housing\HousingDashboardController;
use App\Http\Controllers\Housing\HsContractListController;
use App\Http\Controllers\RealEstate\ReContractController;
use App\Models\ZealMember;
use App\Models\ZealPlan;
use App\Support\ZealFiscalYear;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 年度・当月・年齢・キャンペーン期間を日本の日付で決める（Bug #61）。
 * 時刻は日本時間の 0:00〜8:59（UTC ではまだ前日）に固定する。
 */
class JapanBusinessDayTest extends TestCase
{
    /** 5 月始まりの年度（同じ計算が 5 つのコントローラに複製されている。まとめない） */
    private const MAY_FISCAL_YEAR_METHODS = [
        [DashboardController::class, 'getCurrentFiscalYear'],
        [ReContractController::class, 'getCurrentFiscalYear'],
        [HsContractListController::class, 'getCurrentFiscalYear'],
        [HousingDashboardController::class, 'getCurrentFiscalYear'],
        [DadProjectController::class, 'currentFiscalYear'],
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invokePrivate(string $class, string $method): mixed
    {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(app($class));
    }

    public function test_the_may_fiscal_year_turns_over_at_japan_midnight(): void
    {
        foreach (self::MAY_FISCAL_YEAR_METHODS as [$class, $method]) {
            Carbon::setTestNow(Carbon::parse('2026-04-30 14:59:00', 'UTC')); // 日本時間 4/30 23:59
            $this->assertSame(2025, $this->invokePrivate($class, $method), "{$class}::{$method}: 4/30 はまだ前の年度");

            Carbon::setTestNow(Carbon::parse('2026-04-30 15:30:00', 'UTC')); // 日本時間 5/1 0:30
            $this->assertSame(2026, $this->invokePrivate($class, $method), "{$class}::{$method}: 5/1 の朝は新しい年度");
        }
    }

    public function test_the_dashboard_half_turns_over_at_japan_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 15:30:00', 'UTC')); // 日本時間 5/1 0:30
        $this->assertSame('h1', $this->invokePrivate(DashboardController::class, 'getCurrentPeriod'));

        Carbon::setTestNow(Carbon::parse('2026-10-31 15:30:00', 'UTC')); // 日本時間 11/1 0:30
        $this->assertSame('h2', $this->invokePrivate(DashboardController::class, 'getCurrentPeriod'));
    }

    public function test_zeal_months_turn_over_at_japan_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-31 14:59:00', 'UTC')); // 日本時間 5/31 23:59
        $this->assertSame(2025, ZealFiscalYear::current());
        $this->assertSame('2026-05', ZealFiscalYear::currentMonthYm());

        Carbon::setTestNow(Carbon::parse('2026-05-31 15:30:00', 'UTC')); // 日本時間 6/1 0:30
        $this->assertSame(2026, ZealFiscalYear::current(), '6/1 の朝は新しい年度');
        $this->assertSame('2026-06', ZealFiscalYear::currentMonthYm(), '6/1 の朝は 6 月が当月');
        $this->assertTrue(ZealFiscalYear::isPastMonth('2026-05'), '5 月は締まった月');
        $this->assertTrue(ZealFiscalYear::isCurrentMonth('2026-06'));
        // ⚠ JapanTime::today() が日本時間の 0:00（UTC の前日 15:00）を返すと、ここで当月が「来月」と判定される
        $this->assertFalse(ZealFiscalYear::isFutureMonth('2026-06'), '当月が来月と判定されている（today() が日本時間の 0:00 を返している）');
        $this->assertTrue(ZealFiscalYear::isFutureMonth('2026-07'));
    }

    public function test_a_zeal_members_age_goes_up_at_japan_midnight_on_the_birthday(): void
    {
        $member = (new ZealMember())->forceFill(['birthday' => '1990-09-19']);

        Carbon::setTestNow(Carbon::parse('2026-09-18 14:59:00', 'UTC')); // 日本時間 9/18 23:59
        $this->assertSame(35, $member->age());

        Carbon::setTestNow(Carbon::parse('2026-09-18 15:30:00', 'UTC')); // 日本時間 9/19 0:30（誕生日）
        $this->assertSame(36, $member->age(), '誕生日の朝なのに年齢が上がっていない');
    }

    /**
     * ⚠ 振る舞いが変わる唯一の箇所: 以前は now()（その日の途中の瞬間）を終了日の 0:00 と比べていたため、
     *   終了日の当日（UTC で 0:00 を過ぎた時点＝日本時間の 9:00 以降）が対象外だった。日付で比べるので終了日も適用中になる。
     */
    public function test_a_campaign_runs_from_japan_midnight_of_the_start_day_through_the_end_day(): void
    {
        $plan = (new ZealPlan())->forceFill([
            'campaign_price_excl' => 5000,
            'campaign_starts_on'  => '2026-09-01',
            'campaign_ends_on'    => '2026-09-30',
        ]);

        $cases = [
            ['2026-08-31 14:59:00', false, '開始日の前日（日本時間 8/31 23:59）'],
            ['2026-08-31 15:00:00', true,  '開始日の 0:00（日本時間 9/1 0:00）'],
            ['2026-09-30 14:59:00', true,  '終了日の 23:59（日本時間 9/30 23:59）'],
            ['2026-09-30 15:00:00', false, '終了日の翌日の 0:00（日本時間 10/1 0:00）'],
        ];
        foreach ($cases as [$utc, $expected, $label]) {
            Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
            $this->assertSame($expected, $plan->isCampaignActive(), $label);
        }
    }
}
