<?php

namespace App\Models;

use App\Enums\UnitStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'property_id',
        'floor',
        'room_number',
        'display_name',
        'area_tsubo',
        'usage_type_id',
        'status',
        'rent',
        'common_fee',
        'deposit',
        'garbage_fee',
        'pest_control_fee',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'floor' => 'integer',
            'area_tsubo' => 'decimal:2',
            'status' => UnitStatus::class,
            'rent' => 'integer',
            'common_fee' => 'integer',
            'deposit' => 'integer',
            'garbage_fee' => 'integer',
            'pest_control_fee' => 'integer',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    /**
     * 用途（用途マスター）
     */
    public function usageType(): BelongsTo
    {
        return $this->belongsTo(InquiryUsageType::class, 'usage_type_id');
    }

    /**
     * 所属物件
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * この区画の契約一覧
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * この区画の現在有効な契約（契約中のもの）
     */
    public function activeContract(): HasOne
    {
        return $this->hasOne(Contract::class)->where('status', 'active');
    }

    /**
     * この区画の投資案件
     */
    public function investments(): HasMany
    {
        return $this->hasMany(Investment::class);
    }

    /**
     * この区画の一般修繕
     */
    public function repairs(): HasMany
    {
        return $this->hasMany(Repair::class);
    }

    // ============================================================
    // アクセサ / ヘルパー
    // ============================================================

    /**
     * display_nameを自動生成する（階数 + 号室名）
     * 例: 階数3, 号室A → 「3A」 / 階数-1, 号室A → 「B1A」（地下1階）/ 階数null, 号室A → 「A」
     */
    public static function generateDisplayName(?int $floor, string $roomNumber): string
    {
        if ($floor !== null) {
            if ($floor < 0) {
                return 'B' . abs($floor) . $roomNumber;
            }
            return $floor . $roomNumber;
        }
        return $roomNumber;
    }

    /**
     * 募集条件の月額合計（家賃 + 共益費 + ゴミ代 + 駆除代）
     * ※敷金は月額ではないため含めない
     */
    public function getMonthlyTotalAttribute(): int
    {
        return ($this->rent ?? 0)
             + ($this->common_fee ?? 0)
             + ($this->garbage_fee ?? 0)
             + ($this->pest_control_fee ?? 0);
    }

    /**
     * 入居中かどうか
     */
    public function isOccupied(): bool
    {
        return $this->status === UnitStatus::Occupied;
    }

    /**
     * 空室かどうか
     */
    public function isVacant(): bool
    {
        return $this->status === UnitStatus::Vacant;
    }
}
