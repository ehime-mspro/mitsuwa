{{--
    断られた XMLHttpRequest の文を作る関数（Bug #137）。買主一覧のランク変更（buyers/index）と、登録画面の
    「この部署に取り込む」（buyers/_form）が読む。直す前は 2 か所に同じ読み取りが書いてあった。
    419・401（ログインが切れた・ほかの画面でログアウトした・パスワードが変わった・無効にされた）はサーバーが返す日本語の理由
    （bootstrap/app.php。基幹の GET 以外の 401 は 2026-11-18 から日本語）、ほかは fallback。本文が JSON でなければ fallback。
    ⚠ 呼ぶ側の <script> より前に @include する。@once は、同じ画面で 2 回読んでも関数を 1 回だけ描くため。
--}}
@once
<script>
// 断られた XMLHttpRequest の文: 419・401 はサーバーが返す日本語の理由（bootstrap/app.php）、ほかは fallback
function xhrFailureMessage(xhr, fallback) {
    if (xhr.status === 419 || xhr.status === 401) {
        try {
            var body = JSON.parse(xhr.responseText);
            if (body && typeof body.message === 'string' && body.message !== '') return body.message;
        } catch (e) {}
    }
    return fallback;
}
</script>
@endonce
