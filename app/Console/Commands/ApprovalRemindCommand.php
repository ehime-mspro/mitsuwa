<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Mail\ApprovalMailable;
use App\Mail\ApprovalReminderMail;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalReminderRun;
use App\Models\ApprovalSetting;
use App\Models\User;
use App\Support\Approval\PendingWork;
use App\Support\Approval\ReminderCalendar;
use App\Support\JapanTime;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * 朝の催促のまとめメール（段階3 設計書 §5.8・要件 8.3）。定期実行が平日の朝 9:00〜9:04 の起動で呼ぶ（routes/console.php）。
 *
 * 順に確かめ、当たれば何もしないで終わる: 使い始める前 → 送らない日（土日・祝日・⑫で登録した日。ReminderCalendar）→
 * 今日の分をもう送った（approval_reminder_runs に今日の行を入れられない）。
 *
 * ⚠ 今日の行を入れることと、まとめメールを積むことは 1 つのトランザクション（途中で止まれば両方残らない。キューが database
 *   なので jobs の行も一緒に巻き戻る。Notifier と同じ前提）。同じ日に 2 回目が動いても、一意の日付で入れられずに止まる
 * ⚠ 載せるのはホームの対応待ち（PendingWork）と同じもののうち、待ち日数が 3 以上のもの（D5）。宛先は有効でメールを送れる人だけ
 *   （催促はメールだけで、お知らせは作らない。要件 8.1）
 * ⚠ リンクは設定 approval.mail_link_root（本番は APP_URL に /index.php を足したもの）から作る（画面の操作が無いため。D20）
 */
class ApprovalRemindCommand extends Command
{
    /** 催促に載せる待ち日数（ホームの「3 日待ち」以上。D5） */
    public const MIN_DAYS = 3;

    /** 1 通に載せる件数（D18） */
    public const MAX_ITEMS = 20;

    protected $signature = 'approvals:remind';

    protected $description = '決裁の催促のまとめメールを送信待ちに入れる（平日の朝 9 時の定期実行。使い始める前・送らない日・今日の分を送ったあとは何もしない）';

    public function handle(): int
    {
        $today = JapanTime::today();

        // 定期実行は画面の出力を storage/logs/approval-reminder.log に足す（本番の laravel.log は error だけ残るため）
        $this->line($today->format('Y-m-d') . ' ' . $this->remind($today));

        return self::SUCCESS;
    }

    private function remind(Carbon $today): string
    {
        if (! ApprovalSetting::launchedForMenu()) {
            return '使い始める前なので送りません。';
        }

        $reason = (new ReminderCalendar())->reasonNotToSend($today);
        if ($reason !== null) {
            return "送らない日なので送りません（{$reason}）。";
        }

        $mails = $this->mails();
        $items = array_sum(array_map(fn (ApprovalReminderMail $mail) => $mail->total, $mails));

        $sent = DB::transaction(function () use ($today, $mails, $items): bool {
            try {
                ApprovalReminderRun::create(['sent_on' => $today->format('Y-m-d'), 'recipient_count' => count($mails), 'item_count' => $items]);
            } catch (UniqueConstraintViolationException) {
                return false;
            }

            foreach ($mails as $email => $mail) {
                Mail::to($email)->queue($mail);
            }

            return true;
        });

        if (! $sent) {
            return '今日の分はもう送っています。';
        }

        // 送る相手がいない日も記録は残す（⑫ の「前回の催促」と同じ言葉。利用者の決定 2026-10-02）
        return $mails === [] ? '送る相手はいませんでした。' : '催促を送りました（' . count($mails) . " 人・{$items} 件）。";
    }

    /** @return array<string, ApprovalReminderMail> メールアドレス => まとめメール（宛先は有効で、メールを送れる人だけ） */
    private function mails(): array
    {
        $pending = PendingWork::everyone()
            ->map(fn (Collection $items) => $items->filter(fn (array $item) => PendingWork::waitingDays($item['since']) >= self::MIN_DAYS)->values())
            ->filter(fn (Collection $items) => $items->isNotEmpty());

        if ($pending->isEmpty()) {
            return [];
        }

        $mails = [];

        // 削除した人は SoftDeletes で入らない
        foreach (User::whereKey($pending->keys())->where('status', UserStatus::Active->value)->orderBy('id')->get() as $user) {
            if (ApprovalMailDomain::allows($user->email)) {
                $mails[$user->email] = $this->digest($user, $pending[$user->id]);
            }
        }

        return $mails;
    }

    private function digest(User $user, Collection $items): ApprovalReminderMail
    {
        $listed = $items->take(self::MAX_ITEMS)->map(fn (array $item) => [
            'subject'    => ApprovalMailable::oneLine($item['request']->subject),
            'applicant'  => ApprovalMailable::oneLine($item['request']->applicant?->name),
            'department' => ApprovalMailable::oneLine($item['request']->department?->name),
            'task'       => "{$item['role']}・{$item['action']}",
            'days'       => PendingWork::waitingDays($item['since']),
            'url'        => self::link('approvals.requests.show', $item['request']),
        ])->all();

        return new ApprovalReminderMail($user->name, $items->count(), $listed, self::link('approvals.home'));
    }

    /**
     * リンクは設定の元（approval.mail_link_root）に、ルートの道（/approvals/…）をつないで作る（D20）。
     *
     * ⚠ 定期実行の中の route() は APP_URL を元にし、本番で /index.php が抜ける。URL::forceRootUrl() で元を差し替える形は
     *   通信の種類（https）をリクエストのものに置き換えるので使わない（試作で https が http に化けた）。
     *   route() の第 3 引数 false は、ホストとリクエストの元の道（/system/manage など）を除いた道を返す（2026-10-02 に実測）
     */
    private static function link(string $name, mixed $parameters = []): string
    {
        return rtrim((string) config('approval.mail_link_root'), '/') . route($name, $parameters, false);
    }
}
