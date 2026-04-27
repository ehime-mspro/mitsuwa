<?php

namespace App\Enums;

enum DadCostCategory: string
{
    case Material = 'material';
    case Subcontract = 'subcontract';
    case Labor = 'labor';
    case Equipment = 'equipment';
    case Overhead = 'overhead';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Material => '材料費',
            self::Subcontract => '外注費',
            self::Labor => '人件費',
            self::Equipment => '機械経費',
            self::Overhead => '諸経費',
            self::Other => 'その他',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::Material => 'background: #fef3c7; color: #92400e;',
            self::Subcontract => 'background: #dbeafe; color: #1e40af;',
            self::Labor => 'background: #d1fae5; color: #065f46;',
            self::Equipment => 'background: #ede9fe; color: #5b21b6;',
            self::Overhead => 'background: #fee2e2; color: #991b1b;',
            self::Other => 'background: #f3f4f6; color: #4b5563;',
        };
    }
}
