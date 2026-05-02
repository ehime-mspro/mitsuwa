@extends('layouts.app')

@section('title', $member->name . ' — 会員情報編集')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.dashboard') }}" class="hover:text-emerald-600 transition-colors">ZEAL</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.members.index') }}" class="hover:text-emerald-600 transition-colors">会員一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.members.show', $member) }}" class="hover:text-emerald-600 transition-colors">{{ $member->name }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">編集</span>
@endsection

@section('content')

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <h1 style="font-size: 20px; font-weight: 700; margin: 0;">会員情報 編集</h1>
    <a href="{{ route('zeal.members.show', $member) }}"
       style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        詳細に戻る
    </a>
</div>

@include('zeal.members._form', ['member' => $member, 'trainers' => $trainers])

@endsection
