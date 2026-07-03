<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BuyerSurvey extends Model
{
    protected $fillable = [
        'buyer_id',
        'department',
        'survey_date',
        'project_id',
        'staff_user_id',
        'staff_name',
        'memo',
    ];

    protected $casts = [
        'survey_date' => 'date',
    ];

    /* ========== リレーション ========== */

    public function buyer()
    {
        return $this->belongsTo(Buyer::class, 'buyer_id');
    }

    public function answers()
    {
        return $this->hasMany(BuyerSurveyAnswer::class, 'survey_id');
    }

    public function project()
    {
        return $this->belongsTo(\App\Models\ReProject::class, 'project_id');
    }

    public function staff()
    {
        return $this->belongsTo(\App\Models\User::class, 'staff_user_id')->withTrashed();
    }

    /* ========== スコープ ========== */

    public function scopeOfDepartment($query, string $dept)
    {
        return $query->where('department', $dept);
    }
}
