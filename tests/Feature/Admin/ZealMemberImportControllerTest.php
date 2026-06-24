<?php

namespace Tests\Feature\Admin;

use App\Models\ZealMember;
use App\Models\ZealPlan;
use App\Models\ZealStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesZealSchema;
use Tests\TestCase;

/**
 * ZEAL 会員 CSV インポート（Admin\ZealMemberImportController）の Feature テスト。
 * zeal_* テーブルは migration 管理外のため CreatesZealSchema trait で構築する。
 */
class ZealMemberImportControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesZealSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createZealSchema();
    }

    public function test_zeal_schema_is_usable(): void
    {
        $store = ZealStore::create(['name' => '松山市駅前店', 'display_order' => 1, 'active' => true]);
        $plan  = ZealPlan::create(['name' => 'セミパーソナル通い放題', 'regular_price_excl' => 9800]);
        $member = ZealMember::create([
            'store_id' => $store->id, 'name' => '健全 太郎', 'joined_on' => '2025-10-17',
            'current_plan_id' => $plan->id, 'created_by' => 1, 'updated_by' => 1,
        ]);

        $this->assertDatabaseCount('zeal_members', 1);
        $this->assertSame('健全 太郎', $member->fresh()->name);
    }
}
