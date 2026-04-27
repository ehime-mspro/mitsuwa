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
        return view('auth.change-password', [
            'isForced' => Auth::user()->must_change_password,
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

        return redirect()->route('dashboard')->with('success', 'パスワードを変更しました。');
    }
}
