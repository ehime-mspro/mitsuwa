<?php

namespace Tests\Feature\Mansion\Screens;

use App\Models\MsProperty;
use Illuminate\Support\Facades\DB;

/**
 * 賃貸マンションの物件の一覧・登録・詳細・編集・削除を、描いた画面から送る往復で見る。
 *
 * ⚠ 築年月は年月ピッカー（入れ子のコンポーネント monthPicker）が hidden に入れる。composedForm() で組む。
 * ⚠ 削除はテスト用スキーマに外部キーが無いので、「行が残ること」で歯止めを見る（土台の注記）。
 */
class PropertyScreensTest extends MansionScreenTestCase
{
    private function storeAction(): string
    {
        return route('mansion.properties.store');
    }

    private function createForm(string $html, string $monthSteps = ''): array
    {
        return $this->composedForm($html, $this->storeAction(), 'propertyForm', null, ['monthPicker' => $monthSteps]);
    }

    public function test_the_list_filters_by_keyword_and_ownership_from_the_filter_form(): void
    {
        $this->property(['property_code' => 'MS-002', 'property_name' => '湊町ハイツ', 'ownership_type' => 'managed', 'owner_name' => '田中不動産']);
        $url = route('mansion.properties.index');
        $form = $this->parseForm($this->htmlOf($url), 'action="' . $url . '"');
        $form = $this->fill($form, ['keyword' => '湊町', 'ownership_type' => 'managed']);

        $html = $this->submit($form, $url)->assertOk()->getContent();

        $this->assertStringContainsString('湊町ハイツ', $html);
        $this->assertStringNotContainsString('ミツワレジデンス', $html);
    }

    public function test_a_property_is_registered_from_the_screen(): void
    {
        $url = route('mansion.properties.create');
        $form = $this->createForm($this->htmlOf($url), 'data.pickYear(2015); data.pickMonth(2);');
        $this->assertSame(['self_owned', '2015-03'], [$form['fields']['ownership_type'], $form['fields']['built_year_month']]);
        $form = $this->fill($form, ['property_name' => '平和通りコーポ', 'address' => '愛媛県松山市平和通1-1', 'total_units' => '12', 'total_floors' => '4', 'structure' => 'RC造']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '物件を登録しました');
        $property = MsProperty::where('property_code', 'MS-002')->firstOrFail();
        $this->assertSame(['平和通りコーポ', '2015-03', 12, 4, 'RC造', $this->user->id],
            [$property->property_name, $property->built_year_month, $property->total_units, $property->total_floors, $property->structure, $property->created_by]);
    }

    public function test_a_managed_property_needs_an_owner_name(): void
    {
        $url = route('mansion.properties.create');
        $form = $this->createForm($this->htmlOf($url));
        $form = $this->fill($form, ['property_name' => '受託ビル', 'ownership_type' => 'managed', 'owner_name' => '', 'address' => '松山市']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, '所有形態が管理受託のときは、オーナー名を入力してください。');
        $this->assertSame(1, MsProperty::count());
    }

    public function test_a_self_owned_property_does_not_keep_the_hidden_owner_name(): void
    {
        $property = $this->building;
        $property->update(['ownership_type' => 'managed', 'owner_name' => '田中不動産']);
        $url = route('mansion.properties.edit', $property);
        // 管理受託 → 自社所有に切り替える（オーナー名の欄は隠れるだけで、値は送られる）
        $form = $this->composedForm($this->htmlOf($url), route('mansion.properties.update', $property), 'propertyForm', null, ['monthPicker' => ''], 'data.ownershipType = "self_owned";');
        $this->assertSame('田中不動産', $form['fields']['owner_name'], '隠れたオーナー名の欄が送られていない（前提が崩れた）');

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '物件を更新しました');
        $this->assertSame(['self_owned', null], [$property->fresh()->ownership_type->value, $property->fresh()->owner_name]);
    }

    public function test_the_edit_screen_saves_what_it_shows(): void
    {
        $property = $this->building;
        $property->update(['built_year_month' => '2010-04', 'total_units' => 20, 'structure' => 'S造']);
        $url = route('mansion.properties.edit', $property);
        $form = $this->composedForm($this->htmlOf($url), route('mansion.properties.update', $property), 'propertyForm', null, ['monthPicker' => '']);
        $this->assertSame(['2010-04', '20', 'S造'], [$form['fields']['built_year_month'], $form['fields']['total_units'], $form['fields']['structure']]);
        $form = $this->fill($form, ['property_name' => 'ミツワレジデンス本館']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '物件を更新しました');
        $fresh = $property->fresh();
        $this->assertSame(['ミツワレジデンス本館', '2010-04', 20, $this->user->id], [$fresh->property_name, $fresh->built_year_month, $fresh->total_units, $fresh->updated_by]);
    }

    public function test_a_built_year_month_that_is_not_a_year_and_month_is_refused(): void
    {
        $property = $this->building;
        $url = route('mansion.properties.edit', $property);
        foreach (['2020-13', '2020\\', '2020/04'] as $value) {
            $form = $this->composedForm($this->htmlOf($url), route('mansion.properties.update', $property), 'propertyForm', null, ['monthPicker' => '']);
            $form = $this->fill($form, ['built_year_month' => $value]);

            $html = $this->landed($this->submit($form, $url));

            $this->assertErrorItem($html, '築年月は「2015-03」のような年-月の形で指定してください。');
        }
        $this->assertNull($property->fresh()->built_year_month);
    }

    public function test_quotes_and_backslashes_reach_the_form_script_as_they_are(): void
    {
        // 保存済みの値（入力チェックを足す前に入った値）でも、編集画面の JS が止まらない
        $property = $this->building;
        DB::table('ms_properties')->where('id', $property->id)->update(['built_year_month' => "20'0\\"]);
        $url = route('mansion.properties.edit', $property);
        $form = $this->composedForm($this->htmlOf($url), route('mansion.properties.update', $property), 'propertyForm', null, ['monthPicker' => '']);
        $this->assertArrayHasKey('built_year_month', $form['fields']);

        // 入力エラーで戻った所有形態（手で組んだ送信）でも、登録画面の JS が止まらない
        $create = route('mansion.properties.create');
        $html = $this->landed($this->actingAs($this->user)->from($create)->post($this->storeAction(), [
            'property_name' => 'x', 'ownership_type' => "a'b\\", 'address' => 'y',
        ]));
        $run = $this->createForm($html)['run'];
        $this->assertSame("a'b\\", $run['state']['ownershipType']);
    }

    public function test_the_reverse_zip_lookup_marks_itself_as_ajax(): void
    {
        $html = $this->htmlOf(route('mansion.properties.create'));

        $run = $this->driveAlpine($html, 'propertyForm', $this->xData($html, 'propertyForm'), 'reverseMansionLookup();', [], true, [], [],
            ['input[name="address"]' => '愛媛県松山市湊町4-1-1']);

        $this->assertCount(1, $run['requests']);
        $request = $run['requests'][0];
        $this->assertSame('GET', $request['method']);
        $this->assertStringStartsWith(route('api.reverse-zip') . '?prefecture=', $request['url']);
        // ⚠ これが無いと、GET の JSON の URL がセッションの「直前の画面」に記録される（Top trap #9 / Bug #35）
        $this->assertSame('XMLHttpRequest', $request['headers']['X-Requested-With'] ?? null);
    }

    public function test_the_detail_screen_shows_rooms_parkings_and_the_monthly_total(): void
    {
        $tenant = $this->tenant('佐藤 花子');
        $room = $this->room('101');
        $contract = $this->contract($room, $tenant);
        $this->room('102');
        $this->parkingContract($this->parking('A-1'), $tenant, $contract);

        $html = $this->htmlOf(route('mansion.properties.show', $this->building));

        $this->assertStringContainsString('佐藤 花子', $html);
        $this->assertStringContainsString('101号室契約と連動', $html);
        // 賃料 70,000 + 共益費 5,000 + 駐車場 5,000
        $this->assertMatchesRegularExpression('/月額合計<\/div>\s*<div[^>]*>80,000</u', $html);
    }

    // ============================================================
    // 削除（M1）
    // ============================================================

    public function test_a_property_without_contracts_is_deleted_from_the_detail_screen(): void
    {
        $this->room('101');
        $this->parking('A-1');
        $url = route('mansion.properties.show', $this->building);
        $form = $this->deleteForm($this->htmlOf($url), route('mansion.properties.destroy', $this->building));

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '物件を削除しました');
        $this->assertNull(MsProperty::find($this->building->id));
    }

    public function test_a_property_whose_rooms_or_parkings_have_contracts_is_not_deleted(): void
    {
        $tenant = $this->tenant();
        $this->contract($this->room('101'), $tenant, ['status' => 'terminated', 'move_out_date' => '2025-12-31']);
        $this->parkingContract($this->parking('A-1'), $tenant);
        $url = route('mansion.properties.show', $this->building);
        $form = $this->deleteForm($this->htmlOf($url), route('mansion.properties.destroy', $this->building));

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'error', 'この物件の部屋・駐車場には契約が 2 件（解約済みを含む）あるため削除できません。');
        $this->assertNotNull(MsProperty::find($this->building->id));
    }
}
