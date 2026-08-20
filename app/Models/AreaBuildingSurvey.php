<?php

namespace App\Models;

use App\Support\VacancyRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 周辺ビルの調査回（時点情報）。SoftDeletes は持たない（物理削除。設計 §3.2）。
 */
class AreaBuildingSurvey extends Model
{
    protected $fillable = [
        'area_building_id',
        'surveyed_month',
        'operating_count',
        'vacant_count',
        'unknown_count',
        'surveyed_by',
        'notes',
    ];

    /**
     * DB 側の DEFAULT 0（area_building_surveys.operating_count/vacant_count/unknown_count）を
     * PHP 側にも反映する。
     *
     * ⚠ プランのコードには無かったが実装時に実測で必要と判明した（load-bearing）。
     *   Eloquent の create() は「渡された属性だけ」で INSERT 文を組む。DB は省略された
     *   カラムに DEFAULT 0 を適用するが、それは DB 側の話でしかなく、fresh()/refresh() で
     *   読み直すまで **in-memory の $this->operating_count 等は null のまま**。
     *   vacancyRate() はここを VacancyRate::percent(int $operating, ...) という非 nullable
     *   int 引数へそのまま渡すため、3 項目すべて未指定で create() した直後（fresh() を
     *   挟まない）に呼ぶと TypeError になる（実測: test_survey_without_units_has_no_rate）。
     */
    protected $attributes = [
        'operating_count' => 0,
        'vacant_count'    => 0,
        'unknown_count'   => 0,
    ];

    protected function casts(): array
    {
        return [
            'surveyed_month'  => 'date',
            'operating_count' => 'integer',
            'vacant_count'    => 'integer',
            'unknown_count'   => 'integer',
        ];
    }

    /**
     * ⚠ surveyed_month は DATE 型だが意味は「年月」。日を 01 に正規化しないと
     *   UNIQUE(area_building_id, surveyed_month) が同じ月の重複を止められなくなる。
     *   create / update の両方を通したいので saving フックに置く。
     */
    protected static function booted(): void
    {
        static::saving(function (AreaBuildingSurvey $survey): void {
            if ($survey->surveyed_month !== null) {
                $survey->surveyed_month = Carbon::parse($survey->surveyed_month)->startOfMonth();
            }
        });
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(AreaBuilding::class, 'area_building_id')->withTrashed();
    }

    /** ⚠ User は SoftDeletes。付け忘れると退職者が調査した行の調査者欄が空になる */
    public function surveyor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'surveyed_by')->withTrashed();
    }

    public function totalUnits(): int
    {
        return $this->operating_count + $this->vacant_count + $this->unknown_count;
    }

    /** ⚠ 計算式をここに書かない。VacancyRate の 1 箇所を通す（Bug #41） */
    public function vacancyRate(): ?float
    {
        return VacancyRate::percent($this->operating_count, $this->vacant_count, $this->unknown_count);
    }

    public function vacancyRateLabel(): string
    {
        return VacancyRate::label($this->operating_count, $this->vacant_count, $this->unknown_count);
    }

    /**
     * 入居率のラベル。⚠ 「営業 ÷ 総数」で独立に出さないこと。
     *   画面では空室率と並べて出すので、和が 100.0% にならない行が出てはいけない（Bug #46）。
     *   計算式は VacancyRate 1 箇所を通す（Bug #41）。
     */
    public function occupancyRateLabel(): string
    {
        return VacancyRate::occupancyLabel($this->operating_count, $this->vacant_count, $this->unknown_count);
    }

    public function monthLabel(): string
    {
        return $this->surveyed_month === null ? '—' : $this->surveyed_month->format('Y年n月');
    }

    /** <input type="month"> の value 用 */
    public function monthInputValue(): ?string
    {
        return $this->surveyed_month?->format('Y-m');
    }
}
