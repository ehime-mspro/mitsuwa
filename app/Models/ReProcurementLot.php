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

    /**
     * 区画の選択欄の選択肢（設計書 §5.3）。例: 1号地 132.69㎡（40.13坪） 販売価格 25,000,000円 販売中
     * 販売価格が空なら販売価格の部分は無い（summaryLabel() のとおり）。
     */
    public function optionLabel(): string
    {
        return $this->summaryLabel() . ' ' . $this->status->label();
    }

    /**
     * その区画だけを成約にし、その仕入れ案件のステータスを集約する（設計書 §5.4。null・無い区画なら何もしない）。
     * ⚠ 区画のステータスは紐付けに付いてくる。紐づけ先の区画を付け替えたら、旧の区画を markOnSale()・新の区画を markSold()。
     */
    public static function markSold(?int $lotId): void
    {
        self::mark($lotId, LotStatus::Sold);
    }

    /** その区画だけを販売中にし、その仕入れ案件のステータスを集約する（null・無い区画なら何もしない） */
    public static function markOnSale(?int $lotId): void
    {
        self::mark($lotId, LotStatus::OnSale);
    }

    private static function mark(?int $lotId, LotStatus $status): void
    {
        $lot = $lotId === null ? null : self::find($lotId);
        if ($lot === null) {
            return;
        }

        self::query()->whereKey($lot->id)->update(['status' => $status->value]);
        ReProcurement::find($lot->procurement_id)?->syncStatusFromLots();
    }

    /**
     * その仕入れ案件の区画だけを成約にする（null・区画なしなら何もしない）。
     * ⚠ 仕入れ案件の全区画を動かす古い形（1 案件 1 区画の前提）。呼ぶ側を markSold() へ移したら消す（計画の Task 8）。
     */
    public static function markSoldFor(?int $procurementId): void
    {
        self::markFor($procurementId, LotStatus::Sold);
    }

    /**
     * その仕入れ案件の区画だけを販売中にする（null・区画なしなら何もしない）。
     * ⚠ 古い形。呼ぶ側を markOnSale() へ移したら消す（計画の Task 8）。
     */
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
