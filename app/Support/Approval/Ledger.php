<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStatus;
use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\JapanTime;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * 決裁台帳（⑤）の問い合わせ（要件 10・段階4 設計書 §5.8・§5.9・D19〜D22）。画面と Excel が同じものを使う。
 *
 * - 見られる範囲は RequestVisibility（規則は 1 か所）。下書きは出さない（申請者本人にも）
 * - 中身（申請部門・申請の種類・件名・本文）は、申請者本人以外には**最後に提出した中身**で当てる（D19）。直しかけが今の中身に
 *   残りうるのは差戻し中と取り下げだけなので、その 2 つの状態の他人の申請だけを控え（approval_revisions の今の回）で当て、
 *   ほかは申請の行で当てる（関連する決裁No の候補 RelatedNumberController と同じ考え。ほかの状態は今の中身と控えが同じ）
 * - 並びは id の並びとして PHP で作る（sortedIds）。「決裁日の新しい順・同じ日は決裁No の順」の「日」は日本の暦なので、
 *   UTC で保存した日時を SQL で日本の日付に直す必要があり、MySQL と SQLite で書き方が違う（D22）
 */
final class Ledger
{
    /** 1 ページの件数（D22） */
    public const PER_PAGE = 50;

    /** 行を描くのに要る関係（N+1 にしない。steps は今の回の審査と社長の判断を読む） */
    private const WITH = ['applicant', 'type', 'department', 'lastRevision', 'steps'];

    /** 「決裁No の付いたもの」の状態（番号のあと取り下げた欠番は、取り下げのうち番号のあるもの。要件 6.5） */
    private const DECIDED = [ApprovalStatus::Approved, ApprovalStatus::Condition, ApprovalStatus::Rejected];

    /** 「進行中」の状態（取り消しで番号が残ったまま戻ったものも含む） */
    private const IN_PROGRESS = [ApprovalStatus::HeadReview, ApprovalStatus::Review, ApprovalStatus::President, ApprovalStatus::Returned];

    /** 中身の列（申請の行） */
    private const CURRENT = [
        'department' => 'approval_requests.department_id',
        'type'       => 'approval_requests.type_id',
        'subject'    => 'approval_requests.subject',
        'body'       => 'approval_requests.body',
    ];

    /** 中身の列（最後に提出した控え。JSON の中を指す） */
    private const SUBMITTED = [
        'department' => 'approval_revisions.snapshot->department->id',
        'type'       => 'approval_revisions.snapshot->type->id',
        'subject'    => 'approval_revisions.snapshot->subject',
        'body'       => 'approval_revisions.snapshot->body',
    ];

    /** 見られる申請を条件で絞った問い合わせ（並びは付けない。sortedIds() が並べる） */
    public static function query(User $viewer, LedgerFilter $filter): Builder
    {
        $query = RequestVisibility::apply(ApprovalRequest::query(), $viewer)
            ->where('approval_requests.status', '!=', ApprovalStatus::Draft->value);

        self::whereStatus($query, $filter->status);

        if ($filter->decision !== null) {
            $query->where('approval_requests.decision', $filter->decision->value);
        }

        // 決裁日の期間は日本の暦の日（その日の 0:00 から次の日の 0:00 の前まで）
        if ($filter->decidedFrom !== null) {
            $query->where('approval_requests.decided_at', '>=', $filter->decidedFrom->startOfDay()->utc());
        }
        if ($filter->decidedTo !== null) {
            $query->where('approval_requests.decided_at', '<', $filter->decidedTo->startOfDay()->addDay()->utc());
        }

        // 申請者は名前の一部（空白で分けた語のどれも含む。D21）。登録名の半角・全角の空白は無視して当てる（「申請 花子」を「申請花子」で探せる）。
        // 退職して消した人の申請も探せる（applicant は withTrashed）
        // （⑦ 利用者の管理の検索と同じ部品。段階5 D25）
        $names = NameSearch::terms($filter->applicant);
        if ($names !== []) {
            $query->whereHas('applicant', fn (Builder $q) => NameSearch::whereNameHasAll($q, $names));
        }

        $words = LedgerFilter::terms($filter->keyword);
        if ($filter->year !== null || $filter->departmentId !== null || $filter->typeId !== null || $words !== []) {
            $periods = $filter->year === null ? [] : self::periods($filter->year);
            self::whereContent($query, $viewer, function (Builder|QueryBuilder $q, array $column) use ($filter, $words, $periods): void {
                if ($filter->year !== null) {
                    self::whereYear($q, $filter->year, $periods, $column['department']);
                }
                if ($filter->departmentId !== null) {
                    $q->where($column['department'], $filter->departmentId);
                }
                if ($filter->typeId !== null) {
                    $q->where($column['type'], $filter->typeId);
                }
                // キーワードは件名か本文（空白で分けた語のどれも含む）。LIKE の % と _ は逃がさない（アプリのほかの検索と同じ）
                foreach ($words as $word) {
                    $q->where(fn (Builder|QueryBuilder $q) => $q->where($column['subject'], 'like', "%{$word}%")->orWhere($column['body'], 'like', "%{$word}%"));
                }
            });
        }

        return $query;
    }

    /**
     * 並べた id（D22）。決裁日（番号の無い申請は発信日）の日本の暦の日の新しい順 → 同じ日は決裁No のあるものを
     * 部門のアルファベットと連番の順 → 番号の無いものは発信の新しい順 → id の新しい順
     *
     * @return list<int>
     */
    public static function sortedIds(User $viewer, LedgerFilter $filter): array
    {
        $codes = ApprovalDepartment::query()->pluck('code', 'id');
        $keys  = self::query($viewer, $filter)->toBase()
            ->get(['approval_requests.id', 'approval_requests.number', 'approval_requests.number_department_id', 'approval_requests.number_seq', 'approval_requests.decided_at', 'approval_requests.last_submitted_at'])
            ->map(fn (object $r) => [
                'id'         => (int) $r->id,
                'day'        => self::japanDay($r->decided_at ?? $r->last_submitted_at),
                'unnumbered' => $r->number === null ? 1 : 0,
                'code'       => (string) ($codes[$r->number_department_id] ?? ''),
                'seq'        => (int) $r->number_seq,
                'submitted'  => (string) $r->last_submitted_at,
            ])
            ->all();

        usort($keys, fn (array $a, array $b) => [$b['day'], $a['unnumbered'], $a['code'], $a['seq'], $b['submitted'], $b['id']]
            <=> [$a['day'], $b['unnumbered'], $b['code'], $b['seq'], $a['submitted'], $a['id']]);

        return array_column($keys, 'id');
    }

    /**
     * id の並びのとおりに行を作る（行に要る関係を先にまとめて読む）
     *
     * @param list<int> $ids
     * @return Collection<int, LedgerRow>
     */
    public static function rows(User $viewer, array $ids): Collection
    {
        $position = array_flip($ids);

        return ApprovalRequest::with(self::WITH)->whereKey($ids)->get()
            ->sortBy(fn (ApprovalRequest $r) => $position[$r->id])
            ->map(fn (ApprovalRequest $r) => LedgerRow::for($viewer, $r))
            ->values();
    }

    /**
     * 年度の選択肢（新しい順。今の年度から、いちばん古い申請の年度まで。値は期の始まりの年）
     *
     * @return array<int, string> 年度 => 表示（「R8 年度（2026）」）
     */
    public static function years(): array
    {
        $months = ApprovalCompany::query()->pluck('fiscal_start_month')->unique()->values();
        if ($months->isEmpty()) {
            return [];
        }

        // 今の年度は会社で違うことがある（5 月はミツワだけ新しい年度）ので、いちばん新しいもの。古い方は番号の年度と発信日の年度
        $newest   = $months->map(fn (mixed $m) => ApprovalFiscalYear::current((int) $m))->max();
        $oldest   = $newest;
        $numbered = ApprovalRequest::query()->min('number_fiscal_year');
        if ($numbered !== null) {
            $oldest = min($oldest, (int) $numbered);
        }
        $submitted = ApprovalRequest::query()->min('last_submitted_at');
        if ($submitted !== null) {
            $oldest = min($oldest, ApprovalFiscalYear::ofMoment(CarbonImmutable::parse($submitted, 'UTC'), (int) $months->max()));
        }

        $years = [];
        for ($year = $newest; $year >= $oldest; $year--) {
            $years[$year] = ApprovalFiscalYear::eraLabel($year, (int) $months->min()) . " 年度（{$year}）";
        }

        return $years;
    }

    private static function whereStatus(Builder $query, string $status): void
    {
        $values = fn (array $statuses) => array_map(fn (ApprovalStatus $s) => $s->value, $statuses);

        match ($status) {
            'numbered'  => $query->where(fn (Builder $q) => $q
                ->whereIn('approval_requests.status', $values(self::DECIDED))
                ->orWhere(fn (Builder $q) => $q->where('approval_requests.status', ApprovalStatus::Withdrawn->value)->whereNotNull('approval_requests.number'))),
            'progress'  => $query->whereIn('approval_requests.status', $values(self::IN_PROGRESS)),
            'withdrawn' => $query->where('approval_requests.status', ApprovalStatus::Withdrawn->value),
            default     => null,
        };
    }

    /**
     * 中身で絞る（D19）。申請者本人の申請と、直しかけの残らない状態の申請は申請の行で、他人の差戻し中・取り下げの申請は
     * 最後に提出した控えで当てる
     *
     * @param Closure(Builder|QueryBuilder, array{department: string, type: string, subject: string, body: string}): void $where
     */
    private static function whereContent(Builder $query, User $viewer, Closure $where): void
    {
        // 直しかけが今の中身に残りうる状態（他人の申請はこの状態だけ控えで当てる。D19）
        $fromRevision = array_map(fn (ApprovalStatus $s) => $s->value, ApprovalStatus::mayDifferFromSubmission());

        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('approval_requests.user_id', $viewer->id)->orWhereNotIn('approval_requests.status', $fromRevision))
                ->where(fn (Builder $q) => $where($q, self::CURRENT)))
            ->orWhere(fn (Builder $q) => $q
                ->where('approval_requests.user_id', '!=', $viewer->id)
                ->whereIn('approval_requests.status', $fromRevision)
                ->whereExists(function (QueryBuilder $s) use ($where): void {
                    $s->selectRaw('1')->from('approval_revisions')
                        ->whereColumn('approval_revisions.request_id', 'approval_requests.id')
                        ->whereColumn('approval_revisions.round', 'approval_requests.round');
                    $where($s, self::SUBMITTED);
                })));
    }

    /**
     * その年度の期間（会社の期の始まりの月ごと。日本時間の期の始まりから次の期の始まりの前まで）と、その期の部門
     *
     * @return list<array{departments: list<int>, from: CarbonImmutable, until: CarbonImmutable}>
     */
    private static function periods(int $year): array
    {
        return ApprovalDepartment::query()->join('approval_companies', 'approval_companies.id', '=', 'approval_departments.company_id')
            ->get(['approval_departments.id', 'approval_companies.fiscal_start_month'])
            ->groupBy('fiscal_start_month')
            ->map(function (Collection $departments, int|string $startMonth) use ($year): array {
                $start = CarbonImmutable::create($year, (int) $startMonth, 1, 0, 0, 0, JapanTime::ZONE);

                return ['departments' => $departments->pluck('id')->all(), 'from' => $start->utc(), 'until' => $start->addYear()->utc()];
            })
            ->values()
            ->all();
    }

    /**
     * 年度（D20）。決裁No の付いた申請はその番号の年度、付いていない申請は発信日（最後の提出）の年度。どちらも申請部門の会社の期
     *
     * @param list<array{departments: list<int>, from: CarbonImmutable, until: CarbonImmutable}> $periods
     */
    private static function whereYear(Builder|QueryBuilder $q, int $year, array $periods, string $departmentColumn): void
    {
        $q->where(fn (Builder|QueryBuilder $q) => $q
            ->where('approval_requests.number_fiscal_year', $year)
            ->orWhere(fn (Builder|QueryBuilder $q) => $q
                ->whereNull('approval_requests.number')
                ->where(function (Builder|QueryBuilder $q) use ($periods, $departmentColumn): void {
                    // 部門が 1 つも無いときは何にも当てない（空の括弧は Laravel が落とし、番号の無い申請すべてに当たるため）
                    $q->whereRaw('1 = 0');
                    foreach ($periods as $period) {
                        $q->orWhere(fn (Builder|QueryBuilder $q) => $q
                            ->whereIn($departmentColumn, $period['departments'])
                            ->where('approval_requests.last_submitted_at', '>=', $period['from'])
                            ->where('approval_requests.last_submitted_at', '<', $period['until']));
                    }
                })));
    }

    /** 保存した日時（UTC の文字列）の日本の暦の日 */
    private static function japanDay(?string $at): string
    {
        return $at === null ? '' : CarbonImmutable::parse($at, 'UTC')->setTimezone(JapanTime::ZONE)->format('Y-m-d');
    }
}
