<?php

namespace App\Services\Tenant;

use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Support\VacancyRate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 周辺ビル一覧のクエリ組み立て。
 *
 * 2 層に分かれている:
 *   SQL … 最新調査回の 4 列を相関サブクエリで引き、キーワード検索で絞る
 *   PHP … 空室率の算出・空室率フィルタ・調査年フィルタ・並び替え・ページ切り出し
 *
 * ⚠ 率を SQL 側で計算しないこと。VacancyRate と二重実装になり（Bug #41）、
 *   さらに MySQL の `/` は小数を返すのに SQLite の `/` は整数除算なので値が食い違う。
 *
 * ⚠ 絞り込み後の全件を一度メモリに載せる。本番の想定棟数は数十〜数百なので問題ないが、
 *   数千を超えるようなら SQL 側の並び替えへ移す（そのときは率の算出も 1 箇所に保つ工夫が要る）。
 */
class AreaBuildingListService
{
    public const VACANCY_FULL   = 'full';
    public const VACANCY_ANY    = 'any';
    public const VACANCY_OVER20 = 'over20';
    public const VACANCY_OVER40 = 'over40';

    /** フィルタバーの選択肢(「全て」は空値なのでここには入れない) */
    public const VACANCY_OPTIONS = [
        self::VACANCY_FULL   => '満室（0%）',
        self::VACANCY_ANY    => '空きあり（1%以上）',
        self::VACANCY_OVER20 => '空室率 20% 以上',
        self::VACANCY_OVER40 => '空室率 40% 以上',
    ];

    public function paginate(Request $request, int $perPage = 20): LengthAwarePaginator
    {
        $rows = $this->rows($request);
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                // ⚠ withQueryString() ではなくこの形にする。null 値のキーは
                //   http_build_query が丸ごと捨てるため（Bug #31）。
                'query' => array_map(fn ($v) => $v ?? '', $request->query()),
            ]
        );
    }

    /**
     * 絞り込み・並び替え済みの行。
     *
     * @return Collection<int, array{building: AreaBuilding, month: ?Carbon, operating: ?int, vacant: ?int, unknown: ?int, rate: ?float, rate_label: string}>
     */
    public function rows(Request $request): Collection
    {
        $vacancy = $request->input('vacancy');
        $year    = $request->input('year');

        return $this->baseQuery($request)
            ->get()
            ->map(fn (AreaBuilding $building) => $this->toRow($building))
            ->filter(fn (array $row) => $this->matchesYear($row, $year) && $this->matchesVacancy($row, $vacancy))
            ->sortByDesc(fn (array $row) => [
                $row['rate'] === null ? 0 : 1,   // 未調査を末尾へ
                $row['rate'] ?? 0.0,             // 空室率 降順
            ])
            ->values();
    }

    /** フィルタバーの「調査年」選択肢(降順) */
    public function surveyYears(): array
    {
        return AreaBuildingSurvey::query()
            ->orderByDesc('surveyed_month')
            ->pluck('surveyed_month')
            ->map(fn ($month) => (int) Carbon::parse($month)->format('Y'))
            ->unique()
            ->values()
            ->all();
    }

    private function baseQuery(Request $request): Builder
    {
        // 最新 1 件だけを引く相関サブクエリ。with('surveys') で全件ロードすると
        // 棟数 × 調査回数を毎回引くことになる(設計 §5.3 の N+1 対策)。
        $latest = fn (string $column) => AreaBuildingSurvey::query()
            ->select($column)
            ->whereColumn('area_building_surveys.area_building_id', 'area_buildings.id')
            ->orderByDesc('surveyed_month')
            ->orderByDesc('id')
            ->limit(1);

        $query = AreaBuilding::query()
            ->select('area_buildings.*')
            ->addSelect([
                'latest_month'     => $latest('surveyed_month'),
                'latest_operating' => $latest('operating_count'),
                'latest_vacant'    => $latest('vacant_count'),
                'latest_unknown'   => $latest('unknown_count'),
            ]);

        $keyword = $request->input('keyword');
        // ⚠ `?keyword[]=x` のように配列で来ると "%{$keyword}%" が
        //   ErrorException: Array to string conversion で 500 になる（実測確認済み）。
        //   ProcurementListService::applyKeyword() と同じ防御（想定外の型は絞り込み無しへ）。
        if (filled($keyword) && is_string($keyword)) {
            $like = '%' . $keyword . '%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('area_buildings.name', 'like', $like)
                    ->orWhere('area_buildings.address', 'like', $like)
                    // 現況の行だけ。退去済みまで拾うと「もう居ない会社」でヒットする
                    ->orWhereHas('tenants', fn ($t) => $t->whereNull('moved_out_on')->where('name', 'like', $like));
            });
        }

        // 空室率が同じ行のタイブレーク(PHP の sort は 8.0 以降 stable なのでこの順が残る)
        return $query->orderBy('area_buildings.name')->orderBy('area_buildings.id');
    }

    /** @return array{building: AreaBuilding, month: ?Carbon, operating: ?int, vacant: ?int, unknown: ?int, rate: ?float, rate_label: string} */
    private function toRow(AreaBuilding $building): array
    {
        $hasSurvey = $building->latest_month !== null;

        $operating = $hasSurvey ? (int) $building->latest_operating : null;
        $vacant    = $hasSurvey ? (int) $building->latest_vacant : null;
        $unknown   = $hasSurvey ? (int) $building->latest_unknown : null;

        return [
            'building'   => $building,
            'month'      => $hasSurvey ? Carbon::parse($building->latest_month) : null,
            'operating'  => $operating,
            'vacant'     => $vacant,
            'unknown'    => $unknown,
            'rate'       => $hasSurvey ? VacancyRate::percent($operating, $vacant, $unknown) : null,
            'rate_label' => $hasSurvey ? VacancyRate::label($operating, $vacant, $unknown) : '—',
        ];
    }

    private function matchesVacancy(array $row, mixed $vacancy): bool
    {
        // ⚠ 型ガードより先に null を「全て」として返す。ConvertEmptyStringsToNull により
        //   ?vacancy= は実 HTTP では null で届く(Request::create() では '' のまま。Bug #31)。
        if ($vacancy === null || $vacancy === '') {
            return true;
        }

        if (! is_string($vacancy) || ! array_key_exists($vacancy, self::VACANCY_OPTIONS)) {
            return true;
        }

        $rate = $row['rate'];
        if ($rate === null) {
            return false;   // 未調査は率で絞ると対象外
        }

        return match ($vacancy) {
            self::VACANCY_FULL   => $rate <= 0.0,
            self::VACANCY_ANY    => $rate > 0.0,
            self::VACANCY_OVER20 => $rate >= 20.0,
            self::VACANCY_OVER40 => $rate >= 40.0,
        };
    }

    private function matchesYear(array $row, mixed $year): bool
    {
        if ($year === null || $year === '') {
            return true;
        }

        if (! is_numeric($year)) {
            return true;
        }

        return $row['month'] !== null && (int) $row['month']->format('Y') === (int) $year;
    }
}
