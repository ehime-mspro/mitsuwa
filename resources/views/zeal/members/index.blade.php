@extends('layouts.app')

@section('title', '会員一覧 — ZEAL')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.dashboard') }}" class="hover:text-emerald-600 transition-colors">ZEAL</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">会員一覧</span>
@endsection

@section('content')

<style>
    .badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .badge-active   { background: #d1fae5; color: #065f46; }
    .badge-withdrew { background: #f3f4f6; color: #6b7280; }
    .badge-pair     { background: #ede9fe; color: #5b21b6; }

    /* 横スクロール明示 */
    .scroll-hint {
        display: inline-flex; align-items: center; gap: 6px;
        font-size: 11px; font-weight: 600;
        color: #047857; background: #ecfdf5;
        border: 1px solid #6ee7b7;
        padding: 4px 12px; border-radius: 9999px;
        margin-bottom: 8px;
    }
    .scroll-hint .arrow { display: inline-block; animation: scrollHintBob 1.6s ease-in-out infinite; }
    @keyframes scrollHintBob {
        0%, 100% { transform: translateX(0); }
        50%      { transform: translateX(4px); }
    }
    .scroll-wrap { position: relative; }
    .scroll-area { overflow-x: auto; scrollbar-width: thin; scrollbar-color: #6ee7b7 #f3f4f6; }
    .scroll-area::-webkit-scrollbar { height: 10px; }
    .scroll-area::-webkit-scrollbar-track { background: #f3f4f6; }
    .scroll-area::-webkit-scrollbar-thumb { background: #6ee7b7; border-radius: 5px; border: 2px solid #f3f4f6; }
    .scroll-area::-webkit-scrollbar-thumb:hover { background: #34d399; }
    .scroll-fade-right {
        position: absolute; top: 0; right: 0; bottom: 10px; width: 48px;
        pointer-events: none;
        background: linear-gradient(to right, rgba(255,255,255,0), rgba(255,255,255,0.95) 70%, rgba(255,255,255,1));
        border-top-right-radius: 8px;
        z-index: 2; opacity: 1; transition: opacity 0.2s ease;
    }
    .scroll-fade-right.is-end { opacity: 0; }
    .scroll-fade-right::after {
        content: '›';
        position: absolute; top: 50%; right: 8px; transform: translateY(-50%);
        font-size: 22px; font-weight: 700; color: #059669;
        text-shadow: 0 0 8px white;
    }
</style>

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
    <h1 style="font-size: 20px; font-weight: 700; margin: 0;">会員一覧</h1>
</div>

{{-- フィルターバー --}}
<form id="filter-form" method="GET" action="{{ route('zeal.members.index') }}"
      class="bg-white border border-gray-200 rounded-lg"
      style="padding: 12px 14px; margin-bottom: 16px;">
    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">

        {{-- ステータス --}}
        <select name="status" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white">
            <option value="active"    {{ $status === 'active'    ? 'selected' : '' }}>在籍中</option>
            <option value="withdrew"  {{ $status === 'withdrew'  ? 'selected' : '' }}>退会済み</option>
            <option value="all"       {{ $status === 'all'       ? 'selected' : '' }}>すべて</option>
        </select>

        {{-- プラン --}}
        <select name="plan_id" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white">
            <option value="">プラン: すべて</option>
            @foreach($plans as $plan)
                <option value="{{ $plan->id }}" {{ request('plan_id') == $plan->id ? 'selected' : '' }}>
                    {{ $plan->display_name }}
                </option>
            @endforeach
        </select>

        {{-- 性別 --}}
        <select name="gender" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white">
            <option value="">性別: すべて</option>
            @foreach(\App\Enums\ZealGender::cases() as $g)
                <option value="{{ $g->value }}" {{ request('gender') === $g->value ? 'selected' : '' }}>
                    {{ $g->label() }}
                </option>
            @endforeach
        </select>

        {{-- 入会月 --}}
        <select name="joined_month" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white">
            <option value="">入会月: すべて</option>
            @foreach($joinedMonths as $ym)
                <option value="{{ $ym }}" {{ request('joined_month') === $ym ? 'selected' : '' }}>{{ $ym }}</option>
            @endforeach
        </select>

        {{-- キーワード --}}
        <input type="text" name="keyword" value="{{ request('keyword') }}"
               placeholder="氏名・フリガナ・電話・メール"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white"
               style="flex: 1; min-width: 180px;">

        {{-- クリア --}}
        <a href="{{ route('zeal.members.index') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 inline-flex items-center whitespace-nowrap">
            クリア
        </a>
    </div>
</form>

{{-- フラッシュメッセージは layouts/app.blade.php でグローバル描画 --}}

{{-- 件数 --}}
<div style="font-size: 12px; color: #6b7280; margin-bottom: 12px;">
    @if($status === 'active')
        在籍中
    @elseif($status === 'withdrew')
        退会済み
    @else
        全会員
    @endif
    <b style="color: #047857; font-weight: 700;">{{ number_format($members->total()) }}名</b> を表示
    （{{ $members->firstItem() }}〜{{ $members->lastItem() }}件 / 全{{ number_format($members->total()) }}件）
</div>

{{-- 横スクロール明示ヒント --}}
<div class="scroll-hint" id="zeal-members-scroll-hint">
    <span>横にスクロールして全項目を表示</span>
    <span class="arrow">→</span>
</div>

{{-- テーブル --}}
<div class="scroll-wrap bg-white rounded-lg border border-gray-200">
    <div class="scroll-fade-right" id="zeal-members-scroll-fade"></div>
    <div class="scroll-area" id="zeal-members-scroll-area">
    <table class="border-collapse" style="font-size: 13px; min-width: 860px; width: 100%;">
        <thead>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">氏名</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">フリガナ</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">性別</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">年齢</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">プラン</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">入会日</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">退会日</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($members as $member)
                <tr class="hover:bg-gray-50 transition-colors" style="{{ $member->withdrew_on ? 'opacity: 0.6;' : '' }}">
                    <td class="px-4 py-3 whitespace-nowrap">
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <span style="font-weight: 600; color: #111827;">{{ $member->name }}</span>
                            @if($member->withdrew_on)
                                <span class="badge badge-withdrew">退会</span>
                            @else
                                <span class="badge badge-active">在籍中</span>
                            @endif
                            @if($member->pair_parent_member_id)
                                <span class="badge badge-pair">ペア</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap" style="color: #6b7280;">{{ $member->name_kana }}</td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        {{ $member->gender?->label() ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        {{ $member->age() !== null ? $member->age() . '歳' : '—' }}
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap" style="font-weight: 600; color: #047857;">
                        {{ $member->currentPlan?->display_name ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        {{ $member->joined_on?->format('Y-m-d') ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-center whitespace-nowrap" style="color: #6b7280;">
                        {{ $member->withdrew_on?->format('Y-m-d') ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        <a href="{{ route('zeal.members.show', $member) }}"
                           class="inline-block px-3 py-1 bg-white text-emerald-600 border border-emerald-600 rounded text-xs font-semibold hover:bg-emerald-50 transition-colors">詳細</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-400">
                        該当する会員が見つかりません。
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
    </div>{{-- /.scroll-area --}}
</div>{{-- /.scroll-wrap --}}

{{-- ページネーション --}}
@if($members->hasPages())
    <div style="margin-top: 16px;">
        {{ $members->links() }}
    </div>
@endif

<script>
    // 右端 fade をスクロール余地があるときだけ表示
    (function () {
        var area = document.getElementById('zeal-members-scroll-area');
        var fade = document.getElementById('zeal-members-scroll-fade');
        if (!area || !fade) return;
        function update() {
            var hasMore = area.scrollWidth - area.clientWidth > 2;
            var atEnd   = area.scrollLeft + area.clientWidth >= area.scrollWidth - 2;
            fade.style.display = hasMore ? '' : 'none';
            fade.classList.toggle('is-end', atEnd);
            var hint = document.getElementById('zeal-members-scroll-hint');
            if (hint) hint.style.display = hasMore ? '' : 'none';
        }
        area.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        update();
    })();
</script>

@endsection
