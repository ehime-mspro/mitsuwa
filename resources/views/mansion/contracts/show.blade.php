@extends('layouts.app')

@section('title', $contract->tenant?->name . ' / ' . ($contract->room?->room_number ?? '—') . '号室')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.contracts.index') }}" class="hover:text-emerald-600 transition-colors">部屋契約一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $contract->tenant?->name ?? '—' }} / {{ $contract->room?->room_number ?? '—' }}号室</span>
@endsection

@section('content')

@php
    $role = auth()->user()->role;
    $canEdit = $role->isManagerOrAbove();
    $isTerminated = $contract->isTerminated();

    // 部屋情報（null 安全）
    $property = $contract->room?->property;
    $room = $contract->room;

    // 金額表示用にフォーマット済み文字列を用意（@json 内で number_format を使わないため、事前に整形）
    $rentDisplay = $contract->rent ? (number_format($contract->rent) . '円') : '—';
    $commonFeeDisplay = $contract->common_fee ? (number_format($contract->common_fee) . '円') : '—';
    $depositDisplay = $contract->deposit ? (number_format($contract->deposit) . '円') : '—';
    $keyMoneyDisplay = $contract->key_money ? (number_format($contract->key_money) . '円') : '—';
@endphp

<style>
    .ms-badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .ms-card-title { font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981; }
    .ms-info-row { display: grid; grid-template-columns: 140px 1fr; padding: 8px 0; border-bottom: 1px dashed #e5e7eb; font-size: 14px; }
    .ms-info-row:last-child { border-bottom: none; }
    .ms-info-label { color: #6b7280; font-weight: 600; }
    .ms-info-value { color: #111827; }
    /* 金額情報グリッド（モック踏襲） */
    .ms-money-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
    .ms-money-cell { padding: 14px 16px; border-right: 1px solid #e5e7eb; }
    .ms-money-cell:last-child { border-right: none; }
    .ms-money-label { font-size: 12px; color: #6b7280; margin-bottom: 4px; }
    .ms-money-value { font-size: 18px; font-weight: 700; color: #111827; }

    /* モバイル: 金額 4 列は 2 列へ */
    @media (max-width: 640px) {
        .ms-money-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
</style>

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; gap: 12px; flex-wrap: wrap;">
    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <h1 style="font-size: 20px; font-weight: 700; margin: 0;">
            {{ $contract->tenant?->name ?? '—' }} / {{ $contract->room?->room_number ?? '—' }}号室
        </h1>
        <span class="ms-badge" style="{{ $contract->status->badgeStyle() }}">{{ $contract->status->label() }}</span>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="{{ route('mansion.contracts.index') }}"
           style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
            <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            一覧に戻る
        </a>
        @if($canEdit && !$isTerminated)
            {{-- 賃料改定（GET画面遷移） --}}
            <a href="{{ route('mansion.contracts.revise.show', $contract) }}"
               style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #fbbf24; border-radius: 6px; background: #fffbeb; font-size: 13px; color: #92400e; text-decoration: none;">
                賃料改定
            </a>
            {{-- 解約処理（GET画面遷移） --}}
            <a href="{{ route('mansion.contracts.terminate.show', $contract) }}"
               style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #fecaca; border-radius: 6px; background: #fef2f2; font-size: 13px; color: #b91c1c; text-decoration: none;">
                解約処理
            </a>
            {{-- 編集 --}}
            <a href="{{ route('mansion.contracts.edit', $contract) }}"
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
            {{-- 物件名 --}}
            <div class="ms-info-row">
                <div class="ms-info-label">物件</div>
                <div class="ms-info-value">
                    @if($property)
                        <a href="{{ route('mansion.properties.show', $property) }}" class="hover:underline" style="color: #047857; font-weight: 600;">{{ $property->property_name }}</a>
                    @else
                        —
                    @endif
                </div>
            </div>
            {{-- 号室（部屋タイプ・面積を補足表示） --}}
            <div class="ms-info-row">
                <div class="ms-info-label">号室</div>
                <div class="ms-info-value">
                    @if($room)
                        {{ $room->room_number }}号室
                        @if($room->room_type || $room->area_sqm)
                            <span style="color: #6b7280; font-size: 13px;">
                                （{{ $room->room_type }}{{ $room->room_type && $room->area_sqm ? ' / ' : '' }}{{ $room->area_sqm ? $room->area_sqm . '㎡' : '' }}）
                            </span>
                        @endif
                    @else
                        —
                    @endif
                </div>
            </div>
            {{-- 入居者 --}}
            <div class="ms-info-row">
                <div class="ms-info-label">入居者</div>
                <div class="ms-info-value">
                    @if($contract->tenant)
                        <a href="{{ route('mansion.tenants.show', $contract->tenant) }}" class="hover:underline" style="color: #047857; font-weight: 600;">{{ $contract->tenant->name }}</a>
                    @else
                        —
                    @endif
                </div>
            </div>
        </div>
        <div>
            {{-- 担当者 --}}
            <div class="ms-info-row">
                <div class="ms-info-label">担当者</div>
                <div class="ms-info-value">{{ $contract->staff?->name ?? '—' }}</div>
            </div>
            {{-- 契約日 --}}
            <div class="ms-info-row">
                <div class="ms-info-label">契約日</div>
                <div class="ms-info-value">{{ $contract->contract_date?->format('Y/m/d') ?? '—' }}</div>
            </div>
            {{-- 入居日 --}}
            <div class="ms-info-row">
                <div class="ms-info-label">入居日</div>
                <div class="ms-info-value">{{ $contract->move_in_date?->format('Y/m/d') ?? '—' }}</div>
            </div>
            {{-- 退去日（解約済みの場合のみ表示） --}}
            @if($isTerminated)
                <div class="ms-info-row">
                    <div class="ms-info-label">退去日</div>
                    <div class="ms-info-value" style="color: #b91c1c; font-weight: 600;">{{ $contract->move_out_date?->format('Y/m/d') ?? '—' }}</div>
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
            <div class="ms-money-label">賃料（月額・税抜）</div>
            <div class="ms-money-value">{{ $rentDisplay }}</div>
        </div>
        <div class="ms-money-cell">
            <div class="ms-money-label">共益費（月額）</div>
            <div class="ms-money-value">{{ $commonFeeDisplay }}</div>
        </div>
        <div class="ms-money-cell">
            <div class="ms-money-label">敷金</div>
            <div class="ms-money-value">{{ $depositDisplay }}</div>
        </div>
        <div class="ms-money-cell">
            <div class="ms-money-label">礼金</div>
            <div class="ms-money-value">{{ $keyMoneyDisplay }}</div>
        </div>
    </div>
    <div style="font-size: 12px; color: #6b7280; margin-top: 10px;">
        ※ 駐車場料金は別管理（下記「紐付け駐車場契約」カード参照）
    </div>
</div>

{{-- ========== カード: 紐付け駐車場契約 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
        <div class="ms-card-title" style="margin-bottom: 0;">紐付け駐車場契約（{{ $contract->parkingContracts->count() }}件）</div>
    </div>
    @if($contract->parkingContracts->count() > 0)
        <div class="scroll-hint at-start">
        <div class="scroll-hint-inner">
        <table class="w-full border-collapse" style="table-layout: fixed; min-width: 640px;">
            <colgroup>
                <col style="width: 26%">
                <col style="width: 16%">
                <col style="width: 16%">
                <col style="width: 18%">
                <col style="width: 14%">
                <col style="width: 10%">
            </colgroup>
            <thead>
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">駐車場番号</th>
                    <th class="px-4 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">契約日</th>
                    <th class="px-4 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">利用開始日</th>
                    <th class="px-4 py-2 text-right text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">月額料金</th>
                    <th class="px-4 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">ステータス</th>
                    <th class="px-4 py-2 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach($contract->parkingContracts as $pc)
                    <tr>
                        <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap font-semibold">
                            {{ $pc->parking?->parking_number ?? '—' }}
                            @if($pc->parking)
                                <span style="font-size: 12px; color: #6b7280; font-weight: 400;">
                                    （{{ $pc->parking->has_roof ? '屋根あり' : '屋根なし' }}）
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap">
                            {{ $pc->contract_date?->format('Y/m/d') ?? '—' }}
                        </td>
                        <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap">
                            {{ $pc->start_date?->format('Y/m/d') ?? '—' }}
                        </td>
                        <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 text-right whitespace-nowrap">
                            @if($pc->monthly_fee)
                                {{ number_format($pc->monthly_fee) }}円
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 border-b border-gray-200 whitespace-nowrap">
                            <span class="ms-badge" style="{{ $pc->status->badgeStyle() }}">{{ $pc->status->label() }}</span>
                        </td>
                        <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                            {{-- 駐車場契約の詳細画面は未実装（Phase G 予定）。ルート定義後にリンク有効化予定 --}}
                            <span class="text-xs text-gray-400">—</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        <div class="scroll-hint-text">← スクロールできます →</div>
        </div>
    @else
        <div style="padding: 28px 12px; text-align: center; color: #9ca3af; font-size: 13px;">紐付く駐車場契約はありません。</div>
    @endif
</div>

{{-- ========== カード: 賃料改定履歴 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="ms-card-title">賃料改定履歴（{{ $contract->revisions->count() }}件）</div>
    @if($contract->revisions->count() > 0)
        <div class="scroll-hint at-start">
        <div class="scroll-hint-inner">
        <table class="w-full border-collapse" style="table-layout: fixed; min-width: 620px;">
            <colgroup>
                <col style="width: 15%">
                <col style="width: 15%">
                <col style="width: 15%">
                <col style="width: 55%">
            </colgroup>
            <thead>
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">改定日</th>
                    <th class="px-4 py-2 text-right text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">新賃料</th>
                    <th class="px-4 py-2 text-right text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">新共益費</th>
                    <th class="px-4 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">改定理由</th>
                </tr>
            </thead>
            <tbody>
                @foreach($contract->revisions as $rev)
                    <tr>
                        <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap">
                            {{ $rev->revision_date?->format('Y/m/d') ?? '—' }}
                        </td>
                        <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 text-right whitespace-nowrap">
                            @if($rev->new_rent)
                                {{ number_format($rev->new_rent) }}円
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 text-right whitespace-nowrap">
                            @if($rev->new_common_fee)
                                {{ number_format($rev->new_common_fee) }}円
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
        </div>
        <div class="scroll-hint-text">← スクロールできます →</div>
        </div>
        <div style="font-size: 12px; color: #6b7280; margin-top: 10px;">
            ※ 「賃料改定」ボタンから新しい改定を登録できます。駐車場料金の改定は駐車場契約詳細画面から行います。
        </div>
    @else
        <div style="padding: 28px 12px; text-align: center; color: #9ca3af; font-size: 13px;">改定履歴はありません。</div>
    @endif
</div>

{{-- ========== カード: 備考 ========== --}}
@if($contract->memo)
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="ms-card-title">備考</div>
        <div style="font-size: 14px; color: #374151; line-height: 1.7; white-space: pre-wrap;">{{ $contract->memo }}</div>
    </div>
@endif

@endsection
