<?php

namespace App\Http\Controllers\Housing;

use App\Http\Controllers\Controller;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Services\ScheduleBoardService;
use Illuminate\Http\Request;

/**
 * 住宅事業の工程表ボード（設計書 §4.2）。⚠ 不動産側と対称に保つこと。
 */
class ScheduleBoardController extends Controller
{
    /** 絞り込みキー => [親クラス, 画面に出す種別名] */
    private const KINDS = [
        'property'    => [HsProperty::class, '建売'],
        'customOrder' => [HsCustomOrder::class, '注文住宅'],
    ];

    public function index(Request $request, ScheduleBoardService $service)
    {
        return view('housing.schedules.index', [
            'board' => $service->build(self::KINDS, $request),
        ]);
    }
}
