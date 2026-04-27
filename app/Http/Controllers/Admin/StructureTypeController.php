<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StructureType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 構造マスター管理コントローラー
 * テナント物件の構造種別を管理する
 */
class StructureTypeController extends Controller
{
    /**
     * 一覧表示
     * Route: GET /admin/master/structure-types
     */
    public function index()
    {
        $structureTypes = StructureType::orderBy('sort_order')->get();

        // Alpine.js用: @json()内でfn()を使わないよう事前整形
        $structureTypesForJs = [];
        foreach ($structureTypes as $st) {
            $structureTypesForJs[] = ['id' => $st->id, 'name' => $st->name];
        }

        return view('admin.master.structure-types.index', compact('structureTypes', 'structureTypesForJs'));
    }

    /**
     * 新規追加
     * Route: POST /admin/master/structure-types
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        // sort_order: 現在の最大値 + 1
        $maxOrder = StructureType::max('sort_order') ?? 0;
        $validated['sort_order'] = $maxOrder + 1;

        StructureType::create($validated);

        return redirect()
            ->route('admin.master.structure-types.index')
            ->with('success', '「' . $validated['name'] . '」を追加しました。');
    }

    /**
     * 構造名更新
     * Route: PUT /admin/master/structure-types/{structureType}
     */
    public function update(Request $request, StructureType $structureType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $structureType->update($validated);

        return redirect()
            ->route('admin.master.structure-types.index')
            ->with('success', '「' . $structureType->name . '」を更新しました。');
    }

    /**
     * 削除
     * Route: DELETE /admin/master/structure-types/{structureType}
     */
    public function destroy(StructureType $structureType)
    {
        // テナント物件で使用中か確認
        $inUse = DB::table('properties')
            ->where('structure', $structureType->name)
            ->exists();

        if ($inUse) {
            return redirect()
                ->route('admin.master.structure-types.index')
                ->with('error', '「' . $structureType->name . '」はテナント物件で使用されているため削除できません。');
        }

        $name = $structureType->name;
        $structureType->delete();

        return redirect()
            ->route('admin.master.structure-types.index')
            ->with('success', '「' . $name . '」を削除しました。');
    }

    /**
     * 並替え保存（Ajax）
     * Route: POST /admin/master/structure-types/reorder
     */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'required|integer|exists:structure_types,id',
        ]);

        foreach ($validated['ids'] as $index => $id) {
            StructureType::where('id', $id)->update(['sort_order' => $index + 1]);
        }

        return response()->json(['success' => true]);
    }
}
