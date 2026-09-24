#!/usr/bin/env php
<?php

/**
 * Builds the fill-in workbook for 要件定義書 16.3「ご用意いただくもの」.
 *
 * Columns follow what phase 1 actually implemented (approval-phase1 branch):
 * - 部門の管理: 会社名・期の始まりの月・表示順 / 会社・部門名・略称（6 文字まで）・
 *   アルファベット（英大文字 1〜3 文字）・表示順 / 通知メールを送ってよいドメイン
 * - 社員の CSV 一括登録: 社員番号・氏名・メールアドレス・所属部門（この並び・この見出し）。
 *   The staff sheet is not saved as CSV directly: formatted empty rows come out of Excel as
 *   ",,," lines that the importer rejects, and one import takes at most 50 rows
 *   (approval.csv_max_rows), so it is split into CSV files at registration time.
 * Items that later phases add (部門長・審査担当者・開始番号・申請の種類・送らない日) follow
 * the requirements document.
 *
 * Usage:
 *   php docs/tools/make-preparation-workbook.php <output.xlsx> [<過去台帳.xls>]
 *
 * With the past ledger, the 部門 sheet gets reference columns (count / last NO /
 * last date per 受付簿) so the start numbers can be decided from facts.
 */

require __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// Excel's default Japanese font on both Windows and macOS; Arial has no kana/kanji.
const FONT = '游ゴシック';
const COLOR_INPUT = 'FFF2CC';     // yellow: cells to fill in
const COLOR_HEADER = 'DDEBF7';
const COLOR_REFERENCE = 'F2F2F2'; // gray: reference / automatic
const COLOR_CANDIDATE = '0000FF'; // blue text: candidates we filled in
const COLOR_MUTED = '7F7F7F';

// Sheet names are referenced from formulas and validations; keep them in one place.
const S_GUIDE = '記入のしかた';
const S_COMPANY = '1_会社とドメイン';
const S_DEPT = '2_部門';
const S_STAFF = '3_社員';
const S_ROLE = '4_権限';
const S_REVIEWER = '5_審査担当者';
const S_TYPE = '6_申請の種類';
const S_SCHEDULE = '7_日程とそのほか';

// Row ranges that validations and lookups cover. The staff sheet is sized for
// "100 人以上" (要件定義書 1.2) with room to spare.
const STAFF_HEADER_ROW = 5;
const STAFF_FIRST_ROW = 6;
const STAFF_LAST_ROW = 405;
const DEPT_FIRST_ROW = 7;
const DEPT_LAST_ROW = 40;

// Seeded by database/sql/2026-09-16-approval-phase1.sql (phase 1).
const COMPANIES = [
    ['ミツワ都市開発', 5, 1],
    ['DAD', 6, 2],
    ['ZEAL', 6, 3],
];

$output = $argv[1] ?? null;
$ledgerPath = $argv[2] ?? null;

if ($output === null) {
    fwrite(STDERR, "使い方: php docs/tools/make-preparation-workbook.php <出力先.xlsx> [<過去台帳.xls>]\n");
    exit(1);
}

$ledger = $ledgerPath !== null ? readLedger($ledgerPath) : [];

$book = new Spreadsheet();
$book->getDefaultStyle()->getFont()->setName(FONT)->setSize(11);
$book->removeSheetByIndex(0);

buildGuide($book->createSheet());
buildCompanies($book->createSheet());
buildDepartments($book->createSheet(), $ledger);
buildStaff($book->createSheet());
buildRoles($book->createSheet());
buildReviewers($book->createSheet());
buildTypes($book->createSheet());
buildSchedule($book->createSheet());

// Styling leaves the last styled range selected; start each sheet at its first cell instead.
foreach ($book->getWorksheetIterator() as $sheet) {
    $sheet->setSelectedCells($sheet->getTitle() === S_STAFF ? 'A' . STAFF_FIRST_ROW : 'A1');
}
$book->setActiveSheetIndex(0);
IOFactory::createWriter($book, 'Xlsx')->save($output);

printf("作成しました: %s\n", $output);
printf("台帳の参考: %s\n", $ledger === [] ? 'なし（台帳を渡していない）' : count($ledger) . ' 冊分');

// ---------------------------------------------------------------------------
// Sheets
// ---------------------------------------------------------------------------

function buildGuide(Worksheet $s): void
{
    $s->setTitle(S_GUIDE);
    $s->getTabColor()->setRGB('4472C4');
    widths($s, ['A' => 4, 'B' => 22, 'C' => 46, 'D' => 26, 'E' => 24]);

    title($s, '決裁申請システム　ご用意いただくもの（記入用）');
    $s->setCellValue('A2', 'ここに書いていただいた内容を、決裁の管理画面に登録します（要件定義書 16.3）。わかるところから埋めてください。');

    $s->setCellValue('A4', '色の意味');
    $s->getStyle('A4')->getFont()->setBold(true);
    $legend = [
        ['黄色の欄', '記入する欄です', COLOR_INPUT, null],
        ['青い字', 'こちらで入れておいた候補です。違っていれば書き換えてください', COLOR_INPUT, COLOR_CANDIDATE],
        ['灰色の欄', '参考・自動で出る欄です。書き換えなくて大丈夫です', COLOR_REFERENCE, null],
        ['記入例', '書き方の見本です。そのまま残しておいて構いません（登録のときに飛ばします）', null, COLOR_MUTED],
        ['赤い三角', '見出しの右上に赤い三角があるところは、マウスを乗せると書き方の説明が出ます', null, null],
    ];
    $row = 5;
    foreach ($legend as [$label, $text, $fill, $color]) {
        $s->setCellValue("B{$row}", $label);
        $s->setCellValue("C{$row}", $text);
        $s->mergeCells("C{$row}:E{$row}");
        if ($fill) {
            fill($s, "B{$row}", $fill);
        }
        if ($color) {
            $s->getStyle("B{$row}")->getFont()->getColor()->setRGB($color);
        }
        box($s, "B{$row}");
        $row++;
    }

    $s->setCellValue('A11', '記入の順番');
    $s->getStyle('A11')->getFont()->setBold(true);
    header_($s, 12, ['B' => 'シート', 'C' => '書いていただくこと', 'D' => '登録する画面', 'E' => '登録できるようになる時期']);
    $steps = [
        [S_COMPANY, '会社（登録済みの確認）と、通知メールを送ってよいドメイン', '部門の管理', '段階 1 の本番反映のあと'],
        [S_DEPT, '部門の一覧・部門長・今年度の開始番号', '部門の管理', '部門は段階 1 のあと／部門長と開始番号は段階 2 のあと'],
        [S_STAFF, '社員の一覧（基幹システムをすでに使っている方も含めて全員）。登録のときに、こちらで 50 人ずつの CSV に分けます', '利用者の管理（CSV 一括登録）', '段階 1 の本番反映のあと'],
        [S_ROLE, '社長・全件閲覧者・決裁の管理者', '基幹の利用者管理（基幹の管理者が行う）', '段階 1 の本番反映のあと'],
        [S_REVIEWER, '審査部門ごとの審査担当者', '部門の管理', '段階 2 のあと'],
        [S_TYPE, '申請の種類の一覧と、契約用の分け方', '申請種類の管理', '段階 2 のあと（契約用は段階 5）'],
        [S_SCHEDULE, '切り替え日・催促を送らない日・過去の台帳の年度', '催促の設定ほか', '段階 3 のあと'],
    ];
    $row = 13;
    foreach ($steps as [$sheet, $what, $screen, $when]) {
        $s->setCellValue("B{$row}", $sheet);
        $s->getCell("B{$row}")->getHyperlink()->setUrl("sheet://'{$sheet}'!A1");
        $s->getStyle("B{$row}")->getFont()->setUnderline(true)->getColor()->setRGB('0563C1');
        $s->setCellValue("C{$row}", $what);
        $s->setCellValue("D{$row}", $screen);
        $s->setCellValue("E{$row}", $when);
        box($s, "B{$row}:E{$row}");
        wrap($s, "B{$row}:E{$row}");
        $row++;
    }

    $s->setCellValue('A21', 'ご用意いただくもの（要件定義書 16.3）の状況');
    $s->getStyle('A21')->getFont()->setBold(true);
    header_($s, 22, ['B' => '#・もの', 'C' => '状況', 'D' => 'どこで', 'E' => '']);
    $items = [
        ['1 部門の一覧', 'このファイルに記入', S_DEPT],
        ['2 申請の種類の一覧', 'このファイルに記入', S_TYPE],
        ['3 審査担当者', 'このファイルに記入', S_REVIEWER],
        ['4 社長・全件閲覧者・決裁の管理者', 'このファイルに記入', S_ROLE],
        ['5 社員の一覧', 'このファイルに記入', S_STAFF],
        ['6 メールのドメイン', 'このファイルに記入', S_COMPANY],
        ['7 決裁専用のメールアドレス', '済み（2026/09/14 に作成）', '—'],
        ['8 今年度の開始番号', 'このファイルに記入（切り替え日の直前に）', S_DEPT],
        ['9 部門専用の様式', '済み（2026/09/24 にこれで全部と確認）', '—'],
        ['10 過去の決裁の Excel 台帳', '08 年度は受領済み。ほかの年度は何年分にするかを記入', S_SCHEDULE],
        ['11 切り替え日', 'このファイルに記入', S_SCHEDULE],
        ['12 バックアップの保管場所', '済み（2026/09/14 から稼働）', '—'],
        ['13 迷惑メール対策（SPF・DKIM）', '済み（2026/09/14）', '—'],
        ['14 復元用の鍵の保管', '済み（2026/09/14 に確かめ済み）', '—'],
        ['15 本番の php.ini の調整', '稼働の前に、手順をお渡しして行う', '—'],
        ['16 催促を送らない日', 'このファイルに記入', S_SCHEDULE],
    ];
    $row = 23;
    foreach ($items as [$item, $status, $where]) {
        $s->setCellValue("B{$row}", $item);
        $s->setCellValue("C{$row}", $status);
        $s->setCellValue("D{$row}", $where);
        if (str_starts_with($status, '済み')) {
            $s->getStyle("B{$row}:D{$row}")->getFont()->getColor()->setRGB(COLOR_MUTED);
        }
        box($s, "B{$row}:D{$row}");
        wrap($s, "B{$row}:D{$row}");
        $row++;
    }

    $row += 1;
    $s->setCellValue("A{$row}", '書くときの注意');
    $s->getStyle("A{$row}")->getFont()->setBold(true);
    $notes = [
        '社員番号は、先頭の 0 も含めてそのまま書いてください（欄は文字として扱う設定にしてあります）。',
        '氏名は、姓と名の間に空白を入れてください（データ印の苗字に、空白より前を使います。例: 甲 一郎）。',
        'メールアドレスは会社のドメインのものだけ使えます。部門長・審査担当者・社長になる方は必須です。',
        'パスワードや鍵などの秘密情報は、このファイルに書かないでください。',
        'このファイルには社員の氏名とメールアドレスが入ります。社外へは送らないでください。',
    ];
    foreach ($notes as $note) {
        $row++;
        $s->setCellValue("B{$row}", '・' . $note);
        $s->mergeCells("B{$row}:E{$row}");
    }
}

function buildCompanies(Worksheet $s): void
{
    $s->setTitle(S_COMPANY);
    widths($s, ['A' => 10, 'B' => 22, 'C' => 30, 'D' => 12, 'E' => 44]);
    title($s, '会社と、通知メールを送ってよいドメイン');
    $s->setCellValue('A2', '会社の 3 行は、段階 1 で登録済みです。違っていれば書き換え、足りない会社があれば下の空いた行に足してください。');

    header_($s, 4, ['A' => '', 'B' => '会社名', 'C' => '期の始まりの月（1〜12）', 'D' => '表示順', 'E' => 'メモ']);
    $row = 5;
    foreach (COMPANIES as [$name, $month, $order]) {
        candidate($s, "B{$row}", $name);
        candidate($s, "C{$row}", $month);
        candidate($s, "D{$row}", $order);
        $row++;
    }
    input($s, "B5:E10");
    validateWholeNumber($s, "C5:C10", 1, 12, '期の始まりの月', '1〜12 の数字で入力してください（例: 5 月始まりなら 5）');
    validateTextLength($s, 'B5:B10', 50, '会社名', '50 文字までにしてください');

    $s->setCellValue('A13', '通知メールを送ってよいドメイン（メールアドレスの @ より後ろ）');
    $s->getStyle('A13')->getFont()->setBold(true);
    header_($s, 14, ['A' => '', 'B' => '会社', 'C' => 'ドメイン', 'D' => '', 'E' => 'メモ']);
    example($s, 15, ['B' => 'ミツワ都市開発', 'C' => 'example.co.jp']);
    candidate($s, 'B16', 'ミツワ都市開発');
    candidate($s, 'C16', 'mitsuwat.co.jp');
    $s->setCellValue('E16', '決裁専用のアドレス（kessai@）と同じドメイン');
    candidate($s, 'B17', 'DAD');
    candidate($s, 'B18', 'ZEAL');
    input($s, 'B16:C25');
    reference($s, 'E16:E25');
    validateList($s, 'B16:B25', "='" . S_COMPANY . "'!\$B\$5:\$B\$10", '会社', '上の表の会社から選んでください');
    note($s, 'C14', "個人のメール（gmail.com など）は登録しないでください。ここに無いドメインのアドレスには、通知メールを送りません（要件定義書 8.2）。");
    $s->freezePane('A5');
}

function buildDepartments(Worksheet $s, array $ledger): void
{
    $s->setTitle(S_DEPT);
    widths($s, ['A' => 10, 'B' => 16, 'C' => 24, 'D' => 14, 'E' => 12, 'F' => 8, 'G' => 14, 'H' => 14, 'I' => 16, 'J' => 12,
        'K' => 18, 'L' => 10, 'M' => 12, 'N' => 12, 'O' => 64]);
    title($s, '部門の一覧');
    $s->setCellValue('A2', '過去の台帳（受付簿のシート）から候補を入れてあります。部門名は正式な名前に直してください。要らない行は消し、DAD・ZEAL の部門は空いた行に足してください。');
    $s->setCellValue('A3', 'アルファベットは決裁No の真ん中（R8-J-001 の J）になります。受付簿が分かれている系統（ＪＢ など）は、別の部門として登録します（要件定義書 6.1）。');

    header_($s, 5, [
        'A' => '', 'B' => '会社', 'C' => '部門名', 'D' => '略称（6 文字まで）', 'E' => 'アルファベット', 'F' => '表示順',
        'G' => '部門長の社員番号', 'H' => '部門長の氏名', 'I' => '確認（自動）', 'J' => '今年度の開始番号',
        'K' => '参考: 台帳のシート', 'L' => '参考: 08年度の件数', 'M' => '参考: 最後のＮＯ', 'N' => '参考: 最後の日付', 'O' => 'メモ',
    ]);
    $s->getRowDimension(5)->setRowHeight(32);
    wrap($s, 'A5:O5');
    note($s, 'D5', 'データ印の上段に入る名前です（要件定義書 9.1）。6 文字まで。');
    note($s, 'E5', '半角の英大文字 1〜3 文字です（Ｊ ではなく J）。グループの中で重ならないようにします。');
    note($s, 'I5', '部門長の社員番号から、3_社員 のシートの氏名を自動で出します。「社員の一覧に無い番号」と出たら、番号を見直してください。');
    note($s, 'J5', '切り替え日の直前に、紙の受付簿の最後の番号＋1 を書いてください（紙で 20 まで使っていたら 21）。空なら 1 から始まります（要件定義書 6.4）。');

    example($s, 6, ['B' => 'ミツワ都市開発', 'C' => '〇〇事業部', 'D' => '〇〇', 'E' => 'X', 'F' => 99,
        'G' => 'A0001', 'H' => '甲 一郎', 'J' => 21]);

    $row = DEPT_FIRST_ROW;
    foreach (departmentCandidates($ledger) as $d) {
        candidate($s, "B{$row}", $d['company']);
        if ($d['name'] !== '') {
            candidate($s, "C{$row}", $d['name']);
        }
        candidate($s, "D{$row}", $d['short']);
        candidate($s, "E{$row}", $d['code']);
        candidate($s, "F{$row}", $d['order']);
        $s->setCellValue("K{$row}", $d['sheet']);
        $s->setCellValue("L{$row}", $d['count']);
        $s->setCellValue("M{$row}", $d['lastNo']);
        $s->setCellValue("N{$row}", $d['lastDate']);
        $s->setCellValue("O{$row}", $d['memo']);
        $row++;
    }

    input($s, 'B' . DEPT_FIRST_ROW . ':H' . DEPT_LAST_ROW);
    input($s, 'J' . DEPT_FIRST_ROW . ':J' . DEPT_LAST_ROW);
    reference($s, 'I' . DEPT_FIRST_ROW . ':I' . DEPT_LAST_ROW);
    reference($s, 'K' . DEPT_FIRST_ROW . ':N' . DEPT_LAST_ROW);
    box($s, 'O' . DEPT_FIRST_ROW . ':O' . DEPT_LAST_ROW);
    wrap($s, 'O' . DEPT_FIRST_ROW . ':O' . DEPT_LAST_ROW);
    textFormat($s, 'G' . DEPT_FIRST_ROW . ':G' . DEPT_LAST_ROW);

    for ($r = DEPT_FIRST_ROW; $r <= DEPT_LAST_ROW; $r++) {
        $s->setCellValue("I{$r}", staffLookup("G{$r}"));
    }

    validateList($s, 'B' . DEPT_FIRST_ROW . ':B' . DEPT_LAST_ROW, "='" . S_COMPANY . "'!\$B\$5:\$B\$10", '会社', '1_会社とドメイン の会社から選んでください');
    validateTextLength($s, 'C' . DEPT_FIRST_ROW . ':C' . DEPT_LAST_ROW, 50, '部門名', '50 文字までにしてください');
    validateTextLength($s, 'D' . DEPT_FIRST_ROW . ':D' . DEPT_LAST_ROW, 6, '略称', '6 文字までにしてください（データ印に入りきらないため）');
    // LENB counts a full-width letter as 2 in Japanese Excel, which catches "Ｊ" typed instead of "J".
    // Warning style only: the rule cannot be tried in Excel here, so it must never block a valid entry.
    validateCustom($s, 'E' . DEPT_FIRST_ROW . ':E' . DEPT_LAST_ROW,
        '=AND(LEN(E' . DEPT_FIRST_ROW . ')>=1,LEN(E' . DEPT_FIRST_ROW . ')<=3,EXACT(E' . DEPT_FIRST_ROW . ',UPPER(E' . DEPT_FIRST_ROW . ')),LENB(E' . DEPT_FIRST_ROW . ')=LEN(E' . DEPT_FIRST_ROW . '))',
        'アルファベット', '半角の英大文字 1〜3 文字で入力してください（Ｊ ではなく J）');
    validateWholeNumber($s, 'J' . DEPT_FIRST_ROW . ':J' . DEPT_LAST_ROW, 1, 9999, '今年度の開始番号', '1 以上の数字で入力してください');
    $s->freezePane('D' . 6);
}

function buildStaff(Worksheet $s): void
{
    $s->setTitle(S_STAFF);
    widths($s, ['A' => 16, 'B' => 20, 'C' => 34, 'D' => 18, 'E' => 40]);
    title($s, '社員の一覧');
    $s->setCellValue('A2', '決裁を使う社員の全員分です。基幹システムをすでに使っている方も含めてください（社員番号と所属部門を設定します。要件定義書 12.3）。');
    $s->setCellValue('A3', '見出し（5 行目）は取り込みの CSV と同じ並びです。登録のときに、こちらで 50 人ずつの CSV に分けて取り込みます。');

    // The example sits above the header so that the header-and-data block never contains it.
    foreach (['A' => 'A0001', 'B' => '甲 一郎', 'C' => 'ichiro@mitsuwat.co.jp', 'D' => 'J,JB', 'E' => '← 記入例（この行は取り込みません）'] as $col => $value) {
        $s->getCell("{$col}4")->setValueExplicit($value, DataType::TYPE_STRING);
    }
    $s->getStyle('A4:E4')->getFont()->setItalic(true)->getColor()->setRGB(COLOR_MUTED);
    box($s, 'A4:D4');

    header_($s, STAFF_HEADER_ROW, ['A' => '社員番号', 'B' => '氏名', 'C' => 'メールアドレス', 'D' => '所属部門', 'E' => 'メモ（取り込みません）']);
    note($s, 'A' . STAFF_HEADER_ROW, "ログイン ID になります。英数字とハイフンで 20 文字まで。先頭の 0 もそのまま書いてください。");
    note($s, 'B' . STAFF_HEADER_ROW, "姓と名の間に空白を入れてください（データ印の苗字に使います）。");
    note($s, 'C' . STAFF_HEADER_ROW, "会社のドメインのアドレスだけ使えます。無い方は空欄で構いません（部門長・審査担当者・社長になる方は必須）。");
    note($s, 'D' . STAFF_HEADER_ROW, "2_部門 のアルファベットを書きます。兼務は「J,JB」のように並べてください。");

    $data = 'A' . STAFF_FIRST_ROW . ':D' . STAFF_LAST_ROW;
    input($s, $data);
    box($s, 'E' . STAFF_FIRST_ROW . ':E' . STAFF_LAST_ROW);
    textFormat($s, 'A' . STAFF_FIRST_ROW . ':A' . STAFF_LAST_ROW);
    validateTextLength($s, 'A' . STAFF_FIRST_ROW . ':A' . STAFF_LAST_ROW, 20, '社員番号', '英数字とハイフンで 20 文字までにしてください');
    validateTextLength($s, 'B' . STAFF_FIRST_ROW . ':B' . STAFF_LAST_ROW, 100, '氏名', '100 文字までにしてください');
    $s->freezePane('A' . STAFF_FIRST_ROW);
}

function buildRoles(Worksheet $s): void
{
    $s->setTitle(S_ROLE);
    widths($s, ['A' => 10, 'B' => 18, 'C' => 16, 'D' => 20, 'E' => 20, 'F' => 50]);
    title($s, '社長・全件閲覧者・決裁の管理者');
    $s->setCellValue('A2', '社長は 1 人です。社長を全件閲覧者にも入れておくと、回覧の途中や過去の申請もいつでも見られます（要件定義書 7 章）。');
    $s->setCellValue('A3', '指定は基幹の管理者が行います。1 人が複数の役割を持って構いません（その場合は役割ごとに 1 行ずつ）。');

    header_($s, 5, ['A' => '', 'B' => '役割', 'C' => '社員番号', 'D' => '氏名', 'E' => '確認（自動）', 'F' => 'メモ']);
    example($s, 6, ['B' => '決裁の管理者', 'C' => 'A0001', 'D' => '甲 一郎']);
    $roles = ['社長', '全件閲覧者', '全件閲覧者', '全件閲覧者', '全件閲覧者', '決裁の管理者', '決裁の管理者'];
    $row = 7;
    foreach ($roles as $role) {
        candidate($s, "B{$row}", $role);
        $row++;
    }
    candidate($s, 'F7', '社長はメールアドレスが必要です（3_社員 に書いてください）');
    input($s, 'B7:D25');
    textFormat($s, 'C7:C25');
    reference($s, 'E7:E25');
    for ($r = 7; $r <= 25; $r++) {
        $s->setCellValue("E{$r}", staffLookup("C{$r}"));
    }
    box($s, 'F7:F25');
    validateList($s, 'B7:B25', '"社長,全件閲覧者,決裁の管理者"', '役割', '社長・全件閲覧者・決裁の管理者から選んでください');
    $s->freezePane('A7');
}

function buildReviewers(Worksheet $s): void
{
    $s->setTitle(S_REVIEWER);
    widths($s, ['A' => 10, 'B' => 24, 'C' => 16, 'D' => 20, 'E' => 20, 'F' => 50]);
    title($s, '審査部門ごとの審査担当者');
    $s->setCellValue('A2', '申請の種類ごとに決める「審査部門」で、意見（可・保留・否）を入れる方です。1 つの審査部門に 1 人以上。メールアドレスが必要です。');
    $s->setCellValue('A3', '審査部門の方が自分で申請することがあるなら、2 人以上にしてください（自分の申請には意見を入れられないため。要件定義書 4.3）。');

    header_($s, 5, ['A' => '', 'B' => '審査部門', 'C' => '社員番号', 'D' => '氏名', 'E' => '確認（自動）', 'F' => 'メモ']);
    example($s, 6, ['B' => '〇〇事業部', 'C' => 'A0001', 'D' => '甲 一郎']);
    input($s, 'B7:D40');
    textFormat($s, 'C7:C40');
    reference($s, 'E7:E40');
    for ($r = 7; $r <= 40; $r++) {
        $s->setCellValue("E{$r}", staffLookup("C{$r}"));
    }
    box($s, 'F7:F40');
    validateList($s, 'B7:B40', "='" . S_DEPT . "'!\$C\$" . DEPT_FIRST_ROW . ':$C$' . DEPT_LAST_ROW, '審査部門', '2_部門 の部門名から選んでください');
    $s->freezePane('A7');
}

function buildTypes(Worksheet $s): void
{
    $s->setTitle(S_TYPE);
    widths($s, ['A' => 10, 'B' => 28, 'C' => 16, 'D' => 20, 'E' => 26, 'F' => 28, 'G' => 30, 'H' => 8, 'I' => 50]);
    title($s, '申請の種類');
    $s->setCellValue('A2', 'よく使う種類を候補として入れてあります。名前を変える・足す・消すのは自由です。どの部門でも使う種類は「使える部門」を空欄にしてください。');

    $s->setCellValue('B4', '契約用の分け方');
    $s->getStyle('B4')->getFont()->setBold(true);
    input($s, 'C4:G4');
    $s->mergeCells('C4:G4');
    validateList($s, 'C4', '"(a) 契約用を 1 つにまとめ、件名は毎回書く,(b) 契約の種類ごとに分け、件名の決まり文句を付ける"', '契約用の分け方', '(a) か (b) を選んでください');
    $s->setCellValue('C5', '↑ 欄を押すと (a)・(b) を選べます。(a) がおすすめです（契約の種類によって件名が変わり、決まり文句が合わないことが多いため。要件定義書 5.5.7）');
    $s->mergeCells('C5:I5');
    $s->getStyle('C5')->getFont()->setSize(10)->getColor()->setRGB(COLOR_MUTED);
    note($s, 'C4', "住宅事業部とミツワ不動産の契約用の様式（住宅・不動産決裁申請書.xls）の扱いです（要件定義書 5.5.7）。\n(a) がおすすめです。契約の種類は建売売買・土地売買・建物請負・土地建物などいろいろあり、様式の決まり文句「様請負新築工事契約の件」が合うのは請負の契約だけだったためです。\n(b) なら、この下の「住宅・不動産の契約用」の行を契約の種類ごとに分け、それぞれに決まり文句（例: 様建売売買契約の件）を書いてください。");

    header_($s, 6, ['A' => '', 'B' => '種類名', 'C' => '本文の形', 'D' => '審査部門', 'E' => '使える部門（空欄＝全部門）', 'F' => '件名の決まり文句',
        'G' => '見出しを変える場合（5W2H の種類だけ）', 'H' => '表示順', 'I' => 'メモ']);
    $s->getRowDimension(6)->setRowHeight(32);
    wrap($s, 'A6:I6');
    note($s, 'C6', "5W2H の見出し: 汎用の様式（なぜ・何を・誰が…の箇条書き）\n金額の明細表: 住宅・不動産の契約用の様式（販売金額・工事原価の表）");
    note($s, 'F6', '件名の後ろに自動で付く文字です。「山田」と入れると「山田様追加・少額工事契約の件」になります。空欄なら件名は毎回書きます（要件定義書 5.5.3）。');
    example($s, 7, ['B' => '〇〇の申請', 'C' => '5W2H の見出し', 'D' => '〇〇部', 'H' => 99]);

    $types = [
        ['購入・発注', '5W2H の見出し', '', '', '', 1, ''],
        ['契約', '5W2H の見出し', '', '', '', 2, '売買・請負以外の契約（業務委託・管理受託など）'],
        ['人事', '5W2H の見出し', '', '', '', 3, ''],
        ['その他', '5W2H の見出し', '', '', '', 4, ''],
        ['住宅・不動産の契約用', '金額の明細表', '', '住宅事業部、ミツワ不動産', '', 5, '明細表の行などの設定は要件定義書 5.5.7 のとおり（記入不要）。(b) を選んだら、この行を契約の種類ごとに分ける'],
        ['追加・少額工事契約用', '金額の明細表', '', '住宅（少額・追加工事）', '様追加・少額工事契約の件', 6, '設定は要件定義書 5.5.7 のとおり（記入不要）'],
    ];
    $row = 8;
    foreach ($types as [$name, $body, $reviewer, $depts, $suffix, $order, $memo]) {
        candidate($s, "B{$row}", $name);
        candidate($s, "C{$row}", $body);
        if ($depts !== '') {
            candidate($s, "E{$row}", $depts);
        }
        if ($suffix !== '') {
            candidate($s, "F{$row}", $suffix);
        }
        candidate($s, "H{$row}", $order);
        if ($memo !== '') {
            $s->setCellValue("I{$row}", $memo);
        }
        $row++;
    }
    input($s, 'B8:H30');
    box($s, 'I8:I30');
    wrap($s, 'I8:I30');
    wrap($s, 'E8:G30');
    validateList($s, 'C8:C30', '"5W2H の見出し,金額の明細表"', '本文の形', '5W2H の見出し か 金額の明細表 を選んでください');
    validateList($s, 'D8:D30', "='" . S_DEPT . "'!\$C\$" . DEPT_FIRST_ROW . ':$C$' . DEPT_LAST_ROW, '審査部門', '2_部門 の部門名から選んでください');
    $s->freezePane('C8');
}

function buildSchedule(Worksheet $s): void
{
    $s->setTitle(S_SCHEDULE);
    widths($s, ['A' => 4, 'B' => 30, 'C' => 16, 'D' => 16, 'E' => 18, 'F' => 40]);
    title($s, '日程とそのほか');

    header_($s, 3, ['A' => '', 'B' => '決めること', 'C' => '記入', 'D' => '', 'E' => '', 'F' => 'メモ']);
    $s->setCellValue('B4', '切り替え日（全社で使い始める日）');
    $s->setCellValue('F4', 'この日より前に紙で出した申請は、紙のまま最後まで回します（要件定義書 16.2）');
    $s->setCellValue('B5', '過去の台帳を何年度分取り込むか');
    $s->setCellValue('F5', '08 年度は受け取り済みです。稼働したあとで足すこともできます（要件定義書 11.4）');
    input($s, 'C4:E5');
    $s->mergeCells('C4:E4');
    $s->mergeCells('C5:E5');
    $s->getStyle('C4')->getNumberFormat()->setFormatCode('yyyy/mm/dd');
    box($s, 'B4:B5');
    box($s, 'F4:F5');
    wrap($s, 'F4:F5');
    $s->getRowDimension(4)->setRowHeight(30);
    $s->getRowDimension(5)->setRowHeight(30);

    $s->setCellValue('A8', '催促メールを送らない日（土曜・日曜・祝日は自動で判定するので不要です）');
    $s->getStyle('A8')->getFont()->setBold(true);
    header_($s, 9, ['A' => '', 'B' => '説明', 'C' => '開始日', 'D' => '終了日', 'E' => '毎年繰り返す', 'F' => 'メモ']);
    note($s, 'E9', '「はい」にすると、毎年同じ月日に送らなくなります。年によって変わる休みは「いいえ」にして、その年の分だけ書いてください（要件定義書 8.3）。');
    example($s, 10, ['B' => '夏季休業', 'C' => ExcelDate::PHPToExcel(new DateTimeImmutable('2027-08-13')),
        'D' => ExcelDate::PHPToExcel(new DateTimeImmutable('2027-08-16')), 'E' => 'いいえ']);
    candidate($s, 'B11', '年末年始');
    candidate($s, 'C11', ExcelDate::PHPToExcel(new DateTimeImmutable('2026-12-29')));
    candidate($s, 'D11', ExcelDate::PHPToExcel(new DateTimeImmutable('2027-01-03')));
    candidate($s, 'E11', 'はい');
    $s->setCellValue('F11', '御社の実際の休みに合わせて直してください');
    input($s, 'B11:E25');
    box($s, 'F11:F25');
    $s->getStyle('C10:D25')->getNumberFormat()->setFormatCode('yyyy/mm/dd');
    validateList($s, 'E11:E25', '"はい,いいえ"', '毎年繰り返す', 'はい か いいえ を選んでください');
    validateDate($s, 'C11:D25');
}

// ---------------------------------------------------------------------------
// Past ledger (optional)
// ---------------------------------------------------------------------------

/**
 * Reads count / last NO / last date for every 受付簿 sheet (the ones named "Ｘ・名前").
 * Layout per docs/決裁申請_過去台帳の調査.md: two blocks per sheet (B-E and G-J), a row is
 * real only when its date cell is filled, header rows repeat and contain "日".
 *
 * @return list<array{sheet: string, code: string, label: string, count: int, lastNo: int|string, lastDate: string}>
 */
function readLedger(string $path): array
{
    if (! is_file($path)) {
        fwrite(STDERR, "台帳が見つかりません: {$path}\n");
        exit(1);
    }

    $reader = IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(true);
    $names = array_values(array_filter($reader->listWorksheetNames($path), fn ($n) => str_contains($n, '・') && ! str_contains($n, '金額')));
    $reader->setLoadSheetsOnly($names);
    $book = $reader->load($path);

    $result = [];
    foreach ($names as $name) {
        $sheet = $book->getSheetByName($name);
        $count = 0;
        $last = ['no' => '', 'date' => ''];
        foreach ([['B', 'C'], ['G', 'H']] as [$noCol, $dateCol]) {
            for ($r = 5; $r <= 140; $r++) {
                $date = trim((string) $sheet->getCell("{$dateCol}{$r}")->getValue());
                if ($date === '' || str_contains($date, '日')) {
                    continue;
                }
                $count++;
                $no = (int) $sheet->getCell("{$noCol}{$r}")->getValue();
                if ($no >= (int) $last['no']) {
                    $last = ['no' => $no, 'date' => $date];
                }
            }
        }
        [$mark, $label] = explode('・', $name, 2);
        $result[] = [
            'sheet' => $name,
            'code' => mb_convert_kana($mark, 'r', 'UTF-8'),
            'label' => $label,
            'count' => $count,
            'lastNo' => $count > 0 ? $last['no'] : '',
            'lastDate' => $count > 0 ? '令和 ' . $last['date'] : '',
        ];
    }

    return $result;
}

/**
 * Candidate rows for the 部門 sheet. Known names come from the requirements document
 * (住宅事業部・住宅（少額・追加工事）: 5.5.7 / 6.1); the others are the ledger's sheet labels.
 */
function departmentCandidates(array $ledger): array
{
    $known = [
        'J' => ['name' => '住宅事業部', 'memo' => ''],
        'JB' => ['name' => '住宅（少額・追加工事）', 'memo' => '08 年度は全件が少額工事・追加工事。部門長は住宅事業部と同じ方（要件定義書 6.1）'],
        'MB' => ['name' => 'マンション', 'memo' => '08 年度は 0 件。ミツワ不動産（M）とは事業名が違う。要るかどうか確かめてください'],
    ];

    $rows = [];
    $order = 1;
    foreach ($ledger as $entry) {
        $code = $entry['code'];
        $isB = strlen($code) === 2 && str_ends_with($code, 'B');
        $name = $known[$code]['name'] ?? ($isB ? '' : $entry['label']);
        $memo = $known[$code]['memo'] ?? ($isB
            ? ($entry['count'] === 0 ? '08 年度は 0 件。この系統（Ｂ）が何を指すか・要るかどうかを確かめて、要るなら部門名を付けてください' : 'Ｂ 系統。何を指すかを確かめて部門名を付けてください')
            : '');
        $rows[] = [
            'company' => 'ミツワ都市開発',
            'name' => $name,
            'short' => $entry['label'],
            'code' => $code,
            'order' => $order++,
            'sheet' => $entry['sheet'],
            'count' => $entry['count'],
            'lastNo' => $entry['lastNo'],
            'lastDate' => $entry['lastDate'],
            'memo' => $memo,
        ];
    }

    return $rows;
}

// ---------------------------------------------------------------------------
// Cell helpers
// ---------------------------------------------------------------------------

function title(Worksheet $s, string $text): void
{
    $s->setCellValue('A1', $text);
    $s->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $s->getRowDimension(1)->setRowHeight(24);
}

function widths(Worksheet $s, array $widths): void
{
    foreach ($widths as $col => $w) {
        $s->getColumnDimension($col)->setWidth($w);
    }
}

function header_(Worksheet $s, int $row, array $labels): void
{
    foreach ($labels as $col => $label) {
        if ($label === '') {
            continue;
        }
        $s->setCellValue("{$col}{$row}", $label);
        $style = $s->getStyle("{$col}{$row}");
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(COLOR_HEADER);
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        box($s, "{$col}{$row}");
    }
}

function example(Worksheet $s, int $row, array $values): void
{
    $s->setCellValue("A{$row}", '記入例');
    foreach ($values as $col => $value) {
        $s->setCellValue("{$col}{$row}", $value);
    }
    $last = $s->getHighestColumn(max(1, $row - 1));
    $range = "A{$row}:{$last}{$row}";
    $s->getStyle($range)->getFont()->setItalic(true)->getColor()->setRGB(COLOR_MUTED);
    box($s, $range);
}

function candidate(Worksheet $s, string $cell, mixed $value): void
{
    if (is_string($value)) {
        $s->getCell($cell)->setValueExplicit($value, DataType::TYPE_STRING);
    } else {
        $s->setCellValue($cell, $value);
    }
    $s->getStyle($cell)->getFont()->getColor()->setRGB(COLOR_CANDIDATE);
}

function input(Worksheet $s, string $range): void
{
    fill($s, $range, COLOR_INPUT);
    box($s, $range);
}

function reference(Worksheet $s, string $range): void
{
    fill($s, $range, COLOR_REFERENCE);
    box($s, $range);
    $s->getStyle($range)->getFont()->getColor()->setRGB('595959');
}

function fill(Worksheet $s, string $range, string $rgb): void
{
    $s->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
}

function box(Worksheet $s, string $range): void
{
    $s->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('BFBFBF');
}

function wrap(Worksheet $s, string $range): void
{
    $s->getStyle($range)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
}

function textFormat(Worksheet $s, string $range): void
{
    $s->getStyle($range)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
}

function note(Worksheet $s, string $cell, string $text): void
{
    $comment = $s->getComment($cell);
    $comment->getText()->createTextRun($text);
    $comment->setWidth('320pt')->setHeight('110pt');
}

/**
 * Shows the name registered for this employee number on the staff sheet.
 */
function staffLookup(string $numberCell): string
{
    $staff = "'" . S_STAFF . "'";
    $first = STAFF_FIRST_ROW;
    $last = STAFF_LAST_ROW;

    return "=IF({$numberCell}=\"\",\"\",IFERROR(INDEX({$staff}!\$B\${$first}:\$B\${$last},MATCH({$numberCell},{$staff}!\$A\${$first}:\$A\${$last},0)),\"社員の一覧に無い番号\"))";
}

function validation(Worksheet $s, string $range, string $type, string $title, string $message, string $style = DataValidation::STYLE_STOP): DataValidation
{
    $v = new DataValidation();
    $v->setType($type)
        ->setErrorStyle($style)
        ->setAllowBlank(true)
        ->setShowErrorMessage(true)
        ->setShowInputMessage(false)
        ->setErrorTitle($title)
        ->setError($message);
    $s->setDataValidation($range, $v);

    return $v;
}

function validateList(Worksheet $s, string $range, string $formula, string $title, string $message): void
{
    // <formula1> must not start with "="; Excel reports a damaged file otherwise.
    validation($s, $range, DataValidation::TYPE_LIST, $title, $message)
        ->setShowDropDown(true)
        ->setFormula1(ltrim($formula, '='));
}

function validateWholeNumber(Worksheet $s, string $range, int $min, int $max, string $title, string $message): void
{
    validation($s, $range, DataValidation::TYPE_WHOLE, $title, $message)
        ->setOperator(DataValidation::OPERATOR_BETWEEN)
        ->setFormula1((string) $min)
        ->setFormula2((string) $max);
}

function validateTextLength(Worksheet $s, string $range, int $max, string $title, string $message): void
{
    validation($s, $range, DataValidation::TYPE_TEXTLENGTH, $title, $message)
        ->setOperator(DataValidation::OPERATOR_LESSTHANOREQUAL)
        ->setFormula1((string) $max);
}

function validateCustom(Worksheet $s, string $range, string $formula, string $title, string $message): void
{
    validation($s, $range, DataValidation::TYPE_CUSTOM, $title, $message, DataValidation::STYLE_WARNING)
        ->setFormula1(ltrim($formula, '='));
}

function validateDate(Worksheet $s, string $range): void
{
    validation($s, $range, DataValidation::TYPE_DATE, '日付', '2026/12/29 のような日付で入力してください')
        ->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL)
        ->setFormula1((string) ExcelDate::PHPToExcel(new DateTimeImmutable('2026-01-01')));
}
