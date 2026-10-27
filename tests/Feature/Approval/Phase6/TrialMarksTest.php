<?php

namespace Tests\Feature\Approval\Phase6;

use App\Enums\ApprovalStepResult;
use App\Support\Approval\ApprovalPdf;
use App\Support\Approval\PdfSheet;
use App\Support\Approval\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 試す期間の印（帯・PDF の上の余白の「試し」・Excel の「試し_」）（段階6 設計書 §5.6・計画 §0.6）。
 *
 * 試す期間（使い始める前で試す人が 1 人以上）だけ付く。準備中と使い始めたあとは今とまったく同じ。
 */
class TrialMarksTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const BANNER = '試しの期間です。ここで出した申請は使い始める前に消えます。';

    public function test_the_banner_is_on_the_request_screens_for_a_tester(): void
    {
        $w = $this->trialWorld();

        $this->actingAs($w['applicant'])->get(route('approvals.home'))->assertSee(self::BANNER);
        $this->actingAs($w['applicant'])->get(route('approvals.requests.create'))->assertSee(self::BANNER);
        $this->actingAs($w['head'])->get(route('dashboard.tenant'))->assertOk()->assertDontSee(self::BANNER);   // 基幹の画面には出さない（dashboard は転送だけなので行き先を名指し）
    }

    public function test_the_banner_is_on_the_admin_screens_only_in_trial(): void
    {
        $admin = $this->approvalAdmin();                                  // 試す人でない決裁の管理者
        $this->actingAs($admin)->get(route('approvals.admin.types.index'))->assertDontSee(self::BANNER);   // 準備中

        $this->makeTester($this->approvalOnlyUser());
        $this->actingAs($admin)->get(route('approvals.admin.types.index'))->assertSee(self::BANNER);
        $this->actingAs($this->approvalOnlyUser())->get(route('approvals.home'))->assertDontSee(self::BANNER);   // 試す人でも管理者でもない
    }

    public function test_the_pdf_and_the_excel_are_marked_in_trial(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Tokyo')->utc());
        $w       = $this->trialWorld();
        $request = $this->submittedFor($w);

        $this->assertTrue(PdfSheet::for($w['applicant'], $request->refresh())->trial);
        $this->assertStringContainsString('試し', view('approvals.requests._pdf_trial_header')->render());
        $this->actingAs($w['applicant'])->get(route('approvals.requests.pdf', $request))->assertOk();
        $this->assertSame(
            "attachment; filename=trial_ledger_2026-10-05.xlsx; filename*=utf-8''" . rawurlencode('試し_決裁台帳_2026-10-05.xlsx'),
            $this->actingAs($w['applicant'])->get(route('approvals.ledger.excel'))->headers->get('Content-Disposition'),
        );
    }

    /**
     * 上の余白の「試し」は本文と印を動かさない（段階6 設計書 §5.6）。文字の置き場（BT x y Td）と絵の置き場（… cm）が、上の余白の
     * 「試し」の 1 つだけ増え、ほかは同じ値・同じ順（見た目は Task 8 で確かめる。計画の点検で、部品を渡さない・本文を下げる変異で落ちることを確かめた）
     */
    public function test_the_trial_mark_does_not_move_the_body(): void
    {
        $w = $this->trialWorld();
        $r = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($r->refresh(), $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);   // 部門長の印も載せる
        $sheet  = PdfSheet::for($w['applicant'], $r->refresh());
        $top    = 841.89 - 12 * 72 / 25.4;   // A4 の高さ（pt）− margin_top 12mm。これより上が上の余白
        $places = function (string $pdf): array {
            preg_match_all('#stream\r?\n(.*?)\nendstream#s', $pdf, $streams);
            $found = [];
            foreach ($streams[1] as $stream) {
                $content = @gzuncompress($stream);   // 文字の流れだけ圧縮されている（PdfSheetTest と同じ読み方）
                if ($content !== false && preg_match_all('#BT -?[\d.]+ -?[\d.]+ Td|[-\d. ]+ cm#', $content, $m)) {
                    array_push($found, ...array_map('trim', $m[0]));
                }
            }

            return $found;
        };
        $plain = $places(ApprovalPdf::render(view('approvals.requests.pdf', ['sheet' => $sheet])->render(), view('approvals.requests._pdf_footer', ['sheet' => $sheet])->render(), 't'));
        $trial = $places(ApprovalPdf::sheet($sheet));
        $added = array_values(array_diff($trial, $plain));

        $this->assertCount(count($plain) + 1, $trial, '「試し」が 1 つ増えていない（sheet() が上の余白の部品を渡していない）');
        $this->assertCount(1, $added, '本文・印・下の文字の位置が動いた');
        $this->assertMatchesRegularExpression('#^BT [\d.]+ [\d.]+ Td$#', $added[0]);
        $this->assertGreaterThan($top, (float) explode(' ', $added[0])[2], '「試し」が上の余白（12mm）の中にない');
        $this->assertSame($plain, array_values(array_filter($trial, fn (string $op) => $op !== $added[0])), '本文・印・下の文字の位置か順が変わった');
    }

    public function test_after_launch_there_is_no_trial_mark(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Tokyo')->utc());
        $w = $this->trialWorld();
        $request = $this->submittedFor($w);
        $this->launchApprovals();

        $this->actingAs($w['applicant'])->get(route('approvals.home'))->assertDontSee(self::BANNER);
        $this->assertFalse(PdfSheet::for($w['applicant'], $request->refresh())->trial);
        $this->assertStringStartsWith('attachment; filename=ledger_2026-10-05.xlsx', $this->actingAs($w['applicant'])->get(route('approvals.ledger.excel'))->headers->get('Content-Disposition'));
    }
}
