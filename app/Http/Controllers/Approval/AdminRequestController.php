<?php

namespace App\Http\Controllers\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepStatus;
use App\Http\Controllers\Approval\Concerns\ReturnsToRequestDetail;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\Approval\Assignees;
use App\Support\Approval\CurrentHandler;
use App\Support\Approval\FormInput;
use App\Support\Approval\PageNumbers;
use App\Support\Approval\PendingWork;
use App\Support\Approval\RequestPermissions;
use App\Support\Approval\Workflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * 進行中の申請の管理（画面⑩・決裁の管理者。段階2 設計書 §5.14）と、詳細の画面（③）の「決裁の管理者の操作」の送り先
 * （部門長の確認の付け替え・押し間違いの取り消し・代理の取り下げ）。
 *
 * ⚠ 門番は approval.admin（403）→ approval.launched（使い始める前は転送・404）の順（bootstrap/app.php の優先順）。
 * ⚠ 権限と状態は Workflow（RequestPermissions）が確かめる。ここは入力の形を見て渡すだけ（判断の操作と同じ）。
 * ⚠ 一覧の件名・申請部門は、最後に提出した控えのもの（差戻し中の直しかけを出さない。D26）。
 * ⚠ 操作の戻り先はいつも詳細の画面（Bug #64）。断られたら、送った小窓を打った中身で開き直す（どの小窓かは送り先で決める。
 *   2b 計画 §0.9。判断の操作と共通の ReturnsToRequestDetail。段階3 設計書 §5.7）。
 */
class AdminRequestController extends Controller
{
    use ReturnsToRequestDetail;

    /** 「決裁済み・否決」の 1 ページの件数 */
    private const DECIDED_PER_PAGE = 20;

    /** 「進行中」に出す状態（§5.14） */
    private const IN_PROGRESS = [
        ApprovalStatus::HeadReview, ApprovalStatus::Review, ApprovalStatus::President,
        ApprovalStatus::Returned, ApprovalStatus::Condition,
    ];

    public function __construct(private readonly Workflow $workflow)
    {
    }

    /** 一覧（タブ: 進行中＝待ち日数の長い順・全件／決裁済み・否決＝決裁日の新しい順。取り消しの入口） */
    public function index(Request $request): View
    {
        $tab = $request->query('tab') === 'decided' ? 'decided' : 'progress';

        // いま誰の番かと印を出すので、段階の担当（部門長・審査担当者・付け替え）と控えを先に読む（N+1。CurrentHandler）
        $with = ['applicant', 'revisions', 'steps.department.head', 'steps.department.reviewers', 'steps.assignee'];

        if ($tab === 'decided') {
            $requests = ApprovalRequest::with($with)
                ->whereIn('status', [ApprovalStatus::Approved->value, ApprovalStatus::Rejected->value])
                ->orderByDesc('decided_at')
                ->orderByDesc('id')
                ->paginate(self::DECIDED_PER_PAGE)
                ->withQueryString();

            return view('approvals.admin.requests', ['tab' => $tab, 'requests' => $requests, 'rows' => null, 'pages' => PageNumbers::around($requests->currentPage(), $requests->lastPage())]);
        }

        $presidentId = ApprovalSetting::current()->president_user_id;
        // 印の「審査担当者が申請者本人しかいない」は有効な人で数える（activeReviewers()）
        $rows = ApprovalRequest::with([...$with, 'steps.department.activeReviewers'])
            ->whereIn('status', array_map(fn (ApprovalStatus $status) => $status->value, self::IN_PROGRESS))
            ->get()
            ->map(fn (ApprovalRequest $r) => $this->row($r, $presidentId))
            // 待ち日数の長い順（同じ日数なら先に届いた順）
            ->sort(fn (array $a, array $b) => [$b['days'], $a['since']?->getTimestamp() ?? PHP_INT_MAX, $a['request']->id]
                <=> [$a['days'], $b['since']?->getTimestamp() ?? PHP_INT_MAX, $b['request']->id])
            ->values();

        return view('approvals.admin.requests', ['tab' => $tab, 'requests' => null, 'rows' => $rows, 'pages' => null]);
    }

    /** 部門長の確認の付け替え（D2・D22・D25） */
    public function reassign(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $this->prepare($request, $approvalRequest, 'reassign');
        $validated = $this->validated($request, $approvalRequest, [
            'assignee_user_id' => ['required', 'integer', Assignees::rule()],
        ], [
            'assignee_user_id.required' => '付け替え先を選んでください。',
            'assignee_user_id.exists'   => '付け替え先は、有効でメールアドレスのある人から選んでください。',
        ]);
        $to = User::findOrFail((int) $validated['assignee_user_id']);

        return $this->runOnDetail(
            $approvalRequest,
            fn () => $this->workflow->reassignHead($approvalRequest, $request->user(), FormInput::lockVersion($request), $to, $validated['admin_reason']),
            "部門長の確認を{$to->name}さんに付け替えました。",
        );
    }

    /** 押し間違いの取り消し（D3・D21・D24・D25） */
    public function undo(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $this->prepare($request, $approvalRequest, 'undo');
        $validated = $this->validated($request, $approvalRequest);
        // 取り消す前に名前を読んでおく（取り消したあとは次の操作が「最後」になる）
        $target = RequestPermissions::for($request->user(), $approvalRequest)->undoTarget();
        $label  = $target?->label() ?? '直前の操作';

        return $this->runOnDetail(
            $approvalRequest,
            fn () => $this->workflow->undo($approvalRequest, $request->user(), FormInput::lockVersion($request), $validated['admin_reason']),
            "「{$label}」を取り消しました。",
        );
    }

    /** 代理の取り下げ（要件 4.3 のケース 8・D25） */
    public function withdraw(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $this->prepare($request, $approvalRequest, 'admin_withdraw');
        $validated = $this->validated($request, $approvalRequest);

        return $this->runOnDetail(
            $approvalRequest,
            fn () => $this->workflow->withdrawByAdmin($approvalRequest, $request->user(), FormInput::lockVersion($request), $validated['admin_reason']),
            '申請者に代わって取り下げました。',
        );
    }

    /**
     * 一覧の 1 行（件名と申請部門は最後に提出した控えのもの。D26）
     *
     * @return array{request: ApprovalRequest, subject: ?string, department: ?string, handler: string, since: ?\DateTimeInterface, days: int, flags: list<string>}
     */
    private function row(ApprovalRequest $r, ?int $presidentId): array
    {
        $snapshot = $r->revisions->firstWhere('round', $r->round)?->snapshot ?? [];
        $waiting  = $r->steps->where('round', $r->round)->firstWhere('status', ApprovalStepStatus::Waiting);
        // 待ち日数の起点は、ホームの対応待ちと同じ（段階が届いた日時・申請者の番は状態が変わった日時。PendingWork・D20）
        $since    = $waiting?->arrived_at ?? $r->status_changed_at;

        return [
            'request'    => $r,
            'subject'    => $snapshot['subject'] ?? null,
            'department' => $snapshot['department']['name'] ?? null,
            'handler'    => CurrentHandler::label($r),
            'since'      => $since,
            'days'       => PendingWork::waitingDays($since),
            'flags'      => $waiting === null ? [] : self::flags($r, $waiting, $presidentId),
        ];
    }

    /**
     * 止まっている理由の印（§5.14）: 担当が申請者本人（自分の申請には判断できない＝D16）・審査担当者が申請者本人しかいない
     *
     * @return list<string>
     */
    private static function flags(ApprovalRequest $r, ApprovalStep $step, ?int $presidentId): array
    {
        return match ($step->kind) {
            ApprovalStepKind::Head => ($step->assignee_user_id ?? $step->department?->head_user_id) === $r->user_id
                ? ['担当が申請者本人'] : [],
            ApprovalStepKind::President => $presidentId === $r->user_id ? ['担当が申請者本人'] : [],
            ApprovalStepKind::Review => self::onlyApplicantReviews($r, $step) ? ['審査担当者が申請者本人しかいない'] : [],
        };
    }

    /** 審査担当者（有効な人）が申請者本人しかいない */
    private static function onlyApplicantReviews(ApprovalRequest $r, ApprovalStep $step): bool
    {
        /** @var Collection<int, User> $active */
        $active = $step->department?->activeReviewers ?? collect();

        return $active->contains('id', $r->user_id) && $active->where('id', '!=', $r->user_id)->isEmpty();
    }

    /** 見られるかの確かめと開き直す小窓（ReturnsToRequestDetail）に、理由の改行をそろえるのを足す */
    private function prepare(Request $request, ApprovalRequest $approvalRequest, string $modal): void
    {
        $this->prepareOnDetail($request, $approvalRequest, $modal);
        FormInput::unifyNewlines($request, 'admin_reason');
    }

    /**
     * 理由（必須・2,000 文字まで。改行はそろえてから数える）と、操作ごとの項目の検査
     *
     * @param array<string, mixed> $rules
     * @param array<string, string> $messages
     * @return array<string, mixed>
     */
    private function validated(Request $request, ApprovalRequest $approvalRequest, array $rules = [], array $messages = []): array
    {
        return $this->validateForDetail($request, $approvalRequest, array_merge([
            'admin_reason' => ['required', 'string', 'max:2000'],
        ], $rules), array_merge([
            'admin_reason.required' => '理由を入力してください。',
        ], $messages));
    }
}
