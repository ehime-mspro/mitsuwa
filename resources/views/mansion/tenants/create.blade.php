@extends('layouts.app')

@section('title', '入居者 新規登録')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.tenants.index') }}" class="hover:text-emerald-600 transition-colors">入居者管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">新規登録</span>
@endsection

@section('content')

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <h1 style="font-size: 20px; font-weight: 700; margin: 0;">入居者 新規登録</h1>
    <a href="{{ route('mansion.tenants.index') }}"
       style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        一覧に戻る
    </a>
</div>

{{-- 共通フォーム（$tenant = null で新規モード） --}}
@include('mansion.tenants._form', ['tenant' => null, 'tenantTypes' => $tenantTypes])

{{-- 補足 --}}
<div style="margin-top: 20px; padding: 12px 16px; background: #f9fafb; border-radius: 8px; font-size: 12px; color: #6b7280;">
    <strong style="color: #374151;">※登録後の動作</strong>：入居者を登録後、契約画面で部屋または駐車場を紐付けます。登録完了後は詳細画面に遷移します。
</div>

@endsection
