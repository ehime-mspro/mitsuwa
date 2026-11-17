{{--
    送信の鍵（CSRF）が合わない＝419 の画面（ふつうのフォームの送信。Laravel は既定の英語の画面「Page Expired」より先にこれを使う）。Bug #137。
    画面を開いたまま時間がたってセッションが切れたか、ほかの画面でログアウトして鍵が作り直されたときに出る。鍵の確かめは
    コントローラより前なので、何も保存していない。JSON を求める Ajax には bootstrap/app.php が同じ理由の文を JSON で返す。
    ⚠ ログインしていないこともある（ログイン画面を開いたまま時間がたったときも出る）ので、レイアウトは app でなく guest。
    ⚠ 決裁の画面のフォームにも出る。
--}}
<x-guest-layout title="画面の有効期限が切れました">
    <div class="w-full max-w-[420px]">
        <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(0,0,0,0.06),0_1px_2px_rgba(0,0,0,0.04)] px-10 py-11 max-sm:px-6">

            <div class="text-center mb-7">
                <img src="{{ asset('images/logo.png') }}" alt="ロゴ" class="w-[44px] h-auto mx-auto mb-4">
                <h1 class="text-xl font-bold text-gray-900 tracking-wide mb-1.5">画面の有効期限が切れました</h1>
                <div class="w-8 h-0.5 mx-auto rounded-full bg-gradient-to-r from-emerald-500 to-emerald-300"></div>
            </div>

            <p class="text-sm text-gray-700 leading-relaxed mb-3">画面を開いてから時間がたったか、ほかの画面でログアウトしたため、送れませんでした（何も保存していません）。</p>
            <p class="text-sm text-gray-700 leading-relaxed mb-7">ブラウザの「戻る」で前の画面に戻ると、入力した内容が残っていることがあります。控えてから画面を開き直して、もう一度やり直してください。</p>

            <div class="flex flex-col gap-2.5">
                <button type="button" onclick="history.back()" class="w-full h-[46px] bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-[10px] tracking-wide shadow-[0_2px_8px_rgba(16,163,127,0.3)] transition-all duration-200">前の画面に戻る</button>
                <a href="{{ url('/') }}" class="w-full h-[46px] flex items-center justify-center border border-gray-200 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-[10px] tracking-wide transition-all duration-200">トップページへ</a>
            </div>

            <p class="text-center text-xs text-gray-400 mt-6">エラー番号 419</p>
        </div>
    </div>
</x-guest-layout>
