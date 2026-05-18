<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ZEAL 経営試算表ヘッダー
 *
 * 会計年度ごとに 1 件作成（ZEAL は 6 月始まり、App\Support\ZealFiscalYear 参照）。
 * 通常の月次計画値 + 実績反映値を持つ。
 */
class ZealSimulation extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiscal_year',
        'name',
        'notes',
        'sales_sheet_url',
        'expense_sheet_url',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
        ];
    }

    /**
     * セル値とのリレーション
     */
    public function values()
    {
        return $this->hasMany(ZealSimulationValue::class, 'simulation_id');
    }

    /**
     * 作成ユーザー
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 更新ユーザー
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * 本部 Sheet 取り込み履歴
     */
    public function sheetImports()
    {
        return $this->hasMany(ZealSheetImport::class, 'simulation_id');
    }

    /**
     * 表示用の年度ラベル（例: "2025年度（2025/06〜2026/05）"）
     */
    public function getFiscalYearLabelAttribute(): string
    {
        $start = $this->fiscal_year;
        $end   = $start + 1;
        return sprintf('%d年度（%d/06〜%d/05）', $start, $start, $end);
    }
}
