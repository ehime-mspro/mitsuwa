<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\Investment;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 論理削除した区画を参照する画面が落ちず、削除済みと分かる形で出ること。
 *
 * 区画は論理削除で、削除の歯止めは「契約中の契約がある」だけ。投資・修繕・問合せが付いた区画も削除できるのに、
 * それらから区画へのリレーションが削除済みを読まなかった（2026-09-13 まで）。区画を 1 つ削除した瞬間に
 * 投資の一覧・詳細・物件詳細が 500、修繕は「共用部」と誤表示し保存すると共用部に書き換わり、
 * 問合せは希望区画が黙って消え保存すると中間テーブルからも消えていた（docs/RULES.md Bug #58）。
 *
 * 表示は `Unit::display_label`（表示名 ＋ 削除済みなら「（削除済み）」）。区画ページは削除済みだと 404 なのでリンクを張らない。
 *
 * ⚠ `（削除済み）` は担当者の選択肢にも出る。必ず区画名とセットで見る。
 * ⚠ `B1A（削除済み）` は `1A（削除済み）` を部分文字列に含む。生きている区画 `1A` に印が付いていないことは
 *   前後の文脈ごと見る（Bug #43 の型）。
 */
class DeletedUnitReferenceTest extends TestCase
{
    use RefreshDatabase;

    private Property $building;

    /** 論理削除する区画（投資・修繕・問合せ・解約済み契約が参照する） */
    private Unit $deleted;

    /** 生きている区画 */
    private Unit $live;

    protected function setUp(): void
    {
        parent::setUp();

        $this->building = Property::create([
            'code' => 'T-DEL-1', 'name' => 'テストビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 3,
        ]);

        $this->deleted = $this->makeUnit(-1, 'A');
        $this->live = $this->makeUnit(1, 'A');
        $this->assertSame(['B1A', '1A'], [$this->deleted->display_name, $this->live->display_name], '表示名の前提が崩れている');
    }

    private function makeUnit(?int $floor, string $room): Unit
    {
        return Unit::create([
            'property_id' => $this->building->id,
            'floor' => $floor,
            'room_number' => $room,
            'display_name' => Unit::generateDisplayName($floor, $room),
            'status' => 'vacant',
            'area_tsubo' => 12.5,
        ]);
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** 参照を作ってから区画を削除する（実運用の順序） */
    private function deleteUnit(): void
    {
        $this->deleted->delete();
        $this->assertSoftDeleted($this->deleted);
    }

    private function investmentOn(Unit $unit, string $number): Investment
    {
        return Investment::create([
            'investment_number' => $number,
            'property_id' => $this->building->id,
            'unit_id' => $unit->id,
            'pattern' => 'renovation',
            'status' => 'planning',
            'description' => '削除済み区画のテスト',
            'total_amount' => 1000000,
        ]);
    }

    /** 区画ページへのリンク（閉じ引用符まで含める。`/units/1` が `/units/12` に部分一致するため） */
    private function unitLink(Unit $unit): string
    {
        return 'href="' . route('tenant.units.show', $unit) . '"';
    }

    // ============================================================
    // 投資
    // ============================================================

    public function test_investment_list_shows_a_deleted_unit_with_the_marker(): void
    {
        $this->investmentOn($this->deleted, 'INV-DEL-B1A');
        $this->investmentOn($this->live, 'INV-DEL-1A');
        $this->deleteUnit();

        $html = $this->actingAs($this->executive())
            ->get(route('tenant.investments.index'))
            ->assertOk()
            ->assertSee('テストビル / B1A（削除済み）')
            ->getContent();

        // 生きている区画には印が付かない
        $this->assertMatchesRegularExpression('#テストビル / 1A\s*</td>#u', $html, '生きている区画に印が付いている');
    }

    public function test_investment_detail_shows_a_deleted_unit_without_a_link(): void
    {
        $investment = $this->investmentOn($this->deleted, 'INV-DEL-B1A');
        $this->deleteUnit();

        $this->actingAs($this->executive())
            ->get(route('tenant.investments.show', $investment))
            ->assertOk()
            ->assertSee('B1A（削除済み）')
            // 区画ページは削除済みだと 404 なのでリンクを張らない
            ->assertDontSee($this->unitLink($this->deleted), false)
            // 削除確認モーダルの対象の表記
            ->assertSee('INV-DEL-B1A — テストビル / B1A（削除済み）');
    }

    public function test_investment_detail_keeps_the_link_for_a_live_unit(): void
    {
        $investment = $this->investmentOn($this->live, 'INV-DEL-1A');
        $this->deleteUnit();

        $this->actingAs($this->executive())
            ->get(route('tenant.investments.show', $investment))
            ->assertOk()
            ->assertSee($this->unitLink($this->live) . ' class="text-emerald-600 hover:underline">1A</a>', false)
            ->assertSee('INV-DEL-1A — テストビル / 1A<', false);
    }

    public function test_property_detail_investment_tab_shows_a_deleted_unit(): void
    {
        $this->investmentOn($this->deleted, 'INV-DEL-B1A');
        $this->deleteUnit();

        $html = $this->actingAs($this->executive())
            ->get(route('tenant.properties.show', $this->building))
            ->assertOk()
            ->getContent();

        // 投資タブの行: 投資番号のリンク → 区画のセル
        $this->assertMatchesRegularExpression(
            '#INV-DEL-B1A</a>\s*</td>\s*<td[^>]*>B1A（削除済み）</td>#u',
            $html,
            '物件詳細の投資タブに削除済みの区画が出ていない'
        );
    }
}
