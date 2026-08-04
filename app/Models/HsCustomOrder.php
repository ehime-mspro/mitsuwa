<?php

namespace App\Models;

use App\Enums\BuyerDepartment;
use App\Enums\CustomOrderStatus;
use App\Enums\HousingLandSourceType;
use App\Support\AreaConverter;
use App\Support\ConsumptionTax;
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
    // ライフサイクルフック
    // ============================================================

    /**
     * ステータスが「契約」以降になったとき、住宅事業の顧客ランクを「成約」にする。
     *
     * ⚠ **登録時ではない。** hs_custom_orders は 商談 → 設計 → 見積り → 契約 → 着工 →
     *    完成 → 引渡し と進む案件レコードで、商談段階でも登録できる。登録＝契約ではないので、
     *    登録時に成約へ変えるとまだ商談中の見込み客が成約扱いになる（設計書 §3.2）。
     *
     * ⚠ 判定は CustomOrderStatus::isContractedOrLater()。分譲地区画のステータス連動
     *    （CustomOrderController::syncLotStatus）が使っている判定と同一で、
     *    「契約以降なら区画は販売済」と足並みが揃う。別の閾値を書かないこと。
     *
     * ⚠ **契約以降から手前へ戻した場合、ランクは成約のまま残す**（戻さない）。
     *    同じ isContractedOrLater() を使う CustomOrderController::syncLotStatus() は
     *    双方向に反応して区画を販売中へ戻すので**意図的に非対称**——区画は「また売れるか」という
     *    在庫の話、顧客ランクは獲得履歴の話で意味が違う。設計書 §2 の決定2 が
     *    「契約を削除しても元の買主は成約のまま」と定めており、案件ステータスを一段戻しただけで
     *    戻すのはそれと矛盾する。**「戻す処理の消し忘れ」ではないので足さないこと。**
     *    回帰テスト test_custom_order_status_regression_keeps_rank_contracted が固定している。
     *
     * ⚠ wasRecentlyCreated / wasChanged のガードを外さないこと。外すと備考を直しただけで
     *    利用者が手で戻したランクが成約へ書き戻る（設計書 §3.3）。
     *
     * ⚠ 買主は withTrashed() で引く。customer_id の exists:buyers,id は
     *    DatabasePresenceVerifier がテーブルを直接引くので SoftDeletingScope を通らず、
     *    論理削除済みの id が届きうる（コントローラ自身も withTrashed() で受けている）。
     *    素の find() だと null が返り ?-> で無音になる。
     *
     * ⚠ ここで例外が飛ぶと案件行は既に保存済みなのに後続処理（区画ステータス連動）が
     *    中断する。CustomOrderController は DB::transaction を使っていないので巻き戻らない。
     *    **意図的にそのまま**——唯一の現実的な例外源は markContracted() の初回作成の競合で、
     *    そちらの docblock に受容の理由がある。
     *
     * 部署は hs_custom_orders が住宅事業固有のテーブルなので housing 固定（設計書 §5.3）。
     */
    protected static function booted(): void
    {
        static::saved(function (HsCustomOrder $order): void {
            if ($order->customer_id === null) {
                return;
            }

            if ($order->status?->isContractedOrLater() !== true) {
                return;
            }

            if (! $order->wasRecentlyCreated && ! $order->wasChanged(['customer_id', 'status'])) {
                return;
            }

            Buyer::withTrashed()->find($order->customer_id)?->markContracted(
                BuyerDepartment::Housing->value,
                $order->contract_date?->toDateString(),
            );
        });
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
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    // ============================================================
    // ヘルパー — 土地種別
    // ============================================================

    /**
     * 自社土地か（分譲地区画 or 仕入れ案件）
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
     * 建物消費税額（土地は非課税）
     *
     * 丸めは切り捨て。`ConsumptionTax` に一本化しているので round に戻さないこと（Bug #33/#34 と同じ規約）。
     *
     * ⚠ null ガードを外さないこと。`ConsumptionTax::tax()` は金額 null で null を返すが、
     *   本メソッドの戻り値型は int で、呼び出し側（一覧の税込サブ行）は「未入力なら 0」に依存している。
     */
    public function getBuildingTax(): int
    {
        if ($this->building_contract_price === null) {
            return 0;
        }
        return (int) ConsumptionTax::tax($this->building_contract_price, $this->tax_rate);
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
            return $this->procurement->target_selling_price_land;
        }
        return null;
    }
}
