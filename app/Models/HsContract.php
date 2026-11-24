<?php

namespace App\Models;

use App\Enums\BuyerDepartment;
use App\Support\ConsumptionTax;
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
    // ライフサイクルフック
    // ============================================================

    /**
     * 買主が紐づいたら、住宅事業の顧客ランクを「成約」にする。
     *
     * ⚠ コントローラではなくモデルに置く。買主が契約に紐づく入口は
     *    Housing\ContractController::store と HsContractListController::updateBuilding の
     *    2 経路あり、片方に書き忘れても画面は正常に動いて無音で漏れる（Bug #41 / #44）。
     *
     * ⚠ wasRecentlyCreated / wasChanged のガードを外さないこと。外すと備考を直しただけで
     *    利用者が手で戻したランクが成約へ書き戻る（設計書 §3.3）。
     *
     * ⚠ 買主は withTrashed() で引く。保存の入口は画面の選択肢（住宅事業の買主＋今の買主）だけを受け付けるので
     *    （Buyer::selectableForHousing()・docs/RULES.md Bug #149）、削除済みの買主が届くのは今の買主のまま保存するとき
     *    だけで、そのとき customer_id は変わらずここは動かない＝ HTTP からは届かない。それでも選んだあとで買主が削除された
     *    瞬間などのための守りとして残す（素の find() だと null が返り ?-> で無音になる。モデルのテストが固定）。
     *
     * ⚠ ここで例外が飛ぶと（唯一の現実的な例外源は markContracted() の初回作成の競合。
     *    そちらの docblock に受容の理由がある）、Housing\ContractController::store と
     *    HsContractListController::updateBuilding は DB::transaction の中なので、契約の行も
     *    （store は区画を成約にすることも）一緒に巻き戻る（docs/RULES.md Bug #148）。
     *    Housing\ContractController::update だけはトランザクションの外（区画を動かさない 1 行の保存）で、
     *    買主を替えた更新がここで落ちると、契約の行は新しい買主で残り、買主のランクだけが成約にならない。
     *
     * 部署は hs_contracts が住宅事業固有のテーブルなので housing 固定（設計書 §5.3）。
     */
    protected static function booted(): void
    {
        static::saved(function (HsContract $contract): void {
            if ($contract->customer_id === null) {
                return;
            }

            if (! $contract->wasRecentlyCreated && ! $contract->wasChanged('customer_id')) {
                return;
            }

            Buyer::withTrashed()->find($contract->customer_id)?->markContracted(
                BuyerDepartment::Housing->value,
                $contract->contract_date?->toDateString(),
            );
        });
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
     * 建物消費税額（土地は非課税）
     *
     * 丸めは切り捨て。`ConsumptionTax` に一本化しているので round に戻さないこと（Bug #33/#34 と同じ規約）。
     */
    public function getBuildingTax(): int
    {
        return (int) ConsumptionTax::tax($this->selling_price_building, $this->tax_rate);
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
