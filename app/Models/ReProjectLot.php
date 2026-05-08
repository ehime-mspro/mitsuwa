<?php

namespace App\Models;

use App\Enums\LotStatus;
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
     * 販売坪単価の表示用（@XX.X — 万円単位、小数点第2位切り上げ）
     * 例: 326,729 円/坪 → "@32.7"、101,500 円/坪 → "@10.2"
     */
    public function getSellingPricePerTsuboFormatted(): ?string
    {
        if ($this->selling_price_per_tsubo === null) {
            return null;
        }
        $man = ceil($this->selling_price_per_tsubo / 1000) / 10;
        return '@' . number_format($man, 1);
    }

    /**
     * ㎡ → 坪変換（1坪 = 3.30579㎡）
     */
    public static function sqmToTsubo(float $sqm): float
    {
        return round($sqm / 3.30579, 2);
    }
}
