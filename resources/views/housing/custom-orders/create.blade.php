@extends('layouts.app')

@section('title', '注文住宅 新規登録')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.custom-orders.index') }}" class="text-gray-500 hover:text-emerald-600">注文住宅一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">新規登録</span>
@endsection

@section('content')
    <h1 class="text-lg font-bold text-gray-900 mb-5">注文住宅 新規登録</h1>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
            <p class="text-sm text-red-800">入力内容にエラーがあります。確認してください。</p>
        </div>
    @endif

    <form method="POST" action="{{ route('housing.custom-orders.store') }}">
        @csrf

        @include('housing.custom-orders._form', ['customOrder' => null, 'projectsForJs' => $projectsForJs, 'procurementsForJs' => $procurementsForJs, 'defaultTaxRate' => $defaultTaxRate])

        <x-form-actions submit-label="登録する" :cancel-url="route('housing.custom-orders.index')" />
    </form>
@endsection
