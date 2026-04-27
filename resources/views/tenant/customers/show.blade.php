@extends('layouts.app')

@section('title', '顧客詳細 — ' . $customer->code)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.customers.index') }}" class="hover:text-emerald-600 transition-colors">顧客一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $customer->code }}</span>
@endsection

@section('content')
<div x-data="{ showDeleteModal: false, contractTab: 'active' }">

    {{-- ヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-5">
        <div>
            <div class="flex items-center gap-2.5">
                <h1 class="text-lg font-bold text-gray-900">{{ $customer->code }}　{{ $customer->name }}</h1>
                @php
                    $badgeMap = [
                        'corporation' => 'badge-corporation',
                        'sole_proprietor' => 'badge-sole-proprietor',
                        'individual' => 'badge-individual',
                    ];
                @endphp
                <span class="badge {{ $badgeMap[$customer->customer_type->value] ?? 'badge-individual' }}">{{ $customer->customer_type->label() }}</span>
            </div>
            @if($customer->name_kana)
                <div class="text-sm text-gray-400 mt-0.5">{{ $customer->name_kana }}</div>
            @endif
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            <a href="{{ route('tenant.customers.index') }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">顧客一覧に戻る</a>
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('tenant.customers.edit', $customer) }}"
                   style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 6px; text-decoration: none; background: #fff;">編集</a>
                @if(auth()->user()->role->isExecutive() && ! $customer->hasContracts())
                    <button @click="showDeleteModal = true"
                            style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 6px; background: #fff; cursor: pointer;">削除</button>
                @endif
            @endif
        </div>
    </div>

    {{-- フラッシュメッセージ --}}
    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 font-medium">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 font-medium">{{ session('error') }}</div>
    @endif

    {{-- 基本情報カード --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">基本情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2.5">
            <div>
                <span class="il">代表者名</span>
                <div class="text-sm text-gray-900 mt-0.5">{{ $customer->representative ?? '—' }}</div>
            </div>
            <div>
                <span class="il">担当者名</span>
                <div class="text-sm text-gray-900 mt-0.5">{{ $customer->contact_person ?? '—' }}</div>
            </div>
            <div>
                <span class="il">電話番号</span>
                <div class="text-sm text-gray-900 mt-0.5">{{ $customer->phone ?? '—' }}</div>
            </div>
            <div>
                <span class="il">メールアドレス</span>
                <div class="text-sm mt-0.5">
                    @if($customer->email)
                        <a href="mailto:{{ $customer->email }}" class="text-emerald-600 hover:underline">{{ $customer->email }}</a>
                    @else
                        —
                    @endif
                </div>
            </div>
            <div class="sm:col-span-2">
                <span class="il">住所</span>
                <div class="text-sm text-gray-900 mt-0.5">
                    @if($customer->postal_code || $customer->address)
                        {{ $customer->postal_code ? '〒' . $customer->postal_code . '　' : '' }}{{ $customer->address ?? '' }}
                    @else
                        —
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- 備考 --}}
    @if($customer->notes)
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
            <div class="text-sm text-gray-700 whitespace-pre-wrap">{{ $customer->notes }}</div>
        </div>
    @endif

    {{-- 契約履歴 --}}
    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden mb-5">
        <div class="px-5 pt-4 pb-0">
            <div class="text-sm font-bold text-gray-800 pb-3 border-b border-gray-200">契約履歴</div>
        </div>
        {{-- タブ --}}
        <div class="flex gap-0 px-5 pt-2">
            <button @click="contractTab='active'" class="px-4 py-2 text-sm font-semibold rounded-t border-b-2 transition-colors cursor-pointer"
                    :class="contractTab==='active' ? 'text-emerald-700 border-emerald-600 bg-emerald-50' : 'text-gray-500 border-transparent hover:text-gray-700'">
                契約中（{{ $activeContracts->count() }}件）
            </button>
            <button @click="contractTab='terminated'" class="px-4 py-2 text-sm font-semibold rounded-t border-b-2 transition-colors cursor-pointer"
                    :class="contractTab==='terminated' ? 'text-emerald-700 border-emerald-600 bg-emerald-50' : 'text-gray-500 border-transparent hover:text-gray-700'">
                解約済み（{{ $terminatedContracts->count() }}件）
            </button>
        </div>

        {{-- 契約中テーブル --}}
        <div x-show="contractTab==='active'">
            @if($activeContracts->isEmpty())
                <div class="px-5 py-8 text-center text-sm text-gray-400">契約中の契約はありません。</div>
            @else
                <div style="overflow-x:auto">
                    <table class="w-full border-collapse" style="min-width:700px">
                        <thead>
                            <tr>
                                <th class="px-4 py-2.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">契約番号</th>
                                <th class="px-4 py-2.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">物件名</th>
                                <th class="px-4 py-2.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">区画</th>
                                <th class="px-4 py-2.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">店舗名</th>
                                <th class="px-4 py-2.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">契約日</th>
                                <th class="px-4 py-2.5 text-right text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">月額合計（税抜）</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($activeContracts as $contract)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-4 py-2.5 border-b border-gray-200">
                                        <a href="{{ route('tenant.contracts.show', $contract) }}" class="text-sm font-semibold text-emerald-600 hover:underline">{{ $contract->contract_number }}</a>
                                    </td>
                                    <td class="px-4 py-2.5 border-b border-gray-200">
                                        <a href="{{ route('tenant.properties.show', $contract->property) }}" class="text-sm text-emerald-600 hover:underline">{{ $contract->property->name }}</a>
                                    </td>
                                    <td class="px-4 py-2.5 border-b border-gray-200 text-sm text-gray-700">{{ $contract->unit->display_name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-200 text-sm text-gray-700">{{ $contract->store_name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-200 text-sm text-gray-700">{{ $contract->contract_date->format('Y/m/d') }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-200 text-sm text-right font-semibold text-gray-900">{{ number_format($contract->monthly_total) }}円</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- 解約済みテーブル --}}
        <div x-show="contractTab==='terminated'" x-cloak>
            @if($terminatedContracts->isEmpty())
                <div class="px-5 py-8 text-center text-sm text-gray-400">解約済みの契約はありません。</div>
            @else
                <div style="overflow-x:auto">
                    <table class="w-full border-collapse" style="min-width:600px">
                        <thead>
                            <tr>
                                <th class="px-4 py-2.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">契約番号</th>
                                <th class="px-4 py-2.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">物件名</th>
                                <th class="px-4 py-2.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">区画</th>
                                <th class="px-4 py-2.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">契約日</th>
                                <th class="px-4 py-2.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">解約日</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($terminatedContracts as $contract)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-4 py-2.5 border-b border-gray-200">
                                        <a href="{{ route('tenant.contracts.show', $contract) }}" class="text-sm font-semibold text-emerald-600 hover:underline">{{ $contract->contract_number }}</a>
                                    </td>
                                    <td class="px-4 py-2.5 border-b border-gray-200 text-sm text-gray-700">{{ $contract->property->name }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-200 text-sm text-gray-700">{{ $contract->unit->display_name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-200 text-sm text-gray-700">{{ $contract->contract_date->format('Y/m/d') }}</td>
                                    <td class="px-4 py-2.5 border-b border-gray-200 text-sm text-gray-700">{{ $contract->contract_end_date ? $contract->contract_end_date->format('Y/m/d') : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- 問合せ履歴 --}}
    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <div class="px-5 pt-4 pb-3">
            <div class="text-sm font-bold text-gray-800 pb-3 border-b border-gray-200">問合せ履歴（{{ $inquiries->count() }}件）</div>
        </div>
        @if($inquiries->isEmpty())
            <div class="px-5 py-8 text-center text-sm text-gray-400">問合せ履歴はありません。</div>
        @else
            <div style="overflow-x:auto">
                <table class="w-full border-collapse" style="min-width:600px">
                    <thead>
                        <tr>
                            <th class="px-4 py-2.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">問合せ番号</th>
                            <th class="px-4 py-2.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">物件名</th>
                            <th class="px-4 py-2.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">問合せ日</th>
                            <th class="px-4 py-2.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">経路</th>
                            <th class="px-4 py-2.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">ステータス</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($inquiries as $inquiry)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-2.5 border-b border-gray-200">
                                    <a href="{{ route('tenant.inquiries.show', $inquiry) }}" class="text-sm font-semibold text-emerald-600 hover:underline">{{ $inquiry->inquiry_number }}</a>
                                </td>
                                <td class="px-4 py-2.5 border-b border-gray-200 text-sm text-gray-700">{{ $inquiry->property->name }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-200 text-sm text-gray-700">{{ $inquiry->inquiry_date->format('Y/m/d') }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-200 text-sm text-center font-semibold text-gray-900">{{ $inquiry->source_label }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-200 text-center">
                                    <span class="badge {{ $inquiry->status->badgeClass() }}">{{ $inquiry->status->label() }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- 削除確認モーダル --}}
    <div x-show="showDeleteModal" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showDeleteModal = false">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4 p-6">
            <h3 class="text-base font-bold text-gray-900 mb-2">顧客を削除しますか？</h3>
            <p class="text-sm text-gray-600 mb-4">「{{ $customer->code }} {{ $customer->name }}」を削除します。この操作は取り消せません。</p>
            <div class="flex justify-end gap-2">
                <button @click="showDeleteModal = false"
                        class="px-4 py-2 bg-white text-gray-700 border border-gray-300 rounded-md text-sm hover:bg-gray-50 transition-colors cursor-pointer">キャンセル</button>
                <form method="POST" action="{{ route('tenant.customers.destroy', $customer) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="px-4 py-2 bg-red-600 text-white rounded-md text-sm font-semibold hover:bg-red-700 transition-colors cursor-pointer">削除する</button>
                </form>
            </div>
        </div>
    </div>

</div>
@endsection
