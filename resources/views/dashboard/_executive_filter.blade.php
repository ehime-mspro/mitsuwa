{{-- 経営ダッシュボード: 年度・期フィルター --}}
<div class="page-header">
    <h1 class="page-title">経営ダッシュボード</h1>
    <form class="filter-bar" method="GET" id="filter-form" action="{{ route('dashboard.executive') }}">
        <label for="filter-fy">年度</label>
        <select id="filter-fy" name="fy" onchange="document.getElementById('filter-form').submit()">
            @foreach($fiscalYearOptions as $value => $label)
                {{-- PHP は数字文字列キーを int 化するため文字列比較で揃える --}}
                <option value="{{ $value }}" {{ $fiscalYear === (string) $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
        <div class="filter-divider"></div>
        <label for="filter-period">期</label>
        <select id="filter-period" name="period" onchange="document.getElementById('filter-form').submit()">
            <option value="full" {{ $period === 'full' ? 'selected' : '' }}>全期（5〜4月）</option>
            <option value="h1"   {{ $period === 'h1'   ? 'selected' : '' }}>上期（5〜10月）</option>
            <option value="h2"   {{ $period === 'h2'   ? 'selected' : '' }}>下期（11〜4月）</option>
        </select>
    </form>
</div>
