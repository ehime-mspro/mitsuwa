<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ZEAL 本部 Google Sheets 取り込み履歴
 *
 * 試算表 1 件あたり毎月 売上 / 経費 の 2 種類が取り込まれる。
 * raw_csv に原文を保存しておくことで後から再パース / 監査が可能。
 *
 * - import_type='sales'  : 売上項目清算書 (当月日割売上 / 会費預り金 / ロイヤリティ等)
 * - import_type='expense': 運営費請求根拠 (店舗運営委託費 / 研修 / WEB / 店舗備品費等)
 */
class ZealSheetImport extends Model
{
    /**
     * updated_at は使わない (取り込み履歴は不変)
     */
    public $timestamps = false;

    protected $fillable = [
        'simulation_id',
        'import_type',
        'year_month',
        'raw_csv',
        'parsed_data',
        'imported_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'parsed_data' => 'array',
            'created_at'  => 'datetime',
        ];
    }

    /**
     * 対象試算表
     */
    public function simulation()
    {
        return $this->belongsTo(ZealSimulation::class, 'simulation_id');
    }

    /**
     * 取り込み実施ユーザー
     */
    public function importedBy()
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
