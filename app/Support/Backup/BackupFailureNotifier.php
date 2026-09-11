<?php

namespace App\Support\Backup;

use App\Mail\BackupFailedMail;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * バックアップ失敗の通知（宛先の検証・組み立てと送信）。
 *
 * BACKUP_NOTIFY_TO の設定ミス（空・区切りの誤り・1 件だけの誤記など）で
 * 唯一の失敗通知が無言で消えないよう、宛先の検証と送信をここへ分けている。
 * ops:backup（手動実行）と定期実行の失敗フックの両方から使う。
 */
final class BackupFailureNotifier
{
    /** @var list<string> */
    private array $valid;

    /** @var list<string> */
    private array $invalid;

    public function __construct(?string $configured)
    {
        [$this->valid, $this->invalid] = self::recipients($configured);
    }

    /**
     * BACKUP_NOTIFY_TO の値を宛先へ分ける。
     *
     * 区切りはカンマ・セミコロン・読点・全角カンマ・全角セミコロン・空白（全角スペース含む）。
     * 不正な UTF-8 バイトは mb_scrub で置換文字に変えてから分割する
     * （そのまま preg_split(/u) に渡すと false が返り、呼び出し側で例外になる）。
     * 有効な宛先（filter_var の FILTER_VALIDATE_EMAIL を通るもの）は重複を除き、最初に現れた順番を保つ。
     *
     * @return array{0: list<string>, 1: list<string>} [有効な宛先, 無効な宛先]
     */
    public static function recipients(?string $configured): array
    {
        $scrubbed = mb_scrub((string) $configured, 'UTF-8');
        $tokens = preg_split('/[\s,;、，；]+/u', $scrubbed, flags: PREG_SPLIT_NO_EMPTY);

        $valid = [];
        $invalid = [];
        foreach ($tokens as $token) {
            if (filter_var($token, FILTER_VALIDATE_EMAIL) === false) {
                $invalid[] = $token;

                continue;
            }

            if (! in_array($token, $valid, true)) {
                $valid[] = $token;
            }
        }

        return [$valid, $invalid];
    }

    /**
     * 設定の問題点。順番は「形式の誤ったアドレスがある」→「有効な宛先が 0 件」。
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->invalid !== []) {
            $problems[] = 'BACKUP_NOTIFY_TO に形式の誤ったアドレスがあります: '.implode('、', $this->invalid);
        }

        if ($this->valid === []) {
            $problems[] = 'BACKUP_NOTIFY_TO に有効な宛先がありません。失敗しても誰にも知らせが届きません。';
        }

        return $problems;
    }

    /**
     * 有効な宛先へ、1 人ずつ失敗の知らせをすぐ送る（キューに積まない。BackupFailedMail は
     * ShouldQueue を実装していないため send() で即時送信になる）。
     *
     * 宛先ごとに Mailable を新しく作って 1 通ずつ送る。SMTP は宛先を RCPT TO で 1 件ずつ受け付け、
     * 1 件でも拒否されるとメール全体を送らない（退職者のメールボックスを消したのに
     * BACKUP_NOTIFY_TO を直し忘れる、などで起きる）ため、まとめて 1 通で複数人に送ると
     * その 1 件のせいで全員に届かなくなる。また、同じ Mailable のインスタンスを使い回すと
     * Mail::to() の呼び出しのたびに to が積み重なり、2 通目以降が複数人宛てになってしまう。
     *
     * @return list<string> 表示・記録用の警告（problems() の内容に加えて、宛先ごとの送信失敗のメッセージ）
     */
    public function send(string $reason, CarbonInterface $failedAt): array
    {
        $problems = $this->problems();
        $warnings = $problems;

        /** @var list<array{address: string, exception: Throwable}> $sendFailures */
        $sendFailures = [];

        foreach ($this->valid as $address) {
            try {
                Mail::to($address)->send(new BackupFailedMail($reason, $failedAt));
            } catch (Throwable $e) {
                $warnings[] = 'バックアップ失敗の通知メールを送れませんでした（'.$address.'）: '.$e->getMessage();
                $sendFailures[] = ['address' => $address, 'exception' => $e];
            }
        }

        try {
            // ログの書き込みが壊れていても、すでに確定した警告の一覧（$warnings）は返せるようにする。
            // ログはメールの送信をすべて試みた後にだけ書く
            foreach ($problems as $problem) {
                Log::warning($problem);
            }
            foreach ($sendFailures as $failure) {
                Log::error(
                    'バックアップ失敗の通知メールを送れませんでした（'.$failure['address'].'）',
                    ['exception' => $failure['exception']],
                );
            }
        } catch (Throwable) {
            // ログ基盤自体の不調で通知の成否（$warnings）まで失われないよう、ここで止める
        }

        return $warnings;
    }
}
