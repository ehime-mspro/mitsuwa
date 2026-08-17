<?php

namespace App\Models;

use App\Enums\MsContractStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MsContract extends Model
{
    protected $fillable = [
        'room_id', 'tenant_id', 'status', 'contract_date', 'move_in_date', 'move_out_date',
        'rent', 'common_fee', 'deposit', 'key_money', 'staff_user_id', 'memo',
        'termination_reason', 'restoration_cost', 'cleaning_cost', 'deposit_at_settlement',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => MsContractStatus::class,
            'contract_date' => 'date',
            'move_in_date' => 'date',
            'move_out_date' => 'date',
            'rent' => 'integer',
            'common_fee' => 'integer',
            'deposit' => 'integer',
            'key_money' => 'integer',
            'restoration_cost' => 'integer',
            'cleaning_cost' => 'integer',
            'deposit_at_settlement' => 'integer',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(MsRoom::class, 'room_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(MsTenant::class, 'tenant_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id')->withTrashed();
    }

    public function parkingContracts(): HasMany
    {
        return $this->hasMany(MsParkingContract::class, 'contract_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(MsContractRevision::class, 'contract_id')->orderByDesc('revision_date');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(MsContractDeduction::class, 'contract_id')->orderBy('sort_order')->orderBy('id');
    }

    public function isTerminated(): bool
    {
        return $this->status === MsContractStatus::Terminated;
    }

    /**
     * 敷金精算の記録があるか（解約時に入力された場合のみ true）。
     *
     * ⚠ 「0 円」と「未入力」を区別するため、`?? 0` で潰さず `!== null` で見る。
     *   `ConvertEmptyStringsToNull` により空欄は null で保存される。
     */
    public function hasSettlement(): bool
    {
        return $this->deposit_at_settlement !== null
            || $this->restoration_cost !== null
            || $this->cleaning_cost !== null
            || $this->termination_reason !== null
            || $this->deductions()->exists();
    }

    /**
     * 差引合計（原状回復 + 清掃 + その他差引項目）。
     *
     * ⚠ **合計は DB に保存しない。** 保存すると内訳と合計が別ソースになり無音で食い違う
     *   （Bug #46 を本番で踏んでいる）。必ずここで内訳から積み上げる。
     */
    public function totalDeduction(): int
    {
        return (int) $this->restoration_cost
            + (int) $this->cleaning_cost
            + (int) $this->deductions->sum('amount');
    }

    /**
     * 返金額（精算時点の敷金 − 差引合計）。マイナスは入居者へ請求。
     *
     * ⚠ 現在の `deposit` ではなく `deposit_at_settlement` を使う。
     *   `deposit` は解約後も編集でき、書き換えられると返金の根拠が動いてしまう。
     */
    public function refundAmount(): int
    {
        return (int) $this->deposit_at_settlement - $this->totalDeduction();
    }
}
