<?php

namespace App\Models;

use App\Enums\LotStatus;
use App\Support\AreaConverter;
use App\Support\TsuboPrice;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReProjectLot extends Model
{
    use HasFactory;

    protected $table = 're_project_lots';

    protected $fillable = [
        'project_id',
        'lot_number',
        'area_sqm',
        'area_tsubo',
        'selling_price_per_tsubo',
        'selling_price',
        'is_price_manual',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'area_sqm'                => 'decimal:2',
            'area_tsubo'              => 'decimal:2',
            'selling_price_per_tsubo' => 'integer',
            'selling_price'           => 'integer',
            'is_price_manual'         => 'boolean',
            'status'                  => LotStatus::class,
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    public function project(): BelongsTo
    {
        return $this->belongsTo(ReProject::class, 'project_id');
    }

    // ============================================================
    // ヘルパー
    // ============================================================

    /**
     * 販売坪単価の表示用（@XX.X — 万円単位、小数第2位を切り上げ）
     * 例: 9,880,000円 / 33.33坪 = 296,429.64…円/坪 → "@29.7"
     *
     * ⚠ 保存済みの selling_price_per_tsubo（円/坪の整数）からは計算しない。
     *    あれは丸め済みなので、そこから万円へ切り上げると二段階丸めになり
     *    「必ず切り上げ」が破れる（TsuboPrice の docblock 罠①）。
     *    販売価格と坪数から一度だけ丸める。
     */
    public function getSellingPricePerTsuboFormatted(): ?string
    {
        if ($this->selling_price === null || $this->selling_price <= 0) {
            return null;
        }

        return TsuboPrice::perTsuboManLabel((int) $this->selling_price, $this->area_tsubo);
    }

    /**
     * ㎡ → 坪変換（㎡ × 0.3025 の切り捨て。AreaConverter の docblock 参照）
     *
     * ⚠ このモデルの area_tsubo は算出値ではなく DB 保存カラムで、
     *    ProjectController の区画 store / update がこのメソッドの戻り値を書き込む。
     *    換算式を変えたら既存行の一括更新も要る。
     */
    public static function sqmToTsubo(float $sqm): float
    {
        return AreaConverter::sqmToTsubo($sqm);
    }
}
