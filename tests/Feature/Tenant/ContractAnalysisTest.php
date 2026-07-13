<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Tenant\ContractAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * テナント契約の「契約・解約」年×月集計（ContractAnalysisService）の検証。
 *
 * 集計は DB非依存（PHP/Carbon）のため SQLite in-memory でも YEAR()/MONTH() 問題なし。
 * Contract は HasFactory だが ContractFactory 未定義 → create() 直接で組み立てる
 * （ContractDeletionTest と同方針）。
 * 設計の正: docs/superpowers/specs/2026-07-13-tenant-contract-termination-analysis-design.md
 */
class ContractAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /**
     * 物件＋区画＋顧客＋契約を1セット作成して返す。
     * $contractDate / $status / $contractEndDate を変えて年月・種別を作り分ける。
     */
    private function makeContract(
        string $department = 'tenant',
        ?string $contractDate = '2024-08-15',
        string $status = 'active',
        ?string $contractEndDate = null,
        ?string $rentStartDate = null,
    ): Contract {
        $this->seq++;

        $customer = Customer::create([
            'code' => 'CUST-ANL-' . $this->seq,
            'name' => 'テスト商事' . $this->seq,
            'customer_type' => 'corporation',
        ]);

        $property = Property::create([
            'code' => 'PROP-ANL-' . $this->seq,
            'name' => 'テストビル' . $this->seq,
            'property_type' => 'tenant',
            'department' => 'tenant',
            'address' => '愛媛県松山市本町1-1',
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'room_number' => 'A' . $this->seq,
            'display_name' => '1A-' . $this->seq,
            'status' => 'occupied',
        ]);

        return Contract::create([
            'contract_number' => 'C-ANL-' . str_pad((string) $this->seq, 3, '0', STR_PAD_LEFT),
            'department' => $department,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'contract_date' => $contractDate,
            'rent_start_date' => $rentStartDate ?? $contractDate,
            'contract_end_date' => $contractEndDate,
            'rent' => 100000,
            'common_fee' => 10000,
        ]);
    }

    /** T1: 契約マトリクスは contract_date の暦年×暦月で件数集計され、years は降順 */
    public function test_contract_matrix_counts_by_calendar_year_and_month(): void
    {
        $this->makeContract('tenant', '2024-08-10');
        $this->makeContract('tenant', '2024-08-25');
        $this->makeContract('tenant', '2025-03-05');

        $c = (new ContractAnalysisService)->build()['contract'];

        $this->assertSame(2, $c['cells'][2024][8]);
        $this->assertSame(1, $c['cells'][2025][3]);
        $this->assertSame(2, $c['yearTotals'][2024]);
        $this->assertSame(1, $c['yearTotals'][2025]);
        $this->assertSame(2, $c['monthTotals'][8]);
        $this->assertSame(1, $c['monthTotals'][3]);
        $this->assertSame(3, $c['grandTotal']);
        $this->assertSame([2025, 2024], $c['years']); // 降順（新しい年が上）
    }

    /** T1b: 契約集計は contract_date 基準（rent_start_date が別月でも contract_date のセルに立つ・D5） */
    public function test_contract_matrix_uses_contract_date_not_rent_start_date(): void
    {
        // contract_date=2024/8・rent_start_date=2025/1（家賃発生日を別年月にする）
        $this->makeContract('tenant', '2024-08-10', 'active', null, '2025-01-15');

        $c = (new ContractAnalysisService)->build()['contract'];

        $this->assertSame(1, $c['cells'][2024][8]);     // 契約日の月に計上
        $this->assertArrayNotHasKey(2025, $c['cells']); // 家賃発生日(rent_start_date)の月には立たない
    }

    /** T2: 契約は contract_date 基準・解約は contract_end_date 基準（同一契約が別セルに計上） */
    public function test_termination_uses_end_date_while_contract_uses_contract_date(): void
    {
        // 2024/8 契約 → 2025/3 解約
        $this->makeContract('tenant', '2024-08-10', 'terminated', '2025-03-20');

        $data = (new ContractAnalysisService)->build();

        // 契約マトリクスは契約月（2024/8）
        $this->assertSame(1, $data['contract']['cells'][2024][8]);
        $this->assertSame(1, $data['contract']['grandTotal']);
        // 解約マトリクスは退去月（2025/3）
        $this->assertSame(1, $data['termination']['cells'][2025][3]);
        $this->assertSame(1, $data['termination']['grandTotal']);
    }

    /** T3: active 契約は contract_end_date（予定終了日）を持っていても解約集計から除外 */
    public function test_active_contract_is_excluded_from_termination_even_with_end_date(): void
    {
        $this->makeContract('tenant', '2024-08-10', 'active', '2025-12-31');

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(1, $data['contract']['grandTotal']);    // 契約には出る
        $this->assertSame(0, $data['termination']['grandTotal']); // 解約には出ない
    }

    /** T4: tenant 以外の department の契約は契約・解約どちらにも計上されない */
    public function test_non_tenant_department_contracts_are_excluded(): void
    {
        $this->makeContract('mansion', '2024-08-10', 'terminated', '2025-03-20');

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(0, $data['contract']['grandTotal']);
        $this->assertSame(0, $data['termination']['grandTotal']);
    }

    /** T5: 論理削除された契約は両マトリクスに出ない（SoftDeletes グローバルスコープ） */
    public function test_soft_deleted_contract_is_excluded(): void
    {
        $contract = $this->makeContract('tenant', '2024-08-10', 'terminated', '2025-03-20');
        $contract->delete();

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(0, $data['contract']['grandTotal']);
        $this->assertSame(0, $data['termination']['grandTotal']);
    }

    /** T5b: terminated だが contract_end_date=null の異常データは解約に出ない（契約には出る） */
    public function test_terminated_without_end_date_is_excluded_from_termination(): void
    {
        $this->makeContract('tenant', '2024-08-10', 'terminated', null);

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(1, $data['contract']['grandTotal']);    // 契約日で計上される
        $this->assertSame(0, $data['termination']['grandTotal']); // end_date が無く解約には出ない
    }

    /** T6: 空データ → grandTotal=0 / years=[] / max=0（ゼロ除算しない） */
    public function test_empty_data_produces_zero_matrix(): void
    {
        $data = (new ContractAnalysisService)->build();

        $this->assertSame(0, $data['contract']['grandTotal']);
        $this->assertSame([], $data['contract']['years']);
        $this->assertSame(0, $data['contract']['max']);
        $this->assertSame(0, $data['termination']['grandTotal']);
    }

    /** T7: max は「単一セル」の最大に一致する（月計/年計の最大ではない）。ヒートマップ濃淡の分母 */
    public function test_max_equals_the_largest_single_cell_not_a_total(): void
    {
        // 2024/8 に2件、2025/8 に2件 → 単一セル最大=2・月計[8]=4・年計=各2。
        // max が月計(4)や年計を誤採用していれば assertSame(2, ...) が赤になる。
        // 併せて「異なる年の同月が monthTotals で合算される」ことも担保する。
        $this->makeContract('tenant', '2024-08-01');
        $this->makeContract('tenant', '2024-08-02');
        $this->makeContract('tenant', '2025-08-01');
        $this->makeContract('tenant', '2025-08-02');

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(2, $data['contract']['max']);            // 単一セルの最大
        $this->assertSame(4, $data['contract']['monthTotals'][8]); // 月計は別値（max と混同していない担保）
    }

    /** password.change を通過する経営層ユーザー（CheckDepartmentAccess を無条件パススルー） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** T8: GET /tenant/analysis が 200 で、契約/解約タブと年計/月計が描画される */
    public function test_analysis_page_renders_with_both_tabs(): void
    {
        $this->makeContract('tenant', '2024-08-10', 'terminated', '2025-03-20');

        $response = $this->actingAs($this->executive())->get('/tenant/analysis');

        $response->assertOk();
        $response->assertSee('契約分析');
        $response->assertSee('解約分析');
        $response->assertSee('年計');
        $response->assertSee('月計');
    }
}
