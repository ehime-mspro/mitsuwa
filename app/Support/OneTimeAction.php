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
 *
 * ⚠ **守るのは「同じ鍵の 2 回目」だけ。** `issue()` は発行を記録しないので、毎回あたらしい 40 文字を
 *   送れば何度でも通る。ブラウザの再送信（同じ本文をそのまま送り直す）にはこれで足りるが、
 *   **回数の制限や権限の代わりにはならない**（誰が何回まで実行してよいかは呼び出し側の門番が決める）。
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

        // ⚠ `max(1, …)` が要る。`Repository::add()` は秒数が 0 以下だと**キーの存在も見ずに**
        //    false を返すので、`.env` の書き間違い（`APPROVAL_GUIDE_TOKEN_TTL_HOURS=` や `=0`）で
        //    **新規登録も再発行も 1 回目から無言で止まる**（実測）。クラッシュより気づきにくい。
        $hours = max(1, (int) config('approval.guide_token_ttl_hours'));

        // ⚠ `Cache::add` は「無ければ入れる」を**一度に**行う（本番の database ドライバは
        //    key の主キー制約で本当に排他的）。`has()` してから `put()` に書き換えると、
        //    二重送信が両方通る余地ができる。下の構造テストがこれを守る。
        return Cache::add(self::cacheKey($token), true, now()->addHours($hours));
    }

    public static function cacheKey(string $token): string
    {
        return 'once:' . hash('sha256', $token);
    }
}
