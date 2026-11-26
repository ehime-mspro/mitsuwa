<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Enums\UserRole;
use App\Models\ReProject;
use App\Models\ReProjectCost;
use App\Models\ReProjectDrawing;
use App\Models\ReProjectLot;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ExpectsSaveFailure;
use Tests\Concerns\FailsImportWrites;
use Tests\Concerns\SignsOutElsewhere;

/**
 * 分譲地の登録（原価の行つき）・詳細の原価（削除・試算表の取込。追加・編集は CostGridScreenTest）・一覧のステータスの小窓・
 * 区画の画面の図面の削除を、描いた画面から送る往復で見る。
 */
class ProjectScreensTest extends RealEstateScreenTestCase
{
    use ExpectsSaveFailure;
    use FailsImportWrites;
    use SignsOutElsewhere;

    /** 部品 _ajax_save_failure の script の目印（断られた応答を画面に戻すときに一緒に読む） */
    private const SAVE_FAILURE_SCRIPT = 'function ajaxSaveFailureMessage(';
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

    /** 画面を開いたまま時間がたったか、ほかの画面でログアウトしたあとにステータスを変えると、理由が日本語で出る（Bug #137） */
    public function test_a_status_change_after_the_session_expired_says_why(): void
    {
        $project = $this->project(['status' => 'info_obtained']);
        $html = $this->htmlOf(route('realestate.projects.index'));
        $steps = 'data.select(data.options.find(function (o) { return o.value === "assessment"; }));';
        $factory = $this->xData($html, 'projectStatusCell');

        $request = $this->driveAlpine($html, 'projectStatusCell', $factory, $steps)['requests'][0];
        $this->enforceCsrfToken();
        $response = $this->sendCaptured($this->withExpiredToken($request))->assertStatus(419);
        $after = $this->driveAlpine($html, 'projectStatusCell', $factory, $steps, [$this->asFetchResponse($response)], true, ['value'], withScripts: [self::SAVE_FAILURE_SCRIPT]);

        $this->assertSame([self::EXPIRED_JSON_MESSAGE], $after['alerts']);
        $this->assertSame(['info_obtained'], $after['evaluated'], '断られたのに画面のステータスが変わった');
        $this->assertSame('info_obtained', $project->fresh()->status->value);
    }

    /** ほかの画面でパスワードを変えたあとにステータスを変えると、ログインが切れて 401 になる。理由が日本語で出る（直す前は「…（401）」） */
    public function test_a_status_change_after_the_password_changed_elsewhere_says_why(): void
    {
        $project = $this->project(['status' => 'info_obtained']);
        $html = $this->htmlOf(route('realestate.projects.index'));
        $steps = 'data.select(data.options.find(function (o) { return o.value === "assessment"; }));';
        $factory = $this->xData($html, 'projectStatusCell');

        $request = $this->driveAlpine($html, 'projectStatusCell', $factory, $steps)['requests'][0];
        $this->changePasswordElsewhere();
        $response = $this->sendCaptured($request)->assertUnauthorized();
        $after = $this->driveAlpine($html, 'projectStatusCell', $factory, $steps, [$this->asFetchResponse($response)], true, ['value'], withScripts: [self::SAVE_FAILURE_SCRIPT]);

        $this->assertSame([self::SIGNED_OUT_JSON_MESSAGE], $after['alerts']);
        $this->assertSame(['info_obtained'], $after['evaluated'], '断られたのに画面のステータスが変わった');
        $this->assertSame('info_obtained', $project->fresh()->status->value);
    }

    /** ログインと関係の無い断り（開いたあとで分譲地が消えた 404）は、番号つきの今の文のまま（サーバーの英語の文は出さない） */
    public function test_a_status_change_that_fails_otherwise_keeps_the_numbered_message(): void
    {
        $project = $this->project(['status' => 'info_obtained']);
        $html = $this->htmlOf(route('realestate.projects.index'));
        $steps = 'data.select(data.options.find(function (o) { return o.value === "assessment"; }));';
        $factory = $this->xData($html, 'projectStatusCell');

        $request = $this->driveAlpine($html, 'projectStatusCell', $factory, $steps)['requests'][0];
        $project->delete();
        $response = $this->sendCaptured($request)->assertNotFound();
        $after = $this->driveAlpine($html, 'projectStatusCell', $factory, $steps, [$this->asFetchResponse($response)], withScripts: [self::SAVE_FAILURE_SCRIPT]);

        $this->assertSame(['ステータスの更新に失敗しました（404）'], $after['alerts']);
    }

    /**
     * 画面を開いたあとで権限が外れてからステータスを変えると、403 の理由が出る（直す前は「…（403）」）。
     * 利用者は部署の無い経営層。管理者に変わると、部署の確かめ（department.access:realestate）が役割より先に断る
     */
    public function test_a_status_change_by_a_user_who_lost_the_permission_says_why(): void
    {
        $project = $this->project(['status' => 'info_obtained']);
        $html = $this->htmlOf(route('realestate.projects.index'));
        $steps = 'data.select(data.options.find(function (o) { return o.value === "assessment"; }));';
        $factory = $this->xData($html, 'projectStatusCell');

        $request = $this->driveAlpine($html, 'projectStatusCell', $factory, $steps)['requests'][0];
        $this->user->role = UserRole::Manager;
        $this->user->save();
        $response = $this->sendCaptured($request)->assertForbidden();
        $after = $this->driveAlpine($html, 'projectStatusCell', $factory, $steps, [$this->asFetchResponse($response)], true, ['value'], withScripts: [self::SAVE_FAILURE_SCRIPT]);

        $this->assertSame(['ステータスを更新できませんでした。この部門のデータへのアクセス権限がありません。'], $after['alerts']);
        $this->assertSame(['info_obtained'], $after['evaluated'], '断られたのに画面のステータスが変わった');
        $this->assertSame('info_obtained', $project->fresh()->status->value);
    }

    /** 419・401・403 でも本文が JSON でない（HTML で返った）ときは、番号つきの今の文（読めない本文を理由として出さない） */
    public function test_a_status_change_answered_with_html_keeps_the_numbered_message(): void
    {
        $this->project(['status' => 'info_obtained']);
        $html = $this->htmlOf(route('realestate.projects.index'));
        $steps = 'data.select(data.options.find(function (o) { return o.value === "assessment"; }));';
        $factory = $this->xData($html, 'projectStatusCell');

        foreach ([419, 401, 403] as $status) {
            $after = $this->driveAlpine($html, 'projectStatusCell', $factory, $steps, [$this->htmlResponse($status)], true, ['value'], withScripts: [self::SAVE_FAILURE_SCRIPT]);

            $this->assertSame(["ステータスの更新に失敗しました（{$status}）"], $after['alerts'], "{$status}: 番号つきの今の文でない");
            $this->assertSame(['info_obtained'], $after['evaluated'], "{$status}: 断られたのに画面のステータスが変わった");
        }
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

    // ================================================================
    // 区画の画面の追加・更新・削除と分譲地のステータスの集約は 1 つのトランザクション（docs/RULES.md Bug #148 の追記）
    // ================================================================

    /**
     * 区画の画面の JS（lotManager）が組んだ要求を、集約の途中で落として送る（前は区画だけが変わり、分譲地のステータスがずれたまま残った）。
     * 区画を保存した「あと」の集約の UPDATE（`update "re_projects"`）を断る。区画の保存そのものを落とすだけでは、トランザクションが無くても何も変わらない。
     */
    private function lotScreenRequest(ReProject $project, string $steps, array $elements = []): array
    {
        $html = $this->htmlOf(route('realestate.projects.lots', $project));
        $run = $this->driveAlpine($html, 'lotManager', 'lotManager()', $steps, [], true, [], [], $elements);
        $this->assertCount(1, $run['requests'], '区画の画面の JS が要求を組んでいない（alert: ' . implode(' / ', $run['alerts']) . '）');

        return $run;
    }

    /** 区画 2 号地を選ぶ JS */
    private const LOT_2 = 'data.lots.find(function (l) { return l.lot_number === 2; })';

    public function test_a_lot_added_on_the_lot_screen_that_fails_when_resyncing_leaves_no_lot(): void
    {
        $project = $this->project(['status' => 'sold_out']);
        $this->lot($project, 1, 20000000, 'sold');
        $run = $this->lotScreenRequest($project, 'data.showAddLot = true; data.addLot();', [
            '#add-lot-number' => '2', '#add-area-sqm' => '150.00', '#add-selling-price' => '20000000', '#add-status' => 'on_sale', '#add-notes' => '',
        ]);
        $this->assertSame(['POST', route('realestate.projects.lots.store', $project)], [$run['requests'][0]['method'], $run['requests'][0]['url']]);
        $this->failQuery('/^update "re_projects" /i');

        $this->assertSaveFails(fn () => $this->sendCaptured($run['requests'][0]));

        $this->assertSame(1, ReProjectLot::where('project_id', $project->id)->count(), '集約の途中で落ちたのに区画が増えた');
        $this->assertSame('sold_out', $project->fresh()->status->value);
    }

    public function test_a_lot_edited_on_the_lot_screen_that_fails_when_resyncing_leaves_the_lot_unchanged(): void
    {
        $project = $this->project(['status' => 'selling']);
        $this->lot($project, 1, 20000000, 'sold');
        $lot2 = $this->lot($project, 2, 20000000, 'on_sale');
        $run = $this->lotScreenRequest($project, 'data.showOps = true; data.startEditLot(' . self::LOT_2 . '); data.editLotData.status = "sold"; data.saveLot();');
        $this->assertSame(['PUT', route('realestate.projects.lots.update', [$project, $lot2])], [$run['requests'][0]['method'], $run['requests'][0]['url']]);
        $this->failQuery('/^update "re_projects" /i');

        $this->assertSaveFails(fn () => $this->sendCaptured($run['requests'][0]));

        $this->assertSame('on_sale', $lot2->fresh()->status->value, '集約の途中で落ちたのに区画が変わった');
        $this->assertSame('selling', $project->fresh()->status->value);
    }

    public function test_a_lot_deleted_on_the_lot_screen_that_fails_when_resyncing_leaves_the_lot(): void
    {
        $project = $this->project(['status' => 'selling']);
        $this->lot($project, 1, 20000000, 'sold');
        $lot2 = $this->lot($project, 2, 20000000, 'on_sale');
        $run = $this->lotScreenRequest($project, 'data.showOps = true; data.deleteLot(' . self::LOT_2 . ');');
        $this->assertSame(['区画 2 号地を削除しますか？'], $run['confirms']);
        $this->assertSame(['DELETE', route('realestate.projects.lots.destroy', [$project, $lot2])], [$run['requests'][0]['method'], $run['requests'][0]['url']]);
        $this->failQuery('/^update "re_projects" /i');

        $this->assertSaveFails(fn () => $this->sendCaptured($run['requests'][0]));

        $this->assertNotNull(ReProjectLot::find($lot2->id), '集約の途中で落ちたのに区画が消えた');
        $this->assertSame('selling', $project->fresh()->status->value);
    }
}
