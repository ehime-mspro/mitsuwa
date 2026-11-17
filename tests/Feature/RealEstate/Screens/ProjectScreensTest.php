<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Enums\UserRole;
use App\Models\ReProject;
use App\Models\ReProjectCost;
use App\Models\ReProjectDrawing;
use Illuminate\Support\Facades\Storage;

/**
 * 分譲地の登録（原価の行つき）・詳細の原価（削除・試算表の取込。追加・編集は CostGridScreenTest）・一覧のステータスの小窓・
 * 区画の画面の図面の削除を、描いた画面から送る往復で見る。
 */
class ProjectScreensTest extends RealEstateScreenTestCase
{
    private const DETAIL_SCRIPTS = ['function costExcelImporterFactory(', 'function costGridFactory('];

    public function test_a_project_is_registered_from_the_screen_with_a_cost_row(): void
    {
        $url = route('realestate.projects.create');
        $html = $this->htmlOf($url);
        $form = $this->formWithCostSection($html, route('realestate.projects.store'), 'projectForm', '',
            // 原価欄: 見込み額の表で入れて閉じる（一覧の hidden で登録と一緒に送る）
            'data.openCostGrid("estimated"); var r = data.costGrid.rows.filter(function (x) { return x.name === "測量費"; })[0];'
            . ' r.draft.amount = "1,200,000"; r.draft.notes = "造成前"; data.closeCostGrid();');
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

    /** 取り除いた「＋ 費用追加」「編集」と、表を開いているあいだ隠すもの（設計 7.1・7.2）を、描いた詳細画面で見る */
    public function test_the_detail_list_has_no_add_or_edit_controls(): void
    {
        $owner = $this->project();
        $executive = $this->htmlOf(route('realestate.projects.show', $owner));

        foreach (['＋ 費用追加', 'startEditCost', 'addCost(', 'showAddCost', 'editingCostId', 'saveCost('] as $gone) {
            $this->assertStringNotContainsString($gone, $executive, "取り除いたはずの {$gone} が残っている");
        }
        $this->assertSame(1, preg_match('/class="text-gray-400">未定<\/span>/u', $executive), '確定額が空の表示が「未定」でない');
        $this->assertSame(0, preg_match('/cost\.actual_amount === null[^>]*>—</u', $executive), '確定額が空の表示が「—」のまま');
        $this->assertStringContainsString('原価データがありません。「見込み額を入力」から入れてください。', $executive);
        $this->assertStringContainsString('deleteCost(cost)', $executive, '経営層に「削除」が出ていない');

        $previous = $this->user;
        $this->user = $this->userWithRole(UserRole::Manager);
        try {
            $manager = $this->htmlOf(route('realestate.projects.show', $owner));
        } finally {
            $this->user = $previous;
        }
        $this->assertStringNotContainsString('deleteCost(cost)', $manager, '管理者に「削除」が出ている');

        // 入力の表を開いているあいだは、カード右上の 3 つのボタンと見るだけの一覧を隠す（x-show の式を画面の状態で評価する）
        $guards = [];
        foreach (["openCostExcelImport()", "openCostGrid('estimated')", "openCostGrid('actual')"] as $click) {
            $this->assertSame(1, preg_match('/<button\b[^>]*@click="' . preg_quote($click, '/') . '"[^>]*\sx-show="([^"]*)"/', $executive, $m), "{$click} のボタンに x-show が無い");
            $guards[] = html_entity_decode($m[1]);
        }
        $this->assertSame(1, preg_match('/<div class="border border-gray-200 rounded-md overflow-hidden" x-show="([^"]*)">\s*<div class="scroll-hint/', $executive, $m), '見るだけの一覧の x-show が無い');
        $guards[] = html_entity_decode($m[1]);

        $closed = $this->driveAlpine($executive, 'projectDetail', 'projectDetail()', '', [], true, $guards, self::DETAIL_SCRIPTS);
        $this->assertSame([true, true, true, true], $closed['evaluated'], '表を開く前なのにボタン・一覧が隠れている');
        $open = $this->driveAlpine($executive, 'projectDetail', 'projectDetail()', 'data.openCostGrid("estimated");', [], true, $guards, self::DETAIL_SCRIPTS);
        $this->assertSame([false, false, false, false], $open['evaluated'], '表を開いているのに、ボタンか見るだけの一覧が出たまま');
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

    /**
     * 区画の画面の合計のカード（区画数・面積合計・販売価格合計・原価合計・粗利合計・粗利率）は 1 行の flex で、
     * 375px では 207px 横にはみ出した（2026-10-07 に実ブラウザで実測）。折り返す。
     */
    public function test_the_lot_summary_cards_wrap_on_narrow_screens(): void
    {
        $html = $this->htmlOf(route('realestate.projects.lots', $this->project()));

        $this->assertMatchesRegularExpression('/<div x-show="lots.length > 0">\s*<div style="display: flex;[^"]*flex-wrap: wrap;[^"]*">/u', $html);
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
