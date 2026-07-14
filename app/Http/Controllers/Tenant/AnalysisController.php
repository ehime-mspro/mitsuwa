<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\ContractAnalysisService;
use Illuminate\View\View;

class AnalysisController extends Controller
{
    /**
     * 契約・解約の年別・月別分析
     * Route: GET /tenant/analysis
     */
    public function index(ContractAnalysisService $service): View
    {
        $data = $service->build();

        return view('tenant.analysis.index', [
            'contract'    => $data['contract'],       // 総計バッジ・空判定に使う生集計
            'termination' => $data['termination'],
            'charts'      => [                          // Chart.js 用（Js::from で埋め込む）
                'contract'    => $this->chartPayload($data['contract']),
                'termination' => $this->chartPayload($data['termination']),
            ],
        ]);
    }

    /**
     * サービス集計を Chart.js の {labels, values} 形へ整形（年→文字列・月→「◯月」）。
     */
    private function chartPayload(array $summary): array
    {
        return [
            'year'  => [
                'labels' => array_map(fn (int $y) => (string) $y, $summary['byYear']['labels']),
                'values' => $summary['byYear']['values'],
            ],
            'month' => [
                'labels' => array_map(fn (int $m) => $m . '月', $summary['byMonth']['labels']),
                'values' => $summary['byMonth']['values'],
            ],
        ];
    }
}
