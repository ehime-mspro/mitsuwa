@extends('layouts.app')

@section('title', '分譲地 新規登録')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>不動産管理</span>
    <span class="mx-1.5">›</span>
    <a href="{{ $backUrl }}" class="hover:text-emerald-600 transition-colors">{{ $backLabel }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">新規登録</span>
@endsection

@section('content')

    <h1 class="text-lg font-bold text-gray-900 mb-5">分譲地 新規登録</h1>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
            @foreach($errors->all() as $error)
                <p class="text-sm text-red-800">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('realestate.projects.store') }}">
        @csrf
        @include('realestate.projects._form')

        <x-form-actions submit-label="登録する" :cancel-url="$backUrl" />
    </form>

@endsection
