<?php

namespace App\Models;

use App\Enums\MsTenantType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MsTenant extends Model
{
    protected $fillable = [
        'tenant_type', 'name', 'phone', 'email', 'workplace',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'tenant_type' => MsTenantType::class,
        ];
    }

    /**
     * 部屋契約の入居者に選べる人（登録・編集画面の選択肢と、保存の入力チェックが同じ集合を使う。docs/RULES.md Bug #151）。
     *
     * 区分が「入居者」の人と、今の入居者を名前順。今の入居者は区分があとで「駐車場利用のみ」に変わっていても残す
     * （今の値のまま保存できるように＝Bug #130 の形を作らない）。新しく選べるのは「入居者」区分の人だけ。
     */
    public static function selectableForRoomContract(?int $currentTenantId = null): Collection
    {
        return static::query()
            ->where(function ($query) use ($currentTenantId) {
                $query->where('tenant_type', MsTenantType::Resident->value);
                if ($currentTenantId !== null) {
                    $query->orWhere('id', $currentTenantId);
                }
            })
            ->orderBy('name')
            ->get();
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(MsContract::class, 'tenant_id');
    }

    public function parkingContracts(): HasMany
    {
        return $this->hasMany(MsParkingContract::class, 'tenant_id');
    }

    public function activeContract()
    {
        return $this->hasOne(MsContract::class, 'tenant_id')->where('status', 'active');
    }

    public function activeParkingContracts()
    {
        return $this->hasMany(MsParkingContract::class, 'tenant_id')->where('status', 'active');
    }

    /**
     * 入居申込書などの添付ファイル（ポリモーフィック）。
     * 現在有効なものと削除済み（ソフトデリート）を個別に取得できるよう、
     * withTrashed() ではなく attachments() / deletedAttachments() を提供する。
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')
            ->orderByDesc('created_at');
    }

    /**
     * 削除済み添付ファイル（削除履歴表示用）。
     */
    public function deletedAttachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')
            ->onlyTrashed()
            ->orderByDesc('deleted_at');
    }
}
