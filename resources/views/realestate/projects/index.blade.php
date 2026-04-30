@extends('layouts.app')

@section('title', '分譲地一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>不動産管理</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">分譲地一覧</span>
@endsection

@section('content')

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">分譲地一覧</h1>
        @if(auth()->user()->role->isManagerOrAbove())
            <a href="{{ route('realestate.projects.create') }}"
               class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                新規登録
            </a>
        @endif
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
            <p class="text-sm text-emerald-800">{{ session('success') }}</p>
        </div>
    @endif

    {{-- フィルターバー --}}
    <form id="filter-form" method="GET" action="{{ route('realestate.projects.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <select name="status" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="active" {{ request('status', 'active') === 'active' ? 'selected' : '' }}>ステータス: 不成立以外</option>
            <option value="" {{ request('status') === '' && request()->has('status') ? 'selected' : '' }}>ステータス: 全て</option>
            @foreach(\App\Enums\ProjectStatus::cases() as $st)
                <option value="{{ $st->value }}" {{ request('status') === $st->value ? 'selected' : '' }}>{{ $st->label() }}</option>
            @endforeach
        </select>
        <input type="text" name="keyword" value="{{ request('keyword') }}"
               placeholder="PJ名・所在地・PJ番号"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
        <a href="{{ route('realestate.projects.index') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
            クリア
        </a>
    </form>

    {{-- テーブル --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div style="overflow-x: auto;">
            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">PJ番号</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">ステータス</th>
                        <th class="py-2.5 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding-left: 16px;">プロジェクト名</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">購入価格</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">想定総販売価格</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">粗利見込み</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">区画数</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">マップ</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">区画</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">詳細</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($projects as $pj)
                        @php
                            $profit = $pj->getExpectedProfit();
                            $soldCount = $pj->getSoldLotCount();
                            $lotCount = $pj->lots->count();
                        @endphp
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                <a href="{{ route('realestate.projects.show', $pj) }}"
                                   class="text-sm font-semibold text-emerald-600 hover:text-emerald-700 hover:underline">{{ $pj->project_code }}</a>
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                <span class="badge {{ $pj->status->badgeClass() }}">{{ $pj->status->label() }}</span>
                            </td>
                            <td class="py-3 border-b border-gray-100 text-sm font-medium whitespace-nowrap" style="padding-left: 16px;">{{ $pj->project_name }}</td>
                            <td class="py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($pj->purchase_price)
                                    {{ number_format($pj->purchase_price) }}円
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($pj->target_selling_price)
                                    {{ number_format($pj->target_selling_price) }}円
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($profit !== null)
                                    <span class="text-emerald-600 font-semibold">{{ number_format($profit) }}円</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm text-center whitespace-nowrap">
                                @if($lotCount > 0)
                                    <span class="text-emerald-600 font-semibold">{{ $soldCount }}</span> / {{ $lotCount }}
                                @else
                                    0 / 0
                                @endif
                            </td>
                            {{-- マップボタン（青） --}}
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                @if($pj->latitude && $pj->longitude)
                                    <button type="button" onclick="openMapModal('{{ addslashes($pj->project_name) }}', '{{ addslashes($pj->address) }}', {{ $pj->latitude }}, {{ $pj->longitude }})"
                                            style="background: #fff; color: #2563eb; padding: 4px 12px; border-radius: 5px; font-size: 12px; font-weight: 600; border: 1px solid #2563eb; cursor: pointer; white-space: nowrap;">マップ</button>
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>
                            {{-- 区画ボタン（緑） --}}
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                <a href="{{ route('realestate.projects.lots', $pj) }}"
                                   style="display: inline-block; background: #fff; color: #059669; padding: 4px 12px; border-radius: 5px; font-size: 12px; font-weight: 600; border: 1px solid #059669; text-decoration: none; white-space: nowrap;">区画</a>
                            </td>
                            {{-- 詳細ボタン（濃い黄色） --}}
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                <a href="{{ route('realestate.projects.show', $pj) }}"
                                   style="display: inline-block; background: #fff; color: #b45309; padding: 4px 12px; border-radius: 5px; font-size: 12px; font-weight: 600; border: 1px solid #b45309; text-decoration: none; white-space: nowrap;">詳細</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-5 py-10 text-center text-sm text-gray-400">分譲地データがありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-4 py-2.5 border-t border-gray-200 text-sm text-gray-500">全 {{ $projects->total() }} 件</div>

        @if($projects->hasPages())
            <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
                @if($projects->onFirstPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                @else
                    <a href="{{ $projects->previousPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">&lt;</a>
                @endif
                @foreach($projects->getUrlRange(1, $projects->lastPage()) as $page => $url)
                    @if($page == $projects->currentPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">{{ $page }}</a>
                    @endif
                @endforeach
                @if($projects->hasMorePages())
                    <a href="{{ $projects->nextPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">&gt;</a>
                @else
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                @endif
            </div>
        @endif
    </div>

{{-- マップモーダル --}}
<div id="map-modal-overlay" onclick="if(event.target===this)closeMapModal()"
     style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
    <div style="background: #fff; border-radius: 10px; width: 90%; max-width: 700px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid #e5e7eb;">
            <div>
                <div id="modal-map-title" style="font-size: 15px; font-weight: 700;"></div>
                <div id="modal-map-address" style="font-size: 12px; color: #6b7280; margin-top: 2px;"></div>
            </div>
            <button onclick="closeMapModal()" style="background: none; border: none; font-size: 22px; color: #6b7280; cursor: pointer; padding: 0 4px; line-height: 1;">&times;</button>
        </div>
        <div id="modal-map-container" style="height: 400px;"></div>
    </div>
</div>

<script>
var modalMap = null;
var modalMarker = null;
var modalInfoWindow = null;

function openMapModal(name, address, lat, lng) {
    document.getElementById('modal-map-title').textContent = name;
    document.getElementById('modal-map-address').textContent = address;
    var overlay = document.getElementById('map-modal-overlay');
    overlay.style.display = 'flex';

    setTimeout(function() {
        if (!modalMap) {
            modalMap = new google.maps.Map(document.getElementById('modal-map-container'), {
                center: { lat: lat, lng: lng },
                zoom: 16,
                mapTypeControl: true,
                streetViewControl: true,
                fullscreenControl: false
            });
            modalMarker = new google.maps.Marker({ position: { lat: lat, lng: lng }, map: modalMap });
            modalInfoWindow = new google.maps.InfoWindow();
        } else {
            modalMap.setCenter({ lat: lat, lng: lng });
            modalMarker.setPosition({ lat: lat, lng: lng });
        }
        modalInfoWindow.setContent('<div style="font-size:13px;"><strong>' + name + '</strong><br>' + address + '</div>');
        modalInfoWindow.open(modalMap, modalMarker);
        google.maps.event.trigger(modalMap, 'resize');
    }, 150);
}

function closeMapModal() {
    document.getElementById('map-modal-overlay').style.display = 'none';
}
</script>
<script src="https://maps.googleapis.com/maps/api/js?key={{ env('GOOGLE_MAPS_API_KEY', '') }}&language=ja&region=JP" async defer></script>

{{-- プロジェクトステータスバッジCSS --}}
<style>
.badge-prj-info { background: #dbeafe; color: #1e40af; }
.badge-prj-assess { background: #fce7f3; color: #9d174d; }
.badge-prj-negotiate { background: #fed7aa; color: #9a3412; }
.badge-prj-contracted { background: #fef3c7; color: #92400e; }
.badge-prj-settled { background: #a7f3d0; color: #064e3b; }
.badge-prj-selling { background: #c7d2fe; color: #3730a3; }
.badge-prj-soldout { background: #86efac; color: #14532d; }
.badge-prj-lost { background: #e5e7eb; color: #374151; }
</style>

@endsection
