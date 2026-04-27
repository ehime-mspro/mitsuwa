<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 構造マスター
 * テナント物件の構造種別を管理するマスターテーブル
 */
class StructureType extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'sort_order'];
}
