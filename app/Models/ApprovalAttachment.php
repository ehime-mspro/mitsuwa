<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 添付（要件 5.3・14.3・設計書 §5.7）。ファイルは `local` ディスク（storage/app/private）。
 *
 * ⚠ ファイルを**上書きしない**（夜間バックアップは同じパス・同じ大きさなら送り直さない。CLAUDE.md）。
 * ⚠ 一度でも提出した申請の添付は、外しても行とファイルを残す（計画 §0.5）。
 */
class ApprovalAttachment extends Model
{
    public const UPDATED_AT = null;

    /** 1 ファイルの上限（KB。基幹の添付と同じ 10MB） */
    public const MAX_KB = 10240;

    /** 1 件の申請に付けられる数（設計書 D14） */
    public const MAX_COUNT = 20;

    /** 受け付ける拡張子 → 返すときの Content-Type（送られてきた MIME は信じない） */
    public const TYPES = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv'  => 'text/csv',
        'txt'  => 'text/plain',
    ];

    /** ブラウザで開くもの（それ以外はダウンロード。HEIC は Safari 以外で表示できないので外す。D14） */
    public const INLINE = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];

    protected $fillable = [
        'request_id', 'original_name', 'stored_path', 'mime', 'size', 'uploaded_by', 'added_round', 'removed_round', 'removed_at',
    ];

    protected function casts(): array
    {
        return ['request_id' => 'integer', 'size' => 'integer', 'uploaded_by' => 'integer', 'added_round' => 'integer', 'removed_round' => 'integer', 'removed_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->stored_path, PATHINFO_EXTENSION));
    }

    public function opensInline(): bool
    {
        return in_array($this->extension(), self::INLINE, true);
    }

    /** 大きさの表示（例: 1.2 MB・340 KB） */
    public function sizeLabel(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1) . ' MB'
            : max(1, (int) ceil($this->size / 1024)) . ' KB';
    }
}
