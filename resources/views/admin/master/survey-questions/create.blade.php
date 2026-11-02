@extends('layouts.app')

@section('title', 'アンケート設問 追加')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>システム管理</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('admin.survey-questions.index', ['department' => $department]) }}" class="text-emerald-600 hover:text-emerald-700">アンケート設問管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">追加</span>
@endsection

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900">設問を追加</h1>
</div>

{{-- バリデーションエラー --}}
@if($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
        @foreach($errors->all() as $error)
            <p class="text-sm text-red-800">{{ $error }}</p>
        @endforeach
    </div>
@endif

<form method="POST" action="{{ route('admin.survey-questions.store') }}" x-data="surveyQuestionForm()" style="max-width: 880px;">
    @csrf
    {{-- 部署は一覧で開いていたタブ（画面では変えない） --}}
    <input type="hidden" name="department" value="{{ $department }}">

    @include('admin.master.survey-questions._form')

    <x-form-actions submit-label="登録する" :cancel-url="route('admin.survey-questions.index', ['department' => $department])" />
</form>
@endsection
