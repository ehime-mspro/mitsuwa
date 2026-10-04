<?php

namespace Tests\Feature\Admin\Master;

use App\Models\ReProcurement;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 原価項目マスター（Admin\ReCostItemController）。仕入れ案件・分譲地の原価明細（`re_procurement_costs` /
 * `re_project_costs`）が id で使う。⚠ 本番は両方の明細に外部キー（ON DELETE の指定なし）があるので、
 * 使っている項目を消すと MySQL が断り 500 になる（テストの SQLite は外部キーを張らないので黙って消える）。
 */
class ReCostItemMasterTest extends InlineNameMasterTestCase
{
    use CreatesRealEstateSchema;

    protected function prefix(): string
    {
        return 're-cost-items';
    }

    protected function jsFunction(): string
    {
        return 'costItemManager';
    }

    protected function table(): string
    {
        return 're_cost_items';
    }

    protected function names(): array
    {
        return ['測量費', '造成費', '登記費'];
    }

    protected function maxLength(): int
    {
        return 50;
    }

    protected function createMasterSchema(): void
    {
        $this->createRealEstateSchema();
    }

    protected function markInUse(int $id, string $name): void
    {
        $procurement = ReProcurement::create([
            'procurement_code' => 'P-COST', 'property_type' => 'used_house', 'transaction_type' => 'purchase',
            'status' => 'info_obtained', 'property_name' => '原価テスト', 'address' => '愛媛県松山市1-1', 'created_by' => 1,
        ]);
        DB::table('re_procurement_costs')->insert([
            'procurement_id' => $procurement->id, 'cost_item_id' => $id, 'estimated_amount' => 100000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function inUseMessage(string $name): string
    {
        return "「{$name}」は原価明細で使用されているため削除できません。";
    }
}
