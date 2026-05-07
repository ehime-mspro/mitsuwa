@extends('layouts.app')

@section('title', 'トレーナーマスタ')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.dashboard') }}" class="hover:text-emerald-600 transition-colors">ZEAL</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">トレーナーマスタ</span>
@endsection

@section('content')

<style>
    .zeal-badge {
        display: inline-flex; align-items: center;
        padding: 2px 10px; border-radius: 9999px;
        font-size: 11px; font-weight: 600; white-space: nowrap;
    }
    .badge-active   { background: #d1fae5; color: #065f46; }
    .badge-inactive { background: #f3f4f6; color: #6b7280; }
    /* form-input 標準デザイン（buyers/_form と同等の見た目に統一） */
    .form-input {
        height: 38px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        padding: 7px 12px;
        font-size: 14px;
    }
    /* textarea と checkbox は固定 height を解除 */
    textarea.form-input { height: auto; }
</style>

<div x-data="zealTrainerManager()">

    {{-- トースト通知 --}}
    <div x-show="message" x-cloak
         :style="messageType === 'success'
             ? 'background:#d1fae5; border:1px solid #6ee7b7; color:#065f46;'
             : 'background:#fee2e2; border:1px solid #fca5a5; color:#991b1b;'"
         style="display:flex; align-items:center; gap:8px; padding:12px 16px; margin-bottom:16px; border-radius:8px; font-size:14px;"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100">
        <svg x-show="messageType === 'success'" style="width:16px;height:16px;flex-shrink:0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <svg x-show="messageType === 'error'" style="width:16px;height:16px;flex-shrink:0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span x-text="message"></span>
    </div>

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">トレーナーマスタ</h1>
        @if(auth()->user()->role->isManagerOrAbove())
            <button type="button" x-show="!adding" @click="startAdd()"
                    class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                トレーナーを追加
            </button>
        @endif
    </div>

    {{-- ========== 新規追加フォーム ========== --}}
    @if(auth()->user()->role->isManagerOrAbove())
        <div x-show="adding" x-cloak style="margin-bottom: 20px;">
            <div class="bg-white border border-emerald-300 rounded-lg p-5">
                <div style="font-size: 14px; font-weight: 700; color: #065f46; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981;">
                    トレーナーを追加
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-bottom: 14px;">
                    <div>
                        <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:5px;">
                            氏名<span style="color:#dc2626; font-size:11px; margin-left:4px; font-weight:700;">*必須</span>
                        </label>
                        <input type="text" x-model="newName" placeholder="例: 山田 太郎"
                               maxlength="100"
                               @keydown.enter="$event.isComposing || submitAdd()"
                               @keydown.escape="cancelAdd()"
                               class="form-input w-full"
                               x-ref="newNameInput">
                    </div>
                    <div>
                        <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:5px;">
                            表示順
                        </label>
                        <input type="number" x-model.number="newOrder" min="0" max="9999"
                               inputmode="numeric"
                               class="form-input w-full">
                        <div style="font-size:11px; color:#9ca3af; margin-top:3px;">小さい値ほど先に表示</div>
                    </div>
                </div>
                <div style="margin-bottom: 14px;">
                    <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px; color:#374151;">
                        <input type="checkbox" x-model="newActive"
                               style="width:16px; height:16px; accent-color:#059669; cursor:pointer;">
                        有効（無効にするとプルダウンに表示されません）
                    </label>
                </div>
                <div style="display:flex; gap:8px; justify-content:flex-end;">
                    <button type="button" @click="cancelAdd()"
                            style="padding:8px 16px; border:1px solid #d1d5db; border-radius:6px; background:white; font-size:13px; font-weight:600; color:#374151; cursor:pointer;">
                        キャンセル
                    </button>
                    <button type="button" @click="submitAdd()" :disabled="saving"
                            style="display:inline-flex; align-items:center; gap:6px; padding:8px 20px; background:#059669; color:white; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer;">
                        <span x-show="!saving">追加する</span>
                        <span x-show="saving">追加中...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ========== トレーナー一覧テーブル ========== --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <table class="w-full border-collapse" style="min-width: 500px;">
            <thead>
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap" style="width: 40%;">氏名</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">表示順</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">状態</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="trainer in trainers" :key="trainer.id">
                    <tr class="hover:bg-gray-50 transition-colors border-b border-gray-200"
                        :class="trainer.active ? '' : 'opacity-60'">
                        {{-- 氏名（編集中: input、通常: text）--}}
                        <td class="px-4 py-3">
                            <input x-show="editingId === trainer.id"
                                   type="text" x-model="editingName"
                                   maxlength="100"
                                   @keydown.enter="$event.isComposing || submitEdit()"
                                   @keydown.escape="cancelEdit()"
                                   class="form-input w-full"
                                   style="margin-bottom:0;">
                            <span x-show="editingId !== trainer.id"
                                  class="text-sm font-semibold text-gray-900" x-text="trainer.name"></span>
                        </td>
                        {{-- 表示順（編集中: input、通常: text）--}}
                        <td class="px-4 py-3 text-center">
                            <input x-show="editingId === trainer.id"
                                   type="number" x-model.number="editingOrder"
                                   min="0" max="9999" inputmode="numeric"
                                   class="form-input text-center"
                                   style="width:80px; margin:0 auto;">
                            <span x-show="editingId !== trainer.id"
                                  class="text-sm text-gray-700" x-text="trainer.display_order"></span>
                        </td>
                        {{-- 状態（編集中: チェック、通常: バッジ）--}}
                        <td class="px-4 py-3 text-center">
                            <label x-show="editingId === trainer.id"
                                   style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; font-size:13px; color:#374151;">
                                <input type="checkbox" x-model="editingActive"
                                       style="width:14px; height:14px; accent-color:#059669; cursor:pointer;">
                                有効
                            </label>
                            <span x-show="editingId !== trainer.id"
                                  class="zeal-badge"
                                  :class="trainer.active ? 'badge-active' : 'badge-inactive'"
                                  x-text="trainer.active ? '有効' : '無効'"></span>
                        </td>
                        {{-- 操作 --}}
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            {{-- 編集中のボタン --}}
                            <div x-show="editingId === trainer.id" style="display:flex; gap:6px; justify-content:center;">
                                <button type="button" @click="submitEdit()" :disabled="saving"
                                        style="font-size:12px; font-weight:600; color:#065f46; padding:4px 12px; border:1px solid #6ee7b7; border-radius:4px; background:#d1fae5; cursor:pointer;">
                                    <span x-show="!saving">保存</span>
                                    <span x-show="saving">...</span>
                                </button>
                                <button type="button" @click="cancelEdit()"
                                        style="font-size:12px; font-weight:600; color:#6b7280; padding:4px 12px; border:1px solid #d1d5db; border-radius:4px; background:white; cursor:pointer;">
                                    取消
                                </button>
                            </div>
                            {{-- 通常のボタン --}}
                            <div x-show="editingId !== trainer.id" style="display:flex; gap:6px; justify-content:center;">
                                @if(auth()->user()->role->isManagerOrAbove())
                                    <button type="button" @click="startEdit(trainer)"
                                            style="font-size:12px; font-weight:600; color:#065f46; padding:4px 12px; border:1px solid #6ee7b7; border-radius:4px; background:#d1fae5; cursor:pointer;">
                                        編集
                                    </button>
                                @endif
                                @if(auth()->user()->role->isExecutive())
                                    <button type="button"
                                            @click="deleteTrainer(trainer)"
                                            style="font-size:12px; font-weight:600; color:#dc2626; padding:4px 12px; border:1px solid #fca5a5; border-radius:4px; background:#fee2e2; cursor:pointer;">
                                        削除
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                </template>

                {{-- 空のとき --}}
                <tr x-show="trainers.length === 0">
                    <td colspan="4" class="px-5 py-10 text-center text-sm text-gray-400">
                        トレーナーが登録されていません。
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div style="margin-top: 12px; font-size: 12px; color: #6b7280;">
        ※ 担当会員がいるトレーナーは削除できません。利用中止の場合は「無効」に変更してください。
    </div>

</div>

<script>
/**
 * ZEAL トレーナーマスタ管理
 * Alpine.js CLAUDE.md 規約: 関数名を指定した named function で定義（arrow function 禁止）
 */
function zealTrainerManager() {
    return {
        trainers: @json($trainersJson),
        adding: false,
        newName: '',
        newOrder: {{ $nextOrder }},
        newActive: true,
        editingId: null,
        editingName: '',
        editingOrder: 0,
        editingActive: true,
        saving: false,
        message: '',
        messageType: 'success',
        _messageTimer: null,

        /** 追加フォームを開く */
        startAdd: function () {
            this.cancelEdit();
            this.adding = true;
            this.$nextTick(function () {
                var el = this.$refs.newNameInput;
                if (el) { el.focus(); }
            }.bind(this));
        },

        /** 追加フォームをキャンセル */
        cancelAdd: function () {
            this.adding = false;
            this.newName = '';
        },

        /** トレーナーを追加（Ajax POST）*/
        submitAdd: function () {
            var self = this;
            var name = this.newName.trim();
            if (!name) {
                self.showMessage('氏名を入力してください。', 'error');
                return;
            }
            self.saving = true;
            var body = new URLSearchParams();
            body.append('_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
            body.append('name', name);
            body.append('display_order', self.newOrder);
            body.append('active', self.newActive ? '1' : '0');

            fetch('{{ route("zeal.trainers.store") }}', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: body,
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                self.saving = false;
                if (data.success) {
                    self.trainers.push(data.trainer);
                    self.trainers.sort(function (a, b) {
                        return a.display_order - b.display_order || a.id - b.id;
                    });
                    self.newName = '';
                    self.newOrder = Math.max.apply(null, self.trainers.map(function (t) { return t.display_order; })) + 1;
                    self.adding = false;
                    self.showMessage(data.message, 'success');
                } else {
                    self.showMessage(data.message || '追加に失敗しました。', 'error');
                }
            })
            .catch(function () {
                self.saving = false;
                self.showMessage('通信エラーが発生しました。', 'error');
            });
        },

        /** 編集モードを開始 */
        startEdit: function (trainer) {
            this.cancelAdd();
            this.editingId     = trainer.id;
            this.editingName   = trainer.name;
            this.editingOrder  = trainer.display_order;
            this.editingActive = trainer.active;
        },

        /** 編集をキャンセル */
        cancelEdit: function () {
            this.editingId = null;
        },

        /** トレーナーを更新（Ajax PUT）*/
        submitEdit: function () {
            var self = this;
            var name = this.editingName.trim();
            if (!name) {
                self.showMessage('氏名を入力してください。', 'error');
                return;
            }
            self.saving = true;
            var body = new URLSearchParams();
            body.append('_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
            body.append('_method', 'PUT');
            body.append('name', name);
            body.append('display_order', self.editingOrder);
            body.append('active', self.editingActive ? '1' : '0');

            fetch('/zeal/trainers/' + self.editingId, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: body,
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                self.saving = false;
                if (data.success) {
                    var idx = self.trainers.findIndex(function (t) { return t.id === data.trainer.id; });
                    if (idx !== -1) {
                        self.trainers.splice(idx, 1, data.trainer);
                        self.trainers.sort(function (a, b) {
                            return a.display_order - b.display_order || a.id - b.id;
                        });
                    }
                    self.editingId = null;
                    self.showMessage(data.message, 'success');
                } else {
                    self.showMessage(data.message || '更新に失敗しました。', 'error');
                }
            })
            .catch(function () {
                self.saving = false;
                self.showMessage('通信エラーが発生しました。', 'error');
            });
        },

        /** トレーナーを削除（Ajax DELETE）*/
        deleteTrainer: function (trainer) {
            var self = this;
            if (!confirm('「' + trainer.name + '」を削除しますか？\n担当会員がいる場合は削除できません。')) {
                return;
            }
            var body = new URLSearchParams();
            body.append('_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
            body.append('_method', 'DELETE');

            fetch('/zeal/trainers/' + trainer.id, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: body,
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    self.trainers = self.trainers.filter(function (t) { return t.id !== trainer.id; });
                    self.showMessage(data.message, 'success');
                } else {
                    self.showMessage(data.message || '削除に失敗しました。', 'error');
                }
            })
            .catch(function () {
                self.showMessage('通信エラーが発生しました。', 'error');
            });
        },

        /** トースト通知を表示（5秒後自動消去）*/
        showMessage: function (msg, type) {
            var self = this;
            self.message = msg;
            self.messageType = type || 'success';
            if (self._messageTimer) { clearTimeout(self._messageTimer); }
            self._messageTimer = setTimeout(function () { self.message = ''; }, 5000);
        },
    };
}
</script>

@endsection
