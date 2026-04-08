@php
    /**
     * フロアマップ用 区画カード（v7 確定版）
     * @var \App\Models\Unit $unit
     *
     * 表示ルール:
     * - 入居中 → 店舗名(store_name) + 契約条件の費用、ステータスラベル非表示
     * - 空室/商談中 → ステータスラベル（店舗名の位置） + 募集条件の費用
     * - 坪数: 区画名の横にラベルバッジ（小数点第二位まで）
     * - 坪単価: 家賃・共益費の金額行の下に (@金額) 右寄せ（切り上げ）
     * - ゴミ代・駆除代: 0円でも常時表示（横の縦揃えのため）
     * - 賃料計 = 家賃 + 共益費 + ゴミ代 + 駆除代（敷金含まない）
     * - 全行固定高さで横の縦揃えを統一
     * - 投資バッジ: 工事中→「工事中」/ 回収中→「投資回収中XX%」
     */

    $isOccupied = $unit->status === \App\Enums\UnitStatus::Occupied;
    $isVacant = $unit->status === \App\Enums\UnitStatus::Vacant;
    $isNegotiating = $unit->status === \App\Enums\UnitStatus::Negotiating;

    $activeContract = $unit->activeContract;

    if ($isOccupied && $activeContract) {
        $rent = $activeContract->rent ?? 0;
        $commonFee = $activeContract->common_fee ?? 0;
        $garbageFee = $activeContract->garbage_fee ?? 0;
        $pestControlFee = $activeContract->pest_control_fee ?? 0;
        $deposit = $activeContract->deposit ?? 0;
        $storeName = $activeContract->store_name;
    } else {
        $rent = $unit->rent ?? 0;
        $commonFee = $unit->common_fee ?? 0;
        $garbageFee = $unit->garbage_fee ?? 0;
        $pestControlFee = $unit->pest_control_fee ?? 0;
        $deposit = $unit->deposit ?? 0;
        $storeName = null;
    }

    // 賃料計（家賃 + 共益費 + ゴミ代 + 駆除代）
    $rentalTotal = $rent + $commonFee + $garbageFee + $pestControlFee;

    // 坪数・坪単価
    $areaTsubo = $unit->area_tsubo;
    $hasTsubo = $areaTsubo !== null && (float) $areaTsubo > 0;
    $rentPerTsubo = $hasTsubo ? (int) ceil($rent / (float) $areaTsubo) : null;
    $commonFeePerTsubo = $hasTsubo ? (int) ceil($commonFee / (float) $areaTsubo) : null;

    // 投資バッジ
    $investmentBadge = null;
    $investmentBadgeClass = 'fm-invest';
    if ($unit->relationLoaded('investments')) {
        $activeInvestment = $unit->investments->first();
        if ($activeInvestment) {
            $badgeText = $activeInvestment->status->floorMapBadge($activeInvestment->recovery_rate);
            if ($badgeText) {
                $investmentBadge = $badgeText;
                if ($activeInvestment->status === \App\Enums\InvestmentStatus::InProgress) {
                    $investmentBadgeClass = 'fm-invest fm-invest-wip';
                }
            }
        }
    }

    // 背景色
    $bgClass = match(true) {
        $isOccupied => 'bg-blue-50/70',
        $isNegotiating => 'bg-yellow-50/70',
        default => 'bg-gray-50/50',
    };
@endphp

<a href="{{ route('tenant.units.show', $unit) }}" class="fm-unit {{ $bgClass }} {{ (isset($isLastFloorLastUnit) && $isLastFloorLastUnit) ? 'last-unit' : '' }}" style="text-decoration:none;color:inherit;display:block">

    {{-- 行1: 区画名 + 坪数ラベル + 投資バッジ（固定22px） --}}
    <div class="fm-ut">
        <span class="fm-un">{{ $unit->display_name }}</span>
        @if($hasTsubo)
            <span class="fm-tsubo">{{ number_format((float) $areaTsubo, 2) }}坪</span>
        @endif
        @if($investmentBadge)
            <span class="{{ $investmentBadgeClass }}">{{ $investmentBadge }}</span>
        @endif
    </div>

    {{-- 行2: 店舗名 or ステータスラベル（固定26px） --}}
    <div class="fm-name">
        @if($isOccupied && $storeName)
            <span class="fm-store">{{ $storeName }}</span>
        @elseif($isVacant)
            <span class="fm-status fm-status-vacant">空室</span>
        @elseif($isNegotiating)
            <span class="fm-status fm-status-neg">商談中</span>
        @endif
    </div>

    {{-- 費用明細（全行固定高さ） --}}
    <div class="fm-fees">
        {{-- 家賃（22px）--}}
        <div class="fm-r"><span class="fm-l">家賃</span><span class="fm-v">¥{{ number_format($rent) }}</span></div>
        {{-- 家賃坪単価（16px）--}}
        @if($hasTsubo)
            <div class="fm-tp"><span>({{ '@' . number_format($rentPerTsubo) }})</span></div>
        @else
            <div class="fm-tp"></div>
        @endif

        {{-- 共益費（22px）--}}
        <div class="fm-r"><span class="fm-l">共益費</span><span class="fm-v">¥{{ number_format($commonFee) }}</span></div>
        {{-- 共益費坪単価（16px）--}}
        @if($hasTsubo)
            <div class="fm-tp"><span>({{ '@' . number_format($commonFeePerTsubo) }})</span></div>
        @else
            <div class="fm-tp"></div>
        @endif

        {{-- ゴミ代（22px）— 0円でも表示 --}}
        <div class="fm-r"><span class="fm-l">ゴミ代</span><span class="fm-v">¥{{ number_format($garbageFee) }}</span></div>

        {{-- 駆除代（22px）— 0円でも表示 --}}
        <div class="fm-r"><span class="fm-l">駆除代</span><span class="fm-v">¥{{ number_format($pestControlFee) }}</span></div>

        {{-- 賃料計（28px）--}}
        <div class="fm-total"><span class="fm-l">賃料計</span><span class="fm-v">¥{{ number_format($rentalTotal) }}</span></div>

        {{-- 敷金（28px）--}}
        <div class="fm-dep"><span class="fm-l">敷金</span><span class="fm-v">¥{{ number_format($deposit) }}</span></div>
    </div>

</a>
