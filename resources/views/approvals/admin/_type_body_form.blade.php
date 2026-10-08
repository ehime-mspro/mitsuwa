{{-- 申請の種類の小窓（追加・編集で共有）: 本文の形と、金額の明細表の行（段階5 設計書 §5.4・D4）。
     $state は Alpine の状態の名前（'create' か 'edit'。approvalTypes() の中の同じ形の入れ物）。$checked は描いたときの本文の形（追加の小窓だけ。
     フォームの往復のテストが読む checked のため。Alpine は x-model で選び直す）。
     ⚠ 申請（下書きを含む）がある種類は本文の形を押せなくする（押せない欄は送られない＝サーバーは今の形のまま。送られてきても断る。D4）。
     ⚠ 行の欄は Alpine が描く（<template x-for>）。名前を空にした行は自由行（申請者が名前を入れる）。同じ側に同じ名前の行は置けない（サーバーが断る） --}}
<fieldset>
    <legend class="block text-[12px] font-semibold text-gray-700 mb-1">本文の形<span class="text-red-600 ml-0.5">*</span></legend>
    <div class="flex flex-wrap gap-x-5 gap-y-1">
        @foreach(\App\Enums\ApprovalBodyForm::cases() as $form)
            <label class="inline-flex items-center gap-1.5 text-[13px] text-gray-800 cursor-pointer">
                <input type="radio" name="body_form" value="{{ $form->value }}" x-model="{{ $state }}.bodyForm" :disabled="{{ $state }}.locked"{{ ($checked ?? null) === $form->value ? ' checked' : '' }}>
                {{ $form->label() }}
            </label>
        @endforeach
    </div>
    <p x-show="{{ $state }}.locked" class="text-[11px] text-gray-500 mt-1">この種類の申請が <span x-text="{{ $state }}.requestCount"></span> 件あるため、本文の形は変えられません（変えたいときは新しい種類を作り、この種類を「停止」にします）。</p>
</fieldset>

<div x-show="{{ $state }}.bodyForm === 'table'" class="space-y-3">
    @foreach(['upper' => ['前半の行（建物側）', '「計」の上に並ぶ行'], 'lower' => ['後半の行（土地側）', '「計」と「合計金額」の間に並ぶ行']] as $section => [$title, $hint])
        <fieldset>
            <legend class="block text-[12px] font-semibold text-gray-700 mb-0.5">明細表の{{ $title }}</legend>
            <p class="text-[11px] text-gray-400 mb-1.5">{{ $hint }}。名前を空にした行は自由行（申請者が名前を入れる）。{{ \App\Support\Approval\AmountTable::LAYOUT_MAX_ROWS }} 行まで</p>
            <template x-for="(row, index) in {{ $state }}.{{ $section }}" :key="row.key">
                <div class="flex items-center gap-1.5 mb-1.5">
                    <input type="text" name="layout_{{ $section }}[]" x-model="row.name" maxlength="{{ \App\Support\Approval\AmountTable::NAME_MAX }}" placeholder="（自由行）"
                           :aria-label="'{{ $title }}の ' + (index + 1) + ' 行目の名前'"
                           class="flex-1 min-w-0 h-[34px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    <button type="button" @click="moveRow({{ $state }}.{{ $section }}, index, -1)" :disabled="index === 0" :aria-label="(index + 1) + ' 行目を上へ'"
                            class="h-[34px] px-2 border border-gray-300 rounded-md text-[12px] text-gray-600 bg-white cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed">↑</button>
                    <button type="button" @click="moveRow({{ $state }}.{{ $section }}, index, 1)" :disabled="index === {{ $state }}.{{ $section }}.length - 1" :aria-label="(index + 1) + ' 行目を下へ'"
                            class="h-[34px] px-2 border border-gray-300 rounded-md text-[12px] text-gray-600 bg-white cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed">↓</button>
                    <button type="button" @click="{{ $state }}.{{ $section }}.splice(index, 1)" :aria-label="(index + 1) + ' 行目を消す'"
                            class="h-[34px] px-2 border border-gray-300 rounded-md text-[12px] text-red-600 bg-white cursor-pointer">消す</button>
                </div>
            </template>
            <button type="button" @click="addRow({{ $state }}.{{ $section }})" :disabled="{{ $state }}.{{ $section }}.length >= {{ \App\Support\Approval\AmountTable::LAYOUT_MAX_ROWS }}"
                    class="px-3 py-1 text-[12px] font-semibold text-emerald-700 bg-white border border-emerald-600 rounded-md cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed">＋ 行を足す</button>
        </fieldset>
    @endforeach
    <label class="flex items-center gap-2 text-[13px] text-gray-800 cursor-pointer">
        <input type="checkbox" name="layout_subtotal" value="1" x-model="{{ $state }}.subtotal">
        「計」の行を使う（前半の行の合計。「合計金額」は前半と後半の合計でいつも出る）
    </label>
</div>
