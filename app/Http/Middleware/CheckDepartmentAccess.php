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
     * ルートパラメータまたはリクエストパラメータの'department'を参照。
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        // 経営層は全部門アクセス可能
        if ($user->role->isExecutive()) {
            return $next($request);
        }

        // リクエストから部門コードを取得（ルートパラメータ or リクエストパラメータ）
        $departmentCode = $request->route('department') ?? $request->input('department');

        // 部門コードが指定されていない場合はスキップ（コントローラーで個別に制御）
        if (!$departmentCode) {
            return $next($request);
        }

        // ユーザーが指定部門に所属しているかチェック
        if (!$user->belongsToDepartment($departmentCode)) {
            abort(403, 'この部門のデータへのアクセス権限がありません。');
        }

        return $next($request);
    }
}
