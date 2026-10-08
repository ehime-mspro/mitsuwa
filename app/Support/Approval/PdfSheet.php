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
 * - 明細表の種類（段階5 §5.7）: 重点ポイントの欄の代わりに、紙の住宅の様式の「（記）」の並び（明細表 → 追加の入力欄 → 定型文 → 補足）。
 *   5W2H の種類も、種類で使えば追加の入力欄と定型文を本文の下に載せる
 *
 * ⚠ 下書きは出さない（D17）。呼ぶ側（`RequestPdfController`）が先に断る。
 */
final class PdfSheet
{
    /** 紙の本文の罫線の行数（短い本文でも紙の様式と同じ高さにする） */
    public const MIN_BODY_ROWS = 11;

    /** 本文の 1 行に入れる文字数の上限（mPDF は表の 1 行をページの途中で切れないので、長い行は分ける） */
    public const BODY_ROW_CHARS = 300;

    /** 明細表の種類の補足の罫線の行数（紙の住宅の様式は自由記入 2 行） */
    public const MIN_SUPPLEMENT_ROWS = 2;

    /** 社長の判断 → 紙の決裁の欄の言葉 */
    private const DECISION_MARKS = ['approve' => '可', 'conditional' => '条可', 'return' => '差戻', 'reject' => '否'];

    /** 審査の意見 → 紙の審査部門の欄の言葉 */
    private const REVIEW_MARKS = ['ok' => '可', 'hold' => '保留', 'ng' => '否'];

    /**
     * 紙の住宅の様式の「（記）」で、定型文の前に載せる追加の欄（坪数・坪単価）と後に載せる欄（担当者・契約予定日）（段階5 設計書 §5.7。
     * 5W2H の種類で使うときも、本文の下に同じ並びで載せる）
     */
    public const EXTRAS_BEFORE_FIXED_TEXT = ['tsubo', 'tsubo_price'];

    public const EXTRAS_AFTER_FIXED_TEXT = ['staff', 'contract_date'];

    /**
     * @param list<string> $relatedNumbers
     * @param list<string> $bodyRows
     * @param list<string> $attachmentNames
     * @param list<array{kind: string, name: string, sale: string, cost: string, profit: string, rate: string}>|null $amountRows 明細表の種類だけ
     * @param array<string, array{0: string, 1: string}> $extras 追加の入力欄（キー => 名前・表示。RequestExtras::FIELDS の並び）
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
        public readonly ?array $amountRows = null,
        public readonly array $extras = [],
        public readonly ?string $fixedText = null,
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
            bodyRows: self::bodyRows($content->body, $content->usesTable() ? self::MIN_SUPPLEMENT_ROWS : self::MIN_BODY_ROWS),
            attachmentNames: $content->attachments->pluck('original_name')->values()->all(),
            reviewDepartmentName: $review?->department?->name,
            reviewMark: self::mark($review, self::REVIEW_MARKS),
            reviewStamp: $review ? Stamp::forStep($review) : null,
            reviewComment: self::doneComment($review),
            outputAt: JapanTime::format(now()),
            outputBy: $viewer->name,
            fileName: '決裁申請書_' . ($request->number ?? '申請' . $request->id) . '.pdf',
            amountRows: $content->amountTable === null ? null : self::amountRows($content->amountTable),
            extras: self::extrasOf($content->extras),
            fixedText: $content->fixedText,
        );
    }

    /**
     * 追加の入力欄のうち、名指しした欄（名前・表示。空は「—」。並びは RequestExtras::FIELDS のとおり）
     *
     * @param list<string> $keys
     * @return list<array{0: string, 1: string}>
     */
    public function extraPairs(array $keys): array
    {
        return array_values(array_intersect_key($this->extras, array_flip($keys)));
    }

    /**
     * @param array<string, string|int|null> $extras RequestContent の extras（キー => 値）
     * @return array<string, array{0: string, 1: string}>
     */
    private static function extrasOf(array $extras): array
    {
        $pairs = [];
        foreach ($extras as $key => $value) {
            $pairs[$key] = [RequestExtras::FIELDS[$key], RequestExtras::display($key, $value) ?? '—'];
        }

        return $pairs;
    }

    /**
     * 明細表の紙面の行（前半の行 → 「計」→ 後半の行 → 「合計金額」。名前も金額も無い自由行は載せない。金額は「28,500,000円」）
     *
     * @param array<string, mixed> $table
     * @return list<array{kind: string, name: string, sale: string, cost: string, profit: string, rate: string}>
     */
    public static function amountRows(array $table): array
    {
        $totals = AmountTable::totals($table);
        $rows   = [];
        foreach (AmountTable::shownRows($table, 'upper') as $row) {
            $rows[] = self::amountRow('row', $row['name'] ?? '（項目名なし）', $row);
        }
        if ($totals['subtotal'] !== null) {
            $rows[] = self::amountRow('subtotal', '計', $totals['subtotal']);
        }
        foreach (AmountTable::shownRows($table, 'lower') as $row) {
            $rows[] = self::amountRow('row', $row['name'] ?? '（項目名なし）', $row);
        }
        $rows[] = self::amountRow('total', '合計金額', $totals['total']);

        return $rows;
    }

    /**
     * @param array{sale: ?int, cost: ?int, profit: ?int, rate: ?float} $line
     * @return array{kind: string, name: string, sale: string, cost: string, profit: string, rate: string}
     */
    private static function amountRow(string $kind, string $name, array $line): array
    {
        return [
            'kind'   => $kind,
            'name'   => $name,
            'sale'   => AmountTable::yen($line['sale']),
            'cost'   => AmountTable::yen($line['cost']),
            'profit' => AmountTable::yen($line['profit']),
            'rate'   => $line['profit'] === null ? '' : AmountTable::rateLabel($line['rate']),
        ];
    }

    /**
     * 本文を罫線の行に分ける。改行で分け、長い行は BODY_ROW_CHARS 文字ずつに分け、$minRows 行に満たなければ空の行で埋める
     * （5W2H の種類は紙の 11 行・明細表の種類の補足は紙の 2 行）。
     *
     * @return list<string>
     */
    public static function bodyRows(?string $body, int $minRows = self::MIN_BODY_ROWS): array
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

        return array_pad($rows, $minRows, '');
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
