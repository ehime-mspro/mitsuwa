<?php

namespace Tests\Feature\Housing;

use App\Models\HsPropertyFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Feature\Schedule\ScheduleTestCase;

/**
 * 住宅事業の詳細: 登録・更新の日時（Blade）とファイルの登録日（JSON で JS へ渡す日付だけの値）を日本時間で出す（Bug #61）。
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

    /**
     * 登録情報の日時を出す住宅事業の親。
     *
     * ⚠ 建売物件（properties/show.blade.php）と注文住宅（custom-orders/show.blade.php）は
     *   逐語で同じ形なので、「代表 1 種だけ」で書くと残りの経路が一度も実行されないまま緑になる
     *   （ScheduleTestCase の docblock / docs/RULES.md Bug #44）。
     * ⚠ プロバイダは Laravel の起動前に評価されるので route() を呼ばない（Bug #53）。
     *   ルート名の文字列を返し、URL はテスト本体で組み立てる。
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function housingParents(): array
    {
        return [
            '建売物件' => ['property',    'housing.properties.show',    'property_name', '余戸南 3号地（改）'],
            '注文住宅' => ['customOrder', 'housing.custom-orders.show', 'order_name',    '松山市 T様邸（改）'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('housingParents')]
    public function test_registered_and_updated_times_are_shown_in_japan_time(
        string $parent,
        string $routeName,
        string $nameColumn,
        string $newName
    ): void {
        $manager = $this->manager(['housing']);

        Carbon::setTestNow(Carbon::parse('2026-09-18 17:12:00', 'UTC')); // 日本時間 9/19 2:12
        $owner = $this->makeParent($parent, ['created_by' => $manager->id]);

        Carbon::setTestNow(Carbon::parse('2026-09-18 23:59:00', 'UTC')); // 日本時間 9/19 8:59
        $owner->update(['updated_by' => $manager->id, $nameColumn => $newName]);

        $html = $this->actingAs($manager)->get(route($routeName, $owner))->assertOk()->getContent();

        $this->assertStringContainsString('2026/09/19 02:12', $html, "{$parent}: 登録の日時が日本時間になっていない");
        $this->assertStringContainsString('2026/09/19 08:59', $html, "{$parent}: 更新の日時が日本時間になっていない");
        $this->assertStringNotContainsString('2026/09/18 17:12', $html, "{$parent}: 登録の日時が UTC のまま出ている");
        $this->assertStringNotContainsString('2026/09/18 23:59', $html, "{$parent}: 更新の日時が UTC のまま出ている");
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
