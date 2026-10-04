<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReCostItem extends Model
{
    use HasFactory;

    protected $table = 're_cost_items';

    /**
     * 購入価格から自動で計上する項目の名前。ReProcurement / ReProject::syncPropertyPurchaseCost() が
     * この名前で項目を引く（無ければ作る）ので、マスタで名前を変えたり消したりすると、次の保存で項目が作り直され、
     * 物件購入費の原価行が二重になる。マスタの画面（Admin\ReCostItemController）はこの項目を変更・削除させない。
     * ⚠ 同じ文字列がほかに 15 か所ほど直書きされている（定数へ寄せるのは別の作業）。
     */
    public const PROPERTY_PURCHASE = '物件購入費';

    protected $fillable = [
        'name',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active'  => 'boolean',
        ];
    }

    /** 購入価格から自動で計上する項目か */
    public function isPropertyPurchase(): bool
    {
        return $this->name === self::PROPERTY_PURCHASE;
    }

    // ============================================================
    // スコープ
    // ============================================================

    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
