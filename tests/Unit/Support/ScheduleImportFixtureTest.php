<?php

namespace Tests\Unit\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

/**
 * 取込の固定資産が「正常化されていない」ことを守る（プラン Task 1）。
 *
 * ⚠ **このテストは取込の振る舞いではなく、固定資産そのものを測る。**
 *   誰かが固定資産を Excel で開いて上書き保存したり、`zipfile` で作り直したりすると、
 *   zip の壊れ方（＝取込方式を PhpSpreadsheet にした理由）が消える。
 *   そうなると「本番でだけ読めない」を原理的に防げなくなるので、ここで落とす。
 *
 * ⚠ **SheetJS で読めないことはここでは測れない**（node が要る）。
 *   再加工したときは `tests/fixtures/schedule-import/README.md` の手順で手で測ること。
 */
class ScheduleImportFixtureTest extends TestCase
{
    private const LIST_FORMAT = __DIR__ . '/../../fixtures/schedule-import/list-format.xlsx';

    /** 中央ディレクトリを読んで [名前, 中央csize, 中央usize, ローカルcsize, ローカルusize] を返す */
    private function zipEntries(string $path): array
    {
        $d = file_get_contents($path);
        $eocd = strrpos($d, "PK\x05\x06");
        $this->assertNotFalse($eocd, 'EOCD が見つからない');

        $count = unpack('v', substr($d, $eocd + 10, 2))[1];
        $off = unpack('V', substr($d, $eocd + 16, 4))[1];

        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $f = unpack('Vsig/vmade/vneed/vflags/vmethod/vmt/vmd/Vcrc/Vcsize/Vusize'
                . '/vnlen/velen/vclen/vdisk/viattr/Veattr/Vlho', substr($d, $off, 46));
            $name = substr($d, $off + 46, $f['nlen']);

            $lho = $f['lho'];
            $l = unpack('Vsig/vneed/vflags/vmethod/vmt/vmd/Vcrc/Vcsize/Vusize/vnlen/velen',
                substr($d, $lho, 30));

            $entries[] = [$name, $f['csize'], $f['usize'], $l['csize'], $l['usize']];
            $off += 46 + $f['nlen'] + $f['elen'] + $f['clen'];
        }

        return $entries;
    }

    public function test_the_fixture_exists(): void
    {
        $this->assertFileExists(
            self::LIST_FORMAT,
            '一覧形式の固定資産が無い。tests/fixtures/schedule-import/README.md の手順で作り直すこと'
        );
    }

    /**
     * ⚠ 拡張子なしの media が SheetJS を落とす原因そのもの（README 1.）。
     *   これが消えていたら固定資産は正常化されている。
     */
    public function test_it_keeps_the_extension_less_media_entries(): void
    {
        $names = array_column($this->zipEntries(self::LIST_FORMAT), 0);

        $media = array_values(array_filter(
            $names,
            fn ($n) => str_starts_with($n, 'xl/media/') && ! str_contains(basename($n), '.')
        ));

        $this->assertCount(2, $media, '拡張子なしの media が 2 件で無くなっている: ' . implode(', ', $names));
        foreach ($media as $m) {
            $this->assertStringEndsWith('_brand_output_filename', $m);
        }
    }

    /**
     * ⚠ ローカルヘッダ・中央ディレクトリの両方が 0xFFFFFFFF（README 2.）。
     *   Python の zipfile や普通の zip コマンドで作り直すとここが実サイズになる。
     */
    public function test_it_keeps_the_zip64_sized_headers(): void
    {
        $entries = $this->zipEntries(self::LIST_FORMAT);

        // 走査が空振りして緑になる事故を防ぐ
        $this->assertCount(23, $entries, 'エントリ数が 23 で無い');

        foreach ($entries as [$name, $cCsize, $cUsize, $lCsize, $lUsize]) {
            $this->assertSame(0xFFFFFFFF, $cCsize, "中央 csize が 0xFFFFFFFF でない: $name");
            $this->assertSame(0xFFFFFFFF, $cUsize, "中央 usize が 0xFFFFFFFF でない: $name");
            $this->assertSame(0xFFFFFFFF, $lCsize, "ローカル csize が 0xFFFFFFFF でない: $name");
            $this->assertSame(0xFFFFFFFF, $lUsize, "ローカル usize が 0xFFFFFFFF でない: $name");
        }
    }

    /**
     * ⚠ ローカルヘッダは ZIP64 なのに EOCD は通常形式、という混成が原本の姿。
     *   ZIP64 EOCD を書く実装で作り直すとここが変わる。
     */
    public function test_it_keeps_the_plain_end_of_central_directory(): void
    {
        $d = file_get_contents(self::LIST_FORMAT);

        $this->assertFalse(strpos($d, "PK\x06\x06"), 'ZIP64 EOCD レコードが増えている');
        $this->assertFalse(strpos($d, "PK\x06\x07"), 'ZIP64 EOCD locator が増えている');
    }

    /**
     * 壊れているが **PhpSpreadsheet では読める** こと。
     *
     * ⚠ 構造テストだけだと「壊れているが読めもしない」ゴミを固定してしまう。
     *   読めることと壊れていることを対で押さえる。
     */
    public function test_phpspreadsheet_can_read_both_sheets(): void
    {
        $book = IOFactory::createReader('Xlsx')->load(self::LIST_FORMAT);

        $this->assertSame(['Sheet1', 'Sheet2'], array_map(
            fn ($s) => $s->getTitle(),
            $book->getAllSheets()
        ), '2 シートで無い（Sheet2 は続きなので、落とすと 5 件消える）');

        $steps = 0;
        foreach ($book->getAllSheets() as $sheet) {
            foreach ($sheet->getRowIterator(4) as $row) {
                $r = $row->getRowIndex();
                $name  = trim((string) $sheet->getCell("B$r")->getValue());
                $start = trim((string) $sheet->getCell("E$r")->getValue());
                if ($name !== '' && $start !== '') {
                    $steps++;
                }
            }
        }

        $this->assertSame(65, $steps, '工程が 65 件で無い');
    }
}
