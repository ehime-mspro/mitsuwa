{{-- 申請の詳細の操作（設計書 §5.12）。出すのは RequestPermissions がこの人に「今できる」と判定したものだけ。
     押せないけれど役割のある人（自分の申請の担当に当たる人。D16）には理由を出す（Bug #43）。
     ⚠ 判断・条件確認・取り下げのフォームは、描いたときの lock_version を送る（古い画面から押した操作を断る。計画 §0.3）
     ⚠ 4 つのフォーム（判断・取り下げ・条件確認・下書きの削除）の確定のボタンは、1 回押したら押せなくする（approvalSubmitOnce。
       Task 19 の C2・N-3）。送る値は hidden で持ち、
       ボタンに name・value を持たせない（送る前にボタンを押せなくすると、そのボタンの値は送られない） --}}
@php
    $judgeable = $permissions->judgeableStep();
    $refusal   = $permissions->judgeRefusal();
    $waiting   = $permissions->waitingStep();
    // 判断・取り下げ・条件確認が、入力の誤りかコメントの不足で断られて戻ったとき（RequestActionController が入力を戻す）は、
    // 打った中身で小窓を開き直し、断られた理由を小窓の中にも出す（ページの上の理由は小窓に隠れる。Task 19 の C8）。
    // 先を越されたとき（すでに処理されています）は入力を戻さないので開かない。
    // ⚠ 3 つの小窓は同時には出ない（判断は申請者でない担当・取り下げと条件確認は申請者で、状態も重ならない）
    // ⚠ 版は断られる前の版のまま（古い画面から送って断られたなら、直して送っても「すでに処理されています」で断る）
    $reopen        = is_string(old('lock_version'));
    $lockVersion   = $reopen ? old('lock_version') : $approvalRequest->lock_version;
    $oldComment    = $reopen && is_string(old('comment')) ? old('comment') : '';
    $refusedReason = $reopen ? ($errors->any() ? implode(' ', $errors->all()) : (string) session('error')) : '';
@endphp

{{-- 申請者の操作 --}}
@if($permissions->isApplicant())
    <div class="flex flex-wrap items-center gap-2 mb-5" x-data="{ confirmDelete: false, confirmWithdraw: {{ $reopen && $permissions->canWithdraw() ? 'true' : 'false' }} }">
        @if($permissions->canEdit())
            <a href="{{ route('approvals.requests.edit', $approvalRequest) }}" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold">{{ $approvalRequest->status === \App\Enums\ApprovalStatus::Returned ? '直して出し直す' : '編集する' }}</a>
        @endif
        <a href="{{ route('approvals.requests.create', ['copy' => $approvalRequest->id]) }}" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-700 hover:bg-gray-50">コピーして新しい申請</a>
        @if($permissions->canWithdraw())
            <button type="button" @click="confirmWithdraw = true" class="px-4 py-2 bg-white border border-red-200 rounded-md text-[13px] font-semibold text-red-600 hover:bg-red-50 cursor-pointer">取り下げる</button>
        @endif

        @if($permissions->canDelete())
            <form method="POST" action="{{ route('approvals.requests.destroy', $approvalRequest) }}"
                  x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                @csrf
                @method('DELETE')
                <button type="button" @click="confirmDelete = true" class="px-4 py-2 bg-white border border-red-200 rounded-md text-[13px] font-semibold text-red-600 hover:bg-red-50 cursor-pointer">下書きを削除</button>

                <div x-show="confirmDelete" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
                    <div @click.outside="confirmDelete = false" class="bg-white rounded-xl w-full max-w-[420px] shadow-xl mx-4 px-6 py-5">
                        <p class="text-[15px] font-bold text-gray-900 mb-2">この下書きを削除しますか？</p>
                        <p class="text-[12px] text-gray-500 mb-4">添付したファイルも消えます。元に戻せません。</p>
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                            <button type="button" @click="confirmDelete = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                            <button type="submit" :disabled="submitting" class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">削除する</button>
                        </div>
                    </div>
                </div>
            </form>
        @endif

        @if($permissions->canWithdraw())
            {{-- 取り下げの確認（コメントは任意。D17） --}}
            <div x-show="confirmWithdraw" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
                <div @click.outside="confirmWithdraw = false" class="bg-white rounded-xl w-full max-w-[480px] shadow-xl mx-4">
                    <form method="POST" action="{{ route('approvals.requests.withdraw', $approvalRequest) }}"
                          x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                        @csrf
                        <input type="hidden" name="lock_version" value="{{ $lockVersion }}">
                        <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">この申請を取り下げますか？</div>
                        @if($refusedReason !== '')
                            <p class="mx-6 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-700">{{ $refusedReason }}</p>
                        @endif
                        <div class="px-6 py-4">
                            <p class="text-[12px] text-gray-500 mb-3">取り下げると回覧が止まり、元に戻せません。{{ $approvalRequest->number ? '決裁No はそのまま残ります。' : '' }}</p>
                            <label for="withdraw-comment" class="block text-[12px] font-semibold text-gray-700 mb-1">コメント<span class="text-gray-400 font-normal ml-1">（任意）</span></label>
                            <textarea id="withdraw-comment" name="comment" rows="3" maxlength="2000" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ $oldComment }}</textarea>
                        </div>
                        <div class="px-6 pb-5 flex flex-wrap items-center justify-end gap-2">
                            <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                            <button type="button" @click="confirmWithdraw = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                            <button type="submit" :disabled="submitting" class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">取り下げる</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endif

{{-- 条件の確認（申請者。要件 4.6。条件は社長の段階のコメント） --}}
@if($permissions->canConfirmCondition())
    @php $conditionStep = $approvalRequest->currentSteps()->firstWhere('kind', \App\Enums\ApprovalStepKind::President); @endphp
    <section class="bg-amber-50 rounded-lg border border-amber-200 mb-5 px-5 py-4" x-data="{ confirmCondition: {{ $reopen ? 'true' : 'false' }} }">
        <h2 class="text-[14px] font-bold text-amber-900 mb-1">社長の条件を確認してください</h2>
        <p class="text-[13px] text-amber-900 whitespace-pre-wrap break-words mb-3">{{ $conditionStep?->comment }}</p>
        <button type="button" @click="confirmCondition = true" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">条件を確認しました</button>

        <div x-show="confirmCondition" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
            <div @click.outside="confirmCondition = false" class="bg-white rounded-xl w-full max-w-[480px] shadow-xl mx-4">
                <form method="POST" action="{{ route('approvals.requests.confirmCondition', $approvalRequest) }}"
                      x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                    @csrf
                    <input type="hidden" name="lock_version" value="{{ $lockVersion }}">
                    <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">条件を確認したことを記録しますか？</div>
                    @if($refusedReason !== '')
                        <p class="mx-6 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-700">{{ $refusedReason }}</p>
                    @endif
                    <div class="px-6 py-4">
                        <p class="text-[12px] text-gray-500 mb-3">記録すると決裁済み（条可）になります。</p>
                        <label for="condition-comment" class="block text-[12px] font-semibold text-gray-700 mb-1">コメント<span class="text-gray-400 font-normal ml-1">（任意）</span></label>
                        <textarea id="condition-comment" name="comment" rows="3" maxlength="2000" class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ $oldComment }}</textarea>
                    </div>
                    <div class="px-6 pb-5 flex flex-wrap items-center justify-end gap-2">
                        <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                        <button type="button" @click="confirmCondition = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                        <button type="submit" :disabled="submitting" class="px-5 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">確認しました</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
@endif

{{-- 判断（部門長・審査・社長。要件 4.2）。選んだ判断とコメントを確認のモーダルで確かめてから送る --}}
@if($judgeable)
    @php
        $kind       = $judgeable->kind;
        $choices    = \App\Enums\ApprovalStepResult::allowedFor($kind);
        $judgeRoute = match ($kind) {
            \App\Enums\ApprovalStepKind::Head      => 'approvals.requests.headReview',
            \App\Enums\ApprovalStepKind::Review    => 'approvals.requests.review',
            \App\Enums\ApprovalStepKind::President => 'approvals.requests.decide',
        };
        $hint = match ($kind) {
            \App\Enums\ApprovalStepKind::Head      => '承認すると審査へ回ります。差し戻すと申請者が直して出し直します（差戻しはコメントが必要）。',
            \App\Enums\ApprovalStepKind::Review    => '可・保留・否のどれでも社長へ回ります（保留・否はコメントが必要）。',
            \App\Enums\ApprovalStepKind::President => '可・条可・否で決裁No が付きます。条可はコメントに条件を書いてください。差し戻すと申請者が直して出し直します（条可・差戻し・否はコメントが必要）。',
        };
        $choiceData = [];
        foreach ($choices as $choice) {
            $choiceData[$choice->value] = ['label' => $choice->labelFor($kind), 'comment' => $choice->requiresComment()];
        }
        // 断られて戻ったら、選んでいた判断で小窓を開き直す（C8。この段階で選べる判断のときだけ）
        $reopenChoice = $reopen && is_string(old('result')) && isset($choiceData[old('result')]) ? old('result') : null;
    @endphp
    <section class="bg-white rounded-lg border-2 border-emerald-200 mb-5 px-5 py-4" x-data="approvalJudge({{ \Illuminate\Support\Js::from($choiceData) }})" @if($reopenChoice !== null) x-init="open({{ \Illuminate\Support\Js::from($reopenChoice) }})" @endif>
        <h2 class="text-[14px] font-bold text-gray-900 mb-1">{{ $kind->label() }}としての判断</h2>
        <p class="text-[12px] text-gray-500 mb-3">{{ $hint }}</p>
        <div class="flex flex-wrap gap-2">
            @foreach($choices as $choice)
                <button type="button" @click="open('{{ $choice->value }}')"
                        class="px-4 py-2 rounded-md text-[13px] font-semibold cursor-pointer border {{ in_array($choice, [\App\Enums\ApprovalStepResult::Approve, \App\Enums\ApprovalStepResult::Ok], true) ? 'bg-emerald-600 hover:bg-emerald-700 text-white border-emerald-600' : 'bg-white hover:bg-gray-50 text-gray-800 border-gray-300' }}">{{ $choice->labelFor($kind) }}</button>
            @endforeach
        </div>

        <div x-show="choice !== null" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
            <div @click.outside="choice = null" class="bg-white rounded-xl w-full max-w-[480px] shadow-xl mx-4">
                <form method="POST" action="{{ route($judgeRoute, $approvalRequest) }}"
                      x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                    @csrf
                    <input type="hidden" name="lock_version" value="{{ $lockVersion }}">
                    {{-- 選んだ判断（選ぶボタンの open() が決める）。選べる判断はサーバーが描く（選ぶボタンの open('…') と approvalJudge に
                         渡す Js::from。Bug #47）。⚠ 確定のボタンに name・value を持たせない（Task 19 の C2） --}}
                    <input type="hidden" name="result" :value="choice">
                    <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">「<span x-text="label()"></span>」で確定しますか？</div>
                    @if($refusedReason !== '')
                        {{-- 断られた判断を選んでいるあいだだけ出す（小窓を閉じて別の判断を選び直すと、前の断りの理由は当てはまらない） --}}
                        <p x-show="choice === {{ \Illuminate\Support\Js::from($reopenChoice) }}" class="mx-6 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-700">{{ $refusedReason }}</p>
                    @endif
                    <div class="px-6 py-4">
                        <label for="judge-comment" class="block text-[12px] font-semibold text-gray-700 mb-1">
                            コメント
                            <span x-show="needsComment()" class="text-red-600">（必須）</span>
                            <span x-show="!needsComment()" class="text-gray-400 font-normal">（任意）</span>
                        </label>
                        <textarea id="judge-comment" name="comment" rows="5" maxlength="2000" :required="needsComment()"
                                  class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ $oldComment }}</textarea>
                    </div>
                    <div class="px-6 pb-5 flex flex-wrap items-center justify-end gap-2">
                        <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                        <button type="button" @click="choice = null" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                        <button type="submit" :disabled="submitting" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">確定する</button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    @push('scripts')
    <script>
    {{-- ⚠ x-data にアロー関数を書かない（Top trap #4）。選べる判断は Js::from で渡す（Bug #23） --}}
    function approvalJudge(choices) {
        return {
            choices: choices,
            choice: null,
            open: function (value) { this.choice = value; },
            label: function () { return this.choice ? this.choices[this.choice].label : ''; },
            needsComment: function () { return this.choice ? this.choices[this.choice].comment : false; }
        };
    }
    </script>
    @endpush
@elseif($refusal)
    {{-- 担当に当たるが自分の申請なので判断できない（D16）。押せないボタンは span で包んで理由を付ける（Bug #43） --}}
    <section class="bg-amber-50 rounded-lg border border-amber-200 mb-5 px-5 py-4">
        <p id="judge-refusal" class="text-[13px] font-semibold text-amber-900 mb-1">{{ $refusal }}</p>
        {{-- 2 行目は段階ごとの次の手（Task 19 の C7。社長の指定を変えられるのは基幹の管理者だけ。要件 3.2・4.7） --}}
        <p class="text-[12px] text-amber-800 mb-3">{{ match ($waiting->kind) {
            \App\Enums\ApprovalStepKind::Review    => 'ほかの審査担当者が判断します。',
            \App\Enums\ApprovalStepKind::Head      => '取り下げて出し直すか、決裁の管理者に相談してください。',
            \App\Enums\ApprovalStepKind::President => '社長の指定を変えられるのは基幹の管理者です。急ぐときは取り下げてください。',
        } }}</p>
        <div class="flex flex-wrap gap-2">
            @foreach(\App\Enums\ApprovalStepResult::allowedFor($waiting->kind) as $choice)
                <span title="{{ $refusal }}" style="display: inline-flex;">
                    <button type="button" disabled aria-describedby="judge-refusal" class="px-4 py-2 rounded-md text-[13px] font-semibold border border-gray-300 bg-white text-gray-400 cursor-not-allowed">{{ $choice->labelFor($waiting->kind) }}</button>
                </span>
            @endforeach
        </div>
    </section>
@endif

{{-- 確定の二度押し止めの部品（approvalSubmitOnce。定義は 1 か所）。「戻る」で戻って押し直すと、判断・取り下げ・条件確認は版が古いので
     「すでに処理されています」で断られる（下書きの削除は、もう消えていれば見つからない＝404。Task 19 の N-3 の残り） --}}
@include('approvals._submit_once')
