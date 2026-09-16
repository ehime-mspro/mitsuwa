<?php

namespace App\Support;

/**
 * ログイン ID（社員番号またはメールアドレス）の正規化（設計書 §5.3・D5）。
 *
 * ログイン・試行の制限の鍵・利用者の保存・CSV の取込が**すべてここを通る**。
 * 別々に正規化すると、保存した値と照合する値が食い違って「正しいのに入れない」が起きる。
 *
 * ⚠ 手順の順番に意味がある: 全角→半角 → 前後の空白を落とす → `@` で振り分け → 大文字小文字。
 *   先に `@` を見ると、全角の `＠` を取りこぼす（実測: `mb_convert_kana($v, 'as')` が `＠` を `@` にする）。
 */
final class LoginId
{
    /** 社員番号として認める形（D5。`-` は文字クラスの末尾に置いて範囲にしない） */
    public const EMPLOYEE_NUMBER_PATTERN = '/\A[A-Z0-9-]{1,20}\z/';

    /**
     * ⚠ 引数は `mixed`。**検証の前に呼ばれる経路がある**ので、配列や数値がそのまま届く:
     *   試行の制限の鍵（`AppServiceProvider` のリミッタは `validate()` より前に走る）と、
     *   フォームの正規化（`$request->merge()` で検証の前に整える）。
     *   `?string` で受けると `login_id[]=a&login_id[]=b` を送るだけで TypeError の 500 になり、
     *   しかもその 500 は**どちらの上限にも数えられない**ので無制限に叩ける（実測で再現）。
     *   文字列にできない値は空として扱い、形式の誤りは呼び出し側の `validate()` に任せる。
     */
    public static function normalize(mixed $value): string
    {
        if (! is_scalar($value) && $value !== null) {
            return '';
        }

        // 'a' = 全角の英数字と記号を半角へ / 's' = 全角の空白を半角へ
        $value = trim(mb_convert_kana((string) $value, 'as'));

        return self::isEmail($value)
            ? mb_strtolower($value, 'UTF-8')
            : mb_strtoupper($value, 'UTF-8');
    }

    /**
     * `@` を含むならメールアドレスとして扱う。
     *
     * ⚠ 形式が正しいかは見ない。見てしまうと「メールとしては壊れている」と画面に出せてしまい、
     *   どちらの種類の ID を入れたかが攻撃者に分かる（設計書 §5.3 の「形式の違いを言わない」）。
     */
    public static function isEmail(string $value): bool
    {
        return str_contains($value, '@');
    }

    /**
     * その値を引く列。
     *
     * ⚠ 中でもう一度 normalize() を通す（normalize() は冪等）。呼び出し側が正規化を
     *   忘れて生の値を渡すと、全角の `＠` を含む文字列が `employee_number` に化けて
     *   「正しいのにログインできない」になる。呼び出し側の規律に頼らない。
     */
    public static function column(mixed $value): string
    {
        return self::isEmail(self::normalize($value)) ? 'email' : 'employee_number';
    }

    /** ログイン試行を数える鍵（設計書 §5.4）。正規化してから組むので綴りの違いで回避できない */
    public static function throttleKey(mixed $loginId, string $ip): string
    {
        return self::normalize($loginId) . '|' . $ip;
    }
}
