<?php

namespace Tests\Feature\Mansion\Screens;

use App\Models\MsParkingContract;
use Tests\Concerns\TampersForms;

/**
 * 賃貸マンションの画面で、手で組んだ配列の送信が 500 にならないこと（Bug #121）。
 */
class ArrayInputTest extends MansionScreenTestCase
{
    use TampersForms;

    /** 物件は駐車場を絞り込むための欄で、規則が無く、配列のまま控えて戻った画面が 500 になっていた */
    public function test_the_parking_contract_screen_opens_after_an_array_property(): void
    {
        $html = $this->tamper(route('mansion.parking-contracts.create'), route('mansion.parking-contracts.store'), [
            'property_id' => [(string) $this->building->id], 'monthly_fee' => '',
        ]);

        $this->assertErrorItem($html, '月額料金は必須です。');
        $this->assertSame(0, MsParkingContract::count());
    }
}
