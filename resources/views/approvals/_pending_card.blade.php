{{-- 基幹のダッシュボードの「決裁の対応待ち N 件」とホームへのリンク（段階2 設計書 §5.15・要件 12.2）。
     使い始める前は出さない（ApprovalMenu が null）。件数は 1 リクエストに 1 回だけ数える（サイドバーと同じ数を使う） --}}
@php $approvalPending = \App\Support\Approval\ApprovalMenu::pendingCount(auth()->user()); @endphp
@if($approvalPending !== null)
    <a href="{{ route('approvals.home') }}" class="mb-4 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-lg border px-4 py-3 text-[13px] {{ $approvalPending > 0 ? 'border-amber-200 bg-amber-50 text-amber-900 hover:bg-amber-100' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50' }}">
        <span><span class="font-semibold">決裁の対応待ち</span> <span class="font-bold tabular-nums">{{ $approvalPending }}</span> 件</span>
        <span class="font-semibold text-emerald-700">決裁のホームへ →</span>
    </a>
@endif
