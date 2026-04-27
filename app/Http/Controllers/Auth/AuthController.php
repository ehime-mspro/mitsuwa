<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * ログインフォーム表示
     * Route: GET /login
     */
    public function showLogin()
    {
        return view('auth.login');
    }

    /**
     * ログイン処理
     * Route: POST /login
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ], [
            'email.required' => 'メールアドレスを入力してください。',
            'email.email' => '正しいメールアドレスを入力してください。',
            'password.required' => 'パスワードを入力してください。',
        ]);

        $remember = $request->boolean('remember');

        if (!Auth::attempt($credentials, $remember)) {
            return back()
                ->withInput($request->only('email', 'remember'))
                ->withErrors(['login' => 'メールアドレスまたはパスワードが正しくありません。']);
        }

        $user = Auth::user();

        // アカウントが無効の場合はログアウトしてエラー
        if (!$user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['login' => 'このアカウントは無効になっています。管理者にお問い合わせください。']);
        }

        // セッション再生成（セッション固定攻撃対策）
        $request->session()->regenerate();

        // 最終ログイン日時を更新
        $user->update(['last_login_at' => now()]);

        // ログイン履歴を記録
        LoginHistory::create([
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'logged_in_at' => now(),
        ]);

        // 初回ログイン時はパスワード変更画面へ
        if ($user->must_change_password) {
            return redirect()->route('password.change');
        }

        // ロールに応じてダッシュボードへリダイレクト
        if ($user->role->isExecutive()) {
            return redirect()->route('dashboard.executive');
        }

        return redirect()->route('dashboard.tenant');
    }

    /**
     * ログアウト処理
     * Route: POST /logout
     */
    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
