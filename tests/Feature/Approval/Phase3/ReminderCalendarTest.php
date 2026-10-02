<?php

namespace Tests\Feature\Approval\Phase3;

use App\Models\ApprovalHoliday;
use App\Models\ApprovalReminderRun;
use App\Support\Approval\ReminderCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 催促を送る日の判定（段階3 設計書 §5.10・§6 の「催促」・D4・D17・D19）。
 */
class ReminderCalendarTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 内閣府「国民の祝日について」の syukujitsu.csv（2026-10-02 に取得）の 2026〜2027 年。
     * 「休日」は振替休日と国民の休日（祝日に挟まれた日）。
     */
    private const CABINET_OFFICE = [
        '2026-01-01', '2026-01-12', '2026-02-11', '2026-02-23', '2026-03-20', '2026-04-29', '2026-05-03', '2026-05-04',
        '2026-05-05', '2026-05-06', '2026-07-20', '2026-08-11', '2026-09-21', '2026-09-22', '2026-09-23', '2026-10-12',
        '2026-11-03', '2026-11-23',
        '2027-01-01', '2027-01-11', '2027-02-11', '2027-02-23', '2027-03-21', '2027-03-22', '2027-04-29', '2027-05-03',
        '2027-05-04', '2027-05-05', '2027-07-19', '2027-08-11', '2027-09-20', '2027-09-23', '2027-10-11', '2027-11-03',
        '2027-11-23',
    ];

    private function day(string $date): Carbon
    {
        return Carbon::parse($date);
    }

    private function holiday(string $from, string $to, bool $yearly, string $description): void
    {
        ApprovalHoliday::create(['start_date' => $from, 'end_date' => $to, 'repeats_yearly' => $yearly, 'description' => $description]);
    }

    /** 2026〜2027 年の毎日を、内閣府の祝日の一覧と土日で突き合わせる（部品の祝日の中身を確かめる。D4） */
    public function test_every_day_of_2026_and_2027_matches_the_cabinet_office_list_and_weekends(): void
    {
        $calendar  = new ReminderCalendar();
        $holidays  = array_flip(self::CABINET_OFFICE);
        $day       = Carbon::parse('2026-01-01');
        $sendDays  = 0;
        $problems  = [];

        while ($day->format('Y') !== '2028') {
            $date     = $day->format('Y-m-d');
            $reason   = $calendar->reasonNotToSend($day);
            $expected = match (true) {
                $day->isSaturday()            => '土曜日',
                $day->isSunday()              => '日曜日',
                isset($holidays[$date])       => '祝日',
                default                       => null,
            };

            if ($expected === null ? $reason !== null : ! str_starts_with((string) $reason, $expected)) {
                $problems[] = "{$date}: {$reason}（期待: " . ($expected ?? '送る日') . '）';
            }
            $sendDays += $reason === null ? 1 : 0;
            $day->addDay();
        }

        $this->assertSame([], $problems);
        // 空振りで緑にならないように（2 年の平日 522 日から、平日に当たる祝日 33 日〈2026 年 17・2027 年 16〉を引く）
        $this->assertSame(489, $sendDays);
    }

    public function test_the_reasons_name_the_weekday_or_the_holiday(): void
    {
        $calendar = new ReminderCalendar();

        $this->assertSame('土曜日', $calendar->reasonNotToSend($this->day('2026-10-03')));
        $this->assertSame('日曜日', $calendar->reasonNotToSend($this->day('2026-10-04')));
        $this->assertNull($calendar->reasonNotToSend($this->day('2026-10-05')));
        $this->assertSame('祝日（スポーツの日）', $calendar->reasonNotToSend($this->day('2026-10-12')));
        $this->assertSame('祝日（振替休日 (憲法記念日)）', $calendar->reasonNotToSend($this->day('2026-05-06')));
        $this->assertSame('祝日（国民の休日）', $calendar->reasonNotToSend($this->day('2026-09-22')));
    }

    /** ⑫ で登録した送らない日（年をまたぐ毎年の期間・その年だけの期間） */
    public function test_the_registered_days_off_are_not_send_days(): void
    {
        $this->holiday('2026-12-29', '2027-01-03', true, '年末年始');
        $this->holiday('2026-08-13', '2026-08-14', false, '夏季休暇');
        $calendar = new ReminderCalendar();

        $this->assertSame('送らない日（年末年始）', $calendar->reasonNotToSend($this->day('2026-12-29')));
        $this->assertSame('送らない日（年末年始）', $calendar->reasonNotToSend($this->day('2031-12-30')));
        $this->assertNull($calendar->reasonNotToSend($this->day('2026-12-28')));
        $this->assertNull($calendar->reasonNotToSend($this->day('2027-01-04')));
        $this->assertSame('送らない日（夏季休暇）', $calendar->reasonNotToSend($this->day('2026-08-13')));
        $this->assertNull($calendar->reasonNotToSend($this->day('2027-08-13')));
        // 祝日と重なれば祝日を先に言う
        $this->assertSame('祝日（元日）', $calendar->reasonNotToSend($this->day('2027-01-01')));
    }

    public function test_the_next_send_day_after_skips_weekends_holidays_and_days_off(): void
    {
        $this->holiday('2026-12-29', '2027-01-03', true, '年末年始');
        $calendar = new ReminderCalendar();

        // 金曜の次は、月曜のスポーツの日を飛ばして火曜
        $this->assertSame('2026-10-13', $calendar->nextSendDayAfter($this->day('2026-10-09'))->format('Y-m-d'));
        // 年末の月曜の次は、年末年始と土日を飛ばして 1/4
        $this->assertSame('2027-01-04', $calendar->nextSendDayAfter($this->day('2026-12-28'))->format('Y-m-d'));
        // 送る日の次の日が送る日なら、その日（その日自身は数えない）
        $this->assertSame('2026-10-06', $calendar->nextSendDayAfter($this->day('2026-10-05'))->format('Y-m-d'));
    }

    public function test_there_is_no_next_send_day_when_a_year_is_blocked(): void
    {
        $this->holiday('2026-10-01', '2028-12-31', false, '長い休み');

        $this->assertNull((new ReminderCalendar())->nextSendDayAfter($this->day('2026-10-02')));
    }

    /** ⑫ の「次に催促を送る日」: 今日の 9:05 より前で、今日の分をまだ送っていなければ今日（D17） */
    public function test_the_next_send_day_is_today_only_before_five_past_nine_and_before_sending(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:59:59', 'Asia/Tokyo')->utc());
        $this->assertSame('2026-10-05', (new ReminderCalendar())->nextSendDay()->format('Y-m-d'));

        // 日本時間の朝（UTC ではまだ前の日の夜）でも、日本の今日で決める
        $this->travelTo(CarbonImmutable::parse('2026-10-04 23:30:00', 'UTC'));
        $this->assertSame('2026-10-05', (new ReminderCalendar())->nextSendDay()->format('Y-m-d'));

        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:05:00', 'Asia/Tokyo')->utc());
        $this->assertSame('2026-10-06', (new ReminderCalendar())->nextSendDay()->format('Y-m-d'));

        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:01:00', 'Asia/Tokyo')->utc());
        ApprovalReminderRun::create(['sent_on' => '2026-10-05', 'recipient_count' => 1, 'item_count' => 1]);
        $this->assertSame('2026-10-06', (new ReminderCalendar())->nextSendDay()->format('Y-m-d'));

        // 日本時間の朝（UTC ではまだ前の日）に今日の行がもうあれば、明日（日本の今日の行で見る）
        $this->travelTo(CarbonImmutable::parse('2026-10-06 08:30:00', 'Asia/Tokyo')->utc());
        ApprovalReminderRun::create(['sent_on' => '2026-10-06', 'recipient_count' => 1, 'item_count' => 1]);
        $this->assertSame('2026-10-07', (new ReminderCalendar())->nextSendDay()->format('Y-m-d'));

        // 土曜の朝は、次の月曜
        $this->travelTo(CarbonImmutable::parse('2026-10-03 07:00:00', 'Asia/Tokyo')->utc());
        $this->assertSame('2026-10-05', (new ReminderCalendar())->nextSendDay()->format('Y-m-d'));
    }

    public function test_the_label_has_the_japanese_weekday(): void
    {
        $this->assertSame('10/1（木）', ReminderCalendar::label($this->day('2026-10-01')));
        $this->assertSame('1/4（月）', ReminderCalendar::label($this->day('2027-01-04')));

        ApprovalReminderRun::create(['sent_on' => '2026-10-05', 'recipient_count' => 1, 'item_count' => 1]);
        $this->assertSame('10/5（月）', ReminderCalendar::label(ApprovalReminderRun::latestRun()->sent_on));
    }
}
