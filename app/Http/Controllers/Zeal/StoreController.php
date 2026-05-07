<?php

namespace App\Http\Controllers\Zeal;

use App\Http\Controllers\Controller;
use App\Models\ZealMember;
use App\Models\ZealStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ZEAL 店舗マスタ Ajax CRUD コントローラー
 *
 * 一覧はページ内管理（1画面）。追加・更新・削除はすべて Ajax で実行する。
 * Trainer マスタ (TrainerController) と同パターン。
 */
class StoreController extends Controller
{
    /**
     * 店舗一覧（マスタ管理ページ）
     */
    public function index()
    {
        $stores = ZealStore::orderBy('display_order')->orderBy('id')->get();

        // Alpine.js 用 JSON（@json() 内で関数呼び出ししないよう事前整形）
        $storesJson = $stores->map(function ($s) {
            return [
                'id'            => $s->id,
                'name'          => $s->name,
                'address'       => $s->address ?? '',
                'phone'         => $s->phone ?? '',
                'open_date'     => $s->open_date ? $s->open_date->format('Y-m-d') : '',
                'display_order' => $s->display_order,
                'active'        => (bool) $s->active,
            ];
        })->values();

        // 新規追加時のデフォルト表示順（現在の最大値 + 1）
        $nextOrder = ($stores->max('display_order') ?? 0) + 1;

        return view('zeal.stores.index', compact('storesJson', 'nextOrder'));
    }

    /**
     * 店舗追加（Ajax）
     * Route: POST /zeal/stores
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:100',
            'address'       => 'nullable|string|max:300',
            'phone'         => 'nullable|string|max:20',
            'open_date'     => 'nullable|date',
            'display_order' => 'required|integer|min:0|max:9999',
            'active'        => 'boolean',
        ]);

        $validated['active']    = $request->boolean('active', true);
        $validated['address']   = $validated['address'] ?? null;
        $validated['phone']     = $validated['phone'] ?? null;
        $validated['open_date'] = $validated['open_date'] ?? null;

        $store = ZealStore::create($validated);

        return response()->json([
            'success' => true,
            'store' => [
                'id'            => $store->id,
                'name'          => $store->name,
                'address'       => $store->address ?? '',
                'phone'         => $store->phone ?? '',
                'open_date'     => $store->open_date ? $store->open_date->format('Y-m-d') : '',
                'display_order' => $store->display_order,
                'active'        => (bool) $store->active,
            ],
            'message' => '「' . $store->name . '」を追加しました。',
        ]);
    }

    /**
     * 店舗更新（Ajax）
     * Route: PUT /zeal/stores/{store}
     */
    public function update(Request $request, ZealStore $store): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:100',
            'address'       => 'nullable|string|max:300',
            'phone'         => 'nullable|string|max:20',
            'open_date'     => 'nullable|date',
            'display_order' => 'required|integer|min:0|max:9999',
            'active'        => 'boolean',
        ]);

        $validated['active']    = $request->boolean('active');
        $validated['address']   = $validated['address'] ?? null;
        $validated['phone']     = $validated['phone'] ?? null;
        $validated['open_date'] = $validated['open_date'] ?? null;

        $store->update($validated);

        return response()->json([
            'success' => true,
            'store' => [
                'id'            => $store->id,
                'name'          => $store->name,
                'address'       => $store->address ?? '',
                'phone'         => $store->phone ?? '',
                'open_date'     => $store->open_date ? $store->open_date->format('Y-m-d') : '',
                'display_order' => $store->display_order,
                'active'        => (bool) $store->active,
            ],
            'message' => '「' . $store->name . '」を更新しました。',
        ]);
    }

    /**
     * 店舗削除（Ajax）
     * Route: DELETE /zeal/stores/{store}
     * 所属会員がいる店舗は削除不可（無効化してください）
     */
    public function destroy(ZealStore $store): JsonResponse
    {
        // 所属会員がいる場合は削除不可
        if (ZealMember::where('store_id', $store->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => '「' . $store->name . '」には所属会員がいるため削除できません。「無効」に変更してご利用ください。',
            ], 422);
        }

        $name = $store->name;
        $store->delete();

        return response()->json([
            'success' => true,
            'message' => '「' . $name . '」を削除しました。',
        ]);
    }
}
