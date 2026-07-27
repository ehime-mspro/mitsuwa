<?php

namespace Tests\Feature\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\ProjectStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Enums\UserRole;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use App\Models\User;
use App\Services\RealEstate\ProcurementListRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 仕入れ案件一覧（分譲地統合版）の検証。
 *
 * re_* / hs_* / buyers は migration 管理外のため CreatesRealEstateSchema trait で構築する。
 */
class ProcurementListWithProjectsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /**
     * 経営層ユーザー。
     * - department.access:realestate を無条件通過する（isExecutive）
     * - must_change_password はマイグレーション既定が true なので明示的に false にする
     *   （true のままだと ForcePasswordChange が password.change へリダイレクトする）
     */
    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** ⚠ 一覧が描画するのは procurement_code ではなく property_name（既存テストの実測メモ） */
    private function makeProcurement(string $code, array $attrs = []): ReProcurement
    {
        return ReProcurement::create(array_merge([
            'procurement_code'   => $code,
            'property_type'      => RealEstatePropertyType::UsedHouse->value,
            'transaction_type'   => RealEstateTransactionType::Purchase->value,
            'status'             => 'selling',
            'property_name'      => "物件{$code}",
            'address'            => '愛媛県松山市1-1-1',
            'info_obtained_date' => '2026-06-01',
            'created_by'         => 1,
        ], $attrs));
    }

    /** 既定では assessment/purchase price を入れない（ReProject の saved フックを no-op に保つ） */
    private function makeProject(string $code, array $attrs = []): ReProject
    {
        return ReProject::create(array_merge([
            'project_code'       => $code,
            'project_name'       => "分譲地{$code}",
            'status'             => 'selling',
            'address'            => '愛媛県松山市2-2-2',
            'info_obtained_date' => '2026-06-01',
            'created_by'         => 1,
        ], $attrs));
    }

    private function makeLot(ReProject $project, int $lotNumber, string $status = 'on_sale'): ReProjectLot
    {
        return ReProjectLot::create([
            'project_id' => $project->id,
            'lot_number' => $lotNumber,
            'area_sqm'   => 100.00,
            'area_tsubo' => 30.25,
            'status'     => $status,
        ]);
    }

    // ================================================================
    // Task 1: DTO
    // ================================================================

    public function test_row_from_procurement_maps_fields(): void
    {
        $p = $this->makeProcurement('PRC-001', [
            'purchase_price'       => 30000000,
            'target_selling_price' => 40000000,
            'latitude'             => 33.8416,
            'longitude'            => 132.7657,
        ]);

        $row = ProcurementListRow::fromProcurement($p->fresh()->load('costs'));

        $this->assertSame('procurement', $row->kind);
        $this->assertSame($p->id, $row->id);
        $this->assertSame('物件PRC-001', $row->name);
        $this->assertSame(ProcurementStatus::Selling, $row->status);
        $this->assertSame('中古戸建', $row->propertyTypeLabel);
        $this->assertSame('自社買取', $row->transactionTypeLabel);
        $this->assertSame(30000000, $row->purchasePrice);
        $this->assertSame(40000000, $row->targetSellingPrice);
        // purchase_price を入れたので syncPropertyPurchaseCost() が原価 1 行を作る
        // → 粗利 = 40,000,000 − 30,000,000
        $this->assertSame(10000000, $row->expectedProfit);
        $this->assertEqualsWithDelta(33.8416, $row->latitude, 0.0001);
        $this->assertNull($row->soldLotCount);
        $this->assertNull($row->lotCount);
        $this->assertNull($row->lotsUrl);
        $this->assertStringContainsString("/realestate/procurements/{$p->id}", $row->showUrl);
    }

    public function test_row_from_project_maps_lot_counts(): void
    {
        $pj = $this->makeProject('PJ-001', ['target_selling_price' => 50000000]);
        $this->makeLot($pj, 1, 'sold');
        $this->makeLot($pj, 2, 'sold');
        $this->makeLot($pj, 3, 'on_sale');

        $row = ProcurementListRow::fromProject($pj->fresh()->load('costs', 'lots'));

        $this->assertSame('project', $row->kind);
        $this->assertSame('分譲地PJ-001', $row->name);
        $this->assertSame(ProjectStatus::Selling, $row->status);
        $this->assertSame('分譲地', $row->propertyTypeLabel);
        $this->assertNull($row->transactionTypeLabel);
        $this->assertSame(2, $row->soldLotCount);
        $this->assertSame(3, $row->lotCount);
        $this->assertStringContainsString("/realestate/projects/{$pj->id}", $row->showUrl);
        $this->assertStringContainsString("/realestate/projects/{$pj->id}/lots", $row->lotsUrl);
    }

    /** 区画 0 件でも「区画 0 / 0」と区画ボタンを出すため、null ではなく 0 と URL を返す */
    public function test_row_from_project_with_zero_lots(): void
    {
        $pj = $this->makeProject('PJ-002');

        $row = ProcurementListRow::fromProject($pj->fresh()->load('costs', 'lots'));

        $this->assertSame(0, $row->soldLotCount);
        $this->assertSame(0, $row->lotCount);
        $this->assertNotNull($row->lotsUrl);
    }
}
