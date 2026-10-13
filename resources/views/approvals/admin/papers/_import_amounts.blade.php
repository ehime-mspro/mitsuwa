{{--
    紙の決裁台帳の取り込みの確認のうち、金額の控えの行（画面⑪。段階5 設計書 §5.9）。_import_preview が読む。
    必要変数: $analysis / $choosable（結び付け先を選べる行）/ $yen（金額の表示）
    ⚠ 確定のフォームがあるときはその中で読む（選んだ結び付け links[シートの番号:行] を確定で送る）。無いときは外で読む（選べる行も無い）
--}}
@if($analysis['amounts'] !== [])
    <div class="px-4 pt-3 pb-1.5 border-t border-gray-200">
        <h3 class="text-[13px] font-bold text-gray-900">金額の控え（{{ count($analysis['amounts']) }} 行）</h3>
        @if($choosable !== [])
            <p class="text-[12px] text-amber-800 mt-0.5">結び付け先が 1 つに決まらない行が {{ count($choosable) }} 行あります。結び付ける決裁No を選んでください（選ばなければ結び付けません）。</p>
        @endif
    </div>
    <div class="scroll-hint at-start">
        <div class="scroll-hint-inner">
            <table class="w-full min-w-[960px] border-collapse">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 min-w-[10rem]">台帳の場所</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">日付</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">申請者</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 min-w-[14rem]">件名</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">契約金額</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">工事原価</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">粗利益</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">結び付け先</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($analysis['amounts'] as $amount)
                        <tr class="{{ ! $amount['auto'] && $amount['candidates'] !== [] ? 'bg-amber-50' : '' }}">
                            <td class="px-4 py-2 border-b border-gray-100 text-[12px] text-gray-500">{{ $amount['where'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $amount['date'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $amount['applicant'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-900 break-words">{{ $amount['subject'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums whitespace-nowrap">{{ $yen($amount['amount']) }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums whitespace-nowrap">{{ $yen($amount['cost']) }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums whitespace-nowrap">{{ $yen($amount['profit']) }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700">
                                @if($amount['auto'])
                                    <span class="font-mono">{{ $amount['linked'] }}</span>（自動）
                                @elseif($amount['empty'])
                                    <span class="text-gray-500">金額がありません（結び付けません）</span>
                                @elseif($amount['candidates'] === [])
                                    <span class="text-gray-500">結び付けられる記録がありません</span>
                                @else
                                    <select name="links[{{ $amount['key'] }}]" aria-label="{{ $amount['where'] }} の結び付け先"
                                            class="h-8 max-w-[360px] px-2 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer">
                                        <option value="{{ \App\Support\Approval\PaperImportAnalysis::NO_LINK }}">結び付けない</option>
                                        @foreach($amount['candidates'] as $candidate)
                                            <option value="{{ $candidate['number'] }}" {{ $amount['linked'] === $candidate['number'] ? 'selected' : '' }}>{{ $candidate['label'] }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>{{-- /scroll-hint-inner --}}
        <div class="scroll-hint-text">← スクロールできます →</div>
    </div>{{-- /scroll-hint --}}
@endif
