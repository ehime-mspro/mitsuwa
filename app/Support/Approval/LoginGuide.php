<?php

namespace App\Support\Approval;

use App\Models\User;
use App\Support\LoginQrCode;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログイン案内（1 人 1 ページ・A4・ブラウザで印刷）の応答（設計書 §5.12・D4）。
 *
 * 基幹の新規登録・基幹の再発行・CSV の確定・決裁の管理者の再発行がすべてこれを返す。
 *
 * ⚠ **リダイレクトしない。** POST の応答としてその場で描く（初期パスワードをセッションに入れないため）。
 * ⚠ `Cache-Control: no-store` を付ける（戻るボタンや履歴からパスワードを読ませない）。
 * ⚠ QR の URL は**その場のリクエストから**作る（`route('login')`）。キューや設定に頼ると
 *   本番で `/index.php` が抜ける（要件 15.1）。
 */
final class LoginGuide implements Responsable
{
    /**
     * @param  list<array{user: User, password: string}>  $entries
     * @param  int  $notifiedCount  通知メールを送る人数
     * @param  int  $skippedCount   送らない人数（メールアドレスなし・許可していないドメイン）
     */
    public function __construct(
        private readonly array $entries,
        private readonly int $notifiedCount = 0,
        private readonly int $skippedCount = 0,
    ) {}

    public function toResponse($request): Response
    {
        $loginUrl = route('login');

        return response()
            ->view('approvals.login-guide', [
                'entries'       => $this->entries,
                'loginUrl'      => $loginUrl,
                'qr'            => LoginQrCode::symbolParts($loginUrl),
                'notifiedCount' => $this->notifiedCount,
                'skippedCount'  => $this->skippedCount,
                'issuedAt'      => now()->format('Y年n月j日'),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }
}
