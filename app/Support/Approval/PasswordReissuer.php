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

            // 強度 10 のハッシュを入れる手順は User::setInitialPassword() に 1 本化してある
            // （hashed キャストとの衝突の理由はそちらの docblock。再発行・新規登録・CSV が同じ道を通る）
            $user->setInitialPassword($password);
            $user->save();

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
