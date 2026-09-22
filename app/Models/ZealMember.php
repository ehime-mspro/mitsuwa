<?php

namespace App\Models;

use App\Enums\ZealAcquisitionSource;
use App\Enums\ZealGender;
use App\Enums\ZealPurpose;
use App\Enums\ZealWithdrawReason;
use App\Support\JapanTime;
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

    /**
     * 年齢を生年月日から算出
     *
     * ⚠ **未来の誕生日（入力ミス）では負の数を返す。** diffInYears() は符号付きの float を返し、
     *   (int) は 0 方向へ切り捨てるため。旧実装の `$birthday->age` も中身は `(int) $this->diffInYears()`
     *   （vendor/nesbot/carbon/src/Carbon/Traits/Date.php:1242）なので負になること自体は前からだが、
     *   **差がちょうど整数年になるときだけ旧実装と値が 1 つ違う**（今日 2025-12-31・誕生日 2026-12-31 →
     *   旧 0 / 新 -1）。旧は「その日の途中の瞬間」と比べるので差が N 年より僅かに小さく 0 方向へ丸まっていた。
     *   2026-09-22 の実測（688,128 通り）: 食い違うのは未来の誕生日 243 件だけで全件が整数年
     *   （Carbon が 2/29 → 3/1 を 1 年ちょうどと数える 24 件を含む）。過去の誕生日で食い違うのは
     *   日本時間 0:00〜8:59 の帯だけ ＝ この改修が直した時差そのもの（JapanBusinessDayTest が固定）。
     * ⚠ `birthday` の入力チェックは `nullable|date` だけで、アプリ全体で `before_or_equal` は 0 件。
     *   足すかどうかは別の判断。
     */
    public function age(): ?int
    {
        if ($this->birthday === null) {
            return null;
        }
        return (int) $this->birthday->diffInYears(JapanTime::today());
    }
}
