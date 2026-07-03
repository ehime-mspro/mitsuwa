<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HsContract extends Model
{
    use HasFactory;

    protected $table = 'hs_contracts';

    protected $fillable = [
        'property_id',
        'customer_id',
        'customer_name',
        'selling_price_land',
        'selling_price_building',
        'tax_rate',
        'contract_date',
        'settlement_date',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'selling_price_land'     => 'integer',
            'selling_price_building' => 'integer',
            'tax_rate'               => 'decimal:2',
            'contract_date'          => 'date',
            'settlement_date'        => 'date',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    /**
     * 紐づく建売物件
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(HsProperty::class, 'property_id');
    }

    /**
     * 登録者
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * 更新者
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    // ============================================================
    // ヘルパー — 金額計算
    // ============================================================

    /**
     * 建物消費税額
     */
    public function getBuildingTax(): int
    {
        return (int) round($this->selling_price_building * $this->tax_rate / 100);
    }

    /**
     * 販売価格合計（税抜: 土地+建物）
     */
    public function getSellingPriceTotal(): int
    {
        return $this->selling_price_land + $this->selling_price_building;
    }

    /**
     * 税込販売価格合計
     */
    public function getSellingPriceTotalWithTax(): int
    {
        return $this->selling_price_land + $this->selling_price_building + $this->getBuildingTax();
    }

    /**
     * 土地粗利額（土地販売価格 − 土地原価）
     */
    public function getLandProfit(): ?int
    {
        $property = $this->property;
        if ($property === null || $property->land_cost === null) {
            return null;
        }
        return $this->selling_price_land - $property->land_cost;
    }

    /**
     * 建物粗利額（建物販売価格 − 建築費）
     */
    public function getBuildingProfit(): ?int
    {
        $property = $this->property;
        if ($property === null || $property->building_cost === null) {
            return null;
        }
        return $this->selling_price_building - $property->building_cost;
    }

    /**
     * 合計粗利額
     */
    public function getTotalProfit(): ?int
    {
        $landProfit = $this->getLandProfit();
        $buildingProfit = $this->getBuildingProfit();
        if ($landProfit === null && $buildingProfit === null) {
            return null;
        }
        return ($landProfit ?? 0) + ($buildingProfit ?? 0);
    }

    /**
     * 土地粗利率
     */
    public function getLandProfitRate(): ?float
    {
        $profit = $this->getLandProfit();
        if ($profit === null || $this->selling_price_land === 0) {
            return null;
        }
        return round($profit / $this->selling_price_land * 100, 1);
    }

    /**
     * 建物粗利率
     */
    public function getBuildingProfitRate(): ?float
    {
        $profit = $this->getBuildingProfit();
        if ($profit === null || $this->selling_price_building === 0) {
            return null;
        }
        return round($profit / $this->selling_price_building * 100, 1);
    }

    /**
     * 合計粗利率
     */
    public function getTotalProfitRate(): ?float
    {
        $profit = $this->getTotalProfit();
        $total = $this->getSellingPriceTotal();
        if ($profit === null || $total === 0) {
            return null;
        }
        return round($profit / $total * 100, 1);
    }
}
