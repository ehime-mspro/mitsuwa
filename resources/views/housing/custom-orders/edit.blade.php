@extends('layouts.app')

@section('title', '注文住宅 編集 — ' . $customOrder->order_code)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.custom-orders.index') }}" class="text-gray-500 hover:text-emerald-600">注文住宅一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.custom-orders.show', $customOrder) }}" class="text-gray-500 hover:text-emerald-600">{{ $customOrder->order_code }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">編集</span>
@endsection

@section('content')
    <h1 class="text-lg font-bold text-gray-900 mb-5">注文住宅 編集 — {{ $customOrder->order_code }} {{ $customOrder->order_name }}</h1>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
            <p class="text-sm text-red-800">入力内容にエラーがあります。確認してください。</p>
        </div>
    @endif

    <form method="POST" action="{{ route('housing.custom-orders.update', $customOrder) }}">
        @csrf
        @method('PUT')

        @include('housing.custom-orders._form', ['customOrder' => $customOrder, 'projectsForJs' => $projectsForJs, 'procurementsForJs' => $procurementsForJs])

        <x-form-actions submit-label="更新する" :cancel-url="route('housing.custom-orders.show', $customOrder)" />
    </form>
@endsection
