<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 決裁のホーム（設計書 §5.15）。
 *
 * ⚠ 段階1 は**仮の画面**。段階2 で本当のホーム（要件定義書 13 章 ①）に置き換える。
 *   決裁の機能はまだ何も無いので、ここに機能を足していかないこと。
 */
class HomeController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('approvals.home', [
            'user'    => $user,
            'loginId' => $user->employee_number ?? $user->email,
        ]);
    }
}
