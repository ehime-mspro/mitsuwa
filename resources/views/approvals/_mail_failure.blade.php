{{-- 通知メールが送れないときの帯（決裁の管理者だけ。段階3 設計書 D2・§5.6）。最後に送れなかった日時より後に 1 通でも送れたら出さない
     （MailDelivery::pendingFailure()）。決裁の管理者のホーム（使い始める前の準備中の画面も）と管理の画面の上に置く。
     送れなかったメールの送り直しの操作は作らない（D2）。保存した日時は JapanTime::format（Bug #61） --}}
@if(Auth::user()->isApprovalAdmin() && ($mailFailure = \App\Support\Approval\MailDelivery::pendingFailure()) !== null)
    <div class="mb-5 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-[13px] text-amber-900">
        通知メールが送れていません。最後に送れなかったのは {{ \App\Support\JapanTime::format($mailFailure['at'], 'n/j H:i') }}（宛先: {{ $mailFailure['to'] }}さん）です。決裁のお知らせは画面にも届いています。メールの設定の確認が必要です。
    </div>
@endif
