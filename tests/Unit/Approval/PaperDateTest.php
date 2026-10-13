<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\PaperDate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 紙の台帳の日付の読み方（要件 11.1・段階5 設計書 §5.9）。年は月で決め、先頭の数は年度の和暦の年かその日の和暦の年と合うこと。
 * 1〜4 月を「9.01.15」（暦の年）と書く台帳も「8.01.15」（年度の年）と書く台帳も読める（08 年度の台帳にはまだ 1〜4 月が無く、どちらか分からない）。
 */
class PaperDateTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: int, 2: int, 3: string}> 台帳の日付・年度・期の始まりの月・読んだ日付 */
    public static function readable(): array
    {
        return [
            '5 月（期の始まり）'                 => ['8.05.07', 2026, 5, '2026-05-07'],
            '12 月'                             => ['8.12.28', 2026, 5, '2026-12-28'],
            '1 月を暦の年で'                     => ['9.01.15', 2026, 5, '2027-01-15'],
            '1 月を年度の年で'                   => ['8.01.15', 2026, 5, '2027-01-15'],
            '4 月 30 日（期の終わり）'           => ['9.04.30', 2026, 5, '2027-04-30'],
            '0 を付けない・全角・区切りのゆれ'    => ['８．５．７', 2026, 5, '2026-05-07'],
            'スラッシュ'                         => ['8/5/7', 2026, 5, '2026-05-07'],
            '前後の空白'                         => [' 8.05.07　', 2026, 5, '2026-05-07'],
            '平成の年度'                         => ['30.05.10', 2018, 5, '2018-05-10'],
            '平成の年度の 4 月を暦の年で'         => ['31.04.30', 2018, 5, '2019-04-30'],
            '平成の年度の 4 月を年度の年で'       => ['30.04.30', 2018, 5, '2019-04-30'],
            '令和元年度'                         => ['1.05.01', 2019, 5, '2019-05-01'],
            '令和元年度の 1 月を暦の年で'         => ['2.01.10', 2019, 5, '2020-01-10'],
            '6 月始まりの会社の平成の年度の 5 月（暦は令和）' => ['1.05.10', 2018, 6, '2019-05-10'],
            '6 月始まりの会社の平成の年度の 5 月（年度の年）' => ['30.05.10', 2018, 6, '2019-05-10'],
            'うるう日'                           => ['10.02.29', 2027, 5, '2028-02-29'],
            'Excel の日付の数'                   => [(float) Date::formattedPHPToExcel(2026, 6, 1), 2026, 5, '2026-06-01'],
        ];
    }

    #[DataProvider('readable')]
    public function test_a_date_is_read(mixed $raw, int $fiscalYear, int $startMonth, string $expected): void
    {
        $read = PaperDate::read($raw, $fiscalYear, $startMonth);

        $this->assertNull($read['error']);
        $this->assertSame($expected, $read['date']->format('Y-m-d'));
        $this->assertSame('Asia/Tokyo', $read['date']->getTimezone()->getName(), '日本の暦の日付');
        $this->assertSame('00:00:00', $read['date']->format('H:i:s'));
    }

    /** @return array<string, array{0: mixed, 1: int, 2: string}> 台帳の日付・年度・理由 */
    public static function unreadable(): array
    {
        return [
            '前の年度の書き残し'       => ['7.04.30', 2026, '日付「7.04.30」の年（7）が R8 年度と合いません'],
            '次の年度の日付'           => ['10.05.01', 2026, '日付「10.05.01」の年（10）が R8 年度と合いません'],
            'ない日'                   => ['8.02.30', 2026, '日付「8.02.30」が読めません（ない日付です）'],
            'ない月'                   => ['8.13.01', 2026, '日付「8.13.01」が読めません（ない日付です）'],
            '0 月'                     => ['8.00.10', 2026, '日付「8.00.10」が読めません（ない日付です）'],
            '形が違う'                 => ['8.0513', 2026, '日付「8.0513」が読めません（8.05.13 の形で書きます）'],
            '元号の文字つき'           => ['R8.05.13', 2026, '日付「R8.05.13」が読めません（8.05.13 の形で書きます）'],
            'うるう年でない 2/29'      => ['9.02.29', 2026, '日付「9.02.29」が読めません（ない日付です）'],
            '期間の外の Excel の日付'   => [(float) Date::formattedPHPToExcel(2026, 4, 30), 2026, '日付（2026/04/30）が R8 年度の期間の外です'],
            '日付でない数'             => [-5, 2026, '日付（-5）が読めません'],
        ];
    }

    #[DataProvider('unreadable')]
    public function test_a_wrong_date_is_not_read(mixed $raw, int $fiscalYear, string $error): void
    {
        $this->assertSame(['date' => null, 'error' => $error], PaperDate::read($raw, $fiscalYear, 5));
    }

    /** 結び付けの鍵は、0 の付け方・全角・区切りの違いを無視する（金額の控えと受付簿で書き方がゆれても結び付く） */
    public function test_the_key_ignores_how_the_date_is_written(): void
    {
        $this->assertSame('era:8.5.7', PaperDate::key('8.05.07'));
        $this->assertSame('era:8.5.7', PaperDate::key('８．５．７'));
        $this->assertSame('era:8.5.7', PaperDate::key('8/5/07'));
        $this->assertSame('day:2026-06-01', PaperDate::key((float) Date::formattedPHPToExcel(2026, 6, 1)));
        $this->assertNull(PaperDate::key('日　　付'));
        $this->assertNull(PaperDate::key(null));
        $this->assertNull(PaperDate::key(0));
    }
}
