<?php

namespace Tests\Feature\Housing\Screens;

use App\Enums\HousingPropertyStatus;
use App\Enums\UserRole;
use App\Models\HsContract;
use App\Models\HsProperty;
use App\Models\HsPropertyFile;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use Illuminate\Support\Facades\Storage;

/**
 * 建売物件の登録（土地の紐づけ先は画面の JS が API から取る）・編集・一覧のステータスの小窓・ファイルの削除・
 * 契約の登録画面を、描いた画面から送る往復で見る。
 *
 * ⚠ 登録画面の分譲地・区画・仕入れ案件の選択欄は `<template x-for>` の選択肢（Top trap #3）。browserForm() は
 *   x-model の値が選択肢に無ければ落とす（ブラウザでは何も選ばれず、送られない）。
 */
class PropertyScreensTest extends HousingScreenTestCase
{
    private function storeNeedle(): string
    {
        return 'action="' . route('housing.properties.store') . '"';
    }

    /** 登録画面で分譲地区画を選ぶ（区画の一覧は JS が API から取る。その応答をそのまま返す） */
    private function projectLotForm(string $html, ReProject $project, ReProjectLot $lot): array
    {
        $responses = [$this->apiResponse(route('api.housing.project-lots', ['project_id' => $project->id, 'exclude_hs' => 1]))];

        return $this->browserForm($html, $this->storeNeedle(), 'housingPropertyForm',
            'data.landSourceType = "project_lot"; data.onSourceTypeChange(); data.selectedProjectId = "' . $project->id . '"; data.onProjectChange();
             setImmediate(function () { data.selectedLotId = "' . $lot->id . '"; data.onLotChange(); });', $responses);
    }

    public function test_a_property_on_a_project_lot_is_registered_from_the_screen(): void
    {
        $project = $this->project();
        $lot = $this->lot($project, 3);
        $used = $this->lot($project, 4);
        $this->property(['land_source_type' => 'project_lot', 're_project_lot_id' => $used->id]);
        $url = route('housing.properties.create');

        $form = $this->projectLotForm($this->htmlOf($url), $project, $lot);
        $run = $form['run'];
        $this->assertSame(route('api.housing.project-lots', ['project_id' => $project->id, 'exclude_hs' => 1]), $run['requests'][0]['url']);
        $this->assertSame([$lot->id], array_column($run['state']['lots'], 'id'), '建売に使った区画が選択肢に残っている');
        $this->assertSame(['project_lot', (string) $lot->id, '愛媛県松山市平井町1', '200'],
            [$form['fields']['land_source_type'], $form['fields']['re_project_lot_id'], $form['fields']['address'], $form['fields']['land_area_sqm']],
            '区画を選んでも紐づけ・所在地・面積が入らない');
        $form = $this->fill($form, ['property_name' => '平井 建売 2', 'building_cost' => '18000000']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '建売物件「HS-002」を登録しました。');
        $property = HsProperty::where('property_code', 'HS-002')->firstOrFail();
        $this->assertSame([$lot->id, null, '愛媛県松山市平井町1', 18000000, $this->user->id],
            [$property->re_project_lot_id, $property->re_procurement_id, $property->address, $property->building_cost, $property->created_by]);
    }

    public function test_a_property_on_a_procurement_is_registered_from_the_screen(): void
    {
        $procurement = $this->procurement();
        $url = route('housing.properties.create');
        $responses = [$this->apiResponse(route('api.housing.procurement-info', $procurement))];

        $form = $this->browserForm($this->htmlOf($url), $this->storeNeedle(), 'housingPropertyForm',
            'data.landSourceType = "procurement"; data.onSourceTypeChange(); data.selectedProcurementId = "' . $procurement->id . '"; data.onProcurementChange();', $responses);
        $this->assertSame([(string) $procurement->id, '愛媛県松山市勝山町1-1', '150'],
            [$form['fields']['re_procurement_id'], $form['fields']['address'], $form['fields']['land_area_sqm']], '仕入れ案件を選んでも所在地・面積が入らない');
        $form = $this->fill($form, ['property_name' => '勝山 建売']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '建売物件「HS-001」を登録しました。');
        $property = HsProperty::firstOrFail();
        $this->assertSame([null, $procurement->id], [$property->re_project_lot_id, $property->re_procurement_id]);
    }

    public function test_the_selected_lot_survives_an_input_error_on_the_registration_screen(): void
    {
        $project = $this->project();
        $lot = $this->lot($project, 3);
        $this->lot($project, 5);
        $url = route('housing.properties.create');
        $form = $this->projectLotForm($this->htmlOf($url), $project, $lot);

        // 物件名を書き忘れて送る
        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '物件名']));
        // 戻った画面をそのまま送り直す（区画は選び直さない）
        $again = $this->browserForm($html, $this->storeNeedle(), 'housingPropertyForm');
        $this->assertSame((string) $lot->id, $again['fields']['re_project_lot_id'] ?? null, '入力エラーで戻ると選んでいた区画が消える');
        $this->assertSame([], $again['run']['requests'], '戻った画面が区画の一覧を API から取り直している（サーバが描いた一覧を使う）');
        $again = $this->fill($again, ['property_name' => '平井 建売 2']);

        $html = $this->landed($this->submit($again, $url));

        $this->assertFlash($html, 'success', '建売物件「HS-001」を登録しました。');
        $this->assertSame($lot->id, HsProperty::firstOrFail()->re_project_lot_id);
    }

    public function test_a_project_lot_property_without_a_lot_is_refused(): void
    {
        $project = $this->project();
        $this->lot($project, 3);
        $url = route('housing.properties.create');
        $form = $this->browserForm($this->htmlOf($url), $this->storeNeedle(), 'housingPropertyForm', 'data.landSourceType = "project_lot"; data.onSourceTypeChange();');
        $form = $this->fill($form, ['property_name' => '区画なし', 'address' => '松山市']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, '土地紐づけ種別が分譲地区画のときは、区画を選んでください。');
        $this->assertSame(0, HsProperty::count());
    }

    public function test_the_edit_screen_saves_what_it_shows(): void
    {
        $project = $this->project();
        $lot = $this->lot($project, 3);
        $property = $this->property(['land_source_type' => 'project_lot', 're_project_lot_id' => $lot->id, 'building_cost' => 15000000, 'land_area_sqm' => 200]);
        $url = route('housing.properties.edit', $property);

        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('housing.properties.update', $property) . '"', 'housingPropertyForm');
        $this->assertSame(['PUT', 'project_lot', (string) $lot->id, '愛媛県松山市平井町1-3', '15000000'],
            [$form['fields']['_method'], $form['fields']['land_source_type'], $form['fields']['re_project_lot_id'], $form['fields']['address'], $form['fields']['building_cost']],
            '編集画面に今の値が入っていない');
        $form = $this->fill($form, ['building_cost' => '16000000']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '建売物件「HS-001」を更新しました。');
        $property->refresh();
        $this->assertSame([16000000, $lot->id, $this->user->id], [$property->building_cost, $property->re_project_lot_id, $property->updated_by]);
    }

    // ============================================================
    // 一覧のステータスの小窓・詳細のファイルの削除（Ajax）
    // ============================================================

    public function test_the_status_is_changed_from_the_list(): void
    {
        $property = $this->property();
        $editor = $this->member(UserRole::Manager, '変更 花子');
        $this->user = $editor;
        $html = $this->htmlOf(route('housing.properties.index'));
        $factory = $this->xData($html, 'housingPropertyStatusCell');
        $steps = 'data.select(data.options.find(function (o) { return o.value === "construction"; }));';

        $run = $this->driveAlpine($html, 'housingPropertyStatusCell', $factory, $steps);
        $this->assertSame(['PATCH', route('housing.properties.update-status', $property)], [$run['requests'][0]['method'], $run['requests'][0]['url']]);
        $response = $this->actingAs($editor)->sendCaptured($run['requests'][0]);
        $response->assertOk();

        $property->refresh();
        $this->assertSame([HousingPropertyStatus::Construction, $editor->id], [$property->status, $property->updated_by], 'ステータスを変えた人が更新者に残っていない');
        $after = $this->driveAlpine($html, 'housingPropertyStatusCell', $factory, $steps, [$this->asFetchResponse($response)], true, ['value', 'label', 'open']);
        $this->assertSame(['construction', HousingPropertyStatus::Construction->label(), false], $after['evaluated']);
    }

    public function test_a_file_is_deleted_from_the_detail_screen(): void
    {
        Storage::fake('public');
        $property = $this->property();
        Storage::disk('public')->put('housing-files/' . $property->id . '/a.pdf', '%PDF-1.4');
        $file = HsPropertyFile::create([
            'property_id' => $property->id, 'category' => 'other', 'file_name' => '図面.pdf', 'file_path' => 'housing-files/' . $property->id . '/a.pdf',
            'file_size' => 8, 'mime_type' => 'application/pdf', 'uploaded_by' => $this->user->id,
        ]);
        $html = $this->htmlOf(route('housing.properties.show', $property));
        $steps = 'data.deleteFile(data.files.other[0].id, "other");';

        $run = $this->driveAlpine($html, 'housingFileManager', 'housingFileManager()', $steps);
        $this->assertSame(['このファイルを削除しますか？'], $run['confirms']);
        $this->assertSame(['DELETE', route('housing.properties.files.destroy', [$property, $file])], [$run['requests'][0]['method'], $run['requests'][0]['url']]);
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertOk();

        $this->assertNull($file->fresh());
        Storage::disk('public')->assertMissing('housing-files/' . $property->id . '/a.pdf');
        $after = $this->driveAlpine($html, 'housingFileManager', 'housingFileManager()', $steps, [$this->asFetchResponse($response)], true, ['files.other.length', 'uploadMessage']);
        $this->assertSame([0, '削除しました。'], $after['evaluated']);
    }

    // ============================================================
    // 権限の無い操作のボタンを出さない（H7）
    // ============================================================

    private function soldPropertyWithFile(): HsProperty
    {
        $property = $this->property();
        $this->contract($property, $this->buyer());
        HsPropertyFile::create([
            'property_id' => $property->id, 'category' => 'other', 'file_name' => '図面.pdf', 'file_path' => 'housing-files/x.pdf',
            'file_size' => 8, 'mime_type' => 'application/pdf', 'uploaded_by' => $this->user->id,
        ]);

        return $property;
    }

    /** 詳細画面の操作のボタン（あるかどうか） */
    private function detailButtons(string $html, HsProperty $property): array
    {
        return [
            'edit'            => str_contains($html, 'href="' . route('housing.properties.edit', $property) . '"'),
            'destroy'         => str_contains($html, 'action="' . route('housing.properties.destroy', $property) . '"'),
            'contract_edit'   => str_contains($html, 'href="' . route('housing.contracts.edit', $property) . '"'),
            'contract_delete' => str_contains($html, 'action="' . route('housing.contracts.destroy', $property) . '"'),
            'upload'          => str_contains($html, '@change="uploadFile($event'),
            'file_delete'     => str_contains($html, '@click="deleteFile('),
        ];
    }

    public function test_the_detail_screen_shows_only_the_buttons_the_role_can_use(): void
    {
        $property = $this->soldPropertyWithFile();
        $url = route('housing.properties.show', $property);

        $this->assertSame(array_fill_keys(['edit', 'destroy', 'contract_edit', 'contract_delete', 'upload', 'file_delete'], true),
            $this->detailButtons($this->htmlOf($url), $property), '経営層');

        $this->user = $this->member(UserRole::Manager, '管理 次郎');
        $this->assertSame(['edit' => true, 'destroy' => false, 'contract_edit' => true, 'contract_delete' => false, 'upload' => true, 'file_delete' => false],
            $this->detailButtons($this->htmlOf($url), $property), '管理者');

        $this->user = $this->member(UserRole::Staff, '一般 三郎');
        $this->assertSame(array_fill_keys(['edit', 'destroy', 'contract_edit', 'contract_delete', 'upload', 'file_delete'], false),
            $this->detailButtons($this->htmlOf($url), $property), '一般担当');
    }

    // ============================================================
    // 建売の契約の登録（物件を選ぶ画面から）
    // ============================================================

    public function test_a_contract_is_registered_from_the_property_selection_screen(): void
    {
        $project = $this->project();
        $lot = $this->lot($project, 3, 9000000);
        $property = $this->property(['land_source_type' => 'project_lot', 're_project_lot_id' => $lot->id, 'target_selling_price_building' => 21000000]);
        $buyer = $this->buyer();

        $select = $this->htmlOf(route('housing.contracts.select-building-property'));
        $this->assertTrue(str_contains($select, 'href="' . route('housing.contracts.create', $property) . '"'), '物件を選ぶ画面に契約の登録への入口が無い');
        $url = route('housing.contracts.create', $property);
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('housing.contracts.store', $property) . '"', 'buyerSelect',
            'data.$refs.sel.value = "' . $buyer->id . '"; data.onSelect();');
        $this->assertSame(['9000000', '21000000', (string) $buyer->id, '山田 太郎'],
            [$form['fields']['selling_price_land'], $form['fields']['selling_price_building'], $form['fields']['customer_id'], $form['fields']['customer_name']],
            '契約の登録画面に既定の販売価格・選んだ買主が入っていない');
        $form = $this->fill($form, ['contract_date' => '2026-10-01']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '契約を登録しました。物件のステータスが「成約」に更新されました。');
        $contract = HsContract::where('property_id', $property->id)->firstOrFail();
        $this->assertSame([$buyer->id, 9000000, 21000000], [$contract->customer_id, $contract->selling_price_land, $contract->selling_price_building]);
        $this->assertSame('sold', $lot->fresh()->status->value);
    }

    // ============================================================
    // 手で組んだ URL（H8）
    // ============================================================

    public function test_the_lot_api_returns_nothing_for_a_project_id_that_is_not_a_number(): void
    {
        $project = $this->project();
        $this->lot($project, 1);

        $this->actingAs($this->user)->getJson(route('api.housing.project-lots') . '?project_id[]=' . $project->id)
            ->assertOk()->assertExactJson([]);
    }
}
