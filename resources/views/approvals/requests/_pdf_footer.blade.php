{{-- 決裁申請書の PDF の各ページの下（要件 9.2）。{PAGENO}・{nbpg} は mPDF がページ番号に置き換える --}}
<table style="width: 100%; border-collapse: collapse;">
    <tr>
        <td style="border: none; font-size: 8pt; color: #444;">決裁申請システムから出力 {{ $sheet->outputAt }}（出力者: {{ $sheet->outputBy }}）</td>
        <td style="border: none; font-size: 8pt; color: #444; text-align: right;">{PAGENO} / {nbpg}</td>
    </tr>
</table>
