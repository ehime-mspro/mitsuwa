{{-- 申請の種類の小窓（追加・編集で共有）: 件名の決まり文句・追加の入力欄・定型文・使える部門（どちらの本文の形でも使える。段階5 設計書 §5.4・D12・D13）。
     $state は Alpine の状態の名前（'create' か 'edit'）。⚠ <option> は使わない（使える部門はチェックボックス。Bug #16 の形にしない） --}}
<div>
    <label for="{{ $state }}-subject-suffix" class="block text-[12px] font-semibold text-gray-700 mb-1">件名の決まり文句</label>
    <input type="text" id="{{ $state }}-subject-suffix" name="subject_suffix" x-model="{{ $state }}.suffix" maxlength="50" placeholder="例: 様請負新築工事契約の件"
           class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
    <p class="text-[11px] text-gray-400 mt-1">件名の後ろに付く文字。申請者は前半（例: 施主名）だけを入れる（「件名を直接書く」にも切り替えられる）。空なら件名は 1 行で書く</p>
</div>

<fieldset>
    <legend class="block text-[12px] font-semibold text-gray-700 mb-1">追加の入力欄</legend>
    <div class="flex flex-wrap gap-x-5 gap-y-1">
        @foreach(\App\Support\Approval\RequestExtras::FIELDS as $key => $label)
            <label class="inline-flex items-center gap-1.5 text-[13px] text-gray-800 cursor-pointer">
                <input type="checkbox" name="uses_{{ $key }}" value="1" x-model="{{ $state }}.uses.{{ $key }}">
                {{ $label }}
            </label>
        @endforeach
    </div>
    <p class="text-[11px] text-gray-400 mt-1">担当者と契約予定日は、使う種類では提出に必須（坪数・坪単価は空でも出せる）</p>
</fieldset>

<div>
    <label for="{{ $state }}-fixed-text" class="block text-[12px] font-semibold text-gray-700 mb-1">定型文</label>
    <textarea id="{{ $state }}-fixed-text" name="fixed_text" x-model="{{ $state }}.fixedText" maxlength="500" rows="2" placeholder="例: 上記の内容に基づき、販売をおこないます。"
              class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed"></textarea>
    <p class="text-[11px] text-gray-400 mt-1">本文の下に出す固定の文（申請者は直さない）。空なら出さない</p>
</div>

<fieldset>
    <legend class="block text-[12px] font-semibold text-gray-700 mb-1">使える部門</legend>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1">
        @foreach($departments as $department)
            <label class="inline-flex items-center gap-1.5 text-[13px] text-gray-800 cursor-pointer">
                <input type="checkbox" name="department_ids[]" value="{{ $department->id }}" x-model="{{ $state }}.departmentIds">
                {{ $department->company->name }}・{{ $department->name }}
            </label>
        @endforeach
    </div>
    <p class="text-[11px] text-gray-400 mt-1">選んだ部門の人の申請の画面にだけ出す。1 つも選ばなければ全部門で使える</p>
</fieldset>
