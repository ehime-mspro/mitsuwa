{{-- 削除ブロッカー パネル（依存が 1 件以上あるときだけ描画する。0 件なら空枠も出さない） --}}
{{-- 呼び出し: @include('realestate._partials._deletion_blockers', ['blockers' => $deletionBlockers]) --}}
@if($blockers)
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-3">
            <svg class="w-4 h-4 text-amber-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                <line x1="12" y1="9" x2="12" y2="13" />
                <line x1="12" y1="17" x2="12.01" y2="17" />
            </svg>
            <h2 class="text-sm font-bold text-amber-900">このデータを参照しているため削除できません</h2>
        </div>
        @foreach($blockers as $blocker)
            <div class="mb-2">
                <div class="text-xs font-semibold text-amber-800 mb-1">{{ $blocker['label'] }} {{ count($blocker['items']) }} 件</div>
                @foreach($blocker['items'] as $item)
                    <div class="text-sm text-amber-900 mb-0.5">
                        ・<a href="{{ $item['url'] }}" class="font-medium text-emerald-700 hover:text-emerald-800">{{ $item['name'] }}</a>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
@endif
