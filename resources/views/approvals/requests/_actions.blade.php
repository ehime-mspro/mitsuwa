{{-- 申請の詳細の操作（設計書 §5.12）。出すのは RequestPermissions がこの人に「今できる」と判定したものだけ。
     ⚠ Task 15 で判断・取り下げ・条件確認を足す（このファイルを丸ごと置き換える）。 --}}
@if($permissions->isApplicant())
    <div class="flex flex-wrap items-center gap-2 mb-5" x-data="{ confirmDelete: false }">
        @if($permissions->canEdit())
            <a href="{{ route('approvals.requests.edit', $approvalRequest) }}" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold">{{ $approvalRequest->status === \App\Enums\ApprovalStatus::Returned ? '直して出し直す' : '編集する' }}</a>
        @endif
        <a href="{{ route('approvals.requests.create', ['copy' => $approvalRequest->id]) }}" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-700 hover:bg-gray-50">コピーして新しい申請</a>

        @if($permissions->canDelete())
            <form method="POST" action="{{ route('approvals.requests.destroy', $approvalRequest) }}">
                @csrf
                @method('DELETE')
                <button type="button" @click="confirmDelete = true" class="px-4 py-2 bg-white border border-red-200 rounded-md text-[13px] font-semibold text-red-600 hover:bg-red-50 cursor-pointer">下書きを削除</button>

                <div x-show="confirmDelete" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
                    <div @click.outside="confirmDelete = false" class="bg-white rounded-xl w-full max-w-[420px] shadow-xl mx-4 px-6 py-5">
                        <p class="text-[15px] font-bold text-gray-900 mb-2">この下書きを削除しますか？</p>
                        <p class="text-[12px] text-gray-500 mb-4">添付したファイルも消えます。元に戻せません。</p>
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="confirmDelete = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                            <button type="submit" class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">削除する</button>
                        </div>
                    </div>
                </div>
            </form>
        @endif
    </div>
@endif
