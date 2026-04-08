@extends('layouts.app')

@section('title', '問合せ詳細 — ' . $inquiry->inquiry_number)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.inquiries.index') }}" class="hover:text-emerald-600 transition-colors">問合せ一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $inquiry->inquiry_number }}</span>
@endsection

@section('content')
<div x-data="{ showDeleteModal: false, showStatusForm: false, selectedStatus: '' }">

    <a href="{{ route('tenant.inquiries.index') }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        問合せ一覧に戻る
    </a>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <div class="flex items-center gap-2.5">
            <h1 class="text-lg font-bold text-gray-900">{{ $inquiry->inquiry_number }}</h1>
            <span class="badge {{ $inquiry->status->badgeClass() }}">{{ $inquiry->status->label() }}</span>
        </div>
        @if(auth()->user()->role->isManagerOrAbove())
            <div class="flex gap-2">
                <a href="{{ route('tenant.inquiries.edit', $inquiry) }}"
                   class="px-4 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50 transition-colors">編集</a>
                @if(auth()->user()->role->isExecutive())
                    <button @click="showDeleteModal = true"
                            class="px-4 py-2 bg-white border border-red-200 rounded-md text-sm text-red-600 hover:bg-red-50 transition-colors cursor-pointer">削除</button>
                @endif
            </div>
        @endif
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 font-medium">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 font-medium">{{ session('error') }}</div>
    @endif

    {{-- 保留バナー --}}
    @if($inquiry->status === \App\Enums\InquiryStatus::OnHold)
        @php
            $holdHistory = $inquiry->histories->first(fn($h) => $h->content === 'ステータスを「保留」に変更');
        @endphp
        <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3.5 flex items-center gap-3">
            <span class="text-lg">⏸</span>
            <div>
                <div class="text-sm font-semibold text-amber-800">この問合せは保留中です</div>
                @if($holdHistory)
                    <div class="text-xs text-amber-700 mt-0.5">{{ $holdHistory->action_date->format('Y/m/d') }} に保留に変更されました</div>
                @endif
            </div>
        </div>
    @endif

    {{-- ★ 対応履歴追加フォーム（最上部・フォロー/保留のみ） --}}
    @if(auth()->user()->role->isManagerOrAbove() && ! $inquiry->isClosed())
        <div id="history-form" class="mb-4 rounded-lg p-4" style="border:2px solid #059669; background:#f0fdf4;">
            <div class="text-sm font-bold mb-3 flex items-center gap-1.5" style="color:#065f46;">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#065f46" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                対応履歴を追加
            </div>
            <form method="POST" action="{{ route('tenant.inquiries.storeHistory', $inquiry) }}">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">対応日<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="date" name="action_date" value="{{ old('action_date', now()->format('Y-m-d')) }}"
                               class="form-input w-full h-9 px-3 border border-gray-300 rounded-md text-sm focus:border-emerald-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">対応種別<span class="text-red-600 ml-0.5">*</span></label>
                        <select name="action_type"
                                class="form-input w-full h-9 px-3 border border-gray-300 rounded-md text-sm bg-white focus:border-emerald-500 focus:outline-none cursor-pointer">
                            @foreach(\App\Http\Controllers\Tenant\InquiryController::ACTION_TYPE_LABELS as $key => $label)
                                @if($key !== 'first_contact')
                                    <option value="{{ $key }}" {{ old('action_type') === $key ? 'selected' : '' }}>{{ $label }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="block text-xs font-semibold text-gray-700 mb-1">対応内容<span class="text-red-600 ml-0.5">*</span></label>
                    <textarea name="content" rows="3"
                              class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:border-emerald-500 focus:outline-none resize-y min-h-[60px]"
                              placeholder="対応内容を記入">{{ old('content') }}</textarea>
                </div>
                <div class="text-right">
                    <button type="submit"
                            class="px-4 py-2 bg-emerald-600 text-white rounded-md text-xs font-semibold hover:bg-emerald-700 transition-colors cursor-pointer">
                        履歴を追加
                    </button>
                </div>
            </form>
        </div>
    @endif

    {{-- 対応履歴タイムライン --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">対応履歴</div>

        @if($inquiry->histories->isNotEmpty())
            <style>
                .tl-item { position:relative; padding-left:28px; padding-bottom:18px; }
                .tl-item::before { content:''; position:absolute; left:7px; top:22px; bottom:0; width:2px; background:#e5e7eb; }
                .tl-item:last-child::before { display:none; }
                .tl-dot { position:absolute; left:0; top:5px; width:16px; height:16px; border-radius:50%; border:2.5px solid #059669; background:#fff; }
                .tl-dot.auto { border-color:#9ca3af; background:#f3f4f6; }
            </style>
            @foreach($inquiry->histories as $history)
                <div class="tl-item">
                    <div class="tl-dot {{ in_array($history->action_type, ['first_contact', 'other']) && str_contains($history->content, 'ステータスを') ? 'auto' : ($history->action_type === 'first_contact' ? 'auto' : '') }}"></div>
                    <div class="flex justify-between items-baseline mb-1">
                        <div class="text-sm font-bold text-gray-900">
                            {{ $history->action_date->format('Y/m/d') }} —
                            <span class="{{ $history->action_type === 'first_contact' || str_contains($history->content, 'ステータスを') ? 'text-gray-400' : 'text-emerald-600' }}">{{ $history->action_type_label }}</span>
                        </div>
                        <div class="text-xs text-gray-400">
                            @if(str_contains($history->content, 'ステータスを') || $history->action_type === 'first_contact')
                                システム
                            @else
                                {{ $history->createdByUser->name ?? '—' }}
                            @endif
                        </div>
                    </div>
                    <div class="text-sm {{ str_contains($history->content, 'ステータスを') || $history->action_type === 'first_contact' ? 'text-gray-500' : 'text-gray-700' }} leading-relaxed whitespace-pre-wrap">{{ $history->content }}</div>
                </div>
            @endforeach
        @else
            <p class="text-gray-400 text-center py-4 text-sm">対応履歴はありません。</p>
        @endif

        @if($inquiry->isClosed())
            <div class="mt-4 p-3 bg-gray-50 border border-gray-200 rounded-md text-center">
                <span class="text-xs text-gray-400">この問合せは{{ $inquiry->status->label() }}のため、対応履歴の追加はできません。</span>
            </div>
        @endif
    </div>

    {{-- 問合せ情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">問合せ情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
            <div>
                <div class="text-xs text-gray-500 font-semibold mb-0.5">物件</div>
                <div class="text-sm font-semibold"><a href="{{ route('tenant.properties.show', $inquiry->property) }}" class="text-emerald-600 hover:underline">{{ $inquiry->property->name }}</a></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 font-semibold mb-0.5">希望区画</div>
                <div class="text-sm font-semibold {{ $inquiry->units->isEmpty() ? 'text-gray-400 italic' : 'text-gray-900' }}">{{ $inquiry->unit_labels }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 font-semibold mb-0.5">問合せ日</div>
                <div class="text-sm font-semibold text-gray-900">{{ $inquiry->inquiry_date->format('Y/m/d') }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 font-semibold mb-0.5">問合せ経路</div>
                <div class="text-sm font-semibold text-gray-900">{{ $inquiry->source_label }}</div>
            </div>
            @if($inquiry->assignedUser)
                <div>
                    <div class="text-xs text-gray-500 font-semibold mb-0.5">担当者</div>
                    <div class="text-sm font-semibold text-gray-900">{{ $inquiry->assignedUser->name }}</div>
                </div>
            @endif
        </div>
    </div>

    {{-- 問合せ者情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">問合せ者情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
            <div>
                <div class="text-xs text-gray-500 font-semibold mb-0.5">問合せ者</div>
                <div class="text-sm font-semibold text-gray-900">{{ $inquiry->contact_name }}</div>
            </div>
            @if($inquiry->company_name)
                <div>
                    <div class="text-xs text-gray-500 font-semibold mb-0.5">会社名</div>
                    <div class="text-sm font-semibold text-gray-900">{{ $inquiry->company_name }}</div>
                </div>
            @endif
            @if($inquiry->phone)
                <div>
                    <div class="text-xs text-gray-500 font-semibold mb-0.5">電話番号</div>
                    <div class="text-sm font-semibold text-gray-900">{{ $inquiry->phone }}</div>
                </div>
            @endif
            @if($inquiry->email)
                <div>
                    <div class="text-xs text-gray-500 font-semibold mb-0.5">メールアドレス</div>
                    <div class="text-sm font-semibold text-gray-900">{{ $inquiry->email }}</div>
                </div>
            @endif
        </div>
    </div>

    {{-- 希望条件（値がある場合のみ） --}}
    @if($inquiry->desired_usage_id || $inquiry->desired_area_min || $inquiry->desired_area_max || $inquiry->budget_max !== null || $inquiry->desired_move_date || $inquiry->description)
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">希望条件</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                @if($inquiry->desiredUsageType)
                    <div>
                        <div class="text-xs text-gray-500 font-semibold mb-0.5">希望用途</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $inquiry->desiredUsageType->name }}</div>
                    </div>
                @endif
                @if($inquiry->desired_area_min || $inquiry->desired_area_max)
                    <div>
                        <div class="text-xs text-gray-500 font-semibold mb-0.5">希望面積</div>
                        <div class="text-sm font-semibold text-gray-900">
                            @if($inquiry->desired_area_min && $inquiry->desired_area_max)
                                {{ $inquiry->desired_area_min }}坪 〜 {{ $inquiry->desired_area_max }}坪
                            @elseif($inquiry->desired_area_min)
                                {{ $inquiry->desired_area_min }}坪〜
                            @else
                                〜{{ $inquiry->desired_area_max }}坪
                            @endif
                        </div>
                    </div>
                @endif
                @if($inquiry->budget_max !== null)
                    <div>
                        <div class="text-xs text-gray-500 font-semibold mb-0.5">予算上限（月額）</div>
                        <div class="text-base font-bold text-gray-900">{{ $inquiry->budget_display }}</div>
                    </div>
                @endif
                @if($inquiry->desired_move_date)
                    <div>
                        <div class="text-xs text-gray-500 font-semibold mb-0.5">希望入居月</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $inquiry->desired_move_display }}</div>
                    </div>
                @endif
                @if($inquiry->description)
                    <div class="sm:col-span-2">
                        <div class="text-xs text-gray-500 font-semibold mb-0.5">問合せ内容</div>
                        <div class="text-sm text-gray-900 leading-relaxed whitespace-pre-wrap">{{ $inquiry->description }}</div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- 結果カード（終了状態のみ） --}}
    @if($inquiry->isClosed())
        @php
            $resultBg = match($inquiry->status) {
                \App\Enums\InquiryStatus::Converted => 'background:#eff6ff; border-color:#bfdbfe;',
                \App\Enums\InquiryStatus::Lost => 'background:#f9fafb; border-color:#d1d5db;',
                \App\Enums\InquiryStatus::Unreachable => 'background:#fef2f2; border-color:#fecaca;',
                default => '',
            };
        @endphp
        <div class="rounded-lg p-5 mb-3" style="border:1px solid; {{ $resultBg }}">
            <div class="flex items-center gap-2.5 mb-2.5">
                <span class="badge {{ $inquiry->status->badgeClass() }}">{{ $inquiry->status->label() }}</span>
            </div>
            @if($inquiry->result_reason)
                <div class="text-sm text-gray-700 leading-relaxed whitespace-pre-wrap">{{ $inquiry->result_reason }}</div>
            @endif
            @if($inquiry->contract)
                <div class="flex items-center gap-3 pt-3 mt-3" style="border-top:1px solid rgba(0,0,0,.1);">
                    <span class="text-xs font-semibold text-blue-700">関連契約:</span>
                    <a href="{{ route('tenant.contracts.show', $inquiry->contract) }}" class="text-sm font-bold text-emerald-600 hover:underline">{{ $inquiry->contract->contract_number }}</a>
                    <span class="text-xs text-gray-500">{{ $inquiry->contract->property->name ?? '' }} / {{ $inquiry->contract->store_name ?? '' }}</span>
                </div>
            @endif
        </div>
    @endif

    {{-- ステータス変更（フォロー or 保留のみ） --}}
    @if(auth()->user()->role->isManagerOrAbove() && ! $inquiry->isClosed())
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">ステータス変更</div>

            {{-- 現在のステータス表示 --}}
            <div class="mb-3 px-3.5 py-2 rounded-md flex items-center gap-2"
                 style="{{ $inquiry->status === \App\Enums\InquiryStatus::OnHold ? 'background:#fffbeb; border:1px solid #fde68a;' : 'background:#f0fdf4; border:1px solid #bbf7d0;' }}">
                <span class="text-xs font-semibold text-gray-600">現在のステータス:</span>
                <span class="badge {{ $inquiry->status->badgeClass() }}">{{ $inquiry->status->label() }}</span>
            </div>

            <div class="flex flex-wrap gap-2 mb-3">
                @if($inquiry->status === \App\Enums\InquiryStatus::OnHold)
                    <button @click="selectedStatus = 'follow'; showStatusForm = true"
                            class="px-4 py-2 rounded-md text-sm font-semibold border cursor-pointer transition-colors"
                            style="color:#166534; border-color:#bbf7d0; background:#f0fdf4;">
                        <span class="inline-flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                            フォローに戻す
                        </span>
                    </button>
                @endif
                <button @click="selectedStatus = 'converted'; showStatusForm = true"
                        class="px-4 py-2 rounded-md text-sm font-semibold border cursor-pointer transition-colors"
                        style="color:#1e40af; border-color:#bfdbfe; background:#eff6ff;">成約</button>
                <button @click="selectedStatus = 'lost'; showStatusForm = true"
                        class="px-4 py-2 rounded-md text-sm font-semibold border cursor-pointer transition-colors"
                        style="color:#4b5563; border-color:#d1d5db; background:#f9fafb;">不成約</button>
                <button @click="selectedStatus = 'unreachable'; showStatusForm = true"
                        class="px-4 py-2 rounded-md text-sm font-semibold border cursor-pointer transition-colors"
                        style="color:#991b1b; border-color:#fecaca; background:#fef2f2;">追客不可</button>
                @if($inquiry->status === \App\Enums\InquiryStatus::Follow)
                    <button @click="selectedStatus = 'on_hold'; showStatusForm = true"
                            class="px-4 py-2 rounded-md text-sm font-semibold border cursor-pointer transition-colors"
                            style="color:#92400e; border-color:#fde68a; background:#fffbeb;">保留</button>
                @endif
            </div>

            <div x-show="showStatusForm" x-cloak class="border border-gray-200 rounded-md p-4 bg-gray-50">
                <form method="POST" action="{{ route('tenant.inquiries.updateStatus', $inquiry) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" :value="selectedStatus">

                    <div class="text-sm font-semibold text-gray-700 mb-2">
                        <span x-show="selectedStatus === 'converted'">成約として処理します</span>
                        <span x-show="selectedStatus === 'lost'">不成約として処理します</span>
                        <span x-show="selectedStatus === 'unreachable'">追客不可として処理します</span>
                        <span x-show="selectedStatus === 'on_hold'">保留にします</span>
                        <span x-show="selectedStatus === 'follow'">フォロー状態に戻します</span>
                    </div>

                    <div x-show="selectedStatus !== 'on_hold' && selectedStatus !== 'follow'">
                        <label class="block text-xs text-gray-500 mb-1">結果理由</label>
                        <textarea name="result_reason" rows="2"
                                  class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:border-emerald-500 focus:outline-none resize-y min-h-[50px]"
                                  placeholder="経緯・理由を記入（任意）"></textarea>
                    </div>

                    <div x-show="selectedStatus === 'converted'" class="mt-2 px-3 py-2 rounded-md text-xs" style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af;">
                        💡 変更後、契約登録画面に遷移して契約を作成できます
                    </div>

                    <div class="flex justify-end gap-2 mt-3">
                        <button type="button" @click="showStatusForm = false"
                                class="px-3.5 py-1.5 bg-white border border-gray-300 rounded-md text-xs text-gray-600 cursor-pointer">キャンセル</button>
                        <button type="submit"
                                class="px-3.5 py-1.5 bg-emerald-600 text-white rounded-md text-xs font-semibold cursor-pointer hover:bg-emerald-700">変更する</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- 備考 --}}
    @if($inquiry->notes)
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
            <div class="text-sm text-gray-900 leading-relaxed whitespace-pre-wrap">{{ $inquiry->notes }}</div>
        </div>
    @endif

    {{-- 削除モーダル --}}
    @if(auth()->user()->role->isExecutive())
        <x-delete-confirm-modal
            title="問合せを削除しますか？"
            :action="route('tenant.inquiries.destroy', $inquiry)"
            :target="$inquiry->inquiry_number . ' — ' . $inquiry->contact_display"
        />
    @endif

</div>
@endsection
