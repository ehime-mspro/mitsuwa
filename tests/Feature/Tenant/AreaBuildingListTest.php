<?php

namespace Tests\Feature\Tenant;

use Illuminate\Foundation\Testing\RefreshDatabase;

class AreaBuildingListTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    /** 閲覧は全ロール（設計 §8） */
    public function test_staff_can_view_the_list(): void
    {
        $this->actingAs($this->staff())->get('/tenant/area-buildings')->assertOk();
    }

    /** データが 1 件も無くても落ちない（Bug #27 型） */
    public function test_empty_data_renders(): void
    {
        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings');

        $response->assertOk();
        $response->assertSee('周辺ビル調査');
        $this->assertSame([], $this->listedNames($response));
    }

    /** 調査 0 件のビルも一覧に出る（率は「—」） */
    public function test_building_without_any_survey_is_listed(): void
    {
        $this->makeBuilding('未調査ビル');

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings');

        $response->assertOk();
        $this->assertSame(['未調査ビル'], $this->listedNames($response));
    }

    /**
     * 「空室率: 全て」= 空値。
     *
     * ⚠ 実 HTTP でしか再現しない。ConvertEmptyStringsToNull は HTTP ミドルウェアなので
     *   Request::create() では '' のまま届き、この欠陥を原理的に検出できない（Bug #31）。
     */
    public function test_empty_vacancy_filter_means_all_over_real_http(): void
    {
        $this->makeSurvey($this->makeBuilding('満室ビル'), '2026-08-01', 10, 0);
        $this->makeSurvey($this->makeBuilding('空きビル'), '2026-08-01', 5, 5);
        $this->makeBuilding('未調査ビル');

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings?vacancy=');

        $response->assertOk();
        $this->assertCount(3, $this->listedNames($response), '「全て」なのに絞り込まれている');
    }

    public function test_vacancy_bands(): void
    {
        $this->makeSurvey($this->makeBuilding('満室'), '2026-08-01', 10, 0);
        $this->makeSurvey($this->makeBuilding('率10'), '2026-08-01', 9, 1);
        $this->makeSurvey($this->makeBuilding('率30'), '2026-08-01', 7, 3);
        $this->makeSurvey($this->makeBuilding('率50'), '2026-08-01', 5, 5);
        $this->makeBuilding('未調査');

        $staff = $this->staff();

        $this->assertSame(
            ['満室'],
            $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?vacancy=full'))
        );
        $this->assertSame(
            ['率50', '率30', '率10'],
            $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?vacancy=any'))
        );
        $this->assertSame(
            ['率50', '率30'],
            $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?vacancy=over20'))
        );
        $this->assertSame(
            ['率50'],
            $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?vacancy=over40'))
        );
    }

    /** 「不明」は空き扱いなので率のバンドに効く（VacancyRate を通っている証拠） */
    public function test_unknown_counts_toward_the_vacancy_band(): void
    {
        $this->makeSurvey($this->makeBuilding('不明だらけ'), '2026-08-01', 5, 0, 5);

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings?vacancy=over40');

        $this->assertSame(['不明だらけ'], $this->listedNames($response));
    }

    /** 調査年フィルタは「最終調査年月」の年で見る */
    public function test_year_filter_uses_the_latest_survey(): void
    {
        $old = $this->makeBuilding('2025年止まり');
        $this->makeSurvey($old, '2025-06-01', 5, 5);

        $updated = $this->makeBuilding('2026年に再調査');
        $this->makeSurvey($updated, '2025-06-01', 5, 5);
        $this->makeSurvey($updated, '2026-08-01', 5, 5);

        $staff = $this->staff();

        $this->assertSame(
            ['2026年に再調査'],
            $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?year=2026'))
        );
        $this->assertSame(
            ['2025年止まり'],
            $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?year=2025'))
        );
    }

    public function test_keyword_searches_name_address_and_current_tenants(): void
    {
        $this->makeBuilding('大街道ビル');
        $this->makeBuilding('別名ビル', ['address' => '愛媛県松山市大街道1-1']);

        $withTenant = $this->makeBuilding('テナント持ちビル');
        $this->makeTenant($withTenant, ['name' => '大街道珈琲']);

        $this->makeBuilding('無関係ビル');

        $names = $this->listedNames(
            $this->actingAs($this->staff())->get('/tenant/area-buildings?keyword=' . urlencode('大街道'))
        );

        sort($names);
        // ⚠ 期待値はプラン原文では ['大街道ビル', 'テナント持ちビル', '別名ビル'] だったが、
        //   strcmp によるバイト順は Unicode コードポイント順（テ=U+30C6 < 別=U+5225 < 大=U+5927）
        //   になるため実際には成立しない（実測で確認・プラン Task 6 節側も訂正済み）。
        $this->assertSame(['テナント持ちビル', '別名ビル', '大街道ビル'], $this->sortedJa($names));
    }

    /** 退去済みテナント名では拾わない（もう居ない会社でヒットさせない。設計 §5.3） */
    public function test_keyword_ignores_moved_out_tenants(): void
    {
        $building = $this->makeBuilding('退去済みだけのビル');
        $this->makeTenant($building, ['name' => '撤退カフェ', 'moved_out_on' => '2026-07-31']);

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings?keyword=' . urlencode('撤退カフェ'));

        $this->assertSame([], $this->listedNames($response));
    }

    /** 既定の並び順は空室率降順・未調査は末尾（設計 §5.3） */
    public function test_default_order_is_vacancy_rate_desc_with_unsurveyed_last(): void
    {
        $this->makeBuilding('あ未調査');
        $this->makeSurvey($this->makeBuilding('い率10'), '2026-08-01', 9, 1);
        $this->makeSurvey($this->makeBuilding('う率50'), '2026-08-01', 5, 5);
        $this->makeSurvey($this->makeBuilding('え率0'), '2026-08-01', 8, 0);

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings');

        $this->assertSame(['う率50', 'い率10', 'え率0', 'あ未調査'], $this->listedNames($response));
    }

    public function test_paginates_at_twenty_per_page(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->makeBuilding(sprintf('ビル%02d', $i));
        }

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings');

        $response->assertOk();
        $this->assertCount(20, $this->listedNames($response));
        $this->assertSame(25, $response->viewData('rows')->total());
    }

    /**
     * ページ送りでフィルタが維持されること。
     *
     * ⚠ URL を自分で組み立ててはいけない（`?keyword=x&page=2` と書くと、リンクが壊れていても
     *   必ず緑になる）。1 ページ目を描画させて nextPageUrl() を実際に辿る（Bug #31）。
     *
     * ⚠ このテストが検出するのは「appends / withQueryString を丸ごと忘れた」場合まで。
     *   `?? ''` のマッピング（null キーが http_build_query に捨てられる件）は、本機能では
     *   どのフィルタも既定が「全て」なので原理的に区別できない（プラン §1-3）。
     */
    public function test_pagination_keeps_the_keyword_filter(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->makeBuilding(sprintf('松山ビル%02d', $i));
        }
        $this->makeBuilding('別エリアビル');

        $first = $this->actingAs($this->staff())->get('/tenant/area-buildings?keyword=' . urlencode('松山'));
        $first->assertOk();
        $this->assertSame(25, $first->viewData('rows')->total());

        $nextUrl = $first->viewData('rows')->nextPageUrl();
        $this->assertNotNull($nextUrl, '2 ページ目のリンクが出ていない');
        $this->assertStringContainsString('keyword=', $nextUrl, 'ページ送りリンクから keyword が落ちている');

        $second = $this->actingAs($this->staff())->get($nextUrl);
        $second->assertOk();
        $this->assertSame(25, $second->viewData('rows')->total(), '2 ページ目でフィルタが飛んでいる');
        $this->assertCount(5, $this->listedNames($second));
    }

    /** サイドバーに導線がある（両方のブロックを直したことの確認は Step 8 の変異で行う） */
    public function test_sidebar_has_the_link(): void
    {
        $this->actingAs($this->staff())
            ->get('/tenant/area-buildings')
            ->assertSee('周辺ビル調査');
    }

    /** 日本語を含む配列を安定した順で比較するためのヘルパー */
    private function sortedJa(array $names): array
    {
        usort($names, 'strcmp');

        return $names;
    }
}
