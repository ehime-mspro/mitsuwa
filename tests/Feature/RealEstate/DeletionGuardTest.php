<?php

namespace Tests\Feature\RealEstate;

use App\Enums\UserRole;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\ReProjectDrawing;
use App\Models\ReProjectLot;
use App\Models\User;
use App\Support\DeletionBlockers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 仕入れ案件 / 分譲地PJ / 区画の削除ガード。
 *
 * ⚠ tests/Concerns/CreatesRealEstateSchema.php は列だけ作り FK 制約を張らない（13 行目に明記）。
 *    よって SQLite では ON DELETE SET NULL も CASCADE も起きず、
 *    「ガードを外すとデータが壊れる」ことは**テストでは原理的に再現できない**（Bug #40 と同型）。
 *    ここで固定するのは FK の副作用ではなく **ガードの挙動そのもの**。
 *
 * ⚠ 削除系ルートは全て middleware('role:executive')。executive() で actingAs すること。
 */
class DeletionGuardTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /** 経営層ユーザー（department.access を無条件通過し、削除系 role:executive も届く） */
    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    private function makeProcurement(string $code = 'P-001'): ReProcurement
    {
        return ReProcurement::create([
            'procurement_code' => $code,
            'property_type'    => 'used_house',
            'transaction_type' => 'purchase',
            'status'           => 'info_obtained',
            'property_name'    => "仕入れ{$code}",
            'address'          => '愛媛県松山市1-1-1',
            'created_by'       => 1,
        ]);
    }

    private function makeProject(string $code = 'PJ-001'): ReProject
    {
        return ReProject::create([
            'project_code' => $code,
            'project_name' => "分譲地{$code}",
            'status'       => 'selling',
            'address'      => '愛媛県松山市2-2-2',
            'created_by'   => 1,
        ]);
    }

    private function makeLot(ReProject $project, int $lotNumber = 1): ReProjectLot
    {
        return ReProjectLot::create([
            'project_id' => $project->id,
            'lot_number' => $lotNumber,
            'area_sqm'   => 100.00,
            'area_tsubo' => 30.25,
            'status'     => 'on_sale',
        ]);
    }

    /**
     * @param  array<string, mixed>  $links  procurement_id / project_id / lot_id のいずれか
     */
    private function makeContract(array $links, string $propertyName = '契約物件A'): ReContract
    {
        return ReContract::create(array_merge([
            'department'    => 'realestate',
            'contract_type' => 'procurement_land',
            'status'        => 'contracted',
            'property_name' => $propertyName,
            'buyer_name'    => '山西 太郎',
            'created_by'    => 1,
        ], $links));
    }

    private function makeProperty(ReProjectLot $lot, string $code = 'HS-0007'): HsProperty
    {
        return HsProperty::create([
            'property_code'     => $code,
            'property_name'     => '建売テスト邸',
            'status'            => 'construction',
            'land_source_type'  => 'project_lot',
            're_project_lot_id' => $lot->id,
            'address'           => '愛媛県松山市3-3-3',
            'created_by'        => 1,
        ]);
    }

    private function makeCustomOrder(ReProjectLot $lot, string $code = 'CO-0003'): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'        => $code,
            'order_name'        => '注文住宅テスト邸',
            'status'            => 'contracted',
            'customer_name'     => '大西 花子',
            'land_source_type'  => 'project_lot',
            're_project_lot_id' => $lot->id,
            'address'           => '愛媛県松山市4-4-4',
            'created_by'        => 1,
        ]);
    }

    // ============================================================
    // Task 1: forLotIds()
    // ============================================================

    /** 参照が無ければ空配列（＝削除可能）。空配列を「削除可能」の合図に使うので形を固定する */
    public function test_lot_without_references_has_no_blockers(): void
    {
        $lot = $this->makeLot($this->makeProject());

        $this->assertSame([], DeletionBlockers::forLotIds([$lot->id]));
        $this->assertSame([], DeletionBlockers::forLotIds([]));
    }

    /** 建売・注文住宅・契約が区画を参照していたら 3 種とも拾う */
    public function test_lot_blockers_collect_all_three_kinds(): void
    {
        $project = $this->makeProject();
        $lot     = $this->makeLot($project);

        $this->makeContract(['lot_id' => $lot->id], '区画契約');
        $this->makeProperty($lot);
        $this->makeCustomOrder($lot);

        $blockers = DeletionBlockers::forLotIds([$lot->id]);

        $this->assertCount(3, $blockers);
        $this->assertSame(['契約', '建売物件', '注文住宅'], array_column($blockers, 'label'));
        $this->assertSame('区画契約（山西 太郎 様）', $blockers[0]['items'][0]['name']);
        $this->assertSame('HS-0007 建売テスト邸', $blockers[1]['items'][0]['name']);
        $this->assertSame('CO-0003 注文住宅テスト邸', $blockers[2]['items'][0]['name']);
        $this->assertStringContainsString('/housing/properties/', $blockers[1]['items'][0]['url']);
        // ルート名の取り違え（例: ...show → ...index）を検出するため、契約・注文住宅の url も固定する
        $this->assertStringContainsString('/realestate/contracts/', $blockers[0]['items'][0]['url']);
        $this->assertStringContainsString('/housing/custom-orders/', $blockers[2]['items'][0]['url']);
    }

    /** 他の区画の参照を巻き込まない（whereIn の取り違えを検出する） */
    public function test_lot_blockers_do_not_leak_across_lots(): void
    {
        $project = $this->makeProject();
        $lotA    = $this->makeLot($project, 1);
        $lotB    = $this->makeLot($project, 2);

        $this->makeProperty($lotA);

        $this->assertCount(1, DeletionBlockers::forLotIds([$lotA->id]));
        $this->assertSame([], DeletionBlockers::forLotIds([$lotB->id]));
    }

    // ============================================================
    // Task 2: forProcurementId() / forProject()
    // ============================================================

    /**
     * 仕入れ案件を参照している契約・建売・注文住宅を拾う。
     *
     * ⚠ 3 種そろえないと forProcurementId() の HsCustomOrder クエリを削除しても
     *    このテストは緑のまま通る（設計書 §2.2 が要求する 3 種の 1 つが無防備になる）。
     */
    public function test_procurement_blockers_collect_all_three_kinds(): void
    {
        $procurement = $this->makeProcurement();

        $this->makeContract(['procurement_id' => $procurement->id], '仕入れ契約');
        HsProperty::create([
            'property_code'     => 'HS-0011',
            'property_name'     => '仕入れ土地の建売',
            'status'            => 'construction',
            'land_source_type'  => 'procurement',
            're_procurement_id' => $procurement->id,
            'address'           => '愛媛県松山市5-5-5',
            'created_by'        => 1,
        ]);
        HsCustomOrder::create([
            'order_code'        => 'CO-0012',
            'order_name'        => '仕入れ土地の注文住宅',
            'status'            => 'contracted',
            'customer_name'     => '西野 一郎',
            'land_source_type'  => 'procurement',
            're_procurement_id' => $procurement->id,
            'address'           => '愛媛県松山市6-6-6',
            'created_by'        => 1,
        ]);

        $blockers = DeletionBlockers::forProcurementId($procurement->id);

        $this->assertSame(['契約', '建売物件', '注文住宅'], array_column($blockers, 'label'));
        $this->assertSame('仕入れ契約（山西 太郎 様）', $blockers[0]['items'][0]['name']);
        $this->assertSame('CO-0012 仕入れ土地の注文住宅', $blockers[2]['items'][0]['name']);
        // summarize() の区切り文字（・）と件数・語順を複数エントリで固定する（1 エントリだけだと implode の区切り文字が見えない）
        $this->assertSame(
            '契約 1 件・建売物件 1 件・注文住宅 1 件が参照しているため削除できません。',
            DeletionBlockers::summarize($blockers)
        );
    }

    /** 参照が無ければ空配列。summarize([]) が空文字であることも合わせて固定する（区画一覧の delete_blocked_reason で load-bearing） */
    public function test_procurement_without_references_has_no_blockers(): void
    {
        $procurement = $this->makeProcurement();

        $this->assertSame([], DeletionBlockers::forProcurementId($procurement->id));
        $this->assertSame('', DeletionBlockers::summarize([]));
    }

    /** PJ 直参照の契約と、配下区画を参照する建売の両方を拾う */
    public function test_project_blockers_collect_direct_and_via_lot(): void
    {
        $project = $this->makeProject();
        $lot     = $this->makeLot($project);

        $this->makeContract(['project_id' => $project->id], 'PJ直参照の契約');
        $this->makeProperty($lot);

        $blockers = DeletionBlockers::forProject($project);

        $this->assertSame(['契約', '建売物件'], array_column($blockers, 'label'));
        $this->assertCount(1, $blockers[0]['items']);
        $this->assertCount(1, $blockers[1]['items']);
    }

    /**
     * ⑤ 契約が project_id と lot_id の**両方**で紐づくとき、件数は 1。
     *
     * ⚠ 2 本のクエリに分けて足すと 2 件に見える（設計書 §3.4）。
     *    forProject() をグループ化 OR の 1 クエリから 2 クエリ + concat に戻すと**このテストが赤になる**。
     */
    public function test_contract_linked_by_both_project_and_lot_is_counted_once(): void
    {
        $project = $this->makeProject();
        $lot     = $this->makeLot($project);

        $this->makeContract([
            'project_id' => $project->id,
            'lot_id'     => $lot->id,
        ], '二重紐づけ契約');

        $blockers = DeletionBlockers::forProject($project);

        $this->assertCount(1, $blockers, '契約以外の種別が混ざっていない');
        $this->assertCount(1, $blockers[0]['items'], '同じ契約が 2 件に増えていない');
        $this->assertSame('契約 1 件が参照しているため削除できません。', DeletionBlockers::summarize($blockers));
    }

    /** 区画も参照も無い PJ は空配列（lotIds が空のときにクエリが暴走しないことも兼ねる） */
    public function test_project_without_lots_and_references_has_no_blockers(): void
    {
        $this->assertSame([], DeletionBlockers::forProject($this->makeProject()));
    }

    // ============================================================
    // Task 3: forEachLotId()
    // ============================================================

    /** 区画ごとに分かれること・参照の無い区画は空配列であること */
    public function test_for_each_lot_id_groups_blockers_per_lot(): void
    {
        $project = $this->makeProject();
        $lotA    = $this->makeLot($project, 1);
        $lotB    = $this->makeLot($project, 2);
        $lotC    = $this->makeLot($project, 3);

        $this->makeProperty($lotA, 'HS-0001');
        $this->makeCustomOrder($lotB, 'CO-0001');

        $grouped = DeletionBlockers::forEachLotId([$lotA->id, $lotB->id, $lotC->id]);

        $this->assertSame(['建売物件'], array_column($grouped[$lotA->id], 'label'));
        $this->assertSame(['注文住宅'], array_column($grouped[$lotB->id], 'label'));
        $this->assertSame([], $grouped[$lotC->id], '参照の無い区画は空配列');

        // 渡した区画は全てキーとして返る（?? [] のフォールバック頼みにしない）
        $this->assertSame([$lotA->id, $lotB->id, $lotC->id], array_keys($grouped));
    }

    /** 空配列を渡しても壊れない */
    public function test_for_each_lot_id_with_empty_input(): void
    {
        $this->assertSame([], DeletionBlockers::forEachLotId([]));
    }

    // ============================================================
    // Task 4: モデルのラッパー
    // ============================================================

    /** 3 モデルのラッパーが DeletionBlockers と同じ結果を返す（配線の確認） */
    public function test_model_wrappers_delegate_to_deletion_blockers(): void
    {
        $procurement = $this->makeProcurement();
        $project     = $this->makeProject();
        $lot         = $this->makeLot($project);

        $this->makeContract(['procurement_id' => $procurement->id], '仕入れ契約');
        $this->makeProperty($lot);

        $this->assertSame(
            DeletionBlockers::forProcurementId($procurement->id),
            $procurement->deletionBlockers()
        );
        $this->assertSame(
            DeletionBlockers::forProject($project),
            $project->deletionBlockers()
        );
        $this->assertSame(
            DeletionBlockers::forLotIds([$lot->id]),
            $lot->deletionBlockers()
        );
    }

    /** 参照が無ければ 3 モデルとも空配列（＝削除可能） */
    public function test_model_wrappers_return_empty_when_free(): void
    {
        $project = $this->makeProject();

        $this->assertSame([], $this->makeProcurement()->deletionBlockers());
        $this->assertSame([], $project->deletionBlockers());
        $this->assertSame([], $this->makeLot($project)->deletionBlockers());
    }

    // ============================================================
    // Task 5: ① 仕入れ案件の削除ガード（HTTP）
    // ============================================================

    /** ① 契約が紐づく仕入れ案件は削除できず、レコードが残る */
    public function test_procurement_with_contract_cannot_be_deleted(): void
    {
        $procurement = $this->makeProcurement();
        $this->makeContract(['procurement_id' => $procurement->id], '仕入れ契約');

        $response = $this->actingAs($this->executive())
            ->from("/realestate/procurements/{$procurement->id}")
            ->delete("/realestate/procurements/{$procurement->id}");

        $response->assertRedirect("/realestate/procurements/{$procurement->id}");
        $response->assertSessionHas('error', '契約 1 件が参照しているため削除できません。');
        $this->assertDatabaseHas('re_procurements', ['id' => $procurement->id]);
    }

    /** 建売物件が紐づく仕入れ案件も削除できない */
    public function test_procurement_with_housing_property_cannot_be_deleted(): void
    {
        $procurement = $this->makeProcurement();
        HsProperty::create([
            'property_code'     => 'HS-0011',
            'property_name'     => '仕入れ土地の建売',
            'status'            => 'construction',
            'land_source_type'  => 'procurement',
            're_procurement_id' => $procurement->id,
            'address'           => '愛媛県松山市5-5-5',
            'created_by'        => 1,
        ]);

        $this->actingAs($this->executive())
            ->delete("/realestate/procurements/{$procurement->id}")
            ->assertSessionHas('error', '建売物件 1 件が参照しているため削除できません。');

        $this->assertDatabaseHas('re_procurements', ['id' => $procurement->id]);
    }

    /** ④ 依存が無ければ従来どおり削除できる（ガードが常時ブロック化していないことの確認） */
    public function test_procurement_without_references_can_still_be_deleted(): void
    {
        $procurement = $this->makeProcurement();

        $response = $this->actingAs($this->executive())
            ->delete("/realestate/procurements/{$procurement->id}");

        $response->assertRedirect('/realestate/procurements');
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('re_procurements', ['id' => $procurement->id]);
    }

    // ============================================================
    // Task 6: ② 分譲地PJ の削除ガード（HTTP）
    // ============================================================

    /** ② 建売が紐づく区画を持つ分譲地は削除できず、PJ・区画とも残る */
    public function test_project_with_housing_property_on_a_lot_cannot_be_deleted(): void
    {
        $project = $this->makeProject();
        $lot     = $this->makeLot($project);
        $this->makeProperty($lot);

        $response = $this->actingAs($this->executive())
            ->from("/realestate/projects/{$project->id}")
            ->delete("/realestate/projects/{$project->id}");

        $response->assertRedirect("/realestate/projects/{$project->id}");
        $response->assertSessionHas('error', '建売物件 1 件が参照しているため削除できません。');
        $this->assertDatabaseHas('re_projects', ['id' => $project->id]);
        $this->assertDatabaseHas('re_project_lots', ['id' => $lot->id]);
    }

    /** PJ を直接参照する契約でもブロックされる（区画経由だけではない） */
    public function test_project_with_direct_contract_cannot_be_deleted(): void
    {
        $project = $this->makeProject();
        $this->makeContract(['project_id' => $project->id], 'PJ直参照の契約');

        $this->actingAs($this->executive())
            ->delete("/realestate/projects/{$project->id}")
            ->assertSessionHas('error', '契約 1 件が参照しているため削除できません。');

        $this->assertDatabaseHas('re_projects', ['id' => $project->id]);
    }

    /** ブロックされたとき図面ファイルは消えない（ガードが物理削除より前にあることを固定） */
    public function test_blocked_project_deletion_does_not_touch_drawing_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('re_drawings/plan.pdf', 'dummy');

        $project = $this->makeProject();
        $lot     = $this->makeLot($project);
        $this->makeProperty($lot);
        ReProjectDrawing::create([
            'project_id' => $project->id,
            'file_name'  => 'plan.pdf',
            'file_path'  => 're_drawings/plan.pdf',
            'file_size'  => 5,
            'mime_type'  => 'application/pdf',
        ]);

        $this->actingAs($this->executive())
            ->delete("/realestate/projects/{$project->id}")
            ->assertSessionHas('error');

        Storage::disk('public')->assertExists('re_drawings/plan.pdf');
        $this->assertDatabaseHas('re_project_drawings', ['project_id' => $project->id]);
    }

    /** ④ 区画があっても参照が無ければ従来どおり削除できる（成功パスの図面物理削除も固定） */
    public function test_project_without_references_can_still_be_deleted(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('re_drawings/plan.pdf', 'dummy');

        $project = $this->makeProject();
        $this->makeLot($project);
        ReProjectDrawing::create([
            'project_id' => $project->id,
            'file_name'  => 'plan.pdf',
            'file_path'  => 're_drawings/plan.pdf',
            'file_size'  => 5,
            'mime_type'  => 'application/pdf',
        ]);

        $response = $this->actingAs($this->executive())
            ->delete("/realestate/projects/{$project->id}");

        $response->assertRedirect('/realestate/projects');
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('re_projects', ['id' => $project->id]);
        Storage::disk('public')->assertMissing('re_drawings/plan.pdf');
    }

    // ============================================================
    // Task 7: ③ 区画 1 件の削除ガード（Ajax）
    // ============================================================

    /** ③ 注文住宅が紐づく区画は 422 + message で拒否され、区画が残る */
    public function test_lot_with_custom_order_cannot_be_deleted(): void
    {
        $project = $this->makeProject();
        $lot     = $this->makeLot($project);
        $this->makeCustomOrder($lot);

        $response = $this->actingAs($this->executive())
            ->deleteJson("/realestate/projects/{$project->id}/lots/{$lot->id}");

        $response->assertStatus(422);
        // ⚠ キーは message。lots.blade.php の JS が err.message を読む（error だと理由が出ない）
        $response->assertExactJson(['message' => '注文住宅 1 件が参照しているため削除できません。']);
        $this->assertDatabaseHas('re_project_lots', ['id' => $lot->id]);
    }

    /** ④ 参照の無い区画は従来どおり削除できる */
    public function test_lot_without_references_can_still_be_deleted(): void
    {
        $project = $this->makeProject();
        $lot     = $this->makeLot($project);

        $response = $this->actingAs($this->executive())
            ->deleteJson("/realestate/projects/{$project->id}/lots/{$lot->id}");

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('re_project_lots', ['id' => $lot->id]);
    }

    /** 所属違いの 403 も message キーで返す（JS が理由を出せるように揃える） */
    public function test_lot_from_another_project_is_rejected_with_message_key(): void
    {
        $projectA = $this->makeProject('PJ-001');
        $projectB = $this->makeProject('PJ-002');
        $lot      = $this->makeLot($projectB);

        $response = $this->actingAs($this->executive())
            ->deleteJson("/realestate/projects/{$projectA->id}/lots/{$lot->id}");

        $response->assertStatus(403);
        $response->assertExactJson(['message' => '不正なリクエストです。']);
        $this->assertDatabaseHas('re_project_lots', ['id' => $lot->id]);
    }

    /**
     * 呼び出し側（Blade）と定義側（コントローラ）を対で固定する。
     * サーバが message を返しても、読む側が err.message を見なくなれば
     * 理由は画面に出ない。サーバ側テストは全部緑のまま通るので、ここで固定する（Bug #28 / #35）。
     *
     * ⚠ ファイル全体に対する assertStringContainsString だと false-pass する。
     *    同じ lots.blade.php 内の storeLot / saveLot（区画の追加・更新）にも
     *    "err.message || 'エラーが発生しました。'" が別途あり、deleteLot だけを
     *    err.error に変異させても消えないため（実測で確認済み）。
     *    deleteLot 関数の本体だけを正規表現で切り出してから見る。
     */
    public function test_lots_view_reads_message_key_from_delete_error(): void
    {
        $blade = file_get_contents(resource_path('views/realestate/projects/lots.blade.php'));

        $matched = preg_match(
            '/deleteLot:\s*function\s*\([^)]*\)\s*\{(.*?)\n        \},/s',
            $blade,
            $m
        );
        $this->assertSame(1, $matched, 'deleteLot 関数本体を抽出できなかった（走査の空振り防止）');

        $this->assertStringContainsString("err.message || 'エラーが発生しました。'", $m[1]);
    }

    // ============================================================
    // Task 8: ⑥ 詳細画面のパネルと削除ボタン
    // ============================================================

    /** 依存ありの詳細画面: 削除ボタンが disabled になっている */
    private function assertDeleteButtonDisabled(string $html): void
    {
        $this->assertMatchesRegularExpression(
            '/<button[^>]*\sdisabled[^>]*>\s*削除\s*<\/button>/u',
            $html,
            '削除ボタンが disabled で描画されていない'
        );
    }

    /** 依存なしの詳細画面: 従来どおり submit の削除ボタンが出ている */
    private function assertDeleteButtonEnabled(string $html): void
    {
        $this->assertMatchesRegularExpression(
            '/<button\s+type="submit"[^>]*>\s*削除\s*<\/button>/u',
            $html,
            '通常の削除ボタンが描画されていない'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<button[^>]*\sdisabled[^>]*>\s*削除\s*<\/button>/u',
            $html,
            '依存が無いのに削除ボタンが無効化されている'
        );
    }

    /**
     * (b) ブロック時、destroy へ送信する <form> が跡形もないこと。
     * 残っていると confirm() を経ずに直接 POST できる導線が HTML に残ってしまう。
     * destroy と show は同じ URI（動詞違いだけ）なので action 属性の文字列一致で判定できる
     * （実測: routes/web.php でこの URI を action に使う <form> は該当 show.blade.php にしか無い）。
     */
    private function assertNoDestroyFormPresent(string $html, string $destroyUrl): void
    {
        $this->assertStringNotContainsString(
            'action="' . $destroyUrl . '"',
            $html,
            'ブロック時なのに削除 form の action が残っている'
        );
    }

    /**
     * 採用①: title は disabled なボタン自身に置いても表示されない（disabled 要素はホバーイベントを
     * 発火しないため。Firefox もかつては例外的に出していたが Bugzilla 274626 で他ブラウザに揃えた）。
     * ホバーを受けられる <span> 側に載っていることを固定する。
     */
    private function assertDeleteReasonShownOnHoverableSpan(string $html, string $expectedSummary): void
    {
        $this->assertStringContainsString(
            '<span title="' . e($expectedSummary) . '"',
            $html,
            '<span> に理由の title が無い（disabled なボタン自身に title を置いても表示されない）'
        );
    }

    /** ⑥ 仕入れ案件詳細: パネルに依存名が出て、かつ削除ボタンが無効 */
    public function test_procurement_show_lists_blockers_and_disables_delete(): void
    {
        $procurement = $this->makeProcurement();
        // ⚠ 買主名は既存の契約カードにも出るので、パネルの検証には property_name を使う
        $this->makeContract(['procurement_id' => $procurement->id], 'パネル検証用契約名');

        $response = $this->actingAs($this->executive())
            ->get("/realestate/procurements/{$procurement->id}");

        $response->assertOk();
        $response->assertSee('このデータを参照しているため削除できません');
        $response->assertSee('パネル検証用契約名（山西 太郎 様）');
        $this->assertDeleteButtonDisabled($response->getContent());
        $this->assertNoDestroyFormPresent(
            $response->getContent(),
            route('realestate.procurements.destroy', $procurement)
        );
        $this->assertDeleteReasonShownOnHoverableSpan(
            $response->getContent(),
            '契約 1 件が参照しているため削除できません。'
        );
    }

    /** ⑥ 分譲地詳細: パネルに依存名が出て、かつ削除ボタンが無効 */
    public function test_project_show_lists_blockers_and_disables_delete(): void
    {
        $project = $this->makeProject();
        $lotA    = $this->makeLot($project, 1);
        $lotB    = $this->makeLot($project, 2);
        $this->makeProperty($lotA, 'HS-0042');
        // ⚠ (d) 依存を複数件にする。常に 1 件だけの fixture では count() の実装ミス
        //    （例: 常に 1 固定で返す）を原理的に検出できない。
        $this->makeProperty($lotB, 'HS-0043');

        $response = $this->actingAs($this->executive())
            ->get("/realestate/projects/{$project->id}");
        $html = $response->getContent();

        $response->assertOk();
        $response->assertSee('このデータを参照しているため削除できません');
        $response->assertSee('HS-0042 建売テスト邸');
        $response->assertSee('HS-0043 建売テスト邸');
        // ⚠ (a) assertSee('建売物件 2 件') だけでは false-pass する — 無効ボタンの title 要約文
        //    「建売物件 2 件が参照しているため削除できません。」にも部分文字列として一致するため
        //    （Bug #40 と同型）。パネルの件数行はタグに囲まれているので、タグ込みで見て区別する。
        //    実測: 件数行だけを削っても、この確認が無ければ緑のまま通ってしまう。
        $this->assertMatchesRegularExpression(
            '/<div class="text-xs font-semibold text-amber-800 mb-1">建売物件 2 件<\/div>/u',
            $html,
            'パネルの件数行が正しく描画されていない（title 属性への false-pass 対策）'
        );
        // (c) パネルの各行が <a href> リンクになっていること（設計書 §5.1: 該当詳細画面へのリンク）
        $this->assertMatchesRegularExpression(
            '#<a\s+href="[^"]*/housing/properties/\d+"[^>]*>HS-0042 建売テスト邸</a>#u',
            $html,
            'パネル行が <a href> リンクになっていない（素のテキストに後退している）'
        );
        $this->assertDeleteButtonDisabled($html);
        $this->assertNoDestroyFormPresent($html, route('realestate.projects.destroy', $project));
        $this->assertDeleteReasonShownOnHoverableSpan(
            $html,
            '建売物件 2 件が参照しているため削除できません。'
        );
    }

    /** 依存 0 件のときはパネルを描かない（空枠を全画面に増やさない）+ ボタンは有効のまま */
    public function test_show_pages_without_blockers_render_no_panel_and_keep_delete(): void
    {
        $procurement = $this->makeProcurement();
        $project     = $this->makeProject();

        $procurementResponse = $this->actingAs($this->executive())
            ->get("/realestate/procurements/{$procurement->id}");
        $procurementResponse->assertOk();
        $procurementResponse->assertDontSee('このデータを参照しているため削除できません');
        $this->assertDeleteButtonEnabled($procurementResponse->getContent());

        $projectResponse = $this->actingAs($this->executive())
            ->get("/realestate/projects/{$project->id}");
        $projectResponse->assertOk();
        $projectResponse->assertDontSee('このデータを参照しているため削除できません');
        $this->assertDeleteButtonEnabled($projectResponse->getContent());
    }

    // ============================================================
    // Task 9: ⑦ 区画一覧の delete_blocked
    // ============================================================

    /** ⑦ 参照のある区画だけ delete_blocked = true、他は false */
    public function test_lots_page_marks_only_blocked_lots(): void
    {
        $project = $this->makeProject();
        $blocked = $this->makeLot($project, 1);
        $free    = $this->makeLot($project, 2);

        $this->makeProperty($blocked);

        $response = $this->actingAs($this->executive())
            ->get("/realestate/projects/{$project->id}/lots");

        $response->assertOk();

        $lots = collect($response->viewData('lotsForJs'))->keyBy('id');

        $this->assertTrue($lots[$blocked->id]['delete_blocked']);
        $this->assertSame(
            '建売物件 1 件が参照しているため削除できません。',
            $lots[$blocked->id]['delete_blocked_reason']
        );

        $this->assertFalse($lots[$free->id]['delete_blocked']);
        $this->assertSame('', $lots[$free->id]['delete_blocked_reason']);
    }

    /**
     * 呼び出し側（Blade）と定義側（コントローラ）を対で固定する。
     *
     * ⚠ Bug #28 / #35 と同じ構図 — viewData だけ見ていると、Blade からバインドが消えても緑になる。
     * ⚠ Bug #32 — x-show は display を自分のものとして扱うので、この要素は 1 要素のまま
     *    :disabled で出し分ける。静的 style= を残すと Alpine に上書きされる（Bug #2 / #5）。
     * ⚠ 採用①（コードレビュー指摘）— disabled なボタン自身の title は表示されない
     *    （ホバーイベントが発火しないため）。理由はホバー可能なラッパー <span> に置く
     *    （projects/show.blade.php と同じ扱い）。よってボタン自身は :title を持たないことを固定し、
     *    理由の置き場はラッパー側の正規表現で別途確認する。
     */
    public function test_lots_blade_binds_delete_blocked_without_style_conflict(): void
    {
        $blade = file_get_contents(resource_path('views/realestate/projects/lots.blade.php'));

        // 削除ボタンの開始タグを全部拾う。
        // ⚠ preg_match（単数）だと「1 個以上見つかった」しか分からず、
        //    バインドの無い 2 個目のボタンが増えても緑のまま通る（走査テストの空振り防止）。
        $found = preg_match_all('/<button[^>]*deleteLot\(lot\)[^>]*>/u', $blade, $m);
        $this->assertSame(1, $found, '区画削除ボタンはちょうど 1 つ（2026-08-02 実測）');
        $button = $m[0][0];

        $this->assertStringContainsString(':disabled="lot.delete_blocked"', $button);
        $this->assertStringNotContainsString(' style="', $button, '静的 style= は :style へ寄せること（Bug #2 / #5）');
        $this->assertStringNotContainsString('x-show', $button, 'x-show は display を奪う（Bug #32）');
        $this->assertStringNotContainsString(':title', $button, 'disabled なボタン自身の title は表示されない（採用①）');

        // 理由はホバー可能なラッパー <span> に載せる（disabled なボタン自身の title は表示されない）。
        // ⚠ 属性名を明示する（:data-reason 等への付け替えでも赤になるように。採用② G2 対策）。
        $this->assertMatchesRegularExpression(
            '/<span[^>]*:title="[^"]*lot\.delete_blocked_reason[^"]*"[^>]*>\s*<button[^>]*deleteLot\(lot\)/us',
            $blade,
            'title は disabled なボタンではなくホバー可能なラッパー <span> に置くこと'
        );

        // 無効時の見た目（色分け）が実際に効いていること（採用③ G1 対策 — :style の三項式を
        // 落として色分けを消しても :disabled だけは残るため、見た目だけの劣化はここでしか拾えない）。
        $this->assertStringContainsString('not-allowed', $button, '無効時のカーソルが :style に無い');
        $this->assertStringContainsString('#9ca3af', $button, '無効時の文字色が :style に無い');
    }

    /**
     * ⚠ G3（コードレビュー指摘）— forEachLotId() は建売物件・注文住宅だけテストしており、
     *    契約による削除ブロックが一度も検証されていなかった。契約分岐を丸ごと削っても
     *    緑のまま通ることを実測で確認済み（2026-08-03、変異テストで検証）。
     */
    public function test_lots_page_marks_lot_blocked_by_contract(): void
    {
        $project = $this->makeProject();
        $blocked = $this->makeLot($project, 1);

        $this->makeContract(['lot_id' => $blocked->id], '区画契約');

        $response = $this->actingAs($this->executive())
            ->get("/realestate/projects/{$project->id}/lots");

        $response->assertOk();

        $lots = collect($response->viewData('lotsForJs'))->keyBy('id');

        $this->assertTrue($lots[$blocked->id]['delete_blocked']);
        $this->assertSame(
            '契約 1 件が参照しているため削除できません。',
            $lots[$blocked->id]['delete_blocked_reason']
        );
    }

    /**
     * ⚠ 網羅調査（コードレビュー指摘の 12 通り監査）で追加発見 — forProject() は
     *    契約・建売物件しかテストしておらず、配下区画経由の注文住宅が一度も検証されて
     *    いなかった。orders を丸ごと落としても緑のまま通ることを実測で確認済み
     *    （2026-08-03、変異テストで検証）。
     */
    public function test_project_blockers_include_custom_order_via_lot(): void
    {
        $project = $this->makeProject();
        $lot     = $this->makeLot($project);

        $this->makeCustomOrder($lot);

        $blockers = DeletionBlockers::forProject($project);

        $this->assertSame(['注文住宅'], array_column($blockers, 'label'));
        $this->assertCount(1, $blockers[0]['items']);
    }

    /**
     * M-2（コードレビュー指摘）— DeletionBlockers の docblock が「forLotIds() をループ内で
     * 呼ぶと N+1 になる」と明記しているのに、それを固定するテストが無かった。
     * 区画数が増えてもクエリ本数が一定（3 本）であることを実測で固定する。
     */
    public function test_lots_page_bulk_queries_do_not_scale_with_lot_count(): void
    {
        $project = $this->makeProject();
        $lots    = collect(range(1, 5))->map(fn (int $n) => $this->makeLot($project, $n));
        $this->makeProperty($lots[0]);
        $this->makeCustomOrder($lots[2]);

        $queryCount = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $response = $this->actingAs($this->executive())
            ->get("/realestate/projects/{$project->id}/lots");

        $response->assertOk();

        // 区画 5 件・契約 / 建売 / 注文住宅の判定込みでも、削除ブロッカー用のバルククエリは
        // 区画数に比例しない（forEachLotId() は常に 3 本）。実測（2026-08-03）:
        // 正しい実装 = 8 本 / forLotIds() をループで呼ぶ N+1 相当の変異 = 20 本。
        // 閾値はその中間に置き、「区画 1 件ごとに増える」形だけを検出する。
        $this->assertLessThan(15, $queryCount, "区画 5 件でクエリ {$queryCount} 本 — N+1 の疑い");
    }
}
