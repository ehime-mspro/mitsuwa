<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * ガントの時間軸（設計書 §5.1）。区間 [from, to] を保持し、日付を位置(%) と幅(%) に変換する。
 *
 * ⚠ **JS ライブラリを足さない方針の中核。** 位置の計算はここだけが持ち、Blade は
 *   結果を inline style に置くだけにする。JS 側で同じ計算を持つと無音で漂流する（Bug #41）。
 *
 * ⚠ **日付は必ず startOfDay() に揃えてから引く。** 揃えないと実行環境の timezone や
 *   時刻成分で 1 日ずれる（実測: endOfMonth() は 23:59:59.999999 を返すため、
 *   2026-02-01 〜 2026-08-31 が 212 日でなく 213 日になった）。
 *
 * ⚠ **範囲外の日付を clamp しない。** 0% 未満 / 100% 超をそのまま返す。
 *   ここで clamp すると「範囲の作り方がおかしい」ことに気づけなくなる。
 *   棒が枠外へ飛び出さないようにするのは呼び出し側の責務で、道具は clamp() に置いてある。
 */
class GanttScale
{
    private CarbonImmutable $from;

    private CarbonImmutable $to;

    private int $totalDays;

    public function __construct(CarbonInterface $from, CarbonInterface $to)
    {
        $this->from = CarbonImmutable::instance($from)->startOfDay();
        $this->to   = CarbonImmutable::instance($to)->startOfDay();

        // 両端を含む日数。始点と終点が同じ日なら 1（0 除算を防ぐ）。
        $this->totalDays = max(1, self::days($this->from, $this->to) + 1);
    }

    public function from(): CarbonImmutable
    {
        return $this->from;
    }

    public function to(): CarbonImmutable
    {
        return $this->to;
    }

    public function totalDays(): int
    {
        return $this->totalDays;
    }

    /** 区間内かどうか（今日線を描くかの判定に使う） */
    public function contains(CarbonInterface $date): bool
    {
        $d = CarbonImmutable::instance($date)->startOfDay();

        return $d->greaterThanOrEqualTo($this->from) && $d->lessThanOrEqualTo($this->to);
    }

    /** 区間の先頭から見た位置（%）。範囲外は負や 100 超を返す。 */
    public function left(CarbonInterface $date): float
    {
        return self::days($this->from, CarbonImmutable::instance($date)->startOfDay())
            / $this->totalDays * 100;
    }

    /**
     * 開始日から終了日までの幅（%）。
     *
     * ⚠ **`+ 1` を消さないこと。** 両端を含めるための 1 日で、これが無いと
     *   1 日だけの工程（start === end）が幅 0 になって画面から消える。
     */
    public function width(CarbonInterface $start, CarbonInterface $end): float
    {
        $s = CarbonImmutable::instance($start)->startOfDay();
        $e = CarbonImmutable::instance($end)->startOfDay();

        return (self::days($s, $e) + 1) / $this->totalDays * 100;
    }

    public static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    /**
     * 日数の差（符号つき）。
     *
     * ⚠ Carbon 3 の diffInDays() は **float** を返す（実測 3.11.3）。DST のある地域で
     *   23 時間の日があると 0.958… のような値になりうるので round() で整数に丸める。
     *   両端を startOfDay() に揃えてあるので、通常は誤差なく整数になる。
     */
    private static function days(CarbonImmutable $a, CarbonImmutable $b): int
    {
        return (int) round($a->diffInDays($b));
    }
}
