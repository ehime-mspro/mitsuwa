{{-- お知らせのベル（ヘッダーの右・基幹の利用者にも決裁のみ利用者にも同じ所。段階3 設計書 §5.6・D14）。
     未読があれば赤い丸に数（100 以上は 99+）。押すとお知らせ一覧（⑥）へ（プルダウンは出さない）。
     使い始める前は出さない（ApprovalMenu::unreadNotices() が null。基幹の画面に決裁の気配を出さない）。
     ⚠ この partial の名前を sidebar で始めない・<nav> を使わない（LayoutSidebar*Test・LayoutBreadcrumbHomeTest の走査） --}}
@php $unreadNotices = \App\Support\Approval\ApprovalMenu::unreadNotices(Auth::user()); @endphp
@if($unreadNotices !== null)
    <a href="{{ route('approvals.notices.index') }}" class="relative inline-flex items-center justify-center w-9 h-9 rounded-md text-emerald-100 hover:text-white hover:bg-white/10 transition-colors"
       aria-label="お知らせ（未読 {{ $unreadNotices }} 件）" title="お知らせ">
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
            <path d="M13.73 21a2 2 0 0 1-3.46 0" />
        </svg>
        @if($unreadNotices > 0)
            <span class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10px] font-bold leading-[18px] text-center" aria-hidden="true">{{ $unreadNotices > 99 ? '99+' : $unreadNotices }}</span>
        @endif
    </a>
@endif
