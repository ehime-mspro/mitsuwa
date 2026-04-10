<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ZoningType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 用途地域マスター管理コントローラー
 * 不動産の用途地域を管理する
 */
class ZoningTypeController extends Controller
{
    /**
     * 一覧表示
     * Route: GET /admin/master/zoning-types
     */
    public function index()
    {
        $zoningTypes = ZoningType::orderBy('sort_order')->get();

        // Alpine.js用: @json()内でfn()を使わないよう事前整形
        $zoningTypesForJs = [];
        foreach ($zoningTypes as $zt) {
            $zoningTypesForJs[] = ['id' => $zt->id, 'name' => $zt->name];
        }

        return view('admin.master.zoning-types.index', compact('zoningTypes', 'zoningTypesForJs'));
    }

    /**
     * 新規追加
     * Route: POST /admin/master/zoning-types
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        // sort_order: 現在の最大値 + 1
        $maxOrder = ZoningType::max('sort_order') ?? 0;
        $validated['sort_order'] = $maxOrder + 1;

        ZoningType::create($validated);

        return redirect()
            ->route('admin.master.zoning-types.index')
            ->with('success', '「' . $validated['name'] . '」を追加しました。');
    }

    /**
     * 用途地域名更新
     * Route: PUT /admin/master/zoning-types/{zoningType}
     */
    public function update(Request $request, ZoningType $zoningType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $zoningType->update($validated);

        return redirect()
            ->route('admin.master.zoning-types.index')
            ->with('success', '「' . $zoningType->name . '」を更新しました。');
    }

    /**
     * 削除
     * Route: DELETE /admin/master/zoning-types/{zoningType}
     */
    public function destroy(ZoningType $zoningType)
    {
        // 不動産案件で使用中か確認（仕入れ・分譲地PJ）
        $inUse = DB::table('re_procurements')
            ->where('zoning', $zoningType->name)
            ->exists()
            || DB::table('re_projects')
            ->where('zoning', $zoningType->name)
            ->exists();

        if ($inUse) {
            return redirect()
                ->route('admin.master.zoning-types.index')
                ->with('error', '「' . $zoningType->name . '」は不動産案件で使用されているため削除できません。');
        }

        $name = $zoningType->name;
        $zoningType->delete();

        return redirect()
            ->route('admin.master.zoning-types.index')
            ->with('success', '「' . $name . '」を削除しました。');
    }

    /**
     * 並替え保存（Ajax）
     * Route: POST /admin/master/zoning-types/reorder
     */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'required|integer|exists:zoning_types,id',
        ]);

        foreach ($validated['ids'] as $index => $id) {
            ZoningType::where('id', $id)->update(['sort_order' => $index + 1]);
        }

        return response()->json(['success' => true]);
    }
}
