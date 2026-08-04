<?php

namespace Tests\Feature\Housing;

use App\Enums\BuyerRank;
use App\Enums\CustomOrderStatus;
use App\Enums\HousingLandSourceType;
use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\BuyerDepartmentPivot;
use App\Models\HsContract;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 住宅事業(建売契約・注文住宅)の入口で買主ランクが「成約」になることを固定する
 * （設計書 §7.1 #6-10 ＋ 入口の取りこぼしを埋める追加分）。
 *
 * ⚠ 各テストは必ずリクエストの成功を assert する。バリデーションで弾かれると
 *    「ランクが変わらない」系のテストが壊れていても緑になる（false-pass）。
 *
 * ⚠ 編集系は HTTP 経由なのでルートモデルバインディングで DB から取り直したインスタンスが渡る。
 *    直接 $model->update() を書くと wasRecentlyCreated が true のまま残り、
 *    wasChanged ガードの変異を隠してしまう（Bug #39）。HTTP を通すこと。
 */
class HousingContractBuyerRankTest extends TestCase
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

    private function makeProperty(string $code = 'HS-RANK-001'): HsProperty
    {
        return HsProperty::create([
            'property_code' => $code,
            'property_name' => 'ランク検証A号地',
            'status'        => 'construction',
            'address'       => '松山市石井町1-2-3',
            'building_cost' => 21300000,
            'land_cost'     => 9600000,
            'created_by'    => 1,
        ]);
    }

    private function tateuriStorePayload(Buyer $buyer): array
    {
        return [
            'customer_id'            => $buyer->id,
            'customer_name'          => '上書きされる名前',
            'selling_price_land'     => 12800000,
            'selling_price_building' => 28500000,
            'tax_rate'               => 10.00,
            'contract_date'          => '2026-07-19',
        ];
    }

    private function tateuriUpdatePayload(Buyer $buyer, array $overrides = []): array
    {
        return array_merge([
            'customer_id'            => $buyer->id,
            'customer_name'          => '上書きされる名前',
            'contract_date'          => '2026-07-19',
            'selling_price_land'     => 12800000,
            'selling_price_building' => 28500000,
            'tax_rate'               => 10.00,
            'building_cost'          => 21300000,
        ], $overrides);
    }

    // ---------------- #6 / #7 / 追加: 建売契約 ----------------

    /** #6 建売の契約登録で買主が成約になる。 */
    public function test_tateuri_contract_store_marks_buyer_contracted(): void
    {
        $buyer    = $this->makeBuyer('建売', ['housing' => ['rank' => BuyerRank::C->value]]);
        $property = $this->makeProperty();

        $response = $this->actingAs($this->executive())
            ->post('/housing/properties/' . $property->id . '/contract', $this->tateuriStorePayload($buyer));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'housing'));
    }

    /** #7 契約一覧からの建売契約編集で買主を差し替えると、新しい買主が成約になる。 */
    public function test_tateuri_contract_update_from_list_marks_new_buyer_contracted(): void
    {
        $oldBuyer = $this->makeBuyer('建売旧', ['housing' => ['rank' => BuyerRank::C->value]]);
        $newBuyer = $this->makeBuyer('建売新', ['housing' => ['rank' => BuyerRank::C->value]]);
        $property = $this->makeProperty();
        $user     = $this->executive();

        $this->actingAs($user)
            ->post('/housing/properties/' . $property->id . '/contract', $this->tateuriStorePayload($oldBuyer))
            ->assertSessionHasNoErrors();

        $contract = HsContract::firstOrFail();

        $response = $this->actingAs($user)->put(
            '/housing/contracts/building/' . $contract->id,
            $this->tateuriUpdatePayload($newBuyer),
        );

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($newBuyer, 'housing'));
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($oldBuyer, 'housing'), '元の買主が差し戻されている');
    }

    /**
     * 追加（設計書 #15 の建売版）: 買主を変えずに備考だけ編集してもランクは書き戻らない。
     *
     * ReContract 側と同じガードが HsContract にも要る。ここが無いと HsContract の
     * wasChanged ガードを消す変異を誰も検出しない（Bug #44）。
     */
    public function test_tateuri_editing_notes_only_does_not_rewrite_rank(): void
    {
        $buyer    = $this->makeBuyer('建売メモ', ['housing' => ['rank' => BuyerRank::C->value]]);
        $property = $this->makeProperty();
        $user     = $this->executive();

        $this->actingAs($user)
            ->post('/housing/properties/' . $property->id . '/contract', $this->tateuriStorePayload($buyer))
            ->assertSessionHasNoErrors();

        $contract = HsContract::firstOrFail();

        // 利用者が手でランクを A に戻した
        $this->pivot($buyer, 'housing')->update(['rank' => BuyerRank::A->value]);

        $response = $this->actingAs($user)->put(
            '/housing/contracts/building/' . $contract->id,
            $this->tateuriUpdatePayload($buyer, ['notes' => '外構の件を確認']),
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame('外構の件を確認', $contract->fresh()->notes, '備考が保存されていない（編集自体が失敗）');
        $this->assertSame(BuyerRank::A, $this->rankOf($buyer, 'housing'), '手で戻したランクが成約へ書き戻っている');
    }

    /**
     * 追加: 論理削除済みの買主でもランクは更新される（設計書 §4）。
     *
     * ⚠ この経路は実在する。`customer_id` の `exists:buyers,id` は Laravel の
     *    DatabasePresenceVerifier がテーブルを直接引くので **SoftDeletingScope を通らない**
     *    （コントローラ自身も `Buyer::withTrashed()->findOrFail()` で受けている）。
     *
     * ⚠ フックが `Buyer::withTrashed()` でなく素の `Buyer::find()` だと null が返り、
     *    `?->` で**無音で何も起きない**(例外も出ない)。このテストが唯一その退行を捕まえる。
     */
    public function test_tateuri_soft_deleted_buyer_is_still_marked_contracted(): void
    {
        $buyer    = $this->makeBuyer('建売削除済', ['housing' => ['rank' => BuyerRank::C->value]]);
        $property = $this->makeProperty();

        $buyer->delete();
        $this->assertSoftDeleted('buyers', ['id' => $buyer->id]);

        $response = $this->actingAs($this->executive())
            ->post('/housing/properties/' . $property->id . '/contract', $this->tateuriStorePayload($buyer));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'housing'));
    }

    // ---------------- 注文住宅のヘルパー ----------------

    private function customOrderStorePayload(Buyer $buyer, CustomOrderStatus $status): array
    {
        return [
            'order_name'          => 'ランク検証A邸',
            'status'              => $status->value,
            'customer_id'         => $buyer->id,
            'customer_name'       => '上書きされる名前',
            'address'             => '松山市石井町1-2-3',
            'is_land_cost_manual' => 0,
            'tax_rate'            => 10.00,
            'contract_date'       => '2026-07-19',
        ];
    }

    /** 契約一覧からの注文住宅契約編集（HsContractListController::updateCustomOrder）の payload。 */
    private function customOrderListUpdatePayload(Buyer $buyer, array $overrides = []): array
    {
        return array_merge([
            'customer_id'             => $buyer->id,
            'customer_name'           => '上書きされる名前',
            'contract_date'           => '2026-07-19',
            'land_source_type'        => HousingLandSourceType::CustomerLand->value,
            'building_contract_price' => 32000000,
            'tax_rate'                => 10.00,
            'building_cost'           => 24800000,
        ], $overrides);
    }

    // ---------------- 注文住宅 ----------------

    /**
     * 設計書 #8 商談ステータスで登録してもランクは変わらない（設計書 §3.2）。
     *
     * hs_custom_orders は商談段階から登録できる案件レコード。登録＝契約ではないので、
     * まだ商談中の見込み客を成約扱いにしてはいけない。
     */
    public function test_custom_order_stored_as_consultation_changes_no_rank(): void
    {
        $buyer = $this->makeBuyer('商談', ['housing' => ['rank' => BuyerRank::C->value]]);

        $response = $this->actingAs($this->executive())->post(
            '/housing/custom-orders',
            $this->customOrderStorePayload($buyer, CustomOrderStatus::Consultation),
        );

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertDatabaseCount('hs_custom_orders', 1);
        $this->assertSame(BuyerRank::C, $this->rankOf($buyer, 'housing'), '商談段階の見込み客が成約になっている');
    }

    /** 設計書 #9 契約ステータスで登録すると成約になる。 */
    public function test_custom_order_stored_as_contracted_marks_buyer_contracted(): void
    {
        $buyer = $this->makeBuyer('注文契約', ['housing' => ['rank' => BuyerRank::C->value]]);

        $response = $this->actingAs($this->executive())->post(
            '/housing/custom-orders',
            $this->customOrderStorePayload($buyer, CustomOrderStatus::Contracted),
        );

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'housing'));
    }

    /** 設計書 #10 一覧のステップバーで 商談 → 契約 に進めると成約になる。 */
    public function test_custom_order_step_bar_advance_to_contracted_marks_buyer(): void
    {
        $buyer = $this->makeBuyer('ステップ', ['housing' => ['rank' => BuyerRank::C->value]]);
        $user  = $this->executive();

        $this->actingAs($user)
            ->post('/housing/custom-orders', $this->customOrderStorePayload($buyer, CustomOrderStatus::Consultation))
            ->assertSessionHasNoErrors();

        $order = HsCustomOrder::firstOrFail();
        $this->assertSame(BuyerRank::C, $this->rankOf($buyer, 'housing'), '前提: 登録時点ではまだ C');

        $response = $this->actingAs($user)->patch(
            '/housing/custom-orders/' . $order->id . '/status',
            ['status' => CustomOrderStatus::Contracted->value],
        );

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'housing'));
    }

    /**
     * 追加（入口の取りこぼし）: 編集フォーム（PUT /housing/custom-orders/{id}）で
     * 商談 → 契約 に進めた場合も成約になる。
     *
     * ステップバー（PATCH .../status）とは別のコントローラメソッドなので、
     * 片方だけ測ると一方の入口が一度も実行されない（Bug #44）。
     */
    public function test_custom_order_edit_form_advance_to_contracted_marks_buyer(): void
    {
        $buyer = $this->makeBuyer('注文編集', ['housing' => ['rank' => BuyerRank::C->value]]);
        $user  = $this->executive();

        $this->actingAs($user)
            ->post('/housing/custom-orders', $this->customOrderStorePayload($buyer, CustomOrderStatus::Consultation))
            ->assertSessionHasNoErrors();

        $order = HsCustomOrder::firstOrFail();
        $this->assertSame(BuyerRank::C, $this->rankOf($buyer, 'housing'), '前提: 登録時点ではまだ C');

        $response = $this->actingAs($user)->put(
            '/housing/custom-orders/' . $order->id,
            $this->customOrderStorePayload($buyer, CustomOrderStatus::Contracted),
        );

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'housing'));
    }

    /**
     * 追加（入口の取りこぼし）: 契約一覧からの注文住宅契約編集で買主を差し替えると
     * 新しい買主が成約になる（HsContractListController::updateCustomOrder）。
     */
    public function test_custom_order_update_from_contract_list_marks_new_buyer(): void
    {
        $oldBuyer = $this->makeBuyer('注文旧', ['housing' => ['rank' => BuyerRank::C->value]]);
        $newBuyer = $this->makeBuyer('注文新', ['housing' => ['rank' => BuyerRank::C->value]]);
        $user     = $this->executive();

        $this->actingAs($user)
            ->post('/housing/custom-orders', $this->customOrderStorePayload($oldBuyer, CustomOrderStatus::Contracted))
            ->assertSessionHasNoErrors();

        $order = HsCustomOrder::firstOrFail();

        $response = $this->actingAs($user)->put(
            '/housing/contracts/custom-order/' . $order->id,
            $this->customOrderListUpdatePayload($newBuyer),
        );

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($newBuyer, 'housing'));
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($oldBuyer, 'housing'), '元の買主が差し戻されている');
    }

    /**
     * 追加（設計書 #15 の注文住宅版）: 買主・ステータスを変えずに備考だけ編集しても
     * ランクは書き戻らない。
     */
    public function test_custom_order_editing_notes_only_does_not_rewrite_rank(): void
    {
        $buyer = $this->makeBuyer('注文メモ', ['housing' => ['rank' => BuyerRank::C->value]]);
        $user  = $this->executive();

        $this->actingAs($user)
            ->post('/housing/custom-orders', $this->customOrderStorePayload($buyer, CustomOrderStatus::Contracted))
            ->assertSessionHasNoErrors();

        $order = HsCustomOrder::firstOrFail();

        // 利用者が手でランクを A に戻した
        $this->pivot($buyer, 'housing')->update(['rank' => BuyerRank::A->value]);

        $response = $this->actingAs($user)->put(
            '/housing/contracts/custom-order/' . $order->id,
            $this->customOrderListUpdatePayload($buyer, ['notes' => '仕様変更の件を確認']),
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame('仕様変更の件を確認', $order->fresh()->notes, '備考が保存されていない（編集自体が失敗）');
        $this->assertSame(BuyerRank::A, $this->rankOf($buyer, 'housing'), '手で戻したランクが成約へ書き戻っている');
    }

    /**
     * 追加: 論理削除済みの買主でもランクは更新される（設計書 §4）。
     *
     * ⚠ この経路は実在する。`customer_id` の `exists:buyers,id` は Laravel の
     *    DatabasePresenceVerifier がテーブルを直接引くので **SoftDeletingScope を通らない**
     *    （コントローラ自身も `Buyer::withTrashed()->findOrFail()` で受けている）。
     *
     * ⚠ フックが `Buyer::withTrashed()` でなく素の `Buyer::find()` だと null が返り、
     *    `?->` で**無音で何も起きない**（例外も出ない）。このテストが唯一その退行を捕まえる。
     */
    public function test_custom_order_soft_deleted_buyer_is_still_marked_contracted(): void
    {
        $buyer = $this->makeBuyer('注文削除済', ['housing' => ['rank' => BuyerRank::C->value]]);

        $buyer->delete();
        $this->assertSoftDeleted('buyers', ['id' => $buyer->id]);

        $response = $this->actingAs($this->executive())->post(
            '/housing/custom-orders',
            $this->customOrderStorePayload($buyer, CustomOrderStatus::Contracted),
        );

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'housing'));
    }
}
