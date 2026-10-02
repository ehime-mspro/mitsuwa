<?php

namespace App\Support\Approval;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepStatus;
use App\Enums\UserStatus;
use App\Mail\ApprovalMailable;
use App\Mail\ApprovalNoticeMail;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalHistory;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalNotice;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * 知らせを出す 1 か所（段階3 設計書 §5.1・§5.4・§5.5）。場面ごとに宛先を決め、共通の決まりで絞り、1 人 1 つのお知らせを
 * 作ってメールを積む。
 *
 * ⚠ **呼ぶ側のトランザクションの中で呼ぶ**（Workflow の各操作・部門の管理・社長の指定）。お知らせの行もメールの `jobs` の行も
 *   同じ DB なので、操作が途中で失敗して巻き戻ればどちらも残らない（PasswordReissuer と同じ。`QUEUE_CONNECTION=database` で
 *   `DB_QUEUE_CONNECTION` が空だから成り立つ。redis などに替えると「起きなかった操作の知らせが届く」が無音で戻る）
 * ⚠ 宛先・文の材料・リンクは操作の時点で決めてメールに持たせる（キューの中で設定や route() を読まない。本番で /index.php が抜ける）
 *
 * 共通の決まり（§5.4）: 1 操作した本人には出さない ／ 2 1 回の操作で同じ人に同じ申請の知らせは 1 つ（先に足したものを残すので、
 * 対応が要る知らせを先に足す）／ 3 無効の人・削除した人には出さない ／ 4 メールはアドレスがあり許可したドメインの人だけ
 * （ほかの人はお知らせだけ）／ 5 担当の番の知らせは申請者本人に出さない（D16）／ 6 件名と申請部門は最後に提出した控えから
 * （差戻し中の直しかけを出さない。D26）／ 7 使い始める前は何もしない（§5.2）
 */
final class Notifier
{
    /** @var array<string, array{user: User, request: ApprovalRequest, notice: Notice}> 「申請の id:人の id」 => 知らせ */
    private array $entries = [];

    private function __construct(private readonly User $actor)
    {
    }

    /** 場面 1: 自分の番が来た（提出・出し直し・部門長の承認・審査の意見のあと。今の回で待っている段階の担当へ） */
    public static function turnArrived(ApprovalRequest $request, User $actor): void
    {
        $step = StepHandlers::waitingStep($request);

        if ($step !== null) {
            (new self($actor))->add(StepHandlers::for($step, $request), $request, NoticeText::turn($step->kind))->send();
        }
    }

    /**
     * 場面 2: 担当が入れ替わった（付け替え・部門長の交代・社長の交代・審査担当者の追加。D1）。新しい担当は呼ぶ側が明示で渡す
     * （部門長の交代は部門の行を更新する前に呼ばれるので、StepHandlers では前の部門長が出る）
     *
     * @param iterable<ApprovalStep> $steps 待っている段階（1 つの操作で移ったもの。申請ごとに 1 つずつ）
     * @param Collection<int, User>|User|null $to 新しい担当
     */
    public static function handlerChanged(User $actor, iterable $steps, Collection|User|null $to, string $why): void
    {
        $notifier = new self($actor);

        foreach ($steps as $step) {
            $notifier->add($to, $step->request, NoticeText::handlerChanged($step->kind, $why));
        }

        $notifier->send();
    }

    /**
     * 場面 2（審査担当者の追加）: 足した人に、その審査部門で審査を待っている申請ごとに（まだ届いていない審査は、届いたときに
     * 場面 1 で知らせる）
     *
     * @param Collection<int, User> $added 足した審査担当者（前後の差）
     */
    public static function reviewersAdded(User $actor, ApprovalDepartment $department, Collection $added): void
    {
        if ($added->isEmpty()) {
            return;
        }

        $steps = ApprovalStep::with('request')
            ->where('kind', ApprovalStepKind::Review->value)
            ->where('status', ApprovalStepStatus::Waiting->value)
            ->where('department_id', $department->id)
            ->orderBy('id')
            ->get();

        self::handlerChanged($actor, $steps, $added, NoticeText::REVIEWER_ADDED);
    }

    /** 場面 2（社長の交代）: 新しい社長に、社長の決裁を待っているすべての申請ごとに */
    public static function presidentChanged(User $actor, User $president): void
    {
        $steps = ApprovalStep::with('request')
            ->where('kind', ApprovalStepKind::President->value)
            ->where('status', ApprovalStepStatus::Waiting->value)
            ->orderBy('id')
            ->get();

        self::handlerChanged($actor, $steps, $president, NoticeText::PRESIDENT_CHANGED);
    }

    /** 場面 3: 差戻しされた（申請者へ） */
    public static function returned(ApprovalRequest $request, User $actor, ApprovalStepKind $by): void
    {
        (new self($actor))->add($request->applicant, $request, NoticeText::returned($by))->send();
    }

    /**
     * 場面 4・5: 社長の判断。申請者（条可なら条件の確認のお願い）と、その回で部門長として判断した人・意見を入れた審査担当者
     * （段階の actor。部門長の段階を省いた回は部門長がいない。D7）
     */
    public static function decided(ApprovalRequest $request, User $actor, ApprovalDecision $decision): void
    {
        $toApplicant = $decision === ApprovalDecision::Conditional ? NoticeText::conditionRequested() : NoticeText::decided($decision);

        (new self($actor))
            ->add($request->applicant, $request, $toApplicant)
            ->add(self::actorsOf($request, ApprovalStepKind::Head, ApprovalStepKind::Review), $request, NoticeText::decided($decision))
            ->send();
    }

    /** 場面 6: 条件が確認された（決裁した社長と、部門長として判断した人。お知らせだけ。D7） */
    public static function conditionConfirmed(ApprovalRequest $request, User $actor): void
    {
        (new self($actor))
            ->add(self::actorsOf($request, ApprovalStepKind::President, ApprovalStepKind::Head), $request, NoticeText::conditionConfirmed())
            ->send();
    }

    /**
     * 場面 7: 取り下げられた（その時点の担当。管理者の代理の取り下げなら申請者にも。D8）
     *
     * @param Collection<int, User> $handlers 取り下げの前に待っていた段階の担当（段階を打ち切る前に StepHandlers::ofWaiting() で取る）
     */
    public static function withdrawn(ApprovalRequest $request, User $actor, Collection $handlers, bool $byAdmin): void
    {
        $notice = NoticeText::withdrawn($byAdmin);

        (new self($actor))
            ->add($handlers, $request, $notice)
            ->add($byAdmin ? $request->applicant : null, $request, $notice)
            ->send();
    }

    /**
     * 場面 8: 操作が取り消された。番が戻った人（戻った段階の今の担当・条件の確認の取り消しなら申請者）に「もう一度」を先に足し、
     * 取り消された操作をした人と申請者に「取り消されました」（重なった人は先の知らせだけ。決まり 2）。
     * 決裁のあとの取り消しで、場面 4 を受け取った部門長・審査担当者には出さない（D10）
     */
    public static function undone(ApprovalRequest $request, User $admin, ApprovalHistory $target): void
    {
        $label    = $target->label();
        $step     = StepHandlers::waitingStep($request);
        $notifier = new self($admin);

        if ($step !== null) {
            $notifier->add(StepHandlers::for($step, $request), $request, NoticeText::undoneTurn($label, $step->kind));
        } elseif ($request->status === ApprovalStatus::Condition) {
            $notifier->add($request->applicant, $request, NoticeText::undoneTurn($label, null));
        }

        $notifier
            ->add($target->actor, $request, NoticeText::undone($label))
            ->add($request->applicant, $request, NoticeText::undone($label))
            ->send();
    }

    /** その回の段階で判断した人（段階の並び順） @return Collection<int, User> */
    private static function actorsOf(ApprovalRequest $request, ApprovalStepKind ...$kinds): Collection
    {
        return ApprovalStep::with('actor')
            ->where('request_id', $request->id)
            ->where('round', $request->round)
            ->whereIn('kind', array_map(fn (ApprovalStepKind $kind) => $kind->value, $kinds))
            ->get()
            ->sortBy(fn (ApprovalStep $step) => array_search($step->kind, $kinds, true))
            ->map(fn (ApprovalStep $step) => $step->actor)
            ->filter()
            ->values();
    }

    /** @param Collection<int, User>|User|null $users */
    private function add(Collection|User|null $users, ApprovalRequest $request, Notice $notice): self
    {
        foreach ($users instanceof User ? [$users] : ($users ?? []) as $user) {
            $this->entries[$request->id . ':' . $user->id] ??= ['user' => $user, 'request' => $request, 'notice' => $notice];
        }

        return $this;
    }

    private function send(): void
    {
        // ⚠ 行を作らずに読む（部門の管理・社長の指定は使い始める前から呼ばれる。§5.2）
        if ($this->entries === [] || ! ApprovalSetting::launchedForMenu()) {
            return;
        }

        $contexts = [];

        foreach ($this->entries as ['user' => $user, 'request' => $request, 'notice' => $notice]) {
            if (! $this->receives($user, $request, $notice)) {
                continue;
            }

            $context = $contexts[$request->id] ??= self::context($request);

            ApprovalNotice::create([
                'id'                  => (string) Str::orderedUuid(),
                'type'                => ApprovalNotice::TYPE,
                'notifiable_type'     => $user->getMorphClass(),
                'notifiable_id'       => $user->id,
                'approval_request_id' => $request->id,
                'data'                => [
                    'scene'      => $notice->scene,
                    'headline'   => $notice->headline,
                    'subject'    => $context['subject'],
                    'number'     => $context['number'],
                    'applicant'  => $context['applicant'],
                    'department' => $context['department'],
                    'action'     => $notice->action,
                    'actor'      => $this->actor->name,
                ],
            ]);

            if ($notice->mailed && ApprovalMailDomain::allows($user->email)) {
                Mail::to($user->email)->queue(new ApprovalNoticeMail($user->name, $notice, $context));
            }
        }
    }

    /** 共通の決まり 1・3・5 */
    private function receives(User $user, ApprovalRequest $request, Notice $notice): bool
    {
        return $user->id !== $this->actor->id
            && ! $user->trashed()
            && $user->status === UserStatus::Active
            && ! ($notice->handlerTurn && $user->id === $request->user_id);
    }

    /**
     * 申請ごとの材料（件名と申請部門は最後に提出した控えから。決まり 6）とリンク（操作の画面のリクエストから作る＝本番の
     * /index.php が入る）
     *
     * @return array{subject: string, number: ?string, applicant: string, department: string, url: string}
     */
    private static function context(ApprovalRequest $request): array
    {
        $snapshot = ApprovalRevision::where('request_id', $request->id)->where('round', $request->round)->first()?->snapshot ?? [];

        return [
            'subject'    => ApprovalMailable::oneLine($snapshot['subject'] ?? $request->subject),
            'number'     => $request->number,
            'applicant'  => ApprovalMailable::oneLine($request->applicant?->name),
            'department' => ApprovalMailable::oneLine($snapshot['department']['name'] ?? null),
            'url'        => route('approvals.requests.show', $request),
        ];
    }
}
