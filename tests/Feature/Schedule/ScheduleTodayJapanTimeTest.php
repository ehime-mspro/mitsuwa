<?php

namespace Tests\Feature\Schedule;

use App\Services\ScheduleCardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesRealEstateSchema;

/** 工程表の「今日」（状態・遅延・今日の線）を日本の日付で決める（Bug #61） */
class ScheduleTodayJapanTimeTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_schedule_card_uses_the_japanese_today(): void
    {
        $property = $this->makeParent('property');
        Carbon::setTestNow(Carbon::parse('2026-08-31 15:30:00', 'UTC')); // 日本時間 9/1 0:30

        $card = app(ScheduleCardService::class)->build($property);

        $this->assertInstanceOf(CarbonImmutable::class, $card['today']);
        $this->assertSame('2026-09-01', $card['today']->toDateString(), '工程表の今日が日本の日付でない');
    }
}
