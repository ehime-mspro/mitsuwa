<?php

namespace Tests\Feature\Admin;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ChecksBrowserConstraints;
use Tests\Concerns\CreatesStructureTypeSchema;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * テナントの物件の CSV 取込の築年月と階数（取込で入った値が、編集画面でそのまま保存できること）。
 *
 * 直す前: 築年月 `2010/4` は `2010-4` のまま保存され、編集画面の `type="month"` の欄が空にして保存していた。
 * `2010-13` も入り、階数は上限なしで入った（画面とサーバーは 99 まで）。
 *
 * ⚠ CSV は Excel と同じ形（行の終わりは CRLF）で作る。
 */
class TenantPropertyImportValuesTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use SubmitsImportPreview;
    use ChecksBrowserConstraints;
    use CreatesStructureTypeSchema;

    private const HEADER = '物件名,郵便番号,住所,構造,築年月,階数,所有区分,オーナー名,稼働状態';

    private function importBasePath(): string
    {
        return '/admin/tenant-import';
    }

    private function row(string $name, string $built = '', string $floors = ''): string
    {
        return "{$name},790-0001,愛媛県松山市,RC造,{$built},{$floors},自社,,稼働中\r\n";
    }

    public function test_a_built_month_is_saved_with_two_digits_and_the_edit_screen_keeps_it(): void
    {
        $this->createStructureTypeSchema();
        DB::table('structure_types')->insert(['name' => 'RC造', 'sort_order' => 1]);

        $this->confirm('property', self::HEADER . "\r\n" . $this->row('新ビル', '2010/4', '5'))
            ->assertSessionHas('success', '物件インポート完了: 1件を登録しました');

        $property = Property::sole();
        $this->assertSame('2010-04', $property->built_date);

        $user = $this->executive();
        $edit = route('tenant.properties.edit', $property);
        $html = $this->actingAs($user)->get($edit)->assertOk()->getContent();
        $needle = 'action="' . route('tenant.properties.update', $property) . '"';

        $form = $this->parseForm($html, $needle);
        $this->assertBrowserSubmits($html, $needle, $form['fields']);

        $fields = $form['fields'];
        $fields['notes'] = '編集で足したメモ';
        $this->actingAs($user)->from($edit)->post($form['action'], $fields)->assertSessionHasNoErrors();

        $property = $property->fresh();
        $this->assertSame('2010-04', $property->built_date, '編集画面から保存したら築年月が消えた');
        $this->assertSame('編集で足したメモ', $property->notes);
    }

    public function test_a_month_that_does_not_exist_is_an_error_row(): void
    {
        $csv = self::HEADER . "\r\n"
             . $this->row('十三月ビル', '2010-13')
             . $this->row('零月ビル', '2010-00')
             . $this->row('正しいビル', '2010-04');

        $preview = $this->preview('property', $csv)->assertOk();

        $this->assertSame([
            ['row' => 2, 'message' => '築年月「2010-13」は年月として正しくありません（例: 2010-04 か 2010/4）'],
            ['row' => 3, 'message' => '築年月「2010-00」は年月として正しくありません（例: 2010-04 か 2010/4）'],
        ], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('property', $csv);
        $this->assertSame(['正しいビル'], Property::pluck('name')->all());
    }

    public function test_floors_up_to_99_are_imported_and_more_is_an_error_row(): void
    {
        $csv = self::HEADER . "\r\n"
             . $this->row('九十九階ビル', '', '99')
             . $this->row('百階ビル', '', '100')
             . $this->row('零階ビル', '', '0');

        $preview = $this->preview('property', $csv)->assertOk();

        $this->assertSame([
            ['row' => 3, 'message' => '階数「100」は1〜99の整数で入力してください'],
            ['row' => 4, 'message' => '階数「0」は1〜99の整数で入力してください'],
        ], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('property', $csv);
        $this->assertSame(99, (int) Property::sole()->total_floors);
    }

    private function executive(): User
    {
        return User::factory()->create(['role' => 'executive', 'must_change_password' => false]);
    }
}
