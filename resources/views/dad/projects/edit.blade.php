@extends('layouts.app')

@section('title', '工事案件 編集 - ' . $project->project_name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>DAD（土木事業）</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('dad.projects.index') }}" class="text-emerald-600 hover:text-emerald-700">工事案件</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('dad.projects.show', $project) }}" class="text-emerald-600 hover:text-emerald-700">{{ $project->project_code }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">編集</span>
@endsection

@section('content')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900">工事案件 編集 — {{ $project->project_name }}</h1>
    <a href="{{ route('dad.projects.show', $project) }}" class="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-gray-300 text-gray-700 rounded-md text-sm hover:bg-gray-50">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>詳細へ戻る
    </a>
</div>

@if($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
        @foreach($errors->all() as $error)
            <p class="text-sm text-red-800">{{ $error }}</p>
        @endforeach
    </div>
@endif

<div x-data="{ confirmDelete: false }">
    <form method="POST" action="{{ route('dad.projects.update', $project) }}">
        @csrf
        @method('PUT')

        @include('dad.projects._form', ['project' => $project])

        <div style="display: flex; justify-content: space-between; align-items: center;">
            <button type="button" @click="confirmDelete = !confirmDelete" style="padding: 10px 20px; background: white; color: #dc2626; border: 1px solid #fecaca; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer;">削除</button>
            <div style="display: flex; gap: 8px;">
                <a href="{{ route('dad.projects.show', $project) }}" style="display: inline-flex; align-items: center; padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 14px; color: #374151; text-decoration: none;">キャンセル</a>
                <button type="submit" style="padding: 10px 24px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer;">更新する</button>
            </div>
        </div>
    </form>

    <div x-show="confirmDelete" x-cloak class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4" style="max-width: 1100px;">
        <p class="text-sm text-red-800 font-semibold mb-2">「{{ $project->project_name }}」を削除しますか？ 原価明細・人員配置も連動して削除されます。</p>
        <p class="text-xs text-red-600 mb-3">この操作は取り消せません。</p>
        <form method="POST" action="{{ route('dad.projects.destroy', $project) }}">
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
