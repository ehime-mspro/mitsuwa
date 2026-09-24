#!/usr/bin/env php
<?php

/**
 * Dumps the structure of a paper approval form (.xls / .xlsx) so it can be
 * compared against the parts defined in 決裁申請_要件定義書 5.5.
 *
 * Usage:
 *   php docs/tools/dump-approval-form.php <file>            list the sheets
 *   php docs/tools/dump-approval-form.php <file> <sheet>    dump one sheet
 *   php docs/tools/dump-approval-form.php <file> --all      dump every sheet
 *
 * <sheet> accepts either the sheet name or its zero-based index.
 * Output: merged ranges, then every non-empty cell as "A1 | value | =formula".
 */

require __DIR__ . '/../../vendor/autoload.php';

// The file timestamp is easier to compare against the Desktop listing in JST.
date_default_timezone_set('Asia/Tokyo');

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

const MAX_VALUE_LENGTH = 60;

$path = $argv[1] ?? null;
$target = $argv[2] ?? null;

if ($path === null) {
    fwrite(STDERR, "使い方: php docs/tools/dump-approval-form.php <ファイル> [シート名 | 番号 | --all]\n");
    exit(1);
}

if (! is_file($path)) {
    fwrite(STDERR, "ファイルが見つかりません: {$path}\n");
    exit(1);
}

printf("ファイル: %s\n", $path);
printf("大きさ  : %s バイト / 更新: %s\n\n", number_format(filesize($path)), date('Y-m-d H:i', filemtime($path)));

$reader = IOFactory::createReaderForFile($path);
$names = $reader->listWorksheetNames($path);

if ($target === null) {
    echo "=== シート一覧 ===\n";
    foreach ($names as $i => $name) {
        printf("  [%d] %s\n", $i, $name);
    }
    echo "\nシートを指定すると中身を書き出します（名前でも番号でも可）。\n";
    exit(0);
}

$wanted = $target === '--all' ? $names : [resolveSheetName($names, $target)];

$reader->setLoadSheetsOnly($wanted);
$book = $reader->load($path);

foreach ($wanted as $name) {
    dumpSheet($book->getSheetByName($name));
}

/**
 * Turns a sheet name or a zero-based index into a sheet name.
 */
function resolveSheetName(array $names, string $target): string
{
    if (ctype_digit($target)) {
        $index = (int) $target;

        if (! isset($names[$index])) {
            fwrite(STDERR, "シート番号 {$index} がありません（0〜" . (count($names) - 1) . "）\n");
            exit(1);
        }

        return $names[$index];
    }

    foreach ($names as $name) {
        if ($name === $target) {
            return $name;
        }
    }

    fwrite(STDERR, "シート「{$target}」がありません。一覧: " . implode(' / ', $names) . "\n");
    exit(1);
}

function dumpSheet(Worksheet $sheet): void
{
    printf("=== シート: %s ===\n", $sheet->getTitle());
    printf("使っている範囲: %s\n", $sheet->calculateWorksheetDimension());

    $merges = $sheet->getMergeCells();

    if ($merges !== []) {
        printf("\n-- 結合セル（%d 箇所）--\n  %s\n", count($merges), implode('  ', $merges));
    }

    echo "\n-- 中身のあるセル --\n";

    $formulaCount = 0;

    foreach ($sheet->getRowIterator() as $row) {
        $cells = $row->getCellIterator();
        $cells->setIterateOnlyExistingCells(true);

        foreach ($cells as $cell) {
            $raw = $cell->getValue();

            if ($raw === null || trim((string) $raw) === '') {
                continue;
            }

            $coordinate = $cell->getCoordinate();

            if (is_string($raw) && str_starts_with($raw, '=')) {
                $formulaCount++;
                printf("  %-6s %-*s  %s\n", $coordinate, MAX_VALUE_LENGTH, shorten(calculated($cell)), $raw);

                continue;
            }

            printf("  %-6s %s\n", $coordinate, shorten((string) $raw));
        }
    }

    printf("\n計算式のあるセル: %d 個\n\n", $formulaCount);
}

/**
 * Formula results are best-effort: broken references (#REF!) throw.
 */
function calculated($cell): string
{
    try {
        return (string) $cell->getCalculatedValue();
    } catch (\Throwable $e) {
        return '(計算できず)';
    }
}

function shorten(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value));

    return mb_strlen($value) > MAX_VALUE_LENGTH
        ? mb_substr($value, 0, MAX_VALUE_LENGTH - 1) . '…'
        : $value;
}
