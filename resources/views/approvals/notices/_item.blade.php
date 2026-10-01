{{-- お知らせの 1 件（⑥ とホームの「新しいお知らせ」。段階3 設計書 §5.6）。未読は太字と「未読」の印。押すと既読にして申請の詳細へ。
     中身は操作の時点の控え（data）。保存した日時は JapanTime::format（Bug #61） --}}
@php $data = $notice->data; $unread = $notice->read_at === null; @endphp
<li>
    <a href="{{ route('approvals.notices.open', $notice->id) }}" class="block px-5 py-3 hover:bg-gray-50">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            @if($unread)
                <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-semibold text-white bg-red-600">未読</span>
            @endif
            <span class="text-[13px] break-words {{ $unread ? 'font-bold text-gray-900' : 'text-gray-700' }}">{{ $data['headline'] ?? '' }}：{{ $data['subject'] ?? '' }}</span>
            <span class="ml-auto text-[12px] text-gray-500 whitespace-nowrap">{{ \App\Support\JapanTime::format($notice->created_at, 'n/j H:i') }}</span>
        </div>
        <p class="mt-0.5 text-[12px] text-gray-500 break-words">{{ $data['applicant'] ?? '' }}（{{ $data['department'] ?? '' }}）@if(! empty($data['number']))・{{ $data['number'] }}@endif・{{ $data['actor'] ?? '' }}さんの操作・{{ $data['action'] ?? '' }}</p>
    </a>
</li>
