<?php

namespace App\Enums;

/**
 * 段階ごとの判断（要件 4.2）。
 *
 * ⚠ `Approve` は部門長では「承認」、社長では「可」と表示が変わる（値は同じ）。
 *   表示は必ず labelFor() に段階を渡して作る。
 */
enum ApprovalStepResult: string
{
    case Approve     = 'approve';
    case Return      = 'return';
    case Ok          = 'ok';
    case Hold        = 'hold';
    case Ng          = 'ng';
    case Conditional = 'conditional';
    case Reject      = 'reject';

    public function labelFor(ApprovalStepKind $kind): string
    {
        return match ($this) {
            self::Approve     => $kind === ApprovalStepKind::Head ? '承認' : '可',
            self::Return      => '差戻し',
            self::Ok          => '可',
            self::Hold        => '保留',
            self::Ng          => '否',
            self::Conditional => '条可',
            self::Reject      => '否',
        };
    }

    /** その段階で選べる判断（画面の並び順） @return list<self> */
    public static function allowedFor(ApprovalStepKind $kind): array
    {
        return match ($kind) {
            ApprovalStepKind::Head      => [self::Approve, self::Return],
            ApprovalStepKind::Review    => [self::Ok, self::Hold, self::Ng],
            ApprovalStepKind::President => [self::Approve, self::Conditional, self::Return, self::Reject],
        };
    }

    /** コメントが必須か（要件 4.2: 差戻し・保留・否・条可） */
    public function requiresComment(): bool
    {
        return in_array($this, [self::Return, self::Hold, self::Ng, self::Conditional, self::Reject], true);
    }
}
