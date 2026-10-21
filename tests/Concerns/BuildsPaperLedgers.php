<?php

namespace Tests\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 紙の決裁台帳と同じ形の小さな Excel を作る（段階5 設計書 D24。**本物の台帳はリポジトリに入れない**＝実在の人の名字と案件が入っている）。
 *
 * 08 年度の台帳を手元で読んで写した形（計画 §0 の「実物の台帳で分かったこと」）:
 * - 受付簿: 34 行の塊が縦に繰り返す（1 行目 題名・2 行目 部門名と「ＮＯ．1」・4 行目 見出し・5〜34 行目 30 行）。左段 B〜E に 1〜30、
 *   右段 G〜J に 31〜60、次の塊は左 61〜90・右 91〜120。**ＮＯ は使っていない行にも印字済み**。日付は文字の「8.05.13」
 * - 金額の控え: 1 段（B=ＮＯ・C=日付・D=申請者・E=件名・F=契約金額・G=工事原価・H=粗利益〈式 =+F5-G5〉・I=粗利（累計）〈式〉）
 * ⚠ 人の名前と件名は作りもの（甲野・乙山・見本邸など）
 */
trait BuildsPaperLedgers
{
    /**
     * @param array<string, list<array{0: int, 1: mixed, 2: ?string, 3: ?string}>|array{amounts: list<array{0: int, 1: mixed, 2: string, 3: string, 4: mixed, 5: mixed}>}> $sheets
     *        受付簿: シートの名前 => [[ＮＯ, 日付, 申請者, 件名], ...]（ＮＯ の位置の段に書く）／
     *        金額の控え: シートの名前（「金額」を含む）=> ['amounts' => [[ＮＯ, 日付, 申請者, 件名, 契約金額, 工事原価], ...]]（粗利益は式）
     * @param array<string, array<string, mixed>> $cells 最後に上書きするセル（シートの名前 => [座標 => 値]）。ＮＯ の形の誤りなどを作る
     * @param string $format Xls（台帳と同じ古い形式）か Xlsx
     * @return string ファイルのパス（テストの終わりに消す）
     */
    protected function paperLedger(array $sheets, array $cells = [], string $format = 'Xls', int $pages = 2): string
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);

        foreach ($sheets as $title => $rows) {
            $sheet = $book->createSheet();
            $sheet->setTitle($title);
            if (isset($rows['amounts'])) {
                $this->writeAmountSheet($sheet, $title, $rows['amounts']);
            } else {
                $this->writeRegisterSheet($sheet, $title, $rows, $pages);
            }
            foreach ($cells[$title] ?? [] as $coordinate => $value) {
                is_string($value)
                    ? $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING)
                    : $sheet->setCellValue($coordinate, $value);
            }
        }

        $base = tempnam(sys_get_temp_dir(), 'paper');
        $path = $base . '.' . strtolower($format);
        @unlink($base);
        IOFactory::createWriter($book, $format)->save($path);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    /** 受付簿の段の場所（ＮＯ → [行, 左か右]） */
    protected function paperCellOf(int $no): array
    {
        $page  = intdiv($no - 1, 60);
        $index = ($no - 1) % 60;

        return [1 + $page * 34 + 4 + $index % 30, $index < 30 ? '左' : '右'];
    }

    private function writeRegisterSheet(Worksheet $sheet, string $title, array $rows, int $pages): void
    {
        $maxNo = max([0, ...array_map(fn (array $r) => is_int($r[0]) ? $r[0] : 0, $rows)]);
        $pages = max($pages, intdiv(max($maxNo, 1) - 1, 60) + 1);

        for ($page = 0; $page < $pages; $page++) {
            $top = 1 + $page * 34;
            $this->paperText($sheet, "B{$top}", '　決　裁　申　請　書　');
            $this->paperText($sheet, 'B' . ($top + 1), $title . '事業部');
            $this->paperText($sheet, 'J' . ($top + 1), 'ＮＯ．' . ($page + 1));
            foreach (['B' => 'ＮＯ', 'C' => '日　　付', 'D' => '申請者', 'E' => '件　　　名', 'G' => 'ＮＯ', 'H' => '日　　付', 'I' => '申請者', 'J' => '件　　　名'] as $column => $label) {
                $this->paperText($sheet, $column . ($top + 3), $label);
            }
            for ($i = 0; $i < 30; $i++) {
                $sheet->setCellValue('B' . ($top + 4 + $i), $page * 60 + $i + 1);
                $sheet->setCellValue('G' . ($top + 4 + $i), $page * 60 + 30 + $i + 1);
            }
        }

        foreach ($rows as [$no, $date, $applicant, $subject]) {
            [$line, $side] = $this->paperCellOf($no);
            [, $dateColumn, $applicantColumn, $subjectColumn] = $side === '左' ? ['B', 'C', 'D', 'E'] : ['G', 'H', 'I', 'J'];
            is_string($date) ? $this->paperText($sheet, $dateColumn . $line, $date) : $sheet->setCellValue($dateColumn . $line, $date);
            $this->paperText($sheet, $applicantColumn . $line, $applicant);
            $this->paperText($sheet, $subjectColumn . $line, $subject);
        }
    }

    private function writeAmountSheet(Worksheet $sheet, string $title, array $rows): void
    {
        $this->paperText($sheet, 'B1', '住宅・' . $title . '一覧');
        foreach (['B' => 'ＮＯ', 'C' => '日　　付', 'D' => '申請者', 'E' => '件　　　名', 'F' => '契約金額', 'G' => '工事原価', 'H' => '粗利益', 'I' => '粗利（累計）', 'J' => '入金予定日'] as $column => $label) {
            $this->paperText($sheet, "{$column}4", $label);
        }

        // 行の数より多く ＮＯ と式を入れておく（使っていない行にも式がある＝「門田契約 金額」の形）
        for ($i = 0; $i < max(count($rows), 3) + 2; $i++) {
            $line = 5 + $i;
            $sheet->setCellValue("B{$line}", $i + 1);
            $sheet->setCellValue("H{$line}", "=+F{$line}-G{$line}");
            $sheet->setCellValue("I{$line}", $line === 5 ? '=H5' : "=+I" . ($line - 1) . "+H{$line}");
        }
        foreach (array_values($rows) as $i => [$no, $date, $applicant, $subject, $amount, $cost]) {
            $line = 5 + $i;
            $sheet->setCellValue("B{$line}", $no);
            is_string($date) ? $this->paperText($sheet, "C{$line}", $date) : $sheet->setCellValue("C{$line}", $date);
            $this->paperText($sheet, "D{$line}", $applicant);
            $this->paperText($sheet, "E{$line}", $subject);
            is_string($amount) ? $this->paperText($sheet, "F{$line}", $amount) : $sheet->setCellValue("F{$line}", $amount);
            is_string($cost) ? $this->paperText($sheet, "G{$line}", $cost) : $sheet->setCellValue("G{$line}", $cost);
        }
    }

    private function paperText(Worksheet $sheet, string $coordinate, ?string $value): void
    {
        if ($value !== null) {
            $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
        }
    }
}
