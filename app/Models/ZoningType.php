<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 用途地域マスター
 * 不動産の用途地域を管理するマスターテーブル
 */
class ZoningType extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'sort_order'];
}
