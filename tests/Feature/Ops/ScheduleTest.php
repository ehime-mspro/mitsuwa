<?php

namespace Tests\Feature\Ops;

use App\Console\Commands\BackupCommand;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
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
        $this->assertStringContainsString('--backoff=60', $event->command);
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

    public function test_lock_expiry_lets_the_next_meaningful_run_go_ahead(): void
    {
        // 目印（ロック）が残っても、queue は次の 1〜2 回の起動で復帰し、backup は翌晩を止めない。
        // 引数なしの withoutOverlapping() は既定で 1440 分（24 時間）残るため、
        // 残った目印だけでメールが約 1 日、バックアップが翌晩も無言で止まってしまう。
        $this->assertSame(10, $this->event('queue:work')->expiresAt);
        $this->assertSame(180, $this->event('ops:backup')->expiresAt);
    }

    public function test_backup_runs_even_in_maintenance_mode_and_appends_its_output_to_a_log_file(): void
    {
        // メンテナンスモード中は予定が既定では動かず、知らせもなく抜けてしまう。
        // メンテナンス中こそバックアップが要るため、backup だけ evenInMaintenanceMode を付ける
        $backup = $this->event('ops:backup');
        $this->assertTrue($backup->evenInMaintenanceMode);
        $this->assertSame(storage_path('logs/backup-command.log'), $backup->output);
        $this->assertTrue($backup->shouldAppendOutput);

        // キュー処理には付けない（メンテナンス中に無理に送信を試みない）
        $this->assertFalse($this->event('queue:work')->evenInMaintenanceMode);
    }

    public function test_only_the_backup_schedule_stays_active_while_the_application_is_in_maintenance_mode(): void
    {
        // 今のテストは `evenInMaintenanceMode` の値を見るだけ（Task 11 の持ち越し）。実際にメンテナンスモードにして、3:00（日本時間）に backup の `isDue()` が true・queue:work が false になることを確かめる。
        // 記録先はキャッシュ（`config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array'])`）にして、worktree に storage/framework/down を作らない。
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->travelTo(CarbonImmutable::parse('2026-09-14 03:00:00', 'Asia/Tokyo'));

        $backup = $this->event('ops:backup');
        $queue = $this->event('queue:work');
        $this->assertFalse($this->app->isDownForMaintenance());
        $this->assertDirectoryDoesNotExist(storage_path('framework/down'));

        $this->app->maintenanceMode()->activate([]);

        try {
            $this->assertTrue($this->app->isDownForMaintenance());
            $this->assertTrue($backup->isDue($this->app));
            $this->assertFalse($queue->isDue($this->app));
            $this->assertDirectoryDoesNotExist(storage_path('framework/down'));
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }

        $this->assertFalse($this->app->isDownForMaintenance());
        $this->assertDirectoryDoesNotExist(storage_path('framework/down'));
    }

    public function test_backup_is_scheduled_before_the_queue_worker(): void
    {
        // schedule:run は予定を上から順に、前の予定の終了を待って次へ進む。
        // キュー処理より後ろだと、3:00 の回のバックアップの開始が遅れたり、
        // キュー処理が止まったときにその夜のバックアップが知らせなく抜けたりするため、必ず先に置く。
        $commands = array_values(array_map(
            fn (Event $event) => (string) $event->command,
            $this->app->make(Schedule::class)->events(),
        ));

        $backupIndex = null;
        $queueIndex = null;
        foreach ($commands as $index => $command) {
            if (str_contains($command, 'ops:backup')) {
                $backupIndex = $index;
            }
            if (str_contains($command, 'queue:work')) {
                $queueIndex = $index;
            }
        }

        $this->assertNotNull($backupIndex);
        $this->assertNotNull($queueIndex);
        $this->assertLessThan($queueIndex, $backupIndex, 'ops:backup は queue:work より前に登録されていること');
    }

    public function test_backup_process_exit_codes_map_to_the_right_notification_and_always_release_the_lock(): void
    {
        // レビュー担当の探り（$event->run() を通して本物のプロセス終了コードで確かめる形）に合わせる。
        // 出力は一時ファイルへ逃がし、worktree の storage/logs には書かない。
        config(['mail.default' => 'array', 'backup.notify_to' => 'admin@example.com']);
        $event = $this->event('ops:backup');
        $event->output = tempnam(sys_get_temp_dir(), 'schedule-test-backup-output-');

        try {
            // [コマンド, 期待する終了コード, 増えるメールの数]
            $cases = [
                'success' => ["sh -c 'exit 0'", 0, 0],
                'handled failure' => ["sh -c 'exit 3'", 3, 0],
                'uncaught failure' => ["sh -c 'exit 1'", 1, 1],
            ];

            $expectedMails = 0;
            foreach ($cases as $label => [$command, $expectedExitCode, $mailDelta]) {
                $event->command = $command;
                $event->run($this->app);

                $expectedMails += $mailDelta;
                $this->assertSame($expectedExitCode, $event->exitCode, $label);
                $this->assertSame($expectedMails, count(app('mailer')->getSymfonyTransport()->messages()), $label);
                $this->assertFalse($event->mutex->exists($event), $label.': ロックが外れていること');
            }
        } finally {
            @unlink($event->output);
        }
    }

    public function test_a_backup_stopped_by_a_signal_is_reported_through_schedule_run_without_double_notices(): void
    {
        // 本番（FreeBSD）の sh は予定のコマンドを直接実行するため、kill されると終了コードではなく例外になり、onFailure まで進まない。
        // 手元の macOS の sh（bash）はリダイレクトがあると間に残って 137 を返すので、exec を付けて本番の形を再現する。
        // schedule:run を通して、ScheduledTaskFailed の listener と onFailure とコマンド自身の知らせが二重にならないことも確かめる
        config([
            'mail.default' => 'array',
            'backup.notify_to' => 'admin@example.com',
            'logging.default' => 'null',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-09-14 03:00:00', 'Asia/Tokyo'));

        $backup = $this->event('ops:backup');
        $backup->output = tempnam(sys_get_temp_dir(), 'schedule-test-backup-output-');
        $this->event('queue:work')->command = "sh -c 'exit 0'";

        try {
            // [コマンド, 増えるメールの数, 本文に含まれる文言（正規表現）]
            $cases = [
                'success' => ["sh -c 'exit 0'", 0, null],
                'uncaught failure' => ["sh -c 'exit 1'", 1, '/終了コード 1）/u'],
                // 前の回の終了コード（1）が残っていても、終了コードなしの停止として知らせること
                'killed, the process itself' => ["exec sh -c 'kill -9 \$\$'", 1, '/シグナル 9 で強制終了/u'],
                // sh が間に残るか（bash: 137）直接実行するか（dash・FreeBSD: シグナル）は sh しだい。どちらでも 1 通だけ
                'killed, the shell may stay in between' => ["sh -c 'kill -9 \$\$'", 1, '/終了コード 137|シグナル 9 で強制終了/u'],
                'handled failure' => ["sh -c 'exit 3'", 0, null],
            ];

            $expectedMails = 0;
            foreach ($cases as $label => [$command, $mailDelta, $expectedText]) {
                $backup->command = $command;
                $this->assertSame(0, Artisan::call('schedule:run'), $label);

                $expectedMails += $mailDelta;
                $messages = app('mailer')->getSymfonyTransport()->messages();
                $this->assertCount($expectedMails, $messages, $label);
                if ($expectedText !== null) {
                    $this->assertMatchesRegularExpression($expectedText, (string) $messages->last()->getOriginalMessage()->getTextBody(), $label);
                }
                $this->assertFalse($backup->mutex->exists($backup), $label.': ロックが外れていること');
            }
        } finally {
            @unlink($backup->output);
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
