<?php

namespace Tests\Feature\Ops;

use App\Console\Commands\BackupCommand;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_queue_worker_runs_on_every_scheduler_run_and_exits_when_empty(): void
    {
        $event = $this->event('queue:work');

        // CRON（5 分おき）で schedule:run が起動されるたびに回す（CRON の分の設定がずれていても止まらない）
        $this->assertSame('* * * * *', $event->expression);
        $this->assertStringContainsString('--stop-when-empty', $event->command);
        $this->assertStringContainsString('--max-time=240', $event->command);
        $this->assertStringContainsString('--tries=3', $event->command);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_backup_runs_between_three_and_three_oh_four_japan_time(): void
    {
        $event = $this->event('ops:backup');

        $this->assertSame('0-4 3 * * *', $event->expression);
        $this->assertSame('Asia/Tokyo', $this->timezoneName($event));
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_backup_runs_exactly_once_a_day_whatever_minute_the_cron_starts_at(): void
    {
        // さくらの CRON が 0・5・10… 分に起動しても、1・6・11… 分などにずれて起動しても、毎日ちょうど 1 回になること
        $event = $this->event('ops:backup');

        foreach (range(0, 4) as $offset) {
            $runs = 0;
            $firstTick = CarbonImmutable::create(2026, 9, 12, 0, $offset, 0, 'Asia/Tokyo');
            for ($i = 0; $i < 24 * 12; $i++) {
                $this->travelTo($firstTick->addMinutes(5 * $i));
                if ($event->isDue($this->app)) {
                    $runs++;
                }
            }
            $this->assertSame(1, $runs, "CRON が毎時 {$offset} 分から 5 分おきに起動する場合");
        }
    }

    public function test_application_timezone_stays_utc(): void
    {
        // 予定の時刻だけを日本時間にし、アプリ全体の日時の扱いは変えない
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_a_backup_that_dies_without_notifying_is_reported_by_the_scheduler(): void
    {
        config(['mail.default' => 'array', 'backup.notify_to' => 'admin@example.com']);
        $event = $this->event('ops:backup');

        // コマンドが自分で失敗を扱い、知らせを送った終わり方 → 二重に送らない
        $event->exitCode = BackupCommand::HANDLED_FAILURE;
        $event->callAfterCallbacks($this->app);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());

        // 1 = 捕まえきれなかった例外、137 = 強制終了（メモリ不足・kill など）→ スケジューラーが代わりに知らせる
        foreach ([1, 137] as $i => $exitCode) {
            $event->exitCode = $exitCode;
            $event->callAfterCallbacks($this->app);

            $messages = app('mailer')->getSymfonyTransport()->messages();
            $this->assertCount($i + 1, $messages);
            $this->assertStringContainsString('終了コード '.$exitCode, (string) $messages->last()->getOriginalMessage()->getTextBody());
        }
    }

    private function event(string $needle): Event
    {
        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn (Event $event) => str_contains((string) $event->command, $needle),
        ));
        $this->assertCount(1, $events, $needle.' の予定がちょうど 1 件あること');

        return $events[0];
    }

    private function timezoneName(Event $event): string
    {
        return $event->timezone instanceof DateTimeZone ? $event->timezone->getName() : (string) $event->timezone;
    }
}
