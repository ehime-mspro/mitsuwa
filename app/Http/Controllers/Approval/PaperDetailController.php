<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalPaperRecord;
use App\Support\Approval\RequestVisibility;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 紙の決裁の詳細（段階5 設計書 §5.15・D21）。台帳の行と関連する決裁No のリンクの先。
 *
 * ⚠ 見られる人だけ（RequestVisibility の紙の決裁の規則）。見られない記録は 404（申請の詳細と同じ。在ることも知らせない）
 * ⚠ 見せるときだけ（approval.launched の中＝準備中、または試す期間の試す人でない人には見せない）。決裁の管理者の登録・直し・削除は PaperRecordController（approval.launched の外＝準備中も開ける）
 * ⚠ 回覧の操作は無い。あとでスキャンした PDF を付けるときもこの画面に足す（D8）
 */
class PaperDetailController extends Controller
{
    /** Route: GET /approvals/papers/{approvalPaperRecord} */
    public function show(Request $request, ApprovalPaperRecord $approvalPaperRecord): View
    {
        abort_unless(RequestVisibility::canViewPaper($request->user(), $approvalPaperRecord), 404);

        return view('approvals.papers.show', ['record' => $approvalPaperRecord->load('department.company')]);
    }
}
