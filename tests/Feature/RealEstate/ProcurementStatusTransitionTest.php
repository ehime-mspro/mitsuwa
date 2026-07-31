<?php

namespace Tests\Feature\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Enums\UserRole;
use App\Http\Controllers\DashboardController;
use App\Models\Buyer;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 契約の登録・更新・削除に連動した仕入れ案件ステータスの自動遷移と、
 * 一覧フィルタからの販売済除外を検証する。
 *
 * re_* / buyers は migration 管理外のため CreatesRealEstateSchema trait で構築する。
 */
class ProcurementStatusTransitionTest extends TestCase
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
     * - 契約の削除（role:executive）まで到達できる
     * - must_change_password はマイグレーション既定が true なので明示的に false にする
     *   （true のままだと ForcePasswordChange が password.change へリダイレクトする）
     */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * ⚠ 一覧画面が描画するのは procurement_code ではなく property_name（実測）。
     * フィルタのアサーションは property_name（= "物件{$code}"）で行うこと。
     */
    private function makeProcurement(string $code, string $status = 'selling'): ReProcurement
    {
        return ReProcurement::create([
            'procurement_code'  => $code,
            'property_type'     => RealEstatePropertyType::UsedHouse->value,
            'transaction_type'  => RealEstateTransactionType::Purchase->value,
            'status'            => $status,
            'property_name'     => "物件{$code}",
            'address'           => '愛媛県松山市1-1-1',
            'created_by'        => 1,
        ]);
    }

    private function makeBuyer(): Buyer
    {
        return Buyer::create(['last_name' => '山田', 'first_name' => '太郎']);
    }

    public function test_schema_is_built_and_models_are_persistable(): void
    {
        $procurement = $this->makeProcurement('P-001');
        $buyer       = $this->makeBuyer();

        $this->assertSame(ProcurementStatus::Selling, $procurement->fresh()->status);
        $this->assertSame('山田', $buyer->fresh()->last_name);
    }

    /** T1: 仕入れ販売契約を登録すると、その案件が販売済になる */
    public function test_storing_procurement_contract_marks_procurement_as_sold(): void
    {
        $procurement = $this->makeProcurement('P-001');
        $buyer       = $this->makeBuyer();

        $response = $this->actingAs($this->executive())->post('/realestate/contracts', [
            'contract_type'   => 'procurement_land',
            'procurement_id'  => $procurement->id,
            'contract_date'   => '2026-07-21',
            'buyer_id'        => $buyer->id,
            'contract_amount_land' => 30000000,
            'cost_amount'     => 25000000,
            'property_name'   => '松山市A土地',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(ProcurementStatus::Sold, $procurement->fresh()->status);
    }

    /** T2: 仲介契約（procurement_id を持たない）はどの案件のステータスも変えない */
    public function test_storing_brokerage_contract_leaves_procurements_untouched(): void
    {
        $procurement = $this->makeProcurement('P-001');

        $response = $this->actingAs($this->executive())->post('/realestate/contracts', [
            'contract_type'           => 'brokerage',
            'property_name'           => '仲介物件B',
            'brokerage_selling_price' => 20000000,
            'brokerage_fee'           => 660000,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(ProcurementStatus::Selling, $procurement->fresh()->status);
    }

    /** T3: 契約の案件を A→B に付け替えると、A が販売中に戻り B が販売済になる */
    public function test_updating_procurement_id_reverts_old_and_marks_new(): void
    {
        $procurementA = $this->makeProcurement('P-001');
        $procurementB = $this->makeProcurement('P-002');
        $buyer        = $this->makeBuyer();
        $executive    = $this->executive();

        $this->actingAs($executive)->post('/realestate/contracts', [
            'contract_type'   => 'procurement_land',
            'procurement_id'  => $procurementA->id,
            'contract_date'   => '2026-07-21',
            'buyer_id'        => $buyer->id,
            'contract_amount_land' => 30000000,
            'cost_amount'     => 25000000,
            'property_name'   => '松山市A土地',
        ])->assertSessionHasNoErrors();

        $contract = ReContract::firstOrFail();

        $response = $this->actingAs($executive)->put("/realestate/contracts/{$contract->id}", [
            'contract_type'   => 'procurement_land',
            'procurement_id'  => $procurementB->id,
            'contract_date'   => '2026-07-21',
            'buyer_id'        => $buyer->id,
            'contract_amount_land' => 30000000,
            'cost_amount'     => 25000000,
            'property_name'   => '松山市B土地',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(ProcurementStatus::Selling, $procurementA->fresh()->status);
        $this->assertSame(ProcurementStatus::Sold, $procurementB->fresh()->status);
    }

    /**
     * T3b: 案件を変えずに契約を更新しても、案件は販売済のまま。
     *
     * update() の遷移は `!=` ガード 2 本に依存しており、これを崩すと
     * 「金額だけ直す」という実運用で最頻の編集で案件が販売中に戻り、
     * 一覧に復活して契約作成セレクトにも再登場する。T3（付け替え）だけでは
     * このガードが効いていることを固定できないため独立したテストを置く。
     */
    public function test_updating_contract_without_changing_procurement_keeps_sold(): void
    {
        $procurement = $this->makeProcurement('P-001');
        $buyer       = $this->makeBuyer();
        $executive   = $this->executive();

        $this->actingAs($executive)->post('/realestate/contracts', [
            'contract_type'   => 'procurement_land',
            'procurement_id'  => $procurement->id,
            'contract_date'   => '2026-07-21',
            'buyer_id'        => $buyer->id,
            'contract_amount_land' => 30000000,
            'cost_amount'     => 25000000,
            'property_name'   => '松山市A土地',
        ])->assertSessionHasNoErrors();

        $contract = ReContract::firstOrFail();

        // 案件はそのまま、契約金額だけ変更する
        $response = $this->actingAs($executive)->put("/realestate/contracts/{$contract->id}", [
            'contract_type'   => 'procurement_land',
            'procurement_id'  => $procurement->id,
            'contract_date'   => '2026-07-21',
            'buyer_id'        => $buyer->id,
            'contract_amount_land' => 31000000,
            'cost_amount'     => 25000000,
            'property_name'   => '松山市A土地',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(ProcurementStatus::Sold, $procurement->fresh()->status);
        $this->assertSame(31000000, $contract->fresh()->contract_amount_land);
    }

    /** T4: 契約を削除すると案件が販売中に戻る（誤登録を消したとき行方不明にしない） */
    public function test_destroying_contract_reverts_procurement_to_selling(): void
    {
        $procurement = $this->makeProcurement('P-001');
        $buyer       = $this->makeBuyer();
        $executive   = $this->executive();

        $this->actingAs($executive)->post('/realestate/contracts', [
            'contract_type'   => 'procurement_land',
            'procurement_id'  => $procurement->id,
            'contract_date'   => '2026-07-21',
            'buyer_id'        => $buyer->id,
            'contract_amount_land' => 30000000,
            'cost_amount'     => 25000000,
            'property_name'   => '松山市A土地',
        ])->assertSessionHasNoErrors();

        $this->assertSame(ProcurementStatus::Sold, $procurement->fresh()->status);

        $contract = ReContract::firstOrFail();

        $response = $this->actingAs($executive)->delete("/realestate/contracts/{$contract->id}");

        $response->assertRedirect();
        $this->assertSame(ProcurementStatus::Selling, $procurement->fresh()->status);
    }

    /** T5: 一覧の既定フィルタ（進行中のみ）に販売済は含まれない */
    public function test_index_default_filter_excludes_sold(): void
    {
        $selling = $this->makeProcurement('P-001', 'selling');
        $sold    = $this->makeProcurement('P-002', 'sold');
        $lost    = $this->makeProcurement('P-003', 'lost');

        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        // 一覧が描画するのは property_name（procurement_code は出ない）
        $response->assertSee($selling->property_name);
        $response->assertDontSee($sold->property_name);
        $response->assertDontSee($lost->property_name);
    }

    /** T6: ?status=sold なら販売済だけが出る（enum に case を足した時点でセレクトに現れる） */
    public function test_index_status_sold_shows_only_sold(): void
    {
        $selling = $this->makeProcurement('P-001', 'selling');
        $sold    = $this->makeProcurement('P-002', 'sold');

        $response = $this->actingAs($this->executive())->get('/realestate/procurements?status=sold');

        $response->assertOk();
        $response->assertSee($sold->property_name);
        $response->assertDontSee($selling->property_name);
    }

    /** 経営ダッシュボードの仕入れパイプラインからも販売済を除外する */
    public function test_executive_dashboard_pipeline_excludes_sold(): void
    {
        $selling = $this->makeProcurement('P-001', 'selling');
        $selling->update(['target_selling_price_land' => 10000000]);

        $sold = $this->makeProcurement('P-002', 'sold');
        $sold->update(['target_selling_price_land' => 99000000]);

        // aggregateProcurementStats() は private。/dashboard/executive を丸ごと叩くと
        // 5 事業分のテーブルが要るため、対象メソッドだけを Reflection で呼ぶ
        // （CustomerSurveyAuthorizationTest と同じ既存パターン）。
        $method = new \ReflectionMethod(DashboardController::class, 'aggregateProcurementStats');
        $result = $method->invoke(new DashboardController());

        $this->assertSame(1, $result['in_progress_count']);
        $this->assertSame(10000000, $result['target_total']);
    }

    /** 契約がある案件の詳細に「契約情報」カードが出て、契約詳細へ辿れる */
    public function test_show_renders_contract_card_when_contract_exists(): void
    {
        $procurement = $this->makeProcurement('P-001');
        $buyer       = $this->makeBuyer();
        $executive   = $this->executive();

        $this->actingAs($executive)->post('/realestate/contracts', [
            'contract_type'   => 'procurement_land',
            'procurement_id'  => $procurement->id,
            'contract_date'   => '2026-07-21',
            'buyer_id'        => $buyer->id,
            'contract_amount_land' => 30000000,
            'cost_amount'     => 25000000,
            'property_name'   => '松山市A土地',
        ])->assertSessionHasNoErrors();

        $contract = ReContract::firstOrFail();

        $response = $this->actingAs($executive)->get("/realestate/procurements/{$procurement->id}");

        $response->assertOk();
        $response->assertSee('契約情報');
        $response->assertSee('山田 太郎');
        $response->assertSee('30,000,000円');
        $response->assertSee('5,000,000円');   // 粗利 = 契約金額 - 原価
        $response->assertSee("/realestate/contracts/{$contract->id}");
    }

    /** 契約が無い案件では「契約情報」カードごと出さない（空カードは情報量が無い） */
    public function test_show_hides_contract_card_when_no_contract(): void
    {
        $procurement = $this->makeProcurement('P-001');

        $response = $this->actingAs($this->executive())->get("/realestate/procurements/{$procurement->id}");

        $response->assertOk();
        $response->assertDontSee('契約情報');
    }

    /**
     * T7: 「ステータス: 全て」（?status=）は全ステータスを出す。
     *
     * ConvertEmptyStringsToNull がクエリ文字列の空文字も null 化するため、
     * `$request->input('status', 'active')` は既定値ではなく null を返す。
     * `!== ''` 比較だと null が素通りして where('status', null) となり 0 件になる。
     */
    public function test_index_status_all_shows_every_status(): void
    {
        $selling = $this->makeProcurement('P-001', 'selling');
        $sold    = $this->makeProcurement('P-002', 'sold');
        $lost    = $this->makeProcurement('P-003', 'lost');

        $response = $this->actingAs($this->executive())->get('/realestate/procurements?status=');

        $response->assertOk();
        $response->assertSee($selling->property_name);
        $response->assertSee($sold->property_name);
        $response->assertSee($lost->property_name);
    }

    /**
     * T8: 「全て」選択時はセレクトも「全て」を選択状態で描画する。
     *
     * 一覧は全件出ているのにセレクトが「進行中のみ」に見える不一致を防ぐ。
     * assertSee は導入文に一致して false-pass しやすいので option の生 HTML で見る。
     */
    public function test_index_status_all_marks_all_option_selected(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/procurements?status=');

        $response->assertOk();
        $response->assertSee('<option value="" selected>', false);
        $response->assertDontSee('<option value="active" selected>', false);
    }

    /** T9: 無指定（既定＝進行中のみ）ではセレクトも「進行中のみ」を選択状態で描画する */
    public function test_index_default_marks_active_option_selected(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/procurements');

        $response->assertOk();
        $response->assertSee('<option value="active" selected>', false);
        $response->assertDontSee('<option value="" selected>', false);
    }
}
