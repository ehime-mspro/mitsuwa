@extends('layouts.app')

@section('title', '投資案件: ' . $investment->investment_number)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.investments.index') }}" class="hover:text-emerald-600 transition-colors">投資案件一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $investment->investment_number }}</span>
@endsection

@section('content')
<div x-data="{ showDeleteModal: false }">

    {{-- ヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">
            投資案件詳細
            <span class="text-sm font-normal text-gray-500 ml-1">{{ $investment->investment_number }}</span>
        </h1>
        <div style="display: flex; gap: 8px; align-items: center;">
            <a href="{{ route('tenant.investments.index') }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">投資案件一覧に戻る</a>
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('tenant.investments.edit', $investment) }}"
                   style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 6px; text-decoration: none; background: #fff;">編集</a>
                @if(auth()->user()->role->isExecutive())
                    <button @click="showDeleteModal = true"
                            style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 6px; background: #fff; cursor: pointer;">削除</button>
                @endif
            @endif
        </div>
    </div>


    {{-- 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">基本情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
            <div>
                <div class="text-xs text-gray-500 mb-0.5">投資番号</div>
                <div class="text-sm font-semibold text-gray-900">{{ $investment->investment_number }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">投資パターン</div>
                <div class="text-sm font-semibold text-gray-900">{{ $investment->pattern->label() }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">物件</div>
                <div class="text-sm font-semibold"><a href="{{ route('tenant.properties.show', $investment->property) }}" class="text-emerald-600 hover:underline">{{ $investment->property->name }}</a></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">区画</div>
                <div class="text-sm font-semibold"><a href="{{ route('tenant.units.show', $investment->unit) }}" class="text-emerald-600 hover:underline">{{ $investment->unit->display_name }}</a></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">ステータス</div>
                <div class="mt-0.5"><span class="badge {{ $investment->status->badgeClass() }}">{{ $investment->status->label() }}</span></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">投資総額</div>
                <div class="text-xl font-bold text-gray-900">{{ number_format($investment->total_amount) }}円</div>
            </div>
            {{-- 施工業者: 入力がある場合のみ表示 --}}
            @if($investment->contractor_name)
                <div>
                    <div class="text-xs text-gray-500 mb-0.5">施工業者</div>
                    <div class="text-sm font-semibold text-gray-900">{{ $investment->contractor_name }}</div>
                </div>
            @endif
            {{-- 工事期間: 開始日または完了日のいずれかがある場合のみ表示 --}}
            @if($investment->start_date || $investment->end_date)
                <div>
                    <div class="text-xs text-gray-500 mb-0.5">工事期間</div>
                    <div class="text-sm font-semibold text-gray-900">
                        @if($investment->start_date && $investment->end_date)
                            {{ $investment->start_date->format('Y/m/d') }} 〜 {{ $investment->end_date->format('Y/m/d') }}
                        @elseif($investment->start_date)
                            {{ $investment->start_date->format('Y/m/d') }} 〜
                        @else
                            〜 {{ $investment->end_date->format('Y/m/d') }}
                        @endif
                    </div>
                </div>
            @endif
            <div class="sm:col-span-2">
                <div class="text-xs text-gray-500 mb-0.5">工事概要</div>
                <div class="text-sm text-gray-900 leading-relaxed whitespace-pre-wrap">{{ $investment->description }}</div>
            </div>
        </div>
    </div>

    {{-- 投資明細 --}}
    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden mb-3">
        <div class="text-sm font-bold text-gray-800 px-5 py-3 border-b border-gray-200">投資明細</div>
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                <table class="w-full border-collapse text-sm" style="min-width:600px">
                    <thead>
                        <tr>
                            <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 bg-gray-50">費用項目</th>
                            <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 bg-gray-50">業者名</th>
                            <th class="px-4 py-2.5 text-right font-bold text-gray-700 border-b border-gray-200 bg-gray-50">金額</th>
                            <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 bg-gray-50">実施日</th>
                            <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 bg-gray-50">備考</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $costLabels = \App\Http\Controllers\Tenant\InvestmentController::COST_ITEM_LABELS;
                        @endphp
                        @foreach($investment->details as $detail)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap">{{ $costLabels[$detail->cost_item] ?? $detail->cost_item }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap">{{ $detail->contractor_name ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-200 text-right font-semibold whitespace-nowrap">{{ number_format($detail->amount) }}円</td>
                                <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap">{{ $detail->executed_at?->format('Y/m/d') ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap">{{ $detail->notes ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-emerald-50">
                            <td class="px-4 py-2.5 font-bold border-t-2 border-emerald-200">合計</td>
                            <td class="px-4 py-2.5 border-t-2 border-emerald-200"></td>
                            <td class="px-4 py-2.5 border-t-2 border-emerald-200 text-right font-bold">{{ number_format($investment->details->sum('amount')) }}円</td>
                            <td class="px-4 py-2.5 border-t-2 border-emerald-200"></td>
                            <td class="px-4 py-2.5 border-t-2 border-emerald-200"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>
    </div>

    {{-- 回収情報（完成日が設定されていれば表示） --}}
    @if($investment->end_date)
        <div class="bg-rose-50 border border-rose-200 rounded-lg p-5 mb-3">
            <div class="flex items-center justify-between pb-2 mb-3.5 border-b border-rose-200">
                <span class="text-sm font-bold text-rose-800">投資回収情報</span>
                <span class="badge {{ $investment->recoveryBadgeClass($recovery['recovery_rate']) }}">{{ $investment->recoveryLabel($recovery['recovery_rate']) }}</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                <div>
                    <div class="text-xs text-gray-500 mb-0.5">現在の月額家賃</div>
                    <div class="text-xl font-bold text-emerald-600">
                        {{ $recovery['current_rent'] ? number_format($recovery['current_rent']) . '円' : '—' }}
                    </div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 mb-0.5">回収開始日</div>
                    <div class="text-sm font-semibold text-gray-900">{{ $recovery['recovery_started_at']?->format('Y/m') ?? '—' }}</div>
                </div>
            </div>

            {{-- プログレスバー --}}
            <div class="mt-4">
                <div class="flex justify-between mb-1">
                    <span class="text-xs text-gray-500">累計回収額</span>
                    <span class="text-sm font-bold text-gray-900">{{ number_format($recovery['total_recovered']) }}円 / {{ number_format($investment->total_amount) }}円</span>
                </div>
                <div class="h-4 bg-gray-200 rounded-full overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-500"
                         style="width:{{ min($recovery['recovery_rate'], 100) }}%; background: linear-gradient(90deg, #e11d48, #fb7185);"></div>
                </div>
                <div class="flex justify-between mt-1">
                    <span class="text-xs text-gray-500">回収率</span>
                    <span class="text-base font-bold text-rose-600">{{ number_format($recovery['recovery_rate'], 1) }}%</span>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 mt-3">
                <div>
                    <div class="text-xs text-gray-500 mb-0.5">残り回収額</div>
                    <div class="text-sm font-bold text-gray-900">{{ number_format(max(0, $investment->total_amount - $recovery['total_recovered'])) }}円</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 mb-0.5">回収予定残月数</div>
                    <div class="text-sm font-bold text-gray-900">{{ $recovery['estimated_months'] !== null ? $recovery['estimated_months'] . 'ヶ月' : '—' }}</div>
                </div>
            </div>
        </div>
    @endif

    {{-- 備考 --}}
    @if($investment->notes)
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
            <div class="text-sm text-gray-900 leading-relaxed whitespace-pre-wrap">{{ $investment->notes }}</div>
        </div>
    @endif

    {{-- 添付ファイル --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">添付ファイル</div>
        @include('components.attachment-section', [
            'attachableType'     => 'investments',
            'attachableId'       => $investment->id,
            'attachments'        => $investment->attachments,
            'deletedAttachments' => $deletedAttachments,
        ])
    </div>

    {{-- 削除確認モーダル --}}
    @if(auth()->user()->role->isExecutive())
        <x-delete-confirm-modal
            title="投資案件を削除しますか？"
            :action="route('tenant.investments.destroy', $investment)"
            :target="$investment->investment_number . ' — ' . $investment->property->name . ' / ' . $investment->unit->display_name"
        />
    @endif

</div>
@endsection
