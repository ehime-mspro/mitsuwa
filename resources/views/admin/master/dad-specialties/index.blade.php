@extends('layouts.app')

@section('title', '専門分野マスター')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>システム管理</span>
    <span class="mx-1.5">›</span>
    <span>DAD</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">専門分野マスター</span>
@endsection

@section('content')
<div x-data="dadSpecialtyManager()">

    {{-- ページタイトル --}}
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-lg max-lg:text-base font-bold text-gray-900">専門分野マスター</h1>
        <a href="{{ route('admin.master.dad-specialties.create') }}"
           class="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white rounded-md text-sm font-semibold hover:bg-emerald-700 transition-colors cursor-pointer">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            専門分野を登録
        </a>
    </div>



    {{-- 説明バナー --}}
    <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
        <p class="text-xs text-emerald-800">
            ここで登録した専門分野は、協力業者の登録・編集画面でプルダウン選択肢として使用されます。色設定は協力業者一覧のバッジ表示に反映されます。
        </p>
        <p class="text-xs text-emerald-700 mt-1">
            行頭のハンドルをドラッグすると並び順を変更できます（変更は自動保存されます）。
        </p>
    </div>

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

    {{-- テーブル --}}
    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <table class="w-full" style="table-layout: fixed;">
            <colgroup>
                <col style="width: 56px;">
                <col style="width: 22%;">
                <col style="width: 22%;">
                <col style="width: 18%;">
                <col style="width: 18%;">
                <col style="width: 120px;">
            </colgroup>
            <thead>
                <tr class="bg-gray-50 border-b border-gray-200">
                    <th class="py-2.5 px-1"></th>
                    <th class="text-xs font-semibold text-gray-600 text-left py-2.5 px-3">専門分野名</th>
                    <th class="text-xs font-semibold text-gray-600 text-left py-2.5 px-3">バッジプレビュー</th>
                    <th class="text-xs font-semibold text-gray-600 text-left py-2.5 px-3">背景色</th>
                    <th class="text-xs font-semibold text-gray-600 text-left py-2.5 px-3">文字色</th>
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

                        <td class="text-center py-2.5 px-1">
                            <div class="drag-handle">
                                <svg style="width: 18px; height: 18px;" viewBox="0 0 24 24" fill="currentColor">
                                    <circle cx="9" cy="6" r="2"/><circle cx="15" cy="6" r="2"/>
                                    <circle cx="9" cy="12" r="2"/><circle cx="15" cy="12" r="2"/>
                                    <circle cx="9" cy="18" r="2"/><circle cx="15" cy="18" r="2"/>
                                </svg>
                            </div>
                        </td>

                        <td class="text-sm font-semibold text-gray-900 py-2.5 px-3" x-text="item.name"></td>

                        <td class="py-2.5 px-3">
                            <span class="badge"
                                  :style="'background: ' + item.color_bg + '; color: ' + item.color_text + ';'"
                                  x-text="item.name"></span>
                        </td>

                        <td class="py-2.5 px-3 text-xs text-gray-700">
                            <span class="swatch" :style="'background: ' + item.color_bg + ';'"></span>
                            <span class="hex" x-text="item.color_bg"></span>
                        </td>

                        <td class="py-2.5 px-3 text-xs text-gray-700">
                            <span class="swatch" :style="'background: ' + item.color_text + ';'"></span>
                            <span class="hex" x-text="item.color_text"></span>
                        </td>

                        <td class="text-center py-2.5 px-3">
                            <a :href="'{{ url('/admin/master/dad-specialties') }}/' + item.id + '/edit'"
                               class="inline-block px-2.5 py-1 bg-gray-100 text-gray-600 text-xs font-semibold rounded hover:bg-gray-200 transition-colors">編集</a>
                        </td>
                    </tr>
                </template>

                {{-- データなし --}}
                @if($specialties->isEmpty())
                <tr>
                    <td colspan="6" class="text-center text-sm text-gray-500 py-6">
                        登録されている専門分野がありません。
                    </td>
                </tr>
                @endif
            </tbody>
        </table>
    </div>

    {{-- フッター情報 --}}
    <div class="mt-4 text-xs text-gray-400">
        <p>全 <span x-text="items.length"></span> 件 — 協力業者の登録・編集画面のセレクトボックスに上記の順で表示されます。</p>
    </div>

</div>

<style>
.drag-handle { cursor: grab; color: #4b5563; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 6px; background: #f3f4f6; border: 1px solid #d1d5db; transition: all 0.15s; }
.drag-handle:hover { color: #047857; background: #ecfdf5; border-color: #6ee7b7; }
.drag-handle:active { cursor: grabbing; background: #d1fae5; }
.toast { position: fixed; bottom: 24px; right: 24px; background: #059669; color: white; padding: 12px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; box-shadow: 0 10px 25px rgba(0,0,0,0.18); display: inline-flex; align-items: center; gap: 8px; z-index: 9999; }
.badge { display: inline-flex; align-items: center; padding: 3px 12px; border-radius: 9999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
.swatch { display: inline-block; width: 16px; height: 16px; border-radius: 4px; border: 1px solid #d1d5db; vertical-align: middle; margin-right: 8px; }
.hex { font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace; font-size: 11px; color: #4b5563; }
</style>

<script>
function dadSpecialtyManager() {
    return {
        items: @json($specialtiesForJs),
        dragIndex: null,
        dragOverIndex: null,
        reorderMessage: '',

        handleDragStart: function(index, event) {
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
            fetch('{{ route('admin.master.dad-specialties.reorder') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ ids: ids })
            })
            .then(function(response) {
                if (!response.ok) {
                    return response.json().then(function(err) {
                        var msg = err.message || '並替えの保存に失敗しました。';
                        if (err.errors) {
                            msg = msg + '\n' + Object.values(err.errors).flat().join('\n');
                        }
                        self.reorderMessage = '';
                        alert(msg);
                        return null;
                    }).catch(function() {
                        self.reorderMessage = '';
                        alert('並替えの保存に失敗しました（' + response.status + '）');
                        return null;
                    });
                }
                return response.json();
            })
            .then(function(data) {
                if (!data) return;
                if (data.success) {
                    self.reorderMessage = '並び順を更新しました';
                    setTimeout(function() { self.reorderMessage = ''; }, 2500);
                } else {
                    self.reorderMessage = '';
                    alert(data.message || '並替えの保存に失敗しました。');
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
