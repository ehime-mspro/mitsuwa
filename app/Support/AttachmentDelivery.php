<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 添付ファイルの配信（inline 表示 / 強制ダウンロード）の判断を一箇所に集約する。
 *
 * 画像・PDF はブラウザの別タブで表示し、それ以外は強制ダウンロードする。
 * 保存型 XSS 対策として、Content-Type は DB の mime_type ではなく
 * 許可リストの正規化値のみを使う（アップロード時の mimes 制限と併せて二重防御）。
 */
class AttachmentDelivery
{
    /**
     * ブラウザで安全に inline 表示できる MIME → 配信に使う正規化済み Content-Type。
     *
     * heic/heif は Safari 以外で表示できないため、txt/csv は Excel で開く運用のため除外。
     * svg は script 実行が可能なため絶対に含めない。
     */
    private const INLINE_MIME_TYPES = [
        'image/jpeg'      => 'image/jpeg',
        // image/pjpeg は「キーと値が異なる唯一のエントリ」で、T8（Content-Type 正規化の検証）が依存している
        'image/pjpeg'     => 'image/jpeg',
        'image/png'       => 'image/png',
        'image/gif'       => 'image/gif',
        'image/webp'      => 'image/webp',
        'application/pdf' => 'application/pdf',
    ];

    /**
     * inline 配信に使う正規化済み Content-Type を返す。許可リストに無ければ null。
     * 述語（isInlineViewable）と実際の引きが構造的にズレないよう、正規化はここだけで行う。
     */
    private static function resolveInlineContentType(?string $mimeType): ?string
    {
        return self::INLINE_MIME_TYPES[strtolower((string) $mimeType)] ?? null;
    }

    /**
     * ブラウザの別タブで表示できるファイルか。
     */
    public static function isInlineViewable(?string $mimeType): bool
    {
        return self::resolveInlineContentType($mimeType) !== null;
    }

    /**
     * 添付ファイルのレスポンスを生成する。
     * $forceDownload = true（?download=1）または inline 非対応 MIME の場合は強制ダウンロード。
     */
    public static function make(
        string $path,
        string $fileName,
        ?string $mimeType,
        bool $forceDownload = false,
        string $disk = 'public',
    ): StreamedResponse {
        $storage     = Storage::disk($disk);
        $contentType = self::resolveInlineContentType($mimeType);

        if ($forceDownload || $contentType === null) {
            return $storage->download($path, $fileName, [
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return $storage->response($path, $fileName, [
            // DB の mime_type をそのまま渡さない。許可リストの正規化値以外は構造上入らない。
            'Content-Type'           => $contentType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
