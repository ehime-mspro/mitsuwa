<?php

namespace App\Models;

use App\Enums\ZealSimulationCalcType;
use App\Enums\ZealSimulationGroup;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ZEAL 試算表 項目マスター
 *
 * 試算表の縦軸（賃料・委託費・売上・会員数 等）を表すマスタ。
 * カスタマイズ可能（追加・編集・並び替え・無効化）。
 *
 * group_type と calc_type の組み合わせで挙動が決まる:
 *   - Revenue + Manual: 売上（実績連動 or 手入力）
 *   - Member + Manual: 会員数（実績連動 or 手入力）
 *   - Expense + Fixed: 固定費（毎月同額）
 *   - Expense + RevenueLinked: 売上 × rate_percent
 *   - Expense + Manual: 変動費（月ごと手入力）
 *   - Summary + Calculated: 経費計・営業利益・累計利益（is_system=1）
 */
class ZealSimulationCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'group_type',
        'calc_type',
        'default_amount',
        'rate_percent',
        'sort_order',
        'is_system',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'group_type'     => ZealSimulationGroup::class,
            'calc_type'      => ZealSimulationCalcType::class,
            'default_amount' => 'integer',
            'rate_percent'   => 'decimal:3',
            'sort_order'     => 'integer',
            'is_system'      => 'boolean',
            'is_active'      => 'boolean',
        ];
    }

    /**
     * 試算表セルとのリレーション
     */
    public function values()
    {
        return $this->hasMany(ZealSimulationValue::class, 'category_id');
    }

    /**
     * 有効な項目のみ並び順で取得
     */
    public static function activeOrdered()
    {
        return static::where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }
}
