@extends('layouts.app')

@section('title', ($hsCustomOrder->order_name ?? '注文住宅契約') . ' — 契約詳細')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.contracts.index') }}" class="hover:text-emerald-600 transition-colors">契約管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $hsCustomOrder->order_name ?? '—' }}</span>
@endsection

@section('content')

<style>
    /* 注文住宅詳細ページ専用スタイル（Viteビルド外のTailwindクラスを使わずインラインCSSで定義） */
    .hc-card { background: #fff; border: 1px solid #E5E7EB; border-radius: 8px; margin-bottom: 20px; }
    .hc-card-body { padding: 20px; }
    .hc-section-title { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; }
    .hc-section-title .bar { width: 3px; height: 20px; background: #059669; border-radius: 2px; }
    .hc-section-title h2 { font-size: 15px; font-weight: 700; color: #111827; margin: 0; }

    .hc-summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
    .hc-mini-card { background: #fff; border: 1px solid #E5E7EB; border-radius: 8px; padding: 16px; }
    .hc-mini-card .label { font-size: 12px; color: #6B7280; margin-bottom: 4px; }
    .hc-mini-card .value { font-size: 18px; font-weight: 700; color: #111827; }
    .hc-mini-card .value.profit { color: #047857; font-weight: 700; }

    .hc-dl-grid {
        display: grid;
        grid-template-columns: 160px 1fr 160px 1fr;
        border: 1px solid #E5E7EB;
        border-radius: 6px;
        overflow: hidden;
    }
    .hc-dl-grid dt {
        background: #F9FAFB;
        padding: 10px 14px;
        font-size: 13px; color: #6B7280; font-weight: 500;
        border-bottom: 1px solid #E5E7EB;
        border-right: 1px solid #E5E7EB;
    }
    .hc-dl-grid dd {
        padding: 10px 14px;
        font-size: 13px; color: #111827;
        border-bottom: 1px solid #E5E7EB;
        margin: 0;
    }
    .hc-dl-grid .no-border-bottom { border-bottom: none; }
    .hc-dl-grid .profit-cell { color: #047857; font-weight: 700; }
    .hc-dl-grid .sub-rate { font-size: 12px; color: #374151; font-weight: 600; margin-left: 4px; }
    .hc-dl-grid .muted { color: #9CA3AF; font-weight: 400; }
    .hc-dl-grid .span3 { grid-column: span 3; }
    .hc-dl-grid .bold-label { font-weight: 700; color: #111827; }
    .hc-dl-grid .bold-value { font-weight: 700; }

    /* ヘッダーボタン共通 */
    .hc-btn {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 6px 16px;
        font-size: 13px; font-weight: 600;
        border-radius: 6px; text-decoration: none; cursor: pointer;
        background: #fff;
    }
    .hc-btn-outline { color: #059669; border: 1px solid #059669; }
    .hc-btn-outline:hover { background: #ECFDF5; }
    .hc-btn-gray    { color: #6B7280; border: 1px solid #D1D5DB; }
    .hc-btn-gray:hover { background: #F9FAFB; }

    /* モバイル: 4 列サマリーは 2 列へ、定義リストはラベル+値の 1 対へ。
       .hc-dl-grid はラベル列が 160px×2 = 320px あり、375px 画面の内容領域
       （約 303px）を固定列だけで超えてしまう。 */
    @media (max-width: 640px) {
        .hc-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .hc-dl-grid { grid-template-columns: 96px minmax(0, 1fr); }
    }
</style>

    {{-- ヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <div class="flex items-center gap-3">
            <h1 class="text-lg font-bold text-gray-900">{{ $hsCustomOrder->order_name ?? '—' }}</h1>
            <span style="background: #FEF3C7; color: #92400E; display: inline-block; padding: 2px 10px; border-radius: 4px; font-size: 11px; font-weight: 600;">注文住宅</span>
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('housing.contracts.edit-custom-order', $hsCustomOrder) }}" class="hc-btn hc-btn-outline">編集</a>
            @endif
            <a href="{{ route('housing.custom-orders.show', $hsCustomOrder) }}" class="hc-btn hc-btn-gray">元ページへ</a>
            <a href="{{ route('housing.contracts.index') }}" class="hc-btn hc-btn-gray">戻る</a>
        </div>
    </div>


    {{-- 金額サマリー4カード --}}
    <div class="hc-summary">
        <div class="hc-mini-card">
            <div class="label">契約額（税抜）</div>
            <div class="value">
                @if($hsCustomOrder->getTotalSellingPrice() !== null)
                    {{ number_format($hsCustomOrder->getTotalSellingPrice()) }}円
                @else
                    —
                @endif
            </div>
        </div>
        <div class="hc-mini-card">
            <div class="label">原価合計</div>
            <div class="value">
                @if($hsCustomOrder->getTotalCost() !== null)
                    {{ number_format($hsCustomOrder->getTotalCost()) }}円
                @else
                    —
                @endif
            </div>
        </div>
        <div class="hc-mini-card">
            <div class="label">合計粗利</div>
            <div class="value profit">
                @if($hsCustomOrder->getTotalProfit() !== null)
                    {{ number_format($hsCustomOrder->getTotalProfit()) }}円
                @else
                    —
                @endif
            </div>
        </div>
        <div class="hc-mini-card">
            <div class="label">粗利率</div>
            <div class="value">
                @if($hsCustomOrder->getTotalProfitRate() !== null)
                    {{ $hsCustomOrder->getTotalProfitRate() }}%
                @else
                    —
                @endif
            </div>
        </div>
    </div>

    {{-- 基本情報 --}}
    <div class="hc-card">
        <div class="hc-card-body">
            <div class="hc-section-title">
                <span class="bar"></span>
                <h2>基本情報</h2>
            </div>
            <dl class="hc-dl-grid">
                <dt>案件名</dt>
                <dd>
                    <a href="{{ route('housing.custom-orders.show', $hsCustomOrder) }}" style="color: #059669; text-decoration: none;">{{ $hsCustomOrder->order_name ?? '—' }}</a>
                </dd>
                <dt>顧客名</dt>
                <dd>{{ $hsCustomOrder->customer_name ?? '—' }}</dd>

                <dt>契約日</dt>
                <dd>{{ $hsCustomOrder->contract_date?->format('Y/m/d') ?? '—' }}</dd>
                <dt>担当</dt>
                <dd>
                    @if($hsCustomOrder->createdBy)
                        {{ explode(' ', $hsCustomOrder->createdBy->name)[0] }}
                    @else
                        —
                    @endif
                </dd>

                <dt class="no-border-bottom">建築土地</dt>
                <dd class="no-border-bottom span3">
                    @if($hsCustomOrder->isCustomerLand())
                        お客様所有土地
                    @elseif($hsCustomOrder->projectLot && $hsCustomOrder->projectLot->project)
                        <a href="{{ route('realestate.projects.show', $hsCustomOrder->projectLot->project) }}" style="color: #059669; text-decoration: none;">
                            {{ $hsCustomOrder->projectLot->project->project_name }}
                            @if($hsCustomOrder->projectLot->lot_number)
                                {{ $hsCustomOrder->projectLot->lot_number }}号地
                            @endif
                        </a>
                    @elseif($hsCustomOrder->procurement)
                        <a href="{{ route('realestate.procurements.show', $hsCustomOrder->procurement) }}" style="color: #059669; text-decoration: none;">
                            {{ $hsCustomOrder->procurement->property_name }}
                        </a>
                    @else
                        —
                    @endif
                </dd>
            </dl>
        </div>
    </div>

    {{-- 契約金額内訳 --}}
    <div class="hc-card">
        <div class="hc-card-body">
            <div class="hc-section-title">
                <span class="bar"></span>
                <h2>契約金額内訳</h2>
            </div>
            <dl class="hc-dl-grid">
                <dt>土地販売価格</dt>
                <dd>
                    @if($hsCustomOrder->isCustomerLand())
                        <span class="muted">— （顧客所有地）</span>
                    @elseif($hsCustomOrder->land_selling_price !== null)
                        {{ number_format($hsCustomOrder->land_selling_price) }}円
                    @else
                        <span class="muted">—</span>
                    @endif
                </dd>
                <dt>建物契約価格（税抜）</dt>
                <dd>
                    @if($hsCustomOrder->building_contract_price !== null)
                        {{ number_format($hsCustomOrder->building_contract_price) }}円
                    @else
                        <span class="muted">—</span>
                    @endif
                </dd>

                <dt>建物消費税（{{ (int) $hsCustomOrder->tax_rate }}%）</dt>
                <dd>{{ number_format($hsCustomOrder->getBuildingTax()) }}円</dd>
                <dt class="no-border-bottom bold-label">税込合計</dt>
                <dd class="no-border-bottom bold-value">
                    @if($hsCustomOrder->getTotalSellingPrice() !== null)
                        {{ number_format($hsCustomOrder->getTotalSellingPrice() + $hsCustomOrder->getBuildingTax()) }}円
                    @else
                        <span class="muted">—</span>
                    @endif
                </dd>
            </dl>
        </div>
    </div>

    {{-- 原価内訳 --}}
    <div class="hc-card">
        <div class="hc-card-body">
            <div class="hc-section-title">
                <span class="bar"></span>
                <h2>原価内訳</h2>
            </div>
            <dl class="hc-dl-grid">
                <dt>土地原価</dt>
                <dd>
                    @if($hsCustomOrder->isCustomerLand())
                        <span class="muted">— （顧客所有地）</span>
                    @elseif($hsCustomOrder->land_cost !== null)
                        {{ number_format($hsCustomOrder->land_cost) }}円
                    @else
                        <span class="muted">—</span>
                    @endif
                </dd>
                <dt>建物原価</dt>
                <dd>
                    @if($hsCustomOrder->building_cost !== null)
                        {{ number_format($hsCustomOrder->building_cost) }}円
                    @else
                        <span class="muted">—</span>
                    @endif
                </dd>

                <dt class="no-border-bottom bold-label">合計原価</dt>
                <dd class="no-border-bottom span3 bold-value">
                    @if($hsCustomOrder->getTotalCost() !== null)
                        {{ number_format($hsCustomOrder->getTotalCost()) }}円
                    @else
                        <span class="muted">—</span>
                    @endif
                </dd>
            </dl>
        </div>
    </div>

    {{-- 粗利内訳 --}}
    <div class="hc-card">
        <div class="hc-card-body">
            <div class="hc-section-title">
                <span class="bar"></span>
                <h2>粗利内訳</h2>
            </div>
            <dl class="hc-dl-grid">
                <dt>土地粗利額</dt>
                <dd>
                    @if($hsCustomOrder->isCustomerLand())
                        <span class="muted">— （顧客所有地）</span>
                    @elseif($hsCustomOrder->getLandProfit() !== null)
                        <span class="profit-cell">{{ number_format($hsCustomOrder->getLandProfit()) }}円</span>
                        @if($hsCustomOrder->getLandProfitRate() !== null)
                            <span class="sub-rate">（{{ $hsCustomOrder->getLandProfitRate() }}%）</span>
                        @endif
                    @else
                        <span class="muted">—</span>
                    @endif
                </dd>
                <dt>建物粗利額</dt>
                <dd class="profit-cell">
                    @if($hsCustomOrder->getBuildingProfit() !== null)
                        {{ number_format($hsCustomOrder->getBuildingProfit()) }}円
                        @if($hsCustomOrder->getBuildingProfitRate() !== null)
                            <span class="sub-rate">（{{ $hsCustomOrder->getBuildingProfitRate() }}%）</span>
                        @endif
                    @else
                        <span class="muted">—</span>
                    @endif
                </dd>

                <dt class="no-border-bottom bold-label">合計粗利額</dt>
                <dd class="no-border-bottom profit-cell">
                    @if($hsCustomOrder->getTotalProfit() !== null)
                        {{ number_format($hsCustomOrder->getTotalProfit()) }}円
                    @else
                        <span class="muted">—</span>
                    @endif
                </dd>
                <dt class="no-border-bottom">合計粗利率</dt>
                <dd class="no-border-bottom">
                    @if($hsCustomOrder->getTotalProfitRate() !== null)
                        {{ $hsCustomOrder->getTotalProfitRate() }}%
                    @else
                        —
                    @endif
                </dd>
            </dl>
        </div>
    </div>

    {{-- 物件情報（注文コード非表示・住所span3・完成予定/引渡日非表示） --}}
    <div class="hc-card">
        <div class="hc-card-body">
            <div class="hc-section-title">
                <span class="bar"></span>
                <h2>物件情報</h2>
            </div>
            <dl class="hc-dl-grid">
                <dt>住所</dt>
                <dd class="span3">{{ $hsCustomOrder->address ?? '—' }}</dd>

                <dt>土地面積</dt>
                <dd>
                    @if($hsCustomOrder->land_area_sqm !== null)
                        {{ number_format((float) $hsCustomOrder->land_area_sqm, 2) }}㎡
                    @else
                        —
                    @endif
                </dd>
                <dt>建物面積</dt>
                <dd>
                    @if($hsCustomOrder->building_area_sqm !== null)
                        {{ number_format((float) $hsCustomOrder->building_area_sqm, 2) }}㎡
                    @else
                        —
                    @endif
                </dd>

                <dt class="no-border-bottom">構造</dt>
                <dd class="no-border-bottom">{{ $hsCustomOrder->structure ?? '—' }}</dd>
                <dt class="no-border-bottom">階数</dt>
                <dd class="no-border-bottom">
                    @if($hsCustomOrder->floors !== null)
                        {{ $hsCustomOrder->floors }}階
                    @else
                        —
                    @endif
                </dd>
            </dl>
        </div>
    </div>

    {{-- 備考 --}}
    @if($hsCustomOrder->notes)
        <div class="hc-card">
            <div class="hc-card-body">
                <div class="hc-section-title">
                    <span class="bar"></span>
                    <h2>備考</h2>
                </div>
                <p style="font-size: 13px; color: #374151; white-space: pre-wrap; margin: 0;">{{ $hsCustomOrder->notes }}</p>
            </div>
        </div>
    @endif

    {{-- 登録情報 --}}
    <div class="hc-card">
        <div class="hc-card-body">
            <div class="hc-section-title">
                <span class="bar"></span>
                <h2>登録情報</h2>
            </div>
            <dl class="hc-dl-grid">
                <dt>登録者</dt>
                <dd>{{ $hsCustomOrder->createdBy->name ?? '—' }}</dd>
                <dt>登録日時</dt>
                <dd>{{ $hsCustomOrder->created_at?->format('Y/m/d H:i') ?? '—' }}</dd>

                <dt class="no-border-bottom">更新者</dt>
                <dd class="no-border-bottom">{{ $hsCustomOrder->updatedBy->name ?? '—' }}</dd>
                <dt class="no-border-bottom">更新日時</dt>
                <dd class="no-border-bottom">{{ $hsCustomOrder->updated_at?->format('Y/m/d H:i') ?? '—' }}</dd>
            </dl>
        </div>
    </div>

@endsection
