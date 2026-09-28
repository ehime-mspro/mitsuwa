<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
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

    /** 古いブラウザ用の代わりの名前を名前から作れないときに使う名前 */
    private const FALLBACK_BASE_NAME = 'attachment';

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
     * Content-Disposition は Laravel に任せず、ここで組んで渡す（代わりの名前は asciiFallbackName()）。
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
        $fallback    = self::asciiFallbackName($fileName, $path);

        if ($forceDownload || $contentType === null) {
            return $storage->download($path, $fileName, [
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition'    => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName, $fallback),
            ]);
        }

        return $storage->response($path, $fileName, [
            // DB の mime_type をそのまま渡さない。許可リストの正規化値以外は構造上入らない。
            'Content-Type'           => $contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $fileName, $fallback),
        ]);
    }

    /**
     * 古いブラウザ用の ASCII の代わりの名前（Content-Disposition の filename=）。実名は filename*（UTF-8）が運ぶ。
     *
     * ⚠ Laravel（FilesystemAdapter::fallbackName()）に任せない。Laravel は str_replace('%', '', Str::ascii($name)) で作るが、
     *   Str::ascii() は仮名・漢字・絵文字・全角の英数字を消すので、「見積書」「😀」「Ａ４図面」のように拡張子の無い名前は
     *   空になり、Symfony の makeDisposition() が例外を投げて開くのもダウンロードも 500 になる。保存は名前の拡張子を
     *   見ない（mimes は中身から推測した拡張子を見る）ので、こうした名前は普通に保存できてしまう。
     * ⚠ 使える間は Laravel と同じ値を返す（今まで開けていた名前の見出しは 1 文字も変えない）。trim した値は返さない
     *   （「見積書 (1).pdf」の ` (1).pdf` が `(1).pdf` に変わる）。trim は空白しか残らないかの判定にだけ使う。
     * 空白しか残らないか、印字できる ASCII 以外が残る（Str::ascii() は \x10 と \x13 を残す）ときは「attachment.保存先の拡張子」
     * にする。保存先は store() が「ランダムな名前＋中身から推測した拡張子」で付けるので、名前に拡張子が無くても拡張子がある。
     */
    private static function asciiFallbackName(string $fileName, string $path): string
    {
        // Symfony は代わりの名前に % と / と \ を許さない。⚠ / と \ を除くのは今は効いていない守り（Str::ascii() が / や \ を
        //   作る文字は実測で無く、名前も UploadedFile が / と \ より前を落とす）。消してもテストは緑のまま（等価な変異）
        $fallback = str_replace(['%', '/', '\\'], '', Str::ascii($fileName));
        if (trim($fallback) !== '' && preg_match('/^[\x20-\x7e]+$/', $fallback)) {
            return $fallback;
        }

        // ⚠ strtolower は今は効いていない（store() の付ける拡張子は元から小文字。消してもテストは緑のまま＝等価な変異）
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]+$/', $extension)
            ? self::FALLBACK_BASE_NAME . '.' . $extension
            : self::FALLBACK_BASE_NAME;
    }
}
