@extends('layouts.app')

@section('title', $property->property_name . ' — 契約詳細')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.contract-list.index') }}" class="hover:text-emerald-600 transition-colors">契約管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $property->property_name }}</span>
@endsection

@section('content')

    {{-- ヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <div class="flex items-center gap-3">
            <h1 class="text-lg font-bold text-gray-900">{{ $property->property_name }}</h1>
            <span style="background: #dbeafe; color: #1e40af; display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">建売</span>
        </div>
        <div class="flex gap-2">
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('housing.contracts.edit', $property) }}"
                   class="px-3.5 py-1.5 bg-white border-2 border-gray-400 text-gray-700 text-sm font-semibold rounded-md hover:bg-gray-50 transition-colors"
                   style="font-size: 13px;">編集</a>
            @endif
            @if(auth()->user()->role->isExecutive())
                <form method="POST" action="{{ route('housing.contracts.destroy', $property) }}"
                      onsubmit="return confirm('この契約を削除しますか？')">
                    @csrf @method('DELETE')
                    <button type="submit"
                            class="px-3.5 py-1.5 bg-red-600 text-white font-semibold rounded-md hover:bg-red-700 transition-colors cursor-pointer"
                            style="font-size: 12px;">削除</button>
                </form>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
            <p class="text-sm text-emerald-800">{{ session('success') }}</p>
        </div>
    @endif

    {{-- 金額カード --}}
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px;">
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-500">契約額（税抜）</div>
            <div class="text-lg font-bold text-gray-900">{{ number_format($contract->getSellingPriceTotal()) }}円</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-500">原価合計</div>
            <div class="text-lg font-bold text-gray-900">
                @if($property->getTotalCost() !== null)
                    {{ number_format($property->getTotalCost()) }}円
                @else
                    —
                @endif
            </div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-500">粗利額</div>
            <div class="text-lg font-bold" style="color: #047857; font-weight: 700;">
                @if($contract->getTotalProfit() !== null)
                    {{ number_format($contract->getTotalProfit()) }}円
                @else
                    —
                @endif
            </div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-500">粗利率</div>
            <div class="text-lg font-bold text-gray-900">
                @if($contract->getTotalProfitRate() !== null)
                    {{ $contract->getTotalProfitRate() }}%
                @else
                    —
                @endif
            </div>
        </div>
    </div>

    {{-- 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-4">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">基本情報</h2>
        </div>
        <div class="border border-gray-200 rounded-md overflow-hidden" style="display: grid; grid-template-columns: 140px 1fr 140px 1fr;">
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">物件名</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200 font-medium">
                <a href="{{ route('housing.properties.show', $property) }}" class="text-emerald-600 hover:underline">{{ $property->property_name }}</a>
            </dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">物件コード</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $property->property_code ?? '—' }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">買主</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $contract->customer_name ?? '—' }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">契約日</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $contract->contract_date?->format('Y/m/d') ?? '—' }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">決済日</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $contract->settlement_date?->format('Y/m/d') ?? '—' }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">消費税率</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $contract->tax_rate }}%</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">土地販売価格</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ number_format($contract->selling_price_land) }}円</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">建物販売価格</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ number_format($contract->selling_price_building) }}円</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">建物消費税額</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ number_format($contract->getBuildingTax()) }}円</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">販売合計（税込）</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200 font-medium">{{ number_format($contract->getSellingPriceTotalWithTax()) }}円</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">土地原価</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">
                @if($property->land_cost !== null)
                    {{ number_format($property->land_cost) }}円
                @else
                    —
                @endif
            </dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">建築費</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">
                @if($property->building_cost !== null)
                    {{ number_format($property->building_cost) }}円
                @else
                    —
                @endif
            </dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">土地粗利</dt>
            <dd class="px-3.5 py-2.5 text-sm border-b border-gray-200" style="color: #047857; font-weight: 700;">
                @if($contract->getLandProfit() !== null)
                    {{ number_format($contract->getLandProfit()) }}円
                    @if($contract->getLandProfitRate() !== null)
                        <span class="text-xs" style="color: #6b7280; font-weight: 400; margin-left: 4px;">（{{ $contract->getLandProfitRate() }}%）</span>
                    @endif
                @else
                    <span style="color: #9ca3af; font-weight: 400;">—</span>
                @endif
            </dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">建物粗利</dt>
            <dd class="px-3.5 py-2.5 text-sm border-b border-gray-200" style="color: #047857; font-weight: 700;">
                @if($contract->getBuildingProfit() !== null)
                    {{ number_format($contract->getBuildingProfit()) }}円
                    @if($contract->getBuildingProfitRate() !== null)
                        <span class="text-xs" style="color: #6b7280; font-weight: 400; margin-left: 4px;">（{{ $contract->getBuildingProfitRate() }}%）</span>
                    @endif
                @else
                    <span style="color: #9ca3af; font-weight: 400;">—</span>
                @endif
            </dd>

            {{-- 紐づけ先情報 --}}
            @if($property->projectLot && $property->projectLot->project)
                <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-r border-gray-200">分譲地PJ</dt>
                <dd class="px-3.5 py-2.5 text-sm" style="grid-column: span 3;">
                    <a href="{{ route('realestate.projects.show', $property->projectLot->project) }}" class="text-emerald-600 hover:underline">{{ $property->projectLot->project->project_code }} — {{ $property->projectLot->project->project_name }}</a>
                    <span class="text-gray-500">/ 区画{{ $property->projectLot->lot_number }}</span>
                </dd>
            @elseif($property->procurement)
                <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-r border-gray-200">仕入れ案件</dt>
                <dd class="px-3.5 py-2.5 text-sm" style="grid-column: span 3;">
                    <a href="{{ route('realestate.procurements.show', $property->procurement) }}" class="text-emerald-600 hover:underline">{{ $property->procurement->procurement_code }} — {{ $property->procurement->property_name }}</a>
                </dd>
            @endif
        </div>
    </div>

    {{-- 備考 --}}
    @if($contract->notes)
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
            <div class="flex items-center gap-2 mb-4">
                <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
                <h2 class="text-base font-bold text-gray-900">備考</h2>
            </div>
            <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ $contract->notes }}</p>
        </div>
    @endif

    {{-- 登録情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-4">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">登録情報</h2>
        </div>
        <div class="border border-gray-200 rounded-md overflow-hidden" style="display: grid; grid-template-columns: 120px 1fr 120px 1fr;">
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">登録者</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $contract->createdBy->name ?? '—' }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">登録日時</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $contract->created_at?->format('Y/m/d H:i') }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-r border-gray-200">更新者</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900">{{ $contract->updatedBy->name ?? '—' }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-r border-gray-200">更新日時</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900">{{ $contract->updated_at?->format('Y/m/d H:i') }}</dd>
        </div>
    </div>

@endsection
