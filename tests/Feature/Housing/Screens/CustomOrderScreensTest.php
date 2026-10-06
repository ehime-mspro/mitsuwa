<?php

namespace Tests\Feature\Housing\Screens;

use App\Enums\UserRole;
use App\Models\HsCustomOrder;
use App\Models\HsCustomOrderFile;
use Illuminate\Support\Facades\Storage;

/**
 * 注文住宅の登録（買主の選択・土地の紐づけ先は画面の JS）・編集・ファイルの削除を、描いた画面から送る往復で見る。
 *
 * ⚠ 分譲地・区画・仕入れ案件の選択欄は `<template x-for>` の選択肢（Top trap #3）。x-model の値が選択肢に無いと、
 *   ブラウザでは何も選ばれず空で送られる（H1: 編集画面から保存すると紐付けが消えていた）。browserForm() はその場合に落とす。
 * ⚠ `<template x-for>` の選択肢に `:selected` が無いと、選択肢があとから描かれたときにブラウザは x-model の値を選び直さない。
 *   これは PHP から動かす JS では見えないので、`:selected` の有無を構造で固定する（実際の動きは実ブラウザで確かめる）。
 */
class CustomOrderScreensTest extends HousingScreenTestCase
{
    public function test_an_order_on_a_project_lot_is_registered_from_the_screen(): void
    {
        $project = $this->project();
        $lot = $this->lot($project, 2);
        $buyer = $this->buyer();
        $url = route('housing.custom-orders.create');
        $responses = [$this->apiResponse(route('api.housing.project-lots', ['project_id' => $project->id]))];

        $form = $this->composedForm($this->htmlOf($url), route('housing.custom-orders.store'), 'customOrderForm', null, ['buyerSelect' => $this->chooseBuyer($buyer)],
            'data.landSourceType = "project_lot"; data.onSourceTypeChange(); data.selectedProjectId = "' . $project->id . '"; data.onProjectChange();
             setImmediate(function () { data.selectedLotId = "' . $lot->id . '"; data.onLotChange(); });', $responses);
        $this->assertSame([(string) $buyer->id, '山田 太郎', (string) $lot->id, '愛媛県松山市平井町1', '10.00'],
            [$form['fields']['customer_id'], $form['fields']['customer_name'], $form['fields']['re_project_lot_id'], $form['fields']['address'], $form['fields']['tax_rate']]);
        $form = $this->fill($form, ['order_name' => '山田邸 新築工事', 'building_contract_price' => '25000000']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を登録しました。');
        $order = HsCustomOrder::firstOrFail();
        $this->assertSame([$buyer->id, $lot->id, 25000000], [$order->customer_id, $order->re_project_lot_id, $order->building_contract_price]);
    }

    public function test_the_selected_lot_survives_an_input_error_on_the_registration_screen(): void
    {
        $project = $this->project();
        $lot = $this->lot($project, 2);
        $this->lot($project, 4);
        $buyer = $this->buyer();
        $url = route('housing.custom-orders.create');
        $responses = [$this->apiResponse(route('api.housing.project-lots', ['project_id' => $project->id]))];
        $form = $this->composedForm($this->htmlOf($url), route('housing.custom-orders.store'), 'customOrderForm', null, ['buyerSelect' => $this->chooseBuyer($buyer)],
            'data.landSourceType = "project_lot"; data.onSourceTypeChange(); data.selectedProjectId = "' . $project->id . '"; data.onProjectChange();
             setImmediate(function () { data.selectedLotId = "' . $lot->id . '"; data.onLotChange(); });', $responses);

        // 案件名を書き忘れて送る
        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '案件名']));
        $again = $this->composedForm($html, route('housing.custom-orders.store'), 'customOrderForm', null, ['buyerSelect' => '']);
        $this->assertSame([(string) $lot->id, (string) $buyer->id], [$again['fields']['re_project_lot_id'] ?? null, $again['fields']['customer_id']], '入力エラーで戻ると選んでいた区画が消える');
        $again = $this->fill($again, ['order_name' => '山田邸']);

        $html = $this->landed($this->submit($again, $url));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を登録しました。');
        $this->assertSame($lot->id, HsCustomOrder::firstOrFail()->re_project_lot_id);
    }

    public function test_saving_the_edit_screen_keeps_the_lot(): void
    {
        $project = $this->project();
        $lot = $this->lot($project, 2);
        $order = $this->customOrder(['land_source_type' => 'project_lot', 're_project_lot_id' => $lot->id]);
        $url = route('housing.custom-orders.edit', $order);

        // 区画には触らず、ほかの欄だけ直して保存する
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('housing.custom-orders.update', $order) . '"', 'customOrderForm');
        $this->assertSame([], $form['run']['requests'], '編集画面が区画の一覧を API から取り直している（サーバが描いた一覧を使う）');
        $this->assertSame(['PUT', 'project_lot', (string) $lot->id], [$form['fields']['_method'], $form['fields']['land_source_type'], $form['fields']['re_project_lot_id'] ?? null],
            '編集画面で今の区画が選ばれていない');
        $form = $this->fill($form, ['notes' => '外構は別途']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を更新しました。');
        $order->refresh();
        $this->assertSame([$lot->id, '外構は別途', $this->user->id], [$order->re_project_lot_id, $order->notes, $order->updated_by], '保存すると区画の紐付けが消える');
    }

    public function test_saving_the_edit_screen_keeps_the_procurement(): void
    {
        $procurement = $this->procurement();
        $order = $this->customOrder(['land_source_type' => 'procurement', 're_procurement_id' => $procurement->id]);
        $url = route('housing.custom-orders.edit', $order);

        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('housing.custom-orders.update', $order) . '"', 'customOrderForm');
        $this->assertSame((string) $procurement->id, $form['fields']['re_procurement_id'] ?? null, '編集画面で今の仕入れ案件が選ばれていない');

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を更新しました。');
        $this->assertSame($procurement->id, $order->fresh()->re_procurement_id);
    }

    public function test_a_procurement_order_without_a_procurement_is_refused(): void
    {
        $order = $this->customOrder(['land_source_type' => 'customer_land']);
        $url = route('housing.custom-orders.edit', $order);
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('housing.custom-orders.update', $order) . '"', 'customOrderForm',
            'data.landSourceType = "procurement"; data.onSourceTypeChange();');

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, '土地種別が仕入れ案件のときは、仕入れ案件を選んでください。');
        $this->assertSame('customer_land', $order->fresh()->land_source_type->value);
    }

    /**
     * 分譲地・区画・仕入れ案件の選択欄（`<template x-for>` の選択肢を x-model で選ぶ）は、選択肢に `:selected` を持つ
     * （選択肢があとから描かれてもブラウザが今の値を選ぶ。Top trap #3 / Bug #87）。PHP の JS の実駆動では見えないので構造で見る。
     */
    public function test_every_looped_option_of_a_modelled_select_is_selected_by_the_model(): void
    {
        $checked = 0;
        foreach (['housing/properties/_form.blade.php', 'housing/custom-orders/_form.blade.php'] as $view) {
            $source = file_get_contents(resource_path('views/' . $view));
            preg_match_all('/<select\b[^>]*\bx-model="([^"]+)"[^>]*>(.*?)<\/select>/s', $source, $selects, PREG_SET_ORDER);
            foreach ($selects as [, $model, $body]) {
                if (! str_contains($body, '<template x-for')) {
                    continue;
                }
                $checked++;
                $this->assertSame(1, preg_match('/<option\b[^>]*:selected="String\([\w.]+\) === String\(' . preg_quote($model, '/') . '\)"/', $body),
                    "{$view} の x-model=\"{$model}\" の選択肢に :selected が無い");
            }
        }
        $this->assertSame(6, $checked, '分譲地・区画・仕入れ案件の選択欄（2 画面 × 3）を拾えていない');
    }

    // ============================================================
    // ファイルの削除・権限の無い操作のボタン（H7）
    // ============================================================

    public function test_a_file_is_deleted_from_the_detail_screen(): void
    {
        Storage::fake('public');
        $order = $this->customOrder();
        Storage::disk('public')->put('custom-order-files/' . $order->id . '/a.pdf', '%PDF-1.4');
        $file = HsCustomOrderFile::create([
            'custom_order_id' => $order->id, 'category' => 'estimate', 'file_name' => '見積書.pdf', 'file_path' => 'custom-order-files/' . $order->id . '/a.pdf',
            'file_size' => 8, 'mime_type' => 'application/pdf', 'uploaded_by' => $this->user->id,
        ]);
        $html = $this->htmlOf(route('housing.custom-orders.show', $order));
        $steps = 'data.deleteFile(data.files.estimate[0].id, "estimate");';

        $run = $this->driveAlpine($html, 'customOrderFileManager', 'customOrderFileManager()', $steps);
        $this->assertSame(['このファイルを削除しますか？'], $run['confirms']);
        $this->assertSame(['DELETE', route('housing.custom-orders.files.destroy', [$order, $file])], [$run['requests'][0]['method'], $run['requests'][0]['url']]);
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertOk();

        $this->assertNull($file->fresh());
        Storage::disk('public')->assertMissing('custom-order-files/' . $order->id . '/a.pdf');
        $after = $this->driveAlpine($html, 'customOrderFileManager', 'customOrderFileManager()', $steps, [$this->asFetchResponse($response)], true, ['files.estimate.length', 'uploadMessage']);
        $this->assertSame([0, '削除しました。'], $after['evaluated']);
    }

    private function detailButtons(string $html, HsCustomOrder $order): array
    {
        return [
            'edit'        => str_contains($html, 'href="' . route('housing.custom-orders.edit', $order) . '"'),
            'destroy'     => str_contains($html, 'action="' . route('housing.custom-orders.destroy', $order) . '"'),
            'upload'      => str_contains($html, '@change="uploadFile($event'),
            'file_delete' => str_contains($html, '@click="deleteFile('),
        ];
    }

    public function test_the_detail_screen_shows_only_the_buttons_the_role_can_use(): void
    {
        $order = $this->customOrder();
        $url = route('housing.custom-orders.show', $order);

        $this->assertSame(['edit' => true, 'destroy' => true, 'upload' => true, 'file_delete' => true], $this->detailButtons($this->htmlOf($url), $order), '経営層');

        $this->user = $this->member(UserRole::Manager, '管理 次郎');
        $this->assertSame(['edit' => true, 'destroy' => false, 'upload' => true, 'file_delete' => false], $this->detailButtons($this->htmlOf($url), $order), '管理者');

        $this->user = $this->member(UserRole::Staff, '一般 三郎');
        $this->assertSame(['edit' => false, 'destroy' => false, 'upload' => false, 'file_delete' => false], $this->detailButtons($this->htmlOf($url), $order), '一般担当');
    }
}
