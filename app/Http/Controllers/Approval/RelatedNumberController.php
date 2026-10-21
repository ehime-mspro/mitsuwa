<?php

namespace App\Http\Controllers\Approval;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalPaperRecord;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Support\Approval\RelatedNumbers;
use App\Support\Approval\RequestVisibility;
use App\Support\JapanTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 関連する決裁No の候補（設計書 §5.6・D15）。申請書の画面が入力中に Ajax の GET で呼ぶ。
 *
 * 見られる範囲（RequestVisibility）の、番号の付いた申請と取り込んだ紙の決裁を返す（番号と件名。紙の決裁には `paper: true`）。
 * ⚠ 呼ぶ側の fetch は X-Requested-With を付ける（Bug #35。付けないとセッションの直前 URL が
 *   この JSON で上書きされる）。
 * ⚠ LIKE の % と _ は逃がさない（アプリのほかの検索と同じ。見られる範囲の中で候補が広がるだけ）。
 * ⚠ 差戻し中の申請と、差戻し中に直して保存してから取り下げた申請は、最後に提出した控えの件名で当て、控えの件名を返す
 *   （取り消しで番号を残したまま差戻しに戻った申請の直しかけを、ほかの人に漏らさない。D26・設計書 §5.16・2b 計画 Task 6）。
 *   ほかの状態は中身を直せないので、今の件名が最後に提出した件名と同じ。
 * ⚠ 見られる紙の決裁も候補に出す（段階5 §5.15。`paper: true` を付け、画面は「紙」と添える。申請の候補には付けない）。
 *   申請と紙を合わせて決裁日の新しい順に並べ、上限で切る（同じ日は申請が先）
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

        // 直しかけが今の中身に残りうる状態（差戻し中と、差戻し中に直して保存してから取り下げた申請）
        $fromRevision = ApprovalStatus::mayDifferFromSubmission();
        $values       = array_map(fn (ApprovalStatus $status) => $status->value, $fromRevision);

        $found = RequestVisibility::apply(ApprovalRequest::query(), $request->user())
            ->whereNotNull('number')
            ->where(fn (Builder $q) => $q
                ->where('number', 'like', "%{$number}%")
                ->orWhere(fn (Builder $q) => $q->whereNotIn('status', $values)->where('subject', 'like', "%{$text}%"))
                ->orWhere(fn (Builder $q) => $q->whereIn('status', $values)->whereExists(fn (QueryBuilder $s) => $s
                    ->selectRaw('1')->from('approval_revisions')
                    ->whereColumn('approval_revisions.request_id', 'approval_requests.id')
                    ->whereColumn('approval_revisions.round', 'approval_requests.round')
                    ->where('approval_revisions.snapshot->subject', 'like', "%{$text}%"))))
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'number', 'subject', 'status', 'round', 'decided_at']);

        // 差戻し中と取り下げの申請は、最後に提出した控えの件名を返す（1 回の問い合わせで読む）
        $submitted = ApprovalRevision::whereIn('request_id', $found->filter(fn (ApprovalRequest $r) => in_array($r->status, $fromRevision, true))->pluck('id')->all())
            ->get(['request_id', 'round', 'snapshot'])
            ->mapWithKeys(fn (ApprovalRevision $revision) => ["{$revision->request_id}:{$revision->round}" => $revision->snapshot['subject'] ?? null])
            ->all();

        $items = $found
            ->map(fn (ApprovalRequest $r) => [
                'day'  => JapanTime::format($r->decided_at, 'Y-m-d') ?? '',
                'item' => [
                    'number'  => $r->number,
                    'subject' => in_array($r->status, $fromRevision, true) ? ($submitted["{$r->id}:{$r->round}"] ?? null) : $r->subject,
                ],
            ]);

        $papers = ApprovalPaperRecord::query()->visibleTo($request->user())
            ->where(fn (Builder $q) => $q->where('number', 'like', "%{$number}%")->orWhere('subject', 'like', "%{$text}%"))
            ->orderByDesc('decided_on')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'number', 'subject', 'decided_on'])
            ->map(fn (ApprovalPaperRecord $p) => [
                'day'  => $p->decided_on->format('Y-m-d'),
                'item' => ['number' => $p->number, 'subject' => $p->subject, 'paper' => true],
            ]);

        // 決裁日の新しい順（同じ日は申請が先。sortBy は同じ値の順を保つ）
        $merged = $items->concat($papers)->sortByDesc('day')->take(self::LIMIT)->pluck('item')->values()->all();

        return response()->json(['items' => $merged]);
    }
}
