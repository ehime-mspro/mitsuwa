<?php

namespace App\Support;

use Illuminate\Http\Request;
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

    /**
     * 初めての鍵なら true。2 回目以降は false。
     *
     * ⚠ private。呼び出しは必ず `claimFrom()` を経由させる（配列トークンの防御を 1 か所に
     *   集めるため）。private にすることで、動的呼び出し（`call_user_func` 等）やクラスの
     *   エイリアス経由でも外から呼べなくなる——**言語仕様そのものが強制する**ので、走査テストより
     *   確実（Commit 1。走査テストは private化の前から使えたので静的な早期警告として残す）。
     */
    private static function claim(string $token): bool
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

    /**
     * リクエストの hidden `guide_token` を受け取って鍵を使う。**入口はここを通す。**
     * `claim()` は private なので、外から直接呼ぶコードはそもそも書けない
     * （動的呼び出し・エイリアス経由も含め、言語仕様で強制される。Commit 1 で private 化）。
     *
     * ⚠ `guide_token` は配列で送られることがある（`guide_token[]=x` のような**手組みの送信**。
     *   すべての画面が `name="guide_token"` の hidden を**単一の値でしか描画しない**ので、
     *   通常のブラウザ操作では配列にならない——実測 5 箇所とも `name="guide_token"` の単数形）。
     *   配列を `claim(string $token)` にそのまま渡すと型宣言と合わず `TypeError` になる。
     *   `(string) $token` へキャストしてから渡そうとしても、配列のキャストが起こす PHP の
     *   `Array to string conversion` 警告は、Laravel の `HandleExceptions`
     *   （`vendor/laravel/framework/src/Illuminate/Foundation/Bootstrap/HandleExceptions.php`
     *   の `handleError()`）が**その場で** `ErrorException`（500）に変えるため、"Array" という
     *   文字列が実際に作られて `claim()` まで届くことはない（キャストの時点で例外になる。実測）。
     *   ここで `is_string()` を通してから渡すことで、どちらの経路も静かに
     *   「鍵が違う（false）」として扱う。
     */
    public static function claimFrom(Request $request): bool
    {
        $token = $request->input('guide_token');

        return is_string($token) && self::claim($token);
    }
}
