<?php

namespace App\Models;

use App\Enums\CustomOrderFileCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HsCustomOrderFile extends Model
{
    use HasFactory;

    protected $table = 'hs_custom_order_files';

    protected $fillable = [
        'custom_order_id',
        'category',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'category'  => CustomOrderFileCategory::class,
            'file_size' => 'integer',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    public function customOrder(): BelongsTo
    {
        return $this->belongsTo(HsCustomOrder::class, 'custom_order_id');
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
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
