<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Models\ReCostItem;
use App\Models\ReProjectCost;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ReProject extends Model
{
    use HasFactory;

    protected $table = 're_projects';

    protected $fillable = [
        'project_code',
        'project_name',
        'status',
        'postal_code',
        'address',
        'land_area_sqm',
        'zoning',
        'building_coverage',
        'floor_area_ratio',
        'latitude',
        'longitude',
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
            'status'              => ProjectStatus::class,
            'land_area_sqm'       => 'decimal:2',
            'building_coverage'   => 'integer',
            'floor_area_ratio'    => 'integer',
            'latitude'            => 'decimal:7',
            'longitude'           => 'decimal:7',
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
        return $this->hasMany(ReProjectCost::class, 'project_id');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(ReProjectLot::class, 'project_id')->orderBy('lot_number');
    }

    public function drawings(): HasMany
    {
        return $this->hasMany(ReProjectDrawing::class, 'project_id');
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
     * 成約区画数
     */
    public function getSoldLotCount(): int
    {
        return $this->lots->where('status', 'sold')->count();
    }

    /**
     * 全区画の販売価格合計
     */
    public function getLotSellingPriceTotal(): int
    {
        return (int) $this->lots->sum('selling_price');
    }

    /**
     * 全区画に販売価格が入力済みか
     */
    public function allLotsHaveSellingPrice(): bool
    {
        if ($this->lots->isEmpty()) {
            return false;
        }
        return $this->lots->every(function ($lot) {
            return $lot->selling_price !== null && $lot->selling_price > 0;
        });
    }

    // ============================================================
    // ライフサイクルフック
    // ============================================================

    protected static function booted(): void
    {
        static::saved(function (ReProject $project): void {
            // 査定価格・購入価格が変更されたとき、または新規作成時のみ同期
            if ($project->wasChanged(['assessment_price', 'purchase_price'])
                || $project->wasRecentlyCreated) {
                $project->syncPropertyPurchaseCost();
            }
        });
    }

    /**
     * 査定価格→見込み額、購入価格→確定額 を「物件購入費」原価行に自動反映
     * - 物件購入費 マスタが無ければ自動作成
     * - 既存の物件購入費 行があれば update、なければ create（重複は発生しない）
     * - 査定・購入の両方が空の場合は何もしない
     */
    public function syncPropertyPurchaseCost(): void
    {
        $assessment = $this->assessment_price !== null ? (int) $this->assessment_price : null;
        $purchase   = $this->purchase_price   !== null ? (int) $this->purchase_price   : null;

        if ($assessment === null && $purchase === null) {
            return;
        }

        $costItem = ReCostItem::firstOrCreate(
            ['name' => '物件購入費'],
            ['sort_order' => 0, 'is_active' => true],
        );

        ReProjectCost::updateOrCreate(
            [
                'project_id'   => $this->id,
                'cost_item_id' => $costItem->id,
            ],
            [
                'estimated_amount' => $assessment ?? 0,
                'actual_amount'    => $purchase,
            ],
        );
    }
}
