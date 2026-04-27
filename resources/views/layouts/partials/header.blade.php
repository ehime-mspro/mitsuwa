<header class="h-[52px] bg-[#0B5D45] flex items-center justify-between px-4 lg:px-5 relative z-30">
    {{-- 左側: モバイル用ハンバーガー＋ロゴ --}}
    <div class="flex items-center gap-3">
        {{-- モバイル用サイドバー開閉ボタン --}}
        <button
            @click="sidebarOpen = !sidebarOpen"
            class="lg:hidden text-emerald-200 hover:text-white focus:outline-none"
        >
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <line x1="3" y1="6" x2="21" y2="6" />
                <line x1="3" y1="12" x2="21" y2="12" />
                <line x1="3" y1="18" x2="21" y2="18" />
            </svg>
        </button>

        {{-- ロゴのみ --}}
        <img src="{{ asset('images/logo_yoko.png') }}" alt="ミツワ都市開発" class="h-5 w-auto">
    </div>

    {{-- 右側: ユーザーメニュー --}}
    <div class="relative" x-data="{ userMenuOpen: false }">
        <button
            @click="userMenuOpen = !userMenuOpen"
            class="flex items-center gap-2 focus:outline-none"
        >
            {{-- ユーザー名（アバターなし） --}}
            <span class="text-sm text-emerald-100">{{ Auth::user()->name }}</span>

            {{-- 矢印 --}}
            <svg class="w-3.5 h-3.5 text-emerald-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9" />
            </svg>
        </button>

        {{-- ドロップダウンメニュー --}}
        <div
            x-show="userMenuOpen"
            @click.outside="userMenuOpen = false"
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="absolute right-0 top-full mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-200 py-1 z-50"
            style="display: none;"
        >
            {{-- ユーザー情報 --}}
            <div class="px-4 py-2 border-b border-gray-100">
                <p class="text-sm font-medium text-gray-800 truncate">{{ Auth::user()->name }}</p>
                <p class="text-xs text-gray-500 truncate">{{ Auth::user()->role->label() }}</p>
            </div>

            {{-- パスワード変更 --}}
            <a href="{{ route('password.change') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                パスワード変更
            </a>

            {{-- ログアウト --}}
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                    ログアウト
                </button>
            </form>
        </div>
    </div>
</header>
