{{--
    添付ファイルセクション共通パーシャル

    @include('components.attachment-section', [
        'attachableType'      => 'contracts',
        'attachableId'        => $contract->id,
        'attachments'         => $contract->attachments,
        'deletedAttachments'  => $deletedAttachments,
    ])
--}}

@php
    $user = Auth::user();
    $isExecutive = $user->role->isExecutive();
    $canUpload = $user->role->isManagerOrAbove();
    $userId = $user->id;

    // Alpine.js用にデータを事前整形（@json内でfn()を使わないルールに準拠）
    $attachmentsData = [];
    foreach ($attachments as $a) {
        $attachmentsData[] = [
            'id'          => $a->id,
            'file_name'   => $a->file_name,
            'file_path'   => route('attachments.show', $a->id),
            'file_size'   => $a->file_size_formatted,
            'uploaded_by' => $a->uploadedByUser->name ?? '—',
            'uploaded_at' => $a->created_at->format('Y/m/d H:i'),
            'can_delete'  => $isExecutive || $a->uploaded_by === $userId,
            'confirming'  => false,
        ];
    }

    $deletedData = [];
    foreach ($deletedAttachments as $a) {
        $deletedData[] = [
            'file_name'  => $a->file_name,
            'deleted_by' => $a->deletedByUser->name ?? '—',
            'deleted_at' => $a->deleted_at->format('Y/m/d H:i'),
        ];
    }
@endphp

<div x-data="attachmentSection()">

    {{-- アップロードエリア（管理者以上のみ） --}}
    @if($canUpload)
        <div class="border-2 border-dashed rounded-lg p-5 text-center mb-4 transition-colors"
             :class="dragOver ? 'border-emerald-400 bg-emerald-50' : 'border-gray-300 bg-gray-50'"
             @dragover.prevent="dragOver = true"
             @dragleave.prevent="dragOver = false"
             @drop.prevent="handleDrop($event)">
            <svg class="w-8 h-8 text-gray-400 mx-auto mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <p class="text-sm text-gray-500 mb-2">ファイルをドラッグ&ドロップ、または</p>
            <label class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-300 rounded-md text-sm font-semibold text-gray-700 hover:bg-gray-50 cursor-pointer transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>
                ファイルを選択
                <input type="file" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.heic,.heif,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt" class="hidden" @change="handleFileSelect($event)">
            </label>
            <p class="text-xs text-gray-400 mt-2">画像・PDF・Word/Excel・CSV/テキスト・1ファイル10MBまで・複数選択可</p>
        </div>
    @endif

    {{-- アップロード中メッセージ --}}
    <div x-show="uploading" x-cloak class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2.5">
        <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-emerald-600 animate-spin" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-dasharray="31.4 31.4" stroke-linecap="round"/>
            </svg>
            <span class="text-sm text-emerald-800 font-medium">アップロード中...</span>
        </div>
    </div>

    {{-- 成功メッセージ --}}
    <div x-show="successMessage" x-cloak x-transition class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2.5">
        <span class="text-sm text-emerald-800 font-medium" x-text="successMessage"></span>
    </div>

    {{-- エラーメッセージ --}}
    <div x-show="errorMessage" x-cloak x-transition class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2.5">
        <span class="text-sm text-red-800 font-medium" x-text="errorMessage"></span>
    </div>

    {{-- ファイル一覧 --}}
    <template x-if="attachments.length > 0">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 border-b border-gray-200">ファイル名</th>
                        <th class="px-3 py-2 text-center text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">サイズ</th>
                        <th class="px-3 py-2 text-center text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">アップロード者</th>
                        <th class="px-3 py-2 text-center text-xs font-bold text-gray-600 border-b border-gray-200 whitespace-nowrap">日時</th>
                        <th class="px-3 py-2 border-b border-gray-200" style="width:110px;"></th>
                    </tr>
                </thead>
                {{-- x-for のルート要素を tbody にして、データ行+確認行を1グループにする --}}
                <template x-for="(file, index) in attachments" :key="file.id">
                    <tbody>
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2.5 border-b border-gray-100">
                                <div class="flex items-center gap-1.5">
                                    <svg class="w-4 h-4 text-gray-400 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    <a :href="file.file_path" target="_blank" class="text-emerald-600 hover:underline font-medium" x-text="file.file_name"></a>
                                </div>
                            </td>
                            <td class="px-3 py-2.5 border-b border-gray-100 text-center text-gray-500 whitespace-nowrap" x-text="file.file_size"></td>
                            <td class="px-3 py-2.5 border-b border-gray-100 text-center text-gray-700 whitespace-nowrap" x-text="file.uploaded_by"></td>
                            <td class="px-3 py-2.5 border-b border-gray-100 text-center text-gray-500 whitespace-nowrap" x-text="file.uploaded_at"></td>
                            <td class="px-3 py-2.5 border-b border-gray-100 text-center">
                                {{-- DL/削除の誤クリック防止: ヒットエリア 32px・間隔 12px・通常時から緑/赤で色分け --}}
                                <div class="flex items-center justify-center gap-3">
                                    <a :href="file.file_path + '?download=1'"
                                       class="inline-flex items-center justify-center w-8 h-8 rounded-md text-emerald-600 hover:bg-emerald-50 transition-colors" title="ダウンロード">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    </a>
                                    {{-- 削除不可ユーザーでも DL ボタンの位置が動かないよう、ボタンと同じ 32px 幅を確保 --}}
                                    <span class="w-8 flex-shrink-0">
                                        <button x-show="file.can_delete && !file.confirming"
                                                @click="file.confirming = true"
                                                class="inline-flex items-center justify-center w-8 h-8 rounded-md text-red-500 hover:bg-red-50 hover:text-red-600 transition-colors cursor-pointer" title="削除">
                                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                        </button>
                                    </span>
                                </div>
                            </td>
                        </tr>
                        <tr x-show="file.confirming" x-cloak>
                            <td colspan="5" class="px-3 py-2 border-b border-gray-100">
                                <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2.5">
                                    <div class="flex items-center justify-between flex-wrap gap-2">
                                        <div class="flex items-center gap-2">
                                            <svg class="w-4 h-4 text-red-500 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                            <span class="text-sm text-red-800"><strong x-text="file.file_name"></strong> を削除しますか？</span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <button @click="file.confirming = false"
                                                    class="px-3 py-1.5 text-xs font-semibold text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50 cursor-pointer">キャンセル</button>
                                            <button @click="deleteFile(index)"
                                                    class="px-3 py-1.5 text-xs font-semibold text-white bg-red-600 rounded-md hover:bg-red-700 cursor-pointer">削除する</button>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </template>
            </table>
        </div>
    </template>

    {{-- ファイルなし --}}
    <template x-if="attachments.length === 0 && !uploading">
        <p class="text-gray-400 text-center py-3 text-sm">添付ファイルはありません。</p>
    </template>

    {{-- 削除履歴 --}}
    <template x-if="deletedAttachments.length > 0">
        <div class="mt-4 pt-3 border-t border-dashed border-gray-200">
            <button @click="showDeleted = !showDeleted"
                    class="flex items-center gap-1.5 text-xs text-gray-400 hover:text-gray-600 transition-colors cursor-pointer">
                <svg class="w-3.5 h-3.5 transition-transform" :class="showDeleted ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
                削除履歴（<span x-text="deletedAttachments.length"></span>件）
            </button>

            <div x-show="showDeleted" x-cloak x-transition class="mt-2 space-y-1.5">
                <template x-for="del in deletedAttachments" :key="del.file_name + del.deleted_at">
                    <div class="flex items-center gap-2 text-xs text-gray-400 px-1 flex-wrap">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <span class="line-through" x-text="del.file_name"></span>
                        <span>—</span>
                        <span x-text="del.deleted_by + ' が削除'"></span>
                        <span x-text="del.deleted_at"></span>
                    </div>
                </template>
            </div>
        </div>
    </template>
</div>

<script>
function attachmentSection() {
    return {
        uploadUrl: '{{ url("/attachments/" . $attachableType . "/" . $attachableId) }}',
        csrfToken: '{{ csrf_token() }}',
        attachments: @json($attachmentsData),
        deletedAttachments: @json($deletedData),
        uploading: false,
        dragOver: false,
        showDeleted: false,
        successMessage: '',
        errorMessage: '',

        handleFileSelect: function(event) {
            var files = event.target.files;
            if (files.length > 0) {
                this.uploadFiles(files);
            }
            event.target.value = '';
        },

        handleDrop: function(event) {
            this.dragOver = false;
            var files = event.dataTransfer.files;
            if (files.length > 0) {
                this.uploadFiles(files);
            }
        },

        uploadFiles: function(files) {
            var self = this;
            self.uploading = true;
            self.successMessage = '';
            self.errorMessage = '';

            var formData = new FormData();
            for (var i = 0; i < files.length; i++) {
                if (files[i].size > 10 * 1024 * 1024) {
                    self.uploading = false;
                    self.errorMessage = files[i].name + ' は10MBを超えています。';
                    return;
                }
                formData.append('files[]', files[i]);
            }

            fetch(self.uploadUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': self.csrfToken,
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                self.uploading = false;
                if (data.success) {
                    for (var i = 0; i < data.attachments.length; i++) {
                        data.attachments[i].confirming = false;
                        self.attachments.push(data.attachments[i]);
                    }
                    self.successMessage = data.message;
                    setTimeout(function() { self.successMessage = ''; }, 3000);
                } else {
                    self.errorMessage = data.message || 'アップロードに失敗しました。';
                }
            })
            .catch(function(err) {
                self.uploading = false;
                self.errorMessage = 'アップロードに失敗しました。通信エラーが発生しました。';
            });
        },

        deleteFile: function(index) {
            var self = this;
            var file = self.attachments[index];
            self.errorMessage = '';

            fetch('{{ url("/attachments") }}/' + file.id, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': self.csrfToken,
                    'Accept': 'application/json'
                }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    self.attachments.splice(index, 1);
                    self.deletedAttachments.unshift(data.deleted);
                    self.successMessage = data.message;
                    setTimeout(function() { self.successMessage = ''; }, 3000);
                } else {
                    self.errorMessage = data.message || '削除に失敗しました。';
                    file.confirming = false;
                }
            })
            .catch(function(err) {
                self.errorMessage = '削除に失敗しました。通信エラーが発生しました。';
                file.confirming = false;
            });
        }
    };
}
</script>
