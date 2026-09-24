<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * パスワード変更フォーム表示
     * Route: GET /password/change
     */
    public function showChange()
    {
        $user     = Auth::user();
        $previous = session()->previousUrl();

        return view('auth.change-password', [
            'isForced'  => $user->must_change_password,
            // キャンセルの戻り先は、直前の GET の画面（セッションの記録。GET かつ Ajax でない要求だけが記録される）。
            // ⚠ url()->previous() はリファラーを優先するので、POST の応答の画面（CSV 取込の確認画面など）から
            //   開くと POST 専用の URL になり、戻ると 405 だった（docs/RULES.md Bug #64）。
            // ⚠ 直前の画面が無い・この画面自身（差し戻された直後）ならその人のホーム。`route('dashboard')` にしない
            //   （決裁のみ利用者は門番に跳ね返されて警告を見る。Bug #63）
            'cancelUrl' => $previous !== null && $previous !== url()->current() ? $previous : route($user->homeRouteName()),
        ]);
    }

    /**
     * パスワード変更処理
     * Route: PUT /password/change
     */
    public function change(Request $request)
    {
        $request->validate([
            'current_password' => ['required'],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->letters()->numbers(),
            ],
        ], [
            'current_password.required' => '現在のパスワードを入力してください。',
            'password.required' => '新しいパスワードを入力してください。',
            'password.confirmed' => '新しいパスワードが一致しません。',
            'password.min' => 'パスワードは8文字以上で入力してください。',
            'password.letters' => 'パスワードには英字を含めてください。',
            'password.numbers' => 'パスワードには数字を含めてください。',
        ]);

        $user = Auth::user();

        // 現在のパスワードが正しいかチェック
        if (!Hash::check($request->current_password, $user->password)) {
            return back()->withErrors([
                'current_password' => '現在のパスワードが正しくありません。',
            ]);
        }

        // 新しいパスワードが現在と同じでないかチェック
        if (Hash::check($request->password, $user->password)) {
            return back()->withErrors([
                'password' => '現在のパスワードと異なるパスワードを設定してください。',
            ]);
        }

        // パスワード更新（Userモデルのhashedキャストが自動ハッシュ化）
        $user->update([
            'password' => $request->password,
            'must_change_password' => false,
        ]);

        // ⚠ その人のホームへ直接戻す（`/dashboard` を経由させない）。決裁のみ利用者は門番に跳ね返されて
        //   「決裁以外の画面は使えません。」が出るうえ、2 回目の転送でこのフラッシュが消える
        //   （F1。実測: 302 → 302 → 200。基幹の人も /dashboard → /dashboard/tenant の 2 段で消えていた）。
        //   行き先の規則は User::homeRouteName() の 1 箇所（ログイン直後と同じ）
        return redirect()->route($user->homeRouteName())->with('success', 'パスワードを変更しました。');
    }
}
