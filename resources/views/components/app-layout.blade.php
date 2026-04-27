<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '経営管理システム') - {{ config('app.name', '経営管理システム') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased" x-data="{ sidebarOpen: false, sidebarExpanded: true }">
    <div class="flex flex-col min-h-screen">
        {{-- ヘッダー --}}
        @include('layouts.partials.header')

        <div class="flex flex-1 overflow-hidden">
            {{-- サイドバー --}}
            @include('layouts.partials.sidebar')

            {{-- メインコンテンツ --}}
            <main class="flex-1 overflow-y-auto bg-gray-100">
                <div class="p-4 lg:p-8">
                    {{-- パンくずリスト --}}
                    @hasSection('breadcrumb')
                        <nav class="text-xs text-gray-400 mb-5">
                            <a href="{{ route('dashboard') }}" class="hover:text-emerald-600 transition-colors">ホーム</a>
                            @yield('breadcrumb')
                        </nav>
                    @endif

                    {{-- フラッシュメッセージ --}}
                    @if(session('success'))
                        <div class="mb-5 flex items-center gap-2 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200">
                            <svg class="w-4 h-4 text-emerald-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><polyline points="22 4 12 14.01 9 11.01" />
                            </svg>
                            <span class="text-sm text-emerald-800">{{ session('success') }}</span>
                        </div>
                    @endif

                    @if(session('error'))
                        <div class="mb-5 flex items-center gap-2 px-4 py-3 rounded-lg bg-red-50 border border-red-200">
                            <svg class="w-4 h-4 text-red-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10" /><line x1="15" y1="9" x2="9" y2="15" /><line x1="9" y1="9" x2="15" y2="15" />
                            </svg>
                            <span class="text-sm text-red-800">{{ session('error') }}</span>
                        </div>
                    @endif

                    {{-- ページコンテンツ --}}
                    @yield('content')
                </div>
            </main>
        </div>
    </div>
</body>
</html>
