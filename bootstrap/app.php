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
            // 使い始める前は申請を回す画面を誰にも見せない（段階2 設計書 §5.2・D1）
            'approval.launched' => \App\Http\Middleware\EnsureApprovalLaunched::class,
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

        // 決裁の管理の 2 段目（`approval.admin`）も **SubstituteBindings より前**に出す。
        //
        // ⚠ ルートに付けた別名は既定では SubstituteBindings の後ろに並ぶので、権限の無い人が
        //    存在しない ID を叩くと 403 でなく **404** が返り、「その会社・部門・ドメインが
        //    実在するか」を数えられてしまう（実測）。上の 3 本と同じ理由・同じ流儀で前へ出す。
        $middleware->appendToPriorityList(
            \App\Http\Middleware\RestrictApprovalOnlyUsers::class,
            \App\Http\Middleware\EnsureApprovalAdmin::class,
        );

        // 使い始める前の門番（段階2 設計書 §5.2）も SubstituteBindings より前に出す（存在しない ID でも同じ応答）。
        // ⚠ EnsureApprovalAdmin の**後ろ**に置く。前に置くと、管理と稼働の両方の門番を持つ画面（2b の
        //    approvals.admin.requests.*）を権限の無い人が開いたときに、403 より先に転送・404 が返る。
        //    LaunchGateTest の並びのテストが止める（2b からは ApprovalAdminGateTest の 403 も止める）。
        $middleware->appendToPriorityList(
            \App\Http\Middleware\EnsureApprovalAdmin::class,
            \App\Http\Middleware\EnsureApprovalLaunched::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // 入力チェックで断ったとき、配列の形が合わない欄（文字の欄に配列・配列の欄に文字）は控え（old()）に入れない（Bug #121）。
        // 画面は old() を文字のつもりで描くので、控えると戻った画面が 500 になる。外した欄は空か保存済みの値で描かれる。
        // null を返して既定の処理（Handler::invalid）へ続ける。判定は App\Support\ShapeCheckingValidator の 1 か所
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, \Illuminate\Http\Request $request) {
            if ($e->validator instanceof \App\Support\ShapeCheckingValidator) {
                $e->validator->forgetMismatchedInput($request);
            }

            return null;
        });

        // 決裁の添付（Ajax）が PHP の送信の上限（post_max_size）を超えたとき、英語の既定文ではなく
        // 日本語の理由を JSON で返す（段階2 設計書 §5.7）。⚠ 決裁の URL だけ（基幹の画面の応答は変えない）
        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, \Illuminate\Http\Request $request) {
            if ($request->is('approvals/*') && $request->expectsJson()) {
                return response()->json(['message' => 'ファイルが大きすぎて受け取れませんでした。1 ファイル 10MB までです。'], 413);
            }
        });

        // 決裁の URL の JSON の 404 は、無い ID と見られない ID で同じ日本語の文にする。Laravel の既定では、無い ID にだけ
        // 「No query results for model [App\Models\…] 12」が返り、在るかどうかが分かる（見られない ID は空の文）。
        // 申請書の添付の欄は、断られた理由としてこの文をそのまま出す。⚠ 決裁の URL だけ（基幹の応答は変えない）
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
            if ($request->is('approvals/*') && $request->expectsJson()) {
                return response()->json(['message' => '見つかりませんでした。画面を開き直してください。'], 404);
            }
        });

        // JSON の 419（画面の送信の鍵が合わない。既定は「CSRF token mismatch.」）と、決裁の URL・基幹の GET 以外の JSON の 401（ログインが
        // 切れた。既定は「Unauthenticated.」）を日本語にする。申請書の添付の欄は、断られた理由としてこの文をそのまま出す（Task 19 の B5）。
        // 419 は画面を開いたまま時間がたってセッションが切れたときのほか、ほかの画面でログアウトしたときにも出る（ログアウトは鍵を
        // 作り直すので、ログインの確かめより先に断られる。手元のブラウザで実測。Task 19 の N-2）。401 はほかの画面でパスワードを変えたときなど。
        // ⚠ 419 は決裁と基幹の両方（Bug #137）。基幹の保存の Ajax の約 16 画面がサーバーの文をそのまま出す（直す前は英語が出ていた）。
        //    決裁の画面の応答は前と同じ文。ふつうのフォームの送信（JSON を求めない）は resources/views/errors/419.blade.php の画面。
        //    ただしログインの送信だけは、419 の画面を出さず新しいログイン画面へ戻して赤い帯に理由を出す（Bug #138。下の分岐）。
        // ⚠ 401 は決裁の URL と、基幹の GET 以外（2026-11-18。保存の Ajax の約 26 画面がサーバーの文をそのまま出し、直す前は英語の
        //    「Unauthenticated.」が出ていた。保存の Ajax で 401 が出るのは、ほかの画面でパスワードを変えたときなどまれ）。
        //    GET は英語のまま（_partials/_ajax_get_failure が英語の 401 で「ログインが切れた」を見分け、「入力中の内容があれば控えて」と
        //    案内する）。原価のカード（realEstateCostAccessError）はこの文と比べて見分ける。文は AuthController::SIGNED_OUT_MESSAGE。
        // ⚠ 419 は、Laravel が TokenMismatchException を HttpException(419) に置き換えてから render を呼ぶので、HttpException で受けて番号を見る
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() === 419 && $request->expectsJson()) {
                return response()->json(['message' => '画面を開いてから時間がたったか、ほかの画面でログアウトしたため、送れませんでした。画面を開き直して、もう一度やり直してください。'], 419);
            }

            // ログインの送信（ログイン画面を開いたまま時間がたった・ほかの画面でログアウトした）。419 の画面の「前の画面に戻る」は
            // 古い鍵のログイン画面へ戻すので、何度送っても 419 になり、開き直すまでログインできなかった（2026-11-18 に本番で起きた）。
            // 新しいログイン画面へ戻せば新しい鍵が描かれ、そのまま送ればログインできる。鍵の確かめは guest・throttle:login より前なので、
            // ログインの失敗の回数には数えない。⚠ JSON を求める要求は上の分岐が先に返す
            if ($e->getStatusCode() === 419 && $request->isMethod('POST') && $request->is('login')) {
                // ⚠ パスワードは控えない。文字でない値（手で組んだ login_id[]=x）も控えない（ログイン画面は old('login_id') を描くので、
                //    控えると戻った画面が 500 になる。Bug #121）
                return redirect()->route('login')
                    ->withInput(array_filter($request->only('login_id', 'remember'), 'is_string'))
                    ->withErrors(['login' => \App\Http\Controllers\Auth\AuthController::EXPIRED_PAGE_MESSAGE]);
            }
        });
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() && ($request->is('approvals/*') || ! $request->isMethod('GET'))) {
                return response()->json(['message' => \App\Http\Controllers\Auth\AuthController::SIGNED_OUT_MESSAGE], 401);
            }
        });
    })
    ->create();
