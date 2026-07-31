<?php

namespace App\Models;

use App\Enums\ProcurementStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Models\ReCostItem;
use App\Models\ReProcurementCost;
use App\Support\AreaConverter;
use App\Support\ConsumptionTax;
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
        'latitude',
        'longitude',
        'land_area_sqm',
        'building_area_sqm',
        'structure',
        'built_year_month',
        'zoning',
        'building_coverage',
        'floor_area_ratio',
        'supplier_id',
        'info_obtained_date',
        'assessment_price_land',
        'assessment_price_building',
        'purchase_price_land',
        'purchase_price_building',
        'target_selling_price_land',
        'target_selling_price_building',
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
            'property_type'       => RealEstatePropertyType::class,
            'transaction_type'    => RealEstateTransactionType::class,
            'status'              => ProcurementStatus::class,
            'latitude'            => 'decimal:7',
            'longitude'           => 'decimal:7',
            'land_area_sqm'       => 'decimal:2',
            'building_area_sqm'   => 'decimal:2',
            'building_coverage'   => 'integer',
            'floor_area_ratio'    => 'integer',
            'assessment_price_land'         => 'integer',
            'assessment_price_building'     => 'integer',
            'purchase_price_land'           => 'integer',
            'purchase_price_building'       => 'integer',
            'target_selling_price_land'     => 'integer',
            'target_selling_price_building' => 'integer',
            'tax_rate'                      => 'decimal:2',
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

    /**
     * この仕入れ案件を対象にした販売契約。
     * 運用上 1 案件 = 1 契約だが、データ構造としては複数を許す。
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(ReContract::class, 'procurement_id');
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

    // ============================================================
    // 金額（土地 / 建物 / 消費税）
    //
    // 合計カラムは持たない。都度算出する（派生カラムの stale 化を作らないため）。
    // 消費税は建物価格にのみ掛かる（土地の譲渡は非課税）。
    // ============================================================

    /** 査定価格合計（税抜: 土地 + 建物） */
    public function getAssessmentPriceTotal(): ?int
    {
        return $this->sumExcl($this->assessment_price_land, $this->assessment_price_building);
    }

    /** 購入価格合計（税抜: 土地 + 建物） */
    public function getPurchasePriceTotal(): ?int
    {
        return $this->sumExcl($this->purchase_price_land, $this->purchase_price_building);
    }

    /** 想定販売価格合計（税抜: 土地 + 建物） */
    public function getTargetSellingPriceTotal(): ?int
    {
        return $this->sumExcl($this->target_selling_price_land, $this->target_selling_price_building);
    }

    /** 査定の建物消費税額（表示専用。原価にも粗利にも算入しない） */
    public function getAssessmentBuildingTax(): ?int
    {
        return ConsumptionTax::tax($this->assessment_price_building, $this->tax_rate);
    }

    /** 購入の建物消費税額（表示専用。仕入税額控除の対象なので粗利に影響しない） */
    public function getPurchaseBuildingTax(): ?int
    {
        return ConsumptionTax::tax($this->purchase_price_building, $this->tax_rate);
    }

    /** 想定販売の建物消費税額 */
    public function getTargetSellingBuildingTax(): ?int
    {
        return ConsumptionTax::tax($this->target_selling_price_building, $this->tax_rate);
    }

    public function getAssessmentPriceTotalWithTax(): ?int
    {
        return $this->addTax($this->getAssessmentPriceTotal(), $this->getAssessmentBuildingTax());
    }

    public function getPurchasePriceTotalWithTax(): ?int
    {
        return $this->addTax($this->getPurchasePriceTotal(), $this->getPurchaseBuildingTax());
    }

    public function getTargetSellingPriceTotalWithTax(): ?int
    {
        return $this->addTax($this->getTargetSellingPriceTotal(), $this->getTargetSellingBuildingTax());
    }

    /** 建物価格欄を持つ物件種別か（仲介土地は土地のみ） */
    public function hasBuilding(): bool
    {
        return ! $this->property_type->isLandOnly();
    }

    /**
     * 土地・建物の税抜合計。
     * **両方 null のときだけ null** を返す（画面の「—」表示を維持するため）。
     * 片方だけ入っていれば 0 とみなして合算する。
     */
    private function sumExcl(?int $land, ?int $building): ?int
    {
        if ($land === null && $building === null) {
            return null;
        }
        return (int) $land + (int) $building;
    }

    private function addTax(?int $total, ?int $tax): ?int
    {
        if ($total === null) {
            return null;
        }
        return $total + (int) $tax;
    }

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
     * 粗利見込み（想定販売価格の**税抜**合計 − 原価合計採用額）
     *
     * ⚠ 消費税は算入しない。査定・購入とも税抜合計が原価「物件購入費」に同期されるため、
     *    税抜同士の引き算になる（設計書 §2）。
     */
    public function getExpectedProfit(): ?int
    {
        $target = $this->getTargetSellingPriceTotal();
        if ($target === null) {
            return null;
        }
        $costTotal = $this->getEffectiveCostTotal();
        if ($costTotal === 0 && $this->costs->isEmpty()) {
            return null;
        }
        return $target - $costTotal;
    }

    /**
     * 土地面積を坪数に変換（㎡ × 0.3025 の切り捨て。AreaConverter の docblock 参照）
     */
    public function getLandAreaTsubo(): ?float
    {
        if ($this->land_area_sqm === null) {
            return null;
        }
        return AreaConverter::sqmToTsubo($this->land_area_sqm);
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

    // ============================================================
    // ライフサイクルフック
    // ============================================================

    protected static function booted(): void
    {
        static::saved(function (ReProcurement $procurement): void {
            // 査定価格・購入価格（土地・建物とも）が変更されたとき、または新規作成時のみ同期
            // ⚠ _building を書き忘れると、建物金額を変えても原価が同期されない（例外は出ない）
            if ($procurement->wasChanged([
                    'assessment_price_land', 'assessment_price_building',
                    'purchase_price_land',   'purchase_price_building',
                ])
                || $procurement->wasRecentlyCreated) {
                $procurement->syncPropertyPurchaseCost();
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
        // 税抜の土地＋建物合計を原価に同期する（消費税は原価に算入しない）
        $assessment = $this->getAssessmentPriceTotal();
        $purchase   = $this->getPurchasePriceTotal();

        if ($assessment === null && $purchase === null) {
            return;
        }

        $costItem = ReCostItem::firstOrCreate(
            ['name' => '物件購入費'],
            ['sort_order' => 0, 'is_active' => true],
        );

        ReProcurementCost::updateOrCreate(
            [
                'procurement_id' => $this->id,
                'cost_item_id'   => $costItem->id,
            ],
            [
                'estimated_amount' => $assessment ?? 0,
                'actual_amount'    => $purchase,
            ],
        );
    }
}
