<?php

namespace App\Support;

use App\Enums\HousingLandSourceType;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\ReProjectLot;

/**
 * 住宅事業（建売・注文住宅）の土地原価を、紐付け先（分譲地区画・仕入れ案件）から計算する部品と、
 * 手動入力 OFF で保存するときの決まり（判断待ち ⑤・案 A＋b。設計 docs/superpowers/specs/2026-11-20-housing-land-cost-auto-design.md）。
 *
 * 土地原価は保存した時点の値を持つ。手動 OFF の保存では、送られた値を使わず、ここで決める:
 *   - 新規登録／保存済みが手動 ON（＝手入力をやめた）／紐付け先が保存済みと違う／「今の計算値にする」（refresh）を押した
 *     → 紐付け先から計算した値（計算できなければ空）
 *   - それ以外（チェックに触らず保存） → 保存済みの値のまま（「古い契約の原価が勝手に動かないように」。利用者の方針）
 *
 * ⚠ 画面の JS（housing/_land_cost_script の housingLandCostView()）が同じ決まりを写している。片方だけ変えない（Bug #41。
 *   LandCostViewParityTest が同じ場面で突き合わせる）。
 * ⚠ 区画の按分は区画の一覧（ReProject::housingLotOptions()）と同じ式（ReProject::housingLotLandCost()）。
 */
final class HousingLandCost
{
    /**
     * 紐付け先から計算した土地原価。計算できなければ null
     * （区画: 分譲地の全区画に販売価格が無い・その区画が無い／仕入れ案件: 原価の行が 1 つも無い・その案件が無い／それ以外の種別）。
     */
    public static function forLink(?string $sourceType, mixed $lotId, mixed $procurementId): ?int
    {
        if ($sourceType === HousingLandSourceType::ProjectLot->value) {
            $lot = is_numeric($lotId) ? ReProjectLot::find((int) $lotId) : null;
            $project = $lot ? ReProject::with('lots', 'costs')->find($lot->project_id) : null;

            return $project?->housingLotLandCost($lot->id);
        }

        if ($sourceType === HousingLandSourceType::Procurement->value) {
            $procurement = is_numeric($procurementId) ? ReProcurement::with('costs')->find((int) $procurementId) : null;
            if ($procurement === null || $procurement->costs->isEmpty()) {
                return null;
            }

            return $procurement->getEffectiveCostTotal();
        }

        return null;
    }

    /** 手動 OFF で保存する土地原価: 計算し直す条件のどれかなら $computed、どれでもなければ $saved */
    public static function decide(bool $creating, bool $savedManual, bool $linkChanged, bool $refresh, ?int $computed, ?int $saved): ?int
    {
        return ($creating || $savedManual || $linkChanged || $refresh) ? $computed : $saved;
    }

    /**
     * 手動 OFF で保存する土地原価。$current は保存済みの行（新規登録は null。保存済みの値は getOriginal() で読む）、
     * $link は保存する紐付け（land_source_type・re_project_lot_id・re_procurement_id）。
     */
    public static function whenAutomatic(HsProperty|HsCustomOrder|null $current, array $link, bool $refresh): ?int
    {
        $saved = $current?->getOriginal('land_cost');

        return self::decide(
            $current === null,
            (bool) $current?->getOriginal('is_land_cost_manual'),
            $current !== null && $current->landLinkDiffers($link),
            $refresh,
            self::forLink($link['land_source_type'] ?? null, $link['re_project_lot_id'] ?? null, $link['re_procurement_id'] ?? null),
            $saved === null ? null : (int) $saved,
        );
    }
}
