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
            <option value="all" {{ request('status', 'all') === 'all' ? 'selected' : '' }}>ステータス: 全て</option>
            @foreach(\App\Enums\HousingPropertyStatus::cases() as $st)
                <option value="{{ $st->value }}" {{ request('status', 'all') === $st->value ? 'selected' : '' }}>{{ $st->label() }}</option>
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
                        <th class="py-2.5 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding-left: 16px;">物件名</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">進捗</th>
                        <th class="py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="text-align: right; padding-right: 16px;">土地面積</th>
                        <th class="py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="text-align: right; padding-right: 16px;">建物面積</th>
                        <th class="py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="text-align: right; padding-right: 16px;">販売価格</th>
                        <th class="py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="text-align: right; padding-right: 16px;">原価額</th>
                        <th class="py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="text-align: right; padding-right: 16px;">粗利額</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">粗利率</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">詳細</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($properties as $prop)
                        @php
                            $sellingTotal = $prop->getSellingPriceTotal();
                            $totalCost = $prop->getTotalCost();
                            $grossProfit = $prop->getGrossProfit();
                            $grossProfitRate = $prop->getGrossProfitRate();
                        @endphp
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="py-3 border-b border-gray-100" style="padding-left: 16px;">
                                <div class="text-sm font-semibold text-gray-900">{{ $prop->property_name }}</div>
                                <div class="text-xs text-gray-500">{{ $prop->address }}</div>
                            </td>
                            @if($canEditStatus)
                                <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap"
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
                                <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                    <span class="inline-block px-2.5 rounded-full text-xs font-semibold" style="padding-top:2px; padding-bottom:2px; {{ $prop->getDisplayBadgeStyle() }}">{{ $prop->getDisplayStatusLabel() }}</span>
                                </td>
                            @endif
                            <td class="px-3 py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($prop->land_area_sqm)
                                    {{ $prop->land_area_sqm }}㎡
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($prop->building_area_sqm)
                                    {{ $prop->building_area_sqm }}㎡
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($sellingTotal !== null)
                                    {{ number_format($sellingTotal) }}円
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($totalCost !== null)
                                    {{ number_format($totalCost) }}円
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm font-semibold whitespace-nowrap" style="text-align: right; padding-right: 16px; {{ $grossProfit !== null && $grossProfit >= 0 ? 'color: #059669;' : ($grossProfit !== null ? 'color: #dc2626;' : '') }}">
                                @if($grossProfit !== null)
                                    {{ number_format($grossProfit) }}円
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm font-semibold text-center whitespace-nowrap" style="{{ $grossProfitRate !== null && $grossProfitRate >= 0 ? 'color: #059669;' : ($grossProfitRate !== null ? 'color: #dc2626;' : '') }}">
                                @if($grossProfitRate !== null)
                                    {{ $grossProfitRate }}%
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                <a href="{{ route('housing.properties.show', $prop) }}"
                                   style="display: inline-block; padding: 3px 10px; font-size: 13px; font-weight: 600; color: #b45309; border: 1px solid #b45309; border-radius: 5px; text-decoration: none; background: #fff;">詳細</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-3 py-8 text-center text-sm text-gray-500">該当する物件がありません</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($properties->hasPages())
        <div class="mt-4">
            {{ $properties->links() }}
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
