@extends('layouts.app')

@section('title', '注文住宅一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">注文住宅一覧</span>
@endsection

@section('content')

    {{-- ステップバーバッジのホバーエフェクト --}}
    <style>.badge-step-trigger:hover { box-shadow: 0 0 0 3px rgba(5,150,105,0.18); }</style>

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">注文住宅一覧</h1>
        <a href="{{ route('housing.custom-orders.create') }}"
           class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            新規登録
        </a>
    </div>



    {{-- フィルターバー --}}
    <form id="filter-form" method="GET" action="{{ route('housing.custom-orders.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <select name="status" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="" {{ request('status', '') === '' ? 'selected' : '' }}>ステータス: 全て</option>
            @foreach(\App\Enums\CustomOrderStatus::cases() as $st)
                <option value="{{ $st->value }}" {{ request('status') === $st->value ? 'selected' : '' }}>{{ $st->label() }}</option>
            @endforeach
        </select>
        <input type="text" name="keyword" value="{{ request('keyword') }}"
               placeholder="案件番号・案件名・顧客名・住所"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
        <a href="{{ route('housing.custom-orders.index') }}"
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
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">案件番号</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">進捗</th>
                        <th class="py-2.5 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="width: 100%; padding-left: 16px;">案件名</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">顧客名</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">請負金額</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">詳細</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($orders as $ord)
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-3 text-center border-b border-gray-100 whitespace-nowrap">
                                <a href="{{ route('housing.custom-orders.show', $ord) }}" class="text-sm font-semibold text-blue-700 underline">{{ $ord->order_code }}</a>
                            </td>
                            <td class="px-3 py-3 text-center border-b border-gray-100 whitespace-nowrap">
                                <span class="badge-step-trigger"
                                      data-code="{{ $ord->order_code }}"
                                      data-id="{{ $ord->id }}"
                                      data-step="{{ $ord->getStatusIndex() }}"
                                      onclick="openStepBar(this)"
                                      style="display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 12px; font-weight: 600; cursor: pointer; transition: box-shadow 0.15s; {{ $ord->getDisplayBadgeStyle() }}">{{ $ord->status->label() }}</span>
                            </td>
                            <td class="py-3 border-b border-gray-100" style="padding-left: 16px;">
                                <div class="text-sm font-semibold text-gray-900">{{ $ord->order_name }}</div>
                                <div class="text-xs text-gray-500">{{ $ord->address }}</div>
                            </td>
                            <td class="px-3 py-3 text-center text-sm text-gray-800 border-b border-gray-100 whitespace-nowrap">{{ $ord->customer_name }}</td>
                            <td class="px-3 py-3 text-sm border-b border-gray-100 whitespace-nowrap" style="text-align: right; padding-right: 24px;">
                                @if($ord->building_contract_price !== null)
                                    {{ number_format($ord->building_contract_price) }}円
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-center border-b border-gray-100 whitespace-nowrap">
                                <a href="{{ route('housing.custom-orders.show', $ord) }}"
                                   style="display: inline-block; padding: 3px 12px; font-size: 13px; font-weight: 600; color: #b45309; border: 1px solid #b45309; border-radius: 5px; background: #fff; text-decoration: none; cursor: pointer;">詳細</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-8 text-center text-sm text-gray-500 border-b border-gray-100">該当する案件がありません</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($orders->hasPages())
        <div class="mt-4 flex justify-center gap-0.5">
            @if($orders->onFirstPage())
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
            @else
                <a href="{{ $orders->previousPageUrl() }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
            @endif
            @foreach($orders->getUrlRange(1, $orders->lastPage()) as $page => $url)
                @if($page == $orders->currentPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                @else
                    <a href="{{ $url }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                @endif
            @endforeach
            @if($orders->hasMorePages())
                <a href="{{ $orders->nextPageUrl() }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
            @else
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
            @endif
        </div>
    @endif

    <div class="text-sm text-gray-500 text-right mt-2">全 {{ $orders->total() }} 件</div>

    {{-- ステップバー グローバルポップオーバー（body直下、position:fixed） --}}
    <div id="global-step-popover" style="position: fixed; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); z-index: 9000; padding: 16px 20px; display: none; white-space: nowrap;">
        <div id="global-step-bar" style="display: flex; align-items: center;"></div>
    </div>

@endsection

@push('scripts')
<script>
var STEPS = ['商談','設計','見積り','契約','着工','完成','引渡し'];
var STEP_VALUES = ['consultation','design','estimation','contracted','construction','completed','delivered'];
var popover = document.getElementById('global-step-popover');
var stepBar = document.getElementById('global-step-bar');
var currentBadge = null;
var csrfToken = '{{ csrf_token() }}';

function openStepBar(badge) {
    var wasOpen = popover.style.display === 'block' && currentBadge === badge;
    closePopover();
    if (wasOpen) return;

    currentBadge = badge;
    var orderId = badge.getAttribute('data-id');
    var code = badge.getAttribute('data-code');
    var activeIdx = parseInt(badge.getAttribute('data-step'), 10);

    var html = '';
    for (var i = 0; i < STEPS.length; i++) {
        var isDone = i < activeIdx;
        var isActive = i === activeIdx;

        // 円スタイル
        var circleStyle = 'width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;transition:all 0.15s;';
        if (isDone) {
            circleStyle += 'border:2px solid #059669;background:#059669;color:#fff;';
        } else if (isActive) {
            circleStyle += 'border:2px solid #059669;background:#ecfdf5;color:#059669;box-shadow:0 0 0 3px rgba(5,150,105,0.15);';
        } else {
            circleStyle += 'border:2px solid #d1d5db;background:#fff;color:#9ca3af;';
        }

        // ラベルスタイル
        var labelStyle = 'font-size:10px;margin-top:5px;font-weight:500;color:' + (isActive ? '#059669' : '#6b7280') + ';';
        if (isActive) labelStyle += 'font-weight:700;';

        var clickAttr = '';
        if (!isActive) {
            clickAttr = ' onclick="changeStatus(' + orderId + ',\'' + code + '\',\'' + STEP_VALUES[i] + '\',\'' + STEPS[i] + '\')" style="cursor:pointer;"';
        }

        html += '<div' + clickAttr + ' style="display:flex;flex-direction:column;align-items:center;padding:4px 0;' + (isActive ? '' : 'cursor:pointer;') + '">';
        html += '<div style="' + circleStyle + '">' + (i + 1) + '</div>';
        html += '<div style="' + labelStyle + '">' + STEPS[i] + '</div>';
        html += '</div>';

        if (i < STEPS.length - 1) {
            var lineColor = i < activeIdx ? '#059669' : '#d1d5db';
            html += '<div style="width:20px;height:2px;background:' + lineColor + ';margin-bottom:18px;flex-shrink:0;"></div>';
        }
    }
    stepBar.innerHTML = html;

    var rect = badge.getBoundingClientRect();
    popover.style.display = 'block';
    var popW = popover.offsetWidth;
    var popH = popover.offsetHeight;
    var left = rect.left + rect.width / 2 - popW / 2;
    var top = rect.bottom + 10;

    if (left + popW > window.innerWidth - 12) {
        left = window.innerWidth - popW - 12;
    }
    if (left < 12) { left = 12; }
    if (top + popH > window.innerHeight - 12) {
        top = rect.top - popH - 10;
    }

    popover.style.left = left + 'px';
    popover.style.top = top + 'px';
}

function changeStatus(orderId, code, statusValue, statusLabel) {
    if (!confirm(code + ' のステータスを「' + statusLabel + '」に変更しますか？')) {
        closePopover();
        return;
    }

    fetch('{{ url("/housing/custom-orders") }}/' + orderId + '/status', {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json'
        },
        body: JSON.stringify({ status: statusValue })
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            location.reload();
        } else {
            alert('エラーが発生しました。');
            closePopover();
        }
    })
    .catch(function() {
        alert('通信エラーが発生しました。');
        closePopover();
    });
}

function closePopover() {
    popover.style.display = 'none';
    currentBadge = null;
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.badge-step-trigger') && !e.target.closest('#global-step-popover')) {
        closePopover();
    }
});

window.addEventListener('scroll', function() { closePopover(); }, true);
</script>
@endpush
