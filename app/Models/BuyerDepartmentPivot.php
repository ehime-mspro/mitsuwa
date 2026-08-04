<?php

namespace App\Models;

use App\Enums\BuyerDepartment;
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

    /**
     * ⚠ department も enum にキャストする。実 DB は enum('housing','realestate') だが
     *    テストの SQLite は string なので、キャストが無いと範囲外の値が**テストだけ素通り**する
     *    （デプロイ後にしか分からない。docs/RULES.md Bug #40 と同型）。
     *    キャストがあれば Laravel が PHP レベルで ValueError を投げるので DB エンジンに依存しない。
     *
     * ⚠ キャスト済み属性を BuyerDepartment::from() / tryFrom() に渡さないこと（Bug #22）。
     *    そのまま enum として使う。読み手は buyers/show.blade.php の部署バッジのみ。
     */
    protected $casts = [
        'acquired_date' => 'date',
        'department'    => BuyerDepartment::class,
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
