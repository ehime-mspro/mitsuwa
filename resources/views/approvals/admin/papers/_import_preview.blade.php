{{--
    紙の決裁台帳の取り込みの確認（画面⑪。段階5 設計書 §5.9・D6・D7・D20）。

    必要変数: $analysis（PaperImportAnalysis::analyze() の戻り値）/ $ledgerData / $fileName / $year / $years / $importToken

    ⚠ 取り込まない行（rowErrors・赤）と注意（warnings・黄）は**別のブロック**に、**別の言い方**で出す（Bug #54 ④）
    ⚠ 結び付けの誤り（linkErrors）があっても確定のフォームは描く（選び直して送る。確定はファイルを読み直して確かめ直し、
      まだ誤りがあればこの画面をもう一度出す＝PaperImportController::execute()）
    ⚠ 確定のフォームは「足す・補う記録がある」か「選べる金額の控えの行がある」ときだけ描く（画面が決して送らない POST をテストで
      作らない。Bug #54 ③）。足す・補う記録が無いまま送られたときは、コントローラが断る
    ⚠ 確定は 1 回限りの鍵（import_token）と二度押し止めの部品（_partials._submit_once）。押したボタンは押せなくなるので、
      送る値はボタンに持たせず hidden と select で持つ
--}}
@php
    $choosable = array_values(array_filter($analysis['amounts'], fn (array $a) => ! $a['auto'] && $a['candidates'] !== []));
    $canSubmit = $analysis['addCount'] + $analysis['fillCount'] > 0 || $choosable !== [];
    $kinds     = [
        \App\Support\Approval\PaperLedgerFile::REGISTER => '受付簿',
        \App\Support\Approval\PaperLedgerFile::AMOUNTS  => '金額の控え',
        \App\Support\Approval\PaperLedgerFile::IGNORED  => '読まない',
    ];
    $yen = fn (?int $value) => $value === null ? '—' : \App\Support\Approval\AmountTable::yen($value);
@endphp
<section class="bg-white rounded-lg border border-gray-200">
    <div class="px-4 py-3 border-b border-gray-200">
        <h2 class="text-[14px] font-bold text-gray-900 mb-1">② 取り込む内容の確認</h2>
        <p class="text-[12px] text-gray-500 mb-2 break-all">{{ $fileName }}（{{ $years[$year] ?? $year }}）</p>
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-700 text-[12px] font-semibold">足す {{ $analysis['addCount'] }} 件</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-blue-50 text-blue-700 text-[12px] font-semibold">金額を補う {{ $analysis['fillCount'] }} 件</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-gray-100 text-gray-700 text-[12px]">すでにある {{ count($analysis['existing']) }} 件</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-red-50 text-red-700 text-[12px] font-semibold">取り込まない行 {{ count($analysis['rowErrors']) }} 件</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-amber-50 text-amber-800 text-[12px] font-semibold">注意 {{ count($analysis['warnings']) }} 件</span>
        </div>
    </div>

    {{-- シートごとの件数 --}}
    <div class="scroll-hint at-start border-b border-gray-200">
        <div class="scroll-hint-inner">
            <table class="w-full min-w-[720px] border-collapse">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">シート</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">種類</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">部門</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">読んだ行</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">足す</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">すでにある</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">取り込まない</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">結び付いた</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($analysis['sheets'] as $sheet)
                        <tr>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-900">{{ $sheet['title'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $kinds[$sheet['kind']] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">
                                @if($sheet['kind'] === \App\Support\Approval\PaperLedgerFile::REGISTER)
                                    {{ $sheet['department'] ?? "（{$sheet['code']} の部門がありません）" }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums">{{ $sheet['rows'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums">{{ $sheet['added'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums">{{ $sheet['existing'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-right tabular-nums {{ $sheet['errors'] > 0 ? 'text-red-700 font-semibold' : 'text-gray-700' }}">{{ $sheet['errors'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums">{{ $sheet['kind'] === \App\Support\Approval\PaperLedgerFile::AMOUNTS ? $sheet['linked'] : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>{{-- /scroll-hint-inner --}}
        <div class="scroll-hint-text">← スクロールできます →</div>
    </div>{{-- /scroll-hint --}}

    {{-- 次の番号（今年度の部門だけ。D7） --}}
    @if($analysis['nextNumbers'] !== [])
        <div class="px-4 py-3 border-b border-gray-200">
            <p class="text-[13px] font-semibold text-gray-900 mb-1">取り込むと、今年度の次に付く番号が進みます</p>
            <ul class="space-y-0.5">
                @foreach($analysis['nextNumbers'] as $next)
                    <li class="text-[12px] text-gray-700">{{ $next['department'] }}: <span class="font-mono">{{ $next['before'] }}</span> → <span class="font-mono font-semibold">{{ $next['after'] }}</span></li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 取り込まない行（その行だけ。ほかの行は取り込む。D20） --}}
    @if($analysis['rowErrors'] !== [])
        <div class="px-4 py-3 border-b border-gray-200 bg-red-50">
            <p class="text-[13px] font-semibold text-red-800 mb-1.5">取り込まない行が {{ count($analysis['rowErrors']) }} 件あります。ほかの行は取り込みます。取り込むときは、Excel を直してもう一度アップロードしてください。</p>
            <ul class="space-y-0.5 max-h-[240px] overflow-y-auto">
                @foreach($analysis['rowErrors'] as $rowError)
                    <li class="text-[12px] text-red-700">取り込まない {{ $rowError['where'] }}: {{ $rowError['message'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 注意（取り込みは止めない） --}}
    @if($analysis['warnings'] !== [])
        <div class="px-4 py-3 border-b border-gray-200 bg-amber-50">
            <p class="text-[13px] font-semibold text-amber-900 mb-1.5">確認してください（この {{ count($analysis['warnings']) }} 件は取り込みを止めません）。</p>
            <ul class="space-y-0.5 max-h-[240px] overflow-y-auto">
                @foreach($analysis['warnings'] as $warning)
                    <li class="text-[12px] text-amber-800">⚠ {{ $warning['where'] }}: {{ $warning['message'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 結び付けの選び方の誤り（選び直す） --}}
    @if($analysis['linkErrors'] !== [])
        <div class="px-4 py-3 border-b border-gray-200 bg-red-50" role="alert">
            <p class="text-[13px] font-semibold text-red-800 mb-1.5">金額の控えの結び付けを選び直してください。</p>
            <ul class="space-y-0.5">
                @foreach($analysis['linkErrors'] as $linkError)
                    <li class="text-[12px] text-red-700">{{ $linkError }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 足す記録 --}}
    <div class="px-4 pt-3 pb-1.5">
        <h3 class="text-[13px] font-bold text-gray-900">足す記録（{{ $analysis['addCount'] }} 件）</h3>
    </div>
    <div class="scroll-hint at-start">
        <div class="scroll-hint-inner">
            <table class="w-full min-w-[960px] border-collapse">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">決裁No</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">決裁日</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">部門</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">申請者</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 min-w-[16rem]">件名</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">契約金額</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">工事原価</th>
                        <th class="px-4 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 whitespace-nowrap">粗利益</th>
                        <th class="px-4 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-y border-gray-200 min-w-[10rem]">台帳の場所</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($analysis['records'] as $record)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] font-mono text-gray-900 whitespace-nowrap">{{ $record['number'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ str_replace('-', '/', $record['decided_on']) }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $record['department'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $record['applicant_name'] ?? '—' }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-900 break-words">{{ $record['subject'] }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums whitespace-nowrap">{{ $yen($record['amount']) }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums whitespace-nowrap">{{ $yen($record['cost']) }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[13px] text-gray-700 text-right tabular-nums whitespace-nowrap">{{ $yen($record['profit']) }}</td>
                            <td class="px-4 py-2 border-b border-gray-100 text-[12px] text-gray-500">{{ $record['where'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-6 text-center text-[13px] text-gray-500">足す記録はありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>{{-- /scroll-hint-inner --}}
        <div class="scroll-hint-text">← スクロールできます →</div>
    </div>{{-- /scroll-hint --}}

    {{-- すでにある記録（中身は変えない。金額が空なら補う。D6） --}}
    @if($analysis['existing'] !== [])
        <div class="px-4 py-3 border-t border-gray-200">
            <h3 class="text-[13px] font-bold text-gray-900 mb-1">すでにある決裁No（{{ count($analysis['existing']) }} 件。中身は変えません）</h3>
            <ul class="space-y-0.5 max-h-[240px] overflow-y-auto">
                @foreach($analysis['existing'] as $existing)
                    <li class="text-[12px] text-gray-700">
                        <span class="font-mono">{{ $existing['number'] }}</span>（{{ $existing['where'] }}）:
                        @if($existing['fill'] !== null)
                            <span class="text-blue-700 font-semibold">金額を補います（契約金額 {{ $yen($existing['fill']['amount']) }}・工事原価 {{ $yen($existing['fill']['cost']) }}・粗利益 {{ $yen($existing['fill']['profit']) }}）</span>
                        @else
                            変えません
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 確定（金額の控えの結び付けの選択も、このフォームで送る） --}}
    @if($canSubmit)
        @include('_partials._submit_once')
        <form method="POST" action="{{ route('approvals.admin.papers.import.execute') }}"
              x-data="submitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="onPageShow($event)">
            @csrf
            <input type="hidden" name="ledger_data" value="{{ $ledgerData }}">
            <input type="hidden" name="file_name" value="{{ $fileName }}">
            <input type="hidden" name="year" value="{{ $year }}">
            <input type="hidden" name="import_token" value="{{ $importToken }}">
            @include('approvals.admin.papers._import_amounts')
            <div class="px-4 py-4 border-t border-gray-200">
                <p class="text-[12px] text-gray-600 mb-2.5">{{ $analysis['addCount'] }} 件を足し、{{ $analysis['fillCount'] }} 件の金額を補います（金額の控えの選び直しも、このボタンで確かめます）。</p>
                <button type="submit" :disabled="submitting"
                        class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">取り込む</button>
                <span role="status" x-text="submitting ? '取り込んでいます…' : ''" style="display: inline-block; margin-left: 12px; font-size: 13px; color: #374151;"></span>
            </div>
        </form>
    @else
        @include('approvals.admin.papers._import_amounts')
        <div class="px-4 py-4 border-t border-gray-200">
            <p class="text-[13px] text-red-700">取り込む記録がありません（足す決裁No も、金額を補う記録もありません）。</p>
        </div>
    @endif
</section>
