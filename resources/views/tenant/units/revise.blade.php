@extends('layouts.app')

@section('title', '募集家賃の改定: ' . $unit->display_name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.show', $unit->property) }}" class="hover:text-emerald-600 transition-colors">{{ $unit->property->name }}</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.units.show', $unit) }}" class="hover:text-emerald-600 transition-colors">区画: {{ $unit->display_name }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">賃料改定</span>
@endsection

@section('content')

    {{-- 戻るリンク --}}
    <a href="{{ route('tenant.units.show', $unit) }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        区画詳細に戻る
    </a>

    {{-- ページタイトル --}}
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">募集家賃の改定: {{ $unit->display_name }}</h1>

    {{-- 経営層のみの告知 --}}
    <div class="flex items-start gap-2 mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3.5">
        <svg class="w-5 h-5 text-blue-500 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <div class="text-sm text-blue-800">この操作は<strong>経営層のみ</strong>実行できます。改定内容は履歴に記録され、区画の募集条件が更新されます。</div>
    </div>

    {{-- バリデーションエラー --}}
    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-sm font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-xs text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 対象区画情報（読み取り専用） --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-4 lg:px-5 lg:py-4 mb-4">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3 border-b border-gray-200">対象区画</div>
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
            <div>
                <div class="text-xs text-gray-500 mb-0.5">物件 / 区画</div>
                <div class="text-sm font-medium text-gray-900">{{ $unit->property->name }} / {{ $unit->display_name }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">ステータス</div>
                <div class="text-sm font-medium text-gray-900">{{ $unit->status->label() }}</div>
            </div>
        </div>
    </div>

    {{-- 現在の募集条件（読み取り専用） --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-4 lg:px-5 lg:py-4 mb-4">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3 border-b border-gray-200">現在の募集条件</div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <div class="text-xs text-gray-500 mb-0.5">募集家賃</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($unit->rent) }}円</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">共益費</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($unit->common_fee) }}円</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">ゴミ代</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($unit->garbage_fee) }}円</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">駆除代</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($unit->pest_control_fee) }}円</div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('tenant.units.revise.execute', $unit) }}">
        @csrf

        {{-- 改定内容 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">改定内容</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">改定適用日<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="date" name="revision_date" value="{{ old('revision_date') }}"
                           class="form-input w-full sm:max-w-[240px] h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">新・募集家賃<span class="text-red-600 ml-0.5">*</span></label>
                    <div class="relative">
                        <input type="number" name="new_rent" value="{{ old('new_rent', $unit->rent) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">現在: {{ number_format($unit->rent) }}円</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">新・共益費</label>
                    <div class="relative">
                        <input type="number" name="new_common_fee" value="{{ old('new_common_fee', $unit->common_fee) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">現在: {{ number_format($unit->common_fee) }}円</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">新・ゴミ代</label>
                    <div class="relative">
                        <input type="number" name="new_garbage_fee" value="{{ old('new_garbage_fee', $unit->garbage_fee) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">現在: {{ number_format($unit->garbage_fee) }}円</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">新・駆除代</label>
                    <div class="relative">
                        <input type="number" name="new_pest_control_fee" value="{{ old('new_pest_control_fee', $unit->pest_control_fee) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">現在: {{ number_format($unit->pest_control_fee) }}円</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">改定理由</label>
                    <textarea name="reason" rows="3"
                              class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]"
                              placeholder="改定の理由を入力（任意）">{{ old('reason') }}</textarea>
                </div>
            </div>
        </div>

        {{-- アクションボタン --}}
        <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-2">
            <a href="{{ route('tenant.units.show', $unit) }}"
               class="px-4 py-2.5 bg-white text-gray-700 border border-gray-300 rounded-md text-sm text-center hover:bg-gray-50 transition-colors">
                キャンセル
            </a>
            <button type="submit"
                    class="inline-flex items-center justify-center gap-1.5 px-5 py-2.5 bg-emerald-600 text-white rounded-md text-sm font-semibold hover:bg-emerald-700 transition-colors cursor-pointer">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                賃料改定を実行する
            </button>
        </div>
    </form>
@endsection
