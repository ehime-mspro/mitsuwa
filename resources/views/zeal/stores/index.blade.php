@extends('layouts.app')

@section('title', '店舗マスタ')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.dashboard') }}" class="hover:text-emerald-600 transition-colors">ZEAL</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">店舗マスタ</span>
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
    /* 住所列の折り返し */
    .store-address-cell {
        white-space: normal;
        word-break: break-word;
        max-width: 300px;
    }
</style>

<div x-data="zealStoreManager()">

    {{-- トースト通知 --}}
    <div x-show="message" x-cloak
         :style="(messageType === 'success'
             ? 'background:#d1fae5; border:1px solid #6ee7b7; color:#065f46;'
             : 'background:#fee2e2; border:1px solid #fca5a5; color:#991b1b;')
             + 'display:flex; align-items:center; gap:8px; padding:12px 16px; margin-bottom:16px; border-radius:8px; font-size:14px;'"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100">
        <svg x-show="messageType === 'success'" style="width:16px;height:16px;flex-shrink:0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <svg x-show="messageType === 'error'" style="width:16px;height:16px;flex-shrink:0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span x-text="message"></span>
    </div>

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">店舗マスタ</h1>
        @if(auth()->user()->role->isManagerOrAbove())
            <button type="button" x-show="!adding" @click="startAdd()"
                    class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                店舗を追加
            </button>
        @endif
    </div>

    {{-- ========== 新規追加フォーム ========== --}}
    @if(auth()->user()->role->isManagerOrAbove())
        <div x-show="adding" x-cloak style="margin-bottom: 20px;">
            <div class="bg-white border border-emerald-300 rounded-lg p-5">
                <div style="font-size: 14px; font-weight: 700; color: #065f46; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981;">
                    店舗を追加
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-bottom: 14px;">
                    <div>
                        <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:5px;">
                            店舗名<span style="color:#dc2626; font-size:11px; margin-left:4px; font-weight:700;">*必須</span>
                        </label>
                        <input type="text" x-model="newName" placeholder="例: ZEAL BOXING FITNESS ◯◯店"
                               maxlength="100"
                               @keydown.enter="submitAdd()"
                               @keydown.escape="cancelAdd()"
                               class="form-input w-full"
                               x-ref="newNameInput">
                    </div>
                    <div>
                        <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:5px;">
                            電話
                        </label>
                        <input type="tel" x-model="newPhone" placeholder="例: 089-123-4567"
                               maxlength="20"
                               class="form-input w-full">
                    </div>
                </div>
                <div style="margin-bottom: 14px;">
                    <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:5px;">
                        住所
                    </label>
                    <input type="text" x-model="newAddress" placeholder="例: 愛媛県松山市湊町6-2-2"
                           maxlength="300"
                           class="form-input w-full">
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-bottom: 14px;">
                    <div>
                        <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:5px;">
                            開店日
                        </label>
                        <input type="date" x-model="newOpenDate"
                               class="form-input w-full">
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

    {{-- ========== 店舗一覧テーブル ========== --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        <table class="w-full border-collapse" style="min-width: 900px;">
            <thead>
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap" style="width: 22%;">店舗名</th>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">住所</th>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">電話</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">開店日</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">表示順</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">状態</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="store in stores" :key="store.id">
                    <tr class="hover:bg-gray-50 transition-colors border-b border-gray-200"
                        :class="store.active ? '' : 'opacity-60'">
                        {{-- 店舗名 --}}
                        <td class="px-4 py-3">
                            <input x-show="editingId === store.id"
                                   type="text" x-model="editingName"
                                   maxlength="100"
                                   @keydown.enter="submitEdit()"
                                   @keydown.escape="cancelEdit()"
                                   class="form-input w-full"
                                   style="margin-bottom:0;">
                            <span x-show="editingId !== store.id"
                                  class="text-sm font-semibold text-gray-900" x-text="store.name"></span>
                        </td>
                        {{-- 住所 --}}
                        <td class="px-4 py-3 store-address-cell">
                            <input x-show="editingId === store.id"
                                   type="text" x-model="editingAddress"
                                   maxlength="300"
                                   class="form-input w-full"
                                   style="margin-bottom:0;">
                            <span x-show="editingId !== store.id"
                                  class="text-sm text-gray-700" x-text="store.address || '-'"></span>
                        </td>
                        {{-- 電話 --}}
                        <td class="px-4 py-3 whitespace-nowrap">
                            <input x-show="editingId === store.id"
                                   type="tel" x-model="editingPhone"
                                   maxlength="20"
                                   class="form-input w-full"
                                   style="margin-bottom:0;">
                            <span x-show="editingId !== store.id"
                                  class="text-sm text-gray-700" x-text="store.phone || '-'"></span>
                        </td>
                        {{-- 開店日 --}}
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            <input x-show="editingId === store.id"
                                   type="date" x-model="editingOpenDate"
                                   class="form-input"
                                   style="width: 150px; margin: 0 auto;">
                            <span x-show="editingId !== store.id"
                                  class="text-sm text-gray-700" x-text="store.open_date || '-'"></span>
                        </td>
                        {{-- 表示順 --}}
                        <td class="px-4 py-3 text-center">
                            <input x-show="editingId === store.id"
                                   type="number" x-model.number="editingOrder"
                                   min="0" max="9999" inputmode="numeric"
                                   class="form-input text-center"
                                   style="width:80px; margin:0 auto;">
                            <span x-show="editingId !== store.id"
                                  class="text-sm text-gray-700" x-text="store.display_order"></span>
                        </td>
                        {{-- 状態 --}}
                        <td class="px-4 py-3 text-center">
                            <label x-show="editingId === store.id"
                                   style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; font-size:13px; color:#374151;">
                                <input type="checkbox" x-model="editingActive"
                                       style="width:14px; height:14px; accent-color:#059669; cursor:pointer;">
                                有効
                            </label>
                            <span x-show="editingId !== store.id"
                                  class="zeal-badge"
                                  :class="store.active ? 'badge-active' : 'badge-inactive'"
                                  x-text="store.active ? '有効' : '無効'"></span>
                        </td>
                        {{-- 操作 --}}
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            {{-- 編集中のボタン --}}
                            <div x-show="editingId === store.id" style="display:flex; gap:6px; justify-content:center;">
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
                            <div x-show="editingId !== store.id" style="display:flex; gap:6px; justify-content:center;">
                                @if(auth()->user()->role->isManagerOrAbove())
                                    <button type="button" @click="startEdit(store)"
                                            style="font-size:12px; font-weight:600; color:#065f46; padding:4px 12px; border:1px solid #6ee7b7; border-radius:4px; background:#d1fae5; cursor:pointer;">
                                        編集
                                    </button>
                                @endif
                                @if(auth()->user()->role->isExecutive())
                                    <button type="button"
                                            @click="deleteStore(store)"
                                            style="font-size:12px; font-weight:600; color:#dc2626; padding:4px 12px; border:1px solid #fca5a5; border-radius:4px; background:#fee2e2; cursor:pointer;">
                                        削除
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                </template>

                {{-- 空のとき --}}
                <tr x-show="stores.length === 0">
                    <td colspan="7" class="px-5 py-10 text-center text-sm text-gray-400">
                        店舗が登録されていません。
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div style="margin-top: 12px; font-size: 12px; color: #6b7280;">
        ※ 所属会員がいる店舗は削除できません。利用中止の場合は「無効」に変更してください。
    </div>

</div>

<script>
/**
 * ZEAL 店舗マスタ管理
 * Alpine.js CLAUDE.md 規約: 関数名を指定した named function で定義（arrow function 禁止）
 */
function zealStoreManager() {
    return {
        stores: @json($storesJson),
        adding: false,
        newName: '',
        newAddress: '',
        newPhone: '',
        newOpenDate: '',
        newOrder: {{ $nextOrder }},
        newActive: true,
        editingId: null,
        editingName: '',
        editingAddress: '',
        editingPhone: '',
        editingOpenDate: '',
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
            this.newAddress = '';
            this.newPhone = '';
            this.newOpenDate = '';
        },

        /** 店舗を追加（Ajax POST）*/
        submitAdd: function () {
            var self = this;
            var name = this.newName.trim();
            if (!name) {
                self.showMessage('店舗名を入力してください。', 'error');
                return;
            }
            self.saving = true;
            var body = new URLSearchParams();
            body.append('_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
            body.append('name', name);
            body.append('address', self.newAddress || '');
            body.append('phone', self.newPhone || '');
            body.append('open_date', self.newOpenDate || '');
            body.append('display_order', self.newOrder);
            body.append('active', self.newActive ? '1' : '0');

            fetch('{{ route("zeal.stores.store") }}', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: body,
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                self.saving = false;
                if (data.success) {
                    self.stores.push(data.store);
                    self.stores.sort(function (a, b) {
                        return a.display_order - b.display_order || a.id - b.id;
                    });
                    self.newName = '';
                    self.newAddress = '';
                    self.newPhone = '';
                    self.newOpenDate = '';
                    self.newOrder = Math.max.apply(null, self.stores.map(function (s) { return s.display_order; })) + 1;
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
        startEdit: function (store) {
            this.cancelAdd();
            this.editingId       = store.id;
            this.editingName     = store.name;
            this.editingAddress  = store.address || '';
            this.editingPhone    = store.phone || '';
            this.editingOpenDate = store.open_date || '';
            this.editingOrder    = store.display_order;
            this.editingActive   = store.active;
        },

        /** 編集をキャンセル */
        cancelEdit: function () {
            this.editingId = null;
        },

        /** 店舗を更新（Ajax PUT）*/
        submitEdit: function () {
            var self = this;
            var name = this.editingName.trim();
            if (!name) {
                self.showMessage('店舗名を入力してください。', 'error');
                return;
            }
            self.saving = true;
            var body = new URLSearchParams();
            body.append('_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
            body.append('_method', 'PUT');
            body.append('name', name);
            body.append('address', self.editingAddress || '');
            body.append('phone', self.editingPhone || '');
            body.append('open_date', self.editingOpenDate || '');
            body.append('display_order', self.editingOrder);
            body.append('active', self.editingActive ? '1' : '0');

            fetch('{{ url('/zeal/stores') }}/' + self.editingId, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: body,
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                self.saving = false;
                if (data.success) {
                    var idx = self.stores.findIndex(function (s) { return s.id === data.store.id; });
                    if (idx !== -1) {
                        self.stores.splice(idx, 1, data.store);
                        self.stores.sort(function (a, b) {
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

        /** 店舗を削除（Ajax DELETE）*/
        deleteStore: function (store) {
            var self = this;
            if (!confirm('「' + store.name + '」を削除しますか？\n所属会員がいる場合は削除できません。')) {
                return;
            }
            var body = new URLSearchParams();
            body.append('_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
            body.append('_method', 'DELETE');

            fetch('{{ url('/zeal/stores') }}/' + store.id, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: body,
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    self.stores = self.stores.filter(function (s) { return s.id !== store.id; });
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
