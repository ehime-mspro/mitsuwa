<?php

namespace App\Http\Controllers\RealEstate;

use App\Http\Controllers\Controller;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Services\ScheduleBoardService;
use Illuminate\Http\Request;

/**
 * 不動産の工程表ボード（設計書 §4.2）。
 *
 * ⚠ **対象クラスはここで明示的に渡す。** サービス側に既定値を置くと、
 *   新しい部署のボードを足した人が引数を省略した瞬間に全部署が漏れる（設計書 §4.3）。
 */
class ScheduleBoardController extends Controller
{
    /** 絞り込みキー => [親クラス, 画面に出す種別名] */
    private const KINDS = [
        'procurement' => [ReProcurement::class, '仕入れ'],
        'project'     => [ReProject::class, '分譲地'],
    ];

    public function index(Request $request, ScheduleBoardService $service)
    {
        return view('realestate.schedules.index', [
            'board' => $service->build(self::KINDS, $request),
        ]);
    }
}
