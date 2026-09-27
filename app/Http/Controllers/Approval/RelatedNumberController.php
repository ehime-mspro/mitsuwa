<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Support\Approval\RelatedNumbers;
use App\Support\Approval\RequestVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 関連する決裁No の候補（設計書 §5.6・D15）。申請書の画面が入力中に Ajax の GET で呼ぶ。
 *
 * 見られる範囲（RequestVisibility）の、番号の付いた申請だけを返す（番号と件名）。
 * ⚠ 呼ぶ側の fetch は X-Requested-With を付ける（Bug #35。付けないとセッションの直前 URL が
 *   この JSON で上書きされる）。
 * ⚠ LIKE の % と _ は逃がさない（アプリのほかの検索と同じ。見られる範囲の中で候補が広がるだけ）。
 */
class RelatedNumberController extends Controller
{
    /** 候補の数の上限 */
    private const LIMIT = 10;

    public function search(Request $request): JsonResponse
    {
        // ⚠ ?q[]=… のように配列で来ても 500 にしない（文字列でなければ空として扱う）
        $raw  = $request->query('q');
        $text = is_string($raw) ? preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $raw) : '';

        if ($text === '') {
            return response()->json(['items' => []]);
        }

        // 番号は全角・小文字でも当たるようにそろえる。件名は入力のまま探す
        $number = RelatedNumbers::normalize($text);

        $items = RequestVisibility::apply(ApprovalRequest::query(), $request->user())
            ->whereNotNull('number')
            ->where(fn (Builder $q) => $q->where('number', 'like', "%{$number}%")->orWhere('subject', 'like', "%{$text}%"))
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['number', 'subject'])
            ->map(fn (ApprovalRequest $found) => ['number' => $found->number, 'subject' => $found->subject])
            ->all();

        return response()->json(['items' => $items]);
    }
}
