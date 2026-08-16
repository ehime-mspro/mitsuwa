<?php

namespace Tests\Feature\Tenant;

use App\Enums\AreaTenantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AreaBuildingShowTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    public function test_staff_can_view_the_detail(): void
    {
        $building = $this->makeBuilding('ミツワビル');

        $this->actingAs($this->staff())
            ->get("/tenant/area-buildings/{$building->id}")
            ->assertOk()
            ->assertSee('ミツワビル');
    }

    /** 調査 0 件・テナント 0 件でも落ちない（Bug #27 型） */
    public function test_detail_renders_with_no_surveys_and_no_tenants(): void
    {
        $building = $this->makeBuilding('からっぽビル');

        $response = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");

        $response->assertOk();
        $this->assertNull($response->viewData('latestSurvey'));
        $this->assertNull($response->viewData('divergence'));
    }

    /**
     * 詳細に埋め込み地図を置かない（設計 §6.0 の費用方針）。
     * ⚠ この方針が崩れたら赤になる。Maps を読むと 1 棟開くたびに Dynamic Maps が課金される。
     */
    public function test_detail_does_not_load_the_maps_javascript_api(): void
    {
        $building = $this->makeBuilding('座標ありビル', ['latitude' => 33.8392, 'longitude' => 132.7657]);

        $html = $this->actingAs($this->staff())
            ->get("/tenant/area-buildings/{$building->id}")
            ->getContent();

        $this->assertStringNotContainsString('maps.googleapis.com', $html, '詳細に埋め込み地図が入り込んでいる');
        $this->assertStringNotContainsString('new google.maps.Map', $html);
    }

    public function test_detail_links_out_to_google_maps_when_coordinates_exist(): void
    {
        $building = $this->makeBuilding('座標ありビル', ['latitude' => 33.8392, 'longitude' => 132.7657]);

        $this->actingAs($this->staff())
            ->get("/tenant/area-buildings/{$building->id}")
            ->assertSee('https://www.google.com/maps/search/?api=1&amp;query=33.8392000,132.7657000', false)
            ->assertSee('Google マップで開く');
    }

    public function test_detail_shows_a_prompt_when_coordinates_are_missing(): void
    {
        $building = $this->makeBuilding('座標なしビル');

        $this->actingAs($this->staff())
            ->get("/tenant/area-buildings/{$building->id}")
            ->assertSee('位置未登録')
            ->assertDontSee('Google マップで開く');
    }

    /** 調査履歴は年月の降順 */
    public function test_surveys_are_listed_newest_first(): void
    {
        $building = $this->makeBuilding('履歴ビル');
        $this->makeSurvey($building, '2025-06-01', 5, 5);
        $this->makeSurvey($building, '2026-08-01', 8, 2);

        $response = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");

        $months = collect($response->viewData('surveys'))->map(fn ($s) => $s->monthLabel())->all();
        $this->assertSame(['2026年8月', '2025年6月'], $months);
    }

    // ============================================================
    // 乖離警告（設計 §5.4 / Bug #46）— 3 通り
    // ============================================================

    /** ① 明細が食い違うとき出る */
    public function test_divergence_warning_appears_when_counts_disagree(): void
    {
        $building = $this->makeBuilding('食い違いビル');
        $this->makeSurvey($building, '2026-08-01', 3, 1, 0);          // 入力値: 営業3 / 空き1 / 不明0
        $this->makeTenant($building, ['name' => 'A', 'status' => AreaTenantStatus::Operating->value]);
        $this->makeTenant($building, ['name' => 'B', 'status' => AreaTenantStatus::Operating->value]);
        // 明細は 営業2 / 空き0 / 不明0 なので食い違う

        $response = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");

        $divergence = $response->viewData('divergence');
        $this->assertNotNull($divergence, '乖離しているのに警告が出ていない');
        $this->assertSame(['operating' => 3, 'vacant' => 1, 'unknown' => 0], $divergence['input']);
        $this->assertSame(['operating' => 2, 'vacant' => 0, 'unknown' => 0], $divergence['counted']);

        // ⚠ 件数だけを assertSee で見ない。同じ数字が調査履歴の行にも出るので false-pass する
        //   （Bug #43 / #40）。警告ブロック固有の文言で見る。
        $response->assertSee('調査時の実測とテナント明細が一致していません');
    }

    /** ② 一致するときは出ない */
    public function test_no_warning_when_counts_agree(): void
    {
        $building = $this->makeBuilding('一致ビル');
        $this->makeSurvey($building, '2026-08-01', 2, 1, 0);
        $this->makeTenant($building, ['name' => 'A', 'status' => AreaTenantStatus::Operating->value]);
        $this->makeTenant($building, ['name' => 'B', 'status' => AreaTenantStatus::Operating->value]);
        $this->makeTenant($building, ['name' => null, 'status' => AreaTenantStatus::Vacant->value]);

        $response = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");

        $this->assertNull($response->viewData('divergence'));
        $response->assertDontSee('調査時の実測とテナント明細が一致していません');
    }

    /** ③ 明細 0 行のビルでは比較しない（明細を入れていないだけで警告が出ると意味がない） */
    public function test_no_warning_when_there_are_no_tenant_rows(): void
    {
        $building = $this->makeBuilding('明細未入力ビル');
        $this->makeSurvey($building, '2026-08-01', 9, 1, 0);

        $response = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");

        $this->assertNull($response->viewData('divergence'));
        $response->assertDontSee('調査時の実測とテナント明細が一致していません');
    }

    /**
     * ④ 調査が 1 件も無いのにテナント明細だけあるビル（Important I-1）。
     *
     * ⚠ Task 8 の初回調査入力は任意なので「ビルだけ登録 → テナント明細を先に入れる」は
     *   普通に起こる操作。divergence() の `$latest === null ||` を消す変異を当てても、
     *   このテストが無いと 11 本すべて緑のまま通ってしまう（実測確認済み）。
     *   ガードを外すと $latest->operating_count で
     *   `Attempt to read property "operating_count" on null` → 500 になる。
     */
    public function test_no_crash_when_tenants_exist_but_no_survey_at_all(): void
    {
        $building = $this->makeBuilding('調査なしテナントありビル');
        $this->makeTenant($building, ['name' => 'A', 'status' => AreaTenantStatus::Operating->value]);

        $response = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");

        $response->assertOk();
        $this->assertNull($response->viewData('divergence'));
    }

    /** 退去済みは明細集計に入れない */
    public function test_moved_out_tenants_are_excluded_from_the_counted_side(): void
    {
        $building = $this->makeBuilding('退去ありビル');
        $this->makeSurvey($building, '2026-08-01', 1, 0, 0);
        $this->makeTenant($building, ['name' => '現', 'status' => AreaTenantStatus::Operating->value]);
        $this->makeTenant($building, ['name' => '退', 'status' => AreaTenantStatus::Operating->value, 'moved_out_on' => '2026-07-31']);

        $response = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");

        $this->assertNull($response->viewData('divergence'), '退去済みを数えてしまっている');
        $this->assertCount(1, $response->viewData('activeTenants'));
        $this->assertCount(1, $response->viewData('movedOutTenants'));
    }

    /**
     * 現況 0 件・退去済みのみのビル（Minor M-2）。
     *
     * ⚠ 既存の test_moved_out_tenants_are_excluded_from_the_counted_side は現況 1 件 +
     *   退去済み 1 件なので、現況テーブルが空になる経路（@forelse の @empty 側）を
     *   一度も通っていなかった。
     */
    public function test_moved_out_only_building_shows_empty_current_table_and_details(): void
    {
        $building = $this->makeBuilding('退去済みだけのビル');
        $this->makeTenant($building, [
            'name'         => '退去済みテナント',
            'status'       => AreaTenantStatus::Operating->value,
            'moved_out_on' => '2026-07-31',
        ]);

        $response = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");

        $response->assertOk();
        $this->assertCount(0, $response->viewData('activeTenants'));
        $this->assertCount(1, $response->viewData('movedOutTenants'));
        $response->assertSee('入居テナントの明細がありません。');
        $response->assertSee('退去済み 1 件を表示');
    }

    /**
     * 空室率などの下流の値は常に入力値を正とする（設計 §5.4）。
     * ⚠ 明細に寄せると、明細が途中までしか入っていないビルの数字が壊れる。
     */
    public function test_vacancy_rate_follows_the_input_values_not_the_breakdown(): void
    {
        $building = $this->makeBuilding('入力値が正ビル');
        $this->makeSurvey($building, '2026-08-01', 5, 5, 0);   // 入力値ベースなら 50.0%
        $this->makeTenant($building, ['name' => 'A', 'status' => AreaTenantStatus::Operating->value]);

        $response = $this->actingAs($this->staff())->get("/tenant/area-buildings/{$building->id}");

        $this->assertSame(50.0, $response->viewData('latestSurvey')->vacancyRate());
    }
}
