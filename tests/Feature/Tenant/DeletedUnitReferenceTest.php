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

    /** どこからも参照されていない削除済みの区画（編集画面の選択肢に出てはいけない） */
    private Unit $otherDeleted;

    protected function setUp(): void
    {
        parent::setUp();

        $this->building = Property::create([
            'code' => 'T-DEL-1', 'name' => 'テストビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 3,
        ]);

        $this->deleted = $this->makeUnit(-1, 'A');
        $this->live = $this->makeUnit(1, 'A');
        $this->otherDeleted = $this->makeUnit(3, 'A');
        $this->otherDeleted->delete();
        $this->assertSame(
            ['B1A', '1A', '3A'],
            [$this->deleted->display_name, $this->live->display_name, $this->otherDeleted->display_name],
            '表示名の前提が崩れている'
        );
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

    // ------------------------------------------------------------
    // 投資の登録・編集（選択肢は Alpine が描くので HTML でなく viewData で見る。@json は日本語を \uXXXX にする）
    // ------------------------------------------------------------

    /** @return list<string> この物件の区画の選択肢のラベル */
    private function optionLabels(array|\Illuminate\Support\Collection $options): array
    {
        return collect($options)->where('property_id', $this->building->id)->pluck('label')->values()->all();
    }

    /** 画面が送る投資のフォーム値（区画・物件・明細は画面のデータから組む。Bug #54 ②） */
    private function investmentFormFrom(\Illuminate\Testing\TestResponse $edit, array $overrides = []): array
    {
        $investment = $edit->viewData('investment');
        $option = collect($edit->viewData('allUnits'))
            ->where('property_id', $investment->property_id)
            ->firstWhere('id', $investment->unit_id);
        $this->assertNotNull($option, '編集画面の区画の選択肢に今の区画が無い（ブラウザでは選択が外れて「区画は必須」になる）');

        return array_merge([
            'property_id' => $investment->property_id,
            'unit_id' => $option['id'],
            'pattern' => $investment->pattern->value,
            'status' => 'in_progress',
            'description' => $investment->description,
            'details' => $edit->viewData('investmentDetails')->all(),
        ], $overrides);
    }

    public function test_investment_create_form_does_not_offer_deleted_units(): void
    {
        $this->investmentOn($this->deleted, 'INV-DEL-B1A');
        $this->deleteUnit();

        $options = $this->actingAs($this->executive())
            ->get(route('tenant.investments.create'))
            ->assertOk()
            ->viewData('allUnits');

        $this->assertSame(['1A（12.50坪）'], $this->optionLabels($options));
    }

    public function test_investment_edit_form_offers_only_its_own_deleted_unit(): void
    {
        $investment = $this->investmentOn($this->deleted, 'INV-DEL-B1A');
        $this->deleteUnit();

        $options = $this->actingAs($this->executive())
            ->get(route('tenant.investments.edit', $investment))
            ->assertOk()
            ->viewData('allUnits');

        // 今の区画は削除済みでも残り、ほかの削除済み（3A）は出ない
        $this->assertEqualsCanonicalizing(['B1A（削除済み）（12.50坪）', '1A（12.50坪）'], $this->optionLabels($options));
    }

    public function test_investment_edit_round_trip_keeps_the_deleted_unit(): void
    {
        $investment = $this->investmentOn($this->deleted, 'INV-DEL-B1A');
        $investment->details()->create(['cost_item' => 'interior', 'amount' => 1000000]);
        $this->deleteUnit();

        $user = $this->executive();
        $edit = $this->actingAs($user)->get(route('tenant.investments.edit', $investment))->assertOk();

        $this->actingAs($user)
            ->put(route('tenant.investments.update', $investment), $this->investmentFormFrom($edit))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('tenant.investments.show', $investment));

        $investment->refresh();
        $this->assertSame($this->deleted->id, $investment->unit_id, '削除済みの区画が保存で外れた');
        $this->assertSame('in_progress', $investment->status->value, 'ほかの項目の変更が保存されていない');
    }

    public function test_investment_update_can_move_off_the_deleted_unit(): void
    {
        $investment = $this->investmentOn($this->deleted, 'INV-DEL-B1A');
        $investment->details()->create(['cost_item' => 'interior', 'amount' => 1000000]);
        $this->deleteUnit();

        $user = $this->executive();
        $edit = $this->actingAs($user)->get(route('tenant.investments.edit', $investment))->assertOk();

        $this->actingAs($user)
            ->put(route('tenant.investments.update', $investment), $this->investmentFormFrom($edit, ['unit_id' => $this->live->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->live->id, $investment->refresh()->unit_id);
    }

    public function test_investment_update_rejects_another_deleted_unit(): void
    {
        $investment = $this->investmentOn($this->deleted, 'INV-DEL-B1A');
        $investment->details()->create(['cost_item' => 'interior', 'amount' => 1000000]);
        $this->deleteUnit();

        $user = $this->executive();
        $edit = $this->actingAs($user)->get(route('tenant.investments.edit', $investment))->assertOk();

        $this->actingAs($user)
            ->put(route('tenant.investments.update', $investment), $this->investmentFormFrom($edit, ['unit_id' => $this->otherDeleted->id]))
            ->assertSessionHasErrors(['unit_id' => '選択された区画は存在しません。']);

        $this->assertSame($this->deleted->id, $investment->refresh()->unit_id);
    }

    public function test_investment_store_rejects_a_deleted_unit(): void
    {
        $this->deleteUnit();

        $this->actingAs($this->executive())
            ->post(route('tenant.investments.store'), [
                'property_id' => $this->building->id,
                'unit_id' => $this->deleted->id,
                'pattern' => 'renovation',
                'status' => 'planning',
                'details' => [['cost_item' => 'interior', 'amount' => 1000000]],
            ])
            ->assertSessionHasErrors(['unit_id' => '選択された区画は存在しません。']);

        $this->assertSame(0, Investment::count());
    }
}
