@extends('layouts.app')

@section('title', '住宅事業ダッシュボード')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">ダッシュボード</span>
@endsection

@section('content')

    <div class="flex items-center justify-between mb-5">
        <h1 class="text-lg font-bold text-gray-900">住宅事業ダッシュボード</h1>
        <form method="GET" action="{{ route('housing.dashboard') }}" style="display: flex; gap: 8px;">
            <select name="fiscal_year" onchange="this.form.submit()"
                    class="h-9 px-3 border border-gray-300 rounded-md text-sm bg-white">
                @foreach($fiscalYearOptions as $value => $label)
                    <option value="{{ $value }}" {{ $fiscalYear === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            <select name="period" onchange="this.form.submit()"
                    class="h-9 px-3 border border-gray-300 rounded-md text-sm bg-white">
                <option value="all"    {{ $period === 'all' ? 'selected' : '' }}>全期</option>
                <option value="first"  {{ $period === 'first' ? 'selected' : '' }}>上期</option>
                <option value="second" {{ $period === 'second' ? 'selected' : '' }}>下期</option>
            </select>
        </form>
    </div>

    @include('housing._dashboard_kpi', ['kpi' => $kpi])

    @include('housing._dashboard_contracted', ['paginated' => $paginated])

    @include('housing._dashboard_chart', ['monthly' => $monthly])

@endsection
