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

    /** リクエストの間だけ覚えておく入れ物（テストは 1 本ごとにコンテナが作り直されるので勝手に消える） */
    private const CONTAINER_KEY = 'approval.settings';

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
     *
     * ⚠ **リクエストの間は 1 回しか引かない。** `User::isApprovalPresident()` がこれを呼ぶので、
     *   覚えておかないと一覧で 1 人につき 1 回ずつ増える（20 人で 41 クエリになることを実測）。
     *   更新は `current()->update(…)` と同じインスタンスを通るので、覚えた値も一緒に新しくなる。
     *   ⚠ インスタンスを通さずに書き換えたら `forget()` を呼ぶこと。
     */
    public static function current(): self
    {
        if (app()->bound(self::CONTAINER_KEY)) {
            return app(self::CONTAINER_KEY);
        }

        $row = static::find(self::SINGLETON_ID) ?? tap(new self(), function (self $row): void {
            $row->id = self::SINGLETON_ID;
            $row->save();
        });

        app()->instance(self::CONTAINER_KEY, $row);

        return $row;
    }

    /** 覚えておいた設定を捨てる（インスタンスを通さずに書き換えたとき用） */
    public static function forget(): void
    {
        app()->forgetInstance(self::CONTAINER_KEY);
    }

    /**
     * ⚠ 社長は決裁のみ利用者のことも基幹を使う人のこともある（要件 3.1）。
     *
     * ⚠ **`withTrashed()` が要る。** 利用者は SoftDeletes なので、社長に指定された人を削除しても
     *   外部キーの `ON DELETE SET NULL` は**発火しない**（`deleted_at` を立てる UPDATE だから）。
     *   付けないと `president` が null になり、社長名を出す画面が
     *   `Attempt to read property "name" on null` で 500 になる（実測。Top trap #18）。
     */
    public function president(): BelongsTo
    {
        return $this->belongsTo(User::class, 'president_user_id')->withTrashed();
    }
}
