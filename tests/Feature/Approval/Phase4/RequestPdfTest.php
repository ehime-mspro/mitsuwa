<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalDownloadLog;
use App\Models\ApprovalRequest;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 決裁申請書の PDF の出力（要件 9.2・14.2・段階4 設計書 §5.6・§5.7・D17・D18） */
class RequestPdfTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 01:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function decided(array $w): ApprovalRequest
    {
        $workflow = app(Workflow::class);
        $r = $this->submittedFor($w);
        $workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $workflow->judgeReview($r->fresh(), $w['reviewer'], $r->fresh()->lock_version, ApprovalStepResult::Ok, null);
        $workflow->judgePresident($r->fresh(), $w['president'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);

        return $r->fresh();
    }

    public function test_a_viewer_gets_the_pdf_inline_and_it_is_recorded(): void
    {
        $this->launchApprovals();
        $w = $this->approvalWorld();
        $r = $this->decided($w);

        $response = $this->actingAs($w['reviewer'])->get(route('approvals.requests.pdf', $r))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame(
            "inline; filename=approval-R8-J-001.pdf; filename*=utf-8''" . rawurlencode('決裁申請書_R8-J-001.pdf'),
            $response->headers->get('Content-Disposition'),
            'ブラウザでそのまま開く・日本語の名前と ASCII の代わりの名前'
        );
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        $this->assertSame(
            [[$r->id, null, $w['reviewer']->id, 'pdf']],
            ApprovalDownloadLog::all()->map(fn (ApprovalDownloadLog $l) => [$l->request_id, $l->attachment_id, $l->user_id, $l->kind])->all(),
        );
    }

    public function test_someone_who_cannot_see_the_request_gets_404_and_nothing_is_recorded(): void
    {
        $this->launchApprovals();
        $w = $this->approvalWorld();
        $r = $this->decided($w);
        $stranger = $this->baseUser(['name' => '無関係 太郎']);

        $this->actingAs($stranger)->get(route('approvals.requests.pdf', $r))->assertNotFound();

        $this->assertSame(0, ApprovalDownloadLog::count());
    }

    public function test_a_draft_is_not_printed_even_for_the_applicant(): void
    {
        $this->launchApprovals();
        $w = $this->approvalWorld();
        $draft = $this->draftFor($w);

        $this->actingAs($w['applicant'])->get(route('approvals.requests.pdf', $draft))->assertNotFound();

        $this->assertSame(0, ApprovalDownloadLog::count());
    }

    public function test_before_launch_the_pdf_is_not_offered(): void
    {
        $w = $this->approvalWorld();
        $r = $this->decided($w);

        $this->actingAs($w['applicant'])->get(route('approvals.requests.pdf', $r))->assertRedirect(route('approvals.home'));

        $this->assertSame(0, ApprovalDownloadLog::count());
    }

    public function test_the_detail_has_the_link_once_the_request_is_submitted(): void
    {
        $this->launchApprovals();
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $r))->assertOk()->getContent();

        $this->assertStringContainsString('href="' . route('approvals.requests.pdf', $r) . '"', $html);
        $this->assertStringContainsString('PDF を出力', $html);

        $draft = $this->draftFor($w);
        $draftHtml = $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $draft))->assertOk()->getContent();
        $this->assertStringNotContainsString('PDF を出力', $draftHtml, '下書きの詳細にはボタンを出さない');
    }

    public function test_when_the_pdf_cannot_be_made_the_detail_says_so_and_the_log_has_the_error(): void
    {
        $this->launchApprovals();
        $w = $this->approvalWorld();
        $r = $this->decided($w);
        config(['approval.pdf.font_dir' => storage_path('framework/testing/no-fonts-here')]);
        Log::spy();

        $this->actingAs($w['applicant'])->get(route('approvals.requests.pdf', $r))
            ->assertRedirect(route('approvals.requests.show', $r))
            ->assertSessionHas('error', 'PDF を作れませんでした。時間をおいてもう一度お試しください。');

        Log::shouldHaveReceived('error')->with('決裁申請書の PDF を作れませんでした', Mockery::on(fn (array $context) => $context['request_id'] === $r->id))->once();
        $this->assertSame(0, ApprovalDownloadLog::count(), '作れなかったときは記録しない');
    }
}
