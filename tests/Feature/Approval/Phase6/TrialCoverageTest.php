<?php

namespace Tests\Feature\Approval\Phase6;

use App\Enums\ApprovalStatus;
use App\Models\ApprovalMember;
use App\Models\ApprovalSetting;
use App\Models\User;
use App\Support\Approval\SubmitChecker;
use App\Support\Approval\TrialCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 提出の確かめと、試せる部門・種類（段階6 設計書 §5.4・計画 §0.7）。
 *
 * 試す期間（使い始める前で試す人が 1 人以上）の提出・出し直しは、回る先の人（部門長・審査の担当の 1 人以上・社長）が
 * みな試す人のときだけ。準備中と使い始めたあとは確かめない（今とまったく同じ）。
 */
class TrialCoverageTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const LEAD = '試す期間は、回る先の人がみな試す人のときだけ出せます。';

    /**
     * 試す人から外す（makeTester() の逆）。覚えた approvalMember を捨て、覚えた設定も捨てる
     * （覚えた設定が社長の関係を持っているため）。
     */
    private function unmakeTester(User ...$users): void
    {
        foreach ($users as $u) {
            ApprovalMember::where('user_id', $u->id)->update(['is_tester' => false]);
            $u->unsetRelation('approvalMember');
        }
        ApprovalSetting::forget();
    }

    public function test_in_trial_a_request_goes_out_when_everyone_on_the_route_is_a_tester(): void
    {
        $w = $this->trialWorld();

        $this->assertSame([], SubmitChecker::reasons($this->draftFor($w), $w['applicant']));
        $this->assertSame(ApprovalStatus::HeadReview, $this->submittedFor($w)->status);
    }

    public function test_each_missing_person_is_named_in_one_reason(): void
    {
        $w     = $this->trialWorld();
        $draft = $this->draftFor($w);

        $this->unmakeTester($w['head']);
        $this->assertSame([self::LEAD . "住宅事業部の部門長（{$w['head']->name}）が試す人に入っていません。"], SubmitChecker::reasons($draft->fresh(), $w['applicant']));

        $this->unmakeTester($w['reviewer'], $w['president']);
        $this->assertSame([self::LEAD . "住宅事業部の部門長（{$w['head']->name}）・審査部門「総務部」の審査の担当・社長（{$w['president']->name}）が試す人に入っていません。"], SubmitChecker::reasons($draft->fresh(), $w['applicant']));
    }

    /** 申請者が部門長本人なら部門長は見ない（要件 4.3。画面からは試す人しか出せないので本番では等価。ここで直に確かめる） */
    public function test_an_applicant_who_is_the_head_does_not_need_the_head(): void
    {
        $w = $this->trialWorld();
        $w['dept']->update(['head_user_id' => $w['applicant']->id]);
        $this->unmakeTester($w['applicant']);   // 部門長＝申請者本人を試す人から外しても、部門長としては見ない（ほかの 3 人が試す人なので試す期間のまま）

        $this->assertSame([], SubmitChecker::reasons($this->draftFor($w), $w['applicant']));
    }

    public function test_one_tester_among_the_reviewers_is_enough(): void
    {
        $w = $this->trialWorld();
        $w['reviewDept']->reviewers()->attach($this->baseUser(['name' => '試さない 審査'])->id);

        $this->assertSame([], SubmitChecker::reasons($this->draftFor($w), $w['applicant']));
    }

    public function test_the_applicant_does_not_count_as_the_tester_reviewer(): void
    {
        $w = $this->trialWorld();
        $w['reviewDept']->reviewers()->attach($w['applicant']->id);       // 申請者本人も審査担当者
        $this->unmakeTester($w['reviewer']);

        $this->assertSame([self::LEAD . '審査部門「総務部」の審査の担当が試す人に入っていません。'], SubmitChecker::reasons($this->draftFor($w), $w['applicant']));
    }

    public function test_an_unset_role_gives_only_the_reason_of_today(): void
    {
        $w = $this->trialWorld();
        $w['dept']->update(['head_user_id' => null]);

        $reasons = SubmitChecker::reasons($this->draftFor($w), $w['applicant']);
        $this->assertSame(["部門「住宅事業部」の部門長が未設定です。管理者に連絡してください。"], $reasons);
    }

    public function test_an_unset_president_gives_only_the_reason_of_today(): void
    {
        $w = $this->trialWorld();
        ApprovalSetting::current()->update(['president_user_id' => null]);
        ApprovalSetting::forget();

        $this->assertSame(['社長が未設定です。管理者に連絡してください。'], SubmitChecker::reasons($this->draftFor($w), $w['applicant']));
    }

    public function test_no_other_reviewer_gives_only_the_reason_of_today(): void
    {
        $w = $this->trialWorld();
        $w['reviewDept']->reviewers()->detach();

        $this->assertSame(['審査部門「総務部」に、申請者本人以外の審査担当者がいません。管理者に連絡してください。'], SubmitChecker::reasons($this->draftFor($w), $w['applicant']));
    }

    public function test_before_any_tester_the_route_is_not_checked(): void
    {
        $w = $this->approvalWorld();                                       // 準備中（試す人 0 人）
        $this->assertSame([], SubmitChecker::reasons($this->draftFor($w), $w['applicant']));
    }

    public function test_after_launch_the_route_is_not_checked(): void
    {
        $w = $this->approvalWorld();
        $this->makeTester($w['applicant']);
        $this->launchApprovals();

        $this->assertSame([], SubmitChecker::reasons($this->draftFor($w), $w['applicant']));
    }

    public function test_the_overview_marks_each_role_and_lists_the_combinations(): void
    {
        $w      = $this->trialWorld();
        $other  = $this->approvalDepartment($w['company'], ['name' => 'ミツワ不動産', 'code' => 'F', 'head_user_id' => $this->baseUser(['name' => '田中 次郎'])->id]);
        $land   = $this->approvalType($w['reviewDept'], ['name' => '土地の仕入れ']);
        $land->departments()->attach($other->id);                         // ミツワ不動産だけ

        $o = TrialCoverage::overview();

        $this->assertSame([$w['president']->id, true], [$o['president']['user']->id, $o['president']['ok']]);
        $this->assertSame([['住宅事業部', true], ['総務部', false], ['ミツワ不動産', false]], array_map(fn ($d) => [$d['department']->name, $d['ok']], $o['departments']));
        $this->assertSame('田中 次郎', $o['departments'][2]['head']->name);
        $this->assertSame([[$w['type']->name, true, []], ['土地の仕入れ', true, ['ミツワ不動産']]], array_map(fn ($t) => [$t['type']->name, $t['ok'], $t['limitedTo']], $o['types']));
        $this->assertSame(["住宅事業部 × {$w['type']->name}"], $o['combinations']);
    }

    public function test_no_combination_without_the_president(): void
    {
        $w = $this->trialWorld();
        $this->unmakeTester($w['president']);

        $this->assertSame([], TrialCoverage::overview()['combinations']);
    }
}
