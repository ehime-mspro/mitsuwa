<?php

namespace App\Support\Approval;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\JapanTime;
use Illuminate\Support\Collection;

/**
 * 決裁申請書の PDF の紙面に載せるもの（要件 9.2・段階4 設計書 §5.6・D4・D14〜D17）。
 *
 * - 中身は申請の詳細と同じ（`RequestContent::for`。申請者以外には最後に提出した控え）
 * - 判断と印は**今の回だけ**（D4）。前の回のやり取りは画面の履歴で見る
 * - 決裁の前は決裁No・決裁日・判断の○が空欄
 *
 * ⚠ 下書きは出さない（D17）。呼ぶ側（`RequestPdfController`）が先に断る。
 */
final class PdfSheet
{
    /** 紙の本文の罫線の行数（短い本文でも紙の様式と同じ高さにする） */
    public const MIN_BODY_ROWS = 11;

    /** 本文の 1 行に入れる文字数の上限（mPDF は表の 1 行をページの途中で切れないので、長い行は分ける） */
    public const BODY_ROW_CHARS = 300;

    /** 社長の判断 → 紙の決裁の欄の言葉 */
    private const DECISION_MARKS = ['approve' => '可', 'conditional' => '条可', 'return' => '差戻', 'reject' => '否'];

    /** 審査の意見 → 紙の審査部門の欄の言葉 */
    private const REVIEW_MARKS = ['ok' => '可', 'hold' => '保留', 'ng' => '否'];

    /**
     * @param list<string> $relatedNumbers
     * @param list<string> $bodyRows
     * @param list<string> $attachmentNames
     */
    private function __construct(
        public readonly string $statusLabel,
        public readonly int $round,
        public readonly ?string $number,
        public readonly ?string $decidedOn,
        public readonly ?string $submittedOn,
        public readonly ?string $receivedOn,
        public readonly ?string $decisionMark,
        public readonly ?Stamp $presidentStamp,
        public readonly ?string $presidentComment,
        public readonly ?string $conditionConfirmedAt,
        public readonly ?string $departmentName,
        public readonly ?string $applicantName,
        public readonly ?Stamp $headStamp,
        public readonly ?string $headComment,
        public readonly bool $headSkipped,
        public readonly ?string $subject,
        public readonly ?string $amountLabel,
        public readonly ?string $schedule,
        public readonly array $relatedNumbers,
        public readonly array $bodyRows,
        public readonly array $attachmentNames,
        public readonly ?string $reviewDepartmentName,
        public readonly ?string $reviewMark,
        public readonly ?Stamp $reviewStamp,
        public readonly ?string $reviewComment,
        public readonly string $outputAt,
        public readonly string $outputBy,
        public readonly string $fileName,
    ) {
    }

    public static function for(User $viewer, ApprovalRequest $request): self
    {
        $content = RequestContent::for($viewer, $request);
        /** @var Collection<int, ApprovalStep> $steps */
        $steps     = $request->currentSteps()->keyBy(fn (ApprovalStep $s) => $s->kind->value);
        $head      = $steps->get(ApprovalStepKind::Head->value);
        $review    = $steps->get(ApprovalStepKind::Review->value);
        $president = $steps->get(ApprovalStepKind::President->value);

        return new self(
            statusLabel: $request->statusLabel(),
            round: $request->round,
            number: $request->number,
            decidedOn: JapanTime::format($request->decided_at, 'Y年n月j日'),
            submittedOn: JapanTime::format($request->last_submitted_at, 'Y年n月j日'),
            receivedOn: JapanTime::format($review?->arrived_at, 'Y年n月j日'),
            decisionMark: self::mark($president, self::DECISION_MARKS),
            presidentStamp: $president ? Stamp::forStep($president) : null,
            presidentComment: self::doneComment($president),
            conditionConfirmedAt: $request->decision === ApprovalDecision::Conditional && $request->status === ApprovalStatus::Approved
                ? JapanTime::format($request->finished_at) : null,
            departmentName: $content->departmentName,
            applicantName: $request->applicant?->name,
            headStamp: $head ? Stamp::forStep($head) : null,
            headComment: self::doneComment($head),
            headSkipped: $head?->status === ApprovalStepStatus::Skipped,
            subject: $content->subject,
            amountLabel: $content->amountLabel(),
            schedule: $content->schedule,
            relatedNumbers: array_values($content->relatedNumbers),
            bodyRows: self::bodyRows($content->body),
            attachmentNames: $content->attachments->pluck('original_name')->values()->all(),
            reviewDepartmentName: $review?->department?->name,
            reviewMark: self::mark($review, self::REVIEW_MARKS),
            reviewStamp: $review ? Stamp::forStep($review) : null,
            reviewComment: self::doneComment($review),
            outputAt: JapanTime::format(now()),
            outputBy: $viewer->name,
            fileName: '決裁申請書_' . ($request->number ?? '申請' . $request->id) . '.pdf',
        );
    }

    /**
     * 本文を罫線の行に分ける。改行で分け、長い行は BODY_ROW_CHARS 文字ずつに分け、MIN_BODY_ROWS 行に満たなければ空の行で埋める。
     *
     * @return list<string>
     */
    public static function bodyRows(?string $body): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', rtrim((string) $body)) as $line) {
            foreach ($line === '' ? [''] : mb_str_split($line, self::BODY_ROW_CHARS) as $chunk) {
                $rows[] = $chunk;
            }
        }

        if ($rows === ['']) {
            $rows = [];
        }

        return array_pad($rows, self::MIN_BODY_ROWS, '');
    }

    /** @param array<string, string> $marks */
    private static function mark(?ApprovalStep $step, array $marks): ?string
    {
        if ($step?->status !== ApprovalStepStatus::Done || ! $step->result instanceof ApprovalStepResult) {
            return null;
        }

        return $marks[$step->result->value] ?? null;
    }

    private static function doneComment(?ApprovalStep $step): ?string
    {
        return $step?->status === ApprovalStepStatus::Done ? $step->comment : null;
    }
}
