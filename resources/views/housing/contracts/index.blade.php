@extends('layouts.app')

@section('title', '契約管理')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">契約管理</span>
@endsection

@section('content')

    {{-- 一覧テーブルのスタイル（建売物件一覧から流用）。
         :hover・子孫セレクタはインラインで表現できないため <style> を使う（Bug #19 とは無関係）。
         合計ゾーンはレッド（決定 #9）、固定列は 物件名・種別・進行状況 の 3 列。 --}}
    <style>
    /* ヘッダー */
    .co-th        { padding: 10px 12px; background: #f9fafb; border-bottom: 2px solid #e5e7eb; font-size: 12px; font-weight: 600; color: #4b5563; white-space: nowrap; text-align: center; }
    .co-th-name   { text-align: left; padding-left: 16px; }
    .co-grp       { font-size: 11.5px; letter-spacing: .08em; padding-top: 6px; padding-bottom: 6px; }
    .co-grp-t     { background: #fee2e2; color: #991b1b; }   /* 合計＝レッド（決定 #9） */
    .co-grp-b     { background: #f0f9ff; color: #075985; }   /* 建物＝水色（現状維持） */
    .co-grp-l     { background: #fefce8; color: #854d0e; }   /* 土地＝黄色（現状維持） */

    /* ボディ */
    .co-td      { padding: 12px; border-bottom: 1px solid #f3f4f6; font-size: 13px; white-space: nowrap; vertical-align: middle; text-align: center; }
    .co-td-name { text-align: left; padding-left: 16px; }
    .co-num     { text-align: right; }
    .co-muted   { color: #d1d5db; }
    .co-tax-sub { font-size: 11px; color: #6b7280; margin-top: 2px; }

    /* 合計 / 建物 / 土地ゾーンの区切りと淡い地色 */
    .co-gstart { border-left: 1px solid #cbd5e1; }
    td.co-zone-t { background: #fef2f2; }   /* 合計＝淡いレッド地色（決定 #9） */
    td.co-zone-b { background: #fcfeff; }
    td.co-zone-l { background: #fffdf5; }
    /* ⚠ td の背景は tr の背景を上書きするため、行ホバー時の上書き規則が必須 */
    tbody tr:hover td.co-zone-t { background: #fee2e2; }
    tbody tr:hover td.co-zone-b { background: #f5fbfe; }
    tbody tr:hover td.co-zone-l { background: #fefbef; }

    /* --- 横スクロール時に左 3 列（物件名・種別・進行状況）を固定し、合計より右だけスクロールさせる --- */
    /* ⚠ sticky セルは不透明背景が必須（スクロールで下に潜る右側セルが透けるのを防ぐ）。
       ⚠ 各固定列の left は左隣までの実幅合計と一致させる。box-sizing:border-box で padding 込み幅を固定。 */
    th.co-sticky, td.co-sticky { position: sticky; z-index: 1; }
    th.co-sticky               { z-index: 3; }                 /* ヘッダーの固定列は本文セルより前面 */
    .co-sticky-name            { left: 0; }
    .co-sticky-type            { left: 190px; }                /* = .co-col-name の width */
    .co-sticky-stat            { left: 278px; }                /* = 190 + 88（種別の width まで） */
    .co-col-name               { width: 190px; min-width: 190px; max-width: 190px; box-sizing: border-box; }
    .co-col-type               { width: 88px;  min-width: 88px;  box-sizing: border-box; }
    .co-col-stat               { width: 100px; min-width: 100px; box-sizing: border-box; }
    /* 物件名が長くても隣へはみ出さないよう省略 */
    .co-name-link              { display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom; }
    /* 固定列の不透明背景（ヘッダー / 本文 / ホバー） */
    th.co-sticky               { background: #f9fafb; }
    tbody td.co-sticky         { background: #fff; }
    tbody tr:hover td.co-sticky { background: #f9fafb; }
    /* 固定領域とスクロール領域の境界（進行状況の右端に区切り線＋うっすら影） */
    td.co-sticky-stat, th.co-sticky-stat { border-right: 1px solid #e5e7eb; box-shadow: 4px 0 6px -4px rgba(0, 0, 0, .15); }
    </style>

    {{-- ページヘッダー（+ 新規契約登録ドロップダウン） --}}
    <div class="mb-5" style="display: flex; align-items: center; justify-content: space-between;">
        <h1 class="text-lg font-bold text-gray-900">契約管理</h1>

        {{-- 新規契約登録ドロップダウン（建売 / 注文住宅） --}}
        <div x-data="{ open: false }" style="position: relative;">
            <button type="button" @click="open = !open"
                    style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 16px; background: #059669; color: white; font-size: 14px; font-weight: 600; border-radius: 6px; border: none; cursor: pointer;"
                    onmouseover="this.style.background='#047857';"
                    onmouseout="this.style.background='#059669';">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                新規契約登録
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 2px;"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div x-show="open" @click.outside="open = false" x-cloak
                 style="position: absolute; top: calc(100% + 4px); right: 0; background: white; border: 1px solid #E5E7EB; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); min-width: 200px; z-index: 10;">
                <a href="{{ route('housing.contracts.select-building-property') }}"
                   style="display: block; padding: 10px 16px; font-size: 14px; color: #374151; text-decoration: none; border-bottom: 1px solid #F3F4F6;"
                   onmouseover="this.style.background='#F9FAFB'; this.style.color='#059669';"
                   onmouseout="this.style.background='white'; this.style.color='#374151';">
                    建売を登録
                </a>
                <a href="{{ route('housing.custom-orders.create') }}"
                   style="display: block; padding: 10px 16px; font-size: 14px; color: #374151; text-decoration: none;"
                   onmouseover="this.style.background='#F9FAFB'; this.style.color='#059669';"
                   onmouseout="this.style.background='white'; this.style.color='#374151';">
                    注文住宅を登録
                </a>
            </div>
        </div>
    </div>


    {{-- 集計エリア（5分割サマリーカード） --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-4">
        <div class="flex items-center justify-between mb-3">
            <span class="text-sm font-bold text-gray-700">
                @if($fiscalYear !== '' && $fiscalYear !== 'all')
                    {{ $fiscalYear }}年度（{{ $fiscalYear }}/05〜{{ $fiscalYear + 1 }}/04）
                @else
                    全期間
                @endif
            </span>
            <span class="text-xs text-gray-400">住宅事業</span>
        </div>

        {{-- 5カードサマリー: 件数 / 契約額合計 / 土地粗利 / 建物粗利 / 合計粗利 --}}
        <div style="display: flex; justify-content: space-between; gap: 32px; width: 100%; align-items: flex-start;">
            {{-- 1. 契約件数 --}}
            <div style="flex: 0 1 auto; min-width: 0;">
                <div style="font-size: 12px; color: #6B7280; margin-bottom: 2px;">契約件数</div>
                <div style="font-size: 16px; font-weight: 700; color: #111827;">{{ $totalCount }}件</div>
                <div style="font-size: 13px; color: #374151; font-weight: 500; margin-top: 2px;">(建売 {{ $tateuriCount }}件 / 注文住宅 {{ $customCount }}件)</div>
            </div>

            {{-- 2. 契約額合計（土地・建物 横並び） --}}
            <div style="flex: 0 1 auto; min-width: 0;">
                <div style="font-size: 12px; color: #6B7280; margin-bottom: 2px;">契約額合計（税抜）</div>
                <div style="font-size: 16px; font-weight: 700; color: #111827;">{{ number_format($sellingTotal) }}円</div>
                <div style="font-size: 13px; color: #374151; font-weight: 500; margin-top: 2px; white-space: nowrap;">(土地 {{ number_format($landSellingTotal) }}円 ・ 建物 {{ number_format($buildingSellingTotal) }}円)</div>
            </div>

            {{-- 3. 土地粗利合計 --}}
            <div style="flex: 0 1 auto; min-width: 0;">
                <div style="font-size: 12px; color: #6B7280; margin-bottom: 2px;">土地粗利合計</div>
                <div style="font-size: 16px; color: #047857; font-weight: 700;">{{ number_format($landProfitTotal) }}円</div>
            </div>

            {{-- 4. 建物粗利合計 --}}
            <div style="flex: 0 1 auto; min-width: 0;">
                <div style="font-size: 12px; color: #6B7280; margin-bottom: 2px;">建物粗利合計</div>
                <div style="font-size: 16px; color: #047857; font-weight: 700;">{{ number_format($buildingProfitTotal) }}円</div>
            </div>

            {{-- 5. 合計粗利（粗利率付き） --}}
            <div style="flex: 0 1 auto; min-width: 0;">
                <div style="font-size: 12px; color: #6B7280; margin-bottom: 2px;">合計粗利</div>
                <div style="font-size: 16px; color: #047857; font-weight: 700;">{{ number_format($profitTotal) }}円</div>
                <div style="font-size: 13px; color: #374151; font-weight: 500; margin-top: 2px;">(合計粗利率 {{ $profitRate }}%)</div>
            </div>
        </div>
    </div>

    {{-- フィルターバー --}}
    <form id="filter-form" method="GET" action="{{ route('housing.contracts.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <select name="fiscal_year" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="{{ $currentFiscalYear }}" {{ $fiscalYear == $currentFiscalYear ? 'selected' : '' }}>年度: {{ $currentFiscalYear }}年度</option>
            <option value="all" {{ $fiscalYear === 'all' ? 'selected' : '' }}>年度: 全期間</option>
            @foreach($fiscalYears as $fy)
                @if($fy != $currentFiscalYear)
                    <option value="{{ $fy }}" {{ $fiscalYear == $fy ? 'selected' : '' }}>年度: {{ $fy }}年度</option>
                @endif
            @endforeach
        </select>
        <select name="contract_type" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">種別: 全て</option>
            <option value="tateuri" {{ request('contract_type') === 'tateuri' ? 'selected' : '' }}>建売</option>
            <option value="custom" {{ request('contract_type') === 'custom' ? 'selected' : '' }}>注文住宅</option>
        </select>
        <select name="staff_user_id" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">担当者: 全て</option>
            @foreach($staffUsers as $su)
                <option value="{{ $su->id }}" {{ request('staff_user_id') == $su->id ? 'selected' : '' }}>{{ $su->name }}</option>
            @endforeach
        </select>
        <a href="{{ route('housing.contracts.index') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
            クリア
        </a>
    </form>

    {{-- テーブル（建売物件一覧の 3 ゾーン様式・全 18 列・2 段ヘッダー） --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div style="overflow-x: auto;">
            <table class="w-full border-collapse" style="min-width: 1400px;">
                <thead>
                    <tr>
                        <th rowspan="2" class="co-th co-th-name co-sticky co-sticky-name co-col-name">物件名 / 案件名</th>
                        <th rowspan="2" class="co-th co-sticky co-sticky-type co-col-type">種別</th>
                        <th rowspan="2" class="co-th co-sticky co-sticky-stat co-col-stat">進行状況</th>
                        <th rowspan="2" class="co-th">契約日</th>
                        <th rowspan="2" class="co-th">顧客</th>
                        <th colspan="3" class="co-th co-grp co-grp-t co-gstart">合　計</th>
                        <th colspan="4" class="co-th co-grp co-grp-b co-gstart">建　物</th>
                        <th colspan="4" class="co-th co-grp co-grp-l co-gstart">土　地</th>
                        <th rowspan="2" class="co-th co-gstart">担当</th>
                        <th rowspan="2" class="co-th">詳細</th>
                    </tr>
                    <tr>
                        <th class="co-th co-gstart">販売金額</th>
                        <th class="co-th">原価額</th>
                        <th class="co-th">粗利額</th>
                        <th class="co-th co-gstart">販売金額</th>
                        <th class="co-th">原価額</th>
                        <th class="co-th">粗利額</th>
                        <th class="co-th">粗利率</th>
                        <th class="co-th co-gstart">販売金額</th>
                        <th class="co-th">原価額</th>
                        <th class="co-th">粗利額</th>
                        <th class="co-th">粗利率</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($contracts as $c)
                        @php
                            // 担当者表示（苗字。同姓が複数いる場合のみフルネーム）— 現状ロジック維持
                            $staffDisplay = $c['staff_name'];
                            if ($staffDisplay !== '—') {
                                if (($lastNameCounts[$staffDisplay] ?? 0) > 1 && $c['source_model']->createdBy) {
                                    $staffDisplay = $c['source_model']->createdBy->name;
                                }
                            }

                            // 3 ゾーンの内訳（設計書 §3.3）。合計は getTotal*() を直呼びせず、
                            // 表示している建物＋土地から積み上げる（5f3db713 と同じ轍を踏まない）。
                            $isCompanyLand = $c['is_company_land'];
                            $bTax = $c['building_tax'];                          // 建物消費税額（土地は非課税）
                            // 建物
                            $bPrice  = $c['building_selling'];
                            $bCost   = $c['building_cost'];
                            $bProfit = $c['building_profit'];
                            $bRate   = $c['building_profit_rate'];
                            // 土地（顧客所有地は 4 セル「—」）
                            $lPrice  = $isCompanyLand ? $c['land_selling'] : null;
                            $lCost   = $isCompanyLand ? $c['land_cost']    : null;
                            $lProfit = $c['land_profit'];                       // 顧客所有地/原価未入力で既に null
                            $lRate   = $c['land_profit_rate'];
                            // 合計 = 表示している建物＋土地の積み上げ
                            $tPrice  = ($bPrice !== null || $lPrice !== null) ? ($bPrice ?? 0) + ($lPrice ?? 0) : null;
                            $tCost   = ($bCost  !== null || $lCost  !== null) ? ($bCost  ?? 0) + ($lCost  ?? 0) : null;
                            $tProfit = ($tPrice !== null && $tCost  !== null) ? $tPrice - $tCost : null;
                        @endphp
                        <tr class="hover:bg-gray-50">
                            {{-- 固定1: 物件名 / 案件名（詳細リンク・建売一覧に準拠した青リンク） --}}
                            <td class="co-td co-td-name co-sticky co-sticky-name co-col-name">
                                <a href="{{ $c['detail_url'] }}" class="text-blue-700 underline co-name-link">{{ $c['property_name'] }}</a>
                            </td>

                            {{-- 固定2: 種別 --}}
                            <td class="co-td co-sticky co-sticky-type co-col-type">
                                @if($c['type'] === 'tateuri')
                                    <span style="background: #DBEAFE; color: #1E40AF; display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">建売</span>
                                @else
                                    <span style="background: #FEF3C7; color: #92400E; display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">注文住宅</span>
                                @endif
                            </td>

                            {{-- 固定3: 進行状況（読み取り専用の静的バッジ） --}}
                            <td class="co-td co-sticky co-sticky-stat co-col-stat">
                                @if($c['type'] === 'tateuri')
                                    <span style="background: #D1FAE5; color: #065F46; display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">{{ $c['status_label'] }}</span>
                                @else
                                    <span style="{{ $c['status_color'] }} display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">{{ $c['status_label'] }}</span>
                                @endif
                            </td>

                            {{-- 契約日 --}}
                            <td class="co-td">{{ $c['contract_date'] ? $c['contract_date']->format('Y/m/d') : '—' }}</td>

                            {{-- 顧客 --}}
                            <td class="co-td">{{ $c['customer_name'] }}</td>

                            {{-- 合計: 販売金額（税込サブ行あり） --}}
                            <td class="co-td co-num co-zone-t co-gstart">
                                @if($tPrice !== null)
                                    {{ number_format($tPrice) }}円
                                    <div class="co-tax-sub">税込 {{ number_format($tPrice + $bTax) }}円</div>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>
                            {{-- 合計: 原価額 --}}
                            <td class="co-td co-num co-zone-t">
                                @if($tCost !== null){{ number_format($tCost) }}円@else<span class="co-muted">—</span>@endif
                            </td>
                            {{-- 合計: 粗利額 --}}
                            <td class="co-td co-num co-zone-t">
                                @if($tProfit !== null)
                                    <span style="{{ $tProfit >= 0 ? 'color: #047857; font-weight: 700;' : 'color: #dc2626; font-weight: 700;' }}">{{ number_format($tProfit) }}円</span>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>

                            {{-- 建物: 販売金額（税込サブ行あり） --}}
                            <td class="co-td co-num co-zone-b co-gstart">
                                @if($bPrice !== null)
                                    {{ number_format($bPrice) }}円
                                    <div class="co-tax-sub">税込 {{ number_format($bPrice + $bTax) }}円</div>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>
                            {{-- 建物: 原価額 --}}
                            <td class="co-td co-num co-zone-b">
                                @if($bCost !== null){{ number_format($bCost) }}円@else<span class="co-muted">—</span>@endif
                            </td>
                            {{-- 建物: 粗利額 --}}
                            <td class="co-td co-num co-zone-b">
                                @if($bProfit !== null)
                                    <span style="{{ $bProfit >= 0 ? 'color: #047857; font-weight: 700;' : 'color: #dc2626; font-weight: 700;' }}">{{ number_format($bProfit) }}円</span>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>
                            {{-- 建物: 粗利率（常に小数1桁） --}}
                            <td class="co-td co-num co-zone-b">
                                @if($bRate !== null)
                                    <span style="{{ $bRate >= 0 ? 'color: #047857; font-weight: 700;' : 'color: #dc2626; font-weight: 700;' }}">{{ number_format($bRate, 1) }}%</span>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>

                            {{-- 土地: 販売金額（非課税＝税込サブ行なし） --}}
                            <td class="co-td co-num co-zone-l co-gstart">
                                @if($lPrice !== null){{ number_format($lPrice) }}円@else<span class="co-muted">—</span>@endif
                            </td>
                            {{-- 土地: 原価額 --}}
                            <td class="co-td co-num co-zone-l">
                                @if($lCost !== null){{ number_format($lCost) }}円@else<span class="co-muted">—</span>@endif
                            </td>
                            {{-- 土地: 粗利額 --}}
                            <td class="co-td co-num co-zone-l">
                                @if($lProfit !== null)
                                    <span style="{{ $lProfit >= 0 ? 'color: #047857; font-weight: 700;' : 'color: #dc2626; font-weight: 700;' }}">{{ number_format($lProfit) }}円</span>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>
                            {{-- 土地: 粗利率（常に小数1桁） --}}
                            <td class="co-td co-num co-zone-l">
                                @if($lRate !== null)
                                    <span style="{{ $lRate >= 0 ? 'color: #047857; font-weight: 700;' : 'color: #dc2626; font-weight: 700;' }}">{{ number_format($lRate, 1) }}%</span>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>

                            {{-- 担当（現状ロジック維持） --}}
                            <td class="co-td co-gstart">{{ $staffDisplay }}</td>

                            {{-- 詳細（現状の緑ピルを維持） --}}
                            <td class="co-td">
                                <a href="{{ $c['detail_url'] }}"
                                   class="inline-block px-3 py-1 bg-white text-emerald-600 border border-emerald-600 rounded text-xs font-semibold hover:bg-emerald-50 transition-colors">詳細</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="18" class="px-5 py-10 text-center text-sm text-gray-400">契約データがありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-4 py-2.5 border-t border-gray-200 text-sm text-gray-500">全 {{ $contracts->total() }} 件</div>

        @if($contracts->hasPages())
            <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
                @if($contracts->onFirstPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                @else
                    <a href="{{ $contracts->previousPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">&lt;</a>
                @endif
                @foreach($contracts->getUrlRange(1, $contracts->lastPage()) as $page => $url)
                    @if($page == $contracts->currentPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">{{ $page }}</a>
                    @endif
                @endforeach
                @if($contracts->hasMorePages())
                    <a href="{{ $contracts->nextPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">&gt;</a>
                @else
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                @endif
            </div>
        @endif
    </div>

@endsection
