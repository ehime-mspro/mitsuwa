<?php

namespace Tests\Feature\RealEstate;

use App\Enums\BuyerRank;
use App\Enums\ProcurementStatus;
use App\Enums\ProjectStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Enums\ReContractStatus;
use App\Enums\ReContractType;
use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\BuyerDepartmentPivot;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 不動産契約の入口で買主ランクが「成約」になることを固定する（設計書 §7.1 #1-5, #11-15）。
 *
 * ⚠ 実装はモデルイベント 1 箇所だが、**その入口が実際にイベントを通るか**は入口ごとに
 *    確かめないと分からない（クエリビルダ経由の update はイベントを通らない）。Bug #44。
 *
 * ⚠ 各テストは必ず「リクエストが成功したこと」を assert する。バリデーションで弾かれた場合、
 *    「ランクが変わらない」系のテストは**壊れていても緑**になる（false-pass）。
 *
 * ⚠ 編集系は HTTP 経由なのでルートモデルバインディングで DB から取り直したインスタンスが渡る。
 *    直接 $model->update() を書くと wasRecentlyCreated が true のまま残り、
 *    wasChanged ガードの変異を隠してしまう（Bug #39）。HTTP を通すこと。
 */
class ReContractBuyerRankTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    // ---------------- ヘルパー ----------------

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * 買主を作る。$departments は ['realestate' => ['rank' => 'B', 'acquired_date' => '2026-01-05']] の形。
     */
    private function makeBuyer(string $lastName, array $departments = []): Buyer
    {
        $buyer = Buyer::create(['last_name' => $lastName, 'first_name' => '太郎']);

        foreach ($departments as $dept => $spec) {
            BuyerDepartmentPivot::create([
                'buyer_id'      => $buyer->id,
                'department'    => $dept,
                'acquired_date' => $spec['acquired_date'] ?? '2026-01-05',
                'rank'          => $spec['rank'] ?? BuyerRank::C->value,
            ]);
        }

        return $buyer;
    }

    private function pivot(Buyer $buyer, string $department): ?BuyerDepartmentPivot
    {
        return BuyerDepartmentPivot::where('buyer_id', $buyer->id)
            ->where('department', $department)
            ->first();
    }

    private function rankOf(Buyer $buyer, string $department): ?BuyerRank
    {
        return $this->pivot($buyer, $department)?->rank;
    }

    /** 査定・購入価格は入れない（saved フックの syncPropertyPurchaseCost を無効化して素の状態に保つ）。 */
    private function makeProcurement(string $code = 'PRC-RANK-001'): ReProcurement
    {
        return ReProcurement::create([
            'procurement_code' => $code,
            'property_type'    => RealEstatePropertyType::UsedHouse->value,
            'transaction_type' => RealEstateTransactionType::Purchase->value,
            'status'           => ProcurementStatus::Selling->value,
            'property_name'    => 'ランク検証物件',
            'address'          => '愛媛県松山市山西町53-18',
            'created_by'       => 1,
        ]);
    }

    private function makeLot(): ReProjectLot
    {
        $project = ReProject::create([
            'project_code' => 'PRJ-RANK-001',
            'project_name' => 'ランク検証分譲地',
            'status'       => ProjectStatus::Selling->value,
            'address'      => '愛媛県松山市石井町1-2-3',
            'created_by'   => 1,
        ]);

        return ReProjectLot::create([
            'project_id' => $project->id,
            'lot_number' => 1,
            'area_sqm'   => 132.69,
            'area_tsubo' => 40.13,
        ]);
    }

    private function procurementPayload(ReProcurement $proc, Buyer $buyer, array $overrides = []): array
    {
        return array_merge([
            'contract_type'        => ReContractType::ProcurementHouse->value,
            'procurement_id'       => $proc->id,
            'contract_date'        => '2026-07-19',
            'buyer_id'             => $buyer->id,
            'contract_amount_land' => 30000000,
            'cost_amount'          => 25000000,
            'property_name'        => 'ランク検証物件',
        ], $overrides);
    }

    // ---------------- #1 / #2 / #3: 登録の入口 ----------------

    /** #1 仕入れ系の契約登録で買主が成約になる。 */
    public function test_procurement_contract_store_marks_buyer_contracted(): void
    {
        $buyer = $this->makeBuyer('仕入', ['realestate' => ['rank' => BuyerRank::C->value]]);
        $proc  = $this->makeProcurement();

        $response = $this->actingAs($this->executive())
            ->post('/realestate/contracts', $this->procurementPayload($proc, $buyer));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'realestate'));
    }

    /** #2 分譲地の契約登録で買主が成約になる。 */
    public function test_subdivision_contract_store_marks_buyer_contracted(): void
    {
        $buyer = $this->makeBuyer('分譲', ['realestate' => ['rank' => BuyerRank::C->value]]);
        $lot   = $this->makeLot();

        $response = $this->actingAs($this->executive())->post('/realestate/contracts', [
            'contract_type'        => ReContractType::SubdivisionLot->value,
            'project_id'           => $lot->project_id,
            'lot_id'               => $lot->id,
            'contract_date'        => '2026-07-19',
            'buyer_id'             => $buyer->id,
            'contract_amount_land' => 20000000,
            'cost_amount'          => 15000000,
            'property_name'        => 'ランク検証分譲地 1号地',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'realestate'));
    }

    /**
     * #3 仲介登録では誰のランクも変わらない。
     *
     * ⚠ これは「仲介が対象外である」という仕様の記録であって、特定の変異を捕まえるテストではない
     *    （仲介は buyer_id を持たないので、buyer_id ガードを外しても挙動は変わらない）。
     *    将来 仲介に買主マスタを繋ぐなら、この前提から見直すこと（設計書 §3.1）。
     */
    public function test_brokerage_contract_store_changes_no_rank(): void
    {
        $buyer = $this->makeBuyer('仲介', ['realestate' => ['rank' => BuyerRank::C->value]]);

        $response = $this->actingAs($this->executive())->post('/realestate/contracts', [
            'contract_type'           => ReContractType::Brokerage->value,
            'property_name'           => '仲介物件',
            'brokerage_selling_price' => 18000000,
            'brokerage_fee'           => 600000,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertDatabaseCount('re_contracts', 1);
        $this->assertSame(BuyerRank::C, $this->rankOf($buyer, 'realestate'));
    }

    // ---------------- #4 / #5: 編集で買主を差し替え ----------------

    /** #4 契約編集で買主を差し替えると、新しい買主が成約になる。 */
    public function test_swapping_buyer_on_update_marks_new_buyer_contracted(): void
    {
        $oldBuyer = $this->makeBuyer('旧', ['realestate' => ['rank' => BuyerRank::C->value]]);
        $newBuyer = $this->makeBuyer('新', ['realestate' => ['rank' => BuyerRank::C->value]]);
        $proc     = $this->makeProcurement();
        $user     = $this->executive();

        $this->actingAs($user)
            ->post('/realestate/contracts', $this->procurementPayload($proc, $oldBuyer))
            ->assertSessionHasNoErrors();

        $contract = ReContract::firstOrFail();

        $response = $this->actingAs($user)->put(
            '/realestate/contracts/' . $contract->id,
            $this->procurementPayload($proc, $newBuyer),
        );

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($newBuyer, 'realestate'));
    }

    /** #5 決定2: 買主を差し替えても、元の買主は成約のまま（差し戻さない）。 */
    public function test_swapping_buyer_leaves_previous_buyer_contracted(): void
    {
        $oldBuyer = $this->makeBuyer('旧', ['realestate' => ['rank' => BuyerRank::C->value]]);
        $newBuyer = $this->makeBuyer('新', ['realestate' => ['rank' => BuyerRank::C->value]]);
        $proc     = $this->makeProcurement();
        $user     = $this->executive();

        $this->actingAs($user)
            ->post('/realestate/contracts', $this->procurementPayload($proc, $oldBuyer))
            ->assertSessionHasNoErrors();

        $contract = ReContract::firstOrFail();

        $this->actingAs($user)
            ->put('/realestate/contracts/' . $contract->id, $this->procurementPayload($proc, $newBuyer))
            ->assertSessionHasNoErrors();

        $this->assertSame(BuyerRank::Contracted, $this->rankOf($oldBuyer, 'realestate'), '元の買主が差し戻されている');
    }

    // ---------------- #11 / #12 / #14: 部署ランクの更新規則（HTTP 経路） ----------------

    /** #11 決定1: 部署行が無い顧客は、取得日＝契約日・ランク成約でその部署が自動作成される。 */
    public function test_buyer_without_realestate_row_gets_one_with_contract_date(): void
    {
        // 住宅事業にだけ登録された顧客を不動産契約の買主に選ぶ
        $buyer = $this->makeBuyer('住宅のみ', ['housing' => ['rank' => BuyerRank::C->value]]);
        $proc  = $this->makeProcurement();

        $this->actingAs($this->executive())
            ->post('/realestate/contracts', $this->procurementPayload($proc, $buyer))
            ->assertSessionHasNoErrors();

        $pivot = $this->pivot($buyer, 'realestate');
        $this->assertNotNull($pivot, '不動産の部署行が自動作成されていない');
        $this->assertSame(BuyerRank::Contracted, $pivot->rank);
        $this->assertSame('2026-07-19', $pivot->acquired_date->toDateString(), '取得日が契約日になっていない');
    }

    /** #12 既存行の acquired_date は書き換わらない。 */
    public function test_existing_acquired_date_is_not_overwritten_through_http(): void
    {
        $buyer = $this->makeBuyer('取得日', [
            'realestate' => ['rank' => BuyerRank::B->value, 'acquired_date' => '2026-01-05'],
        ]);
        $proc = $this->makeProcurement();

        $this->actingAs($this->executive())
            ->post('/realestate/contracts', $this->procurementPayload($proc, $buyer))
            ->assertSessionHasNoErrors();

        $pivot = $this->pivot($buyer, 'realestate');
        $this->assertSame(BuyerRank::Contracted, $pivot->rank);
        $this->assertSame('2026-01-05', $pivot->acquired_date->toDateString(), '取得日が契約日で上書きされている');
    }

    /** #14 もう一方の部署のランクは変わらない。 */
    public function test_other_department_rank_is_untouched(): void
    {
        $buyer = $this->makeBuyer('両部署', [
            'realestate' => ['rank' => BuyerRank::C->value],
            'housing'    => ['rank' => BuyerRank::C->value],
        ]);
        $proc = $this->makeProcurement();

        $this->actingAs($this->executive())
            ->post('/realestate/contracts', $this->procurementPayload($proc, $buyer))
            ->assertSessionHasNoErrors();

        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'realestate'));
        $this->assertSame(BuyerRank::C, $this->rankOf($buyer, 'housing'), '住宅事業のランクまで変わっている');
    }

    // ---------------- #15: 再発火の抑制 ----------------

    /**
     * #15 契約のメモだけを編集してもランクは書き戻らない（設計書 §3.3）。
     *
     * 利用者が意図的にランクを手で戻した後、無関係な編集で成約へ書き戻るのは不可解な挙動になる。
     */
    public function test_editing_memo_only_does_not_rewrite_rank(): void
    {
        $buyer = $this->makeBuyer('メモ', ['realestate' => ['rank' => BuyerRank::C->value]]);
        $proc  = $this->makeProcurement();
        $user  = $this->executive();

        $this->actingAs($user)
            ->post('/realestate/contracts', $this->procurementPayload($proc, $buyer))
            ->assertSessionHasNoErrors();

        $contract = ReContract::firstOrFail();

        // 利用者が手でランクを A に戻した
        $this->pivot($buyer, 'realestate')->update(['rank' => BuyerRank::A->value]);

        // 買主はそのままでメモだけ編集
        $response = $this->actingAs($user)->put(
            '/realestate/contracts/' . $contract->id,
            $this->procurementPayload($proc, $buyer, ['memo' => '駐車場の件を確認']),
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame('駐車場の件を確認', $contract->fresh()->memo, 'メモが保存されていない（編集自体が失敗）');
        $this->assertSame(BuyerRank::A, $this->rankOf($buyer, 'realestate'), '手で戻したランクが成約へ書き戻っている');
    }

    // ---------------- #18（追加）: department が enum 範囲外 ----------------

    /**
     * re_contracts.department が BuyerDepartment（housing / realestate）の範囲外なら何もしない。
     *
     * buyer_departments.department は enum('housing','realestate') なので、範囲外を書くと
     * 本番 MySQL で DB エラーになる。department はハードコードせず $contract->department を
     * 使う設計（設計書 §5.3）なので、そのガードを固定する。
     *
     * ⚠ コントローラは 'realestate' を固定で入れるため、この経路は HTTP からは作れない。
     *    モデルを直接叩いて確かめる。
     */
    public function test_unknown_department_writes_nothing(): void
    {
        $buyer = $this->makeBuyer('範囲外');
        $proc  = $this->makeProcurement();

        ReContract::create([
            'department'           => 'tenant',
            'contract_type'        => ReContractType::ProcurementHouse->value,
            'status'               => ReContractStatus::Contracted->value,
            'contract_date'        => '2026-07-19',
            'property_name'        => 'ランク検証物件',
            'procurement_id'       => $proc->id,
            'buyer_id'             => $buyer->id,
            'contract_amount_land' => 30000000,
            'cost_amount'          => 25000000,
            'created_by'           => 1,
        ]);

        $this->assertSame(
            0,
            BuyerDepartmentPivot::where('buyer_id', $buyer->id)->count(),
            'BuyerDepartment の範囲外の部署が書き込まれている',
        );
    }
}
