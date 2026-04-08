<?php

namespace App\Models;

use App\Enums\BuyerRank;
use Illuminate\Database\Eloquent\Model;

class BuyerDepartmentPivot extends Model
{
    protected $table = 'buyer_departments';

    public $timestamps = false;

    protected $fillable = [
        'buyer_id',
        'department',
        'acquired_date',
        'rank',
    ];

    protected $casts = [
        'acquired_date' => 'date',
        'rank'          => BuyerRank::class,
    ];

    /* ========== リレーション ========== */

    public function buyer()
    {
        return $this->belongsTo(Buyer::class, 'buyer_id');
    }

    /* ========== アクセサ ========== */

    /**
     * ランクバッジ用インラインスタイル
     */
    public function getRankBadgeStyleAttribute(): string
    {
        return $this->rank ? $this->rank->badgeStyle() : '';
    }
}
