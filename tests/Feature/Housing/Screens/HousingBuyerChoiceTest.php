<?php

namespace Tests\Feature\Housing\Screens;

use App\Enums\BuyerRank;
use App\Models\Buyer;
use App\Models\BuyerDepartmentPivot;
use App\Models\HsContract;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use Tests\Concerns\ExpectsSaveFailure;
use Tests\Concerns\FailsImportWrites;

/**
 * 住宅事業の買主は、画面の選択肢と同じ集合（住宅事業の買主＋今の買主）だけを受け付ける（判断待ち D・案 A。docs/RULES.md Bug #149）。
 *
 * 直す前は、注文住宅の登録・建売の契約の登録と更新・契約一覧から開く建売の契約の編集の 4 つの保存が `exists:buyers,id` で、
 * 手で番号を書き換えた送信なら、ほかの部署の買主・削除済みの買主を紐付けられた（契約になると、その買主が住宅事業に足され
 * 〈Buyer::markContracted()〉、住宅事業の画面に名前と連絡先が出る）。注文住宅の編集と、契約一覧から開く注文住宅の契約の編集は
 * Bug #144 で絞ってある（CustomOrderEditBuyerTest）。
 *
 * ⚠ 送る値は描いた画面のフォームから取り、`customer_id` だけを書き換える（手で番号を書き換えた送信。Bug #47）。
 * ⚠ 断ったことは、入力エラーの文・行が増えない／買主が変わらない・ほかの部署の買主に住宅事業の行が足されないの 3 つで見る。
 */
class HousingBuyerChoiceTest extends HousingScreenTestCase
{
    use ExpectsSaveFailure;
    use FailsImportWrites;

    private const REFUSED_ATTRIBUTE = '顧客';

    /** 住宅事業にいない買主（不動産事業だけ）・削除済みの買主（住宅事業の行はある） */
    public static function unofferedBuyers(): array
    {
        return [
            '住宅事業にいない買主' => ['outsider'],
            '削除済みの買主' => ['deleted'],
        ];
    }

    private function unoffered(string $case): Buyer
    {
        if ($case === 'outsider') {
            $buyer = Buyer::create(['last_name' => '部外', 'first_name' => '花子']);
            $buyer->addToDepartment('realestate', '2026-08-01');

            return $buyer;
        }

        $buyer = $this->buyer('削除', '済子');
        $buyer->delete();

        return $buyer;
    }

    private function assertRefused(string $html, Buyer $other, string $case): void
    {
        $this->assertErrorItem($html, trans('validation.in', ['attribute' => self::REFUSED_ATTRIBUTE]));
        if ($case === 'outsider') {
            $this->assertNull($other->fresh()->getDepartmentPivot('housing'), 'ほかの部署の買主が住宅事業に足された');
        }
    }

    /** 画面の買主の選択欄（customer_id）の選択肢の値（先頭の「選択してください」を除く） */
    private function buyerOptions(string $html): array
    {
        $this->assertSame(1, preg_match('/<select name="customer_id".*?<\/select>/s', $html, $select), '画面に買主の選択欄が無い');
        preg_match_all('/<option value="(\d+)"/', $select[0], $values);

        return $values[1];
    }

    /** 今の買主を住宅事業から外す（前からのデータ・あとで部署から外された買主） */
    private function leaveHousing(Buyer $buyer): void
    {
        BuyerDepartmentPivot::where('buyer_id', $buyer->id)->where('department', 'housing')->delete();
        $this->assertNull($buyer->fresh()->getDepartmentPivot('housing'), '前提: 今の買主が住宅事業から外れていない');
    }

    // ============================================================
    // #1 注文住宅の登録
    // ============================================================

    private function customOrderCreateForm(Buyer $chosen): array
    {
        $form = $this->composedForm($this->htmlOf(route('housing.custom-orders.create')), route('housing.custom-orders.store'), 'customOrderForm', null,
            ['buyerSelect' => $this->chooseBuyer($chosen)]);

        return $this->fill($form, ['order_name' => '部外邸 新築工事', 'status' => 'contracted', 'contract_date' => '2026-10-01']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unofferedBuyers')]
    public function test_the_custom_order_registration_refuses_a_buyer_the_screen_does_not_offer(string $case): void
    {
        $offered = $this->buyer();
        $other = $this->unoffered($case);
        $form = $this->fill($this->customOrderCreateForm($offered), ['customer_id' => (string) $other->id]);

        $html = $this->landed($this->submit($form, route('housing.custom-orders.create')));

        $this->assertRefused($html, $other, $case);
        $this->assertSame(0, HsCustomOrder::count(), '選択肢に無い買主で注文住宅が登録された');
    }

    public function test_the_custom_order_registration_offers_only_housing_buyers(): void
    {
        $offered = $this->buyer();
        $outsider = $this->unoffered('outsider');
        $deleted = $this->unoffered('deleted');

        $options = $this->buyerOptions($this->htmlOf(route('housing.custom-orders.create')));

        $this->assertSame([(string) $offered->id], $options, '住宅事業の買主のほか（' . $outsider->id . '・' . $deleted->id . '）が選択肢に出ている');
    }

    // ============================================================
    // #2 建売の契約の登録（物件詳細・物件を選ぶ画面から）
    // ============================================================

    private function contractCreateForm(HsProperty $property, Buyer $chosen): array
    {
        $form = $this->browserForm($this->htmlOf(route('housing.contracts.create', $property)), 'action="' . route('housing.contracts.store', $property) . '"',
            'buyerSelect', $this->chooseBuyer($chosen));

        return $this->fill($form, ['selling_price_land' => '9000000', 'selling_price_building' => '21000000', 'contract_date' => '2026-10-01']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unofferedBuyers')]
    public function test_the_building_contract_registration_refuses_a_buyer_the_screen_does_not_offer(string $case): void
    {
        $property = $this->property();
        $offered = $this->buyer();
        $other = $this->unoffered($case);
        $form = $this->fill($this->contractCreateForm($property, $offered), ['customer_id' => (string) $other->id]);

        $html = $this->landed($this->submit($form, route('housing.contracts.create', $property)));

        $this->assertRefused($html, $other, $case);
        $this->assertSame(0, HsContract::count(), '選択肢に無い買主で契約が登録された');
    }

    public function test_the_building_contract_registration_offers_only_housing_buyers(): void
    {
        $property = $this->property();
        $offered = $this->buyer();
        $outsider = $this->unoffered('outsider');
        $deleted = $this->unoffered('deleted');

        $options = $this->buyerOptions($this->htmlOf(route('housing.contracts.create', $property)));

        $this->assertSame([(string) $offered->id], $options, '住宅事業の買主のほか（' . $outsider->id . '・' . $deleted->id . '）が選択肢に出ている');
    }

    /**
     * 「新規顧客を登録」の小窓で作った買主は、開いていた登録画面からそのまま登録できる
     * （小窓の登録〈quick-store〉が住宅事業に足すので、送ったときの集合に入る。小窓の JS は CustomerScreensTest が見る）。
     */
    public function test_a_buyer_added_from_the_modal_can_be_registered_from_the_open_screen(): void
    {
        $property = $this->property();
        $html = $this->htmlOf(route('housing.contracts.create', $property));
        $created = $this->actingAs($this->user)->postJson(route('housing.customers.quick-store'),
            ['last_name' => '高橋', 'first_name' => '花子', 'acquired_date' => '2026-10-01'])->assertOk()->json();
        $form = $this->browserForm($html, 'action="' . route('housing.contracts.store', $property) . '"', 'buyerSelect', '');
        // 小窓の JS は登録した買主を選択欄に足して選び、顧客名を入れる
        $form = $this->fill($form, ['customer_id' => (string) $created['id'], 'customer_name' => $created['full_name'],
            'selling_price_land' => '9000000', 'selling_price_building' => '21000000', 'contract_date' => '2026-10-01']);

        $html = $this->landed($this->submit($form, route('housing.contracts.create', $property)));

        $this->assertFlash($html, 'success', '契約を登録しました。物件のステータスが「成約」に更新されました。');
        $this->assertSame([$created['id'], '高橋 花子'], [HsContract::firstOrFail()->customer_id, HsContract::firstOrFail()->customer_name]);
    }

    // ============================================================
    // #3 建売の契約の編集（物件詳細から）
    // ============================================================

    private function contractEditForm(HsProperty $property): array
    {
        return $this->composedForm($this->htmlOf(route('housing.contracts.edit', $property)), route('housing.contracts.update', $property), null, null,
            ['buyerSelect' => '']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unofferedBuyers')]
    public function test_the_building_contract_edit_refuses_a_buyer_the_screen_does_not_offer(string $case): void
    {
        $property = $this->property();
        $current = $this->buyer();
        $contract = $this->contract($property, $current);
        $other = $this->unoffered($case);
        $form = $this->fill($this->contractEditForm($property), ['customer_id' => (string) $other->id]);

        $html = $this->landed($this->submit($form, route('housing.contracts.edit', $property)));

        $this->assertRefused($html, $other, $case);
        $this->assertSame([$current->id, '山田 太郎'], [$contract->fresh()->customer_id, $contract->fresh()->customer_name], '選択肢に無い買主が紐付いた');
    }

    /** 今の買主が削除済み・住宅事業にいなくても、画面をそのまま保存できる（選択肢に今の買主を残す。Bug #58・#113 の形） */
    #[\PHPUnit\Framework\Attributes\DataProvider('unofferedBuyers')]
    public function test_the_building_contract_edit_keeps_the_current_buyer(string $case): void
    {
        $property = $this->property();
        $current = $this->buyer('今', '一郎');
        $contract = $this->contract($property, $current);
        $case === 'deleted' ? $current->delete() : $this->leaveHousing($current);
        $form = $this->contractEditForm($property);
        $this->assertSame((string) $current->id, $form['fields']['customer_id'], '今の買主が選ばれていない');
        $form = $this->fill($form, ['notes' => '外構の件']);

        $html = $this->landed($this->submit($form, route('housing.contracts.edit', $property)));

        $this->assertFlash($html, 'success', '契約情報を更新しました。');
        $this->assertSame([$current->id, '外構の件'], [$contract->fresh()->customer_id, $contract->fresh()->notes]);
    }

    /**
     * 建売の契約の更新と、保存のフック（HsContract::saved）が新しい買主のランクを「成約」にすることは 1 つのトランザクション
     * （docs/RULES.md Bug #148 の追記）。ランクで落ちたら（Buyer::markContracted()）、契約の買主も前のまま。
     * 前は契約だけが新しい買主で残り、再送しても買主が変わらないので、ランクは成約にならないままだった。
     */
    public function test_the_building_contract_edit_that_fails_when_ranking_the_new_buyer_keeps_the_old_buyer(): void
    {
        $property = $this->property();
        $current = $this->buyer();
        $contract = $this->contract($property, $current);
        $next = $this->buyer('佐藤', '花子'); // 画面を開く前に（買主の選択肢は画面に描かれる）
        $form = $this->fill($this->contractEditForm($property), ['customer_id' => (string) $next->id]);
        $this->failQuery('/^(insert into|update) "buyer_departments" /i');

        $this->assertSaveFails(fn () => $this->submit($form, route('housing.contracts.edit', $property)));

        $this->assertSame([$current->id, '山田 太郎'], [$contract->fresh()->customer_id, $contract->fresh()->customer_name], 'ランクで落ちたのに契約の買主が変わった');
        $this->assertSame(BuyerRank::C, $next->fresh()->getDepartmentPivot('housing')->rank);
    }

    // ============================================================
    // #4 契約一覧から開く建売の契約の編集
    // ============================================================

    private function buildingEditForm(HsContract $contract): array
    {
        return $this->composedForm($this->htmlOf(route('housing.contracts.edit-building', $contract)), route('housing.contracts.update-building', $contract),
            'buildingContractCostForm', null, ['datePicker' => '', 'buyerSelect' => '']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unofferedBuyers')]
    public function test_the_contract_list_building_edit_refuses_a_buyer_the_screen_does_not_offer(string $case): void
    {
        $current = $this->buyer();
        $contract = $this->contract($this->property(['building_cost' => 15000000]), $current);
        $other = $this->unoffered($case);
        $form = $this->fill($this->buildingEditForm($contract), ['customer_id' => (string) $other->id]);

        $html = $this->landed($this->submit($form, route('housing.contracts.edit-building', $contract)));

        $this->assertRefused($html, $other, $case);
        $this->assertSame([$current->id, '山田 太郎'], [$contract->fresh()->customer_id, $contract->fresh()->customer_name], '選択肢に無い買主が紐付いた');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unofferedBuyers')]
    public function test_the_contract_list_building_edit_keeps_the_current_buyer(string $case): void
    {
        $current = $this->buyer('今', '一郎');
        $contract = $this->contract($this->property(['building_cost' => 15000000]), $current);
        $case === 'deleted' ? $current->delete() : $this->leaveHousing($current);
        $form = $this->buildingEditForm($contract);
        $this->assertSame((string) $current->id, $form['fields']['customer_id'], '今の買主が選ばれていない');
        $form = $this->fill($form, ['notes' => '外構の件']);

        $html = $this->landed($this->submit($form, route('housing.contracts.edit-building', $contract)));

        $this->assertFlash($html, 'success', '契約を更新しました。');
        $this->assertSame([$current->id, '外構の件'], [$contract->fresh()->customer_id, $contract->fresh()->notes]);
    }
}
