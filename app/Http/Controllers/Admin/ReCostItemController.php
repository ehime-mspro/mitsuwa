<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReCostItem;
use App\Models\ReProcurementCost;
use Illuminate\Http\Request;

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
            $costItemsForJs[] = ['id' => $item->id, 'name' => $item->name];
        }

        return view('admin.master.re-cost-items.index', compact('costItems', 'costItemsForJs'));
    }

    /**
     * 新規追加
     * Route: POST /admin/master/re-cost-items
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50',
        ]);

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
        $validated = $request->validate([
            'name' => 'required|string|max:50',
        ]);

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
        // 使用中チェック（仕入れ原価明細・将来のプロジェクト原価明細）
        $inUse = ReProcurementCost::where('cost_item_id', $costItem->id)->exists();

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
