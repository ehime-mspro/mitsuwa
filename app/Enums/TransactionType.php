<?php

namespace App\Enums;

enum TransactionType: string
{
    case Sales = 'sales';
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Sales => '売上',
            self::Income => '入金',
            self::Expense => '支出',
        };
    }
}
