{{--
    社員の一括登録の確認（設計書 §5.10）。

    必要変数: $rows / $rowErrors / $warnings / $validCount / $createCount / $updateCount /
             $totalRows / $csvData / $guideToken

    ⚠ エラー（$rowErrors）と注意（$warnings）は**別のブロック**に、**別の言い方**で出す。
      同じ文面で出すと `assertSee` がどちらにも一致し、警告をエラーに変える誤りを
      テストが見逃す（Bug #54 ④）。
    ⚠ 確定のフォームは「取り込む行があり、かつエラーが 1 行も無い」ときだけ描く。
      画面が決して送らない POST をテストで作らないため（Bug #54 ③）、また
      コントローラ側も同じ条件で断るため（画面とサーバーの両方で同じ判断をする）。
--}}
<section class="bg-white rounded-lg border border-gray-200">
    <div class="px-4 py-3 border-b border-gray-200">
        <h2 class="text-[14px] font-bold text-gray-900 mb-2">③ 取り込む内容の確認</h2>
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-gray-100 text-gray-700 text-[12px]">全 {{ $totalRows }} 行</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-700 text-[12px] font-semibold">新規 {{ $createCount }} 人</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-blue-50 text-blue-700 text-[12px] font-semibold">更新 {{ $updateCount }} 人</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-red-50 text-red-700 text-[12px] font-semibold">エラー {{ count($rowErrors) }} 行</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-amber-50 text-amber-800 text-[12px] font-semibold">注意 {{ count($warnings) }} 件</span>
        </div>
    </div>

    {{-- エラー（この行は取り込めない） --}}
    @if(count($rowErrors) > 0)
        <div class="px-4 py-3 border-b border-gray-200 bg-red-50">
            <p class="text-[13px] font-semibold text-red-800 mb-1.5">取り込めない行が {{ count($rowErrors) }} 行あります。CSV を直して、もう一度アップロードしてください。</p>
            <ul class="space-y-0.5 max-h-[240px] overflow-y-auto">
                @foreach($rowErrors as $rowError)
                    <li class="text-[12px] text-red-700">エラー 行{{ $rowError['row'] }}: {{ $rowError['message'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 注意（取り込むが、知っておいてほしいこと） --}}
    @if(count($warnings) > 0)
        <div class="px-4 py-3 border-b border-gray-200 bg-amber-50">
            <p class="text-[13px] font-semibold text-amber-900 mb-1.5">確認してください（この {{ count($warnings) }} 件は取り込みを止めません）。</p>
            <ul class="space-y-0.5 max-h-[240px] overflow-y-auto">
                @foreach($warnings as $warning)
                    <li class="text-[12px] text-amber-800">⚠ 行{{ $warning['row'] }}: {{ $warning['message'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 取り込む行 --}}
    <div class="scroll-hint at-start">
        <div class="scroll-hint-inner">
            <table class="w-full min-w-[720px] border-collapse">
                <thead>
                    <tr>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">行</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">区分</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">社員番号</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">氏名</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">メールアドレス</th>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">決裁の所属部門</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-500">{{ $row['line'] }}</td>
                            <td class="px-4 py-2.5 border-b border-gray-100 whitespace-nowrap">
                                @if($row['existing_id'] === null)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-700 text-[11px] font-semibold">新規</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-blue-50 text-blue-700 text-[11px] font-semibold">更新</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-900">{{ $row['employee_number'] }}</td>
                            <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-900">{{ $row['name'] }}</td>
                            <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $row['email'] ?? '—' }}</td>
                            <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ implode('、', $row['department_codes']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center text-[13px] text-gray-500">取り込める行がありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>{{-- /scroll-hint-inner --}}
        <div class="scroll-hint-text">← スクロールできます →</div>
    </div>{{-- /scroll-hint --}}

    {{-- 確定 --}}
    <div class="px-4 py-4 border-t border-gray-200">
        @if($validCount > 0 && $rowErrors === [])
            @if($createCount > 0)
                <p class="text-[12px] text-gray-600 mb-2.5">この {{ $validCount }} 行を取り込みます。新しく登録する {{ $createCount }} 人の初期パスワードを作り、印刷用の案内を開きます。</p>
            @else
                {{-- 新規が 0 人なら案内に印刷する人がいないので、案内そのものを開かない
                     （`UserImportController::execute()` はこの画面へ戻して成功を知らせる）--}}
                <p class="text-[12px] text-gray-600 mb-2.5">この {{ $validCount }} 行を取り込みます。今回は新しく登録する人がいないので、ログイン案内は開きません。</p>
            @endif
            <form method="POST" action="{{ route('approvals.admin.users.import.execute') }}"
                  onsubmit="return confirm('{{ $validCount }} 件を取り込みます。{{ $createCount > 0 ? '印刷用の案内が開きます。' : 'ログイン案内は開きません。' }}よろしいですか。');">
                @csrf
                <input type="hidden" name="csv_data" value="{{ $csvData }}">
                <input type="hidden" name="guide_token" value="{{ $guideToken }}">
                <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">取り込む</button>
            </form>
        @else
            <p class="text-[13px] text-red-700">いまは取り込めません。上のエラーを直してから、もう一度アップロードしてください。</p>
        @endif
    </div>
</section>
