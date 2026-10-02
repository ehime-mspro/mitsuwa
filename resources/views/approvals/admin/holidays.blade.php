@extends('layouts.app')

@section('title', '催促の設定')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">催促の設定</span>
@endsection

@section('content')
@php
    // 断られた入力を、送った小窓に戻して開き直す（申請種類の管理と同じ形）。どの送らない日かの edit_id を送るのは編集の小窓だけ。
    // ⚠ 手で組んだ送信の配列などは文字として扱わない
    $oldText = fn (string $key): string => is_string(old($key)) ? old($key) : '';
    $refused = $errors->any();
    $editId  = is_string(old('edit_id')) ? (int) old('edit_id') : 0;
    $refusedEdit = $refused && $holidays->contains('id', $editId) ? [
        'id'             => $editId,
        'start_date'     => $oldText('start_date'),
        'end_date'       => $oldText('end_date'),
        'repeats_yearly' => (bool) old('repeats_yearly'),
        'description'    => $oldText('description'),
    ] : null;
    $refusedCreate = $refused && old('edit_id') === null && old('_method') === null;
@endphp
<div x-data="approvalHolidays()" x-cloak>
    @include('approvals._mail_failure')

    {{-- 成功・失敗の帯はレイアウトが出す（ここで出すと画面に 2 回出る）。$errors だけ各ビューの責任 --}}
    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <h1 class="text-lg font-bold text-gray-900 mb-2">催促の設定</h1>
    <p class="text-[12px] text-gray-500 mb-5 max-w-[720px]">
        対応を待っている申請が「3 日待ち」以上になった人に、平日の朝 9 時にまとめメールを 1 通送ります。
        土曜・日曜と祝日（振替休日・国民の休日を含む）は自動で送りません。会社の休み（年末年始・夏季休暇など）は、下の「送らない日」に登録してください。
    </p>

    {{-- 次に送る日と前回の催促（要件 13 章の⑫） --}}
    <section class="bg-white rounded-lg border border-gray-200 mb-5 px-4 py-3">
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <dt class="text-[11px] font-semibold text-gray-500">次に催促を送る日</dt>
                <dd class="mt-0.5 text-[14px] font-semibold text-gray-900">
                    @if($nextDay !== null)
                        {{ \App\Support\Approval\ReminderCalendar::label($nextDay) }} 9 時
                    @else
                        1 年以内にありません（送らない日の登録を確かめてください）
                    @endif
                </dd>
                @unless($launched)
                    <dd class="mt-0.5 text-[11px] text-gray-500">決裁を使い始める前なので、まだ送りません。</dd>
                @endunless
            </div>
            <div>
                <dt class="text-[11px] font-semibold text-gray-500">前回の催促</dt>
                <dd class="mt-0.5 text-[14px] text-gray-900">
                    @if($lastRun === null)
                        まだありません
                    @elseif($lastRun->recipient_count === 0)
                        {{-- 申請が待っていても、メールを受け取れる人がいない朝がある（利用者の決定 2026-10-02） --}}
                        {{ \App\Support\Approval\ReminderCalendar::label($lastRun->sent_on) }}送る相手はいませんでした
                    @else
                        {{ \App\Support\Approval\ReminderCalendar::label($lastRun->sent_on) }}（{{ $lastRun->recipient_count }} 人・{{ $lastRun->item_count }} 件）
                    @endif
                </dd>
            </div>
        </dl>
    </section>

    <section class="bg-white rounded-lg border border-gray-200">
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
            <h2 class="text-[14px] font-bold text-gray-900">送らない日</h2>
            <button type="button" @click="createModal = true" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-semibold rounded-md cursor-pointer">送らない日を追加</button>
        </div>
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
        <table class="w-full min-w-[560px] border-collapse">
            <thead>
                <tr>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">期間</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">繰り返し</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">説明</th>
                    <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($holidays as $holiday)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-900 whitespace-nowrap tabular-nums">{{ $holiday->periodLabel() }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $holiday->repeats_yearly ? '毎年' : 'その年だけ' }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $holiday->description }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-right whitespace-nowrap">
                            <button type="button" @click="openEdit({{ \Illuminate\Support\Js::from(['id' => $holiday->id] + $holiday->formValues()) }})" class="text-[12px] text-blue-600 hover:underline cursor-pointer bg-transparent border-none p-0">編集</button>
                            <span class="text-gray-200 mx-1">|</span>
                            <form method="POST" action="{{ route('approvals.admin.holidays.destroy', $holiday) }}" class="inline" onsubmit="return confirm('この送らない日を削除しますか。');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-[12px] text-red-600 hover:underline cursor-pointer bg-transparent border-none p-0">削除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-[13px] text-gray-400">送らない日は登録されていません（土日と祝日だけ送りません）。</td></tr>
                @endforelse
            </tbody>
        </table>
            </div>{{-- /scroll-hint-inner --}}
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>{{-- /scroll-hint --}}
    </section>

    {{-- 送らない日の追加（追加と編集でフォームを分ける。申請種類の管理と同じ形） --}}
    <div x-show="createModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="createModal = false" class="bg-white rounded-xl w-full max-w-[480px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" action="{{ route('approvals.admin.holidays.store') }}"
                  x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                @csrf
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">送らない日の追加</div>
                @if($refusedCreate)
                    {{-- 断られた理由を小窓の中にも出す（上の帯は開き直した小窓に隠れる） --}}
                    <div class="mx-6 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-700">
                        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                    </div>
                @endif
                <div class="px-6 py-4 space-y-3.5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[12px] font-semibold text-gray-700 mb-1">開始日<span class="text-red-600 ml-0.5">*</span></label>
                            <input type="date" name="start_date" value="{{ $refusedCreate ? $oldText('start_date') : '' }}" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                        </div>
                        <div>
                            <label class="block text-[12px] font-semibold text-gray-700 mb-1">終了日<span class="text-red-600 ml-0.5">*</span></label>
                            <input type="date" name="end_date" value="{{ $refusedCreate ? $oldText('end_date') : '' }}" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                        </div>
                    </div>
                    <p class="text-[11px] text-gray-400 -mt-2">1 日だけなら、開始日と終了日を同じ日にします。</p>
                    <label class="flex items-start gap-2 text-[13px] text-gray-800 cursor-pointer">
                        <input type="checkbox" name="repeats_yearly" value="1" class="mt-1"{{ $refusedCreate && old('repeats_yearly') ? ' checked' : '' }}>
                        <span>毎年繰り返す（月と日だけで比べます。12/29〜1/3 のように年をまたぐ期間も 1 件で登録できます）</span>
                    </label>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">説明<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="description" value="{{ $refusedCreate ? $oldText('description') : '' }}" required maxlength="50" placeholder="例: 年末年始" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                </div>
                <div class="px-6 pb-5 flex flex-wrap items-center justify-end gap-2">
                    <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                    <button type="button" @click="createModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" :disabled="submitting" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">保存する</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 送らない日の編集 --}}
    <div x-show="editModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="editModal = false" class="bg-white rounded-xl w-full max-w-[480px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" :action="'{{ url('approvals/admin/holidays') }}/' + editId"
                  x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                @csrf
                @method('PUT')
                {{-- どの送らない日の小窓か（断られたときに同じ日の小窓を開き直す） --}}
                <input type="hidden" name="edit_id" :value="editId">
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">送らない日の編集</div>
                @if($refusedEdit !== null)
                    {{-- この小窓は送らない日ごとに使い回すので、断られた日を編集しているあいだだけ出す --}}
                    <div x-show="editId === {{ $refusedEdit['id'] }}" class="mx-6 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-700">
                        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                    </div>
                @endif
                <div class="px-6 py-4 space-y-3.5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[12px] font-semibold text-gray-700 mb-1">開始日<span class="text-red-600 ml-0.5">*</span></label>
                            <input type="date" name="start_date" x-model="editStart" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                        </div>
                        <div>
                            <label class="block text-[12px] font-semibold text-gray-700 mb-1">終了日<span class="text-red-600 ml-0.5">*</span></label>
                            <input type="date" name="end_date" x-model="editEnd" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                        </div>
                    </div>
                    <label class="flex items-start gap-2 text-[13px] text-gray-800 cursor-pointer">
                        <input type="checkbox" name="repeats_yearly" value="1" x-model="editYearly" class="mt-1">
                        <span>毎年繰り返す（月と日だけで比べます）</span>
                    </label>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">説明<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="description" x-model="editDescription" required maxlength="50" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                </div>
                <div class="px-6 pb-5 flex flex-wrap items-center justify-end gap-2">
                    <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                    <button type="button" @click="editModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" :disabled="submitting" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">保存する</button>
                </div>
            </form>
        </div>
    </div>

</div>

{{-- 保存の二度押し止めの部品（approvalSubmitOnce。定義は 1 か所） --}}
@include('approvals._submit_once')
@endsection

@push('scripts')
<script>
function approvalHolidays() {
    return {
        // 断られた入力で開き直す。追加の小窓はサーバーが打った中身を描き、編集の小窓は init で打った中身を入れる
        createModal: {{ $refusedCreate ? 'true' : 'false' }},
        editModal: false,
        editId: null,
        editStart: '',
        editEnd: '',
        editYearly: false,
        editDescription: '',

        init() {
            var refused = {{ \Illuminate\Support\Js::from($refusedEdit) }};
            if (refused) {
                this.openEdit(refused);
            }
        },

        openEdit(row) {
            this.editId = row.id;
            this.editStart = row.start_date;
            this.editEnd = row.end_date;
            this.editYearly = row.repeats_yearly;
            this.editDescription = row.description;
            this.editModal = true;
        }
    };
}
</script>
@endpush
