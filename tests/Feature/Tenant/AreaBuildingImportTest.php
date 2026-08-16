<?php

namespace Tests\Feature\Tenant;

use App\Enums\AreaTenantStatus;
use App\Http\Controllers\Tenant\AreaBuildingImportController as Importer;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Models\AreaBuildingTenant;
use App\Support\FloorNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * 周辺ビル調査の Excel 取込（設計 §7 / プラン Task 11）。
 *
 * ⚠ サーバ側の経路しか通らないテストであることを忘れないこと。SheetJS の解析・
 *   ヘッダー行検出・列マッピング・プレビューが壊れていても、ここは JSON を PHP から
 *   手で送るので全部緑のまま通る（Bug #28 / #35 と同じ構図）。画面側は
 *   ・SRI が実測値と一致すること
 *   ・option を x-for で作らないこと（Bug #16）
 *   ・確定フォームの配線（Bug #47 の往復）
 *   ・階／範囲の語彙が PHP と JS で一致すること（Bug #41）
 *   ・ヘッダー行の選択 UI と自動検出が在ること
 *   を構造で固定し、実挙動はブラウザで確かめる（プラン Step 10）。
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

    private function importView(): string
    {
        return file_get_contents(resource_path('views/tenant/area-buildings/import.blade.php'));
    }

    /**
     * 「主機構が仕事をしたか」を応答ではなく書き込みで見る（Bug #48）。
     *
     * ⚠ 同一年月のスキップは ①先読み集合による事前チェック ②DB の UNIQUE 制約 + catch の
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

    public function test_unknown_kind_is_rejected(): void
    {
        $this->actingAs($this->manager())
            ->from(self::IMPORT_URL)
            ->post(self::IMPORT_URL, [
                'kind'           => 'everything',
                'surveyed_month' => '2026-08',
                'rows'           => json_encode([['building_name' => 'X', 'name' => 'Y']]),
            ])
            ->assertSessionHasErrors('kind');

        $this->assertSame(0, AreaBuilding::count());
        $this->assertSame(0, AreaBuildingTenant::count());
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
        $this->assertNotNull($alpha->created_by, '取込を実行したユーザーが登録者に入っていない');

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

    /** 同名のビルが複数あるときは id の小さいほうへ寄せる（後勝ちにしない） */
    public function test_duplicate_names_in_the_ledger_resolve_to_the_lowest_id(): void
    {
        $first  = $this->makeBuilding('アルファビル');
        $second = $this->makeBuilding('アルファビル');

        $this->importBuildings([['name' => 'アルファビル', 'operating' => '1']])->assertRedirect();

        $this->assertSame(1, $first->surveys()->count(), 'id の小さいビルに付いていない');
        $this->assertSame(0, $second->surveys()->count());
    }

    /** 同じファイル内に同じ**新規**ビル名が 2 行あっても 1 棟だけ作る */
    public function test_a_new_name_repeated_in_the_same_file_creates_one_building(): void
    {
        $this->importBuildings([
            ['name' => 'アルファビル', 'operating' => '1', 'surveyed_month' => '2026-07'],
            ['name' => 'アルファビル', 'operating' => '2', 'surveyed_month' => '2026-08'],
        ])->assertRedirect();

        $this->assertSame(1, AreaBuilding::count(), '同じビルが二重に作られている');
        $this->assertSame(2, AreaBuildingSurvey::count());
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

    /** 同じファイル内に同一ビル・同一月の行が 2 つあっても 1 件だけ入る */
    public function test_the_same_month_twice_in_one_file_inserts_once(): void
    {
        $this->importBuildings([
            ['name' => 'アルファビル', 'operating' => '4'],
            ['name' => 'アルファビル', 'operating' => '7'],
        ])->assertRedirect();

        $this->assertSame(1, AreaBuildingSurvey::count());
        $this->assertSame(4, AreaBuildingSurvey::firstOrFail()->operating_count);
        $this->assertStringContainsString('同一年月のためスキップ 1 件', session('success'));
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
        $this->assertStringContainsString('値が不正でスキップ 1 件', session('success'));
        $this->assertStringContainsString('ビル名が空でスキップ 1 件', session('success'));
    }

    /**
     * 件数欄の上限。列は INT UNSIGNED で、画面 CRUD は max:9999。
     *
     * ⚠ **SQLite は範囲を強制しない**ので、上限が無いと本番 MySQL（strict）でだけ
     *   1264 Out of range で 500 になる（Bug #40）。
     */
    public function test_counts_out_of_range_are_rejected(): void
    {
        $this->importBuildings([
            ['name' => '上限ちょうど', 'operating' => (string) Importer::MAX_COUNT],
            ['name' => '上限超え',     'operating' => (string) (Importer::MAX_COUNT + 1)],
            ['name' => '負数',         'operating' => '-1'],
        ]);

        $this->assertSame(['上限ちょうど'], AreaBuilding::pluck('name')->all());
        $this->assertStringContainsString('値が不正でスキップ 2 件', session('success'));
    }

    /**
     * 総階数の表記ゆれ。'10階建' '５階' '地上5階' が読めること。
     *
     * ⚠ 2026-08-17 のレビューまで、これらは**全部無警告で NULL** に落ちていた。
     */
    public function test_total_floors_accepts_japanese_notation(): void
    {
        $this->importBuildings([
            ['name' => 'ビルA', 'operating' => '1', 'total_floors' => '10階建'],
            ['name' => 'ビルB', 'operating' => '1', 'total_floors' => '５階'],
            ['name' => 'ビルC', 'operating' => '1', 'total_floors' => '地上5階'],
            ['name' => 'ビルD', 'operating' => '1', 'total_floors' => '7F'],
        ])->assertRedirect();

        $this->assertSame(10, AreaBuilding::where('name', 'ビルA')->firstOrFail()->total_floors);
        $this->assertSame(5, AreaBuilding::where('name', 'ビルB')->firstOrFail()->total_floors);
        $this->assertSame(5, AreaBuilding::where('name', 'ビルC')->firstOrFail()->total_floors);
        $this->assertSame(7, AreaBuilding::where('name', 'ビルD')->firstOrFail()->total_floors);
    }

    /**
     * 読めない / 範囲外の総階数は**行ごと**弾く（設計 §7.3）。
     * ⚠ 黙って NULL に落とすと、利用者は入力した階数が消えたことに気づけない。
     */
    public function test_unreadable_total_floors_reject_the_row(): void
    {
        $this->importBuildings([
            ['name' => '桁あふれビル', 'operating' => '1', 'total_floors' => '99999999999'],
            ['name' => '負の階ビル',   'operating' => '1', 'total_floors' => '-3'],
            ['name' => '地下ビル',     'operating' => '1', 'total_floors' => 'B1'],
            ['name' => '文字階ビル',   'operating' => '1', 'total_floors' => 'ロビー'],
            ['name' => '空欄ビル',     'operating' => '1', 'total_floors' => ''],
        ]);

        $this->assertSame(['空欄ビル'], AreaBuilding::pluck('name')->all());
        $this->assertNull(AreaBuilding::firstOrFail()->total_floors);
        $this->assertStringContainsString('値が不正でスキップ 4 件', session('success'));
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

    /**
     * ビル取込のクエリ数。1 行ごとに find() / exists() を投げない（I-4）。
     *
     * ⚠ 上限が無いと 2000 行の取込で約 8,000 往復が 1 リクエストに乗る。
     *   セッション等を数えないよう area_building を触るクエリだけを数える。
     */
    public function test_building_import_does_not_scale_queries_per_row(): void
    {
        foreach (range(1, 20) as $i) {
            $this->makeBuilding("既存ビル{$i}");
        }

        $rows = [];
        foreach (range(1, 20) as $i) {
            $rows[] = ['name' => "既存ビル{$i}", 'operating' => '1'];
        }

        $manager = $this->manager();

        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'area_building')) {
                $queries++;
            }
        });

        $this->actingAs($manager)->post(self::IMPORT_URL, [
            'kind' => 'buildings', 'surveyed_month' => '2026-08', 'rows' => json_encode($rows),
        ])->assertRedirect();

        $this->assertSame(20, AreaBuildingSurvey::count());

        // 内訳: ビル一覧 1 + 調査回の先読み 1 + INSERT 20 = 22
        $this->assertLessThanOrEqual(
            25,
            $queries,
            "20 行の取込で area_building 系のクエリが {$queries} 本。行ごとに find()/exists() を投げている"
        );
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

    /**
     * 階の表記ゆれ。'1F' 'B1' '2階' '地下1階' が読めること（C-2）。
     *
     * ⚠ 2026-08-17 のレビューまで**全部無警告で NULL** に落ちていた。詳細画面は
     *   `orderByDesc('floor')` で並べて `floorLabel()`（B1 = -1 前提）を出すので、
     *   Excel でいちばん自然な書き方をすると取り込んだテナントが全行「—」になっていた。
     */
    public function test_tenant_floor_accepts_japanese_notation(): void
    {
        $building = $this->makeBuilding('アルファビル');

        $this->importTenants([
            ['building_name' => 'アルファビル', 'room_number' => 'a', 'floor' => '1F',      'status' => '営業'],
            ['building_name' => 'アルファビル', 'room_number' => 'b', 'floor' => 'B1',      'status' => '営業'],
            ['building_name' => 'アルファビル', 'room_number' => 'c', 'floor' => '2階',     'status' => '営業'],
            ['building_name' => 'アルファビル', 'room_number' => 'd', 'floor' => '地下1階', 'status' => '営業'],
            ['building_name' => 'アルファビル', 'room_number' => 'e', 'floor' => 'Ｂ２',    'status' => '営業'],
        ])->assertRedirect();

        $floors = $building->tenants()->orderBy('room_number')->pluck('floor', 'room_number')->all();

        $this->assertSame(['a' => 1, 'b' => -1, 'c' => 2, 'd' => -1, 'e' => -2], $floors);
        $this->assertSame('B1F', $building->tenants()->where('room_number', 'b')->firstOrFail()->floorLabel());
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

    /**
     * 「台帳に無いビル」の報告は **行数**（他のカウンタと単位を揃える）＋ 棟数。
     *
     * ⚠ 相異なるビル名の数だけを出すと、50 行落ちたのに「1 件」と読めてしまう（I-2）。
     */
    public function test_unmatched_rows_are_counted_by_row_not_by_distinct_name(): void
    {
        $this->makeBuilding('アルファビル');

        $rows = [];
        foreach (range(1, 30) as $i) {
            $rows[] = ['building_name' => '知らないビル', 'room_number' => (string) $i, 'status' => '営業'];
        }
        $rows[] = ['building_name' => 'もう一つ知らないビル', 'status' => '営業'];

        $this->importTenants($rows)->assertRedirect();

        $this->assertSame(0, AreaBuildingTenant::count());
        $this->assertStringContainsString('台帳に無いビルでスキップ 31 行', session('success'));
        $this->assertStringContainsString('2 棟: 知らないビル / もう一つ知らないビル', session('success'));
    }

    /** 読めない / 範囲外の階は行ごと弾く（列は INT。画面 CRUD は -10〜200） */
    public function test_unreadable_tenant_floor_rejects_the_row(): void
    {
        $building = $this->makeBuilding('アルファビル');

        $this->importTenants([
            ['building_name' => 'アルファビル', 'room_number' => 'A', 'floor' => '99999999999', 'status' => '営業'],
            ['building_name' => 'アルファビル', 'room_number' => 'B', 'floor' => '-99', 'status' => '営業'],
            ['building_name' => 'アルファビル', 'room_number' => 'C', 'floor' => 'ペントハウス', 'status' => '営業'],
            ['building_name' => 'アルファビル', 'room_number' => 'D', 'floor' => '-1', 'status' => '営業'],
            ['building_name' => 'アルファビル', 'room_number' => 'E', 'floor' => '', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(['D', 'E'], $building->tenants()->orderBy('room_number')->pluck('room_number')->all());
        $this->assertNull($building->tenants()->where('room_number', 'E')->firstOrFail()->floor);
        $this->assertStringContainsString('値が不正でスキップ 3 件', session('success'));
    }

    /**
     * 再取込は行を二重にする。突合キーが設計に無いので防げないが、**気づけるようにする**（I-5）。
     * ⚠ `AreaBuildingController::divergence()` が現況テナント数を見るので、
     *   二重取込は乖離警告に嘘の数字を出させる。
     */
    public function test_repeated_tenant_import_reports_the_current_total(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $rows = [['building_name' => 'アルファビル', 'room_number' => '101', 'status' => '営業']];

        $this->importTenants($rows)->assertRedirect();
        $this->assertStringContainsString('取込後の現況テナント数: アルファビル 1 件', session('success'));

        $this->importTenants($rows)->assertRedirect();
        $this->assertSame(2, $building->tenants()->count());
        $this->assertStringContainsString('取込後の現況テナント数: アルファビル 2 件', session('success'));
    }

    /** 画面にも「2 回取り込むと二重になる」注意書きを出す */
    public function test_the_screen_warns_about_duplicate_tenant_imports(): void
    {
        $html = $this->actingAs($this->manager())->get(self::IMPORT_URL)->getContent();

        $this->assertStringContainsString('同じファイルを 2 回取り込むと行が二重になります', $html);
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

    /** ⚠ 同じ防波堤が buildings 入口にも要る（入口 × 対象。Bug #44） */
    public function test_over_long_building_strings_are_truncated(): void
    {
        $this->importBuildings([[
            'name'      => str_repeat('ビ', 400),
            'address'   => str_repeat('あ', 400),
            'operating' => '1',
        ]])->assertRedirect();

        $building = AreaBuilding::firstOrFail();
        $this->assertSame(255, mb_strlen($building->name));
        $this->assertSame(255, mb_strlen($building->address));
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

    public function test_accepts_exactly_the_row_limit_and_rejects_one_more(): void
    {
        $row = ['name' => 'X', 'operating' => '1'];

        $this->actingAs($this->manager())
            ->post(self::IMPORT_URL, [
                'kind' => 'buildings', 'surveyed_month' => '2026-08', 'rows' => json_encode(array_fill(0, 2000, $row)),
            ])
            ->assertRedirect(route('tenant.area-buildings.index'));
        $this->assertSame(1, AreaBuilding::count(), 'ちょうど 2000 行が弾かれている');

        $this->actingAs($this->manager())
            ->from(self::IMPORT_URL)
            ->post(self::IMPORT_URL, [
                'kind' => 'buildings', 'surveyed_month' => '2026-08', 'rows' => json_encode(array_fill(0, 2001, $row)),
            ])
            ->assertRedirect(self::IMPORT_URL);

        $this->assertStringContainsString('2000 行までです', session('error'));
        $this->assertSame(1, AreaBuilding::count(), '2001 行が取り込まれている');
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
    // 画面の配線（Bug #47 / Bug #28 / Bug #41 / Bug #43）
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
     */
    public function test_the_import_screen_reads_excel_date_cells(): void
    {
        $blade = $this->importView();

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
     * ヘッダー行の選択 UI と自動検出（C-1）。
     *
     * ⚠ 「最初の非空行」固定だと、1 行目がタイトルの Excel で
     *   **'ビル名' という架空のビルが台帳に入り、しかも「新規 3 件」と完全成功に見える**。
     *   列マッピングは直せてもヘッダー行を選ぶ UI が無いと取込画面の中で修復できない。
     */
    public function test_the_import_screen_offers_header_row_selection(): void
    {
        $html  = $this->actingAs($this->manager())->get(self::IMPORT_URL)->getContent();
        $blade = $this->importView();

        $this->assertStringContainsString('id="area-import-header-row"', $html, 'ヘッダー行の選択 UI が無い');
        $this->assertStringContainsString('onHeaderRowChange($event)', $html, 'ヘッダー行の変更が配線されていない');

        $this->assertMatchesRegularExpression('/onHeaderRowChange:\s*function/', $blade, 'onHeaderRowChange の実体が無い');
        $this->assertMatchesRegularExpression('/populateHeaderRowSelect:\s*function/', $blade, 'option の動的注入が無い');

        // loadSheet が検出関数を通ること（「最初の非空行」直書きへの退行を止める）
        $this->assertMatchesRegularExpression(
            '/this\.headerRowIndex\s*=\s*this\.detectHeaderRow\(\)/',
            $blade,
            'loadSheet が detectHeaderRow() を通っていない'
        );

        // ⚠ **関数の存在だけを見ると中身を「最初の非空行」に戻しても緑になる**
        //   （2026-08-17 の変異 C1a で実測。名前を残したまま body を戻すと素通りした）。
        //   検出アルゴリズムの本質——①複数行を走査する ②見出しらしさの閾値で選ぶ
        //   ③見つからなければ最初の非空行へフォールバック——を body の中で固定する。
        $body = $this->jsFunctionBody($blade, 'detectHeaderRow');

        $this->assertStringContainsString(
            'AREA_IMPORT_HEADER_SCAN_ROWS',
            $body,
            'detectHeaderRow が複数行を走査していない（1 行目固定に戻っている）'
        );
        $this->assertMatchesRegularExpression(
            '/Object\.keys\(hits\)\.length >= 2/',
            $body,
            'detectHeaderRow が「マッピング対象が 2 つ以上ヒットする行」で選んでいない'
        );
        $this->assertStringContainsString(
            'findIndex',
            $body,
            'detectHeaderRow に「最初の非空行」フォールバックが無い'
        );
    }

    /** 必須マッピングのガード（I-1）。件数列を割り当て忘れて 0/0/0 の調査回を作らない */
    public function test_the_preview_requires_the_key_columns_to_be_mapped(): void
    {
        $blade = $this->importView();

        $this->assertStringContainsString("alert('「ビル名」列を指定してください。')", $blade);
        $this->assertStringContainsString('「営業」「空き」「不明」のうち少なくとも 1 列を指定してください。', $blade);
    }

    /** ファイルサイズ上限（Mi-4。前例 realestate と同じ 5 MB） */
    public function test_the_import_screen_limits_the_file_size(): void
    {
        $this->assertStringContainsString('5 * 1024 * 1024', $this->importView(), 'ファイルサイズ上限が無い');
    }

    /**
     * 階の語彙が PHP と JS で一致すること（Bug #41）。
     *
     * ⚠ 割れると「プレビューが取り込めると言った行をサーバが弾く」。
     *   JS 側は JSON リテラルで持たせてあるので厳密比較できる。
     */
    public function test_floor_vocabulary_matches_between_php_and_js(): void
    {
        $js = $this->jsonLiteral('AREA_IMPORT_FLOOR_TOKENS');

        $this->assertSame(FloorNumber::BASEMENT_PREFIXES, $js['basement']);
        $this->assertSame(FloorNumber::ABOVE_GROUND_PREFIXES, $js['aboveGround']);
        $this->assertSame(FloorNumber::FLOOR_SUFFIXES, $js['suffix']);
    }

    /** 範囲の定数が PHP と JS で一致すること（Bug #41） */
    public function test_limits_match_between_php_and_js(): void
    {
        $js = $this->jsonLiteral('AREA_IMPORT_LIMITS');

        $this->assertSame([
            'maxCount'       => Importer::MAX_COUNT,
            'minFloors'      => Importer::MIN_FLOORS,
            'maxFloors'      => Importer::MAX_FLOORS,
            'minTenantFloor' => Importer::MIN_TENANT_FLOOR,
            'minYear'        => Importer::MIN_YEAR,
        ], $js);
    }

    /**
     * プレビューが「黙って落とす」経路を持たないこと（C-2 / Mi-5 / Mi-6）。
     * 件数 3 列は**それぞれ**、階・総階数・年月も個別に警告を出す。
     */
    public function test_the_preview_warns_for_every_droppable_column(): void
    {
        $blade = $this->importView();

        $this->assertMatchesRegularExpression(
            "/\\['operating',\s*'vacant',\s*'unknown'\\]\.forEach/",
            $blade,
            '件数 3 列をまとめて検査するループが無い'
        );
        $this->assertStringContainsString("warnings.push(key + ' が数値でない')", $blade);
        $this->assertStringContainsString("' の範囲外')", $blade, '範囲外を「数値でない」と混同している（Mi-5）');
        $this->assertStringContainsString("warnings.push('階数が読めない')", $blade);
        $this->assertStringContainsString("warnings.push('階が読めない')", $blade);
        $this->assertStringContainsString("warnings.push('調査年月が読めない')", $blade);
    }

    /** payload は「警告のある行」を落とすこと（previewRows をそのまま送らない） */
    public function test_the_payload_only_sends_ok_rows(): void
    {
        $this->assertMatchesRegularExpression(
            '/payload:\s*function\s*\(\)\s*\{\s*return JSON\.stringify\(this\.okRows\(\)/',
            $this->importView(),
            'payload() が okRows() を通っていない（警告行まで送ってしまう）'
        );
    }

    /**
     * 調査年月は当月が既定で、空のまま送れないこと（I-3）。
     *
     * ⚠ 差し戻されると back() のフルリロードで Alpine の state（ファイル・マッピング・
     *   プレビュー）が全部消える。このビューに `old(` は 1 箇所も無い＝復元できない。
     * ⚠ 押せない理由は**ラッパーの span** に置く（disabled なボタン自身の title は
     *   どのブラウザでも出ない。Bug #43）。
     */
    public function test_the_month_defaults_to_the_current_month_and_blocks_submission(): void
    {
        $html = $this->actingAs($this->manager())->get(self::IMPORT_URL)->getContent();

        $this->assertStringContainsString(
            "surveyedMonth: '" . now()->format('Y-m') . "'",
            $html,
            '調査年月の既定が当月になっていない'
        );

        $this->assertStringContainsString('<span :title="submitBlockedReason()"', $html, 'ラッパー span に理由が無い');
        $this->assertStringContainsString(':disabled="submitBlockedReason() !== null"', $html);
        $this->assertStringContainsString('調査年月を入力してください。', $html);

        // ⚠ ボタン自身の title は効かない（Bug #43）。付いていたら設計意図と食い違う
        $button = $this->tagAfter($html, '<span :title="submitBlockedReason()"', '<button');
        $this->assertStringNotContainsString('title=', $button, 'disabled なボタン自身に title が付いている（Bug #43）');
    }

    /**
     * 確定フォームの配線（Bug #47）。URL を assertSee するだけでは
     * HTTP メソッド・hidden の有無・@csrf のどれも見ていない。
     *
     * ⚠ 3 つの hidden は Alpine が実行時に値を入れる（`:value`）ので、
     *   描画直後の値は**空**。parseForm がそれを空として返すこと自体も
     *   ここで固定している。
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

    // ============================================================
    // ヘルパー
    // ============================================================

    private function monthOf(string $buildingName): string
    {
        return AreaBuilding::where('name', $buildingName)
            ->firstOrFail()
            ->surveys()
            ->firstOrFail()
            ->surveyed_month
            ->format('Y-m-d');
    }

    /** `var NAME = { … };` の JSON リテラルを取り出す */
    private function jsonLiteral(string $name): array
    {
        $blade = $this->importView();

        $this->assertMatchesRegularExpression(
            '/var ' . preg_quote($name, '/') . ' = (\{.*?\});/s',
            $blade,
            "{$name} が JSON リテラルとして書かれていない（厳密比較できない形になっている）"
        );
        preg_match('/var ' . preg_quote($name, '/') . ' = (\{.*?\});/s', $blade, $m);

        $decoded = json_decode($m[1], true);
        $this->assertIsArray($decoded, "{$name} が JSON として読めない");

        return $decoded;
    }

    /**
     * `name: function (…) { … }` の body を波括弧の対応で切り出す。
     * ⚠ 固定長で切ると隣のメソッドを巻き込んで false-pass する（Bug #45 ④）。
     */
    private function jsFunctionBody(string $blade, string $name): string
    {
        $needle = $name . ': function (';
        $at     = strpos($blade, $needle);
        $this->assertNotFalse($at, "{$name} の定義が見つからない");

        $open = strpos($blade, '{', strpos($blade, ')', $at));
        $this->assertNotFalse($open, "{$name} の body が開いていない");

        $depth = 0;
        for ($i = $open; $i < strlen($blade); $i++) {
            if ($blade[$i] === '{') {
                $depth++;
            } elseif ($blade[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($blade, $open, $i - $open + 1);
                }
            }
        }

        $this->fail("{$name} の body が閉じていない");
    }

    /** $from の直後にある最初の $tag の開始タグを返す */
    private function tagAfter(string $html, string $from, string $tag): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "{$from} が見つからない");

        $open = strpos($html, $tag, $start);
        $this->assertNotFalse($open, "{$from} の後に {$tag} が無い");

        return substr($html, $open, strpos($html, '>', $open) - $open + 1);
    }
}
