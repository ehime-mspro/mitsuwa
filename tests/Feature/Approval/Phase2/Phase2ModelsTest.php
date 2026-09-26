<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStatus;
use App\Models\ApprovalAttachment;
use App\Models\ApprovalDownloadLog;
use App\Models\ApprovalHistory;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\ApprovalSetting;
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

    /** 12.6 の歯止めが出す理由（部門長が先・次に審査担当者・どちらでもなければ null） */
    public function test_the_assignment_label_names_the_department(): void
    {
        $world = $this->approvalWorld();

        $this->assertSame('決裁の部門「住宅事業部」の部門長', $world['head']->approvalAssignmentLabel());
        $this->assertSame('決裁の部門「総務部」の審査担当者', $world['reviewer']->approvalAssignmentLabel());
        $this->assertNull($world['applicant']->approvalAssignmentLabel());
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

    /** 画面から来ない列（状態・番号・回数）は fillable に入れない（Workflow だけが書く） */
    public function test_state_columns_are_not_mass_assignable(): void
    {
        $fillable = (new ApprovalRequest())->getFillable();

        foreach (['status', 'decision', 'number', 'round', 'lock_version', 'finished_at'] as $column) {
            $this->assertNotContains($column, $fillable);
        }
    }
}
