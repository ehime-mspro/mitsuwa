<?php

namespace Tests\Unit\Support;

use App\Support\AndpadScheduleSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;

/**
 * ANDPAD の一覧形式 xlsx の解析（プラン Task 4）。
 *
 * ⚠ **実ファイルと合成データの両方が要る。**
 *   実ファイルは ANDPAD 特有の構造（2 シート・ページ番号行・文字列の日付）を持つが、
 *   桁の上限には当たらない（工程名 最長 22 文字 / 備考 最長 174 文字）。
 *   よって**切り詰めの検証は合成データでしか書けない** —— 実ファイルだけで測ると
 *   切り詰めを消しても緑になる。
 */
class AndpadScheduleSheetTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../fixtures/andpad/list-format.xlsx';

    /** @var array<int, string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }
        $this->temporary = [];
        parent::tearDown();
    }

    // ============================================================
    // 実ファイル（加工版）
    // ============================================================

    public function test_it_recognises_the_list_format(): void
    {
        $this->assertSame(AndpadScheduleSheet::FORMAT_LIST, AndpadScheduleSheet::detectFormat(self::FIXTURE));
    }

    /**
     * ⚠ **67 でも 60 でもなく 65。**
     *   67 になるなら各シート末尾のページ番号行（A 列に '10' だけ）を拾っている。
     *   60 になるなら 2 枚目を読んでいない。
     */
    public function test_it_reads_every_step_from_every_sheet(): void
    {
        $result = AndpadScheduleSheet::read(self::FIXTURE);

        $this->assertCount(65, $result['rows']);
        $this->assertSame([], $result['rowErrors']);
        $this->assertSame([], $result['warnings']);
    }

    /**
     * ⚠ **件数だけでは 2 枚目を読んでいる証明にならない**（1 枚目を 65 行読んだ場合と
     *   区別が付かない）。2 枚目にしかない工程を名指しで固定する。
     */
    public function test_it_keeps_the_five_steps_that_only_exist_on_the_second_sheet(): void
    {
        $names = array_column(AndpadScheduleSheet::read(self::FIXTURE)['rows'], 'name');

        $this->assertSame([
            '検査 / 外壁下地検査',
            '検査 / 施工状況外皮省エネ(断熱）検査',
            '検査 / ２次検査（木完検査）',
            '検査 / 竣工検査',
            '外構工事 / 外構工事',
        ], array_slice($names, -5), '2 枚目の 5 件が落ちている');
    }

    public function test_no_page_number_or_header_row_is_taken_as_a_step(): void
    {
        $names = array_column(AndpadScheduleSheet::read(self::FIXTURE)['rows'], 'name');

        // 各シート末尾の `A='10'`（印刷のページ番号）
        $this->assertNotContains('10', $names);
        $this->assertSame([], array_values(array_filter($names, fn ($n) => str_starts_with($n, '10 /'))));

        // 2 枚目にも繰り返される見出し行
        $this->assertSame([], array_values(array_filter($names, fn ($n) => str_contains($n, '大工程名'))));
    }

    /** ⚠ 読み違えの検出。実測では 65 件すべてで成り立つ */
    public function test_every_step_satisfies_andpad_duration_arithmetic(): void
    {
        $rows = AndpadScheduleSheet::read(self::FIXTURE)['rows'];

        $this->assertCount(65, $rows, '走査の空振り防止');

        foreach ($rows as $row) {
            $this->assertNotNull($row['planned_end'], "{$row['name']}: 完了日が無い");
            $this->assertLessThanOrEqual(
                $row['planned_end'],
                $row['planned_start'],
                "{$row['name']}: 開始が完了より後"
            );
        }

        $starts = array_column($rows, 'planned_start');
        $ends = array_column($rows, 'planned_end');
        $this->assertSame('2026-07-23', min($starts));
        $this->assertSame('2026-12-25', max($ends));
    }

    /**
     * 設計書 §3 決定 2 の根拠。大工程名を落とすと 2 件が同名になって区別できない。
     */
    public function test_the_group_name_disambiguates_steps_that_share_a_name(): void
    {
        $names = array_column(AndpadScheduleSheet::read(self::FIXTURE)['rows'], 'name');

        $shared = array_values(array_filter($names, fn ($n) => str_contains($n, '器具取付')));

        $this->assertSame(['電気工事 / 器具取付', '給排水設備工事 / 器具取付'], $shared);
        $this->assertCount(2, array_unique($shared), '大工程名を落とすと 1 件に潰れる');
    }

    /** ⚠ 分類が実装から実際に呼ばれていること（マッピング単体のテストでは測れない） */
    public function test_it_assigns_categories_from_the_group_name(): void
    {
        $rows = AndpadScheduleSheet::read(self::FIXTURE)['rows'];

        $counts = array_count_values(array_column($rows, 'category'));
        ksort($counts);

        $this->assertSame(['other' => 4, 'permit' => 6, 'work' => 55], $counts);
    }

    public function test_it_reads_the_header_block(): void
    {
        $result = AndpadScheduleSheet::read(self::FIXTURE);

        $this->assertSame('JG見本町3号地 分譲住宅新築工事様邸', $result['site_name']);
        $this->assertSame('愛媛県松山市見本町1丁目1-1、1-2', $result['address']);
        // ⚠ 工事期間は表示専用。実データの最小 2026-07-23 と一致しないのが正しい姿
        $this->assertSame('2026/07/28〜2026/12/25', $result['period']);
        $this->assertNotSame(
            '2026/07/23',
            substr((string) $result['period'], 0, 10),
            '工事期間が実データの範囲と一致しないことは既知（検算に使わない）'
        );
    }

    public function test_rows_are_numbered_sequentially_across_sheets(): void
    {
        $orders = array_column(AndpadScheduleSheet::read(self::FIXTURE)['rows'], 'sort_order');

        $this->assertSame(range(1, 65), $orders);
    }

    /** 担当会社が空でも取り込む（実測で 5 件ある） */
    public function test_steps_without_a_company_are_still_imported(): void
    {
        $rows = AndpadScheduleSheet::read(self::FIXTURE)['rows'];

        $this->assertCount(65, $rows);
        $this->assertSame([], array_values(array_filter($rows, fn ($r) => $r['notes'] === '')),
            '状態は全件あるので備考が空になる行は無い');
    }

    // ============================================================
    // 合成データ（実ファイルは桁の上限に当たらないので、ここでしか測れない）
    // ============================================================

    public function test_it_truncates_and_warns_on_a_long_step_name(): void
    {
        $path = $this->makeListXlsx([
            ['A' => str_repeat('大', 60), 'B' => str_repeat('名', 60), 'E' => '2026/07/01', 'H' => '2026/07/01', 'K' => '1'],
        ]);

        $result = AndpadScheduleSheet::read($path);

        $this->assertSame(AndpadScheduleSheet::MAX_NAME, mb_strlen($result['rows'][0]['name']));
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('工程名', $result['warnings'][0]);
    }

    public function test_it_truncates_and_warns_on_long_notes(): void
    {
        $path = $this->makeListXlsx([
            ['A' => '仮設工事', 'B' => '設置', 'C' => str_repeat('社', 200), 'D' => str_repeat('者', 200),
             'E' => '2026/07/01', 'H' => '2026/07/01', 'K' => '1'],
        ]);

        $result = AndpadScheduleSheet::read($path);

        $this->assertSame(AndpadScheduleSheet::MAX_NOTES, mb_strlen($result['rows'][0]['notes']));
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('備考', $result['warnings'][0]);
    }

    /**
     * ⚠ **`strtotime()` に戻すと通ってしまう日付**（Bug #54）。
     *   2026/02/30 は 3/2 と解釈されるのに、関数は入力文字列をそのまま返す。
     */
    public function test_it_rejects_a_date_that_does_not_exist(): void
    {
        $path = $this->makeListXlsx([
            ['A' => '仮設工事', 'B' => '設置', 'E' => '2026/02/30', 'H' => '2026/03/01', 'K' => '1'],
        ]);

        $result = AndpadScheduleSheet::read($path);

        $this->assertSame([], $result['rows'], '存在しない日付の行を取り込んではいけない');
        $this->assertCount(1, $result['rowErrors']);
        $this->assertStringContainsString('2026/02/30', $result['rowErrors'][0]);
    }

    public function test_it_rejects_a_row_whose_end_is_before_its_start(): void
    {
        $path = $this->makeListXlsx([
            ['A' => '仮設工事', 'B' => '設置', 'E' => '2026/07/10', 'H' => '2026/07/01', 'K' => '1'],
        ]);

        $result = AndpadScheduleSheet::read($path);

        $this->assertSame([], $result['rows']);
        $this->assertCount(1, $result['rowErrors']);
        $this->assertStringContainsString('前です', $result['rowErrors'][0]);
    }

    /** 期間の食い違いは**警告**（取り込む）。エラー（落とす）ではない */
    public function test_a_mismatched_duration_warns_but_still_imports(): void
    {
        $path = $this->makeListXlsx([
            ['A' => '仮設工事', 'B' => '設置', 'E' => '2026/07/01', 'H' => '2026/07/03', 'K' => '9'],
        ]);

        $result = AndpadScheduleSheet::read($path);

        $this->assertCount(1, $result['rows'], '警告であって取りこぼしではない');
        $this->assertSame([], $result['rowErrors']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('9 日', $result['warnings'][0]);
        $this->assertStringContainsString('3 日', $result['warnings'][0]);
    }

    /** 工程名だけ空の行は黙って捨てず、行エラーで報告する */
    public function test_a_row_with_a_date_but_no_name_is_reported(): void
    {
        $path = $this->makeListXlsx([
            ['A' => '仮設工事', 'B' => '', 'E' => '2026/07/01', 'H' => '2026/07/01', 'K' => '1'],
        ]);

        $result = AndpadScheduleSheet::read($path);

        $this->assertSame([], $result['rows']);
        $this->assertCount(1, $result['rowErrors']);
        $this->assertStringContainsString('工程名がありません', $result['rowErrors'][0]);
    }

    /** ⚠ 見出しが無いファイルは「使えない書き出し」。黙って 0 件で成功にしない */
    public function test_a_file_without_the_expected_headers_is_not_the_list_format(): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', '工程表');
        $sheet->setCellValue('A3', '2026/07/01');
        $path = $this->save($book);

        $result = AndpadScheduleSheet::read($path);

        $this->assertSame(AndpadScheduleSheet::FORMAT_OTHER, $result['format']);
        $this->assertSame([], $result['rows']);
    }

    // ============================================================
    // ヘルパ
    // ============================================================

    /** @param array<int, array<string, string>> $dataRows */
    private function makeListXlsx(array $dataRows): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();

        $sheet->setCellValue('D1', '2026/07/01〜2026/12/31');
        $sheet->setCellValue('B2', 'テスト現場');
        $sheet->setCellValue('F2', 'テスト住所');

        foreach ([
            'A' => '大工程名', 'B' => '工程名', 'C' => '担当会社', 'D' => '担当者',
            'E' => '施工開始日', 'H' => '施工完了日', 'K' => '期間', 'L' => '状態',
        ] as $col => $label) {
            $sheet->setCellValue($col . '3', $label);
        }

        $r = 4;
        foreach ($dataRows as $row) {
            foreach ($row as $col => $value) {
                // ⚠ ANDPAD と同じく**文字列**で入れる（日付をシリアル値にしない）
                $sheet->setCellValueExplicit($col . $r, (string) $value, DataType::TYPE_STRING);
            }
            $r++;
        }

        return $this->save($book);
    }

    private function save(Spreadsheet $book): string
    {
        $path = tempnam(sys_get_temp_dir(), 'andpad') . '.xlsx';
        (new XlsxWriter($book))->save($path);
        $this->temporary[] = $path;

        return $path;
    }
}
