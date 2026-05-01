@extends('layouts.app')

@section('title', $client->name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>DAD</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('dad.clients.index') }}" class="text-emerald-600 hover:text-emerald-700">発注者管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $client->name }}</span>
@endsection

@section('content')

    {{-- ヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <div class="flex items-center gap-3">
            <span style="display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; {{ $client->client_type->badgeStyle() }}">{{ $client->client_type->label() }}</span>
            <h1 class="text-lg font-bold text-gray-900">{{ $client->name }}</h1>
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            <a href="{{ route('dad.clients.index') }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">発注者一覧に戻る</a>
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('dad.clients.edit', $client) }}"
                   style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 6px; text-decoration: none; background: #fff;">編集</a>
            @endif
            @if(auth()->user()->role->isExecutive())
                <form method="POST" action="{{ route('dad.clients.destroy', $client) }}"
                      onsubmit="return confirm('この発注者を削除しますか？')">
                    @csrf @method('DELETE')
                    <button type="submit"
                            style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 6px; background: #fff; cursor: pointer;">削除</button>
                </form>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
            <p class="text-sm text-emerald-800">{{ session('success') }}</p>
        </div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
            <p class="text-sm text-red-800">{{ session('error') }}</p>
        </div>
    @endif

    {{-- 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-4">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">基本情報</h2>
        </div>
        <div class="border border-gray-200 rounded-md overflow-hidden" style="display: grid; grid-template-columns: 120px 1fr 120px 1fr;">
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">種別</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $client->client_type->label() }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">発注者名</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200 font-semibold">{{ $client->name }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">代表者</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $client->representative ?: '—' }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">電話番号</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $client->phone ?: '—' }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">FAX</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $client->fax ?: '—' }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">メール</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $client->email ?: '—' }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">郵便番号</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $client->postal_code ?: '—' }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">工事件数</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200" style="font-variant-numeric: tabular-nums;">{{ $projects->count() }}件</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-r border-gray-200">住所</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900" style="grid-column: span 3;">{{ $client->address ?: '—' }}</dd>
        </div>
    </div>

    {{-- 備考 --}}
    @if($client->notes)
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-3">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">備考</h2>
        </div>
        <div class="text-sm text-gray-800 leading-relaxed whitespace-pre-wrap">{{ $client->notes }}</div>
    </div>
    @endif

    {{-- 関連工事案件 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5">
        <div class="flex items-center gap-2 mb-4">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">関連する工事案件</h2>
            <span class="text-xs text-gray-500 ml-1">（{{ $projects->count() }}件）</span>
        </div>

        @if($projects->isEmpty())
            <div style="padding: 24px; text-align: center; font-size: 13px; color: #9ca3af; background: #f9fafb; border-radius: 6px;">
                この発注者で登録された工事案件はまだありません。
            </div>
        @else
            <div class="border border-gray-200 rounded-md overflow-hidden" style="overflow-x: auto;">
                <table class="w-full border-collapse">
                    <thead>
                        <tr>
                            <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">案件番号</th>
                            <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">ステータス</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding-left: 16px;">工事名</th>
                            <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">受注額</th>
                            <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">工期</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">担当者</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($projects as $p)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-2.5 border-b border-gray-100 text-center whitespace-nowrap">
                                    <a href="{{ route('dad.projects.show', $p) }}" class="text-sm font-semibold text-emerald-600 hover:underline" style="font-variant-numeric: tabular-nums;">{{ $p->project_code }}</a>
                                </td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-center whitespace-nowrap">
                                    <span style="display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; {{ $p->status->badgeStyle() }}">{{ $p->status->label() }}</span>
                                </td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-sm font-medium" style="padding-left: 16px;">{{ $p->project_name }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-sm text-right whitespace-nowrap" style="font-variant-numeric: tabular-nums;">{{ $p->contract_amount !== null ? number_format($p->contract_amount) . '円' : '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-xs text-center text-gray-600 whitespace-nowrap">
                                    {{ optional($p->period_start)->format('Y/n/j') ?: '—' }}
                                    〜
                                    {{ optional($p->period_end)->format('Y/n/j') ?: '—' }}
                                </td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-sm whitespace-nowrap">{{ optional($p->staffUser)->name ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

@endsection
