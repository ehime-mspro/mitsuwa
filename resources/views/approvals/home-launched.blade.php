@extends('layouts.app')

@section('title', '決裁申請')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">決裁申請</span>
@endsection

@section('content')
<div class="max-w-[960px]">

    @if(session('warning'))
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-800">
            {{ session('warning') }}
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">決裁申請</h1>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('approvals.requests.create') }}" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold">新しい申請</a>
            <a href="{{ route('approvals.requests.index') }}" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-700 hover:bg-gray-50">自分の申請</a>
        </div>
    </div>

    {{-- 対応待ち（古い順・待ち日数つき。自分の申請は部門長・審査・社長としては出さない。§5.12・D16・D20） --}}
    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">
            対応待ち <span class="ml-1 text-[12px] font-semibold text-emerald-700">{{ $pending->count() }} 件</span>
        </h2>
        @if($pending->isEmpty())
            <p class="px-5 py-6 text-[13px] text-gray-400">いま対応が必要な申請はありません。</p>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach($pending as $item)
                    @php $days = \App\Support\Approval\PendingWork::waitingDays($item['since']); @endphp
                    <li>
                        <a href="{{ route('approvals.requests.show', $item['request']) }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 hover:bg-gray-50">
                            <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="background: #dbeafe; color: #1e40af;">{{ $item['role'] }}・{{ $item['action'] }}</span>
                            <span class="text-[13px] font-semibold text-gray-900 break-words">{{ $item['request']->subject ?? '（件名なし）' }}</span>
                            <span class="text-[12px] text-gray-500">{{ $item['request']->applicant->name }}・{{ $item['request']->department?->name ?? '—' }}</span>
                            <span class="ml-auto text-[12px] text-gray-600 whitespace-nowrap">{{ $days === 0 ? '今日' : $days . ' 日待ち' }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- 自分の申請の進み具合（§5.12） --}}
    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">自分の申請の進み具合</h2>
        @if($inProgress->isEmpty() && $recentlyFinished->isEmpty())
            <p class="px-5 py-6 text-[13px] text-gray-400">まだ申請はありません。「新しい申請」から始めます。</p>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach($inProgress as $item)
                    <li>
                        <a href="{{ route('approvals.requests.show', $item) }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 hover:bg-gray-50">
                            <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $item->status->badgeStyle() }}">{{ $item->statusLabel() }}</span>
                            <span class="text-[13px] font-semibold text-gray-900 break-words">{{ $item->subject ?? '（件名なし）' }}</span>
                            <span class="ml-auto text-[12px] text-gray-600">いま: {{ \App\Support\Approval\CurrentHandler::label($item) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
            @if($recentlyFinished->isNotEmpty())
                <p class="px-5 pt-3 pb-1 text-[12px] font-semibold text-gray-500 border-t border-gray-100">最近の完了</p>
                <ul class="divide-y divide-gray-100">
                    @foreach($recentlyFinished as $item)
                        <li>
                            <a href="{{ route('approvals.requests.show', $item) }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-2.5 hover:bg-gray-50">
                                <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $item->status->badgeStyle() }}">{{ $item->statusLabel() }}</span>
                                <span class="text-[12px] font-mono text-gray-600">{{ $item->number }}</span>
                                <span class="text-[13px] text-gray-900 break-words">{{ $item->subject ?? '（件名なし）' }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
        <div class="px-5 py-3 border-t border-gray-100 text-right">
            <a href="{{ route('approvals.requests.index') }}" class="text-[13px] text-emerald-600 hover:underline">自分の申請をすべて見る</a>
        </div>
    </section>

    <div class="flex flex-wrap gap-3 text-[13px]">
        <a href="{{ route('password.change') }}" class="text-emerald-600 hover:underline">パスワードを変更する</a>
        @if($user->isApprovalAdmin())
            <a href="{{ route('approvals.admin.users.index') }}" class="text-emerald-600 hover:underline">利用者の管理</a>
            <a href="{{ route('approvals.admin.organization.index') }}" class="text-emerald-600 hover:underline">部門の管理</a>
            <a href="{{ route('approvals.admin.types.index') }}" class="text-emerald-600 hover:underline">申請種類の管理</a>
        @endif
    </div>
</div>
@endsection
