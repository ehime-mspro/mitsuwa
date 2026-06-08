<?php

namespace Tests\Feature\Housing;

use App\Enums\CustomOrderStatus;
use App\Enums\HousingLandSourceType;
use App\Http\Controllers\Housing\HousingDashboardController;
use App\Models\HsCustomOrder;
use ReflectionClass;
use Tests\TestCase;

/**
 * 注文住宅 DTO 変換が「契約日基準・実ステータス表示」になっていること。
 * hs_* テーブルはテストDBに無いため DB 保存はせず、未保存モデルを reflection で変換する。
 */
class HousingDashboardMapOrderTest extends TestCase
{
    public function test_map_order_to_dto_uses_contract_date_and_real_status(): void
    {
        $controller = new HousingDashboardController();
        $method = (new ReflectionClass($controller))->getMethod('mapOrderToDto');
        $method->setAccessible(true);

        $order = new HsCustomOrder([
            'order_code'              => 'CO-T',
            'order_name'              => 'テスト邸',
            'status'                  => CustomOrderStatus::Contracted->value,
            'customer_name'           => 'テスト顧客',
            'land_source_type'        => HousingLandSourceType::CustomerLand->value,
            'building_contract_price' => 20000000,
            'building_cost'           => 15000000,
            'tax_rate'                => 10,
            'contract_date'           => '2026-06-01',
        ]);
        $order->id = 1; // route('housing.custom-orders.show', $order) 生成のため

        $dto = $method->invoke($controller, $order);

        $this->assertSame('custom-order', $dto['type']);
        $this->assertSame('契約', $dto['status_label']);              // 「引渡し済み」固定でなく実ステータス
        $this->assertNotNull($dto['contracted_date']);
        $this->assertSame('2026-06-01', $dto['contracted_date']->format('Y-m-d')); // 契約日基準
        $this->assertSame(20000000, $dto['selling_price']);
        $this->assertSame(5000000, $dto['gross_profit']);
    }
}
