<?php

namespace App\Http\Controllers\Housing;

use App\Enums\CustomOrderStatus;
use App\Enums\HousingLandSourceType;
use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\HsContract;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReProcurement;
use App\Models\ReProjectLot;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 住宅事業 契約管理（統合一覧 + 建売契約 + 注文住宅契約）
 *
 * Phase 2 時点: index / showBuilding は既存ロジックを移行
 * Phase 4 以降: showCustomOrder / editBuilding / editCustomOrder /
 *   updateBuilding / updateCustomOrder / selectBuildingProperty を本実装
 */
class HsContractListController extends Controller
{
    /**
     * 契約一覧（建売 + 注文住宅 統合）
     * GET /housing/contracts
     */
    public function index(Request $request)
    {
        // 年度計算（5月始まり）
        $currentFiscalYear = $this->getCurrentFiscalYear();
        $fiscalYear = $request->input('fiscal_year', (string) $currentFiscalYear);

        // 建売契約クエリ
        $tateuriQuery = HsContract::with(['property', 'createdBy']);

        // 注文住宅クエリ（契約以降のステータス + contract_date あり）
        $contractedStatuses = [
            CustomOrderStatus::Contracted->value,
            CustomOrderStatus::Construction->value,
            CustomOrderStatus::Completed->value,
            CustomOrderStatus::Delivered->value,
        ];
        $customQuery = HsCustomOrder::with('createdBy')
            ->whereIn('status', $contractedStatuses)
            ->whereNotNull('contract_date');

        // 年度フィルター
        if ($fiscalYear !== '' && $fiscalYear !== 'all') {
            $fy = (int) $fiscalYear;
            $start = "{$fy}-05-01";
            $end = ($fy + 1) . "-04-30";
            $tateuriQuery->whereBetween('contract_date', [$start, $end]);
            $customQuery->whereBetween('contract_date', [$start, $end]);
        }

        // 種別フィルター
        $contractType = $request->input('contract_type', '');

        // 担当者フィルター
        if ($request->filled('staff_user_id')) {
            $tateuriQuery->where('created_by', $request->staff_user_id);
            $customQuery->where('created_by', $request->staff_user_id);
        }

        // 建売データ取得（種別フィルターでcustomのみの場合はスキップ）
        $tateuriItems = collect();
        if ($contractType !== 'custom') {
            $tateuriItems = $tateuriQuery->get()->map(function ($c) {
                return $this->mapTateuriToDto($c);
            });
        }

        // 注文住宅データ取得（種別フィルターでtateuriのみの場合はスキップ）
        $customItems = collect();
        if ($contractType !== 'tateuri') {
            $customItems = $customQuery->get()->map(function ($c) {
                return $this->mapCustomOrderToDto($c);
            });
        }

        // 統合・ソート
        $allItems = $tateuriItems->merge($customItems)->sortByDesc('contract_date')->values();

        // 集計（全体）
        $tateuriCount = $tateuriItems->count();
        $customCount = $customItems->count();
        $totalCount = $allItems->count();
        $sellingTotal = (int) $allItems->sum('selling_total');
        $costTotal = (int) $allItems->sum('cost_total');
        $profitTotal = (int) $allItems->sum('profit');
        $profitRate = $sellingTotal > 0 ? round(($profitTotal / $sellingTotal) * 100, 1) : 0;

        // 集計（土地・建物別 — Phase 3 のサマリーカード5分割向け）
        $landProfitTotal = (int) $allItems->sum('land_profit');
        $buildingProfitTotal = (int) $allItems->sum('building_profit');
        $landSellingTotal = (int) $allItems->sum('land_selling');
        $buildingSellingTotal = (int) $allItems->sum('building_selling');
        $landProfitRate = $landSellingTotal > 0
            ? round(($landProfitTotal / $landSellingTotal) * 100, 1)
            : 0;
        $buildingProfitRate = $buildingSellingTotal > 0
            ? round(($buildingProfitTotal / $buildingSellingTotal) * 100, 1)
            : 0;

        // 手動ページネーション
        $perPage = 20;
        $currentPage = $request->input('page', 1);
        $pagedItems = $allItems->slice(($currentPage - 1) * $perPage, $perPage)->values();
        $contracts = new LengthAwarePaginator(
            $pagedItems,
            $allItems->count(),
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // 年度リスト
        $fiscalYears = $this->getFiscalYearList();

        // 担当者リスト
        $staffUsers = User::orderBy('name')->get();

        // 担当者苗字重複チェック用
        $lastNameCounts = [];
        foreach ($staffUsers as $u) {
            $lastName = mb_substr($u->name, 0, mb_strpos($u->name, ' ') ?: mb_strlen($u->name));
            $lastNameCounts[$lastName] = ($lastNameCounts[$lastName] ?? 0) + 1;
        }

        return view('housing.contracts.index', compact(
            'contracts', 'currentFiscalYear', 'fiscalYear', 'fiscalYears',
            'totalCount', 'sellingTotal', 'costTotal', 'profitTotal', 'profitRate',
            'landProfitTotal', 'buildingProfitTotal',
            'landSellingTotal', 'buildingSellingTotal',
            'landProfitRate', 'buildingProfitRate',
            'tateuriCount', 'customCount',
            'staffUsers', 'lastNameCounts'
        ));
    }

    /**
     * 建売契約詳細
     * GET /housing/contracts/building/{hsContract}
     */
    public function showBuilding(HsContract $hsContract)
    {
        $hsContract->load([
            'property.projectLot.project',
            'property.procurement',
            'createdBy',
            'updatedBy',
        ]);

        $contract = $hsContract;
        $property = $contract->property;

        return view('housing.contracts.show-building', compact('contract', 'property'));
    }

    /**
     * 注文住宅契約詳細
     * GET /housing/contracts/custom-order/{hsCustomOrder}
     */
    public function showCustomOrder(HsCustomOrder $hsCustomOrder)
    {
        $hsCustomOrder->load([
            'projectLot.project',
            'procurement',
            'createdBy',
            'updatedBy',
        ]);

        return view('housing.contracts.show-custom-order', compact('hsCustomOrder'));
    }

    /**
     * 建売契約編集フォーム
     * GET /housing/contracts/building/{hsContract}/edit
     *
     * 契約情報（HsContract）と物件側の原価項目（HsProperty.land_cost / building_cost / is_land_cost_manual）
     * を同一フォームで編集する。
     */
    public function editBuilding(HsContract $hsContract)
    {
        $property = $hsContract->property;
        if (!$property) {
            abort(404, '建売物件が見つかりません');
        }

        $hsContract->load(['createdBy']);
        $property->load(['projectLot.project', 'procurement']);

        // 担当者リスト
        $staffUsers = User::orderBy('name')->get();

        // 買主マスタ（住宅事業所属。現在紐付け中のbuyerが SoftDelete されていても選択可能にする）
        $buyers = Buyer::ofDepartment('housing')
            ->orderBy('last_name_kana')
            ->get();
        if ($hsContract->customer_id) {
            $current = Buyer::withTrashed()->find($hsContract->customer_id);
            if ($current && !$buyers->contains('id', $current->id)) {
                $buyers->push($current);
            }
        }

        return view('housing.contracts.edit-building', compact(
            'hsContract', 'property', 'staffUsers', 'buyers'
        ));
    }

    /**
     * 建売契約更新
     * PUT /housing/contracts/building/{hsContract}
     *
     * HsContract（売価・契約日・顧客等）と HsProperty（土地原価・建築費）を
     * DB::transaction で同時更新する。
     */
    public function updateBuilding(Request $request, HsContract $hsContract)
    {
        $property = $hsContract->property;
        if (!$property) {
            abort(404, '建売物件が見つかりません');
        }

        $validated = $request->validate([
            'customer_name'          => 'required|string|max:100',
            'customer_id'            => 'nullable|integer|exists:buyers,id',
            'created_by'             => 'nullable|integer|exists:users,id',
            'contract_date'          => 'required|date',
            'notes'                  => 'nullable|string|max:5000',
            'selling_price_land'     => 'required|integer|min:0',
            'selling_price_building' => 'required|integer|min:0',
            'tax_rate'               => 'required|numeric|min:0|max:100',
            'is_land_cost_manual'    => 'sometimes|boolean',
            'land_cost'              => [
                Rule::requiredIf(fn() => $request->boolean('is_land_cost_manual')),
                'nullable', 'integer', 'min:0',
            ],
            'building_cost'          => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($validated, $request, $hsContract, $property) {
            // 契約レコードを更新（売価・顧客・契約日・備考・担当者）
            $contractData = [
                'customer_name'          => $validated['customer_name'],
                'customer_id'            => $validated['customer_id'] ?? null,
                'contract_date'          => $validated['contract_date'],
                'selling_price_land'     => $validated['selling_price_land'],
                'selling_price_building' => $validated['selling_price_building'],
                'tax_rate'               => $validated['tax_rate'],
                'notes'                  => $validated['notes'] ?? null,
                'updated_by'             => auth()->id(),
            ];
            // 担当者（created_by）は明示指定された場合のみ上書き
            if (!empty($validated['created_by'])) {
                $contractData['created_by'] = $validated['created_by'];
            }
            $hsContract->update($contractData);

            // 物件側の原価項目を更新
            $isManual = $request->boolean('is_land_cost_manual');
            $propertyData = [
                'building_cost'       => $validated['building_cost'],
                'is_land_cost_manual' => $isManual,
                'updated_by'          => auth()->id(),
            ];
            // 手動入力ON のときのみ land_cost を上書き保存（OFFのときは紐付け先参照を維持）
            if ($isManual) {
                $propertyData['land_cost'] = $validated['land_cost'] ?? null;
            }
            $property->update($propertyData);
        });

        return redirect()
            ->route('housing.contracts.show-building', $hsContract)
            ->with('success', '契約を更新しました。');
    }

    /**
     * 注文住宅契約編集フォーム
     * GET /housing/contracts/custom-order/{hsCustomOrder}/edit
     *
     * 土地種別（仕入れ/分譲地/顧客所有地）に応じて入力項目を切り替える。
     */
    public function editCustomOrder(HsCustomOrder $hsCustomOrder)
    {
        $hsCustomOrder->load(['projectLot.project', 'procurement', 'createdBy']);

        // 担当者リスト
        $staffUsers = User::orderBy('name')->get();

        // 買主マスタ（住宅事業所属。現在紐付け中のbuyerが SoftDelete されていても選択可能にする）
        $buyers = Buyer::ofDepartment('housing')
            ->orderBy('last_name_kana')
            ->get();
        if ($hsCustomOrder->customer_id) {
            $current = Buyer::withTrashed()->find($hsCustomOrder->customer_id);
            if ($current && !$buyers->contains('id', $current->id)) {
                $buyers->push($current);
            }
        }

        // 仕入れ案件 / 分譲地区画リスト（プルダウン選択用）
        $procurements = ReProcurement::orderBy('procurement_code')->get();
        $projectLots = ReProjectLot::with('project')->orderBy('id')->get();

        return view('housing.contracts.edit-custom-order', compact(
            'hsCustomOrder', 'staffUsers', 'buyers', 'procurements', 'projectLots'
        ));
    }

    /**
     * 注文住宅契約更新
     * PUT /housing/contracts/custom-order/{hsCustomOrder}
     *
     * HsCustomOrder 単一テーブル更新。土地種別により土地関連カラムをクリアする。
     */
    public function updateCustomOrder(Request $request, HsCustomOrder $hsCustomOrder)
    {
        $validated = $request->validate([
            'customer_name'           => 'required|string|max:100',
            'customer_id'             => 'nullable|integer|exists:buyers,id',
            'created_by'              => 'nullable|integer|exists:users,id',
            'contract_date'           => 'required|date',
            'notes'                   => 'nullable|string|max:5000',
            'land_source_type'        => ['required', Rule::in([
                HousingLandSourceType::ProjectLot->value,
                HousingLandSourceType::Procurement->value,
                HousingLandSourceType::CustomerLand->value,
            ])],
            're_project_lot_id'       => 'nullable|integer|exists:re_project_lots,id',
            're_procurement_id'       => 'nullable|integer|exists:re_procurements,id',
            'land_selling_price'      => 'nullable|integer|min:0',
            'building_contract_price' => 'required|integer|min:0',
            'tax_rate'                => 'required|numeric|min:0|max:100',
            'is_land_cost_manual'     => 'sometimes|boolean',
            // 顧客所有地以外 かつ 手動入力ON の場合のみ土地原価必須
            'land_cost'               => [
                Rule::requiredIf(fn() =>
                    $request->input('land_source_type') !== HousingLandSourceType::CustomerLand->value
                    && $request->boolean('is_land_cost_manual')
                ),
                'nullable', 'integer', 'min:0',
            ],
            'building_cost'           => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($validated, $request, $hsCustomOrder) {
            $isManual = $request->boolean('is_land_cost_manual');
            $sourceType = $validated['land_source_type'];

            $data = [
                'customer_name'           => $validated['customer_name'],
                'customer_id'             => $validated['customer_id'] ?? null,
                'contract_date'           => $validated['contract_date'],
                'notes'                   => $validated['notes'] ?? null,
                'land_source_type'        => $sourceType,
                'building_contract_price' => $validated['building_contract_price'],
                'tax_rate'                => $validated['tax_rate'],
                'building_cost'           => $validated['building_cost'],
                'is_land_cost_manual'     => $isManual,
                'updated_by'              => auth()->id(),
            ];
            if (!empty($validated['created_by'])) {
                $data['created_by'] = $validated['created_by'];
            }

            // 土地種別に応じて関連カラムを整理
            if ($sourceType === HousingLandSourceType::CustomerLand->value) {
                // 顧客所有地: 土地関連カラムをすべてクリア
                $data['re_project_lot_id']   = null;
                $data['re_procurement_id']   = null;
                $data['land_selling_price']  = null;
                $data['land_cost']           = null;
                $data['is_land_cost_manual'] = false;
            } else {
                // 自社土地（分譲地区画 or 仕入れ案件）
                if ($sourceType === HousingLandSourceType::ProjectLot->value) {
                    $data['re_project_lot_id'] = $validated['re_project_lot_id'] ?? null;
                    $data['re_procurement_id'] = null;
                } else { // procurement
                    $data['re_procurement_id'] = $validated['re_procurement_id'] ?? null;
                    $data['re_project_lot_id'] = null;
                }
                $data['land_selling_price'] = $validated['land_selling_price'] ?? null;
                // 手動入力ON のときのみ land_cost を上書き。OFF時は既存値を維持
                if ($isManual) {
                    $data['land_cost'] = $validated['land_cost'] ?? null;
                }
            }

            $hsCustomOrder->update($data);
        });

        return redirect()
            ->route('housing.contracts.show-custom-order', $hsCustomOrder)
            ->with('success', '契約を更新しました。');
    }

    /**
     * 建売物件選択画面（建売契約新規登録の第一段階）
     * GET /housing/contracts/create/building/select-property
     *
     * 未契約の建売物件一覧から、契約を登録する物件を選択させる。
     * 選択された物件は housing.contracts.create へサブリソースとして渡される。
     */
    public function selectBuildingProperty(Request $request)
    {
        // 未契約物件のみ取得（紐づけ先の参考販売価格を計算するため projectLot / procurement を eager load）
        $query = HsProperty::whereDoesntHave('contract')
            ->with(['projectLot', 'procurement']);

        // 物件名の LIKE 検索（部分一致）
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $query->where('property_name', 'LIKE', '%' . $keyword . '%');
        }

        $properties = $query->orderBy('property_code')->paginate(20)->withQueryString();

        return view('housing.contracts.select-building-property', [
            'properties' => $properties,
            'keyword'    => $keyword,
        ]);
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * 建売契約を統一DTOに変換
     *
     * @return array<string, mixed>
     */
    private function mapTateuriToDto(HsContract $c): array
    {
        $property = $c->property;
        $sellingTotal = $c->getSellingPriceTotal();
        $costTotal = $property ? ($property->getTotalCost() ?? 0) : 0;
        $profit = $sellingTotal - $costTotal;
        $profitRate = $sellingTotal > 0 ? round(($profit / $sellingTotal) * 100, 1) : null;

        // 土地・建物別の売価（サマリー合計用）
        // HsContract のカラムは selling_price_land / selling_price_building
        $landSelling = (int) ($c->selling_price_land ?? 0);
        $buildingSelling = (int) ($c->selling_price_building ?? 0);

        // 土地・建物別の粗利
        $landProfit = $c->getLandProfit();
        $buildingProfit = $c->getBuildingProfit();
        $landProfitRate = $c->getLandProfitRate();
        $buildingProfitRate = $c->getBuildingProfitRate();
        $totalProfitRate = $c->getTotalProfitRate();

        return [
            'id'                  => $c->id,
            'type'                => 'tateuri',
            'type_label'          => '建売',
            'property_name'       => $property ? $property->property_name : '—',
            'customer_name'       => $c->customer_name ?? '—',
            'contract_date'       => $c->contract_date,
            'selling_total'       => $sellingTotal,
            'land_selling'        => $landSelling,
            'building_selling'    => $buildingSelling,
            'cost_total'          => $costTotal,
            'profit'              => $profit,
            'profit_rate'         => $profitRate,
            'land_profit'         => $landProfit ?? 0,
            'building_profit'     => $buildingProfit ?? 0,
            'land_profit_rate'    => $landProfitRate,
            'building_profit_rate'=> $buildingProfitRate,
            'total_profit_rate'   => $totalProfitRate,
            'status_label'        => '契約済',
            'status_color'        => '#047857', // emerald-700 (建売は契約成立後のみ一覧に現れる想定)
            'staff_name'          => $this->getStaffLastName($c->createdBy),
            'detail_url'          => route('housing.contracts.show-building', $c),
            'edit_url'            => route('housing.contracts.edit-building', $c),
            'original_url'        => $property ? route('housing.properties.show', $property) : null,
            'source_model'        => $c,
        ];
    }

    /**
     * 注文住宅を統一DTOに変換
     *
     * @return array<string, mixed>
     */
    private function mapCustomOrderToDto(HsCustomOrder $c): array
    {
        $sellingTotal = $c->getTotalSellingPrice() ?? 0;
        $costTotal = $c->getTotalCost() ?? 0;
        $profit = $c->getTotalProfit() ?? 0;
        $profitRate = $c->getTotalProfitRate();

        // 土地・建物別の売価（サマリー合計用）
        // HsCustomOrder のカラムは land_selling_price / building_contract_price
        // 自社土地でない場合（customer_land）は土地売価は null → 0 扱い
        $landSelling = (int) ($c->land_selling_price ?? 0);
        $buildingSelling = (int) ($c->building_contract_price ?? 0);

        // 土地・建物別の粗利
        $landProfit = $c->getLandProfit();         // 自社土地時のみ値あり
        $buildingProfit = $c->getBuildingProfit();
        $landProfitRate = $c->getLandProfitRate();
        $buildingProfitRate = $c->getBuildingProfitRate();

        // ステータスラベル・バッジ色
        $statusEnum = CustomOrderStatus::tryFrom($c->status);
        $statusLabel = $statusEnum ? $statusEnum->label() : '—';
        $statusColor = $statusEnum ? $statusEnum->badgeStyle() : '';

        return [
            'id'                  => $c->id,
            'type'                => 'custom',
            'type_label'          => '注文住宅',
            'property_name'       => $c->order_name ?? '—',
            'customer_name'       => $c->customer_name ?? '—',
            'contract_date'       => $c->contract_date,
            'selling_total'       => $sellingTotal,
            'land_selling'        => $landSelling,
            'building_selling'    => $buildingSelling,
            'cost_total'          => $costTotal,
            'profit'              => $profit,
            'profit_rate'         => $profitRate,
            'land_profit'         => $landProfit ?? 0,
            'building_profit'     => $buildingProfit ?? 0,
            'land_profit_rate'    => $landProfitRate,
            'building_profit_rate'=> $buildingProfitRate,
            'total_profit_rate'   => $profitRate, // 合計粗利率は getTotalProfitRate と同値
            'status_label'        => $statusLabel,
            'status_color'        => $statusColor,
            'staff_name'          => $this->getStaffLastName($c->createdBy),
            'detail_url'          => route('housing.contracts.show-custom-order', $c),
            'edit_url'            => route('housing.contracts.edit-custom-order', $c),
            'original_url'        => route('housing.custom-orders.show', $c),
            'source_model'        => $c,
        ];
    }

    /**
     * ユーザーの苗字を取得
     */
    private function getStaffLastName(?User $user): string
    {
        if (!$user) {
            return '—';
        }
        $parts = explode(' ', $user->name);
        return $parts[0] ?? $user->name;
    }

    /**
     * 現在の決算年度を取得（5月始まり）
     */
    private function getCurrentFiscalYear(): int
    {
        $now = now();
        return $now->month >= 5 ? $now->year : $now->year - 1;
    }

    /**
     * 年度リストを取得
     *
     * @return array<int>
     */
    private function getFiscalYearList(): array
    {
        $current = $this->getCurrentFiscalYear();

        // 建売契約の最古日
        $oldestTateuri = HsContract::whereNotNull('contract_date')->min('contract_date');
        // 注文住宅の最古日
        $oldestCustom = HsCustomOrder::whereNotNull('contract_date')
            ->whereIn('status', [
                CustomOrderStatus::Contracted->value,
                CustomOrderStatus::Construction->value,
                CustomOrderStatus::Completed->value,
                CustomOrderStatus::Delivered->value,
            ])
            ->min('contract_date');

        // 最古の日付から開始年度を計算
        $oldest = $oldestTateuri;
        if ($oldestCustom && (!$oldest || $oldestCustom < $oldest)) {
            $oldest = $oldestCustom;
        }

        $years = [$current];
        if ($oldest) {
            $oldestYear = (int) date('Y', strtotime($oldest));
            $oldestMonth = (int) date('m', strtotime($oldest));
            $startYear = $oldestMonth >= 5 ? $oldestYear : $oldestYear - 1;
            for ($y = $current - 1; $y >= $startYear; $y--) {
                $years[] = $y;
            }
        }

        return $years;
    }
}
