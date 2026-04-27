@extends('layouts.app')

@section('title', '収支一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">収支管理</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-700 font-medium">収支一覧</span>
@endsection

@section('content')
{{-- テーブル共通スタイル --}}
<style>
    .dt { width:100%; border-collapse:collapse; table-layout:fixed; }
    .dt th, .dt td { padding:12px 16px; font-size:14px; color:#374151; border-bottom:1px solid #e5e7eb; }
    .dt th { font-weight:700; color:#4b5563; background:#f9fafb; }
    .dt .al { text-align:left; }
    .dt .ac { text-align:center; }
    .dt .bold { font-weight:700; }
    .dt .total td { background:#fefce8; border-top:2px solid #d1d5db; border-bottom:none; font-weight:700; color:#1f2937; }
</style>

    <h1 class="text-lg font-bold text-gray-900 mb-5">収支一覧</h1>

    {{-- フィルター --}}
    <form method="GET" action="{{ route('transactions.index') }}"
          class="flex flex-wrap items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <label class="text-sm font-semibold text-gray-700">対象月:</label>
        <input type="month" name="ym" value="{{ $yearMonth }}"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none">
        <button type="submit"
                class="h-9 px-5 bg-gray-50 border-2 border-gray-400 rounded-md text-sm font-semibold text-gray-700 hover:bg-gray-100 hover:border-gray-500 transition-colors cursor-pointer">
            表示
        </button>
    </form>

    {{-- サマリーカード --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-4">
        <div class="grid grid-cols-3 gap-4">
            <div>
                <div class="text-sm text-gray-600 font-medium mb-1">月間賃料収入（税抜）</div>
                <div class="text-2xl font-bold text-gray-900">¥{{ number_format($totalAmount) }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-600 font-medium mb-1">入居件数</div>
                <div class="text-2xl font-bold text-gray-900">{{ $totalContracts }}<span class="text-sm font-normal text-gray-500 ml-0.5">件</span></div>
            </div>
            <div>
                <div class="text-sm text-gray-600 font-medium mb-1">稼働ビル数</div>
                <div class="text-2xl font-bold text-gray-900">{{ $activePropertyCount }}<span class="text-sm font-normal text-gray-500 ml-0.5">棟</span></div>
            </div>
        </div>
    </div>

    {{-- 物件別テーブル --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="dt" style="min-width:760px;">
                <colgroup>
                    <col style="width:24%">
                    <col style="width:8%">
                    <col style="width:16%">
                    <col style="width:13%">
                    <col style="width:11%">
                    <col style="width:11%">
                    <col style="width:17%">
                </colgroup>
                <thead>
                    <tr>
                        <th class="al">物件名</th>
                        <th class="ac">入居数</th>
                        <th class="ac">家賃</th>
                        <th class="ac">共益費</th>
                        <th class="ac">ゴミ代</th>
                        <th class="ac">駆除代</th>
                        <th class="ac">月額合計（税抜）</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($revenues as $rev)
                        <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('tenant.properties.show', $rev['property']) }}'">
                            <td class="al">
                                <a href="{{ route('tenant.properties.show', $rev['property']) }}" class="text-emerald-600 font-semibold hover:underline">{{ $rev['property']->name }}</a>
                                @if(! $rev['is_active'])
                                    <span style="display:inline-block;padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:600;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;margin-left:6px;">非稼働</span>
                                @endif
                            </td>
                            <td class="ac">{{ $rev['contract_count'] }}</td>
                            <td class="ac">¥{{ number_format($rev['rent']) }}</td>
                            <td class="ac">¥{{ number_format($rev['common_fee']) }}</td>
                            <td class="ac">¥{{ number_format($rev['garbage_fee']) }}</td>
                            <td class="ac">¥{{ number_format($rev['pest_control_fee']) }}</td>
                            <td class="ac bold">¥{{ number_format($rev['total']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="ac" style="padding:40px 16px;color:#9ca3af;">
                                {{ $yearMonth }} の賃料収入データはありません。
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if($revenues->isNotEmpty())
                    <tfoot>
                        <tr class="total">
                            <td class="al">合計</td>
                            <td class="ac">{{ $totalContracts }}</td>
                            <td class="ac">¥{{ number_format($totalRent) }}</td>
                            <td class="ac">¥{{ number_format($totalCommon) }}</td>
                            <td class="ac">¥{{ number_format($totalGarbage) }}</td>
                            <td class="ac">¥{{ number_format($totalPest) }}</td>
                            <td class="ac">¥{{ number_format($totalAmount) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    <p class="text-sm text-gray-500 mt-3">※ 金額は税抜き表示。契約中の賃料を固定収入として集計。敷金・滞納は含みません。</p>

@endsection
