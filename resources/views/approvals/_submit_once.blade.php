{{--
    確定の二度押し止め（Task 19 の C2・N-3。手本は基幹の顧客取込の確定〈Bug #67〉）。
    詳細の判断・取り下げ・条件確認・下書きの削除（requests/_actions）と、申請種類の管理の保存（admin/types）のフォームに付ける:
      <form … x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
    1 回目は通して印を立て、2 回目からは送信を取り消す。ボタンは :disabled="submitting"、横の role="status" に「送っています…」。
    「戻る」で画面がそのまま戻ったとき（bfcache）は pageshow で印を下ろす（Bug #65 と同じく persisted で絞らない）。

    ⚠ **ここがこの部品の唯一の定義**（使う画面が @include する。@once で 1 ページに 1 回だけ出す。_partials/_schedule_gantt_style と同じ形）
    ⚠ 押したボタンは押せなくなるので、送る値はボタンの name・value に持たせず hidden で持つ（押せなくしたボタンの値は送られない）
    ⚠ x-data にアロー関数を書かない（Top trap #4）
--}}
@once
    @push('scripts')
        <script>
        function approvalSubmitOnce() {
            return {
                submitting: false,
                onSubmit: function (event) {
                    if (this.submitting) {
                        event.preventDefault();
                        return;
                    }
                    this.submitting = true;
                },
                resetSubmit: function () {
                    this.submitting = false;
                }
            };
        }
        </script>
    @endpush
@endonce
