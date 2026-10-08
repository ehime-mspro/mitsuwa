{{-- 申請の本文の欄（申請の詳細・提出の履歴で共有。段階5 設計書 §5.6）。
     明細表の種類（$table が在る）: 明細表 → 追加の入力欄 → 定型文 → 補足（紙の住宅の様式の「（記）」の並び）。
     5W2H の種類: 重点ポイント → 追加の入力欄 → 定型文（定型文と追加の欄は、種類で使うときだけ）。
     $table: 明細表（AmountTable の形）か null ／ $extras: 追加の入力欄（キー => 値。RequestExtras::FIELDS の並び）／ $fixedText ／ $body --}}
<div class="space-y-4">
    @if($table !== null)
        <div>
            <p class="text-[12px] font-semibold text-gray-500 mb-1.5">金額の明細</p>
            @include('approvals.requests._amount_table_show', ['table' => $table])
        </div>
    @else
        <div>
            <p class="text-[12px] font-semibold text-gray-500 mb-1.5">重点ポイント（5W2H）</p>
            <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-[13px] text-gray-900 leading-relaxed whitespace-pre-wrap break-words">{{ $body }}</div>
        </div>
    @endif

    @if($extras !== [])
        <dl class="grid grid-cols-1 sm:grid-cols-[9em_1fr] gap-x-4 gap-y-2 text-[13px]">
            @foreach($extras as $key => $value)
                <dt class="text-gray-500">{{ \App\Support\Approval\RequestExtras::FIELDS[$key] }}</dt>
                <dd class="text-gray-900 break-words">{{ \App\Support\Approval\RequestExtras::display($key, $value) ?? '—' }}</dd>
            @endforeach
        </dl>
    @endif

    @if(($fixedText ?? '') !== '')
        <p class="rounded-md border border-dashed border-gray-300 bg-gray-50 px-4 py-2 text-[13px] text-gray-700 whitespace-pre-wrap break-words">{{ $fixedText }}</p>
    @endif

    @if($table !== null)
        <div>
            <p class="text-[12px] font-semibold text-gray-500 mb-1.5">補足</p>
            <div class="rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-[13px] text-gray-900 leading-relaxed whitespace-pre-wrap break-words">{{ ($body ?? '') !== '' ? $body : '—' }}</div>
        </div>
    @endif
</div>
