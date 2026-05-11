@extends('layouts.app')

@section('title', '駐車場契約 編集')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.parking-contracts.index') }}" class="hover:text-emerald-600 transition-colors">駐車場契約一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.parking-contracts.show', $parkingContract) }}" class="hover:text-emerald-600 transition-colors">契約詳細</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">編集</span>
@endsection

@section('content')

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <div style="display: flex; align-items: center; gap: 12px;">
        <h1 style="font-size: 20px; font-weight: 700; margin: 0;">駐車場契約 編集</h1>
        <span style="font-size: 12px; background: #f3f4f6; color: #4b5563; padding: 3px 10px; border-radius: 4px; font-weight: 600;">
            {{ $parkingContract->tenant?->name ?? '—' }} / {{ $parkingContract->parking?->parking_number ?? '—' }}
        </span>
    </div>
    <a href="{{ route('mansion.parking-contracts.show', $parkingContract) }}"
       style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        詳細に戻る
    </a>
</div>

<form method="POST" action="{{ route('mansion.parking-contracts.update', $parkingContract) }}">
    @csrf
    @method('PUT')

    {{-- 編集時は _form 内で「対象駐車場（編集不可）」カードが自動表示され、
         共通フィールド（利用者・契約日・月額料金・敷金・担当者・備考）のみ編集可能 --}}
    @include('mansion.parking-contracts._form', ['parkingContract' => $parkingContract])

    {{-- ========== アクションボタン ========== --}}
    {{-- 本システムは契約の物理削除（destroy）エンドポイントを持たない設計。
         不要になった契約は「解約処理（terminate）」で扱うため、削除ボタンは配置しない。 --}}
    <x-form-actions submit-label="更新する" :cancel-url="route('mansion.parking-contracts.show', $parkingContract)" />
</form>

{{-- 補足 --}}
<div style="margin-top: 20px; padding: 12px 16px; background: #f9fafb; border-radius: 8px; font-size: 12px; color: #6b7280;">
    <strong style="color: #374151;">※編集できない項目</strong>：物件・駐車場の組み合わせは変更できません。変更が必要な場合は「解約処理」後に新規契約を登録してください。月額料金の改定は「料金改定」機能をご利用ください。
</div>

@endsection
