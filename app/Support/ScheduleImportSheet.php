<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 外部の工程管理サービスの「一覧」書き出し xlsx を工程の配列に変換する（設計書 §2.2 / §4.2）。
 *
 * ⚠ **DB には触らない。** 呼び出し側（ScheduleImportController）が保存する。
 *
 * ⚠ **クライアント側の SheetJS では読めない**ので、ここはサーバ側の PhpSpreadsheet でやる。
 *   この xlsx はロゴ画像に拡張子が無く、SheetJS 0.18.5 がバイナリを文字列展開しようとして
 *   落ちる（実測: 原本・加工版とも `Cannot create a string longer than 0x1fffffe8 characters`）。
 *   詳細は tests/fixtures/schedule-import/README.md。
 */
final class ScheduleImportSheet
{
    /** 取り込める書き出し */
    public const FORMAT_LIST = 'list';

    /** それ以外（工程表＝ガント形式・別のファイル）。黙って 0 件にせず差し戻す */
    public const FORMAT_OTHER = 'other';

    /** `schedule_steps.name` / `.notes` の桁 */
    public const MAX_NAME = 100;
    public const MAX_NOTES = 255;

    /** 1 回の取込で受け付ける最大工程数（実測 65 件に対し十分な余裕） */
    public const MAX_ROWS = 500;

    /** 見出し行を探す範囲。実測ではどのシートも 3 行目 */
    private const HEADER_SEARCH_ROWS = 10;

    private const COL_GROUP   = 'A';   // 大工程名
    private const COL_NAME    = 'B';   // 工程名
    private const COL_COMPANY = 'C';   // 担当会社
    private const COL_PERSON  = 'D';   // 担当者
    private const COL_START   = 'E';   // 施工開始日
    private const COL_END     = 'H';   // 施工完了日
    private const COL_DAYS    = 'K';   // 期間
    private const COL_STATUS  = 'L';   // 状態

    /** ヘッダー情報（見出し行より上の案内ブロック） */
    private const CELL_PERIOD    = 'D1';
    private const CELL_SITE_NAME = 'B2';
    private const CELL_ADDRESS   = 'F2';

    /**
     * 見出し行と判定するのに要求するラベル。
     *
     * ⚠ **実際に読む列だけを要求する。** 曜日や時間の列まで一致を求めると、
     *   書き出し元が些細なラベル変更をしただけで取り込めなくなる。
     */
    private const REQUIRED_HEADERS = [
        self::COL_GROUP  => '大工程名',
        self::COL_NAME   => '工程名',
        self::COL_START  => '施工開始日',
        self::COL_END    => '施工完了日',
        self::COL_STATUS => '状態',
    ];

    /**
     * 書き出し形式を判別する。
     *
     * ⚠ ガント形式は日付グリッドなので見出しが揃わず FORMAT_OTHER に落ちる。
     *   ガント形式だけを名指しで判別する分岐は**置かない** —— 実物で確かめられない判別を
     *   書くと、当たらない分岐がテストだけ緑にして残る。利用者に見せる文言は
     *   「一覧形式で書き出し直してください」で両方に効く。
     */
    public static function detectFormat(string $path): string
    {
        return self::read($path)['format'];
    }

    /**
     * @return array{
     *     format: string,
     *     site_name: ?string,
     *     address: ?string,
     *     period: ?string,
     *     rows: array<int, array<string, mixed>>,
     *     warnings: array<int, string>,
     *     rowErrors: array<int, string>,
     * }
     *
     * ⚠ 返すキーは `rowErrors`。**`errors` にしない** —— view へ渡したとき Blade の
     *   `$errors`（ViewErrorBag）を壊して 500 になる（Bug #53）。
     */
    public static function read(string $path): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        $rows = [];
        $warnings = [];
        $rowErrors = [];
        $sawHeader = false;

        // ⚠ **全シートを読む。** 2 枚目は「続き」で、1 枚目だけだと実測 5 件落ちる（設計書 §3.1 E）
        foreach ($book->getAllSheets() as $sheet) {
            // ⚠ **見出しはシートごとに探す。** 2 枚目も独立した印刷ページなので
            //    1〜3 行目に案内ブロックと見出しを丸ごと繰り返し持っている。
            //    「1 枚目だけ飛ばす」実装だと 2 枚目の見出し行が工程として入る。
            $headerRow = self::findHeaderRow($sheet);
            if ($headerRow === null) {
                continue;
            }
            $sawHeader = true;

            $last = $sheet->getHighestDataRow();
            for ($r = $headerRow + 1; $r <= $last; $r++) {
                $row = self::readRow($sheet, $r, count($rows) + 1, $warnings, $rowErrors);
                if ($row !== null) {
                    $rows[] = $row;
                }
                if (count($rows) > self::MAX_ROWS) {
                    $rowErrors[] = sprintf('工程が %d 件を超えています。ファイルを分けてください。', self::MAX_ROWS);
                    break 2;
                }
            }
        }

        if (! $sawHeader) {
            return [
                'format' => self::FORMAT_OTHER,
                'site_name' => null, 'address' => null, 'period' => null,
                'rows' => [], 'warnings' => [], 'rowErrors' => [],
            ];
        }

        $first = $book->getSheet(0);

        return [
            'format'    => self::FORMAT_LIST,
            'site_name' => self::cell($first, self::CELL_SITE_NAME) ?: null,
            'address'   => self::cell($first, self::CELL_ADDRESS) ?: null,
            // ⚠ 工事期間は**表示専用**。実測では D1 が 2026/07/28 開始なのに実データの最小は
            //    2026/07/23 で、範囲が一致しない。検算に使うと実ファイルでいきなり警告が出る。
            'period'    => self::cell($first, self::CELL_PERIOD) ?: null,
            'rows'      => $rows,
            'warnings'  => $warnings,
            'rowErrors' => $rowErrors,
        ];
    }

    // ============================================================
    // 内部
    // ============================================================

    private static function findHeaderRow(Worksheet $sheet): ?int
    {
        for ($r = 1; $r <= self::HEADER_SEARCH_ROWS; $r++) {
            foreach (self::REQUIRED_HEADERS as $col => $label) {
                if (self::cell($sheet, $col . $r) !== $label) {
                    continue 2;
                }
            }

            return $r;
        }

        return null;
    }

    /**
     * 1 行を読む。工程行でなければ null。
     *
     * @param array<int, string> $warnings
     * @param array<int, string> $rowErrors
     * @return array<string, mixed>|null
     */
    private static function readRow(
        Worksheet $sheet,
        int $r,
        int $order,
        array &$warnings,
        array &$rowErrors
    ): ?array {
        $group    = self::cell($sheet, self::COL_GROUP . $r);
        $name     = self::cell($sheet, self::COL_NAME . $r);
        $startRaw = self::cell($sheet, self::COL_START . $r);
        $endRaw   = self::cell($sheet, self::COL_END . $r);

        // ⚠ **採用条件は「工程名と施工開始日がともに非空」**。
        //    「大工程名が非空」で採ると、各シート末尾にあるページ番号だけの行
        //    （A 列に '10' だけ）を工程として拾い、実測 65 件が 67 件になる。
        $hasName = $name !== '';
        $hasStart = $startRaw !== '';

        if (! $hasName && ! $hasStart) {
            return null;   // 空行・ページ番号行・脚注
        }

        $where = sprintf('%s の %d 行目', $sheet->getTitle(), $r);

        // ⚠ 片方だけあるのは**黙って捨てない**。データの取りこぼしなので行エラーにする。
        if (! $hasName) {
            $rowErrors[] = "{$where}: 工程名がありません。";

            return null;
        }
        if (! $hasStart) {
            $rowErrors[] = "{$where}: 施工開始日がありません。";

            return null;
        }

        // ⚠ 日付は Excel のシリアル値ではなく**文字列 `Y/m/d`**（セルは t="str"）。
        //    CsvDate は `/` を `-` に直したうえで checkdate() で存在を判定する（Bug #54）。
        $start = CsvDate::normalize($startRaw);
        if ($start === null) {
            $rowErrors[] = "{$where}: 施工開始日「{$startRaw}」を日付として読めません。";

            return null;
        }

        $end = null;
        if ($endRaw !== '') {
            $end = CsvDate::normalize($endRaw);
            if ($end === null) {
                $rowErrors[] = "{$where}: 施工完了日「{$endRaw}」を日付として読めません。";

                return null;
            }
            if ($end < $start) {
                $rowErrors[] = "{$where}: 施工完了日が施工開始日より前です（{$startRaw} → {$endRaw}）。";

                return null;
            }
        }

        // ⚠ 担当会社 / 担当者 / 状態は工程表の表示項目ではないが、捨てると書き出し元を
        //    見に行くことになる（設計書 §3.1 F）。
        $notes = implode(' / ', array_filter([
            self::cell($sheet, self::COL_COMPANY . $r),
            self::cell($sheet, self::COL_PERSON . $r),
            self::cell($sheet, self::COL_STATUS . $r),
        ], fn ($v) => $v !== ''));

        // ⚠ ファイルの「期間」は読むが**保存しない**（内訳と合計の二重管理を作らない。Bug #46）。
        //    読み違えの検出にだけ使う。実測では 65 件すべてで 完了 - 開始 + 1 と一致した。
        $daysRaw = self::cell($sheet, self::COL_DAYS . $r);
        if ($daysRaw !== '' && $end !== null && ctype_digit($daysRaw)) {
            $expected = (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days + 1;
            if ((int) $daysRaw !== $expected) {
                $warnings[] = sprintf(
                    '%s: ファイルの期間 %d 日と、開始〜完了から計算した %d 日が食い違います。',
                    $where,
                    (int) $daysRaw,
                    $expected
                );
            }
        }

        return self::buildRow($group, $name, $start, $end, $notes, $order, $where, $warnings);
    }

    /**
     * 画面から返ってきた行を、読み取り時と**同じ規則**で作り直す。
     *
     * ⚠ **プレビューが返した値をそのまま信じない。** 2 段目の hidden は利用者が
     *   書き換えられるので、日付・桁・分類はここで引き直す。
     *   ⚠ 規則を確定側で書き直さないこと —— 同じ計算の 2 実装は無音で漂流する（Bug #41）。
     *   読み取りも確定も buildRow() を通る。
     *
     * @param array<int, mixed> $submitted
     * @return array{rows: array<int, array<string, mixed>>, warnings: array<int, string>, rowErrors: array<int, string>}
     */
    public static function sanitizeSubmittedRows(array $submitted): array
    {
        $rows = [];
        $warnings = [];
        $rowErrors = [];

        foreach ($submitted as $i => $raw) {
            $where = sprintf('%d 行目', $i + 1);

            if (! is_array($raw)) {
                $rowErrors[] = "{$where}: 行の形式が壊れています。";
                continue;
            }

            $group = is_scalar($raw['group'] ?? null) ? trim((string) $raw['group']) : '';
            $name  = is_scalar($raw['name'] ?? null) ? trim((string) $raw['name']) : '';
            $notes = is_scalar($raw['notes'] ?? null) ? trim((string) $raw['notes']) : '';

            // ⚠ プレビューの name は「大工程名 / 工程名」に連結済みなので、ここでは再連結しない
            if ($name === '') {
                $rowErrors[] = "{$where}: 工程名がありません。";
                continue;
            }

            $startRaw = is_scalar($raw['planned_start'] ?? null) ? trim((string) $raw['planned_start']) : '';
            $start = $startRaw === '' ? null : CsvDate::normalize($startRaw);
            if ($start === null) {
                $rowErrors[] = "{$where}: 施工開始日「{$startRaw}」を日付として読めません。";
                continue;
            }

            $endRaw = is_scalar($raw['planned_end'] ?? null) ? trim((string) $raw['planned_end']) : '';
            $end = null;
            if ($endRaw !== '') {
                $end = CsvDate::normalize($endRaw);
                if ($end === null) {
                    $rowErrors[] = "{$where}: 施工完了日「{$endRaw}」を日付として読めません。";
                    continue;
                }
                if ($end < $start) {
                    $rowErrors[] = "{$where}: 施工完了日が施工開始日より前です。";
                    continue;
                }
            }

            if (count($rows) >= self::MAX_ROWS) {
                $rowErrors[] = sprintf('工程が %d 件を超えています。', self::MAX_ROWS);
                break;
            }

            // ⚠ sort_order は受け取った値でなく**並び順から振り直す**（重複・欠番を持ち込ませない）
            $rows[] = self::buildRow($group, $name, $start, $end, $notes, count($rows) + 1, $where, $warnings, false);
        }

        return ['rows' => $rows, 'warnings' => $warnings, 'rowErrors' => $rowErrors];
    }

    /**
     * 読み取りと確定で共有する行の組み立て（桁の切り詰めと分類の決定）。
     *
     * @param array<int, string> $warnings
     * @return array<string, mixed>
     */
    private static function buildRow(
        string $group,
        string $name,
        string $start,
        ?string $end,
        string $notes,
        int $order,
        string $where,
        array &$warnings,
        bool $joinGroup = true
    ): array {
        // ⚠ **大工程名を工程名に含める**（設計書 §3 決定 2）。落とすと区別できない
        //    ——実測で「器具取付」が電気工事と給排水設備工事の 2 件ある。
        $fullName = ($joinGroup && $group !== '') ? "{$group} / {$name}" : $name;

        if (mb_strlen($fullName) > self::MAX_NAME) {
            $warnings[] = sprintf('%s: 工程名が %d 文字を超えたので切り詰めました。', $where, self::MAX_NAME);
            $fullName = mb_substr($fullName, 0, self::MAX_NAME);
        }

        if (mb_strlen($notes) > self::MAX_NOTES) {
            $warnings[] = sprintf('%s: 備考が %d 文字を超えたので切り詰めました。', $where, self::MAX_NOTES);
            $notes = mb_substr($notes, 0, self::MAX_NOTES);
        }

        return [
            'group'         => $group,
            'name'          => $fullName,
            // ⚠ 分類は**必ず大工程名から引き直す**。画面から送られてきた値を信じない
            'category'      => ScheduleImportCategory::forGroup($group)->value,
            'planned_start' => $start,
            'planned_end'   => $end,
            'notes'         => $notes,
            'sort_order'    => $order,
            'where'         => $where,
        ];
    }

    /** セルを trim 済みの文字列で読む（存在しないセルを作らない） */
    private static function cell(Worksheet $sheet, string $ref): string
    {
        if (! $sheet->cellExists($ref)) {
            return '';
        }

        $value = $sheet->getCell($ref)->getValue();

        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        // ⚠ **trim() の文字リストを使わないこと。** 文字リストは**バイト単位**なので、
        //    全角空白 U+3000（e3 80 80）を渡すと `サ`（e3 82 b5）の先頭バイトまで剥がれ、
        //    不正な UTF-8 が生まれる（2026-09-01 実測: `サッシ工事` が壊れて json_encode が
        //    false を返し、確定フォームの hidden が空になった）。
        //    全角空白も落としたいので preg_replace の /u で文字として扱う。
        return (string) preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', (string) $value);
    }
}
