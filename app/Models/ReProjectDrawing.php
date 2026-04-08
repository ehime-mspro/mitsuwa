<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReProjectDrawing extends Model
{
    use HasFactory;

    protected $table = 're_project_drawings';

    protected $fillable = [
        'project_id',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    public function project(): BelongsTo
    {
        return $this->belongsTo(ReProject::class, 'project_id');
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ============================================================
    // ヘルパー
    // ============================================================

    /**
     * ファイルサイズを読みやすい形式で返す
     */
    public function getFileSizeFormatted(): string
    {
        $bytes = $this->file_size;
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1048576, 1) . ' MB';
    }

    /**
     * 画像ファイルかどうか
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
