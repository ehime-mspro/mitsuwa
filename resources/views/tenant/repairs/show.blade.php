@extends('layouts.app')

@section('title', '修繕詳細')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.repairs.index') }}" class="hover:text-emerald-600 transition-colors">一般修繕一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">修繕詳細</span>
@endsection

@section('content')
<div x-data="{ showDeleteModal: false }">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">一般修繕 詳細</h1>
        <div style="display: flex; gap: 8px; align-items: center;">
            <a href="{{ route('tenant.repairs.index') }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">一般修繕一覧に戻る</a>
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('tenant.repairs.edit', $repair) }}"
                   style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 6px; text-decoration: none; background: #fff;">編集</a>
                @if(auth()->user()->role->isExecutive())
                    <button @click="showDeleteModal = true"
                            style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 6px; background: #fff; cursor: pointer;">削除</button>
                @endif
            @endif
        </div>
    </div>


    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">修繕情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
            <div>
                <div class="text-xs text-gray-500 mb-0.5">物件</div>
                <div class="text-sm font-semibold"><a href="{{ route('tenant.properties.show', $repair->property) }}" class="text-emerald-600 hover:underline">{{ $repair->property->name }}</a></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">区画</div>
                <div class="text-sm font-semibold {{ !$repair->unit_id ? 'text-gray-400 italic' : '' }}">
                    @if($repair->unit)
                        <a href="{{ route('tenant.units.show', $repair->unit) }}" class="text-emerald-600 hover:underline">{{ $repair->unit->display_name }}</a>
                    @else
                        共用部
                    @endif
                </div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">ステータス</div>
                <div class="mt-0.5"><span class="badge {{ $repair->status->badgeClass() }}">{{ $repair->status->label() }}</span></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">カテゴリ</div>
                <div class="text-sm font-semibold text-gray-900">{{ $repair->category_label }}</div>
            </div>
            <div class="sm:col-span-2">
                <div class="text-xs text-gray-500 mb-0.5">修繕内容</div>
                <div class="text-sm text-gray-900 leading-relaxed whitespace-pre-wrap">{{ $repair->description }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">業者名</div>
                <div class="text-sm font-semibold text-gray-900">{{ $repair->contractor_name ?? '—' }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">費用</div>
                <div class="text-xl font-bold text-gray-900">{{ $repair->cost !== null ? number_format($repair->cost) . '円' : '—' }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">実施日</div>
                <div class="text-sm font-semibold text-gray-900">{{ $repair->started_at?->format('Y/m/d') ?? '—' }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">完了日</div>
                <div class="text-sm font-semibold text-gray-900">{{ $repair->completed_at?->format('Y/m/d') ?? '—' }}</div>
            </div>
        </div>
    </div>

    @if($repair->notes)
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
            <div class="text-sm text-gray-900 leading-relaxed whitespace-pre-wrap">{{ $repair->notes }}</div>
        </div>
    @endif

    {{-- 添付ファイル --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">添付ファイル</div>
        @include('components.attachment-section', [
            'attachableType'     => 'repairs',
            'attachableId'       => $repair->id,
            'attachments'        => $repair->attachments,
            'deletedAttachments' => $deletedAttachments,
        ])
    </div>

    @if(auth()->user()->role->isExecutive())
        <x-delete-confirm-modal
            title="修繕記録を削除しますか？"
            :action="route('tenant.repairs.destroy', $repair)"
            :target="$repair->property->name . ' / ' . $repair->unit_label . ' — ' . Str::limit($repair->description, 30)"
        />
    @endif

</div>
@endsection
