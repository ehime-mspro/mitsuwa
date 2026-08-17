{{-- 月次成約件数 棒グラフ（Chart.js）--}}
{{-- スクリプトはこの partial 内にインラインで置く（@push('scripts') へは移していない）。
     ⚠ @stack('scripts') は 2026-07-26 に追加済みだが、動いているため移行していない（Bug #28） --}}
@if($monthly !== null)
<div class="bg-white border border-gray-200 rounded-lg px-4 py-3 mb-5">
    <div class="text-sm font-semibold text-gray-700 mb-3">月次成約件数</div>
    <div style="height: 240px;">
        <canvas id="housingMonthlyChart"></canvas>
    </div>
</div>

{{-- ⚠ `.min.js` に戻さないこと。npm に実在せず jsDelivr の動的生成物で、そのバナー自身が
     「Do NOT use SRI with dynamically generated files!」と警告する。値は CdnScriptIntegrityTest::PINNED_SRI で固定 --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"
        integrity="sha384-dug+JxfBvklEQdJ4AYuBBAIScUz0bVN73xpy273gcAwHjb3qI0fXmuYNaNfdyYJG"
        crossorigin="anonymous"></script>
<script>
(function() {
    var data = @json($monthly);
    var canvas = document.getElementById('housingMonthlyChart');
    if (!canvas || typeof Chart === 'undefined') return;

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [
                { label: '成約件数', data: data.data, backgroundColor: '#047857' }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            },
            plugins: { legend: { display: false } }
        }
    });
})();
</script>
@endif
