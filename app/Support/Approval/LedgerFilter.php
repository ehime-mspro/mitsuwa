<?php

namespace App\Support\Approval;

use App\Enums\ApprovalDecision;
use App\Support\JapanTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * 決裁台帳（⑤）の絞り込みの条件（要件 10・段階4 設計書 §5.8・D19〜D21）。画面・Excel・出力の記録が同じものを使う。
 *
 * ⚠ 台帳は GET のフォーム。読み取れない値（形の違う日付・長すぎる文字・配列など）は**断らずに外し**、外した項目の名前を
 *   `ignored` に残して画面で知らせる。入力の検査（validate()）で前の画面へ戻すと、GET の戻り先がこの画面そのものになり、
 *   同じ URL を開き直し続ける（前の URL はセッションに今の GET が入る）。
 * ⚠ 前後の空白（全角を含む）はアプリ全体の前処理 TrimStrings が外し、空は ConvertEmptyStringsToNull が null にする。
 */
final class LedgerFilter
{
    /** 状態の絞り込み（キーは URL の `?status=`。既定は決裁No の付いたもの。§5.8） */
    public const STATUSES = [
        'numbered'  => '決裁No の付いたもの',
        'progress'  => '進行中',
        'withdrawn' => '取り下げ',
        'all'       => 'すべて',
    ];

    public const DEFAULT_STATUS = 'numbered';

    /** キーワード・申請者の文字数の上限（超えたら外して知らせる。画面の maxlength と同じ） */
    public const KEYWORD_MAX = 100;

    public const APPLICANT_MAX = 50;

    /** 外した項目の名前（画面の知らせに出す） */
    private const LABELS = [
        'year'       => '年度',
        'department' => '申請部門',
        'type'       => '申請の種類',
        'applicant'  => '申請者',
        'from'       => '決裁日（から）',
        'to'         => '決裁日（まで）',
        'status'     => '状態',
        'decision'   => '判断',
        'q'          => 'キーワード',
    ];

    /** @param list<string> $ignored 読み取れずに外した項目の名前 */
    private function __construct(
        public readonly ?int $year,
        public readonly ?int $departmentId,
        public readonly ?int $typeId,
        public readonly ?string $applicant,
        public readonly ?CarbonImmutable $decidedFrom,
        public readonly ?CarbonImmutable $decidedTo,
        public readonly string $status,
        public readonly ?ApprovalDecision $decision,
        public readonly ?string $keyword,
        public readonly array $ignored,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $ignored = [];
        $read    = function (string $key, callable $parse) use ($request, &$ignored): mixed {
            $raw = $request->query($key);
            if ($raw === null || $raw === '') {
                return null;
            }
            // 壊れた文字（不正な UTF-8。手で打った URL）も外す。そのままだと語に分けられず黙って外れ、記録の JSON にもできない
            $value = is_string($raw) && mb_check_encoding($raw, 'UTF-8') ? $parse($raw) : null;
            if ($value === null) {
                $ignored[] = self::LABELS[$key];
            }

            return $value;
        };

        $year       = $read('year', fn (string $v) => preg_match('/^\d{4}$/', $v) ? (int) $v : null);
        $department = $read('department', self::positiveInt(...));
        $type       = $read('type', self::positiveInt(...));
        $applicant  = $read('applicant', fn (string $v) => mb_strlen($v) <= self::APPLICANT_MAX ? $v : null);
        $from       = $read('from', self::calendarDay(...));
        $to         = $read('to', self::calendarDay(...));
        $status     = $read('status', fn (string $v) => array_key_exists($v, self::STATUSES) ? $v : null);
        $decision   = $read('decision', fn (string $v) => ApprovalDecision::tryFrom($v));
        $keyword    = $read('q', fn (string $v) => mb_strlen($v) <= self::KEYWORD_MAX ? $v : null);

        return new self($year, $department, $type, $applicant, $from, $to, $status ?? self::DEFAULT_STATUS, $decision, $keyword, $ignored);
    }

    /** 既定のまま（何も絞り込んでいない。状態は決裁No の付いたもの） */
    public static function defaults(): self
    {
        return new self(null, null, null, null, null, null, self::DEFAULT_STATUS, null, null, []);
    }

    /**
     * 画面の選択肢に無い年度・部門・種類を外す（手で打った URL や古いリンク）。外さないと、条件としては効いて 0 件になるのに
     * プルダウンは「すべて」に見え、プルダウンを 1 つ変えるとその条件が黙って外れる
     *
     * @param list<int> $years
     * @param list<int> $departmentIds
     * @param list<int> $typeIds
     */
    public function within(array $years, array $departmentIds, array $typeIds): self
    {
        $ignored = $this->ignored;
        $keep    = function (?int $value, array $choices, string $key) use (&$ignored): ?int {
            if ($value === null || in_array($value, $choices, true)) {
                return $value;
            }
            $ignored[] = self::LABELS[$key];

            return null;
        };

        $year       = $keep($this->year, $years, 'year');
        $department = $keep($this->departmentId, $departmentIds, 'department');
        $type       = $keep($this->typeId, $typeIds, 'type');

        return new self($year, $department, $type, $this->applicant, $this->decidedFrom, $this->decidedTo, $this->status, $this->decision, $this->keyword, $ignored);
    }

    /**
     * 画面のリンク（ページ送り・Excel）に付ける形。読み取れた条件だけで、空と既定の状態は付けない
     *
     * @return array<string, string|int>
     */
    public function query(): array
    {
        return array_filter([
            'year'       => $this->year,
            'department' => $this->departmentId,
            'type'       => $this->typeId,
            'applicant'  => $this->applicant,
            'from'       => $this->decidedFrom?->format('Y-m-d'),
            'to'         => $this->decidedTo?->format('Y-m-d'),
            'status'     => $this->status === self::DEFAULT_STATUS ? null : $this->status,
            'decision'   => $this->decision?->value,
            'q'          => $this->keyword,
        ], fn (mixed $value) => $value !== null);
    }

    /**
     * 出力の記録に控える形（D25・§9 の 9）。項目はいつも同じで、使っていない条件は null（あとで読むときに迷わない）。
     * ⚠ MySQL の JSON はキーを並べ替えて返す（キーの長さの順。RequestSnapshot の注意）ので、読むときに並びに頼らない
     *
     * @return array{year: ?int, department_id: ?int, type_id: ?int, applicant: ?string, decided_from: ?string, decided_to: ?string, status: string, decision: ?string, keyword: ?string}
     */
    public function toLog(): array
    {
        return [
            'year'          => $this->year,
            'department_id' => $this->departmentId,
            'type_id'       => $this->typeId,
            'applicant'     => $this->applicant,
            'decided_from'  => $this->decidedFrom?->format('Y-m-d'),
            'decided_to'    => $this->decidedTo?->format('Y-m-d'),
            'status'        => $this->status,
            'decision'      => $this->decision?->value,
            'keyword'       => $this->keyword,
        ];
    }

    /**
     * 空白（全角を含む）で分けた語。どの語も含むものに当てる（「山田 太郎」で「山田太郎」も探せる）。分け方は NameSearch と同じ
     *
     * @return list<string>
     */
    public static function terms(?string $text): array
    {
        return NameSearch::terms($text);
    }

    private static function positiveInt(string $value): ?int
    {
        return preg_match('/^[1-9]\d{0,18}$/', $value) ? (int) $value : null;
    }

    /**
     * 日本の暦の日付（YYYY-MM-DD・在る日付だけ。2026-02-30 は外す）。年は 1900〜2099 だけ（0000-01-01 を UTC に直すと年が -1 になり、
     * MySQL の TIMESTAMP と比べられない。4b の Task 2 の軽微。紙の台帳は平成の年度も取り込むので 2000 年より前も通す）
     */
    private static function calendarDay(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^(19|20)\d{2}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, JapanTime::ZONE);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
