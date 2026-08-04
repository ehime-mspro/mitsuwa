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
 * 物件詳細側の建売契約編集（PUT /housing/properties/{property}/contract）が
 * 買主マスタに紐づくことを固定する。
 *
 * 建売契約の編集画面は 2 つある:
 *   - 契約一覧側 `edit-building.blade.php` … 元から `_buyer-select` で買主マスタ対応
 *   - 物件詳細側 `edit.blade.php`          … **本件で対応させた**
 *
 * 修正前はこの画面だけ顧客名がフリーテキストで、しかも**テナント事業の API**
 * `/api/tenant/customers/search`（返るのは別テーブルの Customer）を叩いていた。
 * `customer_id` の欄が無く `update()` にも検証が無かったため、保存すると
 * `customer_name` だけが上書きされて `customer_id` と食い違った。
 *
 * ⚠ 挙動テスト（PHP から customer_id を送る）**だけでは不十分**。
 *    Blade が壊れて画面から customer_id が送られなくなっても緑のまま通る。
 *    画面側の検証と**対で**持つこと（docs/RULES.md Bug #28 / #35 と同じ構図）。
 */
class PropertyContractEditBuyerTest extends TestCase
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

    private function makeBuyer(string $lastName): Buyer
    {
        $buyer = Buyer::create(['last_name' => $lastName, 'first_name' => '太郎']);

        BuyerDepartmentPivot::create([
            'buyer_id'      => $buyer->id,
            'department'    => 'housing',
            'acquired_date' => '2026-01-05',
            'rank'          => BuyerRank::C->value,
        ]);

        return $buyer;
    }

    private function makeProperty(): HsProperty
    {
        return HsProperty::create([
            'property_code' => 'HS-EDIT-001',
            'property_name' => '編集検証A号地',
            'status'        => 'construction',
            'address'       => '松山市石井町1-2-3',
            'building_cost' => 21300000,
            'land_cost'     => 9600000,
            'created_by'    => 1,
        ]);
    }

    /** 物件＋契約を作る。契約の買主は $buyer。 */
    private function makeContract(HsProperty $property, Buyer $buyer): HsContract
    {
        return HsContract::create([
            'property_id'            => $property->id,
            'customer_id'            => $buyer->id,
            'customer_name'          => $buyer->full_name,
            'selling_price_land'     => 12800000,
            'selling_price_building' => 28500000,
            'tax_rate'               => 10.00,
            'contract_date'          => '2026-07-19',
            'created_by'             => 1,
        ]);
    }

    private function updatePayload(?Buyer $buyer, array $overrides = []): array
    {
        $payload = [
            'customer_name'          => '画面からは送られるが買主名で上書きされる',
            'selling_price_land'     => 12800000,
            'selling_price_building' => 28500000,
            'tax_rate'               => 10.00,
            'contract_date'          => '2026-07-19',
        ];

        if ($buyer !== null) {
            $payload['customer_id'] = $buyer->id;
        }

        return array_merge($payload, $overrides);
    }

    // ---------------- 画面側（これが無いと挙動テストが false-pass する） ----------------

    /**
     * 編集画面が買主マスタのプルダウンを描画し、テナント事業の API を叩かない。
     *
     * ⚠ 修正前はここが `<input name="customer_name">` ＋ `/api/tenant/customers/search` だった。
     */
    public function test_edit_screen_renders_buyer_select_and_not_tenant_api(): void
    {
        $buyer    = $this->makeBuyer('現買主');
        $property = $this->makeProperty();
        $this->makeContract($property, $buyer);

        $res = $this->actingAs($this->executive())
            ->get('/housing/properties/' . $property->id . '/contract/edit');

        $res->assertOk();
        // 買主マスタのプルダウンがあり、現在の買主が選択済み
        $res->assertSee('name="customer_id"', false);
        $res->assertSee('value="' . $buyer->id . '"', false);
        // テナント事業の顧客 API を叩いていない（返るのは Buyer ではなく別テーブルの Customer）
        $res->assertDontSee('/api/tenant/customers/search', false);
    }

    // ---------------- 挙動 ----------------

    /** 買主を差し替えると customer_id と customer_name の両方が更新される。 */
    public function test_swapping_buyer_updates_both_id_and_name(): void
    {
        $oldBuyer = $this->makeBuyer('旧買主');
        $newBuyer = $this->makeBuyer('新買主');
        $property = $this->makeProperty();
        $contract = $this->makeContract($property, $oldBuyer);

        $res = $this->actingAs($this->executive())
            ->put('/housing/properties/' . $property->id . '/contract', $this->updatePayload($newBuyer));

        $res->assertSessionHasNoErrors();
        $res->assertRedirect();

        $fresh = $contract->fresh();
        $this->assertSame($newBuyer->id, $fresh->customer_id, '買主の紐付けが変わっていない');
        $this->assertSame($newBuyer->full_name, $fresh->customer_name, '顧客名が買主名で上書きされていない');
    }

    /** customer_id を送らないとバリデーションエラーになる（必須化されていることの証明）。 */
    public function test_missing_customer_id_fails_validation(): void
    {
        $buyer    = $this->makeBuyer('現買主');
        $property = $this->makeProperty();
        $contract = $this->makeContract($property, $buyer);

        $res = $this->actingAs($this->executive())
            ->put('/housing/properties/' . $property->id . '/contract', $this->updatePayload(null));

        $res->assertSessionHasErrors('customer_id');
        $this->assertSame($buyer->id, $contract->fresh()->customer_id, '弾いたのに更新されている');
    }

    /**
     * 買主を差し替えると新しい買主のランクが「成約」になる。
     *
     * ⚠ この画面は修正前は買主を変えられなかったため、買主ランク自動成約の設計書 §5.1 の
     *    入口表には載っていなかった。**入口が増えたので入口ごとに測る**（Bug #44）。
     */
    public function test_swapping_buyer_marks_new_buyer_contracted(): void
    {
        $oldBuyer = $this->makeBuyer('旧買主');
        $newBuyer = $this->makeBuyer('新買主');
        $property = $this->makeProperty();
        $this->makeContract($property, $oldBuyer);

        $res = $this->actingAs($this->executive())
            ->put('/housing/properties/' . $property->id . '/contract', $this->updatePayload($newBuyer));

        $res->assertSessionHasNoErrors();

        $pivot = BuyerDepartmentPivot::where('buyer_id', $newBuyer->id)
            ->where('department', 'housing')
            ->first();

        $this->assertSame(BuyerRank::Contracted, $pivot->rank);
    }
}
