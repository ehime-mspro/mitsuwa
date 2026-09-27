<?php

namespace Tests\Feature\Admin;

use App\Enums\BuyerRank;
use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\SurveyQuestion;
use App\Models\User;
use App\Support\BuyerCsvRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Concerns\CreatesSurveyQuestionSchema;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 顧客 CSV インポート（`Admin\CustomerImportController`）のプレビュー → 確定の往復。
 *
 * ⚠ 確定（「インポート実行」）は最初のコミット 2046289d から 2026-09-26 まで一度も通っていなかった
 *   （docs/RULES.md Bug #66）。確定のフォームが送るのは `csv_data`（base64）なのに、コントローラが
 *   `csv_file` を常に必須にしていて、押すと「CSVファイルは必須です。」で取込の画面へ戻っていた。
 *   確定まで送るテストが 0 本だった（ImportPreviewRenderTest はプレビューの描画しか見ない）。
 *
 * ⚠ 確定は、プレビューが描いた確定のフォームを分解して**そのまま**送り返す（Bug #47・Bug #54 ②）。
 *   送信先も hidden も自前で組み立てない。チェックボックスは、フォームに描かれていることを確かめてから値を足す
 *   （利用者がチェックを入れるのと同じ）。
 * ⚠ 送信は from(取込の画面) で行い、転送をたどって**着いた画面で**文言を見る（Bug #63）。
 * ⚠ セッションに触らない（`assertSessionHas*()` を呼ぶと、そのあと描いた画面からエラー表示が消える。Bug #49）。
 * ⚠ 確定は確認画面 1 つにつき 1 回だけ（hidden の `import_token`。設計書 2026-09-27-customer-import-double-submit-design.md）。
 *   同じフォームを 2 回送るテストは、1 つのテストの中でキャッシュ（phpunit.xml の `CACHE_STORE=array`）が
 *   使った鍵を覚えていることに頼る。ほぼ同時の 2 回（本番の file ドライバの排他ロック）はここでは測れない
 *   （`LoginGuideTest::test_the_token_is_claimed_atomically` が `Cache::add` を使っていることを構造で守る）。
 */
class CustomerImportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;
    use CreatesSurveyQuestionSchema;
    use ParsesForms;

    /** 住宅のテンプレートと同じ並びの見出し（設問の列は無し） */
    private const HEADER = [
        '姓', '名', 'セイ', 'メイ', '生年月日', '元号', '大人人数', '子供人数', '郵便番号', '都道府県',
        '市区町村', '住所詳細', '建物名', '電話番号', 'メールアドレス', '職業', '勤務先', '勤続年数',
        '取得日', '来場分譲地名', '担当者名',
    ];

    /** 「インポート実行」ボタン（見出しにも同じ語があるので、ボタンごと探す。SubmitsImportPreview と同じ理由） */
    private const IMPORT_BUTTON = '/<button\b[^>]*>\s*インポート実行/u';

    private const NO_IMPORTABLE_ROWS = 'インポート可能なデータがありません。CSVを修正してください。';

    /** 同じ確認画面から 2 回目を送ったとき（1 回限りの鍵が使えないとき）の案内（設計書 2026-09-27 §4.4） */
    private const USED_TOKEN = 'この確認画面からは取り込めません（すでに送信したか、画面が古くなっています）。取り込まれたかは「顧客管理」で確かめられます。取り込み直すときは、CSVをアップロードし直してください。';

    /** 確定の時点で取り込める行が 0 件・重複候補はあるときの案内（設計書 2026-09-27 §4.4。チェックは勧めない） */
    private const REGISTERED_AFTER_PREVIEW = '取り込める行がありません。プレビューのあとに、同じ人が登録された可能性があります。CSVをアップロードし直して、重複候補を確かめてください。';

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        $this->createSurveyQuestionSchema();

        $this->actor = User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    // ================================================================
    // 部品
    // ================================================================

    /**
     * CSV の本文。行は見出し => 値 で渡し、書かない列は空欄にする。
     *
     * @param  list<array<string, string>>  $rows
     * @param  list<string>  $extraColumns  見出しの後ろに足す列（設問の列など）
     */
    private function csv(array $rows, array $extraColumns = []): string
    {
        $columns = array_merge(self::HEADER, $extraColumns);
        $lines   = [implode(',', $columns)];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn (string $column) => $row[$column] ?? '', $columns));
        }

        return implode("\n", $lines) . "\n";
    }

    /** 必須の 3 項目と住所（重複の確認に使う）が入った行 */
    private function person(string $last, string $first, array $cells = []): array
    {
        return $cells + ['姓' => $last, '名' => $first, '都道府県' => '愛媛県', '市区町村' => '松山市', '取得日' => '2026-09-01'];
    }

    private function existingBuyer(string $last, string $first): Buyer
    {
        return Buyer::create(['last_name' => $last, 'first_name' => $first, 'prefecture' => '愛媛県', 'city' => '松山市']);
    }

    /** 取込の画面からファイルを上げてプレビューを描かせる（Excel と同じく BOM を付ける） */
    private function preview(string $csv, string $department = 'housing'): TestResponse
    {
        return $this->actingAs($this->actor)
            ->from('/admin/customers/import')
            ->post('/admin/customers/import', [
                'department' => $department,
                'csv_file'   => UploadedFile::fake()->createWithContent('customers.csv', "\xEF\xBB\xBF" . $csv),
            ]);
    }

    /**
     * プレビューが描いた確定のフォームを、ブラウザと同じように分解する。
     *
     * @return array{method: string, action: string, fields: array<string, string>}
     */
    private function confirmForm(TestResponse $preview): array
    {
        $preview->assertStatus(200);

        $form = $this->parseForm($preview->getContent(), 'action="' . route('admin.customers.import.execute') . '"');

        $this->assertSame('POST', $form['method']);
        // ⚠ @csrf の欠落は挙動から検出できない（VerifyCsrfToken が runningUnitTests() で素通りする。Bug #47）
        $this->assertArrayHasKey('_token', $form['fields'], '確定のフォームに @csrf が無い');
        // 確定の印が抜けると、押してもプレビューが描き直されるだけになる（Bug #54 ②）
        $this->assertSame('1', $form['fields']['confirmed'] ?? null, '確定のフォームに confirmed が無い');
        // 確定は確認画面 1 つにつき 1 回だけ。鍵が描かれていないと、どの確定も断られる（設計書 2026-09-27 §4.3）
        $this->assertNotSame('', $form['fields']['import_token'] ?? '', '確定のフォームに 1 回限りの鍵（import_token）が無い');

        return $form;
    }

    /** 確定のフォームの HTML（開始タグから閉じタグの手前まで） */
    private function confirmFormHtml(string $html): string
    {
        $pos = strpos($html, 'action="' . route('admin.customers.import.execute') . '"');
        $this->assertNotFalse($pos, '確定のフォームが無い');

        $open  = strrpos(substr($html, 0, $pos), '<form');
        $close = strpos($html, '</form>', $pos);

        return substr($html, $open, $close - $open);
    }

    /** 確定のフォームに描かれたチェックボックスの開始タグ */
    private function checkboxTag(string $html, string $name): string
    {
        $found = preg_match(
            '/<input\b[^>]*(?<![\w:.@-])name="' . preg_quote($name, '/') . '"[^>]*>/u',
            $this->confirmFormHtml($html),
            $m
        );
        $this->assertSame(1, $found, "確定のフォームにチェックボックス {$name} が無い");
        $this->assertSame('checkbox', $this->htmlAttr($m[0], 'type'));

        return $m[0];
    }

    /** 利用者がチェックを入れるのと同じ: 確定のフォームに描かれたチェックボックスの value を足す */
    private function tick(TestResponse $preview, array $form, string $name): array
    {
        $form['fields'][$name] = $this->htmlAttr($this->checkboxTag($preview->getContent(), $name), 'value') ?? 'on';

        return $form;
    }

    /**
     * 確定の欄の件数（`csvImport()` の `importCount()`）を node の vm で実際に動かす。
     *
     * ⚠ PHP のテストからブラウザの JavaScript は動かせないので、画面が描いた `<script>` をそのまま node で読み込む
     *   （AreaBuildingMapTabTest・DatePickerMonthAgoTest と同じ流儀。Bug #47 の「振る舞いの正本は実駆動」）。
     *   文脈には何も渡さない（ブラウザより寛容にしない）。チェックとの結びつき（x-model）とボタンの出し分け（x-show）は
     *   構造で見る（test_a_preview_of_only_duplicates_hides_the_button_until_the_check）
     *
     * @return list<int>  [チェックなしの件数, チェックありの件数]
     */
    private function importCountsInNode(string $html): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので確定の欄の件数の実駆動を飛ばす');
        }

        $found = preg_match('/<script>\s*(function csvImport\(\) \{.*?)<\/script>/su', $html, $m);
        $this->assertSame(1, $found, 'csvImport() の <script> が無い');

        $harness = <<<'JS'
            const fs = require('fs');
            const vm = require('vm');
            const context = vm.createContext({});
            vm.runInContext(fs.readFileSync(process.argv[1], 'utf8'), context, { filename: 'admin/customers/import.blade.php' });
            const data = vm.runInContext('csvImport()', context);
            const counts = [data.importCount()];
            data.includeDupes = true;
            counts.push(data.importCount());
            process.stdout.write(JSON.stringify(counts));
            JS;

        $file = tempnam(sys_get_temp_dir(), 'csv-import-count-');
        try {
            file_put_contents($file, $m[1]);
            $output = shell_exec(sprintf('%s -e %s %s 2>&1', escapeshellarg($node), escapeshellarg($harness), escapeshellarg($file)));
        } finally {
            unlink($file);
        }

        $counts = json_decode((string) $output, true);
        $this->assertIsArray($counts, "node で csvImport() を動かせなかった:\n" . $output);

        return $counts;
    }

    /** 確定のフォームを送り、転送をたどって着いた画面を返す */
    private function submit(array $form): TestResponse
    {
        $response = $this->actingAs($this->actor)
            ->from('/admin/customers/import')
            ->post($form['action'], $form['fields']);

        // ⚠ assertRedirect() は使わない（検証エラーを持つ応答で外れると、失敗文を組み立てる途中で落ちて理由が読めない）
        $this->assertSame(302, $response->getStatusCode(), '確定の応答が転送になっていない');
        $this->assertSame(route('admin.customers.import'), $response->headers->get('Location'));

        return $this->followRedirects($response);
    }

    /**
     * 「インポート実行」ボタンを直接包む要素の開始タグ（x-show で出し分ける要素）。
     *
     * ⚠ 開始タグは引用符の中の `>` を飛ばして切り出す（`x-show="importCount() > 0"` の `>` で途切れる）。
     */
    private function buttonWrapper(string $html): string
    {
        $found = preg_match(self::IMPORT_BUTTON, $html, $m, PREG_OFFSET_CAPTURE);
        $this->assertSame(1, $found, '「インポート実行」ボタンが無い');

        $before = substr($html, 0, $m[0][1]);
        preg_match_all('/<div\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/u', $before, $tags, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($tags[0], 'ボタンを包む要素が無い');

        [$tag, $offset] = end($tags[0]);
        $this->assertStringNotContainsString('</div>', substr($before, $offset), 'ボタンを直接包む要素でない');

        return $tag;
    }

    // ================================================================
    // 確定まで通る
    // ================================================================

    public function test_confirming_the_preview_imports_every_valid_row(): void
    {
        $preview = $this->preview($this->csv([
            $this->person('山田', '太郎'),
            $this->person('佐藤', '花子', ['取得日' => '2026-09-02']),
        ]));
        $this->assertSame(2, $preview->viewData('validCount'));

        $landed = $this->submit($this->confirmForm($preview));

        $landed->assertSee('2件のインポートが完了しました。');
        $this->assertSame(2, Buyer::count());

        $pivot = Buyer::where('last_name', '佐藤')->firstOrFail()->getDepartmentPivot('housing');
        $this->assertNotNull($pivot, '部署の紐付けが無い');
        $this->assertSame('2026-09-02', $pivot->acquired_date->toDateString());
        $this->assertSame(BuyerRank::C, $pivot->rank);
    }

    public function test_the_department_chosen_for_the_preview_is_used_by_the_confirmation(): void
    {
        $preview = $this->preview($this->csv([$this->person('山田', '太郎')]), 'realestate');

        $this->submit($this->confirmForm($preview))->assertSee('1件のインポートが完了しました。');

        $buyer = Buyer::firstOrFail();
        $this->assertNotNull($buyer->getDepartmentPivot('realestate'));
        $this->assertNull($buyer->getDepartmentPivot('housing'));
    }

    public function test_values_are_converted_before_they_are_saved(): void
    {
        // 直す前: 生年月日は date キャストに任せ、元号は書かれた文字のまま、取得日は正規表現が 2 桁を要求していた
        $preview = $this->preview($this->csv([$this->person('山田', '太郎', [
            '生年月日' => '1980/1/2', '元号' => '昭和', '大人人数' => '２', '取得日' => '2026/9/1',
        ])]));

        $this->submit($this->confirmForm($preview))->assertSee('1件のインポートが完了しました。');

        $buyer = Buyer::firstOrFail();
        $this->assertSame('1980-01-02', $buyer->birth_date->toDateString());
        $this->assertSame('S', $buyer->birth_era);
        $this->assertSame(2, $buyer->family_adults);
        // 詳細画面の表示（元号を S にそろえないと「昭和.1980年 1月 2日」と崩れる）
        $this->assertSame('S.55年 1月 2日', $buyer->birth_date_display);
        $this->assertSame('2026-09-01', $buyer->getDepartmentPivot('housing')->acquired_date->toDateString());
    }

    public function test_invalid_rows_are_reported_in_the_preview_and_left_out_of_the_import(): void
    {
        // 直す前: どの行もプレビューを「正常」で通り、確定で壊れた値を書くか全行を巻き戻した（設計書 §2.2）
        $preview = $this->preview($this->csv([
            $this->person('山田', '太郎'),
            $this->person('佐藤', '一', ['生年月日' => '昭和55年1月2日']),
            $this->person('鈴木', '二', ['大人人数' => '2人']),
            $this->person('高橋', '三', ['取得日' => '2026-02-30']),
            $this->person('田中', '四', ['生年月日' => '19800102']),
        ]));

        $expected = [
            ['row' => 3, 'message' => '生年月日「昭和55年1月2日」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）'],
            ['row' => 4, 'message' => '大人人数「2人」は0〜255の整数で入力してください'],
            ['row' => 5, 'message' => '取得日「2026-02-30」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）'],
            ['row' => 6, 'message' => '生年月日「19800102」は日付として正しくありません（例: 2026-09-01 か 2026/9/1）'],
        ];

        // 役割（コントローラが数えたエラー）と表示（画面の「⚠ 行N: …」）を別々に見る（Bug #54 ④）
        $this->assertSame($expected, $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));
        foreach ($expected as $error) {
            $preview->assertSee("⚠ 行{$error['row']}: {$error['message']}");
        }

        $this->submit($this->confirmForm($preview))->assertSee('1件のインポートが完了しました。');

        $this->assertSame(['山田'], Buyer::pluck('last_name')->all());
    }

    public function test_a_survey_and_its_answers_are_imported_with_the_row(): void
    {
        $question = SurveyQuestion::create([
            'department' => 'housing', 'label' => '来場のきっかけ', 'question_type' => 'text',
            'sort_order' => 1, 'is_active' => true,
        ]);
        $projectId = DB::table('re_projects')->insertGetId([
            'project_code' => 'P-0001', 'project_name' => '南梅本の杜', 'status' => 'selling',
            'address' => '愛媛県松山市', 'created_by' => $this->actor->id,
        ]);
        $staff = User::factory()->create(['name' => '田中一郎', 'role' => UserRole::Staff->value]);

        $preview = $this->preview($this->csv([
            $this->person('山田', '太郎', ['来場分譲地名' => '南梅本', '担当者名' => '田中', 'Q1:来場のきっかけ' => '看板']),
            // 回答が空欄の行はアンケートを作らない
            $this->person('佐藤', '花子', ['担当者名' => '田中']),
        ], ['Q1:来場のきっかけ']));

        $this->submit($this->confirmForm($preview))->assertSee('2件のインポートが完了しました。');

        $survey = BuyerSurvey::sole();
        $this->assertSame(Buyer::where('last_name', '山田')->value('id'), $survey->buyer_id);
        $this->assertSame('housing', $survey->department);
        $this->assertSame('2026-09-01', $survey->survey_date->toDateString());
        $this->assertSame($projectId, $survey->project_id);
        $this->assertSame('田中', $survey->staff_name);
        $this->assertSame($staff->id, $survey->staff_user_id);

        $answer = $survey->answers()->sole();
        $this->assertSame($question->id, $answer->question_id);
        $this->assertSame('看板', $answer->answer_value);
        $this->assertSame('来場のきっかけ', $answer->question_snapshot['label']);
    }

    // ================================================================
    // 取り込める行が無いとき・重複候補
    // ================================================================

    public function test_a_preview_with_no_importable_rows_offers_no_import(): void
    {
        $preview = $this->preview($this->csv([$this->person('', '太郎')]));
        $preview->assertStatus(200);
        $html = $preview->getContent();

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertSame([], $preview->viewData('dupeRows'));
        $preview->assertSee('⚠ 行2: 姓が未入力です');
        $preview->assertSee(self::NO_IMPORTABLE_ROWS);

        // 直す前: 0 件でも「インポート実行（0件）」のボタンと csv_data を持つフォームが出ていた
        $this->assertDoesNotMatchRegularExpression(self::IMPORT_BUTTON, $html);
        $this->assertStringNotContainsString('name="csv_data"', $html);
    }

    public function test_a_preview_of_only_duplicates_hides_the_button_until_the_check(): void
    {
        $this->existingBuyer('山田', '太郎');

        $preview = $this->preview($this->csv([$this->person('山田', '太郎')]));
        $html    = $preview->getContent();

        $this->assertSame(0, $preview->viewData('validCount'));
        $this->assertCount(1, $preview->viewData('dupeRows'));

        // 最初の状態はサーバが描く（V＝0 なのでボタンを包む要素を隠して描く。設計書 §4.4）
        $wrapper = $this->buttonWrapper($html);
        $this->assertStringContainsString('x-show="importCount() > 0"', $wrapper);
        $this->assertStringContainsString('display: none', $wrapper);
        $this->assertMatchesRegularExpression('/インポート実行（<span x-text="importCount\(\)">0<\/span>件）/u', $html);
        // 理由は件数が 0 の間だけ出す（チェックを入れたら消える）
        $this->assertMatchesRegularExpression(
            '/<div x-show="importCount\(\) === 0"[^>]*>重複候補だけです。取り込むときは、上のチェックを入れてください。<\/div>/u',
            $html
        );
        // チェックが件数に結びついている（外すと、チェックを入れてもボタンが出ない）
        $this->assertStringContainsString('x-model="includeDupes"', $this->checkboxTag($html, 'include_duplicates'));

        // Alpine がチェックに合わせて数える元の値（サーバが数えた値）
        $this->assertStringContainsString('validCount: 0,', $html);
        $this->assertStringContainsString('dupeCount: 1,', $html);
    }

    public function test_confirming_only_duplicates_without_the_check_imports_nothing(): void
    {
        $this->existingBuyer('山田', '太郎');
        $preview = $this->preview($this->csv([$this->person('山田', '太郎')]));

        // 画面はボタンを隠すが、隠れたフォームがそのまま送られてきても（細工した送信と同じ形）、サーバが 0 件の確定を断る。
        // 同じ確認画面からの 2 回目は、この歯止めより先に 1 回限りの鍵が断る（test_sending_the_same_confirmation_twice_imports_once）
        $landed = $this->submit($this->confirmForm($preview));

        $landed->assertSee(self::REGISTERED_AFTER_PREVIEW);
        $landed->assertDontSee('0件のインポートが完了しました。');
        $this->assertSame(1, Buyer::count());
    }

    public function test_confirming_only_duplicates_with_the_check_imports_them(): void
    {
        $this->existingBuyer('山田', '太郎');
        $preview = $this->preview($this->csv([$this->person('山田', '太郎')]));

        $landed = $this->submit($this->tick($preview, $this->confirmForm($preview), 'include_duplicates'));

        $landed->assertSee('1件のインポートが完了しました。');
        $this->assertSame(2, Buyer::where('last_name', '山田')->count());
    }

    public function test_valid_rows_and_duplicates_show_the_button_with_the_valid_count(): void
    {
        $this->existingBuyer('山田', '太郎');

        $preview = $this->preview($this->csv([$this->person('山田', '太郎'), $this->person('佐藤', '花子')]));
        $html    = $preview->getContent();

        $this->assertSame(1, $preview->viewData('validCount'));
        $this->assertCount(1, $preview->viewData('dupeRows'));

        $wrapper = $this->buttonWrapper($html);
        $this->assertStringContainsString('x-show="importCount() > 0"', $wrapper);
        $this->assertStringNotContainsString('display: none', $wrapper);
        $this->assertMatchesRegularExpression('/インポート実行（<span x-text="importCount\(\)">1<\/span>件）/u', $html);
        $preview->assertDontSee('重複候補だけです。');
        $this->assertStringContainsString('validCount: 1,', $html);
        $this->assertStringContainsString('dupeCount: 1,', $html);
    }

    public function test_the_import_count_follows_the_duplicates_check(): void
    {
        $this->existingBuyer('山田', '太郎');

        // 重複候補だけ（V＝0・D＝1）: チェックを入れるまで 0 件（ボタンは隠れたまま）、入れると 1 件
        $onlyDupes = $this->preview($this->csv([$this->person('山田', '太郎')]));
        $this->assertSame([0, 1], $this->importCountsInNode($onlyDupes->getContent()));

        // 正常 1 件と重複候補 1 件（V＝1・D＝1）: チェックを入れると 2 件
        $mixed = $this->preview($this->csv([$this->person('山田', '太郎'), $this->person('佐藤', '花子')]));
        $this->assertSame([1, 2], $this->importCountsInNode($mixed->getContent()));
    }

    public function test_without_the_check_only_the_valid_rows_are_imported(): void
    {
        $this->existingBuyer('山田', '太郎');
        $preview = $this->preview($this->csv([$this->person('山田', '太郎'), $this->person('佐藤', '花子')]));

        $this->submit($this->confirmForm($preview))->assertSee('1件のインポートが完了しました。');

        $this->assertSame(1, Buyer::where('last_name', '山田')->count());
        $this->assertSame(1, Buyer::where('last_name', '佐藤')->count());
    }

    public function test_with_the_check_the_duplicates_are_imported_too(): void
    {
        $this->existingBuyer('山田', '太郎');
        $preview = $this->preview($this->csv([$this->person('山田', '太郎'), $this->person('佐藤', '花子')]));

        $landed = $this->submit($this->tick($preview, $this->confirmForm($preview), 'include_duplicates'));

        $landed->assertSee('2件のインポートが完了しました。');
        $this->assertSame(2, Buyer::where('last_name', '山田')->count());
        $this->assertSame(1, Buyer::where('last_name', '佐藤')->count());
    }

    // ================================================================
    // 確定でも検査をやり直す（ブラウザから届いた値を信用しない）
    // ================================================================

    /** @return array<string, array{0: string|null}> [csv_data に入れる値（null なら送らない）] */
    public static function brokenCsvData(): array
    {
        return [
            'csv_data が無い'     => [null],
            'csv_data が壊れている' => ['%%%'],
        ];
    }

    #[DataProvider('brokenCsvData')]
    public function test_a_confirmation_without_readable_csv_data_imports_nothing(?string $csvData): void
    {
        $form = $this->confirmForm($this->preview($this->csv([$this->person('山田', '太郎')])));

        if ($csvData === null) {
            unset($form['fields']['csv_data']);
        } else {
            $form['fields']['csv_data'] = $csvData;
        }

        $this->submit($form)->assertSee('CSVファイルにデータがありません。');
        $this->assertSame(0, Buyer::count());
    }

    public function test_a_confirmation_with_a_rewritten_department_is_turned_back(): void
    {
        $form = $this->confirmForm($this->preview($this->csv([$this->person('山田', '太郎')])));
        $form['fields']['department'] = 'tenant';

        $landed = $this->submit($form);

        $landed->assertSee('入力内容にエラーがあります。');
        $landed->assertSee('<li>' . trans('validation.in', ['attribute' => 'インポート先部署']) . '</li>', false);
        $this->assertSame(0, Buyer::count());
    }

    public function test_a_rewritten_csv_data_is_checked_again_on_confirmation(): void
    {
        $form = $this->confirmForm($this->preview($this->csv([$this->person('山田', '太郎')])));
        $form['fields']['csv_data'] = base64_encode($this->csv([
            $this->person('山田', '太郎'),
            $this->person('佐藤', '花子', ['取得日' => '2026-02-30']),
        ]));

        $this->submit($form)->assertSee('1件のインポートが完了しました。');

        $this->assertSame(['山田'], Buyer::pluck('last_name')->all());
    }

    public function test_a_confirmation_with_no_importable_rows_is_turned_back(): void
    {
        // 画面は 0 件のとき確定のフォームを出さないが、書き換えた csv_data で送られてもサーバが断る
        $form = $this->confirmForm($this->preview($this->csv([$this->person('山田', '太郎')])));
        $form['fields']['csv_data'] = base64_encode($this->csv([$this->person('', '太郎')]));

        $landed = $this->submit($form);

        $landed->assertSee(self::NO_IMPORTABLE_ROWS);
        $landed->assertDontSee('0件のインポートが完了しました。');
        $this->assertSame(0, Buyer::count());
    }

    public function test_a_failure_while_writing_rolls_back_every_row(): void
    {
        $form = $this->confirmForm($this->preview($this->csv([
            $this->person('山田', '太郎'),
            $this->person('佐藤', '花子'),
        ])));

        // ⚠ 2 行目で失敗させる（1 行目で失敗させると、巻き戻しを消しても 0 件のままで緑になる。Bug #64 で踏んだ形）
        $created = 0;
        Buyer::creating(function () use (&$created) {
            if (++$created === 2) {
                throw new \RuntimeException('2 行目の書き込みの失敗（テスト）');
            }
        });

        $this->submit($form)->assertSee('インポートに失敗しました: 2 行目の書き込みの失敗（テスト）');

        $this->assertSame(0, DB::table('buyers')->count(), '1 行目が巻き戻っていない');
        $this->assertSame(0, DB::table('buyer_departments')->count());
    }

    // ================================================================
    // 確定は確認画面 1 つにつき 1 回だけ（設計書 2026-09-27-customer-import-double-submit-design.md）
    // ================================================================

    public function test_sending_the_same_confirmation_twice_imports_once(): void
    {
        $form = $this->confirmForm($this->preview($this->csv([$this->person('山田', '太郎'), $this->person('佐藤', '花子')])));

        $this->submit($form)->assertSee('2件のインポートが完了しました。');
        $second = $this->submit($form);

        $second->assertSee(self::USED_TOKEN);
        // 鍵をほかの検査より先に使っている（0 件の歯止めより後ろに置くと、2 回目は 1 回目で入った人を重複候補と数えて
        // 0 件の案内に着く。設計書 2026-09-27 §4.3）
        $second->assertDontSee(self::REGISTERED_AFTER_PREVIEW);
        $second->assertDontSee('件のインポートが完了しました。');
        $this->assertSame(2, Buyer::count());
    }

    public function test_sending_a_checked_confirmation_twice_imports_once(): void
    {
        $this->existingBuyer('山田', '太郎');
        $preview = $this->preview($this->csv([$this->person('山田', '太郎'), $this->person('佐藤', '花子')]));
        $form    = $this->tick($preview, $this->confirmForm($preview), 'include_duplicates');

        $this->submit($form)->assertSee('2件のインポートが完了しました。');
        $second = $this->submit($form);

        // 直す前: チェック済みなので、2 回目は 1 回目で入った人も重複候補として取り込み、「2件のインポートが完了しました。」が
        // もう一度出て全員がもう一度入った（2026-09-27 の試作で実測: 山田 3 人・佐藤 2 人）
        $this->assertSame(2, Buyer::where('last_name', '山田')->count(), '2 回目の送信で、重複候補がもう一度入った');
        $this->assertSame(1, Buyer::where('last_name', '佐藤')->count(), '2 回目の送信で、1 回目に入った人がもう一度入った');
        $second->assertSee(self::USED_TOKEN);
    }

    public function test_each_preview_issues_its_own_token(): void
    {
        $first  = $this->confirmForm($this->preview($this->csv([$this->person('山田', '太郎')])));
        $second = $this->confirmForm($this->preview($this->csv([$this->person('佐藤', '花子')])));

        $this->assertNotSame($first['fields']['import_token'], $second['fields']['import_token'], 'プレビューごとに鍵が変わっていない');

        $this->submit($first)->assertSee('1件のインポートが完了しました。');
        // 別のプレビューの確定は、1 つ目を使ったあとでも取り込める
        $this->submit($second)->assertSee('1件のインポートが完了しました。');
        $this->assertSame(['山田', '佐藤'], Buyer::orderBy('id')->pluck('last_name')->all());
    }

    /** @return array<string, array{0: string|list<string>|null}> [import_token に入れる値（null なら送らない）] */
    public static function unusableTokens(): array
    {
        return [
            '鍵が無い' => [null],
            '鍵が空'   => [''],
            '鍵が配列' => [['a', 'b']],
        ];
    }

    #[DataProvider('unusableTokens')]
    public function test_a_confirmation_without_a_usable_token_imports_nothing(string|array|null $token): void
    {
        $form = $this->confirmForm($this->preview($this->csv([$this->person('山田', '太郎')])));

        if ($token === null) {
            unset($form['fields']['import_token']);
        } else {
            $form['fields']['import_token'] = $token;
        }

        // 500 にならない（配列の鍵は OneTimeAction::claimFrom() が is_string で断る。submit() が 302 と戻り先を見る）
        $this->submit($form)->assertSee(self::USED_TOKEN);
        $this->assertSame(0, Buyer::count());
    }

    public function test_someone_registered_after_the_preview_turns_the_confirmation_back(): void
    {
        $form = $this->confirmForm($this->preview($this->csv([$this->person('山田', '太郎')])));

        // プレビューのあとに、別の画面（別のタブ・ほかの人）で同じ人が登録された
        $this->existingBuyer('山田', '太郎');

        $landed = $this->submit($form);

        $landed->assertSee(self::REGISTERED_AFTER_PREVIEW);
        // この場面でチェックを入れると、先に登録された人がもう一度入るので勧めない（設計書 2026-09-27 §4.4）
        $landed->assertDontSee('チェックを入れてください');
        $this->assertSame(1, Buyer::count());
    }

    // ================================================================
    // テンプレート
    // ================================================================

    public function test_every_template_column_except_questions_is_a_known_column(): void
    {
        SurveyQuestion::create([
            'department' => 'housing', 'label' => '来場のきっかけ', 'question_type' => 'text',
            'sort_order' => 1, 'is_active' => true,
        ]);

        foreach (['housing', 'realestate'] as $department) {
            $csv    = $this->actingAs($this->actor)->get('/admin/customers/import/template?department=' . $department)->getContent();
            $header = str_getcsv(explode("\n", preg_replace('/^\xEF\xBB\xBF/', '', $csv))[0]);

            $unknown = array_values(array_filter(
                $header,
                fn (string $column) => preg_match('/^Q\d+:/', $column) !== 1 && ! array_key_exists($column, BuyerCsvRow::COLUMNS)
            ));

            $this->assertSame([], $unknown, "{$department} のテンプレートに、取込が読まない見出しがある");
        }
    }
}
