@extends('layouts.app')

@section('title', '建売物件 編集 — ' . $property->property_code)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.properties.index') }}" class="text-gray-500 hover:text-emerald-600">建売物件一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.properties.show', $property) }}" class="text-gray-500 hover:text-emerald-600">{{ $property->property_code }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">編集</span>
@endsection

@section('content')
    <h1 class="text-lg font-bold text-gray-900 mb-5">建売物件 編集 — {{ $property->property_code }}</h1>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
            <p class="text-sm text-red-800">入力内容にエラーがあります。確認してください。</p>
            {{-- 理由を 1 件ずつ出す（項目の下に @error が無い欄も多い。H5） --}}
            <ul class="list-disc list-inside text-xs text-red-700 mt-1 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('housing.properties.update', $property) }}">
        @csrf
        @method('PUT')

        @include('housing.properties._form', ['property' => $property, 'projectsForJs' => $projectsForJs, 'procurementsForJs' => $procurementsForJs])

        <x-form-actions submit-label="更新する" :cancel-url="route('housing.properties.show', $property)" />
    </form>
@endsection
