<?php

namespace Tests\Feature\Admin;

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

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::create([
            'code' => 'T-LIM-1', 'name' => '上限ビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 5,
        ]);
        Unit::create([
            'property_id' => $this->property->id, 'floor' => 1, 'room_number' => 'A',
            'display_name' => Unit::generateDisplayName(1, 'A'), 'status' => 'vacant', 'area_tsubo' => 10,
        ]);
        Customer::create(['code' => 'CU-LIM-1', 'name' => '上限商事', 'customer_type' => 'corporation']);
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
