<?php

namespace App\Http\Controllers\Housing;

use App\Enums\LotStatus;
use App\Models\ReProcurementLot;
use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\HsContract;
use App\Models\HsProperty;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

        $property->load(['projectLot.project', 'procurement.costs', 'procurementLot']);

        // デフォルト値の準備
        $defaults = $this->getContractDefaults($property);

        // デフォルト消費税率（システム設定から取得、なければ10.00）
        $defaultTaxRate = $this->getDefaultTaxRate();

        // 買主の選択肢（住宅事業の買主。保存の入力チェックも同じ集合＝Buyer::selectableForHousing()）
        $buyers = Buyer::selectableForHousing();

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

        // フェーズ2: 買主マスタ紐付けを必須化し、customer_name を買主名で上書き（customer_id は validateContract() が見る）
        $validated = $this->validateContract($request);
        $buyer = Buyer::withTrashed()->findOrFail($request->integer('customer_id'));
        $validated['customer_id']   = $buyer->id;
        $validated['customer_name'] = $buyer->full_name;

        $validated['property_id'] = $property->id;
        $validated['created_by'] = auth()->id();

        // 契約の保存（買主のランクを含む）と区画を成約にすることは 1 つのトランザクション（区画を成約にする途中で落ちたら
        // 契約も残さない。残すと区画が販売中のまま＝分譲地の区画はほかに選べてしまう。docs/RULES.md Bug #148）
        DB::transaction(function () use ($validated, $property) {
            HsContract::create($validated);

            // 分譲地区画・土地の区画のステータスを「成約」に自動更新
            $this->updateLotStatusOnSold($property);
        });

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

        // 買主の選択肢（住宅事業の買主＋今の買主〈削除済み・住宅事業にいなくても〉。CLAUDE.md の
        // 「edit 画面では現在の buyer を必ず含める」規約・Bug #12。保存の入力チェックも同じ集合）
        $buyers = Buyer::selectableForHousing($contract->customer_id);

        return view('housing.contracts.edit', compact('property', 'contract', 'buyers'));
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

        // 買主マスタ紐付けを必須化し、customer_name を買主名で上書きする（store() と同じ。customer_id は validateContract() が見る）。
        // ⚠ ここが無いと customer_name だけが上書きされて customer_id と食い違う。
        //    修正前はこの画面だけフリーテキスト＋テナント事業の API を叩いていた。
        // 今の買主は削除済み・住宅事業にいなくても選べる（画面の選択肢と同じ。Bug #149）
        $validated = $this->validateContract($request, $contract);
        $buyer = Buyer::withTrashed()->findOrFail($request->integer('customer_id'));
        $validated['customer_id']   = $buyer->id;
        $validated['customer_name'] = $buyer->full_name;

        $validated['updated_by'] = auth()->id();

        // 契約の保存と、保存のフック（HsContract::saved）が新しい買主のランクを成約にすることは 1 つのトランザクション
        // （ランクで落ちたら契約の買主も変えない。前は契約だけが新しい買主で残り、再送してもランクが成約にならなかった。Bug #148 の追記）
        DB::transaction(fn () => $contract->update($validated));

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

        // 契約の削除と区画を戻すことは 1 つのトランザクション（区画を戻す途中で落ちたら契約も消さない。
        // 消したままだと区画が「成約」のまま残る。docs/RULES.md Bug #147 の追記）
        DB::transaction(function () use ($contract, $property) {
            $contract->delete();

            // 分譲地区画・土地の区画のステータスを「販売中」に戻す（建売の削除と同じ処理。HsProperty::releaseLots()）
            $property->releaseLots();
        });

        return redirect()
            ->route('housing.properties.show', $property)
            ->with('success', '契約を削除しました。物件のステータスが「販売中」に戻りました。');
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * バリデーション
     *
     * $current は編集する契約（登録は null）。買主は画面の選択肢（登録は住宅事業の買主・編集は＋今の買主）だけを受け付ける
     * （手で番号を書き換えた送信で、ほかの部署・削除済みの買主を紐付けさせない。判断待ち D・案 A。docs/RULES.md Bug #149）。
     */
    private function validateContract(Request $request, ?HsContract $current = null): array
    {
        return $request->validate([
            // 買主（入力チェックを 1 回にまとめる（2 回に分けると、1 回目で断ったときに 2 回目の欄の配列が控えに残り、戻った画面が 500 になる。Bug #121））
            'customer_id'            => ['required', 'integer', Rule::in(Buyer::selectableForHousing($current?->customer_id)->pluck('id')->all())],
            'customer_name'          => 'required|string|max:100',
            // 金額の列は符号付き INT・消費税率は DECIMAL(4,2)（本番の MySQL は範囲外で 500。H4 / Bug #73）
            'selling_price_land'     => 'required|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'selling_price_building' => 'required|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'tax_rate'               => 'required|numeric|min:0|max:99.99',
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
     * 契約登録時: 分譲地区画・土地の区画を「成約」に更新
     */
    private function updateLotStatusOnSold(HsProperty $property): void
    {
        // 土地の仕入れ案件の区画: その建売の区画だけを成約にし、仕入れ案件のステータスを区画から集約する（設計書 §5.4）。
        // 区画の無い建売（区画の無い土地・#21 の形）は何もしない
        ReProcurementLot::markSold($property->re_procurement_lot_id ? (int) $property->re_procurement_lot_id : null);

        if ($property->re_project_lot_id) {
            $lot = $property->projectLot;
            if ($lot) {
                $lot->update(['status' => LotStatus::Sold->value]);
                $lot->project?->syncStatusFromLots();
            }
        }
    }
}
