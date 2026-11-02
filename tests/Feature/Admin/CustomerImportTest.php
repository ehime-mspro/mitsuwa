<?php

namespace Tests\Feature\Admin;

use App\Enums\BuyerRank;
use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\SurveyQuestion;
use App\Models\User;
use App\Support\BuyerCsvRow;
use App\Support\CsvImportReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Concerns\CreatesSurveyQuestionSchema;
use Tests\Concerns\DrivesAlpineFetch;
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
    use DrivesAlpineFetch;
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

    /** 設問の列の見出しが今の設問と合わないときの、最後の文（設計書 2026-11-02-survey-question-admin-design.md §4.4・D8） */
    private const REDOWNLOAD_TEMPLATE = '設問の並びや文が変わっています。テンプレートをダウンロードし直してください。';

    /** 取込の画面のテンプレートの案内（前のテンプレートは断られる。設計書 §4.4） */
    private const OLD_TEMPLATE_NOTE = '※ 設問の並び替え・有効／無効・設問文の変更のあとは、前にダウンロードしたテンプレートは取り込めません（ダウンロードし直してください）';

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
        return $this->upload("\xEF\xBB\xBF" . $csv, $department);
    }

    /** 取込の画面から、中身をそのままのバイトで上げる（Shift_JIS で保存したファイルなど） */
    private function upload(string $bytes, string $department = 'housing'): TestResponse
    {
        return $this->actingAs($this->actor)
            ->from('/admin/customers/import')
            ->post('/admin/customers/import', [
                'department' => $department,
                'csv_file'   => UploadedFile::fake()->createWithContent('customers.csv', $bytes),
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
     * 画面が描いた `csvImport()` を node の vm で作り、$steps（JavaScript）を走らせて、`result` に入れた値を返す。
     *
     * ⚠ PHP のテストからブラウザの JavaScript は動かせないので、画面が描いた `<script>` をそのまま node で読み込む
     *   （AreaBuildingMapTabTest・DatePickerMonthAgoTest と同じ流儀。Bug #47 の「振る舞いの正本は実駆動」）。
     *   文脈には何も渡さない（ブラウザより寛容にしない）。Alpine との結びつき（x-model・x-show・x-on・:disabled）は
     *   構造で見る（test_a_preview_of_only_duplicates_hides_the_button_until_the_check・
     *   test_the_confirmation_form_guards_against_a_second_press）
     *
     * @param  string  $steps  `data`（csvImport() が返したもの）を使い、返したい値を `result` に入れる
     */
    private function csvImportInNode(string $html, string $steps): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので確定の欄の JavaScript の実駆動を飛ばす');
        }

        $found = preg_match('/<script>\s*(function csvImport\(\) \{.*?)<\/script>/su', $html, $m);
        $this->assertSame(1, $found, 'csvImport() の <script> が無い');

        $prelude = <<<'JS'
            const fs = require('fs');
            const vm = require('vm');
            const context = vm.createContext({});
            vm.runInContext(fs.readFileSync(process.argv[1], 'utf8'), context, { filename: 'admin/customers/import.blade.php' });
            const data = vm.runInContext('csvImport()', context);
            let result;
            JS;
        $harness = $prelude . "\n" . $steps . "\nprocess.stdout.write(JSON.stringify(result));\n";

        $file = tempnam(sys_get_temp_dir(), 'csv-import-js-');
        try {
            file_put_contents($file, $m[1]);
            $output = shell_exec(sprintf('%s -e %s %s 2>&1', escapeshellarg($node), escapeshellarg($harness), escapeshellarg($file)));
        } finally {
            unlink($file);
        }

        $result = json_decode((string) $output, true);
        $this->assertIsArray($result, "node で csvImport() を動かせなかった:\n" . $output);

        return $result;
    }

    /**
     * 確定の欄の件数（`csvImport()` の `importCount()`）。
     *
     * @return list<int>  [チェックなしの件数, チェックありの件数]
     */
    private function importCountsInNode(string $html): array
    {
        return $this->csvImportInNode($html, <<<'JS'
            result = [data.importCount()];
            data.includeDupes = true;
            result.push(data.importCount());
            JS);
    }

    /**
     * 確定のフォームを送り、転送をたどって着いた画面を返す。
     *
     * @param  string|null  $location  転送先（既定は部署なしの取込の画面。設問の列の見出しで断るときは部署つき）
     */
    private function submit(array $form, ?string $location = null): TestResponse
    {
        $response = $this->actingAs($this->actor)
            ->from('/admin/customers/import')
            ->post($form['action'], $form['fields']);

        // ⚠ assertRedirect() は使わない（検証エラーを持つ応答で外れると、失敗文を組み立てる途中で落ちて理由が読めない）
        $this->assertSame(302, $response->getStatusCode(), '確定の応答が転送になっていない');
        $this->assertSame($location ?? route('admin.customers.import'), $response->headers->get('Location'));

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

    /** 取込の画面からテンプレートをダウンロードし、見出しの行（BOM を除いた 1 行目。引用符はそのまま）を返す */
    private function templateHeader(string $department): string
    {
        $response = $this->actingAs($this->actor)->get('/admin/customers/import/template?department=' . $department);
        $response->assertStatus(200);

        return explode("\n", preg_replace('/^\xEF\xBB\xBF/', '', $response->getContent()))[0];
    }

    /**
     * テンプレートの見出しの行の下に、行を足した CSV（行は見出し => 値。書かない列は空欄）。
     * 見出しは CSV の決まり（RFC 4180）で読む（テンプレートがその形で書く）。
     *
     * @param  list<array<string, string>>  $rows
     */
    private function csvUnder(string $headerLine, array $rows): string
    {
        $columns = str_getcsv($headerLine, ',', '"', '');
        $lines   = [$headerLine];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn (string $column) => $row[$column] ?? '', $columns));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * 設問の列の見出しで断られた（プレビューも確定も描かずに、同じ部署の取込の画面へ戻された）ことを確かめ、着いた画面を返す。
     * ⚠ セッションに触らない（Bug #49）。着いた画面の入力エラーの箱で見る
     */
    private function refused(TestResponse $response, string $department = 'housing'): TestResponse
    {
        $this->assertSame(302, $response->getStatusCode(), 'プレビューか確定が描かれた（断られていない）');
        $this->assertSame(route('admin.customers.import', ['department' => $department]), $response->headers->get('Location'));

        return $this->followRedirects($response);
    }

    /** 取込の画面の入力エラーの箱の `<li>`（文字の実体参照を戻した文） */
    private function errorItems(string $html): array
    {
        $found = preg_match('/入力内容にエラーがあります。<\/p>\s*<ul\b[^>]*>(.*?)<\/ul>/su', $html, $m);
        $this->assertSame(1, $found, '取込の画面に入力エラーの箱が無い');
        preg_match_all('/<li>(.*?)<\/li>/su', $m[1], $items);

        return array_map(fn (string $item) => html_entity_decode($item, ENT_QUOTES, 'UTF-8'), $items[1]);
    }

    /** 設問の列の見出しで断られた画面: 箱に列ごとの文と最後の文が全文で並び、取込の最初のフォームに戻っている */
    private function assertRefusedForQuestionColumns(TestResponse $page, array $columnMessages): void
    {
        $page->assertStatus(200);
        $html = $page->getContent();

        $this->assertSame([...$columnMessages, self::REDOWNLOAD_TEMPLATE], $this->errorItems($html));
        $this->assertStringContainsString('id="import-form"', $html, '取込の最初のフォームに戻っていない');
        $this->assertDoesNotMatchRegularExpression(self::IMPORT_BUTTON, $html, 'プレビュー（確定のボタン）が描かれた');
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

    /**
     * 設問を 3 つ（単一選択・複数選択・スライダー）作る。見出しの Q1〜Q3 はこの並びに当たる。
     *
     * @return list<string>  CSV に足す見出し
     */
    private function surveyColumns(): array
    {
        SurveyQuestion::create([
            'department' => 'housing', 'label' => '現在のお住まい', 'question_type' => 'single_select',
            'options' => ['持ち家', '賃貸'], 'sort_order' => 1, 'is_active' => true,
        ]);
        SurveyQuestion::create([
            'department' => 'housing', 'label' => '来場のきっかけ', 'question_type' => 'multi_select',
            'options' => ['A', 'B', 'C'], 'sort_order' => 2, 'is_active' => true,
        ]);
        SurveyQuestion::create([
            'department' => 'housing', 'label' => 'ご予算', 'question_type' => 'slider',
            'settings' => ['min' => 2000, 'max' => 6000, 'step' => 100, 'unit' => '万円'], 'sort_order' => 3, 'is_active' => true,
        ]);

        return ['Q1:現在のお住まい', 'Q2:来場のきっかけ', 'Q3:ご予算'];
    }

    public function test_survey_columns_are_checked_in_the_preview_and_stored_in_the_saved_form(): void
    {
        // 直す前: どのセルも検査せず、書かれた文字のまま answer_value に入れていた（複数選択は画面の JSON でなく「A、B」、
        // 選択肢に無い「社宅」・刻みの外の「3550」もそのまま入った）
        $columns = $this->surveyColumns();

        $preview = $this->preview($this->csv([
            $this->person('山田', '太郎', ['Q1:現在のお住まい' => '賃貸', 'Q2:来場のきっかけ' => 'A、B', 'Q3:ご予算' => '3500']),
            // カンマで区切るセルは CSV の引用符で囲む。全角の数字と桁区切りも読める
            $this->person('佐藤', '花子', ['Q1:現在のお住まい' => '持ち家', 'Q2:来場のきっかけ' => '"B,C"', 'Q3:ご予算' => '３，５００']),
            // 既存の誤り（姓が空）と、アンケートの列の誤りは 1 件にまとめる
            $this->person('', '一', ['Q1:現在のお住まい' => '社宅']),
            $this->person('鈴木', '二', ['Q2:来場のきっかけ' => 'A、X', 'Q3:ご予算' => '3550']),
            // 答えが 1 つも無い行は、アンケートを作らない
            $this->person('高橋', '三'),
        ], $columns));

        $expected = [
            ['row' => 4, 'message' => '姓が未入力です／Q1（現在のお住まい）の「社宅」は選択肢にありません'],
            ['row' => 5, 'message' => 'Q2（来場のきっかけ）の「X」は選択肢にありません／Q3（ご予算）は2000〜6000万円の100万円刻みで選んでください'],
        ];

        // 役割（コントローラが数えたエラー）と表示（画面の「⚠ 行N: …」）を別々に見る（Bug #54 ④）
        $this->assertSame($expected, $preview->viewData('rowErrors'));
        $this->assertSame(3, $preview->viewData('validCount'));
        foreach ($expected as $error) {
            $preview->assertSee("⚠ 行{$error['row']}: {$error['message']}");
        }

        $this->submit($this->confirmForm($preview))->assertSee('3件のインポートが完了しました。');

        $this->assertSame(['山田', '佐藤', '高橋'], Buyer::orderBy('id')->pluck('last_name')->all());
        $this->assertSame(2, BuyerSurvey::count(), '答えの無い行にアンケートを作った');

        $questionIds = SurveyQuestion::orderBy('sort_order')->pluck('id')->all();
        $saved = fn (string $last) => BuyerSurvey::where('buyer_id', Buyer::where('last_name', $last)->value('id'))
            ->firstOrFail()->answers()->orderBy('question_id')->pluck('answer_value', 'question_id')->all();

        $this->assertSame([
            $questionIds[0] => '賃貸',
            $questionIds[1] => '["A","B"]',
            $questionIds[2] => '3500',
        ], $saved('山田'));
        $this->assertSame([
            $questionIds[0] => '持ち家',
            $questionIds[1] => '["B","C"]',
            $questionIds[2] => '3500',
        ], $saved('佐藤'));
        $this->assertSame(0, BuyerSurvey::where('buyer_id', Buyer::where('last_name', '高橋')->value('id'))->count());
    }

    public function test_a_survey_column_error_uses_the_number_in_the_header(): void
    {
        $this->surveyColumns();

        // 見出しに Q3 だけがある CSV。誤りの文の番号は、設問の並びの何番目かでなく、見出しの番号（Q3）にする
        $preview = $this->preview($this->csv([
            $this->person('山田', '太郎', ['Q3:ご予算' => '3550']),
        ], ['Q3:ご予算']));

        $this->assertSame(
            [['row' => 2, 'message' => 'Q3（ご予算）は2000〜6000万円の100万円刻みで選んでください']],
            $preview->viewData('rowErrors'),
        );
        $preview->assertSee('⚠ 行2: Q3（ご予算）は2000〜6000万円の100万円刻みで選んでください');
    }

    public function test_two_columns_for_the_same_question_store_one_answer(): void
    {
        $this->surveyColumns();

        // 見出しに同じ「Q1:」の列が 2 つある CSV（片方は空）。csv() は見出しの名前で値を引くので、行は手で組む
        $columns = array_merge(self::HEADER, ['Q1:現在のお住まい', 'Q1:現在のお住まい']);
        $line = function (string $last, string $first, string $q1First, string $q1Second): string {
            $row = $this->person($last, $first);

            return implode(',', [...array_map(fn (string $column) => $row[$column] ?? '', self::HEADER), $q1First, $q1Second]);
        };
        $csv = implode("\n", [
            implode(',', $columns),
            $line('山田', '太郎', '賃貸', ''),
            $line('佐藤', '花子', '', '持ち家'),
        ]) . "\n";

        $preview = $this->preview($csv);
        $this->assertSame([], $preview->viewData('rowErrors'));
        $this->assertSame(2, $preview->viewData('validCount'));

        // 直す前: 確定が見出しの列ごとに回答を作り、同じ設問の回答を 2 回作って一意制約で取込全体が巻き戻った
        $this->submit($this->confirmForm($preview))->assertSee('2件のインポートが完了しました。');

        $questionId = SurveyQuestion::where('sort_order', 1)->value('id');
        $answers = fn (string $last) => BuyerSurvey::where('buyer_id', Buyer::where('last_name', $last)->value('id'))
            ->sole()->answers()->pluck('answer_value', 'question_id')->all();
        $this->assertSame([$questionId => '賃貸'], $answers('山田'));
        $this->assertSame([$questionId => '持ち家'], $answers('佐藤'));
    }

    public function test_a_survey_cell_rewritten_after_the_preview_is_checked_again_on_confirmation(): void
    {
        $columns = $this->surveyColumns();

        $preview = $this->preview($this->csv([
            $this->person('山田', '太郎', ['Q1:現在のお住まい' => '賃貸']),
            $this->person('佐藤', '花子', ['Q1:現在のお住まい' => '持ち家']),
        ], $columns));
        $form = $this->confirmForm($preview);

        // 確認画面が持ち回った CSV の「持ち家」を、選択肢に無い値に書き換える
        $csv = base64_decode($form['fields']['csv_data']);
        $this->assertStringContainsString('持ち家', $csv);
        $form['fields']['csv_data'] = base64_encode(str_replace('持ち家', '社宅', $csv));

        $this->submit($form)->assertSee('1件のインポートが完了しました。');

        $this->assertSame(['山田'], Buyer::pluck('last_name')->all());
        $this->assertSame(['賃貸'], BuyerSurvey::sole()->answers()->pluck('answer_value')->all());
    }

    // ================================================================
    // 設問の列の見出しを今の設問と照らし合わせる（D8）
    // ================================================================

    public function test_a_template_downloaded_before_reordering_is_refused(): void
    {
        $this->surveyColumns();
        $header = $this->templateHeader('housing');

        // テンプレートを取ったあとで、一覧の ↓ で Q1 と Q2 を入れ替える
        $this->actingAs($this->actor)
            ->post(route('admin.survey-questions.move', SurveyQuestion::where('label', '現在のお住まい')->sole()), ['direction' => 'down'])
            ->assertStatus(302);

        // 直す前: 見出しの番号だけで結びつけ、「賃貸」を今の Q1（来場のきっかけ）の回答として断り、「A」を今の Q2（現在のお住まい）の回答として断っていた
        // （選択肢が同じ形なら、黙って別の設問の回答として入った）
        $page = $this->refused($this->preview($this->csvUnder($header, [
            $this->person('山田', '太郎', ['Q1:現在のお住まい' => '賃貸', 'Q2:来場のきっかけ' => 'A']),
        ])));

        $this->assertRefusedForQuestionColumns($page, [
            'Q1 の列の設問「現在のお住まい」が、今の Q1「来場のきっかけ」と違います。',
            'Q2 の列の設問「来場のきっかけ」が、今の Q2「現在のお住まい」と違います。',
        ]);
        $this->assertSame(0, Buyer::count());
    }

    public function test_a_question_column_without_a_question_is_refused(): void
    {
        $this->surveyColumns();
        // 無効の設問は数えない（今の設問は有効な 3 問。テンプレートにも出ない）
        SurveyQuestion::create([
            'department' => 'housing', 'label' => 'お子様の人数', 'question_type' => 'text',
            'sort_order' => 4, 'is_active' => false,
        ]);

        // 直す前: 当たる設問の無い列は黙って捨て、プレビューは「取込可能」と出していた
        $page = $this->refused($this->preview($this->csv([
            $this->person('山田', '太郎', ['Q1:現在のお住まい' => '賃貸', 'Q4:お子様の人数' => '2', 'Q9:ご趣味' => '釣り']),
        ], ['Q1:現在のお住まい', 'Q4:お子様の人数', 'Q9:ご趣味'])));

        $this->assertRefusedForQuestionColumns($page, [
            'Q4 の列に当たる設問がありません（今の設問は 3 問です）。',
            'Q9 の列に当たる設問がありません（今の設問は 3 問です）。',
        ]);
        $this->assertSame(0, Buyer::count());
    }

    public function test_the_label_check_runs_again_on_confirmation(): void
    {
        $columns = $this->surveyColumns();

        $preview = $this->preview($this->csv([
            $this->person('山田', '太郎', ['Q2:来場のきっかけ' => 'A']),
            $this->person('佐藤', '花子'),
        ], $columns));
        $form = $this->confirmForm($preview);

        // プレビューのあとで、Q2 の設問の文を変える（確定は CSV を読み直し、見出しも照らし合わせ直す）
        SurveyQuestion::where('label', '来場のきっかけ')->update(['label' => 'ご来場のきっかけ']);

        $this->assertRefusedForQuestionColumns($this->submit($form, route('admin.customers.import', ['department' => 'housing'])), [
            'Q2 の列の設問「来場のきっかけ」が、今の Q2「ご来場のきっかけ」と違います。',
        ]);
        $this->assertSame(0, Buyer::count());
        $this->assertSame(0, BuyerSurvey::count());
    }

    /** 見出しの文と設問の文は、前後の空白（全角の空白も）を除いて比べる */
    public function test_spaces_around_the_question_text_are_ignored(): void
    {
        $this->surveyColumns();
        SurveyQuestion::where('label', '来場のきっかけ')->update(['label' => "来場のきっかけ\u{3000}"]);

        $preview = $this->preview($this->csv([
            $this->person('山田', '太郎', ["Q1:\u{3000}現在のお住まい" => '賃貸', 'Q2:来場のきっかけ' => 'A']),
        ], ["Q1:\u{3000}現在のお住まい", 'Q2:来場のきっかけ']));

        $preview->assertStatus(200);
        $this->assertSame([], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));
    }

    /**
     * Windows の Excel が Shift_JIS（cp932）で保存し直したテンプレートは、取込（CsvImportReader::decode()）が SJIS（JIS の対応）
     * として読むので「～」「－」が「〜」「−」になる。設問の列の見出しは、その字の組を 1 つにそろえてから比べる（Ruling 18）。
     */
    public function test_a_template_saved_by_excel_as_shift_jis_is_not_refused(): void
    {
        $budget = SurveyQuestion::create([
            'department' => 'housing', 'label' => 'ご予算（～万円）', 'question_type' => 'text',
            'sort_order' => 1, 'is_active' => true,
        ]);
        $loan = SurveyQuestion::create([
            'department' => 'housing', 'label' => 'ローン－頭金', 'question_type' => 'text',
            'sort_order' => 2, 'is_active' => true,
        ]);
        // ほかに読み方で変わる字（∥ ￠ ￡ ￢ は SJIS で ‖ ¢ £ ¬ になる。—（U+2014）は cp932 に無く、― は SJIS でも ― のまま）
        SurveyQuestion::create([
            'department' => 'housing', 'label' => 'ご記入の記号（∥￠￡￢）', 'question_type' => 'text',
            'sort_order' => 3, 'is_active' => true,
        ]);

        // Windows の Excel と同じく cp932 で保存する（「～」は 0x8160・「－」は 0x817C。BOM は付かない）
        $sjis = mb_convert_encoding($this->csvUnder($this->templateHeader('housing'), [
            $this->person('山田', '太郎', ['Q1:ご予算（～万円）' => '3000', 'Q2:ローン－頭金' => 'あり']),
        ]), 'SJIS-win', 'UTF-8');

        // 前提: 取込はこのファイルを SJIS として読み、「～」「－」を「〜」「−」にする（ここが崩れると直す前でも緑になる）
        $decoded = CsvImportReader::decode($sjis);
        $this->assertStringContainsString("Q1:ご予算（\u{301C}万円）", $decoded);
        $this->assertStringContainsString("Q2:ローン\u{2212}頭金", $decoded);
        $this->assertStringContainsString("Q3:ご記入の記号（\u{2016}\u{00A2}\u{00A3}\u{00AC}）", $decoded);

        // 直す前: 見出しの文が今の設問の文と違うとして、ファイルごと断っていた（ダウンロードし直しても Excel は同じ形で保存する）
        $preview = $this->upload($sjis);
        // ⚠ assertStatus() は使わない（入力エラーを持つ転送で外れると、失敗文を組み立てる途中で落ちて理由が読めない）
        if ($preview->getStatusCode() !== 200) {
            $this->fail("断られた:\n" . implode("\n", $this->errorItems($this->followRedirects($preview)->getContent())));
        }
        $this->assertSame([], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->submit($this->confirmForm($preview))->assertSee('1件のインポートが完了しました。');
        $this->assertSame(
            [$budget->id => '3000', $loan->id => 'あり'],
            BuyerSurvey::sole()->answers()->orderBy('question_id')->pluck('answer_value', 'question_id')->all(),
        );

        // 本当に違う文は、そろえたあとも断る。文には読んだ見出しをそのまま・設問の文をそのまま出す
        $loan->update(['label' => 'ローン－借入']);
        $this->assertRefusedForQuestionColumns($this->refused($this->upload($sjis)), [
            "Q2 の列の設問「ローン\u{2212}頭金」が、今の Q2「ローン－借入」と違います。",
        ]);
    }

    /**
     * 字は両側でそろえる: Mac で打った「〜」（U+301C）の設問の文は、そのまま UTF-8 のテンプレートで取り込める
     * （見出しの側だけ Windows の字にそろえると、こちらが断られる）。
     */
    public function test_a_label_with_the_jis_wave_dash_matches_its_own_template(): void
    {
        SurveyQuestion::create([
            'department' => 'housing', 'label' => "ご予算（\u{301C}万円）", 'question_type' => 'text',
            'sort_order' => 1, 'is_active' => true,
        ]);

        $preview = $this->preview($this->csvUnder($this->templateHeader('housing'), [
            $this->person('山田', '太郎', ["Q1:ご予算（\u{301C}万円）" => '3000']),
        ]));
        if ($preview->getStatusCode() !== 200) {
            $this->fail("断られた:\n" . implode("\n", $this->errorItems($this->followRedirects($preview)->getContent())));
        }
        $this->assertSame(1, $preview->viewData('validCount'));
    }

    /**
     * 設問の列の見出しで断ったときは、取込の画面を同じ部署で開き直す。案内どおりテンプレートをダウンロードし直すと、
     * その部署のテンプレートになる（Ruling 19）。
     */
    public function test_a_realestate_file_refused_for_its_question_columns_keeps_the_department(): void
    {
        SurveyQuestion::create([
            'department' => 'realestate', 'label' => 'ご希望の広さ', 'question_type' => 'text',
            'sort_order' => 1, 'is_active' => true,
        ]);

        // 直す前: 部署なしの取込の画面へ戻し、画面は住宅事業を選んだ状態で開いた（テンプレートも住宅事業のものになった）
        $page = $this->refused($this->preview($this->csv([
            $this->person('山田', '太郎', ['Q1:ご希望の面積' => '50坪']),
        ], ['Q1:ご希望の面積']), 'realestate'), 'realestate');
        $this->assertRefusedForQuestionColumns($page, ['Q1 の列の設問「ご希望の面積」が、今の Q1「ご希望の広さ」と違います。']);
        $html = $page->getContent();

        // 部署のラジオ（x-model）は、ブラウザが描いた直後の状態で不動産事業を選んでいる
        $this->assertSame('realestate', $this->browserForm($html, 'id="import-form"', 'csvImport')['fields']['department'] ?? null);

        // テンプレートのリンク（x-bind:href）は、不動産事業のテンプレートを指す
        preg_match_all('/\bx-bind:href="([^"]*)"/u', $html, $links);
        $template = array_values(array_filter($links[1], fn (string $expr) => str_contains($expr, route('admin.customers.import.template'))));
        $this->assertCount(1, $template, 'テンプレートのリンクがちょうど 1 つ無い');
        $run = $this->driveAlpine($html, 'csvImport', $this->xData($html, 'csvImport'), '', [], true, [html_entity_decode($template[0], ENT_QUOTES, 'UTF-8')]);
        $this->assertSame(route('admin.customers.import.template') . '?department=realestate', $run['evaluated'][0]);
    }

    /** 取込の画面の部署は、URL の値が 2 つの部署のどちらかのときだけ選ぶ（ほかは今までどおり住宅事業） */
    public function test_the_import_page_chooses_the_department_only_from_known_values(): void
    {
        foreach (['realestate' => 'realestate', 'housing' => 'housing', 'tenant' => 'housing', "realestate'" => 'housing', '' => 'housing'] as $query => $expected) {
            $html = $this->actingAs($this->actor)->get(route('admin.customers.import', ['department' => $query]))->assertStatus(200)->getContent();
            $this->assertSame($expected, $this->browserForm($html, 'id="import-form"', 'csvImport')['fields']['department'] ?? null, "?department={$query}");
        }

        $html = $this->actingAs($this->actor)->get('/admin/customers/import?department[]=realestate')->assertStatus(200)->getContent();
        $this->assertSame('housing', $this->browserForm($html, 'id="import-form"', 'csvImport')['fields']['department'] ?? null);
    }

    /** 取込の画面のテンプレートの案内に、前のテンプレートが断られることを書く（設計書 §4.4） */
    public function test_the_import_page_says_an_old_template_is_refused(): void
    {
        $this->actingAs($this->actor)->get('/admin/customers/import')
            ->assertStatus(200)
            ->assertSeeInOrder(['テンプレートCSVをダウンロード', self::OLD_TEMPLATE_NOTE, 'CSVファイルをアップロードするとプレビューが表示されます']);
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
        $csv   = $this->csv([$this->person('山田', '太郎')]);
        $first = $this->confirmForm($this->preview($csv));

        // 1 回目の確定が書き込みの途中で失敗した（巻き戻るが、鍵は使用済みになる）
        $failing = true;
        Buyer::creating(function () use (&$failing) {
            if ($failing) {
                throw new \RuntimeException('書き込みの失敗（テスト）');
            }
        });
        $this->submit($first)->assertSee('インポートに失敗しました: 書き込みの失敗（テスト）');
        $failing = false;

        // 案内どおり**同じ CSV** をアップロードし直すと、新しい鍵で取り込める。
        // ⚠ 別の CSV で 2 回プレビューする形では、鍵を CSV の中身から作る書き換え（上げ直しても同じ鍵になり、
        //   12 時間断られる）を見逃す（2026-09-27 のレビューで実測: その形では 29 本すべて緑）
        $second = $this->confirmForm($this->preview($csv));
        $this->assertNotSame($first['fields']['import_token'], $second['fields']['import_token'], 'プレビューごとに鍵が変わっていない');

        $this->submit($second)->assertSee('1件のインポートが完了しました。');
        $this->assertSame(['山田'], Buyer::pluck('last_name')->all());
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

    public function test_the_confirmation_form_guards_against_a_second_press(): void
    {
        $html = $this->preview($this->csv([$this->person('山田', '太郎')]))->getContent();

        // 送信の印は確定のフォームそのものに付ける（ページのどこかに在るだけでは足りない。Bug #47）
        $form    = $this->confirmFormHtml($html);
        $formTag = substr($form, 0, strpos($form, '>') + 1);
        $this->assertStringContainsString('x-on:submit="onSubmit($event)"', $formTag);
        // 「戻る」で戻った確認画面の印を下ろす。pageshow は window にしか届かない（Bug #65）
        $this->assertStringContainsString('x-on:pageshow.window="resetSubmit()"', $formTag);

        // 送信中はボタンを押せなくする。見た目は Tailwind の disabled: で切り替える
        $this->assertSame(1, preg_match('/<button\b[^>]*>(?=\s*インポート実行)/u', $html, $m), '「インポート実行」ボタンが無い');
        $button = $m[0];
        $this->assertStringContainsString(':disabled="submitting"', $button);
        $classes = preg_split('/\s+/', (string) $this->htmlAttr($button, 'class'));
        foreach (['cursor-pointer', 'disabled:cursor-not-allowed', 'disabled:opacity-60'] as $class) {
            $this->assertContains($class, $classes, "「インポート実行」ボタンに {$class} が無い");
        }
        // style に cursor があると disabled:cursor-not-allowed に勝つ（style はクラスより強い）
        $this->assertStringNotContainsString('cursor', (string) $this->htmlAttr($button, 'style'));

        // 押したことを知らせる文字は、ボタンを包む要素の中にいつも置いて、文字だけ変える（設計書 2026-09-27 §4.5）
        $wrapper = $this->buttonWrapper($html);
        $start   = strpos($html, $wrapper) + strlen($wrapper);
        $inside  = substr($html, $start, strpos($html, '</div>', $start) - $start);
        $this->assertSame(1, preg_match('/<span\b[^>]*\brole="status"[^>]*>/u', $inside, $status), 'ボタンを包む要素の中に role="status" が無い');
        $this->assertStringContainsString('x-text="submitting ? \'取り込んでいます…\' : \'\'"', $status[0]);
        // 幅が狭いとき（375px）に文字の途中で折り返さず、まとまって次の行へ移る
        $this->assertStringContainsString('display: inline-block', (string) $this->htmlAttr($status[0], 'style'));
    }

    public function test_a_second_submit_is_cancelled_until_the_page_is_shown_again(): void
    {
        $html = $this->preview($this->csv([$this->person('山田', '太郎')]))->getContent();

        // 1 回目は通して印を立て、2 回目は取り消す。「戻る」で戻った画面（pageshow → resetSubmit()）では、また 1 回通す
        $this->assertSame(
            ['before' => false, 'first' => false, 'afterFirst' => true, 'second' => true, 'afterShown' => false, 'third' => false],
            $this->csvImportInNode($html, <<<'JS'
                const press = () => {
                    const event = { cancelled: false, preventDefault() { this.cancelled = true; } };
                    data.onSubmit(event);
                    return event.cancelled;
                };
                result = { before: data.submitting };
                result.first = press();
                result.afterFirst = data.submitting;
                result.second = press();
                data.resetSubmit();
                result.afterShown = data.submitting;
                result.third = press();
                JS)
        );
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

    /**
     * テンプレートの見出しは CSV の決まり（RFC 4180）で書く: カンマ・`"` を含むセルは引用符で囲み、`"` は 2 つ重ねる。
     * 取込も見出しを同じ決まりで読む（Review Focus 2。円記号を逃がしの文字として読むと、`\"` の続く見出しが割れる）。
     */
    public function test_a_label_with_a_comma_survives_the_template(): void
    {
        // 全角の「、」・カンマ・引用符・円記号の後ろの引用符・& と <・末尾の円記号（Review Focus 2 の壊れやすい文字）。
        // ⚠ 円記号の後ろの引用符が無いと、書く側・読む側を円記号の逃がしに戻しても同じ見出しになり、テストが見分けられない
        $label    = "ご予算、ローン,\"頭金\\\"'&<\\";
        $question = SurveyQuestion::create([
            'department' => 'housing', 'label' => $label, 'question_type' => 'text',
            'sort_order' => 1, 'is_active' => true,
        ]);

        $header = $this->templateHeader('housing');

        // 直す前: 見出しをカンマでつないだだけで、設問の列が「Q1:ご予算、ローン」と後ろの文の 2 つのセルに割れた。
        // ほかの列は今までと同じ（引用符で囲まない）
        $this->assertSame(implode(',', self::HEADER) . ',"Q1:ご予算、ローン,""頭金\\""\'&<\\"', $header);
        $this->assertSame([...self::HEADER, "Q1:{$label}"], str_getcsv($header, ',', '"', ''));

        // そのテンプレートで取り込める（見出しの文が今の設問の文と同じに読める）
        $preview = $this->preview($this->csvUnder($header, [
            $this->person('山田', '太郎', ["Q1:{$label}" => '3000万円まで']),
        ]));
        // ⚠ assertStatus() は使わない（入力エラーを持つ転送で外れると、失敗文を組み立てる途中で落ちて理由が読めない。Bug #49）。
        //   断られたときは、着いた取込の画面の入力エラーの箱の文を失敗文に載せる
        $this->assertSame(
            200,
            $preview->getStatusCode(),
            $preview->getStatusCode() === 200 ? '' : "断られた:\n" . implode("\n", $this->errorItems($this->followRedirects($preview)->getContent()))
        );
        $this->assertSame([], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->submit($this->confirmForm($preview))->assertSee('1件のインポートが完了しました。');
        $this->assertSame([$question->id => '3000万円まで'], BuyerSurvey::sole()->answers()->pluck('answer_value', 'question_id')->all());
    }

    /**
     * 設問の一覧の番号・↑↓・テンプレートの Q の番号・取込の Q の番号は、同じ並び（sort_order、同点は id）で数える。
     *
     * ⚠ SQLite は同じ sort_order の設問を id の順に返すので、並びの結果を見るだけでは id の条件を外しても緑になる
     *   （本番の MySQL は同点の順を決めない）。流れた SQL を見る。
     */
    public function test_every_screen_numbers_questions_in_the_same_order(): void
    {
        $first = SurveyQuestion::create([
            'department' => 'housing', 'label' => '現在のお住まい', 'question_type' => 'text',
            'sort_order' => 1, 'is_active' => true,
        ]);
        SurveyQuestion::create([
            'department' => 'housing', 'label' => '来場のきっかけ', 'question_type' => 'text',
            'sort_order' => 1, 'is_active' => true,
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "survey_questions"')) {
                $queries[] = $query->sql;
            }
        });

        $this->actingAs($this->actor)->get(route('admin.survey-questions.index'))->assertStatus(200);
        $this->actingAs($this->actor)->post(route('admin.survey-questions.move', $first), ['direction' => 'down'])->assertStatus(302);
        $template = $this->actingAs($this->actor)->get('/admin/customers/import/template?department=housing')->getContent();
        $this->preview($this->csv([$this->person('山田', '太郎')], ['Q1:来場のきっかけ', 'Q2:現在のお住まい']))->assertStatus(200);

        // 一覧（2 部署）・↑↓・テンプレート・取込のプレビュー（↑↓ のルートの束縛は並べないので数えない）
        $ordered = array_values(array_filter($queries, fn (string $sql) => str_contains($sql, 'order by')));
        $this->assertCount(5, $ordered, "設問を並べる問い合わせの数が違う:\n" . implode("\n", $queries));
        foreach ($ordered as $sql) {
            $this->assertStringEndsWith('order by "sort_order" asc, "id" asc', $sql);
        }
        $this->assertStringContainsString(',Q1:来場のきっかけ,Q2:現在のお住まい', $template);
    }
}
