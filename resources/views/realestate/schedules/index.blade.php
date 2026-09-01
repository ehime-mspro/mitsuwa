@extends('layouts.app')

@section('title', '工程表')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>不動産管理</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">工程表</span>
@endsection

@section('content')
    <div class="flex items-center gap-2 mb-4">
        <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
        <h1 class="text-base font-bold text-gray-900">工程表</h1>
    </div>

    @include('_partials._schedule_board', ['board' => $board, 'boardRoute' => 'realestate.schedules.index'])
@endsection
