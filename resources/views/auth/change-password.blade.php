@extends('layouts.app')

@section('title', 'パスワード変更')

@section('breadcrumb')
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="text-gray-600">パスワード変更</span>
@endsection

@section('content')
    <div class="flex justify-center">
        <div class="w-full max-w-[480px]">
            <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(0,0,0,0.06),0_1px_2px_rgba(0,0,0,0.04)] px-9 py-9">

                {{-- タイトル --}}
                <div class="mb-7">
                    <h2 class="text-lg font-bold text-gray-900 mb-1.5">パスワード変更</h2>
                    <div class="w-8 h-0.5 rounded-full bg-gradient-to-r from-emerald-500 to-emerald-300 mb-2"></div>

                    {{-- 初回強制変更メッセージ --}}
                    @if($isForced)
                        <div class="flex items-center gap-2 px-3 py-2.5 mt-3 rounded-lg bg-amber-50 border border-amber-200">
                            <svg class="w-4 h-4 text-amber-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                                <line x1="12" y1="9" x2="12" y2="13" />
                                <line x1="12" y1="17" x2="12.01" y2="17" />
                            </svg>
                            <span class="text-xs text-amber-800 leading-snug">初回ログインのため、パスワードの変更が必要です。</span>
                        </div>
                    @endif

                    {{-- セッション由来の警告メッセージ --}}
                    @if(session('warning'))
                        <div class="flex items-center gap-2 px-3 py-2.5 mt-3 rounded-lg bg-amber-50 border border-amber-200">
                            <svg class="w-4 h-4 text-amber-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                                <line x1="12" y1="9" x2="12" y2="13" />
                                <line x1="12" y1="17" x2="12.01" y2="17" />
                            </svg>
                            <span class="text-xs text-amber-800 leading-snug">{{ session('warning') }}</span>
                        </div>
                    @endif
                </div>

                {{-- エラーメッセージ（現在のパスワードが不正な場合等） --}}
                @if($errors->any())
                    <div class="flex items-center gap-2.5 px-3.5 py-3 mb-5 rounded-[10px] bg-red-50 border border-red-100">
                        <div class="w-5 h-5 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                            <svg class="w-3 h-3 text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                                <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
                            </svg>
                        </div>
                        <span class="text-[13px] text-red-800 leading-snug">{{ $errors->first() }}</span>
                    </div>
                @endif

                <form method="POST" action="{{ route('password.update') }}">
                    @csrf
                    @method('PUT')

                    {{-- 現在のパスワード --}}
                    <div class="mb-4">
                        <label for="current_password" class="block text-xs font-semibold text-gray-700 mb-2 tracking-wide">
                            現在のパスワード <span class="text-red-500">*</span>
                        </label>
                        <div class="flex items-center gap-2.5 px-3.5 h-[46px] rounded-[10px] border-[1.5px] bg-gray-50 transition-all duration-200 focus-within:border-emerald-500 focus-within:bg-white focus-within:shadow-[0_0_0_3px_rgba(16,163,127,0.08)] {{ $errors->has('current_password') ? 'border-red-300' : 'border-gray-200' }}">
                            <svg class="w-[18px] h-[18px] text-gray-400 shrink-0" viewBox="-1 -1 26 26" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                            </svg>
                            <input
                                id="current_password"
                                name="current_password"
                                type="password"
                                placeholder="現在のパスワードを入力"
                                required
                                autofocus
                                class="flex-1 bg-transparent border-none outline-none text-sm text-gray-900 placeholder-gray-400"
                            >
                        </div>
                    </div>

                    {{-- 新しいパスワード --}}
                    <div class="mb-1.5">
                        <label for="password" class="block text-xs font-semibold text-gray-700 mb-2 tracking-wide">
                            新しいパスワード <span class="text-red-500">*</span>
                        </label>
                        <div class="flex items-center gap-2.5 px-3.5 h-[46px] rounded-[10px] border-[1.5px] bg-gray-50 transition-all duration-200 focus-within:border-emerald-500 focus-within:bg-white focus-within:shadow-[0_0_0_3px_rgba(16,163,127,0.08)] {{ $errors->has('password') ? 'border-red-300' : 'border-gray-200' }}">
                            <svg class="w-[18px] h-[18px] text-gray-400 shrink-0" viewBox="-1 -1 26 26" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.78 7.78 5.5 5.5 0 0 1 7.78-7.78zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4" />
                            </svg>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                placeholder="新しいパスワードを入力"
                                required
                                class="flex-1 bg-transparent border-none outline-none text-sm text-gray-900 placeholder-gray-400"
                            >
                        </div>
                    </div>
                    <p class="text-[11px] text-gray-400 mb-4 pl-0.5">※ 8文字以上・英数字混合</p>

                    {{-- 新しいパスワード（確認） --}}
                    <div class="mb-7">
                        <label for="password_confirmation" class="block text-xs font-semibold text-gray-700 mb-2 tracking-wide">
                            新しいパスワード（確認） <span class="text-red-500">*</span>
                        </label>
                        <div class="flex items-center gap-2.5 px-3.5 h-[46px] rounded-[10px] border-[1.5px] bg-gray-50 transition-all duration-200 focus-within:border-emerald-500 focus-within:bg-white focus-within:shadow-[0_0_0_3px_rgba(16,163,127,0.08)] border-gray-200">
                            <svg class="w-[18px] h-[18px] text-gray-400 shrink-0" viewBox="-1 -1 26 26" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                            </svg>
                            <input
                                id="password_confirmation"
                                name="password_confirmation"
                                type="password"
                                placeholder="もう一度入力してください"
                                required
                                class="flex-1 bg-transparent border-none outline-none text-sm text-gray-900 placeholder-gray-400"
                            >
                        </div>
                    </div>

                    {{-- ボタン --}}
                    <div class="flex gap-3 justify-end">
                        @unless($isForced)
                            <a
                                href="{{ url()->previous() !== url()->current() ? url()->previous() : route('dashboard') }}"
                                class="inline-flex items-center px-6 py-2.5 text-[13px] font-medium text-gray-500 bg-white border-[1.5px] border-gray-200 rounded-[10px] hover:bg-gray-50 hover:border-gray-300 transition-all duration-150"
                            >
                                キャンセル
                            </a>
                        @endunless

                        <button
                            type="submit"
                            class="inline-flex items-center px-7 py-2.5 text-[13px] font-semibold text-white bg-emerald-500 rounded-[10px] shadow-[0_2px_8px_rgba(16,163,127,0.25)] hover:bg-emerald-600 hover:shadow-[0_4px_12px_rgba(16,163,127,0.35)] transition-all duration-200 tracking-wide"
                        >
                            変更する
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>
@endsection
