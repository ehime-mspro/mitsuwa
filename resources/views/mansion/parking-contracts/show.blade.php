@extends('layouts.app')

@section('title', ($parkingContract->parking?->property?->property_name ?? '—') . ' / ' . ($parkingContract->parking?->parking_number ?? '—'))

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.parking-contracts.index') }}" class="hover:text-emerald-600 transition-colors">駐車場契約一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">詳細</span>
@endsection

@section('content')

@php
    $role = auth()->user()->role;
    $canEdit = $role->isManagerOrAbove();
    $isTerminated = $parkingContract->status === \App\Enums\MsContractStatus::Terminated;

    // 物件・駐車場情報（null 安全）
    $parking  = $parkingContract->parking;
    $property = $parking?->property;

    // 金額表示用にフォーマット済み文字列を用意（@json 内で number_format を使わないため、事前に整形）
    $monthlyFeeDisplay = $parkingContract->monthly_fee ? (number_format($parkingContract->monthly_fee) . '円') : '—';
    $depositDisplay    = $parkingContract->deposit     ? (number_format($parkingContract->deposit)     . '円') : '—';

    // ページタイトル用
    $propertyName  = $property?->property_name  ?? '—';
    $parkingNumber = $parking?->parking_number  ?? '—';
@endphp

<style>
    .ms-badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .ms-card-title { font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981; }
    .ms-info-row { display: grid; grid-template-columns: 140px 1fr; padding: 8px 0; border-bottom: 1px dashed #e5e7eb; font-size: 14px; }
    .ms-info-row:last-child { border-bottom: none; }
    .ms-info-label { color: #6b7280; font-weight: 600; }
    .ms-info-value { color: #111827; }
    /* 金額情報グリッド（モック踏襲） */
    .ms-money-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
    .ms-money-cell { padding: 14px 16px; border-right: 1px solid #e5e7eb; }
    .ms-money-cell:last-child { border-right: none; }
    .ms-money-label { font-size: 12px; color: #6b7280; margin-bottom: 4px; }
    .ms-money-value { font-size: 18px; font-weight: 700; color: #111827; }
    .ms-standalone-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; background: #f3f4f6; color: #6b7280; }
</style>

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; gap: 12px; flex-wrap: wrap;">
    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <h1 style="font-size: 20px; font-weight: 700; margin: 0;">
            駐車場契約詳細 — {{ $propertyName }} / {{ $parkingNumber }}
        </h1>
        <span class="ms-badge" style="{{ $parkingContract->status->badgeStyle() }}">{{ $parkingContract->status->label() }}</span>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        {{-- 一覧に戻るボタン --}}
        <a href="{{ route('mansion.parking-contracts.index') }}"
           style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
            <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            一覧に戻る
        </a>
        @if($canEdit && !$isTerminated)
            {{-- 料金改定（GET画面遷移） --}}
            <a href="{{ route('mansion.parking-contracts.revise.show', $parkingContract) }}"
               style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #fbbf24; border-radius: 6px; background: #fffbeb; font-size: 13px; color: #92400e; text-decoration: none;">
                料金改定
            </a>
            {{-- 解約処理（GET画面遷移） --}}
            <a href="{{ route('mansion.parking-contracts.terminate.show', $parkingContract) }}"
               style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #fecaca; border-radius: 6px; background: #fef2f2; font-size: 13px; color: #b91c1c; text-decoration: none;">
                解約処理
            </a>
            {{-- 編集 --}}
            <a href="{{ route('mansion.parking-contracts.edit', $parkingContract) }}"
               style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #059669; color: white; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none;">
                <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                編集
            </a>
        @endif
    </div>
</div>

{{-- ========== カード: 基本情報 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="ms-card-title">基本情報</div>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0 32px;">
        <div>
            {{-- 駐車場（物件名 / 駐車場番号） --}}
            <div class="ms-info-row">
                <div class="ms-info-label">駐車場</div>
                <div class="ms-info-value">
                    @if($property)
                        <a href="{{ route('mansion.properties.show', $property) }}" class="hover:underline" style="color: #047857; font-weight: 600;">{{ $property->property_name }}</a>
                    @else
                        —
                    @endif
                    @if($parking)
                        <span style="color: #374151;"> / {{ $parking->parking_number }}</span>
                    @endif
                </div>
            </div>
            {{-- 屋根 --}}
            <div class="ms-info-row">
                <div class="ms-info-label">屋根</div>
                <div class="ms-info-value">
                    @if($parking)
                        {{ $parking->has_roof ? '有り' : '無し' }}
                    @else
                        —
                    @endif
                </div>
            </div>
            {{-- 利用者 --}}
            <div class="ms-info-row">
                <div class="ms-info-label">利用者</div>
                <div class="ms-info-value">
                    @if($parkingContract->tenant)
                        <a href="{{ route('mansion.tenants.show', $parkingContract->tenant) }}" class="hover:underline" style="color: #047857; font-weight: 600;">{{ $parkingContract->tenant->name }}</a>
                    @else
                        —
                    @endif
                </div>
            </div>
            {{-- 紐付け（部屋契約 or 外部単独） --}}
            <div class="ms-info-row">
                <div class="ms-info-label">紐付け</div>
                <div class="ms-info-value">
                    @if($parkingContract->contract)
                        部屋契約:
                        <a href="{{ route('mansion.contracts.show', $parkingContract->contract) }}" class="hover:underline" style="color: #047857; font-weight: 600;">
                            {{ $parkingContract->contract->room?->property?->property_name ?? '—' }}
                            {{ $parkingContract->contract->room?->room_number ? $parkingContract->contract->room->room_number . '号室' : '' }}
                        </a>
                    @else
                        <span class="ms-standalone-badge">外部単独契約</span>
                    @endif
                </div>
            </div>
        </div>
        <div>
            {{-- 担当者 --}}
            <div class="ms-info-row">
                <div class="ms-info-label">担当者</div>
                <div class="ms-info-value">{{ $parkingContract->staff?->name ?? '—' }}</div>
            </div>
            {{-- 契約日 --}}
            <div class="ms-info-row">
                <div class="ms-info-label">契約日</div>
                <div class="ms-info-value">{{ $parkingContract->contract_date?->format('Y/m/d') ?? '—' }}</div>
            </div>
            {{-- 利用開始日 --}}
            <div class="ms-info-row">
                <div class="ms-info-label">利用開始日</div>
                <div class="ms-info-value">{{ $parkingContract->start_date?->format('Y/m/d') ?? '—' }}</div>
            </div>
            {{-- 利用終了日（解約済みの場合のみ表示） --}}
            @if($isTerminated)
                <div class="ms-info-row">
                    <div class="ms-info-label">利用終了日</div>
                    <div class="ms-info-value" style="color: #b91c1c; font-weight: 600;">{{ $parkingContract->end_date?->format('Y/m/d') ?? '—' }}</div>
                </div>
            @endif
        </div>
    </div>
</div>

{{-- ========== カード: 金額情報 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="ms-card-title">金額情報</div>
    <div class="ms-money-grid">
        <div class="ms-money-cell">
            <div class="ms-money-label">月額料金（税抜）</div>
            <div class="ms-money-value">{{ $monthlyFeeDisplay }}</div>
        </div>
        <div class="ms-money-cell">
            <div class="ms-money-label">敷金</div>
            <div class="ms-money-value">{{ $depositDisplay }}</div>
        </div>
    </div>
</div>

{{-- ========== カード: 料金改定履歴 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="ms-card-title">料金改定履歴（{{ $parkingContract->revisions->count() }}件）</div>
    @if($parkingContract->revisions->count() > 0)
        <table class="w-full border-collapse" style="table-layout: fixed;">
            <colgroup>
                <col style="width: 18%">
                <col style="width: 22%">
                <col style="width: 60%">
            </colgroup>
            <thead>
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">改定日</th>
                    <th class="px-4 py-2 text-right text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">新月額料金</th>
                    <th class="px-4 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">改定理由</th>
                </tr>
            </thead>
            <tbody>
                @foreach($parkingContract->revisions as $rev)
                    <tr>
                        <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap">
                            {{ $rev->revision_date?->format('Y/m/d') ?? '—' }}
                        </td>
                        <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 text-right whitespace-nowrap">
                            @if($rev->new_monthly_fee)
                                {{ number_format($rev->new_monthly_fee) }}円
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700">
                            {{ $rev->reason ?: '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div style="font-size: 12px; color: #6b7280; margin-top: 10px;">
            ※ 「料金改定」ボタンから新しい改定を登録できます。
        </div>
    @else
        <div style="padding: 28px 12px; text-align: center; color: #9ca3af; font-size: 13px;">改定履歴はありません。</div>
    @endif
</div>

{{-- ========== カード: 備考 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="ms-card-title">備考</div>
    @if($parkingContract->memo)
        <div style="font-size: 14px; color: #374151; line-height: 1.7; white-space: pre-wrap;">{{ $parkingContract->memo }}</div>
    @else
        <div style="font-size: 14px; color: #9ca3af;">—</div>
    @endif
</div>

@endsection
