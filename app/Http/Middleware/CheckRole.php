<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * ユーザーのロールが指定されたロールのいずれかに一致するかチェックする。
     *
     * 使用例:
     *   middleware('role:executive')           → 経営層のみ
     *   middleware('role:executive,manager')   → 経営層＋部門管理者
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles  許可するロール（カンマ区切り）
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // 【一時診断ログ】CheckRole 通過記録
        \Log::info('CheckRole entry', [
            'user_id'        => $user?->id,
            'user_role'      => $user?->role?->value,
            'required_roles' => $roles,
            'route'          => $request->route()?->getName(),
            'url'            => $request->fullUrl(),
            'method'         => $request->method(),
        ]);

        if (!$user) {
            return redirect()->route('login');
        }

        // ユーザーのロール値が許可リストに含まれるか判定
        if (!in_array($user->role->value, $roles)) {
            // 【一時診断ログ】403 abort 直前
            \Log::warning('CheckRole 403 abort', [
                'user_id'        => $user->id,
                'user_role'      => $user->role->value,
                'required_roles' => $roles,
                'route'          => $request->route()?->getName(),
                'url'            => $request->fullUrl(),
            ]);
            abort(403, 'このページへのアクセス権限がありません。');
        }

        return $next($request);
    }
}
