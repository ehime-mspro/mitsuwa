<?php

namespace App\Support\Approval;

use App\Support\JapanTime;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * 紙の台帳の日付の読み方（要件 11.1・段階5 設計書 §5.9）。受付簿と金額の控えの「日付」の列。
 *
 * 台帳の日付は和暦のドットの文字（`8.05.07`＝令和 8 年 5 月 7 日）。年は**月で決める**（期の始まりの月より前の月は、年度の次の年）。
 * 先頭の数は、年度の和暦の年（`8.01.15`）か、その日の和暦の年（`9.01.15`）の**どちらかと合うこと**（1〜4 月をどちらで書く台帳でも読め、
 * 違う年度の日付〈前の年度の書き残し `7.04.30` など〉を黙って入れない。合わなければエラーにして取り込まない）。
 *
 * ⚠ Excel の日付の数のセル（書式を読まないので数として来る）は、その日がその年度の期間の中にあるときだけ読む（先頭の数が無いので）
 */
final class PaperDate
{
    /**
     * @return array{date: ?CarbonImmutable, error: ?string} 日本の暦の日付（時刻なし）か、読めない理由
     */
    public static function read(mixed $raw, int $fiscalYear, int $startMonth): array
    {
        $era = ApprovalFiscalYear::eraLabel($fiscalYear, $startMonth);

        if (is_int($raw) || is_float($raw)) {
            $day = self::serialDay($raw);
            if ($day === null) {
                return ['date' => null, 'error' => "日付（{$raw}）が読めません"];
            }

            return ApprovalFiscalYear::of($day, $startMonth) === $fiscalYear
                ? ['date' => $day, 'error' => null]
                : ['date' => null, 'error' => "日付（{$day->format('Y/m/d')}）が {$era} 年度の期間の外です"];
        }

        $text = FormInput::trim(mb_convert_kana((string) $raw, 'as'));
        if (! preg_match('/\A(\d{1,2})[.\/\-](\d{1,2})[.\/\-](\d{1,2})\z/', $text, $m)) {
            return ['date' => null, 'error' => "日付「{$raw}」が読めません（8.05.13 の形で書きます）"];
        }
        [, $eraYear, $month, $day] = array_map('intval', $m);

        $year = $month >= $startMonth ? $fiscalYear : $fiscalYear + 1;
        if ($month < 1 || $month > 12 || ! checkdate($month, $day, $year)) {
            return ['date' => null, 'error' => "日付「{$raw}」が読めません（ない日付です）"];
        }

        $date = CarbonImmutable::create($year, $month, $day, 0, 0, 0, JapanTime::ZONE);
        if ($eraYear !== (int) substr($era, 1) && $eraYear !== ApprovalFiscalYear::eraYearOf($date)) {
            return ['date' => null, 'error' => "日付「{$raw}」の年（{$eraYear}）が {$era} 年度と合いません"];
        }

        return ['date' => $date, 'error' => null];
    }

    /**
     * 結び付けに使う日付の鍵（金額の控えと受付簿の行を比べる）。文字の日付は数の並び（`8.5.13`。0 の付け方・全角・区切りの違いを無視）、
     * 数の日付はその日。読めなければ null
     */
    public static function key(mixed $raw): ?string
    {
        if (is_int($raw) || is_float($raw)) {
            $day = self::serialDay($raw);

            return $day === null ? null : 'day:' . $day->format('Y-m-d');
        }

        $text = FormInput::trim(mb_convert_kana((string) $raw, 'as'));

        return preg_match('/\A(\d{1,2})[.\/\-](\d{1,2})[.\/\-](\d{1,2})\z/', $text, $m)
            ? sprintf('era:%d.%d.%d', $m[1], $m[2], $m[3])
            : null;
    }

    /** Excel の日付の数（1900 年の 1 月 1 日が 1）のその日。日付の範囲の外は null */
    private static function serialDay(int|float $serial): ?CarbonImmutable
    {
        if ($serial < 1 || $serial >= 2958466) {   // 9999/12/31 の次
            return null;
        }
        $day = Date::excelToDateTimeObject($serial);

        return CarbonImmutable::create((int) $day->format('Y'), (int) $day->format('m'), (int) $day->format('d'), 0, 0, 0, JapanTime::ZONE);
    }
}
