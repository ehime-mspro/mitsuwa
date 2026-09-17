#!/usr/bin/env php
<?php

/**
 * Compares a new paper approval form against a baseline one (normally the
 * generic 決裁申請書.xls) and reports which labels the new form adds or drops.
 *
 * The frame (決裁No / 発信日 / 審査部門 …) is identical across the forms seen so
 * far, so "labels only in the new form" is the body of the 「記」 box — exactly
 * the part that has to be expressed with the parts in 要件定義書 5.5.
 *
 * Usage:
 *   php docs/tools/compare-approval-form.php <baseFile> <baseSheet> <newFile> <newSheet>
 *
 * <sheet> accepts either the sheet name or its zero-based index.
 */

require __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

if ($argc < 5) {
    fwrite(STDERR, "使い方: php docs/tools/compare-approval-form.php <基準ファイル> <基準シート> <新しいファイル> <新しいシート>\n");
    exit(1);
}

[, $basePath, $baseSheet, $newPath, $newSheet] = $argv;

$base = readLabels($basePath, $baseSheet);
$new = readLabels($newPath, $newSheet);

$common = array_intersect_key($base, $new);
$onlyBase = array_diff_key($base, $new);
$onlyNew = array_diff_key($new, $base);

printf("基準    : %s [%s]  見出し %d 個\n", basename($basePath), $baseSheet, count($base));
printf("新しい版: %s [%s]  見出し %d 個\n\n", basename($newPath), $newSheet, count($new));

section(sprintf('両方にある見出し（＝共通の枠組み・%d 個）', count($common)), $common);
section(sprintf('基準にしか無い見出し（%d 個）', count($onlyBase)), $onlyBase);
section(sprintf('新しい版にしか無い見出し（%d 個）★ここが判定の対象', count($onlyNew)), $onlyNew);

printf("判定の手順は docs/決裁申請_様式の調査.md の「様式が届いたときの判定」を見る。\n");

/**
 * Returns [normalized label => "A1 original text"] for every non-formula cell.
 */
function readLabels(string $path, string $sheet): array
{
    if (! is_file($path)) {
        fwrite(STDERR, "ファイルが見つかりません: {$path}\n");
        exit(1);
    }

    $reader = IOFactory::createReaderForFile($path);
    $names = $reader->listWorksheetNames($path);
    $name = ctype_digit($sheet) ? ($names[(int) $sheet] ?? null) : $sheet;

    if ($name === null || ! in_array($name, $names, true)) {
        fwrite(STDERR, "シート「{$sheet}」がありません（{$path}）。一覧: " . implode(' / ', $names) . "\n");
        exit(1);
    }

    $reader->setLoadSheetsOnly([$name]);

    return collectLabels($reader->load($path)->getSheetByName($name));
}

function collectLabels(Worksheet $sheet): array
{
    $labels = [];

    foreach ($sheet->getRowIterator() as $row) {
        $cells = $row->getCellIterator();
        $cells->setIterateOnlyExistingCells(true);

        foreach ($cells as $cell) {
            $raw = $cell->getValue();

            // Formulas describe calculations, not labels; they are reported by the dump tool.
            if (! is_string($raw) || str_starts_with($raw, '=')) {
                continue;
            }

            $key = normalize($raw);

            if ($key === '') {
                continue;
            }

            $labels[$key] ??= $cell->getCoordinate() . ' ' . trim(preg_replace('/\s+/u', ' ', $raw));
        }
    }

    return $labels;
}

/**
 * Forms space their labels out for print ("決 裁 N0"), so spacing is ignored.
 */
function normalize(string $value): string
{
    return preg_replace('/[\s\x{3000}]+/u', '', $value);
}

function section(string $title, array $labels): void
{
    printf("-- %s --\n", $title);

    if ($labels === []) {
        echo "  （なし）\n\n";

        return;
    }

    foreach ($labels as $label) {
        printf("  %s\n", $label);
    }

    echo "\n";
}
