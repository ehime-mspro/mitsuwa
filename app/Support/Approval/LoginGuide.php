<?php

namespace App\Support\Approval;

use App\Models\User;
use App\Support\JapanTime;
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
     * @param  string  $backUrl  「元の画面へ戻る」の行き先（F2）。**入口ごとに決める**:
     *   フォームが GET の画面に載っている入口はリファラー（`url()->previous()`。絞り込み・ページ番号が残る）、
     *   確認画面（POST の応答）から送る CSV の確定は取込の画面。
     *   ⚠ ビューで `url()->previous()` を呼ばない。リファラーが優先されるので、CSV の確定では
     *   POST 専用の URL になり、押すと 405 だった（docs/RULES.md Bug #64）
     * @param  array{notified: int, skipped: int}|null  $mailCounts  通知メールを送る人数・送らない人数
     *   （メールアドレスなし・許可していないドメイン）。**再発行のときだけ**渡す（F7。新規登録と CSV の確定では
     *   通知メールを送らないので、帯にも出さない。設計書 §5.10・§5.13）。2 つを 1 つの配列にして、片方だけ渡す誤りを防ぐ
     */
    public function __construct(
        private readonly array $entries,
        private readonly string $backUrl,
        private readonly ?array $mailCounts = null,
    ) {}

    public function toResponse($request): Response
    {
        $loginUrl = route('login');

        return response()
            ->view('approvals.login-guide', [
                'entries'       => $this->entries,
                'backUrl'       => $this->backUrl,
                'loginUrl'      => $loginUrl,
                'qr'            => LoginQrCode::symbolParts($loginUrl),
                'mailCounts'    => $this->mailCounts,
                // 発行日は日本の今日（アプリの時刻は UTC。now() だと日本時間の 0:00〜8:59 に前日が出る。F5）
                'issuedAt'      => JapanTime::today()->format('Y年n月j日'),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }
}
