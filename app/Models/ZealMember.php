<?php

namespace App\Models;

use App\Enums\ZealAcquisitionSource;
use App\Enums\ZealGender;
use App\Enums\ZealPurpose;
use App\Enums\ZealWithdrawReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ZEAL 会員マスタ
 *
 * current_plan_id は zeal_member_contracts の最新 open contract（period_end IS NULL）を
 * ミラーするキャッシュカラム。真実は zeal_member_contracts。
 * 入会時・プラン変更時・退会時のトランザクション内で必ず同期する。
 */
class ZealMember extends Model
{
    protected $fillable = [
        'store_id', 'gym_inquiry_id',
        'name', 'name_kana',
        'gender', 'birthday',
        'phone', 'email',
        'postal_code', 'address',
        'joined_on', 'withdrew_on',
        'withdraw_reason', 'withdraw_note',
        'current_plan_id', 'trainer_id', 'pair_parent_member_id',
        'acquisition_source', 'purpose', 'memo',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'birthday'           => 'date',
            'joined_on'          => 'date',
            'withdrew_on'        => 'date',
            'gender'             => ZealGender::class,
            'withdraw_reason'    => ZealWithdrawReason::class,
            'acquisition_source' => ZealAcquisitionSource::class,
            'purpose'            => ZealPurpose::class,
        ];
    }

    /** 所属店舗 */
    public function store(): BelongsTo
    {
        return $this->belongsTo(ZealStore::class, 'store_id');
    }

    /** 現行プラン（キャッシュ）*/
    public function currentPlan(): BelongsTo
    {
        return $this->belongsTo(ZealPlan::class, 'current_plan_id');
    }

    /** 担当トレーナー */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(ZealTrainer::class, 'trainer_id');
    }

    /** ペアプランの主契約者（NULL = 通常会員）*/
    public function pairParent(): BelongsTo
    {
        return $this->belongsTo(ZealMember::class, 'pair_parent_member_id');
    }

    /** この会員が主契約者となっているペア会員一覧 */
    public function pairChildren(): HasMany
    {
        return $this->hasMany(ZealMember::class, 'pair_parent_member_id');
    }

    /** プラン契約履歴（SCD Type-2）*/
    public function memberContracts(): HasMany
    {
        return $this->hasMany(ZealMemberContract::class, 'member_id');
    }

    /** 現行契約（period_end IS NULL）*/
    public function currentContract(): HasMany
    {
        return $this->hasMany(ZealMemberContract::class, 'member_id')->whereNull('period_end');
    }

    /** 体験予約（外部 DB 参照）*/
    public function gymInquiry(): BelongsTo
    {
        return $this->belongsTo(GymInquiry::class, 'gym_inquiry_id');
    }

    /** 在籍中かどうか */
    public function isActive(): bool
    {
        return $this->withdrew_on === null;
    }

    /** 年齢を生年月日から算出 */
    public function age(): ?int
    {
        if ($this->birthday === null) {
            return null;
        }
        return $this->birthday->age;
    }
}
