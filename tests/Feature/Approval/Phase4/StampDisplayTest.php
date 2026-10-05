<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalMember;
use App\Models\ApprovalStep;
use App\Support\Approval\ApprovalPdf;
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

    public function test_the_department_short_name_in_the_top_row_is_escaped(): void
    {
        $w = $this->approvalWorld();
        $w['dept']->update(['short_name' => '<i>&"']);
        $r = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);

        $svg = StampSvg::render(Stamp::forStep(ApprovalStep::where('request_id', $r->id)->where('kind', 'head')->firstOrFail()));

        $this->assertStringNotContainsString('<i>', $svg);
        $this->assertStringContainsString('>&lt;i&gt;&amp;&quot;</text>', $svg, '上段（部門の略称）は打った文字のまま出す');
        $this->assertStringContainsString('aria-label="&lt;i&gt;&amp;&quot; R8.10.5 部門 の印"', $svg);
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

    /** PDF の中の文字の置き方（`q 1 0 0 1 <ずらし> 0 cm … /F<n> <大きさ> Tf`）。@return list<array{0: float, 1: float}> [ずらし, 文字の大きさ] を描いた順に */
    private static function textShifts(string $pdf): array
    {
        preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $pdf, $streams);
        $shifts = [];
        foreach ($streams[1] as $stream) {
            $content = @gzuncompress($stream);   // 文字の流れだけ圧縮されている（フォントなどは別の形）
            if ($content !== false && preg_match_all('#q 1 0 0 1 (-?[\d.]+) 0 cm q [\d. ]+ g BT\s+/F\d+ ([\d.]+) Tf#', $content, $found, PREG_SET_ORDER)) {
                foreach ($found as $m) {
                    $shifts[] = [(float) $m[1], (float) $m[2]];
                }
            }
        }

        return $shifts;
    }

    public function test_the_pdf_stamp_is_the_same_drawing_in_a_box_of_the_printed_size(): void
    {
        $stamp  = $this->judgedStamp('長谷川');
        $screen = StampSvg::render($stamp);
        $pdf    = StampSvg::render($stamp, StampSvg::PDF_FONT, '80');

        $this->assertStringContainsString('viewBox="0 0 120 120" width="48" height="48"', $screen, '画面は 120 の箱のまま（ブラウザは text-anchor で中央に収める）');
        $this->assertStringNotContainsString('<g ', $screen);
        // PDF は viewBox を描く大きさ（px）にして、120 の箱の中身を縮める。viewBox が 120 のままだと mPDF が文字の幅を縮尺の分だけ短く測り、文字が右に寄る
        $this->assertStringContainsString('viewBox="0 0 80 80" width="80" height="80"', $pdf);
        $this->assertSame(1, substr_count($pdf, '<g transform="scale(0.666667)"><circle '));
        $this->assertSame(1, substr_count($pdf, '</g></svg>'));
        $this->assertSame(3, substr_count($pdf, 'text-anchor="middle"'), '文字は 3 つとも中央寄せ');
        // 3 段・座標・大きさ・色・読み上げの名前は画面と同じ（違うのは書体だけ）
        $body = fn (string $svg) => preg_match('#<circle .*</text>#s', $svg, $m) ? $m[0] : null;
        $this->assertNotNull($body($screen));
        $this->assertSame(str_replace(e(StampSvg::SCREEN_FONT), StampSvg::PDF_FONT, $body($screen)), $body($pdf));
        $this->assertSame(1, preg_match('#aria-label="住宅 R8.10.5 長谷川 の印"#', $pdf));
    }

    public function test_mpdf_puts_the_pdf_stamp_text_on_the_center_of_the_circle(): void
    {
        $stamp = $this->judgedStamp('長谷川');   // 上段「住宅」・中段「R8.10.5」・下段「長谷川」
        $pdf   = ApprovalPdf::render('<html><body>' . StampSvg::render($stamp, StampSvg::PDF_FONT, '80') . '</body></html>', '<div></div>', 'x');

        $shifts = self::textShifts($pdf);
        $this->assertCount(3, $shifts, 'PDF の文字の置き方の形が変わった（mPDF の出力を見直す）');
        [[$labelShift, $labelSize], [$dateShift, $dateSize], [$textShift, $textSize]] = $shifts;

        // 中央寄せは「文字の幅の半分だけ左へ」。全角の字の幅は 1 字ぶんの大きさ。mPDF が幅を縮尺の分だけ短く測ると、左へのずらしが足りず文字が右に寄る
        $this->assertEqualsWithDelta(-2 * $labelSize / 2, $labelShift, 0.2, '上段「住宅」');
        $this->assertEqualsWithDelta(-3 * $textSize / 2, $textShift, 0.2, '下段「長谷川」');
        $this->assertEqualsWithDelta(-1.82 * $dateSize, $dateShift, 0.15 * $dateSize, '中段「R8.10.5」（IPAex 明朝で 3.64 字ぶんの幅の半分）');
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
