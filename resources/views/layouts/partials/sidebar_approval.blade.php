{{-- 決裁のみ利用者（UserRole::ApprovalOnly）のサイドバー（設計書 §5.15）。
     基幹のサイドバー（sidebar.blade.php）の代わりに layouts/app.blade.php が出し分ける。
     ⚠ 中身は「決裁のホーム」と、決裁の管理者なら「利用者の管理」「部門の管理」だけ。
       社員の CSV 一括登録は「利用者の管理」の下位の画面なのでここには出さない。
     回帰テスト tests/Feature/Approval/ApprovalSidebarTest.php --}}

{{-- モバイル用オーバーレイ --}}
<div x-show="sidebarOpen" @click="sidebarOpen = false" class="fixed inset-0 bg-black/50 z-20 lg:hidden"
     x-transition:enter="transition-opacity ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
     x-transition:leave="transition-opacity ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     style="display: none;"></div>

@php
    $isApprovalAdmin = Auth::user()->isApprovalAdmin();
@endphp

{{-- ========== PC用: 展開サイドバー ========== --}}
{{-- ⚠ x-cloak を付けない（docs/RULES.md Bug #56）。sidebarExpanded は body の x-data で true 固定（永続化なし）なので
     起動後は必ず表示される。x-cloak があると Alpine の起動前だけ display: none になり、パース中に走るスクリプトが
     表示領域を 220px 広く測る。起動前に隠れている必要がある折りたたみ版とモバイルのドロワーには x-cloak を残す。 --}}
<aside x-show="sidebarExpanded" class="hidden lg:flex flex-col w-[220px] min-w-[220px] bg-white border-r border-gray-200 overflow-y-auto pt-4 pb-6 transition-all duration-200">
    <div class="mb-1">
        <div class="flex items-center justify-between px-5 py-2">
            <span class="text-[13px] font-bold text-emerald-600 tracking-wide">決裁申請</span>
            <button @click="sidebarExpanded = false" title="サイドバーを閉じる"
                    class="inline-flex items-center gap-1 px-2 py-1 rounded-md border border-gray-300 bg-gray-50 text-[10px] text-gray-500 hover:bg-gray-100 cursor-pointer">閉じる</button>
        </div>
        <x-sidebar-item :href="route('approvals.home')" label="決裁のホーム" :active="request()->routeIs('approvals.home')" />
        @if($isApprovalAdmin)
            <x-sidebar-item :href="route('approvals.admin.users.index')" label="利用者の管理" :active="request()->routeIs('approvals.admin.users.*')" />
            <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
        @endif
    </div>
</aside>

{{-- ========== PC用: 折りたたみサイドバー ========== --}}
<aside x-show="!sidebarExpanded" x-cloak class="hidden lg:flex flex-col items-center w-[56px] min-w-[56px] bg-white border-r border-gray-200 overflow-y-auto pt-4 pb-6">
    <button @click="sidebarExpanded = true" title="サイドバーを開く" class="w-9 h-9 mb-3 rounded-lg flex items-center justify-center hover:bg-gray-100 cursor-pointer">›</button>
    <a href="{{ route('approvals.home') }}" title="決裁のホーム" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->routeIs('approvals.home') ? 'bg-emerald-50' : 'hover:bg-gray-100' }}">決</a>
    @if($isApprovalAdmin)
        <a href="{{ route('approvals.admin.users.index') }}" title="利用者の管理" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->routeIs('approvals.admin.*') ? 'bg-emerald-50' : 'hover:bg-gray-100' }}">設</a>
    @endif
</aside>

{{-- ========== モバイル用: ドロワーサイドバー ========== --}}
<aside x-show="sidebarOpen" x-cloak class="fixed inset-y-0 left-0 w-[240px] bg-white border-r border-gray-200 overflow-y-auto pt-4 pb-6 z-30 lg:hidden"
       x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
       x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full">
    {{-- ⚠ 閉じるボタンを消さない。オーバーレイのタップだけが閉じる手段になると、
         ドロワーを開いた利用者が行き詰まる（基幹のドロワー sidebar.blade.php も同じ位置に持つ）。
         回帰テスト tests/Feature/LayoutSidebarDrawerTest.php が両方の partial に課す。 --}}
    <div class="flex items-center justify-between px-5 py-2">
        <span class="text-[13px] font-bold text-emerald-600 tracking-wide">決裁申請</span>
        <button @click="sidebarOpen = false" title="メニューを閉じる"
                class="p-1 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
            </svg>
        </button>
    </div>
    <x-sidebar-item :href="route('approvals.home')" label="決裁のホーム" :active="request()->routeIs('approvals.home')" />
    @if($isApprovalAdmin)
        <x-sidebar-item :href="route('approvals.admin.users.index')" label="利用者の管理" :active="request()->routeIs('approvals.admin.users.*')" />
        <x-sidebar-item :href="route('approvals.admin.organization.index')" label="部門の管理" :active="request()->routeIs('approvals.admin.organization.*')" />
    @endif
</aside>
