<?php

namespace Tests\Feature\Admin\Master;

use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * 用途マスター（Admin\UsageTypeController。`inquiry_usage_types` は Laravel マイグレーションにある）。
 * 問合せの希望用途（`inquiries.desired_usage_id`）と区画の用途（`units.usage_type_id`。論理削除した区画は数えない）で使う。
 */
class UsageTypeMasterTest extends InlineNameMasterTestCase
{
    protected function prefix(): string
    {
        return 'usage-types';
    }

    protected function jsFunction(): string
    {
        return 'usageTypeManager';
    }

    protected function table(): string
    {
        return 'inquiry_usage_types';
    }

    protected function names(): array
    {
        return ['店舗', '事務所', '住居'];
    }

    protected function maxLength(): int
    {
        return 100;
    }

    protected function createMasterSchema(): void
    {
        // 用途・問合せ・区画はマイグレーションにある
    }

    private function property(): Property
    {
        return Property::firstOrCreate(['code' => 'P-USAGE'], [
            'name' => '用途テストビル', 'property_type' => 'tenant', 'department' => 'tenant', 'address' => '愛媛県松山市1-1',
        ]);
    }

    protected function markInUse(int $id, string $name): void
    {
        DB::table('inquiries')->insert([
            'inquiry_number' => 'INQ-2026-001', 'property_id' => $this->property()->id, 'contact_name' => '問合せ 太郎',
            'inquiry_date' => '2026-10-01', 'desired_usage_id' => $id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function inUseMessage(string $name): string
    {
        return "「{$name}」は問合せまたは区画で使用されているため削除できません。";
    }

    private function unitUsing(int $id, bool $deleted): void
    {
        DB::table('units')->insert([
            'property_id' => $this->property()->id, 'room_number' => $deleted ? 'B' : 'A', 'display_name' => $deleted ? 'B' : 'A',
            'status' => 'vacant', 'usage_type_id' => $id, 'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }

    public function test_a_type_used_by_a_unit_is_not_deleted(): void
    {
        $this->unitUsing($this->ids['店舗'], false);

        $sent = $this->submitFromScreen($this->indexUrl(), $this->jsFunction(),
            'var it = data.items[0]; data.startDelete(it.id, it.name); data.submitDelete();');

        $this->assertTrue(DB::table('inquiry_usage_types')->where('id', $this->ids['店舗'])->exists());
        $this->assertFlash($this->landed($sent['response']), 'error', $this->inUseMessage('店舗'));
    }

    /** 論理削除した区画だけが使っている用途は削除できる */
    public function test_a_type_used_only_by_a_deleted_unit_is_deleted(): void
    {
        $this->unitUsing($this->ids['店舗'], true);

        $sent = $this->submitFromScreen($this->indexUrl(), $this->jsFunction(),
            'var it = data.items[0]; data.startDelete(it.id, it.name); data.submitDelete();');

        $this->assertFalse(DB::table('inquiry_usage_types')->where('id', $this->ids['店舗'])->exists());
        $this->assertFlash($this->landed($sent['response']), 'success', '「店舗」を削除しました。');
    }
}
