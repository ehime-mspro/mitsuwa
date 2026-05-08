{{--
    仕入れ先 picker partial
    親フォーム（_form.blade.php）から $p（仕入れ案件 or 分譲地モデル、null 可）を引き継ぐ前提。
    親フォームの x-data は `Object.assign(supplierPicker(), { ... })` で合成して使用する。
--}}

<div class="sm:col-span-2">
    <label class="block text-sm font-semibold text-gray-700 mb-1">仕入れ先</label>
    <input type="hidden" name="supplier_id" :value="supplierId">

    {{-- 検索ボックス + 「仕入先として登録」ボタン（未選択時） --}}
    <div x-show="!supplierId" style="max-width: 460px;">
        <div style="display: flex; gap: 8px;">
            <div class="relative" style="flex: 1;">
                <input type="text" x-model="supplierQuery" @input="searchSupplier()" @focus="searchSupplier()"
                       placeholder="仕入れ先を検索..."
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                <div x-show="supplierResults.length > 0"
                     @click.outside="supplierResults = []"
                     class="absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-md shadow-lg max-h-48 overflow-y-auto">
                    <template x-for="item in supplierResults" :key="item.id">
                        <div @click="selectSupplier(item)"
                             class="px-3 py-2 text-sm cursor-pointer hover:bg-emerald-50 border-b border-gray-100">
                            <span class="font-semibold text-gray-800" x-text="item.name"></span>
                            <span class="text-xs text-gray-500 ml-1" x-text="'(' + item.type_label + ')'"></span>
                        </div>
                    </template>
                </div>
            </div>
            <button type="button" @click="openQuickRegister()"
                    :disabled="!supplierQuery.trim()"
                    :style="(!supplierQuery.trim()) ? 'opacity: 0.4; cursor: not-allowed; height: 40px; padding: 0 14px; background: #fff; color: #059669; border: 1px solid #059669; border-radius: 6px; font-size: 13px; font-weight: 600; white-space: nowrap;' : 'height: 40px; padding: 0 14px; background: #fff; color: #059669; border: 1px solid #059669; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap;'">
                仕入先として登録
            </button>
        </div>
    </div>

    {{-- 選択済み表示 --}}
    <div x-show="supplierId" class="flex gap-2" style="max-width: 460px;">
        <div style="flex: 1; height: 40px; padding: 0 12px; display: flex; align-items: center; border: 2px solid #34d399; border-radius: 6px; background: #ecfdf5; font-size: 14px;">
            <span class="font-semibold text-emerald-700" x-text="supplierDisplay"></span>
        </div>
        <button type="button" @click="clearSupplier()" class="text-gray-400 hover:text-red-500 transition-colors" title="クリア">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>

    <p class="text-xs text-gray-500 mt-1">※ テキスト入力で候補を検索（Ajax）。新規業者は入力後「仕入先として登録」をクリック</p>

    {{-- 簡易登録モーダル --}}
    <div x-show="quickModalOpen" x-cloak
         @keydown.escape.window="closeQuickRegister()"
         style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 100; display: flex; align-items: center; justify-content: center; padding: 20px;">
        <div @click.outside="closeQuickRegister()"
             style="background: white; border-radius: 8px; padding: 24px; max-width: 480px; width: 100%; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
            <h3 style="font-size: 16px; font-weight: 700; color: #111827; margin: 0 0 16px 0; padding-bottom: 12px; border-bottom: 1px solid #e5e7eb;">仕入れ先登録</h3>

            {{-- エラー表示 --}}
            <div x-show="quickError" x-cloak x-text="quickError"
                 style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; font-size: 13px; padding: 8px 12px; border-radius: 6px; margin-bottom: 12px;"></div>

            {{-- 重複候補 --}}
            <div x-show="quickDuplicates.length > 0" x-cloak
                 style="background: #fffbeb; border: 1px solid #fde68a; padding: 12px; border-radius: 6px; margin-bottom: 12px;">
                <div style="font-size: 13px; font-weight: 600; color: #92400e; margin-bottom: 8px;">同名の仕入先が既に存在します:</div>
                <template x-for="dup in quickDuplicates" :key="dup.id">
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 6px 0;">
                        <div>
                            <span style="font-weight: 600; color: #111827; font-size: 13px;" x-text="dup.name"></span>
                            <span style="font-size: 11px; color: #6b7280; margin-left: 4px;" x-text="'(' + dup.type_label + ')'"></span>
                        </div>
                        <button type="button" @click="selectDuplicate(dup)"
                                style="background: #fff; color: #059669; border: 1px solid #059669; padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer;">
                            これを選択
                        </button>
                    </div>
                </template>
                <div style="font-size: 12px; color: #6b7280; margin-top: 8px;">別の仕入先として登録したい場合は、上の「名前」を編集してから「登録して選択」をクリックしてください（例: 「田中工務店(松山)」）</div>
            </div>

            {{-- 名前 --}}
            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 4px;">名前 <span style="color: #dc2626;">*</span></label>
                <input type="text" x-model="quickName"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>

            {{-- 区分 --}}
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">区分 <span style="color: #dc2626;">*</span></label>
                <div style="display: flex; gap: 16px;">
                    <label style="display: flex; align-items: center; cursor: pointer; gap: 4px;">
                        <input type="radio" x-model="quickType" value="individual">
                        <span style="font-size: 13px;">個人</span>
                    </label>
                    <label style="display: flex; align-items: center; cursor: pointer; gap: 4px;">
                        <input type="radio" x-model="quickType" value="corporation">
                        <span style="font-size: 13px;">法人</span>
                    </label>
                    <label style="display: flex; align-items: center; cursor: pointer; gap: 4px;">
                        <input type="radio" x-model="quickType" value="realtor">
                        <span style="font-size: 13px;">業者</span>
                    </label>
                </div>
            </div>

            {{-- ボタン --}}
            <div style="display: flex; gap: 8px; justify-content: flex-end; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                <button type="button" @click="closeQuickRegister()"
                        style="padding: 8px 16px; background: #fff; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                    キャンセル
                </button>
                <button type="button" @click="submitQuickRegister()"
                        :disabled="quickSubmitting || !quickName.trim() || !quickType"
                        :style="(quickSubmitting || !quickName.trim() || !quickType) ? 'opacity: 0.5; cursor: not-allowed; padding: 8px 16px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 600;' : 'padding: 8px 16px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;'">
                    <span x-show="!quickSubmitting">登録して選択</span>
                    <span x-show="quickSubmitting">登録中...</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function supplierPicker() {
    return {
        supplierId: {{ old('supplier_id', $p?->supplier_id) ?: 'null' }},
        supplierDisplay: '{{ $p && $p->supplier ? $p->supplier->name : "" }}',
        supplierQuery: '',
        supplierResults: [],
        searchTimer: null,

        // 簡易登録モーダル
        quickModalOpen: false,
        quickName: '',
        quickType: '',
        quickSubmitting: false,
        quickError: '',
        quickDuplicates: [],

        searchSupplier: function() {
            var self = this;
            clearTimeout(self.searchTimer);
            if (self.supplierQuery.length < 2) {
                self.supplierResults = [];
                return;
            }
            self.searchTimer = setTimeout(function() {
                fetch('{{ url("/api/realestate/suppliers/search") }}?q=' + encodeURIComponent(self.supplierQuery), {
                    headers: { 'Accept': 'application/json' }
                })
                .then(function(res) { return res.json(); })
                .then(function(data) { self.supplierResults = data; })
                .catch(function() { self.supplierResults = []; });
            }, 300);
        },

        selectSupplier: function(item) {
            this.supplierId = item.id;
            this.supplierDisplay = item.name;
            this.supplierQuery = '';
            this.supplierResults = [];
        },

        clearSupplier: function() {
            this.supplierId = null;
            this.supplierDisplay = '';
            this.supplierQuery = '';
        },

        openQuickRegister: function() {
            if (!this.supplierQuery.trim()) {
                return;
            }
            this.quickName = this.supplierQuery.trim();
            this.quickType = '';
            this.quickError = '';
            this.quickDuplicates = [];
            this.quickModalOpen = true;
        },

        closeQuickRegister: function() {
            this.quickModalOpen = false;
            this.quickError = '';
            this.quickDuplicates = [];
        },

        selectDuplicate: function(dup) {
            this.selectSupplier(dup);
            this.closeQuickRegister();
        },

        submitQuickRegister: function() {
            var self = this;
            if (self.quickSubmitting) return;
            self.quickSubmitting = true;
            self.quickError = '';
            // 重複候補表示中に再送信した場合はリセット（名前を編集して再登録するケース）
            self.quickDuplicates = [];

            var meta = document.querySelector('meta[name="csrf-token"]');
            var token = meta ? meta.getAttribute('content') : '';

            fetch('{{ url("/api/realestate/suppliers/quick") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    name: self.quickName.trim(),
                    type: self.quickType
                })
            })
            .then(function(res) {
                if (res.status === 419) {
                    self.quickError = 'セッションが切れました。ページを再読込してください。';
                    return null;
                }
                return res.json().then(function(data) {
                    return { status: res.status, data: data };
                }).catch(function() {
                    return { status: res.status, data: {} };
                });
            })
            .then(function(result) {
                self.quickSubmitting = false;
                if (!result) return;
                if (result.status === 201) {
                    self.selectSupplier(result.data);
                    self.closeQuickRegister();
                } else if (result.status === 200 && result.data.duplicates) {
                    self.quickDuplicates = result.data.duplicates;
                } else if (result.status === 422) {
                    var firstError = '';
                    if (result.data.errors) {
                        var keys = Object.keys(result.data.errors);
                        if (keys.length > 0) {
                            firstError = result.data.errors[keys[0]][0];
                        }
                    }
                    self.quickError = firstError || result.data.message || '入力内容に誤りがあります。';
                } else {
                    self.quickError = '登録に失敗しました（' + result.status + '）';
                }
            })
            .catch(function() {
                self.quickSubmitting = false;
                self.quickError = '通信エラーが発生しました。';
            });
        }
    };
}
</script>
