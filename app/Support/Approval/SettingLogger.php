<?php

namespace App\Support\Approval;

use App\Models\ApprovalSettingLog;

/**
 * 設定の変更を 1 行記録する（設計書 §5.14）。
 *
 * 呼び出し側が「誰が・IP・端末」を毎回書かなくて済むように 1 本化する。
 *
 * ⚠ **パスワードを渡さない。** 再発行を記録するときは `['password' => '…']` のような
 *   値を入れず、`user.password_reissued` という action だけを残す。
 */
final class SettingLogger
{
    /** 変更の前後をそのまま記録する */
    public static function record(string $action, string $targetType, ?int $targetId, array $old = [], array $new = []): ApprovalSettingLog
    {
        return ApprovalSettingLog::create([
            'actor_user_id' => auth()->id(),
            'action'        => $action,
            'target_type'   => $targetType,
            'target_id'     => $targetId,
            'old_values'    => $old,
            'new_values'    => $new,
            'ip_address'    => request()->ip(),
            'user_agent'    => mb_substr((string) request()->userAgent(), 0, 255),
        ]);
    }

    /**
     * 変わった項目だけを記録する。何も変わっていなければ何も足さない。
     *
     * ⚠ 全項目を積むと、あとで差分が読めない（記録の意味が薄れる）。
     */
    public static function recordChange(string $action, string $targetType, ?int $targetId, array $old, array $new): ?ApprovalSettingLog
    {
        $changedOld = [];
        $changedNew = [];

        foreach ($new as $key => $value) {
            if (! array_key_exists($key, $old) || ! self::isSame($old[$key], $value)) {
                $changedOld[$key] = $old[$key] ?? null;
                $changedNew[$key] = $value;
            }
        }

        if ($changedNew === []) {
            return null;
        }

        return self::record($action, $targetType, $targetId, $changedOld, $changedNew);
    }

    /**
     * 同じ値か。
     *
     * ⚠ 素の `!==` だと、DB から来た `'5'`（文字列）と画面から来た `5`（整数）が
     *   **常に「変わった」**になり、変わっていないのに記録が増える（実測）。
     *   数と文字は文字列にそろえて比べる。
     * ⚠ `null` と `''` は**別物**として扱う（消したのか、もともと無いのかを取り違えない）。
     *   真偽値も数・文字とは混ぜない（`true` と `'1'` を同じにしない）。
     */
    private static function isSame(mixed $a, mixed $b): bool
    {
        $numeric = static fn (mixed $v): bool => is_int($v) || is_float($v) || is_string($v);

        if ($numeric($a) && $numeric($b)) {
            return (string) $a === (string) $b;
        }

        return $a === $b;
    }
}
