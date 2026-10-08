{{-- 提出の履歴（提出の回ごとに、その回の控えの中身・添付と、その回の段階の判断。設計書 §5.13）。
     控えは提出した中身なので、差戻し中の直しかけは入らない（D26）。外した添付もここから開ける（見られる範囲の確認と記録は §5.7 と同じ）。
     ⚠ 保存した日時は JapanTime::format で出す（Bug #61）。金額は「28,500,000円」の形（規約: ¥ を付けない） --}}
@if($revisions->isNotEmpty())
    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <h2 class="px-5 py-3 border-b border-gray-200 text-[14px] font-bold text-gray-900">提出の履歴</h2>
        <div class="divide-y divide-gray-100">
            @foreach($revisions->sortByDesc('round') as $revision)
                @php
                    $snapshot   = $revision->snapshot;
                    $roundSteps = $approvalRequest->steps->where('round', $revision->round);
                @endphp
                <details class="px-5 py-3 text-[13px]">
                    <summary class="cursor-pointer font-semibold text-gray-900">
                        {{ $revision->round }} 回目の提出
                        <span class="ml-1 font-normal text-[12px] text-gray-500">{{ \App\Support\JapanTime::format($revision->created_at) }}</span>
                        @if($revision->round === $approvalRequest->round)
                            <span class="ml-1 font-normal text-[12px] text-gray-500">（最後に提出した中身）</span>
                        @endif
                    </summary>
                    <dl class="mt-3 grid grid-cols-1 sm:grid-cols-[9em_1fr] gap-x-4 gap-y-2">
                        <dt class="text-gray-500">申請部門</dt>
                        <dd class="text-gray-900">{{ $snapshot['department']['name'] ?? '—' }}</dd>
                        <dt class="text-gray-500">申請の種類</dt>
                        <dd class="text-gray-900">{{ $snapshot['type']['name'] ?? '—' }}</dd>
                        <dt class="text-gray-500">件名</dt>
                        <dd class="text-gray-900 break-words">{{ $snapshot['subject'] ?? '—' }}</dd>
                        <dt class="text-gray-500">金額（税抜）</dt>
                        <dd class="text-gray-900">{{ isset($snapshot['amount']) ? number_format((int) $snapshot['amount']) . '円' : '—' }}</dd>
                        <dt class="text-gray-500">実施時期</dt>
                        <dd class="text-gray-900 break-words">{{ ($snapshot['schedule'] ?? '') !== '' ? $snapshot['schedule'] : '—' }}</dd>
                        <dt class="text-gray-500">関連する決裁No</dt>
                        <dd class="text-gray-900 font-mono">{{ ($snapshot['related_numbers'] ?? []) === [] ? '—' : implode('・', $snapshot['related_numbers']) }}</dd>
                    </dl>
                    {{-- 本文の欄（その回の控えの明細表・追加の欄・定型文。段階5 より前の控えには無い＝5W2H の形） --}}
                    <div class="mt-3">
                        @include('approvals.requests._body_section', [
                            'table'     => is_array($snapshot['amount_table'] ?? null) ? $snapshot['amount_table'] : null,
                            'extras'    => \App\Support\Approval\RequestExtras::ordered($snapshot['extras'] ?? null),
                            'fixedText' => $snapshot['fixed_text'] ?? null,
                            'body'      => $snapshot['body'] ?? '',
                        ])
                    </div>
                    <p class="mt-3 text-[12px] font-semibold text-gray-500 mb-1.5">添付</p>
                    <ul class="space-y-1">
                        @forelse($snapshot['attachments'] ?? [] as $attachment)
                            <li><a href="{{ route('approvals.attachments.show', $attachment['id']) }}" target="_blank" rel="noopener" class="text-emerald-600 hover:underline break-all">{{ $attachment['name'] }}</a></li>
                        @empty
                            <li class="text-gray-400">添付はありません。</li>
                        @endforelse
                    </ul>
                    <p class="mt-3 text-[12px] font-semibold text-gray-500 mb-1.5">この回の判断</p>
                    <ul class="space-y-1.5">
                        @foreach($roundSteps as $step)
                            <li>
                                <span class="font-semibold text-gray-900">{{ $step->kind->label() }}</span>
                                <span class="inline-block ml-1 px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $step->status->badgeStyle() }}">{{ $step->status->label() }}</span>
                                @if($step->status === \App\Enums\ApprovalStepStatus::Done)
                                    <span class="ml-1 font-semibold text-gray-900">{{ $step->result->labelFor($step->kind) }}</span>
                                    <span class="ml-1 text-gray-700">{{ $step->actor?->name }}</span>
                                    <span class="ml-1 text-[12px] text-gray-400">{{ \App\Support\JapanTime::format($step->acted_at) }}</span>
                                @elseif($step->status === \App\Enums\ApprovalStepStatus::Skipped)
                                    <span class="ml-1 text-gray-500">申請者が部門長のため省略</span>
                                @endif
                                @if($step->comment)
                                    <p class="mt-1 rounded-md bg-gray-50 px-3 py-2 text-gray-800 whitespace-pre-wrap break-words">{{ $step->comment }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endforeach
        </div>
    </section>
@endif
