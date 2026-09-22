<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * **画面に出す日時**と**日本の今日**（Asia/Tokyo）の扱いをここにまとめる（docs/RULES.md Bug #61）。
 *
 * アプリの timezone は UTC のまま（config/app.php・段階0 の決定。tests/Feature/Ops/ScheduleTest.php が固定）。
 * 保存・ログ・瞬間の比較は UTC で行い、この 2 つだけをここで作る。
 *
 * ⚠ **「日本時間の扱いが 1 か所にまとまっている」わけではない。** 2026-09-22 の実測では、この部品の外にも
 *   `Asia/Tokyo` を書いたファイルが 6 本ある（失敗メールの本文・運用のコマンド・設定。画面の表示と日本の今日には関わらない）:
 *   - `app/Mail/BackupFailedMail.php` — 失敗メールの日時。**format() と逐語で同じ**（既定の書式まで一致）。
 *     寄せる候補だが今回は保留（範囲外の判断）
 *   - `app/Support/Backup/RetentionPolicy.php` — 保管先のキー名（`Ymd-His`）。format($at, 'Ymd-His') と同値だが、
 *     キーの形は互換性のため固定なので**あえて寄せない**
 *   - `app/Console/Commands/BackupCommand.php` — 運用（瞬間が要る）
 *   - `app/Console/Commands/MailTestCommand.php` — 運用
 *   - `routes/console.php` — 運用
 *   - `config/app.php` の `schedule_timezone` — 定期実行の時刻。ZONE とは**独立**
 *     （両者が割れていないことは JapanTimeTest::test_the_zone_matches_the_schedule_timezone が固定する）
 *
 * ⚠ 保存された日時（TIMESTAMP 列。UTC で入っている）を見せるときは format() を通す。`->format()` を
 *   直接呼ぶと 9 時間ずれる（走査テスト StoredTimestampDisplayScanTest が止める）。
 * ⚠ 「今日」「今月」「今年度」を now() / today() / date() で作らない。日本時間の 0:00〜8:59 は UTC では
 *   まだ前日（走査テスト ClockReadScanTest が止める）。
 */
final class JapanTime
{
    public const ZONE = 'Asia/Tokyo';

    /**
     * 保存された日時（UTC）を日本時間で整形する。null は null（呼び出し側の `?? '—'` がそのまま使える）。
     *
     * ⚠ date キャストの属性（日付だけの列。repairs.started_at など）に既定の書式を当てない。
     *   UTC の 0:00 が日本時間の 9:00 になり、**存在しない時刻**が出る（実測: 2026/05/01 09:00）。
     *   日付だけの列は必ず第 2 引数で 'Y/m/d' を渡す（日付そのものは +9h でも変わらないので正しく出る）。
     */
    public static function format(?DateTimeInterface $at, string $format = 'Y/m/d H:i'): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone(self::ZONE)->format($format);
    }

    /**
     * 日本の今日（暦の日付）を、**アプリの timezone の 0:00** として返す（毎回新しい可変のインスタンス）。
     *
     * ⚠ 日本時間の 0:00（UTC では前日の 15:00）で返さない。date キャストの属性（UTC の 0:00）や
     *   Carbon::create(年, 月, 日)・createFromFormat('Y-m-d', …) と前後を比べると 9 時間ずれ、
     *   たとえば ZealFiscalYear::isFutureMonth() が「今月」を「来月」と判定する。
     *   この形なら ->year / ->month / ->format('Y-m-d') / ->startOfMonth() も、date 属性との比較もそのまま正しい。
     * ⚠ TIMESTAMP 列（UTC の瞬間）へ保存する・TIMESTAMP 列と比べる用途には使わない（それは now() のまま）。
     */
    public static function today(): Carbon
    {
        return Carbon::parse(Carbon::now(self::ZONE)->toDateString());
    }
}
