<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\AreaBuildingSurvey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $writes = $this->watchWrites();

        $response = $this->actingAs($this->manager())
            ->from(route('tenant.area-buildings.surveys.create', $building))
            ->post("/tenant/area-buildings/{$building->id}/surveys", ['surveyed_month' => '2026-08']);

        $response->assertRedirect(route('tenant.area-buildings.surveys.create', $building));
        $response->assertSessionHasErrors('surveyed_month');
        $this->assertStringContainsString('既に登録されています', session('errors')->first('surveyed_month'));
        $this->assertSame(1, $building->surveys()->count());
        $this->assertFalse($writes->created, '事前チェックをすり抜けて INSERT を試みている（monthTaken が効いていない）');
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
        $writes = $this->watchWrites();

        $this->actingAs($this->manager())
            ->from(route('tenant.area-buildings.surveys.edit', [$building, $july]))
            ->put("/tenant/area-buildings/{$building->id}/surveys/{$july->id}", ['surveyed_month' => '2026-08'])
            ->assertSessionHasErrors('surveyed_month');

        $this->assertSame('2026-07-01', $july->fresh()->surveyed_month->format('Y-m-d'));
        $this->assertFalse($writes->updated, '事前チェックをすり抜けて UPDATE を試みている（monthTaken が効いていない）');
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
     *
     * ⚠ **store と update の 2 入口でループさせる。** store しか叩いていなかったため、
     *   update() 側の `'notes' => '所見'` を削除しても 628 テスト全部が緑だった（Bug #44）。
     */
    public function test_notes_error_says_shoken_on_both_entry_points(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $survey   = $this->makeSurvey($building, '2026-08-01', 5, 5);
        $manager  = $this->manager();
        $long     = str_repeat('あ', 2001);

        $entries = [
            'store' => fn () => $this->actingAs($manager)
                ->from(route('tenant.area-buildings.surveys.create', $building))
                ->post("/tenant/area-buildings/{$building->id}/surveys", [
                    'surveyed_month' => '2026-09',
                    'notes'          => $long,
                ]),
            'update' => fn () => $this->actingAs($manager)
                ->from(route('tenant.area-buildings.surveys.edit', [$building, $survey]))
                ->put("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}", [
                    'surveyed_month' => '2026-08',
                    'notes'          => $long,
                ]),
        ];

        foreach ($entries as $entry => $send) {
            $send()->assertSessionHasErrors('notes');
            $this->assertStringContainsString(
                '所見',
                session('errors')->first('notes'),
                "{$entry}() の項目名が「所見」になっていない（第3引数の上書きが効いていない）"
            );
        }
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

    // ============================================================
    // 描画されたフォームの往復（Bug #28）
    //
    // ⚠ URL を見るだけのテストは **半分しか固定していない**。実測（2026-08-17）で
    //   `@method('PUT')` / `@method('DELETE')` を消しても、edit の action を store へ
    //   向けても、628 テスト全部が緑のままだった。描画したものをそのまま送り返す。
    // ============================================================

    /** 編集フォームを描画 → 何も触らずそのまま送信 → 内容が変わらず、レコードも増えない */
    public function test_edit_form_round_trips_unchanged(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $walker   = $this->actor(UserRole::Staff);
        $survey   = $this->makeSurvey($building, '2026-08-01', 7, 2, 1, [
            'surveyed_by' => $walker->id,
            'notes'       => '1階は改装中',
        ]);
        $manager = $this->manager();

        $html = $this->actingAs($manager)
            ->get("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}/edit")->getContent();

        $update = route('tenant.area-buildings.surveys.update', [$building, $survey]);
        $form   = $this->parseForm($html, 'action="' . $update . '"');

        $this->assertSame('PUT', $form['method'], '@method(\'PUT\') が無い（送信すると 405 になる）');
        $this->assertSame($update, $form['action'], 'action が更新先を向いていない');
        // @csrf の欠落は挙動では検出できない（VerifyCsrfToken がテストでは素通りする）
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が無い');
        $this->assertNotSame('', $form['fields']['_token']);

        // ブラウザと同じく POST + _method スプーフィングで送り返す
        $this->actingAs($manager)->post($form['action'], $form['fields'])
            ->assertRedirect(route('tenant.area-buildings.show', $building));

        $this->assertSame(1, AreaBuildingSurvey::count(), 'レコードが増えている（action が新規登録を向いている）');

        $fresh = $survey->fresh();
        $this->assertSame('2026-08-01', $fresh->surveyed_month->format('Y-m-d'));
        $this->assertSame([7, 2, 1], [$fresh->operating_count, $fresh->vacant_count, $fresh->unknown_count]);
        $this->assertSame($walker->id, $fresh->surveyed_by);
        $this->assertSame('1階は改装中', $fresh->notes);
    }

    /** 削除フォームを描画 → そのまま送信 → 実際に消える */
    public function test_delete_form_round_trips(): void
    {
        $building  = $this->makeBuilding('ミツワビル');
        $survey    = $this->makeSurvey($building, '2026-08-01', 5, 5);
        $executive = $this->executive();

        $html = $this->actingAs($executive)->get("/tenant/area-buildings/{$building->id}")->getContent();

        $destroy = route('tenant.area-buildings.surveys.destroy', [$building, $survey]);
        $form    = $this->parseForm($html, 'action="' . $destroy . '"');

        $this->assertSame('DELETE', $form['method'], '@method(\'DELETE\') が無い（押しても 405 で無反応）');
        $this->assertSame($destroy, $form['action']);
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が無い');

        $this->actingAs($executive)->post($form['action'], $form['fields'])
            ->assertRedirect(route('tenant.area-buildings.show', $building));

        $this->assertDatabaseMissing('area_building_surveys', ['id' => $survey->id]);
    }

    /**
     * 「未指定」に戻す操作が**画面から**できること。
     * ⚠ test_update_can_clear_the_surveyor は `surveyed_by => ''` を直接 POST しているので、
     *   `<option value="">` を画面から消しても緑のままだった。ここは描画された選択肢を使う。
     */
    public function test_surveyor_can_be_cleared_through_the_rendered_form(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $walker   = $this->actor(UserRole::Staff);
        $survey   = $this->makeSurvey($building, '2026-08-01', 5, 5, 0, ['surveyed_by' => $walker->id]);
        $manager  = $this->manager();

        $html = $this->actingAs($manager)
            ->get("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}/edit")->getContent();

        $select = $this->extractSelect($html, 'surveyed_by');
        $this->assertStringContainsString('<option value="">', $select, '「未指定」の選択肢が無い（調査者を外せない）');

        $form = $this->parseForm($html, 'action="' . route('tenant.area-buildings.surveys.update', [$building, $survey]) . '"');
        $form['fields']['surveyed_by'] = '';   // 画面で「未指定」を選ぶ

        $this->actingAs($manager)->post($form['action'], $form['fields'])->assertRedirect();

        $this->assertNull($survey->fresh()->surveyed_by);
    }

    /**
     * 件数欄は空欄スタート（設計 §5.5 / CLAUDE.md の Form 規約「金額 input に value="0" を入れない」）。
     */
    public function test_count_fields_start_blank_on_the_create_form(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $html = $this->actingAs($this->manager())
            ->get("/tenant/area-buildings/{$building->id}/surveys/create")->getContent();

        $form = $this->parseForm($html, 'action="' . route('tenant.area-buildings.surveys.store', $building) . '"');

        foreach (['operating_count', 'vacant_count', 'unknown_count'] as $name) {
            $this->assertSame('', $form['fields'][$name], "{$name} に既定値が入っている（空欄スタートが原則）");
        }
    }

    /**
     * エラーで差し戻されても入力が残ること。
     * ⚠ このリポジトリは Bug #35 で「バリデーションエラーで入力が全消失する」を本番で踏んでいる。
     *   `withInput()` と `old(...)` の**両方**が生きていないと成立しない。
     */
    public function test_input_survives_a_validation_error(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $this->makeSurvey($building, '2026-08-01', 5, 5);   // 重複させる相手
        $manager = $this->manager();
        $create  = route('tenant.area-buildings.surveys.create', $building);

        $payload = [
            'surveyed_month'  => '2026-08',   // 重複 → 差し戻し
            'operating_count' => '7',
            'vacant_count'    => '2',
            'unknown_count'   => '1',
            'notes'           => '1階は改装中',
        ];

        $this->actingAs($manager)->from($create)
            ->post("/tenant/area-buildings/{$building->id}/surveys", $payload)
            ->assertRedirect($create);

        // 差し戻された画面を実際に描画して、入力が残っているかを見る
        $html = $this->actingAs($manager)->get($create)->getContent();
        $form = $this->parseForm($html, 'action="' . route('tenant.area-buildings.surveys.store', $building) . '"');

        foreach ($payload as $name => $value) {
            $this->assertSame($value, $form['fields'][$name], "{$name} が差し戻し後に消えている（Bug #35）");
        }
    }

    // ============================================================
    // バリデーション（Bug #40: SQLite は範囲外の整数を黙って通すので、
    // min:0 / max:9999 は「本番 MySQL 専用の防波堤」。テストで固定しないと見えない）
    // ============================================================

    public function test_invalid_input_is_rejected_on_both_entry_points(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $survey   = $this->makeSurvey($building, '2026-08-01', 5, 5);
        $manager  = $this->manager();

        $cases = [
            '調査年月が空'           => [['surveyed_month' => ''], 'surveyed_month'],
            '調査年月が Y-m-d'        => [['surveyed_month' => '2026-08-01'], 'surveyed_month'],
            '調査年月の年が 5 桁'      => [['surveyed_month' => '99999-08'], 'surveyed_month'],
            '調査年月の月が 13'       => [['surveyed_month' => '2026-13'], 'surveyed_month'],
            '調査年月が全角'          => [['surveyed_month' => '２０２６-０８'], 'surveyed_month'],
            '調査年月が下限より前'     => [['surveyed_month' => '1899-12'], 'surveyed_month'],
            '営業が負'               => [['operating_count' => -5], 'operating_count'],
            '空きが上限超'            => [['vacant_count' => 10000], 'vacant_count'],
            '不明が小数'              => [['unknown_count' => 3.7], 'unknown_count'],
            '調査者が存在しない ID'    => [['surveyed_by' => 999999], 'surveyed_by'],
            '所見が長すぎる'          => [['notes' => str_repeat('あ', 2001)], 'notes'],
        ];

        foreach ($cases as $label => [$override, $key]) {
            // --- store ---
            $response = $this->actingAs($manager)->from(route('tenant.area-buildings.surveys.create', $building))
                ->post("/tenant/area-buildings/{$building->id}/surveys", array_merge(['surveyed_month' => '2026-09'], $override));

            // ⚠ 302 であることを必ず見る。exists ルールを外すと FK 違反で 500 になるが、
            //   「レコードが増えない」だけを見ていると 500 でも緑になって見逃す
            $response->assertStatus(302);
            $this->assertContains($key, $this->errorKeys(), "store / {$label}: {$key} のエラーが出ていない");
            $this->assertSame(1, $building->surveys()->count(), "store / {$label}: 不正な調査回が作られた");

            // --- update ---
            $response = $this->actingAs($manager)->from(route('tenant.area-buildings.surveys.edit', [$building, $survey]))
                ->put("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}", array_merge(['surveyed_month' => '2026-08'], $override));

            $response->assertStatus(302);
            $this->assertContains($key, $this->errorKeys(), "update / {$label}: {$key} のエラーが出ていない");
            $this->assertSame(
                ['2026-08-01', 5, 5, 0],
                [$survey->fresh()->surveyed_month->format('Y-m-d'), $survey->fresh()->operating_count,
                    $survey->fresh()->vacant_count, $survey->fresh()->unknown_count],
                "update / {$label}: 不正な値が保存された"
            );
        }
    }

    /**
     * `exists:users,id` を **SoftDeletes で締めてはいけない**ことを固定する（方向 1）。
     *
     * ⚠ コントローラに「厳しくすべきと後から締めないこと」と書いてあるのに、それを見る
     *   テストが無かった（Bug #45「警告を書いた本人がその罠をテストに作り込む」と同型）。
     *   締めると、退職者を調査者に持つ調査回は選択肢に退職者が残る（Bug #12 対策）ため
     *   **画面が出す値のままでは保存できず、その回が永久に編集不能**になる。
     */
    public function test_a_retired_surveyor_can_still_be_saved_from_the_form(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $retired  = $this->actor(UserRole::Staff);
        $survey   = $this->makeSurvey($building, '2026-08-01', 5, 5, 0, ['surveyed_by' => $retired->id]);
        $retired->delete();   // 退職（SoftDeletes）
        $manager = $this->manager();

        $html = $this->actingAs($manager)
            ->get("/tenant/area-buildings/{$building->id}/surveys/{$survey->id}/edit")->getContent();

        $form = $this->parseForm($html, 'action="' . route('tenant.area-buildings.surveys.update', [$building, $survey]) . '"');
        $this->assertSame((string) $retired->id, $form['fields']['surveyed_by'], '退職者が既定値から消えている');

        $form['fields']['operating_count'] = '8';   // 件数だけ直す、という普通の編集

        $this->actingAs($manager)->post($form['action'], $form['fields'])
            ->assertRedirect(route('tenant.area-buildings.show', $building));

        $fresh = $survey->fresh();
        $this->assertSame(8, $fresh->operating_count, '退職者を調査者に持つ調査回が編集不能になっている');
        $this->assertSame($retired->id, $fresh->surveyed_by);
    }

    // ============================================================
    // 同時送信（設計 §3.2「衝突したら確認を出す」＝ 500 は仕様違反）
    // ============================================================

    /**
     * 事前チェックと INSERT の間に他の送信が割り込んでも 500 にせず差し戻すこと。
     * ⚠ 送信ボタンは無効化されないのでダブルクリックが現実的な引き金。
     *   `creating` フックで衝突する行を割り込ませて TOCTOU を決定的に再現する。
     */
    public function test_a_concurrent_duplicate_insert_is_shown_as_a_validation_error(): void
    {
        $building = $this->makeBuilding('ミツワビル');
        $manager  = $this->manager();
        $create   = route('tenant.area-buildings.surveys.create', $building);

        AreaBuildingSurvey::creating(function () use ($building) {
            // 事前チェックを通過した「あと」に、別リクエストが同じ年月を入れた状態を作る
            DB::table('area_building_surveys')->insert([
                'area_building_id' => $building->id,
                'surveyed_month'   => '2026-08-01 00:00:00',
                'operating_count'  => 1,
                'vacant_count'     => 1,
                'unknown_count'    => 0,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        });

        $response = $this->actingAs($manager)->from($create)
            ->post("/tenant/area-buildings/{$building->id}/surveys", ['surveyed_month' => '2026-08']);

        $response->assertRedirect($create);   // 500 ではない
        $response->assertSessionHasErrors('surveyed_month');
        $this->assertStringContainsString('既に登録されています', session('errors')->first('surveyed_month'));
        $this->assertSame(1, $building->surveys()->count(), '割り込みぶんに加えて二重登録されている');
    }

    /**
     * 書き込みが試みられたかを監視する。
     *
     * ⚠ **重複年月は「事前チェック（monthTaken）で止める」のが正で、DB の UNIQUE 制約は
     *   同時送信のための最後の砦**。TOCTOU 用の try/catch を入れた結果、`whereDate` を
     *   `where` に戻す変異が**検出できなくなった**（例外側が同じ差し戻しを返すので HTTP から
     *   見ると区別が付かない）。安全網が主機構のカバレッジを食う典型なので、
     *   「INSERT / UPDATE を試みていないこと」まで見て主機構を固定する。
     */
    private function watchWrites(): object
    {
        $seen = new class
        {
            public bool $created = false;

            public bool $updated = false;
        };

        AreaBuildingSurvey::creating(function () use ($seen): void {
            $seen->created = true;
        });
        AreaBuildingSurvey::updating(function () use ($seen): void {
            $seen->updated = true;
        });

        return $seen;
    }

    /**
     * バリデーションエラーのキー一覧。
     * ⚠ `session('errors')` は assertSessionHasErrors を通すまで生の配列なので両対応にする。
     *
     * @return list<string>
     */
    private function errorKeys(): array
    {
        $errors = session('errors');

        if (is_array($errors)) {
            return array_keys($errors['default']['messages'] ?? []);
        }

        return $errors === null ? [] : array_keys($errors->getBag('default')->getMessages());
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
