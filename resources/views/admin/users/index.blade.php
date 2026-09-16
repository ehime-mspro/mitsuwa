@extends('layouts.app')

@section('title', 'ユーザー管理')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('admin.users.index') }}" class="hover:text-emerald-600 transition-colors">システム管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">ユーザー管理</span>
@endsection

@section('content')
@php
    $usersJsonData = $users->getCollection()->map(fn($u) => [
        'id' => $u->id,
        'name' => $u->name,
        'email' => $u->email,
        'role' => $u->role->value,
        'departments' => $u->departments->pluck('id')->values(),
    ])->values();
@endphp
<script type="application/json" id="usersData">{!! json_encode($usersJsonData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

<div x-data="userManagement()" x-cloak>

    {{-- バリデーションエラー --}}
    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">ユーザー管理</h1>
        <button
            @click="createModal = true"
            class="inline-flex items-center justify-center gap-1.5 px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-[13px] font-semibold rounded-md transition-colors cursor-pointer w-full sm:w-auto"
        >
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            ユーザーを登録
        </button>
    </div>

    {{-- 決裁の社長（設計書 §5.7） --}}
    <div class="flex flex-wrap items-center gap-2 mb-5 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5 text-[13px]">
        <span class="text-gray-500">決裁の社長:</span>
        <span class="font-medium text-gray-900">{{ $settings->president?->name ?? '未設定' }}</span>
        <button type="button" @click="presidentModal = true" class="text-[12px] text-emerald-600 hover:underline cursor-pointer bg-transparent border-none p-0">変更</button>
    </div>

    {{-- フィルターバー --}}
    <form method="GET" action="{{ route('admin.users.index') }}"
          class="flex flex-col sm:flex-row sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <select name="role" class="h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">ロール: すべて</option>
            @foreach(App\Enums\UserRole::cases() as $role)
                <option value="{{ $role->value }}" {{ request('role') === $role->value ? 'selected' : '' }}>{{ $role->label() }}</option>
            @endforeach
        </select>
        <select name="department" class="h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">部門: すべて</option>
            @foreach($departments as $dept)
                <option value="{{ $dept->id }}" {{ request('department') == $dept->id ? 'selected' : '' }}>{{ $dept->name }}</option>
            @endforeach
        </select>
        <select name="status" class="h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">状態: すべて</option>
            @foreach(App\Enums\UserStatus::cases() as $st)
                <option value="{{ $st->value }}" {{ request('status') === $st->value ? 'selected' : '' }}>{{ $st->label() }}</option>
            @endforeach
                <option value="deleted" {{ request('status') === 'deleted' ? 'selected' : '' }}>削除済み</option>
        </select>
        <input type="text" name="search" value="{{ request('search') }}" placeholder="氏名・社員番号・メールで検索"
               class="h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none w-full sm:flex-1 sm:min-w-[140px]">
        <button type="submit"
                class="h-8 px-3.5 bg-gray-50 border border-gray-300 rounded-md text-[12px] text-gray-700 hover:bg-gray-100 cursor-pointer transition-colors w-full sm:w-auto">検索</button>
        @if(request()->anyFilled(['role', 'department', 'status', 'search']))
            <a href="{{ route('admin.users.index') }}" class="text-[12px] text-gray-500 hover:text-emerald-600 transition-colors text-center sm:text-left">クリア</a>
        @endif
    </form>

    {{-- テーブル --}}
    <div class="bg-white rounded-lg border border-gray-200">
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                <table class="w-full min-w-[760px] border-collapse">
            <thead>
                <tr>
                    <th class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap w-[1%]">社員番号</th>
                    <th class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">氏名</th>
                    <th class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 text-center text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap w-[1%]">ロール</th>
                    <th class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap">所属部門</th>
                    <th class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 text-center text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap w-[1%]">状態</th>
                    <th class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap w-[1%]">最終ログイン</th>
                    <th class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap w-[1%]">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $u)
                    <tr class="{{ $u->status === App\Enums\UserStatus::Inactive ? 'opacity-50' : '' }} hover:bg-gray-50">
                        <td class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 border-b border-gray-100 whitespace-nowrap font-mono text-[12px] text-gray-700">{{ $u->employee_number ?? '—' }}</td>
                        <td class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 border-b border-gray-100 whitespace-nowrap">
                            <span class="text-[13px] font-medium text-gray-900">{{ $u->name }}</span>
                            @if($u->isApprovalPresident())<span class="ml-1.5 inline-block px-1.5 rounded bg-purple-100 text-purple-800 text-[10px]">社長</span>@endif
                            @if($u->isApprovalAdmin())<span class="ml-1 inline-block px-1.5 rounded bg-emerald-100 text-emerald-800 text-[10px]">決裁管理</span>@endif
                            @if($u->canViewAllApprovals())<span class="ml-1 inline-block px-1.5 rounded bg-sky-100 text-sky-800 text-[10px]">全件閲覧</span>@endif
                        </td>
                        <td class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 border-b border-gray-100 whitespace-nowrap text-center">
                            <span class="inline-block px-2 rounded text-[11px] font-medium
                                @switch($u->role)
                                    @case(App\Enums\UserRole::Executive) bg-amber-100 text-amber-800 @break
                                    @case(App\Enums\UserRole::Manager) bg-blue-100 text-blue-800 @break
                                    @case(App\Enums\UserRole::Staff) bg-gray-100 text-gray-600 @break
                                    @case(App\Enums\UserRole::ApprovalOnly) bg-purple-100 text-purple-800 @break
                                @endswitch
                            " style="padding-top:2px; padding-bottom:2px;">{{ $u->role->label() }}</span>
                        </td>
                        <td class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 border-b border-gray-100 text-[11px] text-gray-700 whitespace-nowrap">
                            {{ $u->departments->pluck('name')->join('・') }}
                        </td>
                        <td class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 border-b border-gray-100 whitespace-nowrap text-center">
                            @if($u->trashed())
                                <span class="inline-block px-2 rounded text-[11px] font-medium bg-gray-200 text-gray-600" style="padding-top:2px; padding-bottom:2px;">削除済み</span>
                            @else
                                <span class="inline-block px-2 rounded text-[11px] font-medium
                                    {{ $u->status === App\Enums\UserStatus::Active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}
                                " style="padding-top:2px; padding-bottom:2px;">{{ $u->status->label() }}</span>
                            @endif
                        </td>
                        <td class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 border-b border-gray-100 text-[12px] text-gray-400 whitespace-nowrap">
                            {{ $u->last_login_at ? $u->last_login_at->format('m/d H:i') : '—' }}
                        </td>
                        <td class="px-3.5 py-2.5 lg:px-5 lg:py-3.5 border-b border-gray-100 text-right whitespace-nowrap">
                            @if($u->trashed())
                                {{-- 削除済み行: 復元のみ --}}
                                <form action="{{ url('admin/users/'.$u->id.'/restore') }}" method="POST" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="text-[12px] text-emerald-600 hover:underline cursor-pointer bg-transparent border-none p-0 font-normal">復元</button>
                                </form>
                            @else
                                {{-- 編集 --}}
                                <button
                                    @click="openEditModal({{ $u->id }}, {{ \Illuminate\Support\Js::from($u->name) }}, {{ \Illuminate\Support\Js::from($u->employee_number ?? '') }}, {{ \Illuminate\Support\Js::from($u->email ?? '') }}, '{{ $u->role->value }}', {{ \Illuminate\Support\Js::from($u->departments->pluck('id')->values()) }}, '{{ $u->status->value }}', {{ \Illuminate\Support\Js::from($u->isApprovalAdmin()) }}, {{ \Illuminate\Support\Js::from($u->canViewAllApprovals()) }})"
                                    class="text-[12px] text-blue-600 hover:underline cursor-pointer bg-transparent border-none p-0 font-normal"
                                >編集</button>

                                @if($u->id !== auth()->id())
                                    {{-- PW再発行。案内の画面をその場で返すので、モーダルでなく行ごとの素のフォームにする --}}
                                    <span class="text-gray-200 mx-1">|</span>
                                    <form method="POST" action="{{ route('admin.users.resetPassword', $u) }}" class="inline"
                                          onsubmit="return confirm({{ \Illuminate\Support\Js::from($u->name . 'さんのパスワードを再発行します。印刷用の案内が開きます。よろしいですか。') }});">
                                        @csrf
                                        {{-- 1 回限りの鍵。行ごとに違う値をサーバーで描くので、ブラウザの再送信では同じ鍵になり 2 回目が止まる --}}
                                        <input type="hidden" name="guide_token" value="{{ \App\Support\OneTimeAction::issue() }}">
                                        <button type="submit" class="text-[12px] text-amber-600 hover:underline cursor-pointer bg-transparent border-none p-0 font-normal">PW再発行</button>
                                    </form>

                                    {{-- 無効化/有効化 --}}
                                    <span class="text-gray-200 mx-1">|</span>
                                    @if($u->status === App\Enums\UserStatus::Active)
                                        <button
                                            @click="openDisableModal({{ $u->id }}, {{ \Illuminate\Support\Js::from($u->name) }})"
                                            class="text-[12px] text-red-600 hover:underline cursor-pointer bg-transparent border-none p-0 font-normal"
                                        >無効化</button>
                                    @else
                                        <button
                                            @click="openEnableModal({{ $u->id }}, {{ \Illuminate\Support\Js::from($u->name) }})"
                                            class="text-[12px] text-emerald-600 hover:underline cursor-pointer bg-transparent border-none p-0 font-normal"
                                        >有効化</button>
                                    @endif

                                    {{-- 削除 --}}
                                    <span class="text-gray-200 mx-1">|</span>
                                    <button
                                        @click="openDeleteModal({{ $u->id }}, {{ \Illuminate\Support\Js::from($u->name) }})"
                                        class="text-[12px] text-red-600 hover:underline cursor-pointer bg-transparent border-none p-0 font-normal"
                                    >削除</button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-3.5 py-8 text-center text-[13px] text-gray-400">
                            該当するユーザーが見つかりません。
                        </td>
                    </tr>
                @endforelse
            </tbody>
                </table>
            </div>{{-- /scroll-hint-inner --}}
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>{{-- /scroll-hint --}}

        {{-- ページネーション --}}
        @if($users->hasPages())
            <div class="flex justify-center gap-0.5 py-3 border-t border-gray-200">
                @if($users->onFirstPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                @else
                    <a href="{{ $users->previousPageUrl() }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
                @endif
                @foreach($users->getUrlRange(1, $users->lastPage()) as $page => $url)
                    @if($page == $users->currentPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}"
                           class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                    @endif
                @endforeach
                @if($users->hasMorePages())
                    <a href="{{ $users->nextPageUrl() }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
                @else
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                @endif
            </div>
        @endif
    </div>

    {{-- ========== 新規登録モーダル ========== --}}
    <div x-show="createModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div @click.outside="createModal = false" class="bg-white rounded-xl w-full max-w-[480px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" action="{{ route('admin.users.store') }}">
                @csrf
                {{-- 1 回限りの鍵。ブラウザの再送信では同じ鍵になり 2 回目が止まる（設計書 §5.12） --}}
                <input type="hidden" name="guide_token" value="{{ \App\Support\OneTimeAction::issue() }}">
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">ユーザー新規登録</div>
                <div class="px-6 py-4 space-y-3.5">
                    {{-- 氏名 --}}
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">氏名<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="name" required maxlength="100"
                               class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] text-gray-700 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               placeholder="例: 山田太郎">
                    </div>
                    {{-- 社員番号 --}}
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">社員番号</label>
                        <input type="text" name="employee_number" maxlength="20"
                               class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] text-gray-700 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               placeholder="例: M001">
                    </div>
                    {{-- メール --}}
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">メールアドレス</label>
                        <input type="email" name="email" maxlength="255"
                               class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] text-gray-700 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               placeholder="例: yamada@mitsuwa.co.jp">
                        <p class="text-[11px] text-gray-400 mt-1.5">※ 社員番号とメールアドレスは、どちらか一方は必ず入力してください（どちらもログインIDになります）。</p>
                    </div>
                    {{-- ロール --}}
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">ロール<span class="text-red-600 ml-0.5">*</span></label>
                        <select name="role" required
                                class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer">
                            <option value="staff">一般担当者</option>
                            <option value="manager">部門管理者</option>
                            <option value="executive">経営層</option>
                        </select>
                    </div>
                    {{-- 所属部門 --}}
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">所属部門<span class="text-red-600 ml-0.5">*</span>（複数選択可）</label>
                        <div class="border border-gray-300 rounded-md p-2.5 grid grid-cols-2 sm:grid-cols-3 gap-1">
                            @foreach($departments as $dept)
                                <label class="flex items-center gap-1.5 text-[12px] text-gray-700 cursor-pointer px-1.5 py-1 rounded hover:bg-gray-50">
                                    <input type="checkbox" name="departments[]" value="{{ $dept->id }}"
                                           class="w-[15px] h-[15px] accent-emerald-600 cursor-pointer">
                                    {{ $dept->name }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <p class="text-[11px] text-gray-400 leading-relaxed">
                        ※ 初期パスワードは登録時にシステムが作ります。登録すると印刷用の「ログインのご案内」が開くので、印刷して本人にお渡しください。
                    </p>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="createModal = false"
                            class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] text-gray-700 hover:bg-gray-50 cursor-pointer transition-colors">キャンセル</button>
                    <button type="submit"
                            class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer transition-colors">登録する</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ========== 編集モーダル ========== --}}
    <div x-show="editModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div @click.outside="editModal = false" class="bg-white rounded-xl w-full max-w-[480px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form :action="'{{ url('admin/users') }}/' + editUserId" method="POST">
                @csrf
                @method('PUT')
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">ユーザー情報 編集</div>
                <div class="px-6 py-4 space-y-3.5">
                    {{-- 氏名 --}}
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">氏名<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="text" name="name" x-model="editName" required maxlength="100"
                               class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] text-gray-700 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                    </div>
                    {{-- 社員番号 --}}
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">社員番号</label>
                        <input type="text" name="employee_number" x-model="editEmployeeNumber" maxlength="20"
                               class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] text-gray-700 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                    </div>
                    {{-- メール --}}
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">メールアドレス</label>
                        <input type="email" name="email" x-model="editEmail" maxlength="255"
                               class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] text-gray-700 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                        <p class="text-[11px] text-gray-400 mt-1.5">※ 社員番号とメールアドレスは、どちらか一方は必ず入力してください。</p>
                    </div>
                    {{-- ロール --}}
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">ロール<span class="text-red-600 ml-0.5">*</span></label>
                        <select name="role" x-model="editRole" required
                                class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer">
                            <option value="staff">一般担当者</option>
                            <option value="manager">部門管理者</option>
                            <option value="executive">経営層</option>
                            <option value="approval_only">決裁のみ</option>
                        </select>
                    </div>
                    {{-- 所属部門（決裁のみ利用者は基幹の所属部門を持たない。設計書 §5.7） --}}
                    <div x-show="editRole !== 'approval_only'">
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">所属部門<span class="text-red-600 ml-0.5">*</span>（複数選択可）</label>
                        <div class="border border-gray-300 rounded-md p-2.5 grid grid-cols-2 sm:grid-cols-3 gap-1">
                            @foreach($departments as $dept)
                                <label class="flex items-center gap-1.5 text-[12px] text-gray-700 cursor-pointer px-1.5 py-1 rounded hover:bg-gray-50">
                                    <input type="checkbox" name="departments[]" value="{{ $dept->id }}"
                                           :checked="editDepartments.includes({{ $dept->id }})"
                                           class="w-[15px] h-[15px] accent-emerald-600 cursor-pointer">
                                    {{ $dept->name }}
                                </label>
                            @endforeach
                        </div>
                        <p class="text-[11px] text-gray-400 mt-1.5">※ 「決裁のみ」にすると、基幹の所属部門は外れます。</p>
                    </div>
                    {{-- 決裁の指定（設計書 §5.7） --}}
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">決裁の指定</label>
                        <div class="border border-gray-300 rounded-md p-2.5 space-y-1">
                            <label class="flex items-center gap-1.5 text-[12px] text-gray-700 cursor-pointer px-1.5 py-1 rounded hover:bg-gray-50">
                                <input type="checkbox" name="is_admin" value="1" x-model="editIsAdmin"
                                       class="w-[15px] h-[15px] accent-emerald-600 cursor-pointer">
                                決裁の管理者（会社・部門・利用者を管理できる）
                            </label>
                            <label class="flex items-center gap-1.5 text-[12px] text-gray-700 cursor-pointer px-1.5 py-1 rounded hover:bg-gray-50">
                                <input type="checkbox" name="can_view_all" value="1" x-model="editCanViewAll"
                                       class="w-[15px] h-[15px] accent-emerald-600 cursor-pointer">
                                全件閲覧者（すべての決裁を見られる）
                            </label>
                        </div>
                    </div>
                    {{-- ステータス --}}
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">ステータス</label>
                        <select name="status" x-model="editStatus" required
                                class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer">
                            <option value="active">有効</option>
                            <option value="inactive">無効</option>
                        </select>
                    </div>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="editModal = false"
                            class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] text-gray-700 hover:bg-gray-50 cursor-pointer transition-colors">キャンセル</button>
                    <button type="submit"
                            class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer transition-colors">更新する</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ========== 決裁の社長の変更モーダル ========== --}}
    <div x-show="presidentModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div @click.outside="presidentModal = false" class="bg-white rounded-xl w-full max-w-[440px] shadow-xl mx-4">
            <form method="POST" action="{{ route('admin.users.president') }}">
                @csrf
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">決裁の社長</div>
                <div class="px-6 py-4">
                    <label class="block text-[12px] font-semibold text-gray-700 mb-1">社長に指定する利用者<span class="text-red-600 ml-0.5">*</span></label>
                    {{-- ⚠ <option> は @foreach で静的に出す（x-for は x-model の同期より後に描画されて値がズレる。Bug #16） --}}
                    <select name="president_user_id" required
                            class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer">
                        @foreach($presidentCandidates as $candidate)
                            <option value="{{ $candidate->id }}" {{ $settings->president_user_id === $candidate->id ? 'selected' : '' }}>{{ $candidate->name }}（{{ $candidate->email }}）</option>
                        @endforeach
                    </select>
                    <p class="text-[11px] text-gray-400 mt-1.5 leading-relaxed">
                        ※ 候補は「有効でメールアドレスのある利用者」です（決裁の通知メールを受け取るため）。<br>
                        ※ 社長に指定された利用者は、無効化・削除・メールアドレスを空にする操作ができなくなります。
                    </p>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="presidentModal = false"
                            class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] text-gray-700 hover:bg-gray-50 cursor-pointer transition-colors">キャンセル</button>
                    <button type="submit"
                            class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer transition-colors">設定する</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ========== 無効化確認モーダル ========== --}}
    <div x-show="disableModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div @click.outside="disableModal = false" class="bg-white rounded-xl w-full max-w-[400px] shadow-xl mx-4">
            <form :action="'{{ url('admin/users') }}/' + toggleUserId + '/toggle-status'" method="POST">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="inactive">
                <div class="px-6 py-6 text-center">
                    <div class="w-11 h-11 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-3">
                        <svg class="w-[22px] h-[22px] text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    </div>
                    <p class="text-[14px] text-gray-700 mb-1"><strong x-text="toggleUserName"></strong> さんを無効化しますか？</p>
                    <p class="text-[12px] text-gray-400 mb-4 leading-relaxed">無効化するとログインできなくなります。後から有効に戻せます。</p>
                    <div class="flex justify-center gap-2">
                        <button type="button" @click="disableModal = false"
                                class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] text-gray-700 hover:bg-gray-50 cursor-pointer transition-colors">キャンセル</button>
                        <button type="submit"
                                class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-[13px] font-semibold cursor-pointer transition-colors">無効化する</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- ========== 有効化確認モーダル ========== --}}
    <div x-show="enableModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div @click.outside="enableModal = false" class="bg-white rounded-xl w-full max-w-[400px] shadow-xl mx-4">
            <form :action="'{{ url('admin/users') }}/' + toggleUserId + '/toggle-status'" method="POST">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="active">
                <div class="px-6 py-6 text-center">
                    <div class="w-11 h-11 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-3">
                        <svg class="w-[22px] h-[22px] text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </div>
                    <p class="text-[14px] text-gray-700 mb-1"><strong x-text="toggleUserName"></strong> さんを有効化しますか？</p>
                    <p class="text-[12px] text-gray-400 mb-4 leading-relaxed">有効化すると再びログインできるようになります。</p>
                    <div class="flex justify-center gap-2">
                        <button type="button" @click="enableModal = false"
                                class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] text-gray-700 hover:bg-gray-50 cursor-pointer transition-colors">キャンセル</button>
                        <button type="submit"
                                class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer transition-colors">有効化する</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- ========== 削除確認モーダル ========== --}}
    <div x-show="deleteModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div @click.outside="deleteModal = false" class="bg-white rounded-xl w-full max-w-[400px] shadow-xl mx-4">
            <form :action="'{{ url('admin/users') }}/' + deleteUserId" method="POST">
                @csrf
                @method('DELETE')
                <div class="px-6 py-6 text-center">
                    <div class="w-11 h-11 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-3">
                        <svg class="w-[22px] h-[22px] text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    </div>
                    <p class="text-[14px] text-gray-700 mb-1"><strong x-text="deleteUserName"></strong> さんを削除しますか？</p>
                    <p class="text-[12px] text-gray-400 mb-4 leading-relaxed">削除するとログイン・担当者選択に表示されなくなります。過去の担当履歴は残り、「削除済み」から復元できます。</p>
                    <div class="flex justify-center gap-2">
                        <button type="button" @click="deleteModal = false"
                                class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] text-gray-700 hover:bg-gray-50 cursor-pointer transition-colors">キャンセル</button>
                        <button type="submit"
                                class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-[13px] font-semibold cursor-pointer transition-colors">削除する</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

</div>

<script>
function userManagement() {
    return {
        // モーダル状態
        createModal: false,
        editModal: false,
        presidentModal: false,
        disableModal: false,
        enableModal: false,
        deleteModal: false,

        // 編集
        editUserId: null,
        editName: '',
        editEmployeeNumber: '',
        editEmail: '',
        editRole: 'staff',
        editDepartments: [],
        editStatus: 'active',
        editIsAdmin: false,
        editCanViewAll: false,

        // 無効化/有効化
        toggleUserId: null,
        toggleUserName: '',

        // 削除
        deleteUserId: null,
        deleteUserName: '',

        // 編集モーダル
        openEditModal(id, name, employeeNumber, email, role, departments, status, isAdmin, canViewAll) {
            this.editUserId = id;
            this.editName = name;
            this.editEmployeeNumber = employeeNumber;
            this.editEmail = email;
            this.editRole = role;
            this.editDepartments = departments;
            this.editStatus = status;
            this.editIsAdmin = isAdmin;
            this.editCanViewAll = canViewAll;
            this.editModal = true;
        },

        // 無効化モーダル
        openDisableModal(id, name) {
            this.toggleUserId = id;
            this.toggleUserName = name;
            this.disableModal = true;
        },

        // 有効化モーダル
        openEnableModal(id, name) {
            this.toggleUserId = id;
            this.toggleUserName = name;
            this.enableModal = true;
        },

        // 削除モーダル
        openDeleteModal(id, name) {
            this.deleteUserId = id;
            this.deleteUserName = name;
            this.deleteModal = true;
        }
    };
}
</script>
@endsection
