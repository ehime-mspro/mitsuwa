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
 * - 日付は Excel の日付（日本の暦の日。時刻は入れない）、金額は数（税抜）、粗利率は数（12.3% と出る書式。販売金額が 0 なら空。段階5 D19）
 * - 文字の欄は**すべて文字として入れる**（setCellValueExplicit）。件名などが「=」「+」「-」「@」で始まっても式にしない
 * - 見出しの行を固定し、見出しに絞り込みのボタン（オートフィルタ）
 * - 中身は台帳と同じ出し分け（LedgerRow。申請者本人以外には最後に提出した中身。D19）
 * ⚠ 列の並び・見出し・幅・種類（文字・折り返す文字・日付・金額・率）・値の取り方は columns() の 1 つの定義から作る（列の記号を手で書かない。
 *   段階4 の BACKLOG の注意「段階5 で列を足すときは 1 つの定義から作る形に直す」）
 * ⚠ 行の関係は CHUNK 件ずつ読む（全件の申請を一度に読まない）。件数の上限は config('approval.ledger.excel_limit')
 */
final class LedgerExcel
{
    /** 1 回に読む申請の数 */
    private const CHUNK = 200;

    /** 列の値の種類（書き方と書式） */
    private const TEXT = 'text';

    private const WRAPPED = 'wrapped';

    private const DATE = 'date';

    private const AMOUNT = 'amount';

    private const RATE = 'rate';

    private const SHEET_TITLE = '決裁台帳';

    /**
     * 列（要件 10 の並び。D23 で審査部門の意見を 2 列に分けた。段階5 で工事原価・粗利益金額・粗利率・契約予定日を足した）。
     * 見出し・幅・種類・行から値を取る関数。並びを変えるときもここだけを変える
     *
     * @return list<array{0: string, 1: int, 2: string, 3: \Closure(LedgerRow): mixed}>
     */
    private static function columns(): array
    {
        return [
            ['決裁No', 12, self::TEXT, fn (LedgerRow $r) => $r->number],
            ['決裁日', 11, self::DATE, fn (LedgerRow $r) => $r->decidedAt],
            ['判断', 6, self::TEXT, fn (LedgerRow $r) => $r->decisionLabel],
            ['件名', 40, self::WRAPPED, fn (LedgerRow $r) => $r->subject],
            ['申請の種類', 16, self::TEXT, fn (LedgerRow $r) => $r->typeName],
            ['申請部門', 16, self::TEXT, fn (LedgerRow $r) => $r->departmentName],
            ['申請者', 14, self::TEXT, fn (LedgerRow $r) => $r->applicantName],
            ['金額（税抜）', 14, self::AMOUNT, fn (LedgerRow $r) => $r->amount],
            ['工事原価', 14, self::AMOUNT, fn (LedgerRow $r) => $r->cost],
            ['粗利益金額', 14, self::AMOUNT, fn (LedgerRow $r) => $r->profit],
            ['粗利率', 8, self::RATE, fn (LedgerRow $r) => $r->rate],
            // 実施時期は「2026年11月〜2027年2月」が右隣に値があっても欠けない幅（4b の画面の確かめの指摘）
            ['実施時期', 22, self::TEXT, fn (LedgerRow $r) => $r->schedule],
            ['契約予定日', 11, self::DATE, fn (LedgerRow $r) => $r->contractDate],
            ['関連する決裁No', 18, self::TEXT, fn (LedgerRow $r) => $r->relatedNumbers === [] ? null : implode('・', $r->relatedNumbers)],
            ['提出日', 11, self::DATE, fn (LedgerRow $r) => $r->submittedAt],
            ['審査の意見', 10, self::TEXT, fn (LedgerRow $r) => $r->reviewResult],
            ['審査のコメント', 40, self::WRAPPED, fn (LedgerRow $r) => $r->reviewComment],
            ['条件', 40, self::WRAPPED, fn (LedgerRow $r) => $r->condition],
            ['状態', 16, self::TEXT, fn (LedgerRow $r) => $r->statusLabel],
        ];
    }

    /**
     * xlsx の中身（バイト列）
     *
     * @param list<int> $ids 台帳の並び（Ledger::sortedIds）
     */
    public static function build(User $viewer, array $ids): string
    {
        $book    = new Spreadsheet();
        $sheet   = $book->getActiveSheet();
        $columns = self::columns();
        $sheet->setTitle(self::SHEET_TITLE);

        self::heading($sheet, $columns);

        $line = 2;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (Ledger::rows($viewer, $chunk) as $row) {
                self::write($sheet, $line++, $row, $columns);
            }
        }

        $last = $line - 1;
        if ($last >= 2) {
            self::formatBody($sheet, $last, $columns);
        }

        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:' . self::letter(count($columns)) . $last);

        // ⚠ 書き出しに失敗しても、開いた出力のバッファを残さない（finally で閉じる）
        ob_start();
        try {
            (new Xlsx($book))->save('php://output');
        } finally {
            $bytes = (string) ob_get_clean();
        }

        return $bytes;
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

    /** @param list<array{0: string, 1: int, 2: string, 3: \Closure}> $columns */
    private static function heading(Worksheet $sheet, array $columns): void
    {
        foreach ($columns as $index => [$label, $width]) {
            $sheet->setCellValueExplicit([$index + 1, 1], $label, DataType::TYPE_STRING);
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
        }
        $heading = 'A1:' . self::letter(count($columns)) . '1';
        $sheet->getStyle($heading)->getFont()->setBold(true);
        $sheet->getStyle($heading)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
    }

    /** 列の記号（1 → A・19 → S） */
    private static function letter(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    /**
     * 2 行目から $last 行目までの書式（日付・金額・率・折り返し・上ぞろえ）。行が無ければ呼ばない（書式だけの空の行を作らない）
     *
     * @param list<array{0: string, 1: int, 2: string, 3: \Closure}> $columns
     */
    private static function formatBody(Worksheet $sheet, int $last, array $columns): void
    {
        $formats = [self::DATE => 'yyyy/mm/dd', self::AMOUNT => '#,##0', self::RATE => '0.0%'];

        foreach ($columns as $index => [, , $kind]) {
            $range = self::letter($index + 1) . '2:' . self::letter($index + 1) . $last;
            if (isset($formats[$kind])) {
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode($formats[$kind]);
            }
            if ($kind === self::WRAPPED) {
                $sheet->getStyle($range)->getAlignment()->setWrapText(true);
            }
        }
        $sheet->getStyle('A2:' . self::letter(count($columns)) . $last)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    }

    /**
     * 1 行を書く（空の値は書かない）。文字は文字として（式にしない）・日付は日本の暦の日の Excel の日付・金額は数・率は割合の数（12.3% → 0.123）
     *
     * @param list<array{0: string, 1: int, 2: string, 3: \Closure(LedgerRow): mixed}> $columns
     */
    private static function write(Worksheet $sheet, int $line, LedgerRow $row, array $columns): void
    {
        foreach ($columns as $index => [, , $kind, $value]) {
            $cell = self::letter($index + 1) . $line;
            $v    = $value($row);
            if ($v === null || $v === '') {
                continue;
            }

            match ($kind) {
                self::DATE   => $sheet->setCellValueExplicit($cell, self::excelDate($v), DataType::TYPE_NUMERIC),
                self::AMOUNT => $sheet->setCellValueExplicit($cell, $v, DataType::TYPE_NUMERIC),
                self::RATE   => $sheet->setCellValueExplicit($cell, round($v / 100, 3), DataType::TYPE_NUMERIC),
                default      => $sheet->setCellValueExplicit($cell, (string) $v, DataType::TYPE_STRING),
            };
        }
    }

    /** 保存した瞬間（UTC）の日本の暦の日を、Excel の日付の数にする（時刻は入れない） */
    private static function excelDate(DateTimeInterface $at): float
    {
        $day = CarbonImmutable::instance($at)->setTimezone(JapanTime::ZONE);

        return (float) Date::formattedPHPToExcel((int) $day->format('Y'), (int) $day->format('m'), (int) $day->format('d'));
    }
}
