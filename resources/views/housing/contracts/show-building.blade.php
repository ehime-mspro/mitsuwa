@extends('layouts.app')

@section('title', $property->property_name . ' — 契約詳細')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.contracts.index') }}" class="hover:text-emerald-600 transition-colors">契約管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $property->property_name }}</span>
@endsection

@section('content')

<style>
    /* 詳細ページ専用カード／dl-grid（Viteビルド外のTailwindクラスを使わずインラインCSSで定義） */
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
    .hc-btn-danger  { color: #DC2626; border: 1px solid #DC2626; }
    .hc-btn-danger:hover { background: #FEF2F2; }

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
            <h1 class="text-lg font-bold text-gray-900">{{ $property->property_name }}</h1>
            <span style="background: #dbeafe; color: #1e40af; display: inline-block; padding: 2px 10px; border-radius: 4px; font-size: 11px; font-weight: 600;">建売</span>
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('housing.contracts.edit-building', $contract) }}" class="hc-btn hc-btn-outline">編集</a>
            @endif
            <a href="{{ route('housing.properties.show', $property) }}" class="hc-btn hc-btn-gray">元ページへ</a>
            <a href="{{ route('housing.contracts.index') }}" class="hc-btn hc-btn-gray">戻る</a>
            @if(auth()->user()->role->isExecutive())
                <form method="POST" action="{{ route('housing.contracts.destroy', $property) }}"
                      onsubmit="return confirm('この契約を削除しますか？')" style="margin: 0;">
                    @csrf @method('DELETE')
                    <button type="submit" class="hc-btn hc-btn-danger">削除</button>
                </form>
            @endif
        </div>
    </div>


    {{-- 金額サマリー4カード --}}
    <div class="hc-summary">
        <div class="hc-mini-card">
            <div class="label">契約額（税抜）</div>
            <div class="value">{{ number_format($contract->getSellingPriceTotal()) }}円</div>
        </div>
        <div class="hc-mini-card">
            <div class="label">原価合計</div>
            <div class="value">
                @if($property->getTotalCost() !== null)
                    {{ number_format($property->getTotalCost()) }}円
                @else
                    —
                @endif
            </div>
        </div>
        <div class="hc-mini-card">
            <div class="label">合計粗利</div>
            <div class="value profit">
                @if($contract->getTotalProfit() !== null)
                    {{ number_format($contract->getTotalProfit()) }}円
                @else
                    —
                @endif
            </div>
        </div>
        <div class="hc-mini-card">
            <div class="label">粗利率</div>
            <div class="value">
                @if($contract->getTotalProfitRate() !== null)
                    {{ $contract->getTotalProfitRate() }}%
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
                <dt>物件名</dt>
                <dd>
                    <a href="{{ route('housing.properties.show', $property) }}" style="color: #059669; text-decoration: none;">{{ $property->property_name }}</a>
                </dd>
                <dt>買主</dt>
                <dd>{{ $contract->customer_name ?? '—' }}</dd>

                <dt>契約日</dt>
                <dd>{{ $contract->contract_date?->format('Y/m/d') ?? '—' }}</dd>
                <dt class="no-border-bottom">担当</dt>
                <dd class="no-border-bottom">
                    @if($contract->createdBy)
                        {{ explode(' ', $contract->createdBy->name)[0] }}
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
                <dd>{{ number_format($contract->selling_price_land) }}円</dd>
                <dt>建物販売価格（税抜）</dt>
                <dd>{{ number_format($contract->selling_price_building) }}円</dd>

                <dt>建物消費税（{{ (int) $contract->tax_rate }}%）</dt>
                <dd>{{ number_format($contract->getBuildingTax()) }}円</dd>
                <dt class="no-border-bottom bold-label">税込合計</dt>
                <dd class="no-border-bottom bold-value">{{ number_format($contract->getSellingPriceTotalWithTax()) }}円</dd>
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
                    @if($property->land_cost !== null)
                        {{ number_format($property->land_cost) }}円
                    @else
                        <span class="muted">—</span>
                    @endif
                </dd>
                <dt>建物原価</dt>
                <dd>
                    @if($property->building_cost !== null)
                        {{ number_format($property->building_cost) }}円
                    @else
                        <span class="muted">—</span>
                    @endif
                </dd>

                <dt class="no-border-bottom bold-label">合計原価</dt>
                <dd class="no-border-bottom span3 bold-value">
                    @if($property->getTotalCost() !== null)
                        {{ number_format($property->getTotalCost()) }}円
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
                <dd class="profit-cell">
                    @if($contract->getLandProfit() !== null)
                        {{ number_format($contract->getLandProfit()) }}円
                        @if($contract->getLandProfitRate() !== null)
                            <span class="sub-rate">（{{ $contract->getLandProfitRate() }}%）</span>
                        @endif
                    @else
                        <span class="muted">—</span>
                    @endif
                </dd>
                <dt>建物粗利額</dt>
                <dd class="profit-cell">
                    @if($contract->getBuildingProfit() !== null)
                        {{ number_format($contract->getBuildingProfit()) }}円
                        @if($contract->getBuildingProfitRate() !== null)
                            <span class="sub-rate">（{{ $contract->getBuildingProfitRate() }}%）</span>
                        @endif
                    @else
                        <span class="muted">—</span>
                    @endif
                </dd>

                <dt class="no-border-bottom bold-label">合計粗利額</dt>
                <dd class="no-border-bottom profit-cell">
                    @if($contract->getTotalProfit() !== null)
                        {{ number_format($contract->getTotalProfit()) }}円
                    @else
                        <span class="muted">—</span>
                    @endif
                </dd>
                <dt class="no-border-bottom">合計粗利率</dt>
                <dd class="no-border-bottom">
                    @if($contract->getTotalProfitRate() !== null)
                        {{ $contract->getTotalProfitRate() }}%
                    @else
                        —
                    @endif
                </dd>
            </dl>
        </div>
    </div>

    {{-- 物件情報 --}}
    <div class="hc-card">
        <div class="hc-card-body">
            <div class="hc-section-title">
                <span class="bar"></span>
                <h2>物件情報</h2>
            </div>
            <dl class="hc-dl-grid">
                <dt>物件コード</dt>
                <dd>{{ $property->property_code ?? '—' }}</dd>
                <dt>住所</dt>
                <dd>{{ $property->address ?? '—' }}</dd>

                <dt>土地面積</dt>
                <dd>
                    @if($property->land_area_sqm !== null)
                        {{ number_format((float) $property->land_area_sqm, 2) }}㎡
                    @else
                        —
                    @endif
                </dd>
                <dt>建物面積</dt>
                <dd>
                    @if($property->building_area_sqm !== null)
                        {{ number_format((float) $property->building_area_sqm, 2) }}㎡
                    @else
                        —
                    @endif
                </dd>

                <dt>構造</dt>
                <dd>{{ $property->structure ?? '—' }}</dd>
                <dt class="no-border-bottom">階数</dt>
                <dd class="no-border-bottom">
                    @if($property->floors !== null)
                        {{ $property->floors }}階
                    @else
                        —
                    @endif
                </dd>
            </dl>
        </div>
    </div>

    {{-- 備考 --}}
    @if($contract->notes)
        <div class="hc-card">
            <div class="hc-card-body">
                <div class="hc-section-title">
                    <span class="bar"></span>
                    <h2>備考</h2>
                </div>
                <p style="font-size: 13px; color: #374151; white-space: pre-wrap; margin: 0;">{{ $contract->notes }}</p>
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
                <dd>{{ $contract->createdBy->name ?? '—' }}</dd>
                <dt>登録日時</dt>
                <dd>{{ $contract->created_at?->format('Y/m/d H:i') ?? '—' }}</dd>

                <dt class="no-border-bottom">更新者</dt>
                <dd class="no-border-bottom">{{ $contract->updatedBy->name ?? '—' }}</dd>
                <dt class="no-border-bottom">更新日時</dt>
                <dd class="no-border-bottom">{{ $contract->updated_at?->format('Y/m/d H:i') ?? '—' }}</dd>
            </dl>
        </div>
    </div>

@endsection
