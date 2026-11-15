<?php

namespace Tests\Feature\Admin;

use App\Models\MsProperty;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMansionSchema();

        MsProperty::create([
            'property_code' => 'MS-LIM-1', 'property_name' => '上限ハイツ', 'ownership_type' => 'self_owned',
            'address' => '愛媛県松山市', 'created_by' => 1,
        ]);
        MsTenant::create(['tenant_type' => 'resident', 'name' => '山田太郎']);
    }

    /** 取込画面の URL の前半（SubmitsImportPreview が使う） */
    private function importBasePath(): string
    {
        return '/admin/mansion-import';
    }

    /**
     * 物件の CSV の 1 行。$values は「CSV の見出し => 値」で、無い列は空。
     *
     * @param  array<string, string>  $values
     */
    private function propertyRow(array $values): string
    {
        $base = ['物件名' => '', '所有区分' => '自社所有', 'オーナー名' => '', '郵便番号' => '', '住所' => '愛媛県松山市一番町1-1',
            '総戸数' => '', '階数' => '', '構造' => '', '築年月' => '', '備考' => ''];

        return implode(',', array_replace($base, $values)) . "\r\n";
    }

    /** @param  array<string, string>  $values */
    private function tenantRow(array $values): string
    {
        $base = ['区分' => '入居者', '氏名' => '', '電話番号' => '', 'メールアドレス' => '', '勤務先' => '',
            '緊急連絡先氏名' => '', '緊急連絡先電話' => '', '続柄' => '', '備考' => ''];

        return implode(',', array_replace($base, $values)) . "\r\n";
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
             . $this->propertyRow(['物件名' => '上限四号館', $label => '12.5']);

        $preview = $this->preview('property', $csv)->assertOk();
        $this->assertSame([
            ['row' => 3, 'message' => "{$label}「{$over}」は{$max}以下の値にしてください"],
            ['row' => 4, 'message' => "{$label}「99999999999999999999」は{$max}以下の値にしてください"],
            ['row' => 5, 'message' => "{$label}「12.5」は0以上の整数で入力してください"],
        ], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('property', $csv);
        $this->assertSame($max, MsProperty::where('property_name', '上限一号館')->sole()->{$column});
        $this->assertSame(0, MsProperty::whereIn('property_name', ['上限二号館', '上限三号館', '上限四号館'])->count());
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
