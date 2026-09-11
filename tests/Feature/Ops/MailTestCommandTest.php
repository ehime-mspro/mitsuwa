<?php

namespace Tests\Feature\Ops;

use App\Mail\OpsTestMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailTestCommandTest extends TestCase
{
    public function test_test_mail_is_queued_for_the_address(): void
    {
        Mail::fake();

        $this->artisan('ops:mail-test', ['to' => 'kessai@example.com'])
            ->expectsOutputToContain('送信待ちに入れました')
            ->assertExitCode(0);

        // 送信待ち（キュー）に積むことで、定期実行によるキュー処理まで一緒に確かめられる
        Mail::assertQueued(OpsTestMail::class, fn (OpsTestMail $mail) => $mail->hasTo('kessai@example.com'));
    }

    public function test_invalid_address_is_refused(): void
    {
        Mail::fake();

        $this->artisan('ops:mail-test', ['to' => 'not-an-address'])->assertExitCode(1);

        Mail::assertNothingQueued();
    }

    public function test_without_an_address_it_goes_to_the_backup_failure_recipients(): void
    {
        // 失敗通知の宛先（BACKUP_NOTIFY_TO）に実際に届くかを、設定したその日に確かめられるようにする
        Mail::fake();
        config(['backup.notify_to' => 'admin@example.com、it@example.com']);

        $this->artisan('ops:mail-test')->assertExitCode(0);

        // 宛先ごとに 1 通ずつ（1 人の宛先が送信先のサーバーで拒否されても、ほかの人には届き、どの宛先が悪いか分かる）
        Mail::assertQueued(OpsTestMail::class, 2);
        Mail::assertQueued(OpsTestMail::class, fn (OpsTestMail $mail) => $mail->hasTo('admin@example.com') && ! $mail->hasTo('it@example.com'));
        Mail::assertQueued(OpsTestMail::class, fn (OpsTestMail $mail) => $mail->hasTo('it@example.com') && ! $mail->hasTo('admin@example.com'));
    }

    public function test_without_an_address_and_without_recipients_it_fails(): void
    {
        Mail::fake();
        config(['backup.notify_to' => '']);

        $this->artisan('ops:mail-test')
            ->expectsOutputToContain('BACKUP_NOTIFY_TO')
            ->assertExitCode(1);

        Mail::assertNothingQueued();
    }

    public function test_body_explains_what_the_mail_proves(): void
    {
        $mail = new OpsTestMail('2026/09/12 10:00');

        $mail->assertHasSubject('【テスト】基幹システムからのメール送信テスト');
        $mail->assertSeeInText('定期実行');
        $mail->assertSeeInText('2026/09/12 10:00');
    }
}
