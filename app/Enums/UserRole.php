<?php

namespace App\Enums;

enum UserRole: string
{
    case Executive = 'executive';
    case Manager = 'manager';
    case Staff = 'staff';
    /** 決裁申請だけを使う人（設計書 D6）。基幹の画面はすべて門番が止める */
    case ApprovalOnly = 'approval_only';

    public function label(): string
    {
        return match ($this) {
            self::Executive => '経営層',
            self::Manager => '部門管理者',
            self::Staff => '一般担当者',
            self::ApprovalOnly => '決裁のみ',
        };
    }

    public function isExecutive(): bool
    {
        return $this === self::Executive;
    }

    public function isManagerOrAbove(): bool
    {
        return in_array($this, [self::Executive, self::Manager]);
    }

    public function isApprovalOnly(): bool
    {
        return $this === self::ApprovalOnly;
    }

    /** 基幹を使うロール（決裁のみを除く。登録・編集の選択肢と絞り込みで使う） */
    public static function baseCases(): array
    {
        return [self::Executive, self::Manager, self::Staff];
    }
}
