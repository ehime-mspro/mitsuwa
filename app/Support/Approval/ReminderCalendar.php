<?php

namespace App\Support\Approval;

use App\Models\ApprovalHoliday;
use App\Models\ApprovalReminderRun;
use App\Support\JapanTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Yasumi\Holiday;
use Yasumi\Yasumi;

/**
 * 催促を送る日の判定（段階3 設計書 §5.10・D4・D17・D19）。送らない日 ＝ 土曜・日曜 ／ 日本の祝日（部品 Yasumi。
 * 振替休日・国民の休日を含む）／ 催促の設定（⑫）で登録した送らない日。
 *
 * ⚠ 日付は日本の暦の日付で渡す（JapanTime::today() と同じ「UTC の 0:00」の形）。Yasumi の isHoliday() は渡した日時の
 *   その時刻帯での年月日で比べ、年をまたいだ日は false を返す（2026-10-02 に実測）ので、年ごとに祝日の表を作り、
 *   'Y-m-d' の文字列で引く。
 * ⚠ 祝日の中身は部品が計算する（Yasumi 2.12.0 の 2025〜2027 年は、内閣府の「国民の祝日」の一覧と 1 日も違わないことを
 *   2026-10-02 に確かめた）。法律で祝日が変わったら部品を新しくする（D4）。年末年始・会社の休業日は⑫で登録する。
 * ⚠ 1 つのインスタンスの中では、登録した送らない日は 1 回だけ読み、祝日の表は年ごとに 1 回だけ作る（次に送る日を探すときに
 *   日ごとに読まない）。登録を変えたあとに同じインスタンスで判定し直さない。
 */
final class ReminderCalendar
{
    /** 定期実行が催促を送る時刻の終わり（9:00〜9:04 の起動。D17）。これより後は、その日の分はもう送られない */
    public const SEND_UNTIL = '09:05';

    /** 次に送る日を探す範囲（日数）。これより先に見つからなければ「ない」 */
    private const SEARCH_DAYS = 366;

    private const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    /** @var Collection<int, ApprovalHoliday>|null */
    private ?Collection $registered = null;

    /** @var array<int, array<string, string>> 年 => ['Y-m-d' => 祝日の名前] */
    private array $nationalHolidays = [];

    /** 送らないわけ（送る日なら null）。例: 「土曜日」「祝日（敬老の日）」「送らない日（年末年始）」 */
    public function reasonNotToSend(CarbonInterface $day): ?string
    {
        if ($day->isSaturday()) {
            return '土曜日';
        }

        if ($day->isSunday()) {
            return '日曜日';
        }

        $name = $this->nationalHolidaysOf((int) $day->format('Y'))[$day->format('Y-m-d')] ?? null;
        if ($name !== null) {
            return "祝日（{$name}）";
        }

        $this->registered ??= ApprovalHoliday::ordered()->get();
        $holiday = $this->registered->first(fn (ApprovalHoliday $holiday) => $holiday->covers($day));

        return $holiday === null ? null : "送らない日（{$holiday->description}）";
    }

    public function isSendDay(CarbonInterface $day): bool
    {
        return $this->reasonNotToSend($day) === null;
    }

    /** その日より後（その日は含めない）で最初に送る日。366 日先まで無ければ null */
    public function nextSendDayAfter(CarbonInterface $day): ?Carbon
    {
        $candidate = Carbon::parse($day->format('Y-m-d'));

        for ($i = 0; $i < self::SEARCH_DAYS; $i++) {
            $candidate->addDay();

            if ($this->isSendDay($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * ⑫ の「次に催促を送る日」。今日が送る日で、今日の分をまだ送っておらず、日本時間の 9:05 より前なら今日。
     * そうでなければ明日から数えて最初の送る日（9:00〜9:04 に動かなかった日は送らない。D17）。
     *
     * ⚠ 使い始める前かどうかは見ない（使い始める前は送らないことは、画面が添える）
     */
    public function nextSendDay(): ?Carbon
    {
        $today = JapanTime::today();
        $until = CarbonImmutable::createFromFormat('!Y-m-d H:i', $today->format('Y-m-d') . ' ' . self::SEND_UNTIL, JapanTime::ZONE);

        if (now()->lt($until) && $this->isSendDay($today) && ! ApprovalReminderRun::whereDate('sent_on', $today->format('Y-m-d'))->exists()) {
            return $today;
        }

        return $this->nextSendDayAfter($today);
    }

    /** 「10/1（木）」の形（日本の暦の日付・date キャストの属性を渡す。Bug #61 の決まりで JapanTime::format を通す） */
    public static function label(CarbonInterface $day): string
    {
        return JapanTime::format($day, 'n/j') . '（' . self::WEEKDAYS[(int) JapanTime::format($day, 'w')] . '）';
    }

    /** @return array<string, string> その年の日本の祝日（'Y-m-d' => 名前） */
    private function nationalHolidaysOf(int $year): array
    {
        if (! isset($this->nationalHolidays[$year])) {
            $this->nationalHolidays[$year] = [];

            foreach (Yasumi::create('Japan', $year, 'ja_JP') as $holiday) {
                /** @var Holiday $holiday */
                $this->nationalHolidays[$year][$holiday->format('Y-m-d')] ??= $holiday->getName();
            }
        }

        return $this->nationalHolidays[$year];
    }
}
