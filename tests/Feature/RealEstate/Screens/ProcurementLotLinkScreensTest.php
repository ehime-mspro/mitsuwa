<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Http\Controllers\RealEstate\ProcurementLotController;
use App\Models\HsContract;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProcurementLot;
use App\Support\ProcurementLotClaims;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 区画を足すとき、区画を選んでいない契約などをその区画につなぐ
 * （設計書 docs/superpowers/specs/2026-11-22-lot-link-on-add-design.md の決まり 1〜5）。
 *
 * ⚠ 送る値は描いた画面のフォームから取る（Bug #47）。追加のフォームは id="lot-create-form" で掴む。
 * ⚠ 入力エラーの期待文は 1 件の全文で見る（Bug #49）。
 */
class ProcurementLotLinkScreensTest extends RealEstateScreenTestCase
{
    private const CREATE = 'id="lot-create-form"';

    private const CONTRACT_LABEL = '契約「勝山の土地（山田 太郎 様）」';

    private const LINKED_TO_CONTRACT = '区画を登録しました（契約「勝山の土地（山田 太郎 様）」をこの区画に紐づけました）。';

    private const ONE_NOTICE = 'この区画は契約「勝山の土地（山田 太郎 様）」に紐づけます（区画のステータスは「成約」になります）。';

    private const STALE = '区画を選んでいない契約・建売物件・注文住宅が、画面を開いたあとで変わりました。紐づけるものを確かめて、もう一度登録してください。';

    private const CHOOSE = 'この区画に紐づけるものを選んでください。';

    private function detailUrl(ReProcurement $land): string
    {
        return route('realestate.procurements.show', $land);
    }

    /** 区画を選んでいない不動産の契約（買主 山田 太郎・#21 の形） */
    private function lotlessContract(ReProcurement $land): ReContract
    {
        return ReContract::create([
            'department' => 'realestate', 'contract_type' => 'procurement_land', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => '勝山の土地', 'procurement_id' => $land->id, 'buyer_id' => $this->buyer()->id,
            'contract_amount_land' => 10000000, 'contract_amount_building' => 0, 'cost_amount' => 9000000, 'created_by' => $this->user->id,
        ]);
    }

    /** 区画を選んでいない建売（$contracted なら建売の契約を付ける） */
    private function building(ReProcurement $land, string $code, bool $contracted): HsProperty
    {
        $property = HsProperty::create([
            'property_code' => $code, 'property_name' => '勝山の建売', 'status' => 'construction',
            'land_source_type' => 'procurement', 're_procurement_id' => $land->id,
            'address' => '愛媛県松山市勝山町2-2', 'created_by' => $this->user->id,
        ]);
        if ($contracted) {
            HsContract::create([
                'property_id' => $property->id, 'customer_name' => '高橋', 'selling_price_land' => 1,
                'selling_price_building' => 1, 'contract_date' => '2026-11-01', 'created_by' => $this->user->id,
            ]);
        }

        return $property;
    }

    /** 区画を選んでいない注文住宅 */
    private function order(ReProcurement $land, string $code, string $status): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code' => $code, 'order_name' => '勝山の注文住宅', 'status' => $status, 'customer_name' => '大西 花子',
            'land_source_type' => 'procurement', 're_procurement_id' => $land->id,
            'address' => '愛媛県松山市勝山町3-3', 'created_by' => $this->user->id,
        ]);
    }

    /** 追加のフォームの紐づけるものの選択欄の option の [value, 文字] */
    private function linkOptions(string $html): array
    {
        $this->assertSame(1, preg_match('/<select id="lot-new-link" name="lot_link"[^>]*>(.*?)<\/select>/su', $html, $m), '紐づけるものの選択欄が無い');
        preg_match_all('/<option value="([^"]*)"[^>]*>\s*(.*?)\s*<\/option>/su', $m[1], $options, PREG_SET_ORDER);

        return array_map(fn (array $o) => [$o[1], html_entity_decode($o[2], ENT_QUOTES, 'UTF-8')], $options);
    }

    /** 区画の表のその区画の行（<tr>…</tr>） */
    private function row(string $html, ReProcurementLot $lot): string
    {
        $start = strpos($html, '<tr id="lot-row-' . $lot->id . '"');
        $this->assertNotFalse($start, "{$lot->lot_number}号地の行が区画の表に無い");

        return $this->balancedElement($html, $start, 'tr');
    }

    // ================================================================
    // 1 件のとき（自動でつなぐ）
    // ================================================================

    public function test_adding_a_lot_links_the_only_contract_without_a_lot(): void
    {
        $land = $this->landProcurement(['status' => 'sold']);
        $contract = $this->lotlessContract($land);
        $url = $this->detailUrl($land);

        $detail = $this->htmlOf($url);
        $form = $this->parseForm($detail, self::CREATE);
        $this->assertArrayNotHasKey('status', $form['fields'], '区画を選んでいないものがあるのにステータスの欄がある');
        $this->assertSame('contract:' . $contract->id, $form['fields']['lot_link'] ?? null);
        $this->assertSame('contract:' . $contract->id, $form['fields']['lot_link_candidates'] ?? null);
        $this->assertStringContainsString(e(self::ONE_NOTICE), $detail);

        $html = $this->landed($this->submit($form, $url));

        $this->assertLotNotice($html, 'success', self::LINKED_TO_CONTRACT);
        $lot = ReProcurementLot::where('procurement_id', $land->id)->sole();
        $this->assertSame(['sold', $lot->id, 'sold'], [$lot->status->value, $contract->fresh()->procurement_lot_id, $land->fresh()->status->value]);
        $this->assertStringContainsString('href="' . route('realestate.contracts.show', $contract) . '"', $this->row($html, $lot), '区画の行の「紐づいているもの」に契約が無い');
    }

    /** 設計書の目標 2: つないだ契約を消すと、区画は販売中・土地は区画から集約して販売中 */
    public function test_the_linked_lot_returns_to_on_sale_when_its_contract_is_deleted(): void
    {
        $land = $this->landProcurement(['status' => 'sold']);
        $contract = $this->lotlessContract($land);
        $url = $this->detailUrl($land);
        $this->landed($this->submit($this->parseForm($this->htmlOf($url), self::CREATE), $url));
        $lot = ReProcurementLot::sole();

        $show = route('realestate.contracts.show', $contract);
        $form = $this->parseForm($this->htmlOf($show), 'action="' . route('realestate.contracts.destroy', $contract) . '"');
        $this->assertSame('DELETE', $form['method']);
        $this->submit($form, $show)->assertRedirect();

        $this->assertSame(['on_sale', 'selling'], [$lot->fresh()->status->value, $land->fresh()->status->value]);
    }

    /** 直す前の ①: 区画を「販売中」に戻しても、つないだ区画で 2 件目の契約は断る（取られているかは紐付けで決める） */
    public function test_a_second_contract_on_the_linked_lot_is_refused_even_after_it_is_set_on_sale(): void
    {
        $land = $this->landProcurement(['status' => 'sold']);
        $this->lotlessContract($land);
        $url = $this->detailUrl($land);
        $this->landed($this->submit($this->parseForm($this->htmlOf($url), self::CREATE), $url));
        $lot = ReProcurementLot::sole();

        $edit = $this->fill($this->parseForm($this->htmlOf($url), 'id="lot-edit-form-' . $lot->id . '"'), ['status' => 'on_sale']);
        $this->landed($this->submit($edit, $url));
        $this->assertSame('on_sale', $lot->fresh()->status->value, 'テストの前提: 区画を販売中に戻せていない');

        $response = $this->actingAs($this->user)->from(route('realestate.contracts.create'))->post(route('realestate.contracts.store'), [
            'contract_type' => 'procurement_land', 'procurement_id' => $land->id, 'procurement_lot_id' => $lot->id,
            'contract_date' => '2026-10-01', 'buyer_id' => $this->buyer('高橋', '一郎')->id,
            'contract_amount_land' => 20000000, 'cost_amount' => 15000000, 'property_name' => '勝山の土地 2',
        ]);

        $response->assertSessionHasErrors([
            'procurement_lot_id' => strtr(ProcurementLotClaims::TAKEN, [':number' => '1', ':taker' => self::CONTRACT_LABEL]),
        ]);
        $this->assertSame(1, ReContract::count());
    }

    // ================================================================
    // 2 件以上のとき（選ぶ）
    // ================================================================

    public function test_two_or_more_owners_are_chosen_on_the_form(): void
    {
        $land = $this->landProcurement();
        $open = $this->building($land, 'HS-001', false);
        $sold = $this->building($land, 'HS-002', true);
        $contracted = $this->order($land, 'CO-001', 'contracted');
        $estimate = $this->order($land, 'CO-002', 'estimation');
        $url = $this->detailUrl($land);

        $detail = $this->htmlOf($url);
        $this->assertSame([
            ['', '— 選んでください —'],
            ['property:' . $open->id, '建売物件「HS-001 勝山の建売」（区画は販売中）'],
            ['property:' . $sold->id, '建売物件「HS-002 勝山の建売」（区画は成約）'],
            ['order:' . $contracted->id, '注文住宅「CO-001 勝山の注文住宅」（区画は成約）'],
        ], $this->linkOptions($detail));
        $this->assertStringContainsString(
            e('区画を選んでいない契約・建売物件・注文住宅が 3 件あります。この区画に紐づけるものを選んでください（区画を足すたびに 1 つずつ紐づけます。区画のステータスは、紐づけるものに合わせて決まります）。'),
            $detail
        );
        $form = $this->parseForm($detail, self::CREATE);
        $this->assertArrayNotHasKey('status', $form['fields'], '区画を選んでいないものがあるのにステータスの欄がある');

        // 選ばずに送る → 断る
        $html = $this->landed($this->submit($form, $url));
        $this->assertErrorItem($html, self::CHOOSE);
        $this->assertSame(0, ReProcurementLot::count());

        // 契約の無い建売を選ぶ → 区画は販売中
        $html = $this->landed($this->submit($this->fill($this->parseForm($this->htmlOf($url), self::CREATE), ['lot_link' => 'property:' . $open->id]), $url));
        $this->assertLotNotice($html, 'success', '区画を登録しました（建売物件「HS-001 勝山の建売」をこの区画に紐づけました）。');
        $first = ReProcurementLot::sole();
        $this->assertSame(['on_sale', $first->id], [$first->status->value, $open->fresh()->re_procurement_lot_id]);
        $this->assertSame(['', 'property:' . $sold->id, 'order:' . $contracted->id], array_column($this->linkOptions($html), 0));
        $form = $this->parseForm($html, self::CREATE);
        $this->assertSame('property:' . $sold->id . ',order:' . $contracted->id, $form['fields']['lot_link_candidates']);

        // 契約の注文住宅を選ぶ → 区画は成約。残りは 1 件（案内に替わる）
        $html = $this->landed($this->submit($this->fill($form, ['lot_link' => 'order:' . $contracted->id, 'area_sqm' => '100.00']), $url));
        $this->assertLotNotice($html, 'success', '区画を登録しました（注文住宅「CO-001 勝山の注文住宅」をこの区画に紐づけました）。');
        $second = ReProcurementLot::where('id', '!=', $first->id)->sole();
        $this->assertSame(['sold', $second->id], [$second->status->value, $contracted->fresh()->re_procurement_lot_id]);
        $this->assertStringContainsString(e('この区画は建売物件「HS-002 勝山の建売」に紐づけます（区画のステータスは「成約」になります）。'), $html);
        $this->assertNull($estimate->fresh()->re_procurement_lot_id, '見積りの注文住宅がつながった');
    }

    /** 区画を選んでいないものが無い土地は今のまま（見積りの注文住宅は数えない。決まり 4） */
    public function test_a_land_without_owners_keeps_the_status_field(): void
    {
        $land = $this->landProcurement();
        $estimate = $this->order($land, 'CO-001', 'estimation');
        $url = $this->detailUrl($land);

        $form = $this->parseForm($this->htmlOf($url), self::CREATE);
        $this->assertSame('on_sale', $form['fields']['status'] ?? null);
        $this->assertArrayNotHasKey('lot_link', $form['fields']);
        $this->assertSame('', $form['fields']['lot_link_candidates'] ?? null);

        $html = $this->landed($this->submit($this->fill($form, ['status' => 'negotiating']), $url));

        $this->assertLotNotice($html, 'success', '区画を登録しました。');
        $this->assertSame('negotiating', ReProcurementLot::sole()->status->value);
        $this->assertNull($estimate->fresh()->re_procurement_lot_id);
    }

    // ================================================================
    // Review Focus
    // ================================================================

    public static function changedOwners(): array
    {
        return [
            '開いたあとで増えた' => ['appeared'],
            '開いたあとで消えた' => ['gone'],
            'この変更の前に開いた画面' => ['old screen'],
        ];
    }

    /** 決まり 5: 画面を開いたあとで顔ぶれが変わったら、何も保存せず理由を出す（打った値は残る） */
    #[DataProvider('changedOwners')]
    public function test_an_old_screen_is_refused_when_the_owners_changed(string $case): void
    {
        $land = $this->landProcurement();
        $contract = $case === 'appeared' ? null : $this->lotlessContract($land);
        $url = $this->detailUrl($land);
        $form = $this->parseForm($this->htmlOf($url), self::CREATE);

        if ($case === 'appeared') {
            $contract = $this->lotlessContract($land);
        } elseif ($case === 'gone') {
            $contract->delete();
            $contract = null;
        } else {
            unset($form['fields']['lot_link'], $form['fields']['lot_link_candidates']);
            $form['fields']['status'] = 'on_sale';
        }
        $form['fields']['lot_number'] = '7';

        $response = $this->submit($form, $url);
        $response->assertRedirect($url . '#lot');
        $html = $this->landed($response);

        $this->assertErrorItem($html, self::STALE);
        $this->assertSame(0, ReProcurementLot::count());
        if ($contract !== null) {
            $this->assertNull($contract->fresh()->procurement_lot_id);
        }
        $this->assertSame('7', $this->parseForm($html, self::CREATE)['fields']['lot_number'], '打った号地番号が戻った画面に残っていない');
    }

    public static function handMadeLinks(): array
    {
        return [
            '無い契約' => ['missing'],
            'ほかの土地の契約' => ['other land'],
            '見積りの注文住宅' => ['estimate'],
            '形の違う値' => ['junk'],
        ];
    }

    /** 手で組んだ lot_link（候補に無いもの）は断り、ほかの土地の契約に区画を書かない */
    #[DataProvider('handMadeLinks')]
    public function test_a_hand_made_link_outside_the_list_is_refused(string $case): void
    {
        $land = $this->landProcurement();
        $this->lotlessContract($land);
        $other = $this->lotlessContract($this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '別の土地']));
        $estimate = $this->order($land, 'CO-001', 'estimation');
        $url = $this->detailUrl($land);

        $form = $this->parseForm($this->htmlOf($url), self::CREATE);
        $form['fields']['lot_link'] = match ($case) {
            'missing' => 'contract:999999',
            'other land' => 'contract:' . $other->id,
            'estimate' => 'order:' . $estimate->id,
            'junk' => 'x',
        };
        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.in', ['attribute' => '紐づけるもの']));
        $this->assertSame(0, ReProcurementLot::count());
        $this->assertNull($other->fresh()->procurement_lot_id);
        $this->assertNull($estimate->fresh()->re_procurement_lot_id);
    }

    public static function arrayFields(): array
    {
        return [
            '紐づけるもの' => ['lot_link', '紐づけるもの'],
            '紐づけるものの一覧' => ['lot_link_candidates', '紐づけるものの一覧'],
        ];
    }

    /** 配列で送られた欄は 500 にせず断り、戻った画面が開く（Bug #121） */
    #[DataProvider('arrayFields')]
    public function test_array_shaped_link_fields_are_refused(string $field, string $attribute): void
    {
        $land = $this->landProcurement();
        $this->lotlessContract($land);
        $url = $this->detailUrl($land);

        $form = $this->parseForm($this->htmlOf($url), self::CREATE);
        $form['fields'][$field] = ['x'];
        $response = $this->submit($form, $url);

        $response->assertRedirect($url . '#lot');
        $this->assertErrorItem($this->landed($response), trans('validation.string', ['attribute' => $attribute]));
        $this->assertSame(0, ReProcurementLot::count());
    }

    /** 2 件以上で選んだあと号地の重なりで断られて戻ると、打った値と選んだものが残る */
    public function test_an_input_error_keeps_the_chosen_owner(): void
    {
        $land = $this->landProcurement();
        $this->procurementLot($land, ['lot_number' => 1]);
        // 区画のある土地で区画を選んでいない建売（この変更の前のデータの形）
        $this->building($land, 'HS-001', false);
        $second = $this->building($land, 'HS-002', false);
        $url = $this->detailUrl($land);

        $form = $this->fill($this->parseForm($this->htmlOf($url), self::CREATE), [
            'lot_link' => 'property:' . $second->id, 'lot_number' => '1', 'area_sqm' => '90.00',
        ]);
        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, ProcurementLotController::DUPLICATE_NUMBER);
        $again = $this->parseForm($html, self::CREATE);
        $this->assertSame(['property:' . $second->id, '1', '90.00'], [$again['fields']['lot_link'], $again['fields']['lot_number'], $again['fields']['area_sqm']]);
        $this->assertSame(1, ReProcurementLot::count());
        $this->assertNull($second->fresh()->re_procurement_lot_id);
    }
}
