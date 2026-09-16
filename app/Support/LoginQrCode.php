<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use RuntimeException;

/**
 * ログイン画面の QR コードを SVG で作る（設計書 §5.12・D4）。
 *
 * ⚠ **`<svg>` 丸ごとではなく viewBox と中身を返す。** 1 枚 約 10KB あり、200 人ぶんを
 *   ページごとに繰り返すと約 1.9MB になる（2026-09-16 実測）。案内のページは
 *   `<symbol>` を 1 つ置いて各ページは `<use>` で参照する。
 *
 * ⚠ 出力の形が変わったら**黙って壊れず例外にする**（部品を上げたときに気づけるように）。
 */
final class LoginQrCode
{
    /**
     * @return array{viewBox: string, inner: string}
     */
    public static function symbolParts(string $url): array
    {
        $options = new QROptions([
            'outputInterface'      => QRMarkupSVG::class,
            'outputBase64'         => false,
            // インラインで埋めるので XML 宣言は要らない
            'svgAddXmlHeader'      => false,
            // CSS に頼らず fill="#000" を属性で入れる（印刷で色が落ちない）
            'svgUseFillAttributes' => true,
            'connectPaths'         => true,
            // 明るいマスは描かない（紙は白なので不要。ファイルも小さくなる）
            'drawLightModules'     => false,
            'eccLevel'             => EccLevel::L,
            'addQuietzone'         => true,
            'quietzoneSize'        => 2,
            'cssClass'             => 'login-qr',
        ]);

        $svg = (new QRCode($options))->render($url);

        if (! preg_match('#<svg[^>]*\sviewBox="([^"]+)"[^>]*>(.*)</svg>#s', $svg, $m)) {
            throw new RuntimeException('QR コードの SVG を解釈できませんでした（部品の出力形式が変わった可能性があります）。');
        }

        return ['viewBox' => $m[1], 'inner' => trim($m[2])];
    }
}
