<?php

namespace App\Support\Approval;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * 決裁申請書の HTML から PDF を作る（mPDF。段階4 設計書 §5.6・D5・D16）。
 *
 * ⚠ 本文は表の行を 1 行ずつに分けて渡す（`PdfSheet::bodyRows()`）。mPDF は表の 1 つの行をページの途中で切れず、
 *   1 つの枠に長い本文を入れると、ページに収めようとして文字を縮めてしまう（2026-10-03 の試しで 444 行が 1 ページになった）。
 * ⚠ 書体の名前（ipaexg・ipaexm）は紙面の CSS と `StampSvg::PDF_FONT` が使う。
 */
final class ApprovalPdf
{
    public const FONT_GOTHIC = 'ipaexg';

    public const FONT_MINCHO = 'ipaexm';

    /**
     * 決裁申請書の PDF（紙面 approvals.requests.pdf と各ページの下 approvals.requests._pdf_footer）。
     * 試す期間は、上の余白に小さく「試し」を出す（approvals.requests._pdf_trial_header。段階6 設計書 §5.6）。@return string PDF のバイト列
     */
    public static function sheet(PdfSheet $sheet): string
    {
        return self::render(
            view('approvals.requests.pdf', ['sheet' => $sheet])->render(),
            view('approvals.requests._pdf_footer', ['sheet' => $sheet])->render(),
            basename($sheet->fileName, '.pdf'),
            $sheet->trial ? view('approvals.requests._pdf_trial_header')->render() : null,
        );
    }

    /**
     * @param string|null $headerHtml 各ページの上の余白（margin_top 12mm の中）に出す HTML。本文と印の位置は変えない
     * @return string PDF のバイト列
     */
    public static function render(string $html, string $footerHtml, string $title, ?string $headerHtml = null): string
    {
        $defaults = (new ConfigVariables())->getDefaults();
        $fonts    = (new FontVariables())->getDefaults();

        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'tempDir'       => config('approval.pdf.temp_dir'),
            'fontDir'       => array_merge($defaults['fontDir'], [config('approval.pdf.font_dir')]),
            'fontdata'      => $fonts['fontdata'] + [
                self::FONT_GOTHIC => ['R' => 'ipaexg.ttf'],
                self::FONT_MINCHO => ['R' => 'ipaexm.ttf'],
            ],
            'default_font'  => self::FONT_GOTHIC,
            'margin_left'   => 12,
            'margin_right'  => 12,
            'margin_top'    => 12,
            'margin_bottom' => 16,
            'margin_header' => 5,
            'margin_footer' => 6,
        ]);

        $mpdf->SetTitle($title);
        $mpdf->SetCreator('決裁申請システム');
        if ($headerHtml !== null) {
            $mpdf->SetHTMLHeader($headerHtml);
        }
        $mpdf->SetHTMLFooter($footerHtml);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
