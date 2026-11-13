<?php

namespace Tests\Feature\Admin;

use App\Models\MsProperty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMansionSchema;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
use Tests\TestCase;

/**
 * 賃貸マンションの物件の CSV 取込の築年月。月を 2 桁にそろえるだけで範囲を見ておらず、`2010-13` が入っていた
 * （編集画面の年月の部品が `2011-01` に作り直す）。テナントの物件の取込と同じ部品・同じ文で断る。
 */
class MansionPropertyImportValuesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMansionSchema;
    use ParsesForms;
    use SubmitsImportPreview;

    private const HEADER = '物件名,所有区分,オーナー名,郵便番号,住所,総戸数,階数,構造,築年月,備考';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMansionSchema();
    }

    private function importBasePath(): string
    {
        return '/admin/mansion-import';
    }

    private function row(string $name, string $built): string
    {
        return "{$name},自社所有,,790-0001,愛媛県松山市一番町1-1,20,5,RC造,{$built},\r\n";
    }

    public function test_a_month_that_does_not_exist_is_an_error_row(): void
    {
        $csv = self::HEADER . "\r\n"
             . $this->row('十三月レジデンス', '2010-13')
             . $this->row('スラッシュレジデンス', '2010/4');

        $preview = $this->preview('property', $csv)->assertOk();

        $this->assertSame([
            ['row' => 2, 'message' => '築年月「2010-13」は年月として正しくありません（例: 2010-04 か 2010/4）'],
        ], $preview->viewData('rowErrors'));
        $this->assertSame(1, $preview->viewData('validCount'));

        $this->confirm('property', $csv);
        $property = MsProperty::sole();
        $this->assertSame('スラッシュレジデンス', $property->property_name);
        $this->assertSame('2010-04', $property->built_year_month);
    }
}
