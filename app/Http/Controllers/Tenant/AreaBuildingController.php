<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\AreaTenantStatus;
use App\Http\Controllers\Controller;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Services\Tenant\AreaBuildingListService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * 周辺ビル調査(テナント管理)。
 *
 * 権限は routes/web.php 側のミドルウェアで担保する(設計 §8):
 *   閲覧 = 全ロール / 登録・編集 = role:executive,manager / 削除 = role:executive
 */
class AreaBuildingController extends Controller
{
    public function index(Request $request, AreaBuildingListService $service)
    {
        return view('tenant.area-buildings.index', [
            'rows'           => $service->paginate($request),
            'surveyYears'    => $service->surveyYears(),
            'vacancyOptions' => AreaBuildingListService::VACANCY_OPTIONS,
        ]);
    }

    public function show(AreaBuilding $building)
    {
        $surveys = $building->surveys()
            ->with('surveyor')
            ->orderByDesc('surveyed_month')
            ->orderByDesc('id')
            ->get();

        $latestSurvey = $surveys->first();

        $tenants = $building->tenants()
            ->orderByDesc('floor')
            ->orderBy('room_number')
            ->orderBy('id')
            ->get();

        $activeTenants   = $tenants->filter(fn ($t) => $t->isActive())->values();
        $movedOutTenants = $tenants->reject(fn ($t) => $t->isActive())->values();

        return view('tenant.area-buildings.show', [
            'building'        => $building,
            'surveys'         => $surveys,
            'latestSurvey'    => $latestSurvey,
            'activeTenants'   => $activeTenants,
            'movedOutTenants' => $movedOutTenants,
            'divergence'      => $this->divergence($latestSurvey, $activeTenants),
        ]);
    }

    public function create()
    {
        return view('tenant.area-buildings.create');
    }

    public function store(Request $request)
    {
        // ⚠ ルールは literal 配列で直書きする。$this->rules() のような間接参照にすると
        //   JapaneseValidationMessagesTest の走査正規表現
        //   /validate\(\s*\[(.*?)\n\s*\]\s*[,)]/s にマッチせず、このコントローラのキーが
        //   和名チェックから丸ごと外れる（2026-08-16 実測）。store と update で重複するが、
        //   既存 185 ルートも同じ書き方をしている。
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'address'      => 'nullable|string|max:255',
            'latitude'     => 'nullable|numeric|between:-90,90',
            'longitude'    => 'nullable|numeric|between:-180,180',
            'total_floors' => 'nullable|integer|min:0|max:200',
            'notes'        => 'nullable|string|max:5000',
            // 新規登録時のみ 1 回目の調査を同時に作れる（設計 §5.5）。
            // ⚠ 所見は survey_notes。ビル自身の notes と衝突するため名前を分けている
            'surveyed_month'  => 'nullable|date_format:Y-m',
            'operating_count' => 'nullable|integer|min:0|max:9999',
            'vacant_count'    => 'nullable|integer|min:0|max:9999',
            'unknown_count'   => 'nullable|integer|min:0|max:9999',
            'survey_notes'    => 'nullable|string|max:2000',
        ], [], [
            // ⚠ 第3引数が attributes（第2引数は messages）。Bug #37
            'name'    => 'ビル名',
            'address' => '所在地',
        ]);

        $building = AreaBuilding::create([
            'name'         => $validated['name'],
            'address'      => $validated['address'] ?? null,
            'latitude'     => $validated['latitude'] ?? null,
            'longitude'    => $validated['longitude'] ?? null,
            'total_floors' => $validated['total_floors'] ?? null,
            'notes'        => $validated['notes'] ?? null,
            'created_by'   => Auth::id(),
        ]);

        if (filled($validated['surveyed_month'] ?? null)) {
            AreaBuildingSurvey::create([
                'area_building_id' => $building->id,
                'surveyed_month'   => $validated['surveyed_month'] . '-01',
                // 件数欄は空欄スタート。未入力は 0 として保存する
                'operating_count'  => $validated['operating_count'] ?? 0,
                'vacant_count'     => $validated['vacant_count'] ?? 0,
                'unknown_count'    => $validated['unknown_count'] ?? 0,
                'surveyed_by'      => Auth::id(),
                'notes'            => $validated['survey_notes'] ?? null,
            ]);
        }

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', 'ビルを登録しました。');
    }

    public function edit(AreaBuilding $building)
    {
        return view('tenant.area-buildings.edit', ['building' => $building]);
    }

    public function update(Request $request, AreaBuilding $building)
    {
        // ⚠ 編集画面に調査欄は出さない(調査は履歴側で管理する。設計 §5.5)。
        //   このルールだけを通すので、調査の項目が送られてきても validated に入らない。
        // ⚠ literal 配列で直書きする理由は store() のコメントを参照
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'address'      => 'nullable|string|max:255',
            'latitude'     => 'nullable|numeric|between:-90,90',
            'longitude'    => 'nullable|numeric|between:-180,180',
            'total_floors' => 'nullable|integer|min:0|max:200',
            'notes'        => 'nullable|string|max:5000',
        ], [], [
            'name'    => 'ビル名',
            'address' => '所在地',
        ]);

        $building->update([
            'name'         => $validated['name'],
            'address'      => $validated['address'] ?? null,
            'latitude'     => $validated['latitude'] ?? null,
            'longitude'    => $validated['longitude'] ?? null,
            'total_floors' => $validated['total_floors'] ?? null,
            'notes'        => $validated['notes'] ?? null,
        ]);

        return redirect()->route('tenant.area-buildings.show', $building)
            ->with('success', 'ビル情報を更新しました。');
    }

    public function destroy(AreaBuilding $building)
    {
        // SoftDeletes。調査回とテナントは FK CASCADE だが、ビル行が残るので子も残る
        // (復元可能にするための意図どおりの挙動。設計 §8)
        $building->delete();

        return redirect()->route('tenant.area-buildings.index')
            ->with('success', 'ビルを削除しました。');
    }

    /**
     * 「調査時の実測（入力値）」と「テナント明細からの集計」の乖離（設計 §5.4 / Bug #46）。
     *
     * ⚠ 内訳と合計を別ソースのまま並べると無音で食い違う。両方を出して差があるときだけ警告する。
     * ⚠ 明細 0 行のビルでは比較しない（明細を入れていないだけで警告が出ると意味がない）。
     * ⚠ 下流の空室率は常に入力値を正とする。明細に寄せると、明細が途中までしか
     *   入っていないビルの数字が壊れる。
     *
     * @return array{input: array<string, int>, counted: array<string, int>}|null
     */
    private function divergence(?AreaBuildingSurvey $latest, Collection $activeTenants): ?array
    {
        if ($latest === null || $activeTenants->isEmpty()) {
            return null;
        }

        $input = [
            'operating' => $latest->operating_count,
            'vacant'    => $latest->vacant_count,
            'unknown'   => $latest->unknown_count,
        ];

        // ⚠ status はキャスト済みなので enum インスタンス。tryFrom() を呼ばない（Bug #22）
        $counted = [
            'operating' => $activeTenants->filter(fn ($t) => $t->status === AreaTenantStatus::Operating)->count(),
            'vacant'    => $activeTenants->filter(fn ($t) => $t->status === AreaTenantStatus::Vacant)->count(),
            'unknown'   => $activeTenants->filter(fn ($t) => $t->status === AreaTenantStatus::Unknown)->count(),
        ];

        return $input === $counted ? null : ['input' => $input, 'counted' => $counted];
    }
}
