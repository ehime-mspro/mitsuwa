@extends('layouts.app')

@section('title', $property->property_name . ' — 編集')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">物件一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.show', $property) }}" class="hover:text-emerald-600 transition-colors">{{ $property->property_code }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">編集</span>
@endsection

@section('content')

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <h1 style="font-size: 20px; font-weight: 700; margin: 0;">マンション物件 編集</h1>
        <span style="font-size: 12px; background: #f3f4f6; color: #4b5563; padding: 3px 10px; border-radius: 4px; font-weight: 600;">{{ $property->property_code }}</span>
    </div>
    <a href="{{ route('mansion.properties.show', $property) }}"
       style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        詳細に戻る
    </a>
</div>

{{-- 共通フォーム（$property を渡して編集モード） --}}
@include('mansion.properties._form', ['property' => $property, 'ownershipTypes' => $ownershipTypes])

{{-- 削除ゾーン（経営層のみ） --}}
@if(auth()->user()->role->isExecutive())
    <div class="bg-white border border-red-200 rounded-lg" style="margin-top: 28px; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
        <div>
            <div style="font-size: 14px; font-weight: 700; color: #b91c1c; margin-bottom: 2px;">物件を削除</div>
            <div style="font-size: 12px; color: #6b7280;">契約中の部屋または駐車場がある場合は削除できません。</div>
        </div>
        <form method="POST" action="{{ route('mansion.properties.destroy', $property) }}"
              onsubmit="return confirm('本当にこの物件を削除しますか？部屋・駐車場も連動削除されます。');">
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
