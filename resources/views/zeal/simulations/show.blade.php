@extends('layouts.app')

@section('title', $simulation->fiscal_year . '年度 経営試算表 — ZEAL')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>ZEAL</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.simulations.index') }}" class="text-gray-500 hover:text-emerald-600">経営試算表</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $simulation->fiscal_year }}年度</span>
@endsection

@section('content')
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-5">
        <div>
            <h1 class="text-lg font-bold text-gray-900">{{ $simulation->fiscal_year }}年度 経営試算表</h1>
            <div class="flex items-center gap-2 mt-1">
                <span class="text-sm text-gray-500">{{ $simulation->fiscal_year }}/06 〜 {{ $simulation->fiscal_year + 1 }}/05</span>
                @if($simulation->name)
                    <span class="text-sm text-gray-700">— {{ $simulation->name }}</span>
                @endif
            </div>
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            <a href="{{ route('zeal.simulations.index') }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">一覧に戻る</a>
            <a href="{{ route('zeal.simulations.edit', $simulation) }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 6px; text-decoration: none; background: #fff;">編集</a>
        </div>
    </div>

    @if($simulation->notes)
        <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 13px; color: #92400e; white-space: pre-wrap;">{{ $simulation->notes }}</div>
    @endif

    {{-- 試算表テーブル（横スクロール） --}}
    @include('zeal.simulations._table', ['editable' => false])
@endsection
