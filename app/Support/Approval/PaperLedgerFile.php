<?php

namespace App\Support\Approval;

use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * 紙の決裁台帳の Excel（年度ごとの .xls・.xlsx）を読む（要件 11.1・段階5 設計書 §5.9）。**DB には触らない**
 * （部門・番号との突き合わせと結び付けは PaperImportAnalysis）。
 *
 * - シートの名前の先頭の英字（全角は半角に）が部門のアルファベットの受付簿（「Ｊ・住宅」→ J・「ＪＢ・住宅」→ JB）。名前に「金額」を
 *   含むシートは金額の控え。どちらでもないシートは読まない
 * - 受付簿は 1 枚の中が左右 2 段（左 B〜E・右 G〜J。どちらも ＮＯ・日付・申請者・件名）。題名と見出しの塊が 34 行ごとに繰り返し、
 *   ＮＯ は使っていない行にも印字済み → ＮＯ が数か日付が日付の形で、日付・申請者・件名のどれかが入っている段だけを拾う
 *   （ＮＯ だけの段は使っていない行・題名と見出しの行は ＮＯ も日付も数でない）。拾った段に日付が無いなどの誤りは PaperImportAnalysis がエラーにする
 * - 金額の控えは 1 段（B=ＮＯ・C=日付・D=申請者・E=件名・F=契約金額・G=工事原価・H=粗利益）。日付・申請者・件名がそろう行だけを拾う
 *   （「門田契約 金額」のような、式だけが残った計算の跡は読まない）
 * ⚠ 式のセルは計算した値を読む（粗利益は `=+F5-G5`）。計算できなければ Excel が保存していた値。**粗利益の式は、契約金額と工事原価が
 *   どちらも書いてあるときだけ使う**（空のセルは 0 として計算され、金額をまだ書いていない行が「粗利益 0」や「粗利率 100%」になるため）
 * ⚠ 書式は読まない（readDataOnly）。日付のセルが Excel の日付の数で来ても PaperDate が読む
 * ⚠ **読む前に大きさを見る**（listWorksheetInfo はセルを作らずに行の数を返す。読み込んでから数えると、大きなファイルで本番の 128M を
 *   使い切り、捕まえられない 500 になる）。読むのは A〜J 列だけ（幅の広いシートのセルを作らない）
 */
final class PaperLedgerFile
{
    /** 受付簿の 2 段（段の名前 => ＮＯ・日付・申請者・件名の列） */
    public const SIDES = ['左' => ['B', 'C', 'D', 'E'], '右' => ['G', 'H', 'I', 'J']];

    /** 金額の控えの列 */
    private const AMOUNT_COLUMNS = ['no' => 'B', 'date' => 'C', 'applicant' => 'D', 'subject' => 'E', 'amount' => 'F', 'cost' => 'G', 'profit' => 'H'];

    /** 金額の控えのシートの名前の目印 */
    private const AMOUNT_MARK = '金額';

    /** 1 回に読む行の上限（受付簿の段と金額の控えの行を合わせて。08 年度は 224。年度ごとのファイルでないものを取り込まない） */
    public const MAX_ROWS = 3000;

    /**
     * 読む前に見る、すべてのシートの行の数の上限（使っていない行も数える。08 年度の台帳は 15 枚で 2,000 行ほど）。
     * 2026-10-13 の点検の実測で、10 列 15,000 行の .xlsx を読むと 116MB を使った（本番の上限は 128M）
     */
    public const MAX_SHEET_ROWS = 6000;

    /** 読む列（受付簿の 2 段と金額の控え。ほかの列のセルは作らない） */
    private const READ_COLUMNS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];

    /** シートの種類 */
    public const REGISTER = 'register';

    public const AMOUNTS = 'amounts';

    public const IGNORED = 'ignored';

    /**
     * @return array{
     *     sheets: list<array{index: int, title: string, kind: string, code: ?string}>,
     *     register: list<array{sheet: int, title: string, code: string, line: int, side: string, no: mixed, date: mixed, applicant: ?string, subject: ?string}>,
     *     amounts: list<array{sheet: int, title: string, line: int, no: mixed, date: mixed, applicant: string, subject: string, amount: mixed, cost: mixed, profit: mixed}>,
     * }
     *
     * @throws PaperImportException Excel として読めない・行が多すぎる
     */
    public static function read(string $path): array
    {
        try {
            $reader = IOFactory::createReader(IOFactory::identify($path, [IOFactory::READER_XLS, IOFactory::READER_XLSX]));
            $reader->setReadDataOnly(true);
            $rows = array_sum(array_column($reader->listWorksheetInfo($path), 'totalRows'));
        } catch (Throwable $e) {
            throw self::unreadable($e);
        }
        if ($rows > self::MAX_SHEET_ROWS) {
            throw new PaperImportException('台帳の行が多すぎます（すべてのシートで ' . number_format(self::MAX_SHEET_ROWS) . ' 行まで）。年度ごとの台帳のファイルか確かめてください。');
        }

        try {
            $reader->setReadFilter(new class (self::READ_COLUMNS) implements IReadFilter {
                /** @param list<string> $columns */
                public function __construct(private readonly array $columns)
                {
                }

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return in_array($columnAddress, $this->columns, true);
                }
            });
            $book = $reader->load($path);
        } catch (Throwable $e) {
            throw self::unreadable($e);
        }

        $sheets = $register = $amounts = [];

        foreach ($book->getAllSheets() as $index => $sheet) {
            $title = $sheet->getTitle();
            $kind  = self::kindOf($title);
            $code  = $kind === self::REGISTER ? self::codeOf($title) : null;
            $sheets[] = ['index' => $index, 'title' => $title, 'kind' => $kind, 'code' => $code];

            if ($kind === self::REGISTER) {
                foreach (self::registerRows($sheet, $index, $code) as $row) {
                    $register[] = $row;
                }
            } elseif ($kind === self::AMOUNTS) {
                foreach (self::amountRows($sheet, $index) as $row) {
                    $amounts[] = $row;
                }
            }

            if (count($register) + count($amounts) > self::MAX_ROWS) {
                $book->disconnectWorksheets();

                throw new PaperImportException('1 回に読めるのは ' . number_format(self::MAX_ROWS) . ' 行までです。年度ごとのファイルか確かめてください。');
            }
        }

        $book->disconnectWorksheets();

        return ['sheets' => $sheets, 'register' => $register, 'amounts' => $amounts];
    }

    /** 読めない理由は利用者には出さず、記録にだけ残す（自分のコードの誤りが「ファイルが悪い」に化けて、本番で原因を追えなくならないように。本番の LOG_LEVEL は error で、warning は残らない） */
    private static function unreadable(Throwable $e): PaperImportException
    {
        Log::error('紙の決裁の台帳を Excel として読めませんでした', ['exception' => $e]);

        return new PaperImportException('Excel のファイル（.xls・.xlsx）として読めませんでした。台帳のファイルを選び直してください。');
    }

    /** シートの種類（名前に「金額」→ 金額の控え・先頭が英字 → 受付簿・ほかは読まない） */
    private static function kindOf(string $title): string
    {
        if (str_contains($title, self::AMOUNT_MARK)) {
            return self::AMOUNTS;
        }

        return self::codeOf($title) === null ? self::IGNORED : self::REGISTER;
    }

    /** シートの名前の先頭の英字 1〜3 文字（全角は半角・大文字に。4 文字以上続けば部門の記号ではない） */
    public static function codeOf(string $title): ?string
    {
        $text = mb_strtoupper(mb_convert_kana(FormInput::trim($title), 'a'), 'UTF-8');

        return preg_match('/\A([A-Z]{1,3})(?![A-Z])/', $text, $m) ? $m[1] : null;
    }

    /** @return iterable<array{sheet: int, title: string, code: string, line: int, side: string, no: mixed, date: mixed, applicant: ?string, subject: ?string}> */
    private static function registerRows(Worksheet $sheet, int $index, string $code): iterable
    {
        $last = $sheet->getHighestDataRow();

        for ($line = 1; $line <= $last; $line++) {
            foreach (self::SIDES as $side => [$noColumn, $dateColumn, $applicantColumn, $subjectColumn]) {
                $no        = self::value($sheet, $noColumn . $line);
                $date      = self::value($sheet, $dateColumn . $line);
                $applicant = self::value($sheet, $applicantColumn . $line);
                $subject   = self::value($sheet, $subjectColumn . $line);

                if ($date === null && $applicant === null && $subject === null) {
                    continue;   // 使っていない行（ＮＯ だけ）・空の行
                }
                if (! self::isNumber($no) && ! self::isDate($date)) {
                    continue;   // 題名・部門名・見出し（ＮＯ・日付の列に文字）の行
                }

                yield [
                    'sheet' => $index, 'title' => $sheet->getTitle(), 'code' => $code, 'line' => $line, 'side' => $side,
                    'no' => $no, 'date' => $date, 'applicant' => self::text($applicant), 'subject' => self::text($subject),
                ];
            }
        }
    }

    /** @return iterable<array{sheet: int, title: string, line: int, no: mixed, date: mixed, applicant: string, subject: string, amount: mixed, cost: mixed, profit: mixed}> */
    private static function amountRows(Worksheet $sheet, int $index): iterable
    {
        $last = $sheet->getHighestDataRow();

        for ($line = 1; $line <= $last; $line++) {
            $cells = [];
            foreach (self::AMOUNT_COLUMNS as $key => $column) {
                $cells[$key] = self::value($sheet, $column . $line);
            }

            // 日付・申請者・件名がそろう行だけ（計算の跡の行を読まない）。見出しの行（ＮＯ・日付の列に文字）も読まない
            if ($cells['date'] === null || $cells['applicant'] === null || $cells['subject'] === null) {
                continue;
            }
            if (! self::isNumber($cells['no']) && ! self::isDate($cells['date'])) {
                continue;
            }

            // 粗利益の式は、契約金額と工事原価がそろうときだけ使う（空のセルを 0 として計算した値を入れない）
            $profitCell = self::AMOUNT_COLUMNS['profit'] . $line;
            if (($cells['amount'] === null || $cells['cost'] === null) && $sheet->cellExists($profitCell) && $sheet->getCell($profitCell)->isFormula()) {
                $cells['profit'] = null;
            }

            yield ['sheet' => $index, 'title' => $sheet->getTitle(), 'line' => $line] + array_merge($cells, [
                'applicant' => (string) self::text($cells['applicant']),
                'subject'   => (string) self::text($cells['subject']),
            ]);
        }
    }

    /** セルの値（空と空白だけの文字は null・文字は前後の空白を落とす・式は計算した値・装飾つきの文字は文字だけ） */
    private static function value(Worksheet $sheet, string $coordinate): mixed
    {
        if (! $sheet->cellExists($coordinate)) {
            return null;
        }
        $cell = $sheet->getCell($coordinate);

        try {
            $value = $cell->isFormula() ? $cell->getCalculatedValue() : $cell->getValue();
        } catch (Throwable) {
            $value = $cell->getOldCalculatedValue();
        }
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if (is_string($value)) {
            $value = FormInput::trim($value);

            return $value === '' ? null : $value;
        }

        return is_int($value) || is_float($value) ? $value : null;
    }

    /** 文字にした値（数のセルの件名なども文字で持つ） */
    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /** ＮＯ の形（数のセル・数字だけの文字。全角も） */
    public static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && preg_match('/\A[0-9]+\z/', mb_convert_kana($value, 'n')) === 1);
    }

    /** 日付の形（`8.05.13` の文字か Excel の日付の数） */
    private static function isDate(mixed $value): bool
    {
        return PaperDate::key($value) !== null;
    }
}
