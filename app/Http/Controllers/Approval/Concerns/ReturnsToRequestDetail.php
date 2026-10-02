<?php

namespace App\Http\Controllers\Approval\Concerns;

use App\Models\ApprovalRequest;
use App\Support\Approval\RequestVisibility;
use App\Support\Approval\WorkflowConflict;
use App\Support\Approval\WorkflowRefused;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 詳細の画面（③）から送る操作の共通の形（判断・条件確認・取り下げと、決裁の管理者の 3 つの操作。段階3 設計書 §5.7）。
 * 2a の RequestActionController と 2b の AdminRequestController に同じ中身があったものを寄せた（2b の最後の点検 I-1）。
 *
 * ⚠ 戻り先はいつも詳細の画面（Bug #64）。
 * ⚠ 断られたとき詳細の画面が開き直す小窓は、送り先で決めてセッションに残す（approval_reopen。フォームの値で受け取らないので、
 *   状態が進んだあとに古い画面の小窓が断られても、別の小窓は開かない。2b 計画 §0.9）。
 */
trait ReturnsToRequestDetail
{
    /**
     * 見られない申請は 404（在るかどうかを漏らさない。段階2 設計書 §5.10）。見られるなら、断られたときに開き直す小窓を残す。
     * ⚠ フラッシュは 404 の確かめのあと（2b の最後の点検 M-4。前は送り先によって前後が揺れていた）
     */
    private function prepareOnDetail(Request $request, ApprovalRequest $approvalRequest, string $modal): void
    {
        abort_unless(RequestVisibility::canView($request->user(), $approvalRequest), 404);
        $request->session()->flash('approval_reopen', $modal);
    }

    /**
     * 入力の検査。断られたら詳細の画面へ戻す（打った中身が戻り、小窓が開き直す）
     *
     * @param array<string, mixed> $rules
     * @param array<string, string> $messages
     * @return array<string, mixed>
     */
    private function validateForDetail(Request $request, ApprovalRequest $approvalRequest, array $rules, array $messages = []): array
    {
        try {
            return $request->validate($rules, $messages);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('approvals.requests.show', $approvalRequest));
        }
    }

    /**
     * Workflow を呼び、詳細の画面へ戻す。先を越されたら「すでに処理されています」（入力は戻さない＝今の状態を見てもらう）、
     * 断られたら理由と打った中身を戻す（画面が小窓を開き直す。入力の検査で断られたときと同じ。2a の Task 19 の C8）
     *
     * @param Closure(): void $action
     * @param string|Closure(): string $success
     */
    private function runOnDetail(ApprovalRequest $approvalRequest, Closure $action, string|Closure $success): RedirectResponse
    {
        $show = redirect()->route('approvals.requests.show', $approvalRequest);

        try {
            $action();
        } catch (WorkflowConflict) {
            return $show->with('error', WorkflowConflict::MESSAGE);
        } catch (WorkflowRefused $e) {
            return $show->withInput()->with('error', implode(' ', $e->reasons));
        }

        return $show->with('success', $success instanceof Closure ? $success() : $success);
    }
}
