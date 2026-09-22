<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Inquiry;
use App\Models\InquiryHistory;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 問合せ: 登録画面の既定の問合せ日・採番の年・状態変更で自動で付く対応履歴の日付を、日本の日付で決める（Bug #61）。
 * 時刻は日本時間の 2027/1/1 0:30（UTC ではまだ 2026/12/31）。
 */
class InquiryJapanDateTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_YEARS_MORNING_UTC = '2026-12-31 15:30:00';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'status'               => UserStatus::Active->value,
            'must_change_password' => false,
        ]);
    }

    public function test_the_create_form_uses_the_japanese_date_on_new_years_morning(): void
    {
        $exec = $this->executive();
        Carbon::setTestNow(Carbon::parse(self::NEW_YEARS_MORNING_UTC, 'UTC'));

        $html = $this->actingAs($exec)->get(route('tenant.inquiries.create'))->assertOk()->getContent();

        $this->assertStringContainsString('name="inquiry_date" value="2027-01-01"', $html, '問合せ日の既定が日本の今日でない');
        $this->assertStringContainsString('value="INQ-2027-001"', $html, '問合せ番号の年が日本の年でない');
        $this->assertStringNotContainsString('INQ-2026-', $html);
    }

    public function test_the_automatic_history_of_a_status_change_is_dated_in_japan(): void
    {
        $exec = $this->executive();
        $property = Property::create([
            'code'          => 'PROP-JT-001',
            'name'          => '日付ビル',
            'property_type' => 'tenant',
            'department'    => 'tenant',
            'address'       => '愛媛県松山市本町1-1',
        ]);
        $inquiry = Inquiry::create([
            'inquiry_number' => 'INQ-JT-001',
            'property_id'    => $property->id,
            'status'         => 'follow',
            'contact_name'   => '日付 太郎',
            'inquiry_date'   => '2026-12-01',
            'assigned_to'    => $exec->id,
        ]);

        Carbon::setTestNow(Carbon::parse(self::NEW_YEARS_MORNING_UTC, 'UTC'));
        $this->actingAs($exec)
            ->patch(route('tenant.inquiries.updateStatus', $inquiry), ['status' => 'on_hold'])
            ->assertRedirect();

        $history = InquiryHistory::where('inquiry_id', $inquiry->id)->latest('id')->firstOrFail();
        $this->assertSame('2027-01-01', $history->action_date->toDateString(), '自動の対応履歴の日付が日本の日付でない');
    }
}
