<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalAttachment;
use App\Models\ApprovalDownloadLog;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 添付（要件 5.3・14.3・設計書 §5.7・計画 §0.5） */
class RequestAttachmentTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const AJAX = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function upload(User $user, ApprovalRequest $request, UploadedFile $file): TestResponse
    {
        return $this->actingAs($user)->post(route('approvals.requests.attachments.store', $request), ['file' => $file], self::AJAX);
    }

    private function remove(User $user, ApprovalAttachment $attachment): TestResponse
    {
        return $this->actingAs($user)->delete(route('approvals.attachments.destroy', $attachment), [], self::AJAX);
    }

    private function pdf(string $name = '見積書.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    /** 下書きを提出して、部門長が差し戻す（一度提出した申請にする） */
    private function submitAndReturn(ApprovalRequest $draft, array $w): ApprovalRequest
    {
        $workflow = app(Workflow::class);
        $workflow->submit($draft->refresh(), $w['applicant']);
        $draft->refresh();
        $workflow->judgeHead($draft, $w['head'], $draft->lock_version, ApprovalStepResult::Return, '見積書を差し替えてください');

        return $draft->refresh();
    }

    public function test_the_applicant_can_attach_a_file_to_a_draft(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);

        $response = $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();

        $attachment = ApprovalAttachment::sole();
        $response->assertJson([
            'success'    => true,
            'message'    => '見積書.pdf を添付しました。',
            'attachment' => ['id' => $attachment->id, 'name' => '見積書.pdf', 'url' => route('approvals.attachments.show', $attachment)],
        ]);
        $this->assertSame(1, $attachment->added_round, 'この添付が初めて入るのは 1 回目の提出');
        $this->assertSame('application/pdf', $attachment->mime);
        $this->assertMatchesRegularExpression('#^approvals/' . $draft->id . '/[A-Za-z0-9]{40}\.pdf$#', $attachment->stored_path);
        Storage::disk('local')->assertExists($attachment->stored_path);
    }

    /** 同じ名前でも上書きしない（新しい内容は新しい名前） */
    public function test_the_same_name_never_overwrites(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);

        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();

        $paths = ApprovalAttachment::pluck('stored_path');
        $this->assertCount(2, $paths->unique());
        foreach ($paths as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_the_kind_and_the_size_are_checked(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);

        $this->upload($w['applicant'], $draft, UploadedFile::fake()->create('tool.exe', 10, 'application/x-msdownload'))
            ->assertStatus(422)
            ->assertJsonPath('message', '添付できるのは、画像（jpg・png・gif・webp・heic）・PDF・Word・Excel・CSV・テキストです。');

        $this->upload($w['applicant'], $draft, UploadedFile::fake()->create('big.pdf', ApprovalAttachment::MAX_KB + 1, 'application/pdf'))
            ->assertStatus(422)
            ->assertJsonPath('message', '1 ファイル 10MB までです。');

        $this->assertSame(0, ApprovalAttachment::count());
    }

    /** 1 件の申請に 20 ファイルまで（D14） */
    public function test_twenty_files_is_the_limit(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        for ($i = 1; $i <= ApprovalAttachment::MAX_COUNT; $i++) {
            $this->upload($w['applicant'], $draft, $this->pdf("見積書{$i}.pdf"))->assertOk();
        }

        $this->upload($w['applicant'], $draft, $this->pdf())
            ->assertStatus(422)
            ->assertJsonPath('message', '添付は 1 件の申請に 20 ファイルまでです。');
        $this->assertSame(ApprovalAttachment::MAX_COUNT, ApprovalAttachment::count());
    }

    /** 足せるのは申請者だけ・下書きと差戻し中だけ（要件 5.3） */
    public function test_only_the_applicant_can_attach_and_only_while_editable(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $submitted = $this->submittedFor($w);

        $this->upload($w['applicant'], $submitted, $this->pdf())
            ->assertForbidden()
            ->assertJsonPath('message', '添付を足せるのは、下書きと差戻し中の申請者だけです。');
        // 部門長は見られるが足せない。見られない人は 404
        $this->upload($w['head'], $submitted, $this->pdf())->assertForbidden();
        $this->upload($this->approvalOnlyUser(), $submitted, $this->pdf())->assertNotFound();

        $this->assertSame(0, ApprovalAttachment::count());
    }

    /** PDF・画像はブラウザで開き、それ以外（HEIC を含む）はダウンロード。種類はこちらの表から出す（§5.7・D14） */
    public function test_files_open_inline_or_download_with_safe_headers(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $this->upload($w['applicant'], $draft, UploadedFile::fake()->create('現場.heic', 50, 'image/heic'))->assertOk();
        [$pdf, $heic] = ApprovalAttachment::orderBy('id')->get()->all();

        $response = $this->actingAs($w['applicant'])->get(route('approvals.attachments.show', $pdf))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("filename*=utf-8''" . rawurlencode('見積書.pdf'), $response->headers->get('Content-Disposition'));

        $response = $this->actingAs($w['applicant'])->get(route('approvals.attachments.show', $heic))->assertOk();
        $this->assertSame('image/heic', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
    }

    /** 一度でも提出した申請の添付は、開くたびに記録する。一度も提出していない下書きは記録しない（計画 §0.5） */
    public function test_opening_is_logged_once_the_request_was_submitted(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $attachment = ApprovalAttachment::sole();

        $this->actingAs($w['applicant'])->get(route('approvals.attachments.show', $attachment))->assertOk();
        $this->assertSame(0, ApprovalDownloadLog::count());

        app(Workflow::class)->submit($draft->refresh(), $w['applicant']);
        $this->actingAs($w['head'])->get(route('approvals.attachments.show', $attachment))->assertOk();

        $log = ApprovalDownloadLog::sole();
        $this->assertSame(
            [$draft->id, $attachment->id, $w['head']->id, 'attachment'],
            [(int) $log->request_id, (int) $log->attachment_id, (int) $log->user_id, $log->kind]
        );
    }

    /** 見られない人は、URL を知っていても開けない（毎回確かめる。下書きの添付は申請者だけ） */
    public function test_someone_who_cannot_see_the_request_cannot_open_its_files(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $attachment = ApprovalAttachment::sole();

        $this->actingAs($this->approvalAdmin())->get(route('approvals.attachments.show', $attachment))->assertNotFound();

        app(Workflow::class)->submit($draft->refresh(), $w['applicant']);
        $this->actingAs($this->approvalOnlyUser())->get(route('approvals.attachments.show', $attachment))->assertNotFound();

        $this->assertSame(0, ApprovalDownloadLog::count());
    }

    /** 一度も提出していない下書きの添付は、外すと行もファイルも消える */
    public function test_removing_from_a_draft_deletes_the_file(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $attachment = ApprovalAttachment::sole();

        $this->remove($w['applicant'], $attachment)->assertOk()
            ->assertJson(['success' => true, 'message' => '見積書.pdf を外しました。']);

        $this->assertNull($attachment->fresh());
        Storage::disk('local')->assertMissing($attachment->stored_path);
    }

    /** 一度でも提出した申請の添付は、外しても行とファイルを残す（控えと記録から開ける。計画 §0.5） */
    public function test_removing_from_a_returned_request_keeps_the_record(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $attachment = ApprovalAttachment::sole();
        $request = $this->submitAndReturn($draft, $w);

        $this->remove($w['applicant'], $attachment)->assertOk();

        $fresh = $attachment->fresh();
        $this->assertSame(2, $fresh->removed_round);
        $this->assertNotNull($fresh->removed_at);
        Storage::disk('local')->assertExists($fresh->stored_path);
        $this->assertSame(0, $request->attachments()->count());
        // 1 回目の提出の控えには残っていて、部門長は開ける（範囲の確認と記録は同じ）
        $this->assertSame([$attachment->id], array_column($request->revisions()->first()->snapshot['attachments'], 'id'));
        $this->actingAs($w['head'])->get(route('approvals.attachments.show', $attachment))->assertOk();
        $this->assertSame(1, ApprovalDownloadLog::count());
    }

    public function test_files_cannot_be_removed_while_the_request_is_in_circulation(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $attachment = ApprovalAttachment::sole();
        app(Workflow::class)->submit($draft->refresh(), $w['applicant']);

        $this->remove($w['applicant'], $attachment)
            ->assertForbidden()
            ->assertJsonPath('message', '添付を外せるのは、下書きと差戻し中の申請者だけです。');

        $this->assertNull($attachment->fresh()->removed_at);
    }

    /** 下書きを削除すると、添付のファイルも消える（計画 §0.5） */
    public function test_deleting_a_draft_deletes_its_files(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $attachment = ApprovalAttachment::sole();

        $this->actingAs($w['applicant'])->delete(route('approvals.requests.destroy', $draft))->assertRedirect(route('approvals.home'));

        $this->assertSame(0, ApprovalAttachment::count());
        Storage::disk('local')->assertMissing($attachment->stored_path);
    }

    /** PHP の送信の上限を超えたときも、日本語の理由を JSON で返す（§5.7） */
    public function test_a_post_that_is_too_large_gets_a_japanese_reason(): void
    {
        // ⚠ 本物の上限（php.ini の post_max_size）は環境で変わるので、例外そのものを投げる見本のルートで測る
        Route::post('/approvals/_probe_too_large', fn () => throw new PostTooLargeException());

        $this->postJson('/approvals/_probe_too_large')
            ->assertStatus(413)
            ->assertExactJson(['message' => 'ファイルが大きすぎて受け取れませんでした。1 ファイル 10MB までです。']);
    }

    /** 添付の欄は下書きを 1 回保存したあとに出る（D14） */
    public function test_the_attachment_area_appears_after_the_first_save(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $this->actingAs($w['applicant'])->get(route('approvals.requests.create'))->assertOk()
            ->assertSee('下書きを 1 回保存すると、ここで添付を追加できます。')
            ->assertDontSee('function approvalAttachments()', false);

        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $attachment = ApprovalAttachment::sole();

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.edit', $draft))->assertOk()->getContent();
        $this->assertStringContainsString('function approvalAttachments()', $html);
        $this->assertStringContainsString(Js::from([$attachment->listItem()])->toHtml(), $html);
    }

    /**
     * 詳細の添付は中身と同じ版（RequestContent）。申請者には今の添付、ほかの人には最後に提出した控えの添付が並ぶ。
     * 差戻し中に外した添付は、出し直すまでほかの人の一覧に残り、出し直したあとも控えから開ける（利用者の決定 2026-09-27）
     */
    public function test_the_detail_lists_the_attachments_of_the_shown_content(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $this->upload($w['applicant'], $draft, $this->pdf('古い図面.pdf'))->assertOk();
        $request = $this->submitAndReturn($draft, $w);
        $removed = ApprovalAttachment::where('original_name', '古い図面.pdf')->sole();
        $this->remove($w['applicant'], $removed)->assertOk();

        $html = $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->assertOk()->getContent();
        $this->assertStringContainsString('見積書.pdf', $html);
        $this->assertStringNotContainsString('古い図面.pdf', $html);

        // 部門長には、まだ最後に提出した中身（外したことは出し直すまで申請者だけ）
        $html = $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()->getContent();
        $this->assertStringContainsString('見積書.pdf', $html);
        $this->assertStringContainsString('古い図面.pdf', $html);

        // 出し直すと一覧から消えるが、1 回目の控えに入っているので開ける（2b の履歴から開く）
        app(Workflow::class)->submit($request->refresh(), $w['applicant']);
        $html = $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()->getContent();
        $this->assertStringContainsString('見積書.pdf', $html);
        $this->assertStringNotContainsString('古い図面.pdf', $html);
        $this->actingAs($w['head'])->get(route('approvals.attachments.show', $removed))->assertOk();
    }

    /** 差戻し中に足した添付は、出し直すまで申請者だけ（ID を知っていても 404。利用者の決定 2026-09-27） */
    public function test_a_file_added_while_returned_stays_with_the_applicant_until_resubmitted(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->upload($w['applicant'], $draft, $this->pdf())->assertOk();
        $request = $this->submitAndReturn($draft, $w);
        $this->upload($w['applicant'], $request, $this->pdf('新しい見積書.pdf'))->assertOk();
        $added = ApprovalAttachment::where('original_name', '新しい見積書.pdf')->sole();

        $this->actingAs($w['applicant'])->get(route('approvals.attachments.show', $added))->assertOk();
        $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->assertOk()->assertSee('新しい見積書.pdf');
        foreach ([$w['head'], $this->approvalAdmin()] as $other) {
            $this->actingAs($other)->get(route('approvals.attachments.show', $added))->assertNotFound();
            $this->actingAs($other)->get(route('approvals.requests.show', $request))->assertOk()->assertDontSee('新しい見積書.pdf');
        }
        $this->assertSame([$w['applicant']->id], ApprovalDownloadLog::pluck('user_id')->map(fn ($id) => (int) $id)->all(), '断った人の記録を残した');

        // 出し直すと、部門長も開ける
        app(Workflow::class)->submit($request->refresh(), $w['applicant']);
        $this->actingAs($w['head'])->get(route('approvals.attachments.show', $added))->assertOk();
        $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()->assertSee('新しい見積書.pdf');
    }

    /** 差戻し中に足して、出し直す前に外した添付は、どの回にも出していないので申請者しか開けない */
    public function test_a_file_added_and_removed_before_resubmitting_is_never_shown_to_others(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submitAndReturn($this->draftFor($w), $w);
        $this->upload($w['applicant'], $request, $this->pdf('間違えた添付.pdf'))->assertOk();
        $mistake = ApprovalAttachment::sole();
        $this->remove($w['applicant'], $mistake)->assertOk();
        app(Workflow::class)->submit($request->refresh(), $w['applicant']);

        $this->actingAs($w['head'])->get(route('approvals.attachments.show', $mistake))->assertNotFound();
        $this->actingAs($w['applicant'])->get(route('approvals.attachments.show', $mistake))->assertOk();
    }
}
