<?php

namespace App\Enums;

enum CustomOrderFileCategory: string
{
    case Budget      = 'budget';
    case FloorPlan   = 'floor_plan';
    case Estimate    = 'estimate';
    case ContractDoc = 'contract_doc';
    case Other       = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Budget      => '実行予算書',
            self::FloorPlan   => '設計図面',
            self::Estimate    => '見積書',
            self::ContractDoc => '契約書',
            self::Other       => 'その他資料',
        };
    }
}
