@extends('layouts.app')

@section('title', '過去の決裁の取り込み')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">過去の決裁の取り込み</span>
@endsection

{{-- 取り込んだ紙の決裁の一覧と、取り込みの記録・取り消し（画面⑪。段階5 設計書 §5.10・D9）。決裁の管理者だけ・使い始める前から（D11）。
     決裁No から 1 件ずつの直し・削除の画面へ
     ⚠ 決裁日は日付（時刻なし）なので ->format() のまま。取り込んだ日時・取り消した日時は保存した日時なので JapanTime::format（Bug #61）
     ⚠ プルダウンは変えた瞬間に送る（CLAUDE.md の即時フィルタ） --}}
@section('content')
<div>
    @include('approvals._mail_failure')

    {{-- 成功・失敗の帯はレイアウトが出す（ここで出すと画面に 2 回出る）。$errors だけ各ビューの責任 --}}
    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <h1 class="text-lg font-bold text-gray-900 mb-1">過去の決裁の取り込み</h1>
    <p class="text-[12px] text-gray-500 mb-4 max-w-[720px]">紙の決裁台帳（年度ごとの Excel）から取り込んだ決裁です。決裁台帳で「紙」として申請と一緒に並び、関連する決裁No から開けます。回覧の操作はできません。</p>

    <div class="flex flex-wrap gap-2 mb-4">
        <a href="{{ route('approvals.admin.papers.import') }}" class="inline-flex items-center h-9 px-4 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold">Excel の台帳から取り込む</a>
        <a href="{{ route('approvals.admin.papers.create') }}" class="inline-flex items-center h-9 px-4 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-md text-[13px] font-semibold">1 件ずつ登録</a>
    </div>

    <form id="paper-filter" method="GET" action="{{ route('approvals.admin.papers.index') }}"
          class="flex flex-wrap items-end gap-3 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-3">
        <label class="block text-[12px] text-gray-600">年度
            <select name="year" onchange="document.getElementById('paper-filter').submit()" class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer">
                <option value="">すべて</option>
                @foreach($yearChoices as $value => $label)
                    <option value="{{ $value }}" {{ $year === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-[12px] text-gray-600">部門
            <select name="department" onchange="document.getElementById('paper-filter').submit()" class="mt-1 h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer">
                <option value="">すべて</option>
                @foreach($departments as $item)
                    <option value="{{ $item->id }}" {{ $department === $item->id ? 'selected' : '' }}>{{ $item->company->name }} / {{ $item->name }}（{{ $item->code }}）</option>
                @endforeach
            </select>
        </label>
        @if($year !== null || $department !== null)
            <a href="{{ route('approvals.admin.papers.index') }}" class="text-[12px] text-gray-500 hover:text-emerald-600 transition-colors pb-1.5">条件を消す</a>
        @endif
    </form>

    <section class="bg-white rounded-lg border border-gray-200 mb-6">
        <div class="px-4 py-2.5 border-b border-gray-200">
            <p class="text-[13px] text-gray-700"><span class="font-semibold tabular-nums">{{ number_format($records->total()) }}</span> 件</p>
        </div>
        @if($records->isEmpty())
            <p class="px-4 py-8 text-center text-[13px] text-gray-400">紙の決裁はありません。</p>
        @else
            <div class="scroll-hint at-start">
                <div class="scroll-hint-inner">
                    <table class="w-full min-w-[760px] border-collapse">
                        <thead>
                            <tr>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">決裁No</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">決裁日</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">部門</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">申請者</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">件名</th>
                                <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">金額</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($records as $record)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-mono whitespace-nowrap">
                                        <a href="{{ route('approvals.admin.papers.edit', $record) }}" class="text-emerald-700 hover:underline" title="直す">{{ $record->number }}</a>
                                    </td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $record->decided_on->format('Y/m/d') }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $record->department->name }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $record->applicant_name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-900 break-words">{{ $record->subject }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums whitespace-nowrap">{{ \App\Support\Approval\AmountTable::yen($record->amount) ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>{{-- /scroll-hint-inner --}}
                <div class="scroll-hint-text">← スクロールできます →</div>
            </div>{{-- /scroll-hint --}}
            @include('approvals._pager', ['paginator' => $records, 'pages' => $pages])
        @endif
    </section>

    {{-- 取り込みの記録（新しい順） --}}
    <section class="bg-white rounded-lg border border-gray-200">
        <h2 class="px-4 py-2.5 border-b border-gray-200 text-[13px] font-bold text-gray-900">取り込みの記録</h2>
        @if($imports->isEmpty())
            <p class="px-4 py-6 text-center text-[13px] text-gray-400">まだ取り込んでいません。</p>
        @else
            <div class="scroll-hint at-start">
                <div class="scroll-hint-inner">
                    <table class="w-full min-w-[760px] border-collapse">
                        <thead>
                            <tr>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">取り込んだ日時</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">ファイル</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">年度</th>
                                <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">足した</th>
                                <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">金額を補った</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">取り込んだ人</th>
                                <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">取り消し</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($imports as $import)
                                <tr>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ \App\Support\JapanTime::format($import->created_at, 'Y/m/d H:i') }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 break-all">{{ $import->file_name }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $years[$import->fiscal_year] ?? $import->fiscal_year }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums">{{ number_format($import->added_count) }} 件</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums">{{ number_format($import->filled_count) }} 件</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $import->importer?->name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">
                                        @if($import->undone_at !== null)
                                            {{ \App\Support\JapanTime::format($import->undone_at, 'Y/m/d H:i') }} に {{ $import->undoer?->name ?? '—' }} が取り消し（{{ number_format((int) $import->undone_count) }} 件を消した）
                                        @elseif($import->records_count === 0)
                                            —（この回に足した記録は残っていません）
                                        @else
                                            {{-- 取り消し（D9）: 今の件数と、そのあと手で直した件数を見せて確かめる。見せた件数を送り、変わっていれば断る --}}
                                            <form method="POST" action="{{ route('approvals.admin.papers.imports.undo', $import) }}" class="inline"
                                                  onsubmit="return confirm('この取り込みで足した {{ $import->records_count }} 件を消します。{{ $import->edited_count > 0 ? "そのあと手で直した {$import->edited_count} 件も消えます。" : '' }}{{ $import->filled_count > 0 ? "金額を補った {$import->filled_count} 件は、記録も金額も残ります。" : '' }}次に付く番号は戻りません。よろしいですか。');">
                                                @csrf
                                                <input type="hidden" name="count_shown" value="{{ $import->records_count }}">
                                                <input type="hidden" name="edited_shown" value="{{ $import->edited_count }}">
                                                <button type="submit" class="px-2.5 py-1 bg-white border border-red-300 text-red-700 hover:bg-red-50 rounded-md text-[12px] font-semibold cursor-pointer">取り消す（今 {{ $import->records_count }} 件{{ $import->edited_count > 0 ? "・うち手で直した {$import->edited_count} 件" : '' }}）</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>{{-- /scroll-hint-inner --}}
                <div class="scroll-hint-text">← スクロールできます →</div>
            </div>{{-- /scroll-hint --}}
        @endif
    </section>
</div>
@endsection
