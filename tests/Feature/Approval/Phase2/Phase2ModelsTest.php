<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalAttachment;
use App\Models\ApprovalDownloadLog;
use App\Models\ApprovalHistory;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;
use App\Models\ApprovalType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

class Phase2ModelsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    public function test_a_department_has_a_head_and_reviewers(): void
    {
        $world = $this->approvalWorld();

        $this->assertSame($world['head']->id, $world['dept']->head->id);
        $this->assertSame([$world['reviewer']->id], $world['reviewDept']->reviewers->pluck('id')->all());
    }

    /**
     * 有効な審査担当者（段階3 設計書 §5.7）: 無効の人と削除した人を入れない。審査担当者の並び（reviewers）には残る
     * （「いま誰の番か」の表示と部門の管理はこちらを使う）
     */
    public function test_active_reviewers_leave_out_inactive_and_deleted_users(): void
    {
        $world    = $this->approvalWorld();
        $inactive = $this->baseUser(['name' => '無効 審査']);
        $inactive->forceFill(['status' => 'inactive'])->save();
        $deleted  = $this->baseUser(['name' => '削除 審査']);
        $world['reviewDept']->reviewers()->attach([$inactive->id, $deleted->id]);
        $deleted->delete();

        $department = $world['reviewDept']->fresh();

        $this->assertSame([$world['reviewer']->id], $department->activeReviewers->pluck('id')->all());
        $this->assertSame(1, $department->activeReviewers()->count());
        $this->assertEqualsCanonicalizing([$world['reviewer']->id, $inactive->id], $department->reviewers->pluck('id')->all());
    }

    /** 12.6 の歯止めが出す理由（部門長が先・次に審査担当者・どちらでもなければ null） */
    public function test_the_assignment_label_names_the_department(): void
    {
        $world = $this->approvalWorld();

        $this->assertSame('決裁の部門「住宅事業部」の部門長', $world['head']->approvalAssignmentLabel());
        $this->assertSame('決裁の部門「総務部」の審査担当者', $world['reviewer']->approvalAssignmentLabel());
        $this->assertNull($world['applicant']->approvalAssignmentLabel());
    }

    /** 部門長と審査担当者の両方に指定されていれば、部門長の理由を出す（部門長が先） */
    public function test_the_head_label_wins_when_the_user_is_also_a_reviewer(): void
    {
        $world = $this->approvalWorld();
        $world['reviewDept']->reviewers()->attach($world['head']->id);

        $this->assertSame('決裁の部門「住宅事業部」の部門長', $world['head']->fresh()->approvalAssignmentLabel());
    }

    public function test_the_settings_know_whether_approvals_are_launched(): void
    {
        $this->assertFalse(ApprovalSetting::current()->isLaunched());

        $this->launchApprovals();

        $this->assertTrue(ApprovalSetting::current()->isLaunched());
    }

    public function test_a_new_request_starts_as_a_draft_with_round_zero(): void
    {
        $request = $this->draftFor($this->approvalWorld());

        $this->assertSame(ApprovalStatus::Draft, $request->status);
        $this->assertSame(0, $request->round);
        $this->assertSame(0, $request->lock_version);
        $this->assertSame('2,850,000円', $request->amountLabel());
    }

    public function test_an_approved_request_shows_the_decision_in_its_status(): void
    {
        $request = $this->draftFor($this->approvalWorld());
        DB::table('approval_requests')->where('id', $request->id)->update(['status' => 'approved', 'decision' => 'conditional']);

        $this->assertSame('決裁済み（条可）', $request->refresh()->statusLabel());
        $this->assertSame(ApprovalDecision::Conditional, $request->decision);
    }

    public function test_a_type_name_is_unique(): void
    {
        $world = $this->approvalWorld();

        $this->expectException(QueryException::class);
        ApprovalType::create(['name' => $world['type']->name, 'headings' => 'x', 'review_department_id' => $world['reviewDept']->id]);
    }

    public function test_a_number_is_unique(): void
    {
        $world = $this->approvalWorld();
        $a = $this->draftFor($world);
        $b = $this->draftFor($world);
        DB::table('approval_requests')->where('id', $a->id)->update(['number' => 'R8-J-001']);

        $this->expectException(QueryException::class);
        DB::table('approval_requests')->where('id', $b->id)->update(['number' => 'R8-J-001']);
    }

    public function test_one_sequence_row_per_department_and_year(): void
    {
        $world = $this->approvalWorld();
        $row = ['department_id' => $world['dept']->id, 'fiscal_year' => 2026, 'next_number' => 1, 'last_issued' => 0];
        DB::table('approval_number_sequences')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('approval_number_sequences')->insert($row);
    }

    /** 控え・記録・ダウンロードの記録は、あとから変えられない（要件 14.2） */
    public function test_append_only_records_cannot_be_updated_or_deleted(): void
    {
        $world   = $this->approvalWorld();
        $request = $this->draftFor($world);

        // ⚠ 書き換える値は実在する列にする。変わらない値を渡すと Eloquent は UPDATE を出さず、
        //   updating も起きない（守りが無くても緑になる）
        $records = [
            [ApprovalRevision::create(['request_id' => $request->id, 'round' => 1, 'snapshot' => ['subject' => 'x'], 'submitted_by' => $world['applicant']->id]), ['round' => 9]],
            [ApprovalHistory::create(['request_id' => $request->id, 'round' => 1, 'action' => 'submitted']), ['action' => 'tampered']],
            [ApprovalDownloadLog::create(['request_id' => $request->id, 'user_id' => $world['applicant']->id, 'kind' => 'attachment']), ['kind' => 'tampered']],
        ];

        foreach ($records as [$record, $change]) {
            try {
                $record->update($change);
                $this->fail(get_class($record) . ' が書き換えられた');
            } catch (RuntimeException $e) {
                $this->assertSame('この記録は書き換えられません。', $e->getMessage());
            }

            try {
                $record->delete();
                $this->fail(get_class($record) . ' が削除された');
            } catch (RuntimeException $e) {
                $this->assertSame('この記録は削除できません。', $e->getMessage());
            }
        }
    }

    /** 記録の表示名。審査の意見は可・保留・否を添える（詳細の画面の「操作の記録」） */
    public function test_a_history_label_names_the_review_opinion(): void
    {
        $this->assertSame('審査の意見（保留）', (new ApprovalHistory(['action' => 'reviewed', 'result' => 'hold']))->label());
        $this->assertSame('部門長が承認', (new ApprovalHistory(['action' => 'head_approved', 'result' => 'approve']))->label());
    }

    public function test_attachment_helpers(): void
    {
        $pdf  = new ApprovalAttachment(['stored_path' => 'approvals/1/abc.pdf', 'size' => 1572864]);
        $heic = new ApprovalAttachment(['stored_path' => 'approvals/1/abc.heic', 'size' => 300000]);

        $this->assertTrue($pdf->opensInline());
        $this->assertFalse($heic->opensInline(), 'HEIC はブラウザで開かない（D14）');
        $this->assertSame('1.5 MB', $pdf->sizeLabel());
        $this->assertSame('293 KB', $heic->sizeLabel());
    }

    /** 一括代入できるのは画面の入力の列と持ち主だけ（状態・番号・回数・日時は Workflow だけが書く） */
    public function test_state_columns_are_not_mass_assignable(): void
    {
        $this->assertSame(
            [
                'user_id', 'department_id', 'type_id', 'subject', 'amount', 'schedule', 'body', 'related_numbers',
                // 段階5 の中身（明細表と追加の欄・定型文。下書きと差戻し中に申請者が直す中身。状態の列ではない）
                'amount_table', 'tsubo', 'tsubo_price', 'staff', 'contract_date', 'fixed_text',
            ],
            (new ApprovalRequest())->getFillable()
        );
    }

    /**
     * 退職などで利用者を削除（論理削除）しても、申請・段階・記録・部門から読めること（Top trap #18）。
     * 審査担当者の並び（reviewers）だけは削除した人を出さない（今判断できる人の並び。提出の条件が使う）。
     */
    public function test_people_are_still_readable_after_they_are_deleted(): void
    {
        $world   = $this->approvalWorld();
        $request = $this->draftFor($world);
        $step    = ApprovalStep::create([
            'request_id' => $request->id, 'round' => 1, 'kind' => ApprovalStepKind::Head, 'department_id' => $world['dept']->id,
            'assignee_user_id' => $world['head']->id, 'status' => ApprovalStepStatus::Done, 'actor_user_id' => $world['head']->id,
        ]);
        $history = ApprovalHistory::create(['request_id' => $request->id, 'round' => 1, 'action' => 'head_approved', 'actor_user_id' => $world['head']->id, 'step_id' => $step->id]);

        foreach (['applicant', 'head', 'reviewer'] as $key) {
            $world[$key]->delete();
        }

        $this->assertSame($world['applicant']->id, $request->fresh()->applicant?->id, '申請者');
        $this->assertSame($world['head']->id, $world['dept']->fresh()->head?->id, '部門長');
        $this->assertSame($world['head']->id, $step->fresh()->actor?->id, '段階の判断した人');
        $this->assertSame($world['head']->id, $step->fresh()->assignee?->id, '段階の付け替えた担当');
        $this->assertSame($world['head']->id, $history->fresh()->actor?->id, '記録の操作した人');
        $this->assertSame([], $world['reviewDept']->fresh()->reviewers->pluck('id')->all(), '削除した審査担当者は並びに出さない');
    }
}
