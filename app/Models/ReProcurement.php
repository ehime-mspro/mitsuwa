<?php

namespace App\Models;

use App\Enums\ProcurementStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ReProcurement extends Model
{
    use HasFactory;

    protected $table = 're_procurements';

    protected $fillable = [
        'procurement_code',
        'property_type',
        'transaction_type',
        'status',
        'property_name',
        'postal_code',
        'address',
        'land_area_sqm',
        'building_area_sqm',
        'structure',
        'built_year_month',
        'zoning',
        'building_coverage',
        'floor_area_ratio',
        'supplier_id',
        'info_obtained_date',
        'assessment_price',
        'purchase_price',
        'target_selling_price',
        'contract_date',
        'settlement_date',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'property_type'       => RealEstatePropertyType::class,
            'transaction_type'    => RealEstateTransactionType::class,
            'status'              => ProcurementStatus::class,
            'land_area_sqm'       => 'decimal:2',
            'building_area_sqm'   => 'decimal:2',
            'building_coverage'   => 'integer',
            'floor_area_ratio'    => 'integer',
            'assessment_price'    => 'integer',
            'purchase_price'      => 'integer',
            'target_selling_price'=> 'integer',
            'info_obtained_date'  => 'date',
            'contract_date'       => 'date',
            'settlement_date'     => 'date',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(ReSupplier::class, 'supplier_id')->withTrashed();
    }

    public function costs(): HasMany
    {
        return $this->hasMany(ReProcurementCost::class, 'procurement_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * 添付ファイル（ポリモーフィック）
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    // ============================================================
    // ヘルパー
    // ============================================================

    /**
     * 原価合計（採用額: 確定額優先、なければ見込み額）
     */
    public function getEffectiveCostTotal(): int
    {
        $total = 0;
        foreach ($this->costs as $cost) {
            $total += $cost->actual_amount ?? $cost->estimated_amount;
        }
        return $total;
    }

    /**
     * 見込み額合計
     */
    public function getEstimatedCostTotal(): int
    {
        return (int) $this->costs->sum('estimated_amount');
    }

    /**
     * 確定額合計
     */
    public function getActualCostTotal(): int
    {
        return (int) $this->costs->whereNotNull('actual_amount')->sum('actual_amount');
    }

    /**
     * 粗利見込み（想定販売価格 − 原価合計採用額）
     */
    public function getExpectedProfit(): ?int
    {
        if ($this->target_selling_price === null) {
            return null;
        }
        $costTotal = $this->getEffectiveCostTotal();
        if ($costTotal === 0 && $this->costs->isEmpty()) {
            return null;
        }
        return $this->target_selling_price - $costTotal;
    }

    /**
     * 土地面積を坪数に変換（1坪 = 3.30579㎡）
     */
    public function getLandAreaTsubo(): ?float
    {
        if ($this->land_area_sqm === null) {
            return null;
        }
        return round((float) $this->land_area_sqm / 3.30579, 2);
    }

    /**
     * 築年月を表示用にフォーマット（例: 1998年3月）
     */
    public function getBuiltYearMonthFormatted(): ?string
    {
        if (empty($this->built_year_month)) {
            return null;
        }
        $parts = explode('-', $this->built_year_month);
        if (count($parts) !== 2) {
            return $this->built_year_month;
        }
        return (int) $parts[0] . '年' . (int) $parts[1] . '月';
    }
}
