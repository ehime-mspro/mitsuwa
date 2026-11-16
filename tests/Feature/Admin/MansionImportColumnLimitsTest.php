<?php

namespace Tests\Feature\Admin;

use App\Models\MsContract;
use App\Models\MsParking;
use App\Models\MsParkingContract;
use App\Models\MsProperty;
use App\Models\MsRoom;
use App\Models\MsTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesMansionSchema;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * 賃貸マンションの CSV 取込で、本番の列（database/sql/create_mansion_tables.sql）に入らない値をその行の誤りにする。
 *
 * 本番の MySQL は strict なので、直す前は確定で例外になり、全行が取り消されていた（どの値が悪いかは画面に出ない）。
 * テストの SQLite は長さも範囲も見ないので、ここでは「その行が誤りになり、確定で書かれない」ことを件数で見る。
 * 文字の上限ちょうどは全角の文字で埋める（`mb_strlen` を `strlen` に戻す変異を捕まえる。Bug #72 と同じ）。
 * 設計: docs/superpowers/specs/2026-11-15-mansion-import-column-limits-design.md
 */
class MansionImportColumnLimitsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMansionSchema;
    use ParsesForms;
    use SubmitsImportPreview;

    private const PROPERTY_HEADER = '物件名,所有区分,オーナー名,郵便番号,住所,総戸数,階数,構造,築年月,備考';

    private const TENANT_HEADER = '区分,氏名,電話番号,メールアドレス,勤務先,緊急連絡先氏名,緊急連絡先電話,続柄,備考';

    private const ROOM_HEADER = '物件名,部屋番号,階,間取り,面積(㎡),状態,家賃,共益費,敷金,礼金,備考';

    private const PARKING_HEADER = '物件名,駐車場番号,月額料金,状態,屋根あり,備考';

    /** 金額の列（INT UNSIGNED）の上限（基底 Controller::MAX_UNSIGNED_INT_COLUMN と同じ値） */
    private const MAX_AMOUNT = 4294967295;

    private const ROOM_CONTRACT_HEADER = '物件名,部屋番号,入居者名,契約日,入居日,退去日,家賃,共益費,敷金,礼金,担当者ユーザー名,メモ';

    private const PARKING_CONTRACT_HEADER = '物件名,駐車場番号,入居者名,紐付部屋番号,契約日,開始日,終了日,月額料金,敷金,担当者ユーザー名,メモ';

    private MsProperty $property;

    private MsRoom $room;

    private MsParking $parking;

    private MsTenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMansionSchema();

        $this->property = MsProperty::create([
            'property_code' => 'MS-LIM-1', 'property_name' => '上限ハイツ', 'ownership_type' => 'self_owned',
            'address' => '愛媛県松山市', 'created_by' => 1,
        ]);
        $this->room = MsRoom::create(['property_id' => $this->property->id, 'room_number' => '101', 'status' => 'vacant']);
        $this->parking = MsParking::create(['property_id' => $this->property->id, 'parking_number' => 'P-1', 'monthly_fee' => 8000, 'status' => 'vacant']);
        $this->tenant = MsTenant::create(['tenant_type' => 'resident', 'name' => '山田太郎']);
    }

    /** 取込画面の URL の前半（SubmitsImportPreview が使う） */
    private function importBasePath(): string
    {
        return '/admin/mansion-import';
    }

    /**
     * CSV の 1 行。$values は「CSV の見出し => 値」で、無い列は $base か空。カンマを含む値は引用符で囲む。
     *
     * @param  array<string, string>  $base
     * @param  array<string, string>  $values
     */
    private function csvRow(string $header, array $base, array $values): string
    {
        $cells = array_replace(array_fill_keys(explode(',', $header), ''), $base, $values);

        return implode(',', array_map(fn (string $v) => str_contains($v, ',') ? "\"{$v}\"" : $v, $cells)) . "\r\n";
    }

    /** @param  array<string, string>  $values */
    private function propertyRow(array $values): string
    {
        return $this->csvRow(self::PROPERTY_HEADER, ['所有区分' => '自社所有', '住所' => '愛媛県松山市一番町1-1'], $values);
    }

    /** @param  array<string, string>  $values */
    private function tenantRow(array $values): string
    {
        return $this->csvRow(self::TENANT_HEADER, ['区分' => '入居者'], $values);
    }

    /** @param  array<string, string>  $values */
    private function roomRow(array $values): string
    {
        return $this->csvRow(self::ROOM_HEADER, ['物件名' => '上限ハイツ', '状態' => '空室'], $values);
    }

    /** @param  array<string, string>  $values */
    private function parkingRow(array $values): string
    {
        return $this->csvRow(self::PARKING_HEADER, ['物件名' => '上限ハイツ'], $values);
    }

    /** @param  array<string, string>  $values */
    private function roomContractRow(array $values): string
    {
        return $this->csvRow(self::ROOM_CONTRACT_HEADER, ['物件名' => '上限ハイツ', '部屋番号' => '101', '入居者名' => '山田太郎'], $values);
    }

    /** @param  array<string, string>  $values */
    private function parkingContractRow(array $values): string
    {
        return $this->csvRow(self::PARKING_CONTRACT_HEADER, ['物件名' => '上限ハイツ', '駐車場番号' => 'P-1', '入居者名' => '山田太郎', '月額料金' => '8000'], $values);
    }

    // ================================================================
    // 物件
    // ================================================================

    public static function propertyTextLimits(): array
    {
        return [
            'オーナー名' => ['オーナー名', 'owner_name', 100],
            '郵便番号'   => ['郵便番号', 'postal_code', 10],
            '構造'       => ['構造', 'structure', 50],
        ];
    }

    /** 行 3 は自社所有（オーナー名は保存しない値でも、画面の入力チェックと同じく長さを見る。設計の「こちらの判断」）。 */
    #[DataProvider('propertyTextLimits')]
    public function test_property_text_up_to_the_column_size_is_imported(string $label, string $column, int $max): void
    {
        $csv = self::PROPERTY_HEADER . "\r\n"
             . $this->propertyRow(['物件名' => '上限一号館', '所有区分' => '管理受託', $label => str_repeat('あ', $max)])
             . $this->propertyRow(['物件名' => '上限二号館', '所有区分' => '自社所有', $label => str_repeat('あ', $max + 1)]);

        $preview = $this->preview('property', $csv)->assertOk();
        $this->assertSame([['row' => 3, 'message' => "{$label}は{$max}文字以内で入力してください"]], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('property', $csv);
        $this->assertSame(str_repeat('あ', $max), MsProperty::where('property_name', '上限一号館')->sole()->{$column});
        $this->assertFalse(MsProperty::where('property_name', '上限二号館')->exists());
    }

    public static function propertyCountLimits(): array
    {
        return [
            '総戸数' => ['総戸数', 'total_units', 65535],
            '階数'   => ['階数', 'total_floors', 255],
        ];
    }

    #[DataProvider('propertyCountLimits')]
    public function test_property_counts_up_to_the_column_range_are_imported(string $label, string $column, int $max): void
    {
        $over = (string) ($max + 1);
        $csv = self::PROPERTY_HEADER . "\r\n"
             . $this->propertyRow(['物件名' => '上限一号館', $label => (string) $max])
             . $this->propertyRow(['物件名' => '上限二号館', $label => $over])
             . $this->propertyRow(['物件名' => '上限三号館', $label => '99999999999999999999'])
             . $this->propertyRow(['物件名' => '上限四号館', $label => '12.5'])
             . $this->propertyRow(['物件名' => '上限五号館', $label => '2,5']);

        // 総戸数・階数はカンマを通さない（直す前と同じ。金額と違い、カンマ入りは「2,5」→ 25 のような打ち間違いになる）
        $preview = $this->preview('property', $csv)->assertOk();
        $this->assertSame([
            ['row' => 3, 'message' => "{$label}「{$over}」は{$max}以下の値にしてください"],
            ['row' => 4, 'message' => "{$label}「99999999999999999999」は{$max}以下の値にしてください"],
            ['row' => 5, 'message' => "{$label}「12.5」は0以上の整数で入力してください"],
            ['row' => 6, 'message' => "{$label}「2,5」は0以上の整数で入力してください"],
        ], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('property', $csv);
        $this->assertSame($max, MsProperty::where('property_name', '上限一号館')->sole()->{$column});
        $this->assertSame(0, MsProperty::whereIn('property_name', ['上限二号館', '上限三号館', '上限四号館', '上限五号館'])->count());
    }

    // ================================================================
    // 入居者
    // ================================================================

    public static function tenantTextLimits(): array
    {
        return [
            '電話番号'       => ['電話番号', 'phone', 20],
            '勤務先'         => ['勤務先', 'workplace', 100],
            '緊急連絡先氏名' => ['緊急連絡先氏名', 'emergency_contact_name', 100],
            '緊急連絡先電話' => ['緊急連絡先電話', 'emergency_contact_phone', 20],
            '続柄'           => ['続柄', 'emergency_contact_relation', 50],
        ];
    }

    #[DataProvider('tenantTextLimits')]
    public function test_tenant_text_up_to_the_column_size_is_imported(string $label, string $column, int $max): void
    {
        $csv = self::TENANT_HEADER . "\r\n"
             . $this->tenantRow(['氏名' => '上限一郎', $label => str_repeat('あ', $max)])
             . $this->tenantRow(['氏名' => '上限二郎', $label => str_repeat('あ', $max + 1)]);

        $preview = $this->preview('tenant', $csv)->assertOk();
        $this->assertSame([['row' => 3, 'message' => "{$label}は{$max}文字以内で入力してください"]], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('tenant', $csv);
        $this->assertSame(str_repeat('あ', $max), MsTenant::where('name', '上限一郎')->sole()->{$column});
        $this->assertFalse(MsTenant::where('name', '上限二郎')->exists());
    }

    // ================================================================
    // 部屋・駐車場
    // ================================================================

    public function test_room_type_up_to_20_characters_is_imported(): void
    {
        $csv = self::ROOM_HEADER . "\r\n"
             . $this->roomRow(['部屋番号' => '201', '間取り' => str_repeat('あ', 20)])
             . $this->roomRow(['部屋番号' => '202', '間取り' => str_repeat('あ', 21)]);

        $preview = $this->preview('room', $csv)->assertOk();
        $this->assertSame([['row' => 3, 'message' => '間取りは20文字以内で入力してください']], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('room', $csv);
        $this->assertSame(str_repeat('あ', 20), MsRoom::where('room_number', '201')->sole()->room_type);
        $this->assertFalse(MsRoom::where('room_number', '202')->exists());
    }

    /**
     * 部屋の階と 4 つの金額。行 2 は上限ちょうど（家賃はカンマ入り）・行 3 以降は $bad の順に 1 行ずつ。
     * 金額は整数だけ（直す前は「55000.9」が 55000 で保存され、「1e10」も通った）。
     */
    public static function roomNumberLimits(): array
    {
        $max = (string) self::MAX_AMOUNT;
        $over = (string) (self::MAX_AMOUNT + 1);
        $amount = fn (string $label) => [
            $over     => "{$label}「{$over}」は{$max}以下の値にしてください",
            '55000.9' => "{$label}「55000.9」は不正な値です",
        ];

        return [
            '階' => ['階', 'floor', '255', 255, [
                '256' => '階「256」は255以下の値にしてください',
                '2.5' => '階「2.5」は0以上の整数で入力してください',
                '2,5' => '階「2,5」は0以上の整数で入力してください',
            ]],
            '家賃' => ['家賃', 'rent', '4,294,967,295', self::MAX_AMOUNT, [
                $over                  => "家賃「{$over}」は{$max}以下の値にしてください",
                '99999999999999999999' => "家賃「99999999999999999999」は{$max}以下の値にしてください",
                '55000.9'              => '家賃「55000.9」は不正な値です',
                '1e10'                 => '家賃「1e10」は不正な値です',
                // カンマの区切りが崩れた値（直す前はカンマを全部除いて 55000 で入っていた）
                '5,5000'               => '家賃「5,5000」は不正な値です',
            ]],
            '共益費' => ['共益費', 'common_fee', $max, self::MAX_AMOUNT, $amount('共益費')],
            '敷金'   => ['敷金', 'deposit', $max, self::MAX_AMOUNT, $amount('敷金')],
            '礼金'   => ['礼金', 'key_money', $max, self::MAX_AMOUNT, $amount('礼金')],
        ];
    }

    /** @param  array<string, string>  $bad  セルの値 => 誤りの文 */
    #[DataProvider('roomNumberLimits')]
    public function test_room_numbers_up_to_the_column_range_are_imported(string $header, string $column, string $okRaw, int $okStored, array $bad): void
    {
        $csv = self::ROOM_HEADER . "\r\n" . $this->roomRow(['部屋番号' => '201', $header => $okRaw]);
        $expected = [];
        $roomNumber = 202;
        foreach ($bad as $raw => $message) {
            $csv .= $this->roomRow(['部屋番号' => (string) $roomNumber, $header => (string) $raw]);
            $expected[] = ['row' => $roomNumber - 199, 'message' => $message];
            $roomNumber++;
        }

        $preview = $this->preview('room', $csv)->assertOk();
        $this->assertSame($expected, $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('room', $csv);
        $this->assertSame($okStored, MsRoom::where('room_number', '201')->sole()->{$column});
        $this->assertSame(0, MsRoom::where('room_number', '>=', '202')->count());
    }

    public function test_area_up_to_999999_99_is_imported(): void
    {
        $csv = self::ROOM_HEADER . "\r\n"
             . $this->roomRow(['部屋番号' => '201', '面積(㎡)' => '999999.99'])
             . $this->roomRow(['部屋番号' => '202', '面積(㎡)' => '1000000'])
             . $this->roomRow(['部屋番号' => '203', '面積(㎡)' => '999999.995']);

        $preview = $this->preview('room', $csv)->assertOk();
        $this->assertSame([
            ['row' => 3, 'message' => '面積「1000000」は999999.99以下の値にしてください'],
            ['row' => 4, 'message' => '面積「999999.995」は999999.99以下の値にしてください'],
        ], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('room', $csv);
        $this->assertSame('999999.99', MsRoom::where('room_number', '201')->sole()->area_sqm);
        $this->assertSame(0, MsRoom::whereIn('room_number', ['202', '203'])->count());
    }

    /** 面積のカンマは 3 桁ごとの正しい区切りのときだけ除く（直す前はカンマを全部除いて「5,5000」が 55000 ㎡で入っていた）。 */
    public function test_area_with_thousands_separators_is_read_only_when_well_grouped(): void
    {
        $csv = self::ROOM_HEADER . "\r\n"
             . $this->roomRow(['部屋番号' => '201', '面積(㎡)' => '1,234.56'])
             . $this->roomRow(['部屋番号' => '202', '面積(㎡)' => '5,5000'])
             . $this->roomRow(['部屋番号' => '203', '面積(㎡)' => '1,234.5,6']);

        $preview = $this->preview('room', $csv)->assertOk();
        $this->assertSame([
            ['row' => 3, 'message' => '面積「5,5000」は不正な値です'],
            ['row' => 4, 'message' => '面積「1,234.5,6」は不正な値です'],
        ], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('room', $csv);
        $this->assertSame('1234.56', MsRoom::where('room_number', '201')->sole()->area_sqm);
        $this->assertSame(0, MsRoom::whereIn('room_number', ['202', '203'])->count());
    }

    public function test_parking_monthly_fee_up_to_the_column_range_is_imported(): void
    {
        $max = (string) self::MAX_AMOUNT;
        $over = (string) (self::MAX_AMOUNT + 1);
        $csv = self::PARKING_HEADER . "\r\n"
             . $this->parkingRow(['駐車場番号' => 'P-2', '月額料金' => $max])
             . $this->parkingRow(['駐車場番号' => 'P-3', '月額料金' => $over])
             . $this->parkingRow(['駐車場番号' => 'P-4', '月額料金' => '8000.5']);

        $preview = $this->preview('parking', $csv)->assertOk();
        $this->assertSame([
            ['row' => 3, 'message' => "月額料金「{$over}」は{$max}以下の値にしてください"],
            ['row' => 4, 'message' => '月額料金「8000.5」は0以上の整数で入力してください'],
        ], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('parking', $csv);
        $this->assertSame(self::MAX_AMOUNT, MsParking::where('parking_number', 'P-2')->sole()->monthly_fee);
        $this->assertSame(0, MsParking::whereIn('parking_number', ['P-3', 'P-4'])->count());
    }

    // ================================================================
    // 部屋契約・駐車場契約（行ごとに契約日を変える＝CSV 内の重複にしない）
    // ================================================================

    public static function roomContractAmounts(): array
    {
        return [
            '家賃'   => ['家賃', 'rent'],
            '共益費' => ['共益費', 'common_fee'],
            '敷金'   => ['敷金', 'deposit'],
            '礼金'   => ['礼金', 'key_money'],
        ];
    }

    #[DataProvider('roomContractAmounts')]
    public function test_room_contract_amounts_up_to_the_column_range_are_imported(string $header, string $column): void
    {
        $max = (string) self::MAX_AMOUNT;
        $over = (string) (self::MAX_AMOUNT + 1);
        $csv = self::ROOM_CONTRACT_HEADER . "\r\n"
             . $this->roomContractRow(['契約日' => '2024-04-01', $header => $max])
             . $this->roomContractRow(['契約日' => '2024-05-01', $header => $over])
             . $this->roomContractRow(['契約日' => '2024-06-01', $header => '55000.9']);

        $preview = $this->preview('room-contract', $csv)->assertOk();
        $this->assertSame([
            ['row' => 3, 'message' => "{$header}「{$over}」は{$max}以下の値にしてください"],
            ['row' => 4, 'message' => "{$header}「55000.9」は不正な値です"],
        ], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('room-contract', $csv);
        $this->assertSame(self::MAX_AMOUNT, MsContract::sole()->{$column});
    }

    public static function parkingContractAmounts(): array
    {
        return [
            '月額料金' => ['月額料金', 'monthly_fee', '月額料金「55000.9」は0以上の整数で入力してください'],
            '敷金'     => ['敷金', 'deposit', '敷金「55000.9」は不正な値です'],
        ];
    }

    #[DataProvider('parkingContractAmounts')]
    public function test_parking_contract_amounts_up_to_the_column_range_are_imported(string $header, string $column, string $formatError): void
    {
        $max = (string) self::MAX_AMOUNT;
        $over = (string) (self::MAX_AMOUNT + 1);
        $csv = self::PARKING_CONTRACT_HEADER . "\r\n"
             . $this->parkingContractRow(['契約日' => '2024-04-01', $header => $max])
             . $this->parkingContractRow(['契約日' => '2024-05-01', $header => $over])
             . $this->parkingContractRow(['契約日' => '2024-06-01', $header => '55000.9']);

        $preview = $this->preview('parking-contract', $csv)->assertOk();
        $this->assertSame([
            ['row' => 3, 'message' => "{$header}「{$over}」は{$max}以下の値にしてください"],
            ['row' => 4, 'message' => $formatError],
        ], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('parking-contract', $csv);
        $this->assertSame(self::MAX_AMOUNT, MsParkingContract::sole()->{$column});
    }

    public static function registeredContracts(): array
    {
        return [
            '部屋契約'   => ['room-contract'],
            '駐車場契約' => ['parking-contract'],
        ];
    }

    /** 金額の検査は照合の後ろ（登録済みの行は金額に誤りがあってもスキップ。クラスの docblock）。上限を超えた金額も同じ。 */
    #[DataProvider('registeredContracts')]
    public function test_a_registered_contract_with_an_amount_over_the_limit_is_still_skipped(string $tab): void
    {
        $over = (string) (self::MAX_AMOUNT + 1);
        if ($tab === 'room-contract') {
            MsContract::create([
                'room_id' => $this->room->id, 'tenant_id' => $this->tenant->id, 'status' => 'active',
                'contract_date' => '2024-04-01', 'move_in_date' => '2024-04-15', 'rent' => 55000, 'created_by' => 1,
            ]);
            $csv = self::ROOM_CONTRACT_HEADER . "\r\n"
                 . $this->roomContractRow(['契約日' => '2024-04-01', '入居日' => '2024-04-15', '家賃' => $over]);
        } else {
            MsParkingContract::create([
                'parking_id' => $this->parking->id, 'tenant_id' => $this->tenant->id, 'status' => 'active',
                'contract_date' => '2024-04-01', 'start_date' => '2024-04-15', 'monthly_fee' => 8000, 'created_by' => 1,
            ]);
            $csv = self::PARKING_CONTRACT_HEADER . "\r\n"
                 . $this->parkingContractRow(['契約日' => '2024-04-01', '開始日' => '2024-04-15', '月額料金' => $over]);
        }

        $preview = $this->preview($tab, $csv)->assertOk();
        $this->assertSame([], $preview->viewData('rowErrors'));
        $this->assertCount(1, $preview->viewData('skippedRows'));
        $this->assertSame(0, $preview->viewData('validCount'));
    }

    // ================================================================
    // 登録済みの行は、長すぎる値があってもスキップのまま（新しい検査はスキップより後ろ）
    // ================================================================

    public static function registeredRows(): array
    {
        return [
            '物件' => ['property', self::PROPERTY_HEADER, ['物件名' => '上限ハイツ', '構造' => str_repeat('あ', 51)], '物件「上限ハイツ」は既に登録済みのためスキップ'],
            '入居者' => ['tenant', self::TENANT_HEADER, ['氏名' => '山田太郎', '続柄' => str_repeat('あ', 51)], '入居者「山田太郎」は既に登録済みのためスキップ'],
        ];
    }

    /** @param  array<string, string>  $values */
    #[DataProvider('registeredRows')]
    public function test_a_registered_row_with_a_long_value_is_still_skipped(string $tab, string $header, array $values, string $skip): void
    {
        $row = $tab === 'property' ? $this->propertyRow($values) : $this->tenantRow($values);

        $preview = $this->preview($tab, $header . "\r\n" . $row)->assertOk();
        $this->assertSame([], $preview->viewData('rowErrors'));
        $this->assertSame([['row' => 2, 'message' => $skip]], $preview->viewData('skippedRows'));
    }
}
