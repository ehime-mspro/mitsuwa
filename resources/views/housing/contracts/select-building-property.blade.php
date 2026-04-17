@extends('layouts.app')

@section('title', '建売契約登録 — 物件選択')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.contracts.index') }}" class="hover:text-emerald-600">契約管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">建売契約登録 — 物件選択</span>
@endsection

@section('content')

    {{-- ページヘッダー --}}
    <div class="mb-5" style="display: flex; align-items: center; justify-content: space-between; gap: 12px;">
        <h1 class="text-lg font-bold text-gray-900">建売契約登録 — 物件選択</h1>
        <a href="{{ route('housing.contracts.index') }}"
           style="display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; font-size: 13px; font-weight: 600; background: white; color: #6B7280; border: 1px solid #D1D5DB; border-radius: 6px; text-decoration: none;"
           onmouseover="this.style.background='#F9FAFB';"
           onmouseout="this.style.background='white';">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            一覧へ戻る
        </a>
    </div>

    {{-- 説明文 --}}
    <div style="background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 6px; padding: 12px 16px; margin-bottom: 16px; font-size: 13px; color: #1E40AF;">
        契約を登録する建売物件を選択してください。一覧には未契約の物件のみ表示されています。契約済みの物件は「契約管理」一覧から該当行の詳細ページを開いて編集してください。
    </div>

    {{-- フィルターバー（物件名検索のみ、ステータスは「未契約」固定） --}}
    <form id="filter-form" method="GET" action="{{ route('housing.contracts.select-building-property') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <input type="text" name="keyword" value="{{ $keyword }}"
               placeholder="物件名で検索"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
        <button type="submit"
                class="h-9 px-4 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md whitespace-nowrap w-full sm:w-auto">
            検索
        </button>
        <a href="{{ route('housing.contracts.select-building-property') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
            クリア
        </a>
    </form>

    {{-- テーブル（7列構成） --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        @if($properties->isEmpty())
            {{-- 空状態 --}}
            <div style="padding: 60px 20px; text-align: center; color: #9CA3AF; font-size: 14px;">
                <div style="margin-bottom: 16px;">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#D1D5DB" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                </div>
                <div style="color: #4B5563; font-weight: 600; font-size: 15px; margin-bottom: 8px;">
                    @if($keyword !== '')
                        「{{ $keyword }}」に該当する未契約の建売物件はありません
                    @else
                        未契約の建売物件がありません
                    @endif
                </div>
                <div style="margin-bottom: 16px;">
                    @if($keyword !== '')
                        検索条件を変更するか、建売物件を新規登録してください。
                    @else
                        新規に建売物件を登録してから契約登録を行ってください。
                    @endif
                </div>
                <a href="{{ route('housing.properties.create') }}"
                   style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #059669; color: white; font-size: 13px; font-weight: 600; border-radius: 4px; border: 1px solid #059669; text-decoration: none;"
                   onmouseover="this.style.background='#047857';"
                   onmouseout="this.style.background='#059669';">
                    建売物件を新規登録する
                </a>
            </div>
        @else
            <div style="overflow-x: auto;">
                <table class="w-full border-collapse" style="min-width: 900px;">
                    <thead>
                        <tr>
                            <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">物件コード</th>
                            <th class="py-2.5 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding-left: 16px;">物件名</th>
                            <th class="py-2.5 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding-left: 16px;">住所</th>
                            <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">土地面積</th>
                            <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">建物面積</th>
                            <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">予定販売価格</th>
                            <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">アクション</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($properties as $prop)
                            @php
                                $sellingTotal = $prop->getSellingPriceTotal();
                            @endphp
                            <tr class="hover:bg-gray-50 transition-colors">
                                {{-- 1. 物件コード --}}
                                <td class="px-3 py-3 border-b border-gray-100 text-sm text-center whitespace-nowrap">{{ $prop->property_code ?? '—' }}</td>

                                {{-- 2. 物件名 --}}
                                <td class="py-3 border-b border-gray-100 whitespace-nowrap" style="padding-left: 16px;">
                                    <a href="{{ route('housing.properties.show', $prop) }}"
                                       class="text-sm font-medium text-emerald-600 hover:text-emerald-700 hover:underline">{{ $prop->property_name }}</a>
                                </td>

                                {{-- 3. 住所 --}}
                                <td class="py-3 border-b border-gray-100 text-sm text-gray-700" style="padding-left: 16px;">
                                    {{ $prop->address ?? '—' }}
                                </td>

                                {{-- 4. 土地面積 --}}
                                <td class="px-3 py-3 border-b border-gray-100 text-sm text-center whitespace-nowrap">
                                    @if($prop->land_area_sqm !== null)
                                        {{ number_format((float) $prop->land_area_sqm, 2) }}㎡
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>

                                {{-- 5. 建物面積 --}}
                                <td class="px-3 py-3 border-b border-gray-100 text-sm text-center whitespace-nowrap">
                                    @if($prop->building_area_sqm !== null)
                                        {{ number_format((float) $prop->building_area_sqm, 2) }}㎡
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>

                                {{-- 6. 予定販売価格（建物予定 + 土地参考） --}}
                                <td class="px-3 py-3 border-b border-gray-100 text-sm text-center whitespace-nowrap">
                                    @if($sellingTotal !== null)
                                        {{ number_format($sellingTotal) }}円
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>

                                {{-- 7. アクション --}}
                                <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                    <a href="{{ route('housing.contracts.create', $prop) }}"
                                       style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; background: #059669; color: white; font-size: 12px; font-weight: 600; border-radius: 4px; border: 1px solid #059669; text-decoration: none;"
                                       onmouseover="this.style.background='#047857';"
                                       onmouseout="this.style.background='#059669';">
                                        この物件で契約登録
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- 件数表示 --}}
            <div class="px-4 py-2.5 border-t border-gray-200 text-sm text-gray-500">
                全 {{ $properties->total() }} 件（未契約の建売物件）
                @if($properties->total() > 0)
                    ／ {{ $properties->firstItem() }} 〜 {{ $properties->lastItem() }} 件目を表示
                @endif
            </div>

            {{-- ページネーション（20件/ページ） --}}
            @if($properties->hasPages())
                <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
                    @if($properties->onFirstPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                    @else
                        <a href="{{ $properties->previousPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">&lt;</a>
                    @endif
                    @foreach($properties->getUrlRange(1, $properties->lastPage()) as $page => $url)
                        @if($page == $properties->currentPage())
                            <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">{{ $page }}</a>
                        @endif
                    @endforeach
                    @if($properties->hasMorePages())
                        <a href="{{ $properties->nextPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">&gt;</a>
                    @else
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                    @endif
                </div>
            @endif
        @endif
    </div>

@endsection
