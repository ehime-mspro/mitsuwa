<?php

namespace App\Http\Controllers\Mansion;

use App\Enums\MsRoomStatus;
use App\Enums\MsParkingStatus;
use App\Http\Controllers\Controller;
use App\Models\MsContract;
use App\Models\MsParkingContract;
use App\Models\MsProperty;
use App\Models\MsRoom;
use App\Models\MsParking;

/**
 * 賃貸マンションダッシュボードコントローラー。
 * 部屋・駐車場・契約の集計を 1 クエリあたり 1 回ずつでまとめ、
 * KPI カード・物件別稼働状況・空室/空き駐車場カード一覧のための
 * ビューモデルを提供する。
 */
class DashboardController extends Controller
{
    /**
     * 賃貸マンションダッシュボード。
     * 部屋 KPI（総戸数 / 入居中 / 空室 / 申込 / 退去予定）・入居率・物件別稼働率・
     * 空室一覧・空き駐車場一覧を集約して返す。
     */
    public function index()
    {
        // 部屋 KPI
        $totalRooms = MsRoom::count();
        $occupiedRooms = MsRoom::where('status', MsRoomStatus::Occupied->value)->count();
        $vacantRooms = MsRoom::where('status', MsRoomStatus::Vacant->value)->count();
        $negotiatingRooms = MsRoom::where('status', MsRoomStatus::Negotiating->value)->count();
        $moveOutPlanned = MsRoom::where('status', MsRoomStatus::MoveOutPlanned->value)->count();
        $occupancyRate = $totalRooms > 0 ? round($occupiedRooms / $totalRooms * 100, 1) : 0;

        // 物件別稼働状況（物件ごとの総戸数 / 入居戸数 / 稼働率）
        $properties = MsProperty::with('rooms')->orderBy('property_code')->get()->map(function ($p) {
            $total = $p->rooms->count();
            $occupied = $p->rooms->where('status', MsRoomStatus::Occupied)->count();
            $p->occupancy = $total > 0 ? round($occupied / $total * 100, 1) : 0;
            $p->total_rooms = $total;
            $p->occupied_rooms = $occupied;
            return $p;
        });

        // 空室一覧（vacant + negotiating を対象。物件 → 部屋番号の順でソート）
        $vacantList = MsRoom::with('property')
            ->whereIn('status', [MsRoomStatus::Vacant->value, MsRoomStatus::Negotiating->value])
            ->orderBy('property_id')
            ->orderBy('room_number')
            ->get();

        // 空き駐車場一覧
        $vacantParkings = MsParking::with('property')
            ->where('status', MsParkingStatus::Vacant->value)
            ->orderBy('property_id')
            ->orderBy('parking_number')
            ->get();

        return view('mansion.dashboard', compact(
            'totalRooms', 'occupiedRooms', 'vacantRooms', 'negotiatingRooms',
            'moveOutPlanned', 'occupancyRate', 'properties', 'vacantList', 'vacantParkings'
        ));
    }
}
