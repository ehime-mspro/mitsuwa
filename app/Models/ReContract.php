<?php

namespace App\Models;

use App\Enums\BuyerDepartment;
use App\Enums\ReContractStatus;
use App\Enums\ReContractType;
use App\Support\ConsumptionTax;
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
        'contract_amount_land',
        'contract_amount_building',
        'tax_rate',
        'tax_amount',
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
            'contract_amount_land'     => 'integer',
            'contract_amount_building' => 'integer',
            'tax_rate'                 => 'decimal:2',
            'tax_amount'               => 'integer',
            'cost_amount'             => 'integer',
            'gross_profit'            => 'integer',
            'brokerage_selling_price' => 'integer',
            'brokerage_fee'           => 'integer',
        ];
    }

    // ============================================================
    // ライフサイクルフック
    // ============================================================

    /**
     * 建物欄を持たない構成のとき、建物側の列を明示的に null へ正規化する。
     *
     * ⚠ 画面は建物 input を :disabled にして送信しないが、Laravel の validated() は
     *    未送信キーを結果に含めないため、update() では**旧値が DB に残る**。
     *    getContractAmountTotal() は hasBuilding() を見ない単純加算なので、
     *    残った建物額が契約額合計・一覧・ダッシュボードに混ざる。
     *    しかも gross_profit は $validated から再計算される（建物 0）ため、
     *    「契約額」と「粗利」が食い違って表示される。
     *
     * ⚠ hasBuilding() は使わない。hasBuilding() は「procurement_id が無い（＝紐づけ不明）」と
     *    「紐づく仕入れ案件が土地のみと確定している」を**区別せず両方 false**にする。
     *    本番の re_contracts.procurement_id は仕入れ案件側の FK が ON DELETE SET NULL
     *    のため、仕入れ案件を削除すると（ProcurementController::destroy() は紐づく契約の
     *    有無を確認しない）既存契約の procurement_id が NULL になる。ここで「紐づけ不明」を
     *    「土地のみ」と同一視すると、記録済みの建物額・消費税額の手入力が黙って失われる
     *    （本番に該当契約が実在する）。**土地のみと確定できたときだけ**消す。
     */
    protected static function booted(): void
    {
        static::saving(function (ReContract $contract): void {
            if ($contract->contract_type->isProcurement()) {
                // ReProcurement::find() で毎回引き直す（procurement リレーションのキャッシュに
                // 依存しない）。procurement_id が無い/紐づけ先が既に削除済みなら「不明」として
                // 何もしない。
                $procurement = $contract->procurement_id
                    ? ReProcurement::find($contract->procurement_id)
                    : null;

                if ($procurement !== null && $procurement->property_type->isLandOnly()) {
                    $contract->contract_amount_building = null;
                    $contract->tax_amount               = null;
                }

                return;
            }

            // 分譲地販売・仲介は構造的に土地のみ（validate も建物欄を受け付けない）
            $contract->contract_amount_building = null;
            $contract->tax_amount               = null;
        });

        /**
         * 買主が紐づいたら、その部署の顧客ランクを「成約」にする。
         *
         * ⚠ コントローラではなくモデルに置く。買主が契約に紐づく入口は登録・編集の 2 経路あり、
         *    片方に書き忘れても画面は正常に動いて無音で漏れる（Bug #41 / #44 と同型）。
         *
         * ⚠ 仲介は buyer_id を持たない（買主欄が :disabled で validate にもルートが無く、
         *    成約処理も自由入力の buyer_name を受け取るだけ）。「buyer_id があるときだけ」
         *    という条件で自然に対象外になる（設計書 §3.1）。
         *
         * ⚠ wasRecentlyCreated / wasChanged のガードを外さないこと。外すと契約のメモを
         *    直しただけで、利用者が手で戻したランクが成約へ書き戻る（設計書 §3.3）。
         *
         * ⚠ department はハードコードしない。re_contracts は住宅事業へ拡張可能な設計で
         *    department カラムを持つ。ただし buyer_departments.department は
         *    enum('housing','realestate') なので、範囲外なら何もしない（設計書 §5.3）。
         *    ((string) キャストは Bug #22 型の TypeError 予防。department は NOT NULL なので
         *    現状 null は来ないが、tryFrom() は string|int しか受け付けないため残してある)
         *
         * ⚠ ここで例外が飛ぶと、契約行は既に INSERT 済みなのにコントローラの後続処理
         *    (区画・仕入れ案件のステータス同期) が中断する。ReContractController::store() は
         *    DB::transaction を使っていないため巻き戻らない。**意図的にそのままにしている**——
         *    唯一の現実的な例外源は markContracted() の初回作成の競合で、そちらの docblock に
         *    受容の理由がある。store() をトランザクションで包むのは既存の本番挙動を変えるので別案件。
         */
        static::saved(function (ReContract $contract): void {
            if ($contract->buyer_id === null) {
                return;
            }

            if (! $contract->wasRecentlyCreated && ! $contract->wasChanged('buyer_id')) {
                return;
            }

            $department = BuyerDepartment::tryFrom((string) $contract->department);
            if ($department === null) {
                return;
            }

            Buyer::withTrashed()->find($contract->buyer_id)?->markContracted(
                $department->value,
                $contract->contract_date?->toDateString(),
            );
        });
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
    // 金額（土地 / 建物 / 消費税）
    //
    // 合計カラムは持たない。都度算出する。消費税は建物価格にのみ掛かる。
    // ============================================================

    /** 契約金額合計（税抜: 土地 + 建物）。両方 null なら null */
    public function getContractAmountTotal(): ?int
    {
        if ($this->contract_amount_land === null && $this->contract_amount_building === null) {
            return null;
        }
        return (int) $this->contract_amount_land + (int) $this->contract_amount_building;
    }

    /**
     * 建物消費税額。
     *
     * tax_amount に手入力があればそれを正とする（契約書の端数処理が
     * 自動計算と一致しないことがあるため）。NULL なら建物 × 税率で自動計算。
     */
    public function getBuildingTax(): ?int
    {
        if ($this->tax_amount !== null) {
            return (int) $this->tax_amount;
        }
        return ConsumptionTax::tax($this->contract_amount_building, $this->tax_rate);
    }

    /** 税込の契約金額合計 */
    public function getContractAmountTotalWithTax(): ?int
    {
        $total = $this->getContractAmountTotal();
        if ($total === null) {
            return null;
        }
        return $total + (int) $this->getBuildingTax();
    }

    /**
     * 建物価格欄を持つ契約か。
     *
     * ⚠ 契約種別では判定しない。物件種別（RealEstatePropertyType）は建物を持つものが 5 種
     *    あるのに対し、販売の契約種別（ReContractType）は「中古マンション販売」「中古戸建販売」
     *    の 2 種しかなく、テナントビル / アパート / 一棟売りマンションに当てはまる種別が無い。
     *    契約種別で判定すると、それらを「仕入れ土地販売」で登録した瞬間に建物欄が消え、
     *    建物価格と消費税を記録できなくなる（設計書 §4.2）。
     *
     * 仕入れ系契約は procurement_id が必須なので紐づけ先は必ず存在する。
     * 分譲地販売・仲介は常に土地のみ。
     */
    public function hasBuilding(): bool
    {
        if ($this->contract_type->isProcurement()) {
            return $this->procurement !== null
                && ! $this->procurement->property_type->isLandOnly();
        }
        return false;
    }

    // ============================================================
    // アクセサ
    // ============================================================

    /**
     * 粗利率（%）。分母は**税抜**の契約金額合計
     */
    public function getGrossProfitRateAttribute(): ?float
    {
        $total = $this->getContractAmountTotal();
        if (!$total) {
            return null;
        }
        if ($this->contract_type->isBrokerage()) {
            return null;
        }
        return round(($this->gross_profit / $total) * 100, 1);
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
     * 粗利額自動計算（税抜合計 − 原価）
     *
     * ⚠ 現状どこからも呼ばれていない（コントローラが $validated から直接組み立てるため）。
     *    仕様の正本として合計ベースに合わせておく。
     */
    public function calculateGrossProfit(): int
    {
        if ($this->contract_type->isBrokerage()) {
            return (int) $this->brokerage_fee;
        }
        return (int) $this->getContractAmountTotal() - (int) $this->cost_amount;
    }
}
