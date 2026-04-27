{{-- ========== 以下を sidebar.blade.php の「不動産管理」グループの後に追加 ========== --}}

{{-- ★ PC展開サイドバー・モバイルドロワーの両方に追加すること --}}

    {{-- 住宅事業 --}}
    @if($isExecutive || $user->belongsToDepartment('housing'))
        <x-sidebar-group label="住宅事業">
            <x-sidebar-item :href="url('/housing/properties')" label="建売物件" :active="request()->is('housing/properties*')" />
        </x-sidebar-group>
    @endif

{{-- ========== PC折りたたみサイドバーにも追加（不動産アイコンの後） ========== --}}

    {{-- 住宅事業 --}}
    @if($isExecutive || $user->belongsToDepartment('housing'))
        <a href="{{ url('/housing/properties') }}" title="住宅事業" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->is('housing/*') ? 'bg-emerald-50' : 'hover:bg-gray-100' }} transition-colors">
            <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="{{ request()->is('housing/*') ? '#059669' : '#6B7280' }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /><rect x="9" y="14" width="6" height="8" />
            </svg>
        </a>
    @endif
