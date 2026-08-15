<?php

namespace App\Models;

use App\Enums\AreaTenantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 周辺ビルの入居テナント（現況リスト）。
 * 退去は moved_out_on を入れて履歴として残す（行は消さない）。
 */
class AreaBuildingTenant extends Model
{
    protected $fillable = [
        'area_building_id',
        'floor',
        'room_number',
        'name',
        'industry',
        'status',
        'confirmed_on',
        'moved_out_on',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'       => AreaTenantStatus::class,
            'floor'        => 'integer',
            'confirmed_on' => 'date',
            'moved_out_on' => 'date',
        ];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(AreaBuilding::class, 'area_building_id')->withTrashed();
    }

    public function isActive(): bool
    {
        return $this->moved_out_on === null;
    }

    /** 地下は負数で持つ（B1 = -1） */
    public function floorLabel(): string
    {
        if ($this->floor === null) {
            return '—';
        }

        return $this->floor < 0 ? 'B' . abs($this->floor) . 'F' : $this->floor . 'F';
    }
}
