@extends('layouts.app')

@section('title', $property->property_code . ' ' . $property->property_name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.properties.index') }}" class="text-gray-500 hover:text-emerald-600">建売物件一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $property->property_code }} {{ $property->property_name }}</span>
@endsection

@section('content')
    @php
        $isSold = $property->isSold();
        $contract = $property->contract;
    @endphp

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-5">
        <div>
            <h1 class="text-lg font-bold text-gray-900">{{ $property->property_name }}</h1>
            <div class="flex items-center gap-2 mt-1">
                <span class="text-sm text-gray-500">{{ $property->property_code }}</span>
                <span class="inline-block px-2.5 rounded-full text-xs font-semibold " style="padding-top:2px; padding-bottom:2px; {{ $property->getDisplayBadgeStyle() }}">{{ $property->getDisplayStatusLabel() }}</span>
            </div>
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            <a href="{{ route('housing.properties.index') }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">建売物件一覧に戻る</a>
            <a href="{{ route('housing.properties.edit', $property) }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 6px; text-decoration: none; background: #fff;">編集</a>
            <form method="POST" action="{{ route('housing.properties.destroy', $property) }}"
                  onsubmit="return confirm('この物件を削除しますか？関連する契約・ファイルも全て削除されます。')">
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
        <div style="display: grid; grid-template-columns: 130px 1fr 130px 1fr; gap: 0; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden;">
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">物件番号</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $property->property_code }}</div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">ステータス</div>
            <div style="padding: 10px 14px; border-bottom: 1px solid #e5e7eb;">
                <span class="inline-block px-2.5 rounded-full text-xs font-semibold " style="padding-top:2px; padding-bottom:2px; {{ $property->getDisplayBadgeStyle() }}">{{ $property->getDisplayStatusLabel() }}</span>
            </div>

            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">物件名</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $property->property_name }}</div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">土地紐づけ</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">
                @php $sourceDisplay = $property->getLandSourceDisplay(); @endphp
                @if($sourceDisplay)
                    @if($property->land_source_type === \App\Enums\HousingLandSourceType::ProjectLot && $property->projectLot)
                        <a href="{{ route('realestate.projects.show', $property->projectLot->project_id) }}" style="color: #1d4ed8; text-decoration: underline;">{{ $sourceDisplay }}</a>
                    @elseif($property->land_source_type === \App\Enums\HousingLandSourceType::Procurement && $property->procurement)
                        <a href="{{ route('realestate.procurements.show', $property->procurement) }}" style="color: #1d4ed8; text-decoration: underline;">{{ $sourceDisplay }}</a>
                    @else
                        {{ $sourceDisplay }}
                    @endif
                @else
                    <span class="text-gray-400">—</span>
                @endif
            </div>

            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">所在地</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">
                @if($property->postal_code)
                    〒{{ $property->postal_code }}
                @endif
                {{ $property->address }}
            </div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">構造</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $property->structure ?? '—' }}</div>

            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">土地面積</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">
                @if($property->land_area_sqm)
                    {{ $property->land_area_sqm }}㎡（{{ $property->getLandAreaTsubo() }}坪）
                @else
                    —
                @endif
            </div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">建物面積</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">
                @if($property->building_area_sqm)
                    {{ $property->building_area_sqm }}㎡（{{ $property->getBuildingAreaTsubo() }}坪）
                @else
                    —
                @endif
            </div>

            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">完成予定日</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $property->scheduled_completion_date?->format('Y/m/d') ?? '—' }}</div>
            <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">実際の完成日</div>
            <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $property->actual_completion_date?->format('Y/m/d') ?? '—' }}</div>
        </div>
    </div>

    {{-- 収支サマリー（契約あり）または 原価情報（契約なし） --}}
    @if($isSold)
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">収支サマリー</div>
            @php
                $c = $contract;
                $landProfit = $c->getLandProfit();
                $buildingProfit = $c->getBuildingProfit();
                $totalProfit = $c->getTotalProfit();
                $buildingTax = $c->getBuildingTax();
            @endphp
            <table class="w-full border-collapse">
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
                        <td class="text-sm border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ number_format($c->selling_price_land) }}円</td>
                        <td class="text-sm border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ number_format($c->selling_price_building) }}円</td>
                        <td class="text-sm font-semibold border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ number_format($c->getSellingPriceTotal()) }}円</td>
                    </tr>
                    <tr>
                        <td class="bg-gray-50 text-sm text-gray-600 font-medium border border-gray-200" style="padding: 10px 14px;">消費税</td>
                        <td class="text-sm text-gray-400 border border-gray-200" style="padding: 10px 14px; text-align: right;">—</td>
                        <td class="text-sm border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ number_format($buildingTax) }}円</td>
                        <td class="text-sm border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ number_format($buildingTax) }}円</td>
                    </tr>
                    <tr>
                        <td class="bg-gray-50 text-sm text-gray-600 font-medium border border-gray-200" style="padding: 10px 14px;">税込販売価格</td>
                        <td class="text-sm border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ number_format($c->selling_price_land) }}円</td>
                        <td class="text-sm border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ number_format($c->selling_price_building + $buildingTax) }}円</td>
                        <td class="text-sm font-semibold border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ number_format($c->getSellingPriceTotalWithTax()) }}円</td>
                    </tr>
                    <tr>
                        <td class="bg-gray-50 text-sm text-gray-600 font-medium border border-gray-200" style="padding: 10px 14px;">原価</td>
                        <td class="text-sm border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ $property->land_cost !== null ? number_format($property->land_cost) . '円' : '—' }}</td>
                        <td class="text-sm border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ $property->building_cost !== null ? number_format($property->building_cost) . '円' : '—' }}</td>
                        <td class="text-sm font-semibold border border-gray-200" style="padding: 10px 14px; text-align: right;">{{ $property->getTotalCost() !== null ? number_format($property->getTotalCost()) . '円' : '—' }}</td>
                    </tr>
                    <tr style="border-top: 2px solid #b0b0b0;">
                        <td class="text-sm font-bold border border-gray-200" style="padding: 14px 14px; background: #fef3c7; color: #111827;">粗利額</td>
                        <td class="text-sm border border-gray-200" style="padding: 14px 14px; text-align: right; font-size: 15px; font-weight: 800; background: #fffbeb; {{ $landProfit !== null && $landProfit >= 0 ? 'color: #047857;' : 'color: #dc2626;' }}">
                            @if($landProfit !== null)
                                {{ number_format($landProfit) }}円
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-sm border border-gray-200" style="padding: 14px 14px; text-align: right; font-size: 15px; font-weight: 800; background: #fffbeb; {{ $buildingProfit !== null && $buildingProfit >= 0 ? 'color: #047857;' : ($buildingProfit !== null && $buildingProfit < 0 ? 'color: #dc2626;' : 'color: #374151;') }}">
                            @if($buildingProfit !== null)
                                {{ number_format($buildingProfit) }}円
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-sm border border-gray-200" style="padding: 14px 14px; text-align: right; font-size: 18px; font-weight: 800; background: #fffbeb; {{ $totalProfit !== null && $totalProfit >= 0 ? 'color: #047857;' : 'color: #dc2626;' }}">
                            @if($totalProfit !== null)
                                {{ number_format($totalProfit) }}円
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="text-sm font-bold border border-gray-200" style="padding: 14px 14px; background: #fef3c7; color: #111827;">粗利率</td>
                        <td class="text-sm border border-gray-200" style="padding: 14px 14px; text-align: right; font-size: 15px; font-weight: 800; background: #fffbeb; {{ $c->getLandProfitRate() !== null && $c->getLandProfitRate() >= 0 ? 'color: #047857;' : 'color: #dc2626;' }}">
                            {{ $c->getLandProfitRate() !== null ? $c->getLandProfitRate() . '%' : '—' }}
                        </td>
                        <td class="text-sm border border-gray-200" style="padding: 14px 14px; text-align: right; font-size: 15px; font-weight: 800; background: #fffbeb; {{ $c->getBuildingProfitRate() !== null && $c->getBuildingProfitRate() >= 0 ? 'color: #047857;' : ($c->getBuildingProfitRate() !== null && $c->getBuildingProfitRate() < 0 ? 'color: #dc2626;' : 'color: #374151;') }}">
                            {{ $c->getBuildingProfitRate() !== null ? $c->getBuildingProfitRate() . '%' : '—' }}
                        </td>
                        <td class="text-sm border border-gray-200" style="padding: 14px 14px; text-align: right; font-size: 18px; font-weight: 800; background: #fffbeb; {{ $c->getTotalProfitRate() !== null && $c->getTotalProfitRate() >= 0 ? 'color: #047857;' : 'color: #dc2626;' }}">
                            {{ $c->getTotalProfitRate() !== null ? $c->getTotalProfitRate() . '%' : '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    @else
        {{-- 原価・販売予定情報（未契約時） --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">原価・販売予定情報</div>
            <div style="display: grid; grid-template-columns: 150px 1fr 150px 1fr; gap: 0; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden;">
                <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">土地原価</div>
                <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $property->land_cost !== null ? number_format($property->land_cost) . '円' : '—' }}</div>
                <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">建築費</div>
                <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $property->building_cost !== null ? number_format($property->building_cost) . '円' : '—' }}</div>
                <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">原価合計</div>
                <div style="padding: 10px 14px; font-size: 14px; font-weight: 700; border-bottom: 1px solid #e5e7eb;">{{ $property->getTotalCost() !== null ? number_format($property->getTotalCost()) . '円' : '—' }}</div>
                <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">建物予定販売価格</div>
                <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $property->target_selling_price_building !== null ? number_format($property->target_selling_price_building) . '円' : '—' }}</div>
            </div>
            @php $refLandPrice = $property->getReferenceLandSellingPrice(); @endphp
            @if($refLandPrice !== null || $property->target_selling_price_building !== null)
                <div style="margin-top: 12px; padding: 10px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px; color: #475569;">
                    <span style="font-weight: 600;">参考販売価格:</span>
                    @if($refLandPrice !== null)
                        土地 {{ number_format($refLandPrice) }}円（{{ $property->getLandSourceDisplay() }}の販売価格）
                    @endif
                    @if($refLandPrice !== null && $property->target_selling_price_building !== null)
                        ／
                    @endif
                    @if($property->target_selling_price_building !== null)
                        建物 {{ number_format($property->target_selling_price_building) }}円（建物予定販売価格）
                    @endif
                </div>
            @endif
        </div>
    @endif

    {{-- 契約情報（成約済み） --}}
    @if($isSold)
        <div class="bg-white rounded-lg p-5 mb-5" style="border: 2px solid #059669;">
            <div class="flex items-center justify-between pb-2 mb-3.5 border-b border-gray-200">
                <div class="text-sm font-bold text-gray-800 flex items-center gap-2">
                    <span style="width: 4px; height: 18px; background: #059669; border-radius: 2px; display: inline-block;"></span>
                    契約情報
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('housing.contracts.edit', $property) }}"
                       style="display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 4px; text-decoration: none; background: #fff;">契約編集</a>
                    <form method="POST" action="{{ route('housing.contracts.destroy', $property) }}"
                          onsubmit="return confirm('契約を削除しますか？物件のステータスが「販売中」に戻ります。')">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                style="display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 4px; background: #fff; cursor: pointer;">契約削除</button>
                    </form>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 130px 1fr 130px 1fr; gap: 0; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden;">
                <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">顧客名</div>
                <div style="padding: 10px 14px; font-size: 14px; font-weight: 600; border-bottom: 1px solid #e5e7eb;">
                    <a href="#" style="color: #1d4ed8; text-decoration: underline;">{{ $contract->customer_name }}</a>
                </div>
                <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">契約日</div>
                <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $contract->contract_date->format('Y/m/d') }}</div>
                <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">決済日</div>
                <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">{{ $contract->settlement_date?->format('Y/m/d') ?? '—（未定）' }}</div>
                <div style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;"></div>
                <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;"></div>
            </div>
            @if($contract->notes)
                <div style="margin-top: 12px; padding: 10px 14px; background: #f9fafb; border-radius: 6px; font-size: 13px; color: #4b5563;">
                    <span style="font-weight: 600; color: #374151;">契約備考:</span> {{ $contract->notes }}
                </div>
            @endif
        </div>
    @endif

    {{-- ファイル管理 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5" x-data="housingFileManager()">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">ファイル管理</div>

        @foreach(\App\Enums\HousingFileCategory::cases() as $cat)
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
                            <button @click="deleteFile(file.id, '{{ $cat->value }}')" style="background: none; border: none; color: #9ca3af; font-size: 12px; cursor: pointer; padding: 2px 6px;" title="削除">✕</button>
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
            <span>登録: {{ $property->createdBy->name ?? '—' }} {{ $property->created_at->format('Y/m/d H:i') }}</span>
            @if($property->updatedBy)
                <span>更新: {{ $property->updatedBy->name }} {{ $property->updated_at->format('Y/m/d H:i') }}</span>
            @endif
        </div>
    </div>
{{-- @stack('scripts') がレイアウトに存在しないため、スクリプトを @section 内にインラインで埋め込む --}}
<script>
function housingFileManager() {
    return {
        files: @json($filesByCategory),
        uploadMessage: '',
        uploadSuccess: false,

        uploadFile: function(event, category) {
            var self = this;
            var file = event.target.files[0];
            if (!file) return;

            var formData = new FormData();
            formData.append('file', file);
            formData.append('category', category);

            self.uploadMessage = 'アップロード中...';
            self.uploadSuccess = true;

            var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            fetch('{{ route("housing.properties.files.store", $property) }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(function(res) {
                return res.json().then(function(data) {
                    return { status: res.status, ok: res.ok, data: data };
                }).catch(function() {
                    return { status: res.status, ok: res.ok, data: null };
                });
            })
            .then(function(result) {
                if (result.ok && result.data && result.data.success) {
                    self.files[category].push(result.data.file);
                    self.uploadMessage = 'アップロードしました。';
                    self.uploadSuccess = true;
                    return;
                }
                self.uploadSuccess = false;
                if (result.status === 419) {
                    self.uploadMessage = 'セッションが切れました。ページを再読込してください。';
                } else if (result.status === 422 && result.data && result.data.errors) {
                    var keys = Object.keys(result.data.errors);
                    self.uploadMessage = keys.length > 0
                        ? result.data.errors[keys[0]][0]
                        : (result.data.message || 'バリデーションエラー');
                } else if (result.data && result.data.message) {
                    self.uploadMessage = 'アップロード失敗: ' + result.data.message;
                } else {
                    self.uploadMessage = 'アップロード失敗（HTTP ' + result.status + '）';
                }
            })
            .catch(function(err) {
                self.uploadMessage = '通信エラー: ' + (err && err.message ? err.message : '不明');
                self.uploadSuccess = false;
            });

            event.target.value = '';
        },

        deleteFile: function(fileId, category) {
            if (!confirm('このファイルを削除しますか？')) return;

            var self = this;
            fetch('{{ url("/housing/properties/" . $property->id . "/files") }}/' + fileId, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
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
