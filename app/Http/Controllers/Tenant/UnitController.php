<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ContractStatus;
use App\Enums\DepartmentCode;
use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Models\InquiryUsageType;
use App\Models\Property;
use App\Models\Repair;
use App\Models\Unit;
use App\Models\UnitRentRevision;
use App\Services\Tenant\RentalIncomeService;
use App\Support\ListSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    /**
     * 部屋一覧で並び替えを許す列 → [SQL 式, 「—」を末尾へ回すか]。
     *
     * ⚠ **許可リストはここ 1 箇所だけ。** ListSort::fromRequest() には array_keys() を渡す。
     *   許可リストと式のマップを別々のリテラルで持つと、キーを足して片方を忘れたときに
     *   一覧が 500 になる。しかも「不正なキーを投げる」テストでは**この向きの取り違えを
     *   原理的に検出できない**（不正キーは既定順に落ちるだけなので）。
     *
     * ⚠ 「—」を末尾へ回すのは**画面に「—」と出る列だけ**（設計書 §4.4）。
     *   units.rent は nullable だが、ビューは number_format(null) で **「0円」**と描画するので、
     *   末尾へ飛ばさず COALESCE で 0 として並べる。ここを揃え損なうと
     *   「画面は 0円 なのにその行だけ末尾に飛ぶ」という食い違いになる（Bug #41 / #46）。
     */
    private const SORTABLE = [
        'area' => ['units.area_tsubo', true],           // NULL は画面で「—」  → 末尾へ
        'rent' => ['COALESCE(units.rent, 0)', false],   // NULL は画面で「0円」→ 0 として
        'monthly' => [Unit::MONTHLY_TOTAL_SQL, false],  // COALESCE 済みで NULL になりえない
    ];

    /**
     * 部屋一覧（物件横断）
     * Route: GET /tenant/units
     */
    public function index(Request $request)
    {
        $sort = ListSort::fromRequest($request, array_keys(self::SORTABLE));

        // テナント物件に属する区画を取得
        $query = Unit::whereHas('property', function ($q) {
            $q->where('department', DepartmentCode::Tenant);
        })->with(['property', 'activeContract']);

        // フィルター: 物件（チェックボックス複数選択）
        if ($request->filled('property_ids')) {
            $query->whereIn('property_id', $request->input('property_ids'));
        }

        // フィルター: ステータス
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // フィルター: キーワード（物件名・表示名）
        if ($request->filled('keyword')) {
            $keyword = $request->input('keyword');
            $query->where(function ($q) use ($keyword) {
                $q->where('display_name', 'like', "%{$keyword}%")
                  ->orWhereHas('property', function ($pq) use ($keyword) {
                      $pq->where('name', 'like', "%{$keyword}%");
                  });
            });
        }

        $this->applySort($query, $sort);

        // ⚠ withQueryString() は使わない。null 値のキーは http_build_query が丸ごと捨てるため、
        //   `?status=` のような空の絞り込みがページ送りリンクから消える（Bug #31）。
        //   物件一覧の paginateCollection() / 見出しリンクの ListSort::url() と同じ正規化にする。
        $units = $query->paginate(20)->appends(array_map(fn ($value) => $value ?? '', $request->query()));

        // 物件一覧（チェックボックス用）
        $properties = Property::where('department', DepartmentCode::Tenant)
            ->orderBy('id')
            ->get(['id', 'name']);

        // チェックボックス用の物件ID配列（@json用に事前整形）
        $propertyIdsForJs = $properties->pluck('id')->map(function ($id) {
            return (string) $id;
        })->values()->toArray();

        return view('tenant.units.index', compact('units', 'properties', 'propertyIdsForJs', 'sort'));
    }

    /**
     * 部屋一覧の並び替えを適用する。指定が無ければ既定順だけを付ける。
     *
     * ⚠ 「—」を末尾へ回す判断は列ごとに違う。理由は @see self::SORTABLE に書いてある
     *   （同じ規範を 2 箇所へ写すと片方だけ直す事故が起きる。Bug #41 / #46）。
     *
     * ⚠ 許可リストを通った値しか来ないので、self::SORTABLE のキーは必ず存在し、
     *   式は必ずコード内の定数。利用者の入力が SQL に混ざる経路は無い。
     */
    private function applySort(Builder $query, ?ListSort $sort): void
    {
        if ($sort !== null) {
            [$expression, $nullsLast] = self::SORTABLE[$sort->key];

            if ($nullsLast) {
                $query->orderByRaw("({$expression} IS NULL) asc");
            }

            $query->orderByRaw($expression . ' ' . ($sort->isAscending() ? 'asc' : 'desc'));
        }

        // ⚠ 既定順は**必ず最後に**付ける。これが無いと同点の行がページをまたいで
        //   重複したり消えたりする（設計書 §4.3-3）。
        $query->orderBy('units.property_id')
              ->orderBy('units.floor')
              ->orderBy('units.room_number');
    }

    /**
     * 区画登録フォーム
     * Route: GET /tenant/properties/{property}/units/create
     */
    public function create(Property $property)
    {
        $usageTypes = InquiryUsageType::orderBy('sort_order')->get(['id', 'name']);

        return view('tenant.units.create', compact('property', 'usageTypes'));
    }

    /**
     * 区画保存
     * Route: POST /tenant/properties/{property}/units
     */
    public function store(Request $request, Property $property)
    {
        $validated = $request->validate([
            'floor'            => 'nullable|integer|min:-3|max:99',
            'room_number'      => 'required|string|max:20',
            'area_tsubo'       => 'nullable|numeric|min:0|max:9999.99',
            'usage_type_id'    => 'nullable|exists:inquiry_usage_types,id',
            'status'           => 'required|in:vacant,negotiating',
            'rent'             => 'nullable|integer|min:0',
            'common_fee'       => 'nullable|integer|min:0',
            'deposit'          => 'nullable|integer|min:0',
            'garbage_fee'      => 'nullable|integer|min:0',
            'pest_control_fee' => 'nullable|integer|min:0',
            'notes'            => 'nullable|string|max:5000',
        ], [], [
            // 画面ラベルに合わせる（既定は「賃料」）
            'rent' => '家賃',
        ]);

        // 階数0は不許可（地下は-1〜-3、地上は1〜99）
        if (isset($validated['floor']) && $validated['floor'] === 0) {
            return back()->withInput()->withErrors(['floor' => '階数に0は入力できません。地下の場合は-1〜-3を入力してください。']);
        }

        // display_name自動生成
        $displayName = $this->generateDisplayName($validated['floor'] ?? null, $validated['room_number']);

        // 既存レコード検索（ソフトデリート含む）
        $existing = Unit::withTrashed()
            ->where('property_id', $property->id)
            ->where('display_name', $displayName)
            ->first();

        $validated['property_id'] = $property->id;
        $validated['display_name'] = $displayName;

        // null → 0 変換（費用フィールド）
        foreach (['rent', 'common_fee', 'deposit', 'garbage_fee', 'pest_control_fee'] as $field) {
            $validated[$field] = $validated[$field] ?? 0;
        }

        if ($existing) {
            if ($existing->trashed()) {
                // 削除済みレコードがある → 復元して新しい入力値で上書き
                // （DB UNIQUE 制約と過去の契約履歴を両立させるため）
                $existing->restore();
                $existing->update($validated);

                return redirect()
                    ->route('tenant.properties.show', $property)
                    ->with('success', "削除済みだった区画「{$displayName}」を復元し、新しい内容で登録しました。");
            }

            // アクティブな同名区画がある → エラー
            return back()
                ->withInput()
                ->withErrors(['room_number' => "表示名「{$displayName}」は既に登録されています。階数または号室を変更してください。"]);
        }

        Unit::create($validated);

        return redirect()
            ->route('tenant.properties.show', $property)
            ->with('success', "区画「{$displayName}」を登録しました。");
    }

    /**
     * 区画詳細
     * Route: GET /tenant/units/{unit}
     */
    public function show(Unit $unit)
    {
        $unit->load([
            'property',
            'activeContract.customer',
            'investments' => function ($q) {
                $q->orderByDesc('created_at')->orderByDesc('id');
            },
            'rentRevisions.revisedByUser',
            'contracts.rentRevisions.revisedByUser',
            'contracts.customer',
        ]);

        $property = $unit->property;

        // 現在の契約（activeContract）
        $activeContract = $unit->activeContract;

        // 月額合計（契約条件）
        $contractMonthlyTotal = $activeContract ? $activeContract->monthly_total : 0;

        // 修繕履歴（この区画の直近10件）
        $unitRepairs = Repair::where('unit_id', $unit->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // 賃料収入履歴（STEP 7）
        $rentalIncome = app(RentalIncomeService::class)->forUnit($unit);

        // 各投資の回収情報（区画ベース・家賃のみ）を算出して表示用に整形
        $unitInvestments = $unit->investments->map(function ($inv) {
            $recovery = $inv->calculateRecovery();
            $rate = (float) $recovery['recovery_rate'];
            return [
                'id'              => $inv->id,
                'investment_number' => $inv->investment_number,
                'pattern_label'   => $inv->pattern->label(),
                'total_amount'    => $inv->total_amount,
                'total_recovered' => $recovery['total_recovered'],
                'rate'            => $rate,
                'has_end_date'    => $inv->end_date !== null,
                'label'           => $inv->recoveryLabel($rate) ?? $inv->status->label(),
                'badge_class'     => $inv->recoveryBadgeClass($rate) ?? $inv->status->badgeClass(),
            ];
        })->values();

        // 募集家賃改定＋この区画の全契約の契約家賃改定を統合した履歴（日付降順）
        $rentHistory = $this->buildRentHistory($unit);

        return view('tenant.units.show', compact('unit', 'property', 'activeContract', 'contractMonthlyTotal', 'unitRepairs', 'rentalIncome', 'unitInvestments', 'rentHistory'));
    }

    /**
     * 区画編集フォーム
     * Route: GET /tenant/units/{unit}/edit
     */
    public function edit(Unit $unit)
    {
        $unit->load('property');
        $property = $unit->property;
        $usageTypes = InquiryUsageType::orderBy('sort_order')->get(['id', 'name']);

        return view('tenant.units.edit', compact('unit', 'property', 'usageTypes'));
    }

    /**
     * 区画更新
     * Route: PUT /tenant/units/{unit}
     */
    public function update(Request $request, Unit $unit)
    {
        $unit->load('property');
        $property = $unit->property;

        $isOccupied = $unit->status === UnitStatus::Occupied;

        $validated = $request->validate([
            'floor'            => 'nullable|integer|min:-3|max:99',
            'room_number'      => 'required|string|max:20',
            'area_tsubo'       => 'nullable|numeric|min:0|max:9999.99',
            'usage_type_id'    => 'nullable|exists:inquiry_usage_types,id',
            'status'           => [
                'required',
                $isOccupied ? Rule::in([$unit->status->value]) : Rule::in(['vacant', 'negotiating']),
            ],
            // 募集家賃の4項目は「賃料改定」フローでのみ変更可能。編集では現値を保持するため
            // validation から除外する（送られても $validated に入らず update で無視される）。
            'deposit'          => 'nullable|integer|min:0',
            'notes'            => 'nullable|string|max:5000',
        ]);

        // 階数0は不許可（地下は-1〜-3、地上は1〜99）
        if (isset($validated['floor']) && $validated['floor'] === 0) {
            return back()->withInput()->withErrors(['floor' => '階数に0は入力できません。地下の場合は-1〜-3を入力してください。']);
        }

        // display_name自動再生成
        $displayName = $this->generateDisplayName($validated['floor'] ?? null, $validated['room_number']);

        // UNIQUE制約チェック（自分自身を除外、ソフトデリート含む）
        $exists = Unit::withTrashed()
            ->where('property_id', $property->id)
            ->where('display_name', $displayName)
            ->where('id', '!=', $unit->id)
            ->exists();

        if ($exists) {
            return back()
                ->withInput()
                ->withErrors(['room_number' => "表示名「{$displayName}」は既に登録されています。階数または号室を変更してください。"]);
        }

        $validated['display_name'] = $displayName;

        // null → 0 変換（敷金のみ。募集家賃4項目は除外済みで現値を保持する）
        $validated['deposit'] = $validated['deposit'] ?? 0;

        $unit->update($validated);

        return redirect()
            ->route('tenant.units.show', $unit)
            ->with('success', "区画「{$displayName}」を更新しました。");
    }

    /**
     * 区画削除（ソフトデリート）
     * Route: DELETE /tenant/units/{unit}
     */
    public function destroy(Unit $unit)
    {
        $unit->load('property');
        $property = $unit->property;
        $displayName = $unit->display_name;

        // 契約中のデータがある場合は削除不可
        $activeContracts = $unit->contracts()
            ->where('status', ContractStatus::Active)
            ->count();

        if ($activeContracts > 0) {
            return back()->with('error', '契約中のデータがあるため削除できません。');
        }

        $unit->delete();

        return redirect()
            ->route('tenant.properties.show', $property)
            ->with('success', "区画「{$displayName}」を削除しました。");
    }

    /**
     * 区画ステータス変更（vacant ↔ negotiating）
     * Route: PATCH /tenant/units/{unit}/status
     */
    public function updateStatus(Request $request, Unit $unit)
    {
        // 入居中の場合は変更不可
        if ($unit->status === UnitStatus::Occupied) {
            return back()->with('error', '入居中の区画のステータスは変更できません。契約管理から操作してください。');
        }

        // トグル：vacant → negotiating / negotiating → vacant
        $newStatus = $unit->status === UnitStatus::Vacant
            ? UnitStatus::Negotiating
            : UnitStatus::Vacant;

        $unit->update(['status' => $newStatus->value]);

        $label = $newStatus->label();

        return redirect()
            ->route('tenant.units.show', $unit)
            ->with('success', "ステータスを「{$label}」に変更しました。");
    }

    /**
     * 募集家賃の賃料改定フォーム
     * Route: GET /tenant/units/{unit}/revise
     */
    public function showReviseRent(Unit $unit)
    {
        // 入居中は契約改定へ誘導（募集家賃の改定対象は空室・商談中のみ）
        if ($unit->status === UnitStatus::Occupied) {
            return redirect()
                ->route('tenant.units.show', $unit)
                ->with('error', '入居中の区画は契約から賃料改定してください。');
        }

        $unit->load('property');

        return view('tenant.units.revise', compact('unit'));
    }

    /**
     * 募集家賃の賃料改定実行
     * Route: POST /tenant/units/{unit}/revise
     */
    public function reviseRent(Request $request, Unit $unit)
    {
        // 入居中ガード（同上）
        if ($unit->status === UnitStatus::Occupied) {
            return redirect()
                ->route('tenant.units.show', $unit)
                ->with('error', '入居中の区画は契約から賃料改定してください。');
        }

        $validated = $request->validate([
            'revision_date'        => 'required|date',
            'new_rent'             => 'required|integer|min:0',
            'new_common_fee'       => 'nullable|integer|min:0',
            'new_garbage_fee'      => 'nullable|integer|min:0',
            'new_pest_control_fee' => 'nullable|integer|min:0',
            'new_deposit'          => 'nullable|integer|min:0',
            'reason'               => 'nullable|string|max:5000',
        ]);

        DB::transaction(function () use ($unit, $validated) {
            // 改定履歴を記録（old=現在の募集条件、new=入力値）
            UnitRentRevision::create([
                'unit_id'              => $unit->id,
                'revision_date'        => $validated['revision_date'],
                'old_rent'             => $unit->rent,
                'new_rent'             => $validated['new_rent'],
                'old_common_fee'       => $unit->common_fee,
                'new_common_fee'       => $validated['new_common_fee'] ?? 0,
                'old_garbage_fee'      => $unit->garbage_fee,
                'new_garbage_fee'      => $validated['new_garbage_fee'] ?? 0,
                'old_pest_control_fee' => $unit->pest_control_fee,
                'new_pest_control_fee' => $validated['new_pest_control_fee'] ?? 0,
                'old_deposit'          => $unit->deposit,
                'new_deposit'          => $validated['new_deposit'] ?? 0,
                'reason'               => $validated['reason'] ?? null,
                'revised_by'           => Auth::id(),
            ]);

            // 区画の募集条件を更新
            $unit->update([
                'rent'             => $validated['new_rent'],
                'common_fee'       => $validated['new_common_fee'] ?? 0,
                'garbage_fee'      => $validated['new_garbage_fee'] ?? 0,
                'pest_control_fee' => $validated['new_pest_control_fee'] ?? 0,
                'deposit'          => $validated['new_deposit'] ?? 0,
            ]);
        });

        return redirect()
            ->route('tenant.units.show', $unit)
            ->with('success', "区画「{$unit->display_name}」の募集家賃を改定しました。");
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * 区画の家賃推移を統合した履歴を返す（募集家賃改定＋その区画の全契約の契約家賃改定）。
     * 各行を共通形に正規化し、改定日降順（同日は登録時刻降順）で並べる。
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function buildRentHistory(Unit $unit): \Illuminate\Support\Collection
    {
        // 募集家賃改定（区分: 募集）
        $asking = $unit->rentRevisions->map(function ($r) {
            return [
                'revision_date'        => $r->revision_date,
                'created_at'           => $r->created_at,
                'kind'                 => 'asking',
                'context_label'        => '募集家賃',
                'old_rent'             => $r->old_rent,
                'new_rent'             => $r->new_rent,
                'old_common_fee'       => $r->old_common_fee,
                'new_common_fee'       => $r->new_common_fee,
                'old_garbage_fee'      => $r->old_garbage_fee,
                'new_garbage_fee'      => $r->new_garbage_fee,
                'old_pest_control_fee' => $r->old_pest_control_fee,
                'new_pest_control_fee' => $r->new_pest_control_fee,
                'old_deposit'          => $r->old_deposit,
                'new_deposit'          => $r->new_deposit,
                'revised_by_name'      => $r->revisedByUser->name ?? '—',
            ];
        });

        // この区画の全契約（解約済み含む）の契約家賃改定（区分: 契約）
        $contractRevisions = $unit->contracts->flatMap(function ($contract) {
            return $contract->rentRevisions->map(function ($r) use ($contract) {
                return [
                    'revision_date'        => $r->revision_date,
                    'created_at'           => $r->created_at,
                    'kind'                 => 'contract',
                    'context_label'        => $contract->contract_number . ' / ' . ($contract->customer->name ?? '—'),
                    'old_rent'             => $r->old_rent,
                    'new_rent'             => $r->new_rent,
                    'old_common_fee'       => $r->old_common_fee,
                    'new_common_fee'       => $r->new_common_fee,
                    'old_garbage_fee'      => $r->old_garbage_fee,
                    'new_garbage_fee'      => $r->new_garbage_fee,
                    'old_pest_control_fee' => $r->old_pest_control_fee,
                    'new_pest_control_fee' => $r->new_pest_control_fee,
                    'old_deposit'          => $r->old_deposit,
                    'new_deposit'          => $r->new_deposit,
                    'revised_by_name'      => $r->revisedByUser->name ?? '—',
                ];
            });
        });

        // 2ソースをマージし、改定日降順（同日は created_at 降順）で整列
        return $asking->concat($contractRevisions)
            ->sortByDesc(function ($row) {
                return $row['revision_date']->format('Y-m-d') . ' ' . $row['created_at']->format('H:i:s');
            })
            ->values();
    }

    /**
     * display_name自動生成: floor正→「3A」、floor負→「B1A」（地下）、floor無し→「A」
     */
    private function generateDisplayName(?int $floor, string $roomNumber): string
    {
        if ($floor !== null) {
            if ($floor < 0) {
                return 'B' . abs($floor) . $roomNumber;
            }
            return $floor . $roomNumber;
        }

        return $roomNumber;
    }
}
