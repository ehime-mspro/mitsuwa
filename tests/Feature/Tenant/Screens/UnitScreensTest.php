<?php

namespace Tests\Feature\Tenant\Screens;

use App\Models\InquiryUsageType;
use App\Models\Unit;

/**
 * 区画の登録・編集・ステータスの切り替え・削除（tenant.units.create / store / edit / update / updateStatus / destroy）を、
 * 描いた画面から送る往復で見る。
 */
class UnitScreensTest extends TenantScreenTestCase
{
    private const FLOOR_ZERO = '階数に0は入力できません。地下の場合は-1〜-3を入力してください。';

    private function createForm(): array
    {
        $show = $this->htmlOf(route('tenant.properties.show', $this->building));
        $this->assertStringContainsString('href="' . route('tenant.units.create', $this->building) . '"', $show, '物件の詳細に区画の追加の入口が無い');

        return $this->parseForm($this->htmlOf(route('tenant.units.create', $this->building)), 'action="' . route('tenant.units.store', $this->building) . '"');
    }

    private function editForm(Unit $unit): array
    {
        $show = $this->htmlOf(route('tenant.units.show', $unit));
        $this->assertStringContainsString('href="' . route('tenant.units.edit', $unit) . '"', $show, '区画の詳細に編集の入口が無い');

        return $this->parseForm($this->htmlOf(route('tenant.units.edit', $unit)), 'action="' . route('tenant.units.update', $unit) . '"');
    }

    public function test_a_unit_is_registered_from_the_screen(): void
    {
        $usage = InquiryUsageType::create(['name' => '飲食店', 'sort_order' => 1]);
        $form = $this->fill($this->createForm(), [
            'floor' => '3', 'room_number' => 'B', 'area_tsubo' => '15.5', 'usage_type_id' => (string) $usage->id,
            'status' => 'negotiating', 'rent' => '120000', 'common_fee' => '12000', 'deposit' => '360000',
        ]);

        $html = $this->landed($this->submit($form, route('tenant.units.create', $this->building)));

        $this->assertFlash($html, 'success', '区画「3B」を登録しました。');
        $unit = Unit::where('display_name', '3B')->firstOrFail();
        $this->assertSame(3, $unit->floor);
        $this->assertSame('negotiating', $unit->status->value);
        $this->assertSame($usage->id, $unit->usage_type_id);
        $this->assertSame(120000, $unit->rent);
        $this->assertSame(0, $unit->garbage_fee, '空欄の費用は 0 で保存する');
    }

    public function test_floor_zero_is_refused_when_registering(): void
    {
        $form = $this->fill($this->createForm(), ['floor' => '0', 'room_number' => 'Z']);

        $html = $this->landed($this->submit($form, route('tenant.units.create', $this->building)));

        $this->assertInputError($html, self::FLOOR_ZERO);
        $this->assertFalse(Unit::where('room_number', 'Z')->exists(), '0 階の区画が登録された');
    }

    public function test_the_edit_screen_saves_what_it_shows(): void
    {
        $unit = $this->unit(2, 'A');
        $form = $this->fill($this->editForm($unit), ['room_number' => 'C', 'area_tsubo' => '20.25']);
        $this->assertSame('2', $form['fields']['floor'], '編集画面に今の階が入っていない');

        $html = $this->landed($this->submit($form, route('tenant.units.edit', $unit)));

        $this->assertFlash($html, 'success', '区画「2C」を更新しました。');
        $unit->refresh();
        $this->assertSame('2C', $unit->display_name);
        $this->assertSame('20.25', (string) $unit->area_tsubo);
        $this->assertSame(80000, $unit->rent, '募集家賃は編集では変わらない（賃料改定で変える）');
    }

    public function test_floor_zero_is_refused_when_editing(): void
    {
        $unit = $this->unit(2, 'A');
        $form = $this->fill($this->editForm($unit), ['floor' => '0']);

        $html = $this->landed($this->submit($form, route('tenant.units.edit', $unit)));

        $this->assertInputError($html, self::FLOOR_ZERO);
        $this->assertSame('2A', $unit->fresh()->display_name);
    }

    public function test_the_status_toggles_from_the_unit_screen(): void
    {
        $unit = $this->unit(1, 'A');
        $show = route('tenant.units.show', $unit);

        $form = $this->parseForm($this->htmlOf($show), 'action="' . route('tenant.units.updateStatus', $unit) . '"');
        $this->assertSame('PATCH', $form['method']);
        $html = $this->landed($this->submit($form, $show));
        $this->assertFlash($html, 'success', 'ステータスを「商談中」に変更しました。');
        $this->assertSame('negotiating', $unit->fresh()->status->value);

        $form = $this->parseForm($this->htmlOf($show), 'action="' . route('tenant.units.updateStatus', $unit) . '"');
        $html = $this->landed($this->submit($form, $show));
        $this->assertFlash($html, 'success', 'ステータスを「空室」に変更しました。');
        $this->assertSame('vacant', $unit->fresh()->status->value);
    }

    public function test_a_unit_is_deleted_from_the_confirmation(): void
    {
        $unit = $this->unit(1, 'A');
        $show = route('tenant.units.show', $unit);

        $form = $this->parseForm($this->htmlOf($show), 'action="' . route('tenant.units.destroy', $unit) . '"');
        $this->assertSame('DELETE', $form['method']);
        $this->assertNotSame('', $form['fields']['_token'] ?? '', '削除のフォームに @csrf が無い');
        $html = $this->landed($this->submit($form, $show));

        $this->assertFlash($html, 'success', '区画「1A」を削除しました。');
        $this->assertSoftDeleted($unit);
    }

    public function test_a_unit_under_contract_is_not_deleted(): void
    {
        $unit = $this->unit(1, 'A');
        $this->activeContract($unit);
        $show = route('tenant.units.show', $unit);

        $form = $this->parseForm($this->htmlOf($show), 'action="' . route('tenant.units.destroy', $unit) . '"');
        $html = $this->landed($this->submit($form, $show));

        $this->assertFlash($html, 'error', '契約中のデータがあるため削除できません。');
        $this->assertNotSoftDeleted($unit);
    }
}
