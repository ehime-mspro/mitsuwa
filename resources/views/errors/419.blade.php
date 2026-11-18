{{--
    送信の鍵（CSRF）が合わない＝419 の画面（ふつうのフォームの送信。Laravel は既定の英語の画面「Page Expired」より先にこれを使う）。Bug #137。
    画面を開いたまま時間がたってセッションが切れたか、ほかの画面でログアウトして鍵が作り直されたときに出る。鍵の確かめは
    コントローラより前なので、何も保存していない。JSON を求める Ajax には bootstrap/app.php が同じ理由の文を JSON で返す。
    ⚠ ログインしていないこともある（ログアウトの送信など）ので、レイアウトは app でなく guest。ログインの送信には出さない
      （「前の画面に戻る」が古い鍵のログイン画面へ戻し、何度送っても 419 になるため。bootstrap/app.php が新しいログイン画面へ戻す。Bug #138）。
    ⚠ 決裁の画面のフォームにも出る。
    「前の画面に戻る」で戻った画面は古い鍵のままなので、この画面が新しい鍵を残し（window.rememberFreshCsrfToken()）、
    レイアウトの部品（_partials/_fresh_csrf_token）が戻った画面の鍵を差し替える。ログインしたままなら、戻った画面からそのまま送れる。
    ログインが切れていれば、送るとログイン画面に移る（設計メモ 2026-11-18-419-back-fresh-token-design.md）。
--}}
{{-- ⚠ ログインしているかは、鍵のメタを描くレイアウトより前に確かめる。「ログイン状態を保持」の人はここで自動でログインし直し、
     そのとき鍵が変わる（コンポーネントは中身を先に描くので、ここで確かめればメタは変わったあとの鍵になる。ExpiredSessionResponseTest） --}}
@php($signedIn = auth()->check())
<x-guest-layout title="画面の有効期限が切れました">
    <div class="w-full max-w-[420px]">
        <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(0,0,0,0.06),0_1px_2px_rgba(0,0,0,0.04)] px-10 py-11 max-sm:px-6">

            <div class="text-center mb-7">
                <img src="{{ asset('images/logo.png') }}" alt="ロゴ" class="w-[44px] h-auto mx-auto mb-4">
                <h1 class="text-xl font-bold text-gray-900 tracking-wide mb-1.5">画面の有効期限が切れました</h1>
                <div class="w-8 h-0.5 mx-auto rounded-full bg-gradient-to-r from-emerald-500 to-emerald-300"></div>
            </div>

            <p class="text-sm text-gray-700 leading-relaxed mb-3">画面を開いてから時間がたったか、ほかの画面でログアウトしたため、送れませんでした（何も保存していません）。</p>
            @if($signedIn)
                <p class="text-sm text-gray-700 leading-relaxed mb-7">「前の画面に戻る」で戻ると、そのまま送り直せます。入力した内容が消えていたら、入れ直してください。</p>
            @else
                <p class="text-sm text-gray-700 leading-relaxed mb-7">ログインが切れています。「前の画面に戻る」で戻って入力した内容を控えてから、ログインし直して、もう一度入力してください（戻った画面から送ると、ログイン画面に移ります）。</p>
            @endif

            <div class="flex flex-col gap-2.5">
                <button type="button" onclick="history.back()" class="w-full h-[46px] bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-[10px] tracking-wide shadow-[0_2px_8px_rgba(16,163,127,0.3)] transition-all duration-200">前の画面に戻る</button>
                @if($signedIn)
                    <a href="{{ url('/') }}" class="w-full h-[46px] flex items-center justify-center border border-gray-200 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-[10px] tracking-wide transition-all duration-200">トップページへ</a>
                @else
                    <a href="{{ route('login') }}" class="w-full h-[46px] flex items-center justify-center border border-gray-200 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-[10px] tracking-wide transition-all duration-200">ログイン画面へ</a>
                @endif
            </div>

            <p class="text-center text-xs text-gray-400 mt-6">エラー番号 419</p>
        </div>
    </div>
    {{-- 新しい鍵を残す（「前の画面に戻る」で戻った画面の鍵を、レイアウトの部品が差し替える） --}}
    <script>if (window.rememberFreshCsrfToken) { window.rememberFreshCsrfToken(); }</script>
</x-guest-layout>
