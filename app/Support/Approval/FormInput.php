<?php

namespace App\Support\Approval;

use Illuminate\Http\Request;

/**
 * 決裁の画面から送られてきた値の読み方（段階2b 計画 §0.11）。2a では 3 つのコントローラに同じ中身の
 * private メソッドがあった（Task 19 の点検 m-7）ので、2b で理由の入力欄が増えるのに合わせて 1 か所に寄せる。
 */
final class FormInput
{
    /**
     * 改行を \n にそろえる（Task 19 の B1）。ブラウザの maxlength は改行を 1 文字と数えるが、送るときは \r\n にするので、
     * そろえずに数えると改行の多い入力が上限の手前で断られる。検査の前に呼ぶ（保存する値もそろえた形になる）。
     * 文字列でない値（配列など）はそのまま（検査の `string` で断る）。
     */
    public static function unifyNewlines(Request $request, string ...$keys): void
    {
        foreach ($keys as $key) {
            $value = $request->input($key);

            if (is_string($value)) {
                $request->merge([$key => str_replace(["\r\n", "\r"], "\n", $value)]);
            }
        }
    }

    /**
     * 画面が描いたときの lock_version（計画 2a §0.3）。0 以上の整数の形でなければ -1（必ず「すでに処理されています」）。
     * ⚠ `$request->integer()` は intval なので、配列を 1、'1abc' を 1 と読んでしまう（2a の Task 15 の点検 m-1）。
     *
     * @param int $whenMissing 送られてこなかったときの値。判断などの操作は -1（必ず断る）。申請書の保存は 0
     *                         （作ってから一度も保存し直していない下書きの版。2a からの振る舞い）
     */
    public static function lockVersion(Request $request, int $whenMissing = -1): int
    {
        $value = $request->input('lock_version');

        if ($value === null) {
            return $whenMissing;
        }

        return is_string($value) && preg_match('/\A\d{1,9}\z/', $value) === 1 ? (int) $value : -1;
    }
}
