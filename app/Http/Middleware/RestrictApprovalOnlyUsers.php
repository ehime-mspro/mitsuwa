<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 決裁のみ利用者を、決裁以外の全画面から締め出す（設計書 §5.2・要件 15.6）。
 *
 * ⚠ **web グループに置く**（ルートのミドルウェアではない）。`role:` や `department.access` は
 *   付いていない画面があり（`/dashboard/tenant` は 2026-09-15 実測でどちらも無い）、
 *   ロールだけでは守れない。ここが守りの本体で、`role:` / `department.access` は二重の守り。
 *
 * ⚠ **`SubstituteBindings`（ルートモデル結合）より前**に走らせる（`bootstrap/app.php` の優先順）。
 *   後ろだと存在しない ID で 404 が返り、「そのデータがあるかどうか」が漏れる。
 *
 * 通すのは 3 種類だけ:
 *   - ルート名が `approvals.` で始まるもの（決裁の画面）
 *   - `password.change` / `password.update` / `logout`
 *   - `guest` ミドルウェアを持つルート（未ログイン専用。ログイン済みの人は RedirectIfAuthenticated が追い返す）
 *
 * 全ルートを 4 つに分類して検査する `ApprovalOnlyLockoutTest` が、新しいルートを自動で検査対象にする。
 */
class RestrictApprovalOnlyUsers
{
    public const MESSAGE = '決裁以外の画面は使えません。';

    private const ALLOWED_NAMES = ['password.change', 'password.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isApprovalOnly()) {
            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->ajax() || ! $request->isMethod('GET')) {
            abort(403, self::MESSAGE);
        }

        return redirect()->route('approvals.home')->with('warning', self::MESSAGE);
    }

    private function isAllowed(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        $name = $route->getName();

        if ($name !== null && (str_starts_with($name, 'approvals.') || in_array($name, self::ALLOWED_NAMES, true))) {
            return true;
        }

        return in_array('guest', $route->gatherMiddleware(), true);
    }
}
