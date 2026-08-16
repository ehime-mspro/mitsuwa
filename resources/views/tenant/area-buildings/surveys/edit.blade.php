@extends('layouts.app')

@section('title', '調査を編集')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.area-buildings.index') }}" class="hover:text-emerald-600 transition-colors">周辺ビル調査</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.area-buildings.show', $building) }}" class="hover:text-emerald-600 transition-colors">{{ $building->name }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">調査を編集</span>
@endsection

@section('content')

    <a href="{{ route('tenant.area-buildings.show', $building) }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        {{ $building->name }} に戻る
    </a>

    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">{{ $building->name }} — 調査を編集</h1>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-sm font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-xs text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('tenant.area-buildings.surveys.update', [$building, $survey]) }}">
        @csrf
        @method('PUT')
        @include('tenant.area-buildings.surveys._form')
        <x-form-actions submit-label="更新する" :cancel-url="route('tenant.area-buildings.show', $building)" />
    </form>

@endsection
