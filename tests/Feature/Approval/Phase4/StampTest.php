<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalMember;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Support\Approval\Stamp;
use App\Support\Approval\StampText;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 印の文字と控え（要件 9.1・段階4 設計書 §5.4・D3・D6〜D8） */
class StampTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-04 16:30:00', 'UTC'));   // 日本時間 10/5 1:30（UTC では 10/4）
        $this->workflow = app(Workflow::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function step(ApprovalRequest $request, ApprovalStepKind $kind): ApprovalStep
    {
        return ApprovalStep::where('request_id', $request->id)->where('round', $request->fresh()->round)->where('kind', $kind->value)->firstOrFail();
    }

    /** @return array{string, string, string} 上段・日付・下段 */
    private function stampOf(ApprovalRequest $request, ApprovalStepKind $kind): array
    {
        $stamp = Stamp::forStep($this->step($request, $kind));
        $this->assertNotNull($stamp, "{$kind->value} の印が無い");

        return [$stamp->label, $stamp->date, $stamp->text];
    }

    public function test_the_default_text_is_the_name_before_the_first_space(): void
    {
        $this->assertSame('山田', StampText::defaultFor('山田 太郎'));
        $this->assertSame('山田', StampText::defaultFor('山田　太郎'), '全角の空白でも区切る');
        $this->assertSame('鈴木', StampText::defaultFor('　 鈴木 一郎 '), '前後の空白は先に除く');
        $this->assertSame('長谷川', StampText::defaultFor('長谷川  三郎'), '空白が続いても最初の区切りまで');
        $this->assertSame('山田太郎', StampText::defaultFor('山田太郎'), '空白の無い氏名は氏名全体');
    }

    public function test_the_text_set_by_the_admin_wins_over_the_name(): void
    {
        $user = $this->baseUser(['name' => '山田太郎']);
        $this->assertSame('山田太郎', StampText::for($user));

        ApprovalMember::create(['user_id' => $user->id, 'stamp_text' => '山田']);
        $this->assertSame('山田', StampText::for($user->fresh()));

        ApprovalMember::where('user_id', $user->id)->update(['stamp_text' => '']);
        $this->assertSame('山田太郎', StampText::for($user->fresh()), '空の文字は氏名からの既定に戻る');
    }

    public function test_each_judgement_records_its_stamp(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->judgeReview($r->fresh(), $w['reviewer'], $r->fresh()->lock_version, ApprovalStepResult::Hold, '確認中');
        $this->workflow->judgePresident($r->fresh(), $w['president'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);

        // 部門長は申請部門・審査は審査部門の略称・社長は「社長」。日付は日本時間の暦（UTC の 10/4 は日本の 10/5）
        $this->assertSame(['住宅', 'R8.10.5', '部門'], $this->stampOf($r, ApprovalStepKind::Head));
        $this->assertSame(['総務', 'R8.10.5', '審査'], $this->stampOf($r, ApprovalStepKind::Review));
        $this->assertSame(['社長', 'R8.10.5', '社長'], $this->stampOf($r, ApprovalStepKind::President));
        $this->assertSame(['住宅', '部門'], [$this->step($r, ApprovalStepKind::Head)->stamp_label, $this->step($r, ApprovalStepKind::Head)->stamp_text], '判断したときに控える');
    }

    public function test_a_return_is_stamped_too(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Return, '金額を見直してください');

        $this->assertSame(['住宅', 'R8.10.5', '部門'], $this->stampOf($r, ApprovalStepKind::Head));
    }

    public function test_changing_the_settings_later_does_not_change_the_stamp(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);

        $w['dept']->update(['short_name' => '住宅事業']);
        ApprovalMember::create(['user_id' => $w['head']->id, 'stamp_text' => '別名']);
        $w['head']->update(['name' => '改姓 長']);

        $this->assertSame(['住宅', 'R8.10.5', '部門'], $this->stampOf($r, ApprovalStepKind::Head));
    }

    public function test_the_stamp_uses_the_text_set_at_the_time_of_the_judgement(): void
    {
        $w = $this->approvalWorld();
        ApprovalMember::create(['user_id' => $w['reviewer']->id, 'stamp_text' => '高橋']);
        $r = $this->submittedFor($w);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->judgeReview($r->fresh(), $w['reviewer'], $r->fresh()->lock_version, ApprovalStepResult::Ok, null);

        $this->assertSame(['総務', 'R8.10.5', '高橋'], $this->stampOf($r, ApprovalStepKind::Review));
    }

    public function test_undo_removes_the_stamp_with_the_judgement(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $r     = $this->submittedFor($w);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);

        $this->workflow->undo($r->fresh(), $admin, $r->fresh()->lock_version, '押し間違い');

        $head = $this->step($r, ApprovalStepKind::Head);
        $this->assertNull(Stamp::forStep($head));
        $this->assertSame([null, null], [$head->stamp_label, $head->stamp_text], '控えも消える');
    }

    public function test_steps_that_are_not_judged_have_no_stamp(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->assertNull(Stamp::forStep($this->step($r, ApprovalStepKind::Head)), '待ち');
        $this->assertNull(Stamp::forStep($this->step($r, ApprovalStepKind::President)), 'まだ届いていない');
    }

    public function test_a_judgement_without_a_recorded_stamp_is_drawn_from_the_current_settings(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        ApprovalStep::where('request_id', $r->id)->update(['stamp_label' => null, 'stamp_text' => null]);

        $this->assertSame(['住宅', 'R8.10.5', '部門'], $this->stampOf($r, ApprovalStepKind::Head));
    }

    public function test_the_date_is_in_the_japanese_era_of_the_calendar_day_in_japan(): void
    {
        $this->assertSame('R8.10.4', Stamp::eraDate(Carbon::parse('2026-10-04 14:59:59', 'UTC')), '日本時間 10/4 23:59');
        $this->assertSame('R8.10.5', Stamp::eraDate(Carbon::parse('2026-10-04 15:00:00', 'UTC')), '日本時間 10/5 0:00');
        $this->assertSame('R1.5.1', Stamp::eraDate(Carbon::parse('2019-04-30 15:00:00', 'UTC')), '令和の初日');
        $this->assertSame('H31.4.30', Stamp::eraDate(Carbon::parse('2019-04-30 14:59:59', 'UTC')), '平成の最後の日');
        $this->assertSame('R9.1.1', Stamp::eraDate(Carbon::parse('2026-12-31 15:00:00', 'UTC')), '年度ではなく暦の年');
    }
}
