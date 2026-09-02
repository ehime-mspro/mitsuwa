<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * 工程の遅延・進捗の判定（設計書 §5.4）。
 *
 * ⚠ **判定はここ 1 箇所だけ。** 詳細カード・ボードのバッジ・KPI が別々に計算すると
 *   画面ごとに数が食い違う（Bug #46）。
 *
 * ⚠ **遅延と進捗は別の軸として返す。** 混ぜると「完了したが遅れた」が表現できなくなる。
 *   どう見せるか（赤枠にするのかチップにするのか）は表示側が決める。
 *
 * ⚠ **「今日」は必ず引数で受け取る。** 内部で now() を呼ぶと、テストが実行日に依存して
 *   「凍結したつもりで効いていない」状態を作る。
 */
final class ScheduleStepStatus
{
    public const DONE    = 'done';

    public const RUNNING = 'running';

    public const TODO    = 'todo';

    /**
     * 日付だけで決まる状態（設計書 §4.1）。**住宅事業が使う。遅延とは別の軸。**
     *
     * ⚠ `RUNNING` / `DONE` は上の進捗の定数と**同じ文字列**を意図的に共有している。
     *   ボードの絞り込みの URL（`?status=running`）が部署で食い違わないようにするため。
     *   語彙を 2 つ持つと、どちらの 'running' なのかが読めなくなる。
     */
    public const UPCOMING = 'upcoming';

    public const UNDATED = 'undated';

    /** チップに出す日本語。⚠ 画面はここだけを見る（Blade に直書きしない） */
    public const STATE_LABELS = [
        self::UPCOMING => 'これから',
        self::RUNNING  => '進行中',
        self::DONE     => '済',
        self::UNDATED  => '未定',
    ];

    /**
     * 遅延日数。遅れていなければ 0（設計書 §5.4）。
     *
     * ```
     * planned_end が NULL      → 判定しない（0）
     * 実績終了あり             → actual_end  > planned_end なら その差
     * 実績終了なし             → 今日        > planned_end なら その差
     * ```
     *
     * ⚠ 「実績開始あり・終了なし」と「実績なし」は**同じ式**になる（どちらも今日と比べる）。
     *   分けて書く必要はないが、**未着手でも遅延に数えるのが肝**なので消さないこと。
     *   落とすと「着手すらしていない工程が一番危ないのに一番静か」という逆転が起きる。
     *
     * ⚠ 判定は `>` であって `>=` ではない。予定終了ちょうどに終わったのは遅延ではない。
     */
    public static function delayDays(
        ?CarbonInterface $plannedEnd,
        ?CarbonInterface $actualEnd,
        CarbonInterface $today
    ): int {
        if ($plannedEnd === null) {
            return 0;
        }

        $due  = CarbonImmutable::instance($plannedEnd)->startOfDay();
        $mark = CarbonImmutable::instance($actualEnd ?? $today)->startOfDay();

        if ($mark->lessThanOrEqualTo($due)) {
            return 0;
        }

        return (int) round($due->diffInDays($mark));
    }

    public static function isLate(
        ?CarbonInterface $plannedEnd,
        ?CarbonInterface $actualEnd,
        CarbonInterface $today
    ): bool {
        return self::delayDays($plannedEnd, $actualEnd, $today) > 0;
    }

    /**
     * 進捗の状態。遅延とは独立。
     *
     * ⚠ 実績終了だけが入って実績開始が空、という状態は validate() が禁じている（設計書 §4.5）が、
     *   万一入っても「完了」に倒して描画側の分岐が壊れないようにする。
     */
    public static function progress(?CarbonInterface $actualStart, ?CarbonInterface $actualEnd): string
    {
        if ($actualEnd !== null) {
            return self::DONE;
        }

        return $actualStart !== null ? self::RUNNING : self::TODO;
    }

    /**
     * 日付だけで決まる状態（設計書 §4.1）。
     *
     * ```
     * 開始日も終了日も無い     → 未定
     * 今日 < 開始日            → これから
     * 開始日 ≤ 今日 ≤ 終了日   → 進行中
     * 終了日 < 今日            → 済
     * ```
     *
     * ⚠ **終了日が無く開始日だけの行（＝ ◆）**: `今日 < 開始日` なら これから、それ以外は 済。
     * ⚠ **開始日が無く終了日だけの行**は「終了日 < 今日 なら 済 / そうでなければ 進行中」。
     *   入力上ありうるので分岐を落とさない。
     * ⚠ **判定は `<` であって `<=`。** 開始日ちょうどの工程は「これから」ではなく「進行中」。
     * ⚠ **「今日」は必ず引数で受け取る**（このクラスの方針）。
     */
    public static function dateState(
        ?CarbonInterface $start,
        ?CarbonInterface $end,
        CarbonInterface $today
    ): string {
        $t = CarbonImmutable::instance($today)->startOfDay();
        $s = $start !== null ? CarbonImmutable::instance($start)->startOfDay() : null;
        $e = $end !== null ? CarbonImmutable::instance($end)->startOfDay() : null;

        if ($s === null && $e === null) {
            return self::UNDATED;
        }

        if ($s === null) {
            return $e->lessThan($t) ? self::DONE : self::RUNNING;
        }

        if ($t->lessThan($s)) {
            return self::UPCOMING;
        }

        if ($e === null) {
            return self::DONE;
        }

        return $e->lessThan($t) ? self::DONE : self::RUNNING;
    }

    /**
     * 自動マイルストーン（◆）の塗り分け（設計書 §3.4）。
     *
     * ⚠ **日付だけで決める。** その列が予定なのか実績なのかを知る必要はない。
     *   今日以前 → 塗りつぶし ◆ ／ 今日より後 → 白抜き ◆
     */
    public static function isReached(CarbonInterface $date, CarbonInterface $today): bool
    {
        return CarbonImmutable::instance($date)->startOfDay()
            ->lessThanOrEqualTo(CarbonImmutable::instance($today)->startOfDay());
    }
}
