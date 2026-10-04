{{-- 決裁申請書の PDF の紙面（mPDF に渡す HTML。要件 9.2・段階4 設計書 §5.6・D14〜D16）。
     紙の様式（決裁申請書.xls）の枠を使い、使わない欄（決裁区分・項目番号・関連部門）は外す。
     ⚠ mPDF は CSS の一部しか読まない（flex・grid・CSS の変数は使えない）。枠は表で組む。
     ⚠ 本文は罫線の 1 行を表の 1 行にする（mPDF は表の 1 行をページの途中で切れない。PdfSheet::bodyRows()）。
     ⚠ 文字はすべて {{ }} で包む（印は StampSvg が e() で包んだ SVG を返す）。 --}}
@php
    // 紙の「可・条可・差戻・否」「可・保留・否」の欄。判断したものだけ朱の枠にする
    // ⚠ 文中の <span> の枠は mPDF が描き損なうことがある（2026-10-03 の試しで一部の枠が消えた）ので、小さな表で組む
    // ⚠ 朱の枠の CSS は td.mark.on で書く（.on だけだと、詳細度の高い td.mark の黒の細い枠が勝ち、文字だけ朱になる）
    $mark = fn (?string $current, string $label): string => $current === $label ? 'mark on' : 'mark';
@endphp
<style>
    body { font-family: {{ \App\Support\Approval\ApprovalPdf::FONT_GOTHIC }}; font-size: 10pt; color: #000; }
    .mincho { font-family: {{ \App\Support\Approval\ApprovalPdf::FONT_MINCHO }}; }
    .title { font-family: {{ \App\Support\Approval\ApprovalPdf::FONT_MINCHO }}; font-size: 20pt; text-align: center; letter-spacing: 8pt; }
    .status { text-align: center; font-size: 9pt; color: #444; margin-bottom: 3mm; }
    table { border-collapse: collapse; width: 100%; }
    td { border: 0.6pt solid #000; padding: 1.5mm 2mm; vertical-align: top; }
    td.label { font-family: {{ \App\Support\Approval\ApprovalPdf::FONT_MINCHO }}; background-color: #f2f2f2; text-align: center; vertical-align: middle; }
    .gap { height: 3mm; }
    table.marks { width: auto; border-collapse: separate; border-spacing: 1mm 0; }
    td.mark { border: 0.6pt solid #000; padding: 0.3mm 1.5mm; text-align: center; vertical-align: middle; }
    td.mark.on { border: 1.2pt solid {{ \App\Support\Approval\StampSvg::COLOR }}; color: {{ \App\Support\Approval\StampSvg::COLOR }}; }
    td.upper { border-bottom: none; padding-bottom: 0; vertical-align: middle; }
    td.lower { border-top: none; padding-top: 0.5mm; }
    .small { font-size: 8.5pt; }
    .note { font-size: 8.5pt; color: #444; }
    td.line { border-top: none; border-bottom: 0.4pt dashed #999; height: 6mm; }
    table.body { overflow: wrap; }
</style>

<div class="title">決裁申請書</div>
<div class="status">状態: {{ $sheet->statusLabel }} ・ {{ $sheet->round }} 回目の提出</div>

<table>
    <tr>
        <td class="label" style="width: 18mm;">決裁No</td>
        <td>{{ $sheet->number ?? '' }}</td>
        <td class="label" style="width: 18mm;">決裁日</td>
        <td>{{ $sheet->decidedOn ?? '' }}</td>
        <td class="label" style="width: 18mm;">発信日</td>
        <td>{{ $sheet->submittedOn ?? '' }}</td>
        <td class="label" style="width: 18mm;">受付日</td>
        <td>{{ $sheet->receivedOn ?? '' }}</td>
    </tr>
</table>
<div class="gap"></div>

{{-- ⚠ 判断の欄（入れ子の小さな表）は上の段に置き、コメント・印と同じセルに入れない。同じセルに入れると、mPDF がセルの高さを
     少なく見積もり、社長のコメントが 8 行ほどで印が枠からはみ出した（2026-10-04 の点検）。上の段と下の段の間の線は消して 1 つの欄に見せ、
     表は 1 段だったときと同じく段の間でページを分けない（page-break-inside: avoid） --}}
<table style="page-break-inside: avoid;">
    <tr>
        <td class="upper" style="width: 45%;">
            <table class="marks">
                <tr>
                    <td style="border: none; padding: 0 1mm 0 0;" class="mincho">決裁</td>
                    @foreach(['可', '条可', '差戻', '否'] as $label)
                        <td class="{{ $mark($sheet->decisionMark, $label) }}">{{ $label }}</td>
                    @endforeach
                </tr>
            </table>
        </td>
        <td class="upper mincho" style="width: 35%;">申請部門</td>
        <td class="upper mincho" style="width: 20%; text-align: center;">承認</td>
    </tr>
    <tr>
        <td class="lower">
            <div class="mincho" style="margin-top: 2mm;">決裁者コメント{{ $sheet->decisionMark === '条可' ? '（条件）' : '' }}</div>
            <div>{!! nl2br(e($sheet->presidentComment ?? '')) !!}</div>
            @if($sheet->conditionConfirmedAt)
                <div class="note">条件確認: {{ $sheet->conditionConfirmedAt }}</div>
            @endif
            @if($sheet->presidentStamp)
                <div>{!! \App\Support\Approval\StampSvg::render($sheet->presidentStamp, \App\Support\Approval\StampSvg::PDF_FONT, '80') !!}</div>
            @endif
        </td>
        <td class="lower">
            <div>{{ $sheet->departmentName ?? '' }}</div>
            <div class="mincho" style="margin-top: 2mm;">申請者名</div>
            <div>{{ $sheet->applicantName ?? '' }}</div>
        </td>
        <td class="lower" style="text-align: center;">
            @if($sheet->headSkipped)
                <div class="small" style="margin-top: 4mm;">申請者が部門長のため省略</div>
            @elseif($sheet->headStamp)
                <div>{!! \App\Support\Approval\StampSvg::render($sheet->headStamp, \App\Support\Approval\StampSvg::PDF_FONT, '80') !!}</div>
            @endif
            @if($sheet->headComment)
                <div class="small" style="text-align: left;">{!! nl2br(e($sheet->headComment)) !!}</div>
            @endif
        </td>
    </tr>
</table>
<div class="gap"></div>

<table>
    <tr>
        <td class="label" style="width: 18mm;">件名</td>
        <td>{{ $sheet->subject ?? '' }}</td>
    </tr>
    <tr>
        <td colspan="2">
            金額 {{ $sheet->amountLabel ?? '—' }}（税抜）&nbsp;&nbsp;
            実施時期 {{ $sheet->schedule ?? '—' }}&nbsp;&nbsp;
            関連する決裁No {{ $sheet->relatedNumbers === [] ? '—' : implode('・', $sheet->relatedNumbers) }}
            <div class="mincho" style="margin-top: 2mm;">重点ポイント箇条書（5W2H）</div>
        </td>
    </tr>
</table>
{{-- ⚠ overflow: wrap は mPDF の表の CSS。空白の無い長い語（URL など）をセルの中で折り返す。無いと、その語が収まるまで
     本文の表全体を縮めた（200 文字の URL 1 つで全ページの本文が約半分の大きさになった。word-wrap・overflow-wrap は効かない） --}}
<table class="body">
    @foreach($sheet->bodyRows as $row)
        <tr><td class="line">{{ $row }}</td></tr>
    @endforeach
</table>
<table>
    <tr>
        <td style="border-top: none; text-align: right;" class="small">
            （添付ファイル {{ count($sheet->attachmentNames) }} 件{{ $sheet->attachmentNames === [] ? '' : ': ' . implode(' ／ ', $sheet->attachmentNames) }}）
        </td>
    </tr>
</table>
<div class="gap"></div>

<table>
    <tr>
        <td class="label" style="width: 22mm;">審査部門</td>
        <td style="width: 60%;">
            <div class="note">{{ $sheet->reviewDepartmentName ?? '' }}</div>
            <div>{!! nl2br(e($sheet->reviewComment ?? '')) !!}</div>
        </td>
        <td style="text-align: center;">
            <table class="marks" style="margin: 0 auto;">
                <tr>
                    @foreach(['可', '保留', '否'] as $label)
                        <td class="{{ $mark($sheet->reviewMark, $label) }}">{{ $label }}</td>
                    @endforeach
                </tr>
            </table>
            @if($sheet->reviewStamp)
                <div>{!! \App\Support\Approval\StampSvg::render($sheet->reviewStamp, \App\Support\Approval\StampSvg::PDF_FONT, '80') !!}</div>
            @endif
        </td>
    </tr>
</table>
