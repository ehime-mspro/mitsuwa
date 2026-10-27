<?php

namespace Tests\Feature\Approval\Phase6;

use App\Enums\ApprovalLaunchStage;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Approval\ApprovalMenu;
use App\Support\Approval\LaunchState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 3 つの状態（準備中・試す期間・使い始めた）と、試す人にだけ開く画面（段階6 設計書 §5.1・§5.2・計画 §0.2）。
 *
 * 判定は LaunchState の 1 か所。門番・ホーム・サイドバー・基幹のメニューとベルがそれを読む。
 */
class TrialAccessTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    public function test_the_stage_follows_the_launch_and_the_testers(): void
    {
        $w = $this->approvalWorld();
        $this->assertSame(ApprovalLaunchStage::Preparing, LaunchState::stage());

        $this->makeTester($w['applicant']);
        $this->assertSame(ApprovalLaunchStage::Trial, LaunchState::stage());
        $this->assertTrue(LaunchState::inTrial());

        $w['applicant']->forceFill(['status' => UserStatus::Inactive->value])->save();
        $this->assertSame(ApprovalLaunchStage::Preparing, LaunchState::stage());

        $this->launchApprovals();
        $this->assertSame(ApprovalLaunchStage::Launched, LaunchState::stage());
        $this->assertSame(['準備中', '試す期間', '使い始めた'], array_map(fn ($s) => $s->label(), ApprovalLaunchStage::cases()));
    }

    public function test_in_trial_a_tester_gets_the_launched_home_and_the_links(): void
    {
        $w = $this->trialWorld();

        $this->actingAs($w['applicant'])->get(route('approvals.home'))
            ->assertOk()->assertViewIs('approvals.home-launched')
            ->assertSee(route('approvals.requests.create'))->assertSee(route('approvals.ledger.index'));
    }

    public function test_in_trial_a_non_tester_keeps_the_preparing_home(): void
    {
        $w = $this->approvalWorld();
        $this->makeTester($w['head']);
        $this->assertSame(ApprovalLaunchStage::Trial, LaunchState::stage(), '試す期間になっていない');

        foreach ([$w['applicant'], $this->approvalAdmin()] as $user) {
            $this->actingAs($user)->get(route('approvals.home'))
                ->assertOk()->assertViewIs('approvals.home')->assertDontSee(route('approvals.requests.create'));
        }
    }

    public function test_in_trial_the_menu_count_and_the_bell_are_only_for_testers(): void
    {
        $w = $this->approvalWorld();
        $this->makeTester($w['head']);

        $this->assertSame(0, ApprovalMenu::pendingCount($w['head']));
        $this->assertSame(0, ApprovalMenu::unreadNotices($w['head']));
        $this->assertNull(ApprovalMenu::pendingCount($w['applicant']));
        $this->assertNull(ApprovalMenu::unreadNotices($w['applicant']));
    }

    public function test_an_inactive_or_deleted_tester_is_not_a_tester(): void
    {
        $w = $this->approvalWorld();
        $this->makeTester($w['applicant'], $w['head']);

        $w['applicant']->forceFill(['status' => UserStatus::Inactive->value])->save();
        $w['head']->delete();

        $this->assertFalse(LaunchState::opensFor($w['applicant']->fresh()));
        $this->assertFalse(LaunchState::opensFor(User::withTrashed()->find($w['head']->id)));
        $this->assertSame(ApprovalLaunchStage::Preparing, LaunchState::stage());   // 有効な試す人がいない
    }

    public function test_after_launch_the_tester_flag_changes_nothing(): void
    {
        $w = $this->approvalWorld();
        $this->makeTester($w['head']);
        $this->launchApprovals();

        $this->assertTrue(LaunchState::opensFor($w['applicant']));
        $this->actingAs($w['applicant'])->get(route('approvals.home'))->assertViewIs('approvals.home-launched');
        $this->assertSame(0, ApprovalMenu::pendingCount($w['applicant']));
    }
}
