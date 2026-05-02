{{-- モバイル用オーバーレイ --}}
<div
    x-show="sidebarOpen"
    @click="sidebarOpen = false"
    class="fixed inset-0 bg-black/50 z-20 lg:hidden"
    x-transition:enter="transition-opacity ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition-opacity ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    style="display: none;"
></div>

@php
    $user = Auth::user();
    $isExecutive = $user->role->isExecutive();
    $isManagerOrAbove = $user->role->isManagerOrAbove();
    $hasTenantAccess = $isExecutive || $user->belongsToDepartment('tenant');
    $hasRealEstateAccess = $isExecutive || $user->belongsToDepartment('realestate');
    $hasHousingAccess = $isExecutive || $user->belongsToDepartment('housing');
    $hasMansionAccess = $isExecutive || $user->belongsToDepartment('mansion');
    $hasDadAccess  = $isExecutive || $user->belongsToDepartment('dad');
    $hasZealAccess = $isExecutive || $user->belongsToDepartment('zeal');
@endphp

{{-- ========== PC用: 展開サイドバー ========== --}}
<aside
    x-show="sidebarExpanded"
    x-cloak
    class="hidden lg:flex flex-col w-[220px] min-w-[220px] bg-white border-r border-gray-200 overflow-y-auto pt-4 pb-6 transition-all duration-200"
>
    {{-- 最初のグループ: ダッシュボード + 閉じるボタン --}}
    <div class="mb-1">
        <div class="flex items-center justify-between px-5 py-2">
            <span class="text-[13px] font-bold text-emerald-600 tracking-wide">ダッシュボード</span>
            <button
                @click="sidebarExpanded = false"
                title="サイドバーを閉じる"
                class="inline-flex items-center gap-1 px-2 py-1 rounded-md border border-gray-300 bg-gray-50 text-[10px] text-gray-500 hover:bg-gray-100 hover:text-gray-700 hover:border-gray-400 transition-all cursor-pointer"
            >
                <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6" />
                </svg>
                閉じる
            </button>
        </div>
        @if($isExecutive)
            <x-sidebar-item :href="url('/dashboard/executive')" label="経営ダッシュボード" :active="request()->is('dashboard/executive')" />
        @endif
        @if($hasTenantAccess)
            <x-sidebar-item :href="url('/dashboard/tenant')" label="テナントダッシュボード" :active="request()->is('dashboard/tenant')" />
        @endif
        @if($hasMansionAccess)
            <x-sidebar-item :href="url('/mansion/dashboard')" label="賃貸Mダッシュボード" :active="request()->is('mansion/dashboard')" />
        @endif
    </div>

    {{-- テナント管理 --}}
    @if($hasTenantAccess)
        <x-sidebar-group label="テナント管理">
            <x-sidebar-item :href="url('/tenant/properties')" label="物件一覧" :active="request()->is('tenant/properties*')" />
            <x-sidebar-item :href="url('/tenant/units')" label="部屋一覧" :active="request()->is('tenant/units')" />
            <x-sidebar-item :href="url('/tenant/contracts')" label="契約一覧" :active="request()->is('tenant/contracts*')" />
            <x-sidebar-item :href="url('/tenant/investments')" label="投資案件" :active="request()->is('tenant/investments*')" />
            <x-sidebar-item :href="url('/tenant/repairs')" label="一般修繕" :active="request()->is('tenant/repairs*')" />
            <x-sidebar-item :href="url('/tenant/customers')" label="顧客一覧" :active="request()->is('tenant/customers*')" />
            <x-sidebar-item :href="url('/tenant/inquiries')" label="問合せ管理" :active="request()->is('tenant/inquiries*')" />
        </x-sidebar-group>
    @endif

    {{-- 賃貸マンション --}}
    @if($hasMansionAccess)
        <x-sidebar-group label="賃貸マンション">
            <x-sidebar-item :href="url('/mansion/properties')" label="物件一覧" :active="request()->is('mansion/properties*')" />
            <x-sidebar-item :href="url('/mansion/tenants')" label="入居者管理" :active="request()->is('mansion/tenants*')" />
            <x-sidebar-item :href="url('/mansion/contracts')" label="部屋契約一覧" :active="request()->is('mansion/contracts*')" />
            <x-sidebar-item :href="url('/mansion/parking-contracts')" label="駐車場契約一覧" :active="request()->is('mansion/parking-contracts*')" />
        </x-sidebar-group>
    @endif

    {{-- 収支管理 --}}
    <x-sidebar-group label="収支管理">
        <x-sidebar-item :href="url('/tenant/transactions')" label="収支一覧" :active="request()->is('tenant/transactions') || request()->is('tenant/transactions/create') || request()->is('tenant/transactions/*/edit')" />
        <x-sidebar-item :href="url('/tenant/transactions/summary')" label="収支サマリー" :active="request()->is('tenant/transactions/summary') || request()->is('tenant/transactions/by-*')" />
    </x-sidebar-group>

    {{-- 不動産管理 --}}
    @if($hasRealEstateAccess)
        <x-sidebar-group label="不動産管理">
            <x-sidebar-item :href="url('/realestate/procurements')" label="仕入れ案件" :active="request()->is('realestate/procurements*')" />
            <x-sidebar-item :href="url('/realestate/projects')" label="分譲地" :active="request()->is('realestate/projects*')" />
            <x-sidebar-item :href="url('/realestate/suppliers')" label="仕入れ先管理" :active="request()->is('realestate/suppliers*')" />
            <x-sidebar-item :href="url('/realestate/customers')" label="顧客管理" :active="request()->is('realestate/customers*')" />
            <x-sidebar-item :href="url('/realestate/contracts')" label="契約管理" :active="request()->is('realestate/contracts*')" />
        </x-sidebar-group>
    @endif
    
    {{-- 住宅事業 --}}
    @if($hasHousingAccess)
        <x-sidebar-group label="住宅事業">
            <x-sidebar-item :href="url('/housing')" label="ダッシュボード" :active="request()->is('housing') || request()->is('housing/')" />
            <x-sidebar-item :href="url('/housing/properties')" label="建売物件" :active="request()->is('housing/properties*')" />
            <x-sidebar-item :href="url('/housing/custom-orders')" label="注文住宅" :active="request()->is('housing/custom-orders*')" />
            <x-sidebar-item :href="url('/housing/contracts')" label="契約管理" :active="request()->is('housing/contracts*')" />
            <x-sidebar-item :href="url('/housing/customers')" label="顧客管理" :active="request()->is('housing/customers*')" />
        </x-sidebar-group>
    @endif

    {{-- DAD --}}
    @if($hasDadAccess)
        <x-sidebar-group label="DAD">
            <x-sidebar-item :href="url('/dad/projects')" label="工事案件" :active="request()->is('dad/projects*')" />
            <x-sidebar-item :href="url('/dad/clients')" label="発注者管理" :active="request()->is('dad/clients*')" />
            <x-sidebar-item :href="url('/dad/subcontractors')" label="協力業者管理" :active="request()->is('dad/subcontractors*')" />
            <x-sidebar-item :href="url('/dad/employees')" label="従業員管理" :active="request()->is('dad/employees*')" />
        </x-sidebar-group>
    @endif

    {{-- ZEAL フィットネス事業 --}}
    @if($hasZealAccess)
        <x-sidebar-group label="ZEAL">
            <x-sidebar-item :href="url('/zeal')" label="ダッシュボード" :active="request()->is('zeal') || request()->routeIs('zeal.dashboard')" />
            <x-sidebar-item :href="url('/zeal/members')" label="会員管理" :active="request()->is('zeal/members*')" />
            <x-sidebar-item :href="url('/zeal/inquiries')" label="体験予約" :active="request()->is('zeal/inquiries*')" />
            <x-sidebar-item :href="url('/zeal/plans')" label="プランマスタ" :active="request()->is('zeal/plans*')" />
            <x-sidebar-item :href="url('/zeal/trainers')" label="トレーナーマスタ" :active="request()->is('zeal/trainers*')" />
        </x-sidebar-group>
    @endif

    {{-- システム管理（経営層のみ） --}}
    @if($isExecutive)
        <x-sidebar-group label="システム管理">
            {{-- サブ見出し: テナント --}}
            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 20px 3px;">
                <span style="font-size: 10px; font-weight: 600; color: #6B7280; letter-spacing: 0.05em; white-space: nowrap;">テナント</span>
                <span style="flex: 1; height: 1px; background: #D1D5DB;"></span>
            </div>
            <x-sidebar-item :href="url('/admin/master/usage-types')" label="用途マスター" :active="request()->is('admin/master/usage-types*')" />
            <x-sidebar-item :href="url('/admin/master/structure-types')" label="構造マスター" :active="request()->is('admin/master/structure-types*')" />
            <x-sidebar-item :href="url('/admin/tenant-import')" label="テナントCSVインポート" :active="request()->is('admin/tenant-import*')" />
            {{-- サブ見出し: 賃貸マンション --}}
            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 20px 3px;">
                <span style="font-size: 10px; font-weight: 600; color: #6B7280; letter-spacing: 0.05em; white-space: nowrap;">賃貸マンション</span>
                <span style="flex: 1; height: 1px; background: #D1D5DB;"></span>
            </div>
            <x-sidebar-item :href="url('/admin/mansion-import')" label="賃貸マンションCSVインポート" :active="request()->is('admin/mansion-import*')" />
            {{-- サブ見出し: 不動産 --}}
            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 20px 3px;">
                <span style="font-size: 10px; font-weight: 600; color: #6B7280; letter-spacing: 0.05em; white-space: nowrap;">不動産</span>
                <span style="flex: 1; height: 1px; background: #D1D5DB;"></span>
            </div>
            <x-sidebar-item :href="url('/admin/master/re-cost-items')" label="原価項目マスター" :active="request()->is('admin/master/re-cost-items*')" />
            <x-sidebar-item :href="url('/admin/master/zoning-types')" label="用途地域マスター" :active="request()->is('admin/master/zoning-types*')" />
            {{-- サブ見出し: 住宅 --}}
            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 20px 3px;">
                <span style="font-size: 10px; font-weight: 600; color: #6B7280; letter-spacing: 0.05em; white-space: nowrap;">住宅</span>
                <span style="flex: 1; height: 1px; background: #D1D5DB;"></span>
            </div>
            <x-sidebar-item :href="url('/admin/survey-questions')" label="アンケート設問管理" :active="request()->is('admin/survey-questions*')" />
            <x-sidebar-item :href="url('/admin/customers/import')" label="顧客CSVインポート" :active="request()->is('admin/customers/import*')" />
            {{-- サブ見出し: DAD --}}
            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 20px 3px;">
                <span style="font-size: 10px; font-weight: 600; color: #6B7280; letter-spacing: 0.05em; white-space: nowrap;">DAD</span>
                <span style="flex: 1; height: 1px; background: #D1D5DB;"></span>
            </div>
            <x-sidebar-item :href="url('/admin/master/dad-specialties')" label="専門分野マスター" :active="request()->is('admin/master/dad-specialties*')" />
            {{-- サブ見出し: ZEAL --}}
            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 20px 3px;">
                <span style="font-size: 10px; font-weight: 600; color: #6B7280; letter-spacing: 0.05em; white-space: nowrap;">ZEAL</span>
                <span style="flex: 1; height: 1px; background: #D1D5DB;"></span>
            </div>
            <x-sidebar-item :href="url('/admin/zeal/member-import')" label="会員CSVインポート" :active="request()->is('admin/zeal/member-import*')" />
            {{-- サブ見出し: マスター --}}
            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 20px 3px;">
                <span style="font-size: 10px; font-weight: 600; color: #6B7280; letter-spacing: 0.05em; white-space: nowrap;">マスター</span>
                <span style="flex: 1; height: 1px; background: #D1D5DB;"></span>
            </div>
            <x-sidebar-item :href="url('/admin/users')" label="ユーザー管理" :active="request()->is('admin/users*')" />
            <x-sidebar-item :href="url('/admin/settings')" label="マスター設定" :active="request()->is('admin/settings*')" />
        </x-sidebar-group>
    @endif
</aside>

{{-- ========== PC用: 折りたたみサイドバー（アイコンのみ） ========== --}}
<aside
    x-show="!sidebarExpanded"
    x-cloak
    class="hidden lg:flex flex-col items-center w-[56px] min-w-[56px] bg-white border-r border-gray-200 overflow-y-auto pt-2 pb-6 transition-all duration-200"
>
    {{-- 開くボタン --}}
    <button
        @click="sidebarExpanded = true"
        title="サイドバーを開く"
        class="w-9 h-8 mb-3 rounded-md bg-gray-100 flex items-center justify-center text-gray-500 hover:bg-gray-200 hover:text-gray-700 transition-all cursor-pointer"
    >
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="13 7 18 12 13 17" />
            <line x1="18" y1="12" x2="4" y2="12" />
        </svg>
    </button>

    {{-- ダッシュボード --}}
    <a href="{{ url('/dashboard') }}" title="ダッシュボード" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->is('dashboard*') ? 'bg-emerald-50' : 'hover:bg-gray-100' }} transition-colors">
        <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="{{ request()->is('dashboard*') ? '#059669' : '#6B7280' }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="7" height="9" rx="1" /><rect x="14" y="3" width="7" height="5" rx="1" /><rect x="14" y="12" width="7" height="9" rx="1" /><rect x="3" y="16" width="7" height="5" rx="1" />
        </svg>
    </a>

    {{-- テナント管理 --}}
    @if($hasTenantAccess)
        <a href="{{ url('/tenant/properties') }}" title="テナント管理" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->is('tenant/*') ? 'bg-emerald-50' : 'hover:bg-gray-100' }} transition-colors">
            <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="{{ request()->is('tenant/*') ? '#059669' : '#6B7280' }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="4" y="2" width="16" height="20" rx="2" /><line x1="9" y1="6" x2="9" y2="6.01" /><line x1="15" y1="6" x2="15" y2="6.01" /><line x1="9" y1="10" x2="9" y2="10.01" /><line x1="15" y1="10" x2="15" y2="10.01" /><line x1="9" y1="14" x2="9" y2="14.01" /><line x1="15" y1="14" x2="15" y2="14.01" /><path d="M9 18h6" />
            </svg>
        </a>
    @endif

    {{-- 賃貸マンション --}}
    @if($hasMansionAccess)
    <a href="{{ url('/mansion/dashboard') }}" title="賃貸マンション" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->is('mansion/*') ? 'bg-emerald-50' : 'hover:bg-gray-100' }} transition-colors">
        <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="{{ request()->is('mansion/*') ? '#059669' : '#6B7280' }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 2h12v20H6z" /><path d="M9 6h2M13 6h2M9 10h2M13 10h2M9 14h2M13 14h2" /><path d="M10 22v-4h4v4" />
        </svg>
    </a>
    @endif

    {{-- 収支管理 --}}
    <a href="{{ url('/tenant/transactions') }}" title="収支管理" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->is('tenant/transactions*') ? 'bg-emerald-50' : 'hover:bg-gray-100' }} transition-colors">
        <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="{{ request()->is('tenant/transactions*') ? '#059669' : '#6B7280' }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <line x1="12" y1="1" x2="12" y2="23" /><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
        </svg>
    </a>

    {{-- 不動産管理 --}}
    @if($hasRealEstateAccess)
        <a href="{{ url('/realestate/procurements') }}" title="不動産管理" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->is('realestate/*') ? 'bg-emerald-50' : 'hover:bg-gray-100' }} transition-colors">
            <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="{{ request()->is('realestate/*') ? '#059669' : '#6B7280' }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /><polyline points="9 22 9 12 15 12 15 22" />
            </svg>
        </a>
    @endif
    
    {{-- 住宅事業 --}}
    @if($hasHousingAccess)
        <a href="{{ url('/housing/properties') }}" title="住宅事業" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->is('housing/*') ? 'bg-emerald-50' : 'hover:bg-gray-100' }} transition-colors">
            <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="{{ request()->is('housing/*') ? '#059669' : '#6B7280' }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /><rect x="9" y="14" width="6" height="8" />
            </svg>
        </a>
    @endif

    {{-- DAD --}}
    @if($hasDadAccess)
        <a href="{{ url('/dad/projects') }}" title="DAD" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->is('dad/*') ? 'bg-emerald-50' : 'hover:bg-gray-100' }} transition-colors">
            <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="{{ request()->is('dad/*') ? '#059669' : '#6B7280' }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 21h18M5 21V10l7-5 7 5v11M9 21v-6h6v6" /><circle cx="12" cy="13" r="0.5" />
            </svg>
        </a>
    @endif

    {{-- ZEAL フィットネス事業 --}}
    @if($hasZealAccess)
        <a href="{{ url('/zeal') }}" title="ZEAL" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->is('zeal*') ? 'bg-emerald-50' : 'hover:bg-gray-100' }} transition-colors">
            <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="{{ request()->is('zeal*') ? '#059669' : '#6B7280' }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                {{-- ダンベルアイコン --}}
                <line x1="8.5" y1="12" x2="15.5" y2="12"/>
                <rect x="2" y="9" width="3" height="6" rx="1"/>
                <rect x="5.5" y="7" width="3" height="10" rx="1"/>
                <rect x="15.5" y="7" width="3" height="10" rx="1"/>
                <rect x="19" y="9" width="3" height="6" rx="1"/>
            </svg>
        </a>
    @endif

    {{-- システム管理（経営層のみ。テナント / 不動産 / 住宅 / マスター サブ見出しを内包） --}}
    @if($isExecutive)
        <a href="{{ url('/admin/master/usage-types') }}" title="システム管理" class="w-9 h-9 mb-1 rounded-lg flex items-center justify-center {{ request()->is('admin/*') ? 'bg-emerald-50' : 'hover:bg-gray-100' }} transition-colors">
            <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" fill="none" stroke="{{ request()->is('admin/*') ? '#059669' : '#6B7280' }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3" /><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" />
            </svg>
        </a>
    @endif
</aside>

{{-- ========== モバイル用: ドロワーサイドバー ========== --}}
<aside
    x-show="sidebarOpen"
    x-cloak
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    class="fixed inset-y-0 left-0 z-30 w-[260px] bg-white overflow-y-auto pt-4 pb-6 transform lg:hidden transition-transform duration-200 ease-in-out shadow-xl"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="-translate-x-full"
    x-transition:enter-end="translate-x-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="translate-x-0"
    x-transition:leave-end="-translate-x-full"
>
    {{-- モバイル: 閉じるボタン --}}
    <div class="flex items-center justify-between px-5 mb-3">
        <img src="{{ asset('images/logo_yoko.png') }}" alt="ミツワ都市開発" class="h-5 w-auto">
        <button @click="sidebarOpen = false" class="p-1 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
            </svg>
        </button>
    </div>

    {{-- ダッシュボード --}}
    <x-sidebar-group label="ダッシュボード">
        @if($isExecutive)
            <x-sidebar-item :href="url('/dashboard/executive')" label="経営ダッシュボード" :active="request()->is('dashboard/executive')" />
        @endif
        @if($hasTenantAccess)
            <x-sidebar-item :href="url('/dashboard/tenant')" label="テナントダッシュボード" :active="request()->is('dashboard/tenant')" />
        @endif
        @if($hasMansionAccess)
            <x-sidebar-item :href="url('/mansion/dashboard')" label="賃貸Mダッシュボード" :active="request()->is('mansion/dashboard')" />
        @endif
    </x-sidebar-group>

    @if($hasTenantAccess)
        <x-sidebar-group label="テナント管理">
            <x-sidebar-item :href="url('/tenant/properties')" label="物件一覧" :active="request()->is('tenant/properties*')" />
            <x-sidebar-item :href="url('/tenant/units')" label="部屋一覧" :active="request()->is('tenant/units')" />
            <x-sidebar-item :href="url('/tenant/contracts')" label="契約一覧" :active="request()->is('tenant/contracts*')" />
            <x-sidebar-item :href="url('/tenant/investments')" label="投資案件" :active="request()->is('tenant/investments*')" />
            <x-sidebar-item :href="url('/tenant/repairs')" label="一般修繕" :active="request()->is('tenant/repairs*')" />
            <x-sidebar-item :href="url('/tenant/customers')" label="顧客一覧" :active="request()->is('tenant/customers*')" />
            <x-sidebar-item :href="url('/tenant/inquiries')" label="問合せ管理" :active="request()->is('tenant/inquiries*')" />
        </x-sidebar-group>
    @endif

    @if($hasMansionAccess)
        <x-sidebar-group label="賃貸マンション">
            <x-sidebar-item :href="url('/mansion/properties')" label="物件一覧" :active="request()->is('mansion/properties*')" />
            <x-sidebar-item :href="url('/mansion/tenants')" label="入居者管理" :active="request()->is('mansion/tenants*')" />
            <x-sidebar-item :href="url('/mansion/contracts')" label="部屋契約一覧" :active="request()->is('mansion/contracts*')" />
            <x-sidebar-item :href="url('/mansion/parking-contracts')" label="駐車場契約一覧" :active="request()->is('mansion/parking-contracts*')" />
        </x-sidebar-group>
    @endif

    <x-sidebar-group label="収支管理">
        <x-sidebar-item :href="url('/tenant/transactions')" label="収支一覧" :active="request()->is('tenant/transactions') || request()->is('tenant/transactions/create') || request()->is('tenant/transactions/*/edit')" />
        <x-sidebar-item :href="url('/tenant/transactions/summary')" label="収支サマリー" :active="request()->is('tenant/transactions/summary') || request()->is('tenant/transactions/by-*')" />
    </x-sidebar-group>

    @if($hasRealEstateAccess)
        <x-sidebar-group label="不動産管理">
            <x-sidebar-item :href="url('/realestate/procurements')" label="仕入れ案件" :active="request()->is('realestate/procurements*')" />
            <x-sidebar-item :href="url('/realestate/projects')" label="分譲地" :active="request()->is('realestate/projects*')" />
            <x-sidebar-item :href="url('/realestate/suppliers')" label="仕入れ先管理" :active="request()->is('realestate/suppliers*')" />
            <x-sidebar-item :href="url('/realestate/customers')" label="顧客管理" :active="request()->is('realestate/customers*')" />
            <x-sidebar-item :href="url('/realestate/contracts')" label="契約管理" :active="request()->is('realestate/contracts*')" />
        </x-sidebar-group>
    @endif
    
    @if($hasHousingAccess)
        <x-sidebar-group label="住宅事業">
            <x-sidebar-item :href="url('/housing')" label="ダッシュボード" :active="request()->is('housing') || request()->is('housing/')" />
            <x-sidebar-item :href="url('/housing/properties')" label="建売物件" :active="request()->is('housing/properties*')" />
            <x-sidebar-item :href="url('/housing/custom-orders')" label="注文住宅" :active="request()->is('housing/custom-orders*')" />
            <x-sidebar-item :href="url('/housing/contracts')" label="契約管理" :active="request()->is('housing/contracts*')" />
            <x-sidebar-item :href="url('/housing/customers')" label="顧客管理" :active="request()->is('housing/customers*')" />
        </x-sidebar-group>
    @endif

    @if($hasDadAccess)
        <x-sidebar-group label="DAD">
            <x-sidebar-item :href="url('/dad/projects')" label="工事案件" :active="request()->is('dad/projects*')" />
            <x-sidebar-item :href="url('/dad/clients')" label="発注者管理" :active="request()->is('dad/clients*')" />
            <x-sidebar-item :href="url('/dad/subcontractors')" label="協力業者管理" :active="request()->is('dad/subcontractors*')" />
            <x-sidebar-item :href="url('/dad/employees')" label="従業員管理" :active="request()->is('dad/employees*')" />
        </x-sidebar-group>
    @endif

    {{-- ZEAL フィットネス事業 --}}
    @if($hasZealAccess)
        <x-sidebar-group label="ZEAL">
            <x-sidebar-item :href="url('/zeal')" label="ダッシュボード" :active="request()->is('zeal') || request()->routeIs('zeal.dashboard')" />
            <x-sidebar-item :href="url('/zeal/members')" label="会員管理" :active="request()->is('zeal/members*')" />
            <x-sidebar-item :href="url('/zeal/inquiries')" label="体験予約" :active="request()->is('zeal/inquiries*')" />
            <x-sidebar-item :href="url('/zeal/plans')" label="プランマスタ" :active="request()->is('zeal/plans*')" />
            <x-sidebar-item :href="url('/zeal/trainers')" label="トレーナーマスタ" :active="request()->is('zeal/trainers*')" />
        </x-sidebar-group>
    @endif

    @if($isExecutive)
        <x-sidebar-group label="システム管理">
            {{-- サブ見出し: テナント --}}
            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 20px 3px;">
                <span style="font-size: 10px; font-weight: 600; color: #6B7280; letter-spacing: 0.05em; white-space: nowrap;">テナント</span>
                <span style="flex: 1; height: 1px; background: #D1D5DB;"></span>
            </div>
            <x-sidebar-item :href="url('/admin/master/usage-types')" label="用途マスター" :active="request()->is('admin/master/usage-types*')" />
            <x-sidebar-item :href="url('/admin/master/structure-types')" label="構造マスター" :active="request()->is('admin/master/structure-types*')" />
            <x-sidebar-item :href="url('/admin/tenant-import')" label="テナントCSVインポート" :active="request()->is('admin/tenant-import*')" />
            {{-- サブ見出し: 賃貸マンション --}}
            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 20px 3px;">
                <span style="font-size: 10px; font-weight: 600; color: #6B7280; letter-spacing: 0.05em; white-space: nowrap;">賃貸マンション</span>
                <span style="flex: 1; height: 1px; background: #D1D5DB;"></span>
            </div>
            <x-sidebar-item :href="url('/admin/mansion-import')" label="賃貸マンションCSVインポート" :active="request()->is('admin/mansion-import*')" />
            {{-- サブ見出し: 不動産 --}}
            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 20px 3px;">
                <span style="font-size: 10px; font-weight: 600; color: #6B7280; letter-spacing: 0.05em; white-space: nowrap;">不動産</span>
                <span style="flex: 1; height: 1px; background: #D1D5DB;"></span>
            </div>
            <x-sidebar-item :href="url('/admin/master/re-cost-items')" label="原価項目マスター" :active="request()->is('admin/master/re-cost-items*')" />
            <x-sidebar-item :href="url('/admin/master/zoning-types')" label="用途地域マスター" :active="request()->is('admin/master/zoning-types*')" />
            {{-- サブ見出し: 住宅 --}}
            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 20px 3px;">
                <span style="font-size: 10px; font-weight: 600; color: #6B7280; letter-spacing: 0.05em; white-space: nowrap;">住宅</span>
                <span style="flex: 1; height: 1px; background: #D1D5DB;"></span>
            </div>
            <x-sidebar-item :href="url('/admin/survey-questions')" label="アンケート設問管理" :active="request()->is('admin/survey-questions*')" />
            <x-sidebar-item :href="url('/admin/customers/import')" label="顧客CSVインポート" :active="request()->is('admin/customers/import*')" />
            {{-- サブ見出し: DAD --}}
            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 20px 3px;">
                <span style="font-size: 10px; font-weight: 600; color: #6B7280; letter-spacing: 0.05em; white-space: nowrap;">DAD</span>
                <span style="flex: 1; height: 1px; background: #D1D5DB;"></span>
            </div>
            <x-sidebar-item :href="url('/admin/master/dad-specialties')" label="専門分野マスター" :active="request()->is('admin/master/dad-specialties*')" />
            {{-- サブ見出し: ZEAL --}}
            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 20px 3px;">
                <span style="font-size: 10px; font-weight: 600; color: #6B7280; letter-spacing: 0.05em; white-space: nowrap;">ZEAL</span>
                <span style="flex: 1; height: 1px; background: #D1D5DB;"></span>
            </div>
            <x-sidebar-item :href="url('/admin/zeal/member-import')" label="会員CSVインポート" :active="request()->is('admin/zeal/member-import*')" />
            {{-- サブ見出し: マスター --}}
            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 20px 3px;">
                <span style="font-size: 10px; font-weight: 600; color: #6B7280; letter-spacing: 0.05em; white-space: nowrap;">マスター</span>
                <span style="flex: 1; height: 1px; background: #D1D5DB;"></span>
            </div>
            <x-sidebar-item :href="url('/admin/users')" label="ユーザー管理" :active="request()->is('admin/users*')" />
            <x-sidebar-item :href="url('/admin/settings')" label="マスター設定" :active="request()->is('admin/settings*')" />
        </x-sidebar-group>
    @endif
</aside>
