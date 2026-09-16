<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 無効（status = inactive）な利用者を、ログイン中でもその場で締め出す（設計書 §5.5・D7）。
 *
 * ⚠ **web グループに置く**（ルートのミドルウェアではない）。ルート側に付ける方式だと、
 *   付け忘れた画面から入れてしまう（`/dashboard/tenant` には `role:` も `department.access` も無い）。
 *
 * ⚠ **`SubstituteBindings`（ルートモデル結合）より前**に走らせる（`bootstrap/app.php` の優先順）。
 *   後ろだと、存在しない ID で 404 が返って「そのデータがあるかどうか」が漏れる。
 *
 * ⚠ `Auth::logout()` は鍵（remember_token）を作り直すので、ほかの端末の自動ログインも
 *   同時に無効になる（`SessionGuard::cycleRememberToken`）。これが D7 の本体。
 */
class EnsureUserIsActive
{
    /** ログイン時の既存の文言にそろえる（D15） */
    public const MESSAGE = 'このアカウントは無効になっています。管理者にお問い合わせください。';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->isActive()) {
            return $next($request);
        }

        Auth::logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson() || $request->ajax()) {
            abort(401, self::MESSAGE);
        }

        return redirect()->route('login')->withErrors(['login' => self::MESSAGE]);
    }
}
