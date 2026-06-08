<?php

namespace App\Http\Controllers\Housing;

use App\Enums\LotStatus;
use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\HsContract;
use App\Models\HsProperty;
use App\Support\Settings;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    /**
     * 契約登録フォーム
     * GET /housing/properties/{property}/contract/create
     */
    public function create(HsProperty $property)
    {
        // 既に契約が存在する場合はリダイレクト
        if ($property->contract) {
            return redirect()
                ->route('housing.properties.show', $property)
                ->with('error', 'この物件は既に契約済みです。');
        }

        $property->load(['projectLot.project', 'procurement.costs']);

        // デフォルト値の準備
        $defaults = $this->getContractDefaults($property);

        // デフォルト消費税率（システム設定から取得、なければ10.00）
        $defaultTaxRate = $this->getDefaultTaxRate();

        // 買主マスタ（住宅事業所属）
        $buyers = Buyer::ofDepartment('housing')->orderBy('last_name_kana')->get();

        return view('housing.contracts.create', compact('property', 'defaults', 'defaultTaxRate', 'buyers'));
    }

    /**
     * 契約保存
     * POST /housing/properties/{property}/contract
     */
    public function store(Request $request, HsProperty $property)
    {
        // 既に契約が存在する場合はエラー
        if ($property->contract) {
            return redirect()
                ->route('housing.properties.show', $property)
                ->with('error', 'この物件は既に契約済みです。');
        }

        $validated = $this->validateContract($request);

        // フェーズ2: 買主マスタ紐付けを必須化し、customer_name を買主名で上書き
        $request->validate([
            'customer_id' => ['required', 'integer', 'exists:buyers,id'],
        ]);
        $buyer = Buyer::withTrashed()->findOrFail($request->integer('customer_id'));
        $validated['customer_id']   = $buyer->id;
        $validated['customer_name'] = $buyer->full_name;

        $validated['property_id'] = $property->id;
        $validated['created_by'] = auth()->id();

        $contract = HsContract::create($validated);

        // 分譲地区画のステータスを「成約」に自動更新
        $this->updateLotStatusOnSold($property);

        return redirect()
            ->route('housing.properties.show', $property)
            ->with('success', '契約を登録しました。物件のステータスが「成約」に更新されました。');
    }

    /**
     * 契約編集フォーム
     * GET /housing/properties/{property}/contract/edit
     */
    public function edit(HsProperty $property)
    {
        $contract = $property->contract;
        if (!$contract) {
            return redirect()
                ->route('housing.properties.show', $property)
                ->with('error', '契約が登録されていません。');
        }

        $property->load(['projectLot.project', 'procurement']);

        return view('housing.contracts.edit', compact('property', 'contract'));
    }

    /**
     * 契約更新
     * PUT /housing/properties/{property}/contract
     */
    public function update(Request $request, HsProperty $property)
    {
        $contract = $property->contract;
        if (!$contract) {
            return redirect()
                ->route('housing.properties.show', $property)
                ->with('error', '契約が登録されていません。');
        }

        $validated = $this->validateContract($request);
        $validated['updated_by'] = auth()->id();

        $contract->update($validated);

        return redirect()
            ->route('housing.properties.show', $property)
            ->with('success', '契約情報を更新しました。');
    }

    /**
     * 契約削除
     * DELETE /housing/properties/{property}/contract
     */
    public function destroy(HsProperty $property)
    {
        $contract = $property->contract;
        if (!$contract) {
            return redirect()
                ->route('housing.properties.show', $property)
                ->with('error', '契約が登録されていません。');
        }

        $contract->delete();

        // 分譲地区画のステータスを「販売中」に戻す
        $this->updateLotStatusOnUnsold($property);

        return redirect()
            ->route('housing.properties.show', $property)
            ->with('success', '契約を削除しました。物件のステータスが「販売中」に戻りました。');
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * バリデーション
     */
    private function validateContract(Request $request): array
    {
        return $request->validate([
            'customer_name'          => 'required|string|max:100',
            'selling_price_land'     => 'required|integer|min:0',
            'selling_price_building' => 'required|integer|min:0',
            'tax_rate'               => 'required|numeric|min:0|max:100',
            'contract_date'          => 'required|date',
            'settlement_date'        => 'nullable|date',
            'notes'                  => 'nullable|string|max:5000',
        ]);
    }

    /**
     * 契約登録時のデフォルト値を取得
     */
    private function getContractDefaults(HsProperty $property): array
    {
        $defaults = [
            'selling_price_land'     => null,
            'selling_price_building' => $property->target_selling_price_building,
            'land_source_label'      => null,
            'building_source_label'  => '建物予定販売価格',
        ];

        // 土地販売価格のデフォルト: 紐づけ先から取得
        $refPrice = $property->getReferenceLandSellingPrice();
        if ($refPrice !== null) {
            $defaults['selling_price_land'] = $refPrice;
        }

        // 取得元ラベル
        if ($property->land_source_type !== null) {
            $sourceDisplay = $property->getLandSourceDisplay();
            if ($sourceDisplay) {
                $defaults['land_source_label'] = $sourceDisplay . 'の販売価格';
            }
        }

        // 建物予定販売価格が未設定の場合は建築費をフォールバック
        if ($defaults['selling_price_building'] === null && $property->building_cost !== null) {
            $defaults['selling_price_building'] = $property->building_cost;
            $defaults['building_source_label'] = '建築費（原価）';
        }

        return $defaults;
    }

    /**
     * デフォルト消費税率を取得
     */
    private function getDefaultTaxRate(): string
    {
        // Settings ヘルパー経由で取得。テーブル不在 / 取得失敗時は内部で 10.0 を返す。
        // view 側は '10.00' のような小数2桁文字列を想定しているため number_format で整形。
        return number_format(Settings::taxRate(), 2, '.', '');
    }

    /**
     * 契約登録時: 分譲地区画を「成約」に更新
     */
    private function updateLotStatusOnSold(HsProperty $property): void
    {
        if ($property->re_project_lot_id) {
            $lot = $property->projectLot;
            if ($lot) {
                $lot->update(['status' => LotStatus::Sold->value]);
            }
        }
    }

    /**
     * 契約削除時: 分譲地区画を「販売中」に戻す
     */
    private function updateLotStatusOnUnsold(HsProperty $property): void
    {
        if ($property->re_project_lot_id) {
            $lot = $property->projectLot;
            if ($lot) {
                $lot->update(['status' => LotStatus::OnSale->value]);
            }
        }
    }
}
