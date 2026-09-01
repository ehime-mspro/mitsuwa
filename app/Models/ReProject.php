<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\LotStatus;
use App\Models\Concerns\HasScheduleSteps;
use App\Models\ReCostItem;
use App\Models\ReProjectCost;
use App\Support\AreaConverter;
use App\Support\DeletionBlockers;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ReProject extends Model
{
    use HasFactory;
    use HasScheduleSteps;

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

    /**
     * 区画の成約状況から PJ ステータスを集約する。
     *
     * - 全区画成約（区画1件以上 かつ 全て LotStatus::Sold）→ SoldOut へ昇格
     *   （昇格元は「販売済・不成立 以外」の任意ステータス。「販売済＝完売」を
     *     派生的な完了状態として扱う。区画が全て売れるのは実務上終盤のみ）
     * - 販売済なのに未成約区画が復活 → Selling へ降格
     * - 区画0件のPJ・上記いずれにも該当しないPJは一切触らない
     *
     * ステータス更新はクエリビルダで行う（procurement の案件遷移と同形）。
     * updated_at は Builder::update() が自動付与するが、モデルイベントを
     * 通らないため updated_by は据え置き（＝ユーザー操作ではなくシステム反応）。
     * booted() の saved フック（物件購入費同期）も発火しない。
     * in-memory の status も揃えて呼び出し元の齟齬を防ぐ。
     *
     * ⚠ 本メソッドは「区画の status が変わりうる全経路」から明示的に呼ぶこと。
     *   既知の呼び出し箇所は docs/superpowers/specs/2026-07-22-project-sold-status-design.md §3.3。
     *   新経路を足すときは必ず呼び出しを追加すること。
     */
    public function syncStatusFromLots(): void
    {
        $lots  = ReProjectLot::where('project_id', $this->id)->get(['status']);
        $total = $lots->count();
        if ($total === 0) {
            return; // 区画0件は無干渉（every() が空で true を返す事故も防ぐ）
        }

        $allSold = $lots->every(fn (ReProjectLot $lot) => $lot->status === LotStatus::Sold);
        $current = $this->status;

        if ($allSold && ! in_array($current, [ProjectStatus::SoldOut, ProjectStatus::Lost], true)) {
            ReProject::where('id', $this->id)->update(['status' => ProjectStatus::SoldOut->value]);
            $this->status = ProjectStatus::SoldOut;
        } elseif (! $allSold && $current === ProjectStatus::SoldOut) {
            ReProject::where('id', $this->id)->update(['status' => ProjectStatus::Selling->value]);
            $this->status = ProjectStatus::Selling;
        }
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

    /**
     * この分譲地PJ を参照していて、消えると壊れるデータ（PJ 直参照 ＋ 配下区画経由）。
     * 空配列なら削除可能。判定の実体は DeletionBlockers（画面とサーバで共有する）。
     */
    public function deletionBlockers(): array
    {
        return DeletionBlockers::forProject($this);
    }

    // ============================================================
    // 工程表（設計書 §3.3）
    // ============================================================

    public function scheduleCode(): string
    {
        return $this->project_code;
    }

    public function scheduleName(): string
    {
        return $this->project_name;
    }

    public function scheduleRoutePrefix(): string
    {
        return 'realestate.projects';
    }

    public function autoMilestones(): array
    {
        return array_values(array_filter([
            $this->contract_date   ? ['label' => '契約', 'date' => $this->contract_date] : null,
            $this->settlement_date ? ['label' => '決済', 'date' => $this->settlement_date] : null,
        ]));
    }
}
