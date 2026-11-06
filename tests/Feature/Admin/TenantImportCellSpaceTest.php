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
 * テナントの CSV 取込で、セルと見出しの前後の全角の空白（U+3000）も落とす（docs/RULES.md Bug #128）。
 *
 * 画面の登録は TrimStrings（`Str::trim()`）で全角の空白も落ちるのに、取込は `trim()`（半角の空白だけ）だった。
 * 直す前（2026-11-06 に使い捨てのテストで実測）: 築年月「2010-04　」は形式の誤り／「既存ビル　」は登録済みの物件に当たらず
 * 取り込める行になった／契約の物件「　既存ビル」は「登録されていません」／見出し「物件名　」はファイルごと断られ、
 * 見出し「備考　」の列は黙って空で入った。
 *
 * ⚠ CSV は Excel と同じ形（行の終わりは CRLF）で作る。
 */
class TenantImportCellSpaceTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use SubmitsImportPreview;

    private const PROPERTY_HEADER = '物件名,郵便番号,住所,構造,築年月,階数,所有区分,オーナー名,稼働状態';

    private const CONTRACT_HEADER = '物件名,階,部屋番号,テナント名,契約日,賃料開始日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考';

    /** 取込画面の URL の前半（SubmitsImportPreview が使う） */
    private function importBasePath(): string
    {
        return '/admin/tenant-import';
    }

    /** 登録済みの物件 1・区画 1・顧客 1 */
    private function arrangeRegistered(): void
    {
        $property = Property::create([
            'code' => 'T-SP-1', 'name' => '既存ビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 3,
        ]);
        Unit::create([
            'property_id' => $property->id, 'floor' => 1, 'room_number' => 'A',
            'display_name' => Unit::generateDisplayName(1, 'A'), 'status' => 'vacant',
        ]);
        Customer::create(['code' => 'CU-SP-1', 'name' => '既存商事', 'customer_type' => 'corporation']);
    }

    public function test_a_property_row_drops_full_width_spaces_around_its_values(): void
    {
        $csv = self::PROPERTY_HEADER . "\r\n"
             . "　新ビル　,790-0001,　愛媛県松山市　一番町　,RC造,2010-04　,5　,自社,,稼働中\r\n";

        $this->confirm('property', $csv)->assertSessionHas('success', '物件インポート完了: 1件を登録しました');

        $property = Property::sole();
        $this->assertSame('新ビル', $property->name);
        // 値の中の全角の空白は残す
        $this->assertSame('愛媛県松山市　一番町', $property->address);
        $this->assertSame(5, (int) $property->total_floors);
        $this->assertStringStartsWith('2010-04', (string) $property->built_date);
    }

    public function test_a_registered_name_with_full_width_spaces_around_it_is_skipped(): void
    {
        $this->arrangeRegistered();

        // ⚠ 先頭にも空白を置く。本番の MySQL（utf8mb4_unicode_ci）は末尾の全角の空白を比べるときに無視する（'ビル' = 'ビル　' が真。
        //   2026-11-06 に本番を読み取りで確認）ので、末尾だけの見本では本番で起きていた形（先頭の空白で別の物件になる）を表さない
        $preview = $this->assertPreviewSkipsEveryRow('property', self::PROPERTY_HEADER . "\r\n　既存ビル　,,愛媛県松山市,,,,自社,,稼働中\r\n", 1);

        $this->assertSame([['row' => 2, 'message' => '物件「既存ビル」は既に登録済みのためスキップ']], $preview->viewData('skippedRows'));
        $this->assertSame(1, Property::count());
    }

    public function test_a_contract_finds_the_property_and_the_customer_through_full_width_spaces(): void
    {
        $this->arrangeRegistered();

        $csv = self::CONTRACT_HEADER . "\r\n"
             . "　既存ビル,1,A,既存商事　,2026-04-01　,　2026-04-01,95000　,,,,,,\r\n";

        $this->confirm('contract', $csv)->assertSessionHas('success', '契約インポート完了: 1件を登録しました');

        $contract = Contract::sole();
        $this->assertSame(Unit::sole()->id, $contract->unit_id);
        $this->assertSame(Customer::sole()->id, $contract->customer_id);
        $this->assertSame('2026-04-01', $contract->contract_date->format('Y-m-d'));
        $this->assertSame(95000, (int) $contract->rent);
    }

    public function test_headers_with_full_width_spaces_around_them_still_find_their_columns(): void
    {
        $this->arrangeRegistered();

        // 必須の見出し（物件名）と任意の見出し（備考）の両方。直す前は前者でファイルごと断られ、後者は黙って空で入った
        $csv = str_replace(['物件名', '備考'], ['物件名　', '　備考'], self::CONTRACT_HEADER) . "\r\n"
             . "既存ビル,1,A,既存商事,2026-04-01,2026-04-01,95000,,,,,,メモ\r\n";

        $this->confirm('contract', $csv)->assertSessionHas('success', '契約インポート完了: 1件を登録しました');

        $this->assertSame('メモ', Contract::sole()->notes);
    }
}
