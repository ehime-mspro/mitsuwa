@extends('layouts.app')

@section('title', 'マンションダッシュボード')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">ダッシュボード</span>
@endsection

@section('content')

{{-- ダッシュボード専用スタイル（Vite 未ビルドのためインラインで定義） --}}
<style>
    /* セクション見出し */
    .ms-section-title {
        font-size: 14px;
        font-weight: 700;
        color: #111827;
        margin-bottom: 12px;
        padding-left: 12px;
        border-left: 4px solid #10b981;
    }

    /* KPI グリッド */
    .ms-kpi-grid {
        display: grid;
        gap: 14px;
        margin-bottom: 24px;
        grid-template-columns: repeat(5, 1fr);
    }

    /* KPI カード */
    .ms-kpi-card {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 18px 18px 16px;
        position: relative;
        overflow: hidden;
    }
    .ms-kpi-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0;
        width: 4px; height: 100%;
        background: #e5e7eb;
    }
    .ms-kpi-card.accent-total::before      { background: #6b7280; }
    .ms-kpi-card.accent-occupied::before   { background: #10b981; }
    .ms-kpi-card.accent-vacant::before     { background: #3b82f6; }
    .ms-kpi-card.accent-negotiating::before { background: #f97316; }
    .ms-kpi-card.accent-move-out::before   { background: #ec4899; }

    .ms-kpi-label {
        font-size: 12px;
        color: #6b7280;
        font-weight: 600;
        margin-bottom: 8px;
    }
    .ms-kpi-value {
        font-size: 26px;
        font-weight: 700;
        color: #111827;
        line-height: 1.2;
    }
    .ms-kpi-value .unit {
        font-size: 13px;
        font-weight: 600;
        color: #6b7280;
        margin-left: 3px;
    }
    .ms-kpi-sub {
        font-size: 11px;
        color: #9ca3af;
        margin-top: 6px;
    }
    .ms-kpi-sub b { color: #047857; font-weight: 700; }

    /* 入居率バー */
    .ms-occupancy-bar {
        width: 100%; height: 6px;
        background: #e5e7eb;
        border-radius: 999px;
        overflow: hidden;
        margin-top: 10px;
    }
    .ms-occupancy-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, #10b981, #059669);
        border-radius: 999px;
    }

    /* データカード */
    .ms-data-card {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 18px 20px;
        margin-bottom: 20px;
    }
    .ms-data-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 14px;
    }
    .ms-data-card-title {
        font-size: 14px;
        font-weight: 700;
        color: #111827;
        padding-left: 12px;
        border-left: 4px solid #10b981;
    }
    .ms-data-card-count {
        font-size: 12px;
        color: #6b7280;
    }
    .ms-data-card-count b {
        font-size: 14px;
        color: #047857;
        font-weight: 700;
    }

    /* ダッシュボードテーブル */
    .ms-dash-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }
    .ms-dash-table thead th {
        background: #f9fafb;
        color: #374151;
        font-weight: 700;
        font-size: 12px;
        text-align: left;
        padding: 10px 12px;
        border-bottom: 1px solid #e5e7eb;
        white-space: nowrap;
    }
    .ms-dash-table thead th.num    { text-align: right; }
    .ms-dash-table thead th.center { text-align: center; }
    .ms-dash-table tbody td {
        padding: 10px 12px;
        border-bottom: 1px solid #f3f4f6;
        color: #374151;
        font-size: 13px;
    }
    .ms-dash-table tbody td.num    { text-align: right; font-variant-numeric: tabular-nums; }
    .ms-dash-table tbody td.center { text-align: center; }
    .ms-dash-table tbody tr:hover  { background: #fafafa; }
    .ms-dash-table tbody tr:last-child td { border-bottom: none; }
    .ms-dash-table .muted { color: #9ca3af; }

    /* バッジ */
    .ms-dash-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 99px;
        font-size: 11px;
        font-weight: 700;
    }

    /* 詳細ボタン */
    .ms-btn-detail {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 10px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        background: white;
        font-size: 11px;
        color: #374151;
        text-decoration: none;
        font-weight: 600;
    }
    .ms-btn-detail:hover {
        background: #ecfdf5;
        border-color: #a7f3d0;
        color: #047857;
    }

    /* 物件名リンク */
    .ms-prop-name {
        color: #047857;
        text-decoration: none;
        font-weight: 600;
    }
    .ms-prop-name:hover { text-decoration: underline; }

    /* 2カラムレイアウト（空室と空き駐車場） */
    .ms-two-col {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }

    /* レスポンシブ: 小画面では1カラム */
    @media (max-width: 900px) {
        .ms-kpi-grid { grid-template-columns: repeat(2, 1fr); }
        .ms-two-col  { grid-template-columns: 1fr; }
    }
</style>

{{-- ページヘッダー --}}
<div style="display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 24px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; margin: 0 0 4px;">賃貸マンションダッシュボード</h1>
        <div style="font-size: 12px; color: #6b7280;">{{ now()->format('Y年n月j日') }} 時点のスナップショット</div>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="{{ route('mansion.properties.index') }}"
           style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
            <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            </svg>
            物件一覧
        </a>
        <a href="{{ route('mansion.contracts.create') }}"
           style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: #059669; border: 1px solid #059669; border-radius: 6px; color: white; font-size: 13px; font-weight: 600; text-decoration: none;">
            <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            新規契約
        </a>
    </div>
</div>

{{-- ========== 稼働サマリー: 部屋 KPI 5枚 ========== --}}
<div class="ms-section-title">部屋の稼働状況（全物件合計）</div>
<div class="ms-kpi-grid">

    {{-- 総戸数 + 入居率バー --}}
    <div class="ms-kpi-card accent-total">
        <div class="ms-kpi-label">総戸数</div>
        <div class="ms-kpi-value">{{ $totalRooms }}<span class="unit">戸</span></div>
        <div class="ms-occupancy-bar">
            <div class="ms-occupancy-bar-fill" style="width: {{ $occupancyRate }}%;"></div>
        </div>
        <div class="ms-kpi-sub">入居率 <b>{{ number_format($occupancyRate, 1) }}%</b></div>
    </div>

    {{-- 入居中 --}}
    <div class="ms-kpi-card accent-occupied">
        <div class="ms-kpi-label">入居中</div>
        <div class="ms-kpi-value">{{ $occupiedRooms }}<span class="unit">戸</span></div>
        <div class="ms-kpi-sub">稼働中の部屋</div>
    </div>

    {{-- 空室 --}}
    <div class="ms-kpi-card accent-vacant">
        <div class="ms-kpi-label">空室</div>
        <div class="ms-kpi-value">{{ $vacantRooms }}<span class="unit">戸</span></div>
        <div class="ms-kpi-sub">募集対象</div>
    </div>

    {{-- 申込み・仮押え --}}
    <div class="ms-kpi-card accent-negotiating">
        <div class="ms-kpi-label">申込み・仮押え</div>
        <div class="ms-kpi-value">{{ $negotiatingRooms }}<span class="unit">戸</span></div>
        <div class="ms-kpi-sub">契約手続き中</div>
    </div>

    {{-- 退去予定 --}}
    <div class="ms-kpi-card accent-move-out">
        <div class="ms-kpi-label">退去予定</div>
        <div class="ms-kpi-value">{{ $moveOutPlanned }}<span class="unit">戸</span></div>
        <div class="ms-kpi-sub">退去処理待ち</div>
    </div>

</div>

{{-- ========== 物件別 稼働状況テーブル ========== --}}
<div class="ms-data-card">
    <div class="ms-data-card-header">
        <div class="ms-data-card-title">物件別 稼働状況</div>
        <div class="ms-data-card-count">全 <b>{{ $properties->count() }}</b> 物件</div>
    </div>

    @if($properties->isEmpty())
        <p style="text-align: center; color: #9ca3af; font-size: 13px; padding: 24px 0;">物件がまだ登録されていません。</p>
    @else
        <table class="ms-dash-table">
            <thead>
                <tr>
                    <th>物件名</th>
                    <th class="center">所有形態</th>
                    <th class="num">総戸数</th>
                    <th class="num">入居中</th>
                    <th class="num">空室</th>
                    <th class="num">入居率</th>
                    <th class="num" style="min-width: 110px;">駐車場 使用中 / 総数</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($properties as $property)
                    @php
                        // 駐車場の集計
                        $totalParkings    = $property->parkings->count();
                        $occupiedParkings = $property->parkings->where('status', \App\Enums\MsParkingStatus::Occupied)->count();
                        // 空室数（物件単位）
                        $vacantCount = $property->total_rooms - $property->occupied_rooms;
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('mansion.properties.show', $property) }}" class="ms-prop-name">
                                {{ $property->property_name }}
                            </a>
                        </td>
                        <td class="center">
                            <span class="ms-dash-badge" style="{{ $property->ownership_type->badgeStyle() }}">
                                {{ $property->ownership_type->label() }}
                            </span>
                        </td>
                        <td class="num">{{ $property->total_rooms }}戸</td>
                        <td class="num">{{ $property->occupied_rooms }}戸</td>
                        <td class="num">{{ $vacantCount }}戸</td>
                        <td class="num">
                            <b style="color: #047857;">{{ number_format($property->occupancy, 1) }}%</b>
                        </td>
                        <td class="num">
                            @if($totalParkings > 0)
                                {{ $occupiedParkings }} / {{ $totalParkings }} 台
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                        <td class="center">
                            <a href="{{ route('mansion.properties.show', $property) }}" class="ms-btn-detail">詳細</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

{{-- ========== 下段: 空室 + 空き駐車場（2カラム） ========== --}}
<div class="ms-two-col">

    {{-- ===== 空室一覧 ===== --}}
    <div class="ms-data-card" style="margin-bottom: 0;">
        <div class="ms-data-card-header">
            <div class="ms-data-card-title">空室・申込み中</div>
            <div class="ms-data-card-count">合計 <b>{{ $vacantList->count() }}</b> 戸</div>
        </div>

        @if($vacantList->isEmpty())
            <p style="text-align: center; color: #9ca3af; font-size: 13px; padding: 24px 0;">空室はありません。</p>
        @else
            <table class="ms-dash-table">
                <thead>
                    <tr>
                        <th>物件</th>
                        <th>号室</th>
                        <th>間取り</th>
                        <th class="num">賃料</th>
                        <th class="center">状態</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($vacantList as $room)
                        <tr>
                            <td>
                                <a href="{{ route('mansion.properties.show', $room->property) }}" class="ms-prop-name">
                                    {{ $room->property->property_name }}
                                </a>
                            </td>
                            <td class="whitespace-nowrap">{{ $room->room_number }}号室</td>
                            <td class="whitespace-nowrap">
                                {{ $room->room_type ?? '—' }}
                                @if($room->area_sqm)
                                    / {{ $room->area_sqm }}㎡
                                @endif
                            </td>
                            <td class="num whitespace-nowrap">
                                @if($room->rent)
                                    {{ number_format($room->rent) }}円
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td class="center">
                                <span class="ms-dash-badge" style="{{ $room->status->badgeStyle() }}">
                                    {{ $room->status->label() }}
                                </span>
                            </td>
                            <td class="center">
                                <a href="{{ route('mansion.contracts.create') }}?room_id={{ $room->id }}" class="ms-btn-detail">契約登録</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- ===== 空き駐車場一覧 ===== --}}
    <div class="ms-data-card" style="margin-bottom: 0;">
        @php
            // 全物件の駐車場集計（$properties から算出）
            $totalParkingAll    = 0;
            $occupiedParkingAll = 0;
            foreach ($properties as $prop) {
                $totalParkingAll    += $prop->parkings->count();
                $occupiedParkingAll += $prop->parkings->where('status', \App\Enums\MsParkingStatus::Occupied)->count();
            }
            $parkingRate = $totalParkingAll > 0 ? round($occupiedParkingAll / $totalParkingAll * 100, 1) : 0;
        @endphp
        <div class="ms-data-card-header" style="flex-wrap: wrap; row-gap: 8px;">
            <div class="ms-data-card-title">空き駐車場</div>
            <div style="font-size: 11px; color: #6b7280; display: flex; flex-wrap: wrap; gap: 4px 14px; align-items: center;">
                <span>総台数 <b style="color: #111827; font-weight: 700;">{{ $totalParkingAll }}台</b></span>
                <span>使用中 <b style="color: #047857; font-weight: 700;">{{ $occupiedParkingAll }}台</b></span>
                <span>空き <b style="color: #1e40af; font-weight: 700;">{{ $vacantParkings->count() }}台</b></span>
                <span>稼働率 <b style="color: #047857; font-weight: 700;">{{ number_format($parkingRate, 1) }}%</b></span>
            </div>
        </div>

        @if($vacantParkings->isEmpty())
            <p style="text-align: center; color: #9ca3af; font-size: 13px; padding: 24px 0;">空き駐車場はありません。</p>
        @else
            <table class="ms-dash-table">
                <thead>
                    <tr>
                        <th>物件</th>
                        <th>番号</th>
                        <th>区分</th>
                        <th class="num">月額料金</th>
                        <th class="center">状態</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($vacantParkings as $parking)
                        @php
                            // 屋根区分の表示文字列を生成
                            $roofLabel = $parking->has_roof ? '屋根あり' : '屋根なし';
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('mansion.properties.show', $parking->property) }}" class="ms-prop-name">
                                    {{ $parking->property->property_name }}
                                </a>
                            </td>
                            <td class="whitespace-nowrap">{{ $parking->parking_number }}</td>
                            <td class="whitespace-nowrap">{{ $roofLabel }}</td>
                            <td class="num whitespace-nowrap">
                                @if($parking->monthly_fee)
                                    {{ number_format($parking->monthly_fee) }}円
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td class="center">
                                <span class="ms-dash-badge" style="{{ $parking->status->badgeStyle() }}">
                                    {{ $parking->status->label() }}
                                </span>
                            </td>
                            <td class="center">
                                <a href="{{ route('mansion.parking-contracts.create') }}?parking_id={{ $parking->id }}" class="ms-btn-detail">契約登録</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</div>

@endsection
