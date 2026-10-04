<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalMember;
use App\Models\ApprovalStep;
use App\Support\Approval\Stamp;
use App\Support\Approval\StampSvg;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 印の描き方と申請の詳細の印（要件 9.1・段階4 設計書 §5.4・D8・D12） */
class StampDisplayTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 01:00:00', 'UTC'));   // 日本時間 10/5 10:00
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function judgedStamp(string $text): Stamp
    {
        $w = $this->approvalWorld();
        ApprovalMember::create(['user_id' => $w['head']->id, 'stamp_text' => $text]);
        $r = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);

        return Stamp::forStep(ApprovalStep::where('request_id', $r->id)->where('kind', 'head')->firstOrFail());
    }

    public function test_the_stamp_has_the_three_rows_in_the_stamp_color(): void
    {
        $svg = StampSvg::render($this->judgedStamp('山田'));

        $this->assertStringContainsString('aria-label="住宅 R8.10.5 山田 の印"', $svg);
        $this->assertSame(3, substr_count($svg, '<text '), '上段・中段・下段');
        $this->assertSame(1, substr_count($svg, '<circle '));
        $this->assertSame(2, substr_count($svg, '<line '));
        $this->assertStringContainsString('>住宅</text>', $svg);
        $this->assertStringContainsString('>R8.10.5</text>', $svg);
        $this->assertStringContainsString('>山田</text>', $svg);
        $this->assertSame(6, substr_count($svg, '"' . StampSvg::COLOR . '"'), '丸・線 2 本・文字 3 つは朱の色');
    }

    public function test_the_text_is_escaped(): void
    {
        $svg = StampSvg::render($this->judgedStamp('<b>'));

        $this->assertStringNotContainsString('<b>', $svg);
        $this->assertStringContainsString('>&lt;b&gt;</text>', $svg);
    }

    public function test_longer_text_is_drawn_smaller_to_stay_inside_the_stamp(): void
    {
        $this->assertSame(24, StampSvg::fit('山田', 62, 24));
        $this->assertSame(20, StampSvg::fit('長谷川', 62, 24));
        $this->assertSame(15, StampSvg::fit('勅使河原', 62, 24));
        $this->assertSame(8, StampSvg::fit(str_repeat('長', 20), 62, 24), '下限は 8');
        $this->assertSame(11, StampSvg::fit('住宅事業部長', 66, 20), '上段の 6 文字');
    }

    public function test_the_stamp_is_drawn_with_the_fitted_sizes(): void
    {
        $svg = StampSvg::render($this->judgedStamp('勅使河原'));

        $this->assertStringContainsString('font-size="20" fill="' . StampSvg::COLOR . '">住宅</text>', $svg, '上段の 2 文字は上限の 20');
        $this->assertStringContainsString('font-size="15" fill="' . StampSvg::COLOR . '">勅使河原</text>', $svg, '下段の 4 文字は 15 に縮める');
    }

    public function test_a_long_department_name_is_drawn_smaller_in_the_top_row(): void
    {
        $w = $this->approvalWorld();
        $w['dept']->update(['short_name' => '住宅事業部長']);
        $r = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);

        $svg = StampSvg::render(Stamp::forStep(ApprovalStep::where('request_id', $r->id)->where('kind', 'head')->firstOrFail()));

        $this->assertStringContainsString('font-size="11" fill="' . StampSvg::COLOR . '">住宅事業部長</text>', $svg, '上段の 6 文字は 11 に縮める');
    }

    public function test_the_detail_shows_the_stamp_beside_each_judged_step(): void
    {
        $this->launchApprovals();
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $r))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-stamp'), '判断した段階は部門長だけ');
        $this->assertStringContainsString('aria-label="住宅 R8.10.5 部門 の印"', $html);
    }

    public function test_a_skipped_head_step_shows_the_reason_instead_of_a_stamp(): void
    {
        $this->launchApprovals();
        $w = $this->approvalWorld();
        $w['head']->approvalDepartments()->attach($w['dept']->id);
        $r = $this->submittedFor(array_merge($w, ['applicant' => $w['head']->fresh()]));

        $html = $this->actingAs($w['head'])->get(route('approvals.requests.show', $r))->assertOk()->getContent();

        $this->assertStringContainsString('申請者が部門長のため省略', $html);
        $this->assertStringNotContainsString('data-stamp', $html);
    }
}
