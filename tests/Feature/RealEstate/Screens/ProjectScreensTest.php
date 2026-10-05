<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\ReProject;
use App\Models\ReProjectCost;
use App\Models\ReProjectDrawing;
use Illuminate\Support\Facades\Storage;

/**
 * 分譲地の登録（原価の行つき）・詳細の原価（追加・編集・削除・試算表の取込）・一覧のステータスの小窓・
 * 区画の画面の図面の削除を、描いた画面から送る往復で見る。
 */
class ProjectScreensTest extends RealEstateScreenTestCase
{
    private const DETAIL_SCRIPTS = ['function costExcelImporterFactory('];

    public function test_a_project_is_registered_from_the_screen_with_a_cost_row(): void
    {
        $url = route('realestate.projects.create');
        $html = $this->htmlOf($url);
        $form = $this->formWithCostSection($html, route('realestate.projects.store'), 'projectForm', '',
            'data.showAddCost = true; data.newCost = { cost_item_id: "' . $this->surveyItem->id . '", estimated_amount: "1200000", actual_amount: "", notes: "造成前" }; data.addCost();');
        $form = $this->fill($form, ['project_name' => '北条分譲地', 'status' => 'info_obtained', 'address' => '愛媛県松山市北条1', 'purchase_price' => '30000000']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '分譲地「RE-PRJ-001」を登録しました（原価 1 件を含む）。');
        $project = ReProject::where('project_name', '北条分譲地')->firstOrFail();
        $this->assertSame(30000000, $project->purchase_price);
        $survey = ReProjectCost::where('cost_item_id', $this->surveyItem->id)->firstOrFail();
        $this->assertSame([1200000, null, '造成前'], [$survey->estimated_amount, $survey->actual_amount, $survey->notes]);
    }

    // ============================================================
    // 詳細の原価（Ajax）
    // ============================================================

    private function detail(string $html, string $steps, array $responses = [], array $evaluate = []): array
    {
        return $this->driveAlpine($html, 'projectDetail', 'projectDetail()', $steps, $responses, true, $evaluate, self::DETAIL_SCRIPTS);
    }

    public function test_a_cost_is_added_from_the_detail_screen(): void
    {
        $project = $this->project();
        $html = $this->htmlOf(route('realestate.projects.show', $project));
        $steps = 'data.showAddCost = true; data.newCost = { cost_item_id: "' . $this->surveyItem->id . '", estimated_amount: 800000, actual_amount: 750000, notes: "" }; data.addCost();';

        $run = $this->detail($html, $steps);
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertOk();

        $cost = ReProjectCost::where('project_id', $project->id)->where('cost_item_id', $this->surveyItem->id)->firstOrFail();
        $this->assertSame([800000, 750000], [$cost->estimated_amount, $cost->actual_amount]);
        $after = $this->detail($html, $steps, [$this->asFetchResponse($response)], ['costs.length', 'costMessage']);
        $this->assertSame([1, '費用を追加しました。'], $after['evaluated']);
    }

    public function test_a_cost_is_edited_from_the_detail_screen(): void
    {
        $project = $this->project();
        $cost = ReProjectCost::create(['project_id' => $project->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => 800000]);
        $html = $this->htmlOf(route('realestate.projects.show', $project));
        $steps = 'var c = data.costs[0]; data.startEditCost(c); data.editCost.estimated_amount = 900000; data.editCost.notes = "見直し"; data.saveCost(c);';

        $run = $this->detail($html, $steps);
        $this->assertSame('PUT', $run['requests'][0]['method']);
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertOk();

        $this->assertSame([900000, '見直し'], [$cost->fresh()->estimated_amount, $cost->fresh()->notes]);
        $after = $this->detail($html, $steps, [$this->asFetchResponse($response)], ['costs[0].estimated_amount', 'editingCostId']);
        $this->assertSame([900000, null], $after['evaluated']);
    }

    public function test_a_cost_is_deleted_from_the_detail_screen(): void
    {
        $project = $this->project();
        $cost = ReProjectCost::create(['project_id' => $project->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => 800000]);
        $html = $this->htmlOf(route('realestate.projects.show', $project));
        $steps = 'data.deleteCost(data.costs[0]);';

        $run = $this->detail($html, $steps);
        $this->assertSame(['「測量費」を削除しますか？'], $run['confirms']);
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertOk();

        $this->assertNull($cost->fresh());
        $after = $this->detail($html, $steps, [$this->asFetchResponse($response)], ['costs.length']);
        $this->assertSame([0], $after['evaluated']);
    }

    public function test_costs_are_imported_from_the_spreadsheet_panel(): void
    {
        $project = $this->project();
        $html = $this->htmlOf(route('realestate.projects.show', $project));
        $steps = 'data.costExcelImport.mode = "append"; data.costExcelImport.previewRows = ['
            . '{ skip: false, costItemId: ' . $this->surveyItem->id . ', estimated: 4000000, actual: 3900000, notes: "造成" }]; data.commitCostImport();';

        $run = $this->detail($html, $steps);
        $this->assertSame([], $run['confirms'], '追加の取込で入れ替えの確認が出た');
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertOk();

        $cost = ReProjectCost::where('project_id', $project->id)->firstOrFail();
        $this->assertSame([4000000, 3900000, '造成'], [$cost->estimated_amount, $cost->actual_amount, $cost->notes]);
        $after = $this->detail($html, $steps, [$this->asFetchResponse($response)], ['costs.length', 'costMessage']);
        $this->assertSame([1, '1 件の原価を取り込みました。'], $after['evaluated']);
    }

    // ============================================================
    // 一覧のステータスの小窓・区画の画面の図面の削除
    // ============================================================

    public function test_the_status_is_changed_from_the_list(): void
    {
        $project = $this->project(['status' => 'info_obtained']);
        $html = $this->htmlOf(route('realestate.projects.index'));
        $steps = 'data.select(data.options.find(function (o) { return o.value === "assessment"; }));';
        $factory = $this->xData($html, 'projectStatusCell');

        $run = $this->driveAlpine($html, 'projectStatusCell', $factory, $steps);
        $this->assertSame('PATCH', $run['requests'][0]['method']);
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertOk();

        $this->assertSame('assessment', $project->fresh()->status->value);
        $after = $this->driveAlpine($html, 'projectStatusCell', $factory, $steps, [$this->asFetchResponse($response)], true, ['value', 'label', 'open']);
        $this->assertSame(['assessment', '検討', false], $after['evaluated']);
    }

    public function test_a_drawing_is_deleted_from_the_lot_screen(): void
    {
        Storage::fake('public');
        $project = $this->project();
        Storage::disk('public')->put('project-drawings/' . $project->id . '/a.pdf', '%PDF-1.4');
        $drawing = ReProjectDrawing::create([
            'project_id' => $project->id, 'file_name' => '区画図.pdf', 'file_path' => 'project-drawings/' . $project->id . '/a.pdf',
            'file_size' => 8, 'mime_type' => 'application/pdf', 'uploaded_by' => $this->user->id,
        ]);
        $html = $this->htmlOf(route('realestate.projects.lots', $project));
        $steps = 'data.showDrawingDel = true; data.deleteDrawing(data.drawings[0]);';

        $run = $this->driveAlpine($html, 'lotManager', 'lotManager()', $steps);
        $this->assertSame(['「区画図.pdf」を削除しますか？'], $run['confirms']);
        $this->assertSame('DELETE', $run['requests'][0]['method']);
        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertOk();

        $this->assertNull($drawing->fresh());
        Storage::disk('public')->assertMissing('project-drawings/' . $project->id . '/a.pdf');
        $after = $this->driveAlpine($html, 'lotManager', 'lotManager()', $steps, [$this->asFetchResponse($response)], true, ['drawings.length', 'message']);
        $this->assertSame([0, '図面を削除しました。'], $after['evaluated']);
    }
}
