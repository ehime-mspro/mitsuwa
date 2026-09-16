<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 決裁の設定（設計書 §5.16）。**常に 1 行だけ**（id = 1）。
 *
 * 本番の SQL が最初の 1 行（社長は未設定）を入れる。テストでは `current()` が無ければ作る。
 */
class ApprovalSetting extends Model
{
    public const SINGLETON_ID = 1;

    public $timestamps = true;
    public const CREATED_AT = null;

    protected $fillable = ['president_user_id'];

    protected function casts(): array
    {
        return ['president_user_id' => 'integer'];
    }

    /**
     * 唯一の設定行。無ければ作る。
     *
     * ⚠ `firstOrCreate(['id' => 1])` は使わない。`id` は `$fillable` に無いので
     *   作成時に落ち、自動採番に任せることになる（空でない表では 1 にならない）。
     */
    public static function current(): self
    {
        return static::find(self::SINGLETON_ID) ?? tap(new self(), function (self $row): void {
            $row->id = self::SINGLETON_ID;
            $row->save();
        });
    }

    /** ⚠ 社長は決裁のみ利用者のことも基幹を使う人のこともある（要件 3.1） */
    public function president(): BelongsTo
    {
        return $this->belongsTo(User::class, 'president_user_id');
    }
}
