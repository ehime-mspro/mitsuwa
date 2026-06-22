@extends('layouts.app')

@section('title', '区画: ' . $unit->display_name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">物件一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.show', $property) }}" class="hover:text-emerald-600 transition-colors">{{ $property->name }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">区画: {{ $unit->display_name }}</span>
@endsection

@section('content')
<div x-data="{ activeTab: 'contract', showDeleteModal: false }">

    {{-- ページヘッダー --}}
    <div class="flex flex-wrap items-center gap-3 mb-4">
        <h1 class="text-lg font-bold text-gray-900">区画: {{ $unit->display_name }}</h1>
        <div style="display: flex; gap: 8px; align-items: center; margin-left: auto;">
            <a href="{{ route('tenant.properties.show', $property) }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">{{ $property->name }}に戻る</a>
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('tenant.units.edit', $unit) }}"
                   style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 6px; text-decoration: none; background: #fff;">編集</a>
                @if(auth()->user()->role->isExecutive())
                    <button @click="showDeleteModal = true"
                            style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 6px; background: #fff; cursor: pointer;">削除</button>
                @endif
            @endif
        </div>
    </div>

    {{-- 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-4 lg:px-5 lg:py-4 mb-4">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3 border-b border-gray-200">基本情報</div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <div class="text-sm text-gray-600 mb-0.5">物件</div>
                <div class="text-sm font-medium text-gray-900">
                    <a href="{{ route('tenant.properties.show', $property) }}" class="hover:text-emerald-600 hover:underline transition-colors">{{ $property->name }}</a>
                </div>
            </div>
            <div>
                <div class="text-sm text-gray-600 mb-0.5">区画</div>
                <div class="text-sm font-medium text-gray-900">
                    {{ $unit->display_name }}
                    @if($unit->floor)
                        @if($unit->floor < 0)
                            <span class="text-gray-500">（地下{{ abs($unit->floor) }}階 {{ $unit->room_number }}号室）</span>
                        @else
                            <span class="text-gray-500">（{{ $unit->floor }}階 {{ $unit->room_number }}号室）</span>
                        @endif
                    @else
                        <span class="text-gray-500">（{{ $unit->room_number }}号室）</span>
                    @endif
                </div>
            </div>
            <div>
                <div class="text-sm text-gray-600 mb-0.5">面積</div>
                <div class="text-sm font-medium text-gray-900">
                    @if($unit->area_tsubo)
                        {{ number_format((float) $unit->area_tsubo, 2) }}坪
                    @else
                        —
                    @endif
                </div>
            </div>
            <div>
                <div class="text-sm text-gray-600 mb-0.5">用途</div>
                <div class="text-sm font-medium text-gray-900">
                    @if($unit->usageType)
                        {{ $unit->usageType->name }}
                    @else
                        —
                    @endif
                </div>
            </div>
        </div>

        {{-- ステータス + 変更ボタン --}}
        <div class="mt-3 pt-3 border-t border-gray-100 flex flex-wrap items-center gap-3">
            <div class="text-sm text-gray-600">ステータス:</div>
            @php
                $statusBadgeClass = match($unit->status) {
                    \App\Enums\UnitStatus::Occupied => 'bg-blue-100 text-blue-800',
                    \App\Enums\UnitStatus::Negotiating => 'bg-yellow-100 text-yellow-800',
                    default => 'bg-gray-200 text-gray-700',
                };
            @endphp
            <span class="inline-block px-2.5 py-1 rounded text-xs font-semibold {{ $statusBadgeClass }}">
                {{ $unit->status->label() }}
            </span>

            @if(auth()->user()->role->isManagerOrAbove())
                @if($unit->status === \App\Enums\UnitStatus::Vacant)
                    <form method="POST" action="{{ route('tenant.units.updateStatus', $unit) }}" class="inline">
                        @csrf
                        @method('PATCH')
                        <button type="submit"
                                class="px-3 py-1.5 bg-yellow-50 border border-yellow-300 rounded-md text-xs font-semibold text-yellow-800 hover:bg-yellow-100 transition-colors cursor-pointer">
                            商談中にする
                        </button>
                    </form>
                @elseif($unit->status === \App\Enums\UnitStatus::Negotiating)
                    <form method="POST" action="{{ route('tenant.units.updateStatus', $unit) }}" class="inline">
                        @csrf
                        @method('PATCH')
                        <button type="submit"
                                class="px-3 py-1.5 bg-gray-50 border border-gray-300 rounded-md text-xs font-semibold text-gray-700 hover:bg-gray-100 transition-colors cursor-pointer">
                            空室に戻す
                        </button>
                    </form>
                @endif
            @endif
        </div>
    </div>

    {{-- 募集条件 --}}
    @php
        $recruitTotal = ($unit->rent ?? 0) + ($unit->common_fee ?? 0) + ($unit->garbage_fee ?? 0) + ($unit->pest_control_fee ?? 0);
        $recruitTotalTax = (int) round($recruitTotal * 1.1);
        $areaTsubo = $unit->area_tsubo;
        $hasTsubo = $areaTsubo !== null && (float) $areaTsubo > 0;
        $recruitRentPerTsubo = $hasTsubo ? (int) ceil(($unit->rent ?? 0) / (float) $areaTsubo) : null;
        $recruitCommonFeePerTsubo = $hasTsubo ? (int) ceil(($unit->common_fee ?? 0) / (float) $areaTsubo) : null;
        $recruitTotalPerTsubo = $hasTsubo ? (int) ceil($recruitTotal / (float) $areaTsubo) : null;
    @endphp
    <div class="bg-white border border-gray-200 rounded-lg px-5 py-4 mb-3">
        <div class="flex items-center justify-between pb-2 mb-3 border-b border-gray-200">
            <span class="text-sm font-bold text-gray-800">募集条件</span>
            @if(($unit->status === \App\Enums\UnitStatus::Vacant || $unit->status === \App\Enums\UnitStatus::Negotiating) && auth()->user()->role->isExecutive())
                <a href="{{ route('tenant.units.revise', $unit) }}"
                   style="display:inline-flex; align-items:center; gap:6px; padding:6px 14px; font-size:12px; font-weight:600; color:#b45309; border:1px solid #fde68a; border-radius:6px; text-decoration:none; background:#fff;">
                    <svg style="width:14px;height:14px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    賃料改定
                </a>
            @endif
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-2">
            <div>
                <div class="text-xs text-gray-500 mb-0.5">募集家賃</div>
                <div class="text-sm font-medium text-gray-800">{{ number_format($unit->rent) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span>
                    @if($hasTsubo)<span class="text-[11px] font-medium ml-1.5 max-lg:block max-lg:ml-0 max-lg:mt-px" style="color:#4b5563">({{ '@' . number_format($recruitRentPerTsubo) }})</span>@endif
                </div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">募集共益費</div>
                <div class="text-sm font-medium text-gray-800">{{ number_format($unit->common_fee) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span>
                    @if($hasTsubo)<span class="text-[11px] font-medium ml-1.5 max-lg:block max-lg:ml-0 max-lg:mt-px" style="color:#4b5563">({{ '@' . number_format($recruitCommonFeePerTsubo) }})</span>@endif
                </div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">ゴミ代</div>
                <div class="text-sm font-medium text-gray-800">{{ number_format($unit->garbage_fee) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">駆除代</div>
                <div class="text-sm font-medium text-gray-800">{{ number_format($unit->pest_control_fee) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">敷金</div>
                <div class="text-sm font-medium text-gray-800">{{ number_format($unit->deposit) }}円</div>
            </div>
        </div>
        <div class="mt-2.5 pt-2.5 border-t border-gray-200 flex items-center gap-2 flex-wrap">
            <span class="text-sm font-bold text-gray-800">月額合計:</span>
            <span class="text-base font-bold" style="color:#065F46">{{ number_format($recruitTotal) }}円</span>
            @if($hasTsubo)<span class="text-[11px] font-medium" style="color:#4b5563">({{ '@' . number_format($recruitTotalPerTsubo) }})</span>@endif
            <span class="text-sm font-semibold text-gray-700 max-lg:block max-lg:w-full max-lg:mt-0.5">（税込 {{ number_format($recruitTotalTax) }}円）</span>
        </div>
    </div>

    {{-- 現在の契約条件（入居中の場合のみ表示） --}}
    @if($unit->status === \App\Enums\UnitStatus::Occupied && $activeContract)
        @php
            $contractTotalTax = (int) round($contractMonthlyTotal * 1.1);
            $contractRentPerTsubo = $hasTsubo ? (int) ceil(($activeContract->rent ?? 0) / (float) $areaTsubo) : null;
            $contractCommonFeePerTsubo = $hasTsubo ? (int) ceil(($activeContract->common_fee ?? 0) / (float) $areaTsubo) : null;
            $contractTotalPerTsubo = $hasTsubo ? (int) ceil($contractMonthlyTotal / (float) $areaTsubo) : null;
        @endphp
        <div class="bg-white border border-blue-200 rounded-lg px-5 py-4 mb-3">
            <div class="flex items-center justify-between pb-2 mb-3 border-b border-blue-200">
                <span class="text-sm font-bold" style="color:#1e3a5f">現在の契約条件</span>
                @if(auth()->user()->role->isExecutive())
                    <a href="{{ route('tenant.contracts.revise', ['contract' => $activeContract, 'return_to' => 'unit']) }}"
                       style="display:inline-flex; align-items:center; gap:6px; padding:6px 14px; font-size:12px; font-weight:600; color:#b45309; border:1px solid #fde68a; border-radius:6px; text-decoration:none; background:#fff;">
                        <svg style="width:14px;height:14px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        賃料改定
                    </a>
                @endif
            </div>
            @if($activeContract->store_name)
                <div class="mb-2.5">
                    <div class="text-xs text-gray-500 mb-0.5">店舗名</div>
                    <div class="text-sm font-semibold text-gray-800">{{ $activeContract->store_name }}</div>
                </div>
            @endif
            <div class="grid grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-2">
                <div>
                    <div class="text-xs text-gray-500 mb-0.5">契約者</div>
                    <div class="text-sm font-medium text-gray-800">{{ $activeContract->customer->name ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 mb-0.5">契約家賃</div>
                    <div class="text-sm font-medium text-gray-800">{{ number_format($activeContract->rent) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span>
                        @if($hasTsubo)<span class="text-[11px] font-medium ml-1.5 max-lg:block max-lg:ml-0 max-lg:mt-px" style="color:#4b5563">({{ '@' . number_format($contractRentPerTsubo) }})</span>@endif
                    </div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 mb-0.5">契約共益費</div>
                    <div class="text-sm font-medium text-gray-800">{{ number_format($activeContract->common_fee) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span>
                        @if($hasTsubo)<span class="text-[11px] font-medium ml-1.5 max-lg:block max-lg:ml-0 max-lg:mt-px" style="color:#4b5563">({{ '@' . number_format($contractCommonFeePerTsubo) }})</span>@endif
                    </div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 mb-0.5">ゴミ代</div>
                    <div class="text-sm font-medium text-gray-800">{{ number_format($activeContract->garbage_fee) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span></div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 mb-0.5">駆除代</div>
                    <div class="text-sm font-medium text-gray-800">{{ number_format($activeContract->pest_control_fee) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span></div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 mb-0.5">敷金</div>
                    <div class="text-sm font-medium text-gray-800">{{ number_format($activeContract->deposit) }}円</div>
                </div>
            </div>
            <div class="mt-2.5 pt-2.5 border-t border-blue-200 flex items-center gap-2 flex-wrap">
                <span class="text-sm font-bold text-gray-800">月額合計:</span>
                <span class="text-base font-bold" style="color:#065F46">{{ number_format($contractMonthlyTotal) }}円</span>
                @if($hasTsubo)<span class="text-[11px] font-medium" style="color:#4b5563">({{ '@' . number_format($contractTotalPerTsubo) }})</span>@endif
                <span class="text-sm font-semibold text-gray-700 max-lg:block max-lg:w-full max-lg:mt-0.5">（税込 {{ number_format($contractTotalTax) }}円）</span>
            </div>
        </div>
    @endif

    {{-- 投資・回収 --}}
    <div class="bg-white border border-gray-200 rounded-lg px-5 py-4 mb-3">
        <div class="flex items-center justify-between pb-2 mb-3 border-b border-gray-200">
            <span class="text-sm font-bold text-gray-800">投資・回収</span>
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('tenant.investments.create', ['unit_id' => $unit->id]) }}"
                   style="display:inline-block; padding:6px 14px; font-size:12px; font-weight:600; color:#059669; border:1px solid #059669; border-radius:6px; text-decoration:none; background:#fff;">この区画に投資を登録</a>
            @endif
        </div>
        @if($unitInvestments->isEmpty())
            <p class="text-sm text-gray-400 text-center py-4">この区画の投資案件はありません。</p>
        @else
            <div class="space-y-2">
                @foreach($unitInvestments as $inv)
                    <a href="{{ route('tenant.investments.show', $inv['id']) }}"
                       class="flex items-center justify-between gap-3 px-3 py-2.5 border border-gray-200 rounded-md hover:bg-gray-50 transition-colors">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-gray-900">{{ $inv['investment_number'] }}<span class="text-xs text-gray-500 font-normal ml-1.5">{{ $inv['pattern_label'] }}</span></div>
                            <div class="text-xs text-gray-500 mt-0.5">投資 {{ number_format($inv['total_amount']) }}円 / 回収 {{ number_format($inv['total_recovered']) }}円（{{ number_format($inv['rate'], 1) }}%）</div>
                        </div>
                        <span class="badge {{ $inv['badge_class'] }} flex-shrink-0">{{ $inv['label'] }}</span>
                    </a>
                @endforeach
            </div>
            <p class="text-xs text-gray-400 mt-2">回収は完成日(工事完了日)以降の家賃のみで自動計上されます。</p>
        @endif
    </div>

    {{-- タブセクション --}}
    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <div class="flex border-b border-gray-200 overflow-x-auto">
            @php
                $tabs = [
                    'contract' => '現在の契約',
                    'transactions' => '収支履歴',
                    'repairs' => '修繕履歴',
                    'revisions' => '賃料改定履歴',
                ];
            @endphp
            @foreach($tabs as $key => $label)
                <button @click="activeTab = '{{ $key }}'"
                        :class="activeTab === '{{ $key }}'
                            ? 'text-emerald-600 border-b-2 border-emerald-600 font-bold'
                            : 'text-gray-700 border-b-2 border-transparent hover:text-gray-900 hover:bg-gray-50'"
                        class="px-4 py-2.5 text-sm font-medium whitespace-nowrap transition-colors cursor-pointer">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="p-4 text-sm text-gray-700 min-h-[60px]">

            {{-- 現在の契約タブ --}}
            <div x-show="activeTab === 'contract'" x-cloak>
                @if($activeContract)
                    <div class="space-y-2">
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                            <div>
                                <div class="text-sm text-gray-600 mb-0.5">契約番号</div>
                                <div class="text-sm font-medium text-gray-900">{{ $activeContract->contract_number }}</div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-600 mb-0.5">テナント</div>
                                <div class="text-sm font-medium text-gray-900">{{ $activeContract->customer->name ?? '—' }}</div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-600 mb-0.5">契約日</div>
                                <div class="text-sm font-medium text-gray-900">{{ $activeContract->contract_date->format('Y/m/d') }}</div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-600 mb-0.5">家賃発生日</div>
                                <div class="text-sm font-medium text-gray-900">{{ $activeContract->rent_start_date?->format('Y/m/d') ?? '—' }}</div>
                            </div>
                        </div>
                        <div class="mt-3 pt-3 border-t border-gray-100">
                            <a href="{{ route('tenant.contracts.show', $activeContract) }}" class="text-sm text-emerald-600 hover:text-emerald-700 font-semibold">契約詳細を見る →</a>
                        </div>
                    </div>
                @else
                    <p class="text-gray-400 text-center py-6">現在の契約はありません。</p>
                @endif
            </div>

            {{-- 収支履歴タブ（賃料収入履歴） --}}
            <div x-show="activeTab === 'transactions'" x-cloak>
                @include('tenant.partials._rental-income', ['rentalIncome' => $rentalIncome])
            </div>

            {{-- 修繕履歴タブ --}}
            <div x-show="activeTab === 'repairs'" x-cloak>
                @if(isset($unitRepairs) && $unitRepairs->isNotEmpty())
                    <div class="scroll-hint at-start">
                        <div class="scroll-hint-inner">
                            <table class="w-full border-collapse text-sm" style="min-width:500px">
                                <thead>
                                    <tr>
                                        <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">カテゴリ</th>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">内容</th>
                                        <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">ステータス</th>
                                        <th class="px-4 py-2.5 text-right font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">費用</th>
                                        <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">実施日</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($unitRepairs as $repair)
                                        <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('tenant.repairs.show', $repair) }}'">
                                            <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap text-gray-900">{{ $repair->category_label }}</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap">
                                                <a href="{{ route('tenant.repairs.show', $repair) }}" class="text-emerald-600 font-semibold hover:underline">{{ \Illuminate\Support\Str::limit($repair->description, 30) }}</a>
                                            </td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap">
                                                <span class="badge {{ $repair->status->badgeClass() }}">{{ $repair->status->label() }}</span>
                                            </td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 text-right font-semibold whitespace-nowrap text-gray-900">{{ $repair->cost !== null ? number_format($repair->cost) . '円' : '—' }}</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap text-gray-900">{{ $repair->started_at?->format('Y/m/d') ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="scroll-hint-text">← スクロールできます →</div>
                    </div>
                    <div class="px-4 py-2 text-right">
                        <a href="{{ route('tenant.repairs.index', ['property_id' => $property->id]) }}" class="text-sm text-emerald-600 font-semibold hover:underline">すべての修繕を見る →</a>
                    </div>
                @else
                    <p class="text-gray-400 text-center py-6">修繕履歴がありません。</p>
                @endif
            </div>

            {{-- 賃料改定履歴タブ（募集＋契約 統合） --}}
            <div x-show="activeTab === 'revisions'" x-cloak>
                @if($rentHistory->isNotEmpty())
                    <div class="scroll-hint at-start">
                        <div class="scroll-hint-inner">
                            <table class="w-full border-collapse text-[13px]" style="min-width:820px">
                                <thead>
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">区分</th>
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
                                    @foreach($rentHistory as $row)
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap">
                                                @if($row['kind'] === 'asking')
                                                    <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;background:#fef3c7;color:#92400e;">募集</span>
                                                @else
                                                    <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;background:#dbeafe;color:#1e40af;">契約</span>
                                                    <span class="text-[11px] text-gray-500 ml-1">{{ $row['context_label'] }}</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ $row['revision_date']->format('Y/m/d') }}</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ number_format($row['old_rent']) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap font-bold" style="color:#047857">{{ number_format($row['new_rent']) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ number_format($row['old_common_fee'] ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap font-bold" style="color:#047857">{{ number_format($row['new_common_fee'] ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ number_format($row['old_garbage_fee'] ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap font-bold" style="color:#047857">{{ number_format($row['new_garbage_fee'] ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ number_format($row['old_pest_control_fee'] ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap font-bold" style="color:#047857">{{ number_format($row['new_pest_control_fee'] ?? 0) }}円</td>
                                            <td class="px-3 py-2 border-b border-gray-100 whitespace-nowrap text-gray-900">{{ $row['revised_by_name'] }}</td>
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

        </div>
    </div>

    {{-- 削除確認モーダル --}}
    @if(auth()->user()->role->isExecutive())
        <x-delete-confirm-modal
            title="区画を削除しますか？"
            :action="route('tenant.units.destroy', $unit)"
            :target="$property->name . ' / ' . $unit->display_name"
        />
    @endif

</div>
@endsection
