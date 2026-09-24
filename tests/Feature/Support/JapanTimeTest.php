<?php

namespace Tests\Feature\Support;

use App\Models\Repair;
use App\Models\User;
use App\Support\JapanTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 日本時間の部品（docs/RULES.md Bug #61）。
 *
 * ⚠ Feature の TestCase で書く（Laravel を起動する）。素の PHPUnit の TestCase だと既定の時刻帯が
 *   config/app.php でなく php.ini に左右され、走らせるマシンで結果が変わる（Bug #54 ①）。
 * ⚠ 時刻は日本時間の 0:00〜8:59（UTC ではまだ前日）に固定する。昼に固定すると UTC と日本の日付が
 *   同じになり、変換を外しても緑のまま通る。
 */
class JapanTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_app_timezone_is_still_utc(): void
    {
        // この部品は「アプリは UTC」を前提にしている（段階0 の決定。tests/Feature/Ops/ScheduleTest.php と対）
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function test_format_converts_a_stored_utc_time_to_japan_time(): void
    {
        $stored = Carbon::parse('2026-09-18 17:12:00', 'UTC'); // 日本時間 9/19 2:12

        $this->assertSame('2026/09/19 02:12', JapanTime::format($stored));
        $this->assertSame('09/19 02:12', JapanTime::format($stored, 'm/d H:i'));
        $this->assertSame('2026/09/19', JapanTime::format($stored, 'Y/m/d'));
    }

    public function test_format_shifts_the_zone_rather_than_adding_nine_hours(): void
    {
        // すでに日本時間で来た値は動かさない（+9 時間する実装だと 11:12 になる）
        $this->assertSame('2026/09/19 02:12', JapanTime::format(Carbon::parse('2026-09-19 02:12:00', 'Asia/Tokyo')));

        // 出力の時刻帯そのものを見る（+9 時間する実装だと +00:00 が出る）
        $this->assertSame('2026/09/19 02:12 +09:00', JapanTime::format(Carbon::parse('2026-09-18 17:12:00', 'UTC'), 'Y/m/d H:i P'));
    }

    public function test_format_accepts_immutable_values_and_leaves_the_argument_alone(): void
    {
        $stored = Carbon::parse('2026-04-30 15:30:00', 'UTC');

        $this->assertSame('2026/05/01 00:30', JapanTime::format($stored->toImmutable()));
        $this->assertSame('2026/05/01 00:30', JapanTime::format($stored));
        $this->assertSame('2026-04-30 15:30:00', $stored->toDateTimeString(), '引数の時刻が書き換わっている');
        $this->assertSame('UTC', $stored->timezoneName, '引数の時刻帯が書き換わっている');
    }

    public function test_format_accepts_plain_php_date_objects(): void
    {
        // 宣言は ?DateTimeInterface。?Carbon に狭める変更をここで止める
        $mutable = new \DateTime('2026-04-30 15:30:00', new \DateTimeZone('UTC'));
        $immutable = new \DateTimeImmutable('2026-04-30 15:30:00', new \DateTimeZone('UTC'));

        $this->assertSame('2026/05/01 00:30', JapanTime::format($mutable));
        $this->assertSame('2026/05/01 00:30', JapanTime::format($immutable));

        // 可変の DateTime を渡しても呼び出し側の値は動かない
        $this->assertSame('2026-04-30 15:30:00', $mutable->format('Y-m-d H:i:s'), '引数の時刻が書き換わっている');
        $this->assertSame('UTC', $mutable->getTimezone()->getName(), '引数の時刻帯が書き換わっている');
    }

    public function test_format_returns_null_for_null(): void
    {
        $this->assertNull(JapanTime::format(null));
    }

    public function test_a_stored_timestamp_comes_back_in_utc(): void
    {
        // この改修全体の前提。手で組み立てた値ではなく、実際に保存して読み直して確かめる（Bug #47 / #54 ②）
        Carbon::setTestNow(Carbon::parse('2026-09-18 17:12:00', 'UTC')); // 日本時間 9/19 2:12

        $stored = User::factory()->create()->fresh()->created_at;

        $this->assertSame('UTC', $stored->timezoneName, '保存された日時が UTC で返っていない');
        $this->assertSame('2026/09/19 02:12', JapanTime::format($stored));
    }

    public function test_today_is_the_japanese_date_before_nine_in_the_morning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 15:30:00', 'UTC')); // 日本時間 5/1 0:30

        $today = JapanTime::today();

        $this->assertSame('2026-05-01', $today->toDateString());
        $this->assertSame(2026, $today->year);
        $this->assertSame(5, $today->month);
    }

    public function test_today_turns_over_exactly_at_japan_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 14:59:59', 'UTC')); // 日本時間 4/30 23:59:59
        $this->assertSame('2026-04-30', JapanTime::today()->toDateString());

        Carbon::setTestNow(Carbon::parse('2026-04-30 15:00:00', 'UTC')); // 日本時間 5/1 0:00
        $this->assertSame('2026-05-01', JapanTime::today()->toDateString());
    }

    public function test_today_is_midnight_in_the_app_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 15:30:00', 'UTC'));

        $today = JapanTime::today();

        // ⚠ 日本時間の 0:00（UTC の 4/30 15:00）で返すと、date キャストの属性や Carbon::create() と 9 時間ずれる
        $this->assertSame('2026-05-01 00:00:00', $today->toDateTimeString());
        $this->assertSame('UTC', $today->timezoneName);
        $this->assertTrue($today->equalTo(Carbon::create(2026, 5, 1)));
    }

    public function test_today_equals_a_date_cast_attribute_of_the_same_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 15:30:00', 'UTC'));

        $repair = (new Repair())->forceFill(['started_at' => '2026-05-01']); // date キャスト

        $this->assertTrue(JapanTime::today()->equalTo($repair->started_at));
        $this->assertFalse(JapanTime::today()->greaterThan($repair->started_at));
    }

    public function test_today_returns_a_new_mutable_instance_each_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 15:30:00', 'UTC'));

        $first = JapanTime::today();
        $first->addDay();

        $this->assertInstanceOf(Carbon::class, $first);
        $this->assertSame('2026-05-01', JapanTime::today()->toDateString(), '前の呼び出しの変更が次に漏れている');
    }

    public function test_the_zone_matches_the_schedule_timezone(): void
    {
        // config/app.php の schedule_timezone（定期実行の時刻）と「日本」の定義が割れていないこと
        $this->assertSame(JapanTime::ZONE, config('app.schedule_timezone'));
    }
}
