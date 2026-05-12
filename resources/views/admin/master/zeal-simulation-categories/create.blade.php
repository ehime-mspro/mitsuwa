@extends('layouts.app')

@section('title', '項目 新規登録 — ZEAL 試算表 項目マスター')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>システム管理</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('admin.master.zeal-simulation-categories.index') }}" class="text-gray-500 hover:text-emerald-600">ZEAL 試算表 項目マスター</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">新規登録</span>
@endsection

@section('content')
    <h1 class="text-lg font-bold text-gray-900 mb-5">項目 新規登録</h1>

    <form action="{{ route('admin.master.zeal-simulation-categories.store') }}" method="POST">
        @csrf
        @include('admin.master.zeal-simulation-categories._form')

        <x-form-actions submit-label="登録する" :cancel-url="route('admin.master.zeal-simulation-categories.index')" />
    </form>
@endsection
