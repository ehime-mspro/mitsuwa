<?php

namespace Tests\Feature\Approval\Phase3;

use App\Mail\PasswordReissuedMail;
use App\Models\ApprovalSetting;
use App\Models\User;
use App\Support\Approval\MailDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
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

    /** 決裁の管理者の画面（使い始める前）: ホーム（準備中）と管理の 3 画面 */
    private function adminPagesBeforeLaunch(): array
    {
        return [
            route('approvals.home'),
            route('approvals.admin.users.index'),
            route('approvals.admin.organization.index'),
            route('approvals.admin.types.index'),
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
}
