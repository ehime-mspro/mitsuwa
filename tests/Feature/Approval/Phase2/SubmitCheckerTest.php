<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\UserStatus;
use App\Models\ApprovalSetting;
use App\Support\Approval\BodyTemplate;
use App\Support\Approval\SubmitChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 提出の条件（要件 4.3 のケース 4・5.1・設計書 D4・D10・D12） */
class SubmitCheckerTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    public function test_a_complete_draft_has_no_reasons(): void
    {
        $world = $this->approvalWorld();

        $this->assertSame([], SubmitChecker::reasons($this->draftFor($world), $world['applicant']));
    }

    /** 社長は申請できない（D4） */
    public function test_the_president_cannot_submit(): void
    {
        $world = $this->approvalWorld();
        $this->makePresident($world['applicant']);

        $this->assertContains('社長に指定されている人は申請できません。', SubmitChecker::reasons($this->draftFor($world), $world['applicant']->fresh()));
    }

    public function test_someone_without_departments_cannot_submit(): void
    {
        $world = $this->approvalWorld();
        $world['applicant']->approvalDepartments()->detach();

        $reasons = SubmitChecker::reasons($this->draftFor($world), $world['applicant']);

        $this->assertContains('所属部門が未設定です。管理者に連絡してください。', $reasons);
    }

    public function test_the_department_must_be_one_of_mine(): void
    {
        $world = $this->approvalWorld();
        $other = $this->approvalDepartment($world['company'], ['name' => '賃貸事業部', 'head_user_id' => $world['head']->id]);

        $reasons = SubmitChecker::reasons($this->draftFor($world, ['department_id' => $other->id]), $world['applicant']);

        $this->assertContains('申請部門「賃貸事業部」に所属していません。申請部門を選び直してください。', $reasons);
    }

    /** 4.3 のケース 4: 部門長が未設定 */
    public function test_a_department_without_a_head_cannot_receive_requests(): void
    {
        $world = $this->approvalWorld();
        $world['dept']->update(['head_user_id' => null]);

        $this->assertContains(
            '部門「住宅事業部」の部門長が未設定です。管理者に連絡してください。',
            SubmitChecker::reasons($this->draftFor($world), $world['applicant'])
        );
    }

    /** 4.3 のケース 4: 社長が未設定 */
    public function test_nothing_can_be_submitted_without_a_president(): void
    {
        $world = $this->approvalWorld();
        ApprovalSetting::current()->update(['president_user_id' => null]);

        $this->assertContains('社長が未設定です。管理者に連絡してください。', SubmitChecker::reasons($this->draftFor($world), $world['applicant']));
    }

    /** 4.3 のケース 4: 申請者本人以外の有効な審査担当者がいない（無効な人は数えない） */
    public function test_there_must_be_another_active_reviewer(): void
    {
        $world = $this->approvalWorld();
        $world['reviewer']->status = UserStatus::Inactive->value;
        $world['reviewer']->save();
        $world['reviewDept']->reviewers()->attach($world['applicant']->id);

        $this->assertContains(
            '審査部門「総務部」に、申請者本人以外の審査担当者がいません。管理者に連絡してください。',
            SubmitChecker::reasons($this->draftFor($world), $world['applicant'])
        );
    }

    /** 停止した種類: 下書きは選び直し、差戻し中はそのまま出し直せる（D10） */
    public function test_a_stopped_type_blocks_drafts_but_not_returned_requests(): void
    {
        $world = $this->approvalWorld();
        $world['type']->update(['is_active' => false]);
        $draft = $this->draftFor($world);

        $this->assertContains('申請の種類「' . $world['type']->name . '」は使えなくなりました。種類を選び直してください。', SubmitChecker::reasons($draft, $world['applicant']));

        DB::table('approval_requests')->where('id', $draft->id)->update(['status' => 'returned', 'round' => 1]);

        $this->assertSame([], SubmitChecker::reasons($draft->refresh(), $world['applicant']));
    }

    /** 件名と本文（D12）。理由はまとめて返す */
    public function test_subject_and_body_are_required_and_reasons_are_collected(): void
    {
        $world = $this->approvalWorld();

        $reasons = SubmitChecker::reasons($this->draftFor($world, ['subject' => ' ', 'body' => BodyTemplate::DEFAULT, 'type_id' => null]), $world['applicant']);

        $this->assertSame([
            '申請の種類を選んでください。',
            '件名を入力してください。',
            '重点ポイント（5W2H）が見出しのままです。中身を書いてください。',
        ], $reasons);
    }
}
