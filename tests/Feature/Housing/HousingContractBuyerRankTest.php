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
use Illuminate\Support\Carbon;
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

    /** 建売の契約をモデルで作る（保存の入口を通らない。HTTP から届かない買主のフックを確かめるため） */
    private function createTateuriContract(HsProperty $property, Buyer $buyer): HsContract
    {
        return HsContract::create([
            'property_id'            => $property->id,
            'customer_id'            => $buyer->id,
            'customer_name'          => $buyer->full_name,
            'selling_price_land'     => 12800000,
            'selling_price_building' => 28500000,
            'tax_rate'               => 10.00,
            'contract_date'          => '2026-07-19',
            'created_by'             => $this->executive()->id,
        ]);
    }

    /**
     * 追加: 論理削除済みの買主でもランクは更新される（設計書 §4）— 建売の契約はモデルで確かめる。
     *
     * ⚠ 案 A（判断待ち D・docs/RULES.md Bug #149）で、建売の契約の保存（登録・2 つの編集）は削除済みの買主を断る
     *    （今の買主が削除済みのまま保存するだけなら customer_id が変わらずフックは動かない）＝ HTTP からは届かない
     *    （断ることは Screens\HousingBuyerChoiceTest）。フックの withTrashed() は、選んだあとで買主が削除された瞬間などの
     *    守りとして残すので、モデルを直接叩いて固定する（直す前はここが HTTP の登録で通っていた）。
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

        $this->createTateuriContract($property, $buyer);

        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'housing'));
    }

    /**
     * 追加: 建売で housing の部署行が無い買主は、取得日＝契約日で自動作成される（設計書 §7.1 #11）— モデルで確かめる。
     *
     * ⚠ 案 A（判断待ち D・Bug #149）で、建売の契約の保存は住宅事業にいない買主を断る（今の買主のまま保存するだけなら
     *    フックは動かない）＝ HTTP からは届かない。フックが契約日を取得日に渡すことは、ここでモデルを直接叩いて固定する。
     *    HTTP で届く経路（注文住宅の今の買主のまま「契約」へ進める）は test_custom_order_buyer_without_housing_row_… が見る。
     *    もう一方の部署（不動産）が変わらないことも同時に見る（#14 の逆方向）。
     */
    public function test_tateuri_buyer_without_housing_row_gets_one_with_contract_date(): void
    {
        // 不動産事業にだけ登録された顧客が建売の契約の買主になった（前からのデータ）
        $buyer    = $this->makeBuyer('不動産のみ', ['realestate' => ['rank' => BuyerRank::C->value]]);
        $property = $this->makeProperty();

        $this->createTateuriContract($property, $buyer);

        $pivot = $this->pivot($buyer, 'housing');
        $this->assertNotNull($pivot, '住宅事業の部署行が自動作成されていない');
        $this->assertSame(BuyerRank::Contracted, $pivot->rank);
        $this->assertSame('2026-07-19', $pivot->acquired_date->toDateString(), '取得日が契約日になっていない');
        $this->assertSame(BuyerRank::C, $this->rankOf($buyer, 'realestate'), '不動産事業のランクまで変わっている');
    }

    /** 追加: 建売でも既存行の acquired_date は書き換わらない（設計書 §7.1 #12）。 */
    public function test_tateuri_existing_acquired_date_is_not_overwritten(): void
    {
        $buyer = $this->makeBuyer('建売取得日', [
            'housing' => ['rank' => BuyerRank::B->value, 'acquired_date' => '2026-01-05'],
        ]);
        $property = $this->makeProperty();

        $this->actingAs($this->executive())
            ->post('/housing/properties/' . $property->id . '/contract', $this->tateuriStorePayload($buyer))
            ->assertSessionHasNoErrors();

        $pivot = $this->pivot($buyer, 'housing');
        $this->assertSame(BuyerRank::Contracted, $pivot->rank);
        $this->assertSame('2026-01-05', $pivot->acquired_date->toDateString(), '取得日が契約日で上書きされている');
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
            // 契約一覧からの編集（customOrderListUpdatePayload）と同じ土地にしておく（契約済みの土地は変えられない。LandLockTest）
            'land_source_type'    => HousingLandSourceType::CustomerLand->value,
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
     * 追加: 契約以降から手前のステータスへ戻してもランクは成約のまま（設計書 §2 決定2）。
     *
     * ⚠ 同じ isContractedOrLater() を使う syncLotStatus() は区画を販売中へ戻すので
     *    **意図的な非対称**。区画は在庫、ランクは獲得履歴で意味が違う。
     *    契約レコードごと削除しても戻さないと決めている以上、ステータスを一段戻しただけで
     *    戻すのは矛盾する。このテストはその決定を固定するもので、
     *    「戻すべきなのに戻っていない」ことを示すものではない。
     */
    public function test_custom_order_status_regression_keeps_rank_contracted(): void
    {
        $buyer = $this->makeBuyer('差し戻し', ['housing' => ['rank' => BuyerRank::C->value]]);
        $user  = $this->executive();

        $this->actingAs($user)
            ->post('/housing/custom-orders', $this->customOrderStorePayload($buyer, CustomOrderStatus::Contracted))
            ->assertSessionHasNoErrors();

        $order = HsCustomOrder::firstOrFail();
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'housing'), '前提: 契約で登録したので成約');

        // ステップバーで 契約 → 商談 へ戻す
        $response = $this->actingAs($user)->patch(
            '/housing/custom-orders/' . $order->id . '/status',
            ['status' => CustomOrderStatus::Consultation->value],
        );

        $response->assertOk();
        $this->assertSame(
            CustomOrderStatus::Consultation,
            $order->fresh()->status,
            'ステータスが戻っていない（操作自体が失敗）',
        );
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'housing'), 'ランクが成約から戻ってしまっている');
    }

    /**
     * 見積り以前の注文住宅をモデルで作る（今の買主が住宅事業にいない＝前からのデータ・あとで部署から外された買主）。
     * ⚠ 案 A（判断待ち D・Bug #149）で、登録の保存は住宅事業にいない買主を断るので、この形は HTTP の登録では作れない。
     *    契約前なのでフックは動かない（ランクは変わらない）。
     */
    private function consultationOrderFor(Buyer $buyer, array $overrides = []): HsCustomOrder
    {
        return HsCustomOrder::create(array_merge([
            'order_code'       => 'CO-RANK-001',
            'order_name'       => 'ランク検証A邸',
            'status'           => CustomOrderStatus::Consultation->value,
            'customer_id'      => $buyer->id,
            'customer_name'    => $buyer->full_name,
            'address'          => '松山市石井町1-2-3',
            'tax_rate'         => 10.00,
            'contract_date'    => '2026-07-19',
            'land_source_type' => HousingLandSourceType::CustomerLand->value,
            'created_by'       => $this->executive()->id,
        ], $overrides));
    }

    /**
     * 追加: 論理削除済みの買主でもランクは更新される（設計書 §4）。
     *
     * ⚠ この経路は実在する。案 A（判断待ち D・Bug #149）で登録は削除済みの買主を断るが、今の買主があとで削除された
     *    注文住宅は、ステータスを「契約」へ進めると（一覧の小窓は customer_id を見ない・編集画面も今の買主は選択肢に残す）
     *    削除済みの買主のままフックが動く（直す前はここを HTTP の登録で見ていた）。
     *
     * ⚠ フックが `Buyer::withTrashed()` でなく素の `Buyer::find()` だと null が返り、
     *    `?->` で**無音で何も起きない**（例外も出ない）。このテストが唯一その退行を捕まえる。
     */
    public function test_custom_order_soft_deleted_buyer_is_still_marked_contracted(): void
    {
        $buyer = $this->makeBuyer('注文削除済', ['housing' => ['rank' => BuyerRank::C->value]]);
        $user  = $this->executive();

        $this->actingAs($user)
            ->post('/housing/custom-orders', $this->customOrderStorePayload($buyer, CustomOrderStatus::Consultation))
            ->assertSessionHasNoErrors();
        $order = HsCustomOrder::firstOrFail();

        $buyer->delete();
        $this->assertSoftDeleted('buyers', ['id' => $buyer->id]);
        $this->assertSame(BuyerRank::C, $this->rankOf($buyer, 'housing'), '前提: 見積り以前なのでまだ C');

        $response = $this->actingAs($user)->patch(
            '/housing/custom-orders/' . $order->id . '/status',
            ['status' => CustomOrderStatus::Contracted->value],
        );

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertSame(BuyerRank::Contracted, $this->rankOf($buyer, 'housing'));
    }

    /**
     * 追加: 注文住宅でも housing の部署行が無い買主は取得日＝契約日で自動作成される（設計書 §7.1 #11）。
     *
     * ⚠ 案 A（判断待ち D・Bug #149）で登録は住宅事業にいない買主を断る。届くのは、今の買主が住宅事業にいない注文住宅を
     *    編集画面から「契約」へ進めるとき（今の買主は選択肢に残す）。直す前はここを HTTP の登録で見ていた。
     */
    public function test_custom_order_buyer_without_housing_row_gets_one_with_contract_date(): void
    {
        $buyer = $this->makeBuyer('注文不動産のみ', ['realestate' => ['rank' => BuyerRank::C->value]]);
        $order = $this->consultationOrderFor($buyer);
        $this->assertNull($this->pivot($buyer, 'housing'), '前提: 見積り以前なので住宅事業の行はまだ無い');

        $this->actingAs($this->executive())->put(
            '/housing/custom-orders/' . $order->id,
            $this->customOrderStorePayload($buyer, CustomOrderStatus::Contracted),
        )->assertSessionHasNoErrors();

        $this->assertSame(CustomOrderStatus::Contracted, $order->fresh()->status, 'ステータスが進んでいない（保存自体が失敗）');
        $pivot = $this->pivot($buyer, 'housing');
        $this->assertNotNull($pivot, '住宅事業の部署行が自動作成されていない');
        $this->assertSame(BuyerRank::Contracted, $pivot->rank);
        $this->assertSame('2026-07-19', $pivot->acquired_date->toDateString(), '取得日が契約日になっていない');
    }

    /**
     * 追加: 契約日が未入力の注文住宅では、取得日に当日が入る。
     *
     * ⚠ hs_custom_orders.contract_date は nullable なので、ステータスが「契約」でも
     *    契約日が空のまま保存できる。一方 buyer_departments.acquired_date は **NOT NULL** で、
     *    フックが null をそのまま渡すと本番 MySQL で落ちる。
     *    救っているのは markContracted() の `?:` フォールバックだが、
     *    **この経路は実入口から一度も実行されていなかった**（設計書 §7.1 の 15 項目にも無い）。
     *
     * ⚠ 凍結日は「実際の今日」と違う日にすること。今日と同じ日付だと
     *    フォールバックが壊れていても緑になり、テストが何も証明しない。
     *
     * ⚠ 案 A（判断待ち D・Bug #149）で登録は住宅事業にいない買主を断るので、今の買主が住宅事業にいない契約日の無い
     *    注文住宅を、一覧の小窓で「契約」へ進める形で見る（直す前は HTTP の登録で見ていた）。
     */
    public function test_custom_order_without_contract_date_uses_today_as_acquired_date(): void
    {
        $this->travelTo(Carbon::parse('2030-03-15'));

        $buyer = $this->makeBuyer('契約日なし', ['realestate' => ['rank' => BuyerRank::C->value]]);
        $order = $this->consultationOrderFor($buyer, ['contract_date' => null]);

        $this->actingAs($this->executive())
            ->patch('/housing/custom-orders/' . $order->id . '/status', ['status' => CustomOrderStatus::Contracted->value])
            ->assertOk()
            ->assertJson(['success' => true]);

        $order = $order->fresh();
        $this->assertSame(CustomOrderStatus::Contracted, $order->status, 'ステータスが進んでいない（保存自体が失敗）');
        $this->assertNull($order->contract_date, '前提: 契約日が保存されている（テストの意図と違う）');

        $pivot = $this->pivot($buyer, 'housing');
        $this->assertNotNull($pivot, '住宅事業の部署行が自動作成されていない');
        $this->assertSame('2030-03-15', $pivot->acquired_date->toDateString(), '取得日が当日になっていない');
    }
}
