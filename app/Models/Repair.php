<?php

namespace App\Models;

use App\Enums\RepairStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Repair extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'property_id',
        'unit_id',
        'status',
        'category',
        'description',
        'contractor_name',
        'started_at',
        'completed_at',
        'cost',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'       => RepairStatus::class,
            'started_at'   => 'date',
            'completed_at' => 'date',
            'cost'         => 'integer',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    // ============================================================
    // ヘルパー
    // ============================================================

    /**
     * カテゴリの日本語ラベルを返す
     */
    public function getCategoryLabelAttribute(): string
    {
        $labels = [
            'aircon'     => 'エアコン',
            'plumbing'   => '給排水',
            'electrical' => '電気',
            'exterior'   => '外壁・屋根',
            'interior'   => '内装',
            'other'      => 'その他',
        ];

        return $labels[$this->category] ?? $this->category ?? '—';
    }

    /**
     * 区画名または「共用部」を返す
     */
    public function getUnitLabelAttribute(): string
    {
        if (! $this->unit_id || ! $this->unit) {
            return '共用部';
        }

        return $this->unit->display_name;
    }
}
