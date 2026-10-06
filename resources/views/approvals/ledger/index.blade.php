@extends('layouts.app')

@section('title', '決裁台帳')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">決裁台帳</span>
@endsection

{{-- 決裁台帳（画面⑤。要件 10・段階4 設計書 §5.8・D19〜D22）。見られる申請だけ・下書きは出さない。
     ⚠ 件名・申請部門・金額は、申請者本人以外には最後に提出した中身（LedgerRow）。保存した日時は JapanTime::format（Bug #61）。
     ⚠ プルダウンは変えた瞬間に送る（CLAUDE.md の即時フィルタ）。文字と日付は「絞り込む」で送る。
     ⚠ 絞り込みの欄はスマホの幅でも 2 列（1 列だと欄だけで 1 画面を使い、結果が見えない）。 --}}
@section('content')
<div>
    <h1 class="text-lg font-bold text-gray-900 mb-2">決裁台帳</h1>
    <p class="text-[12px] text-gray-500 mb-4 max-w-[720px]">見られる申請を、決裁日の新しい順に並べます（決裁No の無い申請は発信日の順）。件名を押すと申請の詳細を開きます。</p>

    @if($filter->ignored !== [])
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-800" role="status">
            読み取れない条件があったので、外して探しました（{{ implode('・', $filter->ignored) }}）。
        </div>
    @endif

    <form id="filter-form" method="GET" action="{{ route('approvals.ledger.index') }}"
          class="grid grid-cols-2 lg:grid-cols-4 gap-x-3 gap-y-2.5 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-3">
        <label class="block text-[12px] text-gray-600">年度
            <select name="year" onchange="document.getElementById('filter-form').submit()" class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full">
                <option value="">すべて</option>
                @foreach($years as $year => $label)
                    <option value="{{ $year }}" {{ $filter->year === $year ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-[12px] text-gray-600">状態
            <select name="status" onchange="document.getElementById('filter-form').submit()" class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full">
                @foreach(\App\Support\Approval\LedgerFilter::STATUSES as $key => $label)
                    <option value="{{ $key }}" {{ $filter->status === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-[12px] text-gray-600">判断
            <select name="decision" onchange="document.getElementById('filter-form').submit()" class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full">
                <option value="">すべて</option>
                @foreach(\App\Enums\ApprovalDecision::cases() as $decision)
                    <option value="{{ $decision->value }}" {{ $filter->decision === $decision ? 'selected' : '' }}>{{ $decision->label() }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-[12px] text-gray-600">申請部門
            <select name="department" onchange="document.getElementById('filter-form').submit()" class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full">
                <option value="">すべて</option>
                @foreach($departments as $department)
                    <option value="{{ $department->id }}" {{ $filter->departmentId === $department->id ? 'selected' : '' }}>{{ $department->company->name }} / {{ $department->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-[12px] text-gray-600">申請の種類
            <select name="type" onchange="document.getElementById('filter-form').submit()" class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full">
                <option value="">すべて</option>
                @foreach($types as $type)
                    <option value="{{ $type->id }}" {{ $filter->typeId === $type->id ? 'selected' : '' }}>{{ $type->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-[12px] text-gray-600">申請者
            <input type="text" name="applicant" value="{{ $filter->applicant }}" maxlength="{{ \App\Support\Approval\LedgerFilter::APPLICANT_MAX }}" placeholder="名前の一部"
                   class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none w-full">
        </label>
        <div class="block text-[12px] text-gray-600 col-span-2 lg:col-span-1">決裁日の期間
            <div class="mt-1 flex items-center gap-1.5">
                <input type="date" name="from" value="{{ $filter->decidedFrom?->format('Y-m-d') }}" aria-label="決裁日（から）"
                       class="h-8 px-2 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none w-full min-w-0">
                <span aria-hidden="true">〜</span>
                <input type="date" name="to" value="{{ $filter->decidedTo?->format('Y-m-d') }}" aria-label="決裁日（まで）"
                       class="h-8 px-2 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none w-full min-w-0">
            </div>
        </div>
        <label class="block text-[12px] text-gray-600 col-span-2 lg:col-span-1">キーワード
            <input type="text" name="q" value="{{ $filter->keyword }}" maxlength="{{ \App\Support\Approval\LedgerFilter::KEYWORD_MAX }}" placeholder="件名・本文"
                   class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none w-full">
        </label>
        <div class="flex items-end gap-3 col-span-2 lg:col-span-4">
            <button type="submit" class="h-8 px-4 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[12px] font-semibold cursor-pointer">絞り込む</button>
            @if($filter->query() !== [])
                <a href="{{ route('approvals.ledger.index') }}" class="text-[12px] text-gray-500 hover:text-emerald-600 transition-colors pb-1.5">条件を消す</a>
            @endif
        </div>
    </form>

    <div class="bg-white rounded-lg border border-gray-200">
        <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 border-b border-gray-200">
            <p class="text-[13px] text-gray-700"><span class="font-semibold tabular-nums">{{ number_format($rows->total()) }}</span> 件</p>
        </div>

        @if($rows->isEmpty())
            <p class="px-4 py-8 text-center text-[13px] text-gray-400">該当する申請はありません。@if($filter->status === \App\Support\Approval\LedgerFilter::DEFAULT_STATUS)<br>状態は「{{ \App\Support\Approval\LedgerFilter::STATUSES[\App\Support\Approval\LedgerFilter::DEFAULT_STATUS] }}」で絞っています（進行中・取り下げは状態を変えると出ます）。@endif</p>
        @else
            {{-- スマホの幅では 1 件 1 枚のカード（要件 14.4。横スクロールにしない） --}}
            <ul class="md:hidden divide-y divide-gray-100">
                @foreach($rows as $row)
                    <li>
                        <a href="{{ route('approvals.requests.show', $row->id) }}" class="block px-4 py-3 hover:bg-gray-50">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $row->statusStyle }}">{{ $row->statusLabel }}</span>
                                @if($row->number)
                                    <span class="text-[12px] font-mono text-gray-600">{{ $row->number }}</span>
                                @endif
                            </div>
                            <p class="text-[14px] font-semibold text-gray-900 break-words">{{ $row->subject ?? '（件名なし）' }}</p>
                            <p class="text-[12px] text-gray-500 mt-0.5">{{ $row->departmentName ?? '—' }}・{{ $row->applicantName ?? '—' }}</p>
                            <p class="text-[12px] text-gray-700 mt-0.5">決裁日 {{ \App\Support\JapanTime::format($row->decidedAt, 'Y/m/d') ?? '—' }}{{ $row->decisionLabel ? '・' . $row->decisionLabel : '' }}{{ $row->amountLabel() ? '・' . $row->amountLabel() : '' }}</p>
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="hidden md:block">
                <div class="scroll-hint at-start">
                    <div class="scroll-hint-inner">
                <table class="w-full min-w-[960px] border-collapse">
                    <thead>
                        <tr>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">決裁No</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">決裁日</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">判断</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">件名</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 min-w-[8rem]">申請部門</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">申請者</th>
                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">金額（税抜）</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">状態</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-mono text-gray-700 whitespace-nowrap">{{ $row->number ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ \App\Support\JapanTime::format($row->decidedAt, 'Y/m/d') ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $row->decisionLabel ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px]">
                                    <a href="{{ route('approvals.requests.show', $row->id) }}" class="text-emerald-600 hover:underline break-words">{{ $row->subject ?? '（件名なし）' }}</a>
                                </td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $row->departmentName ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $row->applicantName ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums whitespace-nowrap">{{ $row->amountLabel() ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 whitespace-nowrap">
                                    <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $row->statusStyle }}">{{ $row->statusLabel }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                    </div>{{-- /scroll-hint-inner --}}
                    <div class="scroll-hint-text">← スクロールできます →</div>
                </div>{{-- /scroll-hint --}}
            </div>

            @include('approvals._pager', ['paginator' => $rows, 'pages' => $pages])
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
// 画面をもう一度見せたとき（ブラウザの「戻る」など）は、絞り込みの入力をサーバーが描いた状態＝表に使った条件へ戻す
// （docs/RULES.md Bug #65。戻ると、変えた後のプルダウンと前の条件のままの表・ページ送りが食い違う）。
// ⚠ event.persisted で絞らない（読み込み直して入力欄の値を戻したときも食い違う）
window.addEventListener('pageshow', function () {
    document.getElementById('filter-form').reset();
});
</script>
@endpush
