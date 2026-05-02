@extends('layouts.app')

@section('title', $member->name . ' — 会員詳細')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.dashboard') }}" class="hover:text-emerald-600 transition-colors">ZEAL</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.members.index') }}" class="hover:text-emerald-600 transition-colors">会員一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $member->name }}</span>
@endsection

@section('content')

@php
    $isActive = $member->withdrew_on === null;
    // プラン変更モーダル用: activePlans を Alpine.js 向けに整形（@json内で関数呼び出し不可）
    $plansForModal = $activePlans->map(function ($p) {
        return [
            'id'                  => $p->id,
            'name'                => $p->name,
            'regular_price_excl'  => $p->regular_price_excl,
            'campaign_price_excl' => $p->campaign_price_excl,
        ];
    })->values();
@endphp

<style>
    [x-cloak] { display: none !important; }

    /* 情報行 */
    .zeal-info-row { display: grid; grid-template-columns: 150px 1fr; padding: 10px 0; border-bottom: 1px dashed #e5e7eb; font-size: 14px; }
    .zeal-info-row:last-child { border-bottom: none; }
    .zeal-info-label { color: #6b7280; font-weight: 600; }
    .zeal-info-value { color: #111827; }

    /* タブ */
    .zeal-tab-btn {
        padding: 10px 20px; font-size: 14px; font-weight: 500; color: #374151;
        cursor: pointer; border-bottom: 2px solid transparent; white-space: nowrap;
        background: none; border-top: none; border-left: none; border-right: none;
        transition: color .15s;
    }
    .zeal-tab-btn:hover { color: #111827; background: #f9fafb; }
    .zeal-tab-active { color: #059669; border-bottom-color: #059669; font-weight: 700; }

    /* バッジ */
    .badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .badge-active   { background: #d1fae5; color: #065f46; }
    .badge-withdrew { background: #f3f4f6; color: #6b7280; }
    .badge-campaign { background: #fef3c7; color: #92400e; }
    .badge-current  { background: #dbeafe; color: #1e40af; }

    /* 契約履歴テーブル */
    .zeal-hist-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .zeal-hist-table thead th {
        background: #f9fafb; color: #374151; font-weight: 700; font-size: 12px;
        text-align: left; padding: 10px 12px; border-bottom: 1px solid #e5e7eb;
    }
    .zeal-hist-table thead th.num { text-align: right; }
    .zeal-hist-table thead th.center { text-align: center; }
    .zeal-hist-table tbody td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; color: #374151; }
    .zeal-hist-table tbody td.num { text-align: right; font-variant-numeric: tabular-nums; }
    .zeal-hist-table tbody td.center { text-align: center; }
    .zeal-hist-table tbody tr:last-child td { border-bottom: none; }
    .zeal-incl-tax { color: #047857; font-weight: 700; }

    /* モーダル */
    .zeal-modal-overlay {
        position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 50;
        display: flex; align-items: center; justify-content: center;
    }
    .zeal-modal-box {
        background: white; border-radius: 12px; padding: 28px;
        width: 520px; max-width: 90vw; max-height: 90vh; overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    }
    .zeal-modal-title { font-size: 16px; font-weight: 700; color: #111827; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid #e5e7eb; }
    .zeal-modal-label { font-size: 12px; font-weight: 700; color: #374151; margin-bottom: 6px; display: block; }
    .zeal-modal-input { width: 100%; height: 36px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; color: #111827; background: white; box-sizing: border-box; }
    .zeal-modal-select { width: 100%; height: 36px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; color: #111827; background: white; box-sizing: border-box; }
    .zeal-modal-row { margin-bottom: 16px; }
</style>

{{-- フラッシュメッセージ --}}
@if(session('success'))
    <div style="padding: 10px 14px; margin-bottom: 16px; background: #d1fae5; border: 1px solid #6ee7b7; border-radius: 8px; font-size: 13px; color: #065f46;">
        {{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div style="padding: 10px 14px; margin-bottom: 16px; background: #fee2e2; border: 1px solid #fca5a5; border-radius: 8px; font-size: 13px; color: #991b1b;">
        {{ session('error') }}
    </div>
@endif

{{-- Alpine.js コンポーネントルート --}}
<div x-data="zealMemberShow()">

    {{-- ページヘッダー --}}
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <h1 style="font-size: 20px; font-weight: 700; margin: 0;">{{ $member->name }}</h1>
            @if($isActive)
                <span class="badge badge-active">在籍中</span>
            @else
                <span class="badge badge-withdrew">退会済み</span>
            @endif
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="{{ route('zeal.members.index') }}"
               style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
                <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                一覧に戻る
            </a>
            <a href="{{ route('zeal.members.edit', $member) }}"
               style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #059669; color: white; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; border: none;">
                <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                編集
            </a>
            @if(auth()->user()->role->isExecutive())
                <form method="POST" action="{{ route('zeal.members.destroy', $member) }}"
                      onsubmit="return confirm('「{{ $member->name }}」を削除します。この操作は取り消せません。よろしいですか？')">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: white; color: #dc2626; border: 1px solid #fca5a5; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                        削除
                    </button>
                </form>
            @endif
        </div>
    </div>

    {{-- ========== タブコンテナ ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">

        {{-- タブヘッダー --}}
        <div style="display: flex; border-bottom: 1px solid #e5e7eb; overflow-x: auto;">
            <button type="button" class="zeal-tab-btn"
                    :class="{ 'zeal-tab-active': tab === 'basic' }"
                    @click="tab = 'basic'">基本情報</button>
            <button type="button" class="zeal-tab-btn"
                    :class="{ 'zeal-tab-active': tab === 'recruit' }"
                    @click="tab = 'recruit'">集客・目的</button>
            <button type="button" class="zeal-tab-btn"
                    :class="{ 'zeal-tab-active': tab === 'history' }"
                    @click="tab = 'history'">契約履歴</button>
        </div>

        {{-- ========== Tab 1: 基本情報 ========== --}}
        <div x-show="tab === 'basic'" x-cloak style="padding: 24px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">

                {{-- 左列: 個人情報 --}}
                <div>
                    <div style="font-size: 13px; font-weight: 700; color: #6b7280; margin-bottom: 12px; letter-spacing: 0.05em;">個人情報</div>
                    <div class="zeal-info-row">
                        <div class="zeal-info-label">氏名</div>
                        <div class="zeal-info-value">{{ $member->name }}</div>
                    </div>
                    <div class="zeal-info-row">
                        <div class="zeal-info-label">フリガナ</div>
                        <div class="zeal-info-value" style="color: #6b7280;">{{ $member->name_kana }}</div>
                    </div>
                    <div class="zeal-info-row">
                        <div class="zeal-info-label">性別</div>
                        <div class="zeal-info-value">{{ $member->gender?->label() ?? '—' }}</div>
                    </div>
                    <div class="zeal-info-row">
                        <div class="zeal-info-label">生年月日</div>
                        <div class="zeal-info-value">
                            @if($member->birthday)
                                {{ $member->birthday->format('Y-m-d') }}
                                @if($member->age() !== null)
                                    <span style="color: #6b7280; font-size: 12px;">（{{ $member->age() }}歳）</span>
                                @endif
                            @else
                                <span style="color: #9ca3af;">—</span>
                            @endif
                        </div>
                    </div>
                    <div class="zeal-info-row">
                        <div class="zeal-info-label">電話</div>
                        <div class="zeal-info-value">{{ $member->phone ?: '—' }}</div>
                    </div>
                    <div class="zeal-info-row">
                        <div class="zeal-info-label">メール</div>
                        <div class="zeal-info-value">{{ $member->email ?: '—' }}</div>
                    </div>
                    <div class="zeal-info-row">
                        <div class="zeal-info-label">郵便番号</div>
                        <div class="zeal-info-value">{{ $member->postal_code ?: '—' }}</div>
                    </div>
                    <div class="zeal-info-row">
                        <div class="zeal-info-label">住所</div>
                        <div class="zeal-info-value">{{ $member->address ?: '—' }}</div>
                    </div>
                </div>

                {{-- 右列: 契約情報 --}}
                <div>
                    <div style="font-size: 13px; font-weight: 700; color: #6b7280; margin-bottom: 12px; letter-spacing: 0.05em;">契約情報</div>
                    <div class="zeal-info-row">
                        <div class="zeal-info-label">入会日</div>
                        <div class="zeal-info-value">{{ $member->joined_on?->format('Y-m-d') ?? '—' }}</div>
                    </div>
                    <div class="zeal-info-row">
                        <div class="zeal-info-label">担当トレーナー</div>
                        <div class="zeal-info-value">{{ $member->trainer?->name ?? '—' }}</div>
                    </div>
                    @if($isActive)
                        <div class="zeal-info-row">
                            <div class="zeal-info-label">現在のプラン</div>
                            <div class="zeal-info-value" style="font-weight: 700; color: #047857;">
                                {{ $member->currentPlan?->name ?? '—' }}
                            </div>
                        </div>
                        @if($member->currentPlan)
                            <div class="zeal-info-row">
                                <div class="zeal-info-label">月会費（税抜）</div>
                                <div class="zeal-info-value">
                                    {{ number_format($member->currentPlan->regular_price_excl) }}円
                                    <span style="font-size: 12px; color: #6b7280; margin-left: 6px;">
                                        （税込 {{ number_format((int)round($member->currentPlan->regular_price_excl * 1.1)) }}円）
                                    </span>
                                </div>
                            </div>
                        @endif
                    @else
                        <div class="zeal-info-row">
                            <div class="zeal-info-label">退会日</div>
                            <div class="zeal-info-value">{{ $member->withdrew_on?->format('Y-m-d') ?? '—' }}</div>
                        </div>
                        <div class="zeal-info-row">
                            <div class="zeal-info-label">退会理由</div>
                            <div class="zeal-info-value">{{ $member->withdraw_reason?->label() ?? '—' }}</div>
                        </div>
                        @if($member->withdraw_note)
                            <div class="zeal-info-row">
                                <div class="zeal-info-label">退会備考</div>
                                <div class="zeal-info-value" style="color: #6b7280; font-size: 13px;">{{ $member->withdraw_note }}</div>
                            </div>
                        @endif
                    @endif
                    @if($member->memo)
                        <div class="zeal-info-row">
                            <div class="zeal-info-label">メモ</div>
                            <div class="zeal-info-value" style="color: #6b7280; font-size: 13px; white-space: pre-line;">{{ $member->memo }}</div>
                        </div>
                    @endif
                </div>

            </div>
        </div>

        {{-- ========== Tab 2: 集客・目的 ========== --}}
        <div x-show="tab === 'recruit'" x-cloak style="padding: 24px;">
            <div class="zeal-info-row">
                <div class="zeal-info-label">集客チャネル</div>
                <div class="zeal-info-value">{{ $member->acquisition_source?->label() ?? '—' }}</div>
            </div>
            <div class="zeal-info-row">
                <div class="zeal-info-label">入会目的</div>
                <div class="zeal-info-value">{{ $member->purpose?->label() ?? '—' }}</div>
            </div>
            <div class="zeal-info-row">
                <div class="zeal-info-label">体験予約</div>
                <div class="zeal-info-value">
                    @if($member->gymInquiry)
                        <a href="{{ route('zeal.inquiries.show', $member->gym_inquiry_id) }}"
                           style="color: #047857; font-weight: 600; text-decoration: none;">
                            体験予約 #{{ $member->gym_inquiry_id }} の詳細へ →
                        </a>
                    @else
                        <span style="color: #9ca3af;">紐付けなし</span>
                    @endif
                </div>
            </div>
            @if($member->pair_parent_member_id && $member->pairParent)
                <div class="zeal-info-row">
                    <div class="zeal-info-label">ペア主契約者</div>
                    <div class="zeal-info-value">
                        <a href="{{ route('zeal.members.show', $member->pairParent) }}"
                           style="color: #047857; font-weight: 600; text-decoration: none;">
                            {{ $member->pairParent->name }} →
                        </a>
                    </div>
                </div>
            @endif
        </div>

        {{-- ========== Tab 3: 契約履歴 ========== --}}
        <div x-show="tab === 'history'" x-cloak style="padding: 24px;">

            {{-- アクションボタン（在籍中のみ表示）--}}
            @if($isActive)
                <div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
                    <button type="button" @click="showPlanModal = true"
                            style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        プラン変更
                    </button>
                    <button type="button" @click="showWithdrawModal = true"
                            style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: white; color: #dc2626; border: 1px solid #fca5a5; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        退会処理
                    </button>
                </div>
            @endif

            {{-- 契約履歴テーブル --}}
            @if($contracts->isEmpty())
                <div style="padding: 24px; text-align: center; color: #9ca3af; font-size: 14px;">
                    契約履歴がありません。
                </div>
            @else
                <div style="overflow-x: auto;">
                    <table class="zeal-hist-table">
                        <thead>
                            <tr>
                                <th>期間</th>
                                <th>プラン名</th>
                                <th class="num">適用価格（税抜）</th>
                                <th class="num">税込</th>
                                <th class="center">キャンペーン</th>
                                <th>変更理由</th>
                                <th>備考</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($contracts as $contract)
                                <tr style="{{ $contract->isCurrent() ? 'background: #f0fdf4;' : '' }}">
                                    <td>
                                        @if($contract->isCurrent())
                                            <span style="font-weight: 700; color: #047857;">
                                                {{ $contract->period_start->format('Y-m-d') }} 〜 現在
                                            </span>
                                            <span class="badge badge-current" style="margin-left: 6px; font-size: 10px;">現在</span>
                                        @else
                                            <span style="color: #6b7280;">
                                                {{ $contract->period_start->format('Y-m-d') }} 〜 {{ $contract->period_end->format('Y-m-d') }}
                                            </span>
                                        @endif
                                    </td>
                                    <td style="{{ $contract->isCurrent() ? 'font-weight: 600;' : 'color: #6b7280;' }}">
                                        {{ $contract->plan?->name ?? '（削除済みプラン）' }}
                                    </td>
                                    <td class="num" style="{{ $contract->isCurrent() ? '' : 'color: #6b7280;' }}">
                                        {{ number_format($contract->applied_price_excl) }}円
                                    </td>
                                    <td class="num {{ $contract->isCurrent() ? 'zeal-incl-tax' : '' }}"
                                        style="{{ $contract->isCurrent() ? '' : 'color: #9ca3af;' }}">
                                        {{ number_format($contract->appliedPriceIncl()) }}円
                                    </td>
                                    <td class="center">
                                        @if($contract->is_campaign_applied)
                                            <span class="badge badge-campaign" style="font-size: 10px;">適用</span>
                                        @else
                                            <span style="color: #9ca3af;">—</span>
                                        @endif
                                    </td>
                                    <td style="color: #6b7280;">
                                        {{ $contract->change_reason?->label() ?? '—' }}
                                    </td>
                                    <td style="color: #9ca3af; font-size: 12px;">
                                        {{ $contract->note ?: '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

        </div>

    </div>
    {{-- /タブコンテナ --}}

    {{-- ========== モーダル: プラン変更 ========== --}}
    <div class="zeal-modal-overlay" x-show="showPlanModal" x-cloak @click.self="showPlanModal = false">
        <div class="zeal-modal-box">
            <div class="zeal-modal-title">プラン変更 — {{ $member->name }}</div>

            <form method="POST" action="{{ route('zeal.members.change-plan', $member) }}">
                @csrf

                {{-- 新プラン --}}
                <div class="zeal-modal-row">
                    <label class="zeal-modal-label" for="modal_plan_id">
                        新プラン <span style="color: #dc2626;">*</span>
                    </label>
                    <select id="modal_plan_id" name="plan_id" class="zeal-modal-select"
                            x-model="selectedPlanId"
                            @change="onPlanChange()">
                        <option value="">選択してください</option>
                        @foreach($activePlans as $plan)
                            <option value="{{ $plan->id }}">
                                {{ $plan->name }}（{{ number_format($plan->regular_price_excl) }}円 / {{ number_format((int)round($plan->regular_price_excl * 1.1)) }}円税込）
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- 変更日 --}}
                <div class="zeal-modal-row">
                    <label class="zeal-modal-label" for="modal_change_date">
                        変更日 <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="date" id="modal_change_date" name="change_date"
                           x-model="changeDate" required class="zeal-modal-input">
                    <div style="font-size: 11px; color: #9ca3af; margin-top: 4px;">月初切替が運用ルールです（翌月1日を推奨）</div>
                </div>

                {{-- 適用価格タイプ --}}
                <div class="zeal-modal-row">
                    <label class="zeal-modal-label">適用価格タイプ</label>
                    <div style="display: flex; gap: 16px; margin-top: 4px;">
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 14px; cursor: pointer;">
                            <input type="radio" name="_price_type" value="regular" x-model="priceType"
                                   @change="onPriceTypeChange()" style="accent-color: #059669;">
                            通常価格
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 14px; cursor: pointer;">
                            <input type="radio" name="_price_type" value="campaign" x-model="priceType"
                                   @change="onPriceTypeChange()" style="accent-color: #059669;">
                            キャンペーン価格
                        </label>
                    </div>
                    {{-- キャンペーン適用フラグを hidden で送信 --}}
                    <input type="hidden" name="is_campaign_applied" :value="priceType === 'campaign' ? '1' : '0'">
                </div>

                {{-- 適用価格（税抜）--}}
                <div class="zeal-modal-row">
                    <label class="zeal-modal-label" for="modal_applied_price">
                        適用価格（税抜） <span style="color: #dc2626;">*</span>
                    </label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" id="modal_applied_price" name="applied_price_excl"
                               x-model="appliedPrice" @input="updatePriceIncl()"
                               min="0" max="9999999" inputmode="numeric" required
                               class="zeal-modal-input" style="width: 140px;">
                        <span style="font-size: 13px; color: #047857; font-weight: 600;">
                            税込 <span x-text="priceInclText">—</span>
                        </span>
                    </div>
                    <div style="font-size: 11px; color: #9ca3af; margin-top: 4px;">特例価格の場合は手動で編集できます</div>
                </div>

                {{-- 備考 --}}
                <div class="zeal-modal-row">
                    <label class="zeal-modal-label" for="modal_note">備考</label>
                    <textarea id="modal_note" name="note" rows="3"
                              placeholder="変更理由・備考など"
                              style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; resize: vertical; box-sizing: border-box;"></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px;">
                    <button type="button" @click="showPlanModal = false"
                            style="padding: 8px 16px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; cursor: pointer;">
                        キャンセル
                    </button>
                    <button type="submit"
                            style="padding: 8px 20px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer;">
                        変更を確定する
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ========== モーダル: 退会処理 ========== --}}
    <div class="zeal-modal-overlay" x-show="showWithdrawModal" x-cloak @click.self="showWithdrawModal = false">
        <div class="zeal-modal-box">
            <div class="zeal-modal-title" style="color: #dc2626;">退会処理 — {{ $member->name }}</div>

            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 14px; font-size: 13px; color: #991b1b; margin-bottom: 20px;">
                退会処理後は取り消しできません。内容をよく確認してから実行してください。
            </div>

            <form method="POST" action="{{ route('zeal.members.withdraw', $member) }}">
                @csrf

                {{-- 退会日 --}}
                <div class="zeal-modal-row">
                    <label class="zeal-modal-label" for="modal_withdrew_on">
                        退会日 <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="date" id="modal_withdrew_on" name="withdrew_on"
                           required class="zeal-modal-input">
                </div>

                {{-- 退会理由 --}}
                <div class="zeal-modal-row">
                    <label class="zeal-modal-label" for="modal_withdraw_reason">
                        退会理由 <span style="color: #dc2626;">*</span>
                    </label>
                    <select id="modal_withdraw_reason" name="withdraw_reason" required class="zeal-modal-select">
                        <option value="">選択してください</option>
                        @foreach($withdrawReasons as $reason)
                            <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- 備考 --}}
                <div class="zeal-modal-row">
                    <label class="zeal-modal-label" for="modal_withdraw_note">備考</label>
                    <textarea id="modal_withdraw_note" name="withdraw_note" rows="3"
                              placeholder="退会理由の詳細など"
                              style="width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; resize: vertical; box-sizing: border-box;"></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px;">
                    <button type="button" @click="showWithdrawModal = false"
                            style="padding: 8px 16px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; cursor: pointer;">
                        キャンセル
                    </button>
                    <button type="submit"
                            style="padding: 8px 20px; background: #dc2626; color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer;">
                        退会処理を実行する
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
{{-- /Alpine.js コンポーネントルート --}}

<script>
/**
 * 会員詳細ページの Alpine.js コンポーネント
 * - タブ切替
 * - プラン変更モーダル（プラン選択→価格自動入力）
 * - 退会処理モーダル
 */
function zealMemberShow() {
    var plansData = @json($plansForModal);

    return {
        tab:              'basic',
        showPlanModal:    false,
        showWithdrawModal: false,

        /* プラン変更モーダル用 */
        selectedPlanId: '',
        priceType:      'regular',
        appliedPrice:   '',
        priceInclText:  '—',
        changeDate:     '',

        /** プラン選択時に価格を自動セット */
        onPlanChange: function () {
            var self = this;
            var found = null;
            for (var i = 0; i < plansData.length; i++) {
                if (String(plansData[i].id) === String(self.selectedPlanId)) {
                    found = plansData[i];
                    break;
                }
            }
            if (!found) {
                self.appliedPrice  = '';
                self.priceInclText = '—';
                return;
            }
            self.onPriceTypeChange(found);
        },

        /** 価格タイプ変更時に価格を再セット */
        onPriceTypeChange: function (planOverride) {
            var self = this;
            var found = planOverride || null;
            if (!found) {
                for (var i = 0; i < plansData.length; i++) {
                    if (String(plansData[i].id) === String(self.selectedPlanId)) {
                        found = plansData[i];
                        break;
                    }
                }
            }
            if (!found) {
                return;
            }
            var excl = self.priceType === 'campaign' && found.campaign_price_excl
                ? found.campaign_price_excl
                : found.regular_price_excl;
            self.appliedPrice  = excl;
            self.priceInclText = Math.round(excl * 1.1).toLocaleString() + '円';
        },

        /** 価格入力時に税込を更新 */
        updatePriceIncl: function () {
            var n = parseInt(this.appliedPrice, 10);
            this.priceInclText = (isNaN(n) || n < 0) ? '—' : Math.round(n * 1.1).toLocaleString() + '円';
        }
    };
}
</script>

@endsection
