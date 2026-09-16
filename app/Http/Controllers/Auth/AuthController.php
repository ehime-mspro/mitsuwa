<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\LoginHistory;
use App\Support\LoginId;
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
     *
     * 社員番号またはメールアドレスで照合する（設計書 §5.3）。`@` の有無だけで引く列を決め、
     * 形式が社員番号の規則に合わなくてもそのまま照合して失敗させる（どちらの種類の ID かを画面に出さない）。
     */
    public function login(Request $request)
    {
        $request->validate([
            'login_id' => ['required', 'string', 'max:255'],
            'password' => ['required'],
        ], [
            'login_id.required' => '社員番号またはメールアドレスを入力してください。',
            'login_id.max'      => '社員番号またはメールアドレスは255文字以内で入力してください。',
            'password.required' => 'パスワードを入力してください。',
        ]);

        $loginId  = LoginId::normalize($request->input('login_id'));
        $remember = $request->boolean('remember');

        $credentials = [
            LoginId::column($loginId) => $loginId,
            'password'                => $request->input('password'),
        ];

        if (!Auth::attempt($credentials, $remember)) {
            return back()
                ->withInput($request->only('login_id', 'remember'))
                ->withErrors(['login' => '社員番号・メールアドレスまたはパスワードが正しくありません。']);
        }

        $user = Auth::user();

        // アカウントが無効の場合はログアウトしてエラー
        if (!$user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()
                ->withInput($request->only('login_id'))
                ->withErrors(['login' => EnsureUserIsActive::MESSAGE]);
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

        // ロールに応じた行き先（規則は User::homeRouteName() の 1 箇所だけ）
        return redirect()->route($user->homeRouteName());
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
