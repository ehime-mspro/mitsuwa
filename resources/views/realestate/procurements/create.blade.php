@extends('layouts.app')

@section('title', '仕入れ案件 新規登録')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>不動産管理</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('realestate.procurements.index') }}" class="hover:text-emerald-600 transition-colors">仕入れ案件一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">新規登録</span>
@endsection

@section('content')

    <h1 class="text-lg font-bold text-gray-900 mb-5">仕入れ案件 新規登録</h1>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
            @foreach($errors->all() as $error)
                <p class="text-sm text-red-800">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('realestate.procurements.store') }}">
        @csrf
        @include('realestate.procurements._form')

        <x-form-actions submit-label="登録する" :cancel-url="route('realestate.procurements.index')" />
    </form>

@endsection
