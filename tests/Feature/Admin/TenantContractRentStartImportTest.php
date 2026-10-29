<?php

namespace Tests\Feature\Admin;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * テナントの契約・過去契約の取込で、賃料開始日が空欄の行（家賃発生日が未定・記録が無い）を取り込む。
 * 本番の contracts.rent_start_date は NULL 可で、取込は空欄を null で入れる。
 *
 * ⚠ 2026-10-29 まではテスト用スキーマの rent_start_date が NOT NULL で、ほかの取込のテストは
 *   賃料開始日を埋めて避けていた（この経路は確定までテストで通せなかった。Bug #60 の範囲外）
 */
class TenantContractRentStartImportTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use SubmitsImportPreview;

    private const CONTRACT_HEADER = '物件名,階,部屋番号,テナント名,契約日,賃料開始日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考';

    private const PAST_HEADER = '物件名,階,部屋番号,テナント名,契約日,賃料開始日,解約日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考';

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $property = Property::create([
            'code' => 'T-IMP-1', 'name' => '取込ビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 5,
        ]);
        $this->unit = Unit::create([
            'property_id' => $property->id, 'floor' => 1, 'room_number' => 'A',
            'display_name' => Unit::generateDisplayName(1, 'A'), 'status' => 'vacant', 'area_tsubo' => 10,
        ]);
        Customer::create(['code' => 'CU-IMP-1', 'name' => '取込商事', 'customer_type' => 'corporation']);
    }

    /** 取込画面の URL の前半（SubmitsImportPreview が使う） */
    private function importBasePath(): string
    {
        return '/admin/tenant-import';
    }

    public function test_a_contract_row_without_a_rent_start_date_is_imported_as_unset(): void
    {
        $this->confirm('contract', self::CONTRACT_HEADER . "\n取込ビル,1,A,取込商事,2026-09-01,,100000,,,,,,\n")
            ->assertSessionHas('success', '契約インポート完了: 1件を登録しました');

        $contract = Contract::where('unit_id', $this->unit->id)->sole();
        $this->assertSame('2026-09-01', $contract->contract_date->format('Y-m-d'));
        $this->assertNull($contract->rent_start_date);
    }

    public function test_a_past_contract_row_without_a_rent_start_date_is_imported_as_unset(): void
    {
        $this->confirm('past-contract', self::PAST_HEADER . "\n取込ビル,1,A,取込商事,2020-04-01,,2023-03-31,90000,,,,,,\n")
            ->assertSessionHas('success', '過去契約インポート完了: 契約 1件を登録');

        $contract = Contract::where('unit_id', $this->unit->id)->sole();
        $this->assertSame('terminated', $contract->status->value);
        $this->assertNull($contract->rent_start_date);
    }
}
