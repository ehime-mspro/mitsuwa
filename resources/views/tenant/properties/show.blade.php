@extends('layouts.app')

@section('title', $property->name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">物件一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $property->name }}</span>
@endsection

@section('content')
<div x-data="{ activeTab: 'contracts', showDeleteModal: false }">

    {{-- ページヘッダー --}}
    <div class="flex flex-wrap items-center gap-3 mb-4">
        <h1 class="text-lg font-bold text-gray-900">{{ $property->name }}</h1>
        <span class="badge {{ $property->operation_status->badgeClass() }}">{{ $property->operation_status->label() }}</span>
        <div style="display: flex; gap: 8px; align-items: center; margin-left: auto;">
            <a href="{{ route('tenant.properties.index') }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">物件一覧に戻る</a>
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('tenant.properties.edit', $property) }}"
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
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <div class="text-sm text-gray-600 mb-0.5">物件コード</div>
                <div class="text-sm font-medium text-gray-900">{{ $property->code }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-600 mb-0.5">住所</div>
                <div class="text-sm font-medium text-gray-900">{{ $property->address }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-600 mb-0.5">構造</div>
                <div class="text-sm font-medium text-gray-900">{{ $property->structure ?? '—' }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-600 mb-0.5">築年月</div>
                <div class="text-sm font-medium text-gray-900">
                    @if($property->built_date)
                        {{ \Illuminate\Support\Str::replaceFirst('-', '年', $property->built_date) }}月
                    @else
                        —
                    @endif
                </div>
            </div>
            <div>
                <div class="text-sm text-gray-600 mb-0.5">総階数</div>
                <div class="text-sm font-medium text-gray-900">{{ $property->total_floors ? $property->total_floors . '階' : '平屋型' }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-600 mb-0.5">総区画数</div>
                <div class="text-sm font-medium text-gray-900">{{ $property->units->count() }}区画</div>
            </div>
            <div>
                <div class="text-sm text-gray-600 mb-0.5">総坪数</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($summary['total_tsubo'], 2) }}坪</div>
            </div>
            <div>
                <div class="text-sm text-gray-600 mb-0.5">所有者</div>
                <div class="text-sm font-medium text-gray-900">
                    @if($property->owner_type)
                        {{ $property->owner_type->label() }}
                        @if($property->owner_name)
                            （{{ $property->owner_name }}）
                        @endif
                    @else
                        —
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- サマリーカード（6枚） --}}
    <div class="grid grid-cols-3 lg:grid-cols-6 gap-2.5 mb-4">
        <div class="bg-white border border-gray-200 rounded-lg py-3.5 px-2 text-center flex flex-col items-center justify-center min-h-[80px]">
            <div class="text-sm text-gray-600 mb-1 whitespace-nowrap">入居率</div>
            <div class="text-xl max-lg:text-lg font-bold text-emerald-600 whitespace-nowrap leading-tight">
                {{ number_format($summary['occupancy_rate'], 1) }}<span class="text-sm text-gray-600 font-normal">%</span>
            </div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg py-3.5 px-2 text-center flex flex-col items-center justify-center min-h-[80px]">
            <div class="text-sm text-gray-600 mb-1 whitespace-nowrap">総坪数</div>
            <div class="text-xl max-lg:text-lg font-bold text-gray-900 whitespace-nowrap leading-tight">
                {{ number_format($summary['total_tsubo'], 2) }}<span class="text-sm text-gray-600 font-normal">坪</span>
            </div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg py-3.5 px-2 text-center flex flex-col items-center justify-center min-h-[80px]">
            <div class="text-sm text-gray-600 mb-1 whitespace-nowrap">契約坪数</div>
            <div class="text-xl max-lg:text-lg font-bold text-gray-900 whitespace-nowrap leading-tight">
                {{ number_format($summary['contracted_tsubo'], 2) }}<span class="text-sm text-gray-600 font-normal">坪</span>
            </div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg py-3.5 px-2 text-center flex flex-col items-center justify-center min-h-[80px]">
            <div class="text-sm text-gray-600 mb-1 whitespace-nowrap">賃料収入</div>
            <div class="text-xl max-lg:text-base font-bold text-gray-900 whitespace-nowrap leading-tight">
                {{ number_format($summary['rental_income']) }}円<span class="text-sm text-gray-600 font-normal">/月</span>
            </div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg py-3.5 px-2 text-center flex flex-col items-center justify-center min-h-[80px]">
            <div class="text-sm text-gray-600 mb-1 whitespace-nowrap">契約数</div>
            <div class="text-xl max-lg:text-lg font-bold text-gray-900 whitespace-nowrap leading-tight">
                {{ $summary['active_contract_count'] }}<span class="text-sm text-gray-600 font-normal">件</span>
            </div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg py-3.5 px-2 text-center flex flex-col items-center justify-center min-h-[80px]">
            <div class="text-sm text-gray-600 mb-1 whitespace-nowrap">問合せ</div>
            <div class="text-xl max-lg:text-lg font-bold text-gray-900 whitespace-nowrap leading-tight">
                {{ $summary['inquiry_count'] }}<span class="text-sm text-gray-600 font-normal">件</span>
            </div>
        </div>
    </div>

    {{-- ===== フロアマップ ===== --}}
    @php
        $maxCols = $floorMap['maxCols'] ?? 2;
        $colPercent = match(true) {
            $maxCols >= 5 => 20,
            $maxCols === 4 => 25,
            $maxCols === 3 => 33.333,
            default => 50,
        };
    @endphp
    {{-- モック v7 準拠: 全行固定高さで横の縦揃えを統一 --}}
    <style>
        /* 区画カード外枠 */
        .fm-unit { flex: 0 0 {{ $colPercent }}%; padding: 10px 10px; border-right: 1px solid #f3f4f6; cursor: pointer; transition: background 0.1s; }
        .fm-unit:last-child { border-right: none; }
        .fm-unit:hover { filter: brightness(0.97); }
        /* 行1: 区画名行（固定22px） */
        .fm-ut { display: flex; align-items: center; gap: 6px; height: 22px; flex-wrap: wrap; }
        .fm-un { font-size: 13px; font-weight: 700; color: #111827; }
        .fm-tsubo { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; }
        .fm-invest { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 9px; font-weight: 600; background: #ffe4e6; color: #be123c; border: 1px solid #fecdd3; }
        .fm-invest-wip { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
        /* 行2: 店舗名/ステータスラベル行（固定26px） */
        .fm-name { height: 26px; display: flex; align-items: center; margin-bottom: 2px; }
        .fm-store { font-size: 13px; font-weight: 600; color: #111827; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .fm-status { font-size: 12px; font-weight: 600; display: inline-block; padding: 2px 8px; border-radius: 4px; }
        .fm-status-vacant { background: #e5e7eb; color: #374151; }
        .fm-status-neg { background: #fef9c3; color: #854d0e; }
        /* 費用明細 */
        .fm-fees { font-size: 12px; }
        .fm-r { display: flex; justify-content: space-between; align-items: center; height: 22px; }
        .fm-l { color: #111827; white-space: nowrap; }
        .fm-v { color: #111827; font-weight: 600; white-space: nowrap; }
        .fm-tp { height: 16px; text-align: right; display: flex; align-items: center; justify-content: flex-end; }
        .fm-tp span { color: #4b5563; font-size: 10px; font-weight: 500; }
        .fm-total { display: flex; justify-content: space-between; align-items: center; height: 28px; margin-top: 3px; padding-top: 4px; border-top: 1px solid #6b7280; }
        .fm-total .fm-l { font-weight: 700; }
        .fm-total .fm-v { font-weight: 700; color: #065F46; font-size: 13px; }
        .fm-dep { display: flex; justify-content: space-between; align-items: center; height: 28px; margin-top: 2px; padding-top: 4px; border-top: 1px solid #6b7280; }
        .fm-dep .fm-v { font-weight: 600; }
        @@media (max-width: 1023px) {
            .fm-unit { flex: none !important; width: 100% !important; border-right: none !important; border-bottom: 2px solid #d1d5db; padding: 14px 16px; }
            .fm-unit.last-unit { border-bottom: none; }
        }
    </style>
    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden mb-4">
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
            <h2 class="text-sm font-bold text-gray-900">フロアマップ</h2>
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('tenant.units.create', $property) }}"
                   class="inline-flex items-center gap-1 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-xs font-semibold transition-colors">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    区画追加
                </a>
            @endif
        </div>

        <div class="p-4">
            @if($floorMap['type'] === 'building')
                {{-- ===== ビル型フロアマップ ===== --}}
                @foreach($floorMap['floors'] as $floor)
                    <div class="flex border-b-2 border-gray-300 last:border-b-0 max-lg:flex-col max-lg:border-b-0">
                        {{-- PC: 左端の階数ラベル --}}
                        <div class="hidden lg:flex w-10 min-w-10 items-center justify-center text-sm font-bold text-gray-700 bg-gray-50 border-r border-gray-200">
                            {{ $floor['label'] }}
                        </div>
                        {{-- モバイル: 濃いグリーンバーの階数ラベル --}}
                        <div class="lg:hidden py-2 px-3.5 text-base font-bold text-white tracking-wide" style="background-color:#0B5D45">
                            {{ $floor['label'] }}
                        </div>
                        {{-- 区画カード群 --}}
                        <div class="flex flex-1 flex-wrap max-lg:flex-col">
                            @foreach($floor['units'] as $unit)
                                @include('tenant.properties._unit_card', [
                                    'unit' => $unit,
                                    'isLastFloorLastUnit' => $loop->parent->last && $loop->last,
                                ])
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @else
                {{-- ===== 平屋型フロアマップ ===== --}}
                <div class="flex flex-wrap max-lg:flex-col">
                    @foreach($floorMap['units'] as $unit)
                        @include('tenant.properties._unit_card', [
                            'unit' => $unit,
                            'isLastFloorLastUnit' => $loop->last,
                        ])
                    @endforeach
                </div>
            @endif
        </div>

        {{-- 凡例 --}}
        <div class="flex flex-wrap gap-4 px-4 py-2.5 border-t border-gray-200 text-sm text-gray-600">
            <div class="flex items-center gap-1">
                <div class="w-2.5 h-2.5 rounded-sm bg-blue-100 border border-blue-300"></div>
                入居中
            </div>
            <div class="flex items-center gap-1">
                <div class="w-2.5 h-2.5 rounded-sm bg-gray-100 border border-gray-300"></div>
                空室
            </div>
            <div class="flex items-center gap-1">
                <div class="w-2.5 h-2.5 rounded-sm bg-yellow-50 border border-yellow-300"></div>
                商談中
            </div>
        </div>
    </div>

    {{-- ===== タブセクション ===== --}}
    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <div class="flex border-b border-gray-200 overflow-x-auto">
            @php
                $tabs = [
                    'contracts' => '契約',
                    'terminated' => '解約',
                    'inquiries' => '問合せ',
                    'investments' => '投資',
                    'repairs' => '修繕',
                    'transactions' => '収支',
                    'attachments' => '添付ファイル',
                    'change_logs' => '変更履歴',
                ];
            @endphp
            @foreach($tabs as $key => $label)
                <button @click="activeTab = '{{ $key }}'; $nextTick(() => window.dispatchEvent(new Event('resize')))"
                        :class="activeTab === '{{ $key }}'
                            ? 'text-emerald-600 border-b-2 border-emerald-600 font-bold'
                            : 'text-gray-700 border-b-2 border-transparent hover:text-gray-900 hover:bg-gray-50'"
                        class="px-4 py-2.5 text-sm font-medium whitespace-nowrap transition-colors cursor-pointer">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="p-4 text-sm text-gray-700 min-h-[60px]">

            {{-- 契約タブ --}}
            <div x-show="activeTab === 'contracts'" x-cloak>
                @if($activeContracts->isNotEmpty())
                    <div class="scroll-hint at-start">
                        <div class="scroll-hint-inner">
                            <table class="w-full border-collapse text-sm" style="min-width:600px">
                                <thead>
                                    <tr>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">契約番号</th>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">契約日</th>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">区画</th>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">契約者名</th>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">店舗名</th>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">賃料収入</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($activeContracts as $contract)
                                        <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('tenant.contracts.show', $contract) }}'">
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap">
                                                <a href="{{ route('tenant.contracts.show', $contract) }}" class="text-emerald-600 font-semibold hover:underline">{{ $contract->contract_number }}</a>
                                            </td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-900">{{ $contract->contract_date->format('Y/m/d') }}</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-900">
                                                @php
                                                    $dn = $contract->unit->display_name;
                                                    $unitLabel = ($contract->unit->floor !== null && !preg_match('/^\d/', $dn)) ? $contract->unit->floor . $dn : $dn;
                                                @endphp
                                                {{ $unitLabel }}
                                            </td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-900">{{ $contract->customer?->name ?? '—' }}</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-900">{{ $contract->store_name ?? '—' }}</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-900 font-semibold">{{ number_format($contract->monthly_total) }}円</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="scroll-hint-text">← スクロールできます →</div>
                    </div>
                @else
                    <p class="text-gray-400 text-center py-6">契約中のデータはありません。</p>
                @endif
            </div>

            {{-- 解約タブ --}}
            <div x-show="activeTab === 'terminated'" x-cloak>
                @if($terminatedContracts->isNotEmpty())
                    <div class="scroll-hint at-start">
                        <div class="scroll-hint-inner">
                            <table class="w-full border-collapse text-sm" style="min-width:700px">
                                <thead>
                                    <tr>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">契約番号</th>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">解約日</th>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">区画</th>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">契約者名</th>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">店舗名</th>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">賃料収入</th>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">退去理由</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($terminatedContracts as $contract)
                                        <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('tenant.contracts.show', $contract) }}'">
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap">
                                                <a href="{{ route('tenant.contracts.show', $contract) }}" class="text-emerald-600 font-semibold hover:underline">{{ $contract->contract_number }}</a>
                                            </td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-900">{{ $contract->contract_end_date?->format('Y/m/d') ?? '—' }}</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-900">
                                                @php
                                                    $dn = $contract->unit->display_name;
                                                    $unitLabel = ($contract->unit->floor !== null && !preg_match('/^\d/', $dn)) ? $contract->unit->floor . $dn : $dn;
                                                @endphp
                                                {{ $unitLabel }}
                                            </td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-900">{{ $contract->customer?->name ?? '—' }}</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-900">{{ $contract->store_name ?? '—' }}</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-900 font-semibold">{{ number_format($contract->monthly_total) }}円</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-700">{{ $contract->termination_reason ? \Illuminate\Support\Str::limit($contract->termination_reason, 30) : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="scroll-hint-text">← スクロールできます →</div>
                    </div>
                @else
                    <p class="text-gray-400 text-center py-6">解約済みのデータはありません。</p>
                @endif
            </div>

            <div x-show="activeTab === 'inquiries'" x-cloak
                 x-data="{ f: { follow: true, on_hold: false, converted: false, lost: false, unreachable: false } }">

                {{-- ステータスフィルター --}}
                <div class="flex flex-wrap gap-3 mb-3 px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-md">
                    @foreach(\App\Enums\InquiryStatus::cases() as $s)
                        <label class="flex items-center gap-1.5 text-xs font-semibold text-gray-700 cursor-pointer">
                            <input type="checkbox" x-model="f.{{ $s->value }}" style="accent-color:#059669; width:15px; height:15px;">
                            {{ $s->label() }}
                        </label>
                    @endforeach
                </div>

                {{-- テーブル --}}
                <div x-show="f.follow || f.on_hold || f.converted || f.lost || f.unreachable">
                    @if(isset($inquiries) && $inquiries->isNotEmpty())
                        <div class="scroll-hint at-start">
                            <div class="scroll-hint-inner">
                                <table class="w-full border-collapse text-sm" style="min-width:600px">
                                    <thead>
                                        <tr>
                                            <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">問合せ番号</th>
                                            <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">問合せ日</th>
                                            <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">区画</th>
                                            <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">問合せ者</th>
                                            <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">経路</th>
                                            <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">ステータス</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($inquiries as $inq)
                                            <tr x-show="f.{{ $inq->status->value }}" class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('tenant.inquiries.show', $inq) }}'">
                                                <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap">
                                                    <a href="{{ route('tenant.inquiries.show', $inq) }}" class="text-emerald-600 font-semibold hover:underline">{{ $inq->inquiry_number }}</a>
                                                </td>
                                                <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-900">{{ $inq->inquiry_date->format('Y/m/d') }}</td>
                                                <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap {{ $inq->units->isEmpty() ? 'text-gray-400 italic' : 'text-gray-900' }}">{{ $inq->unit_labels }}</td>
                                                <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-900 font-semibold">{{ $inq->contact_display }}</td>
                                                <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap text-gray-900 font-semibold">{{ $inq->source_label }}</td>
                                                <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap"><span class="badge {{ $inq->status->badgeClass() }}">{{ $inq->status->label() }}</span></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="scroll-hint-text">← スクロールできます →</div>
                        </div>
                    @else
                        <p class="text-gray-400 text-center py-6">問合せデータはありません。</p>
                    @endif
                </div>

                <div x-show="!f.follow && !f.on_hold && !f.converted && !f.lost && !f.unreachable"
                     class="text-gray-400 text-center py-6 text-sm">
                    該当する問合せはありません。
                </div>

                <div class="pt-3 text-right">
                    <a href="{{ route('tenant.inquiries.index', ['property_id' => $property->id]) }}"
                       class="text-sm text-emerald-600 font-semibold hover:underline">すべての問合せを見る →</a>
                </div>
            </div>
            <div x-show="activeTab === 'investments'" x-cloak>
                @if(isset($investments) && $investments->isNotEmpty())
                    <div class="scroll-hint at-start">
                        <div class="scroll-hint-inner">
                            <table class="w-full border-collapse text-sm" style="min-width:600px">
                                <thead>
                                    <tr>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">投資番号</th>
                                        <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">区画</th>
                                        <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">パターン</th>
                                        <th class="px-4 py-2.5 text-right font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">投資総額</th>
                                        <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">回収率</th>
                                        <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">ステータス</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($investments as $inv)
                                        <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('tenant.investments.show', $inv) }}'">
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap">
                                                <a href="{{ route('tenant.investments.show', $inv) }}" class="text-emerald-600 font-semibold hover:underline">{{ $inv->investment_number }}</a>
                                            </td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap text-gray-900">{{ $inv->unit->display_name }}</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap text-gray-900">{{ $inv->pattern->label() }}</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 text-right font-semibold whitespace-nowrap text-gray-900">{{ number_format($inv->total_amount) }}円</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap">
                                                @if((float) $inv->recovery_rate > 0 || in_array($inv->status->value, ['recovering', 'recovered']))
                                                    <span class="text-xs font-bold {{ (float) $inv->recovery_rate >= 100 ? 'text-emerald-600' : 'text-rose-600' }}">{{ number_format((float) $inv->recovery_rate, 1) }}%</span>
                                                @else
                                                    <span class="text-xs text-gray-400">—</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap">
                                                <span class="badge {{ $inv->status->badgeClass() }}">{{ $inv->status->label() }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="scroll-hint-text">← スクロールできます →</div>
                    </div>
                    <div class="px-4 py-2 text-right">
                        <a href="{{ route('tenant.investments.index', ['property_id' => $property->id]) }}" class="text-sm text-emerald-600 font-semibold hover:underline">投資案件一覧を見る →</a>
                    </div>
                @else
                    <p class="text-gray-400 text-center py-6">投資案件データがありません。</p>
                @endif
            </div>
            <div x-show="activeTab === 'repairs'" x-cloak>
                @if(isset($repairs) && $repairs->isNotEmpty())
                    <div class="scroll-hint at-start">
                        <div class="scroll-hint-inner">
                            <table class="w-full border-collapse text-sm" style="min-width:500px">
                                <thead>
                                    <tr>
                                        <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">区画</th>
                                        <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">カテゴリ</th>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">内容</th>
                                        <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">ステータス</th>
                                        <th class="px-4 py-2.5 text-right font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">費用</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($repairs as $repair)
                                        <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('tenant.repairs.show', $repair) }}'">
                                            <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap {{ !$repair->unit_id ? 'text-gray-400 italic' : 'text-gray-900 font-semibold' }}">{{ $repair->unit_label }}</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap text-gray-900">{{ $repair->category_label }}</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-900">
                                                <a href="{{ route('tenant.repairs.show', $repair) }}" class="text-emerald-600 font-semibold hover:underline">{{ \Illuminate\Support\Str::limit($repair->description, 30) }}</a>
                                            </td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap">
                                                <span class="badge {{ $repair->status->badgeClass() }}">{{ $repair->status->label() }}</span>
                                            </td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 text-right font-semibold whitespace-nowrap text-gray-900">{{ $repair->cost !== null ? number_format($repair->cost) . '円' : '—' }}</td>
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
                    <p class="text-gray-400 text-center py-6">修繕データがありません。</p>
                @endif
            </div>
            <div x-show="activeTab === 'transactions'" x-cloak>
                @include('tenant.partials._rental-income', ['rentalIncome' => $rentalIncome])
            </div>
            <div x-show="activeTab === 'attachments'" x-cloak>
                <p class="text-gray-400 text-center py-6">添付ファイルの一覧がここに表示されます。（STEP 11で実装）</p>
            </div>

            {{-- 変更履歴タブ --}}
            <div x-show="activeTab === 'change_logs'" x-cloak>
                @if($changeLogs->isNotEmpty())
                    <div class="scroll-hint at-start">
                        <div class="scroll-hint-inner">
                            <table class="w-full border-collapse text-sm" style="min-width:500px">
                                <thead>
                                    <tr>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">日時</th>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">変更者</th>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">変更項目</th>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">旧値</th>
                                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">新値</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($changeLogs as $log)
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-900">{{ $log->changed_at->format('Y/m/d H:i') }}</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-900">{{ $log->changedByUser->name ?? '—' }}</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-900">{{ $log->field_name }}</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-700">{{ $log->old_value ?? '—' }}</td>
                                            <td class="px-4 py-2.5 border-b border-gray-200 whitespace-nowrap text-gray-900 font-semibold">{{ $log->new_value }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="scroll-hint-text">← スクロールできます →</div>
                    </div>
                @else
                    <p class="text-gray-400 text-center py-6">変更履歴はありません。</p>
                @endif
            </div>

        </div>
    </div>

    {{-- 削除確認モーダル --}}
    @if(auth()->user()->role->isExecutive())
        <x-delete-confirm-modal
            title="物件を削除しますか？"
            :action="route('tenant.properties.destroy', $property)"
            :target="$property->name"
        />
    @endif

</div>
@endsection
