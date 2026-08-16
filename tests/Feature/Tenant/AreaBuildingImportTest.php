<?php

namespace Tests\Feature\Tenant;

use App\Enums\AreaTenantStatus;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Models\AreaBuildingTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * 周辺ビル調査の Excel 取込（設計 §7 / プラン Task 11）。
 *
 * ⚠ サーバ側の経路しか通らないテストであることを忘れないこと。SheetJS の解析・
 *   列マッピング・プレビューが壊れていても、ここは JSON を PHP から手で送るので
 *   全部緑のまま通る（Bug #28 / #35 と同じ構図）。画面側は
 *   ・SRI が実測値と一致すること（test_sheetjs_is_loaded_from_jsdelivr_with_sri）
 *   ・option を x-for で作らないこと（test_mapping_selects_do_not_use_x_for）
 *   ・確定フォームの配線（test_the_confirm_form_round_trips_to_the_execute_route）
 *   だけを構造で固定し、実挙動はブラウザで確かめる（プラン Step 10）。
 */
class AreaBuildingImportTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    /**
     * SheetJS の SRI ハッシュ（2026-08-17 実測）。
     *
     * ⚠ **literal で固定する。** `/integrity="sha384-[A-Za-z0-9+\/=]+"/` のような
     *   「sha384- で始まる何か」の正規表現だと、**打ち間違えたハッシュでも緑になる**。
     *   SRI が一致しないとブラウザはスクリプトを**黙って読み込まない**（コンソールにだけ出る）ので、
     *   取込画面が無反応になっているのにテストも view:cache も全部通る＝ Bug #28 と同型。
     *   jsDelivr のバージョン固定 URL は不変なので固定値でよい。
     *
     * 実測方法:
     *   curl -sL https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js \
     *     | openssl dgst -sha384 -binary | openssl base64 -A
     *   unpkg の同版とバイト一致することも確認済み（CDN 片側の改竄でないことの裏取り）。
     */
    private const SHEETJS_SRI = 'sha384-vtjasyidUo0kW94K5MXDXntzOJpQgBKXmE7e2Ga4LG0skTTLeBi97eFAXsqewJjw';

    private const IMPORT_URL = '/tenant/area-buildings/import';

    private function importBuildings(array $rows, string $month = '2026-08')
    {
        return $this->actingAs($this->manager())->post(self::IMPORT_URL, [
            'kind'           => 'buildings',
            'surveyed_month' => $month,
            'rows'           => json_encode($rows),
        ]);
    }

    private function importTenants(array $rows)
    {
        return $this->actingAs($this->manager())->post(self::IMPORT_URL, [
            'kind' => 'tenants',
            'rows' => json_encode($rows),
        ]);
    }

    /**
     * 「主機構が仕事をしたか」を応答ではなく書き込みで見る（Bug #48）。
     *
     * ⚠ 同一年月のスキップは ①コントローラの事前チェック ②DB の UNIQUE 制約 + catch の
     *   2 段で守っている。②が同じ「スキップ 1 件」を返すので、**応答からは①の死を
     *   区別できない**。INSERT を試みていないことまで見て、はじめて①が独立に固定される。
     */
    private function watchWrites(): object
    {
        $seen = new class
        {
            public bool $created = false;
        };

        AreaBuildingSurvey::creating(function () use ($seen): void {
            $seen->created = true;
        });

        return $seen;
    }

    // ============================================================
    // ルート / 権限
    // ============================================================

    public function test_staff_cannot_reach_the_import_screen(): void
    {
        $this->actingAs($this->staff())->get(self::IMPORT_URL)->assertForbidden();
        $this->actingAs($this->staff())->post(self::IMPORT_URL, [])->assertForbidden();
    }

    /** ⚠ /import が {building} の ID として解釈されないこと */
    public function test_import_route_is_not_swallowed_by_the_show_route(): void
    {
        $this->actingAs($this->manager())
            ->get(self::IMPORT_URL)
            ->assertOk()
            ->assertViewIs('tenant.area-buildings.import');
    }

    /** 一覧からの導線。閲覧専用ロールには出さない */
    public function test_the_index_links_to_the_import_screen_for_managers_only(): void
    {
        $importUrl = route('tenant.area-buildings.import');

        $this->actingAs($this->manager())->get('/tenant/area-buildings')
            ->assertOk()
            ->assertSee($importUrl, false);

        $this->actingAs($this->staff())->get('/tenant/area-buildings')
            ->assertOk()
            ->assertDontSee($importUrl, false);
    }

    // ============================================================
    // ビル＋調査（設計 §7.1）
    // ============================================================

    public function test_creates_new_buildings_with_a_survey(): void
    {
        $this->importBuildings([
            ['name' => 'アルファビル', 'address' => '松山市1-1', 'total_floors' => '5', 'operating' => '4', 'vacant' => '1', 'unknown' => '0'],
            ['name' => 'ベータビル',   'address' => '松山市2-2', 'total_floors' => '3', 'operating' => '3', 'vacant' => '0', 'unknown' => '0'],
        ])->assertRedirect(route('tenant.area-buildings.index'));

        $this->assertSame(2, AreaBuilding::count());
        $this->assertSame(2, AreaBuildingSurvey::count());

        $alpha = AreaBuilding::where('name', 'アルファビル')->firstOrFail();
        $this->assertSame('松山市1-1', $alpha->address);
        $this->assertSame(5, $alpha->total_floors);

        $survey = $alpha->surveys()->firstOrFail();
        $this->assertSame('2026-08-01', $survey->surveyed_month->format('Y-m-d'));
        $this->assertSame([4, 1, 0], [$survey->operating_count, $survey->vacant_count, $survey->unknown_count]);
        $this->assertNotNull($survey->surveyed_by, '取込を実行したユーザーが調査者に入っていない');
    }

    /** 既存ビルにはビル名で突合して調査回だけ足す */
    public function test_matches_an_existing_building_by_name(): void
    {
        $existing = $this->makeBuilding('アルファビル', ['address' => '既存の住所', 'total_floors' => 9]);

        $this->importBuildings([
            ['name' => ' アルファビル ', 'address' => 'Excel の住所', 'total_floors' => '5', 'operating' => '4', 'vacant' => '1'],
        ])->assertRedirect();

        $this->assertSame(1, AreaBuilding::count(), '同じビルが二重に作られている');

        $existing->refresh();
        $this->assertSame('既存の住所', $existing->address, '既存の値が上書きされている');
        $this->assertSame(9, $existing->total_floors, '既存の値が上書きされている');
        $this->assertSame(1, $existing->surveys()->count());
    }

    /** 空の項目だけ Excel の値で補完する（設計 §7.1） */
    public function test_fills_only_the_blank_fields_of_an_existing_building(): void
    {
        $existing = $this->makeBuilding('アルファビル');   // address / total_floors とも null

        $this->importBuildings([
            ['name' => 'アルファビル', 'address' => 'Excel の住所', 'total_floors' => '5', 'operating' => '1'],
        ])->assertRedirect();

        $existing->refresh();
        $this->assertSame('Excel の住所', $existing->address);
        $this->assertSame(5, $existing->total_floors);
    }

    /** 同じビル・同じ調査年月は取り込まずスキップし、件数を報告する（設計 §7.1） */
    public function test_skips_a_survey_that_already_exists_for_the_same_month(): void
    {
        $existing = $this->makeBuilding('アルファビル');
        $this->makeSurvey($existing, '2026-08-01', 9, 1);
        $writes = $this->watchWrites();

        $this->importBuildings([
            ['name' => 'アルファビル', 'operating' => '4', 'vacant' => '1'],
        ]);

        $this->assertSame(1, $existing->surveys()->count());
        $this->assertSame(9, $existing->surveys()->first()->operating_count, '既存の調査が上書きされている');
        $this->assertStringContainsString('スキップ 1 件', session('success'));

        // ⚠ Bug #48: UNIQUE + catch の安全網が同じ「スキップ 1 件」を返すので、
        //   応答だけを見ていると事前チェックが死んでも緑になる
        $this->assertFalse($writes->created, '事前チェックをすり抜けて INSERT を試みている');
    }

    /** Excel 側に年月列があればそちらを優先する（設計 §7.1） */
    public function test_row_level_month_wins_over_the_screen_default(): void
    {
        $this->importBuildings([
            ['name' => 'アルファビル', 'operating' => '1', 'surveyed_month' => '2025年6月'],
            ['name' => 'ベータビル',   'operating' => '1'],
        ], '2026-08')->assertRedirect();

        $this->assertSame('2025-06-01', $this->monthOf('アルファビル'));
        $this->assertSame('2026-08-01', $this->monthOf('ベータビル'));
    }

    /**
     * Excel の「本物の日付セル」も年月として読めること。
     *
     * ⚠ 画面側は `XLSX.read(…, { cellDates: true })` ＋ Date → 'YYYY-MM-DD' 整形で
     *   この形の文字列を送ってくる。2026-08-17 に node で実測したところ、
     *   既定（cellDates 無し）だと日付セルは **シリアル値 '45809'** で届き、
     *   parseMonth が読めず**無音で画面の既定月に落ちていた**（プランのコードはこれ）。
     *   cellDates だけ付けても `String(date)` は 'Sun Jun 01 2025 …' で読めないので、
     *   整形とセットでないと直らない。
     */
    public function test_row_level_month_accepts_an_excel_date_cell(): void
    {
        $this->importBuildings([
            ['name' => 'アルファビル', 'operating' => '1', 'surveyed_month' => '2025-06-01'],
            ['name' => 'ベータビル',   'operating' => '1', 'surveyed_month' => '2025/6'],
        ], '2026-08')->assertRedirect();

        $this->assertSame('2025-06-01', $this->monthOf('アルファビル'));
        $this->assertSame('2025-06-01', $this->monthOf('ベータビル'));
    }

    /** 読めない年月・範囲外の年月は画面の既定月に落とす（行ごと捨てない） */
    public function test_unreadable_row_level_months_fall_back_to_the_screen_default(): void
    {
        $this->importBuildings([
            ['name' => 'ビルA', 'operating' => '1', 'surveyed_month' => '45809'],      // Excel のシリアル値
            ['name' => 'ビルB', 'operating' => '1', 'surveyed_month' => '2025年13月'],  // 月が範囲外
            ['name' => 'ビルC', 'operating' => '1', 'surveyed_month' => '1800年5月'],   // 年が範囲外
        ], '2026-08')->assertRedirect();

        foreach (['ビルA', 'ビルB', 'ビルC'] as $name) {
            $this->assertSame('2026-08-01', $this->monthOf($name), "{$name} が既定月になっていない");
        }
    }

    /** 全角数字・カンマ・空白を落としてから数値判定する（設計 §7.3） */
    public function test_normalizes_full_width_digits_and_separators(): void
    {
        $this->importBuildings([
            ['name' => 'アルファビル', 'operating' => '１，２３４', 'vacant' => ' 5 ', 'unknown' => '', 'total_floors' => '１０'],
        ])->assertRedirect();

        $building = AreaBuilding::where('name', 'アルファビル')->firstOrFail();
        $this->assertSame(10, $building->total_floors);

        $survey = $building->surveys()->firstOrFail();
        $this->assertSame([1234, 5, 0], [$survey->operating_count, $survey->vacant_count, $survey->unknown_count]);
    }

    /**
     * 数値にならない値は行ごと取り込まず警告に出す（設計 §7.3）。
     *
     * ⚠ プランは「ビル名が空」も「数値不正でスキップ」に数えていたが、
     *   利用者が数字の間違いを探しに行くことになるので理由ごとに分けた（実装が正）。
     */
    public function test_rows_with_non_numeric_counts_are_skipped_and_reported(): void
    {
        $this->importBuildings([
            ['name' => '正常ビル',   'operating' => '3'],
            ['name' => '数値NGビル', 'operating' => '数棟'],
            ['name' => '',           'operating' => '1'],
        ]);

        $this->assertSame(['正常ビル'], AreaBuilding::pluck('name')->all());
        $this->assertStringContainsString('数値不正でスキップ 1 件', session('success'));
        $this->assertStringContainsString('ビル名が空でスキップ 1 件', session('success'));
    }

    /**
     * 件数欄の上限。列は INT UNSIGNED で、画面 CRUD は max:9999。
     *
     * ⚠ **SQLite は範囲を強制しない**ので、上限が無いと本番 MySQL（strict）でだけ
     *   1264 Out of range で 500 になる（Bug #40）。プランには上限が無かった。
     */
    public function test_counts_out_of_range_are_rejected(): void
    {
        $this->importBuildings([
            ['name' => '上限ちょうど', 'operating' => '9999'],
            ['name' => '上限超え',     'operating' => '10000'],
            ['name' => '負数',         'operating' => '-1'],
        ]);

        $this->assertSame(['上限ちょうど'], AreaBuilding::pluck('name')->all());
        $this->assertStringContainsString('数値不正でスキップ 2 件', session('success'));
    }

    /** 総階数は範囲外なら「未設定」に落とす（行そのものは取り込む） */
    public function test_total_floors_out_of_range_is_dropped_but_the_row_is_kept(): void
    {
        $this->importBuildings([
            ['name' => '桁あふれビル', 'operating' => '1', 'total_floors' => '99999999999'],
            ['name' => '負の階ビル',   'operating' => '1', 'total_floors' => '-3'],
            ['name' => '文字階ビル',   'operating' => '1', 'total_floors' => '地上5階'],
        ])->assertRedirect();

        foreach (['桁あふれビル', '負の階ビル', '文字階ビル'] as $name) {
            $this->assertNull(
                AreaBuilding::where('name', $name)->firstOrFail()->total_floors,
                "{$name} の総階数が落ちていない（本番 MySQL では 1264 で 500）"
            );
            $this->assertSame(1, AreaBuilding::where('name', $name)->firstOrFail()->surveys()->count());
        }
    }

    public function test_month_is_required_for_the_buildings_kind(): void
    {
        $this->actingAs($this->manager())
            ->from(self::IMPORT_URL)
            ->post(self::IMPORT_URL, [
                'kind' => 'buildings',
                'rows' => json_encode([['name' => 'X', 'operating' => '1']]),
            ])
            ->assertSessionHasErrors('surveyed_month');

        $this->assertSame(0, AreaBuilding::count());
    }

    /**
     * 差し戻されたエラーが取込画面に出ること。
     *
     * ⚠ **Bug #49: このテストでセッションに触らないこと。** `assertSessionHasErrors()` を
     *   1 行呼ぶだけで errors バッグが消費され、そのあと描画した画面から
     *   エラー表示が丸ごと消える（`old()` の復元だけは生き残るので気づけない）。
     *   期待文言は trans() で組み立てる。
     */
    public function test_validation_errors_are_rendered_on_the_import_screen(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->from(self::IMPORT_URL)
            ->post(self::IMPORT_URL, [
                'kind' => 'buildings',
                'rows' => json_encode([['name' => 'X', 'operating' => '1']]),
            ]);

        $html = $this->actingAs($manager)->get(self::IMPORT_URL)->getContent();

        $this->assertStringContainsString('<li>調査年月は必須です。</li>', $html, 'エラーサマリが画面に出ていない');
    }

    // ============================================================
    // テナント明細（設計 §7.2）
    // ============================================================

    public function test_imports_tenant_rows_into_an_existing_building(): void
    {
        $building = $this->makeBuilding('アルファビル');

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '3', 'room_number' => '301', 'name' => '大街道珈琲', 'industry' => '飲食', 'status' => '営業中'],
            ['building_name' => 'アルファビル', 'floor' => '-1', 'room_number' => 'B101', 'name' => '', 'industry' => '', 'status' => '空室'],
            ['building_name' => 'アルファビル', 'floor' => '2', 'room_number' => '201', 'name' => '', 'industry' => '', 'status' => ''],
        ])->assertRedirect();

        $this->assertSame(3, $building->tenants()->count());

        $statuses = $building->tenants()->orderBy('id')->pluck('status')->all();
        $this->assertSame(
            [AreaTenantStatus::Operating, AreaTenantStatus::Vacant, AreaTenantStatus::Unknown],
            $statuses
        );
        $this->assertSame(-1, $building->tenants()->where('room_number', 'B101')->firstOrFail()->floor);
    }

    /** 台帳に無いビル名の行は取り込まず警告に出す。ビルの自動生成はしない（設計 §7.2） */
    public function test_tenant_rows_for_unknown_buildings_are_reported_not_created(): void
    {
        $building = $this->makeBuilding('アルファビル');

        $this->importTenants([
            ['building_name' => 'アルファビル', 'name' => '入る', 'status' => '営業'],
            ['building_name' => '知らないビル', 'name' => '入らない', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, AreaBuilding::count(), 'ビルが自動生成されている');
        $this->assertSame(1, AreaBuildingTenant::count());
        $this->assertSame('入る', $building->tenants()->firstOrFail()->name);
        $this->assertStringContainsString('知らないビル', session('success'));
    }

    /** 階も範囲外は「未設定」に落とす（列は INT。画面 CRUD は -10〜200） */
    public function test_tenant_floor_out_of_range_is_dropped(): void
    {
        $building = $this->makeBuilding('アルファビル');

        $this->importTenants([
            ['building_name' => 'アルファビル', 'room_number' => 'A', 'floor' => '99999999999', 'status' => '営業'],
            ['building_name' => 'アルファビル', 'room_number' => 'B', 'floor' => '-99', 'status' => '営業'],
            ['building_name' => 'アルファビル', 'room_number' => 'C', 'floor' => '-1', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertNull($building->tenants()->where('room_number', 'A')->firstOrFail()->floor);
        $this->assertNull($building->tenants()->where('room_number', 'B')->firstOrFail()->floor);
        $this->assertSame(-1, $building->tenants()->where('room_number', 'C')->firstOrFail()->floor);
    }

    /** VARCHAR 長の防波堤。SQLite は長さを強制しないので本番だけ 1406 で落ちる（Bug #40） */
    public function test_over_long_strings_are_truncated_to_the_column_length(): void
    {
        $building = $this->makeBuilding('アルファビル');

        $this->importTenants([[
            'building_name' => 'アルファビル',
            'room_number'   => str_repeat('あ', 80),
            'name'          => str_repeat('い', 400),
            'industry'      => str_repeat('う', 150),
            'status'        => '営業',
        ]])->assertRedirect();

        $tenant = $building->tenants()->firstOrFail();
        $this->assertSame(50, mb_strlen($tenant->room_number));
        $this->assertSame(255, mb_strlen($tenant->name));
        $this->assertSame(100, mb_strlen($tenant->industry));
    }

    // ============================================================
    // 入力の防御
    // ============================================================

    public function test_rejects_malformed_json(): void
    {
        $this->actingAs($this->manager())
            ->from(self::IMPORT_URL)
            ->post(self::IMPORT_URL, [
                'kind' => 'buildings', 'surveyed_month' => '2026-08', 'rows' => 'これはJSONではない',
            ])
            ->assertRedirect(self::IMPORT_URL);

        $this->assertSame('取り込む行がありません。', session('error'));
        $this->assertSame(0, AreaBuilding::count());
    }

    public function test_rejects_too_many_rows(): void
    {
        $rows = array_fill(0, 2001, ['name' => 'X', 'operating' => '1']);

        $this->actingAs($this->manager())
            ->from(self::IMPORT_URL)
            ->post(self::IMPORT_URL, [
                'kind' => 'buildings', 'surveyed_month' => '2026-08', 'rows' => json_encode($rows),
            ])
            ->assertRedirect(self::IMPORT_URL);

        $this->assertStringContainsString('2000 行までです', session('error'));
        $this->assertSame(0, AreaBuilding::count());
    }

    /**
     * 画面を経由しない POST でも壊れた値で落ちないこと。
     *
     * ⚠ `rows` は利用者が組み立てた任意の JSON。配列やオブジェクトが値に来ると
     *   素の `(string) $raw` は "Array to string conversion" を出す。
     */
    public function test_non_scalar_cell_values_are_rejected_without_crashing(): void
    {
        $this->makeBuilding('アルファビル');

        $this->importBuildings([
            ['name' => ['配列'], 'operating' => '1'],
            ['name' => 'アルファビル', 'operating' => ['配列']],
            'これは配列ですらない',
        ])->assertRedirect();

        $this->assertSame(1, AreaBuilding::count());
        $this->assertSame(0, AreaBuildingSurvey::count());

        $this->importTenants([
            ['building_name' => ['配列'], 'status' => '営業'],
            ['building_name' => 'アルファビル', 'name' => ['配列'], 'industry' => ['配列'], 'status' => ['配列']],
        ])->assertRedirect();

        $this->assertSame(1, AreaBuildingTenant::count());
        $tenant = AreaBuildingTenant::firstOrFail();
        $this->assertNull($tenant->name);
        $this->assertSame(AreaTenantStatus::Unknown, $tenant->status);
    }

    // ============================================================
    // 画面の配線（Bug #47 / Bug #28）
    // ============================================================

    public function test_sheetjs_is_loaded_from_jsdelivr_with_sri(): void
    {
        $html = $this->actingAs($this->manager())->get(self::IMPORT_URL)->getContent();

        $this->assertStringContainsString('cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js', $html);
        $this->assertStringNotContainsString('cdnjs.cloudflare.com', $html, '本番でブロックされる CDN を使っている');
        $this->assertStringContainsString('integrity="' . self::SHEETJS_SRI . '"', $html, 'SRI が無いか、実測値と違う');
        $this->assertStringContainsString('crossorigin="anonymous"', $html);
    }

    /** ⚠ 取込プレビューの option は静的生成（Bug #16） */
    public function test_mapping_selects_do_not_use_x_for(): void
    {
        $html = $this->actingAs($this->manager())->get(self::IMPORT_URL)->getContent();

        $this->assertStringNotContainsString('<template x-for', $html);
    }

    /**
     * Excel の日付セルを読む設定が画面側に入っていること。
     *
     * ⚠ PHP 側のテストでは JS の挙動を原理的に守れないので、
     *   TaxExclusiveCeilingJsTest と同じくソース走査で固定する（Bug #41 の流儀）。
     *   `cellDates` と Date の整形は**対で**必要（片方だけでは日付列が読めない）。
     */
    public function test_the_import_screen_reads_excel_date_cells(): void
    {
        $blade = file_get_contents(resource_path('views/tenant/area-buildings/import.blade.php'));

        $this->assertMatchesRegularExpression(
            '/XLSX\.read\([^)]*cellDates:\s*true/',
            $blade,
            'cellDates: true が無い。日付セルがシリアル値で届き、年月列が無音で無視される'
        );
        $this->assertStringContainsString(
            'instanceof Date',
            $blade,
            'Date を YYYY-MM-DD へ整形していない。cellDates だけでは "Sun Jun 01 2025 …" になって読めない'
        );
    }

    /**
     * 確定フォームの配線（Bug #47）。URL を assertSee するだけでは
     * HTTP メソッド・hidden の有無・@csrf のどれも見ていない。
     *
     * ⚠ 3 つの hidden は Alpine が実行時に値を入れる（`:value`）ので、
     *   描画直後の値は**空**。parseForm がそれを空として返すこと自体も
     *   ここで固定している（`:value` を素の `value` と読み違えると、
     *   Alpine の式文字列がそのまま送られて無音で通ってしまう）。
     */
    public function test_the_confirm_form_round_trips_to_the_execute_route(): void
    {
        $manager = $this->manager();
        $html    = $this->actingAs($manager)->get(self::IMPORT_URL)->getContent();

        $form = $this->parseForm($html, 'action="' . route('tenant.area-buildings.import.execute') . '"');

        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が無い');

        foreach (['kind', 'surveyed_month', 'rows'] as $name) {
            $this->assertArrayHasKey($name, $form['fields'], "hidden {$name} が描画されていない");
            $this->assertSame('', $form['fields'][$name], "{$name} に静的な value が付いている（Alpine が入れる想定）");
        }

        // ブラウザと同じ経路で送る（Alpine が入れる 3 つだけ手で埋める）
        $this->actingAs($manager)->post($form['action'], array_merge($form['fields'], [
            'kind'           => 'buildings',
            'surveyed_month' => '2026-08',
            'rows'           => json_encode([['name' => '往復ビル', 'operating' => '2']]),
        ]))->assertRedirect(route('tenant.area-buildings.index'));

        $this->assertSame(['往復ビル'], AreaBuilding::pluck('name')->all());
        $this->assertSame(2, AreaBuildingSurvey::firstOrFail()->operating_count);
    }

    private function monthOf(string $buildingName): string
    {
        return AreaBuilding::where('name', $buildingName)
            ->firstOrFail()
            ->surveys()
            ->firstOrFail()
            ->surveyed_month
            ->format('Y-m-d');
    }
}
