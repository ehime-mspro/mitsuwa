<?php

namespace App\Support\Approval;

use App\Models\User;
use App\Support\JapanTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 決裁台帳の Excel（要件 10・段階4 設計書 §5.9・D23・D24）。絞り込んだ結果を台帳と同じ並びで全件。
 *
 * - 日付は Excel の日付（日本の暦の日。時刻は入れない）、金額は数（税抜）
 * - 文字の欄は**すべて文字として入れる**（setCellValueExplicit）。件名などが「=」「+」「-」「@」で始まっても式にしない
 * - 見出しの行を固定し、見出しに絞り込みのボタン（オートフィルタ）
 * - 中身は台帳と同じ出し分け（LedgerRow。申請者本人以外には最後に提出した中身。D19）
 * ⚠ 行の関係は CHUNK 件ずつ読む（全件の申請を一度に読まない）。件数の上限は config('approval.ledger.excel_limit')
 */
final class LedgerExcel
{
    /** 1 回に読む申請の数 */
    private const CHUNK = 200;

    /** 列（見出し => 幅）。D23・§5.9 の順。段階5 の列（工事原価など）は段階5 で足す */
    private const COLUMNS = [
        '決裁No'       => 12,
        '決裁日'       => 11,
        '判断'         => 6,
        '件名'         => 40,
        '申請の種類'   => 16,
        '申請部門'     => 16,
        '申請者'       => 14,
        '金額（税抜）' => 14,
        '実施時期'     => 18,
        '関連する決裁No' => 18,
        '提出日'       => 11,
        '審査の意見'   => 10,
        '審査のコメント' => 40,
        '条件'         => 40,
        '状態'         => 16,
    ];

    /** 日付の列・金額の列・折り返す列（A から数えた位置） */
    private const DATE_COLUMNS = ['B', 'K'];

    private const AMOUNT_COLUMN = 'H';

    private const WRAPPED_COLUMNS = ['D', 'M', 'N'];

    private const SHEET_TITLE = '決裁台帳';

    /**
     * xlsx の中身（バイト列）
     *
     * @param list<int> $ids 台帳の並び（Ledger::sortedIds）
     */
    public static function build(User $viewer, array $ids): string
    {
        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(self::SHEET_TITLE);

        self::heading($sheet);

        $line = 2;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (Ledger::rows($viewer, $chunk) as $row) {
                self::write($sheet, $line++, $row);
            }
        }

        $last = $line - 1;
        if ($last >= 2) {
            self::formatBody($sheet, $last);
        }

        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:' . self::lastColumn() . $last);

        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * ファイル名（日本の今日）と、古いブラウザ用の ASCII だけの代わりの名前
     *
     * @return array{0: string, 1: string} ['決裁台帳_2026-10-05.xlsx', 'ledger_2026-10-05.xlsx']
     */
    public static function fileNames(): array
    {
        $today = JapanTime::today()->format('Y-m-d');

        return [self::SHEET_TITLE . "_{$today}.xlsx", "ledger_{$today}.xlsx"];
    }

    private static function heading(Worksheet $sheet): void
    {
        $column = 1;
        foreach (self::COLUMNS as $label => $width) {
            $sheet->setCellValueExplicit([$column, 1], $label, DataType::TYPE_STRING);
            $sheet->getColumnDimensionByColumn($column)->setWidth($width);
            $column++;
        }
        $heading = 'A1:' . self::lastColumn() . '1';
        $sheet->getStyle($heading)->getFont()->setBold(true);
        $sheet->getStyle($heading)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
    }

    /** いちばん右の列（O） */
    private static function lastColumn(): string
    {
        return Coordinate::stringFromColumnIndex(count(self::COLUMNS));
    }

    /** 2 行目から $last 行目までの書式（日付・金額・折り返し・上ぞろえ）。行が無ければ呼ばない（書式だけの空の行を作らない） */
    private static function formatBody(Worksheet $sheet, int $last): void
    {
        foreach (self::DATE_COLUMNS as $column) {
            $sheet->getStyle("{$column}2:{$column}{$last}")->getNumberFormat()->setFormatCode('yyyy/mm/dd');
        }
        $sheet->getStyle(self::AMOUNT_COLUMN . '2:' . self::AMOUNT_COLUMN . $last)->getNumberFormat()->setFormatCode('#,##0');
        foreach (self::WRAPPED_COLUMNS as $column) {
            $sheet->getStyle("{$column}2:{$column}{$last}")->getAlignment()->setWrapText(true);
        }
        $sheet->getStyle('A2:' . self::lastColumn() . $last)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    }

    private static function write(Worksheet $sheet, int $line, LedgerRow $row): void
    {
        $texts = [
            'A' => $row->number,
            'C' => $row->decisionLabel,
            'D' => $row->subject,
            'E' => $row->typeName,
            'F' => $row->departmentName,
            'G' => $row->applicantName,
            'I' => $row->schedule,
            'J' => $row->relatedNumbers === [] ? null : implode('・', $row->relatedNumbers),
            'L' => $row->reviewResult,
            'M' => $row->reviewComment,
            'N' => $row->condition,
            'O' => $row->statusLabel,
        ];
        foreach ($texts as $column => $text) {
            if ($text !== null && $text !== '') {
                $sheet->setCellValueExplicit("{$column}{$line}", $text, DataType::TYPE_STRING);
            }
        }

        if ($row->decidedAt !== null) {
            $sheet->setCellValueExplicit("B{$line}", self::excelDate($row->decidedAt), DataType::TYPE_NUMERIC);
        }
        if ($row->submittedAt !== null) {
            $sheet->setCellValueExplicit("K{$line}", self::excelDate($row->submittedAt), DataType::TYPE_NUMERIC);
        }
        if ($row->amount !== null) {
            $sheet->setCellValueExplicit(self::AMOUNT_COLUMN . $line, $row->amount, DataType::TYPE_NUMERIC);
        }
    }

    /** 保存した瞬間（UTC）の日本の暦の日を、Excel の日付の数にする（時刻は入れない） */
    private static function excelDate(DateTimeInterface $at): float
    {
        $day = CarbonImmutable::instance($at)->setTimezone(JapanTime::ZONE);

        return (float) Date::formattedPHPToExcel((int) $day->format('Y'), (int) $day->format('m'), (int) $day->format('d'));
    }
}
