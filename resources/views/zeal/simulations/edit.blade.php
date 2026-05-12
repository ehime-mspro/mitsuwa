@extends('layouts.app')

@section('title', $simulation->fiscal_year . '年度 経営試算表 編集 — ZEAL')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>ZEAL</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.simulations.index') }}" class="text-gray-500 hover:text-emerald-600">経営試算表</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.simulations.show', $simulation) }}" class="text-gray-500 hover:text-emerald-600">{{ $simulation->fiscal_year }}年度</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">編集</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-5">
        <h1 class="text-lg font-bold text-gray-900">{{ $simulation->fiscal_year }}年度 経営試算表 編集</h1>
    </div>

    <form action="{{ route('zeal.simulations.update', $simulation) }}" method="POST">
        @csrf
        @method('PUT')

        {{-- 名称・備考 --}}
        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px 22px; margin-bottom: 16px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">名称</label>
                    <input type="text" name="name" value="{{ old('name', $simulation->name) }}"
                           placeholder="例: 2025年度 経営試算表"
                           style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                </div>
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">備考</label>
                    <input type="text" name="notes" value="{{ old('notes', $simulation->notes) }}"
                           style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                </div>
            </div>
        </div>

        {{-- 試算表マトリクス（編集モード） --}}
        @include('zeal.simulations._table', ['editable' => true])

        <div style="margin-top: 14px; padding: 10px 14px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; font-size: 12px; color: #075985;">
            <strong>編集について:</strong> 売上連動行（ロイヤリティ・決済手数料 等）と集計行（経費計・営業利益・累計利益）は表示時に自動算出されるため、入力欄は表示されません。手入力・固定額タイプの項目のみ編集可能です。
        </div>

        <x-form-actions submit-label="保存する" :cancel-url="route('zeal.simulations.show', $simulation)" />
    </form>
@endsection
