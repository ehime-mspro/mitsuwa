<?php

namespace App\Support\Approval;

use App\Mail\PasswordReissuedMail;
use App\Models\ApprovalMailDomain;
use App\Models\User;
use App\Support\InitialPassword;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * パスワードの再発行（設計書 §5.7・§5.9・§5.13）。
 *
 * 基幹の管理者の再発行・決裁の管理者の再発行（1 人・まとめて）がすべてここを通る。
 *
 * ⚠ 平文は戻り値（画面に出すため）にだけ載せ、DB・セッション・ログ・記録には残さない。
 * ⚠ **誰を再発行してよいか**の判断はここでしない（呼び出し側の権限の問題。D8 / D16）。
 *
 * ⚠ **全体を 1 つのトランザクションで囲む。** 平文は戻り値にしか存在しないので、途中で落ちると
 *   「もう変わっているのに誰も新しいパスワードを知らない人」が残り、その人はログインできず、
 *   管理者は誰が該当するかも分からない（実測で再現した）。まとめて再発行は 1 回 50 人で
 *   本番の bcrypt は 0.305 秒/件 ＝ 約 15 秒（`config/approval.php`）なので、
 *   実行時間切れで途中終了する現実味がある。
 *   通知メールの `jobs` 行と記録も同じ DB なので一緒に巻き戻り、
 *   「起きなかった再発行の通知だけが届く」ことも防げる。
 *   CSV の一括登録（Task 13）も同じ理由で囲う。
 *
 * ⚠ 通知が一緒に巻き戻るのは **`QUEUE_CONNECTION=database` だから**（`config/queue.php` の
 *   `'connection' => env('DB_QUEUE_CONNECTION')` が未設定＝既定の DB 接続なので、
 *   `jobs` への挿入が同じトランザクションに乗る）。redis や sqs に替えると
 *   **「起きなかった再発行の通知だけが届く」が無音で戻る**。
 *   ⚠ **テストでは測れない** — `Mail::fake()` がキューの挿入そのものを短絡するので、
 *   `jobs` 行が巻き戻ることを見ているテストは 1 本も無い（設定を読んで確かめるしかない）。
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

        // ⚠ 第 2 引数（再試行回数）を足すなら、下の 3 行の初期化を消さないこと。
        //    `ManagesTransactions::transaction()` は失敗すると**閉包を丸ごと呼び直す**のに、
        //    参照で束ねたこの 3 つは試行をまたいで残る（vendor で確認）。初期化しないと、
        //    巻き戻って**もう使えない**平文が案内の紙に混ざり、人数も積み増される
        //    ＝ このトランザクションで防いだはずの「誰も知らないパスワード」を別の道で作り直す。
        //    ⚠ `RefreshDatabase` の入れ子の下では再試行の道に一度も入らないので、
        //    **テストでは原理的に捕まえられない**（`handleTransactionException()` が
        //    `$this->transactions > 1` のとき再試行せず投げる）。
        DB::transaction(function () use ($users, $actorName, $loginUrl, $now, &$entries, &$notified, &$skipped) {
            $entries  = [];
            $notified = 0;
            $skipped  = 0;

            foreach ($users as $user) {
                $password = InitialPassword::generate();

                // 強度 10 のハッシュを入れる手順は User::setInitialPassword() に 1 本化してある
                // （hashed キャストとの衝突の理由はそちらの docblock）
                $user->setInitialPassword($password);
                $user->save();

                $entries[] = ['user' => $user, 'password' => $password];

                SettingLogger::record('user.password_reissued', 'user', $user->id);

                // ⚠ 1 人につき 1 回問い合わせる（上限 50 人 ＝ 50 回）。まとめて引く形にしていないのは、
                //    測ったうえでの判断 —— この回の主な時間は bcrypt で 0.305 秒/件 × 50 ＝ 約 15 秒に対し、
                //    索引の効いたこの問い合わせは合計でも数十ミリ秒。判定の道を 1 本に保つほうが値打ちがある
                //    （CSV の取込も同じ `allows()` を通る）。
                if (ApprovalMailDomain::allows($user->email)) {
                    Mail::to($user->email)->queue(new PasswordReissuedMail($user, $actorName, $now, $loginUrl));
                    $notified++;
                } else {
                    $skipped++;
                }
            }
        });

        return new ReissueResult($entries, $notified, $skipped);
    }
}
