{{-- 前回からの変更点（出し直した申請。直前の回の控えと今の回の控えを比べる。設計書 §5.13）。
     どちらも提出した控えなので、差戻し中の直しかけは入らない（D26）。色だけに頼らず「＋」「−」と読み上げの言葉を付ける。 --}}
@if($changes !== null)
    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">
            前回からの変更点
            <span class="ml-1 text-[12px] font-normal text-gray-500">（{{ $approvalRequest->round - 1 }} 回目 → {{ $approvalRequest->round }} 回目の提出）</span>
        </h2>
        @if(! \App\Support\Approval\RequestSnapshot::hasChanges($changes))
            <p class="px-5 py-4 text-[13px] text-gray-500">前回の提出から、中身と添付は変わっていません。</p>
        @else
            <div class="px-5 py-4 space-y-4 text-[13px]">
                @if($changes['fields'] !== [])
                    <dl class="grid grid-cols-1 sm:grid-cols-[9em_1fr] gap-x-4 gap-y-2">
                        @foreach($changes['fields'] as $field)
                            <dt class="text-gray-500">{{ $field['label'] }}</dt>
                            <dd class="break-words">
                                <span class="text-red-700 line-through"><span class="sr-only">前: </span>{{ $field['before'] }}</span>
                                <span class="mx-1 text-gray-400" aria-hidden="true">→</span>
                                <span class="font-semibold text-emerald-800"><span class="sr-only">後: </span>{{ $field['after'] }}</span>
                            </dd>
                        @endforeach
                    </dl>
                @endif

                @if($changes['body'] !== null)
                    <div>
                        <p class="text-[12px] font-semibold text-gray-500 mb-1.5">重点ポイント（5W2H）の前回との違い（＋ 増えた行・− 消えた行）</p>
                        <ol class="rounded-md border border-gray-200 overflow-hidden leading-relaxed">
                            @foreach($changes['body'] as $op)
                                @switch($op['type'])
                                    @case(\App\Support\Approval\LineDiff::ADDED)
                                        <li class="flex gap-2 px-3 py-0.5 bg-emerald-50 text-emerald-800"><span class="shrink-0 font-mono" aria-hidden="true">＋</span><span class="sr-only">増えた行: </span><span class="whitespace-pre-wrap break-words min-w-0">{{ $op['line'] }}</span></li>
                                        @break
                                    @case(\App\Support\Approval\LineDiff::REMOVED)
                                        <li class="flex gap-2 px-3 py-0.5 bg-red-50 text-red-700"><span class="shrink-0 font-mono" aria-hidden="true">−</span><span class="sr-only">消えた行: </span><span class="whitespace-pre-wrap break-words min-w-0 line-through">{{ $op['line'] }}</span></li>
                                        @break
                                    @default
                                        <li class="flex gap-2 px-3 py-0.5 text-gray-700"><span class="shrink-0 font-mono text-gray-300" aria-hidden="true">&nbsp;</span><span class="whitespace-pre-wrap break-words min-w-0">{{ $op['line'] }}</span></li>
                                @endswitch
                            @endforeach
                        </ol>
                    </div>
                @endif

                @if($changes['attachments_added'] !== [] || $changes['attachments_removed'] !== [])
                    <div>
                        <p class="text-[12px] font-semibold text-gray-500 mb-1.5">添付</p>
                        <ul class="space-y-1">
                            @foreach($changes['attachments_added'] as $attachment)
                                <li class="text-emerald-800"><span aria-hidden="true">＋ </span><span class="sr-only">足した添付: </span><a href="{{ route('approvals.attachments.show', $attachment['id']) }}" target="_blank" rel="noopener" class="hover:underline break-all">{{ $attachment['name'] }}</a></li>
                            @endforeach
                            @foreach($changes['attachments_removed'] as $attachment)
                                {{-- 外した添付も控えに入っているので開ける（見られる範囲の確認と記録は §5.7 と同じ） --}}
                                <li class="text-red-700"><span aria-hidden="true">− </span><span class="sr-only">外した添付: </span><a href="{{ route('approvals.attachments.show', $attachment['id']) }}" target="_blank" rel="noopener" class="line-through hover:underline break-all">{{ $attachment['name'] }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    </section>
@endif
