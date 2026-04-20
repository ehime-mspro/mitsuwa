<?php

namespace App\Http\Controllers\Mansion;

use App\Enums\MsOwnershipType;
use App\Http\Controllers\Controller;
use App\Models\MsProperty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 賃貸マンション物件 CRUD コントローラー。
 * 一覧／詳細は全ロール閲覧可、登録・更新は経営層＋管理者、削除は経営層のみ（ルート側で制御）。
 */
class PropertyController extends Controller
{
    /**
     * 物件一覧（フィルター・ページネーション付き）。
     */
    public function index(Request $request)
    {
        $query = MsProperty::query()->withCount('rooms');

        // 所有形態フィルター
        if ($request->filled('ownership_type')) {
            $query->where('ownership_type', $request->ownership_type);
        }
        // キーワード検索（物件名部分一致）
        if ($request->filled('keyword')) {
            $query->where('property_name', 'like', '%' . $request->keyword . '%');
        }

        $properties = $query->orderBy('property_code')->paginate(20)->withQueryString();

        return view('mansion.properties.index', [
            'properties' => $properties,
            'ownershipTypes' => MsOwnershipType::cases(),
        ]);
    }

    /**
     * 物件登録画面。物件番号は自動採番。
     */
    public function create()
    {
        return view('mansion.properties.create', [
            'ownershipTypes' => MsOwnershipType::cases(),
            'nextCode' => $this->generateNextCode(),
        ]);
    }

    /**
     * 物件登録処理。物件番号を自動生成して保存。
     */
    public function store(Request $request)
    {
        $validated = $this->validateInput($request);
        $validated['property_code'] = $this->generateNextCode();
        $validated['created_by'] = Auth::id();

        $property = MsProperty::create($validated);

        return redirect()->route('mansion.properties.show', $property)
            ->with('success', '物件を登録しました');
    }

    /**
     * 物件詳細（部屋・駐車場・現契約入居者まで eager load）。
     */
    public function show(MsProperty $property)
    {
        $property->load([
            'rooms.activeContract.tenant',
            'parkings.activeContract.tenant',
        ]);

        return view('mansion.properties.show', compact('property'));
    }

    /**
     * 物件編集画面。
     */
    public function edit(MsProperty $property)
    {
        return view('mansion.properties.edit', [
            'property' => $property,
            'ownershipTypes' => MsOwnershipType::cases(),
        ]);
    }

    /**
     * 物件更新処理。
     */
    public function update(Request $request, MsProperty $property)
    {
        $validated = $this->validateInput($request);
        $validated['updated_by'] = Auth::id();
        $property->update($validated);

        return redirect()->route('mansion.properties.show', $property)
            ->with('success', '物件を更新しました');
    }

    /**
     * 物件削除。FK CASCADE により部屋・駐車場も連動削除される点に注意。
     */
    public function destroy(MsProperty $property)
    {
        $property->delete();
        return redirect()->route('mansion.properties.index')
            ->with('success', '物件を削除しました');
    }

    /**
     * 登録・更新共通バリデーション。
     */
    private function validateInput(Request $request): array
    {
        return $request->validate([
            'property_name' => 'required|string|max:100',
            'ownership_type' => 'required|in:self_owned,managed',
            'owner_name' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:10',
            'address' => 'required|string|max:200',
            'total_units' => 'nullable|integer|min:0',
            'total_floors' => 'nullable|integer|min:0',
            'structure' => 'nullable|string|max:50',
            'built_year_month' => 'nullable|string|max:7',
            'notes' => 'nullable|string',
        ]);
    }

    /**
     * 物件番号（MS-NNN）の自動採番。既存最大 ID のコードに +1。
     */
    private function generateNextCode(): string
    {
        $last = MsProperty::orderByDesc('id')->first();
        $next = $last ? ((int) substr($last->property_code, 3)) + 1 : 1;
        return sprintf('MS-%03d', $next);
    }
}
