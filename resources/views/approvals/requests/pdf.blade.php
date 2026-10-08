{{-- 決裁申請書の PDF の紙面（mPDF に渡す HTML。要件 9.2・段階4 設計書 §5.6・D14〜D16）。
     紙の様式（決裁申請書.xls）の枠を使い、使わない欄（決裁区分・項目番号・関連部門）は外す。
     ⚠ mPDF は CSS の一部しか読まない（flex・grid・CSS の変数は使えない）。枠は表で組む。
     ⚠ 本文は罫線の 1 行を表の 1 行にする（mPDF は表の 1 行をページの途中で切れない。PdfSheet::bodyRows()）。
     ⚠ 文字はすべて {{ }} で包む（印は StampSvg が e() で包んだ SVG を返す）。
     ⚠ 人が打った文字の入る表（決裁の欄・件名・本文・明細表・追加の欄・定型文・添付・審査）には class="wrap"（mPDF の表の CSS overflow: wrap）を付ける。
        空白の無い長い語（URL・ファイル名）をセルの中で折り返す。無いと、その語が収まるまで表全体の文字が縮む（word-wrap・overflow-wrap は効かない）。
        決裁No・日付の表（付けると列の幅が少し変わる）と入れ子の判断の欄 table.marks は、短い決まった文字だけなので付けない
     ⚠ 行が 1 つだけの表は、ページの下の端で収まらないと mPDF が 1.4 倍まで縮めて押し込む（Tag/Table.php）。
        人が打つ文字の入る 1 行の表（追加の欄・定型文）には autosize="1" を付けて、縮めずに次のページへ送る。
        4a からある 1 行の表（添付の件数・審査部門）には付けていない（同じ縮みは起きるが約 0.1% で目に見えない） --}}
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
    table.wrap { overflow: wrap; }
    td.num { text-align: right; }
    tr.sum td { background-color: #f2f2f2; }
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
<table class="wrap" style="page-break-inside: avoid;">
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

<table class="wrap">
    <tr>
        <td class="label" style="width: 18mm;">件名</td>
        <td>{{ $sheet->subject ?? '' }}</td>
    </tr>
    <tr>
        <td colspan="2">
            金額 {{ $sheet->amountLabel ?? '—' }}（税抜）&nbsp;&nbsp;
            実施時期 {{ $sheet->schedule ?? '—' }}&nbsp;&nbsp;
            関連する決裁No {{ $sheet->relatedNumbers === [] ? '—' : implode('・', $sheet->relatedNumbers) }}
            <div class="mincho" style="margin-top: 2mm;">{{ $sheet->amountRows === null ? '重点ポイント箇条書（5W2H）' : '（記）' }}</div>
        </td>
    </tr>
</table>
@if($sheet->amountRows !== null)
    {{-- 明細表の種類（段階5 §5.7）: 紙の住宅の様式の「（記）」の並び。明細表の 1 行＝表の 1 行（行の切れ目で次のページへ）。
         見出しの行は thead に入れる（mPDF は次のページの頭で繰り返す。点検の M-8） --}}
    <table class="wrap">
        <thead>
            <tr>
                <td class="label">項目</td>
                <td class="label" style="width: 30mm;">販売金額</td>
                <td class="label" style="width: 30mm;">工事原価</td>
                <td class="label" style="width: 30mm;">粗利益金額</td>
                <td class="label" style="width: 18mm;">粗利率</td>
            </tr>
        </thead>
        @foreach($sheet->amountRows as $row)
            <tr class="{{ $row['kind'] === 'row' ? '' : 'sum' }}">
                <td>{{ $row['name'] }}</td>
                <td class="num">{{ $row['sale'] }}</td>
                <td class="num">{{ $row['cost'] }}</td>
                <td class="num">{{ $row['profit'] }}</td>
                <td class="num">{{ $row['rate'] }}</td>
            </tr>
        @endforeach
    </table>
@else
    <table class="wrap">
        @foreach($sheet->bodyRows as $row)
            <tr><td class="line">{{ $row }}</td></tr>
        @endforeach
    </table>
@endif
{{-- 追加の入力欄（種類が使う欄）と定型文。紙の住宅の様式の「（記）」の並び: 坪数・坪単価 → 定型文 → 担当者・契約予定日（段階5 §5.7。
     5W2H の種類で使うときも本文の下に同じ並び）。1 行に 2 組まで（見出しの幅 22mm で「契約予定日」が 1 行に収まる）。
     1 行だけの表は autosize="1"（ページの下の端で収まらないと、mPDF は 1 行の表を 1.4 倍まで縮めて同じページに置くため。縮めずに次のページへ送る） --}}
@foreach([\App\Support\Approval\PdfSheet::EXTRAS_BEFORE_FIXED_TEXT, \App\Support\Approval\PdfSheet::EXTRAS_AFTER_FIXED_TEXT] as $group => $keys)
    @if($sheet->extraPairs($keys) !== [])
        <table class="wrap" autosize="1">
            <tr>
                @foreach($sheet->extraPairs($keys) as [$label, $value])
                    <td class="label" style="width: 22mm;">{{ $label }}</td>
                    <td>{{ $value }}</td>
                @endforeach
            </tr>
        </table>
    @endif
    @if($group === 0 && ($sheet->fixedText ?? '') !== '')
        <table class="wrap" autosize="1">
            <tr><td>{!! nl2br(e($sheet->fixedText)) !!}</td></tr>
        </table>
    @endif
@endforeach
@if($sheet->amountRows !== null)
    <table class="wrap">
        <tr><td class="mincho" style="border-bottom: none;">補足</td></tr>
        @foreach($sheet->bodyRows as $row)
            <tr><td class="line">{{ $row }}</td></tr>
        @endforeach
    </table>
@endif
<table class="wrap">
    <tr>
        <td style="border-top: none; text-align: right;" class="small">
            （添付ファイル {{ count($sheet->attachmentNames) }} 件{{ $sheet->attachmentNames === [] ? '' : ': ' . implode(' ／ ', $sheet->attachmentNames) }}）
        </td>
    </tr>
</table>
<div class="gap"></div>

<table class="wrap">
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
