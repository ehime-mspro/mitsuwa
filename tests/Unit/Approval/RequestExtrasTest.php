<?php

namespace Tests\Unit\Approval;

use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Support\Approval\RequestExtras;
use Tests\TestCase;

/** 追加の入力欄（坪数・坪単価・担当者・契約予定日。要件 5.5.5・段階5 設計書 D2・D12） */
class RequestExtrasTest extends TestCase
{
    public function test_a_type_uses_only_the_fields_it_turned_on_in_the_fixed_order(): void
    {
        $this->assertSame([], RequestExtras::usedBy(null));
        $this->assertSame([], RequestExtras::usedBy(new ApprovalType()));
        $this->assertSame(
            ['tsubo', 'staff', 'contract_date'],
            RequestExtras::usedBy(new ApprovalType(['uses_contract_date' => true, 'uses_tsubo' => true, 'uses_staff' => true]))
        );
        $this->assertSame(['staff', 'contract_date'], RequestExtras::REQUIRED);
    }

    public function test_the_values_of_a_request_are_those_of_the_used_fields(): void
    {
        $type    = new ApprovalType(['uses_tsubo' => true, 'uses_tsubo_price' => true, 'uses_contract_date' => true]);
        $request = new ApprovalRequest(['tsubo' => '38.5', 'tsubo_price' => 1083000, 'staff' => '使わない欄', 'contract_date' => '2026-10-20']);

        $this->assertSame(['tsubo' => '38.50', 'tsubo_price' => 1083000, 'contract_date' => '2026-10-20'], RequestExtras::valuesOf($request, $type));
        $this->assertSame([], RequestExtras::valuesOf($request, null));
    }

    public function test_the_values_from_a_snapshot_follow_the_fixed_order(): void
    {
        // MySQL は JSON のキーを並べ替えて返す（キーの長さの順）
        $this->assertSame(
            ['tsubo' => '38.50', 'staff' => '佐藤', 'contract_date' => null],
            RequestExtras::ordered(['contract_date' => null, 'staff' => '佐藤', 'tsubo' => '38.50', 'unknown' => 'x'])
        );
        $this->assertSame([], RequestExtras::ordered(null));
    }

    public function test_the_display_of_each_field(): void
    {
        $this->assertSame('38.5坪', RequestExtras::display('tsubo', '38.50'));
        $this->assertSame('40坪', RequestExtras::display('tsubo', '40.00'));
        $this->assertSame('1,234.25坪', RequestExtras::display('tsubo', '1234.25'));
        $this->assertSame('1,083,000円', RequestExtras::display('tsubo_price', 1083000));
        $this->assertSame('佐藤 健一', RequestExtras::display('staff', '佐藤 健一'));
        $this->assertSame('2026/10/20', RequestExtras::display('contract_date', '2026-10-20'));
        $this->assertNull(RequestExtras::display('staff', ''));
        $this->assertNull(RequestExtras::display('contract_date', null));
    }
}
