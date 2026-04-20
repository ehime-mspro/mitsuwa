<?php

namespace App\Models;

use App\Enums\MsTenantType;
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
