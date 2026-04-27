<?php

namespace App\Enums;

enum ReContractType: string
{
    case ProcurementLand    = 'procurement_land';
    case ProcurementMansion = 'procurement_mansion';
    case ProcurementHouse   = 'procurement_house';
    case SubdivisionLot     = 'subdivision_lot';
    case Brokerage          = 'brokerage';

    public function label(): string
    {
        return match ($this) {
            self::ProcurementLand    => '仕入れ土地販売',
            self::ProcurementMansion => '中古マンション販売',
            self::ProcurementHouse   => '中古戸建販売',
            self::SubdivisionLot     => '分譲地販売',
            self::Brokerage          => '仲介',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::ProcurementLand    => '土地販売',
            self::ProcurementMansion => '中古MS',
            self::ProcurementHouse   => '中古戸建',
            self::SubdivisionLot     => '分譲地',
            self::Brokerage          => '仲介',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::ProcurementLand    => 'background: #fef3c7; color: #92400e;',
            self::ProcurementMansion => 'background: #dbeafe; color: #1e40af;',
            self::ProcurementHouse   => 'background: #e0e7ff; color: #3730a3;',
            self::SubdivisionLot     => 'background: #d1fae5; color: #065f46;',
            self::Brokerage          => 'background: #fce7f3; color: #9d174d;',
        };
    }

    public function isProcurement(): bool
    {
        return in_array($this, [
            self::ProcurementLand,
            self::ProcurementMansion,
            self::ProcurementHouse,
        ]);
    }

    public function isSubdivision(): bool
    {
        return $this === self::SubdivisionLot;
    }

    public function isBrokerage(): bool
    {
        return $this === self::Brokerage;
    }
}
