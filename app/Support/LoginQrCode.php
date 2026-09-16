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
            // ⚠ `<symbol>` ＋ `<use>` にしたので、QR の実体はページ全体で 1 回しか出ない
            //    ＝ 訂正レベルを上げても 200 人ぶんの大きさはほとんど変わらない。
            //    紙は折れたりかすれたりするので、既定の M（15%）で訂正耐性を取る。
            'eccLevel'             => EccLevel::M,
            'addQuietzone'         => true,
            'quietzoneSize'        => 2,
            'cssClass'             => 'login-qr',
        ]);

        $svg = (new QRCode($options))->render($url);

        // ⚠ 貪欲な `.*` は `<svg>` が 2 つあると最後の `</svg>` まで飲み込み、壊れた中身を
        //    黙って返す（実測）。「形が変わったら例外」の約束を本当にするため数も見る。
        if (substr_count($svg, '<svg') !== 1
            || ! preg_match('#<svg[^>]*\sviewBox="([^"]+)"[^>]*>(.*)</svg>#s', $svg, $m)) {
            throw new RuntimeException('QR コードの SVG を解釈できませんでした（部品の出力形式が変わった可能性があります）。');
        }

        return ['viewBox' => $m[1], 'inner' => trim($m[2])];
    }
}
