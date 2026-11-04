<?php

namespace Tests\Feature\Admin;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * テナントの CSV 取込で、セルの中の改行（Excel の Alt+Enter）を 1 件の中の値として読む（docs/RULES.md Bug #127）。
 *
 * 直す前（2026-11-04 に使い捨てのテストで実測）は、行を `explode("\n")` で分けてから読んでいたので 1 件が 2 行に割れ、
 * 改行のあるセルより後ろの列の値が**黙って消えたまま取り込まれ**、意味の通らないエラーの行が 1 つ出た
 * （住所に改行 → 構造・築年月・階数が空で登録され「所有区分「稼働中」は不正な値です」）。後ろの行の番号も 1 つずれた。
 *
 * 利用者の決定（2026-11-04）: 画面で改行を入れられる欄（契約・過去契約の「備考」）は改行を残し、
 * ほかの欄（画面の入力欄が 1 行）は空白 1 つにする。
 *
 * ⚠ CSV は Excel と同じ形（行の終わりは CRLF・セルの中の改行は LF）で作る。
 */
class TenantImportLineBreakTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use SubmitsImportPreview;

    private const PROPERTY_HEADER = '物件名,郵便番号,住所,構造,築年月,階数,所有区分,オーナー名,稼働状態';

    private const CUSTOMER_HEADER = 'テナント名,テナントカナ,種別,代表者名,担当者,電話番号,メールアドレス,郵便番号,住所';

    private const CONTRACT_HEADER = '物件名,階,部屋番号,テナント名,契約日,賃料開始日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考';

    private const PAST_HEADER = '物件名,階,部屋番号,テナント名,契約日,賃料開始日,解約日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考';

    /** 取込画面の URL の前半（SubmitsImportPreview が使う） */
    private function importBasePath(): string
    {
        return '/admin/tenant-import';
    }

    /** 契約・過去契約の取込先（物件 1・区画 2・顧客 1） */
    private function arrangeUnits(): void
    {
        $property = Property::create([
            'code' => 'T-LB-1', 'name' => '改行ビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 3,
        ]);
        foreach ([1, 2] as $floor) {
            Unit::create([
                'property_id' => $property->id, 'floor' => $floor, 'room_number' => 'A',
                'display_name' => Unit::generateDisplayName($floor, 'A'), 'status' => 'vacant',
            ]);
        }
        Customer::create(['code' => 'CU-LB-1', 'name' => '改行商事', 'customer_type' => 'corporation']);
    }

    public function test_a_line_break_in_a_one_line_column_becomes_one_space_and_keeps_the_later_columns(): void
    {
        $csv = self::PROPERTY_HEADER . "\r\n"
             . "改行テストビル,790-0001,\"愛媛県松山市\n一番町1-1\",RC造,2010-04,5,自社,,稼働中\r\n"
             . "次のビル,790-0002,愛媛県松山市二番町,S造,,,自社,,稼働中\r\n";

        $this->confirm('property', $csv)->assertSessionHas('success', '物件インポート完了: 2件を登録しました');

        $property = Property::where('name', '改行テストビル')->sole();
        $this->assertSame('愛媛県松山市 一番町1-1', $property->address);
        // 改行のあるセルより後ろの列も同じ行の値として入る（直す前は空で入った）
        $this->assertSame('RC造', $property->structure);
        $this->assertSame(5, (int) $property->total_floors);

        $this->assertSame('S造', Property::where('name', '次のビル')->sole()->structure);
    }

    public function test_the_row_numbers_follow_the_excel_rows(): void
    {
        // 行2 のセルの中に改行があっても、物件名の無い行は Excel と同じ「行3」で出る（直す前は 1 つずれて「行4」）
        $csv = self::PROPERTY_HEADER . "\r\n"
             . "改行テストビル,790-0001,\"愛媛県松山市\n一番町1-1\",RC造,2010-04,5,自社,,稼働中\r\n"
             . ",790-0002,愛媛県松山市二番町,RC造,,,自社,,稼働中\r\n";

        $preview = $this->preview('property', $csv);

        $preview->assertStatus(200);
        $this->assertSame(2, $preview->viewData('totalRows'));
        $this->assertSame(1, $preview->viewData('validCount'));
        $this->assertSame([['row' => 3, 'message' => '物件名が未入力です']], $preview->viewData('rowErrors'));
    }

    public function test_a_customer_with_a_line_break_keeps_the_contact_columns(): void
    {
        $csv = self::CUSTOMER_HEADER . "\r\n"
             . "改行商事,カイギョウショウジ,法人,\"代表\n太郎\",担当花子,089-111-1111,a@example.com,790-0001,愛媛県松山市\r\n";

        $this->confirm('customer', $csv)->assertSessionHas('success', '顧客インポート完了: 1件を登録しました');

        $customer = Customer::sole();
        $this->assertSame('代表 太郎', $customer->representative);
        $this->assertSame('担当花子', $customer->contact_person);
        $this->assertSame('089-111-1111', $customer->phone);
        $this->assertSame('a@example.com', $customer->email);
        $this->assertSame('愛媛県松山市', $customer->address);
    }

    public function test_a_contract_note_keeps_its_line_breaks(): void
    {
        $this->arrangeUnits();

        $csv = self::CONTRACT_HEADER . "\r\n"
             . "改行ビル,1,A,改行商事,2026-04-01,2026-04-01,95000,,,,,,\"1行目のメモ\n2行目のメモ\"\r\n"
             . "改行ビル,2,A,改行商事,2026-05-01,2026-05-01,90000,,,,,,\r\n";

        $this->confirm('contract', $csv)->assertSessionHas('success', '契約インポート完了: 2件を登録しました');

        $this->assertSame(["1行目のメモ\n2行目のメモ", null], Contract::orderBy('id')->pluck('notes')->all());
    }

    public function test_a_store_name_with_a_line_break_becomes_one_line_and_keeps_the_note(): void
    {
        $this->arrangeUnits();

        $csv = self::CONTRACT_HEADER . "\r\n"
             . "改行ビル,1,A,改行商事,2026-04-01,2026-04-01,95000,,,,,\"改行商事\n松山支店\",メモ\r\n";

        $this->confirm('contract', $csv)->assertSessionHas('success', '契約インポート完了: 1件を登録しました');

        $contract = Contract::sole();
        $this->assertSame('改行商事 松山支店', $contract->store_name);
        $this->assertSame('メモ', $contract->notes);
    }

    public function test_a_past_contract_note_keeps_its_line_breaks(): void
    {
        $this->arrangeUnits();

        $csv = self::PAST_HEADER . "\r\n"
             . "改行ビル,1,A,改行商事,2020-04-01,2020-04-01,2024-03-31,95000,,,,,\"改行商事\n旧店\",\"退去時の精算済み\n鍵 2 本返却\"\r\n";

        $this->confirm('past-contract', $csv)->assertSessionHas('success', '過去契約インポート完了: 契約 1件を登録');

        $contract = Contract::sole();
        $this->assertSame("退去時の精算済み\n鍵 2 本返却", $contract->notes);
        $this->assertSame('改行商事 旧店', $contract->store_name);
        $this->assertSame('2024-03-31', $contract->contract_end_date->format('Y-m-d'));
    }
}
