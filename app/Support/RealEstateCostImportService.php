<?php

namespace App\Support;

use App\Models\ReCostItem;
use App\Models\ReProcurement;
use App\Models\ReProcurementCost;
use App\Models\ReProject;
use App\Models\ReProjectCost;
use Illuminate\Support\Facades\DB;

/**
 * 不動産原価管理 試算表 Excel/CSV 取込のバルク投入 Service
 *
 * 仕入れ案件 (ReProcurement) と分譲地PJ (ReProject) の両方から呼び出される。
 * overwrite モード時は「物件購入費」以外の既存原価を一括 delete してから取込行を insert する。
 * append モード時は既存原価に追記する。
 *
 * 物件購入費は ReProcurement / ReProject の syncPropertyPurchaseCost() で
 * 自動同期される行のため、削除も追加もしない（Controller 側で二重防御済み）。
 */
class RealEstateCostImportService
{
    /**
     * 仕入れ案件への取込
     *
     * @param  array<int, array{cost_item_id:int, estimated_amount:int, actual_amount?:int|null, notes?:string|null}>  $rows
     * @param  string  $mode  'overwrite' | 'append'
     * @return array{imported_count:int, costs:array<int, array<string, mixed>>}
     */
    public function importToProcurement(ReProcurement $procurement, array $rows, string $mode): array
    {
        return DB::transaction(function () use ($procurement, $rows, $mode) {
            if ($mode === 'overwrite') {
                $this->deleteExceptPropertyPurchase(
                    ReProcurementCost::query()->where('procurement_id', $procurement->id)
                );
            }

            $importedCount = 0;
            foreach ($rows as $r) {
                ReProcurementCost::create([
                    'procurement_id'   => $procurement->id,
                    'cost_item_id'     => $r['cost_item_id'],
                    'estimated_amount' => $r['estimated_amount'],
                    'actual_amount'    => $r['actual_amount'] ?? null,
                    'notes'            => $r['notes'] ?? null,
                ]);
                $importedCount++;
            }

            $allCosts = ReProcurementCost::with('costItem')
                ->where('procurement_id', $procurement->id)
                ->get();

            return [
                'imported_count' => $importedCount,
                'costs'          => $this->formatCostsForJson($allCosts),
            ];
        });
    }

    /**
     * 分譲地PJ への取込（インターフェースは importToProcurement と対称）
     *
     * @param  array<int, array{cost_item_id:int, estimated_amount:int, actual_amount?:int|null, notes?:string|null}>  $rows
     * @param  string  $mode  'overwrite' | 'append'
     * @return array{imported_count:int, costs:array<int, array<string, mixed>>}
     */
    public function importToProject(ReProject $project, array $rows, string $mode): array
    {
        return DB::transaction(function () use ($project, $rows, $mode) {
            if ($mode === 'overwrite') {
                $this->deleteExceptPropertyPurchase(
                    ReProjectCost::query()->where('project_id', $project->id)
                );
            }

            $importedCount = 0;
            foreach ($rows as $r) {
                ReProjectCost::create([
                    'project_id'       => $project->id,
                    'cost_item_id'     => $r['cost_item_id'],
                    'estimated_amount' => $r['estimated_amount'],
                    'actual_amount'    => $r['actual_amount'] ?? null,
                    'notes'            => $r['notes'] ?? null,
                ]);
                $importedCount++;
            }

            $allCosts = ReProjectCost::with('costItem')
                ->where('project_id', $project->id)
                ->get();

            return [
                'imported_count' => $importedCount,
                'costs'          => $this->formatCostsForJson($allCosts),
            ];
        });
    }

    /**
     * overwrite モード時に既存原価を一括削除する（物件購入費の自動同期行は保持）。
     *
     * 物件購入費マスタ（re_cost_items.name = '物件購入費'）が未登録の場合は、
     * 既存原価のうち「保護すべき自動同期行」も論理的に存在しえない（cost_item_id 参照不能）ため、
     * 全 delete で問題ない。次回保存時に ReProcurement/ReProject::syncPropertyPurchaseCost() が
     * 必要なら物件購入費行を再生成する。
     */
    private function deleteExceptPropertyPurchase($query): void
    {
        $purchaseCostItemId = ReCostItem::where('name', '物件購入費')->value('id');
        if (!$purchaseCostItemId) {
            // マスタ未登録なら保護対象も存在しないため全 delete（意図的）
            $query->delete();
            return;
        }
        $query->where('cost_item_id', '!=', $purchaseCostItemId)->delete();
    }

    /**
     * 取込後の原価コレクションをクライアント返却用 array に整形。
     * 既存 storeCost / updateCost の JSON 形状と完全一致させる。
     */
    private function formatCostsForJson($costs): array
    {
        $list = [];
        foreach ($costs as $cost) {
            $list[] = [
                'id'                   => $cost->id,
                'cost_item_id'         => $cost->cost_item_id,
                'cost_item_name'       => $cost->costItem ? $cost->costItem->name : '（削除済み）',
                'estimated_amount'     => $cost->estimated_amount,
                'actual_amount'        => $cost->actual_amount,
                'notes'                => $cost->notes ?? '',
                'is_property_purchase' => $cost->costItem && $cost->costItem->name === '物件購入費',
            ];
        }

        return $list;
    }
}
