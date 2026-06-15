@extends('layouts.app')

@section('title', '顧客一覧（' . $deptLabel . '）')

@section('content')
<div class="text-sm text-gray-500" style="margin-bottom: 12px;">
    ダッシュボード &gt; {{ $deptLabel }} &gt; <span class="text-gray-800 font-medium">顧客一覧</span>
</div>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
    <h1 style="font-size: 20px; font-weight: 700; margin: 0;">顧客一覧（{{ $deptLabel }}）</h1>
    <a href="{{ route("{$department}.customers.create") }}" class="inline-flex items-center gap-1 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold px-4 py-2 rounded-md">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        新規登録
    </a>
</div>


{{-- フィルターバー --}}
<div class="bg-white border border-gray-200 rounded-lg" style="padding: 10px 16px; margin-bottom: 16px;">
    <form id="filter-form" method="GET" action="{{ route("{$department}.customers.index") }}">
        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <select name="rank" style="font-size: 13px; height: 34px; padding: 0 10px; width: auto; border: 1px solid #d1d5db; border-radius: 6px;"
                    onchange="document.getElementById('filter-form').submit()">
                <option value="active" {{ $rankFilter === 'active' ? 'selected' : '' }}>ランク: A〜D</option>
                <option value="all" {{ $rankFilter === 'all' ? 'selected' : '' }}>全て</option>
                @foreach(\App\Enums\BuyerRank::cases() as $r)
                    <option value="{{ $r->value }}" {{ $rankFilter === $r->value ? 'selected' : '' }}>{{ $r->label() }}</option>
                @endforeach
            </select>
            <input type="text" name="keyword" value="{{ $keyword }}" placeholder="氏名・フリガナ・電話番号で検索"
                   style="font-size: 13px; height: 34px; flex: 1; min-width: 200px; border: 1px solid #d1d5db; border-radius: 6px; padding: 0 12px;"
                   onkeydown="if(event.key==='Enter'){document.getElementById('filter-form').submit();}">
            <a href="{{ route("{$department}.customers.index") }}"
               style="background: #fff; color: #9ca3af; padding: 4px 12px; border-radius: 5px; font-size: 12px; font-weight: 400; border: 1px solid #d1d5db; white-space: nowrap; height: 34px; display: inline-flex; align-items: center; text-decoration: none;"
               onmouseover="this.style.color='#6b7280';this.style.borderColor='#9ca3af';"
               onmouseout="this.style.color='#9ca3af';this.style.borderColor='#d1d5db';">クリア</a>
        </div>
    </form>
</div>

{{-- テーブル --}}
<div class="bg-white border border-gray-200 rounded-lg" style="padding: 0; overflow: hidden;">
    <div style="overflow-x: auto;">
        <table style="border-collapse: collapse; width: 100%;">
            <thead>
                <tr>
                    <th style="background: #f9fafb; font-weight: 600; font-size: 12px; color: #4b5563; padding: 9px 12px; border-bottom: 2px solid #e5e7eb; white-space: nowrap; letter-spacing: 0.03em; text-align: center;">取得日</th>
                    @if($department === 'housing')
                        <th style="background: #f9fafb; font-weight: 600; font-size: 12px; color: #4b5563; padding: 9px 12px; border-bottom: 2px solid #e5e7eb; white-space: nowrap; letter-spacing: 0.03em; text-align: center;">来場分譲地</th>
                    @endif
                    <th style="background: #f9fafb; font-weight: 600; font-size: 12px; color: #4b5563; padding: 9px 12px; border-bottom: 2px solid #e5e7eb; white-space: nowrap; letter-spacing: 0.03em; text-align: center;">ランク</th>
                    <th style="background: #f9fafb; font-weight: 600; font-size: 12px; color: #4b5563; padding: 9px 12px; border-bottom: 2px solid #e5e7eb; white-space: nowrap; letter-spacing: 0.03em; text-align: left; padding-left: 16px;">氏名</th>
                    <th style="background: #f9fafb; font-weight: 600; font-size: 12px; color: #4b5563; padding: 9px 12px; border-bottom: 2px solid #e5e7eb; white-space: nowrap; letter-spacing: 0.03em; text-align: center;">住所</th>
                    <th style="background: #f9fafb; font-weight: 600; font-size: 12px; color: #4b5563; padding: 9px 12px; border-bottom: 2px solid #e5e7eb; white-space: nowrap; letter-spacing: 0.03em; text-align: center;">電話番号</th>
                    <th style="background: #f9fafb; font-weight: 600; font-size: 12px; color: #4b5563; padding: 9px 12px; border-bottom: 2px solid #e5e7eb; white-space: nowrap; letter-spacing: 0.03em; text-align: center;">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($buyers as $buyer)
                    @php
                        $pivot = $buyer->departments->first();
                    @endphp
                    <tr style="border-bottom: 1px solid #f0f0f0;" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background='#fff'">
                        <td style="font-size: 14px; padding: 11px 12px; white-space: nowrap; text-align: center;">
                            {{ $pivot ? $pivot->acquired_date->format('Y/m/d') : '—' }}
                        </td>
                        @if($department === 'housing')
                            <td style="font-size: 13px; padding: 11px 12px; white-space: nowrap; text-align: center;">
                                {{ $projectNames[$buyer->id] ?? '—' }}
                            </td>
                        @endif
                        <td style="font-size: 14px; padding: 11px 12px; white-space: nowrap; text-align: center;">
                            @if($pivot)
                                <span class="rank-badge"
                                      data-buyer-id="{{ $buyer->id }}"
                                      data-department="{{ $department }}"
                                      style="display: inline-block; padding: 3px 12px; border-radius: 4px; font-size: 12px; font-weight: 700; cursor: pointer; {{ $pivot->rank_badge_style }}"
                                      onclick="openRankDropdown(this, {{ $buyer->id }}, '{{ $department }}')">{{ $pivot->rank->label() }}</span>
                            @endif
                        </td>
                        <td style="font-size: 14px; padding: 11px 12px; white-space: nowrap; text-align: left; padding-left: 16px;">
                            <div style="font-weight: 600;">{{ $buyer->full_name }}</div>
                            @if($buyer->full_name_kana)
                                <div style="font-size: 11px; color: #6b7280;">{{ $buyer->full_name_kana }}</div>
                            @endif
                        </td>
                        <td style="font-size: 14px; padding: 11px 12px; white-space: nowrap; text-align: center;">
                            {{ ($buyer->prefecture ?? '') . ($buyer->city ?? '') ?: '—' }}
                        </td>
                        <td style="font-size: 14px; padding: 11px 12px; white-space: nowrap; text-align: center;">
                            {{ $buyer->phone ?: '—' }}
                        </td>
                        <td style="font-size: 14px; padding: 11px 12px; white-space: nowrap; text-align: center;">
                            <a href="{{ route("{$department}.customers.show", $buyer) }}"
                               style="background: #fff; color: #b45309; padding: 4px 12px; border-radius: 5px; font-size: 13px; font-weight: 600; border: 1px solid #b45309; text-decoration: none; display: inline-block;"
                               onmouseover="this.style.background='#fffbeb'" onmouseout="this.style.background='#fff'">詳細</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $department === 'housing' ? 7 : 6 }}" style="text-align: center; padding: 40px; color: #9ca3af; font-size: 14px;">
                            該当する顧客はありません
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($buyers->hasPages())
        <div class="flex justify-center gap-0.5" style="padding: 12px 16px; border-top: 1px solid #e5e7eb;">
            @if($buyers->onFirstPage())
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
            @else
                <a href="{{ $buyers->previousPageUrl() }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
            @endif
            @foreach($buyers->getUrlRange(1, $buyers->lastPage()) as $page => $url)
                @if($page == $buyers->currentPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                @else
                    <a href="{{ $url }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                @endif
            @endforeach
            @if($buyers->hasMorePages())
                <a href="{{ $buyers->nextPageUrl() }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
            @else
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
            @endif
        </div>
    @endif
</div>

@if($department === 'realestate')
    @php
        $hasRealestateQuestions = \App\Models\SurveyQuestion::ofDepartment('realestate')->active()->exists();
    @endphp
    @if(!$hasRealestateQuestions)
        <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 14px 18px; font-size: 13px; color: #0c4a6e; margin-top: 16px;">
            ℹ️ 不動産事業のアンケートは現在未設定です。<a href="{{ route('admin.survey-questions.index', ['department' => 'realestate']) }}" style="color: #1d4ed8; text-decoration: underline;">マスタ管理 &gt; アンケート設問管理</a>から設問を追加すると、顧客登録時にアンケートが利用可能になります。
        </div>
    @endif
@endif

{{-- ランク変更ドロップダウン（グローバル1つ — body直下、position:fixed） --}}
<div id="rank-dropdown-global" style="display: none; position: fixed; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); z-index: 9000; padding: 6px; min-width: 150px;">
    @foreach(\App\Enums\BuyerRank::cases() as $r)
        @if($r === \App\Enums\BuyerRank::Lost)
            <div style="border-top: 1px solid #e5e7eb; margin: 4px 0;"></div>
        @endif
        <div class="rank-dropdown-item" data-rank="{{ $r->value }}"
             style="padding: 6px 12px; font-size: 13px; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 8px;"
             onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='transparent'"
             onclick="selectRank('{{ $r->value }}')">
            <span style="width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; background: {{ $r->dotColor() }};"></span>
            {{ $r->fullLabel() }}
        </div>
    @endforeach
</div>

<script>
var _rankDropdown = document.getElementById('rank-dropdown-global');
var _currentBadge = null;
var _currentBuyerId = null;
var _currentDepartment = null;

function openRankDropdown(badge, buyerId, department) {
    if (_rankDropdown.style.display === 'block' && _currentBuyerId === buyerId) {
        _rankDropdown.style.display = 'none';
        return;
    }
    _currentBadge = badge;
    _currentBuyerId = buyerId;
    _currentDepartment = department;

    var rect = badge.getBoundingClientRect();
    _rankDropdown.style.top = (rect.bottom + 6) + 'px';
    _rankDropdown.style.left = rect.left + 'px';
    _rankDropdown.style.display = 'block';
}

function selectRank(rank) {
    if (!_currentBuyerId) return;

    var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var xhr = new XMLHttpRequest();
    xhr.open('PATCH', '{{ url("/api/customers") }}/' + _currentBuyerId + '/rank');
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.setRequestHeader('X-CSRF-TOKEN', token);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.onload = function() {
        if (xhr.status === 200) {
            var data = JSON.parse(xhr.responseText);
            _currentBadge.textContent = data.label;
            _currentBadge.style.cssText = 'display: inline-block; padding: 3px 12px; border-radius: 4px; font-size: 12px; font-weight: 700; cursor: pointer; ' + data.badgeStyle;
        }
    };
    xhr.send(JSON.stringify({rank: rank, department: _currentDepartment}));

    _rankDropdown.style.display = 'none';
}

document.addEventListener('click', function(e) {
    if (!e.target.classList.contains('rank-badge') && !e.target.closest('#rank-dropdown-global')) {
        _rankDropdown.style.display = 'none';
    }
});
</script>
@endsection
