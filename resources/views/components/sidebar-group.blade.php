{{-- サイドバーのグループ partial
   - label:       グループ名
   - section:     アコーディオン識別子（例: 'tenant', 'zeal', 'admin'）。指定すると折りたたみ式になる
   - collapsible: アコーディオン有効/無効。section 指定時のみ true。section=null なら常時展開（後方互換）
--}}
@props(['label', 'section' => null])

@php
    $isCollapsible = !empty($section);
@endphp

<div class="mb-1"
     @if($isCollapsible)
        x-data="{ open: $store.sidebarGroups.isOpen('{{ $section }}') }"
        x-init="$watch('open', function (v) { $store.sidebarGroups.setOpen('{{ $section }}', v); })"
     @endif>
    @if($isCollapsible)
        {{-- 折りたたみ可能ヘッダー（クリックで開閉） --}}
        <button type="button"
                @click="open = !open"
                class="w-full flex items-center gap-1.5 px-5 py-2 text-[13px] font-bold text-emerald-600 tracking-wide bg-transparent border-0 cursor-pointer hover:bg-gray-50 text-left transition-colors duration-150">
            {{-- chevron アイコン（open 時に 90 度回転） --}}
            <svg class="w-3 h-3 text-gray-400 flex-shrink-0 transition-transform duration-200"
                 :class="{ 'rotate-90 text-emerald-600': open }"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="9 18 15 12 9 6"/>
            </svg>
            <span>{{ $label }}</span>
        </button>
        <div x-show="open"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             x-cloak>
            {{ $slot }}
        </div>
    @else
        {{-- 後方互換: section 未指定なら常時展開 --}}
        <div class="px-5 py-2 text-[13px] font-bold text-emerald-600 tracking-wide">
            {{ $label }}
        </div>
        {{ $slot }}
    @endif
</div>
