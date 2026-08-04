@extends('layouts.app')

@section('title', $customOrder->order_code . ' ' . $customOrder->order_name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.custom-orders.index') }}" class="text-gray-500 hover:text-emerald-600">注文住宅一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $customOrder->order_code }} {{ $customOrder->order_name }}</span>
@endsection

@section('content')
    @php
        $o = $customOrder;
        $isContracted = $o->isContractedOrLater();
        $isCompanyLand = $o->isCompanyLand();
        $isCustomerLand = $o->isCustomerLand();
    @endphp

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-5">
        <div>
            <h1 class="text-lg font-bold text-gray-900">{{ $o->order_name }}</h1>
            <div class="flex items-center gap-2 mt-1">
                <span class="text-sm text-gray-500">{{ $o->order_code }}</span>
                <span class="inline-block px-2.5 rounded-full text-xs font-semibold" style="padding-top:2px; padding-bottom:2px; {{ $o->getDisplayBadgeStyle() }}">{{ $o->status->label() }}</span>
            </div>
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            <a href="{{ route('housing.custom-orders.index') }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">注文住宅一覧に戻る</a>
            <a href="{{ route('housing.custom-orders.edit', $o) }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 6px; text-decoration: none; background: #fff;">編集</a>
            <form method="POST" action="{{ route('housing.custom-orders.destroy', $o) }}"
                  onsubmit="return confirm('この案件を削除しますか？関連するファイルも全て削除されます。')">
                @csrf
                @method('DELETE')
                <button type="submit"
                        style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 6px; background: #fff; cursor: pointer;">削除</button>
            </form>
        </div>
    </div>



    {{-- 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">基本情報</div>
        <div class="dl-stack-sm" style="display: grid; grid-template-columns: 140px 1fr 140px 1fr; gap: 0; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden;">
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">案件番号</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $o->order_code }}</div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">ステータス</div>
            <div style="padding: 10px 14px; border-bottom: 1px solid #e5e7eb;">
                <span class="inline-block px-2.5 rounded-full text-xs font-semibold" style="padding-top:2px; padding-bottom:2px; {{ $o->getDisplayBadgeStyle() }}">{{ $o->status->label() }}</span>
            </div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">案件名</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $o->order_name }}</div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">顧客名</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $o->customer_name }}</div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">土地種別</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $o->land_source_type?->label() ?? '—' }}</div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">紐づけ先</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">
                @if($o->land_source_type === \App\Enums\HousingLandSourceType::ProjectLot && $o->projectLot)
                    <a href="{{ route('realestate.projects.show', $o->projectLot->project_id) }}" style="color: #1d4ed8; text-decoration: underline;">{{ $o->getLandSourceDisplay() }}</a>
                @elseif($o->land_source_type === \App\Enums\HousingLandSourceType::Procurement && $o->procurement)
                    <a href="{{ route('realestate.procurements.show', $o->procurement) }}" style="color: #1d4ed8; text-decoration: underline;">{{ $o->getLandSourceDisplay() }}</a>
                @elseif($isCustomerLand)
                    <span class="text-gray-500">—（お客様所有）</span>
                @else
                    <span class="text-gray-400">—</span>
                @endif
            </div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">所在地</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">
                @if($o->postal_code)
                    〒{{ $o->postal_code }}
                @endif
                {{ $o->address }}
            </div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">構造</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $o->structure ?? '—' }}</div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">土地面積</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">
                @if($o->land_area_sqm)
                    {{ $o->land_area_sqm }}㎡（{{ number_format($o->getLandAreaTsubo(), 2) }}坪）
                @else
                    —
                @endif
            </div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">建物面積</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">
                @if($o->building_area_sqm)
                    {{ $o->building_area_sqm }}㎡（{{ number_format($o->getBuildingAreaTsubo(), 2) }}坪）
                @else
                    —
                @endif
            </div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">完成予定日</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $o->scheduled_completion_date?->format('Y/m/d') ?? '—' }}</div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">実際の完成日</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $o->actual_completion_date?->format('Y/m/d') ?? '—' }}</div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">契約日</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">
                @if($o->contract_date)
                    {{ $o->contract_date->format('Y/m/d') }}
                @else
                    <span class="text-gray-400">—（未契約）</span>
                @endif
            </div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-right: 1px solid #e5e7eb;">引渡日</div>
            <div style="padding: 10px 14px; font-size: 14px;">
                @if($o->delivery_date)
                    {{ $o->delivery_date->format('Y/m/d') }}
                @else
                    <span class="text-gray-400">—（未引渡し）</span>
                @endif
            </div>
        </div>
    </div>

    {{-- 収支サマリー / 原価情報 --}}
    @if($isContracted && ($o->building_contract_price !== null || ($isCompanyLand && $o->land_selling_price !== null)))
        {{-- 契約以降: 収支サマリー --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">収支サマリー</div>

            @if($isCompanyLand)
                {{-- 自社土地: 3列テーブル --}}
                @php
                    $buildingTax = $o->getBuildingTax();
                    $landProfit = $o->getLandProfit();
                    $buildingProfit = $o->getBuildingProfit();
                    $totalProfit = $o->getTotalProfit();
                @endphp
                <div class="scroll-hint at-start">
                <div class="scroll-hint-inner">
                <table class="w-full border-collapse" style="min-width: 520px;">
                    <thead>
                        <tr>
                            <th class="bg-gray-50 text-center text-xs font-semibold text-gray-600 border border-gray-200" style="padding: 10px 14px; width: 160px;">項目</th>
                            <th class="bg-gray-50 text-center text-xs font-semibold text-gray-600 border border-gray-200" style="padding: 10px 14px;">土地</th>
                            <th class="bg-gray-50 text-center text-xs font-semibold text-gray-600 border border-gray-200" style="padding: 10px 14px;">建物</th>
                            <th class="bg-gray-50 text-center text-xs font-semibold text-gray-600 border border-gray-200" style="padding: 10px 14px;">合計</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="bg-gray-50 text-sm text-gray-600 font-medium border border-gray-200" style="padding: 10px 14px;">販売価格</td>
                            <td class="text-sm border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ $o->land_selling_price !== null ? number_format($o->land_selling_price) . '円' : '—' }}</td>
                            <td class="text-sm border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ $o->building_contract_price !== null ? number_format($o->building_contract_price) . '円' : '—' }}</td>
                            <td class="text-sm font-semibold border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ $o->getTotalSellingPrice() !== null ? number_format($o->getTotalSellingPrice()) . '円' : '—' }}</td>
                        </tr>
                        <tr>
                            <td class="bg-gray-50 text-sm text-gray-600 font-medium border border-gray-200" style="padding: 10px 14px;">消費税</td>
                            <td class="text-sm text-gray-400 border border-gray-200" style="padding: 10px 14px; text-align: right;">—</td>
                            <td class="text-sm border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ number_format($buildingTax) }}円</td>
                            <td class="text-sm border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ number_format($buildingTax) }}円</td>
                        </tr>
                        <tr>
                            <td class="bg-gray-50 text-sm text-gray-600 font-medium border border-gray-200" style="padding: 10px 14px;">税込価格</td>
                            <td class="text-sm border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ $o->land_selling_price !== null ? number_format($o->land_selling_price) . '円' : '—' }}</td>
                            <td class="text-sm border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ $o->building_contract_price !== null ? number_format($o->building_contract_price + $buildingTax) . '円' : '—' }}</td>
                            <td class="text-sm font-semibold border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ $o->getTotalSellingPrice() !== null ? number_format($o->getTotalSellingPrice() + $buildingTax) . '円' : '—' }}</td>
                        </tr>
                        <tr>
                            <td class="bg-gray-50 text-sm text-gray-600 font-medium border border-gray-200" style="padding: 10px 14px;">原価</td>
                            <td class="text-sm border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ $o->land_cost !== null ? number_format($o->land_cost) . '円' : '—' }}</td>
                            <td class="text-sm border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ $o->building_cost !== null ? number_format($o->building_cost) . '円' : '—' }}</td>
                            <td class="text-sm font-semibold border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ $o->getTotalCost() !== null ? number_format($o->getTotalCost()) . '円' : '—' }}</td>
                        </tr>
                        <tr style="border-top: 2px solid #b0b0b0;">
                            <td class="text-sm font-bold border border-gray-200" style="padding: 14px 14px; background: #fef3c7; color: #111827;">粗利額</td>
                            <td class="text-sm border border-gray-200" style="padding: 14px 14px; text-align: right; font-size: 15px; font-weight: 800; background: #fffbeb; {{ $landProfit !== null ? ($landProfit >= 0 ? 'color: #047857;' : 'color: #dc2626;') : 'color: #374151;' }}">
                                {{ $landProfit !== null ? number_format($landProfit) . '円' : '—' }}
                            </td>
                            <td class="text-sm border border-gray-200" style="padding: 14px 14px; text-align: right; font-size: 15px; font-weight: 800; background: #fffbeb; {{ $buildingProfit !== null ? ($buildingProfit >= 0 ? 'color: #047857;' : 'color: #dc2626;') : 'color: #374151;' }}">
                                {{ $buildingProfit !== null ? number_format($buildingProfit) . '円' : '—' }}
                            </td>
                            <td class="text-sm border border-gray-200" style="padding: 14px 14px; text-align: right; font-size: 18px; font-weight: 800; background: #fffbeb; {{ $totalProfit !== null ? ($totalProfit >= 0 ? 'color: #047857;' : 'color: #dc2626;') : 'color: #374151;' }}">
                                {{ $totalProfit !== null ? number_format($totalProfit) . '円' : '—' }}
                            </td>
                        </tr>
                        <tr>
                            <td class="text-sm font-bold border border-gray-200" style="padding: 14px 14px; background: #fef3c7; color: #111827;">粗利率</td>
                            <td class="text-sm border border-gray-200" style="padding: 14px 14px; text-align: right; font-size: 15px; font-weight: 800; background: #fffbeb; {{ $o->getLandProfitRate() !== null ? ($o->getLandProfitRate() >= 0 ? 'color: #047857;' : 'color: #dc2626;') : 'color: #374151;' }}">
                                {{ $o->getLandProfitRate() !== null ? $o->getLandProfitRate() . '%' : '—' }}
                            </td>
                            <td class="text-sm border border-gray-200" style="padding: 14px 14px; text-align: right; font-size: 15px; font-weight: 800; background: #fffbeb; {{ $o->getBuildingProfitRate() !== null ? ($o->getBuildingProfitRate() >= 0 ? 'color: #047857;' : 'color: #dc2626;') : 'color: #374151;' }}">
                                {{ $o->getBuildingProfitRate() !== null ? $o->getBuildingProfitRate() . '%' : '—' }}
                            </td>
                            <td class="text-sm border border-gray-200" style="padding: 14px 14px; text-align: right; font-size: 18px; font-weight: 800; background: #fffbeb; {{ $o->getTotalProfitRate() !== null ? ($o->getTotalProfitRate() >= 0 ? 'color: #047857;' : 'color: #dc2626;') : 'color: #374151;' }}">
                                {{ $o->getTotalProfitRate() !== null ? $o->getTotalProfitRate() . '%' : '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
                <div class="scroll-hint-text">← スクロールできます →</div>
                </div>
            @else
                {{-- お客様所有土地 or 土地未選択: カード形式 --}}
                @php
                    $buildingTax = $o->getBuildingTax();
                    $buildingProfit = $o->getBuildingProfit();
                @endphp
                <div style="max-width: 420px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
                    <div style="display: flex; border-bottom: 1px solid #e5e7eb;">
                        <div style="width: 160px; min-width: 160px; background: #f9fafb; padding: 11px 14px; font-size: 13px; font-weight: 500; color: #4b5563;">請負金額（税抜）</div>
                        <div style="flex: 1; padding: 11px 14px; font-size: 14px; color: #111827; text-align: right;">{{ $o->building_contract_price !== null ? number_format($o->building_contract_price) . '円' : '—' }}</div>
                    </div>
                    <div style="display: flex; border-bottom: 1px solid #e5e7eb;">
                        <div style="width: 160px; min-width: 160px; background: #f9fafb; padding: 11px 14px; font-size: 13px; font-weight: 500; color: #4b5563;">消費税（{{ $o->tax_rate }}%）</div>
                        <div style="flex: 1; padding: 11px 14px; font-size: 14px; color: #111827; text-align: right;">{{ number_format($buildingTax) }}円</div>
                    </div>
                    <div style="display: flex; border-bottom: 1px solid #e5e7eb;">
                        <div style="width: 160px; min-width: 160px; background: #f9fafb; padding: 11px 14px; font-size: 13px; font-weight: 500; color: #4b5563;">税込価格</div>
                        <div style="flex: 1; padding: 11px 14px; font-size: 14px; color: #111827; text-align: right; font-weight: 600;">{{ $o->building_contract_price !== null ? number_format($o->building_contract_price + $buildingTax) . '円' : '—' }}</div>
                    </div>
                    <div style="display: flex; border-bottom: 1px solid #e5e7eb;">
                        <div style="width: 160px; min-width: 160px; background: #f9fafb; padding: 11px 14px; font-size: 13px; font-weight: 500; color: #4b5563;">建築原価</div>
                        <div style="flex: 1; padding: 11px 14px; font-size: 14px; color: #111827; text-align: right;">{{ $o->building_cost !== null ? number_format($o->building_cost) . '円' : '—' }}</div>
                    </div>
                    <div style="display: flex; border-bottom: 1px solid #e5e7eb; background: #fffbeb; border-top: 2px solid #b0b0b0;">
                        <div style="width: 160px; min-width: 160px; background: #fef3c7; padding: 11px 14px; font-size: 14px; font-weight: 700; color: #111827;">粗利額</div>
                        <div style="flex: 1; padding: 11px 14px; text-align: right; font-size: 18px; font-weight: 800; {{ $buildingProfit !== null ? ($buildingProfit >= 0 ? 'color: #047857;' : 'color: #dc2626;') : 'color: #374151;' }}">
                            {{ $buildingProfit !== null ? number_format($buildingProfit) . '円' : '—' }}
                        </div>
                    </div>
                    <div style="display: flex; background: #fffbeb;">
                        <div style="width: 160px; min-width: 160px; background: #fef3c7; padding: 11px 14px; font-size: 14px; font-weight: 700; color: #111827;">粗利率</div>
                        <div style="flex: 1; padding: 11px 14px; text-align: right; font-size: 18px; font-weight: 800; {{ $o->getBuildingProfitRate() !== null ? ($o->getBuildingProfitRate() >= 0 ? 'color: #047857;' : 'color: #dc2626;') : 'color: #374151;' }}">
                            {{ $o->getBuildingProfitRate() !== null ? $o->getBuildingProfitRate() . '%' : '—' }}
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @else
        {{-- 契約前: 原価情報 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">原価情報</div>
            <div class="dl-stack-sm" style="display: grid; grid-template-columns: 140px 1fr 140px 1fr; gap: 0; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden;">
                @if($isCompanyLand)
                    <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">土地原価</div>
                    <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $o->land_cost !== null ? number_format($o->land_cost) . '円' : '—' }}</div>
                @else
                    <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">土地原価</div>
                    <div style="padding: 10px 14px; font-size: 14px; color: #6b7280; border-bottom: 1px solid #e5e7eb;">—（{{ $isCustomerLand ? 'お客様所有' : '未選択' }}）</div>
                @endif
                <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">建築原価</div>
                <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $o->building_cost !== null ? number_format($o->building_cost) . '円' : '—（未確定）' }}</div>
                <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-right: 1px solid #e5e7eb;">消費税率</div>
                <div style="padding: 10px 14px; font-size: 14px;">{{ $o->tax_rate }}%</div>
                <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-right: 1px solid #e5e7eb;"></div>
                <div style="padding: 10px 14px;"></div>
            </div>
        </div>
    @endif

    {{-- 備考 --}}
    @if($o->notes)
        <div class="bg-white border border-gray-200 rounded-lg px-5 py-3 mb-5">
            <div class="flex gap-6 text-sm text-gray-600">
                <span class="text-gray-500 font-semibold">備考:</span> {{ $o->notes }}
            </div>
        </div>
    @endif

    {{-- ファイル管理 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5" x-data="customOrderFileManager()">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">ファイル管理</div>
        <div class="text-xs text-gray-500" style="margin-bottom: 12px;">※ アップロード可能なファイルサイズは 7MB 以下です。</div>

        @foreach(\App\Enums\CustomOrderFileCategory::cases() as $cat)
            <div style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; margin-bottom: 16px;">
                <div style="background: #f9fafb; padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e5e7eb;">
                    <span class="text-sm font-bold text-gray-700">{{ $cat->label() }}</span>
                    <label style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 12px; border-radius: 5px; font-size: 12px; font-weight: 600; color: #059669; border: 1px solid #059669; background: #fff; cursor: pointer;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        アップロード
                        <input type="file" class="hidden" @change="uploadFile($event, '{{ $cat->value }}')" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx">
                    </label>
                </div>
                <div style="padding: 12px 16px;">
                    <template x-for="file in files['{{ $cat->value }}']" :key="file.id">
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f3f4f6;">
                            <div>
                                <a :href="file.file_path" target="_blank" class="text-sm" style="color: #1d4ed8; text-decoration: underline;" x-text="file.file_name"></a>
                                <span class="text-xs text-gray-500" style="margin-left: 12px;" x-text="file.file_size + ' ' + file.uploaded_by + ' ' + file.created_at"></span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <a :href="file.file_path + '?download=1'" title="ダウンロード"
                                   style="display: inline-flex; align-items: center; color: #9ca3af; text-decoration: none;">
                                    <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                </a>
                                <button @click="deleteFile(file.id, '{{ $cat->value }}')" style="background: none; border: none; color: #9ca3af; font-size: 12px; cursor: pointer; padding: 2px 6px;" title="削除">✕</button>
                            </div>
                        </div>
                    </template>
                    <div x-show="files['{{ $cat->value }}'].length === 0" class="text-sm text-gray-400">
                        アップロードされたファイルはありません
                    </div>
                </div>
            </div>
        @endforeach

        <div x-show="uploadMessage" class="text-sm mt-2" :class="uploadSuccess ? 'text-emerald-600' : 'text-red-600'" x-text="uploadMessage"></div>
    </div>

    {{-- 登録情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg px-5 py-3">
        <div class="flex gap-6 text-xs text-gray-500">
            <span>登録: {{ $o->createdBy->name ?? '—' }} {{ $o->created_at->format('Y/m/d H:i') }}</span>
            @if($o->updatedBy)
                <span>更新: {{ $o->updatedBy->name }} {{ $o->updated_at->format('Y/m/d H:i') }}</span>
            @endif
        </div>
    </div>
{{-- スクリプトは @section 内にインラインで置く（@push('scripts') へは移していない）。
     ⚠ 2026-07-26 に layouts/app.blade.php へ @stack('scripts') を追加したので push も使えるが、
       インラインで正常動作しているため移行していない（Bug #28） --}}
<script>
function customOrderFileManager() {
    return {
        files: @json($filesByCategory),
        uploadMessage: '',
        uploadSuccess: false,

        uploadFile: function(event, category) {
            var self = this;
            var file = event.target.files[0];
            if (!file) return;

            // ファイルサイズチェック（サーバー側 POST 上限 約8MB のため 7MB を上限とする）
            var MAX_SIZE = 7 * 1024 * 1024;
            if (file.size > MAX_SIZE) {
                self.uploadSuccess = false;
                var mb = (file.size / (1024 * 1024)).toFixed(1);
                self.uploadMessage = 'ファイルサイズが大きすぎます（' + mb + 'MB）。7MB 以下に圧縮してアップロードしてください。';
                event.target.value = '';
                return;
            }

            var formData = new FormData();
            formData.append('file', file);
            formData.append('category', category);

            self.uploadMessage = 'アップロード中...';
            self.uploadSuccess = true;

            fetch('{{ route("housing.custom-orders.files.store", $customOrder) }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(function(res) {
                if (!res.ok) {
                    return res.json().then(function(err) {
                        var msg = err.message || 'エラーが発生しました。';
                        if (err.errors) {
                            var details = Object.values(err.errors).flat().join('\n');
                            msg = msg + '\n' + details;
                        }
                        alert(msg);
                        return null;
                    }).catch(function() {
                        alert('サーバーエラーが発生しました（' + res.status + '）');
                        return null;
                    });
                }
                return res.json();
            })
            .then(function(data) {
                if (!data) return;
                if (data.success) {
                    self.files[category].push(data.file);
                    self.uploadMessage = 'アップロードしました。';
                    self.uploadSuccess = true;
                } else {
                    self.uploadMessage = 'アップロードに失敗しました。';
                    self.uploadSuccess = false;
                }
            })
            .catch(function() {
                self.uploadMessage = 'アップロードに失敗しました。';
                self.uploadSuccess = false;
            });

            event.target.value = '';
        },

        deleteFile: function(fileId, category) {
            if (!confirm('このファイルを削除しますか？')) return;

            var self = this;
            fetch('{{ url("/housing/custom-orders/" . $customOrder->id . "/documents") }}/' + fileId, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                }
            })
            .then(function(res) {
                if (!res.ok) {
                    return res.json().then(function(err) {
                        var msg = err.message || 'エラーが発生しました。';
                        if (err.errors) {
                            var details = Object.values(err.errors).flat().join('\n');
                            msg = msg + '\n' + details;
                        }
                        alert(msg);
                        return null;
                    }).catch(function() {
                        alert('サーバーエラーが発生しました（' + res.status + '）');
                        return null;
                    });
                }
                return res.json();
            })
            .then(function(data) {
                if (!data) return;
                if (data.success) {
                    self.files[category] = self.files[category].filter(function(f) { return f.id !== fileId; });
                    self.uploadMessage = '削除しました。';
                    self.uploadSuccess = true;
                }
            })
            .catch(function() {
                self.uploadMessage = '削除に失敗しました。';
                self.uploadSuccess = false;
            });
        }
    };
}
</script>
@endsection
