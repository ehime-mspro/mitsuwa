<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\ContractAnalysisService;
use Illuminate\View\View;

class AnalysisController extends Controller
{
    /**
     * 契約・解約の年×月分析
     * Route: GET /tenant/analysis
     */
    public function index(ContractAnalysisService $service): View
    {
        $data = $service->build();

        return view('tenant.analysis.index', [
            'contract'    => $data['contract'],
            'termination' => $data['termination'],
        ]);
    }
}
