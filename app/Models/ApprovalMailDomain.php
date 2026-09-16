<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 通知メールを送ってよいドメイン（設計書 §5.8）。
 *
 * ⚠ **完全一致**で判定する（サブドメインは別に登録する）。使い道は CSV のメールアドレスの検査と、
 *   通知メールの宛先の制限（要件 8.2）の 2 つ。
 */
class ApprovalMailDomain extends Model
{
    protected $fillable = ['domain'];

    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            $row->domain = self::normalize($row->domain);
        });
    }

    /** 前後の空白を落とし、`@` が付いていたら外して小文字にする */
    public static function normalize(?string $value): string
    {
        $value = trim(mb_convert_kana((string) $value, 'as'));
        $value = ltrim($value, '@');

        return mb_strtolower($value, 'UTF-8');
    }

    /** そのメールアドレスが許可されたドメインか（1 件も登録されていなければ false） */
    public static function allows(?string $email): bool
    {
        if ($email === null || ! str_contains($email, '@')) {
            return false;
        }

        $domain = mb_strtolower(substr($email, strrpos($email, '@') + 1), 'UTF-8');

        return static::where('domain', $domain)->exists();
    }
}
