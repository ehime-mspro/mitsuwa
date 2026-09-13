<?php

namespace App\Models;

use App\Enums\UnitStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use HasFactory, SoftDeletes;

    /** 論理削除済みの区画の表示名に付ける印（担当者の選択肢と同じ書き方） */
    private const DELETED_SUFFIX = '（削除済み）';

    protected $fillable = [
        'property_id',
        'floor',
        'room_number',
        'display_name',
        'area_tsubo',
        'usage_type_id',
        'status',
        'rent',
        'common_fee',
        'deposit',
        'garbage_fee',
        'pest_control_fee',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'floor' => 'integer',
            'area_tsubo' => 'decimal:2',
            'status' => UnitStatus::class,
            'rent' => 'integer',
            'common_fee' => 'integer',
            'deposit' => 'integer',
            'garbage_fee' => 'integer',
            'pest_control_fee' => 'integer',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    /**
     * 用途（用途マスター）
     */
    public function usageType(): BelongsTo
    {
        return $this->belongsTo(InquiryUsageType::class, 'usage_type_id');
    }

    /**
     * 所属物件
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * この区画の契約一覧
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * この区画の現在有効な契約（契約中のもの）
     */
    public function activeContract(): HasOne
    {
        return $this->hasOne(Contract::class)->where('status', 'active');
    }

    /**
     * この区画の投資案件
     */
    public function investments(): HasMany
    {
        return $this->hasMany(Investment::class);
    }

    /**
     * この区画の一般修繕
     */
    public function repairs(): HasMany
    {
        return $this->hasMany(Repair::class);
    }

    /**
     * この区画の募集家賃改定履歴
     */
    public function rentRevisions(): HasMany
    {
        return $this->hasMany(UnitRentRevision::class);
    }

    // ============================================================
    // スコープ
    // ============================================================

    /**
     * 削除済みでない区画 ＋ 指定した区画（削除済みでも）。
     * 編集画面の選択肢に「そのレコードが今持っている区画」を残すのに使う（User::assignableWith() と同じ考え方）。
     * 残さないと選択が外れ、保存で区画が消える・変わる（docs/RULES.md Bug #58）。
     *
     * ⚠ withTrashed() はクエリ全体から論理削除の除外を外すので、条件はここで括弧にくくる
     *   （呼び出し側の物件・状態などの条件とは AND でつながる）。
     */
    public function scopeIncludingTrashed($query, array $keepIds)
    {
        $keepIds = array_values(array_filter($keepIds, fn ($id) => $id !== null));

        return $query->withTrashed()->where(function ($q) use ($keepIds) {
            $q->whereNull($this->getQualifiedDeletedAtColumn());
            if ($keepIds !== []) {
                $q->orWhereIn($this->getQualifiedKeyName(), $keepIds);
            }
        });
    }

    // ============================================================
    // アクセサ / ヘルパー
    // ============================================================

    /**
     * display_nameを自動生成する（階数 + 号室名）
     * 例: 階数3, 号室A → 「3A」 / 階数-1, 号室A → 「B1A」（地下1階）/ 階数null, 号室A → 「A」
     *
     * ⚠ 表示名は保存時点で階を含む。画面では表示名をそのまま出し、階を前に付けない。
     *   2026-09-11 まで 11 か所が「数字で始まらなければ階を前に付ける」をしていて地下が「-1B1A」に、
     *   修繕の区画選択は条件なしで付けていて地上も「33A」になっていた（docs/RULES.md Bug #57）。
     *   本番の区画 158 件は表示名が全件この関数の結果と一致していた（2026-09-11 実測）。
     */
    public static function generateDisplayName(?int $floor, string $roomNumber): string
    {
        if ($floor !== null) {
            if ($floor < 0) {
                return 'B' . abs($floor) . $roomNumber;
            }
            return $floor . $roomNumber;
        }
        return $roomNumber;
    }

    /**
     * 画面に出す区画名（表示名 ＋ 論理削除済みなら「（削除済み）」）。
     * 投資・修繕・問合せ・契約の画面は削除済みの区画も読むので、区画名はこれで出す（docs/RULES.md Bug #58）。
     *
     * ⚠ 印は DB に保存せず毎回 trashed() で決める。同じ表示名で登録し直すと UnitController::store が
     *   削除済みの行を復元するので、保存すると復元後も印が残る。
     * ⚠ 列を絞って読む（get([...])）ときは deleted_at も取ること。取らないと trashed() が黙って false になり
     *   印が消える（Eloquent の厳格モードは有効にしていない）。
     */
    public function getDisplayLabelAttribute(): string
    {
        return $this->display_name . ($this->trashed() ? self::DELETED_SUFFIX : '');
    }

    /**
     * 月額合計の SQL 式。**下の getMonthlyTotalAttribute() と同じ計算をする。**
     *
     * ⚠ 片方だけ直すと、画面の数字は正しいのに**並び順だけが別の値で並ぶ**（Bug #41）。
     *   画面から気づけないので、UnitListSortTest が両者の一致を固定している。
     * ⚠ COALESCE 済みなので**この式は NULL にならない**。
     *   並び替えで `(… IS NULL)` を前置しても常に false ＝ 死んだ SQL になるので書かないこと。
     * ⚠ `units` が**素の名前で FROM に居る**ことを前提にしている。
     *   `Unit::from('units as u')` や別名付き join を書くと `no such column: units.rent` で落ちる。
     */
    public const MONTHLY_TOTAL_SQL = '(COALESCE(units.rent, 0) + COALESCE(units.common_fee, 0)'
        . ' + COALESCE(units.garbage_fee, 0) + COALESCE(units.pest_control_fee, 0))';

    /**
     * 募集条件の月額合計（家賃 + 共益費 + ゴミ代 + 駆除代）
     * ※敷金は月額ではないため含めない
     */
    public function getMonthlyTotalAttribute(): int
    {
        return ($this->rent ?? 0)
             + ($this->common_fee ?? 0)
             + ($this->garbage_fee ?? 0)
             + ($this->pest_control_fee ?? 0);
    }

    /**
     * 入居中かどうか
     */
    public function isOccupied(): bool
    {
        return $this->status === UnitStatus::Occupied;
    }

    /**
     * 空室かどうか
     */
    public function isVacant(): bool
    {
        return $this->status === UnitStatus::Vacant;
    }
}
