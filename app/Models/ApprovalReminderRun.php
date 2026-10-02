<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 催促を送った日の記録（1 日 1 行。段階3 設計書 §5.3・§5.8）。
 *
 * ⚠ `sent_on` は一意。同じ日に 2 回送らない守りはこの一意の日付（ApprovalRemindCommand が、この行を入れることと
 *   まとめメールを積むことを 1 つのトランザクションで行う）。⑫ の「前回の催促」にも使う。
 * ⚠ 書くのは催促のコマンドだけ（画面からは書かない）。更新日時の列は持たない。
 */
class ApprovalReminderRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['sent_on', 'recipient_count', 'item_count'];

    protected function casts(): array
    {
        return [
            'sent_on'         => 'date',
            'recipient_count' => 'integer',
            'item_count'      => 'integer',
        ];
    }

    /** いちばん新しい催促（無ければ null） */
    public static function latestRun(): ?self
    {
        return static::query()->orderByDesc('sent_on')->first();
    }
}
