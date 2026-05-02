<?php

namespace App\Http\Controllers\Zeal;

use App\Http\Controllers\Controller;
use App\Models\ZealMember;
use App\Models\ZealTrainer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ZEAL トレーナーマスタ Ajax CRUD コントローラー
 *
 * 一覧はページ内管理（1画面）。追加・更新・削除はすべて Ajax で実行する。
 */
class TrainerController extends Controller
{
    /**
     * トレーナー一覧（マスタ管理ページ）
     */
    public function index()
    {
        $trainers = ZealTrainer::orderBy('display_order')->orderBy('id')->get();

        // Alpine.js 用 JSON（@json() 内で関数呼び出しをしないよう事前整形）
        $trainersJson = $trainers->map(function ($t) {
            return [
                'id'            => $t->id,
                'name'          => $t->name,
                'display_order' => $t->display_order,
                'active'        => (bool) $t->active,
            ];
        })->values();

        // 新規追加時のデフォルト表示順（現在の最大値 + 1）
        $nextOrder = ($trainers->max('display_order') ?? 0) + 1;

        return view('zeal.trainers.index', compact('trainersJson', 'nextOrder'));
    }

    /**
     * トレーナー追加（Ajax）
     * Route: POST /zeal/trainers
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:100',
            'display_order' => 'required|integer|min:0|max:9999',
            'active'        => 'boolean',
        ]);

        $validated['active'] = $request->boolean('active', true);

        $trainer = ZealTrainer::create($validated);

        return response()->json([
            'success' => true,
            'trainer' => [
                'id'            => $trainer->id,
                'name'          => $trainer->name,
                'display_order' => $trainer->display_order,
                'active'        => (bool) $trainer->active,
            ],
            'message' => '「' . $trainer->name . '」を追加しました。',
        ]);
    }

    /**
     * トレーナー更新（Ajax）
     * Route: PUT /zeal/trainers/{trainer}
     */
    public function update(Request $request, ZealTrainer $trainer): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:100',
            'display_order' => 'required|integer|min:0|max:9999',
            'active'        => 'boolean',
        ]);

        $validated['active'] = $request->boolean('active');

        $trainer->update($validated);

        return response()->json([
            'success' => true,
            'trainer' => [
                'id'            => $trainer->id,
                'name'          => $trainer->name,
                'display_order' => $trainer->display_order,
                'active'        => (bool) $trainer->active,
            ],
            'message' => '「' . $trainer->name . '」を更新しました。',
        ]);
    }

    /**
     * トレーナー削除（Ajax）
     * Route: DELETE /zeal/trainers/{trainer}
     * 担当会員がいるトレーナーは削除不可（無効化してください）
     */
    public function destroy(ZealTrainer $trainer): JsonResponse
    {
        // 担当会員がいる場合は削除不可
        if (ZealMember::where('trainer_id', $trainer->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => '「' . $trainer->name . '」には担当会員がいるため削除できません。「無効」に変更してご利用ください。',
            ], 422);
        }

        $name = $trainer->name;
        $trainer->delete();

        return response()->json([
            'success' => true,
            'message' => '「' . $name . '」を削除しました。',
        ]);
    }
}
