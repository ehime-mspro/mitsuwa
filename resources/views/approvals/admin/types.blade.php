@extends('layouts.app')

@section('title', '申請種類の管理')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">申請種類の管理</span>
@endsection

@section('content')
<div x-data="approvalTypes()" x-cloak>

    {{-- 成功・失敗の帯はレイアウトが出す（ここで出すと画面に 2 回出る）。$errors だけ各ビューの責任 --}}
    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <h1 class="text-lg font-bold text-gray-900 mb-2">申請種類の管理</h1>
    <p class="text-[12px] text-gray-500 mb-5 max-w-[720px]">
        申請の画面で選ぶ種類です。種類ごとに、本文に最初から入る見出しと、回る審査部門を決めます。
        使わなくなった種類は「停止」にします（新しい申請で選べなくなるだけで、過去の申請はそのまま）。
    </p>

    <section class="bg-white rounded-lg border border-gray-200">
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
            <h2 class="text-[14px] font-bold text-gray-900">申請の種類</h2>
            {{-- ⚠ 押せない理由はボタン自身の title では出ない（ホバーを受ける span で包む。Bug #43） --}}
            <span @if($departments->isEmpty()) title="先に部門の管理で部門を登録してください。" @endif style="display: inline-flex;">
                <button type="button" @click="createModal = true" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-semibold rounded-md cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed" @if($departments->isEmpty()) disabled @endif>種類を追加</button>
            </span>
        </div>
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
        <table class="w-full min-w-[760px] border-collapse">
            <thead>
                <tr>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">種類名</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">審査部門</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">状態</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">申請</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">表示順</th>
                    <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($types as $type)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-900">{{ $type->name }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $type->reviewDepartment->company->name }}・{{ $type->reviewDepartment->name }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 whitespace-nowrap">
                            <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $type->is_active ? 'background: #d1fae5; color: #065f46;' : 'background: #f3f4f6; color: #4b5563;' }}">{{ $type->is_active ? '利用中' : '停止' }}</span>
                        </td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $type->requests_count }} 件</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $type->sort_order }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-right whitespace-nowrap">
                            <button type="button" @click="openEdit({{ \Illuminate\Support\Js::from($type->only(['id', 'name', 'headings', 'review_department_id', 'sort_order', 'is_active'])) }})" class="text-[12px] text-blue-600 hover:underline cursor-pointer bg-transparent border-none p-0">編集</button>
                            <span class="text-gray-200 mx-1">|</span>
                            <form method="POST" action="{{ route('approvals.admin.types.destroy', $type) }}" class="inline" onsubmit="return confirm('この種類を削除しますか。');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-[12px] text-red-600 hover:underline cursor-pointer bg-transparent border-none p-0">削除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-[13px] text-gray-400">申請の種類が登録されていません。</td></tr>
                @endforelse
            </tbody>
        </table>
            </div>{{-- /scroll-hint-inner --}}
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>{{-- /scroll-hint --}}
    </section>

    {{-- 種類の追加（追加と編集でフォームを分ける。部門の管理と同じ形）--}}
    <div x-show="createModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="createModal = false" class="bg-white rounded-xl w-full max-w-[560px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" action="{{ route('approvals.admin.types.store') }}">
                @csrf
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">種類の追加</div>
                <div class="px-6 py-4 space-y-3.5">
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">種類名<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="name" required maxlength="50" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">審査部門<span class="text-red-600 ml-0.5">*</span></label>
                        {{-- ⚠ <option> は @@foreach で静的に出す（Bug #16） --}}
                        <select name="review_department_id" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                            @foreach($departments as $department)
                                <option value="{{ $department->id }}">{{ $department->company->name }}・{{ $department->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">5W2H の見出し<span class="text-red-600 ml-0.5">*</span></label>
                        <textarea name="headings" required maxlength="2000" rows="12" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] font-mono leading-relaxed">{{ \App\Support\Approval\BodyTemplate::DEFAULT }}</textarea>
                        <p class="text-[11px] text-gray-400 mt-1">申請の本文に最初から入る見出し。見出しは「■」で始まる行に、その下は「・」だけの行にする。「いつ」「いくら」は実施時期・金額の欄で書くので入れない（要件 5.2）</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">表示順<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="number" name="sort_order" value="0" required inputmode="numeric" min="0" max="9999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <label class="flex items-center gap-2 text-[13px] text-gray-800 cursor-pointer">
                        <input type="checkbox" name="is_active" value="1" checked>
                        利用中（申請の画面で選べる）
                    </label>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="createModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">保存する</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 種類の編集 --}}
    <div x-show="editModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="editModal = false" class="bg-white rounded-xl w-full max-w-[560px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" :action="'{{ url('approvals/admin/types') }}/' + editId">
                @csrf
                @method('PUT')
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">種類の編集</div>
                <div class="px-6 py-4 space-y-3.5">
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">種類名<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="name" x-model="editName" required maxlength="50" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">審査部門<span class="text-red-600 ml-0.5">*</span></label>
                        <select name="review_department_id" x-model="editDepartmentId" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                            @foreach($departments as $department)
                                <option value="{{ $department->id }}">{{ $department->company->name }}・{{ $department->name }}</option>
                            @endforeach
                        </select>
                        <p class="text-[11px] text-gray-400 mt-1">変えても、回覧中の申請は提出したときの審査部門のまま回ります（出し直すと新しい設定）</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">5W2H の見出し<span class="text-red-600 ml-0.5">*</span></label>
                        <textarea name="headings" x-model="editHeadings" required maxlength="2000" rows="12" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] font-mono leading-relaxed"></textarea>
                        <p class="text-[11px] text-gray-400 mt-1">見出しは「■」で始まる行に、その下は「・」だけの行にする</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">表示順<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="number" name="sort_order" x-model="editSort" required inputmode="numeric" min="0" max="9999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <label class="flex items-center gap-2 text-[13px] text-gray-800 cursor-pointer">
                        <input type="checkbox" name="is_active" value="1" x-model="editActive">
                        利用中（外すと停止。新しい申請で選べなくなる）
                    </label>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="editModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">保存する</button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
function approvalTypes() {
    return {
        createModal: false,
        editModal: false,
        editId: null,
        editName: '',
        editDepartmentId: '',
        editHeadings: '',
        editSort: '0',
        editActive: true,

        openEdit(row) {
            this.editId = row.id;
            this.editName = row.name;
            this.editDepartmentId = String(row.review_department_id);
            this.editHeadings = row.headings;
            this.editSort = String(row.sort_order);
            this.editActive = row.is_active;
            this.editModal = true;
        }
    };
}
</script>
@endpush
