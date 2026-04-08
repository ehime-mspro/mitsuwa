@extends('layouts.app')

@section('title', $supplier->supplier_code . ' ' . $supplier->name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>不動産管理</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('realestate.suppliers.index') }}" class="hover:text-emerald-600 transition-colors">仕入れ先一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $supplier->supplier_code }}</span>
@endsection

@section('content')

    {{-- ヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <div class="flex items-center gap-3">
            <h1 class="text-lg font-bold text-gray-900">{{ $supplier->supplier_code }}</h1>
            <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">{{ $supplier->type->label() }}</span>
        </div>
        <div class="flex gap-2">
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('realestate.suppliers.edit', $supplier) }}"
                   class="px-3.5 py-1.5 bg-white border-2 border-gray-400 text-gray-700 text-sm font-semibold rounded-md hover:bg-gray-50 transition-colors"
                   style="font-size: 13px;">編集</a>
            @endif
            @if(auth()->user()->role->isExecutive())
                <form method="POST" action="{{ route('realestate.suppliers.destroy', $supplier) }}"
                      onsubmit="return confirm('この仕入れ先を削除しますか？')">
                    @csrf @method('DELETE')
                    <button type="submit"
                            class="px-3.5 py-1.5 bg-red-600 text-white text-xs font-semibold rounded-md hover:bg-red-700 transition-colors cursor-pointer"
                            style="font-size: 12px;">削除</button>
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
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">区分</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $supplier->type->label() }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">名前</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200 font-semibold">{{ $supplier->name }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">担当者名</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $supplier->contact_person ?? '—' }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">電話番号</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $supplier->phone ?? '—' }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">メール</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $supplier->email ?? '—' }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">郵便番号</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $supplier->postal_code ?? '—' }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-r border-gray-200">住所</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900" style="grid-column: span 3;">{{ $supplier->address ?? '—' }}</dd>
        </div>
    </div>

    {{-- 備考 --}}
    @if($supplier->notes)
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-3">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">備考</h2>
        </div>
        <div class="text-sm text-gray-800 leading-relaxed whitespace-pre-wrap">{{ $supplier->notes }}</div>
    </div>
    @endif

    {{-- 関連案件 --}}
    @if($procurements->isNotEmpty())
    <div class="bg-white border border-gray-200 rounded-lg p-5">
        <div class="flex items-center gap-2 mb-4">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">関連する仕入れ案件</h2>
        </div>
        <div class="border border-gray-200 rounded-md overflow-hidden" style="overflow-x: auto;">
            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">案件番号</th>
                        <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">ステータス</th>
                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding-left: 16px;">物件名</th>
                        <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">情報入手日</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($procurements as $p)
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-4 py-2.5 border-b border-gray-100 text-center whitespace-nowrap">
                                <a href="{{ route('realestate.procurements.show', $p) }}" class="text-sm font-semibold text-emerald-600 hover:underline">{{ $p->procurement_code }}</a>
                            </td>
                            <td class="px-4 py-2.5 border-b border-gray-100 text-center whitespace-nowrap">
                                <span class="badge {{ $p->status->badgeClass() }}">{{ $p->status->label() }}</span>
                            </td>
                            <td class="px-4 py-2.5 border-b border-gray-100 text-sm font-medium whitespace-nowrap" style="padding-left: 16px;">{{ $p->property_name }}</td>
                            <td class="px-4 py-2.5 border-b border-gray-100 text-sm text-center whitespace-nowrap">{{ $p->info_obtained_date?->format('Y/m/d') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

{{-- 不動産ステータスバッジCSS --}}
<style>
.badge-re-info { background: #dbeafe; color: #1e40af; }
.badge-re-survey { background: #e0e7ff; color: #3730a3; }
.badge-re-assess { background: #fce7f3; color: #9d174d; }
.badge-re-negotiate { background: #fed7aa; color: #9a3412; }
.badge-re-contracted { background: #fef3c7; color: #92400e; }
.badge-re-settled { background: #a7f3d0; color: #064e3b; }
.badge-re-selling { background: #c7d2fe; color: #3730a3; }
.badge-re-lost { background: #e5e7eb; color: #374151; }
</style>

@endsection
