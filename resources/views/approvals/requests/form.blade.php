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

    {{-- 二度押し止め（Task 19 の C2。手本は基幹の顧客取込の確定〈Bug #67〉）: 1 回目は通して印を立て、2 回目からは送信を取り消す。
         送っている間はボタンを押せなくして「送っています…」を出し、「戻る」で画面がそのまま戻ったとき（bfcache）は pageshow で印を下ろす。
         ⚠ 保存か提出かは hidden の intent で送る（ボタンに name・value を持たせない。送る前にボタンを押せなくすると、そのボタンの値は送られない） --}}
    <form method="POST" action="{{ $editing ? route('approvals.requests.update', $approvalRequest) : route('approvals.requests.store') }}"
          x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()"
          class="bg-white rounded-lg border border-gray-200 px-5 py-5 space-y-5">
        @csrf
        {{-- 保存か提出か。押したボタンが setIntent で書く（入力欄で Enter を押したときは、先頭の submit ボタン＝保存が押される） --}}
        <input type="hidden" name="intent" value="save" x-ref="intent">
        @if($editing)
            @method('PUT')
            {{-- 描いたときの版（別の画面で先に保存されていたら、保存を断る。計画 §0.3）。入力の誤りで戻ったときは戻る前の版のまま --}}
            <input type="hidden" name="lock_version" value="{{ old('lock_version', $approvalRequest->lock_version) }}">
        @endif

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="type_id" class="block text-[12px] font-semibold text-gray-700 mb-1">申請の種類<span class="text-red-600 ml-0.5">*</span></label>
                {{-- ⚠ <option> は @@foreach で静的に出す（Bug #16）。選び直しは @@change で拾う（初期値は selected） --}}
                <select id="type_id" name="type_id" x-ref="typeSelect" @change="typeChanged($event.target.value)"
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

        {{-- 種類を選び直して、保存すると消えるものがあるとき（明細表の種類から 5W2H の種類へ・新しい種類が使わない追加の欄）。
             保存するまでは画面に残っているので、元の種類に戻せる（段階5 D16「黙って消さない」。点検の I-3） --}}
        <div x-show="leaving !== null" x-cloak role="alert" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-[13px] text-amber-800">
            <p class="mb-2">選んだ種類は<span class="font-semibold" x-text="leaving ? leaving.labels.join('・') : ''"></span>を使いません。このまま保存すると、入れた内容は消えます。</p>
            <div class="flex flex-wrap gap-2">
                <button type="button" @click="restoreType()" class="px-3 py-1.5 text-[12px] font-semibold text-white bg-amber-600 rounded-md hover:bg-amber-700 cursor-pointer">元の種類に戻す</button>
                <button type="button" @click="leaving = null" class="px-3 py-1.5 text-[12px] font-semibold text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50 cursor-pointer">この種類で進める</button>
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

        {{-- 件名（要件 5.5.3）。種類に決まり文句があれば「前半＋決まり文句」で組み立て、「件名を直接書く」で 1 行に切り替える。
             送るのはいつも組み立てた件名（下の name="subject"。組み立てるときは隠して送る。段階5 設計書 §5.5） --}}
        <div>
            <label for="subject" class="block text-[12px] font-semibold text-gray-700 mb-1">件名<span class="text-red-600 ml-0.5">*</span></label>
            <div x-show="subjectMode === 'split' && suffix() !== ''" x-cloak class="space-y-1.5">
                <div class="flex flex-wrap items-center gap-2">
                    <input type="text" id="subject-prefix" x-model="subjectPrefix" @input="composeSubject()" :maxlength="100 - suffix().length"
                           aria-label="件名の前半（決まり文句の前）" placeholder="例: 山田"
                           class="flex-1 min-w-[10rem] h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                    <span class="px-2.5 py-2 rounded-md border border-dashed border-gray-300 bg-gray-50 text-[13px] text-gray-700" x-text="suffix()"></span>
                </div>
                <p class="text-[12px] text-gray-700 bg-emerald-50 border-l-2 border-emerald-500 px-2.5 py-1.5">台帳と PDF に載る件名: <span class="font-semibold" x-text="subjectText || '（前半を入れてください）'"></span></p>
                <button type="button" @click="writeSubjectDirectly()" class="text-[12px] text-emerald-700 hover:underline cursor-pointer">件名を直接書く</button>
            </div>
            <div x-show="subjectMode === 'direct' || suffix() === ''">
                <input type="text" id="subject" name="subject" value="{{ old('subject', $approvalRequest->subject) }}" x-model="subjectText" maxlength="100"
                       class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                <button type="button" x-show="suffix() !== ''" x-cloak @click="useSuffix()" class="mt-1 text-[12px] text-emerald-700 hover:underline cursor-pointer">決まり文句（<span x-text="suffix()"></span>）を使う</button>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="amount" class="block text-[12px] font-semibold text-gray-700 mb-1">金額（円・税抜）</label>
                {{-- ⚠ value="0" の既定値を入れない（設計書 §5.6）。カンマ入りでも受け付ける。
                     明細表の種類では入れない（合計金額の販売金額をサーバーが計算する。段階5 D15）。押せなくして送らない --}}
                <input type="text" id="amount" name="amount" inputmode="numeric" placeholder="例: 28,500,000"
                       value="{{ old('amount', $approvalRequest->amount === null ? '' : number_format($approvalRequest->amount)) }}"
                       x-show="!isTable()" :disabled="isTable()"
                       class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                <p x-show="isTable()" x-cloak class="h-[38px] flex items-center px-2.5 rounded-md bg-slate-100 text-[13px] text-gray-700">
                    <span x-text="yen(total().sale) || '—'"></span><span class="ml-2 text-[11px] text-gray-500">（明細表の合計金額の販売金額）</span>
                </p>
            </div>
            <div>
                <label for="schedule" class="block text-[12px] font-semibold text-gray-700 mb-1">実施時期</label>
                <input type="text" id="schedule" name="schedule" value="{{ old('schedule', $approvalRequest->schedule) }}" maxlength="50" placeholder="例: 2026年10月"
                       class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
            </div>
        </div>

        {{-- 本文の欄（種類の本文の形で並びを変える。明細表の種類は「明細表 → 坪数・坪単価・担当者・契約予定日 → 定型文 → 補足」で、
             紙の住宅の様式の「（記）」の並び。5W2H の種類は「重点ポイント → 追加の欄 → 定型文」。段階5 設計書 §5.5） --}}
        <div class="flex flex-col gap-5">
            <div class="order-1">
                @include('approvals.requests._amount_table_input')
            </div>

            {{-- 追加の入力欄（種類が使う欄だけ。使わない欄は押せなくして送らない。担当者・契約予定日は提出に必須。D2・D12） --}}
            <div x-show="usesAny()" x-cloak class="order-2 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div x-show="uses('tsubo')">
                    <label for="tsubo" class="block text-[12px] font-semibold text-gray-700 mb-1">坪数</label>
                    <div class="flex items-center gap-1.5">
                        <input type="text" id="tsubo" name="tsubo" inputmode="decimal" placeholder="例: 38.5" :disabled="!uses('tsubo')" x-ref="extra_tsubo"
                               value="{{ old('tsubo', $approvalRequest->tsubo) }}" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] text-right tabular-nums">
                        <span class="text-[12px] text-gray-500">坪</span>
                    </div>
                </div>
                <div x-show="uses('tsubo_price')">
                    <label for="tsubo_price" class="block text-[12px] font-semibold text-gray-700 mb-1">坪単価</label>
                    <div class="flex items-center gap-1.5">
                        <input type="text" id="tsubo_price" name="tsubo_price" inputmode="numeric" placeholder="例: 1,083,000" :disabled="!uses('tsubo_price')" x-ref="extra_tsubo_price"
                               value="{{ old('tsubo_price', $approvalRequest->tsubo_price === null ? '' : number_format($approvalRequest->tsubo_price)) }}" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] text-right tabular-nums">
                        <span class="text-[12px] text-gray-500">円</span>
                    </div>
                </div>
                <div x-show="uses('staff')">
                    <label for="staff" class="block text-[12px] font-semibold text-gray-700 mb-1">担当者<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="text" id="staff" name="staff" maxlength="50" :disabled="!uses('staff')" x-ref="extra_staff"
                           value="{{ old('staff', $approvalRequest->staff) }}" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                </div>
                <div x-show="uses('contract_date')">
                    <label for="contract_date" class="block text-[12px] font-semibold text-gray-700 mb-1">契約予定日<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="date" id="contract_date" name="contract_date" :disabled="!uses('contract_date')" x-ref="extra_contract_date"
                           value="{{ old('contract_date', $approvalRequest->contract_date?->format('Y-m-d')) }}" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]">
                </div>
            </div>

            {{-- 定型文（種類の固定の文。申請者は直さない。保存したときに申請に写す。D14） --}}
            <div x-show="fixedText() !== ''" x-cloak class="order-3">
                <p class="text-[12px] font-semibold text-gray-700 mb-1">定型文</p>
                <p class="rounded-md border border-dashed border-gray-300 bg-gray-50 px-2.5 py-2 text-[13px] text-gray-700 whitespace-pre-wrap break-words" x-text="fixedText()"></p>
            </div>

            <div :class="isTable() ? 'order-4' : 'order-first'">
                <label for="body" class="block text-[12px] font-semibold text-gray-700 mb-1">
                    <span x-show="!isTable()">重点ポイント（5W2H）<span class="text-red-600 ml-0.5">*</span></span>
                    <span x-show="isTable()" x-cloak>補足（自由記入）</span>
                </label>
                <textarea id="body" name="body" x-ref="body" rows="16" :rows="isTable() ? 4 : 16" maxlength="20000" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ old('body', $approvalRequest->body) }}</textarea>
                <p x-show="!isTable()" class="text-[11px] text-gray-400 mt-1">種類を選ぶと見出しが入ります。見出しのままでは提出できません。「いつ」「いくら」は実施時期・金額の欄に書きます。</p>
                <p x-show="isTable()" x-cloak class="text-[11px] text-gray-400 mt-1">任意。長さの制限はありません（紙の様式の 2 行の欄）。</p>
            </div>
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
            <p x-show="numberError" x-cloak class="text-[12px] text-red-600 mt-1" x-text="numberError"></p>
            {{-- 候補の検索に失敗した文言は、番号の形の誤りと分けて出し、あとで検索できたら消す（Task 19 の B6） --}}
            <p x-show="errorMessage" x-cloak class="text-[12px] text-red-600 mt-1" x-text="errorMessage"></p>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-gray-100">
            <a href="{{ $editing ? route('approvals.requests.show', $approvalRequest) : route('approvals.home') }}" class="text-[13px] text-gray-500 hover:underline">やめる</a>
            <div class="flex flex-wrap items-center gap-2">
                <span role="status" x-text="submitting && !confirmSubmit ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                {{-- ⚠ 入力欄で Enter を押したときに送られるのは、この「保存」（先頭の submit ボタン）。提出にはならない --}}
                <button type="submit" @click="setIntent('save')" :disabled="submitting" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-700 hover:bg-gray-50 cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">{{ $returned ? '保存する' : '下書きを保存' }}</button>
                <button type="button" @click="confirmSubmit = true" :disabled="submitting" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">{{ $submitLabel }}</button>
            </div>
        </div>

        {{-- 提出の確認（要件 4.1）。ここの submit が hidden の intent を submit にして送る（setIntent） --}}
        <div x-show="confirmSubmit" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
            <div @click.outside="confirmSubmit = false" class="bg-white rounded-xl w-full max-w-[440px] shadow-xl mx-4 px-6 py-5">
                <p class="text-[15px] font-bold text-gray-900 mb-2">この内容で{{ $returned ? '出し直し' : '提出' }}しますか？</p>
                <p class="text-[12px] text-gray-500 mb-4">部門長（申請者が部門長なら省略）・審査・社長の順に回ります。{{ $returned ? '出し直した' : '提出した' }}あとは、差し戻されるまで中身を直せません。</p>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <span role="status" x-text="submitting && confirmSubmit ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                    <button type="button" @click="confirmSubmit = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" @click="setIntent('submit')" :disabled="submitting" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">{{ $submitLabel }}</button>
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
                            <button type="button" x-show="confirmingId === file.id" @click="remove(file)" :disabled="removing" class="text-[12px] font-semibold text-red-600 hover:underline cursor-pointer disabled:cursor-not-allowed disabled:opacity-50">外す（確定）</button>
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
    // 明細表の行の x-for の鍵
    var rowSeq = 0;
    var keyed = function (rows) {
        return (rows || []).map(function (row) {
            return { key: ++rowSeq, fixed: row.fixed, name: row.name, sale: row.sale, cost: row.cost };
        });
    };
    var tableRows = {{ \Illuminate\Support\Js::from($tableRows) }};

    return {
        headings: {{ \Illuminate\Support\Js::from($typeHeadings) }},
        // 種類ごとの本文の形・明細表の行の設定・件名の決まり文句・使う追加の欄・定型文（段階5。RequestController::formData）
        types: {{ \Illuminate\Support\Js::from($typeConfigs) }},
        typeId: {{ \Illuminate\Support\Js::from((string) old('type_id', $approvalRequest->type_id)) }},
        rows: { upper: keyed(tableRows.upper), lower: keyed(tableRows.lower) },
        // 件名（split＝前半＋決まり文句・direct＝1 行。送るのはいつも subjectText）
        subjectText: {{ \Illuminate\Support\Js::from((string) old('subject', $approvalRequest->subject)) }},
        subjectPrefix: '',
        subjectMode: 'direct',
        numbers: {{ \Illuminate\Support\Js::from(array_values(old('related_numbers', $approvalRequest->related_numbers ?? []))) }},
        maxNumbers: {{ \App\Support\Approval\RelatedNumbers::MAX }},
        numberInput: '',
        suggestions: [],
        searchSeq: 0,
        // 候補の検索に失敗した文言（⚠ 番号の形の誤り〈numberError〉と分ける。あとで検索できたら消す。Task 19 の B6。
        //   AjaxErrorFeedbackTest は !res.ok の中の errorMessage = を表示先として見る）
        errorMessage: '',
        // 関連する決裁No の形の誤り・数の上限（addNumber）
        numberError: '',
        pendingTypeId: null,
        // 種類を選び直して保存すると消えるもの（{ from: 前の種類, labels: 名前の並び }。lostOnChange）
        leaving: null,
        // 選び直す前の明細表の行（leaving と一緒に持つ。「元の種類に戻す」で並べ直さずにそのまま戻す。Task 6 の点検の I-1）
        rowsBefore: null,
        extraLabels: {{ \Illuminate\Support\Js::from(\App\Support\Approval\RequestExtras::FIELDS) }},
        confirmSubmit: false,
        // 保存・提出の二度押し止め（Task 19 の C2）。1 回目で印を立て、2 回目からは送信を取り消す
        submitting: false,

        // 保存か提出かを hidden の intent に書く（押したボタンの @click。送信の submit より先に走る）。
        // ⚠ ボタンに name・value を持たせない（onSubmit で押せなくすると、押したボタンの値は送られない）
        setIntent: function (intent) {
            this.$refs.intent.value = intent;
        },

        onSubmit: function (event) {
            if (this.submitting) {
                event.preventDefault();
                return;
            }
            this.submitting = true;
        },

        init: function () {
            this.initSubject();
        },

        // ---- 種類の設定（段階5） ----
        config: function () {
            return this.types[this.typeId] || null;
        },
        isTable: function () {
            var config = this.config();
            return config !== null && config.form === 'table';
        },
        hasSubtotal: function () {
            var config = this.config();
            return config !== null && !!config.layout.subtotal;
        },
        suffix: function () {
            var config = this.config();
            return config === null ? '' : config.suffix;
        },
        uses: function (key) {
            var config = this.config();
            return config !== null && config.uses.indexOf(key) !== -1;
        },
        usesAny: function () {
            var config = this.config();
            return config !== null && config.uses.length > 0;
        },
        fixedText: function () {
            var config = this.config();
            return config === null ? '' : config.fixedText;
        },

        // ---- 件名の組み立て（要件 5.5.3） ----
        // 決まり文句のある種類で、件名が空か決まり文句で終わっていれば前半と決まり文句に分けて出す（そうでなければ直接書く）
        initSubject: function () {
            var suffix = this.suffix();
            if (suffix !== '' && (this.subjectText === '' || this.endsWith(this.subjectText, suffix))) {
                this.subjectMode = 'split';
                this.subjectPrefix = this.subjectText.slice(0, this.subjectText.length - suffix.length);
                if (this.subjectText === '') {
                    this.subjectPrefix = '';
                }
            } else {
                this.subjectMode = 'direct';
            }
        },
        endsWith: function (text, suffix) {
            return text.length >= suffix.length && text.slice(text.length - suffix.length) === suffix;
        },
        // 前半が空なら件名も空（決まり文句だけの件名は作らない。台帳と PDF に施主名の無い件名が載る。提出は SubmitChecker も断る。点検の I-4）
        composeSubject: function () {
            this.subjectText = this.subjectPrefix.trim() === '' ? '' : this.subjectPrefix + this.suffix();
        },
        writeSubjectDirectly: function () {
            this.subjectMode = 'direct';
        },
        useSuffix: function () {
            var suffix = this.suffix();
            this.subjectPrefix = this.endsWith(this.subjectText, suffix) ? this.subjectText.slice(0, this.subjectText.length - suffix.length) : this.subjectText;
            this.subjectMode = 'split';
            this.composeSubject();
        },
        // 種類を選び直したとき: 組み立てていれば新しい決まり文句で組み直す（決まり文句の無い種類なら、打った前半だけを残す）
        subjectTypeChanged: function () {
            var suffix = this.suffix();
            if (this.subjectMode === 'split') {
                if (suffix === '') {
                    this.subjectMode = 'direct';
                    this.subjectText = this.subjectPrefix;
                } else {
                    this.composeSubject();
                }
            } else if (suffix !== '' && this.subjectText === '') {
                this.subjectMode = 'split';
                this.subjectPrefix = '';
                this.composeSubject();
            }
        },

        // ---- 金額の明細表（App\Support\Approval\AmountTable と同じ式。保存する金額はサーバーが計算し直す） ----
        // 数の入力を読む（App\Support\Approval\FormInput::digits と同じそろえ方。全角・カンマ・「円」・空白を落とし、マイナスの記号を - に、
        // 先頭の + と 0 を落とす。数でなければ null）。15 桁を超える数は読まない（サーバーが「12 桁まで」で断る。BigInt に渡せない数を作らない）
        parseAmount: function (value) {
            var text = String(value === null || value === undefined ? '' : value).replace(/[０-９]/g, function (c) {
                return String.fromCharCode(c.charCodeAt(0) - 0xFEE0);
            });
            text = text.replace(/[−ー‐―–—－]/g, '-').replace(/＋/g, '+').replace(/[,，円¥￥\s]/g, '');
            var m = /^([+-]?)0*(\d{1,15})$/.exec(text);
            if (m === null) {
                return null;
            }
            var number = parseInt(m[2], 10);
            return m[1] === '-' && number !== 0 ? -number : number;
        },
        yen: function (value) {
            return value === null || value === undefined ? '' : value.toLocaleString('ja-JP') + '円';
        },
        // 粗利率（％・小数第 1 位。四捨五入は 0 から遠い方へ）。販売金額が 0 なら無い。
        // ⚠ AmountTable::rate と同じく整数で計算する（浮動小数の割り算は 63.75% を 63.7499…% にする。点検の I-1）。
        //   |粗利益| × 2,000 は Number の正確な整数の範囲を超えうるので BigInt で割る
        rate: function (sale, profit) {
            if (sale === 0) {
                return null;
            }
            var s = BigInt(Math.abs(sale));
            var tenths = Number((BigInt(Math.abs(profit)) * 2000n + s) / (s * 2n));
            return ((profit < 0) !== (sale < 0) && tenths !== 0 ? -tenths : tenths) / 10;
        },
        // 「12.3%」（3 桁の区切りは付けない＝AmountTable::rateLabel と同じ）
        rateLabel: function (rate) {
            return rate === null ? '—' : rate.toFixed(1) + '%';
        },
        line: function (sale, cost) {
            return { sale: sale, cost: cost, profit: sale - cost, rate: this.rate(sale, sale - cost) };
        },
        rowLine: function (row) {
            var sale = this.parseAmount(row.sale);
            var cost = this.parseAmount(row.cost);
            if (sale === null && cost === null) {
                return { profit: null, rate: null };
            }
            return this.line(sale || 0, cost || 0);
        },
        sums: function (section) {
            var self = this;
            var sale = 0;
            var cost = 0;
            this.rows[section].forEach(function (row) {
                sale += self.parseAmount(row.sale) || 0;
                cost += self.parseAmount(row.cost) || 0;
            });
            return [sale, cost];
        },
        subtotal: function () {
            if (!this.hasSubtotal()) {
                return null;
            }
            var upper = this.sums('upper');
            return this.line(upper[0], upper[1]);
        },
        total: function () {
            var upper = this.sums('upper');
            var lower = this.sums('lower');
            return this.line(upper[0] + lower[0], upper[1] + lower[1]);
        },
        // 欄を離れたらカンマ付きに整える（読めない値はそのまま。サーバーが理由を出す）
        formatAmount: function (row, field) {
            var value = this.parseAmount(row[field]);
            if (value !== null) {
                row[field] = value.toLocaleString('ja-JP');
            }
        },
        addRow: function (section) {
            this.rows[section].push({ key: ++rowSeq, fixed: null, name: '', sale: '', cost: '' });
        },
        removeRow: function (section, index) {
            this.rows[section].splice(index, 1);
        },
        // 種類の行の設定に合わせて並べ直す（AmountTable::forForm と同じ規則。名前を設定した行は同じ名前の行の金額を当て〈無ければ同じ名前の
        // 自由行の最初の 1 つを取り込む〉、自由行の位置には自由行を順に当て、残りは後ろへ。設定から名前が消えた行は、金額があれば自由行として
        // 残す＝黙って消さない。段階5 D16）
        mergeRows: function (layout, rows) {
            var merged = {};
            ['upper', 'lower'].forEach(function (section) {
                var names = layout[section] || [];
                var fixed = {};
                var free = [];
                rows[section].forEach(function (row) {
                    if (row.fixed !== null && names.indexOf(row.fixed) !== -1 && !fixed.hasOwnProperty(row.fixed)) {
                        fixed[row.fixed] = row;
                    } else if (row.fixed === null || String(row.sale).trim() !== '' || String(row.cost).trim() !== '') {
                        free.push({ key: ++rowSeq, fixed: null, name: row.fixed !== null ? row.fixed : row.name, sale: row.sale, cost: row.cost });
                    }
                });
                // 名前を設定した行が無ければ、同じ名前の自由行（前から数えて最初の 1 つ）を取り込む。一度自由行になった「紹介料」などが、
                // 元の種類を選び直したときに空の名前の行と並んで二重にならない（名前は前後の空白〈全角を含む〉を落として比べる＝サーバーが
                // 保存のときに落とすのと同じ。点検の I-1）
                names.forEach(function (name) {
                    if (name === null || fixed.hasOwnProperty(name)) {
                        return;
                    }
                    for (var i = 0; i < free.length; i++) {
                        if (String(free[i].name || '').trim() === name) {
                            fixed[name] = free.splice(i, 1)[0];
                            return;
                        }
                    }
                });
                var out = [];
                names.forEach(function (name) {
                    if (name !== null) {
                        var old = fixed[name];
                        out.push({ key: ++rowSeq, fixed: name, name: '', sale: old ? old.sale : '', cost: old ? old.cost : '' });
                    } else {
                        out.push(free.length > 0 ? free.shift() : { key: ++rowSeq, fixed: null, name: '', sale: '', cost: '' });
                    }
                });
                merged[section] = out.concat(free);
            });
            return merged;
        },

        // 「戻る」で画面がそのまま戻ったとき（bfcache）に印を下ろす（Bug #65 と同じく persisted で絞らない）。
        // 編集の画面は画面の版（lock_version）が古くなっているので、押してもサーバーが断る（新しい申請の画面では、もう 1 件の下書きになる）
        resetSubmit: function () {
            this.submitting = false;
        },

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

        // 種類を選んだ: 明細表と件名を新しい種類に合わせる。本文は、5W2H の種類なら見出しのままなら入れ替え、書き始めていれば確かめる。
        // 明細表の種類なら、見出しのままの本文は空にする（補足になる。書いた本文は黙って消さずに補足に残す。段階5 D16）
        typeChanged: function (typeId) {
            var lost = this.lostOnChange(typeId);
            this.leaving = lost.length > 0 ? { from: this.typeId, labels: lost, subjectMode: this.subjectMode } : null;
            this.rowsBefore = lost.length > 0 ? { upper: this.rows.upper.slice(), lower: this.rows.lower.slice() } : null;
            this.typeId = typeId;
            if (this.isTable()) {
                this.rows = this.mergeRows(this.config().layout, this.rows);
            }
            this.subjectTypeChanged();

            var headings = this.headings[typeId];
            this.pendingTypeId = null;
            if (this.isTable()) {
                if (this.isBlankBody(this.$refs.body.value)) {
                    this.$refs.body.value = '';
                }
                return;
            }
            if (!headings) {
                return;
            }
            if (this.isBlankBody(this.$refs.body.value)) {
                this.$refs.body.value = headings;
                return;
            }
            this.pendingTypeId = typeId;
        },

        // 種類を選び直すと保存で消えるもの（明細表の種類から 5W2H の種類へ移るときの明細表・新しい種類が使わない追加の欄の値。
        // 保存のとき RequestFields が空にする。段階5 D16。点検の I-3）
        lostOnChange: function (typeId) {
            var self = this;
            var from = this.config();
            var to = this.types[typeId] || null;
            var lost = [];
            if (from === null) {
                return lost;
            }
            var filled = function (row) {
                return String(row.name || '').trim() !== '' || String(row.sale).trim() !== '' || String(row.cost).trim() !== '';
            };
            if (from.form === 'table' && (to === null || to.form !== 'table') && (this.rows.upper.some(filled) || this.rows.lower.some(filled))) {
                lost.push('明細表');
            }
            from.uses.forEach(function (key) {
                var input = self.$refs['extra_' + key];
                if ((to === null || to.uses.indexOf(key) === -1) && input && String(input.value).trim() !== '') {
                    lost.push(self.extraLabels[key]);
                }
            });
            return lost;
        },
        // 選び直す前の種類に戻す（書いた明細表と欄は、保存するまで画面に残っている）。明細表の行は選び直す前の行をそのまま戻す
        // （並べ直して作り直さない。選び直した種類に無い名前の行が自由行のまま残らない。点検の I-1）。件名を前半と決まり文句で
        // 組み立てていたら、決まり文句の無い種類で 1 行になった件名を組み立て直す
        restoreType: function () {
            var previous = this.leaving.from;
            var composed = this.leaving.subjectMode === 'split';
            var rows = this.rowsBefore;
            this.$refs.typeSelect.value = previous;
            this.typeChanged(previous);
            if (rows !== null) {
                this.rows = rows;
            }
            if (composed) {
                this.useSuffix();
            }
            this.leaving = null;
            this.rowsBefore = null;
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
            this.numberError = '';
            if (number === '') {
                return;
            }
            // App\Support\Approval\RelatedNumbers::PATTERN と同じ形（年は 1 から・連番は 3〜5 桁）
            if (!/^[RH][1-9][0-9]?-[A-Z]{1,3}-[0-9]{3,5}$/.test(number)) {
                this.numberError = '「' + number + '」は決裁No の形ではありません（例: R8-J-001）。';
                return;
            }
            if (this.numbers.indexOf(number) === -1) {
                if (this.numbers.length >= this.maxNumbers) {
                    this.numberError = '関連する決裁No は ' + this.maxNumbers + ' 個までです。';
                    return;
                }
                this.numbers.push(number);
            }
            this.numberInput = '';
            this.suggestions = [];
        },

        removeNumber: function (index) {
            this.numbers.splice(index, 1);
            this.numberError = '';
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
                    // 古い検索の失敗は出さない（新しい検索の結果と食い違わせない）
                    if (seq === self.searchSeq) {
                        self.errorMessage = '候補を読み込めませんでした（' + res.status + '）。番号はそのまま入れられます。';
                    }
                    return null;
                }
                return res.json();
            })
            .then(function (data) {
                // 古い検索の答えが後から届いたら捨てる。検索できたら、前に失敗した文言を消す（Task 19 の B6）
                if (!data || seq !== self.searchSeq) return;
                self.errorMessage = '';
                self.suggestions = data.items;
            })
            .catch(function () {
                if (seq === self.searchSeq) {
                    self.errorMessage = '候補を読み込めませんでした。番号はそのまま入れられます。';
                }
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
        removing: false,
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

        // 外す。⚠ 返事が来るまで印を立てて押せなくする（同じ添付を 2 回外しに行かない。Task 19 の B4）
        remove: function (file) {
            var self = this;
            if (self.removing) {
                return;
            }
            self.removing = true;
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
                self.removing = false;
                self.confirmingId = null;
                if (!data) return;
                self.files = self.files.filter(function (f) { return f.id !== file.id; });
                self.successMessage = data.message;
            })
            .catch(function () {
                self.removing = false;
                self.confirmingId = null;
                self.errorMessage = '通信に失敗しました。もう一度お試しください。';
            });
        }
    };
}
@endif
</script>
@endpush
