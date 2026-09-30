{{--
    確定のボタンの二度押し止め（設計書 2026-09-28-import-double-submit-design.md §4.5）。関数 submitOnce() の唯一の定義。
    確定のフォームを持つ画面が、フォームと同じ if の中でこの部品を読み込み、フォームにこう書く:
      x-data="submitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="onPageShow($event)"
      ボタンは :disabled="submitting"、横の role="status" に「取り込んでいます…」（x-text で文字だけ変える）

    ・1 回目の送信は通して submitting を立て、2 回目以降は送信を取り消す（最後の歯止めはサーバの 1 回限りの鍵）
    ・pageshow（window にだけ届く）で印を下ろす。event.persisted で絞らない（Bug #65）
    ・reloadOnReturn: true（周辺ビル。確認画面が無く、GET の取込の画面で鍵を出す）は、次の 2 つのときだけ読み込み直して
      新しい鍵にする: ① bfcache から戻り、かつ送ったあと ② bfcache を使わずに「戻る」で表示した（ナビゲーションの種類が
      back_forward）。それ以外（作業の途中で別の画面へ移って戻った）は、読み込んだファイルと列の対応づけを残して印を下ろすだけ。
      読み込み直したあとは種類が reload になるので、繰り返さない
    ⚠ 説明はこの Blade コメントに書く。script の中のコメントにアットマークで始まる語やコンポーネントのタグを書くと、
      Blade が展開して壊す（Bug #30）
    ⚠ once で 1 ページに 1 回だけ出し、レイアウトの scripts のスタック（Alpine の起動より前に動く）へ積む
--}}
@once
    @push('scripts')
        <script>
        function submitOnce(options) {
            var reloadOnReturn = !!(options && options.reloadOnReturn);
            return {
                submitting: false,
                onSubmit: function (event) {
                    if (this.submitting) {
                        event.preventDefault();
                        return;
                    }
                    this.submitting = true;
                },
                onPageShow: function (event) {
                    if (reloadOnReturn && (event.persisted ? this.submitting : submitOnceNavigationType() === 'back_forward')) {
                        window.location.reload();
                        return;
                    }
                    this.submitting = false;
                }
            };
        }
        function submitOnceNavigationType() {
            var entries = window.performance && window.performance.getEntriesByType
                ? window.performance.getEntriesByType('navigation') : [];
            return entries.length > 0 ? entries[0].type : '';
        }
        </script>
    @endpush
@endonce
