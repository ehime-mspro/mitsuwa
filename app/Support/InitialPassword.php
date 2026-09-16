<?php

namespace App\Support;

use Illuminate\Support\Facades\Hash;

/**
 * 初期パスワードの生成と暗号化（設計書 §5.11・D13）。
 *
 * 今ここを通るのは**再発行だけ**（`App\Support\Approval\PasswordReissuer`）。
 *
 * ⚠ **基幹の利用者管理（`App\Http\Controllers\Admin\UserController`）はまだ移行していない。**
 *   新規登録は画面の JS（`Math.random` ＋ `sort(() => Math.random() - 0.5)` の偏るシャッフル）が
 *   作った値をそのまま受け取り、再発行は `generatePassword()`（`str_shuffle` ＝ 暗号用の乱数でない）で
 *   作って**平文をセッションのフラッシュ（`reset_password`）に入れている** —— 設計書 D12 が
 *   名指しして禁じている形で、sessions テーブルに平文が残る。**Task 10 でここへ寄せる。**
 *   CSV の取込（Task 13）も同じ。
 *
 * ⚠ 生成した平文は画面に 1 度出すだけ（ログイン案内）。DB・セッション・ログ・記録に残さない。
 */
final class InitialPassword
{
    public const LENGTH = 10;

    /**
     * 暗号化の強度。
     *
     * ⚠ アプリの既定（12）より低い。100〜200 人ぶんをまとめて作るため（強度 12 は手元で 1 件 0.240 秒）。
     *   初回ログインで Laravel が今の強度へ自動で掛け直す（`rehash_on_login` が既定 true）し、
     *   そもそも初回にパスワードの変更を求めるので、無作為な 10 文字を一時的に守るには十分。
     */
    public const HASH_ROUNDS = 10;

    /** 見間違えやすい o / l / i を除いた 23 文字 */
    private const LETTERS = 'abcdefghjkmnpqrstuvwxyz';

    /** 見間違えやすい 0 / 1 を除いた 8 文字 */
    private const DIGITS = '23456789';

    public static function generate(): string
    {
        $pool = self::LETTERS . self::DIGITS;

        // 英字と数字を必ず 1 文字ずつ入れる（本人が決めるパスワードの規則と同じ条件を満たすため）
        $chars = [
            self::pick(self::LETTERS),
            self::pick(self::DIGITS),
        ];

        for ($i = count($chars); $i < self::LENGTH; $i++) {
            $chars[] = self::pick($pool);
        }

        // ⚠ str_shuffle() は暗号用でない乱数を使うので使わない
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /** 強度 10 で暗号化する（`Hash::make()` の既定に任せない） */
    public static function hash(string $plain): string
    {
        return Hash::make($plain, ['rounds' => self::HASH_ROUNDS]);
    }

    private static function pick(string $pool): string
    {
        return $pool[random_int(0, strlen($pool) - 1)];
    }
}
