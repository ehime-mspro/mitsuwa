<?php

namespace Tests\Feature\Approval\Phase2;

use App\Models\ApprovalAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 決裁の URL の JSON の 404 は、無い ID と見られない ID で同じ日本語の文にする（bootstrap/app.php）。
 *
 * Laravel の既定では、無い ID（ルートモデル結合）は「No query results for model [App\Models\…] 12」、見られない ID
 * （abort(404)）は空の文を返すので、JSON で叩くと在るかどうかが分かる。添付の画面（申請書）は、断られた理由として
 * この文をそのまま出す（古いタブで消した下書きの添付を外す・2 回押すと英語の文が出る）。
 */
class ApprovalJsonNotFoundTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const AJAX = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

    private const MESSAGE = '見つかりませんでした。画面を開き直してください。';

    public function test_a_missing_id_and_a_hidden_one_get_the_same_japanese_json(): void
    {
        config(['app.debug' => false]);   // 本番と同じ
        Storage::fake('local');
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->actingAs($w['applicant'])->post(route('approvals.requests.attachments.store', $draft),
            ['file' => UploadedFile::fake()->create('見積書.pdf', 10, 'application/pdf')], self::AJAX)->assertOk();
        $hidden   = ApprovalAttachment::sole();   // 下書きの添付（ほかの人は申請も見られない）
        $stranger = $this->approvalOnlyUser();

        $pairs = [
            'GET 添付'     => fn (int $id) => $this->actingAs($stranger)->get('/approvals/attachments/' . $id, self::AJAX),
            'DELETE 添付'  => fn (int $id) => $this->actingAs($stranger)->delete('/approvals/attachments/' . $id, [], self::AJAX),
            'POST 添付'    => fn (int $id) => $this->actingAs($stranger)->post('/approvals/requests/' . $id . '/attachments',
                ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], self::AJAX),
            'GET 申請'     => fn (int $id) => $this->actingAs($stranger)->get('/approvals/requests/' . $id, self::AJAX),
        ];
        $ids = ['GET 添付' => $hidden->id, 'DELETE 添付' => $hidden->id, 'POST 添付' => $draft->id, 'GET 申請' => $draft->id];

        foreach ($pairs as $label => $call) {
            $seen   = $call($ids[$label]);
            $absent = $call($ids[$label] + 1000);
            $seen->assertNotFound()->assertExactJson(['message' => self::MESSAGE]);
            $absent->assertNotFound()->assertExactJson(['message' => self::MESSAGE]);
        }
        $this->assertNull($hidden->fresh()->removed_at);
    }

    /** 申請者が古いタブから、もう消した下書きの添付を外しても、英語の文ではなく日本語で知らせる */
    public function test_removing_an_already_deleted_draft_file_is_explained_in_japanese(): void
    {
        Storage::fake('local');
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);
        $this->actingAs($w['applicant'])->post(route('approvals.requests.attachments.store', $draft),
            ['file' => UploadedFile::fake()->create('見積書.pdf', 10, 'application/pdf')], self::AJAX)->assertOk();
        $attachment = ApprovalAttachment::sole();

        $this->actingAs($w['applicant'])->delete(route('approvals.attachments.destroy', $attachment), [], self::AJAX)->assertOk();
        $this->actingAs($w['applicant'])->delete(route('approvals.attachments.destroy', $attachment), [], self::AJAX)
            ->assertNotFound()
            ->assertExactJson(['message' => self::MESSAGE]);
    }

    /** 基幹の URL の 404 は変えない */
    public function test_other_urls_keep_the_default(): void
    {
        $this->actingAs($this->baseUser())->getJson('/_no_such_page_for_t14')
            ->assertNotFound()
            ->assertJsonMissing(['message' => self::MESSAGE]);
    }
}
