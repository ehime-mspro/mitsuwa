<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ZEAL 店舗マスタ */
class ZealStore extends Model
{
    protected $fillable = [
        'name', 'address', 'phone', 'open_date',
        'display_order', 'active',
    ];

    protected function casts(): array
    {
        return [
            'open_date'     => 'date',
            'display_order' => 'integer',
            'active'        => 'boolean',
        ];
    }

    /** この店舗に所属する会員 */
    public function members(): HasMany
    {
        return $this->hasMany(ZealMember::class, 'store_id');
    }

    /** 在籍中の会員数 */
    public function activeMembersCount(): int
    {
        return $this->members()->whereNull('withdrew_on')->count();
    }
}
