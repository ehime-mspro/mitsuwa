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

        // Google Maps 認証失敗（課金無効 / キー無効 / リファラー制限等）時のフォールバック表示。
        // Google が認証失敗時に window.gm_authFailure を1度だけ呼ぶ仕様を利用する。
        // 認証成功時は呼ばれないため、正常な地図表示には一切影響しない。
        window.__mapAuthFailed = false;
        window.renderMapFallback = function (el) {
            if (!el || el.dataset.mapFallbackDone === '1') return;
            el.dataset.mapFallbackDone = '1';
            el.innerHTML =
                '<div style="height:100%;min-height:180px;display:flex;flex-direction:column;'
              + 'align-items:center;justify-content:center;gap:8px;padding:16px;box-sizing:border-box;'
              + 'background:#f9fafb;border:1px dashed #d1d5db;border-radius:8px;color:#6b7280;text-align:center;">'
              + '<svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="1.7" '
              + 'stroke-linecap="round" stroke-linejoin="round"><path d="M1 6v15l7-3 8 3 7-3V3l-7 3-8-3-7 3"/>'
              + '<line x1="2" y1="2" x2="22" y2="22"/></svg>'
              + '<div style="font-size:14px;font-weight:600;color:#374151;">地図を一時的に表示できません</div>'
              + '<div style="font-size:12px;line-height:1.5;">地図サービスに接続できません。<br>'
              + '時間をおいて再度お試しください。</div></div>';
        };
        window.gm_authFailure = function () {
            window.__mapAuthFailed = true;
            document.querySelectorAll('[data-map-fallback]').forEach(window.renderMapFallback);
        };
    </script>

    {{-- ページ固有スクリプトの差し込み口。
         @push('scripts') を持つビューのぶんだけ出力され、push が無いページでは何も出ない。
         ⚠ ここが無いと @push('scripts') の中身がサイレントに破棄される（2026-07-26 に本番で発覚）。
           注文住宅一覧の進捗ステップバーと建売契約編集の Alpine コンポーネントが、
           初期コミット 2046289d 以来ずっと動いていなかった。
         ⚠ 位置は body 末尾かつ上の <script> より後。@vite は module（defer）で Alpine の起動が
           DOM パース後になるため、ここで定義した関数は x-data の評価時に間に合う。
           また @yield('content') より後なので、スクリプトが参照する DOM 要素は既に存在する。
         回帰テスト: tests/Feature/LayoutScriptStackTest.php --}}
    @stack('scripts')
</body>
</html>
