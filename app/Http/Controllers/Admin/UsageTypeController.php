<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InquiryUsageType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UsageTypeController extends Controller
{
    /**
     * 一覧表示
     * Route: GET /admin/master/usage-types
     */
    public function index()
    {
        $usageTypes = InquiryUsageType::orderBy('sort_order')->get();

        // Alpine.js用: @json()内でfn()を使わないよう事前整形
        $usageTypesForJs = [];
        foreach ($usageTypes as $ut) {
            $usageTypesForJs[] = ['id' => $ut->id, 'name' => $ut->name];
        }

        return view('admin.master.usage-types.index', compact('usageTypes', 'usageTypesForJs'));
    }

    /**
     * 新規追加
     * Route: POST /admin/master/usage-types
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        // sort_order: 現在の最大値 + 1
        $maxOrder = InquiryUsageType::max('sort_order') ?? 0;
        $validated['sort_order'] = $maxOrder + 1;

        InquiryUsageType::create($validated);

        return redirect()
            ->route('admin.master.usage-types.index')
            ->with('success', '「' . $validated['name'] . '」を追加しました。');
    }

    /**
     * 用途名更新
     * Route: PUT /admin/master/usage-types/{usageType}
     */
    public function update(Request $request, InquiryUsageType $usageType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $usageType->update($validated);

        return redirect()
            ->route('admin.master.usage-types.index')
            ->with('success', '「' . $usageType->name . '」を更新しました。');
    }

    /**
     * 削除
     * Route: DELETE /admin/master/usage-types/{usageType}
     */
    public function destroy(InquiryUsageType $usageType)
    {
        // 問合せで使用中か確認
        $inUse = DB::table('inquiries')
            ->where('desired_usage_id', $usageType->id)
            ->exists();

        if ($inUse) {
            return redirect()
                ->route('admin.master.usage-types.index')
                ->with('error', '「' . $usageType->name . '」は問合せで使用されているため削除できません。');
        }

        $name = $usageType->name;
        $usageType->delete();

        return redirect()
            ->route('admin.master.usage-types.index')
            ->with('success', '「' . $name . '」を削除しました。');
    }

    /**
     * 並替え保存（Ajax）
     * Route: POST /admin/master/usage-types/reorder
     */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'required|integer|exists:inquiry_usage_types,id',
        ]);

        foreach ($validated['ids'] as $index => $id) {
            InquiryUsageType::where('id', $id)->update(['sort_order' => $index + 1]);
        }

        return response()->json(['success' => true]);
    }
}
