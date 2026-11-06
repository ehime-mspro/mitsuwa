<?php

namespace Tests\Feature\Admin;

use App\Models\MsContract;
use App\Models\MsRoom;
use App\Models\MsTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMansionSchema;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * 賃貸マンションの CSV 取込で、セルの前後の全角の空白（U+3000）も落とす（docs/RULES.md Bug #128）。
 *
 * 直す前（2026-11-06 に使い捨てのテストで実測）: 入居者の氏名「　山田太郎　」・電話「089-111-1111　」が
 * 空白つきのまま保存された（画面の登録は TrimStrings で落ちる）。
 *
 * ⚠ CSV は Excel と同じ形（行の終わりは CRLF）で作る。
 */
class MansionImportCellSpaceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMansionSchema;
    use ParsesForms;
    use SubmitsImportPreview;

    private const PROPERTY_HEADER = '物件名,所有区分,オーナー名,郵便番号,住所,総戸数,階数,構造,築年月,備考';

    private const ROOM_HEADER = '物件名,部屋番号,階,間取り,面積(㎡),状態,家賃,共益費,敷金,礼金,備考';

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

    public function test_a_tenant_is_saved_without_full_width_spaces_around_the_values(): void
    {
        $csv = self::TENANT_HEADER . "\r\n"
             . "入居者,　山田太郎　,089-111-1111　,,,,,,\r\n";

        $this->confirm('tenant', $csv)->assertSessionHas('success', '入居者インポート完了: 1件を登録しました');

        $tenant = MsTenant::sole();
        $this->assertSame('山田太郎', $tenant->name);
        $this->assertSame('089-111-1111', $tenant->phone);
    }

    public function test_a_room_contract_finds_the_room_and_the_tenant_through_full_width_spaces(): void
    {
        $this->confirm('property', self::PROPERTY_HEADER . "\r\nミツワレジデンス,自社所有,,,松山市1,,,,,\r\n");
        $this->confirm('room', self::ROOM_HEADER . "\r\nミツワレジデンス,101,,,,,,,,,\r\n");
        $this->confirm('tenant', self::TENANT_HEADER . "\r\n入居者,山田太郎,,,,,,,\r\n");

        $csv = self::ROOM_CONTRACT_HEADER . "\r\n"
             . "　ミツワレジデンス,101　,山田太郎　,2024-04-01　,2024-04-15,,55000　,,,,,\r\n";

        $this->confirm('room-contract', $csv)->assertSessionHas('success', '部屋契約インポート完了: 1件を登録しました');

        $contract = MsContract::sole();
        $this->assertSame(MsRoom::sole()->id, $contract->room_id);
        $this->assertSame(MsTenant::sole()->id, $contract->tenant_id);
        $this->assertSame(55000, (int) $contract->rent);
    }
}
