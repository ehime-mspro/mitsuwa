@extends('layouts.app')

@section('title', '建売物件 新規登録')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.properties.index') }}" class="text-gray-500 hover:text-emerald-600">建売物件一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">新規登録</span>
@endsection

@section('content')
    <h1 class="text-lg font-bold text-gray-900 mb-5">建売物件 新規登録</h1>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
            <p class="text-sm text-red-800">入力内容にエラーがあります。確認してください。</p>
        </div>
    @endif

    <form method="POST" action="{{ route('housing.properties.store') }}">
        @csrf

        @include('housing.properties._form', ['property' => null, 'projectsForJs' => $projectsForJs, 'procurementsForJs' => $procurementsForJs])

        <x-form-actions submit-label="登録する" :cancel-url="route('housing.properties.index')" />
    </form>
@endsection
