<?php

namespace App\Http\Controllers\Approval;

use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Support\Approval\RequestVisibility;
use App\Support\Approval\Workflow;
use App\Support\Approval\WorkflowConflict;
use App\Support\Approval\WorkflowRefused;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 判断・条件確認・取り下げ（要件 4.2・4.5・4.6・設計書 §5.8）。
 *
 * ⚠ 権限と状態は Workflow（RequestPermissions）が確かめる。ここは入力の形を見て渡すだけ。
 * ⚠ 画面が描いたときの lock_version を渡す（古い画面から押した操作を「すでに処理されています」で断る。計画 §0.3）。
 *   送られてこない・0 以上の整数の形でないときは -1 にする（必ず断られる。lockVersion()）。
 * ⚠ 戻り先はいつも詳細の画面（Bug #64）。
 */
class RequestActionController extends Controller
{
    public function __construct(private readonly Workflow $workflow)
    {
    }

    /** 部門長の承認・差戻し */
    public function headReview(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        return $this->judge($request, $approvalRequest, ApprovalStepKind::Head);
    }

    /** 審査の意見（可・保留・否） */
    public function review(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        return $this->judge($request, $approvalRequest, ApprovalStepKind::Review);
    }

    /** 社長の決裁（可・条可・差戻し・否） */
    public function decide(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        return $this->judge($request, $approvalRequest, ApprovalStepKind::President);
    }

    /** 条件の確認（申請者。要件 4.6） */
    public function confirmCondition(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $comment = $this->optionalComment($request, $approvalRequest);

        return $this->run(
            $approvalRequest,
            fn () => $this->workflow->confirmCondition($approvalRequest, $request->user(), self::lockVersion($request), $comment),
            '条件を確認しました。決裁が完了しました。',
        );
    }

    /** 取り下げ（申請者。コメントは任意。D17） */
    public function withdraw(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $comment = $this->optionalComment($request, $approvalRequest);

        return $this->run(
            $approvalRequest,
            fn () => $this->workflow->withdraw($approvalRequest, $request->user(), self::lockVersion($request), $comment),
            '取り下げました。',
        );
    }

    private function judge(Request $request, ApprovalRequest $approvalRequest, ApprovalStepKind $kind): RedirectResponse
    {
        $this->assertVisible($request, $approvalRequest);

        $allowed = array_map(fn (ApprovalStepResult $result) => $result->value, ApprovalStepResult::allowedFor($kind));

        try {
            $validated = $request->validate([
                'result'  => ['required', Rule::in($allowed)],
                'comment' => ['nullable', 'string', 'max:2000'],
            ], [
                'result.required' => '判断を選んでください。',
                'result.in'       => '選べない判断です。',
            ]);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('approvals.requests.show', $approvalRequest));
        }

        $result  = ApprovalStepResult::from($validated['result']);
        $comment = $validated['comment'] ?? null;
        $version = self::lockVersion($request);
        $actor   = $request->user();

        return $this->run(
            $approvalRequest,
            fn () => match ($kind) {
                ApprovalStepKind::Head      => $this->workflow->judgeHead($approvalRequest, $actor, $version, $result, $comment),
                ApprovalStepKind::Review    => $this->workflow->judgeReview($approvalRequest, $actor, $version, $result, $comment),
                ApprovalStepKind::President => $this->workflow->judgePresident($approvalRequest, $actor, $version, $result, $comment),
            },
            fn () => self::judgedMessage($kind, $result, $approvalRequest),
        );
    }

    /**
     * Workflow を呼び、詳細の画面へ戻す（断られたら理由、先を越されたら「すでに処理されています」）。
     *
     * @param Closure(): void $action
     * @param string|Closure(): string $success
     */
    private function run(ApprovalRequest $approvalRequest, Closure $action, string|Closure $success): RedirectResponse
    {
        $show = redirect()->route('approvals.requests.show', $approvalRequest);

        try {
            $action();
        } catch (WorkflowConflict) {
            return $show->with('error', WorkflowConflict::MESSAGE);
        } catch (WorkflowRefused $e) {
            return $show->with('error', implode(' ', $e->reasons));
        }

        return $show->with('success', $success instanceof Closure ? $success() : $success);
    }

    /** 判断のあとの帯の言葉（社長の判断は付いた決裁No を添える。Workflow が同じインスタンスを読み直している） */
    private static function judgedMessage(ApprovalStepKind $kind, ApprovalStepResult $result, ApprovalRequest $approvalRequest): string
    {
        if ($result === ApprovalStepResult::Return) {
            return '差し戻しました。';
        }

        return match ($kind) {
            ApprovalStepKind::Head      => '承認しました。審査へ回りました。',
            ApprovalStepKind::Review    => '意見（' . $result->labelFor($kind) . '）を送りました。社長へ回りました。',
            ApprovalStepKind::President => '「' . $result->labelFor($kind) . '」で決裁しました（決裁No ' . $approvalRequest->number . '）。',
        };
    }

    /** 条件確認・取り下げのコメント（任意・2,000 文字まで） */
    private function optionalComment(Request $request, ApprovalRequest $approvalRequest): ?string
    {
        $this->assertVisible($request, $approvalRequest);

        try {
            $validated = $request->validate([
                'comment' => ['nullable', 'string', 'max:2000'],
            ]);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('approvals.requests.show', $approvalRequest));
        }

        return $validated['comment'] ?? null;
    }

    /**
     * 画面が描いたときの lock_version。送られてこない・0 以上の整数の形でないときは -1（必ず「すでに処理されています」）。
     * ⚠ `$request->integer()` は intval なので、配列を 1、'1abc' を 1 と読んでしまう（Task 15 の点検）
     */
    private static function lockVersion(Request $request): int
    {
        $value = $request->input('lock_version');

        return is_string($value) && preg_match('/\A\d{1,9}\z/', $value) === 1 ? (int) $value : -1;
    }

    /** 見られない申請は 404（在るかどうかを漏らさない。設計書 §5.10） */
    private function assertVisible(Request $request, ApprovalRequest $approvalRequest): void
    {
        abort_unless(RequestVisibility::canView($request->user(), $approvalRequest), 404);
    }
}
