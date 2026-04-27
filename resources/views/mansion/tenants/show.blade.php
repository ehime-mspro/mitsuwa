@extends('layouts.app')

@section('title', $tenant->name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.tenants.index') }}" class="hover:text-emerald-600 transition-colors">入居者管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $tenant->name }}</span>
@endsection

@section('content')

@php
    $role = auth()->user()->role;
    $canEdit = $role->isManagerOrAbove();

    // 部屋契約・駐車場契約の取得
    $roomContract = $tenant->activeContract;
    $parkingContracts = $tenant->activeParkingContracts;
@endphp

<style>
    .ms-badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .ms-card-title { font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981; }
    .ms-info-row { display: grid; grid-template-columns: 140px 1fr; padding: 8px 0; border-bottom: 1px dashed #e5e7eb; font-size: 14px; }
    .ms-info-row:last-child { border-bottom: none; }
    .ms-info-label { color: #6b7280; font-weight: 600; }
    .ms-info-value { color: #111827; }
</style>

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; gap: 12px; flex-wrap: wrap;">
    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <h1 style="font-size: 20px; font-weight: 700; margin: 0;">{{ $tenant->name }}</h1>
        <span class="ms-badge" style="{{ $tenant->tenant_type->badgeStyle() }}">{{ $tenant->tenant_type->label() }}</span>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="{{ route('mansion.tenants.index') }}"
           style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
            <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            一覧に戻る
        </a>
        @if($canEdit)
            <a href="{{ route('mansion.tenants.application', $tenant) }}"
               style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
                <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                入居申込書を表示
            </a>
            <a href="{{ route('mansion.tenants.edit', $tenant) }}"
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
    <div class="ms-info-row">
        <div class="ms-info-label">氏名</div>
        <div class="ms-info-value">{{ $tenant->name }}</div>
    </div>
    <div class="ms-info-row">
        <div class="ms-info-label">利用者区分</div>
        <div class="ms-info-value"><span class="ms-badge" style="{{ $tenant->tenant_type->badgeStyle() }}">{{ $tenant->tenant_type->label() }}</span></div>
    </div>
    <div class="ms-info-row">
        <div class="ms-info-label">電話</div>
        <div class="ms-info-value">{{ $tenant->phone ?: '—' }}</div>
    </div>
    <div class="ms-info-row">
        <div class="ms-info-label">メール</div>
        <div class="ms-info-value">{{ $tenant->email ?: '—' }}</div>
    </div>
    <div class="ms-info-row">
        <div class="ms-info-label">勤務先</div>
        <div class="ms-info-value">{{ $tenant->workplace ?: '—' }}</div>
    </div>
</div>

{{-- ========== カード: 緊急連絡先 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="ms-card-title">緊急連絡先</div>
    <div class="ms-info-row">
        <div class="ms-info-label">氏名</div>
        <div class="ms-info-value">{{ $tenant->emergency_contact_name ?: '—' }}</div>
    </div>
    <div class="ms-info-row">
        <div class="ms-info-label">電話</div>
        <div class="ms-info-value">{{ $tenant->emergency_contact_phone ?: '—' }}</div>
    </div>
    <div class="ms-info-row">
        <div class="ms-info-label">続柄</div>
        <div class="ms-info-value">{{ $tenant->emergency_contact_relation ?: '—' }}</div>
    </div>
</div>

{{-- ========== カード: 部屋契約（resident のみ表示） ========== --}}
@if($tenant->tenant_type->value === 'resident')
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="ms-card-title" style="display: flex; align-items: center; justify-content: space-between;">
            <span>部屋契約</span>
            <span style="font-size: 12px; font-weight: 500; color: #6b7280;">{{ $roomContract ? '1' : '0' }} 件</span>
        </div>
        @if($roomContract && $roomContract->room)
            <table class="w-full" style="border-collapse: collapse; table-layout: fixed;">
                <colgroup>
                    <col style="width: 34%">
                    <col style="width: 18%">
                    <col style="width: 18%">
                    <col style="width: 16%">
                    <col style="width: 14%">
                </colgroup>
                <thead>
                    <tr style="background: #f9fafb;">
                        <th style="padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">物件 / 号室</th>
                        <th style="padding: 10px 12px; text-align: center; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">契約日</th>
                        <th style="padding: 10px 12px; text-align: center; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">入居日</th>
                        <th style="padding: 10px 12px; text-align: right; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">賃料</th>
                        <th style="padding: 10px 12px; text-align: center; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">ステータス</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding: 10px 12px; font-size: 13px; color: #111827; border-bottom: 1px solid #f3f4f6;">
                            @php
                                $roomPropertyName = $roomContract->room->property->property_name ?? '';
                                $roomPropertyId = $roomContract->room->property->id ?? null;
                            @endphp
                            @if($roomPropertyId && $canEdit)
                                <a href="{{ route('mansion.properties.show', $roomPropertyId) }}" style="color: #047857; font-weight: 600; text-decoration: none;">{{ $roomPropertyName }}</a>
                            @else
                                {{ $roomPropertyName }}
                            @endif
                            <span style="color: #6b7280;"> / </span>
                            @if($canEdit)
                                <a href="{{ route('mansion.rooms.edit', $roomContract->room) }}" style="color: #047857; font-weight: 600; text-decoration: none;">{{ $roomContract->room->room_number }}号室</a>
                            @else
                                <span style="font-weight: 600;">{{ $roomContract->room->room_number }}号室</span>
                            @endif
                        </td>
                        <td style="padding: 10px 12px; text-align: center; font-size: 13px; color: #374151; border-bottom: 1px solid #f3f4f6;">
                            {{ $roomContract->contract_date?->format('Y/m/d') ?? '—' }}
                        </td>
                        <td style="padding: 10px 12px; text-align: center; font-size: 13px; color: #374151; border-bottom: 1px solid #f3f4f6;">
                            {{ $roomContract->move_in_date?->format('Y/m/d') ?? '—' }}
                        </td>
                        <td style="padding: 10px 12px; text-align: right; font-size: 13px; color: #111827; font-weight: 500; border-bottom: 1px solid #f3f4f6;">
                            {{ $roomContract->rent ? number_format($roomContract->rent) . '円' : '—' }}
                        </td>
                        <td style="padding: 10px 12px; text-align: center; border-bottom: 1px solid #f3f4f6;">
                            <span class="ms-badge" style="{{ $roomContract->status->badgeStyle() }}">{{ $roomContract->status->label() }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        @else
            <div style="padding: 28px 12px; text-align: center; color: #9ca3af; font-size: 13px;">有効な部屋契約はありません。</div>
        @endif
    </div>
@endif

{{-- ========== カード: 駐車場契約 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="ms-card-title" style="display: flex; align-items: center; justify-content: space-between;">
        <span>駐車場契約</span>
        <span style="font-size: 12px; font-weight: 500; color: #6b7280;">{{ $parkingContracts->count() }} 件</span>
    </div>
    @if($parkingContracts->count() > 0)
        <table class="w-full" style="border-collapse: collapse; table-layout: fixed;">
            <colgroup>
                <col style="width: 30%">
                <col style="width: 20%">
                <col style="width: 18%">
                <col style="width: 18%">
                <col style="width: 14%">
            </colgroup>
            <thead>
                <tr style="background: #f9fafb;">
                    <th style="padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">物件 / 駐車場番号</th>
                    <th style="padding: 10px 12px; text-align: center; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">紐付け</th>
                    <th style="padding: 10px 12px; text-align: center; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">契約日</th>
                    <th style="padding: 10px 12px; text-align: right; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">月額料金</th>
                    <th style="padding: 10px 12px; text-align: center; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">ステータス</th>
                </tr>
            </thead>
            <tbody>
                @foreach($parkingContracts as $pc)
                    @php
                        // 部屋契約と連動している場合はそれを示す文言を作る
                        $linkedRoomNumber = $pc->contract?->room?->room_number ?? null;
                        $linkLabel = $linkedRoomNumber ? ($linkedRoomNumber . '号室契約と連動') : '単独契約';

                        $parkingPropertyName = $pc->parking?->property?->property_name ?? '';
                        $parkingPropertyId = $pc->parking?->property?->id ?? null;
                    @endphp
                    <tr>
                        <td style="padding: 10px 12px; font-size: 13px; color: #111827; border-bottom: 1px solid #f3f4f6;">
                            @if($parkingPropertyId && $canEdit)
                                <a href="{{ route('mansion.properties.show', $parkingPropertyId) }}" style="color: #047857; font-weight: 600; text-decoration: none;">{{ $parkingPropertyName }}</a>
                            @else
                                {{ $parkingPropertyName }}
                            @endif
                            <span style="color: #6b7280;"> / </span>
                            @if($pc->parking && $canEdit)
                                <a href="{{ route('mansion.parkings.edit', $pc->parking) }}" style="color: #047857; font-weight: 600; text-decoration: none;">{{ $pc->parking->parking_number }}</a>
                            @else
                                <span style="font-weight: 600;">{{ $pc->parking->parking_number ?? '—' }}</span>
                            @endif
                        </td>
                        <td style="padding: 10px 12px; text-align: center; font-size: 13px; color: #6b7280; border-bottom: 1px solid #f3f4f6;">
                            {{ $linkLabel }}
                        </td>
                        <td style="padding: 10px 12px; text-align: center; font-size: 13px; color: #374151; border-bottom: 1px solid #f3f4f6;">
                            {{ $pc->contract_date?->format('Y/m/d') ?? '—' }}
                        </td>
                        <td style="padding: 10px 12px; text-align: right; font-size: 13px; color: #111827; font-weight: 500; border-bottom: 1px solid #f3f4f6;">
                            {{ $pc->monthly_fee ? number_format($pc->monthly_fee) . '円' : '—' }}
                        </td>
                        <td style="padding: 10px 12px; text-align: center; border-bottom: 1px solid #f3f4f6;">
                            <span class="ms-badge" style="{{ $pc->status->badgeStyle() }}">{{ $pc->status->label() }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div style="padding: 28px 12px; text-align: center; color: #9ca3af; font-size: 13px;">有効な駐車場契約はありません。</div>
    @endif
</div>

{{-- ========== カード: 備考 ========== --}}
@if($tenant->notes)
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="ms-card-title">備考</div>
        <div style="font-size: 14px; color: #374151; line-height: 1.7; white-space: pre-wrap;">{{ $tenant->notes }}</div>
    </div>
@endif

{{-- 補足 --}}
<div style="padding: 12px 16px; background: #f9fafb; border-radius: 8px; font-size: 12px; color: #6b7280;">
    <strong style="color: #374151;">※表示ルール</strong>：入居者（resident）は部屋契約と駐車場契約の両方を表示。駐車場のみ利用（parking_only）の場合は駐車場契約のみ表示。
</div>

@endsection
