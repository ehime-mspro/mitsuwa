<?php

namespace App\Http\Controllers\Approval;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalDownloadLog;
use App\Models\ApprovalRequest;
use App\Support\Approval\ApprovalPdf;
use App\Support\Approval\PdfSheet;
use App\Support\Approval\RequestVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Throwable;

/**
 * 決裁申請書の PDF（画面③ の「PDF を出力」。要件 9.2・14.2・段階4 設計書 §5.6・§5.7・D17・D18）。
 */
class RequestPdfController extends Controller
{
    /** Route: GET /approvals/requests/{approvalRequest}/pdf */
    public function show(Request $request, ApprovalRequest $approvalRequest): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless(RequestVisibility::canView($user, $approvalRequest), 404);
        // 下書きは出さない（まだ誰にも回っていない。D17）。申請者本人にも
        abort_if($approvalRequest->status === ApprovalStatus::Draft, 404);

        $approvalRequest->load(['applicant', 'department', 'type', 'attachments', 'steps.department', 'steps.actor']);
        $sheet = PdfSheet::for($user, $approvalRequest);

        try {
            $pdf = ApprovalPdf::sheet($sheet);
        } catch (Throwable $e) {
            // 作れなかったときは記録しない（D18・§5.7）。本番の laravel.log は error だけ残すので error で書く
            Log::error('決裁申請書の PDF を作れませんでした', ['request_id' => $approvalRequest->id, 'exception' => $e]);

            return redirect()->route('approvals.requests.show', $approvalRequest)
                ->with('error', 'PDF を作れませんでした。時間をおいてもう一度お試しください。');
        }

        // 出力のたびに記録する（14.2・§5.7）
        ApprovalDownloadLog::create([
            'request_id'    => $approvalRequest->id,
            'attachment_id' => null,
            'user_id'       => $user->id,
            'kind'          => 'pdf',
            'ip_address'    => $request->ip(),
            'user_agent'    => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        // ブラウザでそのまま開く（D18）。日本語の名前は filename*（UTF-8）、古いブラウザ用の代わりは ASCII だけの名前
        $fallback = 'approval-' . ($approvalRequest->number ?? $approvalRequest->id) . '.pdf';

        return response($pdf, 200, [
            'Content-Type'           => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $sheet->fileName, $fallback),
        ]);
    }
}
