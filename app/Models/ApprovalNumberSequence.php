<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 部門・年度ごとの連番（要件 6・設計書 §5.9）。
 *
 * ⚠ 読み書きは `App\Support\Approval\ApprovalNumber` だけが行う（行をロックして採るため）。
 * `next_without_trials` は試しの採番だけを除いた次の番号（段階6 設計書 §5.7。6a で足した）。
 */
class ApprovalNumberSequence extends Model
{
    protected $fillable = ['department_id', 'fiscal_year', 'next_number', 'last_issued', 'next_without_trials'];

    protected function casts(): array
    {
        return [
            'department_id'       => 'integer',
            'fiscal_year'         => 'integer',
            'next_number'         => 'integer',
            'last_issued'         => 'integer',
            'next_without_trials' => 'integer',
        ];
    }
}
