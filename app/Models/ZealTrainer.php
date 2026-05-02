<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ZEAL トレーナーマスタ（セレクトボックス用の最小マスタ）*/
class ZealTrainer extends Model
{
    protected $fillable = [
        'name', 'display_order', 'active',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'active'        => 'boolean',
        ];
    }

    /** このトレーナーが担当している会員 */
    public function members(): HasMany
    {
        return $this->hasMany(ZealMember::class, 'trainer_id');
    }
}
