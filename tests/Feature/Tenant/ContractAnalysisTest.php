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
 * テナント契約の「契約・解約」年別（最大10年）／月別（全年合算）集計の検証。
 *
 * 集計は DB非依存（PHP/Carbon）のため SQLite in-memory でも YEAR()/MONTH() 問題なし。
 * Contract は HasFactory だが ContractFactory 未定義 → create() 直接で組み立てる。
 * byMonth.values は index0=1月 … index11=12月 のリスト。
 * 設計の正: docs/superpowers/specs/2026-07-14-tenant-analysis-year-month-split-design.md
 */
class ContractAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** 物件＋区画＋顧客＋契約を1セット作成して返す。 */
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

    /** CheckDepartmentAccess を無条件パススルーする経営層ユーザー */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** T1: 年別は contract_date の暦年で件数集計され、labels は昇順（古い→新しい） */
    public function test_year_summary_counts_ascending(): void
    {
        $this->makeContract('tenant', '2024-08-10');
        $this->makeContract('tenant', '2024-08-25');
        $this->makeContract('tenant', '2025-03-05');

        $c = (new ContractAnalysisService)->build()['contract'];

        $this->assertSame([2024, 2025], $c['byYear']['labels']); // 昇順
        $this->assertSame([2, 1], $c['byYear']['values']);       // 2024:2件 / 2025:1件
        $this->assertSame(3, $c['byYear']['total']);
    }

    /** T1b: 契約集計は contract_date 基準（rent_start_date が別年月でも contract_date 側に立つ） */
    public function test_contract_uses_contract_date_not_rent_start_date(): void
    {
        // contract_date=2024/8・rent_start_date=2025/1（家賃発生日を別年月にする）
        $this->makeContract('tenant', '2024-08-10', 'active', null, '2025-01-15');

        $c = (new ContractAnalysisService)->build()['contract'];

        $this->assertSame([2024], $c['byYear']['labels']); // 契約日の年に計上（rent_start_date の 2025 は含まない）
        $this->assertSame(1, $c['byYear']['total']);
        $this->assertSame(1, $c['byMonth']['values'][7]);  // 8月（index7）= contract_date
        $this->assertSame(0, $c['byMonth']['values'][0]);  // 1月（index0）= rent_start_date の月には立たない
    }

    /** T2: 月別は全年合算（異なる年の同月が合算される）。values は index0=1月 */
    public function test_month_summary_aggregates_all_years(): void
    {
        $this->makeContract('tenant', '2024-08-10');
        $this->makeContract('tenant', '2025-08-20'); // 別年の8月
        $this->makeContract('tenant', '2024-03-05');

        $c = (new ContractAnalysisService)->build()['contract'];

        $this->assertSame(range(1, 12), $c['byMonth']['labels']);
        $this->assertSame(2, $c['byMonth']['values'][7]); // 8月（index7）= 2024/8 + 2025/8
        $this->assertSame(1, $c['byMonth']['values'][2]); // 3月（index2）
        $this->assertSame(3, $c['byMonth']['total']);
    }

    /** T3: 年別は新しい方から最大10年・古い年は落ちる。年別total（10年）≠月別total（全期間） */
    public function test_year_capped_at_10_and_totals_diverge(): void
    {
        // 2015〜2025 の11種類の年に各1件（全て6月）
        foreach (range(2015, 2025) as $y) {
            $this->makeContract('tenant', "{$y}-06-10");
        }

        $c = (new ContractAnalysisService)->build()['contract'];

        $this->assertCount(10, $c['byYear']['labels']);
        $this->assertSame(2016, $c['byYear']['labels'][0]);      // 最古の表示年（2015 は落ちる）
        $this->assertSame(2025, $c['byYear']['labels'][9]);      // 最新
        $this->assertNotContains(2015, $c['byYear']['labels']);
        $this->assertSame(10, $c['byYear']['total']);            // 直近10年計
        $this->assertSame(11, $c['byMonth']['total']);           // 全期間計（不一致）
        $this->assertSame(11, $c['byMonth']['values'][5]);       // 6月（index5）に11件
    }

    /** T4: 契約=contract_date 基準・解約=contract_end_date 基準（同一契約が別セル） */
    public function test_contract_uses_contract_date_termination_uses_end_date(): void
    {
        $this->makeContract('tenant', '2024-08-10', 'terminated', '2025-03-20');

        $data = (new ContractAnalysisService)->build();

        // 契約: 2024/8
        $this->assertSame([2024], $data['contract']['byYear']['labels']);
        $this->assertSame(1, $data['contract']['byMonth']['values'][7]); // 8月
        // 解約: 2025/3
        $this->assertSame([2025], $data['termination']['byYear']['labels']);
        $this->assertSame(1, $data['termination']['byMonth']['values'][2]); // 3月
    }

    /** T5: active 契約は contract_end_date を持っていても解約集計から除外 */
    public function test_active_excluded_from_termination(): void
    {
        $this->makeContract('tenant', '2024-08-10', 'active', '2025-12-31');

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(1, $data['contract']['byYear']['total']);    // 契約には出る
        $this->assertSame(0, $data['termination']['byYear']['total']); // 解約には出ない
        $this->assertSame(0, $data['termination']['byMonth']['total']);
    }

    /** T6: terminated だが contract_end_date=null は解約に出ない（契約には出る） */
    public function test_terminated_without_end_date_excluded(): void
    {
        $this->makeContract('tenant', '2024-08-10', 'terminated', null);

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(1, $data['contract']['byYear']['total']);
        $this->assertSame(0, $data['termination']['byYear']['total']);
    }

    /** T7: tenant 以外の department は契約・解約どちらにも計上されない */
    public function test_non_tenant_department_excluded(): void
    {
        $this->makeContract('mansion', '2024-08-10', 'terminated', '2025-03-20');

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(0, $data['contract']['byYear']['total']);
        $this->assertSame(0, $data['termination']['byYear']['total']);
    }

    /** T8: 論理削除された契約は両集計に出ない（SoftDeletes グローバルスコープ） */
    public function test_soft_deleted_excluded(): void
    {
        $contract = $this->makeContract('tenant', '2024-08-10', 'terminated', '2025-03-20');
        $contract->delete();

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(0, $data['contract']['byYear']['total']);
        $this->assertSame(0, $data['termination']['byYear']['total']);
    }

    /** T9: 空データ → labels/values 空・total=0・月別は 0×12（ゼロ除算しない） */
    public function test_empty_data(): void
    {
        $data = (new ContractAnalysisService)->build();

        $this->assertSame([], $data['contract']['byYear']['labels']);
        $this->assertSame([], $data['contract']['byYear']['values']);
        $this->assertSame(0, $data['contract']['byYear']['total']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], $data['contract']['byMonth']['values']);
        $this->assertSame(0, $data['contract']['byMonth']['total']);
        $this->assertSame(0, $data['termination']['byYear']['total']);
    }

    /** T10: GET /tenant/analysis が 200 で、両タブ・年別/月別カードが描画される */
    public function test_page_renders_cards(): void
    {
        $this->makeContract('tenant', '2024-08-10', 'terminated', '2025-03-20');

        $response = $this->actingAs($this->executive())->get('/tenant/analysis');

        $response->assertOk();
        $response->assertSee('契約分析');
        $response->assertSee('解約分析');
        $response->assertSee('年別集計');
        $response->assertSee('月別集計');

        // canvas が実際に両タブ・両軸ぶん描画されている（half-render 検知）
        $response->assertSee('chart-contract-year', false);
        $response->assertSee('chart-contract-month', false);
        $response->assertSee('chart-termination-year', false);
        $response->assertSee('chart-termination-month', false);
    }

    /** T11: 0件のとき両タブとも「◯◯データがありません」を表示（空データ分岐のレンダリング健全性・Bug#26 型ガード） */
    public function test_empty_data_renders_no_data_message(): void
    {
        $response = $this->actingAs($this->executive())->get('/tenant/analysis');

        $response->assertOk();
        $response->assertSee('契約データがありません');
        $response->assertSee('解約データがありません');
    }

    /** T12: 年度別の月別集計（byMonthByYear）。年降順キー・各年 values(index0=1月)/total */
    public function test_month_by_year_summary(): void
    {
        $this->makeContract('tenant', '2024-08-10');
        $this->makeContract('tenant', '2024-08-25');
        $this->makeContract('tenant', '2024-03-05');
        $this->makeContract('tenant', '2025-08-20');

        $byYear = (new ContractAnalysisService)->build()['contract']['byMonthByYear'];

        // 年降順（最新が先頭キー・セレクト表示順）
        $this->assertSame([2025, 2024], array_keys($byYear));
        $this->assertSame(2025, array_key_first($byYear));
        // 2024年: 8月×2・3月×1・計3
        $this->assertSame(2, $byYear[2024]['values'][7]); // 8月（index7）
        $this->assertSame(1, $byYear[2024]['values'][2]); // 3月（index2）
        $this->assertSame(3, $byYear[2024]['total']);
        // 2025年: 8月×1・計1
        $this->assertSame(1, $byYear[2025]['values'][7]); // 8月（index7）
        $this->assertSame(1, $byYear[2025]['total']);
    }

    /** T13: 空データ → byMonthByYear は空配列（年度が1つも無い） */
    public function test_month_by_year_empty(): void
    {
        $byYear = (new ContractAnalysisService)->build()['contract']['byMonthByYear'];

        $this->assertSame([], $byYear);
    }

    /** T14: 複数年データで月別カードに年度セレクトの option（全期間・◯◯年）が描画される */
    public function test_month_year_selector_rendered(): void
    {
        $this->makeContract('tenant', '2024-08-10');
        $this->makeContract('tenant', '2025-03-05');

        $response = $this->actingAs($this->executive())->get('/tenant/analysis');

        $response->assertOk();
        $response->assertSee('全期間');
        $response->assertSee('2024年');
        $response->assertSee('2025年');
    }

    /** T15: month payload（all リネーム・years 年降順・year は string・旧 values キー無し）を HTTP viewData で検証 */
    public function test_chart_payload_month_shape(): void
    {
        $this->makeContract('tenant', '2024-08-10');
        $this->makeContract('tenant', '2025-03-05');

        $response = $this->actingAs($this->executive())->get('/tenant/analysis');
        $response->assertOk();
        $month = $response->viewData('charts')['contract']['month'];

        // all = 全期間の12件（3月=index2・8月=index7 に各1）
        $this->assertSame([0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0], $month['all']);
        // years は年降順・year は string（int 退行検知）・total 一致
        $this->assertSame(['2025', '2024'], array_column($month['years'], 'year'));
        $this->assertSame('2025', $month['years'][0]['year']);
        $this->assertSame(1, $month['years'][0]['total']);
        // labels は '◯月'
        $this->assertSame('1月', $month['labels'][0]);
        $this->assertSame('12月', $month['labels'][11]);
        // 旧 values キーは月 payload に存在しない
        $this->assertArrayNotHasKey('values', $month);
    }
}
