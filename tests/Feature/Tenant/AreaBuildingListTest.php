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
            $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?vacancy=over25'))
        );
        $this->assertSame(
            ['率50'],
            $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?vacancy=over50'))
        );
    }

    /**
     * 空室率フィルタの境界は「以上」（inclusive）。
     *
     * ⚠ 上の test_vacancy_bands は 10% / 30% / 50% しか使っておらず、
     *   等号側を一度も踏んでいない(`>=` を `>` に変異させても緑のまま)。
     *   ちょうど 25.0% / ちょうど 50.0% のビルを作って等号側を固定し、
     *   境界のすぐ下(24.9% / 49.9%)が含まれないことで両側から挟む。
     *
     * ⚠ 閾値は VacancyRate::BAND_MID / BAND_HIGH に集約済み。20 / 40 に戻すとここが赤になる。
     */
    public function test_vacancy_band_boundaries_are_inclusive_at_25_and_50_percent(): void
    {
        $this->makeSurvey($this->makeBuilding('率ちょうど25'), '2026-08-01', 3, 1);      // 25.0%
        $this->makeSurvey($this->makeBuilding('率ちょうど50'), '2026-08-01', 5, 5);      // 50.0%
        $this->makeSurvey($this->makeBuilding('率24.9'), '2026-08-01', 301, 100);        // 24.9%
        $this->makeSurvey($this->makeBuilding('率49.9'), '2026-08-01', 501, 500);        // 49.9%

        $staff = $this->staff();

        $over25 = $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?vacancy=over25'));
        $this->assertContains('率ちょうど25', $over25, 'ちょうど 25.0% が over25 に含まれていない（境界が inclusive でない）');
        $this->assertNotContains('率24.9', $over25, '24.9%（25.0% 未満）が over25 に含まれてしまっている');

        $over50 = $this->listedNames($this->actingAs($staff)->get('/tenant/area-buildings?vacancy=over50'));
        $this->assertContains('率ちょうど50', $over50, 'ちょうど 50.0% が over50 に含まれていない（境界が inclusive でない）');
        $this->assertNotContains('率49.9', $over50, '49.9%（50.0% 未満）が over50 に含まれてしまっている');
    }

    /** 「不明」は空き扱いなので率のバンドに効く（VacancyRate を通っている証拠） */
    public function test_unknown_counts_toward_the_vacancy_band(): void
    {
        $this->makeSurvey($this->makeBuilding('不明だらけ'), '2026-08-01', 5, 0, 5);

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings?vacancy=over50');

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

    /**
     * `?keyword[]=x` のように配列で来ても 500 にならない。
     *
     * ⚠ プランのコードには無かったが、実装時のセルフレビューで発見（load-bearing）。
     *   `"%" . $keyword . "%"` に配列を渡すと ErrorException: Array to string conversion で
     *   500 になる（実測確認済み）。ProcurementListService::applyKeyword() が同じ形の
     *   防御を持つ既知パターン。
     */
    public function test_keyword_as_array_does_not_500(): void
    {
        $this->makeBuilding('大街道ビル');

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings?keyword[]=x');

        $response->assertOk();
    }

    /**
     * `?year[]=x` のように配列で来ても 500 にならない。
     *
     * ⚠ プランのコードには無かったが、実装時のセルフレビューで発見（load-bearing）。
     *   ビューの `(string) request('year') === (string) $year` が配列に `(string)` キャストを
     *   かけると同じ Array to string conversion で 500 になる（実測確認済み）。
     *   ⚠ `$surveyYears` が空だと `@foreach` 本体（危険な行）自体が実行されず空振りするため、
     *      調査データを 1 件作ってからでないとこの回帰は検出できない。
     */
    public function test_year_as_array_does_not_500(): void
    {
        $this->makeSurvey($this->makeBuilding('大街道ビル'), '2026-08-01', 5, 5);

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings?year[]=x');

        $response->assertOk();
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
     * ちょうど 20 件では次ページが無い（$perPage=20 の境界の片側）。
     *
     * ⚠ コード品質レビュー M-2/M-3（2026-08-16）で指摘: 上の test_paginates_at_twenty_per_page は
     *   25 件で試しており、「ちょうど 20 件のとき hasPages() が false」の分岐を一度も
     *   通っていなかった。
     */
    public function test_has_no_next_page_at_exactly_twenty_buildings(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->makeBuilding(sprintf('ビル%02d', $i));
        }

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings');

        $response->assertOk();
        $paginator = $response->viewData('rows');
        $this->assertSame(20, $paginator->total());
        $this->assertFalse($paginator->hasPages(), 'ちょうど 20 件なのに次ページが出ている（$perPage が 20 でない）');
    }

    /** 21 件では 2 ページ目ができる（$perPage=20 の境界のもう片側） */
    public function test_has_a_second_page_at_twenty_one_buildings(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $this->makeBuilding(sprintf('ビル%02d', $i));
        }

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings');

        $response->assertOk();
        $paginator = $response->viewData('rows');
        $this->assertSame(21, $paginator->total());
        $this->assertTrue($paginator->hasPages(), '21 件なのに次ページが出ていない（$perPage が 20 でない）');
        $this->assertSame(2, $paginator->lastPage());
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

    /**
     * Step 7b: 論理削除済みのビルの調査年は「調査年」フィルタの選択肢に出ない。
     *
     * ⚠ Task 6 レビュー時点では削除機能が無く到達不能だった欠陥。AreaBuildingSurvey に
     *   SoftDeletes は無いので、ビルを消しても調査回の行自体は残る。surveyYears() が
     *   AreaBuildingSurvey を素通しで引くと、消えたビルの年が選択肢に残ったまま
     *   一覧側は AreaBuilding の SoftDeletes スコープで正しく除外されるため
     *   「選べるのに選ぶと 0 件」という不整合になる。
     */
    public function test_survey_years_excludes_soft_deleted_buildings(): void
    {
        $building = $this->makeBuilding('消えるビル');
        $this->makeSurvey($building, '2020-06-01', 5, 5);
        $this->makeSurvey($this->makeBuilding('残るビル'), '2026-08-01', 5, 5);

        $building->delete();

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings');

        $response->assertOk();
        $this->assertNotContains(2020, $response->viewData('surveyYears'), '削除済みビルの調査年が選択肢に残っている');
        $this->assertContains(2026, $response->viewData('surveyYears'));
    }

    /** 日本語を含む配列を安定した順で比較するためのヘルパー */
    private function sortedJa(array $names): array
    {
        usort($names, 'strcmp');

        return $names;
    }

    /**
     * 閾値の直値がサービス側に残っていないこと。
     *
     * ⚠ 値のテストだけでは守れない。`>= 25.0` と直書きしても上のテストは緑のままで、
     *   地図の凡例（VacancyRate::LEVELS）と別々に動く状態が残る（Bug #41 / #42 ②）。
     * ⚠ コメントを落としてから測る。docblock に「25%」と書いてあると false-pass する。
     */
    public function test_vacancy_filter_reads_the_shared_band_constants(): void
    {
        $source = $this->sourceWithoutComments(app_path('Services/Tenant/AreaBuildingListService.php'));

        $this->assertStringContainsString('VacancyRate::BAND_MID', $source, 'フィルタが共有の閾値定数を見ていない');
        $this->assertStringContainsString('VacancyRate::BAND_HIGH', $source, 'フィルタが共有の閾値定数を見ていない');
        $this->assertDoesNotMatchRegularExpression('/>=\s*\d+\.\d+/', $source, '閾値の直値がフィルタに残っている');
    }

    /** コメント（`//` と docblock）を落としたソース。Bug #42 ② の false-pass 対策 */
    private function sourceWithoutComments(string $path): string
    {
        $out = '';

        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
