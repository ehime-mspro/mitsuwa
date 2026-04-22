@extends('layouts.app')

@section('title', '構造マスター')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>システム管理</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">構造マスター</span>
@endsection

@section('content')
<div x-data="structureTypeManager()">

    {{-- ページタイトル --}}
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-lg max-lg:text-base font-bold text-gray-900">構造マスター</h1>
        <button @click="startAdd()"
                x-show="!adding"
                class="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white rounded-md text-sm font-semibold hover:bg-emerald-700 transition-colors cursor-pointer">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            新規追加
        </button>
    </div>

    {{-- 成功メッセージ --}}
    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
            <p class="text-sm text-emerald-800">{{ session('success') }}</p>
        </div>
    @endif

    {{-- エラーメッセージ --}}
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
            <p class="text-sm text-red-800">{{ session('error') }}</p>
        </div>
    @endif

    {{-- バリデーションエラー --}}
    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
            @foreach($errors->all() as $error)
                <p class="text-sm text-red-800">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    {{-- 並替え成功メッセージ（Ajax用・右下フローティングトースト） --}}
    <div class="toast"
         x-show="reorderMessage"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         x-cloak>
        <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"/>
        </svg>
        <span x-text="reorderMessage"></span>
    </div>

    {{-- 説明 --}}
    <p class="text-xs text-gray-500 mb-2">
        <svg class="w-3.5 h-3.5 inline-block mr-0.5 -mt-0.5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        左側のハンドルをドラッグして表示順を変更できます。ドロップすると自動で保存されます。
    </p>

    {{-- テーブル --}}
    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <table class="w-full" style="table-layout: fixed;">
            <colgroup>
                <col style="width: 96px;">
                <col>
                <col style="width: 120px;">
            </colgroup>
            <thead>
                <tr class="bg-gray-50 border-b border-gray-200">
                    <th class="py-2.5 px-1"></th>
                    <th class="text-xs font-semibold text-gray-600 text-left py-2.5 px-3">構造名</th>
                    <th class="text-xs font-semibold text-gray-600 text-center py-2.5 px-3">操作</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(item, index) in items" :key="item.id">
                    <tr class="border-b border-gray-100 hover:bg-gray-50/50"
                        draggable="true"
                        :class="{ 'opacity-40': dragIndex === index }"
                        :style="dragOverIndex === index ? 'border-top: 2px solid #10b981;' : ''"
                        @dragstart="handleDragStart(index, $event)"
                        @dragover.prevent="handleDragOver(index, $event)"
                        @dragleave="handleDragLeave($event)"
                        @drop.prevent="handleDrop(index, $event)"
                        @dragend="handleDragEnd($event)">

                        {{-- ドラッグハンドル --}}
                        <td class="text-center py-2.5 px-1">
                            <div x-show="editingId !== item.id" class="drag-handle">
                                <svg style="width: 18px; height: 18px;" viewBox="0 0 24 24" fill="currentColor">
                                    <circle cx="9" cy="6" r="2"/><circle cx="15" cy="6" r="2"/>
                                    <circle cx="9" cy="12" r="2"/><circle cx="15" cy="12" r="2"/>
                                    <circle cx="9" cy="18" r="2"/><circle cx="15" cy="18" r="2"/>
                                </svg>
                            </div>
                        </td>

                        {{-- 構造名 --}}
                        <td class="text-sm text-gray-800 py-2.5 px-3">
                            {{-- 編集中 --}}
                            <template x-if="editingId === item.id">
                                <input type="text" x-model="editingName"
                                       class="w-full px-3 border border-emerald-400 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                                       style="height:36px;"
                                       @keydown.enter="submitEdit()"
                                       @keydown.escape="cancelEdit()">
                            </template>
                            {{-- 削除確認中 --}}
                            <template x-if="deletingId === item.id">
                                <span class="text-red-600 font-semibold" x-text="item.name"></span>
                            </template>
                            {{-- 通常表示 --}}
                            <template x-if="editingId !== item.id && deletingId !== item.id">
                                <span x-text="item.name"></span>
                            </template>
                        </td>

                        {{-- 操作 --}}
                        <td class="text-center py-2.5 px-3">
                            {{-- 編集中 --}}
                            <template x-if="editingId === item.id">
                                <div class="flex justify-center gap-1.5">
                                    <button @click="submitEdit()" class="px-2.5 py-1 bg-emerald-600 text-white text-xs font-semibold rounded hover:bg-emerald-700 transition-colors cursor-pointer">保存</button>
                                    <button @click="cancelEdit()" class="px-2.5 py-1 bg-gray-100 text-gray-600 text-xs font-semibold rounded hover:bg-gray-200 transition-colors cursor-pointer">取消</button>
                                </div>
                            </template>
                            {{-- 削除確認中 --}}
                            <template x-if="deletingId === item.id">
                                <div class="flex justify-center gap-1.5">
                                    <button @click="submitDelete()" class="px-2.5 py-1 bg-red-600 text-white text-xs font-semibold rounded hover:bg-red-700 transition-colors cursor-pointer">削除</button>
                                    <button @click="cancelDelete()" class="px-2.5 py-1 bg-gray-100 text-gray-600 text-xs font-semibold rounded hover:bg-gray-200 transition-colors cursor-pointer">取消</button>
                                </div>
                            </template>
                            {{-- 通常 --}}
                            <template x-if="editingId !== item.id && deletingId !== item.id">
                                <div class="flex justify-center gap-1.5">
                                    <button @click="startEdit(item.id, item.name)" class="px-2.5 py-1 bg-gray-100 text-gray-600 text-xs font-semibold rounded hover:bg-gray-200 transition-colors cursor-pointer">編集</button>
                                    <button @click="startDelete(item.id, item.name)" class="px-2.5 py-1 bg-gray-100 text-red-500 text-xs font-semibold rounded hover:bg-red-50 transition-colors cursor-pointer">削除</button>
                                </div>
                            </template>
                        </td>
                    </tr>
                </template>

                {{-- 新規追加行 --}}
                <tr x-show="adding" class="border-b border-gray-100 bg-emerald-50/30">
                    <td class="py-2.5 px-1"></td>
                    <td class="py-2.5 px-3">
                        <input type="text" x-model="newName" placeholder="構造名を入力"
                               class="w-full px-3 border border-emerald-400 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                               style="height:36px;"
                               x-ref="newNameInput"
                               @keydown.enter="submitAdd()"
                               @keydown.escape="cancelAdd()">
                    </td>
                    <td class="text-center py-2.5 px-3">
                        <div class="flex justify-center gap-1.5">
                            <button @click="submitAdd()" class="px-2.5 py-1 bg-emerald-600 text-white text-xs font-semibold rounded hover:bg-emerald-700 transition-colors cursor-pointer">追加</button>
                            <button @click="cancelAdd()" class="px-2.5 py-1 bg-gray-100 text-gray-600 text-xs font-semibold rounded hover:bg-gray-200 transition-colors cursor-pointer">取消</button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- 削除確認メッセージ --}}
    <div x-show="deletingId !== null" class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3">
        <p class="text-sm text-red-800">「<span x-text="deletingName"></span>」を削除しますか？ この操作は取り消せません。</p>
        <p class="text-xs text-red-600 mt-1">※ この構造を使用しているテナント物件がある場合、削除できません。</p>
    </div>

    {{-- フッター情報 --}}
    <div class="mt-4 text-xs text-gray-400">
        <p>全 <span x-text="items.length"></span> 件 — テナント物件登録・編集画面のセレクトボックスに上記の順で表示されます。</p>
    </div>

    {{-- 非表示フォーム: 新規追加 --}}
    <form x-ref="addForm" method="POST" action="{{ route('admin.master.structure-types.store') }}" class="hidden">
        @csrf
        <input type="hidden" name="name" x-bind:value="newName">
    </form>

    {{-- 非表示フォーム: 更新 --}}
    <form x-ref="editForm" method="POST" x-bind:action="editAction" class="hidden">
        @csrf
        @method('PUT')
        <input type="hidden" name="name" x-bind:value="editingName">
    </form>

    {{-- 非表示フォーム: 削除 --}}
    <form x-ref="deleteForm" method="POST" x-bind:action="deleteAction" class="hidden">
        @csrf
        @method('DELETE')
    </form>

</div>

<style>
.drag-handle { cursor: grab; color: #4b5563; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 6px; background: #f3f4f6; border: 1px solid #d1d5db; transition: all 0.15s; }
.drag-handle:hover { color: #047857; background: #ecfdf5; border-color: #6ee7b7; }
.drag-handle:active { cursor: grabbing; background: #d1fae5; }
.toast { position: fixed; bottom: 24px; right: 24px; background: #059669; color: white; padding: 12px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; box-shadow: 0 10px 25px rgba(0,0,0,0.18); display: inline-flex; align-items: center; gap: 8px; z-index: 9999; }
</style>

<script>
function structureTypeManager() {
    return {
        items: @json($structureTypesForJs),
        editingId: null,
        editingName: '',
        deletingId: null,
        deletingName: '',
        adding: false,
        newName: '',
        dragIndex: null,
        dragOverIndex: null,
        reorderMessage: '',
        baseUrl: '{{ url("/admin/master/structure-types") }}',

        // --- 編集 ---
        startEdit: function(id, name) {
            this.cancelDelete();
            this.cancelAdd();
            this.editingId = id;
            this.editingName = name;
        },
        cancelEdit: function() {
            this.editingId = null;
            this.editingName = '';
        },
        submitEdit: function() {
            if (!this.editingName.trim()) return;
            this.$refs.editForm.submit();
        },
        get editAction() {
            if (!this.editingId) return '';
            return this.baseUrl + '/' + this.editingId;
        },

        // --- 削除 ---
        startDelete: function(id, name) {
            this.cancelEdit();
            this.cancelAdd();
            this.deletingId = id;
            this.deletingName = name;
        },
        cancelDelete: function() {
            this.deletingId = null;
            this.deletingName = '';
        },
        submitDelete: function() {
            this.$refs.deleteForm.submit();
        },
        get deleteAction() {
            if (!this.deletingId) return '';
            return this.baseUrl + '/' + this.deletingId;
        },

        // --- 新規追加 ---
        startAdd: function() {
            this.cancelEdit();
            this.cancelDelete();
            this.adding = true;
            var self = this;
            setTimeout(function() {
                if (self.$refs.newNameInput) {
                    self.$refs.newNameInput.focus();
                }
            }, 50);
        },
        cancelAdd: function() {
            this.adding = false;
            this.newName = '';
        },
        submitAdd: function() {
            if (!this.newName.trim()) return;
            this.$refs.addForm.submit();
        },

        // --- ドラッグ&ドロップ ---
        handleDragStart: function(index, event) {
            if (this.editingId || this.deletingId || this.adding) {
                event.preventDefault();
                return;
            }
            this.dragIndex = index;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', index.toString());
        },
        handleDragOver: function(index, event) {
            if (this.dragIndex === null || this.dragIndex === index) {
                this.dragOverIndex = null;
                return;
            }
            this.dragOverIndex = index;
        },
        handleDragLeave: function(event) {
            this.dragOverIndex = null;
        },
        handleDrop: function(index, event) {
            if (this.dragIndex === null || this.dragIndex === index) {
                this.dragOverIndex = null;
                return;
            }
            var item = this.items.splice(this.dragIndex, 1)[0];
            this.items.splice(index, 0, item);
            this.dragIndex = null;
            this.dragOverIndex = null;
            this.saveOrder();
        },
        handleDragEnd: function(event) {
            this.dragIndex = null;
            this.dragOverIndex = null;
        },
        saveOrder: function() {
            var ids = [];
            for (var i = 0; i < this.items.length; i++) {
                ids.push(this.items[i].id);
            }
            var self = this;
            var token = document.querySelector('meta[name="csrf-token"]').content;
            fetch(self.baseUrl + '/reorder', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ ids: ids })
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    self.reorderMessage = '並び順を更新しました';
                    setTimeout(function() { self.reorderMessage = ''; }, 2500);
                }
            })
            .catch(function(error) {
                self.reorderMessage = '';
                alert('並替えの保存に失敗しました。ページをリロードしてください。');
            });
        }
    };
}
</script>
@endsection
