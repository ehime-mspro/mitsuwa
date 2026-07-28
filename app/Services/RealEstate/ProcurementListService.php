<?php

namespace App\Services\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\ProjectStatus;
use App\Models\ReProcurement;
use App\Models\ReProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * 仕入れ案件（re_procurements）と分譲地（re_projects）を 1 本の一覧にマージする。
 *
 * 2 段構え:
 *   第1段 — 両テーブルからフィルタ適用済みの id と info_obtained_date だけを引き、
 *            PHP 側でマージ・ソートして「並び順キー」を作る
 *   第2段 — 現在ページの 20 件分だけモデルを読み込み、DTO へ変換する
 *
 * 全件をモデルとして読み込むと costs / lots のイーガーロードが全行分走るため。
 * 行数が数千を超えるようなら SQL UNION へ移す余地があるが、現在の規模
 * （本番でも数十〜百程度）では過剰。
 */
class ProcurementListService
{
    /**
     * 一覧フィルタ専用の物件種別 擬似値。
     *
     * ⚠ RealEstatePropertyType enum には追加しないこと。
     *   同じ enum を仕入れ案件の登録フォーム（_form.blade.php）と
     *   ProcurementController::validateProcurement() が参照しており、
     *   追加すると「物件種別＝分譲地の仕入れ案件」が作れてしまう。
     */
    public const PROPERTY_TYPE_PROJECT = 'project';

    /** 分譲地に該当ステータスが存在しないことを表す番兵 */
    private const STATUS_NO_MATCH = '__no_match__';

    public function paginate(Request $request, int $perPage = 20): LengthAwarePaginator
    {
        $keys  = $this->sortedKeys($request);
        $page  = LengthAwarePaginator::resolveCurrentPage();
        $slice = $keys->forPage($page, $perPage);

        // 現在ページ分だけモデルを読む。costs は getExpectedProfit()、
        // lots は区画数が使うのでイーガーロード必須（無いと N+1）
        $procs = ReProcurement::with('costs')
            ->whereIn('id', $slice->where('kind', ProcurementListRow::KIND_PROCUREMENT)->pluck('id'))
            ->get()
            ->keyBy('id');

        $projs = ReProject::with('costs', 'lots')
            ->whereIn('id', $slice->where('kind', ProcurementListRow::KIND_PROJECT)->pluck('id'))
            ->get()
            ->keyBy('id');

        $rows = $slice->map(function (array $k) use ($procs, $projs): ?ProcurementListRow {
            if ($k['kind'] === ProcurementListRow::KIND_PROCUREMENT) {
                $model = $procs->get($k['id']);

                return $model ? ProcurementListRow::fromProcurement($model) : null;
            }

            $model = $projs->get($k['id']);

            return $model ? ProcurementListRow::fromProject($model) : null;
        })->filter()->values();

        return new LengthAwarePaginator($rows, $keys->count(), $perPage, $page, [
            'path'  => Paginator::resolveCurrentPath(),
            'query' => $this->paginationQuery($request),
        ]);
    }

    /**
     * ページ送りリンクに載せるクエリ。
     *
     * ⚠ ConvertEmptyStringsToNull により ?status= は null で届く。null のまま渡すと
     *   Arr::query()（= http_build_query）が null のキーを丸ごと捨てるため、
     *   2 ページ目で「ステータス: 全て」が既定の「進行中のみ」へ戻ってしまう（実測確認済み）。
     *   空文字へ戻して保持する。
     *   page キーは AbstractPaginator::url() 側で array_merge の後勝ちにより
     *   正しいページ番号に上書きされるので、除去しなくてよい（実測確認済み）。
     *
     * @return array<string, mixed>
     */
    private function paginationQuery(Request $request): array
    {
        return array_map(fn ($v) => $v ?? '', $request->query());
    }

    /**
     * 両テーブルの並び順キーをマージしてソートする。
     *
     * 並び順: 情報入手日 降順（NULL 末尾）→ id 降順 → 種別（仕入れ案件が先）
     * 種別を最終タイブレークに入れるのは、日付も id も一致する行が別テーブル間では
     * 起こりうるため（ページ間で行が重複・欠落しないよう順序を確定させる）。
     *
     * @return Collection<int, array{kind: string, id: int, date: \Illuminate\Support\Carbon|null}>
     */
    private function sortedKeys(Request $request): Collection
    {
        $procKeys = $this->keysFrom(
            $this->procurementQuery($request),
            ProcurementListRow::KIND_PROCUREMENT
        );
        $projKeys = $this->keysFrom(
            $this->projectQuery($request),
            ProcurementListRow::KIND_PROJECT
        );

        return $procKeys->merge($projKeys)->sortByDesc(fn (array $k) => [
            $k['date'] === null ? 0 : 1,                                  // NULL を末尾へ
            $k['date']?->getTimestamp() ?? 0,                             // 情報入手日 降順
            $k['id'],                                                     // id 降順
            $k['kind'] === ProcurementListRow::KIND_PROCUREMENT ? 1 : 0,  // 完全同着時の確定タイブレーク
        ])->values();
    }

    /**
     * @param  Builder<ReProcurement>|Builder<ReProject>|null  $query
     * @return Collection<int, array{kind: string, id: int, date: \Illuminate\Support\Carbon|null}>
     */
    private function keysFrom(?Builder $query, string $kind): Collection
    {
        if ($query === null) {
            return collect();   // その種別は該当なし
        }

        return $query->get(['id', 'info_obtained_date'])
            ->map(fn ($r) => [
                'kind' => $kind,
                'id'   => (int) $r->id,
                'date' => $r->info_obtained_date,
            ])
            // ⚠ toBase() は必須。Eloquent\Collection::map() は contains() で base 化を
            //   判定するため、空コレクションでは Eloquent\Collection のまま残る
            //   （Laravel 12.55.0 の Eloquent/Collection.php:423 で実測確認）。
            //   そこへ配列要素のコレクションを merge すると getKey() が呼ばれて 500
            //   （docs/RULES.md Bug #27 と同型）。片方 0 件は実際に起こる。
            ->toBase();
    }

    /**
     * 仕入れ案件側のクエリ。該当し得ないフィルタのときは null を返す
     * （whereRaw('1 = 0') のような打ち消し条件より「該当なし」が型で読める）。
     *
     * @return Builder<ReProcurement>|null
     */
    private function procurementQuery(Request $request): ?Builder
    {
        // 物件種別に擬似値「分譲地」が選ばれていたら仕入れ案件は該当なし
        if ($request->input('property_type') === self::PROPERTY_TYPE_PROJECT) {
            return null;
        }

        $query = ReProcurement::query();

        $statusFilter = $request->input('status', 'active');
        if ($statusFilter === 'active') {
            $query->whereNotIn('status', [
                ProcurementStatus::Lost->value,
                ProcurementStatus::Sold->value,
            ]);
        } elseif (filled($statusFilter)) {
            // 「全て」= ?status= は ConvertEmptyStringsToNull で null 化されるため
            // filled() で弾き、フィルタ無し（＝全件）に落とす。'' 比較では null が
            // 素通りして where('status', null) となり 0 件になる
            $query->where('status', $statusFilter);
        }

        if ($request->filled('property_type')) {
            $query->where('property_type', $request->input('property_type'));
        }

        if ($request->filled('transaction_type')) {
            $query->where('transaction_type', $request->input('transaction_type'));
        }

        $this->applyKeyword($query, $request, ['procurement_code', 'property_name', 'address']);

        return $query;
    }

    /**
     * 分譲地側のクエリ。該当し得ないフィルタのときは null を返す。
     *
     * @return Builder<ReProject>|null
     */
    private function projectQuery(Request $request): ?Builder
    {
        $propertyType = $request->input('property_type');

        // 実在の物件種別で絞られていたら分譲地は該当なし（分譲地は擬似値 'project' のみ）
        if (filled($propertyType) && $propertyType !== self::PROPERTY_TYPE_PROJECT) {
            return null;
        }

        // 取引種別は分譲地にカラム自体が無いので、指定されたら該当なし
        if ($request->filled('transaction_type')) {
            return null;
        }

        $status = $this->mapStatusForProject($request);
        if ($status === self::STATUS_NO_MATCH) {
            return null;   // 「現地調査」など分譲地に存在しないステータス
        }

        $query = ReProject::query();

        if ($status === 'active') {
            $query->whereNotIn('status', [
                ProjectStatus::Lost->value,
                ProjectStatus::SoldOut->value,
            ]);
        } elseif ($status !== null) {
            $query->where('status', $status);
        }

        $this->applyKeyword($query, $request, ['project_code', 'project_name', 'address']);

        return $query;
    }

    /**
     * 仕入れ案件のステータス値を分譲地のステータス値へ写す。
     *
     * 戻り値:
     *   'active'         — 既定（進行中のみ）
     *   null             — 全て（フィルタ無し）
     *   STATUS_NO_MATCH  — 分譲地に該当なし（＝分譲地を結果から外す）
     *   その他            — re_projects.status に直接当てる値
     */
    private function mapStatusForProject(Request $request): ?string
    {
        $statusFilter = $request->input('status', 'active');

        if ($statusFilter === 'active') {
            return 'active';
        }

        if (! filled($statusFilter)) {
            return null;   // 「全て」
        }

        // 「販売済」だけラベルが同じで値が違う: 仕入れ sold ⇔ 分譲地 sold_out
        if ($statusFilter === ProcurementStatus::Sold->value) {
            return ProjectStatus::SoldOut->value;
        }

        // 同名で存在するものはそのまま。存在しない（site_survey）は該当なし。
        // ⚠ ここの tryFrom() はリクエストの生文字列に対する変換なので正しい用法。
        //   キャスト済み属性へ渡すと TypeError になる（Bug #22）のとは別物。
        return ProjectStatus::tryFrom($statusFilter)?->value ?? self::STATUS_NO_MATCH;
    }

    /**
     * @param  Builder<ReProcurement>|Builder<ReProject>  $query
     * @param  array<int, string>  $columns
     */
    private function applyKeyword(Builder $query, Request $request, array $columns): void
    {
        if (! $request->filled('keyword')) {
            return;
        }

        $keyword = $request->input('keyword');

        $query->where(function (Builder $q) use ($keyword, $columns): void {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', "%{$keyword}%");
            }
        });
    }
}
