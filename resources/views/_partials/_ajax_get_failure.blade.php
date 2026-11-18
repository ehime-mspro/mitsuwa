{{--
    断られた GET の Ajax の文を作る関数（設計メモ 2026-11-17-get-ajax-failure-design.md）。物件・分譲地を選んだときの
    区画などの取得・顧客や仕入れ先の検索・住所→〒が読む。
    GET は送信の鍵を確かめないので、ログインが切れたときは 419 ではなく 401 になる。基幹の GET の 401 の本文は英語の
    「Unauthenticated.」のまま（bootstrap/app.php。日本語にするのは基幹の GET 以外と決裁の URL。2026-11-18）なので、文は画面で作る。

    ajaxGetFailureMessage(status, body, what, fallback) の決まり（what は「空き区画を取得」の形）:
      1. 401・403 で本文の message に日本語（ASCII 以外の文字）がある → 「{what}できませんでした。」＋その理由
         （無効にされた人の 401・権限の無い人の 403。判定は原価のカードの realEstateCostAccessError と同じ）
      2. 401 → 「画面を開いてから時間がたったか、ほかの画面でログアウトしたため、{what}できませんでした。…」
      3. ほか → fallback（呼ぶ側の今の番号つきの文。null を渡せば null）
    ajaxGetFailure(res, what, fallback) は、応答の本文を JSON として読み（読めなければ本文なし）、上の文を Promise で返す。

    ⚠ 呼ぶ側の script より前に include する。once は、同じ画面で 2 回読んでも関数を 1 回だけ描くため。
    ⚠ 説明はこの Blade コメントに書く。script の中のコメントにアットマークで始まる語やコンポーネントのタグを書くと、
      Blade が展開して壊す（Bug #30）。script の中に「fetch」に丸括弧を続けた形も書かない（Ajax の全件分類の走査が拾う）。
--}}
@once
<script>
function ajaxGetFailureMessage(status, body, what, fallback) {
    var message = body && typeof body.message === 'string' ? body.message : '';
    if ((status === 401 || status === 403) && /[^\x00-\x7f]/.test(message)) {
        return what + 'できませんでした。' + message;
    }
    if (status === 401) {
        return '画面を開いてから時間がたったか、ほかの画面でログアウトしたため、' + what + 'できませんでした。入力中の内容があれば控えてから、画面を開き直してください。';
    }
    return fallback;
}

function ajaxGetFailure(res, what, fallback) {
    return res.json().then(function (body) { return body; }, function () { return null; }).then(function (body) {
        return ajaxGetFailureMessage(res.status, body, what, fallback);
    });
}
</script>
@endonce
