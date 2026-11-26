{{--
    断られた XMLHttpRequest の文を作る関数（Bug #137・設計メモ 2026-11-26-save-ajax-403-design.md）。買主一覧のランク変更
    （buyers/index）と、登録画面の「この部署に取り込む」（buyers/_form）が読む。直す前は 2 か所に同じ読み取りが書いてあった。
    本文を JSON として読み（読めなければ本文なし）、決まりは保存の Ajax の部品 _partials/_ajax_save_failure の
    ajaxSaveFailureMessage に任せる: 419・401 はサーバーが返す日本語の理由（bootstrap/app.php）、403 は failed ＋日本語の理由
    （役割・部署の門番）、ほかは fallback。
    ⚠ 呼ぶ側の <script> より前に @include する。@once は、同じ画面で 2 回読んでも関数を 1 回だけ描くため。
--}}
@include('_partials._ajax_save_failure')
@once
<script>
function xhrFailureMessage(xhr, failed, fallback) {
    var body = null;
    try {
        body = JSON.parse(xhr.responseText);
    } catch (e) {}
    return ajaxSaveFailureMessage(xhr.status, body, failed, fallback);
}
</script>
@endonce
