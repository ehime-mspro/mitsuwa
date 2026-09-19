<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * 日本時間(Asia/Tokyo)の扱いを 1 か所にまとめる(docs/RULES.md Bug #61)。
 *
 * アプリの timezone は UTC のまま(config/app.php・段階0 の決定。tests/Feature/Ops/ScheduleTest.php が固定)。
 * 保存・ログ・瞬間の比較は UTC で行い、**利用者に見せる日時**と**日本の今日**だけをここで作る。
 *
 * ⚠ 保存された日時(TIMESTAMP 列。UTC で入っている)を見せるときは format() を通す。`->format()` を
 *   直接呼ぶと 9 時間ずれる(走査テスト StoredTimestampDisplayScanTest が止める)。
 * ⚠ 「今日」「今月」「今年度」を now() / today() / date() で作らない。日本時間の 0:00〜8:59 は UTC では
 *   まだ前日(走査テスト ClockReadScanTest が止める)。
 */
final class JapanTime
{
    public const ZONE = 'Asia/Tokyo';

    /**
     * 保存された日時(UTC)を日本時間で整形する。null は null(呼び出し側の `?? '—'` がそのまま使える)。
     */
    public static function format(?DateTimeInterface $at, string $format = 'Y/m/d H:i'): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone(self::ZONE)->format($format);
    }

    /**
     * 日本の今日(暦の日付)を、**アプリの timezone の 0:00** として返す(毎回新しい可変のインスタンス)。
     *
     * ⚠ 日本時間の 0:00(UTC では前日の 15:00)で返さない。date キャストの属性(UTC の 0:00)や
     *   Carbon::create(年, 月, 日)・createFromFormat('Y-m-d', …) と前後を比べると 9 時間ずれ、
     *   たとえば ZealFiscalYear::isFutureMonth() が「今月」を「来月」と判定する。
     *   この形なら ->year / ->month / ->format('Y-m-d') / ->startOfMonth() も、date 属性との比較もそのまま正しい。
     * ⚠ TIMESTAMP 列(UTC の瞬間)へ保存する・TIMESTAMP 列と比べる用途には使わない(それは now() のまま)。
     */
    public static function today(): Carbon
    {
        return Carbon::parse(Carbon::now(self::ZONE)->toDateString());
    }
}
