<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalNotice;
use App\Support\Approval\PageNumbers;
use App\Support\Approval\RequestVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * お知らせ一覧（画面⑥・段階3 設計書 §5.6）。決裁のみ利用者も使う。
 *
 * ⚠ 引くのは必ず自分のお知らせ（ApprovalNotice::ownedBy）。ほかの人のお知らせの ID を与えても 404（在るかどうかを漏らさない）
 * ⚠ 既読になるのは 3 つ（D13）: お知らせを押したとき（open）・その申請の詳細を開いたとき（RequestController::show）・
 *   「すべて既読にする」（readAll）。「未読だけ」の絞り込みは作らない（D15）
 * ⚠ 戻り先は固定のルート（Bug #64）
 */
class NoticeController extends Controller
{
    /** 1 ページの件数 */
    private const PER_PAGE = 20;

    /** 一覧（新しい順・20 件ずつ） */
    public function index(Request $request): View
    {
        $user    = $request->user();
        $query   = ApprovalNotice::ownedBy($user)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
        // ページ番号は最後のページまで（Bug #100。手で打った大きな番号で PageNumbers::around() が TypeError の 500・行の無いページは件数と食い違う）
        $page    = min(LengthAwarePaginator::resolveCurrentPage(), max(1, (int) ceil($query->count() / self::PER_PAGE)));
        $notices = $query->paginate(self::PER_PAGE, ['*'], 'page', $page);

        return view('approvals.notices.index', [
            'notices'   => $notices,
            'pages'     => PageNumbers::around($notices->currentPage(), $notices->lastPage()),
            'hasUnread' => ApprovalNotice::ownedBy($user)->unread()->exists(),
        ]);
    }

    /**
     * 1 件を開く: 既読にして申請の詳細へ。付け替えなどで見られなくなった申請は、詳細の 404 にせず一覧に戻して知らせる
     * （お知らせは既読にする。§5.6）
     */
    public function open(Request $request, string $notice): RedirectResponse
    {
        $row = ApprovalNotice::ownedBy($request->user())->findOrFail($notice);
        $row->markAsRead();

        $approvalRequest = $row->approvalRequest;

        if ($approvalRequest === null || ! RequestVisibility::canView($request->user(), $approvalRequest)) {
            return redirect()->route('approvals.notices.index')->with('error', 'この申請は、今は見られません。');
        }

        return redirect()->route('approvals.requests.show', $approvalRequest);
    }

    /** すべて既読にする */
    public function readAll(Request $request): RedirectResponse
    {
        ApprovalNotice::markReadFor($request->user());

        return redirect()->route('approvals.notices.index')->with('success', 'お知らせをすべて既読にしました。');
    }
}
