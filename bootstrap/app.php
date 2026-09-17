<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // ミドルウェアエイリアスの登録
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'department.access' => \App\Http\Middleware\CheckDepartmentAccess::class,
            'password.change' => \App\Http\Middleware\ForcePasswordChange::class,
            // 決裁の管理者に指定された人だけを通す（設計書 §5.2・§5.17）
            'approval.admin' => \App\Http\Middleware\EnsureApprovalAdmin::class,
        ]);

        // 決裁申請 段階1（設計書 §5.2・§5.5）
        //
        // ⚠ 3 本とも **web グループ**（全画面の入口）に置く。ルートごとに付ける方式は付け忘れが効かない。
        // ⚠ 順番は「パスワードの控えの確認 → 無効化 → 決裁のみの締め出し」。
        //    下の appendToPriorityList でこの順に並べ、SubstituteBindings（ルートモデル結合）より
        //    前で止める（存在しない ID でも 404 にならず、データの有無が漏れない）。
        $middleware->web(append: [
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            \App\Http\Middleware\EnsureUserIsActive::class,
            \App\Http\Middleware\RestrictApprovalOnlyUsers::class,
        ]);

        // 既定の優先順は … AuthenticatesSessions → SubstituteBindings → Authorize
        // （`Foundation/Http/Kernel::$middlewarePriority`）。その間に 2 本を割り込ませる。
        $middleware->appendToPriorityList(
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            \App\Http\Middleware\EnsureUserIsActive::class,
        );
        $middleware->appendToPriorityList(
            \App\Http\Middleware\EnsureUserIsActive::class,
            \App\Http\Middleware\RestrictApprovalOnlyUsers::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
