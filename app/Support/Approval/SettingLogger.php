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
            if (! array_key_exists($key, $old) || $old[$key] !== $value) {
                $changedOld[$key] = $old[$key] ?? null;
                $changedNew[$key] = $value;
            }
        }

        if ($changedNew === []) {
            return null;
        }

        return self::record($action, $targetType, $targetId, $changedOld, $changedNew);
    }
}
