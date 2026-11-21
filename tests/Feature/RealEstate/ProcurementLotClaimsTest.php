<?php

namespace Tests\Feature\RealEstate;

use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProcurementLot;
use App\Support\ProcurementLotClaims;
use Tests\Feature\RealEstate\Screens\RealEstateScreenTestCase;

/**
 * 土地の区画が「取られている」かの判定（設計書 §5.3）。
 *
 * ⚠ 取られているかは紐付けで決め、区画のステータスでは決めない（区画 5 は成約のステータスだけで、紐付けは無い）。
 * ⚠ 取るもの: 不動産の契約・建売（契約の前から）・契約以降の注文住宅。見積り以前の注文住宅は数えない。
 */
class ProcurementLotClaimsTest extends RealEstateScreenTestCase
{
    private const TAKEN_BY_CONTRACT = 'この区画（1号地）は契約「勝山の土地（山田 太郎 様）」に紐づいています。ほかの区画を選んでください。';

    private ReProcurement $land;

    /** @var array<int, ReProcurementLot> 号地 => 区画 */
    private array $lots = [];

    private ReContract $contract;

    private HsProperty $property;

    private HsCustomOrder $estimate;

    private HsCustomOrder $contracted;

    protected function setUp(): void
    {
        parent::setUp();

        // 区画 1: 契約 ／ 2: 建売（契約なし）／ 3: 見積りの注文住宅 ／ 4: 契約の注文住宅 ／ 5: 成約のステータスだけ
        $this->land = $this->landProcurement();
        foreach ([1, 2, 3, 4] as $number) {
            $this->lots[$number] = $this->procurementLot($this->land, ['lot_number' => $number]);
        }
        $this->lots[5] = $this->procurementLot($this->land, ['lot_number' => 5, 'status' => 'sold']);

        $this->contract = $this->contractOn($this->land, $this->lots[1]);
        $this->property = $this->propertyOn($this->land, $this->lots[2]);
        $this->estimate = $this->orderOn($this->land, $this->lots[3], 'estimation', 'CO-001', '勝山の見積り');
        $this->contracted = $this->orderOn($this->land, $this->lots[4], 'contracted', 'CO-002', '勝山の注文住宅');
    }

    private function contractOn(ReProcurement $procurement, ?ReProcurementLot $lot, string $name = '勝山の土地'): ReContract
    {
        return ReContract::create([
            'department' => 'realestate',
            'contract_type' => 'procurement_land',
            'status' => 'contracted',
            'property_name' => $name,
            'procurement_id' => $procurement->id,
            'procurement_lot_id' => $lot?->id,
            'buyer_id' => $this->buyer()->id,
            'created_by' => $this->user->id,
        ]);
    }

    private function propertyOn(ReProcurement $procurement, ?ReProcurementLot $lot, string $code = 'HS-001'): HsProperty
    {
        return HsProperty::create([
            'property_code' => $code,
            'property_name' => '勝山の建売',
            'status' => 'construction',
            'land_source_type' => 'procurement',
            're_procurement_id' => $procurement->id,
            're_procurement_lot_id' => $lot?->id,
            'address' => '愛媛県松山市勝山町2-2',
            'created_by' => $this->user->id,
        ]);
    }

    private function orderOn(ReProcurement $procurement, ?ReProcurementLot $lot, string $status, string $code, string $name): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code' => $code,
            'order_name' => $name,
            'status' => $status,
            'customer_name' => '大西 花子',
            'land_source_type' => 'procurement',
            're_procurement_id' => $procurement->id,
            're_procurement_lot_id' => $lot?->id,
            'address' => '愛媛県松山市勝山町3-3',
            'created_by' => $this->user->id,
        ]);
    }

    /** @return array<int, int> 区画の id */
    private function ids(int ...$numbers): array
    {
        return array_map(fn (int $n) => $this->lots[$n]->id, $numbers);
    }

    public function test_taken_lots_follow_links_not_status(): void
    {
        // ほかの土地の区画に付いた契約は、この土地の結果に混ざらない
        $other = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '別の土地']);
        $this->contractOn($other, $this->procurementLot($other), '別の土地');
        // 分譲地の区画を指す列（lot_id・re_project_lot_id）に同じ数字が入っていても数えない
        HsProperty::create([
            'property_code' => 'HS-009', 'property_name' => '分譲地の建売', 'status' => 'construction',
            'land_source_type' => 'project_lot', 're_project_lot_id' => $this->lots[5]->id,
            'address' => '愛媛県松山市平井町1', 'created_by' => $this->user->id,
        ]);

        $this->assertSame($this->ids(1, 2, 4), ProcurementLotClaims::takenLotIds([$this->land->id]));
        // 自分（except）は数えない
        $this->assertSame($this->ids(2, 4), ProcurementLotClaims::takenLotIds([$this->land->id], $this->contract));
        $this->assertSame($this->ids(1, 4), ProcurementLotClaims::takenLotIds([$this->land->id], $this->property));
        $this->assertSame($this->ids(1, 2), ProcurementLotClaims::takenLotIds([$this->land->id], $this->contracted));
        $this->assertSame([], ProcurementLotClaims::takenLotIds([]));
    }

    public function test_the_current_owner_keeps_its_lot(): void
    {
        $this->assertSame(
            $this->ids(1, 3, 5),
            ProcurementLotClaims::selectableLots($this->land, $this->contract)->pluck('id')->all(),
        );
        $this->assertSame(
            $this->ids(2, 3, 5),
            ProcurementLotClaims::selectableLots($this->land, $this->property)->pluck('id')->all(),
        );
        // 新しく登録する（今持っている区画が無い）
        $this->assertSame(
            $this->ids(3, 5),
            ProcurementLotClaims::selectableLots($this->land, null)->pluck('id')->all(),
        );
        // 見積りの注文住宅は自分の区画（取られていない）をそのまま選べる
        $this->assertSame(
            $this->ids(3, 5),
            ProcurementLotClaims::selectableLots($this->land, $this->estimate)->pluck('id')->all(),
        );
    }

    public function test_the_current_lot_stays_selectable_even_if_taken_by_another(): void
    {
        // 見積りの注文住宅が区画 1 を持っている（契約が先に取った）。今の区画は選択肢に残す（Bug #130）
        $order = $this->orderOn($this->land, $this->lots[1], 'estimation', 'CO-003', '後から来た見積り');

        $this->assertSame(
            $this->ids(1, 3, 5),
            ProcurementLotClaims::selectableLots($this->land, $order)->pluck('id')->all(),
        );
    }

    public function test_requires_lot(): void
    {
        $lotless = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '区画の無い土地']);

        $this->assertFalse(ProcurementLotClaims::requiresLot($lotless->id, null));
        $this->assertTrue(ProcurementLotClaims::requiresLot($this->land->id, null));
        $this->assertTrue(ProcurementLotClaims::requiresLot((string) $this->land->id, null));
        $this->assertFalse(ProcurementLotClaims::requiresLot('abc', null));
        $this->assertFalse(ProcurementLotClaims::requiresLot(null, null));
        $this->assertFalse(ProcurementLotClaims::requiresLot([(string) $this->land->id], null));
        $this->assertFalse(ProcurementLotClaims::requiresLot(999999, null));

        // #21 の形: 同じ土地に区画なしで保存済み → 区画を選ばずにそのまま保存できる
        $legacyContract = $this->contractOn($this->land, null, '区画なしの契約');
        $this->assertFalse(ProcurementLotClaims::requiresLot($this->land->id, $legacyContract));
        $this->assertFalse(ProcurementLotClaims::requiresLot('0' . $this->land->id, $legacyContract));
        $legacyProperty = $this->propertyOn($this->land, null, 'HS-002');
        $this->assertFalse(ProcurementLotClaims::requiresLot($this->land->id, $legacyProperty));

        // ほかの土地から変える・今は区画を持っている → 区画が要る
        $elsewhere = $this->contractOn($lotless, null, '別の土地の契約');
        $this->assertTrue(ProcurementLotClaims::requiresLot($this->land->id, $elsewhere));
        $this->assertTrue(ProcurementLotClaims::requiresLot($this->land->id, $this->contract));

        // 保存していない値で判定しない（保存済みの仕入れ案件・区画で決める）
        $legacyContract->procurement_id = $lotless->id;
        $this->assertFalse(ProcurementLotClaims::requiresLot($this->land->id, $legacyContract));

        // 住宅事業は保存済みの種別が仕入れ案件のときだけ「保存済みの仕入れ案件」とみなす
        $projectLand = HsProperty::create([
            'property_code' => 'HS-003', 'property_name' => '分譲地の建売', 'status' => 'construction',
            'land_source_type' => 'project_lot', 're_procurement_id' => $this->land->id,
            'address' => '愛媛県松山市平井町1', 'created_by' => $this->user->id,
        ]);
        $this->assertTrue(ProcurementLotClaims::requiresLot($this->land->id, $projectLand));
    }

    public function test_refusal(): void
    {
        // 取られた区画を新しく選ぶ
        $this->assertSame(self::TAKEN_BY_CONTRACT, ProcurementLotClaims::refusal($this->lots[1]->id, null, true));
        $this->assertSame(self::TAKEN_BY_CONTRACT, ProcurementLotClaims::refusal((string) $this->lots[1]->id, null, false));
        // 別の区画から変える
        $this->assertSame(self::TAKEN_BY_CONTRACT, ProcurementLotClaims::refusal($this->lots[1]->id, $this->property, true));

        // 見積りの注文住宅が今持っている区画 1（契約に先に取られた）
        $order = $this->orderOn($this->land, $this->lots[1], 'estimation', 'CO-003', '後から来た見積り');
        $this->assertNull(ProcurementLotClaims::refusal($this->lots[1]->id, $order, false));
        $this->assertSame(self::TAKEN_BY_CONTRACT, ProcurementLotClaims::refusal($this->lots[1]->id, $order, true));

        // 保存していない値で「変えていない」とみなさない（保存済みの区画で比べる）
        $order->re_procurement_lot_id = $this->lots[3]->id;
        $this->assertNull(ProcurementLotClaims::refusal($this->lots[1]->id, $order, false));

        // 契約が自分の区画をそのまま保存する（ほかに取っているものが無い）
        $this->assertNull(ProcurementLotClaims::refusal($this->lots[1]->id, $this->contract, true));
        // 取られていない区画
        $this->assertNull(ProcurementLotClaims::refusal($this->lots[3]->id, null, true));
        $this->assertNull(ProcurementLotClaims::refusal($this->lots[5]->id, null, true));
        // 数字でない・無い区画は、ここでは断らない（入力チェックが断る）
        $this->assertNull(ProcurementLotClaims::refusal('abc', null, true));
        $this->assertNull(ProcurementLotClaims::refusal(null, null, true));
        $this->assertNull(ProcurementLotClaims::refusal([(string) $this->lots[1]->id], null, true));
        $this->assertNull(ProcurementLotClaims::refusal(999999, null, true));

        // 保存済みの見積りの注文住宅（区画 3）が、契約に取られた区画 1 へ変える。「契約」へは進まない（selfTakes=false）
        // → 区画を変えるので断る（設計書 §5.3。保存済みの区画と比べる分かれ目）
        $this->assertSame(self::TAKEN_BY_CONTRACT, ProcurementLotClaims::refusal($this->lots[1]->id, $this->estimate, false));
        // 今の区画 3 をそのまま保存する → 断らない。あとでほかに取られても断らない（Bug #130）
        $this->assertNull(ProcurementLotClaims::refusal($this->lots[3]->id, $this->estimate, false));
        $this->orderOn($this->land, $this->lots[3], 'contracted', 'CO-004', '区画 3 の注文住宅');
        $this->assertNull(ProcurementLotClaims::refusal($this->lots[3]->id, $this->estimate, false));
    }

    public function test_taker_label_names_each_kind(): void
    {
        $this->assertSame('契約「勝山の土地（山田 太郎 様）」', ProcurementLotClaims::takerLabel($this->lots[1]->id));
        $this->assertSame('建売物件「HS-001 勝山の建売」', ProcurementLotClaims::takerLabel($this->lots[2]->id));
        $this->assertNull(ProcurementLotClaims::takerLabel($this->lots[3]->id));
        $this->assertSame('注文住宅「CO-002 勝山の注文住宅」', ProcurementLotClaims::takerLabel($this->lots[4]->id));
        $this->assertNull(ProcurementLotClaims::takerLabel($this->lots[5]->id));

        // 自分は数えない
        $this->assertNull(ProcurementLotClaims::takerLabel($this->lots[1]->id, $this->contract));
        $this->assertNull(ProcurementLotClaims::takerLabel($this->lots[2]->id, $this->property));
        $this->assertNull(ProcurementLotClaims::takerLabel($this->lots[4]->id, $this->contracted));
    }
}
