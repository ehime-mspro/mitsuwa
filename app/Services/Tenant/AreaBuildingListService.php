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
 *   PHP … 空室率／入居率の算出・入居率フィルタ・調査年フィルタ・並び替え・ページ切り出し
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
    public const OCCUPANCY_FULL    = 'full';
    public const OCCUPANCY_ANY     = 'any';
    public const OCCUPANCY_UNDER75 = 'under75';
    public const OCCUPANCY_UNDER50 = 'under50';

    /**
     * フィルタバーの選択肢(「全て」は空値なのでここには入れない)。
     *
     * ⚠ **判定の中身は空室率のまま**（VacancyRate::BAND_MID / BAND_HIGH）で、
     *   同じ帯を入居率の側から言い直しているだけ。閾値は 1 ミリも動いていない。
     *   ラベルの数字は BAND_MID / BAND_HIGH の裏返しと揃えること
     *   (表示だけ 75% で判定が 80% は最悪の状態)。
     */
    public const OCCUPANCY_OPTIONS = [
        self::OCCUPANCY_FULL    => '満室（100%）',
        self::OCCUPANCY_ANY     => '空きあり（99% 以下）',
        self::OCCUPANCY_UNDER75 => '入居率 75% 以下',
        self::OCCUPANCY_UNDER50 => '入居率 50% 以下',
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
     * 絞り込み済みの行（既定順 ＝ ビル名の昇順）。
     *
     * ⚠ **PHP 側で並べ替えない。** baseQuery() が
     *   `ORDER BY area_buildings.name, area_buildings.id` で引いており、
     *   map() / filter() は順序を保つので、**ここに並べ替えを書かないことがビル名の昇順**
     *   （設計書 2026-08-28 §4.4。利用者の依頼で従来の「空室率の降順」から変更）。
     *   従来の順は「空室率」の見出しを 1 回押せば戻る。
     *
     * @return Collection<int, array{building: AreaBuilding, month: ?Carbon, operating: ?int, vacant: ?int, unknown: ?int, rate: ?float, occupancy_label: string, rate_label: string}>
     */
    public function rows(Request $request): Collection
    {
        $occupancy = $request->input('occupancy');
        $year      = $request->input('year');

        return $this->baseQuery($request)
            ->get()
            ->map(fn (AreaBuilding $building) => $this->toRow($building))
            ->filter(fn (array $row) => $this->matchesYear($row, $year) && $this->matchesOccupancy($row, $occupancy))
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

        // ⚠ **これが既定順そのもの**（設計書 §4.4）。PHP 側は並べ替えないのでこの順が残る。
        //   並び替え中は同点のタイブレークとしても効く（PHP のソートは 8.0 以降 stable）。
        return $query->orderBy('area_buildings.name')->orderBy('area_buildings.id');
    }

    /** @return array{building: AreaBuilding, month: ?Carbon, operating: ?int, vacant: ?int, unknown: ?int, rate: ?float, occupancy_label: string, rate_label: string} */
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
            'rate'            => $hasSurvey ? VacancyRate::percent($operating, $vacant, $unknown) : null,
            // ⚠ 「営業 ÷ 総数」で独立に出さない。並べたとき和が 100.0% にならない（Bug #46）
            'occupancy_label' => $hasSurvey ? VacancyRate::occupancyLabel($operating, $vacant, $unknown) : '—',
            'rate_label'      => $hasSurvey ? VacancyRate::label($operating, $vacant, $unknown) : '—',
        ];
    }

    /**
     * 入居率での絞り込み。
     *
     * ⚠ **判定は空室率（$row['rate']）のまま**で、同じ境界を入居率の言い方に置き換えただけ。
     *   入居率 = 100 − 空室率 なので「入居率 75% 以下」＝「空室率 25% 以上」で完全に等価。
     *   入居率を別に計算して比べる形にすると、丸めの向きの違いで境界の 1 行がズレる。
     */
    private function matchesOccupancy(array $row, mixed $occupancy): bool
    {
        // ⚠ 型ガードより先に null を「全て」として返す。ConvertEmptyStringsToNull により
        //   ?occupancy= は実 HTTP では null で届く(Request::create() では '' のまま。Bug #31)。
        if ($occupancy === null || $occupancy === '') {
            return true;
        }

        if (! is_string($occupancy) || ! array_key_exists($occupancy, self::OCCUPANCY_OPTIONS)) {
            return true;
        }

        $rate = $row['rate'];
        if ($rate === null) {
            return false;   // 未調査は率で絞ると対象外
        }

        return match ($occupancy) {
            self::OCCUPANCY_FULL    => $rate <= 0.0,
            self::OCCUPANCY_ANY     => $rate > 0.0,
            // ⚠ 直値を書かない。地図の凡例と別々に閾値を持つと片方だけ直す事故が起きる(Bug #41)
            self::OCCUPANCY_UNDER75 => $rate >= VacancyRate::BAND_MID,
            self::OCCUPANCY_UNDER50 => $rate >= VacancyRate::BAND_HIGH,
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
