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
        // ⚠ 引数の $request ではなくコンテナの 'request' を見る（PaginationServiceProvider が
        //   そう束ねている）。本番経路では同一インスタンスなので実害は無いが、テストでは
        //   呼び出し側が $this->app->instance('request', $request) で明示的に揃える必要がある。
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
        })
            // ⚠ ここで消えるのは「キー取得後・モデル取得前に削除された」極めて稀な一過性の行のみ。
            //   その場合 total()（$keys->count() 由来）と実件数がわずかにズレ得るが許容する。
            ->filter()
            ->values();

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
            // 完全同着時の確定タイブレーク（仕入れ案件を先に）。
            // ⚠ 現状は merge 順（procKeys が先）＋ PHP 8 の安定ソートでも同じ順序になるため、
            //   この要素を消しても挙動は変わらない。それでも残すのは、消すと正しさが
            //   「merge の引数順」「PHP のソート安定性」「Laravel が sortBy で arsort を使うこと」
            //   という 3 つの暗黙の前提（うち 1 つは vendor 側）に同時に依存するようになるため。
            //   この要素があれば、そのどれが変わっても順序は変わらない（変異試験で確認済み）。
            $k['kind'] === ProcurementListRow::KIND_PROCUREMENT ? 1 : 0,
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

        $statusFilter = $this->statusFilter($request);
        if ($statusFilter === self::STATUS_NO_MATCH) {
            return null;   // 想定外の型。該当なし
        }

        $query = ReProcurement::query();

        if ($statusFilter === 'active') {
            $query->whereNotIn('status', [
                ProcurementStatus::Lost->value,
                ProcurementStatus::Sold->value,
            ]);
        } elseif ($statusFilter !== null) {
            // null は「全て」＝フィルタ無し
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
     * ステータス絞り込みの生値を正規化する。
     *
     * 戻り値:
     *   'active'         — 既定（進行中のみ）
     *   null             — 全て（?status= 。ConvertEmptyStringsToNull で null 化されて届く）
     *   STATUS_NO_MATCH  — 想定外の型（?status[]=... の配列など）。両種別とも該当なしにする
     *   その他            — status 列へ直接当てる文字列
     *
     * ⚠ 配列をそのまま enum の tryFrom() へ渡すと TypeError で 500 になる（実測確認済み）。
     *   未知の文字列（?status=zzz）が 0 件になる既存挙動に合わせ、配列も 0 件へ落とす。
     */
    private function statusFilter(Request $request): ?string
    {
        $status = $request->input('status', 'active');

        // ConvertEmptyStringsToNull ミドルウェアにより、実 HTTP 経由の ?status= は
        // 空文字ではなく null で届く（status キー自体は存在するため既定値 'active' にはならない）。
        // これは「全て」を意味するので、is_string() 判定より先に拾い、配列など
        // 想定外の型（STATUS_NO_MATCH 行き）と混同しないようにする。
        if ($status === null) {
            return null;
        }

        if (! is_string($status)) {
            return self::STATUS_NO_MATCH;
        }

        return filled($status) ? $status : null;
    }

    /**
     * 仕入れ案件のステータス値を分譲地のステータス値へ写す。
     *
     * 戻り値の意味は statusFilter() と同じ 4 通り。
     * ⚠ null（全て＝全件）と STATUS_NO_MATCH（該当なし＝0 件）は**正反対**の結果を指す。
     *   呼び出し側で `if (! $status)` のように緩く畳まないこと。
     */
    private function mapStatusForProject(Request $request): ?string
    {
        $statusFilter = $this->statusFilter($request);

        // 既定 / 全て / 想定外の型 はそのまま素通し
        if ($statusFilter === 'active' || $statusFilter === null || $statusFilter === self::STATUS_NO_MATCH) {
            return $statusFilter;
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
