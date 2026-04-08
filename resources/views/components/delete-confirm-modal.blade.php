@props([
    'title',
    'action',
    'target',
    'message' => null,
    'button' => '削除する',
    'show' => 'showDeleteModal',
])

<div x-show="{{ $show }}" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @keydown.escape.window="{{ $show }} = false">

    <div class="fixed inset-0 bg-black/50" @click="{{ $show }} = false"></div>

    <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full mx-auto p-6"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         @click.stop>

        <div class="flex items-start gap-3 mb-3">
            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-red-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
            <div>
                <h3 class="text-base font-bold text-gray-900">{{ $title }}</h3>
            </div>
        </div>

        <div class="ml-[52px] mb-4">
            <div class="bg-red-50 border border-red-200 rounded-md px-3 py-2 mb-2">
                <span class="text-sm font-bold text-red-800">{{ $target }}</span>
            </div>
            @if($message)
                <p class="text-sm text-gray-600">{{ $message }}</p>
            @else
                <p class="text-sm text-gray-600">この操作は元に戻せません。削除してもよろしいですか？</p>
            @endif
        </div>

        <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 ml-[52px]">
            <button type="button"
                    @click="{{ $show }} = false"
                    class="px-4 py-2.5 bg-white border border-gray-300 rounded-md text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors cursor-pointer w-full sm:w-auto text-center">
                キャンセル
            </button>
            <form method="POST" action="{{ $action }}">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="px-4 py-2.5 bg-red-600 text-white rounded-md text-sm font-semibold hover:bg-red-700 transition-colors cursor-pointer w-full text-center sm:w-auto flex items-center justify-center gap-1.5">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                    </svg>
                    {{ $button }}
                </button>
            </form>
        </div>
    </div>
</div>
