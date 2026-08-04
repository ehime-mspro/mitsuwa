<?php

namespace Tests\Feature\Housing;

use App\Enums\BuyerRank;
use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\BuyerDepartmentPivot;
use App\Models\HsContract;
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
}
