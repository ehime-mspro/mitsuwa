@extends('layouts.app')

@php
    $editing     = $approvalRequest->exists;
    $returned    = $editing && $approvalRequest->status === \App\Enums\ApprovalStatus::Returned;
    $pageTitle   = $returned ? '差戻しの申請を直す' : ($editing ? '下書きの編集' : '新しい申請');
    $submitLabel = $returned ? '出し直す' : '提出する';
    // 提出できなかった理由（submit）と、入力の形の誤りを分けて出す（計画 §0.11）
    $fieldErrors = collect($errors->getMessages())->except('submit')->flatten();
@endphp

@section('title', $pageTitle)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $pageTitle }}</span>
@endsection

@section('content')
<div class="max-w-[880px]" x-data="approvalRequestForm()">

    {{-- 成功・失敗の帯はレイアウトが出す。$errors だけ各ビューの責任 --}}
    @if($errors->has('submit'))
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">{{ $submitLabel }}ことができませんでした（入力した中身は保存してあります）。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->get('submit') as $reason)<li>{{ $reason }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if($fieldErrors->isNotEmpty())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります（まだ保存していません）。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($fieldErrors as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if($isPresident)
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-800">
            社長に指定されている人は申請できません（下書きは保存できますが、提出はできません）。
        </div>
    @endif

    @if($returnNote)
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
            <p class="text-[12px] font-semibold text-amber-800 mb-1">{{ $returnNote->kind->label() }}（{{ $returnNote->actor?->name }}）からの差戻し・{{ \App\Support\JapanTime::format($returnNote->acted_at) }}</p>
            <p class="text-[13px] text-amber-900 whitespace-pre-wrap break-words">{{ $returnNote->comment }}</p>
        </div>
    @endif

    <h1 class="text-lg font-bold text-gray-900 mb-1">{{ $pageTitle }}</h1>
    <p class="text-[12px] text-gray-500 mb-5">
        @if($returned)
            中身を直して「出し直す」を押してください。出し直すと部門長の確認から回り直します。
        @else
            途中でも「下書きを保存」で保存できます。添付は、下書きを 1 回保存したあとに追加できます。
        @endif
    </p>

    <form method="POST" action="{{ $editing ? route('approvals.requests.update', $approvalRequest) : route('approvals.requests.store') }}"
          class="bg-white rounded-lg border border-gray-200 px-5 py-5 space-y-5">
        @csrf
        @if($editing)
            @method('PUT')
            {{-- 描いたときの版（別の画面で先に保存されていたら、保存を断る。計画 §0.3）。入力の誤りで戻ったときは戻る前の版のまま --}}
            <input type="hidden" name="lock_version" value="{{ old('lock_version', $approvalRequest->lock_version) }}">
        @endif

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="type_id" class="block text-[12px] font-semibold text-gray-700 mb-1">申請の種類<span class="text-red-600 ml-0.5">*</span></label>
                {{-- ⚠ <option> は @@foreach で静的に出す（Bug #16）。選び直しは @@change で拾う（初期値は selected） --}}
                <select id="type_id" name="type_id" @change="typeChanged($event.target.value)"
                        class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                    <option value="">選んでください</option>
                    @foreach($types as $type)
                        <option value="{{ $type->id }}" @selected((string) old('type_id', $approvalRequest->type_id) === (string) $type->id)>{{ $type->name }}{{ $type->is_active ? '' : '（停止中）' }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="department_id" class="block text-[12px] font-semibold text-gray-700 mb-1">申請部門<span class="text-red-600 ml-0.5">*</span></label>
                <select id="department_id" name="department_id"
                        class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] bg-white cursor-pointer">
                    <option value="">選んでください</option>
                    @foreach($departments as $department)
                        <option value="{{ $department->id }}" @selected((string) old('department_id', $approvalRequest->department_id) === (string) $department->id)>{{ $department->company->name }}・{{ $department->name }}{{ in_array($department->id, $memberOf, true) ? '' : '（所属していません）' }}</option>
                    @endforeach
                </select>
                @if($memberOf === [])
                    <p class="text-[11px] text-red-600 mt-1">所属部門が未設定です。管理者に連絡してください。</p>
                @endif
            </div>
        </div>

        {{-- 種類を選び直したとき、本文を書き始めていれば入れ替えるか確かめる（設計書 §5.6） --}}
        <div x-show="pendingTypeId !== null" x-cloak class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-[13px] text-amber-800">
            <p class="mb-2">本文を、選んだ種類の見出しに入れ替えますか？（いま書いてある本文は消えます）</p>
            <div class="flex flex-wrap gap-2">
                <button type="button" @click="replaceBody()" class="px-3 py-1.5 text-[12px] font-semibold text-white bg-amber-600 rounded-md hover:bg-amber-700 cursor-pointer">入れ替える</button>
                <button type="button" @click="pendingTypeId = null" class="px-3 py-1.5 text-[12px] font-semibold text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50 cursor-pointer">本文はそのまま</button>
            </div>
        </div>

        <div>
            <label for="subject" class="block text-[12px] font-semibold text-gray-700 mb-1">件名<span class="text-red-600 ml-0.5">*</span></label>
            <input type="text" id="subject" name="subject" value="{{ old('subject', $approvalRequest->subject) }}" maxlength="100"
                   class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="amount" class="block text-[12px] font-semibold text-gray-700 mb-1">金額（円・税抜）</label>
                {{-- ⚠ value="0" の既定値を入れない（設計書 §5.6）。カンマ入りでも受け付ける --}}
                <input type="text" id="amount" name="amount" inputmode="numeric" placeholder="例: 28,500,000"
                       value="{{ old('amount', $approvalRequest->amount === null ? '' : number_format($approvalRequest->amount)) }}"
                       class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
            </div>
            <div>
                <label for="schedule" class="block text-[12px] font-semibold text-gray-700 mb-1">実施時期</label>
                <input type="text" id="schedule" name="schedule" value="{{ old('schedule', $approvalRequest->schedule) }}" maxlength="50" placeholder="例: 2026年10月"
                       class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
            </div>
        </div>

        <div>
            <label for="body" class="block text-[12px] font-semibold text-gray-700 mb-1">重点ポイント（5W2H）<span class="text-red-600 ml-0.5">*</span></label>
            <textarea id="body" name="body" x-ref="body" rows="16" maxlength="20000" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ old('body', $approvalRequest->body) }}</textarea>
            <p class="text-[11px] text-gray-400 mt-1">種類を選ぶと見出しが入ります。見出しのままでは提出できません。「いつ」「いくら」は実施時期・金額の欄に書きます。</p>
        </div>

        {{-- 関連する決裁No（10 個まで。候補は見られる申請の番号と件名。設計書 §5.6・D15） --}}
        <div>
            <label for="related-number" class="block text-[12px] font-semibold text-gray-700 mb-1">関連する決裁No（{{ \App\Support\Approval\RelatedNumbers::MAX }} 個まで）</label>
            <div class="flex flex-wrap gap-1.5 mb-2" x-show="numbers.length > 0">
                <template x-for="(number, index) in numbers" :key="number">
                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-gray-100 text-[12px] font-mono text-gray-800">
                        <span x-text="number"></span>
                        <input type="hidden" name="related_numbers[]" :value="number">
                        <button type="button" @click="removeNumber(index)" :title="number + ' を外す'" class="text-gray-400 hover:text-red-600 cursor-pointer">×</button>
                    </span>
                </template>
            </div>
            <div class="relative max-w-[420px]" @click.outside="suggestions = []">
                {{-- ⚠ Enter は IME の確定を除いて「追加」にする（Bug #6）。フォームの送信にしない --}}
                <input type="text" id="related-number" x-model="numberInput" autocomplete="off"
                       @input.debounce.300ms="searchNumbers()"
                       @keydown.enter.prevent="$event.isComposing || addNumber(numberInput)"
                       :disabled="numbers.length >= maxNumbers"
                       placeholder="例: R8-J-001（番号か件名の一部で候補が出ます）"
                       class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] disabled:bg-gray-100">
                <ul x-show="suggestions.length > 0" x-cloak class="absolute z-10 left-0 right-0 mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-[240px] overflow-y-auto">
                    <template x-for="item in suggestions" :key="item.number">
                        <li>
                            <button type="button" @click="addNumber(item.number)" class="w-full text-left px-3 py-2 text-[12px] hover:bg-emerald-50 cursor-pointer">
                                <span class="font-mono text-gray-900" x-text="item.number"></span>
                                <span class="text-gray-500 ml-1" x-text="item.subject"></span>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>
            <div class="flex flex-wrap items-center gap-2 mt-1.5">
                <button type="button" @click="addNumber(numberInput)" class="px-3 py-1.5 text-[12px] font-semibold text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 cursor-pointer">追加</button>
                <p class="text-[11px] text-gray-400">紙の時代の番号も入れられます（形だけ確かめます）</p>
            </div>
            <p x-show="errorMessage" x-cloak class="text-[12px] text-red-600 mt-1" x-text="errorMessage"></p>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-gray-100">
            <a href="{{ $editing ? route('approvals.requests.show', $approvalRequest) : route('approvals.home') }}" class="text-[13px] text-gray-500 hover:underline">やめる</a>
            <div class="flex flex-wrap gap-2">
                {{-- ⚠ 入力欄で Enter を押したときに送られるのは、この「保存」（先頭の submit ボタン）。提出にはならない --}}
                <button type="submit" name="intent" value="save" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-700 hover:bg-gray-50 cursor-pointer">{{ $returned ? '保存する' : '下書きを保存' }}</button>
                <button type="button" @click="confirmSubmit = true" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">{{ $submitLabel }}</button>
            </div>
        </div>

        {{-- 提出の確認（要件 4.1）。ここの submit が intent=submit を送る --}}
        <div x-show="confirmSubmit" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
            <div @click.outside="confirmSubmit = false" class="bg-white rounded-xl w-full max-w-[440px] shadow-xl mx-4 px-6 py-5">
                <p class="text-[15px] font-bold text-gray-900 mb-2">この内容で{{ $returned ? '出し直し' : '提出' }}しますか？</p>
                <p class="text-[12px] text-gray-500 mb-4">部門長（申請者が部門長なら省略）・審査・社長の順に回ります。{{ $returned ? '出し直した' : '提出した' }}あとは、差し戻されるまで中身を直せません。</p>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="confirmSubmit = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" name="intent" value="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">{{ $submitLabel }}</button>
                </div>
            </div>
        </div>
    </form>

    {{-- 添付（下書きを 1 回保存したあとに追加する。何枚かまとめて選べ、1 つずつ順に送る。D14・設計書 §5.7） --}}
    @if($editing)
        <section class="bg-white rounded-lg border border-gray-200 px-5 py-5 mt-5" x-data="approvalAttachments()">
            <h2 class="text-[14px] font-bold text-gray-900 mb-1">添付</h2>
            <p class="text-[11px] text-gray-400 mb-3">画像・PDF・Word・Excel・CSV・テキスト。1 ファイル 10MB まで、1 件の申請に {{ \App\Models\ApprovalAttachment::MAX_COUNT }} ファイルまで。何枚かまとめて選べます。</p>

            <div class="border-2 border-dashed rounded-lg p-4 text-center mb-3 transition-colors"
                 :class="dragOver ? 'border-emerald-400 bg-emerald-50' : 'border-gray-300 bg-gray-50'"
                 @dragover.prevent="dragOver = true" @dragleave.prevent="dragOver = false" @drop.prevent="drop($event)">
                {{-- ⚠ 選ぶ欄を hidden にしない（キーボードで届かなくなる。要件 14.4・Task 19 の B2）。見えないがフォーカスできる
                     sr-only にし、フォーカスしたら包むラベルに枠を出す --}}
                <label class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-700 hover:bg-gray-50 cursor-pointer focus-within:ring-2 focus-within:ring-emerald-500 focus-within:ring-offset-2">
                    ファイルを選ぶ
                    <input type="file" multiple class="sr-only" accept="{{ '.' . implode(',.', array_keys(\App\Models\ApprovalAttachment::TYPES)) }}"
                           @change="choose($event)" :disabled="uploading">
                </label>
                <p class="text-[11px] text-gray-400 mt-2">ここへドラッグしても追加できます</p>
            </div>

            <p x-show="progress" x-cloak class="text-[12px] text-emerald-700 mb-2" x-text="progress"></p>
            <p x-show="successMessage" x-cloak class="text-[12px] text-emerald-700 mb-2" x-text="successMessage"></p>
            <p x-show="errorMessage" x-cloak class="text-[12px] text-red-600 mb-2 whitespace-pre-line" x-text="errorMessage"></p>

            <ul x-show="files.length > 0" class="divide-y divide-gray-100 border border-gray-200 rounded-md">
                <template x-for="file in files" :key="file.id">
                    <li class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-2 text-[13px]">
                        <a :href="file.url" target="_blank" rel="noopener" class="text-emerald-600 hover:underline break-all" x-text="file.name"></a>
                        <span class="text-[11px] text-gray-400" x-text="file.size"></span>
                        <span class="ml-auto inline-flex items-center gap-3">
                            <button type="button" x-show="confirmingId !== file.id" @click="confirmingId = file.id" class="text-[12px] text-red-600 hover:underline cursor-pointer">外す</button>
                            <button type="button" x-show="confirmingId === file.id" @click="remove(file)" class="text-[12px] font-semibold text-red-600 hover:underline cursor-pointer">外す（確定）</button>
                            <button type="button" x-show="confirmingId === file.id" @click="confirmingId = null" class="text-[12px] text-gray-500 hover:underline cursor-pointer">やめる</button>
                        </span>
                    </li>
                </template>
            </ul>
            <p x-show="files.length === 0" x-cloak class="text-[12px] text-gray-400">添付はまだありません。</p>
        </section>
    @else
        <p class="mt-5 text-[12px] text-gray-500">下書きを 1 回保存すると、ここで添付を追加できます。</p>
    @endif

</div>
@endsection

@push('scripts')
<script>
{{-- ⚠ Js::from を使う（@@json は構造の " を素のまま出す。Bug #23）。x-data にアロー関数を書かない（Top trap #4） --}}
function approvalRequestForm() {
    return {
        headings: {{ \Illuminate\Support\Js::from($typeHeadings) }},
        numbers: {{ \Illuminate\Support\Js::from(array_values(old('related_numbers', $approvalRequest->related_numbers ?? []))) }},
        maxNumbers: {{ \App\Support\Approval\RelatedNumbers::MAX }},
        numberInput: '',
        suggestions: [],
        searchSeq: 0,
        errorMessage: '',
        pendingTypeId: null,
        confirmSubmit: false,

        // 本文が見出しのままか（App\Support\Approval\BodyTemplate::isBlank() と同じ判定）
        isBlankBody: function (text) {
            var lines = String(text || '').split(/\r\n|\r|\n/);
            for (var i = 0; i < lines.length; i++) {
                var line = lines[i].replace(/^[\s　]+|[\s　]+$/g, '');
                if (line === '' || line === '・' || line.charAt(0) === '■') {
                    continue;
                }
                return false;
            }
            return true;
        },

        // 種類を選んだ: 本文が見出しのままなら入れ替え、書き始めていれば確かめる
        typeChanged: function (typeId) {
            var headings = this.headings[typeId];
            this.pendingTypeId = null;
            if (!headings) {
                return;
            }
            if (this.isBlankBody(this.$refs.body.value)) {
                this.$refs.body.value = headings;
                return;
            }
            this.pendingTypeId = typeId;
        },

        replaceBody: function () {
            this.$refs.body.value = this.headings[this.pendingTypeId] || '';
            this.pendingTypeId = null;
        },

        // App\Support\Approval\RelatedNumbers::normalize() と同じそろえ方（最後はサーバーでもう一度そろえる）
        normalizeNumber: function (value) {
            var text = String(value || '').replace(/[！-～]/g, function (c) {
                return String.fromCharCode(c.charCodeAt(0) - 0xFEE0);
            });
            text = text.replace(/[ー―‐−–—\uFF70\u2011\uFE63]/g, '-');
            // 番号に空白は無いので、途中の空白も除く（JS の \s は全角の空白も含む）
            return text.replace(/\s+/g, '').toUpperCase();
        },

        addNumber: function (value) {
            var number = this.normalizeNumber(value);
            this.errorMessage = '';
            if (number === '') {
                return;
            }
            // App\Support\Approval\RelatedNumbers::PATTERN と同じ形（年は 1 から・連番は 3〜5 桁）
            if (!/^[RH][1-9][0-9]?-[A-Z]{1,3}-[0-9]{3,5}$/.test(number)) {
                this.errorMessage = '「' + number + '」は決裁No の形ではありません（例: R8-J-001）。';
                return;
            }
            if (this.numbers.indexOf(number) === -1) {
                if (this.numbers.length >= this.maxNumbers) {
                    this.errorMessage = '関連する決裁No は ' + this.maxNumbers + ' 個までです。';
                    return;
                }
                this.numbers.push(number);
            }
            this.numberInput = '';
            this.suggestions = [];
        },

        removeNumber: function (index) {
            this.numbers.splice(index, 1);
            this.errorMessage = '';
        },

        // 候補を探す。⚠ GET の fetch には X-Requested-With を付ける（Bug #35）
        searchNumbers: function () {
            var self = this;
            var query = self.numberInput.replace(/^[\s　]+|[\s　]+$/g, '');
            var seq = ++self.searchSeq;
            if (query === '') {
                self.suggestions = [];
                return;
            }

            fetch('{{ route('approvals.numbers.search') }}?q=' + encodeURIComponent(query), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) {
                if (!res.ok) {
                    self.errorMessage = '候補を読み込めませんでした（' + res.status + '）。番号はそのまま入れられます。';
                    return null;
                }
                return res.json();
            })
            .then(function (data) {
                // 古い検索の答えが後から届いたら捨てる
                if (!data || seq !== self.searchSeq) return;
                self.suggestions = data.items;
            })
            .catch(function () {
                self.errorMessage = '候補を読み込めませんでした。番号はそのまま入れられます。';
            });
        }
    };
}

@if($editing)
// 添付を 1 ファイルずつ順に送る（D14）。⚠ fetch の `.ok` の分岐と `!data` のガードは同じ数（AjaxErrorFeedbackTest）
function approvalAttachments() {
    return {
        files: {{ \Illuminate\Support\Js::from($attachmentList) }},
        storeUrl: '{{ route('approvals.requests.attachments.store', $approvalRequest) }}',
        csrfToken: '{{ csrf_token() }}',
        maxCount: {{ \App\Models\ApprovalAttachment::MAX_COUNT }},
        maxBytes: {{ \App\Models\ApprovalAttachment::MAX_KB }} * 1024,
        dragOver: false,
        uploading: false,
        progress: '',
        successMessage: '',
        errorMessage: '',
        confirmingId: null,
        busyMessage: '送っている途中です。終わってから、もう一度選んでください。',

        choose: function (event) {
            var picked = Array.prototype.slice.call(event.target.files || []);
            event.target.value = '';
            this.enqueue(picked);
        },

        drop: function (event) {
            this.dragOver = false;
            this.enqueue(Array.prototype.slice.call(event.dataTransfer.files || []));
        },

        // 送る前に、10MB を超えるものと 20 ファイルを超える分を断る（理由を出す）
        enqueue: function (picked) {
            var self = this;
            var accepted = [];
            var refused = [];
            var room = self.maxCount - self.files.length;
            if (picked.length === 0) {
                return;
            }
            // 送っている途中に落としたファイルは送らず、黙って捨てずに知らせる（選ぶ欄は送っている間は押せない。Task 19 の B3）
            if (self.uploading) {
                if (self.errorMessage.indexOf(self.busyMessage) === -1) {
                    self.errorMessage = self.appendLine(self.errorMessage, self.busyMessage);
                }
                return;
            }
            picked.forEach(function (file) {
                if (file.size > self.maxBytes) {
                    refused.push(file.name + '（10MB を超えています）');
                } else if (accepted.length >= room) {
                    refused.push(file.name + '（1 件の申請に ' + self.maxCount + ' ファイルまで）');
                } else {
                    accepted.push(file);
                }
            });
            self.successMessage = '';
            self.errorMessage = refused.length > 0 ? '次のファイルは送りませんでした: ' + refused.join('、') : '';
            self.uploadNext(accepted, 0, accepted.length);
        },

        uploadNext: function (queue, done, total) {
            var self = this;
            if (queue.length === 0) {
                self.uploading = false;
                self.progress = '';
                return;
            }
            var file = queue.shift();
            var body = new FormData();
            body.append('file', file);
            self.uploading = true;
            self.progress = (done + 1) + ' / ' + total + '：' + file.name + ' を送っています…';

            fetch(self.storeUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': self.csrfToken, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            })
            .then(function (res) {
                if (!res.ok) {
                    return res.json().then(function (err) {
                        self.errorMessage = self.appendLine(self.errorMessage, file.name + '：' + (err.message || '添付できませんでした。'));
                        return null;
                    }).catch(function () {
                        self.errorMessage = self.appendLine(self.errorMessage, file.name + '：添付できませんでした（' + res.status + '）。');
                        return null;
                    });
                }
                return res.json();
            })
            .then(function (data) {
                if (!data) {
                    self.uploadNext(queue, done + 1, total);
                    return;
                }
                self.files.push(data.attachment);
                self.successMessage = data.message;
                self.uploadNext(queue, done + 1, total);
            })
            .catch(function () {
                self.errorMessage = self.appendLine(self.errorMessage, file.name + '：通信に失敗しました。もう一度選んでください。');
                self.uploadNext(queue, done + 1, total);
            });
        },

        appendLine: function (text, line) {
            return text === '' ? line : text + '\n' + line;
        },

        remove: function (file) {
            var self = this;
            self.successMessage = '';
            self.errorMessage = '';

            fetch(file.delete_url, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': self.csrfToken, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) {
                if (!res.ok) {
                    return res.json().then(function (err) {
                        self.errorMessage = err.message || '外せませんでした。';
                        return null;
                    }).catch(function () {
                        self.errorMessage = '外せませんでした（' + res.status + '）。';
                        return null;
                    });
                }
                return res.json();
            })
            .then(function (data) {
                self.confirmingId = null;
                if (!data) return;
                self.files = self.files.filter(function (f) { return f.id !== file.id; });
                self.successMessage = data.message;
            })
            .catch(function () {
                self.confirmingId = null;
                self.errorMessage = '通信に失敗しました。もう一度お試しください。';
            });
        }
    };
}
@endif
</script>
@endpush
