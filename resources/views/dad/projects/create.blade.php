@extends('layouts.app')

@section('title', '工事案件 新規登録')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>DAD</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('dad.projects.index') }}" class="text-emerald-600 hover:text-emerald-700">工事案件</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">新規登録</span>
@endsection

@section('content')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900">工事案件 新規登録</h1>
    <a href="{{ route('dad.projects.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-gray-300 text-gray-700 rounded-md text-sm hover:bg-gray-50">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>一覧へ
    </a>
</div>

@if($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
        @foreach($errors->all() as $error)
            <p class="text-sm text-red-800">{{ $error }}</p>
        @endforeach
    </div>
@endif

<form method="POST" action="{{ route('dad.projects.store') }}">
    @csrf

    @include('dad.projects._form', ['project' => null])

    <x-form-actions submit-label="登録する" :cancel-url="route('dad.projects.index')" />
</form>

@endsection
