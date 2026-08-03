<?php

namespace Tests\Feature\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Enums\ReContractStatus;
use App\Enums\ReContractType;
use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\ReContract;
use App\Models\ReCostItem;
use App\Models\ReProcurement;
use App\Models\ReProcurementCost;
use App\Models\ReProject;
use App\Models\ReProjectCost;
use App\Models\ReProjectLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 契約詳細の「原価」は 2 つのソースを持つ。両方を画面に出すことを固定する。
 *
 * - 内訳（各行）   … 仕入れ案件・分譲地から毎回引く**ライブ値**
 * - 契約の cost_amount … 契約時点の**スナップショット**（利用者が手編集もできる必須項目）
 *
 * ⚠ 2026-08-03 本番実測で、契約 id 2（JG山西古澤邸）が
 *      内訳 7 行の合計 26,700,000 円
 *      表示された「原価合計」 25,095,455 円
 *    と食い違っていた。罫線で仕切って「原価合計」と書いてあるので**内訳の合計に見える**が、
 *    実体は別ソース（`$contract->cost_amount`）だった。
 *
 *    差 1,604,545 円の正体は、契約作成時の仕入れ案件が
 *    土地 7,350,000 円 / 建物 17,650,000 円（税込）に分かれており、
 *    `getPurchasePriceTotal()` が税抜（7,350,000 + ⌈17,650,000÷1.1⌉ = 23,395,455）を返していたこと。
 *    土地/建物分割の移行で建物が 0 に寄った結果、土地は非課税なので割り戻しが消えて
 *    25,000,000 になり、スナップショットだけが取り残された。
 *
 * ⚠ **スナップショットを捨ててライブに追随させるのは誤り**（契約時点の記録が失われる）。
 *    仕様は「両方出して乖離を見せる」。粗利は契約時点の原価で計算し続ける。
 */
class ContractCostDivergenceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * 原価行を持つ仕入れ案件を作る。
     *
     * ⚠ 購入価格・査定価格は敢えて空にする。入れると `saved` フックの
     *    `syncPropertyPurchaseCost()` が「物件購入費」行を自動生成し、
     *    このテストが意図した合計にならない。
     */
    private function makeProcurementWithCosts(array $amounts): ReProcurement
    {
        $proc = ReProcurement::create([
            'procurement_code' => 'PRC-COST-001',
            'property_type'    => RealEstatePropertyType::UsedHouse->value,
            'transaction_type' => RealEstateTransactionType::Purchase->value,
            'status'           => ProcurementStatus::Selling->value,
            'property_name'    => 'JG山西古澤邸',
            'address'          => '愛媛県松山市山西町53-18',
            'created_by'       => 1,
        ]);

        $order = 0;
        foreach ($amounts as $name => $amount) {
            $item = ReCostItem::create(['name' => $name, 'sort_order' => $order++, 'is_active' => true]);
            ReProcurementCost::create([
                'procurement_id'   => $proc->id,
                'cost_item_id'     => $item->id,
                'estimated_amount' => $amount,
            ]);
        }

        return $proc->fresh();
    }

    private function makeContract(ReProcurement $proc, ?int $costAmount): ReContract
    {
        return ReContract::create([
            'department'           => 'realestate',
            'contract_type'        => ReContractType::ProcurementHouse->value,
            'status'               => ReContractStatus::Contracted->value,
            'contract_date'        => '2026-07-19',
            'property_name'        => 'JG山西古澤邸',
            'procurement_id'       => $proc->id,
            'contract_amount_land' => 27363636,
            'cost_amount'          => $costAmount,
            'gross_profit'         => 27363636 - (int) $costAmount,
            'buyer_id'             => Buyer::create(['last_name' => '古澤', 'first_name' => '一郎'])->id,
            'created_by'           => 1,
        ]);
    }

    /** 本番で実際に起きていた組み合わせ（内訳 26,700,000 / スナップショット 25,095,455）。 */
    private function divergentContract(): ReContract
    {
        $proc = $this->makeProcurementWithCosts([
            '物件購入費' => 25000000,
            '取得費'     => 1700000,
        ]);

        return $this->makeContract($proc, 25095455);
    }

    // ============================================================

    /**
     * 乖離しているとき、スナップショットとライブの**両方**が出て警告が付くこと。
     *
     * ⚠ 「原価合計」だけを 1 つ出す実装に戻すと、どちらか一方しか出ないのでここが落ちる。
     */
    public function test_show_displays_both_snapshot_and_live_cost_when_they_diverge(): void
    {
        $contract = $this->divergentContract();

        $response = $this->actingAs($this->executive())
            ->get("/realestate/contracts/{$contract->id}");

        $response->assertOk();

        // 内訳のライブ合計と、契約時点のスナップショットの両方が view に渡っていること
        $this->assertSame(26700000, $response->viewData('liveCost'));
        $this->assertSame(1604545, $response->viewData('costDivergence'));

        // 画面に両方の数字と、役割を示すラベルが出ていること
        // ⚠ 金額だけを見ると内訳の行にも一致して false-pass するのでラベルと対で見る
        $response->assertSee('契約時点の原価');
        $response->assertSee('25,095,455円');
        $response->assertSee('現在の仕入れ原価');
        $response->assertSee('26,700,000円');

        // 乖離額は警告文にしか現れない一意な値
        $response->assertSee('1,604,545円');
        $response->assertSee('契約後に仕入れ案件の原価が');

        // 上部 KPI の注記。⚠ 乖離額そのものは下の警告文と同じ値なので、
        //    金額で見ると KPI の注記を消しても false-pass する（2026-08-03 実測）。
        //    KPI 固有の文言で見ること。
        $response->assertSee('原価（契約時点）');
        $response->assertSee('現在の原価と');
    }

    /**
     * ライブ合計は**内訳の積み上げ**であること。
     *
     * ⚠ view に渡す値を別ソース（保存カラム等）から作る実装に戻したらここが落ちる。
     *    `costBreakdown` 経由でなく DB から独立に計算して突き合わせる（自明な検証にしない）。
     */
    public function test_live_cost_equals_sum_of_the_breakdown_rows(): void
    {
        $contract = $this->divergentContract();

        $response = $this->actingAs($this->executive())
            ->get("/realestate/contracts/{$contract->id}");

        $expected = (int) ReProcurementCost::where('procurement_id', $contract->procurement_id)
            ->get()
            ->sum(fn ($c) => $c->actual_amount ?? $c->estimated_amount);

        $this->assertSame(2, ReProcurementCost::where('procurement_id', $contract->procurement_id)->count(), '原価行が作れていない（走査の空振り防止）');
        $this->assertSame($expected, $response->viewData('liveCost'));
    }

    /** 一致しているときは警告を出さない（常時警告になっていたら気づけない）。 */
    public function test_no_warning_when_snapshot_matches_live_cost(): void
    {
        $proc     = $this->makeProcurementWithCosts(['物件購入費' => 25000000, '取得費' => 1700000]);
        $contract = $this->makeContract($proc, 26700000);   // ライブ合計と一致させる

        $response = $this->actingAs($this->executive())
            ->get("/realestate/contracts/{$contract->id}");

        $response->assertOk();
        $this->assertNull($response->viewData('costDivergence'));
        $response->assertDontSee('契約後に仕入れ案件の原価が');
        $response->assertDontSee('現在の原価と');
    }

    /**
     * 粗利は**契約時点のスナップショット**で計算し続けること。
     *
     * ⚠ 「乖離を見せる」対応のついでに粗利までライブへ寄せると、
     *    確定した契約の粗利が後から動く。仕様はスナップショット維持。
     */
    public function test_gross_profit_still_uses_the_contract_snapshot(): void
    {
        $contract = $this->divergentContract();

        // 27,363,636 − 25,095,455（スナップショット）= 2,268,181
        // ライブの 26,700,000 を使っていたら 663,636 になる
        $this->assertSame(2268181, (int) $contract->gross_profit);
        $this->assertSame(2268181, $contract->calculateGrossProfit());

        $this->actingAs($this->executive())
            ->get("/realestate/contracts/{$contract->id}")
            ->assertSee('2,268,181円');
    }

    /**
     * 分譲地区画の契約でも同じ乖離表示が出ること。
     *
     * ⚠ **入口ごとに測る**（docs/RULES.md Bug #44）。仕入れ系だけテストしていた間、
     *    分譲地側のライブ値を `0` に潰しても 471 テスト全部が緑のままだった（2026-08-03 実測）。
     */
    public function test_subdivision_contract_also_shows_both_costs(): void
    {
        $project = ReProject::create([
            'project_code' => 'PJ-COST-001',
            'project_name' => '分譲地A',
            'status'       => 'selling',
            'address'      => '愛媛県松山市2-2-2',
            'created_by'   => 1,
        ]);

        // 4 区画で按分 → 区画あたり 26,700,000 ÷ 4 = 6,675,000
        foreach (range(1, 4) as $n) {
            ReProjectLot::create([
                'project_id' => $project->id, 'lot_number' => $n,
                'area_sqm' => 100.00, 'area_tsubo' => 30.25, 'status' => 'on_sale',
            ]);
        }
        $item = ReCostItem::create(['name' => '造成費', 'sort_order' => 0, 'is_active' => true]);
        ReProjectCost::create([
            'project_id' => $project->id, 'cost_item_id' => $item->id, 'estimated_amount' => 26700000,
        ]);

        $contract = ReContract::create([
            'department'           => 'realestate',
            'contract_type'        => ReContractType::SubdivisionLot->value,
            'status'               => ReContractStatus::Contracted->value,
            'contract_date'        => '2026-07-19',
            'property_name'        => '分譲地A 1区画',
            'project_id'           => $project->id,
            'contract_amount_land' => 9000000,
            'cost_amount'          => 6000000,        // 区画あたり原価 6,675,000 とわざとずらす
            'gross_profit'         => 3000000,
            'buyer_id'             => Buyer::create(['last_name' => '分譲', 'first_name' => '花子'])->id,
            'created_by'           => 1,
        ]);

        $response = $this->actingAs($this->executive())
            ->get("/realestate/contracts/{$contract->id}");

        $response->assertOk();
        $this->assertSame(6675000, $response->viewData('liveCost'));
        $this->assertSame(675000, $response->viewData('costDivergence'));

        $response->assertSee('契約時点の原価');
        $response->assertSee('6,000,000円');
        $response->assertSee('現在の区画あたり原価');
        $response->assertSee('6,675,000円');
        $response->assertSee('675,000円');
        $response->assertSee('契約後に分譲地の原価が');
        $response->assertSee('原価（契約時点）');
        $response->assertSee('現在の原価と');
    }

    /** 仕入れ案件に紐づかない契約でも 500 にならない（乖離は算出しようがないので null）。 */
    public function test_contract_without_procurement_renders(): void
    {
        $contract = ReContract::create([
            'department'           => 'realestate',
            'contract_type'        => ReContractType::Brokerage->value,
            'status'               => ReContractStatus::Closed->value,
            'contract_date'        => '2026-07-19',
            'property_name'        => '仲介物件',
            'brokerage_fee'        => 500000,
            'buyer_id'             => Buyer::create(['last_name' => '仲介', 'first_name' => '太郎'])->id,
            'created_by'           => 1,
        ]);

        $response = $this->actingAs($this->executive())
            ->get("/realestate/contracts/{$contract->id}");

        $response->assertOk();
        $this->assertNull($response->viewData('liveCost'));
        $this->assertNull($response->viewData('costDivergence'));
    }
}
