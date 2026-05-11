@extends('layouts.app')

@section('title', '発注者 新規登録')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>DAD</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('dad.clients.index') }}" class="text-emerald-600 hover:text-emerald-700">発注者管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">新規登録</span>
@endsection

@section('content')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900">発注者 新規登録</h1>
    <a href="{{ route('dad.clients.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-gray-300 text-gray-700 rounded-md text-sm hover:bg-gray-50 transition-colors">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        一覧に戻る
    </a>
</div>

@if($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
        @foreach($errors->all() as $error)
            <p class="text-sm text-red-800">{{ $error }}</p>
        @endforeach
    </div>
@endif

<form method="POST" action="{{ route('dad.clients.store') }}" style="max-width: 1000px;">
    @csrf

    @include('dad.clients._form', ['client' => null])

    {{-- アクション --}}
    <x-form-actions submit-label="登録する" :cancel-url="route('dad.clients.index')" />
</form>

@endsection
