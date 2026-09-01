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
