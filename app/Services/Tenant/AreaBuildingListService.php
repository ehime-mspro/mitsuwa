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
 * ⚠ 絞り込み後の全件を一度メモリに載せる。**実用上は数万棟まで問題ない**
 *   （2026-08-16 コード品質レビューで実測較正。当初の docblock は「数千を超えたら見直す」と
 *   安全側に振りすぎていた）:
 *
 *   | 棟数    | 応答時間  | メモリ増分 |
 *   |---------|-----------|-----------|
 *   | 300     | 44ms      | +4.0MB    |
 *   | 1,000   | 56ms      | +6.0MB    |
 *   | 5,000   | 147ms     | +20.0MB   |
 *   | 20,000  | 509ms     | +72.0MB   |
 *   | 50,000  | 1,275ms   | +176.5MB  |
 *
 *   増分はほぼ線形（1 棟あたり約 3.5〜4KB / 約 25μs）。5,000 棟でも 147ms で全く問題なく、
 *   体感できる遅さは 20,000 棟付近から、明確に問題化するのは 50,000 棟付近。
 *   ⚠ 計測は SQLite（in-memory）で行ったため絶対値は参考値だが、インデックス構造は
 *   本番 MySQL と同一（`uk_area_survey_building_month`）なので傾向は転用できる。
 *   ⚠ さらに、この一覧は手作業の現地調査で作られるデータで、現状の Excel 台帳でも
 *   50 棟超程度。数万棟という規模自体が現実の運用では起こらない。
 *   見直すとしても、まずはこの想定を覆すだけの実データが増えてから検討すればよい。
 */
class AreaBuildingListService
{
    public const VACANCY_FULL   = 'full';
    public const VACANCY_ANY    = 'any';
    public const VACANCY_OVER25 = 'over25';
    public const VACANCY_OVER50 = 'over50';

    /**
     * フィルタバーの選択肢(「全て」は空値なのでここには入れない)。
     *
     * ⚠ ラベルの数字は VacancyRate::BAND_MID / BAND_HIGH と揃えること。
     *   閾値を動かすときは両方直す(表示だけ 25% で判定が 20% は最悪の状態)。
     */
    public const VACANCY_OPTIONS = [
        self::VACANCY_FULL   => '満室（0%）',
        self::VACANCY_ANY    => '空きあり（1%以上）',
        self::VACANCY_OVER25 => '空室率 25% 以上',
        self::VACANCY_OVER50 => '空室率 50% 以上',
    ];

    /**
     * 組み立て済みの行をページャに載せる。
     *
     * ⚠ 呼び出し側で `rows()` を先に呼ぶ形にしてある。地図タブは全件（rows）と
     *   ページャの両方を使うので、サービス側で `rows()` を呼ぶと
     *   1 リクエストで 2 回走ってしまう。
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function paginateRows(Collection $rows, Request $request, int $perPage = 20): LengthAwarePaginator
    {
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

    /**
     * フィルタバーの「調査年」選択肢(降順)。
     *
     * ⚠ 論理削除済みのビルの調査年を含めない(設計 §5.3 / Task 6 レビューで発見)。
     *   AreaBuildingSurvey に SoftDeletes は無いので、ビルを消しても調査回の行は残る。
     *   ⚠ `->whereHas('building')` では効かない(2026-08-17 実測)。
     *   `AreaBuildingSurvey::building()` は withTrashed() 付きのリレーションなので、
     *   whereHas の EXISTS サブクエリもそのリレーション定義をそのまま使い、
     *   SoftDeletingScope が掛からない=論理削除済みでも存在ありと判定されてしまう。
     *   代わりに AreaBuilding 側(SoftDeletes スコープが自然に効く)から生存 ID を引き、
     *   whereIn で絞る。
     */
    public function surveyYears(): array
    {
        return AreaBuildingSurvey::query()
            ->whereIn('area_building_id', AreaBuilding::query()->select('id'))
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
                // ⚠ 所在地は画面に出していないので検索対象にもしない（設計書 §6.1）
                $q->where('area_buildings.name', 'like', $like)
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
            // ⚠ 直値を書かない。地図の凡例と別々に閾値を持つと片方だけ直す事故が起きる(Bug #41)
            self::VACANCY_OVER25 => $rate >= VacancyRate::BAND_MID,
            self::VACANCY_OVER50 => $rate >= VacancyRate::BAND_HIGH,
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
