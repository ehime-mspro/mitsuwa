@extends('layouts.app')

@section('title', $category->name . ' を編集 — ZEAL 試算表 項目マスター')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>システム管理</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('admin.master.zeal-simulation-categories.index') }}" class="text-gray-500 hover:text-emerald-600">ZEAL 試算表 項目マスター</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $category->name }} を編集</span>
@endsection

@section('content')
    <h1 class="text-lg font-bold text-gray-900 mb-5">{{ $category->name }} を編集</h1>

    <form action="{{ route('admin.master.zeal-simulation-categories.update', $category) }}" method="POST">
        @csrf
        @method('PUT')
        @include('admin.master.zeal-simulation-categories._form')

        <x-form-actions submit-label="更新する" :cancel-url="route('admin.master.zeal-simulation-categories.index')" />
    </form>
@endsection
