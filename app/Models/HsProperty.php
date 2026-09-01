<?php

namespace App\Models;

use App\Enums\HousingLandSourceType;
use App\Enums\HousingPropertyStatus;
use App\Models\Concerns\HasScheduleSteps;
use App\Support\AreaConverter;
use App\Support\ConsumptionTax;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HsProperty extends Model
{
    use HasFactory;
    use HasScheduleSteps;

    protected $table = 'hs_properties';

    protected $fillable = [
        'property_code',
        'property_name',
        'status',
        'land_source_type',
        're_project_lot_id',
        're_procurement_id',
        'postal_code',
        'address',
        'land_area_sqm',
        'building_area_sqm',
        'structure',
        'floors',
        'scheduled_completion_date',
        'actual_completion_date',
        'building_cost',
        'land_cost',
        'is_land_cost_manual',
        'target_selling_price_building',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status'                       => HousingPropertyStatus::class,
            'land_source_type'             => HousingLandSourceType::class,
            'land_area_sqm'                => 'decimal:2',
            'building_area_sqm'            => 'decimal:2',
            'building_cost'                => 'integer',
            'land_cost'                    => 'integer',
            'is_land_cost_manual'          => 'boolean',
            'target_selling_price_building' => 'integer',
            'scheduled_completion_date'    => 'date',
            'actual_completion_date'       => 'date',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    /**
     * 紐づく分譲地区画
     */
    public function projectLot(): BelongsTo
    {
        return $this->belongsTo(ReProjectLot::class, 're_project_lot_id');
    }

    /**
     * 紐づく仕入れ案件
     */
    public function procurement(): BelongsTo
    {
        return $this->belongsTo(ReProcurement::class, 're_procurement_id');
    }

    /**
     * 契約（1物件 = 0〜1契約）
     */
    public function contract(): HasOne
    {
        return $this->hasOne(HsContract::class, 'property_id');
    }

    /**
     * ファイル一覧
     */
    public function files(): HasMany
    {
        return $this->hasMany(HsPropertyFile::class, 'property_id');
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
    // ヘルパー — ステータス
    // ============================================================

    /**
     * 成約済みか（契約レコードの有無で判定）
     */
    public function isSold(): bool
    {
        return $this->contract !== null;
    }

    /**
     * 表示用ステータスラベル
     */
    public function getDisplayStatusLabel(): string
    {
        if ($this->isSold()) {
            return '成約';
        }
        return $this->status->label();
    }

    /**
     * 表示用バッジクラス
     */
    public function getDisplayBadgeClass(): string
    {
        if ($this->isSold()) {
            return 'badge-hs-sold';
        }
        return $this->status->badgeClass();
    }

    /**
     * 表示用バッジインラインスタイル（Viteビルド未収録のため）
     */
    public function getDisplayBadgeStyle(): string
    {
        if ($this->isSold()) {
            return 'background: #a7f3d0; color: #064e3b;';
        }
        return $this->status->badgeStyle();
    }

    // ============================================================
    // ヘルパー — 金額計算
    // ============================================================

    /**
     * 原価合計（土地原価 + 建築費）
     */
    public function getTotalCost(): ?int
    {
        if ($this->land_cost === null && $this->building_cost === null) {
            return null;
        }
        return ($this->land_cost ?? 0) + ($this->building_cost ?? 0);
    }

    /**
     * 販売価格合計（土地+建物、税抜）
     * 契約あり: 契約の販売価格
     * 契約なし: 建物予定販売価格 + 紐づけ先の土地参考販売価格
     */
    public function getSellingPriceTotal(): ?int
    {
        // 契約がある場合は契約の販売価格を使用
        if ($this->isSold()) {
            $c = $this->contract;
            return $c->selling_price_land + $c->selling_price_building;
        }

        // 未契約: 建物予定販売価格 + 土地参考販売価格
        $building = $this->target_selling_price_building;
        $land = $this->getReferenceLandSellingPrice();

        if ($building === null && $land === null) {
            return null;
        }

        return ($building ?? 0) + ($land ?? 0);
    }

    /**
     * 契約が存在する場合の粗利額
     */
    public function getGrossProfit(): ?int
    {
        $selling = $this->getSellingPriceTotal();
        $cost = $this->getTotalCost();
        if ($selling === null || $cost === null) {
            return null;
        }
        return $selling - $cost;
    }

    /**
     * 契約が存在する場合の粗利率
     */
    public function getGrossProfitRate(): ?float
    {
        $selling = $this->getSellingPriceTotal();
        $profit = $this->getGrossProfit();
        if ($selling === null || $profit === null || $selling === 0) {
            return null;
        }
        return round($profit / $selling * 100, 1);
    }

    /**
     * 自社土地か（分譲地区画 or 仕入れ案件）
     */
    public function isCompanyLand(): bool
    {
        return $this->land_source_type === HousingLandSourceType::ProjectLot
            || $this->land_source_type === HousingLandSourceType::Procurement;
    }

    /**
     * 建物販売額（契約あり=契約の建物価格 / なし=予定販売価格）
     */
    public function getBuildingSellingPrice(): ?int
    {
        if ($this->isSold()) {
            return $this->contract->selling_price_building;
        }
        return $this->target_selling_price_building;
    }

    /**
     * 土地販売額（契約あり=契約の土地価格 / なし=紐づけ先の参考価格。お客様所有土地は null）
     */
    public function getLandSellingPrice(): ?int
    {
        if ($this->isSold()) {
            return $this->contract->selling_price_land;
        }
        return $this->getReferenceLandSellingPrice();
    }

    /**
     * 建物粗利額（税抜）
     */
    public function getBuildingProfit(): ?int
    {
        $selling = $this->getBuildingSellingPrice();
        if ($selling === null || $this->building_cost === null) {
            return null;
        }
        return $selling - $this->building_cost;
    }

    /**
     * 建物粗利率（税抜ベース）
     */
    public function getBuildingProfitRate(): ?float
    {
        $selling = $this->getBuildingSellingPrice();
        $profit = $this->getBuildingProfit();
        if ($profit === null || $selling === null || $selling === 0) {
            return null;
        }
        return round($profit / $selling * 100, 1);
    }

    /**
     * 土地粗利額（自社土地時のみ）
     */
    public function getLandProfit(): ?int
    {
        if (! $this->isCompanyLand()) {
            return null;
        }
        $selling = $this->getLandSellingPrice();
        if ($selling === null || $this->land_cost === null) {
            return null;
        }
        return $selling - $this->land_cost;
    }

    /**
     * 土地粗利率（自社土地時のみ）
     */
    public function getLandProfitRate(): ?float
    {
        if (! $this->isCompanyLand()) {
            return null;
        }
        $selling = $this->getLandSellingPrice();
        $profit = $this->getLandProfit();
        if ($profit === null || $selling === null || $selling === 0) {
            return null;
        }
        return round($profit / $selling * 100, 1);
    }

    /**
     * 有効消費税率（成約時は契約の税率、未成約時はシステム既定値）
     */
    public function getEffectiveTaxRate(): float
    {
        return (float) ($this->contract?->tax_rate ?? Settings::taxRate());
    }

    /**
     * 建物消費税額（土地は非課税）
     *
     * 丸めは切り捨て。`ConsumptionTax` に一本化しているので round に戻さないこと（Bug #33/#34 と同じ規約）。
     *
     * ⚠ null ガードを外さないこと。`ConsumptionTax::tax()` は金額 null で null を返すが、
     *   本メソッドの戻り値型は int で、呼び出し側（一覧の税込サブ行）は「未入力なら 0」に依存している。
     */
    public function getBuildingTax(): int
    {
        $selling = $this->getBuildingSellingPrice();
        if ($selling === null) {
            return 0;
        }
        return (int) ConsumptionTax::tax($selling, $this->getEffectiveTaxRate());
    }

    /**
     * 建物税込販売額
     */
    public function getBuildingSellingPriceWithTax(): ?int
    {
        $selling = $this->getBuildingSellingPrice();
        if ($selling === null) {
            return null;
        }
        return $selling + $this->getBuildingTax();
    }

    /**
     * 合計税込販売額（合計販売 + 建物消費税。土地は非課税）
     */
    public function getSellingPriceTotalWithTax(): ?int
    {
        $total = $this->getSellingPriceTotal();
        if ($total === null) {
            return null;
        }
        return $total + $this->getBuildingTax();
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
     * 建物面積を坪数に変換
     */
    public function getBuildingAreaTsubo(): ?float
    {
        if ($this->building_area_sqm === null) {
            return null;
        }
        return AreaConverter::sqmToTsubo($this->building_area_sqm);
    }

    // ============================================================
    // ヘルパー — 紐づけ先からの参考販売価格取得
    // ============================================================

    /**
     * 紐づけ先の土地販売価格（参考値）
     */
    public function getReferenceLandSellingPrice(): ?int
    {
        if ($this->land_source_type === HousingLandSourceType::ProjectLot && $this->projectLot) {
            return $this->projectLot->selling_price;
        }
        if ($this->land_source_type === HousingLandSourceType::Procurement && $this->procurement) {
            return $this->procurement->target_selling_price_land;
        }
        return null;
    }

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
        return null;
    }

    // ============================================================
    // 工程表（設計書 §3.3）
    // ============================================================

    public function scheduleCode(): string
    {
        return $this->property_code;
    }

    public function scheduleName(): string
    {
        return $this->property_name;
    }

    public function scheduleRoutePrefix(): string
    {
        return 'housing.properties';
    }

    /**
     * ⚠ **「完成」は 1 つだけ。** scheduled_completion_date と actual_completion_date は
     *   同じ節目なので、実績があれば実績・無ければ予定の位置に ◆ を 1 つだけ描く。
     *   2 つ描くと「完成が 2 回ある」ように見える（設計書 §3.4）。
     */
    public function autoMilestones(): array
    {
        $completion = $this->actual_completion_date ?? $this->scheduled_completion_date;

        return $completion ? [['label' => '完成', 'date' => $completion]] : [];
    }
}
