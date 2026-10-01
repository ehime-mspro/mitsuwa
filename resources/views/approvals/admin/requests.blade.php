@extends('layouts.app')

@section('title', '進行中の申請の管理')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">進行中の申請の管理</span>
@endsection

{{-- 進行中の申請の管理（画面⑩・決裁の管理者。段階2 設計書 §5.14）。
     操作（付け替え・押し間違いの取り消し・代理の取り下げ）は各申請の詳細の画面で行う（2b 計画 §0.8）。
     ⚠ 件名・申請部門は最後に提出した控えのもの（差戻し中の直しかけを出さない。D26）。保存した日時は JapanTime::format（Bug #61） --}}
@section('content')
<div>
    <h1 class="text-lg font-bold text-gray-900 mb-2">進行中の申請の管理</h1>
    <p class="text-[12px] text-gray-500 mb-4 max-w-[720px]">止まっている申請を見つけて、詳細の画面で部門長の確認の付け替え・押し間違いの取り消し・申請者に代わっての取り下げを行います。決裁したあとの押し間違いは「決裁済み・否決」から開きます。</p>

    <nav class="flex flex-wrap gap-1.5 mb-4" aria-label="表示の切り替え">
        <a href="{{ route('approvals.admin.requests.index') }}" @if($tab === 'progress') aria-current="page" @endif
           class="px-3 py-1.5 rounded-full text-[12px] font-semibold border {{ $tab === 'progress' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">進行中</a>
        <a href="{{ route('approvals.admin.requests.index', ['tab' => 'decided']) }}" @if($tab === 'decided') aria-current="page" @endif
           class="px-3 py-1.5 rounded-full text-[12px] font-semibold border {{ $tab === 'decided' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">決裁済み・否決</a>
    </nav>

    <div class="bg-white rounded-lg border border-gray-200">
        @if($tab === 'progress')
            @if($rows->isEmpty())
                <p class="px-4 py-8 text-center text-[13px] text-gray-400">進行中の申請はありません。</p>
            @else
                {{-- スマホの幅では 1 件 1 枚のカード（要件 14.4。横スクロールにしない） --}}
                <ul class="md:hidden divide-y divide-gray-100">
                    @foreach($rows as $row)
                        <li>
                            <a href="{{ route('approvals.requests.show', $row['request']) }}" class="block px-4 py-3 hover:bg-gray-50">
                                <div class="flex flex-wrap items-center gap-2 mb-1">
                                    <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $row['request']->status->badgeStyle() }}">{{ $row['request']->statusLabel() }}</span>
                                    <span class="text-[12px] font-semibold text-gray-700">{{ $row['days'] }} 日</span>
                                    @foreach($row['flags'] as $flag)
                                        <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold bg-red-50 text-red-700">{{ $flag }}</span>
                                    @endforeach
                                </div>
                                <p class="text-[14px] font-semibold text-gray-900 break-words">{{ $row['subject'] ?? '（件名なし）' }}</p>
                                <p class="text-[12px] text-gray-500 mt-0.5">{{ $row['request']->applicant->name }}・{{ $row['department'] ?? '—' }}{{ $row['request']->number ? '・' . $row['request']->number : '' }}</p>
                                <p class="text-[12px] text-gray-700 mt-0.5">いま: {{ $row['handler'] }}</p>
                            </a>
                        </li>
                    @endforeach
                </ul>

                <div class="hidden md:block">
                    <div class="scroll-hint at-start">
                        <div class="scroll-hint-inner">
                    <table class="w-full min-w-[900px] border-collapse">
                        <thead>
                            <tr>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">待ち日数</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">決裁No</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">件名</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">申請者</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">申請部門</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">状態</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">いま誰の番か</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-semibold text-gray-900 whitespace-nowrap tabular-nums">{{ $row['days'] }} 日</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-mono text-gray-700 whitespace-nowrap">{{ $row['request']->number ?? '—' }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px]">
                                        <a href="{{ route('approvals.requests.show', $row['request']) }}" class="text-emerald-600 hover:underline break-words">{{ $row['subject'] ?? '（件名なし）' }}</a>
                                    </td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $row['request']->applicant->name }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $row['department'] ?? '—' }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 whitespace-nowrap">
                                        <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $row['request']->status->badgeStyle() }}">{{ $row['request']->statusLabel() }}</span>
                                    </td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">
                                        {{ $row['handler'] }}
                                        @foreach($row['flags'] as $flag)
                                            <span class="ml-1 inline-block px-2 py-0.5 rounded text-[11px] font-semibold bg-red-50 text-red-700">{{ $flag }}</span>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                        </div>{{-- /scroll-hint-inner --}}
                        <div class="scroll-hint-text">← スクロールできます →</div>
                    </div>{{-- /scroll-hint --}}
                </div>
            @endif
        @else
            @if($requests->isEmpty())
                <p class="px-4 py-8 text-center text-[13px] text-gray-400">決裁済み・否決の申請はありません。</p>
            @else
                <ul class="md:hidden divide-y divide-gray-100">
                    @foreach($requests as $item)
                        @php $snapshot = $item->revisions->firstWhere('round', $item->round)?->snapshot ?? []; @endphp
                        <li>
                            <a href="{{ route('approvals.requests.show', $item) }}" class="block px-4 py-3 hover:bg-gray-50">
                                <div class="flex flex-wrap items-center gap-2 mb-1">
                                    <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $item->status->badgeStyle() }}">{{ $item->statusLabel() }}</span>
                                    <span class="text-[12px] font-mono text-gray-600">{{ $item->number }}</span>
                                </div>
                                <p class="text-[14px] font-semibold text-gray-900 break-words">{{ $snapshot['subject'] ?? '（件名なし）' }}</p>
                                <p class="text-[12px] text-gray-500 mt-0.5">{{ $item->applicant->name }}・{{ $snapshot['department']['name'] ?? '—' }}・決裁日 {{ \App\Support\JapanTime::format($item->decided_at, 'Y/m/d') ?? '—' }}</p>
                            </a>
                        </li>
                    @endforeach
                </ul>

                <div class="hidden md:block">
                    <div class="scroll-hint at-start">
                        <div class="scroll-hint-inner">
                    <table class="w-full min-w-[760px] border-collapse">
                        <thead>
                            <tr>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">決裁No</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">件名</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">申請者</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">申請部門</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">状態</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">決裁日</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($requests as $item)
                                @php $snapshot = $item->revisions->firstWhere('round', $item->round)?->snapshot ?? []; @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-mono text-gray-700 whitespace-nowrap">{{ $item->number }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px]">
                                        <a href="{{ route('approvals.requests.show', $item) }}" class="text-emerald-600 hover:underline break-words">{{ $snapshot['subject'] ?? '（件名なし）' }}</a>
                                    </td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $item->applicant->name }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $snapshot['department']['name'] ?? '—' }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 whitespace-nowrap">
                                        <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $item->status->badgeStyle() }}">{{ $item->statusLabel() }}</span>
                                    </td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ \App\Support\JapanTime::format($item->decided_at, 'Y/m/d') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                        </div>{{-- /scroll-hint-inner --}}
                        <div class="scroll-hint-text">← スクロールできます →</div>
                    </div>{{-- /scroll-hint --}}
                </div>

                @include('approvals._pager', ['paginator' => $requests, 'pages' => $pages])
            @endif
        @endif
    </div>
</div>
@endsection
