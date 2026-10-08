<?php

namespace App\Support\Approval;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * 決裁台帳の 1 行（画面の行とカード・Excel の 1 行。要件 10・段階4 設計書 §5.8・§5.9・D19・D23）。
 *
 * ⚠ 中身（件名・種類・申請部門・金額・実施時期・関連する決裁No）は、申請者本人には今の中身、ほかの人には**最後に提出した
 *   控え**（RequestContent と同じ出し分け。名前も提出したときのもの）。台帳は一覧なので控えは先に読んだもの（lastRevision）を使う。
 *   控えが無いとき（提出した申請には必ずある）は今の中身へ落とさずに空にする（分からないときは見せない）。
 * ⚠ 審査の意見・コメントと条件（条可のコメント）は今の回の段階から（PDF と同じく今の回だけ。D4）。
 * ⚠ 控えは今の回（`round` が申請の回と同じ）のものだけを使う（詳細の RequestContent と同じ引き方。今の回の控えが欠けていれば空にする）。
 * ⚠ 段階5: 工事原価・粗利益金額・粗利率は明細表の「合計金額」の行（5W2H の種類は空）、契約予定日は追加の入力欄（使わない種類は空）。
 */
final class LedgerRow
{
    /** @param list<string> $relatedNumbers */
    private function __construct(
        public readonly int $id,
        public readonly ?string $number,
        public readonly ?CarbonInterface $decidedAt,
        public readonly ?string $decisionLabel,
        public readonly ?string $subject,
        public readonly ?string $typeName,
        public readonly ?string $departmentName,
        public readonly ?string $applicantName,
        public readonly ?int $amount,
        public readonly ?string $schedule,
        public readonly array $relatedNumbers,
        public readonly ?CarbonInterface $submittedAt,
        public readonly ?string $reviewResult,
        public readonly ?string $reviewComment,
        public readonly ?string $condition,
        public readonly string $statusLabel,
        public readonly string $statusStyle,
        public readonly ?int $cost = null,
        public readonly ?int $profit = null,
        public readonly ?float $rate = null,
        public readonly ?CarbonInterface $contractDate = null,
    ) {
    }

    /** 関係（applicant・type・department・lastRevision・steps）は先に読んでおく（Ledger::rows()） */
    public static function for(User $viewer, ApprovalRequest $request): self
    {
        $own      = $request->user_id === $viewer->id;
        $snapshot = $own ? [] : ($request->submittedRevision()?->snapshot ?? []);
        $table    = $own ? $request->amount_table : ($snapshot['amount_table'] ?? null);
        $total    = is_array($table) ? AmountTable::totals($table)['total'] : null;
        $contract = $own ? $request->contract_date : RequestExtras::ordered($snapshot['extras'] ?? null)['contract_date'] ?? null;
        $steps    = $request->currentSteps()->keyBy(fn (ApprovalStep $s) => $s->kind->value);
        $review    = self::done($steps->get(ApprovalStepKind::Review->value));
        $president = self::done($steps->get(ApprovalStepKind::President->value));

        return new self(
            id: $request->id,
            number: $request->number,
            decidedAt: $request->decided_at,
            decisionLabel: $request->decision?->label(),
            subject: $own ? $request->subject : ($snapshot['subject'] ?? null),
            typeName: $own ? $request->type?->name : ($snapshot['type']['name'] ?? null),
            departmentName: $own ? $request->department?->name : ($snapshot['department']['name'] ?? null),
            applicantName: $request->applicant?->name,
            amount: $own ? $request->amount : (isset($snapshot['amount']) ? (int) $snapshot['amount'] : null),
            schedule: $own ? $request->schedule : ($snapshot['schedule'] ?? null),
            relatedNumbers: array_values(array_map('strval', $own ? ($request->related_numbers ?? []) : ($snapshot['related_numbers'] ?? []))),
            submittedAt: $request->last_submitted_at,
            reviewResult: $review?->result?->labelFor(ApprovalStepKind::Review),
            reviewComment: $review?->comment,
            condition: $request->decision === ApprovalDecision::Conditional ? $president?->comment : null,
            statusLabel: $request->statusLabel(),
            statusStyle: $request->status->badgeStyle(),
            cost: $total['cost'] ?? null,
            profit: $total['profit'] ?? null,
            rate: $total['rate'] ?? null,
            contractDate: is_string($contract) ? CarbonImmutable::createFromFormat('!Y-m-d', $contract, 'UTC') ?: null : $contract,
        );
    }

    /** 金額の表示（税抜・末尾に「円」。規約: `¥` 接頭辞 NG） */
    public function amountLabel(): ?string
    {
        return $this->amount === null ? null : number_format($this->amount) . '円';
    }

    /** 判断した段階だけ（待ち・省略・取り消しは意見もコメントも無い） */
    private static function done(?ApprovalStep $step): ?ApprovalStep
    {
        return $step?->status === ApprovalStepStatus::Done ? $step : null;
    }
}
