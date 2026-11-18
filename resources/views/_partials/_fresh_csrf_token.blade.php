{{--
    419 の画面から「前の画面に戻る」で戻った画面の送信の鍵を、新しい鍵に差し替える（設計メモ 2026-11-18-419-back-fresh-token-design.md）。
    「戻る」はブラウザが読み直さずに手元の画面（bfcache か HTTP のキャッシュ）を出すので、戻った画面の鍵は古いままで、
    そのまま送るとまた 419 になっていた（Bug #137 の後回し。ログインの送信は Bug #138 で別に直した）。

    ・419 の画面（resources/views/errors/419.blade.php）が window.rememberFreshCsrfToken() で自分のメタの鍵を sessionStorage に残す。
      419 の応答を出した時点で、サーバーにはもう新しい鍵がある（セッションが切れていれば新しいセッションの鍵）
    ・この部品は読み込みのたびにその鍵を読んで消し、「戻る・進む」で表示したとき（ナビゲーションの種類が back_forward）だけ
      メタと hidden の _token をその鍵に差し替える。bfcache から出したとき（pageshow の persisted）も同じ
    ⚠ head の鍵のメタの直後・ページの script より前に置く（2 つのレイアウト）。画面を開いた時にメタを読む script が新しい鍵を読む
    ⚠ ふつうの読み込みでは当てずに消す。419 の画面のあと、戻らずにほかの画面へ進んでから「戻る」で別の画面を出したときに、
      古くなった鍵を当てないため。419 の画面自身も、head で前の鍵を消してから本文で新しい鍵を残す
    ⚠ pageshow でも差し替え直す。ブラウザは「戻る」でフォームの値（hidden を含むことがある）を戻すので、本文を読み終えた時点
      （DOMContentLoaded）の差し替えが戻されることがある
    ⚠ sessionStorage の読み書きは try で包む（プライベートの窓・サイトのデータを止めたときに例外を投げる。画面の JS を止めない）
    ⚠ 説明はこの Blade コメントに書く。script の中のコメントにアットマークで始まる語やコンポーネントのタグを書くと、
      Blade が展開して壊す（Bug #30）
--}}
<script>
(function () {
    var key = 'freshCsrfTokenAfter419';
    var fresh = null;

    function take() {
        try {
            var token = window.sessionStorage.getItem(key);
            window.sessionStorage.removeItem(key);
            return token;
        } catch (e) {
            return null;
        }
    }

    function navigationType() {
        var entries = window.performance && window.performance.getEntriesByType
            ? window.performance.getEntriesByType('navigation') : [];
        return entries.length > 0 ? entries[0].type : '';
    }

    function apply() {
        if (!fresh) return;
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.setAttribute('content', fresh);
        var inputs = document.querySelectorAll('input[name="_token"]');
        for (var i = 0; i < inputs.length; i++) inputs[i].value = fresh;
    }

    window.rememberFreshCsrfToken = function () {
        try {
            var meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) window.sessionStorage.setItem(key, meta.getAttribute('content'));
        } catch (e) {}
    };

    var token = take();
    if (navigationType() === 'back_forward') fresh = token;
    apply();
    document.addEventListener('DOMContentLoaded', apply);
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            var shown = take();
            if (shown) fresh = shown;
        }
        apply();
    });
})();
</script>
