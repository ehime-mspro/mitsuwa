{{-- 決裁の管理者の操作（部門長の確認の付け替え・押し間違いの取り消し・代理の取り下げ。段階2 設計書 §5.14・2b 計画 §0.8）。
     出すのは RequestPermissions がこの人に「今できる」と判定したものだけ。自分が申請者の申請には出さず、理由を出す（D25）。
     ⚠ 3 つの小窓は描いたときの lock_version を送る（古い画面から押した操作を断る）。確定のボタンは 1 回押したら押せなくする
       （approvalSubmitOnce。定義は _actions が読み込む _submit_once）。理由は必須（D22）
     ⚠ 断られて戻ったら、送った小窓だけを打った中身で開き直す（どの小窓かは送り先がセッションに残す approval_reopen。2b 計画 §0.9） --}}
@php
    $adminRefusal  = $permissions->adminRefusal();
    $canReassign   = $permissions->canReassign();
    $canUndo       = $permissions->canUndo();
    $undoTarget    = $canUndo ? $permissions->undoTarget() : null;
    $undoRefusal   = $canUndo ? $permissions->undoRefusal() : null;
    $canWithdraw   = $permissions->canWithdrawByAdmin();
    $adminModals   = ['reassign', 'undo', 'admin_withdraw'];
    $reopenAdmin   = is_string(old('lock_version')) && in_array(session('approval_reopen'), $adminModals, true) ? session('approval_reopen') : null;
    $adminVersion  = fn (string $modal) => $reopenAdmin === $modal ? old('lock_version') : $approvalRequest->lock_version;
    $adminReason   = fn (string $modal) => $reopenAdmin === $modal && is_string(old('admin_reason')) ? old('admin_reason') : '';
    $adminRefused  = $reopenAdmin !== null ? ($errors->any() ? implode(' ', $errors->all()) : (string) session('error')) : '';
    $showsAdminRow = in_array($approvalRequest->status, [
        \App\Enums\ApprovalStatus::HeadReview, \App\Enums\ApprovalStatus::Review, \App\Enums\ApprovalStatus::President,
        \App\Enums\ApprovalStatus::Returned, \App\Enums\ApprovalStatus::Condition,
        \App\Enums\ApprovalStatus::Approved, \App\Enums\ApprovalStatus::Rejected,
    ], true);
@endphp

@if($adminRefusal !== null && $showsAdminRow)
    <p class="mb-5 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-[12px] text-gray-600">{{ $adminRefusal }}</p>
@elseif($canReassign || $canUndo || $canWithdraw)
    <section class="bg-white rounded-lg border border-gray-200 mb-5 px-5 py-4" x-data="{ adminModal: {{ \Illuminate\Support\Js::from($reopenAdmin) }} }">
        <h2 class="text-[14px] font-bold text-gray-900 mb-1">決裁の管理者の操作</h2>
        <p class="text-[12px] text-gray-500 mb-3">どの操作も理由が必要で、記録に残ります。</p>
        <div class="flex flex-wrap items-center gap-2">
            @if($canReassign)
                <button type="button" @click="adminModal = 'reassign'" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-800 hover:bg-gray-50 cursor-pointer">部門長の確認を付け替える</button>
            @endif
            @if($canUndo)
                @if($undoRefusal !== null)
                    {{-- 差戻しのあと申請者が直し始めていたら取り消せない（D3）。押せないボタンは span で包んで理由を付ける（Bug #43） --}}
                    <span title="{{ $undoRefusal }}" style="display: inline-flex;">
                        <button type="button" disabled aria-describedby="undo-refusal" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-400 cursor-not-allowed">直前の操作を取り消す</button>
                    </span>
                @else
                    <button type="button" @click="adminModal = 'undo'" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-800 hover:bg-gray-50 cursor-pointer">直前の操作を取り消す</button>
                @endif
            @endif
            @if($canWithdraw)
                <button type="button" @click="adminModal = 'admin_withdraw'" class="px-4 py-2 bg-white border border-red-200 rounded-md text-[13px] font-semibold text-red-600 hover:bg-red-50 cursor-pointer">申請者に代わって取り下げる</button>
            @endif
        </div>
        @if($canUndo && $undoRefusal !== null)
            <p id="undo-refusal" class="mt-2 text-[12px] text-amber-800">{{ $undoRefusal }}</p>
        @endif

        @if($canReassign)
            <div x-show="adminModal === 'reassign'" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
                <div @click.outside="adminModal = null" class="bg-white rounded-xl w-full max-w-[480px] shadow-xl mx-4">
                    <form method="POST" action="{{ route('approvals.admin.requests.reassign', $approvalRequest) }}"
                          x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                        @csrf
                        <input type="hidden" name="lock_version" value="{{ $adminVersion('reassign') }}">
                        <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">部門長の確認を付け替えますか？</div>
                        @if($reopenAdmin === 'reassign' && $adminRefused !== '')
                            <p class="mx-6 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-700">{{ $adminRefused }}</p>
                        @endif
                        <div class="px-6 py-4 space-y-3">
                            <p class="text-[12px] text-gray-500">いまの担当: {{ \App\Support\Approval\CurrentHandler::describe($permissions->waitingStep()) }}。付け替えた人が承認・差戻しをします。あとで部門の管理で部門長を変えると、新しい部門長へ移ります。</p>
                            <div>
                                <label for="reassign-to" class="block text-[12px] font-semibold text-gray-700 mb-1">付け替え先<span class="text-red-600 ml-0.5">*</span></label>
                                <select id="reassign-to" name="assignee_user_id" required class="w-full h-9 px-2.5 border border-gray-300 rounded-md text-[13px] bg-white">
                                    <option value="">選んでください</option>
                                    @foreach($assigneeCandidates as $candidate)
                                        <option value="{{ $candidate->id }}" @selected($reopenAdmin === 'reassign' && is_string(old('assignee_user_id')) && old('assignee_user_id') === (string) $candidate->id)>{{ $candidate->name }}{{ $candidate->employee_number ? '（' . $candidate->employee_number . '）' : '' }}{{ $candidate->mail_allowed ? '' : ' ※通知メールが届きません' }}</option>
                                    @endforeach
                                </select>
                                <p class="text-[11px] text-gray-400 mt-1">有効でメールアドレスのある人から選びます（申請者本人は選べません）。</p>
                            </div>
                            <div>
                                <label for="reassign-reason" class="block text-[12px] font-semibold text-gray-700 mb-1">理由<span class="text-red-600 ml-0.5">*</span></label>
                                <textarea id="reassign-reason" name="admin_reason" rows="3" maxlength="2000" required class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ $adminReason('reassign') }}</textarea>
                            </div>
                        </div>
                        <div class="px-6 pb-5 flex flex-wrap items-center justify-end gap-2">
                            <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                            <button type="button" @click="adminModal = null" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                            <button type="submit" :disabled="submitting" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">付け替える</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        @if($canUndo && $undoRefusal === null)
            <div x-show="adminModal === 'undo'" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
                <div @click.outside="adminModal = null" class="bg-white rounded-xl w-full max-w-[480px] shadow-xl mx-4">
                    <form method="POST" action="{{ route('approvals.admin.requests.undo', $approvalRequest) }}"
                          x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                        @csrf
                        <input type="hidden" name="lock_version" value="{{ $adminVersion('undo') }}">
                        <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">直前の操作を取り消しますか？</div>
                        @if($reopenAdmin === 'undo' && $adminRefused !== '')
                            <p class="mx-6 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-700">{{ $adminRefused }}</p>
                        @endif
                        <div class="px-6 py-4 space-y-3">
                            <p class="text-[13px] text-gray-900">
                                取り消す操作: <span class="font-semibold">{{ $undoTarget->label() }}</span>
                                <span class="text-gray-600">{{ $undoTarget->actor?->name }}</span>
                                <span class="text-[12px] text-gray-400">{{ \App\Support\JapanTime::format($undoTarget->created_at) }}</span>
                            </p>
                            <p class="text-[12px] text-gray-500">
                                その操作の前の状態に戻します。元の記録は消えず、取り消したことが記録に残ります。続けて取り消すと、もう 1 つ前の操作にさかのぼります（提出の手前まで）。
                                @if($approvalRequest->number)
                                    決裁No（{{ $approvalRequest->number }}）はこの申請に残り、次に社長が判断したときにそのまま使います。
                                @endif
                            </p>
                            <div>
                                <label for="undo-reason" class="block text-[12px] font-semibold text-gray-700 mb-1">理由<span class="text-red-600 ml-0.5">*</span></label>
                                <textarea id="undo-reason" name="admin_reason" rows="3" maxlength="2000" required class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ $adminReason('undo') }}</textarea>
                            </div>
                        </div>
                        <div class="px-6 pb-5 flex flex-wrap items-center justify-end gap-2">
                            <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                            <button type="button" @click="adminModal = null" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                            <button type="submit" :disabled="submitting" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">取り消す</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        @if($canWithdraw)
            <div x-show="adminModal === 'admin_withdraw'" x-cloak class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center">
                <div @click.outside="adminModal = null" class="bg-white rounded-xl w-full max-w-[480px] shadow-xl mx-4">
                    <form method="POST" action="{{ route('approvals.admin.requests.withdraw', $approvalRequest) }}"
                          x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                        @csrf
                        <input type="hidden" name="lock_version" value="{{ $adminVersion('admin_withdraw') }}">
                        <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">申請者に代わって取り下げますか？</div>
                        @if($reopenAdmin === 'admin_withdraw' && $adminRefused !== '')
                            <p class="mx-6 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[12px] text-red-700">{{ $adminRefused }}</p>
                        @endif
                        <div class="px-6 py-4 space-y-3">
                            <p class="text-[12px] text-gray-500">申請者の退職などで、申請者が取り下げられないときに使います。取り下げると回覧が止まり、元に戻せません。{{ $approvalRequest->number ? '決裁No はそのまま残ります。' : '' }}</p>
                            <div>
                                <label for="admin-withdraw-reason" class="block text-[12px] font-semibold text-gray-700 mb-1">理由<span class="text-red-600 ml-0.5">*</span></label>
                                <textarea id="admin-withdraw-reason" name="admin_reason" rows="3" maxlength="2000" required class="w-full px-2.5 py-2 border border-gray-300 rounded-md text-[13px] leading-relaxed">{{ $adminReason('admin_withdraw') }}</textarea>
                            </div>
                        </div>
                        <div class="px-6 pb-5 flex flex-wrap items-center justify-end gap-2">
                            <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                            <button type="button" @click="adminModal = null" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                            <button type="submit" :disabled="submitting" class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-[13px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">取り下げる</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </section>

    {{-- 確定の二度押し止めの部品（定義は 1 か所。@once なので _actions と重ねて読み込んでも 1 回だけ出る） --}}
    @include('approvals._submit_once')
@endif
