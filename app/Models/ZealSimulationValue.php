<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ZEAL 試算表セル値
 *
 * (simulation_id, category_id, year_month) で一意。
 * amount は null（未入力）/ 0 / 正の整数 / 負の整数 のいずれか。
 * is_manual_override は売上・会員数の実績連動値を手動上書きしているかのフラグ。
 */
class ZealSimulationValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'simulation_id',
        'category_id',
        'year_month',
        'amount',
        'is_manual_override',
    ];

    protected function casts(): array
    {
        return [
            'amount'             => 'integer',
            'is_manual_override' => 'boolean',
        ];
    }

    /**
     * 試算表ヘッダー
     */
    public function simulation()
    {
        return $this->belongsTo(ZealSimulation::class, 'simulation_id');
    }

    /**
     * 項目マスター
     */
    public function category()
    {
        return $this->belongsTo(ZealSimulationCategory::class, 'category_id');
    }
}
