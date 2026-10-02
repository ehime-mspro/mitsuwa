<?php

namespace App\Models;

use App\Support\JapanTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 催促を送らない日（⑫・要件 8.3。段階3 設計書 §5.9・§5.10）。土日と日本の祝日は ReminderCalendar が自動で見るので、
 * ここには会社の休み（年末年始・夏季休暇など）を登録する。
 *
 * ⚠ 毎年繰り返すものは**月と日だけ**で比べる（D19）。開始の月日が終了の月日より後なら年をまたぐとみなす（12/29〜1/3）。
 * ⚠ 毎年繰り返すものは 1 年より短い（終了日は開始日の 1 年後より前。HolidayController が断る）。1 年まるごとの期間を
 *   月と日で比べると、始まりと終わりの月日が隣り合ったり重なったりして、どの日が入るかが分かりにくくなるため。
 */
class ApprovalHoliday extends Model
{
    protected $fillable = ['start_date', 'end_date', 'repeats_yearly', 'description'];

    protected function casts(): array
    {
        return [
            'start_date'     => 'date',
            'end_date'       => 'date',
            'repeats_yearly' => 'boolean',
        ];
    }

    /** 一覧の並び（開始日の順） */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('start_date')->orderBy('id');
    }

    /** 画面のフォームと設定の変更の記録に使う形（日付は 'Y-m-d'。画面から来た値とそのまま比べられる） */
    public function formValues(): array
    {
        return [
            'start_date'     => JapanTime::format($this->start_date, 'Y-m-d'),
            'end_date'       => JapanTime::format($this->end_date, 'Y-m-d'),
            'repeats_yearly' => $this->repeats_yearly,
            'description'    => $this->description,
        ];
    }

    /** 一覧の期間（毎年は月と日だけ「12/29〜1/3」、その年だけは「2026/08/13〜2026/08/14」。1 日だけなら 1 つ） */
    public function periodLabel(): string
    {
        $format = $this->repeats_yearly ? 'n/j' : 'Y/m/d';
        $from   = JapanTime::format($this->start_date, $format);
        $to     = JapanTime::format($this->end_date, $format);

        return $from === $to ? $from : "{$from}〜{$to}";
    }

    /**
     * その日（日本の暦の日付。JapanTime::today() と同じ形）がこの期間に入るか。
     *
     * ⚠ 日付どうしは 'Y-m-d'・'md' の文字列で比べる（date キャストの属性は UTC の 0:00、渡す日も UTC の 0:00 の
     *   日本の日付なので、時刻やタイムゾーンを持ち込まない）
     */
    public function covers(CarbonInterface $day): bool
    {
        if (! $this->repeats_yearly) {
            $date = $day->format('Y-m-d');

            return $this->start_date->format('Y-m-d') <= $date && $date <= $this->end_date->format('Y-m-d');
        }

        $monthDay = $day->format('md');
        $from     = $this->start_date->format('md');
        $to       = $this->end_date->format('md');

        return $from <= $to
            ? $from <= $monthDay && $monthDay <= $to
            : $monthDay >= $from || $monthDay <= $to;
    }
}
