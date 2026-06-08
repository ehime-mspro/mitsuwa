{{-- 買主マスタ紐付け 共通パーシャル（フェーズ2）
     注文住宅・建売の create/edit 4フォームで共通利用。
     引数:
       $buyers       … Buyer コレクション（住宅事業所属 + 編集時は現 buyer を withTrashed で含む）
       $selectedId   … 現在の customer_id（呼び出し側で old() 反映済みを渡す。null 可）
       $selectedName … 現在の customer_name（old() 反映済み。既定 ''）
       $department   … 既定 'housing'
     設計上の注意:
       - <option> は静的 @foreach（Bug #16: x-for で option を作らない）
       - スタイルは全て inline（Bug #7/#19: Vite未収録Tailwind回避・edit/create両CSS系で一貫表示）
       - モーダル入力欄は name 無し → メインフォームに送信されない。Enter は IME ガード付きで握りつぶす（Bug #6）
       - x-data は名前付き関数 buyerSelect()（Top trap #4） --}}
@php
    $bsSelectedId   = $selectedId ?? null;
    $bsSelectedName = $selectedName ?? '';
    $bsDepartment   = $department ?? 'housing';
@endphp

<style>[x-cloak]{display:none !important;}</style>

<div x-data="buyerSelect(@js($bsSelectedName))">
    <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:4px;">
        買主マスタ紐付け<span style="color:#dc2626; margin-left:2px;">*</span>
    </label>

    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <select name="customer_id" x-ref="sel" @change="onSelect()" required
                style="flex:1; min-width:220px; height:40px; padding:0 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; color:#1f2937; background:#fff; cursor:pointer;">
            <option value="">— 買主を選択してください —</option>
            @foreach($buyers as $buyer)
                <option value="{{ $buyer->id }}"
                        data-name="{{ $buyer->full_name }}"
                        @selected((string) old('customer_id', $bsSelectedId) === (string) $buyer->id)>
                    {{ $buyer->full_name }}@if($buyer->trashed()) （削除済み）@endif
                </option>
            @endforeach
        </select>

        <button type="button" @click="openModal()"
                style="display:inline-flex; align-items:center; gap:4px; height:40px; padding:0 14px; font-size:13px; font-weight:600; color:#fff; background:#059669; border:none; border-radius:6px; cursor:pointer; white-space:nowrap;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            新規顧客を登録
        </button>
    </div>
    @error('customer_id') <p style="font-size:12px; color:#dc2626; margin-top:4px;">{{ $message }}</p> @enderror

    {{-- 顧客名（買主選択で自動補完・読み取り専用。サーバー側でも buyer 名で上書き） --}}
    <div style="margin-top:10px;">
        <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:4px;">顧客名（自動）</label>
        <input type="text" name="customer_name" x-model="customerName" readonly
               style="width:100%; max-width:360px; height:40px; padding:0 12px; border:1px solid #e5e7eb; border-radius:6px; font-size:14px; color:#6b7280; background:#f9fafb; box-sizing:border-box;">
        @error('customer_name') <p style="font-size:12px; color:#dc2626; margin-top:4px;">{{ $message }}</p> @enderror
    </div>

    {{-- ＋新規顧客 モーダル（入力欄は name 無し = メインフォーム非送信） --}}
    <div x-show="modalOpen" x-cloak
         style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:1000; display:flex; align-items:flex-start; justify-content:center; padding:40px 16px; overflow-y:auto;"
         @click.self="closeModal()">
        <div style="background:#fff; border-radius:10px; width:100%; max-width:560px; box-shadow:0 20px 50px rgba(0,0,0,0.25);">
            <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #e5e7eb;">
                <h3 style="font-size:15px; font-weight:700; color:#111827; margin:0;">新規顧客を登録</h3>
                <button type="button" @click="closeModal()" style="border:none; background:none; font-size:22px; color:#9ca3af; cursor:pointer; line-height:1;">&times;</button>
            </div>

            <div style="padding:18px 20px;">
                {{-- 重複サジェスト（非ブロッキング） --}}
                <div x-show="duplicates.length > 0" x-cloak
                     style="margin-bottom:14px; padding:10px 12px; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; font-size:12px; color:#92400e;">
                    <p style="font-weight:700; margin:0 0 4px;">同名の買主が既に登録されています:</p>
                    <ul style="margin:0; padding-left:16px;">
                        <template x-for="d in duplicates" :key="d.id">
                            <li x-text="d.full_name + (d.same_dept ? '（住宅事業に登録済み）' : '（他部署）')"></li>
                        </template>
                    </ul>
                    <p style="margin:6px 0 0;">別人であればこのまま登録してください。</p>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">姓<span style="color:#dc2626;">*</span></label>
                        <input type="text" x-model="f.last_name" @blur="checkDup()"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">名<span style="color:#dc2626;">*</span></label>
                        <input type="text" x-model="f.first_name" @blur="checkDup()"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">姓カナ</label>
                        <input type="text" x-model="f.last_name_kana"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">名カナ</label>
                        <input type="text" x-model="f.first_name_kana"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">取得日<span style="color:#dc2626;">*</span></label>
                        <input type="date" x-model="f.acquired_date"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">電話</label>
                        <input type="text" inputmode="numeric" x-model="f.phone"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">郵便番号</label>
                        <input type="text" inputmode="numeric" x-model="f.postal_code"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">都道府県</label>
                        <input type="text" x-model="f.prefecture"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div style="grid-column:span 2;">
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">市区町村</label>
                        <input type="text" x-model="f.city"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                    <div style="grid-column:span 2;">
                        <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:3px;">住所詳細</label>
                        <input type="text" x-model="f.address_detail"
                               @keydown.enter.prevent="$event.isComposing || submitModal()"
                               style="width:100%; height:38px; padding:0 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; box-sizing:border-box;">
                    </div>
                </div>

                <p x-show="error" x-cloak x-text="error" style="margin-top:12px; font-size:12px; color:#dc2626;"></p>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; padding:14px 20px; border-top:1px solid #e5e7eb;">
                <button type="button" @click="closeModal()"
                        style="height:38px; padding:0 16px; font-size:13px; font-weight:600; color:#374151; background:#fff; border:1px solid #d1d5db; border-radius:6px; cursor:pointer;">キャンセル</button>
                <button type="button" @click="submitModal()" :disabled="submitting"
                        :style="submitting ? 'opacity:0.6; cursor:not-allowed; height:38px; padding:0 18px; font-size:13px; font-weight:700; color:#fff; background:#059669; border:none; border-radius:6px;' : 'height:38px; padding:0 18px; font-size:13px; font-weight:700; color:#fff; background:#059669; border:none; border-radius:6px; cursor:pointer;'">
                    <span x-text="submitting ? '登録中…' : '登録して選択'"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function buyerSelect(initialName) {
    return {
        customerName: initialName || '',
        modalOpen: false,
        submitting: false,
        error: '',
        duplicates: [],
        f: {
            last_name: '', first_name: '', last_name_kana: '', first_name_kana: '',
            acquired_date: '{{ now()->format('Y-m-d') }}',
            postal_code: '', prefecture: '', city: '', address_detail: '', phone: ''
        },

        csrf: function() {
            return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        },

        onSelect: function() {
            var sel = this.$refs.sel;
            var opt = sel.options[sel.selectedIndex];
            this.customerName = (opt && opt.dataset.name) ? opt.dataset.name : '';
        },

        openModal: function() {
            this.error = '';
            this.duplicates = [];
            this.modalOpen = true;
        },

        closeModal: function() {
            this.modalOpen = false;
        },

        checkDup: function() {
            var self = this;
            if (!self.f.last_name || !self.f.first_name) { return; }
            fetch('{{ route('api.customers.check-duplicate') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': self.csrf() },
                body: JSON.stringify({
                    last_name: self.f.last_name,
                    first_name: self.f.first_name,
                    prefecture: self.f.prefecture,
                    city: self.f.city,
                    department: '{{ $bsDepartment }}'
                })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) { self.duplicates = data.duplicates || []; })
            .catch(function() { self.duplicates = []; });
        },

        submitModal: function() {
            var self = this;
            if (self.submitting) { return; }
            if (!self.f.last_name || !self.f.first_name || !self.f.acquired_date) {
                self.error = '姓・名・取得日は必須です。';
                return;
            }
            self.submitting = true;
            self.error = '';
            fetch('{{ route('housing.customers.quick-store') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': self.csrf() },
                body: JSON.stringify(self.f)
            })
            .then(function(res) {
                if (!res.ok) { throw new Error('登録に失敗しました（' + res.status + '）'); }
                return res.json();
            })
            .then(function(data) {
                var sel = self.$refs.sel;
                var opt = document.createElement('option');
                opt.value = data.id;
                opt.textContent = data.full_name;
                opt.dataset.name = data.full_name;
                sel.appendChild(opt);
                sel.value = String(data.id);
                self.customerName = data.full_name;
                self.submitting = false;
                self.modalOpen = false;
            })
            .catch(function(err) {
                self.submitting = false;
                self.error = err.message || '登録に失敗しました。';
            });
        }
    };
}
</script>
