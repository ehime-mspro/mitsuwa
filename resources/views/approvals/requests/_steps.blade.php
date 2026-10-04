{{-- 回る順番と各人の判断（今の回。設計書 §5.12）。担当は今の設定から引く（CurrentHandler）。
     前の回の判断は「操作の記録」に出る。判断した段階には、判断したときに控えた印を出す（段階4 設計書 §5.4）。 --}}
@php $steps = $approvalRequest->currentSteps(); @endphp
<section class="bg-white rounded-lg border border-gray-200 mb-5">
    <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">
        回る順番
        @if($approvalRequest->round > 1)
            <span class="ml-1 text-[12px] font-normal text-gray-500">（{{ $approvalRequest->round }} 回目の提出）</span>
        @endif
    </h2>
    @if($steps->isEmpty())
        <p class="px-5 py-4 text-[13px] text-gray-400">提出すると、部門長・審査・社長の順に回ります。</p>
    @else
        <ol class="divide-y divide-gray-100">
            @foreach($steps as $step)
                <li class="px-5 py-3 text-[13px]">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="font-semibold text-gray-900 min-w-[4em]">{{ $step->kind->label() }}</span>
                                <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $step->status->badgeStyle() }}">{{ $step->status->label() }}</span>
                                @switch($step->status)
                                    @case(\App\Enums\ApprovalStepStatus::Done)
                                        <span class="font-semibold text-gray-900">{{ $step->result->labelFor($step->kind) }}</span>
                                        <span class="text-gray-700">{{ $step->actor?->name }}</span>
                                        <span class="text-[12px] text-gray-400">{{ \App\Support\JapanTime::format($step->acted_at) }}</span>
                                        @break
                                    @case(\App\Enums\ApprovalStepStatus::Skipped)
                                        <span class="text-gray-500">申請者が部門長のため省略</span>
                                        @break
                                    @case(\App\Enums\ApprovalStepStatus::Cancelled)
                                        <span class="text-gray-500">差戻し・取り下げのため打ち切り</span>
                                        @break
                                    @default
                                        <span class="text-gray-700">担当: {{ \App\Support\Approval\CurrentHandler::describe($step) }}</span>
                                        {{-- 届いた日時は待ちの段階だけに出す（取り消しで「まだ届いていない」に戻した段階は届いた日時が残る。2b 計画 §0.4） --}}
                                        @if($step->status === \App\Enums\ApprovalStepStatus::Waiting && $step->arrived_at)
                                            <span class="text-[12px] text-gray-400">{{ \App\Support\JapanTime::format($step->arrived_at) }} に届きました</span>
                                        @endif
                                @endswitch
                            </div>
                            @if($step->comment)
                                <p class="mt-1.5 rounded-md bg-gray-50 px-3 py-2 text-gray-800 whitespace-pre-wrap break-words">{{ $step->comment }}</p>
                            @endif
                        </div>
                        @if($stamp = \App\Support\Approval\Stamp::forStep($step))
                            {{-- StampSvg は文字を e() で包んだ SVG を返す --}}
                            <span class="shrink-0" data-stamp>{!! \App\Support\Approval\StampSvg::render($stamp) !!}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</section>
