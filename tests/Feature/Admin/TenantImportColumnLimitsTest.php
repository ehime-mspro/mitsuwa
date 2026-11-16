<?php

namespace Tests\Feature\Admin;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * テナントの CSV 取込で、本番の列（2026-11-15 に本番の SHOW CREATE TABLE で確かめた）と画面の上限に入らない値をその行の誤りにする。
 *
 * 本番の MySQL は strict なので、直す前は確定で例外になり、全行が取り消されていた（どの値が悪いかは画面に出ない）。
 * テストの SQLite は長さも範囲も見ないので、ここでは「その行が誤りになり、確定で書かれない」ことを件数で見る。
 * 文字の上限ちょうどは全角の文字で埋める（`mb_strlen` を `strlen` に戻す変異を捕まえる）。
 * 設計: docs/superpowers/specs/2026-11-15-tenant-import-column-limits-design.md
 */
class TenantImportColumnLimitsTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use SubmitsImportPreview;

    private const PROPERTY_HEADER = '物件名,郵便番号,住所,構造,築年月,階数,所有区分,オーナー名,稼働状態';

    private const CUSTOMER_HEADER = 'テナント名,テナントカナ,種別,代表者名,担当者,電話番号,メールアドレス,郵便番号,住所';

    private const UNIT_HEADER = '物件名,階,部屋番号,面積(坪),用途,状態,募集家賃,募集共益費,募集敷金,募集ゴミ代,募集駆除代';

    private const CONTRACT_HEADER = '物件名,階,部屋番号,テナント名,契約日,賃料開始日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考';

    private const PAST_HEADER = '物件名,階,部屋番号,テナント名,契約日,賃料開始日,解約日,家賃,共益費,敷金,ゴミ代,駆除代,屋号,備考';

    /** 金額の列（符号付き INT）の上限（基底 Controller::MAX_INT_COLUMN と同じ値） */
    private const MAX_AMOUNT = 2147483647;

    private Property $property;

    private Unit $unit;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::create([
            'code' => 'T-LIM-1', 'name' => '上限ビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 5,
        ]);
        $this->unit = Unit::create([
            'property_id' => $this->property->id, 'floor' => 1, 'room_number' => 'A',
            'display_name' => Unit::generateDisplayName(1, 'A'), 'status' => 'vacant', 'area_tsubo' => 10,
        ]);
        $this->customer = Customer::create(['code' => 'CU-LIM-1', 'name' => '上限商事', 'customer_type' => 'corporation']);
    }

    /** 取込画面の URL の前半（SubmitsImportPreview が使う） */
    private function importBasePath(): string
    {
        return '/admin/tenant-import';
    }

    /**
     * CSV の 1 行。$values は「CSV の見出し => 値」で、無い列は $base か空。カンマ・改行・引用符を含む値は引用符で囲む。
     *
     * @param  array<string, string>  $base
     * @param  array<string, string>  $values
     */
    private function csvRow(string $header, array $base, array $values): string
    {
        $cells = array_replace(array_fill_keys(explode(',', $header), ''), $base, $values);
        $quote = fn (string $v) => strpbrk($v, ",\n\"") !== false ? '"' . str_replace('"', '""', $v) . '"' : $v;

        return implode(',', array_map($quote, $cells)) . "\r\n";
    }

    /** @param  array<string, string>  $values */
    private function propertyRow(array $values): string
    {
        return $this->csvRow(self::PROPERTY_HEADER, ['住所' => '愛媛県松山市一番町1-1', '所有区分' => '自社'], $values);
    }

    /** @param  array<string, string>  $values */
    private function customerRow(array $values): string
    {
        return $this->csvRow(self::CUSTOMER_HEADER, ['種別' => '法人'], $values);
    }

    /** @param  array<string, string>  $values */
    private function unitRow(array $values): string
    {
        return $this->csvRow(self::UNIT_HEADER, ['物件名' => '上限ビル', '階' => '2'], $values);
    }

    /**
     * 契約・過去契約の 1 行（区画 1A・顧客「上限商事」・家賃 50000。過去契約は解約日も入れる）。
     *
     * @param  array<string, string>  $values
     */
    private function contractRow(string $tab, array $values): string
    {
        $base = ['物件名' => '上限ビル', '階' => '1', '部屋番号' => 'A', 'テナント名' => '上限商事', '家賃' => '50000'];
        if ($tab === 'past-contract') {
            return $this->csvRow(self::PAST_HEADER, $base + ['解約日' => '2025-03-31'], $values);
        }

        return $this->csvRow(self::CONTRACT_HEADER, $base, $values);
    }

    private function contractHeader(string $tab): string
    {
        return $tab === 'past-contract' ? self::PAST_HEADER : self::CONTRACT_HEADER;
    }

    // ================================================================
    // 物件
    // ================================================================

    public static function propertyTextLimits(): array
    {
        return [
            '物件名'     => ['物件名', 'name', 200],
            '郵便番号'   => ['郵便番号', 'postal_code', 10],
            '住所'       => ['住所', 'address', 500],
            '構造'       => ['構造', 'structure', 50],
            'オーナー名' => ['オーナー名', 'owner_name', 200],
        ];
    }

    /**
     * 行 2 は上限ちょうど（所有区分「オーナー」＝オーナー名も保存する）・行 3 は 1 つ超え（所有区分「自社」。
     * オーナー名は保存しない値でも、画面の入力チェックと同じく長さを見る）。物件名の列は、行の名前そのものが上限の長さ。
     */
    #[DataProvider('propertyTextLimits')]
    public function test_property_text_up_to_the_column_size_is_imported(string $label, string $column, int $max): void
    {
        [$name2, $name3] = $column === 'name' ? [str_repeat('あ', $max), str_repeat('い', $max + 1)] : ['上限一号館', '上限二号館'];
        $csv = self::PROPERTY_HEADER . "\r\n"
             . $this->propertyRow(['物件名' => $name2, '所有区分' => 'オーナー', $label => str_repeat('あ', $max)])
             . $this->propertyRow(['物件名' => $name3, '所有区分' => '自社', $label => str_repeat('い', $max + 1)]);

        $preview = $this->preview('property', $csv)->assertOk();
        $this->assertSame([['row' => 3, 'message' => "{$label}は{$max}文字以内で入力してください"]], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('property', $csv);
        $this->assertSame(str_repeat('あ', $max), Property::where('name', $name2)->sole()->{$column});
        $this->assertFalse(Property::where('name', $name3)->exists());
    }

    // ================================================================
    // 顧客
    // ================================================================

    public static function customerTextLimits(): array
    {
        return [
            'テナント名'   => ['テナント名', 'name', 200],
            'テナントカナ' => ['テナントカナ', 'name_kana', 200],
            '代表者名'     => ['代表者名', 'representative', 100],
            '担当者'       => ['担当者', 'contact_person', 100],
            '電話番号'     => ['電話番号', 'phone', 20],
            '郵便番号'     => ['郵便番号', 'postal_code', 10],
            '住所'         => ['住所', 'address', 500],
        ];
    }

    #[DataProvider('customerTextLimits')]
    public function test_customer_text_up_to_the_column_size_is_imported(string $label, string $column, int $max): void
    {
        [$name2, $name3] = $column === 'name' ? [str_repeat('あ', $max), str_repeat('い', $max + 1)] : ['上限一商事', '上限二商事'];
        $csv = self::CUSTOMER_HEADER . "\r\n"
             . $this->customerRow(['テナント名' => $name2, $label => str_repeat('あ', $max)])
             . $this->customerRow(['テナント名' => $name3, $label => str_repeat('い', $max + 1)]);

        $preview = $this->preview('customer', $csv)->assertOk();
        $this->assertSame([['row' => 3, 'message' => "{$label}は{$max}文字以内で入力してください"]], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('customer', $csv);
        $this->assertSame(str_repeat('あ', $max), Customer::where('name', $name2)->sole()->{$column});
        $this->assertFalse(Customer::where('name', $name3)->exists());
    }

    /**
     * メールアドレスの列は 255 文字だが、形の検査（filter_var）が 254 文字までしか通さないので、取り込めるのは 254 文字まで。
     * 256 文字は長さの検査（形の検査より前）が先に断る＝長さの文が出る。
     */
    public function test_a_customer_email_over_255_characters_gets_the_length_error(): void
    {
        $email254 = str_repeat('a', 63) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 59) . '.jp';
        $email256 = str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 60) . '.jp';
        $this->assertSame([254, 256], [strlen($email254), strlen($email256)]);

        $csv = self::CUSTOMER_HEADER . "\r\n"
             . $this->customerRow(['テナント名' => '上限一商事', 'メールアドレス' => $email254])
             . $this->customerRow(['テナント名' => '上限二商事', 'メールアドレス' => $email256]);

        $preview = $this->preview('customer', $csv)->assertOk();
        $this->assertSame([['row' => 3, 'message' => 'メールアドレスは255文字以内で入力してください']], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('customer', $csv);
        $this->assertSame($email254, Customer::where('name', '上限一商事')->sole()->email);
    }

    // ================================================================
    // 区画
    // ================================================================

    public function test_room_number_up_to_20_characters_is_imported(): void
    {
        $csv = self::UNIT_HEADER . "\r\n"
             . $this->unitRow(['階' => '', '部屋番号' => str_repeat('あ', 20)])
             . $this->unitRow(['階' => '', '部屋番号' => str_repeat('い', 21)]);

        $preview = $this->preview('unit', $csv)->assertOk();
        $this->assertSame([['row' => 3, 'message' => '部屋番号は20文字以内で入力してください']], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('unit', $csv);
        $this->assertTrue(Unit::where('room_number', str_repeat('あ', 20))->exists());
        $this->assertFalse(Unit::where('room_number', str_repeat('い', 21))->exists());
    }

    public static function unitAmounts(): array
    {
        return [
            '募集家賃'   => ['募集家賃', 'rent'],
            '募集共益費' => ['募集共益費', 'common_fee'],
            '募集敷金'   => ['募集敷金', 'deposit'],
            '募集ゴミ代' => ['募集ゴミ代', 'garbage_fee'],
            '募集駆除代' => ['募集駆除代', 'pest_control_fee'],
        ];
    }

    /**
     * 行 2 はカンマ入りの上限ちょうど・行 3 は 1 つ超え・行 4 は小数（直す前は黙って 55000 で保存された）・
     * 行 5 はカンマの区切りが崩れた値（直す前はカンマを全部除いて 55000 で保存された）。
     */
    #[DataProvider('unitAmounts')]
    public function test_unit_amounts_up_to_the_column_range_are_imported(string $header, string $column): void
    {
        $max = (string) self::MAX_AMOUNT;
        $over = (string) (self::MAX_AMOUNT + 1);
        $csv = self::UNIT_HEADER . "\r\n"
             . $this->unitRow(['部屋番号' => 'B', $header => number_format(self::MAX_AMOUNT)])
             . $this->unitRow(['部屋番号' => 'C', $header => $over])
             . $this->unitRow(['部屋番号' => 'D', $header => '55000.9'])
             . $this->unitRow(['部屋番号' => 'E', $header => '5,5000']);

        $preview = $this->preview('unit', $csv)->assertOk();
        $this->assertSame([
            ['row' => 3, 'message' => "{$header}「{$over}」は{$max}以下の値にしてください"],
            ['row' => 4, 'message' => "{$header}「55000.9」は不正な値です"],
            ['row' => 5, 'message' => "{$header}「5,5000」は不正な値です"],
        ], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('unit', $csv);
        $this->assertSame(self::MAX_AMOUNT, Unit::where('display_name', '2B')->sole()->{$column});
        $this->assertSame(0, Unit::whereIn('display_name', ['2C', '2D', '2E'])->count());
    }

    /** 面積は画面と同じ 9,999.99 坪まで（列は 999,999.99 まで入るが、超えた区画は編集画面で断られる）。 */
    public function test_area_up_to_9999_99_is_imported(): void
    {
        $csv = self::UNIT_HEADER . "\r\n"
             . $this->unitRow(['部屋番号' => 'B', '面積(坪)' => '9999.99'])
             . $this->unitRow(['部屋番号' => 'C', '面積(坪)' => '10000'])
             . $this->unitRow(['部屋番号' => 'D', '面積(坪)' => '9999.995']);

        $preview = $this->preview('unit', $csv)->assertOk();
        $this->assertSame([
            ['row' => 3, 'message' => '面積「10000」は9999.99以下の値にしてください'],
            ['row' => 4, 'message' => '面積「9999.995」は9999.99以下の値にしてください'],
        ], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('unit', $csv);
        $this->assertSame('9999.99', Unit::where('display_name', '2B')->sole()->area_tsubo);
        $this->assertSame(0, Unit::whereIn('display_name', ['2C', '2D'])->count());
    }

    // ================================================================
    // 契約・過去契約（行ごとに契約日を変える＝CSV 内の重複にしない）
    // ================================================================

    public static function contractAmounts(): array
    {
        $cases = [];
        foreach (['contract' => '契約', 'past-contract' => '過去契約'] as $tab => $name) {
            foreach (['家賃' => 'rent', '共益費' => 'common_fee', '敷金' => 'deposit', 'ゴミ代' => 'garbage_fee', '駆除代' => 'pest_control_fee'] as $header => $column) {
                $cases["{$name}・{$header}"] = [$tab, $header, $column];
            }
        }

        return $cases;
    }

    #[DataProvider('contractAmounts')]
    public function test_contract_amounts_up_to_the_column_range_are_imported(string $tab, string $header, string $column): void
    {
        $max = (string) self::MAX_AMOUNT;
        $over = (string) (self::MAX_AMOUNT + 1);
        $csv = $this->contractHeader($tab) . "\r\n"
             . $this->contractRow($tab, ['契約日' => '2024-04-01', $header => number_format(self::MAX_AMOUNT)])
             . $this->contractRow($tab, ['契約日' => '2024-05-01', $header => $over])
             . $this->contractRow($tab, ['契約日' => '2024-06-01', $header => '55000.9']);

        $preview = $this->preview($tab, $csv)->assertOk();
        $this->assertSame([
            ['row' => 3, 'message' => "{$header}「{$over}」は{$max}以下の値にしてください"],
            ['row' => 4, 'message' => "{$header}「55000.9」は不正な値です"],
        ], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm($tab, $csv);
        $this->assertSame(self::MAX_AMOUNT, Contract::sole()->{$column});
    }

    /**
     * 備考は、編集画面と同じく改行を 2 文字（CRLF。ブラウザの textarea が送る形）と数えて $length 文字。改行は 1,000 個。
     * （改行を 1 文字と数えると、取り込めたのに編集画面で「5000文字以下」と断られる備考ができる）
     */
    private static function notes(int $length): string
    {
        return str_repeat('あ', $length - 3000) . str_repeat("\nあ", 1000);
    }

    /** 文字数（mb_strlen）では 5,000 文字ちょうどでも、改行を 2 文字と数えると 5,000 文字を超える備考は、その行の誤り。 */
    public function test_a_note_that_the_edit_screen_would_count_over_5000_is_an_error_row(): void
    {
        $note = str_repeat('あ', 4998) . "\nあ";
        $this->assertSame(5000, mb_strlen($note));

        $csv = self::CONTRACT_HEADER . "\r\n" . $this->contractRow('contract', ['契約日' => '2024-04-01', '備考' => $note]);

        $preview = $this->preview('contract', $csv)->assertOk();
        $this->assertSame([['row' => 2, 'message' => '備考は5000文字以内で入力してください']], $preview->viewData('rowErrors'));
        $this->assertSame(0, $preview->viewData('validCount'));
    }

    public static function contractTextLimits(): array
    {
        return [
            '契約・屋号'         => ['contract', '屋号', 'store_name', 200],
            '契約・備考'         => ['contract', '備考', 'notes', 5000],
            '過去契約・屋号'     => ['past-contract', '屋号', 'store_name', 200],
            '過去契約・備考'     => ['past-contract', '備考', 'notes', 5000],
            '過去契約・テナント名' => ['past-contract', 'テナント名', 'customer_name', 200],
        ];
    }

    /** 過去契約のテナント名は、まだ無い顧客の名前（取込で作る）。201 文字の行の顧客は作られない。 */
    #[DataProvider('contractTextLimits')]
    public function test_contract_text_up_to_the_column_size_is_imported(string $tab, string $label, string $column, int $max): void
    {
        [$ok, $long] = $column === 'notes'
            ? [self::notes($max), self::notes($max + 1)]
            : [str_repeat('あ', $max), str_repeat('い', $max + 1)];
        $crlf = fn (string $v) => mb_strlen(str_replace("\n", "\r\n", $v));
        $this->assertSame([$max, $max + 1], [$crlf($ok), $crlf($long)]);

        $csv = $this->contractHeader($tab) . "\r\n"
             . $this->contractRow($tab, ['契約日' => '2024-04-01', $label => $ok])
             . $this->contractRow($tab, ['契約日' => '2024-05-01', $label => $long]);

        $preview = $this->preview($tab, $csv)->assertOk();
        $this->assertSame([['row' => 3, 'message' => "{$label}は{$max}文字以内で入力してください"]], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm($tab, $csv);
        $contract = Contract::sole();
        if ($column === 'customer_name') {
            $this->assertSame($ok, $contract->customer->name);
            $this->assertFalse(Customer::where('name', $long)->exists());
        } else {
            $this->assertSame($ok, $contract->{$column});
        }
    }

    public static function registeredContracts(): array
    {
        return ['契約' => ['contract'], '過去契約' => ['past-contract']];
    }

    /** 文字・金額の検査は照合の後ろ（登録済みの行は値に誤りがあってもスキップ）。 */
    #[DataProvider('registeredContracts')]
    public function test_a_registered_contract_with_a_long_or_large_value_is_still_skipped(string $tab): void
    {
        Contract::create([
            'contract_number' => 'C-1999-901', 'department' => 'tenant', 'property_id' => $this->property->id,
            'unit_id' => $this->unit->id, 'customer_id' => $this->customer->id, 'status' => 'active',
            'contract_date' => '2024-04-01', 'rent_start_date' => '2024-04-01', 'rent' => 95000,
        ]);
        $csv = $this->contractHeader($tab) . "\r\n"
             . $this->contractRow($tab, ['契約日' => '2024-04-01', '屋号' => str_repeat('あ', 201), '家賃' => (string) (self::MAX_AMOUNT + 1)]);

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
            '物件' => ['property', self::PROPERTY_HEADER, ['物件名' => '上限ビル', '構造' => str_repeat('あ', 51)], '物件「上限ビル」は既に登録済みのためスキップ'],
            '顧客' => ['customer', self::CUSTOMER_HEADER, ['テナント名' => '上限商事', '担当者' => str_repeat('あ', 101)], '顧客「上限商事」は既に登録済みのためスキップ'],
        ];
    }

    /** @param  array<string, string>  $values */
    #[DataProvider('registeredRows')]
    public function test_a_registered_row_with_a_long_value_is_still_skipped(string $tab, string $header, array $values, string $skip): void
    {
        $row = $tab === 'property' ? $this->propertyRow($values) : $this->customerRow($values);

        $preview = $this->preview($tab, $header . "\r\n" . $row)->assertOk();
        $this->assertSame([], $preview->viewData('rowErrors'));
        $this->assertSame([['row' => 2, 'message' => $skip]], $preview->viewData('skippedRows'));
    }
}
