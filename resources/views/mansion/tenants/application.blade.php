@extends('layouts.app')

@section('title', $tenant->name . ' — 入居申込書')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.tenants.index') }}" class="hover:text-emerald-600 transition-colors">入居者管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.tenants.show', $tenant) }}" class="hover:text-emerald-600 transition-colors">{{ $tenant->name }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">入居申込書</span>
@endsection

@section('content')

<style>
    .ms-badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .ms-card-title { font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981; }
    .ms-info-row { display: grid; grid-template-columns: 120px 1fr; padding: 8px 0; border-bottom: 1px dashed #e5e7eb; font-size: 14px; }
    .ms-info-row:last-child { border-bottom: none; }
    .ms-info-label { color: #6b7280; font-weight: 600; }
    .ms-info-value { color: #111827; }
</style>

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; gap: 12px; flex-wrap: wrap;">
    <div>
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <h1 style="font-size: 20px; font-weight: 700; margin: 0;">入居申込書</h1>
            <span class="ms-badge" style="{{ $tenant->tenant_type->badgeStyle() }}">{{ $tenant->tenant_type->label() }}</span>
        </div>
        <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">入居予定者の本人確認書類・収入証明・保証人書類を管理します</div>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="{{ route('mansion.tenants.show', $tenant) }}"
           style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
            <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            入居者詳細に戻る
        </a>
    </div>
</div>

{{-- ========== カード: 申込者情報 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="ms-card-title">申込者情報</div>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0 32px;">
        <div>
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
        </div>
        <div>
            <div class="ms-info-row">
                <div class="ms-info-label">メール</div>
                <div class="ms-info-value">{{ $tenant->email ?: '—' }}</div>
            </div>
            <div class="ms-info-row">
                <div class="ms-info-label">勤務先</div>
                <div class="ms-info-value">{{ $tenant->workplace ?: '—' }}</div>
            </div>
            <div class="ms-info-row">
                <div class="ms-info-label">緊急連絡先</div>
                <div class="ms-info-value">
                    @if($tenant->emergency_contact_name || $tenant->emergency_contact_phone)
                        {{ $tenant->emergency_contact_name ?: '—' }}
                        @if($tenant->emergency_contact_relation)
                            <span style="color: #6b7280;">（{{ $tenant->emergency_contact_relation }}）</span>
                        @endif
                        @if($tenant->emergency_contact_phone)
                            <span style="color: #6b7280;"> / {{ $tenant->emergency_contact_phone }}</span>
                        @endif
                    @else
                        —
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ========== カード: 申込書類（アップロード + 一覧） ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="ms-card-title">申込書類</div>

    {{-- 共通の添付ファイルコンポーネント（Ajax アップロード・削除・削除履歴を内包） --}}
    @include('components.attachment-section', [
        'attachableType'     => 'ms_tenants',
        'attachableId'       => $tenant->id,
        'attachments'        => $tenant->attachments,
        'deletedAttachments' => $tenant->deletedAttachments,
    ])
</div>

{{-- ========== カード: 推奨書類ヒント ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="ms-card-title">推奨書類</div>
    <div style="font-size: 13px; color: #374151; line-height: 1.9;">
        <div>・入居申込書</div>
        <div>・本人確認書類（免許証 / マイナンバーカード 等）</div>
        <div>・収入証明書（源泉徴収票 / 直近3ヶ月の給与明細 等）</div>
        <div>・連帯保証人承諾書 / 保証会社の審査結果</div>
    </div>
    <div style="margin-top: 12px; padding: 10px 14px; background: #f9fafb; border-radius: 6px; font-size: 12px; color: #6b7280;">
        <strong style="color: #374151;">※注意</strong>：PDF / JPG / PNG 等の全形式に対応。1 ファイル 10MB まで。複数ファイルを一度にアップロード可能です。削除は「削除履歴」に残り、管理者（経営層）は必要に応じて確認できます。
    </div>
</div>

@endsection
