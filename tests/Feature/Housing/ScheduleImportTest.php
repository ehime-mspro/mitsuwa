<?php

namespace Tests\Feature\Housing;

use App\Http\Controllers\Housing\ScheduleImportController;
use App\Models\HsProperty;
use App\Models\ScheduleStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Feature\Schedule\ScheduleTestCase;

/**
 * 工程表の取込の往復（プラン Task 6 / 8）。
 *
 * ⚠ **確定は「画面が描いたフォームをそのまま送り返す」形で測る**（Bug #47 / #54 ②）。
 *   hidden の名前を変えたり action を差し替えたりする変異は、手組みリクエストでは
 *   原理的に検出できない。
 *
 * ⚠ **ガント形式の実ファイルはまだ手元に無い。** 見出しが揃わないファイルを拒否することは
 *   test_a_file_that_is_not_the_list_format_is_rejected で測っているが、
 *   書き出し元のガント形式そのものでの確認は未了
 *   （tests/fixtures/schedule-import/README.md の gantt-format.xlsx が届いたら足すこと）。
 */
class ScheduleImportTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    private const FIXTURE = __DIR__ . '/../../fixtures/schedule-import/list-format.xlsx';

    /** @var array<int, string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }
        $this->temporary = [];
        parent::tearDown();
    }

    // ============================================================
    // 入口と権限
    // ============================================================

    public function test_a_manager_can_open_the_import_screen(): void
    {
        $property = $this->makeParent('property');

        $this->actingAs($this->manager())
            ->get(route('housing.properties.schedule-import.form', $property))
            ->assertOk()
            ->assertSee('工程表の取込')
            ->assertSee($property->property_code);
    }

    public function test_staff_cannot_open_the_import_screen(): void
    {
        $property = $this->makeParent('property');

        $this->actingAs($this->staff())
            ->get(route('housing.properties.schedule-import.form', $property))
            ->assertForbidden();
    }

    public function test_staff_cannot_execute_the_import(): void
    {
        $property = $this->makeParent('property');

        $this->actingAs($this->staff())
            ->post(route('housing.properties.schedule-import.execute', $property), ['rows_json' => '[]'])
            ->assertForbidden();

        $this->assertSame(0, ScheduleStep::count());
    }

    /**
     * ⚠ **工程表カードは 4 親の共有 partial。** 「建売に出る」だけを見ると、
     *   条件を落として他部署にボタンが生えても緑のまま通る（Bug #41 の「経路が複数」型）。
     *   4 画面すべてを見る。
     */
    public function test_the_import_button_appears_only_on_the_tateuri_detail_page(): void
    {
        foreach (self::PARENTS as $label => [$_class, $prefix, $_dept]) {
            $owner = $this->makeParent($label);

            $html = $this->actingAs($this->manager())
                ->get(route("{$prefix}.show", $owner))
                ->assertOk()
                ->getContent();

            if ($label === 'property') {
                $this->assertStringContainsString('工程表を取り込む', $html, '建売にボタンが出ていない');
                $this->assertStringContainsString(
                    route('housing.properties.schedule-import.form', $owner),
                    $html
                );
            } else {
                $this->assertStringNotContainsString('工程表を取り込む', $html, "{$label}: 建売以外にボタンが出ている");
                $this->assertStringNotContainsString('schedule-import', $html, "{$label}: 取込 URL が漏れている");
            }
        }
    }

    /** ⚠ 押せない理由を disabled な要素の title に書かず、そもそも出さない（Bug #43） */
    public function test_staff_does_not_see_the_import_button(): void
    {
        $property = $this->makeParent('property');

        $html = $this->actingAs($this->staff())
            ->get(route('housing.properties.show', $property))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('工程表を取り込む', $html);
        $this->assertStringNotContainsString('schedule-import', $html);
    }

    /**
     * ⚠ **応答ではなく、コントローラが仕事をしたかを直接見る**（Bug #48）。
     *
     *   工程表カードは `@if($scheduleCanEdit)` で編集 UI 全体を隠すので、
     *   コントローラ側の権限判定を外しても**画面の見た目は変わらない**
     *   （2026-09-01 の変異テストで実測: 16 本すべて緑のまま通った）。
     *   partial の判定が backstop になって、主機構の変異が測れなくなっている状態。
     *   view に渡す値そのものを見れば両方が独立に固定される。
     */
    public function test_the_controller_does_not_offer_the_import_url_to_staff(): void
    {
        $property = $this->makeParent('property');

        $forStaff = $this->actingAs($this->staff())->get(route('housing.properties.show', $property));
        $forStaff->assertOk();
        $this->assertNull($forStaff->viewData('scheduleImportUrl'), 'staff に取込 URL を渡してはいけない');

        $forManager = $this->actingAs($this->manager())->get(route('housing.properties.show', $property));
        $forManager->assertOk();
        $this->assertSame(
            route('housing.properties.schedule-import.form', $property),
            $forManager->viewData('scheduleImportUrl')
        );
    }

    // ============================================================
    // プレビュー
    // ============================================================

    public function test_the_preview_lists_every_step_and_shows_both_sides_of_the_match(): void
    {
        $property = $this->makeParent('property');

        $html = $this->previewFixture($property)->assertOk()->getContent();

        // ファイル側
        $this->assertStringContainsString('JG見本町3号地 分譲住宅新築工事様邸', $html);
        $this->assertStringContainsString('愛媛県松山市見本町1丁目1-1、1-2', $html);
        $this->assertStringContainsString('2026/07/28〜2026/12/25', $html);
        // 取込先側
        $this->assertStringContainsString($property->property_code, $html);
        $this->assertStringContainsString($property->property_name, $html);
        // 2 枚目にしかない工程まで出ていること
        $this->assertStringContainsString('外構工事 / 外構工事', $html);
    }

    /** ⚠ 名前が食い違っても**止めない**（設計書 §3.1 C）。警告を出しつつ確定できること */
    public function test_a_name_mismatch_warns_but_still_offers_the_confirm_form(): void
    {
        $property = $this->makeParent('property', ['property_name' => '全然ちがう物件']);

        $html = $this->previewFixture($property)->assertOk()->getContent();

        $this->assertStringContainsString('一致しません', $html);
        // 警告が出ていても確定フォームは描かれる
        $this->assertStringContainsString(
            'action="' . route('housing.properties.schedule-import.execute', $property) . '"',
            $html
        );
    }

    /** ⚠ 黙って 0 件で成功に見せない（設計書 §6） */
    public function test_a_file_that_is_not_the_list_format_is_rejected(): void
    {
        $property = $this->makeParent('property');

        $book = new Spreadsheet();
        $book->getActiveSheet()->setCellValue('A1', '工程表');
        $book->getActiveSheet()->setCellValue('B5', '2026/07/01');
        $path = $this->save($book);

        $response = $this->actingAs($this->manager())->post(
            route('housing.properties.schedule-import.preview', $property),
            ['file' => new UploadedFile($path, 'gantt.xlsx', null, null, true)]
        );

        // ⚠ **プレビューを 200 で描かないこと。** 判別を外すと 0 件のプレビューが
        //    200 で出て「取り込める工程がありません」と表示され、利用者には
        //    「空のファイルだった」と読める（＝黙って 0 件で成功に見える経路）。
        $response->assertRedirect();
        $response->assertSessionHasErrors('file');

        // ⚠ 理由まで見る。「ファイル形式が不正」のような汎用文だと、
        //    どう直せばよいか分からず書き出し元を開き直すことになる。
        $this->assertStringContainsString(
            '一覧',
            (string) session('errors')?->first('file'),
            'どの書き出しを選び直せばよいか文言で示すこと'
        );

        $this->assertSame(0, ScheduleStep::count());
    }

    public function test_the_confirm_form_carries_a_csrf_token(): void
    {
        $property = $this->makeParent('property');

        $form = $this->confirmForm($this->previewFixture($property)->getContent(), $property);

        // ⚠ @csrf の欠落は挙動からは検出できない（VerifyCsrfToken が runningUnitTests で素通り）。
        //    描画された hidden の存在を見るのが唯一の手（ParsesForms の注記）。
        $this->assertArrayHasKey('_token', $form['fields']);
        $this->assertNotSame('', $form['fields']['_token']);
    }

    // ============================================================
    // 確定（往復）
    // ============================================================

    public function test_submitting_the_rendered_form_imports_every_step(): void
    {
        $property = $this->makeParent('property');
        $form = $this->confirmForm($this->previewFixture($property)->getContent(), $property);

        $this->actingAs($this->manager())
            ->post($form['action'], $form['fields'])
            ->assertRedirect(route('housing.properties.show', $property));

        $steps = $property->scheduleSteps()->get();

        $this->assertCount(65, $steps);
        $this->assertSame(['import'], array_values(array_unique($steps->pluck('source')->all())));
        $this->assertSame(range(1, 65), $steps->pluck('sort_order')->all());

        // ⚠ 実績は触らない（設計書 §3.1 A）。
        //   ⚠ **この 2 行は挙動としては守られているが、この変異は検出しない**
        //   （実測。Bug #48）: 取込先は必ず HsProperty（実績を持たない）なので、
        //   ScheduleStep の saving フックが何を書いても null に潰す。よって
        //   ScheduleImportController::execute() の new ScheduleStep([...]) に
        //   'actual_start' => $row['planned_start'] を足しても、この 2 行は緑のまま通る。
        //   取込が実績に触らないことは test_the_importer_never_writes_actual_dates
        //   （ソースの構造）が担当する。
        $this->assertSame(0, $steps->whereNotNull('actual_start')->count());
        $this->assertSame(0, $steps->whereNotNull('actual_end')->count());

        // 分類が大工程名から引かれていること
        $counts = $steps->countBy(fn ($s) => $s->category->value)->sort()->all();
        ksort($counts);
        $this->assertSame(['other' => 4, 'permit' => 6, 'work' => 55], $counts);

        // 大工程名で区別されていること（設計書 §3 決定 2）
        $this->assertSame(
            ['電気工事 / 器具取付', '給排水設備工事 / 器具取付'],
            $steps->pluck('name')->filter(fn ($n) => str_contains($n, '器具取付'))->values()->all()
        );

        // 備考に担当会社 / 担当者 / 状態が入っていること
        $this->assertGreaterThan(0, $steps->filter(fn ($s) => str_contains((string) $s->notes, '作業前'))->count());
    }

    /** ⚠ hidden は書き換えられる。サーバ側で日付を引き直していること */
    public function test_a_tampered_payload_is_rejected_and_changes_nothing(): void
    {
        $property = $this->makeParent('property');
        $form = $this->confirmForm($this->previewFixture($property)->getContent(), $property);

        $rows = json_decode($form['fields']['rows_json'], true);
        // 存在しない日付（strtotime に戻すと通ってしまう。Bug #54）
        $rows[3]['planned_start'] = '2026/02/30';
        $form['fields']['rows_json'] = json_encode($rows, JSON_UNESCAPED_UNICODE);

        $this->actingAs($this->manager())
            ->post($form['action'], $form['fields'])
            ->assertSessionHasErrors('rows_json');

        $this->assertSame(0, ScheduleStep::count(), '1 行でも壊れていたら何も入れない');
    }

    /** ⚠ 分類は送られてきた値でなく大工程名から引き直すこと */
    public function test_the_category_is_derived_again_on_the_server(): void
    {
        $property = $this->makeParent('property');
        $form = $this->confirmForm($this->previewFixture($property)->getContent(), $property);

        $rows = json_decode($form['fields']['rows_json'], true);
        foreach ($rows as $i => $_) {
            $rows[$i]['category'] = 'sale';   // 画面から嘘の分類を送る
        }
        $form['fields']['rows_json'] = json_encode($rows, JSON_UNESCAPED_UNICODE);

        $this->actingAs($this->manager())->post($form['action'], $form['fields']);

        $this->assertSame(
            0,
            $property->scheduleSteps()->where('category', 'sale')->count(),
            '送られてきた分類をそのまま保存してはいけない'
        );
        $this->assertSame(55, $property->scheduleSteps()->where('category', 'work')->count());
    }

    // ============================================================
    // 再取込（Task 8）
    // ============================================================

    public function test_reimporting_replaces_only_the_imported_steps(): void
    {
        $property = $this->makeParent('property');

        $manual = collect(['手入力A', '手入力B', '手入力C'])->map(
            fn ($name, $i) => $this->makeStep($property, $name, null, $i + 1)
        );
        $stale = $this->makeStep($property, '古い取込工程', 'import', 10);

        $this->importFixture($property);

        $fresh = $property->scheduleSteps()->get();

        $this->assertCount(68, $fresh, '手入力 3 件 + 取込 65 件');
        $this->assertSame(3, $fresh->whereNull('source')->count(), '手入力が残っていること');
        $this->assertSame(65, $fresh->where('source', 'import')->count());

        // ⚠ 手入力は**同じ行**が残ること（消して作り直していないこと）
        $this->assertSame(
            $manual->pluck('id')->sort()->values()->all(),
            $fresh->whereNull('source')->pluck('id')->sort()->values()->all()
        );

        // ⚠ 古い取込工程は残っていないこと
        $this->assertNull(ScheduleStep::find($stale->id), '古い取込工程が消えていない');
    }

    /**
     * ⚠ **4 親は別テーブルなので id が衝突する。** 削除条件から schedulable_type を
     *   落とすと、同じ id の他の親の工程が巻き添えで消える。
     */
    public function test_reimporting_does_not_touch_another_owners_steps(): void
    {
        $property = $this->makeParent('property');
        $procurement = $this->makeParent('procurement');

        // 同じ id になるように、どちらも 1 件目として作る
        $this->assertSame($property->getKey(), $procurement->getKey(), '前提: id が衝突している');

        $other = $this->makeStep($procurement, '他案件の取込工程', 'import', 1);
        $customOrder = $this->makeParent('customOrder');
        $otherHousing = $this->makeStep($customOrder, '注文住宅の取込工程', 'import', 1);

        $this->importFixture($property);

        $this->assertNotNull(ScheduleStep::find($other->id), '仕入れ案件の工程が巻き添えで消えている');
        $this->assertNotNull(ScheduleStep::find($otherHousing->id), '注文住宅の工程が巻き添えで消えている');
        $this->assertSame(65, $property->scheduleSteps()->count());
    }

    /** ⚠ N / M / K は別々にアサートする（1 文にまとめると 1 つ壊れても緑になる） */
    public function test_the_preview_announces_what_will_be_replaced(): void
    {
        $property = $this->makeParent('property');
        $this->makeStep($property, '手入力1', null, 1);
        $this->makeStep($property, '手入力2', null, 2);
        $this->makeStep($property, '既存の取込工程', 'import', 3);

        $html = $this->previewFixture($property)->getContent();

        $this->assertMatchesRegularExpression('/既存の工程\s*<span[^>]*>1<\/span>\s*件を削除/u', $html, 'N（削除する件数）');
        $this->assertMatchesRegularExpression('/<span[^>]*>65<\/span>\s*件を登録/u', $html, 'M（登録する件数）');
        $this->assertMatchesRegularExpression('/工程\s*<span[^>]*>2<\/span>\s*件は残ります/u', $html, 'K（残る件数）');
    }

    /** 取り込んだ工程は手入力の後ろへ続けて並ぶこと */
    public function test_imported_steps_are_ordered_after_the_hand_written_ones(): void
    {
        $property = $this->makeParent('property');
        $this->makeStep($property, '手入力1', null, 1);
        $this->makeStep($property, '手入力2', null, 2);

        $this->importFixture($property);

        $imported = $property->scheduleSteps()->where('source', 'import')->get();
        $this->assertSame(range(3, 67), $imported->pluck('sort_order')->all());
    }

    // ============================================================
    // 実績は触らない（構造）— Bug #48
    // ============================================================

    /**
     * ⚠ **挙動では測れなくなった。** 取込先は必ず HsProperty（実績を持たない）なので、
     *   ScheduleStep の saving フックが何を書いても null に潰す ＝ 取込側に
     *   'actual_start' を足す変異が挙動テストでは緑のまま通る（実測。Bug #48）。
     *   取込が実績に触らないことは**ソースの構造**で固定する。
     */
    public function test_the_importer_never_writes_actual_dates(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Housing/ScheduleImportController.php'));

        // ⚠ コメントを落としてから測る（注意書きに actual_ と書いてあると自分に一致する。Bug #42 ②）
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $this->assertStringNotContainsString('actual_start', $code);
        $this->assertStringNotContainsString('actual_end', $code);
        $this->assertStringContainsString('planned_start', $code, '走査の空振りでないこと');
    }

    // ============================================================
    // ヘルパ
    // ============================================================

    private function previewFixture(HsProperty $property)
    {
        $copy = tempnam(sys_get_temp_dir(), 'sched') . '.xlsx';
        copy(self::FIXTURE, $copy);
        $this->temporary[] = $copy;

        return $this->actingAs($this->manager())->post(
            route('housing.properties.schedule-import.preview', $property),
            ['file' => new UploadedFile($copy, 'schedule-list.xlsx', null, null, true)]
        );
    }

    /** プレビュー → 描画されたフォームをそのまま送り返す */
    private function importFixture(HsProperty $property): void
    {
        $form = $this->confirmForm($this->previewFixture($property)->getContent(), $property);
        $this->actingAs($this->manager())->post($form['action'], $form['fields'])->assertRedirect();
    }

    /** @return array{method: string, action: string, fields: array<string, string>} */
    private function confirmForm(string $html, HsProperty $property): array
    {
        return $this->parseForm(
            $html,
            'action="' . route('housing.properties.schedule-import.execute', $property) . '"'
        );
    }

    private function makeStep($owner, string $name, ?string $source, int $order): ScheduleStep
    {
        $step = new ScheduleStep([
            'name' => $name, 'category' => 'work',
            'planned_start' => '2026-05-01', 'planned_end' => '2026-05-02',
            'source' => $source,
        ]);
        $step->schedulable()->associate($owner);
        $step->sort_order = $order;
        $step->save();

        return $step;
    }

    private function save(Spreadsheet $book): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sched') . '.xlsx';
        (new XlsxWriter($book))->save($path);
        $this->temporary[] = $path;

        return $path;
    }
}
