<?php

namespace Tests\Feature\Housing;

use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Feature\Schedule\ScheduleTestCase;

/**
 * 基本情報の「実際の完成日」を「着工予定日」へ付け替える（設計書 §5）。
 *
 * ⚠ **並びは 着工予定日 → 完成予定日。** 逆に置くと、工事の順番と画面の順番が食い違う。
 */
class HousingConstructionStartDateTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    public function test_both_tables_have_the_new_column_and_not_the_old_one(): void
    {
        foreach (['hs_properties', 'hs_custom_orders'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'construction_start_date'), "{$table} に着工予定日が無い");
            $this->assertFalse(Schema::hasColumn($table, 'actual_completion_date'), "{$table} に旧列が残っている");
        }
    }

    /**
     * ⚠ **本番 DDL とテスト用スキーマを対で維持する。** 片方だけだと SQLite テストと本番が
     *   黙って drift する（過去に実際に起きている）。
     */
    public function test_the_raw_sql_renames_both_tables(): void
    {
        $sql = file_get_contents(base_path('database/sql/2026-09-02-rename-actual-completion-to-construction-start.sql'));

        foreach (['hs_properties', 'hs_custom_orders'] as $table) {
            $this->assertMatchesRegularExpression(
                '/ALTER TABLE\s+`?' . $table . '`?\s+CHANGE COLUMN\s+`?actual_completion_date`?\s+`?construction_start_date`?/i',
                $sql,
                "{$table} の CHANGE COLUMN が DDL に無い"
            );
        }
    }

    public function test_the_column_is_mass_assignable_and_cast_to_a_date(): void
    {
        foreach ([HsProperty::class, HsCustomOrder::class] as $class) {
            $model = new $class();
            $this->assertContains('construction_start_date', $model->getFillable(), $class);
            $this->assertSame('date', $model->getCasts()['construction_start_date'] ?? null, $class);
            $this->assertNotContains('actual_completion_date', $model->getFillable(), $class);
        }
    }

    public function test_the_property_form_offers_the_construction_start_date_on_create_too(): void
    {
        // ⚠ 旧実装は @if($isEdit) で編集画面にしか出していなかった。着工予定日は登録時から分かる
        $html = $this->actingAs($this->manager())->get('/housing/properties/create')->assertOk()->getContent();

        $this->assertStringContainsString('name="construction_start_date"', $html);
        $this->assertStringContainsString('着工予定日', $html);
        $this->assertStringNotContainsString('name="actual_completion_date"', $html);
        $this->assertStringNotContainsString('実際の完成日', $html);
    }

    public function test_the_custom_order_form_offers_it_too(): void
    {
        $html = $this->actingAs($this->manager())->get('/housing/custom-orders/create')->assertOk()->getContent();

        $this->assertStringContainsString('name="construction_start_date"', $html);
        $this->assertStringContainsString('着工予定日', $html);
        $this->assertStringNotContainsString('name="actual_completion_date"', $html);
        $this->assertStringNotContainsString('実際の完成日', $html);
    }

    /** ⚠ 画面の**並び**まで見る。「両方出ている」だけでは順番の入れ替わりを検出できない */
    public function test_the_construction_start_date_comes_before_the_completion_date(): void
    {
        foreach ([
            '/housing/properties/create',
            '/housing/custom-orders/create',
        ] as $url) {
            $html = $this->actingAs($this->manager())->get($url)->assertOk()->getContent();

            $start = strpos($html, 'name="construction_start_date"');
            $end   = strpos($html, 'name="scheduled_completion_date"');

            $this->assertNotFalse($start, $url);
            $this->assertNotFalse($end, $url);
            $this->assertLessThan($end, $start, "{$url}: 着工予定日は完成予定日の前に置く");
        }
    }

    /**
     * ⚠ **詳細画面も見る。** 新規テストは元々フォーム（/create）しか見ておらず、show の
     *   ラベルだけを「実際の完成日」に戻す変異（値は construction_start_date のまま）が
     *   全テスト緑のまま通っていた（コード品質レビュー指摘・実測済み）。ラベルと値が
     *   食い違っても例外は出ない（Bug #43 / #46 / #49 と同型）。
     *
     * ⚠ **ラベルの有無だけでなく「ラベル ↔ 値」の対で見る。** ラベルだけ見ると、値を旧列
     *   （もう存在しない actual_completion_date）から読む変異——結果は常に null＝「—」——を
     *   検出できない。空白を正規化したうえで「ラベルの div の直後に、そのラベルに対応する
     *   正しい日付の div が続く」ことを固定することで、ラベル単独の書き換えにも、
     *   値だけ入れ替わる（あるいは 2 つの値が入れ替わる）書き換えにも反応する。
     */
    public function test_the_property_show_page_pairs_the_label_with_its_own_value(): void
    {
        $property = HsProperty::create([
            'property_code' => 'HS-CS2', 'property_name' => 'ラベル対応テスト', 'status' => 'construction',
            'address' => '愛媛県松山市1-1-1', 'created_by' => 1,
            'construction_start_date'   => '2026-03-10',
            'scheduled_completion_date' => '2026-04-20',
        ]);

        $html       = $this->actingAs($this->manager())->get("/housing/properties/{$property->id}")->assertOk()->getContent();
        $normalized = preg_replace('/\s+/', ' ', $html);

        $this->assertStringNotContainsString('実際の完成日', $html);
        $this->assertStringContainsString(
            '着工予定日</div> <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">2026/03/10</div>',
            $normalized,
            '着工予定日のラベル直後に着工予定日の値（2026/03/10）が無い'
        );
        $this->assertStringContainsString(
            '完成予定日</div> <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">2026/04/20</div>',
            $normalized,
            '完成予定日のラベル直後に完成予定日の値（2026/04/20）が無い'
        );
    }

    /** ⚠ 建売と対で見る（Bug #44 の「代表 1 種だけ」を避ける）。同じ変異は注文住宅の show にも当たりうる */
    public function test_the_custom_order_show_page_pairs_the_label_with_its_own_value(): void
    {
        $order = HsCustomOrder::create([
            'order_code' => 'CO-CS2', 'order_name' => 'ラベル対応テスト', 'status' => 'construction',
            'customer_name' => 'テスト顧客', 'address' => '愛媛県松山市1-1-1', 'created_by' => 1,
            'construction_start_date'   => '2026-05-15',
            'scheduled_completion_date' => '2026-06-25',
        ]);

        $html       = $this->actingAs($this->manager())->get("/housing/custom-orders/{$order->id}")->assertOk()->getContent();
        $normalized = preg_replace('/\s+/', ' ', $html);

        $this->assertStringNotContainsString('実際の完成日', $html);
        $this->assertStringContainsString(
            '着工予定日</div> <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">2026/05/15</div>',
            $normalized,
            '着工予定日のラベル直後に着工予定日の値（2026/05/15）が無い'
        );
        $this->assertStringContainsString(
            '完成予定日</div> <div style="padding: 10px 14px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">2026/06/25</div>',
            $normalized,
            '完成予定日のラベル直後に完成予定日の値（2026/06/25）が無い'
        );
    }

    public function test_saving_a_property_stores_the_construction_start_date(): void
    {
        $property = HsProperty::create([
            'property_code' => 'HS-CS1', 'property_name' => '着工テスト', 'status' => 'construction',
            'address' => '愛媛県松山市1-1-1', 'created_by' => 1,
            'construction_start_date' => '2026-02-19',
        ]);

        $this->assertSame('2026-02-19', $property->fresh()->construction_start_date->toDateString());
    }

    /** 和名が無いとエラー文に英字 `construction start date` が出る（Bug #37） */
    public function test_the_validation_attribute_has_a_japanese_name(): void
    {
        $attributes = require base_path('lang/ja/validation.php');

        $this->assertSame('着工予定日', $attributes['attributes']['construction_start_date'] ?? null);
        $this->assertArrayNotHasKey('actual_completion_date', $attributes['attributes']);
    }

    // ============================================================
    // 取込による自動入力（設計書 §7）
    // ============================================================

    /** 取込のプレビュー → 確定を、画面が描いたフォームどおりに往復する */
    private function importRows(HsProperty $property, array $rows): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->manager())->post(
            route('housing.properties.schedule-import.execute', $property),
            ['rows_json' => json_encode($rows, JSON_UNESCAPED_UNICODE)]
        );
    }

    /** @return list<array<string, mixed>> */
    private function importableRows(): array
    {
        return [
            ['name' => '仮設工事 / 仮囲', 'category' => 'work', 'planned_start' => '2026-02-19', 'planned_end' => '2026-02-19', 'notes' => '', 'sort_order' => 1],
            ['name' => '基礎工事 / 配筋', 'category' => 'work', 'planned_start' => '2026-03-05', 'planned_end' => '2026-03-20', 'notes' => '', 'sort_order' => 2],
            ['name' => '検査 / 竣工検査', 'category' => 'permit', 'planned_start' => '2026-09-20', 'planned_end' => '2026-09-27', 'notes' => '', 'sort_order' => 3],
        ];
    }

    public function test_importing_sets_the_construction_start_and_completion_dates(): void
    {
        $property = $this->makeParent('property', ['property_code' => 'HS-IMP1']);

        $this->importRows($property, $this->importableRows())->assertRedirect();

        $fresh = $property->fresh();
        $this->assertSame('2026-02-19', $fresh->construction_start_date->toDateString(), '最も早い開始日');
        $this->assertSame('2026-09-27', $fresh->scheduled_completion_date->toDateString(), '最も遅い終了日');
    }

    /** ⚠ 常に上書きする（設計書 §7.2） */
    public function test_importing_overwrites_dates_that_were_already_there(): void
    {
        $property = $this->makeParent('property', [
            'property_code'             => 'HS-IMP2',
            'construction_start_date'   => '2020-01-01',
            'scheduled_completion_date' => '2020-12-31',
        ]);

        $this->importRows($property, $this->importableRows());

        $fresh = $property->fresh();
        $this->assertSame('2026-02-19', $fresh->construction_start_date->toDateString());
        $this->assertSame('2026-09-27', $fresh->scheduled_completion_date->toDateString());
    }

    /**
     * ⚠ **2 つは独立。** `planned_end` が 1 つも無い取込で完成予定日を潰さない。
     *   「両方そろわなければ何もしない」にもしない（片方だけ入ることはありうる）。
     *
     * ⚠ **プランは逆方向（`planned_start` が 1 つも無い取込）で書かれていたが、実測すると
     *   HTTP 経由では再現できない。** `ScheduleImportSheet::sanitizeSubmittedRows()` は
     *   `planned_start` の無い行を「壊れた行」として rowErrors に積み、`continue` で
     *   `$rows` から外す（buildRow() に一度も到達しない）。その行が唯一の送信行だと
     *   `execute()` の `if ($sanitized['rowErrors'] !== [] || $sanitized['rows'] === [])` が
     *   真になり、取込全体が `back()->withErrors(...)` で拒否される——`derivedDates()` は
     *   一度も呼ばれない。実測（`ScheduleImportSheet::sanitizeSubmittedRows()` に
     *   `planned_start: null` の行を直接通す）: rows=0 / rowErrors=1
     *   （"1 行目: 施工開始日「」を日付として読めません。"）。
     *   よって「開始日だけ無い」行は $sanitized['rows'] に届く前に排除される一方、
     *   `planned_end` は任意（milestone 行として正当）なので、逆方向のみ HTTP から
     *   到達できる。ここでは reachable な方向で独立性を検証し、到達不能な方向は
     *   下の test_derived_dates_... で `derivedDates()` を直接呼んで確かめる。
     */
    public function test_a_missing_end_date_leaves_the_completion_date_alone(): void
    {
        $property = $this->makeParent('property', [
            'property_code'             => 'HS-IMP3',
            'scheduled_completion_date' => '2020-12-31',
        ]);

        $this->importRows($property, [
            ['name' => '開始日だけの工程', 'category' => 'work', 'planned_start' => '2026-09-27', 'planned_end' => null, 'notes' => '', 'sort_order' => 1],
        ])->assertRedirect();

        $fresh = $property->fresh();
        $this->assertSame('2026-09-27', $fresh->construction_start_date->toDateString(), '着工予定日は更新する');
        $this->assertSame('2020-12-31', $fresh->scheduled_completion_date->toDateString(), '終了日が無いので完成予定日は据え置き');
    }

    /**
     * `ScheduleImportController::derivedDates()` を直接呼び、HTTP からは到達できない
     * 「開始日が 1 つも無い」側の分岐まで確かめる（上のテストの注記のとおり、
     * `sanitizeSubmittedRows()` を経由すると開始日の無い行は execute() 到達前に
     * 全体が差し戻されるため）。**この直接呼び出しが無いと、`derivedDates()` の
     * `$starts === [] ? null : min($starts)` を `min($starts)` 決め打ちに変異させても
     * どのテストも検出できない**（HTTP 経由の全テストで $starts は必ず非空になるため）。
     */
    public function test_derived_dates_treats_the_two_fields_independently(): void
    {
        $this->assertSame(
            ['construction_start_date' => null, 'scheduled_completion_date' => null],
            \App\Http\Controllers\Housing\ScheduleImportController::derivedDates([]),
            '行が 1 つも無ければ両方 null'
        );

        $this->assertSame(
            ['construction_start_date' => null, 'scheduled_completion_date' => '2026-09-27'],
            \App\Http\Controllers\Housing\ScheduleImportController::derivedDates([
                ['planned_start' => null, 'planned_end' => '2026-09-27'],
            ]),
            '開始日が 1 つも無ければ着工予定日は null（HTTP からは到達しない分岐）'
        );

        $this->assertSame(
            ['construction_start_date' => '2026-09-27', 'scheduled_completion_date' => null],
            \App\Http\Controllers\Housing\ScheduleImportController::derivedDates([
                ['planned_start' => '2026-09-27', 'planned_end' => null],
            ]),
            '終了日が 1 つも無ければ完成予定日は null'
        );

        $this->assertSame(
            ['construction_start_date' => '2026-02-19', 'scheduled_completion_date' => '2026-09-27'],
            \App\Http\Controllers\Housing\ScheduleImportController::derivedDates($this->importableRows()),
            '最小の開始日・最大の終了日を独立に出す'
        );
    }

    /**
     * ⚠ **工程と親の日付は同じトランザクションで**（設計書 §7.3）。
     *   片方だけ通ると工程とヘッダーの数字が食い違ったまま残る。
     */
    public function test_the_steps_and_the_parent_dates_move_together(): void
    {
        $property = $this->makeParent('property', ['property_code' => 'HS-IMP4']);

        $this->importRows($property, $this->importableRows());

        $this->assertSame(3, $property->scheduleSteps()->count());
        $this->assertSame('2026-02-19', $property->fresh()->construction_start_date->toDateString());

        // 取り込んだ工程の端と、親に入った日付が一致していること（別ソースにしない。Bug #46）
        //
        // ⚠ **両辺を Y-m-d に揃える。** min() はクエリビルダの集約なので DB の生値
        //   （SQLite では時刻付き '2026-02-19 00:00:00'）が返り、toDateString()（'2026-02-19'）
        //   と形が合わない。見たいのは値であって文字列表現ではないので、両辺を正規化する。
        //   片方だけリテラル比較に弱めると「工程の端と同じソース」という不変条件が消える。
        $this->assertSame(
            \Carbon\CarbonImmutable::parse($property->scheduleSteps()->min('planned_start'))->toDateString(),
            $property->fresh()->construction_start_date->toDateString()
        );
    }

    /**
     * `derivedDates()` が構築する `$dates` を、execute() と**同じ式**
     * （`array_filter(..., fn ($v) => $v !== null)` → `$property->fill($dates)->save()`）
     * に通し、開始日が 1 つも無いときに着工予定日が本当に据え置かれることを確かめる。
     *
     * ⚠ **HTTP 経由では再現できない。** 上の「終了日だけ無い」テストの注記のとおり、
     *   `planned_start` の無い行は `sanitizeSubmittedRows()` の時点で rowErrors に落ち、
     *   execute() が取込全体を差し戻すため、この分岐に生きたデータで到達できない。
     *   ここでは execute() の該当 2 行を**逐語再現**して、その式が正しく動くことを直接確かめる。
     *   `test_a_missing_end_date_leaves_the_completion_date_alone`
     *   （$dates から scheduled_completion_date キーが落ちるケース）と対で見ることで、
     *   同じ汎用機構（キーの有無で列を区別しない array_filter + fill + save）が
     *   どちらの列でも対称に働くことを示す。
     */
    public function test_a_missing_start_date_leaves_the_construction_start_date_alone(): void
    {
        $property = $this->makeParent('property', [
            'property_code'           => 'HS-IMP5',
            'construction_start_date' => '2020-01-01',
        ]);

        // execute() の DB::transaction 内と同じ式
        $dates = array_filter(
            \App\Http\Controllers\Housing\ScheduleImportController::derivedDates([
                ['planned_start' => null, 'planned_end' => '2026-09-27'],
            ]),
            fn ($v) => $v !== null
        );
        $property->fill($dates)->save();

        $fresh = $property->fresh();
        $this->assertSame('2020-01-01', $fresh->construction_start_date->toDateString(), '開始日が無いので着工予定日は据え置き');
        $this->assertSame('2026-09-27', $fresh->scheduled_completion_date->toDateString(), '完成予定日は更新する');
    }

    // ---- プレビューの予告 ----

    private function preview(HsProperty $property): string
    {
        return $this->actingAs($this->manager())->post(
            route('housing.properties.schedule-import.preview', $property),
            ['file' => new \Illuminate\Http\UploadedFile(
                base_path('tests/fixtures/schedule-import/list-format.xlsx'),
                'list-format.xlsx', null, null, true
            )]
        )->assertOk()->getContent();
    }

    public function test_the_preview_announces_the_dates_it_will_write(): void
    {
        $property = $this->makeParent('property', ['property_code' => 'HS-PRE1']);

        $html = $this->preview($property);

        $this->assertStringContainsString('着工予定日を', $html);
        $this->assertStringContainsString('完成予定日を', $html);
        $this->assertStringContainsString('2026/07/23', $html, '固定資産の最小の開始日');
        $this->assertStringContainsString('2026/12/25', $html, '固定資産の最大の終了日');
    }

    /** ⚠ 値が変わらないときは行を出さない（「2026/09/27 → 2026/09/27」というノイズを出さない） */
    public function test_the_preview_stays_quiet_when_a_date_would_not_change(): void
    {
        $property = $this->makeParent('property', [
            'property_code'             => 'HS-PRE2',
            'construction_start_date'   => '2026-07-23',
            'scheduled_completion_date' => '2026-12-25',
        ]);

        $html = $this->preview($property);

        $this->assertStringNotContainsString('着工予定日を', $html);
        $this->assertStringNotContainsString('完成予定日を', $html);
        $this->assertStringContainsString('取り込むと どうなるか', $html, '走査の空振りでないこと');
    }

    /**
     * ⚠ 設計書 §7.2:「プレビューは DB を書かない（現状どおり）。書き込みは確定時のみ」。
     *   `dateChanges()` が `$property->{$column}` を読むだけで書かないことを、
     *   構造でなく**挙動で**固定する（親エージェント指示 (B)）。
     */
    public function test_the_preview_does_not_write_the_dates_it_announces(): void
    {
        $property = $this->makeParent('property', ['property_code' => 'HS-PRE3']);

        $html = $this->preview($property);
        $this->assertStringContainsString('着工予定日を', $html, '前提: 予告が出ていること');

        $fresh = $property->fresh();
        $this->assertNull($fresh->construction_start_date, 'プレビューだけでは着工予定日を書き込まない');
        $this->assertNull($fresh->scheduled_completion_date, 'プレビューだけでは完成予定日を書き込まない');
    }

    /**
     * ⚠ **`preview()` は `$result['rows']` が空かどうかを見ずに `dateChanges()` を呼ぶ。**
     *   `ScheduleImportSheet::read()` は見出し行（`REQUIRED_HEADERS`）が見つかった時点で
     *   `format = FORMAT_LIST` を確定し、そのあとデータ行が 0 件でも `rows = []` のまま返す。
     *   よって「見出しは揃っているが有効な工程行が 1 つも無いファイル」を `preview()` に
     *   送ると `derivedDates([])` に到達する。`execute()` は `$sanitized['rows'] === []` を
     *   弾くのでこの経路は取込側（確定）からは見えないが、**プレビュー側にはその弾きが無い**
     *   （コード品質レビュー指摘・実測済み）。
     *
     * ⚠ **現在のコードは安全**（`$starts === [] ? null : min($starts)` のガードが効き、
     *   両方 null を返して `dateChanges()` は空配列になる）。このテストは**その安全性を
     *   固定する**——ガードを外す変異（c3 と同型）を当てたとき、execute() 経由の
     *   HTTP テストは 1 本も検出できないが、このテストは 500 として検出する。
     */
    public function test_the_preview_does_not_crash_on_a_file_with_headers_but_no_data_rows(): void
    {
        $property = $this->makeParent('property', ['property_code' => 'HS-PRE4']);

        $path = sys_get_temp_dir() . '/schedule-import-headers-only-' . bin2hex(random_bytes(8)) . '.xlsx';

        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            // ScheduleImportSheet::REQUIRED_HEADERS と同じ見出しを 3 行目に置く。データ行は無し。
            $sheet->setCellValue('A3', '大工程名');
            $sheet->setCellValue('B3', '工程名');
            $sheet->setCellValue('E3', '施工開始日');
            $sheet->setCellValue('H3', '施工完了日');
            $sheet->setCellValue('L3', '状態');
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

            $response = $this->actingAs($this->manager())->post(
                route('housing.properties.schedule-import.preview', $property),
                ['file' => new \Illuminate\Http\UploadedFile($path, 'headers-only.xlsx', null, null, true)]
            );

            $response->assertOk();

            $html = $response->getContent();
            $this->assertStringNotContainsString('着工予定日を', $html, '工程が無いので予告は出ない');
            $this->assertStringNotContainsString('完成予定日を', $html);
        } finally {
            @unlink($path);
        }
    }
}
