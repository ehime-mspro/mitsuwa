@extends('layouts.app')

@section('title', $record->number)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.ledger.index') }}" class="hover:text-emerald-600 transition-colors">決裁台帳</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">紙の決裁</span>
@endsection

{{-- 紙の決裁の詳細（段階5 設計書 §5.15・D21）。見られる人だけ。
     ⚠ 決裁日は日付（時刻なし）なので ->format() のまま（JapanTime::format で時差を足さない）
     ⚠ 金額が空の欄は「—」（0 円と分ける） --}}
@section('content')
@php
    $yen = fn (?int $value) => $value === null ? '—' : \App\Support\Approval\AmountTable::yen($value);
@endphp
<div class="max-w-[880px]">
    <div class="flex flex-wrap items-center gap-2 mb-1">
        <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold bg-gray-100 text-gray-600">紙</span>
        <span class="text-[13px] font-mono font-semibold text-gray-800">{{ $record->number }}</span>
        @if(Auth::user()->isApprovalAdmin())
            <a href="{{ route('approvals.admin.papers.edit', $record) }}" class="ml-auto inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md bg-white text-[12px] text-gray-700 hover:bg-gray-50">直す</a>
        @endif
    </div>
    <h1 class="text-lg font-bold text-gray-900 mb-2 break-words">{{ $record->subject }}</h1>
    <p class="mb-5 text-[13px] text-gray-600">紙の決裁です。原本は紙で保管しています。</p>

    <section class="bg-white rounded-lg border border-gray-200">
        <dl class="px-5 py-4 grid grid-cols-1 sm:grid-cols-[9em_1fr] gap-x-4 gap-y-2 text-[13px]">
            <dt class="text-gray-500">決裁No</dt>
            <dd class="text-gray-900 font-mono">{{ $record->number }}</dd>
            <dt class="text-gray-500">決裁日</dt>
            <dd class="text-gray-900">{{ $record->decided_on->format('Y/m/d') }}</dd>
            <dt class="text-gray-500">判断</dt>
            <dd class="text-gray-900">{{ \App\Models\ApprovalPaperRecord::DECISION_LABEL }}（紙の台帳に記録が無い）</dd>
            <dt class="text-gray-500">部門</dt>
            <dd class="text-gray-900">{{ $record->department->company->name }} / {{ $record->department->name }}</dd>
            <dt class="text-gray-500">申請者</dt>
            <dd class="text-gray-900">{{ $record->applicant_name ?? '—' }}</dd>
            <dt class="text-gray-500">金額</dt>
            <dd class="text-gray-900 tabular-nums">{{ $yen($record->amount) }}</dd>
            <dt class="text-gray-500">工事原価</dt>
            <dd class="text-gray-900 tabular-nums">{{ $yen($record->cost) }}</dd>
            <dt class="text-gray-500">粗利益</dt>
            <dd class="text-gray-900 tabular-nums">{{ $yen($record->profit) }}</dd>
            <dt class="text-gray-500">粗利率</dt>
            <dd class="text-gray-900 tabular-nums">{{ \App\Support\Approval\AmountTable::rateLabel($record->rate()) }}</dd>
            <dt class="text-gray-500">備考</dt>
            <dd class="text-gray-900 whitespace-pre-line break-words">{{ $record->note ?? '—' }}</dd>
        </dl>
    </section>
</div>
@endsection
