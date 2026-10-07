<?php

namespace Tests\Feature\Mansion\Screens;

use App\Models\MsContract;
use App\Models\MsContractRevision;
use App\Models\MsParkingContract;
use App\Models\MsRoom;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 賃貸マンションの部屋契約の一覧・登録（部屋・駐車場は画面の JS が API から取る）・編集・賃料改定・解約を、描いた画面から送る往復で見る。
 *
 * ⚠ 登録画面の部屋の選択肢は `<template x-for>`（Top trap #3）。browserForm() は x-model の値をそのまま選ぶので、
 *   `:selected` が無くても PHP のテストでは選ばれて見える（Bug #87 / #93 の死角）。構造のテストで `:selected` を固定し、実ブラウザで確かめる。
 * ⚠ 改定・解約の画面は外側のコンポーネント（rentRevise / terminateContract）の中に日付ピッカー（datePicker）が入れ子になっている。
 *   composedForm() で組む。
 */
class ContractScreensTest extends MansionScreenTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function storeNeedle(): string
    {
        return 'action="' . route('mansion.contracts.store') . '"';
    }

    /** 登録画面で物件を選び、API から届いた部屋（と駐車場）から選ぶ */
    private function createForm(string $html, MsRoom $room, array $parkingIds = []): array
    {
        $responses = [
            $this->apiResponse(route('api.mansion.vacant-rooms', $this->building)),
            $this->apiResponse(route('api.mansion.vacant-parkings', $this->building)),
        ];
        $parkings = json_encode(array_map('strval', $parkingIds));

        return $this->browserForm($html, $this->storeNeedle(), 'contractForm',
            'data.propertyId = "' . $this->building->id . '"; data.loadVacancies();
             setImmediate(function () { data.selectedRoomId = "' . $room->id . '"; data.onRoomSelected(); data.selectedParkingIds = ' . $parkings . '; });', $responses);
    }

    public function test_the_list_filters_by_status_and_fiscal_year(): void
    {
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->contract($this->room('101'), $this->tenant('佐藤 花子'), ['contract_date' => '2026-06-01']);
        $this->contract($this->room('102'), $this->tenant('鈴木 一郎'), ['contract_date' => '2025-06-01', 'status' => 'terminated', 'move_out_date' => '2026-03-31']);
        $url = route('mansion.contracts.index');
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . $url . '"'), ['status' => 'terminated', 'fiscal_year' => '2025']);

        $html = $this->submit($form, $url)->assertOk()->getContent();

        $this->assertStringContainsString('鈴木 一郎', $html);
        $this->assertStringNotContainsString('佐藤 花子', $html);
    }

    public function test_a_contract_is_registered_from_the_screen_with_a_parking(): void
    {
        $room = $this->room('101');
        $this->room('102', 'occupied');
        $parking = $this->parking('A-1');
        $this->parking('A-2', 'occupied');
        $tenant = $this->tenant('佐藤 花子');
        $url = route('mansion.contracts.create');

        $form = $this->createForm($this->htmlOf($url), $room, [$parking->id]);
        $run = $form['run'];
        $this->assertSame([route('api.mansion.vacant-rooms', $this->building), route('api.mansion.vacant-parkings', $this->building)], array_column($run['requests'], 'url'));
        foreach ($run['requests'] as $request) {
            $this->assertSame('XMLHttpRequest', $request['headers']['X-Requested-With'] ?? null, 'API を叩く fetch に X-Requested-With が無い（Top trap #9）');
        }
        $this->assertSame([$room->id], array_column($run['state']['rooms'], 'id'), '入居中の部屋が選択肢に出ている');
        $this->assertSame([$parking->id], array_column($run['state']['parkings'], 'id'), '使用中の駐車場が選択肢に出ている');
        $this->assertSame([(string) $room->id, [(string) $parking->id]], [$form['fields']['room_id'], $form['fields']['parking_ids']]);
        $form = $this->fill($form, ['tenant_id' => (string) $tenant->id, 'contract_date' => '2026-10-01', 'move_in_date' => '2026-10-15', 'rent' => '70000', 'deposit' => '140000']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '部屋契約を登録しました');
        $contract = MsContract::firstOrFail();
        $this->assertSame([$room->id, $tenant->id, 'active', 70000, $this->user->id], [$contract->room_id, $contract->tenant_id, $contract->status->value, $contract->rent, $contract->created_by]);
        $this->assertSame('occupied', $room->fresh()->status->value);
        $pc = MsParkingContract::firstOrFail();
        $this->assertSame([$parking->id, $contract->id, '2026-10-15', 5000], [$pc->parking_id, $pc->contract_id, $pc->start_date->format('Y-m-d'), $pc->monthly_fee]);
        $this->assertSame('occupied', $parking->fresh()->status->value);
    }

    public function test_sending_the_registration_twice_does_not_make_two_contracts_for_the_room(): void
    {
        $room = $this->room('101');
        $parking = $this->parking('A-1');
        $tenant = $this->tenant();
        $url = route('mansion.contracts.create');
        $form = $this->fill($this->createForm($this->htmlOf($url), $room, [$parking->id]), ['tenant_id' => (string) $tenant->id]);

        $this->landed($this->submit($form, $url));
        // 同じ画面からもう一度送る（ダブルクリック・戻って押し直し）
        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, '選んだ部屋は空室・申込み中ではありません（すでに契約されている可能性があります）。');
        $this->assertSame(1, MsContract::count());
        $this->assertSame(1, MsParkingContract::count());
    }

    public function test_a_room_taken_between_the_check_and_the_save_is_refused(): void
    {
        $room = $this->room('101');
        $tenant = $this->tenant();
        $url = route('mansion.contracts.create');
        $form = $this->fill($this->createForm($this->htmlOf($url), $room), ['tenant_id' => (string) $tenant->id]);
        // 入力チェック（部屋が空室か）を通ったあと、保存の前に、ほかの送信が部屋を入居中にした（ほぼ同時の 2 回）。
        // ⚠ 入力チェックの問い合わせの直後に部屋を書き換えて再現する。ロックの中で確かめ直していなければ 2 件目の契約ができる
        $taken = false;
        DB::listen(function ($query) use ($room, &$taken) {
            if (! $taken && str_contains($query->sql, 'count(*)') && str_contains($query->sql, '"ms_rooms"')) {
                $taken = true;
                DB::table('ms_rooms')->where('id', $room->id)->update(['status' => 'occupied']);
            }
        });

        $html = $this->landed($this->submit($form, $url));

        $this->assertTrue($taken, '部屋の入力チェックの問い合わせを捕まえられなかった（前提が崩れた）');
        $this->assertErrorItem($html, '選んだ部屋は空室・申込み中ではありません（すでに契約されている可能性があります）。');
        $this->assertSame(0, MsContract::count());
    }

    public function test_a_parking_that_is_used_or_in_another_property_is_refused(): void
    {
        $room = $this->room('101');
        $other = $this->property(['property_code' => 'MS-002', 'property_name' => '別の物件']);
        $elsewhere = $this->parking('Z-1', 'vacant', ['property_id' => $other->id]);
        $tenant = $this->tenant();
        $url = route('mansion.contracts.create');
        $form = $this->fill($this->createForm($this->htmlOf($url), $room), ['tenant_id' => (string) $tenant->id]);
        // 手で組んだ送信: 別の物件の駐車場を足す
        $form['fields']['parking_ids'] = [(string) $elsewhere->id];

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, '選んだ駐車場は空きではないか、部屋と別の物件の駐車場です。');
        $this->assertSame(0, MsContract::count());
        $this->assertSame('vacant', $room->fresh()->status->value);
    }

    public function test_a_contract_without_a_room_is_refused_with_a_japanese_message(): void
    {
        $url = route('mansion.contracts.create');
        $form = $this->browserForm($this->htmlOf($url), $this->storeNeedle(), 'contractForm');

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '部屋']));
        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '入居者']));
    }

    public function test_the_selected_room_survives_an_input_error_on_the_registration_screen(): void
    {
        $room = $this->room('101');
        $this->room('102');
        $tenant = $this->tenant();
        $url = route('mansion.contracts.create');
        // 入居者を選び忘れて送る
        $html = $this->landed($this->submit($this->createForm($this->htmlOf($url), $room), $url));
        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '入居者']));

        // 戻った画面は物件を覚えていて、部屋の一覧を取り直す。部屋は選び直さない
        $again = $this->browserForm($html, $this->storeNeedle(), 'contractForm', '', [
            $this->apiResponse(route('api.mansion.vacant-rooms', $this->building)),
            $this->apiResponse(route('api.mansion.vacant-parkings', $this->building)),
        ]);
        $this->assertSame((string) $room->id, $again['fields']['room_id'] ?? null, '入力エラーで戻ると選んでいた部屋が消える');
        $again = $this->fill($again, ['tenant_id' => (string) $tenant->id]);

        $html = $this->landed($this->submit($again, $url));

        $this->assertFlash($html, 'success', '部屋契約を登録しました');
        $this->assertSame($room->id, MsContract::firstOrFail()->room_id);
    }

    public function test_every_looped_option_of_a_modelled_select_is_selected_by_the_model(): void
    {
        $checked = 0;
        foreach (['mansion/contracts/create.blade.php', 'mansion/parking-contracts/create.blade.php'] as $view) {
            $source = file_get_contents(resource_path('views/' . $view));
            preg_match_all('/<select\b[^>]*\bx-model="([^"]+)"[^>]*>(.*?)<\/select>/s', $source, $selects, PREG_SET_ORDER);
            foreach ($selects as [, $model, $body]) {
                if (! str_contains($body, '<template x-for')) {
                    continue;
                }
                $checked++;
                $this->assertSame(1, preg_match('/<option\b[^>]*:selected="String\([\w.]+\) === String\(' . preg_quote($model, '/') . '\)"/', $body),
                    "{$view} の x-model=\"{$model}\" の選択肢に :selected が無い（入力エラーで戻ると選択が消える。Top trap #3）");
            }
        }
        $this->assertSame(2, $checked, '部屋契約の部屋・駐車場契約の駐車場の選択欄を拾えていない');
    }

    public function test_the_registration_locks_the_room_and_parkings_before_checking_them(): void
    {
        // ほぼ同時の 2 回（入力チェックをどちらも通る）は順に流すテストでは起こせないので、ロックの形を固定する
        $source = file_get_contents(app_path('Http/Controllers/Mansion/ContractController.php'));
        preg_match('/public function store\(.*?\n    }\n/s', $source, $m);
        $this->assertNotEmpty($m, 'store を読めない');
        $this->assertSame(2, substr_count($m[0], '->lockForUpdate()'), '部屋と駐車場の行をロックしていない');
    }

    public function test_the_edit_screen_saves_what_it_shows(): void
    {
        $tenant = $this->tenant('佐藤 花子');
        $contract = $this->contract($this->room('101'), $tenant, ['key_money' => 70000]);
        $url = route('mansion.contracts.edit', $contract);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('mansion.contracts.update', $contract) . '"');
        $this->assertSame([(string) $tenant->id, '2025-03-20', '2025-04-01', '70000', '70000'],
            [$form['fields']['tenant_id'], $form['fields']['contract_date'], $form['fields']['move_in_date'], $form['fields']['rent'], $form['fields']['key_money']]);
        $form = $this->fill($form, ['memo' => '更新の案内済み', 'deposit' => '150000']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約を更新しました');
        $fresh = $contract->fresh();
        $this->assertSame(['更新の案内済み', 150000, 70000, $this->user->id], [$fresh->memo, $fresh->deposit, $fresh->key_money, $fresh->updated_by]);
    }

    // ============================================================
    // 賃料改定（M5・M14）
    // ============================================================

    private function reviseForm(MsContract $contract, string $steps, string $dateSteps = 'data.selected = new Date(2026, 9, 1);'): array
    {
        $html = $this->htmlOf(route('mansion.contracts.revise.show', $contract));

        return $this->composedForm($html, route('mansion.contracts.revise', $contract), 'rentRevise', null, ['datePicker' => $dateSteps], $steps);
    }

    public function test_the_rent_is_revised_from_the_screen(): void
    {
        $contract = $this->contract($this->room('101'), $this->tenant());
        $url = route('mansion.contracts.revise.show', $contract);
        $form = $this->reviseForm($contract, 'data.newRent = 72000; data.reason = "近隣相場に合わせる";');
        $this->assertSame(['2026-10-01', '72000', '5000'], [$form['fields']['revision_date'], $form['fields']['new_rent'], $form['fields']['new_common_fee']]);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '賃料を改定しました');
        $revision = MsContractRevision::firstOrFail();
        $this->assertSame(['2026-10-01', 72000, 5000, '近隣相場に合わせる'], [$revision->revision_date->format('Y-m-d'), $revision->new_rent, $revision->new_common_fee, $revision->reason]);
        $this->assertSame([72000, 5000], [$contract->fresh()->rent, $contract->fresh()->common_fee]);
    }

    public function test_an_emptied_fee_is_shown_as_the_current_fee_after_an_input_error(): void
    {
        $contract = $this->contract($this->room('101'), $this->tenant());
        $url = route('mansion.contracts.revise.show', $contract);
        // 共益費の欄を空にし（＝今のまま）、改定日を選ばずに送る
        $form = $this->reviseForm($contract, 'data.newRent = 72000; data.newFee = "";', 'data.selected = null;');

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '改定日']));
        $again = $this->composedForm($html, route('mansion.contracts.revise', $contract), 'rentRevise', null, ['datePicker' => 'data.selected = new Date(2026, 9, 1);']);
        $this->assertSame(['72000', '5000'], [$again['fields']['new_rent'], $again['fields']['new_common_fee']], '空にした共益費が 0 円で描かれている（そのまま送ると 0 円になる）');
    }

    public function test_a_revision_sent_after_the_contract_was_terminated_goes_back_to_the_detail_screen(): void
    {
        $contract = $this->contract($this->room('101'), $this->tenant());
        $url = route('mansion.contracts.revise.show', $contract);
        $form = $this->reviseForm($contract, 'data.newRent = 72000;');
        $contract->update(['status' => 'terminated', 'move_out_date' => '2026-09-30']);

        $response = $this->submit($form, $url);

        $response->assertRedirect(route('mansion.contracts.show', $contract));
        $this->assertFlash($this->landed($response), 'error', 'この契約は解約済みのため、賃料を改定できません。');
        $this->assertSame(0, MsContractRevision::count());
    }

    // ============================================================
    // 解約（M4・M7・M14）
    // ============================================================

    private function terminateForm(string $html, MsContract $contract, string $steps, string $dateSteps = 'data.selected = new Date(2026, 9, 31);'): array
    {
        return $this->composedForm($html, route('mansion.contracts.terminate', $contract), 'terminateContract', null, ['datePicker' => $dateSteps], $steps);
    }

    public function test_unchecked_parkings_and_added_deductions_survive_an_input_error_on_the_termination_screen(): void
    {
        $tenant = $this->tenant();
        $contract = $this->contract($this->room('101'), $tenant);
        $keepA = $this->parkingContract($this->parking('A-1'), $tenant, $contract);
        $keepB = $this->parkingContract($this->parking('A-2'), $tenant, $contract);
        $url = route('mansion.contracts.terminate.show', $contract);
        // 駐車場はどちらも残す（チェックを全部外す＝送る項目ごと無くなる）・差引の行を 1 行足す・原状回復費をマイナスにして送る
        $form = $this->terminateForm($this->htmlOf($url), $contract,
            'data.linkParkings[' . $keepA->id . '] = false; data.linkParkings[' . $keepB->id . '] = false;
             data.otherDeductions.push({ name: "鍵交換費", amount: 15000 }); data.restorationCost = -1;');
        $this->assertArrayNotHasKey('terminate_parkings', $form['fields']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.min.numeric', ['attribute' => '原状回復費', 'min' => 0]));
        $again = $this->terminateForm($html, $contract, 'data.restorationCost = 30000;', '');
        $this->assertSame([], $again['fields']['terminate_parkings'] ?? [], '入力エラーで戻ると、外した駐車場のチェックが付き直る（残すつもりの駐車場契約まで解約される）');
        $this->assertSame([['鍵交換費'], ['15000']], [$again['fields']['other_deduction_name'] ?? [], $again['fields']['other_deduction_amount'] ?? []], '入力エラーで戻ると、足した差引の行が消える');

        $html = $this->landed($this->submit($again, $url));

        $this->assertFlash($html, 'success', '契約を解約しました');
        $this->assertSame(['active', 'active'], [$keepA->fresh()->status->value, $keepB->fresh()->status->value]);
        $this->assertSame([['鍵交換費', 15000]], $contract->deductions()->get()->map(fn ($d) => [$d->name, $d->amount])->all());
        $this->assertSame([30000, '2026-10-31'], [$contract->fresh()->restoration_cost, $contract->fresh()->move_out_date->format('Y-m-d')]);
    }

    public function test_a_partly_unchecked_parking_stays_unchecked_after_an_input_error(): void
    {
        $tenant = $this->tenant();
        $contract = $this->contract($this->room('101'), $tenant);
        $keep = $this->parkingContract($this->parking('A-1'), $tenant, $contract);
        $end = $this->parkingContract($this->parking('A-2'), $tenant, $contract);
        $url = route('mansion.contracts.terminate.show', $contract);
        $form = $this->terminateForm($this->htmlOf($url), $contract, 'data.linkParkings[' . $keep->id . '] = false; data.restorationCost = -1;');

        $html = $this->landed($this->submit($form, $url));

        $again = $this->terminateForm($html, $contract, '', '');
        $this->assertSame([(string) $end->id], $again['fields']['terminate_parkings'] ?? []);
    }

    public function test_a_move_out_date_before_the_move_in_date_is_refused(): void
    {
        $contract = $this->contract($this->room('101'), $this->tenant());
        $url = route('mansion.contracts.terminate.show', $contract);
        $form = $this->terminateForm($this->htmlOf($url), $contract, '', 'data.selected = new Date(2025, 2, 31);');

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, '退去日は入居日（2025/04/01）以降の日付を指定してください。');
        $this->assertSame('active', $contract->fresh()->status->value);

        // 入居日と同じ日は通す
        $form = $this->terminateForm($this->htmlOf($url), $contract, '', 'data.selected = new Date(2025, 3, 1);');
        $this->assertFlash($this->landed($this->submit($form, $url)), 'success', '契約を解約しました');
    }

    public function test_the_move_out_date_is_compared_with_the_contract_date_when_there_is_no_move_in_date(): void
    {
        $contract = $this->contract($this->room('101'), $this->tenant(), ['move_in_date' => null]);
        $url = route('mansion.contracts.terminate.show', $contract);
        $form = $this->terminateForm($this->htmlOf($url), $contract, '', 'data.selected = new Date(2025, 2, 19);');

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, '退去日は契約日（2025/03/20）以降の日付を指定してください。');
        $this->assertSame('active', $contract->fresh()->status->value);
    }

    public function test_sending_the_termination_twice_goes_back_to_the_detail_screen(): void
    {
        $contract = $this->contract($this->room('101'), $this->tenant());
        $url = route('mansion.contracts.terminate.show', $contract);
        $form = $this->terminateForm($this->htmlOf($url), $contract, '');

        $this->assertFlash($this->landed($this->submit($form, $url)), 'success', '契約を解約しました');
        $response = $this->submit($form, $url);

        $response->assertRedirect(route('mansion.contracts.show', $contract));
        $this->assertFlash($this->landed($response), 'error', 'この契約はすでに解約済みです。');
    }
}
