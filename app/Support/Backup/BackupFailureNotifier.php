<?php

namespace App\Support\Backup;

use App\Mail\BackupFailedMail;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * バックアップ失敗の通知(宛先の検証・組み立てと送信)。
 *
 * BACKUP_NOTIFY_TO の設定ミス(空・区切りの誤り・1 件だけの誤記など)で
 * 唯一の失敗通知が無言で消えないよう、宛先の検証と送信をここへ分けている。
 * ops:backup(手動実行)と定期実行の失敗フックの両方から使う。
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
     * 区切りはカンマ・セミコロン・読点・全角カンマ・全角セミコロン・空白(全角スペース含む)。
     * 有効な宛先(filter_var の FILTER_VALIDATE_EMAIL を通るもの)は重複を除き、最初に現れた順番を保つ。
     *
     * @return array{0: list<string>, 1: list<string>} [有効な宛先, 無効な宛先]
     */
    public static function recipients(?string $configured): array
    {
        $tokens = preg_split('/[\s,;、，；]+/u', (string) $configured, flags: PREG_SPLIT_NO_EMPTY);

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
     * 有効な宛先にだけ失敗の知らせをすぐ送る(キューに積まない。BackupFailedMail は ShouldQueue を実装していないため send() で即時送信になる)。
     *
     * @return list<string> 表示・記録用の警告(problems() の内容に加えて、送信で例外が出たときのメッセージ)
     */
    public function send(string $reason, CarbonInterface $failedAt): array
    {
        $problems = $this->problems();
        $warnings = $problems;
        $sendError = null;

        if ($this->valid !== []) {
            try {
                // キューの不調が原因の失敗でも届くよう、キューに積まずにすぐ送る
                Mail::to($this->valid)->send(new BackupFailedMail($reason, $failedAt));
            } catch (Throwable $e) {
                $sendError = $e;
                $warnings[] = 'バックアップ失敗の通知メールを送れませんでした: '.$e->getMessage();
            }
        }

        // ログの書き込みが壊れていても先にメールは出ているように、送信を試みた後にログへ書く
        foreach ($problems as $problem) {
            Log::warning($problem);
        }
        if ($sendError !== null) {
            Log::error('バックアップ失敗の通知メールを送れませんでした', ['exception' => $sendError]);
        }

        return $warnings;
    }
}
