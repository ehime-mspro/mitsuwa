<?php

namespace App\Services\RealEstate;

use App\Enums\LotStatus;
use App\Enums\ProcurementStatus;
use App\Enums\ProjectStatus;
use App\Models\ReProcurement;
use App\Models\ReProject;

/**
 * 仕入れ案件一覧（分譲地統合版）の 1 行分の表示データ。
 *
 * 仕入れ案件（re_procurements）と分譲地（re_projects）のカラム差
 * （property_name / project_name など）を吸収し、Blade が instanceof で
 * 分岐せずに済むようにするための readonly 値オブジェクト。
 *
 * 区画数・区画URL は分譲地と、物件種別が土地の仕入れ案件（1 案件に何区画でも）だけが持つ。
 * それ以外の仕入れ案件では null にして、「無い」ことを型で表す。
 */
final class ProcurementListRow
{
    public const KIND_PROCUREMENT = 'procurement';
    public const KIND_PROJECT     = 'project';

    public function __construct(
        public readonly string $kind,
        public readonly int $id,
        public readonly string $name,
        public readonly ProcurementStatus|ProjectStatus $status,
        public readonly string $propertyTypeLabel,
        public readonly ?string $transactionTypeLabel,
        public readonly ?int $purchasePrice,
        public readonly ?int $targetSellingPrice,
        public readonly ?int $targetSellingPriceWithTax,
        public readonly ?int $expectedProfit,
        public readonly ?string $address,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly ?int $soldLotCount,
        public readonly ?int $lotCount,
        public readonly string $showUrl,
        public readonly ?string $lotsUrl,
    ) {
    }

    /**
     * ⚠ costs と lot をイーガーロードしたモデルを渡すこと（getExpectedProfit・区画数が使う）
     */
    public static function fromProcurement(ReProcurement $p): self
    {
        // 土地は区画が 0〜1 つ。区画が 0 でも区画のリンクは出す（詳細の区画の欄から登録するため）
        $isLand = $p->property_type->isLandOnly();
        $lot = $isLand ? $p->lot : null;

        return new self(
            kind: self::KIND_PROCUREMENT,
            id: (int) $p->id,
            name: $p->property_name,
            // ⚠ status は casts() で enum 済み。tryFrom() を通すと TypeError（Bug #22）
            status: $p->status,
            propertyTypeLabel: $p->property_type->label(),
            transactionTypeLabel: $p->transaction_type->label(),
            purchasePrice: $p->getPurchasePriceTotal(),
            targetSellingPrice: $p->getTargetSellingPriceTotal(),
            targetSellingPriceWithTax: $p->getTargetSellingPriceTotalWithTax(),
            expectedProfit: $p->getExpectedProfit(),
            address: $p->address,
            latitude: $p->latitude !== null ? (float) $p->latitude : null,
            longitude: $p->longitude !== null ? (float) $p->longitude : null,
            soldLotCount: $isLand ? ($lot?->status === LotStatus::Sold ? 1 : 0) : null,
            lotCount: $isLand ? ($lot !== null ? 1 : 0) : null,
            showUrl: route('realestate.procurements.show', $p->id),
            lotsUrl: $isLand ? route('realestate.procurements.show', $p->id) . '#lot' : null,
        );
    }

    /**
     * ⚠ costs と lots をイーガーロードしたモデルを渡すこと
     *   （getExpectedProfit / getSoldLotCount が使う）
     */
    public static function fromProject(ReProject $pj): self
    {
        return new self(
            kind: self::KIND_PROJECT,
            id: (int) $pj->id,
            name: $pj->project_name,
            status: $pj->status,
            // 分譲地は一覧上「素のテキストで 分譲地」。enum は持たない
            propertyTypeLabel: '分譲地',
            // 分譲地に取引種別カラムは無い
            transactionTypeLabel: null,
            purchasePrice: $pj->purchase_price,
            targetSellingPrice: $pj->target_selling_price,
            // 分譲地は土地のみ ＝ 消費税なし。税抜と同額を渡すと Blade 側の
            // 「同額なら併記しない」判定で税込行が自動的に消える
            targetSellingPriceWithTax: $pj->target_selling_price,
            expectedProfit: $pj->getExpectedProfit(),
            address: $pj->address,
            latitude: $pj->latitude !== null ? (float) $pj->latitude : null,
            longitude: $pj->longitude !== null ? (float) $pj->longitude : null,
            soldLotCount: $pj->getSoldLotCount(),
            lotCount: $pj->lots->count(),
            showUrl: route('realestate.projects.show', $pj->id),
            // 区画 0 件でも区画一覧へのリンクは出す（そこから登録するため）
            lotsUrl: route('realestate.projects.lots', $pj->id),
        );
    }

    public function isProject(): bool
    {
        return $this->kind === self::KIND_PROJECT;
    }
}
