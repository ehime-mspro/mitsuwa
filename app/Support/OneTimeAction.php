<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 1 回だけ実行してよい操作の鍵（設計書 §5.12）。
 *
 * ログイン案内を出す POST（新規登録・再発行・CSV の確定）に鍵を入れておき、処理した鍵を覚えておく。
 * ブラウザの「フォームを再送信しますか」で続けてしまうと、**印刷済みの案内がこっそり無効になる**ため。
 *
 * ⚠ 鍵そのものをキャッシュのキーにしない（キャッシュの保管先に生の値が残る）。
 */
final class OneTimeAction
{
    public static function issue(): string
    {
        return Str::random(40);
    }

    /** 初めての鍵なら true。2 回目以降は false */
    public static function claim(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        return Cache::add(
            self::cacheKey($token),
            true,
            now()->addHours((int) config('approval.guide_token_ttl_hours'))
        );
    }

    public static function cacheKey(string $token): string
    {
        return 'once:' . hash('sha256', $token);
    }
}
