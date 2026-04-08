<?php

namespace App\Enums;

enum DepartmentCode: string
{
    case Tenant = 'tenant';
    case Mansion = 'mansion';
    case Housing = 'housing';
    case RealEstate = 'realestate';
    case Welfare = 'welfare';
    case Dad = 'dad';

    public function label(): string
    {
        return match ($this) {
            self::Tenant => 'テナント',
            self::Mansion => 'マンション',
            self::Housing => '住宅',
            self::RealEstate => '不動産',
            self::Welfare => '福祉',
            self::Dad => 'DAD',
        };
    }
}
