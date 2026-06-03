<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckDepartmentAccess
{
    /**
     * ユーザーが指定部門のデータにアクセスできるかチェックする。
     *
     * 経営層は全部門アクセス可能。
     * 部門管理者・一般担当者はdepartment_userで紐づく部門のみ。
     *
     * 適用部門はミドルウェア引数（例: department.access:realestate）で指定する。
     * 引数省略時は後方互換でルート/リクエストの 'department' パラメータを参照する。
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$codes  許可する部門コード（複数指定時はいずれかに所属でOK）
     */
    public function handle(Request $request, Closure $next, string ...$codes): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        // 経営層は全部門アクセス可能
        if ($user->role->isExecutive()) {
            return $next($request);
        }

        // 適用する部門コード: ミドルウェア引数を優先。無ければ従来どおり
        // ルート/リクエストの 'department' パラメータを参照（後方互換）
        if (empty($codes)) {
            $param = $request->route('department') ?? $request->input('department');
            if (!$param) {
                return $next($request);
            }
            $codes = [$param];
        }

        // いずれかの部門に所属していればアクセス許可
        foreach ($codes as $code) {
            if ($user->belongsToDepartment($code)) {
                return $next($request);
            }
        }

        abort(403, 'この部門のデータへのアクセス権限がありません。');
    }
}
