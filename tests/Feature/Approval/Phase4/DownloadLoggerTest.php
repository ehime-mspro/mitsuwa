<?php

namespace Tests\Feature\Approval\Phase4;

use App\Models\ApprovalAttachment;
use App\Models\ApprovalDownloadLog;
use App\Models\User;
use App\Support\Approval\DownloadLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 出力の記録の書き方（1 か所。段階4 設計書 §5.7・D25・4a の BACKLOG の持ち越し）。
 *
 * 添付と PDF の記録は、それぞれの画面のテスト（RequestAttachmentTest・RequestPdfTest）が出力を通して見る。
 * ここは 3 つの種類が同じ書き方（誰が・IP・端末を 255 文字まで）になることと、Excel の行の形を見る。
 */
class DownloadLoggerTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private function httpRequest(User $user): Request
    {
        $request = Request::create('/approvals/ledger', 'GET', server: [
            'REMOTE_ADDR'     => '203.0.113.9',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone) ' . str_repeat('端', 300),
        ]);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_the_kinds_are_the_values_kept_in_the_table(): void
    {
        // 本番の行がこの値で残る（4a までの添付と PDF の記録と同じ値）
        $this->assertSame(
            ['attachment', 'pdf', 'excel'],
            [ApprovalDownloadLog::KIND_ATTACHMENT, ApprovalDownloadLog::KIND_PDF, ApprovalDownloadLog::KIND_EXCEL]
        );
    }

    public function test_an_excel_row_has_no_request_and_keeps_the_filters_and_the_count(): void
    {
        $user    = $this->baseUser();
        $filters = ['status' => 'numbered', 'keyword' => '社用車', 'year' => 2026];

        DownloadLogger::excel($this->httpRequest($user), $filters, 12);

        $log  = ApprovalDownloadLog::sole();
        $kept = $log->fresh()->filters;
        // MySQL の JSON はキーを並べ替えて返す（RequestSnapshot の注意）。並びに頼らずに比べる
        ksort($filters);
        ksort($kept);
        $this->assertSame(
            [null, null, $user->id, 'excel', $filters, 12],
            [$log->request_id, $log->attachment_id, (int) $log->user_id, $log->kind, $kept, $log->fresh()->request_count]
        );
        $this->assertSame('203.0.113.9', $log->ip_address);
        $this->assertSame(255, mb_strlen($log->user_agent));
        $this->assertNotNull($log->created_at);
    }

    public function test_every_kind_is_written_the_same_way(): void
    {
        $world   = $this->approvalWorld();
        $request = $this->submittedFor($world);
        $attachment = ApprovalAttachment::create([
            'request_id' => $request->id, 'original_name' => '見積書.pdf', 'stored_path' => 'approvals/x.pdf',
            'mime' => 'application/pdf', 'size' => 10, 'uploaded_by' => $world['applicant']->id, 'added_round' => 1,
        ]);
        $http = $this->httpRequest($world['head']);

        DownloadLogger::attachment($http, $attachment);
        DownloadLogger::pdf($http, $request);
        DownloadLogger::excel($http, [], 0);

        $this->assertSame(
            [
                [$request->id, $attachment->id, 'attachment'],
                [$request->id, null, 'pdf'],
                [null, null, 'excel'],
            ],
            ApprovalDownloadLog::orderBy('id')->get()->map(fn (ApprovalDownloadLog $l) => [
                $l->request_id === null ? null : (int) $l->request_id,
                $l->attachment_id === null ? null : (int) $l->attachment_id,
                $l->kind,
            ])->all()
        );
        foreach (ApprovalDownloadLog::all() as $log) {
            $this->assertSame([$world['head']->id, '203.0.113.9', 255], [(int) $log->user_id, $log->ip_address, mb_strlen($log->user_agent)], $log->kind);
        }
    }

    /** 記録を書くのはこの部品だけ（出力ごとに写すと、端末を 255 文字で切る所などがまた散らばる） */
    public function test_only_the_logger_writes_the_records(): void
    {
        $writers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && preg_match('/ApprovalDownloadLog::(create|insert|forceCreate|query)\b/', file_get_contents($file->getPathname()))) {
                $writers[] = str_replace(base_path() . '/', '', $file->getPathname());
            }
        }

        $this->assertSame(['app/Support/Approval/DownloadLogger.php'], $writers);
    }
}
