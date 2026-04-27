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

    <div class="bg-white border border-gray-200 rounded-lg p-4">
        <p class="text-sm text-gray-700">成約 DTO 件数: <span class="font-bold">{{ count($items ?? []) }}</span> 件</p>
        @if(count($items ?? []) > 0)
            <p class="text-xs text-gray-500 mt-2">先頭1件: {{ $items[0]['code'] ?? '' }} / {{ $items[0]['name'] ?? '' }} / {{ $items[0]['contracted_date']?->format('Y-m-d') ?? '' }}</p>
        @endif
    </div>

@endsection
