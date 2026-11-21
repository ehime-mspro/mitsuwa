<?php

namespace App\Models;

use App\Enums\LotStatus;
use App\Support\AreaConverter;
use App\Support\TsuboPrice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 土地の仕入れ案件の区画（取引種別を問わない。1 案件に何区画でも。自社買取に限るのは「分譲地に変える」だけ）。設計書 §6。
 *
 * ⚠ 坪数・坪単価は保存しない。表示は面積と販売価格から毎回求める（Bug #33・#34。分譲地の区画と違い、
 *   保存した坪数・坪単価が食い違う余地を作らない）。
 */
class ReProcurementLot extends Model
{
    protected $table = 're_procurement_lots';

    protected $fillable = [
        'procurement_id',
        'lot_number',
        'area_sqm',
        'selling_price',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'area_sqm'      => 'decimal:2',
            'selling_price' => 'integer',
            'status'        => LotStatus::class,
        ];
    }

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(ReProcurement::class, 'procurement_id');
    }

    /** この区画に付いた不動産の契約（1 区画に 1 件まで） */
    public function contract(): HasOne
    {
        return $this->hasOne(ReContract::class, 'procurement_lot_id');
    }

    /** この区画を土地元にした建売物件 */
    public function properties(): HasMany
    {
        return $this->hasMany(HsProperty::class, 're_procurement_lot_id');
    }

    /** この区画を土地元にした注文住宅 */
    public function customOrders(): HasMany
    {
        return $this->hasMany(HsCustomOrder::class, 're_procurement_lot_id');
    }

    /** 坪数（㎡ × 0.3025 の切り捨て。保存しない） */
    public function getAreaTsubo(): float
    {
        return AreaConverter::sqmToTsubo((float) $this->area_sqm);
    }

    /** 例: 132.69㎡（40.13坪） */
    public function areaLabel(): string
    {
        return number_format((float) $this->area_sqm, 2) . '㎡（' . number_format($this->getAreaTsubo(), 2) . '坪）';
    }

    /** 販売坪単価の表示用（@XX.X 万円。販売価格と坪数から丸めは 1 回だけ） */
    public function getSellingPricePerTsuboFormatted(): ?string
    {
        if ($this->selling_price === null || $this->selling_price <= 0) {
            return null;
        }

        return TsuboPrice::perTsuboManLabel((int) $this->selling_price, $this->getAreaTsubo());
    }

    /** 例: 1号地 132.69㎡（40.13坪） 販売価格 25,000,000円 */
    public function summaryLabel(): string
    {
        $label = $this->lot_number . '号地 ' . $this->areaLabel();
        if ($this->selling_price !== null) {
            $label .= ' 販売価格 ' . number_format($this->selling_price) . '円';
        }

        return $label;
    }

    /** その仕入れ案件の区画だけを成約にする（null・区画なしなら何もしない） */
    public static function markSoldFor(?int $procurementId): void
    {
        self::markFor($procurementId, LotStatus::Sold);
    }

    /** その仕入れ案件の区画だけを販売中にする（null・区画なしなら何もしない） */
    public static function markOnSaleFor(?int $procurementId): void
    {
        self::markFor($procurementId, LotStatus::OnSale);
    }

    private static function markFor(?int $procurementId, LotStatus $status): void
    {
        if ($procurementId === null) {
            return;
        }

        self::query()->where('procurement_id', $procurementId)->update(['status' => $status->value]);
    }
}
