<?php

namespace Tests\Concerns;

use App\Mail\ApprovalNoticeMail;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalNotice;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * 段階3 のお知らせとメールを読むテストの土台（3a 計画 §0.12）。BuildsApprovalFixtures と一緒に使う。
 *
 * ⚠ 通知メールは許可したドメインの人にだけ届く（要件 8.2）。ファクトリのメールアドレスは example.* なので、
 *   届く人にするには mailable() で mitsuwat.co.jp のアドレスを付ける。
 */
trait ReadsApprovalNotices
{
    /** 許可したドメイン（mitsuwat.co.jp）のメールアドレスを付ける（通知メールが届く人にする） */
    protected function mailable(User $user, string $local): User
    {
        ApprovalMailDomain::firstOrCreate(['domain' => 'mitsuwat.co.jp']);
        $user->update(['email' => "{$local}@mitsuwat.co.jp"]);

        return $user->fresh();
    }

    /** その人のお知らせ（作った順） @return Collection<int, ApprovalNotice> */
    protected function noticesOf(User $user): Collection
    {
        return ApprovalNotice::ownedBy($user)->orderBy('created_at')->orderBy('id')->get();
    }

    /** その人のお知らせの見出し（作った順） @return list<string> */
    protected function headlinesOf(User $user): array
    {
        return $this->noticesOf($user)->map(fn (ApprovalNotice $notice) => $notice->data['headline'])->all();
    }

    /** その宛先に積んだ知らせのメールの件名（積んだ順・Mail::fake() のあと） @return list<string> */
    protected function mailSubjectsTo(string $address): array
    {
        return Mail::queued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $mail) => $mail->hasTo($address))
            ->map(fn (ApprovalNoticeMail $mail) => $mail->envelope()->subject)
            ->values()
            ->all();
    }
}
