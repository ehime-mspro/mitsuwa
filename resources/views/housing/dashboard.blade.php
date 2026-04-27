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
        {{-- 年度・期セレクターは後続タスクで実装 --}}
    </div>

    <p class="text-sm text-gray-500">Phase 3 実装中（KPI / 成約一覧 / グラフは後続タスクで実装）</p>

@endsection
