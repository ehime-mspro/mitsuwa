@extends('layouts.app')

@section('title', 'プランマスタ')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.dashboard') }}" class="hover:text-emerald-600 transition-colors">ZEAL</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">プランマスタ</span>
@endsection

@section('content')

@php
    // 税込換算係数（settings.tax_rate から算出。例: 10% → 1.10）
    $taxMul = 1 + ($taxRate / 100);
@endphp

<style>
    .zeal-badge {
        display: inline-flex; align-items: center;
        padding: 2px 10px; border-radius: 9999px;
        font-size: 11px; font-weight: 600; white-space: nowrap;
    }
    .badge-active   { background: #d1fae5; color: #065f46; }
    .badge-inactive { background: #f3f4f6; color: #6b7280; }
</style>

{{-- フラッシュメッセージ --}}
@if(session('success'))
    <div style="display: flex; align-items: center; gap: 8px; padding: 12px 16px; margin-bottom: 16px; background: #d1fae5; border: 1px solid #6ee7b7; border-radius: 8px; font-size: 14px; color: #065f46;">
        <svg style="width: 16px; height: 16px; flex-shrink: 0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        {{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div style="display: flex; align-items: center; gap: 8px; padding: 12px 16px; margin-bottom: 16px; background: #fee2e2; border: 1px solid #fca5a5; border-radius: 8px; font-size: 14px; color: #991b1b;">
        <svg style="width: 16px; height: 16px; flex-shrink: 0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        {{ session('error') }}
    </div>
@endif

{{-- ページヘッダー --}}
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
    <h1 class="text-lg font-bold text-gray-900">プランマスタ</h1>
    @if(auth()->user()->role->isManagerOrAbove())
        <a href="{{ route('zeal.plans.create') }}"
           class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            プランを追加
        </a>
    @endif
</div>

{{-- テーブル --}}
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <div style="overflow-x: auto;">
        <table class="w-full border-collapse" style="min-width: 880px;">
            <thead>
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap" style="width: 30%;">プラン名</th>
                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">通常価格（税抜）</th>
                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">キャンペーン価格（税抜）</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">キャンペーン期間</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">月限</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">種別</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">順</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">状態</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($plans as $plan)
                    <tr class="hover:bg-gray-50 transition-colors {{ $plan->active ? '' : 'opacity-60' }}">
                        {{-- プラン名 --}}
                        <td class="px-4 py-3 border-b border-gray-200">
                            <div class="text-sm font-semibold text-gray-900">{{ $plan->name }}</div>
                            <div style="font-size: 11px; color: #6b7280; margin-top: 2px;">
                                @if($plan->includes_personal)
                                    <span style="background: #ede9fe; color: #6d28d9; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; margin-right: 3px;">パーソナル</span>
                                @endif
                                @if($plan->includes_semi_personal)
                                    <span style="background: #e0f2fe; color: #0369a1; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; margin-right: 3px;">セミパーソナル</span>
                                @endif
                                @if($plan->is_pair_plan)
                                    <span style="background: #fdf4ff; color: #7e22ce; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; margin-right: 3px;">ペアプラン</span>
                                @endif
                                @if($plan->max_concurrent_reservations !== null)
                                    {{ $plan->max_concurrent_reservations }}枠
                                @endif
                            </div>
                        </td>
                        {{-- 通常価格 --}}
                        <td class="px-4 py-3 border-b border-gray-200 text-right whitespace-nowrap">
                            <div class="text-sm text-gray-900">{{ number_format($plan->regular_price_excl) }}円</div>
                            <div style="font-size: 11px; color: #6b7280;">税込 {{ number_format((int) round($plan->regular_price_excl * $taxMul)) }}円</div>
                        </td>
                        {{-- キャンペーン価格 --}}
                        <td class="px-4 py-3 border-b border-gray-200 text-right whitespace-nowrap">
                            @if($plan->campaign_price_excl !== null)
                                <div class="text-sm text-gray-900">{{ number_format($plan->campaign_price_excl) }}円</div>
                                <div style="font-size: 11px; color: #6b7280;">税込 {{ number_format((int) round($plan->campaign_price_excl * $taxMul)) }}円</div>
                            @else
                                <span class="text-sm text-gray-400">—</span>
                            @endif
                        </td>
                        {{-- キャンペーン期間 --}}
                        <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap text-sm text-gray-700">
                            @if($plan->campaign_starts_on || $plan->campaign_ends_on)
                                <div style="font-size: 12px;">
                                    {{ $plan->campaign_starts_on ? $plan->campaign_starts_on->format('Y/m/d') : '〜' }}
                                    〜
                                    {{ $plan->campaign_ends_on ? $plan->campaign_ends_on->format('Y/m/d') : '無期限' }}
                                </div>
                                @if($plan->isCampaignActive())
                                    <span style="background: #fef3c7; color: #92400e; font-size: 10px; font-weight: 600; padding: 1px 6px; border-radius: 4px;">適用中</span>
                                @endif
                            @else
                                <span class="text-sm text-gray-400">—</span>
                            @endif
                        </td>
                        {{-- 月間上限 --}}
                        <td class="px-4 py-3 border-b border-gray-200 text-center text-sm text-gray-700 whitespace-nowrap">
                            {{ $plan->monthly_session_limit !== null ? '月' . $plan->monthly_session_limit . '回' : '通い放題' }}
                        </td>
                        {{-- 種別タグ --}}
                        <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                            @if($plan->is_pair_plan)
                                <span class="zeal-badge" style="background: #fdf4ff; color: #7e22ce;">ペア</span>
                            @else
                                <span class="zeal-badge" style="background: #f3f4f6; color: #374151;">通常</span>
                            @endif
                        </td>
                        {{-- 表示順 --}}
                        <td class="px-4 py-3 border-b border-gray-200 text-center text-sm text-gray-700 whitespace-nowrap">
                            {{ $plan->display_order }}
                        </td>
                        {{-- 状態 --}}
                        <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                            <span class="zeal-badge {{ $plan->active ? 'badge-active' : 'badge-inactive' }}">
                                {{ $plan->active ? '有効' : '無効' }}
                            </span>
                        </td>
                        {{-- 操作 --}}
                        <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                            <div class="flex gap-1.5 justify-center">
                                @if(auth()->user()->role->isManagerOrAbove())
                                    <a href="{{ route('zeal.plans.edit', $plan) }}"
                                       class="text-xs font-semibold text-emerald-700 px-3 py-1 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100 hover:border-emerald-300 transition-colors">
                                        編集
                                    </a>
                                @endif
                                @if(auth()->user()->role->isExecutive())
                                    <form action="{{ route('zeal.plans.destroy', $plan) }}" method="POST"
                                          onsubmit="return confirm('「{{ addslashes($plan->name) }}」を削除しますか？\n契約履歴で使用中のプランは削除できません。')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="text-xs font-semibold text-red-600 px-3 py-1 border border-red-200 rounded bg-red-50 hover:bg-red-100 hover:border-red-300 transition-colors">
                                            削除
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-5 py-10 text-center text-sm text-gray-400">
                            プランが登録されていません。
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div style="margin-top: 12px; font-size: 12px; color: #6b7280;">
    ※ 契約履歴で使用中のプランは削除できません。利用中止の場合は「有効」を無効に変更してください。
</div>

@endsection
