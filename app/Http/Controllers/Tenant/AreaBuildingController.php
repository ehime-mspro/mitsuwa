<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\AreaTenantStatus;
use App\Http\Controllers\Controller;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Services\Tenant\AreaBuildingListService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
