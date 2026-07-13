@extends('layouts.app')

@section('title', '契約・解約分析')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">契約・解約分析</span>
@endsection

@section('content')
<div x-data="{ tab: 'contract' }">

    {{-- ページヘッダー --}}
    <div class="mb-5">
        <h1 class="text-lg font-bold text-gray-900">契約・解約分析</h1>
        <p class="text-sm text-gray-500" style="margin-top:4px;">契約日・解約日を暦年×暦月で集計。件数の多いセルほど濃く表示します。</p>
    </div>

    {{-- タブ（契約 / 解約） --}}
    <div class="flex gap-1 mb-4" role="tablist">
        <button type="button" @click="tab = 'contract'"
                :class="tab === 'contract' ? 'bg-emerald-600 text-white' : 'bg-white text-gray-700 border border-gray-300'"
                class="px-4 py-2 rounded-md text-sm font-semibold transition-colors">
            契約分析
        </button>
        <button type="button" @click="tab = 'termination'"
                :class="tab === 'termination' ? 'bg-emerald-600 text-white' : 'bg-white text-gray-700 border border-gray-300'"
                class="px-4 py-2 rounded-md text-sm font-semibold transition-colors">
            解約分析
        </button>
    </div>

    {{-- 契約マトリクス --}}
    <div x-show="tab === 'contract'" x-cloak>
        @include('tenant.analysis._matrix', ['matrix' => $contract, 'emptyLabel' => '契約データがありません'])
    </div>

    {{-- 解約マトリクス --}}
    <div x-show="tab === 'termination'" x-cloak>
        @include('tenant.analysis._matrix', ['matrix' => $termination, 'emptyLabel' => '解約データがありません'])
    </div>

</div>
@endsection
