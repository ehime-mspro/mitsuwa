<?php

namespace App\Http\Controllers\Housing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * 住宅事業ダッシュボード（建売 + 注文住宅 成約フォーカス）
 * BACKLOG 優先度3: spec docs/superpowers/specs/2026-04-27-housing-cross-list-design.md
 */
class HousingDashboardController extends Controller
{
    /**
     * GET /housing
     * 住宅事業ダッシュボードを表示する
     */
    public function index(Request $request)
    {
        // Phase 2 仮実装: 空データを渡してビューを返す
        $now = now();
        return view('housing.dashboard', [
            'fiscalYear' => (string) ($now->month >= 5 ? $now->year : $now->year - 1),
            'period' => 'all',
            'fiscalYearOptions' => [],
            'kpi' => null,
            'monthly' => null,
            'paginated' => null,
            'request' => $request,
        ]);
    }
}
