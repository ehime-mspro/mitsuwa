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

    public function test_the_detail_rows_are_kept_after_an_input_error(): void
    {
        $url = route('tenant.investments.create');
        $html = $this->htmlOf($url);
        // 区画を選び忘れて送る
        $form = $this->storeForm($html, "data.propertyId = '{$this->building->id}'; data.filterUnits();
            data.details[0].amount = 500000; data.addDetail();
            data.details[1].cost_item = 'equipment'; data.details[1].amount = 200000;");

        $html = $this->landed($this->submit($form, $url));

        $this->assertInputError($html, '区画は必須です。');
        $state = $this->driveAlpine($html, 'investmentForm', $this->xData($html, 'investmentForm'), '')['state'];
        $this->assertSame(
            [['interior', '500000'], ['equipment', '200000']],
            array_map(fn (array $row) => [$row['cost_item'], (string) $row['amount']], $state['details']),
            '入力エラーで戻ると、打ち込んだ明細の行が消える'
        );
        $this->assertSame(0, Investment::count());
    }

    public function test_the_edit_screen_keeps_the_changed_detail_rows_after_an_input_error(): void
    {
        $investment = Investment::create([
            'investment_number' => 'INV-2026-002', 'property_id' => $this->building->id, 'unit_id' => $this->unit(1, 'A')->id,
            'pattern' => 'renovation', 'status' => 'planning', 'description' => '内装', 'total_amount' => 100000,
        ]);
        $investment->details()->create(['cost_item' => 'interior', 'amount' => 100000]);
        $url = route('tenant.investments.edit', $investment);
        $html = $this->htmlOf($url);
        // 明細を直し、工事完了日を開始日より前にして送る
        $form = $this->browserForm($html, 'action="' . route('tenant.investments.update', $investment) . '"', 'investmentEditForm',
            "data.details[0].amount = 150000; data.addDetail(); data.details[1].cost_item = 'design'; data.details[1].amount = 30000;");
        $form = $this->fill($form, ['start_date' => '2026-10-10', 'end_date' => '2026-10-01']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertInputError($html, '工事完了日には工事開始日以降（同日を含む）の日付を指定してください。');
        $state = $this->driveAlpine($html, 'investmentEditForm', $this->xData($html, 'investmentEditForm'), '')['state'];
        $this->assertSame(
            [['interior', '150000'], ['design', '30000']],
            array_map(fn (array $row) => [$row['cost_item'], (string) $row['amount']], $state['details']),
            '入力エラーで戻ると、直した明細の行が保存済みの内容に戻る'
        );
        $this->assertSame(100000, $investment->fresh()->total_amount);
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
