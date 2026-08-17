{{-- 経営ダッシュボード: Chart.js 4 グラフ初期化スクリプト --}}
@if($monthly !== null)
{{-- ⚠ `.min.js` に戻さないこと。npm に実在せず jsDelivr の動的生成物で、そのバナー自身が
     「Do NOT use SRI with dynamically generated files!」と警告する。値は CdnScriptIntegrityTest::PINNED_SRI で固定 --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"
        integrity="sha384-dug+JxfBvklEQdJ4AYuBBAIScUz0bVN73xpy273gcAwHjb3qI0fXmuYNaNfdyYJG"
        crossorigin="anonymous"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;

    const monthly = @json($monthly);
    const labels  = monthly.labels;

    const commonScaleX = () => ({
        ticks: { font: { size: 12 }, color: '#9ca3af' },
        grid:  { display: false },
        border: { display: false }
    });

    const commonLegend = {
        display: true,
        position: 'bottom',
        labels: { font: { size: 12 }, boxWidth: 14, padding: 16, color: '#374151' }
    };

    // ---- グラフ A: テナントビル 月次収入（棒）＋ 入居率（線） ----
    const ctxIncome = document.getElementById('chartIncome');
    if (ctxIncome) {
        new Chart(ctxIncome, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        type: 'bar', label: '月次収入',
                        data: monthly.tenant.income,
                        backgroundColor: 'rgba(5,150,105,0.45)',
                        borderColor: 'rgba(5,150,105,0.75)',
                        borderWidth: 1, borderRadius: 4, borderSkipped: false,
                        yAxisID: 'yIncome'
                    },
                    {
                        type: 'line', label: '入居率',
                        data: monthly.tenant.occupancy,
                        borderColor: '#2563eb', backgroundColor: 'transparent',
                        fill: false, tension: 0.3,
                        pointRadius: 5, pointHoverRadius: 7,
                        pointBackgroundColor: '#2563eb', pointBorderColor: '#2563eb',
                        pointBorderWidth: 0, borderWidth: 3,
                        yAxisID: 'yOccupancy'
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: commonLegend },
                scales: {
                    yIncome:    { type: 'linear', position: 'left',  ticks: { display: false }, grid: { color: '#f1f5f9' }, border: { display: false } },
                    yOccupancy: { type: 'linear', position: 'right', min: 70, max: 100, ticks: { display: false }, grid: { drawOnChartArea: false }, border: { display: false } },
                    x: commonScaleX()
                }
            }
        });
    }

    // ---- グラフ B: 賃貸マンション 月次収入（棒）＋ 入居率（線） ----
    const ctxMansion = document.getElementById('chartMansionIncome');
    if (ctxMansion) {
        new Chart(ctxMansion, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        type: 'bar', label: '月次収入',
                        data: monthly.mansion.income,
                        backgroundColor: 'rgba(8,145,178,0.45)',
                        borderColor: 'rgba(8,145,178,0.75)',
                        borderWidth: 1, borderRadius: 4, borderSkipped: false,
                        yAxisID: 'yMIncome'
                    },
                    {
                        type: 'line', label: '入居率',
                        data: monthly.mansion.occupancy,
                        borderColor: '#7c3aed', backgroundColor: 'transparent',
                        fill: false, tension: 0.3,
                        pointRadius: 5, pointHoverRadius: 7,
                        pointBackgroundColor: '#7c3aed', pointBorderColor: '#7c3aed',
                        pointBorderWidth: 0, borderWidth: 3,
                        yAxisID: 'yMOccupancy'
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: commonLegend },
                scales: {
                    yMIncome:    { type: 'linear', position: 'left',  ticks: { display: false }, grid: { color: '#f1f5f9' }, border: { display: false } },
                    yMOccupancy: { type: 'linear', position: 'right', min: 70, max: 100, ticks: { display: false }, grid: { drawOnChartArea: false }, border: { display: false } },
                    x: commonScaleX()
                }
            }
        });
    }

    // ---- グラフ C: 住宅事業 月次粗利（棒）＋ 成約件数（線） ----
    const ctxHousing = document.getElementById('chartProfitHousing');
    if (ctxHousing) {
        new Chart(ctxHousing, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        type: 'bar', label: '建売 粗利',
                        data: monthly.housing.building_profit,
                        backgroundColor: 'rgba(16,185,129,0.7)',
                        borderColor: 'rgba(5,150,105,0.85)',
                        borderWidth: 1, borderRadius: 4, borderSkipped: false,
                        yAxisID: 'yHProfit'
                    },
                    {
                        type: 'bar', label: '注文住宅 粗利',
                        data: monthly.housing.custom_profit,
                        backgroundColor: 'rgba(59,130,246,0.7)',
                        borderColor: 'rgba(37,99,235,0.85)',
                        borderWidth: 1, borderRadius: 4, borderSkipped: false,
                        yAxisID: 'yHProfit'
                    },
                    {
                        type: 'line', label: '建売 成約件数',
                        data: monthly.housing.building_count,
                        borderColor: '#047857', backgroundColor: 'transparent',
                        fill: false, tension: 0.3,
                        pointRadius: 5, pointHoverRadius: 7,
                        pointBackgroundColor: '#047857', pointBorderColor: '#047857',
                        pointBorderWidth: 0, borderWidth: 2.5,
                        yAxisID: 'yHCount'
                    },
                    {
                        type: 'line', label: '注文住宅 成約件数',
                        data: monthly.housing.custom_count,
                        borderColor: '#1e40af', backgroundColor: 'transparent',
                        fill: false, tension: 0.3,
                        pointRadius: 5, pointHoverRadius: 7,
                        pointBackgroundColor: '#1e40af', pointBorderColor: '#1e40af',
                        pointBorderWidth: 0, borderWidth: 2.5,
                        yAxisID: 'yHCount'
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: commonLegend },
                scales: {
                    yHProfit: { type: 'linear', position: 'left',  ticks: { display: false }, grid: { color: '#f1f5f9' }, border: { display: false } },
                    yHCount:  { type: 'linear', position: 'right', min: 0, suggestedMax: 3, ticks: { display: false }, grid: { drawOnChartArea: false }, border: { display: false } },
                    x: commonScaleX()
                }
            }
        });
    }

    // ---- グラフ D: 不動産事業 月次粗利（棒）＋ 成約件数（線） ----
    const ctxRE = document.getElementById('chartProfitRealestate');
    if (ctxRE) {
        new Chart(ctxRE, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        type: 'bar', label: '不動産粗利',
                        data: monthly.realEstate.profit,
                        backgroundColor: 'rgba(245,158,11,0.7)',
                        borderColor: 'rgba(217,119,6,0.85)',
                        borderWidth: 1, borderRadius: 4, borderSkipped: false,
                        yAxisID: 'yREProfit'
                    },
                    {
                        type: 'line', label: '不動産成約件数',
                        data: monthly.realEstate.count,
                        borderColor: '#92400e', backgroundColor: 'transparent',
                        fill: false, tension: 0.3,
                        pointRadius: 5, pointHoverRadius: 7,
                        pointBackgroundColor: '#92400e', pointBorderColor: '#92400e',
                        pointBorderWidth: 0, borderWidth: 2.5,
                        yAxisID: 'yRECount'
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: commonLegend },
                scales: {
                    yREProfit: { type: 'linear', position: 'left',  ticks: { display: false }, grid: { color: '#f1f5f9' }, border: { display: false } },
                    yRECount:  { type: 'linear', position: 'right', min: 0, suggestedMax: 5, ticks: { display: false }, grid: { drawOnChartArea: false }, border: { display: false } },
                    x: commonScaleX()
                }
            }
        });
    }
})();
</script>
@endif
