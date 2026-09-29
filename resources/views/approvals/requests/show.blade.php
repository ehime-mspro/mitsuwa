@extends('layouts.app')

@section('title', $approvalRequest->number ?? '申請の詳細')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">申請の詳細</span>
@endsection

@section('content')
<div class="max-w-[880px]">

    {{-- 成功・失敗の帯はレイアウトが出す。$errors（コメントの長さなど）だけここで出す --}}
    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-2 mb-1">
        <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $approvalRequest->status->badgeStyle() }}">{{ $approvalRequest->statusLabel() }}</span>
        @if($approvalRequest->number)
            <span class="text-[13px] font-mono font-semibold text-gray-800">{{ $approvalRequest->number }}</span>
        @elseif($approvalRequest->status !== \App\Enums\ApprovalStatus::Withdrawn)
            {{-- 取り下げた申請には番号が付かないので言わない（取り下げは社長の判断の前だけ。Task 19 の B7） --}}
            <span class="text-[12px] text-gray-400">決裁No は社長の判断のときに付きます</span>
        @endif
    </div>
    <h1 class="text-lg font-bold text-gray-900 mb-4 break-words">{{ $content->subject ?? '（件名なし）' }}</h1>

    {{-- 中身は RequestContent（申請者以外には最後に提出した控え。差戻し中の直しかけは出し直すまで申請者だけ。利用者の決定 2026-09-27） --}}
    @if($content->isLastSubmission && $approvalRequest->status === \App\Enums\ApprovalStatus::Returned)
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-800">
            申請者が直しています。ここには最後に提出した中身（{{ $approvalRequest->round }} 回目の提出）を出しています。
        </div>
    @endif

    @include('approvals.requests._actions')

    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">申請の中身</h2>
        <dl class="px-5 py-4 grid grid-cols-1 sm:grid-cols-[9em_1fr] gap-x-4 gap-y-2 text-[13px]">
            <dt class="text-gray-500">申請者</dt>
            <dd class="text-gray-900">{{ $approvalRequest->applicant->name }}</dd>
            <dt class="text-gray-500">申請部門</dt>
            <dd class="text-gray-900">{{ $content->departmentName ?? '—' }}</dd>
            <dt class="text-gray-500">申請の種類</dt>
            <dd class="text-gray-900">{{ $content->typeName ?? '—' }}</dd>
            <dt class="text-gray-500">発信日</dt>
            <dd class="text-gray-900">{{ \App\Support\JapanTime::format($approvalRequest->last_submitted_at, 'Y/m/d') ?? '—' }}</dd>
            <dt class="text-gray-500">決裁日</dt>
            <dd class="text-gray-900">{{ \App\Support\JapanTime::format($approvalRequest->decided_at, 'Y/m/d') ?? '—' }}</dd>
            <dt class="text-gray-500">金額（税抜）</dt>
            <dd class="text-gray-900">{{ $content->amountLabel() ?? '—' }}</dd>
            <dt class="text-gray-500">実施時期</dt>
            <dd class="text-gray-900 break-words">{{ $content->schedule ?? '—' }}</dd>
            <dt class="text-gray-500">関連する決裁No</dt>
            <dd class="text-gray-900">
                @forelse($content->relatedNumbers as $number)
                    {{-- 見られる申請はリンクにする（紙の時代の番号・見られない申請は文字だけ。設計書 §5.6） --}}
                    @if(isset($relatedLinks[$number]))
                        <a href="{{ route('approvals.requests.show', $relatedLinks[$number]) }}" class="font-mono text-emerald-600 hover:underline mr-2">{{ $number }}</a>
                    @else
                        <span class="font-mono mr-2">{{ $number }}</span>
                    @endif
                @empty
                    —
                @endforelse
            </dd>
        </dl>
        <div class="px-5 pb-5">
            <p class="text-[12px] font-semibold text-gray-500 mb-1.5">重点ポイント（5W2H）</p>
            <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-[13px] text-gray-900 leading-relaxed whitespace-pre-wrap break-words">{{ $content->body }}</div>
        </div>
    </section>

    @include('approvals.requests._changes')

    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">添付</h2>
        <ul class="px-5 py-3 space-y-1.5 text-[13px]">
            {{-- 中身と同じ版の添付（申請者以外には最後に提出した控えの添付。RequestContent） --}}
            @forelse($content->attachments as $attachment)
                <li class="flex flex-wrap items-center gap-x-2">
                    {{-- 開くたびに見られる範囲を確かめ、記録する（§5.7） --}}
                    <a href="{{ route('approvals.attachments.show', $attachment) }}" target="_blank" rel="noopener" class="text-emerald-600 hover:underline break-all">{{ $attachment->original_name }}</a>
                    <span class="text-[11px] text-gray-400">{{ $attachment->sizeLabel() }}{{ $attachment->opensInline() ? '' : '・ダウンロード' }}</span>
                </li>
            @empty
                <li class="text-gray-400">添付はありません。</li>
            @endforelse
        </ul>
    </section>

    @include('approvals.requests._steps')

    {{-- 操作の記録（新しい順。記録は提出から付く。§5.11・§5.12） --}}
    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">操作の記録</h2>
        <ol class="px-5 py-3 space-y-2.5 text-[13px]">
            @forelse($histories as $history)
                <li>
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="text-[12px] text-gray-400 tabular-nums">{{ \App\Support\JapanTime::format($history->created_at) }}</span>
                        <span class="font-semibold text-gray-900">{{ $history->label() }}</span>
                        @if($history->actor)
                            <span class="text-gray-600">{{ $history->actor->name }}</span>
                        @endif
                        {{-- 部門長の交代は、担当が移った先の人を添える（横の名前は交代を操作した管理者。Task 19 の C9） --}}
                        @if($history->action === 'head_changed' && isset($newHeadNames[$history->meta['to_user_id'] ?? 0]))
                            <span class="text-gray-600">新しい担当: {{ $newHeadNames[$history->meta['to_user_id']] }}</span>
                        @endif
                    </div>
                    @if($history->comment)
                        <p class="mt-0.5 text-gray-700 whitespace-pre-wrap break-words">{{ $history->comment }}</p>
                    @endif
                </li>
            @empty
                <li class="text-gray-400">まだ記録はありません（提出から記録します）。</li>
            @endforelse
        </ol>
    </section>

    @include('approvals.requests._history')

</div>
@endsection
