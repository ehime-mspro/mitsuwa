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

class Property extends Model
{
    use HasFactory, SoftDeletes;

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
}
