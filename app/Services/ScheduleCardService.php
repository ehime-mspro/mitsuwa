<?php

namespace App\Services;

use App\Enums\ScheduleStepCategory;
use App\Models\ScheduleStep;
use App\Support\GanttScale;
use App\Support\ScheduleStepStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * 詳細ページの工程表カード 1 枚ぶんの表示データを組み立てる（設計書 §4.1 / §5）。
 *
 * ⚠ **日付 → 位置(%) の変換はここ（と GanttScale）だけが行う。** JS 側に同じ計算を持たせない
 *   （Bug #41: 同じ計算の 2 実装は無音で漂流する）。だから Ajax の応答でも
 *   `_partials._schedule_gantt` を**サーバでレンダリングし直して**返す。
 *
 * ⚠ **「今日」は引数で受け取れるようにする。** テストが実行日に依存しないようにするため。
 */
class ScheduleCardService
{
    /** 軸の前後に取る余白（月）。設計書 §5.5 */
    private const PADDING_MONTHS = 1;

    /** 罫線を濃くする月（四半期の頭） */
    private const QUARTER_MONTHS = [1, 4, 7, 10];

    /**
     * @return array{
     *   owner: Model, steps: Collection, endpoints: array<string, string>,
     *   categories: list<array{value: string, label: string, color: string}>,
     *   today: CarbonImmutable, gantt: array|null
     * }
     */
    public function build(Model $owner, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $steps = $owner->scheduleSteps()->get();

        return [
            'owner'      => $owner,
            'steps'      => $steps,
            // ⚠ JS へ渡す配列は**ここで組み立てる**。Blade の `@json()` に多行の配列リテラルや
            //   `->method()` を渡すと、Blade の引数パーサが途中で打ち切って壊れた PHP を吐き、
            //   本番の view:cache 後に ParseError で 500 する（Bug #26）。
            //   Blade 側は必ず `@json($単一変数)` の形にする。
            'rows'       => $steps->map(fn (ScheduleStep $s) => [
                'id'            => $s->id,
                'name'          => $s->name,
                'category'      => $s->category->value,
                'planned_start' => $s->planned_start?->toDateString(),
                'planned_end'   => $s->planned_end?->toDateString(),
                'actual_start'  => $s->actual_start?->toDateString(),
                'actual_end'    => $s->actual_end?->toDateString(),
                'notes'         => $s->notes ?? '',
            ])->values()->all(),
            'endpoints'  => $this->endpoints($owner),
            'categories' => $this->categories(),
            'today'      => $today,
            'gantt'      => $this->gantt($steps, $owner->autoMilestones(), $today),
        ];
    }

    /**
     * ⚠ `update` / `destroy` は id を含むので **`__ID__` のひな型**を返す。
     *   JS 側が実 id へ差し替える。テストも同じ手順を踏む（プラン 決定 B）。
     */
    private function endpoints(Model $owner): array
    {
        return [
            'store'   => route($owner->scheduleStepRoute('store'), $owner),
            'reorder' => route($owner->scheduleStepRoute('reorder'), $owner),
            'update'  => route($owner->scheduleStepRoute('update'), [$owner, '__ID__']),
            'destroy' => route($owner->scheduleStepRoute('destroy'), [$owner, '__ID__']),
        ];
    }

    /** @return list<array{value: string, label: string, color: string}> */
    private function categories(): array
    {
        return array_map(
            fn (ScheduleStepCategory $c) => ['value' => $c->value, 'label' => $c->label(), 'color' => $c->color()],
            ScheduleStepCategory::cases()
        );
    }

    /**
     * 時間軸と描画用の行を作る。日付が 1 つも無ければ null（＝ガントを描かない）。
     *
     * ⚠ **日付が 1 つも無い案件は軸を作れない**（設計書 §5.5）。0 除算とレイアウト崩れの
     *   両方を防ぐため、ここで null を返して Blade 側に案内文を出させる。
     */
    private function gantt(Collection $steps, array $milestones, CarbonImmutable $today): ?array
    {
        $dates = [];

        foreach ($steps as $step) {
            foreach ([$step->planned_start, $step->planned_end, $step->actual_start, $step->actual_end] as $d) {
                if ($d !== null) {
                    $dates[] = CarbonImmutable::instance($d)->startOfDay();
                }
            }
        }

        foreach ($milestones as $m) {
            $dates[] = CarbonImmutable::instance($m['date'])->startOfDay();
        }

        // ⚠ **工程が 1 行でもあるなら、日付が 1 つも無くてもガントを描く**（設計書 §3.7）。
        //   日付が無い行は棒を描かないが**左の一覧には必ず残し**、期間欄に「日付未設定」を出す。
        //   ここで null を返すと「工程が登録されていません」という**嘘の案内**が出て、
        //   利用者は「保存できていない」と誤解する（§3.7 の「握り潰さない」はこのこと）。
        //   軸は作れないので今日を種にする（0 除算を避けつつ行を並べられる）。
        if ($dates === [] && $steps->isNotEmpty()) {
            $dates[] = $today;
        }

        // 工程も自動 ◆ も 1 つも無いときだけ、ガントを描かず案内文に任せる（設計書 §5.5）
        if ($dates === []) {
            return null;
        }

        $from = min($dates)->subMonths(self::PADDING_MONTHS)->startOfMonth();
        // ⚠ endOfMonth() は 23:59:59.999999 を返すので startOfDay() で揃える。
        //   揃えないと日数が 1 多く出る（実測: 2026-02-01〜2026-08-31 が 213 日になった）。
        $to = max($dates)->addMonths(self::PADDING_MONTHS)->endOfMonth()->startOfDay();

        // 今日が範囲外なら今日も含める（今日線が枠外に出ないように）
        if ($today->lessThan($from)) {
            $from = $today->startOfMonth();
        }
        if ($today->greaterThan($to)) {
            $to = $today->endOfMonth()->startOfDay();
        }

        $scale = new GanttScale($from, $to);

        return [
            'months'     => $this->months($scale),
            'rows'       => $steps->map(fn (ScheduleStep $s) => $this->row($s, $scale, $today))->all(),
            'milestones' => $this->milestones($milestones, $scale, $today),
            'todayPct'   => $scale->contains($today) ? $scale->left($today) : null,
            'todayLabel' => $today->format('n/j'),
        ];
    }

    /** @return list<array{label: string, year: string, widthPct: float, quarterStart: bool}> */
    private function months(GanttScale $scale): array
    {
        $months = [];
        $cursor = $scale->from()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($scale->to())) {
            $months[] = [
                'label'        => $cursor->format('n') . '月',
                'year'         => $cursor->format('Y'),
                'widthPct'     => $cursor->daysInMonth / $scale->totalDays() * 100,
                'quarterStart' => in_array($cursor->month, self::QUARTER_MONTHS, true),
            ];

            $cursor = $cursor->addMonth()->startOfMonth();
        }

        return $months;
    }

    /**
     * 1 工程ぶんの描画情報。
     *
     * ⚠ **範囲外へはみ出さないよう clamp するのは呼び出し側（ここ）の責務**（設計書 §5.1）。
     *   幅は「左端からの残り」までに抑える（棒が枠を突き抜けてレイアウトを壊さないため）。
     */
    private function row(ScheduleStep $step, GanttScale $scale, CarbonImmutable $today): array
    {
        $row = [
            'id'         => $step->id,
            'name'       => $step->name,
            'color'      => $step->category->color(),
            'periodText' => $step->periodText($today),
            'delayDays'  => $step->delayDays($today),
            'progress'   => $step->progress(),
            'kind'       => 'none',
            'leftPct'    => 0.0,
            'widthPct'   => 0.0,
        ];

        if (! $step->isDrawable()) {
            return $row;
        }

        $start          = $step->drawStart();
        $row['kind']    = $step->isMilestone() ? 'milestone' : 'bar';
        $row['leftPct'] = GanttScale::clamp($scale->left($start), 0.0, 100.0);

        if ($row['kind'] === 'bar') {
            $row['widthPct'] = GanttScale::clamp(
                $scale->width($start, $step->drawEnd($today)),
                0.0,
                100.0 - $row['leftPct']
            );
        }

        return $row;
    }

    /** @return list<array{label: string, leftPct: float, reached: bool}> */
    private function milestones(array $milestones, GanttScale $scale, CarbonImmutable $today): array
    {
        return array_map(function (array $m) use ($scale, $today) {
            $date = CarbonImmutable::instance($m['date'])->startOfDay();

            return [
                'label'   => $m['label'],
                'leftPct' => GanttScale::clamp($scale->left($date), 0.0, 100.0),
                // ⚠ 塗り分けは日付だけで決める（その列が予定か実績かは見ない。設計書 §3.4）
                'reached' => ScheduleStepStatus::isReached($date, $today),
            ];
        }, $milestones);
    }
}
