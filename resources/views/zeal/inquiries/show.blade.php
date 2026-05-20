@extends('layouts.app')

@section('title', $inquiry->name . ' — 体験予約詳細')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.dashboard') }}" class="hover:text-emerald-600 transition-colors">ZEAL</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.inquiries.index') }}" class="hover:text-emerald-600 transition-colors">体験予約一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $inquiry->name }}</span>
@endsection

@section('content')

<style>
    .zeal-badge {
        display: inline-flex; align-items: center;
        padding: 2px 10px; border-radius: 9999px;
        font-size: 11px; font-weight: 600; white-space: nowrap;
    }
    .badge-scheduling { background: #f3f4f6; color: #374151; }
    .badge-scheduled  { background: #dbeafe; color: #1d4ed8; }
    .badge-not-joined { background: #fef3c7; color: #92400e; }
    .badge-joined     { background: #d1fae5; color: #065f46; }
    .badge-withdrew   { background: #fee2e2; color: #991b1b; }
    .badge-no-followup{ background: #f3f4f6; color: #6b7280; }
    .badge-unknown    { background: #f3f4f6; color: #6b7280; }
    .zeal-card-title {
        font-size: 15px; font-weight: 700; color: #111827;
        margin-bottom: 14px; padding-left: 12px;
        border-left: 4px solid #10b981;
    }
    .zeal-info-row {
        display: grid; grid-template-columns: 160px 1fr;
        padding: 8px 0; border-bottom: 1px dashed #e5e7eb;
        font-size: 14px;
    }
    .zeal-info-row:last-child { border-bottom: none; }
    .zeal-info-label { color: #6b7280; font-weight: 600; }
    .zeal-info-value { color: #111827; }
</style>

@php
    $badgeClass = match($inquiry->status) {
        '日程調整中' => 'badge-scheduling',
        '来店予定'   => 'badge-scheduled',
        '未入会'     => 'badge-not-joined',
        '入会'       => 'badge-joined',
        '退会'       => 'badge-withdrew',
        '追撃不要'   => 'badge-no-followup',
        default      => 'badge-unknown',
    };
@endphp

{{-- ページヘッダー --}}
<div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px; gap: 12px; flex-wrap: wrap;">
    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <h1 style="font-size: 20px; font-weight: 700; margin: 0;">{{ $inquiry->name }}</h1>
        @if($inquiry->status)
            <span class="zeal-badge {{ $badgeClass }}">{{ $inquiry->status }}</span>
        @endif
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
        {{-- 入会会員が紐付く場合: 会員詳細へのリンク --}}
        @if($member)
            <a href="{{ route('zeal.members.show', $member->id) }}"
               style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none;">
                <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                </svg>
                会員詳細へ（{{ $member->name }}）
            </a>
        @endif
        <a href="{{ route('zeal.inquiries.index') }}"
           style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
            <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
            一覧に戻る
        </a>
    </div>
</div>

{{-- ========== 基本情報 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="zeal-card-title">予約・体験情報</div>
    <div class="zeal-info-row">
        <div class="zeal-info-label">問合せ日</div>
        <div class="zeal-info-value">{{ $inquiry->inquiry_date ? $inquiry->inquiry_date->format('Y年m月d日') : '—' }}</div>
    </div>
    <div class="zeal-info-row">
        <div class="zeal-info-label">体験日</div>
        <div class="zeal-info-value">{{ $inquiry->trial_date ? $inquiry->trial_date->format('Y年m月d日') : '—' }}</div>
    </div>
    <div class="zeal-info-row">
        <div class="zeal-info-label">体験時間</div>
        <div class="zeal-info-value">
            {{ $inquiry->trial_time ? \Illuminate\Support\Carbon::createFromFormat('H:i:s', $inquiry->trial_time)->format('H:i') : '—' }}
        </div>
    </div>
    <div class="zeal-info-row">
        <div class="zeal-info-label">ステータス</div>
        <div class="zeal-info-value">
            @if($inquiry->status)
                <span class="zeal-badge {{ $badgeClass }}">{{ $inquiry->status }}</span>
            @else
                <span style="color: #9ca3af;">—</span>
            @endif
        </div>
    </div>
    <div class="zeal-info-row">
        <div class="zeal-info-label">契約プラン</div>
        <div class="zeal-info-value" style="font-weight: 700; color: #047857;">{{ $inquiry->contract_plan_display ?: '—' }}</div>
    </div>
</div>

{{-- ========== 顧客情報 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="zeal-card-title">顧客情報</div>
    <div class="zeal-info-row">
        <div class="zeal-info-label">氏名</div>
        <div class="zeal-info-value" style="font-weight: 600;">{{ $inquiry->name }}</div>
    </div>
    <div class="zeal-info-row">
        <div class="zeal-info-label">性別</div>
        <div class="zeal-info-value">{{ $inquiry->gender ?: '—' }}</div>
    </div>
    <div class="zeal-info-row">
        <div class="zeal-info-label">年齢</div>
        <div class="zeal-info-value">{{ $inquiry->age !== null ? $inquiry->age . '歳' : '—' }}</div>
    </div>
    <div class="zeal-info-row">
        <div class="zeal-info-label">電話</div>
        <div class="zeal-info-value">{{ $inquiry->phone ?: '—' }}</div>
    </div>
    <div class="zeal-info-row">
        <div class="zeal-info-label">メール</div>
        <div class="zeal-info-value">{{ $inquiry->email ?: '—' }}</div>
    </div>
</div>

{{-- ========== 目的・備考 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="zeal-card-title">目的・備考</div>
    <div class="zeal-info-row">
        <div class="zeal-info-label">目的</div>
        <div class="zeal-info-value">{{ $inquiry->purpose ?: '—' }}</div>
    </div>
    <div class="zeal-info-row">
        <div class="zeal-info-label">目的詳細</div>
        <div class="zeal-info-value">
            @if($inquiry->purpose_detail)
                <div style="white-space: pre-wrap; line-height: 1.6;">{{ $inquiry->purpose_detail }}</div>
            @else
                —
            @endif
        </div>
    </div>
    <div class="zeal-info-row">
        <div class="zeal-info-label">備考</div>
        <div class="zeal-info-value">
            @if($inquiry->memo)
                <div style="white-space: pre-wrap; line-height: 1.6;">{{ $inquiry->memo }}</div>
            @else
                —
            @endif
        </div>
    </div>
    <div class="zeal-info-row">
        <div class="zeal-info-label">特記事項</div>
        <div class="zeal-info-value">
            @if($inquiry->special_notes)
                <div style="white-space: pre-wrap; line-height: 1.6;">{{ $inquiry->special_notes }}</div>
            @else
                —
            @endif
        </div>
    </div>
</div>

{{-- ========== 入会状況（会員リンク） ========== --}}
@if($member)
    <div class="bg-white border border-emerald-200 rounded-lg p-5" style="margin-bottom: 20px; background: #f0fdf4;">
        <div class="zeal-card-title" style="border-left-color: #059669;">入会済み会員</div>
        <p style="font-size: 14px; color: #374151; margin-bottom: 12px;">
            この体験予約から会員登録された方がいます。
        </p>
        <div class="zeal-info-row">
            <div class="zeal-info-label">会員名</div>
            <div class="zeal-info-value">
                <a href="{{ route('zeal.members.show', $member->id) }}"
                   class="text-sm font-semibold text-emerald-700 hover:text-emerald-900 hover:underline transition-colors">
                    {{ $member->name }}
                </a>
                @if($member->name_kana)
                    <span style="font-size: 12px; color: #6b7280; margin-left: 8px;">{{ $member->name_kana }}</span>
                @endif
            </div>
        </div>
        <div class="zeal-info-row">
            <div class="zeal-info-label">入会日</div>
            <div class="zeal-info-value">{{ $member->joined_on ? $member->joined_on->format('Y年m月d日') : '—' }}</div>
        </div>
        <div class="zeal-info-row">
            <div class="zeal-info-label">在籍状況</div>
            <div class="zeal-info-value">
                @if($member->isActive())
                    <span class="zeal-badge badge-joined">在籍中</span>
                @else
                    <span class="zeal-badge badge-withdrew">退会済み（{{ $member->withdrew_on ? $member->withdrew_on->format('Y/m/d') : '—' }}）</span>
                @endif
            </div>
        </div>
        <div style="margin-top: 14px;">
            <a href="{{ route('zeal.members.show', $member->id) }}"
               style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #059669; color: white; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none;">
                <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                </svg>
                会員詳細を開く
            </a>
        </div>
    </div>
@else
    <div style="padding: 12px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; color: #6b7280; margin-bottom: 20px;">
        この体験予約に紐付く会員はまだ登録されていません。
    </div>
@endif

<div style="font-size: 12px; color: #6b7280;">
    ※ 体験予約データは外部システムからの参照です。この画面からの変更はできません。
</div>

@endsection
