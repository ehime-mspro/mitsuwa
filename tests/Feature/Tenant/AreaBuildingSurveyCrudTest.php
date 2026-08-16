<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\AreaBuildingSurvey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;

class AreaBuildingSurveyCrudTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    public function test_manager_can_add_a_survey(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $manager  = $this->manager();

        $this->actingAs($manager)->post("/tenant/area-buildings/{$building->id}/surveys", [
            'surveyed_month'  => '2026-08',
            'operating_count' => 7,
            'vacant_count'    => 2,
            'unknown_count'   => 1,
            'notes'           => '1階は改装中',
        ])->assertRedirect(route('tenant.area-buildings.show', $building));

        $survey = AreaBuildingSurvey::firstOrFail();
        $this->assertSame('2026-08-01', $survey->surveyed_month->format('Y-m-d'));
        $this->assertSame($manager->id, $survey->surveyed_by, '調査者の既定がログインユーザーになっていない');
    }

    /** 調査者は変更できる（現地を歩いた担当と入力者が違うことがある。設計 §3.2） */
    public function test_surveyor_can_be_someone_else(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $walker   = $this->actor(UserRole::Staff);

        $this->actingAs($this->manager())->post("/tenant/area-buildings/{$building->id}/surveys", [
            'surveyed_month' => '2026-08',
            'surveyed_by'    => $walker->id,
        ])->assertRedirect();

        $this->assertSame($walker->id, AreaBuildingSurvey::firstOrFail()->surveyed_by);
    }

    /** 件数欄は空欄スタート。未入力は 0 */
    public function test_blank_counts_become_zero(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $this->actingAs($this->manager())
            ->post("/tenant/area-buildings/{$building->id}/surveys", ['surveyed_month' => '2026-08'])
            ->assertRedirect();

        $survey = AreaBuildingSurvey::firstOrFail();
        $this->assertSame([0, 0, 0], [$survey->operating_count, $survey->vacant_count, $survey->unknown_count]);
    }

    /**
     * 同じビルの同じ年月は 1 件。上書きせず確認を出す（設計 §3.2）。
     * ⚠ 500（UNIQUE 違反）ではなくバリデーションエラーで返すこと。
     */
    public function test_duplicate_month_is_rejected_with_a_validation_error(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $this->makeSurvey($building, '2026-08-01', 5, 5);

        $response = $this->actingAs($this->manager())
            ->from(route('tenant.area-buildings.surveys.create', $building))
            ->post("/tenant/area-buildings/{$building->id}/surveys", ['surveyed_month' => '2026-08']);

        $response->assertRedirect(route('tenant.area-buildings.surveys.create', $building));
        $response->assertSessionHasErrors('surveyed_month');
        $this->assertStringContainsString('既に登録されています', session('errors')->first('surveyed_month'));
        $this->assertSame(1, $building->surveys()->count());
    }

    /** 月中の日付で送っても月初に正規化されるので、同じ月として弾かれる */
    public function test_duplicate_detection_is_month_based(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $this->makeSurvey($building, '2026-08-20', 5, 5);   // → 2026-08-01 に正規化済み

        $this->actingAs($this->manager())
            ->from(route('tenant.area-buildings.surveys.create', $building))
            ->post("/tenant/area-buildings/{$building->id}/surveys", ['surveyed_month' => '2026-08'])
            ->assertSessionHasErrors('surveyed_month');
    }

    /** 別のビルの同じ年月は重複ではない（ビル単位で判定していること） */
    public function test_same_month_on_another_building_is_allowed(): void
    {
        $mine   = $this->makeBuilding('自分のビル');
        $others = $this->makeBuilding('別のビル');
        $this->makeSurvey($others, '2026-08-01', 5, 5);

        $this->actingAs($this->manager())
            ->post("/tenant/area-buildings/{$mine->id}/surveys", ['surveyed_month' => '2026-08'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $mine->surveys()->count());
    }

    /** 自分自身は重複判定から除外する（年月を変えずに件数だけ直せる） */
    public function test_update_can_keep_the_same_month(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $survey   = $this->makeSurvey($building, '2026-08-01', 5, 5);

        $this->actingAs($this->manager())
            ->put("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}", [
                'surveyed_month'  => '2026-08',
                'operating_count' => 8,
                'vacant_count'    => 2,
            ])
            ->assertRedirect(route('tenant.area-buildings.show', $building));

        $this->assertSame(8, $survey->fresh()->operating_count);
    }

    /** 他の回が既に使っている年月へは移せない */
    public function test_update_into_another_existing_month_is_rejected(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $july     = $this->makeSurvey($building, '2026-07-01', 5, 5);
        $this->makeSurvey($building, '2026-08-01', 4, 6);

        $this->actingAs($this->manager())
            ->from(route('tenant.area-buildings.surveys.edit', [$building, $july]))
            ->put("/tenant/area-buildings/{$building->id}/surveys/{$july->id}", ['surveyed_month' => '2026-08'])
            ->assertSessionHasErrors('surveyed_month');

        $this->assertSame('2026-07-01', $july->fresh()->surveyed_month->format('Y-m-d'));
    }

    /**
     * 他のビルの調査回に URL を差し替えて到達できないこと。
     * ⚠ ミドルウェアは部門単位でしか見ないので、所有権はコントローラで明示的に確かめる
     *   （部署共通コントローラの IDOR と同型）。
     */
    public function test_survey_of_another_building_is_404(): void
    {
        $mine   = $this->makeBuilding('自分のビル');
        $others = $this->makeBuilding('別のビル');
        $survey = $this->makeSurvey($others, '2026-08-01', 5, 5);

        $manager = $this->manager();

        $this->actingAs($manager)->get("/tenant/area-buildings/{$mine->id}/surveys/{$survey->id}/edit")->assertNotFound();
        $this->actingAs($manager)->put("/tenant/area-buildings/{$mine->id}/surveys/{$survey->id}", ['surveyed_month' => '2026-09'])->assertNotFound();
        $this->actingAs($this->executive())->delete("/tenant/area-buildings/{$mine->id}/surveys/{$survey->id}")->assertNotFound();

        $this->assertSame(1, $others->surveys()->count(), '別ビルの調査回が消えている');
    }

    public function test_permissions(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $survey   = $this->makeSurvey($building, '2026-08-01', 5, 5);
        $staff    = $this->staff();

        $this->actingAs($staff)->get("/tenant/area-buildings/{$building->id}/surveys/create")->assertForbidden();
        $this->actingAs($staff)->post("/tenant/area-buildings/{$building->id}/surveys", ['surveyed_month' => '2026-09'])->assertForbidden();
        $this->actingAs($staff)->get("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}/edit")->assertForbidden();
        $this->actingAs($staff)->put("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}", ['surveyed_month' => '2026-09'])->assertForbidden();
        $this->actingAs($staff)->delete("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}")->assertForbidden();

        // 削除は経営層のみ
        $this->actingAs($this->manager())->delete("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}")->assertForbidden();
    }

    /** 調査回は物理削除（SoftDeletes を持たない。設計 §3.2） */
    public function test_executive_can_hard_delete_a_survey(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $survey   = $this->makeSurvey($building, '2026-08-01', 5, 5);

        $this->actingAs($this->executive())
            ->delete("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}")
            ->assertRedirect(route('tenant.area-buildings.show', $building));

        $this->assertDatabaseMissing('area_building_surveys', ['id' => $survey->id]);
    }

    /**
     * 所見のエラー文言は「備考」でなく「所見」（第3引数の上書きが効いていること）。
     *
     * ⚠ `session('errors')` は **assertSessionHasErrors() を通すまで生の配列**で、
     *   いきなり `->first()` を呼ぶと `Call to a member function first() on array` で
     *   落ちる（2026-08-17 実測。TestResponse::assertSessionHasErrors が ViewErrorBag へ
     *   復元してセッションへ書き戻している）。上の重複テストが動いていたのは、
     *   たまたま assertSessionHasErrors を先に呼んでいたから。
     */
    public function test_notes_error_says_shoken(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $response = $this->actingAs($this->manager())
            ->from(route('tenant.area-buildings.surveys.create', $building))
            ->post("/tenant/area-buildings/{$building->id}/surveys", [
                'surveyed_month' => '2026-08',
                'notes'          => str_repeat('あ', 2001),
            ]);

        $response->assertSessionHasErrors('notes');
        $this->assertStringContainsString('所見', session('errors')->first('notes'));
    }

    /**
     * 調査者セレクトは編集時に現在の調査者を必ず含める（無効化済みでも消えない。Bug #12）。
     * ⚠ option は @@foreach で静的に生成する（x-for は使わない。Bug #16）。
     */
    public function test_edit_form_keeps_a_deactivated_surveyor_in_the_options(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $retired  = $this->actor(UserRole::Staff);
        $survey   = $this->makeSurvey($building, '2026-08-01', 5, 5, 0, ['surveyed_by' => $retired->id]);

        $retired->delete();   // 退職（SoftDeletes）

        $html = $this->actingAs($this->manager())
            ->get("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}/edit")
            ->getContent();

        // ⚠ ページ全体で 'x-for' を探してはいけない。実測: str_contains('x-form-actions', 'x-for')
        //   === true で、components/form-actions.blade.php の使い方コメントにその文字列が実在する
        //   （今は Blade コメントなので出力に出ないだけ）。セレクトの中だけを見る。
        $select = $this->extractSelect($html, 'surveyed_by');
        $this->assertStringContainsString('value="' . $retired->id . '"', $select, '退職者が選択肢から消えている');
        $this->assertStringNotContainsString('x-for', $select, 'option を x-for で生成している（Bug #16）');
    }

    /**
     * 編集フォームの調査者セレクトは **保存されている値**を選ぶこと。
     *
     * ⚠ プランの `old('surveyed_by', $survey?->surveyed_by ?? auth()->id())` は、
     *   調査者が未設定（null）の調査回を開いたときに**編集者を選択済みにしてしまう**。
     *   利用者が調査者に触れず「更新する」を押しただけで調査者が編集者に化ける＝
     *   コントローラ側で `?? null` にして防いだのと同じ事故が、ビュー側で復活する
     *   （Bug #38 と同族）。新規のときだけログインユーザーを既定にする。
     */
    public function test_edit_form_preselects_the_stored_surveyor_not_the_editor(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $walker   = $this->actor(UserRole::Staff);
        $manager  = $this->manager();

        $withSurveyor = $this->makeSurvey($building, '2026-08-01', 5, 5, 0, ['surveyed_by' => $walker->id]);
        $noSurveyor   = $this->makeSurvey($building, '2026-07-01', 5, 5);

        $html = $this->actingAs($manager)
            ->get("/tenant/area-buildings/{$building->id}/surveys/{$withSurveyor->id}/edit")->getContent();
        $this->assertSame(
            (string) $walker->id,
            $this->selectedOptionValue($this->extractSelect($html, 'surveyed_by')),
            '保存されている調査者が選択されていない'
        );

        $html = $this->actingAs($manager)
            ->get("/tenant/area-buildings/{$building->id}/surveys/{$noSurveyor->id}/edit")->getContent();
        $this->assertSame(
            '',
            $this->selectedOptionValue($this->extractSelect($html, 'surveyed_by')),
            '調査者が未設定なのに編集者が選択済みになっている（押しただけで調査者が化ける）'
        );
    }

    /** 新規フォームは逆に、ログインユーザーを既定で選ぶ（そのまま押せば登録者が調査者になる） */
    public function test_create_form_preselects_the_logged_in_user(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $manager  = $this->manager();

        $html = $this->actingAs($manager)
            ->get("/tenant/area-buildings/{$building->id}/surveys/create")->getContent();

        $this->assertSame(
            (string) $manager->id,
            $this->selectedOptionValue($this->extractSelect($html, 'surveyed_by'))
        );
    }

    /**
     * 編集で調査者が黙って書き換わらないこと。
     * ⚠ payload() に `?? Auth::id()` を残すと、別人が編集した瞬間に調査者がその人に化ける。
     */
    public function test_update_does_not_reassign_the_surveyor_to_the_editor(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $walker   = $this->actor(UserRole::Staff);
        $survey   = $this->makeSurvey($building, '2026-08-01', 5, 5, 0, ['surveyed_by' => $walker->id]);

        $this->actingAs($this->manager())
            ->put("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}", [
                'surveyed_month' => '2026-08',
                'surveyed_by'    => $walker->id,   // フォームが出す値をそのまま返す
            ])
            ->assertRedirect();

        $this->assertSame($walker->id, $survey->fresh()->surveyed_by, '調査者が編集者に置き換わっている');
    }

    /** 「未指定」に戻せること（`?? $survey->surveyed_by` にすると戻せなくなる） */
    public function test_update_can_clear_the_surveyor(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $walker   = $this->actor(UserRole::Staff);
        $survey   = $this->makeSurvey($building, '2026-08-01', 5, 5, 0, ['surveyed_by' => $walker->id]);

        $this->actingAs($this->manager())
            ->put("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}", [
                'surveyed_month' => '2026-08',
                'surveyed_by'    => '',
            ])
            ->assertRedirect();

        $this->assertNull($survey->fresh()->surveyed_by);
    }

    /**
     * 経営層でも追加・編集できること。
     * ⚠ これが無いと `role:executive,manager` から executive を落としても全部緑のまま通る
     *   （Task 8 のコード品質レビューで同じ穴が見つかった）。
     */
    public function test_executive_can_add_and_edit(): void
    {
        $building  = $this->makeBuilding('ミツワビル');
        $executive = $this->executive();

        $this->actingAs($executive)->get("/tenant/area-buildings/{$building->id}/surveys/create")->assertOk();
        $this->actingAs($executive)->post("/tenant/area-buildings/{$building->id}/surveys", [
            'surveyed_month'  => '2026-08',
            'operating_count' => 4,
        ])->assertRedirect(route('tenant.area-buildings.show', $building));

        $survey = AreaBuildingSurvey::firstOrFail();

        $this->actingAs($executive)->get("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}/edit")->assertOk();
        $this->actingAs($executive)->put("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}", [
            'surveyed_month'  => '2026-08',
            'operating_count' => 9,
        ])->assertRedirect();

        $this->assertSame(9, $survey->fresh()->operating_count);
    }

    // ============================================================
    // 詳細画面の導線
    //
    // ⚠ プランに無かったが追加した（2026-08-17）。Step 6 は show.blade.php を書き換えるのに
    //   それを見るテストが 1 本も無く、**リンクを丸ごと消しても全部緑のまま**だった。
    //   呼び出し側（画面のリンク）と実体（ルート＋コントローラ）は対で固定する（Bug #28）。
    // ============================================================

    /**
     * 追加・編集・削除の導線が権限どおりに出ること。
     *
     * ⚠ 素の URL でアサートしてはいけない。destroy の URL
     *   （…/surveys/{id}）は edit の URL（…/surveys/{id}/edit）の**前方一致**なので、
     *   管理者に対する assertDontSee(destroy) が編集リンクに反応して必ず落ちる。
     *   属性まで込みで（href= / action=）見る（Bug #43 の false-pass と同型の false-fail）。
     */
    public function test_detail_shows_survey_links_according_to_role(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $survey   = $this->makeSurvey($building, '2026-08-01', 5, 5);

        $create = 'href="' . route('tenant.area-buildings.surveys.create', $building) . '"';
        $edit   = 'href="' . route('tenant.area-buildings.surveys.edit', [$building, $survey]) . '"';
        $delete = 'action="' . route('tenant.area-buildings.surveys.destroy', [$building, $survey]) . '"';

        $staff = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");
        $staff->assertDontSee($create, false);
        $staff->assertDontSee($edit, false);
        $staff->assertDontSee($delete, false);

        $manager = $this->actingAs($this->manager())->get("/tenant/area-buildings/{$building->id}");
        $manager->assertSee($create, false);
        $manager->assertSee($edit, false);
        $manager->assertDontSee($delete, false);

        $executive = $this->actingAs($this->executive())->get("/tenant/area-buildings/{$building->id}");
        $executive->assertSee($create, false);
        $executive->assertSee($edit, false);
        $executive->assertSee($delete, false);
    }

    /**
     * 行ごとの削除は confirm()。
     * ⚠ JS 文字列への差し込みは Js::from()（生の {{ }} だと `'` を含む値で壊れる）。
     *   Js::from() は文字列を JSON リテラルにするので、素の `'2026年8月'` は出てこない。
     */
    public function test_row_delete_uses_confirm_with_js_from(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $survey   = $this->makeSurvey($building, '2026-08-01', 5, 5);

        $html = $this->actingAs($this->executive())
            ->get("/tenant/area-buildings/{$building->id}")
            ->getContent();

        // 実測（2026-08-17）: Js::from('2026年8月') は非 ASCII を \uXXXX へ逃がした
        // シングルクォート付き JS リテラル（'2026\u5e748\u6708'）を返す。
        // 生の {{ }} に戻すと素の `2026年8月` が出るのでここが赤になる。
        $encoded = (string) Js::from($survey->monthLabel());
        $this->assertStringContainsString(
            'onsubmit="return confirm(' . $encoded,
            $html,
            '行ごとの削除が confirm() + Js::from() になっていない'
        );
    }

    /** 「操作」列を足したぶん colspan を合わせること（空表示が 1 列ずれない） */
    public function test_empty_survey_table_spans_every_column(): void
    {
        $building = $this->makeBuilding('調査なしビル');

        $html = $this->actingAs($this->manager())
            ->get("/tenant/area-buildings/{$building->id}")
            ->getContent();

        $columns = $this->countSurveyHeaderColumns($html);
        $this->assertSame(8, $columns, '調査履歴テーブルの列数が想定と違う');
        $this->assertStringContainsString('colspan="' . $columns . '"', $html, '空行の colspan が列数と合っていない');
    }

    /**
     * `<select ... name="X"> … </select>` を切り出す。
     * ⚠ ページ全体を対象にしたアサーションは、無関係な文字列（`x-form-actions` が
     *   `x-for` を部分文字列に含む等）に反応して誤って赤／緑になる。
     */
    private function extractSelect(string $html, string $name): string
    {
        $start = preg_match('/<select[^>]*name="' . preg_quote($name, '/') . '"/', $html, $m, PREG_OFFSET_CAPTURE)
            ? $m[0][1]
            : false;
        $this->assertNotFalse($start, "<select name=\"{$name}\"> が見つからない");

        $end = strpos($html, '</select>', $start);
        $this->assertNotFalse($end, "<select name=\"{$name}\"> が閉じていない");

        return substr($html, $start, $end - $start);
    }

    /**
     * `<select>` の中で selected が付いている `<option>` の value を返す。
     * 選択済みが 1 つも無い（＝先頭の空 option が既定で選ばれる）ときは ''。
     */
    private function selectedOptionValue(string $select): string
    {
        preg_match_all('/<option\s+value="([^"]*)"[^>]*\bselected\b/s', $select, $m);
        $this->assertLessThanOrEqual(1, count($m[1]), 'selected な option が複数ある');

        return $m[1][0] ?? '';
    }

    /** 調査履歴テーブルの <thead> にある <th> の数（入居テナント側の表と混ざらないよう限定する） */
    private function countSurveyHeaderColumns(string $html): int
    {
        $anchor = strpos($html, '調査年月');
        $this->assertNotFalse($anchor, '調査履歴テーブルの見出しが見つからない');

        $rowStart = strrpos(substr($html, 0, $anchor), '<tr>');
        $this->assertNotFalse($rowStart, '調査履歴テーブルの見出し行が見つからない');

        $rowEnd = strpos($html, '</tr>', $rowStart);
        $this->assertNotFalse($rowEnd, '調査履歴テーブルの見出し行が閉じていない');

        return preg_match_all('/<th\b/', substr($html, $rowStart, $rowEnd - $rowStart));
    }
}
