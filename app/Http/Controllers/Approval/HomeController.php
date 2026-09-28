<?php

namespace App\Http\Controllers\Approval;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Support\Approval\PendingWork;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 決裁のホーム（画面①・段階2 設計書 §5.12）。
 *
 * ⚠ 使い始める前（approval_settings.launched_at が空）は段階1 の「準備中」の画面のまま（§5.2・D1）。
 *   申請を回す画面の門番 `approval.launched` も、準備中はここへ送る。
 */
class HomeController extends Controller
{
    /** 「最近の完了」に出す数 */
    private const RECENT_FINISHED = 5;

    public function index(Request $request): View
    {
        $user = $request->user();

        if (! ApprovalSetting::current()->isLaunched()) {
            return view('approvals.home', [
                'user'    => $user,
                'loginId' => $user->employee_number ?? $user->email,
            ]);
        }

        // いま誰の番かを出すので、段階の担当（部門長・審査担当者・付け替え）を先に読む（CurrentHandler）
        $withHandler = ['type', 'steps.department.head', 'steps.department.reviewers', 'steps.assignee'];

        return view('approvals.home-launched', [
            'user'             => $user,
            'pending'          => PendingWork::for($user),
            // 進み具合に下書きは出さない（§5.12）。下書きだけの人に「まだ申請はありません」と出さないために数える
            'hasDrafts'        => ApprovalRequest::where('user_id', $user->id)->where('status', ApprovalStatus::Draft->value)->exists(),
            // 自分の申請の進み具合（回覧中・差戻し中・条件確認待ち）と、最近の完了（§5.12）
            'inProgress'       => ApprovalRequest::with($withHandler)
                ->where('user_id', $user->id)
                ->whereIn('status', array_map(fn (ApprovalStatus $s) => $s->value, [
                    ApprovalStatus::HeadReview, ApprovalStatus::Review, ApprovalStatus::President,
                    ApprovalStatus::Returned, ApprovalStatus::Condition,
                ]))
                ->orderByDesc('status_changed_at')
                ->get(),
            'recentlyFinished' => ApprovalRequest::with('type')
                ->where('user_id', $user->id)
                ->whereIn('status', array_map(fn (ApprovalStatus $s) => $s->value, [
                    ApprovalStatus::Approved, ApprovalStatus::Rejected, ApprovalStatus::Withdrawn,
                ]))
                ->orderByDesc('status_changed_at')
                ->limit(self::RECENT_FINISHED)
                ->get(),
        ]);
    }
}
