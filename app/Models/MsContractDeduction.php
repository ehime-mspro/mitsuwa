<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 部屋契約 敷金精算の差引項目（原状回復費・清掃費以外の任意項目）。
 *
 * 個数が可変なので子テーブルにしている。固定項目（原状回復費・清掃費・解約理由・
 * 精算時点の敷金）は契約に 1 対 1 なので `ms_contracts` の列に持つ。
 *
 * ⚠ 差引合計・返金額は**保存しない**。`MsContract::totalDeduction()` /
 *   `refundAmount()` が毎回ここから積み上げる（Bug #46）。
 */
class MsContractDeduction extends Model
{
    protected $table = 'ms_contract_deductions';

    protected $fillable = [
        'contract_id', 'name', 'amount', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(MsContract::class, 'contract_id');
    }
}
