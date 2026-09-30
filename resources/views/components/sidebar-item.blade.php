@props(['href', 'label', 'active' => false, 'badge' => null])

<a
    href="{{ $href }}"
    class="block px-5 py-2 text-[13px] transition-colors duration-150 border-l-[3px]
        {{ $active
            ? 'text-[#065F46] bg-emerald-50 border-emerald-500 font-semibold'
            : 'text-gray-700 hover:text-[#065F46] hover:bg-gray-50 border-transparent' }}"
>
    {{ $label }}
    @if($badge)
        {{-- 件数の丸印（決裁の対応待ち。段階2 設計書 §5.15）。0 と null は出さない --}}
        <span class="ml-1.5 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10px] font-bold tabular-nums"><span class="sr-only">対応待ち </span>{{ $badge }}<span class="sr-only"> 件</span></span>
    @endif
</a>
