<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '経営管理システム') - {{ config('app.name', '経営管理システム') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    // サイドバー アコーディオン用: 現在ページのセクション判定
    // 該当グループをデフォルトで展開する
    $currentSection = match (true) {
        request()->is('zeal*')                                                                     => 'zeal',
        request()->is('tenant/transactions*')                                                      => 'income',
        request()->is('tenant*')                                                                   => 'tenant',
        request()->is('mansion*')                                                                  => 'mansion',
        request()->is('realestate*')                                                               => 'realestate',
        request()->is('housing*')                                                                  => 'housing',
        request()->is('dad*')                                                                      => 'dad',
        request()->is('admin*')                                                                    => 'admin',
        request()->is('dashboard*') || request()->is('/') || request()->is('home')                 => 'dashboard',
        default                                                                                    => 'dashboard',
    };
@endphp
<body class="antialiased overflow-hidden" style="height: 100vh;" x-data="{ sidebarOpen: false, sidebarExpanded: true }">
    <div class="flex flex-col h-full">
        {{-- ヘッダー --}}
        @include('layouts.partials.header')

        <div class="flex flex-1 overflow-hidden" style="min-height: 0;">
            {{-- サイドバー --}}
            @include('layouts.partials.sidebar')

            {{-- メインコンテンツ --}}
            <main class="flex-1 overflow-y-auto bg-gray-100" style="min-height: 0;">
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

    <script>
        // 数値入力欄（inputmode="numeric" / "decimal" / type="number"）の全角文字を自動で半角に変換
        // 変換対象: 全角数字 ０-９ → 0-9 / 全角ピリオド ． → . / 全角マイナス －− → - / 全角コンマ ， → ,
        // 注意: type="number" は仕様上、全角ピリオド入力時に value が空になるブラウザがあるため、
        //       小数入力欄は type="text" inputmode="decimal" を使うこと（type="number" のままだと補正不能）
        document.addEventListener('input', function (e) {
            const t = e.target;
            if (!t || !t.matches) return;
            if (!t.matches('input[inputmode="numeric"], input[inputmode="decimal"], input[type="number"]')) return;
            const v = t.value;
            let nv = v.replace(/[０-９]/g, function (c) {
                return String.fromCharCode(c.charCodeAt(0) - 0xFEE0);
            });
            nv = nv.replace(/．/g, '.').replace(/[－−]/g, '-').replace(/，/g, ',');
            if (nv !== v) {
                const start = t.selectionStart;
                const end = t.selectionEnd;
                t.value = nv;
                try { t.setSelectionRange(start, end); } catch (_) {}
            }
        }, true);

        // サイドバー アコーディオン用 Alpine ストア
        // ページロード時は常に全グループ閉じた状態で開始する（localStorage 永続化なし）。
        // セッション中の開閉状態のみメモリに保持し、リロード or ページ遷移でリセットされる。
        // 過去バージョンが残した localStorage キーがあれば一度だけ掃除する。
        document.addEventListener('alpine:init', function () {
            try { localStorage.removeItem('sidebar_groups_open_v1'); } catch (e) {}

            window.Alpine.store('sidebarGroups', {
                // Set のままだと Alpine リアクティブが効きにくいので Array で持つ
                openList: [],

                isOpen: function (section) {
                    if (!section) return true;
                    return this.openList.indexOf(section) !== -1;
                },

                setOpen: function (section, isOpen) {
                    if (!section) return;
                    var idx = this.openList.indexOf(section);
                    if (isOpen && idx === -1) {
                        this.openList.push(section);
                    } else if (!isOpen && idx !== -1) {
                        this.openList.splice(idx, 1);
                    }
                }
            });
        });
    </script>
</body>
</html>
