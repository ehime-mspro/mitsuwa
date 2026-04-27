<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * 経営ダッシュボード（経営層のみ）
     * Route: GET /dashboard/executive
     */
    public function executive()
    {
        return view('dashboard.executive');
    }

    /**
     * テナントダッシュボード
     * Route: GET /dashboard/tenant
     */
    public function tenant()
    {
        return view('dashboard.tenant');
    }
}
