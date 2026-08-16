<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\AreaBuildingListService;
use Illuminate\Http\Request;

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
}
