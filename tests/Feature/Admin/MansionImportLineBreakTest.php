<?php

namespace Tests\Feature\Admin;

use App\Models\MsContract;
use App\Models\MsParking;
use App\Models\MsProperty;
use App\Models\MsRoom;
use App\Models\MsTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMansionSchema;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * 賃貸マンションの CSV 取込で、セルの中の改行（Excel の Alt+Enter）を 1 件の中の値として読む（docs/RULES.md Bug #127）。
 *
 * 直す前（2026-11-04 に使い捨てのテストで実測）は、1 件が 2 行に割れ、改行のあるセルより後ろの列の値が黙って消えたまま
 * 取り込まれた（入居者の勤務先に改行 → 緊急連絡先・備考が空で登録）。物件の備考に改行 → 備考が切れて、
 * 割れた後ろ半分が「住所が未入力です」のエラーの行になった。
 *
 * 利用者の決定（2026-11-04）: 画面で改行を入れられる欄（物件・部屋・駐車場・入居者の「備考」、部屋契約・駐車場契約の
 * 「メモ」）は改行を残し、ほかの欄（画面の入力欄が 1 行）は空白 1 つにする。
 *
 * ⚠ CSV は Excel と同じ形（行の終わりは CRLF・セルの中の改行は LF）で作る。
 */
class MansionImportLineBreakTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMansionSchema;
    use ParsesForms;
    use SubmitsImportPreview;

    private const PROPERTY_HEADER = '物件名,所有区分,オーナー名,郵便番号,住所,総戸数,階数,構造,築年月,備考';

    private const ROOM_HEADER = '物件名,部屋番号,階,間取り,面積(㎡),状態,家賃,共益費,敷金,礼金,備考';

    private const PARKING_HEADER = '物件名,駐車場番号,月額料金,状態,屋根あり,備考';

    private const TENANT_HEADER = '区分,氏名,電話番号,メールアドレス,勤務先,緊急連絡先氏名,緊急連絡先電話,続柄,備考';

    private const ROOM_CONTRACT_HEADER = '物件名,部屋番号,入居者名,契約日,入居日,退去日,家賃,共益費,敷金,礼金,担当者ユーザー名,メモ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMansionSchema();
    }

    /** 取込画面の URL の前半（SubmitsImportPreview が使う） */
    private function importBasePath(): string
    {
        return '/admin/mansion-import';
    }

    public function test_a_property_keeps_the_note_lines_and_joins_the_address(): void
    {
        $csv = self::PROPERTY_HEADER . "\r\n"
             . "改行レジデンス,自社所有,,790-0001,\"愛媛県松山市\n一番町1-1\",20,5,RC造,2010-04,\"1行目\n2行目\"\r\n"
             . "次のレジデンス,自社所有,,790-0002,愛媛県松山市二番町,10,3,S造,,\r\n";

        $this->confirm('property', $csv)->assertSessionHas('success', '物件インポート完了: 2件を登録しました');

        $property = MsProperty::where('property_name', '改行レジデンス')->sole();
        $this->assertSame('愛媛県松山市 一番町1-1', $property->address);
        // 改行のあるセルより後ろの列も同じ行の値として入る
        $this->assertSame(20, $property->total_units);
        $this->assertSame('2010-04', $property->built_year_month);
        $this->assertSame("1行目\n2行目", $property->notes);

        $this->assertSame('S造', MsProperty::where('property_name', '次のレジデンス')->sole()->structure);
    }

    public function test_a_room_and_a_parking_keep_their_note_lines(): void
    {
        $this->confirm('property', self::PROPERTY_HEADER . "\r\nミツワレジデンス,自社所有,,,松山市1,,,,,\r\n");

        $this->confirm('room', self::ROOM_HEADER . "\r\n"
            . "ミツワレジデンス,101,1,\"1LDK\n角部屋\",40.5,,,,,,\"南向き\n日当たり良好\"\r\n")
            ->assertSessionHas('success', '部屋インポート完了: 1件を登録しました');
        $this->confirm('parking', self::PARKING_HEADER . "\r\n"
            . "ミツワレジデンス,P-1,5000,,有,\"軽自動車のみ\n要相談\"\r\n")
            ->assertSessionHas('success', '駐車場インポート完了: 1件を登録しました');

        $room = MsRoom::sole();
        $this->assertSame('1LDK 角部屋', $room->room_type);
        $this->assertSame("南向き\n日当たり良好", $room->notes);
        $this->assertSame("軽自動車のみ\n要相談", MsParking::sole()->notes);
    }

    public function test_a_tenant_with_a_line_break_in_the_workplace_keeps_the_later_columns(): void
    {
        $csv = self::TENANT_HEADER . "\r\n"
             . "入居者,改行太郎,089-111-1111,a@example.com,\"株式会社\n改行\",改行花子,089-222-2222,妻,\"メモ1\nメモ2\"\r\n";

        $this->confirm('tenant', $csv)->assertSessionHas('success', '入居者インポート完了: 1件を登録しました');

        $tenant = MsTenant::sole();
        $this->assertSame('株式会社 改行', $tenant->workplace);
        $this->assertSame('改行花子', $tenant->emergency_contact_name);
        $this->assertSame('089-222-2222', $tenant->emergency_contact_phone);
        $this->assertSame('妻', $tenant->emergency_contact_relation);
        $this->assertSame("メモ1\nメモ2", $tenant->notes);
    }

    public function test_a_room_contract_memo_keeps_its_line_breaks(): void
    {
        $this->confirm('property', self::PROPERTY_HEADER . "\r\nミツワレジデンス,自社所有,,,松山市1,,,,,\r\n");
        $this->confirm('room', self::ROOM_HEADER . "\r\nミツワレジデンス,101,,,,,,,,,\r\n");
        $this->confirm('tenant', self::TENANT_HEADER . "\r\n入居者,山田太郎,,,,,,,\r\n");

        $csv = self::ROOM_CONTRACT_HEADER . "\r\n"
             . "ミツワレジデンス,101,山田太郎,2024-04-01,2024-04-15,,55000,3000,55000,55000,,\"鍵 2 本\n駐輪場あり\"\r\n";

        $this->confirm('room-contract', $csv)->assertSessionHas('success', '部屋契約インポート完了: 1件を登録しました');

        $this->assertSame("鍵 2 本\n駐輪場あり", MsContract::sole()->memo);
    }

    public function test_the_row_numbers_follow_the_excel_rows(): void
    {
        // 行2 のセルの中に改行があっても、氏名の無い行は Excel と同じ「行3」で出る（直す前は 1 つずれて「行4」）
        $csv = self::TENANT_HEADER . "\r\n"
             . "入居者,改行太郎,,,\"株式会社\n改行\",,,,\r\n"
             . "入居者,,,,,,,,\r\n";

        $preview = $this->preview('tenant', $csv);

        $preview->assertStatus(200);
        $this->assertSame(2, $preview->viewData('totalRows'));
        $this->assertSame(1, $preview->viewData('validCount'));
        $this->assertSame([['row' => 3, 'message' => '氏名が未入力です']], $preview->viewData('rowErrors'));
    }
}
