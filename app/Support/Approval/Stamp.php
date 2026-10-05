<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\JapanTime;
use DateTimeInterface;

/**
 * 押された 1 つの印（上段・中段の日付・下段。要件 9.1・段階4 設計書 §5.4）。
 *
 * 判断した段階の印は、段階に控えた文字（D3）と判断の日時（日本時間の暦の和暦。D7）から作る。
 * ⚠ 控えの無い判断（4a より前の判断。本番には無い）は、今の設定から作る（設計書 §5.3）。
 */
final class Stamp
{
    private const REIWA_START = '2019-05-01';

    private function __construct(
        public readonly string $label,
        public readonly string $date,
        public readonly string $text,
    ) {
    }

    /** 判断した段階の印。判断していない段階（待ち・省略・打ち切り）は null */
    public static function forStep(ApprovalStep $step): ?self
    {
        if ($step->status !== ApprovalStepStatus::Done || $step->acted_at === null) {
            return null;
        }

        $label = $step->stamp_label ?? StampText::labelFor($step);
        $text  = $step->stamp_text ?? ($step->actor !== null ? StampText::for($step->actor) : '');

        return new self($label, self::eraDate($step->acted_at), $text);
    }

    /** 利用者の管理（⑦）の見本の印（上段は最初の所属部門の略称・日付は今日。D11） */
    public static function preview(User $user): self
    {
        $label = (string) $user->approvalDepartments->sortBy('sort_order')->first()?->short_name;

        return new self($label, self::eraDate(JapanTime::today()), StampText::for($user));
    }

    /** 日本時間の暦の日付を和暦の短い形にする（例 R8.10.5。令和より前は H） */
    public static function eraDate(DateTimeInterface $at): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', JapanTime::format($at, 'Y-n-j')));
        $isReiwa = JapanTime::format($at, 'Y-m-d') >= self::REIWA_START;

        return sprintf('%s%d.%d.%d', $isReiwa ? 'R' : 'H', $isReiwa ? $y - 2018 : $y - 1988, $m, $d);
    }
}
