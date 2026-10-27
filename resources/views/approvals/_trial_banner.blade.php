{{-- 試す期間の帯（段階6 設計書 §5.6・計画 §0.6）。試す期間に、試す人と決裁の管理者に出す（LaunchState::showsTrialBanner）。
     決裁の画面のいちばん上（layouts/app.blade.php が、決裁の画面でだけ読み込む）。色は _mail_failure と同じ琥珀色。
     文言を変えるときは、手引きとその読みやすい版も直す --}}
@if(\App\Support\Approval\LaunchState::showsTrialBanner(Auth::user()))
    <div role="status" class="mb-5 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-[13px] text-amber-900">
        試しの期間です。ここで出した申請は使い始める前に消えます。
    </div>
@endif
