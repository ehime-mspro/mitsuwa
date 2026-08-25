<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ContractStatus;
use App\Enums\DepartmentCode;
use App\Enums\OperationStatus;
use App\Enums\OwnerType;
use App\Enums\PropertyType;
use App\Http\Controllers\Controller;
use App\Models\Investment;
use App\Models\Property;
use App\Models\PropertyChangeLog;
use App\Models\Repair;
use App\Models\StructureType;
use App\Services\Tenant\RentalIncomeService;
use App\Support\ListSort;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class PropertyController extends Controller
{
    /**
     * 物件一覧で並び替えを許す列 → calculatePropertyStats() が入れる属性の名前。
     *
     * ⚠ **許可リストはここ 1 箇所だけ。** ListSort::fromRequest() には array_keys() を渡す。
     *   三項演算子で書くと、キーを足したときに**黙って賃料収入で並んでしまう**
     *   （落ちないので画面から気づけない）。
     */
    private const SORTABLE = [
        'occupancy' => 'occupancy_rate',
        'income' => 'rental_income',
    ];

    /**
     * 物件一覧
     * Route: GET /tenant/properties
     */
    public function index(Request $request)
    {
        $sort = ListSort::fromRequest($request, array_keys(self::SORTABLE));

        $query = Property::where('department', DepartmentCode::Tenant)
            ->with([
                'units:id,property_id,area_tsubo',
                'contracts' => function ($q) {
                    $q->where('status', ContractStatus::Active)
                      ->select('id', 'property_id', 'unit_id', 'rent', 'common_fee', 'garbage_fee', 'pest_control_fee');
                },
            ]);

        // --- フィルター ---
        if ($request->filled('operation_status')) {
            $query->where('operation_status', $request->operation_status);
        }

        if ($request->filled('owner_type')) {
            $query->where('owner_type', $request->owner_type);
        }

        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('address', 'like', "%{$keyword}%")
                  ->orWhere('code', 'like', "%{$keyword}%");
            });
        }

        // ⚠ 並べ替えの対象は「全件」。ページを切ってから並べると 1 ページ目の中だけで
        //   並んで答えが変わる（設計書 §3.1）。入居率・賃料収入は PHP にしか計算が無いので
        //   ここで全件に計算してから並べる。eager load は据え置きなのでクエリ本数は増えない。
        $all = $query->orderBy('operation_status', 'asc')  // 稼働が先
                     ->orderBy('code', 'asc')
                     ->get();

        // 各物件の入居率・賃料収入を計算（Eager Loadedデータを使用、追加クエリなし）
        //   ⚠ occupancy_rate / rental_income は実カラムではない（メモリ上のみ・DB 書き込みなし）。
        foreach ($all as $property) {
            $this->calculatePropertyStats($property);
        }

        $properties = $this->paginateCollection(
            $request,
            $sort !== null ? $this->sortProperties($all, $sort) : $all,
            20
        );

        return view('tenant.properties.index', compact('properties', 'sort'));
    }

    /**
     * 入居率・賃料収入で並べ替える（設計書 §5.3）。
     *
     * ⚠ calculatePropertyStats() は**非稼働のときだけ null** を入れる（稼働は坪数 0 でも 0）。
     *   よって「値を持つ行＝稼働中・null の行＝非稼働」に分かれ、null を末尾へ回す処理が
     *   既定順の「稼働が先」とも矛盾しない。末尾の中は code 昇順＝既定順。
     *
     * ⚠ 同点の第 2 キーに code を**明示指定する**。PHP のソートが安定であることに
     *   暗黙に頼らない。これが無いとページをまたいで行が重複・消失する（設計書 §4.3-3）。
     *
     * ⚠ **呼ぶ前に calculatePropertyStats() を通しておくこと。** 通していないコレクションを
     *   渡すと全行が「値なし」に落ちて、例外も警告も出さずに静かに既定順を返す。
     *
     * @param  Collection<int, Property>  $properties
     */
    private function sortProperties(Collection $properties, ListSort $sort): Collection
    {
        $field = self::SORTABLE[$sort->key];

        $withValue = $properties->filter(fn (Property $p) => $p->{$field} !== null);
        $withoutValue = $properties->filter(fn (Property $p) => $p->{$field} === null);

        return $withValue
            ->sortBy([[$field, $sort->isAscending() ? 'asc' : 'desc'], ['code', 'asc']])
            ->concat($withoutValue->sortBy('code'))
            ->values();
    }

    /**
     * 並べ替え済みのコレクションを手でページングする。
     *
     * ⚠ ->links() には戻さない。既存ビューはインライン番号付きページネーション
     *   （hasPages / getUrlRange / nextPageUrl）を使っている（Bug #24 以来の規約）。
     *   LengthAwarePaginator を自分で組めばそのまま動く。
     *
     * ⚠ **withQueryString() は使わない。** null 値のキーは http_build_query が
     *   丸ごと捨てるため、`?operation_status=` のような空の絞り込みがページ送りリンクから
     *   消える（Bug #31）。AreaBuildingListService / ProcurementListService と同じ形にする。
     *   ⚠ 見出しリンク側（ListSort::url()）も同じ正規化をしている。片方だけ変えないこと。
     *
     * @param  Collection<int, Property>  $items
     */
    private function paginateCollection(Request $request, Collection $items, int $perPage): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'query' => array_map(fn ($value) => $value ?? '', $request->query()),
            ]
        );
    }

    /**
     * 物件登録フォーム
     * Route: GET /tenant/properties/create
     */
    public function create()
    {
        $nextCode = $this->generateNextCode();
        $structureTypes = StructureType::orderBy('sort_order')->get();

        return view('tenant.properties.create', compact('nextCode', 'structureTypes'));
    }

    /**
     * 物件保存
     * Route: POST /tenant/properties
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:200',
            'operation_status' => 'required|in:active,inactive',
            'address'          => 'required|string|max:500',
            'structure'        => 'nullable|string|max:50',
            'built_date'       => 'nullable|string|max:7',
            'total_floors'     => 'nullable|integer|min:1|max:99',
            'owner_type'       => 'nullable|in:self_owned,owner',
            'owner_name'       => 'nullable|string|max:200',
            'notes'            => 'nullable|string|max:5000',
        ], [], [
            // 画面ラベルに合わせる（lang/ja/validation.php の既定は「名称」）
            'name' => '物件名',
        ]);

        $validated['code'] = $this->generateNextCode();
        $validated['property_type'] = PropertyType::Tenant->value;
        $validated['department'] = DepartmentCode::Tenant->value;

        // オーナー所有でない場合はオーナー名をクリア
        if (($validated['owner_type'] ?? null) !== 'owner') {
            $validated['owner_name'] = null;
        }

        try {
            $property = Property::create($validated);
        } catch (QueryException $e) {
            // UNIQUE制約違反（同時アクセスでのコード重複）の場合はリトライ
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                $validated['code'] = $this->generateNextCode();
                $property = Property::create($validated);
            } else {
                throw $e;
            }
        }

        return redirect()
            ->route('tenant.properties.show', $property)
            ->with('success', "物件「{$property->name}」を登録しました。");
    }

    /**
     * 物件詳細（フロアマップ＋全タブデータ取得）
     * Route: GET /tenant/properties/{property}
     */
    public function show(Property $property)
    {
        // 区画を階数降順 → 号室昇順でロード（投資案件も一緒に）
        $property->load([
            'units' => function ($q) {
                $q->orderByDesc('floor')->orderBy('room_number');
            },
            'units.activeContract.customer',
            'units.investments' => function ($q) {
                $q->whereIn('status', ['in_progress', 'completed', 'recovering', 'recovered'])
                  ->orderByDesc('created_at')
                  ->orderByDesc('id');
            },
        ]);

        // 各区画の投資をライブ計算（フロアマップ用・メモリ上のみ・DB 書き込みなし）
        foreach ($property->units as $unit) {
            foreach ($unit->investments as $inv) {
                $inv->refreshRecovery();
            }
        }

        // --- サマリー計算 ---
        $units = $property->units;
        $totalTsubo = $units->sum('area_tsubo');

        // 契約中の区画（activeContract がある区画）
        $occupiedUnits = $units->filter(fn ($u) => $u->activeContract !== null);
        $contractedTsubo = $occupiedUnits->sum('area_tsubo');

        // 入居率（坪数ベース）
        $occupancyRate = $totalTsubo > 0
            ? round($contractedTsubo / $totalTsubo * 100, 1)
            : 0;

        // 賃料収入（契約中の家賃+共益費+ゴミ代+駆除代）
        $rentalIncome = $occupiedUnits->sum(fn ($u) => $u->activeContract->monthly_total);

        // 契約数（契約中のみ）
        $activeContractCount = $occupiedUnits->count();

        // 問合せ数（全件カウント — ステータス不問）
        $inquiryCount = $property->inquiries()->count();

        $summary = [
            'occupancy_rate'      => $occupancyRate,
            'total_tsubo'         => $totalTsubo,
            'contracted_tsubo'    => $contractedTsubo,
            'rental_income'       => $rentalIncome,
            'active_contract_count' => $activeContractCount,
            'inquiry_count'       => $inquiryCount,
        ];

        // --- フロアマップ用データ構築 ---
        $floorMap = $this->buildFloorMap($property);

        // --- タブデータ ---
        // 契約タブ（契約中のみ）
        $activeContracts = $property->contracts()
            ->where('status', ContractStatus::Active)
            ->with(['unit', 'customer'])
            ->orderBy('contract_date', 'desc')
            ->get();

        // 解約タブ（解約済みのみ）
        $terminatedContracts = $property->contracts()
            ->where('status', ContractStatus::Terminated)
            ->with(['unit', 'customer'])
            ->orderBy('contract_end_date', 'desc')
            ->get();

        // 変更履歴タブ
        $changeLogs = $property->changeLogs()
            ->with('changedByUser')
            ->orderByDesc('changed_at')
            ->limit(50)
            ->get();

        // 投資案件タブ（この物件の全投資案件）
        $investments = Investment::where('property_id', $property->id)
            ->with('unit')
            ->orderByDesc('created_at')
            ->get();

        // 投資タブの各投資をライブ計算（メモリ上のみ・DB 書き込みなし）
        foreach ($investments as $inv) {
            $inv->refreshRecovery();
        }

        // 修繕タブ（この物件の直近10件）
        $repairs = Repair::where('property_id', $property->id)
            ->with('unit')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // 問合せタブ（この物件の全問合せ — クライアント側フィルタ）
        $inquiries = \App\Models\Inquiry::where('property_id', $property->id)
            ->with(['units', 'assignedUser'])
            ->orderByDesc('inquiry_date')
            ->orderByDesc('id')
            ->get();

        // 賃料収入履歴（STEP 7）
        $rentalIncome = app(RentalIncomeService::class)->forProperty($property);

        return view('tenant.properties.show', compact(
            'property',
            'summary',
            'floorMap',
            'activeContracts',
            'terminatedContracts',
            'changeLogs',
            'investments',
            'repairs',
            'inquiries',
            'rentalIncome',
        ));
    }

    /**
     * 物件編集フォーム
     * Route: GET /tenant/properties/{property}/edit
     */
    public function edit(Property $property)
    {
        $structureTypes = StructureType::orderBy('sort_order')->get();

        return view('tenant.properties.edit', compact('property', 'structureTypes'));
    }

    /**
     * 物件更新（変更履歴自動記録）
     * Route: PUT /tenant/properties/{property}
     */
    public function update(Request $request, Property $property)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:200',
            'operation_status' => 'required|in:active,inactive',
            'address'          => 'required|string|max:500',
            'structure'        => 'nullable|string|max:50',
            'built_date'       => 'nullable|string|max:7',
            'total_floors'     => 'nullable|integer|min:1|max:99',
            'owner_type'       => 'nullable|in:self_owned,owner',
            'owner_name'       => 'nullable|string|max:200',
            'notes'            => 'nullable|string|max:5000',
        ], [], [
            // 画面ラベルに合わせる（lang/ja/validation.php の既定は「名称」）
            'name' => '物件名',
        ]);

        // オーナー所有でない場合はオーナー名をクリア
        if (($validated['owner_type'] ?? null) !== 'owner') {
            $validated['owner_name'] = null;
        }

        DB::transaction(function () use ($property, $validated) {
            // 変更履歴を記録
            $this->recordChangeLogs($property, $validated);

            // 物件更新
            $property->update($validated);
        });

        return redirect()
            ->route('tenant.properties.show', $property)
            ->with('success', "物件「{$property->name}」を更新しました。");
    }

    /**
     * 物件削除（ソフトデリート）
     * Route: DELETE /tenant/properties/{property}
     */
    public function destroy(Property $property)
    {
        $name = $property->name;

        // 契約中のデータがある場合は削除不可
        $activeContracts = $property->contracts()
            ->where('status', ContractStatus::Active)
            ->count();

        if ($activeContracts > 0) {
            return back()->with('error', '契約中のデータがあるため削除できません。');
        }

        $property->delete();

        return redirect()
            ->route('tenant.properties.index')
            ->with('success', "物件「{$name}」を削除しました。");
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * 物件コードの次番を自動採番する（T-001形式）
     */
    private function generateNextCode(): string
    {
        $lastCode = Property::withTrashed()
            ->where('department', DepartmentCode::Tenant)
            ->where('code', 'like', 'T-%')
            ->orderByDesc('code')
            ->value('code');

        if ($lastCode) {
            $num = (int) substr($lastCode, 2);
            return 'T-' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
        }

        return 'T-001';
    }

    /**
     * 物件の入居率・賃料収入を計算してプロパティにセットする（一覧用）
     * ※ index()でEager Loadした units / contracts（active only）を参照。追加クエリは発行しない。
     */
    private function calculatePropertyStats(Property $property): void
    {
        // 稼働ビルのみ計算
        if ($property->operation_status !== OperationStatus::Active) {
            $property->occupancy_rate = null;
            $property->rental_income = null;
            return;
        }

        // Eager Loaded済みのコレクションを使用
        $units = $property->units;
        $activeContracts = $property->contracts;  // index()でactive onlyに絞り込み済み

        $totalTsubo = $units->sum('area_tsubo');

        // 契約中の区画IDからcontracted坪数を計算
        $activeUnitIds = $activeContracts->pluck('unit_id');
        $contractedTsubo = $units->whereIn('id', $activeUnitIds)->sum('area_tsubo');

        // 入居率（坪数ベース）
        $property->occupancy_rate = $totalTsubo > 0
            ? round($contractedTsubo / $totalTsubo * 100, 1)
            : 0;

        // 賃料収入（家賃+共益費+ゴミ代+駆除代）
        $property->rental_income = $activeContracts->sum(fn ($c) => $c->monthly_total);
    }

    /**
     * フロアマップ用のデータを構築する
     */
    private function buildFloorMap(Property $property): array
    {
        $units = $property->units;
        $isBuildingType = $property->isBuildingType();

        if ($isBuildingType) {
            // ビル型: 階数ごとにグループ化（上階が上）
            $grouped = $units->groupBy('floor')->sortKeysDesc();
            $floors = [];
            $maxUnitsPerFloor = 0;
            foreach ($grouped as $floor => $floorUnits) {
                $sorted = $floorUnits->sortBy('room_number')->values();
                $floors[] = [
                    'label' => $floor . 'F',
                    'units' => $sorted,
                ];
                $maxUnitsPerFloor = max($maxUnitsPerFloor, $sorted->count());
            }
            // ビル全体の最大列数（上限5列）
            $maxCols = min(max($maxUnitsPerFloor, 2), 5);

            return ['type' => 'building', 'floors' => $floors, 'maxCols' => $maxCols];
        }

        // 平屋型: 全区画を横並び（上限5列）
        $sorted = $units->sortBy('room_number')->values();
        $maxCols = min(max($sorted->count(), 2), 5);

        return [
            'type' => 'flat',
            'units' => $sorted,
            'maxCols' => $maxCols,
        ];
    }

    /**
     * 物件の変更履歴を記録する
     */
    private function recordChangeLogs(Property $property, array $newValues): void
    {
        // 変更を追跡する項目の日本語名マッピング
        $fieldLabels = [
            'name'             => '物件名',
            'operation_status' => '稼働状態',
            'address'          => '住所',
            'structure'        => '構造',
            'built_date'       => '築年月',
            'total_floors'     => '総階数',
            'owner_type'       => '所有者区分',
            'owner_name'       => '所有者名',
            'notes'            => '備考',
        ];

        // ENUMの表示値変換
        $displayValue = function (string $field, $value) {
            if ($value === null || $value === '') return '—';

            return match ($field) {
                'operation_status' => match ($value instanceof OperationStatus ? $value->value : $value) {
                    'active' => '稼働',
                    'inactive' => '非稼働',
                    default => (string) $value,
                },
                'owner_type' => match ($value instanceof OwnerType ? $value->value : $value) {
                    'self_owned' => '自社所有',
                    'owner' => 'オーナー所有',
                    default => (string) $value,
                },
                default => (string) $value,
            };
        };

        $now = now();
        $userId = Auth::id();

        // 数値型フィールド（DB上の "450.00" とフォーム上の "450" を同値と判定するため）
        $numericFields = ['total_floors'];

        foreach ($fieldLabels as $field => $label) {
            if (! array_key_exists($field, $newValues)) {
                continue;
            }

            $oldRaw = $property->getRawOriginal($field) ?? $property->getOriginal($field);
            $newRaw = $newValues[$field];

            // 値の変更判定（数値フィールドはfloatに正規化して比較）
            if (in_array($field, $numericFields)) {
                $oldNorm = ($oldRaw === null || $oldRaw === '') ? null : (float) $oldRaw;
                $newNorm = ($newRaw === null || $newRaw === '') ? null : (float) $newRaw;
                if ($oldNorm === $newNorm) {
                    continue;
                }
            } else {
                $oldStr = $oldRaw === null ? '' : (string) $oldRaw;
                $newStr = $newRaw === null ? '' : (string) $newRaw;
                if ($oldStr === $newStr) {
                    continue;
                }
            }

            PropertyChangeLog::create([
                'property_id' => $property->id,
                'field_name'  => $label,
                'old_value'   => $displayValue($field, $oldRaw),
                'new_value'   => $displayValue($field, $newRaw),
                'changed_by'  => $userId,
                'changed_at'  => $now,
            ]);
        }
    }
}
