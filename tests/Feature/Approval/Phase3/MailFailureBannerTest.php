<?php

namespace Tests\Feature\Approval\Phase3;

use App\Mail\PasswordReissuedMail;
use App\Models\ApprovalSetting;
use App\Models\User;
use App\Support\Approval\MailDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use ReflectionProperty;
use RuntimeException;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 通知メールが送れないときの帯（段階3 設計書 D2・§5.6）と、パスワード再発行のメールも同じ記録を使うこと（D6）。
 */
class MailFailureBannerTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const BANNER = '通知メールが送れていません。';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 10:05', 'Asia/Tokyo')->utc());
        ApprovalSetting::current();   // 本番は SQL が入れた 1 行がある
    }

    /** 決裁の管理者の画面（使い始める前）: ホーム（準備中）と管理の 4 画面（3b で催促の設定を足した） */
    private function adminPagesBeforeLaunch(): array
    {
        return [
            route('approvals.home'),
            route('approvals.admin.users.index'),
            route('approvals.admin.organization.index'),
            route('approvals.admin.types.index'),
            route('approvals.admin.holidays.index'),
        ];
    }

    public function test_there_is_no_banner_while_mail_is_fine(): void
    {
        $admin = $this->approvalAdmin();
        MailDelivery::recordSent();

        foreach ($this->adminPagesBeforeLaunch() as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertDontSee(self::BANNER);
        }
    }

    /** 送れなかったら、決裁の管理者のホームと管理の画面の上に、日時（日本時間）と宛先を出す */
    public function test_a_failure_shows_the_banner_on_the_admin_pages(): void
    {
        $admin = $this->approvalAdmin();
        MailDelivery::recordFailed('山田 花子');

        foreach ($this->adminPagesBeforeLaunch() as $url) {
            $this->actingAs($admin)->get($url)->assertOk()
                ->assertSee('通知メールが送れていません。最後に送れなかったのは 9/30 10:05（宛先: 山田 花子さん）です。決裁のお知らせは画面にも届いています。メールの設定の確認が必要です。');
        }

        $this->launchApprovals();
        foreach ([route('approvals.home'), route('approvals.admin.requests.index')] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertSee(self::BANNER);
        }
    }

    /** 決裁の管理者でない人には出さない */
    public function test_the_banner_is_only_for_approval_admins(): void
    {
        MailDelivery::recordFailed('山田 花子');
        $this->launchApprovals();

        $this->actingAs($this->approvalOnlyUser())->get(route('approvals.home'))->assertOk()->assertDontSee(self::BANNER);
    }

    /** 送れなかったより後に 1 通でも送れたら消える。送れたのが前なら出たまま */
    public function test_a_later_success_clears_the_banner(): void
    {
        $admin = $this->approvalAdmin();
        MailDelivery::recordSent();
        $this->travel(1)->minutes();
        MailDelivery::recordFailed('山田 花子');

        $this->actingAs($admin)->get(route('approvals.home'))->assertSee(self::BANNER);

        $this->travel(1)->minutes();
        MailDelivery::recordSent();

        $this->actingAs($admin)->get(route('approvals.home'))->assertDontSee(self::BANNER);
    }

    /** パスワード再発行のメールも、送れた・送れなかったを同じ所に記録する（D6） */
    public function test_the_password_mail_records_success_and_failure(): void
    {
        $admin = $this->approvalAdmin();
        $mail  = (new PasswordReissuedMail(User::factory()->create(['name' => '再発行 太郎', 'must_change_password' => false]), '管理 花子', now(), 'https://example.com/login'))
            ->to('taro@mitsuwat.co.jp');

        Log::spy();
        $mail->failed(new RuntimeException('SMTP がつながりません'));
        $this->actingAs($admin)->get(route('approvals.home'))->assertSee('（宛先: 再発行 太郎さん）');

        $this->travel(1)->minutes();
        $mail->send(app(MailFactory::class));
        $this->actingAs($admin)->get(route('approvals.home'))->assertDontSee(self::BANNER);
    }

    /**
     * 送れた記録（recordSent）が書けなくても、メールは 1 通だけ・送れなかった扱いにも帯にもならない。
     * 記録の失敗でキューが同じメールを送り直すと、3 通届いたうえで事実と逆の帯が出る（2026-10-01 の最後の点検で実測）。
     * 本番と同じ database のキュー → queue:work の道を通し、記録の UPDATE だけを失敗させる（接続が切れた・列が無い、の代わり）
     */
    public function test_a_failure_to_record_a_sent_mail_does_not_make_the_queue_resend_it(): void
    {
        $this->resetWorkCommandListeners();
        config(['queue.default' => 'database']);
        $admin = $this->approvalAdmin();
        $user  = User::factory()->create(['name' => '再発行 太郎', 'must_change_password' => false]);

        Mail::to('taro@mitsuwat.co.jp')->queue(new PasswordReissuedMail($user, '管理 花子', now(), 'https://example.com/login'));
        $this->assertSame(1, DB::table('jobs')->count());

        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_contains($query, 'set "mail_last_sent_at"')) {
                throw new RuntimeException('接続が切れました');
            }
        });
        Log::spy();

        // 本番の定期実行と同じ設定。送り直しの間（60 秒）をまたいで 3 回処理する（直す前は 3 回とも送られ、3 回目のあとで failed が呼ばれた）
        for ($run = 1; $run <= 3; $run++) {
            $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--backoff' => 60, '--memory' => 1024])->assertExitCode(0);
            $this->travel(2)->minutes();
        }

        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        Log::shouldNotHaveReceived('error');
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'メールは送れたが、送れた記録を残せませんでした') && str_contains($message, '接続が切れました'))
            ->once();
        $this->actingAs($admin)->get(route('approvals.home'))->assertOk()->assertDontSee(self::BANNER);
    }

    /** WorkCommand は同じ PHP プロセスの中で 2 回目以降リスナーを登録し直さない（private static フラグ）。queue:work の前にリセットする */
    private function resetWorkCommandListeners(): void
    {
        (new ReflectionProperty(WorkCommand::class, 'hasRegisteredListeners'))->setValue(null, false);
    }
}
