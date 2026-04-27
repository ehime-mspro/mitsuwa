<x-guest-layout>
    <div class="w-full max-w-[420px]">
        <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(0,0,0,0.06),0_1px_2px_rgba(0,0,0,0.04)] px-10 py-11">

            {{-- ロゴ & タイトル --}}
            <div class="text-center mb-9">
                <img src="{{ asset('images/logo.png') }}" alt="ロゴ" class="w-[44px] h-auto mx-auto mb-4">
                <h1 class="text-xl font-bold text-gray-900 tracking-wide mb-1.5">経営管理システム</h1>
                <div class="w-8 h-0.5 mx-auto rounded-full bg-gradient-to-r from-emerald-500 to-emerald-300"></div>
            </div>

            {{-- エラーメッセージ --}}
            @if($errors->has('login'))
                <div class="flex items-center gap-2.5 px-3.5 py-3 mb-5 rounded-[10px] bg-red-50 border border-red-100">
                    <div class="w-5 h-5 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                        <svg class="w-3 h-3 text-red-600" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                            <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </div>
                    <span class="text-[13px] text-red-800 leading-snug">{{ $errors->first('login') }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}">
                @csrf

                {{-- メールアドレス --}}
                <div class="mb-4">
                    <label for="email" class="block text-xs font-semibold text-gray-700 mb-2 tracking-wide">メールアドレス</label>
                    <div class="flex items-center gap-2.5 px-3.5 h-[46px] rounded-[10px] border-[1.5px] bg-gray-50 transition-all duration-200 focus-within:border-emerald-500 focus-within:bg-white focus-within:shadow-[0_0_0_3px_rgba(16,163,127,0.08)] {{ $errors->has('email') ? 'border-red-300' : 'border-gray-200' }}">
                        <svg class="w-[18px] h-[18px] text-gray-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="4" width="20" height="16" rx="2" />
                            <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
                        </svg>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            placeholder="user@example.com"
                            required
                            autofocus
                            class="flex-1 bg-transparent border-none outline-none text-sm text-gray-900 placeholder-gray-400"
                        >
                    </div>
                    @error('email')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- パスワード --}}
                <div class="mb-5">
                    <label for="password" class="block text-xs font-semibold text-gray-700 mb-2 tracking-wide">パスワード</label>
                    <div class="flex items-center gap-2.5 px-3.5 h-[46px] rounded-[10px] border-[1.5px] bg-gray-50 transition-all duration-200 focus-within:border-emerald-500 focus-within:bg-white focus-within:shadow-[0_0_0_3px_rgba(16,163,127,0.08)] {{ $errors->has('password') ? 'border-red-300' : 'border-gray-200' }}">
                        <svg class="w-[18px] h-[18px] text-gray-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                        </svg>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            placeholder="パスワードを入力"
                            required
                            class="flex-1 bg-transparent border-none outline-none text-sm text-gray-900 placeholder-gray-400"
                        >
                    </div>
                    @error('password')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- ログイン状態を保持 --}}
                <div class="mb-7">
                    <label class="flex items-center gap-2.5 cursor-pointer group">
                        <div class="relative w-[18px] h-[18px]">
                            <input type="checkbox" name="remember" {{ old('remember') ? 'checked' : '' }} class="peer sr-only">
                            <div class="absolute inset-0 rounded-[5px] border-[1.5px] border-gray-300 bg-white transition-all duration-150 peer-checked:border-transparent peer-checked:bg-emerald-500 peer-checked:shadow-[0_1px_3px_rgba(16,163,127,0.3)]"></div>
                            <svg class="absolute inset-0 m-auto w-2.5 h-2.5 text-white opacity-0 peer-checked:opacity-100 transition-opacity pointer-events-none" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 12 12">
                                <path d="M2 6l3 3 5-6" />
                            </svg>
                        </div>
                        <span class="text-[13px] text-gray-500 group-hover:text-gray-700 transition-colors">ログイン状態を保持</span>
                    </label>
                </div>

                {{-- ログインボタン --}}
                <button
                    type="submit"
                    class="w-full h-[46px] bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-[10px] tracking-wide shadow-[0_2px_8px_rgba(16,163,127,0.3)] hover:shadow-[0_4px_12px_rgba(16,163,127,0.4)] transition-all duration-200"
                >
                    ログイン
                </button>
            </form>

            {{-- パスワードをお忘れの方 --}}
            <div class="text-center mt-5" x-data="{ showHelp: false }">
                <button
                    @click="showHelp = !showHelp"
                    type="button"
                    class="text-xs text-gray-500 hover:text-emerald-600 hover:bg-emerald-50 px-2 py-1 rounded-md transition-all duration-150"
                >
                    パスワードをお忘れの方
                </button>

                <div
                    x-show="showHelp"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1"
                    class="flex items-start gap-2.5 px-3.5 py-3 mt-3 rounded-[10px] bg-emerald-50 border border-emerald-200"
                    style="display: none;"
                >
                    <div class="w-5 h-5 rounded-full bg-emerald-100 flex items-center justify-center shrink-0 mt-0.5">
                        <svg class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10" /><line x1="12" y1="16" x2="12" y2="12" /><line x1="12" y1="8" x2="12.01" y2="8" />
                        </svg>
                    </div>
                    <span class="text-xs text-emerald-800 leading-relaxed">
                        パスワードを忘れた場合は、システム管理者にお問い合わせください。管理者がパスワードをリセットいたします。
                    </span>
                </div>
            </div>

        </div>
    </div>
</x-guest-layout>
