@extends('layouts.app')

@section('title', $subcontractor->company_name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>DAD</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('dad.subcontractors.index') }}" class="text-emerald-600 hover:text-emerald-700">協力業者管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $subcontractor->company_name }}</span>
@endsection

@section('content')

    {{-- ヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <div class="flex items-center gap-3">
            @if($subcontractor->specialty)
                <span style="display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; background: #e0e7ff; color: #3730a3;">{{ $subcontractor->specialty->name }}</span>
            @endif
            <h1 class="text-lg font-bold text-gray-900">{{ $subcontractor->company_name }}</h1>
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            <a href="{{ route('dad.subcontractors.index') }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">協力業者一覧に戻る</a>
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('dad.subcontractors.edit', $subcontractor) }}"
                   style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 6px; text-decoration: none; background: #fff;">編集</a>
            @endif
            @if(auth()->user()->role->isExecutive())
                <form method="POST" action="{{ route('dad.subcontractors.destroy', $subcontractor) }}"
                      onsubmit="return confirm('この協力業者を削除しますか？')">
                    @csrf @method('DELETE')
                    <button type="submit"
                            style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 6px; background: #fff; cursor: pointer;">削除</button>
                </form>
            @endif
        </div>
    </div>


    {{-- 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-4">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">基本情報</h2>
        </div>
        <div class="border border-gray-200 rounded-md overflow-hidden" style="display: grid; grid-template-columns: 120px 1fr 120px 1fr;">
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">会社名</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200 font-semibold">{{ $subcontractor->company_name }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">専門分野</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $subcontractor->specialty?->name ?: '—' }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">代表者</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $subcontractor->representative ?: '—' }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">電話番号</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $subcontractor->phone ?: '—' }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">FAX</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $subcontractor->fax ?: '—' }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">メール</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $subcontractor->email ?: '—' }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">郵便番号</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200" style="grid-column: span 3;">{{ $subcontractor->postal_code ?: '—' }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-r border-gray-200">住所</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900" style="grid-column: span 3;">{{ $subcontractor->address ?: '—' }}</dd>
        </div>
    </div>

    {{-- 備考 --}}
    @if($subcontractor->notes)
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-3">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">備考</h2>
        </div>
        <div class="text-sm text-gray-800 leading-relaxed whitespace-pre-wrap">{{ $subcontractor->notes }}</div>
    </div>
    @endif

    {{-- 発注履歴（工事案件別） --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5">
        <div class="flex items-center gap-2 mb-4">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">発注履歴（工事案件別）</h2>
            <span class="text-xs text-gray-500 ml-1">（{{ $projectOrders->count() }}件）</span>
        </div>

        @if($projectOrders->isEmpty())
            <div style="padding: 24px; text-align: center; font-size: 13px; color: #9ca3af; background: #f9fafb; border-radius: 6px;">
                この協力業者への発注実績はまだありません。
            </div>
        @else
            <div class="border border-gray-200 rounded-md overflow-hidden" style="overflow-x: auto;">
                <table class="w-full border-collapse">
                    <thead>
                        <tr>
                            <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">案件番号</th>
                            <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">ステータス</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding-left: 16px;">工事名</th>
                            <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">明細件数</th>
                            <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">見積額合計</th>
                            <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">実績額合計</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($projectOrders as $o)
                            @php($statusEnum = \App\Enums\DadProjectStatus::tryFrom($o->project_status))
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-2.5 border-b border-gray-100 text-center whitespace-nowrap">
                                    <a href="{{ route('dad.projects.show', $o->project_id) }}" class="text-sm font-semibold text-emerald-600 hover:underline" style="font-variant-numeric: tabular-nums;">{{ $o->project_code }}</a>
                                </td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-center whitespace-nowrap">
                                    @if($statusEnum)
                                        <span style="display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; {{ $statusEnum->badgeStyle() }}">{{ $statusEnum->label() }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-sm font-medium" style="padding-left: 16px;">{{ $o->project_name }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-sm text-center whitespace-nowrap">{{ $o->orders_count }}件</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-sm text-right whitespace-nowrap" style="font-variant-numeric: tabular-nums;">{{ number_format((int) $o->estimate_total) }}円</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-sm text-right whitespace-nowrap" style="font-variant-numeric: tabular-nums;">{{ ((int) $o->actual_total) > 0 ? number_format((int) $o->actual_total) . '円' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr style="background: #f9fafb;">
                            <td colspan="3" class="px-4 py-2.5 text-sm font-bold text-gray-700 border-t-2 border-gray-200">合計</td>
                            <td class="px-4 py-2.5 text-sm text-center font-bold text-gray-700 border-t-2 border-gray-200">{{ $projectOrders->sum('orders_count') }}件</td>
                            <td class="px-4 py-2.5 text-sm text-right font-bold text-gray-700 border-t-2 border-gray-200" style="font-variant-numeric: tabular-nums;">{{ number_format((int) $projectOrders->sum('estimate_total')) }}円</td>
                            <td class="px-4 py-2.5 text-sm text-right font-bold text-gray-700 border-t-2 border-gray-200" style="font-variant-numeric: tabular-nums;">{{ ((int) $projectOrders->sum('actual_total')) > 0 ? number_format((int) $projectOrders->sum('actual_total')) . '円' : '—' }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>

@endsection
