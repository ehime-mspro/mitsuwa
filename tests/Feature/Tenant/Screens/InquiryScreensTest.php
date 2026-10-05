<?php

namespace Tests\Feature\Tenant\Screens;

use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use App\Models\InquiryHistory;

/**
 * 問合せの登録・対応履歴の追加・削除（tenant.inquiries.store / storeHistory / destroy）を、描いた画面から送る往復で見る。
 * 登録画面の希望区画は、JS の状態（checkedUnits）から `<template x-for>` の hidden が送られる。
 */
class InquiryScreensTest extends TenantScreenTestCase
{
    private function inquiry(InquiryStatus $status = InquiryStatus::Follow): Inquiry
    {
        return Inquiry::create([
            'inquiry_number' => 'INQ-2026-050', 'property_id' => $this->building->id, 'contact_name' => '問合 花子',
            'inquiry_date' => '2026-09-01', 'status' => $status->value,
        ]);
    }

    public function test_an_inquiry_is_registered_from_the_screen(): void
    {
        $wanted = $this->unit(1, 'A');
        $other = $this->unit(2, 'A');
        $customer = $this->customer('一番町商事', 'CU-0020');
        $url = route('tenant.inquiries.create');
        $html = $this->htmlOf($url);
        $found = $this->actingAs($this->user)->getJson('/api/tenant/customers/search?q=' . urlencode('一番町'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->json();

        $form = $this->browserForm($html, 'action="' . route('tenant.inquiries.store') . '"', 'inquiryCreateForm',
            "data.propertyId = '{$this->building->id}'; data.toggleUnit({$wanted->id}); data.toggleUnit({$other->id}); data.toggleUnit({$other->id});"
            . ' data.selectCustomer(' . json_encode($found[0], JSON_UNESCAPED_UNICODE) . ');');
        $this->assertSame([(string) $wanted->id], $form['fields']['unit_ids'], '画面で選んだ希望区画が送られていない');
        $form = $this->fill($form, ['contact_name' => '一番 次郎', 'inquiry_date' => '2026-10-01', 'budget_max' => '15']);

        $html = $this->landed($this->submit($form, $url));

        $inquiry = Inquiry::where('contact_name', '一番 次郎')->firstOrFail();
        $this->assertFlash($html, 'success', "問合せ「{$inquiry->inquiry_number}」を登録しました。");
        $this->assertSame([$wanted->id], $inquiry->units()->pluck('units.id')->all());
        $this->assertSame($customer->id, $inquiry->customer_id);
        $this->assertSame(15, $inquiry->budget_max);
        $this->assertSame('first_contact', $inquiry->histories()->value('action_type'), '初回の対応履歴が作られていない');
    }

    public function test_a_history_is_added_from_the_inquiry_screen(): void
    {
        $inquiry = $this->inquiry();
        $url = route('tenant.inquiries.show', $inquiry);
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('tenant.inquiries.storeHistory', $inquiry) . '"'), [
            'action_type' => 'viewing', 'action_date' => '2026-10-02', 'content' => '1A を内見',
        ]);

        $response = $this->submit($form, $url);
        $response->assertRedirect($url . '#history-form');
        $html = $this->landed($response);

        $this->assertFlash($html, 'success', '対応履歴を追加しました。');
        $history = InquiryHistory::where('inquiry_id', $inquiry->id)->firstOrFail();
        $this->assertSame('viewing', $history->action_type);
        $this->assertSame('1A を内見', $history->content);
        $this->assertSame($this->user->id, $history->created_by);
    }

    public function test_a_closed_inquiry_takes_no_history_even_from_an_old_screen(): void
    {
        $inquiry = $this->inquiry();
        $url = route('tenant.inquiries.show', $inquiry);
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('tenant.inquiries.storeHistory', $inquiry) . '"'), ['content' => '追加しない']);
        $inquiry->update(['status' => InquiryStatus::Lost->value]);
        $this->assertStringNotContainsString('action="' . route('tenant.inquiries.storeHistory', $inquiry) . '"', $this->htmlOf($url), '終了した問合せに対応履歴の欄が出ている');

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'error', 'この問合せは終了しているため、対応履歴を追加できません。');
        $this->assertSame(0, InquiryHistory::where('inquiry_id', $inquiry->id)->count());
    }

    public function test_an_inquiry_is_deleted_from_the_confirmation(): void
    {
        $inquiry = $this->inquiry();
        $url = route('tenant.inquiries.show', $inquiry);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('tenant.inquiries.destroy', $inquiry) . '"');
        $this->assertSame('DELETE', $form['method']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '問合せを削除しました。');
        $this->assertSoftDeleted($inquiry);
    }
}
