{{--
    添付ファイル アップロード パーシャル（フォーム送信用）
    新規登録・編集画面の <form> 内で使用する。

    @include('components.attachment-upload', [
        'isEdit'      => false,          // true: 編集画面、false: 新規登録
        'description' => '申込書・特約条件等', // 説明文のカスタマイズ
    ])
--}}

@php
    $isEdit = $isEdit ?? false;
    $desc = $description ?? 'ファイル';
@endphp

<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
    <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">
        {{ $isEdit ? '添付ファイル追加（任意）' : '添付ファイル（任意）' }}
    </div>
    <p class="text-xs text-gray-500 mb-3">
        @if($isEdit)
            新しいファイルを追加できます。既存ファイルの確認・削除は詳細画面の「添付ファイル」セクションで行ってください。
        @else
            {{ $desc }}を添付できます。登録後も詳細画面から追加可能です。
        @endif
    </p>

    <div x-data="attachmentUpload()">
        {{-- 実際にフォーム送信されるhidden file input --}}
        <input type="file" name="attachments[]" multiple x-ref="realInput" style="display:none;">

        {{-- ドラッグ&ドロップエリア --}}
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
                ファイルを{{ $isEdit ? '追加' : '選択' }}
                {{-- ユーザー操作用のトリガー input（nameなし = フォーム送信に含まれない） --}}
                <input type="file" multiple class="hidden" x-ref="triggerInput" @change="handleFileSelect($event)">
            </label>
            <p class="text-xs text-gray-400 mt-2">全形式対応・1ファイル10MBまで・複数選択可</p>
        </div>

        {{-- エラーメッセージ --}}
        <div x-show="errorMessage" x-cloak x-transition class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2">
            <span class="text-xs text-red-700 font-medium" x-text="errorMessage"></span>
        </div>

        {{-- 選択済みファイルプレビュー --}}
        <template x-if="files.length > 0">
            <div>
                <div class="space-y-2">
                    <template x-for="(f, index) in files" :key="index">
                        <div class="flex items-center justify-between px-3 py-2 rounded-md"
                             :class="f.error ? 'bg-red-50 border border-red-300' : 'bg-emerald-50 border border-emerald-200'">
                            <div class="flex items-center gap-2 min-w-0">
                                {{-- アイコン --}}
                                <svg x-show="f.error" class="w-4 h-4 text-red-500 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                <svg x-show="!f.error" class="w-4 h-4 text-gray-400 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                <span class="text-sm font-medium truncate" :class="f.error ? 'text-red-700' : 'text-gray-800'" x-text="f.name"></span>
                                <span class="text-xs whitespace-nowrap" :class="f.error ? 'text-red-500 font-semibold' : 'text-gray-500'" x-text="f.error ? f.size + ' — 10MBを超えています' : f.size"></span>
                            </div>
                            <button type="button" @click="removeFile(index)"
                                    class="flex-shrink-0 ml-2 transition-colors cursor-pointer"
                                    :class="f.error ? 'text-red-400 hover:text-red-600' : 'text-gray-400 hover:text-red-500'"
                                    title="取り消し">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                    </template>
                </div>
                <p class="text-xs text-gray-400 mt-2" x-show="validFileCount() > 0">
                    <span x-text="validFileCount()"></span>件選択中
                </p>
            </div>
        </template>
    </div>
</div>

<script>
function attachmentUpload() {
    return {
        files: [],
        dragOver: false,
        errorMessage: '',

        handleFileSelect: function(event) {
            this.addFiles(event.target.files);
            event.target.value = '';
        },

        handleDrop: function(event) {
            this.dragOver = false;
            this.addFiles(event.dataTransfer.files);
        },

        addFiles: function(newFiles) {
            var self = this;
            self.errorMessage = '';
            for (var i = 0; i < newFiles.length; i++) {
                var file = newFiles[i];
                var isOversize = file.size > 10 * 1024 * 1024;
                self.files.push({
                    file: file,
                    name: file.name,
                    size: self.formatSize(file.size),
                    error: isOversize
                });
            }
            self.syncRealInput();
        },

        removeFile: function(index) {
            this.files.splice(index, 1);
            this.syncRealInput();
            this.errorMessage = '';
        },

        syncRealInput: function() {
            var dt = new DataTransfer();
            for (var i = 0; i < this.files.length; i++) {
                if (!this.files[i].error) {
                    dt.items.add(this.files[i].file);
                }
            }
            this.$refs.realInput.files = dt.files;
        },

        validFileCount: function() {
            var count = 0;
            for (var i = 0; i < this.files.length; i++) {
                if (!this.files[i].error) count++;
            }
            return count;
        },

        formatSize: function(bytes) {
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
            if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return bytes + ' B';
        }
    };
}
</script>
