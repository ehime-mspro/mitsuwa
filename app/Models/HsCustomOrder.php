<?php

namespace App\Models;

use App\Enums\CustomOrderStatus;
use App\Enums\HousingLandSourceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HsCustomOrder extends Model
{
    use HasFactory;

    protected $table = 'hs_custom_orders';

    protected $fillable = [
        'order_code',
        'order_name',
        'status',
        'customer_id',
        'customer_name',
        'land_source_type',
        're_project_lot_id',
        're_procurement_id',
        'postal_code',
        'address',
        'land_area_sqm',
        'building_area_sqm',
        'structure',
        'floors',
        'building_contract_price',
        'building_cost',
        'land_selling_price',
        'land_cost',
        'is_land_cost_manual',
        'tax_rate',
        'contract_date',
        'scheduled_completion_date',
        'actual_completion_date',
        'delivery_date',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status'                    => CustomOrderStatus::class,
            'land_source_type'          => HousingLandSourceType::class,
            'land_area_sqm'             => 'decimal:2',
            'building_area_sqm'         => 'decimal:2',
            'building_contract_price'   => 'integer',
            'building_cost'             => 'integer',
            'land_selling_price'        => 'integer',
            'land_cost'                 => 'integer',
            'is_land_cost_manual'       => 'boolean',
            'tax_rate'                  => 'decimal:2',
            'contract_date'             => 'date',
            'scheduled_completion_date' => 'date',
            'actual_completion_date'    => 'date',
            'delivery_date'             => 'date',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    public function projectLot(): BelongsTo
    {
        return $this->belongsTo(ReProjectLot::class, 're_project_lot_id');
    }

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(ReProcurement::class, 're_procurement_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(HsCustomOrderFile::class, 'custom_order_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ============================================================
    // ヘルパー — 土地種別
    // ============================================================

    /**
     * 自社土地か（分譲地PJ区画 or 仕入れ案件）
     */
    public function isCompanyLand(): bool
    {
        return $this->land_source_type === HousingLandSourceType::ProjectLot
            || $this->land_source_type === HousingLandSourceType::Procurement;
    }

    /**
     * お客様所有土地か
     */
    public function isCustomerLand(): bool
    {
        return $this->land_source_type === HousingLandSourceType::CustomerLand;
    }

    // ============================================================
    // ヘルパー — ステータス
    // ============================================================

    /**
     * 契約情報が入力済みか（contract_date が設定済み）
     */
    public function hasContractInfo(): bool
    {
        return $this->contract_date !== null;
    }

    /**
     * ステータスが「契約」以降か
     */
    public function isContractedOrLater(): bool
    {
        return $this->status->isContractedOrLater();
    }

    /**
     * 現在のステータスインデックス（0-6、ステップバー用）
     */
    public function getStatusIndex(): int
    {
        return $this->status->stepIndex();
    }

    /**
     * 表示用バッジインラインスタイル
     */
    public function getDisplayBadgeStyle(): string
    {
        return $this->status->badgeStyle();
    }

    // ============================================================
    // ヘルパー — 金額計算
    // ============================================================

    /**
     * 建物消費税額
     */
    public function getBuildingTax(): int
    {
        if ($this->building_contract_price === null) {
            return 0;
        }
        return (int) round($this->building_contract_price * $this->tax_rate / 100);
    }

    /**
     * 建物粗利額
     */
    public function getBuildingProfit(): ?int
    {
        if ($this->building_contract_price === null || $this->building_cost === null) {
            return null;
        }
        return $this->building_contract_price - $this->building_cost;
    }

    /**
     * 建物粗利率
     */
    public function getBuildingProfitRate(): ?float
    {
        $profit = $this->getBuildingProfit();
        if ($profit === null || $this->building_contract_price === 0) {
            return null;
        }
        return round($profit / $this->building_contract_price * 100, 1);
    }

    /**
     * 土地粗利額（自社土地時のみ）
     */
    public function getLandProfit(): ?int
    {
        if (!$this->isCompanyLand()) {
            return null;
        }
        if ($this->land_selling_price === null || $this->land_cost === null) {
            return null;
        }
        return $this->land_selling_price - $this->land_cost;
    }

    /**
     * 土地粗利率（自社土地時のみ）
     */
    public function getLandProfitRate(): ?float
    {
        $profit = $this->getLandProfit();
        if ($profit === null || $this->land_selling_price === null || $this->land_selling_price === 0) {
            return null;
        }
        return round($profit / $this->land_selling_price * 100, 1);
    }

    /**
     * 販売価格合計（税抜）
     */
    public function getTotalSellingPrice(): ?int
    {
        if ($this->isCompanyLand()) {
            if ($this->land_selling_price === null && $this->building_contract_price === null) {
                return null;
            }
            return ($this->land_selling_price ?? 0) + ($this->building_contract_price ?? 0);
        }
        return $this->building_contract_price;
    }

    /**
     * 原価合計
     */
    public function getTotalCost(): ?int
    {
        if ($this->isCompanyLand()) {
            if ($this->land_cost === null && $this->building_cost === null) {
                return null;
            }
            return ($this->land_cost ?? 0) + ($this->building_cost ?? 0);
        }
        return $this->building_cost;
    }

    /**
     * 合計粗利額
     */
    public function getTotalProfit(): ?int
    {
        $selling = $this->getTotalSellingPrice();
        $cost = $this->getTotalCost();
        if ($selling === null || $cost === null) {
            return null;
        }
        return $selling - $cost;
    }

    /**
     * 合計粗利率
     */
    public function getTotalProfitRate(): ?float
    {
        $selling = $this->getTotalSellingPrice();
        $profit = $this->getTotalProfit();
        if ($selling === null || $profit === null || $selling === 0) {
            return null;
        }
        return round($profit / $selling * 100, 1);
    }

    // ============================================================
    // ヘルパー — 面積変換
    // ============================================================

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
     * 建物面積を坪数に変換
     */
    public function getBuildingAreaTsubo(): ?float
    {
        if ($this->building_area_sqm === null) {
            return null;
        }
        return round((float) $this->building_area_sqm / 3.30579, 2);
    }

    // ============================================================
    // ヘルパー — 紐づけ先表示
    // ============================================================

    /**
     * 紐づけ先の表示名
     */
    public function getLandSourceDisplay(): ?string
    {
        if ($this->land_source_type === HousingLandSourceType::ProjectLot && $this->projectLot) {
            $lot = $this->projectLot;
            $project = $lot->project;
            if ($project) {
                return $project->project_code . ' ' . $project->project_name . ' > ' . $lot->lot_number . '号地';
            }
            return $lot->lot_number . '号地';
        }
        if ($this->land_source_type === HousingLandSourceType::Procurement && $this->procurement) {
            $p = $this->procurement;
            return $p->procurement_code . ' ' . $p->property_name;
        }
        if ($this->land_source_type === HousingLandSourceType::CustomerLand) {
            return 'お客様所有土地';
        }
        return null;
    }

    /**
     * 紐づけ先の土地販売価格（参考値）
     */
    public function getReferenceLandSellingPrice(): ?int
    {
        if ($this->land_source_type === HousingLandSourceType::ProjectLot && $this->projectLot) {
            return $this->projectLot->selling_price;
        }
        if ($this->land_source_type === HousingLandSourceType::Procurement && $this->procurement) {
            return $this->procurement->target_selling_price;
        }
        return null;
    }
}
