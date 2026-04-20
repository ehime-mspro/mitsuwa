@extends('layouts.app')

@section('title', '部屋 新規登録')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">物件一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.show', $property) }}" class="hover:text-emerald-600 transition-colors">{{ $property->property_code }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">部屋登録</span>
@endsection

@section('content')

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <h1 style="font-size: 20px; font-weight: 700; margin: 0;">部屋 新規登録</h1>
        <span style="font-size: 12px; background: #f3f4f6; color: #4b5563; padding: 3px 10px; border-radius: 4px; font-weight: 600;">{{ $property->property_name }}</span>
    </div>
    <a href="{{ route('mansion.properties.show', $property) }}"
       style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        物件詳細に戻る
    </a>
</div>

{{-- 共通フォーム（$room = null で新規モード） --}}
@include('mansion.rooms._form', ['room' => null, 'property' => $property, 'statuses' => $statuses])

{{-- 補足 --}}
<div style="margin-top: 20px; padding: 12px 16px; background: #f9fafb; border-radius: 8px; font-size: 12px; color: #6b7280;">
    <strong style="color: #374151;">※登録後の動作</strong>：同じ物件内で号室番号は重複できません。登録完了後は物件詳細画面に戻ります。
</div>

@endsection
