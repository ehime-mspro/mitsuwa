<?php

namespace Tests\Feature\Housing;

use App\Models\HsPropertyFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Feature\Schedule\ScheduleTestCase;

/**
 * 建売物件の詳細: 登録・更新の日時（Blade）とファイルの登録日（JSON で JS へ渡す日付だけの値）を日本時間で出す（Bug #61）。
 */
class HousingTimestampDisplayTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_registered_and_updated_times_are_shown_in_japan_time(): void
    {
        $manager = $this->manager(['housing']);

        Carbon::setTestNow(Carbon::parse('2026-09-18 17:12:00', 'UTC')); // 日本時間 9/19 2:12
        $property = $this->makeParent('property', ['created_by' => $manager->id]);

        Carbon::setTestNow(Carbon::parse('2026-09-18 23:59:00', 'UTC')); // 日本時間 9/19 8:59
        $property->update(['updated_by' => $manager->id, 'property_name' => '余戸南 3号地（改）']);

        $html = $this->actingAs($manager)->get(route('housing.properties.show', $property))->assertOk()->getContent();

        $this->assertStringContainsString('2026/09/19 02:12', $html, '登録の日時が日本時間になっていない');
        $this->assertStringContainsString('2026/09/19 08:59', $html, '更新の日時が日本時間になっていない');
        $this->assertStringNotContainsString('2026/09/18 17:12', $html, '登録の日時が UTC のまま出ている');
        $this->assertStringNotContainsString('2026/09/18 23:59', $html, '更新の日時が UTC のまま出ている');
    }

    public function test_file_upload_dates_are_japanese_dates(): void
    {
        $manager = $this->manager(['housing']);
        $property = $this->makeParent('property', ['created_by' => $manager->id]);

        Carbon::setTestNow(Carbon::parse('2026-09-18 16:30:00', 'UTC')); // 日本時間 9/19 1:30
        HsPropertyFile::create([
            'property_id' => $property->id,
            'category'    => 'other',
            'file_name'   => 'plan.pdf',
            'file_path'   => 'housing/properties/' . $property->id . '/plan.pdf',
            'file_size'   => 10,
            'mime_type'   => 'application/pdf',
            'uploaded_by' => $manager->id,
        ]);

        $files = $this->actingAs($manager)->get(route('housing.properties.show', $property))
            ->assertOk()
            ->viewData('filesByCategory');

        $this->assertSame('2026/09/19', $files['other'][0]['created_at'], 'ファイルの登録日が日本の日付になっていない');
    }
}
