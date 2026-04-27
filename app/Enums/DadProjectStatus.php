<?php

namespace App\Enums;

enum DadProjectStatus: string
{
    case Estimate = 'estimate';
    case Ordered = 'ordered';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Paid = 'paid';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Estimate => '見積',
            self::Ordered => '受注',
            self::InProgress => '施工中',
            self::Completed => '完工',
            self::Paid => '入金済み',
            self::Lost => '失注',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::Estimate => 'background: #f3f4f6; color: #374151;',
            self::Ordered => 'background: #dbeafe; color: #1e40af;',
            self::InProgress => 'background: #fef3c7; color: #92400e;',
            self::Completed => 'background: #d1fae5; color: #065f46;',
            self::Paid => 'background: #a7f3d0; color: #064e3b;',
            self::Lost => 'background: #e5e7eb; color: #6b7280;',
        };
    }
}
