<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalAttachment;
use App\Models\ApprovalDownloadLog;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 出し直した申請の変更点と、提出の回ごとの履歴（詳細の画面③。段階2 設計書 §5.13・2b 計画 Task 3）。
 *
 * ⚠ どちらも提出した控えどうしで作る（差戻し中の直しかけは、出し直すまで申請者だけ。D26）。
 */
class RequestChangesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const AJAX = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function upload(User $user, ApprovalRequest $request, string $name): TestResponse
    {
        return $this->actingAs($user)->post(route('approvals.requests.attachments.store', $request), [
            'file' => UploadedFile::fake()->create($name, 10, 'application/pdf'),
        ], self::AJAX);
    }

    /** 部門長が差し戻す */
    private function returnByHead(array $w, ApprovalRequest $request): ApprovalRequest
    {
        app(Workflow::class)->judgeHead($request->refresh(), $w['head'], $request->lock_version, ApprovalStepResult::Return, '直してください');

        return $request->refresh();
    }

    /** 添付を 1 つ付けて提出し、差し戻されたあと、件名・金額・本文を直して添付を差し替え、出し直した申請 */
    private function resubmittedWithChanges(array $w): ApprovalRequest
    {
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, '見積書.pdf')->assertOk();
        app(Workflow::class)->submit($draft->refresh(), $w['applicant']);
        $request = $this->returnByHead($w, $draft);

        $request->update(['subject' => '社用車の購入（2 台）', 'amount' => 5700000, 'body' => "■ なぜ（目的・理由）\n・老朽化のため\n・台数を増やす"]);
        $this->actingAs($w['applicant'])->delete(route('approvals.attachments.destroy', ApprovalAttachment::where('original_name', '見積書.pdf')->sole()), [], self::AJAX)->assertOk();
        $this->upload($w['applicant'], $request, '見積書（改）.pdf')->assertOk();
        app(Workflow::class)->submit($request->refresh(), $w['applicant']);

        return $request->refresh();
    }

    private function showHtml(User $user, ApprovalRequest $request): string
    {
        return (string) $this->actingAs($user)->get(route('approvals.requests.show', $request))->assertOk()->getContent();
    }

    /** 部門長が差し戻し、申請者が件名だけを直して出し直した申請 */
    private function returnAndResubmit(array $w, ApprovalRequest $request, string $subject): ApprovalRequest
    {
        $request = $this->returnByHead($w, $request);
        $request->update(['subject' => $subject]);
        app(Workflow::class)->submit($request->refresh(), $w['applicant']);

        return $request->refresh();
    }

    /** 見出しから、その節の終わり（</section>）まで。見出しが無ければ落とす（ページ全体を見て空振りしない） */
    private function section(string $html, string $heading): string
    {
        $at = strpos($html, $heading);
        $this->assertNotFalse($at, "「{$heading}」が無い");

        return substr($html, $at, strpos($html, '</section>', $at) - $at);
    }

    public function test_a_resubmitted_request_shows_what_changed_from_the_previous_round(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->resubmittedWithChanges($w);

        $html = $this->showHtml($w['head'], $request);

        $this->assertStringContainsString('前回からの変更点', $html);
        $this->assertStringContainsString('（1 回目 → 2 回目の提出）', $html);
        $this->assertStringContainsString('<span class="text-red-700 line-through"><span class="sr-only">前: </span>社用車の購入</span>', $html);
        $this->assertStringContainsString('<span class="font-semibold text-emerald-800"><span class="sr-only">後: </span>社用車の購入（2 台）</span>', $html);
        $this->assertStringContainsString('<span class="sr-only">前: </span>2,850,000円</span>', $html);
        $this->assertStringContainsString('<span class="sr-only">後: </span>5,700,000円</span>', $html);
        $this->assertStringContainsString('<span class="sr-only">増えた行: </span><span class="whitespace-pre-wrap break-words min-w-0">・台数を増やす</span>', $html);
        $added   = ApprovalAttachment::where('original_name', '見積書（改）.pdf')->sole();
        $removed = ApprovalAttachment::where('original_name', '見積書.pdf')->sole();
        $this->assertStringContainsString('<span class="sr-only">足した添付: </span><a href="' . route('approvals.attachments.show', $added) . '"', $html);
        $this->assertStringContainsString('<span class="sr-only">外した添付: </span><a href="' . route('approvals.attachments.show', $removed) . '"', $html);
        // 変わっていない項目（実施時期・種類・申請部門）は出さない
        $section = substr($html, strpos($html, '前回からの変更点'));
        $section = substr($section, 0, strpos($section, '</section>'));
        foreach (['実施時期', '申請の種類', '申請部門', '関連する決裁No'] as $unchanged) {
            $this->assertStringNotContainsString($unchanged, $section, "変わっていない「{$unchanged}」が出た");
        }
    }

    public function test_the_applicant_also_sees_the_changes(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->resubmittedWithChanges($w);

        $this->assertStringContainsString('<span class="sr-only">後: </span>社用車の購入（2 台）</span>', $this->showHtml($w['applicant'], $request));
    }

    public function test_a_first_submission_has_no_changes_section(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $html = $this->showHtml($w['head'], $request);

        $this->assertStringNotContainsString('前回からの変更点', $html);
        $this->assertStringContainsString('1 回目の提出', $html, '履歴は 1 回目から出す');
    }

    public function test_a_resubmission_without_changes_says_nothing_changed(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->returnByHead($w, $this->submittedFor($w));
        app(Workflow::class)->submit($request, $w['applicant']);

        $html = $this->showHtml($w['head'], $request->refresh());

        $this->assertStringContainsString('前回の提出から、中身と添付は変わっていません。', $html);
    }

    public function test_the_history_shows_each_round_with_its_own_content(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->resubmittedWithChanges($w);

        $html = $this->showHtml($w['head'], $request);

        // 新しい回から順に並び、最後に提出した回に印を付ける
        $this->assertSame(1, preg_match('/<summary[^>]*>\s*2 回目の提出.*?（最後に提出した中身）.*?<\/summary>.*?<summary[^>]*>\s*1 回目の提出/su', $html));
        // 1 回目の控えの中身（直す前の件名・外した添付・部門長の差戻しとコメント）
        $first = substr($html, strpos($html, '1 回目の提出'));
        $this->assertStringContainsString('<dd class="text-gray-900 break-words">社用車の購入</dd>', $first);
        $this->assertStringContainsString('>見積書.pdf</a>', $first);
        $this->assertStringContainsString('差戻し', $first);
        $this->assertStringContainsString('直してください', $first);
        // その回の判断は、その回の段階だけ（2 回目の部門長の段階〈待ち〉を 1 回目に混ぜない。2b 計画 Task 8 の変異 C03）
        $firstRound = substr($first, 0, strpos($first, '</details>'));
        $this->assertSame(1, substr_count(substr($firstRound, strpos($firstRound, 'この回の判断')), '>部門長</span>'));
        // 外した添付は、その回の中からその添付を開ける（リンク先。2b 計画 Task 8 の変異 C10）
        $removed = ApprovalAttachment::where('original_name', '見積書.pdf')->sole();
        $this->assertStringContainsString('<a href="' . route('approvals.attachments.show', $removed) . '" target="_blank" rel="noopener" class="text-emerald-600 hover:underline break-all">見積書.pdf</a>', $firstRound);
    }

    public function test_a_removed_attachment_can_be_opened_from_the_history_by_others(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->resubmittedWithChanges($w);
        $removed = ApprovalAttachment::where('original_name', '見積書.pdf')->sole();
        $this->assertNotNull($removed->removed_at, '前提: 外した添付');

        $this->actingAs($w['head'])->get(route('approvals.attachments.show', $removed))->assertOk();

        $this->assertSame(1, ApprovalDownloadLog::where('attachment_id', $removed->id)->where('user_id', $w['head']->id)->count(), '開いた記録を残す（§5.7）');
    }

    public function test_the_in_progress_edits_are_not_in_the_changes_or_the_history_for_others(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->returnByHead($w, $this->resubmittedWithChanges($w));
        $request->update(['subject' => '直しかけの件名', 'body' => "■ なぜ（目的・理由）\n・直しかけの本文"]);
        $this->upload($w['applicant'], $request, '直しかけの添付.pdf')->assertOk();

        foreach (['部門長' => $w['head'], '管理者' => $this->approvalAdmin(), '全件閲覧者' => $this->viewAllUser()] as $who => $other) {
            $html = $this->showHtml($other, $request);
            foreach (['直しかけの件名', '直しかけの本文', '直しかけの添付.pdf'] as $secret) {
                $this->assertStringNotContainsString($secret, $html, "{$who}に「{$secret}」が見えた");
            }
            $this->assertStringContainsString('（1 回目 → 2 回目の提出）', $html);
        }
    }

    /** 3 回目の出し直しは直前の 2 回目と比べる（1 回目とではない）。「最後に提出した中身」の印は 3 回目だけ（2b 計画 Task 8 の変異 C06・C07） */
    public function test_the_third_round_is_compared_with_the_second(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w, ['subject' => '一回目の件名']);
        $request = $this->returnAndResubmit($w, $request, '二回目の件名');
        $request = $this->returnAndResubmit($w, $request, '三回目の件名');
        $this->assertSame(3, $request->round, '前提: 3 回目の提出');

        $html = $this->showHtml($w['head'], $request);

        $this->assertStringContainsString('（2 回目 → 3 回目の提出）', $html);
        $changes = $this->section($html, '前回からの変更点');
        $this->assertStringContainsString('<span class="sr-only">前: </span>二回目の件名</span>', $changes);
        $this->assertStringContainsString('<span class="sr-only">後: </span>三回目の件名</span>', $changes);
        $this->assertStringNotContainsString('一回目の件名', $changes);
        // 履歴は新しい回から並び、印は最後に提出した回だけ
        $this->assertSame(1, preg_match('/<summary[^>]*>\s*3 回目の提出.*?<summary[^>]*>\s*2 回目の提出.*?<summary[^>]*>\s*1 回目の提出/su', $html));
        $this->assertSame(1, substr_count($html, '（最後に提出した中身）'), '最後に提出した回でない回にも印が付いた');
    }

    /** 本文の消えた行は、取り消し線と読み上げの「消えた行:」で出す（色だけに頼らない。要件 14.4・2b 計画 Task 8 の変異 C09） */
    public function test_a_removed_body_line_is_struck_through_and_read_out(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->returnByHead($w, $this->submittedFor($w, ['body' => "■ なぜ（目的・理由）\n・老朽化のため"]));
        $request->update(['body' => "■ なぜ（目的・理由）\n・燃費が悪いため"]);
        app(Workflow::class)->submit($request->refresh(), $w['applicant']);

        $changes = $this->section($this->showHtml($w['head'], $request->refresh()), '前回からの変更点');

        $this->assertStringContainsString('<span class="sr-only">消えた行: </span><span class="whitespace-pre-wrap break-words min-w-0 line-through">・老朽化のため</span>', $changes);
        $this->assertStringContainsString('<span class="sr-only">増えた行: </span><span class="whitespace-pre-wrap break-words min-w-0">・燃費が悪いため</span>', $changes);
    }

    /** 変更点と履歴は、控えの値（件名・本文の行）をエスケープして出す（2b 計画 Task 8 の変異 C11〜C14） */
    public function test_the_changes_and_the_history_escape_the_submitted_values(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->returnByHead($w, $this->submittedFor($w, ['subject' => '<b>前の件名</b>', 'body' => "■ なぜ（目的・理由）\n<script>alert(1)</script>"]));
        $request->update(['subject' => '<i>後の件名</i>', 'body' => "■ なぜ（目的・理由）\n<img src=x onerror=alert(2)>"]);
        app(Workflow::class)->submit($request->refresh(), $w['applicant']);

        $html = $this->showHtml($w['head'], $request->refresh());

        foreach (['<b>前の件名</b>', '<i>後の件名</i>', '<script>alert(1)</script>', '<img src=x'] as $raw) {
            $this->assertStringNotContainsString($raw, $html, "エスケープせずに出した: {$raw}");
        }
        foreach (['前回からの変更点', '提出の履歴'] as $heading) {
            $section = $this->section($html, $heading);
            foreach (['&lt;b&gt;前の件名&lt;/b&gt;', '&lt;i&gt;後の件名&lt;/i&gt;', '&lt;script&gt;alert(1)&lt;/script&gt;', '&lt;img src=x onerror=alert(2)&gt;'] as $escaped) {
                $this->assertStringContainsString($escaped, $section, "{$heading}に {$escaped} が無い");
            }
        }
    }
}
