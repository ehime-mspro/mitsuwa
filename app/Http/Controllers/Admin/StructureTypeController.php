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
     *
     * テナント物件は構造を id でなく名前（properties.structure）で持つので、名前を変えたら物件の値も
     * 同じトランザクションで新しい名前に変える（変えないと物件の編集画面で構造が選ばれず、保存で空になる）。
     * 論理削除した物件も変える（使用中の確かめも論理削除した物件を数えている）。
     */
    public function update(Request $request, StructureType $structureType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $oldName = $structureType->name;
        $renamed = 0;

        DB::transaction(function () use ($structureType, $validated, $oldName, &$renamed) {
            $structureType->update($validated);

            if ($structureType->name !== $oldName) {
                $renamed = DB::table('properties')
                    ->where('structure', $oldName)
                    ->update(['structure' => $structureType->name]);
            }
        });

        $message = '「' . $structureType->name . '」を更新しました。';
        if ($renamed > 0) {
            $message .= 'この構造を使っていたテナント物件 ' . $renamed . ' 件も新しい名前に変えました。';
        }

        return redirect()
            ->route('admin.master.structure-types.index')
            ->with('success', $message);
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
