{{--
    断られた保存（GET 以外）の Ajax の文を作る関数（設計メモ 2026-11-26-save-ajax-403-design.md）。一覧のステータスの小窓
    （仕入れ案件・分譲地・建売）・契約の画面の買主の小窓（登録・重複の確認）・仕入れ先の簡易登録・XMLHttpRequest の部品
    buyers/_xhr_failure_message（買主一覧のランク変更・登録画面の「この部署に取り込む」）が読む。

    ajaxSaveFailureMessage(status, body, failed, fallback) の決まり（failed は「ランクを変更できませんでした。」の形の 1 文）:
      1. 419・401 で本文の message が空でない文字 → その message だけ（Bug #137。サーバーの文は
         「…送れませんでした。画面を開き直して…」のように 1 文で完結している。bootstrap/app.php）
      2. 403 で本文の message に日本語（ASCII 以外の文字）がある → failed ＋その理由（役割・部署・決裁のみの門番の
         abort(403, …) の文。判定は取得の部品 _ajax_get_failure・原価のカードの realEstateCostAccessError と同じ）
      3. ほか → fallback（呼ぶ側の今の番号つきの文。null を渡せば null）
    ⚠ 呼ぶ側は自分のファイルでこの部品を include する（SaveAjaxFailureScanTest）。once は、同じ画面で 2 回読んでも
      関数を 1 回だけ描くため。
    ⚠ 説明はこの Blade コメントに書く。script の中のコメントにアットマークで始まる語やコンポーネントのタグを書くと、
      Blade が展開して壊す（Bug #30）。script の中に「fetch」に丸括弧を続けた形も書かない（Ajax の全件分類の走査が拾う）。
--}}
@once
<script>
function ajaxSaveFailureMessage(status, body, failed, fallback) {
    var message = body && typeof body.message === 'string' ? body.message : '';
    if ((status === 419 || status === 401) && message !== '') {
        return message;
    }
    if (status === 403 && /[^\x00-\x7f]/.test(message)) {
        return failed + message;
    }
    return fallback;
}
</script>
@endonce
