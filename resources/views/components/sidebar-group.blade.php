@props(['label'])

<div class="mb-1">
    <div class="px-5 py-2 text-[13px] font-bold text-emerald-600 tracking-wide">
        {{ $label }}
    </div>
    {{ $slot }}
</div>
