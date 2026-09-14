<?php

namespace App\Models;

use App\Enums\DepartmentCode;
use App\Enums\OperationStatus;
use App\Enums\OwnerType;
use App\Enums\PropertyType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * テナントの物件。
 *
 * ⚠ **関連データ（区画・契約・投資・修繕・問合せ）が残る物件は削除させない**（docs/RULES.md Bug #59）。
 *   守る不変条件は「削除済みの物件は、生きている区画・契約・投資・修繕・問合せを持たない」。
 *   削除の歯止め（deletionBlockers()）と、登録・更新の入力チェック（property_id の exists に withoutTrashed()）の 2 つで守る。
 *   使わなくなった物件は、稼働状態を「非稼働」にして残す。
 *
 * ⚠ そのため、子から物件を読むリレーション（Unit / Investment / Repair / Inquiry などの property()）には
 *   **withTrashed() を付けていない**（区画 Unit の Bug #58 とは別の方式）。削除済みの物件を指す子は作られないので、
 *   画面側で削除済みの物件を扱う必要が無い。付けると whereHas('property') が削除済みの物件まで拾い、
 *   一覧・ダッシュボードの集計の意味が変わる。Contract::property() だけは以前から withTrashed() を持つ。
 */
class Property extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * 物件の削除を止める関連データ（リレーション名 => 画面での呼び名）。並びは断りの文言の並び。
     * 論理削除済みの子は数えない（それぞれの count() が既定のスコープで除く）。
     */
    public const DELETION_BLOCKING_RELATIONS = [
        'units'       => '区画',
        'contracts'   => '契約',
        'investments' => '投資',
        'repairs'     => '修繕',
        'inquiries'   => '問合せ',
    ];

    protected $fillable = [
        'code',
        'name',
        'property_type',
        'department',
        'operation_status',
        'postal_code',
        'address',
        'structure',
        'built_date',
        'total_floors',
        'total_units',
        'total_area',
        'owner_type',
        'owner_name',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'property_type' => PropertyType::class,
            'department' => DepartmentCode::class,
            'operation_status' => OperationStatus::class,
            'owner_type' => OwnerType::class,
            'total_floors' => 'integer',
            'total_units' => 'integer',
            'total_area' => 'decimal:2',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    /**
     * この物件の区画一覧
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /**
     * この物件の契約一覧
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * この物件の変更履歴
     */
    public function changeLogs(): HasMany
    {
        return $this->hasMany(PropertyChangeLog::class);
    }

    /**
     * この物件の投資案件
     */
    public function investments(): HasMany
    {
        return $this->hasMany(Investment::class);
    }

    /**
     * この物件の一般修繕
     */
    public function repairs(): HasMany
    {
        return $this->hasMany(Repair::class);
    }

    /**
     * この物件の問合せ
     */
    public function inquiries(): HasMany
    {
    return $this->hasMany(Inquiry::class);
    }

    /**
     * この物件の収支データ
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * 添付ファイル（ポリモーフィック）
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
    

    // ============================================================
    // アクセサ / ヘルパー
    // ============================================================

    /**
     * 稼働中かどうか
     */
    public function isActive(): bool
    {
        return $this->operation_status === OperationStatus::Active;
    }

    /**
     * ビル型かどうか（総階数があれば）
     */
    public function isBuildingType(): bool
    {
        return $this->total_floors !== null && $this->total_floors > 0;
    }

    /**
     * 削除を止める関連データを「区画 2 件」の形で返す（0 件の種類は出さない）。空なら削除してよい。
     *
     * @return list<string>
     */
    public function deletionBlockers(): array
    {
        $blockers = [];
        foreach (self::DELETION_BLOCKING_RELATIONS as $relation => $label) {
            $count = $this->{$relation}()->count();
            if ($count > 0) {
                $blockers[] = "{$label} {$count} 件";
            }
        }

        return $blockers;
    }
}
