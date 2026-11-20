<?php

namespace Tests\Feature\RealEstate;

use App\Enums\RealEstatePropertyType;
use Tests\Feature\RealEstate\Screens\RealEstateScreenTestCase;

/** 物件種別 brokerage_land の表記は「土地」（値は変えない） */
class LandLabelTest extends RealEstateScreenTestCase
{
    public function test_the_land_type_is_labelled_land(): void
    {
        $this->assertSame('土地', RealEstatePropertyType::BrokerageLand->label());
        $this->assertSame('brokerage_land', RealEstatePropertyType::BrokerageLand->value);
    }

    public function test_the_registration_screen_offers_land(): void
    {
        $html = $this->actingAs($this->user)->get('/realestate/procurements/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<option\s+value="brokerage_land"[^>]*>\s*土地\s*</option>#u', $html);
    }

    public function test_no_code_or_view_says_the_old_label(): void
    {
        $old = '仲介'.'土地';
        $found = [];
        foreach ([base_path('app'), base_path('resources/views'), base_path('tests')] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')
                    && str_contains(file_get_contents($file->getPathname()), $old)) {
                    $found[] = $file->getPathname();
                }
            }
        }
        $this->assertSame([], $found, '旧表記が残っているファイル');
    }
}
