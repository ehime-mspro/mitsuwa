@extends('layouts.app')

@section('title', $parking->parking_number . ' — 駐車場編集')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">物件一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.show', $property) }}" class="hover:text-emerald-600 transition-colors">{{ $property->property_code }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $parking->parking_number }} 編集</span>
@endsection

@section('content')

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <h1 style="font-size: 20px; font-weight: 700; margin: 0;">駐車場 編集</h1>
        <span style="font-size: 12px; background: #f3f4f6; color: #4b5563; padding: 3px 10px; border-radius: 4px; font-weight: 600;">{{ $property->property_name }} / {{ $parking->parking_number }}</span>
    </div>
    <a href="{{ route('mansion.properties.show', $property) }}"
       style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        物件詳細に戻る
    </a>
</div>

{{-- 共通フォーム（$parking を渡して編集モード） --}}
@include('mansion.parkings._form', ['parking' => $parking, 'property' => $property, 'statuses' => $statuses])

{{-- 削除ゾーン（経営層のみ） --}}
@if(auth()->user()->role->isExecutive())
    <div class="bg-white border border-red-200 rounded-lg" style="margin-top: 28px; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
        <div>
            <div style="font-size: 14px; font-weight: 700; color: #b91c1c; margin-bottom: 2px;">駐車場を削除</div>
            <div style="font-size: 12px; color: #6b7280;">使用中（契約中）の駐車場は削除できません。契約履歴がある場合も削除は推奨されません。</div>
        </div>
        <form method="POST" action="{{ route('mansion.parkings.destroy', $parking) }}"
              onsubmit="return confirm('本当にこの駐車場を削除しますか？この操作は取り消せません。');">
            @csrf
            @method('DELETE')
            <button type="submit"
                    style="padding: 8px 20px; border: 1px solid #dc2626; border-radius: 6px; background: white; color: #dc2626; font-size: 13px; font-weight: 600; cursor: pointer;">
                削除する
            </button>
        </form>
    </div>
@endif

@endsection
