<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * ガントの時間軸（設計書 §5.1）。区間 [from, to] を保持し、日付を位置(%) と幅(%) に変換する。
 * 併せて軸のトラック幅(px) も出す（`MONTH_WIDTH_PX` / `trackWidthPx()`。設計書 §3）。
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
    /**
     * 1 ヶ月ぶんの幅(px)。設計書 §3.2。
     *
     * モックで承認した密度(1 ヶ月 145px / 1 日 4.79px)を丸めた値。**画面幅から算出しない**
     * ——固定にすることで 1 日の工程の太さが画面幅に依存しなくなる(375px でも同じ約 4.9px)。
     */
    public const MONTH_WIDTH_PX = 150;

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

    /**
     * 軸が掛かっている月の数(月初・月末に丸めない範囲でも、掛かっている月を全部数える)。
     *
     * ⚠ **`diffInMonths()` を使わない。** Carbon 3 は float を返し、月末日をまたぐと
     *   端数が出る(`GanttScale::days()` の注記と同じ理由)。年と月の整数演算で出す。
     *
     * ⚠ 既存の `totalDays()` と揃えて必ず 1 以上を返す。逆転区間でも負の px 幅を
     *   Blade へ渡さないため。
     */
    public function monthCount(): int
    {
        return max(1, ($this->to->year - $this->from->year) * 12
            + ($this->to->month - $this->from->month) + 1);
    }

    /**
     * ガントのトラック(案件名の列を除いた軸の部分)の幅(px)。
     *
     * ⚠ **「1 ヶ月 150px」はこの定数 1 箇所にしか無い。** Blade にも別のサービスにも
     *   数字を書かない(Bug #41)。ラベル列の幅(320 / 262 / 140px)は CSS 変数側が持ち、
     *   PHP は一切知らない(設計書 §4.2)。
     */
    public function trackWidthPx(): int
    {
        return $this->monthCount() * self::MONTH_WIDTH_PX;
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
