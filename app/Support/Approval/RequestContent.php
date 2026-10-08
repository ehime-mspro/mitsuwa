<?php

namespace App\Support\Approval;

use App\Models\ApprovalAttachment;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * 詳細の画面に出す申請の中身（種類・申請部門・件名・金額・実施時期・本文・関連する決裁No・添付）。
 *
 * **申請者以外には、状態を問わず最後に提出した控え（`approval_revisions` の最新の回）を出す**
 * （利用者の決定 2026-09-27。要件 4.8「作成中は申請者だけが見える」）。
 * - 差戻し中の直しかけ（件名・金額・本文・その回に足した添付など）は、出し直すまで申請者だけが見る
 * - 差戻し中に直して保存してから取り下げた申請も、提出していない中身は申請者だけに残る（要件 4.5）
 * - 回覧中・決裁のあとは、今の中身と控えが同じ（直せるのは下書きと差戻し中だけ。設計書 §5.11）。
 *   種類と部門の名前だけは、提出したときの名前が出る（控えは名前も残す。RequestSnapshot）
 * 申請者本人は、いつも今の中身（編集中のもの）を見る。
 *
 * ⚠ 見てよいかどうか（RequestVisibility）は別。ここは「見られる」前提で、どの版の中身を見せるかだけを決める。
 * ⚠ 添付を開く・ダウンロードする経路も mayOpen() で同じ出し分けをする（Task 14）。
 */
final class RequestContent
{
    /**
     * @param list<string>                        $relatedNumbers
     * @param Collection<int, ApprovalAttachment> $attachments
     * @param bool                                $isLastSubmission 最後に提出した控えから作った（申請者以外が見るとき）
     */
    private function __construct(
        public readonly ?string $typeName,
        public readonly ?string $departmentName,
        public readonly ?string $subject,
        public readonly ?int $amount,
        public readonly ?string $schedule,
        public readonly ?string $body,
        public readonly array $relatedNumbers,
        public readonly Collection $attachments,
        public readonly bool $isLastSubmission,
    ) {
    }

    /** この人に見せる中身 */
    public static function for(User $viewer, ApprovalRequest $request): self
    {
        if (RequestPermissions::for($viewer, $request)->isApplicant()) {
            return self::current($request);
        }

        // 控えは提出と同じトランザクションで作るので、提出した申請には必ずある（一度も提出していない下書きは
        // 申請者しか見られない）。見つからなければ今の中身へ落とさずに 404 にする（分からないときは見せない）
        $revision = $request->submittedRevision() ?? throw (new ModelNotFoundException())->setModel(ApprovalRevision::class);

        return self::fromRevision($request, $revision);
    }

    /** 今の中身（申請の行） */
    private static function current(ApprovalRequest $request): self
    {
        return new self(
            typeName: $request->type?->name,
            departmentName: $request->department?->name,
            subject: $request->subject,
            amount: $request->amount,
            schedule: $request->schedule,
            body: $request->body,
            relatedNumbers: $request->related_numbers ?? [],
            attachments: $request->attachments,
            isLastSubmission: false,
        );
    }

    /** 最後に提出した控えの中身。添付は控えに入った行を引く（外したあとも行とファイルは残る。計画 §0.5。並びは控えと同じ id の順） */
    private static function fromRevision(ApprovalRequest $request, ApprovalRevision $revision): self
    {
        $snapshot = $revision->snapshot;
        $ids      = array_column($snapshot['attachments'] ?? [], 'id');

        return new self(
            typeName: $snapshot['type']['name'] ?? null,
            departmentName: $snapshot['department']['name'] ?? null,
            subject: $snapshot['subject'] ?? null,
            amount: $snapshot['amount'] ?? null,
            schedule: $snapshot['schedule'] ?? null,
            body: $snapshot['body'] ?? null,
            relatedNumbers: $snapshot['related_numbers'] ?? [],
            attachments: ApprovalAttachment::where('request_id', $request->id)->whereKey($ids)->orderBy('id')->get(),
            isLastSubmission: true,
        );
    }

    /** 金額の表示（税抜・末尾に「円」。ApprovalRequest::amountLabel() と同じ形。規約: `¥` 接頭辞 NG） */
    public function amountLabel(): ?string
    {
        return $this->amount === null ? null : number_format($this->amount) . '円';
    }

    /**
     * この人がこの添付を開いてよいか（申請を見てよいかは、呼ぶ側が先に RequestVisibility で確かめる）。
     *
     * 申請者は自分の申請の添付をすべて開ける。ほかの人は、いずれかの回の控えに入った添付だけ
     * （直しかけの回に足した添付は、出し直すまで申請者だけ。控えに入った添付は、外したあとも開ける＝2b の履歴）。
     */
    public static function mayOpen(User $viewer, ApprovalAttachment $attachment): bool
    {
        if (RequestPermissions::for($viewer, $attachment->request)->isApplicant()) {
            return true;
        }

        return ApprovalRevision::where('request_id', $attachment->request_id)->get()
            ->contains(fn (ApprovalRevision $revision) => in_array($attachment->id, array_column($revision->snapshot['attachments'] ?? [], 'id'), true));
    }
}
