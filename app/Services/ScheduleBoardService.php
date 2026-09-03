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
 * ⚠ **ページングしない**（設計書 §4.2）。1 部署の案件数が 200 を超えたら見直す。
 */
class ScheduleBoardService
{
    public const STATUS_ALL      = 'all';

    public const STATUS_RUNNING  = 'running';

    public const STATUS_LATE     = 'late';

    public const STATUS_DONE     = 'done';

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
        // ⚠ **ここ 1 箇所だけで出す**（M-2）。build()（view へ返す側）と filters()
        //   （入力を検証する側）の 2 箇所に同じ三項演算子を置くと、語彙が 3 つ目に
        //   増えたときどちらかを直し忘れる形になる。
        $statuses      = $tracksActuals ? self::STATUSES : self::DATE_STATUSES;

        $filters = $this->filters($kinds, $request, $statuses);

        // ⚠ **月初に正規化してから加減算する。** Carbon の subMonths()/addMonths() は
        //   月末日で溢れる（実測: 2026-08-31 の 6 ヶ月前は 2026-03-03 になり、そのあと
        //   startOfMonth() を通しても 3/1 ＝ 軸が 1 ヶ月ずれる）。
        // ⚠ この範囲は Task 4 で「データの範囲」（案B）へ差し替える。ここでは
        //   ズームを消しても振る舞いが変わらないことを優先して直書きしている。
        $anchor = $today->startOfMonth();
        $scale  = new GanttScale(
            $anchor->subMonths(6),
            $anchor->addMonths(12)->endOfMonth()->startOfDay()
        );

        $rows         = [];
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

                $rows[] = $row;
            }
        }

        return [
            'rows'              => $rows,
            'unregisteredCount' => $unregistered,
            'filters'           => $filters,
            'kinds'             => $kinds,
            'statuses'          => $statuses,
            'axis'              => [
                'from'        => $scale->from()->toDateString(),
                'to'          => $scale->to()->toDateString(),
                'headers'     => $this->headers($scale),
                'todayPct'    => $scale->contains($today) ? $scale->left($today) : null,
                'todayLabel'  => $today->format('n/j'),
            ],
        ];
    }

    /**
     * ⚠ **クエリキーに null を入れない**（設計書 §4.2 / Bug #31）。
     *   リンクを組むときに `Arr::query()` が null のキーを丸ごと捨てるため、
     *   ここで `''` / 既定値へ正規化しておく。
     */
    private function filters(array $kinds, Request $request, array $statuses): array
    {
        $kind = (string) ($request->query('kind') ?? self::STATUS_ALL);
        if ($kind !== self::STATUS_ALL && ! array_key_exists($kind, $kinds)) {
            $kind = self::STATUS_ALL;
        }

        $status = (string) ($request->query('status') ?? self::STATUS_RUNNING);
        if (! array_key_exists($status, $statuses)) {
            $status = self::STATUS_RUNNING;
        }

        return [
            'kind'   => $kind,
            'status' => $status,
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
                // まだ始まっていない工程は薄く出す（設計書 §4.2）。
                // ⚠ 実績を持たない親では薄くしない（§4.2 は opacity 案を「済を 1.6:1 まで
                //   落とす」として却下しており、住宅は分類色のまま出す。設計書 §8）。
                'future'   => $tracksActuals && $step->actual_start === null && $spans[$i]['from']->greaterThan($today),
                // 進行中の棒だけ濃い輪郭。詳細カード（_schedule_gantt.blade.php）と同じ規則にする（設計書 §8）
                'ring'     => ! $tracksActuals && $step->dateState($today) === ScheduleStepStatus::RUNNING,
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
     * 実績を持たない親の案件ステータス（設計書 §8）。
     *
     * ⚠ **「済 > 進行中 > これから」は画面（絞り込みの並び）の見せ方であって、
     *   判定の評価順ではない。** 直上の status()「完了 > 遅延 > 進行中」は評価順そのものの
     *   説明だったので、同じ書き方をここでも踏襲すると**コードの方が誤りに見えて直されかねない**。
     *   実際の評価順は次の 3 行:
     *
     *   1. 1 つでも進行中の工程があれば → 進行中
     *   2. 進行中が無く これから があれば → 済も混ざっていれば 進行中 / 無ければ これから
     *   3. どちらも無ければ → 済があれば 済 / 無ければ（全部未定）これから
     *
     * ⚠ 「未定」（日付が 1 つも無い工程）は判定に数えない。全部が未定の案件は
     *   「これから」に倒す。**既定の絞り込みは running なので、全部未定の案件は
     *   どのみち既定の画面には出ない**（「これから」か「すべて」に切り替えて初めて見える）。
     *   ここで「これから」に倒しておくのは、その 2 つの絞り込みに切り替えたときに
     *   日付を入れ忘れた案件が見えて直せるようにするため（「進行中」や「済」に倒すと、
     *   絞り込みをどれに切り替えても永遠に画面から見えなくなる）。
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
     *
     * ⚠ **空と混在は別の例外にする。** 空は「対象クラスの指定漏れ」、混在は
     *   「部署をまたいで束ねてしまった」で、原因も直し方も違う。混在のほうは
     *   `implode()` でどの組み合わせか名指しする（コントローラの KINDS が複数あるので、
     *   「混ぜられません」だけではどのボードの設定か分からない）。
     */
    private function tracksActuals(array $kinds): bool
    {
        if ($kinds === []) {
            throw new \LogicException(
                '対象クラスが空です。ScheduleBoardController の KINDS を確認してください。'
            );
        }

        $flags = [];

        foreach ($kinds as [$class]) {
            $flags[] = (new $class())->scheduleTracksActuals();
        }

        if (count(array_unique($flags)) > 1) {
            throw new \LogicException(
                '1 枚のボードに、実績を持つ親と持たない親を混ぜられません（'
                . implode(' / ', array_keys($kinds)) . '）。'
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

    /**
     * 月の見出し。⚠ 粒度の切り替え（週 / 四半期）は 2026-09-03 に削除した（設計書 §5）。
     *
     * @return list<array{label: string, widthPct: float, strong: bool}>
     */
    private function headers(GanttScale $scale): array
    {
        $headers = [];
        $cursor  = $scale->from()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($scale->to())) {
            $next = $cursor->addMonth()->startOfMonth();

            // ⚠ 最後のセルが軸をはみ出さないように、区間の終わりで打ち切る
            // ⚠ `$scale->to()` が常に月末に揃っている現状では実質到達しない（2026-09-03 実測で
            //   この三項演算子を外しても 183 本すべて緑＝同値変異）。GanttScale の構築元が
            //   増えて月末以外の `to` が来たときの防御として残す。
            $end  = $next->greaterThan($scale->to()) ? $scale->to()->addDay() : $next;
            $days = max(1, (int) round($cursor->diffInDays($end)));

            $headers[] = [
                'label'    => $cursor->format('n') . '月',
                'widthPct' => $days / $scale->totalDays() * 100,
                'strong'   => in_array($cursor->month, [1, 4, 7, 10], true),
            ];

            $cursor = $next;
        }

        return $headers;
    }
}
