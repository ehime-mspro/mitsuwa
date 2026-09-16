<?php

namespace App\Support\Approval;

use App\Mail\PasswordReissuedMail;
use App\Models\ApprovalMailDomain;
use App\Models\User;
use App\Support\InitialPassword;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * パスワードの再発行（設計書 §5.7・§5.9・§5.13）。
 *
 * 基幹の管理者の再発行・決裁の管理者の再発行（1 人・まとめて）がすべてここを通る。
 *
 * ⚠ 平文は戻り値（画面に出すため）にだけ載せ、DB・セッション・ログ・記録には残さない。
 * ⚠ **誰を再発行してよいか**の判断はここでしない（呼び出し側の権限の問題。D8 / D16）。
 */
final class PasswordReissuer
{
    /** @param  Collection<int, User>  $users */
    public function reissue(Collection $users): ReissueResult
    {
        $actorName = auth()->user()?->name ?? '';
        $loginUrl  = route('login');
        $now       = now();

        $entries  = [];
        $notified = 0;
        $skipped  = 0;

        foreach ($users as $user) {
            $password = InitialPassword::generate();

            // ⚠ password は $fillable にあるが hashed キャストが掛かるので、強度 10 の
            //    ハッシュを直接入れるために forceFill で属性ごと差し替える
            // ⚠ Laravel の hashed キャストは「今の設定より高いコストの済ハッシュ」を弾く安全策を持つ
            //    （HasAttributes::castAttributeAsHashedString → Hash::verifyConfiguration()）。
            //    本番は BCRYPT_ROUNDS=12 なので強度 10 は素通りするが、テストは高速化のため
            //    phpunit.xml で BCRYPT_ROUNDS=4 にしており、10 > 4 で RuntimeException になる
            //    （実測: 「Could not verify the hashed value's configuration.」）。
            //    この 1 回だけ password のキャストを外し、強度 10 のハッシュ文字列をそのまま保存する
            $user->mergeCasts(['password' => 'string']);
            $user->forceFill([
                'password'             => InitialPassword::hash($password),
                'must_change_password' => true,
            ])->save();

            $entries[] = ['user' => $user, 'password' => $password];

            SettingLogger::record('user.password_reissued', 'user', $user->id);

            if (ApprovalMailDomain::allows($user->email)) {
                Mail::to($user->email)->queue(new PasswordReissuedMail($user, $actorName, $now, $loginUrl));
                $notified++;
            } else {
                $skipped++;
            }
        }

        return new ReissueResult($entries, $notified, $skipped);
    }
}
