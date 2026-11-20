<?php

namespace Tests\Feature\Housing\Screens;

use App\Enums\BuyerRank;
use App\Models\Buyer;
use App\Models\HsCustomOrder;

/**
 * 注文住宅の編集画面でも買主を選ぶ（判断待ち ⑥。設計書 docs/superpowers/specs/2026-11-20-custom-order-edit-buyer-design.md）。
 *
 * 直す前は編集画面だけ顧客名が自由入力で、テナントの顧客（/api/tenant/customers/search。住宅事業の買主とは別の表）を検索し、
 * 保存は customer_id を受け取らなかった（exclude）＝顧客名だけ変わって紐付く買主と食い違った。前例は建売の契約の編集画面（91368c05）。
 *
 * ⚠ 送る値は描いた画面から取る（Bug #47）。買主の選択は入れ子の部品 buyerSelect なので customOrderEditForm() で組む。
 * ⚠ 選択肢は住宅事業の買主＋今の買主（削除済み・住宅事業にいなくても残す。Bug #58・#113 の形）。
 */
class CustomOrderEditBuyerTest extends HousingScreenTestCase
{
    private const UPDATED = '注文住宅案件「CO-001」を更新しました。';

    private function linkedOrder(Buyer $buyer, array $overrides = []): HsCustomOrder
    {
        return $this->customOrder(array_merge(['customer_id' => $buyer->id, 'customer_name' => $buyer->full_name], $overrides));
    }

    private function save(HsCustomOrder $order, array $form): string
    {
        return $this->landed($this->submit($form, route('housing.custom-orders.edit', $order)));
    }

    public function test_the_edit_screen_picks_a_buyer_and_no_longer_searches_tenant_customers(): void
    {
        $buyer = $this->buyer();
        $outsider = Buyer::create(['last_name' => '部外', 'first_name' => '花子']); // 住宅事業にいない・今の買主でもない
        $order = $this->linkedOrder($buyer);
        $html = $this->htmlOf(route('housing.custom-orders.edit', $order));

        $this->assertStringContainsString('x-data="buyerSelect(', $html, '編集画面に買主の選択が無い');
        $this->assertStringNotContainsString('/api/tenant/customers/search', $html, 'テナントの顧客を検索している');
        $this->assertStringNotContainsString('searchCustomer', $html);
        $this->assertStringNotContainsString('href="' . route('tenant.customers.create') . '"', $html, 'テナントの顧客の登録画面へのリンクが残っている');
        $this->assertStringNotContainsString('value="' . $outsider->id . '"', $html, '住宅事業にいない買主まで選択肢に出ている');
        $this->assertSame((string) $buyer->id, $this->customOrderEditForm($order, html: $html)['fields']['customer_id'], '今の買主が選ばれていない');
    }

    public function test_swapping_the_buyer_from_the_screen_updates_both_id_and_name(): void
    {
        $a = $this->buyer('旧', '一郎');
        $b = $this->buyer('新', '二郎');
        $order = $this->linkedOrder($a);

        $html = $this->save($order, $this->customOrderEditForm($order, buyerSteps: $this->chooseBuyer($b)));

        $this->assertFlash($html, 'success', self::UPDATED);
        $order->refresh();
        $this->assertSame([$b->id, '新 二郎', $this->user->id], [$order->customer_id, $order->customer_name, $order->updated_by]);
    }

    public function test_a_sent_customer_name_is_replaced_by_the_buyer_name(): void
    {
        $buyer = $this->buyer('佐藤', '花子');
        $order = $this->linkedOrder($buyer);
        $form = $this->fill($this->customOrderEditForm($order), ['customer_name' => 'でたらめ']);

        $html = $this->save($order, $form);

        $this->assertFlash($html, 'success', self::UPDATED);
        $this->assertSame('佐藤 花子', $order->fresh()->customer_name, '送った顧客名がそのまま保存された（買主と食い違う）');
    }

    public function test_an_order_without_a_buyer_must_pick_one(): void
    {
        $buyer = $this->buyer();
        $order = $this->customOrder(['customer_id' => null]);
        $form = $this->customOrderEditForm($order);
        $this->assertSame('', $form['fields']['customer_id'], '買主の無い案件で何かが選ばれている');
        $form = $this->fill($form, ['notes' => '外構は別途']);

        $html = $this->save($order, $form);

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '顧客']));
        $this->assertSame([null, null], [$order->fresh()->customer_id, $order->fresh()->notes], '買主を選ばずに保存された');
        $again = $this->customOrderEditForm($order, html: $html, buyerSteps: $this->chooseBuyer($buyer));
        $this->assertSame('外構は別途', $again['fields']['notes'], '入力エラーで戻ると打った備考が消える');

        $html = $this->save($order, $again);

        $this->assertFlash($html, 'success', self::UPDATED);
        $this->assertSame([$buyer->id, '山田 太郎', '外構は別途'], [$order->fresh()->customer_id, $order->fresh()->customer_name, $order->fresh()->notes]);
    }

    public function test_the_chosen_buyer_survives_an_input_error(): void
    {
        $a = $this->buyer('旧', '一郎');
        $b = $this->buyer('新', '二郎');
        $order = $this->linkedOrder($a);
        $form = $this->fill($this->customOrderEditForm($order, buyerSteps: $this->chooseBuyer($b)), ['order_name' => '']);

        $html = $this->save($order, $form);

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '案件名']));
        $again = $this->customOrderEditForm($order, html: $html);
        $this->assertSame([(string) $b->id, '新 二郎'], [$again['fields']['customer_id'], $again['fields']['customer_name']], '入力エラーで戻ると選んだ買主が今の買主に戻る');
        $this->assertSame($a->id, $order->fresh()->customer_id);
    }

    public function test_a_deleted_current_buyer_stays_selected(): void
    {
        $buyer = $this->buyer();
        $order = $this->linkedOrder($buyer);
        $buyer->delete();
        $html = $this->htmlOf(route('housing.custom-orders.edit', $order));

        $this->assertStringContainsString('（削除済み）', $html, '削除済みの今の買主が選択肢に無い');
        $form = $this->customOrderEditForm($order, html: $html);
        $this->assertSame((string) $buyer->id, $form['fields']['customer_id'], '削除済みの今の買主が選ばれていない');

        $html = $this->save($order, $form);

        $this->assertFlash($html, 'success', self::UPDATED);
        $this->assertSame($buyer->id, $order->fresh()->customer_id);
    }

    public function test_a_current_buyer_outside_housing_stays_selected(): void
    {
        $buyer = Buyer::create(['last_name' => '部外', 'first_name' => '花子']);
        $order = $this->linkedOrder($buyer);
        $form = $this->customOrderEditForm($order);
        $this->assertSame((string) $buyer->id, $form['fields']['customer_id'], '住宅事業にいない今の買主が選ばれていない');

        $html = $this->save($order, $form);

        $this->assertFlash($html, 'success', self::UPDATED);
        $this->assertSame($buyer->id, $order->fresh()->customer_id);
    }

    public function test_changing_the_buyer_of_a_contracted_order_marks_the_new_buyer_contracted(): void
    {
        $a = $this->buyer('旧', '一郎');
        $b = $this->buyer('新', '二郎');
        $order = $this->linkedOrder($a, ['status' => 'contracted', 'contract_date' => '2026-09-10']);

        $html = $this->save($order, $this->customOrderEditForm($order, buyerSteps: $this->chooseBuyer($b)));

        $this->assertFlash($html, 'success', self::UPDATED);
        $this->assertSame(
            [BuyerRank::Contracted, BuyerRank::Contracted],
            [$b->fresh()->getDepartmentPivot('housing')->rank, $a->fresh()->getDepartmentPivot('housing')->rank],
            '契約済みの案件で買主を変えても新しい買主が成約にならない（前の買主は成約のまま）'
        );
    }
}
