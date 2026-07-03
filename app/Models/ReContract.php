<?php

namespace App\Models;

use App\Enums\ReContractStatus;
use App\Enums\ReContractType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReContract extends Model
{
    use HasFactory;

    protected $table = 're_contracts';

    protected $fillable = [
        'department',
        'contract_type',
        'status',
        'contract_date',
        'property_name',
        'address',
        'procurement_id',
        'project_id',
        'lot_id',
        'buyer_id',
        'buyer_name',
        'contract_amount',
        'cost_amount',
        'gross_profit',
        'brokerage_selling_price',
        'brokerage_fee',
        'staff_user_id',
        'memo',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'contract_type'           => ReContractType::class,
            'status'                  => ReContractStatus::class,
            'contract_date'           => 'date',
            'contract_amount'         => 'integer',
            'cost_amount'             => 'integer',
            'gross_profit'            => 'integer',
            'brokerage_selling_price' => 'integer',
            'brokerage_fee'           => 'integer',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(ReProcurement::class, 'procurement_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ReProject::class, 'project_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(ReProjectLot::class, 'lot_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class, 'buyer_id')->withTrashed();
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    // ============================================================
    // アクセサ
    // ============================================================

    /**
     * 粗利率（%）
     */
    public function getGrossProfitRateAttribute(): ?float
    {
        if (!$this->contract_amount || $this->contract_amount == 0) {
            return null;
        }
        if ($this->contract_type->isBrokerage()) {
            return null;
        }
        return round(($this->gross_profit / $this->contract_amount) * 100, 1);
    }

    /**
     * 買主表示名（買主マスタ or テキスト）
     */
    public function getBuyerDisplayNameAttribute(): ?string
    {
        if ($this->buyer_id && $this->buyer) {
            return $this->buyer->last_name . ' ' . $this->buyer->first_name;
        }
        return $this->buyer_name;
    }

    // ============================================================
    // スコープ
    // ============================================================

    /**
     * 部署フィルター
     */
    public function scopeOfDepartment($query, string $dept)
    {
        return $query->where('department', $dept);
    }

    /**
     * 年度フィルター（5月始まり）
     */
    public function scopeOfFiscalYear($query, int $year)
    {
        $start = "{$year}-05-01";
        $end   = ($year + 1) . "-04-30";
        return $query->whereBetween('contract_date', [$start, $end]);
    }

    /**
     * 種別フィルター
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('contract_type', $type);
    }

    /**
     * 契約済み+仲介成約のみ（一覧用）
     */
    public function scopeContracted($query)
    {
        return $query->where(function ($q) {
            $q->where('status', ReContractStatus::Contracted->value)
              ->orWhere('status', ReContractStatus::Closed->value);
        });
    }

    // ============================================================
    // メソッド
    // ============================================================

    /**
     * 仕入れ案件から原価計算
     * 物件購入費は ReProcurement::syncPropertyPurchaseCost() で costs に
     * 自動同期されるため、ここでは costs の合計のみを返す（二重計上防止）。
     */
    public static function calculateCostFromProcurement(ReProcurement $procurement): int
    {
        $costsTotal = 0;
        foreach ($procurement->costs as $cost) {
            $costsTotal += $cost->actual_amount ?? $cost->estimated_amount;
        }
        return $costsTotal;
    }

    /**
     * 分譲地から区画あたり原価計算
     */
    public static function calculateCostFromProject(ReProject $project): int
    {
        $costTotal = 0;
        foreach ($project->costs as $cost) {
            $costTotal += $cost->actual_amount ?? $cost->estimated_amount;
        }
        $lotCount = $project->lots->count();
        if ($lotCount === 0) {
            return 0;
        }
        return (int) ceil($costTotal / $lotCount);
    }

    /**
     * 粗利額自動計算
     */
    public function calculateGrossProfit(): int
    {
        if ($this->contract_type->isBrokerage()) {
            return (int) $this->brokerage_fee;
        }
        return (int) $this->contract_amount - (int) $this->cost_amount;
    }
}
