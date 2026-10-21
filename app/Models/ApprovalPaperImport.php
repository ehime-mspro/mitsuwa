<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 過去の紙の決裁の取り込み 1 回分（段階5 設計書 §5.9・§5.10・D9）。取り消しの単位。
 *
 * ⚠ 取り消しても行は消さない（`undone_at`・`undone_by`・`undone_count` を入れる）。いつ・誰が・何件を取り込み、取り消したかを残す。
 */
class ApprovalPaperImport extends Model
{
    protected $fillable = ['file_name', 'fiscal_year', 'added_count', 'filled_count', 'imported_by', 'undone_at', 'undone_by', 'undone_count'];

    protected function casts(): array
    {
        return [
            'fiscal_year'  => 'integer',
            'added_count'  => 'integer',
            'filled_count' => 'integer',
            'imported_by'  => 'integer',
            'undone_at'    => 'datetime',
            'undone_by'    => 'integer',
            'undone_count' => 'integer',
        ];
    }

    /** この取り込みで足した記録（そのあと手で直したものも含む。金額を補っただけの記録は入らない） */
    public function records(): HasMany
    {
        return $this->hasMany(ApprovalPaperRecord::class, 'import_id');
    }

    /** 取り込んだ人（⚠ 利用者は SoftDeletes。Top trap #18） */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by')->withTrashed();
    }

    public function undoer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by')->withTrashed();
    }
}
