<?php

namespace Tests\Feature\Mansion\Screens;

use App\Models\MsParking;
use App\Models\MsParkingContract;
use App\Models\MsParkingContractRevision;

/**
 * 賃貸マンションの駐車場契約の一覧・登録（駐車場は画面の JS が API から取る）・詳細・編集・料金改定・解約を、描いた画面から送る往復で見る。
 * 部屋契約と同じ死角（`<template x-for>` の選択肢の `:selected`）は ContractScreensTest の構造のテストが 2 画面とも見る。
 */
class ParkingContractScreensTest extends MansionScreenTestCase
{
    private function storeNeedle(): string
    {
        return 'action="' . route('mansion.parking-contracts.store') . '"';
    }

    private function createForm(string $html, MsParking $parking): array
    {
        return $this->browserForm($html, $this->storeNeedle(), 'parkingContractForm',
            'data.propertyId = "' . $this->building->id . '"; data.loadVacantParkings();
             setImmediate(function () { data.selectedParkingId = "' . $parking->id . '"; data.onParkingSelected(); });',
            [$this->apiResponse(route('api.mansion.vacant-parkings', $this->building))]);
    }

    public function test_the_list_filters_standalone_contracts(): void
    {
        $tenant = $this->tenant('佐藤 花子');
        $contract = $this->contract($this->room('101'), $tenant);
        $this->parkingContract($this->parking('A-1'), $tenant, $contract);
        $this->parkingContract($this->parking('B-9'), $this->tenant('鈴木 一郎', 'parking_only'));
        $url = route('mansion.parking-contracts.index');
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . $url . '"'), ['link_type' => 'standalone']);

        $html = $this->submit($form, $url)->assertOk()->getContent();

        $this->assertStringContainsString('鈴木 一郎', $html);
        $this->assertStringNotContainsString('佐藤 花子', $html);
    }

    public function test_a_parking_contract_is_registered_from_the_screen(): void
    {
        $parking = $this->parking('A-1');
        $this->parking('A-2', 'occupied');
        $tenant = $this->tenant('鈴木 一郎', 'parking_only');
        $url = route('mansion.parking-contracts.create');

        $form = $this->createForm($this->htmlOf($url), $parking);
        $this->assertSame([$parking->id], array_column($form['run']['state']['parkings'], 'id'), '使用中の駐車場が選択肢に出ている');
        $this->assertSame('XMLHttpRequest', $form['run']['requests'][0]['headers']['X-Requested-With'] ?? null);
        $form = $this->fill($form, ['tenant_id' => (string) $tenant->id, 'monthly_fee' => '5000', 'contract_date' => '2026-10-01', 'start_date' => '2026-10-05']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '駐車場契約を登録しました');
        $pc = MsParkingContract::firstOrFail();
        $this->assertSame([$parking->id, $tenant->id, null, 'active', 5000, '2026-10-05'],
            [$pc->parking_id, $pc->tenant_id, $pc->contract_id, $pc->status->value, $pc->monthly_fee, $pc->start_date->format('Y-m-d')]);
        $this->assertSame('occupied', $parking->fresh()->status->value);
    }

    public function test_sending_the_registration_twice_does_not_make_two_contracts_for_the_parking(): void
    {
        $parking = $this->parking('A-1');
        $tenant = $this->tenant('鈴木 一郎', 'parking_only');
        $url = route('mansion.parking-contracts.create');
        $form = $this->fill($this->createForm($this->htmlOf($url), $parking), ['tenant_id' => (string) $tenant->id, 'monthly_fee' => '5000']);

        $this->landed($this->submit($form, $url));
        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, '選んだ駐車場は空きではありません（すでに契約されている可能性があります）。');
        $this->assertSame(1, MsParkingContract::count());
    }

    public function test_the_registration_locks_the_parking_before_checking_it(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Mansion/ParkingContractController.php'));
        preg_match('/public function store\(.*?\n    }\n/s', $source, $m);
        $this->assertNotEmpty($m, 'store を読めない');
        $this->assertSame(1, substr_count($m[0], '->lockForUpdate()'), '駐車場の行をロックしていない');
    }

    public function test_the_selected_parking_survives_an_input_error_on_the_registration_screen(): void
    {
        $parking = $this->parking('A-1');
        $this->parking('A-2');
        $tenant = $this->tenant('鈴木 一郎', 'parking_only');
        $url = route('mansion.parking-contracts.create');
        // 利用者を選び忘れて送る（項目名は画面のラベル「利用者」）
        $html = $this->landed($this->submit($this->fill($this->createForm($this->htmlOf($url), $parking), ['monthly_fee' => '5000']), $url));
        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '利用者']));

        $again = $this->browserForm($html, $this->storeNeedle(), 'parkingContractForm', '', [$this->apiResponse(route('api.mansion.vacant-parkings', $this->building))]);
        $this->assertSame((string) $parking->id, $again['fields']['parking_id'] ?? null, '入力エラーで戻ると選んでいた駐車場が消える');
        $again = $this->fill($again, ['tenant_id' => (string) $tenant->id]);

        $this->assertFlash($this->landed($this->submit($again, $url)), 'success', '駐車場契約を登録しました');
        $this->assertSame($parking->id, MsParkingContract::firstOrFail()->parking_id);
    }

    public function test_the_detail_and_edit_screens_save_what_they_show(): void
    {
        $tenant = $this->tenant('鈴木 一郎', 'parking_only');
        $pc = $this->parkingContract($this->parking('A-1'), $tenant, null, ['deposit' => 10000]);
        $this->assertStringContainsString('鈴木 一郎', $this->htmlOf(route('mansion.parking-contracts.show', $pc)));
        $url = route('mansion.parking-contracts.edit', $pc);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('mansion.parking-contracts.update', $pc) . '"');
        $this->assertSame([(string) $tenant->id, '5000', '10000', '2025-04-01'], [$form['fields']['tenant_id'], $form['fields']['monthly_fee'], $form['fields']['deposit'], $form['fields']['start_date']]);
        $form = $this->fill($form, ['memo' => '車庫証明済み']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約を更新しました');
        $this->assertSame(['車庫証明済み', 10000, $this->user->id], [$pc->fresh()->memo, $pc->fresh()->deposit, $pc->fresh()->updated_by]);
    }

    // ============================================================
    // 料金改定（M5・M14）
    // ============================================================

    private function reviseForm(MsParkingContract $pc, string $steps, string $dateSteps = 'data.selected = new Date(2026, 9, 1);', ?string $html = null): array
    {
        $html ??= $this->htmlOf(route('mansion.parking-contracts.revise.show', $pc));

        return $this->composedForm($html, route('mansion.parking-contracts.revise', $pc), 'reviseParkingForm', null, ['datePicker' => $dateSteps], $steps);
    }

    public function test_the_fee_is_revised_from_the_screen(): void
    {
        $pc = $this->parkingContract($this->parking('A-1'), $this->tenant());
        $url = route('mansion.parking-contracts.revise.show', $pc);
        $form = $this->reviseForm($pc, 'data.newFee = 5500; data.reason = "舗装工事のため";');

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '料金を改定しました');
        $revision = MsParkingContractRevision::firstOrFail();
        $this->assertSame(['2026-10-01', 5500, '舗装工事のため'], [$revision->revision_date->format('Y-m-d'), $revision->new_monthly_fee, $revision->reason]);
        $this->assertSame(5500, $pc->fresh()->monthly_fee);
    }

    public function test_an_emptied_fee_is_shown_as_the_current_fee_after_an_input_error(): void
    {
        $pc = $this->parkingContract($this->parking('A-1'), $this->tenant());
        $url = route('mansion.parking-contracts.revise.show', $pc);
        $form = $this->reviseForm($pc, 'data.newFee = "";');

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '新月額料金']));
        $again = $this->reviseForm($pc, '', '', $html);
        $this->assertSame('5000', $again['fields']['new_monthly_fee'], '空にした月額料金が 0 円で描かれている（そのまま送ると 0 円になる）');
    }

    public function test_a_revision_sent_after_the_contract_was_terminated_goes_back_to_the_detail_screen(): void
    {
        $pc = $this->parkingContract($this->parking('A-1'), $this->tenant());
        $url = route('mansion.parking-contracts.revise.show', $pc);
        $form = $this->reviseForm($pc, 'data.newFee = 5500;');
        $pc->update(['status' => 'terminated', 'end_date' => '2026-09-30']);

        $response = $this->submit($form, $url);

        $response->assertRedirect(route('mansion.parking-contracts.show', $pc));
        $this->assertFlash($this->landed($response), 'error', 'この契約は解約済みのため、料金を改定できません。');
        $this->assertSame(0, MsParkingContractRevision::count());
    }

    // ============================================================
    // 解約（M7・M14）
    // ============================================================

    private function terminateForm(MsParkingContract $pc, string $dateSteps): array
    {
        $html = $this->htmlOf(route('mansion.parking-contracts.terminate.show', $pc));

        return $this->composedForm($html, route('mansion.parking-contracts.terminate', $pc), null, null, ['datePicker' => $dateSteps]);
    }

    public function test_a_parking_contract_is_terminated_from_the_screen(): void
    {
        $parking = $this->parking('A-1');
        $pc = $this->parkingContract($parking, $this->tenant());
        $url = route('mansion.parking-contracts.terminate.show', $pc);

        $html = $this->landed($this->submit($this->terminateForm($pc, 'data.selected = new Date(2026, 9, 31);'), $url));

        $this->assertFlash($html, 'success', '駐車場契約を解約しました');
        $this->assertSame(['terminated', '2026-10-31'], [$pc->fresh()->status->value, $pc->fresh()->end_date->format('Y-m-d')]);
        $this->assertSame('vacant', $parking->fresh()->status->value);
    }

    public function test_an_end_date_before_the_start_date_is_refused(): void
    {
        $pc = $this->parkingContract($this->parking('A-1'), $this->tenant());
        $url = route('mansion.parking-contracts.terminate.show', $pc);

        $html = $this->landed($this->submit($this->terminateForm($pc, 'data.selected = new Date(2025, 2, 31);'), $url));

        $this->assertErrorItem($html, '利用終了日は利用開始日（2025/04/01）以降の日付を指定してください。');
        $this->assertSame('active', $pc->fresh()->status->value);
    }

    public function test_sending_the_termination_twice_goes_back_to_the_detail_screen(): void
    {
        $pc = $this->parkingContract($this->parking('A-1'), $this->tenant());
        $url = route('mansion.parking-contracts.terminate.show', $pc);
        $form = $this->terminateForm($pc, 'data.selected = new Date(2026, 9, 31);');

        $this->assertFlash($this->landed($this->submit($form, $url)), 'success', '駐車場契約を解約しました');
        $response = $this->submit($form, $url);

        $response->assertRedirect(route('mansion.parking-contracts.show', $pc));
        $this->assertFlash($this->landed($response), 'error', 'この契約はすでに解約済みです。');
    }
}
