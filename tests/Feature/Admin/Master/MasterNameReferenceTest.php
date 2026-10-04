<?php

namespace Tests\Feature\Admin\Master;

use App\Models\Property;
use App\Models\ReProcurement;
use App\Models\ReProject;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Concerns\CreatesStructureTypeSchema;

/**
 * 名前で参照されるマスタ（構造・用途地域）の名前を変えても、使っている側の値が消えないこと（G6）。
 *
 * テナント物件の構造（`properties.structure`）と仕入れ案件・分譲地の用途地域（`zoning`）は、マスタの id ではなく
 * **名前**を文字列で持つ。以前は、
 *   - マスタの名前を変えると、使っている側は古い名前のまま残り、編集画面の選択肢に今の値が無いので
 *     「選択してください」が選ばれ、**どの項目を直して保存しても構造・用途地域が空になった**
 *   - 古い名前で使われているだけなので、名前を変えたマスタは使用中でも削除できた
 * ① 編集画面は、今の値がマスタに無ければ「〇〇（マスタに無い値）」として選んだまま描く
 * ② マスタの名前を変えると、使っている側の値も同じトランザクションで新しい名前に変える
 */
class MasterNameReferenceTest extends MasterScreenTestCase
{
    use CreatesRealEstateSchema;
    use CreatesStructureTypeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        $this->createStructureTypeSchema();
        DB::table('structure_types')->insert([['name' => 'RC造', 'sort_order' => 1], ['name' => 'S造', 'sort_order' => 2]]);
        DB::table('zoning_types')->insert([['name' => '第一種住居地域', 'sort_order' => 1], ['name' => '商業地域', 'sort_order' => 2]]);
    }

    private function propertyWith(?string $structure, string $code): Property
    {
        return Property::create([
            'code' => $code, 'name' => "構造テストビル{$code}", 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市1-1', 'structure' => $structure,
        ]);
    }

    private function procurementWith(?string $zoning, string $code): ReProcurement
    {
        return ReProcurement::create([
            'procurement_code' => $code, 'property_type' => 'used_house', 'transaction_type' => 'purchase',
            'status' => 'info_obtained', 'property_name' => "仕入れ{$code}", 'address' => '愛媛県松山市1-1',
            'zoning' => $zoning, 'created_by' => 1,
        ]);
    }

    private function projectWith(?string $zoning, string $code): ReProject
    {
        return ReProject::create([
            'project_code' => $code, 'project_name' => "分譲地{$code}", 'status' => 'selling',
            'address' => '愛媛県松山市2-2', 'zoning' => $zoning, 'created_by' => 1,
        ]);
    }

    /** 一覧の先頭の項目の名前を、画面から $newName に変える */
    private function renameFirst(string $prefix, string $function, string $newName): string
    {
        $sent = $this->submitFromScreen(route("admin.master.{$prefix}.index"), $function,
            "var it = data.items[0]; data.startEdit(it.id, it.name); data.editingName = '{$newName}'; data.submitEdit();");
        $sent['response']->assertSessionHasNoErrors();

        return $this->landed($sent['response']);
    }

    /** ② 構造の名前を変えると、その名前の物件（論理削除したものも）の構造も変わり、変えた件数が出る */
    public function test_renaming_a_structure_renames_it_on_the_properties(): void
    {
        $alive = $this->propertyWith('RC造', 'P-1');
        $deleted = $this->propertyWith('RC造', 'P-2');
        $deleted->delete();
        $other = $this->propertyWith('S造', 'P-3');

        $html = $this->renameFirst('structure-types', 'structureTypeManager', '鉄筋コンクリート造');

        $this->assertSame('鉄筋コンクリート造', $alive->fresh()->structure);
        $this->assertSame('鉄筋コンクリート造', Property::withTrashed()->find($deleted->id)->structure);
        $this->assertSame('S造', $other->fresh()->structure);
        $this->assertFlash($html, 'success', '「鉄筋コンクリート造」を更新しました。この構造を使っていたテナント物件 2 件も新しい名前に変えました。');

        // 物件が新しい名前で使っているので、名前を変えたあとも削除は断られる
        $refused = $this->submitFromScreen(route('admin.master.structure-types.index'), 'structureTypeManager',
            'var it = data.items[0]; data.startDelete(it.id, it.name); data.submitDelete();');
        $this->assertTrue(DB::table('structure_types')->where('name', '鉄筋コンクリート造')->exists());
        $this->assertFlash($this->landed($refused['response']), 'error', '「鉄筋コンクリート造」はテナント物件で使用されているため削除できません。');
    }

    /** ② 使っている物件が無ければ、件数の文は出さない */
    public function test_renaming_an_unused_structure_says_nothing_about_properties(): void
    {
        $this->propertyWith('S造', 'P-1');

        $html = $this->renameFirst('structure-types', 'structureTypeManager', '鉄筋コンクリート造');

        $this->assertFlash($html, 'success', '「鉄筋コンクリート造」を更新しました。');
        $this->assertSame('S造', Property::where('code', 'P-1')->value('structure'));
    }

    /** ② 用途地域の名前を変えると、その名前の仕入れ案件・分譲地の用途地域も変わり、それぞれの件数が出る */
    public function test_renaming_a_zoning_renames_it_on_procurements_and_projects(): void
    {
        $p1 = $this->procurementWith('第一種住居地域', 'P-001');
        $p2 = $this->procurementWith('第一種住居地域', 'P-002');
        $p3 = $this->procurementWith('商業地域', 'P-003');
        $j1 = $this->projectWith('第一種住居地域', 'J-001');

        $html = $this->renameFirst('zoning-types', 'zoningTypeManager', '第1種住居地域');

        $this->assertSame(['第1種住居地域', '第1種住居地域', '商業地域'], [$p1->fresh()->zoning, $p2->fresh()->zoning, $p3->fresh()->zoning]);
        $this->assertSame('第1種住居地域', $j1->fresh()->zoning);
        $this->assertFlash($html, 'success', '「第1種住居地域」を更新しました。この用途地域を使っていた仕入れ案件 2 件・分譲地 1 件も新しい名前に変えました。');
    }

    /** ① マスタに無い構造の物件は、編集画面がその値を選んだまま描き、保存しても消えない */
    public function test_the_property_form_keeps_a_structure_that_is_not_in_the_master(): void
    {
        $property = $this->propertyWith('鉄骨造', 'P-1');
        $edit = route('tenant.properties.edit', $property);
        $html = $this->htmlOf($edit);

        $this->assertStringContainsString('<option value="鉄骨造" selected>鉄骨造（マスタに無い値）</option>', $html);
        $form = $this->parseForm($html, 'action="' . route('tenant.properties.update', $property) . '"');
        $this->assertSame('鉄骨造', $form['fields']['structure']);

        $this->actingAs($this->user)->from($edit)->post($form['action'], $form['fields'])->assertSessionHasNoErrors();
        $this->assertSame('鉄骨造', $property->fresh()->structure, '編集画面から保存したら構造が消えた');
    }

    /** ① マスタにある値・空の値には、余計な選択肢を足さない */
    public function test_the_property_form_adds_no_extra_option_for_a_master_value_or_an_empty_one(): void
    {
        foreach (['S造' => 'P-1', null => 'P-2'] as $structure => $code) {
            $property = $this->propertyWith($structure === '' ? null : $structure, $code);
            $html = $this->htmlOf(route('tenant.properties.edit', $property));

            $this->assertStringNotContainsString('（マスタに無い値）', $html);
            $form = $this->parseForm($html, 'action="' . route('tenant.properties.update', $property) . '"');
            $this->assertSame($structure === '' ? '' : $structure, $form['fields']['structure']);
        }
    }

    /** ① マスタに無い用途地域の仕入れ案件は、編集画面がその値を選んだまま描き、保存しても消えない */
    public function test_the_procurement_form_keeps_a_zoning_that_is_not_in_the_master(): void
    {
        $procurement = $this->procurementWith('田園住居地域', 'P-001');
        $edit = route('realestate.procurements.edit', $procurement);
        $html = $this->htmlOf($edit);

        $this->assertStringContainsString('<option value="田園住居地域" selected>田園住居地域（マスタに無い値）</option>', $html);
        $form = $this->parseForm($html, 'action="' . route('realestate.procurements.update', $procurement) . '"');
        $this->assertSame('田園住居地域', $form['fields']['zoning']);

        $this->actingAs($this->user)->from($edit)->post($form['action'], $form['fields'])->assertSessionHasNoErrors();
        $this->assertSame('田園住居地域', $procurement->fresh()->zoning, '編集画面から保存したら用途地域が消えた');
    }

    /** ① マスタに無い用途地域の分譲地も同じ */
    public function test_the_project_form_keeps_a_zoning_that_is_not_in_the_master(): void
    {
        $project = $this->projectWith('田園住居地域', 'J-001');
        $edit = route('realestate.projects.edit', $project);
        $html = $this->htmlOf($edit);

        $this->assertStringContainsString('<option value="田園住居地域" selected>田園住居地域（マスタに無い値）</option>', $html);
        $form = $this->parseForm($html, 'action="' . route('realestate.projects.update', $project) . '"');
        $this->assertSame('田園住居地域', $form['fields']['zoning']);

        $this->actingAs($this->user)->from($edit)->post($form['action'], $form['fields'])->assertSessionHasNoErrors();
        $this->assertSame('田園住居地域', $project->fresh()->zoning, '編集画面から保存したら用途地域が消えた');
    }

    /** ① 用途地域がマスタにある仕入れ案件・分譲地には、余計な選択肢を足さない */
    public function test_the_realestate_forms_add_no_extra_option_for_a_master_value(): void
    {
        $procurement = $this->procurementWith('商業地域', 'P-001');
        $project = $this->projectWith('商業地域', 'J-001');

        foreach ([
            [route('realestate.procurements.edit', $procurement), route('realestate.procurements.update', $procurement)],
            [route('realestate.projects.edit', $project), route('realestate.projects.update', $project)],
        ] as [$edit, $update]) {
            $html = $this->htmlOf($edit);
            $this->assertStringNotContainsString('（マスタに無い値）', $html);
            $this->assertSame('商業地域', $this->parseForm($html, 'action="' . $update . '"')['fields']['zoning']);
        }
    }
}
