@extends('layouts.app')

@section('title', '部門の管理')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">部門の管理</span>
@endsection

@section('content')
<div x-data="approvalOrganization()" x-cloak>

    {{-- 成功・失敗の帯はレイアウトが出す（ここで出すと画面に 2 回出る）。$errors だけ各ビューの責任 --}}
    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <h1 class="text-lg font-bold text-gray-900 mb-5">部門の管理</h1>

    {{-- 会社 --}}
    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
            <h2 class="text-[14px] font-bold text-gray-900">会社</h2>
            <button type="button" @click="companyCreateModal = true" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-semibold rounded-md cursor-pointer">会社を追加</button>
        </div>
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
        <table class="w-full min-w-[520px] border-collapse">
            <thead>
                <tr>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">会社名</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">期の始まり</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">表示順</th>
                    <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($companies as $company)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-900">{{ $company->name }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $company->fiscal_start_month }}月</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $company->sort_order }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-right whitespace-nowrap">
                            <button type="button" @click="openCompanyEdit({{ \Illuminate\Support\Js::from($company->only(['id', 'name', 'fiscal_start_month', 'sort_order'])) }})" class="text-[12px] text-blue-600 hover:underline cursor-pointer bg-transparent border-none p-0">編集</button>
                            <span class="text-gray-200 mx-1">|</span>
                            <form method="POST" action="{{ route('approvals.admin.organization.companies.destroy', $company) }}" class="inline" onsubmit="return confirm('この会社を削除しますか。');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-[12px] text-red-600 hover:underline cursor-pointer bg-transparent border-none p-0">削除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-[13px] text-gray-400">会社が登録されていません。</td></tr>
                @endforelse
            </tbody>
        </table>
            </div>{{-- /scroll-hint-inner --}}
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>{{-- /scroll-hint --}}
    </section>

    {{-- 部門 --}}
    <section class="bg-white rounded-lg border border-gray-200 mb-5">
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
            <h2 class="text-[14px] font-bold text-gray-900">部門</h2>
            {{-- ⚠ 押せない理由はボタン自身の title では出ない（ホバーを受ける span で包む。Bug #43） --}}
            <span @if($companies->isEmpty()) title="先に会社を登録してください。" @endif style="display: inline-flex;">
                <button type="button" @click="departmentCreateModal = true" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-semibold rounded-md cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed" @if($companies->isEmpty()) disabled @endif>部門を追加</button>
            </span>
        </div>
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
        <table class="w-full min-w-[760px] border-collapse">
            <thead>
                <tr>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">会社</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">部門名</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">略称</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">アルファベット</th>
                    <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">所属</th>
                    <th class="px-4 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($companies->flatMap->departments as $dept)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $dept->company->name }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-900">{{ $dept->name }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $dept->short_name }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-mono text-gray-700 whitespace-nowrap">{{ $dept->code }}</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ $dept->users_count }} 人</td>
                        <td class="px-4 py-2.5 border-b border-gray-100 text-right whitespace-nowrap">
                            <button type="button" @click="openDepartmentEdit({{ \Illuminate\Support\Js::from($dept->only(['id', 'company_id', 'name', 'short_name', 'code', 'sort_order'])) }})" class="text-[12px] text-blue-600 hover:underline cursor-pointer bg-transparent border-none p-0">編集</button>
                            <span class="text-gray-200 mx-1">|</span>
                            <form method="POST" action="{{ route('approvals.admin.organization.departments.destroy', $dept) }}" class="inline" onsubmit="return confirm('この部門を削除しますか。');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-[12px] text-red-600 hover:underline cursor-pointer bg-transparent border-none p-0">削除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-[13px] text-gray-400">部門が登録されていません。</td></tr>
                @endforelse
            </tbody>
        </table>
            </div>{{-- /scroll-hint-inner --}}
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>{{-- /scroll-hint --}}
    </section>

    {{-- 許可するドメイン --}}
    <section class="bg-white rounded-lg border border-gray-200">
        <div class="px-4 py-3 border-b border-gray-200">
            <h2 class="text-[14px] font-bold text-gray-900">通知メールを送ってよいドメイン</h2>
            <p class="text-[12px] text-gray-500 mt-1">ここに登録したドメインのメールアドレスにだけ、パスワード再発行の通知を送ります。CSV 取込のメールアドレスの検査にも使います。</p>
        </div>
        <form method="POST" action="{{ route('approvals.admin.organization.mailDomains.store') }}" class="flex flex-wrap items-center gap-2 px-4 py-3 border-b border-gray-100">
            @csrf
            <input type="text" name="domain" value="{{ old('domain') }}" placeholder="例: mitsuwat.co.jp" autocapitalize="none" spellcheck="false"
                   class="h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 w-full sm:w-[280px]">
            <button type="submit" class="h-8 px-3.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-semibold rounded-md cursor-pointer">追加</button>
        </form>
        <ul>
            @forelse($mailDomains as $domain)
                <li class="flex flex-wrap items-center gap-2 px-4 py-2.5 border-b border-gray-100 text-[13px]">
                    <span class="font-mono text-gray-900">{{ $domain->domain }}</span>
                    <span class="text-[12px] text-gray-500">このドメインのメールアドレスを持つ利用者: {{ $domain->affected_user_count }} 人（この人たちには通知メールが届かなくなります）</span>
                    <form method="POST" action="{{ route('approvals.admin.organization.mailDomains.destroy', $domain) }}" class="ml-auto" onsubmit="return confirm('このドメインを削除しますか。');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-[12px] text-red-600 hover:underline cursor-pointer bg-transparent border-none p-0">削除</button>
                    </form>
                </li>
            @empty
                <li class="px-4 py-8 text-center text-[13px] text-gray-400">ドメインが登録されていません。登録するまで通知メールは送られません。</li>
            @endforelse
        </ul>
    </section>

    {{-- 会社の追加（追加と編集でフォームを分ける。基幹の利用者管理と同じ形）--}}
    <div x-show="companyCreateModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="companyCreateModal = false" class="bg-white rounded-xl w-full max-w-[420px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" action="{{ route('approvals.admin.organization.companies.store') }}">
                @csrf
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">会社の追加</div>
                <div class="px-6 py-4 space-y-3.5">
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">会社名<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="name" required maxlength="50" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">期の始まりの月<span class="text-red-600 ml-0.5">*</span></label>
                        <select name="fiscal_start_month" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                            @foreach(range(1, 12) as $month)
                                <option value="{{ $month }}">{{ $month }}月</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">表示順<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="number" name="sort_order" value="0" required inputmode="numeric" min="0" max="9999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="companyCreateModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">保存する</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 会社の編集 --}}
    <div x-show="companyEditModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="companyEditModal = false" class="bg-white rounded-xl w-full max-w-[420px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" :action="'{{ url('approvals/admin/organization/companies') }}/' + editCompanyId">
                @csrf
                @method('PUT')
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">会社の編集</div>
                <div class="px-6 py-4 space-y-3.5">
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">会社名<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="name" x-model="editCompanyName" required maxlength="50" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">期の始まりの月<span class="text-red-600 ml-0.5">*</span></label>
                        {{-- ⚠ <option> は @@foreach で静的に出す（x-for は x-model の同期より後に描画されて値がズレる。Bug #16） --}}
                        <select name="fiscal_start_month" x-model="editCompanyMonth" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                            @foreach(range(1, 12) as $month)
                                <option value="{{ $month }}">{{ $month }}月</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">表示順<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="number" name="sort_order" x-model="editCompanySort" required inputmode="numeric" min="0" max="9999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="companyEditModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">保存する</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 部門の追加 --}}
    <div x-show="departmentCreateModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="departmentCreateModal = false" class="bg-white rounded-xl w-full max-w-[420px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" action="{{ route('approvals.admin.organization.departments.store') }}">
                @csrf
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">部門の追加</div>
                <div class="px-6 py-4 space-y-3.5">
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">会社<span class="text-red-600 ml-0.5">*</span></label>
                        <select name="company_id" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                            @foreach($companies as $company)
                                <option value="{{ $company->id }}">{{ $company->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">部門名<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="name" required maxlength="50" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">略称<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="short_name" required maxlength="6" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                        <p class="text-[11px] text-gray-400 mt-1">データ印の上段に入るので6文字まで</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">アルファベット<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="code" required maxlength="3" autocapitalize="characters" spellcheck="false" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] font-mono uppercase">
                        <p class="text-[11px] text-gray-400 mt-1">申請番号に使う英大文字1〜3文字（グループ全体で重複不可）</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">表示順<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="number" name="sort_order" value="0" required inputmode="numeric" min="0" max="9999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="departmentCreateModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">保存する</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 部門の編集 --}}
    <div x-show="departmentEditModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="departmentEditModal = false" class="bg-white rounded-xl w-full max-w-[420px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" :action="'{{ url('approvals/admin/organization/departments') }}/' + editDepartmentId">
                @csrf
                @method('PUT')
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">部門の編集</div>
                <div class="px-6 py-4 space-y-3.5">
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">会社<span class="text-red-600 ml-0.5">*</span></label>
                        <select name="company_id" x-model="editDepartmentCompanyId" required class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                            @foreach($companies as $company)
                                <option value="{{ $company->id }}">{{ $company->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">部門名<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="name" x-model="editDepartmentName" required maxlength="50" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">略称<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="short_name" x-model="editDepartmentShortName" required maxlength="6" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                        <p class="text-[11px] text-gray-400 mt-1">データ印の上段に入るので6文字まで</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">アルファベット<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="code" x-model="editDepartmentCode" required maxlength="3" autocapitalize="characters" spellcheck="false" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] font-mono uppercase">
                        <p class="text-[11px] text-gray-400 mt-1">申請番号に使う英大文字1〜3文字（グループ全体で重複不可）</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">表示順<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="number" name="sort_order" x-model="editDepartmentSort" required inputmode="numeric" min="0" max="9999" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    </div>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="departmentEditModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">保存する</button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
function approvalOrganization() {
    return {
        companyCreateModal: false,
        companyEditModal: false,
        editCompanyId: null,
        editCompanyName: '',
        editCompanyMonth: '1',
        editCompanySort: '0',

        departmentCreateModal: false,
        departmentEditModal: false,
        editDepartmentId: null,
        editDepartmentCompanyId: '',
        editDepartmentName: '',
        editDepartmentShortName: '',
        editDepartmentCode: '',
        editDepartmentSort: '0',

        openCompanyEdit(row) {
            this.editCompanyId = row.id;
            this.editCompanyName = row.name;
            this.editCompanyMonth = String(row.fiscal_start_month);
            this.editCompanySort = String(row.sort_order);
            this.companyEditModal = true;
        },

        openDepartmentEdit(row) {
            this.editDepartmentId = row.id;
            this.editDepartmentCompanyId = String(row.company_id);
            this.editDepartmentName = row.name;
            this.editDepartmentShortName = row.short_name;
            this.editDepartmentCode = row.code;
            this.editDepartmentSort = String(row.sort_order);
            this.departmentEditModal = true;
        }
    };
}
</script>
@endpush
