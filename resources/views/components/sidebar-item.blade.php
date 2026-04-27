@props(['href', 'label', 'active' => false])

<a
    href="{{ $href }}"
    class="block px-5 py-2 text-[13px] transition-colors duration-150 border-l-[3px]
        {{ $active
            ? 'text-[#065F46] bg-emerald-50 border-emerald-500 font-semibold'
            : 'text-gray-700 hover:text-[#065F46] hover:bg-gray-50 border-transparent' }}"
>
    {{ $label }}
</a>
