@extends('layouts.app')

@section('title', '契約: ' . $contract->contract_number)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.contracts.index') }}" class="hover:text-emerald-600 transition-colors">契約一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $contract->contract_number }}</span>
@endsection

@section('content')
<div x-data="{ activeTab: 'revisions' }">

    {{-- ページヘッダー --}}
    <div class="flex flex-wrap items-center gap-3 mb-4">
        <h1 class="text-lg font-bold text-gray-900">契約: {{ $contract->contract_number }}</h1>
        <span class="badge {{ $contract->status->badgeClass() }}">{{ $contract->status->label() }}</span>
        <div style="display: flex; gap: 8px; align-items: center; margin-left: auto;">
            <a href="{{ route('tenant.contracts.index') }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">契約一覧に戻る</a>
            @if(auth()->user()->role->isManagerOrAbove() && $contract->isActive())
                <a href="{{ route('tenant.contracts.edit', $contract) }}"
                   style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 6px; text-decoration: none; background: #fff;">編集</a>
            @endif
        </div>
    </div>

    {{-- 家賃発生日未設定の警告 --}}
    @if(! $contract->rent_start_date)
        <div class="flex items-start gap-2 mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3.5">
            <svg class="w-5 h-5 text-amber-600 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <div class="text-sm text-amber-800"><strong>家賃発生日が未設定です。</strong>契約内容を確認し、家賃発生日が確定したら編集画面で入力してください。</div>
        </div>
    @endif

    {{-- 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-4 lg:px-5 lg:py-4 mb-4">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3 border-b border-gray-200">基本情報</div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <div class="text-xs text-gray-500 mb-0.5">契約番号</div>
                <div class="text-sm font-medium text-gray-900">{{ $contract->contract_number }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">物件</div>
                <div class="text-sm font-semibold text-gray-900">{{ $contract->property->name }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">区画</div>
                <div class="text-sm font-semibold text-gray-900">
                    @php
                        $dn = $contract->unit->display_name;
                        $unitLabel = ($contract->unit->floor !== null && !preg_match('/^\d/', $dn)) ? $contract->unit->floor . $dn : $dn;
                    @endphp
                    {{ $unitLabel }}
                    <span class="font-normal text-gray-600">（{{ number_format($contract->unit->area_tsubo, 2) }}坪）</span>
                </div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">テナント</div>
                <div class="text-sm font-medium text-gray-900">{{ $contract->customer->name ?? '未登録' }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">店舗名</div>
                <div class="text-sm font-medium text-gray-900">{{ $contract->store_name ?? '—' }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">契約日</div>
                <div class="text-sm font-medium text-gray-900">{{ $contract->contract_date->format('Y/m/d') }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">家賃発生日</div>
                <div class="text-sm font-medium text-gray-900">{{ $contract->rent_start_date?->format('Y/m/d') ?? '—' }}</div>
            </div>
            @if($contract->rent_start_date && $contract->initial_month_type && $contract->initial_month_type->value !== 'full')
            @php
                $imtValue = $contract->initial_month_type->value;
                $imtLabel = $contract->initial_month_type->label();
                $imtDaysInfo = '';
                if ($imtValue === 'prorated') {
                    $rsd = $contract->rent_start_date;
                    $totalDays = $rsd->daysInMonth;
                    $usedDays = $totalDays - $rsd->day + 1;
                    $imtDaysInfo = $usedDays . '/' . $totalDays . '日';
                }
            @endphp
            <div>
                <div class="text-xs text-gray-500 mb-0.5">初月家賃（{{ $contract->rent_start_date->format('Y') }}年{{ (int)$contract->rent_start_date->format('m') }}月）</div>
                <div class="text-sm font-bold" style="color:#065F46;">
                    {{ number_format($contract->initial_month_amount ?? 0) }}円
                    <span class="text-xs font-medium text-gray-500 ml-1">{{ $imtLabel }}@if($imtDaysInfo) {{ $imtDaysInfo }}@endif</span>
                </div>
            </div>
            @endif
        </div>
    </div>

    {{-- 賃料情報 --}}
    @php
        $areaTsubo = $contract->unit->area_tsubo;
        $hasTsubo = $areaTsubo !== null && (float) $areaTsubo > 0;
        $rentPerTsubo = $hasTsubo ? (int) ceil($contract->rent / (float) $areaTsubo) : null;
        $commonFeePerTsubo = $hasTsubo ? (int) ceil(($contract->common_fee ?? 0) / (float) $areaTsubo) : null;
        $monthlyTotal = $contract->monthly_total;
        $monthlyTotalPerTsubo = $hasTsubo ? (int) ceil($monthlyTotal / (float) $areaTsubo) : null;
        $monthlyTotalTax = (int) round($monthlyTotal * 1.1);
    @endphp
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-4 lg:px-5 lg:py-4 mb-4">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3 border-b border-gray-200">賃料情報</div>
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-2">
            <div>
                <div class="text-xs text-gray-500 mb-0.5">家賃</div>
                <div class="text-[16px] font-bold text-gray-900">{{ number_format($contract->rent) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span>
                    @if($hasTsubo)<span class="text-[11px] font-medium ml-1.5 max-lg:block max-lg:ml-0 max-lg:mt-px" style="color:#4b5563">({{ '@' . number_format($rentPerTsubo) }})</span>@endif
                </div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">共益費</div>
                <div class="text-[16px] font-bold text-gray-900">{{ number_format($contract->common_fee) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span>
                    @if($hasTsubo)<span class="text-[11px] font-medium ml-1.5 max-lg:block max-lg:ml-0 max-lg:mt-px" style="color:#4b5563">({{ '@' . number_format($commonFeePerTsubo) }})</span>@endif
                </div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">ゴミ代</div>
                <div class="text-[16px] font-bold text-gray-900">{{ number_format($contract->garbage_fee) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">駆除代</div>
                <div class="text-[16px] font-bold text-gray-900">{{ number_format($contract->pest_control_fee) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">敷金</div>
                <div class="text-[16px] font-bold text-gray-900">{{ number_format($contract->deposit) }}円</div>
            </div>
        </div>
        {{-- 月額合計 --}}
        <div class="flex items-baseline gap-2 flex-wrap mt-3 pt-3 border-t border-gray-200">
            <span class="text-[15px] font-bold text-gray-900">月額合計:</span>
            <span class="text-[20px] font-bold" style="color:#065F46">{{ number_format($monthlyTotal) }}円</span>
            @if($hasTsubo)<span class="text-[11px] font-medium" style="color:#4b5563">({{ '@' . number_format($monthlyTotalPerTsubo) }})</span>@endif
            <span class="text-sm font-semibold text-gray-600">（税込 {{ number_format($monthlyTotalTax) }}円）</span>
        </div>
    </div>

    {{-- 保証人情報（いずれかの保証人に値がある場合のみ） --}}
    @if($contract->hasGuarantor())
        <div class="bg-white border border-gray-200 rounded-lg px-4 py-4 lg:px-5 lg:py-4 mb-4">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3 border-b border-gray-200">保証人情報</div>

            {{-- 保証人1 --}}
            @if($contract->hasGuarantor1())
                <div class="@if($contract->hasGuarantor2()) mb-4 @endif">
                    <div class="text-xs font-bold text-gray-600 bg-gray-50 rounded px-3 py-1.5 mb-2.5" style="border-left: 4px solid #10b981;">保証人 1</div>
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        <div>
                            <div class="text-xs text-gray-500 mb-0.5">氏名</div>
                            <div class="text-sm font-medium text-gray-900">{{ $contract->guarantor1_name ?? '—' }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 mb-0.5">連絡先</div>
                            <div class="text-sm font-medium text-gray-900">{{ $contract->guarantor1_contact ?? '—' }}</div>
                        </div>
                        <div class="col-span-2">
                            <div class="text-xs text-gray-500 mb-0.5">住所</div>
                            <div class="text-sm font-medium text-gray-900">{{ $contract->guarantor1_address ?? '—' }}</div>
                        </div>
                        <div class="col-span-2 lg:col-span-4">
                            <div class="text-xs text-gray-500 mb-0.5">勤務先</div>
                            <div class="text-sm font-medium text-gray-900">{{ $contract->guarantor1_workplace ?? '—' }}</div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- 区切り線（両方表示の場合のみ） --}}
            @if($contract->hasGuarantor1() && $contract->hasGuarantor2())
                <div class="border-t border-gray-100 mb-4"></div>
            @endif

            {{-- 保証人2 --}}
            @if($contract->hasGuarantor2())
                <div>
                    <div class="text-xs font-bold text-gray-600 bg-gray-50 rounded px-3 py-1.5 mb-2.5" style="border-left: 4px solid #60a5fa;">保証人 2</div>
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        <div>
                            <div class="text-xs text-gray-500 mb-0.5">氏名</div>
                            <div class="text-sm font-medium text-gray-900">{{ $contract->guarantor2_name ?? '—' }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 mb-0.5">連絡先</div>
                            <div class="text-sm font-medium text-gray-900">{{ $contract->guarantor2_contact ?? '—' }}</div>
                        </div>
                        <div class="col-span-2">
                            <div class="text-xs text-gray-500 mb-0.5">住所</div>
                            <div class="text-sm font-medium text-gray-900">{{ $contract->guarantor2_address ?? '—' }}</div>
                        </div>
                        <div class="col-span-2 lg:col-span-4">
                            <div class="text-xs text-gray-500 mb-0.5">勤務先</div>
                            <div class="text-sm font-medium text-gray-900">{{ $contract->guarantor2_workplace ?? '—' }}</div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- 備考 --}}
    @if($contract->notes)
        <div class="bg-white border border-gray-200 rounded-lg px-4 py-4 lg:px-5 lg:py-4 mb-4">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3 border-b border-gray-200">備考</div>
            <div class="text-sm text-gray-700 leading-relaxed whitespace-pre-wrap">{{ $contract->notes }}</div>
        </div>
    @endif

    {{-- 解約済みの場合の解約情報 --}}
    @if($contract->isTerminated())
        <div class="bg-gray-50 border border-gray-200 rounded-lg px-4 py-4 lg:px-5 lg:py-4 mb-4">
            <div class="text-sm font-bold text-gray-800 mb-3">解約情報</div>
            <div class="grid grid-cols-2 lg:grid-cols-3 gap-3 mb-3">
                <div>
                    <div class="text-xs text-gray-500 mb-0.5">契約終了日</div>
                    <div class="text-sm font-medium text-gray-900">{{ $contract->contract_end_date?->format('Y/m/d') ?? '—' }}</div>
                </div>
                @if($contract->final_month_type && $contract->final_month_type->value !== 'full')
                @php
                    $fmtValue = $contract->final_month_type->value;
                    $fmtLabel = $contract->final_month_type->label();
                    $fmtDaysInfo = '';
                    if ($fmtValue === 'prorated' && $contract->contract_end_date) {
                        $ced = $contract->contract_end_date;
                        $totalDays = $ced->daysInMonth;
                        $usedDays = $ced->day;
                        $fmtDaysInfo = $usedDays . '/' . $totalDays . '日';
                    }
                @endphp
                <div>
                    <div class="text-xs text-gray-500 mb-0.5">最終月家賃（{{ $contract->contract_end_date?->format('Y') }}年{{ (int)$contract->contract_end_date?->format('m') }}月）</div>
                    <div class="text-sm font-bold" style="color:#065F46;">
                        {{ number_format($contract->final_month_amount ?? 0) }}円
                        <span class="text-xs font-medium text-gray-500 ml-1">{{ $fmtLabel }}@if($fmtDaysInfo) {{ $fmtDaysInfo }}@endif</span>
                    </div>
                </div>
                @endif
                <div class="col-span-2">
                    <div class="text-xs text-gray-500 mb-0.5">退去理由</div>
                    <div class="text-sm font-medium text-gray-900">{{ $contract->termination_reason ?? '—' }}</div>
                </div>
            </div>
            @if($settlementFile)
                <a href="{{ asset('storage/' . $settlementFile->file_path) }}" target="_blank"
                   class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-white border border-gray-300 rounded-md text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    解約精算書を開く
                </a>
            @endif
        </div>
    @endif

    {{-- アクションボタン群（契約中の場合のみ） --}}
    @if($contract->isActive())
        <div class="flex flex-wrap gap-2 mb-4">
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('tenant.contracts.terminate', $contract) }}"
                   class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-red-200 rounded-md text-sm font-semibold text-red-600 hover:bg-red-50 transition-colors">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    解約処理
                </a>
            @endif
            @if(auth()->user()->role->isExecutive())
                <a href="{{ route('tenant.contracts.revise', $contract) }}"
                   class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-amber-200 rounded-md text-sm font-semibold text-amber-700 hover:bg-amber-50 transition-colors">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    賃料改定
                </a>
            @endif
        </div>
    @endif

    {{-- タブセクション: 賃料改定履歴 / 添付ファイル --}}
    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <div class="flex border-b border-gray-200 overflow-x-auto">
            <button @click="activeTab = 'revisions'"
                    :class="activeTab === 'revisions'
                        ? 'text-emerald-600 border-b-2 border-emerald-600 font-bold'
                        : 'text-gray-700 border-b-2 border-transparent hover:text-gray-900 hover:bg-gray-50'"
                    class="px-4 py-2.5 text-sm font-medium whitespace-nowrap transition-colors cursor-pointer">
                賃料改定履歴
            </button>
            <button @click="activeTab = 'attachments'"
                    :class="activeTab === 'attachments'
                        ? 'text-emerald-600 border-b-2 border-emerald-600 font-bold'
                        : 'text-gray-700 border-b-2 border-transparent hover:text-gray-900 hover:bg-gray-50'"
                    class="px-4 py-2.5 text-sm font-medium whitespace-nowrap transition-colors cursor-pointer">
                添付ファイル
            </button>
        </div>

        <div class="p-4 text-sm text-gray-700 min-h-[60px]">
            {{-- 賃料改定履歴タブ --}}
            <div x-show="activeTab === 'revisions'" x-cloak>
                @if($contract->rentRevisions->isNotEmpty())
                    <div class="scroll-hint at-start">
                        <div class="scroll-hint-inner">
                            <table class="w-full border-collapse text-[13px]" style="min-width:700px">
                                <thead>
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">改定日</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">旧家賃</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">新家賃</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">旧共益費</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">新共益費</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">旧ゴミ代</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">新ゴミ代</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">旧駆除代</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">新駆除代</th>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">改定者</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($contract->rentRevisions as $revision)
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ $revision->revision_date->format('Y/m/d') }}</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ number_format($revision->old_rent) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap font-bold" style="color:#047857">{{ number_format($revision->new_rent) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ number_format($revision->old_common_fee ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap font-bold" style="color:#047857">{{ number_format($revision->new_common_fee ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ number_format($revision->old_garbage_fee ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap font-bold" style="color:#047857">{{ number_format($revision->new_garbage_fee ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ number_format($revision->old_pest_control_fee ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap font-bold" style="color:#047857">{{ number_format($revision->new_pest_control_fee ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ $revision->revisedByUser->name ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="scroll-hint-text">← スクロールできます →</div>
                    </div>
                @else
                    <p class="text-gray-400 text-center py-6">賃料改定の履歴はありません。</p>
                @endif
            </div>

            {{-- 添付ファイルタブ --}}
            <div x-show="activeTab === 'attachments'" x-cloak>
                @include('components.attachment-section', [
                    'attachableType'     => 'contracts',
                    'attachableId'       => $contract->id,
                    'attachments'        => $contract->attachments,
                    'deletedAttachments' => $deletedAttachments,
                ])
            </div>
        </div>
    </div>

</div>
@endsection
