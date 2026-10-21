@extends('layouts.app')

@section('title', $record === null ? '紙の決裁を登録' : "{$record->number} を直す")

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.admin.papers.index') }}" class="hover:text-emerald-600 transition-colors">過去の決裁の取り込み</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $record === null ? '1 件ずつ登録' : '直す' }}</span>
@endsection

{{--
    紙の決裁の 1 件ずつの登録・直し・削除（画面⑪。段階5 設計書 §5.10・D9）。決裁の管理者だけ・使い始める前から（D11）。
    必要変数: $record（登録は null）/ $years / $departments

    ⚠ 断られた入力は old() から戻す。手で組んだ送信の配列などは文字として扱わない（$value）
    ⚠ 決裁日は日付（時刻なし）なので ->format() のまま。登録・直した日時は保存した日時なので JapanTime::format（Bug #61）
    ⚠ 削除のフォームは保存のフォームの外（フォームを入れ子にしない）
    ⚠ 保存は二度押し止め（approvalSubmitOnce。2 回押すと、1 回目で登録できたのに 2 回目が「すでにあります」で戻る。⑨ と同じ）
--}}
@php
    $refused = old('_token') !== null;
    $value   = function (string $key, mixed $stored) use ($refused): string {
        if ($refused) {
            return is_string(old($key)) ? old($key) : '';
        }

        return $stored === null ? '' : (string) $stored;
    };
    $year       = $value('fiscal_year', $record?->fiscal_year ?? array_key_first($years));
    $department = $value('department_id', $record?->department_id);
    $input      = 'w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] focus:border-emerald-500 focus:outline-none';
    $label      = 'block text-[12px] font-semibold text-gray-700 mb-1';
@endphp

@section('content')
<div class="max-w-[720px]">
    @include('approvals._mail_failure')

    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <h1 class="text-lg font-bold text-gray-900 mb-1">{{ $record === null ? '紙の決裁を 1 件ずつ登録' : "{$record->number} を直す" }}</h1>
    <p class="text-[12px] text-gray-500 mb-5">
        決裁No は年度・部門・ＮＯ から組み立てます（R8-J-021 など）。ほかの紙の決裁やシステムの申請と同じ番号にはできません。
        今年度の番号を入れると、その部門の次に付く番号が、その番号の次まで進みます（戻りません）。
    </p>

    @if($record !== null)
        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-[12px] text-gray-600 bg-white border border-gray-200 rounded-lg px-4 py-3 mb-5">
            <dt class="text-gray-500">登録</dt>
            <dd>
                @if($record->import !== null)
                    取り込み（{{ $record->import->file_name }}・{{ \App\Support\JapanTime::format($record->import->created_at, 'Y/m/d H:i') }}）
                @else
                    1 件ずつ登録（{{ $record->creator?->name ?? '—' }}・{{ \App\Support\JapanTime::format($record->created_at, 'Y/m/d H:i') }}）
                @endif
            </dd>
            @if($record->updated_by !== null)
                <dt class="text-gray-500">直した人</dt>
                <dd>{{ $record->editor?->name ?? '—' }}（{{ \App\Support\JapanTime::format($record->updated_at, 'Y/m/d H:i') }}）</dd>
            @endif
        </dl>
    @endif

    <form method="POST" action="{{ $record === null ? route('approvals.admin.papers.store') : route('approvals.admin.papers.update', $record) }}"
          x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()"
          class="bg-white rounded-lg border border-gray-200 px-4 py-4 space-y-4">
        @csrf
        @if($record !== null)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <div>
                <label for="paper-fiscal-year" class="{{ $label }}">年度<span class="text-red-600 ml-0.5">*</span></label>
                <select id="paper-fiscal-year" name="fiscal_year" class="{{ $input }} bg-white cursor-pointer">
                    @foreach($years as $choice => $text)
                        <option value="{{ $choice }}" {{ $year === (string) $choice ? 'selected' : '' }}>{{ $text }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <label for="paper-department" class="{{ $label }}">部門<span class="text-red-600 ml-0.5">*</span></label>
                <select id="paper-department" name="department_id" class="{{ $input }} bg-white cursor-pointer">
                    <option value="">選んでください</option>
                    @foreach($departments as $item)
                        <option value="{{ $item->id }}" {{ $department === (string) $item->id ? 'selected' : '' }}>{{ $item->company->name }} / {{ $item->name }}（{{ $item->code }}）</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="paper-seq" class="{{ $label }}">ＮＯ<span class="text-red-600 ml-0.5">*</span></label>
                <input id="paper-seq" type="text" inputmode="numeric" name="seq" value="{{ $value('seq', $record?->seq) }}" required maxlength="10" placeholder="例: 21" class="{{ $input }}">
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
                <label for="paper-decided-on" class="{{ $label }}">決裁日<span class="text-red-600 ml-0.5">*</span></label>
                <input id="paper-decided-on" type="date" name="decided_on" value="{{ $value('decided_on', $record?->decided_on?->format('Y-m-d')) }}" required class="{{ $input }}">
            </div>
            <div class="sm:col-span-2">
                <label for="paper-applicant" class="{{ $label }}">申請者</label>
                <input id="paper-applicant" type="text" name="applicant_name" value="{{ $value('applicant_name', $record?->applicant_name) }}" maxlength="{{ \App\Support\Approval\PaperImportAnalysis::APPLICANT_MAX }}" placeholder="台帳の書き方のまま（例: 甲野）" class="{{ $input }}">
            </div>
        </div>

        <div>
            <label for="paper-subject" class="{{ $label }}">件名<span class="text-red-600 ml-0.5">*</span></label>
            <input id="paper-subject" type="text" name="subject" value="{{ $value('subject', $record?->subject) }}" required maxlength="{{ \App\Support\Approval\PaperImportAnalysis::SUBJECT_MAX }}" class="{{ $input }}">
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            @foreach(['amount' => '金額（契約金額）', 'cost' => '工事原価', 'profit' => '粗利益'] as $key => $text)
                <div>
                    <label for="paper-{{ $key }}" class="{{ $label }}">{{ $text }}</label>
                    <input id="paper-{{ $key }}" type="text" inputmode="numeric" name="{{ $key }}" value="{{ $value($key, $record?->{$key}) }}" maxlength="20" placeholder="円" class="{{ $input }} text-right tabular-nums">
                </div>
            @endforeach
        </div>
        <p class="text-[11px] text-gray-400 -mt-2">金額は円の数で入れます（カンマは付けても構いません。値引きなどはマイナスも入れられます）。粗利率は金額と粗利益から計算します。</p>

        <div>
            <label for="paper-note" class="{{ $label }}">備考</label>
            <textarea id="paper-note" name="note" rows="3" maxlength="{{ \App\Http\Controllers\Approval\PaperRecordController::NOTE_MAX }}" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] focus:border-emerald-500 focus:outline-none">{{ $value('note', $record?->note) }}</textarea>
        </div>

        <div class="flex flex-wrap items-center gap-3 pt-1">
            <button type="submit" :disabled="submitting" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">{{ $record === null ? '登録する' : '直す' }}</button>
            <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
            <a href="{{ route('approvals.admin.papers.index') }}" class="text-[13px] text-gray-500 hover:text-emerald-600 transition-colors">一覧へ戻る</a>
        </div>
    </form>

    @if($record !== null)
        <form method="POST" action="{{ route('approvals.admin.papers.destroy', $record) }}" class="mt-6 text-right"
              onsubmit="return confirm('{{ $record->number }} を削除します。次に付く番号は戻りません。よろしいですか。');">
            @csrf
            @method('DELETE')
            <button type="submit" class="px-4 py-1.5 bg-white border border-red-300 text-red-700 hover:bg-red-50 rounded-md text-[12px] font-semibold cursor-pointer">この紙の決裁を削除する</button>
        </form>
    @endif
</div>
@include('approvals._submit_once')
@endsection
