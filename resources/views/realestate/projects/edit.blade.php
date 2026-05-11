@extends('layouts.app')

@section('title', '分譲地 編集')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>不動産管理</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('realestate.projects.index') }}" class="hover:text-emerald-600 transition-colors">分譲地一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('realestate.projects.show', $project) }}" class="hover:text-emerald-600 transition-colors">{{ $project->project_code }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">編集</span>
@endsection

@section('content')

    <h1 class="text-lg font-bold text-gray-900 mb-5">分譲地 編集 — {{ $project->project_code }}</h1>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
            @foreach($errors->all() as $error)
                <p class="text-sm text-red-800">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('realestate.projects.update', $project) }}">
        @csrf
        @method('PUT')
        @include('realestate.projects._form')

        <x-form-actions submit-label="更新する" :cancel-url="route('realestate.projects.show', $project)" />
    </form>

@endsection
