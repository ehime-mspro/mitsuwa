<?php

use App\Console\Commands\BackupCommand;
use App\Support\Backup\BackupFailureNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| 定期実行（要件定義書 15.3）
|--------------------------------------------------------------------------
|
| さくらの CRON から 5 分おきに `schedule:run` を起動する（毎分は不可・常駐プロセスは禁止）。
| 時刻は日本時間（config/app.php の schedule_timezone）。
|
*/

// 送信待ちのメールなどを、空になるまで処理して終わる（常駐させない）。
// CRON（5 分おき）で schedule:run が起動されるたびに回す（CRON の分の設定がずれていても止まらないように）
Schedule::command('queue:work --stop-when-empty --max-time=240 --tries=3 --backoff=60')
    ->everyMinute()
    ->withoutOverlapping(10);

// 夜間バックアップ（DB 全体と添付を暗号化してオブジェクトストレージへ）。
// 3:00〜3:04 に起動された 1 回だけ実行する。「3:00 ちょうど」にすると、CRON の起動が少し遅れたり、
// 分の設定が 0・5・10… 以外だったりしたときに、知らせのないまま一度も動かなくなるため
// （5 分おきの起動なら、ずれがあっても毎日ちょうど 1 回になる）
$backup = Schedule::command('ops:backup')
    ->cron('0-4 3 * * *')
    ->withoutOverlapping(180);

// コマンド自身が知らせられなかった終わり方（捕まえきれなかった例外・メモリ不足・強制終了など）だけを、スケジューラー側で知らせる。
// 終了コード BackupCommand::HANDLED_FAILURE は、コマンドが自分で失敗を扱って知らせを送った印なので、二重には送らない。
$backup->onFailure(function () use ($backup) {
    if ($backup->exitCode !== BackupCommand::HANDLED_FAILURE) {
        app(BackupFailureNotifier::class)->send(
            '夜間バックアップの処理が途中で止まりました（終了コード '.$backup->exitCode.'）。サーバーのメモリ不足・強制終了・予期しないエラーの可能性があります。',
            CarbonImmutable::now('Asia/Tokyo'),
        );
    }
});
