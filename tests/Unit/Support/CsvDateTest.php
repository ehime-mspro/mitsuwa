<?php

namespace Tests\Unit\Support;

use App\Support\CsvDate;
use PHPUnit\Framework\TestCase;

/**
 * CSV 取込の日付正規化。
 *
 * ⚠ Task 6 で `checkdate()` へ直したので、期待値は**正しい振る舞い**を表す。
 *   誤実装（`strtotime()`）に戻したときに落ちる値を明示的に置いてあるので、
 *   下記の値を消さないこと。
 */
class CsvDateTest extends TestCase
{
    private string $originalTimezone;

    /**
     * ⚠ **タイムゾーンをこのテスト自身で固定する。ラチェットの一部なので外さないこと。**
     *
     * このクラスは `PHPUnit\Framework\TestCase` を継承していて **Laravel を起動しない**ので、
     * `config/app.php` の `'timezone' => 'UTC'` は効かない。実際に効くのは php.ini の
     * `date.timezone` で、`phpunit.xml` も指定していない ＝ **走らせるマシン任せ**になる。
     * 固定しないと `test_it_accepts_the_unix_epoch` の検出力が環境依存になる（同メソッドの注記）。
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        // 同一プロセスで走る後続テストへ UTC を漏らさない
        date_default_timezone_set($this->originalTimezone);

        parent::tearDown();
    }

    public function test_it_pads_and_accepts_slashes(): void
    {
        $this->assertSame('2026-04-01', CsvDate::normalize('2026-04-01'));
        $this->assertSame('2026-04-01', CsvDate::normalize('2026/04/01'));
        $this->assertSame('2026-02-03', CsvDate::normalize('2026-2-3'));
    }

    public function test_it_rejects_garbage(): void
    {
        $this->assertNull(CsvDate::normalize(''));
        $this->assertNull(CsvDate::normalize('2026-13-01'));
        $this->assertNull(CsvDate::normalize('令和8年4月1日'));
    }

    /**
     * 存在しない日付を弾くこと。
     *
     * ⚠ **この 4 つの値を消さないこと。** `strtotime()` は存在しない日付を
     *   繰り上げて成功を返すので（`2026-02-30` → 3/2 として解釈）、
     *   誤実装に戻したときに落ちるのはこれらの値だけ。
     *
     * ⚠ 本番 MySQL は strict mode なので `'2026-02-30'` の INSERT は
     *   `Incorrect date value` で例外になり `rollBack()` する。つまり
     *   **打鍵ミス 1 行で数百行の取込が丸ごと失敗**していた。
     */
    public function test_it_rejects_dates_that_do_not_exist(): void
    {
        $this->assertNull(CsvDate::normalize('2026-02-30'));
        $this->assertNull(CsvDate::normalize('2026-04-31'));
        $this->assertNull(CsvDate::normalize('2026-02-29')); // 2026 は閏年でない
        $this->assertNull(CsvDate::normalize('0000-01-01'));
    }

    /**
     * 1970-01-01 を受け付けること。
     *
     * ⚠ **この値を消さないこと。** `&& strtotime($value)` で真偽判定していた旧実装は、
     *   `strtotime('1970-01-01')` が **0**（falsy）を返すせいで、この 1 日だけを理由なく
     *   拒否していた。誤実装に戻したときに落ちるのはこの値だけ。
     *
     * ⚠ **0 が返るのは UTC のときだけ ＝ 検出力はタイムゾーン依存。** 実測:
     *   UTC `0`（falsy）/ Asia/Tokyo `-32400` / America/New_York `18000` /
     *   Europe/London `-3600`（UTC 以外はいずれも **truthy**）。
     *   つまり日本のマシンで素直に走らせると、`strtotime()` へ戻す変異が**このテストを
     *   緑のまま素通りする**（実測: `php -d date.timezone=Asia/Tokyo` では本メソッドだけ通り、
     *   ラチェットが無音で死んだ）。**`config/app.php` の timezone は無関係** —
     *   Laravel を起動しないので効かない。だから `setUp()` で UTC を固定している。
     */
    public function test_it_accepts_the_unix_epoch(): void
    {
        $this->assertSame('1970-01-01', CsvDate::normalize('1970-01-01'));
    }
}
