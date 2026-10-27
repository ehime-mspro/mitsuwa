{{-- 試す期間の PDF の上の余白に出す小さな「試し」（段階6 設計書 §5.6）。社長の印の付いた試しの PDF が本物に見えないようにする。
     本文と印の位置を変えない。margin_top 12mm の中に収まる（ApprovalPdf の margin_header 5mm から 7pt の 1 行）。
     ApprovalPdf::sheet() が、PdfSheet の trial のときだけ渡す --}}
<div style="text-align: right; font-size: 7pt; color: #888;">試し</div>
