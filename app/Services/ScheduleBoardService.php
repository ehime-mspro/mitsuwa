<?php

namespace App\Services;

use App\Models\ScheduleStep;
use App\Support\GanttScale;
use App\Support\LanePacker;
use App\Support\ScheduleStepStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * 横断ボード 1 枚ぶんを組み立てる（設計書 §4.2）。不動産用・住宅用の 2 つが共有する。
 *
 * ⚠ **対象クラスに既定値を持たせない**（設計書 §4.3）。既定を持たせると、新しい部署の
 *   ボードを足した人が引数を省略した瞬間に**全部署の案件が漏れる**。
 *
 * ⚠ **KPI は絞り込み後の行から数える**（プラン 決定 H）。全件から数えると
 *   絞り込んだときに画面の行数と食い違う（Bug #46）。
 *
 * ⚠ **ページングしない**（設計書 §4.2）。1 部署の案件数が 200 を超えたら見直す。
 */
class ScheduleBoardService
{
    public const STATUS_ALL     = 'all';

    public const STATUS_RUNNING = 'running';

    public const STATUS_LATE    = 'late';

    public const STATUS_DONE    = 'done';

    public const STATUS_UPCOMING = 'upcoming';

    /** ⚠ 「すべて」は空文字にしない（Arr::query() が null キーを捨てる。Bug #31 / 決定 I） */
    public const STATUSES = [
        self::STATUS_RUNNING => '進行中',
        self::STATUS_ALL     => 'すべて',
        self::STATUS_LATE    => '遅延',
        self::STATUS_DONE    => '完了',
    ];

    /**
     * 実績を持たない親（住宅事業）の絞り込み（設計書 §8）。**遅延は無い。**
     *
     * ⚠ 「すべて」は空文字にしない（`Arr::query()` が null / 空のキーを捨てる。Bug #31）。
     * ⚠ `running` / `done` は不動産と同じ文字列。URL の語彙を 2 つ持たない（決定 P2）。
     */
    public const DATE_STATUSES = [
        self::STATUS_RUNNING  => '進行中',
        self::STATUS_ALL      => 'すべて',
        self::STATUS_UPCOMING => 'これから',
        self::STATUS_DONE     => '済',
    ];

    /**
     * ズーム（プラン 決定 F）。範囲とヘッダの粒度を一緒に変える。
     * ⚠ 既定 `month` は設計書 §5.5 の「今日の 6 ヶ月前 〜 12 ヶ月後」ちょうど。
     */
    public const ZOOMS = [
        'week'    => ['label' => '週',     'before' => 1,  'after' => 2,  'granularity' => 'week'],
        'month'   => ['label' => '月',     'before' => 6,  'after' => 12, 'granularity' => 'month'],
        'quarter' => ['label' => '四半期', 'before' => 12, 'after' => 24, 'granularity' => 'quarter'],
    ];

    private const DEFAULT_ZOOM = 'month';

    /** 「まもなく」の窓（日） */
    private const SOON_DAYS = 30;

    /**
     * @param  array<string, array{0: class-string, 1: string}>  $kinds  絞り込みキー => [親クラス, 表示名]
     *
     * ⚠ 第 1 引数に既定値を付けないこと（設計書 §4.3。ReflectionMethod でテストが固定している）。
     */
    public function build(array $kinds, Request $request, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        // ⚠ **1 枚のボードで親の方針が混ざらないことを先に確かめる**（決定 P4）。
        //   混ざったまま進むと、案件ごとに遅延の有無が食い違う画面になる。
        $tracksActuals = $this->tracksActuals($kinds);

        $filters = $this->filters($kinds, $request, $tracksActuals);
        $zoom    = self::ZOOMS[$filters['zoom']];

        // ⚠ **月初に正規化してから加減算する。** Carbon の subMonths()/addMonths() は
        //   月末日で溢れる（実測: 2026-08-31 の 6 ヶ月前は 2026-03-03 になり、そのあと
        //   startOfMonth() を通しても 3/1 ＝ 軸が 1 ヶ月ずれる）。
        $anchor = $today->startOfMonth();
        $from   = $anchor->subMonths($zoom['before']);
        $to     = $anchor->addMonths($zoom['after'])->endOfMonth()->startOfDay();
        $scale = new GanttScale($from, $to);

        $rows         = [];
        $keptSteps    = [];   // 絞り込みを通った案件の工程。KPI がこれを数える（Blade へは渡さない）
        $unregistered = 0;

        foreach ($kinds as $key => [$class, $label]) {
            if ($filters['kind'] !== self::STATUS_ALL && $filters['kind'] !== $key) {
                continue;
            }

            foreach ($class::with('scheduleSteps')->get() as $owner) {
                $steps = $owner->scheduleSteps;

                // ⚠ 工程が 0 件の案件は出さない（件数だけ数える。設計書 §4.2）
                if ($steps->isEmpty()) {
                    $unregistered++;

                    continue;
                }

                $row = $this->row($owner, $label, $key, $steps, $scale, $today, $tracksActuals);

                if (! $this->matches($row, $owner, $steps, $filters)) {
                    continue;
                }

                $rows[]      = $row;
                // ⚠ $rows と添字を揃える。KPI は「絞り込み後」から数える（決定 H）ので、
                //   ここで一緒に積むのが唯一ずれない書き方。
                $keptSteps[] = $steps;
            }
        }

        return [
            'rows'              => $rows,
            'kpi'               => $this->kpi($rows, $keptSteps, $today, $tracksActuals),
            'unregisteredCount' => $unregistered,
            'filters'           => $filters,
            'kinds'             => $kinds,
            'statuses'          => $tracksActuals ? self::STATUSES : self::DATE_STATUSES,
            'zooms'             => self::ZOOMS,
            'axis'              => [
                'from'        => $scale->from()->toDateString(),
                'to'          => $scale->to()->toDateString(),
                'granularity' => $zoom['granularity'],
                'headers'     => $this->headers($scale, $zoom['granularity']),
                'todayPct'    => $scale->contains($today) ? $scale->left($today) : null,
                'todayLabel'  => $today->format('n/j'),
            ],
        ];
    }

    /**
     * ⚠ **クエリキーに null を入れない**（設計書 §4.2 / Bug #31）。
     *   ズームのリンクを組むときに `Arr::query()` が null のキーを丸ごと捨てるため、
     *   ここで `''` / 既定値へ正規化しておく。
     */
    private function filters(array $kinds, Request $request, bool $tracksActuals): array
    {
        $kind = (string) ($request->query('kind') ?? self::STATUS_ALL);
        if ($kind !== self::STATUS_ALL && ! array_key_exists($kind, $kinds)) {
            $kind = self::STATUS_ALL;
        }

        $statuses = $tracksActuals ? self::STATUSES : self::DATE_STATUSES;

        $status = (string) ($request->query('status') ?? self::STATUS_RUNNING);
        if (! array_key_exists($status, $statuses)) {
            $status = self::STATUS_RUNNING;
        }

        $zoom = (string) ($request->query('zoom') ?? self::DEFAULT_ZOOM);
        if (! array_key_exists($zoom, self::ZOOMS)) {
            $zoom = self::DEFAULT_ZOOM;
        }

        return [
            'kind'   => $kind,
            'status' => $status,
            'zoom'   => $zoom,
            'q'      => trim((string) ($request->query('q') ?? '')),
        ];
    }

    /** 1 案件ぶんの行（サマリの色帯 ＋ 展開用の工程明細） */
    private function row(Model $owner, string $kindLabel, string $kindKey, $steps, GanttScale $scale, CarbonImmutable $today, bool $tracksActuals): array
    {
        $drawable = $steps->filter(fn (ScheduleStep $s) => $s->isDrawable())->values();

        $spans = $drawable->map(fn (ScheduleStep $s) => [
            'from' => CarbonImmutable::instance($s->drawStart())->startOfDay(),
            'to'   => CarbonImmutable::instance($s->drawEnd($today))->startOfDay(),
        ])->all();

        $lanes = LanePacker::assign($spans);

        $bars = [];
        foreach ($drawable as $i => $step) {
            $left = GanttScale::clamp($scale->left($spans[$i]['from']), 0.0, 100.0);

            $bars[] = [
                'name'     => $step->name,
                'color'    => $step->category->color(),
                'lane'     => $lanes[$i],
                'topPx'    => LanePacker::LANE_TOP + $lanes[$i] * LanePacker::LANE_HEIGHT,
                'leftPct'  => $left,
                'widthPct' => GanttScale::clamp($scale->width($spans[$i]['from'], $spans[$i]['to']), 0.0, 100.0 - $left),
                'late'     => $tracksActuals && $step->isLate($today),
                // まだ始まっていない工程は薄く出す（設計書 §4.2）
                'future'   => $step->actual_start === null && $spans[$i]['from']->greaterThan($today),
            ];
        }

        $laneCount = LanePacker::laneCount($lanes);

        return [
            'kind'       => $kindKey,
            'kindLabel'  => $kindLabel,
            'code'       => $owner->scheduleCode(),
            'name'       => $owner->scheduleName(),
            'url'        => $owner->scheduleUrl(),
            'status'     => $tracksActuals
                ? $this->status($steps, $today)
                : $this->dateStatus($steps, $today),
            // ⚠ 実績を持たない親では遅延を出さない（設計書 §8）
            'delayDays'  => $tracksActuals ? (int) $steps->max(fn (ScheduleStep $s) => $s->delayDays($today)) : 0,
            'laneCount'  => $laneCount,
            'rowHeight'  => LanePacker::rowHeight($laneCount),
            'bars'       => $bars,
            'milestones' => $this->milestones($owner, $scale, $today),
            'steps'      => $steps->map(fn (ScheduleStep $s) => [
                'name'       => $s->name,
                'color'      => $s->category->color(),
                'periodText' => $s->periodText($today),
                'delayDays'  => $tracksActuals ? $s->delayDays($today) : 0,
                'progress'   => $s->progress(),
            ])->all(),
        ];
    }

    /**
     * 案件のステータス（プラン 決定 G: **完了 > 遅延 > 進行中**）。
     *
     * ⚠ 「遅れて終わった案件」を遅延一覧に出さない。完了した案件はもう手当てできず、
     *   遅延の一覧をノイズで埋めるだけになる。
     */
    private function status($steps, CarbonImmutable $today): string
    {
        if ($steps->every(fn (ScheduleStep $s) => $s->actual_end !== null)) {
            return self::STATUS_DONE;
        }

        if ($steps->contains(fn (ScheduleStep $s) => $s->isLate($today))) {
            return self::STATUS_LATE;
        }

        return self::STATUS_RUNNING;
    }

    /**
     * 実績を持たない親の案件ステータス（設計書 §8: **済 > 進行中 > これから**）。
     *
     * ⚠ 「未定」（日付が 1 つも無い工程）は判定に数えない。全部が未定の案件は
     *   「これから」に倒す（絞り込みから消えると、日付を入れ忘れた案件が画面から
     *   見えなくなって直せない）。
     */
    private function dateStatus($steps, CarbonImmutable $today): string
    {
        $states = $steps->map(fn (ScheduleStep $s) => $s->dateState($today));

        if ($states->contains(ScheduleStepStatus::RUNNING)) {
            return self::STATUS_RUNNING;
        }

        if ($states->contains(ScheduleStepStatus::UPCOMING)) {
            // 済 と これから が混ざっていれば「進行中」に見せる（工事は動いている）
            return $states->contains(ScheduleStepStatus::DONE)
                ? self::STATUS_RUNNING
                : self::STATUS_UPCOMING;
        }

        return $states->contains(ScheduleStepStatus::DONE)
            ? self::STATUS_DONE
            : self::STATUS_UPCOMING;
    }

    /**
     * 1 枚のボードに乗る親が全部同じ方針か（決定 P4）。
     *
     * ⚠ **混在したら黙ってどちらかへ倒さない。** 案件ごとに遅延の有無が食い違う画面になり、
     *   絞り込みの選択肢も決められない。
     */
    private function tracksActuals(array $kinds): bool
    {
        $flags = [];

        foreach ($kinds as [$class]) {
            $flags[] = (new $class())->scheduleTracksActuals();
        }

        if ($flags === [] || count(array_unique($flags)) > 1) {
            throw new \LogicException(
                '1 枚のボードに、実績を持つ親と持たない親を混ぜられません。'
                . 'ScheduleBoardController の KINDS を部署ごとに分けてください。'
            );
        }

        return $flags[0];
    }

    private function matches(array $row, Model $owner, $steps, array $filters): bool
    {
        if ($filters['status'] !== self::STATUS_ALL && $row['status'] !== $filters['status']) {
            return false;
        }

        if ($filters['q'] === '') {
            return true;
        }

        // 案件名・案件コード・工程名のいずれかに含まれること（設計書 §4.2）
        $haystack = $owner->scheduleName() . ' ' . $owner->scheduleCode() . ' '
            . $steps->pluck('name')->implode(' ');

        return mb_stripos($haystack, $filters['q']) !== false;
    }

    /**
     * KPI カードの並び（決定 P5）。**partial は並べるだけ**にして「住宅なら 3 枚」を書かせない。
     *
     * @param  list<array<string, mixed>>  $rows       絞り込み**後**の行（決定 H）
     * @param  list<\Illuminate\Support\Collection>  $keptSteps  同じ添字の案件の工程
     * @return list<array{label: string, value: int, color: string}>
     *
     * ⚠ **`$rows` と `$keptSteps` は添字が揃っている前提。** `build()` が同じループで
     *   一緒に積んでいる。片方だけ絞り込むと KPI と画面が食い違う（設計書 §8.4）。
     */
    private function kpi(array $rows, array $keptSteps, CarbonImmutable $today, bool $tracksActuals): array
    {
        $limit = $today->addDays(self::SOON_DAYS);

        $soon = [
            ['label' => '30日以内に始まる工程', 'value' => $this->countSoon($keptSteps, 'start', $today, $limit), 'color' => '#1D4ED8'],
            ['label' => '30日以内に終わる工程', 'value' => $this->countSoon($keptSteps, 'end', $today, $limit),   'color' => '#B45309'],
        ];

        if (! $tracksActuals) {
            // ⚠ 3 枚とも数えるのは**工程**であって案件ではない（設計書 §8）
            return array_merge([[
                'label' => '進行中の工程',
                'value' => $this->countRunningSteps($keptSteps, $today),
                'color' => '#047857',
            ]], $soon);
        }

        return array_merge([
            ['label' => '進行中の案件',   'value' => count(array_filter($rows, fn ($r) => $r['status'] === self::STATUS_RUNNING)), 'color' => '#047857'],
            ['label' => '遅れている案件', 'value' => count(array_filter($rows, fn ($r) => $r['status'] === self::STATUS_LATE)),    'color' => '#B91C1C'],
        ], $soon);
    }

    /** @param  list<\Illuminate\Support\Collection>  $keptSteps */
    private function countRunningSteps(array $keptSteps, CarbonImmutable $today): int
    {
        $count = 0;

        foreach ($keptSteps as $steps) {
            foreach ($steps as $step) {
                if ($step->dateState($today) === ScheduleStepStatus::RUNNING) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * ⚠ 数えるのは**工程**であって案件ではない（設計書 §4.2 の KPI 3 / 4）。
     *   すでに始まった（終わった）工程は数えない。
     *
     * ⚠ **工程は行の配列を経由させず、Eloquent のまま直接受け取る。**
     *   かつては行に `rawSteps` を紛れ込ませていたが、キーが欠けたときに例外ではなく
     *   **KPI が静かに 0 になる**（Bug #40 の「静かな 0」そのもの）。
     *   Blade へ渡す配列に Eloquent Collection を混ぜない形にも揃う。
     *
     * @param  list<\Illuminate\Support\Collection>  $keptSteps
     */
    private function countSoon(array $keptSteps, string $edge, CarbonImmutable $today, CarbonImmutable $limit): int
    {
        $count = 0;

        foreach ($keptSteps as $steps) {
            foreach ($steps as $step) {
                $actual  = $edge === 'start' ? $step->actual_start : $step->actual_end;
                $planned = $edge === 'start' ? $step->planned_start : $step->planned_end;

                if ($actual !== null || $planned === null) {
                    continue;
                }

                $d = CarbonImmutable::instance($planned)->startOfDay();

                if ($d->greaterThanOrEqualTo($today) && $d->lessThanOrEqualTo($limit)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function milestones(Model $owner, GanttScale $scale, CarbonImmutable $today): array
    {
        return array_map(function (array $m) use ($scale, $today) {
            $date = CarbonImmutable::instance($m['date'])->startOfDay();

            return [
                'label'   => $m['label'],
                'leftPct' => GanttScale::clamp($scale->left($date), 0.0, 100.0),
                'reached' => ScheduleStepStatus::isReached($date, $today),
            ];
        }, $owner->autoMilestones());
    }

    /** @return list<array{label: string, widthPct: float, strong: bool}> */
    private function headers(GanttScale $scale, string $granularity): array
    {
        $headers = [];
        $cursor  = $granularity === 'week'
            ? $scale->from()->startOfWeek()
            : $scale->from()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($scale->to())) {
            [$next, $label, $strong] = match ($granularity) {
                'week'    => [$cursor->addWeek(), $cursor->format('n/j'), $cursor->day <= 7],
                'quarter' => [$cursor->addMonths(3), $cursor->format('Y') . ' Q' . (intdiv($cursor->month - 1, 3) + 1), true],
                default   => [$cursor->addMonth()->startOfMonth(), $cursor->format('n') . '月', in_array($cursor->month, [1, 4, 7, 10], true)],
            };

            // ⚠ 最後のセルが軸をはみ出さないように、区間の終わりで打ち切る
            $end  = $next->greaterThan($scale->to()) ? $scale->to()->addDay() : $next;
            $days = max(1, (int) round($cursor->diffInDays($end)));

            $headers[] = [
                'label'    => $label,
                'widthPct' => $days / $scale->totalDays() * 100,
                'strong'   => $strong,
            ];

            $cursor = $next;
        }

        return $headers;
    }
}
