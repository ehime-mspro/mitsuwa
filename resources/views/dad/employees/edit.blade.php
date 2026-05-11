@extends('layouts.app')

@section('title', '従業員 編集 - ' . $employee->name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>DAD</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('dad.employees.index') }}" class="text-emerald-600 hover:text-emerald-700">従業員管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">編集</span>
@endsection

@section('content')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900">従業員 編集 — {{ $employee->name }}</h1>
    <a href="{{ route('dad.employees.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-gray-300 text-gray-700 rounded-md text-sm hover:bg-gray-50 transition-colors">
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

<div x-data="{ confirmDelete: false }" style="max-width: 1000px;">
    <form method="POST" action="{{ route('dad.employees.update', $employee) }}">
        @csrf
        @method('PUT')

        @include('dad.employees._form', ['employee' => $employee])

        <div style="display: flex; margin-bottom: 12px;">
            <button type="button" @click="confirmDelete = !confirmDelete" style="padding: 10px 20px; background: white; color: #dc2626; border: 1px solid #fecaca; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer;">削除</button>
        </div>
        <x-form-actions submit-label="更新する" :cancel-url="route('dad.employees.index')" />
    </form>

    <div x-show="confirmDelete" x-cloak class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4">
        <p class="text-sm text-red-800 font-semibold mb-2">「{{ $employee->name }}」を削除しますか？ この操作は取り消せません。</p>
        <p class="text-xs text-red-600 mb-3">※ 工事配置で参照中の場合、削除できません。退職で運用する場合は在籍状況を変更してください。</p>
        <form method="POST" action="{{ route('dad.employees.destroy', $employee) }}">
            @csrf
            @method('DELETE')
            <div style="display: flex; gap: 8px;">
                <button type="submit" style="padding: 10px 20px; background: #dc2626; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer;">削除する</button>
                <button type="button" @click="confirmDelete = false" style="padding: 10px 20px; background: white; color: #374151; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer;">キャンセル</button>
            </div>
        </form>
    </div>
</div>

@endsection
