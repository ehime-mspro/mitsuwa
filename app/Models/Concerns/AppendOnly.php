<?php

namespace App\Models\Concerns;

use RuntimeException;

/**
 * 追記のみの記録（要件 14.2「記録は後から変更も削除もできない」）。
 *
 * 段階1 の `ApprovalSettingLog` と同じ守りを、段階2 の記録（操作の記録・提出ごとの控え・
 * ダウンロードの記録）に使う。⚠ `ApprovalSettingLog` は今のまま（この trait に寄せない。範囲外）。
 * ⚠ 使うモデルは `const UPDATED_AT = null;` も書く（更新日時の列を持たない）。
 */
trait AppendOnly
{
    protected static function bootAppendOnly(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('この記録は書き換えられません。');
        });

        static::deleting(function (): void {
            throw new RuntimeException('この記録は削除できません。');
        });
    }
}
