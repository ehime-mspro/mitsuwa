<?php

namespace App\Models;

use App\Enums\HousingLandSourceType;
use App\Enums\HousingPropertyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HsProperty extends Model
{
    use HasFactory;

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
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 更新者
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
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
            return $this->procurement->target_selling_price;
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
}
