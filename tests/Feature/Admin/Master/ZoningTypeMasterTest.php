<?php

namespace Tests\Feature\Admin\Master;

use App\Models\ReProcurement;
use App\Models\ReProject;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 用途地域マスター（Admin\ZoningTypeController）。仕入れ案件・分譲地の用途地域（`zoning`）は **名前**で持つ。
 * 名前を変えたときの仕入れ案件・分譲地側は MasterNameReferenceTest が見る。
 */
class ZoningTypeMasterTest extends InlineNameMasterTestCase
{
    use CreatesRealEstateSchema;

    protected function prefix(): string
    {
        return 'zoning-types';
    }

    protected function jsFunction(): string
    {
        return 'zoningTypeManager';
    }

    protected function table(): string
    {
        return 'zoning_types';
    }

    protected function names(): array
    {
        return ['第一種住居地域', '商業地域', '工業地域'];
    }

    protected function maxLength(): int
    {
        return 100;
    }

    protected function createMasterSchema(): void
    {
        $this->createRealEstateSchema();
    }

    protected function markInUse(int $id, string $name): void
    {
        ReProcurement::create([
            'procurement_code' => 'P-ZONE', 'property_type' => 'used_house', 'transaction_type' => 'purchase',
            'status' => 'info_obtained', 'property_name' => '用途地域テスト', 'address' => '愛媛県松山市1-1',
            'zoning' => $name, 'created_by' => 1,
        ]);
    }

    protected function inUseMessage(string $name): string
    {
        return "「{$name}」は不動産案件で使用されているため削除できません。";
    }

    /** 分譲地だけが使っている用途地域も削除しない */
    public function test_a_zoning_used_only_by_a_project_is_not_deleted(): void
    {
        ReProject::create([
            'project_code' => 'J-ZONE', 'project_name' => '用途地域テスト分譲地', 'status' => 'selling',
            'address' => '愛媛県松山市2-2', 'zoning' => '第一種住居地域', 'created_by' => 1,
        ]);

        $sent = $this->submitFromScreen($this->indexUrl(), $this->jsFunction(),
            'var it = data.items[0]; data.startDelete(it.id, it.name); data.submitDelete();');

        $this->assertTrue(DB::table('zoning_types')->where('id', $this->ids['第一種住居地域'])->exists());
        $this->assertFlash($this->landed($sent['response']), 'error', $this->inUseMessage('第一種住居地域'));
    }
}
