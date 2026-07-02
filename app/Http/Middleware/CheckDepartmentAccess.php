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
     * 引数省略時はルート/リクエストの 'department' パラメータを参照する。
     * 非経営層で部門コードが特定できない場合は fail-closed で 403 とする
     * （素通りさせない）。
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

        // 適用する部門コード: ミドルウェア引数を優先。無ければ
        // ルート/リクエストの 'department' パラメータを参照する。
        if (empty($codes)) {
            $param = $request->route('department') ?? $request->input('department');
            if (!$param) {
                // fail-closed: 部門コードが特定できない非経営層は遮断する。
                // （旧実装は素通り＝fail-open だった。将来この経路に部門必須でない
                //   アクションが追加されても無認可で通さないための多重防御）
                abort(403, 'この部門のデータへのアクセス権限がありません。');
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
