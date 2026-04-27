@extends('layouts.app')

@section('title', '仕入れ先 新規登録')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>不動産管理</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('realestate.suppliers.index') }}" class="hover:text-emerald-600 transition-colors">仕入れ先一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">新規登録</span>
@endsection

@section('content')

    <h1 class="text-lg font-bold text-gray-900 mb-5">仕入れ先 新規登録</h1>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
            @foreach($errors->all() as $error)
                <p class="text-sm text-red-800">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('realestate.suppliers.store') }}">
        @csrf
        @include('realestate.suppliers._form')

        <div class="flex gap-3">
            <button type="submit"
                    class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors cursor-pointer">
                登録する
            </button>
            <a href="{{ route('realestate.suppliers.index') }}"
               class="px-5 py-2.5 bg-white border-2 border-gray-400 text-gray-700 text-sm font-semibold rounded-md hover:bg-gray-50 transition-colors">
                キャンセル
            </a>
        </div>
    </form>

@endsection
