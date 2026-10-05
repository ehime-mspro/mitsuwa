<?php

namespace App\Support\Approval;

/**
 * 印を SVG で描く（要件 9.1 の図・段階4 設計書 D12）。**申請の詳細・利用者の管理（⑦）・PDF で同じものを使う。**
 *
 * 赤い丸の中を 2 本の横線で 3 段に分け、上段＝部署・中段＝日付・下段＝印に使う文字。
 * 文字が多いときは段の幅に収まるよう小さくする（D8）。座標は 120 × 120 の箱で決め、大きさは `$size` で変える。
 *
 * ⚠ 文字は必ず e() を通す（下段は利用者の氏名から来る）。
 * ⚠ 色は画面の配色（CSS の変数）を使わない。PDF（mPDF）は CSS の変数を読めず、印は紙の朱肉と同じ色で出す。
 * ⚠ PDF（書体が PDF_FONT のとき）は viewBox を描く大きさ（px）に合わせ、120 の箱の中身を `scale()` の組で縮める。
 *   mPDF は `text-anchor="middle"` の文字の幅を「描く大きさの px」で測り、それを SVG の座標の長さとして使うため、
 *   viewBox が 120 のまま 80 px に縮めると幅が縮尺の分（80/120）だけ短く測れ、文字が円の中心より右に寄る（2026-10-05 の試し）。
 *   画面（ブラウザ）は viewBox が 120 のままで中央に収まる。
 */
final class StampSvg
{
    public const COLOR = '#C4382E';

    /** 画面の書体（端末の明朝体） */
    public const SCREEN_FONT = "'Hiragino Mincho ProN','Yu Mincho','YuMincho','Noto Serif JP',serif";

    /** PDF の書体（mPDF に登録した IPAex 明朝の名前。ApprovalPdf の fontdata と同じ） */
    public const PDF_FONT = 'ipaexm';

    /** 座標を決める箱の一辺 */
    private const BOX = 120;

    public static function render(Stamp $stamp, string $font = self::SCREEN_FONT, string $size = '48'): string
    {
        $forPdf = $font === self::PDF_FONT;   // $font は次の行で e() を通す前に見る

        $label = e($stamp->label);
        $date  = e($stamp->date);
        $text  = e($stamp->text);
        $font  = e($font);
        $color = self::COLOR;
        $aria  = e(trim("{$stamp->label} {$stamp->date} {$stamp->text}") . ' の印');

        $body = '<circle cx="60" cy="60" r="54" fill="none" stroke="' . $color . '" stroke-width="3"/>'
            . '<line x1="8" y1="42" x2="112" y2="42" stroke="' . $color . '" stroke-width="2.5"/>'
            . '<line x1="8" y1="78" x2="112" y2="78" stroke="' . $color . '" stroke-width="2.5"/>'
            . self::text(60, 34, self::fit($stamp->label, 66, 20), $label, $font, $color)
            . self::text(60, 67, 16, $date, $font, $color)
            . self::text(60, 101, self::fit($stamp->text, 62, 24), $text, $font, $color);

        // 画面は 120 の箱のまま。PDF は箱を描く大きさ（px）にして、中身を縮める（クラスの説明の ⚠）
        $viewBox = $forPdf ? e($size) : self::BOX;
        $inner   = $forPdf ? '<g transform="scale(' . self::scale($size) . ')">' . $body . '</g>' : $body;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $viewBox . ' ' . $viewBox . '" width="' . e($size) . '" height="' . e($size) . '" role="img" aria-label="' . $aria . '">'
            . $inner
            . '</svg>';
    }

    /** 段の幅（120 の箱の単位）に収まる文字の大きさ（上限 $max） */
    public static function fit(string $text, int $width, int $max): int
    {
        return max(8, min($max, intdiv($width, max(1, mb_strlen($text)))));
    }

    /** 描く大きさ（px）に 120 の箱を合わせる倍率（例 80 → 0.666667） */
    private static function scale(string $size): string
    {
        return rtrim(rtrim(number_format((float) $size / self::BOX, 6, '.', ''), '0'), '.');
    }

    private static function text(int $x, int $y, int $fontSize, string $escaped, string $font, string $color): string
    {
        return '<text x="' . $x . '" y="' . $y . '" text-anchor="middle" font-family="' . $font . '" font-size="' . $fontSize . '" fill="' . $color . '">' . $escaped . '</text>';
    }
}
