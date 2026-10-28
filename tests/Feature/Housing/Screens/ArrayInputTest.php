<?php

namespace Tests\Feature\Housing\Screens;

use App\Models\HsContract;
use App\Models\HsCustomOrder;
use Tests\Concerns\TampersForms;

/**
 * 住宅事業の画面で、手で組んだ配列の送信が 500 にならないこと（Bug #121）。
 *
 * ⚠ 買主（customer_id）の入力チェックは、以前はほかの欄のあとの 2 回目だった。1 回目で断ると 2 回目の欄の配列が
 *   控えに残り、戻った画面が 500 になっていた（2026-10-28 の送り直しの測定）。1 回にまとめた
 */
class ArrayInputTest extends HousingScreenTestCase
{
    use TampersForms;

    public function test_the_contract_screen_opens_after_an_array_buyer_and_another_error(): void
    {
        $property = $this->property();

        $html = $this->tamper(route('housing.contracts.create', $property), route('housing.contracts.store', $property), [
            'customer_id' => [(string) $this->buyer()->id], 'customer_name' => '',
        ]);

        $this->assertErrorItem($html, '顧客は整数で入力してください。');
        $this->assertSame(0, HsContract::count());
    }

    public function test_the_custom_order_screen_opens_after_an_array_buyer_and_another_error(): void
    {
        $html = $this->tamper(route('housing.custom-orders.create'), route('housing.custom-orders.store'), [
            'customer_id' => [(string) $this->buyer()->id], 'order_name' => '',
        ]);

        $this->assertErrorItem($html, '顧客は整数で入力してください。');
        $this->assertSame(0, HsCustomOrder::count());
    }
}
