<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReCostItem;
use App\Models\ReProcurementCost;
use App\Models\ReProjectCost;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReCostItemController extends Controller
{
    /**
     * 一覧表示
     * Route: GET /admin/master/re-cost-items
     */
    public function index()
    {
        $costItems = ReCostItem::ordered()->get();

        // Alpine.js用: @json()内でfn()を使わないよう事前整形
        $costItemsForJs = [];
        foreach ($costItems as $item) {
            // locked: 購入価格から自動で計上する項目（物件購入費）。一覧は編集・削除のボタンを出さない
            $costItemsForJs[] = ['id' => $item->id, 'name' => $item->name, 'locked' => $item->isPropertyPurchase()];
        }

        return view('admin.master.re-cost-items.index', compact('costItems', 'costItemsForJs'));
    }

    /**
     * 新規追加
     * Route: POST /admin/master/re-cost-items
     */
    public function store(Request $request)
    {
        $validated = $request->validate($this->nameRules(), $this->nameMessages());

        $maxOrder = ReCostItem::max('sort_order') ?? 0;
        $validated['sort_order'] = $maxOrder + 1;

        ReCostItem::create($validated);

        return redirect()
            ->route('admin.master.re-cost-items.index')
            ->with('success', '「' . $validated['name'] . '」を追加しました。');
    }

    /**
     * 更新
     * Route: PUT /admin/master/re-cost-items/{costItem}
     */
    public function update(Request $request, ReCostItem $costItem)
    {
        if ($costItem->isPropertyPurchase()) {
            return redirect()
                ->route('admin.master.re-cost-items.index')
                ->with('error', '「' . ReCostItem::PROPERTY_PURCHASE . '」は仕入れ案件・分譲地の購入価格から自動で計上する項目のため、名前を変更できません。');
        }

        $validated = $request->validate($this->nameRules(), $this->nameMessages());

        $costItem->update($validated);

        return redirect()
            ->route('admin.master.re-cost-items.index')
            ->with('success', '「' . $costItem->name . '」を更新しました。');
    }

    /**
     * 削除
     * Route: DELETE /admin/master/re-cost-items/{costItem}
     */
    public function destroy(ReCostItem $costItem)
    {
        if ($costItem->isPropertyPurchase()) {
            return redirect()
                ->route('admin.master.re-cost-items.index')
                ->with('error', '「' . ReCostItem::PROPERTY_PURCHASE . '」は仕入れ案件・分譲地の購入価格から自動で計上する項目のため、削除できません。');
        }

        // 使用中チェック（仕入れ案件・分譲地の原価明細）。
        // ⚠ 本番は両方の明細に外部キー（ON DELETE の指定なし）があり、見落とすと削除が 500 になる
        $inUse = ReProcurementCost::where('cost_item_id', $costItem->id)->exists()
            || ReProjectCost::where('cost_item_id', $costItem->id)->exists();

        if ($inUse) {
            return redirect()
                ->route('admin.master.re-cost-items.index')
                ->with('error', '「' . $costItem->name . '」は原価明細で使用されているため削除できません。');
        }

        $name = $costItem->name;
        $costItem->delete();

        return redirect()
            ->route('admin.master.re-cost-items.index')
            ->with('success', '「' . $name . '」を削除しました。');
    }

    /**
     * 名前の入力チェック（追加・変更）。「物件購入費」はほかの項目に使わせない（同期が名前で引くので、
     * 同じ名前が 2 つあるとどちらに計上するか決まらない）。
     */
    private function nameRules(): array
    {
        return ['name' => ['required', 'string', 'max:50', Rule::notIn([ReCostItem::PROPERTY_PURCHASE])]];
    }

    private function nameMessages(): array
    {
        return ['name.not_in' => '「' . ReCostItem::PROPERTY_PURCHASE . '」は自動で計上する項目の名前のため、ほかの項目には使えません。'];
    }

    /**
     * 並替え保存（Ajax）
     * Route: POST /admin/master/re-cost-items/reorder
     */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'required|integer|exists:re_cost_items,id',
        ]);

        foreach ($validated['ids'] as $index => $id) {
            ReCostItem::where('id', $id)->update(['sort_order' => $index + 1]);
        }

        return response()->json(['success' => true]);
    }
}
