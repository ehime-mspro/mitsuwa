<?php

namespace App\Http\Controllers\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepResult;
use App\Http\Controllers\Controller;
use App\Models\ApprovalHistory;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\ApprovalStep;
use App\Models\ApprovalType;
use App\Models\User;
use App\Support\Approval\Assignees;
use App\Support\Approval\FormInput;
use App\Support\Approval\RelatedNumbers;
use App\Support\Approval\RequestContent;
use App\Support\Approval\RequestPermissions;
use App\Support\Approval\RequestSnapshot;
use App\Support\Approval\RequestVisibility;
use App\Support\Approval\Workflow;
use App\Support\Approval\WorkflowConflict;
use App\Support\Approval\WorkflowRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 申請書（画面②）と申請の詳細（画面③）（設計書 §5.6・§5.12）。
 *
 * ⚠ 状態を変えるのは Workflow だけ。ここは中身の保存と、提出の呼び出しだけを行う。
 * ⚠ 保存のフォームに `intent=submit` が付いていれば、保存のあと提出する（計画 §0.8 の 2）。
 *   提出できなくても中身は残し、理由を編集の画面の上にすべて並べる（計画 §0.11）。
 * ⚠ 中身の保存は、編集の画面を描いたときの `lock_version` を条件にした 1 回の UPDATE で行い、`lock_version` を
 *   1 進める（計画 §0.3）。古いタブの保存が新しい中身を上書きしない（Task 7 の点検の申し送り）。
 * ⚠ 見られない申請は 404（在るかどうかを漏らさない。他人の下書きは誰も見られない）。
 * ⚠ 詳細の中身は RequestContent から出す（申請者以外には最後に提出した控え。差戻し中の直しかけを見せない）。
 * ⚠ 戻り先は画面ごとに決めた固定のルート（Bug #64。検証で断るときも）。
 */
class RequestController extends Controller
{
    /** 自分の申請一覧の 1 ページの件数（§5.12） */
    private const PER_PAGE = 20;

    public function __construct(private readonly Workflow $workflow)
    {
    }

    /** 自分の申請一覧（画面④。新しい順。絞り込みは ApprovalStatus::listFilters()。設計書 §5.12） */
    public function index(Request $request): View
    {
        $filters = ApprovalStatus::listFilters();
        $filter  = $request->query('filter');
        $filter  = is_string($filter) && isset($filters[$filter]) ? $filter : null;

        // いま誰の番かを出すので、段階の担当を先に読む（CurrentHandler）
        $requests = ApprovalRequest::with(['type', 'steps.department.head', 'steps.department.reviewers', 'steps.assignee'])
            ->where('user_id', $request->user()->id)
            ->when($filter !== null, fn ($query) => $query->whereIn(
                'status',
                array_map(fn (ApprovalStatus $status) => $status->value, $filters[$filter]['statuses'])
            ))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('approvals.requests.index', compact('requests', 'filters', 'filter'));
    }

    /** 作成（`?copy={id}` で自分の申請を写す。保存するまで下書きはできない） */
    public function create(Request $request): View
    {
        $user  = $request->user();
        $draft = new ApprovalRequest();

        $copyId = $request->query('copy');
        if ($copyId !== null) {
            // 写せるのは自分の申請だけ（取り下げ・否決を含む。設計書 §5.6）
            $source = is_string($copyId) ? ApprovalRequest::where('user_id', $user->id)->find($copyId) : null;
            abort_if($source === null, 404);
            $draft->fill($this->copiedFields($source, $user));
        }

        // 所属部門が 1 つなら最初から選んでおく
        if ($draft->department_id === null) {
            $memberOf = $user->approvalDepartments()->pluck('approval_departments.id');
            if ($memberOf->count() === 1) {
                $draft->department_id = $memberOf->first();
            }
        }

        return view('approvals.requests.form', $this->formData($draft, $user));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request, null, route('approvals.requests.create'));

        // 持ち主は必ずログイン中の人（`+` だと $validated の側が勝つ。検査のルールに user_id が足されても、
        // 送られてきた値で他人の申請を作らない。Task 3 の点検の申し送り）
        $approvalRequest = ApprovalRequest::create(array_merge($validated, ['user_id' => $request->user()->id]));

        // 状態・回数・lock_version は DB の既定値で入る。読み直してから次へ渡す
        $approvalRequest->refresh();

        return $this->afterSave($request, $approvalRequest, $approvalRequest->lock_version);
    }

    public function show(Request $request, ApprovalRequest $approvalRequest): View
    {
        $user = $request->user();
        $this->assertVisible($user, $approvalRequest);

        // 回る順番の担当は今の設定から引く（CurrentHandler）。部門長・審査担当者・付け替え・判断した人を先に読む
        $approvalRequest->load([
            'applicant', 'department', 'type', 'attachments',
            'steps.department.head', 'steps.department.reviewers', 'steps.assignee', 'steps.actor',
        ]);
        // 申請者以外には最後に提出した控え（差戻し中の直しかけは出し直すまで申請者だけ。利用者の決定 2026-09-27）
        $content = RequestContent::for($user, $approvalRequest);
        // 操作の記録（新しい順。§5.12）
        $histories = ApprovalHistory::with('actor')->where('request_id', $approvalRequest->id)->orderByDesc('id')->get();
        // 提出の回ごとの控え（履歴）と、直前の回からの変更点（出し直した申請。§5.13）。どちらも提出した控えだけを使う
        // （差戻し中の直しかけは入らない。申請者以外に最後に提出した中身だけを見せる D26 とそろう）
        $revisions   = ApprovalRevision::where('request_id', $approvalRequest->id)->orderBy('round')->get();
        $permissions = RequestPermissions::for($user, $approvalRequest);

        return view('approvals.requests.show', [
            'approvalRequest' => $approvalRequest,
            'content'         => $content,
            'permissions'     => $permissions,
            // 部門長の確認の付け替え先の選択肢（付け替えられるときだけ読む。申請者本人といまの担当は出さない。D2・D7。Assignees）
            'assigneeCandidates' => $permissions->canReassign()
                ? $this->reassignCandidates($approvalRequest, $permissions->waitingStep())
                : collect(),
            'relatedLinks'    => $this->relatedLinks($content->relatedNumbers, $user),
            'histories'       => $histories,
            'newHeadNames'    => $this->newHeadNames($histories),
            'revisions'       => $revisions,
            'changes'         => $this->changesFromPreviousRound($approvalRequest, $revisions),
        ]);
    }

    public function edit(Request $request, ApprovalRequest $approvalRequest): View|RedirectResponse
    {
        $user = $request->user();
        $this->assertVisible($user, $approvalRequest);

        if (! RequestPermissions::for($user, $approvalRequest)->canEdit()) {
            return $this->notEditable($approvalRequest);
        }

        return view('approvals.requests.form', $this->formData($approvalRequest, $user));
    }

    public function update(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $user = $request->user();
        $this->assertVisible($user, $approvalRequest);

        if (! RequestPermissions::for($user, $approvalRequest)->canEdit()) {
            return $this->notEditable($approvalRequest);
        }

        $validated = $this->validated($request, $approvalRequest, route('approvals.requests.edit', $approvalRequest));
        // 編集の画面を描いたときの版（送られてこなければ 0＝作ってから一度も保存し直していない下書きの版。
        // 0 以上の整数の形でなければ -1 で必ず断る。2a の Task 15 の点検の軽微＝intval で「1abc」を 1 と読んでいた）
        $lockVersion = FormInput::lockVersion($request, 0);

        // ⚠ lock_version を条件にした 1 回の UPDATE で保存し、lock_version を 1 進める（計画 §0.3）。別のタブで先に
        //   保存・提出した申請は lock_version が進んでいるので 0 行になる（状態を変える操作も lock_version を進める）。
        //   提出も lock_version を条件に状態を進めるので、提出の途中に割り込んだ保存があれば、提出のほうが断られる
        $saved = ApprovalRequest::whereKey($approvalRequest->id)
            ->where('lock_version', $lockVersion)
            ->update(array_merge($approvalRequest->fill($validated)->getDirty(), ['lock_version' => $lockVersion + 1]));

        if ($saved !== 1) {
            // 入れた中身は残して編集の画面へ戻す。lock_version だけは今の版で描き直す（知らせたうえでの保存し直しは通す）。
            // 直せない状態に変わっていれば（別のタブで提出した）、編集の画面が詳細の画面へ送る
            return redirect()->route('approvals.requests.edit', $approvalRequest)
                ->withInput($request->except('lock_version'))
                ->with('error', '別の画面で先にこの申請が保存されていたため、保存しませんでした（この画面で入れた中身は残してあります）。この中身で保存し直すときは、もう一度押してください。');
        }

        return $this->afterSave($request, $approvalRequest->refresh(), $lockVersion + 1);
    }

    /** 下書きの削除（一度も提出していないものだけ。要件 4.5・計画 §0.5） */
    public function destroy(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $user = $request->user();
        $this->assertVisible($user, $approvalRequest);

        $refusal = redirect()->route('approvals.requests.show', $approvalRequest)
            ->with('error', '削除できるのは、一度も提出していない下書きだけです。');

        if (! RequestPermissions::for($user, $approvalRequest)->canDelete()) {
            return $refusal;
        }

        // ⚠ 状態を条件にして消す（別のタブで提出した直後の申請を消さない。提出した申請には記録があり、
        //   記録の外部キーが削除を拒むので 500 になる）。添付の行は外部キーの CASCADE で消える
        $deleted = ApprovalRequest::whereKey($approvalRequest->id)
            ->where('status', ApprovalStatus::Draft->value)
            ->where('round', 0)
            ->delete();

        if ($deleted !== 1) {
            return $refusal;
        }

        // 添付のファイルはフォルダごと消す（計画 §0.5。一度も提出していないので記録から開かれることもない）
        Storage::disk('local')->deleteDirectory("approvals/{$approvalRequest->id}");

        return redirect()->route('approvals.home')->with('success', '下書きを削除しました。');
    }

    /** 保存のあと: 下書きの保存なら編集の画面へ、提出なら Workflow に渡す（$lockVersion は保存した直後の版） */
    private function afterSave(Request $request, ApprovalRequest $approvalRequest, int $lockVersion): RedirectResponse
    {
        $edit = redirect()->route('approvals.requests.edit', $approvalRequest);

        if ($request->input('intent') !== 'submit') {
            return $edit->with('success', $approvalRequest->status === ApprovalStatus::Returned
                ? '保存しました（まだ出し直していません）。'
                : '下書きを保存しました。');
        }

        try {
            // 保存した直後の版を渡す（保存と提出のあいだに別の画面の保存・提出が入ったら断る。計画 §0.3）
            $this->workflow->submit($approvalRequest, $request->user(), $lockVersion);
        } catch (WorkflowRefused $e) {
            // 中身は保存してある。理由をすべて並べる（計画 §0.11）
            return $edit->withErrors(['submit' => $e->reasons]);
        } catch (WorkflowConflict) {
            return redirect()->route('approvals.requests.show', $approvalRequest)->with('error', WorkflowConflict::MESSAGE);
        }

        return redirect()->route('approvals.requests.show', $approvalRequest)
            ->with('success', $approvalRequest->round > 1 ? '出し直しました。' : '提出しました。');
    }

    /**
     * 形の検査（下書きは途中でも保存できる。D13）。そろっているかは提出のとき SubmitChecker が見る。
     *
     * ⚠ ルールは literal の配列で書く（JapaneseValidationMessagesTest の走査が和名の漏れを見る）。
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ApprovalRequest $current, string $redirectTo): array
    {
        $user = $request->user();

        $request->merge([
            'amount'          => self::normalizeAmount($request->input('amount')),
            'related_numbers' => RelatedNumbers::clean(is_array($request->input('related_numbers')) ? $request->input('related_numbers') : []),
        ]);
        FormInput::unifyNewlines($request, 'body');

        // 選べる種類は利用中の種類と、この申請が今使っている種類（停止していても保存はできる。D10）
        $typeIds = ApprovalType::active()->pluck('id')->push($current?->type_id)->filter()->all();
        // 選べる申請部門は自分の所属部門と、この申請が今使っている部門（提出のとき SubmitChecker が所属を見る）
        $departmentIds = $user->approvalDepartments()->pluck('approval_departments.id')->push($current?->department_id)->filter()->all();

        try {
            return $request->validate([
                'type_id'           => ['nullable', 'integer', Rule::in($typeIds)],
                'department_id'     => ['nullable', 'integer', Rule::in($departmentIds)],
                'subject'           => ['nullable', 'string', 'max:100'],
                'amount'            => ['nullable', 'integer', 'min:0', 'max:999999999999'],
                'schedule'          => ['nullable', 'string', 'max:50'],
                'body'              => ['nullable', 'string', 'max:20000'],
                'related_numbers'   => ['array', 'max:' . RelatedNumbers::MAX],
                'related_numbers.*' => ['string', 'regex:' . RelatedNumbers::PATTERN],
            ], [
                'type_id.in'              => '選んだ申請の種類は使えません。選び直してください。',
                'department_id.in'        => '申請部門は、自分の所属部門から選んでください。',
                'amount.min'              => '金額は 0 以上で入力してください。',
                'amount.max'              => '金額は 999,999,999,999 円以下で入力してください。',
                'related_numbers.max'     => '関連する決裁No は ' . RelatedNumbers::MAX . ' 個までです。',
                'related_numbers.*.regex' => '関連する決裁No「:input」の形が違います（例: R8-J-001）。',
            ], [
                'type_id'       => '申請の種類',
                'department_id' => '申請部門',
                'body'          => '重点ポイント（5W2H）',
            ]);
        } catch (ValidationException $e) {
            throw $e->redirectTo($redirectTo);
        }
    }

    /** 金額の入力を数字だけにそろえる（全角・カンマ・「円」・「¥」・空白を落とす）。数字でなければ検査で断る */
    private static function normalizeAmount(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $digits = str_replace([',', '円', '¥', '￥', ' '], '', mb_convert_kana($value, 'as'));

        return $digits === '' ? null : $digits;
    }

    /** コピーして作成で写す中身（設計書 §5.6。添付は写さない） */
    private function copiedFields(ApprovalRequest $source, User $user): array
    {
        $typeUsable       = $source->type_id !== null && ApprovalType::active()->whereKey($source->type_id)->exists();
        $departmentUsable = $source->department_id !== null && $user->approvalDepartments()->whereKey($source->department_id)->exists();

        return [
            // 停止した種類・今は所属していない部門は空にして選び直してもらう
            'type_id'         => $typeUsable ? $source->type_id : null,
            'department_id'   => $departmentUsable ? $source->department_id : null,
            'subject'         => $source->subject,
            'amount'          => $source->amount,
            'schedule'        => $source->schedule,
            'body'            => $source->body,
            'related_numbers' => $source->related_numbers ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function formData(ApprovalRequest $approvalRequest, User $user): array
    {
        $types = ApprovalType::active()->ordered()->get();
        // 停止した種類を使っている申請は、その種類も選択肢に残す（保存で消えないように。D10）
        if ($approvalRequest->type_id !== null && ! $types->contains('id', $approvalRequest->type_id)) {
            $types->push($approvalRequest->type()->firstOrFail());
        }

        $departments = $user->approvalDepartments()->with('company')->orderBy('approval_departments.sort_order')->get();
        $memberOf    = $departments->pluck('id')->all();
        // 今は所属していない部門を使っている申請も同じ（提出のときに選び直してもらう）
        if ($approvalRequest->department_id !== null && ! in_array($approvalRequest->department_id, $memberOf, true)) {
            $departments->push($approvalRequest->department()->with('company')->firstOrFail());
        }

        return [
            'approvalRequest' => $approvalRequest,
            'types'           => $types,
            'typeHeadings'    => $types->mapWithKeys(fn (ApprovalType $type) => [$type->id => $type->headings])->all(),
            'departments'     => $departments,
            'memberOf'        => $memberOf,
            'isPresident'     => $user->isApprovalPresident(),
            // 差戻しの理由（差戻し中の申請を直すとき、画面の上に出す）
            'returnNote'      => $approvalRequest->status === ApprovalStatus::Returned
                ? ApprovalStep::with('actor')
                    ->where('request_id', $approvalRequest->id)
                    ->where('round', $approvalRequest->round)
                    ->where('result', ApprovalStepResult::Return->value)
                    ->first()
                : null,
            // 今の添付（外したものを除く）。添付は 1 回保存したあとに足せる（D14）
            'attachmentList'  => $approvalRequest->exists
                ? $approvalRequest->attachments()->get()->map->listItem()->all()
                : [],
        ];
    }

    /**
     * 関連する決裁No のうち、この人が見られる申請（番号 => 申請の id）
     *
     * @param list<string> $numbers 画面に出す中身の関連する決裁No（申請者以外には最後に提出した控えのもの。RequestContent）
     * @return array<string, int>
     */
    private function relatedLinks(array $numbers, User $user): array
    {
        if ($numbers === []) {
            return [];
        }

        return RequestVisibility::apply(ApprovalRequest::query(), $user)
            ->whereIn('number', $numbers)
            ->pluck('id', 'number')
            ->all();
    }

    /**
     * 部門長の確認の付け替え先の選択肢（申請者本人と、いまの担当は出さない。D2・D7・利用者の決定 C4）。
     * いまの担当は、付け替えた段階なら付け替えた人、そうでなければ部門の今の部門長（Workflow::reassignHead() の
     * 「いまの担当と同じ人です。」と同じ読み方。そちらの断りは守りとして残す）
     *
     * @return Collection<int, User>
     */
    private function reassignCandidates(ApprovalRequest $approvalRequest, ApprovalStep $step): Collection
    {
        $current = $step->assignee_user_id ?? $step->department?->head_user_id;

        return Assignees::candidates()
            ->reject(fn (User $candidate) => $candidate->id === $approvalRequest->user_id || $candidate->id === $current)
            ->values();
    }

    /**
     * 部門長の交代（head_changed）と付け替え（reassigned。2b）の記録で担当が移った先の人の名前（id => 名前。Task 19 の C9）。
     * 記録の横の名前は操作した管理者なので、移った先を別に添える。
     * ⚠ 論理削除した人も名前を出す（記録は残る）。記録ごとに読まず、1 回の問い合わせで読む
     *
     * @param Collection<int, ApprovalHistory> $histories
     * @return array<int, string>
     */
    private function newHeadNames(Collection $histories): array
    {
        $ids = $histories->whereIn('action', ['head_changed', 'reassigned'])
            ->map(fn (ApprovalHistory $history) => $history->meta['to_user_id'] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $ids === [] ? [] : User::withTrashed()->whereKey($ids)->pluck('name', 'id')->all();
    }

    /**
     * 直前の回の控えと今の回の控えの違い（出し直した申請＝今の回が 2 以上のときだけ。設計書 §5.13）
     *
     * @param Collection<int, ApprovalRevision> $revisions
     * @return array<string, mixed>|null
     */
    private function changesFromPreviousRound(ApprovalRequest $approvalRequest, Collection $revisions): ?array
    {
        $current  = $revisions->firstWhere('round', $approvalRequest->round);
        $previous = $revisions->firstWhere('round', $approvalRequest->round - 1);

        return ($approvalRequest->round >= 2 && $current !== null && $previous !== null)
            ? RequestSnapshot::changes($previous->snapshot, $current->snapshot)
            : null;
    }

    /** 見られない申請は 404（在るかどうかを漏らさない。設計書 §5.10） */
    private function assertVisible(User $user, ApprovalRequest $approvalRequest): void
    {
        abort_unless(RequestVisibility::canView($user, $approvalRequest), 404);
    }

    private function notEditable(ApprovalRequest $approvalRequest): RedirectResponse
    {
        return redirect()->route('approvals.requests.show', $approvalRequest)->with('error', 'この申請は直せる状態ではありません。');
    }
}
