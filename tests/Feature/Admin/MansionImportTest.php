<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\MsProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesMansionSchema;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 賃貸マンション CSV 取込の振る舞い。
 *
 * ⚠ **`MansionImportController` は追加以来ノータッチ（1 コミット）で、
 *   振る舞いのテストが 1 本も無かった**（2026-08-18 時点で 1,489 行）。
 *   本番の `ms_*` が全テーブル 0 件なので誰も踏んでいないが、
 *   これから一括投入するときに初めて踏む。
 *
 * ⚠ **プレビューと確定は別の HTTP リクエスト。** 確定側は画面が持ち回った base64 から
 *   CSV を復元し、行バリデーションを最初からやり直す。よってテストも
 *   **画面が描画したフォームをそのまま分解して送り返す**（Bug #47 の往復テスト）。
 *   URL を `assertSee` で見るだけでは配線の半分も押さえられない。
 */
class MansionImportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMansionSchema;
    // htmlAttr()（`data-name=` や Alpine バインドを除外する実測済みの罠込み）を借りる。
    // 属性の取り出しを書き直すと、そこに書かれた注意書きごと drift する。
    use ParsesForms;

    /**
     * 確定フォームの送信ボタン。
     *
     * ⚠ **素の「インポート実行」で探してはいけない。** 同じ語が `<form>` の**外**にある
     *   セクション見出しにも出るため（実測: 1 ページに 2 箇所）、そこから `<form` を遡ると
     *   レイアウト先頭の**ログアウトフォーム**を掴む。必ず `<button>` ごと探す。
     */
    private const IMPORT_BUTTON_PATTERN = '/<button\b[^>]*>\s*インポート実行/u';

    /** 全行がエラーのときにプレビューが出す文言（フォームの代わりに描画される）。 */
    private const NO_IMPORTABLE_ROWS = 'インポート可能なデータがありません。CSVを修正してください。';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMansionSchema();
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** プレビューを描画させる（確定はしない）。 */
    private function preview(string $tab, string $csv): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->executive())->post("/admin/mansion-import/{$tab}", [
            'csv_file' => UploadedFile::fake()->createWithContent('t.csv', "\xEF\xBB\xBF" . $csv),
        ]);
    }

    /**
     * プレビュー → 確定の往復。
     *
     * **画面が描画した「インポート実行」フォームを分解し、その `action` へ、その hidden を
     * そのまま送り返す**（Bug #47）。送信先も送信内容も自前で組み立てないので、
     * hidden の名前が変わっても・`action` が別タブへ向いても・`@csrf` が消えても赤くなる。
     *
     * ⚠ 以前は `csv_data` だけを抜いて残りを手で組んでいた。実測で
     *   `name="confirmed"` を `name="confirmed_x"` に変えても**緑のまま**通り、
     *   本番では「インポート実行」を押すたびにプレビューが再表示されるだけで
     *   1 件も登録されない（エラーも出ない）状態を素通りさせていた。
     */
    private function confirm(string $tab, string $csv): \Illuminate\Testing\TestResponse
    {
        $preview = $this->preview($tab, $csv);
        $preview->assertStatus(200);

        $form = $this->parseImportForm($preview->getContent(), $tab);

        return $this->actingAs($this->executive())->post($form['action'], $form['fields']);
    }

    /**
     * プレビュー画面の「インポート実行」フォームを、ブラウザと同じように分解する。
     *
     * @return array{action: string, fields: array<string, string>}
     */
    private function parseImportForm(string $html, string $tab): array
    {
        $found = preg_match(self::IMPORT_BUTTON_PATTERN, $html, $m, PREG_OFFSET_CAPTURE);
        $this->assertSame(1, $found, "プレビュー画面に「インポート実行」ボタンが無い（tab={$tab}）");

        $buttonPos = $m[0][1];

        $open = strrpos(substr($html, 0, $buttonPos), '<form');
        $this->assertNotFalse($open, "「インポート実行」ボタンを囲む <form> の開始タグが無い（tab={$tab}）");

        $close = strpos($html, '</form>', $open);
        $this->assertNotFalse($close, "「インポート実行」ボタンを囲む <form> が閉じていない（tab={$tab}）");
        // 手前の別フォーム（レイアウトのログアウト等）を掴んでいないことの確認
        $this->assertGreaterThan(
            $buttonPos,
            $close,
            "「インポート実行」ボタンが <form> の外にある（tab={$tab}）"
        );

        $form    = substr($html, $open, $close - $open);
        $openTag = substr($form, 0, strpos($form, '>') + 1);

        // 確定フォームは「そのプレビューを描いたタブ自身」の endpoint へ戻さねばならない。
        // 別タブを指していると、押しても 1 件も登録されないまま別のタブへ飛ぶ。
        $this->assertSame(
            url("/admin/mansion-import/{$tab}"),
            (string) $this->htmlAttr($openTag, 'action'),
            "「インポート実行」フォームの action が別の endpoint を指している（tab={$tab}）"
        );

        $fields = [];
        preg_match_all('/<input\b[^>]*>/i', $form, $inputs);
        foreach ($inputs[0] as $tag) {
            if (strtolower((string) $this->htmlAttr($tag, 'type')) !== 'hidden') {
                continue;
            }
            $name = $this->htmlAttr($tag, 'name');
            if ($name !== null) {
                $fields[$name] = $this->htmlAttr($tag, 'value') ?? '';
            }
        }

        // 確定フラグが無いと、押しても `boolean('confirmed')` が false のまま
        // プレビューが再描画されるだけで 1 件も登録されない（エラーも出ない）。
        // ⚠ 往復だけでも赤くはなるが、落ち方が「ファイル未選択の差し戻し」になり
        //   理由が読めない（実測: assertRedirect の失敗が
        //   `Call to a member function all() on array` という別物の fatal に化ける）。
        //   名前を変えるならコントローラの `boolean('confirmed')` と対で直すこと。
        $this->assertArrayHasKey('confirmed', $fields, "「インポート実行」フォームに confirmed hidden が無い（tab={$tab}）");

        // ⚠ `@csrf` の欠落は Feature テストでは**原理的に挙動から検出できない**
        //   （`VerifyCsrfToken::handle()` が `runningUnitTests()` で素通りする）。
        //   描画された `_token` hidden の存在を見るのが唯一の手。Bug #47。
        $this->assertArrayHasKey('_token', $fields, "「インポート実行」フォームに @csrf が無い（tab={$tab}）");

        return [
            'action' => (string) $this->htmlAttr($openTag, 'action'),
            'fields' => $fields,
        ];
    }

    /**
     * 全行がエラーの CSV では、プレビューが**取込の入口を 1 つも描かない**ことを固定する。
     *
     * ⚠ 確定フォームは `@if($validCount > 0)` に囲まれているので、
     *   正常行が 0 件だと**フォームごと消える**（実測: `name="confirmed"` も `name="csv_data"` も
     *   HTML に出ない）。よって「0 件を登録しました」という完了メッセージは
     *   **画面からは到達できない**。そこを `confirm()` で叩くとブラウザにできない操作を
     *   テストが勝手に作ることになるので、そういう CSV はこちらで受ける。
     */
    private function assertPreviewOffersNoImport(string $tab, string $csv): \Illuminate\Testing\TestResponse
    {
        $preview = $this->preview($tab, $csv);
        $preview->assertStatus(200);

        $html = $preview->getContent();

        $this->assertSame(0, $preview->viewData('validCount'), "取込可能な行が残っている（tab={$tab}）");

        // コントローラが数えたエラー行が、画面にも出ていること（Bug #53: 件数と表示を突き合わせる）
        $rowErrors = $preview->viewData('rowErrors');
        $this->assertNotEmpty($rowErrors, "エラー行が 1 件も無い（tab={$tab}）");
        $this->assertStringContainsString(
            $rowErrors[0]['message'],
            $html,
            "エラー行の内容が画面に出ていない（tab={$tab}）"
        );

        $this->assertStringContainsString(
            self::NO_IMPORTABLE_ROWS,
            $html,
            "「" . self::NO_IMPORTABLE_ROWS . "」が画面に出ていない（tab={$tab}）"
        );
        $this->assertDoesNotMatchRegularExpression(
            self::IMPORT_BUTTON_PATTERN,
            $html,
            "取込できないはずのプレビューに「インポート実行」ボタンが出ている（tab={$tab}）"
        );

        return $preview;
    }

    private const PROPERTY_HEADER = '物件名,所有区分,オーナー名,郵便番号,住所,総戸数,階数,構造,築年月,備考';

    public function test_property_round_trip_creates_the_row(): void
    {
        $csv = self::PROPERTY_HEADER . "\n"
             . "ミツワレジデンス,自社所有,,790-0001,愛媛県松山市一番町1-1,20,5,RC造,2010-04,メモ\n";

        $response = $this->confirm('property', $csv);

        $response->assertRedirect('/admin/mansion-import?selected_tab=property');
        $response->assertSessionHas('success', '物件インポート完了: 1件を登録しました');

        $property = MsProperty::sole();
        $this->assertSame('ミツワレジデンス', $property->property_name);
        $this->assertSame('MS-001', $property->property_code);
        $this->assertSame('self_owned', $property->ownership_type->value);
        $this->assertSame('愛媛県松山市一番町1-1', $property->address);
        $this->assertSame(20, $property->total_units);
        $this->assertSame(5, $property->total_floors);
        $this->assertSame('2010-04', $property->built_year_month);
    }

    /**
     * 正常行が 1 件も無いプレビューには、確定ボタンもフォームも出ない。
     *
     * ⚠ これが「0 件を登録しました」を画面から出せない根拠。
     *   `confirm()` で叩けば確かに完了メッセージは返るが、それは HTTP を直接叩いた
     *   場合だけで、利用者には押すものが無い。
     */
    public function test_a_preview_with_no_valid_rows_offers_no_import(): void
    {
        $csv = self::PROPERTY_HEADER . "\n"
             . ",自社所有,,790-0001,愛媛県松山市一番町1-1,20,5,RC造,2010-04,メモ\n";

        $this->assertPreviewOffersNoImport('property', $csv)
            ->assertSee('物件名が未入力です', false);

        $this->assertSame(0, MsProperty::count());
    }

    /**
     * 物件コードは連番で採番される。
     *
     * ⚠ 本番の `ms_properties.property_code` は UNIQUE（テスト用スキーマにも入れてある）。
     *   採番が重複すると例外 → `rollBack()` で**取込全体が失敗**する（1 行も入らない）。
     */
    public function test_property_codes_are_numbered_sequentially(): void
    {
        $csv = self::PROPERTY_HEADER . "\n"
             . "A棟,自社所有,,,松山市1,,,,,\n"
             . "B棟,自社所有,,,松山市2,,,,,\n"
             . "C棟,自社所有,,,松山市3,,,,,\n";

        $this->confirm('property', $csv)->assertSessionHas('success', '物件インポート完了: 3件を登録しました');

        $this->assertSame(
            ['MS-001', 'MS-002', 'MS-003'],
            MsProperty::orderBy('id')->pluck('property_code')->all()
        );
    }

    /** 採番は既存の続きから始まる（既存 MS-001 があれば次は MS-002）。 */
    public function test_property_codes_continue_from_the_existing_maximum(): void
    {
        $this->confirm('property', self::PROPERTY_HEADER . "\n先客,自社所有,,,松山市0,,,,,\n");

        $this->confirm('property', self::PROPERTY_HEADER . "\n後客,自社所有,,,松山市9,,,,,\n");

        $this->assertSame(
            ['MS-001', 'MS-002'],
            MsProperty::orderBy('id')->pluck('property_code')->all()
        );
    }

    /**
     * CSV 内で物件名が重複したらエラー行になり、**その行だけ**落ちる。
     *
     * ⚠ 「2 行目が落ちる」ではなく「1 行目は入り 2 行目だけ落ちる」ことを見る。
     *   全体が落ちる実装に変異したときに赤くなる。
     */
    public function test_a_duplicate_name_inside_the_csv_drops_only_that_row(): void
    {
        $csv = self::PROPERTY_HEADER . "\n"
             . "同名,自社所有,,,松山市1,,,,,\n"
             . "同名,自社所有,,,松山市2,,,,,\n"
             . "別名,自社所有,,,松山市3,,,,,\n";

        $preview = $this->preview('property', $csv);
        $preview->assertStatus(200);
        $preview->assertSee('物件名「同名」がCSV内で重複しています', false);

        $this->confirm('property', $csv)->assertSessionHas('success', '物件インポート完了: 2件を登録しました');

        $this->assertSame(['同名', '別名'], MsProperty::orderBy('id')->pluck('property_name')->all());
    }

    /** DB に同名が既にあれば「スキップ」（エラーではない）。 */
    public function test_an_existing_name_is_skipped_not_errored(): void
    {
        $this->confirm('property', self::PROPERTY_HEADER . "\n先客,自社所有,,,松山市0,,,,,\n");

        $csv = self::PROPERTY_HEADER . "\n"
             . "先客,自社所有,,,松山市1,,,,,\n"
             . "新顔,自社所有,,,松山市2,,,,,\n";

        $preview = $this->preview('property', $csv);
        $preview->assertSee('物件「先客」は既に登録済みのためスキップ', false);

        $this->confirm('property', $csv)->assertSessionHas('success', '物件インポート完了: 1件を登録しました');

        $this->assertSame(2, MsProperty::count());
    }

    private const ROOM_HEADER = '物件名,部屋番号,階,間取り,面積(㎡),状態,家賃,共益費,敷金,礼金,備考';

    private function seedProperty(string $name = 'ミツワレジデンス'): MsProperty
    {
        $this->confirm('property', self::PROPERTY_HEADER . "\n{$name},自社所有,,,松山市1,,,,,\n");

        return MsProperty::where('property_name', $name)->sole();
    }

    public function test_room_round_trip_creates_the_row(): void
    {
        $property = $this->seedProperty();

        $csv = self::ROOM_HEADER . "\n"
             . "ミツワレジデンス,101,1,1K,25.50,空室,55000,3000,55000,55000,メモ\n";

        $this->confirm('room', $csv)->assertSessionHas('success', '部屋インポート完了: 1件を登録しました');

        $room = \App\Models\MsRoom::sole();
        $this->assertSame($property->id, $room->property_id);
        $this->assertSame('101', $room->room_number);
        $this->assertSame(1, $room->floor);
        $this->assertSame('vacant', $room->status->value);
        $this->assertSame(55000, $room->rent);
        $this->assertSame('25.50', $room->area_sqm);
    }

    /**
     * 物件が未登録ならエラー行になり、部屋は作られない。
     *
     * ⚠ **ここで `confirm()` を使ってはいけない。** この CSV は 1 行しかなく、その 1 行が
     *   エラーなので `validCount` が 0 になる。`_preview.blade.php` は確定フォームを
     *   `@if($validCount > 0)` で囲んでいるため、**画面には押すものが 1 つも描かれない**
     *   （代わりに「インポート可能なデータがありません。CSVを修正してください。」が出る）。
     *   `confirmed=1` を手で POST すれば「0件を登録しました」は返るが、それは
     *   **ブラウザには作れないリクエスト**なので、そういう CSV は
     *   `assertPreviewOffersNoImport()` で受ける（物件取込の同型テストと同じ扱い）。
     */
    public function test_a_room_whose_property_is_missing_is_an_error_row(): void
    {
        $this->assertPreviewOffersNoImport('room', self::ROOM_HEADER . "\n知らない物件,101,,,,,,,,,\n")
            ->assertSee('物件「知らない物件」がシステムに登録されていません', false);

        $this->assertSame(0, \App\Models\MsRoom::count());
    }

    /**
     * 同じ物件の同じ部屋番号は 2 度入らない。
     *
     * ⚠ **本番は `UNIQUE (property_id, room_number)`。** アプリ側の重複判定が
     *   死ぬと例外 → `rollBack()` で取込全体が落ちる。
     *   `CreatesMansionSchema` に UNIQUE を足してあるので、この形が本番同等で測れる。
     */
    public function test_a_room_number_already_in_the_database_is_an_error_row(): void
    {
        $this->seedProperty();
        $this->confirm('room', self::ROOM_HEADER . "\nミツワレジデンス,101,,,,,,,,,\n");

        $csv = self::ROOM_HEADER . "\n"
             . "ミツワレジデンス,101,,,,,,,,,\n"
             . "ミツワレジデンス,102,,,,,,,,,\n";

        $preview = $this->preview('room', $csv);
        $preview->assertSee('の部屋「101」は既に登録されています', false);

        $this->confirm('room', $csv)->assertSessionHas('success', '部屋インポート完了: 1件を登録しました');

        $this->assertSame(['101', '102'], \App\Models\MsRoom::orderBy('id')->pluck('room_number')->all());
    }

    /**
     * 取込後に `total_units` が実際の部屋数で上書きされる。
     *
     * ⚠ **物件取込で入れた値は捨てられる**（画面にも「※ 総戸数は部屋インポート後に
     *   自動再集計で上書きされます」と出ている）。ここを固定しておかないと、
     *   再集計を消す変異が素通りする。
     */
    public function test_total_units_is_recalculated_after_importing_rooms(): void
    {
        $this->confirm('property', self::PROPERTY_HEADER . "\nミツワレジデンス,自社所有,,,松山市1,99,,,,\n");
        $this->assertSame(99, MsProperty::sole()->total_units);

        $csv = self::ROOM_HEADER . "\n"
             . "ミツワレジデンス,101,,,,,,,,,\n"
             . "ミツワレジデンス,102,,,,,,,,,\n";

        $this->confirm('room', $csv);

        $this->assertSame(2, MsProperty::sole()->total_units);
    }

    private const PARKING_HEADER = '物件名,駐車場番号,月額料金,状態,屋根あり,備考';

    public function test_parking_round_trip_creates_the_row(): void
    {
        $property = $this->seedProperty();

        $this->confirm('parking', self::PARKING_HEADER . "\nミツワレジデンス,P-1,8000,空き,有,メモ\n")
            ->assertSessionHas('success', '駐車場インポート完了: 1件を登録しました');

        $parking = \App\Models\MsParking::sole();
        $this->assertSame($property->id, $parking->property_id);
        $this->assertSame('P-1', $parking->parking_number);
        $this->assertSame(8000, $parking->monthly_fee);
        $this->assertSame('vacant', $parking->status->value);
        $this->assertTrue($parking->has_roof);
    }

    /**
     * 「屋根あり」は表記ゆれを受ける。
     *
     * ⚠ **未入力だけが false 扱い。** `hasRoofMap` に無い値は false にはならず
     *   **エラー行になる**（`executeParking()` が「屋根あり「x」は不正な値です」を積む）。
     *   null にする変異を入れたら赤くなるよう、明示的に false を見る。
     */
    public function test_the_roof_flag_accepts_common_spellings(): void
    {
        $this->seedProperty();

        $csv = self::PARKING_HEADER . "\n"
             . "ミツワレジデンス,P-1,8000,空き,有,\n"
             . "ミツワレジデンス,P-2,8000,空き,あり,\n"
             . "ミツワレジデンス,P-3,8000,空き,無,\n"
             . "ミツワレジデンス,P-4,8000,空き,,\n";

        $this->confirm('parking', $csv);

        $this->assertSame(
            [true, true, false, false],
            \App\Models\MsParking::orderBy('id')->pluck('has_roof')->all()
        );
    }

    private const TENANT_HEADER = '区分,氏名,電話番号,メールアドレス,勤務先,緊急連絡先氏名,緊急連絡先電話,続柄,備考';

    public function test_tenant_round_trip_creates_the_row(): void
    {
        $csv = self::TENANT_HEADER . "\n"
             . "入居者,山田太郎,090-1234-5678,taro@example.com,株式会社サンプル,山田花子,090-9876-5432,配偶者,メモ\n";

        $this->confirm('tenant', $csv)->assertSessionHas('success', '入居者インポート完了: 1件を登録しました');

        $tenant = \App\Models\MsTenant::sole();
        $this->assertSame('resident', $tenant->tenant_type->value);
        $this->assertSame('山田太郎', $tenant->name);
        $this->assertSame('taro@example.com', $tenant->email);
        $this->assertSame('配偶者', $tenant->emergency_contact_relation);
    }

    /** 不正なメールアドレスはエラー行になる（その行だけ落ちる）。 */
    public function test_an_invalid_email_drops_only_that_row(): void
    {
        $csv = self::TENANT_HEADER . "\n"
             . "入居者,壊れた人,,not-an-email,,,,,\n"
             . "入居者,まともな人,,ok@example.com,,,,,\n";

        $preview = $this->preview('tenant', $csv);
        $preview->assertSee('メールアドレス「not-an-email」の形式が不正です', false);

        $this->confirm('tenant', $csv)->assertSessionHas('success', '入居者インポート完了: 1件を登録しました');

        $this->assertSame(['まともな人'], \App\Models\MsTenant::pluck('name')->all());
    }

    private const ROOM_CONTRACT_HEADER = '物件名,部屋番号,入居者名,契約日,入居日,退去日,家賃,共益費,敷金,礼金,担当者ユーザー名,メモ';

    private function seedRoomAndTenant(): void
    {
        $this->seedProperty();
        $this->confirm('room', self::ROOM_HEADER . "\nミツワレジデンス,101,,,,,,,,,\n");
        $this->confirm('tenant', self::TENANT_HEADER . "\n入居者,山田太郎,,,,,,,\n");
    }

    /**
     * 退去日が無ければ active になり、**部屋のステータスが occupied に変わる**。
     *
     * ⚠ 契約を作るだけでなく親の部屋を書き換える副作用があるので、両方見る。
     */
    public function test_an_active_room_contract_marks_the_room_occupied(): void
    {
        $this->seedRoomAndTenant();

        $csv = self::ROOM_CONTRACT_HEADER . "\n"
             . "ミツワレジデンス,101,山田太郎,2024-04-01,2024-04-15,,55000,3000,55000,55000,,メモ\n";

        $this->confirm('room-contract', $csv)
            ->assertSessionHas('success', '部屋契約インポート完了: 1件を登録しました');

        $contract = \App\Models\MsContract::sole();
        $this->assertSame('active', $contract->status->value);
        $this->assertSame('2024-04-01', $contract->contract_date->toDateString());
        $this->assertSame('2024-04-15', $contract->move_in_date->toDateString());
        $this->assertNull($contract->move_out_date);

        $this->assertSame('occupied', \App\Models\MsRoom::sole()->status->value);
    }

    /** 退去日があれば terminated になり、部屋は occupied にしない。 */
    public function test_a_terminated_room_contract_leaves_the_room_alone(): void
    {
        $this->seedRoomAndTenant();

        $csv = self::ROOM_CONTRACT_HEADER . "\n"
             . "ミツワレジデンス,101,山田太郎,2024-04-01,2024-04-15,2025-03-31,,,,,,\n";

        $this->confirm('room-contract', $csv);

        $this->assertSame('terminated', \App\Models\MsContract::sole()->status->value);
        $this->assertSame('vacant', \App\Models\MsRoom::sole()->status->value);
    }

    /**
     * 存在しない日付はエラー行になり、**その行だけ**落ちる。
     *
     * ⚠ **これが Task 6 の修正の効き目を実データ経路で見るテスト。**
     *   旧実装（`strtotime` の真偽判定）は `2026-02-30` を素通りさせ、
     *   本番 MySQL で `Incorrect date value` → `rollBack()` ＝
     *   **正しい行まで含めて 1 件も入らない**状態だった。
     */
    public function test_an_impossible_contract_date_drops_only_that_row(): void
    {
        $this->seedRoomAndTenant();
        $this->confirm('room', self::ROOM_HEADER . "\nミツワレジデンス,102,,,,,,,,,\n");
        $this->confirm('tenant', self::TENANT_HEADER . "\n入居者,鈴木次郎,,,,,,,\n");

        $csv = self::ROOM_CONTRACT_HEADER . "\n"
             . "ミツワレジデンス,101,山田太郎,2026-02-30,,,,,,,,\n"
             . "ミツワレジデンス,102,鈴木次郎,2026-04-01,,,,,,,,\n";

        $preview = $this->preview('room-contract', $csv);
        $preview->assertSee('契約日「2026-02-30」の形式が不正です', false);

        $this->confirm('room-contract', $csv)
            ->assertSessionHas('success', '部屋契約インポート完了: 1件を登録しました');

        $contract = \App\Models\MsContract::sole();
        $this->assertSame('2026-04-01', $contract->contract_date->toDateString());
    }

    /**
     * 既に契約中の部屋には**警告**が出るが、取り込みは止まらない。
     *
     * ⚠ **`assertSee` では警告とエラー行を区別できない。** 同じ文言が
     *   `warnings` ブロックにも `rowErrors` ブロックにも出しうるので、
     *   「文言が画面にある」だけでは**チャネルの取り違えを一切検出しない**。
     *   実測（2026-08-18）: コントローラの二重契約チェックを `$warnings[] =` から
     *   `$errors[] =` に変える変異で、**868 テスト全部が緑のまま**通った。
     *   → 役割は**画面の文字列でなく view データのバケツ**で見る。
     *
     * ⚠ **「それでも入る」側は、この変異に対して無反応。** 当該箇所は
     *   `$errors[]` に積んでも `continue` しない（＝行はそのまま取り込まれる）ので、
     *   契約件数のアサートは変異前後で同じ 2 件になる。件数だけを見ていると
     *   守れているように読めてしまう。**役割と件数は対で置くこと。**
     *
     * ⚠ **view データだけでも守れない**（Bug #53）。ビューが警告ブロックを
     *   描かなくなっても `viewData` は素通りするので、**画面に警告として
     *   出ていること**まで見る。サマリーの件数チップと警告一覧の本文は
     *   片方が消えても他方が残るため、**役割ごとに分けて**アサートする
     *   （Bug #43 / #46 / #49）。
     *
     * ⚠ **未カバー**: 「エラーにした上で `continue` も足す」変異までは、
     *   落ちる理由が `confirm()` の「インポート実行ボタンが無い」に化ける
     *   （`validCount` が 0 になり確定フォームごと消えるため）。赤にはなるが、
     *   二重契約の扱いが変わったことは読み取れない。
     */
    public function test_a_second_active_contract_warns_but_still_imports(): void
    {
        $this->seedRoomAndTenant();
        $this->confirm('tenant', self::TENANT_HEADER . "\n入居者,鈴木次郎,,,,,,,\n");

        $this->confirm('room-contract', self::ROOM_CONTRACT_HEADER
            . "\nミツワレジデンス,101,山田太郎,2024-04-01,,,,,,,,\n");

        $csv = self::ROOM_CONTRACT_HEADER . "\nミツワレジデンス,101,鈴木次郎,2025-04-01,,,,,,,,\n";

        $preview = $this->preview('room-contract', $csv);

        // ① 役割: 二重契約は **警告**バケツに入り、エラー行にはならない。
        $warnings = $preview->viewData('warnings');
        $this->assertCount(1, $warnings, '二重契約の警告が warnings に 1 件入っていない');
        $this->assertStringContainsString(
            'には既に契約中の入居者がいます',
            $warnings[0]['message'],
            'warnings に入っているのが二重契約の警告ではない'
        );
        $this->assertSame(
            [],
            $preview->viewData('rowErrors'),
            '二重契約が rowErrors（取込を止めるエラー行）として扱われている'
        );

        // ② 表示: コントローラが数えた警告が、画面にも警告として出ている（Bug #53）。
        //    警告一覧の本文とサマリーの件数チップは別々の描画なので、役割ごとに見る。
        $html = $preview->getContent();
        $this->assertStringContainsString(
            "⚠ 行{$warnings[0]['row']}: {$warnings[0]['message']}",
            $html,
            '警告一覧に二重契約の警告が出ていない'
        );
        $this->assertStringContainsString(
            '警告: <strong>' . count($warnings) . '</strong> 件',
            $html,
            'サマリーに警告件数が出ていない'
        );

        // ③ それでも取り込みは止まらない。
        $this->confirm('room-contract', $csv)
            ->assertSessionHas('success', '部屋契約インポート完了: 1件を登録しました');

        $this->assertSame(2, \App\Models\MsContract::count());
    }

    private const PARKING_CONTRACT_HEADER = '物件名,駐車場番号,入居者名,紐付部屋番号,契約日,開始日,終了日,月額料金,敷金,担当者ユーザー名,メモ';

    /**
     * 終了日が無ければ active になり、**駐車場のステータスが occupied に変わる**。
     *
     * ⚠ 部屋契約と同じく親を書き換える副作用があるので、契約と駐車場の両方を見る。
     */
    public function test_an_active_parking_contract_marks_the_parking_occupied(): void
    {
        $this->seedProperty();
        $this->confirm('parking', self::PARKING_HEADER . "\nミツワレジデンス,P-1,8000,空き,無,\n");
        $this->confirm('tenant', self::TENANT_HEADER . "\n駐車場利用のみ,佐藤三郎,,,,,,,\n");

        $csv = self::PARKING_CONTRACT_HEADER . "\n"
             . "ミツワレジデンス,P-1,佐藤三郎,,2024-04-01,2024-04-15,,8000,8000,,メモ\n";

        $this->confirm('parking-contract', $csv)
            ->assertSessionHas('success', '駐車場契約インポート完了: 1件を登録しました');

        $contract = \App\Models\MsParkingContract::sole();
        $this->assertSame('active', $contract->status->value);
        $this->assertSame(8000, $contract->monthly_fee);
        $this->assertNull($contract->contract_id);

        $this->assertSame('occupied', \App\Models\MsParking::sole()->status->value);
    }

    /**
     * 紐付部屋番号を指定すると、その部屋の **active な部屋契約 ID** が入る。
     *
     * ⚠ 紐付けを消す変異（常に null にする）で赤くなるよう、ID の一致まで見る。
     */
    public function test_a_linked_room_number_attaches_the_active_room_contract(): void
    {
        $this->seedRoomAndTenant();
        $this->confirm('parking', self::PARKING_HEADER . "\nミツワレジデンス,P-1,8000,空き,無,\n");
        $this->confirm('room-contract', self::ROOM_CONTRACT_HEADER
            . "\nミツワレジデンス,101,山田太郎,2024-04-01,,,,,,,,\n");

        $roomContractId = \App\Models\MsContract::sole()->id;

        $csv = self::PARKING_CONTRACT_HEADER . "\n"
             . "ミツワレジデンス,P-1,山田太郎,101,2024-04-01,2024-04-15,,8000,,,\n";

        $this->confirm('parking-contract', $csv);

        $this->assertSame($roomContractId, \App\Models\MsParkingContract::sole()->contract_id);
    }

    /** 終了日があれば terminated になり、駐車場は空きのまま。 */
    public function test_a_terminated_parking_contract_leaves_the_parking_vacant(): void
    {
        $this->seedProperty();
        $this->confirm('parking', self::PARKING_HEADER . "\nミツワレジデンス,P-1,8000,空き,無,\n");
        $this->confirm('tenant', self::TENANT_HEADER . "\n駐車場利用のみ,佐藤三郎,,,,,,,\n");

        $csv = self::PARKING_CONTRACT_HEADER . "\n"
             . "ミツワレジデンス,P-1,佐藤三郎,,2024-04-01,2024-04-15,2025-03-31,8000,,,\n";

        $this->confirm('parking-contract', $csv);

        $this->assertSame('terminated', \App\Models\MsParkingContract::sole()->status->value);
        $this->assertSame('vacant', \App\Models\MsParking::sole()->status->value);
    }
}
