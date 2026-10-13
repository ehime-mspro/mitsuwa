<?php

namespace App\Models;

use App\Support\Approval\AmountTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 取り込んだ過去の紙の決裁（要件 11.2・段階5 設計書 §5.9〜§5.15・D10）。
 *
 * ⚠ 申請（approval_requests）とは別の表。回覧の操作はできない。申請の表を読むホーム・自分の申請・⑩・お知らせ・催促には出ない
 *   （D10。台帳・Excel・関連する決裁No・紙の決裁の詳細だけが読む）。
 * ⚠ 決裁No（`number`）は年度・部門・ＮＯ から組み立てる（`ApprovalNumber::paperNumber()`）。紙の番号どうし・システムの申請の番号と
 *   重ならない（取り込みと 1 件ずつの登録が確かめる）。
 * ⚠ 決裁日（`decided_on`）は日付（時刻なし）。表示は `->format('Y/m/d')`（JapanTime::format で時差を足さない）。
 * ⚠ `updated_by` は**手で直した人**（取り込みで金額を補っても変えない）。取り込みの取り消しの「あとで手で直した記録」の数に使う。
 */
class ApprovalPaperRecord extends Model
{
    protected $fillable = [
        'department_id', 'fiscal_year', 'seq', 'number', 'decided_on', 'applicant_name', 'subject',
        'amount', 'cost', 'profit', 'note', 'import_id', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'department_id' => 'integer',
            'fiscal_year'   => 'integer',
            'seq'           => 'integer',
            'decided_on'    => 'date',
            'amount'        => 'integer',
            'cost'          => 'integer',
            'profit'        => 'integer',
            'import_id'     => 'integer',
            'created_by'    => 'integer',
            'updated_by'    => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ApprovalDepartment::class, 'department_id');
    }

    /** 足した取り込み（手で登録したものは空） */
    public function import(): BelongsTo
    {
        return $this->belongsTo(ApprovalPaperImport::class, 'import_id');
    }

    /** 登録した人（⚠ 利用者は SoftDeletes。Top trap #18） */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** 手で直した人 */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    /** 粗利率（金額と粗利益から。申請の明細表と同じ計算＝整数で四捨五入。金額が空か 0・粗利益が空なら空） */
    public function rate(): ?float
    {
        return $this->amount === null || $this->profit === null ? null : AmountTable::rate($this->amount, $this->profit);
    }
}
