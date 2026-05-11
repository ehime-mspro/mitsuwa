<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    /**
     * 初回ログイン時のパスワード変更を強制する。
     *
     * must_change_passwordフラグがtrueの場合、
     * パスワード変更画面とログアウト以外の全ページをリダイレクトする。
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            // パスワード変更画面とログアウトは許可
            $allowedRoutes = ['password.change', 'password.update', 'logout'];

            if (!in_array($request->route()?->getName(), $allowedRoutes)) {
                // 【一時診断ログ】password 強制変更でリダイレクト
                \Log::info('ForcePasswordChange redirect', [
                    'user_id' => $user->id,
                    'route'   => $request->route()?->getName(),
                ]);
                return redirect()->route('password.change')
                    ->with('warning', '初回ログインのため、パスワードの変更が必要です。');
            }
        }

        return $next($request);
    }
}
