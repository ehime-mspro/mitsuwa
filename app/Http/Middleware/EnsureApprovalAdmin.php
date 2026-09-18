<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 決裁の管理者に指定された人だけを通す（設計書 §5.2・§5.17）。
 *
 * ⚠ **経営層でも、指定されていなければ 403。** 指定は基幹の利用者管理（`/admin/users`）で行う。
 *   段階1 は「指定するまで誰にも決裁の管理の画面が出ない」状態から始まる（D2）。
 * ⚠ これは web グループの門番を通ったあとの 2 段目（決裁のみ利用者も基幹を使う人も同じ判定）。
 */
class EnsureApprovalAdmin
{
    /** `RestrictApprovalOnlyUsers::MESSAGE` / `EnsureUserIsActive::MESSAGE` と同じ形で定数化する */
    public const MESSAGE = 'この画面を使う権限がありません。';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isApprovalAdmin()) {
            abort(403, self::MESSAGE);
        }

        return $next($request);
    }
}
