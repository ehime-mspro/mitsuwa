@extends('layouts.app')

@section('title', '建売物件一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">建売物件一覧</span>
@endsection

@section('content')

    @php
        // 進捗ステータスポップオーバー用オプション一覧（一覧バッジクリックからの即時変更用）
        // 住宅事業はバッジ用に CSS class ではなくインライン style を使う（Vite ビルド未含有のため）
        $statusOptions = collect(\App\Enums\HousingPropertyStatus::cases())->map(function ($s) {
            return [
                'value'       => $s->value,
                'label'       => $s->label(),
                'badge_style' => $s->badgeStyle(),
            ];
        })->values()->all();
        // 「成約」は HousingPropertyStatus enum の値ではなく契約レコードの有無で導かれる仮想ステータス。
        // クリックで契約登録画面へ遷移する特別オプションとして末尾に追加。
        $statusOptions[] = [
            'value'       => 'sold',
            'label'       => '成約',
            'badge_style' => 'background: #a7f3d0; color: #064e3b;',
        ];
        $canEditStatus = auth()->user()->role->isManagerOrAbove();
    @endphp

    {{-- 一覧テーブルのスタイル（注文住宅一覧から流用）
         :hover・子孫セレクタはインラインで表現できないため <style> を使う（Bug #19 とは無関係）。
         ⚠ .co-num / .co-td-name は .co-td より後ろに書くこと（同詳細度・ソース順で勝敗）。 --}}
    <style>
    /* ヘッダー */
    .co-th        { padding: 10px 12px; background: #f9fafb; border-bottom: 2px solid #e5e7eb; font-size: 12px; font-weight: 600; color: #4b5563; white-space: nowrap; text-align: center; }
    .co-th-name   { text-align: left; padding-left: 16px; }
    .co-grp       { font-size: 11.5px; letter-spacing: .08em; padding-top: 6px; padding-bottom: 6px; }
    .co-grp-t     { background: #eef2f6; color: #1f2937; }
    .co-grp-b     { background: #f0f9ff; color: #075985; }
    .co-grp-l     { background: #fefce8; color: #854d0e; }

    /* ボディ */
    .co-td      { padding: 12px; border-bottom: 1px solid #f3f4f6; font-size: 13px; white-space: nowrap; vertical-align: middle; text-align: center; }
    .co-td-name { text-align: left; padding-left: 16px; }
    .co-num     { text-align: right; }
    .co-muted   { color: #d1d5db; }
    .co-tax-sub { font-size: 11px; color: #6b7280; margin-top: 2px; }

    /* 合計 / 建物 / 土地ゾーンの区切りと淡い地色 */
    .co-gstart { border-left: 1px solid #cbd5e1; }
    td.co-zone-t { background: #f6f8fa; }
    td.co-zone-b { background: #fcfeff; }
    td.co-zone-l { background: #fffdf5; }
    /* ⚠ td の背景は tr の背景を上書きするため、行ホバー時の上書き規則が必須 */
    tbody tr:hover td.co-zone-t { background: #eef2f6; }
    tbody tr:hover td.co-zone-b { background: #f5fbfe; }
    tbody tr:hover td.co-zone-l { background: #fefbef; }

    /* --- 横スクロール時に左2列（物件名・進捗）を固定し、合計より右だけスクロールさせる --- */
    /* ⚠ sticky セルは不透明背景が必須（スクロールで下に潜る右側セルが透けるのを防ぐ）。
       ⚠ 進捗の left は物件名列の実幅（.co-col-name の width）と一致させる。box-sizing:border-box で
          padding 込み幅を固定し、table-layout:auto でも列幅がブレないようにする。 */
    th.co-sticky, td.co-sticky { position: sticky; z-index: 1; }
    th.co-sticky               { z-index: 3; }                 /* ヘッダーの固定列は本文セルより前面 */
    .co-sticky-name            { left: 0; }
    .co-sticky-stat            { left: 200px; }                /* = .co-col-name の width */
    .co-col-name               { width: 200px; min-width: 200px; max-width: 200px; box-sizing: border-box; }
    .co-col-stat               { width: 96px;  min-width: 96px;  box-sizing: border-box; }
    /* 物件名が長くても隣へはみ出さないよう省略（坪数サブ行は元々短い） */
    .co-name-link              { display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom; }
    /* 固定列の不透明背景（ヘッダー / 本文 / ホバー） */
    th.co-sticky               { background: #f9fafb; }
    tbody td.co-sticky         { background: #fff; }
    tbody tr:hover td.co-sticky { background: #f9fafb; }
    /* 固定領域とスクロール領域の境界（進捗の右端に区切り線＋うっすら影） */
    td.co-sticky-stat, th.co-sticky-stat { border-right: 1px solid #e5e7eb; box-shadow: 4px 0 6px -4px rgba(0, 0, 0, .15); }
    </style>

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">建売物件一覧</h1>
        <a href="{{ route('housing.properties.create') }}"
           class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            新規登録
        </a>
    </div>



    {{-- フィルターバー --}}
    <form id="filter-form" method="GET" action="{{ route('housing.properties.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <select name="status" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="all" {{ request('status') === 'all' ? 'selected' : '' }}>全て</option>
            <option value="non_sold" {{ request('status', 'non_sold') === 'non_sold' ? 'selected' : '' }}>成約以外</option>
            @foreach(\App\Enums\HousingPropertyStatus::cases() as $st)
                <option value="{{ $st->value }}" {{ request('status', 'non_sold') === $st->value ? 'selected' : '' }}>{{ $st->label() }}</option>
            @endforeach
            <option value="sold" {{ request('status') === 'sold' ? 'selected' : '' }}>成約</option>
        </select>
        <input type="text" name="keyword" value="{{ request('keyword') }}"
               placeholder="物件名・所在地・物件番号"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
        <a href="{{ route('housing.properties.index') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap inline-flex items-center justify-center">
            クリア
        </a>
    </form>

    {{-- テーブル --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div style="overflow-x: auto;">
            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <th rowspan="2" class="co-th co-th-name co-sticky co-sticky-name co-col-name">物件名</th>
                        <th rowspan="2" class="co-th co-sticky co-sticky-stat co-col-stat">進捗</th>
                        <th colspan="3" class="co-th co-grp co-grp-t co-gstart">合　計</th>
                        <th colspan="4" class="co-th co-grp co-grp-b co-gstart">建　物</th>
                        <th colspan="4" class="co-th co-grp co-grp-l co-gstart">土　地</th>
                        <th rowspan="2" class="co-th co-gstart">詳細</th>
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
                    @forelse($properties as $prop)
                        @php
                            // 土地は isCompanyLand() を単一の判断軸にする（設計書 §3.5）。
                            $isCompanyLand = $prop->isCompanyLand();
                            $bTax = $prop->getBuildingTax();   // 合計・建物の税込サブ行で使う（建物ぶんの税）
                            // 建物
                            $bPrice  = $prop->getBuildingSellingPrice();
                            $bCost   = $prop->building_cost;
                            $bProfit = $prop->getBuildingProfit();
                            $bRate   = $prop->getBuildingProfitRate();
                            // 土地（お客様所有土地は 4 セル「—」）
                            $lPrice  = $isCompanyLand ? $prop->getLandSellingPrice() : null;
                            $lCost   = $isCompanyLand ? $prop->land_cost : null;
                            $lProfit = $prop->getLandProfit();
                            $lRate   = $prop->getLandProfitRate();
                            // 合計は「表示している建物＋土地」から積み上げる（詳細画面 show と同思想）。
                            // getSellingPriceTotal()/getTotalCost()/getGrossProfit() 直呼びは isCompanyLand()
                            // ガード後の内訳とズレる（お客様所有土地・土地種別未選択で不整合）ため使わない（final review §3.6）。
                            $tPrice  = ($bPrice !== null || $lPrice !== null) ? ($bPrice ?? 0) + ($lPrice ?? 0) : null;
                            $tCost   = ($bCost  !== null || $lCost  !== null) ? ($bCost  ?? 0) + ($lCost  ?? 0) : null;
                            $tProfit = ($tPrice !== null && $tCost !== null) ? $tPrice - $tCost : null;
                            // 坪数サブ行
                            $landTsubo = $prop->getLandAreaTsubo();
                            $bldgTsubo = $prop->getBuildingAreaTsubo();
                        @endphp
                        <tr class="hover:bg-gray-50">
                            {{-- 物件名（詳細リンク＋坪数サブ行） --}}
                            <td class="co-td co-td-name co-sticky co-sticky-name co-col-name">
                                <div class="font-semibold">
                                    <a href="{{ route('housing.properties.show', $prop) }}" class="text-blue-700 underline co-name-link">{{ $prop->property_name }}</a>
                                </div>
                                <div class="text-xs text-gray-500">土地 {{ $landTsubo !== null ? number_format($landTsubo, 2) . '坪' : '—' }} / 建物 {{ $bldgTsubo !== null ? number_format($bldgTsubo, 2) . '坪' : '—' }}</div>
                            </td>

                            {{-- 進捗（現状維持: Ajax ドロップダウン。ステップバーではない） --}}
                            @if($canEditStatus)
                                <td class="co-td co-sticky co-sticky-stat co-col-stat"
                                    x-data="housingPropertyStatusCell({{ $prop->id }}, '{{ $prop->isSold() ? 'sold' : $prop->status->value }}', '{{ $prop->getDisplayStatusLabel() }}', '{{ $prop->getDisplayBadgeStyle() }}', '{{ route('housing.contracts.create', $prop) }}')">
                                    <span @click="toggle($event)" class="inline-block px-2.5 rounded-full text-xs font-semibold"
                                          :style="'padding-top:2px; padding-bottom:2px; cursor: pointer; ' + badgeStyle"
                                          x-text="label" title="クリックで進捗ステータス変更"></span>
                                    <div x-show="open" x-cloak @click.outside="open = false"
                                         :style="'position: fixed; top: ' + popoverTop + 'px; left: ' + popoverLeft + 'px; transform: translateX(-50%); z-index: 9999; background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px; box-shadow: 0 6px 20px rgba(0,0,0,0.15); min-width: 130px; display: flex; flex-direction: column; gap: 4px;'">
                                        <template x-for="opt in options" :key="opt.value">
                                            <span @click="select(opt)" class="inline-block px-2.5 rounded-full text-xs font-semibold"
                                                  :style="'padding-top:2px; padding-bottom:2px; text-align: center; ' + opt.badge_style + ((opt.value === value) ? ' opacity: 0.45; cursor: default;' : ' cursor: pointer;')"
                                                  x-text="opt.label"></span>
                                        </template>
                                    </div>
                                </td>
                            @else
                                <td class="co-td co-sticky co-sticky-stat co-col-stat">
                                    <span class="inline-block px-2.5 rounded-full text-xs font-semibold" style="padding-top:2px; padding-bottom:2px; {{ $prop->getDisplayBadgeStyle() }}">{{ $prop->getDisplayStatusLabel() }}</span>
                                </td>
                            @endif

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
                                @if($tCost !== null)
                                    {{ number_format($tCost) }}円
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>
                            {{-- 合計: 粗利額 --}}
                            <td class="co-td co-num co-zone-t">
                                @if($tProfit !== null)
                                    <span style="{{ $tProfit >= 0 ? 'color: #047857; font-weight: 700;' : 'color: #dc2626; font-weight: 700;' }}">{{ number_format($tProfit) }}円</span>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>

                            {{-- 建物: 販売金額（税込サブ行あり）
                                 ⚠ getBuildingTax() は建物販売 null 時 0 なので、$bPrice の null ガード内でしか税込を出さない --}}
                            <td class="co-td co-num co-zone-b co-gstart">
                                @if($bPrice !== null)
                                    {{ number_format($bPrice) }}円
                                    <div class="co-tax-sub">税込 {{ number_format($prop->getBuildingSellingPriceWithTax()) }}円</div>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>
                            {{-- 建物: 原価額 --}}
                            <td class="co-td co-num co-zone-b">
                                @if($bCost !== null)
                                    {{ number_format($bCost) }}円
                                @else
                                    <span class="co-muted">—</span>
                                @endif
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

                            {{-- 土地: 販売金額（非課税なので税込サブ行なし） --}}
                            <td class="co-td co-num co-zone-l co-gstart">
                                @if($lPrice !== null)
                                    {{ number_format($lPrice) }}円
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>
                            {{-- 土地: 原価額 --}}
                            <td class="co-td co-num co-zone-l">
                                @if($lCost !== null)
                                    {{ number_format($lCost) }}円
                                @else
                                    <span class="co-muted">—</span>
                                @endif
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

                            {{-- 詳細（現状維持・12px に統一） --}}
                            <td class="co-td co-gstart">
                                <a href="{{ route('housing.properties.show', $prop) }}"
                                   style="display: inline-block; padding: 3px 12px; font-size: 12px; font-weight: 600; color: #b45309; border: 1px solid #b45309; border-radius: 5px; text-decoration: none; background: #fff;">詳細</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="14" class="px-3 py-8 text-center text-sm text-gray-500 border-b border-gray-100">該当する物件がありません</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($properties->hasPages())
        <div class="mt-4 flex justify-center gap-0.5">
            @if($properties->onFirstPage())
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
            @else
                <a href="{{ $properties->previousPageUrl() }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
            @endif
            @foreach($properties->getUrlRange(1, $properties->lastPage()) as $page => $url)
                @if($page == $properties->currentPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                @else
                    <a href="{{ $url }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                @endif
            @endforeach
            @if($properties->hasMorePages())
                <a href="{{ $properties->nextPageUrl() }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
            @else
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
            @endif
        </div>
    @endif

<script>
// 進捗ステータスポップオーバー: バッジクリックで全ステータスをバッジ色のまま表示し、選択で Ajax 即更新
window.__housingPropertyStatusOptions = @json($statusOptions);

function housingPropertyStatusCell(id, initialValue, initialLabel, initialBadgeStyle, contractCreateUrl) {
    return {
        id: id,
        value: initialValue,
        label: initialLabel,
        badgeStyle: initialBadgeStyle,
        contractCreateUrl: contractCreateUrl,
        open: false,
        submitting: false,
        // ポップオーバーは position:fixed で viewport 基準描画（親コンテナ overflow-hidden 回避）
        popoverTop: 0,
        popoverLeft: 0,
        options: window.__housingPropertyStatusOptions || [],

        toggle: function($event) {
            if (this.submitting) return;
            if (!this.open && $event && $event.currentTarget) {
                var rect = $event.currentTarget.getBoundingClientRect();
                this.popoverTop = rect.bottom + 6;
                this.popoverLeft = rect.left + rect.width / 2;
            }
            this.open = !this.open;
        },

        select: function(opt) {
            var self = this;
            if (opt.value === self.value) {
                self.open = false;
                return;
            }
            // 「成約」は enum 値ではなく契約レコードの有無で導かれる仮想ステータス。
            // クリック時は契約登録画面に遷移する（既存契約があれば契約登録画面側でガード）。
            if (opt.value === 'sold') {
                self.open = false;
                window.location.href = self.contractCreateUrl;
                return;
            }
            if (self.submitting) return;
            self.submitting = true;

            var token = document.querySelector('meta[name="csrf-token"]').content;

            fetch('{{ url("/housing/properties") }}/' + self.id + '/status', {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ status: opt.value })
            })
            .then(function(r) {
                if (!r.ok) {
                    self.submitting = false;
                    alert('進捗ステータスの更新に失敗しました（' + r.status + '）');
                    return null;
                }
                return r.json();
            })
            .then(function(data) {
                self.submitting = false;
                if (!data || !data.success) return;
                // サーバー側で成約済みなら label/style が "成約" 緑バッジに変換されて返ってくる
                self.value = data.status.value;
                self.label = data.status.label;
                self.badgeStyle = data.status.badge_style;
                self.open = false;
            })
            .catch(function() {
                self.submitting = false;
                alert('通信エラーが発生しました。');
            });
        }
    };
}
</script>

@endsection
