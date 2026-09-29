<?php

namespace App\Http\Controllers\Approval;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Support\Approval\RelatedNumbers;
use App\Support\Approval\RequestVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 関連する決裁No の候補（設計書 §5.6・D15）。申請書の画面が入力中に Ajax の GET で呼ぶ。
 *
 * 見られる範囲（RequestVisibility）の、番号の付いた申請だけを返す（番号と件名）。
 * ⚠ 呼ぶ側の fetch は X-Requested-With を付ける（Bug #35。付けないとセッションの直前 URL が
 *   この JSON で上書きされる）。
 * ⚠ LIKE の % と _ は逃がさない（アプリのほかの検索と同じ。見られる範囲の中で候補が広がるだけ）。
 * ⚠ 差戻し中の申請は、最後に提出した控えの件名で当て、控えの件名を返す（取り消しで番号を残したまま差戻しに戻った申請の
 *   直しかけを、ほかの人に漏らさない。D26・設計書 §5.16・2b 計画 Task 6）。ほかの状態は中身を直せないので、今の件名が
 *   最後に提出した件名と同じ。
 */
class RelatedNumberController extends Controller
{
    /** 候補の数の上限 */
    private const LIMIT = 10;

    public function search(Request $request): JsonResponse
    {
        // ⚠ ?q[]=… のように配列で来ても 500 にしない（文字列でなければ空として扱う）
        // ⚠ 壊れた文字（不正な UTF-8）だと preg_replace は null を返す。null のまま進むと LIKE '%%' で
        //   見られる申請すべてに当たるので、空として扱う
        $raw  = $request->query('q');
        $text = is_string($raw) ? (preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $raw) ?? '') : '';

        if ($text === '') {
            return response()->json(['items' => []]);
        }

        // 番号は全角・小文字でも当たるようにそろえる。件名は入力のまま探す
        $number = RelatedNumbers::normalize($text);

        $returned = ApprovalStatus::Returned->value;
        $found    = RequestVisibility::apply(ApprovalRequest::query(), $request->user())
            ->whereNotNull('number')
            ->where(fn (Builder $q) => $q
                ->where('number', 'like', "%{$number}%")
                ->orWhere(fn (Builder $q) => $q->where('status', '!=', $returned)->where('subject', 'like', "%{$text}%"))
                ->orWhere(fn (Builder $q) => $q->where('status', $returned)->whereExists(fn (QueryBuilder $s) => $s
                    ->selectRaw('1')->from('approval_revisions')
                    ->whereColumn('approval_revisions.request_id', 'approval_requests.id')
                    ->whereColumn('approval_revisions.round', 'approval_requests.round')
                    ->where('approval_revisions.snapshot->subject', 'like', "%{$text}%"))))
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'number', 'subject', 'status', 'round']);

        // 差戻し中の申請は、最後に提出した控えの件名を返す（1 回の問い合わせで読む）
        $submitted = ApprovalRevision::whereIn('request_id', $found->where('status', ApprovalStatus::Returned)->pluck('id')->all())
            ->get(['request_id', 'round', 'snapshot'])
            ->mapWithKeys(fn (ApprovalRevision $revision) => ["{$revision->request_id}:{$revision->round}" => $revision->snapshot['subject'] ?? null])
            ->all();

        $items = $found
            ->map(fn (ApprovalRequest $r) => [
                'number'  => $r->number,
                'subject' => $r->status === ApprovalStatus::Returned ? ($submitted["{$r->id}:{$r->round}"] ?? null) : $r->subject,
            ])
            ->all();

        return response()->json(['items' => $items]);
    }
}
