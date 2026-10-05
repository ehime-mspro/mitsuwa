<?php

namespace Tests\Feature\Tenant\Screens;

use App\Models\Investment;

/**
 * 投資案件の登録・削除（tenant.investments.store / destroy）を、描いた画面から送る往復で見る。
 * 登録画面の区画の選択肢と明細の行は JS（investmentForm）が描く（`<template x-for>`）。
 */
class InvestmentScreensTest extends TenantScreenTestCase
{
    private function storeForm(string $html, string $steps): array
    {
        return $this->browserForm($html, 'action="' . route('tenant.investments.store') . '"', 'investmentForm', $steps);
    }

    public function test_an_investment_is_registered_with_two_detail_rows(): void
    {
        $unit = $this->unit(1, 'A');
        $url = route('tenant.investments.create');
        $html = $this->htmlOf($url);
        $form = $this->storeForm($html, "data.propertyId = '{$this->building->id}'; data.filterUnits(); data.unitId = '{$unit->id}';
            data.details[0].amount = 500000; data.addDetail();
            data.details[1].cost_item = 'equipment'; data.details[1].amount = 200000; data.details[1].contractor_name = '設備工業';");
        $this->assertCount(2, $form['fields']['details'], '画面の明細の行が送られていない');
        $form = $this->fill($form, ['status' => 'in_progress', 'description' => '1A の内装と設備', 'start_date' => '2026-10-01']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '投資案件を登録しました。');
        $investment = Investment::where('unit_id', $unit->id)->firstOrFail();
        $this->assertSame('renovation', $investment->pattern->value);
        $this->assertSame('in_progress', $investment->status->value);
        $this->assertSame(700000, $investment->total_amount);
        $this->assertSame(
            [['interior', null, 500000], ['equipment', '設備工業', 200000]],
            $investment->details()->orderBy('id')->get()->map(fn ($d) => [$d->cost_item, $d->contractor_name, $d->amount])->all()
        );
    }

    public function test_an_investment_is_deleted_from_the_confirmation(): void
    {
        $investment = Investment::create([
            'investment_number' => 'INV-2026-001', 'property_id' => $this->building->id, 'unit_id' => $this->unit(1, 'A')->id,
            'pattern' => 'renovation', 'status' => 'planning', 'description' => '内装', 'total_amount' => 100000,
        ]);
        $url = route('tenant.investments.show', $investment);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('tenant.investments.destroy', $investment) . '"');
        $this->assertSame('DELETE', $form['method']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '投資案件を削除しました。');
        $this->assertSoftDeleted($investment);
    }
}
