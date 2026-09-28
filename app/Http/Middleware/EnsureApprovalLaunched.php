<?php

namespace App\Http\Middleware;

use App\Models\ApprovalSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 使い始める前は、申請を回す画面を誰にも見せない（設計書 §5.2・D1・計画 §0.6）。
 *
 * ⚠ 決裁の管理者にも見せない。準備の画面（利用者・部門・申請の種類の管理）にはこの門番を付けない。
 * ⚠ 画面を開く GET・HEAD はホーム（「準備中」を出す）へ送る。それ以外（POST・Ajax・JSON）は 404。
 * ⚠ 並びは `SubstituteBindings` より前・`EnsureApprovalAdmin` より後（bootstrap/app.php）。
 */
class EnsureApprovalLaunched
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ApprovalSetting::current()->isLaunched()) {
            return $next($request);
        }

        $opensAPage = ($request->isMethod('GET') || $request->isMethod('HEAD'))
            && ! $request->expectsJson()
            && ! $request->ajax();

        if ($opensAPage) {
            return redirect()->route('approvals.home');
        }

        abort(404);
    }
}
