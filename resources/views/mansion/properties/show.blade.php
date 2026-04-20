@extends('layouts.app')

@section('title', $property->property_name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">物件一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $property->property_name }}</span>
@endsection

@section('content')

@php
    use App\Enums\MsRoomStatus;
    use App\Enums\MsParkingStatus;

    // ====== 部屋の稼働集計 ======
    $rooms = $property->rooms;
    $totalRooms = $rooms->count();
    $occupiedRooms = $rooms->where('status', MsRoomStatus::Occupied)->count();
    $vacantRooms = $rooms->where('status', MsRoomStatus::Vacant)->count();
    $negotiatingRooms = $rooms->where('status', MsRoomStatus::Negotiating)->count();
    $moveOutRooms = $rooms->where('status', MsRoomStatus::MoveOutPlanned)->count();
    $occupancyRate = $totalRooms > 0 ? round($occupiedRooms / $totalRooms * 100, 1) : 0;

    // ====== 駐車場の稼働集計 ======
    $parkings = $property->parkings;
    $totalParkings = $parkings->count();
    $occupiedParkings = $parkings->where('status', MsParkingStatus::Occupied)->count();
    $vacantParkings = $parkings->where('status', MsParkingStatus::Vacant)->count();
    $parkingRate = $totalParkings > 0 ? round($occupiedParkings / $totalParkings * 100, 1) : 0;

    // ====== 収支（契約中合計・月額） ======
    // 部屋契約（active のみ集計）
    $rentTotal = 0;
    $commonFeeTotal = 0;
    foreach ($rooms as $r) {
        if ($r->activeContract) {
            $rentTotal += (int) ($r->activeContract->rent ?? 0);
            $commonFeeTotal += (int) ($r->activeContract->common_fee ?? 0);
        }
    }
    // 駐車場契約（active のみ集計）
    $parkingFeeTotal = 0;
    foreach ($parkings as $p) {
        if ($p->activeContract) {
            $parkingFeeTotal += (int) ($p->activeContract->monthly_fee ?? 0);
        }
    }
    $monthlyTotal = $rentTotal + $commonFeeTotal + $parkingFeeTotal;

    // 築年月の表示（'YYYY-MM' → 'YYYY年M月'）
    $builtDisplay = null;
    if ($property->built_year_month) {
        $parts = explode('-', $property->built_year_month);
        if (count($parts) >= 2) {
            $builtDisplay = ((int) $parts[0]) . '年' . ((int) $parts[1]) . '月';
        }
    }
@endphp

<style>
    .ms-badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .ms-card-title { font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981; }
    .ms-info-label { font-size: 12px; color: #6b7280; margin-bottom: 4px; }
    .ms-info-value { font-size: 14px; color: #111827; font-weight: 500; }
    .ms-stat-card { background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 20px; text-align: center; }
    .ms-stat-label { font-size: 12px; color: #6b7280; margin-bottom: 6px; }
    .ms-stat-value { font-size: 24px; font-weight: 700; color: #111827; }
    .ms-stat-value-sub { font-size: 13px; color: #6b7280; margin-left: 4px; font-weight: 500; }
    .ms-btn-detail { display: inline-flex; align-items: center; justify-content: center; padding: 4px 12px; font-size: 11px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 4px; background: #fff; text-decoration: none; white-space: nowrap; transition: background-color .15s, color .15s; }
    .ms-btn-detail:hover { background: #059669; color: #fff; }
    .ms-btn-copy { display: inline-flex; align-items: center; justify-content: center; gap: 3px; padding: 4px 10px; font-size: 11px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 4px; background: #fff; text-decoration: none; white-space: nowrap; transition: background-color .15s, color .15s, border-color .15s; margin-left: 4px; }
    .ms-btn-copy:hover { background: #f3f4f6; color: #374151; border-color: #9ca3af; }
</style>

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; gap: 12px; flex-wrap: wrap;">
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <h1 style="font-size: 20px; font-weight: 700; margin: 0;">{{ $property->property_name }}</h1>
        <span class="ms-badge" style="{{ $property->ownership_type->badgeStyle() }}">{{ $property->ownership_type->label() }}</span>
        <span style="font-size: 13px; color: #6b7280;">{{ $property->property_code }}</span>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="{{ route('mansion.properties.index') }}"
           style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
            <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            一覧に戻る
        </a>
        @if(auth()->user()->role->isManagerOrAbove())
            <a href="{{ route('mansion.properties.edit', $property) }}"
               style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border: 1px solid #10b981; border-radius: 6px; background: white; font-size: 13px; color: #059669; font-weight: 600; text-decoration: none;">
                物件を編集
            </a>
            <a href="{{ route('mansion.rooms.create', $property) }}"
               style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #059669; border: 1px solid #059669; border-radius: 6px; color: white; font-size: 13px; font-weight: 600; text-decoration: none;">
                <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                部屋を追加
            </a>
        @endif
    </div>
</div>

{{-- ========== カード: 基本情報 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="ms-card-title">基本情報</div>
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px 24px;">
        @if($property->ownership_type === \App\Enums\MsOwnershipType::Managed && $property->owner_name)
            <div style="grid-column: span 4;">
                <div class="ms-info-label">オーナー</div>
                <div class="ms-info-value">{{ $property->owner_name }}</div>
            </div>
        @endif
        @if($property->postal_code)
            <div>
                <div class="ms-info-label">郵便番号</div>
                <div class="ms-info-value">{{ $property->postal_code }}</div>
            </div>
            <div style="grid-column: span 3;">
                <div class="ms-info-label">所在地</div>
                <div class="ms-info-value">{{ $property->address }}</div>
            </div>
        @else
            <div style="grid-column: span 4;">
                <div class="ms-info-label">所在地</div>
                <div class="ms-info-value">{{ $property->address }}</div>
            </div>
        @endif
        <div>
            <div class="ms-info-label">総戸数</div>
            <div class="ms-info-value">{{ $property->total_units ? $property->total_units . '戸' : '—' }}</div>
        </div>
        <div>
            <div class="ms-info-label">階数</div>
            <div class="ms-info-value">{{ $property->total_floors ? $property->total_floors . '階建て' : '—' }}</div>
        </div>
        <div>
            <div class="ms-info-label">築年月</div>
            <div class="ms-info-value">{{ $builtDisplay ?? '—' }}</div>
        </div>
        <div>
            <div class="ms-info-label">構造</div>
            <div class="ms-info-value">{{ $property->structure ?? '—' }}</div>
        </div>
        @if($property->notes)
            <div style="grid-column: span 4;">
                <div class="ms-info-label">備考</div>
                <div class="ms-info-value" style="white-space: pre-wrap;">{{ $property->notes }}</div>
            </div>
        @endif
    </div>
</div>

{{-- ========== カード: 稼働状況 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="ms-card-title">稼働状況</div>

    {{-- 部屋 --}}
    <div style="font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 8px;">部屋（{{ $totalRooms }}戸）</div>
    <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px;">
        <div class="ms-stat-card">
            <div class="ms-stat-label">総戸数</div>
            <div class="ms-stat-value">{{ $totalRooms }}<span class="ms-stat-value-sub">戸</span></div>
        </div>
        <div class="ms-stat-card" style="background: #ecfdf5; border-color: #a7f3d0;">
            <div class="ms-stat-label" style="color: #065f46;">入居中</div>
            <div class="ms-stat-value" style="color: #065f46;">{{ $occupiedRooms }}<span class="ms-stat-value-sub" style="color: #065f46;">戸</span></div>
        </div>
        <div class="ms-stat-card" style="background: #eff6ff; border-color: #bfdbfe;">
            <div class="ms-stat-label" style="color: #1e40af;">空室</div>
            <div class="ms-stat-value" style="color: #1e40af;">{{ $vacantRooms }}<span class="ms-stat-value-sub" style="color: #1e40af;">戸</span></div>
        </div>
        <div class="ms-stat-card" style="background: #fff7ed; border-color: #fed7aa;">
            <div class="ms-stat-label" style="color: #9a3412;">申込み・仮押え</div>
            <div class="ms-stat-value" style="color: #9a3412;">{{ $negotiatingRooms }}<span class="ms-stat-value-sub" style="color: #9a3412;">戸</span></div>
        </div>
        <div class="ms-stat-card" style="background: #fdf2f8; border-color: #fbcfe8;">
            <div class="ms-stat-label" style="color: #9d174d;">退去予定</div>
            <div class="ms-stat-value" style="color: #9d174d;">{{ $moveOutRooms }}<span class="ms-stat-value-sub" style="color: #9d174d;">戸</span></div>
        </div>
    </div>
    <div style="margin-top: 14px; padding: 10px 14px; background: #f9fafb; border-radius: 6px; font-size: 13px; color: #374151;">
        <strong>入居率</strong>：<span style="font-size: 16px; font-weight: 700; color: #059669; margin-left: 6px;">{{ number_format($occupancyRate, 1) }}%</span>
        <span style="color: #6b7280; margin-left: 6px;">（入居{{ $occupiedRooms }}戸 ÷ 総戸数{{ $totalRooms }}戸）</span>
    </div>

    {{-- 駐車場（コンパクト表示） --}}
    @if($totalParkings > 0)
        <div style="margin-top: 18px; padding-top: 14px; border-top: 1px dashed #e5e7eb;">
            <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 20px; font-size: 13px; color: #6b7280;">
                <span style="font-size: 12px; font-weight: 600; color: #6b7280;">駐車場（{{ $totalParkings }}台）</span>
                <span>使用中 <strong style="color: #059669; font-size: 14px;">{{ $occupiedParkings }}</strong>台</span>
                <span>空き <strong style="color: #1e40af; font-size: 14px;">{{ $vacantParkings }}</strong>台</span>
                <span style="margin-left: auto;">稼働率 <strong style="color: #059669; font-size: 14px;">{{ number_format($parkingRate, 1) }}%</strong></span>
            </div>
        </div>
    @endif
</div>

{{-- ========== カード: 収支状況 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="ms-card-title">収支状況（契約中合計・月額）</div>
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;">
        <div class="ms-stat-card">
            <div class="ms-stat-label">賃料収入</div>
            <div class="ms-stat-value" style="font-size: 20px;">{{ number_format($rentTotal) }}<span class="ms-stat-value-sub">円</span></div>
        </div>
        <div class="ms-stat-card">
            <div class="ms-stat-label">共益費</div>
            <div class="ms-stat-value" style="font-size: 20px;">{{ number_format($commonFeeTotal) }}<span class="ms-stat-value-sub">円</span></div>
        </div>
        <div class="ms-stat-card">
            <div class="ms-stat-label">駐車場料</div>
            <div class="ms-stat-value" style="font-size: 20px;">{{ number_format($parkingFeeTotal) }}<span class="ms-stat-value-sub">円</span></div>
        </div>
        <div class="ms-stat-card" style="background: #ecfdf5; border-color: #a7f3d0;">
            <div class="ms-stat-label" style="color: #065f46;">月額合計</div>
            <div class="ms-stat-value" style="font-size: 20px; color: #065f46;">{{ number_format($monthlyTotal) }}<span class="ms-stat-value-sub" style="color: #065f46;">円</span></div>
        </div>
    </div>
</div>

{{-- ========== カード: 部屋一覧 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 8px;">
        <div class="ms-card-title" style="margin-bottom: 0;">部屋一覧（{{ $totalRooms }}戸）</div>
        @if(auth()->user()->role->isManagerOrAbove())
            <a href="{{ route('mansion.rooms.create', $property) }}"
               style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; font-size: 12px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 4px; background: white; text-decoration: none;">
                <svg style="width: 12px; height: 12px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                部屋を追加
            </a>
        @endif
    </div>

    @if($totalRooms === 0)
        <div style="padding: 32px; text-align: center; color: #9ca3af; font-size: 13px;">部屋が登録されていません。</div>
    @else
        <table class="w-full" style="border-collapse: collapse;">
            <colgroup>
                <col style="width: 10%">
                <col style="width: 8%">
                <col style="width: 12%">
                <col style="width: 12%">
                <col style="width: 14%">
                <col style="width: 14%">
                <col style="width: 16%">
                <col style="width: 14%">
            </colgroup>
            <thead>
                <tr style="background: #f9fafb;">
                    <th style="padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">号室</th>
                    <th style="padding: 10px 12px; text-align: center; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">階</th>
                    <th style="padding: 10px 12px; text-align: center; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">間取り</th>
                    <th style="padding: 10px 12px; text-align: center; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">面積</th>
                    <th style="padding: 10px 12px; text-align: right; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">賃料</th>
                    <th style="padding: 10px 12px; text-align: center; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">ステータス</th>
                    <th style="padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">入居者</th>
                    <th style="padding: 10px 12px; text-align: center; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rooms->sortByDesc('floor')->values() as $room)
                    @php
                        $tenantName = $room->activeContract?->tenant?->name;
                    @endphp
                    <tr>
                        <td style="padding: 10px 12px; font-size: 14px; font-weight: 600; border-bottom: 1px solid #f3f4f6;">
                            @if(auth()->user()->role->isManagerOrAbove())
                                <a href="{{ route('mansion.rooms.edit', $room) }}" style="color: #059669; text-decoration: none;">{{ $room->room_number }}</a>
                            @else
                                <span style="color: #111827;">{{ $room->room_number }}</span>
                            @endif
                        </td>
                        <td style="padding: 10px 12px; text-align: center; font-size: 13px; color: #374151; border-bottom: 1px solid #f3f4f6;">{{ $room->floor ? $room->floor . 'F' : '—' }}</td>
                        <td style="padding: 10px 12px; text-align: center; font-size: 13px; color: #374151; border-bottom: 1px solid #f3f4f6;">{{ $room->room_type ?? '—' }}</td>
                        <td style="padding: 10px 12px; text-align: center; font-size: 13px; color: #374151; border-bottom: 1px solid #f3f4f6;">{{ $room->area_sqm ? number_format((float) $room->area_sqm, 2) . '㎡' : '—' }}</td>
                        <td style="padding: 10px 12px; text-align: right; font-size: 13px; color: #111827; font-weight: 500; border-bottom: 1px solid #f3f4f6;">{{ $room->rent ? number_format($room->rent) . '円' : '—' }}</td>
                        <td style="padding: 10px 12px; text-align: center; border-bottom: 1px solid #f3f4f6;">
                            <span class="ms-badge" style="{{ $room->status->badgeStyle() }}">{{ $room->status->label() }}</span>
                        </td>
                        <td style="padding: 10px 12px; font-size: 13px; color: {{ $tenantName ? '#374151' : '#9ca3af' }}; border-bottom: 1px solid #f3f4f6;">
                            {{ $tenantName ?? '—' }}
                        </td>
                        <td style="padding: 10px 12px; text-align: center; border-bottom: 1px solid #f3f4f6;">
                            @if(auth()->user()->role->isManagerOrAbove())
                                <a href="{{ route('mansion.rooms.edit', $room) }}" class="ms-btn-detail">編集</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

{{-- ========== カード: 駐車場一覧 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 8px;">
        <div class="ms-card-title" style="margin-bottom: 0;">駐車場一覧（{{ $totalParkings }}台）</div>
        @if(auth()->user()->role->isManagerOrAbove())
            <a href="#"
               style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; font-size: 12px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 4px; background: white; text-decoration: none;">
                <svg style="width: 12px; height: 12px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                駐車場を追加
            </a>
        @endif
    </div>

    @if($totalParkings === 0)
        <div style="padding: 32px; text-align: center; color: #9ca3af; font-size: 13px;">駐車場が登録されていません。</div>
    @else
        <table class="w-full" style="border-collapse: collapse;">
            <colgroup>
                <col style="width: 13%">
                <col style="width: 5%">
                <col style="width: 16%">
                <col style="width: 16%">
                <col style="width: 16%">
                <col style="width: 20%">
                <col style="width: 14%">
            </colgroup>
            <thead>
                <tr style="background: #f9fafb;">
                    <th style="padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">駐車場番号</th>
                    <th style="padding: 10px 12px; text-align: center; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">屋根</th>
                    <th style="padding: 10px 12px; text-align: right; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">月額料金</th>
                    <th style="padding: 10px 12px; text-align: center; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">ステータス</th>
                    <th style="padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">利用者</th>
                    <th style="padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">紐付け</th>
                    <th style="padding: 10px 12px; text-align: center; font-size: 12px; font-weight: 700; color: #4b5563; border-bottom: 1px solid #e5e7eb;">操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach($parkings as $parking)
                    @php
                        $parkingTenantName = $parking->activeContract?->tenant?->name;
                        // 部屋契約と紐付いているか（contract_id が設定されていれば連動）
                        $linkedRoomId = $parking->activeContract?->contract?->room_id ?? null;
                        $linkedRoomNumber = null;
                        if ($linkedRoomId) {
                            $linkedRoom = $rooms->firstWhere('id', $linkedRoomId);
                            $linkedRoomNumber = $linkedRoom?->room_number;
                        }
                        $linkLabel = $linkedRoomNumber ? ($linkedRoomNumber . '号室契約と連動') : ($parking->activeContract ? '単独契約' : null);
                    @endphp
                    <tr>
                        <td style="padding: 10px 12px; font-size: 14px; font-weight: 600; border-bottom: 1px solid #f3f4f6;">
                            <a href="#" style="color: #059669; text-decoration: none;">{{ $parking->parking_number }}</a>
                        </td>
                        <td style="padding: 10px 12px; text-align: center; font-size: 13px; color: {{ $parking->has_roof ? '#374151' : '#9ca3af' }}; border-bottom: 1px solid #f3f4f6;">
                            {{ $parking->has_roof ? '○' : '—' }}
                        </td>
                        <td style="padding: 10px 12px; text-align: right; font-size: 13px; color: #111827; font-weight: 500; border-bottom: 1px solid #f3f4f6;">
                            {{ $parking->monthly_fee ? number_format($parking->monthly_fee) . '円' : '—' }}
                        </td>
                        <td style="padding: 10px 12px; text-align: center; border-bottom: 1px solid #f3f4f6;">
                            <span class="ms-badge" style="{{ $parking->status->badgeStyle() }}">{{ $parking->status->label() }}</span>
                        </td>
                        <td style="padding: 10px 12px; font-size: 13px; color: {{ $parkingTenantName ? '#374151' : '#9ca3af' }}; border-bottom: 1px solid #f3f4f6;">
                            {{ $parkingTenantName ?? '—' }}
                        </td>
                        <td style="padding: 10px 12px; font-size: 12px; color: {{ $linkLabel ? '#6b7280' : '#9ca3af' }}; border-bottom: 1px solid #f3f4f6;">
                            {{ $linkLabel ?? '—' }}
                        </td>
                        <td style="padding: 10px 12px; text-align: center; border-bottom: 1px solid #f3f4f6;">
                            <a href="#" class="ms-btn-detail">詳細</a>
                            @if(auth()->user()->role->isManagerOrAbove())
                                <a href="#" class="ms-btn-copy" title="この駐車場と同条件で新規登録">複製</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

{{-- 削除ゾーン（経営層のみ） --}}
@if(auth()->user()->role->isExecutive())
    <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
        <div>
            <div style="font-size: 13px; font-weight: 600; color: #991b1b;">物件を削除</div>
            <div style="font-size: 11px; color: #b91c1c; margin-top: 2px;">契約中の部屋または駐車場がある場合は削除できません。</div>
        </div>
        <form method="POST" action="{{ route('mansion.properties.destroy', $property) }}"
              onsubmit="return confirm('本当にこの物件を削除しますか？部屋・駐車場も連動削除されます。');">
            @csrf
            @method('DELETE')
            <button type="submit"
                    style="padding: 8px 16px; background: white; border: 1px solid #dc2626; border-radius: 6px; color: #dc2626; font-size: 13px; font-weight: 600; cursor: pointer;">
                削除する
            </button>
        </form>
    </div>
@endif

@endsection
